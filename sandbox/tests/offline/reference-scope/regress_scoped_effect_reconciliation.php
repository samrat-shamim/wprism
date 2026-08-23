<?php
declare(strict_types=1);

/**
 * Offline DUO-3344 regression for operation-bound scoped effects.
 *
 * The production boundary writes only canonical hashes to a provider-namespaced
 * target option. This harness supplies just enough WordPress option/cache
 * behavior to prove the durable state machine:
 *   absent receipt -> not_started;
 *   intent only -> recovery_required (never reinvoke);
 *   verified receipt + checked readback -> verified;
 *   mismatched checked readback -> recovery_required.
 */

$root = dirname(__DIR__, 4);

final class DuoScopedEffectFakeWpdb {
    public string $options = 'wp_options';
    public string $last_error = '';
    /** @var array<string,string> */
    public array $optionRows = [];

    public function prepare(string $query, mixed ...$args): string {
        foreach ($args as $arg) {
            $query = preg_replace_callback('/%[sd]/', static function (array $match) use ($arg): string {
                return $match[0] === '%d'
                    ? (string) (int) $arg
                    : "'" . str_replace("'", "''", (string) $arg) . "'";
            }, $query, 1) ?? $query;
        }
        return $query;
    }

    public function get_var(string $query): string|false|null {
        $this->last_error = '';
        if (str_contains($query, 'SELECT option_value')
            && preg_match("/option_name = '([^']*)'/", $query, $match) === 1) {
            return $this->optionRows[$match[1]] ?? null;
        }
        if (preg_match("/option_name = '([^']*)'/", $query, $match) === 1) {
            return array_key_exists($match[1], $this->optionRows) ? $match[1] : null;
        }
        return null;
    }
}

$GLOBALS['wpdb'] = new DuoScopedEffectFakeWpdb();
$GLOBALS['duo_scoped_effect_cache'] = ['transient' => []];
$GLOBALS['duo_scoped_effect_deletes'] = 0;
$GLOBALS['duo_scoped_effect_filters'] = [];
$GLOBALS['duo_scoped_rewrite_child_flushes'] = 0;

function add_option(string $name, mixed $value, mixed $deprecated = '', mixed $autoload = 'yes'): bool {
    global $wpdb;
    if (array_key_exists($name, $wpdb->optionRows)) {
        return false;
    }
    $wpdb->optionRows[$name] = (string) $value;
    return true;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool {
    global $wpdb;
    $old = $wpdb->optionRows[$name] ?? null;
    $wpdb->optionRows[$name] = is_array($value) || is_object($value) || is_bool($value)
        ? serialize($value)
        : (string) $value;
    return $old !== $wpdb->optionRows[$name];
}

function maybe_unserialize(mixed $value): mixed {
    if (!is_string($value) || preg_match('/^(?:a|O|s|b|i|d|N):/', $value) !== 1) {
        return $value;
    }
    $decoded = @unserialize($value, ['allowed_classes' => false]);
    return $decoded === false && $value !== 'b:0;' ? $value : $decoded;
}

function get_option(string $name, mixed $default = false): mixed {
    global $wpdb;
    return array_key_exists($name, $wpdb->optionRows)
        ? maybe_unserialize($wpdb->optionRows[$name])
        : $default;
}

function did_action(string $hook): int {
    return $hook === 'wp_loaded' ? 1 : 0;
}

function add_filter(string $hook, callable $callback, int $priority = 10): bool {
    $GLOBALS['duo_scoped_effect_filters'][$hook][$priority][] = $callback;
    return true;
}

function remove_filter(string $hook, callable $callback, int $priority = 10): bool {
    foreach (($GLOBALS['duo_scoped_effect_filters'][$hook][$priority] ?? []) as $index => $candidate) {
        if ($candidate === $callback) {
            unset($GLOBALS['duo_scoped_effect_filters'][$hook][$priority][$index]);
            return true;
        }
    }
    return false;
}

function wp_cache_get(string $key, string $group = '', bool $force = false, mixed &$found = null): mixed {
    $found = array_key_exists($key, $GLOBALS['duo_scoped_effect_cache'][$group] ?? []);
    return $found ? $GLOBALS['duo_scoped_effect_cache'][$group][$key] : false;
}

function delete_transient(string $name): bool {
    global $wpdb;
    $GLOBALS['duo_scoped_effect_deletes']++;
    unset($wpdb->optionRows['_transient_' . $name]);
    unset($wpdb->optionRows['_transient_timeout_' . $name]);
    unset($GLOBALS['duo_scoped_effect_cache']['transient'][$name]);
    return true;
}

final class DuoScopedRewriteRuntime {
    public string|false $permalink_structure = '/old/%post_id%/';
    /** @var array<string,string> */
    public array $rules = ['^old/([0-9]+)/?$' => 'index.php?p=$matches[1]'];
    public int $flushes = 0;

    public function init(): void {
        $this->permalink_structure = get_option('permalink_structure', false);
    }

    public function flush_rules(bool $hard = true): void {
        $this->flushes++;
        $fingerprint = substr(hash('sha256', (string) $this->permalink_structure), 0, 12);
        $this->rules = ['^scoped/([^/]+)/?$' => 'index.php?name=$matches[1]&grammar=' . $fingerprint];
        update_option('rewrite_rules', $this->rules);
    }

    /** @return array<string,string> */
    public function wp_rewrite_rules(): array {
        $this->rules = get_option('rewrite_rules');
        return $this->rules;
    }
}

final class WP_CLI {
    /** @param array<string,mixed> $args */
    public static function runcommand(string $command, array $args): object {
        global $wp_rewrite;
        if (!str_contains($command, 'NativeActions::execute("rewrite.flush", [])')
            || $args !== ['launch' => true, 'return' => 'all', 'exit_error' => false]) {
            throw new RuntimeException('unexpected scoped rewrite child command');
        }
        $parentRuntime = $wp_rewrite;
        $freshRuntime = new DuoScopedRewriteRuntime();
        $freshRuntime->permalink_structure = get_option('permalink_structure', false);
        $wp_rewrite = $freshRuntime;
        try {
            $method = new ReflectionMethod(Duo\NativeActions::class, 'flush_rewrite_in_fresh_process');
            $receipt = $method->invoke(null);
            $report = [
                'format' => 'duo-rewrite-flush-fresh/v1',
                'after' => $receipt['after'],
            ];
            return (object) [
                'return_code' => 0,
                'stdout' => json_encode($report, JSON_THROW_ON_ERROR),
                'stderr' => '',
            ];
        } catch (Throwable $failure) {
            return (object) [
                'return_code' => 1,
                'stdout' => '',
                'stderr' => $failure->getMessage(),
            ];
        } finally {
            $GLOBALS['duo_scoped_rewrite_child_flushes'] += $freshRuntime->flushes;
            $wp_rewrite = $parentRuntime;
        }
    }
}

require $root . '/agent/src/Kernel/Canon.php';
require $root . '/agent/src/Kernel/Secrets.php';
require $root . '/agent/src/Policy/Policy.php';
require $root . '/agent/src/Adapter/Providers.php';

use Duo\NativeActions;
use Duo\Providers;

$failures = 0;
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures++;
    }
};
$throws = static function (callable $fn, string $needle, string $message) use (&$failures): void {
    try {
        $fn();
        echo "FAIL: $message (did not throw)\n";
        $failures++;
    } catch (\Throwable $t) {
        $ok = str_contains($t->getMessage(), $needle);
        echo ($ok ? 'ok: ' : 'FAIL: ') . $message . ' (message: ' . $t->getMessage() . ")\n";
        if (!$ok) {
            $failures++;
        }
    }
};

class DuoScopedProbeProvider {
    public int $invocations = 0;
    public int $reconciliations = 0;
    public int $state = 0;

    public function identity(): array {
        return ['id' => 'scoped-probe', 'plugin' => 'probe/probe.php', 'version' => '1.0.0'];
    }

    public function capabilities(): array {
        return [
            'repair' => [
                'args' => [],
                'reads' => ['option:probe_state'],
                'writes' => ['option:probe_state'],
                'scope' => 'site',
                'idempotent' => true,
                'timeout_seconds' => 5,
                'scoped' => [
                    'operation_envelope' => Providers::SCOPED_OPERATION_FORMAT,
                    'reconcile' => true,
                ],
            ],
        ];
    }

    public function invoke(string $capability, array $args): array {
        $before = ['state' => $this->state];
        $this->state++;
        return ['before' => $before, 'after' => ['state' => $this->state], 'verified' => true];
    }

    public function invoke_scoped(string $capability, array $args, array $operation): array {
        $this->invocations++;
        $receipt = $this->invoke($capability, $args);
        return $receipt + ['operation' => $operation];
    }

    public function reconcile_scoped(string $capability, array $args, array $operation): array {
        $this->reconciliations++;
        return ['operation' => $operation, 'after' => ['state' => $this->state], 'verified' => true];
    }
}

final class DuoScopedSecretProvider extends DuoScopedProbeProvider {
    public function invoke_scoped(string $capability, array $args, array $operation): array {
        $this->invocations++;
        return [
            'operation' => $operation,
            'before' => ['state' => 0],
            'after' => ['state' => 'sk_live_12345678901234567890'],
            'verified' => true,
        ];
    }
}

/** @return array<string,mixed> */
$operation = static function (string $inputHash, string $id): array {
    return [
        'authority_hash' => str_repeat('a', 64),
        'lease_session_id' => 'lease.0001',
        'operation_id' => $id,
        'input_hash' => $inputHash,
        'effect_hash' => str_repeat('e', 64),
    ];
};

$provider = new DuoScopedProbeProvider();
$action = ['kind' => 'provider', 'provider' => 'scoped-probe', 'capability' => 'repair', 'args' => []];
$decl = $provider->capabilities()['repair'];
$op = $operation(Providers::scoped_input_hash($action, $decl), 'operation.0001');

$ordinaryValidator = new ReflectionMethod(Providers::class, 'validate_capability_declaration');
$ordinaryOnly = $decl;
$ordinaryOnly['scoped'] = ['not_the_scoped_contract' => true];
try {
    $ordinaryValidator->invoke(null, $ordinaryOnly, 'probe capability');
    $ordinaryAccepted = true;
} catch (\Throwable $t) {
    $ordinaryAccepted = false;
}
$check(
    $ordinaryAccepted,
    'ordinary capability validation ignores the optional scoped declaration; only scoped selection requires it'
);
$throws(
    static fn() => Providers::scoped_capability_digest('scoped-probe', 'repair', $ordinaryOnly),
    '.scoped',
    'scoped capability binding requires the strict operation-envelope declaration'
);

$notStarted = Providers::reconcile_scoped($provider, $action, $decl, $op);
$check(
    array_keys($notStarted) === ['format', 'operation', 'capability_digest', 'before_hash', 'after_hash', 'verified', 'status']
        && $notStarted['status'] === 'not_started'
        && $notStarted['verified'] === false
        && $notStarted['before_hash'] === null
        && $provider->reconciliations === 0,
    'a missing durable receipt is the only not_started state and does not call provider reconciliation'
);

$receipt = Providers::invoke_scoped($provider, $action, $decl, $op);
$check(
    $receipt['status'] === 'verified'
        && $receipt['verified'] === true
        && $receipt['operation'] === $op
        && preg_match('/^[a-f0-9]{64}$/', $receipt['before_hash']) === 1
        && preg_match('/^[a-f0-9]{64}$/', $receipt['after_hash']) === 1
        && $receipt['capability_digest'] === Providers::scoped_capability_digest('scoped-probe', 'repair', $decl)
        && !array_key_exists('before', $receipt)
        && !array_key_exists('after', $receipt),
    'scoped invocation returns exact operation binding and hash-only reviewed evidence'
);
$storedOperationRows = array_values(array_filter(
    $GLOBALS['wpdb']->optionRows,
    static fn(string $value): bool => str_contains($value, Providers::SCOPED_OPERATION_RECEIPT_FORMAT)
));
$check(
    count($storedOperationRows) === 1
        && !str_contains($storedOperationRows[0], '"before":')
        && !str_contains($storedOperationRows[0], '"after":')
        && str_contains($storedOperationRows[0], '"before_hash"')
        && str_contains($storedOperationRows[0], '"after_hash"'),
    'the durable provider-owned receipt contains hashes rather than raw provider before/after evidence'
);

$recovered = Providers::reconcile_scoped($provider, $action, $decl, $op);
$check(
    $provider->invocations === 1
        && $provider->reconciliations === 1
        && $recovered['status'] === 'verified'
        && $recovered['before_hash'] === $receipt['before_hash']
        && $recovered['after_hash'] === $receipt['after_hash'],
    'lost-response recovery uses reconcile only and returns the original durable evidence hashes'
);

$provider->state = 99;
$throws(
    static fn() => Providers::reconcile_scoped($provider, $action, $decl, $op),
    'recovery_required',
    'a durable receipt whose checked postcondition changed is fail-closed rather than reinvoked'
);
$check($provider->invocations === 1, 'a mismatched provider readback did not invoke the effect again');

$intentOp = $operation(Providers::scoped_input_hash($action, $decl), 'operation.0002');
Providers::begin_scoped_operation('scoped-probe', 'repair', $intentOp);
$throws(
    static fn() => Providers::reconcile_scoped($provider, $action, $decl, $intentOp),
    'recovery_required',
    'an intent without a verified receipt is unknown/recovery_required, never not_started'
);
$check($provider->invocations === 1, 'an intent-only operation did not reinvoke the provider');

$secret = new DuoScopedSecretProvider();
$secretAction = ['kind' => 'provider', 'provider' => 'scoped-probe', 'capability' => 'repair', 'args' => []];
$secretDecl = $secret->capabilities()['repair'];
$secretOp = $operation(Providers::scoped_input_hash($secretAction, $secretDecl), 'operation.0003');
$throws(
    static fn() => Providers::invoke_scoped($secret, $secretAction, $secretDecl, $secretOp),
    'secret-shaped',
    'secret-shaped provider evidence is refused before a verified receipt is persisted'
);
$throws(
    static fn() => Providers::reconcile_scoped($secret, $secretAction, $secretDecl, $secretOp),
    'recovery_required',
    'a rejected post-effect response leaves an intent that cannot be reinvoked'
);

$nativeArgs = ['name' => 'scoped_native'];
$nativeOp = $operation(NativeActions::scoped_input_hash('transient.delete', $nativeArgs), 'operation.0004');
$nativeNotStarted = NativeActions::reconcile_scoped('transient.delete', $nativeArgs, $nativeOp);
$check(
    $nativeNotStarted['status'] === 'not_started' && $GLOBALS['duo_scoped_effect_deletes'] === 0,
    'native reconciliation does not infer execution from transient absence without a durable receipt'
);
$GLOBALS['wpdb']->optionRows['_transient_scoped_native'] = 'stale';
$GLOBALS['wpdb']->optionRows['_transient_timeout_scoped_native'] = '123';
$GLOBALS['duo_scoped_effect_cache']['transient']['scoped_native'] = false;
$nativeReceipt = NativeActions::invoke_scoped('transient.delete', $nativeArgs, $nativeOp);
$nativeRecovered = NativeActions::reconcile_scoped('transient.delete', $nativeArgs, $nativeOp);
$check(
    $nativeReceipt['status'] === 'verified'
        && $nativeRecovered['status'] === 'verified'
        && $nativeRecovered['after_hash'] === $nativeReceipt['after_hash']
        && $nativeReceipt['capability_digest'] === NativeActions::scoped_action_digest('transient.delete')
        && $GLOBALS['duo_scoped_effect_deletes'] === 1,
    'native recovery reads the transient postcondition and never deletes a second time'
);

$wrongOp = $nativeOp;
$wrongOp['input_hash'] = str_repeat('b', 64);
$throws(
    static fn() => NativeActions::reconcile_scoped('transient.delete', $nativeArgs, $wrongOp),
    'input_hash',
    'a scoped native operation with a mismatched input witness is refused before any read/delete effect'
);

$GLOBALS['wpdb']->optionRows['permalink_structure'] = '/scoped/%postname%/';
$GLOBALS['wpdb']->optionRows['rewrite_rules'] = serialize([
    '^old/([0-9]+)/?$' => 'index.php?p=$matches[1]',
]);
$GLOBALS['wp_rewrite'] = new DuoScopedRewriteRuntime();
$rewriteOp = $operation(NativeActions::scoped_input_hash('rewrite.flush', []), 'operation.0005');
$rewriteNotStarted = NativeActions::reconcile_scoped('rewrite.flush', [], $rewriteOp);
$check(
    $rewriteNotStarted['status'] === 'not_started' && $GLOBALS['duo_scoped_rewrite_child_flushes'] === 0,
    'scoped rewrite reconciliation requires a durable receipt and never infers execution from target state'
);
$rewriteReceipt = NativeActions::invoke_scoped('rewrite.flush', [], $rewriteOp);
$rewriteRecovered = NativeActions::reconcile_scoped('rewrite.flush', [], $rewriteOp);
$check(
    $rewriteReceipt['status'] === 'verified'
        && $rewriteRecovered['status'] === 'verified'
        && $rewriteRecovered['after_hash'] === $rewriteReceipt['after_hash']
        && $rewriteReceipt['capability_digest'] === NativeActions::scoped_action_digest('rewrite.flush')
        && $GLOBALS['duo_scoped_rewrite_child_flushes'] === 1,
    'scoped rewrite recovery checks the persisted/runtime grammar against hash-only evidence without a second flush'
);
$GLOBALS['wpdb']->optionRows['rewrite_rules'] = serialize([
    '^drifted/?$' => 'index.php?drifted=1',
]);
$throws(
    static fn() => NativeActions::reconcile_scoped('rewrite.flush', [], $rewriteOp),
    'recovery_required',
    'scoped rewrite recovery refuses post-receipt rule drift instead of reinvoking the flush'
);
$check(
    $GLOBALS['duo_scoped_rewrite_child_flushes'] === 1,
    'a mismatched scoped rewrite readback never invokes the filesystem-or-database effect again'
);

if ($failures !== 0) {
    fwrite(STDERR, "$failures scoped effect reconciliation check(s) failed\n");
    exit(1);
}
echo "ALL PASSED\n";

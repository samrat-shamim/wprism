<?php
declare(strict_types=1);

/**
 * Offline regression for DUO-3338's native-action vocabulary and plugin-owned
 * provider contract, extended by DUO-3369's structured capability arguments
 * (`list<object>`) and engine batch context channels. No WordPress target, no
 * WP-CLI, no docker: the pieces under test are the closed vocabulary (pure PHP
 * by construction — it runs inside Policy's offline validation pass), the
 * negotiation gate (whose only live inputs are the four WordPress lifecycle
 * primitives stubbed below), and Apply's per-channel batch assembly (driven
 * through reflection against a two-query fake wpdb, the same idiom
 * regress_woocommerce_regen_engine.php uses for the regeneration dispatch).
 *
 * Same idiom as regress_adapter_contract.php: real, unmodified engine files
 * against a scratch DUO_MANIFESTS_DIR holding real fixture bytes.
 *
 * What this deliberately does NOT cover, because it genuinely needs a live
 * WordPress: the shipped providers' own invoke() bodies (WooCommerce's cache
 * boundary is exercised against a fake public API by
 * regress_woocommerce_deletion_authority.php; the CLI-backed ones need a
 * pair), and Apply's placement of the negotiation gate ahead of the first
 * mutation — plus the rebuild pass that surrounds the assembly proven here
 * (object-cache flush, receipt emission), both live-apply properties owned by
 * regress_provider_contract_live.sh.
 */

$root = dirname(__DIR__, 2);
define('DUO_SPEC_VERSION', 2);
// wpdb::get_results()'s output mode, which Ledger's own checked reads pass.
define('ARRAY_A', 'ARRAY_A');

// ---- WordPress lifecycle primitives Deploy::plugin_runtime_state() reads ----
// Defining validate_plugin() also short-circuits Deploy's wp-admin include.
$GLOBALS['duo_test_plugins'] = [];
$GLOBALS['duo_test_active'] = [];
$GLOBALS['duo_test_providers'] = [];
$GLOBALS['duo_test_provider_registry_throw'] = null;

function validate_plugin(string $plugin): mixed {
    return isset($GLOBALS['duo_test_plugins'][$plugin])
        ? 0
        : new \WP_Error("plugin '$plugin' does not exist");
}
function get_plugins(): array {
    return $GLOBALS['duo_test_plugins'];
}
function get_option(string $name, mixed $default = false): mixed {
    return $name === 'active_plugins' ? $GLOBALS['duo_test_active'] : $default;
}
function is_wp_error(mixed $thing): bool {
    return $thing instanceof \WP_Error;
}
function apply_filters(string $hook, mixed $value): mixed {
    if ($hook === 'duo_providers' && $GLOBALS['duo_test_provider_registry_throw'] !== null) {
        throw new \RuntimeException($GLOBALS['duo_test_provider_registry_throw']);
    }
    return $hook === 'duo_providers' ? $GLOBALS['duo_test_providers'] : $value;
}
// Apply::rebuild() flushes the object cache before and after the action loop
// and hard-fails on a false return; the count is asserted by the drive below,
// so the stub is evidence rather than a silencer.
$GLOBALS['duo_test_cache_flushes'] = 0;
function wp_cache_flush(): bool {
    $GLOBALS['duo_test_cache_flushes']++;
    return true;
}
class WP_Error {
    public function __construct(public string $message = '') {}
}

require $root . '/agent/src/Canon.php';
require $root . '/agent/src/OptionState.php';
require $root . '/agent/src/Policy.php';
require $root . '/agent/src/CodeCompatibility.php';
require $root . '/agent/src/Deploy.php';
require $root . '/agent/src/Providers.php';

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$condition) {
        $failures++;
    }
};

// ---- scratch manifests dir with a real manifest-shipped provider file ----
$dir = sys_get_temp_dir() . '/duo-provider-contract-' . getmypid();
@mkdir($dir . '/providers', 0700, true);
register_shutdown_function(static function () use ($dir): void {
    array_map('unlink', glob($dir . '/providers/*.php') ?: []);
    @rmdir($dir . '/providers');
    @rmdir($dir);
});
file_put_contents($dir . '/providers/probe-cache.php', <<<'PHP'
<?php
namespace Duo\Providers;

final class ProbeCache {
    public array $calls = [];
    public static ?array $capabilityMapOverride = null;
    public static array $capabilityOverrides = [];
    public static ?string $capabilitiesThrows = null;
    public static array $identityOverrides = [];
    public static ?string $identityThrows = null;
    public static ?string $invokeThrows = null;
    public static mixed $receiptOverride = null;
    public static float $sleepSeconds = 0.0;

    public function __construct(\Duo\Policy $policy) {}

    public function identity(): array {
        if (self::$identityThrows !== null) {
            throw new \RuntimeException(self::$identityThrows);
        }
        return self::$identityOverrides + [
            'id' => 'probe-cache',
            'plugin' => 'probe/probe.php',
            'version' => '1.0.0',
        ];
    }

    public function capabilities(): array {
        if (self::$capabilitiesThrows !== null) {
            throw new \RuntimeException(self::$capabilitiesThrows);
        }
        $default = [
            'flush' => self::$capabilityOverrides + [
                'args' => ['groups' => ['type' => 'list<string>', 'required' => true]],
                'reads' => ['option:probe_setting'],
                'writes' => ['entity:probe-cache-groups'],
                'scope' => 'site',
                'idempotent' => true,
                'timeout_seconds' => 30,
            ],
        ];
        return self::$capabilityMapOverride ?? $default;
    }

    public function invoke(string $capability, array $args): array {
        $this->calls[] = [$capability, $args];
        if (self::$invokeThrows !== null) {
            throw new \RuntimeException(self::$invokeThrows);
        }
        if (self::$sleepSeconds > 0.0) {
            usleep((int) (self::$sleepSeconds * 1000000));
        }
        if (self::$receiptOverride !== null) {
            return self::$receiptOverride;
        }
        return ['before' => ['groups' => []], 'after' => ['groups' => $args['groups'] ?? []], 'verified' => true];
    }
}
PHP);
// The plugin-sourced anchor check (Providers::plugin_anchor_problem())
// resolves the provider class's file against WP_PLUGIN_DIR/<plugin-dir>, so
// the harness models a real plugins tree: ProbeSupplied lives under
// wp-plugins/probe/ (anchored, negotiates clean), while ProbeCache's own
// file lives in the manifests providers/ dir (posing it as plugin-sourced
// must therefore refuse).
define('WP_PLUGIN_DIR', $dir . '/wp-plugins');
@mkdir($dir . '/wp-plugins/probe', 0700, true);
register_shutdown_function(static function () use ($dir): void {
    array_map('unlink', glob($dir . '/wp-plugins/probe/*.php') ?: []);
    @rmdir($dir . '/wp-plugins/probe');
    @rmdir($dir . '/wp-plugins');
});
file_put_contents($dir . '/wp-plugins/probe/duo-provider.php', <<<'PHP'
<?php
namespace Duo\Providers;

final class ProbeSupplied {
    public function identity(): array {
        return ['id' => 'probe-cache', 'plugin' => 'probe/probe.php', 'version' => '1.0.0'];
    }

    public function capabilities(): array {
        return [
            'flush' => [
                'args' => ['groups' => ['type' => 'list<string>', 'required' => true]],
                'reads' => ['option:probe_setting'],
                'writes' => ['entity:probe-cache-groups'],
                'scope' => 'site',
                'idempotent' => true,
                'timeout_seconds' => 30,
            ],
        ];
    }

    public function invoke(string $capability, array $args): array {
        return ['before' => [], 'after' => ['groups' => $args['groups'] ?? []], 'verified' => true];
    }
}
PHP);
require_once $dir . '/wp-plugins/probe/duo-provider.php';
putenv('DUO_MANIFESTS_DIR=' . $dir);
// Loaded up front so the per-case reset below can address the fixture's static
// override slots; Providers::negotiate() require_once's the same file itself.
require_once $dir . '/providers/probe-cache.php';

$manifest = [
    'name' => 'probe',
    'spec_version' => 2,
    'plugin' => 'probe/probe.php',
    'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
    'providers' => [[
        'id' => 'probe-cache',
        'version' => '1.0.0',
        'source' => 'manifest',
        'plugin' => 'probe/probe.php',
        'capabilities' => ['flush'],
    ]],
    'actions' => [[
        'kind' => 'provider',
        'provider' => 'probe-cache',
        'capability' => 'flush',
        'args' => ['groups' => ['probe-group']],
    ]],
];
$policyFor = static function (array $manifest): \Duo\Policy {
    return \Duo\Policy::from_snapshot([
        'format' => 'duo-policy-snapshot/v4',
        'adapter_sources' => ['format' => 'duo-adapter-sources/v1', 'out_of_tree' => []],
        'capabilities' => null,
        'dispositions' => null,
        'site' => [
            'manifests' => [$manifest['name']],
            'spec_version' => 2,
            'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []],
        ],
        'manifests' => [$manifest],
    ]);
};
$reset = static function (): void {
    $GLOBALS['duo_test_plugins'] = ['probe/probe.php' => ['Version' => '1.5.0']];
    $GLOBALS['duo_test_active'] = ['probe/probe.php'];
    $GLOBALS['duo_test_providers'] = [];
    $GLOBALS['duo_test_provider_registry_throw'] = null;
    \Duo\Providers\ProbeCache::$capabilityMapOverride = null;
    \Duo\Providers\ProbeCache::$capabilityOverrides = [];
    \Duo\Providers\ProbeCache::$capabilitiesThrows = null;
    \Duo\Providers\ProbeCache::$identityOverrides = [];
    \Duo\Providers\ProbeCache::$identityThrows = null;
    \Duo\Providers\ProbeCache::$invokeThrows = null;
    \Duo\Providers\ProbeCache::$receiptOverride = null;
    \Duo\Providers\ProbeCache::$sleepSeconds = 0.0;
};

echo "\n== closed native-action vocabulary ==\n";
$check(\Duo\NativeActions::vocabulary() === ['transient.delete'],
    'v1 vocabulary is exactly transient.delete — a plugin cannot mint an action name');
$expectMessage = static function (callable $body, string $needle, string $label) use ($check): void {
    try {
        $body();
        $check(false, $label);
    } catch (\Throwable $t) {
        $check(str_contains($t->getMessage(), $needle), $label . ' (message: ' . $t->getMessage() . ')');
    }
};
$expectMessage(
    static fn() => \Duo\NativeActions::validate('shell.exec', ['cmd' => 'rm -rf /'], 'probe'),
    'vocabulary is closed',
    'an unknown native action is refused with the closed vocabulary named'
);
$expectMessage(
    static fn() => \Duo\NativeActions::validate('transient.delete', ['name' => 'ok', 'ttl' => 5], 'probe'),
    'unknown key(s)',
    'an unknown argument key is refused rather than ignored'
);
$expectMessage(
    static fn() => \Duo\NativeActions::validate('transient.delete', [], 'probe'),
    'missing required key',
    'a missing required argument is refused'
);
$expectMessage(
    static fn() => \Duo\NativeActions::validate('transient.delete', ['name' => "a'; DROP TABLE wp_options; --"], 'probe'),
    'bounded string',
    'an argument outside the bounded charset is refused before any target contact'
);

echo "\n== negotiation: the supported path ==\n";
$reset();
$policy = $policyFor($manifest);
$selected = $policy->actions_for(['post:probe']);
$check(count($selected) === 1, 'the unscoped probe action is selected by a non-empty surface set');
$check($policy->actions_for([]) === [], 'an empty surface set selects nothing, so nothing is negotiated');
$negotiation = \Duo\Providers::negotiate($policy, $policy->actions_for([]));
$check($negotiation === ['problems' => [], 'providers' => [], 'capabilities' => []],
    'a run selecting no provider action touches no provider code at all');

$negotiation = \Duo\Providers::negotiate($policy, $selected);
$check($negotiation['problems'] === [], 'an installed, active, in-range provider with a matching identity negotiates clean');
$check($negotiation['providers']['probe-cache'] instanceof \Duo\Providers\ProbeCache,
    'the manifest-shipped provider class is loaded from <manifests_dir>/providers/<id>.php');
$check(($negotiation['capabilities']['probe-cache']['flush']['scope'] ?? null) === 'site',
    'the negotiated capability declaration is bound for the rebuild pass');

echo "\n== negotiation: every refusal names expected, found, and a remediation ==\n";
$problemFor = static function (array $mutate, ?callable $before = null) use ($policyFor, $manifest, $reset): array {
    $reset();
    if ($before !== null) {
        $before();
    }
    $m = $mutate === [] ? $manifest : array_replace_recursive($manifest, $mutate);
    $policy = $policyFor($m);
    $negotiation = \Duo\Providers::negotiate($policy, $policy->actions_for(['post:probe']));
    return $negotiation['problems'];
};
$one = static function (array $problems) use ($check): array {
    $check(count($problems) === 1, 'exactly one problem row is reported');
    return $problems[0] ?? [];
};
$providerSecret = "https://provider.example.test/rebuild?access_token=DUO_PROVIDER_SECRET\nINJECTED_PROVIDER_LINE";
$opaqueProviderProblem = static function (array $problem): bool {
    $serialized = json_encode($problem, JSON_THROW_ON_ERROR);
    return !str_contains($serialized, 'DUO_PROVIDER_SECRET')
        && !str_contains($serialized, 'INJECTED_PROVIDER_LINE')
        && !str_contains((string) ($problem['found'] ?? ''), "\n");
};
$opaqueProviderRefusal = static function (array $problem, string $code, string $found) use ($opaqueProviderProblem): bool {
    return ($problem['code'] ?? '') === $code
        && ($problem['found'] ?? '') === $found
        && $opaqueProviderProblem($problem);
};

$p = $one($problemFor([], static function (): void {
    $GLOBALS['duo_test_plugins'] = [];
    $GLOBALS['duo_test_active'] = [];
}));
$check(($p['code'] ?? '') === 'missing_plugin' && str_contains($p['remediation'] ?? '', 'install and activate'),
    'an uninstalled owning plugin is refused with an install remediation');

$p = $one($problemFor([], static function (): void {
    $GLOBALS['duo_test_active'] = [];
}));
$check(($p['code'] ?? '') === 'inactive_plugin' && str_contains($p['remediation'] ?? '', 'duo deploy'),
    'an inactive owning plugin is refused and pointed at deploy');

$p = $one($problemFor([], static function (): void {
    $GLOBALS['duo_test_plugins']['probe/probe.php']['Version'] = '2.4.0';
}));
$check(($p['code'] ?? '') === 'outside_version_range'
    && ($p['expected'] ?? '') === '>=1.0.0 <2.0.0'
    && ($p['found'] ?? '') === '2.4.0',
    'a live plugin version outside the declaring manifest range is refused with both versions named');

$p = $one($problemFor([], static function () use ($providerSecret): void {
    \Duo\Providers\ProbeCache::$identityOverrides = ['version' => $providerSecret];
}));
$check(($p['code'] ?? '') === 'identity_mismatch'
    && str_contains($p['expected'] ?? '', 'version=1.0.0')
    && ($p['found'] ?? '') === 'identity() did not match the declared provider identity'
    && $opaqueProviderProblem($p),
    'a provider identity mismatch is structured without exposing returned identity values');

$p = $one($problemFor(
    ['actions' => [['capability' => 'purge']], 'providers' => [['capabilities' => ['purge']]]],
    static function () use ($providerSecret): void {
        \Duo\Providers\ProbeCache::$capabilityMapOverride = [$providerSecret => []];
    }
));
$check(($p['code'] ?? '') === 'missing_capability'
    && ($p['found'] ?? '') === 'provider did not advertise the declared capability'
    && $opaqueProviderProblem($p),
    'a missing capability is structured without exposing advertised capability names');

$p = $one($problemFor([], static function (): void {
    \Duo\Providers\ProbeCache::$capabilityOverrides = ['idempotent' => false];
}));
$check(($p['code'] ?? '') === 'non_idempotent_capability' && str_contains($p['remediation'] ?? '', 'retry'),
    "a non-idempotent capability is refused because apply's retry re-fires the rebuild pass");

$p = $one($problemFor(['actions' => [['args' => ['groups' => 'not-a-list']]]]));
$check(($p['code'] ?? '') === 'invalid_capability_args'
    && ($p['found'] ?? '') === 'action arguments do not match advertised schema',
    'manifest arguments that do not match the provider-declared schema are refused without echoing validator text');

$p = $one($problemFor([], static function () use ($providerSecret): void {
    \Duo\Providers\ProbeCache::$capabilityOverrides = [$providerSecret => true];
}));
$check(($p['code'] ?? '') === 'malformed_capability'
    && ($p['found'] ?? '') === 'provider advertised a malformed capability declaration'
    && $opaqueProviderProblem($p),
    'a malformed capability is refused without exposing provider-controlled schema keys');

$p = $one($problemFor([], static function () use ($providerSecret): void {
    \Duo\Providers\ProbeCache::$capabilitiesThrows = $providerSecret;
}));
$check(($p['code'] ?? '') === 'contract_shape'
    && ($p['expected'] ?? '') === 'capabilities() returning a name => declaration map'
    && ($p['found'] ?? '') === 'capabilities() threw'
    && $opaqueProviderProblem($p),
    'a provider whose capabilities() throws becomes a structured, redacted contract problem');

$p = $one($problemFor([], static function () use ($providerSecret): void {
    \Duo\Providers\ProbeCache::$identityThrows = $providerSecret;
}));
$check(($p['code'] ?? '') === 'contract_shape'
    && ($p['expected'] ?? '') === 'identity() returning an array'
    && ($p['found'] ?? '') === 'identity() threw'
    && $opaqueProviderProblem($p),
    'a provider whose identity() throws becomes a structured, redacted contract problem');

echo "\n== plugin-sourced providers (a custom plugin advertising its own) ==\n";
$pluginSourced = array_replace_recursive($manifest, ['providers' => [['source' => 'plugin']]]);
$reset();
$policy = $policyFor($pluginSourced);
$p = $one(\Duo\Providers::negotiate($policy, $policy->actions_for(['post:probe']))['problems']);
$check(($p['code'] ?? '') === 'missing_plugin_provider' && str_contains($p['expected'] ?? '', 'duo_providers'),
    'a plugin-sourced provider nobody registered is a negotiation problem, not a load crash');

$registryUnavailableManifest = $pluginSourced;
$registryUnavailableManifest['providers'][] = [
    'id' => 'probe-second',
    'version' => '1.0.0',
    'source' => 'plugin',
    'plugin' => 'probe/probe.php',
    'capabilities' => ['flush'],
];
$registryUnavailableManifest['actions'][] = [
    'kind' => 'provider',
    'provider' => 'probe-second',
    'capability' => 'flush',
    'args' => ['groups' => ['probe-group']],
];
$reset();
$GLOBALS['duo_test_provider_registry_throw'] = $providerSecret;
$registryUnavailablePolicy = $policyFor($registryUnavailableManifest);
$registryProblems = \Duo\Providers::negotiate(
    $registryUnavailablePolicy,
    $registryUnavailablePolicy->actions_for(['post:probe'])
)['problems'];
$registryProblemJson = json_encode($registryProblems, JSON_THROW_ON_ERROR);
$registryProblemsAreOpaque = count($registryProblems) === 2;
$registryProblemProviders = array_map(
    static fn(array $problem): string => (string) ($problem['provider'] ?? ''),
    $registryProblems
);
sort($registryProblemProviders, SORT_STRING);
foreach ($registryProblems as $problem) {
    $registryProblemsAreOpaque = $registryProblemsAreOpaque
        && ($problem['code'] ?? '') === 'provider_registry_unavailable'
        && ($problem['expected'] ?? '') === 'a readable `duo_providers` registry'
        && ($problem['found'] ?? '') === 'provider registry callback failed'
        && ($problem['remediation'] ?? '') === 'upgrade or disable the faulty provider plugin and retry'
        && !str_contains((string) ($problem['found'] ?? ''), "\n");
}
$check(
    $registryProblemsAreOpaque
    && $registryProblemProviders === ['probe-cache', 'probe-second']
    && !str_contains($registryProblemJson, 'DUO_PROVIDER_SECRET')
    && !str_contains($registryProblemJson, 'INJECTED_PROVIDER_LINE'),
    'a throwing duo_providers registry blocks every selected plugin provider with one structured, redacted remediation'
);

$reset();
$GLOBALS['duo_test_providers'] = [new \Duo\Providers\ProbeSupplied()];
$negotiation = \Duo\Providers::negotiate($policy, $policy->actions_for(['post:probe']));
$check($negotiation['problems'] === [] && isset($negotiation['providers']['probe-cache']),
    'a provider advertised on the duo_providers filter is discovered and negotiated by its own identity()');

$reset();
$GLOBALS['duo_test_providers'] = [new \Duo\Providers\ProbeCache($policy)];
$p = $one(\Duo\Providers::negotiate($policy, $policy->actions_for(['post:probe']))['problems']);
$check(($p['code'] ?? '') === 'provider_outside_owning_plugin'
    && str_contains($p['remediation'] ?? '', 'source: manifest'),
    'a plugin-sourced registration whose class file lives outside the owning plugin directory is refused (identity stays falsifiable)');

$reset();
$GLOBALS['duo_test_providers'] = [new class {
    public function identity(): array {
        throw new \RuntimeException('third-party provider exploding on discovery');
    }
}, new \Duo\Providers\ProbeSupplied()];
$negotiation = \Duo\Providers::negotiate($policy, $policy->actions_for(['post:probe']));
$check($negotiation['problems'] === [] && isset($negotiation['providers']['probe-cache']),
    "an unrelated registration whose identity() throws is skipped, not a fatal for the provider actually wanted");

$reset();
$GLOBALS['duo_test_providers'] = [
    new class {
        public function identity(): array {
            return ['id' => new \stdClass()];
        }
    },
    new class($providerSecret) {
        public function __construct(private string $secret) {}

        public function identity(): array {
            return ['id' => new class($this->secret) {
                public function __construct(private string $secret) {}

                public function __toString(): string {
                    throw new \RuntimeException($this->secret);
                }
            }];
        }
    },
    new \Duo\Providers\ProbeSupplied(),
];
$negotiation = \Duo\Providers::negotiate($policy, $policy->actions_for(['post:probe']));
$malformedIdentityJson = json_encode($negotiation, JSON_THROW_ON_ERROR);
$check(
    $negotiation['problems'] === []
    && isset($negotiation['providers']['probe-cache'])
    && !str_contains($malformedIdentityJson, 'DUO_PROVIDER_SECRET')
    && !str_contains($malformedIdentityJson, 'INJECTED_PROVIDER_LINE'),
    'malformed or throwing Stringable plugin registration ids are skipped without a fatal or public payload leak'
);

echo "\n== entity scope negotiates its batch preconditions before any mutation ==\n";
$reset();
\Duo\Providers\ProbeCache::$capabilityOverrides = ['scope' => 'entity'];
$policy = $policyFor($manifest);
$p = $one(\Duo\Providers::negotiate($policy, $policy->actions_for(['post:probe']))['problems']);
$check(($p['code'] ?? '') === 'entity_scope_unscoped_action'
    && str_contains($p['remediation'] ?? '', 'scope: site'),
    'an entity-scoped capability on an unscoped action refuses at negotiation (its batch would always be empty)');

$reset();
\Duo\Providers\ProbeCache::$capabilityOverrides = ['scope' => 'entity'];
$entityManifest = $manifest;
$entityManifest['actions'][0]['triggers'] = ['option:probe_setting'];
$policy = $policyFor($entityManifest);
$p = $one(\Duo\Providers::negotiate($policy, $policy->actions_for(['option:probe_setting']))['problems']);
$check(($p['code'] ?? '') === 'entity_scope_unresolvable_trigger'
    && str_contains($p['found'] ?? '', 'option:probe_setting'),
    'an entity-scoped capability triggered on a surface with no per-entity id refuses at negotiation, not post-commit');
$reset();
$policy = $policyFor($manifest);

echo "\n== invocation: receipts, value-level verification, and the timeout budget ==\n";
$reset();
$policy = $policyFor($manifest);
$action = $policy->actions_for(['post:probe'])[0];
$negotiation = \Duo\Providers::negotiate($policy, [$action]);
$provider = $negotiation['providers']['probe-cache'];
$declaration = $negotiation['capabilities']['probe-cache']['flush'];
$receipt = \Duo\Providers::invoke($provider, $action, $declaration, []);
$check(($receipt['verified'] ?? null) === true
    && ($receipt['after']['groups'] ?? null) === ['probe-group']
    && is_float($receipt['duration_seconds'] ?? null),
    'a verified receipt carries the observed after-state and the measured duration');
$check($provider->calls === [['flush', ['groups' => ['probe-group']]]],
    'the capability receives exactly the manifest-declared arguments, and no engine-invented ones');

$expectInvokeFailure = static function (callable $before, string $needle, string $label) use (
    $check, $provider, $action, $declaration, $reset
): void {
    $reset();
    $before();
    try {
        \Duo\Providers::invoke($provider, $action, $declaration, []);
        $check(false, $label);
    } catch (\Throwable $t) {
        $check(str_contains($t->getMessage(), $needle), $label . ' (message: ' . $t->getMessage() . ')');
    }
};
$expectInvokeFailure(
    static fn() => \Duo\Providers\ProbeCache::$receiptOverride = ['before' => [], 'after' => [], 'verified' => false],
    'no value-level verification',
    'a receipt with verified !== true is refused: command success is not evidence the effect landed'
);
$expectInvokeFailure(
    static fn() => \Duo\Providers\ProbeCache::$receiptOverride = ['ok' => true],
    'malformed receipt',
    'a receipt missing before/after/verified is refused'
);
$receiptSecret = "https://provider.example.test/receipt?access_token=DUO_RECEIPT_SECRET\nINJECTED_RECEIPT_LINE";
$reset();
\Duo\Providers\ProbeCache::$receiptOverride = [$receiptSecret => true];
try {
    \Duo\Providers::invoke($provider, $action, $declaration, []);
    $check(false, 'a malformed receipt never publishes provider-returned receipt keys');
} catch (\Throwable $t) {
    $message = $t->getMessage();
    $check(
        str_contains($message, "provider 'probe-cache' capability 'flush'")
        && str_contains($message, 'exactly before, after, and verified are required')
        && !str_contains($message, 'DUO_RECEIPT_SECRET')
        && !str_contains($message, 'INJECTED_RECEIPT_LINE')
        && !str_contains($message, "\n"),
        'a malformed receipt error preserves provider/capability and required shape without exposing returned keys'
    );
}
$invokeSecret = "https://provider.example.test/invoke?access_token=DUO_INVOKE_SECRET\nINJECTED_INVOKE_LINE";
$reset();
\Duo\Providers\ProbeCache::$invokeThrows = $invokeSecret;
try {
    \Duo\Providers::invoke($provider, $action, $declaration, []);
    $check(false, 'a provider invocation failure never publishes the provider throwable');
} catch (\Throwable $t) {
    $message = $t->getMessage();
    $rendered = (string) $t;
    $check(
        str_contains($message, "provider 'probe-cache' capability 'flush' failed")
        && !str_contains($message, 'DUO_INVOKE_SECRET')
        && !str_contains($message, 'INJECTED_INVOKE_LINE')
        && !str_contains($message, "\n")
        && !str_contains($rendered, 'DUO_INVOKE_SECRET')
        && !str_contains($rendered, 'INJECTED_INVOKE_LINE')
        && $t->getPrevious() === null,
        'a provider invocation failure preserves provider/capability but redacts its throwable chain'
    );
}
// timeout_seconds is a positive integer, so the smallest honest overrun test
// is a one-second budget deliberately exceeded. Worth the wall-clock second:
// this is the only check that the post-hoc budget is enforced at all rather
// than merely declared.
$reset();
\Duo\Providers\ProbeCache::$sleepSeconds = 1.1;
try {
    \Duo\Providers::invoke($provider, $action, ['timeout_seconds' => 1] + $declaration, []);
    $check(false, 'an invocation past its declared timeout_seconds budget is a hard failure');
} catch (\Throwable $t) {
    $check(str_contains($t->getMessage(), 'overran its declared budget'),
        'an invocation past its declared timeout_seconds budget is a hard failure (message: '
        . $t->getMessage() . ')');
}
\Duo\Providers\ProbeCache::$sleepSeconds = 0.0;

// ======================================================================
// DUO-3369: structured capability arguments and engine batch context.
//
// Two additions to the same contract, both closed the way everything else
// here is closed: an argument may be a list of TYPED OBJECTS with a declared
// per-capability field vocabulary, and an entity-scoped capability may opt
// into engine batch channels (deletions/reparents/retry/always_on_write) that
// previously only the regenerator channel could receive. The load-bearing
// compatibility claim — an existing capability's declaration and injected
// batch are byte-identical — is a frozen-bytes check below, not prose.
// ======================================================================

echo "\n== list<object> arguments: the declaration's own field vocabulary ==\n";
$rowFields = [
    'kind' => ['type' => 'string', 'required' => true],
    'id' => ['type' => 'int', 'required' => true],
    'purged' => ['type' => 'bool', 'required' => false],
];
$rowsArgs = ['rows' => ['type' => 'list<object>', 'required' => true, 'fields' => $rowFields]];
// Declared args and action args move together: the capability declaration is
// validated before the manifest arguments are checked against it, so a
// malformed DECLARATION refuses first regardless of what the action passes.
$rowsNegotiation = static function (array $declaredArgs, array $actionArgs) use (
    $policyFor, $manifest, $reset
): array {
    $reset();
    \Duo\Providers\ProbeCache::$capabilityOverrides = ['args' => $declaredArgs];
    $m = $manifest;
    $m['actions'][0]['args'] = $actionArgs;
    $policy = $policyFor($m);
    return \Duo\Providers::negotiate($policy, $policy->actions_for(['post:probe']));
};
$rowsProblem = static function (array $declaredArgs, array $actionArgs) use ($rowsNegotiation, $one): array {
    return $one($rowsNegotiation($declaredArgs, $actionArgs)['problems']);
};

$goodRows = [['kind' => 'post:probe', 'id' => 7], ['kind' => 'term:probe_tax', 'id' => 3, 'purged' => true]];
$negotiation = $rowsNegotiation($rowsArgs, ['rows' => $goodRows]);
$check($negotiation['problems'] === []
    && ($negotiation['capabilities']['probe-cache']['flush']['args']['rows']['type'] ?? null) === 'list<object>',
    'a well-formed list<object> argument negotiates clean and binds with its field vocabulary');

$p = $rowsProblem(
    ['rows' => ['type' => 'list<object>', 'required' => true]],
    ['rows' => $goodRows]
);
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'a list<object> declaration without `fields` is refused through an opaque public capability diagnostic');

$p = $rowsProblem(
    ['rows' => ['type' => 'list<object>', 'required' => true, 'fields' => []]],
    ['rows' => $goodRows]
);
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'an EMPTY fields map is refused through an opaque public capability diagnostic');

$p = $rowsProblem(
    ['rows' => ['type' => 'list<object>', 'required' => true, 'fields' => ['kind', 'id']]],
    ['rows' => $goodRows]
);
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'a fields LIST (rather than a name => rule map) is refused through an opaque public capability diagnostic');

$p = $rowsProblem(
    ['rows' => ['type' => 'list<object>', 'required' => true, 'fields' => [
        'Kind Of Thing' => ['type' => 'string', 'required' => true],
    ]]],
    ['rows' => $goodRows]
);
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'a field name outside the bounded argument-name charset is refused without publishing the provider field name');

$p = $rowsProblem(
    ['rows' => ['type' => 'list<object>', 'required' => true, 'fields' => [
        'kind' => ['type' => 'string', 'required' => true, 'default' => 'post'],
    ]]],
    ['rows' => $goodRows]
);
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'an unknown key inside a field rule is refused without publishing the provider schema');

$p = $rowsProblem(
    ['rows' => ['type' => 'list<object>', 'required' => true, 'fields' => [
        'nested' => ['type' => 'list<object>', 'required' => true],
    ]]],
    ['rows' => $goodRows]
);
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'a list<object> INSIDE a list<object> is refused through an opaque public capability diagnostic');

$p = $rowsProblem(
    ['rows' => ['type' => 'list<object>', 'required' => true, 'fields' => [
        'tags' => ['type' => 'list<string>', 'required' => true],
    ]]],
    ['rows' => $goodRows]
);
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'a list-typed row field is refused through an opaque public capability diagnostic');

$p = $rowsProblem(
    ['rows' => ['type' => 'list<object>', 'required' => true, 'fields' => [
        'kind' => ['type' => 'string', 'required' => 'yes'],
    ]]],
    ['rows' => $goodRows]
);
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'a non-boolean field `required` is refused through an opaque public capability diagnostic');

$p = $rowsProblem(
    ['rows' => ['type' => 'list<string>', 'required' => true, 'fields' => $rowFields]],
    ['rows' => ['a']]
);
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'a scalar-typed argument may not carry a `fields` map through an opaque public capability diagnostic');

echo "\n== list<object> VALUES: two gates, load-time shape then negotiated vocabulary ==\n";
// Depth is bounded at LOAD (Policy::validate_provider_action(), where no
// provider code exists yet) and the field vocabulary at NEGOTIATION (where the
// capability's schema does). Both halves are asserted here so the split is
// visible rather than assumed: a shape the load gate refuses can never reach
// the negotiated check at all.
$expectLoadRefusal = static function (array $actionArgs, string $needle, string $label) use (
    $check, $policyFor, $manifest, $reset
): void {
    $reset();
    $m = $manifest;
    $m['actions'][0]['args'] = $actionArgs;
    try {
        $policyFor($m);
        $check(false, $label);
    } catch (\Throwable $t) {
        $check(str_contains($t->getMessage(), $needle), $label . ' (message: ' . $t->getMessage() . ')');
    }
};
$expectLoadRefusal(
    ['rows' => [['kind' => 'post:probe', 'nested' => [['deep' => 1]]]]],
    "row 0 field 'nested' must be a scalar",
    'a list<object> row carrying its own nested payload is refused at LOAD — one level is the manifest-side bound'
);
$expectLoadRefusal(
    ['rows' => [['Kind' => 'post:probe']]],
    'row 0 field names must match',
    'a row field name outside the bounded charset is refused at load'
);
$expectLoadRefusal(
    ['rows' => ['kind' => 'post:probe', 'id' => 7]],
    'must be a scalar or a list of scalars',
    'a single object where a LIST of objects belongs is refused at load'
);

$p = $rowsProblem($rowsArgs, ['rows' => [['kind' => 'post:probe', 'id' => 7, 'flush' => true]]]);
$check($opaqueProviderRefusal(
    $p,
    'invalid_capability_args',
    'action arguments do not match advertised schema'
), 'an undeclared row field is refused without publishing the provider field vocabulary');

$p = $rowsProblem($rowsArgs, ['rows' => [['kind' => 'post:probe']]]);
$check($opaqueProviderRefusal(
    $p,
    'invalid_capability_args',
    'action arguments do not match advertised schema'
), 'a missing required row field is refused through an opaque public argument diagnostic');

$p = $rowsProblem($rowsArgs, ['rows' => [['kind' => 'post:probe', 'id' => '7']]]);
$check($opaqueProviderRefusal(
    $p,
    'invalid_capability_args',
    'action arguments do not match advertised schema'
), 'a mistyped row field is refused through an opaque public argument diagnostic');

$p = $rowsProblem($rowsArgs, ['rows' => [['kind' => 'post:probe', 'id' => 7], 'not-an-object']]);
$check($opaqueProviderRefusal(
    $p,
    'invalid_capability_args',
    'action arguments do not match advertised schema'
), 'a scalar where a row belongs is refused through an opaque public argument diagnostic');

// The negotiated half of the one-level bound, reached directly because the
// load gate above already refuses this shape in a manifest: a capability
// negotiated from a non-manifest caller must still not receive nested rows.
$reset();
\Duo\Providers\ProbeCache::$capabilityOverrides = ['args' => $rowsArgs];
$validateArgs = new \ReflectionMethod(\Duo\Providers::class, 'validate_args');
try {
    $validateArgs->invoke(
        null,
        ['rows' => [['kind' => 'post:probe', 'id' => 7, 'purged' => [['deep' => 1]]]]],
        $rowsArgs,
        'probe args'
    );
    $check(false, 'a nested object list smuggled into a row VALUE is refused at negotiation too');
} catch (\Throwable $t) {
    $check(str_contains($t->getMessage(), "field 'purged' must be of type bool"),
        'a nested object list smuggled into a row VALUE is refused at negotiation too (message: '
        . $t->getMessage() . ')');
}
try {
    $validateArgs->invoke(null, ['rows' => [['post:probe', 7]]], $rowsArgs, 'probe args');
    $check(false, 'a positional row is refused as unknown fields rather than read positionally');
} catch (\Throwable $t) {
    $check(str_contains($t->getMessage(), 'does not declare: 0, 1'),
        'a positional row is refused as unknown fields rather than read positionally (message: '
        . $t->getMessage() . ')');
}

$optionalRows = ['rows' => ['type' => 'list<object>', 'required' => false, 'fields' => $rowFields]];
$negotiation = $rowsNegotiation($optionalRows, []);
$check($negotiation['problems'] === [], 'an optional list<object> argument the manifest omits negotiates clean');
$negotiation = $rowsNegotiation($rowsArgs, ['rows' => []]);
$check($negotiation['problems'] === [], 'an empty row list is a valid value — emptiness is the manifest\'s to state');

echo "\n== engine batch channels: a capability opts in, and may not mint one ==\n";
$channelNegotiation = static function (mixed $context, string $scope = 'entity') use (
    $policyFor, $manifest, $reset
): array {
    $reset();
    $overrides = ['scope' => $scope];
    if ($context !== null) {
        $overrides['context'] = $context;
    }
    \Duo\Providers\ProbeCache::$capabilityOverrides = $overrides;
    $m = $manifest;
    $m['actions'][0]['triggers'] = ['post:probe'];
    $policy = $policyFor($m);
    return \Duo\Providers::negotiate($policy, $policy->actions_for(['post:probe']));
};
$channelProblem = static function (mixed $context, string $scope = 'entity') use ($channelNegotiation, $one): array {
    return $one($channelNegotiation($context, $scope)['problems']);
};

$negotiation = $channelNegotiation(['deletions', 'retry']);
$check($negotiation['problems'] === []
    && ($negotiation['capabilities']['probe-cache']['flush']['context'] ?? null) === ['deletions', 'retry'],
    'an entity-scoped capability may declare engine batch channels, and the declaration binds for the rebuild pass');
$check(\Duo\Providers::CONTEXT_CHANNELS === ['always_on_write', 'deletions', 'reparents', 'retry'],
    'the channel vocabulary is exactly deletions, reparents, retry, always_on_write — a capability cannot mint a fifth');

$p = $channelProblem(['deletions', 'tombstones']);
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'an unknown channel is refused without publishing the provider-declared token');

$p = $channelProblem(['deletions', 'deletions']);
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'a duplicated channel is refused through an opaque public capability diagnostic');

$p = $channelProblem([]);
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'an empty context list is refused through an opaque public capability diagnostic');

$p = $channelProblem('deletions');
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'a bare channel string is refused through an opaque public capability diagnostic');

$p = $channelProblem(['deletions', 'reparents'], 'site');
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'context on a scope: site capability is refused without publishing declared channels');

echo "\n== internal schema validator detail stays private ==\n";
// Public readiness rows intentionally collapse untrusted provider schemas to
// fixed labels. The private validator still has exact author-facing detail;
// keep representative assertions here so redaction does not weaken the
// list<object> or context grammar itself.
$validateCapabilityDeclaration = new \ReflectionMethod(\Duo\Providers::class, 'validate_capability_declaration');
$expectInternalDeclarationRefusal = static function (array $decl, string $needle, string $label) use (
    $check, $validateCapabilityDeclaration
): void {
    try {
        $validateCapabilityDeclaration->invoke(null, $decl, 'probe capability');
        $check(false, $label);
    } catch (\Throwable $t) {
        $check(str_contains($t->getMessage(), $needle), $label . ' (validator: ' . $t->getMessage() . ')');
    }
};
$capabilityDeclaration = static function (array $args, string $scope = 'site', mixed $context = null): array {
    $decl = [
        'args' => $args,
        'idempotent' => true,
        'reads' => ['option:probe_setting'],
        'scope' => $scope,
        'timeout_seconds' => 30,
        'writes' => ['entity:probe-cache-groups'],
    ];
    if ($context !== null) {
        $decl['context'] = $context;
    }
    return $decl;
};
$expectInternalDeclarationRefusal(
    $capabilityDeclaration(['rows' => ['type' => 'list<object>', 'required' => true]]),
    'closed field vocabulary',
    'the private declaration validator keeps the list<object> field-vocabulary detail'
);
$expectInternalDeclarationRefusal(
    $capabilityDeclaration(['groups' => ['type' => 'list<string>', 'required' => true]], 'entity', ['deletions', 'tombstones']),
    "names 'tombstones'",
    'the private declaration validator keeps the closed context-channel detail'
);

echo "\n== the injected batch: an envelope only for what was declared ==\n";
$reset();
$policy = $policyFor($manifest);
$entityAction = $policy->actions_for(['post:probe'])[0];
$entityAction['triggers'] = ['post:probe'];
$batch = [['kind' => 'post:probe', 'id' => 7], ['kind' => 'term:probe_tax', 'id' => 3]];
$deletionRows = [['kind' => 'post:probe', 'uuid' => 'aaaa', 'id' => 41]];
$invokeWith = static function (array $context, array $channels = ['deletions', 'retry']) use (
    $policyFor, $manifest, $reset, $entityAction, $batch
): array {
    $reset();
    \Duo\Providers\ProbeCache::$capabilityOverrides = ['scope' => 'entity', 'context' => $channels];
    $policy = $policyFor($manifest);
    $provider = new \Duo\Providers\ProbeCache($policy);
    $declaration = $provider->capabilities()['flush'];
    \Duo\Providers::invoke($provider, $entityAction, $declaration, $batch, $context);
    return $provider->calls[0][1];
};
$args = $invokeWith(['deletions' => $deletionRows, 'retry' => true]);
$check(array_keys($args) === ['groups', 'entities'],
    'the envelope still rides under the single reserved `entities` argument key');
$check(array_keys($args['entities']) === ['entities', 'deletions', 'retry'],
    'the envelope carries entities plus ONLY the declared channels, in the engine\'s fixed order (reparents is absent, not empty)');
$check($args['entities']['entities'] === $batch
    && $args['entities']['deletions'] === $deletionRows
    && $args['entities']['retry'] === true,
    'each declared channel arrives with exactly the rows the engine assembled');
$args = $invokeWith(['deletions' => [], 'retry' => false]);
$check($args['entities'] === ['entities' => $batch, 'deletions' => [], 'retry' => false],
    'a declared-but-empty channel is present and empty — "nothing happened" is distinguishable from "never asked for"');
$args = $invokeWith(['always_on_write' => true], ['always_on_write']);
$check($args['entities'] === ['entities' => $batch, 'always_on_write' => true],
    'always_on_write rides as a boolean flag of its own — a passed fact, not a silent change of whether the capability ran');
$args = $invokeWith(
    ['always_on_write' => true, 'deletions' => $deletionRows, 'retry' => false],
    ['retry', 'deletions', 'always_on_write']
);
$check(array_keys($args['entities']) === ['entities', 'always_on_write', 'deletions', 'retry'],
    "the key order is the engine's, not the declaration's — a differently-ordered declaration receives the identical envelope");

$expectPayloadRefusal = static function (array $context, array $channels, string $needle, string $label) use (
    $check, $invokeWith
): void {
    try {
        $invokeWith($context, $channels);
        $check(false, $label);
    } catch (\Throwable $t) {
        $check(str_contains($t->getMessage(), $needle), $label . ' (message: ' . $t->getMessage() . ')');
    }
};
$expectPayloadRefusal(
    ['deletions' => $deletionRows, 'reparents' => []],
    ['deletions'],
    "'reparents' batch channel it never declared",
    'context the capability never declared is refused, not quietly delivered'
);
$expectPayloadRefusal(
    ['deletions' => $deletionRows],
    ['deletions', 'reparents'],
    "declared the 'reparents' batch channel but the engine assembled none",
    'a declared channel the engine failed to assemble is refused, not silently omitted'
);
$expectPayloadRefusal(
    ['deletions' => $deletionRows, 'retry' => 'yes'],
    ['deletions', 'retry'],
    "channel 'retry' was assembled as string",
    'a malformed channel value is refused at the injection boundary'
);
$expectPayloadRefusal(
    ['tombstones' => []],
    ['deletions'],
    'the engine does not assemble: tombstones',
    'a payload name outside the closed channel set is refused'
);

echo "\n== the engine half: what Apply assembles for each declared channel ==\n";
// The assembly is reachable offline, so it is proven here rather than deferred
// to the live leg: same idiom as regress_woocommerce_regen_engine.php, which
// drives Apply's private regeneration dispatch against a fake wpdb. Real,
// unmodified Apply.php; the only fake is the wpdb the ledger's identity and
// marker reads go through. The section after this one drives the whole
// rebuild() pass through the same fake, so the edges BETWEEN these projections
// and Providers::invoke() are covered too; what stays live-only is the rest of
// that pass (term recounts, attachment metadata, cron rescheduling).
require $root . '/agent/src/Db.php';
require $root . '/agent/src/Ledger.php';
require $root . '/agent/src/Apply.php';

final class ProbeBatchWpdb {
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $term_taxonomy = 'wp_term_taxonomy';
    public string $last_error = '';
    /** @var array<string,int> "<uuid>\0<id_kind>" => local id */
    public array $map = [];
    /** @var array<int,array{post_type:string,post_parent:int}> */
    public array $postsRows = [];
    /** @var array<string,string> the duo_kv keyspace (markers) */
    public array $kv = [];

    public function prepare(string $query, ...$args): string {
        foreach ($args as $arg) {
            $value = is_int($arg) || is_float($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'";
            $query = (string) preg_replace('/%[dsif]/', $value, $query, 1);
        }
        return $query;
    }

    public function query(string $query): int {
        if (preg_match("/INSERT INTO wp_duo_kv .*VALUES \\('((?:[^'\\\\]|\\\\.)*)', '((?:[^'\\\\]|\\\\.)*)'\\)/", $query, $m)) {
            $this->kv[stripslashes($m[1])] = stripslashes($m[2]);
            return 1;
        }
        if (preg_match("/DELETE FROM wp_duo_kv WHERE k = '((?:[^'\\\\]|\\\\.)*)'/", $query, $m)) {
            unset($this->kv[stripslashes($m[1])]);
            return 1;
        }
        return 1;
    }

    public function get_results(string $query, $output = null): array {
        if (!str_contains($query, 'SELECT k, v FROM wp_duo_kv')) {
            return [];
        }
        return array_map(
            static fn(string $k, string $v): array => ['k' => $k, 'v' => $v],
            array_keys($this->kv),
            array_values($this->kv)
        );
    }

    public function get_row(string $query, $output = null): ?array {
        if (preg_match('/FROM wp_posts WHERE ID = (\d+)/', $query, $m)) {
            return $this->postsRows[(int) $m[1]] ?? null;
        }
        return null;
    }

    /** Term recounts find no term_taxonomy rows, so the rebuild pass walks past them. */
    public function get_col(string $query): array {
        return [];
    }

    public function get_var(string $query): mixed {
        if (preg_match("/SELECT local_id FROM wp_duo_map WHERE uuid = '([^']+)' AND id_kind = '([^']+)'/", $query, $m)) {
            return $this->map[$m[1] . "\0" . $m[2]] ?? null;
        }
        if (preg_match("/SELECT uuid FROM wp_duo_map WHERE id_kind = '([^']+)' AND local_id = (\\d+)/", $query, $m)) {
            foreach ($this->map as $key => $id) {
                [$uuid, $kind] = explode("\0", $key, 2);
                if ($kind === $m[1] && (int) $id === (int) $m[2]) {
                    return $uuid;
                }
            }
            return null;
        }
        if (preg_match("/SELECT v FROM wp_duo_kv WHERE k = '((?:[^'\\\\]|\\\\.)*)'/", $query, $m)) {
            return $this->kv[stripslashes($m[1])] ?? null;
        }
        return null;
    }
}

$wpdb = new ProbeBatchWpdb();
$liveDeleted = '11111111-1111-4111-8111-111111111111';
$liveTerm = '22222222-2222-4222-8222-222222222222';
$forgottenDeleted = '33333333-3333-4333-8333-333333333333';
$otherAdapters = '44444444-4444-4444-8444-444444444444';
$moved = '55555555-5555-4555-8555-555555555555';
$oldParent = '66666666-6666-4666-8666-666666666666';
$newParent = '77777777-7777-4777-8777-777777777777';
// A tombstone applied by THIS run still resolves (Ledger::forget() runs in the
// ledger transaction after the rebuild pass); one already applied by a prior
// incomplete run may not.
$wpdb->map = [
    $liveDeleted . "\0post" => 41,
    $liveTerm . "\0term" => 9,
    $otherAdapters . "\0post" => 77,
    $moved . "\0post" => 204,
    $oldParent . "\0post" => 202,
    $newParent . "\0post" => 203,
];
// The moved post as the target still holds it: capture reads the CURRENT
// post_parent before phase 1 rewrites it.
$wpdb->postsRows = [204 => ['post_type' => 'probe', 'post_parent' => 202]];

$applyClass = new \ReflectionClass(\Duo\Apply::class);
$apply = $applyClass->newInstanceWithoutConstructor();
$applyPolicy = $applyClass->getProperty('policy');
$applyPolicy->setValue($apply, $policyFor($manifest));
$applyRetry = $applyClass->getProperty('retryingIncompleteApply');
// No setAccessible(): reflection reaches a private directly from PHP 8.1, and
// the engine already requires 8.1 or newer (array_is_list()). Calling it would
// only bury this section's own output in deprecation notices.
$applyMethods = [];
$applyPrivate = static function (string $method, array $args) use ($applyClass, $apply, &$applyMethods): mixed {
    $applyMethods[$method] ??= $applyClass->getMethod($method);
    return $applyMethods[$method]->invokeArgs($apply, $args);
};

$batchAction = [
    'provider' => 'probe-cache',
    'capability' => 'flush',
    'triggers' => ['post:probe', 'term:probe_tax'],
];
$deleteWork = [
    ['uuid' => $liveDeleted, 'type' => 'post', 'deletion_kind' => 'post', 'deletion_type' => 'probe'],
    ['uuid' => $liveTerm, 'type' => 'term', 'deletion_kind' => 'term', 'deletion_type' => 'probe_tax'],
    ['uuid' => $forgottenDeleted, 'type' => 'post', 'deletion_kind' => 'post', 'deletion_type' => 'probe'],
    ['uuid' => $otherAdapters, 'type' => 'post', 'deletion_kind' => 'post', 'deletion_type' => 'somebody_else'],
];
$deletions = $applyPrivate('action_deletions', [$batchAction, $deleteWork]);
$check($deletions === [
    ['kind' => 'post:probe', 'uuid' => $liveDeleted, 'id' => 41],
    ['kind' => 'post:probe', 'uuid' => $forgottenDeleted, 'id' => 0],
    ['kind' => 'term:probe_tax', 'uuid' => $liveTerm, 'id' => 9],
], 'the deletions channel carries {kind, uuid, id} for triggered tombstones only, ordered by surface then uuid');
$check(array_column($deletions, 'uuid') === [$liveDeleted, $forgottenDeleted, $liveTerm]
    && !in_array($otherAdapters, array_column($deletions, 'uuid'), true),
    "a tombstone on a surface this action's triggers do not name stays invisible to it (one adapter, one window)");
$check(($deletions[1]['id'] ?? null) === 0,
    'a tombstone whose ledger mapping is already gone carries id 0 rather than failing the pass or inventing an id');

$regenContext = [
    [
        'kind' => 'reparent',
        'uuid' => $moved,
        'id' => 204,
        'post_type' => 'probe',
        'old_parent_id' => 202,
        'new_parent_id' => 203,
        'parent_id' => 202,
        // The A root of a chained A->B->C move, preserved by
        // capture_regen_reparent_context()'s merge across a failed rebuild.
        'root_ids' => [201, 202, 203],
        'child_ids' => [],
    ],
    ['kind' => 'delete', 'uuid' => $liveDeleted, 'id' => 41, 'post_type' => 'probe', 'parent_id' => 0],
    ['kind' => 'reparent', 'uuid' => $otherAdapters, 'id' => 77, 'post_type' => 'somebody_else',
     'old_parent_id' => 1, 'new_parent_id' => 2, 'root_ids' => [1, 2]],
];
$reparents = $applyPrivate('action_reparents', [$batchAction, $regenContext]);
$check($reparents === [
    ['kind' => 'post:probe', 'uuid' => $moved, 'id' => 204, 'root_id' => 201,
     'old_parent_id' => 202, 'new_parent_id' => 203],
    ['kind' => 'post:probe', 'uuid' => $moved, 'id' => 204, 'root_id' => 202,
     'old_parent_id' => 202, 'new_parent_id' => 203],
    ['kind' => 'post:probe', 'uuid' => $moved, 'id' => 204, 'root_id' => 203,
     'old_parent_id' => 202, 'new_parent_id' => 203],
], 'the reparents channel normalizes one row per root, so a chained move loses no root to the scalar field grammar');
$check(count(array_filter($reparents, static fn(array $r): bool => $r['uuid'] === $otherAdapters)) === 0,
    'a reparent receipt for an untriggered surface is not delivered either');
$check(count(array_filter($reparents, static fn(array $r): bool => $r['uuid'] === $liveDeleted)) === 0,
    'a delete-kind receipt riding in the same context list is not mistaken for a reparent');

$applyRetry->setValue($apply, false);
$context = $applyPrivate('action_context', [
    $batchAction,
    ['scope' => 'entity', 'context' => ['deletions']],
    $deleteWork,
    $regenContext,
]);
$check(array_keys($context) === ['deletions'] && $context['deletions'] === $deletions,
    'only the declared channel is assembled — an undeclared one costs no work and delivers nothing');
$check($applyPrivate('action_context', [$batchAction, ['scope' => 'entity'], $deleteWork, $regenContext]) === [],
    'a capability declaring no context assembles nothing at all (the pre-DUO-3369 path)');
$applyRetry->setValue($apply, true);
$check($applyPrivate('action_context', [$batchAction, ['scope' => 'entity', 'context' => ['retry']], [], []])
    === ['retry' => true],
    "the retry channel reports the apply_in_progress marker this run's selection already consulted");
$check($applyPrivate('action_context', [
        $batchAction,
        ['scope' => 'entity', 'context' => ['always_on_write']],
        [],
        [],
    ]) === ['always_on_write' => true],
    'always_on_write is assembled as the flag it is — true because it was declared, not because anything happened');

echo "\n== the empty-batch skip: narrowed, not loosened ==\n";
$noChannels = ['scope' => 'entity'];
$withDeletions = ['scope' => 'entity', 'context' => ['deletions']];
$alwaysOn = ['scope' => 'entity', 'context' => ['deletions', 'always_on_write']];
$alwaysOnAlone = ['scope' => 'entity', 'context' => ['always_on_write']];
$hasWork = static fn(array $declaration, array $entities, array $channels): bool =>
    (bool) $applyPrivate('action_batch_has_work', [$declaration, $entities, $channels]);
$check($hasWork($noChannels, [], []) === false,
    'the DUO-3338 skip survives verbatim: a channel-less capability with an empty entity batch is still skipped');
$check($hasWork($noChannels, [['kind' => 'post:probe', 'id' => 7]], []) === true,
    'a non-empty entity batch is work, as before');
$check($hasWork($withDeletions, [], ['deletions' => $deletions]) === true,
    'a deletion-only selection is NO LONGER skipped once the capability declared the deletions channel');
$check($hasWork($withDeletions, [], ['deletions' => []]) === false,
    'the skip receipt still stands when every declared channel came back empty and nothing was written');
$check($hasWork($alwaysOn, [], ['always_on_write' => true, 'deletions' => []]) === false,
    'always_on_write does NOT manufacture work: with nothing written and its other channel empty, the skip still stands');
$check($hasWork($alwaysOnAlone, [], ['always_on_write' => true]) === false,
    'a capability declaring ONLY always_on_write is skipped on an empty run — the flag it mirrors suppresses a '
    . 'per-candidate check, it never creates a candidate');
$check($hasWork($alwaysOn, [], ['always_on_write' => true, 'deletions' => $deletions]) === true,
    'the same capability fires the moment a real channel carries something');
$check($hasWork(['scope' => 'entity', 'context' => ['retry']], [], ['retry' => true]) === true
    && $hasWork(['scope' => 'entity', 'context' => ['retry']], [], ['retry' => false]) === false,
    'a retry is work in its own right; an ordinary run with nothing else is not');

echo "\n== the rebuild pass: the edges between those projections and invoke() ==\n";
// Everything above proves what each helper RETURNS. Three edges live one frame
// out and are invisible to those checks: which tombstone set the deletions
// channel is composed from, whether the retry state reaches the envelope, and
// whether the assembled context reaches Providers::invoke() at all. rebuild()
// is drivable offline with the same fake wpdb (its other steps — regen
// dispatch, cron rescheduling, term recounts, attachment metadata — all walk
// past an empty work set and an empty term_taxonomy), so the edges are proven
// here rather than asserted.
$applyWarnings = $applyClass->getProperty('warnings');
$applyReceipts = $applyClass->getProperty('actionReceipts');
$applySelected = $applyClass->getProperty('selectedActions');
$applyNegotiated = $applyClass->getProperty('negotiatedProviders');
$rebuildMethod = $applyClass->getMethod('rebuild');

$drivePolicy = $policyFor($manifest);
$driveAction = $drivePolicy->actions_for(['post:probe'])[0];
$driveAction['triggers'] = ['post:probe'];

/**
 * One rebuild() pass with a scratch provider in the negotiated slot, returning
 * what the capability received plus the pass's own warnings and receipts. A
 * throw comes back as a value rather than a fatal, so a broken edge reads as a
 * FAIL line instead of exit 255.
 *
 * $rebuildArgs is rebuild()'s own parameter list:
 * [attachmentIds, work, tree, regenContext, deleteWork, withDeletes, absentTombstones].
 */
$driveRebuild = static function (array $channels, array $rebuildArgs, bool $retrying = false) use (
    $applyClass, $applyPolicy, $applyRetry, $applyWarnings, $applyReceipts, $applySelected,
    $applyNegotiated, $rebuildMethod, $drivePolicy, $driveAction, $reset
): array {
    $reset();
    \Duo\Providers\ProbeCache::$capabilityOverrides = $channels === []
        ? ['scope' => 'entity']
        : ['scope' => 'entity', 'context' => $channels];
    $provider = new \Duo\Providers\ProbeCache($drivePolicy);
    $apply = $applyClass->newInstanceWithoutConstructor();
    $applyPolicy->setValue($apply, $drivePolicy);
    $applyRetry->setValue($apply, $retrying);
    $applySelected->setValue($apply, [$driveAction]);
    $applyNegotiated->setValue($apply, [
        'providers' => ['probe-cache' => $provider],
        'capabilities' => ['probe-cache' => ['flush' => $provider->capabilities()['flush']]],
    ]);
    $error = '';
    try {
        $rebuildMethod->invokeArgs($apply, $rebuildArgs);
    } catch (\Throwable $t) {
        $error = $t->getMessage();
    }
    return [
        'args' => $provider->calls[0][1] ?? null,
        'calls' => count($provider->calls),
        'warnings' => implode("\n", (array) $applyWarnings->getValue($apply)),
        'receipts' => (array) $applyReceipts->getValue($apply),
        'error' => $error,
    ];
};

$driveTombstones = [
    ['uuid' => $liveDeleted, 'type' => 'post', 'deletion_kind' => 'post', 'deletion_type' => 'probe'],
];
$driveAbsent = [
    ['uuid' => $forgottenDeleted, 'type' => 'post', 'deletion_kind' => 'post', 'deletion_type' => 'probe'],
];

$wpdb->kv = [];
$flushesBefore = $GLOBALS['duo_test_cache_flushes'];
$run = $driveRebuild(['deletions'], [[], [], [], [], $driveTombstones, true, []]);
$check($run['error'] === '' && $run['calls'] === 1
    && ($run['args']['entities']['deletions'] ?? null)
        === [['kind' => 'post:probe', 'uuid' => $liveDeleted, 'id' => 41]],
    'the tombstones a --with-deletes run APPLIED reach the capability through the whole pass, not just the projection');
$check(array_keys((array) ($run['args']['entities'] ?? [])) === ['entities', 'deletions']
    && ($run['args']['entities']['entities'] ?? null) === [],
    'the assembled context reaches invoke() as the envelope — a bare batch here would mean the pass dropped it');
$check($GLOBALS['duo_test_cache_flushes'] === $flushesBefore + 2,
    'the pass still flushes the object cache either side of the action loop (the drive is the real rebuild(), not a stub)');

$run = $driveRebuild(['deletions'], [[], [], [], [], $driveTombstones, false, []]);
$check($run['error'] === '' && $run['calls'] === 0,
    'THE FAIL-OPEN CASE: the identical tombstone selection without --with-deletes invokes nothing — those entities '
    . 'were planned for deletion and are all still present');
$check(($run['receipts'][0]['skipped'] ?? '')
    === 'empty entity batch and no declared batch channel carried work (deletions empty)'
    && str_contains($run['warnings'], 'no declared batch channel carried work: deletions empty'),
    'and the skip is explicit in both the receipt and the human line, naming the channel that came back empty');

$run = $driveRebuild(['deletions', 'retry'], [[], [], [], [], [], false, $driveAbsent], true);
$check($run['error'] === '' && $run['calls'] === 1
    && ($run['args']['entities']['retry'] ?? null) === true
    && ($run['args']['entities']['deletions'] ?? null)
        === [['kind' => 'post:probe', 'uuid' => $forgottenDeleted, 'id' => 0]],
    "while retrying an incomplete apply, the run's retry state and its already-absent tombstones both reach invoke()");
$run = $driveRebuild(['deletions', 'retry'], [[], [], [], [], [], false, $driveAbsent], false);
$check($run['error'] === '' && $run['calls'] === 0,
    'the same already-absent rows on an ordinary run are not evidence about this run: nothing is delivered, nothing fires');
$check(str_contains($run['warnings'], 'deletions empty, retry false'),
    'the skip line renders a declared boolean channel as false rather than as "empty" (an empty list it is not)');

$run = $driveRebuild(['always_on_write'], [[], [], [], [], $driveTombstones, true, []]);
$check($run['error'] === '' && $run['calls'] === 0,
    'a capability declaring ONLY always_on_write is skipped on a run whose declared channels carry nothing');
$check(str_contains(
    (string) ($run['receipts'][0]['skipped'] ?? ''),
    'always_on_write (a flag; never work of its own)'
), 'and the receipt names the flag for what it is instead of calling a boolean empty');

$reparentMarker = json_encode([
    'kind' => 'reparent', 'uuid' => $moved, 'id' => 204, 'post_type' => 'probe',
    'parent_id' => 202, 'old_parent_id' => 202, 'new_parent_id' => 203,
    'root_ids' => [201, 202, 203], 'child_ids' => [],
]);
$wpdb->kv = ['regen_reparent_context:' . $moved => (string) $reparentMarker];
$run = $driveRebuild(['reparents'], [[], [], [], [], [], false, []]);
$check($run['error'] === '' && $run['calls'] === 1
    && array_column((array) ($run['args']['entities']['reparents'] ?? []), 'root_id') === [201, 202, 203],
    'an outstanding reparent marker with NO fresh capture this run still reaches a reparents-declaring capability');
$check(!isset($wpdb->kv['regen_reparent_context:' . $moved]),
    'and it had to be read before the regen pass, which sweeps a marker no batch regenerator can consume — the '
    . 'ordering is load-bearing, not incidental');

$wpdb->kv = ['regen_reparent_context:' . $moved => (string) $reparentMarker];
$run = $driveRebuild(['reparents'], [[], [], [], [
    ['kind' => 'reparent', 'uuid' => $moved, 'id' => 204, 'post_type' => 'probe',
     'old_parent_id' => 203, 'new_parent_id' => 205, 'root_ids' => [203, 205]],
], [], false, []]);
$unionRows = (array) ($run['args']['entities']['reparents'] ?? []);
$check(array_column($unionRows, 'root_id') === [201, 202, 203, 205],
    'marker and fresh capture union without duplicating a root: the dedupe key is (kind, uuid, root_id)');
$check(($unionRows[2]['new_parent_id'] ?? null) === 205,
    'a root both sources name takes the fresh receipt, which already merged the marker rather than replacing it');
$wpdb->kv = [];

// run() itself is not drivable offline (promotion lease, plan, authored
// transaction, convergence recapture), so its two threading edges into this
// pass are asserted against its own source — the idiom
// regress_woocommerce_regen_engine.php uses for the same class of claim.
// Reverting either line in Apply.php fails exactly one of these two checks.
$runMethod = $applyClass->getMethod('run');
$runSource = implode("\n", array_slice(
    (array) file((string) $runMethod->getFileName(), FILE_IGNORE_NEW_LINES),
    $runMethod->getStartLine() - 1,
    $runMethod->getEndLine() - $runMethod->getStartLine() + 1
));
$check((bool) preg_match(
    '/\$this->rebuild\(\s*\$attachmentIds,\s*\$work,\s*\$tree,\s*\$regenContext,\s*\$deleteWork,'
    . '\s*!empty\(\$opts\[\'with_deletes\'\]\),\s*\$plan\[\'deleted\'\]\s*\);/',
    $runSource
), "run() hands the rebuild pass this run's tombstones, the with_deletes gate, and the already-absent set — never "
    . 'the wider set the pre-mutation selection projected surfaces from');
$check((bool) preg_match('/\$this->retryingIncompleteApply\s*=\s*\$retryingIncompleteApply;/', $runSource),
    'run() records its apply_in_progress read on the instance, which is the only path by which the retry channel '
    . 'can ever be true');

echo "\n== the capture behind the reparents channel: scoped to DECLARED consumers ==\n";
// DUO-3369 review, F2(a): scoping the capture to batch regen_dependency post
// types alone made the channel structurally empty for a provider-only
// manifest — a capability could declare `reparents`, negotiate clean, and
// never receive a row no matter what the revision moved.
$captureMethod = $applyClass->getMethod('capture_regen_reparent_context');
$captureWork = [['uuid' => $moved]];
$captureTree = [$moved => ['type' => 'post', 'data' => ['type' => 'probe', 'parent' => '{{post:' . $newParent . '}}']]];
$captureWith = static function (array $channels, array $triggers = ['post:probe']) use (
    $applyClass, $applyPolicy, $applySelected, $applyNegotiated, $captureMethod,
    $drivePolicy, $driveAction, $captureWork, $captureTree, &$wpdb
): array {
    $wpdb->kv = [];
    $apply = $applyClass->newInstanceWithoutConstructor();
    $applyPolicy->setValue($apply, $drivePolicy);
    $applySelected->setValue($apply, [['triggers' => $triggers] + $driveAction]);
    $declaration = $channels === []
        ? ['scope' => 'entity']
        : ['scope' => 'entity', 'context' => $channels];
    $applyNegotiated->setValue($apply, [
        'providers' => [],
        'capabilities' => ['probe-cache' => ['flush' => $declaration]],
    ]);
    return (array) $captureMethod->invokeArgs($apply, [$captureWork, $captureTree]);
};
$captured = $captureWith(['reparents']);
$check(count($captured) === 1
    && ($captured[0]['post_type'] ?? null) === 'probe'
    && ($captured[0]['old_parent_id'] ?? null) === 202
    && ($captured[0]['new_parent_id'] ?? null) === 203,
    'a provider-only manifest (no regen_batch anywhere) whose capability declares reparents DOES get the capture');
$check(isset($wpdb->kv['regen_reparent_context:' . $moved]),
    'and the durable marker is written exactly as the batch path writes it, so the retry above has something to find');
$check($captureWith([]) === [] && $wpdb->kv === [],
    'a post type no declared consumer names is still not captured — the gate widened to declared consumers, not to everything');
$check($captureWith(['deletions']) === [],
    'declaring some OTHER channel does not open the reparent capture either');
$check($captureWith(['reparents'], ['post:somebody_else']) === [],
    "a reparents-declaring capability triggered on another adapter's surface captures nothing here");
$wpdb->kv = [];

echo "\n== byte-compatibility with the pre-DUO-3369 contract, in frozen bytes ==\n";
// Both literals below were captured by running THIS harness's fixtures through
// the engine as of main@40b54fe (the commit before DUO-3369) and printing
// serialize() of the negotiated declaration map and of the arguments the
// capability received. They are the acceptance criterion in executable form:
// an existing scalar-args, channel-less provider must not be able to observe
// that either channel grew.
$reset();
$policy = $policyFor($manifest);
$negotiation = \Duo\Providers::negotiate($policy, $policy->actions_for(['post:probe']));
$check(
    serialize($negotiation['capabilities'])
        === 'a:1:{s:11:"probe-cache";a:1:{s:5:"flush";a:6:{s:4:"args";a:1:{s:6:"groups";a:2:{s:4:"type";'
            . 's:12:"list<string>";s:8:"required";b:1;}}s:5:"reads";a:1:{i:0;s:20:"option:probe_setting";}'
            . 's:6:"writes";a:1:{i:0;s:25:"entity:probe-cache-groups";}s:5:"scope";s:4:"site";s:10:'
            . '"idempotent";b:1;s:15:"timeout_seconds";i:30;}}}',
    'a scalar-args capability negotiates to declaration bytes identical to the pre-change engine (no key added, none reordered)'
);
$reset();
\Duo\Providers\ProbeCache::$capabilityOverrides = ['scope' => 'entity'];
$policy = $policyFor($manifest);
$byteAction = $policy->actions_for(['post:probe'])[0];
$byteAction['triggers'] = ['post:probe'];
$byteProvider = new \Duo\Providers\ProbeCache($policy);
\Duo\Providers::invoke($byteProvider, $byteAction, $byteProvider->capabilities()['flush'], $batch);
$check(
    serialize($byteProvider->calls[0][1])
        === 'a:2:{s:6:"groups";a:1:{i:0;s:11:"probe-group";}s:8:"entities";a:2:{i:0;a:2:{s:4:"kind";'
            . 's:10:"post:probe";s:2:"id";i:7;}i:1;a:2:{s:4:"kind";s:14:"term:probe_tax";s:2:"id";i:3;}}}',
    'a channel-less entity-scoped capability receives the bare batch, byte-identical to the pre-change engine'
);
$reset();

echo "\n== manifest-shipped provider code is part of the manifest artifact ==\n";
unlink($dir . '/providers/probe-cache.php');
$reset();
$policy = $policyFor($manifest);
$expectMessage(
    static fn() => \Duo\Providers::negotiate($policy, $policy->actions_for(['post:probe'])),
    'provider code ships with its manifest, not the engine',
    'a missing manifest-shipped provider file is a packaging fault that throws, not an environment problem'
);

echo $failures === 0 ? "\nALL PASSED\n" : "\n$failures check(s) failed\n";
exit($failures === 0 ? 0 : 1);

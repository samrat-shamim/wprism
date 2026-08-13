<?php
declare(strict_types=1);

/*
 * DUO-3344 offline public-host proof.
 *
 * The public cli/duo process reaches a real isolated rollback-control runtime
 * through fake ssh/scp/wp executables.  The target's wp command is deliberately
 * tiny: target PromotionLock/Apply behavior is covered by
 * regress_scoped_promotion_target.php, while this fixture proves the host's
 * strict local contract, compact-wire, signed external checkpoint sequence,
 * response-loss and controller-death recovery, the forward-only
 * fresh-verification seal, and pre-fresh-verification rollback boundaries.
 */

use Duo\Canon;
use Duo\Recovery\RecoveryExecutor;
use Duo\Recovery\RollbackControl;

$root = $argv[1] ?? realpath(__DIR__ . '/../../..');
if (!is_string($root) || $root === '' || !is_file($root . '/cli/duo')) {
    fwrite(STDERR, "FAIL: repository root is unavailable\n");
    exit(1);
}

require_once $root . '/agent/src/Canon.php';
require_once $root . '/recovery/rollback-control.php';

function scoped_host_fail(string $message): never {
    throw new RuntimeException('FAIL: ' . $message);
}

function scoped_host_ok(bool $condition, string $message): void {
    if (!$condition) {
        scoped_host_fail($message);
    }
    echo "ok: $message\n";
}

function scoped_host_write(string $path, string $bytes, int $mode = 0600): void {
    $parent = dirname($path);
    if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
        scoped_host_fail("could not create fixture directory $parent");
    }
    if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
        scoped_host_fail("could not write fixture file $path");
    }
    if (!chmod($path, $mode)) {
        scoped_host_fail("could not chmod fixture file $path");
    }
}

function scoped_host_remove(string $path): void {
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $child) {
        if ($child !== '.' && $child !== '..') {
            scoped_host_remove($path . '/' . $child);
        }
    }
    @rmdir($path);
}

/** @return array{exit:int,stdout:string,stderr:string} */
function scoped_host_run(array $command, ?string $cwd = null): array {
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        scoped_host_fail('could not start fixture command');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

/** @return array<string,mixed> */
function scoped_host_json(string $bytes, string $label): array {
    try {
        $decoded = json_decode(trim($bytes), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $failure) {
        scoped_host_fail("$label was not JSON: " . $failure->getMessage());
    }
    if (!is_array($decoded)) {
        scoped_host_fail("$label was not an object");
    }
    return $decoded;
}

/** @return list<string> */
function scoped_host_lines(string $path): array {
    if (!is_file($path)) {
        return [];
    }
    return array_values(array_filter(
        array_map('trim', file($path, FILE_IGNORE_NEW_LINES) ?: []),
        static fn(string $line): bool => $line !== ''
    ));
}

function scoped_host_clear(string ...$paths): void {
    foreach ($paths as $path) {
        scoped_host_write($path, '', 0600);
    }
}

/** @return list<list<string>> */
function scoped_host_wp_requests(string $path): array {
    $requests = [];
    foreach (scoped_host_lines($path) as $line) {
        $decoded = scoped_host_json($line, 'fake wp log line');
        if (!array_is_list($decoded) || !array_reduce(
            $decoded,
            static fn(bool $ok, mixed $value): bool => $ok && is_string($value),
            true
        )) {
            scoped_host_fail('fake wp log did not contain an argv list');
        }
        /** @var list<string> $decoded */
        $requests[] = $decoded;
    }
    return $requests;
}

/** @param list<list<string>> $requests @return list<string> */
function scoped_host_wp_subcommands(array $requests): array {
    $subcommands = [];
    foreach ($requests as $args) {
        $duo = array_search('duo', $args, true);
        if ($duo === false || !is_string($args[$duo + 1] ?? null)) {
            scoped_host_fail('fake wp received a non-Duo command');
        }
        $subcommands[] = $args[$duo + 1];
    }
    return $subcommands;
}

/** @param list<list<string>> $requests @return list<array{sub:string,wire:array<string,mixed>}> */
function scoped_host_compact_requests(array $requests): array {
    $out = [];
    foreach ($requests as $args) {
        $duo = array_search('duo', $args, true);
        if ($duo === false) {
            continue;
        }
        $sub = (string) ($args[$duo + 1] ?? '');
        if (!in_array($sub, ['plan', 'apply'], true)) {
            continue;
        }
        $wire = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--scope-request-b64=')) {
                $wire = substr($arg, strlen('--scope-request-b64='));
            }
            if (str_starts_with($arg, '--scope-contract')) {
                scoped_host_fail("$sub received a host-local scope contract path");
            }
        }
        if (!is_string($wire) || $wire === '') {
            scoped_host_fail("$sub received no compact scope request");
        }
        $decoded = base64_decode($wire, true);
        if (!is_string($decoded)) {
            scoped_host_fail("$sub compact request was not base64");
        }
        $out[] = ['sub' => $sub, 'wire' => scoped_host_json($decoded, "$sub compact request")];
    }
    return $out;
}

/** @return list<string> */
function scoped_host_event_steps(string $control, string $receiptId): array {
    $events = glob(dirname($control) . '/rollback/' . $receiptId . '/events/*.json') ?: [];
    sort($events, SORT_STRING);
    $steps = [];
    foreach ($events as $path) {
        $signed = scoped_host_json((string) file_get_contents($path), 'signed authority event');
        $event = $signed['payload'] ?? null;
        if (!is_array($event)) {
            scoped_host_fail('signed authority event had no payload');
        }
        $steps[] = implode('/', [
            (string) ($event['state'] ?? ''),
            (string) ($event['operation_status'] ?? ''),
            (string) ($event['operation_id'] ?? ''),
        ]);
    }
    return $steps;
}

/** @return array{exit:int,stdout:string,stderr:string} */
function scoped_host_promote(string $root, string $envs, string $contract, array $extra = [], array $environment = []): array {
    $before = [];
    foreach ($environment as $name => $value) {
        $before[$name] = getenv($name);
        if ($value === null) {
            putenv($name);
        } else {
            putenv($name . '=' . $value);
        }
    }
    try {
        $command = array_merge([
            PHP_BINARY,
            $root . '/cli/duo',
            '--envs-file=' . $envs,
            'promote',
            'scoped',
        ], $extra === [] ? ['--scope-contract=' . $contract, '--with-deletes', '--format=json'] : $extra);
        $controllerPidFile = getenv('DUO3344_VERIFYING_NEW_CONTROLLER_PID_FILE');
        if ((string) getenv('DUO3344_KILL_VERIFYING_NEW_CONTROLLER') === '1'
            && is_string($controllerPidFile) && $controllerPidFile !== '') {
            // `exec` preserves this wrapper PID as the controller process,
            // letting the fake SSH boundary kill precisely that controller
            // after it has durably published the first seal event.
            $shell = 'printf "%s\\n" "$$" > ' . escapeshellarg($controllerPidFile)
                . ' && exec ' . implode(' ', array_map('escapeshellarg', $command));
            return scoped_host_run(['/bin/sh', '-c', $shell]);
        }
        return scoped_host_run($command);
    } finally {
        foreach ($before as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
    }
}

$tmp = sys_get_temp_dir() . '/duo-3344-scoped-promote-' . bin2hex(random_bytes(8));
$oldPath = (string) getenv('PATH');
$secret = '';
try {
    if (!mkdir($tmp, 0700, true)) {
        scoped_host_fail('could not create temporary fixture root');
    }
    $bin = $tmp . '/bin';
    $target = $tmp . '/target';
    $wordpress = $tmp . '/wordpress';
    $logs = $tmp . '/logs';
    foreach ([$bin, $target, $wordpress, $logs] as $directory) {
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            scoped_host_fail("could not create $directory");
        }
    }

    $sshLog = $logs . '/ssh.log';
    $scpLog = $logs . '/scp.log';
    $wpLog = $logs . '/wp.log';
    $providerLog = $logs . '/provider.log';
    $wireLog = $logs . '/wire.log';
    $orderLog = $logs . '/order.log';
    $targetReplayLog = $logs . '/target-replay.log';
    $targetSession = $tmp . '/target-session.json';
    $targetTerminals = $tmp . '/target-terminals';
    $dropMarker = $tmp . '/claim-response-dropped';
    $completeDropMarker = $tmp . '/complete-response-dropped';
    $verifyingDropMarker = $tmp . '/verifying-new-response-dropped';
    $verifyingDeathMarker = $tmp . '/verifying-new-controller-killed';
    $verifyingDropArmed = $tmp . '/verifying-new-response-armed';
    $verifyingControllerPid = $tmp . '/verifying-new-controller.pid';
    $exclusionState = $tmp . '/exclusion-provider.json';
    if (!mkdir($targetTerminals, 0700, true) && !is_dir($targetTerminals)) {
        scoped_host_fail('could not create target terminal archive directory');
    }
    scoped_host_clear($sshLog, $scpLog, $wpLog, $providerLog, $wireLog, $orderLog, $targetReplayLog);

    scoped_host_write($bin . '/ssh', <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
remote="${!#}"
printf '%s\n' "$remote" >> "${DUO3344_SSH_LOG:?}"
if [[ "${DUO3344_FAIL_AUTHORITY_STATUS:-0}" == 1 \
  && "$remote" == *"authority-status"* ]]; then
  printf '%s\n' 'PRIVATE_TARGET_PATH=/srv/secret; provider-token=do-not-publish' >&2
  exit 47
fi
if [[ "${DUO3344_DROP_CLAIM_RESPONSE:-0}" == 1 \
  && ! -e "${DUO3344_DROP_MARKER:?}" \
  && "$remote" == *"rollback-control.php"* \
  && "$remote" == *" 'request' "* \
  && "$remote" == *"--request="* ]]; then
  : > "$DUO3344_DROP_MARKER"
  set +e
  /bin/sh -c "$remote"
  status=$?
  set -e
  [ "$status" -eq 0 ] || exit "$status"
  exit 75
fi
if [[ "${DUO3344_KILL_VERIFYING_NEW_CONTROLLER:-0}" == 1 \
  && ! -e "${DUO3344_VERIFYING_NEW_DEATH_MARKER:?}" \
  && -f "${DUO3344_DROP_VERIFYING_NEW_ARMED:?}" ]]; then
  request_path="$(<"$DUO3344_DROP_VERIFYING_NEW_ARMED")"
  if [[ "$request_path" != "" && "$remote" == *"$request_path"* ]]; then
    set +e
    /bin/sh -c "$remote"
    status=$?
    set -e
    [ "$status" -eq 0 ] || exit "$status"
    : > "$DUO3344_VERIFYING_NEW_DEATH_MARKER"
    rm -f "$DUO3344_DROP_VERIFYING_NEW_ARMED"
    controller_pid="$(<"${DUO3344_VERIFYING_NEW_CONTROLLER_PID_FILE:?}")"
    case "$controller_pid" in
      ''|*[!0-9]*) exit 78 ;;
    esac
    kill -KILL "$controller_pid"
    exit 75
  fi
fi
if [[ "${DUO3344_DROP_VERIFYING_NEW_RESPONSE:-0}" == 1 \
  && ! -e "${DUO3344_DROP_VERIFYING_NEW_MARKER:?}" \
  && -f "${DUO3344_DROP_VERIFYING_NEW_ARMED:?}" ]]; then
  request_path="$(<"$DUO3344_DROP_VERIFYING_NEW_ARMED")"
  if [[ "$request_path" != "" && "$remote" == *"$request_path"* ]]; then
    set +e
    /bin/sh -c "$remote"
    status=$?
    set -e
    [ "$status" -eq 0 ] || exit "$status"
    : > "$DUO3344_DROP_VERIFYING_NEW_MARKER"
    rm -f "$DUO3344_DROP_VERIFYING_NEW_ARMED"
    exit 75
  fi
fi
if [[ "${DUO3344_DROP_COMPLETE_RESPONSE:-0}" == 1 \
  && ! -e "${DUO3344_DROP_COMPLETE_MARKER:?}" \
  && "$remote" == *"promotion-complete-scoped"* ]]; then
  set +e
  /bin/sh -c "$remote"
  status=$?
  set -e
  [ "$status" -eq 0 ] || exit "$status"
  : > "$DUO3344_DROP_COMPLETE_MARKER"
  exit 75
fi
exec /bin/sh -c "$remote"
SH
    , 0700);
    scoped_host_write($bin . '/scp', <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
src="${1:?missing source}"
destination="${2:?missing destination}"
target="${destination#*:}"
printf '%s -> %s\n' "$src" "$target" >> "${DUO3344_SCP_LOG:?}"
cp "$src" "$target"
if [[ "$target" == /tmp/duo-rollback-request-*.json && ( \
    ( "${DUO3344_DROP_VERIFYING_NEW_RESPONSE:-0}" == 1 \
      && ! -e "${DUO3344_DROP_VERIFYING_NEW_MARKER:?}" ) \
    || ( "${DUO3344_KILL_VERIFYING_NEW_CONTROLLER:-0}" == 1 \
      && ! -e "${DUO3344_VERIFYING_NEW_DEATH_MARKER:?}" ) \
  ) ]]; then
  if php -r '
    $request = json_decode((string) file_get_contents($argv[1]), true);
    $event = is_array($request) ? ($request["event"]["payload"] ?? null) : null;
    exit(is_array($event)
      && ($event["state"] ?? null) === "verifying_new"
      && ($event["operation_status"] ?? null) === "state_transition"
      && ($event["operation_id"] ?? null) === "scoped_fresh_verification" ? 0 : 1);
  ' "$src"; then
    printf '%s\n' "$target" > "${DUO3344_DROP_VERIFYING_NEW_ARMED:?}"
  fi
fi
SH
    , 0700);
    scoped_host_write($bin . '/wp', <<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);

$args = $argv;
array_shift($args);
$canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as $key => $child) $value[$key] = $canonicalize($child);
    return $value;
};
$json = static fn(mixed $value): string => json_encode(
    $canonicalize($value),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
);
$log = (string) getenv('DUO3344_WP_LOG');
file_put_contents($log, $json($args) . "\n", FILE_APPEND | LOCK_EX);
$duo = array_search('duo', $args, true);
$sub = $duo === false ? '' : (string) ($args[$duo + 1] ?? '');
$find = static function (string $prefix) use ($args): ?string {
    foreach ($args as $arg) if (str_starts_with($arg, $prefix)) return substr($arg, strlen($prefix));
    return null;
};
$recordWire = static function () use ($find, $sub, $json): void {
    $wire = $find('--scope-request-b64=');
    if (!is_string($wire) || $wire === '') {
        fwrite(STDERR, "missing compact scope request\n");
        exit(91);
    }
    file_put_contents(
        (string) getenv('DUO3344_WIRE_LOG'),
        $json(['sub' => $sub, 'wire' => $wire]) . "\n",
        FILE_APPEND | LOCK_EX
    );
};

if ($sub === 'compile') {
    $out = $find('--out=');
    if (!is_string($out) || $out === '') exit(92);
    if (!is_dir(dirname($out)) && !mkdir(dirname($out), 0700, true) && !is_dir(dirname($out))) exit(93);
    if (!copy((string) getenv('DUO3344_COMPILED_ARTIFACT'), $out)) exit(94);
    echo file_get_contents((string) getenv('DUO3344_COMPILE_SUMMARY'));
    exit(0);
}
if ($sub === 'plan') {
    $recordWire();
    echo file_get_contents((string) getenv('DUO3344_PLAN'));
    exit(0);
}
if ($sub === 'promotion-begin-scoped') {
    $receipt = $find('--scoped-promotion-receipt=');
    $control = (string) getenv('DUO3344_CONTROL');
    $sessionPath = (string) getenv('DUO3344_TARGET_SESSION');
    $replayLog = (string) getenv('DUO3344_TARGET_REPLAY_LOG');
    if (!is_string($receipt) || preg_match('/^[a-f0-9]{64}$/D', $receipt) !== 1
        || $control === '' || $sessionPath === '' || $replayLog === '') {
        fwrite(STDERR, "scoped target begin has malformed authority binding\n");
        exit(98);
    }
    try {
        require_once $control . '/recovery-runtime/rollback-control.php';
        $authority = \Duo\Recovery\RollbackControl::status($control);
    } catch (Throwable $failure) {
        fwrite(STDERR, "scoped target begin could not read authority: " . $failure->getMessage() . "\n");
        exit(98);
    }
    // Mirror the real target gate: a forward-only first seal must be finished
    // by the host before target begin may recreate its receipt-bound session.
    $state = (string) ($authority['state'] ?? '');
    if (!in_array($state, ['promoting', 'committed'], true)) {
        fwrite(STDERR, "scoped target begin requires promoting or committed authority\n");
        exit(98);
    }
    if (is_file($sessionPath)) {
        $session = json_decode((string) file_get_contents($sessionPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($session) || !hash_equals($receipt, (string) ($session['receipt'] ?? ''))) {
            fwrite(STDERR, "scoped target begin found a different target handoff\n");
            exit(98);
        }
    } elseif (file_put_contents($sessionPath, $json(['receipt' => $receipt, 'state' => 'begun']) . "\n", LOCK_EX) === false) {
        fwrite(STDERR, "scoped target begin could not persist target handoff\n");
        exit(98);
    }
    if (file_put_contents($replayLog, "begin:$state:$receipt\n", FILE_APPEND | LOCK_EX) === false) {
        fwrite(STDERR, "scoped target begin could not record authority witness\n");
        exit(99);
    }
    echo "{\"ok\":true}\n";
    exit(0);
}
if ($sub === 'apply') {
    $recordWire();
    $receipt = $find('--scoped-promotion-receipt=');
    $sessionPath = (string) getenv('DUO3344_TARGET_SESSION');
    $terminalRoot = (string) getenv('DUO3344_TARGET_TERMINALS');
    $replayLog = (string) getenv('DUO3344_TARGET_REPLAY_LOG');
    if (!is_string($receipt) || preg_match('/^[a-f0-9]{64}$/D', $receipt) !== 1
        || $terminalRoot === '' || $replayLog === '') {
        fwrite(STDERR, "scoped apply has malformed target terminal binding\n");
        exit(95);
    }
    if (!is_file($sessionPath)) {
        fwrite(STDERR, "scoped apply had no begun target session\n");
        exit(95);
    }
    $session = json_decode((string) file_get_contents($sessionPath), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($session) || !hash_equals($receipt, (string) ($session['receipt'] ?? ''))) {
        fwrite(STDERR, "scoped apply target handoff did not match the signed receipt\n");
        exit(95);
    }
    if ((string) getenv('DUO3344_FAKE_APPLY_FAIL') === '1') {
        fwrite(STDERR, "PRIVATE_TARGET_PATH=/srv/target-secret; provider-token=apply-do-not-publish\n");
        exit(23);
    }
    $archive = $terminalRoot . '/' . $receipt . '.json';
    if (is_file($archive)) {
        $result = file_get_contents($archive);
        if (!is_string($result) || file_put_contents($replayLog, "replay:$receipt\n", FILE_APPEND | LOCK_EX) === false) {
            fwrite(STDERR, "scoped apply could not replay the archived terminal\n");
            exit(95);
        }
        echo $result;
        exit(0);
    }
    $result = file_get_contents((string) getenv('DUO3344_APPLY_RESULT'));
    if (!is_string($result) || file_put_contents($archive, $result, LOCK_EX) === false) {
        fwrite(STDERR, "scoped apply could not archive the terminal receipt\n");
        exit(95);
    }
    echo $result;
    exit(0);
}
if ($sub === 'promotion-abort') {
    echo "{\"ok\":true}\n";
    exit(0);
}
if ($sub === 'promotion-complete-scoped') {
    $receipt = $find('--scoped-promotion-receipt=');
    $sessionPath = (string) getenv('DUO3344_TARGET_SESSION');
    $terminalRoot = (string) getenv('DUO3344_TARGET_TERMINALS');
    if (!is_string($receipt) || preg_match('/^[a-f0-9]{64}$/D', $receipt) !== 1
        || $terminalRoot === '' || !is_file($terminalRoot . '/' . $receipt . '.json')) {
        fwrite(STDERR, "scoped completion had no exact archived target terminal\n");
        exit(96);
    }
    if (!is_file($sessionPath)) {
        fwrite(STDERR, "scoped completion had no target session\n");
        exit(96);
    }
    $session = json_decode((string) file_get_contents($sessionPath), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($session) || !hash_equals($receipt, (string) ($session['receipt'] ?? ''))) {
        fwrite(STDERR, "scoped completion target handoff did not match the signed receipt\n");
        exit(96);
    }
    $control = (string) getenv('DUO3344_CONTROL');
    $providerStatePath = (string) getenv('DUO3344_EXCLUSION_STATE');
    $orderLog = (string) getenv('DUO3344_ORDER_LOG');
    if ($control === '' || $providerStatePath === '' || $orderLog === '') {
        fwrite(STDERR, "scoped completion has no authority-boundary fixture paths\n");
        exit(98);
    }
    try {
        require_once $control . '/recovery-runtime/rollback-control.php';
        $authority = \Duo\Recovery\RollbackControl::status($control);
        $provider = json_decode(
            (string) file_get_contents($providerStatePath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (Throwable $failure) {
        fwrite(STDERR, "could not read scoped completion boundary: " . $failure->getMessage() . "\n");
        exit(98);
    }
    if (($authority['state'] ?? null) !== 'committed'
        || ($authority['terminal'] ?? null) !== true
        || !is_array($provider) || ($provider['state'] ?? null) !== 'held') {
        fwrite(STDERR, "scoped completion requires committed authority with exclusion still held\n");
        exit(98);
    }
    if (file_put_contents($orderLog, "target-complete:committed-held\n", FILE_APPEND | LOCK_EX) === false) {
        fwrite(STDERR, "could not record scoped completion boundary\n");
        exit(99);
    }
    @unlink($sessionPath);
    echo "{\"ok\":true}\n";
    exit(0);
}
fwrite(STDERR, "unexpected fake wp duo subcommand '$sub'\n");
exit(97);
PHP
    , 0700);

    $exclusion = $tmp . '/providers/exclusion.php';
    $checkpoint = $tmp . '/providers/checkpoint.php';
    $adapter = $tmp . '/providers/adapter.php';
    scoped_host_write($exclusion, <<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);

$canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as $key => $child) $value[$key] = $canonicalize($child);
    return $value;
};
$json = static fn(mixed $value): string => json_encode($canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
[$script, $log, $statePath] = $argv + [null, null, null];
$request = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$action = (string) ($request['action'] ?? '');
file_put_contents((string) $log, "exclusion:$action\n", FILE_APPEND | LOCK_EX);
$state = is_file((string) $statePath)
    ? json_decode((string) file_get_contents((string) $statePath), true, 512, JSON_THROW_ON_ERROR)
    : null;
if ($action === 'probe') {
    $token = null;
    $providerState = 'ready';
} elseif ($action === 'acquire') {
    $token = is_array($state) && ($state['state'] ?? null) === 'held'
        ? (string) $state['token']
        : 'fixture-token-' . hash('sha256', $json($request));
    file_put_contents((string) $statePath, $json(['state' => 'held', 'token' => $token]) . "\n", LOCK_EX);
    $providerState = 'held';
} else {
    if (!is_array($state) || ($state['state'] ?? null) !== 'held'
        || !hash_equals((string) $state['token'], (string) ($request['token'] ?? ''))) {
        fwrite(STDERR, "fixture exclusion is not held\n");
        exit(41);
    }
    $token = (string) $state['token'];
    $providerState = $action === 'release' ? 'released' : 'held';
    if ($action === 'release') {
        $orderLog = (string) getenv('DUO3344_ORDER_LOG');
        if ($orderLog === '' || file_put_contents($orderLog, "exclusion:release\n", FILE_APPEND | LOCK_EX) === false) {
            fwrite(STDERR, "fixture exclusion could not record release order\n");
            exit(45);
        }
        file_put_contents((string) $statePath, $json(['state' => 'released', 'token' => $token]) . "\n", LOCK_EX);
    }
}
echo $json([
    'available' => true,
    'disconnect_behavior' => 'remain_excluded',
    'format' => 'duo-exclusion-provider-response/v2',
    'provider_id' => 'fixture-exclusion',
    'provider_version' => '1.0.0',
    'scopes' => [
        'background_jobs' => true,
        'database_writers' => true,
        'filesystem_writers' => true,
        'package_updates' => true,
        'public_traffic' => true,
    ],
    'state' => $providerState,
    'target_id' => (string) ($request['target_id'] ?? ''),
    'token' => $token,
]) . "\n";
PHP
    , 0700);
    scoped_host_write($checkpoint, <<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);

$canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as $key => $child) $value[$key] = $canonicalize($child);
    return $value;
};
$json = static fn(mixed $value): string => json_encode($canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$hash = static fn(string $value): string => hash('sha256', $value);
[$script, $log] = $argv + [null, null];
$request = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$action = (string) ($request['action'] ?? '');
file_put_contents((string) $log, "checkpoint:$action\n", FILE_APPEND | LOCK_EX);
$base = ['format' => 'duo-checkpoint-provider-response/v1', 'provider_id' => 'fixture-checkpoint', 'provider_version' => '1.0.0'];
if ($action === 'probe') {
    echo $json($base + [
        'available' => true,
        'plaintext_durable' => false,
        'state' => 'ready',
        'streaming_authenticated_encryption' => true,
        'temporary_plaintext_cleaned' => true,
    ]) . "\n";
    exit(0);
}
$directory = (string) ($request['artifact_directory'] ?? '');
if ($directory === '' || $directory[0] !== '/') {
    fwrite(STDERR, "fixture checkpoint has no artifact directory\n");
    exit(42);
}
$cipher = $directory . '/checkpoint.enc';
$verifier = $directory . '/prior-verifier-inputs.json';
if ($action === 'prepare') {
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) exit(43);
    $inputs = [
        'canonical_tree_sha256' => $hash('fixture-canonical-tree'),
        'code_revision_sha256' => $hash('fixture-code-revision'),
        'database_schema_sha256' => $hash('fixture-database-schema'),
        'format' => 'duo-prior-verifier-inputs/v1',
        'ledger_session_sha256' => $hash('fixture-ledger-session'),
        'lifecycle_receipts_sha256' => $hash('fixture-lifecycle'),
        'manifest_inputs_sha256' => $hash('fixture-manifests'),
        'map_state_sha256' => $hash('fixture-map'),
        'policy_sha256' => $hash('fixture-policy'),
        'runtime_fingerprints_sha256' => $hash('fixture-runtime'),
        'state_revision_sha256' => $hash('fixture-state-revision'),
    ];
    file_put_contents($cipher, 'fixture-encrypted-checkpoint:' . $hash((string) ($request['receipt_id'] ?? '')), LOCK_EX);
    file_put_contents($verifier, $json($inputs) . "\n", LOCK_EX);
    echo $json($base + [
        'algorithm' => 'fixture-algorithm',
        'available' => true,
        'ciphertext_path' => $cipher,
        'ciphertext_sha256' => hash_file('sha256', $cipher),
        'ciphertext_size' => filesize($cipher),
        'database_identity_sha256' => $hash('fixture-database'),
        'disposable_import_sha256' => $hash('fixture-disposable-import'),
        'disposable_import_verified' => true,
        'export_evidence_sha256' => $hash('fixture-export'),
        'key_id' => (string) ($request['encryption_key_id'] ?? ''),
        'ledger_session_sha256' => $inputs['ledger_session_sha256'],
        'physical_erasure' => 'fixture-erasure',
        'plaintext_durable' => false,
        'prior_verifier_inputs_path' => $verifier,
        'prior_verifier_inputs_sha256' => hash_file('sha256', $verifier),
        'runtime_fingerprints_sha256' => $inputs['runtime_fingerprints_sha256'],
        'state' => 'prepared',
        'streaming_authenticated_encryption' => true,
        'temporary_plaintext_cleaned' => true,
    ]) . "\n";
    exit(0);
}
if ($action === 'restore') {
    echo $json($base + [
        'abort_before' => true,
        'abort_final' => true,
        'abort_final_attempted' => true,
        'action' => 'restore',
        'authority_survived' => true,
        'available' => true,
        'begin_artifact_hash' => (string) ($request['artifact_hash'] ?? ''),
        'begin_owner' => (string) ($request['owner'] ?? ''),
        'database_identity_sha256' => (string) ($request['database_identity_sha256'] ?? ''),
        'import_succeeded' => true,
        'input_sha256' => (string) ($request['input_sha256'] ?? ''),
        'result_sha256' => $hash('fixture-restore:' . (string) ($request['input_sha256'] ?? '')),
        'state' => 'restored',
        'temporary_plaintext_cleaned' => true,
    ]) . "\n";
    exit(0);
}
if ($action === 'verify-prior') {
    $inputs = json_decode((string) file_get_contents($verifier), true, 512, JSON_THROW_ON_ERROR);
    echo $json($base + [
        'action' => 'verify-prior',
        'available' => true,
        'canonical_first_sha256' => $inputs['canonical_tree_sha256'],
        'canonical_second_sha256' => $inputs['canonical_tree_sha256'],
        'code_revision_sha256' => $inputs['code_revision_sha256'],
        'database_identity_sha256' => (string) ($request['database_identity_sha256'] ?? ''),
        'database_schema_sha256' => $inputs['database_schema_sha256'],
        'fresh_processes' => true,
        'input_sha256' => (string) ($request['input_sha256'] ?? ''),
        'ledger_session_sha256' => $inputs['ledger_session_sha256'],
        'lifecycle_receipts_sha256' => $inputs['lifecycle_receipts_sha256'],
        'live_promotion_absent' => true,
        'manifest_inputs_sha256' => $inputs['manifest_inputs_sha256'],
        'map_state_sha256' => $inputs['map_state_sha256'],
        'policy_sha256' => $inputs['policy_sha256'],
        'result_sha256' => $hash('fixture-prior-verify:' . (string) ($request['input_sha256'] ?? '')),
        'runtime_fingerprints_sha256' => $inputs['runtime_fingerprints_sha256'],
        'state' => 'verified',
        'state_revision_sha256' => $inputs['state_revision_sha256'],
        'verifier_inputs_sha256' => hash_file('sha256', $verifier),
    ]) . "\n";
    exit(0);
}
fwrite(STDERR, "unexpected checkpoint action '$action'\n");
exit(44);
PHP
    , 0700);
    scoped_host_write($adapter, <<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);

$canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as $key => $child) $value[$key] = $canonicalize($child);
    return $value;
};
$json = static fn(mixed $value): string => json_encode($canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
[$script, $log] = $argv + [null, null];
$request = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$action = (string) ($request['action'] ?? '');
$adapter = (string) ($request['adapter'] ?? '');
file_put_contents((string) $log, "adapter:$adapter:$action\n", FILE_APPEND | LOCK_EX);
echo $json([
    'adapter' => $adapter,
    'adapter_version' => '1.0.0',
    'available' => true,
    'format' => 'duo-recovery-adapter-response/v1',
    'input_sha256' => $request['input_sha256'] ?? null,
    'loads_site_code' => false,
    'result_sha256' => $action === 'execute' ? hash('sha256', (string) ($request['input_sha256'] ?? '')) : null,
    'status' => $action === 'execute' ? 'completed' : 'ready',
]) . "\n";
PHP
    , 0700);

    $artifactHash = hash('sha256', 'duo3344-scoped-host-artifact');
    $hash = static fn(string $value): string => hash('sha256', $value);
    $contract = [
        'code_diagnostic' => null,
        // DUO-3465: exercise the public SSH promotion/rollback path with a
        // genuine per-option contract, not merely a whole-options carrier
        // whose target plan happens to name an option surface.
        'eligible_surfaces' => ['option:fixture'],
        'exclusions' => [
            'code' => 'excluded from scoped state evidence',
            'lifecycle' => 'excluded from scoped state evidence',
            'mutation_execution' => 'deferred to the target',
            'target_guard_witnesses' => 'excluded from local evidence',
        ],
        'format' => 'duo-scope-contract/v1',
        'live' => [
            'closure' => [],
            'excluded' => [],
            'inbound' => [],
            'roots' => [[
                'entity' => 'options/core#fixture',
                'entity_hash' => $hash('fixture-option-record'),
                'option' => 'fixture',
                'path' => 'options/core.json',
                'provenance' => ['kind' => 'root', 'selector' => 'option:fixture'],
                'source_hash' => $hash('fixture-options-source'),
                'type' => 'option',
            ]],
        ],
        'media' => [],
        'mutation_authority' => false,
        'potential_actions' => [],
        'potential_effects' => [],
        'potential_providers' => [],
        'purpose' => 'read-only scope evidence; never mutation authority',
        'read_only_evidence' => true,
        'resolution' => [
            'live_root_entities' => ['options/core#fixture'],
            'tombstone_uuids' => [],
        ],
        'selectors' => ['option:fixture'],
        'source' => [
            'artifact_hash' => $artifactHash,
            'manifest_hash' => $hash('fixture-manifest'),
            'state_revision_hash' => $hash('fixture-state'),
        ],
        'tombstones' => [],
        'uploads' => [],
    ];
    $contract['scope_hash'] = hash('sha256', Canon::encode($contract));
    $contractPath = $tmp . '/scope-contract.json';
    scoped_host_write($contractPath, Canon::encode($contract) . "\n");
    $badContract = $contract;
    $badContract['scope_hash'] = str_repeat('0', 64);
    $badContractPath = $tmp . '/tampered-scope-contract.json';
    scoped_host_write($badContractPath, Canon::encode($badContract) . "\n");

    $compiledArtifact = $tmp . '/compiled.json';
    scoped_host_write($compiledArtifact, Canon::encode([
        'artifact_hash' => $artifactHash,
        'format' => 'duo-compiled-repository/v1',
        'tree' => [],
    ]) . "\n");
    $compileSummary = $tmp . '/compile-summary.json';
    scoped_host_write($compileSummary, Canon::encode([
        'artifact_hash' => $artifactHash,
        'resolved_adapters' => [],
    ]) . "\n");
    $planPath = $tmp . '/plan.json';
    scoped_host_write($planPath, Canon::encode([
        'artifact_hash' => $artifactHash,
        'code_drift' => [],
        'code_mismatch' => [],
        'format' => 'duo-scoped-plan/v1',
        'incomplete_apply' => [],
        'incomplete_lifecycle' => [],
        'provider_problems' => [],
        'regen_context' => [],
        'regen_pending' => [],
        'resolved_adapters' => [],
        'scope' => [
            'format' => 'duo-scope-contract/v1',
            'scope_hash' => $contract['scope_hash'],
            'source_artifact_hash' => $artifactHash,
        ],
        'selected_actions' => [],
        'selected_surfaces' => ['option:fixture'],
        'target' => [
            'ledger_map_root' => $hash('fixture-ledger-map'),
            'protected_ledger_map_root' => $hash('fixture-protected-ledger-map'),
            'protected_out_of_scope_root' => $hash('fixture-protected-out-of-scope'),
            'selected_before_root' => $hash('fixture-selected-before'),
            'selected_ledger_map_root' => $hash('fixture-selected-ledger-map'),
            'target_observation_hash' => $hash('fixture-target-observation'),
        ],
    ]) . "\n");
    $terminal = [
        'authority_hash' => $hash('fixture-authority'),
        'convergence_hash' => $hash('fixture-convergence'),
        'intents_hash' => $hash('fixture-intents'),
        'lease_hash' => $hash('fixture-lease'),
        'phase' => 'complete',
        'protected_ledger_map_hash' => $hash('fixture-protected-ledger'),
        'receipts_hash' => $hash('fixture-receipts'),
        'selected_ledger_map_hash' => $hash('fixture-selected-ledger'),
        'session_id' => 'ps-fixture-scoped-session',
    ];
    $terminal['terminal_hash'] = hash('sha256', Canon::encode($terminal));
    $applyResult = $tmp . '/apply-result.json';
    scoped_host_write($applyResult, Canon::encode([
        'format' => 'duo-scoped-apply-result/v1',
        'scoped_receipt' => $terminal,
    ]) . "\n");

    $control = $target . '/.duo/control';
    $keyId = 'fixture-scoped-key';
    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $public = sodium_crypto_sign_publickey($keypair);
    RollbackControl::initialize($control);
    RollbackControl::installPublicKey($control, $keyId, base64_encode($public));
    $runtime = $control . '/recovery-runtime';
    if (!mkdir($runtime, 0700, true) && !is_dir($runtime)) {
        scoped_host_fail('could not create isolated rollback runtime');
    }
    foreach (glob($root . '/recovery/*.php') ?: [] as $source) {
        if (!copy($source, $runtime . '/' . basename($source))) {
            scoped_host_fail('could not copy rollback runtime ' . basename($source));
        }
    }
    $signingKey = $tmp . '/controller-signing.key';
    scoped_host_write($signingKey, base64_encode($secret) . "\n", 0600);

    $adapters = [];
    foreach (['code_restore', 'database_restore', 'prior_verify', 'storage_restore'] as $name) {
        $adapters[$name] = [PHP_BINARY, $adapter, $providerLog];
    }
    $runtimeConfig = [
        'adapters' => $adapters,
        'checkpoint_provider' => [PHP_BINARY, $checkpoint, $providerLog],
        'exclusion_provider' => [PHP_BINARY, $exclusion, $providerLog, $exclusionState],
        'format' => 'duo-recovery-config/v1',
        'timeout_seconds' => 5,
    ];
    $runtimeConfigPath = $tmp . '/recovery-config.json';
    scoped_host_write($runtimeConfigPath, RollbackControl::canonical($runtimeConfig) . "\n");
    RecoveryExecutor::configureFromFile($control, $runtimeConfigPath);

    $driverRecovery = $runtimeConfig;
    unset($driverRecovery['format']);
    $envs = $tmp . '/envs.json';
    scoped_host_write($envs, json_encode(['envs' => ['scoped' => [
        'host' => 'fixture-host',
        'repo_path' => $target,
        'rollback_key_id' => $keyId,
        'rollback_recovery' => $driverRecovery,
        'rollback_signing_key' => $signingKey,
        'transport' => 'ssh',
        'verified_rollback' => [
            'claim_ttl_seconds' => 60,
            'encryption_key_id' => 'fixture-kms',
            'retention_seconds' => 120,
        ],
        'wp_path' => $wordpress,
    ]]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");

    putenv('PATH=' . $bin . ':' . $oldPath);
    foreach ([
        'DUO3344_APPLY_RESULT' => $applyResult,
        'DUO3344_COMPILED_ARTIFACT' => $compiledArtifact,
        'DUO3344_COMPILE_SUMMARY' => $compileSummary,
        'DUO3344_CONTROL' => $control,
        'DUO3344_DROP_COMPLETE_MARKER' => $completeDropMarker,
        'DUO3344_DROP_MARKER' => $dropMarker,
        'DUO3344_DROP_VERIFYING_NEW_ARMED' => $verifyingDropArmed,
        'DUO3344_DROP_VERIFYING_NEW_MARKER' => $verifyingDropMarker,
        'DUO3344_KILL_VERIFYING_NEW_CONTROLLER' => '0',
        'DUO3344_VERIFYING_NEW_CONTROLLER_PID_FILE' => $verifyingControllerPid,
        'DUO3344_VERIFYING_NEW_DEATH_MARKER' => $verifyingDeathMarker,
        'DUO3344_EXCLUSION_STATE' => $exclusionState,
        'DUO3344_ORDER_LOG' => $orderLog,
        'DUO3344_PLAN' => $planPath,
        'DUO3344_SCP_LOG' => $scpLog,
        'DUO3344_SSH_LOG' => $sshLog,
        'DUO3344_TARGET_REPLAY_LOG' => $targetReplayLog,
        'DUO3344_TARGET_SESSION' => $targetSession,
        'DUO3344_TARGET_TERMINALS' => $targetTerminals,
        'DUO3344_WIRE_LOG' => $wireLog,
        'DUO3344_WP_LOG' => $wpLog,
    ] as $name => $value) {
        putenv($name . '=' . $value);
    }

    $noTargetCases = [
        'raw orchestrator wire' => ['--scope-contract=' . $contractPath, '--scope-request-b64=ZXZpbA==', '--format=json'],
        'duplicate local contract' => ['--scope-contract=' . $contractPath, '--scope-contract=' . $contractPath, '--format=json'],
        'malformed split contract flag' => ['--scope-contract', '--format=json'],
        'unsupported scoped force flag' => ['--scope-contract=' . $contractPath, '--force-theirs', '--format=json'],
        'unsupported secret-bearing argument' => [
            '--scope-contract=' . $contractPath,
            '--unexpected=sk_live_DO_NOT_ECHO',
            '--format=json',
        ],
        'tampered local contract' => ['--scope-contract=' . $badContractPath, '--format=json'],
    ];
    foreach ($noTargetCases as $label => $args) {
        scoped_host_clear($sshLog, $scpLog, $wpLog, $providerLog, $wireLog, $orderLog);
        $refused = scoped_host_promote($root, $envs, $contractPath, $args);
        $noContact = scoped_host_lines($sshLog) === []
            && scoped_host_lines($scpLog) === []
            && scoped_host_lines($wpLog) === []
            && scoped_host_lines($providerLog) === [];
        $envelope = scoped_host_json($refused['stdout'], "$label refusal");
        scoped_host_ok(
            $refused['exit'] !== 0
                && ($envelope['format'] ?? null) === 'duo-command-refusal/v1'
                && !str_contains($refused['stdout'] . $refused['stderr'], 'sk_live_DO_NOT_ECHO')
                && $noContact,
            "$label is rejected before SSH, SCP, WP, or recovery-provider contact"
        );
    }

    scoped_host_clear($sshLog, $scpLog, $wpLog, $providerLog, $wireLog, $orderLog);
    $sensitiveStatus = scoped_host_promote($root, $envs, $contractPath, [], [
        'DUO3344_FAIL_AUTHORITY_STATUS' => '1',
    ]);
    $sensitiveRefusal = scoped_host_json($sensitiveStatus['stdout'], 'sensitive authority preflight refusal');
    $sensitivePublicBytes = $sensitiveStatus['stdout'] . $sensitiveStatus['stderr'];
    $sensitiveReachedAuthorityStatus = false;
    foreach (scoped_host_lines($sshLog) as $command) {
        if (str_contains($command, 'authority-status')) {
            $sensitiveReachedAuthorityStatus = true;
            break;
        }
    }
    scoped_host_ok(
        $sensitiveStatus['exit'] !== 0
            && ($sensitiveRefusal['reason_code'] ?? null) === 'scoped_promotion_preflight_failed'
            && $sensitiveReachedAuthorityStatus,
        'scoped authority preflight failure reaches the target then returns its public refusal'
    );
    scoped_host_ok(
        !str_contains($sensitivePublicBytes, 'PRIVATE_TARGET_PATH')
            && !str_contains($sensitivePublicBytes, 'provider-token')
            && !str_contains($sensitivePublicBytes, '/srv/secret'),
        'public JSON redacts target/provider stderr from scoped authority preflight failures'
    );

    scoped_host_clear($sshLog, $scpLog, $wpLog, $providerLog, $wireLog, $orderLog);
    $lost = scoped_host_promote($root, $envs, $contractPath, [], [
        'DUO3344_DROP_CLAIM_RESPONSE' => '1',
        'DUO3344_FAKE_APPLY_FAIL' => null,
    ]);
    $lostStatus = RollbackControl::status($control);
    $lostProvider = scoped_host_lines($providerLog);
    scoped_host_ok(
        $lost['exit'] !== 0 && is_file($dropMarker)
            && ($lostStatus['state'] ?? null) === 'prepared'
            && empty($lostStatus['terminal']),
        'lost claim response leaves the real signed scoped generation prepared and excluded for exact retry'
    );
    scoped_host_ok(
        count(array_filter($lostProvider, static fn(string $line): bool => $line === 'checkpoint:prepare')) === 1
            && !in_array('checkpoint:restore', $lostProvider, true)
            && !in_array('checkpoint:verify-prior', $lostProvider, true),
        'claim response loss does not fabricate a rollback before the host has observed a claim result'
    );
    $lostReceipt = (string) ($lostStatus['receipt_id'] ?? '');

    scoped_host_clear($sshLog, $scpLog, $wpLog, $providerLog, $wireLog, $orderLog);
    $resumed = scoped_host_promote($root, $envs, $contractPath);
    if ($resumed['exit'] !== 0) {
        scoped_host_fail(
            'resumed public scoped promotion failed (' . $resumed['exit'] . "):\n"
            . $resumed['stderr'] . $resumed['stdout']
        );
    }
    $result = scoped_host_json($resumed['stdout'], 'resumed scoped promotion result');
    $committed = RollbackControl::status($control);
    $requests = scoped_host_wp_requests($wpLog);
    $subcommands = scoped_host_wp_subcommands($requests);
    $compact = scoped_host_compact_requests($requests);
    $successProvider = scoped_host_lines($providerLog);
    $successOrder = scoped_host_lines($orderLog);
    $expectedSuccessEvents = [
        'prepared/state_transition/promotion-claim',
        'promoting/state_transition/scoped_promotion_start',
        'promoting/prepared/scoped_apply',
        'promoting/completed/scoped_apply',
        'verifying_new/state_transition/scoped_fresh_verification',
        'committed/state_transition/scoped_committed_verified',
    ];
    scoped_host_ok(
        $resumed['exit'] === 0
            && ($result['format'] ?? null) === 'duo-scoped-promotion-result/v1'
            && ($committed['state'] ?? null) === 'committed'
            && !empty($committed['terminal'])
            && ($committed['generation'] ?? null) === 1
            && ($committed['allow_deletes'] ?? null) === true
            && ($committed['receipt_id'] ?? null) === $lostReceipt,
        'public retry resumes the exact response-lost generation and commits it once'
    );
    scoped_host_ok(
        $subcommands === [
            'compile', 'plan', 'promotion-begin-scoped', 'apply',
            'promotion-abort', 'promotion-complete-scoped',
        ] && $successOrder === [
            'target-complete:committed-held',
            'exclusion:release',
        ],
        'public host path orders compile -> plan -> begin -> apply -> record -> signed commit -> target complete -> exclusion release'
    );
    scoped_host_ok(
        scoped_host_event_steps($control, $lostReceipt) === $expectedSuccessEvents,
        'signed authority chain records claim, scoped apply evidence, completion, and commit in order'
    );
    scoped_host_ok(
        count($compact) === 2
            && array_reduce($compact, static fn(bool $ok, array $entry): bool => $ok
                && ($entry['wire']['format'] ?? null) === 'duo-scope-request/v1'
                && ($entry['wire']['scope_hash'] ?? null) === $contract['scope_hash']
                && ($entry['wire']['selectors'] ?? null) === $contract['selectors'], true),
        'strict local contract becomes only the compact selectors-plus-hash request at plan and apply'
    );
    $successApply = array_values(array_filter(
        $requests,
        static function (array $args): bool {
            $duo = array_search('duo', $args, true);
            return $duo !== false && ($args[$duo + 1] ?? null) === 'apply';
        }
    ));
    scoped_host_ok(
        count($successApply) === 1 && in_array('--with-deletes', $successApply[0], true),
        'public --with-deletes is immutable signed receipt authority and reaches only its exact target apply'
    );
    scoped_host_ok(
        !is_file($targetSession)
            && ($result['rollback']['automatic_window_closed'] ?? null) === true
            && ($result['rollback']['later_rollback_supported'] ?? null) === false
            && !in_array('checkpoint:restore', $successProvider, true)
            && !in_array('checkpoint:verify-prior', $successProvider, true),
        'committed scoped promotion retires its target handoff and exposes no later automatic rollback'
    );
    scoped_host_ok(
        array_filter($successProvider, static fn(string $line): bool => str_starts_with($line, 'adapter:')) === []
            && !array_filter($subcommands, static fn(string $sub): bool => in_array(
                $sub,
                ['promotion-begin', 'code-stage', 'code-finalize', 'deploy'],
                true
            )),
        'scoped success never invokes ordinary lease/code/lifecycle adapters or full-release provider paths'
    );

    // The target may complete successfully while its SSH response is lost.
    // The signed commit must remain terminal with the exclusion held, so an
    // exact retry can replay the target handoff rather than authoring another
    // controller generation or guessing a rollback.
    scoped_host_clear($sshLog, $scpLog, $wpLog, $providerLog, $wireLog, $orderLog);
    $lostComplete = scoped_host_promote($root, $envs, $contractPath, [], [
        'DUO3344_DROP_COMPLETE_RESPONSE' => '1',
        'DUO3344_FAKE_APPLY_FAIL' => null,
    ]);
    $lostCompleteStatus = RollbackControl::status($control);
    $lostCompleteProvider = scoped_host_lines($providerLog);
    $lostCompleteOrder = scoped_host_lines($orderLog);
    $lostCompleteReceipt = (string) ($lostCompleteStatus['receipt_id'] ?? '');
    $lostCompleteExclusion = scoped_host_json(
        (string) file_get_contents($exclusionState),
        'lost target-complete exclusion state'
    );
    scoped_host_ok(
        $lostComplete['exit'] !== 0
            && is_file($completeDropMarker)
            && ($lostCompleteStatus['state'] ?? null) === 'committed'
            && ($lostCompleteStatus['terminal'] ?? null) === true
            && ($lostCompleteStatus['generation'] ?? null) === 2
            && ($lostCompleteExclusion['state'] ?? null) === 'held'
            && !is_file($targetSession)
            && $lostCompleteOrder === ['target-complete:committed-held']
            && !in_array('exclusion:release', $lostCompleteProvider, true)
            && scoped_host_event_steps($control, $lostCompleteReceipt) === $expectedSuccessEvents,
        'lost target-complete response leaves the same signed commit terminal with exclusion held and no rollback'
    );

    scoped_host_clear($sshLog, $scpLog, $wpLog, $providerLog, $wireLog, $orderLog);
    $replayed = scoped_host_promote($root, $envs, $contractPath);
    if ($replayed['exit'] !== 0) {
        scoped_host_fail(
            'retry after lost target completion failed (' . $replayed['exit'] . "):\n"
            . $replayed['stderr'] . $replayed['stdout']
        );
    }
    $replayedResult = scoped_host_json($replayed['stdout'], 'replayed scoped promotion result');
    $replayedStatus = RollbackControl::status($control);
    $replayedProvider = scoped_host_lines($providerLog);
    $replayedSubcommands = scoped_host_wp_subcommands(scoped_host_wp_requests($wpLog));
    $replayedOrder = scoped_host_lines($orderLog);
    scoped_host_ok(
        ($replayedResult['format'] ?? null) === 'duo-scoped-promotion-result/v1'
            && ($replayedStatus['state'] ?? null) === 'committed'
            && ($replayedStatus['terminal'] ?? null) === true
            && ($replayedStatus['generation'] ?? null) === 2
            && ($replayedStatus['receipt_id'] ?? null) === $lostCompleteReceipt
            && scoped_host_event_steps($control, $lostCompleteReceipt) === $expectedSuccessEvents
            && !in_array('checkpoint:prepare', $replayedProvider, true)
            && !in_array('exclusion:acquire', $replayedProvider, true)
            && $replayedSubcommands === [
                'compile', 'plan', 'promotion-begin-scoped', 'apply',
                'promotion-abort', 'promotion-complete-scoped',
            ]
            && $replayedOrder === [
                'target-complete:committed-held',
                'exclusion:release',
            ]
            && !is_file($targetSession),
        'retry after lost target completion replays Apply/complete on one signed generation, then releases exclusion'
    );

    // The first seal transition itself can publish before the controller gets
    // its response.  That leaves a durable verifying_new generation after a
    // completed target Apply and target-terminal archive; the catch must not
    // turn this successful forward boundary into a checkpoint rollback.
    scoped_host_clear($sshLog, $scpLog, $wpLog, $providerLog, $wireLog, $orderLog, $targetReplayLog);
    $lostVerifying = scoped_host_promote($root, $envs, $contractPath, [], [
        'DUO3344_DROP_VERIFYING_NEW_RESPONSE' => '1',
        'DUO3344_FAKE_APPLY_FAIL' => null,
    ]);
    $lostVerifyingStatus = RollbackControl::status($control);
    $lostVerifyingReceipt = (string) ($lostVerifyingStatus['receipt_id'] ?? '');
    $lostVerifyingTargetReceipt = (string) ($lostVerifyingStatus['receipt_payload_sha256'] ?? '');
    $lostVerifyingProvider = scoped_host_lines($providerLog);
    $lostVerifyingSubcommands = scoped_host_wp_subcommands(scoped_host_wp_requests($wpLog));
    $lostVerifyingReplay = scoped_host_lines($targetReplayLog);
    $lostVerifyingRefusal = scoped_host_json($lostVerifying['stdout'], 'fresh-verification response-loss refusal');
    scoped_host_ok(
        $lostVerifying['exit'] !== 0
            && is_file($verifyingDropMarker)
            && ($lostVerifyingRefusal['reason_code'] ?? null) === 'scoped_promotion_completion_pending'
            && ($lostVerifyingStatus['state'] ?? null) === 'verifying_new'
            && empty($lostVerifyingStatus['terminal'])
            && ($lostVerifyingStatus['generation'] ?? null) === 3
            && is_file($targetSession)
            && is_file($targetTerminals . '/' . $lostVerifyingTargetReceipt . '.json')
            && scoped_host_event_steps($control, $lostVerifyingReceipt) === array_slice($expectedSuccessEvents, 0, 5),
        'lost first seal response leaves the exact completed scoped generation verifying_new with its target handoff held'
    );
    scoped_host_ok(
        // Apply releases its target lease before the first seal; the generic
        // catch then repeats that idempotent target-only cleanup after the
        // response loss. Neither command is checkpoint rollback authority.
        $lostVerifyingSubcommands === [
            'compile', 'plan', 'promotion-begin-scoped', 'apply',
            'promotion-abort', 'promotion-abort',
        ]
            && $lostVerifyingReplay === ['begin:promoting:' . $lostVerifyingTargetReceipt]
            && count(array_filter($lostVerifyingProvider, static fn(string $line): bool => $line === 'checkpoint:prepare')) === 1
            && !in_array('checkpoint:restore', $lostVerifyingProvider, true)
            && !in_array('checkpoint:verify-prior', $lostVerifyingProvider, true)
            && !in_array('exclusion:release', $lostVerifyingProvider, true),
        'fresh-verification response-loss catch performs no restore, prior verification, rollback, completion, or exclusion release'
    );

    scoped_host_clear($sshLog, $scpLog, $wpLog, $providerLog, $wireLog, $orderLog, $targetReplayLog);
    $resumedVerifying = scoped_host_promote($root, $envs, $contractPath);
    if ($resumedVerifying['exit'] !== 0) {
        scoped_host_fail(
            'retry after fresh-verification response loss failed (' . $resumedVerifying['exit'] . "):\n"
            . $resumedVerifying['stderr'] . $resumedVerifying['stdout']
        );
    }
    $resumedVerifyingResult = scoped_host_json($resumedVerifying['stdout'], 'fresh-verification replay result');
    $resumedVerifyingStatus = RollbackControl::status($control);
    $resumedVerifyingProvider = scoped_host_lines($providerLog);
    $resumedVerifyingSubcommands = scoped_host_wp_subcommands(scoped_host_wp_requests($wpLog));
    $resumedVerifyingReplay = scoped_host_lines($targetReplayLog);
    $resumedVerifyingOrder = scoped_host_lines($orderLog);
    scoped_host_ok(
        ($resumedVerifyingResult['format'] ?? null) === 'duo-scoped-promotion-result/v1'
            && ($resumedVerifyingStatus['state'] ?? null) === 'committed'
            && ($resumedVerifyingStatus['terminal'] ?? null) === true
            && ($resumedVerifyingStatus['generation'] ?? null) === 3
            && ($resumedVerifyingStatus['receipt_id'] ?? null) === $lostVerifyingReceipt
            && scoped_host_event_steps($control, $lostVerifyingReceipt) === $expectedSuccessEvents
            && !is_file($targetSession),
        'fresh-verification retry seals only the existing generation and commits it without a new checkpoint'
    );
    scoped_host_ok(
        !in_array('checkpoint:prepare', $resumedVerifyingProvider, true)
            && !in_array('exclusion:acquire', $resumedVerifyingProvider, true)
            && !in_array('checkpoint:restore', $resumedVerifyingProvider, true)
            && !in_array('checkpoint:verify-prior', $resumedVerifyingProvider, true)
            && $resumedVerifyingSubcommands === [
                'compile', 'plan', 'promotion-begin-scoped', 'apply',
                'promotion-abort', 'promotion-complete-scoped',
            ]
            && $resumedVerifyingReplay === [
                'begin:committed:' . $lostVerifyingTargetReceipt,
                'replay:' . $lostVerifyingTargetReceipt,
            ]
            && $resumedVerifyingOrder === [
                'target-complete:committed-held',
                'exclusion:release',
            ],
        'fresh-verification retry seals before target begin, replays the archived terminal, then completes and releases once'
    );

    // A real controller death at exactly the same boundary cannot execute the
    // catch at all. The next controller must still recognize the durable
    // verifying_new generation, complete its second seal before target begin,
    // and replay the archived terminal rather than minting another receipt.
    scoped_host_clear($sshLog, $scpLog, $wpLog, $providerLog, $wireLog, $orderLog, $targetReplayLog);
    scoped_host_remove($verifyingControllerPid);
    $killedVerifying = scoped_host_promote($root, $envs, $contractPath, [], [
        'DUO3344_DROP_VERIFYING_NEW_RESPONSE' => null,
        'DUO3344_FAKE_APPLY_FAIL' => null,
        'DUO3344_KILL_VERIFYING_NEW_CONTROLLER' => '1',
    ]);
    $killedVerifyingStatus = RollbackControl::status($control);
    $killedVerifyingReceipt = (string) ($killedVerifyingStatus['receipt_id'] ?? '');
    $killedVerifyingTargetReceipt = (string) ($killedVerifyingStatus['receipt_payload_sha256'] ?? '');
    $killedVerifyingProvider = scoped_host_lines($providerLog);
    $killedVerifyingSubcommands = scoped_host_wp_subcommands(scoped_host_wp_requests($wpLog));
    $killedVerifyingReplay = scoped_host_lines($targetReplayLog);
    scoped_host_ok(
        $killedVerifying['exit'] !== 0
            && trim($killedVerifying['stdout']) === ''
            && trim($killedVerifying['stderr']) === ''
            && is_file($verifyingDeathMarker)
            && ($killedVerifyingStatus['state'] ?? null) === 'verifying_new'
            && empty($killedVerifyingStatus['terminal'])
            && ($killedVerifyingStatus['generation'] ?? null) === 4
            && is_file($targetSession)
            && is_file($targetTerminals . '/' . $killedVerifyingTargetReceipt . '.json')
            && scoped_host_event_steps($control, $killedVerifyingReceipt) === array_slice($expectedSuccessEvents, 0, 5),
        'controller death after durable first seal leaves the exact verifying_new generation and target handoff held'
    );
    scoped_host_ok(
        $killedVerifyingSubcommands === [
            'compile', 'plan', 'promotion-begin-scoped', 'apply', 'promotion-abort',
        ]
            && $killedVerifyingReplay === ['begin:promoting:' . $killedVerifyingTargetReceipt]
            && count(array_filter($killedVerifyingProvider, static fn(string $line): bool => $line === 'checkpoint:prepare')) === 1
            && !in_array('checkpoint:restore', $killedVerifyingProvider, true)
            && !in_array('checkpoint:verify-prior', $killedVerifyingProvider, true)
            && !in_array('exclusion:release', $killedVerifyingProvider, true),
        'controller death performs no rollback catch, restore, prior verification, completion, or exclusion release'
    );

    scoped_host_clear($sshLog, $scpLog, $wpLog, $providerLog, $wireLog, $orderLog, $targetReplayLog);
    $resumedAfterDeath = scoped_host_promote($root, $envs, $contractPath);
    if ($resumedAfterDeath['exit'] !== 0) {
        scoped_host_fail(
            'retry after controller death at fresh verification failed (' . $resumedAfterDeath['exit'] . "):\n"
            . $resumedAfterDeath['stderr'] . $resumedAfterDeath['stdout']
        );
    }
    $resumedAfterDeathResult = scoped_host_json($resumedAfterDeath['stdout'], 'controller-death replay result');
    $resumedAfterDeathStatus = RollbackControl::status($control);
    $resumedAfterDeathProvider = scoped_host_lines($providerLog);
    $resumedAfterDeathSubcommands = scoped_host_wp_subcommands(scoped_host_wp_requests($wpLog));
    $resumedAfterDeathReplay = scoped_host_lines($targetReplayLog);
    $resumedAfterDeathOrder = scoped_host_lines($orderLog);
    scoped_host_ok(
        ($resumedAfterDeathResult['format'] ?? null) === 'duo-scoped-promotion-result/v1'
            && ($resumedAfterDeathStatus['state'] ?? null) === 'committed'
            && ($resumedAfterDeathStatus['terminal'] ?? null) === true
            && ($resumedAfterDeathStatus['generation'] ?? null) === 4
            && ($resumedAfterDeathStatus['receipt_id'] ?? null) === $killedVerifyingReceipt
            && scoped_host_event_steps($control, $killedVerifyingReceipt) === $expectedSuccessEvents
            && !is_file($targetSession),
        'controller-death retry seals and commits the existing generation without a new checkpoint'
    );
    scoped_host_ok(
        !in_array('checkpoint:prepare', $resumedAfterDeathProvider, true)
            && !in_array('exclusion:acquire', $resumedAfterDeathProvider, true)
            && !in_array('checkpoint:restore', $resumedAfterDeathProvider, true)
            && !in_array('checkpoint:verify-prior', $resumedAfterDeathProvider, true)
            && $resumedAfterDeathSubcommands === [
                'compile', 'plan', 'promotion-begin-scoped', 'apply',
                'promotion-abort', 'promotion-complete-scoped',
            ]
            && $resumedAfterDeathReplay === [
                'begin:committed:' . $killedVerifyingTargetReceipt,
                'replay:' . $killedVerifyingTargetReceipt,
            ]
            && $resumedAfterDeathOrder === [
                'target-complete:committed-held',
                'exclusion:release',
            ],
        'controller-death retry seals before target begin, replays the archived terminal, then completes and releases once'
    );

    scoped_host_clear($sshLog, $scpLog, $wpLog, $providerLog, $wireLog, $orderLog);
    $failed = scoped_host_promote($root, $envs, $contractPath, [], ['DUO3344_FAKE_APPLY_FAIL' => '1']);
    $rolledBack = RollbackControl::status($control);
    $failureProvider = scoped_host_lines($providerLog);
    $failureRequests = scoped_host_wp_requests($wpLog);
    $failureSubcommands = scoped_host_wp_subcommands($failureRequests);
    $failedReceipt = (string) ($rolledBack['receipt_id'] ?? '');
    $failedRefusal = scoped_host_json($failed['stdout'], 'pre-fresh-verification rollback refusal');
    $failedPublicBytes = $failed['stdout'] . $failed['stderr'];
    scoped_host_ok(
        $failed['exit'] !== 0
            && ($rolledBack['state'] ?? null) === 'rolled_back'
            && !empty($rolledBack['terminal'])
            && ($rolledBack['generation'] ?? null) === 5
            && ($failedRefusal['reason_code'] ?? null) === 'scoped_promotion_rolled_back'
            && $failed['stderr'] === ''
            && !str_contains($failedPublicBytes, 'PRIVATE_TARGET_PATH')
            && !str_contains($failedPublicBytes, 'provider-token')
            && !str_contains($failedPublicBytes, '/srv/target-secret'),
        'pre-fresh-verification apply failure restores/verifies the checkpoint and emits one redacted public JSON refusal'
    );
    scoped_host_ok(
        $failureSubcommands === ['compile', 'plan', 'promotion-begin-scoped', 'apply', 'promotion-abort'],
        'pre-fresh-verification failure stops before the durable seal and compensates only the exact lease'
    );
    scoped_host_ok(
        in_array('checkpoint:prepare', $failureProvider, true)
            && in_array('checkpoint:restore', $failureProvider, true)
            && in_array('checkpoint:verify-prior', $failureProvider, true)
            && array_filter($failureProvider, static fn(string $line): bool => str_starts_with($line, 'adapter:')) === [],
        'pre-fresh-verification rollback uses only the checkpoint restore/prior-verifier profile, never ordinary recovery adapters'
    );
    scoped_host_ok(
        scoped_host_event_steps($control, $failedReceipt) === [
            'prepared/state_transition/promotion-claim',
            'promoting/state_transition/scoped_promotion_start',
            'rollback_pending/state_transition/scoped_promotion_failed',
            'rolling_back/state_transition/scoped_rollback_start',
            'rolling_back/prepared/database_restore',
            'rolling_back/completed/database_restore',
            'verifying_prior/state_transition/scoped_verifying_prior',
            'verifying_prior/prepared/prior_verify',
            'verifying_prior/completed/prior_verify',
            'rolled_back/state_transition/scoped_rolled_back_verified',
        ],
        'pre-fresh-verification failure appends the complete signed rollback and prior-world verification chain'
    );

    echo "PASS: DUO-3344 public SSH scoped-promotion host regression\n";
} finally {
    putenv('PATH=' . $oldPath);
    foreach ([
        'DUO3344_APPLY_RESULT', 'DUO3344_COMPILED_ARTIFACT', 'DUO3344_COMPILE_SUMMARY',
        'DUO3344_CONTROL', 'DUO3344_DROP_COMPLETE_MARKER', 'DUO3344_DROP_MARKER',
        'DUO3344_DROP_VERIFYING_NEW_ARMED', 'DUO3344_DROP_VERIFYING_NEW_MARKER',
        'DUO3344_DROP_VERIFYING_NEW_RESPONSE', 'DUO3344_KILL_VERIFYING_NEW_CONTROLLER',
        'DUO3344_VERIFYING_NEW_CONTROLLER_PID_FILE', 'DUO3344_VERIFYING_NEW_DEATH_MARKER',
        'DUO3344_EXCLUSION_STATE', 'DUO3344_FAIL_AUTHORITY_STATUS',
        'DUO3344_ORDER_LOG', 'DUO3344_PLAN', 'DUO3344_SCP_LOG', 'DUO3344_SSH_LOG',
        'DUO3344_TARGET_REPLAY_LOG', 'DUO3344_TARGET_SESSION', 'DUO3344_TARGET_TERMINALS',
        'DUO3344_WIRE_LOG', 'DUO3344_WP_LOG',
    ] as $name) {
        putenv($name);
    }
    if ($secret !== '') {
        sodium_memzero($secret);
    }
    scoped_host_remove($tmp);
}

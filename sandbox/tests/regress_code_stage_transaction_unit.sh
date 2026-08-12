#!/usr/bin/env bash
# DUO-3350 CodeStageTransaction seam: failure injection for stage's
# materialized-payload -> staged-ledger handoff.
# Every marker statement and COMMIT must roll back all five temporary receipt
# keys. A child PHP process retries from the serialized Ledger snapshot left by
# rollback, proving a fresh wp-cli process is not permanently stranded.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SCRIPT="$(cd "$(dirname "$0")" && pwd)/$(basename "$0")"
MODE="${DUO_STAGE_TRANSACTION_MODE:-matrix}"

DUO_ROOT="$ROOT" DUO_STAGE_TRANSACTION_MODE="$MODE" DUO_STAGE_TRANSACTION_SCRIPT="$SCRIPT" php -d display_errors=1 <<'PHP'
<?php
namespace Duo;

$root = getenv('DUO_ROOT');
$mode = (string) (getenv('DUO_STAGE_TRANSACTION_MODE') ?: 'matrix');
$state = null;
if ($mode === 'retry') {
    $statePath = (string) getenv('DUO_STAGE_TRANSACTION_STATE');
    $raw = $statePath === '' ? false : @file_get_contents($statePath);
    if ($raw === false) {
        throw new \RuntimeException('FAIL: fresh stage retry did not receive its durable Ledger snapshot');
    }
    try {
        $state = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        throw new \RuntimeException('FAIL: fresh stage retry snapshot is not JSON', 0, $e);
    }
    if (!is_array($state)
        || !is_string($state['repo'] ?? null)
        || !is_string($state['target'] ?? null)
        || !is_string($state['artifact'] ?? null)
        || !is_array($state['ledger'] ?? null)) {
        throw new \RuntimeException('FAIL: fresh stage retry snapshot has an invalid shape');
    }
    $repo = $state['repo'];
    $target = $state['target'];
} elseif ($mode === 'matrix') {
    $target = sys_get_temp_dir() . '/duo-code-stage-transaction-target-' . bin2hex(random_bytes(6));
    $repo = sys_get_temp_dir() . '/duo-code-stage-transaction-repo-' . bin2hex(random_bytes(6));
} else {
    throw new \RuntimeException("FAIL: unknown stage transaction test mode '$mode'");
}
define('WP_CONTENT_DIR', $target);
define('WP_PLUGIN_DIR', $target . '/plugins');
define('WPMU_PLUGIN_DIR', $target . '/mu-plugins');

final class CompiledRepository {
    public function __construct(private array $descriptor, private string $artifact) {}
    public function code_descriptor(): ?array { return $this->descriptor; }
    public function artifact_hash(): string { return $this->artifact; }
}
final class CodeStateContract {
    public static function validate(CompiledRepository $compiled, array $descriptor): void {}
}
final class Ledger {
    /** @var array<string,string> */
    public static array $rows = [];
    public static int $mutation = 0;
    public static ?int $failAt = null;
    public static function ensure(): void {}
    public static function kv_get(string $key): ?string { return self::$rows[$key] ?? null; }
    public static function kv_set(string $key, string $value): void {
        self::$rows[$key] = $value;
        self::$mutation++;
        if (self::$failAt === self::$mutation) {
            throw new \RuntimeException('injected stage marker statement failure');
        }
    }
}
final class Db {
    /** @var ?array<string,string> */
    public static ?array $snapshot = null;
    public static int $starts = 0;
    public static int $commits = 0;
    public static int $rollbacks = 0;
    public static bool $failCommit = false;
    public static function start(string $context): void {
        self::$starts++;
        self::$snapshot = Ledger::$rows;
    }
    public static function commit(string $context): void {
        self::$commits++;
        if (self::$failCommit) {
            throw new \RuntimeException('injected stage marker commit failure');
        }
        self::$snapshot = null;
    }
    public static function rollback(string $context): void {
        self::$rollbacks++;
        Ledger::$rows = self::$snapshot ?? [];
        self::$snapshot = null;
    }
}
final class PromotionLock {
    public static function acquire(string $owner, string $artifact, string $phase, ?int $ttl, bool $continuation): array {
        return ['owner' => $owner, 'artifact_hash' => $artifact, 'phase' => $phase];
    }
    public static function assert_no_lifecycle_attempt(string $owner, string $artifact, string $context): void {}
    public static function heartbeat(string $owner, string $artifact, string $phase): void {}
    public static function release(string $owner, string $artifact): void {}
}

require_once "$root/agent/src/Canon.php";
require_once "$root/agent/src/Code.php";

function remove_stage_transaction(string $path): void {
    if (!file_exists($path) && !is_link($path)) { return; }
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $child) {
        if ($child !== '.' && $child !== '..') {
            remove_stage_transaction($path . '/' . $child);
        }
    }
    @rmdir($path);
}

if ($mode === 'retry') {
    /** @var array{repo:string,target:string,artifact:string,ledger:array<string,string>} $state */
    $descriptor = Code::descriptor_from_source($repo . '/code/wp-content');
    $artifact = $state['artifact'];
    Ledger::$rows = $state['ledger'];
    $expectedRows = Ledger::$rows;
    $expectedRows[Code::CODE_STAGE_HISTORY_KEY] = Canon::encode([$descriptor]);
    $expectedRows[Code::CODE_STAGE_DESCRIPTOR_KEY] = Canon::encode($descriptor);
    $expectedRows[Code::CODE_STAGE_ARTIFACT_KEY] = $artifact;
    // The failed parent process already published the file before its receipt
    // rolled back, so a fresh process must conservatively decline to claim
    // that it observed the path absent.
    $expectedRows[Code::CODE_STAGE_CREATED_PATHS_KEY] = Canon::encode([]);
    $expectedRows[Code::CODE_STAGE_REVISION_KEY] = $descriptor['code_revision'];
    $result = Code::stage($repo, new CompiledRepository($descriptor, $artifact), [
        'artifact_hash' => $artifact,
        'promotion_owner' => 'stage-transaction-fresh-process-retry',
    ]);
    if (($result['staged'] ?? false) !== true || Ledger::$rows !== $expectedRows
        || Db::$starts !== 1 || Db::$commits !== 1 || Db::$rollbacks !== 0) {
        throw new \RuntimeException('FAIL: fresh stage retry did not publish one complete staged receipt');
    }
    Code::assert_verified_staged(new CompiledRepository($descriptor, $artifact));
    exit(0);
}

register_shutdown_function(static function () use ($repo, $target): void {
    remove_stage_transaction($repo);
    remove_stage_transaction($target);
});

mkdir($repo . '/code/wp-content/plugins/fixture', 0777, true);
file_put_contents(
    $repo . '/code/wp-content/plugins/fixture/fixture.php',
    "<?php\n/*\nPlugin Name: Stage Transaction Fixture\n*/\n"
);

$descriptor = Code::descriptor_from_source($repo . '/code/wp-content');
$artifact = str_repeat('d', 64);
$compiled = new CompiledRepository($descriptor, $artifact);
$stageKeys = [
    Code::CODE_STAGE_HISTORY_KEY,
    Code::CODE_STAGE_DESCRIPTOR_KEY,
    Code::CODE_STAGE_ARTIFACT_KEY,
    Code::CODE_STAGE_CREATED_PATHS_KEY,
    Code::CODE_STAGE_REVISION_KEY,
];
$storedStage = new \ReflectionMethod(Code::class, 'stored_stage_descriptor');
$storedStage->setAccessible(true);

$reset = static function () use ($target): void {
    remove_stage_transaction($target);
    mkdir($target, 0777, true);
    Ledger::$rows = [];
    Ledger::$mutation = 0;
    Ledger::$failAt = null;
    Db::$snapshot = null;
    Db::$starts = 0;
    Db::$commits = 0;
    Db::$rollbacks = 0;
    Db::$failCommit = false;
};
$freshRetry = static function () use ($repo, $target, $artifact): void {
    $script = (string) getenv('DUO_STAGE_TRANSACTION_SCRIPT');
    if ($script === '' || !is_file($script)) {
        throw new \RuntimeException('FAIL: fresh stage retry cannot locate this regression script');
    }
    $statePath = $repo . '/.stage-transaction-retry-' . bin2hex(random_bytes(6)) . '.json';
    try {
        $snapshot = json_encode([
            'repo' => $repo,
            'target' => $target,
            'artifact' => $artifact,
            'ledger' => Ledger::$rows,
        ], JSON_THROW_ON_ERROR);
        if (file_put_contents($statePath, $snapshot) === false) {
            throw new \RuntimeException('FAIL: fresh stage retry cannot persist its durable Ledger snapshot');
        }
        $inherited = getenv();
        $env = is_array($inherited) ? $inherited : [];
        $env['PATH'] = $env['PATH'] ?? '/usr/bin:/bin';
        $env['DUO_ROOT'] = (string) getenv('DUO_ROOT');
        $env['DUO_STAGE_TRANSACTION_MODE'] = 'retry';
        $env['DUO_STAGE_TRANSACTION_STATE'] = $statePath;
        $process = proc_open(['bash', $script], [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $env);
        if (!is_resource($process)) {
            throw new \RuntimeException('FAIL: fresh stage retry process could not start');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            throw new \RuntimeException(
                'FAIL: fresh stage retry process failed: ' . trim($stdout . "\n" . $stderr)
            );
        }
    } finally {
        @unlink($statePath);
    }
};
$assertRolledBack = static function (string $label) use ($stageKeys, $repo, $target, $storedStage): void {
    $source = $repo . '/code/wp-content/plugins/fixture/fixture.php';
    $materialized = $target . '/plugins/fixture/fixture.php';
    if (!is_file($materialized)
        || hash_file('sha256', $materialized) !== hash_file('sha256', $source)) {
        throw new \RuntimeException("FAIL: $label failed before complete payload materialization");
    }
    foreach ($stageKeys as $key) {
        if (array_key_exists($key, Ledger::$rows)) {
            throw new \RuntimeException("FAIL: $label retained partial staged marker $key after rollback");
        }
    }
    if (Ledger::$rows !== []) {
        throw new \RuntimeException("FAIL: $label left unexpected durable ledger state");
    }
    if ($storedStage->invoke(null, null, null) !== null) {
        throw new \RuntimeException("FAIL: $label left a stranded staged descriptor");
    }
};

foreach (range(1, 5) as $failure) {
    $reset();
    Ledger::$failAt = $failure;
    try {
        Code::stage($repo, $compiled, [
            'artifact_hash' => $artifact,
            'promotion_owner' => 'stage-transaction-failure-' . $failure,
        ]);
        throw new \RuntimeException("FAIL: stage marker statement $failure did not fail");
    } catch (\Throwable $e) {
        if ($e->getMessage() !== 'injected stage marker statement failure') {
            throw $e;
        }
    }
    if (Db::$starts !== 1 || Db::$commits !== 0 || Db::$rollbacks !== 1) {
        throw new \RuntimeException("FAIL: stage marker statement $failure did not confirm rollback");
    }
    $assertRolledBack('stage marker statement ' . $failure);
    $freshRetry();
}

$reset();
Db::$failCommit = true;
try {
    Code::stage($repo, $compiled, [
        'artifact_hash' => $artifact,
        'promotion_owner' => 'stage-transaction-commit-failure',
    ]);
    throw new \RuntimeException('FAIL: stage marker COMMIT did not fail');
} catch (\Throwable $e) {
    if ($e->getMessage() !== 'injected stage marker commit failure') {
        throw $e;
    }
}
if (Db::$starts !== 1 || Db::$commits !== 1 || Db::$rollbacks !== 1) {
    throw new \RuntimeException('FAIL: stage marker COMMIT failure did not confirm rollback');
}
$assertRolledBack('stage marker COMMIT failure');
$freshRetry();

// An uninterrupted first stage does retain the narrower proof that this path
// was absent before Duo created it. That proof is atomically bound to the
// staged descriptor and may later authorize only abandoned staged-MU cleanup.
$reset();
$result = Code::stage($repo, $compiled, [
    'artifact_hash' => $artifact,
    'promotion_owner' => 'stage-transaction-created-path-proof',
]);
$created = Canon::decode(Ledger::$rows[Code::CODE_STAGE_CREATED_PATHS_KEY] ?? 'null');
if (($result['staged'] ?? false) !== true
    || $created !== ['plugins/fixture/fixture.php']) {
    throw new \RuntimeException('FAIL: successful first stage did not bind its created-path provenance');
}

// The facade and the extracted collaborator must expose the same atomic
// receipt contract. Exercise the collaborator directly with the same fake
// ledger/transaction seam so a future facade-only implementation cannot make
// the direct boundary drift silently.
$reset();
if (!class_exists(CodeStageTransaction::class, false)) {
    throw new \RuntimeException('FAIL: CodeStageTransaction was not loaded by the Code facade');
}
CodeStageTransaction::publish(
    [$descriptor['code_revision'] => $descriptor],
    $descriptor,
    $artifact,
    ['plugins/fixture/fixture.php']
);
$directExpected = [
    Code::CODE_STAGE_HISTORY_KEY => Canon::encode([$descriptor]),
    Code::CODE_STAGE_DESCRIPTOR_KEY => Canon::encode($descriptor),
    Code::CODE_STAGE_ARTIFACT_KEY => $artifact,
    Code::CODE_STAGE_CREATED_PATHS_KEY => Canon::encode(['plugins/fixture/fixture.php']),
    Code::CODE_STAGE_REVISION_KEY => $descriptor['code_revision'],
];
if (Ledger::$rows !== $directExpected || Db::$starts !== 1 || Db::$commits !== 1 || Db::$rollbacks !== 0) {
    throw new \RuntimeException('FAIL: direct CodeStageTransaction publish drifted from the facade receipt contract');
}

echo "ok: CodeStageTransaction receipt is atomic across every marker statement and COMMIT failure\n";
PHP

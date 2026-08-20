<?php
/**
 * Offline contract for DUO-3352's shared recovery protocol foundation.
 *
 * This deliberately exercises the real codec, durable publication, lock, and
 * provider transport in isolation from WordPress and the resource bundles.
 * The existing bundle suites remain the behavioral characterization; this
 * suite proves that their common safety seam is one implementation rather
 * than four subtly divergent copies.
 */

declare(strict_types=1);

$repoRoot = dirname(__DIR__, 4);
require $repoRoot . '/recovery/rollback-control.php';

use Duo\Recovery\AtomicStore;
use Duo\Recovery\CanonicalJson;
use Duo\Recovery\ProtocolLock;
use Duo\Recovery\ProviderClient;

$failures = 0;

function ok(bool $condition, string $message): void {
    global $failures;
    if ($condition) {
        echo "ok: $message\n";
        return;
    }
    echo "FAIL: $message\n";
    $failures++;
}

function throws(callable $callback, string $needle, string $message): void {
    try {
        $callback();
        ok(false, $message . ' (did not refuse)');
    } catch (Throwable $e) {
        ok($needle === '' || str_contains($e->getMessage(), $needle), $message . ' (' . $e->getMessage() . ')');
    }
}

function treeRemove(string $path): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        treeRemove($path . '/' . $entry);
    }
    @rmdir($path);
}

$root = sys_get_temp_dir() . '/duo_recovery_protocol_' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
register_shutdown_function(static function () use ($root): void { treeRemove($root); });

echo "== canonical JSON ==\n";
$value = [
    'z' => 1,
    'list' => [['z' => 1, 'a' => 2], 1],
    'a' => ['b' => 2, 'a' => 1],
];
$expected = '{"a":{"a":1,"b":2},"list":[{"a":2,"z":1},1],"z":1}';
ok(CanonicalJson::encode($value) === $expected, 'canonical JSON sorts object keys recursively but preserves list order');
throws(static fn() => CanonicalJson::encode(['price' => 1.5]), 'unsupported value', 'canonical JSON refuses floats in protocol records');
$canonicalPath = "$root/canonical.json";
file_put_contents($canonicalPath, $expected . "\n");
ok(CanonicalJson::encode(AtomicStore::readCanonical($canonicalPath, 'canonical fixture', 'protocol test')) === CanonicalJson::encode($value),
    'canonical JSON readback returns the exact decoded object');
file_put_contents($canonicalPath, "{\"z\":1,\"a\":{\"b\":2,\"a\":1},\"list\":[{\"z\":1,\"a\":2},1]}\n");
throws(static fn() => AtomicStore::readCanonical($canonicalPath, 'noncanonical fixture', 'protocol test'), 'canonical JSON',
    'canonical JSON refuses reordered bytes instead of normalizing them');

echo "== atomic store ==\n";
$recordPath = "$root/records/record.json";
$recordBytes = $expected . "\n";
AtomicStore::atomicWrite($recordPath, $recordBytes, 0600, 'record', 'protocol test', true);
ok(file_get_contents($recordPath) === $recordBytes, 'atomic store creates parent, fsyncs, publishes, and reads back exact bytes');
AtomicStore::publishExact($recordPath, $recordBytes, 0600, 'record', 'protocol test', true);
ok(file_get_contents($recordPath) === $recordBytes, 'exact publish retry is idempotent for identical bytes');
throws(static fn() => AtomicStore::publishExact($recordPath, "{\"different\":true}\n", 0600, 'record', 'protocol test', true), 'already differs',
    'exact publish refuses a conflicting immutable record');
ok(file_get_contents($recordPath) === $recordBytes, 'conflicting exact publish leaves the original bytes intact');
$outside = "$root/outside.txt";
file_put_contents($outside, 'outside');
$linkPath = "$root/records/link.json";
symlink($outside, $linkPath);
throws(static fn() => AtomicStore::publishExact($linkPath, "{\"owned\":true}\n", 0600, 'linked record', 'protocol test', true), 'regular file',
    'atomic store refuses a symlink destination');
ok(file_get_contents($outside) === 'outside', 'symlink refusal does not mutate the target');
$checkpointPath = "$root/records/checkpoint.json";
$checkpointStages = [];
try {
    AtomicStore::atomicWrite(
        $checkpointPath,
        "{\"checkpoint\":true}\n",
        0600,
        'checkpoint',
        'protocol test',
        true,
        static function (string $stage) use (&$checkpointStages): void {
            $checkpointStages[] = $stage;
            if ($stage === 'after-rename') {
                throw new RuntimeException('injected checkpoint');
            }
        }
    );
} catch (Throwable $e) {
    ok($e->getMessage() === 'injected checkpoint', 'atomic store exposes a deterministic post-rename crash checkpoint');
}
ok($checkpointStages === ['before-write', 'after-file-fsync', 'after-rename'], 'atomic checkpoints preserve the durable write order');
ok(file_get_contents($checkpointPath) === "{\"checkpoint\":true}\n", 'post-rename fault leaves the published bytes for recovery');

echo "== protocol lock ==\n";
$lockPath = "$root/protocol.lock";
$lockEvents = [];
$lockResult = ProtocolLock::withExclusive(
    $lockPath,
    static function () use (&$lockEvents): string {
        $lockEvents[] = 'held';
        return 'callback-result';
    },
    'unsafe lock',
    'open lock failed',
    'acquire lock failed',
    0600
);
ok($lockResult === 'callback-result' && $lockEvents === ['held'], 'protocol lock returns the callback result while holding the exclusive lock');
$secondLock = ProtocolLock::withExclusive($lockPath, static fn(): string => 'reacquired', 'unsafe', 'open', 'acquire', 0600);
ok($secondLock === 'reacquired', 'protocol lock always releases after callback completion');
$unsafeLock = "$root/unsafe.lock";
symlink($outside, $unsafeLock);
throws(static fn() => ProtocolLock::withExclusive($unsafeLock, static fn(): bool => true, 'unsafe lock', 'open', 'acquire'), 'unsafe lock',
    'protocol lock refuses symlink lock paths');

echo "== provider transport ==\n";
$provider = "$root/provider.php";
file_put_contents($provider, <<<'PHP'
<?php
$request = json_decode((string) file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR);
echo json_encode(['echo' => (string) ($request['message'] ?? '')], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
PHP
);
$response = ProviderClient::request(
    [PHP_BINARY, $provider],
    ['message' => 'hello'],
    3,
    'protocol test',
    'provider start failed',
    'provider timeout',
    'provider output too large',
    'provider failed'
);
ok($response === ['echo' => 'hello'], 'provider client uses argv-only execution and canonical response readback');
throws(static fn() => ProviderClient::request([], [], 1, 'protocol test', 'provider start failed', 'timeout', 'large', 'failed'), 'provider start failed',
    'provider client refuses an empty command before process creation');
$badProvider = "$root/bad-provider.php";
file_put_contents($badProvider, "<?php echo \"{\\\"bad\\\":true}\";\n");
throws(static fn() => ProviderClient::request([PHP_BINARY, $badProvider], [], 3, 'protocol test', 'start', 'timeout', 'large', 'failed'), 'noncanonical',
    'provider client refuses noncanonical response bytes');
$largeProvider = "$root/large-provider.php";
file_put_contents($largeProvider, "<?php echo str_repeat('x', 1048577);\n");
throws(static fn() => ProviderClient::request([PHP_BINARY, $largeProvider], [], 3, 'protocol test', 'start', 'timeout', 'provider output too large', 'failed'), 'provider output too large',
    'provider client enforces the output cap after draining an exited process');

echo "== wiring ==\n";
$wiring = [
    'recovery/RecoveryExecutor.php' => ['AtomicStore::', 'ProtocolLock::', 'ProviderClient::'],
    'recovery/CheckpointBundle.php' => ['AtomicStore::', 'ProtocolLock::', 'ProviderClient::'],
    'recovery/CodeRelease.php' => ['AtomicStore::', 'ProtocolLock::', 'ProviderClient::'],
    'recovery/UploadBundle.php' => ['AtomicStore::', 'ProtocolLock::', 'ProviderClient::'],
    'recovery/EffectBundle.php' => ['AtomicStore::', 'ProtocolLock::', 'ProviderClient::'],
];
foreach ($wiring as $relative => $needles) {
    $source = (string) file_get_contents($repoRoot . '/' . $relative);
    foreach ($needles as $needle) {
        ok(str_contains($source, $needle), "$relative delegates its $needle seam");
    }
    ok(!str_contains($source, 'proc_open('), "$relative has no private provider process loop");
}
$bootstrap = (string) file_get_contents($repoRoot . '/cli/src/Onboarding/BootstrapEligibility.php');
$adopt = (string) file_get_contents($repoRoot . '/cli/src/Onboarding/Adopt.php');
foreach (['CanonicalJson.php', 'AtomicStore.php', 'ProtocolLock.php', 'ProviderClient.php'] as $file) {
    ok(str_contains($bootstrap, $file) && str_contains($adopt, $file), "$file is part of local and adopted runtime completeness");
}
$authority = "$root/authority";
\Duo\Recovery\RollbackControl::initialize($authority);
$authorityRuntime = $authority . '/recovery-runtime';
mkdir($authorityRuntime, 0700, true);
$runtimeFiles = [
    'rollback-control.php', 'RecoveryExecutor.php', 'CheckpointBundle.php',
    'CodeRelease.php', 'UploadBundle.php', 'EffectBundle.php',
    'CanonicalJson.php', 'AtomicStore.php', 'ProtocolLock.php', 'ProviderClient.php',
];
foreach ($runtimeFiles as $file) {
    copy($repoRoot . '/recovery/' . $file, $authorityRuntime . '/' . $file);
}
ok(
    \Duo\Recovery\RollbackControl::inspectReadOnly($authority)['quiescent'] === true,
    'read-only rollback inspection accepts a complete shared runtime'
);
unlink($authorityRuntime . '/ProviderClient.php');
throws(
    static fn() => \Duo\Recovery\RollbackControl::inspectReadOnly($authority),
    'recovery runtime file',
    'read-only rollback inspection refuses a runtime missing a shared protocol dependency'
);

if ($failures !== 0) {
    echo "REGRESS_RECOVERY_PROTOCOL FAILED ($failures failures)\n";
    exit(1);
}
echo "PASS: shared recovery protocol foundation is canonical, durable, locked, bounded, and wired\n";

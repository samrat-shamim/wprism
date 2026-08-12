<?php
/** DUO-3450: builder/importer prove one Woo record is current and isolated. */
declare(strict_types=1);

$failures = 0;
function check(bool $ok, string $message): void {
    global $failures;
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures++;
    }
}
function remove_tree(string $path): void {
    if (!file_exists($path) && !is_link($path)) return;
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $item) remove_tree($item->getPathname());
    rmdir($path);
}
function copy_tree(string $source, string $target): void {
    if (is_file($source)) {
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0777, true);
        copy($source, $target);
        return;
    }
    mkdir($target, 0777, true);
    foreach (new FilesystemIterator($source) as $item) {
        copy_tree($item->getPathname(), $target . '/' . $item->getFilename());
    }
}
/** @return array{exit:int,out:string,err:string,json:?array} */
function run(array $command): array {
    $pipes = [];
    $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) throw new RuntimeException('could not start command');
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($proc);
    $json = json_decode(trim($out), true);
    return ['exit' => $exit, 'out' => $out, 'err' => $err, 'json' => is_array($json) ? $json : null];
}
function write_json(string $path, array $value): void {
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}
function load_registry(string $repo): array {
    $code = <<<'PHP'
declare(strict_types=1);
$repo = $argv[1];
define('DUO_AGENT_VERSION', '0.5.0');
define('DUO_SPEC_VERSION', 2);
function is_multisite(): bool { return false; }
require $repo . '/agent/src/Canon.php';
require $repo . '/agent/src/ManifestDispositions.php';
require $repo . '/agent/src/CapabilityRegistry.php';
$dir = $repo . '/manifests';
$dispositions = Duo\ManifestDispositions::load($dir);
$manifests = [];
foreach (glob($dir . '/*.json') ?: [] as $file) {
    if (basename($file) !== 'dispositions.json') {
        $manifest = Duo\Canon::decode(Duo\Canon::read_file($file));
        $manifests[] = $manifest;
    }
}
$registry = Duo\CapabilityRegistry::load($dir, $dispositions, $manifests);
echo json_encode($registry->claim('woocommerce')['evidence']);
PHP;
    return run([PHP_BINARY, '-r', $code, $repo]);
}

$source = realpath(__DIR__ . '/../..');
$entry = json_decode((string) file_get_contents($source . '/sandbox/conformance/entries/woocommerce.json'), true);
$globalEntry = json_decode((string) file_get_contents($source . '/sandbox/conformance/manifests.json'), true)['woocommerce'] ?? null;
check(
    ($entry['manifest'] ?? null) === 'woocommerce' && ($entry['entry'] ?? null) === $globalEntry,
    'scoped Woo fixture entry has an exact subject and matches the generic conformance fixture projection'
);
$matrixHarness = (string) file_get_contents($source . '/sandbox/tests/certify_version_matrix.sh');
check(
    str_contains($matrixHarness, 'VMATRIX_MANIFEST="${VMATRIX_MANIFEST:-all}"')
    && str_contains($matrixHarness, 'all|woocommerce')
    && str_contains($matrixHarness, 'fi # VMATRIX_MANIFEST=all (non-Woo admitted boundaries)'),
    'version matrix exposes a Woo-only lane without weakening the default full matrix'
);
$root = sys_get_temp_dir() . '/duo-adapter-bundle-' . bin2hex(random_bytes(6));
register_shutdown_function(fn() => remove_tree($root));
mkdir($root, 0777, true);
$repo = "$root/repo";
// The builder verifies the actual manifest/disposition/provider closure, so
// give it a real isolated checkout instead of a hand-written substitute.
$clone = run(['git', 'clone', '--quiet', '--no-hardlinks', $source, $repo]);
check($clone['exit'] === 0 && is_file($repo . '/manifests/woocommerce.json'), 'isolated repository clone is available');
foreach ([
    'agent/src/CapabilityRegistry.php',
    'agent/src/ScopedCertificationBundle.php',
    'scripts/capability-registry.php',
    'sandbox/conformance/entries/woocommerce.json',
] as $relative) {
    if (!is_dir(dirname($repo . '/' . $relative))) {
        mkdir(dirname($repo . '/' . $relative), 0777, true);
    }
    copy($source . '/' . $relative, $repo . '/' . $relative);
}

$evidence = "$root/evidence";
mkdir($evidence, 0777, true);
$tests = [];
foreach (['conformance-woocommerce', 'exact-artifact-version-matrix'] as $id) {
    $result = "$evidence/$id.result.json";
    $diff = "$evidence/$id.diff.json";
    $log = "$evidence/$id.log";
    write_json($result, ['test' => $id, 'verdict' => 'pass', 'exit_code' => 0]);
    write_json($diff, ['status' => 'clean', 'test' => $id]);
    file_put_contents($log, "$id passed\n");
    $tests[] = ['id' => $id, 'result' => $result, 'diff' => $diff, 'log' => $log];
}
$spec = [
    'repo_root' => $repo,
    'manifest' => 'woocommerce',
    'created_at' => '2026-08-12T00:00:00Z',
    // Provenance does not participate in scoped currentness: an unbound
    // follow-up commit must not invalidate a verified Woo record.
    'git_revision' => trim((string) shell_exec('git -C ' . escapeshellarg($repo) . ' rev-parse HEAD')),
    'force_hatches' => [],
    'bound_inputs' => [
        'agent/duo.php', 'agent/src/CapabilityRegistry.php', 'cli/duo',
        'manifests/dispositions.json', 'manifests/woocommerce.json',
        'manifests/providers/woocommerce-product-lookups.php',
        'manifests/providers/woocommerce-cache.php',
        'sandbox/conformance/artifacts.lock.json', 'sandbox/conformance/run.sh',
        'sandbox/conformance/entries/woocommerce.json',
    ],
    'tests' => $tests,
];
$specPath = "$root/spec.json";
write_json($specPath, $spec);
$out = "$root/bundles";
$builder = $source . '/sandbox/bin/adapter-certification-bundle.php';
$built = run([PHP_BINARY, $builder, 'build', $specPath, $out]);
$bundle = $built['json']['bundle'] ?? null;
check(
    $built['exit'] === 0 && is_string($bundle) && is_file($bundle . '/bundle.json'),
    'builder emits a content-addressed Woo bundle with both required citations'
        . ($built['exit'] === 0 ? '' : ': ' . trim($built['err']))
);

$verified = is_string($bundle) ? run([PHP_BINARY, $builder, 'verify', $bundle, $repo]) : ['exit' => 1, 'json' => null, 'err' => 'missing bundle'];
check($verified['exit'] === 0 && ($verified['json']['verdict'] ?? null) === 'valid', 'fresh scoped bundle immediately verifies against exact current inputs');

// A descriptor without its immutable bytes is not evidence.  Import must
// reject this before it can make a projected claim current.
$tampered = "$root/tampered";
if (is_string($bundle)) copy_tree($bundle, $tampered);
file_put_contents($tampered . '/logs/conformance-woocommerce.txt', "forged evidence\n");
$tamperedTarget = "$root/tampered-target";
run(['git', 'clone', '--quiet', '--no-hardlinks', $source, $tamperedTarget]);
foreach ([
    'agent/src/CapabilityRegistry.php', 'agent/src/ScopedCertificationBundle.php',
    'scripts/capability-registry.php', 'sandbox/conformance/entries/woocommerce.json',
] as $relative) {
    if (!is_dir(dirname($tamperedTarget . '/' . $relative))) mkdir(dirname($tamperedTarget . '/' . $relative), 0777, true);
    copy($source . '/' . $relative, $tamperedTarget . '/' . $relative);
}
$tamperedImport = run([PHP_BINARY, $tamperedTarget . '/scripts/capability-registry.php', 'import-adapter-bundle', $tampered]);
check($tamperedImport['exit'] !== 0, 'import rejects a bundle whose referenced evidence log is corrupt');

file_put_contents($repo . '/docs/unbound-adapter-note.md', "unbound documentation edit\n");
$unbound = is_string($bundle) ? run([PHP_BINARY, $builder, 'verify', $bundle, $repo]) : ['exit' => 1, 'json' => null];
check($unbound['exit'] === 0, 'unrelated documentation mutation does not expire Woo evidence');
file_put_contents($repo . '/manifests/acf.json', "\n", FILE_APPEND);
$otherAdapter = is_string($bundle) ? run([PHP_BINARY, $builder, 'verify', $bundle, $repo]) : ['exit' => 1, 'json' => null];
check($otherAdapter['exit'] === 0, 'unbound ACF mutation does not expire the Woo-only closure');
file_put_contents($repo . '/agent/duo.php', "\n", FILE_APPEND);
$bound = is_string($bundle) ? run([PHP_BINARY, $builder, 'verify', $bundle, $repo]) : ['exit' => 0, 'json' => null];
check($bound['exit'] !== 0, 'bound generic engine mutation expires the Woo record');

// Restore the one bound byte and import into the isolated checkout.  Global
// evidence remains candidate there, so this proves one scoped refresh changes
// Woo only rather than accidentally upgrading every shipped claim.
$clean = "$root/clean";
run(['git', 'clone', '--quiet', '--no-hardlinks', $source, $clean]);
foreach ([
    'agent/src/CapabilityRegistry.php',
    'agent/src/ScopedCertificationBundle.php',
    'scripts/capability-registry.php',
    'sandbox/conformance/entries/woocommerce.json',
] as $relative) {
    if (!is_dir(dirname($clean . '/' . $relative))) {
        mkdir(dirname($clean . '/' . $relative), 0777, true);
    }
    copy($source . '/' . $relative, $clean . '/' . $relative);
}
$import = is_string($bundle) ? run([PHP_BINARY, $clean . '/scripts/capability-registry.php', 'import-adapter-bundle', $bundle]) : ['exit' => 1, 'json' => null];
check($import['exit'] === 0, 'current scoped bundle imports without requiring its provenance SHA to equal HEAD' . ($import['exit'] === 0 ? '' : ': ' . trim((string) ($import['err'] ?? 'missing bundle'))));
$generate = run([PHP_BINARY, $clean . '/scripts/capability-registry.php', 'generate']);
$registry = json_decode((string) file_get_contents($clean . '/manifests/capabilities/registry.json'), true);
check(
    $generate['exit'] === 0
    && ($registry['manifests']['woocommerce']['evidence']['status'] ?? null) === 'current'
    && ($registry['manifests']['woocommerce']['evidence']['bundle_schema'] ?? null) === 'duo-adapter-certification-bundle/v1'
    && ($registry['manifests']['acf']['evidence']['status'] ?? null) === 'candidate',
    'scoped import changes only Woo evidence status while global/ACF remains candidate'
);
$durable = is_string($bundle) ? $clean . '/manifests/capabilities/scoped/woocommerce/' . basename($bundle) : '';
check(
    $durable !== '' && is_file($durable . '/bundle.json')
    && is_file($durable . '/results/conformance-woocommerce.json')
    && is_file($durable . '/diffs/exact-artifact-version-matrix.json')
    && is_file($durable . '/logs/conformance-woocommerce.txt'),
    'import publishes result, diff, and log bytes at a durable content-addressed path'
);
$loaded = load_registry($clean);
check(
    $loaded['exit'] === 0 && (($loaded['json']['status'] ?? null) === 'current'),
    'runtime registry loader revalidates the durable current scoped evidence'
);
file_put_contents($durable . '/logs/conformance-woocommerce.txt', "tampered durable log\n");
$tamperedLoad = load_registry($clean);
check($tamperedLoad['exit'] !== 0, 'runtime registry loader fails closed when a durable scoped evidence asset changes');
if (is_string($bundle)) copy($bundle . '/logs/conformance-woocommerce.txt', $durable . '/logs/conformance-woocommerce.txt');
file_put_contents($clean . '/agent/duo.php', "\n", FILE_APPEND);
$staleLoad = load_registry($clean);
check($staleLoad['exit'] !== 0, 'runtime registry loader fails closed when a bound generic engine byte changes after generation');

if ($failures !== 0) {
    fwrite(STDERR, "$failures adapter certification bundle assertion(s) failed\n");
    exit(1);
}
echo "✔ REGRESS_ADAPTER_CERTIFICATION_BUNDLE PASSED\n";

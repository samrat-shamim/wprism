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
    && str_contains($matrixHarness, 'manifest name')
    && str_contains($matrixHarness, 'no exact-artifact matrix fixture is implemented'),
    'version matrix accepts manifest-driven selection and fails closed without a fixture'
);
$scopedHarness = (string) file_get_contents($source . '/sandbox/tests/certify_subject_bundle.sh');
check(
    str_contains($scopedHarness, 'source lib/pair_force_hatch.sh')
    && str_contains($scopedHarness, 'pair_force_hatch_init "$WORK_ROOT/pair-force-hatches.log"')
    && str_contains($scopedHarness, 'FORCE_HATCHES=$(pair_force_hatch_json)')
    && str_contains($scopedHarness, '--argjson force_hatches "$FORCE_HATCHES"')
    && str_contains($scopedHarness, 'force_hatches:$force_hatches'),
    'subject wrapper seals validated actual pair-budget override use into its build spec'
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
    'agent/src/ManifestDispositions.php',
    'agent/src/ScopedCertificationBundle.php',
    'manifests/dispositions.json',
    'manifests/capabilities/evidence.json',
    'manifests/capabilities/registry.json',
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
    'subject' => ['kind' => 'manifest', 'name' => 'woocommerce'],
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
$builder = $source . '/sandbox/bin/subject-certification-bundle.php';
$built = run([PHP_BINARY, $builder, 'build', $specPath, $out]);
$bundle = $built['json']['bundle'] ?? null;
check(
    $built['exit'] === 0 && is_string($bundle) && is_file($bundle . '/bundle.json'),
    'builder emits a content-addressed Woo bundle with both required citations'
        . ($built['exit'] === 0 ? '' : ': ' . trim($built['err']))
);

$verified = is_string($bundle) ? run([PHP_BINARY, $builder, 'verify', $bundle, $repo]) : ['exit' => 1, 'json' => null, 'err' => 'missing bundle'];
check($verified['exit'] === 0 && ($verified['json']['verdict'] ?? null) === 'valid', 'fresh scoped bundle immediately verifies against exact current inputs');

$invalidHatchSpec = $spec;
$invalidHatchSpec['force_hatches'] = ['unreviewed-hatch'];
$invalidHatchPath = "$root/invalid-hatch.spec.json";
write_json($invalidHatchPath, $invalidHatchSpec);
$invalidHatch = run([PHP_BINARY, $builder, 'build', $invalidHatchPath, "$root/invalid-hatch-bundles"]);
check(
    $invalidHatch['exit'] !== 0 && str_contains($invalidHatch['err'], 'force_hatches must be empty or exactly'),
    'builder rejects an unreviewed force hatch rather than treating it as a generic override'
);

$forcedSpec = $spec;
$forcedSpec['force_hatches'] = ['DUO_PAIR_BUDGET_OVERRIDE'];
$forcedSpecPath = "$root/forced.spec.json";
write_json($forcedSpecPath, $forcedSpec);
$forcedBuilt = run([PHP_BINARY, $builder, 'build', $forcedSpecPath, "$root/forced-bundles"]);
$forcedBundle = $forcedBuilt['json']['bundle'] ?? null;
$forcedManifest = is_string($forcedBundle) && is_file($forcedBundle . '/bundle.json')
    ? json_decode((string) file_get_contents($forcedBundle . '/bundle.json'), true) : null;
$forcedVerified = is_string($forcedBundle) ? run([PHP_BINARY, $builder, 'verify', $forcedBundle, $repo]) : ['exit' => 1, 'json' => null];
check(
    $forcedBuilt['exit'] === 0
    && ($forcedManifest['force_hatches'] ?? null) === ['DUO_PAIR_BUDGET_OVERRIDE']
    && $forcedVerified['exit'] === 0,
    'builder emits and verifies a forced bundle with its pair-budget override sealed into evidence'
);
$forcedTarget = "$root/forced-target";
run(['git', 'clone', '--quiet', '--no-hardlinks', $source, $forcedTarget]);
foreach ([
    'agent/src/CapabilityRegistry.php', 'agent/src/ManifestDispositions.php', 'agent/src/ScopedCertificationBundle.php',
    'manifests/dispositions.json', 'manifests/capabilities/evidence.json', 'manifests/capabilities/registry.json',
    'scripts/capability-registry.php', 'sandbox/conformance/entries/woocommerce.json',
] as $relative) {
    if (!is_dir(dirname($forcedTarget . '/' . $relative))) mkdir(dirname($forcedTarget . '/' . $relative), 0777, true);
    copy($source . '/' . $relative, $forcedTarget . '/' . $relative);
}
$forcedImport = is_string($forcedBundle)
    ? run([PHP_BINARY, $forcedTarget . '/scripts/capability-registry.php', 'import-subject-bundle', $forcedBundle])
    : ['exit' => 0, 'err' => 'missing forced bundle'];
check(
    $forcedImport['exit'] !== 0 && str_contains($forcedImport['err'], 'current capability claim must be produced without overrides'),
    'forced scoped evidence cannot be imported as a current capability claim'
);

// A descriptor without its immutable bytes is not evidence.  Import must
// reject this before it can make a projected claim current.
$tampered = "$root/tampered";
if (is_string($bundle)) copy_tree($bundle, $tampered);
file_put_contents($tampered . '/logs/conformance-woocommerce.txt', "forged evidence\n");
$tamperedTarget = "$root/tampered-target";
run(['git', 'clone', '--quiet', '--no-hardlinks', $source, $tamperedTarget]);
foreach ([
    'agent/src/CapabilityRegistry.php', 'agent/src/ManifestDispositions.php', 'agent/src/ScopedCertificationBundle.php',
    'manifests/dispositions.json', 'manifests/capabilities/evidence.json', 'manifests/capabilities/registry.json',
    'scripts/capability-registry.php', 'sandbox/conformance/entries/woocommerce.json',
] as $relative) {
    if (!is_dir(dirname($tamperedTarget . '/' . $relative))) mkdir(dirname($tamperedTarget . '/' . $relative), 0777, true);
    copy($source . '/' . $relative, $tamperedTarget . '/' . $relative);
}
$tamperedImport = run([PHP_BINARY, $tamperedTarget . '/scripts/capability-registry.php', 'import-subject-bundle', $tampered]);
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

// Restore the one bound byte and import into the isolated checkout. Every
// other subject remains candidate, proving one refresh changes Woo only.
$clean = "$root/clean";
run(['git', 'clone', '--quiet', '--no-hardlinks', $source, $clean]);
foreach ([
    'agent/src/CapabilityRegistry.php',
    'agent/src/ManifestDispositions.php',
    'agent/src/ScopedCertificationBundle.php',
    'manifests/dispositions.json',
    'manifests/capabilities/evidence.json',
    'manifests/capabilities/registry.json',
    'scripts/capability-registry.php',
    'sandbox/conformance/entries/woocommerce.json',
] as $relative) {
    if (!is_dir(dirname($clean . '/' . $relative))) {
        mkdir(dirname($clean . '/' . $relative), 0777, true);
    }
    copy($source . '/' . $relative, $clean . '/' . $relative);
}
$import = is_string($bundle) ? run([PHP_BINARY, $clean . '/scripts/capability-registry.php', 'import-subject-bundle', $bundle]) : ['exit' => 1, 'json' => null];
check($import['exit'] === 0, 'current scoped bundle imports without requiring its provenance SHA to equal HEAD' . ($import['exit'] === 0 ? '' : ': ' . trim((string) ($import['err'] ?? 'missing bundle'))));
$generate = run([PHP_BINARY, $clean . '/scripts/capability-registry.php', 'generate']);
$registry = json_decode((string) file_get_contents($clean . '/manifests/capabilities/registry.json'), true);
check(
    $generate['exit'] === 0
    && ($registry['manifests']['woocommerce']['evidence']['status'] ?? null) === 'current'
    && ($registry['manifests']['woocommerce']['evidence']['bundle_schema'] ?? null) === 'duo-subject-certification-bundle/v1'
    && ($registry['manifests']['acf']['evidence']['status'] ?? null) === 'candidate',
    'scoped import changes only Woo evidence status while ACF remains candidate'
);
$durable = is_string($bundle) ? $clean . '/manifests/capabilities/scoped/manifests/woocommerce/' . basename($bundle) : '';
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

echo "\n== profile evidence is independent from its parent manifest ==\n";
$profileRepo = "$root/profile";
run(['git', 'clone', '--quiet', '--no-hardlinks', $source, $profileRepo]);
foreach ([
    'agent/src/CapabilityRegistry.php',
    'agent/src/ManifestDispositions.php',
    'agent/src/ScopedCertificationBundle.php',
    'scripts/capability-registry.php',
    'manifests/dispositions.json',
    'manifests/capabilities/evidence.json',
    'manifests/capabilities/registry.json',
] as $relative) {
    if (!is_dir(dirname($profileRepo . '/' . $relative))) mkdir(dirname($profileRepo . '/' . $relative), 0777, true);
    copy($source . '/' . $relative, $profileRepo . '/' . $relative);
}
$profileEvidenceDir = "$root/profile-evidence";
mkdir($profileEvidenceDir, 0777, true);
$profileResult = "$profileEvidenceDir/conformance-fse.result.json";
$profileDiff = "$profileEvidenceDir/conformance-fse.diff.json";
$profileLog = "$profileEvidenceDir/conformance-fse.log";
write_json($profileResult, ['test' => 'conformance-fse', 'verdict' => 'pass', 'exit_code' => 0]);
write_json($profileDiff, ['status' => 'clean', 'test' => 'conformance-fse']);
file_put_contents($profileLog, "conformance-fse passed\n");
$profileSpec = [
    'repo_root' => $profileRepo,
    'subject' => ['kind' => 'profile', 'name' => 'fse'],
    'created_at' => '2026-08-14T00:00:00Z',
    'git_revision' => trim((string) shell_exec('git -C ' . escapeshellarg($profileRepo) . ' rev-parse HEAD')),
    'force_hatches' => [],
    'bound_inputs' => ['agent/duo.php', 'agent/src/CapabilityRegistry.php', 'manifests/core.json'],
    'tests' => [[
        'id' => 'conformance-fse',
        'result' => $profileResult,
        'diff' => $profileDiff,
        'log' => $profileLog,
    ]],
];
$profileSpecPath = "$root/profile.spec.json";
write_json($profileSpecPath, $profileSpec);
$profileBuilt = run([PHP_BINARY, $builder, 'build', $profileSpecPath, "$root/profile-bundles"]);
$profileBundle = $profileBuilt['json']['bundle'] ?? null;
$profileImport = is_string($profileBundle)
    ? run([PHP_BINARY, $profileRepo . '/scripts/capability-registry.php', 'import-subject-bundle', $profileBundle])
    : ['exit' => 1, 'err' => 'missing profile bundle'];
$profileGenerate = $profileImport['exit'] === 0
    ? run([PHP_BINARY, $profileRepo . '/scripts/capability-registry.php', 'generate'])
    : ['exit' => 1, 'err' => 'profile import failed'];
$profileRegistry = json_decode((string) file_get_contents($profileRepo . '/manifests/capabilities/registry.json'), true);
check(
    $profileBuilt['exit'] === 0
    && $profileImport['exit'] === 0
    && $profileGenerate['exit'] === 0
    && ($profileRegistry['profiles']['fse']['evidence']['status'] ?? null) === 'current'
    && ($profileRegistry['profiles']['fse']['evidence']['subject'] ?? null) === 'profiles.fse'
    && ($profileRegistry['manifests']['core']['evidence']['status'] ?? null) === 'candidate'
    && ($profileRegistry['profiles']['fse']['evidence']['bundle_digest'] ?? null)
        !== ($profileRegistry['manifests']['core']['evidence']['bundle_digest'] ?? null),
    'FSE can become current without lending evidence to or borrowing evidence from core'
        . ($profileBuilt['exit'] === 0 ? '' : ': ' . trim((string) ($profileBuilt['err'] ?? 'build failed')))
);

echo "\n== manifest-driven unknown extension onboarding ==\n";
$extensionRepo = "$root/extension";
run(['git', 'clone', '--quiet', '--no-hardlinks', $source, $extensionRepo]);
foreach ([
    'agent/src/CapabilityRegistry.php',
    'agent/src/ManifestDispositions.php',
    'agent/src/ScopedCertificationBundle.php',
    'scripts/capability-registry.php',
    'sandbox/bin/subject-certification-bundle.php',
    'sandbox/tests/certify_subject_bundle.sh',
    'manifests/dispositions.json',
    'manifests/capabilities/evidence.json',
    'manifests/capabilities/registry.json',
] as $relative) {
    if (!is_dir(dirname($extensionRepo . '/' . $relative))) mkdir(dirname($extensionRepo . '/' . $relative), 0777, true);
    copy($source . '/' . $relative, $extensionRepo . '/' . $relative);
}
$frameworkPaths = [
    'agent/src/CapabilityRegistry.php',
    'agent/src/ScopedCertificationBundle.php',
    'scripts/capability-registry.php',
    'sandbox/bin/subject-certification-bundle.php',
    'sandbox/tests/certify_subject_bundle.sh',
];
$frameworkBefore = [];
foreach ($frameworkPaths as $relative) {
    $frameworkBefore[$relative] = hash_file('sha256', $extensionRepo . '/' . $relative);
}
$syntheticManifest = json_decode((string) file_get_contents($extensionRepo . '/manifests/acf.json'), true);
$syntheticManifest['name'] = 'synthetic-extension';
$syntheticManifest['plugin'] = 'synthetic-extension/synthetic.php';
$syntheticManifest['version_range'] = ['max' => '2.0.0', 'min' => '1.0.0'];
write_json($extensionRepo . '/manifests/synthetic-extension.json', $syntheticManifest);
$syntheticDispositions = json_decode((string) file_get_contents($extensionRepo . '/manifests/dispositions.json'), true);
$syntheticClaim = $syntheticDispositions['manifests']['acf'];
$syntheticClaim['reason'] = 'Synthetic regression extension proves data-driven onboarding.';
$syntheticClaim['supported_versions'] = [
    'plugin' => 'synthetic-extension/synthetic.php',
    'range' => ['max' => '2.0.0', 'min' => '1.0.0'],
];
$syntheticClaim['evidence']['tests'] = ['conformance-synthetic-extension', 'exact-artifact-version-matrix'];
$syntheticDispositions['manifests']['synthetic-extension'] = $syntheticClaim;
write_json($extensionRepo . '/manifests/dispositions.json', $syntheticDispositions);
$artifactLock = json_decode((string) file_get_contents($extensionRepo . '/sandbox/conformance/artifacts.lock.json'), true);
$artifactLock['plugins']['synthetic-extension'] = [
    '0.9.0' => [
        'role' => 'refusal-fixture',
        'sha256' => str_repeat('1', 64),
        'url' => 'https://downloads.wordpress.org/plugin/synthetic-extension.0.9.0.zip',
    ],
    '1.0.0' => [
        'role' => 'certified-boundary',
        'sha256' => str_repeat('2', 64),
        'url' => 'https://downloads.wordpress.org/plugin/synthetic-extension.1.0.0.zip',
    ],
];
write_json($extensionRepo . '/sandbox/conformance/artifacts.lock.json', $artifactLock);
write_json($extensionRepo . '/sandbox/conformance/entries/synthetic-extension.json', [
    'entry' => [
        'pin' => ['core', 'synthetic-extension'],
        'plugins' => [['slug' => 'synthetic-extension', 'version' => '1.0.0']],
        'post_types' => ['post', 'page', 'attachment', 'acf-field-group', 'acf-field'],
        'taxonomies' => ['category', 'post_tag'],
    ],
    'manifest' => 'synthetic-extension',
]);
$syntheticEvidenceDir = "$root/synthetic-evidence";
mkdir($syntheticEvidenceDir, 0777, true);
$syntheticTests = [];
foreach (['conformance-synthetic-extension', 'exact-artifact-version-matrix'] as $id) {
    $result = "$syntheticEvidenceDir/$id.result.json";
    $diff = "$syntheticEvidenceDir/$id.diff.json";
    $log = "$syntheticEvidenceDir/$id.log";
    write_json($result, ['test' => $id, 'verdict' => 'pass', 'exit_code' => 0]);
    write_json($diff, ['status' => 'clean', 'test' => $id]);
    file_put_contents($log, "$id passed\n");
    $syntheticTests[] = ['id' => $id, 'result' => $result, 'diff' => $diff, 'log' => $log];
}
$syntheticSpec = [
    'repo_root' => $extensionRepo,
    'subject' => ['kind' => 'manifest', 'name' => 'synthetic-extension'],
    'created_at' => '2026-08-14T00:00:00Z',
    'git_revision' => trim((string) shell_exec('git -C ' . escapeshellarg($extensionRepo) . ' rev-parse HEAD')),
    'force_hatches' => [],
    'bound_inputs' => [
        'agent/duo.php',
        'agent/src/CapabilityRegistry.php',
        'manifests/synthetic-extension.json',
        'sandbox/conformance/artifacts.lock.json',
        'sandbox/conformance/entries/synthetic-extension.json',
    ],
    'tests' => $syntheticTests,
];
$syntheticSpecPath = "$root/synthetic.spec.json";
write_json($syntheticSpecPath, $syntheticSpec);
$syntheticBuilt = run([PHP_BINARY, $builder, 'build', $syntheticSpecPath, "$root/synthetic-bundles"]);
$syntheticBundle = $syntheticBuilt['json']['bundle'] ?? null;
$syntheticImport = is_string($syntheticBundle)
    ? run([PHP_BINARY, $extensionRepo . '/scripts/capability-registry.php', 'import-subject-bundle', $syntheticBundle])
    : ['exit' => 1, 'err' => 'missing synthetic bundle'];
$syntheticGenerate = $syntheticImport['exit'] === 0
    ? run([PHP_BINARY, $extensionRepo . '/scripts/capability-registry.php', 'generate'])
    : ['exit' => 1, 'err' => 'synthetic import failed'];
$syntheticRegistry = json_decode((string) file_get_contents($extensionRepo . '/manifests/capabilities/registry.json'), true);
$frameworkAfter = [];
foreach ($frameworkPaths as $relative) {
    $frameworkAfter[$relative] = hash_file('sha256', $extensionRepo . '/' . $relative);
}
check(
    $syntheticBuilt['exit'] === 0
    && $syntheticImport['exit'] === 0
    && $syntheticGenerate['exit'] === 0
    && ($syntheticRegistry['manifests']['synthetic-extension']['evidence']['status'] ?? null) === 'current'
    && ($syntheticRegistry['manifests']['synthetic-extension']['evidence']['subject'] ?? null) === 'manifests.synthetic-extension'
    && $frameworkBefore === $frameworkAfter,
    'an unknown plugin extension reaches current evidence and registry output by adding only manifest, disposition, artifacts, fixture, and tests'
        . ($syntheticBuilt['exit'] === 0 ? '' : ': ' . trim((string) ($syntheticBuilt['err'] ?? 'build failed')))
);

if ($failures !== 0) {
    fwrite(STDERR, "$failures subject certification bundle assertion(s) failed\n");
    exit(1);
}
echo "✔ REGRESS_SUBJECT_CERTIFICATION_BUNDLE PASSED\n";

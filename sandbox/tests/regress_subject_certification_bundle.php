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
function run(array $command, ?string $cwd = null, ?array $extraEnv = null): array {
    $pipes = [];
    $env = $extraEnv === null ? null : array_merge(getenv(), $extraEnv);
    $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
    if (!is_resource($proc)) throw new RuntimeException('could not start command');
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($proc);
    $json = json_decode(trim($out), true);
    return ['exit' => $exit, 'out' => $out, 'err' => $err, 'json' => is_array($json) ? $json : null];
}
function write_json(string $path, mixed $value): void {
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}
function commit_all(string $repo, string $message): array {
    $add = run(['git', '-C', $repo, 'add', '-A']);
    if ($add['exit'] !== 0) return $add;
    return run([
        'git', '-C', $repo,
        '-c', 'user.name=duo-subject-regression',
        '-c', 'user.email=subject-regression@example.test',
        'commit', '--quiet', '-m', $message,
    ]);
}
function reset_subject_evidence(string $repo): void {
    write_json($repo . '/manifests/capabilities/evidence.json', [
        'format' => 'duo-capability-evidence/v2',
        'records' => (object) [],
    ]);
    $generated = run([PHP_BINARY, $repo . '/scripts/capability-registry.php', 'generate']);
    if ($generated['exit'] !== 0) {
        throw new RuntimeException('could not reset isolated subject evidence: ' . trim($generated['err']));
    }
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
require_once $source . '/agent/src/Canon.php';
require_once $source . '/agent/src/ScopedCertificationBundle.php';
$entry = json_decode((string) file_get_contents($source . '/sandbox/conformance/entries/woocommerce.json'), true);
$globalEntry = json_decode((string) file_get_contents($source . '/sandbox/conformance/manifests.json'), true)['woocommerce'] ?? null;
check(
    ($entry['manifest'] ?? null) === 'woocommerce' && ($entry['entry'] ?? null) === $globalEntry,
    'scoped Woo fixture entry has an exact subject and matches the generic conformance fixture projection'
);
$matrixHarness = (string) file_get_contents($source . '/sandbox/tests/certify_version_matrix.sh');
check(
    str_contains($matrixHarness, 'VMATRIX_MANIFEST="${VMATRIX_MANIFEST:-}"')
    && !str_contains($matrixHarness, 'VMATRIX_MANIFEST" = all')
    && str_contains($matrixHarness, 'no exact-artifact matrix fixture is implemented'),
    'version matrix requires one manifest and exposes no repository-wide all-subject mode'
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
    // The builder requires this exact clean HEAD. Later commits may remain
    // compatible only while every canonical subject input stays byte-equal.
    'git_revision' => trim((string) shell_exec('git -C ' . escapeshellarg($repo) . ' rev-parse HEAD')),
    'force_hatches' => [],
    'bound_inputs' => [],
    'tests' => $tests,
];
$out = "$root/bundles";
$builder = $source . '/sandbox/bin/subject-certification-bundle.php';
$inputProjection = run([PHP_BINARY, $builder, 'inputs', 'manifest', 'woocommerce', $repo]);
$canonicalWooInputs = $inputProjection['json']['inputs'] ?? null;
$spec['bound_inputs'] = is_array($canonicalWooInputs) ? $canonicalWooInputs : [];
$specPath = "$root/spec.json";
write_json($specPath, $spec);
$built = run([PHP_BINARY, $builder, 'build', $specPath, $out]);
$bundle = $built['json']['bundle'] ?? null;
check(
    $built['exit'] === 0 && is_string($bundle) && is_file($bundle . '/bundle.json'),
    'builder emits a content-addressed Woo bundle with both required citations'
        . ($built['exit'] === 0 ? '' : ': ' . trim($built['err']))
);

$verified = is_string($bundle) ? run([PHP_BINARY, $builder, 'verify', $bundle, $repo]) : ['exit' => 1, 'json' => null, 'err' => 'missing bundle'];
check($verified['exit'] === 0 && ($verified['json']['verdict'] ?? null) === 'valid', 'fresh scoped bundle immediately verifies against exact current inputs');

$untrackedPath = $repo . '/agent/untracked-certification-input.php';
file_put_contents($untrackedPath, "<?php\n// convention-discovered but absent from the claimed commit\n");
$untrackedProjection = run([PHP_BINARY, $builder, 'inputs', 'manifest', 'woocommerce', $repo]);
$forged = is_string($bundle) ? json_decode((string) file_get_contents($bundle . '/bundle.json'), true) : null;
if (is_array($forged) && is_array($untrackedProjection['json']['inputs'] ?? null)) {
    $forged['closure']['inputs'] = $untrackedProjection['json']['inputs'];
    $forged['closure']['digest'] = Duo\ScopedCertificationBundle::closureDigest($forged['closure']['inputs']);
    $forged['bundle_digest'] = Duo\ScopedCertificationBundle::digest($forged);
    $forgedDir = "$root/untracked-forgery/{$forged['bundle_digest']}";
    copy_tree($bundle, $forgedDir);
    file_put_contents($forgedDir . '/bundle.json', Duo\Canon::encode($forged));
    $untrackedVerify = run([PHP_BINARY, $builder, 'verify', $forgedDir, $repo]);
} else {
    $untrackedVerify = ['exit' => 0, 'err' => 'could not manufacture untracked closure'];
}
check(
    $untrackedVerify['exit'] !== 0 && str_contains($untrackedVerify['err'], 'absent from Git revision'),
    'verification rejects a canonical current input that never existed in the claimed commit'
        . ($untrackedVerify['exit'] === 0 ? '' : ': ' . trim((string) $untrackedVerify['err']))
);
unlink($untrackedPath);

$externalMatrix = "$root/external-version-matrix";
mkdir($externalMatrix, 0777, true);
file_put_contents($externalMatrix . '/woocommerce.sh', "#!/usr/bin/env bash\nexit 0\n");
chmod($externalMatrix . '/woocommerce.sh', 0755);
mkdir($repo . '/sandbox/certification', 0777, true);
symlink($externalMatrix, $repo . '/sandbox/certification/version-matrix');
$symlinkProjection = run([PHP_BINARY, $builder, 'inputs', 'manifest', 'woocommerce', $repo]);
check(
    $symlinkProjection['exit'] !== 0 && str_contains($symlinkProjection['err'], 'symbolic-link component'),
    'closure discovery rejects a convention driver reached through a symlinked ancestor'
);
unlink($repo . '/sandbox/certification/version-matrix');
rmdir($repo . '/sandbox/certification');

$incompleteSpec = $spec;
$incompleteSpec['bound_inputs'] = ['agent/duo.php'];
$incompleteSpecPath = "$root/incomplete.spec.json";
write_json($incompleteSpecPath, $incompleteSpec);
$incompleteBuilt = run([PHP_BINARY, $builder, 'build', $incompleteSpecPath, "$root/incomplete-bundles"]);
check(
    $incompleteBuilt['exit'] !== 0 && str_contains($incompleteBuilt['err'], 'canonical subject closure'),
    'builder rejects a caller-chosen closure that omits canonical subject inputs'
);

$artifactLockPath = $repo . '/sandbox/conformance/artifacts.lock.json';
$artifactLockBytes = (string) file_get_contents($artifactLockPath);
$artifactLock = json_decode($artifactLockBytes, true);
$artifactLock['plugins']['unrelated-new-extension'] = [
    '1.0.0' => [
        'role' => 'certified-boundary',
        'sha256' => str_repeat('a', 64),
        'url' => 'https://downloads.wordpress.org/plugin/unrelated-new-extension.1.0.0.zip',
    ],
];
write_json($artifactLockPath, $artifactLock);
$unrelatedArtifact = is_string($bundle) ? run([PHP_BINARY, $builder, 'verify', $bundle, $repo]) : ['exit' => 1];
check(
    $unrelatedArtifact['exit'] === 0,
    'adding another plugin artifact projection does not expire Woo evidence'
);
file_put_contents($artifactLockPath, $artifactLockBytes);

$artifactLock = json_decode($artifactLockBytes, true);
$artifactLock['themes']['twentytwentyone']['2.8']['sha256'] = str_repeat('b', 64);
write_json($artifactLockPath, $artifactLock);
$changedBootstrapTheme = is_string($bundle) ? run([PHP_BINARY, $builder, 'verify', $bundle, $repo]) : ['exit' => 0];
check(
    $changedBootstrapTheme['exit'] !== 0 && str_contains($changedBootstrapTheme['err'], 'artifacts are not current'),
    'changing the shared bootstrap theme expires Woo evidence while unrelated lock rows remain isolated'
);
file_put_contents($artifactLockPath, $artifactLockBytes);

$newHook = $repo . '/sandbox/certification/version-matrix/woocommerce.sh';
if (!is_dir(dirname($newHook))) mkdir(dirname($newHook), 0777, true);
file_put_contents($newHook, "#!/usr/bin/env bash\nexit 0\n");
chmod($newHook, 0755);
$expandedClosure = is_string($bundle) ? run([PHP_BINARY, $builder, 'verify', $bundle, $repo]) : ['exit' => 0];
check(
    $expandedClosure['exit'] !== 0 && str_contains($expandedClosure['err'], 'closure is not current'),
    'adding a convention-discovered subject driver expires evidence because closure membership is rederived'
        . ($expandedClosure['exit'] === 0 ? '' : ': ' . trim((string) ($expandedClosure['err'] ?? '')))
);
unlink($newHook);

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
$dirtyBuilt = run([PHP_BINARY, $builder, 'build', $specPath, "$root/dirty-bundles"]);
check(
    $dirtyBuilt['exit'] !== 0 && str_contains($dirtyBuilt['err'], 'clean exact-source checkout'),
    'builder refuses to label dirty post-test bytes with the pre-test Git revision'
);
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
reset_subject_evidence($clean);
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
$staleGenerate = run([PHP_BINARY, $clean . '/scripts/capability-registry.php', 'generate']);
$staleRegistry = json_decode((string) file_get_contents($clean . '/manifests/capabilities/registry.json'), true);
$staleEvidence = $staleRegistry['manifests']['woocommerce']['evidence'] ?? [];
$staleReload = load_registry($clean);
check(
    $staleGenerate['exit'] === 0
    && ($staleEvidence['status'] ?? null) === 'candidate'
    && array_key_exists('bundle_digest', $staleEvidence) && $staleEvidence['bundle_digest'] === null
    && array_key_exists('closure_digest', $staleEvidence) && $staleEvidence['closure_digest'] === null
    && array_key_exists('git_revision', $staleEvidence) && $staleEvidence['git_revision'] === null
    && array_key_exists('subject_digest', $staleEvidence) && $staleEvidence['subject_digest'] === null
    && $staleReload['exit'] === 0
    && ($staleReload['json']['status'] ?? null) === 'candidate',
    'stale durable evidence regenerates as a loadable null-bound candidate claim'
);

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
reset_subject_evidence($profileRepo);
$profileCandidateCommit = commit_all($profileRepo, 'test: reset independent profile evidence');
check($profileCandidateCommit['exit'] === 0, 'profile fixture commits its exact candidate source before certification');
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
    'bound_inputs' => [],
    'tests' => [[
        'id' => 'conformance-fse',
        'result' => $profileResult,
        'diff' => $profileDiff,
        'log' => $profileLog,
    ]],
];
$profileInputs = run([PHP_BINARY, $builder, 'inputs', 'profile', 'fse', $profileRepo]);
$profileSpec['bound_inputs'] = is_array($profileInputs['json']['inputs'] ?? null)
    ? $profileInputs['json']['inputs'] : [];
$profileSpecPath = "$root/profile.spec.json";
write_json($profileSpecPath, $profileSpec);
$profileBuilt = run([PHP_BINARY, $builder, 'build', $profileSpecPath, "$root/profile-bundles"]);
$profileBundle = $profileBuilt['json']['bundle'] ?? null;
$profileBundleJson = is_string($profileBundle) && is_file($profileBundle . '/bundle.json')
    ? json_decode((string) file_get_contents($profileBundle . '/bundle.json'), true) : null;
$profileArtifactIds = [];
foreach (($profileBundleJson['artifacts'] ?? []) as $artifact) {
    $profileArtifactIds[] = ($artifact['kind'] ?? '') . ':' . ($artifact['name'] ?? '') . '@' . ($artifact['version'] ?? '');
}
sort($profileArtifactIds, SORT_STRING);
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
check(
    in_array('theme:twentytwentyone@2.8', $profileArtifactIds, true)
    && in_array('theme:twentytwentyfive@1.5', $profileArtifactIds, true),
    'FSE evidence binds both its shared bootstrap theme and entry-declared block theme'
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
$syntheticClaim['evidence']['tests'] = ['exact-artifact-version-matrix'];
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
$syntheticDriver = $extensionRepo . '/sandbox/certification/version-matrix/synthetic-extension.sh';
if (!is_dir(dirname($syntheticDriver))) mkdir(dirname($syntheticDriver), 0777, true);
copy($source . '/sandbox/tests/fixtures/subject-certification-custom-test.sh', $syntheticDriver);
chmod($syntheticDriver, 0755);
$syntheticCandidate = run([PHP_BINARY, $extensionRepo . '/scripts/capability-registry.php', 'generate']);
$syntheticCommit = $syntheticCandidate['exit'] === 0
    ? commit_all($extensionRepo, 'test: add convention-discovered synthetic extension')
    : ['exit' => 1, 'err' => 'candidate generation failed'];
$fakeBin = "$root/fake-bin";
mkdir($fakeBin, 0777, true);
$fakeDocker = "$fakeBin/docker";
file_put_contents($fakeDocker, <<<'SH'
#!/usr/bin/env bash
if [ "${1:-}" = compose ] && [ "${2:-}" = ls ]; then
  printf '[]\n'
elif [ "${1:-}" = info ] && [ "${3:-}" = '{{.NCPU}}' ]; then
  printf '8\n'
elif [ "${1:-}" = info ] && [ "${3:-}" = '{{.MemTotal}}' ]; then
  printf '17179869184\n'
elif [ "${1:-}" = inspect ] && [ "${2:-}" = -f ] && [ "${3:-}" = '{{.State.Health.Status}}' ]; then
  printf 'healthy\n'
fi
exit 0
SH
    . "\n");
chmod($fakeDocker, 0755);
$syntheticRun = $syntheticCommit['exit'] === 0 ? run(
    ['bash', $extensionRepo . '/sandbox/tests/certify_subject_bundle.sh', 'manifests.synthetic-extension'],
    $extensionRepo,
    [
        'PATH' => $fakeBin . PATH_SEPARATOR . (string) getenv('PATH'),
        'CERT_SUBJECT_OUT' => "$root/synthetic-bundles",
        'CERT_SUBJECT_PAIR' => 'syntheticcert',
        'CERT_SUBJECT_PORT1' => '8990',
        'CERT_SUBJECT_PORT2' => '8991',
    ]
) : ['exit' => 1, 'out' => '', 'err' => 'synthetic source commit failed'];
preg_match('/"bundle"\s*:\s*"([^"]+)"/', (string) ($syntheticRun['out'] ?? ''), $syntheticBundleMatch);
$syntheticBundle = $syntheticBundleMatch[1] ?? null;
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
    $syntheticCandidate['exit'] === 0
    && $syntheticCommit['exit'] === 0
    && $syntheticRun['exit'] === 0
    && str_contains((string) $syntheticRun['out'], 'exact-artifact-version-matrix passed')
    && $syntheticImport['exit'] === 0
    && $syntheticGenerate['exit'] === 0
    && ($syntheticRegistry['manifests']['synthetic-extension']['evidence']['status'] ?? null) === 'current'
    && ($syntheticRegistry['manifests']['synthetic-extension']['evidence']['subject'] ?? null) === 'manifests.synthetic-extension'
    && $frameworkBefore === $frameworkAfter,
    'the real runner discovers an unknown plugin extension matrix driver and reaches current registry output without framework edits'
        . ($syntheticRun['exit'] === 0 ? '' : ': ' . trim((string) ($syntheticRun['err'] ?? 'runner failed')))
);

if ($failures !== 0) {
    fwrite(STDERR, "$failures subject certification bundle assertion(s) failed\n");
    exit(1);
}
echo "✔ REGRESS_SUBJECT_CERTIFICATION_BUNDLE PASSED\n";

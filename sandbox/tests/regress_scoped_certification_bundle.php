<?php
/** DUO-3450: one-manifest certification currentness is closed and isolated. */
declare(strict_types=1);

require __DIR__ . '/../../agent/src/ScopedCertificationBundle.php';
require __DIR__ . '/certification_fixture.php';

use Duo\Canon;
use Duo\CapabilityRegistry;
use Duo\ManifestDispositions;
use Duo\ScopedCertificationBundle;

$failures = 0;
function check(bool $ok, string $message): void {
    global $failures;
    echo ($ok ? "ok: " : "FAIL: ") . $message . "\n";
    if (!$ok) {
        $failures++;
    }
}
function expect_refusal(callable $fn, string $needle, string $message): void {
    try {
        $fn();
        check(false, "$message (accepted)");
    } catch (Throwable $e) {
        check(str_contains($e->getMessage(), $needle), "$message ({$e->getMessage()})");
    }
}
function input(string $path, string $byte): array {
    return ['path' => $path, 'sha256' => hash('sha256', $byte), 'size' => strlen($byte)];
}
function asset(string $path, string $byte): array {
    return ['path' => $path, 'sha256' => hash('sha256', $byte), 'size' => strlen($byte)];
}
function scoped_bundle(array $overrides = []): array {
    $inputs = [input('agent/src/Engine.php', 'engine'), input('manifests/woocommerce.json', 'manifest')];
    $result = asset('results/woo-conformance.json', 'result');
    $diff = asset('diffs/woo-conformance.json', 'diff');
    $log = asset('logs/woo-conformance.txt', 'log');
    $disposition = ['status' => 'certified'];
    $bundle = [
        'adapter_digest' => str_repeat('a', 64),
        'artifacts' => [[
            'name' => 'woocommerce',
            'role' => 'certified-boundary',
            'sha256' => str_repeat('d', 64),
            'url' => 'https://downloads.wordpress.org/plugin/woocommerce.11.0.0.zip',
            'version' => '11.0.0',
        ]],
        'bundle_digest' => str_repeat('0', 64),
        'claims' => ['manifests.woocommerce' => ['woo-conformance']],
        'closure' => ['digest' => ScopedCertificationBundle::closureDigest($inputs), 'inputs' => $inputs],
        'created_at' => '2026-08-12T00:00:00Z',
        'force_hatches' => [],
        'format' => ScopedCertificationBundle::FORMAT,
        'git_revision' => str_repeat('b', 40),
        'platform' => ['agent_version' => '0.5.0', 'wordpress' => '7.0.2'],
        'ratification' => [
            'disposition' => $disposition,
            'manifest' => 'woocommerce',
            'sha256' => hash('sha256', Canon::encode($disposition)),
        ],
        'subject' => ['manifest' => 'woocommerce'],
        'verdict' => 'pass',
        'tests' => [[
            'diff' => $diff,
            'evidence_sha256' => ScopedCertificationBundle::evidenceDigest([$result, $diff, $log]),
            'exit_code' => 0,
            'id' => 'woo-conformance',
            'log' => $log,
            'result' => $result,
            'verdict' => 'pass',
        ]],
    ];
    $bundle = array_replace_recursive($bundle, $overrides);
    $bundle['bundle_digest'] = ScopedCertificationBundle::digest($bundle);
    return $bundle;
}
function remove_tree(string $root): void {
    if (!is_dir($root)) {
        return;
    }
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($entries as $entry) {
        $path = $entry->getPathname();
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($path);
        } else {
            unlink($path);
        }
    }
    rmdir($root);
}
/** @return array{exit:int,stdout:string,stderr:string} */
function runtime_registry_load(string $runtimeRoot, string $scratch): array {
    $runner = $scratch . '/runtime-registry-loader.php';
    $program = <<<'PHP'
<?php
declare(strict_types=1);

$root = $argv[1];
require $root . '/agent/src/Canon.php';
require $root . '/agent/src/ManifestDispositions.php';
require $root . '/agent/src/CapabilityRegistry.php';

try {
    $dir = $root . '/manifests';
    $dispositions = Duo\ManifestDispositions::load($dir);
    $manifests = [];
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        if (basename($file) !== 'dispositions.json') {
            $manifest = Duo\Canon::decode(Duo\Canon::read_file($file));
            $manifests[] = $manifest;
        }
    }
    $registry = Duo\CapabilityRegistry::load($dir, $dispositions, $manifests);
    if (($registry->claim('woocommerce')['evidence']['status'] ?? null) !== 'current') {
        throw new RuntimeException('Woo scoped claim did not load current');
    }
    fwrite(STDOUT, "current\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
PHP;
    if (file_put_contents($runner, $program) === false) {
        throw new RuntimeException('could not create deployed-runtime loader');
    }
    $pipes = [];
    $process = proc_open([PHP_BINARY, $runner, $runtimeRoot], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('could not start deployed-runtime loader');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [
        'exit' => proc_close($process),
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

$bundle = scoped_bundle();
$valid = ScopedCertificationBundle::validate($bundle);
check($valid['format'] === ScopedCertificationBundle::FORMAT, 'valid scoped bundle uses the separate v1 schema');
$current = ScopedCertificationBundle::assertCurrent(
    $bundle,
    'woocommerce',
    str_repeat('a', 64),
    ['agent_version' => '0.5.0', 'wordpress' => '7.0.2'],
    $bundle['closure']['inputs'],
    ['woo-conformance'],
    $bundle['ratification']['disposition'],
    $bundle['artifacts']
);
check($current['status'] === 'current', 'exact subject, adapter digest, platform, closure, and citations are current');

expect_refusal(fn() => ScopedCertificationBundle::assertCurrent(
    $bundle, 'acf', str_repeat('a', 64), $bundle['platform'], $bundle['closure']['inputs'], ['woo-conformance'], $bundle['ratification']['disposition'], $bundle['artifacts']
), 'subject or adapter digest', 'cross-manifest subject cannot borrow scoped evidence');
expect_refusal(fn() => ScopedCertificationBundle::assertCurrent(
    $bundle, 'woocommerce', str_repeat('d', 64), $bundle['platform'], $bundle['closure']['inputs'], ['woo-conformance'], $bundle['ratification']['disposition'], $bundle['artifacts']
), 'subject or adapter digest', 'different adapter digest cannot borrow scoped evidence');

$changedInput = $bundle['closure']['inputs'];
$changedInput[0]['sha256'] = str_repeat('e', 64);
expect_refusal(fn() => ScopedCertificationBundle::assertCurrent(
    $bundle, 'woocommerce', str_repeat('a', 64), $bundle['platform'], $changedInput, ['woo-conformance'], $bundle['ratification']['disposition'], $bundle['artifacts']
), 'closure is not current', 'bound engine mutation expires only this scoped record');
expect_refusal(fn() => ScopedCertificationBundle::assertCurrent(
    $bundle, 'woocommerce', str_repeat('a', 64), ['agent_version' => '0.5.0', 'wordpress' => '7.0.3'], $bundle['closure']['inputs'], ['woo-conformance'], $bundle['ratification']['disposition'], $bundle['artifacts']
), 'platform boundary is not current', 'platform mutation expires the scoped record');
expect_refusal(fn() => ScopedCertificationBundle::assertCurrent(
    $bundle, 'woocommerce', str_repeat('a', 64), $bundle['platform'], $bundle['closure']['inputs'], ['woo-conformance'], ['status' => 'experimental'], $bundle['artifacts']
), 'ratification fragment is not current', 'a changed reviewed disposition cannot borrow prior scoped evidence');
$changedArtifacts = $bundle['artifacts'];
$changedArtifacts[0]['sha256'] = str_repeat('e', 64);
expect_refusal(fn() => ScopedCertificationBundle::assertCurrent(
    $bundle, 'woocommerce', str_repeat('a', 64), $bundle['platform'], $bundle['closure']['inputs'], ['woo-conformance'], $bundle['ratification']['disposition'], $changedArtifacts
), 'artifacts are not current', 'a changed exact artifact boundary expires scoped evidence');
expect_refusal(fn() => ScopedCertificationBundle::validate(scoped_bundle(['force_hatches' => ['override']])), 'empty list', 'forced scoped evidence is refused');
expect_refusal(fn() => ScopedCertificationBundle::validate(scoped_bundle(['claims' => ['manifests.acf' => ['woo-conformance']]])), 'exactly manifests.woocommerce', 'cross-adapter citation is refused');
$tampered = $bundle;
$tampered['bundle_digest'] = str_repeat('f', 64);
expect_refusal(fn() => ScopedCertificationBundle::validate($tampered), 'digest is corrupt', 'tampered content-addressed digest is refused');
$missingTests = $bundle;
$missingTests['tests'] = [];
$missingTests['bundle_digest'] = ScopedCertificationBundle::digest($missingTests);
expect_refusal(fn() => ScopedCertificationBundle::validate($missingTests), 'non-empty list', 'missing evidence tests are refused');
check(Canon::encode($bundle['closure']['inputs']) === Canon::encode($valid['closure']['inputs']), 'validation preserves canonical closure identity');

foreach (['./agent/src/Engine.php', 'agent//src/Engine.php', 'agent/src/'] as $alias) {
    $nonCanonical = $bundle;
    $nonCanonical['closure']['inputs'][0]['path'] = $alias;
    // Keep the original closure digest: the path must be rejected before any
    // caller can manufacture a digest for a noncanonical identity.
    $nonCanonical['bundle_digest'] = ScopedCertificationBundle::digest($nonCanonical);
    expect_refusal(fn() => ScopedCertificationBundle::validate($nonCanonical), 'malformed or duplicated', "noncanonical closure alias '$alias' is refused");
}
$missingVerdict = $bundle;
unset($missingVerdict['verdict']);
expect_refusal(fn() => ScopedCertificationBundle::validate($missingVerdict), 'unsupported keys', 'missing verdict is refused rather than defaulted');

echo "\n== deployed scoped-certification runtime layout ==\n";
$repo = realpath(__DIR__ . '/../..');
$scratch = sys_get_temp_dir() . '/duo-scoped-runtime-' . bin2hex(random_bytes(8));
try {
    $sealedManifests = duo_cert_seal_library($repo, $scratch . '/source');
    $runtimeRoot = $scratch . '/runtime';
    duo_cert_copy_tree($scratch . '/source/agent', $runtimeRoot . '/agent');
    duo_cert_copy_tree($sealedManifests, $runtimeRoot . '/manifests');
    check(
        !file_exists($runtimeRoot . '/Makefile')
        && !file_exists($runtimeRoot . '/cli')
        && !file_exists($runtimeRoot . '/sandbox'),
        'runtime fixture contains only deployed agent and manifests trees, not host certification inputs'
    );
    $runtimeLoad = runtime_registry_load($runtimeRoot, $scratch);
    check(
        $runtimeLoad['exit'] === 0 && trim($runtimeLoad['stdout']) === 'current',
        'the copied deployed agent loads a current scoped record without host-only certification files'
    );
    $runtimeEvidence = Canon::decode(Canon::read_file($runtimeRoot . '/manifests/capabilities/evidence.json'));
    $runtimeInputs = $runtimeEvidence['scoped']['woocommerce']['bundle']['closure']['inputs'] ?? [];
    $agentInput = null;
    foreach ($runtimeInputs as $input) {
        if (is_array($input) && str_starts_with((string) ($input['path'] ?? ''), 'agent/')) {
            $agentInput = $input['path'];
            break;
        }
    }
    check(is_string($agentInput), 'sealed Woo closure contains an installed agent input');
    if (is_string($agentInput)) {
        file_put_contents($runtimeRoot . '/' . $agentInput, "\n// runtime drift\n", FILE_APPEND);
        $runtimeDrift = runtime_registry_load($runtimeRoot, $scratch);
        check(
            $runtimeDrift['exit'] !== 0
            && str_contains($runtimeDrift['stderr'], 'scoped certification runtime input is not current: ' . $agentInput),
            'a changed deployed agent input expires the scoped record without host source access'
        );
    }
} finally {
    remove_tree($scratch);
}

if ($failures !== 0) {
    fwrite(STDERR, "$failures scoped certification assertion(s) failed\n");
    exit(1);
}
echo "✔ REGRESS_SCOPED_CERTIFICATION_BUNDLE PASSED\n";

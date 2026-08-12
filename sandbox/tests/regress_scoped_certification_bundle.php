<?php
/** DUO-3450: one-manifest certification currentness is closed and isolated. */
declare(strict_types=1);

require __DIR__ . '/../../agent/src/ScopedCertificationBundle.php';

use Duo\Canon;
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
function scoped_bundle(array $overrides = []): array {
    $inputs = [input('agent/src/Engine.php', 'engine'), input('manifests/woocommerce.json', 'manifest')];
    $bundle = [
        'adapter_digest' => str_repeat('a', 64),
        'bundle_digest' => str_repeat('0', 64),
        'claims' => ['manifests.woocommerce' => ['woo-conformance']],
        'closure' => ['digest' => ScopedCertificationBundle::closureDigest($inputs), 'inputs' => $inputs],
        'created_at' => '2026-08-12T00:00:00Z',
        'force_hatches' => [],
        'format' => ScopedCertificationBundle::FORMAT,
        'git_revision' => str_repeat('b', 40),
        'platform' => ['agent_version' => '0.5.0', 'wordpress' => '7.0.2'],
        'subject' => ['manifest' => 'woocommerce'],
        'verdict' => 'pass',
        'tests' => [[
            'evidence_sha256' => str_repeat('c', 64),
            'exit_code' => 0,
            'id' => 'woo-conformance',
            'verdict' => 'pass',
        ]],
    ];
    $bundle = array_replace_recursive($bundle, $overrides);
    $bundle['bundle_digest'] = ScopedCertificationBundle::digest($bundle);
    return $bundle;
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
    ['woo-conformance']
);
check($current['status'] === 'current', 'exact subject, adapter digest, platform, closure, and citations are current');

expect_refusal(fn() => ScopedCertificationBundle::assertCurrent(
    $bundle, 'acf', str_repeat('a', 64), $bundle['platform'], $bundle['closure']['inputs'], ['woo-conformance']
), 'subject or adapter digest', 'cross-manifest subject cannot borrow scoped evidence');
expect_refusal(fn() => ScopedCertificationBundle::assertCurrent(
    $bundle, 'woocommerce', str_repeat('d', 64), $bundle['platform'], $bundle['closure']['inputs'], ['woo-conformance']
), 'subject or adapter digest', 'different adapter digest cannot borrow scoped evidence');

$changedInput = $bundle['closure']['inputs'];
$changedInput[0]['sha256'] = str_repeat('e', 64);
expect_refusal(fn() => ScopedCertificationBundle::assertCurrent(
    $bundle, 'woocommerce', str_repeat('a', 64), $bundle['platform'], $changedInput, ['woo-conformance']
), 'closure is not current', 'bound engine mutation expires only this scoped record');
expect_refusal(fn() => ScopedCertificationBundle::assertCurrent(
    $bundle, 'woocommerce', str_repeat('a', 64), ['agent_version' => '0.5.0', 'wordpress' => '7.0.3'], $bundle['closure']['inputs'], ['woo-conformance']
), 'platform boundary is not current', 'platform mutation expires the scoped record');
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

if ($failures !== 0) {
    fwrite(STDERR, "$failures scoped certification assertion(s) failed\n");
    exit(1);
}
echo "✔ REGRESS_SCOPED_CERTIFICATION_BUNDLE PASSED\n";

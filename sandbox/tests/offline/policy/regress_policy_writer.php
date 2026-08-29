<?php
/**
 * Offline regression for PolicyWriter (issue #3348 slice 27).
 *
 * The writer is deliberately tested both directly and through the public
 * Policy::export_manifest() facade: the former pins ownership of the pure
 * projection, while the latter proves the real site-repo and CLI-facing path
 * still returns the same manifest shape and refusal contract.
 */
declare(strict_types=1);

if (!defined('WPRISM_SPEC_VERSION')) {
    define('WPRISM_SPEC_VERSION', 2);
}

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Policy/PolicyWriter.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';

use WPrism\Canon;
use WPrism\Policy;
use WPrism\PolicyWriter;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$assertThrows = static function (callable $fn, string $needle, string $label) use ($check): void {
    try {
        $fn();
        $check(false, "$label: expected a refusal, none thrown");
    } catch (RuntimeException $e) {
        $check(str_contains($e->getMessage(), $needle),
            "$label: refusal names \"$needle\" (got: {$e->getMessage()})");
    }
};

$sitePolicy = [
    'options' => [
        'export_alpha' => ['class' => 'authored', 'autoload' => 'preserve'],
        'unrelated_option' => ['class' => 'runtime'],
    ],
    'post_meta' => [
        'export_title' => ['class' => 'authored'],
    ],
    'term_meta' => [],
    'user_meta' => [],
];
$sections = ['options', 'post_meta', 'term_meta', 'user_meta'];

$direct = PolicyWriter::export_manifest($sitePolicy, '^export_', 'writer-fixture', WPRISM_SPEC_VERSION, $sections);
$check($direct['name'] === 'writer-fixture', 'direct writer preserves the manifest name');
$check($direct['spec_version'] === WPRISM_SPEC_VERSION, 'direct writer preserves the supplied spec version');
$check(isset($direct['options']->export_alpha), 'direct writer includes matching option rules');
$check(isset($direct['post_meta']->export_title), 'direct writer includes matching post-meta rules');
$check(!isset($direct['options']->unrelated_option), 'direct writer excludes non-matching rules');
$check($direct['term_meta'] instanceof stdClass && $direct['user_meta'] instanceof stdClass,
    'direct writer keeps empty sections as JSON objects');
$check(array_keys($direct) === ['name', 'spec_version', 'options', 'post_meta', 'term_meta', 'user_meta'],
    'direct writer preserves the exported top-level key order');

$assertThrows(
    fn() => PolicyWriter::export_manifest($sitePolicy, '[', 'writer-fixture', WPRISM_SPEC_VERSION, $sections),
    "invalid --match regex '['",
    'direct writer preserves invalid match refusal'
);

$root = sys_get_temp_dir() . '/wprism_regress_policy_writer_' . bin2hex(random_bytes(4));
$repo = $root . '/repo';
$manifests = $root . '/manifests';
mkdir($repo, 0777, true);
mkdir($manifests, 0777, true);
Canon::write_file($manifests . '/core.json', Canon::encode([
    'name' => 'core',
    'spec_version' => WPRISM_SPEC_VERSION,
    'options' => (object) [],
    'post_meta' => (object) [],
    'term_meta' => (object) [],
]));
Canon::write_file($repo . '/site.wprism.json', Canon::encode([
    'manifests' => ['core'],
    'spec_version' => WPRISM_SPEC_VERSION,
    'policy' => $sitePolicy + ['post_types' => [], 'taxonomies' => []],
]));
$adapterLibrary = \WPrismTest\FrozenPolicy::adapterLibrary($manifests);

$loadedPolicy = Policy::load($repo, adapterLibrary: $adapterLibrary);
$facade = Policy::export_manifest($repo, '^export_', 'facade-fixture', $adapterLibrary);
$expectedFacade = PolicyWriter::export_manifest(
    $loadedPolicy->site['policy'],
    '^export_',
    'facade-fixture',
    WPRISM_SPEC_VERSION,
    $sections
);
$check(Canon::encode($facade) === Canon::encode($expectedFacade),
    'Policy::export_manifest() delegates to PolicyWriter with byte-identical canonical output');
file_put_contents($manifests . '/facade-fixture.json', Canon::encode($facade));
$adapterLibrary = \WPrismTest\FrozenPolicy::adapterLibrary($manifests);
try {
    Policy::load(null, ['facade-fixture'], adapterLibrary: $adapterLibrary);
    $check(true, 'the facade export remains loadable through Policy::load()');
} catch (Throwable $e) {
    $check(false, 'the facade export remains loadable through Policy::load() (threw: ' . $e->getMessage() . ')');
}

$assertThrows(
    fn() => Policy::export_manifest($repo, '[', 'facade-fixture', $adapterLibrary),
    "invalid --match regex '['",
    'Policy::export_manifest() preserves invalid match refusal through the facade'
);

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $file) {
    $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
}
rmdir($root);

$policySource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Policy/Policy.php');
$writerReflection = new ReflectionClass(PolicyWriter::class);
$policyReflection = new ReflectionClass(Policy::class);
$check(
    $writerReflection->getMethod('export_manifest')->isPublic()
        && $policyReflection->getMethod('export_manifest')->isPublic()
        && substr_count($policySource, 'PolicyWriter::export_manifest(') === 1
        && !str_contains($policySource, 'foreach ($sitePolicy[$section] ?? [] as $key => $rule)'),
    'Policy owns the public facade while PolicyWriter owns the projection implementation'
);

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $failure) {
        echo "  - $failure\n";
    }
    exit(1);
}
echo "\nall PolicyWriter checks passed\n";

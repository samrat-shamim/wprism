<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/tools/src/AdapterPackageValidator.php';

use WPrism\AdapterLibrary;
use WPrism\Policy;
use WPrism\Tooling\AdapterPackageValidator;
use WPrism\Tooling\AdapterProductionReadiness;

$validated = AdapterPackageValidator::validate($root, 'map-block-gutenberg');
wprism_check_same('map-block-gutenberg', $validated['adapter'], 'isolated capsule validates');
$package = dirname(__DIR__, 2);
$read = static fn(string $path): array => json_decode((string) file_get_contents($package . '/' . $path), true, 512, JSON_THROW_ON_ERROR);
$manifest = $read('package/manifest.json');
$disposition = $read('package/disposition.json');
$policy = Policy::load(null, ['core', 'map-block-gutenberg'], adapterLibrary: AdapterLibrary::fromSourcePackage($root, 'map-block-gutenberg'));
wprism_check_same('env', $policy->option_rule('gmw-map-block-key')['class'], 'native API-key option is environment-owned');
wprism_check_same(['min' => '1.35', 'max' => '1.35.1'], $manifest['version_range'], 'window contains only the inspected release family');
wprism_check_same($manifest['version_range'], $disposition['supported_versions']['range'], 'claim and enforced window agree');
wprism_check_same('certified', $disposition['status'], 'authorized bounded disposition admits the reviewed static transport');
wprism_check_same(['capture', 'compile', 'plan', 'deploy', 'apply'], $disposition['capabilities']['operations'], 'bounded transport operations are exercised');
wprism_check_same([], $disposition['capabilities']['lifecycle_phases'], 'no target lifecycle is claimed');
$readiness = AdapterProductionReadiness::record($root, 'map-block-gutenberg');
wprism_check_same('ready', $readiness['readiness'], 'every production scenario is accounted for');
wprism_check(isset($readiness['covered']['clean-target'], $readiness['covered']['lifecycle'], $readiness['covered']['failure-recovery'], $readiness['covered']['data-boundary'], $readiness['covered']['scope-platform']) && $readiness['blocked'] === [] && $readiness['gaps'] === [], 'certified declaration retains the native, safety and exact-artifact evidence owners');
$rules = $policy->block_attr_rules()['webfactory/map'];
wprism_check_same(['zoom', 'height', 'address', 'api_key'], array_column($rules, 'path'), 'native four-attribute inventory is closed');
wprism_check_same(['map-block-gutenberg'], array_values(array_unique(array_column($rules, 'codec'))), 'whole-block guard runs even when no attribute is present');
$artifacts = $read('evidence/artifacts.lock.json')['plugins']['map-block-gutenberg'];
wprism_check_same('certified-boundary', $artifacts['1.35']['role'], 'audited exact artifact is the only certified boundary');
wprism_check_same('refusal-fixture', $artifacts['1.34']['role'], 'adjacent previous release is an explicit refusal fixture');
wprism_check_same('roundtrip', $read('tests/conformance/entry.json')['entry']['mode'], 'live entry must exercise certified host deployment, not experimental agent fallback');
wprism_check_summary('map-block-gutenberg package contract');

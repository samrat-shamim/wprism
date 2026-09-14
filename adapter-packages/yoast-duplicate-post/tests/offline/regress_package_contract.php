<?php
declare(strict_types=1);
$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/tools/src/AdapterPackageValidator.php';

use WPrism\AdapterLibrary;
use WPrism\Policy;
use WPrism\Tooling\AdapterPackageValidator;
use WPrism\Tooling\AdapterProductionReadiness;

$validated = AdapterPackageValidator::validate($root, 'yoast-duplicate-post');
wprism_check_same('yoast-duplicate-post', $validated['adapter'], 'the isolated capsule passes its complete package validator');
$policy = Policy::load(null, ['core', 'yoast-duplicate-post'], adapterLibrary: AdapterLibrary::fromSourcePackage($root, 'yoast-duplicate-post'));
$disposition = json_decode(
    (string) file_get_contents($root . '/adapter-packages/yoast-duplicate-post/package/disposition.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$artifacts = json_decode(
    (string) file_get_contents($root . '/adapter-packages/yoast-duplicate-post/evidence/artifacts.lock.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);

// The bounded claim certifies the exercised settings/reference/provider path;
// managed native duplication remains an explicit refusal boundary.
$blockers = array_values(array_filter(
    $policy->certification_readiness_blockers(),
    static fn(array $row): bool => ($row['name'] ?? null) === 'yoast-duplicate-post'
));
wprism_check_same([], $blockers, 'the bounded claim projects no production-readiness blocker');
wprism_check_same(true, $policy->capability_report()['ready'], 'the bounded claim reports production-ready support');
wprism_check_same('ready', AdapterProductionReadiness::record($root, 'yoast-duplicate-post')['readiness'], 'the scenario ledger authorizes the bounded claim');
wprism_check(
    in_array('native-managed-duplication', array_column($disposition['unsupported'], 'surface'), true),
    'managed native duplication remains an explicit unsupported boundary'
);
wprism_check_same('certified-boundary', $artifacts['plugins']['duplicate-post']['4.7']['role'], '4.7 is the certified artifact boundary');
wprism_check_same('refusal-fixture', $artifacts['plugins']['duplicate-post']['4.6']['role'], '4.6 is the adjacent refusal fixture');
wprism_check_summary('regress_yoast_duplicate_post_package_contract');

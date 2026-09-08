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

// The pre-Capture clone fixture missed copied reserved UUIDs. Assert the real
// promotion boundary, not merely a prose caveat beside a still-certified claim.
$blockers = array_values(array_filter(
    $policy->certification_readiness_blockers(),
    static fn(array $row): bool => ($row['name'] ?? null) === 'yoast-duplicate-post'
));
wprism_check_same(1, count($blockers), 'the withdrawn native-clone claim creates exactly one promotion blocker');
wprism_check_same('blocked', $blockers[0]['status'] ?? null, 'production authorization is blocked');
wprism_check_same('authored_state_not_certified', $blockers[0]['code'] ?? null, 'the refusal is the explicit certification boundary');
wprism_check_same(false, $policy->capability_report()['ready'], 'historical conformance does not report managed-clone readiness');
wprism_check_same('unready', AdapterProductionReadiness::record($root, 'yoast-duplicate-post')['readiness'], 'the scenario ledger agrees with the product refusal');
wprism_check_summary('regress_yoast_duplicate_post_package_contract');

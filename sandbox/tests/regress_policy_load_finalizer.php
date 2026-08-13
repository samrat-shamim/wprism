<?php
/**
 * Offline regression for the shared Policy post-load finalization sequence.
 *
 * Policy::load() and Policy::from_snapshot() both need every manifest before
 * this aggregate closure can run. The finalizer keeps their refusal order and
 * the security-significant validate-pin-before-bind order in one direct-load
 * collaborator; source checks below make a deletion/reordering fail loudly.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../agent/src/PolicyLoadFinalizer.php';

use Duo\Policy;
use Duo\PolicyLoadFinalizer;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$check(
    class_exists(Policy::class, false)
        && class_exists(PolicyLoadFinalizer::class, false)
        && !class_exists(\Duo\RepositoryCompiler::class, false),
    'direct finalizer load closes the Policy aggregate grammar graph without loading RepositoryCompiler'
);

$policy = new Policy();
$policy->site = ['policy' => []];
$policy->manifests = [];
try {
    PolicyLoadFinalizer::finalize($policy, []);
    $check(true, 'an empty already-resolved policy completes the aggregate closure without a digest/compiler path');
} catch (Throwable $e) {
    $check(false, 'an empty already-resolved policy completes the aggregate closure without a digest/compiler path (threw: ' . $e->getMessage() . ')');
}

$conflict = new Policy();
$conflict->site = ['policy' => []];
$conflict->manifests = [
    [
        'name' => 'alpha',
        'options' => ['shared_option' => ['class' => 'env', 'required' => true]],
    ],
    [
        'name' => 'bravo',
        'options' => ['shared_option' => ['class' => 'env', 'required' => false]],
    ],
];
try {
    PolicyLoadFinalizer::finalize($conflict, [[
        'name' => 'alpha',
        'digest' => str_repeat('0', 64),
        'source' => null,
    ]]);
    $check(false, 'cross-manifest refusal remains before a later digest-pin path');
} catch (RuntimeException $e) {
    $check(
        str_contains($e->getMessage(), 'contradictory rules for options.shared_option')
            && !class_exists(\Duo\RepositoryCompiler::class, false),
        'cross-manifest refusal remains before pin validation and does not load RepositoryCompiler'
    );
} catch (Throwable $e) {
    $check(false, 'cross-manifest refusal remains before a later digest-pin path (wrong exception: ' . $e::class . ')');
}

$policySource = (string) file_get_contents(__DIR__ . '/../../agent/src/Policy.php');
$finalizerSource = (string) file_get_contents(__DIR__ . '/../../agent/src/PolicyLoadFinalizer.php');
$calls = [
    'CrossManifestGuards::validate_no_conflicting_option_rules(',
    'OptionReferenceGrammar::validate_no_overlapping_option_name_refs(',
    'AdapterContractGrammar::validate_no_conflicting_adapter_claims(',
    'ActionProviderGrammar::validate_no_conflicting_provider_ids(',
    'CrossManifestGuards::validate_no_conflicting_post_type_contracts(',
    'CrossManifestGuards::validate_one_owner_per_declared_name(',
    'ReferenceKindGrammar::validate_ref_kinds(',
    'CrossManifestGuards::validate_unique_table_id_kinds(',
    'CrossManifestGuards::validate_no_conflicting_taxonomy_object_keyspaces(',
    'CrossManifestGuards::validate_no_conflicting_description_reference_rules(',
    'ReferenceKeyspaceGrammar::validate_reference_keyspaces_and_sidecars(',
    'PinResolver::validate_manifest_pins(',
    '$policy->adapter_sources()->bind_explicit_pins($pins)',
];
$ordered = true;
$previous = -1;
foreach ($calls as $call) {
    $at = strpos($finalizerSource, $call);
    $ordered = $ordered && $at !== false && $at > $previous;
    $previous = $at === false ? $previous : $at;
}
$policyRetainsNoFinalizerCall = false;
foreach (array_slice($calls, 0, -1) as $call) {
    $policyRetainsNoFinalizerCall = $policyRetainsNoFinalizerCall
        || str_contains($policySource, $call . "\n            \$p->")
        || str_contains($policySource, $call . "\n            \$p->manifests")
        || str_contains($policySource, $call . "\$p->manifests");
}
$check(
    substr_count($policySource, 'PolicyLoadFinalizer::finalize(') === 2
        && !$policyRetainsNoFinalizerCall
        && !str_contains($policySource, 'bind_explicit_pins($pins)')
        && $ordered,
    'both Policy loaders delegate one complete ordered finalizer sequence ending in pin verification then explicit binding'
);

if ($failures !== []) {
    fwrite(STDERR, 'regress_policy_load_finalizer: ' . count($failures) . " failure(s)\n");
    exit(1);
}
echo "policy load finalizer regression passed\n";

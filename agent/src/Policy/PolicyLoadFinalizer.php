<?php
namespace WPrism;

// Policy remains the standalone entry point for the aggregate collaborators
// used below. The circular require_once matches ManifestValidator and
// SitePolicyValidator: direct finalizer users close the loader graph, while
// Policy's own require is already marked before this file is evaluated.
require_once __DIR__ . '/Policy.php';
require_once __DIR__ . '/CrossManifestGuards.php';
require_once __DIR__ . '/../Grammar/OptionReferenceGrammar.php';
require_once __DIR__ . '/../Adapter/AdapterContractGrammar.php';
require_once __DIR__ . '/../Adapter/ActionProviderGrammar.php';
require_once __DIR__ . '/../Kernel/ReferenceKindGrammar.php';
require_once __DIR__ . '/../Kernel/ReferenceKeyspaceGrammar.php';
require_once __DIR__ . '/PinResolver.php';
require_once __DIR__ . '/AdapterClaimResolutions.php';

/**
 * Shared post-load closure for live and frozen Policy construction.
 *
 * Loaders retain source discovery, local validation, snapshot reconstruction,
 * and capability-registry work. Once they have formed one complete Policy,
 * this finalizer applies the common cross-manifest/keyspace closure, verifies
 * content pins, and only then records explicit-pin authority in AdapterSources.
 * The verification-before-binding order is observable: a shaped but wrong
 * digest must never acquire even transient elevated provenance on its refusal.
 */
final class PolicyLoadFinalizer {
    /**
     * @param list<array{name:string,digest:?string,source:?string}> $pins
     */
    public static function finalize(Policy $policy, array $pins): void {
        CrossManifestGuards::validate_no_conflicting_option_rules(
            $policy->manifests,
            $policy->site['policy']['options'] ?? []
        );
        OptionReferenceGrammar::validate_no_overlapping_option_name_refs($policy->manifests);
        // WP-5.5: the operator's own claim resolutions bind BEFORE the guard
        // they answer. A resolution naming a collision the pin set no longer
        // has is the operator's file being wrong about the operator's site, and
        // reporting it as a conflict — or as nothing at all — would hide the
        // one fact they can act on. A repository declaring no resolutions
        // reaches the guard with `[]` and its refusal is unchanged, which is
        // the whole compatibility argument for this section.
        $claimResolutions = AdapterClaimResolutions::declared($policy->site['policy'] ?? []);
        AdapterClaimResolutions::assert_binds($policy->manifests, $policy->site['policy'] ?? [], 'site.wprism.json');
        AdapterContractGrammar::validate_no_conflicting_adapter_claims($policy->manifests, $claimResolutions);
        ActionProviderGrammar::validate_no_conflicting_provider_ids($policy->manifests);
        CrossManifestGuards::validate_no_conflicting_post_type_contracts($policy->manifests);
        CrossManifestGuards::validate_one_owner_per_declared_name($policy->manifests);
        ReferenceKindGrammar::validate_ref_kinds($policy->manifests, $policy->site['policy'] ?? []);
        CrossManifestGuards::validate_unique_table_id_kinds($policy->declared_tables());
        CrossManifestGuards::validate_no_conflicting_taxonomy_object_keyspaces($policy->manifests);
        CrossManifestGuards::validate_no_conflicting_description_reference_rules($policy->manifests);
        ReferenceKeyspaceGrammar::validate_reference_keyspaces_and_sidecars(
            $policy->site['policy'] ?? [],
            $policy->manifests,
            $policy->declared_tables()
        );
        PinResolver::validate_manifest_pins($pins, $policy);
        $policy->adapter_sources()->bind_explicit_pins($pins);
    }
}

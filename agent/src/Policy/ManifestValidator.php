<?php
namespace WPrism;

// Policy is the stable standalone entry point for the grammar collaborators
// used below. The require cycle is intentional and safe: Policy loads this
// class before declaring itself, while direct ManifestValidator consumers get
// the same complete grammar surface through Policy's require_once graph.
require_once __DIR__ . '/Policy.php';
require_once __DIR__ . '/../Grammar/FieldGrammar.php';
require_once __DIR__ . '/../Grammar/PostTypeGrammar.php';
require_once __DIR__ . '/ManifestGrammar.php';
require_once __DIR__ . '/../Grammar/AttributeGrammar.php';
require_once __DIR__ . '/../Adapter/ActionProviderGrammar.php';
require_once __DIR__ . '/../Grammar/OptionGrammar.php';
require_once __DIR__ . '/../Grammar/UserMetaGrammar.php';
require_once __DIR__ . '/ScopeGrammar.php';
require_once __DIR__ . '/../Grammar/SubKeyGrammar.php';
require_once __DIR__ . '/../Grammar/TaxonomyGrammar.php';
require_once __DIR__ . '/../Grammar/OptionReferenceGrammar.php';
require_once __DIR__ . '/../Adapter/AdapterContractGrammar.php';
require_once __DIR__ . '/DiscoveryGrammar.php';
require_once __DIR__ . '/../Kernel/ReferenceShapeGrammar.php';
require_once __DIR__ . '/../Grammar/ColumnCodecGrammar.php';
require_once __DIR__ . '/../Grammar/AttrIdCodecGrammar.php';
require_once __DIR__ . '/../Grammar/BodyRefGrammar.php';
require_once __DIR__ . '/../Kernel/BlockValueGrammar.php';
require_once __DIR__ . '/../Kernel/BlockMediaDerivativeGrammar.php';

/**
 * Pure per-manifest validation pipeline shared by live and frozen policy
 * loading (issue #3348 slice 28).
 *
 * Policy keeps the engine-owned vocabularies because runtime classification
 * and materialization also publish/read some of them. This class owns only
 * the order and composition of the manifest-local grammar calls; it performs
 * no source resolution, declared-name checks, snapshot pin checks, or
 * cross-manifest validation.
 */
final class ManifestValidator {
    /**
     * Validate one already-resolved manifest through the shared local grammar
     * pipeline.
     *
     * The final flag preserves a pre-existing entry-point distinction: live
     * loading runs taxonomy object-keyspace declarations before dynamic
     * options, while frozen loading runs dynamic options first. That ordering
     * is retained because refusal order is part of the observable contract.
     *
     * @param array<string,mixed> $manifest
     * @param array{
     *   derivable_field_columns: array<string,string>,
     *   field_classes: list<string>,
     *   menu_derivable_fields: list<string>,
     *   menu_field_classes: list<string>,
     *   casts: list<string>,
     *   classes: list<string>,
     *   missing_user_modes: list<string>
     * } $vocabulary
     */
    public static function validate_manifest(
        array $manifest,
        string $label,
        array $vocabulary,
        bool $dynamicOptionsBeforeTaxonomy = false
    ): void {
        FieldGrammar::validate_field_classes(
            $manifest,
            $vocabulary['derivable_field_columns'],
            $vocabulary['field_classes']
        );
        FieldGrammar::validate_menu_field_classes(
            $manifest,
            $vocabulary['menu_derivable_fields'],
            $vocabulary['menu_field_classes']
        );
        PostTypeGrammar::validate_post_type_children($manifest);
        PostTypeGrammar::validate_post_type_contracts($manifest);
        ManifestGrammar::validate_tables($manifest, $label);
        AttributeGrammar::validate_attr_rules($manifest, $vocabulary['casts']);
        ManifestGrammar::validate_widgets($manifest);
        PostTypeGrammar::validate_regen_dependencies($manifest);
        ActionProviderGrammar::validate_providers($manifest);
        ActionProviderGrammar::validate_actions($manifest);
        OptionGrammar::validate_env_options($manifest, $label);
        UserMetaGrammar::validate_user_meta_rules(
            $manifest,
            $label,
            $vocabulary['classes'],
            $vocabulary['missing_user_modes']
        );
        ScopeGrammar::validate_scope_classes($manifest, $label, false);
        SubKeyGrammar::validate_sub_keys($manifest, $label);
        TaxonomyGrammar::validate_object_type_option_refs($manifest);

        if ($dynamicOptionsBeforeTaxonomy) {
            // issue #3318: preserve from_snapshot()'s established order. Frozen
            // snapshots must reach the same verdict as the process that
            // created them, including which local refusal is reported first.
            SubKeyGrammar::validate_dynamic_options($manifest);
            TaxonomyGrammar::validate_taxonomy_object_keyspace_declarations($manifest);
        } else {
            TaxonomyGrammar::validate_taxonomy_object_keyspace_declarations($manifest);
            SubKeyGrammar::validate_dynamic_options($manifest);
        }

        OptionReferenceGrammar::validate_option_name_refs($manifest);
        OptionGrammar::validate_option_storage($manifest, $label);
        AdapterContractGrammar::validate_adapter_contract($manifest);
        ActionProviderGrammar::validate_effect_contracts($manifest);
        DiscoveryGrammar::validate_discovery_contract($manifest);
        ReferenceShapeGrammar::validate_reference_shapes($manifest, $label, true);
        // WP-6.1's two `engine_features`-staged sections, and their placement
        // is the contract. AFTER validate_adapter_contract() above, because §
        // v3.2/§ v3.3's three verdicts must stay distinct and must arrive
        // FIRST: a `spec_version: 2` manifest declaring one of these sections
        // has to be refused BY SECTION, a v3 manifest declaring it without the
        // feature BY KEY, and an engine lacking the feature BY FEATURE NAME —
        // none of which is a statement about whether the section's contents are
        // well formed. Reaching a codec's own grammar refusal first would tell
        // an author to fix a declaration this engine was never going to admit.
        ColumnCodecGrammar::validate_column_codecs($manifest, $label);
        AttrIdCodecGrammar::validate_attr_id_codecs($manifest, $label);
        BlockValueGrammar::validate($manifest);
        BlockMediaDerivativeGrammar::validate($manifest);
        // WP-6.5's staged section, in the same slot and for the same reason.
        // Its BODY-MODE half is gated earlier, inside
        // PostTypeGrammar::validate_post_type_contracts() — the same placement
        // ManifestGrammar::assert_invalidate_feature_gate() already has, because
        // a value inside an existing section has to be gated where that section
        // is read. Only the `body_refs` section's own contents are judged here,
        // after § v3.2/§ v3.3 have had their say about whether the section
        // exists for this manifest at all.
        BodyRefGrammar::validate_body_refs($manifest, $label);
    }
}

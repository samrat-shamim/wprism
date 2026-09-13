<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/ColumnValueCases.php';
require_once __DIR__ . '/../Kernel/FieldTemplateMap.php';
require_once __DIR__ . '/../Kernel/InputFileBinding.php';

require_once __DIR__ . '/../Kernel/ScalarValueConstraint.php';

require_once __DIR__ . '/../Kernel/TableRowScope.php';

require_once __DIR__ . '/../Kernel/PhpContainerValue.php';
require_once __DIR__ . '/../Kernel/KeyBoundStrings.php';
require_once __DIR__ . '/../Kernel/BlockValueGrammar.php';
require_once __DIR__ . '/../Kernel/BlockContentGrammar.php';
require_once __DIR__ . '/../Kernel/RecordFields.php';
require_once __DIR__ . '/../Kernel/EncodedText.php';
require_once __DIR__ . '/../Kernel/PostMetaInvalidation.php';
require_once __DIR__ . '/../Kernel/BlockMediaDerivativeGrammar.php';

require_once __DIR__ . '/AdapterSources.php';
// The feature roster names the action grammar's bounded post-kind selector.
// Load that owner explicitly: agent/ has no production autoloader, and the
// source-require guard treats an undeclared edge as a real partial-load defect.
require_once __DIR__ . '/ActionProviderGrammar.php';
// WP-4.12: the {N-1, N} window itself, shared with RepositoryCompiler, which
// judges site.wprism.json's own spec_version and cannot reference this layer.
require_once __DIR__ . '/../Kernel/SpecVersionWindow.php';
require_once __DIR__ . '/../Kernel/NativeValueValidation.php';
require_once __DIR__ . '/../Kernel/ReferenceCondition.php';
// WP-6.4: the value grammar for the `declaration_evidence` section. Eager, not
// lazy like AdapterCertification below — that one is deferred because it is one
// of the four names agent/wprism.php's bootstrap deliberately does not declare
// (agent/wprism.php:124-128); this one is an ordinary sibling with no dependencies
// of its own, and validate_adapter_contract() names it unconditionally.
require_once __DIR__ . '/StructuredEvidence.php';
// The manifest-provider runtime owns its feature name. This roster reads that
// one definition so a manifest cannot pass the feature gate under a spelling
// the runtime itself would never recognize.
require_once __DIR__ . '/ManifestProviderRuntime.php';
// SDK availability is independent of the provider protocol. Read the SDK's
// own names so a v3 engine predating a method refuses its consumer at load.
require_once __DIR__ . '/ProviderSdk.php';
// WP-6.2: IMPLEMENTED_FEATURES keys one row off
// ManifestGrammar::INVALIDATE_VOCABULARY_FEATURE. Required directly rather than
// leaned on Policy.php's own require below, because a constant expression that
// resolves through a circular include is a load-order bug waiting for the first
// caller that reaches this file first. ManifestGrammar requires nothing itself
// — that is its stated design property — so this costs one stat.
require_once __DIR__ . '/../Policy/ManifestGrammar.php';
// WP-6.5: the same one-definition rule as the line above, for the feature name
// and the section name `structured-body-refs/v1` claims. BodyRefGrammar is a
// leaf in Grammar (JsonRefs/ReferenceRules/Secrets, all Kernel), so this costs
// the same one stat and cannot circle back through this file.
require_once __DIR__ . '/../Grammar/BodyRefGrammar.php';
// Redirection's action_data column is the measured mixed serialized/text
// demand; this leaf owns the value-vocabulary feature name that gates it.
require_once __DIR__ . '/../Grammar/ColumnCodecGrammar.php';
require_once __DIR__ . '/../Kernel/ScalarReferenceIntersection.php';
// Circular with Policy.php's require_once of this file: safe because
// require_once records the currently included path before the nested require
// is reached, while these methods only resolve Policy at call time.
require_once __DIR__ . '/../Policy/Policy.php';
// WP-5.5: the claim arms and the operator's resolution of a collision between
// two of them. A downward reference (policy is below adapter on the ladder in
// tools/modules.json), so the grammar reads the site-policy section rather
// than the site-policy section reaching up into the grammar.
require_once __DIR__ . '/../Policy/AdapterClaimResolutions.php';

/**
 * Pure adapter compatibility contract grammar.
 *
 * This collaborator validates per-manifest plugin/theme/spec/interpreter
 * claims and the cross-manifest no-conflicting-ownership guard. It never
 * reads the live environment or runs provider code. Policy retains the
 * shared range predicate and loader/runtime/reporting orchestration.
 *
 * It is also where the wire version is decided, and since WP-4.2 that is a
 * WINDOW rather than an equality: a manifest declaring `WPRISM_SPEC_VERSION` or
 * the one version before it is accepted, an integer outside that window refuses
 * naming the window, and a manifest inside it that declares a section this
 * engine implements only at a higher version refuses naming the section
 * (spec/repo-format.md § v3.1). The point is that every later format change can
 * stage through the window, or through the per-adapter `engine_features`
 * channel below (§ v3.2), one adapter at a time — so v3 is meant to be the last
 * flag day rather than one of a series.
 *
 * Since WP-4.3 it is also where the top-level key set is CLOSED for a
 * `spec_version: 3` manifest (§ v3.3): a key in no arm of the signer's own
 * partition and claimed by no implemented feature refuses BY NAME, instead of
 * loading and meaning nothing. The set is not defined here — it is read from
 * `AdapterCertification::topLevelKeyPartition()`, which is what makes the
 * validator and the signer two readers of one definition rather than two lists
 * that agree until they do not.
 */
final class AdapterContractGrammar {
    /** A manifest-owned, fail-closed incompatibility between exact plugin basenames. */
    public const PLUGIN_INCOMPATIBILITY_FEATURE = 'plugin-incompatibility/v1';
    public const PLUGIN_INCOMPATIBILITY_SECTION = 'incompatible_plugins';

    /**
     * Engine features this engine IMPLEMENTS, and what each one claims.
     *
     * ONE definition carrying every fact a feature decides, because they can
     * never be allowed to disagree: `since` is the first `spec_version` at
     * which the feature's sections exist, and `keys` maps each top-level
     * manifest section the feature claims to the CERTIFICATE ARM that section
     * classifies into. § v3.1's per-section refusal reads the minimum version
     * of a section straight out of these rows (section_min_spec()), § v3.2's
     * channel answers "does this engine implement the name this adapter
     * declared" out of the same rows, and § v3.21's signer reads the arm out of
     * them too (admitted_feature_key_arms()) — so a feature cannot be
     * implemented with its section unknown, or the reverse, or with its section
     * unsignable.
     *
     * THE ARM IS PART OF THE ROW BECAUSE THE ALTERNATIVE WAS MEASURED (WP-6.6,
     * spec/repo-format.md § v3.21). `keys` used to be a bare list and the arm
     * lived only in `AdapterCertification`'s three private constants, which
     * meant a feature could ship its section, load on every site, and be
     * unsignable — `AdapterCertification.php:672-694` narrates that exact wall
     * being patched key by key twice (`environment`, `theme_version_range`), and
     * `sandbox/fixtures/wpforms-lite/adapters/wpforms-lite.json` hit it a third
     * time with four keys at once. A map cannot carry a key without an arm, so
     * the failure mode is now unrepresentable rather than remembered. The arm
     * VOCABULARY is not spelled here: it is
     * `AdapterCertification::certificateArms()`, and feature_key_arms() refuses
     * any other value and any key the signer's own partition already carries.
     *
     * `spec-window/v1` is the first entry and is implemented BY THIS FILE: the
     * N/N-1 acceptance window plus the declaration channel itself. That is what
     * makes the channel a live product path on the day it ships rather than an
     * admissibility argument — the failure mode `authored_typed_snapshot_
     * post_v1` demonstrates, which is declared by nothing across all 18 shipped
     * manifests.
     *
     * After `spec-window/v1`, TEN post-v3 features shipped through this
     * channel in one wave, and together they are the proof § v3.2's claim
     * holds — each staged a grammar change with `WPRISM_SPEC_VERSION` left at 3,
     * asserted by each one's own suite:
     *
     *   - `typed-column-codecs/v1` and `attr-id-codecs/v1` (WP-6.1): how one
     *     typed-table column's bytes decode, and the JSON type one block
     *     attribute's resolved id is written back as. Each is a TOP-LEVEL key
     *     rather than a field nested inside `tables`/`block_attrs` for one
     *     reason: a nested field cannot be staged — an engine that predates it
     *     would ignore the field and capture the raw bytes, which is the
     *     silent mis-read this channel exists to convert into a named refusal.
     *   - `structured-evidence/v1` (WP-6.4, spec/repo-format.md § v3.14): the
     *     typed sibling of `notes` that makes the empirical case file
     *     machine-readable.
     *   - `mixed-column-codecs/v1`: admits a measured plain/serialized/NULL
     *     value vocabulary inside `column_codecs` without claiming a second
     *     top-level section.
     *   - `invalidate-vocabulary/v1` (WP-6.2, § v3.15): widens the invalidate[]
     *     verb set INSIDE a section that already exists — the row that shows
     *     `keys` may legitimately be EMPTY, because a feature can widen a value
     *     vocabulary without claiming a new top-level key, and forcing it to
     *     invent one would put a section in the manifest bytes for the sake of
     *     the record's shape.
     *   - `structured-body-refs/v1` (WP-6.5, § v3.20): claims `body_refs` and
     *     admits the `json` post-body mode under one feature, so neither half
     *     can be declared as an inert promise without the other.
     *   - `manifest-provider-runtime/v1` (§ v3.22): moves the manifest-owned
     *     provider protocol shell into core while leaving plugin calls and
     *     value-level postconditions in the digest-bound behavior file.
     *   - `post-kind-action-trigger/v1`: the bounded post:* action selector,
     *     which matches only concrete scoped post kinds and no other surface.
     *   - `schema-settlement/v1`: a host-checkpointed, fresh-process provider
     *     phase which establishes an exact declared table set before strict
     *     target observation; it never makes an absent table plannable.
     *   - `plugin-incompatibility/v1`: one plugin adapter's sorted exact-
     *     basename list of competing plugins that cannot share its policy;
     *     the aggregate loader refuses before mutation in either pin order.
     *
     * All are keyed at `since` 3. PMPro was the first migrated consumer; ten
     * later provider-bearing manifests deliberately paid their own identity
     * change for `manifest-provider-runtime/v1`. Redirection was authored with
     * the runtime, mixed codec, and evidence declarations in its first digest,
     * so no existing adapter moved for that demand (AGENTS.md rule 2). The
     * engine's whole cost for each remains one roster row plus its validating
     * collaborator — the replacement for a flag day has been walked repeatedly
     * before the old window is retired.
     *
     * Feature names are ENGINE-OWNED: an adapter declares one, never mints one
     * (spec/repo-format.md § v3.2). A name is also permanent, which is why
     * docs/wire-surface.md carries it as row R-19: a declared name lives inside
     * the manifest bytes ArtifactPolicyIdentity::manifest_rows() folds into the
     * adapter digest that every `site.wprism.json` pin and every certificate
     * binds, so renaming one moves the digest of every manifest declaring it.
     *
     * @var array<string,array{since:int,keys:array<string,string>}>
     */
    private const IMPLEMENTED_FEATURES = [
        TableRowScope::FEATURE => ['since' => 3, 'keys' => []],
        TableRowScope::SETS_FEATURE => ['since' => 3, 'keys' => []],
        BlockContentGrammar::FEATURE => ['since' => 3, 'keys' => [BlockContentGrammar::SECTION => 'field']],
        BlockMediaDerivativeGrammar::FEATURE => ['since' => 3, 'keys' => [BlockMediaDerivativeGrammar::SECTION => 'field']],
        BlockValueGrammar::FEATURE => ['since' => 3, 'keys' => [BlockValueGrammar::SECTION => 'field']],
        // Exact groups normalize inside block_values, so they retain that
        // section's certificate arm and cannot create a second state surface.
        BlockValueGrammar::GROUP_FEATURE => ['since' => 3, 'keys' => []],
        BlockValueGrammar::CONTRACT_FEATURE => ['since' => 3, 'keys' => []],
        RecordFields::FEATURE => ['since' => 3, 'keys' => []],
        RecordFields::OBJECT_FEATURE => ['since' => 3, 'keys' => []],
        EncodedText::FEATURE => ['since' => 3, 'keys' => []],
        ScalarValueConstraint::FEATURE => ['since' => 3, 'keys' => []],
        PostMetaInvalidation::FEATURE => ['since' => 3, 'keys' => []],
        PhpContainerValue::FEATURE => ['since' => 3, 'keys' => []],
        KeyBoundStrings::FEATURE => ['since' => 3, 'keys' => []],
        ReferenceCondition::FEATURE => ['since' => 3, 'keys' => []],
        // A predicate on an existing metadata field, not a new surface. Its
        // native arm executes only at explicit Capture/Plan/Apply boundaries.
        NativeValueValidation::FEATURE => ['since' => 3, 'keys' => []],
        // A value constraint on an existing option field; canonical references
        // keep their existing kind and certificate arm, so no new section.
        ScalarReferenceIntersection::FEATURE => [
            'since' => 3,
            'keys' => [],
        ],
        // ARM `field`, and the reviewed reason: an id codec is a typed
        // REFINEMENT over a declared `block_attrs` rule — it decides the JSON
        // type one already-declared attribute's resolved id is written back as
        // (AttrIdCodecGrammar::validate_one() refuses a codec with no rule
        // beneath it). Its standing is `block_attrs`'s exactly, and
        // `block_attrs` is a field section, so a certificate covers the two
        // together or covers the second one falsely.
        'attr-id-codecs/v1' => [
            'since' => 3,
            'keys' => ['attr_id_codecs' => 'field'],
        ],
        // ARM `non_surface`, and this is the row that shows the arm is a
        // decision rather than bookkeeping: `engine_features` is the CLAIM
        // CHANNEL itself. It covers no state — it is a list of engine feature
        // names — so putting it in a certificate's `surfaces` list would put a
        // runtime assertion where an operator reads covered state. That is the
        // standing `spec_version` already has in NON_SURFACE_KEYS, and this key
        // is the same kind of fact about the document rather than about the
        // site.
        'spec-window/v1' => [
            'since' => 3,
            'keys' => ['engine_features' => 'non_surface'],
        ],
        // WP-6.4, and the reason this constant is worth having: the FIRST
        // grammar section to ship after v3, added here and nowhere else, with
        // `WPRISM_SPEC_VERSION` left at 3. `since` is 3 rather than 4 for the
        // same reason it is 3 for the row above and NOT the version at which
        // the section was written: `since` is the first version whose grammar
        // HAS the section, and this engine's does. A 4 here would refuse the
        // section at every version this engine accepts (assert_section_
        // versions()) and made the next bump a precondition for using it —
        // which is how a channel meant to AVOID a flag day quietly schedules
        // one. `regress_structured_evidence.php` asserts this 3 against the
        // define, so the two cannot drift apart unnoticed.
        // ARM `non_surface`: the records are PROVENANCE — `{source, locator,
        // observation}` rows and answered questions about why the other
        // declarations say what they say. A certificate's surface list is the
        // state an operator is told is covered, and evidence prose is not
        // state; listing it would make the claim's `surfaces` grow by a member
        // no apply, capture or deploy ever touches. It is `notes` with a
        // machine-checkable shape (§ v3.14), and `notes` is non-surface.
        'structured-evidence/v1' => [
            'since' => 3,
            'keys' => [StructuredEvidence::SECTION => 'non_surface'],
        ],
        // ARM `field`, for `attr_id_codecs`'s reason read one section over: a
        // column codec refines how ONE declared `authored` column of an
        // already-declared `authored_snapshot` table decodes
        // (ColumnCodecGrammar::validate_one() refuses a codec over a column
        // that is not one). `tables` is the entity; the bytes inside one of its
        // columns are a field, exactly as `post_meta` is a field beside
        // `post_types`.
        'typed-column-codecs/v1' => [
            'since' => 3,
            'keys' => ['column_codecs' => 'field'],
        ],
        // Redirection 5.9.0 stores a plain URL, a serialized conditional map,
        // or NULL in one action_data column. This claims no new top-level key:
        // it widens the container vocabulary inside column_codecs, while the
        // existing typed-column-codecs/v1 feature still claims that section.
        ColumnCodecGrammar::MIXED_FEATURE => [
            'since' => 3,
            'keys' => [],
        ],
        ColumnCodecGrammar::VALUES_FEATURE => [
            'since' => 3,
            'keys' => [],
        ],
        ColumnCodecGrammar::FIELD_LABELS_FEATURE => [
            'since' => 3,
            'keys' => [],
        ],
        ColumnCodecGrammar::RECORDS_FEATURE => [
            'since' => 3,
            'keys' => [],
        ],
        ColumnValueCases::FEATURE => ['since' => 3, 'keys' => []],
        FieldTemplateMap::FEATURE => ['since' => 3, 'keys' => []],
        InputFileBinding::FEATURE => ['since' => 3, 'keys' => []],
        ColumnCodecGrammar::JSON_FEATURE => [
            'since' => 3,
            'keys' => [],
        ],
        // WP-6.2, and the first entry that claims NO top-level key: it widens a
        // VALUE vocabulary inside a section that already exists
        // (`tables.<t>.invalidate[]` gains `{cache_group, cache_key}`,
        // spec/repo-format.md § v3.15). An empty `keys` is therefore the honest
        // record rather than a placeholder — section_min_spec() and
        // admitted_feature_keys() both fold over `keys`, so this row correctly
        // contributes nothing to either, and the partition R-21 counts does not
        // move. `since: 3` is not decorative: the channel that carries the name
        // is itself a v3-only section, so an engine reads this feature exactly
        // when a manifest can declare it.
        //
        // This is the growth § v3.2 promised and § v3.12 names as a condition
        // for ever closing the window — a grammar change shipped post-v3 with
        // no version integer moving anywhere. The gate that consumes it is
        // ManifestGrammar::assert_invalidate_feature_gate(); the name is read
        // from there rather than spelled here because Policy is below Adapter
        // on tools/modules.json's ladder and one definition cannot drift.
        // NO ARM, because there is no key to classify — and that is the honest
        // record rather than a gap. WP-6.6 swept every row for an arm and this
        // one has nothing to sweep: a feature that widens a value vocabulary
        // inside a section that already exists adds no surface, so the section
        // it widens (`tables`) keeps the entity arm the partition already gives
        // it. feature_key_arms() folds over `keys` and this row contributes
        // nothing to it, exactly as it contributes nothing to
        // section_min_spec().
        ManifestGrammar::INVALIDATE_VOCABULARY_FEATURE => [
            'since' => 3,
            'keys' => [],
        ],
        // This widens providers[].capabilities with the sibling `contracts`
        // map; no top-level key is added, so the providers section keeps its
        // existing non-surface certificate arm. The map is executable
        // protocol metadata, not plugin behavior: identity, dispatch, scoped
        // receipt construction, and recovery routing are supplied by
        // ManifestProviderRuntime while the provider file retains the native
        // mutation and value-level postcondition.
        ManifestProviderRuntime::FEATURE => [
            'since' => 3,
            'keys' => [],
        ],
        // Manifest providers may opt a capability into the engine's fixed
        // stdin/receipt child protocol. The provider declaration is already a
        // non-surface section; the feature widens only its execution metadata.
        ManifestProviderRuntime::FRESH_PROCESS_FEATURE => [
            'since' => 3,
            'keys' => [],
        ],
        // Independent runtime API requirements, not new sections or grants.
        // Their existing active contract/profile and Db session boundaries
        // still own all read and write authority after compatibility admits.
        ProviderSdk::PHYSICAL_TABLE_ROWS_FEATURE => ['since' => 3, 'keys' => []],
        ProviderSdk::TYPED_ROW_MUTATIONS_FEATURE => ['since' => 3, 'keys' => []],
        ProviderSdk::NATIVE_OPTION_INPUTS_FEATURE => ['since' => 3, 'keys' => []],
        ProviderSdk::NATIVE_POST_TYPES_FEATURE => ['since' => 3, 'keys' => []],
        ProviderSdk::NATIVE_PERMALINKS_FEATURE => ['since' => 3, 'keys' => []],
        ProviderSdk::FILESYSTEM_FILE_SNAPSHOT_FEATURE => ['since' => 3, 'keys' => []],
        // A plugin incompatibility is a constraint on which adapter contracts
        // may share one policy, not a state surface. The exact-basename list is
        // therefore non-surface, while PolicyLoadFinalizer enforces it before
        // a compiler, lifecycle hook, lease, or provider can be reached.
        self::PLUGIN_INCOMPATIBILITY_FEATURE => [
            'since' => 3,
            'keys' => [self::PLUGIN_INCOMPATIBILITY_SECTION => 'non_surface'],
        ],
        // A value-vocabulary extension inside actions[].triggers, not a new
        // top-level section. CanonicalSurfaces constrains it to concrete post
        // kinds; ActionProviderGrammar owns both this name and its load-time
        // feature gate, so an older engine refuses instead of interpreting a
        // wildcard as an exact surface that can never match.
        ActionProviderGrammar::POST_KIND_TRIGGER_FEATURE => [
            'since' => 3,
            'keys' => [],
        ],
        // A value-vocabulary extension inside actions[]: `phase` gains
        // schema_settle and that phase alone gains `prepares`. No top-level
        // key is claimed. ActionProviderGrammar owns the feature spelling and
        // validates the exact prepares/effects relationship.
        ActionProviderGrammar::SCHEMA_SETTLEMENT_FEATURE => [
            'since' => 3,
            'keys' => [],
        ],
        // WP-6.5 (spec/repo-format.md § v3.20), and the first row that does
        // BOTH of the two things the four above each did one of: it claims a
        // top-level key (`body_refs`) AND widens a value vocabulary inside a
        // section that already exists (`post_types.<type>.body` gains `json`).
        // That combination is the reason both halves are gated on ONE name
        // rather than two — a manifest could otherwise declare the paths
        // without the mode, or the mode without the paths, and each half alone
        // is a declaration that captures nothing and says nothing.
        //
        // `since: 3` for the same reason as every row above: `since` is the
        // first version whose grammar HAS the section, and this engine's does.
        // `regress_body_ref_grammar.php` asserts that 3 against the define in
        // the same run as its three § v3.2 verdicts, so the channel cannot
        // quietly become a bump.
        //
        // The name is read from BodyRefGrammar rather than spelled here because
        // Grammar is BELOW Adapter on tools/modules.json's ladder and the
        // body-mode gate — which lives down there, where the declaring manifest
        // is in hand — must be asking about the same string this row admits.
        //
        // ARM `field`: `body_refs` declares id-bearing PATHS inside a post
        // body — `{path, kind, cast}` triples that capture tokenises and apply
        // rebinds. That is the exact standing `block_attrs` has for a block
        // attribute, one container deeper, so it takes the same arm.
        // `post_types` stays the entity beneath it, unmoved.
        BodyRefGrammar::FEATURE => [
            'since' => 3,
            'keys' => [BodyRefGrammar::SECTION => 'field'],
        ],
        BodyRefGrammar::PRESERVED_TYPE_FEATURE => ['since' => 3, 'keys' => []],
        BodyRefGrammar::URL_FEATURE => ['since' => 3, 'keys' => []],
        BodyRefGrammar::PII_FEATURE => ['since' => 3, 'keys' => []],
    ];

    /**
     * The first accepted `spec_version` at which the top-level key set is
     * CLOSED (spec/repo-format.md § v3.3).
     *
     * Unknown v2 keys used to load as inert data. That made a transposed
     * section indistinguishable from an intentional no-op and could omit an
     * entire managed surface. Closing both accepted versions changes no
     * shipped manifest bytes or adapter digests: every shipped key is already
     * in the signer partition or claimed by an implemented engine feature.
     */
    private const CLOSED_KEY_SET_SINCE = 2;

    /** `_draft` is a known authoring sidecar, admitted only by the v2 authoring workflow. */
    private const DRAFT_SIDECAR_REFUSED_SINCE = 3;

    /**
     * The RESERVED top-level key: the executable adapter lane's attachment
     * point (spec/repo-format.md § v3.10, WP-4.11).
     *
     * Reserved means REFUSED BY NAME, never admitted-and-ignored. `package` is
     * in no arm of `AdapterCertification::topLevelKeyPartition()` and no
     * implemented `engine_features` value claims it, so a `spec_version: 3`
     * manifest declaring it is refused by `assert_top_level_keys()` either way
     * — with or without this constant. What the reservation buys is WHICH
     * refusal: "this engine does not recognise 'package', correct the spelling"
     * is false and sends the author to invent a feature name they may not mint
     * (§ v3.2), while the message below names the gate that decides. Nothing
     * about the verdict moves, which is the property WP-7.1's later opening
     * rests on: the lane opens as a policy flip proven by
     * `sandbox/tests/offline/adapter/regress_v3_reservations.php`, never as a
     * format break (§ v3.11 condition 7).
     */
    public const RESERVED_PACKAGE_KEY = 'package';

    /**
     * The `spec_version` integers this engine accepts: exactly N and N-1.
     *
     * The floor is N-1 and never deeper, so an N-2 manifest can never
     * accumulate by inattention; `tools/wire-surface.php` asserts that equality
     * under `make release-gate` by probing this validator rather than by
     * reading this line (spec/repo-format.md § v3.1).
     *
     * DELEGATED SINCE WP-4.12. `site.wprism.json` carries the same wire version
     * integer and `RepositoryCompiler::compile()` now judges it against the
     * same window, but `Repository` is layer 3 and this file is layer 5, so
     * the compiler cannot reference it. The definition moved down to
     * `SpecVersionWindow` (kernel), which both readers already depend on;
     * this method stays because every refusal in this file is written against
     * it and because the release gate probes THIS validator for the floor.
     *
     * @return list<int>
     */
    private static function accepted_window(int $supported): array {
        return SpecVersionWindow::accepted($supported);
    }

    /**
     * The window as it is printed in a refusal: `{2, 3}`.
     *
     * @param list<int> $accepted
     */
    private static function window_text(array $accepted): string {
        return SpecVersionWindow::text($accepted);
    }

    /**
     * The first `spec_version` at which the top-level key set is CLOSED.
     *
     * Public for schema/reporting code that describes when unknown keys stop
     * being accepted. Draft version selection probes the grammar directly;
     * `_draft` is a known authoring sidecar, not an unknown manifest section.
     */
    public static function closed_key_set_since(): int {
        return self::CLOSED_KEY_SET_SINCE;
    }

    /**
     * Every top-level manifest section this engine implements only at some
     * spec_version, mapped to the first version that has it.
     *
     * Derived from IMPLEMENTED_FEATURES so the two can never drift, and
     * ksorted so the refusal a multi-section manifest gets is the same one on
     * every run — refusal ORDER is observable contract here exactly as it is in
     * ManifestValidator::validate_manifest() (:40-42).
     *
     * @return array<string,int>
     */
    public static function section_min_spec(): array {
        $out = [];
        foreach (self::IMPLEMENTED_FEATURES as $row) {
            // array_keys(), because `keys` is a key => ARM map since WP-6.6 and
            // this question is about the SECTION only. The arm is read by
            // admitted_feature_key_arms() and by nothing else on the load path.
            foreach (array_keys($row['keys']) as $key) {
                $key = (string) $key;
                $out[$key] = isset($out[$key]) ? min($out[$key], $row['since']) : $row['since'];
            }
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * The engine feature names this engine implements, sorted.
     *
     * @return list<string>
     */
    public static function implemented_features(): array {
        $names = array_keys(self::IMPLEMENTED_FEATURES);
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * The same rows, whole: feature => `{since, keys}`, sorted by name.
     *
     * Published for `wprism manifest-validate --emit-schema` (WP-6.5), which used
     * to be unable to describe the channel at all — the four post-v3 SECTIONS
     * and the `engine_features` key itself appeared nowhere in the emitted
     * grammar document, so an author could not learn from the engine's own
     * answer that the features exist. This exposes no information the two
     * accessors above did not already publish between them (`implemented_
     * features()` the names, `section_min_spec()` the sections and their
     * versions); what it adds is the PAIRING, which is the half a consumer
     * needs and the half neither accessor alone can state.
     *
     * A copy, not the constant: the rows are engine-owned and a caller that
     * could mutate them would be a second vocabulary.
     *
     * WP-6.6 ADDS `sections`, and it is the half an author actually needs.
     * WP-6.5 published that the features and their sections EXIST; an author
     * who read that still had to open three engine files to learn what may go
     * inside one, and had no way at all to learn whether a certificate would
     * cover it. `sections` answers both, per claimed key: the `arm` the roster
     * classifies it into (§ v3.21) and the `grammar` the owning collaborator
     * publishes from its own constants. `keys` stays the flat list it was, so a
     * consumer that only wanted membership is unaffected.
     *
     * @return array<string,array{since:int,keys:list<string>,sections:array<string,array<string,mixed>>,value_constraint?:array<string,mixed>}>
     */
    public static function implemented_feature_rows(): array {
        $arms = self::feature_key_arms();
        $grammars = self::feature_section_grammars();
        $rows = [];
        foreach (self::implemented_features() as $name) {
            $keys = array_map('strval', array_keys(self::IMPLEMENTED_FEATURES[$name]['keys']));
            sort($keys, SORT_STRING);
            $sections = [];
            foreach ($keys as $key) {
                $sections[$key] = ['arm' => $arms[$key], 'grammar' => $grammars[$key]];
            }
            $rows[$name] = [
                'since' => self::IMPLEMENTED_FEATURES[$name]['since'],
                'keys' => $keys,
                'sections' => $sections,
            ];
            if ($name === ScalarReferenceIntersection::FEATURE) {
                $rows[$name]['value_constraint'] = ScalarReferenceIntersection::declaration_grammar();
            }
            if ($name === NativeValueValidation::FEATURE) {
                $rows[$name]['value_constraint'] = NativeValueValidation::declaration_grammar();
            }
            if ($name === PostMetaInvalidation::FEATURE) {
                $rows[$name]['value_constraint'] = PostMetaInvalidation::declaration_grammar();
            }
            if ($name === BlockValueGrammar::GROUP_FEATURE) {
                $rows[$name]['value_constraint'] = BlockValueGrammar::group_grammar();
            }
            if ($name === BlockValueGrammar::CONTRACT_FEATURE) {
                $rows[$name]['value_constraint'] = BlockValueGrammar::contract_grammar();
            }
            if ($name === RecordFields::FEATURE) {
                $rows[$name]['value_constraint'] = RecordFields::declaration_grammar();
            }
            if ($name === RecordFields::OBJECT_FEATURE) {
                $rows[$name]['value_constraint'] = RecordFields::object_declaration_grammar();
            }
            if ($name === ScalarValueConstraint::FEATURE) {
                $rows[$name]['value_constraint'] = ScalarValueConstraint::declaration_grammar();
            }
            if ($name === EncodedText::FEATURE) {
                $rows[$name]['value_constraint'] = EncodedText::declaration_grammar();
            }
            if ($name === KeyBoundStrings::FEATURE) {
                $rows[$name]['value_constraint'] = KeyBoundStrings::declaration_grammar();
            }
            if ($name === PhpContainerValue::FEATURE) {
                $rows[$name]['value_constraint'] = PhpContainerValue::declaration_grammar();
            }
            if ($name === TableRowScope::FEATURE) {
                $rows[$name]['value_constraint'] = TableRowScope::declaration_grammar();
            }
            if ($name === TableRowScope::SETS_FEATURE) {
                $rows[$name]['value_constraint'] = TableRowScope::sets_declaration_grammar();
            }
            if ($name === ColumnValueCases::FEATURE) {
                $rows[$name]['value_constraint'] = ColumnValueCases::declaration_grammar();
            }
            if ($name === ReferenceCondition::FEATURE) {
                $rows[$name]['value_constraint'] = ReferenceCondition::declaration_grammar();
            }
        }

        return $rows;
    }

    /**
     * Each feature-claimed top-level section's own VALUE grammar, published by
     * the collaborator that validates it (WP-6.6, § v3.21).
     *
     * ONE DEFINITION PER SECTION, and the completeness check below is what
     * makes that claim hold rather than merely be intended. Every entry is a
     * `section_grammar()` the owning class projects from the constants its own
     * refusals are written against — `BodyRefGrammar::RECORD_*`,
     * `AttrIdCodecGrammar::CODEC_KEYS`, `StructuredEvidence::EVIDENCE_KEYS`,
     * and so on — never a shape retyped here or in the emitter.
     *
     * The refusal at the end is the structural half: the published set must be
     * exactly the roster's claimed keys, so a feature that gains a key and
     * publishes no grammar for it refuses HERE, on the next `--emit-schema` and
     * under `make release-gate` (R-31), instead of shipping a document that has
     * gone quiet about a section authors are expected to write.
     *
     * `engine_features` is described by this class because this class validates
     * it (assert_engine_features()); the requires are lazy and local for
     * `admitted_top_level_keys()`'s stated reason — no manifest load path
     * reaches this method.
     *
     * @return array<string,array<string,mixed>> top-level key => grammar
     */
    public static function feature_section_grammars(): array {
        require_once __DIR__ . '/../Grammar/AttrIdCodecGrammar.php';
        require_once __DIR__ . '/../Grammar/ColumnCodecGrammar.php';
        $grammars = [
            BlockValueGrammar::SECTION => BlockValueGrammar::section_grammar(),
            BlockContentGrammar::SECTION => BlockContentGrammar::section_grammar(),
            BlockMediaDerivativeGrammar::SECTION => BlockMediaDerivativeGrammar::section_grammar(),
            AttrIdCodecGrammar::SECTION => AttrIdCodecGrammar::section_grammar(),
            BodyRefGrammar::SECTION => BodyRefGrammar::section_grammar(),
            ColumnCodecGrammar::SECTION => ColumnCodecGrammar::section_grammar(),
            self::PLUGIN_INCOMPATIBILITY_SECTION => self::plugin_incompatibility_section_grammar(),
            StructuredEvidence::SECTION => StructuredEvidence::section_grammar(),
            'engine_features' => [
                'shape' => 'a non-empty, sorted, duplicate-free LIST of engine feature name strings',
                'values' => self::implemented_features(),
                'refines' => 'nothing — declaring a name admits the top-level keys that feature claims '
                    . '(§ v3.3\'s growth rule) and gates the value vocabularies it widens; a name this engine '
                    . 'does not implement refuses the adapter BY FEATURE NAME',
                'validated_by' => 'WPrism\\AdapterContractGrammar::assert_engine_features()',
            ],
        ];
        ksort($grammars, SORT_STRING);
        $described = array_keys($grammars);
        $claimed = array_keys(self::feature_key_arms());
        if ($described !== $claimed) {
            $missing = array_values(array_diff($claimed, $described));
            $extra = array_values(array_diff($described, $claimed));
            throw new \RuntimeException(
                'wprism: the published feature-section grammars do not match the roster — claimed but undescribed {'
                . implode(', ', $missing) . '}, described but unclaimed {' . implode(', ', $extra)
                . '}. A feature that claims a top-level key publishes that section\'s value grammar in the same '
                . 'change (spec/repo-format.md § v3.21)'
            );
        }

        return $grammars;
    }

    /**
     * Every top-level key a `spec_version: 3` manifest may declare.
     *
     * ONE definition, read from the signer rather than restated:
     * `AdapterCertification::topLevelKeyPartition()` (WP-4.1) is the base set,
     * and the manifest's own declared, implemented features add the keys they
     * claim (§ v3.2's growth rule). The alternative — a list typed here —
     * equals the partition on the day it is typed and stops equalling it on the
     * day the engine moves, which is the day nobody is looking; `php
     * tools/wire-surface.php --check` asserts the equality under `make
     * release-gate` (register row R-21) so that it cannot be reintroduced.
     *
     * The require is lazy, and deliberately: `AdapterCertification` is one of
     * the four names `agent/wprism.php`'s bootstrap does NOT declare, and it is
     * require_once'd at each use site instead (agent/wprism.php:124-128). A v2
     * manifest never reaches this method, so the open v2 era loads exactly the
     * files it loads today; the require is here rather than at the top of the
     * file for that reason and no other.
     *
     * @param array<string,mixed> $manifest
     * @return list<string>
     */
    public static function admitted_top_level_keys(array $manifest): array {
        require_once __DIR__ . '/AdapterCertification.php';
        $partition = AdapterCertification::topLevelKeyPartition();
        $admitted = array_merge(
            $partition['entity_sections'],
            $partition['field_sections'],
            $partition['non_surface_keys'],
            self::admitted_feature_keys($manifest)
        );
        $admitted = array_values(array_unique($admitted));
        sort($admitted, SORT_STRING);

        return $admitted;
    }

    /**
     * The top-level sections a manifest's DECLARED features admit.
     *
     * The other half of § v3.2's channel, and the seam § v3.3's closed key set
     * (WP-4.3) attaches to: a key claimed by a declared feature the engine
     * IMPLEMENTS is admitted. A key claimed by a feature this engine does not
     * have never reaches here — validate_adapter_contract() has already refused
     * that manifest by feature name — so this answers only for a manifest the
     * contract grammar accepted.
     *
     * @param array<string,mixed> $manifest
     * @return list<string>
     */
    public static function admitted_feature_keys(array $manifest): array {
        $keys = [];
        foreach ((array) ($manifest['engine_features'] ?? []) as $feature) {
            if (!is_string($feature) || !isset(self::IMPLEMENTED_FEATURES[$feature])) {
                continue;
            }
            foreach (array_keys(self::IMPLEMENTED_FEATURES[$feature]['keys']) as $key) {
                $keys[(string) $key] = true;
            }
        }
        $out = array_keys($keys);
        sort($out, SORT_STRING);

        return $out;
    }

    /**
     * The same keys, each with the CERTIFICATE ARM its feature classifies it
     * into — for the manifest that declared the feature, and no other
     * (WP-6.6, spec/repo-format.md § v3.21).
     *
     * THE ONE CONSUMER IS THE SIGNER, and the question it asks is narrower than
     * `admitted_feature_keys()`'s. That method answers "may this key exist";
     * this one answers "what does a certificate say about it", which is a
     * question only for a key this manifest actually brought through the
     * channel. So the roster is filtered by THIS manifest's declarations rather
     * than published whole: `body_refs` sitting in a manifest that never
     * declared `structured-body-refs/v1` is a section the engine reads nothing
     * from, and classifying it anyway would put uncaptured state in a
     * certificate's surface list. Such a manifest keeps the unclassifiable-key
     * refusal it has today, which is the correct verdict for it.
     *
     * Every value is checked on the way out (assert_arm()), so a roster row
     * with a typo'd arm cannot reach a signature: it refuses at the roster.
     *
     * @param array<string,mixed> $manifest
     * @return array<string,string> top-level key => arm
     */
    public static function admitted_feature_key_arms(array $manifest): array {
        $arms = [];
        foreach ((array) ($manifest['engine_features'] ?? []) as $feature) {
            if (!is_string($feature) || !isset(self::IMPLEMENTED_FEATURES[$feature])) {
                continue;
            }
            foreach (self::IMPLEMENTED_FEATURES[$feature]['keys'] as $key => $arm) {
                $arms[(string) $key] = self::assert_arm($feature, (string) $key, $arm);
            }
        }
        ksort($arms, SORT_STRING);

        return $arms;
    }

    /**
     * The WHOLE roster's classification: every feature-admitted top-level key
     * this engine implements, mapped to its arm.
     *
     * Published rather than derived per manifest because two readers need the
     * engine-wide answer and neither has a manifest in hand: `wprism
     * manifest-validate --emit-schema` prints the arm beside each roster row so
     * an author can see, before writing a line, whether the section they are
     * about to declare will be covered by a certificate; and
     * `tools/wire-surface.php --check` asserts under `make release-gate` that
     * every claimed key has an arm and that no claimed key collides with the
     * signer's own partition (register row R-31).
     *
     * @return array<string,string> top-level key => arm, key order
     */
    public static function feature_key_arms(): array {
        $arms = [];
        foreach (self::IMPLEMENTED_FEATURES as $feature => $row) {
            foreach ($row['keys'] as $key => $arm) {
                $arms[(string) $key] = self::assert_arm((string) $feature, (string) $key, $arm);
            }
        }
        ksort($arms, SORT_STRING);

        return $arms;
    }

    /**
     * The roster's own self-check, and the reason property (a) holds by
     * construction rather than by review.
     *
     * TWO REFUSALS, and they close the two ways a roster row could be a second
     * spelling of the partition instead of the one definition beside it:
     *
     *   - an arm outside `AdapterCertification::certificateArms()` is a value
     *     `siteSurfaceSections()` would silently treat as "not entity, not
     *     field, not non-surface" — i.e. it would fall through to the
     *     unclassifiable refusal and report the AUTHOR's manifest for the
     *     ENGINE's typo. Refusing at the roster names the feature and the key;
     *   - a key the signer's partition ALREADY carries would give one key two
     *     arms whose winner depends on which `in_array()` runs first
     *     (siteSurfaceSections() asks the constants before the roster, so the
     *     roster row would be dead code that reads as a decision).
     *
     * The require is lazy for `admitted_top_level_keys()`'s stated reason:
     * `AdapterCertification` is one of the four names agent/wprism.php's bootstrap
     * deliberately does not declare (agent/wprism.php:124-128), and no v2 manifest
     * reaches this method.
     */
    private static function assert_arm(string $feature, string $key, mixed $arm): string {
        require_once __DIR__ . '/AdapterCertification.php';
        $vocabulary = AdapterCertification::certificateArms();
        if (!is_string($arm) || !in_array($arm, $vocabulary, true)) {
            throw new \RuntimeException(
                "wprism: engine feature '$feature' classifies its top-level key '$key' as "
                . var_export($arm, true) . ", which is not one of the signer's certificate arms ("
                . implode(', ', $vocabulary) . ') — a feature that claims a top-level key must say what a '
                . 'certificate covers it as, in the same row that claims it (spec/repo-format.md § v3.21)'
            );
        }
        $partition = AdapterCertification::topLevelKeyPartition();
        $classified = array_merge(
            $partition['entity_sections'],
            $partition['field_sections'],
            $partition['non_surface_keys']
        );
        if (in_array($key, $classified, true)) {
            throw new \RuntimeException(
                "wprism: engine feature '$feature' classifies '$key', which the signer's own three-arm partition "
                . 'already carries — a roster row classifies only the keys § v3.2\'s channel ADDS, so the two '
                . 'can never be two spellings of one arm (spec/repo-format.md § v3.21)'
            );
        }

        return $arm;
    }

    /**
     * Validate one manifest's adapter compatibility contract.
     */
    public static function validate_adapter_contract(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        $spec = $manifest['spec_version'] ?? null;
        $supported = defined('WPRISM_SPEC_VERSION') ? WPRISM_SPEC_VERSION : 0;
        // Absent or non-integer keeps issue #3247's refusal byte for byte, and
        // deliberately so: it is not a version, so there is no window for it to
        // be inside and nothing about the window is worth printing at it
        // (spec/repo-format.md § v3.1).
        if (!is_int($spec)) {
            $declared = $spec === null ? 'no spec_version' : ('spec_version ' . var_export($spec, true));
            throw new \RuntimeException(
                "wprism: manifest '$name' declares $declared"
                . " but this engine requires spec_version $supported — pin a compatible manifest or update it"
            );
        }
        $accepted = self::accepted_window($supported);
        if (!in_array($spec, $accepted, true)) {
            throw new \RuntimeException(
                "wprism: manifest '$name' declares spec_version " . var_export($spec, true)
                . ' but this engine accepts spec_version ' . self::window_text($accepted)
                . " — the acceptance window is exactly N and N-1, where N is this engine's WPRISM_SPEC_VERSION"
                . ' (spec/repo-format.md § v3.1) — pin a compatible manifest or update it'
            );
        }
        self::assert_section_versions($name, $spec, $manifest, $accepted);
        self::assert_engine_features($name, $manifest);
        // AFTER the feature channel and BEFORE every value check below, and
        // both halves of that placement are the contract. After, because §
        // v3.3's three verdicts must stay distinct: a key claimed by a feature
        // this engine LACKS has to refuse by FEATURE name (assert_engine_
        // features(), one line up), never as a typo naming the key. Before,
        // because whether a key EXISTS is a different question from whether its
        // value is well-formed — an author who misspelled a section should be
        // told that, not handed a refusal about the contents of a section the
        // engine does not have.
        self::assert_top_level_keys($name, $spec, $manifest);
        // WP-6.4, and FIRST among the value checks because it is the one that
        // can only be reached by walking the whole channel: the section exists
        // for this engine (assert_section_versions()), the feature that claims
        // it was declared and is implemented (assert_engine_features()), and
        // the closed key set admitted the key on that basis
        // (assert_top_level_keys()). Anything wrong before this line is a
        // question about whether the section EXISTS; from here on it is a
        // question about what is inside it, and those are not the same refusal.
        StructuredEvidence::assert_section($name, $manifest);
        // Validate the interpreter name at load rather than waiting for the
        // lazy interpreters() lookup to hand a non-string to preg_match().
        if (array_key_exists('interpreter', $manifest) && $manifest['interpreter'] !== null) {
            $interpreter = $manifest['interpreter'];
            if (!is_string($interpreter) || preg_match('/^[a-z][a-z0-9_-]*$/D', $interpreter) !== 1) {
                throw new \RuntimeException(
                    "wprism: manifest '$name' declares interpreter " . var_export($interpreter, true)
                    . ' — an interpreter name must be a non-empty string matching ^[a-z][a-z0-9_-]*$, since it resolves '
                    . 'to <manifests_dir>/interpreters/<name>.php'
                );
            }
        }
        foreach ([['plugin', 'version_range'], ['theme', 'theme_version_range']] as [$idKey, $rangeKey]) {
            $id = $manifest[$idKey] ?? null;
            if ($id === null) {
                continue;
            }
            if (!is_string($id) || $id === '') {
                throw new \RuntimeException("wprism: manifest '$name' declares a non-string or empty '$idKey'");
            }
            if ($idKey === 'plugin') {
                AdapterSources::assert_plugin_basename($id, "manifest '$name' declares 'plugin'");
            }
            // The identifier is later concatenated into filesystem paths by
            // code-version consumers, so reject traversing or absolute theme
            // identities before any consumer sees them.
            $segments = explode('/', $id);
            $depthOk = $idKey === 'plugin' ? count($segments) <= 2 : count($segments) === 1;
            if ($idKey !== 'plugin' && (!$depthOk || $id[0] === '/' || str_contains($id, '\\')
                || in_array('..', $segments, true) || in_array('.', $segments, true)
                || in_array('', $segments, true))) {
                throw new \RuntimeException(
                    "wprism: manifest '$name' declares '$idKey' " . var_export($id, true)
                    . ' — a ' . $idKey . ' identifier is '
                    . ($idKey === 'plugin' ? "'<directory>/<file>.php' or '<file>.php'" : 'a bare directory slug')
                    . ', never an absolute path and never one containing a ".." segment; it is concatenated into '
                    . 'filesystem paths by the code-half version checks'
                );
            }
            $range = $manifest[$rangeKey] ?? null;
            if (!is_array($range)) {
                throw new \RuntimeException(
                    "wprism: manifest '$name' declares '$idKey' ('$id') but no '$rangeKey' — an adapter naming a "
                    . "$idKey with no exact version range is unbounded support, which this project's contract "
                    . 'forbids (issue #3222). Declare {"min":..,"max":..} or drop the ' . "$idKey claim."
                );
            }
            Policy::assert_min_max_range($range, "manifest '$name' declares '$rangeKey'");
        }
        self::assert_plugin_incompatibilities($manifest, $name);
    }

    /** The author-facing grammar emitted by `wprism manifest-validate --emit-schema`. */
    private static function plugin_incompatibility_section_grammar(): array {
        return [
            'shape' => 'a non-empty, sorted, duplicate-free LIST of exact plugin basenames',
            'item' => "'<directory>/<main-file>.php' or '<main-file>.php'",
            'refines' => 'the declaring plugin adapter\'s admissible co-installation boundary',
            'validated_by' => self::class . '::assert_plugin_incompatibilities()',
        ];
    }

    /** @param array<string,mixed> $manifest */
    private static function assert_plugin_incompatibilities(array $manifest, string $name): void {
        if (!array_key_exists(self::PLUGIN_INCOMPATIBILITY_SECTION, $manifest)) {
            return;
        }
        $plugin = $manifest['plugin'] ?? null;
        $incompatible = $manifest[self::PLUGIN_INCOMPATIBILITY_SECTION];
        if (!is_string($plugin) || $plugin === '') {
            throw new \RuntimeException(
                "wprism: manifest '$name' declares '" . self::PLUGIN_INCOMPATIBILITY_SECTION
                . "' without owning a plugin — only a plugin adapter can declare which other plugin cannot "
                . 'safely share its policy'
            );
        }
        $wellShaped = is_array($incompatible) && $incompatible !== [] && array_is_list($incompatible);
        if ($wellShaped) {
            foreach ($incompatible as $other) {
                if (!is_string($other) || $other === '') {
                    $wellShaped = false;
                    break;
                }
            }
        }
        if ($wellShaped) {
            $canonical = array_values(array_unique($incompatible));
            sort($canonical, SORT_STRING);
            $wellShaped = $canonical === $incompatible;
        }
        if (!$wellShaped) {
            throw new \RuntimeException(
                "wprism: manifest '$name' " . self::PLUGIN_INCOMPATIBILITY_SECTION
                . ' must be a non-empty, sorted, duplicate-free list of exact plugin basenames'
            );
        }
        foreach ($incompatible as $other) {
            AdapterSources::assert_plugin_basename(
                $other,
                "manifest '$name' " . self::PLUGIN_INCOMPATIBILITY_SECTION . ' entry'
            );
            if (hash_equals($plugin, $other)) {
                throw new \RuntimeException(
                    "wprism: manifest '$name' declares its own plugin '$plugin' incompatible with itself"
                );
            }
        }
    }

    /**
     * A manifest inside the window may not use a section from a HIGHER version.
     *
     * This is the refusal that makes the window a staging channel rather than
     * a tolerance. The alternative — ignoring a section the declared version
     * does not have — is the failure "Vocabulary ownership and extension"
     * already states for values: an unrecognised declaration that means nothing
     * is indistinguishable from a deliberate one, which is how a transposed
     * letter drops a plugin's authored rows out of canonical state with no
     * diagnostic anywhere.
     *
     * The refusal names the SECTION and the ADAPTER, never just "this engine
     * requires spec_version N", because those are the two facts an operator
     * needs to decide whether to edit a manifest or move an engine. Its blast
     * radius is the blast radius the SOURCE already grants: `wprism
     * manifest-validate` loads every manifest on its own and prints a verdict
     * per manifest, AdapterSources::grammar_verdict() judges one adapter at a
     * time for the survey, and the plugin source records a per-adapter refusal
     * row (AdapterSources.php:2145, SCOPE_ADAPTER). A PINNED adapter still
     * refuses the load, exactly as every other manifest grammar refusal does —
     * dropping a pinned adapter silently would BE the state-loss this rule
     * exists to prevent.
     *
     * @param array<string,mixed> $manifest
     * @param list<int> $accepted
     */
    private static function assert_section_versions(
        string $name,
        int $spec,
        array $manifest,
        array $accepted
    ): void {
        foreach (self::section_min_spec() as $section => $since) {
            if ($spec >= $since || !array_key_exists($section, $manifest)) {
                continue;
            }
            // Two eras, two honest remedies. While the section's version is
            // itself inside the window an author can simply declare it; while
            // it is not, saying "declare spec_version $since" would send them
            // to a manifest this same validator refuses wholesale one line
            // above. WP-4.12 moved this engine from the second era into the
            // first: at WPRISM_SPEC_VERSION 2 the only implemented section
            // (`engine_features`, since 3) sat one past the ceiling and BOTH
            // in-window versions refused it; the flip put 3 inside the window,
            // so a v2 manifest now gets the actionable remedy and a v3 one is
            // simply admitted. Both arms stay, because the next section
            // declared at a version this engine does not reach re-enters the
            // second era on the day it is added.
            $remedy = in_array($since, $accepted, true)
                ? "declare spec_version $since to use it, or remove the section"
                : "this engine's window does not reach spec_version $since, so remove the section or run an "
                    . 'engine whose window does';
            throw new \RuntimeException(
                "wprism: manifest '$name' declares spec_version $spec and the section '$section', which this "
                . "engine implements only at spec_version $since — a manifest inside the acceptance window "
                . self::window_text($accepted) . ' may not declare a section from a HIGHER version '
                . '(spec/repo-format.md § v3.1). Remedy: ' . $remedy
            );
        }
    }

    /**
     * The `engine_features` declaration channel (spec/repo-format.md § v3.2).
     *
     * An engine that implements every listed feature loads the adapter; one
     * that lacks a listed feature refuses THAT ADAPTER, naming the feature. The
     * point is that a post-v3 primitive ships as a feature name, a manifest key
     * the feature claims, and a refusal for the engine that does not have it —
     * so an older engine meeting a manifest that uses the primitive says so by
     * name instead of mis-reading the declaration, and no version integer moves
     * anywhere. A name nothing implements is refused as unimplemented rather
     * than admitted as forward-looking; that is what distinguishes this channel
     * from `authored_typed_snapshot_post_v1`, the declared-but-not-implemented
     * marker whose one honest property is that it captures nothing and says so.
     *
     * Shape is checked before vocabulary, and strictly: an unreadable
     * declaration cannot be compared against the vocabulary at all, and
     * admitting it would reintroduce the silence this channel replaces.
     *
     * @param array<string,mixed> $manifest
     */
    private static function assert_engine_features(string $name, array $manifest): void {
        if (!array_key_exists('engine_features', $manifest)) {
            return;
        }
        $declared = $manifest['engine_features'];
        $wellShaped = is_array($declared) && $declared !== [] && array_is_list($declared);
        if ($wellShaped) {
            foreach ($declared as $feature) {
                if (!is_string($feature) || $feature === '') {
                    $wellShaped = false;
                    break;
                }
            }
        }
        if ($wellShaped) {
            /** @var list<string> $declared */
            $canonical = array_values(array_unique($declared));
            sort($canonical, SORT_STRING);
            $wellShaped = $canonical === $declared;
        }
        if (!$wellShaped) {
            // Rendered as JSON, not var_export: the declaration arrived as
            // JSON, and var_export of an array is multi-line — a refusal that
            // spans lines is unreadable in a WP-CLI error and unmatchable by
            // the harnesses that pin these strings.
            $shown = json_encode($declared, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            throw new \RuntimeException(
                "wprism: manifest '$name' declares 'engine_features' "
                . (is_string($shown) ? $shown : var_export($declared, true))
                . ' — engine_features is a non-empty, sorted, duplicate-free list of engine feature name '
                . 'strings (spec/repo-format.md § v3.2)'
            );
        }
        $implemented = self::implemented_features();
        foreach ($declared as $feature) {
            if (in_array($feature, $implemented, true)) {
                continue;
            }
            throw new \RuntimeException(
                "wprism: manifest '$name' declares engine feature " . var_export($feature, true)
                . ' — this engine does not implement it. Feature names are engine-owned: an adapter declares '
                . 'one, never mints one (spec/repo-format.md § v3.2). This engine implements: '
                . implode(', ', $implemented)
                . '. Remedy: drop the declaration, or run an engine that has the feature'
            );
        }
    }

    /**
     * The closed top-level key set (spec/repo-format.md § v3.3, WP-4.3).
     *
     * WHAT THIS REPLACES. The set already existed, was already maintained and
     * already refused — but only in `AdapterCertification::siteRatification()`,
     * which most authors reach long after the typo. So a manifest carrying
     * `totally_made_up_section` and a transposed `optoins` validated `[ok]` and
     * was then unsignable, measured both ways in
     * `sandbox/tests/offline/policy/regress_spec_v3_dry_run.php` under rule
     * V3-KEYS. That silence is the failure ManifestGrammar.php:50-56 already
     * states one level down for a table `class` value: an unrecognised
     * declaration that means nothing is indistinguishable from a deliberate
     * one, which is how a whole plugin's authored rows go missing from
     * canonical state because of one transposed letter.
     *
     * WHY IT IS SAFE TO CLOSE. Because the set can GROW without a flag day. A
     * closed set that cannot grow is simply the next flag day deferred, so §
     * v3.2's channel is the growth rule: a key claimed by a declared feature
     * this engine IMPLEMENTS is admitted (`admitted_top_level_keys()`), a key
     * claimed by a declared feature it does NOT implement has already refused
     * by feature name, and a key nothing claims refuses here. Three verdicts,
     * no fourth — and in particular no "unknown keys are ignored", which is the
     * behaviour v3 removes.
     *
     * WHY THE REFUSAL NAMES EVERY OFFENDING KEY. `sort()` and then all of them,
     * not the first: an author who transposed one letter in two sections would
     * otherwise pay two round trips to learn two facts the engine knew at once,
     * and a refusal whose content depends on PHP's key order is not a refusal a
     * harness can pin.
     *
     * @param array<string,mixed> $manifest
     */
    private static function assert_top_level_keys(string $name, int $spec, array $manifest): void {
        $admitted = self::admitted_top_level_keys($manifest);
        $unknown = array_values(array_diff(array_map('strval', array_keys($manifest)), $admitted));
        // `wprism adapter-draft` emits one recognised authoring sidecar at v2.
        // It remains unsignable and is refused from v3; no arbitrary key gets
        // this exception, so misspelled or invented v2 sections fail closed.
        if ($spec < self::DRAFT_SIDECAR_REFUSED_SINCE) {
            $unknown = array_values(array_diff($unknown, ['_draft']));
        }
        if ($unknown === []) {
            return;
        }
        sort($unknown, SORT_STRING);

        // `_draft` gets its own sentence, and it wins over every other unknown
        // key, because it says something about the whole document rather than
        // about one section: this is `wprism adapter-draft` output
        // (cli/src/Adapter/AdapterDraft.php:379), and the first thing its author
        // has to do is strip the sidecar and re-validate — at which point any
        // remaining unrecognised key is reported with the remedy that fits it.
        // Its own remedy is the opposite of the general one: nothing is
        // misspelled and nothing is missing an engine feature, and telling that
        // author to "declare the feature that claims it" would send them to
        // invent a feature name they may not mint (§ v3.2). Admitting it is
        // what § v3.3 refuses on the merits: the key would land inside the
        // identity row every certificate covers
        // (ArtifactPolicyIdentity::manifest_rows()), putting unreviewed
        // proposals under a signature.
        if (in_array('_draft', $unknown, true)) {
            throw new \RuntimeException(
                "wprism: manifest '$name' declares spec_version $spec and the top-level key '_draft' — that is the "
                . 'proposal sidecar `wprism adapter-draft` writes for a human reviewer, and it is an authoring '
                . 'artifact rather than a declaration: admitting it would put unreviewed proposals inside the '
                . 'identity row every certificate covers (spec/repo-format.md § v3.3). Remedy: strip the `_draft` '
                . 'key before install — `wprism manifest-validate` reports the sidecar\'s facts, proposals and '
                . 'unsupported counts on every run, so nothing in it is lost by removing it'
            );
        }

        // The exec lane's reserved slot (§ v3.10, WP-4.11), placed AFTER
        // `_draft` for the reason that case gives — a draft sidecar is a fact
        // about the whole document and has to be stripped before anything else
        // in it is worth reading — and BEFORE the general refusal because the
        // general refusal would be a lie: `package` is not a misspelling and
        // there is no `engine_features` value an author may declare to admit
        // it. The gate, not the spelling, is what decides, and the message says
        // so. The verdict is unchanged in both directions: refused before this
        // rider, refused after it, same exception, same load failure.
        if (in_array(self::RESERVED_PACKAGE_KEY, $unknown, true)) {
            throw new \RuntimeException(
                // The pinned phrase is written CONTIGUOUSLY, never split across
                // a concatenation, so `grep` and the document suite find the
                // spec's own sentence in the shipped bytes.
                "wprism: manifest '$name' declares '" . self::RESERVED_PACKAGE_KEY
                . "' — the executable adapter lane is reserved and shut."
                . ' It opens only at gate G5 (spec/repo-format.md § v3.11), never by declaring the key'
            );
        }

        throw new \RuntimeException(
            "wprism: manifest '$name' declares spec_version $spec and the top-level "
            . (count($unknown) === 1 ? 'key ' : 'keys ')
            . implode(', ', array_map(static fn(string $k): string => "'" . $k . "'", $unknown))
            . ', which this engine does not recognise — at spec_version ' . self::CLOSED_KEY_SET_SINCE
            . ' the top-level key set is CLOSED, so an unrecognised section is a misspelling rather than an inert '
            . 'marker (spec/repo-format.md § v3.3). Remedy: correct the spelling, remove the section, or declare '
            . 'the `engine_features` value that claims it (§ v3.2) — this engine implements: '
            . implode(', ', self::implemented_features())
        );
    }

    /**
     * Refuse a pinned plugin pair an adapter has declared non-composable.
     * Sorting the complete conflict set before choosing a verdict keeps the
     * refusal byte-identical under both pin orders; no adapter wins by loading
     * first, and no lifecycle or provider process can observe the bad policy.
     *
     * @param list<array<string,mixed>> $manifests
     */
    public static function validate_no_incompatible_plugins(array $manifests): void {
        $claimants = [];
        foreach ($manifests as $manifest) {
            $plugin = $manifest['plugin'] ?? null;
            if (!is_string($plugin) || $plugin === '') {
                continue;
            }
            $claimants[$plugin][] = (string) ($manifest['name'] ?? '?');
        }
        foreach ($claimants as &$names) {
            $names = array_values(array_unique($names));
            sort($names, SORT_STRING);
        }
        unset($names);

        $conflicts = [];
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '?');
            $plugin = $manifest['plugin'] ?? null;
            if (!is_string($plugin) || $plugin === '') {
                continue;
            }
            foreach ((array) ($manifest[self::PLUGIN_INCOMPATIBILITY_SECTION] ?? []) as $other) {
                if (!is_string($other) || !isset($claimants[$other])) {
                    continue;
                }
                $conflicts[] = [
                    'claimants' => $claimants[$other],
                    'manifest' => $name,
                    'other' => $other,
                    'plugin' => $plugin,
                ];
            }
        }
        if ($conflicts === []) {
            return;
        }
        usort($conflicts, static fn(array $a, array $b): int => [
            $a['manifest'], $a['plugin'], $a['other'], $a['claimants'],
        ] <=> [
            $b['manifest'], $b['plugin'], $b['other'], $b['claimants'],
        ]);
        $conflict = $conflicts[0];
        throw new \RuntimeException(
            "wprism: manifest '{$conflict['manifest']}' for plugin '{$conflict['plugin']}' declares plugin "
            . "'{$conflict['other']}' incompatible, and pinned manifest(s) {"
            . implode(', ', array_map(static fn(string $n): string => "'$n'", $conflict['claimants']))
            . '} claim that plugin — incompatible plugin adapters cannot share one policy; pin only one'
        );
    }

    /**
     * Reject load-order-dependent ownership when two pinned manifests name
     * the same plugin or theme with different compatibility ranges. Identical
     * ranges remain redundant but deterministic and are intentionally allowed.
     *
     * WP-5.5 adds the one way out, and it is the operator's rather than an
     * adapter's: `site.wprism.json` `policy.adapter_claims` names WHICH claimant
     * is in force for that plugin or theme (spec/repo-format.md § v3.13), and
     * a collision so resolved is not ambiguous any more — pin order decides
     * nothing, a written decision does. The displaced claimant is reported by
     * `Policy::displaced_adapter_claims()`, never hidden, and its manifest
     * stays pinned and loaded: this resolves a CLAIM, it does not unload an
     * adapter or merge two claims into one.
     *
     * Everything about the unresolved case is unchanged, deliberately and
     * byte for byte: `$resolutions` empty is every repository that existed
     * before this section, and the message below is the one they have always
     * received. That is what makes the resolution an opt-in operator decision
     * instead of a relaxation — an undeclared conflict still refuses.
     *
     * The refusal text keeps its "conflicting ownership with no v2 composition
     * rule" sentence rather than advertising the new section, because it is
     * still true and still the right advice: composition does not exist, at v2
     * or at v3. The resolution is not composition and does not become the
     * first remedy an operator reaches for — pinning one, or narrowing a range
     * to a disjoint window, remains a better answer whenever it is available.
     *
     * @param list<array<string,mixed>> $manifests
     * @param array<string,array<string,array{in_force:string,note:?string}>> $resolutions AdapterClaimResolutions::declared()
     */
    public static function validate_no_conflicting_adapter_claims(array $manifests, array $resolutions): void {
        foreach (AdapterClaimResolutions::CLAIM_ARMS as $idKey => $rangeKey) {
            $seen = [];
            foreach ($manifests as $m) {
                $id = $m[$idKey] ?? null;
                if (!is_string($id) || $id === '') {
                    continue;
                }
                $range = $m[$rangeKey] ?? [];
                $name = (string) ($m['name'] ?? '?');
                if (isset($seen[$id])) {
                    $prev = $seen[$id];
                    if ($prev['range'] != $range
                        && !AdapterClaimResolutions::resolves($resolutions, $idKey, $id)
                    ) {
                        throw new \RuntimeException(
                            "wprism: manifests '{$prev['name']}' and '$name' both declare $idKey '$id' with "
                            . "different $rangeKey values (" . json_encode($prev['range']) . ' vs '
                            . json_encode($range) . ') — conflicting ownership with no v2 composition rule; '
                            . 'pin only one, or narrow one range to a disjoint window'
                        );
                    }
                    continue;
                }
                $seen[$id] = ['name' => $name, 'range' => $range];
            }
        }
    }
}

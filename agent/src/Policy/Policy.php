<?php
namespace WPrism;

// Manifest validation is a pure offline pass with several entry points of
// its own (the frozen-snapshot path, the offline harnesses that load this
// file directly). The native-action vocabulary is part of that pass, so it
// is required here rather than left to wprism.php's bootstrap order — same
// precedent as Deploy.php requiring CodeCompatibility.php.
require_once __DIR__ . '/../Rebuild/NativeActions.php';
// issue #3314: adapter provenance is decided inside the same offline pass, before
// any manifest reaches a policy consumer, so it is required here for the same
// reason NativeActions is.
require_once __DIR__ . '/../Adapter/AdapterSources.php';
require_once __DIR__ . '/../Kernel/ManifestExecutableLoader.php';
// Shipped executable paths belong to the adapter package that declares them.
// Required here because this file is also loaded directly by offline policy
// validators that never pass through agent/wprism.php.
require_once __DIR__ . '/AdapterLibrary.php';
require_once __DIR__ . '/AdapterPackage.php';
require_once __DIR__ . '/../Kernel/ReferenceRules.php';
require_once __DIR__ . '/../Kernel/ScalarReferenceIntersection.php';
require_once __DIR__ . '/../Kernel/PlainData.php';
require_once __DIR__ . '/../Kernel/ActionTriggerMatcher.php';
// issue #3348 first extraction slice: the pure table/widget declaration grammar,
// required here for the same "loads alone" reason as its neighbors above.
require_once __DIR__ . '/ManifestGrammar.php';
// issue #3348 slice 4: adapter provenance / capability-readiness resolution,
// required here for the same "loads alone" reason as its neighbors above.
require_once __DIR__ . '/../Adapter/AdapterRegistry.php';
// Runtime platform compatibility is a pre-policy gate: direct `wp wprism`
// mutations must not be able to bypass the host-side doctor boundary.
require_once __DIR__ . '/PlatformCompatibility.php';
// The topology gate assert_single_site() delegates to. Required here for the
// same "loads alone" reason as its neighbors: the offline policy harnesses
// include this file directly, never agent/wprism.php's bootstrap.
require_once __DIR__ . '/../Kernel/SiteTopology.php';
// The typed refusal the missing-site.wprism.json gates below throw. SiteTopology
// loads it too, but the direct-require contract
// (sandbox/tests/offline/guards/regress_agent_src_requires.php) is that every
// engine class a file NAMES is loaded by that file, not by a neighbour.
require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Kernel/CheckpointRecoveryIntent.php';
require_once __DIR__ . '/../Kernel/ProviderSettlementIntent.php';
// issue #3348 slice 5: manifest-pin normalization/validation, required here for
// the same "loads alone" reason as its neighbors above.
require_once __DIR__ . '/PinResolver.php';
// issue #3348 slice 6: action/provider/effect grammar validation, required here
// for the same "loads alone" reason as its neighbors above.
require_once __DIR__ . '/../Adapter/ActionProviderGrammar.php';
// issue #3348 slice 7: cross-manifest "one owner, no contradiction" guards,
// required here for the same "loads alone" reason as its neighbors above.
require_once __DIR__ . '/CrossManifestGuards.php';
// issue #3348 slice 8: the "named sub-key of an otherwise-atomic value"
// declaration grammar, required here for the same "loads alone" reason as
// its neighbors above.
require_once __DIR__ . '/../Grammar/SubKeyGrammar.php';
// issue #3348 slice 9: exact and pattern taxonomy object_keyspace declaration
// grammar, required here for the same "loads alone" reason as its neighbors.
require_once __DIR__ . '/../Grammar/TaxonomyGrammar.php';
// issue #3348 slice 11: option-name reference declaration grammar and its
// cross-manifest identical-pattern guard, required here for the same
// "loads alone" reason as its neighbors.
require_once __DIR__ . '/../Grammar/OptionReferenceGrammar.php';
// issue #3348 slice 12: the closed post-type body/phase declaration grammar,
// required here for the same "loads alone" reason as its neighbors.
require_once __DIR__ . '/../Grammar/PostTypeGrammar.php';
// issue #3348 slice 13: the pure option-namespace/authored-meta discovery
// grammar, required here for the same "loads alone" reason as its neighbors.
require_once __DIR__ . '/DiscoveryGrammar.php';
// issue #3348 slice 16: option declaration/storage grammar, required here for
// the same "loads alone" reason as its neighbors.
require_once __DIR__ . '/../Grammar/OptionGrammar.php';
// issue #3348 slice 17: block/shortcode attribute declaration grammar, required
// here for the same "loads alone" reason as its neighbors.
require_once __DIR__ . '/../Grammar/AttributeGrammar.php';
// issue #3348 slice 50: block/shortcode structural registry projection is pure
// manifest work; Policy retains the public compatibility accessors below.
require_once __DIR__ . '/../Grammar/ContentAttributeRuleResolver.php';
// WP-6.1's two `engine_features`-staged codec sections. Required here for the
// same "loads alone" reason as AttributeGrammar above: closed_vocabularies()
// publishes their vocabularies and the accessors below project them, both on a
// directly-constructed Policy that never ran the loader.
require_once __DIR__ . '/../Grammar/ColumnCodecGrammar.php';
require_once __DIR__ . '/../Grammar/AttrIdCodecGrammar.php';
require_once __DIR__ . '/../Grammar/BodyRefGrammar.php';
// issue #3348 slice 52: widget type registry/provenance is a pure manifest
// projection; Policy retains its public facades for current callers.
require_once __DIR__ . '/../Grammar/WidgetTypeResolver.php';
// issue #3348 slice 53: effective table declarations and attached-meta lookup
// are pure raw declaration projection; grammar and graph validation stay put.
require_once __DIR__ . '/../Grammar/TableDeclarationResolver.php';
// issue #3348 slice 54: exact/pattern classification rule selection remains pure
// manifest work; Policy keeps the public facades and source-autoload port.
require_once __DIR__ . '/PolicyRuleResolver.php';
// issue #3348 slice 55: the exact option declaration projection is pure
// manifest work; Policy keeps its public inventory facades below.
require_once __DIR__ . '/../Grammar/ExactOptionResolver.php';
// issue #3348 slice 56: option namespace ownership is pure manifest work;
// Policy keeps its public discovery authority facade below.
require_once __DIR__ . '/../Grammar/OptionNamespaceResolver.php';
// issue #3348 slice 18: pure reference-valued declaration shape grammar,
// required here for the same "loads alone" reason as its neighbors above.
require_once __DIR__ . '/../Kernel/ReferenceShapeGrammar.php';
// issue #3348 slice 19: pure post/menu field declaration grammar, required here
// for the same "loads alone" reason as its neighbors above.
require_once __DIR__ . '/../Grammar/FieldGrammar.php';
// issue #3348 slice 20: user-meta safety grammar, required here for the same
// "loads alone" reason as its neighbors above.
require_once __DIR__ . '/../Grammar/UserMetaGrammar.php';
require_once __DIR__ . '/../Kernel/NativeValueValidation.php';
// issue #3348 slice 21: whole-entity scope declaration grammar, required here
// for the same "loads alone" reason as its neighbors above.
require_once __DIR__ . '/ScopeGrammar.php';
// issue #3348 slice 22: adapter compatibility contract grammar, required here
// for the same "loads alone" reason as its neighbors above.
require_once __DIR__ . '/../Adapter/AdapterContractGrammar.php';
// issue #3348 slice 23: cross-source reference-keyspace and attached-meta
// ownership grammar, required here for the same "loads alone" reason as its
// neighbors above.
require_once __DIR__ . '/../Kernel/ReferenceKeyspaceGrammar.php';
// issue #3348 slice 24: ref/token/ledger kind vocabulary grammar, required here
// for the same "loads alone" reason as its neighbors above.
require_once __DIR__ . '/../Kernel/ReferenceKindGrammar.php';
// issue #3348 slice 25: optional site code-declaration grammar, required here
// for the same "loads alone" reason as its neighbors above. The grammar
// preserves Policy's pre-existing implicit Code boundary; it does not load
// Code.php or its materialization graph transitively.
require_once __DIR__ . '/CodeConfigGrammar.php';
// issue #3348 slice 27: pure manifest export projection, required here so the
// stable Policy::export_manifest() facade remains independently loadable.
require_once __DIR__ . '/PolicyWriter.php';
// issue #3348 slice 28: shared per-manifest validation orchestration, required
// here so the live and frozen loaders retain one grammar pipeline.
require_once __DIR__ . '/ManifestValidator.php';
// issue #3348 slice 29: the site.wprism.json policy envelope has one shared
// validation sequence for live and frozen loaders, required here so both
// entry points retain the same standalone load graph and refusal order.
require_once __DIR__ . '/SitePolicyValidator.php';
// WP-2.8: the optional recorded per-release probe evidence block, whose key
// name Policy's own accessor reads. Required directly rather than leaned on
// through SitePolicyValidator above, so this file's class references stay
// self-satisfied the way every other agent/src file's are.
require_once __DIR__ . '/VersionEvidenceGrammar.php';
// WP-5.5: the operator's plugin/theme claim resolutions, whose in-force
// decision version_ranges()/theme_ranges() read and whose displaced rows
// displaced_adapter_claims() reports. Required directly for the same
// self-satisfied-references reason VersionEvidenceGrammar is.
require_once __DIR__ . '/AdapterClaimResolutions.php';
// issue #3348 slice 36: live and frozen loads share one post-local-load
// validation/pin-binding sequence, so keep its refusal order in one place.
require_once __DIR__ . '/PolicyLoadFinalizer.php';
// issue #3348 slice 37: pure dynamic-option declaration resolution is separate
// from Policy's public compatibility/query surface and caller-owned live values.
require_once __DIR__ . '/../Grammar/DynamicOptionResolver.php';
// issue #3348 slice 47: taxonomy-pattern declaration normalization and concrete
// matching are pure manifest work; Policy retains the live taxonomy discovery
// query and the public compatibility facades below.
require_once __DIR__ . '/../Grammar/TaxonomyPatternResolver.php';
// issue #3348 slice 48: taxonomy relationship-keyspace resolution consumes only
// exact declarations and the pure taxonomy-pattern contract, never live DB state.
require_once __DIR__ . '/../Grammar/TaxonomyKeyspaceResolver.php';
// issue #3348 slice 49: description-reference lookup is pure manifest grammar;
// Policy retains public facades so every current runtime caller stays stable.
require_once __DIR__ . '/../Grammar/TaxonomyDescriptionReferenceResolver.php';
// issue #3348 slice 51: option-derived taxonomy object-type declarations are
// pure manifest lookups; Apply retains the compiled-tree timing behavior.
require_once __DIR__ . '/../Grammar/TaxonomyObjectTypeOptionResolver.php';
// issue #3348 slice 45: pure option-name reference declaration resolution is
// separate from Policy's public compatibility/query surface and live callers.
require_once __DIR__ . '/../Grammar/OptionNameReferenceResolver.php';
// issue #3348 slice 46: pure deletion-capability declaration resolution is
// separate from Policy's public compatibility/query surface and live callers.
require_once __DIR__ . '/DeletionCapabilityResolver.php';
// issue #3348 slice 40: manifest-declared post-type relationship queries are
// pure and reusable by scope/planning without broadening their authority.
require_once __DIR__ . '/../Grammar/PostTypeRelationResolver.php';

/**
 * Layered classification policy: site policy overrides > pinned manifests
 * (in pin order) > option name-patterns. Anything unmatched is unclassified,
 * and unclassified is a loud abort at the call sites (never a silent guess).
 */
final class Policy {
    private const DEPLOYED_ADAPTER_LIBRARY_MARKER = 'adapter-library.deployed';
    private const DEPLOYED_ADAPTER_LIBRARY_MARKER_BYTES = "wprism-embedded-adapter-library-assembly/v1\n";

    private const MAX_DISCOVERED_TAXONOMIES = 4096;
    private const MAX_NATIVE_OPTION_COMPANIONS = 8;
    /**
     * The one {min,max} version-range predicate, shared by every site that
     * bounds something by an exact, certifiable window: min and max are both
     * non-empty version strings and min is strictly less than max (min
     * inclusive, max exclusive — the same version_compare() arithmetic
     * AdapterRegistry::inside_range() applies at negotiation). Wildcards,
     * empty, and unbounded forms are not certifiable and are refused. $where
     * names the coordinate so one message serves every caller: this
     * project's own discovery-contract keyspace versioning, the plugin/theme
     * adapter version_range contract, and (via ActionProviderGrammar, a
     * issue #3348 slice 6 extraction) the provider `requires` grammar's three
     * separate version bounds all call this same one implementation.
     *
     * @param array<string,mixed> $range
     */
    public static function assert_min_max_range(array $range, string $where): void {
        $min = $range['min'] ?? null;
        $max = $range['max'] ?? null;
        if (!is_string($min) || $min === '' || !is_string($max) || $max === ''
            || version_compare($min, $max, '>=')) {
            throw new \RuntimeException(
                "wprism: $where has a malformed range (min=" . var_export($min, true)
                . ', max=' . var_export($max, true) . ') — both must be non-empty version strings '
                . 'with min strictly less than max; wildcards/empty/unbounded are not certifiable'
            );
        }
    }

    // v4 adds the required `adapter_sources` record (issue #3314). It is required
    // rather than optional on purpose: if a snapshot could omit it and have
    // every manifest default to "shipped", dropping one key would silently
    // launder an out-of-tree adapter into a shipped one on the verification
    // path, which is exactly the provenance guarantee this record exists for.
    // v5 carries signed site-adapter certification envelopes. v6 drops the
    // frozen `capabilities` record: the reviewed dispositions are now the whole
    // authored claim source, and a v5 snapshot's generated registry has no
    // reader left to validate it against. It is a rejected format rather than
    // an ignored key — a snapshot carrying a record this agent no longer checks
    // must not verify as if it had been checked.
    //
    // v4 is retired outright, not merely superseded. Every v4 document any
    // version of this engine ever exported carries a `capabilities` key —
    // export_snapshot() emitted one from the commit that introduced v4
    // (55538ad) through the last v5 commit — so v6's closed key set already
    // refused every genuine v4 document before the format was even consulted.
    // What the read path still accepted was a five-key shape nothing ever
    // wrote, and it accepted it onto the FAIL-OPEN wprism-adapter-sources/v1
    // record, where a name absent from `out_of_tree` took shipped authority
    // with no proof against the trusted library. Verifying nothing, reachable
    // only by hand-built input, and weaker than the wire it shadowed: refusing
    // it by name is the honest answer.
    private const SNAPSHOT_FORMAT = 'wprism-policy-snapshot/v6';
    /**
     * The exact canonical-surface literal grammar. Apply derives these keys
     * from authored work as a pure projection (Apply::rebuild_surfaces()) and
     * manifests match them literally in `actions[].triggers`; issue #3338's
     * provider capabilities describe their own reads/writes in the same
     * vocabulary, so it is a shared constant rather than two regexes that can
     * drift into accepting different names for the same surface.
     */
    // Native private option keys begin with '_' (transients and ACF shadows).
    // Only that namespace gains the leading byte; the four other domains,
    // exact-literal semantics, capture group and 128-byte bound stay intact.
    public const SURFACE_PATTERN = '/^(post|term|table|option|entity):(?:[a-z0-9]|(?<=option:)_)[a-z0-9._-]{0,127}$/D';

    public array $site = [];
    /** @var array<int, array> */
    public array $manifests = [];
    /** External review state; null only for explicit legacy/custom flat libraries without reviewed data. */
    private ?ManifestDispositions $manifestDispositions = null;
    /** Which source installed each pinned adapter, and what that origin may do (issue #3314). */
    private ?AdapterSources $adapterSources = null;
    /** The shipped library whose package paths this policy executes and hashes. */
    private ?AdapterLibrary $adapterLibrary = null;
    /** @var null|array{artifact_hash:string,site_hash:string,manifest_hash:string,resolved_adapters_sha256:string,adapter_digests:array<string,string>} */
    private ?array $executionArtifactIdentity = null;
    /** @var null|array<string,mixed> exact validated policy frozen with the execution artifact */
    private ?array $executionPolicySnapshot = null;
    /** @var array<string, object>|null lazily-built interpreter instances */
    private ?array $interpreterInstances = null;
    /** @var array<string, object>|null lazily-built regenerator instances (issue #3234) */
    private ?array $regeneratorInstances = null;

    /**
     * Interpreter contract. An interpreter is a class with the required
     *   post_meta_rule(string $key, array $allMeta): ?array
     * hook and may additionally define any of the optional hooks
     *   term_meta_rule(string $key, array $allMeta): ?array
     *   user_meta_rule(string $key, array $allMeta): ?array
     *   option_rule(string $name, array $allOptions): ?array
     * Each returns a classification rule (same shape as the corresponding
     * static meta rule, optionally with 'cast') or null to defer. An option
     * interpreter may additionally return `deletion_witness: true` when
     * that exact option value is the minimum context required to classify
     * related tombstones from an immutable tree; OptionState binds the
     * retained value/autoload to the prior record hash and apply never
     * treats it as desired data. Manifests opt in via
     * {"interpreter": "<name>"} — for schema-driven plugins (ACF) whose meta
     * semantics live in data, not in a static key list. option_rule() (issue #3263)
     * is consulted only for an option NAME already namespace-owned by some
     * manifest's option_namespaces declaration — unlike the meta hooks, an
     * interpreter has no implicit reach over every option in the table.
     *
     * Interpreter CODE is part of the adapter package, never the engine:
     * a declared name resolves through that package's closed runtime inventory,
     * which must define \WPrism\Interpreters\<CamelCase(name)>. The engine holds
     * only this loading contract — no plugin names, no plugin logic. Trust
     * boundary: the embedded adapter library ships with the agent itself (ro
     * in the sandbox), so loading PHP from it is
     * the same trust decision as running the agent.
     */

    /** The one checked-in or installed shipped library; no path search or override. */
    public static function shipped_adapter_library(): AdapterLibrary {
        return self::shipped_adapter_library_at(dirname(__DIR__, 2));
    }

    /** Resolve either an explicitly assembled deployment or this source checkout. */
    private static function shipped_adapter_library_at(string $agentRoot): AdapterLibrary {
        $embedded = $agentRoot . '/adapter-library';
        $marker = $agentRoot . '/' . self::DEPLOYED_ADAPTER_LIBRARY_MARKER;
        if (file_exists($marker) || is_link($marker)) {
            if (is_link($marker)
                || !is_file($marker)
                || file_get_contents($marker) !== self::DEPLOYED_ADAPTER_LIBRARY_MARKER_BYTES) {
                throw new \RuntimeException("wprism: deployed adapter library marker is invalid: $marker");
            }
            return AdapterLibrary::fromEmbeddedDirectory(
                $embedded,
                dirname($agentRoot) . '/wprism-control/adapter-revocations.json'
            );
        }
        if (file_exists($embedded) || is_link($embedded)) {
            throw new \RuntimeException(
                "wprism: embedded adapter library exists without its deployment marker: $embedded"
            );
        }

        $sourceRoot = dirname($agentRoot);
        if (is_dir($sourceRoot . '/adapter-packages') && is_dir($sourceRoot . '/platform/adapter-library')) {
            return AdapterLibrary::fromSourceTree($sourceRoot);
        }

        // An installed agent has exactly one authority root. Passing its
        // expected location to the strict reader preserves that fact in the
        // refusal instead of searching a neighboring or process-selected tree.
        return AdapterLibrary::fromEmbeddedDirectory(
            $embedded,
            dirname($agentRoot) . '/wprism-control/adapter-revocations.json'
        );
    }

    /** The active production library boundary. */
    public static function adapter_library_context(): AdapterLibrary {
        return self::shipped_adapter_library();
    }

    /**
     * Resolve the shipped physical library once per policy instance.
     *
     * Runtime execution and identity share one package/path answer instead of
     * independently deriving interpreter, provider, and regenerator paths.
     */
    public function adapter_library(): AdapterLibrary {
        if ($this->adapterLibrary !== null) {
            return $this->adapterLibrary;
        }
        return $this->adapterLibrary = self::shipped_adapter_library();
    }

    /**
     * Bind executable adapter loading to the artifact this request validated.
     *
     * A Policy is loaded before its compiled artifact. Provider execution is
     * later still, so recomputing identity only at provider load would accept
     * package bytes changed in that gap under a new digest. This one-time
     * binding compares the artifact's site, manifest, and stable executable
     * adapter identities with the current Policy projection. Capability claims
     * also carry the running platform version and intentionally are not part of
     * that stable projection: CompiledArtifactReader permits an otherwise
     * identical artifact across a compatible platform bump, and execution must
     * preserve that contract instead of reintroducing artifact_hash equality by
     * another name. The snapshot is frozen in the same step: a later fresh
     * child may replay these exact validated bytes, never a site/action document
     * reopened after artifact validation.
     *
     * @param list<array<string,mixed>> $resolvedAdapters
     */
    public function bind_execution_artifact_identity(
        string $artifactHash,
        string $siteHash,
        string $manifestHash,
        array $resolvedAdapters
    ): void {
        if (preg_match('/^[a-f0-9]{64}$/D', $artifactHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $siteHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $manifestHash) !== 1
            || !array_is_list($resolvedAdapters)) {
            throw new \RuntimeException('wprism: compiled execution adapter identity is malformed');
        }
        if (!class_exists(ArtifactPolicyIdentity::class, false)) {
            require_once __DIR__ . '/ArtifactPolicyIdentity.php';
        }
        $currentSiteHash = ArtifactPolicyIdentity::site_hash($this);
        $currentManifestHash = ArtifactPolicyIdentity::manifest_hash($this);
        $currentAdapters = ArtifactPolicyIdentity::resolved_adapters($this);
        if (!hash_equals($siteHash, $currentSiteHash)
            || !hash_equals($manifestHash, $currentManifestHash)
            || Canon::encode(self::execution_adapter_projection($resolvedAdapters))
                !== Canon::encode(self::execution_adapter_projection($currentAdapters))) {
            throw new \RuntimeException(
                'wprism: compiled execution policy identity no longer matches the validated policy bytes'
            );
        }
        $this->retain_execution_artifact_identity([
            'artifact_hash' => $artifactHash,
            'site_hash' => $siteHash,
            'manifest_hash' => $manifestHash,
            'resolved_adapters_sha256' => hash(
                'sha256',
                Canon::encode(self::execution_adapter_projection($resolvedAdapters))
            ),
        ], $resolvedAdapters);
    }

    /**
     * Rebind a fresh child to the compact identity its parent proved.
     *
     * The artifact hash is an opaque request identity here; the three policy
     * projections are independently recomputed from the frozen snapshot and
     * exact adapter library before any provider source is loaded.
     *
     * @param array<string,mixed> $identity
     */
    public function bind_fresh_execution_identity(array $identity): void {
        $keys = array_keys($identity);
        sort($keys, SORT_STRING);
        if ($keys !== ['artifact_hash', 'manifest_hash', 'resolved_adapters_sha256', 'site_hash']) {
            throw new \RuntimeException('wprism: fresh execution policy identity is malformed');
        }
        foreach ($identity as $digest) {
            if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new \RuntimeException('wprism: fresh execution policy identity is malformed');
            }
        }
        if (!class_exists(ArtifactPolicyIdentity::class, false)) {
            require_once __DIR__ . '/ArtifactPolicyIdentity.php';
        }
        $this->assert_fresh_shipped_dispositions_current();
        $currentAdapters = ArtifactPolicyIdentity::resolved_adapters($this);
        if (!hash_equals($identity['site_hash'], ArtifactPolicyIdentity::site_hash($this))
            || !hash_equals($identity['manifest_hash'], ArtifactPolicyIdentity::manifest_hash($this))
            || !hash_equals(
                $identity['resolved_adapters_sha256'],
                hash('sha256', Canon::encode(self::execution_adapter_projection($currentAdapters)))
            )) {
            throw new \RuntimeException(
                'wprism: fresh execution identity does not match its frozen policy and adapter bytes'
            );
        }
        $this->retain_execution_artifact_identity($identity, $currentAdapters);
    }

    /**
     * Keep only package identity that must survive from artifact validation to
     * executable load. Dynamic capability/platform projections remain covered
     * by current Policy validation, while name+digest pins the exact package
     * bytes the child is permitted to execute.
     *
     * @param list<array<string,mixed>> $resolvedAdapters
     * @return list<array{name:string,digest:string}>
     */
    private static function execution_adapter_projection(array $resolvedAdapters): array {
        $projection = [];
        foreach ($resolvedAdapters as $row) {
            $name = is_array($row) ? ($row['name'] ?? null) : null;
            $digest = is_array($row) ? ($row['digest'] ?? null) : null;
            if (!is_string($name)
                || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $name) !== 1
                || !is_string($digest)
                || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new \RuntimeException(
                    'wprism: compiled execution adapter identity is malformed'
                );
            }
            $projection[] = ['name' => $name, 'digest' => $digest];
        }
        return $projection;
    }

    /**
     * Frozen policy semantics stay frozen, but shipped ratification is code-like
     * package identity. Reopen only those current disposition bytes and prove
     * exact equality before any adapter executable can load in the child.
     */
    private function assert_fresh_shipped_dispositions_current(): void {
        $shipped = $this->adapter_sources()->shipped_manifests($this->manifests);
        if ($shipped === []) {
            return;
        }
        if ($this->manifestDispositions === null) {
            throw new \RuntimeException(
                'wprism: fresh execution identity has no frozen shipped disposition bytes'
            );
        }
        $current = ManifestDispositions::load_library($this->adapter_library());
        $current->assert_covers($shipped);
        foreach ($shipped as $manifest) {
            $name = (string) ($manifest['name'] ?? '');
            $frozenEntry = $this->manifestDispositions->entry($name);
            $currentEntry = $current->entry($name);
            if (!is_array($frozenEntry)
                || !is_array($currentEntry)
                || !hash_equals(
                    hash('sha256', Canon::encode($frozenEntry)),
                    hash('sha256', Canon::encode($currentEntry))
                )) {
                throw new \RuntimeException(
                    'wprism: fresh execution identity does not match current shipped disposition bytes'
                );
            }
        }
    }

    /** @param array<string,string> $identity @param list<array<string,mixed>> $resolvedAdapters */
    private function retain_execution_artifact_identity(array $identity, array $resolvedAdapters): void {
        $digests = [];
        foreach ($resolvedAdapters as $index => $row) {
            $expectedName = $this->manifests[$index]['name'] ?? null;
            $name = is_array($row) ? ($row['name'] ?? null) : null;
            $digest = is_array($row) ? ($row['digest'] ?? null) : null;
            if (!is_string($name)
                || $name !== $expectedName
                || !is_string($digest)
                || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
                || isset($digests[$name])) {
                throw new \RuntimeException('wprism: compiled execution adapter identity is malformed');
            }
            $digests[$name] = $digest;
        }
        if (count($digests) !== count($this->manifests)) {
            throw new \RuntimeException('wprism: compiled execution adapter identity is incomplete');
        }
        $binding = $identity + ['adapter_digests' => $digests];
        $snapshot = $this->export_snapshot();
        if ($this->executionArtifactIdentity !== null
            && ($this->executionArtifactIdentity !== $binding
                || Canon::encode($this->executionPolicySnapshot) !== Canon::encode($snapshot))) {
            throw new \RuntimeException(
                'wprism: one policy instance cannot acquire two compiled execution identities'
            );
        }
        $this->executionArtifactIdentity = $binding;
        $this->executionPolicySnapshot = $snapshot;
    }

    /** The artifact-bound digest required before manifest code may mutate. */
    public function execution_adapter_digest(string $name): ?string {
        return $this->executionArtifactIdentity['adapter_digests'][$name] ?? null;
    }

    /** @return null|array{artifact_hash:string,site_hash:string,manifest_hash:string,resolved_adapters_sha256:string} */
    public function execution_artifact_identity(): ?array {
        if ($this->executionArtifactIdentity === null) {
            return null;
        }
        return [
            'artifact_hash' => $this->executionArtifactIdentity['artifact_hash'],
            'site_hash' => $this->executionArtifactIdentity['site_hash'],
            'manifest_hash' => $this->executionArtifactIdentity['manifest_hash'],
            'resolved_adapters_sha256' => $this->executionArtifactIdentity['resolved_adapters_sha256'],
        ];
    }

    /** @return null|array<string,mixed> */
    public function execution_policy_snapshot(): ?array {
        return $this->executionPolicySnapshot;
    }

    /** The platform boundary belonging to this policy's resolved library. */
    public function adapter_platform_boundary(): array {
        return ManifestDispositions::platform_boundary_library($this->adapter_library());
    }

    /** The shipped package that owns one manifest name. */
    public function adapter_package(string $name): AdapterPackage {
        $package = $this->adapter_library()->package($name);
        if ($package === null) {
            throw new \RuntimeException(
                "wprism: shipped adapter package '$name' is absent from " . $this->adapter_library()->root()
            );
        }
        return $package;
    }

    /**
     * Resolve manifest-owned runtime through its explicitly selected package.
     */
    public function adapter_runtime_path(string $manifest, string $kind, string $id): string {
        $package = $this->adapter_package($manifest);
        return match ($kind) {
            'interpreters' => $package->interpreterPath()
                ?? throw new \RuntimeException("wprism: adapter $manifest does not declare interpreter $id"),
            'providers' => $package->providerPath($id),
            'regenerators' => $package->regeneratorPath($id),
            default => throw new \RuntimeException("wprism: unknown adapter runtime kind '$kind'"),
        };
    }

    /**
     * V1 is deliberately single-site. Refuse before policy/repository reads
     * so a network install cannot be mistaken for a supported convergence
     * surface and no command can publish a partial single-blog projection.
     * The function guard keeps the pure offline policy validators usable
     * outside WordPress while the real product path always has is_multisite().
     *
     * The throw itself moved to agent/src/Kernel/SiteTopology.php so the
     * Policy-free verbs can ask the same question with the same answer:
     * journal-reset (Cli.php:2203-2237), the four promotion-lease verbs
     * (:605-802) and classify (:2608) never build a Policy, so on a network
     * they reached `Ledger::ensure()`'s four CREATE TABLEs and `PromotionLock`
     * with no gate at any layer. The answer is also TYPED now
     * (CommandRefusalException, reason code `multisite_unsupported`): a bare
     * RuntimeException is not in `Cli::PUBLIC_REFUSAL_CLASSES` (:397-399), so
     * `--format=json` collapsed it to `<command>_failed` with
     * `details_redacted: true` (:84-85, :98) and never said "multisite".
     * getMessage() is unchanged (CommandRefusal.php:52 takes the operator
     * message), so human mode prints the same bytes.
     */
    private static function assert_single_site(): void {
        SiteTopology::assert_single_site();
    }

    /** Refuse unexercised runtime versions before any policy/repository read. */
    private static function assert_supported_platform(?AdapterLibrary $adapterLibrary = null): void {
        // Pure manifest/compiler contexts and the shared offline WP stubs may
        // expose path helpers without loading WordPress core. A real loaded
        // target defines WPINC as well as ABSPATH before wp-cli dispatch, so
        // all three facts are required to distinguish it from those fixtures.
        if (!defined('ABSPATH') || !defined('WPINC') || !function_exists('get_bloginfo')) {
            return;
        }
        $platform = ManifestDispositions::platform_boundary_library(
            $adapterLibrary ?? self::shipped_adapter_library()
        );
        PlatformCompatibility::assert_supported($platform);
    }

    /**
     * Supply the engine-owned vocabularies used by the pure manifest-local
     * validator without making ManifestValidator duplicate runtime policy
     * constants.
     *
     * @return array{
     *   derivable_field_columns: array<string,string>,
     *   field_classes: list<string>,
     *   menu_derivable_fields: list<string>,
     *   menu_field_classes: list<string>,
     *   casts: list<string>,
     *   classes: list<string>,
     *   missing_user_modes: list<string>
     * }
     */
    private static function manifest_validator_vocabulary(): array {
        return [
            'derivable_field_columns' => self::DERIVABLE_FIELD_COLUMNS,
            'field_classes' => self::FIELD_CLASSES,
            'menu_derivable_fields' => self::MENU_DERIVABLE_FIELDS,
            'menu_field_classes' => self::MENU_FIELD_CLASSES,
            'casts' => self::CASTS,
            'classes' => self::CLASSES,
            'missing_user_modes' => self::MISSING_USER_MODES,
        ];
    }

    /**
     * "This directory is not a wprism repository" is the first thing an
     * orchestrator meets on a mistyped --repo, and it was a bare
     * \RuntimeException: `Cli::halt_json_failure()` classified it through its
     * catch-all (agent/src/Command/Cli.php:83-98), so `--format=json` returned
     * `plan_failed` / `capture_failed` / … plus `details_redacted: true` and
     * the caller could not distinguish a wrong path from a real repository
     * defect. It is one FACT reached from three places (load(), and set_rule()'s
     * two write gates), so it is one code minted in one place rather than three
     * hand-copied constructions that could drift.
     *
     * The public half MUST NOT carry $siteFile. The path is absolute, and on any
     * developer or shared-host layout it matches
     * `CommandRefusalException::containsSensitivePublicDetail()`'s ~/(?:Users|home)/~
     * screen (agent/src/Kernel/CommandRefusal.php:199) — which would redact the
     * WHOLE payload, replacing the reason code's guidance with the generic
     * "structured refusal details were redacted" (:44-48). The path stays in the
     * operator sentence, which `parent::__construct` (CommandRefusal.php:52)
     * keeps byte-identical to what `WP_CLI::error($t->getMessage())` has always
     * printed.
     */
    private static function repository_missing(string $siteFile): CommandRefusalException {
        return new CommandRefusalException(
            'repository_missing',
            'the given repository path is not a wprism site repository: it has no site.wprism.json',
            'point --repo at an initialized wprism site repository, or run wprism init against that directory first',
            [],
            "wprism: $siteFile not found (not a wprism site repo?)"
        );
    }

    public static function load(
        ?string $repo,
        ?array $manifestNames = null,
        bool $allowUnsupportedSiteForReadOnlyCapabilities = false,
        ?string $adapterRepo = null,
        ?AdapterLibrary $adapterLibrary = null
    ): self {
        return self::load_with(
            null,
            $repo,
            $manifestNames,
            $allowUnsupportedSiteForReadOnlyCapabilities,
            $adapterRepo,
            $adapterLibrary
        );
    }

    /**
     * Everything a load resolves about the LIBRARY rather than about a pin,
     * resolved once, for a reader that will load many pins against it.
     *
     * The only caller is `AdapterScan` (WP-1.3), which holds the result behind
     * a file-set witness and hands it back through load_from_scan() below;
     * `AdapterSources::survey()` was re-running all three of these once per
     * surveyed adapter, which is O(library) work repeated O(library) times.
     *
     * The ORDER is load()'s own and is load-bearing, which is why this is
     * three statements and not one array literal: the two asserts refuse
     * before any repository or library read (that is the whole point of
     * assert_supported_platform() sitting where it does), and `discover()`
     * refuses an ambiguous installation before the reviewed registry is even
     * opened. A survey against a multisite target with a broken library still
     * reports the multisite refusal, because that is the one that fires first
     * here exactly as it fires first there.
     *
     * The exact library object is retained by the resolution, so consumers
     * cannot silently join the result to a different physical inventory.
     *
     * @return array{dir:string, adapter_library:AdapterLibrary, dispositions:?ManifestDispositions, sources:AdapterSources}
     */
    public static function resolve_library(?string $repo, ?AdapterLibrary $adapterLibrary = null): array {
        self::assert_single_site();
        $adapterLibrary ??= self::shipped_adapter_library();
        self::assert_supported_platform($adapterLibrary);
        $dir = $adapterLibrary->root();
        $sources = AdapterSources::discover_library($adapterLibrary, $repo);
        $dispositions = !class_exists(ManifestDispositions::class)
            ? null
            : ManifestDispositions::load_library($adapterLibrary);
        return [
            'dir' => $dir,
            'adapter_library' => $adapterLibrary,
            'dispositions' => $dispositions,
            'sources' => $sources,
        ];
    }

    /**
     * One pin, loaded against a library resolution the CALLER has proved is
     * still current.
     *
     * READ-ONLY BY CONSTRUCTION, and that is the risk control rather than a
     * naming convention: no mutation entry point calls this — every one of
     * them enters through load(), which resolves its own sources — and the
     * only caller in the shipped tree is `AdapterScan::load()`, which re-
     * derives its witness before every single call and refuses instead of
     * serving a resolution the disk no longer matches.
     * `sandbox/tests/offline/adapter/regress_adapter_survey_scale.php` asserts
     * that call-site set against the tree.
     *
     * @param array{dir:string, adapter_library:AdapterLibrary, dispositions:?ManifestDispositions, sources:AdapterSources} $library
     * @param list<string>|list<array<string,mixed>> $manifestNames
     */
    public static function load_from_scan(array $library, ?string $repo, array $manifestNames): self {
        return self::load_with($library, $repo, $manifestNames, false, null, null);
    }

    /**
     * load()'s one body. `$library === null` is the ordinary load, which
     * resolves each piece exactly where it always did; a supplied library
     * substitutes those pieces and changes nothing else, including the order
     * every other refusal fires in.
     *
     * @param ?array{dir:string, adapter_library:AdapterLibrary, dispositions:?ManifestDispositions, sources:AdapterSources} $library
     */
    private static function load_with(
        ?array $library,
        ?string $repo,
        ?array $manifestNames,
        bool $allowUnsupportedSiteForReadOnlyCapabilities,
        ?string $adapterRepo,
        ?AdapterLibrary $adapterLibrary
    ): self {
        $selectedLibrary = $library === null
            ? ($adapterLibrary ?? self::shipped_adapter_library())
            : $library['adapter_library'];
        // A supplied library has already been through resolve_library(), which
        // runs both asserts FIRST, before it reads anything; running them
        // again per pin would re-read the resolved platform boundary once per
        // surveyed adapter to re-answer a question about the process.
        if ($library === null && !$allowUnsupportedSiteForReadOnlyCapabilities) {
            self::assert_single_site();
            self::assert_supported_platform($selectedLibrary);
        }
        $p = new self();
        $p->adapterLibrary = $selectedLibrary;
        if ($repo !== null) {
            $siteFile = rtrim($repo, '/') . '/site.wprism.json';
            if (!is_file($siteFile)) {
                throw self::repository_missing($siteFile);
            }
            // Repository identity is locally decidable and has a typed public
            // refusal. Establish it before reading external recovery debt;
            // otherwise a mistyped --repo is misreported as an unsafe recovery
            // boundary. Both debt fences still run before the first policy byte
            // is read, which is the safety boundary they protect.
            CheckpointRecoveryIntent::assert_clear($repo);
            ProviderSettlementIntent::assert_clear($repo);
            $p->site = Canon::decode(Canon::read_file($siteFile));
            SitePolicyValidator::validate(
                $p->site,
                'site.wprism.json',
                self::CLASSES,
                self::MISSING_USER_MODES
            );
        }
        $manifestValidatorVocabulary = self::manifest_validator_vocabulary();
        $rawPins = $manifestNames ?? ($p->site['manifests'] ?? ['core']);
        $pins = PinResolver::normalize_manifest_pins($rawPins);
        $dir = $p->adapterLibrary->root();
        // A supplied resolution must carry the exact object root it names.
        // AdapterScan's witness guards content and shape; this guards a caller
        // assembling a logically inconsistent resolution array.
        if ($library !== null && $library['dir'] !== $dir) {
            throw new \RuntimeException(
                'wprism: a resolved adapter library was offered for a different manifest directory than the one this '
                . 'process now loads from'
            );
        }
        // issue #3314: every installed source is scanned, and ambiguous identity or
        // shadowing refused, before the first pin resolves — a broken adapter
        // installation must not wait for a pin to reveal itself.
        // Init needs source-aware validation before site.wprism.json exists. Its
        // fourth argument supplies only the repository-owned adapter source;
        // ordinary loads continue to derive both config and source from $repo.
        //
        // CLONED, never shared: the finalizer binds THIS load's pins onto the
        // instance (PolicyLoadFinalizer.php:51, bind_explicit_pins), so a
        // shared one would carry row 1's explicit pins into row 2's
        // certification elevation. Every property of AdapterSources is a
        // string or an array, so the shallow copy is a value copy.
        $p->adapterSources = $library === null
            ? AdapterSources::discover_library($p->adapterLibrary, $adapterRepo ?? $repo)
            : clone $library['sources'];
        PinResolver::validate_manifest_sources($pins, $p->adapterSources);
        // The registry DOCUMENT is read here — or carried in by a resolved
        // library, which read it after its own discover() and before any pin,
        // so the order the refusals fire in is the same one, and the object is
        // immutable after construction so every pin sees the same bytes.
        // Either way it is ahead of the pin loop, so a
        // malformed root or profile still refuses before any manifest is
        // validated — the order it always refused in. What it no longer does is
        // decode the whole directory: coverage is proved against the PINNED
        // shipped subset after the loop, where the manifests are already in
        // hand (ManifestDispositions::assert_covers()).
        $p->manifestDispositions = $library === null
            ? (!class_exists(ManifestDispositions::class)
                ? null
                : ManifestDispositions::load_library($p->adapterLibrary))
            : $library['dispositions'];
        foreach ($pins as $pin) {
            $name = $pin['name'];
            // normalize_manifest_pins() has already proved this exact identity
            // path-free and canonical; never rewrite it into a different key.
            $key = $name;
            $manifest = Canon::decode(Canon::read_file(
                $p->adapterSources->file($key, $p->adapterLibrary ?? $dir)
            ));
            // issue #3371: the earliest point on the live load path where a
            // manifest's FILE name and its DECLARED name are both in hand, and
            // therefore the only place one identity can be enforced for both
            // keyings. The pin and the file key off the file name;
            // ManifestDispositions::entry()/assert_covers(),
            // RepositoryCompiler::manifest_rows()'s per-adapter digest, and the
            // capability registry's claims all key off the declared name. Every
            // one of those declared-name lookups is downstream of this line —
            // nothing reads $p->manifests before it exists, and WP-1.2's
            // coverage check runs after the whole loop — so refusing here,
            // ahead of the first validator, is what keeps one adapter from
            // answering to two keys. from_snapshot() has always refused the
            // same disagreement against the frozen pin; this is the
            // live path's half of that, and AdapterSources owns the sentence so
            // the site source (issue #3314) and the shipped source say it once.
            //
            // issue #3339/B2: for the PLUGIN source this is a TAUTOLOGY, and
            // deliberately kept. Identity inverts there — every bundle is
            // named `wprism-adapter.json`, so the file name carries none and the
            // scan keys the origin off the DECLARED name — which makes
            // $key === $manifest['name'] true by construction. It stays
            // because it is only true by construction while that remains how
            // the plugin scan keys an origin: the day something keys it off
            // anything else, this line is what notices, and the alternative
            // (skipping the source) would be the silence it exists to remove.
            AdapterSources::assert_declared_name(
                $manifest,
                $key,
                $p->adapterSources->source($key),
                (string) $p->adapterSources->path($key)
            );
            if ($p->adapterSources->is_out_of_tree($key)) {
                // The instance picks the noun and the path from the origin it
                // actually resolved: with three sources, a hardcoded "site
                // adapter" would have named the wrong directory to go fix for
                // every plugin-bundled manifest.
                $p->adapterSources->assert_installed_contract($key, $manifest);
            }
            ManifestValidator::validate_manifest(
                $manifest,
                "manifest '$name'",
                $manifestValidatorVocabulary
            );
            $p->manifests[] = $manifest;
        }
        // "A manifest cannot certify itself merely by existing beside the
        // agent" (ManifestDispositions.php:7) is a rule about a PINNED
        // manifest, and this is where it fires: every shipped pin must have a
        // reviewed entry, and that entry must pass all nine per-entry rules
        // against the manifest bytes just validated above. The refusal sentence
        // is the one the whole-directory check emitted.
        //
        // shipped_manifests() and not $p->manifests, for the reason the frozen
        // path states at :559-564 and AdapterSources::shipped_manifests()
        // repeats: an out-of-tree adapter has no reviewed entry by
        // construction, so demanding one would refuse every unrelated shipped
        // adapter beside it.
        $p->manifestDispositions?->assert_covers($p->adapterSources->shipped_manifests($p->manifests));
        PolicyLoadFinalizer::finalize($p, $pins);
        return $p;
    }

    /**
     * Serialize the already-validated policy inputs for a fresh verification
     * process. The compiled artifact independently binds the site and
     * manifest hashes; this snapshot carries the bytes needed to reconstruct
     * that exact policy without reopening mutable repository files.
     */
    public function export_snapshot(): array {
        $adapterSources = $this->adapter_sources()->export();
        return [
            'format' => self::SNAPSHOT_FORMAT,
            'site' => $this->site,
            'manifests' => $this->manifests,
            'adapter_sources' => $adapterSources,
            'dispositions' => $this->manifestDispositions?->data(),
        ];
    }

    /** Reconstruct and fully validate a policy exported by export_snapshot(). */
    public static function from_snapshot(array $snapshot, ?AdapterLibrary $adapterLibrary = null): self {
        $adapterLibrary ??= self::shipped_adapter_library();
        self::assert_single_site();
        self::assert_supported_platform($adapterLibrary);
        $keys = array_keys($snapshot);
        sort($keys, SORT_STRING);
        $snapshotFormat = $snapshot['format'] ?? null;
        // Named ahead of the shape gate on purpose. A genuine v4 document also
        // carries the retired `capabilities` key, so the closed key set would
        // otherwise answer an operator holding a real pre-v5 snapshot with the
        // generic "malformed shape" and never tell them the format is why.
        if ($snapshotFormat === 'wprism-policy-snapshot/v4') {
            throw new \RuntimeException(
                'wprism: frozen policy snapshot format wprism-policy-snapshot/v4 is retired and is no longer read; '
                . 'its wprism-adapter-sources/v1 record let a manifest absent from `out_of_tree` take shipped '
                . 'authority without proving its bytes against the trusted library, which '
                . self::SNAPSHOT_FORMAT . ' refuses — re-export the policy with this agent'
            );
        }
        if ($keys !== ['adapter_sources', 'dispositions', 'format', 'manifests', 'site']
            || $snapshotFormat !== self::SNAPSHOT_FORMAT
            || !is_array($snapshot['adapter_sources'] ?? null)
            || !is_array($snapshot['site'] ?? null)
            || !is_array($snapshot['manifests'] ?? null)
            || !array_is_list($snapshot['manifests'])) {
            throw new \RuntimeException('wprism: frozen policy snapshot has an unsupported or malformed shape');
        }
        if (($snapshot['adapter_sources']['format'] ?? null) !== AdapterSources::FORMAT) {
            throw new \RuntimeException(
                'wprism: frozen policy snapshot format disagrees with its adapter source record format'
            );
        }

        $p = new self();
        $p->adapterLibrary = $adapterLibrary;
        $p->site = $snapshot['site'];
        SitePolicyValidator::validate(
            $p->site,
            'frozen site.wprism.json',
            self::CLASSES,
            self::MISSING_USER_MODES
        );
        $manifestValidatorVocabulary = self::manifest_validator_vocabulary();

        $pins = PinResolver::normalize_manifest_pins($p->site['manifests'] ?? ['core']);
        if (count($pins) !== count($snapshot['manifests'])) {
            throw new \RuntimeException('wprism: frozen policy snapshot manifest count disagrees with site pins');
        }
        foreach ($snapshot['manifests'] as $i => $manifest) {
            if (!is_array($manifest) || array_is_list($manifest)) {
                throw new \RuntimeException("wprism: frozen policy snapshot manifests[$i] is not an object");
            }
            $name = (string) ($manifest['name'] ?? '');
            if ($name === '' || !hash_equals((string) $pins[$i]['name'], $name)) {
                throw new \RuntimeException("wprism: frozen policy snapshot manifest order/name disagrees with site pin $i");
            }
            ManifestValidator::validate_manifest(
                $manifest,
                "frozen manifest '$name'",
                $manifestValidatorVocabulary,
                true
            );
            $p->manifests[] = $manifest;
        }
        // Provenance is reconstructed before the reviewed dispositions so both
        // see the same shipped subset load() gave them (issue #3314). Only the
        // shipped subset is a reviewed claim: handing an out-of-tree manifest
        // to disposition validation would demand an entry that cannot exist,
        // so one site-installed adapter would refuse every unrelated shipped
        // adapter along with itself — the exact failure issue #3314 removes.
        $p->adapterSources = AdapterSources::from_snapshot(
            $snapshot['adapter_sources'],
            $p->manifests,
            $adapterLibrary
        );
        PinResolver::validate_manifest_sources($pins, $p->adapterSources);
        $shipped = $p->adapterSources->shipped_manifests($p->manifests);
        $dispositions = $snapshot['dispositions'] ?? null;
        if ($dispositions !== null) {
            if (!is_array($dispositions) || !class_exists(ManifestDispositions::class)) {
                throw new \RuntimeException('wprism: frozen policy snapshot disposition registry is unavailable or malformed');
            }
            $p->manifestDispositions = ManifestDispositions::from_snapshot($dispositions, $shipped);
        }
        PolicyLoadFinalizer::finalize($p, $pins);
        return $p;
    }

    /**
     * Adapter provenance for this policy. Never null after load()/from_
     * snapshot(); the fallback covers only a policy built by an offline test
     * harness that never ran either, and it claims nothing (no sources known,
     * so nothing is out-of-tree and nothing is laundered).
     */
    public function adapter_sources(): AdapterSources {
        if ($this->adapterSources !== null) {
            return $this->adapterSources;
        }
        return $this->adapterSources = AdapterSources::discover_library($this->adapter_library(), null);
    }

    /**
     * The reviewed support boundary for one pinned adapter, if this library has
     * a registry. An out-of-tree adapter has no reviewed entry and never
     * acquires one: it answers with the synthesized provenance record instead,
     * so the disposition slot that feeds RepositoryCompiler::manifest_rows()
     * binds its origin into the adapter digest exactly where a reviewed entry
     * would sit — and every shipped row keeps hashing the bytes it always did.
     *
     * issue #3348 slice 4: the implementation now lives in
     * AdapterRegistry::manifest_disposition(); this method is a thin facade
     * kept so every existing caller needs no change.
     */
    public function manifest_disposition(string $name): ?array {
        return $this->adapter_registry()->manifest_disposition($name);
    }

    /**
     * The reviewed capability claim for one pinned adapter.
     *
     * issue #3348 slice 4: the implementation now lives in
     * AdapterRegistry::capability_claim(); thin facade, as above.
     */
    public function capability_claim(string $name): ?array {
        return $this->adapter_registry()->capability_claim($name);
    }

    /**
     * Certification/source-only blockers for a pinned adapter set.
     *
     * This intentionally does not contact provider code. Apply::build_plan()
     * starts with this stable source/evidence view, then appends provider
     * problems for only the actions its own work/deletion surfaces selected.
     * Calling the global provider view here would turn an unrelated or empty
     * plan into a blocker for a declaration it cannot execute on that plan.
     *
     * issue #3348 slice 4: the implementation now lives in
     * AdapterRegistry::certification_readiness_blockers(); thin facade, as
     * manifest_disposition() above.
     */
    public function certification_readiness_blockers(): array {
        return $this->adapter_registry()->certification_readiness_blockers();
    }

    /**
     * Every source/evidence and runtime-provider blocker for this policy.
     *
     * This is the global readiness answer used by callers that ask whether
     * the installed adapter set is usable at all. It deliberately negotiates
     * every declared provider ACTION, irrespective of that action's trigger:
     * a capability report must not be green merely because this particular
     * moment has no matching authored surface. Plan construction uses
     * certification_readiness_blockers() plus the selected-action method
     * below instead.
     *
     * issue #3348 slice 4: the implementation now lives in
     * AdapterRegistry::adapter_readiness_blockers(); thin facade, as
     * manifest_disposition() above.
     */
    public function adapter_readiness_blockers(): array {
        return $this->adapter_registry()->adapter_readiness_blockers();
    }

    /**
     * Negotiate selected provider actions for a target-facing diagnostic.
     *
     * Providers::negotiate() calls only identity() and capabilities() on a
     * provider; it never calls invoke(). Its structured problems are promoted
     * into the same adapter_dispositions wire shape plan/status already render
     * so provider, plugin, source, tier, and operator remediation remain
     * visible instead of a signed claim masking a live incompatibility.
     *
     * @param list<array<string,mixed>> $actions
     * @return list<array<string,mixed>>
     *
     * issue #3348 slice 4: the implementation now lives in
     * AdapterRegistry::provider_readiness_blockers(); thin facade, as
     * manifest_disposition() above.
     */
    public function provider_readiness_blockers(array $actions): array {
        return $this->adapter_registry()->provider_readiness_blockers($actions);
    }

    /**
     * Resolve CLI capability output from the same manifests and external review bytes.
     *
     * issue #3348 slice 4: the implementation now lives in
     * AdapterRegistry::capability_report(); thin facade, as
     * manifest_disposition() above.
     */
    public function capability_report(array $query = []): array {
        return $this->adapter_registry()->capability_report($query);
    }

    /**
     * Fresh per call, matching ConvergenceVerifier's identical relationship to
     * Apply (issue #3347): AdapterRegistry has no state of its own to lose between
     * calls (every field is set once at load()/from_snapshot() time and read
     * from here, never mutated), so constructing on demand needs no cache.
     */
    private function adapter_registry(): AdapterRegistry {
        return new AdapterRegistry($this, $this->manifestDispositions);
    }

    /** @return ?array{format:int,layout:string,source:string,lock?:string} */
    public function code_config(): ?array {
        $code = $this->site['code'] ?? null;
        return is_array($code) ? $code : null;
    }

    /**
     * The repository-relative code lock path a format-2 declaration names, or
     * null for the fully vendored format-1 shape and for a state-only repo
     * (issue #3499). Read straight off the declaration so Policy keeps no code
     * grammar of its own — CodeConfigGrammar already refused anything else at
     * load time.
     */
    public function code_lock_path(): ?string {
        $code = $this->code_config();
        return is_array($code) && ($code['format'] ?? null) === 2 && is_string($code['lock'] ?? null)
            ? $code['lock']
            : null;
    }

    /**
     * WP-2.8: the recorded per-release probe outcomes this site holds, read
     * straight off the declaration for the same reason code_config() is —
     * VersionEvidenceGrammar already refused every other shape at load time
     * (SitePolicyValidator.php), on the live and the frozen path alike, so
     * Policy keeps no evidence grammar of its own. An absent key is an empty
     * block, which is what makes "no evidence" the default: the graduated
     * verdict cannot fire, and outside_version_range refuses unchanged.
     *
     * @return array<string,mixed> keyed by plugin basename, exactly as version_ranges() is
     */
    public function adapter_version_evidence(): array {
        $block = $this->site[VersionEvidenceGrammar::SITE_KEY] ?? null;
        return is_array($block) ? $block : [];
    }

    /** @return array{rule:?array, source:?string} Policy's compatibility facade over PolicyRuleResolver. */
    private function rule_details(string $section, string $name): array {
        return $this->policy_rule_resolver()->details($section, $name);
    }

    /** Fresh per call so mutable Policy fixture declarations remain observable. */
    private function policy_rule_resolver(): PolicyRuleResolver {
        return new PolicyRuleResolver(
            $this->site,
            $this->manifests,
            static fn(array $rule, array $source): array => self::with_option_autoload($rule, $source)
        );
    }

    /** Fresh per call so mutable Policy fixture declarations remain observable. */
    private function exact_option_resolver(): ExactOptionResolver {
        return new ExactOptionResolver($this->site, $this->manifests, $this->policy_rule_resolver());
    }

    private function rule(string $section, string $name): ?array {
        return $this->rule_details($section, $name)['rule'];
    }

    public function option_rule(string $name): ?array {
        return $this->rule('options', $name);
    }

    /** @return array{rule:?array, source:?string} */
    public function option_rule_details(string $name): array {
        return $this->rule_details('options', $name);
    }

    /** Fresh per call so mutable Policy fixture declarations remain observable. */
    private function option_namespace_resolver(): OptionNamespaceResolver {
        return new OptionNamespaceResolver($this->manifests);
    }

    /**
     * Return the manifest namespace that claims discovery responsibility for
     * an option name. A namespace is deliberately ownership-only: it does
     * not classify the value. The ordinary exact/pattern rule must still do
     * that, otherwise Capture/Pending report the row as an unknown.
     *
     * @return ?array{owner:string, match:string}
     */
    public function option_namespace(string $name): ?array {
        return $this->option_namespace_resolver()->owner_for($name);
    }

    /** Classification for a namespace-owned option, with owner agreement. */
    public function owned_option_rule(string $name): ?array {
        $owner = $this->option_namespace($name);
        if ($owner === null) {
            return null;
        }
        $details = $this->option_rule_details($name);
        if ($details['rule'] !== null && $details['source'] !== 'site.wprism.json'
            && $details['source'] !== $owner['owner']) {
            throw new \RuntimeException(
                "wprism: option '$name' namespace is owned by '{$owner['owner']}' but its classification comes from "
                . "'{$details['source']}' — cross-manifest ownership is ambiguous"
            );
        }
        return $details['rule'];
    }

    public function post_meta_rule(string $key): ?array {
        return $this->rule('post_meta', $key);
    }

    /** @return array{rule:?array, source:?string} */
    public function post_meta_rule_details(string $key): array {
        return $this->rule_details('post_meta', $key);
    }

    public function term_meta_rule(string $key): ?array {
        return $this->rule('term_meta', $key);
    }

    /**
     * The missing third `_details()` sibling of the options/post_meta pair.
     *
     * Journal::ground_truth_details() dispatches over exactly the four
     * sections Journal::ground_truth() always has — options, postmeta,
     * termmeta, then the table rule — and needs the declaring manifest for
     * each. Three of the four already published one; term_meta did not, so a
     * termmeta write would have had to be dropped from attribution silently.
     *
     * @return array{rule:?array, source:?string}
     */
    public function term_meta_rule_details(string $key): array {
        return $this->rule_details('term_meta', $key);
    }

    public function user_meta_rule(string $key): ?array {
        return $this->rule('user_meta', $key);
    }

    public function table_rule(string $unprefixedTable): ?array {
        return $this->table_declaration_resolver()->details($unprefixedTable)['rule'];
    }

    /**
     * Snapshot consumes declared_tables(), whose established precedence is
     * last pinned manifest then site override. Report the source of that same
     * effective declaration; using rule_details() here would reproduce the
     * older first-manifest single-name inconsistency instead of the rule the
     * typed-snapshot writer actually follows.
     *
     * @return array{rule:?array, source:?string}
     */
    public function declared_table_details(string $name): array {
        return $this->table_declaration_resolver()->details($name);
    }

    /**
     * Every exact option name a pinned manifest or site policy declares, of
     * ANY class, mapped to its winning rule.
     *
     * The unfiltered sibling of authored_options()/sub_keyed_options() below,
     * and deliberately a separate accessor rather than a filter argument on
     * them: bulk CAPTURE asks "is this name in the whitelist", which is a
     * class question, while bulk VISIBILITY asks "does any rule win for this
     * name at all", which is not. issue #3505: Coverage::options_report()
     * answered the second question with the first question's enumerators, so
     * a name an adapter declares env/runtime/derived was reported "invisible
     * to every installed adapter" — 10 of the 19 option names
     * `platform/adapter-library/core/manifest.json` itself declares, measured on a site holding
     * nothing else. Same ExactOptionResolver as the filtered views, so the
     * pin winner is identical to the per-name capture/apply path.
     *
     * @return array<string,array> name => winning rule, ksorted
     */
    public function exact_options(): array {
        return $this->exact_option_resolver()->all();
    }

    /**
     * Option names classified authored (the capture whitelist).
     *
     * issue #3255: ExactOptionResolver is the single precedence path for this
     * and the env/sub-key bulk enumerators. It delegates every name to
     * PolicyRuleResolver, so bulk lookup can never silently use a different
     * pin winner than the per-name capture/apply path. Policy::load() has
     * already refused contradictory non-core declarations; identical ones
     * dedupe here, while core-yields-to-plugin and site override precedence
     * remain exactly the rule_details() contract.
     */
    public function authored_options(): array {
        return $this->exact_option_resolver()->authored();
    }

    /**
     * Options declaring `sub_keys` (issue #3233): name => full rule (including
     * the `sub_keys` map). Sibling enumeration to authored_options() above,
     * same merge precedence (site policy replaces a manifest's whole rule
     * wholesale, never a deep merge — a site overriding options.<name> is
     * expected to repeat sub_keys if it still wants any of it, exactly like
     * it already must repeat 'class' today) — but keyed on "declares
     * sub_keys" instead of "class === authored", because a sub_keys option's
     * OWN top-level class is legitimately something else (Polylang's
     * `polylang`/Yoast's `wpseo` are both 'env': excluded whole, except the
     * named sub-keys carved out below them).
     *
     * This is the engine capability recorded in
     * `adapter-packages/polylang/package/manifest.json`'s own notes,
     * long flagged as missing: "v0's options model classifies a whole
     * option name at once ... there is no way to keep force_lang/
     * default_lang/etc authored while excluding first_activation/version
     * without capturing them too. Building that sub-key classification
     * split is a genuinely separate, unscoped engine capability." This is
     * that capability — a NAMED sub-key of one option blob captured/
     * excluded independently, with apply-side merge into the live blob
     * (Apply::apply_option_sub_keys()) so the undeclared remainder is never
     * clobbered. issue #3211's review comment asked for exactly this: "'exact'
     * [option] reconciliation should be written so per-key ownership can
     * later narrow to sub-key ownership without another format change" —
     * `sub_keys` on an ordinary options.<name> rule IS that narrowing, not
     * a parallel format.
     *
     * @return array<string, array{class:string, sub_keys:array<string,array>}>
     */
    public function sub_keyed_options(): array {
        return $this->exact_option_resolver()->sub_keyed();
    }

    /**
     * issue #3264 (owner ruling, fork A, issue comment 9fd882a6): "a manifest-
     * level dynamic-name resolution primitive... one new primitive, reusable
     * for any future active-theme-bound option, instead of a second bespoke
     * path beside nav_menu_locations." theme_mods_<stylesheet> is the proven
     * case (see `platform/adapter-library/core/manifest.json`'s own
     * declaration + note) but this
     * section is deliberately not theme_mods-specific: any manifest may
     * declare an entry under any key.
     *
     * Shape: `{"<declaration key>": {"prefix": "...", "resolver": "...",
     * "sub_keys": {...}}}`. `prefix` + the resolver's live value (supplied
     * by the CALLER — Capture.php/Apply.php, which already call get_option()
     * freely; this class stays WordPress-free/offline-testable by design,
     * same posture as every other Policy.php method) concatenate to the one
     * option name that is authored-eligible right now (e.g. "theme_mods_" .
     * "storefront" = "theme_mods_storefront"). `sub_keys` is the ordinary
     * sub_keys grammar (Policy::sub_keyed_options()'s own shape,
     * Apply::apply_option_sub_keys()'s own merge-into-live-blob machinery),
     * applied against that ONE resolved name — no new capture/apply
     * machinery, only a new way to find the option's own NAME.
     *
     * Every OTHER live option name sharing the same `prefix` (a theme_mods_*
     * row for a theme that is not currently active) is env-local residue by
     * this SAME declaration, per the ruling's own required semantics — see
     * is_dynamic_option_residue() below, the query surface a caller uses to
     * recognize and skip such a row (never unclassified-pending, never
     * captured) without a second, separately-maintained exclusion list.
     *
     * First-declaring-manifest wins per declaration key, matching this
     * class's own established enumeration precedence elsewhere (no shipped
     * manifest is expected to collide on a key here — only the platform-owned
     * core manifest is expected to ever declare theme_mods — but the tie-break is defined
     * for the same reason it is everywhere else in this file: consistency,
     * not because a real collision is anticipated).
     *
     * @return array<string, array{prefix:string, resolver:string, sub_keys:array<string,array>, autoload:?string}>
     */
    public function dynamic_options(): array {
        return $this->dynamic_option_resolver()->dynamic_options();
    }

    /**
     * Resolve one declared dynamic_options entry against a caller-supplied
     * resolved value (e.g. the live active stylesheet slug for the
     * "active_stylesheet" resolver) into the one concrete option name that
     * is authored-eligible right now, plus its sub_keys rule. 'class' is
     * always 'env' — a dynamic_options row's own containing blob is, by
     * definition, mostly environment-local except its declared sub_keys,
     * the identical "whole value env, named sub-keys authored" shape
     * sub_keyed_options() already uses for polylang/wpseo; not made
     * manifest-declarable since no other value has ever been a real,
     * grounded need (widen this the day one is).
     *
     * @return ?array{name:string, class:string, sub_keys:array<string,array>, autoload:?string}
     */
    public function resolve_dynamic_option(string $key, string $resolvedValue): ?array {
        return $this->dynamic_option_resolver()->resolve_dynamic_option($key, $resolvedValue);
    }

    /**
     * True when $liveName shares a declared dynamic_options entry's prefix
     * but is NOT the one name resolve_dynamic_option() would currently
     * produce for that same entry — a theme_mods_* row for a theme that
     * used to be active, kept by WordPress itself so nothing is lost if the
     * site switches back (confirmed empirically, issue #3264: this is the same
     * shape of residue as the nested sidebars_widgets/wp_classic_sidebars
     * theme-switch bookkeeping already excluded in
     * `platform/adapter-library/core/manifest.json`'s own note).
     * $resolvedValues maps resolver name => this environment's own
     * live value (e.g. `['active_stylesheet' => get_option('stylesheet')]`)
     * — plural because a future second resolver is anticipated by the
     * ruling's own "reusable for any future active-theme-bound option"
     * framing, not because more than one exists yet.
     *
     * @param array<string,string> $resolvedValues
     */
    public function is_dynamic_option_residue(string $liveName, array $resolvedValues): bool {
        return $this->dynamic_option_resolver()->is_dynamic_option_residue($liveName, $resolvedValues);
    }

    /**
     * The lookup Apply::option_apply_target() needs at actual apply time:
     * given a captured document's own option key, find the one
     * dynamic_options declaration (if any) it currently, EXACTLY resolves
     * to on THIS environment, and return its sub_keys rule — or null if
     * $name matches no declared prefix, or matches one but is NOT the
     * currently-resolved row (residue; see is_dynamic_option_residue() —
     * never guessed at, never silently applied to the wrong theme's own
     * row). Safe to require an exact match here specifically because
     * Apply::apply()'s own theme-mismatch refuse-gate (issue #3216) already
     * guarantees the target's active theme matches what was captured by
     * the time this method is ever reached (the identical invariant
     * assign_locations()/nav_menu_locations already depends on) — this is
     * NOT the method RepositoryAuthorization::authorize_options() uses;
     * see dynamic_option_rule_for_prefix() below for why authorization
     * needs a looser check.
     *
     * @param array<string,string> $resolvedValues
     * @return ?array{class:string, sub_keys:array<string,array>, autoload:?string}
     */
    public function dynamic_option_rule_for_name(string $name, array $resolvedValues): ?array {
        return $this->dynamic_option_resolver()->dynamic_option_rule_for_name($name, $resolvedValues);
    }

    /**
     * The lookup RepositoryAuthorization::authorize_options() needs
     * instead — deliberately LOOSER than dynamic_option_rule_for_name()
     * above: matches $name against a declared prefix alone, independent of
     * which theme is active on THIS environment right now. Authorization
     * runs as part of RepositoryCompiler::compile(), which BOTH `wp wprism
     * deploy` and `wp wprism apply` go through — including deploy itself, the
     * command that reconciles a theme mismatch in the first place (issue #3216).
     * Requiring an exact match here would make deploy unable to compile the
     * very repository it needs to read to know which theme to switch to, a
     * genuine circular dependency (caught live, not by inspection: `wp wprism
     * deploy` itself refused with 'theme_mods_<captured-theme>' unclassified
     * while the target was still on its PREVIOUS theme). Authorization's own
     * checks (declared sub_keys class, autoload) are already theme-agnostic
     * in substance — they validate the DECLARATION, not the live
     * environment — so this method never needed the exact-match constraint
     * dynamic_option_rule_for_name() correctly enforces for the different
     * question (never write to the wrong theme's own row), which only
     * matters once actual mutation is about to happen, well after issue #3216's
     * own refuse-gate has already run.
     *
     * @return ?array{class:string, sub_keys:array<string,array>, autoload:?string}
     */
    public function dynamic_option_rule_for_prefix(string $name): ?array {
        return $this->dynamic_option_resolver()->dynamic_option_rule_for_prefix($name);
    }

    /**
     * Keep Policy::with_option_autoload() on Policy: cross-manifest grammar
     * validators also use that public compatibility primitive.  The resolver
     * receives only this narrow normalizer, never a Policy/service locator.
     */
    private function dynamic_option_resolver(): DynamicOptionResolver {
        return new DynamicOptionResolver(
            $this->manifests,
            static fn(array $rule, array $source): array => self::with_option_autoload($rule, $source)
        );
    }

    /**
     * Every declared table rule, keyed by unprefixed table name, merged
     * across manifests (last pinned manifest declaring a given table wins,
     * matching table_rule()/declared_table_details()) with site policy
     * overrides applied last. TableGraph filters this by `class` (row-shaped
     * "authored_snapshot" vs attached-meta "authored_snapshot_meta" vs the
     * honest-intent-only "authored_typed_snapshot_post_v1" markers that have
     * no engine effect) for Snapshot and the other typed-table consumers.
     * This accessor just answers "what did every pinned
     * manifest + this site's own policy say about tables," mirroring
     * authored_options()'s shape for the tables section.
     */
    public function declared_tables(): array {
        return $this->table_declaration_resolver()->tables();
    }

    /**
     * Thin compatibility facades over ManifestGrammar (issue #3348 first
     * extraction slice — the pure table/widget declaration grammar). Kept so
     * existing external callers (Snapshot.php's live schema re-checks) need
     * no change while this decomposition proceeds; Policy's own internal
     * callers below (closed_vocabularies()) call ManifestGrammar directly
     * rather than through these facades; the load-time table/widget
     * enumerators now live on ManifestGrammar as well.
     *
     * @return list<string>
     */
    public static function natural_key_columns(array $decl): array {
        return ManifestGrammar::natural_key_columns($decl);
    }

    public static function assert_table_grammar(string $table, mixed $decl, ?string $source = null): void {
        ManifestGrammar::assert_table_grammar($table, $decl, $source);
    }

    /** @return ?array{name:string,rule:array} effective EAV sidecar for one row table */
    public function attached_meta_table_for_owner(string $ownerTable): ?array {
        return $this->table_declaration_resolver()->attached_meta_table_for_owner($ownerTable);
    }

    /** Fresh because site and manifests stay publicly mutable in offline fixtures. */
    private function table_declaration_resolver(): TableDeclarationResolver {
        return new TableDeclarationResolver($this->site, $this->manifests);
    }

    /** Closed widget type registry. Last pinned manifest wins per type. */
    public function widget_types(): array {
        return $this->widget_type_resolver()->types();
    }

    /**
     * The declaration source for the same last-pinned-wins widget rule that
     * widget_types() returns. Reporting callers must not reconstruct a
     * different precedence walk merely to name its owner.
     *
     * @return array{rule:?array,source:?string}
     */
    public function widget_type_rule_details(string $type): array {
        return $this->widget_type_resolver()->details($type);
    }

    /** Fresh because manifests stay publicly mutable in offline fixtures. */
    private function widget_type_resolver(): WidgetTypeResolver {
        return new WidgetTypeResolver($this->manifests);
    }

    /** blockName => list of {path, kind, type} rules, merged across manifests. */
    public function block_attr_rules(): array {
        return $this->content_attribute_rule_resolver()->block_attr_rules();
    }

    /** @return array{rule:?array,source:?string} */
    public function block_attr_rule_details(string $block): array {
        return $this->content_attribute_rule_resolver()->block_attr_rule_details($block);
    }

    /**
     * `attr_id_codecs` (WP-6.1): blockName => attribute path => {id_type},
     * merged across manifests on exactly block_attr_rules()'s precedence.
     *
     * The two are read together by Blocks::capture_rewrite()/apply_rewrite()
     * and must therefore be projected together — see
     * AttrIdCodecGrammar::rules() for why replacement is whole-block.
     *
     * @return array<string,array<string,array{id_type:string}>>
     */
    public function attr_id_codec_rules(): array {
        return AttrIdCodecGrammar::rules($this->manifests);
    }

    /**
     * `body_refs` (WP-6.5): the declared reference paths for ONE post type in
     * `json` body mode, or null when that type declares none.
     *
     * Per post type rather than the whole section, because all three consumers
     * (PostCapture, PostMaterializer, Lint) work one post at a time and reading
     * the whole map would make each of them do the lookup this method already
     * does. Null and not `[]` when nothing is declared: `body_mode()` returning
     * `json` with no rule beneath it is a state validate_body_refs() refuses at
     * load, so a null here means the caller is on a body mode that has no paths
     * — a fact worth being able to distinguish from an empty path list.
     *
     * @return array{json_refs:list<array<string,mixed>>,sentinels:array<string,list<string>>,url_rebinding?:true,pii_paths?:list<string>}|null
     */
    public function body_ref_rule(string $postType): ?array {
        return BodyRefGrammar::rules($this->manifests)[$postType] ?? null;
    }

    /**
     * `column_codecs` (WP-6.1): the declared codecs for ONE typed table,
     * column => {container, leaves}.
     *
     * Per table rather than the whole section, because both consumers
     * (TypedTableCapture, TypedTableMaterializer) work one table at a time and
     * a whole-section map would hand each of them declarations about tables
     * they are not capturing.
     *
     * @return array<string,array{container:string,leaves:string}>
     */
    public function column_codec_rules(string $table): array {
        return ColumnCodecGrammar::rules_for($this->manifests, $table);
    }

    /**
     * `shortcode_attrs` (issue #3259): the shortcode twin of `block_attrs()`
     * above, same precedence (last pin wins per tag name, a structural
     * fact about a shortcode's own attribute grammar, not a site-local
     * policy choice — no site.wprism.json override, mirroring block_attrs'
     * own reasoning exactly), but a flatter rule shape: tagName => list of
     * named path rules or closed positional/alternate lookup rules. Ordinary
     * named refs use {path, kind, cast?} — no `type`, unlike block_attrs — a
     * shortcode attribute value is always flat text in the source (never
     * a native JSON array the way a block attr can be), so `cast: "csv"`
     * alone signals "comma-joined id list" (WordPress's own convention
     * for gallery's `ids`/`include`/`exclude` attributes, confirmed by
     * reading gallery_shortcode() directly, not assumed); anything not
     * csv-cast is a plain scalar id. `path` names a shortcode ATTRIBUTE
     * (not a JSON path; shortcode attributes are already a flat key=value
     * grammar, `shortcode_parse_atts()`'s own return shape). Alternate rules
     * bind plugin-public identifiers (currently a decimal post-meta value or
     * a fixed lowercase-hex post-meta prefix) to the same canonical post
     * token while refusing missing or ambiguous owners.
     */
    public function shortcode_attr_rules(): array {
        return $this->content_attribute_rule_resolver()->shortcode_attr_rules();
    }

    /** Fresh because manifests stay publicly mutable in offline fixtures. */
    private function content_attribute_rule_resolver(): ContentAttributeRuleResolver {
        return new ContentAttributeRuleResolver($this->manifests);
    }

    /**
     * `taxonomies.<tax>.description_refs` (spec v0.8; typed
     * serialized-description rewriting): declares that a taxonomy's
     * term_taxonomy.description column holds PHP-
     * serialized data (Polylang's post_translations/term_translations
     * `{lang_slug: local_id}` shape, verified byte-for-byte) with ref-typed
     * values reachable via the ordinary json_refs primitive at path "$.*"
     * (Capture::term_description() / Apply::encode_description() own
     * deciding how to (un)serialize; this only returns the declared rule).
     *
     * Manifest-only structural fact about the taxonomy's OWN data shape
     * (like block_attrs is a structural fact about a block type's shape),
     * not a site-local policy choice, so
     * — unlike options/post_meta/term_meta — there is no site.wprism.json
     * policy override. This also sidesteps a real naming collision:
     * site.wprism.json's policy.taxonomies is already the flat taxonomy-scope
     * LIST (Policy::taxonomies() below); reusing that key for a name-keyed
     * rule map would silently shadow it instead of erroring, since PHP's
     * array access on a list by an unknown string key just returns null.
     *
     * Duplicate declarations must normalize identically; load() and
     * from_snapshot() reject pin-order-dependent shapes before this accessor
     * can run.
     *
     * @return ?array{json_refs:array,key_refs:?array,legacy_flat_map:bool}
     */
    public function description_reference_rule(string $tax): ?array {
        return $this->taxonomy_description_reference_resolver()->resolve($tax);
    }

    /** Fresh because manifests stay publicly mutable in offline fixtures. */
    private function taxonomy_description_reference_resolver(): TaxonomyDescriptionReferenceResolver {
        return new TaxonomyDescriptionReferenceResolver($this->manifests);
    }

    /**
     * Exact manifest opt-in for wp_terms.term_group, which Polylang uses as
     * native language order. Dynamic rules never claim the shared column.
     */
    public function taxonomy_term_group_is_authored(string $tax): bool {
        foreach ($this->manifests as $manifest) {
            if (($manifest['taxonomies'][$tax]['term_group'] ?? null) === 'authored') {
                return true;
            }
        }
        return false;
    }

    /**
     * An adapter may exempt a non-reference description from the generic
     * serialized-value lint only after validating its complete native shape.
     */
    public function taxonomy_description_lint_rule(string $taxonomy, mixed $description): ?array {
        foreach ($this->interpreters() as $name => $interpreter) {
            if (!method_exists($interpreter, 'taxonomy_description_lint_rule')) {
                continue;
            }
            $rule = $interpreter->taxonomy_description_lint_rule($taxonomy, $description);
            if ($rule === null) {
                continue;
            }
            if (!is_array($rule) || array_is_list($rule)
                || array_keys($rule) !== ['lint_ok'] || $rule['lint_ok'] !== true) {
                throw new \RuntimeException(
                    "wprism: interpreter '$name' taxonomy_description_lint_rule() must return null "
                    . "or exactly ['lint_ok' => true]"
                );
            }
            return $rule;
        }
        return null;
    }

    /** @deprecated Use description_reference_rule(); retained for extensions. */
    public function description_refs_for_taxonomy(string $tax): ?array {
        return $this->description_reference_rule($tax);
    }

    /**
     * The canonical keyspace for a taxonomy relationship's `object_id`.
     *
     * `wp_term_relationships.object_id` is deliberately ambiguous at the
     * database level: WordPress posts and terms are minted from independent
     * counters, so the same integer can name one of each. A taxonomy's
     * runtime `object_type` is useful for choosing WHICH post types a
     * post-keyspace taxonomy belongs to, but it is not a portable statement
     * of whether the relationship rows themselves belong to posts or terms:
     * plugins may use arbitrary object-type strings, and an engine sentinel
     * such as literal `term` would make that plugin implementation detail a
     * hidden part of WPrism's wire contract.
     *
     * A manifest can therefore declare `object_keyspace: "post"|"term"`
     * under an exact `taxonomies.<name>` rule or a matching
     * `taxonomy_patterns` rule. All declarations that apply to a concrete
     * taxonomy must agree. Omission preserves legacy post-only behavior for
     * an ordinary runtime post taxonomy, so existing manifests and already-
     * canonical state keep their exact bytes. A runtime taxonomy whose
     * object_type contains `term` is NOT a legacy post taxonomy, however:
     * term and mixed keyspaces must declare this field or the resolver
     * refuses rather than reviving the old sentinel inference. Capture,
     * lint, compile, apply, and fresh-process verification all ask this one
     * resolver.
     *
     * @return "post"|"term"
     */
    public function taxonomy_object_keyspace(string $tax, ?array $runtimeObjectTypes = null): string {
        return $this->taxonomy_keyspace_resolver()->resolve($tax, $runtimeObjectTypes);
    }

    /** Fresh because manifests stay publicly mutable in offline fixtures. */
    private function taxonomy_keyspace_resolver(): TaxonomyKeyspaceResolver {
        return new TaxonomyKeyspaceResolver($this->manifests, $this->taxonomy_pattern_resolver());
    }

    /**
     * `taxonomies.<tax>.object_type_from_option` (issue #3280): declares that
     * $tax's registered object_type is additionally, DYNAMICALLY driven by
     * sub_keys-declared option values. The original object form names one
     * array of object-type slugs; a declaration list can also name a boolean
     * gate with `object_types_when_truthy` (Polylang's `media_support` adds
     * `attachment`). Pure declaration data — WHICH option, WHICH sub-key,
     * and which fixed types a true gate enables — never a live value; this
     * class stays WordPress-free
     * by design, the identical "class holds the declaration, caller does
     * the live read" split dynamic_options() above already uses. Apply's
     * own taxes_by_object_type() consults this, then resolves it against
     * the CURRENT apply's own compiled tree (see Apply::
     * option_driven_object_type()'s own comment for why the compiled
     * tree, not a live database read, is the correct — and safer —
     * source: phase-2's own stable-sort ordering can finalize a brand-new
     * post before the declaring option's sub_keys merge in the SAME
     * apply, so "the committed row" is not yet a fixed point at the
     * moment a live read would happen). The declared sub-key is assumed
     * to hold plain, non-ref-typed values (post-type/taxonomy slugs are
     * inherently portable, unlike ids) — validate_object_type_option_refs()
     * enforces that at load time.
     *
     * Deliberately a DIFFERENT primitive than taxonomy_pattern_rules()
     * (task #92), not a mode of it: that mechanism answers "is $tax
     * registered at ALL this request" (a yes/no gate for a taxonomy
     * get_taxonomy() cannot find yet); this answers "what ELSE does an
     * already-found taxonomy's object_type cover" — additive, never a
     * substitute registration source, and consulted regardless of whether
     * get_taxonomy() succeeded or the pattern fallback did.
     *
     * Same first-manifest-wins, exact-name lookup as
     * description_refs_for_taxonomy() immediately above. Apply consumes the
     * complete list so no compiled contribution is discarded.
     *
     * @return list<array{option:string, sub_key:string, object_types_when_truthy?:list<string>}>
     */
    public function object_type_option_refs(string $tax): array {
        return $this->taxonomy_object_type_option_resolver()->resolve_all($tax) ?? [];
    }

    /** Fresh because manifests stay publicly mutable in offline fixtures. */
    private function taxonomy_object_type_option_resolver(): TaxonomyObjectTypeOptionResolver {
        return new TaxonomyObjectTypeOptionResolver($this->manifests);
    }

    public function post_types(): array {
        $exact = $this->site['policy']['post_types'] ?? ['post', 'page', 'attachment'];
        foreach ($this->site['policy']['scope']['post_type'] ?? [] as $name => $rule) {
            if (($rule['class'] ?? null) === 'authored') {
                $exact[] = (string) $name;
            }
        }
        return array_values(array_unique($exact));
    }

    /** @return string[] post types for which a pinned manifest declares a whole-type contract. */
    public function declared_post_types(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            $out = array_merge($out, array_keys($m['post_types'] ?? []));
        }
        return array_values(array_unique($out));
    }

    /**
     * Declared direct child post types for a parent CPT.  This is a
     * structural manifest fact about wp_posts.post_parent, not a deletion
     * capability or a request to discover an arbitrary post hierarchy.
     *
     * Pinned manifests compose additively for this relationship because an
     * adapter may name additional child types for the same parent.  Sorting
     * makes the result independent of manifest pin order and safe for query
     * construction and future scoped dependency closure.
     *
     * @return string[]
     */
    public function child_post_types(string $postType): array {
        return (new PostTypeRelationResolver($this->manifests))->children($postType);
    }

    /**
     * Declared direct parent post types for a child CPT.  A child row has one
     * wp_posts.post_parent value at a time, while this type-level reverse
     * lookup intentionally permits more than one adapter-declared parent
     * type.  Callers that need a concrete relationship still inspect the
     * local parent id; this method never manufactures one.
     *
     * @return string[]
     */
    public function parent_post_types(string $postType): array {
        return (new PostTypeRelationResolver($this->manifests))->parents($postType);
    }

    /**
     * Return an input type set plus every type connected to it by declared
     * parent/child edges. Traversal is deliberately bidirectional and
     * transitive: scoped-operation callers expand parent/child dependencies
     * through this API, closing a bounded declared scope without a
     * target-wide post query. Unknown input types survive as isolated
     * members; undeclared edges are never inferred.
     *
     * @param array<int,mixed> $postTypes
     * @return string[] lexical, duplicate-free order
     */
    public function post_type_relation_closure(array $postTypes): array {
        return (new PostTypeRelationResolver($this->manifests))->closure($postTypes);
    }

    /** @return string[] taxonomies for which a pinned manifest declares a structural contract. */
    public function declared_taxonomies(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            $out = array_merge($out, array_keys($m['taxonomies'] ?? []));
        }
        return array_values(array_unique($out));
    }

    /**
     * taxonomy_patterns (task #92): dynamic-taxonomy-NAME scope, the
     * mirror-in-INTENT (not in mechanism) of option_patterns/meta_patterns.
     * Deliberately NOT added to PATTERN_KEYS/rule() above: that map's shape
     * is "classify a single key some OTHER enumeration already produced"
     * (an options/post_meta row is discovered some other way, THEN
     * classified by pattern); taxonomy scope has no outer enumeration to
     * piggyback on — answering "which taxonomy NAMES are in scope" is
     * itself the job, so the pattern consultation has to happen inside
     * taxonomies() below, a structurally different shape by necessity, not
     * an inconsistency with the existing mechanism.
     *
     * @return array<int, array{match:string, object_type:string[], update_count_callback:?string, object_keyspace:string, source:string}>
     */
    public function taxonomy_pattern_rules(): array {
        return $this->taxonomy_pattern_resolver()->rules();
    }

    /**
     * The unambiguous declared object_type for every taxonomy_pattern
     * matching $tax, or null. Consulted by Capture's/Apply's taxes_by_object_type()
     * ONLY as a fallback when get_taxonomy() fails — WooCommerce registers
     * pa_* taxonomies from a DB table read on `init`, which already ran
     * before Snapshot's own phase-1 write of that table's row this same
     * request/apply — so a taxonomy_patterns-matched name can be genuinely
     * in scope (its term_taxonomy rows exist, found live) without being
     * registered yet THIS request. A manifest-declared object_type is a
     * fact about the PLUGIN's own registration code (confirmed against
     * WooCommerce's actual source for pa_*: object_type defaults to
     * `['product']`), sidestepping the need for get_taxonomy() to have
     * caught up. get_taxonomy() stays authoritative whenever it succeeds —
     * this is a narrow fallback for one specific timing gap, never a
     * general override.
     */
    public function pattern_object_type(string $tax): ?array {
        return $this->taxonomy_pattern_resolver()->match($tax)['object_type'] ?? null;
    }

    /**
     * The manifest-declared object_type for $tax when `get_taxonomy()` cannot
     * answer: the exact `taxonomies.<tax>.object_type` declaration first (a
     * fact about the plugin's own registration, needed under the isolated
     * control bootstrap where no plugin is loaded — refresh-export's strict
     * read-only ownership resolution), then the `taxonomy_patterns` fallback
     * above. Same authority order as get_taxonomy() itself: exact
     * registration before dynamic-name registration. Never consulted while
     * get_taxonomy() succeeds.
     */
    public function declared_object_type(string $tax): ?array {
        return $this->taxonomy_pattern_resolver()->declaredRegistration($tax)['object_type']
            ?? $this->pattern_object_type($tax);
    }

    /** The exact-then-pattern count callback, mirroring declared_object_type(). */
    public function declared_update_count_callback(string $tax): ?string {
        return $this->taxonomy_pattern_resolver()->declaredRegistration($tax)['update_count_callback']
            ?? $this->pattern_update_count_callback($tax);
    }

    /** Reviewed hierarchy fact used only when the live registry cannot answer. */
    public function declared_taxonomy_hierarchical(string $tax): ?bool {
        $exact = $this->taxonomy_pattern_resolver()->declaredRegistration($tax);
        if ($exact !== null && $exact['hierarchical'] !== null) {
            return $exact['hierarchical'];
        }
        return $this->taxonomy_pattern_resolver()->match($tax)['hierarchical'] ?? null;
    }

    /**
     * Registered taxonomy state can lag a taxonomy_patterns-backed table
     * write until the next request. A version-pinned manifest may declare
     * the plugin's real count callback so Apply can honor the identical
     * contract during that one timing window instead of guessing a COUNT.
     */
    public function pattern_update_count_callback(string $tax): ?string {
        return $this->taxonomy_pattern_resolver()->match($tax)['update_count_callback'] ?? null;
    }

    /** taxonomy_patterns stores an undelimited PCRE fragment by contract. */
    public static function taxonomy_pattern_matches(string $match, string $tax): bool {
        return TaxonomyPatternResolver::matches($match, $tax);
    }

    /** Fresh because manifests stay publicly mutable in offline fixtures. */
    private function taxonomy_pattern_resolver(): TaxonomyPatternResolver {
        return new TaxonomyPatternResolver($this->manifests);
    }

    /**
     * The full in-scope taxonomy list: site.wprism.json's exact
     * `policy.taxonomies` PLUS every taxonomy name actually present in
     * wp_term_taxonomy that matches a manifest's taxonomy_patterns regex
     * (WooCommerce's pa_* — task #92). Empirically confirmed live (not
     * just reasoned) which source is timing-safe: after a raw-SQL insert
     * into wp_woocommerce_attribute_taxonomies (Snapshot's own phase 1),
     * get_taxonomy('pa_x') still returns false for the REST of that SAME
     * request/process, but a term_taxonomy row for the new taxonomy (ALSO
     * written raw-SQL in phase 1, unconditionally, no registration check)
     * is immediately visible to a live SELECT DISTINCT — so expansion
     * reads LIVE TABLE DATA, never get_taxonomies()'s in-memory registry,
     * which is exactly the thing that's stale mid-request.
     *
     * SCOPE-GATED, not a blanket widen: only names that match a DECLARED
     * pattern are ever added to the exact list — never every distinct
     * taxonomy the database happens to hold. This is the identical posture
     * task #73 established for ref-typed options (a real-but-out-of-scope
     * target aborts loudly rather than silently entering canonical state);
     * silently widening scope to "whatever's in the database" would be the
     * same failure class in the opposite direction, and is deliberately
     * not what this does. When no manifest declares taxonomy_patterns,
     * this method's behavior (and its DB query) is byte-for-byte unchanged
     * from before task #92 — an empty pattern list is a fast exact-return,
     * no query at all, so every manifest that doesn't use this pays zero
     * cost.
     *
     * This is the one deliberate exception to this class's DB-free-ness
     * elsewhere (Snapshot.php's own docblock states that purity as a
     * layering principle): unlike a post_meta/option KEY (already
     * enumerated by its caller before Policy::rule() is ever consulted),
     * the taxonomy SCOPE LIST has no outer enumeration of its own to
     * piggyback on. Centralizing the live-DB expansion HERE — rather than
     * duplicating a DISTINCT-query-and-filter snippet at every one of
     * taxonomies()'s several call sites in Capture.php/Apply.php — means
     * every caller (scope_terms(), taxes_by_object_type()'s input,
     * Capture's unscoped-ref scope check, Apply::rebuild()'s recount list)
     * gets pattern support for free with zero changes of their own beyond
     * this one method.
     */
    public function taxonomies(): array {
        $exact = $this->site['policy']['taxonomies'] ?? ['category', 'post_tag'];
        foreach ($this->site['policy']['scope']['taxonomy'] ?? [] as $name => $rule) {
            if (($rule['class'] ?? null) === 'authored') {
                $exact[] = (string) $name;
            }
        }
        $exact = array_values(array_unique($exact));
        $patterns = $this->taxonomy_pattern_rules();
        if (!$patterns) {
            return $exact;
        }
        global $wpdb;
        $wpdb->last_error = '';
        $liveRows = $wpdb->get_results(
            "SELECT BINARY taxonomy AS taxonomy FROM {$wpdb->term_taxonomy} "
            . 'GROUP BY BINARY taxonomy ORDER BY BINARY taxonomy ASC LIMIT '
            . (self::MAX_DISCOVERED_TAXONOMIES + 1),
            ARRAY_A
        );
        if (!is_array($liveRows)
            || !array_is_list($liveRows)
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('wprism: taxonomy-pattern scope discovery read failed');
        }
        if (count($liveRows) > self::MAX_DISCOVERED_TAXONOMIES) {
            throw new \RuntimeException('wprism: taxonomy-pattern scope discovery exceeds the bounded taxonomy limit');
        }
        $live = [];
        foreach ($liveRows as $position => $row) {
            $tax = is_array($row) && array_keys($row) === ['taxonomy'] ? $row['taxonomy'] : null;
            if (!is_string($tax)
                || preg_match('/^[a-z0-9_-]{1,32}$/D', $tax) !== 1
                || isset($live[$tax])) {
                throw new \RuntimeException(
                    "wprism: taxonomy-pattern scope discovery returned a malformed/duplicate row at position $position"
                );
            }
            $live[$tax] = $tax;
        }
        $matched = [];
        foreach (array_values($live) as $tax) {
            if (in_array($tax, $exact, true)) {
                continue;
            }
            foreach ($patterns as $pat) {
                if (self::taxonomy_pattern_matches($pat['match'], $tax)) {
                    $matched[] = $tax;
                    break;
                }
            }
        }
        return array_values(array_unique(array_merge($exact, $matched)));
    }

    /**
     * option_name_refs (task #93): options discovered by NAME PATTERN, not
     * exact-key whitelist — for options whose NAME embeds another declared
     * table's local id (WooCommerce's woocommerce_<method_id>_<instance_id>
     * _settings, instance_id being a woocommerce_shipping_zone_methods
     * row's own pk). Pure manifest merge (flat concatenated list, manifest
     * pin order then declaration order — same first-match-wins semantics
     * as the PATTERN_KEYS fallback loop in rule() above); the live
     * wp_options NAME scan this declares is OptionsCapture::capture()'s job,
     * not this accessor's — mirrors declared_tables()/block_attr_rules()'s
     * existing split between "what did manifests declare" (pure, here) and
     * "what do we do about it against a live environment" (the DB-touching
     * caller).
     *
     * A DELIBERATE sibling of option_patterns, not a variant of it:
     * option_patterns is consulted only to CLASSIFY a key some other
     * enumeration already produced (Policy::rule()'s fallback loop);
     * OptionsCapture::capture()'s exact authored loop is whitelist-only and NEVER consults
     * option_patterns for DISCOVERY (confirmed by reading it — r1b-shop.md's
     * own finding). option_name_refs entries drive their OWN discovery scan
     * because these rows are otherwise invisible to every existing option
     * mechanism. Consumers must resolve a concrete name through
     * option_name_ref_match_details(); that resolver rejects malformed names
     * and every concrete overlap rather than applying pin/declaration order.
     *
     * @return array<int, array{match:string, id_kind:string, class:string, malformed_match?:string, json_refs?:array, key_refs?:array}>
     */
    public function option_name_ref_rules(): array {
        return $this->option_name_reference_resolver()->rules();
    }

    /**
     * Resolve one live option name against the complete option-name-ref
     * grammar.  This is deliberately one resolver rather than separate
     * first-match loops in capture, preservation, and apply: two declarations
     * owning the same name (including declarations for different id_kinds)
     * are ambiguous and must never be selected by pin/declaration order.
     *
     * `malformed_match` is an optional manifest-owned sibling namespace for
     * names which look like this rule's family but contain an invalid local
     * id (Woo's leading-zero instance ids are the first shipped example).
     * A malformed candidate is refused even when another broad declaration
     * would otherwise happen to match it.
     *
     * @return ?array{rule:array,matches:array,source:string}
     */
    public function option_name_ref_match_details(string $realOptionName): ?array {
        return $this->option_name_reference_resolver()->match_details($realOptionName);
    }

    /** @return int|null only an exact positive decimal local id is accepted. */
    public static function strict_positive_local_id($value): ?int {
        return OptionNameReferenceResolver::strict_positive_local_id($value);
    }

    /**
     * Resolve a canonical option name containing one identity token against
     * option_name_refs without consulting a target ledger. Replacing the
     * token with a representative positive integer lets the declaration's
     * existing numeric-name regex decide ownership, while checking id_kind
     * separately prevents a hand-edited token of the wrong keyspace from
     * borrowing that authorization. The shared concrete-name resolver also
     * rejects same-kind and cross-kind overlaps.
     *
     * @return array{rule:?array, source:?string}
     */
    public function canonical_option_name_ref_details(string $name): array {
        return $this->option_name_reference_resolver()->canonical_details($name);
    }

    /**
     * The sole option_name_refs rule whose `match` regex matches
     * $realOptionName (a name with any embedded id already in its REAL,
     * numeric form — never a token) — or null. Shared by Capture's
     * discovery pass (matching a live wp_options row's actual name),
     * Snapshot preservation, and Apply's apply-direction path (matching the
     * DETOKENIZED name, i.e. after splicing the resolved local id back in).
     * Ambiguous or malformed names throw, so capture and apply cannot
     * disagree about which rows this mechanism owns.
     */
    public function match_option_name_ref(string $realOptionName): ?array {
        return $this->option_name_reference_resolver()->match($realOptionName);
    }

    /** Keep Policy's public autoload normalizer as the resolver's sole port. */
    private function option_name_reference_resolver(): OptionNameReferenceResolver {
        return new OptionNameReferenceResolver(
            $this->manifests,
            static fn(array $rule, array $source): array => self::with_option_autoload($rule, $source)
        );
    }

    /**
     * Resolve every declared interpreter name to a loaded, instantiated class.
     *
     * Public for the same reason regenerators() below is: a second caller
     * outside this class needs it. `wprism manifest-validate` (issue #3327) calls it
     * immediately after each successful load, because a manifest naming an
     * interpreter file that does not exist — or a file that does not define the
     * contract class — is a manifest that is wrong offline, and leaving that
     * discovery to the first live meta lookup meant an offline check reported
     * `ok` for a declaration no target could ever run. Resolution is a pure
     * file-system + class-contract question about the manifests directory the
     * checker was handed, so it belongs to the offline half.
     *
     * @return array<string, object>
     */
    public function interpreters(): array {
        if ($this->interpreterInstances !== null) {
            return $this->interpreterInstances;
        }
        // Interpreter hooks consume the same bounded observation SDK as
        // providers; the executable loader must supply it without relying on
        // a prior provider invocation or the additive classmap fallback.
        require_once __DIR__ . '/../Adapter/ProviderSdk.php';
        $this->interpreterInstances = [];
        foreach ($this->manifests as $m) {
            $name = $m['interpreter'] ?? null;
            if ($name === null || isset($this->interpreterInstances[$name])) {
                continue;
            }
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_-]*$/D', $name)) {
                throw new \RuntimeException("wprism: manifest '{$m['name']}' declares invalid interpreter name '$name'");
            }
            $file = $this->adapter_runtime_path((string) $m['name'], 'interpreters', $name);
            if (!is_file($file)) {
                throw new \RuntimeException(
                    "wprism: manifest '{$m['name']}' wants interpreter '$name' but $file is missing — "
                    . 'interpreter code ships with its manifest, not the engine'
                );
            }
            if (!class_exists(ArtifactPolicyIdentity::class, false)) {
                require_once __DIR__ . '/ArtifactPolicyIdentity.php';
            }
            $descriptor = ArtifactPolicyIdentity::runtime_component_descriptor(
                $this,
                (string) $m['name'],
                'interpreters',
                $name
            );
            $class = ManifestExecutableLoader::load(
                $descriptor,
                $this->execution_adapter_digest((string) $m['name'])
            );
            if (!method_exists($class, 'post_meta_rule')) {
                throw new \RuntimeException(
                    "wprism: interpreter file $file must define $class with post_meta_rule(string, array): ?array"
                );
            }
            $this->interpreterInstances[$name] = new $class($this);
        }
        return $this->interpreterInstances;
    }

    /**
     * issue #3234 regenerator discovery remains distinct from interpreter
     * discovery: potentially several names arise from post_types{} entries.
     * Once discovered, both kinds cross the same ManifestExecutableLoader
     * identity/provenance boundary as manifest providers; plugin-specific
     * behavior remains in package code and loading mechanics remain engine
     * owned.
     *
     * A declared name resolves through its adapter package's regenerator inventory,
     * which must define \WPrism\Regenerators\<CamelCase(name)> with
     * regenerate(int $localId): void. Any exception it throws is the
     * caller's (Apply::regen_dependencies()) hard-failure signal — there is
     * no success/failure return-value protocol, matching interpreters' own
     * all-or-throw shape.
     *
     * Public, like interpreters() above, and for the same reason: the callers
     * are in other classes — Apply::regen_dependencies() drives it live, and
     * `wprism manifest-validate` resolves it offline so a declared-but-missing
     * regenerator file is refused by the authoring check rather than by the
     * first apply that needs it.
     *
     * @return array<string, object> regenerator name => instance
     */
    public function regenerators(): array {
        if ($this->regeneratorInstances !== null) {
            return $this->regeneratorInstances;
        }
        $this->regeneratorInstances = [];
        foreach ($this->manifests as $m) {
            foreach ($m['post_types'] ?? [] as $postType => $decl) {
                $name = $decl['regen_dependency']['regenerator'] ?? null;
                if ($name === null || isset($this->regeneratorInstances[$name])) {
                    continue;
                }
                if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_-]*$/D', $name)) {
                    throw new \RuntimeException(
                        "wprism: manifest '{$m['name']}' post_types.$postType declares invalid regenerator name '$name'"
                    );
                }
                $file = $this->adapter_runtime_path((string) $m['name'], 'regenerators', $name);
                if (!is_file($file)) {
                    throw new \RuntimeException(
                        "wprism: manifest '{$m['name']}' post_types.$postType wants regenerator '$name' but $file is missing — "
                        . 'regenerator code ships with its manifest, not the engine'
                    );
                }
                if (!class_exists(ArtifactPolicyIdentity::class, false)) {
                    require_once __DIR__ . '/ArtifactPolicyIdentity.php';
                }
                $descriptor = ArtifactPolicyIdentity::runtime_component_descriptor(
                    $this,
                    (string) $m['name'],
                    'regenerators',
                    $name
                );
                $class = ManifestExecutableLoader::load(
                    $descriptor,
                    $this->execution_adapter_digest((string) $m['name'])
                );
                if (!method_exists($class, 'regenerate')) {
                    throw new \RuntimeException(
                        "wprism: regenerator file $file must define $class with regenerate(int \$localId): void"
                    );
                }
                $this->regeneratorInstances[$name] = new $class($this);
            }
        }
        return $this->regeneratorInstances;
    }

    /**
     * Context-aware meta classification: interpreters see the owning
     * entity's full meta map (shadow keys and all) and win over static rules.
     * post_meta_rule() is the required baseline contract; term/user hooks are
     * deliberately optional, so an existing post-only interpreter retains
     * byte-for-byte lookup behavior on the two new dispatch paths.
     */
    public function meta_rule_for_post(string $key, array $allMeta): ?array {
        return $this->meta_rule_for_interpreter_hook('post_meta_rule', 'post_meta', $key, $allMeta);
    }

    public function meta_rule_for_term(string $key, array $allMeta): ?array {
        return $this->meta_rule_for_interpreter_hook('term_meta_rule', 'term_meta', $key, $allMeta);
    }

    public function meta_rule_for_user(string $key, array $allMeta): ?array {
        $rule = $this->meta_rule_for_interpreter_hook('user_meta_rule', 'user_meta', $key, $allMeta);
        if ($rule !== null) {
            UserMetaGrammar::validate_user_meta_rule($rule, "user_meta.$key", self::CLASSES, self::MISSING_USER_MODES);
        }
        return $rule;
    }

    /**
     * issue #3263: a third optional interpreter hook, same contract shape as
     * term/user above, for options whose NAME a manifest's option_namespaces
     * claims but whose per-name classification can't be a static exact/
     * pattern rule (ACF options-page fields: arbitrary field names, ref kind
     * determined by a shadow-key-pointed schema, exactly like post/term meta
     * — see `adapter-packages/acf/package/runtime/interpreters/acf.php`'s
     * option_rule()). $allOptions is
     * the full option-name classification context (mirroring $allMeta's
     * "owning scope, shadow keys and all" shape) — options have no single
     * owning entity to scope the map to. Live capture passes raw wp_options
     * values; immutable-tree callers pass OptionState::classification_values(),
     * which adds only valid v2 witness context to ordinary present values.
     *
     * Routes through option_rule_details_for_option() rather than the plain
     * meta_rule_for_interpreter_hook() every other meta_rule_for_*() uses —
     * caught live
     * (`adapter-packages/acf/tests/live/regress_acf_term_options_fields.sh`'s
     * first run):
     * OptionState::assert_rule_autoload() requires every options rule to
     * declare 'autoload' (or 'preserve'), and the static options path
     * always gets that via with_option_autoload()'s manifest-level
     * option_autoload default; an interpreter-returned rule bypassed it
     * entirely. Only the details() path knows which manifest's interpreter
     * answered, so autoload injection lives there (see its own docblock).
     */
    public function meta_rule_for_option(string $name, array $allOptions): ?array {
        return $this->option_rule_details_for_option($name, $allOptions)['rule'];
    }

    /**
     * Interpreter-aware sibling of owned_option_rule() (issue #3263): same
     * namespace-ownership gate and cross-manifest-ambiguity check, but
     * consulting a declared interpreter's option_rule() before the static
     * options rule. A separate method rather than changing owned_option_rule()
     * itself — that accessor has callers uninterested in live/repository
     * option context (mirrors post_meta_rule()/meta_rule_for_post() staying
     * two separate methods rather than one changing shape underneath its
     * existing callers).
     */
    public function owned_option_rule_via_interpreter(string $name, array $allOptions): ?array {
        $owner = $this->option_namespace($name);
        if ($owner === null) {
            return null;
        }
        $details = $this->option_rule_details_for_option($name, $allOptions);
        if ($details['rule'] !== null && $details['source'] !== 'site.wprism.json'
            && $details['source'] !== $owner['owner']
            && !str_starts_with((string) $details['source'], $owner['owner'] . ' (interpreter')) {
            throw new \RuntimeException(
                "wprism: option '$name' namespace is owned by '{$owner['owner']}' but its classification comes from "
                . "'{$details['source']}' — cross-manifest ownership is ambiguous"
            );
        }
        return $details['rule'];
    }

    /**
     * Optional manifest-owned native materialization for a mixed option.
     * The engine resolves references and enforces the closed sibling registry;
     * only the interpreter paired with an exact declaring manifest may replace
     * the generic SQL merge. The hook executes inside Apply's authored
     * transaction, so a warning, save failure, or postcondition mismatch rolls
     * back with every other canonical mutation.
     */
    public function materialize_option_sub_keys_via_interpreter(
        string $name,
        array $captured,
        array $effectiveRule,
        ?string $effectiveSource,
        string $autoload,
        ?array $targetValue,
        \Closure $lockTargetOption,
        \Closure $finalizeStorage,
        \Closure $restoreStorage,
        ?\Closure $registerRuntimeRestore = null,
        ?\Closure $writeStorage = null,
        ?\Closure $writeRuntimeOption = null
    ): bool {
        $candidate = $this->option_sub_key_interpreter_candidate(
            $name,
            $effectiveRule,
            $effectiveSource,
            'materialize_option_sub_keys',
            'native materialization'
        );
        if ($candidate === null) {
            return false;
        }
        $handled = $candidate['interpreter']->materialize_option_sub_keys(
            $name,
            $captured,
            (array) ($effectiveRule['sub_keys'] ?? []),
            $autoload,
            $targetValue,
            $lockTargetOption,
            $finalizeStorage,
            $restoreStorage,
            $registerRuntimeRestore ?? static function (): void {
                throw new \RuntimeException('wprism: native option runtime restoration registrar is unavailable');
            },
            $writeStorage ?? static function (): void {
                throw new \RuntimeException('wprism: native option engine-owned storage writer is unavailable');
            },
            $writeRuntimeOption ?? static function (): void {
                throw new \RuntimeException('wprism: native option runtime-companion writer is unavailable');
            }
        );
        if (!is_bool($handled)) {
            throw new \RuntimeException(
                "wprism: interpreter '{$candidate['interpreter_name']}' materialize_option_sub_keys() must return a boolean"
            );
        }
        if (!$handled) {
            throw new \RuntimeException(
                "wprism: interpreter '{$candidate['interpreter_name']}' is the exact native materialization owner for "
                . "option '$name' but returned false after dispatch; generic SQL fallback is forbidden"
            );
        }
        return true;
    }

    /**
     * Project plugin-native stored authored siblings onto their canonical
     * materialized comparison shape after a native mixed-option write.
     *
     * The exact native owner is resolved through materialize_option_sub_keys,
     * so an optional projection hook inherits the same digest-bound authority.
     * It receives no target-owned sibling bytes. A plugin-native sparse carrier
     * may remain physically present solely to preserve nested target-owned
     * state, so projection may omit a raw authored key only when the desired
     * authored-key roster says that key is absent. It can never add a key or
     * see the desired values; exact desired-value comparison remains owned by
     * OptionsMaterializer. Every returned value stays inside the bounded
     * plain-data grammar.
     */
    public function project_materialized_option_sub_keys_via_interpreter(
        string $name,
        array $rawAuthored,
        array $effectiveRule,
        ?string $effectiveSource,
        array $desiredAuthoredKeys
    ): array {
        if (!array_is_list($desiredAuthoredKeys)) {
            throw new \RuntimeException(
                "wprism: native materialization projection for option '$name' requires a desired authored-key list"
            );
        }
        $desiredSeen = [];
        foreach ($desiredAuthoredKeys as $position => $desiredKey) {
            if (!is_string($desiredKey)
                || isset($desiredSeen[$desiredKey])
                || (($effectiveRule['sub_keys'][$desiredKey]['class'] ?? null) !== 'authored')) {
                throw new \RuntimeException(
                    "wprism: native materialization projection for option '$name' received a malformed/"
                    . "non-authored desired key at position $position"
                );
            }
            $desiredSeen[$desiredKey] = true;
        }
        $candidate = $this->option_sub_key_interpreter_candidate(
            $name,
            $effectiveRule,
            $effectiveSource,
            'materialize_option_sub_keys',
            'native materialization projection'
        );
        if ($candidate === null
            || !method_exists($candidate['interpreter'], 'project_materialized_option_sub_keys')) {
            return $rawAuthored;
        }
        $projected = $candidate['interpreter']->project_materialized_option_sub_keys(
            $name,
            $rawAuthored,
            (array) ($effectiveRule['sub_keys'] ?? []),
            $desiredAuthoredKeys
        );
        if (!is_array($projected) || ($projected !== [] && array_is_list($projected))) {
            throw new \RuntimeException(
                "wprism: interpreter '{$candidate['interpreter_name']}' project_materialized_option_sub_keys() "
                . 'must return an object-shaped array'
            );
        }
        PlainData::assert($projected, "interpreter-projected materialized option '$name'");
        if (array_diff_key($projected, $rawAuthored) !== []) {
            throw new \RuntimeException(
                "wprism: interpreter '{$candidate['interpreter_name']}' project_materialized_option_sub_keys() "
                . "must not add an authored key beyond raw finalized storage for option '$name'"
            );
        }
        foreach ($desiredAuthoredKeys as $desiredKey) {
            if (!array_key_exists($desiredKey, $projected)) {
                throw new \RuntimeException(
                    "wprism: interpreter '{$candidate['interpreter_name']}' project_materialized_option_sub_keys() "
                    . "must retain every desired authored key for option '$name'"
                );
            }
        }
        return $projected;
    }

    /**
     * Exact target-owned companions a digest-bound native materializer must
     * observe. The engine resolves the full owner before calling this pure
     * roster hook so every row/gap can be locked in canonical byte order
     * before the mutation hook receives control.
     *
     * @return list<string>
     */
    public function option_sub_key_materialization_companions(
        string $name,
        array $effectiveRule,
        ?string $effectiveSource
    ): array {
        $candidate = $this->option_sub_key_interpreter_candidate(
            $name,
            $effectiveRule,
            $effectiveSource,
            'materialize_option_sub_keys',
            'native materialization companion discovery'
        );
        if ($candidate === null
            || !method_exists($candidate['interpreter'], 'option_sub_key_materialization_companions')) {
            return [];
        }
        $companions = $candidate['interpreter']->option_sub_key_materialization_companions($name);
        if (!is_array($companions) || !array_is_list($companions)) {
            throw new \RuntimeException(
                "wprism: interpreter '{$candidate['interpreter_name']}' option_sub_key_materialization_companions() "
                . 'must return a list'
            );
        }
        if (count($companions) > self::MAX_NATIVE_OPTION_COMPANIONS) {
            throw new \RuntimeException(
                "wprism: interpreter '{$candidate['interpreter_name']}' returned too many native option companions"
            );
        }
        $seen = [];
        foreach ($companions as $position => $companion) {
            if (!is_string($companion) || $companion === '' || isset($seen[$companion])) {
                throw new \RuntimeException(
                    "wprism: interpreter '{$candidate['interpreter_name']}' returned a malformed/duplicate native "
                    . "option companion at position $position"
                );
            }
            $seen[$companion] = true;
        }
        return array_keys($seen);
    }

    /**
     * Exact target-owned rows a digest-bound native materializer may update
     * as source-proven runtime effects. This roster is deliberately separate
     * from observation-only companions: OptionsMaterializer grants only these
     * names to its raw writer and retains locking, readback, cache invalidation
     * and rollback authority for every byte.
     *
     * @return list<string>
     */
    public function option_sub_key_materialization_runtime_companions(
        string $name,
        array $effectiveRule,
        ?string $effectiveSource
    ): array {
        $candidate = $this->option_sub_key_interpreter_candidate(
            $name,
            $effectiveRule,
            $effectiveSource,
            'materialize_option_sub_keys',
            'native materialization runtime-companion discovery'
        );
        if ($candidate === null
            || !method_exists($candidate['interpreter'], 'option_sub_key_materialization_runtime_companions')) {
            return [];
        }
        $companions = $candidate['interpreter']->option_sub_key_materialization_runtime_companions($name);
        if (!is_array($companions) || !array_is_list($companions)) {
            throw new \RuntimeException(
                "wprism: interpreter '{$candidate['interpreter_name']}' "
                . 'option_sub_key_materialization_runtime_companions() must return a list'
            );
        }
        if (count($companions) > self::MAX_NATIVE_OPTION_COMPANIONS) {
            throw new \RuntimeException(
                "wprism: interpreter '{$candidate['interpreter_name']}' returned too many native option runtime companions"
            );
        }
        $seen = [];
        foreach ($companions as $position => $companion) {
            if (!is_string($companion) || $companion === '' || isset($seen[$companion])) {
                throw new \RuntimeException(
                    "wprism: interpreter '{$candidate['interpreter_name']}' returned a malformed/duplicate native "
                    . "option runtime companion at position $position"
                );
            }
            $seen[$companion] = true;
        }
        return array_keys($seen);
    }

    /**
     * Let an exact interpreter fill plugin-native defaults and normalize raw
     * authored siblings before the ordinary secret/ref/text capture codec.
     * The hook never receives repository tokens, and every returned value is
     * subsequently guarded and encoded through the declared sub-key rule.
     * The final argument is the checked raw option snapshot for this capture
     * attempt. Passing it explicitly keeps native normalization attempt-scoped:
     * an interpreter cannot accidentally reuse mutable observations after a
     * transient retry or when the primary mixed-option row is absent.
     * The final flag marks the lifecycle handoff's strict read-only snapshot:
     * its target may deliberately have plugin files installed but inactive,
     * so an interpreter must not require an active plugin runtime merely to
     * compare pre-lifecycle option bytes.
     */
    public function normalize_captured_option_sub_keys_via_interpreter(
        string $name,
        array $rawAuthored,
        array $effectiveRule,
        ?string $effectiveSource,
        array $rawOptionSnapshot,
        bool $strictReadOnly
    ): array {
        $candidate = $this->option_sub_key_interpreter_candidate(
            $name,
            $effectiveRule,
            $effectiveSource,
            'normalize_captured_option_sub_keys',
            'native capture normalization'
        );
        if ($candidate === null) {
            return $rawAuthored;
        }
        $normalized = $candidate['interpreter']->normalize_captured_option_sub_keys(
            $name,
            $rawAuthored,
            (array) ($effectiveRule['sub_keys'] ?? []),
            $rawOptionSnapshot,
            $strictReadOnly
        );
        if (!is_array($normalized) || ($normalized !== [] && array_is_list($normalized))) {
            throw new \RuntimeException(
                "wprism: interpreter '{$candidate['interpreter_name']}' normalize_captured_option_sub_keys() "
                . 'must return an object-shaped array'
            );
        }
        foreach (array_keys($rawAuthored) as $subKey) {
            if (!array_key_exists($subKey, $normalized)) {
                throw new \RuntimeException(
                    "wprism: interpreter '{$candidate['interpreter_name']}' native capture normalization dropped "
                    . "already-present authored option '$name.$subKey'"
                );
            }
        }
        $subKeys = (array) ($effectiveRule['sub_keys'] ?? []);
        foreach ($normalized as $subKey => $value) {
            if (($subKeys[(string) $subKey]['class'] ?? null) !== 'authored') {
                throw new \RuntimeException(
                    "wprism: interpreter '{$candidate['interpreter_name']}' native capture normalization returned "
                    . "undeclared/non-authored option '$name.$subKey'"
                );
            }
            PlainData::assert($value, "interpreter-normalized option $name.$subKey");
        }
        return $normalized;
    }

    /** @return ?array{interpreter_name:string,interpreter:object,manifest_name:string} */
    private function option_sub_key_interpreter_candidate(
        string $name,
        array $effectiveRule,
        ?string $effectiveSource,
        string $hook,
        string $operation
    ): ?array {
        $interpreters = $this->interpreters();
        $candidates = [];
        foreach ($this->manifests as $manifest) {
            $declaredSubKeys = $manifest['options'][$name]['sub_keys'] ?? null;
            $interpreterName = $manifest['interpreter'] ?? null;
            if (!is_array($declaredSubKeys)
                || !is_string($interpreterName)
                || !isset($interpreters[$interpreterName])
                || !method_exists($interpreters[$interpreterName], $hook)) {
                continue;
            }
            $manifestName = (string) ($manifest['name'] ?? '');
            $declaredRule = self::with_option_autoload((array) $manifest['options'][$name], $manifest);
            if ($effectiveSource !== $manifestName || $declaredRule !== $effectiveRule) {
                throw new \RuntimeException(
                    "wprism: interpreter '$interpreterName' $operation declaration for option '$name' is owned by "
                    . "manifest '$manifestName', but the full effective rule/provenance differs; refusing hook dispatch"
                );
            }
            if (($effectiveRule['closed_sub_keys'] ?? null) !== true) {
                throw new \RuntimeException(
                    "wprism: interpreter '$interpreterName' $operation declaration for option '$name' is not a "
                    . 'closed_sub_keys registry; native hook dispatch is forbidden'
                );
            }
            $candidates[] = [
                'interpreter_name' => $interpreterName,
                'interpreter' => $interpreters[$interpreterName],
                'manifest_name' => $manifestName,
            ];
        }
        if (count($candidates) > 1) {
            $owners = array_map(
                static fn(array $candidate): string => "'{$candidate['manifest_name']}'/"
                    . "'{$candidate['interpreter_name']}'",
                $candidates
            );
            throw new \RuntimeException(
                "wprism: option '$name' $operation has multiple exact manifest/interpreter owners: "
                . implode(', ', $owners)
            );
        }
        return $candidates[0] ?? null;
    }

    /**
     * Backward-compatible facade kept for callers introduced by issue #3262.
     * Authored user meta is representable now, so classification itself is
     * no longer a blocker; Capture performs the value-level PII/secret and
     * shape checks while building the login-keyed sidecar.
     */
    public function user_meta_capture_blocker(string $key, array $allMeta): ?string {
        $this->meta_rule_for_user($key, $allMeta);
        return null;
    }

    /**
     * Missing owning-user behavior for one sidecar. Any authored key using
     * the fail-closed default wins over warn-and-skip, so mixed declarations
     * can never partially apply a sidecar.
     */
    public function user_meta_missing_behavior(array $meta): string {
        $hasAuthored = false;
        foreach ($meta as $key => $_) {
            $rule = $this->meta_rule_for_user((string) $key, $meta);
            if (($rule['class'] ?? '') !== 'authored') {
                continue;
            }
            $hasAuthored = true;
            if (($rule['missing_user'] ?? 'block') !== 'warn') {
                return 'block';
            }
        }
        return $hasAuthored ? 'warn' : 'block';
    }

    private function meta_rule_for_interpreter_hook(
        string $hook,
        string $section,
        string $key,
        array $allMeta
    ): ?array {
        return $this->rule_details_for_interpreter_hook(
            $hook,
            $section,
            $key,
            $allMeta,
            fn() => $this->rule_details($section, $key)
        )['rule'];
    }

    /** @return array{rule:?array, source:?string} */
    public function meta_rule_details_for_post(string $key, array $allMeta): array {
        return $this->rule_details_for_interpreter_hook(
            'post_meta_rule',
            'post_meta',
            $key,
            $allMeta,
            fn() => $this->post_meta_rule_details($key)
        );
    }

    /**
     * issue #3263: option_rule() sibling of meta_rule_details_for_post() above,
     * for the same "which interpreter/manifest actually decided this"
     * provenance RepositoryAuthorization/RepositoryCompiler need (they
     * report a mismatched source, not just a classification).
     *
     * @return array{rule:?array, source:?string}
     */
    public function option_rule_details_for_option(string $name, array $allOptions): array {
        return $this->rule_details_for_interpreter_hook(
            'option_rule',
            'options',
            $name,
            $allOptions,
            fn() => $this->option_rule_details($name)
        );
    }

    /**
     * Shared by every interpreter-backed meta/option lookup. post_meta_rule is
     * mandatory, while option_rule, term_meta_rule and user_meta_rule are
     * optional, so the method_exists() gate is load-bearing for those hooks.
     * Evaluate every non-null answer: the first interpreter cannot safely win
     * when another pinned manifest or static declaration owns the same bytes.
     *
     * The $hook === 'option_rule' branch below is the one hook-specific
     * exception to this being a generic dispatcher: every STATIC options
     * rule already gets the owning manifest's own option_autoload default
     * injected (with_option_autoload(), called at every static rule()
     * options lookup) — OptionState::assert_rule_autoload() hard-requires
     * every options rule to declare 'autoload' (or 'preserve') before a row
     * can be captured. An interpreter-returned options rule needs the exact
     * same treatment or it can never pass that check (caught live:
     * `adapter-packages/acf/tests/live/regress_acf_term_options_fields.sh`'s
     * first run failed capture outright
     * with "option '...' has autoload 'off' but policy declares NULL").
     * term_meta/user_meta rules have no such concept, so this is scoped to
     * the one hook name that does, not a general behavior change.
     *
     * @return array{rule:?array, source:?string}
     */
    private function rule_details_for_interpreter_hook(
        string $hook,
        string $section,
        string $key,
        array $allValues,
        callable $staticDetails
    ): array {
        $static = $staticDetails();
        $candidates = [];
        foreach ($this->interpreters() as $name => $i) {
            if (!method_exists($i, $hook)) {
                continue;
            }
            $rule = $i->{$hook}($key, $allValues);
            if ($rule === null) {
                continue;
            }
            // This feature authorizes one exact static option, not executable
            // classification. Even echoing a static constrained rule would
            // add a second authority capable of stripping its constraint.
            $intersection = array_key_exists(ScalarReferenceIntersection::FIELD, $rule)
                || array_key_exists(ScalarReferenceIntersection::TAXONOMY_FIELD, $rule)
                || array_key_exists(ScalarReferenceIntersection::FIELD, $static['rule'] ?? []);
            foreach ((array) ($rule['sub_keys'] ?? []) as $subRule) {
                $intersection = $intersection
                    || (is_array($subRule) && (array_key_exists(ScalarReferenceIntersection::FIELD, $subRule)
                        || array_key_exists(ScalarReferenceIntersection::TAXONOMY_FIELD, $subRule)));
            }
            if ($intersection) {
                throw new \RuntimeException(
                    "wprism: interpreter '$name' cannot classify $section '$key' with a static scalar reference intersection"
                );
            }
            $owners = [];
            foreach ($this->manifests as $m) {
                if (($m['interpreter'] ?? null) === $name) {
                    $owners[] = $m;
                }
            }
            if (count($owners) > 1) {
                $ownerNames = array_map(static fn(array $m): string => "'" . (string) ($m['name'] ?? '?') . "'", $owners);
                sort($ownerNames, SORT_STRING);
                throw new \RuntimeException(
                    "wprism: interpreter '$name' classified $section '$key' but its manifest owner is ambiguous: "
                    . implode(', ', $ownerNames)
                );
            }
            $owner = $owners === [] ? "interpreter $name" : (string) ($owners[0]['name'] ?? '?');
            $source = $owners === [] ? $owner : $owner . " (interpreter $name)";
            if (array_key_exists(NativeValueValidation::FIELD, $rule)) {
                if (!in_array($section, ['post_meta', 'term_meta', 'user_meta'], true) || count($owners) !== 1
                    || ($owners[0]['spec_version'] ?? 0) < 3
                    || !in_array(NativeValueValidation::FEATURE, (array)($owners[0]['engine_features'] ?? []), true)) {
                    throw new \RuntimeException("wprism: interpreter '$name' native value validation requires an exact v3 metadata owner declaring " . NativeValueValidation::FEATURE);
                }
                NativeValueValidation::assert_rule($rule, "$source $section.$key");
            }
            if (array_key_exists(NativeValueValidation::FIELD, $static['rule'] ?? [])
                && ($rule[NativeValueValidation::FIELD] ?? null) != $static['rule'][NativeValueValidation::FIELD]) {
                throw new \RuntimeException("wprism: interpreter '$name' cannot remove or change a static native value predicate");
            }
            if ($hook === 'option_rule' && $owners !== []) {
                $rule = self::with_option_autoload($rule, $owners[0]);
            }
            $candidates[] = [
                'owner' => $owner,
                'rule' => $rule,
                'source' => $source,
            ];
        }

        if ($candidates === []) {
            return $static;
        }

        $owners = [];
        foreach ($candidates as $candidate) {
            $owners[$candidate['owner']] = "'{$candidate['owner']}' via {$candidate['source']}";
        }
        if ($static['rule'] !== null) {
            $staticSource = (string) ($static['source'] ?? '?');
            $owners[$staticSource] ??= "'$staticSource' via static declaration";
        }
        if (count($owners) > 1) {
            ksort($owners, SORT_STRING);
            throw new \RuntimeException(
                "wprism: $section '$key' has multiple classification owners: "
                . implode(', ', array_values($owners))
                . '; dynamic interpreter dispatch cannot resolve cross-manifest ownership'
            );
        }

        return ['rule' => $candidates[0]['rule'], 'source' => $candidates[0]['source']];
    }

    /** @return array{rule:?array, source:?string} */
    public function meta_rule_details_for_term(string $key, array $allMeta): array {
        return $this->rule_details_for_interpreter_hook(
            'term_meta_rule',
            'term_meta',
            $key,
            $allMeta,
            fn() => $this->term_meta_rule_details($key)
        );
    }

    /** @return array{rule:?array, source:?string} */
    public function meta_rule_details_for_user(string $key, array $allMeta): array {
        $details = $this->rule_details_for_interpreter_hook(
            'user_meta_rule',
            'user_meta',
            $key,
            $allMeta,
            fn() => $this->rule_details('user_meta', $key)
        );
        if ($details['rule'] !== null) {
            UserMetaGrammar::validate_user_meta_rule(
                $details['rule'],
                (string) ($details['source'] ?? '?') . " user_meta.$key",
                self::CLASSES,
                self::MISSING_USER_MODES
            );
        }
        return $details;
    }

    /**
     * Give schema-driven interpreters the immutable repository tree before
     * authorization. ACF field definitions are themselves canonical posts;
     * priming from those files keeps a fresh target and an already-mapped
     * target from classifying the same payload differently merely because
     * only one target has the definitions in its database yet.
     */
    public function prime_interpreters_from_repository(array $tree): void {
        foreach ($this->interpreters() as $i) {
            if (method_exists($i, 'prime_repository')) {
                $i->prime_repository($tree);
            }
        }
    }

    /**
     * Optional offline cross-entity constraints supplied by the same pinned
     * interpreter artifact that already classifies schema-driven meta. This
     * is deliberately not a second extension loader or a plugin callback:
     * compiler constraints execute only manifest-shipped interpreter code,
     * and that interpreter's bytes are part of the compiled artifact hash.
     *
     * @return array<int,array<string,mixed>> stable compiler diagnostics
     */
    public function repository_constraint_diagnostics(array $tree): array {
        $out = [];
        foreach ($this->interpreters() as $name => $i) {
            if (!method_exists($i, 'repository_diagnostics')) {
                continue;
            }
            foreach ((array) $i->repository_diagnostics($tree) as $d) {
                if (!is_array($d)) {
                    throw new \RuntimeException("wprism: interpreter '$name' returned a non-array repository diagnostic");
                }
                $d['adapter'] ??= $name;
                $out[] = $d;
            }
        }
        return $out;
    }

    /** @return array{rule:?array, source:?string} */
    public function post_type_rule_details(string $postType): array {
        $site = $this->site['policy']['scope']['post_type'][$postType] ?? null;
        if (is_array($site)) {
            return ['rule' => $site, 'source' => 'site.wprism.json'];
        }
        foreach ($this->manifests as $m) {
            if (isset($m['post_types'][$postType]['class'])) {
                return [
                    'rule' => ['class' => $m['post_types'][$postType]['class']],
                    'source' => (string) ($m['name'] ?? '?'),
                ];
            }
        }
        return ['rule' => null, 'source' => null];
    }

    /** Whole-taxonomy disposition, parallel to post_type_rule_details().
     * A structural manifest declaration without an explicit class defaults
     * to authored: it names portable data the adapter understands, but the
     * site must still opt that taxonomy into its authored scope. */
    public function taxonomy_rule_details(string $taxonomy): array {
        $site = $this->site['policy']['scope']['taxonomy'][$taxonomy] ?? null;
        if (is_array($site)) {
            return ['rule' => $site, 'source' => 'site.wprism.json'];
        }
        foreach ($this->manifests as $m) {
            if (isset($m['taxonomies'][$taxonomy])) {
                return [
                    'rule' => ['class' => (string) ($m['taxonomies'][$taxonomy]['class'] ?? 'authored')],
                    'source' => (string) ($m['name'] ?? '?'),
                ];
            }
        }
        return ['rule' => null, 'source' => null];
    }

    /**
     * Which pinned ADAPTER declares a surface — provenance, deliberately
     * independent of which declaration won the classification above.
     *
     * The two questions are different and issue #3504 is the bill for having
     * conflated them. `post_type_rule_details()`:1841-1843 returns
     * `site.wprism.json` the moment a site scope rule exists, which is correct
     * and load-bearing (site policy always wins, and that source is compared
     * against namespace owners in `owned_option_rule()`:667-668). But
     * issue #3495 made `wprism adapter certify --pin` write exactly such a rule for
     * every type the adapter declares
     * (cli/src/Adapter/AdapterCertify.php:588-609), so a site that adopted a
     * certified adapter's CPT erased the adapter from
     * `AssessInventory::declarant()` and `wprism assess` printed
     * "Platform-certified" for that adapter's own post type. Deciding a
     * type's CLASS does not un-declare the type.
     *
     * DECLARES, not "declares authored". One `isset()` over the manifest
     * section is the whole rule, and it is uniform on purpose:
     *
     *  - It sees a STRUCTURAL declaration — `"wpcf7_contact_form": {}`, the
     *    shape Contact Form 7 uses — which `:1846`'s `isset(...['class'])`
     *    test cannot, a second and independent way an adapter lost credit for
     *    its own type even with no scope rule in play.
     *  - It does not filter by class. Filtering would leave provenance
     *    path-dependent for a `runtime`/`derived` declaration exactly as this
     *    issue found it for an authored one: unshadowed, `:1846` already
     *    credits that manifest; shadowed by a site rule, a class filter would
     *    hand the credit back to the platform. An adapter that names a
     *    surface declares it whatever it classifies it as.
     *
     * `$section` is the manifest section name, the same vocabulary
     * `rule_details()` takes. `core` is never an answer: it is the platform,
     * not an adapter, and `AssessInventory::CORE_DECLARANT` already means "no
     * adapter declared this". Null says exactly that.
     */
    public function declaring_manifest(string $section, string $name): ?string {
        foreach ($this->manifests as $m) {
            $declarant = (string) ($m['name'] ?? '');
            if ($declarant === '' || $declarant === 'core') {
                continue;
            }
            if (isset($m[$section][$name])) {
                return $declarant;
            }
        }
        return null;
    }

    /**
     * Pure repository-side taxonomy authorization. taxonomies() expands
     * pattern matches from the live target database, which is right for
     * capture discovery but wrong for immutable-revision preflight: the same
     * repository must not pass on a mapped target and fail on a fresh one.
     *
     * @return array{authorized:bool, source:?string}
     */
    public function taxonomy_scope_details(string $taxonomy): array {
        if (in_array($taxonomy, $this->site['policy']['taxonomies'] ?? ['category', 'post_tag'], true)
            || (($this->site['policy']['scope']['taxonomy'][$taxonomy]['class'] ?? null) === 'authored')) {
            return ['authorized' => true, 'source' => 'site.wprism.json'];
        }
        foreach ($this->manifests as $m) {
            foreach ($m['taxonomy_patterns'] ?? [] as $pat) {
                if (preg_match('/' . $pat['match'] . '/', $taxonomy)) {
                    return ['authorized' => true, 'source' => (string) ($m['name'] ?? '?')];
                }
            }
        }
        return ['authorized' => false, 'source' => null];
    }

    /**
     * 'blocks' (default: block-parser rewriting + URL tokenization),
     * 'verbatim' (byte-preserved opaque content), or 'serialized' (strict,
     * class-free PHP plain data whose string leaves are URL-tokenized and
     * re-serialized so embedded byte lengths remain correct).
     */
    public function body_mode(string $postType): string {
        foreach ($this->manifests as $m) {
            $mode = $m['post_types'][$postType]['body'] ?? null;
            if ($mode !== null) {
                return $mode;
            }
        }
        return PostTypeGrammar::defaultBodyMode();
    }

    /**
     * 'early' post types finalize before everything else in apply phase 2:
     * definition CPTs (acf-field*) whose content interpreters read to type
     * OTHER entities' meta — declared ordering, never glob-alphabetical luck.
     */
    public function post_type_phase(string $postType): string {
        foreach ($this->manifests as $m) {
            $phase = $m['post_types'][$postType]['phase'] ?? null;
            if ($phase !== null) {
                return $phase;
            }
        }
        return PostTypeGrammar::defaultPostTypePhase();
    }

    /**
     * issue #3234 — a post type's derived-table hard-dependency declaration, if
     * any: `{"regenerator": "<name>", "verify": {"table": "<t>", "column": "<c>"}}`.
     * v2 scope, stated loudly: post_types{}-keyed only (a per-post-type
     * property, matching the phase/fields precedents immediately above and
     * below — never a `tables{}` declaration, since the derived table itself
     * has no independent identity to declare; see agent/src/Repository/Snapshot.php's
     * own "gives no special meaning to any class value besides
     * authored_snapshot/authored_snapshot_meta" precedent, unchanged by
     * this). If a derived table ever hangs off a TERM or a declared
     * custom-table row instead of a post, that needs its own design — not
     * assumed covered here. First-declaring-manifest wins, same precedence
     * as its post_types{} siblings (phase/body) immediately around it, for
     * the same reason: consistency with how this section already resolves,
     * not an independently-chosen precedence rule for this one field.
     */
    public function regen_dependency(string $postType): ?array {
        foreach ($this->manifests as $m) {
            $decl = $m['post_types'][$postType]['regen_dependency'] ?? null;
            if ($decl !== null) {
                return $decl;
            }
        }
        return null;
    }

    /**
     * Return the optional batch/refresh contract for a post regeneration
     * dependency.
     *
     * The original regen_dependency contract is intentionally still the
     * default: callers get one id at a time and only a missing verification
     * row triggers regenerate().  A manifest may opt into the newer batch
     * boundary with either
     *
     *     "batch": {"enabled": true, "always_on_write": true}
     *
     * or the equivalent "refresh" spelling.  The latter exists because the
     * useful distinction for derived lookup tables is that a row can exist
     * and still be stale.  Both spellings are normalized here so the engine
     * has one contract and plugin code remains in the manifest regenerator.
     * A bare true is shorthand for an enabled, always-on-write batch.
     *
     * @return array{enabled:bool,always_on_write:bool}|null
     */
    public function regen_batch(string $postType): ?array {
        $decl = $this->regen_dependency($postType);
        if ($decl === null) {
            return null;
        }
        $raw = $decl['batch'] ?? ($decl['refresh'] ?? null);
        if ($raw === null && array_key_exists('always_on_write', $decl)) {
            $raw = ['enabled' => true, 'always_on_write' => $decl['always_on_write']];
        }
        if ($raw === null || $raw === false) {
            return null;
        }
        if ($raw === true) {
            return ['enabled' => true, 'always_on_write' => true];
        }
        if (!is_array($raw)) {
            // Load-time validation catches this; keep this method defensive
            // for frozen/third-party Policy instances constructed by tests.
            return null;
        }
        $config = [
            'enabled' => array_key_exists('enabled', $raw) ? (bool) $raw['enabled'] : true,
            'always_on_write' => array_key_exists('always_on_write', $raw)
                ? (bool) $raw['always_on_write']
                : true,
        ];
        return $config['enabled'] ? $config : null;
    }

    /** Compatibility alias for callers that prefer the explicit name. */
    public function regen_batch_dependency(string $postType): ?array {
        return $this->regen_batch($postType);
    }

    /** @return array<string,array{enabled:bool,always_on_write:bool}> */
    public function regen_batch_post_types(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['post_types'] ?? [] as $postType => $_decl) {
                if (isset($out[$postType])) {
                    continue;
                }
                $batch = $this->regen_batch((string) $postType);
                if ($batch !== null && !empty($batch['enabled'])) {
                    $out[(string) $postType] = $batch;
                }
            }
        }
        return $out;
    }

    /**
     * v2-supported post FIELD classification surface (task #88 origin,
     * extended by the evidence-backed Woo timestamp case). A field
     * name must appear here before ANY manifest may declare it under
     * `post_types.<type>.fields.<field>` — FieldGrammar::validate_field_classes()
     * enforces this at load() time
     * enforces this at load() time, loudly, rather than silently ignoring
     * an unsupported declaration. `title` is derived for Woo variation
     * self-healing; `modified`/`modified_gmt` are derived for Woo products
     * and variations because WooCommerce-mediated saves own those timestamps
     * even when the authored product inputs did not change. 'slug' remains
     * excluded on purpose: a post's slug participates in its canonical
     * FILENAME and in collision/identity checks (Apply::find_collision()),
     * so "derived" would need to answer questions (does the filename track
     * the live value? does identity?) this mechanism does not answer.
     * status/menu_order/comment_status/ping_status/excerpt have no proven
     * self-healing precedent. Widening this list is a deliberate, separate
     * decision per field, not a mechanical extension of the mechanism.
     *
     * A MAP, front-matter field => the wp_posts column it writes (issue #3318).
     * Apply::finalize_post() has to drop the mapped COLUMN from its UPDATE
     * payload, and it used to carry its own independent copy of this
     * translation: widening the allowlist here without also widening that
     * literal would have produced a field a manifest may legally declare
     * `derived` and that apply then overwrites anyway — a silent
     * half-implementation of the very classification the declaration asked
     * for. One declaration site makes that combination unrepresentable.
     * Public because the consumer lives in another class; the value is the
     * engine's own vocabulary, not a manifest input.
     */
    public const DERIVABLE_FIELD_COLUMNS = [
        'title' => 'post_title',
        'modified' => 'post_modified',
        'modified_gmt' => 'post_modified_gmt',
    ];

    /** @see DERIVABLE_FIELD_COLUMNS */
    private const FIELD_CLASSES = ['derived'];

    /**
     * Post-FIELD classification — NOT post_meta/options (Policy::rule()'s
     * 'post_meta'/'term_meta'/'options' sections), and not the same thing
     * as this class's own post_types()/body_mode()/post_type_phase()
     * either: post_types() is the site-policy SCOPE list (which types
     * capture at all), body_mode()/post_type_phase() classify how a whole
     * post TYPE behaves. This classifies one of the ~13 keys every post
     * FILE carries unconditionally (Capture::build_post()'s $front /
     * Apply::finalize_post()'s $wpdb->update() payload — title, slug,
     * status, dates, parent, menu_order, comment_status, ping_status,
     * excerpt) — fields no manifest could classify at all before task #88
     * introduced this mechanism,
     * unlike meta/options which have supported `class: derived` from v0.
     *
     * The proven cases are WooCommerce-owned fields:
     * WC_Product_Variation_Data_Store_CPT::read() (task #72's confirmed root
     * cause) silently recomputes a variation's post_title from the parent's
     * attribute order + the variation's own current attribute values on
     * EVERY wc_get_product() load, writing it via a raw $wpdb->update()
     * specifically to dodge wp_update_post()/save_post — hook-free and
     * invisible to Apply's canary. Separately, ordinary WooCommerce product
     * and variation saves update post_modified/post_modified_gmt as a
     * persistence timestamp even when only runtime stock or another plugin-
     * owned value changed. Two environments can therefore disagree on these
     * bytes while every authored input is identical.
     *
     * Manifest-only, first declaring manifest wins — same precedence as
     * body_mode()/post_type_phase() immediately above, for the identical
     * reason description_refs_for_taxonomy() gives for its own no-site-
     * override stance: this is a structural fact about how a PLUGIN's post
     * type behaves (a fact this manifest is asserting about WooCommerce's
     * own code), not a site-local policy choice. It also sidesteps the
     * same real naming collision body_mode()/post_type_phase() already
     * avoid: site.wprism.json's policy.post_types is already the flat SCOPE
     * LIST post_types() reads above — a site-policy override here would
     * need a different key or silently shadow that list.
     *
     * Default 'authored': every field is authored unless a manifest says
     * otherwise, matching how every post type captures fully today with
     * zero manifest declarations. See DERIVABLE_FIELD_COLUMNS for what a manifest
     * may actually declare — anything else fails loudly at load() time,
     * never silently here. Capture still records a derived field verbatim;
     * Canon strips it only from the hash basis, and Apply omits its mapped
     * database column only for an existing row.
     */
    public function field_class(string $postType, string $field): string {
        return $this->field_rule_details($postType, $field)['class'];
    }

    /** @return array{class:string, source:string} */
    public function field_rule_details(string $postType, string $field): array {
        foreach ($this->manifests as $m) {
            $class = $m['post_types'][$postType]['fields'][$field]['class'] ?? null;
            if ($class !== null) {
                return [
                    'class' => $class,
                    'source' => (string) ($m['name'] ?? '?'),
                ];
            }
        }
        return ['class' => 'authored', 'source' => 'repo-format'];
    }

    /**
     * v2-supported MENU FIELD classification surface (issue #3272) — same
     * purpose as DERIVABLE_FIELD_COLUMNS above (task #88), but for `menus/*.json`
     * entities: a field name must appear here before ANY manifest may
     * declare it under top-level `menu_fields.<field>` —
     * FieldGrammar::validate_menu_field_classes() enforces this at load() time.
     * Deliberately just 'locations': it is the only menu field with a
     * proven self-healing precedent under a real plugin (Polylang).
     *
     * Structurally different from DERIVABLE_FIELD_COLUMNS/field_class() even
     * though the intent rhymes: field_class() is a narrow opt-in (implicit
     * 'authored', a manifest may only ever DECLARE 'derived' — no core
     * declaration exists to yield to, since every shipped user is a PLUGIN
     * manifest asserting a fact about its own post type). menu_fields
     * needs the full issue #3249 core-yields-to-plugin precedence instead:
     * `platform/adapter-library/core/manifest.json` declares 'locations'
     * authored as its v0 baseline (every
     * ordinary, non-Polylang site), and a pinned plugin manifest may
     * reclassify it — see menu_field_rule_details() below, which reuses
     * rule_details('menu_fields', $field) directly rather than
     * field_rule_details()'s simpler first-match-wins loop. That is why
     * MENU_FIELD_CLASSES (unlike FIELD_CLASSES) allows both 'authored' and
     * 'derived': core's own declaration must be expressible too.
     */
    private const MENU_DERIVABLE_FIELDS = ['locations'];

    /** @see MENU_DERIVABLE_FIELDS */
    private const MENU_FIELD_CLASSES = ['authored', 'derived'];

    /**
     * Menu-FIELD classification (issue #3272). Proven case: Polylang's own
     * Languages::update_default() (wp-content/plugins/polylang/src/Model/
     * Languages.php:774) unconditionally rewrites
     * theme_mods_<stylesheet>['nav_menu_locations'] — the exact raw value
     * Capture::scope_menus() reads and Apply::assign_locations() writes —
     * from Polylang's OWN nav_menus[theme][loc][lang] bookkeeping, any
     * time the default language changes OR Languages::get_default()'s own
     * fallback fires ("the default language is lost... let's select one
     * arbitrarily" — an environment-dependent term-query-ordering pick
     * this engine does not control). Two environments processing the
     * identical captured state can end up with a DIFFERENT menu owning
     * the same location, entirely outside WPrism's own capture/apply cycle —
     * issue #3272's filed repro is 100% reproducible on demand: calling
     * update_default() with a different language flips which menu file's
     * `locations` holds a given slot, byte-for-byte matching the original
     * flake.
     *
     * Owner ruling (issue comment 8e0edde6): the raw slot is a PROJECTION
     * of state WPrism already carries losslessly elsewhere —
     * `adapter-packages/polylang/package/manifest.json`'s own sub_keys
     * mechanism (issue #3233/task #121) already propagates both
     * `nav_menus` (which menu belongs at which location, PER LANGUAGE) and
     * `default_lang` inside the `polylang` option itself. So classifying
     * `locations` 'derived' under Polylang does not drop authored
     * information — it stops WPrism from ALSO separately carrying a value
     * Polylang's own machinery treats as its mutable cache and rewrites at
     * will, which is exactly what made the flake possible. Default
     * 'authored' if nothing declares a rule at all (defensive fallback
     * only — the platform core manifest's own menu_fields.locations
     * declaration means this branch is not expected to be reached in
     * practice).
     */
    public function menu_field_class(string $field): string {
        return $this->menu_field_rule($field)['class'] ?? 'authored';
    }

    private function menu_field_rule(string $field): ?array {
        return $this->rule('menu_fields', $field);
    }

    /** @return array{rule:?array, source:?string} */
    public function menu_field_rule_details(string $field): array {
        return $this->rule_details('menu_fields', $field);
    }

    /**
     * The pure-grammar half of ONE `widgets.<type>` declaration — the exact
     * mirror of assert_table_grammar() above, and for the same reason
     * (issue #3318 review, S4).
     *
     * This is the only implementation of these rules: ManifestGrammar's
     * aggregate validator runs it for every declared type at load, and
     * SidebarState::assert_policy() runs it again immediately before its own
     * genuinely-live work.
     *
     * issue #3348 first extraction slice: the implementation now lives in
     * ManifestGrammar::assert_widget_grammar(); this method is a thin
     * compatibility facade kept so SidebarState.php's capture-time re-check
     * needs no change while this decomposition proceeds. Policy's loader paths
     * call ManifestGrammar directly rather than through this facade.
     */
    public static function assert_widget_grammar(string $type, mixed $decl, ?string $source = null): void {
        ManifestGrammar::assert_widget_grammar($type, $decl, $source);
    }

    /** Published shared input for UserMetaGrammar's missing-user vocabulary. */
    private const MISSING_USER_MODES = ['block', 'warn'];
    /**
     * Option names classified `env`, keyed by name, value = the full rule
     * (including the mandatory `required` flag validate_env_options()
     * already guaranteed is present and boolean). Sibling enumerator to
     * authored_options() above, same merge precedence (site policy
     * replaces a manifest's whole rule wholesale, never a deep merge).
     *
     * issue #3232: the enumeration half of "env-bound value provisioning" —
     * feeds Apply::build_plan()'s env_missing bucket (which entity of
     * this list actually looks unset on THIS environment) and, indirectly
     * via `wp wprism env-set`'s own lookup, the write-time guard that refuses
     * to write to any option name NOT in this map (never an arbitrary
     * option, only a manifest-declared env-classified one).
     *
     * Scope: OPTIONS ONLY for v2, deliberately. post_meta/term_meta
     * classification can be interpreter-driven (Policy::meta_rule_for_post()
     * dispatches to schema-driven code reading a SPECIFIC post's whole
     * meta map — Policy.php's own interpreter contract docblock at the top
     * of this file), so "enumerate every env-classified meta key globally"
     * has no well-defined answer without live per-post data a fresh target
     * doesn't have yet. Every real env-classified value across every
     * shipped manifest today is an option (verified empirically, not
     * assumed) — a genuine env-classified meta key, if one ever surfaces,
     * is a separate, scoped follow-up, not solved speculatively here.
     */
    public function env_options(): array {
        return $this->exact_option_resolver()->env();
    }

    /**
     * issue #3249: every core-manifest OPTION name where a pinned, NON-core
     * manifest's own declaration outranks core's per rule_details()'s
     * core-yields-to-plugin precedence AND actually resolves to a
     * DIFFERENT class — the loud, plan-visible half of that precedence fix
     * (owner ruling, issue comment 8e6d4aeb: "Plan emits a note whenever a
     * reclassification override is active — loud, never silent").
     * Apply::build_plan() turns each entry into a plain plan warning.
     *
     * Deliberately narrow, matching the ruling's own scope: options only
     * (mirrors env_options()'s identical "options only for v2" cut — no
     * shipped manifest reclassifies a core post_meta/term_meta/table key
     * today), and core-vs-PLUGIN-MANIFEST only — a site.wprism.json override
     * of a core option is the operator's own explicit, already-visible
     * choice (it's sitting in a file they wrote), not a silent manifest-
     * pinning side effect, so it does not need this same loud treatment
     * and is excluded here on purpose, not by oversight. This says nothing
     * about, and does not resolve, the SEPARATE question of two non-core
     * manifests declaring the same option name (issue #3255) — that
     * collision (if it exists in this policy at all) does not surface
     * here regardless of which of the two manifests rule_details() picks.
     *
     * @return list<array{name:string, core_class:?string, active_class:?string, overridden_by:string}>
     */
    public function active_reclassifications(): array {
        $core = null;
        foreach ($this->manifests as $m) {
            if (($m['name'] ?? '') === 'core') {
                $core = $m;
                break;
            }
        }
        if ($core === null) {
            return [];
        }
        $out = [];
        foreach ($core['options'] ?? [] as $name => $coreRule) {
            $winner = $this->option_rule_details($name);
            $source = $winner['source'] ?? null;
            if ($source === null || $source === 'core' || $source === 'site.wprism.json') {
                continue;
            }
            $activeClass = $winner['rule']['class'] ?? null;
            $coreClass = $coreRule['class'] ?? null;
            if ($activeClass === $coreClass) {
                continue; // same-name declaration in both, but not actually a DIFFERENT classification -- nothing to warn about
            }
            $out[] = [
                'name' => (string) $name,
                'core_class' => $coreClass,
                'active_class' => $activeClass,
                'overridden_by' => $source,
            ];
        }
        return $out;
    }

    /**
     * issue #3272's own version of active_reclassifications() immediately
     * above — same purpose (the loud, plan-visible half of the issue #3249
     * core-yields-to-plugin precedence, this time for menu_fields instead
     * of options), kept as a separate function rather than a generalized
     * shared one for the same reason validate_menu_field_classes() stays
     * separate from validate_field_classes(): the two sections don't share
     * a manifest shape (menu_fields is flat; options is too, but the two
     * are semantically unrelated surfaces with their own core-declaration
     * sets), and Apply::build_plan() needs to report them as distinct,
     * clearly-labeled warnings rather than one merged list a reader has to
     * disambiguate by field name alone.
     *
     * @return list<array{name:string, core_class:?string, active_class:?string, overridden_by:string}>
     */
    public function active_menu_field_reclassifications(): array {
        $core = null;
        foreach ($this->manifests as $m) {
            if (($m['name'] ?? '') === 'core') {
                $core = $m;
                break;
            }
        }
        if ($core === null) {
            return [];
        }
        $out = [];
        foreach ($core['menu_fields'] ?? [] as $name => $coreRule) {
            $winner = $this->menu_field_rule_details($name);
            $source = $winner['source'] ?? null;
            if ($source === null || $source === 'core' || $source === 'site.wprism.json') {
                continue;
            }
            $activeClass = $winner['rule']['class'] ?? null;
            $coreClass = $coreRule['class'] ?? null;
            if ($activeClass === $coreClass) {
                continue; // same-name declaration in both, but not actually a DIFFERENT classification -- nothing to warn about
            }
            $out[] = [
                'name' => (string) $name,
                'core_class' => $coreClass,
                'active_class' => $activeClass,
                'overridden_by' => $source,
            ];
        }
        return $out;
    }

    /**
     * WP-5.5: every plugin/theme claim an explicit `site.wprism.json`
     * `policy.adapter_claims` resolution displaced — the REPORTING half of
     * that section, and the reason it is a resolution rather than a silent
     * override (spec/repo-format.md § v3.13).
     *
     * The same posture, and the same plan surface, as the two
     * active_*_reclassifications() accessors above: the owner ruling behind
     * those is "Plan emits a note whenever a reclassification override is
     * active — loud, never silent", and a displaced claim is the identical
     * shape of fact — a precedence decision that is CORRECT once the operator
     * has written it down, and that an operator reading `version_ranges()`
     * against their own pin list must be able to account for. So it is a plain
     * `$plan['warnings']` entry in ApplyPlanBuilder and never an ok-flipping
     * bucket: a resolved collision is a decision, not a defect.
     *
     * The word is `displaced_by_resolution`, one step over from the catalog's
     * own `shadowed_by_site` (AdapterSources::CERTIFICATION_SHADOWED_BY_SITE),
     * which reports a shipped adapter an explicit `{name, source:"site"}` pin
     * displaced. Distinct rather than reused because the subject differs: a
     * displaced CLAIMANT is still installed, still pinned and still loaded,
     * and reporting it in `not_installed` would be false about all three.
     *
     * @return list<array{kind:string, id:string, reason_code:string, in_force:string, in_force_range:?array<string,string>, displaced:string, displaced_range:?array<string,string>, note:?string}>
     */
    public function displaced_adapter_claims(): array {
        return AdapterClaimResolutions::displaced($this->manifests, $this->site['policy'] ?? []);
    }

    /** Apply a source-level default without mutating the loaded artifact. */
    public static function with_option_autoload(array $rule, array $source): array {
        if (!array_key_exists('autoload', $rule) && array_key_exists('option_autoload', $source)) {
            $rule['autoload'] = $source['option_autoload'];
        }
        return $rule;
    }

    /**
     * Manifest-declared rebuild actions, flattened in pin order.
     *
     * Every row is annotated with `manifest` (the declaring manifest's name)
     * and `index` (its position in that manifest's own `actions` list). Both
     * are load-bearing for the consumers, not decoration: a provider-kind
     * action resolves its `provider` id against the SAME manifest's
     * declarations (validate_actions() enforces that scope, so the id alone
     * is not a global key until provider_declarations() has proven global
     * uniqueness), and effects_inventory()/negotiation diagnostics name the
     * exact declaration a human has to go edit.
     *
     * @return list<array<string,mixed>>
     */
    public function actions(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            $name = (string) ($m['name'] ?? '?');
            foreach ((array) ($m['actions'] ?? []) as $i => $action) {
                $out[] = $action + ['manifest' => $name, 'index' => (int) $i];
            }
        }
        return $out;
    }

    /**
     * Provider-owned completion gates selected by a verified code transition,
     * after fresh-process activation and before finalize/state apply.
     *
     * @return list<array<string,mixed>>
     */
    public function lifecycle_settle_actions(): array {
        return array_values(array_filter(
            $this->actions(),
            static fn(array $action): bool => ($action['phase'] ?? null) === 'lifecycle_settle'
        ));
    }

    /**
     * Idempotent provider actions which establish exact declared table schema
     * before a full target plan may observe canonical rows.
     *
     * @return list<array<string,mixed>>
     */
    public function schema_settle_actions(): array {
        return array_values(array_filter(
            $this->actions(),
            static fn(array $action): bool => ($action['phase'] ?? null) === 'schema_settle'
        ));
    }

    /**
     * Read-only capability projections paired one-to-one with schema actions.
     * The internal table list lets live negotiation enforce the same exact
     * surface set the manifest grammar checked without turning readiness into
     * a second mutating action or an effect-inventory entry.
     *
     * @return list<array<string,mixed>>
     */
    public function schema_readiness_actions(): array {
        $out = [];
        foreach ($this->schema_settle_actions() as $action) {
            $out[] = [
                '_schema_readiness_tables' => array_values((array) ($action['prepares'] ?? [])),
                'args' => [],
                'capability' => (string) ($action['readiness'] ?? ''),
                'index' => (int) ($action['index'] ?? 0),
                'kind' => 'provider',
                'manifest' => (string) ($action['manifest'] ?? '?'),
                'provider' => (string) ($action['provider'] ?? ''),
            ];
        }
        return $out;
    }

    /** @return array<string,array{manifest:string,index:int,provider:string,capability:string}> */
    public function schema_settle_tables(): array {
        $out = [];
        foreach ($this->schema_settle_actions() as $action) {
            foreach ((array) ($action['prepares'] ?? []) as $table) {
                $out[(string) $table] = [
                    'manifest' => (string) ($action['manifest'] ?? '?'),
                    'index' => (int) ($action['index'] ?? 0),
                    'provider' => (string) ($action['provider'] ?? ''),
                    'capability' => (string) ($action['capability'] ?? ''),
                ];
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * Manifest actions with an exact canonical surface intersection.
     *
     * A declaration without `triggers` is deliberately unscoped: it remains
     * required for every authored mutation, preserving the semantics the
     * retired `rebuilders` channel gave an un-triggered declaration. A
     * declaration with triggers is selected only when Apply has derived the
     * exact same canonical surface from this request. The empty surface set
     * is a no-op, so a read-only apply cannot fire an action.
     *
     * @param list<string>      $surfaces
     * @param ?list<string>     $mutationChannels
     * @return list<array<string,mixed>>
     */
    public function actions_for(array $surfaces, ?array $mutationChannels = null): array {
        $wanted = [];
        foreach ($surfaces as $surface) {
            if (is_string($surface) && $surface !== '') {
                $wanted[$surface] = true;
            }
        }
        if ($wanted === []) {
            return [];
        }

        $out = [];
        $declarations = $mutationChannels === null ? [] : $this->provider_declarations();
        foreach ($this->actions() as $action) {
            // Phased actions have their own host/apply scheduling boundary;
            // they are never selected again as post-commit rebuilds merely
            // because an authored surface happened to change.
            if (array_key_exists('phase', $action)) {
                continue;
            }
            if (($action['kind'] ?? null) === 'provider'
                && $mutationChannels !== null
                && !self::provider_action_matches_mutation_channels(
                    $action,
                    $mutationChannels,
                    $declarations
                )) {
                continue;
            }
            if (!array_key_exists('triggers', $action)) {
                $out[] = $action;
                continue;
            }
            foreach (array_keys($wanted) as $surface) {
                if (ActionTriggerMatcher::any_matches((array) $action['triggers'], $surface)) {
                    $out[] = $action;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Provider `context` is already the closed statement of which engine
     * mutation channel a capability consumes. Use it for selection as well as
     * payload construction so two capabilities may own the same canonical
     * surface without both running for a tombstone. Callers which omit channel
     * evidence retain the historical surface-only behavior.
     *
     * @param array<string,array<string,mixed>> $declarations
     */
    private static function provider_action_matches_mutation_channels(
        array $action,
        array $mutationChannels,
        array $declarations
    ): bool {
        $provider = (string) ($action['provider'] ?? '');
        $capability = (string) ($action['capability'] ?? '');
        $contract = $declarations[$provider]['contracts'][$capability] ?? null;
        if (!is_array($contract) || !array_key_exists('context', $contract)) {
            return true;
        }
        $declared = array_fill_keys(array_map('strval', (array) $contract['context']), true);
        foreach ($mutationChannels as $channel) {
            if (isset($declared[(string) $channel])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Every pinned manifest's provider declarations, keyed by provider id.
     *
     * The key is global because validate_no_conflicting_provider_ids() has
     * already refused two pinned manifests declaring the same id — the same
     * posture AdapterContractGrammar::validate_no_conflicting_adapter_claims()
     * takes for a plugin or theme claim, and for the same reason: a provider id is an identity
     * assertion about installed executable code, so letting pin order pick a
     * winner would make which code runs depend on load order.
     *
     * Each row carries the declaring manifest's name and its `version_range`
     * (null when the manifest pins no range) because negotiation bounds a
     * provider by exactly the range that bounds its manifest's classification
     * guarantees — the provider is that adapter's executable half, so letting
     * it run outside the window the declarative half was certified for would
     * make the version pin mean two different things.
     *
     * @return array<string, array<string,mixed>> id => declaration + `manifest` + `version_range`
     */
    public function provider_declarations(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            $name = (string) ($m['name'] ?? '?');
            $range = is_array($m['version_range'] ?? null)
                ? ['min' => (string) $m['version_range']['min'], 'max' => (string) $m['version_range']['max']]
                : null;
            foreach ((array) ($m['providers'] ?? []) as $declaration) {
                $out[(string) $declaration['id']] = $declaration
                    + ['manifest' => $name, 'version_range' => $range];
            }
        }
        return $out;
    }

    /**
     * Complete target-independent effect declaration for the automatic
     * rollback profile. Missing lifecycle/rebuilder/regenerator declarations
     * are represented as explicit irreversible rows instead of disappearing:
     * manual promotion remains available, while receipt preparation must
     * refuse before code stage. The manifest digest already binds these bytes;
     * this projection gives the recovery controller a stable, minimal input.
     *
     * @return list<array<string,mixed>>
     */
    public function effects_inventory(): array {
        // Engine-owned rebuild effects are declarations too. Database rows
        // are covered by the encrypted checkpoint; the external object cache
        // needs a real provider inverse/readback and therefore cannot hide
        // behind the fact that its contents are derived.
        $out = [
            [
                'manifest' => 'core', 'phase' => 'rebuild', 'source' => 'future-post-schedule',
                'effect' => [
                    'id' => 'core-future-post-schedule', 'kind' => 'database', 'mode' => 'restorable',
                    'selector' => ['scope' => 'database_checkpoint', 'type' => 'option', 'value' => 'cron'],
                ],
            ],
            [
                'manifest' => 'core', 'phase' => 'rebuild', 'source' => 'taxonomy-counts',
                'effect' => [
                    'id' => 'core-taxonomy-counts', 'kind' => 'database', 'mode' => 'restorable',
                    'selector' => ['scope' => 'database_checkpoint', 'type' => 'table', 'value' => 'term_taxonomy'],
                ],
            ],
            [
                'manifest' => 'core', 'phase' => 'rebuild', 'source' => 'object-cache-flush',
                'effect' => [
                    'id' => 'core-object-cache', 'kind' => 'cache', 'mode' => 'reversible',
                    'selector' => ['scope' => 'external', 'type' => 'namespace', 'value' => 'wordpress-object-cache'],
                    'adapter' => [
                        'id' => 'core-object-cache', 'version' => '1.0.0',
                        'inverse' => 'flush-prior-generation',
                        'inverse_inputs' => ['namespace', 'prior_generation'],
                        'verifier' => 'fresh-cache-generation',
                        'verifier_inputs' => ['namespace', 'expected_generation'],
                    ],
                ],
            ],
            [
                'manifest' => 'core', 'phase' => 'rebuild', 'source' => 'attachment-metadata',
                'effect' => [
                    'id' => 'core-attachment-metadata-db', 'kind' => 'database', 'mode' => 'restorable',
                    'selector' => ['scope' => 'database_checkpoint', 'type' => 'table', 'value' => 'postmeta'],
                ],
            ],
            [
                'manifest' => 'core', 'phase' => 'rebuild', 'source' => 'attachment-metadata',
                'effect' => [
                    'id' => 'core-attachment-derivatives', 'kind' => 'filesystem', 'mode' => 'reversible',
                    'selector' => ['scope' => 'external', 'type' => 'provider_resource', 'value' => 'compiled-upload-inventory'],
                    'adapter' => [
                        'id' => 'upload-bundle', 'version' => '1.0.0',
                        'inverse' => 'storage-restore',
                        'inverse_inputs' => ['uploads_inventory_sha256', 'prior_inventory_sha256'],
                        'verifier' => 'fresh-storage-readback',
                        'verifier_inputs' => ['uploads_inventory_sha256', 'prior_inventory_sha256'],
                    ],
                ],
            ],
        ];
        foreach ($this->manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '?');
            $adapter = isset($manifest['plugin']) ? (string) $manifest['plugin']
                : (isset($manifest['theme']) ? (string) $manifest['theme'] : null);
            if ($adapter !== null) {
                $effects = $manifest['lifecycle_effects'] ?? null;
                if (!is_array($effects) || $effects === []) {
                    $effects = [self::missing_effect($name . '-lifecycle', 'plugin_lifecycle', $adapter)];
                }
                foreach ($effects as $effect) {
                    $out[] = ['manifest' => $name, 'phase' => 'lifecycle', 'source' => $adapter, 'effect' => $effect];
                }
            }
            foreach ((array) ($manifest['actions'] ?? []) as $i => $action) {
                // The source string is the effect inventory's stable name for
                // "what performs this effect". Under the retired free-form
                // channel that was the wp-cli command text; a structured
                // action's equivalent is its closed identity — the native
                // vocabulary entry, or the exact provider capability — which
                // is what a recovery operator can look up and re-run.
                $source = self::action_source($action, $i);
                $effects = self::action_effects($action + ['manifest' => $name], $i);
                foreach ($effects as $effect) {
                    $out[] = [
                        'manifest' => $name,
                        'phase' => match ($action['phase'] ?? null) {
                            'lifecycle_settle' => 'lifecycle-settle',
                            'schema_settle' => 'schema-settle',
                            default => 'rebuild',
                        },
                        'source' => $source,
                        'effect' => $effect,
                    ];
                }
            }
            foreach ((array) ($manifest['post_types'] ?? []) as $postType => $declaration) {
                $regen = is_array($declaration) ? ($declaration['regen_dependency'] ?? null) : null;
                if (!is_array($regen)) {
                    continue;
                }
                $effects = $regen['effects'] ?? null;
                if (!is_array($effects) || $effects === []) {
                    $effects = [self::missing_effect($name . '-regenerator-' . $postType, 'provider_resource', (string) $postType)];
                }
                foreach ($effects as $effect) {
                    $out[] = ['manifest' => $name, 'phase' => 'regenerator', 'source' => (string) $postType, 'effect' => $effect];
                }
            }
        }
        usort($out, static fn(array $a, array $b): int => strcmp(
            implode("\0", [$a['phase'], $a['manifest'], (string) $a['effect']['id']]),
            implode("\0", [$b['phase'], $b['manifest'], (string) $b['effect']['id']])
        ));
        return $out;
    }

    /**
     * Project the immutable effect authority reachable by one ordinary apply.
     * The policy-wide inventory remains the certification/diagnostic surface;
     * a recovery receipt gets only selected engine sources, exact action
     * declarations, and selected legacy regenerators.
     *
     * @param list<array<string,mixed>> $selectedActions
     * @param list<string> $engineSources
     * @param list<string> $regeneratorPostTypes
     * @return list<array<string,mixed>>
     */
    public function execution_effects_inventory(
        array $selectedActions,
        array $engineSources,
        array $regeneratorPostTypes = []
    ): array {
        $wanted = [];
        foreach ($engineSources as $source) {
            $wanted["core\0rebuild\0" . (string) $source] = true;
        }
        foreach ($regeneratorPostTypes as $postType) {
            foreach ($this->manifests as $manifest) {
                $name = (string) ($manifest['name'] ?? '?');
                if (is_array($manifest['post_types'][(string) $postType]['regen_dependency'] ?? null)) {
                    $wanted[$name . "\0regenerator\0" . (string) $postType] = true;
                }
            }
        }
        $out = array_values(array_filter(
            $this->effects_inventory(),
            static fn(array $row): bool => isset($wanted[
                    (string) ($row['manifest'] ?? '') . "\0"
                    . (string) ($row['phase'] ?? '') . "\0"
                    . (string) ($row['source'] ?? '')
                ])
        ));
        // A source spelling is not an action identity: multiple native
        // declarations may share `native:transient.delete` while naming
        // different exact effects. Rebuild these rows from the selected
        // declarations so the receipt cannot acquire an unselected sibling.
        foreach ($selectedActions as $action) {
            $index = (int) ($action['index'] ?? 0);
            foreach (self::action_effects($action, $index) as $effect) {
                $out[] = [
                    'manifest' => (string) ($action['manifest'] ?? '?'),
                    'phase' => match ($action['phase'] ?? null) {
                        'lifecycle_settle' => 'lifecycle-settle',
                        'schema_settle' => 'schema-settle',
                        default => 'rebuild',
                    },
                    'source' => self::action_source($action, $index),
                    'effect' => $effect,
                ];
            }
        }
        usort($out, static fn(array $a, array $b): int => strcmp(
            implode("\0", [$a['phase'], $a['manifest'], (string) $a['effect']['id']]),
            implode("\0", [$b['phase'], $b['manifest'], (string) $b['effect']['id']])
        ));
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function lifecycle_effects_inventory(): array {
        return array_values(array_filter(
            $this->effects_inventory(),
            static fn(array $row): bool => in_array(
                (string) ($row['phase'] ?? ''),
                ['lifecycle', 'lifecycle-settle'],
                true
            )
        ));
    }

    /**
     * Hash-only action selection proof published by ordinary and scoped plans.
     * Effects alone cannot distinguish two declarations sharing one provider
     * source, while this digest binds triggers, args, channels, and effects.
     *
     * @param list<array<string,mixed>> $actions
     * @return list<array{declaration_hash:string,index:int,manifest:string}>
     */
    public static function action_identities(array $actions): array {
        return array_map(
            static fn(array $action): array => [
                'declaration_hash' => hash('sha256', Canon::encode($action)),
                'index' => (int) ($action['index'] ?? 0),
                'manifest' => (string) ($action['manifest'] ?? ''),
            ],
            $actions
        );
    }

    /**
     * The stable, closed identity of one action declaration.
     *
     * Shared by effects_inventory() above and Apply's rebuild receipts so a
     * recovery operator correlating a receipt with a declared effect compares
     * one string produced in one place, never two independently-formatted
     * spellings of the same fact. `$index` is only reached by a declaration
     * that failed validation (validate_actions() requires `kind`), which
     * effects_inventory() can still be asked about through a frozen snapshot.
     */
    public static function action_source(array $action, int $index): string {
        $kind = $action['kind'] ?? null;
        if ($kind === 'native') {
            return 'native:' . (string) ($action['action'] ?? '?');
        }
        if ($kind === 'provider') {
            return 'provider:' . (string) ($action['provider'] ?? '?')
                . '/' . (string) ($action['capability'] ?? '?');
        }
        return "actions[$index]";
    }

    /**
     * Exact effect declaration for one structured action. Public read-only
     * evidence needs this helper because two native actions can share a
     * closed source spelling while carrying different triggers/effects.
     *
     * @return list<array<string,mixed>>
     */
    public static function action_effects(array $action, int $index): array {
        $effects = $action['effects'] ?? null;
        if (is_array($effects) && ($effects !== [] || array_key_exists('effects', $action))) {
            return $effects;
        }
        $manifest = (string) ($action['manifest'] ?? '?');
        return [self::missing_effect(
            $manifest . "-action-$index",
            'provider_resource',
            self::action_source($action, $index)
        )];
    }

    /** @return array<string,mixed> */
    private static function missing_effect(string $id, string $type, string $value): array {
        return [
            'id' => preg_replace('/[^a-z0-9._:-]+/', '-', strtolower($id)),
            'kind' => 'external',
            'mode' => 'irreversible',
            'selector' => ['scope' => 'external', 'type' => $type, 'value' => $value],
        ];
    }

    /**
     * docs/code-half.md §4.3's version_range mechanism: a manifest
     * may declare a top-level `"plugin"` (the plugin's basename, e.g.
     * "woocommerce/woocommerce.php" — the same string active_plugins/
     * get_plugins() key on) alongside `"version_range": {"min","max"}`
     * (min inclusive, max exclusive). Deliberately {min,max} + two
     * version_compare() calls, not a semver-range constraint string: the
     * agent is dependency-free (DESIGN.md §4 — "a drop-in agent must not
     * vendor libraries"), and a real semver-range parser is exactly the
     * dependency that rules out. First declaration in pin order wins per
     * plugin — same precedence as block_attr_rules(); in practice
     * AdapterContractGrammar::validate_no_conflicting_adapter_claims() has already refused two
     * pinned manifests naming one plugin with different ranges, so this
     * accessor never actually arbitrates.
     *
     * WP-5.5 keeps that true by removing the arbitration rather than by
     * teaching this walk to arbitrate. Where TWO pinned manifests claim one
     * plugin and `site.wprism.json` `policy.adapter_claims` says which is in
     * force (spec/repo-format.md § v3.13), the manifests that are NOT in force
     * are skipped, so the answer is the operator's written decision and not
     * this loop's traversal order. With no resolution declared the map is
     * pin-order-first exactly as it always was, because there is nothing to
     * skip: the guard above refused before the site could reach this line.
     *
     * Deploy::code_mismatch() / Apply::build_plan()'s code_mismatch bucket
     * and issue #3338's provider negotiation (Providers::negotiate(), which
     * bounds a plugin-owned provider by the same declared range that bounds
     * its manifest's classification guarantees) are this accessor's readers.
     *
     * @return array<string, array{min:string, max:string, manifest:string}> keyed by plugin basename
     */
    public function version_ranges(): array {
        $inForce = AdapterClaimResolutions::in_force($this->site['policy'] ?? [], 'plugin');
        $out = [];
        foreach ($this->manifests as $m) {
            $plugin = $m['plugin'] ?? null;
            $range = $m['version_range'] ?? null;
            if (!is_string($plugin) || $plugin === '' || !is_array($range) || isset($out[$plugin])) {
                continue;
            }
            if (isset($inForce[$plugin]) && $inForce[$plugin] !== (string) ($m['name'] ?? '?')) {
                continue;
            }
            $out[$plugin] = [
                'min' => (string) ($range['min'] ?? '0'),
                'max' => (string) ($range['max'] ?? '999999999'),
                'manifest' => (string) ($m['name'] ?? '?'),
            ];
        }
        return $out;
    }

    /**
     * issue #3222: theme twin of version_ranges() above — same {min,max} +
     * version_compare() shape, same first-pin-order-wins internal fallback
     * (never actually exercised in practice: validate_no_conflicting_
     * adapter_claims() at load time already refuses two pinned manifests
     * naming the same theme with different ranges, so this accessor's only
     * reader — Deploy::code_mismatch() — always sees a pre-validated,
     * unambiguous answer by the time it asks). Deliberately theme-
     * directory-keyed (not template/stylesheet-slot-keyed), for the same
     * reason version_ranges() is plugin-basename-keyed rather than
     * active_plugins-index-keyed — the CONTRACT is about an installed
     * artifact's identity, not which options field happens to name it on a
     * given environment; a manifest pinning a parent theme applies equally
     * whether that theme is loaded via `template` or `stylesheet`.
     *
     * @return array<string, array{min:string, max:string, manifest:string}> keyed by theme directory name
     */
    public function theme_ranges(): array {
        // WP-5.5, the twin of version_ranges()'s own skip: a resolved theme
        // claim answers to the operator's decision rather than to pin order.
        $inForce = AdapterClaimResolutions::in_force($this->site['policy'] ?? [], 'theme');
        $out = [];
        foreach ($this->manifests as $m) {
            $theme = $m['theme'] ?? null;
            $range = $m['theme_version_range'] ?? null;
            if (!is_string($theme) || $theme === '' || !is_array($range) || isset($out[$theme])) {
                continue;
            }
            if (isset($inForce[$theme]) && $inForce[$theme] !== (string) ($m['name'] ?? '?')) {
                continue;
            }
            $out[$theme] = [
                'min' => (string) ($range['min'] ?? '0'),
                'max' => (string) ($range['max'] ?? '999999999'),
                'manifest' => (string) ($m['name'] ?? '?'),
            ];
        }
        return $out;
    }

    /**
     * Version-1 deletion capabilities are exact entity selectors
     * (`post:page`, `term:category`, `menu:nav_menu`, or
     * `table:nf3_forms`). Multiple pinned manifests may add guards to the
     * same selector, but their cascade contract must agree exactly.
     *
     * @return ?array{cascades:string[],guards:array<int,array<string,mixed>>,declared_by:string[]}
     */
    public function deletion_capability(string $selector): ?array {
        return $this->deletion_capability_resolver()->capability($selector);
    }

    /** Keep Policy compatibility fresh for mutable offline fixtures. */
    private function deletion_capability_resolver(): DeletionCapabilityResolver {
        return new DeletionCapabilityResolver(
            $this->manifests,
            $this->option_name_reference_resolver(),
            self::CASTS
        );
    }

    private const SECTIONS = ['options', 'post_meta', 'term_meta', 'user_meta'];
    public const CLASSES = ['authored', 'runtime', 'derived', 'env', 'managed'];
    private const CASTS = ['string', 'csv'];

    /**
     * Write one classification rule into site.wprism.json's policy overrides
     * (`wp wprism classify`'s only write path — DESIGN.md 3.1.5: "accepted
     * decisions persist to policy.yml/json"). Validates shape, then loads +
     * rewrites the file via Canon::encode so formatting stays canonical.
     */
    public static function set_rule(string $repo, string $section, string $key, array $rule): void {
        if ($section === 'scope') {
            if (!preg_match('/^(post_type|taxonomy):(.+)$/', $key, $m)) {
                throw new \RuntimeException(
                    "wprism: scope key '$key' must be post_type:<name> or taxonomy:<name>"
                );
            }
            $class = $rule['class'] ?? '';
            if (!in_array($class, ScopeGrammar::scopeClasses(), true)) {
                throw new \RuntimeException(
                    "wprism: unknown scope class '$class' (expected " . implode('|', ScopeGrammar::scopeClasses()) . ')'
                );
            }
            if (array_diff_key($rule, ['class' => true])) {
                throw new \RuntimeException('wprism: scope rules accept class only (no ref, cast, or secret override)');
            }
            $siteFile = rtrim($repo, '/') . '/site.wprism.json';
            if (!is_file($siteFile)) {
                throw self::repository_missing($siteFile);
            }
            $site = Canon::decode(Canon::read_file($siteFile));
            $site['policy']['scope'][$m[1]][$m[2]] = $rule;
            Canon::write_file($siteFile, Canon::encode($site));
            return;
        }
        if (!in_array($section, self::SECTIONS, true)) {
            throw new \RuntimeException(
                'wprism: unknown policy section \'' . $section . '\' (expected '
                . implode('|', array_merge(self::SECTIONS, ['scope'])) . ')'
            );
        }
        if ($key === '') {
            throw new \RuntimeException('wprism: policy key must not be empty');
        }
        if (array_key_exists(NativeValueValidation::FIELD, $rule)) {
            throw new \RuntimeException('wprism: native value validation requires an adapter-owned declaration, not a site policy override');
        }
        $class = $rule['class'] ?? '';
        if (!in_array($class, self::CLASSES, true)) {
            throw new \RuntimeException(
                "wprism: unknown class '$class' (expected " . implode('|', self::CLASSES) . ')'
            );
        }
        if (isset($rule['ref']) && !preg_match('/^(post|term|user)(\[\])?$/', (string) $rule['ref'])) {
            throw new \RuntimeException(
                "wprism: invalid ref '{$rule['ref']}' (expected post|term|user, optionally suffixed with [])"
            );
        }
        if (isset($rule['cast']) && !in_array($rule['cast'], self::CASTS, true)) {
            throw new \RuntimeException("wprism: invalid cast '{$rule['cast']}' (expected " . implode('|', self::CASTS) . ')');
        }
        if (isset($rule['allow_secret']) && !is_bool($rule['allow_secret'])) {
            throw new \RuntimeException('wprism: allow_secret must be a boolean');
        }
        if (isset($rule['allow_pii']) && !is_bool($rule['allow_pii'])) {
            throw new \RuntimeException('wprism: allow_pii must be a boolean');
        }
        if ($section === 'user_meta') {
            UserMetaGrammar::validate_user_meta_rule($rule, "user_meta.$key", self::CLASSES, self::MISSING_USER_MODES);
        } elseif (isset($rule['missing_user'])) {
            throw new \RuntimeException('wprism: missing_user is valid only for user_meta rules');
        }
        if ($section !== 'options'
            && (array_key_exists('autoload', $rule) || array_key_exists('required', $rule))) {
            throw new \RuntimeException('wprism: autoload and required are valid only for options rules');
        }

        $siteFile = rtrim($repo, '/') . '/site.wprism.json';
        if (!is_file($siteFile)) {
            throw self::repository_missing($siteFile);
        }
        $site = Canon::decode(Canon::read_file($siteFile));
        if ($section === 'options') {
            self::assert_option_rule_loads($key, $rule, (array) ($site['policy'] ?? []));
        }
        $site['policy'][$section][$key] = $rule;
        Canon::write_file($siteFile, Canon::encode($site));
    }

    /**
     * The loader's own option grammar, run at the write boundary (issue #3496).
     *
     * `classify` used to write `options.<key> = {"class":"authored"}` and
     * exit 0; the very next command refused the document it had just
     * produced — "site.wprism.json options.legacy_banner needs autoload=preserve
     * or an explicit supported autoload value", or the env twin demanding an
     * explicit boolean `required` (agent/src/Grammar/OptionGrammar.php:76-96
     * and :42-56) — because SitePolicyValidator runs both on every
     * Policy::load(). Running them here, with the same 'site.wprism.json' label,
     * makes the refusal identical but arrives before the bytes land, so
     * nothing has to be repaired by hand.
     *
     * Scoped to the ONE rule being written plus the document's own
     * `option_autoload` default rather than the whole policy: an already
     * incomplete row elsewhere (written by the defect this fixes) must not
     * block the classify that repairs a different key, and the default is the
     * legitimate site-level way an operator can already have answered the
     * autoload question for every row at once (proven offline: a
     * `policy.option_autoload` document accepts an authored rule with no
     * per-row flag).
     *
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $policy the site document's `policy` object
     */
    private static function assert_option_rule_loads(string $key, array $rule, array $policy): void {
        $class = $rule['class'] ?? '';
        // An inert field is a decision that silently does nothing: the grammar
        // reads `autoload` only for authored/managed rules and `required` only
        // for env rules, so accepting either anywhere else would record an
        // operator's answer that no consumer ever asks for.
        if (array_key_exists('autoload', $rule) && !in_array($class, ['authored', 'managed'], true)) {
            throw new \RuntimeException(
                "wprism: options.$key declares autoload with class=$class; the storage flag is read only for authored and managed option rules"
            );
        }
        if (array_key_exists('required', $rule) && $class !== 'env') {
            throw new \RuntimeException(
                "wprism: options.$key declares required with class=$class; the provisioning decision is read only for env option rules"
            );
        }
        $probe = ['options' => [$key => $rule]];
        if (array_key_exists('option_autoload', $policy)) {
            $probe['option_autoload'] = $policy['option_autoload'];
        }
        OptionGrammar::validate_option_storage($probe, 'site.wprism.json');
        OptionGrammar::validate_env_options($probe, 'site.wprism.json');
    }

    /**
     * Draft-manifest export (DESIGN.md 3.1.5: "accepted decisions ...
     * shareable upstream as draft manifests"): every rule in THIS site's own
     * policy overrides (not inherited manifest rules — the human is
     * promoting decisions they made) whose key matches $matchRegex, grouped
     * into a manifest-shaped {name, options, post_meta, term_meta, user_meta}
     * structure. Reads site.wprism.json; never writes it — promotion is a
     * deliberate, separate human act (`wp wprism policy-to-manifest` only
     * prints to stdout).
     */
    public static function export_manifest(
        string $repo,
        string $matchRegex,
        string $name,
        ?AdapterLibrary $adapterLibrary = null
    ): array {
        $policy = self::load($repo, adapterLibrary: $adapterLibrary);
        $sitePolicy = $policy->site['policy'] ?? [];

        // issue #3247 made spec_version mandatory at load() — sourced from the
        // canonical constant, never a literal, so this can never drift out
        // of sync with what load() actually requires the way it silently
        // did before (this export wrote no spec_version at all until
        // issue #3284 caught it live: an exported manifest the engine's own
        // loader refused, found via a sandbox/tests/ run that finally
        // exercised the full export-then-reload path).
        $specVersion = defined('WPRISM_SPEC_VERSION') ? WPRISM_SPEC_VERSION : 0;
        return PolicyWriter::export_manifest($sitePolicy, $matchRegex, $name, $specVersion, self::SECTIONS);
    }

    /**
     * The closed VALUE vocabularies this class refuses against — the legal
     * values of a declared field — keyed by the grammar name an adapter author
     * sees (issue #3327).
     *
     * Bounded on purpose, and the boundary is published with the document (see
     * ManifestValidate::emitSchema()'s `coverage` field) rather than left for a
     * consumer to discover:
     *
     *   - VALUE vocabularies only. The closed KEY vocabularies — which keys an
     *     `actions[]` entry may carry, the exact five a `providers[]` entry
     *     requires, the `invalidate` key set, a table declaration's own section
     *     names — are equally closed and equally refused, and none of them is
     *     here. They are per-surface allowlists computed at their refusal site
     *     (several depend on a sibling value, e.g. an action's legal key set is
     *     a function of its `kind`), so publishing them as flat sets would
     *     publish something the engine does not have.
     *   - Unconditional sets only. Where a value is legal only in combination
     *     with another (`prevented` needs kind ∈ mail/http/queue, a `database`
     *     effect needs selector.type ∈ table/option, `restorable` needs
     *     database_checkpoint scope), the CONDITION is not expressible here and
     *     is not expressed: this map says what the engine's alphabet is, never
     *     which sentences are well-formed.
     *   - Pin-dependent vocabularies publish their ENGINE-OWNED BASE only, named
     *     as such (see below).
     *
     * Additive, read-only, and deliberately assembled from the same consts the
     * validators themselves read — never from a second list. An offline
     * validator, an editor completion source, or a published grammar document
     * that restated these sets would be a second spelling of the engine's
     * vocabulary, free to say `verbatim` is legal a release after the engine
     * stopped accepting it. The one honest way to publish a closed set is to
     * hand back the exact value the refusal consults, which is all this does:
     * no computation, no normalization, no ordering change (declared order is
     * load-bearing in the refusal messages that print these sets).
     *
     * Not every vocabulary here is a flat list. `pattern_keys` and
     * `post_derivable_fields` are maps because the engine's own declaration is
     * a map, and flattening them here would lose the half a reader needs.
     *
     * Vocabularies whose legal values depend on which manifests are pinned —
     * ref/token/ledger kinds, which union their engine base with every declared
     * table `id_kind` — publish the ENGINE-OWNED BASE only, named as such. The
     * declared half is a property of a pin set, not of this engine, and is
     * reported per-run by whatever loaded those manifests.
     *
     * @return array<string, array<int|string, mixed>>
     */
    public static function closed_vocabularies(): array {
        return [
            'classification_classes' => self::CLASSES,
            'classification_sections' => self::SECTIONS,
            'scope_classes' => ScopeGrammar::scopeClasses(),
            'value_casts' => self::CASTS,
            'pattern_keys' => PolicyRuleResolver::pattern_keys(),
            'option_autoload_values' => OptionState::AUTOLOAD_VALUES,
            'option_autoload_sentinels' => OptionGrammar::optionAutoloadSentinels(),
            'dynamic_option_resolvers' => SubKeyGrammar::dynamic_option_resolvers(),
            'user_meta_missing_user_modes' => self::MISSING_USER_MODES,
            'post_derivable_fields' => self::DERIVABLE_FIELD_COLUMNS,
            'post_field_classes' => self::FIELD_CLASSES,
            'post_type_body_modes' => PostTypeGrammar::bodyModes(),
            'feature_gated_post_type_body_modes' => PostTypeGrammar::featureGatedBodyModes(),
            'post_type_phases' => PostTypeGrammar::postTypePhases(),
            'menu_derivable_fields' => self::MENU_DERIVABLE_FIELDS,
            'menu_field_classes' => self::MENU_FIELD_CLASSES,
            'table_classes' => ManifestGrammar::tableClasses(),
            'table_identity_modes' => ManifestGrammar::identityModes(),
            'engine_ref_kinds' => ReferenceKindGrammar::engineRefKinds(),
            'engine_token_kinds' => ReferenceKindGrammar::engineTokenKinds(),
            'engine_ledger_kinds' => ReferenceKindGrammar::engineLedgerKinds(),
            'attribute_value_types' => AttributeGrammar::attributeValueTypes(),
            'attribute_tokenize_codecs' => AttributeGrammar::attributeTokenizeCodecs(),
            'attribute_id_types' => AttrIdCodecGrammar::idTypes(),
            'column_codec_containers' => ColumnCodecGrammar::containers(),
            'column_codec_leaves' => ColumnCodecGrammar::leafCodecs(),
            'widget_setting_codecs' => ManifestGrammar::widgetSettingCodecs(),
            'widget_setting_refs' => ManifestGrammar::widgetSettingRefs(),
            'action_kinds' => ActionProviderGrammar::actionKinds(),
            'action_phases' => ActionProviderGrammar::actionPhases(),
            'provider_sources' => ActionProviderGrammar::providerSources(),
            'effect_kinds' => ActionProviderGrammar::effectKinds(),
            'effect_modes' => ActionProviderGrammar::effectModes(),
            'effect_selector_scopes' => ActionProviderGrammar::effectSelectorScopes(),
            'effect_selector_types' => ActionProviderGrammar::effectSelectorTypes(),
            'provider_resource_placeholders' => ActionProviderGrammar::providerResourcePlaceholders(),
        ];
    }

    /**
     * The NAMED SUBSET of bounded string patterns the manifest grammar checks
     * against, as the exact PCRE this class hands to preg_match() (issue #3327).
     *
     * A subset, and it says so: these five are the patterns that have an
     * engine-owned NAME (a `*_PATTERN` const, referenced from more than one
     * refusal), which is what makes publishing them meaningful — a consumer can
     * bind to the name and get whatever the engine currently means by it.
     * Policy.php alone applies roughly twenty further inline PCREs (identity and
     * column-name shapes, sha-256 digests, the secret-shaped-value screens, the
     * placeholder-brace scan) that have no such name; they are deliberately
     * absent rather than scraped, because a scraped anonymous pattern would be a
     * consumer contract nobody on this side agreed to keep. The boundary is
     * published with the document (ManifestValidate::emitSchema()'s `coverage`).
     *
     * Additive companion to closed_vocabularies(), same discipline and same
     * reason: a published pattern that is not the pattern that refuses is worse
     * than no published pattern. Delimiters and modifiers are kept rather than
     * stripped so a consumer can run the identical match; a consumer that wants
     * the bare body can strip them, but this side may not decide that for it.
     *
     * @return array<string, string>
     */
    public static function grammar_patterns(): array {
        return [
            'action_trigger_surface' => self::SURFACE_PATTERN,
            'capability_name' => ActionProviderGrammar::capabilityNamePattern(),
            'effect_id' => ActionProviderGrammar::effectIdPattern(),
            'provider_id' => ActionProviderGrammar::providerIdPattern(),
            'provider_version' => ActionProviderGrammar::providerVersionPattern(),
        ];
    }
}

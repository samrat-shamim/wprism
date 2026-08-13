<?php
namespace Duo;

// Manifest validation is a pure offline pass with several entry points of
// its own (the frozen-snapshot path, the offline harnesses that load this
// file directly). The native-action vocabulary is part of that pass, so it
// is required here rather than left to duo.php's bootstrap order — same
// precedent as Deploy.php requiring CodeCompatibility.php.
require_once __DIR__ . '/NativeActions.php';
// DUO-3314: adapter provenance is decided inside the same offline pass, before
// any manifest reaches a policy consumer, so it is required here for the same
// reason NativeActions is.
require_once __DIR__ . '/AdapterSources.php';
require_once __DIR__ . '/ReferenceRules.php';
// DUO-3348 first extraction slice: the pure table/widget declaration grammar,
// required here for the same "loads alone" reason as its neighbors above.
require_once __DIR__ . '/ManifestGrammar.php';
// DUO-3348 slice 4: adapter provenance / capability-readiness resolution,
// required here for the same "loads alone" reason as its neighbors above.
require_once __DIR__ . '/AdapterRegistry.php';
// DUO-3348 slice 5: manifest-pin normalization/validation, required here for
// the same "loads alone" reason as its neighbors above.
require_once __DIR__ . '/PinResolver.php';
// DUO-3348 slice 6: action/provider/effect grammar validation, required here
// for the same "loads alone" reason as its neighbors above.
require_once __DIR__ . '/ActionProviderGrammar.php';
// DUO-3348 slice 7: cross-manifest "one owner, no contradiction" guards,
// required here for the same "loads alone" reason as its neighbors above.
require_once __DIR__ . '/CrossManifestGuards.php';
// DUO-3348 slice 8: the "named sub-key of an otherwise-atomic value"
// declaration grammar, required here for the same "loads alone" reason as
// its neighbors above.
require_once __DIR__ . '/SubKeyGrammar.php';
// DUO-3348 slice 9: exact and pattern taxonomy object_keyspace declaration
// grammar, required here for the same "loads alone" reason as its neighbors.
require_once __DIR__ . '/TaxonomyGrammar.php';
// DUO-3348 slice 11: option-name reference declaration grammar and its
// cross-manifest identical-pattern guard, required here for the same
// "loads alone" reason as its neighbors.
require_once __DIR__ . '/OptionReferenceGrammar.php';
// DUO-3348 slice 12: the closed post-type body/phase declaration grammar,
// required here for the same "loads alone" reason as its neighbors.
require_once __DIR__ . '/PostTypeGrammar.php';
// DUO-3348 slice 13: the pure option-namespace/authored-meta discovery
// grammar, required here for the same "loads alone" reason as its neighbors.
require_once __DIR__ . '/DiscoveryGrammar.php';
// DUO-3348 slice 16: option declaration/storage grammar, required here for
// the same "loads alone" reason as its neighbors.
require_once __DIR__ . '/OptionGrammar.php';
// DUO-3348 slice 17: block/shortcode attribute declaration grammar, required
// here for the same "loads alone" reason as its neighbors.
require_once __DIR__ . '/AttributeGrammar.php';
// DUO-3348 slice 18: pure reference-valued declaration shape grammar,
// required here for the same "loads alone" reason as its neighbors above.
require_once __DIR__ . '/ReferenceShapeGrammar.php';
// DUO-3348 slice 19: pure post/menu field declaration grammar, required here
// for the same "loads alone" reason as its neighbors above.
require_once __DIR__ . '/FieldGrammar.php';
// DUO-3348 slice 20: user-meta safety grammar, required here for the same
// "loads alone" reason as its neighbors above.
require_once __DIR__ . '/UserMetaGrammar.php';
// DUO-3348 slice 21: whole-entity scope declaration grammar, required here
// for the same "loads alone" reason as its neighbors above.
require_once __DIR__ . '/ScopeGrammar.php';
// DUO-3348 slice 22: adapter compatibility contract grammar, required here
// for the same "loads alone" reason as its neighbors above.
require_once __DIR__ . '/AdapterContractGrammar.php';
// DUO-3348 slice 23: cross-source reference-keyspace and attached-meta
// ownership grammar, required here for the same "loads alone" reason as its
// neighbors above.
require_once __DIR__ . '/ReferenceKeyspaceGrammar.php';
// DUO-3348 slice 24: ref/token/ledger kind vocabulary grammar, required here
// for the same "loads alone" reason as its neighbors above.
require_once __DIR__ . '/ReferenceKindGrammar.php';
// DUO-3348 slice 25: optional site code-declaration grammar, required here
// for the same "loads alone" reason as its neighbors above. The grammar
// preserves Policy's pre-existing implicit Code boundary; it does not load
// Code.php or its materialization graph transitively.
require_once __DIR__ . '/CodeConfigGrammar.php';
// DUO-3348 slice 27: pure manifest export projection, required here so the
// stable Policy::export_manifest() facade remains independently loadable.
require_once __DIR__ . '/PolicyWriter.php';
// DUO-3348 slice 28: shared per-manifest validation orchestration, required
// here so the live and frozen loaders retain one grammar pipeline.
require_once __DIR__ . '/ManifestValidator.php';
// DUO-3348 slice 29: the site.duo.json policy envelope has one shared
// validation sequence for live and frozen loaders, required here so both
// entry points retain the same standalone load graph and refusal order.
require_once __DIR__ . '/SitePolicyValidator.php';
// DUO-3348 slice 36: live and frozen loads share one post-local-load
// validation/pin-binding sequence, so keep its refusal order in one place.
require_once __DIR__ . '/PolicyLoadFinalizer.php';
// DUO-3348 slice 37: pure dynamic-option declaration resolution is separate
// from Policy's public compatibility/query surface and caller-owned live values.
require_once __DIR__ . '/DynamicOptionResolver.php';
// DUO-3348 slice 47: taxonomy-pattern declaration normalization and concrete
// matching are pure manifest work; Policy retains the live taxonomy discovery
// query and the public compatibility facades below.
require_once __DIR__ . '/TaxonomyPatternResolver.php';
// DUO-3348 slice 48: taxonomy relationship-keyspace resolution consumes only
// exact declarations and the pure taxonomy-pattern contract, never live DB state.
require_once __DIR__ . '/TaxonomyKeyspaceResolver.php';
// DUO-3348 slice 45: pure option-name reference declaration resolution is
// separate from Policy's public compatibility/query surface and live callers.
require_once __DIR__ . '/OptionNameReferenceResolver.php';
// DUO-3348 slice 46: pure deletion-capability declaration resolution is
// separate from Policy's public compatibility/query surface and live callers.
require_once __DIR__ . '/DeletionCapabilityResolver.php';
// DUO-3348 slice 40: manifest-declared post-type relationship queries are
// pure and reusable by scope/planning without broadening their authority.
require_once __DIR__ . '/PostTypeRelationResolver.php';

/**
 * Layered classification policy: site policy overrides > pinned manifests
 * (in pin order) > option name-patterns. Anything unmatched is unclassified,
 * and unclassified is a loud abort at the call sites (never a silent guess).
 */
final class Policy {
    /**
     * The one {min,max} version-range predicate, shared by every site that
     * bounds something by an exact, certifiable window: min and max are both
     * non-empty version strings and min is strictly less than max (min
     * inclusive, max exclusive — the same version_compare() arithmetic
     * CapabilityRegistry::in_range() applies at negotiation). Wildcards,
     * empty, and unbounded forms are not certifiable and are refused. $where
     * names the coordinate so one message serves every caller: this
     * project's own discovery-contract keyspace versioning, the plugin/theme
     * adapter version_range contract, and (via ActionProviderGrammar, a
     * DUO-3348 slice 6 extraction) the provider `requires` grammar's three
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
                "duo: $where has a malformed range (min=" . var_export($min, true)
                . ', max=' . var_export($max, true) . ') — both must be non-empty version strings '
                . 'with min strictly less than max; wildcards/empty/unbounded are not certifiable'
            );
        }
    }

    // v4 adds the required `adapter_sources` record (DUO-3314). It is required
    // rather than optional on purpose: if a snapshot could omit it and have
    // every manifest default to "shipped", dropping one key would silently
    // launder an out-of-tree adapter into a shipped one on the verification
    // path, which is exactly the provenance guarantee this record exists for.
    // v5 carries signed site-adapter certification envelopes. from_snapshot()
    // retains v4 reads only for the prior uncertified adapter-sources/v1 form.
    private const SNAPSHOT_FORMAT = 'duo-policy-snapshot/v5';
    private const LEGACY_SNAPSHOT_FORMAT = 'duo-policy-snapshot/v4';
    /**
     * The exact canonical-surface literal grammar. Apply derives these keys
     * from authored work as a pure projection (Apply::rebuild_surfaces()) and
     * manifests match them literally in `actions[].triggers`; DUO-3338's
     * provider capabilities describe their own reads/writes in the same
     * vocabulary, so it is a shared constant rather than two regexes that can
     * drift into accepting different names for the same surface.
     */
    public const SURFACE_PATTERN = '/^(post|term|table|option|entity):[a-z0-9][a-z0-9._-]{0,127}$/D';

    public array $site = [];
    /** @var array<int, array> */
    public array $manifests = [];
    /** External review state; null for legacy/custom manifest directories without a registry. */
    private ?ManifestDispositions $manifestDispositions = null;
    /** Which source installed each pinned adapter, and what that origin may do (DUO-3314). */
    private ?AdapterSources $adapterSources = null;
    /** Generated evidence/platform projection of the reviewed dispositions. */
    private ?CapabilityRegistry $capabilityRegistry = null;
    /** @var array<string, object>|null lazily-built interpreter instances */
    private ?array $interpreterInstances = null;
    /** @var array<string, object>|null lazily-built regenerator instances (DUO-3234) */
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
     * semantics live in data, not in a static key list. option_rule() (DUO-3263)
     * is consulted only for an option NAME already namespace-owned by some
     * manifest's option_namespaces declaration — unlike the meta hooks, an
     * interpreter has no implicit reach over every option in the table.
     *
     * Interpreter CODE is part of the manifest artifact, never the engine:
     * a declared name resolves to <manifests_dir>/interpreters/<name>.php,
     * which must define \Duo\Interpreters\<CamelCase(name)>. The engine holds
     * only this loading contract — no plugin names, no plugin logic. Trust
     * boundary: the manifests dir is operator-controlled and ships/mounts
     * with the agent itself (ro in the sandbox), so loading PHP from it is
     * the same trust decision as running the agent.
     */

    public static function manifests_dir(): string {
        $env = getenv('DUO_MANIFESTS_DIR');
        if ($env && is_dir($env)) {
            return $env;
        }
        $local = dirname(__DIR__, 2) . '/manifests';
        if (is_dir($local)) {
            return $local;
        }
        return '/duo-manifests';
    }

    /**
     * V1 is deliberately single-site. Refuse before policy/repository reads
     * so a network install cannot be mistaken for a supported convergence
     * surface and no command can publish a partial single-blog projection.
     * The function guard keeps the pure offline policy validators usable
     * outside WordPress while the real product path always has is_multisite().
     */
    private static function assert_single_site(): void {
        if (function_exists('is_multisite') && is_multisite()) {
            throw new \RuntimeException(
                'duo: multisite is unsupported by the certified v1 contract; '
                . 'this command is single-site only and refuses before loading policy or mutating state'
            );
        }
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

    public static function load(
        ?string $repo,
        ?array $manifestNames = null,
        bool $allowUnsupportedSiteForReadOnlyCapabilities = false,
        ?string $adapterRepo = null
    ): self {
        if (!$allowUnsupportedSiteForReadOnlyCapabilities) {
            self::assert_single_site();
        }
        $p = new self();
        if ($repo !== null) {
            $siteFile = rtrim($repo, '/') . '/site.duo.json';
            if (!is_file($siteFile)) {
                throw new \RuntimeException("duo: $siteFile not found (not a duo site repo?)");
            }
            $p->site = Canon::decode(Canon::read_file($siteFile));
            SitePolicyValidator::validate(
                $p->site,
                'site.duo.json',
                self::CLASSES,
                self::MISSING_USER_MODES
            );
        }
        $manifestValidatorVocabulary = self::manifest_validator_vocabulary();
        $rawPins = $manifestNames ?? ($p->site['manifests'] ?? ['core']);
        $pins = PinResolver::normalize_manifest_pins($rawPins);
        $dir = self::manifests_dir();
        // DUO-3314: every installed source is scanned, and ambiguous identity or
        // shadowing refused, before the first pin resolves — a broken adapter
        // installation must not wait for a pin to reveal itself.
        // Init needs source-aware validation before site.duo.json exists. Its
        // fourth argument supplies only the repository-owned adapter source;
        // ordinary loads continue to derive both config and source from $repo.
        $p->adapterSources = AdapterSources::discover($dir, $adapterRepo ?? $repo);
        PinResolver::validate_manifest_sources($pins, $p->adapterSources);
        $p->manifestDispositions = class_exists(ManifestDispositions::class)
            ? ManifestDispositions::load($dir)
            : null;
        foreach ($pins as $pin) {
            $name = $pin['name'];
            // normalize_manifest_pins() has already proved this exact identity
            // path-free and canonical; never rewrite it into a different key.
            $key = $name;
            $manifest = Canon::decode(Canon::read_file($p->adapterSources->file($key, $dir)));
            // DUO-3371: the earliest point on the live load path where a
            // manifest's FILE name and its DECLARED name are both in hand, and
            // therefore the only place one identity can be enforced for both
            // keyings. The pin, the file, and ManifestDispositions::load()'s
            // coverage check all key off the file name; ManifestDispositions::
            // entry(), RepositoryCompiler::manifest_rows()'s per-adapter digest,
            // and the capability registry's claims all key off the declared
            // name. Every one of those declared-name lookups is downstream of
            // this line — nothing reads $p->manifests before it exists — so
            // refusing here, ahead of the first validator, is what keeps one
            // adapter from answering to two keys. from_snapshot() has always
            // refused the same disagreement against the frozen pin; this is the
            // live path's half of that, and AdapterSources owns the sentence so
            // the site source (DUO-3314) and the shipped source say it once.
            //
            // DUO-3339/B2: for the PLUGIN source this is a TAUTOLOGY, and
            // deliberately kept. Identity inverts there — every bundle is
            // named `duo-adapter.json`, so the file name carries none and the
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
        PolicyLoadFinalizer::finalize($p, $pins);
        if ($p->manifestDispositions !== null && class_exists(CapabilityRegistry::class)) {
            // Only the shipped subset is a registry claim. Handing an
            // out-of-tree manifest to registry validation would demand a claim
            // that cannot exist, so one site-installed adapter would refuse
            // every unrelated shipped adapter along with itself — the exact
            // failure DUO-3314 exists to remove.
            $p->capabilityRegistry = CapabilityRegistry::load(
                $dir,
                $p->manifestDispositions,
                $p->adapterSources->shipped_manifests($p->manifests)
            );
            if ($p->capabilityRegistry === null) {
                throw new \RuntimeException(
                    "duo: $dir has manifest dispositions but no generated capability registry; "
                    . 'missing registry data is unsupported'
                );
            }
        }
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
            'format' => ($adapterSources['format'] ?? null) === AdapterSources::LEGACY_FORMAT
                ? self::LEGACY_SNAPSHOT_FORMAT
                : self::SNAPSHOT_FORMAT,
            'site' => $this->site,
            'manifests' => $this->manifests,
            'adapter_sources' => $adapterSources,
            'dispositions' => $this->manifestDispositions?->data(),
            'capabilities' => $this->capabilityRegistry?->data(),
        ];
    }

    /** Reconstruct and fully validate a policy exported by export_snapshot(). */
    public static function from_snapshot(array $snapshot): self {
        self::assert_single_site();
        $keys = array_keys($snapshot);
        sort($keys, SORT_STRING);
        $snapshotFormat = $snapshot['format'] ?? null;
        if ($keys !== ['adapter_sources', 'capabilities', 'dispositions', 'format', 'manifests', 'site']
            || !in_array($snapshotFormat, [self::LEGACY_SNAPSHOT_FORMAT, self::SNAPSHOT_FORMAT], true)
            || !is_array($snapshot['adapter_sources'] ?? null)
            || !is_array($snapshot['site'] ?? null)
            || !is_array($snapshot['manifests'] ?? null)
            || !array_is_list($snapshot['manifests'])) {
            throw new \RuntimeException('duo: frozen policy snapshot has an unsupported or malformed shape');
        }
        $adapterSourceFormat = $snapshot['adapter_sources']['format'] ?? null;
        if (($snapshotFormat === self::LEGACY_SNAPSHOT_FORMAT
                && $adapterSourceFormat !== AdapterSources::LEGACY_FORMAT)
            || ($snapshotFormat === self::SNAPSHOT_FORMAT
                && $adapterSourceFormat !== AdapterSources::FORMAT)) {
            throw new \RuntimeException(
                'duo: frozen policy snapshot format disagrees with its adapter source record format'
            );
        }

        $p = new self();
        $p->site = $snapshot['site'];
        SitePolicyValidator::validate(
            $p->site,
            'frozen site.duo.json',
            self::CLASSES,
            self::MISSING_USER_MODES
        );
        $manifestValidatorVocabulary = self::manifest_validator_vocabulary();

        $pins = PinResolver::normalize_manifest_pins($p->site['manifests'] ?? ['core']);
        if (count($pins) !== count($snapshot['manifests'])) {
            throw new \RuntimeException('duo: frozen policy snapshot manifest count disagrees with site pins');
        }
        foreach ($snapshot['manifests'] as $i => $manifest) {
            if (!is_array($manifest) || array_is_list($manifest)) {
                throw new \RuntimeException("duo: frozen policy snapshot manifests[$i] is not an object");
            }
            $name = (string) ($manifest['name'] ?? '');
            if ($name === '' || !hash_equals((string) $pins[$i]['name'], $name)) {
                throw new \RuntimeException("duo: frozen policy snapshot manifest order/name disagrees with site pin $i");
            }
            ManifestValidator::validate_manifest(
                $manifest,
                "frozen manifest '$name'",
                $manifestValidatorVocabulary,
                true
            );
            $p->manifests[] = $manifest;
        }
        // Provenance is reconstructed before the reviewed registries so both of
        // them see the same shipped subset load() gave them (DUO-3314).
        $p->adapterSources = AdapterSources::from_snapshot($snapshot['adapter_sources'], $p->manifests);
        PinResolver::validate_manifest_sources($pins, $p->adapterSources);
        $shipped = $p->adapterSources->shipped_manifests($p->manifests);
        $dispositions = $snapshot['dispositions'] ?? null;
        if ($dispositions !== null) {
            if (!is_array($dispositions) || !class_exists(ManifestDispositions::class)) {
                throw new \RuntimeException('duo: frozen policy snapshot disposition registry is unavailable or malformed');
            }
            $p->manifestDispositions = ManifestDispositions::from_snapshot($dispositions, $shipped);
        }
        $capabilities = $snapshot['capabilities'] ?? null;
        if ($capabilities !== null) {
            if (!is_array($capabilities) || $p->manifestDispositions === null
                || !class_exists(CapabilityRegistry::class)) {
                throw new \RuntimeException('duo: frozen policy snapshot capability registry is unavailable or malformed');
            }
            $p->capabilityRegistry = CapabilityRegistry::from_snapshot(
                $capabilities,
                $p->manifestDispositions,
                $shipped
            );
        } elseif ($p->manifestDispositions !== null) {
            throw new \RuntimeException('duo: frozen policy snapshot has dispositions but no capability registry');
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
        return $this->adapterSources ??= AdapterSources::discover(self::manifests_dir(), null);
    }

    /**
     * The reviewed support boundary for one pinned adapter, if this library has
     * a registry. An out-of-tree adapter has no reviewed entry and never
     * acquires one: it answers with the synthesized provenance record instead,
     * so the disposition slot that feeds RepositoryCompiler::manifest_rows()
     * binds its origin into the adapter digest exactly where a reviewed entry
     * would sit — and every shipped row keeps hashing the bytes it always did.
     *
     * DUO-3348 slice 4: the implementation now lives in
     * AdapterRegistry::manifest_disposition(); this method is a thin facade
     * kept so every existing caller needs no change.
     */
    public function manifest_disposition(string $name): ?array {
        return $this->adapter_registry()->manifest_disposition($name);
    }

    /**
     * The generated evidence-bound claim for one pinned adapter.
     *
     * DUO-3348 slice 4: the implementation now lives in
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
     * DUO-3348 slice 4: the implementation now lives in
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
     * DUO-3348 slice 4: the implementation now lives in
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
     * DUO-3348 slice 4: the implementation now lives in
     * AdapterRegistry::provider_readiness_blockers(); thin facade, as
     * manifest_disposition() above.
     */
    public function provider_readiness_blockers(array $actions): array {
        return $this->adapter_registry()->provider_readiness_blockers($actions);
    }

    /**
     * Resolve CLI capability output from the same manifests and external review bytes.
     *
     * DUO-3348 slice 4: the implementation now lives in
     * AdapterRegistry::capability_report(); thin facade, as
     * manifest_disposition() above.
     */
    public function capability_report(array $query = []): array {
        return $this->adapter_registry()->capability_report($query);
    }

    /**
     * Fresh per call, matching ConvergenceVerifier's identical relationship to
     * Apply (DUO-3347): AdapterRegistry has no state of its own to lose between
     * calls (every field is set once at load()/from_snapshot() time and read
     * from here, never mutated), so constructing on demand needs no cache.
     */
    private function adapter_registry(): AdapterRegistry {
        return new AdapterRegistry($this, $this->manifestDispositions, $this->capabilityRegistry);
    }

    /** @return ?array{format:int,layout:string,source:string} */
    public function code_config(): ?array {
        $code = $this->site['code'] ?? null;
        return is_array($code) ? $code : null;
    }

    /**
     * Pattern-fallback manifest arrays, keyed by the section they apply to.
     * `option_patterns` predates this map (kept as its original name for
     * backward compat with shipped manifests, e.g. core.json's
     * `^_transient_` rule); `meta_patterns` is new (task #11 wave 2 /
     * docs/frontier/elementor.md's finding: "post_meta/term_meta
     * classification has no pattern-matching escape hatch" — Elementor's
     * `_elementor_migrations_state_<hash>` is exactly the versioned-suffix
     * shape that needs it). Deliberately NOT post-type-scoped, unlike the
     * report's own suggestion: exact-match post_meta/term_meta rules
     * already aren't post-type-scoped in this engine (Policy::rule() has
     * never taken a post type), so a pattern fallback that suddenly needed
     * one would be a new, inconsistent axis rather than "mirroring
     * option_patterns" — a meta key name is either safe to classify by
     * pattern everywhere it appears, or it isn't; a plugin's own key-naming
     * convention already makes collisions with an unrelated plugin's keys
     * exceedingly unlikely, the same trust the exact-match case already
     * extends. term_meta gets the same fallback for free, at zero extra
     * cost, since it shares this one lookup path.
     *
     * @var array<string, string>
     */
    private const PATTERN_KEYS = [
        'options' => 'option_patterns',
        'post_meta' => 'meta_patterns',
        'term_meta' => 'meta_patterns',
    ];

    /**
     * Resolve a policy rule together with the declaration that won. Apply's
     * repository authorization gate needs the source as evidence: a refusal
     * that only says "runtime" but not whether site.duo.json or which pinned
     * manifest made that decision is not actionable enough to repair safely.
     *
     * DUO-3249: a NON-core manifest's own declaration of a name ALSO
     * declared by the core manifest always outranks core's, regardless of
     * relative pin order. This is not a general "later pin wins" rule (see
     * the loop below — among two or more NON-core manifests declaring the
     * same name, the FIRST one in pin order still wins, completely
     * unchanged from before this fix; that remaining ambiguity is a
     * separate, undecided question, DUO-3255, deliberately not touched
     * here). It specifically encodes DESIGN.md §3.1's own numbered
     * precedence order — "1. Core schema rules" then "2. Plugin manifests"
     * — as an actual load-bearing precedence rather than merely descriptive
     * prose: every shipped site.duo.json pins `core` FIRST (grep-verified,
     * not assumed), so a plain first-pin-order walk would have let core's
     * own declaration win over ANY later plugin manifest's deliberate
     * reclassification of the same option, every single time, silently —
     * exactly backwards from "layer 2 refines layer 1", and exactly what
     * left Polylang's per-language `default_category` divergence
     * undetected until live grind evidence forced the question (DUO-3249).
     * A plugin manifest reclassifying a core option is therefore always
     * loud and deliberate by construction (it only ever WINS, never
     * silently collides) — `Policy::active_reclassifications()` is what
     * makes it plan-visible too, so "loud" extends to runtime output, not
     * just load-time precedence.
     *
     * @return array{rule:?array, source:?string}
     */
    private function rule_details(string $section, string $name): array {
        $sitePolicy = $this->site['policy'][$section][$name] ?? null;
        if ($sitePolicy !== null) {
            return [
                'rule' => $section === 'options'
                    ? self::with_option_autoload($sitePolicy, $this->site['policy'] ?? [])
                    : $sitePolicy,
                'source' => 'site.duo.json',
            ];
        }
        $coreMatch = null;
        foreach ($this->manifests as $m) {
            if (!isset($m[$section][$name])) {
                continue;
            }
            $found = [
                'rule' => $section === 'options'
                    ? self::with_option_autoload($m[$section][$name], $m)
                    : $m[$section][$name],
                'source' => (string) ($m['name'] ?? '?'),
            ];
            if ($found['source'] === 'core') {
                $coreMatch = $found; // keep scanning: a non-core manifest's own declaration still outranks this
                continue;
            }
            return $found;
        }
        if ($coreMatch !== null) {
            return $coreMatch;
        }
        $patternKey = self::PATTERN_KEYS[$section] ?? null;
        if ($patternKey !== null) {
            foreach ($this->manifests as $m) {
                foreach ($m[$patternKey] ?? [] as $pat) {
                    if (preg_match('/' . $pat['match'] . '/', $name)) {
                        return [
                            'rule' => $section === 'options'
                                ? self::with_option_autoload(array_diff_key($pat, ['match' => true]), $m)
                                : array_diff_key($pat, ['match' => true]),
                            'source' => (string) ($m['name'] ?? '?'),
                        ];
                    }
                }
            }
        }
        return ['rule' => null, 'source' => null];
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

    /**
     * Return the manifest namespace that claims discovery responsibility for
     * an option name. A namespace is deliberately ownership-only: it does
     * not classify the value. The ordinary exact/pattern rule must still do
     * that, otherwise Capture/Pending report the row as an unknown.
     *
     * @return ?array{owner:string, match:string}
     */
    public function option_namespace(string $name): ?array {
        $matches = [];
        foreach ($this->manifests as $m) {
            foreach ($m['option_namespaces'] ?? [] as $decl) {
                if (preg_match('/' . $decl['match'] . '/', $name)) {
                    $matches[] = [
                        'owner' => (string) ($m['name'] ?? '?'),
                        'match' => (string) $decl['match'],
                    ];
                }
            }
        }
        if (count($matches) > 1) {
            throw new \RuntimeException(
                "duo: option '$name' is claimed by overlapping namespaces from "
                . implode(', ', array_map(fn($m) => $m['owner'], $matches))
                . ' — discovery ownership must not depend on manifest load order'
            );
        }
        return $matches[0] ?? null;
    }

    /** Classification for a namespace-owned option, with owner agreement. */
    public function owned_option_rule(string $name): ?array {
        $owner = $this->option_namespace($name);
        if ($owner === null) {
            return null;
        }
        $details = $this->option_rule_details($name);
        if ($details['rule'] !== null && $details['source'] !== 'site.duo.json'
            && $details['source'] !== $owner['owner']) {
            throw new \RuntimeException(
                "duo: option '$name' namespace is owned by '{$owner['owner']}' but its classification comes from "
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

    public function user_meta_rule(string $key): ?array {
        return $this->rule('user_meta', $key);
    }

    public function table_rule(string $unprefixedTable): ?array {
        return $this->declared_table_details($unprefixedTable)['rule'];
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
        $rule = null;
        $source = null;
        foreach ($this->manifests as $m) {
            if (isset($m['tables'][$name])) {
                $rule = $m['tables'][$name];
                $source = (string) ($m['name'] ?? '?');
            }
        }
        if (isset($this->site['policy']['tables'][$name])) {
            $rule = $this->site['policy']['tables'][$name];
            $source = 'site.duo.json';
        }
        return ['rule' => $rule, 'source' => $source];
    }

    /**
     * Option names classified authored (the capture whitelist).
     *
     * DUO-3255: resolved_exact_options() is the single precedence path for
     * this and the env/sub-key bulk enumerators. It delegates every name to
     * rule_details(), so bulk lookup can never silently use a different pin
     * winner than the per-name capture/apply path. Policy::load() has
     * already refused contradictory non-core declarations; identical ones
     * dedupe here, while core-yields-to-plugin and site override precedence
     * remain exactly the rule_details() contract.
     */
    public function authored_options(): array {
        $out = [];
        foreach ($this->resolved_exact_options() as $name => $r) {
            if (($r['class'] ?? '') === 'authored') {
                $out[$name] = $r;
            }
        }
        return $out;
    }

    /**
     * Options declaring `sub_keys` (DUO-3233): name => full rule (including
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
     * This is the engine capability manifests/polylang.json's own notes
     * long flagged as missing: "v0's options model classifies a whole
     * option name at once ... there is no way to keep force_lang/
     * default_lang/etc authored while excluding first_activation/version
     * without capturing them too. Building that sub-key classification
     * split is a genuinely separate, unscoped engine capability." This is
     * that capability — a NAMED sub-key of one option blob captured/
     * excluded independently, with apply-side merge into the live blob
     * (Apply::apply_option_sub_keys()) so the undeclared remainder is never
     * clobbered. DUO-3211's review comment asked for exactly this: "'exact'
     * [option] reconciliation should be written so per-key ownership can
     * later narrow to sub-key ownership without another format change" —
     * `sub_keys` on an ordinary options.<name> rule IS that narrowing, not
     * a parallel format.
     *
     * @return array<string, array{class:string, sub_keys:array<string,array>}>
     */
    public function sub_keyed_options(): array {
        $out = [];
        foreach ($this->resolved_exact_options() as $name => $r) {
            if (!empty($r['sub_keys'])) {
                $out[$name] = $r;
            }
        }
        return $out;
    }

    /**
     * Resolve every exact option name once through rule_details() — never
     * reimplement its precedence with a bulk foreach overwrite. Names from
     * site policy are included even when no manifest declares them. Pattern
     * rules remain discovery/classification rules and are deliberately not
     * enumerated here, matching these bulk APIs' historical exact-name
     * contract.
     *
     * @return array<string,array>
     */
    private function resolved_exact_options(): array {
        $names = [];
        foreach ($this->manifests as $manifest) {
            foreach ($manifest['options'] ?? [] as $name => $_rule) {
                $names[(string) $name] = true;
            }
        }
        foreach ($this->site['policy']['options'] ?? [] as $name => $_rule) {
            $names[(string) $name] = true;
        }
        $out = [];
        foreach (array_keys($names) as $name) {
            $rule = $this->option_rule_details($name)['rule'];
            if ($rule !== null) {
                $out[$name] = $rule;
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * DUO-3264 (owner ruling, fork A, issue comment 9fd882a6): "a manifest-
     * level dynamic-name resolution primitive... one new primitive, reusable
     * for any future active-theme-bound option, instead of a second bespoke
     * path beside nav_menu_locations." theme_mods_<stylesheet> is the proven
     * case (see manifests/core.json's own declaration + note) but this
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
     * manifest is expected to collide on a key here — only core.json is
     * expected to ever declare theme_mods — but the tie-break is defined
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
     * site switches back (confirmed empirically, DUO-3264: this is the same
     * shape of residue as the nested sidebars_widgets/wp_classic_sidebars
     * theme-switch bookkeeping already excluded in manifests/core.json's own
     * note). $resolvedValues maps resolver name => this environment's own
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
     * Apply::apply()'s own theme-mismatch refuse-gate (DUO-3216) already
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
     * runs as part of RepositoryCompiler::compile(), which BOTH `wp duo
     * deploy` and `wp duo apply` go through — including deploy itself, the
     * command that reconciles a theme mismatch in the first place (DUO-3216).
     * Requiring an exact match here would make deploy unable to compile the
     * very repository it needs to read to know which theme to switch to, a
     * genuine circular dependency (caught live, not by inspection: `wp duo
     * deploy` itself refused with 'theme_mods_<captured-theme>' unclassified
     * while the target was still on its PREVIOUS theme). Authorization's own
     * checks (declared sub_keys class, autoload) are already theme-agnostic
     * in substance — they validate the DECLARATION, not the live
     * environment — so this method never needed the exact-match constraint
     * dynamic_option_rule_for_name() correctly enforces for the different
     * question (never write to the wrong theme's own row), which only
     * matters once actual mutation is about to happen, well after DUO-3216's
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
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['tables'] ?? [] as $name => $r) {
                $out[$name] = $r;
            }
        }
        foreach ($this->site['policy']['tables'] ?? [] as $name => $r) {
            $out[$name] = $r;
        }
        return $out;
    }

    /**
     * Thin compatibility facades over ManifestGrammar (DUO-3348 first
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
        foreach ($this->declared_tables() as $name => $rule) {
            if (($rule['class'] ?? '') === 'authored_snapshot_meta'
                && (string) ($rule['attached_to']['table'] ?? '') === $ownerTable) {
                return ['name' => (string) $name, 'rule' => $rule];
            }
        }
        return null;
    }

    /** Closed widget type registry. Last pinned manifest wins per type. */
    public function widget_types(): array {
        $out = [];
        foreach ($this->manifests as $manifest) {
            foreach ((array) ($manifest['widgets'] ?? []) as $type => $rule) {
                $out[(string) $type] = (array) $rule;
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * The declaration source for the same last-pinned-wins widget rule that
     * widget_types() returns. Reporting callers must not reconstruct a
     * different precedence walk merely to name its owner.
     *
     * @return array{rule:?array,source:?string}
     */
    public function widget_type_rule_details(string $type): array {
        $rule = null;
        $source = null;
        foreach ($this->manifests as $manifest) {
            if (isset($manifest['widgets'][$type]) && is_array($manifest['widgets'][$type])) {
                $rule = $manifest['widgets'][$type];
                $source = (string) ($manifest['name'] ?? '?');
            }
        }
        return ['rule' => $rule, 'source' => $source];
    }

    /** blockName => list of {path, kind, type} rules, merged across manifests. */
    public function block_attr_rules(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['block_attrs'] ?? [] as $block => $rules) {
                $out[$block] = $rules;
            }
        }
        return $out;
    }

    /**
     * `shortcode_attrs` (DUO-3259): the shortcode twin of `block_attrs()`
     * above, same precedence (last pin wins per tag name, a structural
     * fact about a shortcode's own attribute grammar, not a site-local
     * policy choice — no site.duo.json override, mirroring block_attrs'
     * own reasoning exactly), but a flatter rule shape: tagName => list of
     * {path, kind, cast?} rules — no `type`, unlike block_attrs — a
     * shortcode attribute value is always flat text in the source (never
     * a native JSON array the way a block attr can be), so `cast: "csv"`
     * alone signals "comma-joined id list" (WordPress's own convention
     * for gallery's `ids`/`include`/`exclude` attributes, confirmed by
     * reading gallery_shortcode() directly, not assumed); anything not
     * csv-cast is a plain scalar id. `path` names a shortcode ATTRIBUTE
     * (not a JSON path; shortcode attributes are already a flat key=value
     * grammar, `shortcode_parse_atts()`'s own return shape).
     */
    public function shortcode_attr_rules(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['shortcode_attrs'] ?? [] as $tag => $rules) {
                $out[$tag] = $rules;
            }
        }
        return $out;
    }

    /**
     * `taxonomies.<tax>.description_refs` (spec v0.8 / docs/frontier/
     * polylang.md's "typed serialized-description rewriting"): declares
     * that a taxonomy's term_taxonomy.description column holds PHP-
     * serialized data (Polylang's post_translations/term_translations
     * `{lang_slug: local_id}` shape, verified byte-for-byte) with ref-typed
     * values reachable via the ordinary json_refs primitive at path "$.*"
     * (Capture::term_description() / Apply::encode_description() own
     * deciding how to (un)serialize; this only returns the declared rule).
     *
     * Manifest-only structural fact about the taxonomy's OWN data shape
     * (like block_attrs is a structural fact about a block type's shape),
     * not a site-local policy choice, so
     * — unlike options/post_meta/term_meta — there is no site.duo.json
     * policy override. This also sidesteps a real naming collision:
     * site.duo.json's policy.taxonomies is already the flat taxonomy-scope
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
        foreach ($this->manifests as $m) {
            if (isset($m['taxonomies'][$tax]['description_refs'])) {
                return ReferenceRules::description(
                    $m['taxonomies'][$tax]['description_refs'],
                    "manifest '" . ($m['name'] ?? '?') . "'.taxonomies.$tax.description_refs"
                );
            }
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
     * hidden part of Duo's wire contract.
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
     * `taxonomies.<tax>.object_type_from_option` (DUO-3280): declares that
     * $tax's registered object_type is additionally, DYNAMICALLY driven by
     * a sub_keys-declared option's own named sub-key (Polylang: `language`/
     * `post_translations` additionally cover whatever post types the
     * `polylang` option's own `post_types` sub-key currently names — see
     * polylang.json's own note). Pure declaration data — WHICH option,
     * WHICH sub-key — never a live value; this class stays WordPress-free
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
     * description_refs_for_taxonomy() immediately above.
     *
     * @return ?array{option:string, sub_key:string}
     */
    public function object_type_option_ref(string $tax): ?array {
        foreach ($this->manifests as $m) {
            $decl = $m['taxonomies'][$tax]['object_type_from_option'] ?? null;
            if ($decl !== null) {
                return ['option' => (string) $decl['option'], 'sub_key' => (string) $decl['sub_key']];
            }
        }
        return null;
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
     * The full in-scope taxonomy list: site.duo.json's exact
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
        $live = $wpdb->get_col("SELECT DISTINCT taxonomy FROM {$wpdb->term_taxonomy}") ?: [];
        $matched = [];
        foreach ($live as $tax) {
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
     * wp_options NAME scan this declares is Capture::build_options()'s job,
     * not this accessor's — mirrors declared_tables()/block_attr_rules()'s
     * existing split between "what did manifests declare" (pure, here) and
     * "what do we do about it against a live environment" (the DB-touching
     * caller).
     *
     * A DELIBERATE sibling of option_patterns, not a variant of it:
     * option_patterns is consulted only to CLASSIFY a key some other
     * enumeration already produced (Policy::rule()'s fallback loop);
     * Capture::build_options() is exact-whitelist-only and NEVER consults
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
     * outside this class needs it. `duo manifest-validate` (DUO-3327) calls it
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
        $this->interpreterInstances = [];
        foreach ($this->manifests as $m) {
            $name = $m['interpreter'] ?? null;
            if ($name === null || isset($this->interpreterInstances[$name])) {
                continue;
            }
            if (!preg_match('/^[a-z0-9_-]+$/', $name)) {
                throw new \RuntimeException("duo: manifest '{$m['name']}' declares invalid interpreter name '$name'");
            }
            $file = self::manifests_dir() . '/interpreters/' . $name . '.php';
            if (!is_file($file)) {
                throw new \RuntimeException(
                    "duo: manifest '{$m['name']}' wants interpreter '$name' but $file is missing — "
                    . 'interpreter code ships with its manifest, not the engine'
                );
            }
            require_once $file;
            $class = '\\Duo\\Interpreters\\' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
            if (!class_exists($class) || !method_exists($class, 'post_meta_rule')) {
                throw new \RuntimeException(
                    "duo: interpreter file $file must define $class with post_meta_rule(string, array): ?array"
                );
            }
            $this->interpreterInstances[$name] = new $class($this);
        }
        return $this->interpreterInstances;
    }

    /**
     * DUO-3234 regenerator loading — the exact same trust boundary and
     * validate/load/instantiate shape as interpreters() above (same
     * rationale: manifest-shipped PHP, engine holds only the loading
     * contract, never plugin-specific logic), deliberately mirrored rather
     * than sharing code with interpreters(), for the same reason
     * assert_meta_schema() stays separate from assert_row_schema() in
     * Snapshot.php — the two mechanisms' discovery differs enough
     * (interpreters: one name per manifest, off a top-level `interpreter`
     * key; regenerators: potentially several names per manifest, one per
     * declaring post_types{} entry's `regen_dependency.regenerator`) that
     * a shared helper would need its own branching, buying nothing over two
     * short, independently-readable methods.
     *
     * A declared name resolves to <manifests_dir>/regenerators/<name>.php,
     * which must define \Duo\Regenerators\<CamelCase(name)> with
     * regenerate(int $localId): void. Any exception it throws is the
     * caller's (Apply::regen_dependencies()) hard-failure signal — there is
     * no success/failure return-value protocol, matching interpreters' own
     * all-or-throw shape.
     *
     * Public, like interpreters() above, and for the same reason: the callers
     * are in other classes — Apply::regen_dependencies() drives it live, and
     * `duo manifest-validate` resolves it offline so a declared-but-missing
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
                if (!preg_match('/^[a-z0-9_-]+$/', $name)) {
                    throw new \RuntimeException(
                        "duo: manifest '{$m['name']}' post_types.$postType declares invalid regenerator name '$name'"
                    );
                }
                $file = self::manifests_dir() . '/regenerators/' . $name . '.php';
                if (!is_file($file)) {
                    throw new \RuntimeException(
                        "duo: manifest '{$m['name']}' post_types.$postType wants regenerator '$name' but $file is missing — "
                        . 'regenerator code ships with its manifest, not the engine'
                    );
                }
                require_once $file;
                $class = '\\Duo\\Regenerators\\' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
                if (!class_exists($class) || !method_exists($class, 'regenerate')) {
                    throw new \RuntimeException(
                        "duo: regenerator file $file must define $class with regenerate(int \$localId): void"
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
     * DUO-3263: a third optional interpreter hook, same contract shape as
     * term/user above, for options whose NAME a manifest's option_namespaces
     * claims but whose per-name classification can't be a static exact/
     * pattern rule (ACF options-page fields: arbitrary field names, ref kind
     * determined by a shadow-key-pointed schema, exactly like post/term meta
     * — see manifests/interpreters/acf.php's option_rule()). $allOptions is
     * the full option-name classification context (mirroring $allMeta's
     * "owning scope, shadow keys and all" shape) — options have no single
     * owning entity to scope the map to. Live capture passes raw wp_options
     * values; immutable-tree callers pass OptionState::classification_values(),
     * which adds only valid v2 witness context to ordinary present values.
     *
     * Routes through option_rule_details_for_option() rather than the plain
     * meta_rule_for_interpreter_hook() every other meta_rule_for_*() uses —
     * caught live (regress_acf_term_options_fields.sh's first run):
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
     * Interpreter-aware sibling of owned_option_rule() (DUO-3263): same
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
        if ($details['rule'] !== null && $details['source'] !== 'site.duo.json'
            && $details['source'] !== $owner['owner']
            && !str_starts_with((string) $details['source'], $owner['owner'] . ' (interpreter')) {
            throw new \RuntimeException(
                "duo: option '$name' namespace is owned by '{$owner['owner']}' but its classification comes from "
                . "'{$details['source']}' — cross-manifest ownership is ambiguous"
            );
        }
        return $details['rule'];
    }

    /**
     * Backward-compatible facade kept for callers introduced by DUO-3262.
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
        foreach ($this->interpreters() as $i) {
            if (!method_exists($i, $hook)) {
                continue;
            }
            $rule = $i->{$hook}($key, $allMeta);
            if ($rule !== null) {
                return $rule;
            }
        }
        return $this->rule($section, $key);
    }

    /** @return array{rule:?array, source:?string} */
    public function meta_rule_details_for_post(string $key, array $allMeta): array {
        return $this->rule_details_for_interpreter_hook(
            'post_meta_rule',
            $key,
            $allMeta,
            fn() => $this->post_meta_rule_details($key)
        );
    }

    /**
     * DUO-3263: option_rule() sibling of meta_rule_details_for_post() above,
     * for the same "which interpreter/manifest actually decided this"
     * provenance RepositoryAuthorization/RepositoryCompiler need (they
     * report a mismatched source, not just a classification).
     *
     * @return array{rule:?array, source:?string}
     */
    public function option_rule_details_for_option(string $name, array $allOptions): array {
        return $this->rule_details_for_interpreter_hook(
            'option_rule',
            $name,
            $allOptions,
            fn() => $this->option_rule_details($name)
        );
    }

    /**
     * Shared by meta_rule_details_for_post() (post_meta_rule is mandatory —
     * every interpreter already satisfies method_exists() by the load-time
     * check in interpreters(), so the guard below is a no-op there) and
     * option_rule_details_for_option() (option_rule is optional, so the
     * guard is load-bearing there, mirroring meta_rule_for_interpreter_hook()'s
     * own method_exists() gate).
     *
     * The $hook === 'option_rule' branch below is the one hook-specific
     * exception to this being a generic dispatcher: every STATIC options
     * rule already gets the owning manifest's own option_autoload default
     * injected (with_option_autoload(), called at every static rule()
     * options lookup) — OptionState::assert_rule_autoload() hard-requires
     * every options rule to declare 'autoload' (or 'preserve') before a row
     * can be captured. An interpreter-returned options rule needs the exact
     * same treatment or it can never pass that check (caught live:
     * regress_acf_term_options_fields.sh's first run failed capture outright
     * with "option '...' has autoload 'off' but policy declares NULL").
     * term_meta/user_meta rules have no such concept, so this is scoped to
     * the one hook name that does, not a general behavior change.
     *
     * @return array{rule:?array, source:?string}
     */
    private function rule_details_for_interpreter_hook(
        string $hook,
        string $key,
        array $allMeta,
        callable $staticDetails
    ): array {
        foreach ($this->interpreters() as $name => $i) {
            if (!method_exists($i, $hook)) {
                continue;
            }
            $rule = $i->{$hook}($key, $allMeta);
            if ($rule === null) {
                continue;
            }
            foreach ($this->manifests as $m) {
                if (($m['interpreter'] ?? null) === $name) {
                    if ($hook === 'option_rule') {
                        $rule = self::with_option_autoload($rule, $m);
                    }
                    return [
                        'rule' => $rule,
                        'source' => (string) ($m['name'] ?? '?') . " (interpreter $name)",
                    ];
                }
            }
            return ['rule' => $rule, 'source' => "interpreter $name"];
        }
        return $staticDetails();
    }

    /** @return array{rule:?array, source:?string} */
    public function meta_rule_details_for_term(string $key, array $allMeta): array {
        foreach ($this->interpreters() as $name => $i) {
            if (!method_exists($i, 'term_meta_rule')) {
                continue;
            }
            $rule = $i->term_meta_rule($key, $allMeta);
            if ($rule === null) {
                continue;
            }
            foreach ($this->manifests as $m) {
                if (($m['interpreter'] ?? null) === $name) {
                    return [
                        'rule' => $rule,
                        'source' => (string) ($m['name'] ?? '?') . " (interpreter $name)",
                    ];
                }
            }
            return ['rule' => $rule, 'source' => "interpreter $name"];
        }
        return $this->rule_details('term_meta', $key);
    }

    /** @return array{rule:?array, source:?string} */
    public function meta_rule_details_for_user(string $key, array $allMeta): array {
        foreach ($this->interpreters() as $name => $i) {
            if (!method_exists($i, 'user_meta_rule')) {
                continue;
            }
            $rule = $i->user_meta_rule($key, $allMeta);
            if ($rule === null) {
                continue;
            }
            UserMetaGrammar::validate_user_meta_rule($rule, "interpreter $name user_meta.$key", self::CLASSES, self::MISSING_USER_MODES);
            foreach ($this->manifests as $m) {
                if (($m['interpreter'] ?? null) === $name) {
                    return [
                        'rule' => $rule,
                        'source' => (string) ($m['name'] ?? '?') . " (interpreter $name)",
                    ];
                }
            }
            return ['rule' => $rule, 'source' => "interpreter $name"];
        }
        $details = $this->rule_details('user_meta', $key);
        if ($details['rule'] !== null) {
            UserMetaGrammar::validate_user_meta_rule($details['rule'], "user_meta.$key", self::CLASSES, self::MISSING_USER_MODES);
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
                    throw new \RuntimeException("duo: interpreter '$name' returned a non-array repository diagnostic");
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
            return ['rule' => $site, 'source' => 'site.duo.json'];
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
            return ['rule' => $site, 'source' => 'site.duo.json'];
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
            return ['authorized' => true, 'source' => 'site.duo.json'];
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
     * 'blocks' (default: block-parser rewriting + URL tokenization) or
     * 'verbatim' (byte-preserved — for post types whose content is serialized
     * data, e.g. acf-field, where URL substitution would corrupt lengths).
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
     * DUO-3234 — a post type's derived-table hard-dependency declaration, if
     * any: `{"regenerator": "<name>", "verify": {"table": "<t>", "column": "<c>"}}`.
     * v2 scope, stated loudly: post_types{}-keyed only (a per-post-type
     * property, matching the phase/fields precedents immediately above and
     * below — never a `tables{}` declaration, since the derived table itself
     * has no independent identity to declare; see agent/src/Snapshot.php's
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
     * A MAP, front-matter field => the wp_posts column it writes (DUO-3318).
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
     * avoid: site.duo.json's policy.post_types is already the flat SCOPE
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
     * v2-supported MENU FIELD classification surface (DUO-3272) — same
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
     * needs the full DUO-3249 core-yields-to-plugin precedence instead:
     * core.json declares 'locations' authored as its v0 baseline (every
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
     * Menu-FIELD classification (DUO-3272). Proven case: Polylang's own
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
     * the same location, entirely outside Duo's own capture/apply cycle —
     * DUO-3272's filed repro is 100% reproducible on demand: calling
     * update_default() with a different language flips which menu file's
     * `locations` holds a given slot, byte-for-byte matching the original
     * flake.
     *
     * Owner ruling (issue comment 8e0edde6): the raw slot is a PROJECTION
     * of state Duo already carries losslessly elsewhere — polylang.json's
     * own sub_keys mechanism (DUO-3233/task #121) already propagates both
     * `nav_menus` (which menu belongs at which location, PER LANGUAGE) and
     * `default_lang` inside the `polylang` option itself. So classifying
     * `locations` 'derived' under Polylang does not drop authored
     * information — it stops Duo from ALSO separately carrying a value
     * Polylang's own machinery treats as its mutable cache and rewrites at
     * will, which is exactly what made the flake possible. Default
     * 'authored' if nothing declares a rule at all (defensive fallback
     * only — core.json's own menu_fields.locations declaration means this
     * branch is not expected to be reached in practice).
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
     * (DUO-3318 review, S4).
     *
     * This is the only implementation of these rules: ManifestGrammar's
     * aggregate validator runs it for every declared type at load, and
     * SidebarState::assert_policy() runs it again immediately before its own
     * genuinely-live work.
     *
     * DUO-3348 first extraction slice: the implementation now lives in
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
     * DUO-3232: the enumeration half of "env-bound value provisioning" —
     * feeds Apply::build_plan()'s env_missing bucket (which entity of
     * this list actually looks unset on THIS environment) and, indirectly
     * via `wp duo env-set`'s own lookup, the write-time guard that refuses
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
        $out = [];
        foreach ($this->resolved_exact_options() as $name => $r) {
            if (($r['class'] ?? '') === 'env') {
                $out[$name] = $r;
            }
        }
        return $out;
    }

    /**
     * DUO-3249: every core-manifest OPTION name where a pinned, NON-core
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
     * today), and core-vs-PLUGIN-MANIFEST only — a site.duo.json override
     * of a core option is the operator's own explicit, already-visible
     * choice (it's sitting in a file they wrote), not a silent manifest-
     * pinning side effect, so it does not need this same loud treatment
     * and is excluded here on purpose, not by oversight. This says nothing
     * about, and does not resolve, the SEPARATE question of two non-core
     * manifests declaring the same option name (DUO-3255) — that
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
            if ($source === null || $source === 'core' || $source === 'site.duo.json') {
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
     * DUO-3272's own version of active_reclassifications() immediately
     * above — same purpose (the loud, plan-visible half of the DUO-3249
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
            if ($source === null || $source === 'core' || $source === 'site.duo.json') {
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
     * Manifest actions with an exact canonical surface intersection.
     *
     * A declaration without `triggers` is deliberately unscoped: it remains
     * required for every authored mutation, preserving the semantics the
     * retired `rebuilders` channel gave an un-triggered declaration. A
     * declaration with triggers is selected only when Apply has derived the
     * exact same canonical surface from this request. The empty surface set
     * is a no-op, so a read-only apply cannot fire an action.
     *
     * @param list<string> $surfaces
     * @return list<array<string,mixed>>
     */
    public function actions_for(array $surfaces): array {
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
        foreach ($this->actions() as $action) {
            if (!array_key_exists('triggers', $action)) {
                $out[] = $action;
                continue;
            }
            foreach ((array) $action['triggers'] as $trigger) {
                if (isset($wanted[$trigger])) {
                    $out[] = $action;
                    break;
                }
            }
        }
        return $out;
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
                    $out[] = ['manifest' => $name, 'phase' => 'rebuild', 'source' => $source, 'effect' => $effect];
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
        if (is_array($effects) && $effects !== []) {
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
     * docs/proposals/code-half.md §4.3's version_range mechanism: a manifest
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
     * Deploy::code_mismatch() / Apply::build_plan()'s code_mismatch bucket
     * and DUO-3338's provider negotiation (Providers::negotiate(), which
     * bounds a plugin-owned provider by the same declared range that bounds
     * its manifest's classification guarantees) are this accessor's readers.
     *
     * @return array<string, array{min:string, max:string, manifest:string}> keyed by plugin basename
     */
    public function version_ranges(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            $plugin = $m['plugin'] ?? null;
            $range = $m['version_range'] ?? null;
            if (!is_string($plugin) || $plugin === '' || !is_array($range) || isset($out[$plugin])) {
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
     * DUO-3222: theme twin of version_ranges() above — same {min,max} +
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
        $out = [];
        foreach ($this->manifests as $m) {
            $theme = $m['theme'] ?? null;
            $range = $m['theme_version_range'] ?? null;
            if (!is_string($theme) || $theme === '' || !is_array($range) || isset($out[$theme])) {
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
     * Write one classification rule into site.duo.json's policy overrides
     * (`wp duo classify`'s only write path — DESIGN.md 3.1.5: "accepted
     * decisions persist to policy.yml/json"). Validates shape, then loads +
     * rewrites the file via Canon::encode so formatting stays canonical.
     */
    public static function set_rule(string $repo, string $section, string $key, array $rule): void {
        if ($section === 'scope') {
            if (!preg_match('/^(post_type|taxonomy):(.+)$/', $key, $m)) {
                throw new \RuntimeException(
                    "duo: scope key '$key' must be post_type:<name> or taxonomy:<name>"
                );
            }
            $class = $rule['class'] ?? '';
            if (!in_array($class, ScopeGrammar::scopeClasses(), true)) {
                throw new \RuntimeException(
                    "duo: unknown scope class '$class' (expected " . implode('|', ScopeGrammar::scopeClasses()) . ')'
                );
            }
            if (array_diff_key($rule, ['class' => true])) {
                throw new \RuntimeException('duo: scope rules accept class only (no ref, cast, or secret override)');
            }
            $siteFile = rtrim($repo, '/') . '/site.duo.json';
            if (!is_file($siteFile)) {
                throw new \RuntimeException("duo: $siteFile not found (not a duo site repo?)");
            }
            $site = Canon::decode(Canon::read_file($siteFile));
            $site['policy']['scope'][$m[1]][$m[2]] = $rule;
            Canon::write_file($siteFile, Canon::encode($site));
            return;
        }
        if (!in_array($section, self::SECTIONS, true)) {
            throw new \RuntimeException(
                'duo: unknown policy section \'' . $section . '\' (expected '
                . implode('|', array_merge(self::SECTIONS, ['scope'])) . ')'
            );
        }
        if ($key === '') {
            throw new \RuntimeException('duo: policy key must not be empty');
        }
        $class = $rule['class'] ?? '';
        if (!in_array($class, self::CLASSES, true)) {
            throw new \RuntimeException(
                "duo: unknown class '$class' (expected " . implode('|', self::CLASSES) . ')'
            );
        }
        if (isset($rule['ref']) && !preg_match('/^(post|term|user)(\[\])?$/', (string) $rule['ref'])) {
            throw new \RuntimeException(
                "duo: invalid ref '{$rule['ref']}' (expected post|term|user, optionally suffixed with [])"
            );
        }
        if (isset($rule['cast']) && !in_array($rule['cast'], self::CASTS, true)) {
            throw new \RuntimeException("duo: invalid cast '{$rule['cast']}' (expected " . implode('|', self::CASTS) . ')');
        }
        if (isset($rule['allow_secret']) && !is_bool($rule['allow_secret'])) {
            throw new \RuntimeException('duo: allow_secret must be a boolean');
        }
        if ($section === 'user_meta') {
            UserMetaGrammar::validate_user_meta_rule($rule, "user_meta.$key", self::CLASSES, self::MISSING_USER_MODES);
        } elseif (isset($rule['allow_pii']) || isset($rule['missing_user'])) {
            throw new \RuntimeException('duo: allow_pii and missing_user are valid only for user_meta rules');
        }

        $siteFile = rtrim($repo, '/') . '/site.duo.json';
        if (!is_file($siteFile)) {
            throw new \RuntimeException("duo: $siteFile not found (not a duo site repo?)");
        }
        $site = Canon::decode(Canon::read_file($siteFile));
        $site['policy'][$section][$key] = $rule;
        Canon::write_file($siteFile, Canon::encode($site));
    }

    /**
     * Draft-manifest export (DESIGN.md 3.1.5: "accepted decisions ...
     * shareable upstream as draft manifests"): every rule in THIS site's own
     * policy overrides (not inherited manifest rules — the human is
     * promoting decisions they made) whose key matches $matchRegex, grouped
     * into a manifest-shaped {name, options, post_meta, term_meta, user_meta}
     * structure. Reads site.duo.json; never writes it — promotion is a
     * deliberate, separate human act (`wp duo policy-to-manifest` only
     * prints to stdout).
     */
    public static function export_manifest(string $repo, string $matchRegex, string $name): array {
        $policy = self::load($repo);
        $sitePolicy = $policy->site['policy'] ?? [];

        // DUO-3247 made spec_version mandatory at load() — sourced from the
        // canonical constant, never a literal, so this can never drift out
        // of sync with what load() actually requires the way it silently
        // did before (this export wrote no spec_version at all until
        // DUO-3284 caught it live: an exported manifest the engine's own
        // loader refused, found via a sandbox/tests/ run that finally
        // exercised the full export-then-reload path).
        $specVersion = defined('DUO_SPEC_VERSION') ? DUO_SPEC_VERSION : 0;
        return PolicyWriter::export_manifest($sitePolicy, $matchRegex, $name, $specVersion, self::SECTIONS);
    }

    /**
     * The closed VALUE vocabularies this class refuses against — the legal
     * values of a declared field — keyed by the grammar name an adapter author
     * sees (DUO-3327).
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
            'pattern_keys' => self::PATTERN_KEYS,
            'option_autoload_values' => OptionState::AUTOLOAD_VALUES,
            'option_autoload_sentinels' => OptionGrammar::optionAutoloadSentinels(),
            'dynamic_option_resolvers' => SubKeyGrammar::dynamic_option_resolvers(),
            'user_meta_missing_user_modes' => self::MISSING_USER_MODES,
            'post_derivable_fields' => self::DERIVABLE_FIELD_COLUMNS,
            'post_field_classes' => self::FIELD_CLASSES,
            'post_type_body_modes' => PostTypeGrammar::bodyModes(),
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
            'widget_setting_codecs' => ManifestGrammar::widgetSettingCodecs(),
            'widget_setting_refs' => ManifestGrammar::widgetSettingRefs(),
            'action_kinds' => ActionProviderGrammar::actionKinds(),
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
     * against, as the exact PCRE this class hands to preg_match() (DUO-3327).
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

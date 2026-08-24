<?php
namespace Duo;

/**
 * External ratification data for the shipped manifest library.
 *
 * A manifest cannot certify itself merely by existing beside the agent. The
 * separate dispositions document records the reviewed support boundary and
 * its evidence, while this loader makes omissions and malformed claims loud.
 * Custom/test manifest directories without a dispositions document keep their
 * historical policy behavior, but expose no reviewed capability claim.
 *
 * These reviewed bytes are the ONLY authored source of a product capability
 * claim: `claim_from_disposition()` below projects one, and AdapterRegistry
 * evaluates that projection against a live target. There is no second,
 * generated document to agree with.
 */
final class ManifestDispositions {
    public const FORMAT = 'duo-manifest-dispositions/v1';

    /**
     * The agent's own platform/runtime boundary, shipped beside the manifest
     * library at `capabilities/platform.json` rather than derived here: the
     * bytes of this object are what a signed site-adapter certificate binds as
     * `platform_sha256` (AdapterCertification::currentPlatform()), so it has
     * exactly one on-disk representation and both readers name this constant.
     */
    public const PLATFORM_FORMAT = 'duo-platform-boundary/v1';
    private const PLATFORM_RELATIVE = 'capabilities/platform.json';

    /**
     * The synthesized blocker status for a manifest with NO reviewed
     * disposition entry (DUO-3372). It is a RUNTIME status only — never a
     * value a disposition may DECLARE (validate_entry() refuses it) — emitted
     * by blockers()/report() so a direct caller cannot read an uncovered
     * manifest as ready.
     */
    public const STATUS_UNCOVERED = 'uncovered';
    public const UNCOVERED_REASON = 'no reviewed disposition entry — a manifest cannot certify itself merely by existing beside the agent';
    public const EVIDENCE_SCHEMA = 'duo-subject-certification-bundle/v1';

    /**
     * The v3 declaration channel an adapter narrows its environment claim
     * through (spec/repo-format.md § v3.5), and the four axes it may name.
     *
     * The axis list is not a subset of the boundary's five compatibility axes
     * by accident: it is exactly what claim_from_disposition() projects into
     * `environment_assumptions` below (`site_mode` plus the `php`, `database`
     * and `wordpress` compatibility axes). `filesystem` and `process` are
     * load-time gates that no claim states, so a declaration naming one would
     * narrow a sentence the claim never makes — refused by name rather than
     * accepted as a no-op, because an unrecognised declaration that means
     * nothing is indistinguishable from a deliberate one.
     */
    private const ENVIRONMENT_KEY = 'environment';
    private const ENVIRONMENT_AXES = ['database', 'php', 'site_mode', 'wordpress'];

    private array $data;

    private function __construct(array $data) {
        $this->data = $data;
    }

    /**
     * The reviewed registry DOCUMENT: its own root shape and its profiles,
     * both of which are self-contained (validate_profiles() resolves each
     * profile's `manifest` against the registry's own declared names, never
     * against the directory).
     *
     * It deliberately no longer globs and decodes every `*.json` beside it.
     * That whole-directory read cost one decode per SHIPPED manifest on every
     * `Policy::load()` — measured 18 decodes for a one-pin load of the shipped
     * 16-manifest library — to answer a question about manifests the caller
     * never pinned, and it made the bidirectional set comparison a RUNTIME
     * refusal: a single unreviewed file dropped into the library refused every
     * unrelated pin along with it. That is the same shape Policy.php:559-564
     * already names on the frozen path ("would demand an entry that cannot
     * exist, so one site-installed adapter would refuse every unrelated shipped
     * adapter along with itself").
     *
     * The rule did not relax, it split:
     *   - RUNTIME ("you may not USE an unreviewed adapter") is assert_covers()
     *     below, called by Policy::load() with the pinned shipped subset, and
     *     by blockers()/report() as the synthesized `uncovered` row. A manifest
     *     still cannot certify itself merely by existing beside the agent —
     *     the moment it is PINNED it refuses, with the same sentence.
     *   - AUTHORING ("the shipped library is exactly reviewed", both
     *     directions, including a reviewed entry that outlived its manifest)
     *     is `make release-gate`: tools/capability-doc.php's
     *     capdoc_cross_check() already computes the identical two-way
     *     comparison over manifests/ and exits 1 on any difference, and
     *     sandbox/tests/offline/policy/regress_manifest_dispositions.php runs
     *     the real loader over the whole shipped library in the merge gate.
     */
    public static function load(string $dir): ?self {
        $file = rtrim($dir, '/') . '/dispositions.json';
        if (!is_file($file)) {
            return null;
        }
        $data = Canon::decode(Canon::read_file($file));
        self::validate_root($data, "manifest disposition registry '$file'");
        self::validate_profiles($data['profiles'], array_keys($data['manifests']));
        return new self($data);
    }

    /**
     * Require a reviewed entry for exactly the manifests handed in — the
     * validate-what-you-were-given shape from_snapshot() has always had, on
     * the live path.
     *
     * Callers pass the SHIPPED subset only (AdapterSources::shipped_manifests(),
     * "Only the shipped subset participates in disposition/registry coverage"):
     * an out-of-tree adapter has no reviewed entry by construction and
     * demanding one is exactly the DUO-3314 failure.
     *
     * The refusal is byte-identical to the whole-directory check it replaces,
     * because it is the same refusal for the case an operator can actually
     * reach: pinning a manifest with no reviewed entry. `extra` can only ever
     * be empty here — nothing this call was given is unaccounted for, and the
     * reviewed-entry-with-no-manifest direction is a property of the DIRECTORY,
     * which `make release-gate` owns. The bytes stay put anyway (AGENTS.md rule
     * 8; docs/guides/adapter-authoring.md quotes the sentence verbatim).
     *
     * Missing entries are collected and refused before the first validate_entry()
     * so the precedence the whole-directory check had is preserved: a pin with
     * no entry at all reports coverage, never a malformed-field message about
     * some other pinned adapter.
     *
     * @param list<array<string,mixed>> $manifests
     */
    public function assert_covers(array $manifests): void {
        $missing = [];
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '');
            // array_key_exists, not isset: a reviewed entry authored as JSON
            // `null` is PRESENT and malformed, not absent. isset() folded it
            // into the coverage list, so `"core": null` answered "manifest
            // disposition coverage mismatch; missing=[core]" — an operator
            // sent to add an entry that is already there — where the
            // per-entry validator says exactly what is wrong with it:
            // "manifest disposition 'core' must be an object".
            if (!array_key_exists($name, $this->data['manifests'])) {
                $missing[] = $name;
            }
        }
        if ($missing !== []) {
            sort($missing, SORT_STRING);
            throw new \RuntimeException(
                'duo: manifest disposition coverage mismatch; missing=[' . implode(',', $missing) . '], extra=[]'
            );
        }
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '');
            self::validate_entry($name, $this->data['manifests'][$name], $manifest);
        }
    }

    /**
     * The same per-entry rules, at PROJECTION time, for a reader that got its
     * entry from entry() instead of from assert_covers().
     *
     * assert_covers() runs over the PINNED shipped subset, which is the right
     * scope for "may this site USE this adapter" and the wrong scope for every
     * caller that projects a claim from an entry it looked up by name:
     * AdapterRegistry::shipped_claim() (the funnel under capability_claim() and
     * report(), so `wp duo capabilities --all` and the adapter catalog), and
     * blockers()/report() below. Until this call existed those readers
     * projected UNVALIDATED reviewed bytes. Measured against a woocommerce
     * entry tampered four ways and read back through `wp duo capabilities
     * --all`: evidence deleted printed `certified`/`verified` with
     * `evidence: []`; an invented `entity_section` printed `certified` and
     * listed the invention among its surfaces; a fabricated version range
     * printed `certified` over a range the manifest does not declare; and with
     * `capabilities` deleted, report() below reached `$entry['capabilities']
     * ['entity_sections']` and produced two PHP warnings and
     * "resolve_sections(): Argument #2 ($sections) must be of type array, null
     * given" — a TypeError where a refusal belongs.
     *
     * It delegates rather than re-checks: one validator means the wording an
     * operator meets at projection is the wording load time used, and a tenth
     * rule added to validate_entry() is enforced on both paths by construction.
     *
     * Static because its callers hold arrays and not this object —
     * AdapterRegistry::shipped_claim() is a static projection over
     * (manifest, disposition, platform) with no registry instance in scope.
     */
    public static function assert_entry(string $name, array $entry, array $manifest): void {
        self::validate_entry($name, $entry, $manifest);
    }

    /** Revalidate frozen bytes without reopening the mutable manifest dir. */
    public static function from_snapshot(array $data, array $manifests): self {
        self::validate_root($data, 'frozen manifest disposition registry');
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '');
            if ($name === '' || !isset($data['manifests'][$name])) {
                throw new \RuntimeException("duo: frozen disposition registry has no entry for manifest '$name'");
            }
            self::validate_entry($name, $data['manifests'][$name], $manifest);
        }
        self::validate_profiles($data['profiles'], array_keys($data['manifests']));
        return new self($data);
    }

    public function data(): array {
        return $this->data;
    }

    public function entry(string $name): ?array {
        $entry = $this->data['manifests'][$name] ?? null;
        return is_array($entry) ? $entry : null;
    }

    public function profiles(): array {
        return $this->data['profiles'];
    }

    /**
     * The content address of these exact reviewed bytes. It is the number a
     * host contract pins as `registry_sha256` and re-observes later, so it has
     * one definition rather than one per report producer.
     */
    public function sha256(): string {
        return hash('sha256', Canon::encode($this->data));
    }

    /**
     * The agent platform boundary this library ships, validated against the
     * loaded agent.
     *
     * The version agreement is not decoration: a platform object naming a
     * different agent/spec version than the code reading it would let a claim
     * describe a runtime nobody is running. Loud, and before any claim is
     * projected from it.
     *
     * @return array<string,mixed>
     */
    public static function platform_boundary(?string $dir = null): array {
        $file = rtrim($dir ?? self::manifests_dir(), '/') . '/' . self::PLATFORM_RELATIVE;
        $label = "agent platform boundary '$file'";
        if (!is_file($file)) {
            throw new \RuntimeException("duo: $label is absent; this manifest library declares no platform boundary");
        }
        $data = Canon::decode(Canon::read_file($file));
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        if ($keys !== ['format', 'platform']
            || ($data['format'] ?? null) !== self::PLATFORM_FORMAT
            || !is_array($data['platform'] ?? null) || array_is_list($data['platform'])
            || !is_array($data['platform']['compatibility'] ?? null)) {
            throw new \RuntimeException("duo: $label has an unsupported or malformed root");
        }
        $platform = $data['platform'];
        if (($platform['agent_version'] ?? null) !== (defined('DUO_AGENT_VERSION') ? DUO_AGENT_VERSION : '0.5.0')
            || ($platform['spec_version'] ?? null) !== (defined('DUO_SPEC_VERSION') ? DUO_SPEC_VERSION : 2)) {
            throw new \RuntimeException("duo: $label platform version disagrees with the loaded agent");
        }
        return $platform;
    }

    /**
     * Resolve the manifest library without requiring Policy to be loaded: this
     * file is reachable from partially-loaded offline contexts that never
     * include Policy.php, and a hard `Policy::` call there would fatal.
     */
    private static function manifests_dir(): string {
        if (class_exists(Policy::class)) {
            return Policy::manifests_dir();
        }
        $env = getenv('DUO_MANIFESTS_DIR');
        if ($env && is_dir($env)) {
            return $env;
        }
        return dirname(__DIR__, 3) . '/manifests';
    }

    /**
     * Project one reviewed disposition into the generic capability-row shape
     * without assigning an adapter digest or consulting another subject's
     * evidence.  Shipped capability reporting and separately authenticated
     * site adapters can therefore share operations/surface/environment
     * semantics; callers bind their own source-specific evidence and may add
     * the final digest only after the disposition source is settled.
     *
     * @return array<string,mixed>
     */
    public static function claim_from_disposition(
        array $manifest,
        array $disposition,
        array $evidence,
        array $platform,
        ?array $pluginExecution = null
    ): array {
        $name = (string) ($manifest['name'] ?? '');
        if ($name === '' || !is_array($disposition['capabilities'] ?? null)
            || !is_array($platform['compatibility'] ?? null)) {
            throw new \RuntimeException('duo: cannot project a malformed manifest disposition capability claim');
        }
        $status = (string) ($disposition['status'] ?? 'unsupported');
        $execution = $pluginExecution ?? [
            'mode' => 'unmodified',
            'status' => $status === 'certified'
                ? 'verified'
                : ($status === 'excluded' ? 'not-a-product-claim' : 'unverified'),
        ];
        $capabilities = $disposition['capabilities'];
        $surfaces = [];
        foreach (array_merge(
            (array) ($capabilities['entity_sections'] ?? []),
            (array) ($capabilities['field_sections'] ?? [])
        ) as $section) {
            if (!is_string($section) || $section === '') {
                throw new \RuntimeException("duo: manifest disposition '$name' has a malformed capability section");
            }
            $surfaces[] = $section;
            $value = $manifest[$section] ?? null;
            if (is_array($value) && !array_is_list($value)) {
                foreach (array_keys($value) as $key) {
                    $surfaces[] = $section . '.' . $key;
                }
            }
        }
        foreach ((array) (($capabilities['deletion_semantics']['supported'] ?? [])) as $selector) {
            if (!is_string($selector) || $selector === '') {
                throw new \RuntimeException("duo: manifest disposition '$name' has a malformed deletion selector");
            }
            $surfaces[] = 'deletions.' . $selector;
        }
        $surfaces = array_values(array_unique($surfaces, SORT_STRING));
        sort($surfaces, SORT_STRING);

        $operations = array_values((array) ($capabilities['operations'] ?? []));
        if (in_array('deploy', $operations, true) && in_array('apply', $operations, true)) {
            $operations[] = 'promote';
        }
        foreach ($operations as $operation) {
            if (!is_string($operation) || $operation === '') {
                throw new \RuntimeException("duo: manifest disposition '$name' has a malformed operation");
            }
        }
        $operations = array_values(array_unique($operations, SORT_STRING));
        sort($operations, SORT_STRING);

        return [
            'name' => $name,
            'status' => $status,
            'reason' => (string) ($disposition['reason'] ?? ''),
            'plugin_execution' => $execution,
            'authored_state' => [
                'status' => $status === 'excluded' ? 'unsupported' : $status,
                'scope' => 'only the exact registered surfaces and operations below',
            ],
            'supported_versions' => $disposition['supported_versions'] ?? new \stdClass(),
            'environment_assumptions' => self::narrowed_environment($manifest, $platform),
            'operations' => $operations,
            'surfaces' => $surfaces,
            'lifecycle_phases' => $capabilities['lifecycle_phases'] ?? [],
            'deletion_semantics' => $capabilities['deletion_semantics'] ?? new \stdClass(),
            'unsupported' => $disposition['unsupported'] ?? [],
            'evidence' => $evidence,
            'platform' => $platform,
        ];
    }

    /**
     * `environment_assumptions`: the reviewed boundary's own cells, narrowed by
     * the adapter's own declaration and never widened by it (spec/repo-format.md
     * § v3.5).
     *
     * Until this function existed the projection was the whole boundary copied
     * verbatim into every claim, which is why all 16 shipped claims are
     * byte-identical (measured in regress_spec_v3_dry_run.php under rule
     * V3-AXIS) and why no adapter could say which cells it actually ran on. A
     * v3 manifest may declare `"environment": {"php": ["8.3"], …}` — a list of
     * cell names per axis, the same subset shape `unsupported[]` already uses
     * for surfaces — and the claim then states that subset instead of the whole
     * matrix.
     *
     * Three properties, in the order they are enforced below:
     *
     *   1. an adapter declaring nothing binds the whole current boundary, byte
     *      for byte — and so does a `spec_version: 2` manifest that declares the
     *      key anyway. The channel is INERT under this engine exactly as § v3.2's
     *      feature-declaration channel is; turning that silence into a named
     *      per-section refusal is WP-4.2's acceptance window, which is the only
     *      mechanism that can refuse a v3-only section inside a v2 manifest
     *      without refusing the manifest wholesale;
     *   2. every declared cell must be one the reviewed boundary carries. A cell
     *      it does not carry is a WIDER claim and refuses naming BOTH the axis
     *      and the cell — "environment declaration invalid" would send an author
     *      to re-read four axes to find one transposed digit;
     *   3. narrowing scopes the CLAIM and nothing else. This function is a
     *      projection: it hands PlatformCompatibility nothing and reads nothing
     *      PlatformCompatibility wrote, so `assert_supported()` still gates
     *      site_mode, PHP, the database engine and version, the WordPress core,
     *      the filesystem profile and the process profile against the same
     *      boundary object, on all five axes, exactly as it does today. A claim
     *      that says which cells were exercised is strictly more information
     *      than one that inherits the whole boundary; it is not permission to
     *      run outside it.
     *
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $platformBoundary
     * @return array<string,mixed>
     */
    private static function narrowed_environment(array $manifest, array $platformBoundary): array {
        // The historical four members, in their historical order: the claim
        // states `site_mode` plus three of the boundary's five compatibility
        // axes, and an adapter may narrow exactly what the claim states.
        $assumptions = [
            'site_mode' => $platformBoundary['site_mode'] ?? null,
            'php' => $platformBoundary['compatibility']['php'] ?? new \stdClass(),
            'database' => $platformBoundary['compatibility']['database'] ?? new \stdClass(),
            'wordpress' => $platformBoundary['compatibility']['wordpress'] ?? new \stdClass(),
        ];
        $declared = $manifest[self::ENVIRONMENT_KEY] ?? null;
        $spec = $manifest['spec_version'] ?? null;
        if ($declared === null || !is_int($spec) || $spec < 3) {
            return $assumptions;
        }

        $name = (string) ($manifest['name'] ?? '');
        if (!is_array($declared) || array_is_list($declared) || $declared === []) {
            throw new \RuntimeException(
                "duo: manifest '$name' environment declaration must be a non-empty object of axis => exercised cells"
            );
        }
        // Sorted, so the refusal an author meets names the same axis whatever
        // order the declaration happens to be authored in.
        ksort($declared, SORT_STRING);
        foreach ($declared as $axis => $cells) {
            $axis = (string) $axis;
            if (!in_array($axis, self::ENVIRONMENT_AXES, true)) {
                throw new \RuntimeException(
                    "duo: manifest '$name' declares environment axis '$axis', which a capability claim does not "
                    . 'state; narrowable axes are ' . implode(', ', self::ENVIRONMENT_AXES)
                );
            }
            if (!self::string_list($cells, false)) {
                throw new \RuntimeException(
                    "duo: manifest '$name' environment axis '$axis' must be a non-empty list of distinct cell names"
                );
            }
            // The cell vocabulary is the boundary's OWN, per axis: the exercised
            // series map for the two ranged axes (a bare range claims minor
            // lines nobody ran, which is why PlatformCompatibility accepts on
            // range AND series), the engines map for the database axis whose
            // range is a function of its engine, and the single reviewed value
            // for site_mode. A one-value axis can only ever be restated, which
            // is what makes `"site_mode": ["multisite"]` the clearest possible
            // demonstration that this channel cannot widen anything.
            $axisBoundary = is_array($assumptions[$axis] ?? null) ? $assumptions[$axis] : [];
            $carried = match ($axis) {
                'site_mode' => is_string($assumptions['site_mode']) ? [$assumptions['site_mode']] : [],
                'database' => array_map('strval', array_keys(
                    is_array($axisBoundary['engines'] ?? null) ? $axisBoundary['engines'] : []
                )),
                default => array_map('strval', array_keys(
                    is_array($axisBoundary['verified'] ?? null) ? $axisBoundary['verified'] : []
                )),
            };
            $wider = array_values(array_diff($cells, $carried));
            if ($wider !== []) {
                sort($wider, SORT_STRING);
                throw new \RuntimeException(
                    "duo: manifest '$name' environment axis '$axis' declares [" . implode(', ', $wider)
                    . '] which the reviewed platform boundary does not carry ['
                    . implode(', ', $carried) . '] — an adapter may narrow the reviewed environment, never widen it'
                );
            }

            if ($axis === 'site_mode') {
                continue;
            }
            $subset = array_fill_keys($cells, true);
            if ($axis === 'database') {
                $axisBoundary['engines'] = array_intersect_key($axisBoundary['engines'], $subset);
                $assumptions[$axis] = $axisBoundary;
                continue;
            }
            $axisBoundary['verified'] = array_intersect_key($axisBoundary['verified'], $subset);
            if (array_key_exists('last_verified', $axisBoundary)) {
                // `last_verified` is the GREATEST exercised runtime on its axis
                // and must be a member of `verified`
                // (PlatformCompatibility::valid_wordpress_axis()). Carrying the
                // boundary's scalar behind a narrowed map would publish a claim
                // whose newest exercised core is not in its own exercised set —
                // the one internal contradiction this narrowing could introduce.
                $newest = '';
                foreach ($axisBoundary['verified'] as $patch) {
                    if ($newest === '' || version_compare((string) $patch, $newest, '>')) {
                        $newest = (string) $patch;
                    }
                }
                $axisBoundary['last_verified'] = $newest;
            }
            $assumptions[$axis] = $axisBoundary;
        }

        return $assumptions;
    }

    /**
     * Reuse the shipped disposition semantics for a separately authenticated
     * site-adapter entry.  The caller owns source/signature/path checks; this
     * narrow helper keeps section, version, capability, and evidence grammar
     * identical instead of growing a second long-lived validator beside it.
     */
    public static function validate_external_entry(
        string $name,
        array $entry,
        array $manifest,
        string $evidenceSchema,
        bool $requireExerciseTests = true
    ): void {
        if ($name === '' || ($entry['status'] ?? null) !== 'certified') {
            throw new \RuntimeException(
                "duo: external manifest disposition '$name' must be a certified entry"
            );
        }
        self::validate_entry($name, $entry, $manifest, $evidenceSchema, $requireExerciseTests);
    }

    /** @return list<array{name:string,status:string,reason:string}> */
    public function blockers(array $manifests): array {
        $out = [];
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '?');
            $entry = $this->entry($name);
            if ($entry === null) {
                // DUO-3372: an uncovered manifest is a BLOCKER, not a skip.
                // Silently dropping it would let a caller read a manifest with
                // no reviewed disposition as ready, which is exactly the "a
                // manifest cannot certify itself merely by existing" doctrine
                // this file states. `uncovered` is a synthesized runtime status
                // only; validate_entry() still refuses it as a DECLARED status.
                //
                // WP-1.2 made this row REACHABLE where it used to be a
                // fail-safe: load() no longer refuses a whole library over a
                // file nobody pinned, so a report over the LIBRARY (`wp duo
                // capabilities --all`, the adapter catalog) now meets an
                // unreviewed manifest and answers with this row and a
                // non-`ready` verdict instead of refusing the command. That is
                // the narrower loud refusal the runtime coverage check traded
                // for scope, not a relaxation — a pinned uncovered manifest
                // still refuses at load (assert_covers()).
                $out[] = [
                    'name' => $name,
                    'status' => self::STATUS_UNCOVERED,
                    'reason' => self::UNCOVERED_REASON,
                ];
                continue;
            }
            // The entry EXISTS; whether it is well-formed is a separate
            // question, and this method answers "is anything blocking" from
            // its `status`/`reason` alone. A malformed entry that reached here
            // would be read for a status it has no right to declare, so it
            // refuses in the validator's own words instead.
            self::assert_entry($name, $entry, $manifest);
            if (($entry['status'] ?? null) === 'certified') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'status' => (string) $entry['status'],
                'reason' => (string) $entry['reason'],
            ];
        }
        return $out;
    }

    /** Machine-readable CLI view, resolved from these exact registry bytes. */
    public function report(array $manifests): array {
        $rows = [];
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '?');
            $entry = $this->entry($name);
            if ($entry === null) {
                // DUO-3372: surface the uncovered manifest as an explicit row
                // rather than dropping it from the report — the top-level
                // `blockers`/`ready` already reflect it (blockers() above), and
                // a per-manifest report that silently omitted it would disagree
                // with its own blocker list. Minimal shape: there is no entry to
                // resolve entity/field sections from.
                $rows[] = [
                    'name' => $name,
                    'status' => self::STATUS_UNCOVERED,
                    'reason' => self::UNCOVERED_REASON,
                ];
                continue;
            }
            // Ahead of the two capabilities reads below, which are where an
            // entry with `capabilities` deleted produced two PHP warnings and
            // a TypeError out of resolve_sections() instead of a refusal.
            self::assert_entry($name, $entry, $manifest);
            $resolved = $entry;
            $resolved['name'] = $name;
            $resolved['entities'] = self::resolve_sections(
                $manifest,
                $entry['capabilities']['entity_sections']
            );
            $resolved['fields'] = self::resolve_sections(
                $manifest,
                $entry['capabilities']['field_sections']
            );
            unset($resolved['capabilities']['entity_sections'], $resolved['capabilities']['field_sections']);
            $rows[] = $resolved;
        }
        // One call, read twice. `ready` and `blockers` are two projections of
        // the same list by definition, and since blockers() now revalidates
        // every entry it walks (assert_entry above), computing it twice would
        // pay the per-entry rules a third time over one report for no answer
        // that is not already in hand.
        $blockers = $this->blockers($manifests);
        return [
            'schema_version' => self::FORMAT,
            'registry_sha256' => $this->sha256(),
            'ready' => $blockers === [],
            'blockers' => $blockers,
            'manifests' => $rows,
            'profiles' => $this->profiles(),
        ];
    }

    private static function resolve_sections(array $manifest, array $sections): array {
        $out = [];
        foreach ($sections as $section) {
            $value = $manifest[$section] ?? null;
            if (is_array($value) && !array_is_list($value)) {
                $keys = array_keys($value);
                sort($keys, SORT_STRING);
                $out[$section] = $keys;
            } else {
                $out[$section] = $value;
            }
        }
        return $out;
    }

    private static function validate_root(array $data, string $label): void {
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        if ($keys !== ['format', 'manifests', 'profiles']
            || ($data['format'] ?? null) !== self::FORMAT
            || !is_array($data['manifests'] ?? null) || array_is_list($data['manifests'])
            || !is_array($data['profiles'] ?? null)
            || (array_is_list($data['profiles']) && $data['profiles'] !== [])) {
            throw new \RuntimeException(
                "duo: $label must contain exactly format, manifests, and profiles for " . self::FORMAT
            );
        }
    }

    private static function validate_profiles(array $profiles, array $manifestNames): void {
        foreach ($profiles as $name => $profile) {
            if (!is_array($profile) || array_is_list($profile)
                || !in_array($profile['status'] ?? null, ['certified', 'experimental'], true)
                || !is_string($profile['manifest'] ?? null) || $profile['manifest'] === ''
                || !in_array($profile['manifest'], $manifestNames, true)
                || !is_string($profile['reason'] ?? null) || trim($profile['reason']) === ''
                || !is_array($profile['supported_versions'] ?? null)
                || array_is_list($profile['supported_versions'])
                || $profile['supported_versions'] === []
                || !is_array($profile['scope'] ?? null) || array_is_list($profile['scope'])
                || !is_array($profile['evidence'] ?? null)
                || ($profile['evidence']['bundle_schema'] ?? null) !== self::EVIDENCE_SCHEMA
                || !self::string_list($profile['evidence']['tests'] ?? null, false, true)) {
                throw new \RuntimeException("duo: manifest disposition profile '$name' is malformed");
            }
        }
    }

    /**
     * $requireExerciseTests is false ONLY for an external site-adapter entry
     * whose bundle declares `evidence.exercised: false` (round-3 T6). The
     * caller has already proved that shape is admissible — a site trust root,
     * an empty bundle test set, no artifacts — so requiring a citation here
     * would force the certificate to name a test that provably does not exist.
     * Every SHIPPED registry row keeps the default: a reviewed certified claim
     * with no named evidence is the thing this validator exists to refuse.
     */
    private static function validate_entry(
        string $name,
        $entry,
        array $manifest,
        string $evidenceSchema = self::EVIDENCE_SCHEMA,
        bool $requireExerciseTests = true
    ): void {
        if (!is_array($entry) || array_is_list($entry)) {
            throw new \RuntimeException("duo: manifest disposition '$name' must be an object");
        }
        $status = $entry['status'] ?? null;
        if (!in_array($status, ['certified', 'experimental', 'excluded'], true)
            || !is_string($entry['reason'] ?? null) || trim($entry['reason']) === ''
            || !is_array($entry['supported_versions'] ?? null) || array_is_list($entry['supported_versions'])
            || $entry['supported_versions'] === []
            || !is_array($entry['capabilities'] ?? null) || array_is_list($entry['capabilities'])
            || !is_array($entry['unsupported'] ?? null) || !array_is_list($entry['unsupported'])
            || $entry['unsupported'] === []
            || !is_array($entry['default_authored_keyspaces'] ?? null)
            || !array_is_list($entry['default_authored_keyspaces'])) {
            throw new \RuntimeException("duo: manifest disposition '$name' has a malformed required field");
        }
        $cap = $entry['capabilities'];
        $capKeys = array_keys($cap);
        sort($capKeys, SORT_STRING);
        if ($capKeys !== ['deletion_semantics', 'entity_sections', 'field_sections', 'lifecycle_phases', 'operations']
            || !self::string_list($cap['entity_sections'] ?? null)
            || !self::string_list($cap['field_sections'] ?? null)
            || !self::string_list($cap['operations'] ?? null, false)
            || !self::string_list($cap['lifecycle_phases'] ?? null)
            || !is_array($cap['deletion_semantics'] ?? null)
            || array_is_list($cap['deletion_semantics'])) {
            throw new \RuntimeException("duo: manifest disposition '$name' capabilities are malformed");
        }
        $deletionKeys = array_keys($cap['deletion_semantics']);
        sort($deletionKeys, SORT_STRING);
        if ($deletionKeys !== ['supported', 'unsupported']
            || !self::string_list($cap['deletion_semantics']['supported'] ?? null)
            || !self::string_list($cap['deletion_semantics']['unsupported'] ?? null, false)) {
            throw new \RuntimeException("duo: manifest disposition '$name' deletion semantics are malformed");
        }
        foreach (array_merge($cap['entity_sections'], $cap['field_sections']) as $section) {
            if (!array_key_exists($section, $manifest)) {
                throw new \RuntimeException(
                    "duo: manifest disposition '$name' names absent manifest section '$section'"
                );
            }
        }
        foreach ($entry['unsupported'] as $i => $unsupported) {
            if (!is_array($unsupported) || array_is_list($unsupported)
                || !is_string($unsupported['surface'] ?? null) || $unsupported['surface'] === ''
                || !is_string($unsupported['operation'] ?? null) || $unsupported['operation'] === ''
                || !is_string($unsupported['reason'] ?? null) || $unsupported['reason'] === '') {
                throw new \RuntimeException("duo: manifest disposition '$name' unsupported[$i] is malformed");
            }
        }
        $unsupportedSurfaces = array_fill_keys(array_column($entry['unsupported'], 'surface'), true);
        foreach (($manifest['tables'] ?? []) as $table => $rule) {
            if (($rule['class'] ?? null) === 'authored_typed_snapshot_post_v1'
                && !isset($unsupportedSurfaces["tables.$table"])) {
                throw new \RuntimeException(
                    "duo: manifest disposition '$name' must mark intent-only table '$table' unsupported"
                );
            }
        }

        $defaultRows = [];
        foreach ($entry['default_authored_keyspaces'] as $row) {
            if (!is_array($row) || array_is_list($row)
                || !is_string($row['table'] ?? null) || $row['table'] === ''
                || !in_array($row['status'] ?? null, ['justified', 'unsupported'], true)
                || !is_string($row['reason'] ?? null) || $row['reason'] === '') {
                throw new \RuntimeException("duo: manifest disposition '$name' has malformed default authored keyspace evidence");
            }
            $defaultRows[$row['table']] = true;
            if (!isset($manifest['tables'][$row['table']])
                || ($manifest['tables'][$row['table']]['default_class'] ?? null) !== 'authored') {
                throw new \RuntimeException(
                    "duo: manifest disposition '$name' names non-default-authored keyspace '{$row['table']}'"
                );
            }
        }
        foreach (($manifest['tables'] ?? []) as $table => $rule) {
            if (($rule['default_class'] ?? null) === 'authored' && !isset($defaultRows[$table])) {
                throw new \RuntimeException(
                    "duo: manifest disposition '$name' omits default authored keyspace '$table'"
                );
            }
        }

        $evidence = $entry['evidence'] ?? null;
        if ($status === 'certified') {
            if (!is_array($evidence) || array_is_list($evidence)
                || ($evidence['bundle_schema'] ?? null) !== $evidenceSchema
                || !self::string_list($evidence['tests'] ?? null, !$requireExerciseTests, true)) {
                throw new \RuntimeException("duo: certified manifest disposition '$name' lacks current bundle evidence");
            }
            $plugin = $manifest['plugin'] ?? null;
            if (is_string($plugin) && $plugin !== '') {
                if (($entry['supported_versions']['plugin'] ?? null) !== $plugin
                    || Canon::encode($entry['supported_versions']['range'] ?? null)
                        !== Canon::encode($manifest['version_range'] ?? null)) {
                    throw new \RuntimeException(
                        "duo: certified manifest disposition '$name' versions disagree with its manifest contract"
                    );
                }
            }
        }
    }

    private static function string_list($value, bool $allowEmpty = true, bool $canonicalSlugs = false): bool {
        if (!is_array($value) || !array_is_list($value) || (!$allowEmpty && $value === [])) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_string($item) || $item === ''
                || ($canonicalSlugs && preg_match('/^[a-z][a-z0-9-]*$/D', $item) !== 1)) {
                return false;
            }
        }
        return count(array_unique($value, SORT_STRING)) === count($value);
    }
}

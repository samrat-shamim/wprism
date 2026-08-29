<?php
namespace WPrism;

require_once __DIR__ . '/AdapterLibrary.php';

/**
 * External ratification data for the resolved adapter library.
 *
 * A manifest cannot certify itself merely by existing beside the agent. Each
 * authoring capsule records its reviewed boundary in
 * `adapter-packages/<name>/package/disposition.json`; core is platform-owned,
 * and installation projects those inputs into `agent/adapter-library/`.
 * Production reads those exact paths through AdapterLibrary, while the explicit
 * legacy flat-layout reader below preserves historical custom/test behavior
 * without granting an unreviewed capability claim.
 *
 * These reviewed bytes are the ONLY authored source of a product capability
 * claim: `claim_from_disposition()` below projects one, and AdapterRegistry
 * evaluates that projection against a live target. There is no second,
 * generated document to agree with.
 */
final class ManifestDispositions {
    public const FORMAT = 'wprism-manifest-dispositions/v1';

    /**
     * Historically, WP-4.4 changed the reviewed claim source to a DIRECTORY of
     * one document per subject (spec/repo-format.md § v3.4), replacing the
     * single `dispositions.json` this class had read:
     *
     *   manifests/dispositions/<name>.json    one adapter's entry, verbatim
     *   manifests/dispositions/profiles.json  the profiles map
     *
     * Each document holds the entry's DECODED array unchanged, which is the
     * whole reason the relocation is admissible on a flag day at all:
     * ArtifactPolicyIdentity::manifest_rows() folds each manifest's own
     * disposition into that adapter's row (`:82`) and the row hashed IS its
     * `digest` (`:162`), so a one-byte canonical difference in one document
     * would move that adapter's digest and every `site.wprism.json` content pin
     * naming it. Canon::encode() sorts keys at every level (Canon.php:44,58),
     * so `format` + the reassembled `manifests`/`profiles` maps re-encode to
     * the monolith's exact bytes — measured over all 16 shipped entries in
     * sandbox/tests/offline/policy/regress_disposition_split.php, which pins
     * the 16 digests as literals captured BEFORE the move rather than
     * recomputing both sides of an equality that would hold vacuously.
     *
     * Reads are per subject, which is what keeps the split from re-imposing
     * the whole-directory cost WP-1.2 removed one layer up (see load()): a
     * one-pin load decodes one entry document, not sixteen.
     */
    public const DIRECTORY = 'dispositions';

    /**
     * In the explicit legacy split directory, `profiles` is RESERVED: it names
     * the profiles map,
     * so no adapter can own it. AdapterSources::assert_name() admits it as a
     * slug, and the monolith could carry a `profiles` key under `manifests`
     * beside the sibling `profiles` map without ambiguity — the split cannot,
     * because both would be `dispositions/profiles.json`. Refused by name in
     * document() below rather than resolved by precedence.
     */
    private const PROFILES_DOCUMENT = 'profiles';

    /**
     * The monolith's own name, kept only to REFUSE it.
     *
     * A `dispositions.json` left beside the directory is ratification data the
     * engine never reads. That is precisely the failure mode AdapterSources
     * refuses everywhere else — "inert is exactly the failure mode to refuse:
     * an operator who wrote them believes their adapter is certified"
     * (AdapterSources.php:868-873) — so a stale monolith is named at load()
     * instead of being silently ignored.
     */
    private const MONOLITH = 'dispositions.json';

    /**
     * The document-name grammar, which is AdapterSources::assert_name()'s
     * (`:3156-3157`) restated rather than called: this file is reachable from
     * partially-loaded offline contexts that never include AdapterSources, and a
     * `require` here would grow the load graph of every context that only
     * wanted an entry.
     *
     * It is also the path guard. A subject name reaches document() straight
     * from a manifest's declared `name`, and this pattern admits no `/`, no
     * backslash and neither `.` nor `..` (both fail the mandatory-lowercase
     * check), so no name can address a file outside the directory.
     */
    private const NAME_PATTERN = '/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/D';

    /**
     * The agent's own platform/runtime boundary is authored at
     * `platform/adapter-library/capabilities/platform.json` and installed at
     * `agent/adapter-library/platform/capabilities/platform.json`, never
     * derived here. The bytes of this object are what a signed site-adapter
     * certificate binds as `platform_sha256`
     * (AdapterCertification::currentPlatform()); AdapterLibrary supplies the
     * current physical path, while this relative name serves only the explicit
     * legacy flat-layout reader.
     */
    public const PLATFORM_FORMAT = 'wprism-platform-boundary/v1';
    private const PLATFORM_RELATIVE = 'capabilities/platform.json';

    /**
     * The synthesized blocker status for a manifest with NO reviewed
     * disposition entry (issue #3372). It is a RUNTIME status only — never a
     * value a disposition may DECLARE (validate_entry() refuses it) — emitted
     * by blockers()/report() so a direct caller cannot read an uncovered
     * manifest as ready.
     */
    public const STATUS_UNCOVERED = 'uncovered';
    public const UNCOVERED_REASON = 'no reviewed disposition entry — a manifest cannot certify itself merely by existing beside the agent';
    public const EVIDENCE_SCHEMA = 'wprism-subject-certification-bundle/v1';

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

    /**
     * The subject directory this instance reads, or '' for a frozen snapshot
     * whose documents are all in hand already and whose bytes must never be
     * re-resolved against a mutable library.
     */
    private string $dir;

    /** @var array<string, array{0:bool, 1:mixed}> subject => [document present, decoded value] */
    private array $documents = [];

    /** @var ?list<string> the subjects this source declares, resolved once */
    private ?array $names;

    /** @var ?array<string,mixed> the profiles map, decoded once */
    private ?array $profiles;

    /** @var ?array<string,string> subject => AdapterLibrary-owned disposition path */
    private ?array $documentPaths;

    /** AdapterLibrary-owned profiles path, or null for the legacy string source. */
    private ?string $profilesPath;

    /**
     * @param ?list<string>         $names    prefilled for a frozen snapshot, null to resolve from disk
     * @param ?array<string,mixed>  $profiles prefilled for a frozen snapshot, null to resolve from disk
     * @param ?array<string,string> $documentPaths exact object-owned document paths, null for string resolution
     */
    private function __construct(
        string $dir,
        ?array $names,
        ?array $profiles,
        ?array $documentPaths = null,
        ?string $profilesPath = null
    ) {
        $this->dir = $dir;
        $this->names = $names;
        $this->profiles = $profiles;
        $this->documentPaths = $documentPaths;
        $this->profilesPath = $profilesPath;
    }

    /**
     * The explicit legacy registry DOCUMENT: its own root shape and its
     * profiles, both of which are self-contained (validate_profiles() resolves
     * each profile's `manifest` against the registry's own declared names,
     * never against the directory).
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
     *     comparison over `adapter-packages/<slug>/package/` plus
     *     `platform/adapter-library/core/` and exits 1 on any difference, and
     *     sandbox/tests/offline/policy/regress_manifest_dispositions.php runs
     *     the real loader over the whole shipped library in the merge gate.
     *
     * WP-4.4 moved the source from one document to one per subject, and this
     * function is where the two costs meet. It decodes NO entry: every entry
     * document is read lazily, by the caller that names it, so a one-pin
     * Policy::load() decodes ONE entry where the monolith decoded sixteen —
     * WP-1.2's own argument (the paragraph above) applied one layer down. The
     * only work here is the profiles map, which must resolve its `manifest`
     * references against the subjects this source declares, exactly as
     * validate_profiles() resolved them against the monolith's `manifests`
     * keys; a library with no profiles resolves nothing and does not even
     * enumerate the directory.
     */
    public static function load(string $dir): ?self {
        $library = rtrim($dir, '/');
        if (is_file($library . '/' . self::MONOLITH)) {
            throw new \RuntimeException(
                "wprism: manifest library '$library' still carries the pre-split " . self::MONOLITH
                . '. The reviewed claim source is the per-subject directory ' . self::DIRECTORY
                . '/ (spec/repo-format.md § v3.4) and that file is no longer read, so leaving it in place would '
                . 'publish ratification bytes nothing enforces — split it into one document per adapter under '
                . self::DIRECTORY . '/ and remove it'
            );
        }
        $subjects = $library . '/' . self::DIRECTORY;
        if (!is_dir($subjects)) {
            return null;
        }
        $self = new self($subjects, null, null);
        // names() is a directory scan, so it is paid only when something needs
        // it: the ONLY load-time reader of the declared-subject set is the
        // profile resolution below, and a library with no profiles has nothing
        // to resolve. Measured in regress_policy_load_scale.php — over an
        // identical 10,000-manifest library, a 10,001-subject reviewed source
        // now costs what a 2-subject one costs, where the monolith's own decode
        // was a second per-entry term on top of the adapter-source scan.
        $profiles = $self->profiles();
        if ($profiles !== []) {
            self::validate_profiles($profiles, $self->names());
        }
        return $self;
    }

    /**
     * Load the production reviewed source through its closed library object.
     *
     * The string entry point above remains an explicit legacy flat-layout
     * reader for compatibility tests and callers that deliberately select that
     * format. Production callers hand over the inventory they already
     * validated, so neither profiles nor per-subject dispositions are
     * rediscovered by joining physical path spellings here.
     */
    public static function load_library(AdapterLibrary $library): self {
        $paths = [];
        foreach ($library->packages() as $package) {
            $paths[$package->name()] = $package->dispositionPath();
        }
        ksort($paths, SORT_STRING);
        $names = array_keys($paths);
        $self = new self(
            dirname($library->profilesPath()),
            $names,
            null,
            $paths,
            $library->profilesPath()
        );
        $profiles = $self->profiles();
        if ($profiles !== []) {
            self::validate_profiles($profiles, $names);
        }
        return $self;
    }

    /**
     * The subjects this source declares — the directory listing, never a
     * decode, so the AUTHORING question ("which adapters are reviewed here")
     * costs one readdir and the RUNTIME question ("is this pin reviewed")
     * costs one stat.
     *
     * A file that is not named for a canonical adapter slug refuses rather
     * than being skipped: this directory is a closed namespace, and a
     * `2024.json` or a `NOTES.json` dropped into it is reviewed bytes with no
     * subject — the authoring half of the same mistake `make release-gate`'s
     * two-way comparison catches for an entry whose manifest is gone.
     *
     * @return list<string>
     */
    private function names(): array {
        if ($this->names !== null) {
            return $this->names;
        }
        $files = glob($this->dir . '/*.json');
        if ($files === false) {
            throw new \RuntimeException(
                "wprism: manifest disposition directory '{$this->dir}' could not be enumerated — "
                . 'WPrism refuses to treat unreadable reviewed claim bytes as absent'
            );
        }
        $names = [];
        foreach ($files as $file) {
            $name = basename($file, '.json');
            if ($name === self::PROFILES_DOCUMENT) {
                continue;
            }
            if (!self::is_subject_name($name)) {
                throw new \RuntimeException(
                    "wprism: manifest disposition document '$file' is not named for a canonical adapter slug; every "
                    . 'document in ' . self::DIRECTORY . "/ is one adapter's reviewed entry, addressed by its name"
                );
            }
            $names[] = $name;
        }
        sort($names, SORT_STRING);
        return $this->names = $names;
    }

    /**
     * One subject's document: [present, decoded]. Present-and-null is a real
     * state and is NOT absence — see assert_covers() for why the two must stay
     * distinguishable.
     *
     * @return array{0:bool, 1:mixed}
     */
    private function document(string $name): array {
        if (array_key_exists($name, $this->documents)) {
            return $this->documents[$name];
        }
        if ($this->documentPaths !== null) {
            $file = $this->documentPaths[$name] ?? null;
            return $this->documents[$name] = $file !== null
                ? [true, Canon::decode(Canon::read_file($file))]
                : [false, null];
        }
        if ($this->dir === '') {
            // A frozen snapshot carries every document it will ever have.
            return $this->documents[$name] = [false, null];
        }
        if ($name === self::PROFILES_DOCUMENT) {
            throw new \RuntimeException(
                "wprism: '" . self::PROFILES_DOCUMENT . "' is the reserved name of the profiles document in "
                . self::DIRECTORY . '/, so no adapter may be called that'
            );
        }
        if (!self::is_subject_name($name)) {
            // No document can be addressed by a name outside the grammar, so
            // this is absence — reported by assert_covers()'s coverage
            // sentence, not by a second refusal about the shape of a name the
            // manifest validator already owns.
            return $this->documents[$name] = [false, null];
        }
        $file = $this->dir . '/' . $name . '.json';
        return $this->documents[$name] = is_file($file)
            ? [true, Canon::decode(Canon::read_file($file))]
            : [false, null];
    }

    private static function is_subject_name(string $name): bool {
        return preg_match(self::NAME_PATTERN, $name) === 1 && preg_match('/[a-z]/D', $name) === 1;
    }

    /**
     * Require a reviewed entry for exactly the manifests handed in — the
     * validate-what-you-were-given shape from_snapshot() has always had, on
     * the live path.
     *
     * Callers pass the SHIPPED subset only (AdapterSources::shipped_manifests(),
     * "Only the shipped subset participates in disposition/registry coverage"):
     * an out-of-tree adapter has no reviewed entry by construction and
     * demanding one is exactly the issue #3314 failure.
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
            // "manifest disposition 'core' must be an object". Since WP-4.4
            // the same distinction is a FILE that exists and decodes to null,
            // which is why document() answers [present, value] rather than a
            // value that has to double as its own absence sentinel.
            if (!$this->document($name)[0]) {
                $missing[] = $name;
            }
        }
        if ($missing !== []) {
            sort($missing, SORT_STRING);
            throw new \RuntimeException(
                'wprism: manifest disposition coverage mismatch; missing=[' . implode(',', $missing) . '], extra=[]'
            );
        }
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '');
            self::validate_entry($name, $this->document($name)[1], $manifest);
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
     * report(), so `wp wprism capabilities --all` and the adapter catalog), and
     * blockers()/report() below. Until this call existed those readers
     * projected UNVALIDATED reviewed bytes. Measured against a woocommerce
     * entry tampered four ways and read back through `wp wprism capabilities
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

    /**
     * Revalidate frozen bytes without reopening the mutable manifest dir.
     *
     * The frozen wire is the WHOLE registry document and stays that way
     * through the split: `Policy::export_snapshot()` carries `dispositions =>
     * data()` and a compiled artifact binds those bytes, so a snapshot taken
     * before WP-4.4 and one taken after are byte-identical and validate_root()
     * keeps its one live reader. There is no directory here to address, which
     * is why this instance is constructed with an empty $dir: frozen bytes may
     * never silently re-resolve against a library that has moved on.
     */
    public static function from_snapshot(array $data, array $manifests): self {
        self::validate_root($data, 'frozen manifest disposition registry');
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '');
            if ($name === '' || !isset($data['manifests'][$name])) {
                throw new \RuntimeException("wprism: frozen disposition registry has no entry for manifest '$name'");
            }
            self::validate_entry($name, $data['manifests'][$name], $manifest);
        }
        self::validate_profiles($data['profiles'], array_keys($data['manifests']));
        $names = array_map('strval', array_keys($data['manifests']));
        sort($names, SORT_STRING);
        $self = new self('', $names, $data['profiles'], []);
        foreach ($data['manifests'] as $name => $entry) {
            $self->documents[(string) $name] = [true, $entry];
        }
        return $self;
    }

    /**
     * The whole registry, reassembled: `format` plus every subject's decoded
     * entry plus the profiles map.
     *
     * This is the one call that materialises every document, and every caller
     * of it already walks the whole library — export_snapshot() freezing a
     * policy, report() rendering the catalog, sha256() addressing the source.
     * The reassembly is what makes the split invisible downstream: Canon
     * sorts keys at every level, so these bytes are the monolith's bytes and
     * `registry_sha256` did not move (WP-4.5 is the rider that narrows that
     * hash to per-subject addressing; WP-4.4 deliberately leaves it whole).
     */
    public function data(): array {
        $manifests = [];
        foreach ($this->names() as $name) {
            $manifests[$name] = $this->document($name)[1];
        }
        return ['format' => self::FORMAT, 'manifests' => $manifests, 'profiles' => $this->profiles()];
    }

    public function entry(string $name): ?array {
        [$present, $entry] = $this->document($name);
        return $present && is_array($entry) ? $entry : null;
    }

    /**
     * The profiles map supplied by AdapterLibrary — authored at
     * `platform/adapter-library/profiles.json` and installed at
     * `agent/adapter-library/platform/profiles.json` — keyed independently of
     * the adapter documents. The explicit legacy flat reader resolves the old
     * `dispositions/profiles.json` spelling through `$this->dir` instead.
     *
     * Absent means none. The monolith's root REQUIRED a `profiles` key and
     * accepted `{}`; a directory has no root to require a key of, so a library
     * that reviews no profiles now ships no profiles document, and the
     * refusals that matter (a profile naming an unreviewed manifest, a
     * malformed profile) are unchanged in validate_profiles().
     *
     * @return array<string,mixed>
     */
    public function profiles(): array {
        if ($this->profiles !== null) {
            return $this->profiles;
        }
        $file = $this->profilesPath ?? ($this->dir . '/' . self::PROFILES_DOCUMENT . '.json');
        if (($this->dir === '' && $this->profilesPath === null) || !is_file($file)) {
            return $this->profiles = [];
        }
        $decoded = Canon::decode(Canon::read_file($file));
        if (!is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
            throw new \RuntimeException(
                "wprism: manifest disposition profiles document '$file' must be an object of profile name => profile"
            );
        }
        return $this->profiles = $decoded;
    }

    /**
     * The content address of these exact reviewed bytes. It is the number a
     * host contract pins as `registry_sha256` and re-observes later, so it has
     * one definition rather than one per report producer.
     */
    public function sha256(): string {
        return hash('sha256', Canon::encode($this->data()));
    }

    /**
     * The agent platform boundary this library ships, validated against the
     * loaded agent.
     *
     * The version agreement is not decoration: a platform object naming a
     * different agent/spec version than the code reading it would let a claim
     * describe a runtime nobody is running. Loud, and before any claim is
     * projected from it. A non-null string explicitly selects the legacy flat
     * root; production resolves the path through AdapterLibrary.
     *
     * @return array<string,mixed>
     */
    public static function platform_boundary(?string $dir = null): array {
        if ($dir === null) {
            if (!class_exists(Policy::class)) {
                throw new \RuntimeException(
                    'wprism: the shipped adapter library must be selected explicitly when Policy is unavailable'
                );
            }
            return self::platform_boundary_library(Policy::shipped_adapter_library());
        }
        $file = rtrim($dir, '/') . '/' . self::PLATFORM_RELATIVE;
        return self::platform_boundary_file($file);
    }

    /** Resolve the production boundary only through AdapterLibrary's accessor. */
    public static function platform_boundary_library(AdapterLibrary $library): array {
        return self::platform_boundary_file($library->platformBoundaryPath());
    }

    /** @return array<string,mixed> */
    private static function platform_boundary_file(string $file): array {
        $label = "agent platform boundary '$file'";
        if (!is_file($file)) {
            throw new \RuntimeException("wprism: $label is absent; this manifest library declares no platform boundary");
        }
        $data = Canon::decode(Canon::read_file($file));
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        if ($keys !== ['format', 'platform']
            || ($data['format'] ?? null) !== self::PLATFORM_FORMAT
            || !is_array($data['platform'] ?? null) || array_is_list($data['platform'])
            || !is_array($data['platform']['compatibility'] ?? null)) {
            throw new \RuntimeException("wprism: $label has an unsupported or malformed root");
        }
        $platform = $data['platform'];
        if (($platform['agent_version'] ?? null) !== (defined('WPRISM_AGENT_VERSION') ? WPRISM_AGENT_VERSION : '0.7.0')
            || ($platform['spec_version'] ?? null) !== (defined('WPRISM_SPEC_VERSION') ? WPRISM_SPEC_VERSION : 3)) {
            throw new \RuntimeException("wprism: $label platform version disagrees with the loaded agent");
        }
        return $platform;
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
            throw new \RuntimeException('wprism: cannot project a malformed manifest disposition capability claim');
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
                throw new \RuntimeException("wprism: manifest disposition '$name' has a malformed capability section");
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
                throw new \RuntimeException("wprism: manifest disposition '$name' has a malformed deletion selector");
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
                throw new \RuntimeException("wprism: manifest disposition '$name' has a malformed operation");
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
     * PUBLIC since WP-4.7, and for one caller with one reason.
     * `AdapterCertification::signStatement()` binds the compatibility cells a
     * certificate was exercised against (spec § v3.6), and a narrowing adapter
     * must bind its NARROWED cells — so the signer needs the same subset this
     * projection computes. Re-deriving it there would be a second copy of rule
     * 2's widening refusal in the file that mints the signature, drifting
     * silently the moment either moved; calling this instead also means the
     * widening refusal fires at MINT time rather than only when the minted
     * certificate is first verified.
     *
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $platformBoundary
     * @return array<string,mixed>
     */
    public static function narrowed_environment(array $manifest, array $platformBoundary): array {
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
                "wprism: manifest '$name' environment declaration must be a non-empty object of axis => exercised cells"
            );
        }
        // Sorted, so the refusal an author meets names the same axis whatever
        // order the declaration happens to be authored in.
        ksort($declared, SORT_STRING);
        foreach ($declared as $axis => $cells) {
            $axis = (string) $axis;
            if (!in_array($axis, self::ENVIRONMENT_AXES, true)) {
                throw new \RuntimeException(
                    "wprism: manifest '$name' declares environment axis '$axis', which a capability claim does not "
                    . 'state; narrowable axes are ' . implode(', ', self::ENVIRONMENT_AXES)
                );
            }
            if (!self::string_list($cells, false)) {
                throw new \RuntimeException(
                    "wprism: manifest '$name' environment axis '$axis' must be a non-empty list of distinct cell names"
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
                    "wprism: manifest '$name' environment axis '$axis' declares [" . implode(', ', $wider)
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
                "wprism: external manifest disposition '$name' must be a certified entry"
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
                // issue #3372: an uncovered manifest is a BLOCKER, not a skip.
                // Silently dropping it would let a caller read a manifest with
                // no reviewed disposition as ready, which is exactly the "a
                // manifest cannot certify itself merely by existing" doctrine
                // this file states. `uncovered` is a synthesized runtime status
                // only; validate_entry() still refuses it as a DECLARED status.
                //
                // WP-1.2 made this row REACHABLE where it used to be a
                // fail-safe: load() no longer refuses a whole library over a
                // file nobody pinned, so a report over the LIBRARY (`wp wprism
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
                // issue #3372: surface the uncovered manifest as an explicit row
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
                "wprism: $label must contain exactly format, manifests, and profiles for " . self::FORMAT
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
                throw new \RuntimeException("wprism: manifest disposition profile '$name' is malformed");
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
            throw new \RuntimeException("wprism: manifest disposition '$name' must be an object");
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
            throw new \RuntimeException("wprism: manifest disposition '$name' has a malformed required field");
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
            throw new \RuntimeException("wprism: manifest disposition '$name' capabilities are malformed");
        }
        $deletionKeys = array_keys($cap['deletion_semantics']);
        sort($deletionKeys, SORT_STRING);
        if ($deletionKeys !== ['supported', 'unsupported']
            || !self::string_list($cap['deletion_semantics']['supported'] ?? null)
            || !self::string_list($cap['deletion_semantics']['unsupported'] ?? null, false)) {
            throw new \RuntimeException("wprism: manifest disposition '$name' deletion semantics are malformed");
        }
        foreach (array_merge($cap['entity_sections'], $cap['field_sections']) as $section) {
            if (!array_key_exists($section, $manifest)) {
                throw new \RuntimeException(
                    "wprism: manifest disposition '$name' names absent manifest section '$section'"
                );
            }
        }
        foreach ($entry['unsupported'] as $i => $unsupported) {
            if (!is_array($unsupported) || array_is_list($unsupported)
                || !is_string($unsupported['surface'] ?? null) || $unsupported['surface'] === ''
                || !is_string($unsupported['operation'] ?? null) || $unsupported['operation'] === ''
                || !is_string($unsupported['reason'] ?? null) || $unsupported['reason'] === '') {
                throw new \RuntimeException("wprism: manifest disposition '$name' unsupported[$i] is malformed");
            }
        }
        $unsupportedSurfaces = array_fill_keys(array_column($entry['unsupported'], 'surface'), true);
        foreach (($manifest['tables'] ?? []) as $table => $rule) {
            if (($rule['class'] ?? null) === 'authored_typed_snapshot_post_v1'
                && !isset($unsupportedSurfaces["tables.$table"])) {
                throw new \RuntimeException(
                    "wprism: manifest disposition '$name' must mark intent-only table '$table' unsupported"
                );
            }
        }

        $defaultRows = [];
        foreach ($entry['default_authored_keyspaces'] as $row) {
            if (!is_array($row) || array_is_list($row)
                || !is_string($row['table'] ?? null) || $row['table'] === ''
                || !in_array($row['status'] ?? null, ['justified', 'unsupported'], true)
                || !is_string($row['reason'] ?? null) || $row['reason'] === '') {
                throw new \RuntimeException("wprism: manifest disposition '$name' has malformed default authored keyspace evidence");
            }
            $defaultRows[$row['table']] = true;
            if (!isset($manifest['tables'][$row['table']])
                || ($manifest['tables'][$row['table']]['default_class'] ?? null) !== 'authored') {
                throw new \RuntimeException(
                    "wprism: manifest disposition '$name' names non-default-authored keyspace '{$row['table']}'"
                );
            }
        }
        foreach (($manifest['tables'] ?? []) as $table => $rule) {
            if (($rule['default_class'] ?? null) === 'authored' && !isset($defaultRows[$table])) {
                throw new \RuntimeException(
                    "wprism: manifest disposition '$name' omits default authored keyspace '$table'"
                );
            }
        }

        $evidence = $entry['evidence'] ?? null;
        if ($status === 'certified') {
            if (!is_array($evidence) || array_is_list($evidence)
                || ($evidence['bundle_schema'] ?? null) !== $evidenceSchema
                || !self::string_list($evidence['tests'] ?? null, !$requireExerciseTests, true)) {
                throw new \RuntimeException("wprism: certified manifest disposition '$name' lacks current bundle evidence");
            }
            $plugin = $manifest['plugin'] ?? null;
            if (is_string($plugin) && $plugin !== '') {
                if (($entry['supported_versions']['plugin'] ?? null) !== $plugin
                    || Canon::encode($entry['supported_versions']['range'] ?? null)
                        !== Canon::encode($manifest['version_range'] ?? null)) {
                    throw new \RuntimeException(
                        "wprism: certified manifest disposition '$name' versions disagree with its manifest contract"
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

<?php
namespace Duo;

/**
 * Where each pinned adapter came from, and what that origin is allowed to do.
 *
 * Until DUO-3314 the engine knew exactly one adapter source: the manifest
 * directory that ships and mounts with the agent. Every identity check hung off
 * that assumption — ManifestDispositions::load() still requires the shipped
 * directory's *.json set and its reviewed disposition set to match one-for-one,
 * which is what stops a replaced manifests directory from silently discarding
 * shipped claims. The cost was that a site could not install an adapter at all:
 * dropping one extra file beside the shipped set failed that coverage check and
 * took down every unrelated shipped adapter with it.
 *
 * This class adds sources rather than loosening the first. There are now
 * THREE, and the whole file is organized around what each one's owner already
 * consented to:
 *
 *   - `shipped` — the agent's own manifest library. Reviewed, digest-bound,
 *     and the only source whose code the engine will load.
 *   - `site` — `<repo>/adapters/<name>.json`. Authored by the operator, in the
 *     operator's own repository, and travelling with it.
 *   - `plugin` — `<plugin-dir>/duo-adapter.json`, bundled by an ACTIVE plugin
 *     (DUO-3339). Third-party content that arrives, and changes, through a
 *     plugin update the operator did not author.
 *
 * A site or plugin manifest OVERLAYS the shipped set — they are additional
 * pinnable adapters, never replacements. The shipped directory's coverage check
 * is untouched, so shipped claims keep proving themselves against exactly the
 * bytes they always did, and a repository with no `adapters/` directory on a
 * host with no plugin bundles takes no new code path at all.
 *
 * Four properties are load-bearing, all enforced before any manifest reaches a
 * policy consumer or a target:
 *
 * 1. **Identity is unambiguous.** A site adapter whose file name collides with a
 *    shipped manifest, or whose declared `name` disagrees with its own file
 *    name, or which collides with a shipped manifest's declared name, is a loud
 *    refusal. Shadowing a shipped adapter is never silent replacement — pin
 *    order must never be what decides which adapter definition wins.
 *
 * 2. **PRECEDENCE is `shipped > site > plugin`, and it is derived from the walk
 *    order below rather than declared anywhere.** A plugin-bundled adapter
 *    whose name a shipped or site definition already answers to is not refused:
 *    it is DROPPED and REPORTED, as a `not_installed` row naming its winner, on
 *    every run. The reviewed definition always wins, the loser is never loaded,
 *    and a pin that writes down `source: "plugin"` for that name still refuses
 *    loudly through Policy::validate_manifest_sources(). Refusing the whole
 *    scan instead — the site source's rule — would mean that the day a popular
 *    plugin starts shipping a `duo-adapter.json` whose name this project also
 *    ships, every site running that plugin loses every command through an
 *    automatic plugin update its operator never performed. The site source can
 *    defend whole-scan refusal because `adapters/` is operator-authored;
 *    WP_PLUGIN_DIR is not. Two ACTIVE plugins declaring one name have no
 *    precedence available between them, so both are dropped and the pair is
 *    reported as one `source_collision` refusal.
 *
 * 3. **A plugin-source condition is refused PER ADAPTER, never whole-scan.**
 *    scan()'s whole-directory rationale is about the operator's own
 *    installation; applied to third-party content it turns a plugin author's
 *    typo into a site-wide outage with no operator remedy short of
 *    deactivating the plugin. So every condition in the plugin block records a
 *    refusal ROW in BOTH scan modes — the same helper, the same message, one
 *    scan — that adapter is not installed, and the walk continues. The refusal
 *    becomes fatal exactly when somebody pinned it: file() throws the refusal's
 *    own message and remediation instead of a generic not-found. Unpinned
 *    refusals are reported by `wp duo adapter-survey` and `duo adapter
 *    list|doctor`.
 *
 * 4. **A data-only manifest acquires no executable privileges.** The three
 *    channels that load PHP by name — `interpreter`, `regen_dependency.
 *    regenerator`, and `providers[].source: "manifest"` — all resolve inside
 *    the agent's own manifest directory (Policy::interpreters(),
 *    Policy::regenerators(), Providers::load()). An out-of-tree manifest naming
 *    one of them would either reach shipped bytes it does not own or resolve to
 *    nothing, so it is refused with the remediation instead. `source: "plugin"`
 *    providers stay available: their trust anchor is the installed plugin, named
 *    explicitly and version-bounded, which is a trust decision the operator
 *    already made by installing that plugin. The boundary is identical for both
 *    out-of-tree sources; only the noun in the message changes.
 *
 * 5. **Certification is external, and a bundled adapter cannot hold it.** With
 *    no signed companion a site adapter carries the synthesized `uncertified`
 *    record below — a fourth status word which cannot be pasted into
 *    dispositions.json as self-certification. A companion under
 *    adapters/certifications is accepted only when its disposition and complete
 *    evidence are signed by a key in the agent-owned authority registry. A
 *    PLUGIN-bundled adapter is `uncertified` by construction and can never be
 *    anything else: AdapterCertification derives the certificate path from the
 *    repository, opens `adapters/<name>.json` to hash, and the signed statement
 *    binds `adapter.source: "site"` and `adapter.path: "adapters/<name>.json"`.
 *    Its provenance reason says so, and its diagnostics remediation is the
 *    promotion path — install the same adapter as a repository package, sign
 *    it, pin it; the site copy then wins by precedence and the bundled copy
 *    reports `not_installed`, with no plugin deactivation anywhere. Either
 *    record joins the adapter digest through the same `disposition` slot a
 *    shipped reviewed entry uses, so source/evidence changes move only that
 *    out-of-tree adapter's identity; shipped rows remain byte-for-byte
 *    unchanged.
 */
final class AdapterSources {
    /** v2 adds externally signed certification envelopes for site adapters. */
    public const FORMAT = 'duo-adapter-sources/v2';
    public const LEGACY_FORMAT = 'duo-adapter-sources/v1';

    /** The agent's own manifest library — the historical single source. */
    public const SHIPPED = 'shipped';
    /** `<repo>/adapters/` — installed by the site, travels in the site repo. */
    public const SITE = 'site';
    /** `<plugin-dir>/duo-adapter.json` — bundled by an active plugin (DUO-3339). */
    public const PLUGIN = 'plugin';

    public const SITE_DIR = 'adapters';
    public const CERTIFICATION_DIR = 'certifications';

    /**
     * Exactly one file, exactly named, at the root of the plugin that owns it.
     *
     * A glob would import the whole site-source scan surface into third-party
     * directories and let one plugin install N adapters; one-to-one is the
     * shape this file already uses for certificates (certification_files())
     * and the shape AdapterCertification::bundleFile() requires of a bundle.
     * The `duo-` prefix namespaces it exactly as site.duo.json and
     * .duo-envs.json do, so the scan never has an opinion about a name a
     * plugin author could reasonably have chosen for something else.
     */
    public const PLUGIN_FILE = 'duo-adapter.json';
    /**
     * The provenance prefix for a bundled adapter, and it is load-bearing: a
     * plugin whose directory is literally named `adapters` would otherwise
     * record a path indistinguishable from a repo-relative site adapter.
     */
    public const PLUGIN_PATH_PREFIX = 'plugins';

    /**
     * Trust tiers named exactly as docs/proposals/engine-adapter-boundary.md
     * names them, so a diagnostic line and the doctrine cannot drift into two
     * vocabularies for one decision. Ordered least to most executable authority;
     * a manifest reports the highest tier it actually reaches.
     */
    public const TIER_DECLARATIVE = 'declarative_manifest';
    public const TIER_NATIVE_ACTION = 'native_action';
    public const TIER_PLUGIN_PROVIDER = 'plugin_provider';
    public const TIER_COMPATIBILITY_SHIM = 'compatibility_shim';

    /**
     * A stable code for every condition the scan refuses (DUO-3339).
     *
     * Minted HERE rather than in the reporting layer, and for the same reason
     * the refusal messages live here: one scan produces both the load-time
     * exception and the reported row (see scan()), so a code invented by a
     * reporter could name a condition this file no longer has. The message a
     * code carries is byte-identical to the one discover() throws — a catalog
     * that paraphrased an engine refusal would be a second, drifting copy of
     * the rule.
     */
    public const REFUSAL_SOURCE_NOT_IN_REPOSITORY = 'source_not_in_repository';
    public const REFUSAL_RESERVED_NAME = 'reserved_name';
    public const REFUSAL_SYMLINK_SOURCE = 'symlink_source';
    public const REFUSAL_NESTED_JSON = 'nested_json';
    public const REFUSAL_EXTENSION_CASE_MISMATCH = 'extension_case_mismatch';
    public const REFUSAL_SHADOWS_SHIPPED = 'shadows_shipped';
    public const REFUSAL_AMBIGUOUS_IDENTITY = 'ambiguous_identity';
    public const REFUSAL_NAME_COLLISION = 'name_collision';
    public const REFUSAL_CASE_COLLISION = 'case_collision';
    public const REFUSAL_MALFORMED_MANIFEST = 'malformed_manifest';
    /**
     * The four conditions DUO-3314's signed-certification pass added to this
     * scan. They are codes here for the same reason the nine above are: every
     * one of them is a whole-directory refusal, so an operator who hits one
     * cannot run any other command to find out which file caused it.
     */
    public const REFUSAL_INVALID_NAME = 'invalid_adapter_name';
    public const REFUSAL_OUT_OF_TREE_PRIVILEGE = 'out_of_tree_privilege';
    public const REFUSAL_CERTIFICATION_SOURCE = 'certification_source';
    public const REFUSAL_CERTIFICATE_INVALID = 'certificate_invalid';

    /**
     * The site repository's own policy file, which every grammar verdict
     * loads. Not one of the scan's conditions at all — discover() never reads
     * site.duo.json, Policy::load() opens it first — so it is a survey-only
     * refusal, kept here because a consumer grouping refusal codes should not
     * have to know which layer produced one.
     */
    public const REFUSAL_SITE_POLICY_UNREADABLE = 'site_policy_unreadable';

    /**
     * The two conditions DUO-3339's plugin source adds, both of which exist
     * only because that source has no reviewed owner to arbitrate between two
     * claims:
     *
     *   - `source_collision` — two ACTIVE plugins bundle one name. Precedence
     *     ranks sources, not siblings, so there is no honest winner to pick:
     *     both are dropped and the pair is named in one row.
     *   - `plugin_anchor_mismatch` — a bundled manifest must declare the
     *     plugin that owns it, the exact analogue of
     *     Providers::plugin_anchor_problem(). It is what makes the frozen
     *     provenance path re-derivable with no new wire key, and it is what
     *     lets Policy's existing providers[].plugin agreement rule close the
     *     provider story for this source without a new rule.
     */
    public const REFUSAL_SOURCE_COLLISION = 'source_collision';
    public const REFUSAL_PLUGIN_ANCHOR = 'plugin_anchor_mismatch';

    /**
     * A directory this scan does not own could not be enumerated at all —
     * mode 0711 (search without read) is the ordinary way to reach it. It is
     * a refusal rather than a shrug because the thing that could not be read
     * is the evidence: without the directory listing, the near-miss sweep
     * cannot prove a plugin bundles exactly ONE `duo-adapter.json`, so
     * installing the one file that happens to be openable would be asserting
     * something nobody checked.
     */
    public const REFUSAL_SOURCE_UNREADABLE = 'source_unreadable';

    /**
     * A refusal row's blast radius, which two different consumers need and
     * neither can infer.
     *
     * `source` is a whole-directory refusal: nothing in that source was
     * judged, so a grammar verdict about any adapter in it is not available
     * and the pin set will not load. `adapter` is one adapter refused while
     * the rest of its source kept walking — the plugin source's only mode.
     * Without the distinction, grammar_verdict() would read one plugin
     * author's typo as grounds to stop judging every site adapter, and
     * AdapterCatalog::blockers() would attribute a pin-set failure to a source
     * that had nothing to do with it.
     */
    public const SCOPE_SOURCE = 'source';
    public const SCOPE_ADAPTER = 'adapter';

    /**
     * Why an adapter that is INSTALLED on this machine is nonetheless not
     * loaded. Neither of these is a refusal: the file is well-formed, the
     * engine simply resolved the name to something else (`shadowed`, which
     * names its winner) or to nothing yet, because the plugin that bundles it
     * is not active (`plugin_not_active`, reported by survey() only — an
     * inactive plugin is not consent to run its adapter).
     */
    public const NOT_INSTALLED_SHADOWED = 'shadowed';
    public const NOT_INSTALLED_PLUGIN_INACTIVE = 'plugin_not_active';

    /**
     * A surveyed adapter's grammar verdict (DUO-3339).
     *
     * `blocked_by_source_refusal` is a third word rather than an `error`
     * because the two are different facts and only one of them is about this
     * adapter. The engine refuses an adapter source WHOLE-DIRECTORY, before
     * any manifest in it is validated, so once a sibling file is refused this
     * adapter's own grammar has not been judged at all — reporting that as
     * `error` would blame a manifest nobody has read yet, and counting it as a
     * grammar error would inflate the number an operator uses to decide how
     * much is broken.
     */
    /**
     * A site adapter's certification word, which is DUO-3314's vocabulary plus
     * one honest gap. `certification_unjudged` is the same shape as
     * `blocked_by_source_refusal` above and exists for the same reason: when
     * the certification source itself is refused, NO companion certificate is
     * paired, so a signed adapter's evidence was never looked at. Reporting
     * that as `uncertified` would be a judged negative for something never
     * judged — the one failure mode this whole surface exists to remove.
     */
    public const CERTIFICATION_UNJUDGED = 'certification_unjudged';

    public const GRAMMAR_OK = 'ok';
    public const GRAMMAR_ERROR = 'error';
    public const GRAMMAR_BLOCKED = 'blocked_by_source_refusal';

    /** @var array<string, array{source:string, file:string, path:string}> */
    private array $origins;
    /** @var array<string, array> synthesized disposition per out-of-tree name */
    private array $provenance;
    /** @var array<string, array> verified signed envelope per certified site adapter */
    private array $certificates;
    /** @var array<string, array> capability claim derived from signed evidence */
    private array $claims;
    /** @var array<string, bool> whether the repository explicitly pins source + final digest */
    private array $explicitPins = [];
    /** Frozen wire generation retained when reconstructing a legacy snapshot. */
    private string $wireFormat;
    /**
     * What this LIVE scan saw beyond the adapters it installed: which sources
     * were reachable at all, which plugin-bundled adapters were refused, which
     * installed adapters lost to a higher-precedence definition, and the
     * refused names a pin can still ask for.
     *
     * Empty for a reconstructed (frozen) instance, which is the honest answer:
     * from_snapshot() deliberately reopens no mutable source, so it knows
     * nothing about what is on this machine's disk today.
     *
     * @var array{not_installed:list<array<string,mixed>>, refusals:list<array<string,mixed>>, refused_names:array<string,array<string,mixed>>, sources:list<array<string,mixed>>}
     */
    private array $scanReport = [
        'not_installed' => [],
        'refusals' => [],
        'refused_names' => [],
        'sources' => [],
    ];

    private function __construct(
        array $origins,
        array $provenance,
        array $certificates = [],
        array $claims = [],
        string $wireFormat = self::FORMAT,
        array $scanReport = []
    ) {
        $this->origins = $origins;
        $this->provenance = $provenance;
        $this->certificates = $certificates;
        $this->claims = $claims;
        $this->wireFormat = $wireFormat;
        foreach (['not_installed', 'refusals', 'refused_names', 'sources'] as $key) {
            if (isset($scanReport[$key]) && is_array($scanReport[$key])) {
                $this->scanReport[$key] = $scanReport[$key];
            }
        }
    }

    /**
     * Scan every installed source and refuse ambiguity before a single pin is
     * resolved. Whole-directory rather than pin-scoped on purpose: an adapter
     * that shadows a shipped one is a broken installation whether or not this
     * particular site.duo.json happens to pin it, and the operator should learn
     * that from the next command rather than from the first command that pins
     * it. Same discipline ManifestDispositions::load() applies to the shipped
     * directory.
     *
     * The PLUGIN source is the exception, and the header states the reason at
     * length: its refusals are recorded rather than thrown even here, so a
     * third party's typo cannot take down an installation the operator did not
     * author. They are carried on the instance so file() can make one fatal at
     * the moment a pin actually asks for it.
     */
    public static function discover(string $manifestDir, ?string $repo): self {
        $refusals = [];
        $scan = self::scan($manifestDir, $repo, false, $refusals);
        return new self(
            $scan['origins'],
            $scan['provenance'],
            $scan['certificates'],
            $scan['claims'],
            self::FORMAT,
            [
                'not_installed' => $scan['not_installed'],
                'refusals' => $refusals,
                'refused_names' => $scan['refused_names'],
                'sources' => $scan['sources'],
            ]
        );
    }

    /**
     * The one scan, in its two modes.
     *
     * `$collect === false` is discover(): every condition below throws, with
     * the same message, at the same point, in the same order it always did —
     * a broken installation must not wait for a pin to reveal itself, and a
     * caller that reaches a manifest has been told nothing was ambiguous.
     *
     * `$collect === true` is survey(): the identical conditions, identical
     * messages, recorded as rows instead. That mode exists because the doctor
     * view has to SHOW a shadowed or ambiguous adapter, and a command that
     * died on the condition it was run to report would be useless exactly when
     * it was needed. Sharing one body rather than writing a second scan is the
     * whole point: a rule that moved in discover() and not in the reporter
     * would be a catalog quietly describing an engine that no longer exists.
     *
     * The two modes diverge in exactly three places, all stated at their site:
     * a refused site file is SKIPPED rather than aborting the walk, collect
     * mode decodes every shipped manifest up front (throw mode still decodes
     * them only when a site source or a plugin bundle exists — see
     * declared_names()), and collect mode additionally lists INSTALLED BUT
     * INACTIVE plugin bundles so an operator can see the adapter that is
     * sitting there waiting for an activation.
     *
     * The PLUGIN block is walked last, and that is the entire implementation
     * of the `shipped > site > plugin` precedence rule: by the time it runs,
     * every higher-ranked definition is already in $origins, so a bundled name
     * that collides with one is simply not installed. Nothing declares the
     * order; moving the block would move the rule, which is why it is stated
     * here and asserted by the suite.
     *
     * @param array<int, array<string,mixed>> $refusals collected in $collect mode
     * @return array{origins:array<string,array>, provenance:array<string,array>, manifests:array<string,array>, not_installed:list<array<string,mixed>>, refused_names:array<string,array<string,mixed>>, sources:list<array<string,mixed>>}
     */
    private static function scan(string $manifestDir, ?string $repo, bool $collect, array &$refusals): array {
        $origins = [];
        foreach (glob(rtrim($manifestDir, '/') . '/*.json') ?: [] as $file) {
            $name = basename($file, '.json');
            if ($name === 'dispositions') {
                continue;
            }
            if (!self::guarded(
                $collect,
                $refusals,
                self::SHIPPED,
                self::REFUSAL_INVALID_NAME,
                [$file],
                'rename the file to a canonical lowercase ASCII slug, or remove it from the manifest library',
                static fn() => self::assert_name($name, "shipped adapter '$file'")
            )) {
                continue;
            }
            $origins[$name] = ['source' => self::SHIPPED, 'file' => $file, 'path' => $file];
        }
        ksort($origins, SORT_STRING);
        // Collect mode reports one row per adapter, so it needs every shipped
        // manifest's own bytes whether or not a site source exists. Throw mode
        // must not pay that I/O (see declared_names()), so the decode is here
        // rather than in the glob above.
        $manifests = [];
        if ($collect) {
            foreach ($origins as $name => $origin) {
                $read = self::read_manifest((string) $origin['file']);
                if ($read['stage'] !== 'ok') {
                    self::refuse(
                        $collect,
                        $refusals,
                        self::SHIPPED,
                        self::REFUSAL_MALFORMED_MANIFEST,
                        [(string) $origin['path']],
                        "duo: shipped adapter '{$origin['path']}' cannot be read as a manifest: " . $read['error'],
                        self::repair_advice($read['stage'])
                        . ', or restore it from the agent release this library shipped with'
                    );
                    unset($origins[$name]);
                    continue;
                }
                $manifests[$name] = $read['manifest'];
            }
        }

        // The shipped set exactly as it stands before any overlay, because
        // both out-of-tree blocks need "which names does the SHIPPED library
        // declare" and $origins stops meaning that the moment the site loop
        // adds to it.
        $shippedOrigins = $origins;
        $shippedNames = null;
        // Memoized, and lazy for the reason declared_names() gives: a
        // repository with no adapters/ on a host with no plugin bundle must
        // pay no new I/O and take no new refusal path. Both out-of-tree blocks
        // share the one call, so a shipped library defect is reported once.
        $declaredNames = static function () use (
            &$shippedNames,
            $shippedOrigins,
            &$manifests,
            $collect,
            &$refusals
        ): array {
            if ($shippedNames === null) {
                $shippedNames = self::declared_names($shippedOrigins, $manifests, $collect, $refusals);
            }
            return $shippedNames;
        };

        $provenance = [];
        $certificates = [];
        $claims = [];
        $notInstalled = [];
        $refusedNames = [];
        $sources = [[
            'note' => "the agent's own manifest library, which ships and mounts with the agent",
            'path' => rtrim($manifestDir, '/'),
            'scanned' => true,
            'source' => self::SHIPPED,
        ]];
        self::scan_site_source(
            $manifestDir,
            $repo,
            $collect,
            $declaredNames,
            $refusals,
            $origins,
            $manifests,
            $provenance,
            $certificates,
            $claims,
            $sources
        );
        self::scan_plugin_source(
            $collect,
            $declaredNames,
            $refusals,
            $origins,
            $manifests,
            $provenance,
            $notInstalled,
            $refusedNames,
            $sources
        );
        ksort($origins, SORT_STRING);
        ksort($provenance, SORT_STRING);
        ksort($certificates, SORT_STRING);
        ksort($claims, SORT_STRING);
        ksort($manifests, SORT_STRING);
        return [
            'certificates' => $certificates,
            'claims' => $claims,
            'manifests' => $manifests,
            'not_installed' => $notInstalled,
            'origins' => $origins,
            'provenance' => $provenance,
            'refused_names' => $refusedNames,
            'sources' => $sources,
        ];
    }

    /**
     * The site half of the scan, extracted from scan() unchanged so the plugin
     * block below can run after it in every case — including the three cases
     * this half returns early from (no repository, no adapters/ directory, a
     * source that resolves outside the repository). Those used to be returns
     * out of scan() itself, which would have skipped the plugin source for a
     * reason that has nothing to do with it.
     *
     * Every refusal, message, and order inside is byte-identical to what
     * discover() threw before; the only additions are the `source`/`scope`
     * fields refuse() now stamps on a row and the `sources` inventory row.
     *
     * @param callable():array<string,string> $declaredNames memoized shipped declared names
     * @param list<array<string,mixed>> $refusals
     */
    private static function scan_site_source(
        string $manifestDir,
        ?string $repo,
        bool $collect,
        callable $declaredNames,
        array &$refusals,
        array &$origins,
        array &$manifests,
        array &$provenance,
        array &$certificates,
        array &$claims,
        array &$sources
    ): void {
        if ($repo === null) {
            $sources[] = [
                'note' => 'no site repository was named, so this process surveyed the shipped library alone; '
                    . 'pass a repository to include its ' . self::SITE_DIR . '/ source',
                'path' => null,
                'scanned' => false,
                'source' => self::SITE,
            ];
            return;
        }
        $siteDir = rtrim($repo, '/') . '/' . self::SITE_DIR;
        if (!is_dir($siteDir)) {
            $sources[] = [
                'note' => "this repository has no $siteDir directory, so it installs no site adapter",
                'path' => null,
                'scanned' => false,
                'source' => self::SITE,
            ];
            return;
        }
        // is_dir() FOLLOWS symlinks, so the source directory itself has to be
        // proved before anything inside it is trusted: `adapters` checked in as
        // a symlink is ordinary repository content (git stores symlinks), and
        // it would source every adapter from outside the repository while every
        // provenance record still read `adapters/<name>.json`. Resolving both
        // sides and requiring the exact expected location covers the symlinked
        // directory and a symlinked ancestor in one check — a repo path that is
        // itself reached through a link resolves the same way on both sides, so
        // naming the repo through a link stays supported.
        $resolvedRepo = realpath(rtrim($repo, '/'));
        $resolvedSite = realpath($siteDir);
        $expectedSite = $resolvedRepo === false ? '' : $resolvedRepo . '/' . self::SITE_DIR;
        if ($resolvedRepo === false || $resolvedSite === false || $resolvedSite !== $expectedSite) {
            $sources[] = [
                'note' => "$siteDir does not resolve to this repository's own " . self::SITE_DIR
                    . ' directory, so nothing in it was surveyed',
                'path' => $siteDir,
                'scanned' => false,
                'source' => self::SITE,
            ];
            self::refuse(
                $collect,
                $refusals,
                self::SITE,
                self::REFUSAL_SOURCE_NOT_IN_REPOSITORY,
                [$siteDir],
                "duo: site adapter source $siteDir resolves to "
                . ($resolvedSite === false ? '(unresolvable)' : $resolvedSite)
                . ', which is not ' . ($expectedSite !== '' ? $expectedSite : "this repository's own "
                    . self::SITE_DIR . ' directory')
                . ' — an out-of-tree adapter source must be a real directory inside the site repository, because '
                . 'every adapter it installs records a repo-relative provenance that travels with the repository. '
                . 'Replace the link with the directory itself',
                'replace the link with a real ' . self::SITE_DIR . '/ directory inside the site repository'
            );
            // Collect mode stops HERE rather than continuing, unlike every
            // other refusal below: the files behind this link are outside the
            // repository, so surveying them would report adapters under
            // repo-relative paths that no checkout of this repository holds.
            return;
        }
        $sources[] = [
            'note' => 'installed by the operator in the site repository, and travelling with it',
            'path' => $siteDir,
            'scanned' => true,
            'source' => self::SITE,
        ];

        // A site-local source that ships ratification data is asserting an
        // authority it does not have. These files/directories are inert (only
        // the agent's own manifest directory is ever read for them), and inert
        // is exactly the failure mode to refuse: an operator who wrote them
        // believes their adapter is certified, or believes their interpreter
        // will run. Say so instead of ignoring the bytes.
        foreach (['dispositions.json' => 'certification data', 'capabilities' => 'capability registry data'] as $entry => $what) {
            if (file_exists($siteDir . '/' . $entry)) {
                self::refuse(
                    $collect,
                    $refusals,
                    self::SITE,
                    self::REFUSAL_RESERVED_NAME,
                    [self::SITE_DIR . '/' . $entry],
                    "duo: site adapter source $siteDir contains $entry — a site-local adapter source cannot supply "
                    . "$what for itself. Certification is external review evidence held with the agent's own "
                    . 'manifest library; remove the file and treat these adapters as uncertified support',
                    "remove $entry from " . self::SITE_DIR
                        . '/ — these adapters are uncertified support and cannot ratify themselves'
                );
            }
        }
        // file_exists(), not is_dir(): a regular FILE named `interpreters` is
        // the same misunderstanding as a directory, and is_dir() would have
        // waved it through into the "silently ignored" bucket this whole block
        // exists to close.
        foreach (['interpreters', 'providers', 'regenerators'] as $codeDir) {
            if (file_exists($siteDir . '/' . $codeDir)) {
                self::refuse(
                    $collect,
                    $refusals,
                    self::SITE,
                    self::REFUSAL_RESERVED_NAME,
                    [self::SITE_DIR . '/' . $codeDir],
                    "duo: site adapter source $siteDir contains $codeDir, which the engine never loads — "
                    . 'out-of-tree adapters are data only. Install the adapter into the agent manifest library if it '
                    . 'genuinely needs to ship executable code, or declare a plugin-owned provider instead',
                    "remove $codeDir from " . self::SITE_DIR . '/, install the adapter into the agent manifest '
                        . 'library, or declare a plugin-owned provider instead'
                );
            }
        }
        self::assert_flat_json_source($siteDir, $collect, $refusals);

        $shippedNames = $declaredNames();
        $siteFiles = glob($siteDir . '/*.json') ?: [];
        // `dispositions.json` was already refused above as a reserved name, and
        // it is the one reserved entry this glob can also match. Reachable only
        // in collect mode — throw mode never gets past that refusal — but there
        // it mattered: the file went on to be judged AS AN ADAPTER and drew a
        // second, misleading `ambiguous_identity` row about a manifest nobody
        // ever claimed it was. One wrong file, one refusal. (The shipped glob
        // above skips the same name for the same reason.)
        $siteFiles = array_values(array_filter(
            $siteFiles,
            static fn(string $file): bool => basename($file, '.json') !== 'dispositions'
        ));
        sort($siteFiles, SORT_STRING);
        $certificateFiles = [];
        self::guarded(
            $collect,
            $refusals,
            self::SITE,
            self::REFUSAL_CERTIFICATION_SOURCE,
            [self::SITE_DIR . '/' . self::CERTIFICATION_DIR],
            'give every certificate an exact companion ' . self::SITE_DIR
                . '/<name>.json, and choose names that are distinct without relying on letter case',
            static function () use ($siteDir, $siteFiles, &$certificateFiles): void {
                $certificateFiles = self::certification_files($siteDir, $siteFiles);
            }
        );
        foreach ($siteFiles as $file) {
            $name = basename($file, '.json');
            $relative = self::SITE_DIR . '/' . basename($file);
            if (!self::guarded(
                $collect,
                $refusals,
                self::SITE,
                self::REFUSAL_INVALID_NAME,
                [$relative],
                'rename the file to a canonical lowercase ASCII slug',
                static fn() => self::assert_name($name, 'site adapter ' . self::render($relative))
            )) {
                continue;
            }
            // Symlinks are already refused for the whole directory by
            // assert_flat_json_source() above — deliberately there rather than
            // here, so a symlinked README or subdirectory is caught too, and
            // so nothing in this source is read before the check runs.
            $collision = $origins[$name] ?? null;
            if ($collision !== null) {
                self::refuse(
                    $collect,
                    $refusals,
                    self::SITE,
                    self::REFUSAL_SHADOWS_SHIPPED,
                    [$relative, (string) $collision['path']],
                    'duo: site adapter ' . self::render($relative) . " shadows the shipped adapter '$name' "
                    . "({$collision['file']}) — "
                    . 'an out-of-tree adapter overlays the shipped set, it never replaces a member of it. Rename the '
                    . 'site adapter, or remove it and pin the shipped adapter',
                    "rename the site adapter, or remove it and pin the shipped '$name'"
                );
                // Every per-file refusal below ends the same way in collect
                // mode: the file is not an installable adapter, so it gets a
                // refusal row and no adapter row, and the walk continues so
                // one broken file cannot hide the rest of the source.
                continue;
            }
            // TWO BEHAVIORAL DELTAS, stated rather than buried (DUO-3339).
            // Both live at this call, and every OTHER refusal message in this
            // scan is byte-identical to what discover() threw before.
            //
            // 1. An unreadable or unparseable site manifest used to propagate
            //    Canon's own bare exception out of discover() — "duo: cannot
            //    read <path>" or "duo: invalid JSON: <reason>", naming no
            //    adapter source. It is now wrapped so the message names the
            //    file AS a site adapter, which is what makes it reportable as
            //    a row beside the other refusals.
            // 2. A site manifest that is valid JSON but not an OBJECT (a
            //    number, string, boolean, or non-empty array) used to fall
            //    through to the declared-name check and refuse as
            //    `ambiguous_identity` — "declares name NULL but its file name
            //    is 'x'" — which described a manifest it was not. It now
            //    refuses here as `malformed_manifest` with its actual top-level
            //    type named. The shipped side had no refusal at all for this:
            //    Canon::decode() returns whatever the document was, so a
            //    scalar reached trust_tier(array $manifest) and killed the
            //    survey with a TypeError.
            $read = self::read_manifest($file);
            if ($read['stage'] !== 'ok') {
                self::refuse(
                    $collect,
                    $refusals,
                    self::SITE,
                    self::REFUSAL_MALFORMED_MANIFEST,
                    [$relative],
                    'duo: site adapter ' . self::render($relative) . ' cannot be read as a manifest: '
                    . $read['error'],
                    self::repair_advice($read['stage']) . ', or remove it from ' . self::SITE_DIR . '/'
                );
                continue;
            }
            $manifest = $read['manifest'];
            $declared = $manifest['name'] ?? null;
            if (!is_string($declared) || $declared !== $name) {
                self::refuse(
                    $collect,
                    $refusals,
                    self::SITE,
                    self::REFUSAL_AMBIGUOUS_IDENTITY,
                    [$relative],
                    self::ambiguous_identity_message(self::SITE, $relative, $declared, $name),
                    self::AMBIGUOUS_IDENTITY_REMEDY
                );
                continue;
            }
            if (isset($shippedNames[$name])) {
                self::refuse(
                    $collect,
                    $refusals,
                    self::SITE,
                    self::REFUSAL_NAME_COLLISION,
                    [$relative, self::SHIPPED . ':' . $shippedNames[$name]],
                    'duo: site adapter ' . self::render($relative) . " claims the name '$name', already "
                    . 'declared by the shipped manifest '
                    . "'{$shippedNames[$name]}' — two adapters cannot answer to one name",
                    "choose a name no shipped manifest declares, or pin the shipped '{$shippedNames[$name]}' instead"
                );
                continue;
            }
            // Case-insensitive too: a name that differs from a shipped one only
            // by case is one name on a case-insensitive filesystem and two on a
            // case-sensitive one, so the SAME repository would resolve
            // differently per host. That is ambiguous identity by any other
            // route, and it is refused by the same rule.
            // $origins already holds the site adapters accepted earlier in this
            // loop, so the colliding side is named from its own origin row
            // rather than assumed shipped — a site-vs-site collision reported
            // as a shipped one would send the operator to the wrong directory.
            $folded = self::casefold($name);
            $caseCollision = false;
            foreach ($origins as $otherName => $otherOrigin) {
                if ((string) $otherName !== $name && self::casefold((string) $otherName) === $folded) {
                    self::refuse(
                        $collect,
                        $refusals,
                        self::SITE,
                        self::REFUSAL_CASE_COLLISION,
                        [$relative, (string) $otherOrigin['path']],
                        "duo: site adapter '$relative' claims the name " . self::render($name)
                        . ', which differs only by letter case from the ' . $otherOrigin['source'] . ' adapter '
                        . self::render((string) $otherName) . " ({$otherOrigin['path']}) — one name on a "
                        . 'case-insensitive filesystem, two on a case-sensitive one. Choose a name that is '
                        . 'distinct without relying on case',
                        'choose a name that is distinct without relying on letter case'
                    );
                    $caseCollision = true;
                    break;
                }
            }
            if ($caseCollision) {
                continue;
            }
            foreach ($shippedNames as $declaredName => $declaringFile) {
                if ((string) $declaredName !== $name && self::casefold((string) $declaredName) === $folded) {
                    self::refuse(
                        $collect,
                        $refusals,
                        self::SITE,
                        self::REFUSAL_CASE_COLLISION,
                        [$relative, self::SHIPPED . ':' . $declaringFile],
                        "duo: site adapter '$relative' claims the name " . self::render($name)
                        . ', which differs only by letter case from the name ' . self::render((string) $declaredName)
                        . " declared by the shipped manifest '$declaringFile' — one name on a case-insensitive "
                        . 'filesystem, two on a case-sensitive one. Choose a name that is distinct without '
                        . 'relying on case',
                        'choose a name that is distinct without relying on letter case'
                    );
                    $caseCollision = true;
                    break;
                }
            }
            if ($caseCollision) {
                continue;
            }
            // Prove the data-only boundary before spending any authority on a
            // companion certificate. Policy repeats this after loading the
            // pinned manifest as defense in depth and frozen reconstruction
            // runs the same check.
            if (!self::guarded(
                $collect,
                $refusals,
                self::SITE,
                self::REFUSAL_OUT_OF_TREE_PRIVILEGE,
                [$relative],
                "install this adapter into the agent's own manifest library, or declare a plugin-owned provider "
                    . 'instead — an out-of-tree manifest is data and acquires no executable privileges',
                static fn() => self::assert_out_of_tree_contract($manifest, $name, $relative)
            )) {
                continue;
            }
            $origins[$name] = ['source' => self::SITE, 'file' => $file, 'path' => $relative];
            if ($collect) {
                $manifests[$name] = $manifest;
            }
            $certificateFile = $certificateFiles[$name] ?? null;
            if ($certificateFile === null) {
                $provenance[$name] = self::provenance_record($name, $relative, $manifest);
                continue;
            }
            // Keep the optional certification layer out of the ordinary
            // unsigned-source loader graph. AdapterCertification depends on
            // the source contract and capability/disposition validators, so
            // loading it at file scope would form a circular bootstrap and
            // make otherwise independent offline entry points order-sensitive.
            require_once __DIR__ . '/AdapterCertification.php';
            $verified = null;
            if (!self::guarded(
                $collect,
                $refusals,
                self::SITE,
                self::REFUSAL_CERTIFICATE_INVALID,
                [self::SITE_DIR . '/' . self::CERTIFICATION_DIR . "/$name.json", $relative],
                'obtain a certificate signed by an authority this agent trusts, or remove the companion and keep '
                    . 'the adapter as uncertified support',
                static function () use ($manifestDir, $repo, $name, $manifest, $certificateFile, &$verified): void {
                    $verified = AdapterCertification::verifyFile(
                        $manifestDir,
                        $repo,
                        $name,
                        $manifest,
                        $certificateFile
                    );
                }
            )) {
                // A certificate that does not verify is an authority claim the
                // engine refuses, and discover() refuses the whole source over
                // it. Collect mode drops the adapter for the same reason every
                // other per-file refusal does: reporting it as installed beside
                // a row saying it is refused would be two answers to one
                // question. The refusal row carries the verifier's own message.
                unset($origins[$name], $manifests[$name]);
                continue;
            }
            $provenance[$name] = $verified['disposition'];
            $certificates[$name] = $verified['envelope'];
            $claims[$name] = $verified['claim'];
        }
    }

    /**
     * The live plugin source, or null when this process is not a WordPress one.
     *
     * Read straight through the two guards rather than through
     * Deploy::plugin_runtime_state(): AdapterSources is on the pure loader
     * path that Policy::load() and every offline entry point walk, and pulling
     * Deploy into that graph would make otherwise independent entry points
     * order-sensitive — the same argument the lazy AdapterCertification
     * require in the site loop above makes for the certification layer.
     * Deploy::current_active_plugins() is the sibling primitive and reads the
     * identical option; if the lifecycle half ever needs more than the option
     * (activation hooks, per-network state), that is where it belongs, not
     * here.
     *
     * WP_PLUGIN_DIR is a define(), so a process cannot be made to see two
     * plugin directories: multi-fixture testing of this source is child
     * processes by construction, which is what the DUO-3339 suite does.
     *
     * @return ?array{active:list<string>, dir:string}
     */
    private static function plugin_source(): ?array {
        // Both WordPress reads are guarded AT THE CALL rather than only by the
        // early return above them. This file is on the pure loader path that
        // the WordPress-free host commands walk, and this project checks that
        // property by scanning for an unguarded reach line by line — a
        // reviewer reads it the same way. A guard they cannot see beside the
        // call is a guard that does not count.
        if (!defined('WP_PLUGIN_DIR') || !function_exists('get_option')) {
            return null;
        }
        $dir = defined('WP_PLUGIN_DIR') ? rtrim((string) WP_PLUGIN_DIR, '/') : '';
        if ($dir === '' || !is_dir($dir)) {
            return null;
        }
        $active = [];
        $raw = function_exists('get_option') ? get_option('active_plugins') : [];
        foreach ((array) $raw as $basename) {
            if (is_string($basename) && $basename !== '') {
                $active[] = $basename;
            }
        }
        sort($active, SORT_STRING);
        return ['active' => array_values(array_unique($active)), 'dir' => $dir];
    }

    /**
     * The plugin half of the scan: `<plugin-dir>/duo-adapter.json`, bundled by
     * an ACTIVE plugin.
     *
     * Two rules govern every line below, both argued in this class's header:
     *
     *   1. Nothing here throws. Every condition calls the SAME refuse()/
     *      guarded() helpers the site block calls, with $collect forced true,
     *      so one message exists per condition and discover() records it
     *      instead of dying on third-party content the operator did not
     *      author. file() is where a refusal becomes fatal, and only for a
     *      name somebody actually pinned.
     *   2. Precedence is the walk order. This runs last, so $origins already
     *      holds every shipped and site definition; a bundled name that
     *      collides with one is reported as `not_installed`, never refused.
     *
     * ACTIVE is the gate because activation is the operator's consent: it is
     * the same gate CapabilityRegistry's `plugin_not_active` blocker and
     * Providers' negotiation already use for a plugin's code. survey() (collect
     * mode) additionally lists installed-but-inactive bundles, so the adapter
     * waiting behind an activation is visible rather than absent.
     *
     * @param callable():array<string,string> $declaredNames memoized shipped declared names
     * @param list<array<string,mixed>> $refusals
     */
    private static function scan_plugin_source(
        bool $collect,
        callable $declaredNames,
        array &$refusals,
        array &$origins,
        array &$manifests,
        array &$provenance,
        array &$notInstalled,
        array &$refusedNames,
        array &$sources
    ): void {
        $source = self::plugin_source();
        if ($source === null) {
            $sources[] = [
                'note' => 'plugin source not scanned (no WP_PLUGIN_DIR in this process) — a bundled adapter is '
                    . 'discoverable only on the target itself; run `wp duo adapter-survey` there',
                'path' => null,
                'scanned' => false,
                'source' => self::PLUGIN,
            ];
            return;
        }
        $dir = $source['dir'];
        $sources[] = [
            'note' => $collect
                ? 'bundled by installed plugins; every ACTIVE plugin was scanned for one '
                    . self::PLUGIN_FILE . ', and inactive installations are listed as not installed'
                : 'bundled by ACTIVE plugins; one ' . self::PLUGIN_FILE . ' at the root of each plugin that owns it',
            'path' => $dir,
            'scanned' => true,
            'source' => self::PLUGIN,
        ];

        // A duo-adapter.json at the plugins directory ROOT belongs to no
        // plugin: there is no owning basename to anchor it to, no version to
        // bound it by, and no activation that consented to it. Single-file
        // plugins land in the same place and are refused for the same reason —
        // exactly the "strongest true statement available" problem
        // Providers::plugin_anchor_problem() names for a plugin with no
        // directory of its own.
        if (is_file($dir . '/' . self::PLUGIN_FILE)) {
            self::refuse(
                true,
                $refusals,
                self::PLUGIN,
                self::REFUSAL_RESERVED_NAME,
                [self::PLUGIN_PATH_PREFIX . '/' . self::PLUGIN_FILE],
                'duo: the plugins directory itself contains ' . self::PLUGIN_PATH_PREFIX . '/' . self::PLUGIN_FILE
                . ' — a bundled adapter is exactly one ' . self::PLUGIN_FILE . ' at the root of the plugin that '
                . 'owns it, and a file at the plugins root is owned by no plugin, anchored to no basename, and '
                . 'bounded by no plugin version. A single-file plugin has no directory of its own and cannot '
                . 'bundle an adapter for the same reason',
                'move it into the owning plugin\'s own directory as <plugin-dir>/' . self::PLUGIN_FILE
                    . ', or install it as a site adapter at ' . self::SITE_DIR . '/<name>.json'
            );
        }

        // Pass one: judge each candidate on its own. Nothing is installed
        // here, because a name is not resolvable until every candidate that
        // could claim it has been read — a plugin-vs-plugin collision has no
        // precedence to fall back on and both sides have to lose.
        $candidates = [];
        foreach (self::plugin_candidates($source, $collect, $refusals) as $candidate) {
            $sub = $candidate['dir'];
            $relative = self::PLUGIN_PATH_PREFIX . "/$sub/" . self::PLUGIN_FILE;
            // Containment is proved BEFORE any read, on both paths. The
            // inactive path used to read first, which meant a bundle symlinked
            // out of WP_PLUGIN_DIR was opened, its declared name lifted into a
            // reported row, and no refusal raised — a file outside the plugin
            // directory reported under that plugin's name, and read in full by
            // a path with no size ceiling. "Not installed" is not a licence to
            // open arbitrary bytes.
            if (!self::plugin_bundle_contained($candidate, $relative, $refusals)) {
                continue;
            }
            if (!$candidate['active']) {
                // Not a refusal: the bundle is well-formed as far as anybody
                // knows, and the operator simply has not activated its plugin.
                // Reported only in collect mode — discover() has no business
                // reading a manifest whose plugin is not running.
                $read = self::read_manifest($candidate['file']);
                $declared = $read['stage'] === 'ok' ? ($read['manifest']['name'] ?? null) : null;
                $notInstalled[] = [
                    'message' => 'adapter ' . (is_string($declared) ? self::render($declared) . ' ' : '')
                        . 'is bundled at ' . self::render($relative) . ', but the plugin '
                        . self::render($candidate['plugin'])
                        . ' that owns it is not active — activation is the consent that installs a bundled adapter'
                        . (is_string($declared) ? '' : ', and this bundle\'s declared name could not be read'),
                    'name' => is_string($declared) ? $declared : null,
                    'path' => $relative,
                    'plugin' => $candidate['plugin'],
                    'reason_code' => self::NOT_INSTALLED_PLUGIN_INACTIVE,
                    'source' => self::PLUGIN,
                    'winner' => null,
                ];
                continue;
            }
            $anchored = null;
            $manifest = self::plugin_candidate_manifest(
                $candidate,
                $relative,
                $refusals,
                $refusedNames,
                $anchored
            );
            if ($manifest === null) {
                continue;
            }
            $name = (string) $manifest['name'];
            $candidates[$name][] = [
                // The basename the anchor rule PROVED this manifest names, not
                // whichever of the directory's active plugins sorted first.
                // The distinction is identity-bearing: this string enters the
                // §5 provenance reason, the reason enters the disposition, and
                // the disposition enters the adapter digest a pin binds. With
                // the first-sorted basename, a directory running two plugins
                // gave the adapter a digest that depended on which SIBLING was
                // active — so deactivating an unrelated plugin silently moved
                // the adapter's identity and the pin failed with a digest
                // mismatch pointing at nothing the operator had touched.
                'anchored' => $anchored,
                'file' => $candidate['file'],
                'manifest' => $manifest,
                'path' => $relative,
                'plugin' => $candidate['plugin'],
            ];
        }

        // Pass two: resolve each name against everything that outranks it.
        ksort($candidates, SORT_STRING);
        foreach ($candidates as $name => $claimants) {
            $name = (string) $name;
            if (count($claimants) > 1) {
                $paths = array_column($claimants, 'path');
                sort($paths, SORT_STRING);
                $plugins = array_column($claimants, 'plugin');
                sort($plugins, SORT_STRING);
                $refusedNames[$name] = self::refuse(
                    true,
                    $refusals,
                    self::PLUGIN,
                    self::REFUSAL_SOURCE_COLLISION,
                    $paths,
                    'duo: the active plugins ' . implode(' and ', array_map(
                        static fn(string $p): string => self::render($p),
                        $plugins
                    )) . " each bundle an adapter named '$name' (" . implode(', ', array_map(
                        static fn(string $path): string => self::render($path),
                        $paths
                    )) . ') — precedence '
                    . 'ranks adapter SOURCES, and these are the same source, so there is no rule that could '
                    . 'decide which definition this site runs. Neither is installed',
                    'deactivate one of the colliding plugins, or install the definition this site intends to run '
                    . 'as a site adapter at ' . self::SITE_DIR . "/$name.json, which outranks both"
                );
                continue;
            }
            $claimant = $claimants[0];
            $winner = $origins[$name] ?? null;
            $shippedNames = $declaredNames();
            if ($winner === null && isset($shippedNames[$name])) {
                $winner = [
                    'path' => $shippedNames[$name],
                    'source' => self::SHIPPED,
                ];
            }
            if ($winner !== null) {
                $notInstalled[] = [
                    'message' => "adapter '$name' is bundled at {$claimant['path']} by the active plugin "
                        . self::render((string) $claimant['plugin'])
                        . ", and the {$winner['source']} adapter source already answers to "
                        . "that name ({$winner['path']}) — adapter sources rank shipped, then site, then plugin, so "
                        . 'the reviewed definition wins and the bundled one is not loaded. Nothing about the plugin '
                        . 'changes: it stays active and its own code keeps running',
                    'name' => $name,
                    'path' => (string) $claimant['path'],
                    'plugin' => (string) $claimant['plugin'],
                    'reason_code' => self::NOT_INSTALLED_SHADOWED,
                    'source' => self::PLUGIN,
                    'winner' => [
                        'path' => (string) $winner['path'],
                        'source' => (string) $winner['source'],
                    ],
                ];
                continue;
            }
            // Defense in depth, and deliberately unreachable through a
            // declared name today: assert_name() forbids uppercase and
            // non-ASCII, so two canonical slugs cannot case-fold onto each
            // other. Kept for the same reason the site loop keeps its copy —
            // the day the identity grammar widens, this is the check that
            // notices.
            $folded = self::casefold($name);
            $confusable = null;
            foreach ($origins as $otherName => $otherOrigin) {
                if ((string) $otherName !== $name && self::casefold((string) $otherName) === $folded) {
                    $confusable = ['path' => (string) $otherOrigin['path'], 'source' => (string) $otherOrigin['source']];
                    break;
                }
            }
            if ($confusable === null) {
                foreach ($declaredNames() as $declaredName => $declaringFile) {
                    if ((string) $declaredName !== $name && self::casefold((string) $declaredName) === $folded) {
                        $confusable = ['path' => (string) $declaringFile, 'source' => self::SHIPPED];
                        break;
                    }
                }
            }
            if ($confusable !== null) {
                $refusedNames[$name] = self::refuse(
                    true,
                    $refusals,
                    self::PLUGIN,
                    self::REFUSAL_CASE_COLLISION,
                    [(string) $claimant['path'], (string) $confusable['path']],
                    'duo: plugin adapter ' . self::render((string) $claimant['path']) . ' claims the name '
                    . self::render($name)
                    . ', which differs only by letter case from the ' . $confusable['source'] . ' adapter ('
                    . $confusable['path'] . ') — one name on a case-insensitive filesystem, two on a '
                    . 'case-sensitive one. Choose a name that is distinct without relying on case',
                    'choose a name that is distinct without relying on letter case'
                );
                continue;
            }
            $origins[$name] = [
                'source' => self::PLUGIN,
                'file' => (string) $claimant['file'],
                'path' => (string) $claimant['path'],
            ];
            if ($collect) {
                $manifests[$name] = $claimant['manifest'];
            }
            $provenance[$name] = self::provenance_record(
                $name,
                (string) $claimant['path'],
                $claimant['manifest'],
                self::PLUGIN,
                (string) $claimant['anchored']
            );
        }
    }

    /**
     * Every plugin directory this scan will look inside, with whether its
     * plugin is active.
     *
     * discover() sees ACTIVE plugins only. survey() additionally walks the
     * plugins directory itself, because "installed, waiting for an
     * activation" is a state an operator has to be able to see — it is the
     * third documented divergence between the two modes, and the only one
     * that ADDS I/O rather than changing what happens to a finding.
     *
     * `plugins` carries EVERY active basename in that one directory, not just
     * the first. A plugin directory holding two separately activated plugin
     * files is unusual but legal, and the anchor rule compares the manifest's
     * `plugin` claim against an exact basename — so anchoring to whichever one
     * happened to sort first would refuse a manifest that correctly named its
     * owner. The frozen provenance path is unaffected either way: all of them
     * share the directory the path is derived from.
     *
     * @param array{active:list<string>, dir:string} $source
     * @param list<array<string,mixed>> $refusals
     * @return list<array{active:bool, dir:string, file:string, plugin:string, plugins:list<string>}>
     */
    private static function plugin_candidates(array $source, bool $collect, array &$refusals): array {
        $dir = $source['dir'];
        $rows = [];
        foreach ($source['active'] as $basename) {
            $sub = dirname($basename);
            // A single-file plugin has no directory of its own, so there is no
            // place for it to hold a bundle; the plugins-root refusal above
            // covers the file it would have to use instead. `..` is excluded
            // explicitly: a poisoned `active_plugins` row must not be able to
            // walk this scan out of the plugins directory. The anchor rule
            // would refuse whatever it found there anyway — this is the
            // cheaper, earlier half of the same fail-closed answer, taken
            // before any I/O rather than after it.
            if ($sub === '.' || $sub === '..' || $sub === '' || $sub === '/' || str_contains($sub, '/')) {
                continue;
            }
            if (isset($rows[$sub])) {
                $rows[$sub]['plugins'][] = $basename;
                continue;
            }
            $file = $dir . '/' . $sub . '/' . self::PLUGIN_FILE;
            if (!self::bundle_present($file)) {
                continue;
            }
            $rows[$sub] = [
                'active' => true,
                'dir' => $sub,
                'file' => $file,
                'plugin' => $basename,
                'plugins' => [$basename],
            ];
        }
        if ($collect) {
            $installed = self::entries($dir);
            if ($installed === null) {
                // Scoped to `adapter` like every other row in this source,
                // even though it is about a directory: marking it `source`
                // would blank out every SITE adapter's grammar verdict and
                // attribute an unloadable pin set to the plugin source, which
                // is the whole-scan blast radius this source does not have.
                self::refuse(
                    true,
                    $refusals,
                    self::PLUGIN,
                    self::REFUSAL_SOURCE_UNREADABLE,
                    [self::PLUGIN_PATH_PREFIX . '/'],
                    'duo: the plugins directory ' . self::render($dir) . ' cannot be enumerated (it is not '
                    . 'readable by the user running duo), so installed-but-inactive bundles could not be listed. '
                    . 'Every ACTIVE plugin was still scanned by name',
                    'make the plugins directory readable by the user running duo, or ignore this if the '
                    . 'inactive listing is not wanted'
                );
            }
            foreach ($installed ?? [] as $entry) {
                if (isset($rows[$entry]) || !is_dir($dir . '/' . $entry)) {
                    continue;
                }
                $file = $dir . '/' . $entry . '/' . self::PLUGIN_FILE;
                if (!self::bundle_present($file)) {
                    continue;
                }
                $rows[$entry] = [
                    'active' => false,
                    'dir' => $entry,
                    'file' => $file,
                    'plugin' => $entry,
                    'plugins' => [],
                ];
            }
        }
        ksort($rows, SORT_STRING);
        return array_values($rows);
    }

    /**
     * Whether `<plugin>/duo-adapter.json` exists as something this scan has an
     * opinion about.
     *
     * `is_link()` is included so a symlinked bundle becomes a CANDIDATE and is
     * then refused by name, rather than being skipped as absent — the refusal
     * is the point. A DIRECTORY under that name is included for the same
     * reason: `is_file()` alone silently passed it over, so a plugin whose
     * author made `duo-adapter.json` a directory installed nothing and was
     * told nothing, which is exactly the silence this source refuses.
     */
    private static function bundle_present(string $file): bool {
        return is_file($file) || is_link($file) || is_dir($file);
    }

    /**
     * Prove that `<plugin>/duo-adapter.json` is a real, regular file inside the
     * plugin that owns it — before anything reads a byte of it.
     *
     * A symlinked PLUGIN DIRECTORY is accepted: development checkouts symlink
     * plugin directories as a matter of course, and both sides are resolved
     * exactly as Providers::plugin_anchor_problem() resolves them. A symlinked
     * or otherwise escaping `duo-adapter.json` is not, because the bundle would
     * then be authored somewhere the plugin's own version, update, and review
     * story does not reach.
     *
     * Called on the inactive path too. "Not installed" describes what the
     * engine will LOAD; it says nothing about what this scan may OPEN, and
     * opening a file that resolves outside WP_PLUGIN_DIR is the same mistake
     * whether or not its plugin happens to be running.
     *
     * @param array{active:bool, dir:string, file:string, plugin:string} $candidate
     * @param list<array<string,mixed>> $refusals
     */
    private static function plugin_bundle_contained(
        array $candidate,
        string $relative,
        array &$refusals
    ): bool {
        $file = $candidate['file'];
        $pluginDir = dirname($file);
        $real = is_link($file) ? false : realpath($file);
        $realDir = realpath($pluginDir);
        if (is_link($file) || !is_file($file) || $real === false || $realDir === false
            || !str_starts_with($real, rtrim($realDir, '/') . '/')) {
            self::refuse(
                true,
                $refusals,
                self::PLUGIN,
                self::REFUSAL_SYMLINK_SOURCE,
                [$relative],
                'duo: the plugin ' . self::render($candidate['plugin']) . ' bundles '
                . self::render($relative) . ' as '
                . (is_link($file)
                    ? 'a symbolic link (-> ' . self::render(readlink($file) ?: '?') . ')'
                    : (is_dir($file)
                        ? 'a directory'
                        : 'a path that does not resolve to a regular file inside the plugin directory'))
                . ' — a bundled adapter is a real file inside the plugin that owns it, so that what the engine '
                . "loads is what that plugin's own version, update, and review story covers",
                'replace it with the real file inside the plugin directory, or install the adapter as a site '
                    . 'adapter at ' . self::SITE_DIR . '/<name>.json'
            );
            return false;
        }
        return true;
    }

    /**
     * One active plugin's bundle, judged and decoded — or null with a refusal
     * row already recorded.
     *
     * The near-miss sweep runs FIRST and only inside this project's own
     * `duo-` namespace. A plugin root is third-party territory: refusing a
     * plugin over a file whose name this project never claimed would be the
     * exact overreach the per-adapter refusal scope exists to avoid, while a
     * `duo-adapters.json` or a `duo-adapter.JSON` is unambiguously an attempt
     * to bundle an adapter that the engine would otherwise silently never
     * load.
     *
     * @param array{active:bool, dir:string, file:string, plugin:string, plugins:list<string>} $candidate
     * @param list<array<string,mixed>> $refusals
     * @param ?string $anchored set to the ACTIVE plugin basename this manifest
     *        proved it belongs to — the caller needs the proven one, not a
     *        guess, because it is identity-bearing
     * @return ?array<string,mixed> the decoded manifest, with a validated `name`
     */
    private static function plugin_candidate_manifest(
        array $candidate,
        string $relative,
        array &$refusals,
        array &$refusedNames,
        ?string &$anchored = null
    ): ?array {
        $pluginDir = dirname($candidate['file']);
        $ok = true;
        // The near-miss set is EVIDENCE, not decoration: it is what proves this
        // plugin bundles exactly one duo-adapter.json. A directory that cannot
        // be enumerated has not produced that proof, so the adapter is refused
        // rather than installed on the strength of the one file that happened
        // to be openable through search permission.
        $entries = self::entries($pluginDir);
        if ($entries === null) {
            self::refuse(
                true,
                $refusals,
                self::PLUGIN,
                self::REFUSAL_SOURCE_UNREADABLE,
                [$relative],
                'duo: the plugin directory ' . self::render(self::PLUGIN_PATH_PREFIX . '/' . $candidate['dir'])
                . ' cannot be enumerated (it is not readable by the user running duo), so this scan cannot prove '
                . 'the plugin bundles exactly one ' . self::PLUGIN_FILE . ' — a directory that is searchable but '
                . 'not readable can hide a second, near-miss bundle beside the one file that opens. Installing on '
                . 'that basis would assert something nobody checked',
                'make the plugin directory readable by the user running duo, or install the adapter as a site '
                    . 'adapter at ' . self::SITE_DIR . '/<name>.json'
            );
            return null;
        }
        foreach ($entries as $entry) {
            $folded = self::casefold($entry);
            if ($entry === self::PLUGIN_FILE || !str_starts_with($folded, 'duo-adapter')) {
                continue;
            }
            $entryPath = self::PLUGIN_PATH_PREFIX . '/' . $candidate['dir'] . '/' . $entry;
            if (str_contains($folded, 'certif')) {
                // The one place a plugin author can express "and here is its
                // certificate", which is the belief §5 exists to correct: a
                // bundled adapter cannot be certified in place at all.
                self::refuse(
                    true,
                    $refusals,
                    self::PLUGIN,
                    self::REFUSAL_CERTIFICATION_SOURCE,
                    [$entryPath],
                    'duo: the plugin ' . self::render($candidate['plugin']) . ' bundles '
                    . self::render($entry) . ' beside its adapter — a '
                    . 'plugin-bundled adapter cannot carry its own certification. Certification is a '
                    . 'repository-scoped signed companion at ' . self::SITE_DIR . '/' . self::CERTIFICATION_DIR
                    . '/<name>.json, verified against ' . self::SITE_DIR . '/<name>.json, and the signed statement '
                    . 'binds the site source and that exact path',
                    'remove the file; to certify this adapter, install it as a site adapter at ' . self::SITE_DIR
                        . '/<name>.json and obtain a signed companion under ' . self::SITE_DIR . '/'
                        . self::CERTIFICATION_DIR . '/'
                );
                $ok = false;
                continue;
            }
            // An extension that is `.json` only when letter case is ignored
            // loads on a case-insensitive filesystem and vanishes on a
            // case-sensitive one, so the SAME plugin would install an adapter
            // on one host and nothing on another. Judged before the reserved
            // -name catch-all because that per-host divergence is the specific
            // thing an author has to be told.
            if (str_ends_with($folded, '.json') && !str_ends_with($entry, '.json')) {
                self::refuse(
                    true,
                    $refusals,
                    self::PLUGIN,
                    self::REFUSAL_EXTENSION_CASE_MISMATCH,
                    [$entryPath],
                    'duo: the plugin ' . self::render($candidate['plugin']) . ' bundles '
                    . self::render($entry) . ", whose extension is not exactly '.json' — it would load on a "
                    . 'case-insensitive filesystem and disappear on a case-sensitive one, so the same plugin '
                    . 'would install an adapter on one host and none on another. Rename it to use a lowercase '
                    . '.json extension',
                    'rename it to use a lowercase .json extension, or remove it from the plugin'
                );
                $ok = false;
                continue;
            }
            self::refuse(
                true,
                $refusals,
                self::PLUGIN,
                self::REFUSAL_RESERVED_NAME,
                [$entryPath],
                'duo: the plugin ' . self::render($candidate['plugin']) . ' bundles ' . self::render($entry)
                . ", which is in this engine's reserved "
                . '`duo-adapter` namespace but is not the one file a plugin may bundle. A plugin installs exactly '
                . 'one adapter, from exactly one ' . self::PLUGIN_FILE . ' at its own root, so anything else in '
                . 'that namespace is bytes the engine will never read',
                'remove it, or make it the single ' . self::PLUGIN_FILE . ' this plugin bundles'
            );
            $ok = false;
        }
        if (!$ok) {
            return null;
        }

        $read = self::read_manifest($candidate['file']);
        if ($read['stage'] !== 'ok') {
            self::refuse(
                true,
                $refusals,
                self::PLUGIN,
                self::REFUSAL_MALFORMED_MANIFEST,
                [$relative],
                'duo: plugin adapter ' . self::render($relative) . ' cannot be read as a manifest: '
                . $read['error'],
                self::repair_advice($read['stage']) . ', or remove it from the plugin'
            );
            return null;
        }
        $manifest = $read['manifest'];

        // Identity INVERTS the site rule. The file name is a constant here, so
        // it can carry no identity at all; the declared name is the only thing
        // that can. `dispositions` is refused with the rest of the grammar
        // because it is the one name the shipped library reserves for
        // certification data rather than for an adapter.
        $declared = $manifest['name'] ?? null;
        if (!self::guarded(
            true,
            $refusals,
            self::PLUGIN,
            self::REFUSAL_INVALID_NAME,
            [$relative],
            'declare a canonical lowercase ASCII slug as this manifest\'s `name`',
            static function () use ($declared, $relative): void {
                if (!is_string($declared)) {
                    throw new \RuntimeException(
                        'duo: plugin adapter ' . self::render($relative) . ' declares name '
                        . self::render($declared)
                        . ' — a bundled adapter is named by its manifest, never by its file name (every bundle is '
                        . 'called ' . self::PLUGIN_FILE . '), so a missing or non-string `name` leaves it with no '
                        . 'identity at all'
                    );
                }
                if ($declared === 'dispositions') {
                    throw new \RuntimeException(
                        'duo: plugin adapter ' . self::render($relative) . ' declares the reserved name '
                        . "'dispositions', which names the shipped library's reviewed certification data "
                        . 'rather than an adapter'
                    );
                }
                self::assert_name($declared, 'plugin adapter ' . self::render($relative) . ' declared name');
            }
        )) {
            return null;
        }

        // The anchor rule, load-bearing twice: it is the exact analogue of
        // Providers::plugin_anchor_problem() for a data manifest, and it is
        // what makes the frozen provenance path re-derivable from the manifest
        // alone, with no new wire key (validate_frozen_record()). Policy
        // already refuses a providers[].plugin that disagrees with the
        // manifest's own `plugin`, so binding this one claim closes the
        // provider story for this source for free.
        $anchorRow = null;
        if (!self::guarded(
            true,
            $refusals,
            self::PLUGIN,
            self::REFUSAL_PLUGIN_ANCHOR,
            [$relative],
            'declare `plugin`: ' . self::render($candidate['plugin']) . ' in the bundled manifest',
            static function () use ($manifest, $candidate, $relative, &$anchored): void {
                if (!array_key_exists('plugin', $manifest)) {
                    throw new \RuntimeException(
                        'duo: plugin adapter ' . self::render($relative) . ' declares no `plugin` — a bundled '
                        . 'adapter must name the '
                        . 'plugin that owns it (' . self::render($candidate['plugin']) . '), because that claim '
                        . 'is the only thing anchoring the manifest to the code it ships with, and it is what a '
                        . 'frozen policy rebuilds this adapter\'s provenance path from'
                    );
                }
                $anchored = self::assert_plugin_basename(
                    $manifest['plugin'],
                    'plugin adapter ' . self::render($relative) . ' plugin'
                );
                // EXACT basename equality, not merely the same directory. The
                // claim is what every version check reads: a manifest in
                // plugins/acme/ declaring `acme/other.php` while `acme/acme.php`
                // is the plugin that was activated would have its
                // version_range, plugin_not_active, and plugin_version_mismatch
                // verdicts evaluated against a plugin file nobody turned on —
                // a true-looking compatibility answer about the wrong code.
                // Compared against every ACTIVE basename in that directory,
                // because a directory holding two separately activated plugin
                // files has two correct answers and only one of them sorts
                // first.
                if (!in_array($anchored, $candidate['plugins'], true)) {
                    throw new \RuntimeException(
                        'duo: plugin adapter ' . self::render($relative) . ' declares plugin '
                        . self::render($anchored)
                        . ', but it is bundled by ' . implode(' / ', array_map(
                            static fn(string $p): string => self::render($p),
                            $candidate['plugins']
                        )) . ' — a bundled adapter names the exact plugin file that owns it, never another one, '
                        . 'or every version and activation verdict about it would be answered against code no '
                        . 'update to this plugin can change'
                    );
                }
            },
            $anchorRow
        )) {
            $refusedNames[(string) $declared] = $anchorRow;
            return null;
        }

        $privilegeRow = null;
        if (!self::guarded(
            true,
            $refusals,
            self::PLUGIN,
            self::REFUSAL_OUT_OF_TREE_PRIVILEGE,
            [$relative],
            "install this adapter into the agent's own manifest library, or declare a plugin-owned provider "
                . 'instead — an out-of-tree manifest is data and acquires no executable privileges',
            static fn() => self::assert_out_of_tree_contract(
                $manifest,
                (string) $declared,
                $relative,
                'plugin adapter',
                true
            ),
            $privilegeRow
        )) {
            $refusedNames[(string) $declared] = $privilegeRow;
            return null;
        }
        return $manifest;
    }

    /**
     * Read one manifest file, distinguishing the three separate ways it can
     * fail to BE one.
     *
     * They are kept apart because they take three different actions, and a
     * single "repair the file so it decodes as JSON" would be actively wrong
     * advice for two of them:
     *
     *   - `read`   — the bytes could not be obtained at all (permissions, a
     *                dangling entry). Nothing is wrong with the JSON; nobody
     *                has seen it.
     *   - `decode` — the bytes are not JSON.
     *   - `shape`  — the bytes are valid JSON that is not a manifest: a
     *                number, a string, a boolean, or a non-empty array. This
     *                one is not cosmetic. `Canon::decode()` returns whatever
     *                the document was, so a shipped `123.json` used to reach
     *                `trust_tier(array $manifest)` as an int and kill the
     *                whole survey with a TypeError — an inventory taken down
     *                by one of the files it exists to inventory. An empty
     *                `[]`/`{}` is deliberately NOT refused here: the two are
     *                indistinguishable after an associative decode, and an
     *                empty object is a legitimately EMPTY manifest whose
     *                verdict belongs to the grammar check, not to this scan.
     *
     * @return array{manifest:?array, stage:string, error:string}
     */
    private static function read_manifest(string $file): array {
        try {
            $raw = Canon::read_file($file);
        } catch (\Throwable $t) {
            return ['error' => self::unprefixed($t->getMessage()), 'manifest' => null, 'stage' => 'read'];
        }
        try {
            $decoded = Canon::decode($raw);
        } catch (\Throwable $t) {
            return ['error' => self::unprefixed($t->getMessage()), 'manifest' => null, 'stage' => 'decode'];
        }
        if (!is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
            return [
                'error' => 'its top level is ' . (is_array($decoded) ? 'a JSON array' : get_debug_type($decoded))
                    . ', and a manifest is a JSON object',
                'manifest' => null,
                'stage' => 'shape',
            ];
        }
        return ['error' => '', 'manifest' => $decoded, 'stage' => 'ok'];
    }

    /** The action each read_manifest() failure stage actually calls for. */
    private static function repair_advice(string $stage): string {
        return match ($stage) {
            'read' => 'make the file readable by the user running duo',
            'shape' => "replace the file's contents with a JSON object",
            default => 'repair the file so it parses as JSON',
        };
    }

    /**
     * Drop one leading `duo: ` from a nested engine message, so a wrapped
     * refusal does not read `duo: … : duo: …`. Only the prefix is touched;
     * the engine's own wording is never edited.
     */
    private static function unprefixed(string $message): string {
        return str_starts_with($message, 'duo: ') ? substr($message, strlen('duo: ')) : $message;
    }

    /**
     * Throw the refusal, or record it — the one place the two scan modes
     * differ about a condition they agree on completely.
     *
     * `message` is the exception discover() has always thrown, verbatim.
     * `remediation` is a separate, short field rather than more message text:
     * the existing messages already end with their own advice, and rewriting
     * one to split it would change a string this project's regressions pin
     * byte for byte.
     *
     * `$source` is the source the condition is ABOUT, and `scope` is derived
     * from it rather than passed: every plugin-source condition is
     * per-adapter and every shipped/site condition is whole-directory, which
     * is the whole content of Amendment B. Both fields are on the row because
     * two consumers need them and neither can recover them from the message —
     * grammar_verdict() must not let one plugin author's typo un-judge every
     * site adapter, and AdapterCatalog::blockers() used to sniff `paths` for
     * a leading `adapters/`, which a `plugins/...` path silently fell out of.
     *
     * The identity used for deduplication stays code+paths, deliberately: one
     * wrong FILE draws one row, and a file belongs to exactly one source, so
     * adding source to the key could only ever split a duplicate that is
     * already impossible.
     *
     * Returns the recorded row, so a caller that needs to remember WHICH
     * refusal it just made — file() answers a pin with that row's own message —
     * takes it from here rather than reaching back into `$refusals` by index.
     * Indexing was correct and silently fragile: it reads the last row, which
     * is a different row the moment a call site refuses twice or dedupe
     * suppresses the write.
     *
     * @param list<string> $paths every file or directory the condition is about
     * @return ?array<string,mixed> the row (existing one on dedupe); null in throw mode
     */
    private static function refuse(
        bool $collect,
        array &$refusals,
        string $source,
        string $code,
        array $paths,
        string $message,
        string $remediation
    ): ?array {
        if (!$collect) {
            throw new \RuntimeException($message);
        }
        $row = [
            'code' => $code,
            'message' => $message,
            'paths' => array_values($paths),
            'remediation' => $remediation,
            'scope' => $source === self::PLUGIN ? self::SCOPE_ADAPTER : self::SCOPE_SOURCE,
            'source' => $source,
        ];
        // One wrong file draws one refusal. Several checks legitimately cover
        // the same condition from different directions — the flat-source scan
        // validates every top-level `*.json` identity, and the adapter loop
        // validates the same identity again as defense in depth — and in throw
        // mode only the first of them is ever reached. Collect mode reaches
        // both, so identity is enforced here rather than by remembering at
        // each call site which earlier check already covered this file.
        foreach ($refusals as $existing) {
            if ($existing['code'] === $row['code'] && $existing['paths'] === $row['paths']) {
                return $existing;
            }
        }
        $refusals[] = $row;
        return $row;
    }

    /**
     * refuse(), for a condition that is expressed as a THROWING assertion
     * rather than as an `if`.
     *
     * DUO-3314's signed-certification pass added several of those to this scan
     * (canonical identity grammar, the out-of-tree privilege boundary, the
     * certification directory's own shape, and the signature verification
     * itself), each written as an `assert_*`/`verify*` call that throws. In
     * throw mode this re-throws the ORIGINAL exception object, so discover()
     * fails with the same type, the same message, and the same stack it always
     * did. In collect mode the same message becomes a row and the caller
     * decides what to skip — the identical bargain refuse() makes, reached
     * through a call instead of a branch.
     *
     * @param list<string> $paths
     * @param ?array<string,mixed> $row set to the recorded refusal row, for the
     *        same reason refuse() returns one
     * @return bool false when the assertion refused (collect mode only)
     */
    private static function guarded(
        bool $collect,
        array &$refusals,
        string $source,
        string $code,
        array $paths,
        string $remediation,
        callable $assert,
        ?array &$row = null
    ): bool {
        try {
            $assert();
            return true;
        } catch (\Throwable $t) {
            if (!$collect) {
                throw $t;
            }
            $row = self::refuse($collect, $refusals, $source, $code, $paths, $t->getMessage(), $remediation);
            return false;
        }
    }

    /**
     * Every installed adapter and every refused one, reported rather than
     * thrown (DUO-3339).
     *
     * This is the read side of discover(). It exists because the catalog and
     * doctor surfaces have to be able to SHOW the conditions discover()
     * refuses — a shadowed pair, an ambiguous identity, a confusable name —
     * and an operator whose repository is in one of those states is exactly
     * the operator who cannot run any other command to find out why. Both
     * modes walk the SAME scan (see scan()), so a refusal here is the engine's
     * own refusal with its own message, never a reporter's restatement of it.
     *
     * What it does NOT do, deliberately: it never loads manifest-shipped PHP.
     * A declared `interpreter` or `regen_dependency.regenerator` is REPORTED
     * (as an executable surface, and as the basis of the trust tier) but not
     * resolved, because resolving one means running its top level and its
     * constructor — the trust decision `duo manifest-validate` documents at
     * length and takes deliberately. An inventory of what is installed must
     * not be the command that executes it.
     *
     * The shipped library is Policy::manifests_dir() rather than a parameter,
     * matching every other product entry point into the catalog: one process
     * has one shipped library, and the grammar verdict below resolves it
     * through Policy::load() anyway, so a second directory here could only
     * ever describe adapters the verdict was not about.
     *
     * The PLUGIN source is scanned here too, and it is the one source this
     * process can only report on when it IS the target — a bundled adapter
     * lives in WP_PLUGIN_DIR, which a host-side CLI does not have. `sources`
     * says which of the three were actually reachable in this process, so a
     * consumer renders that fact instead of asserting it in prose; and
     * `not_installed` carries every adapter that is on this disk and did not
     * load, with the definition that outranked it.
     *
     * @param ?string $repo site repository whose adapters/ source also counts
     * @return array{adapters:list<array<string,mixed>>, not_installed:list<array<string,mixed>>, refusals:list<array<string,mixed>>, sources:list<array<string,mixed>>}
     */
    public static function survey(?string $repo): array {
        $manifestDir = Policy::manifests_dir();
        $refusals = [];
        $scan = self::scan($manifestDir, $repo, true, $refusals);

        // The site's OWN policy file, checked once, here rather than in scan()
        // — discover() never reads it (Policy::load() opens it first and
        // throws its own message), so adding this to the shared scan would
        // change discover()'s behavior for a fact discover() has no opinion
        // about.
        //
        // Checked at all because every grammar verdict below loads that file:
        // one unparseable site.duo.json would otherwise produce one identical,
        // unattributed refusal on EVERY shipped row and no refusal row at all,
        // which reads as "all fifteen of your adapters are broken" for a
        // single misplaced comma in a file none of them is. Recorded as one
        // refusal that names the file, after which the fallback below judges
        // shipped adapters without the site half — the same thing it already
        // does for every other whole-directory refusal.
        if ($repo !== null) {
            $siteFile = rtrim($repo, '/') . '/site.duo.json';
            $sitePolicy = self::read_manifest($siteFile);
            if ($sitePolicy['stage'] !== 'ok') {
                $refusals[] = [
                    'code' => self::REFUSAL_SITE_POLICY_UNREADABLE,
                    'message' => "duo: site policy '$siteFile' cannot be read: " . $sitePolicy['error']
                        . ' — every adapter grammar verdict loads this file, so none of them could be judged '
                        . 'against this repository',
                    'paths' => [$siteFile],
                    'remediation' => self::repair_advice($sitePolicy['stage'])
                        . '; until then shipped adapters are reported as they would be with no --repo at all',
                    // Whole-repository, like every other site-source refusal:
                    // no adapter's grammar could be judged against this
                    // repository while it stands.
                    'scope' => self::SCOPE_SOURCE,
                    'source' => self::SITE,
                ];
            }
        }

        // The reviewed disposition set is a property of the shipped library,
        // and its own one-for-one coverage check can refuse. That refusal is
        // about the library rather than about any one adapter, so it degrades
        // to "no reviewed status known" here instead of taking the inventory
        // down; `duo capabilities` is where a library-level registry problem
        // is the subject.
        $dispositions = null;
        if (class_exists(ManifestDispositions::class)) {
            try {
                $dispositions = ManifestDispositions::load($manifestDir);
            } catch (\Throwable $t) {
                $dispositions = null;
            }
        }

        // A site adapter's certification word is DUO-3314's, not a second
        // vocabulary invented here: a verified signature alone is
        // `signed_unpinned`, and only a repository pin that binds both
        // source "site" and the final certificate-derived digest elevates it
        // to `third_party_signed`. diagnostics() draws exactly that
        // distinction for `wp duo capabilities`, and a catalog that flattened
        // the two would report an adapter as certified before the repository
        // had reviewed the evidence — the elevation the pin exists to gate.
        $bound = new self($scan['origins'], $scan['provenance'], $scan['certificates'], $scan['claims']);
        $bound->bind_explicit_pins(self::surveyed_pins($repo));
        // Whether this library HAS a reviewed certification story at all. A
        // custom or test manifest directory carrying neither file makes no
        // product claim (spec/repo-format.md says so in as many words), so a
        // shipped row there defers its certification to nothing — and `null`
        // is what says that. Answering `registry` would name a registry the
        // consumer would then go looking for.
        $hasRegistry = $dispositions !== null
            && is_file(rtrim($manifestDir, '/') . '/capabilities/registry.json');

        // A refused SITE certification source means certification_files()
        // returned nothing, so not one companion certificate was paired or
        // opened. Every site row in this run is therefore unjudged rather than
        // unsigned.
        //
        // Filtered on `source`, and that filter is load-bearing since DUO-3339
        // made `certification_source` reachable from the PLUGIN source too (a
        // plugin shipping a `duo-adapter.certification.json` beside its
        // bundle). Unfiltered, one third party's stray file re-judged every
        // adapter in the operator's OWN repository: a genuinely uncertified
        // site adapter flipped to `certification_unjudged`, and — worse — a
        // signed, pinned `third_party_signed` one was masked behind the same
        // word, reporting reviewed evidence as unexamined. This is
        // grammar_verdict()'s twin, and `source`/`scope` exist on the row for
        // exactly these two questions.
        $certificationUnjudged = false;
        foreach ($refusals as $refusal) {
            if (($refusal['code'] ?? null) === self::REFUSAL_CERTIFICATION_SOURCE
                && ($refusal['source'] ?? null) === self::SITE) {
                $certificationUnjudged = true;
                break;
            }
        }

        $adapters = [];
        foreach ($scan['origins'] as $name => $origin) {
            $name = (string) $name;
            $manifest = $scan['manifests'][$name] ?? [];
            $source = (string) $origin['source'];
            $outOfTree = isset($scan['provenance'][$name]);
            $entry = $dispositions === null || $outOfTree ? null : $dispositions->entry($name);
            $tier = self::tier_decision($manifest);
            $grammar = self::grammar_verdict($name, $source, $repo, $refusals);
            $adapters[] = [
                // A bundled adapter's word is `uncertified`, always, and never
                // one of DUO-3314's signed words: certification binds
                // `adapter.source: "site"` and `adapters/<name>.json` inside
                // the SIGNED statement, so no certificate can name this
                // adapter at all. site_certification()'s three-way question
                // does not arise, and asking it here would invite an answer.
                'certification' => $source === self::PLUGIN
                    ? 'uncertified'
                    : ($outOfTree
                        ? self::site_certification($bound, $name, $certificationUnjudged, $grammar)
                        : ($hasRegistry ? 'registry' : null)),
                // The version story a certified package actually carries,
                // which was invisible before: inspect_row() read the claim off
                // the SHIPPED registry, which by construction never names a
                // site adapter, so a signed and pinned site adapter printed
                // "registry claim: (none)". Carried from the verified
                // envelope's own proof rather than re-derived.
                'certification_evidence' => self::certification_evidence(
                    $scan['provenance'][$name] ?? null,
                    $scan['certificates'][$name] ?? null,
                    $scan['claims'][$name] ?? null
                ),
                'disposition_status' => is_array($entry) ? (string) ($entry['status'] ?? '') : null,
                'executable_surfaces' => self::executable_surfaces($manifest),
                'grammar' => $grammar,
                'name' => $name,
                'path' => (string) $origin['path'],
                'required_providers' => self::required_providers($manifest),
                // The canonical manifest hash, exactly the basis
                // provenance_record() uses — so a survey row and a frozen
                // provenance record describe one adapter with one number,
                // rather than a file-byte hash here and a content hash there.
                'sha256' => hash('sha256', Canon::encode($manifest)),
                'source' => $source,
                'tier_basis' => $tier['tier_basis'],
                'trust_tier' => $tier['trust_tier'],
            ];
        }

        return [
            'adapters' => $adapters,
            'not_installed' => $scan['not_installed'],
            'refusals' => $refusals,
            'sources' => $scan['sources'],
        ];
    }

    /**
     * One row's signed version story, or null when it has none.
     *
     * This is the ANSWER to "what binds the version of an adapter installed as
     * a package", and every field of it is already inside the signed envelope:
     * the authority that signed, the certificate/statement/platform digests
     * the signature covers, the evidence bundle and its git revision and named
     * tests, the artifacts[] rows naming what was actually exercised and at
     * which version, and the supported_versions the ratification forced to
     * equal the manifest's own. Nothing is minted and nothing is recomputed —
     * this is a projection, which is why it can be trusted next to the word
     * `certified`.
     *
     * `artifacts` needs the certificate itself: the derived disposition keeps
     * bundle identity, not the bundle's artifact list, so the retained
     * envelope is decoded for exactly that one field. A malformed envelope
     * yields `null` rather than an exception — every field beside it is
     * already proved, and an inventory must not die reporting an extra.
     * `null` and `[]` are kept APART: `[]` is "this bundle exercised no named
     * artifact", `null` is "the list could not be read", and reporting the
     * second as the first is the shape of silence this whole surface exists to
     * remove.
     *
     * @return ?array<string,mixed>
     */
    private static function certification_evidence(?array $record, ?array $envelope, ?array $claim): ?array {
        $proof = is_array($record['provenance']['proof'] ?? null) ? $record['provenance']['proof'] : null;
        if ($proof === null) {
            return null;
        }
        $artifacts = null;
        $encoded = is_string($envelope['certificate_json'] ?? null)
            ? base64_decode((string) $envelope['certificate_json'], true)
            : false;
        if (is_string($encoded)) {
            try {
                $certificate = Canon::decode($encoded);
                $artifacts = [];
                foreach ((array) ($certificate['statement']['bundle']['artifacts'] ?? []) as $artifact) {
                    if (is_array($artifact)) {
                        $artifacts[] = $artifact;
                    }
                }
            } catch (\Throwable $t) {
                $artifacts = null;
            }
        }
        return [
            'artifacts' => $artifacts,
            'authority' => is_array($proof['authority'] ?? null) ? $proof['authority'] : null,
            'bundle' => is_array($proof['bundle'] ?? null) ? $proof['bundle'] : null,
            'certificate_sha256' => $proof['certificate_sha256'] ?? null,
            'platform_sha256' => $proof['platform_sha256'] ?? null,
            'statement_sha256' => $proof['statement_sha256'] ?? null,
            'supported_versions' => is_array($claim['supported_versions'] ?? null)
                ? $claim['supported_versions']
                : null,
        ];
    }

    /**
     * One out-of-tree adapter's certification word.
     *
     * Three facts decide it, and the order matters:
     *
     *   1. If the certification SOURCE was refused, nothing was paired and no
     *      certificate was opened — so this adapter is `certification_unjudged`
     *      whether or not it ships one. Calling it `uncertified` would report a
     *      verdict on evidence nobody read.
     *   2. A verified signature alone is `signed_unpinned`. Elevation to
     *      `third_party_signed` additionally requires the repository pin to
     *      bind both source "site" and the final certificate-derived digest.
     *   3. Elevation is withheld unless this row's own grammar is `ok`.
     *      bind_explicit_pins() checks the digest's SHAPE, not its value; the
     *      engine compares the value (Policy's hash_equals) and refuses the
     *      repository outright when it disagrees. Without this clause a
     *      well-formed but WRONG 64-hex digest read as promotion-ready
     *      third-party evidence in the catalog while every real command
     *      refused the repo — the catalog contradicting the engine about the
     *      one claim the pin exists to gate.
     *
     * @param array{status:string, message:?string} $grammar
     */
    private static function site_certification(
        self $sources,
        string $name,
        bool $certificationUnjudged,
        array $grammar
    ): string {
        if ($certificationUnjudged) {
            return self::CERTIFICATION_UNJUDGED;
        }
        if (!$sources->is_certified($name)) {
            return 'uncertified';
        }
        return !empty($sources->explicitPins[$name]) && $grammar['status'] === self::GRAMMAR_OK
            ? 'third_party_signed'
            : 'signed_unpinned';
    }

    /**
     * The repository's own pins, for the elevation check above and nothing
     * else.
     *
     * Read straight out of site.duo.json rather than through Policy::load(),
     * which would refuse the whole repository over any unrelated policy
     * defect and take an inventory down for a reason that is not about
     * adapters at all. A pin list this cannot read is simply not an explicit
     * pin, which is the fail-closed direction: a signed adapter then reports
     * `signed_unpinned`, never `third_party_signed`.
     *
     * @return list<array{name:string,source?:string,digest?:string}>
     */
    private static function surveyed_pins(?string $repo): array {
        if ($repo === null) {
            return [];
        }
        try {
            $site = Canon::decode(Canon::read_file(rtrim($repo, '/') . '/site.duo.json'));
        } catch (\Throwable $t) {
            return [];
        }
        $pins = [];
        foreach ((array) (is_array($site) ? ($site['manifests'] ?? []) : []) as $raw) {
            if (is_string($raw)) {
                $pins[] = ['name' => $raw];
                continue;
            }
            if (is_array($raw) && is_string($raw['name'] ?? null)) {
                $pins[] = [
                    'name' => $raw['name'],
                    'digest' => is_string($raw['digest'] ?? null) ? $raw['digest'] : null,
                    'source' => is_string($raw['source'] ?? null) ? $raw['source'] : null,
                ];
            }
        }
        return $pins;
    }

    /**
     * One adapter's grammar verdict, from the REAL loader — the same posture
     * `duo manifest-validate` takes, and for the same reason: a second
     * validator here would be a grammar this engine does not enforce.
     *
     * Which repo the load gets is not cosmetic, and neither is what happens
     * when it cannot be given one. The engine refuses an adapter source
     * WHOLE-DIRECTORY, before any manifest in it is validated, so once
     * anything in this survey is refused:
     *
     *   - a SHIPPED adapter is judged without the site half. It does not need
     *     one to exist, and reporting one site file's refusal as fifteen
     *     unrelated adapters' grammar errors is true and useless.
     *   - a SITE adapter is judged not at all, and says so with its own status
     *     rather than borrowing `error`. Its manifest has not been read yet;
     *     calling that a grammar error would blame bytes nobody has looked at,
     *     and would put it in the same count as an adapter that really is
     *     malformed.
     *   - a PLUGIN adapter is judged normally. Its resolution needs no
     *     repository at all, and a per-adapter refusal in its own source did
     *     not stop anything else in that source from being read.
     *
     * Which is why only `scope === 'source'` refusals count here. Without the
     * filter, DUO-3339's plugin source would have silently changed what every
     * SITE row reports: one bundled adapter with a typo in a plugin nobody
     * asked about would have marked every site adapter `blocked_by_source_
     * refusal` and dropped the site half of every shipped verdict, which is
     * both false and exactly the "true and useless" answer this function was
     * written to avoid.
     *
     * @param string $source the row's own adapter source
     * @param list<array<string,mixed>> $refusals refusals this survey already collected
     * @return array{status:string, message:?string}
     */
    private static function grammar_verdict(string $name, string $source, ?string $repo, array $refusals): array {
        $blocking = [];
        foreach ($refusals as $refusal) {
            if (($refusal['scope'] ?? self::SCOPE_SOURCE) === self::SCOPE_SOURCE) {
                $blocking[] = $refusal;
            }
        }
        if ($source === self::SITE && $blocking !== []) {
            $first = $blocking[0];
            return [
                'message' => "this adapter's own grammar was not judged: its source carries "
                    . count($blocking) . ' unresolved refusal(s) (first: ' . (string) $first['code'] . ' — '
                    . implode(', ', array_map(
                        static fn($path): string => self::render($path),
                        (array) $first['paths']
                    )) . '), and the engine refuses an adapter source '
                    . 'whole-directory before any manifest in it is validated. Resolve the refusals in this '
                    . 'report, then re-run',
                'status' => self::GRAMMAR_BLOCKED,
            ];
        }
        try {
            Policy::load($blocking === [] ? $repo : null, [$name]);
            return ['message' => null, 'status' => self::GRAMMAR_OK];
        } catch (\Throwable $t) {
            return ['message' => $t->getMessage(), 'status' => self::GRAMMAR_ERROR];
        }
    }

    /**
     * The three channels that make a manifest more than data, listed even
     * when empty: an inventory whose "no executable surfaces" row looked
     * identical to a row that simply omitted the field would be the silence
     * this whole surface exists to remove.
     *
     * @return array{interpreter:?string, manifest_providers:list<string>, regenerators:list<array{post_type:string, regenerator:string}>}
     */
    private static function executable_surfaces(array $manifest): array {
        $interpreter = $manifest['interpreter'] ?? null;
        $regenerators = [];
        foreach ((array) ($manifest['post_types'] ?? []) as $postType => $declaration) {
            $regenerator = is_array($declaration) ? ($declaration['regen_dependency']['regenerator'] ?? null) : null;
            if ($regenerator !== null) {
                $regenerators[] = [
                    'post_type' => (string) $postType,
                    'regenerator' => is_string($regenerator) ? $regenerator : var_export($regenerator, true),
                ];
            }
        }
        $manifestProviders = [];
        foreach ((array) ($manifest['providers'] ?? []) as $declaration) {
            if (is_array($declaration) && ($declaration['source'] ?? null) === 'manifest') {
                $manifestProviders[] = (string) ($declaration['id'] ?? '?');
            }
        }
        return [
            'interpreter' => is_string($interpreter) ? $interpreter : null,
            'manifest_providers' => $manifestProviders,
            'regenerators' => $regenerators,
        ];
    }

    /**
     * The providers this adapter's declarations REQUIRE, with the capabilities
     * each one has to advertise. Declared facts only: whether the owning
     * plugin is installed, active, in range, and actually answering is
     * negotiation's answer (Providers::diagnose()), and a catalog that guessed
     * at it offline would be inventing the one fact it cannot have.
     *
     * @return list<array<string,mixed>>
     */
    private static function required_providers(array $manifest): array {
        $out = [];
        foreach ((array) ($manifest['providers'] ?? []) as $declaration) {
            if (!is_array($declaration)) {
                continue;
            }
            $capabilities = [];
            foreach ((array) ($declaration['capabilities'] ?? []) as $capability) {
                $capabilities[] = is_string($capability) ? $capability : var_export($capability, true);
            }
            $out[] = [
                'capabilities' => $capabilities,
                'id' => (string) ($declaration['id'] ?? '?'),
                'plugin' => (string) ($declaration['plugin'] ?? ''),
                'source' => (string) ($declaration['source'] ?? ''),
                'version' => (string) ($declaration['version'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * The site adapter source is a FLAT directory of `<name>.json` files, and
     * this file's own doctrine is that inert bytes an operator believed in are
     * the failure mode to refuse. glob('*.json') silently skips both classes
     * below, so they are enumerated explicitly instead:
     *
     * - a nested *.json, which the engine will never look at; and
     * - an extension near-miss (`.JSON`, `.Json`), which loads on a
     *   case-insensitive filesystem and vanishes on a case-sensitive one — the
     *   same adapter set resolving differently per host.
     *
     * Non-JSON companions (README, .gitignore) are deliberately left alone:
     * they assert nothing about adapters and refusing them would be noise.
     */
    private static function assert_flat_json_source(string $siteDir, bool $collect, array &$refusals): void {
        foreach (scandir($siteDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $siteDir . '/' . $entry;
            if (is_link($full)) {
                self::refuse(
                    $collect,
                    $refusals,
                    self::SITE,
                    self::REFUSAL_SYMLINK_SOURCE,
                    [self::SITE_DIR . '/' . $entry],
                    "duo: site adapter source $siteDir contains the symbolic link '$entry' (-> "
                    . (readlink($full) ?: '?') . ') — an out-of-tree adapter source holds only real files inside '
                    . 'the site repository, so that its recorded provenance travels with the repository',
                    'replace the link with the real file, or remove it from ' . self::SITE_DIR . '/'
                );
                continue;
            }
            if (is_dir($full)) {
                if ($entry === self::CERTIFICATION_DIR) {
                    self::guarded(
                        $collect,
                        $refusals,
                        self::SITE,
                        self::REFUSAL_CERTIFICATION_SOURCE,
                        [self::SITE_DIR . '/' . $entry],
                        'hold only real, exactly-named <adapter-name>.json certificate files there',
                        static fn() => self::assert_certification_directory($siteDir, $full)
                    );
                    continue;
                }
                $nested = self::first_nested_json($full);
                if ($nested !== null) {
                    self::refuse(
                        $collect,
                        $refusals,
                        self::SITE,
                        self::REFUSAL_NESTED_JSON,
                        [self::SITE_DIR . '/' . $entry . '/' . $nested],
                        "duo: site adapter source $siteDir contains a nested adapter '$entry/$nested' — adapters are "
                        . 'discovered only at the top level of this directory, so a nested file is never loaded. '
                        . 'Move it to ' . self::SITE_DIR . '/<name>.json',
                        'move it to ' . self::SITE_DIR . '/<name>.json'
                    );
                }
                continue;
            }
            if (preg_match('/\.json$/iD', $entry) === 1 && !str_ends_with($entry, '.json')) {
                self::refuse(
                    $collect,
                    $refusals,
                    self::SITE,
                    self::REFUSAL_EXTENSION_CASE_MISMATCH,
                    [self::SITE_DIR . '/' . $entry],
                    "duo: site adapter source $siteDir contains '$entry', whose extension is not exactly '.json' — "
                    . 'it would load on a case-insensitive filesystem and disappear on a case-sensitive one. '
                    . 'Rename it to use a lowercase .json extension',
                    'rename it to use a lowercase .json extension'
                );
            }
            if (str_ends_with($entry, '.json')) {
                self::guarded(
                    $collect,
                    $refusals,
                    self::SITE,
                    self::REFUSAL_INVALID_NAME,
                    [self::SITE_DIR . '/' . $entry],
                    'rename the file to a canonical lowercase ASCII slug',
                    static fn() => self::assert_name(basename($entry, '.json'), 'site adapter '
                        . self::render($entry))
                );
            }
        }
    }

    /**
     * The one deliberately nested site source. A certificate is authority-
     * bearing data, so unlike a README there are no ignored companions here:
     * every entry must be one canonical, real `<name>.json` file.
     */
    private static function assert_certification_directory(string $siteDir, string $dir): void {
        $resolvedSite = realpath($siteDir);
        $resolved = realpath($dir);
        if ($resolvedSite === false || $resolved === false
            || $resolved !== $resolvedSite . '/' . self::CERTIFICATION_DIR) {
            throw new \RuntimeException(
                "duo: site adapter certification source $dir is not the repository's real "
                . self::SITE_DIR . '/' . self::CERTIFICATION_DIR . ' directory'
            );
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            if (is_link($full) || !is_file($full)) {
                throw new \RuntimeException(
                    "duo: site adapter certification source $dir contains '$entry', which is not a real regular "
                    . 'file — certificates may not be symlinks, directories, or external paths'
                );
            }
            if (!str_ends_with($entry, '.json')) {
                throw new \RuntimeException(
                    "duo: site adapter certification source $dir contains '$entry' — every entry must be an exact "
                    . 'lowercase <adapter-name>.json certificate'
                );
            }
            self::assert_name(
                basename($entry, '.json'),
                'site adapter certificate ' . self::render(
                    self::SITE_DIR . '/' . self::CERTIFICATION_DIR . "/$entry"
                )
            );
        }
    }

    /**
     * Return the exact companion certificate for each top-level adapter and
     * reject orphan/case-confusable certificate identities before pins resolve.
     *
     * @param list<string> $siteFiles
     * @return array<string,string>
     */
    private static function certification_files(string $siteDir, array $siteFiles): array {
        $dir = $siteDir . '/' . self::CERTIFICATION_DIR;
        if (!is_dir($dir)) {
            return [];
        }
        $adapterNames = [];
        foreach ($siteFiles as $file) {
            $adapterNames[basename($file, '.json')] = true;
        }
        $out = [];
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $name = basename($file, '.json');
            if (!isset($adapterNames[$name])) {
                $folded = self::casefold($name);
                $near = null;
                foreach (array_keys($adapterNames) as $adapterName) {
                    if (self::casefold((string) $adapterName) === $folded) {
                        $near = (string) $adapterName;
                        break;
                    }
                }
                throw new \RuntimeException(
                    "duo: site adapter certificate '" . self::SITE_DIR . '/' . self::CERTIFICATION_DIR . '/'
                    . basename($file) . "' has no exact companion '" . self::SITE_DIR . "/$name.json'"
                    . ($near === null ? '' : "; '$near' differs only by letter case")
                );
            }
            $folded = self::casefold($name);
            foreach (array_keys($out) as $other) {
                if ($other !== $name && self::casefold((string) $other) === $folded) {
                    throw new \RuntimeException(
                        "duo: site adapter certificates '$other' and '$name' differ only by letter case"
                    );
                }
            }
            $out[$name] = $file;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * The first JSON-ish entry found under $dir, relative to it.
     *
     * A symlink is judged by its NAME and never followed: the caller only sees
     * the top level, so skipping a link here would let a symlinked
     * `docs/nested.json` be silently ignored — neither refused nor loaded,
     * which is the outcome this whole scan exists to prevent. Judging by name
     * also keeps the refusal message truthful for a symlinked non-JSON
     * companion, which is passed over rather than reported as a nested adapter.
     *
     * @return ?string
     */
    private static function first_nested_json(string $dir): ?string {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            if (preg_match('/\.json$/iD', $entry) === 1) {
                return $entry;
            }
            if (is_link($full)) {
                continue;
            }
            if (is_dir($full)) {
                $deeper = self::first_nested_json($full);
                if ($deeper !== null) {
                    return $entry . '/' . $deeper;
                }
            }
        }
        return null;
    }

    /**
     * Case folding for identity comparison only — never for storage or lookup.
     * mbstring is not a hard dependency of this engine, so fall back to the
     * byte-wise fold: ASCII case is the confusable class this guards, and a
     * missed non-ASCII fold still leaves the exact-match checks in force.
     */
    private static function casefold(string $value): string {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /**
     * Render a name for an operator. Two names can differ in bytes while
     * rendering identically in a terminal — NFC vs NFD 'café' is the reachable
     * case — so a refusal that only prints them is unactionable. The hex suffix
     * is appended exactly when the rendering would otherwise be ambiguous.
     *
     * Control characters are REPLACED rather than merely annotated, and the
     * rendering is length-capped. Every value that reaches here is one this
     * scan does not own — a declared `name` out of a third party's JSON, a
     * directory entry, a plugin basename out of an option — and the result is
     * printed to a terminal and embedded in a JSON document. An ANSI escape
     * sequence inside a declared name would otherwise clear the screen and
     * print a line indistinguishable from this command's own output, which is
     * a refusal message forging the report it appears in. The hex receipt is
     * unaffected: it already fired for exactly these values, and it is what
     * keeps the refusal actionable after the substitution.
     */
    /**
     * render(), for the two CLI renderers.
     *
     * They print the SAME third-party strings this scan quotes — a bundled
     * adapter's declared `name`, and paths whose middle segment is a plugin
     * directory name — straight out of the report's data fields, which are
     * deliberately kept raw so a rendered line and a `--format=json` document
     * still describe the same bytes. Sanitizing a value at every place it is
     * PRINTED, rather than where it is stored, is what keeps those two true at
     * once; sharing the one implementation is what stops the terminal-safety
     * rule from acquiring a second, weaker copy in each renderer.
     */
    public static function render_untrusted($value): string {
        return self::render($value);
    }

    private static function render($value, int $limit = 256): string {
        if (!is_string($value)) {
            return var_export($value, true);
        }
        $truncated = strlen($value) > $limit;
        $shown = $truncated ? substr($value, 0, $limit) : $value;
        $printable = preg_match('/^[\x20-\x7e]*$/D', $shown) === 1;
        return "'" . preg_replace('/[\x00-\x1f\x7f]/', '?', $shown) . "'"
            . ($printable ? '' : ' (hex ' . bin2hex($shown) . ')')
            . ($truncated ? ' (truncated from ' . strlen($value) . ' bytes)' : '');
    }

    /**
     * `scandir()` for a directory this scan does not own.
     *
     * Unguarded, `scandir()` on an unreadable directory emits a PHP warning and
     * returns false. Both of those are load-bearing failures here rather than
     * cosmetic ones, and a plugin directory at mode 0711 (search, no read) is
     * an ordinary hardened-hosting configuration, not a contrived one:
     *
     *   - the warning goes to output, which corrupts every `--format=json`
     *     document this scan feeds; and
     *   - under a warnings-as-exceptions error handler — Query Monitor,
     *     Sentry, Whoops, all common on WordPress — the warning becomes an
     *     ErrorException thrown out of BOTH discover() and survey(), which is
     *     precisely the site-wide outage a per-adapter refusal scope exists to
     *     prevent, arriving through the one channel that bypasses it.
     *
     * So the read is proved first and suppressed second, and the caller is told
     * it got nothing rather than an empty directory. The two are different
     * facts and only one of them means "this plugin bundles nothing".
     *
     * @return ?list<string> null when the directory could not be enumerated
     */
    private static function entries(string $dir): ?array {
        if (!is_dir($dir) || !is_readable($dir)) {
            return null;
        }
        $entries = @scandir($dir);
        if (!is_array($entries)) {
            return null;
        }
        return array_values(array_filter(
            $entries,
            static fn(string $entry): bool => $entry !== '.' && $entry !== '..'
        ));
    }

    /**
     * One canonical identity grammar is shared by file names, repository pins,
     * authority key ids, ratification maps, and frozen records. Keeping it
     * ASCII and lowercase makes the same bytes resolve on case-folding and
     * Unicode-normalizing filesystems; allowing dots, underscores, and
     * hyphens internally preserves ordinary slug-like names without admitting
     * hidden files or traversal components. Every identity must contain a
     * letter: PHP turns an all-digit JSON object key into an integer array key,
     * which would make an authority, ratification, or frozen-map lookup depend
     * on a lossy decoder representation.
     */
    public static function assert_name(string $name, string $label = 'adapter name'): void {
        if (preg_match('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/D', $name) !== 1
            || preg_match('/[a-z]/D', $name) !== 1) {
            throw new \RuntimeException(
                "duo: $label uses " . self::render($name)
                . ' — canonical lowercase ASCII slugs for adapter/key identities must begin and end with a '
                . 'letter or digit, containing only letters, digits, dots, underscores, or hyphens, and containing '
                . 'at least one lowercase letter (numeric-only identities are not safe JSON object-map keys)'
            );
        }
    }

    /**
     * The exact safe plugin-basename grammar shared by a manifest's own
     * compatibility claim and every plugin-owned provider declaration. A
     * provider without a top-level `plugin` claim still reaches WordPress's
     * plugin-path APIs during negotiation, so it cannot be held to a weaker
     * shape merely because there is no version-range claim to compare it to.
     *
     * @return string the validated, unmodified basename
     */
    public static function assert_plugin_basename(mixed $plugin, string $label = 'plugin basename'): string {
        if (!is_string($plugin) || $plugin === '' || strlen($plugin) > 255) {
            throw new \RuntimeException("duo: $label must be a non-empty plugin basename");
        }
        $segments = explode('/', $plugin);
        $depthOk = count($segments) <= 2;
        $file = $segments[count($segments) - 1] ?? '';
        if (!$depthOk || $plugin[0] === '/' || str_contains($plugin, '\\')
            || in_array('..', $segments, true) || in_array('.', $segments, true)
            || in_array('', $segments, true) || !str_ends_with($file, '.php')) {
            throw new \RuntimeException(
                "duo: $label " . self::render($plugin)
                . " — a plugin basename is '<directory>/<file>.php' or '<file>.php', never an absolute path and "
                . 'never one containing a ".." segment; it is concatenated into filesystem paths by the code-half '
                . 'version checks and provider negotiation'
            );
        }
        return $plugin;
    }

    /**
     * Shipped manifests are decoded only when a site source actually exists.
     * The declared-name collision check is the only thing that needs them, and
     * a repository with no `adapters/` directory must pay no new I/O and take
     * no new refusal path.
     *
     * Collect mode has already decoded them (scan()), and has already refused
     * any that would not decode, so it reads them out of $manifests instead of
     * opening the same files a second time — and never re-refuses the ones it
     * already reported.
     *
     * @param array<string, array> $manifests decoded shipped manifests ($collect only)
     * @return array<string, string> declared name => file name that declares it
     */
    private static function declared_names(
        array $origins,
        array $manifests,
        bool $collect,
        array &$refusals
    ): array {
        $names = [];
        foreach ($origins as $file => $origin) {
            if ($collect) {
                $manifest = $manifests[$file] ?? null;
                if ($manifest === null) {
                    continue;
                }
            } else {
                $manifest = Canon::decode(Canon::read_file($origin['file']));
            }
            $declared = $manifest['name'] ?? null;
            if (is_string($declared) && $declared !== '') {
                // Guarded like every other DUO-3314 assertion reachable from
                // collect mode. A shipped manifest whose FILE name is a legal
                // slug but whose DECLARED name is not is reachable through any
                // DUO_MANIFESTS_DIR, and unguarded it made the catalog answer
                // two different ways about one library: `duo adapter list`
                // worked, `duo adapter list --repo=...` died with exit 2,
                // because only the second reaches this function.
                if (!self::guarded(
                    $collect,
                    $refusals,
                    self::SHIPPED,
                    self::REFUSAL_INVALID_NAME,
                    [(string) $origin['path']],
                    "make the manifest's declared name a canonical lowercase ASCII slug",
                    static fn() => self::assert_name($declared, "shipped adapter '$file' declared name")
                )) {
                    continue;
                }
                $names[$declared] = $file;
            }
        }
        return $names;
    }

    /**
     * The synthesized stand-in for a reviewed disposition. It is not a weaker
     * disposition — it is the assertion that no review happened, recorded in a
     * shape the digest can bind. `path` is repo-relative so the same adapter
     * digests identically in every checkout of the site repo.
     *
     * `sha256` hashes the CANONICAL manifest, not the file's raw bytes. The
     * frozen verification path holds no file to reopen, so a raw-byte hash
     * there could only ever be an unverifiable breadcrumb; hashing canonical
     * content instead makes it recomputable from the frozen record itself, so
     * from_snapshot() proves this record describes the manifest it is attached
     * to rather than one moved onto it. Nothing is lost: the manifest's own
     * content is already inside the adapter digest either way.
     *
     * `reason` is IDENTITY-BEARING: this record is the adapter's disposition,
     * the disposition is folded into the adapter digest, and the digest is
     * what a repository pin binds. So the plugin variant's wording is not
     * prose — changing it changes every bundled adapter's identity and
     * invalidates every pin naming one. It says the two things an operator
     * reading a blocked promotion needs and cannot get anywhere else: WHICH
     * plugin this definition came from, and that no amount of certifying will
     * work while it lives there.
     */
    private static function provenance_record(
        string $name,
        string $relativePath,
        array $manifest,
        string $source = self::SITE,
        ?string $plugin = null
    ): array {
        $sha256 = hash('sha256', Canon::encode($manifest));
        return [
            'certification' => 'uncertified',
            'provenance' => [
                'format' => self::FORMAT,
                'path' => $relativePath,
                'sha256' => $sha256,
                'source' => $source,
            ],
            'reason' => $source === self::PLUGIN
                ? "adapter '$name' is bundled by the active plugin '" . (string) $plugin . "' ($relativePath) and "
                    . 'carries no reviewed certification evidence; a bundled adapter cannot be certified in place '
                    . '— certification is a repository-scoped signed companion at ' . self::SITE_DIR . '/'
                    . self::CERTIFICATION_DIR . "/$name.json."
                : "adapter '$name' is installed out-of-tree from the site repository's "
                    . self::SITE_DIR . "/ directory and carries no reviewed certification evidence",
            'status' => 'uncertified',
            'trust_tier' => self::trust_tier($manifest),
        ];
    }

    /**
     * The highest trust tier this manifest's own declarations reach. Derived,
     * never declared: an adapter cannot self-report a lower tier than the
     * privileges it actually asks for.
     */
    public static function trust_tier(array $manifest): string {
        return self::tier_decision($manifest)['trust_tier'];
    }

    /**
     * The tier AND the declaration that produced it, from one walk.
     *
     * `tier_basis` is the anti-masquerade receipt DUO-3339 needs: a diagnostic
     * line saying `compatibility_shim` invites "says who?", and the honest
     * answer is a coordinate inside the manifest the reader can go open. It is
     * derived beside the tier rather than by a second reader for the reason
     * CapabilityRegistry::adapter_digest() gives for its own pairing — two
     * walks of one rule are two rules the moment either moves, and the one
     * that moved silently would be the one printed next to the word "shim".
     *
     * @return array{trust_tier:string, tier_basis:string}
     */
    public static function tier_decision(array $manifest): array {
        // Presence, not well-formedness — the same reason
        // assert_out_of_tree_contract() keys on presence: a malformed
        // declaration must not be able to report a LOWER tier than the
        // privilege it is reaching for.
        if (array_key_exists('interpreter', $manifest) && $manifest['interpreter'] !== null) {
            return self::tier(
                self::TIER_COMPATIBILITY_SHIM,
                'interpreter ' . self::render_value($manifest['interpreter'])
            );
        }
        foreach ((array) ($manifest['post_types'] ?? []) as $postType => $declaration) {
            if (is_array($declaration) && isset($declaration['regen_dependency']['regenerator'])) {
                return self::tier(
                    self::TIER_COMPATIBILITY_SHIM,
                    "post_types.$postType.regen_dependency.regenerator "
                    . self::render_value($declaration['regen_dependency']['regenerator'])
                );
            }
        }
        $tier = self::TIER_DECLARATIVE;
        $basis = 'no interpreter, regenerator, provider, or native action is declared';
        foreach ((array) ($manifest['providers'] ?? []) as $i => $declaration) {
            if (!is_array($declaration)) {
                continue;
            }
            if (($declaration['source'] ?? null) === 'manifest') {
                return self::tier(
                    self::TIER_COMPATIBILITY_SHIM,
                    "providers[$i] source \"manifest\" (id " . self::render_value($declaration['id'] ?? null) . ')'
                );
            }
            // The tier itself is set by EVERY non-manifest provider row, as it
            // always was; only the basis is first-wins, because the basis
            // answers "which declaration got you here", and the first one did.
            if ($tier !== self::TIER_PLUGIN_PROVIDER) {
                $basis = "providers[$i] source " . self::render_value($declaration['source'] ?? null)
                    . ' (id ' . self::render_value($declaration['id'] ?? null) . ')';
            }
            $tier = self::TIER_PLUGIN_PROVIDER;
        }
        if ($tier === self::TIER_DECLARATIVE) {
            foreach ((array) ($manifest['actions'] ?? []) as $i => $action) {
                if (is_array($action) && ($action['kind'] ?? null) === 'native') {
                    return self::tier(
                        self::TIER_NATIVE_ACTION,
                        "actions[$i] kind \"native\" (action " . self::render_value($action['action'] ?? null) . ')'
                    );
                }
            }
        }
        return self::tier($tier, $basis);
    }

    /** @return array{trust_tier:string, tier_basis:string} */
    private static function tier(string $tier, string $basis): array {
        return ['tier_basis' => $basis, 'trust_tier' => $tier];
    }

    /** A declaration value as it appears in a basis string, never trusted to be a string. */
    private static function render_value($value): string {
        return is_string($value) ? "'" . $value . "'" : var_export($value, true);
    }

    /**
     * One adapter, one name — asserted for a manifest this scan did not read.
     *
     * DUO-3314 refuses a disagreeing declared name for the SITE source inside
     * scan(), where the file is opened. The SHIPPED source is not read there at
     * all (see declared_names(): a repository with no adapters/ pays no decode),
     * so its manifests reach Policy::load() with the same disagreement
     * unrefused. That call is this method: the identical rule, stated in the
     * identical sentence, applied at the one point on the shipped path where
     * both names are in hand. Sharing the message rather than writing a second
     * one is the whole point — two hand-written copies of the sentence that
     * TEACHES this rule would eventually teach two different rules.
     */
    public static function assert_declared_name(array $manifest, string $name, string $source, string $path): void {
        $declared = $manifest['name'] ?? null;
        if (!is_string($declared) || $declared !== $name) {
            throw new \RuntimeException(self::ambiguous_identity_message($source, $path, $declared, $name));
        }
    }

    /** The remedy REFUSAL_AMBIGUOUS_IDENTITY rows carry, kept beside its message. */
    private const AMBIGUOUS_IDENTITY_REMEDY = 'make the declared name match the file name';

    /**
     * The one ambiguous-identity sentence, built once for every adapter source.
     *
     * `$path` is rendered rather than interpolated, which is DEFENSE IN DEPTH
     * with no reachable vector today and is kept deliberately: every path that
     * reaches here is printable by construction — the site and shipped routes
     * both pass a basename `assert_name()` has already held to a lowercase
     * ASCII slug, and the plugin route (whose path DOES carry a third-party
     * directory name) cannot fire this refusal at all, because identity
     * inverts there and the origin key IS the declared name. No fixture kills
     * a mutant that removes this, and inventing one would prove nothing real.
     * It costs nothing, renders byte-identically for every printable path, and
     * is the one argument here that a future caller could hand third-party
     * bytes.
     */
    private static function ambiguous_identity_message(
        string $source,
        string $path,
        $declared,
        string $name
    ): string {
        return "duo: $source adapter " . self::render($path) . ' declares name ' . self::render($declared)
            . ' but its file name is ' . self::render($name) . ' — a pin names the file while every '
            . 'downstream identity (dispositions, digests, diagnostics) keys off the declared name, so the '
            . 'two disagreeing is ambiguous identity. Make the declared name match the file name';
    }

    /**
     * The out-of-tree privilege boundary. Each refused channel resolves its
     * file inside the agent's own manifest directory, so an out-of-tree
     * manifest naming one would reach bytes it does not own (or nothing at
     * all). Refusing here, at load, keeps that from becoming either a silent
     * no-op or a cross-source code load.
     *
     * The boundary is identical for both out-of-tree sources — a bundled
     * manifest is exactly as data-only as a site one — so `$label` changes the
     * noun in the message and NOTHING else. It defaults to the historical
     * wording so every existing message stays byte-identical, which is what
     * the site-source regressions pin.
     */
    public static function assert_out_of_tree_contract(
        array $manifest,
        string $name,
        string $relativePath,
        string $label = 'site adapter',
        bool $renderPath = false
    ): void {
        // The path is a message field here, and for the PLUGIN source its
        // middle segment is a third party's directory name, which can hold
        // bytes that rewrite a terminal. Rendering is opt-in rather than
        // unconditional so every site-source message stays byte-identical —
        // those paths are `adapters/<slug>.json` and this project's
        // regressions compare them exactly.
        $shown = $renderPath ? self::render($relativePath) : "'$relativePath'";
        $remedy = "install the adapter into the agent's own manifest library (where its code ships, digest-binds, and "
            . 'is reviewed with it), or declare a plugin-owned provider whose code the installed plugin already owns';
        foreach ([
            'adapter_certificate', 'authority', 'authority_id', 'certificate', 'certification',
            'certification_authority', 'disposition', 'evidence', 'key_id', 'public_key', 'signature', 'trust_tier',
        ] as $reserved) {
            if (array_key_exists($reserved, $manifest)) {
                throw new \RuntimeException(
                    "duo: $label $shown declares reserved authority field '$reserved' — a data-only "
                    . 'manifest cannot certify itself or carry a trust root. Put an externally signed companion at '
                    . self::SITE_DIR . '/' . self::CERTIFICATION_DIR . "/$name.json instead"
                );
            }
        }
        // Keyed on PRESENCE, not on the value being a well-formed string: an
        // out-of-tree manifest that declares this key at all is asking for a
        // privilege it cannot have, and the refusal must not depend on the
        // declaration being well-formed enough to recognize. (Type/shape is
        // Policy::validate_adapter_contract()'s job, for every source.)
        if (array_key_exists('interpreter', $manifest) && $manifest['interpreter'] !== null) {
            throw new \RuntimeException(
                "duo: $label $shown declares interpreter "
                . var_export($manifest['interpreter'], true) . ", but interpreter code loads only from the agent's "
                . 'manifest library — an out-of-tree manifest is data and acquires no executable privileges. '
                . "Remediation: $remedy"
            );
        }
        foreach ((array) ($manifest['post_types'] ?? []) as $postType => $declaration) {
            $regenerator = is_array($declaration) ? ($declaration['regen_dependency']['regenerator'] ?? null) : null;
            if ($regenerator !== null) {
                throw new \RuntimeException(
                    "duo: $label $shown post_types.$postType declares regenerator "
                    . var_export($regenerator, true) . ", but regenerator code loads only from the agent's manifest "
                    . "library — an out-of-tree manifest is data and acquires no executable privileges. "
                    . "Remediation: $remedy"
                );
            }
        }
        foreach ((array) ($manifest['providers'] ?? []) as $i => $declaration) {
            if (is_array($declaration) && ($declaration['source'] ?? null) === 'plugin') {
                self::assert_plugin_basename(
                    $declaration['plugin'] ?? null,
                    "$label $shown providers[$i].plugin"
                );
            }
            if (is_array($declaration) && ($declaration['source'] ?? null) === 'manifest') {
                throw new \RuntimeException(
                    "duo: $label $shown providers[$i] declares source \"manifest\", which resolves to "
                    . "the agent's own manifests/providers/ tree — an out-of-tree manifest cannot supply provider "
                    . "code. Use source \"plugin\" so the installed plugin remains the code's trust anchor, or: $remedy"
                );
            }
        }
        // Unreachable via the loop above once every executable channel is
        // refused, but tier derivation is the thing diagnostics print, so a
        // future channel that lands here without its own refusal must not be
        // able to report itself as merely declarative.
        $tier = self::trust_tier($manifest);
        if ($tier === self::TIER_COMPATIBILITY_SHIM) {
            throw new \RuntimeException(
                "duo: $label $shown reaches the $tier trust tier, which an out-of-tree adapter cannot "
                . "hold. Remediation: $remedy"
            );
        }
    }

    /**
     * The file backing one adapter name, or a refusal naming every searched
     * source.
     *
     * This is where a plugin-source refusal becomes FATAL, and the only place
     * it does. The scan recorded it rather than throwing so that a third
     * party's broken bundle could not take down an installation the operator
     * did not author — but a pin naming that adapter is the operator saying
     * "run this one", and answering "not found" would send them looking for a
     * missing file while a named, remediable refusal sat against the one on
     * disk. So the pin gets the refusal's own message and its remediation.
     */
    public function file(string $name, string $manifestDir): string {
        $origin = $this->origins[$name] ?? null;
        if ($origin === null) {
            $refused = $this->scanReport['refused_names'][$name] ?? null;
            if (is_array($refused)) {
                throw new \RuntimeException(
                    (string) $refused['message'] . '. This adapter is pinned, so that refusal is fatal here rather '
                    . 'than reported: ' . (string) $refused['remediation']
                );
            }
            // A reconstructed (frozen) instance scanned nothing, so it can only
            // name the sources its own provenance proves it had — the exact
            // message this branch has always produced. A live instance knows
            // which of the three were reachable in THIS process, including the
            // one that is only reachable on the target, and says so.
            $searched = $this->searched_sources($manifestDir);
            throw new \RuntimeException("duo: manifest '$name' not found in " . $searched);
        }
        return $origin['file'];
    }

    /** The prose half of file()'s not-found message. */
    private function searched_sources(string $manifestDir): string {
        if ($this->scanReport['sources'] === []) {
            $sources = "$manifestDir (shipped)";
            if ($this->provenance !== []) {
                $sources .= " or the site repository's " . self::SITE_DIR . '/ directory';
            }
            return $sources;
        }
        $parts = [];
        foreach ($this->scanReport['sources'] as $row) {
            $source = (string) ($row['source'] ?? '');
            $path = $row['path'] ?? null;
            if (!empty($row['scanned'])) {
                $parts[] = ($path === null ? $source : (string) $path) . " ($source)";
                continue;
            }
            $parts[] = $source === self::PLUGIN
                ? 'plugin source not scanned (no WP_PLUGIN_DIR in this process)'
                : "$source source not scanned (" . (string) ($row['note'] ?? 'unavailable') . ')';
        }
        return implode('; ', $parts);
    }

    public function source(string $name): string {
        return (string) ($this->origins[$name]['source'] ?? self::SHIPPED);
    }

    public function path(string $name): ?string {
        return isset($this->origins[$name]) ? (string) $this->origins[$name]['path'] : null;
    }

    public function is_out_of_tree(string $name): bool {
        return isset($this->provenance[$name]);
    }

    /**
     * Re-prove the out-of-tree privilege boundary for one LOADED manifest,
     * with the label and path this instance actually resolved it from.
     *
     * Policy::load() has repeated this check since DUO-3314 as defense in
     * depth, hardcoding the site source's noun and path. With a third source
     * that would have printed "site adapter 'plugins/acme/duo-adapter.json'",
     * which names the wrong directory to go fix. The origin already knows.
     */
    public function assert_installed_contract(string $name, array $manifest): void {
        $source = $this->source($name);
        self::assert_out_of_tree_contract(
            $manifest,
            $name,
            (string) ($this->path($name) ?? $name),
            self::source_label($source)
        );
    }

    /** The noun a refusal message uses for one adapter source. */
    private static function source_label(string $source): string {
        return $source === self::PLUGIN ? 'plugin adapter' : 'site adapter';
    }

    /**
     * The plugin-source refusals this scan recorded rather than threw.
     *
     * Filtered from the same list survey() returns, rather than kept in a
     * second array, so the architectural claim the suite pins — that
     * discover()'s plugin refusals ARE survey()'s plugin refusals, row for row
     * and byte for byte — is true by construction instead of by maintenance.
     *
     * @return list<array<string,mixed>>
     */
    public function plugin_refusals(): array {
        $out = [];
        foreach ($this->scanReport['refusals'] as $refusal) {
            if (($refusal['source'] ?? null) === self::PLUGIN) {
                $out[] = $refusal;
            }
        }
        return $out;
    }

    /**
     * Adapters that are installed on this machine and did not load, with the
     * definition that outranked them. Never a refusal: nothing here is
     * broken.
     *
     * @return list<array<string,mixed>>
     */
    public function not_installed(): array {
        return $this->scanReport['not_installed'];
    }

    /**
     * Which of the three adapter sources this process could reach, and where.
     * Empty for a reconstructed policy, which reopened none of them.
     *
     * @return list<array<string,mixed>>
     */
    public function sources(): array {
        return $this->scanReport['sources'];
    }

    /** @return list<string> every unambiguous installed adapter identity */
    public function names(): array {
        $names = array_keys($this->origins);
        sort($names, SORT_STRING);
        return $names;
    }

    /** The synthesized disposition for an out-of-tree adapter; null for shipped. */
    public function provenance(string $name): ?array {
        return $this->provenance[$name] ?? null;
    }

    public function is_certified(string $name): bool {
        return isset($this->certificates[$name], $this->claims[$name]);
    }

    /**
     * Certification is an elevation of authority, so a valid signature alone
     * is not enough: the repository must explicitly review both the source and
     * the final certificate-derived digest in its pin.
     *
     * @param list<array{name:string,source?:string,digest?:string}> $pins
     */
    public function bind_explicit_pins(array $pins): void {
        $this->explicitPins = [];
        foreach ($pins as $pin) {
            $name = (string) ($pin['name'] ?? '');
            if ($name !== '' && $this->is_certified($name)
                && ($pin['source'] ?? null) === self::SITE
                && is_string($pin['digest'] ?? null)
                && preg_match('/^[0-9a-f]{64}$/D', (string) $pin['digest']) === 1) {
                $this->explicitPins[$name] = true;
            }
        }
    }

    /**
     * The host-bound claim preserves signed evidence visibility before the
     * elevation pin, but cannot report certified until that pin is exact.
     */
    public function claim(string $name): ?array {
        $claim = $this->claims[$name] ?? null;
        if ($claim === null || !empty($this->explicitPins[$name])) {
            return $claim;
        }
        $claim['status'] = 'uncertified';
        $claim['reason'] = 'valid signed third-party evidence is present, but the repository pin does not bind '
            . 'both source "site" and the final certificate-derived digest';
        if (is_array($claim['authored_state'] ?? null)) {
            $claim['authored_state']['status'] = 'uncertified';
        }
        return $claim;
    }

    /**
     * Per-row signed evidence context for capability reporting. The raw claim
     * is retained when the signature is valid but the pin is not yet explicit,
     * so diagnostics can prescribe `manifest-pin` instead of misreporting the
     * adapter as unsigned.
     *
     * @return array<string,array{claim:array,explicit_pin:bool}>
     */
    public function certification_contexts(): array {
        $out = [];
        foreach ($this->claims as $name => $claim) {
            $out[$name] = [
                'claim' => $claim,
                'explicit_pin' => !empty($this->explicitPins[$name]),
            ];
        }
        return $out;
    }

    /**
     * Per-adapter diagnostic facts: source, trust tier, certification state, and
     * the remediation an operator would act on. Shipped rows carry no
     * remediation here — their certification state comes from the reviewed
     * registry, which supplies its own per-reason remediation.
     *
     * @param list<array> $manifests loaded manifests, in pin order
     * @return array<string, array{source:string, path:?string, trust_tier:string, certification:string, remediation:string}>
     */
    public function diagnostics(array $manifests): array {
        $rows = [];
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '?');
            $record = $this->provenance($name);
            $signed = $this->is_certified($name);
            $explicit = !empty($this->explicitPins[$name]);
            $source = $this->source($name);
            $rows[$name] = [
                'certification' => $record === null
                    ? 'registry'
                    : ($signed ? ($explicit ? 'third_party_signed' : 'signed_unpinned') : 'uncertified'),
                'path' => $this->path($name),
                // A bundled adapter's remediation is not "get it signed" —
                // that is impossible where it lives, and telling an operator
                // to try would waste their afternoon proving it. It is the
                // PROMOTION PATH, and every step of it is already supported:
                // the site copy outranks the bundled one by precedence, so
                // nothing has to be deactivated and nothing breaks in between.
                'remediation' => $record === null
                    ? ''
                    : ($source === self::PLUGIN
                        ? ('install this adapter as a repository package at ' . self::SITE_DIR . "/$name.json, "
                            . 'obtain a certificate signed by an authority this agent trusts at ' . self::SITE_DIR
                            . '/' . self::CERTIFICATION_DIR . "/$name.json, then run `wp duo manifest-pin "
                            . "--repo=... --name=$name` and commit the emitted {name,source:\"site\",digest} pin. "
                            . 'The site copy wins by precedence and the bundled copy reports as not installed; the '
                            . 'plugin stays active throughout')
                        : ($signed
                            ? ($explicit
                                ? ''
                                : ('review the signed evidence, then replace this name-only pin with the exact '
                                    . '{name,source:"site",digest} object emitted by `wp duo manifest-pin '
                                    . '--repo=...`'))
                            : ('obtain an externally signed certificate from an authority trusted by this agent, or '
                                . 'keep it as uncertified support — plan and apply remain available, while readiness '
                                . 'and host promotion stay blocked'))),
                'source' => $source,
                'trust_tier' => self::trust_tier($manifest),
            ];
        }
        return $rows;
    }

    /** Only the shipped subset participates in disposition/registry coverage. */
    public function shipped_manifests(array $manifests): array {
        $out = [];
        foreach ($manifests as $manifest) {
            if (!$this->is_out_of_tree((string) ($manifest['name'] ?? ''))) {
                $out[] = $manifest;
            }
        }
        return $out;
    }

    /**
     * Freeze the provenance a verification process must reconstruct. Only the
     * out-of-tree records travel. In v2, a name absent from this map is shipped
     * only when from_snapshot() proves that the trusted manifest directory
     * still holds those exact bytes, so "absent" cannot launder a site adapter.
     * The legacy v1 read path retains its pre-existing custom-library contract.
     */
    public function export(): array {
        if ($this->wireFormat === self::LEGACY_FORMAT) {
            return ['format' => self::LEGACY_FORMAT, 'out_of_tree' => $this->provenance];
        }
        return [
            'certificates' => $this->certificates,
            'format' => self::FORMAT,
            'out_of_tree' => $this->provenance,
        ];
    }

    public function wire_format(): string {
        return $this->wireFormat;
    }

    /**
     * Rebuild frozen provenance and re-prove the out-of-tree contract against
     * the frozen manifest bytes — the snapshot path re-validates, it never
     * trusts. Concretely, every field of a frozen record is either derived here
     * or recomputed here: validate_frozen_record() rebuilds the expected path
     * from the record's own key and recomputes the canonical content hash from
     * the frozen manifest, and the trust tier is re-derived from that manifest's
     * declarations rather than read off the record.
     *
     * The mutable site repository is never reopened. In the v2 wire, a name
     * absent from `out_of_tree` is claiming agent-owned shipped authority, so
     * it is compared byte-for-byte (canonically) with the trusted agent
     * manifest library. This is required even when a caller reconstructs a
     * snapshot without disposition/registry data: deleting one provenance row
     * must not relabel arbitrary site bytes as shipped. Legacy v1 snapshots
     * retain their historical already-bound custom-library behavior and cannot
     * carry certificates; every new live export uses v2. Editing an
     * out-of-tree record in place separately fails through
     * validate_frozen_record() and the adapter digest binding.
     */
    public static function from_snapshot(array $data, array $manifests): self {
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        $format = $data['format'] ?? null;
        $legacy = $format === self::LEGACY_FORMAT;
        $expectedKeys = $legacy ? ['format', 'out_of_tree'] : ['certificates', 'format', 'out_of_tree'];
        if ($keys !== $expectedKeys
            || !in_array($format, [self::LEGACY_FORMAT, self::FORMAT], true)
            || !is_array($data['out_of_tree'] ?? null)
            || (array_is_list($data['out_of_tree']) && $data['out_of_tree'] !== [])
            || (!$legacy && (!is_array($data['certificates'] ?? null)
                || (array_is_list($data['certificates']) && $data['certificates'] !== [])))) {
            throw new \RuntimeException('duo: frozen adapter source record is malformed');
        }
        $frozen = $data['out_of_tree'];
        $frozenCertificates = $legacy ? [] : $data['certificates'];
        self::assert_identity_map_keys($frozen, 'frozen adapter source out_of_tree');
        if (!$legacy) {
            self::assert_identity_map_keys($frozenCertificates, 'frozen adapter source certificates');
        }
        $origins = [];
        $provenance = [];
        $certificates = [];
        $claims = [];
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '');
            self::assert_name($name, 'frozen adapter source record name');
            $record = $frozen[$name] ?? null;
            if ($record === null) {
                if ($legacy) {
                    // v1 predates an authoritative shipped-membership proof
                    // and remains readable for existing custom policy
                    // snapshots. It cannot carry certificates, while every
                    // newly exported policy uses the fail-closed v2 path.
                    $file = rtrim(Policy::manifests_dir(), '/') . '/' . $name . '.json';
                    $origins[$name] = ['source' => self::SHIPPED, 'file' => $file, 'path' => $file];
                    continue;
                }
                // Absence from out_of_tree is a positive shipped claim, not a
                // default. Prove it against the trusted agent library before
                // assigning shipped authority; otherwise deleting one frozen
                // provenance row could launder arbitrary site bytes into the
                // executable shipped source.
                $file = rtrim(Policy::manifests_dir(), '/') . '/' . $name . '.json';
                if (!is_file($file)) {
                    throw new \RuntimeException(
                        "duo: frozen adapter '$name' is absent from out_of_tree but no shipped manifest exists at "
                        . $file
                    );
                }
                $shipped = Canon::decode(Canon::read_file($file));
                if (Canon::encode($shipped) !== Canon::encode($manifest)) {
                    throw new \RuntimeException(
                        "duo: frozen adapter '$name' is absent from out_of_tree but its bytes do not match the "
                        . 'trusted shipped manifest — frozen provenance cannot relabel site content as shipped'
                    );
                }
                $origins[$name] = ['source' => self::SHIPPED, 'file' => $file, 'path' => $file];
                continue;
            }
            $recordSource = is_array($record) && is_array($record['provenance'] ?? null)
                ? ($record['provenance']['source'] ?? null)
                : null;
            $certificate = $frozenCertificates[$name] ?? null;
            if ($certificate === null) {
                self::validate_frozen_record($name, $record, $manifest, $legacy ? self::LEGACY_FORMAT : self::FORMAT);
            } else {
                if ($legacy) {
                    throw new \RuntimeException("duo: legacy frozen adapter source '$name' cannot carry a certificate");
                }
                // The same impossibility the live scan refuses, refused again
                // on the read side: certification binds `adapter.source:
                // "site"` and `adapters/<name>.json` INSIDE the signed
                // statement, so a certificate paired with a plugin-sourced
                // record is a pairing no signer could have produced. Refused
                // here rather than left to the verifier's own binding check so
                // the message names the actual contradiction.
                if ($recordSource === self::PLUGIN) {
                    throw new \RuntimeException(
                        "duo: frozen adapter source record for '$name' is bundled by a plugin and cannot carry a "
                        . 'certificate — a bundled adapter cannot be certified in place; certification binds the '
                        . 'site source and ' . self::SITE_DIR . "/$name.json inside the signed statement"
                    );
                }
                // A legacy/unsigned frozen policy never needs the optional
                // certification verifier. Load it only for the v2 record that
                // actually carries a signed external claim; see discover().
                require_once __DIR__ . '/AdapterCertification.php';
                $verified = AdapterCertification::verifyFrozen(
                    Policy::manifests_dir(),
                    $name,
                    $manifest,
                    $certificate
                );
                if (Canon::encode($verified['disposition']) !== Canon::encode($record)) {
                    throw new \RuntimeException(
                        "duo: frozen adapter certification for '$name' disagrees with its derived disposition"
                    );
                }
                $certificates[$name] = $verified['envelope'];
                $claims[$name] = $verified['claim'];
            }
            // The record's own path, not a re-derived site path: a frozen
            // plugin record's path is `plugins/<dir>/duo-adapter.json`, and
            // validate_frozen_record() has already proved that string is the
            // ONLY one this record could describe (from its own key for a site
            // record, from the frozen manifest's `plugin` claim for a bundled
            // one), so using it here re-proves nothing and misnames nothing.
            $path = (string) $record['provenance']['path'];
            $origin = $recordSource === self::PLUGIN ? self::PLUGIN : self::SITE;
            self::assert_out_of_tree_contract($manifest, $name, $path, self::source_label($origin));
            if (($record['trust_tier'] ?? null) !== self::trust_tier($manifest)) {
                throw new \RuntimeException(
                    "duo: frozen adapter source record for '$name' claims trust tier '{$record['trust_tier']}' but "
                    . 'its manifest reaches ' . self::trust_tier($manifest)
                );
            }
            $origins[$name] = [
                'source' => $origin,
                'file' => $path,
                'path' => $path,
            ];
            $provenance[$name] = $record;
        }
        $unmatched = array_diff(array_keys($frozen), array_keys($provenance));
        if ($unmatched !== []) {
            throw new \RuntimeException(
                'duo: frozen adapter source record names manifests absent from the snapshot: '
                . implode(',', $unmatched)
            );
        }
        $unmatchedCertificates = array_diff(array_keys($frozenCertificates), array_keys($certificates));
        if ($unmatchedCertificates !== []) {
            throw new \RuntimeException(
                'duo: frozen adapter source record names certificates absent from its manifests: '
                . implode(',', $unmatchedCertificates)
            );
        }
        ksort($origins, SORT_STRING);
        ksort($provenance, SORT_STRING);
        ksort($certificates, SORT_STRING);
        ksort($claims, SORT_STRING);
        return new self($origins, $provenance, $certificates, $claims, (string) $format);
    }

    /**
     * JSON object keys are identity-bearing here. json_decode(..., true)
     * silently turns an all-digit key into an integer, so reject it before a
     * lookup/diff can make the wire representation and the PHP map disagree.
     */
    private static function assert_identity_map_keys(array $map, string $label): void {
        foreach (array_keys($map) as $name) {
            if (!is_string($name)) {
                throw new \RuntimeException(
                    'duo: ' . $label . ' key ' . self::render($name)
                    . ' is not a string canonical identity — numeric-only identities are forbidden because PHP '
                    . 'coerces JSON object-map keys to integers'
                );
            }
            self::assert_name($name, $label . ' key');
        }
    }

    /**
     * The frozen path re-validates, it does not trust — so both fields that
     * carry meaning are PROVED here rather than pattern-matched:
     *
     * - `path` must be exactly the one path discovery could have produced for
     *   this record's own key. A prefix test ("starts with adapters/") accepts
     *   `adapters/../../../etc/x.json`; deriving the expected string from
     *   $name instead leaves no traversal to express. The explicit `..`/
     *   separator guard on $name closes the same escape one level up, where
     *   the name itself is the site-controlled half.
     * - `sha256` must be the canonical hash of the manifest this record is
     *   attached to, which is recomputable here precisely because
     *   provenance_record() hashes canonical content rather than file bytes.
     *
     * A PLUGIN record derives its path from the frozen manifest's own `plugin`
     * claim instead of from the name — which is precisely what the live
     * anchor rule exists to make possible, and why DUO-3339 needed no new wire
     * key and no snapshot format bump: `plugins/<dir>/duo-adapter.json` is a
     * function of a claim the manifest already carries and the scan already
     * proved against the plugin that bundled it. assert_plugin_basename()
     * closes traversal, absolute paths, backslashes, and depth, exactly as it
     * does for a provider declaration; a bundled record whose manifest omits
     * `plugin` has no derivable path at all and is refused here.
     */
    private static function validate_frozen_record(
        string $name,
        $record,
        array $manifest,
        string $recordFormat = self::FORMAT
    ): void {
        $provenance = is_array($record) ? ($record['provenance'] ?? null) : null;
        $keys = is_array($record) ? array_keys($record) : [];
        $provenanceKeys = is_array($provenance) ? array_keys($provenance) : [];
        sort($keys, SORT_STRING);
        sort($provenanceKeys, SORT_STRING);
        // Legacy v1 predates the plugin source entirely, so it stays a
        // site-only wire: a v1 snapshot claiming a bundled adapter is a
        // snapshot no version of this engine ever wrote.
        $allowedSources = $recordFormat === self::LEGACY_FORMAT
            ? [self::SITE]
            : [self::SITE, self::PLUGIN];
        if ($keys !== ['certification', 'provenance', 'reason', 'status', 'trust_tier']
            || $record['certification'] !== 'uncertified'
            || $record['status'] !== 'uncertified'
            || !is_string($record['reason'] ?? null) || trim((string) $record['reason']) === ''
            || !in_array($record['trust_tier'] ?? null, [
                self::TIER_DECLARATIVE,
                self::TIER_NATIVE_ACTION,
                self::TIER_PLUGIN_PROVIDER,
            ], true)
            || $provenanceKeys !== ['format', 'path', 'sha256', 'source']
            || ($provenance['format'] ?? null) !== $recordFormat
            || !in_array($provenance['source'] ?? null, $allowedSources, true)
            || !is_string($provenance['path'] ?? null)
            || preg_match('/^[0-9a-f]{64}$/D', (string) ($provenance['sha256'] ?? '')) !== 1) {
            throw new \RuntimeException("duo: frozen adapter source record for '$name' is malformed");
        }
        self::assert_name($name, 'frozen adapter source record name');
        if (($provenance['source'] ?? null) === self::PLUGIN) {
            $basename = self::assert_plugin_basename(
                $manifest['plugin'] ?? null,
                "frozen plugin-bundled adapter '$name' plugin"
            );
            $pluginDir = dirname($basename);
            if ($pluginDir === '.' || $pluginDir === '' || str_contains($pluginDir, '/')) {
                throw new \RuntimeException(
                    "duo: frozen adapter source record for '$name' claims a plugin source, but its manifest names "
                    . "the single-file plugin '$basename', which has no directory of its own to bundle an adapter in"
                );
            }
            $expected = self::PLUGIN_PATH_PREFIX . '/' . $pluginDir . '/' . self::PLUGIN_FILE;
        } else {
            $expected = self::SITE_DIR . '/' . $name . '.json';
        }
        if (!hash_equals($expected, (string) $provenance['path'])) {
            throw new \RuntimeException(
                "duo: frozen adapter source record for '$name' declares path "
                . self::render((string) $provenance['path']) . " but the only path this record can describe is "
                . "'$expected'"
            );
        }
        $actual = hash('sha256', Canon::encode($manifest));
        if (!hash_equals($actual, (string) $provenance['sha256'])) {
            throw new \RuntimeException(
                "duo: frozen adapter source record for '$name' does not describe its own manifest: recorded "
                . "content hash {$provenance['sha256']}, actual $actual"
            );
        }
    }
}

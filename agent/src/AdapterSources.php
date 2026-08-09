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
 * This class adds a SECOND source rather than loosening the first. A site repo
 * may carry `adapters/<name>.json`; those manifests OVERLAY the shipped set —
 * they are additional pinnable adapters, never replacements. The shipped
 * directory's coverage check is untouched, so shipped claims keep proving
 * themselves against exactly the bytes they always did, and a repository with
 * no `adapters/` directory takes no new code path at all.
 *
 * Three properties are load-bearing, all enforced before any manifest reaches a
 * policy consumer or a target:
 *
 * 1. **Identity is unambiguous.** A site adapter whose file name collides with a
 *    shipped manifest, or whose declared `name` disagrees with its own file
 *    name, or which collides with a shipped manifest's declared name, is a loud
 *    refusal. Shadowing a shipped adapter is never silent replacement — pin
 *    order must never be what decides which adapter definition wins.
 *
 * 2. **A data-only manifest acquires no executable privileges.** The three
 *    channels that load PHP by name — `interpreter`, `regen_dependency.
 *    regenerator`, and `providers[].source: "manifest"` — all resolve inside
 *    the agent's own manifest directory (Policy::interpreters(),
 *    Policy::regenerators(), Providers::load()). An out-of-tree manifest naming
 *    one of them would either reach shipped bytes it does not own or resolve to
 *    nothing, so it is refused with the remediation instead. `source: "plugin"`
 *    providers stay available: their trust anchor is the installed plugin, named
 *    explicitly and version-bounded, which is a trust decision the operator
 *    already made by installing that plugin.
 *
 * 3. **Certification is external.** With no signed companion a site adapter
 *    carries the synthesized `uncertified` record below — a fourth status word
 *    which cannot be pasted into dispositions.json as self-certification. A
 *    companion under adapters/certifications is accepted only when its
 *    disposition and complete evidence are signed by a key in the agent-owned
 *    authority registry. Either record joins the adapter digest through the
 *    same `disposition` slot a shipped reviewed entry uses, so source/evidence
 *    changes move only that site adapter's identity; shipped rows remain byte-
 *    for-byte unchanged.
 */
final class AdapterSources {
    /** v2 adds externally signed certification envelopes for site adapters. */
    public const FORMAT = 'duo-adapter-sources/v2';
    public const LEGACY_FORMAT = 'duo-adapter-sources/v1';

    /** The agent's own manifest library — the historical single source. */
    public const SHIPPED = 'shipped';
    /** `<repo>/adapters/` — installed by the site, travels in the site repo. */
    public const SITE = 'site';

    public const SITE_DIR = 'adapters';
    public const CERTIFICATION_DIR = 'certifications';

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

    private function __construct(
        array $origins,
        array $provenance,
        array $certificates = [],
        array $claims = [],
        string $wireFormat = self::FORMAT
    ) {
        $this->origins = $origins;
        $this->provenance = $provenance;
        $this->certificates = $certificates;
        $this->claims = $claims;
        $this->wireFormat = $wireFormat;
    }

    /**
     * Scan every installed source and refuse ambiguity before a single pin is
     * resolved. Whole-directory rather than pin-scoped on purpose: an adapter
     * that shadows a shipped one is a broken installation whether or not this
     * particular site.duo.json happens to pin it, and the operator should learn
     * that from the next command rather than from the first command that pins
     * it. Same discipline ManifestDispositions::load() applies to the shipped
     * directory.
     */
    public static function discover(string $manifestDir, ?string $repo): self {
        $refusals = [];
        $scan = self::scan($manifestDir, $repo, false, $refusals);
        return new self($scan['origins'], $scan['provenance'], $scan['certificates'], $scan['claims']);
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
     * The two modes diverge in exactly two places, both stated at their site:
     * a refused site file is SKIPPED rather than aborting the walk, and
     * collect mode decodes every shipped manifest up front (throw mode still
     * decodes them only when a site source exists — see declared_names()).
     *
     * @param array<int, array<string,mixed>> $refusals collected in $collect mode
     * @return array{origins:array<string,array>, provenance:array<string,array>, manifests:array<string,array>}
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
                try {
                    $manifests[$name] = Canon::decode(Canon::read_file($origin['file']));
                } catch (\Throwable $t) {
                    self::refuse(
                        $collect,
                        $refusals,
                        self::REFUSAL_MALFORMED_MANIFEST,
                        [(string) $origin['path']],
                        "duo: shipped adapter '{$origin['path']}' cannot be read as a manifest: " . $t->getMessage(),
                        'repair the file, or restore it from the agent release this library shipped with'
                    );
                    unset($origins[$name]);
                }
            }
        }
        if ($repo === null) {
            return [
                'certificates' => [],
                'claims' => [],
                'manifests' => $manifests,
                'origins' => $origins,
                'provenance' => [],
            ];
        }
        $siteDir = rtrim($repo, '/') . '/' . self::SITE_DIR;
        if (!is_dir($siteDir)) {
            return [
                'certificates' => [],
                'claims' => [],
                'manifests' => $manifests,
                'origins' => $origins,
                'provenance' => [],
            ];
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
            self::refuse(
                $collect,
                $refusals,
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
            return [
                'certificates' => [],
                'claims' => [],
                'manifests' => $manifests,
                'origins' => $origins,
                'provenance' => [],
            ];
        }

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

        $shippedNames = self::declared_names($origins, $manifests, $collect);
        $siteFiles = glob($siteDir . '/*.json') ?: [];
        sort($siteFiles, SORT_STRING);
        $certificateFiles = [];
        self::guarded(
            $collect,
            $refusals,
            self::REFUSAL_CERTIFICATION_SOURCE,
            [self::SITE_DIR . '/' . self::CERTIFICATION_DIR],
            'give every certificate an exact companion ' . self::SITE_DIR
                . '/<name>.json, and choose names that are distinct without relying on letter case',
            static function () use ($siteDir, $siteFiles, &$certificateFiles): void {
                $certificateFiles = self::certification_files($siteDir, $siteFiles);
            }
        );
        $provenance = [];
        $certificates = [];
        $claims = [];
        foreach ($siteFiles as $file) {
            $name = basename($file, '.json');
            $relative = self::SITE_DIR . '/' . basename($file);
            if (!self::guarded(
                $collect,
                $refusals,
                self::REFUSAL_INVALID_NAME,
                [$relative],
                'rename the file to a canonical lowercase ASCII slug',
                static fn() => self::assert_name($name, "site adapter '$relative'")
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
                    self::REFUSAL_SHADOWS_SHIPPED,
                    [$relative, (string) $collision['path']],
                    "duo: site adapter '$relative' shadows the shipped adapter '$name' ({$collision['file']}) — "
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
            try {
                $manifest = Canon::decode(Canon::read_file($file));
            } catch (\Throwable $t) {
                self::refuse(
                    $collect,
                    $refusals,
                    self::REFUSAL_MALFORMED_MANIFEST,
                    [$relative],
                    "duo: site adapter '$relative' cannot be read as a manifest: " . $t->getMessage(),
                    'repair the file so it decodes as a JSON object, or remove it from ' . self::SITE_DIR . '/'
                );
                continue;
            }
            $declared = $manifest['name'] ?? null;
            if (!is_string($declared) || $declared !== $name) {
                self::refuse(
                    $collect,
                    $refusals,
                    self::REFUSAL_AMBIGUOUS_IDENTITY,
                    [$relative],
                    "duo: site adapter '$relative' declares name " . self::render($declared)
                    . ' but its file name is ' . self::render($name) . ' — a pin names the file while every '
                    . 'downstream identity (dispositions, digests, diagnostics) keys off the declared name, so the '
                    . 'two disagreeing is ambiguous identity. Make the declared name match the file name',
                    'make the declared name match the file name'
                );
                continue;
            }
            if (isset($shippedNames[$name])) {
                self::refuse(
                    $collect,
                    $refusals,
                    self::REFUSAL_NAME_COLLISION,
                    [$relative, self::SHIPPED . ':' . $shippedNames[$name]],
                    "duo: site adapter '$relative' claims the name '$name', already declared by the shipped manifest "
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
        ksort($origins, SORT_STRING);
        ksort($provenance, SORT_STRING);
        ksort($certificates, SORT_STRING);
        ksort($claims, SORT_STRING);
        ksort($manifests, SORT_STRING);
        return [
            'certificates' => $certificates,
            'claims' => $claims,
            'manifests' => $manifests,
            'origins' => $origins,
            'provenance' => $provenance,
        ];
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
     * @param list<string> $paths every file or directory the condition is about
     */
    private static function refuse(
        bool $collect,
        array &$refusals,
        string $code,
        array $paths,
        string $message,
        string $remediation
    ): void {
        if (!$collect) {
            throw new \RuntimeException($message);
        }
        $row = [
            'code' => $code,
            'message' => $message,
            'paths' => array_values($paths),
            'remediation' => $remediation,
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
                return;
            }
        }
        $refusals[] = $row;
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
     * @return bool false when the assertion refused (collect mode only)
     */
    private static function guarded(
        bool $collect,
        array &$refusals,
        string $code,
        array $paths,
        string $remediation,
        callable $assert
    ): bool {
        try {
            $assert();
            return true;
        } catch (\Throwable $t) {
            if (!$collect) {
                throw $t;
            }
            self::refuse($collect, $refusals, $code, $paths, $t->getMessage(), $remediation);
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
     * @param ?string $repo site repository whose adapters/ source also counts
     * @return array{adapters:list<array<string,mixed>>, refusals:list<array<string,mixed>>}
     */
    public static function survey(?string $repo): array {
        $manifestDir = Policy::manifests_dir();
        $refusals = [];
        $scan = self::scan($manifestDir, $repo, true, $refusals);

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
        $sources = new self($scan['origins'], $scan['provenance'], $scan['certificates'], $scan['claims']);
        $sources->bind_explicit_pins(self::surveyed_pins($repo));

        $adapters = [];
        foreach ($scan['origins'] as $name => $origin) {
            $name = (string) $name;
            $manifest = $scan['manifests'][$name] ?? [];
            $outOfTree = isset($scan['provenance'][$name]);
            $entry = $dispositions === null || $outOfTree ? null : $dispositions->entry($name);
            $tier = self::tier_decision($manifest);
            $adapters[] = [
                'certification' => $outOfTree
                    ? ($sources->is_certified($name)
                        ? (!empty($sources->explicitPins[$name]) ? 'third_party_signed' : 'signed_unpinned')
                        : 'uncertified')
                    : 'registry',
                'disposition_status' => is_array($entry) ? (string) ($entry['status'] ?? '') : null,
                'executable_surfaces' => self::executable_surfaces($manifest),
                'grammar' => self::grammar_verdict($name, $outOfTree, $repo, $refusals),
                'name' => $name,
                'path' => (string) $origin['path'],
                'required_providers' => self::required_providers($manifest),
                // The canonical manifest hash, exactly the basis
                // provenance_record() uses — so a survey row and a frozen
                // provenance record describe one adapter with one number,
                // rather than a file-byte hash here and a content hash there.
                'sha256' => hash('sha256', Canon::encode($manifest)),
                'source' => (string) $origin['source'],
                'tier_basis' => $tier['tier_basis'],
                'trust_tier' => $tier['trust_tier'],
            ];
        }

        return ['adapters' => $adapters, 'refusals' => $refusals];
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
     * Which repo the load gets is not cosmetic. A shipped adapter is loaded
     * WITHOUT the site half as soon as the site source has any refusal at all,
     * because discover() refuses whole-directory: with a shadowed pair
     * installed, every unrelated shipped adapter would otherwise report that
     * one site file's refusal as its own grammar error, which is both true and
     * useless. A site adapter has no such fallback — it exists only in that
     * repository — so it reports the engine's exact words, refusal included.
     *
     * @param list<array<string,mixed>> $refusals refusals this survey already collected
     * @return array{status:string, message:?string}
     */
    private static function grammar_verdict(string $name, bool $outOfTree, ?string $repo, array $refusals): array {
        try {
            Policy::load($outOfTree || $refusals === [] ? $repo : null, [$name]);
            return ['message' => null, 'status' => 'ok'];
        } catch (\Throwable $t) {
            return ['message' => $t->getMessage(), 'status' => 'error'];
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
                    self::REFUSAL_INVALID_NAME,
                    [self::SITE_DIR . '/' . $entry],
                    'rename the file to a canonical lowercase ASCII slug',
                    static fn() => self::assert_name(basename($entry, '.json'), "site adapter '$entry'")
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
                "site adapter certificate '" . self::SITE_DIR . '/' . self::CERTIFICATION_DIR . "/$entry'"
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
     */
    private static function render($value): string {
        if (!is_string($value)) {
            return var_export($value, true);
        }
        $printable = preg_match('/^[\x20-\x7e]*$/D', $value) === 1;
        return "'" . $value . "'" . ($printable ? '' : ' (hex ' . bin2hex($value) . ')');
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
    private static function declared_names(array $origins, array $manifests, bool $collect): array {
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
                self::assert_name($declared, "shipped adapter '$file' declared name");
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
     */
    private static function provenance_record(
        string $name,
        string $relativePath,
        array $manifest
    ): array {
        $sha256 = hash('sha256', Canon::encode($manifest));
        return [
            'certification' => 'uncertified',
            'provenance' => [
                'format' => self::FORMAT,
                'path' => $relativePath,
                'sha256' => $sha256,
                'source' => self::SITE,
            ],
            'reason' => "adapter '$name' is installed out-of-tree from the site repository's "
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
     * The out-of-tree privilege boundary. Each refused channel resolves its
     * file inside the agent's own manifest directory, so an out-of-tree
     * manifest naming one would reach bytes it does not own (or nothing at
     * all). Refusing here, at load, keeps that from becoming either a silent
     * no-op or a cross-source code load.
     */
    public static function assert_out_of_tree_contract(array $manifest, string $name, string $relativePath): void {
        $remedy = "install the adapter into the agent's own manifest library (where its code ships, digest-binds, and "
            . 'is reviewed with it), or declare a plugin-owned provider whose code the installed plugin already owns';
        foreach ([
            'adapter_certificate', 'authority', 'authority_id', 'certificate', 'certification',
            'certification_authority', 'disposition', 'evidence', 'key_id', 'public_key', 'signature', 'trust_tier',
        ] as $reserved) {
            if (array_key_exists($reserved, $manifest)) {
                throw new \RuntimeException(
                    "duo: site adapter '$relativePath' declares reserved authority field '$reserved' — a data-only "
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
                "duo: site adapter '$relativePath' declares interpreter "
                . var_export($manifest['interpreter'], true) . ", but interpreter code loads only from the agent's "
                . 'manifest library — an out-of-tree manifest is data and acquires no executable privileges. '
                . "Remediation: $remedy"
            );
        }
        foreach ((array) ($manifest['post_types'] ?? []) as $postType => $declaration) {
            $regenerator = is_array($declaration) ? ($declaration['regen_dependency']['regenerator'] ?? null) : null;
            if ($regenerator !== null) {
                throw new \RuntimeException(
                    "duo: site adapter '$relativePath' post_types.$postType declares regenerator "
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
                    "site adapter '$relativePath' providers[$i].plugin"
                );
            }
            if (is_array($declaration) && ($declaration['source'] ?? null) === 'manifest') {
                throw new \RuntimeException(
                    "duo: site adapter '$relativePath' providers[$i] declares source \"manifest\", which resolves to "
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
                "duo: site adapter '$relativePath' reaches the $tier trust tier, which an out-of-tree adapter cannot "
                . "hold. Remediation: $remedy"
            );
        }
    }

    /** The file backing one adapter name, or a refusal naming every searched source. */
    public function file(string $name, string $manifestDir): string {
        $origin = $this->origins[$name] ?? null;
        if ($origin === null) {
            $sources = "$manifestDir (shipped)";
            if ($this->provenance !== []) {
                $sources .= " or the site repository's " . self::SITE_DIR . '/ directory';
            }
            throw new \RuntimeException("duo: manifest '$name' not found in $sources");
        }
        return $origin['file'];
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
            $rows[$name] = [
                'certification' => $record === null
                    ? 'registry'
                    : ($signed ? ($explicit ? 'third_party_signed' : 'signed_unpinned') : 'uncertified'),
                'path' => $this->path($name),
                'remediation' => $record === null
                    ? ''
                    : ($signed
                        ? ($explicit
                            ? ''
                            : ('review the signed evidence, then replace this name-only pin with the exact '
                                . '{name,source:"site",digest} object emitted by `wp duo manifest-pin --repo=...`'))
                        : ('obtain an externally signed certificate from an authority trusted by this agent, or '
                            . 'keep it as uncertified support — plan and apply remain available, while readiness '
                            . 'and host promotion stay blocked')),
                'source' => $this->source($name),
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
            $certificate = $frozenCertificates[$name] ?? null;
            if ($certificate === null) {
                self::validate_frozen_record($name, $record, $manifest, $legacy ? self::LEGACY_FORMAT : self::FORMAT);
            } else {
                if ($legacy) {
                    throw new \RuntimeException("duo: legacy frozen adapter source '$name' cannot carry a certificate");
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
            $path = self::SITE_DIR . '/' . $name . '.json';
            self::assert_out_of_tree_contract($manifest, $name, $path);
            if (($record['trust_tier'] ?? null) !== self::trust_tier($manifest)) {
                throw new \RuntimeException(
                    "duo: frozen adapter source record for '$name' claims trust tier '{$record['trust_tier']}' but "
                    . 'its manifest reaches ' . self::trust_tier($manifest)
                );
            }
            $origins[$name] = [
                'source' => self::SITE,
                'file' => (string) $record['provenance']['path'],
                'path' => (string) $record['provenance']['path'],
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
            || ($provenance['source'] ?? null) !== self::SITE
            || !is_string($provenance['path'] ?? null)
            || preg_match('/^[0-9a-f]{64}$/D', (string) ($provenance['sha256'] ?? '')) !== 1) {
            throw new \RuntimeException("duo: frozen adapter source record for '$name' is malformed");
        }
        self::assert_name($name, 'frozen adapter source record name');
        $expected = self::SITE_DIR . '/' . $name . '.json';
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

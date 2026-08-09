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
 * 3. **Out-of-tree is uncertified by construction.** A site adapter gets no
 *    disposition entry and no capability-registry claim; it carries the
 *    synthesized provenance record below instead, whose `status` is the fourth
 *    word `uncertified` — deliberately outside the reviewed
 *    certified/experimental/excluded vocabulary, so pasting one of these records
 *    into dispositions.json is refused by ManifestDispositions::validate_entry()
 *    rather than accepted as a self-certification. The record joins the adapter
 *    digest through the same `disposition` slot a reviewed entry uses
 *    (RepositoryCompiler::manifest_rows()), so an out-of-tree adapter's identity
 *    binds its origin, while every shipped row hashes byte-for-byte as before.
 */
final class AdapterSources {
    public const FORMAT = 'duo-adapter-sources/v1';

    /** The agent's own manifest library — the historical single source. */
    public const SHIPPED = 'shipped';
    /** `<repo>/adapters/` — installed by the site, travels in the site repo. */
    public const SITE = 'site';

    public const SITE_DIR = 'adapters';

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

    /** @var array<string, array{source:string, file:string, path:string}> */
    private array $origins;
    /** @var array<string, array> synthesized disposition per out-of-tree name */
    private array $provenance;

    private function __construct(array $origins, array $provenance) {
        $this->origins = $origins;
        $this->provenance = $provenance;
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
        $origins = [];
        foreach (glob(rtrim($manifestDir, '/') . '/*.json') ?: [] as $file) {
            $name = basename($file, '.json');
            if ($name === 'dispositions') {
                continue;
            }
            $origins[$name] = ['source' => self::SHIPPED, 'file' => $file, 'path' => $file];
        }
        ksort($origins, SORT_STRING);
        if ($repo === null) {
            return new self($origins, []);
        }
        $siteDir = rtrim($repo, '/') . '/' . self::SITE_DIR;
        if (!is_dir($siteDir)) {
            return new self($origins, []);
        }

        // A site-local source that ships ratification data is asserting an
        // authority it does not have. These files/directories are inert (only
        // the agent's own manifest directory is ever read for them), and inert
        // is exactly the failure mode to refuse: an operator who wrote them
        // believes their adapter is certified, or believes their interpreter
        // will run. Say so instead of ignoring the bytes.
        foreach (['dispositions.json' => 'certification data', 'capabilities' => 'capability registry data'] as $entry => $what) {
            if (file_exists($siteDir . '/' . $entry)) {
                throw new \RuntimeException(
                    "duo: site adapter source $siteDir contains $entry — a site-local adapter source cannot supply "
                    . "$what for itself. Certification is external review evidence held with the agent's own "
                    . 'manifest library; remove the file and treat these adapters as uncertified support'
                );
            }
        }
        foreach (['interpreters', 'providers', 'regenerators'] as $codeDir) {
            if (is_dir($siteDir . '/' . $codeDir)) {
                throw new \RuntimeException(
                    "duo: site adapter source $siteDir contains $codeDir/, which the engine never loads — "
                    . 'out-of-tree adapters are data only. Install the adapter into the agent manifest library if it '
                    . 'genuinely needs to ship executable code, or declare a plugin-owned provider instead'
                );
            }
        }

        $shippedNames = self::declared_names($origins);
        $siteFiles = glob($siteDir . '/*.json') ?: [];
        sort($siteFiles, SORT_STRING);
        $provenance = [];
        foreach ($siteFiles as $file) {
            $name = basename($file, '.json');
            $relative = self::SITE_DIR . '/' . basename($file);
            if (isset($origins[$name])) {
                throw new \RuntimeException(
                    "duo: site adapter '$relative' shadows the shipped adapter '$name' ({$origins[$name]['file']}) — "
                    . 'an out-of-tree adapter overlays the shipped set, it never replaces a member of it. Rename the '
                    . 'site adapter, or remove it and pin the shipped adapter'
                );
            }
            $manifest = Canon::decode(Canon::read_file($file));
            $declared = $manifest['name'] ?? null;
            if (!is_string($declared) || $declared !== $name) {
                throw new \RuntimeException(
                    "duo: site adapter '$relative' declares name " . var_export($declared, true)
                    . " but its file name is '$name' — a pin names the file while every downstream identity "
                    . '(dispositions, digests, diagnostics) keys off the declared name, so the two disagreeing is '
                    . 'ambiguous identity. Make the declared name match the file name'
                );
            }
            if (isset($shippedNames[$name])) {
                throw new \RuntimeException(
                    "duo: site adapter '$relative' claims the name '$name', already declared by the shipped manifest "
                    . "'{$shippedNames[$name]}' — two adapters cannot answer to one name"
                );
            }
            $origins[$name] = ['source' => self::SITE, 'file' => $file, 'path' => $relative];
            $provenance[$name] = self::provenance_record($name, $relative, hash_file('sha256', $file), $manifest);
        }
        ksort($origins, SORT_STRING);
        ksort($provenance, SORT_STRING);
        return new self($origins, $provenance);
    }

    /**
     * Shipped manifests are decoded only when a site source actually exists.
     * The declared-name collision check is the only thing that needs them, and
     * a repository with no `adapters/` directory must pay no new I/O and take
     * no new refusal path.
     *
     * @return array<string, string> declared name => file name that declares it
     */
    private static function declared_names(array $origins): array {
        $names = [];
        foreach ($origins as $file => $origin) {
            $manifest = Canon::decode(Canon::read_file($origin['file']));
            $declared = $manifest['name'] ?? null;
            if (is_string($declared) && $declared !== '') {
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
     */
    private static function provenance_record(
        string $name,
        string $relativePath,
        string $sha256,
        array $manifest
    ): array {
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
        if (is_string($manifest['interpreter'] ?? null) && $manifest['interpreter'] !== '') {
            return self::TIER_COMPATIBILITY_SHIM;
        }
        foreach ((array) ($manifest['post_types'] ?? []) as $declaration) {
            if (is_array($declaration) && isset($declaration['regen_dependency']['regenerator'])) {
                return self::TIER_COMPATIBILITY_SHIM;
            }
        }
        $tier = self::TIER_DECLARATIVE;
        foreach ((array) ($manifest['providers'] ?? []) as $declaration) {
            if (!is_array($declaration)) {
                continue;
            }
            if (($declaration['source'] ?? null) === 'manifest') {
                return self::TIER_COMPATIBILITY_SHIM;
            }
            $tier = self::TIER_PLUGIN_PROVIDER;
        }
        if ($tier === self::TIER_DECLARATIVE) {
            foreach ((array) ($manifest['actions'] ?? []) as $action) {
                if (is_array($action) && ($action['kind'] ?? null) === 'native') {
                    $tier = self::TIER_NATIVE_ACTION;
                    break;
                }
            }
        }
        return $tier;
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
        $interpreter = $manifest['interpreter'] ?? null;
        if (is_string($interpreter) && $interpreter !== '') {
            throw new \RuntimeException(
                "duo: site adapter '$relativePath' declares interpreter '$interpreter', but interpreter code loads "
                . "only from the agent's manifest library — an out-of-tree manifest is data and acquires no "
                . "executable privileges. Remediation: $remedy"
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
            $rows[$name] = [
                'certification' => $record === null ? 'registry' : 'uncertified',
                'path' => $this->path($name),
                'remediation' => $record === null
                    ? ''
                    : ("ratify this adapter in the agent's own manifest library to certify it, or keep it as "
                        . 'uncertified support — plan and apply remain available, while readiness and host '
                        . 'promotion stay blocked for as long as it is pinned'),
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
     * out-of-tree records travel: a name absent from this map is shipped, and
     * from_snapshot() proves that by requiring the shipped manifest directory to
     * still hold it, so "absent" can never be a way to launder a site adapter
     * into looking shipped.
     */
    public function export(): array {
        return ['format' => self::FORMAT, 'out_of_tree' => $this->provenance];
    }

    /**
     * Rebuild frozen provenance and re-prove the out-of-tree contract against
     * the frozen manifest bytes — the snapshot path re-validates, it never
     * trusts.
     *
     * Deliberately no filesystem probe for the shipped side: this method exists
     * to reconstruct a policy "without reopening mutable repository files"
     * (Policy::export_snapshot()), and two existing bindings already close the
     * laundering path a probe would guard. Dropping an `out_of_tree` entry to
     * make a site adapter look shipped (1) hands that manifest to
     * ManifestDispositions/CapabilityRegistry::from_snapshot(), which refuse a
     * manifest with no reviewed entry and no claim, and (2) changes the
     * adapter digest, because manifest_rows() folds the provenance record into
     * the very hash the compiled artifact independently binds. Editing a record
     * in place fails the same way through validate_frozen_record() and the
     * digest.
     */
    public static function from_snapshot(array $data, array $manifests): self {
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        if ($keys !== ['format', 'out_of_tree']
            || ($data['format'] ?? null) !== self::FORMAT
            || !is_array($data['out_of_tree'] ?? null)
            || (array_is_list($data['out_of_tree']) && $data['out_of_tree'] !== [])) {
            throw new \RuntimeException('duo: frozen adapter source record is malformed');
        }
        $frozen = $data['out_of_tree'];
        $origins = [];
        $provenance = [];
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '');
            $record = $frozen[$name] ?? null;
            if ($record === null) {
                $file = rtrim(Policy::manifests_dir(), '/') . '/' . basename($name) . '.json';
                $origins[$name] = ['source' => self::SHIPPED, 'file' => $file, 'path' => $file];
                continue;
            }
            self::validate_frozen_record($name, $record);
            self::assert_out_of_tree_contract($manifest, $name, (string) $record['provenance']['path']);
            if ($record['trust_tier'] !== self::trust_tier($manifest)) {
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
        ksort($origins, SORT_STRING);
        ksort($provenance, SORT_STRING);
        return new self($origins, $provenance);
    }

    private static function validate_frozen_record(string $name, $record): void {
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
            || ($provenance['format'] ?? null) !== self::FORMAT
            || ($provenance['source'] ?? null) !== self::SITE
            || !is_string($provenance['path'] ?? null)
            || !str_starts_with((string) $provenance['path'], self::SITE_DIR . '/')
            || preg_match('/^[0-9a-f]{64}$/D', (string) ($provenance['sha256'] ?? '')) !== 1) {
            throw new \RuntimeException("duo: frozen adapter source record for '$name' is malformed");
        }
    }
}

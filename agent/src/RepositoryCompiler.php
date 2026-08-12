<?php
namespace Duo;

require_once __DIR__ . '/CodeCompatibility.php';
require_once __DIR__ . '/ReferenceGraph.php';
// DUO-3348 slice 2: CompiledRepository and RepositoryCompilationException moved
// to their own file (the "CompiledArtifact value object" target seam). Required
// here, not just left to duo.php's bootstrap order, so every existing caller of
// THIS file keeps getting both classes transitively with no change on its part.
require_once __DIR__ . '/CompiledArtifact.php';

/**
 * Deterministic offline compiler: repository files + pinned policy artifacts
 * become one validated IR before Tokens, Ledger, Capture, or a target query
 * can be constructed. It owns parsing, schema validation, identity/natural-
 * key uniqueness, graph closure, media safety, conflict markers, adapter
 * constraints, and DUO-3203's active-policy authorization pass.
 */
final class RepositoryCompiler {
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
    private const CONFLICT_RE = '/^(<{7}|={7}|>{7})(?: .*|)$/m';

    private string $repo;
    private string $stateDir;
    private Policy $policy;
    // DUO-3287: this compiler serves two structurally different callers.
    // Most (Apply, Deploy, Cli's verify command, RepositoryAuthorization,
    // IdentityBackup) compile the tree they are about to ACT on — apply it,
    // deploy it, export it, authorize it — under the CURRENT policy, and
    // that tree genuinely must be complete against that policy right now;
    // an option authored_options() requires with no record at all really is
    // a hole. But two callers (Capture::run()'s own pre-flight compile of
    // the PREVIOUS revision, purely to harvest deletion-reconciliation
    // data; Capture::snapshot()'s non-minting drift-check path, which
    // Apply::build_plan() also uses) are reading a tree that was captured
    // under whatever policy was active AT THAT TIME, specifically so it can
    // be diffed against live/new state — and the current policy is often
    // wider than the one that produced it, e.g. a manifest was added to
    // site.duo.json since the last capture. Demanding CURRENT-policy
    // completeness from a HISTORICAL revision doesn't detect corruption;
    // it makes ordinary incremental adoption (start narrow, expand
    // coverage later, the entire premise of DUO-3257) impossible — verified
    // live: every required-option-missing name across 13-17 diagnostics
    // was a genuinely NEW authored-exact option the previous revision had
    // never seen, not a hole in an already-known one. That can include the
    // code declaration itself: a historical state-only revision necessarily
    // has no explicit lifecycle intent for the current code payload. A
    // descriptor needs that intent only when an artifact can be staged,
    // finalized, or applied; a comparison artifact never gets that authority.
    //
    // $completenessOptional therefore narrows historical comparison mode to
    // two current-action checks: the "every required name needs some record"
    // gate in parse_entity()'s options branch, and the CodeStateContract
    // lifecycle bridge below. It does NOT skip Code::compile() itself, so
    // current code config/source/descriptor validation, malformed JSON,
    // invalid record shapes, illegitimate tombstones, conflict markers,
    // identity uniqueness, media integrity, and every other historical
    // validation stay fully active. A genuinely corrupted historical
    // revision is still caught exactly as before.
    private bool $completenessOptional;
    /** @var array<int,array<string,mixed>> */
    private array $diagnostics = [];
    /** @var array<string,array{kind:string,path:string}> */
    private array $identities = [];
    /** @var array<string,array<string,mixed>> */
    private array $deletions = [];
    /** @var array<string,string> upload-relative path => source path */
    private array $uploadPaths = [];
    /** @var array<string,string> derivative directory/basename prefix => source path */
    private array $uploadDerivativeRoots = [];
    /** @var array<string,array{sha256:string,base64:string}> media blob => immutable payload */
    private array $media = [];
    /** @var array<string,string> every content-addressed blob in media/, including safe orphans */
    private array $mediaCatalog = [];
    /** Exact media directory used for this compilation. Normally repo/media;
     * scoped transaction probes may supply an immutable byte-for-byte view. */
    private string $mediaDir;

    private function __construct(
        string $stateDir,
        string $mediaRoot,
        Policy $policy,
        bool $completenessOptional = false,
        ?string $mediaDir = null
    ) {
        $this->stateDir = rtrim($stateDir, '/');
        $this->repo = rtrim($mediaRoot, '/'); // media/ root only — see compile_staged()'s docblock for why this can differ from stateDir's own parent
        $this->mediaDir = $mediaDir === null ? $this->repo . '/media' : rtrim($mediaDir, '/');
        $this->policy = $policy;
        $this->completenessOptional = $completenessOptional;
    }

    public static function compile(string $repo, Policy $policy): CompiledRepository {
        $repo = rtrim($repo, '/');
        return self::compile_staged($repo . '/state', $repo, $policy);
    }

    /**
     * DUO-3287: same as compile(), for the two callers reading a
     * historical/comparison revision rather than one about to be acted on
     * — see $completenessOptional's own docblock above for the exact
     * distinction and why it must not apply to every caller.
     */
    public static function compile_for_diff(
        string $repo,
        Policy $policy,
        ?string $mediaDir = null
    ): CompiledRepository {
        $repo = rtrim($repo, '/');
        $c = new self($repo . '/state', $repo, $policy, true, $mediaDir);
        return $c->run();
    }

    /**
     * DUO-3236: validate an arbitrary staged tree — e.g. Publish's
     * state.capture-staging, before it is ever promoted to state/ — against
     * the repository's REAL media/ directory. This is not a compromise:
     * Publish.php's own class docblock is explicit that media writes are
     * never staged at all — they land in the real repo/media immediately,
     * content-addressed and idempotent, independent of the state-tree swap
     * — so a staged state/ candidate's media references were always meant
     * to resolve against the real media/ directory, exactly like an
     * already-published tree's do. No restructuring of Publish.php's
     * staging layout is needed to make this correct.
     *
     * compile() above is just this method's $stateDir=$repo/state,
     * $mediaRoot=$repo special case, kept behaviorally identical for every
     * existing caller (Apply.php, Cli.php, Deploy.php,
     * RepositoryAuthorization.php, IdentityBackup.php, Capture.php's own
     * previous-revision read) — none of them change.
     */
    public static function compile_staged(
        string $stateDir,
        string $mediaRoot,
        Policy $policy,
        ?string $mediaDir = null
    ): CompiledRepository {
        $c = new self($stateDir, $mediaRoot, $policy, false, $mediaDir);
        return $c->run();
    }

    public static function read_artifact(string $path, Policy $policy): CompiledRepository {
        try {
            $artifact = CompiledRepository::from_array(Canon::decode(Canon::read_file($path)));
        } catch (\Throwable $t) {
            throw self::artifact_exception('compiled_artifact_invalid', $path, $t->getMessage());
        }
        if (!hash_equals(self::site_hash($policy), $artifact->site_hash())) {
            throw self::artifact_exception(
                'compiled_artifact_policy_mismatch', $path,
                'compiled site policy does not match the active site.duo.json'
            );
        }
        if (!hash_equals(self::manifest_hash($policy), $artifact->manifest_hash())) {
            throw self::artifact_exception(
                'compiled_artifact_manifest_mismatch', $path,
                'compiled manifest/interpreter set does not match active pins'
            );
        }
        if (!hash_equals(
            Canon::encode($artifact->effects_inventory()),
            Canon::encode($policy->effects_inventory())
        )) {
            throw self::artifact_exception(
                'compiled_artifact_invalid', $path,
                'compiled effect inventory does not match the active manifest contracts'
            );
        }
        $policyHasCode = $policy->code_config() !== null;
        $artifactHasCode = $artifact->code_descriptor() !== null;
        if ($policyHasCode !== $artifactHasCode) {
            throw self::artifact_exception(
                'compiled_artifact_code_mismatch', $path,
                $policyHasCode
                    ? 'active site policy enables code materialization but this artifact has no code descriptor'
                    : 'active site policy is legacy/state-only but this artifact unexpectedly contains a code descriptor'
            );
        }
        if ($artifactHasCode) {
            if (!class_exists(CodeStateContract::class)) {
                throw self::artifact_exception(
                    'compiled_artifact_code_state_contract_unavailable', $path,
                    'code/state bridge support is not loaded'
                );
            }
            try {
                CodeStateContract::validate($artifact, (array) $artifact->code_descriptor());
            } catch (\Throwable $t) {
                throw self::artifact_exception(
                    'compiled_artifact_code_state_mismatch', $path, $t->getMessage()
                );
            }
        }
        // Artifact consumers need the identical repository-derived schema
        // facts compilation used; never let a loaded artifact make ACF (or
        // a future interpreter) fall back to target-only rows during apply.
        $policy->prime_interpreters_from_repository($artifact->tree());
        return $artifact;
    }

    private static function artifact_exception(string $code, string $path, string $message): RepositoryCompilationException {
        return new RepositoryCompilationException([[
            'severity' => 'blocking', 'code' => $code, 'path' => $path,
            'locator' => '', 'message' => $message,
        ]]);
    }

    public static function site_hash(Policy $policy): string {
        return hash('sha256', Canon::encode($policy->site));
    }

    /**
     * Identity of the site policy that can affect canonical state.
     *
     * The top-level code declaration selects the independent code payload
     * compiler/materializer. The complete declaration remains bound by
     * site_hash() and therefore by the outer compiled artifact, but it must
     * not move revision_hash: enabling or disabling identical code bytes is
     * not a canonical database-state change.
     */
    public static function state_site_hash(Policy $policy): string {
        $site = $policy->site;
        unset($site['code']);
        return hash('sha256', Canon::encode($site));
    }

    /**
     * DUO-3222: the per-manifest content row manifest_hash()/resolved_
     * adapters() both hash — factored out so a per-manifest digest
     * (resolved_adapters() below) and the pre-existing combined hash
     * (manifest_hash()) are provably the SAME content, never two
     * independently-maintained notions of "what identifies this manifest."
     * CapabilityRegistry::adapter_digest() mirrors this row shape (it cannot
     * call in here — the registry loads without a compiled repository), so
     * any key added to this row must be added there in the same change.
     * DUO-3360 made that a pin rather than only a rule: sandbox/tests/
     * regress_actions_providers.php drives this method over every shipped
     * adapter and requires hash('sha256', Canon::encode($row)) — the exact
     * formula resolved_adapters() falls back to without the registry — to
     * equal adapter_digest()'s answer, so a key added to one implementation
     * alone fails offline instead of splitting the two digests silently.
     *
     * @return list<array{name:string, manifest:array, disposition:?array, interpreter?:array{name:string,sha256:?string}, providers?:list<array{id:string,sha256:?string}>, regenerators?:list<array{name:string,sha256:?string}>}>
     */
    private static function manifest_rows(Policy $policy): array {
        $rows = [];
        foreach ($policy->manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '');
            $row = [
                'name' => $name,
                'manifest' => $manifest,
                'disposition' => $policy->manifest_disposition($name),
            ];
            $interpreter = $manifest['interpreter'] ?? null;
            if (is_string($interpreter) && $interpreter !== '') {
                $file = Policy::manifests_dir() . '/interpreters/' . basename($interpreter) . '.php';
                $row['interpreter'] = [
                    'name' => $interpreter,
                    'sha256' => is_file($file) ? hash_file('sha256', $file) : null,
                ];
            }
            // DUO-3338: a manifest-sourced provider is executable code whose
            // identity the manifest asserts, so its bytes join the identity
            // row exactly as an interpreter's do — a changed provider file is
            // a changed adapter, not an invisible drift behind a stable
            // manifest digest. Plugin-sourced providers are deliberately NOT
            // file-hashed here: their trust anchor is the installed plugin
            // itself, whose identity the code half already checks against the
            // manifest's version_range at negotiation time. A missing file
            // hashes as null (Policy::load() has already refused it loudly;
            // this layer only records identity, mirroring the interpreter
            // line above).
            $providerHashes = [];
            foreach ((array) ($manifest['providers'] ?? []) as $declaration) {
                if (!is_array($declaration) || ($declaration['source'] ?? null) !== 'manifest') {
                    continue;
                }
                $id = (string) ($declaration['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $file = Policy::manifests_dir() . '/providers/' . basename($id) . '.php';
                $providerHashes[] = [
                    'id' => $id,
                    'sha256' => is_file($file) ? hash_file('sha256', $file) : null,
                ];
            }
            if ($providerHashes !== []) {
                $row['providers'] = $providerHashes;
            }
            // DUO-3360: a manifest-shipped regenerator is the same trust
            // boundary the two entries above bind — executable code that
            // ships, versions, and pins with its manifest — so its bytes join
            // the identity row for the same reason: a changed regenerator is a
            // changed adapter, not invisible drift behind a stable manifest
            // digest. The entry is keyed by `name` like the interpreter's,
            // because a regen_dependency names a regenerator exactly as a
            // manifest names an interpreter, and resolves through the same
            // <manifests_dir>/regenerators/<name>.php path Policy::
            // regenerators() loads (name grammar refused there and by `duo
            // manifest-validate`; basename() here mirrors the two entries
            // above rather than trusting that check from a distance).
            // Several post_types{} entries may name ONE regenerator, so a
            // name appears once — the same de-duplication Policy::
            // regenerators() performs, so the row binds what the engine can
            // actually load. Policy::regenerators() also de-duplicates ACROSS
            // manifests (one instance per name, whichever manifest reaches it
            // first); that is instance reuse, not shared identity, so every
            // declaring manifest binds the bytes it depends on here. Entries
            // are sorted by NAME, where providers[] keeps its authored order:
            // that list is a JSON array, whose order is canonical content,
            // while these names are discovered by walking a JSON OBJECT, whose
            // key order Canon::encode() normalizes away everywhere else
            // (`manifest` above included). Ordering the list by discovery
            // would make a no-op post_types{} key reshuffle move a certified
            // adapter's digest. A missing file hashes as null: the loud refusal is
            // Policy::regenerators()' (which `duo manifest-validate` drives
            // offline, before any apply), and this layer only records
            // identity, so the entry stays present rather than silently
            // vanishing from the row.
            $regeneratorHashes = [];
            $seenRegenerators = [];
            foreach ((array) ($manifest['post_types'] ?? []) as $declaration) {
                $regenerator = is_array($declaration)
                    ? ($declaration['regen_dependency']['regenerator'] ?? null)
                    : null;
                if (!is_string($regenerator) || $regenerator === ''
                    || isset($seenRegenerators[$regenerator])) {
                    continue;
                }
                $seenRegenerators[$regenerator] = true;
                $file = Policy::manifests_dir() . '/regenerators/' . basename($regenerator) . '.php';
                $regeneratorHashes[] = [
                    'name' => $regenerator,
                    'sha256' => is_file($file) ? hash_file('sha256', $file) : null,
                ];
            }
            usort($regeneratorHashes, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
            if ($regeneratorHashes !== []) {
                $row['regenerators'] = $regeneratorHashes;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    public static function manifest_hash(Policy $policy): string {
        return hash('sha256', Canon::encode(self::manifest_rows($policy)));
    }

    /**
     * DUO-3222: the resolved adapter compatibility contract — see
     * CompiledRepository::resolved_adapters()'s docblock for what this
     * proves and what it deliberately does not (declaration validity, not
     * a live-environment match). `digest` is `manifest_rows()`'s own
     * per-manifest row hashed alone — the exact same bytes manifest_hash()
     * folds into its one combined hash above, just exposed per-adapter
     * instead of only combined, so a single manifest's identity is
     * independently checkable without needing every OTHER pinned
     * manifest's bytes too.
     *
     * DUO-3314 adds `source`/`trust_tier`: which adapter source installed this
     * manifest and how much executable authority it reaches. `digest` is
     * deliberately unchanged for shipped adapters — provenance reaches the
     * digest through the `disposition` slot manifest_rows() already hashes
     * (Policy::manifest_disposition() answers with the synthesized provenance
     * record for an out-of-tree adapter), so no shipped adapter's digest, and
     * therefore no existing site.duo.json pin or registry claim, moves.
     *
     * @return list<array{name:string, digest:string, source:string, trust_tier:string, spec_version:?int, plugin:?string, version_range:?array, theme:?string, theme_version_range:?array, disposition:?array, capability:?array}>
     */
    public static function resolved_adapters(Policy $policy): array {
        $sources = $policy->adapter_sources();
        $out = [];
        foreach (self::manifest_rows($policy) as $row) {
            $manifest = $row['manifest'];
            $out[] = [
                'name' => $row['name'],
                'source' => $sources->source((string) $row['name']),
                'trust_tier' => AdapterSources::trust_tier($manifest),
                'digest' => class_exists(CapabilityRegistry::class)
                    ? CapabilityRegistry::adapter_digest($manifest, $row['disposition'])
                    : hash('sha256', Canon::encode($row)),
                'spec_version' => isset($manifest['spec_version']) ? (int) $manifest['spec_version'] : null,
                'plugin' => isset($manifest['plugin']) ? (string) $manifest['plugin'] : null,
                'version_range' => is_array($manifest['version_range'] ?? null) ? $manifest['version_range'] : null,
                'theme' => isset($manifest['theme']) ? (string) $manifest['theme'] : null,
                'theme_version_range' => is_array($manifest['theme_version_range'] ?? null)
                    ? $manifest['theme_version_range'] : null,
                'disposition' => $row['disposition'],
                'capability' => $policy->capability_claim((string) $row['name']),
            ];
        }
        return $out;
    }

    private function run(): CompiledRepository {
        SidebarState::assert_policy($this->policy);
        $codeDescriptor = null;
        $codeConfig = $this->policy->code_config();
        try {
            if ($codeConfig !== null) {
                if (!class_exists(Code::class)) {
                    throw new \RuntimeException('code payload support is not loaded');
                }
                $codeDescriptor = Code::compile($this->repo, $codeConfig);
            }
        } catch (CodeCompilationException $e) {
            foreach ($e->diagnostics as $diagnostic) {
                $this->diagnostics[] = $diagnostic;
            }
        } catch (\Throwable $t) {
            $this->add('code_payload_invalid', 'code', '', $t->getMessage());
        }
        if (!is_dir($this->stateDir)) {
            $this->add('state_directory_missing', 'state', '', 'repository has no state/ directory');
            $this->fail();
        }
        $spec = $this->policy->site['spec_version'] ?? null;
        $supported = defined('DUO_SPEC_VERSION') ? DUO_SPEC_VERSION : 0;
        if (!is_int($spec) || $spec !== $supported) {
            $this->add(
                'manifest_compatibility', 'site.duo.json', 'spec_version',
                'repository spec_version ' . var_export($spec, true) . " is incompatible with compiler version $supported"
            );
        }

        $tree = [];
        $sourceRows = [];
        foreach ($this->state_files() as $path => $absolute) {
            $content = Canon::read_file($absolute);
            $sourceRows[] = ['path' => $path, 'sha256' => hash('sha256', $content)];
            if (preg_match(self::CONFLICT_RE, $content, $m, PREG_OFFSET_CAPTURE)) {
                $line = 1 + substr_count(substr($content, 0, $m[0][1]), "\n");
                $this->add('conflict_marker', $path, "line $line", 'Git conflict marker survives in canonical content');
            }
            if (preg_match('#^deletions/([^/]+)\.json$#', $path)) {
                $deletion = $this->parse_deletion($path, $content);
                if ($deletion !== null) {
                    $uuid = (string) $deletion['data']['uuid'];
                    if (isset($this->deletions[$uuid])) {
                        $this->add(
                            'duplicate_deletion', $path, 'uuid',
                            "deletion intent for $uuid is already declared by {$this->deletions[$uuid]['path']}",
                            $this->deletions[$uuid]['path']
                        );
                    } else {
                        $this->deletions[$uuid] = $deletion;
                    }
                }
                continue;
            }
            $entity = $this->parse_entity($path, $content);
            if ($entity === null) {
                continue;
            }
            $uuid = (string) ($entity['data']['uuid'] ?? '');
            if ($entity['type'] === 'options') {
                $tree['options/core'] = $entity;
                continue;
            }
            if ($entity['type'] === 'user-meta') {
                $stateKey = UserMetaState::key((string) ($entity['data']['login'] ?? ''));
                if (isset($tree[$stateKey])) {
                    $this->add(
                        'duplicate_user_meta_login', $path, 'login',
                        "exact login is already represented by {$tree[$stateKey]['path']}",
                        $tree[$stateKey]['path']
                    );
                } else {
                    $tree[$stateKey] = $entity;
                }
                continue;
            }
            if ($entity['type'] === SidebarState::ENTITY_TYPE) {
                $sidebar = SidebarState::sidebar_from_path($path);
                $stateKey = SidebarState::key((string) $sidebar);
                $tree[$stateKey] = $entity;
                foreach ((array) ($entity['data']['widgets'] ?? []) as $i => $widget) {
                    $widgetUuid = (string) ($widget['uuid'] ?? '');
                    if (!preg_match(self::UUID_RE, $widgetUuid)) {
                        $this->add('invalid_uuid', $path, "widgets[$i].uuid", "'$widgetUuid' is not a lowercase RFC UUID");
                    } else {
                        $this->register_identity($widgetUuid, 'widget', $path . "#widgets[$i]");
                    }
                }
                continue;
            }
            if (!$this->register_identity($uuid, $entity['type'], $path)) {
                continue;
            }
            $tree[$uuid] = $entity;
            if ($entity['type'] === 'menu') {
                foreach ((array) ($entity['data']['items'] ?? []) as $i => $item) {
                    $itemUuid = (string) ($item['uuid'] ?? '');
                    if (!preg_match(self::UUID_RE, $itemUuid)) {
                        $this->add('invalid_uuid', $path, "items[$i].uuid", "'$itemUuid' is not a lowercase RFC UUID");
                    } else {
                        $this->register_identity($itemUuid, 'menu_item', $path . "#items[$i]");
                    }
                }
            }
        }

        ksort($tree, SORT_STRING);
        ksort($this->deletions, SORT_STRING);
        foreach ($this->deletions as $uuid => $deletion) {
            if (isset($this->identities[$uuid])) {
                $this->add(
                    'delete_live_conflict', $deletion['path'], 'uuid',
                    "uuid $uuid is both live and explicitly deleted by this revision",
                    $this->identities[$uuid]['path']
                );
            }
        }
        usort($sourceRows, static fn(array $a, array $b): int => $a['path'] <=> $b['path']);
        $this->policy->prime_interpreters_from_repository($tree);
        $this->validate_natural_identities($tree);
        $this->validate_menu_locations($tree);
        $this->validate_graph($tree);
        $this->validate_portable_shapes($tree);
        // A comparison revision may predate code opt-in. Keep compiling and
        // validating the current descriptor above, but do not require this
        // non-action artifact to express the lifecycle intent needed to
        // authorize stage/finalize/apply. compile() and compile_staged()
        // remain strict because they construct actionable artifacts.
        if ($codeDescriptor !== null) {
            $lifecycleRequirements = null;
            if (!$this->completenessOptional && !class_exists(CodeStateContract::class)) {
                $this->add('code_state_contract_unavailable', 'code', '', 'code/state bridge support is not loaded');
            } elseif (!$this->completenessOptional) {
                try {
                    $lifecycleRequirements = CodeStateContract::lifecycle_requirements_from_tree($tree);
                } catch (\Throwable $_requirementsFailure) {
                    // validate_tree() below owns the structured lifecycle
                    // diagnostic. Do not let a malformed active_plugins value
                    // turn the separate source compatibility gate into a
                    // second, less-specific failure.
                }
                try {
                    CodeStateContract::validate_tree($tree, $codeDescriptor);
                } catch (\Throwable $t) {
                    $this->add('code_state_mismatch', 'state/options/core.json', '', $t->getMessage());
                }
                if ($lifecycleRequirements !== null) {
                    foreach (CodeCompatibility::diagnostics(
                        $this->repo . '/' . Code::SOURCE,
                        $codeDescriptor,
                        self::resolved_adapters($this->policy),
                        (array) ($lifecycleRequirements['active_plugins'] ?? [])
                    ) as $diagnostic) {
                        $this->diagnostics[] = $diagnostic;
                    }
                }
            }
            if ($this->completenessOptional || $lifecycleRequirements === null) {
                foreach (CodeCompatibility::diagnostics(
                    $this->repo . '/' . Code::SOURCE,
                    $codeDescriptor,
                    self::resolved_adapters($this->policy),
                    null
                ) as $diagnostic) {
                    $this->diagnostics[] = $diagnostic;
                }
            }
        }

        // Adapter code is the already-ratified interpreter trust boundary:
        // it ships and hashes with a pinned manifest, never plugin runtime.
        foreach ($this->policy->repository_constraint_diagnostics($tree) as $d) {
            $this->diagnostics[] = $d + ['severity' => 'blocking', 'locator' => '', 'message' => 'adapter constraint failed'];
        }
        $this->catalog_media_directory();
        if ($this->diagnostics) {
            $this->fail();
        }

        // Authorization is part of compilation. Preserve DUO-3203's exact
        // exception/payload when it is the only failing layer so existing
        // CLI/CI consumers do not lose their stable contract.
        RepositoryAuthorization::assert_tree($this->policy, $tree);

        ksort($this->media, SORT_STRING);
        ksort($this->mediaCatalog, SORT_STRING);
        $siteHash = self::site_hash($this->policy);
        $stateSiteHash = self::state_site_hash($this->policy);
        $manifestHash = self::manifest_hash($this->policy);
        $revisionInputs = [
            // Keep the historical input key so state-only repositories retain
            // their exact revision identity. Its value excludes only the
            // separate top-level code declaration.
            'site_hash' => $stateSiteHash,
            'manifest_hash' => $manifestHash,
            'sources' => $sourceRows,
            'media' => $this->mediaCatalog,
        ];
        $revision = hash('sha256', Canon::encode($revisionInputs));
        $payload = [
            'compiler_version' => 1,
            'spec_version' => $supported,
            'site_hash' => $siteHash,
            'manifest_hash' => $manifestHash,
            // DUO-3222: derived entirely from bytes manifest_hash() already
            // folds in above (manifest_rows() -> the full $manifest array
            // per entry) — reshaped for per-adapter consumption, not new
            // input, so it deliberately does NOT also feed revision_hash's
            // computation; the whole artifact (this field included) is
            // still tamper-evident via CompiledRepository::create()'s own
            // artifact_hash over the complete payload.
            'resolved_adapters' => self::resolved_adapters($this->policy),
            // DUO-3298: immutable and target-independent. The recovery
            // provider resolves target before-images/adapters only after this
            // complete declaration has blocked unknown/irreversible effects.
            'effects_inventory' => $this->policy->effects_inventory(),
            'revision_hash' => $revision,
            'media_catalog' => $this->mediaCatalog,
            'media' => $this->media,
            'tree' => $tree,
            'deletions' => $this->deletions,
        ];
        if ($codeDescriptor !== null) {
            $payload['code'] = $codeDescriptor;
        }
        return CompiledRepository::create($payload);
    }

    /** @return array<string,string> state-relative path => absolute path */
    private function state_files(): array {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->stateDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile() || $file->isLink()) {
                if ($file->isLink()) {
                    $rel = substr($file->getPathname(), strlen($this->stateDir) + 1);
                    $this->add('unsafe_repository_path', $rel, '', 'symbolic links are not valid canonical entities');
                }
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($this->stateDir) + 1));
            $out[$rel] = $file->getPathname();
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @return ?array typed IR entry */
    private function parse_entity(string $path, string $content): ?array {
        $kind = null;
        if (preg_match('#^posts/([^/]+)/([^/]+)\.md$#', $path, $m)) {
            $kind = 'post';
        } elseif (preg_match('#^terms/([^/]+)/([^/]+)\.json$#', $path, $m)) {
            $kind = 'term';
        } elseif (preg_match('#^menus/([^/]+)\.json$#', $path, $m)) {
            $kind = 'menu';
        } elseif (preg_match('#^sidebars/([^/]+)\.json$#', $path, $m)) {
            $kind = SidebarState::ENTITY_TYPE;
        } elseif ($path === 'options/core.json') {
            $kind = 'options';
        } elseif (preg_match('#^user-meta/([0-9a-f]{64})\.json$#', $path, $m)) {
            $kind = 'user-meta';
        } elseif (preg_match('#^tables/([^/]+)/([^/]+)\.json$#', $path, $m)) {
            $kind = 'table';
        }
        if ($kind === null) {
            $this->add(
                'invalid_entity_kind', $path, '',
                'path does not name a supported post/term/menu/sidebar/options/user-meta/table entity'
            );
            return null;
        }

        try {
            if ($kind === 'post') {
                [$data, $body] = Canon::parse_post_file($content);
            } else {
                $data = Canon::decode($content);
                $body = null;
            }
        } catch (\Throwable $t) {
            $this->add('malformed_entity', $path, '', $t->getMessage());
            return null;
        }
        if (!is_array($data)) {
            $this->add('schema_content_mismatch', $path, '', 'entity metadata must decode to an object');
            return null;
        }
        if ($kind === 'options') {
            try {
                $records = OptionState::records($data);
            } catch (\Throwable $t) {
                $this->add('schema_content_mismatch', $path, '', $t->getMessage());
                return null;
            }
            if (!$this->completenessOptional) {
                $required = array_fill_keys(array_keys($this->policy->authored_options()), true);
                $required += array_fill_keys(array_keys($this->policy->sub_keyed_options()), true);
                foreach (['active_plugins', 'template', 'stylesheet'] as $managedOption) {
                    if (($this->policy->option_rule($managedOption)['class'] ?? null) === 'managed') {
                        $required[$managedOption] = true;
                    }
                }
                foreach (array_diff_key($required, $records) as $name => $_) {
                    $this->add(
                        'schema_content_mismatch', $path, 'records.' . $name,
                        "authored exact option '$name' needs an explicit absent, present, or deleted record; "
                        . 'removing a record is not deletion intent'
                    );
                }
            }
            return [
                'type' => 'options', 'path' => $path,
                'hash' => hash('sha256', $content), 'source_hash' => hash('sha256', $content),
                'content' => $content,
                'data' => $data,
            ];
        }

        if ($kind === 'user-meta') {
            $this->validate_schema($kind, $path, $data, null);
            $login = (string) ($data['login'] ?? '');
            try {
                UserMetaState::assert_login($login);
            } catch (\Throwable $t) {
                $this->add('schema_content_mismatch', $path, 'login', $t->getMessage());
            }
            if ($path !== UserMetaState::path($login)) {
                $this->add(
                    'schema_content_mismatch', $path, 'login',
                    'user-meta filename must be the SHA-256 canonical key of the exact login'
                );
            }
            return [
                'type' => 'user-meta', 'path' => $path,
                'hash' => hash('sha256', $content), 'source_hash' => hash('sha256', $content),
                'content' => $content,
                'data' => $data,
            ];
        }

        if ($kind === SidebarState::ENTITY_TYPE) {
            $this->validate_schema($kind, $path, $data, null);
            return [
                'type' => SidebarState::ENTITY_TYPE, 'path' => $path,
                'hash' => hash('sha256', $content), 'source_hash' => hash('sha256', $content),
                'content' => $content,
                'data' => $data,
            ];
        }

        $this->validate_schema($kind, $path, $data, $body);
        $uuid = (string) ($data['uuid'] ?? '');
        if (!preg_match(self::UUID_RE, $uuid)) {
            $this->add('invalid_uuid', $path, 'uuid', "'$uuid' is not a lowercase RFC UUID");
        }
        if (!str_starts_with(basename($path), $uuid . '--') && $kind !== 'menu') {
            $this->add('schema_content_mismatch', $path, 'uuid', 'filename identity does not match entity uuid');
        }

        $type = $kind;
        if ($kind === 'post') {
            $dir = explode('/', $path)[1];
            if (($data['type'] ?? null) !== $dir) {
                $this->add('schema_content_mismatch', $path, 'type', 'post type disagrees with its directory');
            }
            if (basename($path) !== $uuid . '--' . (string) ($data['slug'] ?? '') . '.md') {
                $this->add('schema_content_mismatch', $path, 'slug', 'post filename does not match uuid and slug');
            }
            $entry = [
                'type' => 'post', 'post_type' => (string) ($data['type'] ?? ''), 'path' => $path,
                'hash' => hash('sha256', Canon::post_hash_basis($data, (string) $body, $this->policy)),
                'source_hash' => hash('sha256', $content), 'content' => $content,
                'data' => $data, 'body' => (string) $body,
            ];
            $this->validate_attachment($path, $data);
            return $entry;
        }
        if ($kind === 'term') {
            $dir = explode('/', $path)[1];
            if (($data['taxonomy'] ?? null) !== $dir) {
                $this->add('schema_content_mismatch', $path, 'taxonomy', 'term taxonomy disagrees with its directory');
            }
            if (basename($path) !== $uuid . '--' . (string) ($data['slug'] ?? '') . '.json') {
                $this->add('schema_content_mismatch', $path, 'slug', 'term filename does not match uuid and slug');
            }
        } elseif ($kind === 'menu') {
            if (basename($path) !== (string) ($data['slug'] ?? '') . '.json') {
                $this->add('schema_content_mismatch', $path, 'slug', 'menu filename does not match slug');
            }
        } elseif ($kind === 'table') {
            $dir = explode('/', $path)[1];
            if (($data['table'] ?? null) !== $dir) {
                $this->add('schema_content_mismatch', $path, 'table', 'table name disagrees with its directory');
            }
            $type = (string) ($data['table'] ?? '');
        }
        return [
            'type' => $type, 'path' => $path,
            'hash' => hash('sha256', $content), 'source_hash' => hash('sha256', $content),
            'content' => $content,
            'data' => $data,
        ];
    }

    /** @return ?array typed deletion IR entry */
    private function parse_deletion(string $path, string $content): ?array {
        try {
            $data = Canon::decode($content);
        } catch (\Throwable $t) {
            $this->add('malformed_deletion', $path, '', $t->getMessage());
            return null;
        }
        if (!is_array($data) || array_is_list($data)) {
            $this->add('malformed_deletion', $path, '', 'deletion intent must decode to an object');
            return null;
        }
        $required = ['format', 'uuid', 'kind', 'type', 'expected_hash', 'expected_revision', 'source_path'];
        $unknown = array_values(array_diff(array_keys($data), $required));
        if ($unknown) {
            sort($unknown, SORT_STRING);
            $this->add('malformed_deletion', $path, '', 'unknown deletion field(s): ' . implode(', ', $unknown));
        }
        foreach ($required as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) || $data[$field] === '') {
                $this->add('malformed_deletion', $path, $field, 'required deletion field is missing or not a non-empty string');
            }
        }
        $uuid = (string) ($data['uuid'] ?? '');
        if (($data['format'] ?? '') !== Deletion::FORMAT) {
            $this->add('malformed_deletion', $path, 'format', 'unsupported deletion intent format');
        }
        if (!preg_match(self::UUID_RE, $uuid)) {
            $this->add('invalid_uuid', $path, 'uuid', "'$uuid' is not a lowercase RFC UUID");
        }
        if (basename($path) !== $uuid . '.json') {
            $this->add('malformed_deletion', $path, 'uuid', 'deletion filename does not match its uuid');
        }
        $kind = (string) ($data['kind'] ?? '');
        $type = (string) ($data['type'] ?? '');
        if (!in_array($kind, ['post', 'term', 'menu', 'table'], true)) {
            $this->add('malformed_deletion', $path, 'kind', "unsupported deletion kind '$kind'");
        }
        foreach (['expected_hash', 'expected_revision'] as $field) {
            if (!preg_match('/^[0-9a-f]{64}$/', (string) ($data[$field] ?? ''))) {
                $this->add('malformed_deletion', $path, $field, 'field must be a lowercase SHA-256 hash');
            }
        }
        $source = (string) ($data['source_path'] ?? '');
        $sourceOk = match ($kind) {
            'post' => preg_match('#^posts/' . preg_quote($type, '#') . '/' . preg_quote($uuid, '#') . '--[^/]+\.md$#', $source),
            'term' => preg_match('#^terms/' . preg_quote($type, '#') . '/' . preg_quote($uuid, '#') . '--[^/]+\.json$#', $source),
            'menu' => $type === 'nav_menu' && preg_match('#^menus/[^/]+\.json$#', $source),
            'table' => preg_match('#^tables/' . preg_quote($type, '#') . '/' . preg_quote($uuid, '#') . '--[^/]+\.json$#', $source),
            default => false,
        };
        if (!$sourceOk) {
            $this->add('malformed_deletion', $path, 'source_path', 'source_path does not match the declared kind, type, and uuid');
        }
        if (in_array($kind, ['post', 'term', 'menu', 'table'], true) && $type !== '') {
            try {
                Deletion::capability($this->policy, $kind, $type);
            } catch (\Throwable $t) {
                $this->add('unsupported_deletion', $path, 'type', $t->getMessage());
            }
        }
        return [
            'type' => 'deletion',
            'path' => $path,
            'hash' => hash('sha256', $content),
            'source_hash' => hash('sha256', $content),
            'content' => $content,
            'data' => $data,
        ];
    }

    private function validate_schema(string $kind, string $path, array $data, ?string $body): void {
        $required = match ($kind) {
            'post' => ['uuid','type','slug','title','status','date','date_gmt','modified_gmt','author','parent','menu_order','comment_status','ping_status','excerpt','meta','terms'],
            'term' => ['uuid','taxonomy','name','slug','description','parent','meta','relationships'],
            // DUO-3272: 'locations' drops out of the required set the
            // moment a pinned manifest reclassifies menu_fields.locations
            // 'derived' (e.g. Polylang) -- Capture::scope_menus() omits the
            // key entirely under that override (never writes an empty []
            // either), so a schema that still hard-required it here would
            // reject every menu Capture legitimately produces. Ordinary,
            // non-overridden sites are completely unaffected: 'locations'
            // stays required exactly as before.
            'menu' => $this->policy->menu_field_class('locations') === 'derived'
                ? ['uuid','name','slug','items']
                : ['uuid','name','slug','locations','items'],
            'user-meta' => ['login','meta'],
            'sidebar' => ['widgets'],
            'table' => ['uuid','table','columns','meta'],
            default => [],
        };
        foreach ($required as $field) {
            if (!array_key_exists($field, $data)) {
                $this->add('schema_content_mismatch', $path, $field, 'required field is missing');
            }
        }
        foreach (['uuid','slug'] as $field) {
            if (isset($data[$field]) && !is_string($data[$field])) {
                $this->add('schema_content_mismatch', $path, $field, 'field must be a string');
            }
        }
        if ($kind === 'post' && (!isset($data['meta']) || !is_array($data['meta']) || !isset($data['terms']) || !is_array($data['terms']))) {
            $this->add('schema_content_mismatch', $path, 'meta/terms', 'post meta and terms must be object maps');
        } elseif ($kind === 'post') {
            foreach ($data['terms'] as $taxonomy => $uuids) {
                if (!is_string($taxonomy) || !is_array($uuids) || !array_is_list($uuids)) {
                    $this->add('schema_content_mismatch', $path, 'terms', 'each taxonomy relationship must be a UUID list');
                    continue;
                }
                $this->validate_taxonomy_relationship_keyspace($path, "terms.$taxonomy", $taxonomy, 'post');
            }
            foreach ((array) ($data['term_orders'] ?? []) as $taxonomy => $orders) {
                if (!is_string($taxonomy) || !is_array($orders) || array_is_list($orders)) {
                    $this->add('schema_content_mismatch', $path, 'term_orders', 'each taxonomy order set must be a UUID-to-integer map');
                    continue;
                }
                foreach ($orders as $uuid => $order) {
                    if (!is_string($uuid) || !is_int($order)
                        || !in_array($uuid, (array) ($data['terms'][$taxonomy] ?? []), true)) {
                        $this->add('schema_content_mismatch', $path, 'term_orders', 'order entries must name a related UUID and carry an integer');
                    }
                }
            }
        }
        if ($kind === 'term' && (!isset($data['meta']) || !is_array($data['meta'])
            || !isset($data['relationships']) || !is_array($data['relationships']))) {
            $this->add('schema_content_mismatch', $path, 'meta/relationships', 'term meta and relationships must be object maps');
        } elseif ($kind === 'term') {
            foreach ((array) ($data['relationships'] ?? []) as $taxonomy => $uuids) {
                if (!is_string($taxonomy) || !is_array($uuids) || !array_is_list($uuids)) {
                    $this->add('schema_content_mismatch', $path, 'relationships', 'each term-object relationship must be a UUID list');
                    continue;
                }
                $this->validate_taxonomy_relationship_keyspace($path, "relationships.$taxonomy", $taxonomy, 'term');
            }
        }
        if ($kind === 'user-meta') {
            $unknown = array_values(array_diff(array_keys($data), ['login', 'meta']));
            if ($unknown) {
                sort($unknown, SORT_STRING);
                $this->add(
                    'schema_content_mismatch', $path, '',
                    'unknown user-meta field(s): ' . implode(', ', $unknown)
                );
            }
            if (!is_string($data['login'] ?? null)) {
                $this->add('schema_content_mismatch', $path, 'login', 'login must be a string');
            }
            if (!isset($data['meta']) || !is_array($data['meta'])) {
                $this->add('schema_content_mismatch', $path, 'meta', 'meta must be an object map');
            }
        }
        if ($kind === 'menu' && isset($data['items']) && !is_array($data['items'])) {
            $this->add('schema_content_mismatch', $path, 'items', 'menu items must be a list');
        } elseif ($kind === 'menu') {
            foreach ((array) ($data['items'] ?? []) as $i => $item) {
                if (!is_array($item)) {
                    $this->add('schema_content_mismatch', $path, "items[$i]", 'menu item must be an object');
                    continue;
                }
                foreach (['uuid','type','ref','position','title'] as $field) {
                    if (!array_key_exists($field, $item)) {
                        $this->add('schema_content_mismatch', $path, "items[$i].$field", 'required menu-item field is missing');
                    }
                }
                if (isset($item['type']) && !in_array($item['type'], ['post_type','taxonomy','custom'], true)) {
                    $this->add('schema_content_mismatch', $path, "items[$i].type", 'menu-item type must be post_type, taxonomy, or custom');
                }
                if (isset($item['ref']) && !is_string($item['ref'])) {
                    $this->add('schema_content_mismatch', $path, "items[$i].ref", 'menu-item ref must be a string');
                }
                if (isset($item['parent']) && $item['parent'] !== null && !is_string($item['parent'])) {
                    $this->add('schema_content_mismatch', $path, "items[$i].parent", 'menu-item parent must be null or a UUID string');
                }
            }
        }
        if ($kind === 'menu' && $this->policy->menu_field_class('locations') !== 'derived') {
            if (!isset($data['locations']) || !is_array($data['locations']) || !array_is_list($data['locations'])) {
                $this->add('schema_content_mismatch', $path, 'locations', 'menu locations must be an ordered string list');
            } else {
                foreach ($data['locations'] as $i => $location) {
                    if (!is_string($location) || $location === '') {
                        $this->add('schema_content_mismatch', $path, "locations[$i]", 'menu location must be a non-empty string');
                    }
                }
            }
        }
        if ($kind === SidebarState::ENTITY_TYPE) {
            $unknown = array_values(array_diff(array_keys($data), ['widgets']));
            if ($unknown) {
                sort($unknown, SORT_STRING);
                $this->add('schema_content_mismatch', $path, '', 'unknown sidebar field(s): ' . implode(', ', $unknown));
            }
            if (!isset($data['widgets']) || !is_array($data['widgets']) || !array_is_list($data['widgets'])) {
                $this->add('schema_content_mismatch', $path, 'widgets', 'widgets must be an ordered list');
            }
            $declared = $this->policy->widget_types();
            foreach ((array) ($data['widgets'] ?? []) as $i => $widget) {
                if (!is_array($widget)
                    || array_diff(array_keys($widget), ['uuid', 'type', 'settings'])
                    || array_diff(['uuid', 'type', 'settings'], array_keys($widget))) {
                    $this->add('schema_content_mismatch', $path, "widgets[$i]", 'widget must contain exactly uuid, type, settings');
                    continue;
                }
                $type = (string) ($widget['type'] ?? '');
                if (!isset($declared[$type])) {
                    $this->add('schema_content_mismatch', $path, "widgets[$i].type", "widget type '$type' is not manifest-declared");
                }
                if (!is_array($widget['settings'] ?? null)) {
                    $this->add('schema_content_mismatch', $path, "widgets[$i].settings", 'widget settings must be an object');
                    continue;
                }
                $unknownSettings = array_diff(array_keys($widget['settings']), array_keys((array) ($declared[$type]['settings'] ?? [])));
                if ($unknownSettings) {
                    sort($unknownSettings, SORT_STRING);
                    $this->add('schema_content_mismatch', $path, "widgets[$i].settings", 'undeclared setting(s): ' . implode(', ', $unknownSettings));
                }
            }
        }
        if ($kind === 'table' && (!isset($data['columns']) || !is_array($data['columns']) || !isset($data['meta']) || !is_array($data['meta']))) {
            $this->add('schema_content_mismatch', $path, 'columns/meta', 'table columns and meta must be object maps');
        }
    }

    /**
     * Canonical `posts.*.terms` and `terms.*.relationships` name the two
     * different object_id keyspaces. This offline check keeps a hand-edited
     * file from reaching Apply, where a shared numeric id could otherwise
     * select another entity's relationship rows. Policy owns the one
     * manifest resolver; a resolver refusal (for example overlapping
     * pattern declarations) is a structured compilation failure too.
     */
    private function validate_taxonomy_relationship_keyspace(
        string $path,
        string $locator,
        string $taxonomy,
        string $expected
    ): void {
        try {
            $actual = $this->policy->taxonomy_object_keyspace($taxonomy);
        } catch (\Throwable $t) {
            $this->add('taxonomy_object_keyspace_invalid', $path, $locator, $t->getMessage());
            return;
        }
        if ($actual !== $expected) {
            $this->add(
                'taxonomy_object_keyspace_mismatch',
                $path,
                $locator,
                "taxonomy '$taxonomy' resolves to object_keyspace='$actual'; this field requires '$expected'"
            );
        }
    }

    private function validate_attachment(string $path, array $data): void {
        if (($data['type'] ?? '') !== 'attachment') {
            return;
        }
        foreach (['file','media','mime','alt'] as $field) {
            if (!array_key_exists($field, $data) || !is_string($data[$field])) {
                $this->add('schema_content_mismatch', $path, $field, 'attachment field is required and must be a string');
            }
        }
        $upload = (string) ($data['file'] ?? '');
        // WordPress normally stores Y/m/basename, but plugins legitimately
        // create upload-root files (WooCommerce's placeholder is the
        // canonical example) and may use their own safe subdirectories.
        // The portable invariant is a normalized relative path, not one
        // particular directory policy.
        $segments = explode('/', $upload);
        $safeUpload = $upload !== ''
            && !str_starts_with($upload, '/')
            && !str_contains($upload, '\\')
            && !preg_match('/[\x00-\x1f\x7f]/', $upload)
            && !array_filter($segments, static fn(string $part): bool => $part === '' || $part === '.' || $part === '..');
        if (!$safeUpload) {
            $this->add('unsafe_media_path', $path, 'file', "upload path '$upload' is not a normalized relative path");
        } elseif (isset($this->uploadPaths[$upload])) {
            $this->add('duplicate_upload_path', $path, 'file', "upload path '$upload' is also owned by {$this->uploadPaths[$upload]}", $this->uploadPaths[$upload]);
        } else {
            $this->uploadPaths[$upload] = $path;
            $directory = dirname($upload);
            $root = ($directory === '.' ? '' : $directory . '/')
                . pathinfo(basename($upload), PATHINFO_FILENAME) . '-';
            if (isset($this->uploadDerivativeRoots[$root])) {
                $this->add(
                    'duplicate_media_derivative_root',
                    $path,
                    'file',
                    "upload path '$upload' shares derivative root '$root*' with {$this->uploadDerivativeRoots[$root]}",
                    $this->uploadDerivativeRoots[$root]
                );
            } else {
                $this->uploadDerivativeRoots[$root] = $path;
            }
        }
        $blob = (string) ($data['media'] ?? '');
        if (!preg_match('/^([0-9a-f]{64})\.[A-Za-z0-9]+$/', $blob, $m)) {
            $this->add('unsafe_media_path', $path, 'media', "media reference '$blob' is not content-addressed");
            return;
        }
        $absolute = $this->mediaDir . '/' . $blob;
        if (is_link($absolute)) {
            $this->add('unsafe_media_path', $path, 'media', "media/$blob is a symbolic link");
            return;
        }
        if (!is_file($absolute)) {
            $this->add('missing_media_blob', $path, 'media', "media/$blob does not exist");
            return;
        }
        $bytes = Canon::read_file($absolute);
        $actual = hash('sha256', $bytes);
        if (!hash_equals($m[1], $actual)) {
            $this->add('media_hash_mismatch', $path, 'media', "media/$blob hashes to $actual");
            return;
        }
        $this->media[$blob] = ['sha256' => $actual, 'base64' => base64_encode($bytes)];
    }

    /** Hash the whole media partition so an artifact identifies one exact
     * repository revision even when capture has left safe orphan blobs. */
    private function catalog_media_directory(): void {
        $dir = $this->mediaDir;
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $file) {
            $name = $file->getFilename();
            $path = 'media/' . $name;
            if ($file->isLink() || !$file->isFile()) {
                $this->add('unsafe_media_path', $path, '', 'media entries must be regular files directly under media/');
                continue;
            }
            if (!preg_match('/^([0-9a-f]{64})\.[A-Za-z0-9]+$/', $name, $m)) {
                $this->add('unsafe_media_path', $path, '', 'media filename is not content-addressed');
                continue;
            }
            $actual = hash_file('sha256', $file->getPathname());
            if (!hash_equals($m[1], $actual)) {
                $this->add('media_hash_mismatch', $path, '', "filename hash does not match $actual");
                continue;
            }
            $this->mediaCatalog[$name] = $actual;
        }
        ksort($this->mediaCatalog, SORT_STRING);
    }

    private function register_identity(string $uuid, string $kind, string $path): bool {
        if (!preg_match(self::UUID_RE, $uuid)) {
            return false;
        }
        if (isset($this->identities[$uuid])) {
            $first = $this->identities[$uuid];
            $this->add('duplicate_uuid', $path, 'uuid', "uuid $uuid is already used by {$first['path']}", $first['path']);
            return false;
        }
        $this->identities[$uuid] = ['kind' => $kind, 'path' => $path];
        return true;
    }

    private function validate_natural_identities(array $tree): void {
        $seen = [];
        $rows = Snapshot::row_tables($this->policy);
        foreach ($tree as $entity) {
            if ($entity['type'] === 'options') {
                continue;
            }
            $d = $entity['data'];
            $key = null;
            if ($entity['type'] === 'post') {
                $key = 'post|' . ($d['type'] ?? '') . '|' . ($d['slug'] ?? '')
                    . '|' . (is_scalar($d['parent'] ?? null) ? (string) $d['parent'] : '');
            } elseif ($entity['type'] === 'term') {
                $key = 'term|' . ($d['taxonomy'] ?? '') . '|' . ($d['slug'] ?? '');
            } elseif ($entity['type'] === 'menu') {
                $key = 'term|nav_menu|' . ($d['slug'] ?? '');
            } elseif (isset($rows[$entity['type']])
                && ($identityColumns = Policy::natural_key_columns($rows[$entity['type']])) !== []) {
                // DUO-3318: the whole declared tuple, in declared order — a
                // parent-scoped key is unique only WITHIN its parent, so
                // comparing one component would report every sibling row of
                // every other parent as a duplicate identity.
                $components = [];
                foreach ($identityColumns as $col) {
                    $components[] = $d['columns'][$col] ?? null;
                }
                $key = 'table|' . $entity['type'] . '|' . Canon::encode($components);
            }
            if ($key === null) {
                continue;
            }
            if (isset($seen[$key])) {
                $this->add('duplicate_natural_identity', $entity['path'], 'slug', "natural identity collides with {$seen[$key]}", $seen[$key]);
            } else {
                $seen[$key] = $entity['path'];
            }
        }
    }

    /**
     * One active theme location may name one menu. This is a compiler
     * invariant rather than an apply-time overwrite rule: it rejects both an
     * malformed full source tree and a scoped candidate that combines a
     * selected source menu with an excluded target holder before any scoped
     * authority/session or target mutation can be created. A manifest that
     * classifies locations as derived owns that whole surface and is exempt.
     */
    private function validate_menu_locations(array $tree): void {
        if ($this->policy->menu_field_class('locations') === 'derived') {
            return;
        }
        $holders = [];
        foreach ($tree as $entity) {
            if (($entity['type'] ?? '') !== 'menu') {
                continue;
            }
            $path = (string) ($entity['path'] ?? '');
            foreach ((array) ($entity['data']['locations'] ?? []) as $i => $location) {
                if (!is_string($location) || $location === '') {
                    continue; // validate_schema() owns the shape diagnostic.
                }
                if (isset($holders[$location])) {
                    $this->add(
                        'duplicate_menu_location',
                        $path,
                        "locations[$i]",
                        "menu location '$location' is already assigned by {$holders[$location]}",
                        $holders[$location]
                    );
                    continue;
                }
                $holders[$location] = $path;
            }
        }
    }

    /**
     * DUO-3344: the edge enumeration this validator needs is the same one
     * scope resolution needs, so it lives in ReferenceGraph and both callers
     * consume it — see that class's docblock for why a second walker would
     * be a correctness trap rather than a duplication nuisance.
     *
     * Folding the walk into one enumerator also retired a silent hole here:
     * the previous inline loop reused the tree's loop variable as the terms
     * loop variable, so any post carrying a term assignment filed its
     * post_parent edge under the last TERM's uuid. A genuine parent cycle
     * therefore went undetected the moment its posts were categorized —
     * pinned now by regress_scope_closure.sh.
     */
    private function validate_graph(array $tree): void {
        $kindTypes = ReferenceGraph::kind_types($this->policy);
        $parentGraph = [];
        $parentLocations = [];
        foreach (ReferenceGraph::edges($tree, $this->policy) as $edge) {
            if ($edge['check'] === ReferenceGraph::CHECK_TOKEN) {
                $this->validate_token((string) $edge['token'], $edge['path'], $edge['locator'], $kindTypes);
            } elseif ($edge['check'] === ReferenceGraph::CHECK_RAW) {
                $this->validate_raw_ref($edge['target'], $edge['expects'], $edge['path'], $edge['locator']);
            }
            if ($edge['relation'] === ReferenceGraph::REL_PARENT) {
                $parentGraph[$edge['from']][] = $edge['target'];
                $parentLocations[$edge['from']] = [$edge['path'], $edge['locator']];
            }
        }
        // A menu item's parent must live in the same menu. That is a
        // membership question about one file rather than an edge, so the
        // graph reports only the parents that do resolve and the missing
        // ones are diagnosed here.
        foreach ($tree as $entity) {
            if ($entity['type'] !== 'menu') {
                continue;
            }
            $items = [];
            foreach ((array) ($entity['data']['items'] ?? []) as $item) {
                $items[(string) ($item['uuid'] ?? '')] = true;
            }
            foreach ((array) ($entity['data']['items'] ?? []) as $i => $item) {
                if (!empty($item['parent']) && !isset($items[(string) $item['parent']])) {
                    $this->add('semantic_delete_reference', $entity['path'], "items[$i].parent", "menu-item parent {$item['parent']} is absent from this menu");
                }
            }
        }
        $this->validate_reference_cycles($parentGraph, $parentLocations);
    }

    /**
     * Declared references must already be canonical tokens in the IR. A raw
     * numeric id is syntactically valid JSON and can even name a real row on
     * one target, but has no environment-independent meaning.
     */
    private function validate_portable_shapes(array $tree): void {
        $rows = Snapshot::row_tables($this->policy);
        $metaByOwner = [];
        foreach (Snapshot::meta_tables($this->policy) as $name => $decl) {
            $metaByOwner[(string) ($decl['attached_to']['table'] ?? '')][$name] = $decl;
        }
        foreach ($tree as $entity) {
            $path = $entity['path'];
            $d = $entity['data'];
            if ($entity['type'] === 'post') {
                if (($d['author'] ?? null) !== null
                    && (!is_string($d['author']) || !str_starts_with($d['author'], 'user:'))) {
                    $this->add('nonportable_reference', $path, 'author', 'post author must be a user:<login> token');
                }
                if (($d['parent'] ?? null) !== null) {
                    $this->validate_declared_ref($d['parent'], 'post', $path, 'parent');
                }
                $meta = (array) ($d['meta'] ?? []);
                foreach ($meta as $key => $value) {
                    $rule = $this->policy->meta_rule_for_post((string) $key, $meta) ?? [];
                    if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                        $this->validate_structured_rule($value, $rule, $path, 'meta.' . $key);
                    } elseif (!empty($rule['ref'])) {
                        $this->validate_declared_ref($value, (string) $rule['ref'], $path, 'meta.' . $key);
                    }
                }
            } elseif ($entity['type'] === 'menu') {
                foreach ((array) ($d['items'] ?? []) as $i => $item) {
                    $kind = ($item['type'] ?? '') === 'post_type' ? 'post'
                        : (($item['type'] ?? '') === 'taxonomy' ? 'term' : null);
                    if ($kind !== null) {
                        $this->validate_declared_ref($item['ref'] ?? null, $kind, $path, "items[$i].ref");
                    }
                }
            } elseif ($entity['type'] === 'term') {
                $descriptionRule = $this->policy->description_reference_rule((string) ($d['taxonomy'] ?? ''));
                if ($descriptionRule !== null) {
                    $this->validate_structured_rule(
                        $d['description'] ?? null,
                        $descriptionRule,
                        $path,
                        'description'
                    );
                }
                $meta = (array) ($d['meta'] ?? []);
                foreach ($meta as $key => $value) {
                    $rule = $this->policy->meta_rule_for_term((string) $key, $meta) ?? [];
                    if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                        $this->validate_structured_rule($value, $rule, $path, 'meta.' . $key);
                    } elseif (!empty($rule['ref'])) {
                        $this->validate_declared_ref($value, (string) $rule['ref'], $path, 'meta.' . $key);
                    }
                }
            } elseif ($entity['type'] === 'options') {
                // DUO-3263: an interpreter-classified option's ref kind (ACF's
                // options-page fields) needs the same document-sourced
                // sibling map meta_rule_for_post() above already gets from
                // $meta — options have no single owning entity, so this is
                // every present value plus valid v2 deletion witnesses in
                // this SAME document, built once.
                $allOptions = OptionState::classification_values($d);
                foreach (OptionState::records($d) as $name => $record) {
                    if ($record['state'] !== 'present') {
                        continue;
                    }
                    $value = $record['value'];
                    $details = str_contains((string) $name, '{{')
                        ? $this->policy->canonical_option_name_ref_details((string) $name)
                        : $this->policy->option_rule_details_for_option((string) $name, $allOptions);
                    $rule = $details['rule'] ?? [];
                    if (!empty($rule['sub_keys']) && is_array($value)) {
                        foreach ($value as $subKey => $subValue) {
                            $subRule = (array) ($rule['sub_keys'][$subKey] ?? []);
                            if (($subRule['class'] ?? '') !== 'authored') {
                                continue; // RepositoryAuthorization reports ownership violations.
                            }
                            $subLocator = 'options.' . $name . '.' . $subKey;
                            if (!empty($subRule['json_refs']) || !empty($subRule['key_refs'])) {
                                $this->validate_structured_rule($subValue, $subRule, $path, $subLocator);
                            } elseif (!empty($subRule['ref'])) {
                                $this->validate_declared_ref(
                                    $subValue,
                                    (string) $subRule['ref'],
                                    $path,
                                    $subLocator
                                );
                            }
                        }
                    } elseif (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                        $this->validate_structured_rule($value, $rule, $path, 'options.' . $name);
                    } elseif (!empty($rule['ref'])) {
                        $this->validate_declared_ref($value, (string) $rule['ref'], $path, 'options.' . $name);
                    }
                }
            } elseif ($entity['type'] === 'user-meta') {
                $meta = (array) ($d['meta'] ?? []);
                foreach ($meta as $key => $value) {
                    $rule = $this->policy->meta_rule_for_user((string) $key, $meta) ?? [];
                    if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                        $this->validate_structured_rule($value, $rule, $path, 'meta.' . $key);
                    } elseif (!empty($rule['ref'])) {
                        $this->validate_declared_ref($value, (string) $rule['ref'], $path, 'meta.' . $key);
                    }
                }
            } elseif ($entity['type'] === SidebarState::ENTITY_TYPE) {
                $declaredWidgets = $this->policy->widget_types();
                foreach ((array) ($d['widgets'] ?? []) as $i => $widget) {
                    $type = (string) ($widget['type'] ?? '');
                    foreach ((array) ($widget['settings'] ?? []) as $setting => $value) {
                        $rule = (array) (($declaredWidgets[$type]['settings'] ?? [])[$setting] ?? []);
                        if (!empty($rule['ref'])) {
                            $this->validate_declared_ref(
                                $value,
                                (string) $rule['ref'],
                                $path,
                                "widgets[$i].settings.$setting"
                            );
                        }
                        if (($rule['codec'] ?? '') === 'blocks' && !is_string($value)) {
                            $this->add(
                                'schema_content_mismatch', $path, "widgets[$i].settings.$setting",
                                'block-content widget setting must be a string'
                            );
                        }
                    }
                }
            } elseif (isset($rows[$entity['type']])) {
                foreach ($rows[$entity['type']]['refs'] ?? [] as $ref) {
                    $column = (string) $ref['column'];
                    $this->validate_declared_ref(
                        $d['columns'][$column] ?? null, (string) $ref['kind'], $path, 'columns.' . $column
                    );
                }
                foreach ($metaByOwner[$entity['type']] ?? [] as $metaName => $decl) {
                    foreach ((array) ($d['meta'] ?? []) as $key => $value) {
                        $rule = ReferenceRules::attached_meta_key($decl, (string) $key);
                        if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                            $this->validate_structured_rule(
                                $value,
                                $rule,
                                $path,
                                "meta:$metaName.$key"
                            );
                        } elseif (!empty($rule['ref'])) {
                            $this->validate_declared_ref(
                                $value, (string) $rule['ref'], $path, "meta:$metaName.$key"
                            );
                        }
                    }
                }
            }
        }
    }

    /** Validate declared structural leaves and id-keyed maps before apply. */
    private function validate_structured_rule($value, array $rule, string $path, string $locator): void {
        foreach ((array) ($rule['json_refs'] ?? []) as $ref) {
            $copy = $value;
            JsonRefs::walk(
                $copy,
                JsonRefs::parse_path((string) $ref['path']),
                function (&$container, $key, string $matchedLocator) use ($ref, $path, $locator): void {
                    $leaf = $container[$key];
                    if ($leaf === null || $leaf === '' || $leaf === 0 || $leaf === '0' || $leaf === false
                        || is_array($leaf)) {
                        return; // declared unset/container conventions
                    }
                    $this->validate_declared_ref(
                        $leaf,
                        (string) $ref['kind'],
                        $path,
                        $locator . $matchedLocator
                    );
                },
                ''
            );
        }
        $keyRefs = $rule['key_refs'] ?? null;
        if (!is_array($keyRefs)) {
            return;
        }
        $validateMap = function ($map, string $mapLocator) use ($keyRefs, $path, $locator): void {
            if (!is_array($map) || ($map !== [] && array_is_list($map))) {
                $this->add(
                    'nonportable_reference',
                    $path,
                    $locator . $mapLocator,
                    'key_refs path must resolve to an id-keyed map'
                );
                return;
            }
            foreach ($map as $key => $_value) {
                $this->validate_declared_ref(
                    $key,
                    (string) $keyRefs['kind'],
                    $path,
                    $locator . $mapLocator . ' (key)'
                );
            }
        };
        if (isset($keyRefs['path'])) {
            $copy = $value;
            JsonRefs::walk(
                $copy,
                JsonRefs::parse_path((string) $keyRefs['path']),
                function (&$container, $key, string $matchedLocator) use ($validateMap): void {
                    $validateMap($container[$key], $matchedLocator);
                },
                ''
            );
            return;
        }
        $validateMap($value, '');
    }

    private function validate_declared_ref($value, string $ref, string $path, string $locator): void {
        if ($value === null) {
            return;
        }
        $many = str_ends_with($ref, '[]');
        $kind = $many ? substr($ref, 0, -2) : $ref;
        if ($many) {
            if (!is_array($value) || !array_is_list($value)) {
                $this->add('nonportable_reference', $path, $locator, "declared $ref value must be a token list");
                return;
            }
            foreach ($value as $i => $one) {
                $this->validate_declared_ref($one, $kind, $path, $locator . "[$i]");
            }
            return;
        }
        $valid = $kind === 'user'
            ? is_string($value) && str_starts_with($value, 'user:')
            : is_string($value) && preg_match('/^\{\{' . preg_quote($kind, '/') . ':[0-9a-f-]{36}\}\}$/', $value);
        if (!$valid) {
            $this->add('nonportable_reference', $path, $locator, "declared $kind reference must be a canonical token, never a raw target id");
        }
    }

    /** Parent-like graphs must be acyclic even though phase 1 makes every
     * row locally resolvable; otherwise the revision is structurally
     * impossible in WordPress despite having no dangling edge. */
    private function validate_reference_cycles(array $graph, array $locations): void {
        ksort($graph, SORT_STRING);
        $state = [];
        $stack = [];
        $reported = [];
        $visit = function (string $node) use (&$visit, &$state, &$stack, &$reported, $graph, $locations): void {
            $state[$node] = 1;
            $stack[] = $node;
            foreach ($graph[$node] ?? [] as $next) {
                if (($state[$next] ?? 0) === 0) {
                    $visit($next);
                    continue;
                }
                if (($state[$next] ?? 0) !== 1) {
                    continue;
                }
                $start = array_search($next, $stack, true);
                $cycle = array_slice($stack, $start === false ? 0 : $start);
                $cycle[] = $next;
                $keyParts = $cycle;
                sort($keyParts, SORT_STRING);
                $key = implode('|', array_unique($keyParts));
                if (isset($reported[$key])) {
                    continue;
                }
                $reported[$key] = true;
                [$path, $locator] = $locations[$node] ?? ['state', ''];
                $this->add('reference_cycle', $path, $locator, 'parent graph contains cycle ' . implode(' -> ', $cycle));
            }
            array_pop($stack);
            $state[$node] = 2;
        };
        foreach (array_keys($graph) as $node) {
            if (($state[$node] ?? 0) === 0) {
                $visit((string) $node);
            }
        }
    }

    private function validate_token(string $token, string $path, string $locator, array $kindTypes): void {
        if (!preg_match('/^\{\{([a-z][a-z0-9_]*):([0-9a-f-]{36})\}\}$/', $token, $m)) {
            $this->add('malformed_reference', $path, $locator, "reference token '$token' is malformed");
            return;
        }
        $kind = $m[1];
        $uuid = $m[2];
        if (!isset($kindTypes[$kind])) {
            $this->add('invalid_reference_kind', $path, $locator, "reference kind '$kind' is not registered by core or a pinned table schema");
            return;
        }
        $this->validate_raw_ref($uuid, $kindTypes[$kind], $path, $locator);
    }

    private function validate_raw_ref(string $uuid, array $expectedTypes, string $path, string $locator): void {
        if (!preg_match(self::UUID_RE, $uuid)) {
            $this->add('malformed_reference', $path, $locator, "reference '$uuid' is not a valid UUID");
            return;
        }
        if (!isset($this->identities[$uuid])) {
            if (isset($this->deletions[$uuid])) {
                $this->add(
                    'semantic_delete_reference', $path, $locator,
                    "reference target $uuid is explicitly deleted by {$this->deletions[$uuid]['path']}",
                    $this->deletions[$uuid]['path']
                );
            } else {
                $this->add('semantic_delete_reference', $path, $locator, "reference target $uuid is absent from the compiled revision");
            }
            return;
        }
        $actual = $this->identities[$uuid]['kind'];
        if (!in_array($actual, $expectedTypes, true)) {
            $this->add('reference_kind_mismatch', $path, $locator, "reference target $uuid is $actual; expected " . implode('|', $expectedTypes));
        }
    }

    private function add(string $code, string $path, string $locator, string $message, ?string $relatedPath = null): void {
        $d = [
            'severity' => 'blocking', 'code' => $code, 'path' => $path,
            'locator' => $locator, 'message' => $message,
        ];
        if ($relatedPath !== null) {
            $d['related_path'] = $relatedPath;
        }
        $this->diagnostics[] = $d;
    }

    private function fail(): void {
        usort($this->diagnostics, static fn(array $a, array $b): int =>
            [$a['path'], $a['locator'] ?? '', $a['code'], $a['message']]
            <=> [$b['path'], $b['locator'] ?? '', $b['code'], $b['message']]
        );
        throw new RepositoryCompilationException($this->diagnostics);
    }
}

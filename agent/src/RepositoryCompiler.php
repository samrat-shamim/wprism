<?php
namespace Duo;

require_once __DIR__ . '/CodeCompatibility.php';
require_once __DIR__ . '/ReferenceGraph.php';
// DUO-3348 slice 2: CompiledRepository and RepositoryCompilationException moved
// to their own file (the "CompiledArtifact value object" target seam). Required
// here, not just left to duo.php's bootstrap order, so every existing caller of
// THIS file keeps getting both classes transitively with no change on its part.
require_once __DIR__ . '/CompiledArtifact.php';
// DUO-3348 slice 30: policy/manifest artifact identity is independent of the
// repository tree builder. Keep the long-lived RepositoryCompiler methods as
// compatibility facades while the extracted collaborator owns byte projection.
require_once __DIR__ . '/ArtifactPolicyIdentity.php';
// DUO-3348 slice 35: persisted-artifact validation is independent of the
// compiler builder. Keep read_artifact() below as the compatibility facade.
require_once __DIR__ . '/CompiledArtifactReader.php';
// DUO-3348 slice 31: repository-owned attachment/blob validation and full
// media cataloguing are independent of tree parsing and Policy. Required
// directly so existing RepositoryCompiler consumers keep one closed load graph.
require_once __DIR__ . '/RepositoryMediaCatalog.php';
require_once __DIR__ . '/RepositoryDeletionParser.php';
require_once __DIR__ . '/RepositoryEntityParser.php';
require_once __DIR__ . '/RepositoryIdentityRegistry.php';
require_once __DIR__ . '/RepositoryReferenceGraphValidator.php';
require_once __DIR__ . '/RepositoryPortableShapeValidator.php';
require_once __DIR__ . '/RepositoryMenuLocationValidator.php';
require_once __DIR__ . '/RepositoryStateFileCatalog.php';

/**
 * Deterministic offline compiler: repository files + pinned policy artifacts
 * become one validated IR before Tokens, Ledger, Capture, or a target query
 * can be constructed. Its entity-parser collaborator owns one-file routing,
 * decoding, schema validation, and attachment reference checks; this compiler
 * retains tree traversal, identity-registry orchestration, graph closure,
 * full media cataloguing, conflict markers, adapter constraints, and
 * DUO-3203's active-policy authorization pass.
 */
final class RepositoryCompiler {
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
    // gate in RepositoryEntityParser's options branch, and the CodeStateContract
    // lifecycle bridge below. It does NOT skip Code::compile() itself, so
    // current code config/source/descriptor validation, malformed JSON,
    // invalid record shapes, illegitimate tombstones, conflict markers,
    // identity uniqueness, media integrity, and every other historical
    // validation stay fully active. A genuinely corrupted historical
    // revision is still caught exactly as before.
    private bool $completenessOptional;
    /** @var array<int,array<string,mixed>> */
    private array $diagnostics = [];
    /** @var array<string,array<string,mixed>> */
    private array $deletions = [];
    /** Attachment/blob validation state for this compilation's resolved media root. */
    private RepositoryMediaCatalog $mediaCatalog;
    /** Decoded deletion-intent validation for this compiler's immutable Policy. */
    private RepositoryDeletionParser $deletionParser;
    /** Decoded canonical entity parsing for this compiler's immutable Policy. */
    private RepositoryEntityParser $entityParser;
    /** UUID ownership plus natural-key uniqueness for this compilation. */
    private RepositoryIdentityRegistry $identityRegistry;
    /** Validates canonical reference graph claims through this compiler's aggregate sink. */
    private RepositoryReferenceGraphValidator $referenceGraphValidator;
    /** Validates declared canonical-reference shapes through this compiler's aggregate sink. */
    private RepositoryPortableShapeValidator $portableShapeValidator;
    /** Validates authored menu-location uniqueness through this compiler's aggregate sink. */
    private RepositoryMenuLocationValidator $menuLocationValidator;
    /** Enumerates this compilation's arbitrary staged state root through the aggregate sink. */
    private RepositoryStateFileCatalog $stateFileCatalog;

    private function __construct(
        string $stateDir,
        string $mediaRoot,
        Policy $policy,
        bool $completenessOptional = false,
        ?string $mediaDir = null
    ) {
        $this->stateDir = rtrim($stateDir, '/');
        $this->repo = rtrim($mediaRoot, '/'); // media/ root only — see compile_staged()'s docblock for why this can differ from stateDir's own parent
        $this->policy = $policy;
        $this->completenessOptional = $completenessOptional;
        // Keep the existing compiler-level accumulator and one final fail()
        // sort: a bad media path must be reported alongside independent tree,
        // schema, and reference findings rather than short-circuiting media.
        $this->mediaCatalog = new RepositoryMediaCatalog(
            $mediaDir === null ? $this->repo . '/media' : rtrim($mediaDir, '/'),
            function (string $code, string $path, string $locator, string $message, ?string $relatedPath = null): void {
                $this->add($code, $path, $locator, $message, $relatedPath);
            }
        );
        $this->deletionParser = new RepositoryDeletionParser(
            $policy,
            function (string $code, string $path, string $locator, string $message, ?string $relatedPath = null): void {
                $this->add($code, $path, $locator, $message, $relatedPath);
            }
        );
        $this->entityParser = new RepositoryEntityParser(
            $policy,
            $completenessOptional,
            $this->mediaCatalog,
            function (string $code, string $path, string $locator, string $message, ?string $relatedPath = null): void {
                $this->add($code, $path, $locator, $message, $relatedPath);
            }
        );
        $this->identityRegistry = new RepositoryIdentityRegistry(
            $policy,
            function (string $code, string $path, string $locator, string $message, ?string $relatedPath = null): void {
                $this->add($code, $path, $locator, $message, $relatedPath);
            }
        );
        $this->referenceGraphValidator = new RepositoryReferenceGraphValidator(
            $policy,
            $this->identityRegistry,
            function (string $code, string $path, string $locator, string $message, ?string $relatedPath = null): void {
                $this->add($code, $path, $locator, $message, $relatedPath);
            }
        );
        $this->portableShapeValidator = new RepositoryPortableShapeValidator(
            $policy,
            function (string $code, string $path, string $locator, string $message, ?string $relatedPath = null): void {
                $this->add($code, $path, $locator, $message, $relatedPath);
            }
        );
        $this->menuLocationValidator = new RepositoryMenuLocationValidator(
            $policy,
            function (string $code, string $path, string $locator, string $message, ?string $relatedPath = null): void {
                $this->add($code, $path, $locator, $message, $relatedPath);
            }
        );
        $this->stateFileCatalog = new RepositoryStateFileCatalog(
            $this->stateDir,
            function (string $code, string $path, string $locator, string $message, ?string $relatedPath = null): void {
                $this->add($code, $path, $locator, $message, $relatedPath);
            }
        );
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
        return CompiledArtifactReader::read_artifact($path, $policy);
    }

    public static function site_hash(Policy $policy): string {
        return ArtifactPolicyIdentity::site_hash($policy);
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
        return ArtifactPolicyIdentity::state_site_hash($policy);
    }

    private static function manifest_rows(Policy $policy): array {
        return ArtifactPolicyIdentity::manifest_rows($policy);
    }

    public static function manifest_hash(Policy $policy): string {
        return ArtifactPolicyIdentity::manifest_hash($policy);
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
        return ArtifactPolicyIdentity::resolved_adapters($policy);
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
        foreach ($this->stateFileCatalog->files() as $path => $absolute) {
            $content = Canon::read_file($absolute);
            $sourceRows[] = ['path' => $path, 'sha256' => hash('sha256', $content)];
            if (preg_match(self::CONFLICT_RE, $content, $m, PREG_OFFSET_CAPTURE)) {
                $line = 1 + substr_count(substr($content, 0, $m[0][1]), "\n");
                $this->add('conflict_marker', $path, "line $line", 'Git conflict marker survives in canonical content');
            }
            if (preg_match('#^deletions/([^/]+)\.json$#', $path)) {
                $deletion = $this->deletionParser->parse($path, $content);
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
            $entity = $this->entityParser->parse($path, $content);
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
                    if (!RepositoryIdentityRegistry::is_uuid($widgetUuid)) {
                        $this->add('invalid_uuid', $path, "widgets[$i].uuid", "'$widgetUuid' is not a lowercase RFC UUID");
                    } else {
                        $this->identityRegistry->register($widgetUuid, 'widget', $path . "#widgets[$i]");
                    }
                }
                continue;
            }
            if (!$this->identityRegistry->register($uuid, $entity['type'], $path)) {
                continue;
            }
            $tree[$uuid] = $entity;
            if ($entity['type'] === 'menu') {
                foreach ((array) ($entity['data']['items'] ?? []) as $i => $item) {
                    $itemUuid = (string) ($item['uuid'] ?? '');
                    if (!RepositoryIdentityRegistry::is_uuid($itemUuid)) {
                        $this->add('invalid_uuid', $path, "items[$i].uuid", "'$itemUuid' is not a lowercase RFC UUID");
                    } else {
                        $this->identityRegistry->register($itemUuid, 'menu_item', $path . "#items[$i]");
                    }
                }
            }
        }

        ksort($tree, SORT_STRING);
        ksort($this->deletions, SORT_STRING);
        foreach ($this->deletions as $uuid => $deletion) {
            if (($identity = $this->identityRegistry->find($uuid)) !== null) {
                $this->add(
                    'delete_live_conflict', $deletion['path'], 'uuid',
                    "uuid $uuid is both live and explicitly deleted by this revision",
                    $identity['path']
                );
            }
        }
        usort($sourceRows, static fn(array $a, array $b): int => $a['path'] <=> $b['path']);
        $this->policy->prime_interpreters_from_repository($tree);
        $this->identityRegistry->validate_natural_identities($tree);
        $this->menuLocationValidator->validate($tree);
        $this->referenceGraphValidator->validate($tree, $this->deletions);
        $this->portableShapeValidator->validate($tree);
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
        $this->mediaCatalog->catalog_directory();
        if ($this->diagnostics) {
            $this->fail();
        }

        // Authorization is part of compilation. Preserve DUO-3203's exact
        // exception/payload when it is the only failing layer so existing
        // CLI/CI consumers do not lose their stable contract.
        RepositoryAuthorization::assert_tree($this->policy, $tree);

        $media = $this->mediaCatalog->referenced_media();
        $mediaCatalog = $this->mediaCatalog->catalog();
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
            'media' => $mediaCatalog,
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
            'media_catalog' => $mediaCatalog,
            'media' => $media,
            'tree' => $tree,
            'deletions' => $this->deletions,
        ];
        if ($codeDescriptor !== null) {
            $payload['code'] = $codeDescriptor;
        }
        return CompiledRepository::create($payload);
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

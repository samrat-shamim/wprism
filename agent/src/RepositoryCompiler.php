<?php
namespace Duo;

/**
 * One immutable, typed result of compiling a repository revision. The
 * constructor is private on purpose: plan/apply/deploy can receive this
 * object, never an arbitrary array assembled by a caller that skipped the
 * compiler. PHP arrays are copy-on-write, so returning tree() cannot mutate
 * the object held by another phase of the same command.
 */
final class CompiledRepository {
    public const FORMAT = 'duo-compiled-repository/v1';

    private array $artifact;

    private function __construct(array $artifact) {
        $this->artifact = $artifact;
    }

    public static function create(array $payload): self {
        $payload['format'] = self::FORMAT;
        $payload['artifact_hash'] = self::content_hash($payload);
        return new self($payload);
    }

    public static function from_array(array $artifact): self {
        $actual = (string) ($artifact['artifact_hash'] ?? '');
        $copy = $artifact;
        unset($copy['artifact_hash']);
        if (($artifact['format'] ?? '') !== self::FORMAT
            || !preg_match('/^[0-9a-f]{64}$/', $actual)
            || !hash_equals(self::content_hash($copy), $actual)) {
            throw new \RuntimeException('duo: compiled artifact is malformed or its content hash does not verify');
        }
        if (!isset($artifact['tree']) || !is_array($artifact['tree'])) {
            throw new \RuntimeException('duo: compiled artifact has no typed tree');
        }
        return new self($artifact);
    }

    private static function content_hash(array $payload): string {
        unset($payload['artifact_hash']);
        return hash('sha256', Canon::encode($payload));
    }

    public function tree(): array {
        return $this->artifact['tree'];
    }

    /** @return array<string,array<string,mixed>> keyed by deleted UUID */
    public function deletions(): array {
        return (array) ($this->artifact['deletions'] ?? []);
    }

    public function artifact_hash(): string {
        return $this->artifact['artifact_hash'];
    }

    public function revision_hash(): string {
        return $this->artifact['revision_hash'];
    }

    public function manifest_hash(): string {
        return $this->artifact['manifest_hash'];
    }

    /**
     * DUO-3222: the resolved adapter compatibility contract this artifact
     * was compiled against — one row per pinned manifest (name, per-
     * manifest digest, spec_version if declared, plugin/version_range or
     * theme/theme_version_range if declared). This is compilation staying
     * honest about what it validated: proof of DECLARATION validity and
     * non-ambiguity (every range well-formed, no conflicting ownership —
     * Policy::load()'s own validators already refused the artifact from
     * ever existing otherwise), not a live-environment match — compilation
     * is deliberately target-DB-free (no $wpdb, no get_plugins()/
     * wp_get_theme() calls anywhere in this compiler), so it cannot also
     * prove what's actually INSTALLED matches. That second proof is
     * Deploy::code_mismatch()'s job, unchanged by this artifact's own
     * offline-ness. DUO-3227's capability registry is expected to generate
     * from exactly this shape (one machine-readable row per adapter).
     *
     * @return list<array{name:string, digest:string, spec_version:?int, plugin:?string, version_range:?array, theme:?string, theme_version_range:?array}>
     */
    public function resolved_adapters(): array {
        return $this->artifact['resolved_adapters'] ?? [];
    }

    public function site_hash(): string {
        return $this->artifact['site_hash'];
    }

    public function media_content(string $name): string {
        $row = $this->artifact['media'][$name] ?? null;
        if (!is_array($row) || !is_string($row['base64'] ?? null) || !is_string($row['sha256'] ?? null)) {
            throw new \RuntimeException("duo: compiled artifact has no media payload '$name'");
        }
        $bytes = base64_decode($row['base64'], true);
        if ($bytes === false || !hash_equals($row['sha256'], hash('sha256', $bytes))) {
            throw new \RuntimeException("duo: compiled artifact media payload '$name' does not verify");
        }
        return $bytes;
    }

    public function export(): array {
        return $this->artifact;
    }

    public function write(string $path): void {
        Canon::write_file($path, Canon::encode($this->artifact));
    }
}

/** Stable, batched failure returned by the offline compiler. */
final class RepositoryCompilationException extends \RuntimeException {
    /** @var array<int,array<string,mixed>> */
    public array $diagnostics;

    public function __construct(array $diagnostics) {
        $this->diagnostics = $diagnostics;
        $lines = array_map(static function (array $d): string {
            $where = $d['path'] . (($d['locator'] ?? '') !== '' ? ':' . $d['locator'] : '');
            return '[' . $d['code'] . '] ' . $where . ' — ' . $d['message'];
        }, $diagnostics);
        parent::__construct(
            'duo: repository compilation failed (' . count($diagnostics)
            . " blocking diagnostic(s)); no target contact or mutation attempted:\n  - "
            . implode("\n  - ", $lines)
        );
    }

    public function payload(): array {
        return [
            'ok' => false,
            'error' => 'repository_compilation_failed',
            'diagnostics' => $this->diagnostics,
        ];
    }
}

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
    /** @var array<int,array<string,mixed>> */
    private array $diagnostics = [];
    /** @var array<string,array{kind:string,path:string}> */
    private array $identities = [];
    /** @var array<string,array<string,mixed>> */
    private array $deletions = [];
    /** @var array<string,string> upload-relative path => source path */
    private array $uploadPaths = [];
    /** @var array<string,array{sha256:string,base64:string}> media blob => immutable payload */
    private array $media = [];
    /** @var array<string,string> every content-addressed blob in media/, including safe orphans */
    private array $mediaCatalog = [];

    private function __construct(string $stateDir, string $mediaRoot, Policy $policy) {
        $this->stateDir = rtrim($stateDir, '/');
        $this->repo = rtrim($mediaRoot, '/'); // media/ root only — see compile_staged()'s docblock for why this can differ from stateDir's own parent
        $this->policy = $policy;
    }

    public static function compile(string $repo, Policy $policy): CompiledRepository {
        $repo = rtrim($repo, '/');
        return self::compile_staged($repo . '/state', $repo, $policy);
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
    public static function compile_staged(string $stateDir, string $mediaRoot, Policy $policy): CompiledRepository {
        $c = new self($stateDir, $mediaRoot, $policy);
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
     * DUO-3222: the per-manifest content row manifest_hash()/resolved_
     * adapters() both hash — factored out so a per-manifest digest
     * (resolved_adapters() below) and the pre-existing combined hash
     * (manifest_hash()) are provably the SAME content, never two
     * independently-maintained notions of "what identifies this manifest."
     *
     * @return list<array{name:string, manifest:array, interpreter?:array{name:string,sha256:?string}}>
     */
    private static function manifest_rows(Policy $policy): array {
        $rows = [];
        foreach ($policy->manifests as $manifest) {
            $row = ['name' => (string) ($manifest['name'] ?? ''), 'manifest' => $manifest];
            $interpreter = $manifest['interpreter'] ?? null;
            if (is_string($interpreter) && $interpreter !== '') {
                $file = Policy::manifests_dir() . '/interpreters/' . basename($interpreter) . '.php';
                $row['interpreter'] = [
                    'name' => $interpreter,
                    'sha256' => is_file($file) ? hash_file('sha256', $file) : null,
                ];
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
     * @return list<array{name:string, digest:string, spec_version:?int, plugin:?string, version_range:?array, theme:?string, theme_version_range:?array}>
     */
    public static function resolved_adapters(Policy $policy): array {
        $out = [];
        foreach (self::manifest_rows($policy) as $row) {
            $manifest = $row['manifest'];
            $out[] = [
                'name' => $row['name'],
                'digest' => hash('sha256', Canon::encode($row)),
                'spec_version' => isset($manifest['spec_version']) ? (int) $manifest['spec_version'] : null,
                'plugin' => isset($manifest['plugin']) ? (string) $manifest['plugin'] : null,
                'version_range' => is_array($manifest['version_range'] ?? null) ? $manifest['version_range'] : null,
                'theme' => isset($manifest['theme']) ? (string) $manifest['theme'] : null,
                'theme_version_range' => is_array($manifest['theme_version_range'] ?? null)
                    ? $manifest['theme_version_range'] : null,
            ];
        }
        return $out;
    }

    private function run(): CompiledRepository {
        SidebarState::assert_policy($this->policy);
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
        $this->validate_graph($tree);
        $this->validate_portable_shapes($tree);

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
        $manifestHash = self::manifest_hash($this->policy);
        $revision = hash('sha256', Canon::encode([
            'site_hash' => $siteHash,
            'manifest_hash' => $manifestHash,
            'sources' => $sourceRows,
            'media' => $this->mediaCatalog,
        ]));
        return CompiledRepository::create([
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
            'revision_hash' => $revision,
            'media_catalog' => $this->mediaCatalog,
            'media' => $this->media,
            'tree' => $tree,
            'deletions' => $this->deletions,
        ]);
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
            return [
                'type' => 'options', 'path' => $path,
                'hash' => hash('sha256', $content), 'source_hash' => hash('sha256', $content),
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
                'data' => $data,
            ];
        }

        if ($kind === SidebarState::ENTITY_TYPE) {
            $this->validate_schema($kind, $path, $data, null);
            return [
                'type' => SidebarState::ENTITY_TYPE, 'path' => $path,
                'hash' => hash('sha256', $content), 'source_hash' => hash('sha256', $content),
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
                'source_hash' => hash('sha256', $content), 'data' => $data, 'body' => (string) $body,
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
                }
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
                }
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
        }
        $blob = (string) ($data['media'] ?? '');
        if (!preg_match('/^([0-9a-f]{64})\.[A-Za-z0-9]+$/', $blob, $m)) {
            $this->add('unsafe_media_path', $path, 'media', "media reference '$blob' is not content-addressed");
            return;
        }
        $absolute = $this->repo . '/media/' . $blob;
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
        $dir = $this->repo . '/media';
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
            } elseif (isset($rows[$entity['type']]) && ($rows[$entity['type']]['identity']['mode'] ?? 'mapped') === 'natural_key') {
                $col = $rows[$entity['type']]['identity']['column'];
                $key = 'table|' . $entity['type'] . '|' . Canon::encode($d['columns'][$col] ?? null);
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

    private function validate_graph(array $tree): void {
        $kindTypes = [
            'post' => ['post','menu_item'],
            'term' => ['term','menu'],
            'tt' => ['term','menu'],
        ];
        foreach (Snapshot::row_tables($this->policy) as $table => $decl) {
            $kindTypes[(string) $decl['id_kind']] = [$table];
        }
        foreach ($this->policy->widget_types() as $type => $_decl) {
            $kindTypes[SidebarState::kind((string) $type)] = ['widget'];
        }
        $parentGraph = [];
        $parentLocations = [];
        foreach ($tree as $uuid => $entity) {
            $path = $entity['path'];
            $this->walk_tokens($entity['data'], '$', function (string $token, string $locator) use ($path, $kindTypes) {
                $this->validate_token($token, $path, $locator, $kindTypes);
            });
            if ($entity['type'] === 'post') {
                $this->walk_tokens($entity['body'], 'body', function (string $token, string $locator) use ($path, $kindTypes) {
                    $this->validate_token($token, $path, $locator, $kindTypes);
                });
                foreach ((array) ($entity['data']['terms'] ?? []) as $tax => $uuids) {
                    foreach ((array) $uuids as $i => $uuid) {
                        $this->validate_raw_ref((string) $uuid, ['term'], $path, "terms.$tax[$i]");
                    }
                }
                if (is_string($entity['data']['parent'] ?? null)
                    && preg_match('/^\{\{post:([0-9a-f-]{36})\}\}$/', $entity['data']['parent'], $m)) {
                    $parentGraph[(string) $uuid][] = $m[1];
                    $parentLocations[(string) $uuid] = [$path, 'parent'];
                }
            } elseif ($entity['type'] === 'term') {
                if (!empty($entity['data']['parent'])) {
                    $this->validate_raw_ref((string) $entity['data']['parent'], ['term'], $path, 'parent');
                    $parentGraph[(string) $uuid][] = (string) $entity['data']['parent'];
                    $parentLocations[(string) $uuid] = [$path, 'parent'];
                }
                foreach ((array) ($entity['data']['relationships'] ?? []) as $tax => $uuids) {
                    foreach ((array) $uuids as $i => $uuid) {
                        $this->validate_raw_ref((string) $uuid, ['term'], $path, "relationships.$tax[$i]");
                    }
                }
            } elseif ($entity['type'] === 'menu') {
                $items = [];
                foreach ((array) ($entity['data']['items'] ?? []) as $i => $item) {
                    $items[(string) ($item['uuid'] ?? '')] = true;
                    if (!empty($item['parent']) && !isset($items[(string) $item['parent']])) {
                        // Parent may legally occur later in the list, so the
                        // final membership test happens after indexing all.
                        continue;
                    }
                }
                foreach ((array) ($entity['data']['items'] ?? []) as $i => $item) {
                    if (!empty($item['parent']) && !isset($items[(string) $item['parent']])) {
                        $this->add('semantic_delete_reference', $path, "items[$i].parent", "menu-item parent {$item['parent']} is absent from this menu");
                    } elseif (!empty($item['parent'])) {
                        $itemUuid = (string) ($item['uuid'] ?? '');
                        $parentGraph[$itemUuid][] = (string) $item['parent'];
                        $parentLocations[$itemUuid] = [$path, "items[$i].parent"];
                    }
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
                    if (!empty($rule['ref'])) {
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
                $meta = (array) ($d['meta'] ?? []);
                foreach ($meta as $key => $value) {
                    $rule = $this->policy->meta_rule_for_term((string) $key, $meta) ?? [];
                    if (!empty($rule['ref'])) {
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
                    if (!empty($rule['ref'])) {
                        $this->validate_declared_ref($value, (string) $rule['ref'], $path, 'options.' . $name);
                    }
                }
            } elseif ($entity['type'] === 'user-meta') {
                $meta = (array) ($d['meta'] ?? []);
                foreach ($meta as $key => $value) {
                    $rule = $this->policy->meta_rule_for_user((string) $key, $meta) ?? [];
                    if (!empty($rule['ref'])) {
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
                        $rule = $decl['keys'][$key] ?? ['class' => $decl['default_class'] ?? 'authored'];
                        if (!empty($rule['ref'])) {
                            $this->validate_declared_ref(
                                $value, (string) $rule['ref'], $path, "meta:$metaName.$key"
                            );
                        }
                    }
                }
            }
        }
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

    private function walk_tokens($value, string $locator, callable $visit): void {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $next = $locator . (is_int($key) ? "[$key]" : '.' . $key);
                if (is_string($key)) {
                    $this->tokens_in_string($key, $next . ' (key)', $visit);
                }
                $this->walk_tokens($child, $next, $visit);
            }
            return;
        }
        if (is_string($value)) {
            $this->tokens_in_string($value, $locator, $visit);
        }
    }

    private function tokens_in_string(string $value, string $locator, callable $visit): void {
        if (!preg_match_all('/\{\{([a-z][a-z0-9_]*):([^{}]+)\}\}/', $value, $matches, PREG_SET_ORDER)) {
            return;
        }
        foreach ($matches as $m) {
            $visit($m[0], $locator);
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

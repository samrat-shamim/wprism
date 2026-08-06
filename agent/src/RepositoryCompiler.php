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

    public function artifact_hash(): string {
        return $this->artifact['artifact_hash'];
    }

    public function revision_hash(): string {
        return $this->artifact['revision_hash'];
    }

    public function manifest_hash(): string {
        return $this->artifact['manifest_hash'];
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
    /** @var array<string,string> upload-relative path => source path */
    private array $uploadPaths = [];
    /** @var array<string,array{sha256:string,base64:string}> media blob => immutable payload */
    private array $media = [];
    /** @var array<string,string> every content-addressed blob in media/, including safe orphans */
    private array $mediaCatalog = [];

    private function __construct(string $repo, Policy $policy) {
        $this->repo = rtrim($repo, '/');
        $this->stateDir = $this->repo . '/state';
        $this->policy = $policy;
    }

    public static function compile(string $repo, Policy $policy): CompiledRepository {
        $c = new self($repo, $policy);
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

    public static function manifest_hash(Policy $policy): string {
        $inputs = [];
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
            $inputs[] = $row;
        }
        return hash('sha256', Canon::encode($inputs));
    }

    private function run(): CompiledRepository {
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
            $entity = $this->parse_entity($path, $content);
            if ($entity === null) {
                continue;
            }
            $uuid = (string) ($entity['data']['uuid'] ?? '');
            if ($entity['type'] === 'options') {
                $tree['options/core'] = $entity;
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
            'revision_hash' => $revision,
            'media_catalog' => $this->mediaCatalog,
            'media' => $this->media,
            'tree' => $tree,
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
        } elseif ($path === 'options/core.json') {
            $kind = 'options';
        } elseif (preg_match('#^tables/([^/]+)/([^/]+)\.json$#', $path, $m)) {
            $kind = 'table';
        }
        if ($kind === null) {
            $this->add('invalid_entity_kind', $path, '', 'path does not name a supported post/term/menu/options/table entity');
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
            if ($data !== [] && array_is_list($data)) {
                $this->add('schema_content_mismatch', $path, '', 'options/core.json must be an object map');
            }
            return [
                'type' => 'options', 'path' => $path,
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

    private function validate_schema(string $kind, string $path, array $data, ?string $body): void {
        $required = match ($kind) {
            'post' => ['uuid','type','slug','title','status','date','date_gmt','modified_gmt','author','parent','menu_order','comment_status','ping_status','excerpt','meta','terms'],
            'term' => ['uuid','taxonomy','name','slug','description','parent','relationships'],
            'menu' => ['uuid','name','slug','locations','items'],
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
        }
        if ($kind === 'term' && isset($data['relationships']) && !is_array($data['relationships'])) {
            $this->add('schema_content_mismatch', $path, 'relationships', 'term relationships must be an object map');
        } elseif ($kind === 'term') {
            foreach ((array) ($data['relationships'] ?? []) as $taxonomy => $uuids) {
                if (!is_string($taxonomy) || !is_array($uuids) || !array_is_list($uuids)) {
                    $this->add('schema_content_mismatch', $path, 'relationships', 'each term-object relationship must be a UUID list');
                }
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
            } elseif ($entity['type'] === 'options') {
                foreach ($d as $name => $value) {
                    $details = str_contains((string) $name, '{{')
                        ? $this->policy->canonical_option_name_ref_details((string) $name)
                        : $this->policy->option_rule_details((string) $name);
                    $rule = $details['rule'] ?? [];
                    if (!empty($rule['ref'])) {
                        $this->validate_declared_ref($value, (string) $rule['ref'], $path, 'options.' . $name);
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
            $this->add('semantic_delete_reference', $path, $locator, "reference target $uuid is absent from the compiled revision");
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

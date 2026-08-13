<?php
namespace Duo;

// This parser is a canonical-file-to-IR boundary. Normal direct loads close
// every collaborator it uses, but CLI refusal fixtures may preload fake Canon
// and Policy classes; preserve that established boundary rather than
// redeclaring their test doubles.
if (!class_exists(Canon::class, false)) {
    require_once __DIR__ . '/Canon.php';
}
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/Policy.php';
}
if (!class_exists(OptionState::class, false)) {
    require_once __DIR__ . '/OptionState.php';
}
if (!class_exists(UserMetaState::class, false)) {
    require_once __DIR__ . '/UserMetaState.php';
}
if (!class_exists(SidebarState::class, false)) {
    require_once __DIR__ . '/SidebarState.php';
}
if (!class_exists(RepositorySchemaValidator::class, false)) {
    require_once __DIR__ . '/RepositorySchemaValidator.php';
}
if (!class_exists(RepositoryMediaCatalog::class, false)) {
    require_once __DIR__ . '/RepositoryMediaCatalog.php';
}

/**
 * Decodes one canonical state file into typed repository IR while preserving
 * the compiler's accumulated diagnostics and shared media catalog state.
 * Traversal, identity ownership, deletions, graph validation, and artifact
 * construction remain with RepositoryCompiler.
 */
final class RepositoryEntityParser {
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private Policy $policy;
    private bool $completenessOptional;
    private RepositoryMediaCatalog $mediaCatalog;
    private RepositorySchemaValidator $schemaValidator;
    /** @var \Closure(string,string,string,string,?string):void */
    private \Closure $add;

    /** @param \Closure(string,string,string,string,?string):void $add */
    public function __construct(
        Policy $policy,
        bool $completenessOptional,
        RepositoryMediaCatalog $mediaCatalog,
        \Closure $add
    ) {
        $this->policy = $policy;
        $this->completenessOptional = $completenessOptional;
        $this->mediaCatalog = $mediaCatalog;
        $this->add = $add;
        $this->schemaValidator = new RepositorySchemaValidator(
            $policy,
            SidebarState::ENTITY_TYPE,
            function (string $code, string $path, string $locator, string $message, ?string $relatedPath = null): void {
                $this->add($code, $path, $locator, $message, $relatedPath);
            }
        );
    }

    /** @return ?array typed IR entry */
    public function parse(string $path, string $content): ?array {
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
            $this->schemaValidator->validate($kind, $path, $data, null);
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
            $this->schemaValidator->validate($kind, $path, $data, null);
            return [
                'type' => SidebarState::ENTITY_TYPE, 'path' => $path,
                'hash' => hash('sha256', $content), 'source_hash' => hash('sha256', $content),
                'content' => $content,
                'data' => $data,
            ];
        }

        $this->schemaValidator->validate($kind, $path, $data, $body);
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
            $this->mediaCatalog->validate_attachment($path, $data);
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

    private function add(string $code, string $path, string $locator, string $message, ?string $relatedPath = null): void {
        ($this->add)($code, $path, $locator, $message, $relatedPath);
    }
}

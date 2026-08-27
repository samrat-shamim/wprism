<?php
declare(strict_types=1);

namespace {
    $repoRoot = dirname(__DIR__, 4);
    require_once $repoRoot . '/sandbox/tests/lib/check.php';

    define('ARRAY_A', 'ARRAY_A');
    define('FS_CHMOD_FILE', 0644);
    $scratch = sys_get_temp_dir() . '/duo_code_snippets_provider_' . bin2hex(random_bytes(8));
    if (!mkdir($scratch, 0700, true) && !is_dir($scratch)) {
        throw new \RuntimeException("could not create $scratch");
    }
    define('WP_CONTENT_DIR', $scratch);

    $GLOBALS['cs_multisite'] = false;
    $GLOBALS['cs_network_residue'] = false;
    $GLOBALS['cs_settings'] = ['general' => ['enable_flat_files' => true]];
    $GLOBALS['cs_api_cache'] = null;
    $GLOBALS['cs_cache_clears'] = 0;

    function is_multisite(): bool {
        return $GLOBALS['cs_multisite'];
    }

    function get_option(string $name, mixed $default = false): mixed {
        return $name === 'active_shared_network_snippets'
            ? $GLOBALS['cs_network_residue']
            : $default;
    }

    function wp_cache_delete(string $key, string $group = ''): bool {
        return true;
    }

    function wp_json_encode(mixed $value, int $flags = 0): string|false {
        return json_encode($value, $flags);
    }

    function wp_hash(string $value): string {
        return md5('provider-fixture:' . $value);
    }

    final class CodeSnippetsWpdb {
        public string $last_error = '';
        public bool $fail_reads = false;

        /** @var list<array<string,mixed>> */
        public array $rows = [
            [
                'id' => '12', 'name' => 'Portable content', 'description' => 'UTF-8 東京 🚀',
                'code' => '<strong>portable</strong>', 'tags' => 'duo, html', 'scope' => 'content',
                'condition_id' => '0', 'priority' => '17', 'active' => '1',
            ],
            [
                'id' => '13', 'name' => 'Runtime filter', 'description' => 'Executable PHP',
                'code' => "add_filter('duo_runtime', fn(\$v) => \$v . '|repository-runtime');",
                'tags' => 'duo, runtime', 'scope' => 'global', 'condition_id' => '0',
                'priority' => '32767', 'active' => '1',
            ],
            [
                'id' => '14', 'name' => 'Invalid inactive', 'description' => 'Must stay inert',
                'code' => 'if (', 'tags' => 'duo, invalid', 'scope' => 'global',
                'condition_id' => '0', 'priority' => '1', 'active' => '0',
            ],
        ];

        public function get_results(string $sql, string $mode): array|false {
            if ($this->fail_reads) {
                $this->last_error = 'fixture schema mismatch details must not escape';
                return false;
            }
            $this->last_error = '';
            return $this->rows;
        }
    }

    $GLOBALS['wpdb'] = new CodeSnippetsWpdb();
}

namespace Duo {
    final class Policy {}

    final class Providers {
        public const SCOPED_OPERATION_FORMAT = 'duo-scoped-effect-operation/v1';
    }
}

namespace Code_Snippets\Settings {
    const CACHE_KEY = 'code_snippets_settings';

    function get_settings_values(): array {
        return $GLOBALS['cs_settings'];
    }
}

namespace Code_Snippets {
    const CACHE_GROUP = 'code_snippets';

    function clean_snippets_cache(string $table): void {
        $GLOBALS['cs_cache_clears']++;
        $GLOBALS['cs_api_cache'] = null;
    }

    /** @return list<Snippet> */
    function get_snippets(): array {
        if (is_array($GLOBALS['cs_api_cache'])) {
            return $GLOBALS['cs_api_cache'];
        }
        $GLOBALS['cs_api_cache'] = array_map(
            static fn(array $row): Snippet => new Snippet($row),
            $GLOBALS['wpdb']->rows
        );
        return $GLOBALS['cs_api_cache'];
    }

    function code_snippets(): object {
        static $plugin;
        if (!$plugin) {
            $plugin = (object) [
                'db' => new class {
                    public function get_table_name(bool $network = false): string {
                        return 'wp_snippets';
                    }
                },
                'snippet_handler_registry' => new Snippet_Handler_Registry([
                    'php' => new Php_Handler(),
                    'html' => new Html_Handler(),
                ]),
            ];
        }
        return $plugin;
    }

    final class Snippet {
        public int $id;
        public string $name;
        public string $desc;
        public string $code;
        public string $tags_list;
        public string $scope;
        public int $condition_id;
        public int $priority;
        public bool $active;
        public string $type;
        private int $rawActive;

        public function __construct(array $row) {
            $this->id = (int) $row['id'];
            $this->name = (string) $row['name'];
            $this->desc = (string) $row['description'];
            $this->code = (string) $row['code'];
            $this->tags_list = implode(', ', array_map('trim', explode(',', (string) $row['tags'])));
            $this->scope = (string) $row['scope'];
            $this->condition_id = (int) $row['condition_id'];
            $this->priority = (int) $row['priority'];
            $this->rawActive = (int) $row['active'];
            $this->active = $this->rawActive === 1;
            $this->type = $this->scope === 'content' ? 'html' : 'php';
        }

        public function is_trashed(): bool {
            return $this->rawActive === -1;
        }

        public function get_fields(): array {
            return [
                'id' => $this->id,
                'code' => $this->code,
                'scope' => $this->scope,
                'condition_id' => $this->condition_id,
                'priority' => $this->priority,
                'active' => $this->active,
            ];
        }
    }

    interface Handler {
        public function get_dir_name(): string;
        public function get_file_extension(): string;
        public function wrap_code(string $code): string;
    }

    final class Php_Handler implements Handler {
        public function get_dir_name(): string { return 'php'; }
        public function get_file_extension(): string { return 'php'; }
        public function wrap_code(string $code): string { return "<?php\n\n" . $code; }
    }

    final class Html_Handler implements Handler {
        public function get_dir_name(): string { return 'html'; }
        public function get_file_extension(): string { return 'php'; }
        public function wrap_code(string $code): string {
            return "<?php\n\nif ( ! defined( 'ABSPATH' ) ) { return; }\n\n?>\n\n" . $code;
        }
    }

    final class Snippet_Handler_Registry {
        public function __construct(private array $handlers) {}
        public function get_handler(string $type): ?Handler {
            return $this->handlers[$type] ?? null;
        }
    }

    final class WordPress_File_System_Adapter {
        public function delete(string $path, bool $recursive = false): bool {
            if (is_link($path) || is_file($path)) {
                return unlink($path);
            }
            if (!is_dir($path)) {
                return true;
            }
            foreach (new \FilesystemIterator($path) as $entry) {
                if (!$this->delete($entry->getPathname(), true)) {
                    return false;
                }
            }
            return rmdir($path);
        }
    }

    final class Snippet_Config_Repository {
        public function __construct(WordPress_File_System_Adapter $filesystem) {}
    }

    final class Snippet_Files {
        public function __construct(
            private Snippet_Handler_Registry $registry,
            private WordPress_File_System_Adapter $filesystem,
            private Snippet_Config_Repository $repository
        ) {}

        public static function get_base_dir(string $table = '', string $type = ''): string {
            return WP_CONTENT_DIR . '/code-snippets'
                . ($table !== '' ? '/' . $table : '')
                . ($type !== '' ? '/' . $type : '');
        }

        public static function get_hashed_table_name(string $table): string {
            return wp_hash($table);
        }

        public static function is_active(): bool {
            return is_file(self::get_base_dir() . '/flat-files-enabled.flag');
        }

        public function create_all_flat_files(array $settings): void {
            $enabled = !empty($settings['general']['enable_flat_files']);
            $root = self::get_base_dir();
            if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
                throw new \RuntimeException('fixture could not create flat root');
            }
            $flag = $root . '/flat-files-enabled.flag';
            if (!$enabled) {
                if (is_file($flag)) {
                    unlink($flag);
                }
                return;
            }
            file_put_contents($flag, '');
            $table = self::get_hashed_table_name('wp_snippets');
            $indexes = [];
            foreach (get_snippets() as $snippet) {
                if (!$snippet->active) {
                    continue;
                }
                $handler = $this->registry->get_handler($snippet->type);
                if (!$handler) {
                    continue;
                }
                $type = $handler->get_dir_name();
                $directory = self::get_base_dir($table, $type);
                if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                    throw new \RuntimeException('fixture could not create flat type directory');
                }
                file_put_contents(
                    $directory . '/' . $snippet->id . '.' . $handler->get_file_extension(),
                    $handler->wrap_code($snippet->code)
                );
                $indexes[$type][$snippet->id] = $snippet->get_fields();
            }
            foreach ($indexes as $type => $rows) {
                ksort($rows, SORT_NUMERIC);
                file_put_contents(
                    self::get_base_dir($table, $type) . '/index.php',
                    "<?php\nreturn " . var_export($rows, true) . ";\n"
                );
            }
        }
    }
}

namespace {
    require_once $repoRoot . '/agent/src/Adapter/ManifestProviderRuntime.php';
    require_once dirname(__DIR__, 2) . '/package/runtime/providers/code-snippets-state.php';

    $manifest = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2) . '/package/manifest.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $provider = new \Duo\Providers\CodeSnippetsState($manifest['providers'][0]);
    $capabilities = $provider->capabilities();
    duo_check_same(
        [
            'args' => [],
            'reads' => ['table:snippets', 'option:code_snippets_settings'],
            'writes' => ['entity:code-snippets-cache', 'entity:code-snippets-flat-files'],
            'scope' => 'site',
            'idempotent' => true,
            'timeout_seconds' => 60,
            'scoped' => [
                'operation_envelope' => \Duo\Providers::SCOPED_OPERATION_FORMAT,
                'reconcile' => true,
                'invoke_after' => 'reconcile',
            ],
        ],
        $capabilities['rebuild_snippet_state'] ?? null,
        'provider publishes one bounded site-scoped idempotent cache/flat-file repair contract'
    );

    $operation = [
        'authority_hash' => str_repeat('a', 64),
        'lease_session_id' => 'fixture-session',
        'operation_id' => 'fixture-operation',
        'input_hash' => str_repeat('b', 64),
        'effect_hash' => str_repeat('c', 64),
    ];
    $hash = \Code_Snippets\Snippet_Files::get_hashed_table_name('wp_snippets');
    $directory = \Code_Snippets\Snippet_Files::get_base_dir($hash);
    mkdir($directory . '/php', 0700, true);
    file_put_contents($directory . '/php/999.php', '<?php stale-target-code');
    file_put_contents($directory . '/php/index.php', '<?php return [999 => []];');
    file_put_contents(\Code_Snippets\Snippet_Files::get_base_dir() . '/flat-files-enabled.flag', '');
    $GLOBALS['cs_api_cache'] = [new \Code_Snippets\Snippet([
        'id' => 999, 'name' => 'Stale cached row', 'description' => 'stale',
        'code' => 'stale-target-code', 'tags' => 'stale', 'scope' => 'global',
        'condition_id' => 0, 'priority' => 1, 'active' => 1,
    ])];

    $receipt = $provider->invoke_scoped('rebuild_snippet_state', [], $operation);
    duo_check(($receipt['verified'] ?? false) === true, 'provider reports success only after its verified postcondition');
    duo_check_same(3, $receipt['after']['row_count'] ?? null, 'provider receipt counts the exact DB/API row set');
    duo_check_same(
        $receipt['after']['database_hash'] ?? null,
        $receipt['after']['api_hash'] ?? null,
        'cache invalidation forces the plugin API to agree with direct database bytes'
    );
    duo_check_same(4, $receipt['after']['flat_file_count'] ?? null, 'provider rebuilds exactly two code files and two indexes');
    duo_check(!is_file($directory . '/php/999.php'), 'provider purges a stale executable file omitted from the database');
    duo_check(
        is_file($directory . '/php/13.php') && is_file($directory . '/html/12.php'),
        'provider delegates PHP and HTML code-file reconstruction to registered plugin handlers'
    );
    $published = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    duo_check(
        is_string($published)
            && !str_contains($published, 'repository-runtime')
            && !str_contains($published, 'portable</strong>'),
        'provider receipt contains counts and hashes without executable or authored plaintext'
    );

    $firstAfter = $receipt['after'];
    $again = $provider->invoke_scoped('rebuild_snippet_state', [], $operation);
    duo_check_same($firstAfter, $again['after'] ?? null, 'a second full rebuild is byte-stable and idempotent');

    // A fresh recovery process may hold a stale persistent object-cache row.
    // reconcile_scoped() must invalidate that cache, but must not rebuild the
    // already verified filesystem effect a second time.
    $GLOBALS['cs_api_cache'] = [new \Code_Snippets\Snippet([
        'id' => 998, 'name' => 'Recovery stale row', 'description' => 'stale',
        'code' => 'stale', 'tags' => 'stale', 'scope' => 'global',
        'condition_id' => 0, 'priority' => 1, 'active' => 1,
    ])];
    $reconciled = $provider->reconcile_scoped('rebuild_snippet_state', [], $operation);
    duo_check_same(
        $firstAfter['database_hash'],
        $reconciled['after']['api_hash'] ?? null,
        'scoped recovery clears a stale persistent cache before verifying DB/API agreement'
    );

    $GLOBALS['cs_settings'] = ['general' => ['enable_flat_files' => false]];
    file_put_contents($directory . '/php/unexpected.php', '<?php stale-disabled-code');
    $disabled = $provider->invoke_scoped('rebuild_snippet_state', [], $operation);
    duo_check(
        ($disabled['after']['flat_files_enabled'] ?? null) === false
            && ($disabled['after']['flat_file_count'] ?? null) === 0
            && !file_exists($directory)
            && !\Code_Snippets\Snippet_Files::is_active(),
        'disabled flat-file mode removes the exact table projection and enabled flag'
    );

    // The directory boundary is checked before recursive deletion. A symlink
    // must refuse without touching its destination.
    $GLOBALS['cs_settings'] = ['general' => ['enable_flat_files' => true]];
    $outside = WP_CONTENT_DIR . '/outside';
    mkdir($outside, 0700, true);
    file_put_contents($outside . '/sentinel', 'preserve');
    symlink($outside, $directory);
    $symlinkRefused = false;
    try {
        $provider->invoke('rebuild_snippet_state', []);
    } catch (\RuntimeException $e) {
        $symlinkRefused = str_contains($e->getMessage(), 'symlinked')
            || str_contains($e->getMessage(), 'not a real directory');
    }
    duo_check(
        $symlinkRefused && file_get_contents($outside . '/sentinel') === 'preserve',
        'symlinked flat projection refuses before recursive repair and preserves the outside target'
    );
    unlink($directory);

    // Schema/read failure happens in the before observation, before purge.
    mkdir($directory, 0700, true);
    file_put_contents($directory . '/stale-before-schema-refusal', 'preserve');
    $GLOBALS['wpdb']->fail_reads = true;
    $schemaRefused = false;
    try {
        $provider->invoke('rebuild_snippet_state', []);
    } catch (\RuntimeException $e) {
        $schemaRefused = $e->getMessage() === 'duo: Code Snippets verification query failed against wp_snippets';
    }
    duo_check(
        $schemaRefused && is_file($directory . '/stale-before-schema-refusal'),
        'database/schema read failure refuses before any flat-file mutation and redacts DB detail'
    );
    $GLOBALS['wpdb']->fail_reads = false;

    $GLOBALS['cs_network_residue'] = [];
    $residueRefused = false;
    try {
        $provider->invoke('rebuild_snippet_state', []);
    } catch (\RuntimeException $e) {
        $residueRefused = str_contains($e->getMessage(), 'residual multisite snippet state');
    }
    duo_check($residueRefused, 'single-site provider refuses residual network identity state instead of touching it');
    $GLOBALS['cs_network_residue'] = false;

    $remove = new \Code_Snippets\WordPress_File_System_Adapter();
    $remove->delete(WP_CONTENT_DIR, true);

    duo_check_summary('Code Snippets state provider');
}

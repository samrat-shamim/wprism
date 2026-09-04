<?php
declare(strict_types=1);

namespace {
    if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
    $root = dirname(__DIR__, 3);
    require_once "$root/sandbox/tests/support/wp_cli_child_process_fake.php";

    $GLOBALS['pll_scoped_options'] = [
        'polylang' => ['default_lang' => '', 'nav_menus' => ['fixture-theme' => []]],
        'stylesheet' => 'fixture-theme',
        'permalink_structure' => '/%postname%/',
        'default_category' => 1,
        'rewrite_rules' => ['^stale$' => 'index.php?stale=1'],
        'theme_mods_fixture-theme' => ['nav_menu_locations' => ['stale' => 9]],
    ];

    function get_option(string $name, mixed $default = false): mixed {
        return array_key_exists($name, $GLOBALS['pll_scoped_options'])
            ? $GLOBALS['pll_scoped_options'][$name]
            : $default;
    }

    function update_option(string $name, mixed $value, mixed $autoload = null): bool {
        $changed = !array_key_exists($name, $GLOBALS['pll_scoped_options'])
            || $GLOBALS['pll_scoped_options'][$name] !== $value;
        $GLOBALS['pll_scoped_options'][$name] = $value;
        return $changed;
    }

    function add_option(string $name, mixed $value, mixed $deprecated = '', mixed $autoload = 'yes'): bool {
        if (array_key_exists($name, $GLOBALS['pll_scoped_options'])) {
            return false;
        }
        $GLOBALS['pll_scoped_options'][$name] = $value;
        return true;
    }

    function get_theme_mod(string $name, mixed $default = false): mixed {
        $mods = get_option('theme_mods_' . (string) get_option('stylesheet'), []);
        return is_array($mods) ? ($mods[$name] ?? $default) : $default;
    }

    function set_theme_mod(string $name, mixed $value): void {
        $key = 'theme_mods_' . (string) get_option('stylesheet');
        $mods = get_option($key, []);
        $mods = is_array($mods) ? $mods : [];
        $mods[$name] = $value;
        update_option($key, $mods);
    }

    function wp_cache_delete(string $key, string $group = ''): bool { return true; }
    function wp_get_nav_menu_object(int $menuId): object|false { return false; }
    function pll_get_term(int $termId, string $language): int|false { return false; }
    function pll_get_term_language(int $termId, string $field = 'slug'): string|false { return false; }
    function get_term_meta(int $termId, string $key, bool $single = false): mixed { return ''; }
    function clean_term_cache(int|array $termIds, string $taxonomy = ''): void {}
    function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false {
        return json_encode($value, $flags, $depth);
    }

    final class PllScopedModel {
        public function clean_languages_cache(): void {}
        public function get_languages_list(): array { return []; }
    }

    function PLL(): object {
        static $runtime;
        return $runtime ??= (object) ['model' => new PllScopedModel()];
    }

    final class WP_CLI {
        use \WPrismTest\WpCliChildRuntime;

        public static function runcommand(string $command, array $options): object {
            if ($command === 'rewrite flush') {
                $GLOBALS['pll_scoped_options']['rewrite_rules'] = ['^fresh$' => 'index.php?fresh=1'];
                return (object) [
                    'return_code' => 0,
                    'stdout' => "Success: Rewrite rules flushed.\n",
                    'stderr' => '',
                ];
            }
            $projection = ['catalogs' => []];
            return (object) [
                'return_code' => 0,
                'stdout' => 'WPRISM_PLL_NATIVE:'
                    . base64_encode((string) json_encode($projection)) . "\n",
                'stderr' => '',
            ];
        }
    }

}

namespace WPrism {
    final class Policy {
        public const SURFACE_PATTERN = '/^(post|term|table|option|entity):[a-z0-9][a-z0-9._-]{0,127}$/D';
    }
}

namespace {
    require_once "$root/sandbox/tests/lib/wp_stubs.php";
    require_once "$root/sandbox/tests/lib/FakeWpdb.php";
    require_once "$root/agent/src/Adapter/Providers.php";
    require_once "$root/adapter-packages/polylang/package/runtime/providers/polylang-nav-menus.php";

    $GLOBALS['wpdb'] = (new \WPrismTest\FakeWpdb())
        ->setColumns('options', [
            'option_id' => 'bigint unsigned',
            'option_name' => 'varchar(191)',
            'option_value' => 'longtext',
            'autoload' => 'varchar(20)',
        ])
        ->setAutoIncrement('options', 1, 'option_id')
        ->setUniqueKey('options', ['option_name'])
        ->setIndexes('options', [[
            'Key_name' => 'option_name',
            'Seq_in_index' => 1,
            'Column_name' => 'option_name',
            'Sub_part' => null,
            'Non_unique' => 0,
            'Index_type' => 'BTREE',
        ]])
        ->setTableEngine('options', 'InnoDB')
        ->seedTable('options', [])
        ->enableInformationSchema();
    $manifest = json_decode(
        (string) file_get_contents("$root/adapter-packages/polylang/package/manifest.json"),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $provider = new \WPrism\Providers\PolylangNavMenus($manifest['providers'][0]);
    $action = [
        'kind' => 'provider',
        'provider' => 'polylang-nav-menus',
        'capability' => 'synchronize_runtime',
        'args' => [],
    ];
    $declaration = $provider->capabilities()['synchronize_runtime'];
    $operation = [
        'authority_hash' => str_repeat('a', 64),
        'lease_session_id' => 'polylang-fixture-session',
        'operation_id' => 'polylang-fixture-operation',
        'input_hash' => \WPrism\Providers::scoped_input_hash($action, $declaration),
        'effect_hash' => str_repeat('c', 64),
    ];
    $invoked = \WPrism\Providers::invoke_scoped($provider, $action, $declaration, $operation);
    $reconciled = \WPrism\Providers::reconcile_scoped($provider, $action, $declaration, $operation);
    echo json_encode([
        'invoke_status' => $invoked['status'] ?? null,
        'reconcile_status' => $reconciled['status'] ?? null,
        'invoke_after_hash' => $invoked['after_hash'] ?? null,
        'reconcile_after_hash' => $reconciled['after_hash'] ?? null,
    ], JSON_UNESCAPED_SLASHES) . "\n";
}

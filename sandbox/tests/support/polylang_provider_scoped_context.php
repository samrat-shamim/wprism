<?php
declare(strict_types=1);

namespace {
    if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

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
                'stdout' => 'DUO_PLL_NATIVE:'
                    . base64_encode((string) json_encode($projection)) . "\n",
                'stderr' => '',
            ];
        }
    }

    final class PllScopedWpdb {
        public string $options = 'wp_options';
        public string $last_error = '';

        public function prepare(string $sql, mixed ...$args): string {
            foreach ($args as $arg) {
                $quoted = "'" . str_replace("'", "''", (string) $arg) . "'";
                $sql = preg_replace('/%s/', $quoted, $sql, 1) ?? $sql;
            }
            return $sql;
        }

        public function get_var(string $sql): mixed {
            if (preg_match("/option_name = '((?:''|[^'])*)'/D", $sql, $match) !== 1) {
                throw new \RuntimeException('fixture received an unsupported scoped receipt query');
            }
            $name = str_replace("''", "'", $match[1]);
            return $GLOBALS['pll_scoped_options'][$name] ?? null;
        }
    }
}

namespace Duo {
    final class Policy {
        public const SURFACE_PATTERN = '/^(post|term|table|option|entity):[a-z0-9][a-z0-9._-]{0,127}$/D';
    }

    final class WpCliChildProcess {
        /** @return array{return_code:int,stdout:string,stderr:string} */
        public static function capture(
            string $command,
            int $timeoutSeconds,
            int $stdoutLimit,
            int $stderrLimit
        ): array {
            if (!str_starts_with($command, 'eval ')
                || $timeoutSeconds !== 120
                || $stdoutLimit !== 262144
                || $stderrLimit !== 131072) {
                throw new \RuntimeException('fixture received an invalid bounded catalog-child contract');
            }
            $projection = ['catalogs' => []];
            return [
                'return_code' => 0,
                'stdout' => 'DUO_PLL_NATIVE:'
                    . base64_encode((string) json_encode($projection)) . "\n",
                'stderr' => '',
            ];
        }
    }
}

namespace {
    $root = dirname(__DIR__, 3);
    require_once "$root/agent/src/Adapter/Providers.php";
    require_once "$root/manifests/providers/polylang-nav-menus.php";

    $GLOBALS['wpdb'] = new PllScopedWpdb();
    $provider = new \Duo\Providers\PolylangNavMenus(new \Duo\Policy());
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
        'input_hash' => \Duo\Providers::scoped_input_hash($action, $declaration),
        'effect_hash' => str_repeat('c', 64),
    ];
    $invoked = \Duo\Providers::invoke_scoped($provider, $action, $declaration, $operation);
    $reconciled = \Duo\Providers::reconcile_scoped($provider, $action, $declaration, $operation);
    echo json_encode([
        'invoke_status' => $invoked['status'] ?? null,
        'reconcile_status' => $reconciled['status'] ?? null,
        'invoke_after_hash' => $invoked['after_hash'] ?? null,
        'reconcile_after_hash' => $reconciled['after_hash'] ?? null,
    ], JSON_UNESCAPED_SLASHES) . "\n";
}

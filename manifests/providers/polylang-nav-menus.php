<?php
namespace Duo\Providers;

use Duo\Policy;
use Duo\WpCliChildProcess;

if (!class_exists(WpCliChildProcess::class, false)) {
    require_once __DIR__ . '/../../agent/src/Kernel/WpCliChildProcess.php';
}

/**
 * Close Polylang's hook-free apply gap for its three derived projections.
 *
 * Polylang 3.8 through 3.8.7 updates nav-menu locations, the translated
 * default category, and rewrite rules only from its settings save path
 * (`Model\Languages::update_default()` and `Settings\Settings_Module`). Duo
 * writes the reviewed option sub-keys without those hooks, so a target can
 * otherwise retain the previous default language's menu/category and stale
 * routes indefinitely. This provider reproduces those bounded native effects
 * and refuses unless fresh readback proves all three values agree.
 */
final class PolylangNavMenus {
    private const LANGUAGE_SLUG_MAX_BYTES = 200;
    private const THEME_COMPONENT_MAX_BYTES = 764;
    private const NAV_LOCATION_MAX_BYTES = 764;
    private const NAV_MAX_LOCATIONS = 1024;
    private const NAV_MAX_LANGUAGES_PER_LOCATION = 512;
    private const NAV_MAX_ASSIGNMENTS = 32768;
    private const NAV_MAX_TOTAL_BYTES = 4194304;
    private const CATALOG_MAX_ROWS = 10000;
    private const CATALOG_MAX_TOTAL_BYTES = 16777216;
    private const CATALOG_CHILD_PREFIX = 'DUO_PLL_NATIVE:';
    private Policy $policy;

    public function __construct(Policy $policy) {
        $this->policy = $policy;
    }

    /** @return array{id:string, plugin:string, version:string} */
    public function identity(): array {
        return [
            'id' => 'polylang-nav-menus',
            'plugin' => 'polylang/polylang.php',
            'version' => '2.0.0',
        ];
    }

    public function capabilities(): array {
        return [
            'synchronize_runtime' => [
                'args' => [],
                'reads' => [
                    'option:polylang',
                    'option:stylesheet',
                    'option:default_category',
                    'option:permalink_structure',
                    'option:rewrite_rules',
                    'term:language',
                    'entity:nav-menu',
                ],
                'writes' => [
                    'entity:theme-mods-nav-menu-locations',
                    'option:default_category',
                    'option:rewrite_rules',
                ],
                'scope' => 'site',
                'idempotent' => true,
                // A fresh `wp rewrite flush` child is deliberately bounded;
                // it has no site-size-dependent traversal.
                'timeout_seconds' => 120,
                'scoped' => [
                    'operation_envelope' => \Duo\Providers::SCOPED_OPERATION_FORMAT,
                    'reconcile' => true,
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $args */
    public function invoke(string $capability, array $args): array {
        if ($capability !== 'synchronize_runtime') {
            throw new \RuntimeException(
                "duo: Polylang runtime provider does not implement capability '$capability'"
            );
        }

        $this->assert_runtime();
        $this->purge_polylang_language_cache();
        $configuration = $this->configuration();
        $before = $this->observe(false, $configuration);
        $this->synchronize_default_category($configuration);
        $this->synchronize_nav_menu_locations($configuration);
        $this->flush_rewrite_rules();
        $this->purge_polylang_language_cache();
        $nativeCatalogs = $this->verify_fresh_native_catalogs();
        $after = $this->observe(true, $configuration);

        return [
            'before' => $before,
            'after' => $after + [
                'native_catalogs_hash' => $nativeCatalogs,
            ],
            'verified' => true,
        ];
    }

    /** @param array<string,mixed> $args @param array<string,mixed> $operation */
    public function invoke_scoped(string $capability, array $args, array $operation): array {
        $receipt = $this->invoke($capability, $args);
        return [
            'operation' => $operation,
            'before' => $receipt['before'],
            'after' => $receipt['after'],
            'verified' => true,
        ];
    }

    /** @param array<string,mixed> $args @param array<string,mixed> $operation */
    public function reconcile_scoped(string $capability, array $args, array $operation): array {
        if ($capability !== 'synchronize_runtime') {
            throw new \RuntimeException(
                "duo: Polylang runtime provider does not implement capability '$capability'"
            );
        }
        $this->assert_runtime();
        $this->purge_polylang_language_cache();
        $configuration = $this->configuration();
        $nativeCatalogs = $this->verify_fresh_native_catalogs();
        return [
            'operation' => $operation,
            'after' => $this->observe(true, $configuration) + [
                'native_catalogs_hash' => $nativeCatalogs,
            ],
            'verified' => true,
        ];
    }

    private function assert_runtime(): void {
        foreach ([
            'get_option',
            'get_theme_mod',
            'set_theme_mod',
            'update_option',
            'wp_cache_delete',
            'wp_get_nav_menu_object',
            'pll_get_term',
            'pll_get_term_language',
            'PLL',
            'get_term_meta',
            'clean_term_cache',
        ] as $required) {
            if (!function_exists($required)) {
                throw new \RuntimeException(
                    "duo: Polylang runtime synchronization requires WordPress/Polylang's $required()"
                );
            }
        }
        if (!class_exists('WP_CLI') || !is_callable(['WP_CLI', 'runcommand'])) {
            throw new \RuntimeException(
                'duo: Polylang runtime synchronization requires WP_CLI::runcommand()'
            );
        }
    }

    /** @return array<string,mixed> */
    private function configuration(): array {
        $polylang = get_option('polylang');
        $stylesheet = get_option('stylesheet');
        if (!is_array($polylang)) {
            throw new \RuntimeException('duo: Polylang option is not an array; recovery_required');
        }
        if (!$this->valid_stylesheet($stylesheet)) {
            throw new \RuntimeException('duo: Polylang active stylesheet is invalid; recovery_required');
        }
        $defaultLang = $polylang['default_lang'] ?? null;
        if (!is_string($defaultLang)
            || strlen($defaultLang) > self::LANGUAGE_SLUG_MAX_BYTES
            || ($defaultLang !== '' && preg_match('/^[a-z][a-z0-9_-]*$/D', $defaultLang) !== 1)) {
            throw new \RuntimeException('duo: Polylang default language is invalid; recovery_required');
        }
        $allNavMenus = $polylang['nav_menus'] ?? null;
        if (!is_array($allNavMenus) || ($allNavMenus !== [] && array_is_list($allNavMenus))) {
            throw new \RuntimeException('duo: Polylang nav_menus option is invalid; recovery_required');
        }
        $activeThemeDeclared = array_key_exists($stylesheet, $allNavMenus);
        $navMenus = $activeThemeDeclared ? $allNavMenus[$stylesheet] : [];
        if (!is_array($navMenus)
            || ($navMenus !== [] && array_is_list($navMenus))
            || count($navMenus) > self::NAV_MAX_LOCATIONS) {
            throw new \RuntimeException('duo: Polylang nav_menus theme map is invalid; recovery_required');
        }
        $expected = [];
        $assignments = 0;
        $bytes = strlen($stylesheet) + strlen($defaultLang);
        foreach ($navMenus as $location => $byLanguage) {
            if (!is_string($location)
                || strlen($location) > self::NAV_LOCATION_MAX_BYTES
                || $location === ''
                || preg_match('//u', $location) !== 1
                || preg_match('/[\x00-\x1F\x7F]/', $location) === 1
                || !is_array($byLanguage)
                || ($byLanguage !== [] && array_is_list($byLanguage))
                || count($byLanguage) > self::NAV_MAX_LANGUAGES_PER_LOCATION) {
                throw new \RuntimeException('duo: Polylang nav_menus location map is invalid; recovery_required');
            }
            $bytes += strlen($location);
            foreach ($byLanguage as $language => $menuId) {
                if (!is_string($language) || $language === ''
                    || strlen($language) > self::LANGUAGE_SLUG_MAX_BYTES
                    || preg_match('/^[a-z][a-z0-9_-]*$/D', $language) !== 1
                    || !is_int($menuId) || $menuId < 0) {
                    throw new \RuntimeException('duo: Polylang nav_menus language entry is invalid; recovery_required');
                }
                ++$assignments;
                $bytes += strlen($language) + 8;
                if ($assignments > self::NAV_MAX_ASSIGNMENTS || $bytes > self::NAV_MAX_TOTAL_BYTES) {
                    throw new \RuntimeException(
                        'duo: Polylang nav_menus exceeds the bounded aggregate; recovery_required'
                    );
                }
                if ($menuId > 0 && !is_object(wp_get_nav_menu_object($menuId))) {
                    throw new \RuntimeException(
                        'duo: Polylang nav_menus references a missing target menu; recovery_required'
                    );
                }
            }
            if ($defaultLang !== '') {
                $expected[$location] = $byLanguage[$defaultLang] ?? 0;
            }
        }
        ksort($expected, SORT_STRING);

        $defaultCategory = get_option('default_category');
        if (!(is_int($defaultCategory)
            || (is_string($defaultCategory) && preg_match('/^[1-9][0-9]*$/D', $defaultCategory) === 1))) {
            throw new \RuntimeException('duo: Polylang default category is invalid; recovery_required');
        }
        $defaultCategory = (int) $defaultCategory;
        $expectedCategory = null;
        if ($defaultLang !== '') {
            $language = pll_get_term_language($defaultCategory, 'slug');
            if ($language === $defaultLang) {
                $expectedCategory = $defaultCategory;
            } else {
                $translated = pll_get_term($defaultCategory, $defaultLang);
                if (!(is_int($translated)
                    || (is_string($translated) && preg_match('/^[1-9][0-9]*$/D', $translated) === 1))) {
                    throw new \RuntimeException(
                        'duo: Polylang default category has no target-language translation; recovery_required'
                    );
                }
                $expectedCategory = (int) $translated;
            }
        }

        $permalinkStructure = get_option('permalink_structure', '');
        if (!is_string($permalinkStructure)) {
            throw new \RuntimeException('duo: Polylang permalink_structure is invalid; recovery_required');
        }

        return [
            'default_lang' => $defaultLang,
            'expected_category' => $expectedCategory,
            'expected_locations' => $defaultLang === '' || !$activeThemeDeclared ? null : $expected,
            'permalink_structure' => $permalinkStructure,
            'stylesheet' => $stylesheet,
        ];
    }

    private function valid_stylesheet(mixed $value): bool {
        return is_string($value)
            && $value !== ''
            && strlen($value) <= self::THEME_COMPONENT_MAX_BYTES
            && preg_match('//u', $value) === 1
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
            && preg_match('/^[^\/:<>*?"|]+$/uD', $value) === 1;
    }

    private function synchronize_nav_menu_locations(array $configuration): void {
        if ($configuration['expected_locations'] === null) {
            return;
        }
        set_theme_mod('nav_menu_locations', $configuration['expected_locations']);
    }

    private function synchronize_default_category(array $configuration): void {
        if ($configuration['expected_category'] === null
            || (int) get_option('default_category') === $configuration['expected_category']) {
            return;
        }
        update_option('default_category', $configuration['expected_category']);
    }

    private function flush_rewrite_rules(): void {
        try {
            $result = \WP_CLI::runcommand('rewrite flush', [
                'launch' => true,
                'return' => 'all',
                'exit_error' => false,
            ]);
        } catch (\Throwable $t) {
            throw new \RuntimeException(
                "duo: Polylang 'wp rewrite flush' could not start",
                0,
                $t
            );
        }
        if (!is_object($result)
            || !is_int($result->return_code ?? null)
            || !is_string($result->stdout ?? null)
            || !is_string($result->stderr ?? null)) {
            throw new \RuntimeException(
                "duo: Polylang 'wp rewrite flush' returned an unreadable process result"
            );
        }
        if ($result->return_code !== 0) {
            throw new \RuntimeException(
                "duo: Polylang 'wp rewrite flush' exited {$result->return_code}; recovery_required"
            );
        }
        if ($result->stderr !== '') {
            throw new \RuntimeException(
                "duo: Polylang 'wp rewrite flush' emitted stderr despite exit 0; recovery_required"
            );
        }
        if (preg_match('/Success:\s+Rewrite rules flushed\./i', $result->stdout) !== 1) {
            throw new \RuntimeException(
                "duo: Polylang 'wp rewrite flush' exited 0 without its native success receipt"
            );
        }

        // The launched command is a child process. Expire this process's
        // option caches before the postcondition reads the committed row.
        wp_cache_delete('rewrite_rules', 'options');
        wp_cache_delete('alloptions', 'options');
    }

    /** @return array<string,mixed> */
    private function observe(bool $verify, array $configuration): array {
        $rawLocations = $this->raw_locations($configuration['stylesheet']);
        $defaultCategory = get_option('default_category');
        $defaultCategory = is_int($defaultCategory)
            || (is_string($defaultCategory) && preg_match('/^[1-9][0-9]*$/D', $defaultCategory) === 1)
            ? (int) $defaultCategory
            : 0;
        $categoryLanguage = $configuration['default_lang'] !== '' && $defaultCategory > 0
            ? pll_get_term_language($defaultCategory, 'slug')
            : false;
        $rewriteRules = get_option('rewrite_rules');
        $validRules = is_array($rewriteRules);
        $prettyPermalinks = $configuration['permalink_structure'] !== '';

        if ($verify) {
            if ($configuration['expected_locations'] !== null
                && $rawLocations !== $configuration['expected_locations']) {
                throw new \RuntimeException(
                    'duo: Polylang raw nav_menu_locations does not match its default-language map; recovery_required'
                );
            }
            if ($configuration['expected_category'] !== null
                && $defaultCategory !== $configuration['expected_category']) {
                throw new \RuntimeException(
                    'duo: Polylang default category does not match the exact translated term; recovery_required'
                );
            }
            if ($prettyPermalinks && (!$validRules || $rewriteRules === [])) {
                throw new \RuntimeException(
                    'duo: Polylang rewrite_rules postcondition is missing or invalid; recovery_required'
                );
            }
        }

        return [
            'default_category' => $defaultCategory,
            'default_category_language' => is_string($categoryLanguage) ? $categoryLanguage : '',
            'default_lang' => $configuration['default_lang'],
            'nav_menu_locations_count' => count($rawLocations),
            'nav_menu_locations_hash' => hash('sha256', serialize($rawLocations)),
            'rewrite_rules_count' => $validRules ? count($rewriteRules) : 0,
            'rewrite_rules_hash' => $validRules ? hash('sha256', serialize($rewriteRules)) : '',
            'stylesheet' => $configuration['stylesheet'],
        ];
    }

    /** @return array<string,int> */
    private function raw_locations(string $stylesheet): array {
        $themeMods = get_option('theme_mods_' . $stylesheet);
        if (!is_array($themeMods)) {
            return [];
        }
        $locations = $themeMods['nav_menu_locations'] ?? [];
        if (!is_array($locations)) {
            throw new \RuntimeException(
                'duo: Polylang raw nav_menu_locations storage is invalid; recovery_required'
            );
        }
        if (count($locations) > self::NAV_MAX_LOCATIONS) {
            throw new \RuntimeException(
                'duo: Polylang raw nav_menu_locations exceeds the bounded location limit; recovery_required'
            );
        }
        foreach ($locations as $location => $menuId) {
            if (!is_string($location)
                || $location === ''
                || strlen($location) > self::NAV_LOCATION_MAX_BYTES
                || preg_match('//u', $location) !== 1
                || preg_match('/[\x00-\x1F\x7F]/', $location) === 1
                || !is_int($menuId) || $menuId < 0) {
                throw new \RuntimeException(
                    'duo: Polylang raw nav_menu_locations entry is invalid; recovery_required'
                );
            }
        }
        ksort($locations, SORT_STRING);
        return $locations;
    }

    private function purge_polylang_language_cache(): void {
        $runtime = PLL();
        $model = is_object($runtime) ? ($runtime->model ?? null) : null;
        if (!is_object($model) || !is_callable([$model, 'clean_languages_cache'])) {
            throw new \RuntimeException(
                'duo: Polylang runtime synchronization requires the native language-cache purge boundary'
            );
        }
        $model->clean_languages_cache();
    }

    private function verify_fresh_native_catalogs(): string {
        $expected = $this->catalog_projection(true);
        $code = <<<'PHP'
$runtime = function_exists('PLL') ? PLL() : null;
$model = is_object($runtime) ? ($runtime->model ?? null) : null;
$languages = is_object($model) && is_callable(array($model, 'get_languages_list'))
    ? $model->get_languages_list()
    : null;
$projection = array('catalogs' => array());
if (is_array($languages)) {
    foreach ($languages as $language) {
        if (!is_object($language) || !is_string($language->slug ?? null) || !is_int($language->term_id ?? null)) {
            $projection = null;
            break;
        }
        $raw = get_term_meta($language->term_id, '_pll_strings_translations', true);
        if (!is_array($raw) || !class_exists('PLL_MO')) {
            $projection = null;
            break;
        }
        $mo = new PLL_MO();
        $mo->import_from_db($language);
        $visible = array();
        foreach ($raw as $row) {
            if (!is_array($row) || !is_string($row[0] ?? null)) {
                $projection = null;
                break 2;
            }
            $visible[] = array($row[0], $mo->translate_if_any($row[0]));
        }
        $projection['catalogs'][$language->slug] = array(
            'raw_count' => count($raw),
            'raw_hash' => hash('sha256', serialize($raw)),
            'native_hash' => hash('sha256', serialize($visible)),
        );
    }
}
if (is_array($projection)) {
    ksort($projection['catalogs'], SORT_STRING);
}
echo 'DUO_PLL_NATIVE:' . base64_encode(wp_json_encode($projection)) . "\n";
PHP;
        try {
            // The projection is hashes/counts for at most 512 languages, so
            // 256KiB admits every reviewed receipt while rejecting a plugin
            // warning flood before WP-CLI's sequential return=all capture can
            // deadlock or retain unbounded output (WP-CLI 2.12.0 :1607-1669).
            $result = WpCliChildProcess::capture(
                'eval ' . escapeshellarg($code),
                120,
                262144,
                131072
            );
        } catch (\Throwable) {
            throw new \RuntimeException(
                'duo: Polylang native registry/catalog verification child could not start; recovery_required'
            );
        }
        if ($result['return_code'] !== 0 || $result['stderr'] !== '') {
            throw new \RuntimeException(
                'duo: Polylang native registry/catalog verification child failed; recovery_required'
            );
        }
        $stdout = $result['stdout'];
        if (!str_ends_with($stdout, "\n")
            || str_contains(substr($stdout, 0, -1), "\n")
            || str_contains($stdout, "\r")) {
            throw new \RuntimeException(
                'duo: Polylang native registry/catalog verification child returned no exact receipt; recovery_required'
            );
        }
        $line = substr($stdout, 0, -1);
        if (!str_starts_with($line, self::CATALOG_CHILD_PREFIX)) {
            throw new \RuntimeException(
                'duo: Polylang native registry/catalog verification child returned no exact receipt; recovery_required'
            );
        }
        $encoded = substr($line, strlen(self::CATALOG_CHILD_PREFIX));
        $json = base64_decode($encoded, true);
        $observed = is_string($json) && hash_equals(base64_encode($json), $encoded)
            ? json_decode($json, true)
            : null;
        if (!is_array($observed) || $observed !== $expected) {
            throw new \RuntimeException(
                'duo: Polylang fresh native registry/catalog projection disagrees with exact persisted state; '
                . 'recovery_required'
            );
        }
        return hash('sha256', serialize($observed));
    }

    /** @return array{catalogs:array<string,array>} */
    private function catalog_projection(bool $fresh): array {
        $bytes = 0;
        $runtime = PLL();
        $model = is_object($runtime) ? ($runtime->model ?? null) : null;
        $languages = is_object($model) && is_callable([$model, 'get_languages_list'])
            ? $model->get_languages_list()
            : null;
        if (!is_array($languages) || !array_is_list($languages) || count($languages) > 512) {
            throw new \RuntimeException('duo: Polylang native catalog verification returned an invalid language list');
        }
        $catalogs = [];
        foreach ($languages as $language) {
            $slug = is_object($language) ? ($language->slug ?? null) : null;
            $termId = is_object($language) ? ($language->term_id ?? null) : null;
            if (!is_string($slug)
                || preg_match('/^[a-z][a-z0-9_-]*$/D', $slug) !== 1
                || !is_int($termId)
                || $termId <= 0) {
                throw new \RuntimeException('duo: Polylang native catalog verification returned a malformed language');
            }
            if ($fresh) {
                clean_term_cache($termId, 'language');
            }
            $raw = get_term_meta($termId, '_pll_strings_translations', true);
            if ($raw === '') {
                $raw = [];
            }
            if (!is_array($raw) || !array_is_list($raw) || count($raw) > self::CATALOG_MAX_ROWS) {
                throw new \RuntimeException('duo: Polylang string catalog is invalid or over the bounded frontier');
            }
            $visible = [];
            foreach ($raw as $row) {
                if (!is_array($row) || !array_is_list($row) || count($row) !== 2
                    || !is_string($row[0] ?? null) || !is_string($row[1] ?? null)) {
                    throw new \RuntimeException('duo: Polylang string catalog contains a malformed row');
                }
                $bytes += strlen($row[0]) + strlen($row[1]);
                if ($bytes > self::CATALOG_MAX_TOTAL_BYTES) {
                    throw new \RuntimeException('duo: Polylang string catalogs exceed the bounded byte frontier');
                }
                $visible[] = [$row[0], ($row[0] === '' || $row[1] === '') ? '' : $row[1]];
            }
            $catalogs[$slug] = [
                'raw_count' => count($raw),
                'raw_hash' => hash('sha256', serialize($raw)),
                'native_hash' => hash('sha256', serialize($visible)),
            ];
        }
        ksort($catalogs, SORT_STRING);
        return ['catalogs' => $catalogs];
    }
}

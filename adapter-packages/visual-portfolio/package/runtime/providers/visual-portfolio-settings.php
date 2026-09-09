<?php
declare(strict_types=1);

namespace WPrism\Providers;

use WPrism\ManifestProviderRuntime;
use WPrism\ProviderSdk;

/**
 * Direct authored writes bypass VP 3.8.1's settings-save projections. Archive
 * routing is initialized at init:9, so the engine's two fresh WordPress boots
 * own invocation and independent durable observation. Plugin semantics stay
 * here; no package-owned process, SQL transaction, or broad authored scope.
 */
final class VisualPortfolioSettings extends ManifestProviderRuntime {
    private const MARKER = '_vp_post_type_mapped';
    private const DERIVED_OPTIONS = [
        'rewrite_rules', 'visual_portfolio_updated_caps',
        '_transient_vp_flush_rewrite_rules', '_transient_timeout_vp_flush_rewrite_rules',
    ];
    private const DEPENDENCIES = [
        'posts' => [['ID'], ['ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title',
            'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name',
            'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent',
            'guid', 'menu_order', 'post_type', 'post_mime_type', 'comment_count']],
        'terms' => [['term_id'], ['term_id', 'name', 'slug', 'term_group']],
        'term_taxonomy' => [['term_taxonomy_id'], ['term_taxonomy_id', 'term_id', 'taxonomy', 'description', 'parent', 'count']],
        'term_relationships' => [['object_id', 'term_taxonomy_id'], ['object_id', 'term_taxonomy_id', 'term_order']],
        'termmeta' => [['meta_id'], ['meta_id', 'term_id', 'meta_key', 'meta_value']],
        'users' => [['ID'], ['ID', 'user_login', 'user_pass', 'user_nicename', 'user_email', 'user_url',
            'user_registered', 'user_activation_key', 'user_status', 'display_name']],
        'usermeta' => [['umeta_id'], ['umeta_id', 'user_id', 'meta_key', 'meta_value']],
    ];

    protected function invoke_reconcile_settings(array $args): array {
        self::arguments($args);
        $first = $this->rebuild_pass();
        if ($this->durable_snapshot() !== $first['after']) self::refuse('committed settings changed before readback; recovery_required');
        $second = $this->rebuild_pass();
        if ($first['after'] !== $second['before'] || $second['before'] !== $second['after']) {
            self::refuse('native settings repair did not reach a fixed point; recovery_required');
        }
        if ($this->durable_snapshot() !== $second['after']) self::refuse('fixed-point settings changed after commit; recovery_required');
        return ['before' => $first['before'], 'after' => $second['after'], 'verified' => true];
    }

    protected function reconcile_reconcile_settings(array $args): array {
        self::arguments($args);
        return $this->durable_snapshot();
    }

    protected function project_reconcile_settings(array $value): array {
        $value = $this->project_fresh_postimage_reconcile_settings($value);
        return ['derived_sha256' => $value['derived_sha256'], 'files_sha256' => $value['files_sha256']];
    }

    protected function observe_fresh_postimage_reconcile_settings(array $args): array {
        self::arguments($args);
        return self::projection($this->physical_state());
    }

    protected function project_fresh_postimage_reconcile_settings(array $value): array {
        if (array_keys($value) !== ['derived_sha256', 'files_sha256', 'inputs_sha256', 'remainder_sha256']) {
            self::refuse('settings projection is malformed; recovery_required');
        }
        foreach ($value as $hash) {
            if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                self::refuse('settings projection is malformed; recovery_required');
            }
        }
        return $value;
    }

    private function durable_snapshot(): array {
        return ProviderSdk::database_read_contract_snapshot('Visual Portfolio durable settings',
            fn(): array => self::projection($this->physical_state()));
    }

    private function rebuild_pass(): array {
        return ProviderSdk::database_write_contract_transaction('Visual Portfolio settings repair', function (): array {
            $state = $this->physical_state();
            $before = self::projection($state);
            self::runtime();
            [$archive, $enabled] = $this->native_settings();
            $roles = $this->expected_roles($enabled);
            if (self::projection($this->physical_state()) !== $before) self::refuse('native settings reads changed durable state');
            if ($archive !== 0) {
                $types = ProviderSdk::checked_native_post_types([$archive], 'Visual Portfolio archive page');
                if ($types !== ['page']) self::refuse('selected archive is not an existing native page');
            }
            // The native helper deletes all old markers, defers a hard flush,
            // then update_post_meta(0) is WordPress's no-op for a cleared page.
            // Skip an already correct marker to preserve its physical identity.
            if (!self::markers_agree($state['markers'], $archive)) {
                \Visual_Portfolio_Archive_Mapping::save_archive_page_option($archive);
                foreach ($state['markers'] as $row) wp_cache_delete((int) $row['post_id'], 'post_meta');
                if ($archive !== 0) wp_cache_delete($archive, 'post_meta');
            }
            if ($enabled) \Visual_Portfolio_Custom_Post_Type::sync_roles_and_caps(true);
            else \Visual_Portfolio_Custom_Post_Type::sync_roles_without_portfolio(true);

            $plugin = visual_portfolio();
            $plugin->defer_flush_rewrite_rules();
            $plugin->run_deferred_rewrite_rules();
            $this->assert_native_postcondition($archive, $enabled, $roles);
            $after = self::projection($this->physical_state());
            if ($before['inputs_sha256'] !== $after['inputs_sha256'] || $before['remainder_sha256'] !== $after['remainder_sha256']) {
                self::refuse('native settings repair changed an input or an unrelated row');
            }
            return ['before' => $before, 'after' => $after];
        }, function (array $result): string {
            $actual = self::projection($this->physical_state());
            if ($actual === $result['after']) return ProviderSdk::DATABASE_POSTIMAGE_APPLIED;
            if ($actual === $result['before']) return ProviderSdk::DATABASE_POSTIMAGE_NOT_APPLIED;
            return ProviderSdk::DATABASE_POSTIMAGE_UNKNOWN;
        });
    }

    private function native_settings(): array {
        $values = ProviderSdk::native_option_inputs([
            ['name' => 'vp_general', 'default' => false, 'passed_default' => false, 'reads' => 2],
        ], static fn(): array => [
            \Visual_Portfolio_Settings::get_option('portfolio_archive_page', 'vp_general'),
            \Visual_Portfolio_Custom_Post_Type::portfolio_post_type_is_registered(),
        ], 'Visual Portfolio native general settings');
        $archive = $values[0];
        if ($archive === false || $archive === null || $archive === '' || $archive === '0' || $archive === 0) $archive = 0;
        elseif ((!is_int($archive) && !is_string($archive))
            || preg_match('/^[1-9][0-9]*$/D', (string) $archive) !== 1
            || (string) (int) $archive !== (string) $archive) self::refuse('archive setting is not a bounded native page identity');
        return [(int) $archive, (bool) $values[1]];
    }

    private function expected_roles(bool $enabled): array {
        global $wpdb;
        $expected = ProviderSdk::checked_durable_option($wpdb->prefix . 'user_roles', null, 'Visual Portfolio native roles');
        $registry = wp_roles();
        if (!is_array($expected) || !isset($expected['author']['capabilities'])
            || !is_array($expected['author']['capabilities']) || $registry->roles !== $expected
            || $registry->role_key !== $wpdb->prefix . 'user_roles' || $registry->use_db !== true) {
            self::refuse('native role registry disagrees with durable single-site roles');
        }
        foreach ($expected as $role) {
            if (!is_array($role) || !is_string($role['name'] ?? null) || !is_array($role['capabilities'] ?? null)) {
                self::refuse('native role record is malformed');
            }
        }
        if ($enabled) {
            foreach (['portfolio_manager' => 'Portfolio Manager', 'portfolio_author' => 'Portfolio Author'] as $name => $label) {
                if (!isset($expected[$name])) $expected[$name] = ['name' => __($label, 'visual-portfolio'), 'capabilities' => $expected['author']['capabilities']];
            }
        }
        foreach (\Visual_Portfolio_Custom_Post_Type::get_portfolio_caps() as $cap) {
            foreach (['portfolio_manager', 'portfolio_author', 'administrator', 'editor'] as $name) {
                if (!isset($expected[$name])) continue;
                if ($enabled) $expected[$name]['capabilities'][$cap] = true;
                else unset($expected[$name]['capabilities'][$cap]);
            }
        }
        if (!$enabled) {
            unset($expected['portfolio_author']);
            if (!isset($expected['portfolio_manager'])) {
                $expected['portfolio_manager'] = ['name' => __('Portfolio Manager', 'visual-portfolio'), 'capabilities' => $expected['author']['capabilities']];
            }
        }
        foreach (\Visual_Portfolio_Custom_Post_Type::get_lists_caps() as $cap) {
            foreach (['portfolio_manager', 'administrator'] as $name) {
                if (isset($expected[$name])) $expected[$name]['capabilities'][$cap] = true;
            }
        }
        return $expected;
    }

    private function assert_native_postcondition(int $archive, bool $enabled, array $roles): void {
        global $wpdb, $wp_rewrite, $wp_version;
        $state = $this->physical_state();
        if (!self::markers_agree($state['markers'], $archive)) self::refuse('archive marker did not converge on the selected page');
        if (ProviderSdk::checked_durable_option($wpdb->prefix . 'user_roles', null, 'Visual Portfolio repaired roles') !== $roles
            || wp_roles()->roles !== $roles) self::refuse('native roles did not converge on the complete expected role map');
        $fingerprint = 'Plugin: ' . VISUAL_PORTFOLIO_VERSION . ' WP: ' . $wp_version . ' Portfolio: ' . ($enabled ? '1' : '0');
        if (ProviderSdk::checked_durable_option('visual_portfolio_updated_caps', null, 'Visual Portfolio role fingerprint') !== $fingerprint) {
            self::refuse('native role fingerprint did not converge');
        }
        foreach (['_transient_vp_flush_rewrite_rules', '_transient_timeout_vp_flush_rewrite_rules'] as $name) {
            if (ProviderSdk::checked_durable_option($name, null, 'Visual Portfolio pending rewrite') !== null) self::refuse('native hard rewrite remains deferred');
        }
        $rules = ProviderSdk::checked_durable_option('rewrite_rules', null, 'Visual Portfolio durable rewrite rules');
        if (!is_array($rules) || $rules !== $wp_rewrite->rules || $rules !== $wp_rewrite->rewrite_rules()) {
            self::refuse('durable rewrite rules disagree with the initialized native routing projection');
        }
    }

    private function physical_state(): array {
        global $wpdb;
        $inputs = [];
        foreach (self::DEPENDENCIES as $property => [$identity, $columns]) {
            $inputs[$property] = self::rows($wpdb->{$property}, $identity, $columns, false);
        }
        $markers = $metaRemainder = $derived = $optionRemainder = [];
        foreach (self::rows($wpdb->postmeta, ['meta_id'], ['meta_id', 'post_id', 'meta_key', 'meta_value'], true)['rows'] as $row) {
            if ($row['meta_key'] === self::MARKER) $markers[] = $row;
            else $metaRemainder[] = $row;
        }
        foreach (self::rows($wpdb->options, ['option_id'], ['option_id', 'option_name', 'option_value', 'autoload'], true)['rows'] as $row) {
            if (in_array($row['option_name'], self::DERIVED_OPTIONS, true) || $row['option_name'] === $wpdb->prefix . 'user_roles') $derived[] = $row;
            else $optionRemainder[] = $row;
        }
        $files = [];
        foreach (['.htaccess', 'web.config'] as $path) $files[] = ProviderSdk::filesystem_file_snapshot(ABSPATH, $path);
        return ['inputs' => $inputs, 'markers' => $markers, 'meta_remainder' => $metaRemainder,
            'derived' => $derived, 'option_remainder' => $optionRemainder, 'files' => $files];
    }

    private static function rows(string $table, array $identity, array $columns, bool $payload): array {
        return ProviderSdk::physical_table_rows(['table' => $table, 'columns' => $columns, 'identity' => $identity,
            'max_rows' => 8192, 'max_raw_bytes' => 16777216, 'mode' => $payload ? 'rows' : 'digest'], 'Visual Portfolio complete settings dependencies');
    }

    private static function projection(array $state): array {
        return ['derived_sha256' => hash('sha256', serialize([$state['markers'], $state['derived']])),
            'files_sha256' => hash('sha256', serialize($state['files'])),
            'inputs_sha256' => hash('sha256', serialize($state['inputs'])),
            'remainder_sha256' => hash('sha256', serialize([$state['meta_remainder'], $state['option_remainder']]))];
    }

    private static function markers_agree(array $rows, int $archive): bool {
        if ($archive === 0) return $rows === [];
        return count($rows) === 1 && $rows[0]['post_id'] === (string) $archive && $rows[0]['meta_value'] === 'portfolio';
    }

    private static function runtime(): void {
        global $wp_rewrite;
        if (is_multisite() || !defined('VISUAL_PORTFOLIO_VERSION') || VISUAL_PORTFOLIO_VERSION !== '3.8.1'
            || !$wp_rewrite instanceof \WP_Rewrite || !did_action('wp_loaded') || !is_blog_installed()) {
            self::refuse('requires initialized single-site Visual Portfolio 3.8.1 and WordPress rewrite APIs');
        }
        // The first experimental lane has one document root. A relocated
        // WordPress home needs its own independently observable file boundary.
        if (!function_exists('get_home_path') || rtrim(get_home_path(), '/') !== rtrim(ABSPATH, '/')) {
            self::refuse('the native server-file root must be the WordPress root');
        }
    }

    private static function arguments(array $args): void {
        if ($args !== []) self::refuse('settings repair accepts no authored arguments');
    }

    private static function refuse(string $reason): never {
        throw new \RuntimeException('wprism: Visual Portfolio ' . $reason);
    }
}

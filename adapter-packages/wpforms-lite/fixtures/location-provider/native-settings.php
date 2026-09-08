<?php
declare(strict_types=1);

require_once __DIR__ . '/settings-evidence.php';
require_once __DIR__ . '/native-admin.php';

/** Real WordPress HTTP/session APIs; only WPForms' form and consumer semantics are capsule-owned. */
final class WPFormsNativeSettings {
    public static function observe(): array {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$wpdb->options} WHERE BINARY option_name IN ('wpforms_settings','wpforms_crypto_secret_key') ORDER BY FIELD(option_name,'wpforms_settings','wpforms_crypto_secret_key')", ARRAY_A);
        WPFormsSettingsEvidence::check(is_array($rows) && $wpdb->last_error === '', 'native complete option rows');
        return ['rows' => $rows, 'values' => get_option('wpforms_settings', [])];
    }

    public static function consumers(): array {
        $frontend = wpforms()->obj('frontend');
        $frontend->assets_css();
        return ['strings' => $frontend->get_strings(), 'global_assets' => $frontend->assets_global(),
            'render_engine' => wpforms_get_render_engine(), 'ip_allowed' => wpforms_is_collecting_ip_allowed(),
            'cookies_allowed' => wpforms_is_collecting_cookies_allowed(),
            'styles' => array_values(array_filter(wp_styles()->queue, static fn(string $handle): bool => str_starts_with($handle, 'wpforms-')))];
    }

    public static function local(): array {
        $before = self::observe();
        $settings = $before['values'];
        // Synthetic hostile target state is installed AFTER the real UI saves.
        // This is preservation evidence, never a claim Lite authored Pro state.
        $settings = array_replace($settings, ['gdpr-disable-uuid' => true, 'gdpr-disable-details' => true,
            'modern-markup-is-set' => 'target-initialized', 'modern-markup-hide-setting' => false,
            'lite-connect-enabled' => '0', 'license-key' => 'sk_live_LOCALFIXTURE123456789012',
            'integrations-providers' => ['customer_email' => 'private@example.test'],
            'future-setting' => ['nested' => [null, false, 0, '0', '日本語 Ω']]]);
        WPFormsSettingsEvidence::check(update_option('wpforms_settings', $settings), 'install synthetic local preservation witnesses');
        return ['format' => 'wprism-wpforms-native-local-settings/v1', 'before' => $before, 'after' => self::observe()];
    }

    public static function author(string $pair, string $side, string $view): void {
        WPFormsSettingsEvidence::check(preg_match('/^[a-z][a-z0-9]+$/D', $pair) === 1
            && in_array($side, ['source', 'target'], true) && in_array($view, ['general', 'validation'], true), 'explicit owned author request');
        WPFormsSettingsEvidence::check(current_user_can('manage_options') && wp_get_current_user()->user_login === 'admin', 'native administrative actor');
        WPFormsSettingsEvidence::check(defined('WP_DEBUG') && defined('WP_DEBUG_LOG') && defined('WP_DEBUG_DISPLAY')
            && [WP_DEBUG, WP_DEBUG_LOG, WP_DEBUG_DISPLAY] === [true, true, false], 'real HTTP server diagnostics enabled');
        WPForms\Helpers\Crypto::get_secret_key();
        $record = ['format' => 'wprism-wpforms-native-settings-author/v1', 'version' => WPFORMS_VERSION,
            'side' => $side, 'view' => $view, 'home' => home_url(), 'actor' => wp_get_current_user()->user_login,
            'debug_config' => [WP_DEBUG, WP_DEBUG_LOG, WP_DEBUG_DISPLAY], 'diagnostics_before' => WPFormsNativeAdminSession::diagnostics(),
            'before' => self::observe(), 'requests' => [], 'session_retired' => false];
        $session = new WPFormsNativeAdminSession($pair, $side);
        try {
            $post = [];
            foreach (['GET', 'POST'] as $method) {
                $reply = $session->request('/wp-admin/admin.php?page=wpforms-settings&view=' . $view, $method, $post);
                $nonce = WPFormsSettingsEvidence::form($reply['body'], $view);
                $post = WPFormsSettingsEvidence::post($side, $view, $nonce);
            }
        } finally {
            $record['requests'] = $session->requests;
            if ($session->transportError !== null) $record['transport_error'] = $session->transportError;
            try {
                $record['session_retired'] = $session->retire();
                wp_cache_delete('wpforms_settings', 'options');
                wp_cache_delete('alloptions', 'options');
                wp_cache_delete('notoptions', 'options');
                $record['after'] = self::observe();
                $record['diagnostics_after'] = WPFormsNativeAdminSession::diagnostics();
            } finally {
                // An incomplete observation still retains the exchanged HTTP
                // bytes; host admission requires every missing field and fails.
                echo wp_json_encode($record, JSON_THROW_ON_ERROR);
            }
        }
        WPFormsSettingsEvidence::author($record, $pair, $side, $view);
    }
}

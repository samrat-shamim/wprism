<?php
declare(strict_types=1);

// Semantic doubles over the shared native getter/cache and SQL interpreter.
// They exercise real Apply/provider bodies, never certify a native WP boot.
define('VISUAL_PORTFOLIO_VERSION', '3.8.1');
$GLOBALS['wp_version'] = '7.1';
$GLOBALS['vp_settings_fault'] = '';
$GLOBALS['vp_native_calls'] = [];

function did_action(string $name): int { return $name === 'wp_loaded' ? 1 : 0; }
function is_blog_installed(): bool { return true; }
function get_home_path(): string { return ABSPATH; }
function __(string $text, string $domain = ''): string { return $text; }

function update_option(string $name, mixed $value, string|bool|null $autoload = null): bool {
    global $wpdb;
    $raw = is_string($value) ? $value : serialize($value);
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $wpdb->options WHERE option_name = %s", $name), ARRAY_A);
    if (is_array($row) && $row['option_value'] === $raw) return false;
    if (is_array($row)) $wpdb->update($wpdb->options, ['option_value' => $raw], ['option_id' => $row['option_id']]);
    else $wpdb->insert($wpdb->options, ['option_name' => $name, 'option_value' => $raw, 'autoload' => 'yes']);
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
    wp_cache_delete($name, 'options');
    return true;
}

function delete_option(string $name): bool {
    global $wpdb;
    $changed = $wpdb->delete($wpdb->options, ['option_name' => $name]);
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
    wp_cache_delete($name, 'options');
    return $changed > 0;
}

final class Visual_Portfolio_Settings {
    public static function get_option(string $name, string $section): mixed {
        $options = get_option($section);
        $value = $options[$name] ?? ($name === 'register_portfolio_post_type' ? 'on' : false);
        return $value === 'on' ? true : ($value === 'off' ? false : $value);
    }
}

final class Visual_Portfolio_Archive_Mapping {
    public static function save_archive_page_option(int $id): int {
        global $wpdb;
        $GLOBALS['vp_native_calls'][] = ['marker', $id];
        if ($GLOBALS['vp_settings_fault'] === 'marker-noop') return $id;
        $wpdb->delete($wpdb->postmeta, ['meta_key' => '_vp_post_type_mapped']);
        visual_portfolio()->defer_flush_rewrite_rules();
        if ($id > 0) $wpdb->insert($wpdb->postmeta, ['post_id' => $id, 'meta_key' => '_vp_post_type_mapped', 'meta_value' => 'portfolio']);
        if ($GLOBALS['vp_settings_fault'] === 'unrelated-meta') {
            $wpdb->update($wpdb->postmeta, ['meta_value' => 'changed'], ['meta_key' => '_vp_views_count']);
        }
        return $id;
    }
}

final class Visual_Portfolio_Custom_Post_Type {
    public static function portfolio_post_type_is_registered(): mixed {
        return Visual_Portfolio_Settings::get_option('register_portfolio_post_type', 'vp_general');
    }
    public static function get_portfolio_caps(): array { return ['edit_portfolios', 'read_portfolio', 'publish_portfolios']; }
    public static function get_lists_caps(): array { return ['edit_vp_lists', 'read_vp_list']; }
    public static function sync_roles_and_caps(bool $force = false): void { self::sync(true); }
    public static function sync_roles_without_portfolio(bool $force = false): void { self::sync(false); }
    private static function sync(bool $enabled): void {
        global $wp_version;
        $GLOBALS['vp_native_calls'][] = ['roles', $enabled];
        if ($GLOBALS['vp_settings_fault'] === 'roles-noop') return;
        $roles = wp_roles();
        if ($enabled) {
            foreach (['portfolio_manager' => 'Portfolio Manager', 'portfolio_author' => 'Portfolio Author'] as $name => $label) {
                $roles->roles[$name] ??= ['name' => $label, 'capabilities' => $roles->roles['author']['capabilities']];
            }
        }
        foreach (self::get_portfolio_caps() as $cap) foreach (['portfolio_manager', 'portfolio_author', 'administrator', 'editor'] as $name) {
            if (!isset($roles->roles[$name])) continue;
            if ($enabled) $roles->roles[$name]['capabilities'][$cap] = true;
            else unset($roles->roles[$name]['capabilities'][$cap]);
        }
        if (!$enabled) {
            unset($roles->roles['portfolio_author']);
            $roles->roles['portfolio_manager'] ??= ['name' => 'Portfolio Manager', 'capabilities' => $roles->roles['author']['capabilities']];
        }
        foreach (self::get_lists_caps() as $cap) foreach (['portfolio_manager', 'administrator'] as $name) {
            if (isset($roles->roles[$name])) $roles->roles[$name]['capabilities'][$cap] = true;
        }
        update_option($roles->role_key, $roles->roles);
        update_option('visual_portfolio_updated_caps', 'Plugin: 3.8.1 WP: ' . $wp_version . ' Portfolio: ' . ($enabled ? '1' : '0'));
    }
}

function wp_roles(): object { return $GLOBALS['vp_fixture_roles']; }

class WP_Rewrite {
    public array $rules = [];
    public function rewrite_rules(): array {
        $options = get_option('vp_general');
        return ['fixture-archive-' . ($options['portfolio_archive_page'] ?? '') => 'index.php?post_type=portfolio'];
    }
}

final class VisualPortfolioSettingsNativeDouble {
    public function defer_flush_rewrite_rules(): void { update_option('_transient_vp_flush_rewrite_rules', '1'); }
    public function run_deferred_rewrite_rules(): void {
        if (!get_option('_transient_vp_flush_rewrite_rules')) return;
        $GLOBALS['vp_native_calls'][] = ['hard-rewrite'];
        if ($GLOBALS['vp_settings_fault'] !== 'rewrite-noop') {
            $GLOBALS['wp_rewrite']->rules = $GLOBALS['wp_rewrite']->rewrite_rules();
            update_option('rewrite_rules', $GLOBALS['wp_rewrite']->rules);
        }
        if ($GLOBALS['vp_settings_fault'] !== 'pending-rewrite') delete_option('_transient_vp_flush_rewrite_rules');
        if ($GLOBALS['vp_settings_fault'] === 'unrelated-option') update_option('visual_portfolio_items_count_notice_state', 'changed');
    }
}
function visual_portfolio(): VisualPortfolioSettingsNativeDouble {
    static $plugin;
    return $plugin ??= new VisualPortfolioSettingsNativeDouble();
}

final class WP_CLI {
    use WPrismTest\WpCliChildRuntime;

    public static function runcommand(string $command, array $options): object {
        [$repo, $policy, $compiled, $contract] = $GLOBALS['vp_apply_context'];
        if (str_contains($command, 'wprism verify-canonical')) {
            $session = WPrism\ScopedApplySession::open(new WPrism\LedgerScopedApplySessionStorage());
            $authority = $session->authority();
            $report = (new WPrism\ConvergenceVerifier($repo, $policy, $contract))->verify_scoped_local($compiled, false,
                $authority['target']['protected_out_of_scope_hash'], $authority['target']['protected_ledger_map_hash'],
                $session->authority_hash_value(), WPrism\ScopedApplySession::hash_value($session->receipts()));
            return (object) ['return_code' => 0, 'stdout' => json_encode($report, JSON_THROW_ON_ERROR), 'stderr' => ''];
        }
        if (!str_contains($command, 'ProviderOperationProcess::child_main')) throw new LogicException('unexpected fixture child command');
        $pending = (new ReflectionMethod(WPrism\ProviderOperationProcess::class, 'pending_identity'))->invoke(null);
        if (!is_array($pending) || $pending['provider'] !== 'visual-portfolio-settings' || $pending['capability'] !== 'reconcile_settings') {
            throw new LogicException('unexpected fixture child identity');
        }
        $provider = $GLOBALS['vp_fixture_provider'];
        if ($pending['operation'] === 'invoke') {
            $GLOBALS['vp_before_native_options'] = array_values(array_filter($GLOBALS['wpdb']->rows('wp_options'),
                static fn(array $row): bool => $row['option_name'] !== 'vp_general'));
            $receipt = (new ReflectionMethod(WPrism\ManifestProviderRuntime::class, 'invokeDirect'))->invoke($provider, 'reconcile_settings', []);
            $result = ['postimage' => $receipt['after'], 'receipt' => $receipt];
        } else {
            $begin = new ReflectionMethod(WPrism\ManifestProviderRuntime::class, 'beginContractInvocation');
            $end = new ReflectionMethod(WPrism\ManifestProviderRuntime::class, 'endContractInvocation');
            $begin->invoke($provider, 'reconcile_settings', $provider->capabilities()['reconcile_settings']);
            try {
                $result = ['postimage' => WPrism\ProviderSdk::database_read_contract_snapshot('Visual Portfolio semantic child observation',
                    static fn(): array => (new ReflectionMethod($provider, 'observe_fresh_postimage_reconcile_settings'))->invoke($provider, []))];
            } finally {
                $end->invoke($provider);
            }
        }
        return (object) ['return_code' => 0, 'stdout' => WPrism\Canon::encode(array_merge($pending,
            ['format' => WPrism\ProviderOperationProcess::RECEIPT_FORMAT, 'result' => $result])), 'stderr' => ''];
    }
}

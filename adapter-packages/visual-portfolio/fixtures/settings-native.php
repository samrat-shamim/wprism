<?php
declare(strict_types=1);

$phase = $args[0] ?? '';
$check = static function (bool $ok, string $reason): void {
    if (!$ok) throw new RuntimeException('Visual Portfolio native settings evidence: ' . $reason);
};
$check(current_user_can('manage_options'), 'owned administrator');
if ($phase === 'pad') {
    for ($i = 0; $i < 8; $i++) {
        $id = wp_insert_post(['post_type' => 'post', 'post_status' => 'trash', 'post_title' => 'Target padding ' . $i], true);
        $check(is_int($id) && $id > 0, 'divergent native target IDs');
    }
    echo json_encode(['phase' => $phase, 'count' => 8], JSON_THROW_ON_ERROR), "\n";
    return;
}
$check(defined('VISUAL_PORTFOLIO_VERSION') && VISUAL_PORTFOLIO_VERSION === '3.8.1', 'exact native artifact');
global $wpdb;
$page = static function (string $slug) use ($wpdb, $check): int {
    $wpdb->last_error = '';
    $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_name = %s AND post_type = 'page' AND post_status = 'publish'", $slug));
    $check($wpdb->last_error === '' && count($ids) === 1, 'one native page for ' . $slug);
    return (int) $ids[0];
};
if ($phase === 'setup-source' || $phase === 'setup-target') {
    // This settings fixture uses the retained native dynamic block with its
    // unrelated source-host CSS removed. It is API-authored, not new UI evidence.
    $blocks = parse_blocks((string) file_get_contents(__DIR__ . '/native/archive.html'));
    unset($blocks[0]['attrs']['custom_css']);
    $body = serialize_blocks($blocks);
    foreach (['vp-old-archive', 'vp-new-archive', 'vp-unrelated-page'] as $slug) {
        $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug,
            'post_title' => $slug, 'post_content' => $body, 'post_date' => '2026-09-09 00:00:00'], true);
        $check(is_int($id) && $id > 0, 'native page creation');
    }
    foreach (['First native portfolio', 'Second native portfolio', 'Third native portfolio'] as $i => $title) {
        $id = wp_insert_post(['post_type' => 'portfolio', 'post_status' => 'publish', 'post_title' => $title,
            'post_content' => '<p>Native portfolio item.</p>', 'post_date' => sprintf('2026-09-0%d 12:00:00', $i + 1)], true);
        $check(is_int($id) && $id > 0, 'native portfolio Save');
    }
    Visual_Portfolio_Settings::update_option('register_portfolio_post_type', 'vp_general', 'on');
    Visual_Portfolio_Settings::update_option('archive_page_items_per_page', 'vp_general', '2');
    $old = $page('vp-old-archive');
    Visual_Portfolio_Settings::update_option('portfolio_archive_page', 'vp_general', (string) $old);
    Visual_Portfolio_Archive_Mapping::save_archive_page_option($old);
    update_post_meta($old, '_vp_views_count', '42');
    add_role('vp_fixture_other_role', 'Other plugin fixture role', ['read' => true, 'other_plugin_cap' => true]);
    if ($phase === 'setup-source') {
        WPrism\Canon::write_file('/siterepo/site.wprism.json', WPrism\Canon::encode([
            'manifests' => ['core', 'visual-portfolio'], 'spec_version' => WPRISM_SPEC_VERSION,
            'policy' => ['post_types' => ['post', 'page', 'attachment', 'portfolio'],
                'taxonomies' => ['category', 'post_tag', 'portfolio_category', 'portfolio_tag']],
        ]));
    }
    echo json_encode(['phase' => $phase, 'old' => $old, 'new' => $page('vp-new-archive')], JSON_THROW_ON_ERROR), "\n";
    return;
}
if (in_array($phase, ['move', 'clear', 'disable', 'enable'], true)) {
    if ($phase === 'move' || $phase === 'clear') {
        $archive = $phase === 'move' ? $page('vp-new-archive') : 0;
        Visual_Portfolio_Settings::update_option('portfolio_archive_page', 'vp_general', $archive === 0 ? '' : (string) $archive);
        Visual_Portfolio_Archive_Mapping::save_archive_page_option($archive);
    } else {
        Visual_Portfolio_Settings::update_option('register_portfolio_post_type', 'vp_general', $phase === 'enable' ? 'on' : 'off');
    }
    echo json_encode(['phase' => $phase, 'general' => get_option('vp_general')], JSON_THROW_ON_ERROR), "\n";
    return;
}
$check($phase === 'observe', 'known observation phase');
$rows = static function (string $table, string $order) use ($wpdb, $check): array {
    $wpdb->last_error = '';
    $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY $order LIMIT 8193", ARRAY_A);
    $check($wpdb->last_error === '' && is_array($rows) && count($rows) <= 8192, 'complete bounded table census');
    $check(strlen(json_encode($rows, JSON_THROW_ON_ERROR)) <= 16777216, 'bounded native table bytes');
    return $rows;
};
$tables = [];
$order = ['posts' => 'ID', 'postmeta' => 'meta_id', 'options' => 'option_id', 'terms' => 'term_id',
    'term_taxonomy' => 'term_taxonomy_id', 'term_relationships' => 'object_id,term_taxonomy_id',
    'termmeta' => 'meta_id', 'users' => 'ID', 'usermeta' => 'umeta_id'];
foreach ($order as $property => $key) $tables[$property] = $rows($wpdb->$property, $key);
$engine = [];
$engineOrder = ['wprism_map' => 'uuid,id_kind', 'wprism_state' => 'uuid', 'wprism_kv' => 'k', 'wprism_journal' => 'id'];
foreach ($engineOrder as $suffix => $key) {
    $engine[$suffix] = $rows($wpdb->prefix . $suffix, $key);
}
$markers = array_values(array_filter($tables['postmeta'], static fn(array $row): bool => $row['meta_key'] === '_vp_post_type_mapped'));
$options = get_option('vp_general');
$archive = (int) ($options['portfolio_archive_page'] ?? 0);
$check($archive === 0 ? $markers === [] : count($markers) === 1 && (int) $markers[0]['post_id'] === $archive && $markers[0]['meta_value'] === 'portfolio',
    'native marker matches the current archive selection');
$roles = get_option($wpdb->prefix . 'user_roles');
$enabled = (bool) Visual_Portfolio_Custom_Post_Type::portfolio_post_type_is_registered();
$check(isset($roles['portfolio_author']) === $enabled, 'native portfolio role availability');
foreach (Visual_Portfolio_Custom_Post_Type::get_portfolio_caps() as $cap) {
    foreach (['administrator', 'editor', 'portfolio_manager'] as $name) {
        $check($enabled ? ($roles[$name]['capabilities'][$cap] ?? null) === true : !array_key_exists($cap, $roles[$name]['capabilities']),
            'native portfolio capability projection');
    }
}
$check(($roles['vp_fixture_other_role'] ?? null) === ['name' => 'Other plugin fixture role', 'capabilities' => ['read' => true, 'other_plugin_cap' => true]],
    'unrelated role survives');
$files = [];
foreach (['.htaccess', 'web.config'] as $path) $files[$path] = WPrism\ProviderSdk::filesystem_file_snapshot(ABSPATH, $path);
foreach ($order as $property => $key) $check($tables[$property] === $rows($wpdb->$property, $key), 'native observation preserves every table');
foreach ($engineOrder as $suffix => $key) $check($engine[$suffix] === $rows($wpdb->prefix . $suffix, $key), 'observation preserves engine rows');
echo json_encode(['format' => 'wprism-visual-portfolio-settings-native/v1', 'wordpress' => get_bloginfo('version'),
    'plugin' => VISUAL_PORTFOLIO_VERSION, 'php' => PHP_VERSION, 'database' => $wpdb->db_server_info(),
    'home' => home_url(), 'tables' => $tables, 'engine' => $engine,
    'files' => $files, 'archive' => $archive, 'enabled' => $enabled,
    'ids' => ['old' => $page('vp-old-archive'), 'new' => $page('vp-new-archive')],
    'uuids' => ['old' => get_post_meta($page('vp-old-archive'), '_wprism_uuid', true),
        'new' => get_post_meta($page('vp-new-archive'), '_wprism_uuid', true)]], JSON_THROW_ON_ERROR), "\n";

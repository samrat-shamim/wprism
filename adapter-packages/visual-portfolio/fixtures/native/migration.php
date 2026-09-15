<?php
declare(strict_types=1);

$check = static function (bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException('Visual Portfolio native migration: ' . $why);
};
$check(current_user_can('manage_options')
    && defined('VISUAL_PORTFOLIO_VERSION')
    && VISUAL_PORTFOLIO_VERSION === '3.8.1'
    && class_exists('Visual_Portfolio_Migrations'), 'exact plugin migration runtime and administrator');

global $wpdb;
foreach ([$wpdb->options, $wpdb->posts, $wpdb->postmeta] as $table) {
    $check(is_string($table) && preg_match('/^[A-Za-z0-9_]+$/D', $table) === 1, 'bounded native table name');
}
$rows = static function (string $sql, string $label) use ($wpdb, $check): array {
    $wpdb->last_error = '';
    $value = $wpdb->get_results($sql, ARRAY_A);
    $check(is_array($value) && count($value) <= 4096 && $wpdb->last_error === '', $label . ' bounded query');
    $bytes = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $check(strlen($bytes) <= 4194304, $label . ' bounded bytes');
    return ['count' => count($value), 'sha256' => hash('sha256', $bytes)];
};
$observe = static function () use ($wpdb, $rows, $check): array {
    $wpdb->last_error = '';
    $cursorRows = $wpdb->get_results($wpdb->prepare(
        "SELECT option_value, autoload FROM `{$wpdb->options}` WHERE option_name = %s LIMIT 2",
        'vpf_db_version'
    ), ARRAY_A);
    $check(is_array($cursorRows) && count($cursorRows) <= 1 && $wpdb->last_error === '', 'exact physical cursor');
    $cursor = $cursorRows[0]['option_value'] ?? null;
    $check($cursor === null || (is_string($cursor) && strlen($cursor) <= 64
        && preg_match('/^[0-9A-Za-z._-]+$/D', $cursor) === 1), 'bounded cursor value');
    $images = get_option('vp_images', null);
    $lazyLoading = is_array($images) && array_key_exists('lazy_loading', $images)
        ? $images['lazy_loading']
        : null;
    $check($lazyLoading === null || is_string($lazyLoading), 'bounded native lazy-loading value type');
    if (is_string($lazyLoading)) $check(strlen($lazyLoading) <= 64, 'bounded native lazy-loading value');
    $names = [
        '_vp_add_archive_page', '_vp_trying_to_add_archive_page',
        'vp_general', 'vp_images', 'vp_popup_gallery', 'vpf_db_version',
    ];
    $quoted = implode(', ', array_map(
        static fn(string $name): string => "'" . esc_sql($name) . "'",
        $names
    ));
    return [
        'cursor' => $cursor,
        'lazy_loading' => $lazyLoading,
        'options' => $rows(
            "SELECT option_name, option_value, autoload FROM `{$wpdb->options}` "
                . "WHERE option_name IN ($quoted) ORDER BY option_name LIMIT 4097",
            'migration options'
        ),
        'posts' => $rows(
            "SELECT * FROM `{$wpdb->posts}` WHERE post_type IN ('page', 'vp_lists') ORDER BY ID LIMIT 4097",
            'migration posts'
        ),
        'postmeta' => $rows(
            "SELECT * FROM `{$wpdb->postmeta}` WHERE meta_key REGEXP '^_?vp_' ORDER BY meta_id LIMIT 4097",
            'migration postmeta'
        ),
    ];
};

$mode = $args[0] ?? 'observe';
if ($mode === 'observe') {
    echo json_encode(['format' => 'wprism-vp-native-migration/v1', 'state' => $observe()], JSON_THROW_ON_ERROR), "\n";
    return;
}
$check($mode === 'settle', 'known fixture mode');
$before = $observe();
$migration = new Visual_Portfolio_Migrations();
$migration->init();
$after = $observe();
$migration->init();
$fixed = $observe();
$check($after['cursor'] === VISUAL_PORTFOLIO_VERSION, 'native procedure advances exact cursor');
$check($after === $fixed, 'native procedure reaches a physical fixed point');
echo json_encode([
    'format' => 'wprism-vp-native-migration/v1',
    'before' => $before,
    'after' => $after,
    'fixed_point' => true,
], JSON_THROW_ON_ERROR), "\n";

<?php
declare(strict_types=1);

$phase = $args[0] ?? '';
$check = static function (bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException('Visual Portfolio native authoring: ' . $why);
};
$check(current_user_can('manage_options'), 'owned administrator');
if ($phase === 'pad-target') {
    $ids = [];
    for ($i = 0; $i < 8; $i++) {
        $id = wp_insert_post(['post_type' => 'post', 'post_status' => 'trash', 'post_title' => 'Local padding ' . $i], true);
        $term = wp_insert_term('Local category ' . $i, 'category');
        $check(is_int($id) && $id > 0 && !is_wp_error($term), 'native target identity padding');
        $ids[] = ['post' => $id, 'term' => $term['term_id']];
    }
    echo json_encode(['padding' => $ids], JSON_THROW_ON_ERROR), "\n";
    return;
}
$check(defined('VISUAL_PORTFOLIO_VERSION') && VISUAL_PORTFOLIO_VERSION === '3.8.1', 'exact free artifact');
global $wpdb;
$rows = static function (string $table, string $order) use ($wpdb, $check): array {
    $wpdb->last_error = '';
    $data = $wpdb->get_results("SELECT * FROM $table ORDER BY $order LIMIT 4097", ARRAY_A);
    $check(is_array($data) && count($data) <= 4096 && $wpdb->last_error === '', 'bounded complete native table');
    $check(strlen(json_encode($data, JSON_THROW_ON_ERROR)) <= 8388608, 'native table byte bound');
    return $data;
};
if ($phase === 'seed-source') {
    $check(post_type_exists('portfolio') && taxonomy_exists('portfolio_category'), 'native portfolio registration');
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $seed = ['images' => [], 'projects' => [], 'pages' => [], 'terms' => []];
    foreach (['harbor', 'garden'] as $name) {
        $temp = wp_tempnam($name . '.png');
        $check(is_string($temp) && copy(__DIR__ . '/' . $name . '.png', $temp), 'native upload temporary file');
        $id = media_handle_sideload(['name' => $name . '.png', 'tmp_name' => $temp], 0, ucfirst($name));
        $check(is_int($id) && $id > 0, 'native WordPress upload');
        $seed['images'][$name] = $id;
    }
    foreach (['Field notes', 'Studio work'] as $name) {
        $term = wp_insert_term($name, 'portfolio_category');
        $check(!is_wp_error($term), 'native project category');
        $seed['terms'][$name] = $term['term_id'];
    }
    foreach (['Harbor Light', 'Paper Garden', 'Quiet Shapes', 'Open Horizon'] as $i => $title) {
        $id = wp_insert_post(['post_type' => 'portfolio', 'post_status' => 'publish', 'post_title' => $title,
            'post_content' => '<p>' . implode(' ', array_fill(0, 265 * ($i + 1), 'shape')) . '</p>'], true);
        $check(is_int($id) && $id > 0, 'native project Save');
        $check(set_post_thumbnail($id, array_values($seed['images'])[$i % 2]) !== false, 'native featured image');
        $check(!is_wp_error(wp_set_object_terms($id, [array_values($seed['terms'])[$i % 2]], 'portfolio_category')), 'native category assignment');
        $seed['projects'][$title] = $id;
    }
    foreach (['Visual Portfolio Gallery' => 'vp-author-gallery', 'Alternate Portfolio Archive' => 'vp-alternate-archive'] as $title => $slug) {
        $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => $slug], true);
        $check(is_int($id) && $id > 0, 'native editor page');
        $seed['pages'][$slug] = $id;
    }
    echo json_encode($seed, JSON_THROW_ON_ERROR), "\n";
    return;
}
$check($phase === 'observe', 'known read-only phase');
$data = ['format' => 'wprism-visual-portfolio-native-observation/v1', 'wordpress' => get_bloginfo('version'),
    'plugin' => VISUAL_PORTFOLIO_VERSION, 'php' => PHP_VERSION, 'home' => home_url(),
    'theme' => ['name' => get_stylesheet(), 'version' => wp_get_theme()->get('Version')], 'tables' => []];
foreach (['posts' => 'ID', 'postmeta' => 'meta_id', 'terms' => 'term_id', 'termmeta' => 'meta_id',
    'term_taxonomy' => 'term_taxonomy_id', 'term_relationships' => 'object_id,term_taxonomy_id', 'options' => 'option_id'] as $kind => $order) {
    $data['tables'][$kind] = $rows($wpdb->$kind, $order);
}
$data['blocks'] = [];
foreach (WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $block) {
    if (str_starts_with($name, 'visual-portfolio/')) {
        $data['blocks'][$name] = ['attributes' => $block->attributes, 'supports' => $block->supports];
    }
}
ksort($data['blocks'], SORT_STRING);
$data['uploads'] = [];
$uploads = wp_upload_dir(null, false);
$check(empty($uploads['error']), 'native upload directory resolution');
if (is_dir($uploads['basedir'])) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads['basedir'], FilesystemIterator::SKIP_DOTS)) as $file) {
        $check($file->isFile() && !$file->isLink() && count($data['uploads']) < 4096, 'ordinary bounded upload census');
        $data['uploads'][substr($file->getPathname(), strlen($uploads['basedir']) + 1)] = [
            'bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getPathname())];
    }
}
ksort($data['uploads'], SORT_STRING);
foreach (['posts' => 'ID', 'postmeta' => 'meta_id', 'terms' => 'term_id', 'termmeta' => 'meta_id',
    'term_taxonomy' => 'term_taxonomy_id', 'term_relationships' => 'object_id,term_taxonomy_id', 'options' => 'option_id'] as $kind => $order) {
    $check($data['tables'][$kind] === $rows($wpdb->$kind, $order), 'observer preserves its complete native table census');
}
echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";

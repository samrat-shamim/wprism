<?php
declare(strict_types=1);

$phase = $args[0] ?? '';
$check = static function (bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException('Qi native Apply evidence: ' . $why);
};
$check(defined('QI_BLOCKS_VERSION') && QI_BLOCKS_VERSION === '1.5.2' && current_user_can('manage_options'), 'exact plugin and administrator');
if ($phase === 'setup-source') {
    $check(!file_exists('/siterepo/site.wprism.json'), 'fresh content-only repository');
    WPrism\Canon::write_file('/siterepo/site.wprism.json', WPrism\Canon::encode([
        'manifests' => ['core', 'qi-blocks'], 'spec_version' => 3,
        'policy' => ['post_types' => ['post', 'page', 'attachment'], 'taxonomies' => ['category', 'post_tag'],
            'options' => (object) [], 'post_meta' => (object) [], 'term_meta' => (object) []],
    ]));
    echo wp_json_encode(['phase' => $phase, 'home' => home_url(), 'code' => null]);
    return;
}
if ($phase === 'setup-target') {
    $check(!file_exists('/siterepo/site.wprism.json'), 'target has never had a code descriptor');
    $padding = [];
    for ($i = 0; $i < 8; $i++) {
        $id = wp_insert_post(['post_type' => 'post', 'post_status' => 'trash', 'post_title' => 'Unmanaged Qi target ' . $i,
            'post_content' => 'Retained target trash ' . $i], true);
        $check(is_int($id) && $id > 0, 'independent target padding');
        $padding[] = $id;
    }
    update_option('qi_blocks_custom_templates_flag', 'target-local-qi-probe');
    echo wp_json_encode(['phase' => $phase, 'home' => home_url(), 'padding' => $padding]);
    return;
}
$check($phase === 'observe', 'declared evidence phase');
global $wpdb;
$posts = $wpdb->get_results("SELECT * FROM {$wpdb->posts} ORDER BY ID", ARRAY_A);
$check(is_array($posts) && $wpdb->last_error === '', 'complete native post census');
$options = $wpdb->get_results("SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name LIKE 'qi\\_blocks\\_%' ORDER BY option_name", ARRAY_A);
$check(is_array($options) && $wpdb->last_error === '', 'complete native Qi option census');
$ids = []; $roles = [];
foreach (['image' => ['attachment', 'qi-conformance-image'], 'first' => ['post', 'qi-first-source-post'],
    'second' => ['post', 'qi-second-source-post'], 'page' => ['page', 'qi-native-corpus']] as $role => [$type, $slug]) {
    $matches = array_values(array_filter($posts, static fn(array $p): bool => $p['post_type'] === $type
        && $p['post_name'] === $slug && $p['post_status'] !== 'trash'));
    $check(count($matches) <= 1, 'unique source-managed role ' . $role);
    if ($matches !== []) {
        $roles[$role] = $matches[0];
        $ids[$role] = (int) $matches[0]['ID'];
    }
}
$uuids = []; $featured = [];
foreach ($ids as $role => $id) $uuids[$role] = get_post_meta($id, '_wprism_uuid', true);
foreach (['first', 'second'] as $role) if (isset($ids[$role])) $featured[$role] = get_post_thumbnail_id($ids[$role]);
$attachment = isset($ids['image']) ? ['id' => $ids['image'], 'url' => wp_get_attachment_url($ids['image']),
    'file' => get_post_meta($ids['image'], '_wp_attached_file', true), 'metadata' => wp_get_attachment_metadata($ids['image'])] : null;
$upload = wp_upload_dir();
$check(empty($upload['error']), 'native upload root');
$files = [];
if (is_dir($upload['basedir'])) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($upload['basedir'], FilesystemIterator::SKIP_DOTS)) as $f) {
        $check(!$f->isLink() && $f->isFile(), 'ordinary upload file');
        $path = $f->getPathname();
        $dimensions = getimagesize($path);
        $check(is_array($dimensions), 'every fixture upload is a native image');
        $files[substr($path, strlen($upload['basedir']) + 1)] = ['sha256' => hash_file('sha256', $path), 'bytes' => filesize($path),
            'width' => $dimensions[0], 'height' => $dimensions[1], 'mime' => $dimensions['mime']];
    }
}
ksort($files, SORT_STRING);
echo wp_json_encode(['format' => 'wprism-qi-native-apply/v1', 'home' => home_url(), 'posts' => $posts, 'options' => $options,
    'ids' => $ids, 'uuids' => $uuids, 'featured' => $featured, 'attachment' => $attachment,
    'page_body' => $roles['page']['post_content'] ?? null,
    'styles' => serialize(get_option('qi_blocks_global_styles')), 'uploads' => $files], JSON_THROW_ON_ERROR);

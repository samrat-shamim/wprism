<?php
declare(strict_types=1);

// Executed only by the disposable conformance source through wp eval-file.
wp_set_current_user(1);
$mode = $args[0] ?? '';
$post = get_page_by_path('map-boundary-fixture', OBJECT, 'page');
if ($mode === 'seed') {
    update_option('gmw-map-block-key', 'map-fixture-source-key');
    $id = wp_insert_post([
        'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Map boundary fixture',
        'post_name' => 'map-boundary-fixture',
        'post_content' => '<!-- wp:paragraph --><p>Ordinary source content.</p><!-- /wp:paragraph -->',
    ], true);
    if (is_wp_error($id) || !$id) throw new RuntimeException('map fixture page creation failed');
    $post = get_post($id);
    file_put_contents('/siterepo/.tmp-map-original.json', wp_json_encode((array) $post));
} elseif (in_array($mode, ['default', 'explicit', 'nested'], true)) {
    if (!$post) throw new RuntimeException('map fixture page missing');
    $body = file_get_contents('/siterepo/.tmp-map-saved.html');
    if ($mode === 'explicit') $body = str_replace('"zoom":12', '"api_key":"map-fixture-source-key","zoom":12', $body);
    if ($mode === 'nested') $body = '<!-- wp:group --><div class="wp-block-group">' . $body . '</div><!-- /wp:group -->';
    $result = wp_update_post(wp_slash(['ID' => $post->ID, 'post_content' => $body]), true);
    if (is_wp_error($result)) throw new RuntimeException('map fixture content save failed');
    if (get_post($post->ID)->post_content !== $body) throw new RuntimeException('map fixture saved bytes disagree');
} elseif ($mode === 'restore') {
    $original = json_decode((string) file_get_contents('/siterepo/.tmp-map-original.json'), true, 512, JSON_THROW_ON_ERROR);
    // This fixture must recover exact source timestamps as well as content.
    // A second wp_update_post() would replace modified stamps and weaken the
    // whole-tree recapture comparison; restore this one test-owned row exactly.
    global $wpdb;
    $restore = array_intersect_key($original, array_flip(['post_content', 'post_modified', 'post_modified_gmt']));
    if ($wpdb->update($wpdb->posts, $restore, ['ID' => (int) $original['ID']]) === false) throw new RuntimeException('map fixture restore failed');
    clean_post_cache((int) $original['ID']);
    $restored = get_post((int) $original['ID']);
    foreach ($restore as $field => $value) {
        if ($restored->$field !== $value) throw new RuntimeException('map fixture restore readback failed');
    }
} elseif ($mode !== 'observe') {
    throw new RuntimeException('unknown map fixture mode');
}
if (!class_exists('wf_map_block')) throw new RuntimeException('native map plugin missing');
wf_map_block::enqueue_block_editor_assets();
$localized = wp_scripts()->get_data('wf-map-block', 'data');
echo wp_json_encode([
    'mode' => $mode,
    'post' => (int) ($post->ID ?? 0),
    'version' => wf_map_block::get_plugin_version(),
    'option_matches' => get_option('gmw-map-block-key') === 'map-fixture-source-key',
    'editor_uses_local_key' => is_string($localized) && str_contains($localized, 'map-fixture-source-key'),
    'body_has_map' => $post && str_contains(get_post($post->ID)->post_content, '<!-- wp:webfactory/map '),
]);

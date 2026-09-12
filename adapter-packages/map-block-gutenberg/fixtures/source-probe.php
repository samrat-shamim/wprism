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
        'post_content' => (string) file_get_contents('/siterepo/.tmp-map-saved.html'),
    ], true);
    if (is_wp_error($id) || !$id) throw new RuntimeException('map fixture page creation failed');
    $post = get_post($id);
    file_put_contents('/siterepo/.tmp-map-original.json', wp_json_encode((array) $post));
    $explicit = str_replace('map-fixture-source-key', 'historical-fixture-key', (string) file_get_contents('/siterepo/.tmp-map-saved.html'));
    $explicit = str_replace('"zoom":12', '"api_key":"historical-fixture-key","zoom":12', $explicit);
    $created = wp_insert_post(wp_slash(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Map created fixture',
        'post_name' => 'map-created-fixture', 'post_content' => $explicit]), true);
    if (is_wp_error($created) || !$created) throw new RuntimeException('explicit-key source map creation failed');
} elseif ($mode === 'target-seed') {
    if ($post) throw new RuntimeException('target fixture unexpectedly exists');
    update_option('_transient_wprism_map_fixture', 'target-only-runtime');
    update_option('gmw-map-block-key', 'preexisting-target-key', false);
    $id = wp_insert_post(['import_id' => 7001, 'post_type' => 'page', 'post_status' => 'publish',
        'post_title' => 'Unmanaged target map', 'post_name' => 'map-boundary-fixture',
        'post_content' => '<!-- wp:paragraph --><p>Unmanaged target content.</p><!-- /wp:paragraph -->'], true);
    if (is_wp_error($id) || $id !== 7001) throw new RuntimeException('target fixture distinct identity failed');
    $post = get_post($id);
} elseif ($mode === 'target-drift') {
    update_option('gmw-map-block-key', 'drifted-target-key');
} elseif (in_array($mode, ['canonical-update', 'canonical-restore'], true)) {
    $paths = glob('/siterepo/state/posts/page/*--map-boundary-fixture.md');
    if (count($paths) !== 1) throw new RuntimeException('canonical map fixture identity is not unique');
    if ($mode === 'canonical-update') {
        $bytes = (string) file_get_contents($paths[0]);
        if (file_exists('/siterepo/.tmp-map-before-update.md')) throw new RuntimeException('canonical fixture backup already exists');
        file_put_contents('/siterepo/.tmp-map-before-update.md', $bytes);
        $next = str_replace(['"height":420', 'height="420px"'], ['"height":430', 'height="430px"'], $bytes, $count);
        if ($count !== 2) throw new RuntimeException('canonical fixture update did not bind both representations');
        file_put_contents($paths[0], $next);
    } else {
        $bytes = file_get_contents('/siterepo/.tmp-map-before-update.md');
        if (!is_string($bytes) || $bytes === '') throw new RuntimeException('canonical fixture backup missing');
        file_put_contents($paths[0], $bytes);
    }
} elseif (in_array($mode, ['default', 'explicit', 'nested', 'malformed'], true)) {
    if (!$post) throw new RuntimeException('map fixture page missing');
    $body = file_get_contents('/siterepo/.tmp-map-saved.html');
    if ($mode === 'explicit') $body = str_replace('"zoom":12', '"api_key":"map-fixture-source-key","zoom":12', $body);
    if ($mode === 'nested') $body = '<!-- wp:group --><div class="wp-block-group">' . $body . '</div><!-- /wp:group -->';
    if ($mode === 'malformed') $body = str_replace('"zoom":12', '"unknown":"private-source-value","zoom":12', $body);
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
} elseif (!in_array($mode, ['observe', 'target'], true)) {
    throw new RuntimeException('unknown map fixture mode');
}
if (!class_exists('wf_map_block')) throw new RuntimeException('native map plugin missing');
wf_map_block::enqueue_block_editor_assets();
$localized = wp_scripts()->get_data('wf-map-block', 'data');
$expectedKey = str_starts_with($mode, 'target') ? 'map-fixture-target-key' : 'map-fixture-source-key';
$created = get_page_by_path('map-created-fixture', OBJECT, 'page');
echo wp_json_encode([
    'mode' => $mode,
    'post' => (int) ($post->ID ?? 0),
    'version' => wf_map_block::get_plugin_version(),
    'option_matches' => get_option('gmw-map-block-key') === $expectedKey,
    'editor_uses_local_key' => is_string($localized) && str_contains($localized, $expectedKey),
    'body_has_map' => $post && str_contains(get_post($post->ID)->post_content, '<!-- wp:webfactory/map '),
    'target_key_locations' => $post ? substr_count($post->post_content, $expectedKey) : 0,
    'source_key_absent' => $post && !str_contains($post->post_content, 'map-fixture-source-key'),
    'native_post_hash' => $post ? hash('sha256', wp_json_encode((array) $post)) : null,
    'runtime_preserved' => get_option('_transient_wprism_map_fixture') === 'target-only-runtime',
    'created' => (int) ($created->ID ?? 0),
    'created_target_bound' => $created && substr_count($created->post_content, 'map-fixture-target-key') === 2
        && !str_contains($created->post_content, 'historical-fixture-key'),
    'updated_height' => $post && str_contains($post->post_content, 'height="430px"'),
]);

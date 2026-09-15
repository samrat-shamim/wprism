<?php
declare(strict_types=1);

require_once __DIR__ . '/roundtrip-evidence.php';
$check = static function (bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException('Visual Portfolio native roundtrip: ' . $why);
};
$check(current_user_can('manage_options') && defined('VISUAL_PORTFOLIO_VERSION')
    && VISUAL_PORTFOLIO_VERSION === '3.8.1', 'exact plugin and native administrator');
$phase = $args[0] ?? 'observe';
if ($phase === 'pad-target') {
    for ($i = 0; $i < 32; $i++) {
        $post = wp_insert_post(['post_type' => 'post', 'post_status' => 'trash', 'post_title' => 'Target identity padding ' . $i], true);
        $term = wp_insert_term('Target identity padding ' . $i, 'category');
        $check(is_int($post) && $post > 0 && !is_wp_error($term), 'native target identity padding');
        $check(wp_delete_term($term['term_id'], 'category') === true, 'term padding leaves no authored target-only category');
    }
    echo json_encode(['padding' => 32], JSON_THROW_ON_ERROR), "\n";
    return;
}
if ($phase === 'editor-roundtrip') {
    $roster = static function (array $blocks) use (&$roster): array {
        $names = [];
        foreach ($blocks as $block) {
            if (is_string($block['blockName'] ?? null)) $names[] = $block['blockName'];
            $names = array_merge($names, $roster($block['innerBlocks'] ?? []));
        }
        return $names;
    };
    $result = [
        'format' => 'wprism-vp-native-editor-roundtrip/v1',
        'cursor' => get_option('vpf_db_version'),
        'lazy_loading' => Visual_Portfolio_Settings::get_option('lazy_loading', 'vp_images'),
        'pages' => [],
    ];
    $expected = [
        'vp-author-gallery' => [
            'visual-portfolio/loop', 'visual-portfolio/loop-filter',
            'visual-portfolio/loop-filter-item', 'visual-portfolio/loop-filter-item',
            'visual-portfolio/item-template', 'visual-portfolio/item-image',
            'visual-portfolio/item-title', 'visual-portfolio/item-categories',
            'visual-portfolio/loop-pagination', 'visual-portfolio/loop-pagination-previous',
            'visual-portfolio/loop-pagination-numbers', 'visual-portfolio/loop-pagination-next',
        ],
        'vp-alternate-archive' => ['visual-portfolio/block'],
    ];
    foreach ($expected as $slug => $expectedRoster) {
        $page = get_page_by_path($slug, OBJECT, 'page');
        $check($page instanceof WP_Post && $page->post_status === 'publish', 'native editor page ' . $slug);
        $body = get_post_field('post_content', $page->ID, 'raw');
        $modified = ['post_modified' => $page->post_modified, 'post_modified_gmt' => $page->post_modified_gmt];
        $check(is_string($body) && $roster(parse_blocks($body)) === $expectedRoster,
            'complete native editor block roster ' . $slug);
        $request = new WP_REST_Request('POST', '/wp/v2/pages/' . $page->ID);
        $request->set_param('content', $body);
        $response = rest_do_request($request);
        $reopened = get_post_field('post_content', $page->ID, 'raw');
        $check($response->get_status() === 200 && $reopened === $body
            && $roster(parse_blocks($reopened)) === $expectedRoster,
            'native Save and reopen retain exact body and block roster ' . $slug);
        // The no-op REST Save advances both modified columns. Restore only
        // that test-owned witness; the harness's full recapture rejects every
        // other authored change after this fixture returns.
        global $wpdb;
        $wpdb->last_error = '';
        $restored = $wpdb->update($wpdb->posts, $modified, ['ID' => $page->ID], ['%s', '%s'], ['%d']);
        clean_post_cache($page->ID);
        $restoredPage = get_post($page->ID);
        $check($restored !== false && $wpdb->last_error === '' && $restoredPage instanceof WP_Post
            && $restoredPage->post_modified === $modified['post_modified']
            && $restoredPage->post_modified_gmt === $modified['post_modified_gmt']
            && get_post_field('post_content', $page->ID, 'raw') === $body,
            'bounded editor timestamp witness cleanup ' . $slug);
        $result['pages'][$slug] = ['blocks' => count($expectedRoster), 'sha256' => hash('sha256', $reopened)];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
    return;
}
$check($phase === 'observe', 'known native phase');
$record = ['format' => 'wprism-vp-native-roundtrip/v1', 'plugin' => VISUAL_PORTFOLIO_VERSION, 'home' => home_url()];
foreach (VisualPortfolioRoundtripEvidence::POSTS as $i => $slug) {
    $type = $i < 2 ? 'attachment' : ($i < 6 ? 'portfolio' : 'page');
    $post = get_page_by_path($slug, OBJECT, $type);
    $check($post instanceof WP_Post && $post->post_type === $type && $post->post_name === $slug
        && $post->post_status === ($type === 'attachment' ? 'inherit' : 'publish'), 'physical post role ' . $slug);
    $record['posts'][$slug] = ['id' => $post->ID, 'uuid' => WPrism\Ledger::uuid_for($post->ID, 'post')];
}
foreach (VisualPortfolioRoundtripEvidence::TERMS as $name) {
    $term = get_term_by('name', $name, 'portfolio_category');
    $check($term instanceof WP_Term && $term->taxonomy === 'portfolio_category' && $term->name === $name, 'physical category role');
    $record['terms'][$name] = ['id' => $term->term_id, 'uuid' => WPrism\Ledger::uuid_for($term->term_id, 'term')];
}
$settings = get_option('vp_general');
$check(is_array($settings) && isset($settings['portfolio_archive_page'], $settings['no_image']), 'native general settings');
foreach (['archive_page' => 'portfolio_archive_page', 'placeholder' => 'no_image'] as $field => $key) {
    $check((is_int($settings[$key]) || is_string($settings[$key]))
        && preg_match('/^[1-9][0-9]*$/D', (string) $settings[$key]) === 1, 'positive native settings reference');
    $record[$field] = (int) $settings[$key];
}
foreach (['harbor', 'garden'] as $name) {
    $id = $record['posts'][$name]['id'];
    $path = get_attached_file($id);
    $check(is_string($path) && is_file($path) && !is_link($path), 'physical original image');
    $bytes = filesize($path); $hash = hash_file('sha256', $path);
    $check(is_int($bytes) && $bytes > 0 && is_string($hash), 'readable original image');
    $check($hash === hash_file('sha256', __DIR__ . '/' . $name . '.png'), 'admitted fixture image bytes');
    $record['media'][$name] = ['id' => $id, 'bytes' => $bytes, 'sha256' => $hash, 'url' => wp_get_attachment_url($id)];
}
foreach (['harbor-light', 'paper-garden', 'quiet-shapes', 'open-horizon'] as $name) {
    $record['featured'][$name] = get_post_thumbnail_id($record['posts'][$name]['id']);
}
$blocks = parse_blocks(get_post_field('post_content', $record['posts']['vp-author-gallery']['id'], 'raw'));
$check(count($blocks) >= 1 && $blocks[0]['blockName'] === 'visual-portfolio/loop', 'native gallery root');
$images = $blocks[0]['attrs']['imagesQuery']['images'] ?? null;
$check(is_array($images) && array_is_list($images) && count($images) === 2, 'two ordered native gallery images');
$record['gallery_ids'] = [];
foreach ($images as $image) {
    $check(is_array($image) && is_int($image['id'] ?? null), 'native integer gallery identity');
    $record['gallery_ids'][] = $image['id'];
}
echo json_encode($record, JSON_THROW_ON_ERROR), "\n";

<?php
declare(strict_types=1);
$check = static function (bool $ok, string $why): void { if (!$ok) throw new RuntimeException('VP capture fixture: ' . $why); };
$check(current_user_can('manage_options') && defined('VISUAL_PORTFOLIO_VERSION') && VISUAL_PORTFOLIO_VERSION === '3.8.1', 'native subject and administrator');
$seed = json_decode(file_get_contents(__DIR__ . '/seed.json'), true, 512, JSON_THROW_ON_ERROR);
$fixture = __DIR__;
$images = [3 => $seed['images']['harbor'], 4 => $seed['images']['garden']];
$check($images[3] !== 3 && $images[4] !== 4, 'source fixture media IDs actually diverge from retained UI Save');
$home = home_url();
$replace = static function (mixed $value) use (&$replace, $home): mixed {
    if (is_string($value)) return str_replace('http://localhost:9186', $home, $value);
    if (is_array($value)) foreach ($value as &$child) $child = $replace($child);
    return $value;
};
$walk = static function (array $blocks) use (&$walk, $replace, $images, $check): array {
    foreach ($blocks as &$block) {
        $block['attrs'] = $replace($block['attrs']);
        if ($block['blockName'] === 'visual-portfolio/loop') {
            foreach ($block['attrs']['imagesQuery']['images'] as &$image) {
                $check(isset($images[$image['id']]), 'known native image identity');
                $image['id'] = $images[$image['id']];
                $image['imgUrl'] = wp_get_attachment_url($image['id']);
                $image['imgThumbnailUrl'] = wp_get_attachment_image_url($image['id'], 'thumbnail');
                $check(is_string($image['imgUrl']) && is_string($image['imgThumbnailUrl']), 'complete native media readback');
            }
            unset($image);
        }
        if ($block['blockName'] === 'visual-portfolio/block') {
            $block['attrs']['custom_css'] = str_replace(rawurlencode('http://localhost:9186/wp-content/uploads/2026/09/harbor.png'),
                rawurlencode(wp_get_attachment_url($images[3])), $block['attrs']['custom_css']);
        }
        $block['innerBlocks'] = $walk($block['innerBlocks']);
    }
    return $blocks;
};
$bodies = [];
foreach (['gallery' => 'vp-author-gallery', 'archive' => 'vp-alternate-archive'] as $fixtureName => $slug) {
    $id = $seed['pages'][$slug];
    $body = serialize_blocks($walk(parse_blocks(file_get_contents($fixture . '/' . $fixtureName . '.html'))));
    $request = new WP_REST_Request('POST', '/wp/v2/pages/' . $id);
    $request->set_param('content', $body);
    $response = rest_do_request($request);
    $check($response->get_status() === 200 && get_post_field('post_content', $id, 'raw') === $body, 'native REST Save retains complete fixture body');
    $bodies[$slug] = ['id' => $id, 'body' => $body];
}
$rows = json_decode(file_get_contents($fixture . '/options.json'), true, 512, JSON_THROW_ON_ERROR);
$saved = [];
foreach ($rows as $row) {
    $name = $row['option_name'];
    if (!in_array($name, ['vp_general', 'vp_images', 'vp_popup_gallery'], true)) continue;
    $value = $replace(unserialize($row['option_value'], ['allowed_classes' => false]));
    if ($name === 'vp_general') {
        $value['portfolio_archive_page'] = (string) $seed['pages']['vp-alternate-archive'];
        $value['no_image'] = (string) $seed['images']['harbor'];
        // This is the native source fixture writer, not a product Apply hook.
        Visual_Portfolio_Archive_Mapping::save_archive_page_option($value['portfolio_archive_page']);
    }
    update_option($name, $value);
    $check(get_option($name) === $value, 'native complete settings readback');
    $saved[$name] = $value;
}
echo json_encode(['format' => 'wprism-vp-native-capture-source/v1', 'qualification' => false, 'source_fixture' => $seed, 'bodies' => $bodies, 'settings' => $saved], JSON_THROW_ON_ERROR), "\n";

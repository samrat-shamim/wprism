<?php
declare(strict_types=1);

require_once __DIR__ . '/corpus.php';
$check = static function (bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException('Qi native media evidence: ' . $why);
};
$check(defined('QI_BLOCKS_VERSION') && QI_BLOCKS_VERSION === '1.5.2' && current_user_can('manage_options'), 'locked plugin and administrator');
$phase = $args[0] ?? '';
$pages = get_posts(['post_type' => 'page', 'name' => 'qi-native-corpus', 'post_status' => 'publish']);
$images = get_posts(['post_type' => 'attachment', 'name' => 'qi-conformance-image', 'post_status' => 'inherit']);
$check(count($pages) === 1 && count($images) === 1, 'unique native page and attachment');
$page = $pages[0]->ID; $attachment = $images[0]->ID;
$imageUrl = wp_get_attachment_url($attachment);
$check(is_string($imageUrl) && str_ends_with($imageUrl, '.png'), 'native PNG original');
if ($phase === 'seed') {
    $receipt = json_decode(file_get_contents(dirname(__DIR__) . '/receipt.json'), true, 32, JSON_THROW_ON_ERROR);
    $check($receipt['ids']['image'] === $attachment && $receipt['ids']['page'] === $page, 'source receipt identities');
    foreach (QiNativeMediaCorpus::DIMENSIONS as [$width, $height]) {
        $crop = qi_blocks_resize_image($attachment, ['width' => $width, 'height' => $height], true);
        $check(is_array($crop), 'native Qi crop writer');
    }
    $saved = QiNativeMediaCorpus::saved(file_get_contents(dirname(__DIR__) . '/native-blocks.html'), file_get_contents(__DIR__ . '/blocks.html'));
    $body = QiConformanceCorpus::body($saved, $receipt['ids'], home_url(), $imageUrl);
    $request = new WP_REST_Request('POST', '/wp/v2/pages/' . $page);
    $request->set_header('Content-Type', 'application/json');
    $request->set_body(wp_json_encode(['content' => $body]));
    $response = rest_do_request($request);
    $check(!is_wp_error($response) && $response->get_status() === 200 && get_post_field('post_content', $page, 'raw') === $body, 'native content Save and exact readback');
    echo wp_json_encode(['format' => 'wprism-qi-native-media-seed/v1', 'page' => $page, 'attachment' => $attachment, 'body_sha256' => hash('sha256', $body)]);
    return;
}
$check($phase === 'pixels', 'declared native media phase');
$html = get_post_field('post_content', $page, 'raw');
$original = get_attached_file($attachment);
$originalSamples = QiConformanceCorpus::image_samples(file_get_contents($original));
$rows = [];
foreach (QiNativeMediaCorpus::DIMENSIONS as [$width, $height]) {
    $url = substr($imageUrl, 0, -4) . '-' . $width . 'x' . $height . '.png';
    $check(str_contains($html, 'src="' . esc_url($url) . '"'), 'native saved HTML selects the crop ' . $width . 'x' . $height);
    $path = substr($original, 0, -4) . '-' . $width . 'x' . $height . '.png';
    $check(is_file($path), 'selected native crop exists: ' . $width . 'x' . $height);
    $bytes = file_get_contents($path); $dimensions = getimagesizefromstring($bytes);
    $check(is_array($dimensions) && [$dimensions[0], $dimensions[1], $dimensions['mime']] === [$width, $height, 'image/png'], 'decoded native crop dimensions');
    $image = imagecreatefromstring($bytes);
    $check($image !== false, 'decoded native crop pixels');
    $hash = hash_init('sha256');
    for ($y = 0; $y < $height; $y++) {
        $row = '';
        for ($x = 0; $x < $width; $x++) {
            $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
            $row .= pack('CCCC', $color['red'], $color['green'], $color['blue'], $color['alpha']);
        }
        hash_update($hash, $row);
    }
    imagedestroy($image);
    $rows[] = ['width' => $width, 'height' => $height, 'url' => $url,
        'bytes_sha256' => hash('sha256', $bytes), 'pixels_sha256' => hash_final($hash)];
}
echo wp_json_encode(['format' => 'wprism-qi-native-media-pixels/v1', 'home' => home_url(), 'page' => $page, 'attachment' => $attachment,
    'original_samples' => $originalSamples, 'images' => $rows]);

<?php
declare(strict_types=1);

require_once __DIR__ . '/corpus.php';

$phase = $args[0] ?? '';
$receiptPath = __DIR__ . '/receipt.json';
$saved = file_get_contents(__DIR__ . '/native-blocks.html');
$stylesFixture = file_get_contents(__DIR__ . '/native-options.json');
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$assert(defined('QI_BLOCKS_VERSION') && QI_BLOCKS_VERSION === '1.5.2', 'Conformance requires the pinned Qi 1.5.2 native code');
$assert(get_current_user_id() > 0 && current_user_can('publish_pages'), 'Native authoring requires the fixture administrator');
$rest = static function (string $path, array $data) use ($assert): array {
    $request = new WP_REST_Request('POST', $path);
    $request->set_header('Content-Type', 'application/json');
    $request->set_body(wp_json_encode($data));
    $response = rest_do_request($request);
    $assert(!is_wp_error($response) && $response->get_status() >= 200 && $response->get_status() < 300,
        'Native REST writer refused ' . $path . ': ' . wp_json_encode(is_wp_error($response) ? $response->get_error_message() : $response->get_data()));
    return $response->get_data();
};
if ($phase === 'seed') {
    $assert(!file_exists($receiptPath), 'Native source receipt already exists; use a clean conformance pair');
    $png = QiConformanceCorpus::image_png();
    QiConformanceCorpus::image_samples($png);
    $upload = wp_upload_bits('tmp-qi-image.png', null, $png);
    $assert(empty($upload['error']), 'Native upload refused its image');
    $attachment = wp_insert_attachment(['post_title' => 'Qi conformance image', 'post_status' => 'inherit', 'post_mime_type' => 'image/png'], $upload['file'], 0, true);
    $assert(!is_wp_error($attachment), 'Native attachment insertion failed');
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $metadata = wp_generate_attachment_metadata($attachment, $upload['file']);
    $assert(is_array($metadata) && ($metadata['width'] ?? 0) === 1200 && ($metadata['height'] ?? 0) === 800, 'Native image metadata is incomplete');
    wp_update_attachment_metadata($attachment, $metadata);
    $crop = qi_blocks_resize_image((int) $attachment, ['width' => 333, 'height' => 211], true);
    $cropFile = dirname($upload['file']) . '/' . pathinfo($upload['file'], PATHINFO_FILENAME) . '-333x211.png';
    $assert(is_array($crop) && is_file($cropFile) && array_slice(getimagesize($cropFile), 0, 2) === [333, 211], 'Native Qi crop helper did not produce the saved custom image');
    $first = $rest('/wp/v2/posts', ['title' => 'Qi first source post', 'status' => 'publish', 'featured_media' => (int) $attachment, 'content' => '<p>Qi first native query result.</p>']);
    $second = $rest('/wp/v2/posts', ['title' => 'Qi second source post', 'status' => 'publish', 'featured_media' => (int) $attachment, 'content' => '<p>Qi second native query result.</p>']);
    // WordPress 7.1 create_item() reads absent id/post_parent properties for a
    // draft with an explicit slug (posts controller:769/772). Assign the slug
    // through the native publish request; diagnostics remain fatal throughout.
    $page = $rest('/wp/v2/pages', ['title' => 'Qi native corpus', 'status' => 'draft']);
    $ids = ['image' => (int) $attachment, 'first' => (int) $first['id'], 'second' => (int) $second['id'], 'page' => (int) $page['id']];
    $assert($ids['image'] !== 8 && $ids['page'] !== 13, 'Source identities must diverge from the retained fixture');
    $home = home_url(); $imageUrl = wp_get_attachment_url($attachment);
    $body = QiConformanceCorpus::body($saved, $ids, $home, $imageUrl);
    $names = QiConformanceCorpus::block_names($body);
    $assert(count($names) === 50 && count(array_unique($names)) === 47, 'Standalone native corpus must retain all 47 independent block types and 50 instances');
    $rest('/wp/v2/pages/' . $ids['page'], ['status' => 'publish', 'slug' => 'qi-native-corpus', 'content' => $body]);
    $assert(get_post_field('post_content', $ids['page'], 'raw') === $body, 'Native REST save changed the retained block corpus');
    $receipt = ['format' => 'wprism-qi-native-source/v1', 'ids' => $ids, 'home' => $home, 'image_url' => $imageUrl,
        'body_sha256' => hash('sha256', $body), 'block_types' => 47, 'block_instances' => 50];
    $assert(file_put_contents($receiptPath, wp_json_encode($receipt)) !== false, 'Cannot retain native source receipt');
    echo wp_json_encode($receipt), "\n";
    return;
}
$assert(is_file($receiptPath), 'Native authoring receipt is absent');
$receipt = json_decode(file_get_contents($receiptPath), true, 512, JSON_THROW_ON_ERROR);
$ids = $receipt['ids']; $home = $receipt['home']; $imageUrl = $receipt['image_url'];
$body = QiConformanceCorpus::body($saved, $ids, $home, $imageUrl);
$expectedStyles = QiConformanceCorpus::styles($stylesFixture, $body, $ids, $home, $imageUrl);
if ($phase === 'styles') {
    // Qi owns this HTTP-equivalent REST callback and exits with its native JSON
    // envelope. The shell checks that envelope, then observes in a fresh process.
    $rest('/qi-blocks/v1/update-styles', ['page_id' => (string) $ids['page'], 'options' => $expectedStyles]);
    throw new RuntimeException('Qi native style callback unexpectedly returned instead of its documented response');
}
$assert($phase === 'observe' || $phase === 'capture', 'Unknown native conformance phase');
$assert(get_post_field('post_content', $ids['page'], 'raw') === $body, 'Fresh native readback changed the saved body');
$actualStyles = get_option('qi_blocks_global_styles');
$assert(is_array($actualStyles) && serialize($actualStyles['posts'][$ids['page']] ?? null) === serialize($expectedStyles), 'Native style writer changed its expected values, order or PHP container types');
$frames = 0;
foreach ($expectedStyles as $style) foreach ($style->values as $value) $frames += substr_count($value->selector, 'body[class*="-' . $ids['page'] . '"]');
$assert($frames === 84, 'Native CSS corpus lost its owning page selectors');
if ($phase === 'capture') {
    $tokens = [];
    foreach ($ids as $name => $id) {
        $uuid = get_post_meta($id, '_wprism_uuid', true);
        $assert(is_string($uuid) && preg_match('/^[0-9a-f-]{36}$/D', $uuid) === 1, 'Capture did not establish native ' . $name . ' identity');
        $tokens[$name] = '{{post:' . $uuid . '}}';
    }
    $files = glob('/siterepo/state/posts/page/' . get_post_meta($ids['page'], '_wprism_uuid', true) . '--*.md');
    $assert(count($files) === 1, 'Captured corpus page is absent or ambiguous');
    [$front, $canonicalBody] = \WPrism\Canon::parse_post_file(file_get_contents($files[0]));
    $canonicalImage = str_replace(wp_upload_dir()['baseurl'], '{{uploads}}', $imageUrl);
    $expectedBody = QiConformanceCorpus::body($saved, $tokens, '{{home}}', $canonicalImage, true);
    $assert($canonicalBody === $expectedBody, 'Full canonical body differs from the independent native corpus expectation');
    $document = \WPrism\Canon::decode(file_get_contents('/siterepo/state/options/core.json'));
    $options = \WPrism\OptionState::values($document);
    $packed = $options['qi_blocks_global_styles'] ?? null;
    $portableStyles = \WPrism\PhpContainerValue::restore($packed, 'Qi native capture proof');
    $expectedPortable = QiConformanceCorpus::styles($stylesFixture, $body, $tokens, '{{home}}', $canonicalImage);
    $assert(serialize($portableStyles['posts'][$tokens['page']] ?? null) === serialize($expectedPortable), 'Canonical CSS does not preserve complete native styles with portable owner frames');
}
echo wp_json_encode(['format' => 'wprism-qi-native-observation/v1', 'phase' => $phase,
    'block_types' => 47, 'block_instances' => 50, 'selector_frames' => $frames,
    'body_sha256' => hash('sha256', $body), 'styles_sha256' => hash('sha256', serialize($actualStyles))]), "\n";

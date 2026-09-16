<?php
declare(strict_types=1);

require_once __DIR__ . '/corpus.php';
if (!defined('QI_BLOCKS_VERSION') || QI_BLOCKS_VERSION !== '1.5.2' || !current_user_can('manage_options')) {
    throw new RuntimeException('Native picker evidence requires locked Qi and an administrator');
}
$receipt = json_decode(file_get_contents(dirname(__DIR__) . '/receipt.json'), true, 32, JSON_THROW_ON_ERROR);
$page = $receipt['ids']['page']; $attachment = $receipt['ids']['image'];
if (get_post_type($page) !== 'page' || get_post_type($attachment) !== 'attachment') throw new RuntimeException('Native gallery receipt identities are absent');
$saved = QiNativeControlsCorpus::saved(file_get_contents(dirname(__DIR__) . '/native-blocks.html'), file_get_contents(__DIR__ . '/blocks.html'));
$body = QiConformanceCorpus::body($saved, $receipt['ids'], home_url(), wp_get_attachment_url($attachment));
$request = new WP_REST_Request('POST', '/wp/v2/pages/' . $page);
$request->set_header('Content-Type', 'application/json');
$request->set_body(wp_json_encode(['content' => $body]));
$response = rest_do_request($request);
if (is_wp_error($response) || $response->get_status() !== 200 || get_post_field('post_content', $page, 'raw') !== $body) {
    throw new RuntimeException('Native gallery fixture Save or exact readback failed');
}
echo wp_json_encode(['format' => 'wprism-qi-native-gallery-seed/v1', 'page' => $page,
    'attachment' => $attachment, 'body_sha256' => hash('sha256', $body)]);

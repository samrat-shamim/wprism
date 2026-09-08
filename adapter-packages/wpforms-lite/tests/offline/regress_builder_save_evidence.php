<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 2) . '/fixtures/qr-destination/browser-evidence.php';

// Synthetic transport exercises the actual host admission. It is not a
// recorded native Save; the checked-in collector still needs a native run.
$home = 'http://localhost:9546';
$formId = 6;
$qr = ['qr_code' => 'page', 'qr_code_page_id' => '5', 'qr_code_url' => '', 'qr_code_logo' => 'wpforms',
    'qr_code_generated' => $home . '/wprism-qr-page-a/'];
$pages = [2 => $home . '/sample-page/', 4 => $home . '/wprism-qr-page-a/', 5 => $home . '/wprism-qr-page-b/'];
$controls = [];
foreach (['id' => '6', 'fields[1][id]' => '1', 'fields[1][type]' => 'text', 'fields[1][label]' => 'Text Ω',
    'settings[form_title]' => 'WPrism QR Destination Proof', 'settings[form_desc]' => '',
    'settings[form_tags_json]' => '[]', 'settings[confirmations][1][type]' => 'message'] as $name => $value) $controls[] = compact('name', 'value');
foreach ($qr as $key => $value) $controls[] = ['name' => 'settings[' . $key . ']', 'value' => $value];
$url = $home . '/wp-admin/admin.php?page=wpforms-builder&view=settings&form_id=6&section=general';
$snapshot = ['url' => $url, 'home' => $home, 'form_id' => 6, 'nonce' => 'a123456789', 'ajax_url' => $home . '/wp-admin/admin-ajax.php',
    'controls' => $controls, 'page_maps' => [['id' => 'wpforms-panel-field-settings-qr_code-content', 'value' => json_encode($pages, JSON_THROW_ON_ERROR)]], 'saved' => false];
$body = static fn(string $bytes): array => ['length' => strlen($bytes), 'base64' => base64_encode($bytes)];
$headers = static function (array $map): array { $out = []; foreach ($map as $name => $value) $out[] = compact('name', 'value'); return $out; };
$data = ['form_name' => 'WPrism QR Destination Proof', 'form_desc' => '', 'redirect' => $home . '/wp-admin/admin.php?page=wpforms-overview'];
$post = ['action' => 'wpforms_save_form', 'data' => json_encode($controls, JSON_THROW_ON_ERROR), 'id' => '6', 'nonce' => 'a123456789'];
$requestBytes = http_build_query($post);
$responseBytes = json_encode(['success' => true, 'data' => $data], JSON_THROW_ON_ERROR);
$good = ['format' => 'wprism-wpforms-builder-save/v1', 'started_ms' => 1000, 'ended_ms' => 1100,
    'before' => $snapshot, 'after' => array_replace($snapshot, ['saved' => true]),
    'events' => ['before_save' => [['at_ms' => 1010, 'controls' => $controls]], 'saved' => [['at_ms' => 1050, 'data' => $data]]],
    'exchanges' => [['ordinal' => 1, 'started_ms' => 1020, 'method' => 'POST', 'url' => $home . '/wp-admin/admin-ajax.php', 'resource_type' => 'xhr',
        'redirected' => false, 'request_headers' => $headers(['Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
            'Origin' => $home, 'Referer' => $url, 'X-Requested-With' => 'XMLHttpRequest']), 'request_body' => $body($requestBytes),
        'response' => ['status' => 200, 'received_ms' => 1040, 'headers' => $headers(['Content-Type' => 'application/json; charset=UTF-8']),
            'body' => $body($responseBytes), 'finished_ms' => 1060], 'failure' => null]],
    'console' => [], 'page_errors' => [], 'errors' => [], 'drained' => true];
$admit = static fn(array $record): array => WPFormsBuilderSaveEvidence::admit($record, $home, $formId, $qr, $pages);
wprism_check_same(['request_bytes' => strlen($requestBytes), 'request_sha256' => hash('sha256', $requestBytes),
    'response_bytes' => strlen($responseBytes), 'response_sha256' => hash('sha256', $responseBytes)], $admit($good),
    'actual capsule admission binds full submitted bytes, response and native event semantics');
$mutations = [
    'missing request' => static function (array &$v): void { $v['exchanges'] = []; },
    'extra request' => static function (array &$v): void { $v['exchanges'][] = $v['exchanges'][0]; },
    'wrong method' => static function (array &$v): void { $v['exchanges'][0]['method'] = 'GET'; },
    'wrong route' => static function (array &$v): void { $v['exchanges'][0]['url'] .= '/other'; },
    'copied fetch writer' => static function (array &$v): void { $v['exchanges'][0]['resource_type'] = 'fetch'; },
    'redirected request' => static function (array &$v): void { $v['exchanges'][0]['redirected'] = true; },
    'failed transport' => static function (array &$v): void { $v['exchanges'][0]['failure'] = ['errorText' => 'aborted']; },
    'missing request body' => static function (array &$v): void { $v['exchanges'][0]['request_body'] = null; },
    'missing response' => static function (array &$v): void { $v['exchanges'][0]['response'] = null; },
    'missing response body' => static function (array &$v): void { $v['exchanges'][0]['response']['body'] = null; },
    'HTTP failure' => static function (array &$v): void { $v['exchanges'][0]['response']['status'] = 403; },
    'body length drift' => static function (array &$v): void { ++$v['exchanges'][0]['request_body']['length']; },
    'body framing drift' => static function (array &$v): void { $v['exchanges'][0]['response']['body']['base64'] .= ' '; },
    'extra JSON fields' => static function (array &$v): void { $v['invented'] = true; },
    'diagnostic warning' => static function (array &$v): void { $v['console'] = [['type' => 'warning', 'text' => 'native diagnostic']]; },
    'browser error' => static function (array &$v): void { $v['page_errors'] = ['transition skipped']; },
    'runner error' => static function (array &$v): void { $v['errors'] = ['body read failed']; },
    'undrained failure' => static function (array &$v): void { $v['drained'] = false; },
    'wrong Builder identity' => static function (array &$v): void { $v['before']['form_id'] = 7; },
    'unsaved native form' => static function (array &$v): void { $v['after']['saved'] = false; },
    'no native saved event' => static function (array &$v): void { $v['events']['saved'] = []; },
    'duplicate native saved event' => static function (array &$v): void { $v['events']['saved'][] = $v['events']['saved'][0]; },
    'premature native saved event' => static function (array &$v): void { $v['events']['saved'][0]['at_ms'] = 1030; },
    'late body read' => static function (array &$v): void { $v['exchanges'][0]['response']['finished_ms'] = 1200; },
    'wrong event response' => static function (array &$v): void { $v['events']['saved'][0]['data']['form_desc'] = 'changed'; },
    'before-save serialization drift' => static function (array &$v): void { array_pop($v['events']['before_save'][0]['controls']); },
    'full non-QR preservation drift' => static function (array &$v): void { $v['after']['controls'][3]['value'] = 'changed'; },
    'wrong page-map identity' => static function (array &$v): void { $v['before']['page_maps'][0]['value'] = '{}'; },
    'wrong semantic header' => static function (array &$v): void { $v['exchanges'][0]['request_headers'][1]['value'] = 'http://foreign.invalid'; },
    'duplicate semantic header' => static function (array &$v): void { $v['exchanges'][0]['response']['headers'][] = $v['exchanges'][0]['response']['headers'][0]; },
];
foreach ($mutations as $name => $mutate) {
    $changed = $good;
    $mutate($changed);
    wprism_check_throws(static fn() => $admit($changed), RuntimeException::class, $name . ' refuses');
}
foreach (['wrong nonce' => ['nonce' => '0000000000'], 'wrong form' => ['id' => '7'],
    'alternate writer' => ['action' => 'wpforms_new_form'], 'AI revision' => ['ai_revision_source' => 'smart_edit'],
    'truncated control list' => ['data' => '[]']] as $name => $change) {
    $changed = $good;
    $changed['exchanges'][0]['request_body'] = $body(http_build_query(array_replace($post, $change)));
    wprism_check_throws(static fn() => $admit($changed), RuntimeException::class, $name . ' refuses through actual POST admission');
}
foreach (['duplicate field' => $requestBytes . '&id=6', 'bad escape' => $requestBytes . '%XX'] as $name => $bytes) {
    $changed = $good;
    $changed['exchanges'][0]['request_body'] = $body($bytes);
    wprism_check_throws(static fn() => $admit($changed), RuntimeException::class, $name . ' cannot hide in URL decoding');
}
foreach (['payments[paypal_commerce][enable]' => '1', 'settings[qr_code_logo_id]' => '0', 'fields[2][type]' => 'payment-single'] as $name => $value) {
    $changed = $good;
    foreach (['before', 'after'] as $phase) $changed[$phase]['controls'][] = compact('name', 'value');
    wprism_check_throws(static fn() => $admit($changed), RuntimeException::class, 'out-of-lane native control refuses: ' . $name);
}
$invocation = WPFormsBuilderSaveEvidence::invocation($home, $formId);
wprism_check(str_contains($invocation, ')(page, {"home":"http:\/\/localhost:9546","form_id":6})')
    && str_contains($invocation, (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/qr-destination/browser-save.js')),
    'invocation contains the exact collector and independently supplied owner identity');
foreach (['http://foreign.invalid', 'http://localhost:80', 'http://localhost:70000', 'http://localhost:9546/extra'] as $badHome) {
    wprism_check_throws(static fn() => WPFormsBuilderSaveEvidence::invocation($badHome, 6), RuntimeException::class, 'wrong owner origin refuses before browser invocation');
}
wprism_check_summary('regress_wpforms_builder_save_evidence');

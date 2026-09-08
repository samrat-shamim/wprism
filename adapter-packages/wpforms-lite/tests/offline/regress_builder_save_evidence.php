<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 2) . '/fixtures/qr-destination/browser-evidence.php';

// Synthetic transport exercises the actual host admission. Native v5 exposed
// Choices search controls and a second theme writer; its warning-bearing REST
// response remains a refusal, not a positive fixture or native E2E proof.
$home = 'http://localhost:9546';
$formId = 6;
$qr = ['qr_code' => 'page', 'qr_code_page_id' => '5', 'qr_code_url' => '', 'qr_code_logo' => 'wpforms',
    'qr_code_generated' => $home . '/wprism-qr-page-a/'];
$pages = [2 => $home . '/sample-page/', 4 => $home . '/wprism-qr-page-a/', 5 => $home . '/wprism-qr-page-b/'];
$controls = [];
foreach (['id' => '6', 'fields[1][id]' => '1', 'fields[1][type]' => 'text', 'fields[1][label]' => 'Text Ω',
    'settings[form_title]' => 'WPrism QR Destination Proof', 'settings[form_desc]' => '',
    'settings[form_tags_json]' => '[]', 'settings[confirmations][1][type]' => 'message',
    'settings[confirmations][1][message]' => '<p>Thanks for contacting us!</p>'] as $name => $value) $controls[] = compact('name', 'value');
foreach ($qr as $key => $value) $controls[] = ['name' => 'settings[' . $key . ']', 'value' => $value];
for ($index = 0; $index < 3; $index++) $controls[] = ['name' => 'search_terms', 'value' => ''];
$url = $home . '/wp-admin/admin.php?page=wpforms-builder&view=settings&form_id=6&section=general';
$snapshot = ['url' => $url, 'home' => $home, 'form_id' => 6, 'nonce' => 'a123456789', 'ajax_url' => $home . '/wp-admin/admin-ajax.php',
    'controls' => $controls, 'page_maps' => [['id' => 'wpforms-panel-field-settings-qr_code-content', 'value' => json_encode($pages, JSON_THROW_ON_ERROR)]], 'saved' => false];
$body = static fn(string $bytes): array => ['length' => strlen($bytes), 'base64' => base64_encode($bytes)];
$headers = static function (array $map): array { $out = []; foreach ($map as $name => $value) $out[] = compact('name', 'value'); return $out; };
$data = ['form_name' => 'WPrism QR Destination Proof', 'form_desc' => '', 'redirect' => $home . '/wp-admin/admin.php?page=wpforms-overview'];
$post = ['action' => 'wpforms_save_form', 'data' => json_encode($controls, JSON_THROW_ON_ERROR), 'id' => '6', 'nonce' => 'a123456789'];
$requestBytes = http_build_query($post);
$responseBytes = json_encode(['success' => true, 'data' => $data], JSON_THROW_ON_ERROR);
$themeRequest = '{"customThemes":{}}';
$themeResponse = '{"result":true}';
$good = ['format' => 'wprism-wpforms-builder-save/v1', 'started_ms' => 1000, 'ended_ms' => 1400,
    'before' => $snapshot, 'after' => array_replace($snapshot, ['saved' => true]),
    'events' => ['before_save' => [['at_ms' => 1010, 'controls' => $controls]], 'saved' => [['at_ms' => 1050, 'data' => $data]], 'closed_ms' => 1300],
    'exchanges' => [['ordinal' => 1, 'started_ms' => 1020, 'method' => 'POST', 'url' => $home . '/wp-admin/admin-ajax.php', 'resource_type' => 'xhr',
        'redirected' => false, 'request_headers' => $headers(['Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
            'Origin' => $home, 'Referer' => $url, 'X-Requested-With' => 'XMLHttpRequest']), 'request_body' => $body($requestBytes),
        'response' => ['status' => 200, 'received_ms' => 1040, 'headers' => $headers(['Content-Type' => 'application/json; charset=UTF-8']),
            'body' => $body($responseBytes), 'finished_ms' => 1060], 'failure' => null]],
    'console' => [], 'page_errors' => [], 'errors' => [], 'drained' => true];
$good['exchanges'][] = ['ordinal' => 2, 'started_ms' => 1051, 'method' => 'POST',
    'url' => $home . '/wp-json/wpforms/v1/themes/custom/?_locale=user', 'resource_type' => 'fetch', 'redirected' => false,
    'request_headers' => $headers(['Content-Type' => 'application/json', 'Origin' => $home, 'Referer' => $url, 'X-WP-Nonce' => 'b123456789']),
    'request_body' => $body($themeRequest), 'response' => ['status' => 200, 'received_ms' => 1080,
        'headers' => $headers(['Content-Type' => 'application/json; charset=UTF-8', 'X-WP-Nonce' => 'b123456789']),
        'body' => $body($themeResponse), 'finished_ms' => 1100], 'failure' => null];
$baseline = ['format' => 'wprism-wpforms-builder-baseline/v1', 'observed_ms' => 990, 'snapshot' => $snapshot];
$admit = static fn(array $record): array => WPFormsBuilderSaveEvidence::admit($record, $home, $formId, $qr, $pages, $baseline);
wprism_check_same(['request_bytes' => strlen($requestBytes), 'request_sha256' => hash('sha256', $requestBytes),
    'response_bytes' => strlen($responseBytes), 'response_sha256' => hash('sha256', $responseBytes),
    'theme_request_bytes' => strlen($themeRequest), 'theme_request_sha256' => hash('sha256', $themeRequest),
    'theme_response_bytes' => strlen($themeResponse), 'theme_response_sha256' => hash('sha256', $themeResponse)], $admit($good),
    'actual capsule admission binds complete controls and both native writer exchanges, not their physical effects');
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
    'late body read' => static function (array &$v): void { $v['exchanges'][0]['response']['finished_ms'] = 1500; },
    'missing observation suffix' => static function (array &$v): void { $v['events']['closed_ms'] = 1060; },
    'missing closure' => static function (array &$v): void { $v['events']['closed_ms'] = null; },
    'late closure' => static function (array &$v): void { $v['events']['closed_ms'] = 1500; },
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
$themeMutations = [
    'missing secondary writer' => static function (array &$v): void { array_pop($v['exchanges']); },
    'reordered writers' => static function (array &$v): void { $v['exchanges'] = array_reverse($v['exchanges']); },
    'secondary ordinal' => static function (array &$v): void { $v['exchanges'][1]['ordinal'] = 1; },
    'secondary method' => static function (array &$v): void { $v['exchanges'][1]['method'] = 'GET'; },
    'secondary route' => static function (array &$v): void { $v['exchanges'][1]['url'] .= '&unexpected=1'; },
    'secondary resource type' => static function (array &$v): void { $v['exchanges'][1]['resource_type'] = 'xhr'; },
    'secondary redirect' => static function (array &$v): void { $v['exchanges'][1]['redirected'] = true; },
    'secondary transport failure' => static function (array &$v): void { $v['exchanges'][1]['failure'] = ['errorText' => 'aborted']; },
    'missing secondary body' => static function (array &$v): void { $v['exchanges'][1]['request_body'] = null; },
    'missing secondary response' => static function (array &$v): void { $v['exchanges'][1]['response'] = null; },
    'secondary HTTP failure' => static function (array &$v): void { $v['exchanges'][1]['response']['status'] = 500; },
    'secondary body framing' => static function (array &$v): void { ++$v['exchanges'][1]['response']['body']['length']; },
    'secondary content type' => static function (array &$v): void { $v['exchanges'][1]['request_headers'][0]['value'] = 'text/plain'; },
    'secondary origin' => static function (array &$v): void { $v['exchanges'][1]['request_headers'][1]['value'] = 'http://foreign.invalid'; },
    'secondary referer' => static function (array &$v): void { $v['exchanges'][1]['request_headers'][2]['value'] .= '&other=1'; },
    'secondary nonce framing' => static function (array &$v): void { $v['exchanges'][1]['request_headers'][3]['value'] = ''; },
    'secondary nonce mismatch' => static function (array &$v): void { $v['exchanges'][1]['response']['headers'][1]['value'] = 'c123456789'; },
    'secondary duplicate nonce' => static function (array &$v): void { $v['exchanges'][1]['request_headers'][] = $v['exchanges'][1]['request_headers'][3]; },
    'theme before form success' => static function (array &$v): void { $v['exchanges'][1]['started_ms'] = 1039; },
    'theme after observation suffix' => static function (array &$v): void { $v['exchanges'][1]['started_ms'] = 1301; },
    'theme response before request' => static function (array &$v): void { $v['exchanges'][1]['response']['received_ms'] = 1049; },
    'theme incomplete at close' => static function (array &$v): void { $v['exchanges'][1]['response']['finished_ms'] = 1401; },
];
foreach ($themeMutations as $name => $mutate) {
    $changed = $good;
    $mutate($changed);
    wprism_check_throws(static fn() => $admit($changed), RuntimeException::class, $name . ' refuses');
}
foreach (['{}', '{"customThemes":[]}', '{"customThemes":{"unexpected":{}}}', '{"customThemes":{},"extra":true}'] as $bytes) {
    $changed = $good;
    $changed['exchanges'][1]['request_body'] = $body($bytes);
    wprism_check_throws(static fn() => $admit($changed), RuntimeException::class, 'different complete theme payload refuses');
}
foreach (['{"result":false}', '{"result":true,"error":"warning"}', '{"result":1}', '{}'] as $bytes) {
    $changed = $good;
    $changed['exchanges'][1]['response']['body'] = $body($bytes);
    wprism_check_throws(static fn() => $admit($changed), RuntimeException::class, 'unsuccessful or ambiguous theme response refuses');
}
foreach ([0, 1] as $exchangeIndex) foreach (['native warning', '', 'duplicate'] as $variant) {
    $changed = $good;
    $diagnostic = ['name' => 'x-Wp-DoingItWrong', 'value' => $variant === '' ? '' : 'register_rest_route: slash-delimited namespace'];
    $changed['exchanges'][$exchangeIndex]['response']['headers'][] = $diagnostic;
    if ($variant === 'duplicate') $changed['exchanges'][$exchangeIndex]['response']['headers'][] = $diagnostic;
    wprism_check_throws(static fn() => $admit($changed), RuntimeException::class,
        'HTTP200 diagnostic header refuses independently of empty logs: ' . $exchangeIndex . '/' . $variant,
        'native HTTP response contains a WordPress diagnostic header');
}
// Model coherent control changes across all observations and the real POST:
// refusal must come from the lane's multiplicity/value constraint, not drift.
foreach (['missing search', 'extra search', 'active search', 'other duplicate'] as $variant) {
    $changed = $good;
    $changedControls = $controls;
    if ($variant === 'missing search') array_pop($changedControls);
    elseif ($variant === 'extra search') $changedControls[] = ['name' => 'search_terms', 'value' => ''];
    elseif ($variant === 'active search') $changedControls[count($changedControls) - 1]['value'] = 'pending search';
    else $changedControls[] = ['name' => 'id', 'value' => '6'];
    foreach (['before', 'after'] as $phase) $changed[$phase]['controls'] = $changedControls;
    $changed['events']['before_save'][0]['controls'] = $changedControls;
    $changedPost = array_replace($post, ['data' => json_encode($changedControls, JSON_THROW_ON_ERROR)]);
    $changed['exchanges'][0]['request_body'] = $body(http_build_query($changedPost));
    $changedBaseline = $baseline;
    $changedBaseline['snapshot']['controls'] = $changedControls;
    wprism_check_throws(static fn() => WPFormsBuilderSaveEvidence::admit($changed, $home, $formId, $qr, $pages, $changedBaseline),
        RuntimeException::class, 'coherent ' . $variant . ' refuses');
}
$changed = $good;
$changed['exchanges'][1]['started_ms'] = 1049;
$admit($changed);
wprism_check(true, 'theme listener may dispatch after form response but before our Saved listener');
$changed = $good;
$changedBaseline = $baseline;
foreach ($controls as $index => $control) {
    if ($control['name'] !== 'settings[confirmations][1][message]') continue;
    $changed['before']['controls'][$index]['value'] = 'Thanks for contacting us!';
    $changedBaseline['snapshot']['controls'][$index]['value'] = 'Thanks for contacting us!';
}
wprism_check_throws(static fn() => WPFormsBuilderSaveEvidence::admit($changed, $home, $formId, $qr, $pages, $changedBaseline),
    RuntimeException::class, 'first-save rich-text synchronization cannot be relabeled QR-only preservation',
    'entire observed form-control list preserved');
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
foreach (['payments[paypal_commerce][enable]' => '1', 'settings[qr_code_logo_id]' => '0', 'fields[2][type]' => 'payment-single',
    'fields[2][label]' => 'hidden untyped field'] as $name => $value) {
    $changed = $good;
    foreach (['before', 'after'] as $phase) $changed[$phase]['controls'][] = compact('name', 'value');
    $changed['events']['before_save'][0]['controls'][] = compact('name', 'value');
    $changedPost = $post;
    $changedPost['data'] = json_encode($changed['before']['controls'], JSON_THROW_ON_ERROR);
    $changed['exchanges'][0]['request_body'] = $body(http_build_query($changedPost));
    $changedBaseline = $baseline;
    $changedBaseline['snapshot']['controls'][] = compact('name', 'value');
    wprism_check_throws(static fn() => WPFormsBuilderSaveEvidence::admit($changed, $home, $formId, $qr, $pages, $changedBaseline),
        RuntimeException::class, 'out-of-lane native control refuses: ' . $name);
}
$changed = $good;
foreach (['before', 'after'] as $phase) $changed[$phase]['controls'][3]['value'] = 'coordinated forgery';
$changed['events']['before_save'][0]['controls'][3]['value'] = 'coordinated forgery';
$forgedPost = $post;
$forgedPost['data'] = json_encode($changed['before']['controls'], JSON_THROW_ON_ERROR);
$changed['exchanges'][0]['request_body'] = $body(http_build_query($forgedPost));
wprism_check_throws(static fn() => $admit($changed), RuntimeException::class, 'coherent full-control mutation refuses against the independent baseline');
$invocation = WPFormsBuilderSaveEvidence::invocation($home, $formId);
wprism_check(str_contains($invocation, ')(page, {"home":"http:\/\/localhost:9546","form_id":6,"mode":"save"})')
    && str_contains($invocation, (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/qr-destination/browser-save.js')),
    'invocation contains the exact collector and independently supplied owner identity');
wprism_check(str_contains(WPFormsBuilderSaveEvidence::invocation($home, $formId, true), '"mode":"observe"'),
    'the independently captured baseline uses the explicit read-only collector mode');
foreach ([1001, -60000] as $observedAt) {
    $badBaseline = array_replace($baseline, ['observed_ms' => $observedAt]);
    wprism_check_throws(static fn() => WPFormsBuilderSaveEvidence::admit($good, $home, $formId, $qr, $pages, $badBaseline),
        RuntimeException::class, 'future or stale baseline refuses');
}
foreach (['0', '05', '+5', '5e0', 'not-a-page', '99'] as $pageValue) {
    $changed = $good;
    $changedQr = array_replace($qr, ['qr_code_page_id' => $pageValue]);
    $changedBaseline = $baseline;
    foreach ($changed['before']['controls'] as $index => $control) {
        if ($control['name'] !== 'settings[qr_code_page_id]') continue;
        foreach (['before', 'after'] as $phase) $changed[$phase]['controls'][$index]['value'] = $pageValue;
        $changed['events']['before_save'][0]['controls'][$index]['value'] = $pageValue;
        $changedBaseline['snapshot']['controls'][$index]['value'] = $pageValue;
    }
    $changedPost = $post;
    $changedPost['data'] = json_encode($changed['before']['controls'], JSON_THROW_ON_ERROR);
    $changed['exchanges'][0]['request_body'] = $body(http_build_query($changedPost));
    wprism_check_throws(static fn() => WPFormsBuilderSaveEvidence::admit($changed, $home, $formId, $changedQr, $pages, $changedBaseline),
        RuntimeException::class, 'malformed or unobserved submitted page identity refuses: ' . $pageValue);
}
foreach (['empty page placeholder' => ['qr_code_page_id' => ''], 'None keeps inactive submitted inputs' =>
    ['qr_code' => 'none', 'qr_code_url' => $home . '/wprism-qr-page-b/?label=701', 'qr_code_generated' => '']] as $label => $change) {
    $changed = $good;
    $changedQr = array_replace($qr, $change);
    $changedBaseline = $baseline;
    foreach ($controls as $index => $control) foreach ($change as $key => $value) {
        if ($control['name'] !== 'settings[' . $key . ']') continue;
        foreach (['before', 'after'] as $phase) $changed[$phase]['controls'][$index]['value'] = $value;
        $changed['events']['before_save'][0]['controls'][$index]['value'] = $value;
        $changedBaseline['snapshot']['controls'][$index]['value'] = $value;
    }
    $changedPost = $post;
    $changedPost['data'] = json_encode($changed['before']['controls'], JSON_THROW_ON_ERROR);
    $changed['exchanges'][0]['request_body'] = $body(http_build_query($changedPost));
    WPFormsBuilderSaveEvidence::admit($changed, $home, $formId, $changedQr, $pages, $changedBaseline);
    wprism_check(true, $label . ' is admitted as DOM/HTTP evidence, not a persisted-body verdict');
}
foreach (['http://foreign.invalid', 'http://localhost:80', 'http://localhost:70000', 'http://localhost:9546/extra'] as $badHome) {
    wprism_check_throws(static fn() => WPFormsBuilderSaveEvidence::invocation($badHome, 6), RuntimeException::class, 'wrong owner origin refuses before browser invocation');
}
$private = sys_get_temp_dir() . '/wprism-builder-save-evidence-' . bin2hex(random_bytes(8));
wprism_check(mkdir($private, 0700), 'private test transport allocated without replacement');
try {
    foreach (['stdout' => json_encode(['result' => json_encode($good, JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR), 'stderr' => '', 'exit' => "0\n"] as $suffix => $bytes) {
        $path = $private . '/save.' . $suffix;
        wprism_check(file_put_contents($path, $bytes) === strlen($bytes) && chmod($path, 0600), 'private ' . $suffix . ' framing');
    }
    wprism_check_same($good, WPFormsBuilderSaveEvidence::read($private . '/save'), 'actual private CLI JSON envelope retains complete nested record');
    foreach (['error exit zero' => '{"isError":true,"error":"fixture error"}', 'error beside result' =>
        json_encode(['result' => json_encode($good, JSON_THROW_ON_ERROR), 'isError' => true], JSON_THROW_ON_ERROR),
        'nonstring result' => '{"result":true}', 'missing object' => '{"result":"[]"}',
        'truncated result' => '{"result":"{"}', 'text-mode envelope' => "### Result\n{}\n"] as $label => $bytes) {
        file_put_contents($private . '/save.stdout', $bytes);
        wprism_check_throws(static fn() => WPFormsBuilderSaveEvidence::read($private . '/save'), Throwable::class,
            $label . ' refuses even with exit0 and empty stderr');
    }
} finally {
    foreach (['stdout', 'stderr', 'exit'] as $suffix) wprism_check(unlink($private . '/save.' . $suffix), 'retire exact private transport ' . $suffix);
    wprism_check(rmdir($private), 'retire owned empty transport root');
}
wprism_check_summary('regress_wpforms_builder_save_evidence');

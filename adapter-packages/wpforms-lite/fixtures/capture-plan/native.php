<?php
declare(strict_types=1);

// Only the real plugin may author these bodies. Host admission independently
// compares their complete captured representation; this worker never supplies
// a manifest, a FrozenPolicy, a canonical entity, or a preassigned ledger ID.
$phase = $args[0] ?? '';
if (!in_array($phase, ['seed', 'observe'], true)) {
    throw new RuntimeException('WPForms conformance: unknown native phase');
}
$require = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException('WPForms conformance: ' . $message);
    }
};
$require(defined('WPFORMS_VERSION') && WPFORMS_VERSION === '2.0.1.1', 'wrong exact plugin release');
$require(!wpforms()->is_pro() && current_user_can('manage_options'), 'wrong edition or actor');
$roles = [
    'integer' => ['wpforms', 'wprism-wpf-integer'],
    'string' => ['wpforms', 'wprism-wpf-string'],
    'template' => ['wpforms-template', 'wprism-wpf-template'],
    'destination' => ['page', 'wprism-wpf-destination'],
    'embed' => ['page', 'wprism-wpf-embed'],
];
$find = static function (string $type, string $slug) use ($require): array {
    $rows = get_posts([
        'post_type' => $type, 'name' => $slug, 'post_status' => 'any',
        'posts_per_page' => 2, 'suppress_filters' => true,
    ]);
    $require(is_array($rows) && count($rows) <= 1, 'ambiguous native fixture identity');
    return $rows;
};
$body = static function (int $id) use ($require): array {
    $post = wpforms()->obj('form')->get($id);
    $require($post instanceof WP_Post, 'native form API cannot read its row');
    $data = wpforms_decode($post->post_content);
    $require(is_array($data), 'native body is not a form document');
    return $data;
};

if ($phase === 'seed') {
    foreach ($roles as [$type, $slug]) {
        $require($find($type, $slug) === [], 'fresh source already contains a fixture');
    }
    $auth = new WPForms\SetupWizard\Auth();
    $token = $auth->generate_token();
    $request = new WP_REST_Request('POST', '/wpforms/v1/setup-wizard/update');
    $request->set_header('X-WPForms-Setup-Wizard-Token', $token);
    $request->set_header('Content-Type', 'application/json');
    $request->set_body(wp_json_encode(['lite-connect-enabled' => false, 'gdpr' => true]));
    $require(rest_do_request($request)->get_status() === 200, 'native wizard update failed');
    $complete = new WP_REST_Request('POST', '/wpforms/v1/setup-wizard/complete');
    $complete->set_header('X-WPForms-Setup-Wizard-Token', $token);
    $complete->set_param('outcome', 'build');
    $require(rest_do_request($complete)->get_status() === 200, 'native wizard completion failed');
    $require($auth->validate_request($request) === 0, 'native wizard session was not revoked');

    $ids = [];
    foreach (['integer', 'string', 'template'] as $role) {
        [$type, $slug] = $roles[$role];
        $ids[$role] = wpforms()->obj('form')->add(
            'WPrism WPForms ' . $role,
            ['post_type' => $type, 'post_name' => $slug],
            $role === 'template' ? [] : ['builder' => false]
        );
        $require(is_int($ids[$role]) && $ids[$role] > 0, 'native form creation failed');
    }
    $ids['destination'] = wp_insert_post([
        'post_type' => 'page', 'post_status' => 'publish',
        'post_name' => $roles['destination'][1], 'post_title' => 'WPrism WPForms destination',
        'post_content' => 'Native confirmation destination.',
    ], true);
    $require(is_int($ids['destination']) && $ids['destination'] > 0, 'destination creation failed');
    foreach (['integer', 'string'] as $role) {
        $id = $ids[$role];
        $data = $body($id);
        $data['id'] = $role === 'string' ? (string) $id : $id;
        $data['field_id'] = '3';
        $data['fields'] = [
            1 => ['id' => '1', 'type' => 'text', 'label' => 'Message 日本語 Ω', 'required' => '1', 'default_value' => 'Public fixture'],
            2 => ['id' => '2', 'type' => 'email', 'label' => 'Email', 'required' => '1'],
        ];
        $data['settings']['notifications'] = [1 => [
            'email' => 'operations@example.test', 'sender_name' => 'WPrism notifications Ω',
            'sender_address' => 'forms@example.test', 'replyto' => 'support@example.test',
            'subject' => 'Native fixture', 'message' => '{all_fields}',
        ]];
        $data['settings']['notification_enable'] = '1';
        $data['settings']['confirmations'] = [
            1 => ['type' => 'message', 'message' => 'Thanks 日本語'],
            2 => ['type' => 'page', 'page' => (string) $ids['destination']],
            3 => ['type' => 'page', 'page' => 'previous_page'],
            4 => ['type' => 'redirect', 'redirect' => home_url('/wprism-wpf-destination/?page_id=' . $ids['destination'])],
        ];
        $forms = wpforms()->obj('form');
        $require($forms->update($id, $data) === $id, 'native form update failed');
        $stored = $forms->get($id)->post_content;
        $require($forms->update($id, $body($id)) === $id
            && $forms->get($id)->post_content === $stored, 'native repeat changed complete body bytes');
    }
    $ids['embed'] = wp_insert_post(wp_slash([
        'post_type' => 'page', 'post_status' => 'publish',
        'post_name' => $roles['embed'][1], 'post_title' => 'WPrism WPForms embed',
        'post_content' => '[wpforms id="' . $ids['integer'] . '" title="true"]' . "\n"
            . '<!-- wp:wpforms/form-selector {"formId":"' . $ids['string'] . '","displayTitle":true} /-->',
    ]), true);
    $require(is_int($ids['embed']) && $ids['embed'] > 0, 'native embedding page creation failed');
}

$observed = [];
foreach ($roles as $role => [$type, $slug]) {
    $rows = $find($type, $slug);
    $require(count($rows) === 1, 'missing native fixture: ' . $role);
    $post = $rows[0];
    $uuid = $phase === 'observe' ? \WPrism\Ledger::uuid_for((int) $post->ID, 'post') : null;
    $require($phase === 'seed' || is_string($uuid), 'capture did not map the native fixture');
    $observed[$role] = [
        'id' => (int) $post->ID, 'type' => $post->post_type, 'slug' => $post->post_name,
        'body' => $post->post_content, 'uuid' => $uuid,
    ];
}
$require(count(array_unique(array_column($observed, 'id'))) === count($roles), 'fixture identities overlap');
$locator = wpforms()->obj('locator');
$located = array_values($locator->get_form_ids($observed['embed']['body']));
$require($located === [$observed['integer']['id'], $observed['string']['id']], 'native locator cannot consume both embed codecs');
$renders = [];
foreach (['integer', 'string'] as $role) {
    ob_start();
    wpforms_display($observed[$role]['id'], true, true);
    $html = ob_get_clean();
    $require(is_string($html) && $html !== '', 'native form render is empty');
    $renders[$role] = $html;
}
echo wp_json_encode([
    'format' => 'wprism-wpforms-capture-probe/v1', 'phase' => $phase,
    'version' => WPFORMS_VERSION, 'home' => home_url(),
    'onboarding' => get_option(WPForms\SetupWizard\SetupWizard::OPTION_COMPLETED),
    'posts' => $observed, 'located' => $located, 'rendered' => $renders,
], JSON_THROW_ON_ERROR);

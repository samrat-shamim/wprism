<?php
declare(strict_types=1);
wp_set_current_user(1);
$mode = trim(file_get_contents('/siterepo/.tmp-aio-profile'));
$call = static function(string $route, string $nonce, array $params): array {
    $request = new WP_REST_Request('POST', '/aio-login/' . $route);
    $request->set_body_params(['_wpnonce'=>wp_create_nonce($nonce)] + $params);
    $response = rest_do_request($request);
    $data = $response->get_data();
    if ($response->get_status() !== 200 || (isset($data['success']) && $data['success'] !== true)) throw new RuntimeException('native profile update refused: ' . $route);
    return $data;
};
$css = match($mode) {
    'repository' => '.login #login { outline: 3px solid #56789a; } /* 東京 বাংলা \'quoted\' \\slash */',
    'target' => '.login #login { outline: 4px solid #654321; } /* target-only intent */',
    'reinstall' => '.login #login { outline: 5px solid #abcdef; } /* explicit restore */',
    default => throw new RuntimeException('unknown native profile'),
};
$call('custom-css/save-custom-css-settings', 'aio-login-custom-css', ['custom_css'=>$css]);
if ($mode !== 'target') {
    $page = get_page_by_path('aio-login-destination');
    if (!$page) throw new RuntimeException('source page fixture absent');
    $call('login-redirection/save-rule','aio-login-login-redirection', ['id'=>'aio-main','condition_type'=>'all_users',
        'login_target_type'=>'custom','login_target_value'=>home_url('/aio-logout-destination/'),
        'logout_target_type'=>'page','logout_target_value'=>(string)$page->ID]);
}
// This native REST callback applies wp_unslash even to REST params; its stored
// value drops the single literal backslash. Compare the actual plugin contract.
$storedCss = str_replace('\\slash', 'slash', $css);
if (get_option('aio_login_custom-css') !== $storedCss) throw new RuntimeException('native CSS writer did not preserve difficult text');
echo wp_json_encode(['mode'=>$mode,'requested_css'=>$css,'css'=>$storedCss,'rules'=>get_option('aio_login_pro_login_redirection_rules')]);

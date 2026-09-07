<?php
declare(strict_types=1);
global $wpdb;
wp_set_current_user(1);
$receipts = [];
$nativeCases = [];
$call = static function (string $route, array $params = [], string $nonce = '', int $status = 200) use (&$receipts): array {
    $request = new WP_REST_Request('POST', '/aio-login/' . $route);
    if ($nonce !== '') $params['_wpnonce'] = wp_create_nonce($nonce);
    $request->set_body_params($params);
    $response = rest_do_request($request);
    $data = $response->get_data();
    if ($response->get_status() !== $status || ($status === 200 && isset($data['success']) && $data['success'] !== true)) {
        throw new RuntimeException($route . ' native writer refused: ' . wp_json_encode($data));
    }
    $receipts[] = ['route' => $route, 'status' => $status];
    file_put_contents('/siterepo/.tmp-aio-writer-progress.json', wp_json_encode($receipts));
    return $data;
};
$pages = [];
foreach (['login', 'logout'] as $event) {
    $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'AIO ' . $event . ' destination',
        'post_name' => 'aio-' . $event . '-destination', 'post_content' => '<p class="aio-public-control">AIO ' . $event . ' destination</p>'], true);
    if (is_wp_error($id) || $id <= 0) throw new RuntimeException('page writer failed');
    $pages[$event] = $id;
}
require_once ABSPATH . 'wp-admin/includes/image.php';
$bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aBfkAAAAASUVORK5CYII=');
$upload = wp_upload_bits('aio-native-design.png', null, $bytes);
if ($upload['error']) throw new RuntimeException('native upload failed');
$media = wp_insert_attachment(['post_title' => 'AIO native design', 'post_mime_type' => 'image/png', 'post_status' => 'inherit'], $upload['file'], 0, true);
if (is_wp_error($media) || $media <= 0) throw new RuntimeException('native attachment failed');
wp_update_attachment_metadata($media, wp_generate_attachment_metadata($media, $upload['file']));
$call('custom-css/save-custom-css-settings', ['custom_css' => '.login #login { outline: 2px solid #123456; }'], 'aio-login-custom-css');
$call('background/save-settings', ['bg_color' => '#102030', 'bg_image' => (string) $media], 'aio-login-background');
$call('logo/save-settings', ['logo_id' => (string) $media, 'redirect_url' => home_url('/aio-login-destination/'),
    'logo_width' => '110', 'logo_height' => '70', 'margin_bottom' => '18'], 'aio-login-logo');
$call('dashboard/update/limit-login-attempts', ['value' => 'off']);
$call('dashboard/update/two-factor-authentication', ['value' => 'off']);
$call('dashboard/update/block-ip-address', ['value' => 'off']);
$call('limit-login-attempts/save-settings', ['enabled' => true, 'maximum_attempts' => 3, 'timeout' => 7,
    'lockout_message' => 'AIO test lockout বাংলা'], 'limit-login-attempts');
$call('dashboard/update/user-enumeration-settings', ['settings' => ['enable_protection' => true, 'stop_oembed_calls' => true,
    'disable_author_sitemaps' => true, 'remove_comment_numbers' => true, 'protect_rest_api' => true,
    'login_registration_errors' => true, 'log_attempts' => true, 'log_duration' => 17]]);
$call('dashboard/update/activity-log-settings', ['settings' => ['log_enumeration_attempts' => true, 'log_enumeration_duration' => 19]]);
$call('passwordless-otp/save-settings', ['email_enable' => true, 'email_length' => 8, 'email_expiration' => 13,
    'email_resend_timer' => 90, 'email_max_retries' => 4, 'email_block_duration' => 21, 'email_skip_2fa' => false,
    'sms_enable' => true], 'aio-login-passwordless-otp');
if (get_option('aio_login_otp_sms_enable') !== 'off') throw new RuntimeException('free tier admitted SMS');
$call('magic-link/save-settings', ['magic_link_enable' => true], 'aio-login-magic-link', 403);
foreach (['v2', 'v3'] as $version) {
    $call('grecaptcha/save-settings', ['enabled' => false, 'version' => $version,
        'v2_site_key' => '', 'v2_secret_key' => '', 'theme' => 'dark',
        'v3_site_key' => '', 'v3_secret_key' => '', 'threshold' => '0.7'], 'google-recaptcha');
}
$call('grecaptcha/test-connection', ['provider' => 'recaptcha', 'version' => 'v2', 'site_key' => '', 'secret_key' => ''], 'google-recaptcha', 400);
$call('login-redirection/save-settings', ['settings' => ['enabled' => true, 'fallback_enabled' => true,
    'fallback_type' => 'custom', 'fallback_custom_url' => home_url('/aio-fallback/')]], 'aio-login-login-redirection');
foreach (['page-first', 'url-first'] as $mode) {
    $call('login-redirection/save-rule', ['id' => 'aio-main', 'condition_type' => 'all_users',
        'login_target_type' => $mode === 'page-first' ? 'page' : 'custom',
        'login_target_value' => $mode === 'page-first' ? (string) $pages['login'] : home_url('/aio-url-login/'),
        'logout_target_type' => $mode === 'page-first' ? 'custom' : 'page',
        'logout_target_value' => $mode === 'page-first' ? home_url('/aio-url-logout/') : (string) $pages['logout']], 'aio-login-login-redirection');
    $nativeCases[$mode] = get_option('aio_login_pro_login_redirection_rules');
}
$call('login-redirection/save-rule', ['id' => 'aio-pro-user', 'condition_type' => 'user', 'condition_value' => '1'], 'aio-login-login-redirection', 403);
$call('login-redirection/save-rule', ['id' => 'aio-pro-role', 'condition_type' => 'user_role', 'condition_value' => 'administrator'], 'aio-login-login-redirection', 403);
$call('login-redirection/save-rule', ['id' => 'aio-duplicate', 'condition_type' => 'all_users',
    'login_target_type' => 'custom', 'login_target_value' => home_url('/aio-login-destination/')], 'aio-login-login-redirection', 409);
$call('login-redirection/delete-rule', ['id' => 'aio-main'], 'aio-login-login-redirection');
if (get_option('aio_login_pro_login_redirection_rules') !== []) throw new RuntimeException('native rule deletion failed');
$call('login-redirection/save-rule', ['id' => 'aio-main', 'condition_type' => 'all_users',
    'login_target_type' => 'page', 'login_target_value' => (string) $pages['login'],
    'logout_target_type' => 'custom', 'logout_target_value' => home_url('/aio-logout-destination/')], 'aio-login-login-redirection');
$call('change-wp-admin-login/save-settings', ['enabled' => true, 'login_url' => 'AIO WPrism Login', 'redirect_url' => 'AIO WPrism Missing'], 'change-wp-admin-login');
if (get_option('rwl_page') !== 'aio-wprism-login') throw new RuntimeException('native route sanitizer premise failed');
wp_set_current_user(0);
$call('change-wp-admin-login/save-settings', ['enabled' => false], '', 401);
wp_set_current_user(1);
$call('change-wp-admin-login/save-settings', ['enabled' => false, 'login_url' => '', 'redirect_url' => '', '_wpnonce' => 'invalid'], '', 403);
$defaults = AIO_Login_Pro\Login_Customization\Login_Customizer::get_instance()->get_customizer_default_values();
$customized = $defaults;
foreach ($customized as $key => &$value) {
    if (str_ends_with($key, '_color')) $value = '#345678';
}
unset($value);
$customized = array_replace($customized, [
    'aio_login_background_image' => $media, 'aio_login_background_image_mobile' => $media,
    'aio_login_logo' => $media, 'aio_login_favicon' => $media, 'aio_login_forgot_background_image' => $media,
    'aio_login__customization_templates' => 'template-8', 'aio_login_logo_width' => 114, 'aio_login_logo_height' => 72,
    'aio_login_margin_bottom' => 19, 'aio_login_logo_url' => home_url('/aio-login-destination/'),
    'aio_login_logo_title' => 'AIO বাংলা brand', 'aio_login_login_page_title' => 'AIO secure entrance',
    'aio_login_el_form_width' => 410, 'aio_login_el_form_min_height' => 320,
    'aio_login_el_form_border_radius' => 17, 'aio_login_el_btn_text_size' => 18,
    'aio_login_el_background_position' => 'left top', 'aio_login_el_background_size' => 'contain',
    'aio_login_el_btn_padding' => '12px 24px', 'aio_login_el_btn_padding_tb' => 12,
    'aio_login_el_form_padding' => '24px', 'aio_login_el_form_border' => '1px solid #123456',
    'aio_login_custom-css' => '.login #login { outline: 2px solid #123456; }',
]);
file_put_contents('/siterepo/.tmp-aio-customizer.json', wp_json_encode($customized));
remove_theme_mod('custom_css_post_id');
$rows = $wpdb->get_results("SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE 'aio\\_login%' OR option_name LIKE 'rwl\\_%' ORDER BY option_name", ARRAY_A);
echo wp_json_encode(['version' => AIO_LOGIN__VERSION, 'pages' => $pages, 'media' => $media,
    'native_writers' => $receipts, 'native_cases' => $nativeCases, 'customizer_keys' => array_keys($customized), 'raw_options' => $rows], JSON_UNESCAPED_SLASHES);

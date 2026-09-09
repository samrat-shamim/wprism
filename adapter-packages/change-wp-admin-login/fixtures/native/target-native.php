<?php
declare(strict_types=1);
wp_set_current_user(1);
$pages = [];
foreach (['login', 'logout'] as $event) {
    $page = get_page_by_path('aio-' . $event . '-destination');
    if (!$page || $page->ID < 801) throw new RuntimeException('target page identities did not diverge');
    $pages[$event] = $page->ID;
}
$images = [];
foreach (['aio_login_logo','aio_login_background_image','aio_login_background_image_mobile','aio_login_favicon','aio_login_forgot_background_image'] as $name) {
    $id = (int) get_option($name);
    if ($id < 801 || get_post_type($id) !== 'attachment' || !is_file(get_attached_file($id))) throw new RuntimeException('target native attachment did not resolve: ' . $name);
    $images[$name] = $id;
}
$user = get_userdata(1);
$login = apply_filters('login_redirect', admin_url(), admin_url(), $user);
$logout = apply_filters('logout_redirect', wp_login_url(), '', $user);
if ($login !== get_permalink($pages['login'])) throw new RuntimeException('native login rule did not consume mapped page');
if ($logout !== home_url('/aio-logout-destination/')) throw new RuntimeException('native logout rule did not consume rebound URL');
if (get_option('aio_login_google_recaptcha_v2_secret_key') !== 'aio-target-secret') throw new RuntimeException('target environment secret was overwritten');
$routes = [];
foreach (rest_get_server()->get_routes() as $route => $handlers) {
    if (!str_starts_with($route, '/aio-login/')) continue;
    $readable = false;
    foreach ($handlers as $handler) if (!empty($handler['methods']['GET'])) $readable = true;
    if (!$readable) continue;
    $reply = rest_do_request(new WP_REST_Request('GET', $route));
    if ($reply->get_status() !== 200) throw new RuntimeException('target native API failed: ' . $route);
    $routes[$route] = $reply->get_data();
}
if (count($routes) !== 32) throw new RuntimeException('target native route census changed');
$limit = $routes['/aio-login/limit-login-attempts/get-settings'];
if ($limit['enabled'] !== true || $limit['maximum_attempts'] !== '3' || $limit['timeout'] !== '7' || $limit['lockout_message'] !== 'AIO test lockout বাংলা') throw new RuntimeException('native limit settings changed');
$enumeration = $routes['/aio-login/dashboard/user-enumeration-settings']['data'];
foreach (['enable_protection','stop_oembed_calls','disable_author_sitemaps','remove_comment_numbers','protect_rest_api','login_registration_errors','log_attempts'] as $name) {
    if ($enumeration[$name] !== 'on') throw new RuntimeException('native enumeration setting changed: ' . $name);
}
if ($enumeration['log_duration'] !== '19') throw new RuntimeException('native activity log retention changed');
wp_set_current_user(0);
if (apply_filters('login_errors', 'Private account detail') !== 'Invalid username or password.') throw new RuntimeException('native error redaction is inactive');
$embed = get_oembed_response_data($pages['login'], 600);
if (!is_array($embed) || array_key_exists('author_name', $embed) || array_key_exists('author_url', $embed)
    || $embed['title'] !== 'AIO login destination' || $embed['type'] !== 'rich' || $embed['width'] !== 600
    || !str_contains($embed['html'], get_permalink($pages['login']))) throw new RuntimeException('native oEmbed protection or public embed content failed');
$args = apply_filters('wp_sitemaps_users_query_args', ['number'=>2000]);
if ($args['number'] !== 0) throw new RuntimeException('native author sitemap protection is inactive');
wp_set_current_user(1);
$otp = AIO_Login\Passwordless_Otp\OTP_Service::create_challenge('email', 'admin@example.test', 1);
if (is_wp_error($otp) || strlen($otp['otp']) !== 8 || $otp['resend_in'] !== 90) throw new RuntimeException('native email OTP did not consume authored options');
$session = AIO_Login\Passwordless_Otp\OTP_Service::get_session($otp['token']);
if (is_wp_error($session) || abs(($session['expires'] - time()) - 780) > 5 || $session['hash'] === $otp['otp']) throw new RuntimeException('native OTP expiry or hash differs');
$verified = AIO_Login\Passwordless_Otp\OTP_Service::verify_challenge($otp['token'], $otp['otp']);
if (is_wp_error($verified) || $verified['user_id'] !== 1 || $verified['channel'] !== 'email') throw new RuntimeException('native OTP verification failed');
$sms = AIO_Login\Passwordless_Otp\OTP_Service::create_challenge('sms', 'unused', 1);
if (!is_wp_error($sms) || $sms->get_error_code() !== 'channel_disabled') throw new RuntimeException('free edition SMS boundary changed');
echo wp_json_encode(['pages'=>$pages,'images'=>$images,'login'=>$login,'logout'=>$logout,'otp'=>['length'=>8,'resend'=>90,'expires_minutes'=>13,'verification'=>true,'sms_disabled'=>true],'target_secret_preserved'=>true,'native_get_routes'=>32,'enumeration_and_rate_settings'=>true,'enumeration_filters'=>true]);

<?php
declare(strict_types=1);
global $wpdb;
wp_set_current_user(1);
$expected = json_decode(file_get_contents('/siterepo/.tmp-aio-customizer.json'), true, 512, JSON_THROW_ON_ERROR);
$observed = [];
foreach ($expected as $key => $value) $observed[$key] = get_option($key, null);
$legacy = get_option('aio_login_elements_settings', []);
foreach ($observed as $key => $value) {
    if (str_starts_with($key, 'aio_login_el_') && (string) ($legacy[substr($key, strlen('aio_login_el_'))] ?? '') !== (string) $value) {
        throw new RuntimeException('Customizer legacy projection differs: ' . $key);
    }
}
foreach (['aio_login_logo','aio_login_background_image','aio_login_background_image_mobile','aio_login_favicon','aio_login_forgot_background_image'] as $key) {
    if ((int) $observed[$key] !== (int) $expected[$key] || !wp_get_attachment_url((int) $observed[$key])) throw new RuntimeException('native media readback failed');
}
if ($observed['aio_login__customization_templates'] !== 'template-8' || $observed['aio_login_logo_title'] !== 'AIO বাংলা brand'
    || (int) $observed['aio_login_el_form_width'] !== 410) throw new RuntimeException('native published design readback failed');
$get = [];
foreach (rest_get_server()->get_routes() as $route => $handlers) {
    if (!str_starts_with($route, '/aio-login/')) continue;
    $readable = false;
    foreach ($handlers as $handler) if (!empty($handler['methods']['GET'])) $readable = true;
    if (!$readable) continue;
    $response = rest_do_request(new WP_REST_Request('GET', $route));
    if ($response->get_status() !== 200) throw new RuntimeException('native GET route failed: ' . $route);
    $get[$route] = $response->get_data();
}
$rows = $wpdb->get_results("SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE 'aio\\_login%' OR option_name LIKE 'rwl\\_%' ORDER BY option_name", ARRAY_A);
echo wp_json_encode(['version' => AIO_LOGIN__VERSION, 'customizer' => $observed, 'legacy' => $legacy, 'get_routes' => $get,
    'raw_options' => $rows, 'login_url' => wp_login_url(), 'logout_url' => wp_logout_url(), 'lostpassword_url' => wp_lostpassword_url()], JSON_UNESCAPED_SLASHES);

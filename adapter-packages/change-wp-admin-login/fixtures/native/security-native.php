<?php
declare(strict_types=1);

use AIO_Login\Helper\Helper;

// The owned documentation-range peer avoids disturbing the HTTP admin session.
// A native geolocation cache value keeps this local rate-limit test independent
// of ipapi.co; remote delivery and proxy configuration remain environment scope.
$_SERVER['REMOTE_ADDR'] = '203.0.113.51';
unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP']);
wp_set_current_user(0);
$ip = Helper::get_ip();
if ($ip !== '203.0.113.51' || Helper::is_ip_blocked() !== false || Helper::get_user_attempt_count() !== 0) {
    throw new RuntimeException('native lockout fixture is not fresh');
}
set_transient('aio_login_ip_location_' . md5($ip), ['country' => 'Owned test', 'city' => 'Local'], DAY_IN_SECONDS);
$errors = [];
for ($attempt = 1; $attempt <= 3; $attempt++) {
    $result = wp_authenticate('admin', 'aio-owned-wrong-password');
    if (!is_wp_error($result)) throw new RuntimeException('wrong password authenticated');
    $errors[] = $result->get_error_codes();
    if (($attempt < 3) !== (Helper::is_ip_blocked() === false)) {
        throw new RuntimeException('native configured three-attempt boundary did not hold');
    }
}
$blocked = wp_authenticate('admin', 'admin');
if (!is_wp_error($blocked) || !in_array('aio_login__blocked', $blocked->get_error_codes(), true)
    || $blocked->get_error_message('aio_login__blocked') !== 'AIO test lockout বাংলা') {
    throw new RuntimeException('native lockout did not reject a correct password with the authored message');
}
$record = Helper::is_ip_blocked();
if (!is_array($record) || !isset($record['time']) || abs(current_time('timestamp') - $record['time']) > 30) {
    throw new RuntimeException('native lockout runtime row is absent or stale');
}
echo wp_json_encode(['attempt_limit' => 3, 'failed_password_errors' => $errors, 'correct_password_blocked' => true,
    'authored_lockout_message' => true, 'runtime_lockout_created' => true, 'remote_geolocation_exercised' => false]);

<?php
declare(strict_types=1);

/**
 * Loginizer native settings-writer fixture. The brute-force settings save
 * (main/settings/brute-force.php:75-134) writes loginizer_options as exactly
 * nine scalar members in one update_option(), and the login-notification save
 * (brute-force.php:56-66) writes loginizer_login_mail as exactly six members;
 * the enable toggle (brute-force.php:41-50) writes loginizer_disable_brute as
 * the whole-row 0/1. All three blocks read $_POST behind
 * check_admin_referer('loginizer-options') and current_user_can(
 * 'manage_options'), reached through the add_menu_page callback
 * loginizer_brute_force_settings() (main/admin.php:739-742).
 *
 * brute-force.php:83-85 refuses trusted_ips unless the operator's own address
 * is whitelisted. The whitelist was seeded by native-options-save-ranges.php
 * as 10.0.0.5-10.0.0.9, and a real admin request carries its REMOTE_ADDR into
 * lz_getip() (common.php:30-47) at boot; in a CLI process the boot already
 * happened with no REMOTE_ADDR, so the plugin's own current-ip global and
 * $_SERVER carry the in-range operator address before the callback runs.
 *
 * The handler multiplies the three duration fields on save
 * (brute-force.php:110-112: minutes->seconds, hours->seconds), so the readback
 * asserts the transformed values the manifest actually captures. The mail
 * body carries home_url('/'), which the capture's home-URL token codec turns
 * into {{home}} — proven offline in regress_package_contract.php.
 */

$check = static function (bool $ok, string $reason): void {
    if (!$ok) {
        throw new RuntimeException('Loginizer native fixture: ' . $reason);
    };
};
$check(current_user_can('manage_options'), 'owned administrator identity');
$check(defined('LOGINIZER_VERSION') && LOGINIZER_VERSION === '2.1.0', 'exact locked plugin release');
$check(function_exists('loginizer_brute_force_settings'), 'admin menu callback is loaded (is_admin context)');

global $loginizer;
$loginizer['current_ip'] = '10.0.0.7';
$_SERVER['REMOTE_ADDR'] = '10.0.0.7';

$nonce = wp_create_nonce('loginizer-options');
$_POST['_wpnonce'] = $nonce;
$_REQUEST['_wpnonce'] = $nonce;

$_POST['enable_brute_lz'] = '1';
$_POST['save_lz'] = '1';
$_POST['max_retries'] = '3';
$_POST['lockout_time'] = '15';
$_POST['max_lockouts'] = '5';
$_POST['lockouts_extend'] = '6';
$_POST['reset_retries'] = '12';
$_POST['notify_email'] = '1';
$_POST['notify_email_address'] = 'admin@example.test';
$_POST['trusted_ips'] = 'on';
$_POST['blocked_screen'] = 'on';
$_POST['save_lz_login_email'] = '1';
$_POST['loginizer_login_mail_enable'] = '1';
$_POST['loginizer_login_mail_disable_whitelist'] = '0';
$_POST['loginizer_notify_html_mail'] = '1';
$_POST['loginizer_login_mail_subject'] = '[$sitename] Failed login attempts';
$_POST['loginizer_login_mail_body'] = '<p>Locked out at ' . home_url('/') . '</p>';
$_POST['loginizer_login_mail_roles'] = ['administrator'];

ob_start();
loginizer_brute_force_settings();
$html = (string) ob_get_clean();

$check(str_contains($html, 'The settings were saved successfully'), 'the brute-force save reported an error instead of success: ' . $html);

$row = static function (string $name) {
    $value = get_option($name);
    return $value === false ? null : $value;
};
$options = $row('loginizer_options');
$mail = $row('loginizer_login_mail');
$toggle = $row('loginizer_disable_brute');

$check(is_array($options) && count($options) === 9, 'loginizer_options did not persist exactly nine members');
$check(($options['max_retries'] ?? null) === 3, 'max_retries did not persist with its native int');
$check(($options['lockout_time'] ?? null) === 900, 'lockout_time was not stored in seconds by the native writer');
$check(($options['max_lockouts'] ?? null) === 5, 'max_lockouts did not persist');
$check(($options['lockouts_extend'] ?? null) === 21600, 'lockouts_extend was not stored in seconds by the native writer');
$check(($options['reset_retries'] ?? null) === 43200, 'reset_retries was not stored in seconds by the native writer');
$check(($options['notify_email'] ?? null) === 1, 'notify_email did not persist');
$check(($options['notify_email_address'] ?? null) === 'admin@example.test', 'notify_email_address did not persist');
$check(($options['trusted_ips'] ?? null) === 'on', 'trusted_ips was refused or dropped by the native writer');
$check(($options['blocked_screen'] ?? null) === 'on', 'blocked_screen did not persist');
$check(is_array($mail) && count($mail) === 6, 'loginizer_login_mail did not persist exactly six members');
$check(($mail['enable'] ?? null) === 1 && ($mail['disable_whitelist'] ?? null) === 0, 'the notification enable flags did not persist');
$check(($mail['html_mail'] ?? null) === true, 'the html_mail flag did not persist as the plugin\'s own boolean');
$check(($mail['subject'] ?? null) === '[$sitename] Failed login attempts', 'the notification subject did not persist');
$check(is_string($mail['body'] ?? null) && str_contains((string) $mail['body'], home_url('/')), 'the notification body does not carry this environment\'s home URL');
$check(($mail['roles'] ?? null) === ['administrator'], 'the notification role list did not persist');
$check($toggle === 0, 'the enable toggle did not persist the whole-row 0');

echo wp_json_encode([
    'disable_brute' => $toggle,
    'login_mail' => $mail,
    'options' => $options,
]), "\n";

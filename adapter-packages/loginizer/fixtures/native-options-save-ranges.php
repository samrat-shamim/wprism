<?php
declare(strict_types=1);

/**
 * Loginizer native IP-range writer fixture. The whitelist/blacklist writers
 * are admin POST blocks inside loginizer_page_brute_force()
 * (main/settings/brute-force.php:253-330), reached in a real request through
 * the add_menu_page callback loginizer_brute_force_settings()
 * (main/admin.php:739-742). This fixture reproduces exactly that context —
 * authenticated administrator, valid 'loginizer-options' nonce, populated
 * $_POST — and invokes the callback itself, so the saves that run are the
 * plugin's own, not a copy.
 */

$check = static function (bool $ok, string $reason): void {
    if (!$ok) {
        throw new RuntimeException('Loginizer native fixture: ' . $reason);
    };
};
$check(current_user_can('manage_options'), 'owned administrator identity');
$check(defined('LOGINIZER_VERSION') && LOGINIZER_VERSION === '2.1.0', 'exact locked plugin release');
$check(function_exists('loginizer_brute_force_settings'), 'admin menu callback is loaded (is_admin context)');

$nonce = wp_create_nonce('loginizer-options');
$_POST['_wpnonce'] = $nonce;
$_REQUEST['_wpnonce'] = $nonce;

$_POST['whitelist_iprange'] = '1';
$_POST['start_ip_w'] = '10.0.0.5';
$_POST['end_ip_w'] = '10.0.0.9';
$_POST['blacklist_iprange'] = '1';
$_POST['start_ip'] = '192.168.7.7';
$_POST['end_ip'] = '192.168.7.7';

ob_start();
loginizer_brute_force_settings();
$html = (string) ob_get_clean();

$check(str_contains($html, 'Whitelist IP range added successfully'), 'the whitelist writer did not report success');
$check(str_contains($html, 'Blacklist IP range added successfully'), 'the blacklist writer did not report success');

$row = static function (string $name) {
    $value = get_option($name);
    return $value === false ? null : $value;
};
$whitelist = $row('loginizer_whitelist');
$blacklist = $row('loginizer_blacklist');
$check(is_array($whitelist) && ($whitelist[1]['start'] ?? null) === '10.0.0.5' && ($whitelist[1]['end'] ?? null) === '10.0.0.9', 'the whitelisted operator range did not persist');
$check(is_array($blacklist) && ($blacklist[1]['start'] ?? null) === '192.168.7.7', 'the blacklisted range did not persist');

echo wp_json_encode([
    'blacklist' => $blacklist,
    'whitelist' => $whitelist,
]), "\n";

<?php
declare(strict_types=1);

/**
 * Loginizer native writer fixture. The brute-force settings page's save
 * handlers are admin POST blocks inside loginizer_page_brute_force()
 * (main/settings/brute-force.php:23-134), reached in a real request through
 * the add_menu_page callback loginizer_brute_force_settings()
 * (main/admin.php:739-742). This fixture reproduces exactly that context —
 * authenticated administrator, valid 'loginizer-options' nonce, populated
 * $_POST, and the operator's REMOTE_ADDR inside the whitelisted range the
 * trusted-ips check (brute-force.php:83-85) demands — and invokes the
 * callback itself, so the saves that run are the plugin's own, not a copy.
 *
 * Phases:
 *   save-ranges    whitelist_iprange + blacklist_iprange posts
 *   save-settings  save_lz (nine members) + save_lz_login_email (six members)
 *   verify-capture row readback plus canonical authored/withheld assertions
 *
 * The handler multiplies the three duration fields on save
 * (brute-force.php:110-112: minutes->seconds, hours->seconds), so the readback
 * asserts the transformed values the manifest actually captures.
 */

$phase = $args[0] ?? '';
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

/**
 * Invoke the native menu callback with the current $_POST and return its
 * rendered page, so the success/error markers the page prints are the proof
 * of which branch ran.
 */
$invokePage = static function (): string {
    ob_start();
    loginizer_brute_force_settings();
    $html = (string) ob_get_clean();
    return $html;
};

$optionRow = static function (string $name): ?array {
    global $wpdb;
    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name),
        ARRAY_A
    );
    return $row === null ? null : [
        'autoload' => $row['autoload'],
        'value' => maybe_unserialize($row['option_value']),
    ];
};

if ($phase === 'save-ranges') {
    $_POST['whitelist_iprange'] = '1';
    $_POST['start_ip_w'] = '10.0.0.5';
    $_POST['end_ip_w'] = '10.0.0.9';
    $_POST['blacklist_iprange'] = '1';
    $_POST['start_ip'] = '192.168.7.7';
    $_POST['end_ip'] = '192.168.7.7';
    $html = $invokePage();
    $check(str_contains($html, 'Whitelist IP range added successfully'), 'the whitelist writer did not report success');
    $check(str_contains($html, 'Blacklist IP range added successfully'), 'the blacklist writer did not report success');
    $whitelist = $optionRow('loginizer_whitelist');
    $blacklist = $optionRow('loginizer_blacklist');
    $check(is_array($whitelist) && isset($whitelist['value'][1]['start']) && $whitelist['value'][1]['start'] === '10.0.0.5', 'whitelist range did not persist');
    $check(is_array($blacklist) && isset($blacklist['value'][1]['start']) && $blacklist['value'][1]['start'] === '192.168.7.7', 'blacklist range did not persist');
    echo wp_json_encode([
        'blacklist' => $blacklist['value'],
        'whitelist' => $whitelist['value'],
    ]), "\n";
    return;
}

if ($phase === 'save-settings') {
    // brute-force.php:83-85 refuses trusted_ips unless the operator's own
    // address is whitelisted; a real admin request carries REMOTE_ADDR, so
    // the fixture carries the address inside the range it just seeded.
    $_SERVER['REMOTE_ADDR'] = '10.0.0.7';
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
    $html = $invokePage();
    $check(str_contains($html, 'The settings were saved successfully'), 'the brute-force save reported an error instead of success');
    $settings = $optionRow('loginizer_options');
    $mail = $optionRow('loginizer_login_mail');
    $check(is_array($settings), 'loginizer_options has no row after the native save');
    $check(is_array($mail), 'loginizer_login_mail has no row after the native save');
    $check(($settings['value']['lockout_time'] ?? 0) === 900, 'lockout_time was not stored in seconds by the native writer');
    $check(($settings['value']['lockouts_extend'] ?? 0) === 21600, 'lockouts_extend was not stored in seconds by the native writer');
    $check(($settings['value']['reset_retries'] ?? 0) === 43200, 'reset_retries was not stored in seconds by the native writer');
    $check(($settings['value']['trusted_ips'] ?? '') === 'on', 'trusted_ips was refused or dropped by the native writer');
    $check(isset($mail['value']['body']) && is_string($mail['value']['body']) && $mail['value']['body'] !== '', 'the notification template body did not persist');
    echo wp_json_encode([
        'login_mail' => $mail['value'],
        'options' => $settings['value'],
    ]), "\n";
    return;
}

if ($phase === 'verify-capture') {
    $settings = $optionRow('loginizer_options');
    $mail = $optionRow('loginizer_login_mail');
    $whitelist = $optionRow('loginizer_whitelist');
    $blacklist = $optionRow('loginizer_blacklist');
    $toggle = $optionRow('loginizer_disable_brute');
    $markers = [];
    foreach (['loginizer_last_reset', 'loginizer_version', 'loginizer_ins_time', 'loginizer_msg', 'loginizer_captcha'] as $marker) {
        $markers[$marker] = $optionRow($marker);
    }
    $check(is_array($settings) && is_array($mail) && is_array($whitelist) && is_array($blacklist), 'native option rows are missing at verification time');

    // The canonical half: capture must have carried the authored rows and
    // left every runtime and undeclared family out of state.
    $canonicalPath = '/siterepo/state/options/core.json';
    $check(is_file($canonicalPath), 'no canonical options document was published');
    $canonical = (string) file_get_contents($canonicalPath);
    foreach (['loginizer_options', 'loginizer_login_mail', 'loginizer_whitelist', 'loginizer_blacklist'] as $authored) {
        $check(str_contains($canonical, '"' . $authored . '"'), "authored option $authored is absent from the canonical document");
    }
    foreach (array_keys($markers) as $name) {
        $check(!str_contains($canonical, '"' . $name . '"'), "excluded option $name reached the canonical document");
    }

    echo wp_json_encode([
        'authored_rows' => [
            'loginizer_blacklist' => $blacklist['value'],
            'loginizer_disable_brute' => $toggle === null ? null : $toggle['value'],
            'loginizer_login_mail' => $mail['value'],
            'loginizer_options' => $settings['value'],
            'loginizer_whitelist' => $whitelist['value'],
        ],
        'markers_present' => array_map(static fn(?array $row): bool => $row !== null, $markers),
    ]), "\n";
    return;
}

throw new RuntimeException('Loginizer native fixture: unknown phase ' . $phase);

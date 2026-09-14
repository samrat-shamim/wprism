<?php
declare(strict_types=1);

/**
 * Loginizer runtime observation. A pure readback — no assertions, no writes —
 * so the same fixture serves the pre-apply hostile premise, every post-apply
 * convergence check, the lifecycle recovery checks and the exact-boundary
 * version matrix. It reports four layers the byte-diff cannot see:
 *
 *  - the raw wp_options rows for every owned family plus the undeclared
 *    neighbour probe;
 *  - the plugin's own effective configuration: the $loginizer globals
 *    init.php:255-320 assembles at boot from those rows (a fresh process per
 *    observation, so a converged target is proven through the plugin's real
 *    read path, not a stale in-process copy);
 *  - the plugin's own access decisions: loginizer_is_whitelisted() /
 *    loginizer_is_blacklisted() (common.php:119, init.php:490) probed with
 *    the in-range operator address, the blacklisted address and a neutral
 *    address, by setting the plugin's current-ip global the way a request's
 *    REMOTE_ADDR would have;
 *  - the runtime loginizer_logs table (existence and row count) that the
 *    runtime classification must leave untouched, and whether the
 *    notification body carries this environment's own home URL.
 */

global $loginizer, $wpdb;

$row = static function (string $name) {
    $value = get_option($name);
    return $value === false ? null : $value;
};

$probe = static function (string $ip): array {
    global $loginizer;
    $loginizer['current_ip'] = $ip;
    return [
        'blacklisted' => (bool) loginizer_is_blacklisted(),
        'ip' => $ip,
        'whitelisted' => (bool) loginizer_is_whitelisted(),
    ];
};

$mail = $row('loginizer_login_mail');
$body = is_array($mail) && is_string($mail['body'] ?? null) ? (string) $mail['body'] : '';

$logsTable = $wpdb->prefix . 'loginizer_logs';
$logsExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $logsTable)) === $logsTable;

echo wp_json_encode([
    'access' => [
        'blacklisted_member' => $probe('192.168.7.7'),
        'neutral' => $probe('8.8.4.4'),
        'whitelisted_member' => $probe('10.0.0.7'),
    ],
    'body_carries_home' => $body !== '' && str_contains($body, home_url('/')),
    'effective' => [
        'disable_brute' => $loginizer['disable_brute'] ?? null,
        'lockout_time' => $loginizer['lockout_time'] ?? null,
        'lockouts_extend' => $loginizer['lockouts_extend'] ?? null,
        'max_lockouts' => $loginizer['max_lockouts'] ?? null,
        'max_retries' => $loginizer['max_retries'] ?? null,
        'notify_email' => $loginizer['notify_email'] ?? null,
        'notify_email_address' => $loginizer['notify_email_address'] ?? null,
        'reset_retries' => $loginizer['reset_retries'] ?? null,
        'trusted_ips' => !empty($loginizer['trusted_ips']),
    ],
    'logs' => [
        'exists' => $logsExists,
        'rows' => $logsExists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$logsTable}`") : null,
    ],
    'rows' => [
        'blacklist' => $row('loginizer_blacklist'),
        'disable_brute' => $row('loginizer_disable_brute'),
        'login_mail' => $mail,
        'neighbor' => $row('loginizer_target_probe'),
        'options' => $row('loginizer_options'),
        'whitelist' => $row('loginizer_whitelist'),
    ],
    'version' => defined('LOGINIZER_VERSION') ? LOGINIZER_VERSION : null,
]), "\n";

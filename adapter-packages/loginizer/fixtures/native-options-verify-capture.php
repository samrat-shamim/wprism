<?php
declare(strict_types=1);

/**
 * Loginizer source-side capture verification. Asserts both halves at once:
 * the native rows the plugin's own writers produced are still intact, and the
 * published canonical document carries every authored family — with the mail
 * body carried through the home-URL token codec — while every runtime marker
 * and every undeclared option stays out of state.
 *
 * Marker presence is split by guarantee: loginizer_version
 * (init.php:213), loginizer_last_reset (activation, init.php:44) and
 * loginizer_ins_time (self-materializes on the first boot, init.php:308-312)
 * exist on every activated install, so their absence means the premise broke.
 * loginizer_msg and loginizer_captcha exist only after a screen message or a
 * captcha save, so they are checked for exclusion only — requiring their
 * presence would assert a premise the plugin does not create.
 */

$check = static function (bool $ok, string $reason): void {
    if (!$ok) {
        throw new RuntimeException('Loginizer native fixture: ' . $reason);
    };
};
$check(defined('LOGINIZER_VERSION') && LOGINIZER_VERSION === '2.1.0', 'exact locked plugin release');

$row = static function (string $name) {
    $value = get_option($name);
    return $value === false ? null : $value;
};
$options = $row('loginizer_options');
$mail = $row('loginizer_login_mail');
$whitelist = $row('loginizer_whitelist');
$blacklist = $row('loginizer_blacklist');
$toggle = $row('loginizer_disable_brute');
$check(is_array($options) && is_array($mail) && is_array($whitelist) && is_array($blacklist), 'a native authored row went missing before capture verification');

$markers = [];
foreach (['loginizer_last_reset', 'loginizer_version', 'loginizer_ins_time', 'loginizer_msg', 'loginizer_captcha'] as $marker) {
    $markers[$marker] = $row($marker);
}
$check($markers['loginizer_version'] === '2.1.0', 'the version marker row is the premise the runtime classification excludes');
$check($markers['loginizer_last_reset'] !== null, 'the reset marker row is the premise the runtime classification excludes');
$check($markers['loginizer_ins_time'] !== null, 'the install-time marker row is the premise the runtime classification excludes');

$canonicalPath = '/siterepo/state/options/core.json';
$check(is_file($canonicalPath), 'no canonical options document was published');
$canonical = json_decode((string) file_get_contents($canonicalPath), true, 512, JSON_THROW_ON_ERROR);
$records = $canonical['records'] ?? [];
$check(is_array($records), 'the canonical options document has no records object');
foreach (['loginizer_options', 'loginizer_login_mail', 'loginizer_whitelist', 'loginizer_blacklist', 'loginizer_disable_brute'] as $authored) {
    $check(array_key_exists($authored, $records), "authored option $authored is absent from the canonical document");
}
foreach (array_keys($markers) as $name) {
    $check(!array_key_exists($name, $records), "excluded option $name reached the canonical document");
}
$check(
    is_string(($records['loginizer_login_mail']['value']['body'] ?? null))
        && str_contains((string) $records['loginizer_login_mail']['value']['body'], '{{home}}'),
    'the canonical notification body did not capture through the home-URL token codec'
);
$check(
    ($records['loginizer_whitelist']['state'] ?? null) === 'present'
        && is_array($records['loginizer_whitelist']['value'] ?? null)
        && count($records['loginizer_whitelist']['value']) === 1,
    'the canonical whitelist is not the one persisted IP-range record'
);

echo wp_json_encode([
    'authored_rows' => [
        'loginizer_blacklist' => $blacklist,
        'loginizer_disable_brute' => $toggle,
        'loginizer_login_mail' => $mail,
        'loginizer_options' => $options,
        'loginizer_whitelist' => $whitelist,
    ],
    'markers_present' => [
        'loginizer_ins_time' => $markers['loginizer_ins_time'] !== null,
        'loginizer_last_reset' => $markers['loginizer_last_reset'] !== null,
        'loginizer_version' => $markers['loginizer_version'] !== null,
    ],
]), "\n";

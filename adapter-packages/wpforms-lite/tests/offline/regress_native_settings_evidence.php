<?php
declare(strict_types=1);

// Mutation tests for actual admission code, not fabricated native run evidence.
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 4) . '/agent/src/Kernel/OptionState.php';
require_once dirname(__DIR__, 2) . '/fixtures/location-provider/settings-evidence.php';
use WPrism\OptionState;

$html = static function (string $view): string {
    $body = '<html><body><form class="wpforms-admin-settings-form" method="post">'
        . '<input type="hidden" name="action" value="update-settings"><input type="hidden" name="view" value="' . $view . '">'
        . '<input type="hidden" name="nonce" value="123456abcd"><button type="submit" name="wpforms-settings-submit">Save</button>';
    foreach (WPFormsSettingsEvidence::values('source', $view) as $key => $_) {
        $identity = ' id="wpforms-setting-' . $key . '" name="' . $key . '"';
        $body .= $key === 'disable-css' ? '<select' . $identity . '><option value="1">Full</option><option value="2">Base</option><option value="3">None</option></select>'
            : '<input type="' . ($view === 'general' ? 'checkbox' : 'text') . '"' . $identity . '>';
    }
    if ($view === 'general') foreach (['gdpr-disable-uuid', 'gdpr-disable-details'] as $key) {
        $body .= '<input type="checkbox" name="' . $key . '" disabled>';
    }
    return $body . '</form><p>Settings were successfully saved.</p></body></html>';
};
$stored = static function (array $values, string $crypto = 's'): array {
    return ['values' => $values, 'rows' => [
        ['option_id' => '12', 'option_name' => 'wpforms_settings', 'option_value' => serialize($values), 'autoload' => 'auto'],
        ['option_id' => '13', 'option_name' => 'wpforms_crypto_secret_key', 'option_value' => base64_encode(str_repeat($crypto, 32)), 'autoload' => 'auto'],
    ]];
};
$author = static function (string $side, string $view) use ($html, $stored): array {
    $number = $side === 'source' ? 1 : 2;
    $before = ['modern-markup' => '1', 'modern-markup-is-set' => true, 'modern-markup-hide-setting' => true];
    $after = array_replace($before, WPFormsSettingsEvidence::values($side, $view));
    if ($view === 'general') $after += ['gdpr-disable-uuid' => false, 'gdpr-disable-details' => false];
    $requests = [];
    foreach (['GET', 'POST'] as $method) $requests[] = ['url' => 'http://wprism-wpfsettings-wp' . $number . '-1/wp-admin/admin.php?page=wpforms-settings&view=' . $view,
        'host' => 'wpfsettings' . $number . '.invalid', 'method' => $method, 'status' => 200, 'headers' => [], 'body' => $html($view),
        'post' => $method === 'GET' ? [] : WPFormsSettingsEvidence::post($side, $view, '123456abcd')];
    return ['format' => 'wprism-wpforms-native-settings-author/v1', 'version' => '2.0.1.1', 'side' => $side, 'view' => $view,
        'home' => 'http://wpfsettings' . $number . '.invalid', 'actor' => 'admin', 'session_retired' => true,
        'debug_config' => [true, true, false], 'diagnostics_before' => ['present' => false, 'bytes' => ''],
        'diagnostics_after' => ['present' => false, 'bytes' => ''], 'requests' => $requests,
        'before' => $stored($before), 'after' => $stored($after)];
};
foreach (['source', 'target'] as $side) foreach (['general', 'validation'] as $view) {
    WPFormsSettingsEvidence::author($author($side, $view), 'wpfsettings', $side, $view);
    wprism_check(true, "$side $view exact native form, request and persisted-value admission");
}
$positive = $author('source', 'general');
foreach ([
    'wrong edition version' => static function (&$r): void { $r['version'] = '2.0.0.4'; },
    'foreign source' => static function (&$r): void { $r['home'] = 'http://foreign.invalid'; },
    'foreign transport' => static function (&$r): void { $r['requests'][1]['url'] = str_replace('wpfsettings', 'foreign', $r['requests'][1]['url']); },
    'wrong HTTP host' => static function (&$r): void { $r['requests'][1]['host'] = 'foreign.invalid'; },
    'wrong actor' => static function (&$r): void { $r['actor'] = 'subscriber'; },
    'unretired session' => static function (&$r): void { $r['session_retired'] = false; },
    'missing HTTP exchange' => static function (&$r): void { array_pop($r['requests']); },
    'redirect' => static function (&$r): void { $r['requests'][1]['status'] = 302; },
    'wrong nonce' => static function (&$r): void { $r['requests'][1]['post']['nonce'] = 'abcdef1234'; },
    'forged disabled input' => static function (&$r): void { $r['requests'][1]['post']['gdpr-disable-uuid'] = '1'; },
    'unrelated submitted field' => static function (&$r): void { $r['requests'][1]['post']['license-key'] = 'fixture'; },
    'wrong response view' => static function (&$r): void { $r['requests'][1]['body'] = str_replace('value="general"', 'value="validation"', $r['requests'][1]['body']); },
    'disabled authored control' => static function (&$r): void { $r['requests'][0]['body'] = str_replace('name="gdpr"', 'name="gdpr" disabled', $r['requests'][0]['body']); },
    'enabled Pro control' => static function (&$r): void { $r['requests'][0]['body'] = str_replace('name="gdpr-disable-uuid" disabled', 'name="gdpr-disable-uuid"', $r['requests'][0]['body']); },
    'invented enabled cloud enrollment' => static function (&$r): void { $r['requests'][0]['body'] = str_replace('</form>', '<input name="lite-connect-enabled"></form>', $r['requests'][0]['body']); },
    'invented disabled cloud enrollment' => static function (&$r): void { $r['requests'][0]['body'] = str_replace('</form>', '<input name="lite-connect-enabled" disabled></form>', $r['requests'][0]['body']); },
    'invented markup UI' => static function (&$r): void { $r['requests'][0]['body'] = str_replace('</form>', '<input name="modern-markup"></form>', $r['requests'][0]['body']); },
    'duplicate nonce' => static function (&$r): void { $r['requests'][0]['body'] = str_replace('</form>', '<input name="nonce" value="123456abcd"></form>', $r['requests'][0]['body']); },
    'missing success notice' => static function (&$r): void { $r['requests'][1]['body'] = str_replace('Settings were successfully saved.', '', $r['requests'][1]['body']); },
    'native PHP diagnostic' => static function (&$r): void { $r['requests'][1]['body'] .= '<b>Warning</b>: native request failed'; },
    'server diagnostics disabled' => static function (&$r): void { $r['debug_config'][0] = false; },
    'new server diagnostic' => static function (&$r): void { $r['diagnostics_after'] = ['present' => true, 'bytes' => 'PHP Warning: unexpected']; },
    'preserved preexisting diagnostic' => static function (&$r): void { $r['diagnostics_before'] = $r['diagnostics_after'] = ['present' => true, 'bytes' => 'PHP Warning: unexpected']; },
    'wrong stored toggle type' => static function (&$r) use ($stored): void { $r['after'] = $stored(array_replace($r['after']['values'], ['gdpr' => '1'])); },
    'unrelated native setting changed' => static function (&$r) use ($stored): void { $r['after'] = $stored(array_replace($r['after']['values'], ['modern-markup-hide-setting' => false])); },
    'native crypto changed' => static function (&$r): void { $r['after']['rows'][1]['option_value'] = base64_encode(str_repeat('x', 32)); },
    'native settings identity changed' => static function (&$r): void { $r['after']['rows'][0]['option_id'] = '44'; },
    'missing physical column' => static function (&$r): void { unset($r['after']['rows'][0]['autoload']); },
    'extra physical column' => static function (&$r): void { $r['after']['rows'][0]['extra'] = 'unproved'; },
    'extra physical row' => static function (&$r): void { $r['after']['rows'][] = $r['after']['rows'][0]; },
    'hidden transport failure' => static function (&$r): void { $r['transport_error'] = ['codes' => ['http_request_failed']]; },
    'undeclared request data' => static function (&$r): void { $r['requests'][1]['unexpected'] = 'hidden'; },
] as $label => $mutate) {
    $record = $positive;
    $mutate($record);
    wprism_check_throws(static fn() => WPFormsSettingsEvidence::author($record, 'wpfsettings', 'source', 'general'), RuntimeException::class,
        'native author admission refuses ' . $label, 'WPForms settings evidence:');
}

$sourceValues = ['modern-markup' => '1'] + WPFormsSettingsEvidence::values('source', 'general') + WPFormsSettingsEvidence::values('source', 'validation');
$targetValues = ['modern-markup' => '1', 'gdpr-disable-uuid' => true, 'gdpr-disable-details' => true,
    'opaque' => ['secret' => 'sk_live_NATIVELOCAL123456789012', 'nested' => [false, null, '0']]]
    + WPFormsSettingsEvidence::values('target', 'general') + WPFormsSettingsEvidence::values('target', 'validation');
$source = ['settings' => $stored($sourceValues), 'settings_diagnostics' => ['present' => false, 'bytes' => '']];
$before = ['settings' => $stored($targetValues, 't'), 'settings_diagnostics' => ['present' => false, 'bytes' => '']];
$strings = [];
foreach (WPFormsSettingsEvidence::VALIDATION as $key => $consumer) $strings[$consumer] = $sourceValues[$key];
$after = ['settings' => $stored(array_replace($targetValues, $sourceValues), 't'),
    'settings_diagnostics' => ['present' => false, 'bytes' => ''],
    'settings_consumers' => ['strings' => $strings, 'global_assets' => true, 'render_engine' => 'modern',
        'styles' => ['wpforms-choicesjs', 'wpforms-modern-base'], 'ip_allowed' => false, 'cookies_allowed' => false]];
$compiled = OptionState::document(['wpforms_settings' => OptionState::present($sourceValues, 'auto')]);
WPFormsSettingsEvidence::native($source, $before, $after, $after, $compiled);
wprism_check(true, 'actual native Apply settings admission accepts complete source-bound merge and consumers');
foreach ([
    'wrong compiled input' => static function (&$s, &$b, &$a, &$r, &$c): void { $values = OptionState::values($c)['wpforms_settings']; $values['gdpr-disable-uuid'] = false; $c = OptionState::document(['wpforms_settings' => OptionState::present($values, 'auto')]); },
    'wrong source value' => static function (&$s, &$b, &$a, &$r, &$c) use ($stored): void { $s['settings'] = $stored(array_replace($s['settings']['values'], ['validation-required' => 'wrong'])); },
    'local residue overwritten' => static function (&$s, &$b, &$a, &$r, &$c) use ($stored): void { $a['settings'] = $stored(array_replace($a['settings']['values'], ['gdpr-disable-uuid' => false]), 't'); },
    'unknown nested value lost' => static function (&$s, &$b, &$a, &$r, &$c) use ($stored): void { $a['settings'] = $stored(array_diff_key($a['settings']['values'], ['opaque' => true]), 't'); },
    'secret replaced' => static function (&$s, &$b, &$a, &$r, &$c): void { $a['settings']['rows'][1] = $s['settings']['rows'][1]; },
    'retry mutation' => static function (&$s, &$b, &$a, &$r, &$c): void { $r['settings']['rows'][0]['autoload'] = 'no'; },
    'native consumer missing' => static function (&$s, &$b, &$a, &$r, &$c): void { unset($a['settings_consumers']['strings']['val_creditcard']); },
    'native stylesheet wrong' => static function (&$s, &$b, &$a, &$r, &$c): void { $a['settings_consumers']['styles'] = ['wpforms-no-styles']; },
    'global field stylesheet missing' => static function (&$s, &$b, &$a, &$r, &$c): void { $a['settings_consumers']['styles'] = ['wpforms-modern-base']; },
    'native privacy wrong' => static function (&$s, &$b, &$a, &$r, &$c): void { $a['settings_consumers']['ip_allowed'] = true; },
    'autoload changed' => static function (&$s, &$b, &$a, &$r, &$c): void { $a['settings']['rows'][0]['autoload'] = 'no'; $r = $a; },
    'consumer server warning' => static function (&$s, &$b, &$a, &$r, &$c): void { $r['settings_diagnostics'] = ['present' => true, 'bytes' => 'PHP Notice: hidden']; },
] as $label => $mutate) {
    [$s, $b, $a, $r, $c] = [$source, $before, $after, $after, $compiled];
    $mutate($s, $b, $a, $r, $c);
    wprism_check_throws(static fn() => WPFormsSettingsEvidence::native($s, $b, $a, $r, $c), RuntimeException::class,
        'native settings Apply admission refuses ' . $label, 'WPForms settings evidence:');
}
$nativeTarget = ['modern-markup' => '1', 'modern-markup-is-set' => true, 'modern-markup-hide-setting' => true,
    'lite-connect-enabled' => false, 'gdpr-disable-uuid' => false, 'gdpr-disable-details' => false]
    + WPFormsSettingsEvidence::values('target', 'general') + WPFormsSettingsEvidence::values('target', 'validation');
$residue = ['gdpr-disable-uuid' => true, 'gdpr-disable-details' => true,
    'modern-markup-is-set' => 'target-initialized', 'modern-markup-hide-setting' => false, 'lite-connect-enabled' => '0',
    'license-key' => 'sk_live_LOCALFIXTURE123456789012', 'integrations-providers' => ['customer_email' => 'private@example.test'],
    'future-setting' => ['nested' => [null, false, 0, '0', '日本語 Ω']]];
$local = ['format' => 'wprism-wpforms-native-local-settings/v1', 'before' => $stored($nativeTarget, 't'),
    'after' => $stored(array_replace($nativeTarget, $residue), 't')];
$initial = ['settings' => $local['after'], 'settings_consumers' => ['global_assets' => false,
    'styles' => ['wpforms-no-styles'], 'ip_allowed' => true, 'cookies_allowed' => true]];
$sourceAuthor = ['after' => $source['settings']];
$targetAuthor = ['after' => $local['before']];
WPFormsSettingsEvidence::initial($sourceAuthor, $targetAuthor, $local, $source, $initial);
wprism_check(true, 'initial admission binds both native author records and the exact synthetic target setup');
foreach ([
    'unrelated source author' => static function (&$s, &$t, &$l, &$i): void { $s['after']['rows'][0]['option_id'] = '99'; },
    'unrelated target author' => static function (&$s, &$t, &$l, &$i): void { $t['after']['rows'][0]['option_id'] = '99'; },
    'missing local credential witness' => static function (&$s, &$t, &$l, &$i) use ($stored): void { $l['after'] = $stored(array_diff_key($l['after']['values'], ['license-key' => true]), 't'); $i['settings'] = $l['after']; },
    'extra setup mutation' => static function (&$s, &$t, &$l, &$i) use ($stored): void { $l['after'] = $stored($l['after']['values'] + ['unexpected' => true], 't'); $i['settings'] = $l['after']; },
    'nondiscriminating target' => static function (&$s, &$t, &$l, &$i) use ($stored): void { $l['after'] = $stored(array_replace($l['after']['values'], ['disable-css' => '2']), 't'); $i['settings'] = $l['after']; },
    'wrong initial consumer' => static function (&$s, &$t, &$l, &$i): void { $i['settings_consumers']['cookies_allowed'] = false; },
] as $label => $mutate) {
    [$s, $t, $l, $i] = [$sourceAuthor, $targetAuthor, $local, $initial];
    $mutate($s, $t, $l, $i);
    wprism_check_throws(static fn() => WPFormsSettingsEvidence::initial($s, $t, $l, $source, $i), RuntimeException::class,
        'initial settings admission refuses ' . $label, 'WPForms settings evidence:');
}
// The pair mounts capsules next to the drop-in's src/, not a host agent/.
// A fresh child must load and exercise the actual native assertion closure
// without accidentally finding host-only dependencies already in this process.
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/ShellProbe.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/frozen_policy.php';
$mounted = WPrismTest\FrozenPolicy::library() . '/mu-plugins/adapter-packages/wpforms-lite/fixtures/location-provider';
if (!mkdir($mounted, 0700, true)) throw new RuntimeException('cannot create native fixture mount');
foreach (['native-settings.php', 'settings-evidence.php', 'native-admin.php'] as $file) {
    if (!copy(dirname(__DIR__, 2) . '/fixtures/location-provider/' . $file, $mounted . '/' . $file)) {
        throw new RuntimeException('cannot copy actual native fixture');
    }
}
$script = <<<'SH'
exec "$1" -d display_errors=stderr -r 'require $argv[1]; WPFormsSettingsEvidence::author(json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR), "wpfsettings", "source", "general"); if (class_exists("WPrism\\Canon", false) || class_exists("WPrism\\OptionState", false)) throw new RuntimeException("native assertion loaded host compiler dependencies"); echo "MOUNTED_NATIVE_ASSERTIONS_READY\n";' "$2" "$3"
SH;
[$status, $stdout, $stderr] = WPrismTest\ShellProbe::run($script,
    [PHP_BINARY, $mounted . '/native-settings.php', json_encode($positive, JSON_THROW_ON_ERROR)], dirname(__DIR__, 4));
wprism_check_same(0, $status, 'actual native settings helper loads from the capsule-only mounted layout');
wprism_check_same("MOUNTED_NATIVE_ASSERTIONS_READY\n", $stdout, 'mounted native author admission needs no host compiler classes');
wprism_check_same('', $stderr, 'mounted native assertion closure has no hidden load diagnostics');
wprism_check_summary('regress_wpforms_lite_native_settings_evidence');

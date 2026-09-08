<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $root . '/sandbox/tests/support/wp-block-parser-stub.php';
require_once $root . '/sandbox/tests/support/wp-shortcode-stub.php';
require_once $root . '/agent/src/Policy/AdapterLibrary.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Capture/OptionsCapture.php';
require_once $root . '/agent/src/Capture/CaptureSafetyGates.php';
require_once $root . '/agent/src/Grammar/Blocks.php';
require_once $root . '/agent/src/Grammar/Shortcodes.php';
require_once $root . '/agent/src/Repository/Snapshot.php';
require_once $root . '/agent/src/Repository/SidebarState.php';

use WPrism\AdapterLibrary;
use WPrism\Blocks;
use WPrism\Canon;
use WPrism\CaptureSafetyGates;
use WPrism\OptionsCapture;
use WPrism\OptionState;
use WPrism\Policy;
use WPrism\Shortcodes;
use WPrism\SidebarState;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

wprism_test_define_agent_versions();
WpStore::reset();
$wpdb = new FakeWpdb();
$policy = Policy::load(null, ['core', 'wpforms-lite'], adapterLibrary: AdapterLibrary::fromSourcePackage($root, 'wpforms-lite'));
$fixture = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/native-authoring.json'), true, 512, JSON_THROW_ON_ERROR);
$settings = $fixture['settings'];
// Synthetic hostile siblings surround exact native general/validation values.
// Capturing a whole settings blob would either leak them or incorrectly refuse
// the valid authored subkeys; both are wrong for the shared mixed-option path.
$settings['license-key'] = 'sk_live_EXCLUDEDLICENSE1234567890';
$settings['integrations-providers'] = ['customer_email' => 'private@example.test'];
$settings['unreviewed-setting'] = 'target-local';
$rows = [
    ['option_id' => 1, 'option_name' => 'wpforms_settings', 'option_value' => serialize($settings), 'autoload' => 'yes'],
    ['option_id' => 2, 'option_name' => 'wpforms_crypto_secret_key', 'option_value' => 'sk_live_EXCLUDEDCRYPTO1234567890', 'autoload' => 'no'],
    ['option_id' => 3, 'option_name' => 'wpforms_forms_first_created', 'option_value' => '1750000000', 'autoload' => 'no'],
];
$wpdb->seedTable('wp_options', $rows)->seedTable('wp_wprism_map', []);
$tokens = new Tokens('https://source.example.test', 'https://source.example.test/wp-content/uploads');
$tokens->policy = $policy;
$gates = new CaptureSafetyGates('/fixture/wpforms');
$producer = new OptionsCapture($policy, $tokens,
    static function (string $section, string $key, mixed $value, array $rule) use ($gates): void {
        $gates->guardSecret($section, $key, $value, $rule);
        $gates->guardPersonalData($section, $key, $value, $rule);
    }, static function (): never { throw new LogicException('settings fixtures carry no entity reference'); },
    static function (): never { throw new LogicException('settings fixtures require no entity lookup'); });
$captured = $producer->capture(false);
$expected = array_diff_key($fixture['settings'], array_flip(['modern-markup-is-set', 'modern-markup-hide-setting',
    'lite-connect-enabled', 'gdpr-disable-uuid', 'gdpr-disable-details']));
wprism_check_same(Canon::encode(['wpforms_settings' => $expected]), Canon::encode(OptionState::values($captured['document'])), 'actual mixed-option capture selects exactly native portable settings');
wprism_check_same([], $captured['unclassified'], 'reviewed runtime/env siblings do not produce an authored discovery gap');
wprism_check_same($rows, $wpdb->rows('wp_options'), 'settings capture preserves all native and excluded option rows');
wprism_check_same($captured, $producer->capture(false), 'repeated settings capture is an exact fixed point');
foreach (['visitor@example.test', 'sk_live_AUTHOREDSECRET1234567890'] as $hostile) {
    $bad = $settings;
    $bad['validation-required'] = $hostile;
    $badRows = $rows;
    $badRows[0]['option_value'] = serialize($bad);
    $wpdb->seedTable('wp_options', $badRows);
    wprism_check_throws(static fn() => $producer->capture(false), RuntimeException::class, 'authored validation copy retains the real PII/secret guard');
    wprism_check_same($badRows, $wpdb->rows('wp_options'), 'hostile settings refusal preserves the complete stored blob');
}
$reviewedSecret = $rows;
$secretSettings = $settings;
$secretSettings['validation-email'] = 'sk_live_REVIEWEDFIELD1234567890';
$reviewedSecret[0]['option_value'] = serialize($secretSettings);
$wpdb->seedTable('wp_options', $reviewedSecret);
wprism_check_throws(static fn() => $producer->capture(false), RuntimeException::class, 'reviewed email-message PII authority cannot clear a credential', 'secret guard tripped');
wprism_check_same($reviewedSecret, $wpdb->rows('wp_options'), 'reviewed-field secret refusal preserves every option byte');

$formUuid = '019200cc-0000-7000-8000-000000000005';
$formToken = '{{post:' . $formUuid . '}}';
$wpdb->seedTable('wp_wprism_map', [['id' => 1, 'uuid' => $formUuid, 'entity_type' => 'post', 'id_kind' => 'post', 'local_id' => 5]]);
$shortcode = '[wpforms id="5" title="false" description="true"]';
wprism_check_same(str_replace('id="5"', 'id="' . $formToken . '"', $shortcode),
    Shortcodes::capture_rewrite_text($shortcode, $policy, $tokens), 'shared shortcode capture rewrites only the declared form ID');
$block = '<!-- wp:wpforms/form-selector {"formId":"5","displayTitle":false} /-->';
$canonicalBlock = Blocks::capture_rewrite($block, $policy, $tokens);
wprism_check_same($formToken, parse_blocks($canonicalBlock)[0]['attrs']['formId'], 'native string block ID enters canonical state as a post reference');
wprism_check_same(false, parse_blocks($canonicalBlock)[0]['attrs']['displayTitle'], 'unrelated block boolean stays native');
wprism_check_throws(static fn() => Blocks::capture_rewrite(str_replace('"5"', '5', $block), $policy, $tokens),
    RuntimeException::class, 'wrong native block ID type refuses instead of silently coercing');
$canonicalShortcode = Shortcodes::capture_rewrite_text($shortcode, $policy, $tokens);
$wpdb->seedTable('wp_wprism_map', [['id' => 1, 'uuid' => $formUuid, 'entity_type' => 'post', 'id_kind' => 'post', 'local_id' => 1005]]);
$targetTokens = new Tokens('https://target.example.test', 'https://target.example.test/wp-content/uploads');
$targetTokens->policy = $policy;
wprism_check_same(str_replace('id="5"', 'id="1005"', $shortcode), Shortcodes::apply_rewrite_text($canonicalShortcode, $policy, $targetTokens), 'shared shortcode replay uses the target form ID');
$targetBlock = Blocks::apply_rewrite($canonicalBlock, $policy, $targetTokens);
wprism_check_same('1005', parse_blocks($targetBlock)[0]['attrs']['formId'], 'existing attribute codec emits a string-valued target ID');
wprism_check_same($canonicalBlock, Blocks::capture_rewrite($targetBlock, $policy, $targetTokens), 'block capture/replay/recapture reaches an exact canonical fixed point');
wprism_check_same([], $tokens->warnings, 'source settings and embeds produce no warnings');
wprism_check_same([], $targetTokens->warnings, 'target embed codecs produce no warnings');

$widgetUuid = '019200cc-0000-7000-8000-000000000007';
$widgetSettings = ['title' => 'Public form widget', 'form_id' => '1005', 'show_title' => 1, 'show_desc' => 0];
$widgetRows = [
    ['option_id' => 1, 'option_name' => 'widget_wpforms-widget', 'option_value' => serialize([7 => $widgetSettings, '_multiwidget' => 1]), 'autoload' => 'yes'],
    ['option_id' => 2, 'option_name' => 'sidebars_widgets', 'option_value' => serialize(['sidebar-1' => ['wpforms-widget-7'], 'array_version' => 3]), 'autoload' => 'yes'],
];
$widgetMap = [
    ['id' => 1, 'uuid' => $formUuid, 'entity_type' => 'post', 'id_kind' => 'post', 'local_id' => 1005],
    ['id' => 2, 'uuid' => $widgetUuid, 'entity_type' => 'widget', 'id_kind' => SidebarState::kind('wpforms-widget'), 'local_id' => 7],
];
$wpdb->seedTable('wp_options', $widgetRows)->seedTable('wp_wprism_map', $widgetMap);
$sidebar = SidebarState::capture($policy, $targetTokens, false);
wprism_check_same(1, count($sidebar['entities']), 'an active WPForms widget enters exactly one authored sidebar');
wprism_check_same(Canon::encode(['widgets' => [['uuid' => $widgetUuid, 'type' => 'wpforms-widget', 'settings' => array_replace($widgetSettings, ['form_id' => $formToken])]]]),
    $sidebar['entities'][0]['content'], 'actual sidebar capture converts only the widget form reference');
wprism_check_same([], $sidebar['warnings'], 'active mapped widget capture has no warning fallback');
wprism_check_same($sidebar, SidebarState::capture($policy, $targetTokens, false), 'actual sidebar recapture is an exact fixed point');
wprism_check_same([$widgetRows, $widgetMap], [$wpdb->rows('wp_options'), $wpdb->rows('wp_wprism_map')], 'widget capture preserves settings, layout and ledger rows');
foreach (['undeclared' => ['future_setting' => true], 'secret' => ['title' => 'sk_live_WIDGETSECRET1234567890'], 'pii' => ['title' => 'visitor@example.test']] as $name => $overlay) {
    $badRows = $widgetRows;
    $badRows[0]['option_value'] = serialize([7 => array_replace($widgetSettings, $overlay), '_multiwidget' => 1]);
    $wpdb->seedTable('wp_options', $badRows);
    wprism_check_throws(static fn() => SidebarState::capture($policy, $targetTokens, false), RuntimeException::class, "$name widget value refuses at actual capture");
    wprism_check_same([$badRows, $widgetMap], [$wpdb->rows('wp_options'), $wpdb->rows('wp_wprism_map')], "$name widget refusal preserves complete layout, settings and mappings");
}
$inactiveRows = $widgetRows;
$inactiveRows[1]['option_value'] = serialize(['wp_inactive_widgets' => ['wpforms-widget-7'], 'array_version' => 3]);
$wpdb->seedTable('wp_options', $inactiveRows);
$inactive = SidebarState::capture($policy, $targetTokens, false);
wprism_check_same([], $inactive['entities'], 'unselected inactive widgets stay local instead of claiming sidebar ownership');
wprism_check_same(1, count($inactive['warnings']), 'inactive exclusion is disclosed explicitly');
wprism_check_same($inactiveRows, $wpdb->rows('wp_options'), 'inactive exclusion does not delete native widget settings');
wprism_check_summary('regress_wpforms_lite_settings_and_embeds');

<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $root . '/agent/src/Policy/AdapterLibrary.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Capture/OptionsCapture.php';
require_once $root . '/agent/src/Capture/CaptureSafetyGates.php';
require_once $root . '/agent/src/Review/Lint.php';

use WPrism\AdapterLibrary;
use WPrism\Canon;
use WPrism\CaptureSafetyGates;
use WPrism\CommandRefusalException;
use WPrism\Lint;
use WPrism\OptionsCapture;
use WPrism\OptionState;
use WPrism\Policy;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

wprism_test_define_agent_versions();
WpStore::reset()->options['home'] = 'https://source.example.test';
$wpdb = FakeWpdb::install();
$wpdb->seedTable('wp_wprism_map', [])->seedTable('wp_posts', [
    ['ID' => 1, 'post_type' => 'wpforms', 'post_title' => 'Native collision', 'post_status' => 'publish'],
    ['ID' => 2, 'post_type' => 'page', 'post_title' => 'Styling collision', 'post_status' => 'publish'],
]);
$policy = Policy::load(null, ['core', 'wpforms-lite'], adapterLibrary: AdapterLibrary::fromSourcePackage($root, 'wpforms-lite'));
$fixture = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/native-authoring.json'), true, 512, JSON_THROW_ON_ERROR);
$tokens = new Tokens('https://source.example.test', 'https://source.example.test/wp-content/uploads');
$tokens->policy = $policy;
$gates = new CaptureSafetyGates('/fixture/wpforms-settings-lint');
$producer = new OptionsCapture($policy, $tokens,
    static function (string $section, string $key, mixed $value, array $rule) use ($gates): void {
        $gates->guardSecret($section, $key, $value, $rule);
        $gates->guardPersonalData($section, $key, $value, $rule);
    }, static function (): never { throw new LogicException('native flags are not entity references'); },
    static function (): never { throw new LogicException('native flags require no identity lookup'); });
$state = sys_get_temp_dir() . '/wprism-wpforms-settings-lint-' . bin2hex(random_bytes(8));
mkdir($state, 0700);
register_shutdown_function(static function () use ($state): void {
    if (is_file($state . '/options/core.json')) {
        unlink($state . '/options/core.json');
    }
    if (is_dir($state . '/options')) {
        rmdir($state . '/options');
    }
    rmdir($state);
});
$capture = static function (array $settings) use ($wpdb, $producer): array {
    $wpdb->seedTable('wp_options', [[
        'option_id' => 1, 'option_name' => 'wpforms_settings',
        'option_value' => serialize($settings), 'autoload' => 'yes',
    ]]);
    return $producer->capture(false)['document'];
};
$lint = static function (array $document) use ($state, $policy): array {
    Canon::write_file($state . '/options/core.json', Canon::encode($document));
    return Lint::scan_tree($state, $policy);
};

// f2fb4d50's native run stored modern-markup="1" beside a genuine form ID 1.
// This is the actual options reader and linter over retained native settings,
// not a finding-shaped stub or a test that avoids allocating the small ID.
$native = $capture($fixture['settings']);
wprism_check_same([], $lint($native), 'retained native settings lint clean even when a flag coincides with a real form ID');
$flags = ['gdpr', 'gdpr-disable-details', 'gdpr-disable-uuid', 'global-assets', 'modern-markup'];
$reviews = array_keys(array_filter($policy->option_rule('wpforms_settings')['sub_keys'], static fn(array $rule): bool => ($rule['lint_ok'] ?? false) === true));
sort($reviews, SORT_STRING);
wprism_check_same(array_merge(['disable-css'], $flags), $reviews, 'only the native stylesheet enum and five flag fields carry non-reference reviews');

foreach ($flags as $flag) {
    foreach ([true, false, 1, 0, '1', '0'] as $value) {
        $settings = array_replace($fixture['settings'], array_fill_keys($flags, false), [$flag => $value]);
        $captured = $capture($settings);
        $before = $wpdb->rows('wp_options');
        wprism_check_same($value, OptionState::values($captured)['wpforms_settings'][$flag], "$flag retains its exact " . get_debug_type($value) . ' representation');
        wprism_check_same([], $lint($captured), "$flag has no unrewritten-reference finding for " . var_export($value, true));
        wprism_check_same($before, $wpdb->rows('wp_options'), "$flag lint leaves the complete native settings row unchanged");
    }
    foreach (['private@example.test' => 'personal_data_refused', 'sk_live_FLAGREVIEW123456789012' => 'secret_state_refused'] as $value => $reason) {
        $settings = array_replace($fixture['settings'], array_fill_keys($flags, false), [$flag => $value]);
        $failure = null;
        try {
            $capture($settings);
        } catch (CommandRefusalException $caught) {
            $failure = $caught;
        }
        wprism_check_same($reason, $failure?->reasonCode, "$flag non-reference review grants no $reason exception");
        wprism_check_same(serialize($settings), $wpdb->rows('wp_options')[0]['option_value'], "$flag security refusal preserves the entire mixed settings blob");
    }
}

$unreviewed = array_replace($fixture['settings'], ['validation-required' => '1']);
$findings = $lint($capture($unreviewed));
wprism_check_same(1, count($findings), 'reviewed flags do not suppress a coincident number in an unreviewed sibling');
wprism_check_same('options.wpforms_settings.validation-required', $findings[0]['locator'] ?? null, 'the linter still names the exact unreviewed sibling');
wprism_check_same(['kind' => 'post', 'id' => 1, 'title' => 'Native collision', 'post_type' => 'wpforms'], $findings[0]['matches'] ?? null, 'the negative control resolves the real colliding database row');
wprism_check_summary('regress_wpforms_lite_settings_lint');

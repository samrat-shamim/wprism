<?php
declare(strict_types=1);

/**
 * Loginizer capsule contract: the manifest's brute-force slice verified
 * through the real policy loader, the real OptionsCapture classifier over
 * FakeWpdb, the real capture-side secret/PII gates, and the real lint scanner
 * — plus the three mutation proofs that make the declarations load-bearing
 * rather than decorative:
 *   - closed_sub_keys removed -> the unknown sibling rides along silently;
 *   - allow_pii removed -> the real PII screen refuses the IP policy list;
 *   - lint_ok removed -> the numeric settings sub-keys turn into bare-id
 *     findings against a colliding live post.
 * The live half of the claim (native authoring, round-trip, lifecycle,
 * conflict, deletion, exact-artifact boundaries) lives in
 * tests/conformance/ and tests/certify/, cited by the disposition.
 */

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/agent_version.php';
wprism_test_define_agent_versions();
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/wp_stubs.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/FakeWpdb.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/frozen_policy.php';
require_once dirname(__DIR__, 4) . '/tools/src/AdapterPackageValidator.php';
require_once dirname(__DIR__, 4) . '/tools/src/ArtifactLibrary.php';
require_once dirname(__DIR__, 4) . '/tools/src/AdapterProductionReadiness.php';
require_once dirname(__DIR__, 4) . '/agent/src/Capture/OptionsCapture.php';
require_once dirname(__DIR__, 4) . '/agent/src/Capture/CaptureSafetyGates.php';
require_once dirname(__DIR__, 4) . '/agent/src/Review/Lint.php';

use WPrism\AdapterLibrary;
use WPrism\Canon;
use WPrism\CaptureSafetyGates;
use WPrism\OptionState;
use WPrism\Lint;
use WPrism\LintEnvironment;
use WPrism\OptionsCapture;
use WPrism\Policy;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;
use WPrismTest\WpStore;

$root = dirname(__DIR__, 4);
$package = dirname(__DIR__, 2);
$slug = 'loginizer';

// ---------------------------------------------------------------------------
// 1. The capsule passes its complete isolated package validator.
// ---------------------------------------------------------------------------
$validated = WPrism\Tooling\AdapterPackageValidator::validate($root, $slug);
wprism_check_same($slug, $validated['adapter'], 'the isolated capsule passes its complete package validator');

$manifest = Canon::decode(Canon::read_file($package . '/package/manifest.json'));
$disposition = Canon::decode(Canon::read_file($package . '/package/disposition.json'));
$artifacts = Canon::decode(Canon::read_file($package . '/evidence/artifacts.lock.json'));

// ---------------------------------------------------------------------------
// 2. The declared inventory is exactly what the pinned 2.1.0 source writes.
// ---------------------------------------------------------------------------
$expectedOptions = [
    'loginizer_blacklist', 'loginizer_disable_brute', 'loginizer_ins_time',
    'loginizer_last_reset', 'loginizer_login_mail', 'loginizer_msg',
    'loginizer_options', 'loginizer_version', 'loginizer_whitelist',
];
$declaredOptions = array_keys($manifest['options']);
sort($declaredOptions, SORT_STRING);
wprism_check_same($expectedOptions, $declaredOptions, 'the option inventory is exactly the brute-force slice inspected in the artifact');
wprism_check_same(['loginizer_logs'], array_keys($manifest['tables']), 'the one custom table is the runtime failed-attempt log');
wprism_check_same('runtime', $manifest['tables']['loginizer_logs']['class'], 'the log table is declared runtime: owned and excluded, never captured');
foreach (['post_meta', 'term_meta', 'user_meta', 'post_types', 'taxonomies', 'providers', 'deletions', 'option_namespaces', 'option_patterns'] as $section) {
    wprism_check(!array_key_exists($section, $manifest), "the capsule declares no $section: Loginizer's free brute-force core registers none");
}
wprism_check_same(
    ['max_retries', 'lockout_time', 'max_lockouts', 'lockouts_extend', 'reset_retries', 'notify_email', 'notify_email_address', 'trusted_ips', 'blocked_screen'],
    array_keys($manifest['options']['loginizer_options']['sub_keys']),
    "loginizer_options names exactly brute-force.php's nine save members"
);
wprism_check_same(
    ['enable', 'disable_whitelist', 'html_mail', 'subject', 'body', 'roles'],
    array_keys($manifest['options']['loginizer_login_mail']['sub_keys']),
    "loginizer_login_mail names exactly the login-notification save's six members"
);
foreach (['loginizer_options', 'loginizer_login_mail'] as $mixed) {
    wprism_check_same(true, $manifest['options'][$mixed]['closed_sub_keys'], "$mixed closes its sibling set so a future member aborts capture");
    wprism_check_same('yes', $manifest['options'][$mixed]['absent_autoload'], "$mixed inserts with the autoload add_option() actually uses");
}
foreach (['loginizer_last_reset', 'loginizer_version', 'loginizer_ins_time', 'loginizer_msg'] as $marker) {
    wprism_check_same('runtime', $manifest['options'][$marker]['class'], "$marker is a runtime marker the plugin owns per environment");
}

// ---------------------------------------------------------------------------
// 3. Real capture over the plugin's native row shapes.
// ---------------------------------------------------------------------------
$db = FakeWpdb::install()->enableInformationSchema();
WpStore::reset()->seedOptions(['home' => 'https://source.test']);
$db->seedTable('wp_options', [
    ['option_id' => 10, 'option_name' => 'home', 'option_value' => 'https://source.test', 'autoload' => 'yes'],
    ['option_id' => 11, 'option_name' => 'loginizer_options', 'option_value' => serialize([
        'max_retries' => 3, 'lockout_time' => 900, 'max_lockouts' => 5, 'lockouts_extend' => 21600,
        'reset_retries' => 43200, 'notify_email' => 1, 'notify_email_address' => 'admin@example.test',
        'trusted_ips' => 'on', 'blocked_screen' => 'on',
    ]), 'autoload' => 'yes'],
    ['option_id' => 12, 'option_name' => 'loginizer_login_mail', 'option_value' => serialize([
        'enable' => 1, 'disable_whitelist' => 0, 'html_mail' => true,
        'subject' => '[$sitename] Failed login attempts', 'body' => '<p>Locked out at https://source.test/wp-login.php</p>',
        'roles' => ['administrator'],
    ]), 'autoload' => 'yes'],
    ['option_id' => 13, 'option_name' => 'loginizer_whitelist', 'option_value' => serialize([
        1 => ['start' => '10.0.0.5', 'end' => '10.0.0.9', 'time' => 1726300000],
    ]), 'autoload' => 'yes'],
    ['option_id' => 14, 'option_name' => 'loginizer_blacklist', 'option_value' => serialize([
        1 => ['start' => '192.168.7.7', 'end' => '192.168.7.7', 'time' => 1726300001],
    ]), 'autoload' => 'yes'],
    ['option_id' => 15, 'option_name' => 'loginizer_disable_brute', 'option_value' => '0', 'autoload' => 'yes'],
    ['option_id' => 16, 'option_name' => 'loginizer_version', 'option_value' => '2.1.0', 'autoload' => 'yes'],
    ['option_id' => 17, 'option_name' => 'loginizer_last_reset', 'option_value' => '1726300002', 'autoload' => 'yes'],
    ['option_id' => 18, 'option_name' => 'loginizer_ins_time', 'option_value' => '1726300003', 'autoload' => 'yes'],
    ['option_id' => 19, 'option_name' => 'loginizer_msg', 'option_value' => serialize(['a' => 1]), 'autoload' => 'yes'],
])->setColumns('wp_options', ['option_id' => 'bigint unsigned', 'option_name' => 'varchar(191)', 'option_value' => 'longtext', 'autoload' => 'varchar(20)'])
    ->setUniqueKey('wp_options', ['option_name']);
$db->setTableEngine('wp_options', 'InnoDB');
foreach (['wp_postmeta', 'wp_termmeta', 'wp_posts', 'wp_terms', 'wp_term_taxonomy', 'wp_wprism_map', 'wp_wprism_state', 'wp_wprism_kv'] as $table) {
    $db->seedTable($table, [])->setTableEngine($table, 'InnoDB');
}
$db->setColumns('wp_posts', ['ID' => 'bigint unsigned', 'post_type' => 'varchar(20)', 'post_title' => 'varchar(200)', 'post_status' => 'varchar(20)']);

$policy = Policy::load(null, ['core', $slug], adapterLibrary: AdapterLibrary::fromSourcePackage($root, $slug));
wprism_check_same([], $policy->declared_post_types(), 'no post type is claimed');
wprism_check_same([], $policy->declared_taxonomies(), 'and no taxonomy is claimed');

$gates = new CaptureSafetyGates('/wprism-loginizer-fixture-repo');
$guard = static function (string $section, string $key, mixed $value, array $rule) use ($gates): void {
    $gates->guardSecret($section, $key, $value, $rule);
    $gates->guardPersonalData($section, $key, $value, $rule);
};
$tokens = new Tokens('https://source.test', 'https://source.test/wp-content/uploads');
$capture = new OptionsCapture($policy, $tokens, $guard, static fn(): string => 'managed', static fn(): bool => false);

$before = $db->rows('wp_options');
$result = $capture->capture(false, strictReadOnly: true);
wprism_check_same([], $result['unclassified'], 'every declared option resolves through a rule; nothing is left pending');
wprism_check_same($before, $db->rows('wp_options'), 'capture is read-only: every native row survives byte-for-byte');

$values = OptionState::values($result['document']);
wprism_check_same(
    ['max_retries' => 3, 'lockout_time' => 900, 'max_lockouts' => 5, 'lockouts_extend' => 21600,
        'reset_retries' => 43200, 'notify_email' => 1, 'notify_email_address' => 'admin@example.test',
        'trusted_ips' => 'on', 'blocked_screen' => 'on'],
    $values['loginizer_options'] ?? null,
    'the nine authored settings members capture with their native scalar types'
);
wprism_check_same(
    ['enable' => 1, 'disable_whitelist' => 0, 'html_mail' => true,
        'subject' => '[$sitename] Failed login attempts', 'roles' => ['administrator']],
    array_intersect_key($values['loginizer_login_mail'] ?? [], ['enable' => 1, 'disable_whitelist' => 1, 'html_mail' => 1, 'subject' => 1, 'roles' => 1]),
    'the notification template members capture beside the tokenized body'
);
wprism_check(
    is_string($values['loginizer_login_mail']['body'] ?? null) && str_contains($values['loginizer_login_mail']['body'], '{{home}}'),
    'the body text captures through the home-URL token codec, so a target applies its own URL'
);
wprism_check_same(
    [1 => ['start' => '10.0.0.5', 'end' => '10.0.0.9', 'time' => 1726300000]],
    $values['loginizer_whitelist'] ?? null,
    'the whitelist IP-range map captures whole, record positions and creation clock included'
);
wprism_check_same(
    [1 => ['start' => '192.168.7.7', 'end' => '192.168.7.7', 'time' => 1726300001]],
    $values['loginizer_blacklist'] ?? null,
    'and so does the blacklist'
);
wprism_check_same('0', $values['loginizer_disable_brute'] ?? null, 'the whole-row 0/1 toggle captures as stored');
foreach (['loginizer_version', 'loginizer_last_reset', 'loginizer_ins_time', 'loginizer_msg'] as $marker) {
    wprism_check(
        !array_key_exists($marker, $values),
        "$marker is excluded from canonical state — runtime classification, not capture silence"
    );
}

// The activation shape: a physically present empty parent is authoritative
// removal intent, not absence (OptionsCapture.php:490-494).
$db->seedTable('wp_options', [
    ['option_id' => 10, 'option_name' => 'home', 'option_value' => 'https://source.test', 'autoload' => 'yes'],
    ['option_id' => 11, 'option_name' => 'loginizer_options', 'option_value' => serialize([]), 'autoload' => 'yes'],
])->setColumns('wp_options', ['option_id' => 'bigint unsigned', 'option_name' => 'varchar(191)', 'option_value' => 'longtext', 'autoload' => 'varchar(20)'])
    ->setUniqueKey('wp_options', ['option_name']);
$db->setTableEngine('wp_options', 'InnoDB');
$fresh = $capture->capture(false, strictReadOnly: true);
wprism_check_same(
    [],
    OptionState::values($fresh['document'])['loginizer_options'] ?? null,
    'the activation-shaped empty parent captures as present-empty removal intent'
);

// ---------------------------------------------------------------------------
// 4. Mutation proof — the closed sibling set is what bites.
// ---------------------------------------------------------------------------
$db->seedTable('wp_options', [
    ['option_id' => 10, 'option_name' => 'home', 'option_value' => 'https://source.test', 'autoload' => 'yes'],
    ['option_id' => 11, 'option_name' => 'loginizer_options', 'option_value' => serialize([
        'max_retries' => 3, 'lockout_time' => 900, 'max_lockouts' => 5, 'lockouts_extend' => 21600,
        'reset_retries' => 43200, 'notify_email' => 1, 'notify_email_address' => 'admin@example.test',
        'trusted_ips' => 'on', 'blocked_screen' => 'on', 'future_feature' => 'on',
    ]), 'autoload' => 'yes'],
])->setColumns('wp_options', ['option_id' => 'bigint unsigned', 'option_name' => 'varchar(191)', 'option_value' => 'longtext', 'autoload' => 'varchar(20)'])
    ->setUniqueKey('wp_options', ['option_name']);
$db->setTableEngine('wp_options', 'InnoDB');
wprism_check_throws(
    static fn() => $capture->capture(false, strictReadOnly: true),
    \RuntimeException::class,
    'a future tenth loginizer_options member aborts capture instead of riding along',
    'undeclared sibling key'
);

$opened = $manifest;
unset($opened['options']['loginizer_options']['closed_sub_keys']);
$openedPolicy = FrozenPolicy::policy([$opened], FrozenPolicy::site([$opened]), null, AdapterLibrary::fromSourcePackage($root, $slug));
$openedCapture = new OptionsCapture($openedPolicy, $tokens, static function (): void {}, static fn(): string => 'managed', static fn(): bool => false);
$openedResult = $openedCapture->capture(false, strictReadOnly: true);
$openedValue = OptionState::values($openedResult['document'])['loginizer_options'] ?? null;
wprism_check(
    is_array($openedValue) && !array_key_exists('future_feature', $openedValue) && count($openedValue) === 9,
    'the same row captures without refusing once closed_sub_keys is removed — and the future member is silently dropped from canonical state, which is exactly the quiet loss the closed declaration turns into a loud refusal'
);

// ---------------------------------------------------------------------------
// 5. Mutation proof — the PII review is what admits the IP policy lists.
// ---------------------------------------------------------------------------
$strict = new OptionsCapture($policy, $tokens, $guard, static fn(): string => 'managed', static fn(): bool => false);
$db->seedTable('wp_options', [
    ['option_id' => 10, 'option_name' => 'home', 'option_value' => 'https://source.test', 'autoload' => 'yes'],
    ['option_id' => 13, 'option_name' => 'loginizer_whitelist', 'option_value' => serialize([
        1 => ['start' => '10.0.0.5', 'end' => '10.0.0.9', 'time' => 1726300000],
    ]), 'autoload' => 'yes'],
])->setColumns('wp_options', ['option_id' => 'bigint unsigned', 'option_name' => 'varchar(191)', 'option_value' => 'longtext', 'autoload' => 'varchar(20)'])
    ->setUniqueKey('wp_options', ['option_name']);
$db->setTableEngine('wp_options', 'InnoDB');
$cleared = $strict->capture(false, strictReadOnly: true);
wprism_check(
    array_key_exists('loginizer_whitelist', OptionState::values($cleared['document'])),
    'the reviewed allow_pii admits the operator-authored IP policy list through the real gate'
);

$unreviewed = $manifest;
unset($unreviewed['options']['loginizer_whitelist']['allow_pii']);
$unreviewedPolicy = FrozenPolicy::policy([$unreviewed], FrozenPolicy::site([$unreviewed]), null, AdapterLibrary::fromSourcePackage($root, $slug));
$unreviewedCapture = new OptionsCapture($unreviewedPolicy, $tokens, $guard, static fn(): string => 'managed', static fn(): bool => false);
wprism_check_refuses(
    static fn() => $unreviewedCapture->capture(false, strictReadOnly: true),
    'personal_data_refused',
    'without the allow_pii review the same IP list refuses through the real PII screen'
);

// ---------------------------------------------------------------------------
// 6. Mutation proof — the lint reviews are what keep numeric settings clean.
// ---------------------------------------------------------------------------
$state = sys_get_temp_dir() . '/wprism_loginizer_lint_' . bin2hex(random_bytes(6));
mkdir($state . '/options', 0777, true);
register_shutdown_function(static function () use ($state): void {
    if (!is_dir($state)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($state, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($state);
});

// Live posts whose IDs cover every numeric settings member (3, 5, 900,
// 21600, 43200) plus 1: every resolve_id() candidate then answers from the
// posts branch, so the mutated scan's findings are attributable to the
// missing review and not to an unresolved lookup.
$db->seedTable('wp_posts', [
    ['ID' => 1, 'post_type' => 'post', 'post_title' => 'Hello world!', 'post_status' => 'publish'],
    ['ID' => 3, 'post_type' => 'post', 'post_title' => 'Third post', 'post_status' => 'publish'],
    ['ID' => 5, 'post_type' => 'post', 'post_title' => 'Fifth post', 'post_status' => 'publish'],
    ['ID' => 900, 'post_type' => 'post', 'post_title' => 'Nine hundredth post', 'post_status' => 'publish'],
    ['ID' => 21600, 'post_type' => 'post', 'post_title' => 'Twenty-one-thousandth post', 'post_status' => 'publish'],
    ['ID' => 43200, 'post_type' => 'post', 'post_title' => 'Forty-three-thousandth post', 'post_status' => 'publish'],
])->setColumns('wp_posts', ['ID' => 'bigint unsigned', 'post_type' => 'varchar(20)', 'post_title' => 'varchar(200)', 'post_status' => 'varchar(20)']);
Canon::write_file($state . '/options/core.json', Canon::encode(OptionState::document([
    'loginizer_options' => OptionState::present([
        'max_retries' => 3, 'lockout_time' => 900, 'max_lockouts' => 5, 'lockouts_extend' => 21600,
        'reset_retries' => 43200, 'notify_email' => 1, 'notify_email_address' => 'admin@example.test',
        'trusted_ips' => 'on', 'blocked_screen' => 'on',
    ], 'yes'),
    'loginizer_whitelist' => OptionState::present([
        1 => ['start' => '10.0.0.5', 'end' => '10.0.0.9', 'time' => 1726300000],
    ], 'yes'),
])));
$shippedFindings = Lint::scan_tree($state, $policy, LintEnvironment::live());
wprism_check_same([], $shippedFindings, 'the reviewed manifest lints clean even with colliding live post ids 900 and 1');
wprism_check(
    !in_array('loginizer_whitelist', array_column(array_map(static fn(array $f): array => $f['locator'] ?? [], $shippedFindings), 0), true),
    'the whitelist creation clock sits one level deeper than the id-shaped-value scanner walks, so no lint review is claimed for it'
);

$unreviewedLint = $manifest;
foreach (array_keys($unreviewedLint['options']['loginizer_options']['sub_keys']) as $subKey) {
    unset($unreviewedLint['options']['loginizer_options']['sub_keys'][$subKey]['lint_ok']);
}
$unreviewedLintPolicy = FrozenPolicy::policy([$unreviewedLint], FrozenPolicy::site([$unreviewedLint]), null, AdapterLibrary::fromSourcePackage($root, $slug));
$unreviewedFindings = Lint::scan_tree($state, $unreviewedLintPolicy, LintEnvironment::live());
wprism_check(
    $unreviewedFindings !== [],
    'the identical state lints dirty once the lint_ok reviews are removed — the reviews are load-bearing'
);
wprism_check(
    in_array('bare_id', array_column($unreviewedFindings, 'class'), true),
    'and the findings are bare-id findings, the exact shape the reviews exist to suppress'
);

// ---------------------------------------------------------------------------
// 7. The reviewed claim, artifacts and readiness record agree.
// ---------------------------------------------------------------------------
wprism_check_same('certified', $disposition['status'], 'the claim is certified: the disposition cites the live conformance and exact-artifact matrix evidence beside this offline contract');
wprism_check_same(
    ['capture', 'compile', 'plan', 'deploy', 'apply', 'recapture', 'render-api'],
    $disposition['capabilities']['operations'],
    'the claimed operations are the ones the conformance round-trip, the version matrix and this suite exercise'
);
wprism_check_same(['retire', 'activate', 'verify'], $disposition['capabilities']['lifecycle_phases'], 'the lifecycle phases are the ones the deactivate/reactivate, uninstall-residue and reinstall evidence exercises');
wprism_check_same(['options'], $disposition['capabilities']['field_sections'], 'options are the only claimed field section');
wprism_check_same(
    wprism_check_ksort_recursive($manifest['version_range']),
    wprism_check_ksort_recursive($disposition['supported_versions']['range']),
    'the reviewed window restates the declared one'
);
wprism_check_same($manifest['plugin'], $disposition['supported_versions']['plugin'], 'both halves name one plugin subject');
wprism_check_same('loginizer/loginizer.php', $manifest['plugin'], "the subject is the plugin's real main file");
wprism_check_same(['min' => '2.1.0', 'max' => '2.1.1'], $manifest['version_range'], 'the range admits exactly the pinned 2.1.0 artifact line');
wprism_check_same(['conformance-loginizer', 'exact-artifact-version-matrix', 'regress-package-contract'], $disposition['evidence']['tests'], 'the reviewed claim cites the live round-trip, the exact-artifact matrix and this offline contract');
$unsupportedSurfaces = array_column($disposition['unsupported'], 'surface');
foreach (['multisite', 'options.loginizer_captcha', 'options.loginizer_2fa', 'options.loginizer_epl', 'options.loginizer_security_social_sso_dashboard', 'options.loginizer_license_promo'] as $surface) {
    wprism_check(in_array($surface, $unsupportedSurfaces, true), "the disposition records $surface as an explicit unsupported boundary");
}

wprism_check_same(
    ['2.0.9', '2.1.0'],
    array_keys($artifacts['plugins']['loginizer']),
    'the capsule pins the exercised release and the adjacent refusal fixture'
);
wprism_check_same('certified-boundary', $artifacts['plugins']['loginizer']['2.1.0']['role'], '2.1.0 is the in-range certified boundary the matrix exercises');
wprism_check_same('refusal-fixture', $artifacts['plugins']['loginizer']['2.0.9']['role'], '2.0.9 is the out-of-range refusal fixture');
wprism_check_same('https://downloads.wordpress.org/plugin/loginizer.2.1.0.zip', $artifacts['plugins']['loginizer']['2.1.0']['url'], 'the pin names an exact versioned URL, never the redirect');
wprism_check(!isset(WPrism\Tooling\ArtifactLibrary::loadPlatform($root)['plugins']['loginizer']), 'platform bootstrap does not duplicate this plugin artifact ownership');

$readiness = WPrism\Tooling\AdapterProductionReadiness::record($root, $slug);
wprism_check_same('ready', $readiness['readiness'], 'every scenario family is covered or structurally not applicable');
wprism_check_same([], $readiness['gaps'], 'no family is hidden as a gap');
wprism_check_same([], $readiness['blocked'], 'no family is left blocked');
wprism_check_same(
    ['identity-references', 'derived-state'],
    array_keys($readiness['not_applicable']),
    'exactly the two structurally absent families are recorded not applicable, each with its reason'
);
wprism_check_same(10, count($readiness['covered']), 'the other ten families name their evidence files');

wprism_check_summary('regress_loginizer_package_contract');

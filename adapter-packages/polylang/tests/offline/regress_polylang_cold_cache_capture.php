<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/agent_version.php';
wprism_test_define_agent_versions();
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/wp_stubs.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/FakeWpdb.php';
require_once dirname(__DIR__, 4) . '/agent/src/Capture/OptionsCapture.php';
require_once dirname(__DIR__, 4) . '/agent/src/Capture/CaptureTransaction.php';
require_once dirname(__DIR__, 2) . '/fixtures/polylang_language_factory_double.php';

use WPrism\AdapterLibrary;
use WPrism\CaptureTransaction;
use WPrism\DatabaseQueryIsolationViolationException;
use WPrism\Db;
use WPrism\OptionsCapture;
use WPrism\Policy;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

final class PllCaptureOptions {
    public const DEFAULTS = [
        'force_lang' => 1, 'domains' => [], 'hide_default' => true, 'rewrite' => true,
        'redirect_lang' => false, 'browser' => false, 'media_support' => true,
        'post_types' => [], 'taxonomies' => [], 'sync' => [], 'default_lang' => '',
        'nav_menus' => [], 'first_activation' => false, 'previous_version' => '', 'version' => '3.8.6',
    ];
    public function get(string $key): mixed { return self::DEFAULTS[$key] ?? null; }
    public function get_schema(): array {
        return ['properties' => array_map(static fn(mixed $default): array => ['default' => $default], self::DEFAULTS)];
    }
}

final class PllColdCacheModel {
    public int $listCalls = 0;
    public bool $warm = false;
    public function get_languages_list(): array {
        ++$this->listCalls;
        if (!$this->warm) {
            global $wpdb;
            // Languages::get_from_taxonomies() writes even the empty roster
            // once languages_ready is true (pinned Polylang 3.8.6:1361).
            $wpdb->query("INSERT INTO `$wpdb->options` (option_name, option_value, autoload) VALUES ('_transient_pll_languages_list', 'a:0:{}', 'on') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)");
        }
        return [];
    }
}

function PLL(): object { return $GLOBALS['pll_capture_runtime']; }

$db = FakeWpdb::install()->enableInformationSchema();
WpStore::reset()->seedOptions(['home' => 'https://source.test', 'stylesheet' => 'fixture-theme']);
$db->seedTable('wp_options', [[
    'option_id' => 1, 'option_name' => 'polylang', 'option_value' => serialize(PllCaptureOptions::DEFAULTS), 'autoload' => 'yes',
]])->setColumns('wp_options', ['option_id' => 'bigint unsigned', 'option_name' => 'varchar(191)', 'option_value' => 'longtext', 'autoload' => 'varchar(20)'])
    ->setUniqueKey('wp_options', ['option_name']);
$db->setColumns('wp_terms', ['term_id' => 'bigint', 'name' => 'varchar(200)', 'slug' => 'varchar(200)', 'term_group' => 'bigint'])
    ->setColumns('wp_term_taxonomy', ['term_taxonomy_id' => 'bigint', 'term_id' => 'bigint', 'taxonomy' => 'varchar(32)',
        'description' => 'longtext', 'parent' => 'bigint', 'count' => 'bigint']);
foreach (['wp_postmeta', 'wp_termmeta', 'wp_posts', 'wp_terms', 'wp_term_taxonomy', 'wp_wprism_map', 'wp_wprism_state', 'wp_wprism_kv'] as $table) {
    $db->seedTable($table, [])->setTableEngine($table, 'InnoDB');
}
$db->setTableEngine('wp_options', 'InnoDB');
$policy = Policy::load(null, ['core', 'polylang'], adapterLibrary: AdapterLibrary::fromSourcePackage(dirname(__DIR__, 4), 'polylang'));
$model = new PllColdCacheModel();
$GLOBALS['pll_capture_runtime'] = (object) ['options' => new PllCaptureOptions(), 'model' => $model];
$capture = new OptionsCapture($policy, new Tokens('https://source.test', 'https://source.test/wp-content/uploads'),
    static function (): void {}, static fn(): string => 'managed', static fn(): bool => false);
$observe = static function () use ($capture, $policy): array {
    Db::start_read_only_consistent_snapshot('Polylang cold-cache capture', CaptureTransaction::database_profile($policy, true));
    try {
        return $capture->capture(false, strictReadOnly: true);
    } finally {
        Db::rollback('Polylang cold-cache capture cleanup');
    }
};

// Prove the actual engine boundary catches the archived native mechanism;
// the passing capture below must not turn this same write into authority.
Db::start_read_only_consistent_snapshot('Polylang cold-cache control', CaptureTransaction::database_profile($policy, true));
try {
    wprism_check_throws(static fn() => $model->get_languages_list(), DatabaseQueryIsolationViolationException::class,
        'cold language cache writes remain forbidden in an options-only snapshot');
} finally {
    Db::rollback('Polylang cold-cache control cleanup');
}
$model->listCalls = 0;
$before = $db->rows('wp_options');
$empty = $observe();
wprism_check_same([], $empty['unclassified'], 'empty cold target captures through the real option classifier');
wprism_check_same(0, $model->listCalls, 'capture never enters the cache-backed language list');
wprism_check_same($before, $db->rows('wp_options'), 'cold empty capture preserves all option rows');

$seedLanguages = static function () use ($db): void {
    $db->seedTable('wp_terms', [
        ['term_id' => 31, 'name' => 'Français', 'slug' => 'fr', 'term_group' => 2],
        ['term_id' => 17, 'name' => 'English', 'slug' => 'en', 'term_group' => 1],
    ])->seedTable('wp_term_taxonomy', [
        ['term_taxonomy_id' => 201, 'term_id' => 31, 'taxonomy' => 'language', 'description' => serialize(['locale' => 'fr_FR', 'rtl' => 0, 'flag_code' => 'fr']), 'parent' => 0, 'count' => 0],
        ['term_taxonomy_id' => 202, 'term_id' => 17, 'taxonomy' => 'language', 'description' => serialize(['locale' => 'en_US', 'rtl' => 0, 'flag_code' => 'us']), 'parent' => 0, 'count' => 0],
    ]);
};
$seedLanguages();
foreach ([false, true] as $warm) {
    $model->warm = $warm;
    PLL_Language_Factory::$inputs = [];
    wprism_check_same($empty, $observe(), 'cold and stale-warm lists do not change canonical options');
    wprism_check_same(['en', 'fr'], array_map(static fn(array $terms): string => $terms['language']->slug, PLL_Language_Factory::$inputs),
        'native factory sees every physical language in native term-group order');
    wprism_check_same($before, $db->rows('wp_options'), 'flag interpretation preserves the entire options table');
}
wprism_check_same(0, $model->listCalls, 'stale empty singleton cannot hide nonempty language rows');

// The former live "language deletion" control deleted only wp_terms, not its
// taxonomy row. It therefore exercises malformed physical input, never the
// later unsupported-deletion policy gate. Keep those two premises distinct.
$seedLanguages();
$db->query('DELETE FROM wp_terms WHERE term_id = 31');
$orphanBefore = [$db->rows('wp_terms'), $db->rows('wp_term_taxonomy'), $db->rows('wp_options')];
PLL_Language_Factory::$inputs = [];
wprism_check_throws($observe, RuntimeException::class, 'orphaned language refuses through the actual protected option-capture path',
    'Polylang language flag audit bounded term observation refused: field or aggregate byte budget exceeded');
wprism_check_same([], PLL_Language_Factory::$inputs, 'orphaned language fails before native interpretation or deletion inference');
wprism_check_same($orphanBefore, [$db->rows('wp_terms'), $db->rows('wp_term_taxonomy'), $db->rows('wp_options')],
    'malformed-language refusal preserves the entire native term, taxonomy and option preimage');
$db->query('DELETE FROM wp_term_taxonomy WHERE term_id = 31');
wprism_check_same($empty, $observe(), 'a consistent remaining language roster passes the physical input boundary');
$seedLanguages();

foreach (['custom-url', 'custom-markup', 'missing-builtin', 'invalid-code', 'malformed-language', 'native-write'] as $fault) {
    PLL_Language_Factory::$interpret = static function (array $terms) use ($fault): mixed {
        if ($fault === 'malformed-language') return null;
        if ($fault === 'native-write') {
            global $wpdb;
            $wpdb->query("UPDATE `$wpdb->options` SET option_value = 'hostile' WHERE option_name = 'polylang'");
        }
        return (object) [
            'slug' => $terms['language']->slug, 'flag_code' => $fault === 'invalid-code' ? '../unsafe' : 'us',
            'flag_url' => $fault === 'missing-builtin' ? '' : 'https://fixture.test/flag.png',
            'custom_flag_url' => $fault === 'custom-url' ? 'https://fixture.test/custom.svg' : '',
            'custom_flag' => $fault === 'custom-markup' ? '<svg/>' : '',
        ];
    };
    wprism_check_throws($observe, $fault === 'native-write' ? DatabaseQueryIsolationViolationException::class : RuntimeException::class,
        "$fault still refuses through the native capture hook");
    wprism_check_same($before, $db->rows('wp_options'), "$fault refusal preserves every option byte");
}
PLL_Language_Factory::$interpret = null;
$seedLanguages();
$bad = $db->rows('wp_term_taxonomy');
$bad[0]['description'] = 'O:8:"stdClass":0:{}';
$db->seedTable('wp_term_taxonomy', $bad);
PLL_Language_Factory::$inputs = [];
wprism_check_throws($observe, RuntimeException::class, 'unsafe serialized term input refuses before native decoding');
wprism_check_same([], PLL_Language_Factory::$inputs, 'the complete description roster is admitted before any factory callback');
wprism_check_summary('regress_polylang_cold_cache_capture');

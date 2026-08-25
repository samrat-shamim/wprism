<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression harness for
 * DUO-3290's pure logic: prefix-grouping, the advisory attribution
 * heuristic, and transient-partitioning. Coverage::report()/options_report()/
 * tables_report() themselves need a real $wpdb (live queries against
 * wp_options/SHOW TABLES) and real WordPress functions (get_option(),
 * esc_sql(), $wpdb->tables()) -- exercised live instead, in
 * regress_coverage.sh, matching the same offline/live split
 * regress_repository_compiler.sh vs regress_repository_compiler_integration.sh
 * already establishes for a comparably-shaped class. This harness covers
 * everything that does NOT touch the database: the private static methods
 * most likely to have an off-by-one or a wrong regex, tested directly via
 * Reflection since Coverage has no public constructor to instantiate
 * (report() is the only public surface, and it requires a live repo+DB).
 *
 * Exit 0 and "ALL PASSED" on success; any failed check prints "FAIL: ..."
 * and the script exits 1.
 */

require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require __DIR__ . '/../../../../agent/src/Review/Coverage.php';

// The product path below is Coverage::report(), which loads a real Policy and
// therefore needs the two constants the drop-in binds at load. Read from
// agent/duo.php rather than restated, so a version bump does not edit this file.
$duo_coverage_bootstrap = (string) file_get_contents(__DIR__ . '/../../../../agent/duo.php');
if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', preg_match("/define\('DUO_SPEC_VERSION',\s*(\d+)\)/", $duo_coverage_bootstrap, $m) === 1
        ? (int) $m[1] : 2);
}
if (!defined('DUO_AGENT_VERSION')) {
    define('DUO_AGENT_VERSION', preg_match("/define\('DUO_AGENT_VERSION',\s*'([^']+)'\)/", $duo_coverage_bootstrap, $m) === 1
        ? $m[1] : '0.0.0');
}
require_once __DIR__ . '/../../../../agent/src/Policy/ManifestDispositions.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';

$failures = 0;
function check(bool $cond, string $msg): void {
    global $failures;
    if ($cond) {
        echo "ok: $msg\n";
    } else {
        echo "FAIL: $msg\n";
        $failures++;
    }
}

function call_private(string $method, array $args) {
    $ref = new ReflectionMethod(Duo\Coverage::class, $method);
    return $ref->invokeArgs(null, $args);
}

// ======================================================================
// guess_prefix()
// ======================================================================
echo "\n== guess_prefix() ==\n";
// Verified empirically (php -r), not assumed: the regex is greedy on its
// optional second segment, so a name with three-plus underscore-separated
// words groups on its first TWO, not one. Confirmed this is the more
// useful behavior, not just the regex's default: it clusters
// woocommerce_email_* / woocommerce_checkout_* / woocommerce_tax_* as
// distinct feature-area sub-groups within WooCommerce rather than
// flattening everything to one "woocommerce" bucket -- attribute()
// still correctly rolls each sub-group up to the right plugin via
// prefix-matching (str_starts_with($slug, $needle)), so a human reading
// the report sees both the fine-grained clustering AND the plugin-level
// rollup, not a forced choice between them.
check(call_private('guess_prefix', ['woocommerce_checkout_pay_endpoint']) === 'woocommerce_checkout',
    'a 4-segment name groups on its first two words (feature-area clustering within a plugin)');
check(call_private('guess_prefix', ['_transient_timeout_foo']) === 'transient_timeout',
    'a leading underscore (WordPress\'s own "private" convention) is stripped before grouping, not treated as part of the prefix; the remaining name still groups two-segment');
check(call_private('guess_prefix', ['rank_math_title']) === 'rank_math',
    'a real two-word plugin prefix (rank_math) with exactly 3 total segments groups on the first two, not truncated to just "rank"');
check(call_private('guess_prefix', ['akismet']) === 'akismet',
    'a bare single-word name with no trailing underscore falls back to itself, not an empty string');

// ======================================================================
// partition_transients()
// ======================================================================
echo "\n== partition_transients() ==\n";
[$transient, $other] = call_private('partition_transients', [[
    '_transient_foo', '_site_transient_bar', 'woocommerce_currency', '_transient_timeout_foo',
]]);
check($transient === ['_transient_foo', '_site_transient_bar', '_transient_timeout_foo'],
    'both _transient_ and _site_transient_ prefixes partition out (got: ' . json_encode($transient) . ')');
check($other === ['woocommerce_currency'], 'a non-transient name stays in the "other" bucket');

// ======================================================================
// attribute() — advisory, always labeled-or-null, per team-lead's own
// explicit ruling that a mislabeled guess is cosmetic but must never be
// presented as a bare unlabeled fact.
// ======================================================================
echo "\n== attribute() ==\n";
$slugs = ['woocommerce', 'contact-form-7', 'akismet'];
check(call_private('attribute', ['woocommerce', $slugs]) === 'woocommerce',
    'exact slug match attributes correctly');
check(call_private('attribute', ['woocommerce_checkout', $slugs]) === 'woocommerce',
    'a needle that starts with an active slug attributes to that slug');
check(call_private('attribute', ['rank_math', $slugs]) === null,
    'no active plugin matches -> null (the explicit "unattributed" case), never a wrong guess');
// T6 adapter walk: an edition-suffixed slug (`wpforms-lite`) prefixes its
// options and tables with the family name alone; the family token attributes
// when it is a name (>= 4 chars) and unambiguous, and two edition slugs of one
// family say nothing rather than guess.
check(call_private('attribute', ['wpforms_tasks_meta', ['woocommerce', 'wpforms-lite']]) === 'wpforms-lite',
    'a table/option prefixed with the family name of an edition-suffixed slug attributes to that slug');
check(call_private('attribute', ['wpforms', ['wpforms-lite']]) === 'wpforms-lite',
    'the bare family name attributes too');
check(call_private('attribute', ['wpforms_settings', ['wpforms-lite', 'wpforms-pro']]) === null,
    'two active editions of one family: ambiguous, left unattributed rather than guessed');
check(call_private('attribute', ['wp_rocket_cache', ['wp-super-cache']]) === null,
    'a family token shorter than a name (wp) never attributes');
check(call_private('attribute', ['wpcf7', $slugs]) === null,
    "CF7's own option prefix (wpcf7) does not match its slug (contact-form-7) under simple prefix matching -- "
    . 'documenting this as a KNOWN heuristic limitation (DUO-3290\'s own design doc named this exact risk), not '
    . 'a bug: attribution is advisory and this is precisely the kind of miss it is allowed to make'
);

// ======================================================================
// group_and_attribute() — row count AND distinct-group count both matter
// per team-lead's own framing (one plugin with many rows reads
// differently from many plugins with one row each), and grouping must be
// sorted by count descending so the biggest gaps surface first.
// ======================================================================
echo "\n== group_and_attribute() ==\n";
// Verified empirically first (php -r): three names sharing their first TWO
// segments (woocommerce_email_*) group together; two unrelated names each
// form their own singleton group.
$names = [
    'woocommerce_email_from_name', 'woocommerce_email_from_address', 'woocommerce_email_header_image',
    'rank_math_title', 'akismet_api_key',
];
$groups = call_private('group_and_attribute', [$names, ['woocommerce']]);
check(count($groups) === 3, 'three distinct prefixes from five names (woocommerce_email x3, rank_math_title x1, akismet_api x1) — got ' . count($groups));
check($groups[0]['prefix'] === 'woocommerce_email' && $groups[0]['count'] === 3,
    'the largest group (woocommerce_email, 3 rows) sorts first — got ' . json_encode($groups[0]));
check($groups[0]['probable_owner'] === 'woocommerce',
    'a feature-area sub-group (woocommerce_email) still attributes correctly to the shorter active slug (woocommerce) via the needle-starts-with-slug direction');
check($groups[1]['probable_owner'] === null && $groups[2]['probable_owner'] === null,
    'groups with no matching active plugin attribute to null, not a wrong guess');

// ======================================================================
// tables_report(), through the PUBLIC product path (Coverage::report()).
//
// This half used to exist only in the live pair suite (regress_coverage.sh),
// which is why the published-row shape could drift unnoticed: the row is
// consumed by `duo assess`, and both consumers
// (cli/src/Assess/SurfaceCatalog.php:326 and AssessReport.php:156) SKIP any
// undeclared-table row that carries no `logical_name`. The report built the
// name, used it for attribution, and then dropped it, so the `table:` surface
// rows assess is designed to mint never appeared on a real site. The row keys
// are asserted exactly here so the next drop is a failing suite rather than a
// silently empty section of somebody's assessment.
// ======================================================================
echo "\n== tables_report() published row shape (product path) ==\n";

$coverageScratch = sys_get_temp_dir() . '/duo_regress_coverage_offline_' . getmypid() . '_' . bin2hex(random_bytes(4));
mkdir($coverageScratch . '/repo', 0777, true);
register_shutdown_function(static function () use ($coverageScratch): void {
    @unlink($coverageScratch . '/repo/site.duo.json');
    @rmdir($coverageScratch . '/repo');
    @rmdir($coverageScratch);
});
file_put_contents($coverageScratch . '/repo/site.duo.json', json_encode([
    'manifests' => ['core'],
    'policy' => new stdClass(),
    'spec_version' => DUO_SPEC_VERSION,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$store = DuoTest\WpStore::reset();
$store->seedOptions(['active_plugins' => ['acme-catalog/acme-catalog.php']]);
$wpdb = DuoTest\FakeWpdb::install();
$wpdb->seedTable('wp_options', [
    ['option_id' => 1, 'option_name' => 'blogname', 'option_value' => 'Fixture', 'autoload' => 'yes'],
]);
// wp_comments/wp_commentmeta are WordPress core tables ($wpdb->tables()), so
// they must be filtered out; wp_acme_catalog_index is the undeclared one an
// unmanifested plugin owns, with two real rows to count.
$wpdb->seedTable('wp_comments', []);
$wpdb->seedTable('wp_commentmeta', []);
$wpdb->seedTable('wp_acme_catalog_index', [
    ['id' => 1, 'label' => 'first'],
    ['id' => 2, 'label' => 'second'],
]);
// Duo's own ledger tables are neither core nor adapter-declared; before T6 the
// walk read `table:duo_journal … unclassified` in its own assessment. They are
// Ledger::OWN_TABLES and must never be reported as undeclared.
foreach (Duo\Ledger::OWN_TABLES as $own) {
    $wpdb->seedTable('wp_' . $own, [['k' => 'x']]);
}

$report = Duo\Coverage::report($coverageScratch . '/repo');
check($report['format'] === Duo\Coverage::FORMAT, 'the product path emits the versioned coverage format');
check(($report['tables']['undeclared_total'] ?? null) === 1,
    'exactly one undeclared table is counted (core tables are excluded) — got '
    . var_export($report['tables']['undeclared_total'] ?? null, true));
$undeclared = $report['tables']['undeclared'];
check(count($undeclared) === 1, 'one published undeclared row — got ' . count($undeclared));
$rowKeys = array_keys($undeclared[0]);
sort($rowKeys, SORT_STRING);
check($rowKeys === ['logical_name', 'probable_owner', 'registered', 'row_count', 'table'],
    'a published undeclared-table row carries exactly table, logical_name, row_count, probable_owner, registered — got '
    . implode(',', $rowKeys));
check(($undeclared[0]['logical_name'] ?? null) === 'acme_catalog_index',
    'logical_name is the UNPREFIXED name `duo assess` builds `table:<name>` from — got '
    . var_export($undeclared[0]['logical_name'] ?? null, true));
check(($undeclared[0]['table'] ?? null) === 'wp_acme_catalog_index',
    'table stays the physical, prefixed name');
check((int) ($undeclared[0]['row_count'] ?? -1) === 2, 'row_count is the real COUNT(*)');
check(($undeclared[0]['probable_owner'] ?? null) === 'acme-catalog',
    'attribution still resolves the owning active plugin slug');
check(($undeclared[0]['registered'] ?? null) === false,
    'a plugin table that never touched $wpdb->tables is not `registered` — it was always visible');
check(($report['tables']['registered_total'] ?? null) === 0,
    'no plugin registered a table on this fixture, so registered_total is 0 — got '
    . var_export($report['tables']['registered_total'] ?? null, true));
check(($report['tables']['core_source'] ?? null) === 'wpdb_class_declaration',
    'the core/registered split was made from $wpdb\'s own class declaration — got '
    . var_export($report['tables']['core_source'] ?? null, true));
check(($report['tables']['core_total'] ?? null) === 3,
    'core_total counts only the core tables actually live here (options, comments, commentmeta) — got '
    . var_export($report['tables']['core_total'] ?? null, true));

// ======================================================================
// A PLUGIN-REGISTERED TABLE IS A COVERAGE SUBJECT, NOT A CORE TABLE.
//
// Measured on a WPForms Lite 2.0.0.5 site (recon, 2026-08-25):
// `$wpdb->tables('all', true)` returned SIXTEEN names and four of them were
// `wp_actionscheduler_*`. `duo coverage` read `live=26 core=16 declared=2
// undeclared=6` — the six wpforms_* tables, and not one of the four Action
// Scheduler tables the site actually writes rows to
// (wp_actionscheduler_actions held `action_scheduler/migration_hook`).
//
// The mechanism is one line of the bundled library:
// `$wpdb->tables[] = $table` in ActionScheduler_Abstract_Schema::
// register_tables() (vendor/woocommerce/action-scheduler/classes/abstracts/
// ActionScheduler_Abstract_Schema.php:54). $wpdb->tables is a public instance
// property, tables('all') composes from it (class-wpdb.php:1122-1130), and
// the previous engine treated the whole composition as "what WordPress
// considers core" (Coverage.php's comment at the old :269-273). So every
// Action-Scheduler-bundling plugin — WPForms Lite, WooCommerce, WP Mail SMTP
// — hid four tables from coverage, from `duo assess`'s `table:` surfaces, and
// from `duo adapter-draft --seed`'s proposals.
//
// Reproduced below with the four measured names, registered the way the
// library registers them. Against the prior engine every assertion in this
// block fails: undeclared_total reads 1 instead of 5, the actionscheduler
// rows are absent, and core_total reads 7 instead of 3.
// ======================================================================
echo "\n== a table a plugin appended to \$wpdb->tables is not core ==\n";

$asTables = [
    'actionscheduler_actions',
    'actionscheduler_claims',
    'actionscheduler_groups',
    'actionscheduler_logs',
];
foreach ($asTables as $asTable) {
    // Exactly ActionScheduler_Abstract_Schema::register_tables():54's first
    // statement. Its second (`$wpdb->$table = $name`, the `$wpdb->
    // actionscheduler_actions` convenience property) is deliberately not
    // replayed: Coverage never reads it, and a dynamic property on a class
    // without #[AllowDynamicProperties] is a PHP 8.2+ deprecation — a warning
    // in a suite that has to stay clean to be green. The APPEND is the whole
    // mechanism; the class DECLARATION stays untouched, which is the
    // asymmetry declared_core_tables() reads.
    $wpdb->tables[] = $asTable;
}
// One row in `actions`, as the measured site had; the other three empty, as
// _claims (0) and the rest measured. Row counts are asserted so the fix is
// proved to run the same COUNT(*) path every other undeclared table takes.
$wpdb->seedTable('wp_actionscheduler_actions', [['action_id' => 1, 'hook' => 'action_scheduler/migration_hook']]);
$wpdb->seedTable('wp_actionscheduler_claims', []);
$wpdb->seedTable('wp_actionscheduler_groups', []);
$wpdb->seedTable('wp_actionscheduler_logs', []);

// Without this the block would prove nothing: the whole defect starts with
// tables() reporting a plugin's table indistinguishably from `posts`.
$liveTableNames = array_keys($wpdb->tables('all', true));
check(in_array('actionscheduler_actions', $liveTableNames, true)
    && in_array('posts', $liveTableNames, true),
    '$wpdb->tables(\'all\', true) now lists the registered name beside WordPress\'s own — got '
    . implode(',', $liveTableNames));

$registeredReport = Duo\Coverage::report($coverageScratch . '/repo');
$rt = $registeredReport['tables'];
check(($rt['registered_total'] ?? null) === 4,
    'the four registered tables are counted as registered, not core — got '
    . var_export($rt['registered_total'] ?? null, true));
check(($rt['core_total'] ?? null) === 3,
    'core_total is unmoved by the registration: it still counts only WordPress\'s own declared tables — got '
    . var_export($rt['core_total'] ?? null, true));
check(($rt['undeclared_total'] ?? null) === 5,
    'all four registered tables joined acme_catalog_index as undeclared coverage subjects — got '
    . var_export($rt['undeclared_total'] ?? null, true));
$byTable = [];
foreach ($rt['undeclared'] as $row) {
    $byTable[$row['table']] = $row;
}
foreach ($asTables as $asTable) {
    check(isset($byTable['wp_' . $asTable]),
        "wp_$asTable reaches the undeclared listing (it was silently core before)");
    check(($byTable['wp_' . $asTable]['logical_name'] ?? null) === $asTable,
        "wp_$asTable publishes the logical_name `duo assess` mints `table:$asTable` from");
    check(($byTable['wp_' . $asTable]['registered'] ?? null) === true,
        "wp_$asTable is marked `registered`, naming why it used to read as core");
}
check((int) ($byTable['wp_actionscheduler_actions']['row_count'] ?? -1) === 1,
    'the registered table\'s real COUNT(*) is reported, like any other undeclared table');
// array_key_exists, not ??: the honest answer here IS null, and `?? 'x'`
// cannot tell "attributed to nobody" from "the key was dropped".
check(array_key_exists('probable_owner', $byTable['wp_actionscheduler_actions'])
    && $byTable['wp_actionscheduler_actions']['probable_owner'] === null,
    'attribution stays honest: no active slug is `actionscheduler`, so the bundled library\'s table attributes to nobody — got '
    . var_export($byTable['wp_actionscheduler_actions']['probable_owner'] ?? '(absent)', true));
check(($byTable['wp_acme_catalog_index']['registered'] ?? null) === false,
    'the ordinary plugin table is still not `registered` — the flag separates the two discovery paths');

// ======================================================================
// options_report() visibility, through the PUBLIC product path.
//
// DUO-3505: visibility used to be decided by two CLASS-FILTERED capture
// enumerators (authored_options() + sub_keyed_options()), so a name whose
// winning rule was anything else -- env, runtime, derived, managed, an
// option_patterns match, a dynamic_options row -- was reported "invisible to
// every installed adapter" even though a pinned adapter declares it by name.
// Measured before the fix on a site holding only the 19 option names
// manifests/core.json itself declares: 10 invisible, including the 3 managed
// rows the artifact always contains.
//
// Every row below is seeded against the REAL shipped manifests/core.json plus
// four site-policy rules, so what is asserted is the shipped library's own
// answer rather than a fixture's idea of it.
// ======================================================================
echo "\n== options_report() visibility (product path) ==\n";

$visibilityScratch = sys_get_temp_dir() . '/duo_regress_coverage_visibility_' . getmypid() . '_' . bin2hex(random_bytes(4));
mkdir($visibilityScratch . '/repo', 0777, true);
register_shutdown_function(static function () use ($visibilityScratch): void {
    @unlink($visibilityScratch . '/repo/site.duo.json');
    @rmdir($visibilityScratch . '/repo');
    @rmdir($visibilityScratch);
});
// The four site-policy rules from the issue: an agency's own adapter
// declaring its option family exactly, one name per class.
file_put_contents($visibilityScratch . '/repo/site.duo.json', json_encode([
    'manifests' => ['core'],
    'policy' => [
        'options' => [
            // autoload is mandatory on an insertable rule: OptionGrammar.php:92
            // refuses "insertion may never guess".
            'agency_cs_settings' => ['class' => 'authored', 'autoload' => 'preserve'],
            // an env rule needs an explicit boolean `required`: OptionGrammar.php:48.
            'agency_cs_api_key' => ['class' => 'env', 'required' => true],
            'agency_cs_case_count' => ['class' => 'derived'],
            'agency_cs_cache_stamp' => ['class' => 'runtime'],
        ],
    ],
    'spec_version' => DUO_SPEC_VERSION,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$store = DuoTest\WpStore::reset();
// stylesheet is read by options_report() itself to resolve core.json's
// dynamic_options.theme_mods (resolver `active_stylesheet`).
$store->seedOptions([
    'active_plugins' => ['acme-catalog/acme-catalog.php'],
    'stylesheet' => 'fixture-child',
    'template' => 'fixture-parent',
]);
$wpdb = DuoTest\FakeWpdb::install();
$optionRows = [
    // captured: site-policy authored
    'agency_cs_settings' => 'x',
    // declared and excluded, one per class, all four previously invisible
    'agency_cs_api_key' => 'x',
    'agency_cs_case_count' => 'x',
    'agency_cs_cache_stamp' => 'x',
    // core.json `managed`: OptionsCapture.php:202-216 always writes these
    'active_plugins' => 'a:0:{}',
    'template' => 'fixture-parent',
    'stylesheet' => 'fixture-child',
    // core.json `authored` / `env`
    'blogname' => 'Fixture',
    'siteurl' => 'https://fixture.test',
    // core.json option_patterns: ^_transient_ (derived), ^_wp_session_ (runtime)
    '_transient_foo' => 'x',
    '_wp_session_abc' => 'x',
    // core.json dynamic_options.theme_mods: the active theme's row is written,
    // a former theme's row is residue WordPress keeps (DUO-3264)
    'theme_mods_fixture-child' => 'a:0:{}',
    'theme_mods_fixture-parent' => 'a:0:{}',
    // the ONE name nothing declares
    'genuinely_unknown_thing' => 'x',
];
$seeded = [];
$optionId = 1;
foreach ($optionRows as $optionName => $optionValue) {
    $seeded[] = [
        'option_id' => $optionId++,
        'option_name' => $optionName,
        'option_value' => $optionValue,
        'autoload' => 'yes',
    ];
}
$wpdb->seedTable('wp_options', $seeded);

$o = Duo\Coverage::report($visibilityScratch . '/repo')['options'];

// The published key set, asserted exactly like the undeclared-table row above,
// so a future key drop is a failing suite rather than a silently empty section
// of somebody's assessment.
$optionKeys = array_keys($o);
sort($optionKeys, SORT_STRING);
check($optionKeys === [
    'captured', 'declared_excluded', 'declared_excluded_by_class', 'invisible_groups',
    'invisible_other', 'invisible_total', 'invisible_transient', 'pending', 'total',
], 'the options report publishes exactly the nine documented keys — got ' . implode(',', $optionKeys));

check($o['total'] === count($optionRows),
    'every seeded row is read (' . count($optionRows) . ') — got ' . var_export($o['total'], true));

// The headline defect: a name a pinned adapter declares is never invisible,
// whatever its class. Before the fix these were group `agency_cs` count 2.
$invisiblePrefixes = array_column($o['invisible_groups'], 'prefix');
check(!in_array('agency_cs', $invisiblePrefixes, true),
    'site-policy env/derived/runtime rules are NOT reported invisible (before DUO-3505: prefix agency_cs, 2 rows) — got '
    . json_encode($o['invisible_groups']));
check($o['invisible_total'] === 1 && $invisiblePrefixes === ['genuinely_unknown'],
    'the ONE name no rule from any source matches is the whole invisible set — got '
    . $o['invisible_total'] . ' ' . json_encode($invisiblePrefixes));
check($o['invisible_transient'] === 0,
    'a transient is declared by core.json\'s own ^_transient_ pattern, so it never reaches the invisible set — got '
    . var_export($o['invisible_transient'], true));

// captured means exactly "Duo writes this name into the artifact".
// agency_cs_settings + active_plugins + template + stylesheet + blogname
// + theme_mods_fixture-child = 6.
check($o['captured'] === 6,
    'captured counts the authored, the three managed and the active theme\'s dynamic row (6) — got '
    . var_export($o['captured'], true));

// declared and excluded, by class:
//   env     = agency_cs_api_key, siteurl, theme_mods_fixture-parent (residue)
//   derived = agency_cs_case_count, _transient_foo
//   runtime = agency_cs_cache_stamp, _wp_session_abc
check($o['declared_excluded'] === 7,
    'seven declared names have no writer and are counted excluded, not invisible — got '
    . var_export($o['declared_excluded'], true));
check($o['declared_excluded_by_class'] === ['derived' => 2, 'env' => 3, 'runtime' => 2],
    'the per-class split is published ksorted, every class of Policy::CLASSES minus authored/managed present — got '
    . json_encode($o['declared_excluded_by_class']));
check(array_sum($o['declared_excluded_by_class']) === $o['declared_excluded'],
    'the per-class split sums to the total it splits');

// The invariant the four buckets exist to satisfy: every row lands in exactly
// one of them (Coverage.php's options_report() docblock).
check($o['total'] === $o['captured'] + $o['declared_excluded'] + $o['pending'] + $o['invisible_total'],
    'total === captured + declared_excluded + pending + invisible_total — got '
    . $o['total'] . ' vs ' . ($o['captured'] + $o['declared_excluded'] + $o['pending'] + $o['invisible_total']));

if ($failures > 0) {
    fwrite(STDERR, "\n$failures check(s) FAILED\n");
    exit(1);
}
echo "\nALL PASSED\n";

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

require_once __DIR__ . '/lib/wp_stubs.php';
require_once __DIR__ . '/lib/FakeWpdb.php';
require __DIR__ . '/../../agent/src/Review/Coverage.php';

// The product path below is Coverage::report(), which loads a real Policy and
// therefore needs the two constants the drop-in binds at load. Read from
// agent/duo.php rather than restated, so a version bump does not edit this file.
$duo_coverage_bootstrap = (string) file_get_contents(__DIR__ . '/../../agent/duo.php');
if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', preg_match("/define\('DUO_SPEC_VERSION',\s*(\d+)\)/", $duo_coverage_bootstrap, $m) === 1
        ? (int) $m[1] : 2);
}
if (!defined('DUO_AGENT_VERSION')) {
    define('DUO_AGENT_VERSION', preg_match("/define\('DUO_AGENT_VERSION',\s*'([^']+)'\)/", $duo_coverage_bootstrap, $m) === 1
        ? $m[1] : '0.0.0');
}
require_once __DIR__ . '/../../agent/src/Policy/ManifestDispositions.php';
require_once __DIR__ . '/../../agent/src/Policy/Policy.php';

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

$report = Duo\Coverage::report($coverageScratch . '/repo');
check($report['format'] === Duo\Coverage::FORMAT, 'the product path emits the versioned coverage format');
check(($report['tables']['undeclared_total'] ?? null) === 1,
    'exactly one undeclared table is counted (core tables are excluded) — got '
    . var_export($report['tables']['undeclared_total'] ?? null, true));
$undeclared = $report['tables']['undeclared'];
check(count($undeclared) === 1, 'one published undeclared row — got ' . count($undeclared));
$rowKeys = array_keys($undeclared[0]);
sort($rowKeys, SORT_STRING);
check($rowKeys === ['logical_name', 'probable_owner', 'row_count', 'table'],
    'a published undeclared-table row carries exactly table, logical_name, row_count, probable_owner — got '
    . implode(',', $rowKeys));
check(($undeclared[0]['logical_name'] ?? null) === 'acme_catalog_index',
    'logical_name is the UNPREFIXED name `duo assess` builds `table:<name>` from — got '
    . var_export($undeclared[0]['logical_name'] ?? null, true));
check(($undeclared[0]['table'] ?? null) === 'wp_acme_catalog_index',
    'table stays the physical, prefixed name');
check((int) ($undeclared[0]['row_count'] ?? -1) === 2, 'row_count is the real COUNT(*)');
check(($undeclared[0]['probable_owner'] ?? null) === 'acme-catalog',
    'attribution still resolves the owning active plugin slug');

if ($failures > 0) {
    fwrite(STDERR, "\n$failures check(s) FAILED\n");
    exit(1);
}
echo "\nALL PASSED\n";

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

require __DIR__ . '/../../agent/src/Coverage.php';

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

if ($failures > 0) {
    fwrite(STDERR, "\n$failures check(s) FAILED\n");
    exit(1);
}
echo "\nALL PASSED\n";

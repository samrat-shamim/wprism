<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression harness for
 * DUO-3214(b) / task #123: Canon::normalize()'s alphabetical ksort()
 * permanently reorders any order-sensitive associative array a meta rule
 * doesn't explicitly protect — WooCommerce's variation-title generator
 * reads the parent's `_product_attributes` array order directly
 * (WC_Product_Variation_Data_Store_CPT::read()), so canonicalization
 * silently changed the generated title's word order on every applied
 * target (a real, permanent divergence — not #72/#88's self-heal timing
 * issue, which is a separate, already-closed bug).
 *
 * The fix: agent/src/Kernel/OrderPreserved.php (a marker wrapper) + Canon::
 * normalize()'s new branch that recognizes it and recurses without ever
 * calling ksort(), at any depth. Capture::build_post()'s post_meta loop
 * wraps a value in it when the meta rule declares "order_preserving":
 * true (spec v0.13, sibling to json_refs/cast — see manifests/
 * woocommerce.json's `_product_attributes` declaration).
 *
 * This harness proves the CORE mechanism (Canon.php + OrderPreserved.php,
 * both zero WordPress dependency) directly: a hand-built PHP array in
 * non-alphabetical key order, run through the real Canon::encode()/
 * decode() round trip, with and without the wrapper. The call-site wiring
 * (Capture::build_post() actually applying the wrapper when a manifest
 * rule says to) and the real end-to-end WooCommerce proof (a variable
 * product's variation TITLE literally converging byte-for-byte, not just
 * as an anagram) are live sandbox-pair evidence instead — see the PR body;
 * Capture::build() is not offline-stubbable end-to-end (same reasoning as
 * DUO-3213's Publish.php split and DUO-3214(a)'s guard_secret() split).
 *
 * Exit 0 and "ALL PASSED" on success; any failed check prints "FAIL: ..."
 * and the script exits 1.
 */

require __DIR__ . '/../../../../agent/src/Kernel/OrderPreserved.php';
require __DIR__ . '/../../../../agent/src/Kernel/Canon.php';

use Duo\Canon;
use Duo\OrderPreserved;

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

// A hand-built stand-in for WooCommerce's real `_product_attributes` shape
// (maybe_unserialize() of `a:2:{s:8:"pa_color";a:6:{...};s:7:"pa_size";a:6:{...}}`),
// deliberately created in NON-alphabetical order (pa_color before pa_size
// alphabetizes correctly by coincidence — swap to a case that doesn't:
// "zzz_last" created first, "aaa_first" created second, exactly the
// out-of-order case a merchant adding attributes in an arbitrary sequence
// would produce).
$productAttributes = [
    'zzz_last' => ['name' => 'zzz_last', 'value' => '', 'position' => 0, 'is_visible' => 1, 'is_variation' => 1, 'is_taxonomy' => 1],
    'aaa_first' => ['name' => 'aaa_first', 'value' => '', 'position' => 1, 'is_visible' => 1, 'is_variation' => 1, 'is_taxonomy' => 1],
];

// ======================================================================
// O1 -- baseline: WITHOUT the wrapper, the existing (pre-#123-fix) bug
// reproduces exactly as documented -- alphabetical resort, order lost.
// ======================================================================
echo "\n== O1: baseline (no OrderPreserved) reproduces the #123 bug exactly ==\n";

$plainJson = Canon::encode(['meta' => (object) ['_product_attributes' => $productAttributes]]);
$plainDecoded = Canon::decode($plainJson);
$plainKeys = array_keys($plainDecoded['meta']['_product_attributes']);
check($plainKeys === ['aaa_first', 'zzz_last'], 'O1a: unwrapped -- keys DO get alphabetically resorted (got: ' . json_encode($plainKeys) . ') -- confirms the bug exists in this Canon.php version absent the fix, i.e. this test is not vacuous');
check($plainKeys !== ['zzz_last', 'aaa_first'], 'O1b: sanity -- the resorted order is NOT the original creation order');

// ======================================================================
// O2 -- the fix: WITH the wrapper, original creation order survives the
// full encode -> JSON text -> decode round trip byte-for-byte.
// ======================================================================
echo "\n== O2: OrderPreserved -- original order survives the full round trip ==\n";

$wrappedJson = Canon::encode(['meta' => (object) ['_product_attributes' => new OrderPreserved($productAttributes)]]);
$wrappedDecoded = Canon::decode($wrappedJson);
$wrappedKeys = array_keys($wrappedDecoded['meta']['_product_attributes']);
check($wrappedKeys === ['zzz_last', 'aaa_first'], 'O2a: wrapped -- keys preserve the ORIGINAL creation order (got: ' . json_encode($wrappedKeys) . ')');

// The JSON TEXT itself must show zzz_last before aaa_first (not just the
// decoded array -- proving order survives in the actual file bytes on
// disk, not merely as an artifact of how this test happens to re-decode).
check(
    strpos($wrappedJson, 'zzz_last') < strpos($wrappedJson, 'aaa_first'),
    'O2b: the JSON TEXT itself has zzz_last before aaa_first (order is real in the file bytes, not just in a decoded PHP array)'
);

// ======================================================================
// O3 -- recursive: order preservation applies to EVERY nesting level
// inside the wrapped value, not just the top level (each attribute's own
// per-row map -- name/value/position/is_visible/is_variation/is_taxonomy
// -- must also keep ITS OWN insertion order, matching the real captured
// bytes' shape: a genuine array literal written in a specific field
// order, not a normalized/resorted one).
// ======================================================================
echo "\n== O3: order preservation is recursive (every nesting level) ==\n";

$nestedOutOfOrder = [
    'zzz_last' => ['is_taxonomy' => 1, 'name' => 'zzz_last', 'is_visible' => 1],
];
$json = Canon::encode(['meta' => (object) ['_x' => new OrderPreserved($nestedOutOfOrder)]]);
$decoded = Canon::decode($json);
$innerKeys = array_keys($decoded['meta']['_x']['zzz_last']);
check($innerKeys === ['is_taxonomy', 'name', 'is_visible'], 'O3: the INNER row keeps its own out-of-alphabetical-order field order too (got: ' . json_encode($innerKeys) . ')');

// ======================================================================
// O4 -- scoped, not global: everything else in the SAME document still
// gets the ordinary alphabetical treatment. Order preservation is an
// opt-in per declared value, never a document-wide behavior change.
// ======================================================================
echo "\n== O4: order preservation is scoped -- the REST of the document is unaffected ==\n";

$doc = [
    'meta' => (object) [
        '_product_attributes' => new OrderPreserved($productAttributes),
        'zzz_ordinary_key' => 'value1',
        'aaa_ordinary_key' => 'value2',
    ],
];
$json = Canon::encode($doc);
$decoded = Canon::decode($json);
$metaKeys = array_keys($decoded['meta']);
check(
    $metaKeys === ['_product_attributes', 'aaa_ordinary_key', 'zzz_ordinary_key'],
    'O4a: sibling meta KEYS (the outer `meta` map itself) are still alphabetically sorted as normal (got: ' . json_encode($metaKeys) . ')'
);
check(
    array_keys($decoded['meta']['_product_attributes']) === ['zzz_last', 'aaa_first'],
    'O4b: only the WRAPPED value itself keeps its own creation order, unaffected by its sorted siblings'
);

// ======================================================================
// O5 -- an empty wrapped array still round-trips as `{}` matching
// normalize()'s existing empty-object convention for a *keyed* structure
// context (front-matter fields are always JSON objects, never arrays,
// once populated) -- but since PHP can't distinguish an empty list from
// an empty map, and the SOURCE value here (an EMPTY array()) is
// technically a list by array_is_list()'s own definition, this documents
// the actual (list-shaped, `[]`) behavior rather than asserting a
// stronger guarantee normalize_preserving_order() does not itself make --
// consistent with how the UNWRAPPED path already behaves for the exact
// same reason (see Canon::post_hash_basis()'s own docblock on this).
// ======================================================================
echo "\n== O5: empty wrapped value ==\n";
$json = Canon::encode(['meta' => (object) ['_empty' => new OrderPreserved([])]]);
check(str_contains($json, '"_empty": []'), 'O5: an empty wrapped array encodes as [] (PHP cannot distinguish an empty list from an empty map -- documented, not a new limitation this fix introduces)');

// ======================================================================
// O6 -- determinism: encoding the identical wrapped value twice produces
// byte-identical JSON (capture-twice determinism must hold for an order-
// preserving value exactly as it already does for an ordinary one).
// ======================================================================
echo "\n== O6: determinism ==\n";
$a = Canon::encode(['meta' => (object) ['_product_attributes' => new OrderPreserved($productAttributes)]]);
$b = Canon::encode(['meta' => (object) ['_product_attributes' => new OrderPreserved($productAttributes)]]);
check($a === $b, 'O6: encoding the same order-preserved value twice is byte-identical');

// ======================================================================
echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);

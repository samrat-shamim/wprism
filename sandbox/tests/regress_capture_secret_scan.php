<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression harness for
 * DUO-3214(a): Capture::guard_secret()'s authored post-meta and option call
 * sites used to
 * gate the guard behind `is_string($v)` — an authored value that decoded to
 * an ARRAY (a plugin's serialized settings blob) got ZERO secret scanning
 * in any downstream branch (decode_structured()/Tokens::struct_capture()
 * touch no Secrets:: call at all). guard_secret() itself now deep-scans via
 * Secrets::hard_match_deep() (widened from hard_match()), matching the
 * pattern Snapshot::guard_secret() and the option_name_refs inline scan
 * already proved in the wave-1 security subset, and both call sites no
 * longer gate the call on is_string().
 *
 * guard_secret() has zero WordPress dependency (pure PHP + Secrets::) and
 * is a PRIVATE instance method on a class whose constructor is ALSO
 * private (Capture's only public entry points are the static run()/
 * snapshot()/gate_scan() factories, none suited to a narrow unit test of
 * this one method) -- so this harness uses Reflection to construct a
 * Capture instance WITHOUT running its constructor (only $repo is ever
 * read by guard_secret() itself, set directly via ReflectionProperty) and
 * invoke the private method directly. This proves the CORE logic change in
 * total isolation; the call-site wiring itself (that the extracted entity
 * and options capturers call guard_secret() unconditionally now) is a
 * live sandbox-pair proof instead, in the PR body -- Capture::build() is
 * not designed to be offline-stubbable end-to-end the way agent/src/
 * Publish.php was for DUO-3213 (this file intentionally does not attempt
 * a FakeWpdb covering posts/terms/menus/options/tables; that is a much
 * larger undertaking than this fix warrants).
 *
 * Exit 0 and "ALL PASSED" on success; any failed check prints "FAIL: ..."
 * and the script exits 1.
 */

require __DIR__ . '/../../agent/src/Secrets.php';
require __DIR__ . '/../../agent/src/Capture.php';

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

// ---------------------------------------------------------------- fixture

/**
 * @param string $section 'post_meta' or 'options' -- guard_secret()'s
 *   first positional argument, only used for the thrown message text.
 * @param mixed $v the (already assert_plain()-checked-shape) value
 * @param array $rule the meta/option rule (only 'allow_secret' matters here)
 * @return string|null the caught RuntimeException message, or null if
 *   guard_secret() returned normally (no secret found / allow_secret hit)
 */
function invoke_guard_secret(string $section, string $key, $v, array $rule, string $context = ''): ?string {
    // No setAccessible() calls: a no-op since PHP 8.1 (private members are
    // directly Reflection-accessible since then) and deprecated outright
    // in 8.5 — this repo's target runtimes span both.
    $ref = new ReflectionClass(Duo\Capture::class);
    $instance = $ref->newInstanceWithoutConstructor();
    $repoProp = $ref->getProperty('repo');
    $repoProp->setValue($instance, '/siterepo');

    $method = $ref->getMethod('guard_secret');
    try {
        $method->invoke($instance, $section, $key, $v, $rule, $context);
        return null;
    } catch (\RuntimeException $e) {
        return $e->getMessage();
    }
}

// ======================================================================
// S1 -- scalar-string secret: UNCHANGED behavior (regression baseline --
// this worked before the fix too, must keep working identically after).
// ======================================================================
echo "\n== S1: scalar string secret (baseline, pre-existing behavior) ==\n";

$msg = invoke_guard_secret('post_meta', 'my_api_key', 'sk_live_ABCDEF1234567890', []);
check($msg !== null, 'S1a: a bare Stripe-shaped string still trips the guard');
check($msg !== null && str_contains($msg, 'stripe key'), 'S1a: message names the correct label (got: ' . ($msg ?? 'null') . ')');
check($msg !== null && str_contains($msg, "post_meta 'my_api_key'"), 'S1a: message names the section and key');

$msg = invoke_guard_secret('options', 'blogname', 'Ordinary Site Name', []);
check($msg === null, 'S1b: an ordinary string is never flagged');

// ======================================================================
// S2 -- array-shaped secret: THE FIX. Before DUO-3214(a), the is_string()
// gate at both call sites meant this never even reached guard_secret() at
// all; now it does, and guard_secret() itself deep-scans it.
// ======================================================================
echo "\n== S2: array-shaped secret (the actual defect this closes) ==\n";

$flatRateSettings = ['title' => 'Flat rate', 'cost' => '5.00', 'tax_status' => 'taxable'];
$msg = invoke_guard_secret('options', 'woocommerce_flat_rate_settings', $flatRateSettings, []);
check($msg === null, 'S2a: an ordinary array (no secret anywhere inside) is never flagged (regression baseline for the widened path)');

$withSecret = ['title' => 'Flat rate', 'api_key' => 'sk_live_DUOFAKE1234567890TEST', 'cost' => '5.00'];
$msg = invoke_guard_secret('options', 'woocommerce_flat_rate_settings', $withSecret, []);
check($msg !== null, 'S2b: a secret ONE LEVEL INSIDE an array now trips the guard (was silently missed before this fix)');
check($msg !== null && str_contains($msg, 'stripe key'), 'S2b: message names the correct label');

$nested = ['gateway' => ['stripe' => ['test_mode' => false, 'secret_key' => 'sk_live_NESTEDDEEP7890123456']], 'active' => true];
$msg = invoke_guard_secret('post_meta', '_payment_config', $nested, []);
check($msg !== null, 'S2c: a secret THREE LEVELS deep inside a nested array now trips the guard');
check($msg !== null && str_contains($msg, "post_meta '_payment_config'"), 'S2c: message still names the correct section/key even when the match was nested');

$listOfStrings = ['conf-color', 'conf-size', 'sk_live_INALISTNOTAMAP998877'];
$msg = invoke_guard_secret('post_meta', '_variation_attrs', $listOfStrings, []);
check($msg !== null, 'S2d: a secret inside a plain LIST (not just an associative map) is caught -- hard_match_deep() walks any array shape');

// ======================================================================
// S3 -- allow_secret escapes an array-shaped value exactly like a scalar
// (DUO-3214's stated care point: "allow_secret for arrays" -- confirmed
// here to already fall out correctly from the widening, no extra code
// needed, since the allow_secret short-circuit runs before any type-
// specific scan logic).
// ======================================================================
echo "\n== S3: allow_secret escapes arrays too ==\n";

$msg = invoke_guard_secret('options', 'woocommerce_flat_rate_settings', $withSecret, ['allow_secret' => true]);
check($msg === null, 'S3a: allow_secret=true short-circuits BEFORE scanning, even for an array containing a real secret');

$msg = invoke_guard_secret('post_meta', '_payment_config', $nested, ['allow_secret' => false]);
check($msg !== null, 'S3b: allow_secret=false (explicit) still scans normally (sanity: false is not truthy-empty-skipped)');

// ======================================================================
// S4 -- non-string, non-array scalars never false-positive or crash
// (int/float/bool/null -- exactly what assert_plain() guarantees $v can be
// besides string/array, since it throws on any PHP object anywhere in the
// structure before guard_secret() is ever reached at either call site).
// ======================================================================
echo "\n== S4: non-string, non-array scalars (int/float/bool/null) never crash or false-positive ==\n";

foreach ([0 => 42, 1 => 3.14, 2 => true, 3 => false, 4 => null] as $label => $v) {
    $threw = null;
    try {
        $msg = invoke_guard_secret('options', 'some_numeric_option', $v, []);
    } catch (\Throwable $t) {
        $threw = $t;
    }
    check($threw === null, "S4.$label: guard_secret() does not crash on scalar type " . get_debug_type($v));
    check($threw === null && $msg === null, "S4.$label: guard_secret() never flags a non-string, non-array scalar");
}

// A mixed array containing exactly one of these alongside a real string
// leaf, to prove the recursive walk skips non-string leaves silently
// rather than erroring on them.
$mixedShapes = ['count' => 5, 'enabled' => true, 'ratio' => 1.5, 'notes' => null, 'flag' => 'sk_live_MIXEDSHAPEOK112233'];
$msg = invoke_guard_secret('post_meta', '_mixed', $mixedShapes, []);
check($msg !== null, 'S4b: a real secret is still found inside an array that ALSO contains int/bool/float/null siblings');

// ======================================================================
// S5 -- determinism: identical input twice yields identical outcome
// (cheap but direct -- guard_secret() must be a pure function of ($v, rule)).
// ======================================================================
echo "\n== S5: determinism ==\n";
$a = invoke_guard_secret('post_meta', '_payment_config', $nested, []);
$b = invoke_guard_secret('post_meta', '_payment_config', $nested, []);
check($a === $b, 'S5: scanning the identical value twice produces the identical result');

// ======================================================================
echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);

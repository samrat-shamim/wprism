<?php
/**
 * WP-5.5 — a plugin-claim collision is an operator RESOLUTION, not a load
 * refusal (spec/repo-format.md § v3.13).
 *
 * Two pinned manifests claiming one plugin (or one theme) with different
 * ranges refused outright, because `Policy::version_ranges()` resolves by pin
 * order and "manifest precedence may never depend on pin order". This suite
 * measures the one thing that changed and, at greater length, everything that
 * did not:
 *
 *  - WITH an explicit `site.wprism.json` `policy.adapter_claims` resolution the
 *    pin set loads, the named manifest's range is what bounds the subject in
 *    BOTH pin orders, and the displaced claimant is REPORTED with the
 *    `displaced_by_resolution` code — never hidden;
 *  - WITHOUT one the refusal stands, and its message is asserted against a
 *    LITERAL here rather than against a regenerated string, so a later edit to
 *    the sentence fails this suite instead of quietly moving what every
 *    existing repository sees (AGENTS.md rule 8);
 *  - identical ranges stay tolerated as redundant, with and without a
 *    resolution;
 *  - the resolution is RESOLUTION ONLY: the displaced manifest stays pinned
 *    and loaded with every other declaration it makes intact, exactly one
 *    range is in force, nothing is merged, and an unrelated cross-manifest
 *    conflict in the same pin set still refuses;
 *  - a resolution that decides nothing, or that names a manifest making no
 *    such claim, refuses — the drift case, not the typo case.
 *
 * Everything runs through the real `Policy::load()` against real manifest
 * files in an explicit scratch `AdapterLibrary` and a real `site.wprism.json`, the same
 * product path a site takes; no validator is called directly except where a
 * check is explicitly about one collaborator's own seam.
 *
 * Exit 0 and "ALL PASSED" on success; any failed check prints "FAIL: ..." and
 * the script exits 1.
 */

// WordPress supplies this in production; Policy::load()'s single-site gate
// calls it before anything else. Same switchable stub the adapter-contract
// suite uses, for the same reason: no WordPress bootstrap offline.
$GLOBALS['wprism_test_is_multisite'] = false;
function is_multisite(): bool {
    return (bool) $GLOBALS['wprism_test_is_multisite'];
}

require __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../policy/manifest_fixtures.php';

use WPrism\AdapterClaimResolutions;
use WPrism\AdapterLibrary;
use WPrism\Canon;
use WPrism\Policy;

if (!defined('WPRISM_SPEC_VERSION')) {
    define('WPRISM_SPEC_VERSION', 0);
}

$failures = 0;
$asserted = 0;

function check(bool $cond, string $msg): void {
    global $failures, $asserted;
    $asserted++;
    if ($cond) {
        echo "ok: $msg\n";
    } else {
        fwrite(STDERR, "FAIL: $msg\n");
        $failures++;
    }
}

function check_same(string $expected, string $actual, string $msg): void {
    if ($expected === $actual) {
        check(true, $msg);
        return;
    }
    check(false, "$msg\n     expected: $expected\n     actual:   $actual");
}

function expect_throw(callable $fn, string $needle, string $msg): void {
    try {
        $fn();
        check(false, "$msg (expected a RuntimeException containing '$needle', none thrown)");
    } catch (\RuntimeException $e) {
        check(str_contains($e->getMessage(), $needle), "$msg (message: {$e->getMessage()})");
    }
}

/** The exact message of the refusal a call raises, or '' when it does not raise. */
function refusal_message(callable $fn): string {
    try {
        $fn();
        return '';
    } catch (\RuntimeException $e) {
        return $e->getMessage();
    }
}

$scratch = [];

/** A scratch manifest library; the whole set is written fresh per group. */
function library(array $manifests): void {
    global $scratch;
    $root = sys_get_temp_dir() . '/wprism_regress_claim_resolution_lib_' . bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    foreach ($manifests as $name => $manifest) {
        Canon::write_file("$root/$name.json", Canon::encode($manifest));
    }
    $scratch[] = $root;
    $GLOBALS['claim_resolution_adapter_library'] = manifest_fixture_adapter_library($root);
}

/** Load claims through the exact fixture inventory selected by library(). */
function claim_policy_load(?string $repo, ?array $names = null): Policy {
    $library = $GLOBALS['claim_resolution_adapter_library'] ?? null;
    if (!$library instanceof AdapterLibrary) {
        throw new \RuntimeException('claim-resolution fixture selected no adapter library');
    }
    return Policy::load($repo, $names, adapterLibrary: $library);
}

/** A scratch repository carrying only the pins and the policy under test. */
function repo(array $pins, array $policy = []): string {
    global $scratch;
    $root = sys_get_temp_dir() . '/wprism_regress_claim_resolution_repo_' . bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    Canon::write_file("$root/site.wprism.json", Canon::encode([
        'manifests' => $pins,
        'policy' => $policy === [] ? new \stdClass() : $policy,
        'spec_version' => WPRISM_SPEC_VERSION,
    ]));
    $scratch[] = $root;
    return $root;
}

register_shutdown_function(static function (): void {
    global $scratch;
    foreach ($scratch as $root) {
        manifest_fixture_remove_tree($root);
    }
});

/** The two colliding plugin claimants every group below reuses. */
const PLUGIN = 'acme/acme.php';
const RANGE_A = ['min' => '1.0.0', 'max' => '2.0.0'];
const RANGE_B = ['min' => '2.0.0', 'max' => '3.0.0'];

function plugin_manifest(string $name, array $range, array $extra = []): array {
    return [
        'name' => $name,
        'spec_version' => WPRISM_SPEC_VERSION,
        'plugin' => PLUGIN,
        'version_range' => $range,
    ] + $extra;
}

function resolution(string $kind, string $id, string $inForce, ?string $note = null): array {
    $row = ['in_force' => $inForce];
    if ($note !== null) {
        $row['note'] = $note;
    }
    return ['adapter_claims' => [$kind => [$id => $row]]];
}

// The sentence a repository with no resolution has always received. Written
// out in full, on purpose: this is the byte-identity assertion, and deriving
// it from the engine would assert only that the engine agrees with itself.
// The `{"max":…,"min":…}` ordering is Canon's own key sort on the manifest
// bytes the engine re-encodes, not a typo.
const UNRESOLVED_PLUGIN_REFUSAL = "wprism: manifests 'conf-a' and 'conf-b' both declare plugin 'acme/acme.php' "
    . 'with different version_range values ({"max":"2.0.0","min":"1.0.0"} vs {"max":"3.0.0","min":"2.0.0"}) '
    . '— conflicting ownership with no v2 composition rule; pin only one, or narrow one range to a disjoint '
    . 'window';

const UNRESOLVED_THEME_REFUSAL = "wprism: manifests 'theme-a' and 'theme-b' both declare theme 'acme-theme' "
    . 'with different theme_version_range values ({"max":"2.0.0","min":"1.0.0"} vs '
    . '{"max":"10.0.0","min":"9.0.0"}) — conflicting ownership with no v2 composition rule; pin only one, '
    . 'or narrow one range to a disjoint window';

// ======================================================================
echo "\n== WITHOUT a resolution the refusal stands, byte for byte ==\n";

library([
    'conf-a' => plugin_manifest('conf-a', RANGE_A),
    'conf-b' => plugin_manifest('conf-b', RANGE_B),
]);

check_same(
    UNRESOLVED_PLUGIN_REFUSAL,
    refusal_message(fn() => claim_policy_load(repo(['conf-a', 'conf-b']))),
    'a repository declaring NO policy.adapter_claims gets the pre-WP-5.5 message, byte for byte'
);

check_same(
    UNRESOLVED_PLUGIN_REFUSAL,
    refusal_message(fn() => claim_policy_load(repo(['conf-a', 'conf-b'], ['adapter_claims' => new \stdClass()]))),
    'an EMPTY policy.adapter_claims object admits nothing and gets the identical message'
);

// A resolution is scoped to the claim it names. Two collisions in one pin set,
// one of them resolved: the OTHER still refuses, with its own message intact.
library([
    'conf-a' => plugin_manifest('conf-a', RANGE_A),
    'conf-b' => plugin_manifest('conf-b', RANGE_B),
    'oth-a' => ['name' => 'oth-a', 'spec_version' => WPRISM_SPEC_VERSION, 'plugin' => 'other/other.php', 'version_range' => RANGE_A],
    'oth-b' => ['name' => 'oth-b', 'spec_version' => WPRISM_SPEC_VERSION, 'plugin' => 'other/other.php', 'version_range' => RANGE_B],
]);
check_same(
    UNRESOLVED_PLUGIN_REFUSAL,
    refusal_message(fn() => claim_policy_load(
        repo(['conf-a', 'conf-b', 'oth-a', 'oth-b'], resolution('plugin', 'other/other.php', 'oth-a'))
    )),
    'a resolution about a DIFFERENT plugin does not answer this collision'
);

library([
    'conf-a' => plugin_manifest('conf-a', RANGE_A),
    'conf-b' => plugin_manifest('conf-b', RANGE_B),
]);

// The pin-order independence the guard was built for is unchanged: the
// refusal does not depend on which manifest is pinned first, only its two
// names swap.
check(
    str_contains(
        refusal_message(fn() => claim_policy_load(repo(['conf-b', 'conf-a']))),
        'conflicting ownership with no v2 composition rule'
    ),
    'the unresolved refusal is still pin-order-independent'
);

// ======================================================================
echo "\n== WITH a resolution the pin set LOADS, and the named claim is the one in force ==\n";

$resolved = ['adapter_claims' => ['plugin' => [PLUGIN => ['in_force' => 'conf-b', 'note' => 'acme 2.x is what this site runs']]]];

$p = claim_policy_load(repo(['conf-a', 'conf-b'], $resolved));
check(true, 'two manifests claiming one plugin with different ranges LOAD when a resolution names one');

$ranges = $p->version_ranges();
check(count($ranges) === 1, 'exactly ONE range is in force for the claimed plugin — resolution, never composition');
check(
    ($ranges[PLUGIN] ?? null) === ['min' => '2.0.0', 'max' => '3.0.0', 'manifest' => 'conf-b'],
    "version_ranges() returns the IN-FORCE manifest's range, not the first pin's"
);

// The whole point of naming a winner: the answer stops depending on the pin
// list's order. Pinned the other way round it is the same range.
$reversed = claim_policy_load(repo(['conf-b', 'conf-a'], $resolved));
check(
    $reversed->version_ranges()[PLUGIN] === ['min' => '2.0.0', 'max' => '3.0.0', 'manifest' => 'conf-b'],
    'the in-force range is identical with the pins REVERSED — pin order decides nothing once a resolution exists'
);

// And a resolution naming the FIRST-pinned manifest is honoured just as
// literally, so "the resolution won" can never be confused with "pin order
// happened to agree with it".
$firstWins = claim_policy_load(repo(['conf-a', 'conf-b'], resolution('plugin', PLUGIN, 'conf-a')));
check(
    $firstWins->version_ranges()[PLUGIN] === ['min' => '1.0.0', 'max' => '2.0.0', 'manifest' => 'conf-a'],
    'a resolution naming the other claimant puts THAT range in force'
);

// ======================================================================
echo "\n== the displaced claim is REPORTED, never hidden ==\n";

$displaced = $p->displaced_adapter_claims();
check(count($displaced) === 1, 'exactly one displaced claim is reported');
$row = $displaced[0] ?? [];
check(($row['kind'] ?? null) === 'plugin', 'the displaced row names the claim KIND');
check(($row['id'] ?? null) === PLUGIN, 'the displaced row names the claimed plugin');
check(
    ($row['reason_code'] ?? null) === 'displaced_by_resolution',
    'the reason code is `displaced_by_resolution` — the shadowed_by_site word family, for a claim rather than an adapter'
);
check(($row['in_force'] ?? null) === 'conf-b', 'the displaced row names the manifest in force');
check(($row['displaced'] ?? null) === 'conf-a', 'the displaced row names the displaced manifest');
check(
    ($row['displaced_range'] ?? null) === ['min' => '1.0.0', 'max' => '2.0.0'],
    'the displaced row carries the range that no longer bounds the plugin'
);
check(
    ($row['in_force_range'] ?? null) === ['min' => '2.0.0', 'max' => '3.0.0'],
    'the displaced row carries the range that does'
);
check(
    ($row['note'] ?? null) === 'acme 2.x is what this site runs',
    "the operator's own note travels with the report"
);

check(
    AdapterClaimResolutions::DISPLACED_BY_RESOLUTION === 'displaced_by_resolution',
    'the reason code has one definition and the report reads it'
);

// A site with no resolution at all reports nothing, so the accessor cannot
// become a source of noise on the 100% of repositories that never use it.
library(['solo' => plugin_manifest('solo', RANGE_A)]);
check(
    claim_policy_load(repo(['solo']))->displaced_adapter_claims() === [],
    'a repository with no resolutions reports no displaced claims'
);

// ======================================================================
echo "\n== RESOLUTION ONLY: the displaced manifest is still pinned, still loaded, unmerged ==\n";

library([
    'conf-a' => plugin_manifest('conf-a', RANGE_A, [
        'option_autoload' => 'preserve',
        'options' => ['acme_a_only' => ['class' => 'authored']],
    ]),
    'conf-b' => plugin_manifest('conf-b', RANGE_B, [
        'option_autoload' => 'preserve',
        'options' => ['acme_b_only' => ['class' => 'authored']],
    ]),
]);
$p = claim_policy_load(repo(['conf-a', 'conf-b'], resolution('plugin', PLUGIN, 'conf-b')));

check(count($p->manifests) === 2, 'BOTH manifests are still loaded — a displaced claim unloads no adapter');
check(
    isset($p->authored_options()['acme_a_only']),
    "the DISPLACED manifest's other declarations still govern — only its plugin claim was displaced"
);
check(
    isset($p->authored_options()['acme_b_only']),
    "the in-force manifest's declarations are untouched"
);
check(
    $p->version_ranges()[PLUGIN]['manifest'] === 'conf-b'
        && count($p->version_ranges()) === 1,
    'no range is merged, intersected or unioned: one claim is in force and the other is not'
);

// The resolution is scoped to the CLAIM. An unrelated cross-manifest conflict
// in the same pin set is untouched by it — a claim resolution is not an
// amnesty for the pin set.
library([
    'conf-a' => plugin_manifest('conf-a', RANGE_A, [
        'post_types' => ['acme_thing' => ['class' => 'authored']],
    ]),
    'conf-b' => plugin_manifest('conf-b', RANGE_B, [
        'post_types' => ['acme_thing' => ['class' => 'derived']],
    ]),
]);
expect_throw(
    fn() => claim_policy_load(repo(['conf-a', 'conf-b'], resolution('plugin', PLUGIN, 'conf-b'))),
    'exactly one owner',
    'a plugin-claim resolution does NOT resolve an unrelated post-type contract conflict in the same set'
);

// ======================================================================
echo "\n== identical ranges stay tolerated as redundant, with and without a resolution ==\n";

library([
    'dup-a' => plugin_manifest('dup-a', RANGE_A),
    'dup-b' => plugin_manifest('dup-b', RANGE_A),
]);
$dup = claim_policy_load(repo(['dup-a', 'dup-b']));
check(true, 'two manifests claiming one plugin with the IDENTICAL range still load with no resolution');
check($dup->displaced_adapter_claims() === [], 'and report no displaced claim, because nobody decided anything');
check(
    $dup->version_ranges()[PLUGIN]['manifest'] === 'dup-a',
    'the redundant pair keeps its historical first-pin-order answer when nothing is declared'
);

// Redundant is not the same as decided: naming one of them is legal and makes
// WHICH manifest answers for the plugin deterministic rather than positional.
$dupResolved = claim_policy_load(repo(['dup-a', 'dup-b'], resolution('plugin', PLUGIN, 'dup-b')));
check(
    $dupResolved->version_ranges()[PLUGIN]['manifest'] === 'dup-b',
    'a resolution over an identical-range pair makes the answering manifest the declared one'
);
check(
    count($dupResolved->displaced_adapter_claims()) === 1,
    'and the redundant claimant is reported as displaced, so the map can be reconciled against the pin list'
);

// ======================================================================
echo "\n== the theme arm mirrors the plugin arm exactly ==\n";

$themeA = [
    'name' => 'theme-a',
    'spec_version' => WPRISM_SPEC_VERSION,
    'theme' => 'acme-theme',
    'theme_version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
];
$themeB = [
    'name' => 'theme-b',
    'spec_version' => WPRISM_SPEC_VERSION,
    'theme' => 'acme-theme',
    'theme_version_range' => ['min' => '9.0.0', 'max' => '10.0.0'],
];
library(['theme-a' => $themeA, 'theme-b' => $themeB]);

check_same(
    UNRESOLVED_THEME_REFUSAL,
    refusal_message(fn() => claim_policy_load(repo(['theme-a', 'theme-b']))),
    'an unresolved THEME collision keeps its own message byte for byte'
);

$t = claim_policy_load(repo(['theme-a', 'theme-b'], resolution('theme', 'acme-theme', 'theme-b')));
check(
    $t->theme_ranges()['acme-theme'] === ['min' => '9.0.0', 'max' => '10.0.0', 'manifest' => 'theme-b'],
    'theme_ranges() returns the in-force theme claim'
);
check(
    ($t->displaced_adapter_claims()[0]['kind'] ?? null) === 'theme'
        && ($t->displaced_adapter_claims()[0]['displaced'] ?? null) === 'theme-a',
    'the displaced theme claimant is reported with kind "theme"'
);

// A plugin resolution never reaches a theme claim, or the reverse: the arms
// are keyed apart because a plugin basename and a theme directory are
// different namespaces that could otherwise collide on one string. Keying
// them apart is what makes a mis-armed resolution refuse BY NAME instead of
// silently deciding the wrong collision — the answer here is the stale-
// resolution refusal, not the theme conflict, because the operator's own file
// is what is wrong.
check(
    str_contains(
        refusal_message(fn() => claim_policy_load(repo(['theme-a', 'theme-b'], resolution('plugin', 'acme-theme', 'theme-b')))),
        "policy.adapter_claims.plugin.acme-theme resolves nothing — 0 pinned manifest(s) claim plugin 'acme-theme'"
    ),
    'a PLUGIN resolution for a THEME identity resolves nothing and says so — the two arms never cross'
);

// ======================================================================
echo "\n== a resolution that decides nothing REFUSES (the drift case) ==\n";

library(['solo' => plugin_manifest('solo', RANGE_A)]);
expect_throw(
    fn() => claim_policy_load(repo(['solo'], resolution('plugin', PLUGIN, 'solo'))),
    'resolves nothing',
    'a resolution left behind after one of its manifests was unpinned refuses instead of looking like a live decision'
);
expect_throw(
    fn() => claim_policy_load(repo(['solo'], resolution('plugin', 'gone/gone.php', 'solo'))),
    'resolves nothing',
    'a resolution for a plugin no pinned manifest claims at all refuses'
);

library([
    'conf-a' => plugin_manifest('conf-a', RANGE_A),
    'conf-b' => plugin_manifest('conf-b', RANGE_B),
    'bystander' => ['name' => 'bystander', 'spec_version' => WPRISM_SPEC_VERSION],
]);
expect_throw(
    fn() => claim_policy_load(repo(['conf-a', 'conf-b', 'bystander'], resolution('plugin', PLUGIN, 'bystander'))),
    'may only choose among the claims that were made',
    'a resolution naming a pinned manifest that makes no such claim refuses — it may choose, never install'
);
expect_throw(
    fn() => claim_policy_load(repo(['conf-a', 'conf-b'], resolution('plugin', PLUGIN, 'not-pinned-at-all'))),
    'may only choose among the claims that were made',
    'a resolution naming a manifest that is not pinned refuses through the same sentence'
);

// The stale-resolution refusal wins over the collision refusal, because the
// operator's own file is the thing they can act on.
check(
    str_contains(
        refusal_message(fn() => claim_policy_load(repo(['conf-a', 'conf-b'], resolution('plugin', 'gone/gone.php', 'conf-a')))),
        'resolves nothing'
    ),
    'a stale resolution is reported ahead of the collision it does not answer'
);

// ======================================================================
echo "\n== the section's own grammar refuses by name, before any pin resolves ==\n";

library([
    'conf-a' => plugin_manifest('conf-a', RANGE_A),
    'conf-b' => plugin_manifest('conf-b', RANGE_B),
]);

expect_throw(
    fn() => claim_policy_load(repo(['conf-a', 'conf-b'], ['adapter_claims' => ['widget' => [PLUGIN => ['in_force' => 'conf-b']]]])),
    "declares claim kind 'widget'",
    'an unknown claim kind refuses BY NAME rather than being ignored'
);
expect_throw(
    fn() => claim_policy_load(repo(['conf-a', 'conf-b'], ['adapter_claims' => ['plugin' => 'conf-b']])),
    'policy.adapter_claims.plugin must be a JSON object',
    'a scalar arm refuses'
);
expect_throw(
    fn() => claim_policy_load(repo(['conf-a', 'conf-b'], ['adapter_claims' => [['plugin' => []]]])),
    'policy.adapter_claims must be a JSON object',
    'a JSON array in place of the section refuses'
);
expect_throw(
    fn() => claim_policy_load(repo(['conf-a', 'conf-b'], ['adapter_claims' => ['plugin' => [PLUGIN => ['in_force' => 'conf-b', 'version_range' => RANGE_A]]]])),
    'a claim resolution accepts exactly in_force and note',
    'a resolution restating a RANGE refuses — a resolution chooses a claim, it never authors one'
);
expect_throw(
    fn() => claim_policy_load(repo(['conf-a', 'conf-b'], ['adapter_claims' => ['plugin' => [PLUGIN => ['note' => 'no winner named']]]])),
    'must declare a non-empty string in_force',
    'a resolution with no in_force refuses'
);
expect_throw(
    fn() => claim_policy_load(repo(['conf-a', 'conf-b'], ['adapter_claims' => ['plugin' => [PLUGIN => ['in_force' => 'conf-b', 'note' => 7]]]])),
    'non-string note',
    'a non-string note refuses'
);
expect_throw(
    fn() => claim_policy_load(repo(['conf-a', 'conf-b'], ['adapter_claims' => ['plugin' => [PLUGIN => 'conf-b']]])),
    'must be an object with a non-empty string in_force',
    'a bare-string resolution refuses — there is one shape, not a string/object polymorphism'
);

// ======================================================================
echo "\n== the frozen loader resolves identically (one finalizer, two entry points) ==\n";

$live = claim_policy_load(repo(['conf-a', 'conf-b'], resolution('plugin', PLUGIN, 'conf-b')));
$frozen = Policy::from_snapshot($live->export_snapshot(), $GLOBALS['claim_resolution_adapter_library']);
check(
    $frozen->version_ranges() === $live->version_ranges(),
    'a frozen snapshot puts the same claim in force as the live load it was exported from'
);
check(
    $frozen->displaced_adapter_claims() === $live->displaced_adapter_claims(),
    'and reports the same displaced claim'
);

// ======================================================================
echo "\n== the seams: one guard call site, one plan-warning reader ==\n";

$root = dirname(__DIR__, 4);
$finalizer = (string) file_get_contents($root . '/agent/src/Policy/PolicyLoadFinalizer.php');
check(
    substr_count($finalizer, 'AdapterClaimResolutions::assert_binds(') === 1
        && substr_count($finalizer, 'AdapterContractGrammar::validate_no_conflicting_adapter_claims(') === 1
        && strpos($finalizer, 'AdapterClaimResolutions::assert_binds(')
            < strpos($finalizer, 'AdapterContractGrammar::validate_no_conflicting_adapter_claims('),
    'the finalizer binds the resolutions BEFORE the guard they answer, in exactly one place'
);

$planBuilder = (string) file_get_contents($root . '/agent/src/Apply/ApplyPlanBuilder.php');
check(
    substr_count($planBuilder, '$this->policy->displaced_adapter_claims()') === 1
        && str_contains($planBuilder, '$this->warnings[] = "displaced:'),
    'the plan renders every displaced claim as a plain warning — loud, and never an ok-flipping bucket'
);
check(
    !str_contains($planBuilder, "displaced_adapter_claims()) {\n            \$plan['ok'] = false"),
    'a resolved collision never flips the plan red: it is a decision, not a defect'
);

// The collision refusal is now a function of the site half of policy, so
// `wprism manifest-validate` run WITHOUT --site must annotate it as possibly
// site-resolvable. The list that decides is a constant, and a list that lagged
// the engine would send an author to add an override they already have.
$manifestValidate = (string) file_get_contents($root . '/cli/src/Adapter/ManifestValidate.php');
check(
    str_contains($manifestValidate, "'conflicting ownership with no v2 composition rule',"),
    'manifest-validate counts the collision refusal among the site-sensitive ones'
);

// ======================================================================
if ($failures > 0) {
    fwrite(STDERR, "\n$failures CHECK(S) FAILED\n");
    exit(1);
}
if ($asserted === 0) {
    fwrite(STDERR, "\nNO CHECKS RAN\n");
    exit(1);
}
echo "\nALL PASSED ($asserted checks)\n";

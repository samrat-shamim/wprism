<?php
/**
 * Offline regression for SubKeyGrammar (DUO-3348 slice 8: the "named
 * sub-key of an otherwise-atomic manifest value" declaration grammar
 * extracted from Policy.php).
 *
 * validate_dynamic_options() already has deep behavioral coverage through
 * the real Policy::load()/from_snapshot() flow in
 * regress_dynamic_options_policy.php (unchanged by this move, still green);
 * the shared assert_sub_key_parent_has_no_value_fields() helper already has
 * coverage through regress_duo3316_contract.php. validate_sub_keys()'s own
 * two refusals ("not a non-empty object", "class=authored and sub_keys are
 * mutually exclusive") have no dedicated test matching their exact text
 * anywhere in the repo before this file (grep-verified), so this suite is
 * their first genuine behavioral proof. The rest of this suite is
 * direct-API characterization plus the structural checks: the two methods
 * no longer exist on Policy, Policy::CLASSES is now public, and the shared
 * private helper is reachable from BOTH call sites -- the coupling that is
 * the actual reason these two methods, not just one, moved together this
 * slice.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../agent/src/OptionState.php';
require_once __DIR__ . '/../../agent/src/Policy.php';
require_once __DIR__ . '/../../agent/src/SubKeyGrammar.php';

use Duo\Policy;
use Duo\SubKeyGrammar;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$assertThrows = static function (callable $fn, string $needle, string $label) use ($check): void {
    try {
        $fn();
        $check(false, "$label: expected a refusal, none thrown");
    } catch (\RuntimeException $e) {
        $check(
            str_contains($e->getMessage(), $needle),
            "$label: refusal names \"$needle\" (got: {$e->getMessage()})"
        );
    }
};

$assertOk = static function (callable $fn, string $label) use ($check): void {
    try {
        $fn();
        $check(true, "$label: accepted");
    } catch (\Throwable $e) {
        $check(false, "$label: unexpectedly refused ({$e->getMessage()})");
    }
};

// ----------------------------------------------------------------- validate_sub_keys

$assertThrows(
    static fn() => SubKeyGrammar::validate_sub_keys(
        ['options' => ['acme_setting' => ['class' => 'runtime', 'sub_keys' => []]]],
        "manifest 'acme'"
    ),
    'but it is not a non-empty object',
    'sub_keys: an empty sub_keys object is refused'
);
$assertThrows(
    static fn() => SubKeyGrammar::validate_sub_keys(
        ['options' => ['acme_setting' => ['class' => 'runtime', 'sub_keys' => 'x']]],
        "manifest 'acme'"
    ),
    'but it is not a non-empty object',
    'sub_keys: a non-array sub_keys value is refused'
);
$assertThrows(
    static fn() => SubKeyGrammar::validate_sub_keys(
        ['options' => ['acme_setting' => [
            'class' => 'authored',
            'sub_keys' => ['x' => ['class' => 'authored']],
        ]]],
        "manifest 'acme'"
    ),
    'these are mutually exclusive',
    'sub_keys: class=authored together with sub_keys on the same option rule is refused'
);
$assertThrows(
    static fn() => SubKeyGrammar::validate_sub_keys(
        ['options' => ['acme_setting' => [
            'class' => 'runtime',
            'sub_keys' => ['x' => ['class' => 'not-a-real-class']],
        ]]],
        "manifest 'acme'"
    ),
    'invalid or missing class',
    'sub_keys: an unrecognized per-sub-key class is refused'
);
$assertOk(
    static fn() => SubKeyGrammar::validate_sub_keys(
        ['options' => ['acme_setting' => [
            'class' => 'runtime',
            'sub_keys' => ['x' => ['class' => 'authored'], 'y' => ['class' => 'env']],
        ]]],
        "manifest 'acme'"
    ),
    'sub_keys: a well-formed non-empty sub_keys object with recognized per-sub-key classes'
);
$assertOk(
    static fn() => SubKeyGrammar::validate_sub_keys(['options' => ['acme_setting' => ['class' => 'authored']]], "manifest 'acme'"),
    'sub_keys: an ordinary option with no sub_keys at all is unaffected'
);

// ---------------------------------------------------- the shared parent-value-fields helper

$assertThrows(
    static fn() => SubKeyGrammar::validate_sub_keys(
        ['options' => ['acme_setting' => [
            'class' => 'runtime',
            'ref' => 'post',
            'sub_keys' => ['x' => ['class' => 'authored']],
        ]]],
        "manifest 'acme'"
    ),
    'declares sub_keys together with whole-value field(s)',
    'sub_keys: a parent declaring both sub_keys and a whole-value field (ref) is refused'
);
$assertThrows(
    static fn() => SubKeyGrammar::validate_dynamic_options([
        'name' => 'acme',
        'dynamic_options' => ['theme_mods' => [
            'prefix' => 'theme_mods_', 'resolver' => 'active_stylesheet', 'json_encoded' => true,
            'sub_keys' => ['x' => ['class' => 'env']],
        ]],
    ]),
    'declares sub_keys together with whole-value field(s)',
    'dynamic_options: the SAME shared helper refuses a whole-value field on a dynamic_options parent too -- the coupling this slice is built on'
);

// ------------------------------------------------------------ validate_dynamic_options

$assertOk(
    static fn() => SubKeyGrammar::validate_dynamic_options([
        'name' => 'acme',
        'dynamic_options' => ['theme_mods' => [
            'prefix' => 'theme_mods_', 'resolver' => 'active_stylesheet',
            'sub_keys' => ['nav_menu_locations' => ['class' => 'env']],
        ]],
    ]),
    'dynamic_options: a well-formed declaration is accepted'
);
$assertThrows(
    static fn() => SubKeyGrammar::validate_dynamic_options([
        'name' => 'acme',
        'dynamic_options' => ['theme_mods' => [
            'prefix' => 'theme_mods_', 'resolver' => 'not-a-real-resolver',
            'sub_keys' => ['x' => ['class' => 'env']],
        ]],
    ]),
    'is supported in v1',
    'dynamic_options: an unrecognized resolver is refused'
);
$assertThrows(
    static fn() => SubKeyGrammar::validate_dynamic_options([
        'name' => 'acme',
        'dynamic_options' => ['theme_mods' => [
            'prefix' => 'theme_mods_', 'resolver' => 'active_stylesheet', 'class' => 'authored',
            'sub_keys' => ['x' => ['class' => 'env']],
        ]],
    ]),
    'carries no top-level',
    'dynamic_options: a dead top-level class field is refused (DUO-3375)'
);

// ------------------------------------------------------------------ dynamic_option_resolvers()

$check(
    SubKeyGrammar::dynamic_option_resolvers() === Policy::closed_vocabularies()['dynamic_option_resolvers'],
    'SubKeyGrammar::dynamic_option_resolvers() publishes the exact same value Policy::closed_vocabularies() reports'
);
$check(
    SubKeyGrammar::dynamic_option_resolvers() === ['active_stylesheet'],
    'dynamic_option_resolvers() is still exactly the v1-scoped single-resolver set'
);

// --------------------------------------------------- structural: moved, not duplicated

foreach (['validate_sub_keys', 'validate_dynamic_options', 'assert_sub_key_parent_has_no_value_fields'] as $method) {
    $check(
        !(new ReflectionClass(Policy::class))->hasMethod($method),
        "Policy.php no longer defines $method() itself (moved to SubKeyGrammar.php)"
    );
}
$check(
    (new ReflectionClass(Policy::class))->hasConstant('CLASSES')
        && (new ReflectionClassConstant(Policy::class, 'CLASSES'))->isPublic(),
    'Policy::CLASSES stayed on Policy (read at 11 places across Policy.php, not exclusive to this cluster) and is now public'
);
$check(
    Policy::CLASSES === ['authored', 'runtime', 'derived', 'env', 'managed'],
    'Policy::CLASSES is byte-for-byte unchanged by the visibility widening'
);

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "\nall SubKeyGrammar checks passed\n";
exit(0);

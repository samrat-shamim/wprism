<?php
/**
 * Offline regression for CrossManifestGuards (DUO-3348 slice 7: the
 * cross-manifest "one owner, no contradiction" guard family extracted from
 * Policy.php).
 *
 * Unlike slice 6's ActionProviderGrammar (where existing suites already
 * covered the whole moved cluster's behavior through Policy::load()), only
 * two of these six methods have confirmed prior behavioral coverage:
 * validate_no_conflicting_option_rules (regress_env_options_policy.php,
 * regress_manifest_validate.php) and validate_no_conflicting_taxonomy_
 * object_keyspaces (regress_taxonomy_object_keyspace.php) -- both unchanged
 * by this move and still exercised through the real Policy::load() flow.
 * The other four (validate_no_conflicting_description_reference_rules,
 * validate_no_conflicting_post_type_contracts,
 * validate_one_owner_per_declared_name, validate_unique_table_id_kinds) had
 * no dedicated test matching their exact refusal text anywhere in the repo
 * before this file (grep-verified), so this suite is their first genuine
 * behavioral proof, not just wiring/shape confirmation -- each gets a real
 * conflict case and a real non-conflict (redundant/disjoint) case, direct
 * against the public API, never reimplementing the validation logic itself.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../agent/src/Policy.php';
require_once __DIR__ . '/../../agent/src/CrossManifestGuards.php';

use Duo\CrossManifestGuards;
use Duo\Policy;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

/** Runs $fn, asserts it threw, and that the message contains $needle. */
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

/** Runs $fn, asserts it did NOT throw. */
$assertOk = static function (callable $fn, string $label) use ($check): void {
    try {
        $fn();
        $check(true, "$label: accepted");
    } catch (\Throwable $e) {
        $check(false, "$label: unexpectedly refused ({$e->getMessage()})");
    }
};

// ----------------------------------------- validate_no_conflicting_taxonomy_object_keyspaces

$assertThrows(
    static fn() => CrossManifestGuards::validate_no_conflicting_taxonomy_object_keyspaces([
        ['name' => 'a', 'taxonomies' => ['lang' => ['object_keyspace' => 'post']]],
        ['name' => 'b', 'taxonomies' => ['lang' => ['object_keyspace' => 'term']]],
    ]),
    "has conflicting object_keyspace declarations",
    'taxonomy keyspaces: two manifests disagree on the same exact taxonomy'
);
$assertOk(
    static fn() => CrossManifestGuards::validate_no_conflicting_taxonomy_object_keyspaces([
        ['name' => 'a', 'taxonomies' => ['lang' => ['object_keyspace' => 'post']]],
        ['name' => 'b', 'taxonomies' => ['lang' => ['object_keyspace' => 'post']]],
    ]),
    'taxonomy keyspaces: two manifests agreeing on the same exact taxonomy'
);

// ----------------------------------------- validate_no_conflicting_description_reference_rules

$assertThrows(
    static fn() => CrossManifestGuards::validate_no_conflicting_description_reference_rules([
        ['name' => 'a', 'taxonomies' => ['lang' => ['description_refs' => ['kind' => 'post']]]],
        ['name' => 'b', 'taxonomies' => ['lang' => ['description_refs' => ['kind' => 'term']]]],
    ]),
    "has conflicting description_refs declarations",
    'description refs: two manifests disagree on the same taxonomy'
);
$assertOk(
    static fn() => CrossManifestGuards::validate_no_conflicting_description_reference_rules([
        ['name' => 'a', 'taxonomies' => ['lang' => ['description_refs' => ['kind' => 'post']]]],
        ['name' => 'b', 'taxonomies' => ['lang' => ['description_refs' => ['kind' => 'post']]]],
    ]),
    'description refs: two manifests agreeing on the same taxonomy'
);
$assertOk(
    static fn() => CrossManifestGuards::validate_no_conflicting_description_reference_rules([
        ['name' => 'a', 'taxonomies' => ['lang' => ['description_refs' => ['kind' => 'post']]]],
        ['name' => 'b', 'taxonomies' => ['other' => ['description_refs' => ['kind' => 'term']]]],
    ]),
    'description refs: two manifests declaring disjoint taxonomies never conflict'
);

// ----------------------------------------- validate_no_conflicting_option_rules

$assertThrows(
    static fn() => CrossManifestGuards::validate_no_conflicting_option_rules([
        ['name' => 'a', 'options' => ['some_option' => ['class' => 'authored']]],
        ['name' => 'b', 'options' => ['some_option' => ['class' => 'runtime']]],
    ], []),
    'declare contradictory rules',
    'option rules: two non-core manifests disagree on the same option'
);
$assertOk(
    static fn() => CrossManifestGuards::validate_no_conflicting_option_rules([
        ['name' => 'a', 'options' => ['some_option' => ['class' => 'authored']]],
        ['name' => 'b', 'options' => ['some_option' => ['class' => 'authored']]],
    ], []),
    'option rules: two non-core manifests agreeing on the same option'
);
$assertOk(
    static fn() => CrossManifestGuards::validate_no_conflicting_option_rules([
        ['name' => 'core', 'options' => ['some_option' => ['class' => 'authored']]],
        ['name' => 'plugin', 'options' => ['some_option' => ['class' => 'runtime']]],
    ], []),
    'option rules: core is exempt from the conflict guard (DUO-3249 core-yields-to-plugin)'
);
$assertOk(
    static fn() => CrossManifestGuards::validate_no_conflicting_option_rules([
        ['name' => 'a', 'options' => ['some_option' => ['class' => 'authored']]],
        ['name' => 'b', 'options' => ['some_option' => ['class' => 'runtime']]],
    ], ['some_option' => ['class' => 'env']]),
    'option rules: an explicit site.duo.json policy.options override skips the guard entirely'
);

// ----------------------------------------- validate_no_conflicting_post_type_contracts

$assertThrows(
    static fn() => CrossManifestGuards::validate_no_conflicting_post_type_contracts([
        ['name' => 'a', 'post_types' => ['product' => ['body_mode' => 'html']]],
        ['name' => 'b', 'post_types' => ['product' => ['body_mode' => 'blocks']]],
    ]),
    "post_types.product.body_mode with different values",
    'post-type contracts: two manifests disagree on the same key of the same post type'
);
$assertOk(
    static fn() => CrossManifestGuards::validate_no_conflicting_post_type_contracts([
        ['name' => 'a', 'post_types' => ['product' => ['body_mode' => 'html']]],
        ['name' => 'b', 'post_types' => ['product' => ['body_mode' => 'html']]],
    ]),
    'post-type contracts: two manifests agreeing on the same key are redundant, not ambiguous'
);
$assertOk(
    static fn() => CrossManifestGuards::validate_no_conflicting_post_type_contracts([
        ['name' => 'a', 'post_types' => ['product' => ['body_mode' => 'html']]],
        ['name' => 'b', 'post_types' => ['product' => ['scope' => 'authored']]],
    ]),
    'post-type contracts: two manifests declaring DIFFERENT keys of the same post type never contradict'
);

// ----------------------------------------- validate_one_owner_per_declared_name

$assertThrows(
    static fn() => CrossManifestGuards::validate_one_owner_per_declared_name([
        ['name' => 'a', 'widgets' => ['acme_widget' => ['settings' => ['x' => ['class' => 'authored']]]]],
        ['name' => 'b', 'widgets' => ['acme_widget' => ['settings' => ['y' => ['class' => 'authored']]]]],
    ]),
    "both declare widgets.acme_widget",
    'one owner per name: two manifests declare the same widget type with different bodies'
);
$assertOk(
    static fn() => CrossManifestGuards::validate_one_owner_per_declared_name([
        ['name' => 'a', 'widgets' => ['acme_widget' => ['settings' => ['x' => ['class' => 'authored']]]]],
        ['name' => 'b', 'widgets' => ['acme_widget' => ['settings' => ['x' => ['class' => 'authored']]]]],
    ]),
    'one owner per name: byte-identical declarations of the same widget type are redundant, not ambiguous'
);
$assertOk(
    static fn() => CrossManifestGuards::validate_one_owner_per_declared_name([
        ['name' => 'a', 'post_types' => ['product' => ['body_mode' => 'html']]],
        ['name' => 'b', 'tables' => ['acme_rooms' => ['class' => 'authored_snapshot']]],
    ]),
    'one owner per name: different surfaces (post_types vs. tables) never collide'
);

// ----------------------------------------- validate_unique_table_id_kinds

$assertThrows(
    static fn() => CrossManifestGuards::validate_unique_table_id_kinds([
        'acme_rooms' => ['class' => 'authored_snapshot', 'id_kind' => 'room'],
        'other_rooms' => ['class' => 'authored_snapshot', 'id_kind' => 'room'],
    ]),
    "id_kind 'room' is declared by both",
    'unique table id_kinds: two authored_snapshot tables share one id_kind'
);
$assertOk(
    static fn() => CrossManifestGuards::validate_unique_table_id_kinds([
        'acme_rooms' => ['class' => 'authored_snapshot', 'id_kind' => 'room'],
        'other_rooms' => ['class' => 'authored_snapshot', 'id_kind' => 'slot'],
    ]),
    'unique table id_kinds: two authored_snapshot tables with distinct id_kinds'
);
$assertOk(
    static fn() => CrossManifestGuards::validate_unique_table_id_kinds([
        'acme_rooms' => ['class' => 'authored_snapshot', 'id_kind' => 'room'],
        'meta_table' => ['class' => 'authored_snapshot_meta', 'id_kind' => 'room'],
    ]),
    'unique table id_kinds: a non-authored_snapshot class sharing the id_kind string is out of scope for this guard'
);

// --------------------------------------------------------- shared helpers reachable

$assertThrows(
    static fn() => CrossManifestGuards::validate_no_conflicting_option_rules([
        ['name' => 'a', 'options' => ['x' => ['class' => 'authored', 'autoload' => 'yes']]],
        ['name' => 'b', 'options' => ['x' => ['class' => 'authored']], 'option_autoload' => 'no'],
    ], []),
    'declare contradictory rules',
    "Policy::with_option_autoload() is reachable: a manifest-level autoload default makes an otherwise-identical rule diverge"
);

// --------------------------------------------------- structural: moved, not duplicated

foreach ([
    'validate_no_conflicting_taxonomy_object_keyspaces',
    'validate_no_conflicting_description_reference_rules',
    'validate_no_conflicting_option_rules',
    'validate_no_conflicting_post_type_contracts',
    'validate_one_owner_per_declared_name',
    'validate_unique_table_id_kinds',
] as $method) {
    $check(
        !(new ReflectionClass(Policy::class))->hasMethod($method),
        "Policy.php no longer defines $method() itself (moved to CrossManifestGuards.php)"
    );
}
$check(
    (new ReflectionClass(Policy::class))->hasMethod('taxonomy_pattern_matches')
        && (new ReflectionMethod(Policy::class, 'taxonomy_pattern_matches'))->isPublic(),
    'taxonomy_pattern_matches() stayed on Policy (also used by the live taxonomy_object_keyspace() runtime path, not exclusive to this cluster) and is now public'
);
$check(
    (new ReflectionClass(Policy::class))->hasMethod('with_option_autoload')
        && (new ReflectionMethod(Policy::class, 'with_option_autoload'))->isPublic(),
    'with_option_autoload() stayed on Policy (used across a dozen call sites beyond this cluster) and is now public'
);

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "\nall CrossManifestGuards checks passed\n";
exit(0);

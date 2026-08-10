<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression harness for
 * DUO-3264's Policy.php-side wiring: the dynamic_options primitive (fork A
 * of the owner ruling, issue comment 9fd882a6) — dynamic_options()/
 * resolve_dynamic_option()/dynamic_option_rule_for_name()/
 * dynamic_option_rule_for_prefix()/is_dynamic_option_residue(), and
 * validate_dynamic_options()'s load-time guard. Uses a FAKE fixture
 * manifest via DUO_MANIFESTS_DIR, never the real manifests/core.json —
 * this file proves the MECHANISM works in isolation; a real theme_mods_*
 * blob's own key-by-key shapes are grounded and proven live (DUO-3264's
 * own issue comments have the full empirical record: two real WordPress
 * themes, real Customizer-equivalent APIs, not assumed) and round-tripped
 * end to end on a live pair (capture -> push -> clone -> deploy -> apply
 * -> byte-identical recapture, including ref-token re-resolution to a
 * second environment's own local ids).
 *
 * The two lookup methods deliberately differ (see Policy.php's own
 * docblocks for the full reasoning, proven live not just reasoned about):
 * dynamic_option_rule_for_name() requires an EXACT match against the
 * caller-supplied resolved value (safe only at actual apply time, once
 * DUO-3216's own theme-mismatch refuse-gate already guarantees the match
 * holds); dynamic_option_rule_for_prefix() matches by prefix ALONE,
 * independent of the live resolved value (what RepositoryAuthorization
 * needs, since it also runs as part of `wp duo deploy`'s own repository
 * compilation — the command that reconciles a theme mismatch in the first
 * place, discovered as a genuine circular dependency when the stricter
 * check was tried there first and `wp duo deploy` itself refused to
 * compile).
 *
 * Exit 0 and "ALL PASSED" on success; any failed check prints "FAIL: ..."
 * and the script exits 1.
 */

$fixtureDir = sys_get_temp_dir() . '/duo_regress_dynamic_options_' . bin2hex(random_bytes(4));
mkdir($fixtureDir, 0777, true);
register_shutdown_function(function () use ($fixtureDir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixtureDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($fixtureDir);
});
putenv("DUO_MANIFESTS_DIR=$fixtureDir");

require __DIR__ . '/../../agent/src/Canon.php';
require __DIR__ . '/../../agent/src/OptionState.php';
require __DIR__ . '/../../agent/src/Policy.php';

use Duo\Policy;

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 0);
}

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

function write_manifest(string $dir, string $name, array $json): void {
    file_put_contents("$dir/$name.json", json_encode($json, JSON_PRETTY_PRINT));
}

// ======================================================================
echo "\n== fixture: a 'core' manifest declaring dynamic_options.theme_mods ==\n";

write_manifest($fixtureDir, 'core', [
    'name' => 'core',
    'spec_version' => DUO_SPEC_VERSION,
    'option_autoload' => 'preserve',
    'dynamic_options' => [
        'theme_mods' => [
            'prefix' => 'theme_mods_',
            'resolver' => 'active_stylesheet',
            'autoload' => 'preserve',
            'sub_keys' => [
                'background_color' => ['class' => 'authored'],
                'custom_logo' => ['class' => 'authored', 'ref' => 'post'],
                'sidebars_widgets' => ['class' => 'runtime'],
            ],
        ],
    ],
]);

$p = Policy::load(null, ['core']);

// ======================================================================
echo "\n== dynamic_options() enumeration ==\n";

$decls = $p->dynamic_options();
check(isset($decls['theme_mods']), 'theme_mods declaration is enumerated');
check(($decls['theme_mods']['prefix'] ?? null) === 'theme_mods_', 'prefix round-trips correctly');
check(($decls['theme_mods']['resolver'] ?? null) === 'active_stylesheet', 'resolver round-trips correctly');
check(($decls['theme_mods']['autoload'] ?? null) === 'preserve', 'declared autoload round-trips correctly');

// ======================================================================
echo "\n== resolve_dynamic_option(): builds the one concrete, authored-eligible name ==\n";

$resolved = $p->resolve_dynamic_option('theme_mods', 'storefront');
check($resolved !== null, 'resolve_dynamic_option() resolves a declared key');
check(($resolved['name'] ?? null) === 'theme_mods_storefront', 'resolved name is prefix + resolved value');
check(($resolved['class'] ?? null) === 'env', "resolved class is always 'env' -- the whole-blob class sub_keys carves authored keys out of");
check(isset($resolved['sub_keys']['background_color']), 'resolved sub_keys carries the declared background_color rule');
check(($resolved['autoload'] ?? null) === 'preserve', 'resolved autoload carries through');

check($p->resolve_dynamic_option('nonexistent_key', 'storefront') === null, 'an undeclared key resolves to null');

// ======================================================================
echo "\n== dynamic_option_rule_for_name(): EXACT match only (the apply-time lookup) ==\n";

$exact = $p->dynamic_option_rule_for_name('theme_mods_storefront', ['active_stylesheet' => 'storefront']);
check($exact !== null, 'the currently-resolved name matches exactly');
check(($exact['class'] ?? null) === 'env', 'exact match returns class=env');
check(isset($exact['sub_keys']['custom_logo']), 'exact match carries the declared sub_keys');

$residueMatch = $p->dynamic_option_rule_for_name('theme_mods_twentytwentyone', ['active_stylesheet' => 'storefront']);
check($residueMatch === null, 'a DIFFERENT theme\'s own row (matches the prefix, not the resolved value) returns null -- never guessed at');

check($p->dynamic_option_rule_for_name('widget_text', ['active_stylesheet' => 'storefront']) === null, 'a name sharing no declared prefix at all returns null');

// ======================================================================
echo "\n== dynamic_option_rule_for_prefix(): PREFIX-only match (the authorization-time lookup) ==\n";

$prefixMatchActive = $p->dynamic_option_rule_for_prefix('theme_mods_storefront');
check($prefixMatchActive !== null, 'the currently-resolved name also matches by prefix');

$prefixMatchInactive = $p->dynamic_option_rule_for_prefix('theme_mods_twentytwentyone');
check($prefixMatchInactive !== null, 'a DIFFERENT theme\'s own row ALSO matches by prefix alone -- deliberately looser than dynamic_option_rule_for_name(), this is the whole point (deploy must be able to compile before the theme is reconciled)');
check(($prefixMatchInactive['sub_keys'] ?? null) === ($prefixMatchActive['sub_keys'] ?? null), 'both resolve to the identical declared sub_keys regardless of which theme the name names');

check($p->dynamic_option_rule_for_prefix('widget_text') === null, 'a name sharing no declared prefix returns null here too');

// ======================================================================
echo "\n== is_dynamic_option_residue(): the queryable form of 'not the currently-resolved row' ==\n";

check($p->is_dynamic_option_residue('theme_mods_twentytwentyone', ['active_stylesheet' => 'storefront']) === true, 'a non-active theme\'s own row is residue');
check($p->is_dynamic_option_residue('theme_mods_storefront', ['active_stylesheet' => 'storefront']) === false, 'the active theme\'s own row is NOT residue');
check($p->is_dynamic_option_residue('widget_text', ['active_stylesheet' => 'storefront']) === false, 'a name matching no declared prefix at all is not residue either (nothing to be residue OF)');
check($p->is_dynamic_option_residue('theme_mods_storefront', []) === false, 'no resolved value supplied for the declared resolver -- never guessed at, defensively false');

// ======================================================================
echo "\n== validate_dynamic_options(): loud load-time rejection of bad declarations ==\n";

function expect_load_failure(string $fixtureDir, string $manifestName, string $needle): void {
    global $failures;
    $threw = false;
    $msg = '';
    try {
        Policy::load(null, ['core', $manifestName]);
    } catch (\RuntimeException $e) {
        $threw = true;
        $msg = $e->getMessage();
    }
    check($threw, "manifest '$manifestName' fails load() loudly, not silently");
    check(str_contains($msg, $needle), "the thrown message names the offending declaration (expected substring '$needle', got: $msg)");
}

write_manifest($fixtureDir, 'bad_no_prefix', [
    'name' => 'bad_no_prefix', 'spec_version' => DUO_SPEC_VERSION,
    'dynamic_options' => ['widgets' => ['resolver' => 'active_stylesheet', 'sub_keys' => ['x' => ['class' => 'authored']]]],
]);
expect_load_failure($fixtureDir, 'bad_no_prefix', "dynamic_options.widgets");

write_manifest($fixtureDir, 'bad_resolver', [
    'name' => 'bad_resolver', 'spec_version' => DUO_SPEC_VERSION,
    'dynamic_options' => ['widgets' => ['prefix' => 'widgets_', 'resolver' => 'active_plugin_xyz', 'sub_keys' => ['x' => ['class' => 'authored']]]],
]);
expect_load_failure($fixtureDir, 'bad_resolver', 'dynamic_options.widgets.resolver');

write_manifest($fixtureDir, 'bad_empty_subkeys', [
    'name' => 'bad_empty_subkeys', 'spec_version' => DUO_SPEC_VERSION,
    'dynamic_options' => ['widgets' => ['prefix' => 'widgets_', 'resolver' => 'active_stylesheet', 'sub_keys' => []]],
]);
expect_load_failure($fixtureDir, 'bad_empty_subkeys', 'dynamic_options.widgets.sub_keys');

write_manifest($fixtureDir, 'bad_subkey_class', [
    'name' => 'bad_subkey_class', 'spec_version' => DUO_SPEC_VERSION,
    'dynamic_options' => ['widgets' => ['prefix' => 'widgets_', 'resolver' => 'active_stylesheet', 'sub_keys' => ['x' => ['class' => 'not_a_real_class']]]],
]);
expect_load_failure($fixtureDir, 'bad_subkey_class', 'dynamic_options.widgets.sub_keys.x');

// ======================================================================
echo "\n== DUO-3375: a top-level `class` on a dynamic_options declaration is a DEAD field, refused at load ==\n";

// resolve_dynamic_option()/dynamic_option_rule_for_name() HARDWIRE the resolved
// row's class to 'env' (proven above), so a manifest that writes a top-level
// class used to LOAD and then be silently discarded — the operator's ownership
// claim (e.g. class:authored) accepted and overridden with no signal. The fix
// refuses it at load with an actionable dead-field message. Mutation anchor: if
// the array_key_exists('class', $decl) guard in validate_dynamic_options() is
// reverted, this manifest LOADS and resolve_dynamic_option() reports class=env
// — the exact bug — and the checks below flip to FAIL.
$msg = '';
$threw = false;
try {
    write_manifest($fixtureDir, 'bad_toplevel_class_authored', [
        'name' => 'bad_toplevel_class_authored', 'spec_version' => DUO_SPEC_VERSION,
        'option_autoload' => 'preserve',
        'dynamic_options' => ['widgets' => [
            'prefix' => 'widgets_',
            'resolver' => 'active_stylesheet',
            'class' => 'authored', // the operator's ownership claim — never consumed
            'sub_keys' => ['x' => ['class' => 'authored']],
        ]],
    ]);
    Policy::load(null, ['core', 'bad_toplevel_class_authored']);
} catch (\RuntimeException $e) {
    $threw = true;
    $msg = $e->getMessage();
}
check($threw, "a dynamic_options declaration carrying class:authored is REFUSED at load — never loaded-then-coerced-to-env (the DUO-3375 bug)");
check(str_contains($msg, 'dynamic_options.widgets.class'), "the refusal names the exact dead field (got: $msg)");
check(str_contains($msg, 'authored'), 'the refusal echoes the rejected value back to the operator');
check(str_contains($msg, "'env'"), "the refusal names what the engine forces instead — 'env'");
check(
    str_contains($msg, 'discarded') || str_contains($msg, 'never consumed') || str_contains($msg, 'not consumed'),
    'the refusal says the field is not consumed (dead-field diagnosis, not a shape typo)'
);

// ANY top-level class is refused — even 'env' (the value the engine forces) and
// 'managed'. The field is dead regardless of value; declaring the "right" value
// is still an unconsumed claim, so the schema is not quietly widened to it.
foreach (['env', 'managed'] as $deadClass) {
    write_manifest($fixtureDir, "bad_toplevel_class_$deadClass", [
        'name' => "bad_toplevel_class_$deadClass", 'spec_version' => DUO_SPEC_VERSION,
        'option_autoload' => 'preserve',
        'dynamic_options' => ['widgets' => [
            'prefix' => 'widgets_',
            'resolver' => 'active_stylesheet',
            'class' => $deadClass,
            'sub_keys' => ['x' => ['class' => 'authored']],
        ]],
    ]);
    expect_load_failure($fixtureDir, "bad_toplevel_class_$deadClass", 'dynamic_options.widgets.class');
}

// ======================================================================
echo "\n== DUO-3375: the frozen-snapshot entry point reaches the SAME verdict (load/from_snapshot lockstep) ==\n";

// Both Policy::load() and Policy::from_snapshot() call the identical
// validate_dynamic_options(); this half proves the from_snapshot() call site
// (Policy.php, the DUO-3318 lockstep line) is present. Mutation anchor: delete
// the self::validate_dynamic_options() call inside from_snapshot() and the
// refusal check below flips to FAIL while load() above still refuses — exactly
// the DUO-3318 L1 divergence class.
function frozen_snapshot(array $manifests): array {
    return [
        'adapter_sources' => ['format' => 'duo-adapter-sources/v1', 'out_of_tree' => []],
        'capabilities' => null,
        'dispositions' => null,
        'format' => 'duo-policy-snapshot/v4',
        'manifests' => $manifests,
        // A real snapshot has already been through Canon::decode(), so every
        // object is a PHP array by the time from_snapshot() sees it.
        'site' => [
            'manifests' => array_map(static fn(array $m): string => (string) $m['name'], $manifests),
            'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []],
            'spec_version' => DUO_SPEC_VERSION,
        ],
    ];
}

$cleanCoreManifest = [
    'name' => 'core',
    'spec_version' => DUO_SPEC_VERSION,
    'option_autoload' => 'preserve',
    'dynamic_options' => ['theme_mods' => [
        'prefix' => 'theme_mods_',
        'resolver' => 'active_stylesheet',
        'autoload' => 'preserve',
        'sub_keys' => [
            'background_color' => ['class' => 'authored'],
            'custom_logo' => ['class' => 'authored', 'ref' => 'post'],
            'sidebars_widgets' => ['class' => 'runtime'],
        ],
    ]],
];
$badCoreManifest = $cleanCoreManifest;
$badCoreManifest['dynamic_options']['theme_mods']['class'] = 'authored';

$frozenClean = Policy::from_snapshot(frozen_snapshot([$cleanCoreManifest]));
check(
    ($frozenClean->dynamic_options()['theme_mods']['prefix'] ?? null) === 'theme_mods_',
    'a clean dynamic_options declaration loads through the frozen entry point too — the fix does not break the valid shape'
);

$frozenMsg = '';
$frozenThrew = false;
try {
    Policy::from_snapshot(frozen_snapshot([$badCoreManifest]));
} catch (\RuntimeException $e) {
    $frozenThrew = true;
    $frozenMsg = $e->getMessage();
}
check($frozenThrew, 'from_snapshot() ALSO refuses a top-level class — the two entry points reach the same verdict (DUO-3375 lockstep)');
check(str_contains($frozenMsg, 'dynamic_options.theme_mods.class'), "from_snapshot()'s refusal names the dead field too (got: $frozenMsg)");

// ======================================================================
echo "\n== DUO-3375: a valid declaration's canonical bytes are UNCHANGED (schema not widened) ==\n";

// $p is the clean 'core' fixture loaded at the top of this file. Its enumerated
// declaration and its resolved row must serialize to these exact bytes: the fix
// only ADDS a refusal for a top-level class and touches no valid path, so a
// declaration with no top-level class must load byte-identically.
$expectedEnumCanon = <<<JSON
{
    "autoload": "preserve",
    "prefix": "theme_mods_",
    "resolver": "active_stylesheet",
    "sub_keys": {
        "background_color": {
            "class": "authored"
        },
        "custom_logo": {
            "class": "authored",
            "ref": "post"
        },
        "sidebars_widgets": {
            "class": "runtime"
        }
    }
}

JSON;
check(
    \Duo\Canon::encode($p->dynamic_options()['theme_mods']) === $expectedEnumCanon,
    'the enumerated valid declaration canonicalizes to its expected, unchanged bytes'
);

$expectedResolveCanon = <<<JSON
{
    "autoload": "preserve",
    "class": "env",
    "name": "theme_mods_storefront",
    "sub_keys": {
        "background_color": {
            "class": "authored"
        },
        "custom_logo": {
            "class": "authored",
            "ref": "post"
        },
        "sidebars_widgets": {
            "class": "runtime"
        }
    }
}

JSON;
check(
    \Duo\Canon::encode($p->resolve_dynamic_option('theme_mods', 'storefront')) === $expectedResolveCanon,
    "the resolved row still canonicalizes with class=env and the declared sub_keys — unchanged bytes"
);

// ======================================================================
echo "\n== validate_option_storage(): a dynamic_options entry with an authored sub_key still needs autoload ==\n";

write_manifest($fixtureDir, 'bad_no_autoload', [
    'name' => 'bad_no_autoload', 'spec_version' => DUO_SPEC_VERSION,
    // deliberately no 'option_autoload' manifest default AND no per-declaration 'autoload'
    'dynamic_options' => ['widgets' => ['prefix' => 'widgets_', 'resolver' => 'active_stylesheet', 'sub_keys' => ['x' => ['class' => 'authored']]]],
]);
expect_load_failure($fixtureDir, 'bad_no_autoload', 'dynamic_options.widgets');

// ======================================================================
echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);

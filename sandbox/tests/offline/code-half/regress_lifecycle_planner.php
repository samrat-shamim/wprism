<?php
/**
 * Offline regression for LifecyclePlanner (DUO-3350 slice 6: the lifecycle
 * detection/reporting collaborator extracted from Deploy). Deliberately
 * narrow, the same wiring/shape idiom the earlier slices in this issue
 * established: this file does not re-implement or re-assert code_mismatch/
 * code_revision_mismatch/code_drift/record_code_versions behavior -- doing
 * so from a hand-copied twin of the logic would only add a second copy that
 * could silently drift from the real one, and
 * sandbox/tests/offline/code-half/regress_code_revision_enforcement.php,
 * sandbox/tests/offline/code-half/regress_template_mismatch.php,
 * sandbox/tests/live/regress_adapter_theme_range.sh, and
 * sandbox/tests/live/regress_code_drift.sh already exercise the real behavior
 * deeply, unchanged, through Deploy's own kept facades. This file proves
 * the extraction itself: Deploy no longer inlines the moved bodies, only
 * thin facades remain, and LifecyclePlanner is a directly reachable,
 * correctly-shaped standalone collaborator.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$deploySource = file_get_contents($root . '/agent/src/Promotion/Deploy.php');
$plannerSource = file_get_contents($root . '/agent/src/Promotion/LifecyclePlanner.php');
if (!is_string($deploySource) || !is_string($plannerSource)) {
    fwrite(STDERR, "FAIL: could not read Deploy/LifecyclePlanner sources\n");
    exit(1);
}

$checks = 0;
function check(bool $ok, string $message): void {
    global $checks;
    $checks++;
    if (!$ok) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    fwrite(STDOUT, "ok: $message\n");
}

check(str_contains($deploySource, "require_once __DIR__ . '/LifecyclePlanner.php';"), 'Deploy loads the extracted lifecycle planner');
check(str_contains($plannerSource, 'final class LifecyclePlanner'), 'LifecyclePlanner is a dedicated collaborator');

// === Prove the extraction itself. code_mismatch()/code_revision_mismatch()/
// code_drift()/record_code_versions() each keep a thin Deploy facade (each
// had an external caller beyond Deploy::run() itself at extraction time --
// Apply::build_plan() for the first three, Capture::run() for the fourth;
// DUO-3507 later moved capture onto observe_code_versions(), so
// record_code_versions()'s facade is now Deploy-internal and is kept only
// because run() still spells it self::); compiled_code_revision()
// and check_theme_range() had no caller anywhere outside their own moved
// cluster and were removed from Deploy.php entirely, no facade needed.
foreach ([
    ['code_mismatch', 'return LifecyclePlanner::code_mismatch($policy, $desired);'],
    ['code_revision_mismatch', 'return LifecyclePlanner::code_revision_mismatch($compiled);'],
    ['code_drift', 'return LifecyclePlanner::code_drift($policy, $desired);'],
    ['record_code_versions', 'LifecyclePlanner::record_code_versions($policy);'],
] as [$method, $delegate]) {
    check(
        str_contains($deploySource, $delegate),
        "Deploy::$method() is a thin facade delegating to LifecyclePlanner"
    );
}
foreach (['compiled_code_revision', 'check_theme_range'] as $method) {
    check(
        !str_contains($deploySource, "private static function $method(") && !str_contains($deploySource, "function $method("),
        "Deploy.php no longer defines $method() at all (moved to LifecyclePlanner, no facade needed -- it had no other caller)"
    );
}
check(
    !str_contains($deploySource, 'CODE_VERSIONS_KEY ='),
    'Deploy.php no longer declares CODE_VERSIONS_KEY itself (moved to LifecyclePlanner alongside code_drift()/record_code_versions())'
);
check(
    str_contains($deploySource, 'public static function require_plugin_admin_functions(')
        && str_contains($deploySource, 'public static function current_active_plugins('),
    'require_plugin_admin_functions()/current_active_plugins() stay on Deploy, widened to public -- both are used by Deploy::run()/plugin_runtime_state() too, not exclusive to the moved cluster'
);

// The four public entry points keep exactly their original parameter shapes
// -- no change was needed here, unlike DUO-3350 slice 5's DeleteExecutor-
// style $roots threading, since none of these four methods needed a new
// explicit dependency once moved.
require_once $root . '/agent/src/Promotion/LifecyclePlanner.php';
$planner = new ReflectionClass(\Duo\LifecyclePlanner::class);
check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $planner->getMethod('code_mismatch')->getParameters()) === ['policy', 'desired'],
    'code_mismatch() keeps its original two parameters'
);
check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $planner->getMethod('code_revision_mismatch')->getParameters()) === ['compiled'],
    'code_revision_mismatch() keeps its original single parameter'
);
check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $planner->getMethod('code_drift')->getParameters()) === ['policy', 'desired'],
    'code_drift() keeps its original two parameters'
);
check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $planner->getMethod('record_code_versions')->getParameters()) === ['policy'],
    'record_code_versions() keeps its original single parameter'
);
foreach (['code_mismatch', 'code_revision_mismatch', 'code_drift', 'record_code_versions'] as $public) {
    check($planner->getMethod($public)->isPublic(), "$public() is public on LifecyclePlanner");
}
foreach (['compiled_code_revision', 'check_theme_range'] as $private) {
    check($planner->getMethod($private)->isPrivate(), "$private() stays private -- an internal collaborator, not a shared API");
}

// DUO-3507: observe_code_versions() is capture's baseline write -- the same
// single-$policy shape as the record_code_versions() it wraps, but returning
// the code_drift rows it declined to accept instead of nothing. That return
// type IS the fix: a void observe() could not tell capture there was
// anything to warn about, and a record_code_versions() that returned rows
// would have made deploy's unconditional re-baseline (Deploy.php:472,
// downstream of its own refuse-or-force gate) conditional by accident.
// The behaviour itself is exercised in
// sandbox/tests/offline/code-half/regress_capture_code_baseline.php.
check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $planner->getMethod('observe_code_versions')->getParameters()) === ['policy'],
    'observe_code_versions() takes exactly the policy, like the writer it wraps'
);
check($planner->getMethod('observe_code_versions')->isPublic(), 'observe_code_versions() is public on LifecyclePlanner');
check(
    (string) $planner->getMethod('observe_code_versions')->getReturnType() === 'array',
    'observe_code_versions() returns the rows it declined to accept'
);
check(
    (string) $planner->getMethod('record_code_versions')->getReturnType() === 'void',
    'record_code_versions() still returns nothing -- it decides nothing, it just writes'
);
check(
    str_contains($plannerSource, 'self::record_code_versions($policy);')
        && str_contains($plannerSource, 'if ($drift !== []) {'),
    'observe_code_versions() composes code_drift() and record_code_versions() rather than re-implementing either'
);

// sandbox/tests/offline/policy/regress_manifest_validate.sh's static WordPress-reach
// scanner keys its allowlist on exact "file.php:function_name" pairs -- a
// stale entry there (still naming Deploy.php for a function that moved)
// fails loud on its own, but pin the specific renamed entries here too so a
// future edit that reverts them in isolation is caught by this narrower,
// faster-to-run offline check as well.
$scannerSource = file_get_contents($root . '/sandbox/tests/offline/policy/regress_manifest_validate.sh');
check(is_string($scannerSource), 'regress_manifest_validate.sh is readable');
foreach (['code_mismatch', 'code_drift', 'record_code_versions', 'check_theme_range'] as $fn) {
    check(
        str_contains($scannerSource, "LifecyclePlanner.php:$fn") && !str_contains($scannerSource, "Deploy.php:$fn"),
        "regress_manifest_validate.sh's wp_allow list points $fn at LifecyclePlanner.php, not the old Deploy.php location"
    );
}
// observe_code_versions() never lived on Deploy.php, so it is not a renamed
// entry -- it is a NEW unguarded get_option('template'/'stylesheet') reach in
// boot()'s load closure, which that scanner fails loud on unless allowlisted.
check(
    str_contains($scannerSource, 'LifecyclePlanner.php:observe_code_versions'),
    "regress_manifest_validate.sh's wp_allow list covers observe_code_versions()'s own get_option() reach"
);

if ($checks < 1) {
    fwrite(STDERR, "FAIL: no checks ran\n");
    exit(1);
}
printf("LifecyclePlanner regression: %d checks passed\n", $checks);

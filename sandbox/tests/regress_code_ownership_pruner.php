<?php
/**
 * Offline regression for CodeOwnershipPruner (DUO-3350 slice 5: the removal-
 * authority collaborator extracted from Code). Deliberately narrow, the same
 * wiring/shape idiom the earlier slices in this issue established: this file
 * does not re-implement or re-assert removal/pruning behavior in depth --
 * doing so from a hand-copied twin of the logic would only add a second copy
 * that could silently drift from the real one, and
 * regress_code_materializer_unit.sh / regress_code_stage_lock_unit.sh
 * already exercise the full prune/preflight/type-conflict/foreign-candidate
 * behavior deeply, unchanged, through Code's own kept facade. This file
 * proves the two things genuinely new here instead: the extraction itself
 * (Code no longer inlines the moved bodies, only thin facades remain), and
 * that CodeOwnershipPruner is independently reachable and minimally correct
 * standalone -- not merely correct-on-paper through Code's delegation.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$codeSource = file_get_contents($root . '/agent/src/Code/Code.php');
$prunerSource = file_get_contents($root . '/agent/src/Code/CodeOwnershipPruner.php');
if (!is_string($codeSource) || !is_string($prunerSource)) {
    fwrite(STDERR, "FAIL: could not read Code/CodeOwnershipPruner sources\n");
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

check(str_contains($codeSource, "require_once __DIR__ . '/CodeOwnershipPruner.php';"), 'Code loads the extracted ownership pruner');
check(str_contains($prunerSource, 'final class CodeOwnershipPruner'), 'CodeOwnershipPruner is a dedicated collaborator');

// === Prove the extraction itself: none of the eight internal-only methods
// (no external caller anywhere in the repo, grep-verified, including
// reflection-based callers) remain inlined in Code.php -- only the three
// facades over CodeOwnershipPruner's public entry points do.
foreach ([
    'removal_inventory', 'assert_removal_inventory', 'assert_prunable_directory',
    'assert_obsolete_file_unchanged', 'recorded_path_types', 'assert_recorded_target_types',
    'prune_owned_directory', 'collect_owned_extras',
] as $method) {
    check(
        !str_contains($codeSource, "private static function $method("),
        "Code.php no longer defines $method() at all (moved to CodeOwnershipPruner, no facade needed -- it had no external caller)"
    );
    check(
        (bool) preg_match('/(public|private) static function ' . preg_quote($method, '/') . '\(/', $prunerSource),
        "CodeOwnershipPruner defines $method()"
    );
}
check(
    str_contains($codeSource, 'return CodeOwnershipPruner::remove_old_owned_files($previous, $staged, $history, $current, self::ROOTS);'),
    'Code::remove_old_owned_files() is a thin facade delegating to CodeOwnershipPruner, passing the fixed component-root allowlist'
);
check(
    str_contains($codeSource, 'CodeOwnershipPruner::assert_removal_safe($previous, $staged, $history, $current, self::ROOTS);'),
    'Code::assert_removal_safe() is a thin facade delegating to CodeOwnershipPruner'
);
check(
    str_contains($codeSource, 'return CodeOwnershipPruner::owned_extra_files($descriptor);'),
    'Code::owned_extra_files() is a thin facade delegating to CodeOwnershipPruner'
);

// The three public entry points take exactly the parameters the facades
// pass -- $roots travels explicitly, matching PathSafety::safe_component_root()'s
// own existing (path, roots) contract since slice 1, rather than reaching
// back into Code::ROOTS.
require_once $root . '/agent/src/Code/CodeOwnershipPruner.php';
$pruner = new ReflectionClass(\Duo\CodeOwnershipPruner::class);
check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $pruner->getMethod('remove_old_owned_files')->getParameters())
        === ['previous', 'staged', 'history', 'current', 'roots'],
    'remove_old_owned_files() keeps its original four parameters and gains the explicit "roots" one'
);
check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $pruner->getMethod('assert_removal_safe')->getParameters())
        === ['previous', 'staged', 'history', 'current', 'roots'],
    'assert_removal_safe() keeps its original four parameters and gains the explicit "roots" one'
);
check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $pruner->getMethod('owned_extra_files')->getParameters())
        === ['descriptor'],
    'owned_extra_files() keeps its original single parameter -- it never needed the roots allowlist (it trusts the descriptor\'s own owned_roots, not PathSafety::safe_component_root())'
);
foreach (['remove_old_owned_files', 'assert_removal_safe', 'owned_extra_files'] as $public) {
    check($pruner->getMethod($public)->isPublic(), "$public() is public on CodeOwnershipPruner");
}
foreach ([
    'removal_inventory', 'assert_removal_inventory', 'assert_prunable_directory',
    'assert_obsolete_file_unchanged', 'recorded_path_types', 'assert_recorded_target_types',
    'prune_owned_directory', 'collect_owned_extras',
] as $private) {
    check($pruner->getMethod($private)->isPrivate(), "$private() stays private -- an internal collaborator, not a shared API");
}

// === Minimal standalone-reachability smoke test: real filesystem, real
// WP_CONTENT_DIR (the same idiom regress_path_safety.php already
// established as safe/cheap in this offline suite), calling
// CodeOwnershipPruner directly -- not through Code -- so this is a genuine
// proof the class works independently, not merely correct on paper.
$tmp = sys_get_temp_dir() . '/duo-code-ownership-pruner-' . bin2hex(random_bytes(6));
mkdir($tmp, 0777, true);
define('WP_CONTENT_DIR', $tmp);
mkdir(WP_CONTENT_DIR . '/plugins/kept', 0777, true);
mkdir(WP_CONTENT_DIR . '/plugins/stale', 0777, true);
file_put_contents(WP_CONTENT_DIR . '/plugins/kept/kept.php', '<?php // kept');
file_put_contents(WP_CONTENT_DIR . '/plugins/stale/stale.php', '<?php // stale');
$staleHash = hash_file('sha256', WP_CONTENT_DIR . '/plugins/stale/stale.php');
$roots = ['mu-plugins', 'plugins', 'themes'];
$previous = [
    'owned_roots' => ['plugins/kept', 'plugins/stale'],
    'files' => [
        ['path' => 'plugins/kept/kept.php', 'sha256' => hash_file('sha256', WP_CONTENT_DIR . '/plugins/kept/kept.php')],
        ['path' => 'plugins/stale/stale.php', 'sha256' => $staleHash],
    ],
];
$current = [
    'owned_roots' => ['plugins/kept'],
    'files' => [
        ['path' => 'plugins/kept/kept.php', 'sha256' => hash_file('sha256', WP_CONTENT_DIR . '/plugins/kept/kept.php')],
    ],
];
$removed = \Duo\CodeOwnershipPruner::remove_old_owned_files($previous, null, [], $current, $roots);
check($removed === ['plugins/stale/stale.php'], 'remove_old_owned_files() removes only the file the current descriptor dropped: got ' . json_encode($removed));
check(!file_exists(WP_CONTENT_DIR . '/plugins/stale'), 'remove_old_owned_files() removes the now-empty stale directory too');
check(file_exists(WP_CONTENT_DIR . '/plugins/kept/kept.php'), 'remove_old_owned_files() never touches a still-owned file');

$extras = \Duo\CodeOwnershipPruner::owned_extra_files($current);
check($extras === [], 'owned_extra_files() finds nothing unrecorded once the stale file is gone: got ' . json_encode($extras));

file_put_contents(WP_CONTENT_DIR . '/plugins/kept/untracked.php', '<?php // never recorded');
$extrasAfter = \Duo\CodeOwnershipPruner::owned_extra_files($current);
check($extrasAfter === ['plugins/kept/untracked.php'], 'owned_extra_files() reports a real unrecorded file inside an owned root: got ' . json_encode($extrasAfter));

array_map('unlink', glob(WP_CONTENT_DIR . '/plugins/kept/*'));
rmdir(WP_CONTENT_DIR . '/plugins/kept');
rmdir(WP_CONTENT_DIR . '/plugins');
rmdir($tmp);

printf("CodeOwnershipPruner regression: %d checks passed\n", $checks);

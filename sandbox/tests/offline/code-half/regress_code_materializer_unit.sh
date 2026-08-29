#!/usr/bin/env bash
# Offline ownership/recovery checks for code-stage/finalize. The test invokes
# private filesystem helpers against a temporary WP_CONTENT_DIR; no DB/WP APIs.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"

WPRISM_ROOT="$ROOT" php -d display_errors=1 <<'PHP'
<?php
$root = getenv('WPRISM_ROOT');
$target = sys_get_temp_dir() . '/wprism-code-target-' . bin2hex(random_bytes(6));
define('WP_CONTENT_DIR', $target);
define('WP_PLUGIN_DIR', $target . '/custom-plugins');
require_once "$root/agent/src/Kernel/Canon.php";
require_once "$root/agent/src/Code/Code.php";

use WPrism\Code;
use WPrism\CodeMaterializer;

function fail_materializer(string $message): never { throw new RuntimeException("FAIL: $message"); }
function put_materializer(string $path, string $bytes): void {
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
        fail_materializer("cannot create " . dirname($path));
    }
    file_put_contents($path, $bytes);
}
function remove_materializer(string $path): void {
    if (!file_exists($path) && !is_link($path)) { return; }
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $child) {
        if ($child !== '.' && $child !== '..') { remove_materializer($path . '/' . $child); }
    }
    @rmdir($path);
}
function descriptor_materializer(string $source, string $component, string $body): array {
    put_materializer("$source/plugins/$component/$component.php", "<?php\n/*\nPlugin Name: $component\n*/\n$body");
    return Code::descriptor_from_source($source);
}
function file_descriptor_materializer(string $source, string $component, string $body): array {
    put_materializer("$source/plugins/$component", "<?php\n/*\nPlugin Name: $component\n*/\n$body");
    return Code::descriptor_from_source($source);
}

mkdir($target, 0777, true);
$tmp = sys_get_temp_dir() . '/wprism-code-source-' . bin2hex(random_bytes(6));
mkdir($tmp . '/a', 0777, true);
mkdir($tmp . '/b', 0777, true);
register_shutdown_function(static function () use ($tmp, $target): void {
    remove_materializer($tmp);
    remove_materializer($target);
});

$prior = descriptor_materializer($tmp . '/a', 'old', 'v1');
$current = descriptor_materializer($tmp . '/b', 'new', 'v2');
if (!method_exists(CodeMaterializer::class, 'materialize_payload')
    || !method_exists(CodeMaterializer::class, 'verify_payload')) {
    fail_materializer('CodeMaterializer does not expose the materialization seam');
}
// A target promoted before theme_templates existed retains this exact v1
// descriptor in completed/history. A newly compiled descriptor must still be
// able to use that bounded old ownership to prune only old/ during finalize.
$legacyPrior = $prior;
unset($legacyPrior['theme_templates'], $legacyPrior['code_revision']);
$legacyPrior['code_revision'] = Code::revision_for($legacyPrior);
Code::assert_descriptor($legacyPrior);

// Simulate a failed stage that introduced old/ and left partial files, then
// a repaired stage whose desired payload is only new/.  History retains the
// old component root; current roots prune extras while sibling remains intact.
put_materializer($target . '/plugins/old/old.php', file_get_contents($tmp . '/a/plugins/old/old.php'));
put_materializer($target . '/plugins/old/orphan.php', 'partial orphan');
put_materializer($target . '/plugins/new/new.php', file_get_contents($tmp . '/b/plugins/new/new.php'));
put_materializer($target . '/plugins/new/orphan.php', 'stale new');
put_materializer($target . '/plugins/sibling/keep.php', 'unmanaged sibling');

// The extracted collaborator is directly exercised as well as through Code's
// historical private facades below. This catches a facade that merely keeps
// the old implementation while the new class remains unused.
CodeMaterializer::assert_payload_targets($current);
if (CodeMaterializer::created_paths_for_stage($current, null, []) !== []) {
    fail_materializer('CodeMaterializer created-path provenance disagrees with the live target');
}
$directCallbackRan = false;
$directExisting = file_get_contents($target . '/plugins/new/new.php');
try {
    CodeMaterializer::materialize_payload(
        $tmp . '/b',
        $current,
        null,
        null,
        [],
        [],
        static function (?array $previous, ?array $staged, array $history, array $descriptor) use (&$directCallbackRan): void {
            $directCallbackRan = true;
            throw new RuntimeException('direct materializer callback checkpoint');
        }
    );
    fail_materializer('direct CodeMaterializer callback checkpoint was bypassed');
} catch (Throwable $e) {
    if (!$directCallbackRan || $e->getMessage() !== 'direct materializer callback checkpoint') {
        fail_materializer('direct CodeMaterializer did not preserve the preflight/callback boundary: ' . $e->getMessage());
    }
}
if (file_get_contents($target . '/plugins/new/new.php') !== $directExisting) {
    fail_materializer('direct CodeMaterializer callback checkpoint wrote before the ownership callback');
}

$method = new ReflectionMethod(Code::class, 'remove_old_owned_files');
$removed = $method->invoke(null, null, $current, [$legacyPrior], $current);

foreach (['plugins/old/old.php', 'plugins/old/orphan.php', 'plugins/new/orphan.php'] as $path) {
    if (file_exists($target . '/' . $path)) { fail_materializer("failed-stage extra survived: $path"); }
}
if (file_exists($target . '/plugins/old')) { fail_materializer('obsolete component directory survived finalize'); }
if (!file_exists($target . '/plugins/new/new.php')) { fail_materializer('desired current file was removed'); }
if (file_get_contents($target . '/plugins/sibling/keep.php') !== 'unmanaged sibling') {
    fail_materializer('unknown sibling component was touched');
}
if ($removed !== ['plugins/new/orphan.php', 'plugins/old/old.php', 'plugins/old/orphan.php']) {
    fail_materializer('removal inventory was not deterministic: ' . json_encode($removed));
}

// Historical type information is a deletion boundary. Replacing a recorded
// directory with an unowned file must fail before pruning and preserve it.
$dirPrior = descriptor_materializer($tmp . '/dir-prior', 'dir-to-file', 'v1');
mkdir($tmp . '/empty-current', 0777, true);
$emptyCurrent = Code::descriptor_from_source($tmp . '/empty-current');
put_materializer($target . '/plugins/dir-to-file', 'unowned replacement');
try {
    $method->invoke(null, $dirPrior, $emptyCurrent, [], $emptyCurrent);
    fail_materializer('recorded directory replaced by a file was pruned');
} catch (Throwable $e) {
    if (!str_contains($e->getMessage(), "recorded directory 'plugins/dir-to-file' that is now a file")) {
        fail_materializer('directory-to-file refusal was unclear: ' . $e->getMessage());
    }
}
if (file_get_contents($target . '/plugins/dir-to-file') !== 'unowned replacement') {
    fail_materializer('directory-to-file preflight mutated the replacement');
}
unlink($target . '/plugins/dir-to-file');

// The inverse is equally dangerous: recursive pruning must not interpret a
// directory full of new content as the old top-level plugin file.
$filePrior = file_descriptor_materializer($tmp . '/file-prior', 'file-to-dir.php', 'v1');
put_materializer($target . '/plugins/file-to-dir.php/secret.php', 'unowned secret');
try {
    $method->invoke(null, $filePrior, $emptyCurrent, [], $emptyCurrent);
    fail_materializer('recorded file replaced by a directory was pruned');
} catch (Throwable $e) {
    if (!str_contains($e->getMessage(), "recorded file 'plugins/file-to-dir.php' that is now a directory")) {
        fail_materializer('file-to-directory refusal was unclear: ' . $e->getMessage());
    }
}
if (file_get_contents($target . '/plugins/file-to-dir.php/secret.php') !== 'unowned secret') {
    fail_materializer('file-to-directory preflight mutated nested content');
}

// Desired target types are checked as one inventory before the first rename.
// A late lexical conflict must not let an earlier desired file move to v2.
$payloadPriorSource = $tmp . '/payload-prior';
$payloadPrior = descriptor_materializer($payloadPriorSource, 'payload', 'old-payload');
$payloadRepo = $tmp . '/payload-repo';
$payloadSource = $payloadRepo . '/code/wp-content';
put_materializer(
    $payloadSource . '/plugins/payload/payload.php',
    "<?php\n/*\nPlugin Name: payload\n*/\nnew-payload"
);
put_materializer($payloadSource . '/plugins/payload/zz-conflict.php', '<?php new-conflict');
$payloadCurrent = Code::descriptor_from_source($payloadSource);
$oldPayload = file_get_contents($payloadPriorSource . '/plugins/payload/payload.php');
put_materializer($target . '/plugins/payload/payload.php', $oldPayload);
put_materializer($target . '/plugins/payload/zz-conflict.php/keep.txt', 'operator directory');
$materialize = new ReflectionMethod(Code::class, 'materialize_payload');
try {
    $materialize->invoke(null, $payloadRepo, $payloadCurrent, $payloadPrior, null, [], []);
    fail_materializer('late payload type conflict was accepted');
} catch (Throwable $e) {
    if (!str_contains($e->getMessage(), "target path is not a regular file 'plugins/payload/zz-conflict.php'")) {
        fail_materializer('payload type refusal was unclear: ' . $e->getMessage());
    }
}
if (file_get_contents($target . '/plugins/payload/payload.php') !== $oldPayload) {
    fail_materializer('payload preflight changed an earlier target file');
}

// Hash safety is also a whole-removal preflight. A changed late obsolete
// file must preserve an earlier obsolete file instead of partially pruning.
$pruneSource = $tmp . '/prune-prior';
put_materializer(
    $pruneSource . '/plugins/preflight/preflight.php',
    "<?php\n/*\nPlugin Name: preflight\n*/\nowned-main"
);
put_materializer($pruneSource . '/plugins/preflight/z-obsolete.php', '<?php owned-obsolete');
$prunePrior = Code::descriptor_from_source($pruneSource);
$ownedMain = file_get_contents($pruneSource . '/plugins/preflight/preflight.php');
put_materializer($target . '/plugins/preflight/preflight.php', $ownedMain);
put_materializer($target . '/plugins/preflight/z-obsolete.php', '<?php operator-changed');
try {
    $method->invoke(null, $prunePrior, $emptyCurrent, [], $emptyCurrent);
    fail_materializer('changed late obsolete file was pruned');
} catch (Throwable $e) {
    if (!str_contains($e->getMessage(), "changed prior-owned file 'plugins/preflight/z-obsolete.php'")) {
        fail_materializer('changed obsolete refusal was unclear: ' . $e->getMessage());
    }
}
if (file_get_contents($target . '/plugins/preflight/preflight.php') !== $ownedMain) {
    fail_materializer('removal preflight deleted an earlier owned file');
}

// A fully staged top-level user MU plugin can fatal before lifecycle/finalize
// boots. A reviewed retry may remove that exact abandoned staged-only byte so
// WordPress can start again. Completed code and staged-only regular plugins
// remain executable for the fresh lifecycle retirement boundary.
$recoveryPreviousSource = $tmp . '/recovery-previous';
$recoveryStagedSource = $tmp . '/recovery-staged';
$recoveryCurrentSource = $tmp . '/recovery-current';
put_materializer(
    $recoveryPreviousSource . '/plugins/completed/completed.php',
    "<?php\n/*\nPlugin Name: completed\n*/\ncompleted"
);
put_materializer(
    $recoveryStagedSource . '/plugins/completed/completed.php',
    "<?php\n/*\nPlugin Name: completed\n*/\ncompleted"
);
put_materializer(
    $recoveryStagedSource . '/plugins/staged-regular/staged-regular.php',
    "<?php\n/*\nPlugin Name: staged-regular\n*/\nstaged"
);
put_materializer(
    $recoveryStagedSource . '/mu-plugins/fatal-user.php',
    "<?php\nthrow new RuntimeException('staged fatal');\n"
);
put_materializer(
    $recoveryCurrentSource . '/plugins/current/current.php',
    "<?php\n/*\nPlugin Name: current\n*/\ncurrent"
);
$recoveryPrevious = Code::descriptor_from_source($recoveryPreviousSource);
$recoveryStaged = Code::descriptor_from_source($recoveryStagedSource);
$recoveryCurrent = Code::descriptor_from_source($recoveryCurrentSource);
foreach ($recoveryStaged['files'] as $row) {
    put_materializer(
        $target . '/' . $row['path'],
        file_get_contents($recoveryStagedSource . '/' . $row['path'])
    );
}
$recoverAbandoned = new ReflectionMethod(Code::class, 'remove_abandoned_staged_mu_files');
$unproven = $recoverAbandoned->invoke(
    null,
    $recoveryPrevious,
    $recoveryStaged,
    $recoveryCurrent,
    []
);
if ($unproven !== [] || !is_file($target . '/mu-plugins/fatal-user.php')) {
    fail_materializer('staged hash alone granted deletion authority over a pre-existing MU path');
}
$recovered = $recoverAbandoned->invoke(
    null,
    $recoveryPrevious,
    $recoveryStaged,
    $recoveryCurrent,
    ['mu-plugins/fatal-user.php']
);
if ($recovered !== ['mu-plugins/fatal-user.php']) {
    fail_materializer('abandoned MU recovery returned the wrong exact inventory: ' . json_encode($recovered));
}
if (file_exists($target . '/mu-plugins/fatal-user.php')) {
    fail_materializer('exact abandoned staged-only MU file survived recovery');
}
foreach (['plugins/completed/completed.php', 'plugins/staged-regular/staged-regular.php'] as $path) {
    if (!is_file($target . '/' . $path)) {
        fail_materializer("abandoned MU recovery crossed the lifecycle boundary at $path");
    }
}

put_materializer($target . '/mu-plugins/fatal-user.php', "<?php\n// operator changed\n");
try {
    $recoverAbandoned->invoke(
        null,
        $recoveryPrevious,
        $recoveryStaged,
        $recoveryCurrent,
        ['mu-plugins/fatal-user.php']
    );
    fail_materializer('changed abandoned staged MU file was removed');
} catch (Throwable $e) {
    if (!str_contains($e->getMessage(), "refuses changed abandoned staged MU file 'mu-plugins/fatal-user.php'")) {
        fail_materializer('changed abandoned MU refusal was unclear: ' . $e->getMessage());
    }
}
if (file_get_contents($target . '/mu-plugins/fatal-user.php') !== "<?php\n// operator changed\n") {
    fail_materializer('changed abandoned staged MU file was mutated');
}

// Custom WP_PLUGIN_DIR would make the standard payload inert; fail before a
// stage can mutate anything.
$layout = new ReflectionMethod(Code::class, 'assert_target_layout');
try {
    $layout->invoke(null);
    fail_materializer('custom WP_PLUGIN_DIR was accepted');
} catch (Throwable $e) {
    if (!str_contains($e->getMessage(), 'WP_PLUGIN_DIR is custom')) {
        fail_materializer('custom-root refusal was unclear: ' . $e->getMessage());
    }
}
$layout->invoke(null, ['owned_roots' => ['mu-plugins/bootstrap.php']]);

echo "ok: materialization preflights conflicts, prunes recorded roots, preserves siblings, and limits staged-MU recovery to created-path provenance\n";
PHP

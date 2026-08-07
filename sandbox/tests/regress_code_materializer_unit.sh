#!/usr/bin/env bash
# Offline ownership/recovery checks for code-finalize.  The test invokes the
# read-only pruning helper with a temporary WP_CONTENT_DIR; no DB or WP APIs.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

DUO_ROOT="$ROOT" php -d display_errors=1 <<'PHP'
<?php
$root = getenv('DUO_ROOT');
$target = sys_get_temp_dir() . '/duo-code-target-' . bin2hex(random_bytes(6));
define('WP_CONTENT_DIR', $target);
define('WP_PLUGIN_DIR', $target . '/custom-plugins');
require_once "$root/agent/src/Canon.php";
require_once "$root/agent/src/Code.php";

use Duo\Code;

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
$tmp = sys_get_temp_dir() . '/duo-code-source-' . bin2hex(random_bytes(6));
mkdir($tmp . '/a', 0777, true);
mkdir($tmp . '/b', 0777, true);
register_shutdown_function(static function () use ($tmp, $target): void {
    remove_materializer($tmp);
    remove_materializer($target);
});

$prior = descriptor_materializer($tmp . '/a', 'old', 'v1');
$current = descriptor_materializer($tmp . '/b', 'new', 'v2');

// Simulate a failed stage that introduced old/ and left partial files, then
// a repaired stage whose desired payload is only new/.  History retains the
// old component root; current roots prune extras while sibling remains intact.
put_materializer($target . '/plugins/old/old.php', file_get_contents($tmp . '/a/plugins/old/old.php'));
put_materializer($target . '/plugins/old/orphan.php', 'partial orphan');
put_materializer($target . '/plugins/new/new.php', file_get_contents($tmp . '/b/plugins/new/new.php'));
put_materializer($target . '/plugins/new/orphan.php', 'stale new');
put_materializer($target . '/plugins/sibling/keep.php', 'unmanaged sibling');

$method = new ReflectionMethod(Code::class, 'remove_old_owned_files');
$method->setAccessible(true);
$removed = $method->invoke(null, null, $current, [$prior], $current);

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

// Custom WP_PLUGIN_DIR would make the standard payload inert; fail before a
// stage can mutate anything.
$layout = new ReflectionMethod(Code::class, 'assert_target_layout');
$layout->setAccessible(true);
try {
    $layout->invoke(null);
    fail_materializer('custom WP_PLUGIN_DIR was accepted');
} catch (Throwable $e) {
    if (!str_contains($e->getMessage(), 'WP_PLUGIN_DIR is custom')) {
        fail_materializer('custom-root refusal was unclear: ' . $e->getMessage());
    }
}
$layout->invoke(null, ['owned_roots' => ['mu-plugins/bootstrap.php']]);

echo "ok: finalize prunes recorded roots, preserves siblings/type replacements, and rejects custom roots\n";
PHP

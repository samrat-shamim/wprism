#!/usr/bin/env bash
# Offline code/state identity contract.  No WordPress, database, Composer, or
# target filesystem is contacted: the compiler only sees a temporary repo.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

DUO_ROOT="$ROOT" php -d display_errors=1 <<'PHP'
<?php
$root = getenv('DUO_ROOT');
define('DUO_SPEC_VERSION', 2);
foreach (['Canon', 'OptionState', 'Uuid', 'Db', 'Ledger', 'Policy', 'Snapshot', 'Deletion', 'RepositoryAuthorization', 'SidebarState', 'Code', 'RepositoryCompiler', 'CodeStateContract'] as $file) {
    require_once "$root/agent/src/$file.php";
}

use Duo\Canon;
use Duo\Code;
use Duo\OptionState;
use Duo\Policy;
use Duo\RepositoryCompilationException;
use Duo\RepositoryCompiler;

function fail_test(string $message): never { throw new RuntimeException("FAIL: $message"); }
function assert_test(bool $condition, string $message): void {
    if (!$condition) { fail_test($message); }
}
function put_test(string $path, string $bytes): void {
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
        fail_test("cannot create " . dirname($path));
    }
    if (file_put_contents($path, $bytes) === false) { fail_test("cannot write $path"); }
}
function remove_test(string $path): void {
    if (!file_exists($path) && !is_link($path)) { return; }
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $child) {
        if ($child !== '.' && $child !== '..') { remove_test($path . '/' . $child); }
    }
    @rmdir($path);
}

$repo = sys_get_temp_dir() . '/duo-code-contract-' . bin2hex(random_bytes(6));
mkdir($repo . '/state', 0777, true);
register_shutdown_function(static function () use ($repo): void { remove_test($repo); });

put_test($repo . '/site.duo.json', Canon::encode([
    'manifests' => ['core'],
    'policy' => [
        'options' => (object) [], 'post_meta' => (object) [], 'term_meta' => (object) [],
        'post_types' => [], 'taxonomies' => [],
    ],
    'spec_version' => 2,
    'code' => ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'],
]));
put_test($repo . '/code/wp-content/plugins/example/example.php', "<?php\n/*\nPlugin Name: Example\nVersion: 1.0.0\n*/\n");
// A PHP include is inventory-owned but must not be blessed as a plugin main file.
put_test($repo . '/code/wp-content/plugins/example/include.php', "<?php\n// no WordPress Plugin Name header\n");
put_test($repo . '/code/wp-content/plugins/example/late.php', str_repeat("x\n", 5000) . "Plugin Name: Late\n");
// WordPress get_plugins() discovers plugin headers only at the plugins root
// or one directory below it. A deep include must not satisfy active_plugins.
put_test($repo . '/code/wp-content/plugins/example/includes/fake-main.php', "<?php\n/*\nPlugin Name: Deep fake\n*/\n");
put_test($repo . '/code/wp-content/themes/example/style.css', "/*\nTheme Name: Example\n*/\n");
put_test($repo . '/code/wp-content/themes/example/index.php', "<?php\n");
put_test($repo . '/code/wp-content/mu-plugins/bootstrap.php', "<?php\n");

$compile = static function () use ($repo) {
    $policy = Policy::load($repo);
    return RepositoryCompiler::compile($repo, $policy);
};

$baseline = $compile();
$descriptor = $baseline->code_descriptor();
assert_test(is_array($descriptor), 'opted-in repo must carry a code descriptor');
assert_test($descriptor['owned_roots'] === ['mu-plugins/bootstrap.php', 'plugins/example', 'themes/example'], 'ownership must be component-scoped and sorted');
assert_test(count($descriptor['plugin_main_files']) === 1, 'only a WordPress-discoverable Plugin Name header may be a plugin main file');
assert_test($descriptor['plugin_main_files'][0]['basename'] === 'example/example.php', 'plugin basename must be relative to plugins/');
assert_test($descriptor['theme_slugs'] === ['example'], 'theme slug must come from a valid style.css header');
$baselineRevision = $baseline->revision_hash();
$baselineCodeRevision = $baseline->code_revision();

// State-only change: state revision moves, code revision remains independent.
$records = [];
foreach ([
    'active_plugins', 'blogdescription', 'blogname', 'default_category', 'page_for_posts',
    'page_on_front', 'posts_per_page', 'show_on_front', 'sticky_posts', 'stylesheet',
    'template', 'wp_page_for_privacy_policy',
] as $name) {
    $records[$name] = OptionState::absent();
}
put_test($repo . '/state/options/core.json', Canon::encode(OptionState::document($records)));
$stateChanged = $compile();
assert_test($stateChanged->revision_hash() !== $baselineRevision, 'state-only change must change revision_hash');
assert_test($stateChanged->code_revision() === $baselineCodeRevision, 'state-only change must not change code_revision');
assert_test($stateChanged->artifact_hash() !== $baseline->artifact_hash(), 'state-only artifact must still bind the new state');

// The bridge is also a compiler-time gate, not only a stage-time check.
$records['active_plugins'] = OptionState::present(['missing/missing.php'], 'yes');
put_test($repo . '/state/options/core.json', Canon::encode(OptionState::document($records)));
try {
    $compile();
    fail_test('compile accepted an active plugin absent from the code descriptor');
} catch (RepositoryCompilationException $e) {
    assert_test(in_array('code_state_mismatch', array_column($e->diagnostics, 'code'), true), 'compile refusal must identify the code/state bridge');
}

// Code-only change: code descriptor/artifact moves, state revision does not.
unlink($repo . '/state/options/core.json');
file_put_contents($repo . '/code/wp-content/plugins/example/example.php', "<?php\n/*\nPlugin Name: Example\nVersion: 2.0.0\n*/\n");
$codeChanged = $compile();
assert_test($codeChanged->revision_hash() === $baselineRevision, 'code-only change must not change state revision_hash');
assert_test($codeChanged->code_revision() !== $baselineCodeRevision, 'code-only change must change code_revision');
assert_test($codeChanged->artifact_hash() !== $baseline->artifact_hash(), 'artifact_hash must bind the separate code descriptor');

$expectedArtifact = new ReflectionMethod(Code::class, 'assert_expected_artifact');
$expectedArtifact->setAccessible(true);
$expectedArtifact->invoke(null, $baseline, ['artifact_hash' => $baseline->artifact_hash()]);
try {
    $expectedArtifact->invoke(null, $codeChanged, ['artifact_hash' => $baseline->artifact_hash()]);
    fail_test('code materializer accepted a replaced artifact at the same target path');
} catch (ReflectionException $e) {
    throw $e;
} catch (Throwable $e) {
    assert_test(
        str_contains($e->getMessage(), 'does not match the host-compiled artifact hash'),
        'artifact replacement refusal was unclear'
    );
}

echo "ok: code/state revisions stay independent and every materializer phase pins the outer hash\n";
PHP

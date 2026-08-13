#!/usr/bin/env bash
# Offline completed-payload proof: a matching ledger revision is not enough
# when a managed byte changes or an extra file appears in an owned root.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

DUO_ROOT="$ROOT" php -d display_errors=1 <<'PHP'
<?php
$root = getenv('DUO_ROOT');
$target = sys_get_temp_dir() . '/duo-code-completed-' . bin2hex(random_bytes(6));
define('WP_CONTENT_DIR', $target);
define('WP_PLUGIN_DIR', $target . '/plugins');
define('WPMU_PLUGIN_DIR', $target . '/mu-plugins');
function get_theme_root(): string { return WP_CONTENT_DIR . '/themes'; }

final class FakeWpdbCompleted {
    public string $prefix = 'wp_';
    public string $last_error = '';
    /** @var array<string,string> */
    public array $rows = [];
    public function prepare(string $sql, ...$args): string {
        foreach ($args as $arg) {
            $quoted = "'" . addslashes((string) $arg) . "'";
            $sql = preg_replace('/%[sd]/', $quoted, $sql, 1);
        }
        return $sql;
    }
    public function get_var(string $sql): ?string {
        if (preg_match("/WHERE k = '([^']*)'/", $sql, $m)) {
            return $this->rows[$m[1]] ?? null;
        }
        return null;
    }
}

require_once "$root/agent/src/Canon.php";
require_once "$root/agent/src/Code.php";
require_once "$root/agent/src/RepositoryCompiler.php";
require_once "$root/agent/src/Ledger.php";

use Duo\Canon;
use Duo\Code;
use Duo\CompiledRepository;

function fail_completed(string $message): never { throw new RuntimeException("FAIL: $message"); }
function remove_completed(string $path): void {
    if (!file_exists($path) && !is_link($path)) { return; }
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $child) {
        if ($child !== '.' && $child !== '..') { remove_completed($path . '/' . $child); }
    }
    @rmdir($path);
}

$source = sys_get_temp_dir() . '/duo-code-completed-source-' . bin2hex(random_bytes(6));
mkdir($source . '/plugins/example', 0777, true);
file_put_contents($source . '/plugins/example/example.php', "<?php\n/*\nPlugin Name: Example\nVersion: 1.0.0\n*/\n");
mkdir($source . '/themes/example-theme', 0777, true);
file_put_contents($source . '/themes/example-theme/style.css', "/*\nTheme Name: Example Theme\n*/\n");
mkdir($source . '/mu-plugins', 0777, true);
file_put_contents($source . '/mu-plugins/bootstrap.php', "<?php\n// bootstrap\n");
mkdir($target, 0777, true);
$outside = sys_get_temp_dir() . '/duo-code-completed-outside-' . bin2hex(random_bytes(6));
mkdir($outside, 0777, true);
register_shutdown_function(static function () use ($source, $target, $outside): void {
    remove_completed($source);
    remove_completed($target);
    remove_completed($outside);
});

$descriptor = Code::descriptor_from_source($source);
$payload = [
    'compiler_version' => 1, 'spec_version' => 2,
    'site_hash' => str_repeat('a', 64), 'manifest_hash' => str_repeat('b', 64),
    'revision_hash' => str_repeat('c', 64), 'media_catalog' => [], 'media' => [],
    'tree' => [], 'deletions' => [], 'code' => $descriptor,
];
$compiled = CompiledRepository::create($payload);
foreach ($descriptor['files'] as $row) {
    $src = $source . '/' . $row['path'];
    $dst = $target . '/' . $row['path'];
    if (!is_dir(dirname($dst))) { mkdir(dirname($dst), 0777, true); }
    copy($src, $dst);
}

$GLOBALS['wpdb'] = new FakeWpdbCompleted();
$GLOBALS['wpdb']->rows[Code::CODE_REVISION_KEY] = $descriptor['code_revision'];
$GLOBALS['wpdb']->rows[Code::CODE_DESCRIPTOR_KEY] = Canon::encode($descriptor);
$check = static function (?string $detail, string $message): void {
    if ($detail !== null) { fail_completed($message . ': ' . $detail); }
};

// Lifecycle deploy authorization is stricter than a matching revision
// string. It requires the complete staged descriptor, the artifact identity,
// and a fresh target-hash proof. These are all offline checks against the
// same temporary target used by the completed-marker cases below.
$GLOBALS['wpdb']->rows[Code::CODE_STAGE_REVISION_KEY] = $descriptor['code_revision'];
$GLOBALS['wpdb']->rows[Code::CODE_STAGE_DESCRIPTOR_KEY] = Canon::encode($descriptor);
$GLOBALS['wpdb']->rows[Code::CODE_STAGE_ARTIFACT_KEY] = $compiled->artifact_hash();
Code::assert_verified_staged($compiled);

$savedStageDescriptor = $GLOBALS['wpdb']->rows[Code::CODE_STAGE_DESCRIPTOR_KEY];
unset($GLOBALS['wpdb']->rows[Code::CODE_STAGE_DESCRIPTOR_KEY]);
$stageFailure = null;
try {
    Code::assert_verified_staged($compiled);
} catch (Throwable $e) {
    $stageFailure = $e->getMessage();
}
if ($stageFailure === null || !str_contains($stageFailure, 'no code_stage_descriptor')) {
    fail_completed('missing staged descriptor was accepted');
}
$GLOBALS['wpdb']->rows[Code::CODE_STAGE_DESCRIPTOR_KEY] = $savedStageDescriptor;

$otherSource = sys_get_temp_dir() . '/duo-code-completed-other-' . bin2hex(random_bytes(6));
mkdir($otherSource . '/plugins/other', 0777, true);
file_put_contents($otherSource . '/plugins/other/other.php', "<?php\n/*\nPlugin Name: Other\n*/\n");
$otherDescriptor = Code::descriptor_from_source($otherSource);
$GLOBALS['wpdb']->rows[Code::CODE_STAGE_DESCRIPTOR_KEY] = Canon::encode($otherDescriptor);
$stageFailure = null;
try {
    Code::assert_verified_staged($compiled);
} catch (Throwable $e) {
    $stageFailure = $e->getMessage();
}
if ($stageFailure === null || !str_contains($stageFailure, 'staged code descriptor does not match')) {
    fail_completed('mismatched staged descriptor was accepted');
}
$GLOBALS['wpdb']->rows[Code::CODE_STAGE_DESCRIPTOR_KEY] = $savedStageDescriptor;
remove_completed($otherSource);

$originalTargetBytes = file_get_contents($target . '/plugins/example/example.php');
file_put_contents($target . '/plugins/example/example.php', (string) $originalTargetBytes . "// staged byte drift\n");
$stageFailure = null;
try {
    Code::assert_verified_staged($compiled);
} catch (Throwable $e) {
    $stageFailure = $e->getMessage();
}
if ($stageFailure === null || !str_contains($stageFailure, 'verification failed')) {
    fail_completed('changed staged target file was accepted');
}
file_put_contents($target . '/plugins/example/example.php', (string) $originalTargetBytes);

// An artifact with the same opaque code revision but different state payload
// must not reuse this stage. The artifact hash binds both halves even though
// revision_hash itself intentionally remains state/media-only.
$differentStatePayload = $payload;
$differentStatePayload['revision_hash'] = str_repeat('d', 64);
$differentArtifact = CompiledRepository::create($differentStatePayload);
$stageFailure = null;
try {
    Code::assert_verified_staged($differentArtifact);
} catch (Throwable $e) {
    $stageFailure = $e->getMessage();
}
if ($stageFailure === null || !str_contains($stageFailure, 'staged code artifact does not match')) {
    fail_completed('same-code/different-artifact stage reuse was accepted');
}

// A post-stage replacement of any standard managed root must not redirect
// lifecycle verification through a textual path into an external tree.
foreach (['plugins', 'themes', 'mu-plugins'] as $managedRoot) {
    rename($target . '/' . $managedRoot, $outside . '/' . $managedRoot);
    symlink($outside . '/' . $managedRoot, $target . '/' . $managedRoot);
    $stageFailure = null;
    try {
        Code::assert_verified_staged($compiled);
    } catch (Throwable $e) {
        $stageFailure = $e->getMessage();
    }
    if ($stageFailure === null || !str_contains($stageFailure, "symbolic-link target path '$managedRoot'")) {
        fail_completed("post-stage $managedRoot symlink was accepted");
    }
    unlink($target . '/' . $managedRoot);
    rename($outside . '/' . $managedRoot, $target . '/' . $managedRoot);
}

unset(
    $GLOBALS['wpdb']->rows[Code::CODE_STAGE_REVISION_KEY],
    $GLOBALS['wpdb']->rows[Code::CODE_STAGE_DESCRIPTOR_KEY],
    $GLOBALS['wpdb']->rows[Code::CODE_STAGE_ARTIFACT_KEY],
    $GLOBALS['wpdb']->rows[Code::CODE_STAGE_HISTORY_KEY]
);
$check(Code::completed_code_mismatch($compiled), 'matching ledger and target should be clean');

file_put_contents($target . '/plugins/example/example.php', "<?php\n/*\nPlugin Name: Example\nVersion: 1.0.0\n*/\n// byte drift\n");
$drift = Code::completed_code_mismatch($compiled);
if ($drift === null || !str_contains($drift, 'verification failed')) {
    fail_completed('same-version byte edit was not reported as stale');
}
copy($source . '/plugins/example/example.php', $target . '/plugins/example/example.php');
file_put_contents($target . '/plugins/example/extra.php', '<?php // unmanaged after completion');
$extra = Code::completed_code_mismatch($compiled);
if ($extra === null || !str_contains($extra, 'unrecorded file')) {
    fail_completed('extra file in an owned component was not reported as stale');
}
unlink($target . '/plugins/example/extra.php');

// Completed-state proof has the same containment gate, so status/deploy
// reports the drift without hashing or traversing the external destination.
foreach (['plugins', 'themes', 'mu-plugins'] as $managedRoot) {
    rename($target . '/' . $managedRoot, $outside . '/' . $managedRoot);
    symlink($outside . '/' . $managedRoot, $target . '/' . $managedRoot);
    $linkDrift = Code::completed_code_mismatch($compiled);
    if ($linkDrift === null || !str_contains($linkDrift, "symbolic-link target path '$managedRoot'")) {
        fail_completed("completed $managedRoot symlink was accepted");
    }
    unlink($target . '/' . $managedRoot);
    rename($outside . '/' . $managedRoot, $target . '/' . $managedRoot);
}

echo "ok: completed/staged code proof verifies hashes, extras, and managed-root containment\n";
PHP

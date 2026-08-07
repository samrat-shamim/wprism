#!/usr/bin/env bash
# A mismatched artifact may not tear down the live promotion it failed to
# continue. Exercise Code::stage through its public boundary with a rejecting
# PromotionLock double and prove no legacy release/rotation occurs first.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

DUO_ROOT="$ROOT" php -d display_errors=1 <<'PHP'
<?php
namespace Duo;

$root = getenv('DUO_ROOT');
$target = sys_get_temp_dir() . '/duo-code-stage-lock-target-' . bin2hex(random_bytes(6));
$repo = sys_get_temp_dir() . '/duo-code-stage-lock-repo-' . bin2hex(random_bytes(6));
define('WP_CONTENT_DIR', $target);
define('WP_PLUGIN_DIR', $target . '/plugins');
define('WPMU_PLUGIN_DIR', $target . '/mu-plugins');

final class CompiledRepository {
    public function __construct(private array $descriptor, private string $artifact) {}
    public function code_descriptor(): ?array { return $this->descriptor; }
    public function artifact_hash(): string { return $this->artifact; }
}
final class CodeStateContract {
    public static function validate(CompiledRepository $compiled, array $descriptor): void {}
}
final class Ledger {
    public static function ensure(): void {}
}
final class PromotionLock {
    public static int $acquires = 0;
    public static int $releases = 0;
    public static array $lastAcquire = [];
    public static function acquire(string $owner, string $artifact, string $phase, ?int $ttl, bool $continuation): array {
        self::$acquires++;
        self::$lastAcquire = [$owner, $artifact, $phase, $ttl, $continuation];
        throw new \RuntimeException('test: begun owner/artifact session was superseded');
    }
    public static function release(string $owner, string $artifact): void { self::$releases++; }
}

require_once "$root/agent/src/Canon.php";
require_once "$root/agent/src/Code.php";

function remove_stage_lock(string $path): void {
    if (!file_exists($path) && !is_link($path)) { return; }
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $child) {
        if ($child !== '.' && $child !== '..') { remove_stage_lock($path . '/' . $child); }
    }
    @rmdir($path);
}

mkdir($repo . '/code/wp-content/plugins/example', 0777, true);
mkdir($target, 0777, true);
file_put_contents(
    $repo . '/code/wp-content/plugins/example/example.php',
    "<?php\n/*\nPlugin Name: Example\n*/\n"
);
register_shutdown_function(static function () use ($repo, $target): void {
    remove_stage_lock($repo);
    remove_stage_lock($target);
});

$descriptor = Code::descriptor_from_source($repo . '/code/wp-content');
$artifact = str_repeat('2', 64);
$compiled = new CompiledRepository($descriptor, $artifact);
$failure = null;
try {
    Code::stage($repo, $compiled, [
        'artifact_hash' => $artifact,
        'promotion_owner' => 'same-owner-as-live-h1',
    ]);
} catch (\Throwable $e) {
    $failure = $e->getMessage();
}
if ($failure === null || !str_contains($failure, 'superseded')) {
    throw new \RuntimeException('FAIL: strict continuation failure was not propagated');
}
if (PromotionLock::$acquires !== 1
    || PromotionLock::$lastAcquire !== ['same-owner-as-live-h1', $artifact, 'code-stage', null, true]) {
    throw new \RuntimeException('FAIL: code-stage did not request one exact strict continuation');
}
if (PromotionLock::$releases !== 0) {
    throw new \RuntimeException('FAIL: mismatched code-stage released the live prior artifact');
}

echo "ok: code-stage rejects artifact rotation without releasing the live promotion\n";
PHP

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
    public static array $rows = [];
    public static function ensure(): void {}
    public static function kv_get(string $key): ?string { return self::$rows[$key] ?? null; }
    public static function kv_set(string $key, string $value): void { self::$rows[$key] = $value; }
}
final class PromotionLock {
    public static bool $reject = true;
    public static int $acquires = 0;
    public static int $releases = 0;
    public static array $lastAcquire = [];
    public static function acquire(string $owner, string $artifact, string $phase, ?int $ttl, bool $continuation): array {
        self::$acquires++;
        self::$lastAcquire = [$owner, $artifact, $phase, $ttl, $continuation];
        if (self::$reject) {
            throw new \RuntimeException('test: begun owner/artifact session was superseded');
        }
        return ['owner' => $owner, 'artifact_hash' => $artifact, 'phase' => $phase];
    }
    public static function heartbeat(string $owner, string $artifact, string $phase): void {}
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

// A descriptor that fails the whole-target preflight has written no bytes and
// must not become future deletion authority. In particular, merely declaring
// a new component may not let a later promotion prune an operator-owned file
// that happened to live under that never-materialized component root.
file_put_contents(
    $repo . '/code/wp-content/plugins/example/example.php',
    "<?php\n/*\nPlugin Name: Example\n*/\n// candidate\n"
);
mkdir($repo . '/code/wp-content/plugins/foreign-candidate', 0777, true);
file_put_contents(
    $repo . '/code/wp-content/plugins/foreign-candidate/candidate.php',
    "<?php\n/*\nPlugin Name: Candidate\n*/\n"
);
mkdir($repo . '/code/wp-content/plugins/z-conflict', 0777, true);
file_put_contents(
    $repo . '/code/wp-content/plugins/z-conflict/z.php',
    "<?php\n/*\nPlugin Name: Conflict\n*/\n"
);
mkdir($target . '/plugins/foreign-candidate', 0777, true);
file_put_contents($target . '/plugins/foreign-candidate/foreign.php', '<?php // operator owned');
mkdir($target . '/plugins/z-conflict/z.php', 0777, true);
file_put_contents($target . '/plugins/z-conflict/z.php/keep.txt', 'operator directory');

Ledger::$rows = [];
PromotionLock::$reject = false;
$candidate = Code::descriptor_from_source($repo . '/code/wp-content');
$candidateCompiled = new CompiledRepository($candidate, $artifact);
$preflightFailure = null;
try {
    Code::stage($repo, $candidateCompiled, [
        'artifact_hash' => $artifact,
        'promotion_owner' => 'preflight-owner',
    ]);
} catch (\Throwable $e) {
    $preflightFailure = $e->getMessage();
}
if ($preflightFailure === null || !str_contains($preflightFailure, "target path is not a regular file 'plugins/z-conflict/z.php'")) {
    throw new \RuntimeException('FAIL: deterministic late target conflict did not fail stage preflight');
}
if (isset(Ledger::$rows[Code::CODE_STAGE_HISTORY_KEY])) {
    throw new \RuntimeException('FAIL: a no-write stage attempt was persisted as deletion ownership');
}
if (file_exists($target . '/plugins/foreign-candidate/candidate.php')
    || file_get_contents($target . '/plugins/foreign-candidate/foreign.php') !== '<?php // operator owned') {
    throw new \RuntimeException('FAIL: failed preflight mutated the candidate component');
}

// Remove the unrelated conflict and model the next revision omitting every
// candidate component. With no admitted history, its cleanup inventory has
// no authority to traverse foreign-candidate/.
remove_stage_lock($target . '/plugins/z-conflict');
$empty = $repo . '/empty-code';
mkdir($empty, 0777, true);
$emptyDescriptor = Code::descriptor_from_source($empty);
$history = [];
if (isset(Ledger::$rows[Code::CODE_STAGE_HISTORY_KEY])) {
    foreach (Canon::decode(Ledger::$rows[Code::CODE_STAGE_HISTORY_KEY]) as $row) {
        $history[$row['code_revision']] = $row;
    }
}
$remove = new \ReflectionMethod(Code::class, 'remove_old_owned_files');
$remove->setAccessible(true);
$remove->invoke(null, null, null, $history, $emptyDescriptor);
if (file_get_contents($target . '/plugins/foreign-candidate/foreign.php') !== '<?php // operator owned') {
    throw new \RuntimeException('FAIL: later cleanup traversed a never-materialized component root');
}

echo "ok: failed preflight grants no future component-root deletion authority\n";
PHP

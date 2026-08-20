#!/usr/bin/env bash
# Failure injection for finalize's staged->completed code-ledger handoff. Every
# statement and COMMIT must roll back to the exact retryable staged snapshot.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"

DUO_ROOT="$ROOT" php -d display_errors=1 <<'PHP'
<?php
namespace Duo;

final class Ledger {
    /** @var array<string,string> */
    public static array $rows = [];
    public static int $mutation = 0;
    public static ?int $failAt = null;
    private static function changed(): void {
        self::$mutation++;
        if (self::$failAt === self::$mutation) {
            throw new \RuntimeException('injected ledger statement failure');
        }
    }
    public static function kv_set(string $key, string $value): void {
        self::$rows[$key] = $value;
        self::changed();
    }
    public static function kv_delete(string $key): void {
        unset(self::$rows[$key]);
        self::changed();
    }
}
final class Db {
    /** @var ?array<string,string> */
    public static ?array $snapshot = null;
    public static int $commits = 0;
    public static int $rollbacks = 0;
    public static bool $failCommit = false;
    public static function start(string $context): void { self::$snapshot = Ledger::$rows; }
    public static function commit(string $context): void {
        self::$commits++;
        if (self::$failCommit) { throw new \RuntimeException('injected commit failure'); }
        self::$snapshot = null;
    }
    public static function rollback(string $context): void {
        self::$rollbacks++;
        Ledger::$rows = self::$snapshot ?? [];
        self::$snapshot = null;
    }
}

$root = getenv('DUO_ROOT');
require_once "$root/agent/src/Kernel/Canon.php";
require_once "$root/agent/src/Code/Code.php";

$revision = str_repeat('a', 64);
$descriptor = ['code_revision' => $revision, 'proof' => 'completed descriptor'];
$staged = [
    Code::CODE_STAGE_DESCRIPTOR_KEY => '{"staged":true}',
    Code::CODE_STAGE_ARTIFACT_KEY => str_repeat('b', 64),
    Code::CODE_STAGE_HISTORY_KEY => '[{"history":true}]',
    Code::CODE_STAGE_CREATED_PATHS_KEY => '["mu-plugins/staged.php"]',
    Code::CODE_STAGE_REVISION_KEY => $revision,
    Code::CODE_DESCRIPTOR_KEY => '{"proof":"prior completed descriptor"}',
    Code::CODE_REVISION_KEY => str_repeat('c', 64),
];
$publish = new \ReflectionMethod(Code::class, 'publish_completed_descriptor');

$reset = static function () use ($staged): void {
    Ledger::$rows = $staged;
    Ledger::$mutation = 0;
    Ledger::$failAt = null;
    Db::$snapshot = null;
    Db::$commits = 0;
    Db::$rollbacks = 0;
    Db::$failCommit = false;
};

for ($failure = 1; $failure <= 7; $failure++) {
    $reset();
    Ledger::$failAt = $failure;
    try {
        $publish->invoke(null, $descriptor);
        throw new \RuntimeException("FAIL: ledger mutation $failure did not fail");
    } catch (\ReflectionException $e) {
        throw $e;
    } catch (\Throwable $e) {
        if ($e->getMessage() !== 'injected ledger statement failure') { throw $e; }
    }
    if (Ledger::$rows !== $staged || Db::$rollbacks !== 1 || Db::$commits !== 0) {
        throw new \RuntimeException("FAIL: ledger mutation $failure left a partial finalization");
    }
}

$reset();
Db::$failCommit = true;
try {
    $publish->invoke(null, $descriptor);
    throw new \RuntimeException('FAIL: COMMIT failure did not fail');
} catch (\ReflectionException $e) {
    throw $e;
} catch (\Throwable $e) {
    if ($e->getMessage() !== 'injected commit failure') { throw $e; }
}
if (Ledger::$rows !== $staged || Db::$rollbacks !== 1 || Db::$commits !== 1) {
    throw new \RuntimeException('FAIL: COMMIT failure left a partial finalization');
}

$reset();
$publish->invoke(null, $descriptor);
foreach ([
    Code::CODE_STAGE_DESCRIPTOR_KEY,
    Code::CODE_STAGE_ARTIFACT_KEY,
    Code::CODE_STAGE_HISTORY_KEY,
    Code::CODE_STAGE_CREATED_PATHS_KEY,
    Code::CODE_STAGE_REVISION_KEY,
] as $temporary) {
    if (array_key_exists($temporary, Ledger::$rows)) {
        throw new \RuntimeException("FAIL: successful finalization retained $temporary");
    }
}
if ((Ledger::$rows[Code::CODE_DESCRIPTOR_KEY] ?? null) !== Canon::encode($descriptor)
    || (Ledger::$rows[Code::CODE_REVISION_KEY] ?? null) !== $revision
    || Db::$commits !== 1 || Db::$rollbacks !== 0) {
    throw new \RuntimeException('FAIL: successful finalization did not atomically publish descriptor/revision');
}

echo "ok: code-ledger finalization is atomic across every statement and commit failure\n";
PHP

<?php
declare(strict_types=1);

namespace Duo;

if (!class_exists(Canon::class, false)) {
    require_once __DIR__ . '/../Kernel/Canon.php';
}
if (!class_exists(Secrets::class, false)) {
    require_once __DIR__ . '/../Kernel/Secrets.php';
}
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}
if (!class_exists(RefreshExport::class, false)) {
    require_once __DIR__ . '/../Review/RefreshExport.php';
}
if (!class_exists(OriginStore::class, false)) {
    require_once __DIR__ . '/OriginStore.php';
}

/**
 * Seal one strict production observation for later outbound transfer.
 *
 * This boundary intentionally has no HTTP client and accepts no remote action,
 * script, argv, SQL, callback URL, or repository path. A locally configured
 * caller supplies only a content-addressed session and the exact Git commit it
 * expects this already-adopted repository to represent. The live target is
 * touched once; a sealed retry is satisfied exclusively from OriginStore.
 */
final class OriginExporter {
    /**
     * @return array<string,mixed> verified duo-origin-export-manifest/v1
     */
    public static function seal(string $repo, string $sessionId, string $expectedCommit): array {
        // This is deliberately first. Ordinary web/plugin/MU bootstrap can run
        // arbitrary DML before RefreshExport opens its READ ONLY transaction;
        // even an invalid/missing repo must not be inspected from that context.
        if (!defined('WP_CLI') || WP_CLI !== true
            || !defined('DUO_CONTROL_PLANE') || DUO_CONTROL_PLANE !== true) {
            throw new \RuntimeException(
                'duo: origin export requires the isolated WP-CLI control plane'
            );
        }
        self::assertSessionId($sessionId);
        self::assertCommit($expectedCommit);

        $store = OriginStore::open($repo);
        return $store->withExclusive(static function () use ($store, $sessionId, $expectedCommit): array {
            // The terminal local artifact is the recovery boundary. This check
            // intentionally precedes Git, Policy, WordPress, and DB access.
            $sealed = $store->readSealed($sessionId, $expectedCommit);
            if ($sealed !== null) {
                return $sealed;
            }

            $root = $store->repository();
            self::assertExactGitState($root, $expectedCommit);
            $policy = Policy::load($root);
            self::assertEgressEligible($policy);

            // Exactly one strict live observation. Network transfer belongs
            // after this method and can resume from the sealed bytes without
            // reopening the MVCC snapshot, repository, media, or WordPress.
            $export = RefreshExport::run($root, false, null);
            self::assertExactGitState($root, $expectedCommit);
            self::assertProductionExport($export);
            $secret = Secrets::hard_match_deep($export);
            if ($secret !== null) {
                throw new \RuntimeException(
                    "duo: origin export refused — completed snapshot contains a high-confidence $secret"
                );
            }
            $canonical = Canon::encode($export);

            return $store->publish(
                $sessionId,
                $expectedCommit,
                $canonical,
                [
                    'format' => (string) $export['format'],
                    'repository' => $export['repository'],
                    'snapshot_hash' => (string) $export['snapshot_hash'],
                ]
            );
        });
    }

    /**
     * Sensitive capture permission never implicitly authorizes cloud egress.
     * Policy combines static declarations with each interpreter's reviewed,
     * context-independent capability before this boundary touches live data.
     */
    private static function assertEgressEligible(Policy $policy): void {
        $grants = $policy->egress_sensitivity_grants();
        if ($grants !== []) {
            throw new \RuntimeException(
                "duo: origin export refused — policy contains an explicit {$grants[0]} grant"
            );
        }
    }

    /** @param mixed $export */
    private static function assertProductionExport(mixed $export): void {
        if (!is_array($export) || array_is_list($export)
            || ($export['format'] ?? null) !== RefreshExport::FORMAT
            || !self::isSha256($export['snapshot_hash'] ?? null)) {
            throw new \RuntimeException('duo: origin export did not produce a canonical production snapshot');
        }
        foreach (['deletions', 'media', 'policy', 'records', 'repository', 'warnings'] as $field) {
            if (!is_array($export[$field] ?? null)) {
                throw new \RuntimeException("duo: origin export production snapshot has invalid '$field'");
            }
        }
        $basis = $export;
        $claimed = (string) $basis['snapshot_hash'];
        unset($basis['snapshot_hash']);
        if (!hash_equals($claimed, hash('sha256', Canon::encode($basis)))) {
            throw new \RuntimeException('duo: origin export production snapshot hash does not verify');
        }
        $repository = $export['repository'];
        $keys = is_array($repository) ? array_keys($repository) : [];
        sort($keys, SORT_STRING);
        if ($keys !== ['artifact_hash', 'code_revision', 'revision_hash']
            || !self::isSha256($repository['artifact_hash'] ?? null)
            || !self::isSha256($repository['revision_hash'] ?? null)
            || (($repository['code_revision'] ?? null) !== null
                && !self::isSha256($repository['code_revision']))) {
            throw new \RuntimeException('duo: origin export repository identity is malformed');
        }
    }

    /**
     * Match Refresh's existing target topology proof: exact HEAD, no tracked or
     * ordinary untracked changes anywhere, and no ignored canonical compiler
     * inputs. `--no-optional-locks` keeps these fixed read commands read-only.
     */
    private static function assertExactGitState(string $repo, string $expectedCommit): void {
        $head = self::git($repo, ['rev-parse', '--verify', 'HEAD^{commit}']);
        if ($head['exit'] !== 0 || !hash_equals($expectedCommit, trim($head['stdout']))) {
            throw new \RuntimeException('duo: origin export repository HEAD does not match the expected production commit');
        }
        $status = self::git($repo, ['status', '--porcelain=v1', '--untracked-files=all']);
        if ($status['exit'] !== 0 || trim($status['stdout']) !== '') {
            throw new \RuntimeException('duo: origin export repository has tracked or untracked changes');
        }
        $ignored = self::git($repo, [
            'ls-files', '--others', '--ignored', '--exclude-standard', '--',
            'site.duo.json', 'state', 'media', 'code', 'manifests',
        ]);
        if ($ignored['exit'] !== 0 || trim($ignored['stdout']) !== '') {
            throw new \RuntimeException('duo: origin export repository has ignored canonical compiler inputs');
        }
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private static function git(string $repo, array $arguments): array {
        if (!function_exists('proc_open')) {
            return ['exit' => 127, 'stdout' => '', 'stderr' => ''];
        }
        $pipes = [];
        $process = @proc_open(
            array_merge(['git', '--no-optional-locks', '-C', $repo], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            return ['exit' => 127, 'stdout' => '', 'stderr' => ''];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [
            'exit' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private static function assertSessionId(string $sessionId): void {
        if (preg_match('/^[a-f0-9]{64}$/D', $sessionId) !== 1) {
            throw new \RuntimeException('duo: origin export session id must be lowercase SHA-256');
        }
    }

    private static function assertCommit(string $commit): void {
        if (preg_match('/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/D', $commit) !== 1) {
            throw new \RuntimeException('duo: origin export expected production commit is malformed');
        }
    }

    private static function isSha256(mixed $value): bool {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}

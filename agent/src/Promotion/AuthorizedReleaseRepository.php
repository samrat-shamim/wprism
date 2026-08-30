<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/../Kernel/CommandRefusal.php';

/**
 * Target-side Git binding held through promotion-lease election.
 *
 * The controller's repository checks are evidence, not serialization. This
 * object takes the target-private repository flock, rechecks the exact
 * materialized commit/tree and clean worktree, and retains the kernel lock
 * until `PromotionLock::begin()` has elected the artifact-bound database
 * lease in the same WP-CLI process.
 */
final class AuthorizedReleaseRepository {
    /** @param resource $lock */
    private function __construct(private $lock, private string $lockPath) {}

    public static function acquire(
        string $repo,
        string $operationId,
        string $sourceCommit,
        string $sourceTree,
        string $promotionOwner
    ): self {
        if ($repo === '' || $repo[0] !== '/' || str_contains($repo, "\0")
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@+\/-]{0,255}$/D', $operationId) !== 1
            || preg_match('/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/D', $sourceCommit) !== 1
            || preg_match('/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/D', $sourceTree) !== 1) {
            throw self::refuse(
                'promotion_repository_binding_invalid',
                'the authorized release repository binding is malformed'
            );
        }
        $expectedOwner = 'authorized-release-' . hash(
            'sha256',
            $operationId . "\0" . $sourceCommit . "\0" . $sourceTree
        );
        if (!hash_equals($expectedOwner, $promotionOwner)) {
            throw self::refuse(
                'promotion_repository_binding_invalid',
                'the promotion lease owner does not identify this exact authorized source'
            );
        }

        $gitDirectory = self::git($repo, ['rev-parse', '--absolute-git-dir']);
        if ($gitDirectory === '' || $gitDirectory[0] !== '/') {
            throw self::changed('the target repository has no absolute private Git directory');
        }
        $controlRoot = rtrim($gitDirectory, '/') . '/wprism-control';
        $lockPath = $controlRoot . '/repository.lock';
        if (!is_dir($controlRoot) || is_link($controlRoot)
            || is_link($lockPath) || !is_file($lockPath)) {
            throw self::changed('the target repository lock established by materialization is unavailable');
        }
        $lockBefore = @lstat($lockPath);
        $lock = @fopen($lockPath, 'rb');
        $lockAfter = is_resource($lock) ? @fstat($lock) : false;
        if (!is_array($lockBefore) || !is_array($lockAfter)
            || (($lockBefore['mode'] ?? 0) & 0170000) !== 0100000
            || (($lockAfter['mode'] ?? 0) & 0170000) !== 0100000
            || ($lockBefore['dev'] ?? null) !== ($lockAfter['dev'] ?? null)
            || ($lockBefore['ino'] ?? null) !== ($lockAfter['ino'] ?? null)
            || !is_resource($lock) || !@flock($lock, LOCK_EX | LOCK_NB)
            || !self::lockBound($lock, $lockPath)) {
            if (is_resource($lock)) {
                @fclose($lock);
            }
            throw self::changed('another target repository operation owns the materialization lock');
        }

        try {
            $status = self::git($repo, ['status', '--porcelain', '--untracked-files=all'], false);
            $statusLines = $status === '' ? [] : preg_split('/\r?\n/D', $status);
            if (!is_array($statusLines)) {
                throw self::changed('the canonical target repository status could not be interpreted');
            }
            $unexpected = array_values(array_filter(
                $statusLines,
                static fn(string $line): bool => preg_match(
                    '#^\?\? \.wprism/(?:artifacts|checkpoints|code-release-prepare|code-push)/#D',
                    $line
                ) !== 1
            ));
            if ($unexpected !== []) {
                throw self::changed('the canonical target repository has tracked or untracked worktree changes');
            }
            $actualCommit = self::git($repo, ['rev-parse', '--verify', 'HEAD']);
            $actualTree = self::git($repo, ['rev-parse', '--verify', 'HEAD^{tree}']);
            if (!hash_equals($sourceCommit, $actualCommit) || !hash_equals($sourceTree, $actualTree)) {
                throw self::changed('the canonical target repository no longer has the authorized commit and tree');
            }
            if (!self::lockBound($lock, $lockPath)) {
                throw self::changed('the acquired repository lock path was replaced before promotion-lease election');
            }

            return new self($lock, $lockPath);
        } catch (\Throwable $error) {
            @flock($lock, LOCK_UN);
            @fclose($lock);
            throw $error;
        }
    }

    /** Refuse if another worker could now acquire a replacement lock inode. */
    public function assertBound(): void {
        if (!self::lockBound($this->lock, $this->lockPath)) {
            throw self::changed('the acquired repository lock path was replaced before promotion-lease election');
        }
    }

    public function __destruct() {
        if (is_resource($this->lock)) {
            @flock($this->lock, LOCK_UN);
            @fclose($this->lock);
        }
    }

    /** @param list<string> $arguments */
    private static function git(string $repo, array $arguments, bool $trim = true): string {
        $process = @proc_open(
            array_merge(['git', '-C', $repo], $arguments),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw self::changed('the target Git binding could not be inspected');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw self::changed('the target Git binding could not be inspected');
        }

        return $trim ? trim($stdout) : rtrim($stdout, "\r\n");
    }

    /** @param resource $lock */
    private static function lockBound($lock, string $path): bool {
        $held = is_resource($lock) ? @fstat($lock) : false;
        $named = @lstat($path);

        return is_array($held) && is_array($named)
            && (($named['mode'] ?? 0) & 0170000) === 0100000
            && ($held['dev'] ?? null) === ($named['dev'] ?? null)
            && ($held['ino'] ?? null) === ($named['ino'] ?? null);
    }

    private static function changed(string $privateDetail): CommandRefusalException {
        return self::refuse('promotion_repository_binding_changed', $privateDetail);
    }

    private static function refuse(string $code, string $privateDetail): CommandRefusalException {
        return new CommandRefusalException(
            $code,
            'the canonical target repository no longer matches the externally authorized release source',
            'do not promote; preserve current target bytes and reconcile the consumed release operation',
            [],
            $privateDetail
        );
    }
}

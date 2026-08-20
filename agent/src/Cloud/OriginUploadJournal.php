<?php
declare(strict_types=1);

namespace Duo;

if (!class_exists(Canon::class, false)) {
    require_once __DIR__ . '/../Kernel/Canon.php';
}

/** @internal Mutable view held under OriginUploadJournal's exclusive lock. */
final class OriginUploadJournalSession {
    /** @var ?array<string,mixed> */
    private ?array $state;
    /** @var \Closure(array<string,mixed>):void */
    private \Closure $writer;

    /**
     * @param ?array<string,mixed> $state
     * @param \Closure(array<string,mixed>):void $writer
     */
    public function __construct(?array $state, \Closure $writer) {
        $this->state = $state;
        $this->writer = $writer;
    }

    /** @return ?array<string,mixed> */
    public function state(): ?array {
        return $this->state;
    }

    /** @param array<string,mixed> $state */
    public function save(array $state): void {
        ($this->writer)($state);
        $this->state = $state;
    }
}

/**
 * Private atomic journal for generation-bound outbound upload progress.
 *
 * The journal intentionally stores only identities, cursors, and receipts.
 * Export bytes remain in OriginStore and private keys/device codes remain in
 * OriginPairingStateStore, so recovery evidence cannot become a second secret
 * or production-content store.
 */
final class OriginUploadJournal {
    private const MAX_BYTES = 65536;

    private string $directory;
    private string $statePath;
    private string $lockPath;

    public function __construct(string $directory) {
        self::assertControlPlane();
        if ($directory === '' || $directory[0] !== '/' || str_contains($directory, "\0")) {
            throw new \RuntimeException('duo: cloud origin upload journal directory is invalid');
        }
        $directory = rtrim($directory, '/');
        if ($directory === '') {
            throw new \RuntimeException('duo: cloud origin upload journal directory is invalid');
        }
        self::ensurePrivateDirectory($directory);
        $resolved = realpath($directory);
        if (!is_string($resolved)) {
            throw new \RuntimeException('duo: cloud origin upload journal directory could not be resolved');
        }
        $this->directory = rtrim($resolved, '/');
        self::ensurePrivateDirectory($this->directory);
        $this->statePath = $this->directory . '/upload.json';
        $this->lockPath = $this->directory . '/upload.lock';
    }

    public function statePath(): string {
        return $this->statePath;
    }

    /**
     * @template T
     * @param callable(OriginUploadJournalSession):T $callback
     * @return T
     */
    public function locked(callable $callback): mixed {
        self::assertControlPlane();
        self::assertRegularOrAbsent($this->lockPath, 'lock');
        $handle = @fopen($this->lockPath, 'c+b');
        if (!is_resource($handle)) {
            throw new \RuntimeException('duo: cloud origin upload journal lock could not be opened');
        }
        try {
            if (!@chmod($this->lockPath, 0600)) {
                throw new \RuntimeException('duo: cloud origin upload journal lock permissions could not be restricted');
            }
            $opened = fstat($handle);
            $named = self::freshLstat($this->lockPath);
            if (!is_array($opened) || !is_array($named)
                || !self::sameRegularPrivateFile($opened, $named)) {
                throw new \RuntimeException('duo: cloud origin upload journal lock path is unsafe');
            }
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('duo: cloud origin upload journal lock could not be acquired');
            }
            $named = self::freshLstat($this->lockPath);
            if (!is_array($named) || !self::sameRegularPrivateFile($opened, $named)) {
                throw new \RuntimeException('duo: cloud origin upload journal lock pathname changed while held');
            }
            $this->reconcileTemporaryState();
            $session = new OriginUploadJournalSession(
                $this->readState(),
                function (array $state): void {
                    $this->writeState($state);
                }
            );
            return $callback($session);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return ?array<string,mixed> */
    private function readState(): ?array {
        $stat = self::freshLstat($this->statePath);
        if ($stat === false) {
            return null;
        }
        if (!self::regularPrivateFile($stat) || is_link($this->statePath)
            || (int) ($stat['size'] ?? -1) < 2 || (int) $stat['size'] > self::MAX_BYTES) {
            throw new \RuntimeException('duo: cloud origin upload journal state path is unsafe');
        }
        $handle = @fopen($this->statePath, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('duo: cloud origin upload journal state could not be opened');
        }
        try {
            $opened = fstat($handle);
            if (!is_array($opened) || !self::sameFile($opened, $stat)) {
                throw new \RuntimeException('duo: cloud origin upload journal state changed while opening');
            }
            $bytes = stream_get_contents($handle, (int) $stat['size'] + 1);
            if (!is_string($bytes) || strlen($bytes) !== (int) $stat['size']) {
                throw new \RuntimeException('duo: cloud origin upload journal state could not be read exactly');
            }
        } finally {
            fclose($handle);
        }
        $after = self::freshLstat($this->statePath);
        if (!is_array($after) || !self::sameFile($after, $stat)
            || (int) ($after['size'] ?? -1) !== (int) $stat['size']) {
            throw new \RuntimeException('duo: cloud origin upload journal state changed while reading');
        }
        if (!str_ends_with($bytes, "\n")) {
            throw new \RuntimeException('duo: cloud origin upload journal state is not canonical JSON');
        }
        try {
            $decoded = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\Throwable $error) {
            throw new \RuntimeException('duo: cloud origin upload journal state JSON is malformed', 0, $error);
        }
        if (!is_array($decoded) || array_is_list($decoded) || Canon::encode($decoded) . "\n" !== $bytes) {
            throw new \RuntimeException('duo: cloud origin upload journal state JSON is not canonical');
        }
        return $decoded;
    }

    /** @param array<string,mixed> $state */
    private function writeState(array $state): void {
        $bytes = Canon::encode($state) . "\n";
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new \RuntimeException('duo: cloud origin upload journal state exceeds its byte limit');
        }
        self::assertRegularOrAbsent($this->statePath, 'state');
        $this->reconcileTemporaryState();
        $temporary = $this->statePath . '.tmp';
        $handle = @fopen($temporary, 'x+b');
        if (!is_resource($handle)) {
            throw new \RuntimeException('duo: cloud origin upload journal temporary state could not be created');
        }
        $published = false;
        try {
            if (!@chmod($temporary, 0600)) {
                throw new \RuntimeException('duo: cloud origin upload journal temporary permissions could not be restricted');
            }
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($handle, substr($bytes, $offset));
                if (!is_int($written) || $written < 1) {
                    throw new \RuntimeException('duo: cloud origin upload journal state could not be written completely');
                }
                $offset += $written;
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new \RuntimeException('duo: cloud origin upload journal state could not be synchronized');
            }
            $opened = fstat($handle);
            $named = self::freshLstat($temporary);
            if (!is_array($opened) || !is_array($named)
                || !self::sameRegularPrivateFile($opened, $named)
                || (int) ($opened['size'] ?? -1) !== strlen($bytes)
                || (int) ($named['size'] ?? -1) !== strlen($bytes)) {
                throw new \RuntimeException('duo: cloud origin upload journal temporary state is unsafe');
            }
            fclose($handle);
            $handle = null;
            if (!@rename($temporary, $this->statePath)) {
                throw new \RuntimeException('duo: cloud origin upload journal state could not be published atomically');
            }
            $published = true;
            self::syncDirectory($this->directory);
            $stateStat = self::freshLstat($this->statePath);
            if (!is_array($stateStat) || !self::regularPrivateFile($stateStat)
                || is_link($this->statePath)) {
                throw new \RuntimeException('duo: cloud origin upload journal published state is unsafe');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (!$published) {
                $this->reconcileTemporaryState();
            }
        }
    }

    /**
     * The exclusive upload lock makes this deterministic name single-writer.
     * Reopen plus pathname identity checks keep crash cleanup from following a
     * substituted node or silently freeing unbounded controller storage.
     */
    private function reconcileTemporaryState(): void {
        $temporary = $this->statePath . '.tmp';
        $before = self::freshLstat($temporary);
        if ($before === false) {
            return;
        }
        if (!self::regularPrivateFile($before) || is_link($temporary)
            || (int) ($before['size'] ?? -1) < 0
            || (int) ($before['size'] ?? -1) > self::MAX_BYTES) {
            throw new \RuntimeException('duo: cloud origin upload journal temporary state is unsafe');
        }
        $handle = @fopen($temporary, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('duo: cloud origin upload journal temporary state could not be opened');
        }
        try {
            $opened = fstat($handle);
            $after = self::freshLstat($temporary);
            if (!is_array($opened) || !is_array($after)
                || !self::sameRegularPrivateFile($before, $opened)
                || !self::sameRegularPrivateFile($opened, $after)
                || (int) ($before['size'] ?? -1) !== (int) ($opened['size'] ?? -2)
                || (int) ($after['size'] ?? -1) !== (int) ($opened['size'] ?? -2)) {
                throw new \RuntimeException('duo: cloud origin upload journal temporary state changed during cleanup');
            }
        } finally {
            fclose($handle);
        }
        if (!@unlink($temporary) || self::freshLstat($temporary) !== false) {
            throw new \RuntimeException('duo: cloud origin upload journal temporary state could not be removed');
        }
        self::syncDirectory($this->directory);
    }

    private static function ensurePrivateDirectory(string $directory): void {
        $parent = dirname($directory);
        $parentStat = self::freshLstat($parent);
        if (!is_array($parentStat) || self::kind($parentStat) !== 0040000 || is_link($parent)
            || !self::ownedByProcess($parentStat)
            || (DIRECTORY_SEPARATOR === '/' && (((int) ($parentStat['mode'] ?? 0)) & 0022) !== 0)) {
            throw new \RuntimeException('duo: cloud origin upload journal parent is not protected');
        }
        $stat = self::freshLstat($directory);
        if ($stat === false) {
            if (!@mkdir($directory, 0700)) {
                throw new \RuntimeException('duo: cloud origin upload journal directory could not be created');
            }
        } elseif (self::kind($stat) !== 0040000 || is_link($directory)) {
            throw new \RuntimeException('duo: cloud origin upload journal directory is unsafe');
        }
        $stat = self::freshLstat($directory);
        if (!is_array($stat) || !self::privateMode($stat, 0700) || !self::ownedByProcess($stat)) {
            throw new \RuntimeException('duo: cloud origin upload journal directory is not private');
        }
    }

    private static function assertRegularOrAbsent(string $path, string $label): void {
        $stat = self::freshLstat($path);
        if (is_array($stat) && (self::kind($stat) !== 0100000 || is_link($path)
            || !self::ownedByProcess($stat) || ((int) ($stat['nlink'] ?? 1)) !== 1)) {
            throw new \RuntimeException("duo: cloud origin upload journal $label path is unsafe");
        }
    }

    /** @param array<string|int,mixed> $opened @param array<string|int,mixed> $named */
    private static function sameRegularPrivateFile(array $opened, array $named): bool {
        return self::sameFile($opened, $named) && self::regularPrivateFile($opened)
            && self::regularPrivateFile($named);
    }

    /** @param array<string|int,mixed> $stat */
    private static function regularPrivateFile(array $stat): bool {
        return self::kind($stat) === 0100000 && self::privateMode($stat, 0600)
            && self::ownedByProcess($stat) && ((int) ($stat['nlink'] ?? 1)) === 1;
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private static function sameFile(array $left, array $right): bool {
        return (string) ($left['dev'] ?? '') === (string) ($right['dev'] ?? '')
            && (string) ($left['ino'] ?? '') === (string) ($right['ino'] ?? '');
    }

    /** @param array<string|int,mixed> $stat */
    private static function kind(array $stat): int {
        return ((int) ($stat['mode'] ?? 0)) & 0170000;
    }

    /** @param array<string|int,mixed> $stat */
    private static function privateMode(array $stat, int $expected): bool {
        return DIRECTORY_SEPARATOR !== '/' || (((int) ($stat['mode'] ?? 0)) & 0777) === $expected;
    }

    /** @param array<string|int,mixed> $stat */
    private static function ownedByProcess(array $stat): bool {
        return !function_exists('posix_geteuid') || (int) ($stat['uid'] ?? -1) === posix_geteuid();
    }

    /** @return array<string|int,mixed>|false */
    private static function freshLstat(string $path): array|false {
        clearstatcache(true, $path);
        return @lstat($path);
    }

    private static function syncDirectory(string $directory): void {
        if (!function_exists('fsync')) {
            return;
        }
        $handle = @fopen($directory, 'r');
        if (!is_resource($handle)) {
            throw new \RuntimeException('duo: cloud origin upload journal directory could not be opened for sync');
        }
        try {
            if (!@fsync($handle)) {
                throw new \RuntimeException('duo: cloud origin upload journal directory could not be synchronized');
            }
        } finally {
            fclose($handle);
        }
    }

    private static function assertControlPlane(): void {
        if (!defined('WP_CLI') || WP_CLI !== true
            || !defined('DUO_CONTROL_PLANE') || DUO_CONTROL_PLANE !== true) {
            throw new \RuntimeException('duo: cloud origin upload requires the isolated WP-CLI control plane');
        }
    }
}

<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once __DIR__ . '/ControlRefusal.php';
require_once __DIR__ . '/CanonicalJson.php';

/**
 * One crash-safe canonical authority document protected by an OS file lock.
 *
 * The lock is held across authorization, durable replay reservation, workload
 * execution, and receipt publication. Authority release therefore cannot race
 * a command that has already passed the exact held-fence comparison.
 */
final class FileAuthorityStore {
    private const STATE_LIMIT = 67108864;

    private string $path;
    private string $lockPath;
    private string $directory;

    public function __construct(string $path) {
        if ($path === '' || str_contains($path, "\0") || in_array(basename($path), ['', '.', '..'], true)) {
            throw new ControlRefusal('authority state path is invalid');
        }
        $directory = realpath(dirname($path));
        if (!is_string($directory) || $directory === '') {
            throw new ControlRefusal('authority state directory does not exist');
        }
        self::assertPrivateDirectory($directory);
        $this->directory = $directory;
        $this->path = $directory . '/' . basename($path);
        $this->lockPath = $this->path . '.lock';
        self::assertAbsentOrPrivateFile($this->path, 'authority state');
        self::assertAbsentOrPrivateFile($this->lockPath, 'authority lock');
    }

    /**
     * @template T
     * @param callable(AuthorityStateSession):T $callback
     * @return T
     */
    public function locked(callable $callback): mixed {
        $lock = $this->openLock();
        $wouldBlock = 0;
        if (!flock($lock, LOCK_EX | LOCK_NB, $wouldBlock)) {
            fclose($lock);
            if ($wouldBlock === 1) {
                throw new ControlRefusal('authority store lock is busy');
            }
            throw new ControlRefusal('authority store lock could not be acquired');
        }
        $session = null;
        try {
            $this->reconcileTemporaryState();
            $state = $this->readState();
            $session = new AuthorityStateSession(
                $state,
                function (array $next): void {
                    $this->writeState($next);
                }
            );
            return $callback($session);
        } finally {
            if ($session instanceof AuthorityStateSession) {
                $session->close();
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Read one whole atomically-published document without contending on the
     * writer lock. A concurrent rename can only expose the old or new inode;
     * the bounded retry closes the narrow lstat/open/readback swap window.
     *
     * @return array<string,mixed>
     */
    public function stableRead(): array {
        $last = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return $this->readState();
            } catch (ControlRefusal $error) {
                $last = $error;
            }
        }
        throw new ControlRefusal(
            'authority state could not be read as one stable publication',
            0,
            $last
        );
    }

    /** @return resource */
    private function openLock() {
        self::assertAbsentOrPrivateFile($this->lockPath, 'authority lock');
        $previousUmask = umask(0077);
        try {
            $handle = @fopen($this->lockPath, 'c+b');
        } finally {
            umask($previousUmask);
        }
        if (!is_resource($handle) || !@chmod($this->lockPath, 0600)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new ControlRefusal('authority lock could not be opened with mode 0600');
        }
        try {
            self::assertOpenedPrivateFile($handle, $this->lockPath, 'authority lock');
        } catch (\Throwable $error) {
            fclose($handle);
            throw $error;
        }
        return $handle;
    }

    /** @return array<string,mixed> */
    private function readState(): array {
        clearstatcache(true, $this->path);
        if (!file_exists($this->path) && !is_link($this->path)) {
            return [];
        }
        self::assertAbsentOrPrivateFile($this->path, 'authority state');
        $before = @lstat($this->path);
        $handle = @fopen($this->path, 'rb');
        if (!is_array($before) || !is_resource($handle)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new ControlRefusal('authority state could not be opened');
        }
        try {
            self::assertOpenedPrivateFile($handle, $this->path, 'authority state');
        } catch (\Throwable $error) {
            fclose($handle);
            throw $error;
        }
        $opened = fstat($handle);
        if (!is_array($opened) || !self::sameFile($before, $opened)
            || (int) $opened['size'] < 2 || (int) $opened['size'] > self::STATE_LIMIT) {
            fclose($handle);
            throw new ControlRefusal('authority state changed while opening or has an invalid size');
        }
        $bytes = stream_get_contents($handle, self::STATE_LIMIT + 1);
        $closed = fclose($handle);
        clearstatcache(true, $this->path);
        $after = @lstat($this->path);
        if (!is_string($bytes) || !$closed || !is_array($after) || !self::sameFile($before, $after)) {
            throw new ControlRefusal('authority state changed while reading');
        }
        return CanonicalJson::decodeObject($bytes, self::STATE_LIMIT);
    }

    /** @param array<string,mixed> $state */
    private function writeState(array $state): void {
        self::assertAbsentOrPrivateFile($this->path, 'authority state');
        $bytes = CanonicalJson::encode($state) . "\n";
        if (strlen($bytes) > self::STATE_LIMIT) {
            throw new ControlRefusal('authority state exceeds its byte limit');
        }
        $temporary = $this->path . '.tmp';
        $handle = null;
        $created = false;
        try {
            $previousUmask = umask(0077);
            try {
                $handle = @fopen($temporary, 'x+b');
            } finally {
                umask($previousUmask);
            }
            if (!is_resource($handle)) {
                throw new ControlRefusal('authority state temporary file could not be created');
            }
            $created = true;
            if (!@chmod($temporary, 0600)) {
                throw new ControlRefusal('authority state temporary file could not be protected');
            }
            self::assertOpenedPrivateFile($handle, $temporary, 'authority state temporary file');
            self::writeAll($handle, $bytes);
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new ControlRefusal('authority state temporary file could not be synchronized');
            }
            self::testCheckpoint('temporary-synchronized');
            if (!fclose($handle)) {
                $handle = null;
                throw new ControlRefusal('authority state temporary file could not be closed');
            }
            $handle = null;
            if (!@rename($temporary, $this->path)) {
                throw new ControlRefusal('authority state could not be atomically published');
            }
            $created = false;
            self::assertAbsentOrPrivateFile($this->path, 'authority state');
            $this->syncDirectory();
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if ($created) {
                @unlink($temporary);
            }
        }
    }

    /**
     * One lock-scoped destination owns one temporary name, bounding SIGKILL
     * residue while inode parity prevents cleanup of a substituted pathname.
     */
    private function reconcileTemporaryState(): void {
        $temporary = $this->path . '.tmp';
        clearstatcache(true, $temporary);
        $before = @lstat($temporary);
        if ($before === false) {
            return;
        }
        self::assertAbsentOrPrivateFile($temporary, 'authority state temporary file');
        if ((int) ($before['size'] ?? -1) < 0 || (int) $before['size'] > self::STATE_LIMIT) {
            throw new ControlRefusal('authority state temporary file has an invalid size');
        }
        $handle = @fopen($temporary, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal('authority state temporary file could not be opened for recovery');
        }
        try {
            self::assertOpenedPrivateFile($handle, $temporary, 'authority state temporary file');
            $opened = fstat($handle);
            clearstatcache(true, $temporary);
            $after = @lstat($temporary);
            if (!is_array($opened) || !is_array($after)
                || !self::sameFile($before, $opened) || !self::sameFile($before, $after)
                || !@unlink($temporary)) {
                throw new ControlRefusal('authority state temporary file changed during recovery');
            }
            $unlinked = fstat($handle);
            clearstatcache(true, $temporary);
            if (!is_array($unlinked) || @lstat($temporary) !== false
                || (int) $unlinked['dev'] !== (int) $opened['dev']
                || (int) $unlinked['ino'] !== (int) $opened['ino']
                || (int) ($unlinked['nlink'] ?? -1) !== 0) {
                throw new ControlRefusal('authority state temporary file changed while being removed');
            }
        } finally {
            fclose($handle);
        }
        $this->syncDirectory();
    }

    private static function testCheckpoint(string $phase): void {
        if (!function_exists('posix_kill')
            || getenv('DUO_TEST_FILE_AUTHORITY_KILL_PHASE') !== $phase) {
            return;
        }
        posix_kill(getmypid(), SIGKILL);
        usleep(1000000);
        exit(137);
    }

    /** @param resource $handle */
    private static function writeAll($handle, string $bytes): void {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($handle, substr($bytes, $offset));
            if (!is_int($written) || $written < 1) {
                throw new ControlRefusal('authority state temporary file could not be written completely');
            }
            $offset += $written;
        }
    }

    private function syncDirectory(): void {
        if (!function_exists('fsync')) {
            return;
        }
        $handle = @fopen($this->directory, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal('authority state directory could not be opened for synchronization');
        }
        $synced = @fsync($handle);
        $closed = fclose($handle);
        if (!$synced || !$closed) {
            throw new ControlRefusal('authority state directory could not be synchronized');
        }
    }

    private static function assertPrivateDirectory(string $path): void {
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000 || is_link($path)
            || (DIRECTORY_SEPARATOR === '/' && ($stat['mode'] & 0077) !== 0)
            || (function_exists('posix_geteuid') && (int) $stat['uid'] !== posix_geteuid())
            || !is_readable($path) || !is_writable($path)) {
            throw new ControlRefusal('authority state directory must be process-owned and mode 0700 or stricter');
        }
    }

    private static function assertAbsentOrPrivateFile(string $path, string $label): void {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            return;
        }
        if (($stat['mode'] & 0170000) !== 0100000 || is_link($path)
            || (int) ($stat['nlink'] ?? 0) !== 1
            || (DIRECTORY_SEPARATOR === '/' && ($stat['mode'] & 0777) !== 0600)
            || (function_exists('posix_geteuid') && (int) $stat['uid'] !== posix_geteuid())) {
            throw new ControlRefusal("$label must be a process-owned, single-link, mode-0600 regular file");
        }
    }

    /** @param resource $handle */
    private static function assertOpenedPrivateFile($handle, string $path, string $label): void {
        clearstatcache(true, $path);
        $pathStat = @lstat($path);
        $openedStat = fstat($handle);
        if (!is_array($pathStat) || !is_array($openedStat)
            || !self::sameFile($pathStat, $openedStat)
            || ($openedStat['mode'] & 0170000) !== 0100000
            || (int) ($openedStat['nlink'] ?? 0) !== 1
            || (DIRECTORY_SEPARATOR === '/' && ($openedStat['mode'] & 0777) !== 0600)
            || (function_exists('posix_geteuid') && (int) $openedStat['uid'] !== posix_geteuid())) {
            throw new ControlRefusal("$label changed while opening");
        }
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private static function sameFile(array $left, array $right): bool {
        return (int) $left['dev'] === (int) $right['dev']
            && (int) $left['ino'] === (int) $right['ino']
            && (int) $left['mode'] === (int) $right['mode']
            && (int) $left['uid'] === (int) $right['uid']
            && (int) ($left['nlink'] ?? 0) === (int) ($right['nlink'] ?? 0)
            && (int) $left['size'] === (int) $right['size'];
    }
}

/** A lock-scoped mutable view; it becomes unusable as soon as locked() exits. */
final class AuthorityStateSession {
    /** @var array<string,mixed> */
    private array $state;
    /** @var \Closure(array<string,mixed>):void */
    private \Closure $writer;
    private bool $open = true;

    /** @param array<string,mixed> $state @param callable(array<string,mixed>):void $writer */
    public function __construct(array $state, callable $writer) {
        $this->state = $state;
        $this->writer = \Closure::fromCallable($writer);
    }

    /** @return array<string,mixed> */
    public function state(): array {
        $this->assertOpen();
        return $this->state;
    }

    /** @param array<string,mixed> $state */
    public function save(array $state): void {
        $this->assertOpen();
        ($this->writer)($state);
        $this->state = $state;
    }

    public function close(): void {
        $this->open = false;
    }

    private function assertOpen(): void {
        if (!$this->open) {
            throw new ControlRefusal('authority state session escaped its file lock');
        }
    }
}

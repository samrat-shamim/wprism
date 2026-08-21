<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once __DIR__ . '/ControlRefusal.php';
require_once __DIR__ . '/OriginBlobStore.php';

/** Private immutable filesystem storage for origin chunks and assemblies. */
final class OriginFileBlobStore implements OriginBlobStore {
    private const MAX_BLOB_BYTES = 67108864;

    private string $root;
    private string $objects;

    public function __construct(string $root) {
        if ($root === '' || str_contains($root, "\0")) {
            throw new ControlRefusal('origin blob root is invalid');
        }
        $lexical = rtrim($root, '/');
        $lexicalStat = @lstat($lexical);
        if (!is_array($lexicalStat) || ($lexicalStat['mode'] & 0170000) !== 0040000 || is_link($lexical)) {
            throw new ControlRefusal('origin blob root must be a non-symlink directory');
        }
        $resolved = realpath($lexical);
        if (!is_string($resolved) || $resolved === '') {
            throw new ControlRefusal('origin blob root does not exist');
        }
        self::assertPrivateDirectory($resolved, 'origin blob root');
        $this->root = rtrim($resolved, '/');
        $this->objects = $this->root . '/objects';
        $this->ensureDirectory($this->objects, 'origin blob objects');
    }

    public function putChunk(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        string $exportId,
        string $sha256,
        string $bytes
    ): void {
        self::assertSha256($exportId, 'export id');
        self::assertSha256($sha256, 'chunk digest');
        if ($bytes === '' || strlen($bytes) > 1048576 || !hash_equals($sha256, hash('sha256', $bytes))) {
            throw new ControlRefusal('origin chunk bytes do not match their identity');
        }
        $scope = $this->scopeDirectory(
            $tenantId,
            $siteId,
            $originGeneration,
            $demandGeneration,
            $exportId
        );
        $chunks = $scope . '/chunks';
        $this->ensureDirectory($chunks, 'origin blob chunk scope');
        $this->writeImmutable($chunks . '/' . $sha256, $bytes, 'origin chunk');
    }

    public function getChunk(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        string $exportId,
        string $sha256
    ): ?string {
        self::assertSha256($exportId, 'export id');
        self::assertSha256($sha256, 'chunk digest');
        $scope = $this->scopeDirectoryPath(
            $tenantId,
            $siteId,
            $originGeneration,
            $demandGeneration,
            $exportId
        );
        if (!$this->optionalDirectory($scope, 'origin blob export scope')) {
            return null;
        }
        if (!$this->optionalDirectory($scope . '/chunks', 'origin blob chunk scope')) {
            return null;
        }
        return $this->readOptional($scope . '/chunks/' . $sha256, 1048576, 'origin chunk');
    }

    public function publishArtifact(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        string $exportId,
        string $bytes
    ): void {
        self::assertSha256($exportId, 'export id');
        if ($bytes === '' || strlen($bytes) > self::MAX_BLOB_BYTES) {
            throw new ControlRefusal('origin artifact bytes exceed their storage limit');
        }
        $scope = $this->scopeDirectory(
            $tenantId,
            $siteId,
            $originGeneration,
            $demandGeneration,
            $exportId
        );
        $this->writeImmutable($scope . '/artifact.bin', $bytes, 'origin artifact');
    }

    public function getArtifact(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        string $exportId
    ): ?string {
        self::assertSha256($exportId, 'export id');
        $scope = $this->scopeDirectoryPath(
            $tenantId,
            $siteId,
            $originGeneration,
            $demandGeneration,
            $exportId
        );
        if (!$this->optionalDirectory($scope, 'origin blob export scope')) {
            return null;
        }
        return $this->readOptional($scope . '/artifact.bin', self::MAX_BLOB_BYTES, 'origin artifact');
    }

    public function deleteExport(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        string $exportId
    ): void {
        self::assertSha256($exportId, 'export id');
        $scope = $this->scopeDirectoryPath(
            $tenantId,
            $siteId,
            $originGeneration,
            $demandGeneration,
            $exportId
        );
        $name = basename($scope);
        $deleting = $this->objects . '/.deleting-' . $name;
        self::assertPrivateDirectory($this->objects, 'origin blob objects');

        clearstatcache(true, $scope);
        clearstatcache(true, $deleting);
        $scopeExists = @lstat($scope) !== false;
        $deletingExists = @lstat($deleting) !== false;
        if ($scopeExists && $deletingExists) {
            throw new ControlRefusal('origin blob export deletion has conflicting source and tombstone scopes');
        }
        if ($scopeExists) {
            self::assertPrivateDirectory($scope, 'origin blob export scope');
            if (!@rename($scope, $deleting)) {
                throw new ControlRefusal('origin blob export scope could not enter deletion state');
            }
            $this->syncDirectory($this->objects);
            $deletingExists = true;
        }
        if (!$deletingExists) {
            return;
        }
        self::assertPrivateDirectory($deleting, 'origin blob deleting scope');
        $this->removePrivateTree($deleting);
        $this->syncDirectory($this->objects);
    }

    private function scopeDirectory(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        string $exportId
    ): string {
        $path = $this->scopeDirectoryPath(
            $tenantId,
            $siteId,
            $originGeneration,
            $demandGeneration,
            $exportId
        );
        $this->ensureDirectory($path, 'origin blob export scope');
        return $path;
    }

    private function scopeDirectoryPath(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        string $exportId
    ): string {
        if ($tenantId === '' || $siteId === '' || str_contains($tenantId . $siteId, "\0")
            || $originGeneration < 1 || $demandGeneration < 1) {
            throw new ControlRefusal('origin blob scope identity is invalid');
        }
        self::assertSha256($exportId, 'export id');
        $scope = hash(
            'sha256',
            "duo-cloud-origin-blob-scope/v1\0$tenantId\0$siteId\0"
                . "$originGeneration\0$demandGeneration\0$exportId"
        );
        return $this->objects . '/' . $scope;
    }

    private function ensureDirectory(string $path, string $label): void {
        if ($path !== $this->objects) {
            self::assertPrivateDirectory($this->objects, 'origin blob objects');
            self::assertPrivateDirectory(dirname($path), "$label parent");
        } else {
            self::assertPrivateDirectory($this->root, 'origin blob root');
        }
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            $previousUmask = umask(0077);
            try {
                $created = @mkdir($path, 0700);
            } finally {
                umask($previousUmask);
            }
            if (!$created || !@chmod($path, 0700)) {
                throw new ControlRefusal("$label could not be created with mode 0700");
            }
            $this->syncDirectory(dirname($path));
        }
        self::assertPrivateDirectory($path, $label);
    }

    private function optionalDirectory(string $path, string $label): bool {
        self::assertPrivateDirectory($this->objects, 'origin blob objects');
        if ($path !== $this->objects) {
            self::assertPrivateDirectory(dirname($path), "$label parent");
        }
        clearstatcache(true, $path);
        if (@lstat($path) === false) {
            return false;
        }
        self::assertPrivateDirectory($path, $label);
        return true;
    }

    private function writeImmutable(string $path, string $bytes, string $label): void {
        $existing = $this->readOptional($path, self::MAX_BLOB_BYTES, $label);
        if ($existing !== null) {
            if (!hash_equals(hash('sha256', $existing), hash('sha256', $bytes)) || $existing !== $bytes) {
                throw new ControlRefusal("$label immutable path already contains different bytes");
            }
            return;
        }

        // OriginAuthority serializes every write under its authority lock. A
        // destination-bound name therefore caps killed attempts at one entry
        // while still keeping the temporary inside the reaper-owned scope.
        $temporary = $path . '.write';
        $this->removeStaleTemporary($temporary, $label);
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
                throw new ControlRefusal("$label temporary file could not be created");
            }
            $created = true;
            if (!@chmod($temporary, 0600)) {
                throw new ControlRefusal("$label temporary file could not be protected");
            }
            self::assertOpenedPrivateFile($handle, $temporary, "$label temporary file");
            self::writeAll($handle, $bytes, $label);
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new ControlRefusal("$label temporary file could not be synchronized");
            }
            self::testCheckpoint('temporary-synchronized');
            if (!fclose($handle)) {
                $handle = null;
                throw new ControlRefusal("$label temporary file could not be closed");
            }
            $handle = null;
            clearstatcache(true, $path);
            if (file_exists($path) || is_link($path) || !@rename($temporary, $path)) {
                $winner = $this->readOptional($path, self::MAX_BLOB_BYTES, $label);
                if ($winner === null || $winner !== $bytes) {
                    throw new ControlRefusal("$label could not be atomically published");
                }
                return;
            }
            $created = false;
            self::assertPrivateFile($path, $label);
            $this->syncDirectory(dirname($path));
            $readback = $this->readOptional($path, self::MAX_BLOB_BYTES, $label);
            if ($readback === null || $readback !== $bytes) {
                throw new ControlRefusal("$label failed immutable readback");
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if ($created) {
                @unlink($temporary);
            }
        }
    }

    private function removeStaleTemporary(string $path, string $label): void {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if ($before === false) {
            return;
        }
        self::assertPrivateFile($path, "$label stale temporary file");
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal("$label stale temporary file could not be opened");
        }
        try {
            self::assertOpenedPrivateFile($handle, $path, "$label stale temporary file");
            if (!@unlink($path)) {
                throw new ControlRefusal("$label stale temporary file could not be removed");
            }
            $opened = fstat($handle);
            clearstatcache(true, $path);
            if (!is_array($opened) || @lstat($path) !== false
                || (int) $opened['dev'] !== (int) $before['dev']
                || (int) $opened['ino'] !== (int) $before['ino']
                || (int) ($opened['nlink'] ?? -1) !== 0) {
                throw new ControlRefusal("$label stale temporary file changed while removing");
            }
        } finally {
            fclose($handle);
        }
        $this->syncDirectory(dirname($path));
    }

    private function readOptional(string $path, int $limit, string $label): ?string {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if ($before === false) {
            return null;
        }
        self::assertPrivateFile($path, $label);
        if ((int) ($before['size'] ?? -1) < 1 || (int) $before['size'] > $limit) {
            throw new ControlRefusal("$label has an invalid size");
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal("$label could not be opened");
        }
        try {
            self::assertOpenedPrivateFile($handle, $path, $label);
            $bytes = stream_get_contents($handle, $limit + 1);
            $opened = fstat($handle);
        } finally {
            fclose($handle);
        }
        clearstatcache(true, $path);
        $after = @lstat($path);
        if (!is_string($bytes) || !is_array($opened) || !is_array($after)
            || strlen($bytes) !== (int) $before['size']
            || !self::sameFile($before, $opened) || !self::sameFile($before, $after)) {
            throw new ControlRefusal("$label changed while reading");
        }
        return $bytes;
    }

    private function removePrivateTree(string $root): void {
        $stack = [[$root, false]];
        $visited = 0;
        while ($stack !== []) {
            $entry = array_pop($stack);
            if (!is_array($entry)) {
                throw new ControlRefusal('origin blob deletion traversal is corrupt');
            }
            [$path, $expanded] = $entry;
            if (++$visited > 512) {
                throw new ControlRefusal('origin blob deletion traversal exceeds its bound');
            }
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if (!is_array($stat)) {
                continue;
            }
            $type = $stat['mode'] & 0170000;
            if ($type === 0100000) {
                self::assertPrivateFile($path, 'origin blob deletion file');
                if (!@unlink($path)) {
                    throw new ControlRefusal('origin blob deletion file could not be removed');
                }
                continue;
            }
            if ($type !== 0040000 || is_link($path)) {
                throw new ControlRefusal('origin blob deletion encountered an unsafe filesystem node');
            }
            self::assertPrivateDirectory($path, 'origin blob deletion directory');
            if (!$expanded) {
                $children = @scandir($path);
                if (!is_array($children)) {
                    throw new ControlRefusal('origin blob deletion directory could not be enumerated');
                }
                $stack[] = [$path, true];
                rsort($children, SORT_STRING);
                foreach ($children as $child) {
                    if ($child === '.' || $child === '..') {
                        continue;
                    }
                    if ($child === '' || str_contains($child, '/') || str_contains($child, "\0")) {
                        throw new ControlRefusal('origin blob deletion entry name is unsafe');
                    }
                    $stack[] = [$path . '/' . $child, false];
                }
                continue;
            }
            if (!@rmdir($path)) {
                throw new ControlRefusal('origin blob deletion directory could not be removed');
            }
        }
    }

    /** @param resource $handle */
    private static function writeAll($handle, string $bytes, string $label): void {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($handle, substr($bytes, $offset));
            if (!is_int($written) || $written < 1) {
                throw new ControlRefusal("$label could not be written completely");
            }
            $offset += $written;
        }
    }

    private function syncDirectory(string $directory): void {
        if (!function_exists('fsync')) {
            return;
        }
        $handle = @fopen($directory, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal('origin blob directory could not be opened for synchronization');
        }
        $synced = @fsync($handle);
        $closed = fclose($handle);
        if (!$synced || !$closed) {
            throw new ControlRefusal('origin blob directory could not be synchronized');
        }
    }

    private static function assertPrivateDirectory(string $path, string $label): void {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000 || is_link($path)
            || (DIRECTORY_SEPARATOR === '/' && ($stat['mode'] & 0077) !== 0)
            || (function_exists('posix_geteuid') && (int) $stat['uid'] !== posix_geteuid())) {
            throw new ControlRefusal("$label must be process-owned and mode 0700 or stricter");
        }
    }

    private static function assertPrivateFile(string $path, string $label): void {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || is_link($path)
            || (int) ($stat['nlink'] ?? 0) !== 1
            || (DIRECTORY_SEPARATOR === '/' && ($stat['mode'] & 0777) !== 0600)
            || (function_exists('posix_geteuid') && (int) $stat['uid'] !== posix_geteuid())) {
            throw new ControlRefusal("$label must be a process-owned, single-link, mode-0600 regular file");
        }
    }

    /** @param resource $handle */
    private static function assertOpenedPrivateFile($handle, string $path, string $label): void {
        clearstatcache(true, $path);
        $named = @lstat($path);
        $opened = fstat($handle);
        if (!is_array($named) || !is_array($opened) || !self::sameFile($named, $opened)
            || ($opened['mode'] & 0170000) !== 0100000
            || (int) ($opened['nlink'] ?? 0) !== 1
            || (DIRECTORY_SEPARATOR === '/' && ($opened['mode'] & 0777) !== 0600)
            || (function_exists('posix_geteuid') && (int) $opened['uid'] !== posix_geteuid())) {
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

    private static function assertSha256(string $value, string $label): void {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new ControlRefusal("$label must be lowercase SHA-256");
        }
    }

    /** Test-only crash seam; production writes never inspect the phase selector. */
    private static function testCheckpoint(string $phase): void {
        if (getenv('DUO_TEST_MODE') !== '1'
            || getenv('DUO_TEST_ORIGIN_BLOB_KILL_PHASE') !== $phase) {
            return;
        }
        $signal = defined('SIGKILL') ? (int) constant('SIGKILL') : 9;
        if (!function_exists('posix_kill') || !@posix_kill(getmypid(), $signal)) {
            throw new ControlRefusal('origin blob test interruption could not be delivered');
        }
    }
}

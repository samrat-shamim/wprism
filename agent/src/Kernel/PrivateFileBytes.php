<?php
declare(strict_types=1);

namespace WPrism;

/**
 * Create-only private bytes, not request grammar or transaction authority.
 *
 * The caller supplies an existing canonical POSIX parent. Standard UID/mode
 * and sticky-directory DAC is a premise: PHP cannot attest ACL/LSM grants or
 * exclude malicious same-euid writers. Binding every ancestor prevents a
 * writable non-sticky grandparent from defeating an immediate 0700 parent.
 * Failed creation preserves its private partial file; it never repairs,
 * replaces or deletes operator evidence. Consumers hash/parse read()'s bytes,
 * never a second pathname read.
 */
final class PrivateFileBytes {
    public const MAX_BYTES = 1048576;

    /** Returns SHA-256 only after same-handle readback and file/parent fsync. */
    public static function create(string $parent, string $name, string $bytes): string {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new \RuntimeException('wprism: private file exceeds its byte bound');
        }
        if (!function_exists('fsync')) {
            throw new \RuntimeException('wprism: private file creation requires fsync');
        }
        [$path, $uid, $chain] = self::prepare($parent, $name);
        $directory = @fopen($parent, 'rb');
        if (!is_resource($directory)) {
            throw new \RuntimeException('wprism: private file parent cannot be opened');
        }
        $handle = null;
        try {
            self::assert_directory_handle($directory, $parent, $uid, $chain);
            $mask = umask(0077);
            try {
                $handle = @fopen($path, 'x+b');
            } finally {
                umask($mask);
            }
            if (!is_resource($handle)) {
                throw new \RuntimeException('wprism: private file exclusive creation failed');
            }
            $created = self::file_stat(@fstat($handle), $uid, self::MAX_BYTES);
            if ($created['size'] !== 0) {
                throw new \RuntimeException('wprism: private file creation did not produce an empty file');
            }
            self::assert_file($handle, $path, $uid, $created, $chain);
            self::assert_directory_handle($directory, $parent, $uid, $chain);

            $offset = 0;
            $length = strlen($bytes);
            while ($offset < $length) {
                $written = @fwrite($handle, substr($bytes, $offset, 65536));
                if (!is_int($written) || $written < 1 || $written > min(65536, $length - $offset)) {
                    throw new \RuntimeException('wprism: private file write failed');
                }
                $offset += $written;
            }
            if (!@fflush($handle) || !@fsync($handle)) {
                throw new \RuntimeException('wprism: private file durable write failed');
            }
            $written = self::file_stat(@fstat($handle), $uid, self::MAX_BYTES);
            if (!self::same($created, $written, ['dev', 'ino', 'uid', 'mode', 'nlink'])
                || $written['size'] !== $length || !@rewind($handle)) {
                throw new \RuntimeException('wprism: private file changed during creation');
            }
            if (self::read_handle($handle, $length) !== $bytes) {
                throw new \RuntimeException('wprism: private file exact readback failed');
            }
            self::assert_file($handle, $path, $uid, $written, $chain);
            self::assert_directory_handle($directory, $parent, $uid, $chain);
            if (!@fsync($directory)) {
                throw new \RuntimeException('wprism: private file parent durable sync failed');
            }
            self::assert_directory_handle($directory, $parent, $uid, $chain);
            self::assert_file($handle, $path, $uid, $written, $chain);
            self::close($handle);
            self::close($directory);
            return hash('sha256', $bytes);
        } finally {
            if (is_resource($handle)) @fclose($handle);
            if (is_resource($directory)) @fclose($directory);
        }
    }

    /** Read exactly one admitted file version, bounded before allocation. */
    public static function read(string $parent, string $name, int $maxBytes): string {
        if ($maxBytes < 0 || $maxBytes > self::MAX_BYTES) {
            throw new \RuntimeException('wprism: private file read bound is invalid');
        }
        [$path, $uid, $chain] = self::prepare($parent, $name);
        clearstatcache(true, $path);
        $before = self::file_stat(@lstat($path), $uid, $maxBytes);
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('wprism: private file cannot be opened');
        }
        try {
            // No bytes are consumed before opened/named identity agreement.
            self::assert_file($handle, $path, $uid, $before, $chain);
            $bytes = self::read_handle($handle, $before['size']);
            self::assert_file($handle, $path, $uid, $before, $chain);
            self::close($handle);
            return $bytes;
        } finally {
            if (is_resource($handle)) @fclose($handle);
        }
    }

    /** @return array{string,int,array<string,array<string,int>>} */
    private static function prepare(string $parent, string $name): array {
        if (!function_exists('posix_geteuid')) {
            throw new \RuntimeException('wprism: private file access requires posix_geteuid');
        }
        $uid = posix_geteuid();
        if (!is_int($uid) || $uid < 0) {
            throw new \RuntimeException('wprism: private file effective UID is unavailable');
        }
        if (strlen($parent) > 4096 || !str_starts_with($parent, '/') || $parent === '/'
            || preg_match('/[\x00-\x1F\x7F\\\\]/', $parent) === 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $name) !== 1) {
            throw new \RuntimeException('wprism: private file path is not canonical');
        }
        $components = explode('/', substr($parent, 1));
        if (count($components) > 128 || array_intersect($components, ['', '.', '..']) !== []) {
            throw new \RuntimeException('wprism: private file path is not canonical');
        }
        $chain = ['/' => self::directory_stat('/', $uid, false)];
        $path = '';
        foreach ($components as $component) {
            $path .= '/' . $component;
            $chain[$path] = self::directory_stat($path, $uid, $path === $parent);
        }
        self::assert_chain($chain, $uid);
        return [$parent . '/' . $name, $uid, $chain];
    }

    /** @return array<string,int> */
    private static function directory_stat(string $path, int $uid, bool $immediate): array {
        clearstatcache(true, $path);
        $stat = self::stat_fields(@lstat($path), ['dev', 'ino', 'uid', 'mode']);
        if (($stat['mode'] & 0170000) !== 0040000 || !in_array($stat['uid'], [0, $uid], true)
            || ($immediate && ($stat['uid'] !== $uid || ($stat['mode'] & 07777) !== 0700))
            || (!$immediate && ($stat['mode'] & 0022) !== 0 && ($stat['mode'] & 01000) === 0)) {
            throw new \RuntimeException('wprism: private file parent chain is not owned and private');
        }
        return $stat;
    }

    /** @param array<string,array<string,int>> $chain */
    private static function assert_chain(array $chain, int $uid): void {
        if (posix_geteuid() !== $uid) {
            throw new \RuntimeException('wprism: private file effective UID changed');
        }
        $parent = array_key_last($chain);
        foreach ($chain as $path => $before) {
            // Every child is root/euid-owned, including the child protected by
            // a sticky writable ancestor. mtime/nlink are not directory identity.
            if (self::directory_stat($path, $uid, $path === $parent) !== $before) {
                throw new \RuntimeException('wprism: private file parent chain changed');
            }
        }
    }

    /** @param resource $handle @param array<string,array<string,int>> $chain */
    private static function assert_directory_handle($handle, string $parent, int $uid, array $chain): void {
        if (self::stat_fields(@fstat($handle), ['dev', 'ino', 'uid', 'mode']) !== $chain[$parent]) {
            throw new \RuntimeException('wprism: private file opened parent changed');
        }
        self::assert_chain($chain, $uid);
    }

    /** @return array<string,int> */
    private static function file_stat(mixed $stat, int $uid, int $maxBytes): array {
        $stat = self::stat_fields($stat, ['dev', 'ino', 'uid', 'mode', 'nlink', 'size', 'mtime', 'ctime']);
        if (($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 07777) !== 0600
            || $stat['uid'] !== $uid || $stat['nlink'] !== 1) {
            throw new \RuntimeException('wprism: private file is not an owned single-link 0600 regular file');
        }
        if ($stat['size'] < 0 || $stat['size'] > $maxBytes) {
            throw new \RuntimeException('wprism: private file exceeds its byte bound');
        }
        return $stat;
    }

    /**
     * @param resource $handle
     * @param array<string,int> $before
     * @param array<string,array<string,int>> $chain
     */
    private static function assert_file($handle, string $path, int $uid, array $before, array $chain): void {
        $opened = self::file_stat(@fstat($handle), $uid, self::MAX_BYTES);
        clearstatcache(true, $path);
        $named = self::file_stat(@lstat($path), $uid, self::MAX_BYTES);
        if ($opened !== $before || $named !== $before) {
            throw new \RuntimeException('wprism: private file version changed');
        }
        self::assert_chain($chain, $uid);
    }

    /** @param resource $handle */
    private static function read_handle($handle, int $size): string {
        $bytes = '';
        while (strlen($bytes) < $size) {
            $chunk = @fread($handle, min(65536, $size - strlen($bytes)));
            if (!is_string($chunk) || $chunk === '' || strlen($chunk) > $size - strlen($bytes)) {
                throw new \RuntimeException('wprism: private file bounded read failed');
            }
            $bytes .= $chunk;
        }
        if (@fread($handle, 1) !== '' || !feof($handle)) {
            throw new \RuntimeException('wprism: private file has unobserved bytes');
        }
        return $bytes;
    }

    /** @param list<string> $fields @return array<string,int> */
    private static function stat_fields(mixed $stat, array $fields): array {
        if (!is_array($stat)) {
            throw new \RuntimeException('wprism: private file metadata is unavailable');
        }
        $selected = [];
        foreach ($fields as $field) {
            if (!isset($stat[$field]) || !is_int($stat[$field])) {
                throw new \RuntimeException('wprism: private file metadata is malformed');
            }
            $selected[$field] = $stat[$field];
        }
        return $selected;
    }

    /** @param array<string,int> $left @param array<string,int> $right @param list<string> $fields */
    private static function same(array $left, array $right, array $fields): bool {
        foreach ($fields as $field) {
            if ($left[$field] !== $right[$field]) return false;
        }
        return true;
    }

    /** @param resource $handle */
    private static function close($handle): void {
        if (!@fclose($handle)) {
            throw new \RuntimeException('wprism: private file handle close failed');
        }
    }
}

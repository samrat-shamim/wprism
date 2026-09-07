<?php
declare(strict_types=1);

namespace WPrismTest;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/FilesystemTreeSnapshot.php';
require_once __DIR__ . '/EvidenceSizeProfile.php';

/** Private diagnostic bytes; neither canonical publication nor a capability certificate. */
final class FilesystemTreeEvidence {
    public const MAX_BYTES = 262144;
    public const MAX_ENTRIES = 4096;
    // Two trees plus the caller's small envelope fit the shared 1 MiB
    // command reader, even when many empty files dominate the metadata.
    public const MAX_RECORD_BYTES = 491520;

    /** @return array{root:string,directories:list<string>,files:list<array<string,mixed>>} */
    public static function capture(string $contentRoot, string $relativeRoot, string $profile = EvidenceSizeProfile::COMPACT): array {
        $limits = EvidenceSizeProfile::limits($profile);
        $absolute = $contentRoot . '/' . $relativeRoot;
        $observe = static fn(): array => \WPrism\FilesystemTreeSnapshot::observe(
            $contentRoot, $absolute, $relativeRoot, 'private tree evidence', 'evidence boundary'
        );
        $tree = $observe();
        if (count($tree['directories']) + count($tree['files']) > self::MAX_ENTRIES
            || strlen(json_encode($tree, JSON_THROW_ON_ERROR)) > self::MAX_BYTES) {
            throw new \RuntimeException('private tree evidence exceeds its metadata bound');
        }
        $result = $tree;
        $remaining = $limits['tree_bytes'];
        foreach ($tree['files'] as $index => $file) {
            if ($file['bytes'] > $remaining) {
                throw new \RuntimeException('private tree evidence exceeds its content bound');
            }
            $path = $tree['directories'] === [] ? $absolute : $absolute . '/' . $file['path'];
            $bytes = self::readFile($path, $file['bytes'], $file['sha256']);
            $result['files'][$index]['contents_base64'] = base64_encode($bytes);
            $remaining -= strlen($bytes);
        }
        // The core snapshot supplies the roster/confinement/race checks. Its
        // second complete observation also detects same-size rewrites whose
        // whole-second mtime/ctime cannot distinguish them from the preimage.
        if ($observe() !== $tree) {
            throw new \RuntimeException('private tree evidence changed during content retention');
        }
        if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > $limits['tree_record_bytes']) {
            throw new \RuntimeException('private tree evidence exceeds its encoded record bound');
        }
        return $result;
    }

    /** Validate retained bytes after the source tree has been destroyed. */
    public static function assertRecord(mixed $tree, string $relativeRoot, string $profile = EvidenceSizeProfile::COMPACT): void {
        $limits = EvidenceSizeProfile::limits($profile);
        if (!is_array($tree) || self::keys($tree) !== ['directories', 'files', 'root']
            || $tree['root'] !== $relativeRoot || !is_array($tree['directories']) || !array_is_list($tree['directories'])
            || !is_array($tree['files']) || !array_is_list($tree['files'])
            || count($tree['directories']) + count($tree['files']) > self::MAX_ENTRIES) {
            throw new \RuntimeException('private tree evidence record is malformed');
        }
        if (strlen(json_encode($tree, JSON_THROW_ON_ERROR)) > $limits['tree_record_bytes']) {
            throw new \RuntimeException('private tree evidence exceeds its encoded record bound');
        }
        $directories = [];
        foreach ($tree['directories'] as $path) {
            if (!is_string($path) || ($path !== '' && !self::relativePath($path)) || isset($directories['/' . $path])) {
                throw new \RuntimeException('private tree evidence directory roster is malformed');
            }
            $directories['/' . $path] = true;
        }
        $paths = [];
        $bytes = 0;
        foreach ($tree['files'] as $file) {
            if (!is_array($file) || self::keys($file) !== ['bytes', 'contents_base64', 'mtime', 'path', 'sha256']
                || !is_string($file['path']) || !self::relativePath($file['path'])
                || isset($paths['/' . $file['path']]) || isset($directories['/' . $file['path']])
                || !is_int($file['bytes']) || $file['bytes'] < 0 || !is_int($file['mtime'])
                || !is_string($file['sha256']) || preg_match('/^[a-f0-9]{64}$/D', $file['sha256']) !== 1
                || !is_string($file['contents_base64']) || $bytes > $limits['tree_bytes'] - $file['bytes']) {
                throw new \RuntimeException('private tree evidence file roster is malformed');
            }
            $content = base64_decode($file['contents_base64'], true);
            if (!is_string($content) || base64_encode($content) !== $file['contents_base64']
                || strlen($content) !== $file['bytes'] || hash('sha256', $content) !== $file['sha256']) {
                throw new \RuntimeException('private tree evidence bytes do not match their identity');
            }
            $paths['/' . $file['path']] = true;
            $bytes += strlen($content);
        }
        $fileNames = array_column($tree['files'], 'path');
        $directoryNames = $tree['directories'];
        sort($fileNames, SORT_STRING);
        sort($directoryNames, SORT_STRING);
        if ($fileNames !== array_column($tree['files'], 'path') || $directoryNames !== $tree['directories']) {
            throw new \RuntimeException('private tree evidence rosters are not ordered');
        }
        if ($directories === []) {
            if ($fileNames !== [basename($relativeRoot)]) {
                throw new \RuntimeException('private file-root evidence has an invalid roster');
            }
        } else {
            if (!isset($directories['/'])) throw new \RuntimeException('private directory-root evidence lacks its root');
            foreach ([...$directoryNames, ...$fileNames] as $path) {
                if ($path === '') continue;
                $parent = dirname($path);
                if (!isset($directories['/' . ($parent === '.' ? '' : $parent)])) {
                    throw new \RuntimeException('private tree evidence path lacks its parent directory');
                }
            }
        }
        $metadata = $tree;
        foreach ($metadata['files'] as &$file) unset($file['contents_base64']);
        unset($file);
        if (strlen(json_encode($metadata, JSON_THROW_ON_ERROR)) > self::MAX_BYTES) {
            throw new \RuntimeException('private tree evidence exceeds its metadata bound');
        }
    }

    /** @param array<array-key,mixed> $value @return list<array-key> */
    private static function keys(array $value): array {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        return $keys;
    }

    private static function relativePath(string $path): bool {
        if ($path === '' || str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path) === 1) return false;
        foreach (explode('/', $path) as $component) {
            if ($component === '' || $component === '.' || $component === '..') return false;
        }
        return true;
    }

    private static function readFile(string $path, int $length, string $hash): string {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!is_array($before) || ($before['mode'] & 0170000) !== 0100000) {
            throw new \RuntimeException('private tree evidence file is nonregular');
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('private tree evidence file is unreadable');
        }
        try {
            $opened = @fstat($handle);
            if (!is_array($opened) || !self::sameVersion($before, $opened) || $opened['size'] !== $length) {
                throw new \RuntimeException('private tree evidence file changed before retention');
            }
            $bytes = stream_get_contents($handle, $length + 1);
            $after = @fstat($handle);
            clearstatcache(true, $path);
            $current = @lstat($path);
            if (!is_string($bytes) || strlen($bytes) !== $length || hash('sha256', $bytes) !== $hash
                || !is_array($after) || !is_array($current)
                || !self::sameVersion($opened, $after) || !self::sameVersion($opened, $current)) {
                throw new \RuntimeException('private tree evidence bytes changed during retention');
            }
            return $bytes;
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private static function sameVersion(array $left, array $right): bool {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            if (!isset($left[$field], $right[$field]) || $left[$field] !== $right[$field]) return false;
        }
        return true;
    }
}

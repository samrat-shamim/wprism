<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once dirname(__DIR__) . '/src/CanonicalJson.php';
require_once dirname(__DIR__) . '/src/ControlRefusal.php';

/** Closed root-owned configuration for the dm-crypt/XFS quota authority. */
final class HostStorageConfig {
    public const FORMAT = 'duo-cloud-host-storage-config/v1';
    private const LIMIT = 1048576;
    private const EXECUTABLE_LIMIT = 67108864;

    /** @param array<string,mixed> $document */
    private function __construct(private array $document) {}

    public static function loadInstalled(string $path, string $pinPath): self {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new ControlRefusal('storage installed configuration requires root authority');
        }
        return self::load($path, self::pin($pinPath, true));
    }

    public static function load(string $path, string $sha256): self {
        self::sha256($sha256, 'storage configuration pin');
        $bytes = self::pinnedFile($path, $sha256, 'configuration', self::LIMIT);
        $document = CanonicalJson::decodeObject($bytes, self::LIMIT);
        if ($bytes !== CanonicalJson::encode($document) . "\n") {
            throw new ControlRefusal('storage configuration is not canonical JSON with one trailing LF');
        }
        self::exactKeys($document, [
            'backing_device', 'cipher', 'configuration_sha256s', 'container_engine',
            'cryptsetup', 'dmsetup', 'docker_root', 'durable_paths', 'filesystem_uuid',
            'findmnt', 'format', 'key_location', 'key_size_bits', 'limits', 'luks_uuid',
            'mapper_name', 'mapper_path', 'mapper_size_sectors', 'mapper_uuid', 'mountpoint',
            'payload_offset_sectors', 'sector_size_bytes',
            'process_timeout_seconds', 'service_uids', 'state_root', 'worker_roots',
            'xfs_io', 'xfs_quota',
        ]);
        if (($document['format'] ?? null) !== self::FORMAT) {
            throw new ControlRefusal('storage configuration format is unsupported');
        }
        foreach (['container_engine', 'cryptsetup', 'dmsetup', 'findmnt', 'xfs_io', 'xfs_quota'] as $field) {
            $descriptor = $document[$field] ?? null;
            if (!is_array($descriptor) || array_is_list($descriptor)) {
                throw new ControlRefusal("storage $field executable descriptor is invalid");
            }
            self::exactKeys($descriptor, ['path', 'sha256']);
            self::pinnedFile(
                $descriptor['path'] ?? null,
                $descriptor['sha256'] ?? null,
                $field,
                self::EXECUTABLE_LIMIT
            );
            if (!is_executable($descriptor['path'])) {
                throw new ControlRefusal("storage $field executable is not executable");
            }
        }
        if (!is_int($document['process_timeout_seconds'] ?? null)
            || $document['process_timeout_seconds'] < 1
            || $document['process_timeout_seconds'] > 10) {
            throw new ControlRefusal('storage process timeout is outside the closed range');
        }
        foreach (['mountpoint', 'docker_root', 'state_root'] as $field) {
            $pathValue = $document[$field] ?? null;
            if (!is_string($pathValue) || self::safePath($pathValue) === false
                || !is_dir($pathValue) || is_link($pathValue)) {
                throw new ControlRefusal("storage $field is not an approved directory");
            }
        }
        $state = @lstat($document['state_root']);
        if (!is_array($state) || ($state['mode'] & 0170000) !== 0040000
            || ($state['mode'] & 0077) !== 0
            || (function_exists('posix_geteuid') && (int) $state['uid'] !== posix_geteuid())) {
            throw new ControlRefusal('storage state root is not private and process-owned');
        }
        $luksUuid = $document['luks_uuid'] ?? null;
        if (($document['mapper_path'] ?? null) !== '/dev/mapper/' . ($document['mapper_name'] ?? '')
            || !is_string($document['mapper_name'] ?? null)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,127}\z/D', $document['mapper_name']) !== 1
            || !is_string($document['mapper_uuid'] ?? null)
            || !is_string($luksUuid)
            || preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/D', $luksUuid) !== 1
            || ($document['mapper_uuid'] ?? null) !== 'CRYPT-LUKS2-'
                . str_replace('-', '', $luksUuid) . '-' . $document['mapper_name']
            || !is_string($document['backing_device'] ?? null)
            || preg_match('#\A/dev/[A-Za-z0-9._/+:-]+\z#D', $document['backing_device']) !== 1
            || !is_string($document['cipher'] ?? null)
            || preg_match('/\A[a-z0-9][a-z0-9._+-]{1,63}\z/D', $document['cipher']) !== 1
            || ($document['key_location'] ?? null) !== 'keyring'
            || !is_int($document['key_size_bits'] ?? null)
            || !in_array($document['key_size_bits'], [256, 512], true)
            || !is_int($document['sector_size_bytes'] ?? null)
            || !in_array($document['sector_size_bytes'], [512, 4096], true)
            || !is_int($document['payload_offset_sectors'] ?? null)
            || $document['payload_offset_sectors'] < 8
            || !is_int($document['mapper_size_sectors'] ?? null)
            || $document['mapper_size_sectors'] < 131072
            || !is_string($document['filesystem_uuid'] ?? null)
            || preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/D', $document['filesystem_uuid']) !== 1) {
            throw new ControlRefusal('storage encrypted device identity is invalid');
        }
        self::sortedStrings($document['configuration_sha256s'] ?? null, 64, true, 'configuration digest');
        self::sortedStrings($document['durable_paths'] ?? null, 256, false, 'durable path');
        $workerRoots = $document['worker_roots'] ?? null;
        if (!is_array($workerRoots) || !array_is_list($workerRoots)
            || $workerRoots === [] || count($workerRoots) > 64) {
            throw new ControlRefusal('storage worker root registry is invalid');
        }
        $workerConfigurations = [];
        $registeredRoots = [];
        $previous = null;
        $dockerRoot = $document['docker_root'];
        $mountpoint = $document['mountpoint'];
        foreach ($workerRoots as $workerRoot) {
            if (!is_array($workerRoot) || array_is_list($workerRoot)) {
                throw new ControlRefusal('storage worker root descriptor is invalid');
            }
            self::exactKeys($workerRoot, ['configuration_sha256', 'path']);
            $configuration = $workerRoot['configuration_sha256'] ?? null;
            $path = $workerRoot['path'] ?? null;
            self::sha256($configuration, 'storage worker root configuration digest');
            if (!in_array($configuration, $document['configuration_sha256s'], true)
                || ($previous !== null && strcmp($previous, $configuration) >= 0)
                || !self::safePath($path)) {
                throw new ControlRefusal('storage worker root registry is not canonical and registered');
            }
            if (!self::within($path, $mountpoint)
                || $path === $mountpoint || self::within($path, $dockerRoot)
                || self::within($dockerRoot, $path)) {
                throw new ControlRefusal('storage worker root and Docker root overlap');
            }
            foreach ($registeredRoots as $registered) {
                if ($path !== $registered
                    && (self::within($path, $registered) || self::within($registered, $path))) {
                    throw new ControlRefusal('storage worker roots overlap');
                }
            }
            $workerConfigurations[] = $configuration;
            $registeredRoots[] = $path;
            $previous = $configuration;
        }
        if ($workerConfigurations !== $document['configuration_sha256s']) {
            throw new ControlRefusal('storage worker roots do not cover every configuration');
        }
        $uids = $document['service_uids'] ?? null;
        if (!is_array($uids) || !array_is_list($uids) || $uids === [] || count($uids) > 32) {
            throw new ControlRefusal('storage service UID registry is invalid');
        }
        $previous = 0;
        foreach ($uids as $uid) {
            if (!is_int($uid) || $uid < 1 || $uid <= $previous) {
                throw new ControlRefusal('storage service UID registry is not canonical and unique');
            }
            $previous = $uid;
        }
        $limits = $document['limits'] ?? null;
        if (!is_array($limits) || array_is_list($limits)) {
            throw new ControlRefusal('storage limits are invalid');
        }
        self::exactKeys($limits, [
            'database_bytes', 'database_inodes', 'filesystem_bytes', 'filesystem_inodes',
            'worker_bytes', 'worker_inodes',
        ]);
        foreach (['database_bytes', 'filesystem_bytes', 'worker_bytes'] as $field) {
            if (!is_int($limits[$field] ?? null) || $limits[$field] < 67108864
                || $limits[$field] > 1099511627776 || $limits[$field] % 1024 !== 0) {
                throw new ControlRefusal('storage byte limit is invalid');
            }
        }
        foreach (['database_inodes', 'filesystem_inodes', 'worker_inodes'] as $field) {
            if (!is_int($limits[$field] ?? null) || $limits[$field] < 1024
                || $limits[$field] > 10000000) {
                throw new ControlRefusal('storage inode limit is invalid');
            }
        }
        return new self($document);
    }

    public function binary(string $field): string {
        if (!in_array($field, ['container_engine', 'cryptsetup', 'dmsetup', 'findmnt', 'xfs_io', 'xfs_quota'], true)) {
            throw new ControlRefusal('storage binary name is outside the closed registry');
        }
        return $this->document[$field]['path'];
    }

    public function string(string $field): string {
        if (!in_array($field, [
            'backing_device', 'cipher', 'docker_root', 'filesystem_uuid', 'key_location', 'luks_uuid',
            'mapper_name', 'mapper_path', 'mapper_uuid', 'mountpoint', 'state_root',
        ], true)) {
            throw new ControlRefusal('storage string name is outside the closed registry');
        }
        return $field === 'state_root' ? (string) realpath($this->document[$field]) : $this->document[$field];
    }

    /** @return list<string> */
    public function strings(string $field): array {
        if (!in_array($field, ['configuration_sha256s', 'durable_paths'], true)) {
            throw new ControlRefusal('storage list name is outside the closed registry');
        }
        return $this->document[$field];
    }

    /** @return array{database_bytes:int,database_inodes:int,filesystem_bytes:int,filesystem_inodes:int,worker_bytes:int,worker_inodes:int} */
    public function limits(): array {
        return $this->document['limits'];
    }

    /** @return list<int> */
    public function serviceUids(): array {
        return $this->document['service_uids'];
    }

    /** @return list<array{configuration_sha256:string,path:string}> */
    public function workerRoots(): array {
        return $this->document['worker_roots'];
    }

    public function timeout(): int {
        return $this->document['process_timeout_seconds'];
    }

    public function integer(string $field): int {
        if (!in_array($field, [
            'key_size_bits', 'mapper_size_sectors', 'payload_offset_sectors', 'sector_size_bytes',
        ], true)) {
            throw new ControlRefusal('storage integer name is outside the closed registry');
        }
        return $this->document[$field];
    }

    private static function safePath(mixed $path): bool {
        return is_string($path) && preg_match('#\A/[A-Za-z0-9._/-]+\z#D', $path) === 1
            && !str_contains($path, '//') && !str_ends_with($path, '/')
            && preg_match('#(?:\A|/)\.\.?(/|\z)#D', $path) !== 1;
    }

    private static function within(string $path, string $root): bool {
        return $path === $root || str_starts_with($path, $root . '/');
    }

    private static function pinnedFile(
        mixed $path,
        mixed $sha256,
        string $label,
        int $limit
    ): string {
        if (!self::safePath($path)) {
            throw new ControlRefusal("storage $label path is invalid");
        }
        self::sha256($sha256, "storage $label digest");
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!is_array($before) || is_link($path) || ($before['mode'] & 0170000) !== 0100000
            || ($before['mode'] & 0022) !== 0 || (int) $before['size'] < 1
            || (int) $before['size'] > $limit
            || (function_exists('posix_geteuid') && (int) $before['uid'] !== posix_geteuid())) {
            throw new ControlRefusal("storage $label is not an approved pinned regular file");
        }
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::protectedAncestors(dirname($path), $label);
        }
        $handle = @fopen($path, 'rb');
        $opened = is_resource($handle) ? fstat($handle) : false;
        $bytes = is_resource($handle) ? stream_get_contents($handle, $limit + 1) : false;
        $closed = is_resource($handle) ? fclose($handle) : false;
        clearstatcache(true, $path);
        $after = @lstat($path);
        if (!is_array($opened) || !is_string($bytes) || !$closed || !is_array($after)
            || !self::same($before, $opened) || !self::same($before, $after)
            || strlen($bytes) > $limit || !hash_equals($sha256, hash('sha256', $bytes))) {
            throw new ControlRefusal("storage $label changed or differs from its digest");
        }
        return $bytes;
    }

    private static function pin(string $path, bool $root): string {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!self::safePath($path) || !is_array($before) || is_link($path)
            || ($before['mode'] & 0170000) !== 0100000 || ($before['mode'] & 0022) !== 0
            || ($root && (int) $before['uid'] !== 0) || (int) $before['size'] !== 65) {
            throw new ControlRefusal('storage configuration pin is not protected');
        }
        if ($root) {
            self::protectedAncestors(dirname($path), 'configuration pin');
        }
        $handle = @fopen($path, 'rb');
        $opened = is_resource($handle) ? fstat($handle) : false;
        $bytes = is_resource($handle) ? stream_get_contents($handle, 66) : false;
        $closed = is_resource($handle) ? fclose($handle) : false;
        clearstatcache(true, $path);
        $after = @lstat($path);
        if (!is_array($opened) || !is_string($bytes) || !$closed || !is_array($after)
            || !self::same($before, $opened) || !self::same($before, $after)
            || preg_match('/\A[a-f0-9]{64}\n\z/D', $bytes) !== 1) {
            throw new ControlRefusal('storage configuration pin changed or is invalid');
        }
        return substr($bytes, 0, -1);
    }

    private static function protectedAncestors(string $path, string $label): void {
        while ($path !== '/') {
            $stat = @lstat($path);
            if (!is_array($stat) || is_link($path) || ($stat['mode'] & 0170000) !== 0040000
                || ($stat['mode'] & 0022) !== 0 || (int) $stat['uid'] !== 0) {
                throw new ControlRefusal("storage $label has an unprotected install ancestor");
            }
            $parent = dirname($path);
            if ($parent === $path) {
                throw new ControlRefusal("storage $label ancestry is invalid");
            }
            $path = $parent;
        }
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function same(array $left, array $right): bool {
        return (int) ($left['dev'] ?? -1) === (int) ($right['dev'] ?? -2)
            && (int) ($left['ino'] ?? -1) === (int) ($right['ino'] ?? -2)
            && (int) ($left['size'] ?? -1) === (int) ($right['size'] ?? -2)
            && (int) ($left['mtime'] ?? -1) === (int) ($right['mtime'] ?? -2);
    }

    private static function sha256(mixed $value, string $label): void {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new ControlRefusal("$label is not lowercase SHA-256");
        }
    }

    private static function sortedStrings(mixed $values, int $limit, bool $sha, string $label): void {
        if (!is_array($values) || !array_is_list($values) || $values === [] || count($values) > $limit) {
            throw new ControlRefusal("storage $label registry is invalid");
        }
        $previous = null;
        foreach ($values as $value) {
            if (!is_string($value) || ($sha && preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1)
                || (!$sha && !self::safePath($value))
                || ($previous !== null && strcmp($previous, $value) >= 0)) {
                throw new ControlRefusal("storage $label registry is not canonical and unique");
            }
            $previous = $value;
        }
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new ControlRefusal('storage configuration has missing or unknown fields');
        }
    }
}

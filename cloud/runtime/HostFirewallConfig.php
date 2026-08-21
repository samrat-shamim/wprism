<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once dirname(__DIR__) . '/src/CanonicalJson.php';
require_once dirname(__DIR__) . '/src/ControlRefusal.php';

/** Closed host-global configuration for the root nftables authority. */
final class HostFirewallConfig {
    public const FORMAT = 'duo-cloud-host-firewall-config/v1';
    private const LIMIT = 1048576;
    private const EXECUTABLE_LIMIT = 67108864;

    /** @param array<string,mixed> $document */
    private function __construct(private array $document) {}

    public static function loadInstalled(string $path, string $pinPath): self {
        $pin = self::rootPin($pinPath);
        return self::load($path, $pin);
    }

    public static function load(string $path, string $expectedSha256): self {
        self::sha256($expectedSha256, 'firewall configuration pin');
        $bytes = self::pinnedFile(
            $path,
            $expectedSha256,
            'firewall configuration',
            self::LIMIT
        );
        $document = CanonicalJson::decodeObject($bytes, self::LIMIT);
        if ($bytes !== CanonicalJson::encode($document) . "\n") {
            throw new ControlRefusal('firewall configuration is not canonical JSON with one trailing LF');
        }
        self::exactKeys($document, [
            'container_engine', 'format', 'ip', 'nft', 'principals',
            'process_timeout_seconds', 'state_root',
        ]);
        if (($document['format'] ?? null) !== self::FORMAT) {
            throw new ControlRefusal('firewall configuration format is unsupported');
        }
        foreach (['container_engine', 'ip', 'nft'] as $name) {
            $descriptor = $document[$name] ?? null;
            if (!is_array($descriptor) || array_is_list($descriptor)) {
                throw new ControlRefusal("firewall $name executable descriptor is invalid");
            }
            self::exactKeys($descriptor, ['path', 'sha256']);
            self::pinnedFile(
                $descriptor['path'] ?? null,
                $descriptor['sha256'] ?? null,
                $name,
                self::EXECUTABLE_LIMIT
            );
            if (!is_executable($descriptor['path'])) {
                throw new ControlRefusal("firewall $name executable is not executable");
            }
        }
        if (!is_int($document['process_timeout_seconds'] ?? null)
            || $document['process_timeout_seconds'] < 1
            || $document['process_timeout_seconds'] > 5) {
            throw new ControlRefusal('firewall process timeout is outside the closed range');
        }
        $principals = $document['principals'] ?? null;
        if (!is_array($principals) || !array_is_list($principals)
            || $principals === [] || count($principals) > 32) {
            throw new ControlRefusal('firewall principal registry is empty or unbounded');
        }
        $previousWorker = null;
        $configurationDigests = [];
        foreach ($principals as $principal) {
            if (!is_array($principal) || array_is_list($principal)) {
                throw new ControlRefusal('firewall principal is malformed');
            }
            self::exactKeys($principal, ['configuration_sha256s', 'service_uid', 'worker_id']);
            $configurationSha256s = $principal['configuration_sha256s'] ?? null;
            $serviceUid = $principal['service_uid'] ?? null;
            $workerId = $principal['worker_id'] ?? null;
            if (!is_string($workerId)
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $workerId) !== 1
                || $previousWorker !== null && strcmp($previousWorker, $workerId) >= 0) {
                throw new ControlRefusal('firewall principals are not canonical and unique');
            }
            if (!is_int($serviceUid) || $serviceUid < 1) {
                throw new ControlRefusal('firewall principal service UID is invalid');
            }
            if (!is_array($configurationSha256s) || !array_is_list($configurationSha256s)
                || $configurationSha256s === [] || count($configurationSha256s) > 16) {
                throw new ControlRefusal('firewall principal configuration rotation set is invalid');
            }
            $previousConfiguration = null;
            foreach ($configurationSha256s as $configurationSha256) {
                self::sha256($configurationSha256, 'firewall principal configuration digest');
                if (($previousConfiguration !== null
                        && strcmp($previousConfiguration, $configurationSha256) >= 0)
                    || isset($configurationDigests[$configurationSha256])) {
                    throw new ControlRefusal('firewall principal configuration digests are not unique');
                }
                $configurationDigests[$configurationSha256] = true;
                $previousConfiguration = $configurationSha256;
            }
            $previousWorker = $workerId;
        }
        $stateRoot = $document['state_root'] ?? null;
        if (!is_string($stateRoot) || $stateRoot === '' || $stateRoot[0] !== '/'
            || str_contains($stateRoot, "\0") || preg_match('#(?:^|/)\.\.?(/|$)#D', $stateRoot) === 1
            || !is_dir($stateRoot) || is_link($stateRoot)) {
            throw new ControlRefusal('firewall state root is not an approved directory');
        }
        $stat = @lstat($stateRoot);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000
            || (DIRECTORY_SEPARATOR === '/' && ($stat['mode'] & 0077) !== 0)
            || (function_exists('posix_geteuid') && (int) $stat['uid'] !== posix_geteuid())) {
            throw new ControlRefusal('firewall state root is not private and process-owned');
        }
        return new self($document);
    }

    public function nft(): string {
        return $this->document['nft']['path'];
    }

    public function containerEngine(): string {
        return $this->document['container_engine']['path'];
    }

    public function ip(): string {
        return $this->document['ip']['path'];
    }

    public function stateRoot(): string {
        return (string) realpath($this->document['state_root']);
    }

    public function timeout(): int {
        return $this->document['process_timeout_seconds'];
    }

    /** @return list<array{configuration_sha256s:list<string>,service_uid:int,worker_id:string}> */
    public function principals(): array {
        return $this->document['principals'];
    }

    private static function pinnedFile(
        mixed $path,
        mixed $sha256,
        string $label,
        int $limit
    ): string {
        if (!is_string($path) || $path === '' || $path[0] !== '/' || str_contains($path, "\0")
            || preg_match('#(?:^|/)\.\.?(/|$)#D', $path) === 1) {
            throw new ControlRefusal("firewall $label path is invalid");
        }
        self::sha256($sha256, "firewall $label digest");
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!is_array($before) || is_link($path) || ($before['mode'] & 0170000) !== 0100000
            || ($before['mode'] & 0022) !== 0 || (int) $before['size'] < 1
            || (int) $before['size'] > $limit
            || (function_exists('posix_geteuid') && (int) $before['uid'] !== posix_geteuid())) {
            throw new ControlRefusal("firewall $label is not an approved pinned regular file");
        }
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::protectedAncestors(dirname($path), $label);
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal("firewall $label could not be opened");
        }
        $opened = fstat($handle);
        $bytes = stream_get_contents($handle, $limit + 1);
        $closed = fclose($handle);
        clearstatcache(true, $path);
        $after = @lstat($path);
        if (!is_array($opened) || !is_string($bytes) || !$closed || !is_array($after)
            || !self::sameFile($before, $opened) || !self::sameFile($before, $after)
            || strlen($bytes) > $limit
            || !hash_equals($sha256, hash('sha256', $bytes))) {
            throw new ControlRefusal("firewall $label changed or differs from its digest");
        }
        return $bytes;
    }

    private static function rootPin(string $path): string {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0
            || $path === '' || $path[0] !== '/' || str_contains($path, "\0")) {
            throw new ControlRefusal('firewall configuration pin path is invalid');
        }
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!is_array($before) || is_link($path) || ($before['mode'] & 0170000) !== 0100000
            || ($before['mode'] & 0022) !== 0 || (int) $before['uid'] !== 0
            || (int) $before['size'] !== 65) {
            throw new ControlRefusal('firewall configuration pin is not protected');
        }
        self::protectedAncestors(dirname($path), 'configuration pin');
        $handle = @fopen($path, 'rb');
        $opened = is_resource($handle) ? fstat($handle) : false;
        $bytes = is_resource($handle) ? stream_get_contents($handle, 66) : false;
        $closed = is_resource($handle) ? fclose($handle) : false;
        clearstatcache(true, $path);
        $after = @lstat($path);
        if (!is_array($opened) || !is_string($bytes) || !$closed || !is_array($after)
            || !self::sameFile($before, $opened) || !self::sameFile($before, $after)
            || preg_match('/\A[a-f0-9]{64}\n\z/D', $bytes) !== 1) {
            throw new ControlRefusal('firewall configuration pin changed or is invalid');
        }
        return substr($bytes, 0, -1);
    }

    private static function protectedAncestors(string $path, string $label): void {
        while ($path !== '/') {
            $stat = @lstat($path);
            if (!is_array($stat) || is_link($path) || ($stat['mode'] & 0170000) !== 0040000
                || ($stat['mode'] & 0022) !== 0 || (int) $stat['uid'] !== 0) {
                throw new ControlRefusal("firewall $label has an unprotected install ancestor");
            }
            $parent = dirname($path);
            if ($parent === $path) {
                throw new ControlRefusal("firewall $label install ancestry is invalid");
            }
            $path = $parent;
        }
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function sameFile(array $left, array $right): bool {
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

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new ControlRefusal('firewall configuration has missing or unknown fields');
        }
    }
}

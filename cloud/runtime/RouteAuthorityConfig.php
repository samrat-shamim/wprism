<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once dirname(__DIR__) . '/src/CanonicalJson.php';
require_once dirname(__DIR__) . '/src/ControlRefusal.php';

/** Immutable local configuration for the host TLS route authority. */
final class RouteAuthorityConfig {
    public const FORMAT = 'duo-cloud-route-authority-config/v1';

    /** @param array<string,mixed> $document */
    private function __construct(private array $document) {}

    public static function load(string $path, string $expectedSha256): self {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $expectedSha256) !== 1) {
            throw new ControlRefusal('route authority configuration pin is invalid');
        }
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!is_array($before) || is_link($path) || ($before['mode'] & 0170000) !== 0100000
            || ($before['mode'] & 0022) !== 0 || !self::ownedByService($before)
            || (int) $before['size'] < 2 || (int) $before['size'] > 1048576) {
            throw new ControlRefusal('route authority configuration file is unsafe');
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal('route authority configuration could not be opened');
        }
        $opened = fstat($handle);
        $bytes = stream_get_contents($handle, 1048577);
        $closed = fclose($handle);
        clearstatcache(true, $path);
        $after = @lstat($path);
        if (!is_array($opened) || !is_array($after) || !is_string($bytes) || !$closed
            || !self::sameFile($before, $opened) || !self::sameFile($before, $after)
            || strlen($bytes) > 1048576
            || !hash_equals($expectedSha256, hash('sha256', $bytes))) {
            throw new ControlRefusal('route authority configuration differs from its pin');
        }
        $document = CanonicalJson::decodeObject($bytes, 1048576);
        if ($bytes !== CanonicalJson::encode($document) . "\n") {
            throw new ControlRefusal('route authority configuration is not canonical');
        }
        self::exactKeys($document, [
            'admin_endpoint', 'container_engine', 'curl', 'format',
            'principals', 'process_timeout_seconds', 'state_root',
        ]);
        if (($document['format'] ?? null) !== self::FORMAT) {
            throw new ControlRefusal('route authority configuration format is unsupported');
        }
        foreach (['container_engine', 'curl'] as $field) {
            self::executable($document[$field] ?? null, str_replace('_', ' ', $field));
        }
        if (($document['admin_endpoint'] ?? null) !== 'http://127.0.0.1:2019/config/') {
            throw new ControlRefusal('route authority Caddy admin endpoint is not fixed loopback');
        }
        self::principals($document['principals'] ?? null);
        if (!is_int($document['process_timeout_seconds'] ?? null)
            || $document['process_timeout_seconds'] < 1
            || $document['process_timeout_seconds'] > 120) {
            throw new ControlRefusal('route authority process timeout is outside its closed range');
        }
        $stateRoot = $document['state_root'] ?? null;
        if (!is_string($stateRoot) || $stateRoot === '' || $stateRoot[0] !== '/'
            || preg_match('#(?:^|/)\.\.?(/|$)#D', $stateRoot) === 1 || $stateRoot === '/') {
            throw new ControlRefusal('route authority state root is invalid');
        }
        if (!file_exists($stateRoot) && !is_link($stateRoot)
            && (!mkdir($stateRoot, 0700, true) || !chmod($stateRoot, 0700))) {
            throw new ControlRefusal('route authority state root could not be created');
        }
        $real = realpath($stateRoot);
        $rootStat = is_string($real) ? @lstat($real) : false;
        if (!is_string($real) || !is_array($rootStat) || is_link($stateRoot)
            || ($rootStat['mode'] & 0077) !== 0 || !self::ownedByService($rootStat)
            || !is_writable($real)) {
            throw new ControlRefusal('route authority state root is not private');
        }
        $document['state_root'] = $real;
        return new self($document);
    }

    public function get(string $name): mixed {
        if (!array_key_exists($name, $this->document)) {
            throw new ControlRefusal('route authority configuration field is unknown');
        }
        return $this->document[$name];
    }

    /** @return array{preview_domain:string,reviewed_base_sha256:string,runtime_configuration_sha256:string} */
    public function principal(string $configurationSha256, string $reviewedBaseSha256): array {
        foreach ($this->document['principals'] as $principal) {
            if (hash_equals($principal['runtime_configuration_sha256'], $configurationSha256)
                && hash_equals($principal['reviewed_base_sha256'], $reviewedBaseSha256)) {
                return $principal;
            }
        }
        throw new ControlRefusal('route authority input has no registered runtime principal');
    }

    /** @param mixed $value */
    private static function principals(mixed $value): void {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 10000) {
            throw new ControlRefusal('route authority principals must be a non-empty bounded list');
        }
        $last = '';
        foreach ($value as $principal) {
            if (!is_array($principal) || array_is_list($principal)) {
                throw new ControlRefusal('route authority principal must be an object');
            }
            self::exactKeys($principal, [
                'preview_domain', 'reviewed_base_sha256', 'runtime_configuration_sha256',
            ]);
            foreach (['reviewed_base_sha256', 'runtime_configuration_sha256'] as $field) {
                if (!is_string($principal[$field] ?? null)
                    || preg_match('/\A[a-f0-9]{64}\z/D', $principal[$field]) !== 1) {
                    throw new ControlRefusal("route authority principal $field is invalid");
                }
            }
            if (!is_string($principal['preview_domain'] ?? null)
                || preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}\z/D', $principal['preview_domain']) !== 1) {
                throw new ControlRefusal('route authority principal preview domain is invalid');
            }
            $identity = $principal['runtime_configuration_sha256'] . "\0"
                . $principal['reviewed_base_sha256'];
            if ($identity <= $last) {
                throw new ControlRefusal('route authority principals are not uniquely canonical-sorted');
            }
            $last = $identity;
        }
    }

    /** @param mixed $value */
    private static function executable(mixed $value, string $label): void {
        if (!is_array($value) || array_is_list($value)) {
            throw new ControlRefusal("route authority $label descriptor is invalid");
        }
        self::exactKeys($value, ['path', 'sha256']);
        $path = $value['path'] ?? null;
        $sha = $value['sha256'] ?? null;
        if (!is_string($path) || $path === '' || $path[0] !== '/' || is_link($path)
            || !is_file($path) || !is_executable($path)
            || !is_string($sha) || preg_match('/\A[a-f0-9]{64}\z/D', $sha) !== 1) {
            throw new ControlRefusal("route authority $label executable is invalid");
        }
        $actual = hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($sha, $actual)) {
            throw new ControlRefusal("route authority $label executable differs from its pin");
        }
    }

    /** @param array<string,mixed> $stat */
    private static function ownedByService(array $stat): bool {
        if (!function_exists('posix_geteuid')) {
            return true;
        }
        return in_array((int) ($stat['uid'] ?? -1), [0, posix_geteuid()], true);
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function sameFile(array $left, array $right): bool {
        return (int) ($left['dev'] ?? -1) === (int) ($right['dev'] ?? -2)
            && (int) ($left['ino'] ?? -1) === (int) ($right['ino'] ?? -2)
            && (int) ($left['mode'] ?? -1) === (int) ($right['mode'] ?? -2)
            && (int) ($left['uid'] ?? -1) === (int) ($right['uid'] ?? -2)
            && (int) ($left['size'] ?? -1) === (int) ($right['size'] ?? -2);
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new ControlRefusal('route authority configuration has missing or unknown fields');
        }
    }
}

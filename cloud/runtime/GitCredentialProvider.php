<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once dirname(__DIR__) . '/src/CanonicalJson.php';
require_once dirname(__DIR__) . '/src/ControlRefusal.php';

/** Fixed-file, noninteractive Git HTTPS credential provider. */
final class GitCredentialProvider {
    public const FORMAT = 'duo-cloud-git-credential-provider/v1';

    /** @param array<string,mixed> $descriptor */
    private function __construct(private array $descriptor, private string $password) {}

    public function __destruct() {
        if ($this->password !== '') {
            sodium_memzero($this->password);
        }
    }

    public static function load(string $descriptorPath, string $pinPath): self {
        $pin = self::readFile($pinPath, 65, false, 'credential provider pin');
        if (preg_match('/\A([a-f0-9]{64})\n\z/D', $pin, $match) !== 1) {
            throw new ControlRefusal('credential provider pin is not canonical SHA-256');
        }
        $bytes = self::readFile($descriptorPath, 1048576, true, 'credential provider descriptor');
        if (!hash_equals($match[1], hash('sha256', $bytes))) {
            throw new ControlRefusal('credential provider descriptor differs from its pin');
        }
        $descriptor = CanonicalJson::decodeObject($bytes, 1048576);
        if ($bytes !== CanonicalJson::encode($descriptor) . "\n") {
            throw new ControlRefusal('credential provider descriptor is not canonical JSON');
        }
        self::exactKeys($descriptor, [
            'format', 'password_file', 'password_sha256', 'remote_url_sha256', 'username',
        ]);
        if (($descriptor['format'] ?? null) !== self::FORMAT) {
            throw new ControlRefusal('credential provider descriptor format is unsupported');
        }
        foreach (['password_sha256', 'remote_url_sha256'] as $field) {
            if (!is_string($descriptor[$field] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/D', $descriptor[$field]) !== 1) {
                throw new ControlRefusal("credential provider $field is invalid");
            }
        }
        if (!is_string($descriptor['password_file'] ?? null)
            || $descriptor['password_file'] === '' || $descriptor['password_file'][0] !== '/'
            || preg_match('#(?:^|/)\.\.?(/|$)#D', $descriptor['password_file']) === 1) {
            throw new ControlRefusal('credential provider password path is invalid');
        }
        if (!is_string($descriptor['username'] ?? null)
            || preg_match('/\A[A-Za-z0-9._@+-]{1,128}\z/D', $descriptor['username']) !== 1) {
            throw new ControlRefusal('credential provider username is invalid');
        }
        $passwordBytes = self::readFile(
            $descriptor['password_file'],
            4096,
            true,
            'credential provider password'
        );
        if (!hash_equals($descriptor['password_sha256'], hash('sha256', $passwordBytes))
            || preg_match('/\A([^\x00-\x20\x7f]{16,4095})\n\z/D', $passwordBytes, $passwordMatch) !== 1) {
            throw new ControlRefusal('credential provider password differs from its pin or is noncanonical');
        }
        return new self($descriptor, $passwordMatch[1]);
    }

    /** @return array{format:string,ready:bool,remote_url_sha256:string} */
    public function status(): array {
        return [
            'format' => 'duo-cloud-git-credential-provider-status/v1',
            'ready' => true,
            'remote_url_sha256' => $this->descriptor['remote_url_sha256'],
        ];
    }

    public function handle(string $action, string $input): string {
        if (!in_array($action, ['get', 'store', 'erase'], true)) {
            throw new ControlRefusal('credential provider action is unsupported');
        }
        if ($input === '' || strlen($input) > 16384 || !str_ends_with($input, "\n\n")) {
            throw new ControlRefusal('credential provider request framing is invalid');
        }
        $fields = [];
        foreach (explode("\n", substr($input, 0, -2)) as $line) {
            $separator = strpos($line, '=');
            if ($separator === false) {
                throw new ControlRefusal('credential provider request field is malformed');
            }
            $name = substr($line, 0, $separator);
            $value = substr($line, $separator + 1);
            if (!in_array($name, ['host', 'password', 'path', 'protocol', 'username'], true)
                || isset($fields[$name]) || $value === '' || str_contains($value, "\0")) {
                throw new ControlRefusal('credential provider request has unknown or repeated fields');
            }
            $fields[$name] = $value;
        }
        foreach (['host', 'path', 'protocol'] as $required) {
            if (!isset($fields[$required])) {
                throw new ControlRefusal('credential provider request is missing URL identity');
            }
        }
        if ($fields['protocol'] !== 'https'
            || preg_match('/\A[a-z0-9.-]+(?::443)?\z/D', $fields['host']) !== 1
            || $fields['path'][0] === '/' || str_contains($fields['path'], '..')) {
            throw new ControlRefusal('credential provider request URL is noncanonical');
        }
        $url = 'https://' . $fields['host'] . '/' . $fields['path'];
        $urlSha256 = hash('sha256', "duo-cloud-repository-remote-url/v1\0$url");
        if (!hash_equals($this->descriptor['remote_url_sha256'], $urlSha256)) {
            throw new ControlRefusal('credential provider request is for a foreign remote');
        }
        if ($action !== 'get') {
            return '';
        }
        return 'username=' . $this->descriptor['username'] . "\npassword=" . $this->password . "\n\n";
    }

    private static function readFile(string $path, int $limit, bool $private, string $label): string {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!is_array($before) || is_link($path) || ($before['mode'] & 0170000) !== 0100000
            || ($before['mode'] & 0022) !== 0
            || ($private && DIRECTORY_SEPARATOR === '/' && ($before['mode'] & 0077) !== 0)
            || (int) $before['size'] < 1 || (int) $before['size'] > $limit) {
            throw new ControlRefusal("$label is not a protected regular file");
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal("$label could not be opened");
        }
        $opened = fstat($handle);
        $bytes = stream_get_contents($handle, $limit + 1);
        $closed = fclose($handle);
        clearstatcache(true, $path);
        $after = @lstat($path);
        if (!is_array($opened) || !is_array($after) || !is_string($bytes) || !$closed
            || !self::sameFile($before, $opened) || !self::sameFile($before, $after)
            || strlen($bytes) > $limit) {
            throw new ControlRefusal("$label changed while reading");
        }
        return $bytes;
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
            throw new ControlRefusal('credential provider descriptor has missing or unknown fields');
        }
    }
}

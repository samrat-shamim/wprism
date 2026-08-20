<?php
declare(strict_types=1);

namespace Duo;

if (!class_exists(OriginCloudClient::class, false)) {
    require_once __DIR__ . '/OriginCloudClient.php';
}
if (!class_exists(OriginStore::class, false)) {
    require_once __DIR__ . '/OriginStore.php';
}

/** @internal Mutable view held under OriginPairingStateStore's file lock. */
final class OriginPairingStateSession {
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
 * Encrypted, atomic private-state boundary for pairing credentials.
 *
 * The caller supplies WordPress-secret-derived material; only its fixed
 * generichash is retained in memory. The state pathname contains an
 * XChaCha20-Poly1305 envelope, never a device code or Ed25519 private key.
 */
final class OriginPairingStateStore {
    private const ENVELOPE_FORMAT = 'duo-cloud-origin-pairing-ciphertext/v1';
    private const AAD = "duo-cloud-origin-pairing-ciphertext/v1\0";
    private const MAX_STATE_BYTES = 131072;

    private string $directory;
    private string $statePath;
    private string $lockPath;
    private string $encryptionKey;

    public function __construct(string $directory, string $keyMaterial) {
        self::assertSodium();
        if ($directory === '' || $directory[0] !== '/' || str_contains($directory, "\0")
            || strlen($keyMaterial) < 32 || strlen($keyMaterial) > 4096) {
            throw new \RuntimeException('duo: cloud origin pairing storage configuration is invalid');
        }
        $directory = rtrim($directory, '/');
        if ($directory === '') {
            throw new \RuntimeException('duo: cloud origin pairing storage directory is invalid');
        }
        self::ensurePrivateDirectory($directory);
        $resolved = realpath($directory);
        if (!is_string($resolved)) {
            throw new \RuntimeException('duo: cloud origin pairing storage directory could not be resolved');
        }
        $directory = rtrim($resolved, '/');
        self::ensurePrivateDirectory($directory);
        $this->directory = $directory;
        $this->statePath = $directory . '/pairing.json.enc';
        $this->lockPath = $directory . '/pairing.lock';
        $this->encryptionKey = sodium_crypto_generichash(
            "duo-cloud-origin-pairing-state-key/v1\0" . $keyMaterial,
            '',
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES
        );
    }

    public function __destruct() {
        if ($this->encryptionKey !== '') {
            sodium_memzero($this->encryptionKey);
        }
    }

    /** @return array<string,string> */
    public function __debugInfo(): array {
        return ['state_path' => $this->statePath];
    }

    /** @return never */
    public function __serialize(): array {
        throw new \RuntimeException('duo: cloud origin pairing stores containing encryption keys cannot be serialized');
    }

    public function __clone(): void {
        throw new \RuntimeException('duo: cloud origin pairing stores containing encryption keys cannot be cloned');
    }

    public function statePath(): string {
        return $this->statePath;
    }

    /**
     * @template T
     * @param callable(OriginPairingStateSession):T $callback
     * @return T
     */
    public function locked(callable $callback): mixed {
        self::assertRegularOrAbsent($this->lockPath, 'pairing lock');
        $handle = @fopen($this->lockPath, 'c+b');
        if (!is_resource($handle)) {
            throw new \RuntimeException('duo: cloud origin pairing lock could not be opened');
        }
        try {
            $opened = fstat($handle);
            $named = self::freshLstat($this->lockPath);
            if (!is_array($opened) || !is_array($named)
                || self::kind($opened) !== 0100000 || self::kind($named) !== 0100000
                || (string) ($opened['dev'] ?? '') !== (string) ($named['dev'] ?? '')
                || (string) ($opened['ino'] ?? '') !== (string) ($named['ino'] ?? '')) {
                throw new \RuntimeException('duo: cloud origin pairing lock path is unsafe');
            }
            if (!@chmod($this->lockPath, 0600)) {
                throw new \RuntimeException('duo: cloud origin pairing lock permissions could not be restricted');
            }
            $named = self::freshLstat($this->lockPath);
            if (!is_array($named) || !self::privateMode($named, 0600) || !self::ownedByProcess($named)) {
                throw new \RuntimeException('duo: cloud origin pairing lock is not private');
            }
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('duo: cloud origin pairing lock could not be acquired');
            }
            $named = self::freshLstat($this->lockPath);
            if (!is_array($named)
                || (string) ($opened['dev'] ?? '') !== (string) ($named['dev'] ?? '')
                || (string) ($opened['ino'] ?? '') !== (string) ($named['ino'] ?? '')) {
                throw new \RuntimeException('duo: cloud origin pairing lock pathname changed while held');
            }
            $this->reconcileTemporaryState();
            $session = new OriginPairingStateSession(
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
        if (self::kind($stat) !== 0100000 || is_link($this->statePath)
            || !self::privateMode($stat, 0600) || !self::ownedByProcess($stat)
            || (int) ($stat['size'] ?? -1) < 2 || (int) $stat['size'] > self::MAX_STATE_BYTES) {
            throw new \RuntimeException('duo: cloud origin pairing state path is unsafe');
        }
        $handle = @fopen($this->statePath, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('duo: cloud origin pairing state could not be opened');
        }
        try {
            $opened = fstat($handle);
            if (!is_array($opened)
                || (string) ($opened['dev'] ?? '') !== (string) ($stat['dev'] ?? '')
                || (string) ($opened['ino'] ?? '') !== (string) ($stat['ino'] ?? '')) {
                throw new \RuntimeException('duo: cloud origin pairing state changed while opening');
            }
            $bytes = stream_get_contents($handle, (int) $stat['size'] + 1);
            if (!is_string($bytes) || strlen($bytes) !== (int) $stat['size']) {
                throw new \RuntimeException('duo: cloud origin pairing state could not be read exactly');
            }
        } finally {
            fclose($handle);
        }
        $after = self::freshLstat($this->statePath);
        if (!is_array($after)
            || (string) ($after['dev'] ?? '') !== (string) ($stat['dev'] ?? '')
            || (string) ($after['ino'] ?? '') !== (string) ($stat['ino'] ?? '')
            || (int) ($after['size'] ?? -1) !== (int) $stat['size']) {
            throw new \RuntimeException('duo: cloud origin pairing state changed while reading');
        }
        $envelope = self::decodeObject($bytes);
        self::exactKeys($envelope, ['ciphertext', 'format', 'nonce'], 'pairing ciphertext');
        if (($envelope['format'] ?? null) !== self::ENVELOPE_FORMAT) {
            throw new \RuntimeException('duo: cloud origin pairing ciphertext format is unsupported');
        }
        $nonce = self::canonicalBase64(
            $envelope['nonce'] ?? null,
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES,
            'pairing nonce'
        );
        $ciphertext = self::canonicalBase64Variable($envelope['ciphertext'] ?? null, 'pairing ciphertext');
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            self::AAD,
            $nonce,
            $this->encryptionKey
        );
        if (!is_string($plaintext)) {
            throw new \RuntimeException('duo: cloud origin pairing state authentication failed');
        }
        try {
            return self::decodeObject($plaintext);
        } finally {
            sodium_memzero($plaintext);
        }
    }

    /** @param array<string,mixed> $state */
    private function writeState(array $state): void {
        $plaintext = self::encode($state) . "\n";
        if (strlen($plaintext) > self::MAX_STATE_BYTES / 2) {
            throw new \RuntimeException('duo: cloud origin pairing state exceeds its byte limit');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        try {
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $plaintext,
                self::AAD,
                $nonce,
                $this->encryptionKey
            );
        } finally {
            sodium_memzero($plaintext);
        }
        $bytes = self::encode([
            'ciphertext' => base64_encode($ciphertext),
            'format' => self::ENVELOPE_FORMAT,
            'nonce' => base64_encode($nonce),
        ]) . "\n";
        if (strlen($bytes) > self::MAX_STATE_BYTES) {
            throw new \RuntimeException('duo: cloud origin pairing ciphertext exceeds its byte limit');
        }

        $this->reconcileTemporaryState();
        $temporary = $this->statePath . '.tmp';
        $handle = @fopen($temporary, 'x+b');
        if (!is_resource($handle)) {
            throw new \RuntimeException('duo: cloud origin pairing temporary state could not be created');
        }
        $published = false;
        try {
            if (!@chmod($temporary, 0600)) {
                throw new \RuntimeException('duo: cloud origin pairing temporary permissions could not be restricted');
            }
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($handle, substr($bytes, $offset));
                if (!is_int($written) || $written < 1) {
                    throw new \RuntimeException('duo: cloud origin pairing state could not be written completely');
                }
                $offset += $written;
            }
            if (!fflush($handle) || (function_exists('fsync') && !@fsync($handle))) {
                throw new \RuntimeException('duo: cloud origin pairing state could not be flushed');
            }
            $opened = fstat($handle);
            $named = self::freshLstat($temporary);
            if (!is_array($opened) || !is_array($named)
                || self::kind($opened) !== 0100000 || self::kind($named) !== 0100000
                || (string) ($opened['dev'] ?? '') !== (string) ($named['dev'] ?? '')
                || (string) ($opened['ino'] ?? '') !== (string) ($named['ino'] ?? '')
                || !self::privateMode($named, 0600) || !self::ownedByProcess($named)
                || (int) ($opened['nlink'] ?? 0) !== 1 || (int) ($named['nlink'] ?? 0) !== 1
                || (int) ($opened['size'] ?? -1) !== strlen($bytes)
                || (int) ($named['size'] ?? -1) !== strlen($bytes)) {
                throw new \RuntimeException('duo: cloud origin pairing temporary state is unsafe');
            }
            self::assertRegularOrAbsent($this->statePath, 'pairing state');
            if (!@rename($temporary, $this->statePath)) {
                throw new \RuntimeException('duo: cloud origin pairing state could not be published atomically');
            }
            $published = true;
            $stateStat = self::freshLstat($this->statePath);
            if (!is_array($stateStat) || self::kind($stateStat) !== 0100000
                || !self::privateMode($stateStat, 0600) || !self::ownedByProcess($stateStat)
                || (int) ($stateStat['size'] ?? -1) !== strlen($bytes)) {
                throw new \RuntimeException('duo: published cloud origin pairing state is unsafe');
            }
            self::syncDirectory($this->directory);
        } finally {
            fclose($handle);
            if (!$published) {
                $this->reconcileTemporaryState();
            }
        }
    }

    /**
     * A destination-bound name makes one interrupted write the maximum residue.
     * The pairing lock excludes legitimate writers, while inode revalidation
     * prevents cleanup from following or unlinking a substituted pathname.
     */
    private function reconcileTemporaryState(): void {
        $temporary = $this->statePath . '.tmp';
        $before = self::freshLstat($temporary);
        if ($before === false) {
            return;
        }
        if (self::kind($before) !== 0100000 || is_link($temporary)
            || !self::privateMode($before, 0600) || !self::ownedByProcess($before)
            || (int) ($before['nlink'] ?? 0) !== 1
            || (int) ($before['size'] ?? -1) < 0
            || (int) ($before['size'] ?? -1) > self::MAX_STATE_BYTES) {
            throw new \RuntimeException('duo: cloud origin pairing temporary state is unsafe');
        }
        $handle = @fopen($temporary, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('duo: cloud origin pairing temporary state could not be opened');
        }
        try {
            $opened = fstat($handle);
            $after = self::freshLstat($temporary);
            if (!is_array($opened) || !is_array($after)
                || self::kind($opened) !== 0100000 || self::kind($after) !== 0100000
                || (string) ($before['dev'] ?? '') !== (string) ($opened['dev'] ?? '')
                || (string) ($before['ino'] ?? '') !== (string) ($opened['ino'] ?? '')
                || (string) ($after['dev'] ?? '') !== (string) ($opened['dev'] ?? '')
                || (string) ($after['ino'] ?? '') !== (string) ($opened['ino'] ?? '')
                || (int) ($before['size'] ?? -1) !== (int) ($opened['size'] ?? -2)
                || (int) ($after['size'] ?? -1) !== (int) ($opened['size'] ?? -2)
                || !self::privateMode($opened, 0600) || !self::privateMode($after, 0600)
                || !self::ownedByProcess($opened) || !self::ownedByProcess($after)
                || (int) ($opened['nlink'] ?? 0) !== 1 || (int) ($after['nlink'] ?? 0) !== 1) {
                throw new \RuntimeException('duo: cloud origin pairing temporary state changed during cleanup');
            }
        } finally {
            fclose($handle);
        }
        if (!@unlink($temporary) || self::freshLstat($temporary) !== false) {
            throw new \RuntimeException('duo: cloud origin pairing temporary state could not be removed');
        }
        self::syncDirectory($this->directory);
    }

    private static function ensurePrivateDirectory(string $directory): void {
        $parent = dirname($directory);
        $parentStat = self::freshLstat($parent);
        if (!is_array($parentStat) || self::kind($parentStat) !== 0040000 || is_link($parent)
            || !self::ownedByProcess($parentStat)
            || (DIRECTORY_SEPARATOR === '/' && (((int) ($parentStat['mode'] ?? 0)) & 0022) !== 0)) {
            throw new \RuntimeException('duo: cloud origin pairing storage parent is not protected');
        }
        $stat = self::freshLstat($directory);
        if ($stat === false) {
            if (!@mkdir($directory, 0700)) {
                throw new \RuntimeException('duo: cloud origin pairing storage directory could not be created');
            }
        } elseif (self::kind($stat) !== 0040000 || is_link($directory)) {
            throw new \RuntimeException('duo: cloud origin pairing storage directory is unsafe');
        }
        $stat = self::freshLstat($directory);
        if (!is_array($stat) || !self::privateMode($stat, 0700) || !self::ownedByProcess($stat)) {
            throw new \RuntimeException('duo: cloud origin pairing storage directory is not private');
        }
    }

    private static function assertRegularOrAbsent(string $path, string $label): void {
        $stat = self::freshLstat($path);
        if (is_array($stat) && (self::kind($stat) !== 0100000 || is_link($path))) {
            throw new \RuntimeException("duo: cloud origin $label path is unsafe");
        }
    }

    /** @return array<string,mixed> */
    private static function decodeObject(string $bytes): array {
        if ($bytes === '' || strlen($bytes) > self::MAX_STATE_BYTES || !str_ends_with($bytes, "\n")) {
            throw new \RuntimeException('duo: cloud origin pairing state is not bounded canonical JSON');
        }
        try {
            $decoded = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\Throwable $error) {
            throw new \RuntimeException('duo: cloud origin pairing state JSON is malformed', 0, $error);
        }
        if (!is_array($decoded) || array_is_list($decoded) || self::encode($decoded) . "\n" !== $bytes) {
            throw new \RuntimeException('duo: cloud origin pairing state JSON is not canonical');
        }
        return $decoded;
    }

    private static function encode(mixed $value): string {
        try {
            return json_encode(
                self::canonicalize($value, 0),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\RuntimeException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new \RuntimeException('duo: cloud origin pairing state cannot be encoded', 0, $error);
        }
    }

    private static function canonicalize(mixed $value, int $depth): mixed {
        if ($depth > 63 || is_float($value) || is_object($value) || is_resource($value)) {
            throw new \RuntimeException('duo: cloud origin pairing state contains an unsupported value');
        }
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            foreach (array_keys($value) as $key) {
                if (!is_string($key)) {
                    throw new \RuntimeException('duo: cloud origin pairing state object key is invalid');
                }
            }
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = self::canonicalize($child, $depth + 1);
        }
        return $value;
    }

    private static function canonicalBase64(mixed $value, int $length, string $label): string {
        $decoded = self::canonicalBase64Variable($value, $label);
        if (strlen($decoded) !== $length) {
            throw new \RuntimeException("duo: cloud origin $label has an invalid length");
        }
        return $decoded;
    }

    private static function canonicalBase64Variable(mixed $value, string $label): string {
        if (!is_string($value)) {
            throw new \RuntimeException("duo: cloud origin $label is malformed");
        }
        $decoded = base64_decode($value, true);
        if (!is_string($decoded) || base64_encode($decoded) !== $value) {
            throw new \RuntimeException("duo: cloud origin $label is not canonical base64");
        }
        return $decoded;
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException("duo: cloud origin $label has unexpected fields");
        }
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
            throw new \RuntimeException('duo: cloud origin pairing directory could not be opened for sync');
        }
        try {
            @fsync($handle);
        } finally {
            fclose($handle);
        }
    }

    private static function assertSodium(): void {
        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            throw new \RuntimeException('duo: cloud origin pairing requires sodium XChaCha20-Poly1305');
        }
    }
}

/**
 * Crash-safe pairing state machine layered over OriginCloudClient.
 *
 * `begin()` is the only method that accepts the administrator's device code;
 * durable state retains only its digest, while a retry must supply the same
 * code to reproduce the signed begin bytes. `poll()` activates authority only
 * from a verified signed `paired` payload. Dispatch phases are persisted before
 * I/O, so a lost response reuses the same attempt/sequence/id and never creates
 * new authority.
 */
final class OriginPairing {
    private const STATE_FORMAT = 'duo-cloud-origin-pairing-state/v1';

    private OriginPairingStateStore $store;
    private string $baseEndpoint;
    private string $serviceKeyId;
    private string $servicePublicKey;
    private int $timeoutSeconds;
    /** @var ?\Closure(string,string,int,int):array<string,mixed> */
    private ?\Closure $testExchange;

    /**
     * @param ?callable(string,string,int,int):array<string,mixed> $testExchange
     */
    public function __construct(
        OriginPairingStateStore $store,
        string $baseEndpoint,
        string $serviceKeyId,
        string $servicePublicKey,
        ?callable $testExchange = null,
        int $timeoutSeconds = 15
    ) {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $serviceKeyId) !== 1
            || strlen($servicePublicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $timeoutSeconds < 1 || $timeoutSeconds > 30) {
            throw new \RuntimeException('duo: cloud origin pairing client configuration is invalid');
        }
        $testMode = getenv('DUO_TEST_MODE') === '1';
        if ($testExchange !== null && !$testMode) {
            throw new \RuntimeException('duo: cloud origin pairing exchange injection requires DUO_TEST_MODE');
        }
        $this->store = $store;
        $this->baseEndpoint = $testMode
            ? OriginCloudClient::normalizeTestEndpoint($baseEndpoint)
            : OriginCloudClient::normalizeProductionEndpoint(
                $baseEndpoint,
                $serviceKeyId,
                $servicePublicKey
            );
        $this->serviceKeyId = $serviceKeyId;
        $this->servicePublicKey = $servicePublicKey;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->testExchange = $testExchange === null ? null : \Closure::fromCallable($testExchange);
    }

    public static function production(string $repository): self {
        $descriptor = OriginCloudClient::productionDescriptor();
        $originStore = OriginStore::open($repository);
        $stateRoot = $originStore->repository() . '/.duo/control/cloud-origin/pairing';
        $keyMaterial = self::wordpressKeyMaterial();
        try {
            return new self(
                new OriginPairingStateStore($stateRoot, $keyMaterial),
                $descriptor['endpoint'],
                $descriptor['service_key_id'],
                $descriptor['service_public_key']
            );
        } finally {
            sodium_memzero($keyMaterial);
        }
    }

    private static function wordpressKeyMaterial(): string {
        $names = ['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY'];
        $parts = [];
        foreach ($names as $name) {
            if (!defined($name) || !is_string(constant($name))) {
                throw new \RuntimeException('duo: WordPress secret keys are unavailable for encrypted origin state');
            }
            $value = (string) constant($name);
            if (strlen($value) < 32 || str_contains(strtolower($value), 'put your unique phrase here')) {
                throw new \RuntimeException('duo: WordPress secret keys are not strong enough for encrypted origin state');
            }
            $parts[] = $name . "\0" . $value;
        }
        return implode("\0", $parts);
    }

    /**
     * @param array<string,mixed> $connector
     * @return array<string,mixed>
     */
    public function begin(string $deviceCode, array $connector): array {
        self::assertBeginInput($deviceCode, $connector);
        return $this->store->locked(function (OriginPairingStateSession $session) use (
            $deviceCode,
            $connector
        ): array {
            $state = $session->state();
            if ($state !== null) {
                $this->assertState($state);
                $this->assertBinding($state);
                $sameDeviceCode = is_string($state['device_code_sha256'])
                    && hash_equals($state['device_code_sha256'], hash('sha256', $deviceCode));
                $sameConnector = $state['connector'] === $connector;
                if (!$sameDeviceCode || !$sameConnector) {
                    // A different one-time code is fresh administrator authority
                    // only after the prior attempt is conclusively terminal. The
                    // coordinator separately proves the upload spool is idle.
                    if (!$sameDeviceCode
                        && in_array($state['phase'], [
                            'denied', 'expired', 'rejected', 'remote_inactive', 'revoked',
                        ], true)) {
                        $state = null;
                    } else {
                        throw new \RuntimeException(
                            'duo: cloud origin pairing already binds different begin input'
                        );
                    }
                }
            }
            if ($state === null) {
                $keypair = sodium_crypto_sign_keypair();
                $secretKey = sodium_crypto_sign_secretkey($keypair);
                $publicKey = sodium_crypto_sign_publickey($keypair);
                try {
                    $state = $this->emptyState();
                    $state['connector'] = $connector;
                    $state['device_code_sha256'] = hash('sha256', $deviceCode);
                    $state['origin_key_id'] = OriginCloudClient::deriveOriginKeyId($publicKey);
                    $state['origin_public_key'] = base64_encode($publicKey);
                    $state['origin_secret_key'] = base64_encode($secretKey);
                    $state['pair_attempt_id'] = bin2hex(random_bytes(16));
                    $state['phase'] = 'beginning';
                    $this->assertState($state);
                    // Persist the code digest, key, attempt, and connector before
                    // dispatch. Supplying the same code after a lost response
                    // reproduces the exact signed request without storing it.
                    $session->save($state);
                } finally {
                    sodium_memzero($secretKey);
                }
            } else {
                if ($state['phase'] !== 'beginning') {
                    if (is_array($state['begin_response'])) {
                        return $state['begin_response'];
                    }
                    if ($state['phase'] === 'rejected' && is_array($state['begin_refusal'])) {
                        throw self::storedRefusal($state['begin_refusal']);
                    }
                    throw new \RuntimeException('duo: cloud origin pairing cannot begin from its current phase');
                }
            }

            $client = $this->clientFromState($state);
            try {
                $response = $client->pairBegin(
                    $deviceCode,
                    $state['connector'],
                    (string) $state['pair_attempt_id']
                );
            } catch (OriginCloudRefusal $refusal) {
                $state['begin_refusal'] = self::refusalEvidence(
                    $refusal,
                    ['pairing_code_rejected'],
                    'duo-cloud-origin-pair-begin-request/v1'
                );
                $state['origin_secret_key'] = null;
                $state['phase'] = 'rejected';
                $this->assertState($state);
                $session->save($state);
                throw $refusal;
            }
            $state['begin_response'] = $response;
            $state['pairing_id'] = $response['pairing_id'];
            $state['phase'] = 'pending';
            $state['poll_sequence'] = 0;
            $this->assertState($state);
            $session->save($state);
            return $response;
        });
    }

    /** @return array<string,mixed> */
    public function poll(): array {
        return $this->store->locked(function (OriginPairingStateSession $session): array {
            $state = $this->requiredState($session);
            if (in_array($state['phase'], ['paired', 'denied', 'expired'], true)) {
                if (!is_array($state['pair_response'])) {
                    throw new \RuntimeException('duo: terminal cloud origin pairing has no signed poll response');
                }
                return $state['pair_response'];
            }
            if (!in_array($state['phase'], ['pending', 'polling'], true)) {
                throw new \RuntimeException('duo: cloud origin pairing cannot poll from its current phase');
            }
            if ($state['phase'] === 'pending') {
                $state['phase'] = 'polling';
                $this->assertState($state);
                // The sequence is reserved before I/O. A lost response leaves
                // `polling`, causing the identical signed request on restart.
                $session->save($state);
            }

            $client = $this->clientFromState($state);
            $response = $client->pairPoll(
                (string) $state['pair_attempt_id'],
                (string) $state['pairing_id'],
                (int) $state['poll_sequence']
            );
            $state['pair_response'] = $response;
            if ($response['state'] === 'pending') {
                $state['poll_sequence']++;
                $state['phase'] = 'pending';
            } elseif ($response['state'] === 'paired') {
                $state['pairing'] = $response['pairing'];
                $state['phase'] = 'paired';
            } elseif ($response['state'] === 'denied' || $response['state'] === 'expired') {
                $state['origin_secret_key'] = null;
                $state['phase'] = $response['state'];
            } else {
                throw new \RuntimeException('duo: cloud origin pairing poll state is unsupported');
            }
            $this->assertState($state);
            $session->save($state);
            return $response;
        });
    }

    /**
     * Return a fixed-surface client only for signed, active pairing authority.
     * Neither the device code nor raw private key crosses this boundary.
     */
    public function pairedClient(): OriginCloudClient {
        return $this->store->locked(function (OriginPairingStateSession $session): OriginCloudClient {
            $state = $this->requiredState($session);
            if ($state['phase'] !== 'paired') {
                throw new \RuntimeException('duo: cloud origin client requires active signed pairing');
            }
            return $this->clientFromState($state);
        });
    }

    /**
     * Rotate once, retaining both exact keys while the signed outcome is
     * unknown. A completed receipt is replayed on retry; this bounded slice
     * intentionally requires a fresh pairing object/state for a later second
     * rotation rather than guessing whether a duplicate call meant "again".
     *
     * @return array<string,mixed>
     */
    public function rotate(): array {
        return $this->store->locked(function (OriginPairingStateSession $session): array {
            $state = $this->requiredState($session);
            if ($state['phase'] === 'paired' && is_array($state['rotation_response'])) {
                return $state['rotation_response'];
            }
            if ($state['phase'] === 'paired') {
                $keypair = sodium_crypto_sign_keypair();
                $newSecret = sodium_crypto_sign_secretkey($keypair);
                $newPublic = sodium_crypto_sign_publickey($keypair);
                try {
                    $state['new_origin_key_id'] = OriginCloudClient::deriveOriginKeyId($newPublic);
                    $state['new_origin_public_key'] = base64_encode($newPublic);
                    $state['new_origin_secret_key'] = base64_encode($newSecret);
                    $pairing = $state['pairing'];
                    if (!is_array($pairing)) {
                        throw new \RuntimeException('duo: cloud origin rotation has no pairing authority');
                    }
                    $state['rotation_id'] = OriginCloudClient::rotationId(
                        (string) $pairing['tenant_id'],
                        (string) $pairing['site_id'],
                        (int) $pairing['origin_generation'],
                        (string) $state['new_origin_key_id']
                    );
                    $state['phase'] = 'rotating';
                    $this->assertState($state);
                    $session->save($state);
                } finally {
                    sodium_memzero($newSecret);
                }
            } elseif ($state['phase'] !== 'rotating') {
                throw new \RuntimeException('duo: cloud origin pairing cannot rotate from its current phase');
            }

            $pairing = $state['pairing'];
            if (!is_array($pairing)) {
                throw new \RuntimeException('duo: cloud origin rotation has no pairing authority');
            }
            $newSecret = $this->decodeSecret($state['new_origin_secret_key'], 'new origin key');
            try {
                $client = $this->clientFromState($state);
                $response = $client->rotate(
                    (string) $pairing['tenant_id'],
                    (string) $pairing['site_id'],
                    (int) $pairing['origin_generation'],
                    (int) $pairing['origin_generation'] + 1,
                    $newSecret
                );
            } finally {
                sodium_memzero($newSecret);
            }
            $state['origin_key_id'] = $state['new_origin_key_id'];
            $state['origin_public_key'] = $state['new_origin_public_key'];
            $state['origin_secret_key'] = $state['new_origin_secret_key'];
            $state['new_origin_key_id'] = null;
            $state['new_origin_public_key'] = null;
            $state['new_origin_secret_key'] = null;
            $state['pairing']['origin_generation'] = $response['next_origin_generation'];
            $state['rotation_response'] = $response;
            $state['phase'] = 'paired';
            $this->assertState($state);
            $session->save($state);
            return $response;
        });
    }

    /** @return array<string,mixed> */
    public function revoke(string $reason = 'administrator_requested'): array {
        return $this->store->locked(function (OriginPairingStateSession $session) use ($reason): array {
            $state = $this->requiredState($session);
            if ($state['phase'] === 'revoked') {
                if ($state['revocation_reason'] !== $reason || !is_array($state['revocation_response'])) {
                    throw new \RuntimeException('duo: cloud origin pairing was revoked with different authority');
                }
                return $state['revocation_response'];
            }
            if ($state['phase'] === 'remote_inactive') {
                if ($state['revocation_reason'] !== $reason
                    || !is_array($state['revocation_refusal'])) {
                    throw new \RuntimeException(
                        'duo: cloud origin pairing was inactivated with different authority'
                    );
                }
                return $state['revocation_refusal'];
            }
            if ($state['phase'] === 'paired') {
                $pairing = $state['pairing'];
                if (!is_array($pairing)) {
                    throw new \RuntimeException('duo: cloud origin revocation has no pairing authority');
                }
                $state['revocation_id'] = OriginCloudClient::revocationId(
                    (string) $pairing['tenant_id'],
                    (string) $pairing['site_id'],
                    (int) $pairing['origin_generation'],
                    $reason
                );
                $state['revocation_reason'] = $reason;
                $state['phase'] = 'revoking';
                $this->assertState($state);
                $session->save($state);
            } elseif ($state['phase'] !== 'revoking' || $state['revocation_reason'] !== $reason) {
                throw new \RuntimeException('duo: cloud origin pairing cannot revoke from its current phase');
            }

            $pairing = $state['pairing'];
            if (!is_array($pairing)) {
                throw new \RuntimeException('duo: cloud origin revocation has no pairing authority');
            }
            $client = $this->clientFromState($state);
            try {
                $response = $client->revoke(
                    (string) $pairing['tenant_id'],
                    (string) $pairing['site_id'],
                    (int) $pairing['origin_generation'],
                    $reason
                );
            } catch (OriginCloudRefusal $refusal) {
                $state['revocation_refusal'] = self::refusalEvidence(
                    $refusal,
                    ['origin_revoked', 'stale_origin_generation'],
                    'duo-cloud-origin-revoke-request/v1'
                );
                $state['origin_secret_key'] = null;
                $state['phase'] = 'remote_inactive';
                $this->assertState($state);
                $session->save($state);
                return $state['revocation_refusal'];
            }
            $state['origin_secret_key'] = null;
            $state['revocation_response'] = $response;
            $state['phase'] = 'revoked';
            $this->assertState($state);
            $session->save($state);
            return $response;
        });
    }

    /**
     * Redacted durable status. It deliberately omits device code, public-key
     * bytes, private-key ciphertext, connectors, and signed response bodies.
     *
     * @return array<string,mixed>
     */
    public function status(): array {
        return $this->store->locked(function (OriginPairingStateSession $session): array {
            $state = $session->state();
            if ($state === null) {
                return [
                    'origin_key_id' => null,
                    'pair_attempt_id' => null,
                    'pairing' => null,
                    'pairing_id' => null,
                    'phase' => 'unpaired',
                ];
            }
            $this->assertState($state);
            $this->assertBinding($state);
            return [
                'origin_key_id' => $state['origin_key_id'],
                'pair_attempt_id' => $state['pair_attempt_id'],
                'pairing' => $state['pairing'],
                'pairing_id' => $state['pairing_id'],
                'phase' => $state['phase'],
            ];
        });
    }

    /** @return array<string,mixed> */
    private function emptyState(): array {
        return [
            'base_endpoint' => $this->baseEndpoint,
            'begin_refusal' => null,
            'begin_response' => null,
            'connector' => null,
            'device_code_sha256' => null,
            'format' => self::STATE_FORMAT,
            'new_origin_key_id' => null,
            'new_origin_public_key' => null,
            'new_origin_secret_key' => null,
            'origin_key_id' => null,
            'origin_public_key' => null,
            'origin_secret_key' => null,
            'pair_attempt_id' => null,
            'pair_response' => null,
            'pairing' => null,
            'pairing_id' => null,
            'phase' => 'unpaired',
            'poll_sequence' => 0,
            'revocation_id' => null,
            'revocation_refusal' => null,
            'revocation_reason' => null,
            'revocation_response' => null,
            'rotation_id' => null,
            'rotation_response' => null,
            'service_key_id' => $this->serviceKeyId,
            'service_public_key_sha256' => hash('sha256', $this->servicePublicKey),
        ];
    }

    /** @return array<string,mixed> */
    private function requiredState(OriginPairingStateSession $session): array {
        $state = $session->state();
        if ($state === null) {
            throw new \RuntimeException('duo: cloud origin pairing has not begun');
        }
        $this->assertState($state);
        $this->assertBinding($state);
        return $state;
    }

    /** @param array<string,mixed> $state */
    private function clientFromState(array $state): OriginCloudClient {
        $secret = $this->decodeSecret($state['origin_secret_key'], 'origin key');
        try {
            $client = new OriginCloudClient(
                $this->baseEndpoint,
                $this->serviceKeyId,
                $this->servicePublicKey,
                $secret,
                $this->testExchange,
                $this->timeoutSeconds
            );
        } finally {
            sodium_memzero($secret);
        }
        if ($client->originKeyId() !== $state['origin_key_id']) {
            throw new \RuntimeException('duo: cloud origin stored secret key does not match its id');
        }
        return $client;
    }

    private function decodeSecret(mixed $encoded, string $label): string {
        if (!is_string($encoded)) {
            throw new \RuntimeException("duo: cloud origin pairing $label is unavailable");
        }
        $secret = base64_decode($encoded, true);
        if (!is_string($secret) || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            || base64_encode($secret) !== $encoded) {
            throw new \RuntimeException("duo: cloud origin pairing $label is malformed");
        }
        return $secret;
    }

    /** @param array<string,mixed> $state */
    private function assertBinding(array $state): void {
        if ($state['base_endpoint'] !== $this->baseEndpoint
            || $state['service_key_id'] !== $this->serviceKeyId
            || !is_string($state['service_public_key_sha256'])
            || !hash_equals(hash('sha256', $this->servicePublicKey), $state['service_public_key_sha256'])) {
            throw new \RuntimeException('duo: cloud origin pairing state belongs to a different endpoint or service key');
        }
    }

    /** @param array<string,mixed> $state */
    private function assertState(array $state): void {
        self::exactKeys($state, [
            'base_endpoint', 'begin_refusal', 'begin_response', 'connector', 'device_code_sha256',
            'format', 'new_origin_key_id',
            'new_origin_public_key', 'new_origin_secret_key', 'origin_key_id',
            'origin_public_key', 'origin_secret_key', 'pair_attempt_id',
            'pair_response', 'pairing', 'pairing_id', 'phase', 'poll_sequence',
            'revocation_id', 'revocation_refusal', 'revocation_reason', 'revocation_response',
            'rotation_id', 'rotation_response', 'service_key_id',
            'service_public_key_sha256',
        ], 'pairing state');
        if (($state['format'] ?? null) !== self::STATE_FORMAT
            || !is_string($state['base_endpoint'] ?? null)
            || !is_string($state['service_key_id'] ?? null)
            || !self::isSha256($state['service_public_key_sha256'] ?? null)
            || !in_array($state['phase'] ?? null, [
                'beginning', 'denied', 'expired', 'paired', 'pending', 'polling',
                'rejected', 'remote_inactive', 'revoked', 'revoking', 'rotating',
            ], true)
            || !is_int($state['poll_sequence'] ?? null)
            || $state['poll_sequence'] < 0 || $state['poll_sequence'] > 9007199254740991
            || !self::isAttemptId($state['pair_attempt_id'] ?? null)
            || !self::isSha256($state['origin_key_id'] ?? null)
            || !self::isCanonicalBase64($state['origin_public_key'] ?? null, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)
            || !is_array($state['connector'] ?? null) || array_is_list($state['connector'])) {
            throw new \RuntimeException('duo: cloud origin pairing state is malformed');
        }
        self::assertConnector($state['connector']);
        $publicKey = base64_decode((string) $state['origin_public_key'], true);
        if (!is_string($publicKey)
            || OriginCloudClient::deriveOriginKeyId($publicKey) !== $state['origin_key_id']) {
            throw new \RuntimeException('duo: cloud origin pairing public key does not match its id');
        }
        if (!is_string($state['device_code_sha256'] ?? null)
            || !self::isSha256($state['device_code_sha256'])) {
            throw new \RuntimeException('duo: cloud origin pairing device-code binding is malformed');
        }
        foreach ([
            'begin_refusal', 'begin_response', 'pair_response', 'pairing',
            'revocation_refusal', 'revocation_response', 'rotation_response',
        ] as $key) {
            if ($state[$key] !== null && (!is_array($state[$key]) || array_is_list($state[$key]))) {
                throw new \RuntimeException("duo: cloud origin pairing state $key is malformed");
            }
        }
        foreach (['pairing_id', 'revocation_reason'] as $key) {
            if ($state[$key] !== null && !is_string($state[$key])) {
                throw new \RuntimeException("duo: cloud origin pairing state $key is malformed");
            }
        }
        foreach (['revocation_id', 'rotation_id'] as $key) {
            if ($state[$key] !== null && !self::isSha256($state[$key])) {
                throw new \RuntimeException("duo: cloud origin pairing state $key is malformed");
            }
        }
        if ($state['phase'] === 'beginning') {
            if (!self::hasSecret($state['origin_secret_key'])
                || $state['pairing_id'] !== null || $state['begin_response'] !== null
                || $state['begin_refusal'] !== null) {
                throw new \RuntimeException('duo: beginning cloud origin pairing state is incomplete');
            }
        } elseif ($state['phase'] === 'rejected') {
            self::assertRefusalEvidence(
                $state['begin_refusal'],
                ['pairing_code_rejected'],
                'duo-cloud-origin-pair-begin-request/v1'
            );
            if ($state['begin_response'] !== null || $state['pairing_id'] !== null) {
                throw new \RuntimeException('duo: rejected cloud origin pairing state is incomplete');
            }
        } else {
            if (!is_array($state['begin_response']) || !is_string($state['pairing_id'])
                || $state['pairing_id'] === '') {
                throw new \RuntimeException('duo: progressed cloud origin pairing state is incomplete');
            }
            if ($state['begin_refusal'] !== null) {
                throw new \RuntimeException('duo: progressed cloud origin pairing retained a begin refusal');
            }
        }
        if (in_array($state['phase'], ['pending', 'polling', 'paired', 'rotating', 'revoking'], true)
            && !self::hasSecret($state['origin_secret_key'])) {
            throw new \RuntimeException('duo: active cloud origin pairing has no private key');
        }
        if (self::hasSecret($state['origin_secret_key'])) {
            self::assertSecretMatchesPublic(
                $state['origin_secret_key'],
                $state['origin_public_key'],
                $state['origin_key_id'],
                'origin key'
            );
        }
        if (in_array($state['phase'], [
            'denied', 'expired', 'rejected', 'remote_inactive', 'revoked',
        ], true)
            && $state['origin_secret_key'] !== null) {
            throw new \RuntimeException('duo: terminal cloud origin pairing retained a private key');
        }
        if (in_array($state['phase'], [
            'paired', 'remote_inactive', 'rotating', 'revoking', 'revoked',
        ], true)) {
            self::assertPairingAuthority($state['pairing']);
        } elseif ($state['pairing'] !== null) {
            throw new \RuntimeException('duo: inactive cloud origin pairing contains active authority');
        }
        if ($state['phase'] === 'rotating') {
            if (!self::isSha256($state['rotation_id'])
                || !self::isSha256($state['new_origin_key_id'])
                || !self::isCanonicalBase64(
                    $state['new_origin_public_key'],
                    SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                )
                || !self::hasSecret($state['new_origin_secret_key'])) {
                throw new \RuntimeException('duo: rotating cloud origin pairing has no complete new key');
            }
            self::assertSecretMatchesPublic(
                $state['new_origin_secret_key'],
                $state['new_origin_public_key'],
                $state['new_origin_key_id'],
                'new origin key'
            );
        } elseif ($state['new_origin_key_id'] !== null
            || $state['new_origin_public_key'] !== null
            || $state['new_origin_secret_key'] !== null) {
            throw new \RuntimeException('duo: non-rotating cloud origin pairing retained staged key material');
        }
        if ($state['phase'] === 'revoking') {
            if (!self::isSha256($state['revocation_id'])
                || !in_array($state['revocation_reason'], ['administrator_requested', 'uninstall'], true)) {
                throw new \RuntimeException('duo: revoking cloud origin pairing has incomplete intent');
            }
        }
        if ($state['phase'] === 'revoked' && (!is_array($state['revocation_response'])
            || !in_array($state['revocation_reason'], ['administrator_requested', 'uninstall'], true))) {
            throw new \RuntimeException('duo: revoked cloud origin pairing has no signed receipt');
        }
        if ($state['phase'] === 'remote_inactive') {
            if (!self::isSha256($state['revocation_id'])
                || !in_array($state['revocation_reason'], ['administrator_requested', 'uninstall'], true)
                || $state['revocation_response'] !== null) {
                throw new \RuntimeException('duo: inactive cloud origin pairing has incomplete revoke intent');
            }
            self::assertRefusalEvidence(
                $state['revocation_refusal'],
                ['origin_revoked', 'stale_origin_generation'],
                'duo-cloud-origin-revoke-request/v1'
            );
        } elseif ($state['revocation_refusal'] !== null) {
            throw new \RuntimeException('duo: active cloud origin pairing retained a revoke refusal');
        }
    }

    /**
     * @param list<string> $reasons
     * @return array<string,mixed>
     */
    private static function refusalEvidence(
        OriginCloudRefusal $refusal,
        array $reasons,
        string $requestFormat
    ): array {
        $evidence = [
            'reason_code' => $refusal->reasonCode(),
            'request_format' => $refusal->requestFormat(),
            'request_sha256' => $refusal->requestSha256(),
            'retryable' => $refusal->retryable(),
        ];
        self::assertRefusalEvidence($evidence, $reasons, $requestFormat);
        return $evidence;
    }

    /** @param array<string,mixed> $evidence @param list<string> $reasons */
    private static function assertRefusalEvidence(
        array $evidence,
        array $reasons,
        string $requestFormat
    ): void {
        self::exactKeys($evidence, [
            'reason_code', 'request_format', 'request_sha256', 'retryable',
        ], 'signed refusal evidence');
        if (!in_array($evidence['reason_code'] ?? null, $reasons, true)
            || ($evidence['request_format'] ?? null) !== $requestFormat
            || !self::isSha256($evidence['request_sha256'] ?? null)
            || ($evidence['retryable'] ?? null) !== false) {
            throw new \RuntimeException('duo: cloud origin signed refusal evidence is malformed');
        }
    }

    /** @param array<string,mixed> $evidence */
    private static function storedRefusal(array $evidence): OriginCloudRefusal {
        self::assertRefusalEvidence(
            $evidence,
            ['pairing_code_rejected'],
            'duo-cloud-origin-pair-begin-request/v1'
        );
        return new OriginCloudRefusal(
            $evidence['reason_code'],
            $evidence['retryable'],
            $evidence['request_format'],
            $evidence['request_sha256']
        );
    }

    private static function assertPairingAuthority(mixed $pairing): void {
        if (!is_array($pairing) || array_is_list($pairing)) {
            throw new \RuntimeException('duo: cloud origin pairing authority is malformed');
        }
        self::exactKeys($pairing, [
            'demand_generation', 'origin_generation', 'service_key_id',
            'service_public_key_sha256', 'site_id', 'tenant_id',
        ], 'pairing authority');
        if (!is_int($pairing['demand_generation'] ?? null) || $pairing['demand_generation'] < 0
            || !is_int($pairing['origin_generation'] ?? null) || $pairing['origin_generation'] < 1
            || !is_string($pairing['service_key_id'] ?? null)
            || !self::isSha256($pairing['service_public_key_sha256'] ?? null)
            || !is_string($pairing['site_id'] ?? null) || $pairing['site_id'] === ''
            || !is_string($pairing['tenant_id'] ?? null) || $pairing['tenant_id'] === '') {
            throw new \RuntimeException('duo: cloud origin pairing authority is malformed');
        }
    }

    /** @param array<string,mixed> $connector */
    private static function assertBeginInput(string $deviceCode, array $connector): void {
        if (preg_match('/\A[A-Z2-7]{5}(?:-[A-Z2-7]{5}){3}\z/D', $deviceCode) !== 1) {
            throw new \RuntimeException('duo: cloud origin device code is malformed');
        }
        self::assertConnector($connector);
    }

    /** @param array<string,mixed> $connector */
    private static function assertConnector(array $connector): void {
        self::exactKeys($connector, [
            'agent_version', 'home_url_sha256', 'installation_id', 'multisite',
            'php_version', 'site_url_sha256', 'wordpress_version',
        ], 'connector');
        foreach (['agent_version', 'php_version', 'wordpress_version'] as $key) {
            if (!is_string($connector[$key] ?? null) || $connector[$key] === ''
                || strlen($connector[$key]) > 64 || str_contains($connector[$key], "\0")) {
                throw new \RuntimeException("duo: cloud origin connector $key is malformed");
            }
        }
        if (!self::isSha256($connector['home_url_sha256'] ?? null)
            || !self::isSha256($connector['site_url_sha256'] ?? null)
            || !is_string($connector['installation_id'] ?? null)
            || preg_match(
                '/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',
                $connector['installation_id']
            ) !== 1
            || !is_bool($connector['multisite'] ?? null)) {
            throw new \RuntimeException('duo: cloud origin connector identity is malformed');
        }
    }

    private static function hasSecret(mixed $value): bool {
        return self::isCanonicalBase64($value, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
    }

    private static function assertSecretMatchesPublic(
        mixed $encodedSecret,
        mixed $encodedPublic,
        mixed $keyId,
        string $label
    ): void {
        if (!is_string($encodedSecret) || !is_string($encodedPublic) || !is_string($keyId)) {
            throw new \RuntimeException("duo: cloud origin pairing $label is malformed");
        }
        $secret = base64_decode($encodedSecret, true);
        $public = base64_decode($encodedPublic, true);
        if (!is_string($secret) || !is_string($public)) {
            throw new \RuntimeException("duo: cloud origin pairing $label is malformed");
        }
        try {
            $derived = sodium_crypto_sign_publickey_from_secretkey($secret);
            if (!hash_equals($public, $derived)
                || !hash_equals($keyId, OriginCloudClient::deriveOriginKeyId($derived))) {
                throw new \RuntimeException("duo: cloud origin pairing $label does not match its public identity");
            }
        } finally {
            sodium_memzero($secret);
        }
    }

    private static function isCanonicalBase64(mixed $value, int $length): bool {
        if (!is_string($value)) {
            return false;
        }
        $decoded = base64_decode($value, true);
        return is_string($decoded) && strlen($decoded) === $length && base64_encode($decoded) === $value;
    }

    private static function isSha256(mixed $value): bool {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private static function isAttemptId(mixed $value): bool {
        return is_string($value) && preg_match('/\A[a-f0-9]{32}\z/D', $value) === 1;
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException("duo: cloud origin $label has unexpected fields");
        }
    }
}

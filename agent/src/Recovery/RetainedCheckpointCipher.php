<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/DatabaseTargetIdentity.php';

/**
 * Streaming authenticated encryption for operator-directed database
 * checkpoints. The key is derived from WordPress's target-local signing salts,
 * so copying the site repository never copies the key beside its ciphertext.
 */
final class RetainedCheckpointCipher {
    private const MAGIC = "WPRISMCP01\n";
    private const TARGET_PREFIX = "WPRISMDB01\n";
    private const CHUNK_BYTES = 1048576;
    private const KEY_CONTEXT = 'wprism retained checkpoint v1';

    /** @param resource $input */
    public static function seal(
        string $repo,
        string $output,
        $input,
        string $databaseTargetSha256
    ): void {
        self::assertPath($repo, $output);
        if (file_exists($output) || is_link($output)) {
            throw new \RuntimeException('encrypted retained checkpoints are immutable once published');
        }
        if (!is_resource($input)) {
            throw new \InvalidArgumentException('checkpoint plaintext input must be a stream');
        }
        $databaseTargetSha256 = DatabaseTargetIdentity::assertDigest($databaseTargetSha256);
        [$key, $keyId] = self::key();
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $temporary = $output . '.tmp-' . bin2hex(random_bytes(8));
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            sodium_memzero($key);
            throw new \RuntimeException('could not create the encrypted checkpoint staging file');
        }
        if (!@chmod($temporary, 0600)) {
            fclose($handle);
            @unlink($temporary);
            sodium_memzero($key);
            throw new \RuntimeException('could not protect the encrypted checkpoint staging file');
        }
        try {
            self::write($handle, self::MAGIC . $keyId . $header);
            $identity = sodium_crypto_secretstream_xchacha20poly1305_push(
                $state,
                self::TARGET_PREFIX . $databaseTargetSha256,
                '',
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
            );
            self::write($handle, pack('N', strlen($identity)) . $identity);
            while (!feof($input)) {
                $plain = fread($input, self::CHUNK_BYTES);
                if ($plain === false) {
                    throw new \RuntimeException('could not read the database export stream');
                }
                if ($plain === '' && !feof($input)) {
                    continue;
                }
                $tag = feof($input)
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plain, '', $tag);
                self::write($handle, pack('N', strlen($cipher)) . $cipher);
            }
            if (!fflush($handle) || !fsync($handle)) {
                throw new \RuntimeException('could not durably flush the encrypted checkpoint');
            }
            $staged = fstat($handle);
            if (!is_array($staged)
                || (($staged['mode'] ?? 0) & 0170000) !== 0100000
                || (($staged['mode'] ?? 0) & 0077) !== 0) {
                throw new \RuntimeException('encrypted checkpoint staging identity or mode is unsafe');
            }
            fclose($handle);
            $handle = null;
            $expectedDigest = @hash_file('sha256', $temporary);
            if (!is_string($expectedDigest)) {
                throw new \RuntimeException('could not identify the encrypted checkpoint staging bytes');
            }
            // Same-directory hard-link publication is atomic and create-only;
            // rename(2) would silently replace a checkpoint that appeared
            // between the absence check and publication.
            if (!@link($temporary, $output)) {
                throw new \RuntimeException('could not publish the encrypted checkpoint atomically');
            }
            if (!@unlink($temporary)) {
                throw new \RuntimeException('could not retire the encrypted checkpoint staging name');
            }
            self::syncDirectory(dirname($output));
            $published = @lstat($output);
            $actualDigest = @hash_file('sha256', $output);
            if (!is_array($published)
                || (($published['mode'] ?? 0) & 0170000) !== 0100000
                || (($published['mode'] ?? 0) & 0077) !== 0
                || !is_string($actualDigest)
                || !hash_equals($expectedDigest, $actualDigest)) {
                throw new \RuntimeException('encrypted checkpoint durable publication readback failed');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($temporary);
            sodium_memzero($key);
        }
    }

    /**
     * Authenticate a retained checkpoint without exposing plaintext. The
     * ciphertext digest lets a later destructive topology reset prove it is
     * opening the exact bytes authenticated before the reset.
     *
     * @return array{cipher_sha256:string,database_target_sha256:string,format:string}
     */
    public static function verify(string $repo, string $input): array {
        self::assertPath($repo, $input);
        [$key, $keyId] = self::key();
        $handle = @fopen($input, 'rb');
        if ($handle === false) {
            sodium_memzero($key);
            throw new \RuntimeException('could not open the encrypted checkpoint');
        }
        try {
            self::assertOpenedInput($input, $handle);
            if (!flock($handle, LOCK_SH)) {
                throw new \RuntimeException('could not lock the encrypted checkpoint for authenticated restore');
            }
            $verification = self::decrypt(
                $handle,
                $key,
                $keyId,
                false,
                STDOUT,
                hash_init('sha256')
            );
            return [
                'cipher_sha256' => $verification['cipher_sha256'],
                'database_target_sha256' => $verification['database_target_sha256'],
                'format' => 'wprism-retained-checkpoint-verification/v2',
            ];
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
            sodium_memzero($key);
        }
    }

    /** @param resource|null $output */
    public static function open(
        string $repo,
        string $input,
        $output = null,
        ?string $expectedCipherSha256 = null,
        ?string $expectedDatabaseTargetSha256 = null
    ): void {
        self::assertPath($repo, $input);
        if ($expectedCipherSha256 !== null
            && preg_match('/^[a-f0-9]{64}$/D', $expectedCipherSha256) !== 1) {
            throw new \InvalidArgumentException('expected checkpoint ciphertext digest is invalid');
        }
        if ($expectedDatabaseTargetSha256 !== null) {
            $expectedDatabaseTargetSha256 = DatabaseTargetIdentity::assertDigest(
                $expectedDatabaseTargetSha256
            );
        }
        $output ??= STDOUT;
        if (!is_resource($output)) {
            throw new \InvalidArgumentException('checkpoint plaintext output must be a stream');
        }
        [$key, $keyId] = self::key();
        $handle = @fopen($input, 'rb');
        if ($handle === false) {
            sodium_memzero($key);
            throw new \RuntimeException('could not open the encrypted checkpoint');
        }
        $snapshot = null;
        try {
            self::assertOpenedInput($input, $handle);
            if (!flock($handle, LOCK_SH)) {
                throw new \RuntimeException('could not lock the encrypted checkpoint for authenticated restore');
            }
            // An advisory lock cannot stop a same-UID writer from truncating
            // this inode. Capture one private, unlinked ciphertext identity;
            // both authentication and emission then read bytes no pathname can
            // replace between passes.
            [$snapshot, $snapshotDigest] = self::privateCiphertextSnapshot($handle, $input);
            if ($expectedCipherSha256 !== null
                && !hash_equals($expectedCipherSha256, $snapshotDigest)) {
                throw new \RuntimeException(
                    'the retained checkpoint changed after pre-restore authentication'
                );
            }
            $verification = self::decrypt(
                $snapshot,
                $key,
                $keyId,
                false,
                $output
            );
            if ($expectedDatabaseTargetSha256 !== null
                && !hash_equals(
                    $expectedDatabaseTargetSha256,
                    $verification['database_target_sha256']
                )) {
                throw new \RuntimeException(
                    'the retained checkpoint database target differs from pre-restore authentication'
                );
            }
            if (!rewind($snapshot)) {
                throw new \RuntimeException('could not rewind the private checkpoint snapshot');
            }
            self::decrypt($snapshot, $key, $keyId, true, $output);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
            if (is_resource($snapshot)) {
                fclose($snapshot);
            }
            sodium_memzero($key);
        }
    }

    /** @return array{string,string} */
    private static function key(): array {
        $parts = [];
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY'] as $name) {
            if (!defined($name) || !is_string(constant($name)) || strlen((string) constant($name)) < 32) {
                throw new \RuntimeException("WordPress $name is unavailable for checkpoint encryption");
            }
            $parts[] = (string) constant($name);
        }
        $key = hash_hkdf('sha256', implode("\0", $parts), 32, self::KEY_CONTEXT);

        return [$key, substr(hash('sha256', $key), 0, 16)];
    }

    private static function assertPath(string $repo, string $path): void {
        $repoInput = rtrim($repo, '/');
        $repoRoot = realpath($repoInput);
        $stateDirectory = $repoRoot === false ? false : $repoRoot . '/.wprism';
        $checkpointDirectory = $stateDirectory === false ? false : $stateDirectory . '/checkpoints';
        $basename = basename($path);
        if ($repoRoot === false
            || !is_dir($repoRoot)
            || is_link($repoInput)
            || $stateDirectory === false
            || !is_dir($stateDirectory)
            || is_link($stateDirectory)
            || realpath($stateDirectory) !== $stateDirectory
            || $checkpointDirectory === false
            || !is_dir($checkpointDirectory)
            || is_link($checkpointDirectory)
            || realpath($checkpointDirectory) !== $checkpointDirectory
            || dirname($path) !== $checkpointDirectory
            || $path !== $checkpointDirectory . '/' . $basename
            || preg_match('/^(?:promote|deploy|materialize)-[A-Za-z0-9][A-Za-z0-9._-]{0,127}\.sql\.enc$/D', $basename) !== 1
            || (file_exists($path) && !is_file($path))
            || is_link($path)) {
            throw new \RuntimeException('checkpoint path is outside the encrypted retained-checkpoint boundary');
        }
    }

    private static function syncDirectory(string $directory): void {
        $handle = @fopen($directory, 'r');
        if (!is_resource($handle)) {
            throw new \RuntimeException('could not open the retained-checkpoint directory for fsync');
        }
        try {
            if (!@fsync($handle)) {
                throw new \RuntimeException('could not fsync the retained-checkpoint directory');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private static function assertOpenedInput(string $path, $handle): void {
        $opened = fstat($handle);
        $named = @lstat($path);
        if (!is_array($opened)
            || !is_array($named)
            || (($opened['mode'] ?? 0) & 0170000) !== 0100000
            || (($named['mode'] ?? 0) & 0170000) !== 0100000
            || ($opened['dev'] ?? null) !== ($named['dev'] ?? null)
            || ($opened['ino'] ?? null) !== ($named['ino'] ?? null)) {
            throw new \RuntimeException('checkpoint input changed or escaped its retained-checkpoint boundary');
        }
    }

    /**
     * Copy ciphertext, never plaintext, to a private unlinked regular file.
     *
     * @param resource $source
     * @return array{0:resource,1:string}
     */
    private static function privateCiphertextSnapshot($source, string $path): array {
        $snapshot = @tmpfile();
        if (!is_resource($snapshot)) {
            throw new \RuntimeException('could not create the private checkpoint snapshot');
        }
        try {
            $metadata = stream_get_meta_data($snapshot);
            $temporaryPath = is_string($metadata['uri'] ?? null) ? $metadata['uri'] : '';
            if ($temporaryPath !== '') {
                @chmod($temporaryPath, 0600);
                if (!@unlink($temporaryPath) && file_exists($temporaryPath)) {
                    throw new \RuntimeException('could not unlink the private checkpoint snapshot');
                }
            }
            $identity = fstat($snapshot);
            if (!is_array($identity)
                || (($identity['mode'] ?? 0) & 0170000) !== 0100000
                || (($identity['mode'] ?? 0) & 0077) !== 0) {
                throw new \RuntimeException('private checkpoint snapshot identity or mode is unsafe');
            }
            $digest = hash_init('sha256');
            while (!feof($source)) {
                $bytes = fread($source, self::CHUNK_BYTES);
                if ($bytes === false) {
                    throw new \RuntimeException('could not read the encrypted checkpoint snapshot source');
                }
                if ($bytes === '' && !feof($source)) {
                    continue;
                }
                hash_update($digest, $bytes);
                self::write($snapshot, $bytes);
            }
            // A pathname swap is a distinct failure from a same-inode write.
            // Recheck it after the copy; the digest plus secretstream proof
            // handles any same-inode bytes observed during the copy itself.
            self::assertOpenedInput($path, $source);
            if (!fflush($snapshot) || !rewind($snapshot)) {
                throw new \RuntimeException('could not finalize the private checkpoint snapshot');
            }
            return [$snapshot, hash_final($digest)];
        } catch (\Throwable $failure) {
            fclose($snapshot);
            throw $failure;
        }
    }

    /**
     * @param resource $handle
     * @param resource $output
     * @return array{cipher_sha256:string,database_target_sha256:string}
     */
    private static function decrypt(
        $handle,
        string $key,
        string $keyId,
        bool $emit,
        $output,
        ?\HashContext $cipherDigest = null
    ): array {
        $prefixBytes = strlen(self::MAGIC) + 16;
        $prefix = self::readExact($handle, $prefixBytes, $cipherDigest);
        if (substr($prefix, 0, strlen(self::MAGIC)) !== self::MAGIC) {
            throw new \RuntimeException('the retained checkpoint is not a WPrism encrypted checkpoint');
        }
        if (!hash_equals($keyId, substr($prefix, strlen(self::MAGIC)))) {
            throw new \RuntimeException('the retained checkpoint belongs to different target key material');
        }
        $header = self::readExact(
            $handle,
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES,
            $cipherDigest
        );
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        $sawFinal = false;
        $databaseTargetSha256 = null;
        $record = 0;
        while (!feof($handle)) {
            $lengthBytes = fread($handle, 4);
            if ($lengthBytes === false || $lengthBytes === '') {
                break;
            }
            if (strlen($lengthBytes) !== 4) {
                throw new \RuntimeException('the encrypted checkpoint record length is truncated');
            }
            if ($cipherDigest !== null) {
                hash_update($cipherDigest, $lengthBytes);
            }
            $length = unpack('Nlength', $lengthBytes)['length'];
            $maximum = self::CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
            if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $length > $maximum) {
                throw new \RuntimeException('the encrypted checkpoint record length is invalid');
            }
            $opened = sodium_crypto_secretstream_xchacha20poly1305_pull(
                $state,
                self::readExact($handle, $length, $cipherDigest)
            );
            if ($opened === false) {
                throw new \RuntimeException('the encrypted checkpoint failed authentication');
            }
            [$plain, $tag] = $opened;
            if ($sawFinal || ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
                && $tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL)) {
                throw new \RuntimeException('the encrypted checkpoint record sequence is invalid');
            }
            if ($record === 0) {
                $prefixBytes = strlen(self::TARGET_PREFIX);
                $candidate = substr($plain, $prefixBytes);
                if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
                    || substr($plain, 0, $prefixBytes) !== self::TARGET_PREFIX
                    || strlen($candidate) !== 64) {
                    throw new \RuntimeException(
                        'the retained checkpoint does not bind its pre-mutation database target'
                    );
                }
                $databaseTargetSha256 = DatabaseTargetIdentity::assertDigest($candidate);
            } elseif ($emit) {
                self::write($output, $plain);
            }
            $sawFinal = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            ++$record;
        }
        if (!$sawFinal || $databaseTargetSha256 === null) {
            throw new \RuntimeException('the encrypted checkpoint has no authenticated final record');
        }
        return [
            'cipher_sha256' => $cipherDigest === null ? '' : hash_final($cipherDigest),
            'database_target_sha256' => $databaseTargetSha256,
        ];
    }

    /** @param resource $handle */
    private static function readExact(
        $handle,
        int $length,
        ?\HashContext $cipherDigest = null
    ): string {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $part = fread($handle, $length - strlen($bytes));
            if ($part === false || $part === '') {
                throw new \RuntimeException('the encrypted checkpoint is truncated');
            }
            $bytes .= $part;
        }
        if ($cipherDigest !== null) {
            hash_update($cipherDigest, $bytes);
        }

        return $bytes;
    }

    /** @param resource $handle */
    private static function write($handle, string $bytes): void {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('could not write the checkpoint stream');
            }
            $offset += $written;
        }
    }
}

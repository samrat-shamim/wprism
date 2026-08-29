<?php
declare(strict_types=1);

namespace Duo;

/**
 * Streaming authenticated encryption for operator-directed database
 * checkpoints. The key is derived from WordPress's target-local signing salts,
 * so copying the site repository never copies the key beside its ciphertext.
 */
final class RetainedCheckpointCipher {
    private const MAGIC = "DUOCP01\n";
    private const CHUNK_BYTES = 1048576;
    private const KEY_CONTEXT = 'duo retained checkpoint v1';

    /** @param resource|null $input */
    public static function seal(string $repo, string $output, $input = null): void {
        self::assertPath($repo, $output);
        $input ??= STDIN;
        if (!is_resource($input)) {
            throw new \InvalidArgumentException('checkpoint plaintext input must be a stream');
        }
        [$key, $keyId] = self::key();
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $temporary = $output . '.tmp-' . bin2hex(random_bytes(8));
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            sodium_memzero($key);
            throw new \RuntimeException('could not create the encrypted checkpoint staging file');
        }
        @chmod($temporary, 0600);
        try {
            self::write($handle, self::MAGIC . $keyId . $header);
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
            if (!fflush($handle)) {
                throw new \RuntimeException('could not flush the encrypted checkpoint');
            }
            fclose($handle);
            $handle = null;
            if (!@rename($temporary, $output)) {
                throw new \RuntimeException('could not publish the encrypted checkpoint atomically');
            }
            @chmod($output, 0600);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($temporary);
            sodium_memzero($key);
        }
    }

    /** @param resource|null $output */
    public static function open(string $repo, string $input, $output = null): void {
        self::assertPath($repo, $input);
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
        try {
            if (!flock($handle, LOCK_SH)) {
                throw new \RuntimeException('could not lock the encrypted checkpoint for authenticated restore');
            }
            // The first pass authenticates the COMPLETE ciphertext before a
            // single SQL byte reaches the importer. The second pass uses the
            // same locked inode and is the only one that emits plaintext.
            self::decrypt($handle, $key, $keyId, false, $output);
            rewind($handle);
            self::decrypt($handle, $key, $keyId, true, $output);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
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
        $root = rtrim($repo, '/') . '/.duo/checkpoints/';
        if (!str_starts_with($path, $root)
            || preg_match('/\/(?:promote|deploy|materialize)-[A-Za-z0-9][A-Za-z0-9._-]{0,127}\.sql\.enc$/D', $path) !== 1) {
            throw new \RuntimeException('checkpoint path is outside the encrypted retained-checkpoint boundary');
        }
    }

    /** @param resource $handle @param resource $output */
    private static function decrypt($handle, string $key, string $keyId, bool $emit, $output): void {
        $prefixBytes = strlen(self::MAGIC) + 16;
        $prefix = self::readExact($handle, $prefixBytes);
        if (substr($prefix, 0, strlen(self::MAGIC)) !== self::MAGIC) {
            throw new \RuntimeException('the retained checkpoint is not a Duo encrypted checkpoint');
        }
        if (!hash_equals($keyId, substr($prefix, strlen(self::MAGIC)))) {
            throw new \RuntimeException('the retained checkpoint belongs to different target key material');
        }
        $header = self::readExact(
            $handle,
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES
        );
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        $sawFinal = false;
        while (!feof($handle)) {
            $lengthBytes = fread($handle, 4);
            if ($lengthBytes === false || $lengthBytes === '') {
                break;
            }
            if (strlen($lengthBytes) !== 4) {
                throw new \RuntimeException('the encrypted checkpoint record length is truncated');
            }
            $length = unpack('Nlength', $lengthBytes)['length'];
            $maximum = self::CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
            if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $length > $maximum) {
                throw new \RuntimeException('the encrypted checkpoint record length is invalid');
            }
            $opened = sodium_crypto_secretstream_xchacha20poly1305_pull(
                $state,
                self::readExact($handle, $length)
            );
            if ($opened === false) {
                throw new \RuntimeException('the encrypted checkpoint failed authentication');
            }
            [$plain, $tag] = $opened;
            if ($sawFinal || ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
                && $tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL)) {
                throw new \RuntimeException('the encrypted checkpoint record sequence is invalid');
            }
            if ($emit) {
                self::write($output, $plain);
            }
            $sawFinal = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
        }
        if (!$sawFinal) {
            throw new \RuntimeException('the encrypted checkpoint has no authenticated final record');
        }
    }

    /** @param resource $handle */
    private static function readExact($handle, int $length): string {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $part = fread($handle, $length - strlen($bytes));
            if ($part === false || $part === '') {
                throw new \RuntimeException('the encrypted checkpoint is truncated');
            }
            $bytes .= $part;
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

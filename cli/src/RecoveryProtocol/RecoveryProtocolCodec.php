<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/**
 * Host-side byte contract for the standalone recovery runtime.
 *
 * This intentionally duplicates no recovery behavior: it owns only the wire
 * constants, canonical JSON profile, and Ed25519 envelope needed by the host
 * client. Golden fixtures compare it to archived recovery-runtime bytes.
 */
final class RecoveryProtocolCodec {
    public const RECEIPT_FORMAT = 'duo-rollback-receipt/v2';
    public const SCOPED_PROMOTION_RECEIPT_FORMAT = 'duo-scoped-promotion-receipt/v1';
    public const EVENT_FORMAT = 'duo-rollback-event/v1';

    /** @param array<string,mixed> $value */
    public static function canonical(array $value): string {
        try {
            return (string) json_encode(
                self::normalize($value),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            throw new \RuntimeException('duo recovery protocol: canonical JSON encoding failed', 0, $e);
        }
    }

    /** @return array{key_id:string,payload:array<string,mixed>,signature:string} */
    public static function sign(array $payload, string $keyId, string $secretKey): array {
        if (strlen($keyId) < 1 || strlen($keyId) > 64
            || preg_match('/^[A-Za-z0-9._-]+$/D', $keyId) !== 1) {
            throw new \RuntimeException('duo recovery protocol: signing key id is malformed');
        }
        if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('duo recovery protocol: signing secret has the wrong Ed25519 byte length');
        }
        return [
            'key_id' => $keyId,
            'payload' => $payload,
            'signature' => base64_encode(sodium_crypto_sign_detached(self::canonical($payload), $secretKey)),
        ];
    }

    private static function normalize(mixed $value): mixed {
        if (!is_array($value)) {
            if (is_float($value) || is_resource($value) || is_object($value)) {
                throw new \RuntimeException('duo recovery protocol: canonical JSON contains unsupported value');
            }
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(static fn(mixed $item): mixed => self::normalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \RuntimeException('duo recovery protocol: canonical JSON object keys must be strings');
            }
            $value[$key] = self::normalize($item);
        }
        return $value;
    }
}

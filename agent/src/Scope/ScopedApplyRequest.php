<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/../Kernel/Canon.php';

/**
 * A caller's stable retry identity, independent of desired content and lease.
 * Content can return A→B→A; neither an artifact hash nor a freshly randomized
 * retry can identify that second A safely. Only hashes enter durable authority.
 */
final class ScopedApplyRequest {
    public const BINDING_FORMAT = 'wprism-scoped-apply-request-binding/v1';

    public static function validate_id(mixed $id): string {
        if (!is_string($id) || preg_match('/\A[A-Za-z0-9._:-]{8,128}\z/', $id) !== 1) {
            throw new \InvalidArgumentException('wprism: scoped apply request ID must be 8..128 ASCII letters, digits, dots, underscores, colons, or hyphens');
        }
        return $id;
    }

    /** @return array<string,mixed> */
    public static function binding(mixed $id, bool $allowDeletes): array {
        $binding = [
            'allow_deletes' => $allowDeletes,
            'format' => self::BINDING_FORMAT,
            'request_id_hash' => hash('sha256', Canon::encode([
                'format' => 'wprism-scoped-apply-request-id/v1',
                'request_id' => self::validate_id($id),
            ])),
        ];
        $binding['binding_hash'] = hash('sha256', Canon::encode($binding));
        return $binding;
    }

    /** @return array<string,mixed> */
    public static function validate_binding(mixed $binding): array {
        $keys = is_array($binding) ? array_keys($binding) : [];
        sort($keys, SORT_STRING);
        if ($keys !== ['allow_deletes', 'binding_hash', 'format', 'request_id_hash']
            || $binding['format'] !== self::BINDING_FORMAT
            || !is_bool($binding['allow_deletes'])) {
            throw new \RuntimeException('wprism: scoped apply request binding has an unexpected schema');
        }
        foreach (['binding_hash', 'request_id_hash'] as $key) {
            if (!is_string($binding[$key]) || preg_match('/\A[a-f0-9]{64}\z/', $binding[$key]) !== 1) {
                throw new \RuntimeException('wprism: scoped apply request binding contains a malformed hash');
            }
        }
        $withoutHash = $binding;
        unset($withoutHash['binding_hash']);
        if (!hash_equals(hash('sha256', Canon::encode($withoutHash)), $binding['binding_hash'])) {
            throw new \RuntimeException('wprism: scoped apply request binding hash does not verify');
        }
        return $binding;
    }
}

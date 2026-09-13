<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/Canon.php';

/** Opaque target-input evidence, separate from portable authored content. */
final class InputBindingWitness {
    public static function assert_authority(mixed $value): void {
        self::assert_shape($value, ['before_hash', 'intent_hash']);
    }

    public static function assert_observation(mixed $value): void {
        self::assert_shape($value, ['available', 'intent_hash', 'observed_hash']);
    }

    private static function assert_shape(mixed $value, array $expected): void {
        if (!is_array($value)) throw new \RuntimeException('wprism: scoped input witness must be an object');
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== $expected) throw new \RuntimeException('wprism: scoped input witness has an invalid shape');
        foreach ($value as $key => $item) {
            if ($key === 'available' ? !is_bool($item) : (!is_string($item) || preg_match('/^[a-f0-9]{64}$/D', $item) !== 1)) {
                throw new \RuntimeException('wprism: scoped input witness has an invalid value');
            }
        }
    }

    public static function authority(array $observation): array {
        self::assert_observation($observation);
        return ['intent_hash' => $observation['intent_hash'], 'before_hash' => $observation['observed_hash']];
    }

    public static function state(?array $observation, ?array $authority): string {
        if ($observation === null && $authority === null) return 'desired';
        self::assert_observation($observation);
        self::assert_authority($authority);
        if (!$observation['available'] || !hash_equals($authority['intent_hash'], $observation['intent_hash'])) return 'mixed';
        if (hash_equals($authority['intent_hash'], $observation['observed_hash'])) return 'desired';
        return hash_equals($authority['before_hash'], $observation['observed_hash']) ? 'before' : 'mixed';
    }

    public static function matches_before(?array $observation, ?array $authority): bool {
        if ($observation === null && $authority === null) return true;
        self::assert_observation($observation);
        self::assert_authority($authority);
        return $observation['available'] && hash_equals($authority['intent_hash'], $observation['intent_hash'])
            && hash_equals($authority['before_hash'], $observation['observed_hash']);
    }

    /** No-input requests keep their existing receipt bytes. */
    public static function bind_readback(string $mapHash, ?array $observation): string {
        if ($observation === null) return $mapHash;
        self::assert_observation($observation);
        if (!$observation['available'] || !hash_equals($observation['intent_hash'], $observation['observed_hash'])) {
            throw new \RuntimeException('wprism: scoped input bindings have not reached their intended native values');
        }
        return hash('sha256', Canon::encode(['ledger_map' => $mapHash, 'input_bindings' => $observation['intent_hash']]));
    }
}

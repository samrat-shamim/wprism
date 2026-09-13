<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/../Kernel/BlockContentGrammar.php';
require_once __DIR__ . '/../Kernel/WordPressOptionValueCodec.php';
require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/CacheInvalidationTransaction.php';
require_once __DIR__ . '/EnvironmentValues.php';
require_once __DIR__ . '/../Policy/Policy.php';

/** Required block bindings reuse env-set intent and the existing exact option-row lock boundary. */
final class BlockEnvironmentOptions {
    public static function lock(Policy $policy, string $repo): array {
        $names = [];
        foreach (BlockContentGrammar::project($policy->manifests, $policy->site['policy'] ?? []) as $rule) {
            foreach ($rule['env_options'] as $name) $names[$name] = true;
        }
        if ($names === []) return [];
        ksort($names, SORT_STRING);
        $expected = EnvironmentValues::read($repo);
        $out = [];
        // Sorted locks precede adoption/materialization; a concurrent editor
        // or env-set cannot rotate a key between the two post write phases.
        foreach ($names as $name => $_) {
            $row = CacheInvalidationTransaction::lock_option_row($name, 'block environment binding');
            $value = $expected[$name] ?? null;
            if (!is_string($value) || $value === '' || $row === null
                || !hash_equals(WordPressOptionValueCodec::encode_scalar_string($value), $row['option_value'])) {
                throw CommandRefusalException::applyRefused(
                    'block environment binding is missing or differs from target-local intent',
                    'provision each required block environment option with wp wprism env-set --stdin, then retry apply',
                    'wprism: block environment binding is missing or differs from target-local intent'
                );
            }
            $out[$name] = $value;
        }
        return $out;
    }
}

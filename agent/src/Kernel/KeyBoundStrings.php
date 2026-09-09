<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/JsonRefs.php';
require_once __DIR__ . '/IdentityTokenCodec.php';

/** Declared literal frames duplicate their owning map key, never a second identity. */
final class KeyBoundStrings {
    public const FEATURE = 'key-bound-strings/v1';
    public const FIELD = 'bound_strings';
    public const MAX_RULES = 128;
    public const MAX_FRAME_BYTES = 256;
    public const MAX_STRING_BYTES = 1048576;
    public const MAX_OCCURRENCES = 100000;

    public static function declaration_grammar(): array {
        return [
            'field' => 'key_refs.' . self::FIELD, 'surfaces' => ['options', 'option_patterns'],
            'class' => 'authored', 'static_only' => true, 'container' => 'php',
            'required' => ['path', 'prefix', 'suffix'],
            'path' => 'relative to each map entry; existing structured-reference path dialect',
            'identity' => 'every literal frame must contain exactly the owning key',
            'absence' => 'an absent path is allowed; a matched value requires at least one complete frame',
            'max_rules' => self::MAX_RULES, 'max_frame_bytes' => self::MAX_FRAME_BYTES,
            'max_string_bytes' => self::MAX_STRING_BYTES, 'max_occurrences_per_entry' => self::MAX_OCCURRENCES,
        ];
    }

    public static function assert_rule(array $rule, string $where, bool $allowed): void {
        $keys = $rule['key_refs'] ?? null;
        if (!is_array($keys) || !array_key_exists(self::FIELD, $keys)) return;
        if (!$allowed || ($rule['class'] ?? null) !== 'authored'
            || ($rule['php_containers'] ?? null) !== true || ($keys['container'] ?? null) !== 'php') {
            self::refuse($where, 'requires a static whole PHP-container option in a v3 adapter declaring ' . self::FEATURE);
        }
        self::declarations($keys, $where);
    }

    /** Validate all entries before lookup can drop a dangling owning key. */
    public static function assert_map(array $map, array $rule, string $where): void {
        foreach ($map as $key => $entry) self::rewrite_entry($entry, $key, $key, $rule, $where);
    }

    /** Both phases share exact framing; literals outside each frame retain their bytes. */
    public static function rewrite_entry(mixed $entry, int|string $oldKey, int|string $newKey, array $rule, string $where): mixed {
        $old = self::identity($oldKey, $rule['kind'], $where);
        $new = self::identity($newKey, $rule['kind'], $where);
        $occurrences = 0;
        foreach (self::declarations($rule, $where) as $declaration) {
            JsonRefs::walk_atomic($entry, JsonRefs::parse_path($declaration['path']),
                static function (&$container, $key, string $locator) use ($declaration, $old, $new, $where, &$occurrences): void {
                    $value = $container[$key];
                    if (!is_string($value) || strlen($value) > self::MAX_STRING_BYTES || preg_match('//u', $value) !== 1) {
                        self::refuse($where . $locator, 'requires a bounded UTF-8 string');
                    }
                    $prefix = $declaration['prefix'];
                    $before = $prefix . $old . $declaration['suffix'];
                    $after = $prefix . $new . $declaration['suffix'];
                    $offset = 0;
                    $output = '';
                    $matched = false;
                    while (($position = strpos($value, $prefix, $offset)) !== false) {
                        // Matching the complete frame avoids treating a suffix inside
                        // a UUID as the delimiter or accepting 13 as a prefix of 130.
                        if (substr($value, $position, strlen($before)) !== $before) {
                            self::refuse($where . $locator, 'contains a frame that disagrees with its owning map key');
                        }
                        if (++$occurrences > self::MAX_OCCURRENCES) self::refuse($where, 'exceeds the entry occurrence bound');
                        $output .= substr($value, $offset, $position - $offset) . $after;
                        if (strlen($output) > self::MAX_STRING_BYTES) self::refuse($where . $locator, 'rewritten string exceeds its byte bound');
                        $offset = $position + strlen($before);
                        $matched = true;
                    }
                    if (!$matched) self::refuse($where . $locator, 'contains no owning-key frame');
                    if (strlen($output) + strlen($value) - $offset > self::MAX_STRING_BYTES) {
                        self::refuse($where . $locator, 'rewritten string exceeds its byte bound');
                    }
                    $container[$key] = $output . substr($value, $offset);
                }, '');
        }
        return $entry;
    }

    private static function declarations(array $rule, string $where): array {
        $rules = $rule[self::FIELD] ?? null;
        if (!is_array($rules) || !array_is_list($rules) || count($rules) < 1 || count($rules) > self::MAX_RULES) {
            self::refuse($where, 'requires one to ' . self::MAX_RULES . ' string declarations');
        }
        foreach ($rules as $item) {
            if (!is_array($item) || count($item) !== 3 || array_diff_key($item, ['path' => true, 'prefix' => true, 'suffix' => true])
                || !is_string($item['path'] ?? null) || strlen($item['path']) > 1024 || trim($item['path']) !== $item['path']) {
                self::refuse($where, 'requires closed {path, prefix, suffix} declarations');
            }
            JsonRefs::parse_path($item['path']);
            foreach (['prefix', 'suffix'] as $field) {
                $literal = $item[$field] ?? null;
                if (!is_string($literal) || $literal === '' || strlen($literal) > self::MAX_FRAME_BYTES
                    || preg_match('//u', $literal) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $literal) === 1
                    || str_contains($literal, '{{') || str_contains($literal, '}}')) {
                    self::refuse($where, 'requires nonempty bounded literal frames without control bytes or token delimiters');
                }
            }
        }
        return $rules;
    }

    private static function identity(int|string $key, string $kind, string $where): string {
        if (is_int($key) && $key > 0) return (string) $key;
        if (is_string($key)) {
            if (preg_match('/^[1-9][0-9]*$/D', $key) === 1 && (string) (int) $key === $key) return $key;
            try {
                $identity = IdentityTokenCodec::decode($key);
                if ($identity['kind'] === $kind && IdentityTokenCodec::encode($kind, $identity['uuid']) === $key) return $key;
            } catch (\RuntimeException) {}
        }
        self::refuse($where, 'requires an owning positive integer or exact token in its declared keyspace');
    }

    private static function refuse(string $where, string $reason): never {
        throw new \RuntimeException("wprism: key-bound strings $where $reason");
    }
}

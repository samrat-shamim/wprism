<?php
declare(strict_types=1);

namespace WPrism;

/** Native scalar framing is explicit; canonical text uses the ordinary URL, reference and privacy machinery. */
final class EncodedText {
    public const FEATURE = 'encoded-text-values/v1';
    public const FIELD = 'text_encoding';
    public const MAX_BYTES = 1048576;
    public const MAX_WIRE_BYTES = 8388608;
    public const MAX_LITERAL_BYTES = 128;

    public static function declaration_grammar(): array {
        return [
            'field' => self::FIELD, 'class' => 'authored', 'codec' => 'uri-component',
            'escape' => 'optional closed {text, wire} literal replacement before URI encoding',
            'surfaces' => ['options', 'option_patterns', 'static option sub_keys', 'post_meta', 'term_meta', 'user_meta', 'metadata patterns', 'block_values'],
            'canonical' => 'decoded UTF-8 string without unsafe control bytes; one-to-one native round trip required',
            'native' => 'canonical JavaScript encodeURIComponent spelling, after the declared literal escape',
            'authority' => 'static v3 manifest declaring this feature; no interpreter or authored site override',
            'max_bytes' => self::MAX_BYTES, 'max_wire_bytes' => self::MAX_WIRE_BYTES,
            'max_literal_bytes' => self::MAX_LITERAL_BYTES,
        ];
    }

    public static function uses(array $rule): bool {
        if (array_key_exists(self::FIELD, $rule)) return true;
        foreach ((array) ($rule['sub_keys'] ?? []) as $child) {
            if (is_array($child) && self::uses($child)) return true;
        }
        return false;
    }

    public static function assert_rule(array $rule, string $where, bool $allowed): void {
        if (!array_key_exists(self::FIELD, $rule)) return;
        if (!$allowed) self::refuse($where, 'requires a static v3 manifest declaring ' . self::FEATURE);
        if (($rule['class'] ?? null) !== 'authored') self::refuse($where, 'requires class authored');
        foreach (['ref', 'cast', 'json_refs', 'key_refs', 'json_encoded', 'plain_data', 'php_containers', 'record_fields',
            'sub_keys', 'repeated_rows', 'order_preserving', 'native_value_validation'] as $field) {
            if (array_key_exists($field, $rule)) self::refuse($where, "cannot combine with $field");
        }
        $profile = $rule[self::FIELD];
        if (!is_array($profile) || array_is_list($profile) || ($profile['codec'] ?? null) !== 'uri-component'
            || array_diff_key($profile, ['codec' => true, 'escape' => true])) {
            self::refuse($where, 'requires a closed uri-component codec declaration');
        }
        if (!array_key_exists('escape', $profile)) return;
        $escape = $profile['escape'];
        if (!is_array($escape) || count($escape) !== 2 || array_diff_key($escape, ['text' => true, 'wire' => true])
            || !is_string($escape['text'] ?? null) || !is_string($escape['wire'] ?? null)
            || $escape['text'] === '' || strlen($escape['text']) > self::MAX_LITERAL_BYTES
            || preg_match('//u', $escape['text']) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $escape['text']) === 1
            || strlen($escape['wire']) <= strlen($escape['text']) || strlen($escape['wire']) > self::MAX_LITERAL_BYTES
            || preg_match('/^[A-Za-z0-9_]+$/D', $escape['wire']) !== 1
            || str_contains($escape['wire'], $escape['text'])) {
            self::refuse($where, 'requires a bounded literal escape with a longer, disjoint ASCII wire marker');
        }
    }

    /** A policy exclusion is explicit; replacing a codec with authored raw text would reinterpret existing canonical bytes. */
    public static function assert_site_override(array $declared, array $override, string $where): void {
        if (!self::uses($declared)) return;
        if (in_array($override['class'] ?? null, ['runtime', 'derived', 'env'], true)
            && !self::uses($override) && !array_key_exists('sub_keys', $override)) return;
        self::refuse($where, 'cannot replace a manifest text codec with authored site policy; exclude the whole value or retain its manifest contract');
    }

    public static function decode_if_declared(mixed $value, array $rule, string $where): mixed {
        return array_key_exists(self::FIELD, $rule) ? self::decode($value, $rule, $where) : $value;
    }

    /** Bounds precede expansion and diagnostics never reproduce potentially private native text. */
    public static function decode(mixed $value, array $rule, string $where): string {
        if (!array_key_exists(self::FIELD, $rule)) self::refuse($where, 'requires a declared codec');
        self::assert_rule($rule, $where, true);
        if (!is_string($value) || strlen($value) > self::MAX_WIRE_BYTES) self::refuse($where, 'requires bounded native encoded text');
        $plain = rawurldecode($value);
        if (isset($rule[self::FIELD]['escape'])) {
            $escape = $rule[self::FIELD]['escape'];
            $plain = str_replace($escape['wire'], $escape['text'], $plain);
        }
        if (self::encode($plain, $rule, $where) !== $value) self::refuse($where, 'requires canonical native URI-component spelling');
        return $plain;
    }

    public static function assert_canonical(mixed $value, array $rule, string $where): void {
        if (!array_key_exists(self::FIELD, $rule)) return;
        self::prepare($value, $rule, $where);
    }

    public static function encode(mixed $value, array $rule, string $where): string {
        if (!array_key_exists(self::FIELD, $rule)) self::refuse($where, 'requires a declared codec');
        $plain = self::prepare($value, $rule, $where);
        return strtr(rawurlencode($plain), ['%21' => '!', '%27' => "'", '%28' => '(', '%29' => ')', '%2A' => '*']);
    }

    /** Compilation proves the same expansion bound as Apply, before target-specific URL rebinding. */
    private static function prepare(mixed $value, array $rule, string $where): string {
        self::assert_rule($rule, $where, true);
        if (!is_string($value) || strlen($value) > self::MAX_BYTES || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) === 1) {
            self::refuse($where, 'requires bounded decoded UTF-8 text without unsafe control bytes');
        }
        if (isset($rule[self::FIELD]['escape']) && str_contains($value, $rule[self::FIELD]['escape']['wire'])) {
            self::refuse($where, 'decoded text collides with its native literal escape marker');
        }
        $plain = self::escape($value, $rule, $where);
        // encodeURIComponent leaves !'()* unescaped in addition to RFC 3986's
        // unreserved set. Count byte expansion before allocating its output.
        $escapedBytes = strlen((string) preg_replace('/[A-Za-z0-9_.!~*\x27()-]/', '', $plain));
        if (strlen($plain) + 2 * $escapedBytes > self::MAX_WIRE_BYTES) self::refuse($where, 'URI encoding exceeds the native byte bound');
        return $plain;
    }

    /** A partial marker next to escaped text can collide even when the input contains no complete marker. */
    private static function escape(string $plain, array $rule, string $where): string {
        if (isset($rule[self::FIELD]['escape'])) {
            $escape = $rule[self::FIELD]['escape'];
            $size = strlen($plain) + substr_count($plain, $escape['text']) * (strlen($escape['wire']) - strlen($escape['text']));
            if ($size > self::MAX_WIRE_BYTES) self::refuse($where, 'literal escaping exceeds the native byte bound');
            $encoded = str_replace($escape['text'], $escape['wire'], $plain);
            if (str_replace($escape['wire'], $escape['text'], $encoded) !== $plain) self::refuse($where, 'literal escape boundaries do not round trip');
            return $encoded;
        }
        return $plain;
    }

    private static function refuse(string $where, string $why): never {
        throw new \RuntimeException("wprism: $where text encoding $why");
    }
}

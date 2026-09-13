<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/IdentityTokenCodec.php';

/** Opaque field references and authored literals have different transport and privacy semantics. */
final class FieldTemplateMap {
    public const FIELD = 'field_templates';
    public const FEATURE = 'column-field-templates/v1';
    public const FORMATS = ['brace', 'brace_enabled'];
    public const MAX_BYTES = 1048576;
    public const MAX_FRAGMENTS = 16384;

    public static function assert_rule(array $rule, string $where, bool $negotiated = false): void {
        if (!array_key_exists(self::FIELD, $rule)) return;
        if (!$negotiated) throw new \RuntimeException("wprism: $where.field_templates requires negotiated column field templates");
        if (count($rule) !== 2 || ($rule['class'] ?? null) !== 'authored'
            || !in_array($rule[self::FIELD] ?? null, self::FORMATS, true)) {
            throw new \RuntimeException("wprism: $where.field_templates requires authored brace or brace_enabled and no other codec");
        }
    }

    public static function assert_value(mixed $value, string $format, bool $canonical, int &$budget, string $where): void {
        if (!in_array($format, self::FORMATS, true) || !is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \RuntimeException("wprism: $where requires a field-template map");
        }
        foreach ($value as $field => $definition) {
            if (!is_string($field) || $field === '') throw new \RuntimeException("wprism: $where field-template keys require nonempty strings");
            if ($format === 'brace_enabled') {
                if (!is_array($definition) || !array_is_list($definition) || count($definition) !== 2
                    || !in_array($definition[1], [0, 1], true)) {
                    throw new \RuntimeException("wprism: $where field templates require [expression, integer 0 or 1]");
                }
                $definition = $definition[0];
            }
            if ($canonical) self::assert_fragments($definition, true, $budget, $where);
            elseif (is_string($definition)) self::parse($definition, $budget, $where);
            else throw new \RuntimeException("wprism: $where native field templates require strings");
        }
    }

    /**
     * The brace dialect is exactly /\{([^}]+)\}/m: nested opening braces are
     * header bytes, empty/unclosed braces are literal, and no escape is implied.
     * Streaming discovery bounds allocations before building the fragment list.
     */
    public static function parse(string $text, int &$budget, string $where): array {
        self::assert_length(strlen($text), $where);
        $parts = [];
        $offset = 0;
        $search = 0;
        while (($open = strpos($text, '{', $search)) !== false) {
            $close = strpos($text, '}', $open + 1);
            if ($close === false) break;
            if ($close === $open + 1) {
                $search = $close + 1;
                continue;
            }
            if ($open > $offset) self::append($parts, 'text', substr($text, $offset, $open - $offset), $budget, $where);
            self::append($parts, 'field', substr($text, $open + 1, $close - $open - 1), $budget, $where);
            $offset = $search = $close + 1;
        }
        if ($offset < strlen($text)) self::append($parts, 'text', substr($text, $offset), $budget, $where);
        return $parts;
    }

    /** Caller validates the complete root value before transformation. Only literal text reaches Tokens. */
    public static function transform(array $value, string $format, bool $capture, callable $text, string $where): array {
        $budget = 0;
        foreach ($value as &$definition) {
            $expression = $format === 'brace_enabled' ? $definition[0] : $definition;
            $parts = $capture ? self::parse($expression, $budget, $where) : $expression;
            foreach ($parts as &$part) {
                if (isset($part['text'])) $part['text'] = $text($part['text']);
            }
            unset($part);
            $checkBudget = 0;
            self::assert_fragments($parts, $capture, $checkBudget, $where);
            $result = $capture ? $parts : self::render($parts);
            if ($format === 'brace_enabled') $definition[0] = $result;
            else $definition = $result;
        }
        unset($definition);
        return $value;
    }

    /**
     * Retain every stored scalar byte for value-based detectors. Only joined
     * literals inherit destination roles; joining closes split-literal evasion,
     * and disabled definitions retain the same clearance as enabled ones.
     */
    public static function sensitivity_subject(array $value, string $format, bool $canonical, string $where): array {
        $subject = [];
        $budget = 0;
        foreach ($value as $field => $definition) {
            $expression = $format === 'brace_enabled' ? $definition[0] : $definition;
            $parts = $canonical ? $expression : self::parse($expression, $budget, $where);
            $metadata = [$field, self::render($parts)];
            if ($format === 'brace_enabled') $metadata[] = $definition[1];
            $literals = implode('', array_column($parts, 'text'));
            $subject[] = $literals === '' ? $metadata : [$metadata, [$field => $literals]];
        }
        return $subject;
    }

    /** Opaque header identities must not be linted as environment URLs. */
    public static function text_subject(array $value, string $format): array {
        $subject = [];
        foreach ($value as $definition) {
            $parts = $format === 'brace_enabled' ? $definition[0] : $definition;
            $subject[] = array_column($parts, 'text');
        }
        return $subject;
    }

    private static function assert_fragments(mixed $parts, bool $canonical, int &$budget, string $where): void {
        if (!is_array($parts) || !array_is_list($parts)) throw new \RuntimeException("wprism: $where requires normalized field-template fragments");
        $masked = [];
        $length = 0;
        foreach ($parts as $part) {
            if (!is_array($part) || count($part) !== 1 || !in_array(array_key_first($part), ['text', 'field'], true)
                || !is_string(current($part)) || current($part) === '') {
                throw new \RuntimeException("wprism: $where requires exact nonempty text or field fragments");
            }
            if (++$budget > self::MAX_FRAGMENTS) throw new \RuntimeException("wprism: $where exceeds the field-template fragment budget");
            $key = array_key_first($part);
            $length += strlen($part[$key]) + ($key === 'field' ? 2 : 0);
            self::assert_length($length, $where);
            $masked[] = [$key => $canonical && $key === 'text' ? self::mask_tokens($part[$key]) : $part[$key]];
        }
        // WPrism tokens overlap the native brace grammar. Mask only recognized
        // text tokens for this proof; after target expansion even those masks
        // disappear, so a target URL cannot introduce new field references.
        $checkBudget = 0;
        if (self::parse(self::render($masked), $checkBudget, $where) !== $masked) {
            throw new \RuntimeException("wprism: $where requires unambiguous normalized field-template fragments");
        }
    }

    private static function mask_tokens(string $text): string {
        return preg_replace_callback('/\{\{[^{}]*\}\}/', static function (array $match): string {
            $token = $match[0];
            $recognized = in_array($token, ['{{home}}', '{{uploads}}'], true);
            if (!$recognized) {
                try {
                    $identity = IdentityTokenCodec::decode($token);
                    $recognized = IdentityTokenCodec::encode($identity['kind'], $identity['uuid']) === $token;
                } catch (\RuntimeException) {}
            }
            return $recognized ? str_repeat('x', strlen($token)) : $token;
        }, $text);
    }

    private static function render(array $parts): string {
        $text = '';
        foreach ($parts as $part) $text .= isset($part['field']) ? '{' . $part['field'] . '}' : $part['text'];
        return $text;
    }

    private static function append(array &$parts, string $key, string $value, int &$budget, string $where): void {
        if (++$budget > self::MAX_FRAGMENTS) throw new \RuntimeException("wprism: $where exceeds the field-template fragment budget");
        $parts[] = [$key => $value];
    }

    private static function assert_length(int $length, string $where): void {
        if ($length > self::MAX_BYTES) throw new \RuntimeException("wprism: $where exceeds the field-template expression byte budget");
    }
}

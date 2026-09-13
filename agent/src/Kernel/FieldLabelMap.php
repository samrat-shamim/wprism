<?php
declare(strict_types=1);

namespace WPrism;

/** Exact field-definition metadata; privacy role projection never removes stored scalar bytes. */
final class FieldLabelMap {
    public const FIELD = 'field_labels';
    public const FORMATS = ['label', 'label_enabled'];

    public static function assert_rule(array $rule, string $where, bool $negotiated = false): void {
        if (!array_key_exists(self::FIELD, $rule)) return;
        if (!$negotiated) {
            throw new \RuntimeException("wprism: $where.field_labels requires negotiated column field labels");
        }
        if (count($rule) !== 2 || ($rule['class'] ?? null) !== 'authored'
            || !in_array($rule[self::FIELD], self::FORMATS, true)) {
            throw new \RuntimeException("wprism: $where.field_labels requires authored label or label_enabled and no other codec");
        }
    }

    public static function assert_value(mixed $value, string $format, string $where): void {
        if (!in_array($format, self::FORMATS, true) || !is_array($value)
            || ($value !== [] && array_is_list($value))) {
            throw new \RuntimeException("wprism: $where requires a field-label map");
        }
        foreach ($value as $field => $label) {
            if (!is_string($field) || $field === '') {
                throw new \RuntimeException("wprism: $where field-label keys require nonempty strings");
            }
            if ($format === 'label_enabled') {
                if (!is_array($label) || !array_is_list($label) || count($label) !== 2
                    || !in_array($label[1], [0, 1], true)) {
                    throw new \RuntimeException("wprism: $where field labels require [label, integer 0 or 1]");
                }
                $label = $label[0];
            }
            if (!is_string($label)) {
                throw new \RuntimeException("wprism: $where field labels require strings");
            }
        }
    }

    /**
     * user_email => [label, enabled] is a field definition, not an email
     * value. Keep its key bytes as a scalar subject alongside every value;
     * the caller retains enclosing roles and scans original bytes for secrets.
     */
    public static function pii_subject(array $value): array {
        $subject = [];
        foreach ($value as $field => $label) $subject[] = [$field, $label];
        return $subject;
    }
}

<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/StructuredReferenceScanner.php';
require_once __DIR__ . '/../Kernel/ValueShapeContract.php';

/** Shared declared-value traversal; surface callers own framing and invalid-value findings. */
final class AuthoredValueReferenceScanner {
    /** Nesting changes ownership paths, not a leaf codec's existing lint contract. */
    public static function scan($value, array $rule, string $rel, string $locator, callable $resolveId): array {
        if (isset($rule[ValueShapeContract::ALTERNATIVES_FIELD])) {
            return self::scan($value, ValueShapeContract::select($value, $rule, $locator), $rel, $locator, $resolveId);
        }
        if (isset($rule['object_fields'])) {
            $findings = [];
            if (is_array($value)) foreach ($value as $field => $child) {
                if (isset($rule['object_fields'][$field])) {
                    array_push($findings, ...self::scan($child, $rule['object_fields'][$field], $rel, "$locator.$field", $resolveId));
                }
            }
            return $findings;
        }
        return isset($rule['json_refs']) || isset($rule['key_refs'])
            ? StructuredReferenceScanner::scan($value, $rel, $locator, $rule['json_refs'] ?? [], $rule['key_refs'] ?? null, $resolveId) : [];
    }

}

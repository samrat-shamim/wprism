<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/ReferencePath.php';

/** Native response caches must not become authored state merely by sharing a record with an identity. */
final class RecordFields {
    public const FEATURE = 'block-record-fields/v1';
    public const FIELD = 'record_fields';
    public const MAX_FIELDS = 256;

    public static function declaration_grammar(): array {
        return [
            'field' => 'block_values.<block>.<attribute>.record_fields',
            'shape' => 'closed {container: object|list, fields: nonempty distinct exact-name list}',
            'scope' => 'one record or a list of records at the attribute root; no recursive selection',
            'max_fields' => self::MAX_FIELDS,
            'capture' => 'retain only declared immediate fields, preserving native key order, absence, types and list duplicates',
            'canonical' => 'excluded fields refuse; each record must retain at least one field; an empty list is valid',
            'references' => 'compose json_refs or nested key_refs only when each path starts with a retained exact field',
            'authority' => 'v3 manifest declaring block-attribute-values/v1 and block-record-fields/v1',
        ];
    }

    public static function validate(array $rule, string $where): void {
        if (!array_key_exists(self::FIELD, $rule)) return;
        $declaration = $rule[self::FIELD];
        if (!is_array($declaration) || count($declaration) !== 2
            || array_diff_key($declaration, ['container' => true, 'fields' => true])
            || !in_array($declaration['container'] ?? null, ['object', 'list'], true)
            || !is_array($declaration['fields'] ?? null) || !array_is_list($declaration['fields'])
            || $declaration['fields'] === [] || count($declaration['fields']) > self::MAX_FIELDS) {
            throw new \RuntimeException("wprism: $where.record_fields requires a bounded closed container and fields declaration");
        }
        $fields = [];
        foreach ($declaration['fields'] as $field) {
            if (!is_string($field) || preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,127}$/D', $field) !== 1 || isset($fields[$field])) {
                throw new \RuntimeException("wprism: $where.record_fields requires distinct exact field names");
            }
            $fields[$field] = true;
        }
        if (isset($rule['ref'])) throw new \RuntimeException("wprism: $where.record_fields cannot project a scalar reference");
        $paths = array_column($rule['json_refs'] ?? [], 'path');
        if (isset($rule['key_refs'])) $paths[] = $rule['key_refs']['path'] ?? '$';
        foreach ($paths as $path) {
            // A retained exact first edge makes every later existing path
            // segment safe. Wild/recursive first edges could own discarded
            // references, and a root key_ref owns keys this projection removes.
            $first = ReferencePath::parse($path)[0] ?? null;
            if (($first['type'] ?? '') !== 'child' || !isset($fields[$first['key']])) {
                throw new \RuntimeException("wprism: $where.record_fields requires references rooted in retained exact fields");
            }
        }
    }

    /** The caller's ordinary JSON bound applies before any native field is discarded. */
    public static function assert_value(mixed $value, array $declaration, bool $canonical, string $where): void {
        if (!is_array($value) || ($declaration['container'] === 'list' && !array_is_list($value))) {
            throw new \RuntimeException("wprism: $where requires its declared record container");
        }
        $fields = array_fill_keys($declaration['fields'], true);
        $records = $declaration['container'] === 'list' ? $value : [$value];
        foreach ($records as $record) {
            // Associative decoding cannot distinguish {} from []. Refuse an
            // empty projected record instead of changing its native JSON type.
            if (!is_array($record) || array_is_list($record) || array_intersect_key($record, $fields) === []) {
                throw new \RuntimeException("wprism: $where requires records containing retained fields");
            }
            if ($canonical && array_diff_key($record, $fields)) {
                throw new \RuntimeException("wprism: $where contains excluded canonical record fields");
            }
        }
    }

    /** Called only after the shared value validator has checked native JSON and container shape. */
    public static function capture(array $value, array $declaration): array {
        $fields = array_fill_keys($declaration['fields'], true);
        if ($declaration['container'] === 'object') return array_intersect_key($value, $fields);
        foreach ($value as &$record) $record = array_intersect_key($record, $fields);
        unset($record);
        return $value;
    }
}

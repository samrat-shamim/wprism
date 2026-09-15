<?php
declare(strict_types=1);

namespace WPrism;

/** Data-only native storage admission; migration execution is a separate authority. */
final class StoragePrerequisiteGrammar {
    public const FEATURE = 'storage-prerequisites/v1';
    public const SECTION = 'storage_prerequisites';
    private const KEYS = ['equals', 'option'];
    private const MAX_ROWS = 16;
    private const MAX_VALUE_BYTES = 256;
    private const OPTION_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9_.:-]{0,190}$/D';

    public static function section_grammar(): array {
        return [
            'shape' => 'nonempty list of exact runtime option/string prerequisites, unique and sorted by option',
            'record' => ['required' => self::KEYS, 'optional' => []],
            'max_items' => self::MAX_ROWS,
            'option_pattern' => self::OPTION_PATTERN,
            'equals' => ['type' => 'nonempty UTF-8 string without control characters', 'max_bytes' => self::MAX_VALUE_BYTES],
            'refines' => 'read-only storage admission; no migration execution or authored surface',
            'validated_by' => self::class . '::validate()',
        ];
    }

    public static function validate(array $manifest): void {
        if (!array_key_exists(self::SECTION, $manifest)) return;
        $where = "manifest '" . (string) ($manifest['name'] ?? '?') . "' storage_prerequisites";
        if (!in_array(self::FEATURE, (array) ($manifest['engine_features'] ?? []), true)) {
            throw new \RuntimeException("wprism: $where requires engine feature '" . self::FEATURE . "'");
        }
        $rows = $manifest[self::SECTION];
        if (!is_array($rows) || !array_is_list($rows) || $rows === [] || count($rows) > self::MAX_ROWS) {
            throw new \RuntimeException("wprism: $where must be a nonempty list of at most 16 exact option prerequisites");
        }
        $previous = null;
        foreach ($rows as $row) {
            $keys = is_array($row) ? array_keys($row) : [];
            sort($keys, SORT_STRING);
            $option = is_array($row) ? ($row['option'] ?? null) : null;
            $equals = is_array($row) ? ($row['equals'] ?? null) : null;
            if ($keys !== self::KEYS
                || !is_string($option) || preg_match(self::OPTION_PATTERN, $option) !== 1
                || !is_string($equals) || $equals === '' || strlen($equals) > self::MAX_VALUE_BYTES
                || preg_match('/[\x00-\x1F\x7F]/', $equals) === 1
                || preg_match('//u', $equals) !== 1) {
                throw new \RuntimeException("wprism: $where requires only a bounded literal option and nonempty string equals");
            }
            if ($previous !== null && strcmp($previous, $option) >= 0) {
                throw new \RuntimeException("wprism: $where must use unique options sorted lexically");
            }
            $rule = $manifest['options'][$option] ?? null;
            if (!is_array($rule) || ($rule['class'] ?? null) !== 'runtime' || array_key_exists('sub_keys', $rule)) {
                throw new \RuntimeException("wprism: $where must name an exact wholly runtime option declared by its own manifest");
            }
            $previous = $option;
        }
    }

    /** @return list<array{manifest:string,option:string,equals:string}> */
    public static function project(array $manifests): array {
        $result = [];
        $seen = [];
        foreach ($manifests as $manifest) {
            self::validate($manifest);
            foreach ($manifest[self::SECTION] ?? [] as $row) {
                $option = $row['option'];
                if (isset($seen[$option]) && $seen[$option] !== $row['equals']) {
                    throw new \RuntimeException('wprism: manifests declare conflicting storage prerequisites for one option');
                }
                $seen[$option] = $row['equals'];
                $result[] = ['manifest' => (string) $manifest['name'], 'option' => $option, 'equals' => $row['equals']];
            }
        }
        return $result;
    }
}

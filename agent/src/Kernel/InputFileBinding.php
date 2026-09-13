<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/PathSafety.php';
require_once __DIR__ . '/Uuid.php';

/** Portable dependency presence; native filenames and file contents stay environment-local. */
final class InputFileBinding {
    public const FIELD = 'input_file';
    public const FEATURE = 'column-input-files/v1';
    public const PREFIX = 'column_file:';
    public const MARKER = ['environment' => 'input_file'];

    public static function assert_rule(array $rule, string $where, bool $negotiated = false): void {
        $spec = $rule[self::FIELD] ?? null;
        if (!$negotiated || count($rule) !== 2 || ($rule['class'] ?? null) !== 'authored'
            || !is_array($spec) || array_is_list($spec)) {
            throw new \RuntimeException("wprism: $where.input_file requires negotiated authored column input files");
        }
        $keys = array_keys($spec);
        sort($keys, SORT_STRING);
        $directory = $spec['directory'] ?? null;
        $extensions = $spec['extensions'] ?? null;
        if ($keys !== ['directory', 'extensions'] || !is_string($directory)
            || strlen($directory) > 256 || !PathSafety::safe_relative($directory)
            || preg_match('/^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+){0,3}$/D', $directory) !== 1
            || !is_array($extensions) || !array_is_list($extensions) || $extensions === [] || count($extensions) > 16) {
            throw new \RuntimeException("wprism: $where.input_file requires a bounded content-relative directory and extension list");
        }
        $previous = '';
        foreach ($extensions as $extension) {
            if (!is_string($extension) || preg_match('/^[a-z0-9]{1,12}$/D', $extension) !== 1
                || strcmp($previous, $extension) >= 0) {
                throw new \RuntimeException("wprism: $where.input_file extensions must be sorted unique lowercase codes");
            }
            $previous = $extension;
        }
    }

    public static function assert_value(mixed $value, bool $canonical, string $where): void {
        if ($value === '') return;
        if ($canonical ? $value !== self::MARKER : !is_string($value) || strlen($value) > 8192) {
            throw new \RuntimeException("wprism: $where requires an input-file dependency or an explicit empty draft");
        }
    }

    /** Capture dependency presence, including restored foreign environments; no source bytes grant target file authority. */
    public static function capture(string $value, array $spec): array|string {
        if ($value === '') return '';
        self::url_prefix($spec, $value);
        $parts = parse_url($value);
        $path = $parts['path'] ?? '';
        $filename = basename($path);
        self::assert_filename($filename, $spec);
        if (!str_ends_with($path, '/' . $spec['directory'] . '/' . $filename)) {
            throw new \RuntimeException('wprism: input file URL does not name its declared directory');
        }
        return self::MARKER;
    }

    public static function native_url(string $filename, array $spec, string $contentUrl): string {
        self::assert_filename($filename, $spec);
        return self::url_prefix($spec, $contentUrl) . $filename;
    }

    public static function assert_filename(string $filename, array $spec): void {
        if (strlen($filename) > 255 || !PathSafety::safe_component($filename)
            || preg_match('/[\s%?#:<>"{}]/u', $filename) !== 0
            || preg_match('//u', $filename) !== 1
            || !in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), $spec['extensions'], true)) {
            throw new \RuntimeException('wprism: input binding requires one literal filename with a declared extension');
        }
    }

    /** Field names exclude dots, so the readable coordinate has one unambiguous spelling. */
    public static function name(string $uuid, string $column, array $path): string {
        if (strlen($uuid) !== 36 || !Uuid::is($uuid) || !array_is_list($path)) {
            throw new \RuntimeException('wprism: input binding requires a canonical UUID and field path');
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $column) !== 1 || count($path) > 4) {
            throw new \RuntimeException('wprism: input binding requires a canonical column coordinate');
        }
        foreach ($path as $field) {
            if (!is_string($field) || preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,127}$/D', $field) !== 1) {
                throw new \RuntimeException('wprism: input binding requires exact object field coordinates');
            }
        }
        return self::PREFIX . $uuid . ':' . implode('.', [$column, ...$path]);
    }

    public static function declared(array $rule): bool {
        if (isset($rule[self::FIELD])) return true;
        foreach ($rule['object_fields'] ?? [] as $child) if (self::declared($child)) return true;
        return false;
    }

    /** Exact declared leaves only; callers own framing and complete canonical validation. */
    public static function bindings(mixed $value, array $rule, array $path = []): array {
        if (isset($rule[self::FIELD])) {
            self::assert_value($value, true, 'column input binding');
            return $value === '' ? [] : [['path' => $path, 'spec' => $rule[self::FIELD]]];
        }
        $out = [];
        foreach ($rule['object_fields'] ?? [] as $field => $child) {
            if (is_array($value) && array_key_exists($field, $value)) {
                array_push($out, ...self::bindings($value[$field], $child, [...$path, $field]));
            }
        }
        return $out;
    }

    private static function url_prefix(array $spec, string $contentUrl): string {
        $parts = parse_url($contentUrl);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || !is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f\\\\{}]/', $contentUrl)) {
            throw new \RuntimeException('wprism: input binding requires an exact native content URL base');
        }
        return rtrim($contentUrl, '/') . '/' . $spec['directory'] . '/';
    }
}

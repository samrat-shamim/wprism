<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/DatabaseExceptions.php';

/**
 * Reuse wpdb's own field-format, charset, length and invalid-text validation
 * before Db renders authority-bound CRUD.
 *
 * wpdb exposes only the combined insert/update methods publicly, and those
 * methods execute immediately through reconnecting query(). The reviewed
 * protected process_fields() stage is the only place that preserves core's
 * validation semantics without surrendering execution to that transport.
 */
final class WpdbFieldCodec {
    /**
     * @return array<string,array{value:mixed,format:string}>
     */
    public static function process(
        object $database,
        string $table,
        array $data,
        mixed $format,
        string $context
    ): array {
        if ($data === []) {
            throw new \InvalidArgumentException("wprism: $context received an empty database field set");
        }
        foreach (array_keys($data) as $column) {
            if (!is_string($column) || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $column) !== 1) {
                throw new \InvalidArgumentException("wprism: $context received an unsafe column identifier");
            }
        }
        if (!method_exists($database, 'process_fields')) {
            throw new DatabaseMutationException(
                $context . ' requires WordPress wpdb field validation'
            );
        }

        $invoke = \Closure::bind(
            function (string $target, array $fields, mixed $formats) use ($database): mixed {
                return $database->process_fields($target, $fields, $formats);
            },
            $database,
            get_class($database)
        );
        if (!$invoke instanceof \Closure) {
            throw new DatabaseMutationException(
                $context . ' could not bind WordPress wpdb field validation'
            );
        }
        try {
            $processed = $invoke($table, $data, $format);
        } catch (\Throwable $failure) {
            throw new DatabaseMutationException(
                $context . ' failed WordPress wpdb field validation',
                $failure
            );
        }
        if (!is_array($processed)
            || array_keys($processed) !== array_keys($data)) {
            throw new DatabaseMutationException(
                $context . ' was refused by WordPress wpdb field validation'
            );
        }

        $out = [];
        foreach ($processed as $column => $field) {
            $value = is_array($field) ? ($field['value'] ?? null) : null;
            $fieldFormat = is_array($field) ? ($field['format'] ?? null) : null;
            if (!is_array($field)
                || !array_key_exists('value', $field)
                || !is_string($fieldFormat)
                || preg_match('/^%(?:d|f|F|s)$/D', $fieldFormat) !== 1
                || ($value !== null && !is_scalar($value))) {
                throw new DatabaseMutationException(
                    $context . ' received malformed WordPress wpdb field validation output'
                );
            }
            $out[$column] = ['value' => $value, 'format' => $fieldFormat];
        }
        return $out;
    }
}

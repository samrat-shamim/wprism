<?php
namespace Duo;

/**
 * Canonical wire format for authored wp_options rows.
 *
 * A bare map cannot distinguish three materially different programs:
 * "this option is not authored", "this authored option has no value intent",
 * "this authored option is intentionally deleted", and "this authored
 * option has a false/null/empty value". The
 * record envelope makes those states explicit and carries the storage fact
 * needed to recreate a row without WordPress choosing autoload implicitly.
 */
final class OptionState {
    public const FORMAT = 'duo-options/v1';
    public const AUTOLOAD_VALUES = ['yes', 'no', 'auto', 'on', 'off', 'auto-on', 'auto-off'];

    /** @return array{format:string,records:array<string,array<string,mixed>>} */
    public static function document(array $records): array {
        ksort($records, SORT_STRING);
        return ['format' => self::FORMAT, 'records' => $records];
    }

    /** @return array<string,array<string,mixed>> */
    public static function records(array $document): array {
        $fields = array_keys($document);
        sort($fields, SORT_STRING);
        if ($fields !== ['format', 'records'] || ($document['format'] ?? null) !== self::FORMAT
            || !is_array($document['records'] ?? null)) {
            throw new \RuntimeException(
                'options/core.json must be a ' . self::FORMAT . ' object with exactly format and records fields'
            );
        }
        $records = $document['records'];
        if ($records !== [] && array_is_list($records)) {
            throw new \RuntimeException('options/core.json records must be an object keyed by canonical option name');
        }
        foreach ($records as $name => $record) {
            self::validate_record((string) $name, $record);
        }
        return $records;
    }

    public static function present($value, string $autoload): array {
        self::validate_autoload($autoload, 'captured option');
        return ['state' => 'present', 'autoload' => $autoload, 'value' => $value];
    }

    public static function absent(): array {
        return ['state' => 'absent'];
    }

    public static function deleted(array $previousPresent): array {
        if (($previousPresent['state'] ?? null) !== 'present') {
            throw new \RuntimeException('duo: an option tombstone requires a prior present record');
        }
        return ['state' => 'deleted', 'expected_hash' => self::record_hash($previousPresent)];
    }

    public static function record_hash(array $record): string {
        return hash('sha256', Canon::encode($record));
    }

    /** Present values only; tombstones are intent, never values. */
    public static function values(array $document): array {
        $out = [];
        foreach (self::records($document) as $name => $record) {
            if ($record['state'] === 'present') {
                $out[$name] = $record['value'];
            }
        }
        return $out;
    }

    /** @return string[] */
    public static function deleted_names(array $document): array {
        $out = [];
        foreach (self::records($document) as $name => $record) {
            if ($record['state'] === 'deleted') {
                $out[] = (string) $name;
            }
        }
        return $out;
    }

    public static function assert_rule_autoload(array $rule, string $autoload, string $context): void {
        self::validate_autoload($autoload, $context);
        $declared = $rule['autoload'] ?? null;
        if ($declared === 'preserve') {
            return;
        }
        if ($declared !== $autoload) {
            throw new \RuntimeException(
                "duo: $context has autoload '$autoload' but policy declares " . var_export($declared, true)
                . " — update the adapter declaration or the source row; storage semantics cannot be guessed"
            );
        }
    }

    private static function validate_record(string $name, $record): void {
        if ($name === '' || !is_array($record)) {
            throw new \RuntimeException('options/core.json has an empty name or non-object option record');
        }
        $state = $record['state'] ?? null;
        if ($state === 'present') {
            $fields = array_keys($record);
            sort($fields, SORT_STRING);
            if ($fields !== ['autoload', 'state', 'value']) {
                throw new \RuntimeException(
                    "option '$name' present record must contain exactly state, autoload, and value"
                );
            }
            self::validate_autoload($record['autoload'], "option '$name'");
            return;
        }
        if ($state === 'absent') {
            if (array_keys($record) !== ['state']) {
                throw new \RuntimeException("option '$name' absent record must contain exactly state");
            }
            return;
        }
        if ($state === 'deleted') {
            $fields = array_keys($record);
            sort($fields, SORT_STRING);
            if ($fields !== ['expected_hash', 'state']
                || !is_string($record['expected_hash'])
                || !preg_match('/^[0-9a-f]{64}$/', $record['expected_hash'])) {
                throw new \RuntimeException(
                    "option '$name' deleted record must contain exactly state and a lowercase sha256 expected_hash"
                );
            }
            return;
        }
        throw new \RuntimeException("option '$name' state must be absent, present, or deleted");
    }

    private static function validate_autoload($autoload, string $context): void {
        if (!is_string($autoload) || !in_array($autoload, self::AUTOLOAD_VALUES, true)) {
            throw new \RuntimeException(
                "duo: $context has unsupported autoload " . var_export($autoload, true)
                . ' (expected ' . implode('|', self::AUTOLOAD_VALUES) . ')'
            );
        }
    }
}

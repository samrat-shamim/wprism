<?php
namespace WPrism;

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
    public const FORMAT = 'wprism-options/v1';
    public const WITNESS_FORMAT = 'wprism-options/v2';
    public const AUTOLOAD_VALUES = ['yes', 'no', 'auto', 'on', 'off', 'auto-on', 'auto-off'];

    /** @return array{format:string,records:array<string,array<string,mixed>>} */
    public static function document(array $records): array {
        // Keep ordinary option documents byte-identical to v1. A global
        // tag-only rewrite would make an old v1 ledger base and a freshly
        // observed target both appear edited during three-way planning.
        // v2 is selected exactly when its new record shape is present.
        $format = self::FORMAT;
        foreach ($records as $record) {
            if (is_array($record) && array_key_exists('classification_witness', $record)) {
                $format = self::WITNESS_FORMAT;
                break;
            }
        }
        ksort($records, SORT_STRING);
        return ['format' => $format, 'records' => $records];
    }

    /** @return array<string,array<string,mixed>> */
    public static function records(array $document): array {
        $fields = array_keys($document);
        sort($fields, SORT_STRING);
        $format = $document['format'] ?? null;
        if ($fields !== ['format', 'records']
            || !in_array($format, [self::FORMAT, self::WITNESS_FORMAT], true)
            || !is_array($document['records'] ?? null)) {
            throw new \RuntimeException(
                'options/core.json must be a ' . self::FORMAT . ' or ' . self::WITNESS_FORMAT
                . ' object with exactly format and records fields'
            );
        }
        $records = $document['records'];
        if ($records !== [] && array_is_list($records)) {
            throw new \RuntimeException('options/core.json records must be an object keyed by canonical option name');
        }
        foreach ($records as $name => $record) {
            self::validate_record((string) $name, $record, (string) $format);
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

    public static function deleted(array $previousPresent, bool $retainClassificationWitness = false): array {
        if (($previousPresent['state'] ?? null) !== 'present') {
            throw new \RuntimeException('wprism: an option tombstone requires a prior present record');
        }
        $out = ['state' => 'deleted', 'expected_hash' => self::record_hash($previousPresent)];
        if ($retainClassificationWitness) {
            // The witness deliberately excludes `state`: it is context for
            // current-policy classification, never a second desired value.
            // validate_record() binds it to expected_hash by reconstructing
            // the exact prior present envelope before any caller may use it.
            $out['classification_witness'] = [
                'autoload' => $previousPresent['autoload'],
                'value' => $previousPresent['value'],
            ];
        }
        return $out;
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

    /**
     * Immutable-revision classification context.
     *
     * Present records contribute their ordinary values. When (and only
     * when) this document contains a self-verifying witness, deleted names
     * are represented as present-in-the-document with null and the
     * witnessed name contributes its retained value. Interpreters may
     * therefore re-derive a shadow-key relationship from a cold checkout
     * without changing legacy/plain-tombstone context or treating a
     * tombstone as desired option data.
     */
    public static function classification_values(array $document): array {
        $out = [];
        $records = self::records($document);
        $hasWitness = false;
        foreach ($records as $record) {
            if (array_key_exists('classification_witness', $record)) {
                $hasWitness = true;
                break;
            }
        }
        foreach ($records as $name => $record) {
            if ($record['state'] === 'present') {
                $out[$name] = $record['value'];
            } elseif ($hasWitness && $record['state'] === 'deleted') {
                $out[$name] = array_key_exists('classification_witness', $record)
                    ? $record['classification_witness']['value']
                    : null;
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
                "wprism: $context has autoload '$autoload' but policy declares " . var_export($declared, true)
                . ' — update the adapter declaration or the source row; storage semantics cannot be guessed'
            );
        }
    }

    private static function validate_record(string $name, $record, string $format): void {
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
            $plain = $fields === ['expected_hash', 'state'];
            $witnessed = $format === self::WITNESS_FORMAT
                && $fields === ['classification_witness', 'expected_hash', 'state'];
            if ((!$plain && !$witnessed)
                || !is_string($record['expected_hash'])
                || !preg_match('/^[0-9a-f]{64}$/', $record['expected_hash'])) {
                throw new \RuntimeException(
                    "option '$name' deleted record must contain state, a lowercase sha256 expected_hash, "
                    . 'and only the optional v2 classification_witness'
                );
            }
            if ($witnessed) {
                $witness = $record['classification_witness'];
                if (!is_array($witness)) {
                    throw new \RuntimeException("option '$name' classification_witness must be an object");
                }
                $witnessFields = array_keys($witness);
                sort($witnessFields, SORT_STRING);
                if ($witnessFields !== ['autoload', 'value']) {
                    throw new \RuntimeException(
                        "option '$name' classification_witness must contain exactly autoload and value"
                    );
                }
                self::validate_autoload($witness['autoload'], "option '$name' classification_witness");
                $prior = self::present($witness['value'], $witness['autoload']);
                if (!hash_equals($record['expected_hash'], self::record_hash($prior))) {
                    throw new \RuntimeException(
                        "option '$name' classification_witness does not match its expected_hash"
                    );
                }
            }
            return;
        }
        throw new \RuntimeException("option '$name' state must be absent, present, or deleted");
    }

    private static function validate_autoload($autoload, string $context): void {
        if (!is_string($autoload) || !in_array($autoload, self::AUTOLOAD_VALUES, true)) {
            throw new \RuntimeException(
                "wprism: $context has unsupported autoload " . var_export($autoload, true)
                . ' (expected ' . implode('|', self::AUTOLOAD_VALUES) . ')'
            );
        }
    }
}

<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/ScalarValueConstraint.php';

require_once __DIR__ . '/ReferenceRules.php';
require_once __DIR__ . '/RecordFields.php';
require_once __DIR__ . '/EncodedText.php';

/** Surface owners negotiate capabilities; this pure grammar owns their shared recursive value shape. */
final class ValueContractGrammar {
    public const MAX_OBJECT_FIELDS = 256;
    public const MAX_OBJECT_DEPTH = 4;
    public const MAX_CONTRACT_RULES = 65536;
    public const MAX_ENUM_VALUES = ScalarValueConstraint::MAX_ENUM_VALUES;
    private int $contractRules = 0;

    public function __construct(
        private readonly bool $contracts,
        private readonly bool $records,
        private readonly bool $encodedText,
        private readonly string $surface,
        private readonly bool $strictReferences = false
    ) {}

    /** Object members reuse the authored leaf grammar; absence never creates a default. */
    public function validate(array $rule, string $where, int $depth = 0): void {
        $contract = array_intersect(array_keys($rule), ReferenceRules::BLOCK_CONTRACT_FIELDS) !== [];
        $negotiated = $this->contracts;
        if (($contract || $depth > 0) && (!$negotiated || $depth > self::MAX_OBJECT_DEPTH
            || ++$this->contractRules > self::MAX_CONTRACT_RULES)) {
            throw new \RuntimeException("wprism: $where requires negotiated bounded {$this->surface} value contracts");
        }
        if (($rule['class'] ?? null) === 'derived') {
            if ($depth > 0) throw new \RuntimeException("wprism: $where object fields require authored value rules");
            if (array_keys($rule) !== ['class']) {
                throw new \RuntimeException("wprism: $where derived attributes admit only class");
            }
            return;
        }
        if (($rule['class'] ?? null) !== 'authored'
            || array_diff(array_keys($rule), ['class', 'ref', 'cast', 'json_refs', 'key_refs', 'plain_data',
                RecordFields::FIELD, EncodedText::FIELD, ...ReferenceRules::BLOCK_CONTRACT_FIELDS])) {
            throw new \RuntimeException("wprism: $where has an unsupported value disposition or field");
        }
        if (array_key_exists('object_fields', $rule)) {
            $fields = $rule['object_fields'];
            if (count($rule) !== 2 || !is_array($fields) || $fields === [] || array_is_list($fields)
                || count($fields) > self::MAX_OBJECT_FIELDS) {
                throw new \RuntimeException("wprism: $where.object_fields requires a bounded exact authored field map and no other codec");
            }
            foreach ($fields as $field => $child) {
                if (!is_string($field) || preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,127}$/D', $field) !== 1
                    || !is_array($child) || array_is_list($child)) {
                    throw new \RuntimeException("wprism: $where.object_fields requires exact field names and authored value rules");
                }
                $this->validate($child, "$where.object_fields.$field", $depth + 1);
            }
            return;
        }
        if (array_key_exists('enum', $rule)) {
            $values = $rule['enum'];
            if (count($rule) !== 2 || !is_array($values) || !array_is_list($values)
                || $values === [] || count($values) > self::MAX_ENUM_VALUES) {
                throw new \RuntimeException("wprism: $where.enum requires a bounded nonempty literal list and no other codec");
            }
            ScalarValueConstraint::assert_literal_values($values, $where);
            return;
        }
        if (array_key_exists('on_unmapped', $rule) && (($rule['on_unmapped'] ?? null) !== 'refuse'
            || !(isset($rule['ref']) || isset($rule['json_refs']) || isset($rule['key_refs'])))) {
            throw new \RuntimeException("wprism: $where.on_unmapped requires refuse and a reference codec");
        }
        if ($this->strictReferences && (isset($rule['ref']) || isset($rule['json_refs']) || isset($rule['key_refs']))
            && ($rule['on_unmapped'] ?? null) !== 'refuse') {
            throw new \RuntimeException("wprism: $where column references require on_unmapped:refuse");
        }
        ReferenceRules::value_rule($rule, $where, blockRecords: true, encodedText: $this->encodedText, blockContracts: $negotiated);
        self::assert_structured_keyspaces($rule, $where);
        if (array_key_exists(RecordFields::FIELD, $rule)) {
            if (!$this->records) {
                throw new \RuntimeException("wprism: $where.record_fields requires both negotiated {$this->surface} value and record field features");
            }
            RecordFields::validate($rule, $where);
        }
        $choices = (int) isset($rule['ref']) + (int) (isset($rule['json_refs']) || isset($rule['key_refs']))
            + (int) (($rule['plain_data'] ?? null) === true) + (int) array_key_exists(EncodedText::FIELD, $rule);
        if ($choices !== 1 || (isset($rule['cast']) && !isset($rule['ref']))
            || (($rule['cast'] ?? null) === 'csv' && !str_ends_with($rule['ref'], '[]'))) {
            throw new \RuntimeException("wprism: $where requires one reference or plain-data codec; CSV requires a list ref");
        }
    }

    /** StructuredReferenceCodec resolves durable tokens; user:login belongs to the scalar/list user codec. */
    public static function assert_structured_keyspaces(array $rule, string $where): void {
        foreach ($rule['json_refs'] ?? [] as $ref) {
            if (($ref['kind'] ?? null) === 'user') {
                throw new \RuntimeException("wprism: $where user references require ref:user or ref:user[]");
            }
        }
        if (($rule['key_refs']['kind'] ?? null) === 'user') {
            throw new \RuntimeException("wprism: $where user references require ref:user or ref:user[]");
        }
    }

}

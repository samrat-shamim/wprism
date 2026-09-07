<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/NativeValueValidation.php';

require_once __DIR__ . '/../Kernel/PlainData.php';
require_once __DIR__ . '/../Kernel/StructuredValue.php';
require_once __DIR__ . '/../Kernel/OrderPreserved.php';
require_once __DIR__ . '/../Kernel/MetaRows.php';

/**
 * Read-only post/term metadata discovery and authored-value classification.
 *
 * This boundary owns the exact ordered wp_postmeta/wp_termmeta reads used by
 * capture and pending, plus their shared policy/security/codec pipeline. It
 * never mints identity or writes the database. Capture supplies the few side
 * channels that remain repository-context decisions: observation checkpoints,
 * secret refusal, and loud unclassified-key collection.
 */
final class EntityMetaCapture {
    private object $policy;
    private object $tokens;
    private \Closure $guardSecret;
    private \Closure $checkpointObservationRead;
    private \Closure $recordUnclassified;

    public function __construct(
        object $policy,
        object $tokens,
        \Closure $guardSecret,
        \Closure $checkpointObservationRead,
        \Closure $recordUnclassified
    ) {
        $this->policy = $policy;
        $this->tokens = $tokens;
        $this->guardSecret = $guardSecret;
        $this->checkpointObservationRead = $checkpointObservationRead;
        $this->recordUnclassified = $recordUnclassified;
    }

    /** First-value-per-key postmeta context in meta_id order. */
    public function postMetaMap(int $postId): array {
        global $wpdb;
        $rows = $this->ownerMetaRows($wpdb->postmeta, 'post_id', $postId, 'post metadata capture');
        $out = [];
        foreach ($rows as $row) {
            if (!array_key_exists($row['meta_key'], $out)) {
                $out[$row['meta_key']] = $row['meta_value'];
            }
        }
        return $out;
    }

    /** All postmeta values grouped by key, preserving meta_id order per key. */
    public function postMetaByKey(int $postId): array {
        global $wpdb;
        $rows = $this->ownerMetaRows($wpdb->postmeta, 'post_id', $postId, 'post metadata capture');
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['meta_key']][] = $row['meta_value'];
        }
        return $byKey;
    }

    /** First-value-per-key termmeta context in meta_id order. */
    public function termMetaMap(int $termId): array {
        global $wpdb;
        $rows = $this->ownerMetaRows($wpdb->termmeta, 'term_id', $termId, 'term metadata capture');
        $out = [];
        foreach ($rows as $row) {
            if (!array_key_exists($row['meta_key'], $out)) {
                $out[$row['meta_key']] = $row['meta_value'];
            }
        }
        return $out;
    }

    /** All termmeta values grouped by key, preserving meta_id order per key. */
    public function termMetaByKey(int $termId): array {
        global $wpdb;
        $rows = $this->ownerMetaRows($wpdb->termmeta, 'term_id', $termId, 'term metadata capture');
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['meta_key']][] = $row['meta_value'];
        }
        return $byKey;
    }

    /**
     * @return list<array{meta_id:string,meta_key:string,meta_value:?string}>
     */
    private function ownerMetaRows(
        string $table,
        string $ownerColumn,
        int $ownerId,
        string $purpose
    ): array {
        return MetaRows::ordered(
            $table,
            $ownerColumn,
            $ownerId,
            'meta_id',
            $purpose,
            null,
            $this->checkpointObservationRead
        );
    }

    /**
     * Classify and fully resolve one post- or term-meta value.
     *
     * @param string[] $values Raw values for one key in meta_id order.
     * @param array<string,string> $flatMeta First-value sibling context.
     * @return array{0:bool,1:mixed} Whether to store, and the captured value.
     */
    public function classifyValue(
        string $key,
        array $values,
        array $flatMeta,
        string $ownerLabel,
        string $unclassifiedPrefix,
        bool $termMeta = false
    ): array {
        $rule = $termMeta
            ? $this->policy->meta_rule_for_term($key, $flatMeta)
            : $this->policy->meta_rule_for_post($key, $flatMeta);
        if ($rule === null) {
            ($this->recordUnclassified)("$unclassifiedPrefix:$key");
            return [false, null];
        }
        if (($rule['class'] ?? '') !== 'authored') {
            return [false, null];
        }
        $repeated = array_key_exists('repeated_rows', $rule);
        if (!$repeated && count($values) > 1) {
            throw new \RuntimeException("wprism: multi-value authored meta '$key' on $ownerLabel unsupported in v0");
        }
        if (!$repeated) {
            return $this->classifyOne($key, $values[0], $rule, $ownerLabel, $termMeta);
        }
        if ($values === []) {
            throw new \RuntimeException(
                "wprism: repeated-row authored meta '$key' on $ownerLabel must contain one or more rows when present"
            );
        }

        $captured = [];
        $seen = [];
        foreach ($values as $rawValue) {
            [$store, $value] = $this->classifyOne($key, $rawValue, $rule, $ownerLabel, $termMeta);
            if (!$store) {
                throw new \RuntimeException(
                    "wprism: repeated-row authored meta '$key' on $ownerLabel cannot drop one unresolved row without changing its ordered set"
                );
            }
            $fingerprint = "v\0" . serialize($value);
            if (isset($seen[$fingerprint])) {
                throw new \RuntimeException(
                    "wprism: repeated-row authored meta '$key' on $ownerLabel contains a duplicate value"
                );
            }
            $seen[$fingerprint] = true;
            $captured[] = $value;
        }
        return [true, $captured];
    }

    /** @return array{0:bool,1:mixed} */
    private function classifyOne(
        string $key,
        mixed $rawValue,
        array $rule,
        string $ownerLabel,
        bool $termMeta
    ): array {
        $value = PlainData::decode($rawValue, "$ownerLabel meta $key");
        PlainData::assert($value, "$ownerLabel meta $key");
        NativeValueValidation::assert_native($value, $rule, "$ownerLabel meta $key");
        if (array_key_exists('repeated_rows', $rule) && !is_scalar($value)) {
            throw new \RuntimeException(
                "wprism: repeated-row authored meta '$key' on $ownerLabel requires one scalar value per database row"
            );
        }
        ($this->guardSecret)($termMeta ? 'term_meta' : 'post_meta', $key, $value, $rule, " on $ownerLabel");
        if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
            $decoded = StructuredValue::decode($value, $rule, "$ownerLabel meta $key");
            $value = $this->tokens->struct_capture(
                $decoded,
                $rule['json_refs'] ?? [],
                $rule['key_refs'] ?? null
            );
        } elseif (!empty($rule['plain_data'])) {
            $value = $this->tokens->plain_data_capture($value);
        } elseif (!empty($rule['ref'])) {
            $value = $this->tokens->meta_value_to_tokens($value, $rule);
            if ($value === null) {
                return [false, null];
            }
        } elseif (is_string($value)) {
            $value = $this->tokens->tokenize_text($value);
        }
        if (!empty($rule['order_preserving'])) {
            $value = new OrderPreserved($value);
        }
        return [true, $value];
    }
}

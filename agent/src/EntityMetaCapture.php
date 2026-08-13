<?php
namespace Duo;

require_once __DIR__ . '/PlainData.php';
require_once __DIR__ . '/StructuredValue.php';
require_once __DIR__ . '/OrderPreserved.php';

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
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id ASC",
            $postId
        ), ARRAY_A) ?: [];
        ($this->checkpointObservationRead)();
        $out = [];
        foreach ($rows as $row) {
            if (!isset($out[$row['meta_key']])) {
                $out[$row['meta_key']] = $row['meta_value'];
            }
        }
        return $out;
    }

    /** All postmeta values grouped by key, preserving meta_id order per key. */
    public function postMetaByKey(int $postId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_key ASC, meta_id ASC",
            $postId
        ), ARRAY_A) ?: [];
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['meta_key']][] = $row['meta_value'];
        }
        return $byKey;
    }

    /** First-value-per-key termmeta context in meta_id order. */
    public function termMetaMap(int $termId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->termmeta} WHERE term_id = %d ORDER BY meta_id ASC",
            $termId
        ), ARRAY_A) ?: [];
        ($this->checkpointObservationRead)();
        $out = [];
        foreach ($rows as $row) {
            if (!isset($out[$row['meta_key']])) {
                $out[$row['meta_key']] = $row['meta_value'];
            }
        }
        return $out;
    }

    /** All termmeta values grouped by key, preserving meta_id order per key. */
    public function termMetaByKey(int $termId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->termmeta} WHERE term_id = %d ORDER BY meta_key ASC, meta_id ASC",
            $termId
        ), ARRAY_A) ?: [];
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['meta_key']][] = $row['meta_value'];
        }
        return $byKey;
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
        if (count($values) > 1) {
            throw new \RuntimeException("duo: multi-value authored meta '$key' on $ownerLabel unsupported in v0");
        }
        $value = PlainData::decode($values[0], "$ownerLabel meta $key");
        PlainData::assert($value, "$ownerLabel meta $key");
        ($this->guardSecret)($termMeta ? 'term_meta' : 'post_meta', $key, $value, $rule, " on $ownerLabel");
        if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
            $decoded = StructuredValue::decode($value, $rule, "$ownerLabel meta $key");
            $value = $this->tokens->struct_capture(
                $decoded,
                $rule['json_refs'] ?? [],
                $rule['key_refs'] ?? null
            );
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

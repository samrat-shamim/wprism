<?php
declare(strict_types=1);

namespace WPrism\Interpreters;

use WPrism\Policy;

/**
 * Block Visibility keeps three things: one settings option, one
 * `visibility_preset` post type, and a `blockVisibility` attribute the manifest
 * declares `unsupported` on every block that can carry it.
 *
 * This interpreter exists for the two decisions the static grammar cannot make.
 *
 * FIRST, the preset meta keys are UNPREFIXED. `register-presets.php` registers
 * `enable`, `layout`, `hide_block` and `control_sets` scoped by
 * `object_subtype => visibility_preset`, but a manifest `post_meta` entry
 * claims a key across every post type. Declaring `enable` statically would let
 * this adapter classify an unrelated plugin's `enable` row on an ordinary post.
 * The keys are therefore claimed only when the sibling context proves the owner,
 * the same shape Contact Form 7's interpreter uses for its own bare property
 * names.
 *
 * SECOND, `control_sets` carries the SAME structure as the refused block
 * attribute: `controls.location` rules whose `value` is a post id list, a
 * comma-separated post-id string, term ids or an author id depending on the
 * sibling `field`, and the engine has no reference path that can be
 * conditioned on a sibling. A control set that references no entity is
 * ordinary portable data and is captured; one that does is refused by name,
 * because carrying it would put a source-local id on a target pointing at an
 * unrelated entity.
 */
final class BlockVisibility {
    private const PRESET_POST_TYPE = 'visibility_preset';
    private const CONTROL_SETS = 'control_sets';
    private const SCALAR_KEYS = ['enable', 'hide_block', 'layout'];

    /**
     * `location` rule fields whose `value` is an entity reference. Measured
     * against 3.7.1 `includes/frontend/visibility-tests/location.php`'s own
     * dispatch switch (`:150-220`), not guessed from key names.
     */
    private const REFERENCE_FIELDS = [
        'post',
        'postID',
        'postTaxonomy',
        'archive',
        'taxonomyTermHierarchy',
        'taxonomyTermRelativeHierarchy',
        'attributesAuthor',
    ];

    public function __construct(Policy $policy) {
        // The whole decision is fixed by the pinned 3.7.1 storage contract; no
        // site policy is consulted after construction.
    }

    /**
     * @param array<string,mixed> $allMeta First-value sibling context.
     * @return array<string,mixed>|null
     */
    public function post_meta_rule(string $key, array $allMeta): ?array {
        if (!$this->is_preset_meta($allMeta)) {
            return null;
        }
        if (in_array($key, self::SCALAR_KEYS, true)) {
            return ['class' => 'authored'];
        }
        if ($key !== self::CONTROL_SETS) {
            return null;
        }
        $this->assert_control_sets_carry_no_reference($allMeta[$key] ?? null);
        return ['class' => 'authored', 'plain_data' => true];
    }

    /**
     * A preset row is proven by its own distinctive key, never by one of the
     * three bare scalars: `enable` and `layout` are common enough that a
     * sibling-free guess would claim another plugin's meta.
     *
     * @param array<string,string> $allMeta
     */
    private function is_preset_meta(array $allMeta): bool {
        return array_key_exists(self::CONTROL_SETS, $allMeta);
    }

    /**
     * Refuse a control set that references an entity, naming the exact rule
     * field, because no declaration in this engine can rebind it.
     *
     * The sibling context arrives already decoded for a serialized row and as
     * the raw string otherwise (measured live: capture handed this hook a PHP
     * array, not the `array<string,string>` its own docblock promises), so both
     * shapes are accepted rather than one being assumed.
     */
    private function assert_control_sets_carry_no_reference(mixed $value): void {
        if ($value === '' || $value === null) {
            return;
        }
        $decoded = $value;
        if (is_string($value)) {
            $decoded = @unserialize($value, ['allowed_classes' => false]);
            if ($decoded === false && $value !== serialize(false)) {
                throw new \RuntimeException(
                    'wprism: Block Visibility preset meta control_sets is not decodable PHP serialization; '
                    . 'refusing to classify a value this adapter cannot inspect for entity references'
                );
            }
        }
        $found = $this->reference_fields_in($decoded);
        if ($found === []) {
            return;
        }
        sort($found, SORT_STRING);
        throw new \RuntimeException(
            'wprism: Block Visibility preset control_sets carries location rule field(s) '
            . implode(', ', $found) . ' whose value is an entity reference. The engine has no reference '
            . 'path conditioned on a sibling field, so this id cannot be rebound on a target; refusing '
            . 'rather than carrying a source-local id (see this capsule manifest note 4)'
        );
    }

    /** @return list<string> */
    private function reference_fields_in(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }
        $found = [];
        $field = $value['field'] ?? null;
        if (is_string($field)
            && in_array($field, self::REFERENCE_FIELDS, true)
            && array_key_exists('value', $value)) {
            $found[] = $field;
        }
        foreach ($value as $child) {
            foreach ($this->reference_fields_in($child) as $nested) {
                $found[] = $nested;
            }
        }
        return array_values(array_unique($found));
    }
}

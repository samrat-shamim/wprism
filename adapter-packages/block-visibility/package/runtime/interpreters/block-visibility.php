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
    /**
     * The three portable preset scalars, and whether each needs `lint_ok`.
     *
     * `enable` and `hide_block` are booleans WordPress stores as 1/0, so on a
     * young site the value collides with a real post id and lint reports a
     * bare_id — measured on the first conformance run, where `meta.enable` = 1
     * matched the preset itself (post 1). register-presets.php declares both
     * `type => boolean`, so neither is ever an entity reference and the
     * exemption is a statement about the plugin's own schema. `layout` is a
     * string enum and needs no exemption.
     */
    private const SCALAR_KEYS = [
        'enable' => true,
        'hide_block' => true,
        'layout' => false,
    ];

    /**
     * Controls reviewed as carrying NO entity reference in ANY mode, measured
     * against 3.7.1's own test files under includes/frontend/visibility-tests/.
     *
     * THIS IS AN ALLOWLIST ON PURPOSE. The first version of this class listed
     * the `location` rule FIELDS that are references and admitted everything
     * else, and that was unsound twice over: WooCommerce keeps its product id
     * in `subField`, not `value` (woocommerce/helper-functions.php:376-383,
     * `(int) $rule['subField']`), so no field-name list would ever match it;
     * and its category rules put term identifiers in `value` under field names
     * a location-derived list does not contain. A denylist has to stay
     * complete against a plugin that keeps adding controls. This one refuses
     * anything it has not reviewed, so a control added in a later release is a
     * loud refusal rather than a silently captured id.
     */
    private const REFERENCE_FREE_CONTROLS = [
        'browserDevice',
        'cookie',
        'dateTime',
        'queryString',
        'referralSource',
        'screenSize',
        'urlPath',
    ];

    /**
     * `userRole` is reference-free ONLY in its role shapes, so its own KEY SET
     * is allowlisted rather than a couple of bad keys being excluded. Two
     * distinct shapes carry USER IDS: `restrictedUsers` under
     * visibilityByRole='users' (user-role.php:125-156, :368-372), and a
     * `ruleSets` rule whose `field` is 'users' (:291-293), a SECOND field
     * namespace unrelated to location's. Excluding those two by name would be
     * a denylist nested inside the allowlist and would fail open on the third
     * shape; admitting only the reviewed role keys fails closed instead.
     */
    private const ROLE_CONTROL = 'userRole';
    private const ROLE_SAFE_KEYS = [
        'visibilityByRole',
        'restrictedRoles',
        'hideOnRestrictedRoles',
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
        if (array_key_exists($key, self::SCALAR_KEYS)) {
            $rule = ['class' => 'authored'];
            if (self::SCALAR_KEYS[$key]) {
                $rule['lint_ok'] = true;
            }
            return $rule;
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
        $found = $this->unreviewed_controls_in($decoded);
        if ($found === []) {
            return;
        }
        sort($found, SORT_STRING);
        throw new \RuntimeException(
            'wprism: Block Visibility preset control_sets uses control(s) ' . implode(', ', $found)
            . ' that this capsule has not reviewed as reference-free, so their values may be entity '
            . 'references the engine cannot rebind on a target; refusing rather than carrying a '
            . 'source-local id (see this capsule manifest note 4)'
        );
    }

    /**
     * Every control a set declares must be one this capsule has reviewed as
     * reference-free. Returns the control names that are not.
     *
     * @return list<string>
     */
    private function unreviewed_controls_in(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }
        $found = [];
        $controls = $value['controls'] ?? null;
        if (is_array($controls)) {
            foreach ($controls as $name => $control) {
                $name = (string) $name;
                if ($name === self::ROLE_CONTROL) {
                    foreach ($this->role_control_unreviewed_keys($control) as $key) {
                        $found[] = $name . '.' . $key;
                    }
                    continue;
                }
                if (!in_array($name, self::REFERENCE_FREE_CONTROLS, true)) {
                    $found[] = $name;
                }
            }
        }
        foreach ($value as $child) {
            foreach ($this->unreviewed_controls_in($child) as $nested) {
                $found[] = $nested;
            }
        }
        return array_values(array_unique($found));
    }

    /**
     * @return list<string> the userRole keys this capsule has not reviewed
     */
    private function role_control_unreviewed_keys(mixed $control): array {
        if (!is_array($control)) {
            return [];
        }
        $unreviewed = [];
        foreach (array_keys($control) as $key) {
            if (!in_array((string) $key, self::ROLE_SAFE_KEYS, true)) {
                $unreviewed[] = (string) $key;
            }
        }
        sort($unreviewed, SORT_STRING);
        return $unreviewed;
    }
}

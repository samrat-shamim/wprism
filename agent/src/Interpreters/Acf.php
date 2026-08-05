<?php
namespace Duo\Interpreters;

use Duo\Policy;

/**
 * ACF interpreter (design finding #12): which meta values are id refs is
 * determined by field-group definitions living in the DB (acf-field posts),
 * not a static key list. ACF itself resolves a value's type at read time via
 * a shadow "_<key>" meta pointing at the owning field's key; this interpreter
 * reads the same pointer and looks up the field definition it names.
 *
 * Storage shapes below are as observed against ACF (free) 6.x via the
 * spike-e seed (sandbox/tests/spike_e_acf.sh — an image field and a
 * relationship field; see that script's seed step and this spike's final
 * report for the empirical trace):
 *   - acf-field-group / acf-field posts: the field/group config (everything
 *     but the handful of properties promoted to real post columns — key,
 *     label/title, menu_order, parent) is a serialize()'d PHP array in
 *     post_content; post_name is the key (e.g. "field_duo_hero"). Consumed
 *     verbatim by the acf manifest's body:"verbatim" post_types entries —
 *     re-serializing through canonical JSON would corrupt the byte lengths
 *     serialize() embeds.
 *   - image / file / post_object: the referenced post id, stored as a lone
 *     scalar meta_value. meta_value is a TEXT column, so there is no
 *     int-vs-string distinction at the byte level for a lone scalar — the
 *     "cast":"string" declared below is a no-op for these three today, but
 *     is declared anyway for consistency with the shape that *does* matter:
 *   - relationship / gallery: a serialize()'d array of id strings, e.g.
 *     a:2:{i:0;s:2:"12";i:1;s:2:"34";} — ACF's own field-type update_value()
 *     casts each element to a string before saving, so reproducing that
 *     exact serialized byte sequence on apply requires round-tripping the
 *     ids as strings (a:2:{i:0;s:...} not a:2:{i:0;i:...}), which is what
 *     Tokens' "cast":"string" restores.
 *   - taxonomy / user: NOT exercised by the spike-e seed (it seeds neither
 *     field type) — mapped from ACF's documented behavior: single- vs
 *     multi-value storage is itself a field setting (taxonomy's
 *     "field_type": checkbox/multi_select selects vs. radio/select; user's
 *     boolean "multiple"), so the ref kind below is picked from that setting
 *     rather than hard-coded, but this path is unverified end-to-end here.
 */
final class Acf {
    private const FIELD_KEY_PATTERN = '/^field_[A-Za-z0-9_]+$/';

    /** @var array<string, array|null> field key -> unserialized post_content (null = no such field). */
    private array $fieldDefs = [];

    public function __construct(Policy $policy) {
        // Unused: classification here is fully schema-driven from acf-field
        // post_content, so no static-policy lookups are needed — the param
        // exists to satisfy the interpreter contract (Policy::interpreters()
        // constructs every interpreter as `new $class($this)`).
    }

    /**
     * @param array<string,string> $allMeta this entity's flat meta map (first
     *   value per key), shadow keys included — as Policy::meta_rule_for_post
     *   receives it from both Capture (live postmeta) and Apply (canonical
     *   front-matter meta).
     */
    public function post_meta_rule(string $key, array $allMeta): ?array {
        if (str_starts_with($key, '_')) {
            return $this->shadow_key_rule($key, $allMeta);
        }

        $pointer = $allMeta['_' . $key] ?? null;
        if (!is_string($pointer) || !preg_match(self::FIELD_KEY_PATTERN, $pointer)) {
            return null;
        }
        $def = $this->field_definition($pointer);
        if ($def === null) {
            // Field definition not in this DB: never guess — defer to static
            // rules, and from there to the loud unclassified gate.
            return null;
        }
        return $this->rule_for_type((string) ($def['type'] ?? ''), $def);
    }

    /** The field-key pointer meta itself ("_<key>" => "field_..."): a plain authored string. */
    private function shadow_key_rule(string $key, array $allMeta): ?array {
        $base = substr($key, 1);
        $pointer = $allMeta[$key] ?? null;
        if (array_key_exists($base, $allMeta) && is_string($pointer) && preg_match(self::FIELD_KEY_PATTERN, $pointer)) {
            return ['class' => 'authored'];
        }
        return null;
    }

    private function rule_for_type(string $type, array $def): array {
        return match ($type) {
            'image', 'file', 'post_object' => ['class' => 'authored', 'ref' => 'post', 'cast' => 'string'],
            'relationship', 'gallery' => ['class' => 'authored', 'ref' => 'post[]', 'cast' => 'string'],
            'taxonomy' => [
                'class' => 'authored',
                'ref' => in_array($def['field_type'] ?? '', ['checkbox', 'multi_select'], true) ? 'term[]' : 'term',
                'cast' => 'string',
            ],
            'user' => [
                'class' => 'authored',
                'ref' => empty($def['multiple']) ? 'user' : 'user[]',
                'cast' => 'string',
            ],
            // text/textarea/wysiwyg/true_false/select/number/...: a plain
            // authored value. The default (non-ref) capture path already
            // URL-tokenizes strings, which is exactly right for wysiwyg.
            default => ['class' => 'authored'],
        };
    }

    /** @return ?array unserialized post_content of the acf-field post named by $fieldKey, or null if absent. */
    private function field_definition(string $fieldKey): ?array {
        if (array_key_exists($fieldKey, $this->fieldDefs)) {
            return $this->fieldDefs[$fieldKey];
        }
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT post_content FROM {$wpdb->posts} WHERE post_type = 'acf-field' AND post_name = %s LIMIT 1",
            $fieldKey
        ));
        $def = $raw !== null ? maybe_unserialize($raw) : null;
        return $this->fieldDefs[$fieldKey] = (is_array($def) ? $def : null);
    }
}

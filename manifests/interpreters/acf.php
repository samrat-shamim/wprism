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
 * relationship field) and the acf conformance seed (sandbox/conformance/
 * seeds/acf.sh — taxonomy checkbox/radio + user fields, task #16's
 * end-to-end exercise; see that seed and its conformance run for the trace):
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
 *   - taxonomy: single- vs multi-value storage is itself a field setting
 *     ("field_type": checkbox/multi_select selects vs. radio/select single),
 *     so the ref kind is picked from that setting. Verified: multi-value is
 *     a serialize()'d array of id INTEGERS, a:2:{i:0;i:2;i:1;i:3;} — no
 *     strval, unlike relationship/gallery above — so no "cast" is declared;
 *     single-value is a bare scalar (same no-op-cast case as image/file/
 *     post_object). Getting this wrong doesn't break the byte-for-byte
 *     canonical-JSON round trip (token ids are (int)-cast either way going
 *     back through meta_value_to_tokens — see Tokens::meta_value_to_tokens's
 *     $one closure) so a wrong cast here is silent until something diffs the
 *     raw DB row or a stricter reader cares about the element type.
 *   - user: single- vs multi-value storage is the boolean "multiple" field
 *     setting. Verified: multi-value IS strval'd like relationship/gallery,
 *     a:2:{i:0;s:1:"1";i:1;s:1:"2";} — "cast":"string" is correct here;
 *     single-value is a bare scalar (no-op either way).
 */
final class Acf {
    private const FIELD_KEY_PATTERN = '/^field_[A-Za-z0-9_]+$/';

    /** @var array<string, array|null> field key -> unserialized post_content (null = no such field). */
    private array $fieldDefs = [];
    private bool $repositoryPrimed = false;

    public function __construct(Policy $policy) {
        // Unused: classification here is fully schema-driven from acf-field
        // post_content, so no static-policy lookups are needed — the param
        // exists to satisfy the interpreter contract (Policy::interpreters()
        // constructs every interpreter as `new $class($this)`).
    }

    /**
     * Prime field definitions from the immutable repository rather than the
     * target database. An acf-field post is deliberately captured with a
     * verbatim serialized body; using that same body for authorization makes
     * the result target-independent (fresh targets do not have these rows
     * until apply phase 1/2, while mapped targets already do).
     */
    public function prime_repository(array $tree): void {
        $this->repositoryPrimed = true;
        foreach ($tree as $entity) {
            if (($entity['type'] ?? '') !== 'post') {
                continue;
            }
            [$front, $body] = \Duo\Canon::parse_post_file((string) $entity['content']);
            if (($front['type'] ?? '') !== 'acf-field') {
                continue;
            }
            $fieldKey = (string) ($front['slug'] ?? '');
            if (!preg_match(self::FIELD_KEY_PATTERN, $fieldKey)) {
                continue;
            }
            $decoded = @unserialize($body, ['allowed_classes' => false]);
            $this->fieldDefs[$fieldKey] = is_array($decoded) ? $decoded : null;
        }
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
            // Empirically verified (sandbox/conformance/seeds/acf.sh, task
            // #16): unlike relationship/gallery, ACF's taxonomy field-type
            // update_value() does NOT strval its ids — a multi-value
            // (checkbox/multi_select) meta_value is a:N:{i:0;i:<id>;...}
            // (int elements), so no 'cast' here (single-value is a bare
            // scalar either way, same no-op note as image/file/post_object).
            'taxonomy' => [
                'class' => 'authored',
                'ref' => in_array($def['field_type'] ?? '', ['checkbox', 'multi_select'], true) ? 'term[]' : 'term',
            ],
            // Empirically verified: the user field-type's multi-value shape
            // IS strval'd (a:N:{i:0;s:1:"1";...}), unlike taxonomy — so
            // 'cast'=>'string' here is correct as originally written.
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
        if ($this->repositoryPrimed) {
            // Authorization is about one immutable revision. Falling back to
            // a mapped target's DB here would let that target authorize a
            // stale/incomplete tree which a fresh target correctly refuses.
            return null;
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

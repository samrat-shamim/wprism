<?php
namespace Duo\Interpreters;

require_once __DIR__ . '/../../agent/src/PlainData.php';

use Duo\Policy;
use Duo\PlainData;

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
    /**
     * DUO-3263: options-page field storage prefix, empirically confirmed
     * (fresh ACF 6.8.7, free plugin — sandbox/tests/spike_e_acf.sh's sibling
     * probe, see the DUO-3263 PR body for the exact session). ACF's
     * "acf_add_options_page()" admin-UI registration function does NOT
     * exist in the free plugin (grepped the installed plugin source: no
     * options-page-functions file, only a PRO upsell preview view) — but
     * the underlying value storage is NOT gated the same way:
     * update_field($key, $value, 'option') (and the 'options' spelling,
     * confirmed a synonym) persists with zero PRO code present, because
     * ACF's core value API treats option/options as an ordinary object
     * type independent of whether an admin page was ever registered (a
     * real free-plugin pattern: acf_form() on a front-end page, a custom
     * admin page, WP-CLI, or a snippet, wiring global site-settings values
     * without paying for PRO's options-page UI convenience).
     *
     * Storage shape is a THIRD shape, matching neither post/term meta's
     * bare shadow-key convention nor a single blob: individually-stored
     * wp_options rows with a fixed 'options_'/'_options_' prefix —
     * options_<fieldname> = value, _options_<fieldname> = shadow pointer to
     * field_<key>, same shadow-pointer convention as post/term meta, just
     * prefixed (ACF's own device for not colliding with an unrelated
     * option that happens to share a bare field name, since wp_options is
     * one flat global namespace unlike wp_postmeta/wp_termmeta which are
     * already scoped per-object). Confirmed a ref-type (image) field
     * stores the bare attachment id scalar — same no-op-cast shape
     * documented below for image/file/post_object. Confirmed
     * delete_field(..., 'option') removes both rows atomically (no
     * orphaned shadow to find on a later scan).
     */
    private const OPTIONS_PREFIX = 'options_';

    /** @var array<string, array|null> field key -> unserialized post_content (null = no such field). */
    private array $fieldDefs = [];
    /** @var array<string,string> field key -> canonical source path */
    private array $fieldDefPaths = [];
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
            if (isset($entity['data'])) {
                $front = $entity['data'];
                $body = (string) ($entity['body'] ?? '');
            } else {
                [$front, $body] = \Duo\Canon::parse_post_file((string) $entity['content']);
            }
            if (($front['type'] ?? '') !== 'acf-field') {
                continue;
            }
            $fieldKey = (string) ($front['slug'] ?? '');
            if (!preg_match(self::FIELD_KEY_PATTERN, $fieldKey)) {
                continue;
            }
            $decoded = @unserialize($body, ['allowed_classes' => false]);
            $this->fieldDefs[$fieldKey] = is_array($decoded) ? $decoded : null;
            $this->fieldDefPaths[$fieldKey] = (string) ($entity['path'] ?? '');
        }
    }

    /**
     * Compiler extension: prove that the schema post named by every ACF
     * shadow key exists in this same immutable revision and that the
     * canonical value has the scalar/list token shape that schema declares.
     * A clean JSON/Git merge can otherwise pair a relationship value with a
     * field changed to image (or delete its field definition) while every
     * individual file remains syntactically valid.
     *
     * @return array<int,array<string,mixed>>
     */
    public function repository_diagnostics(array $tree): array {
        $out = [];
        foreach ($this->fieldDefs as $key => $def) {
            if ($def !== null && is_string($def['type'] ?? null) && $def['type'] !== '') {
                continue;
            }
            $out[] = [
                'code' => 'adapter_schema_content_mismatch',
                'path' => $this->fieldDefPaths[$key] ?? '',
                'locator' => 'body',
                'message' => "ACF field definition '$key' is not a serialized field schema with a type",
            ];
        }
        foreach ($tree as $entity) {
            if (($entity['type'] ?? '') !== 'post') {
                continue;
            }
            $front = $entity['data'] ?? \Duo\Canon::parse_post_file((string) $entity['content'])[0];
            $meta = (array) ($front['meta'] ?? []);
            foreach ($meta as $shadow => $pointer) {
                if (!is_string($shadow) || !str_starts_with($shadow, '_')) {
                    continue;
                }
                $name = substr($shadow, 1);
                if (!array_key_exists($name, $meta) || !is_string($pointer)
                    || !preg_match(self::FIELD_KEY_PATTERN, $pointer)) {
                    continue;
                }
                $def = $this->fieldDefs[$pointer] ?? null;
                if ($def === null) {
                    $out[] = [
                        'code' => 'adapter_schema_content_mismatch',
                        'path' => (string) $entity['path'],
                        'locator' => "meta.$shadow",
                        'message' => "ACF value '$name' points to field schema '$pointer', absent or invalid in this revision",
                    ];
                    continue;
                }
                $rule = $this->rule_for_type((string) ($def['type'] ?? ''), $def);
                $ref = (string) ($rule['ref'] ?? '');
                if ($ref === '') {
                    continue;
                }
                $value = $meta[$name];
                $many = str_ends_with($ref, '[]');
                $kind = $many ? substr($ref, 0, -2) : $ref;
                $values = $many ? (is_array($value) ? $value : null) : [$value];
                if ($values === null || (!$many && is_array($value))) {
                    $out[] = [
                        'code' => 'adapter_schema_content_mismatch',
                        'path' => (string) $entity['path'],
                        'locator' => "meta.$name",
                        'message' => "ACF field '$pointer' ($kind" . ($many ? '[]' : '') . ') has the wrong scalar/list shape',
                    ];
                    continue;
                }
                foreach ($values as $i => $v) {
                    $valid = $v === null || ($kind === 'user'
                        ? is_string($v) && str_starts_with($v, 'user:')
                        : is_string($v) && preg_match('/^\{\{' . preg_quote($kind, '/') . ':[0-9a-f-]{36}\}\}$/', $v));
                    if (!$valid) {
                        $out[] = [
                            'code' => 'adapter_schema_content_mismatch',
                            'path' => (string) $entity['path'],
                            'locator' => "meta.$name" . ($many ? "[$i]" : ''),
                            'message' => "ACF field '$pointer' expects a canonical $kind reference token",
                        ];
                    }
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string,string> $allMeta this entity's flat meta map (first
     *   value per key), shadow keys included — as Policy::meta_rule_for_post
     *   receives it from both Capture (live postmeta) and Apply (canonical
     *   front-matter meta).
     */
    public function post_meta_rule(string $key, array $allMeta): ?array {
        return $this->shadow_keyed_rule($key, $allMeta);
    }

    /**
     * DUO-3263: term-attached ACF fields use the exact same shadow-key
     * convention against wp_termmeta that post_meta_rule() already resolves
     * against wp_postmeta — ACF's field-type update_value()/get_value()
     * methods are attachment-agnostic (they don't know or care whether the
     * owning object is a post or a term), and Capture::term_meta_map()'s
     * "first value per key" shape is byte-identical to post_meta_map()'s
     * (both confirmed by reading Capture.php). Capture's term_meta call
     * sites already dispatch through Policy::meta_rule_for_term() (DUO-3262),
     * so this is pure reuse — zero new resolution logic.
     */
    public function term_meta_rule(string $key, array $allMeta): ?array {
        return $this->shadow_keyed_rule($key, $allMeta);
    }

    /**
     * DUO-3263: ACF options-page fields (manifests/interpreters/acf.php's
     * own class docblock has the full empirical grounding for the
     * 'options_'/'_options_' prefix convention this resolves against).
     * $allOptions is Duo's own option-name classification map (raw values
     * during live capture; present values plus valid deletion witnesses for
     * repository-side authorization/compilation — see
     * Policy::meta_rule_for_option()'s own docblock) — NOT ACF's internal
     * store, so this never touches the database or acf_get_value() itself.
     */
    public function option_rule(string $name, array $allOptions): ?array {
        if (str_starts_with($name, '_' . self::OPTIONS_PREFIX)) {
            return $this->shadow_options_key_rule($name, $allOptions);
        }
        if (!str_starts_with($name, self::OPTIONS_PREFIX)) {
            return null; // not an ACF options-page name at all -- defer
        }
        $pointer = $allOptions['_' . $name] ?? null;
        if (!is_string($pointer) || !preg_match(self::FIELD_KEY_PATTERN, $pointer)) {
            return null;
        }
        $def = $this->field_definition($pointer);
        if ($def === null) {
            // Field definition not in this DB/repository: never guess --
            // defer to static rules, and from there to the loud unclassified
            // gate, same posture as post_meta_rule()'s identical branch.
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

    /** Shared by post_meta_rule() and term_meta_rule() -- identical shadow-key convention, different owning table. */
    private function shadow_keyed_rule(string $key, array $allMeta): ?array {
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

    /**
     * The options-page field-key pointer ("_options_<name>" => "field_..."):
     * a plain authored string, prefix sibling of shadow_key_rule(). Its
     * value is also the minimum context needed to classify both halves
     * after ACF atomically deletes them, so capture retains it in the
     * shadow tombstone as a hash-bound deletion witness.
     */
    private function shadow_options_key_rule(string $name, array $allOptions): ?array {
        $base = substr($name, 1); // strip leading '_', leaving "options_<field>"
        $pointer = $allOptions[$name] ?? null;
        if (array_key_exists($base, $allOptions) && is_string($pointer) && preg_match(self::FIELD_KEY_PATTERN, $pointer)) {
            return ['class' => 'authored', 'deletion_witness' => true];
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
        $def = $raw !== null ? PlainData::decode($raw, "ACF field '$fieldKey' post_content") : null;
        return $this->fieldDefs[$fieldKey] = (is_array($def) ? $def : null);
    }
}

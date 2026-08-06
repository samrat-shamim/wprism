<?php
namespace Duo;

/**
 * Environment-bound values are tokenized at capture and re-bound at apply:
 *   {{home}}, {{uploads}}, {{post:<uuid>}}, {{term:<uuid>}}, {{tt:<uuid>}}
 * Numeric positions carry the quoted token in canonical form; the applier
 * restores the declared numeric type. All rewriting is structure-aware or a
 * declared textual pattern — never a blind regex over content.
 */
final class Tokens {
    private string $home;
    private string $uploadsUrl;
    /** JSON-escaped forms (every "/" -> "\/") of the two URLs above — every
     *  "/" in a wp_json_encode()'d string is escaped this way when the
     *  JSON_UNESCAPED_SLASHES flag is absent, which is Elementor's own
     *  convention for _elementor_data (confirmed byte-level via xxd in
     *  docs/frontier/elementor.md) and is legal, unremarkable JSON. */
    private string $homeEscaped;
    private string $uploadsUrlEscaped;
    /** @var string[] capture-time warnings (unmapped ids etc.) */
    public array $warnings = [];
    /** @var array<int,string> user id -> login (capture direction) */
    private array $userLogins = [];
    /** @var array<string,int> login -> user id (apply direction) */
    private array $userIds = [];
    /** Fallback for unresolvable user tokens on apply (set by Apply). */
    public ?int $defaultUserId = null;

    public function __construct() {
        $this->home = untrailingslashit((string) get_option('home'));
        $up = wp_upload_dir(null, false);
        $this->uploadsUrl = untrailingslashit((string) $up['baseurl']);
        $this->homeEscaped = str_replace('/', '\/', $this->home);
        $this->uploadsUrlEscaped = str_replace('/', '\/', $this->uploadsUrl);
    }

    public function home(): string {
        return $this->home;
    }

    // ---- text (URLs) ----

    /**
     * Matches BOTH the plain form (`http://host/path`) and the JSON-escaped
     * form (`http:\/\/host\/path`) of {{home}}/{{uploads}}, collapsing both
     * to the SAME plain-spelled token — DESIGN.md §3.3's documented-but-
     * unshipped claim ("the tokenizer also understands JSON-escaped URL
     * forms"), now actually implemented. See detokenize_text() for why a
     * single canonical (always-plain) token spelling is deliberately
     * chosen over trying to preserve which form each occurrence originally
     * used.
     *
     * Only the matched substring (the URL prefix) is touched; any residual
     * escaped bytes immediately after it (e.g. the rest of an escaped path)
     * are left completely alone — `{{uploads}}\/2026\/08\/x.png` is exactly
     * what an escaped `http:\/\/host\/wp-content\/uploads\/2026\/08\/x.png`
     * becomes, matching the shape DESIGN.md's own illustrative example uses.
     *
     * Uploads is matched before home in BOTH forms: the uploads URL is
     * normally home-prefixed (`{home}/wp-content/uploads`), so replacing
     * home first would destroy the literal substring the uploads match
     * needs — same ordering constraint the original (plain-only) code
     * already respected.
     */
    public function tokenize_text(string $s): string {
        if ($s === '') {
            return $s;
        }
        $s = str_replace($this->uploadsUrl, '{{uploads}}', $s);
        $s = str_replace($this->uploadsUrlEscaped, '{{uploads}}', $s);
        $s = str_replace($this->home, '{{home}}', $s);
        $s = str_replace($this->homeEscaped, '{{home}}', $s);
        return $s;
    }

    /**
     * Always restores the PLAIN (unescaped) form — never the JSON-escaped
     * one — regardless of which form the token replaced at capture. This is
     * lossless where it matters: RFC 8259 makes escaping "/" inside a JSON
     * string OPTIONAL (`/` and `\/` decode identically), so emitting the
     * plain form at a position that sits inside JSON text is still valid,
     * correctly-parseable JSON — Elementor's own json_decode() (or any
     * conformant parser) reads it the same either way. Values that need
     * Elementor's OWN escaped-everywhere convention on the wire get it back
     * for free at the structural re-encode step (Tokens::struct_apply() /
     * Capture.php's json_refs handling), which re-escapes the WHOLE
     * reconstructed string uniformly — not by detokenize_text() trying to
     * guess, per-occurrence, whether THIS spot was originally escaped
     * (genuinely undecidable from the token alone, since both forms
     * collapse to one canonical spelling above).
     */
    public function detokenize_text(string $s): string {
        if ($s === '') {
            return $s;
        }
        $s = str_replace('{{uploads}}', $this->uploadsUrl, $s);
        $s = str_replace('{{home}}', $this->home, $s);
        return $s;
    }

    // ---- typed id refs ----

    /**
     * The three core keyspaces keep their short, historical token spelling
     * ({{post:...}} not {{Duo\Ledger::KIND_POST:...}}) for every canonical
     * file already shipped; anything else is a manifest-declared id_kind
     * STRING used verbatim as both the token's kind-name and the ledger
     * lookup kind — Ledger::id_for()/uuid_for()/set() have taken an
     * arbitrary id_kind string since day one (duo_map has no enum
     * constraint on the column, only PHP's own call sites were narrowed to
     * these three), so Snapshot.php's typed-snapshot tables (id_kind values
     * like "nf3_form", "attr_taxonomy" — see its docblock for the VARCHAR(16)
     * budget this engine has always implicitly kept within, "term_taxonomy"
     * being the prior high-water mark) need no engine-side registration
     * beyond what's already declared in their own manifest.
     */
    private const KIND_MAP = [
        'post' => Ledger::KIND_POST,
        'term' => Ledger::KIND_TERM,
        'tt'   => Ledger::KIND_TT,
    ];

    /** A ref/token kind name is a bare lowercase-ish identifier, matching
     *  every id_kind this engine has ever declared (post/term/tt, and every
     *  Snapshot.php table id_kind) — deliberately excludes anything that
     *  could collide with the OTHER token forms this class recognizes by
     *  their own fixed spelling ({{home}}, {{uploads}}, user:<login>). */
    private const KIND_NAME_RE = '[a-z][a-z0-9_]*';

    /** id -> "{{<kind>:uuid}}" (capture direction). Returns null when unmapped. */
    public function id_to_token(int $id, string $refKind): ?string {
        $kind = self::KIND_MAP[$refKind] ?? $refKind;
        if ($kind === '' || $id <= 0) {
            return null;
        }
        $uuid = Ledger::uuid_for($id, $kind);
        if ($uuid === null) {
            $this->warnings[] = "unmapped $refKind id $id left as-is (broken or out-of-scope reference)";
            return null;
        }
        return '{{' . $refKind . ':' . $uuid . '}}';
    }

    /** "{{<kind>:uuid}}" -> id (apply direction). Throws when unresolvable. */
    public function token_to_id(string $token): int {
        if (!preg_match('/^\{\{(' . self::KIND_NAME_RE . '):([0-9a-f-]{36})\}\}$/', $token, $m)) {
            throw new \RuntimeException("duo: malformed ref token '$token'");
        }
        $kind = self::KIND_MAP[$m[1]] ?? $m[1];
        $id = Ledger::id_for($m[2], $kind);
        if ($id === null) {
            throw new \RuntimeException("duo: unresolvable ref $token (entity not in this environment)");
        }
        return $id;
    }

    // ---- user refs (users are env-local; tokens are logins, never ids) ----

    /** id -> "user:<login>" (capture). Unresolvable users stay numeric, warned. */
    public function user_id_to_token(int $id): ?string {
        global $wpdb;
        if ($id <= 0) {
            return null;
        }
        if (!isset($this->userLogins[$id])) {
            $login = $wpdb->get_var($wpdb->prepare(
                "SELECT user_login FROM {$wpdb->users} WHERE ID = %d", $id
            ));
            $this->userLogins[$id] = $login ?: '';
        }
        if ($this->userLogins[$id] === '') {
            $this->warnings[] = "user id $id not found (env-local user gone)";
            return null;
        }
        return 'user:' . $this->userLogins[$id];
    }

    /** "user:<login>" -> id (apply), falling back to the configured default. */
    public function user_token_to_id(string $token): int {
        $login = substr($token, 5);
        if (!isset($this->userIds[$login])) {
            global $wpdb;
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} WHERE user_login = %s LIMIT 1", $login
            ));
            $this->userIds[$login] = $id ? (int) $id : 0;
        }
        if ($this->userIds[$login] > 0) {
            return $this->userIds[$login];
        }
        $fallback = $this->defaultUserId ?? 1;
        $this->warnings[] = "user '$login' not in this environment; fell back to user #$fallback";
        return $fallback;
    }

    // ---- rule-aware meta values (ref + optional cast) ----

    /**
     * Capture direction for a meta value per manifest/interpreter rule:
     * ['ref' => 'post'|'term'|'user'|'post[]'|..., 'cast' => null|'string'|'csv'].
     * 'string' preserves ids-as-strings inside serialized arrays byte-exactly
     * (ACF stores them that way); 'csv' canonicalizes a "1,2,3" string into a
     * token list re-joined on apply.
     *
     * Unmapped ids are DROPPED with a warning, never kept numeric: a raw
     * env-local id in canonical state is indistinguishable on another
     * environment from a valid id — which may resolve to an unrelated live
     * entity after auto-increment reuse (silently wrong, not just dangling).
     * Dropping converges: the corrected canonical value applies everywhere.
     * Array/csv values drop the element; a scalar ref returns null and the
     * caller skips the key (same semantics options have always had).
     */
    public function meta_value_to_tokens($value, array $rule) {
        $ref = $rule['ref'];
        $cast = $rule['cast'] ?? null;
        $one = function ($v, string $kind): ?string {
            $tok = $kind === 'user'
                ? $this->user_id_to_token((int) $v)
                : $this->id_to_token((int) $v, $kind);
            if ($tok === null) {
                $this->warnings[] = "unmapped $kind id " . (int) $v . ' dropped from ref-typed meta (dangling reference)';
            }
            return $tok;
        };
        if ($cast === 'csv') {
            $kind = rtrim($ref, '[]');
            $parts = array_values(array_filter(
                array_map('trim', explode(',', (string) $value)),
                fn($s) => $s !== ''
            ));
            return array_values(array_filter(array_map(fn($v) => $one($v, $kind), $parts)));
        }
        if (str_ends_with($ref, '[]')) {
            $kind = substr($ref, 0, -2);
            $out = [];
            foreach ((array) $value as $v) {
                $tok = $one($v, $kind);
                if ($tok !== null) {
                    $out[] = $tok;
                }
            }
            return $out;
        }
        return $one($value, $ref);
    }

    /** Apply direction for meta values; restores the declared storage shape. */
    public function meta_tokens_to_value($value, array $rule) {
        $cast = $rule['cast'] ?? null;
        $toId = function ($v): int {
            if (is_string($v) && str_starts_with($v, '{{')) {
                return $this->token_to_id($v);
            }
            if (is_string($v) && str_starts_with($v, 'user:')) {
                return $this->user_token_to_id($v);
            }
            return (int) $v;
        };
        if ($cast === 'csv') {
            return implode(',', array_map($toId, (array) $value));
        }
        if (str_ends_with($rule['ref'], '[]')) {
            $ids = array_map($toId, (array) $value);
            return $cast === 'string' ? array_map('strval', $ids) : $ids;
        }
        $id = $toId($value);
        return $cast === 'string' ? (string) $id : $id;
    }

    // ---- structured (JSON-path) refs: json_refs + key_refs ----
    // (task #11 wave 2 / docs/frontier/elementor.md, docs/frontier/
    // polylang.md's `polylang` option finding, design-review-v0 finding #9)
    //
    // Operate on an already-DECODED native structure — Capture/Apply own
    // deciding HOW to decode/re-encode the raw stored value (a JSON-text
    // string, e.g. Elementor's _elementor_data, vs. an already-native PHP
    // array from maybe_unserialize(), e.g. any ordinary WP option/meta) —
    // this class only ever sees the resulting array/scalar, the same split
    // of responsibility the rest of Tokens already has with Capture.

    /**
     * Capture direction: rewrite every position $jsonRefs declares (a list
     * of {path, kind, cast?}) from a raw id to a token, rewrite the map
     * $keyRefs declares (nullable {path?, kind}) from raw-id keys to
     * token keys, and tokenize every remaining string leaf NOT consumed by
     * either (catches incidental URLs the id-paths don't name — e.g.
     * Elementor's "url" sibling of "id" — without needing them declared;
     * see Blocks.php's block_attrs for the analogous "tokenize":"text"
     * idea, generalized here to "every leaf, by default").
     *
     * Unmapped ids: json_refs nulls the scalar (task #21 semantics — a raw
     * env-local id must never reach canonical state); key_refs drops the
     * WHOLE entry under that key (mission wording: "unmapped keys drop-
     * with-warning (whole entry)" — there is no safe partial value to keep
     * once its own identity doesn't resolve). Both warn.
     *
     * $value=0/''/null at a json_refs path is left untouched, never warned:
     * WordPress's/plugins' own "unset" convention for an id field (fresh
     * installs, unconfigured optional fields like Yoast's per-term
     * og-image) — same treatment Capture::option_ref_tokens() already
     * gives whole-option id 0.
     */
    public function struct_capture($value, array $jsonRefs, ?array $keyRefs) {
        foreach ($jsonRefs as $rule) {
            $segments = JsonRefs::parse_path($rule['path']);
            JsonRefs::walk($value, $segments, function (&$container, $key, string $locator) use ($rule) {
                $v = $container[$key];
                if (is_array($v)) {
                    return; // path resolved to a container, not a scalar id — not a valid match
                }
                $n = (int) $v;
                if ($n <= 0) {
                    return; // unset convention: leave 0/''/absent-ish values alone
                }
                $tok = $this->id_to_token($n, $rule['kind']); // warns internally if unmapped
                $container[$key] = $tok; // token string, or null (dropped) if unmapped
            }, '');
        }
        if ($keyRefs !== null) {
            $this->rewrite_keys($value, $keyRefs, true);
        }
        $this->tokenize_leaves($value, true);
        return $value;
    }

    /**
     * Apply direction mirror of struct_capture(): token -> id at each
     * json_refs path (restoring the declared numeric/string type),
     * token-keys -> id-keys for key_refs, detokenize every remaining
     * string leaf. A null left by a capture-time drop stays null (there
     * was never a valid id to restore — see struct_capture()'s docblock).
     */
    public function struct_apply($value, array $jsonRefs, ?array $keyRefs) {
        $this->tokenize_leaves($value, false);
        foreach ($jsonRefs as $rule) {
            $segments = JsonRefs::parse_path($rule['path']);
            JsonRefs::walk($value, $segments, function (&$container, $key) use ($rule) {
                $v = $container[$key];
                if ($v === null || $v === '' || is_array($v)) {
                    return;
                }
                $id = (is_string($v) && str_starts_with($v, '{{')) ? $this->token_to_id($v) : (int) $v;
                $container[$key] = (($rule['cast'] ?? null) === 'string') ? (string) $id : $id;
            }, '');
        }
        if ($keyRefs !== null) {
            $this->rewrite_keys($value, $keyRefs, false);
        }
        return $value;
    }

    /**
     * Shared by struct_capture()/struct_apply(): rewrite the KEYS of the
     * map found at $keyRefs['path'] (or, when no path is declared, of
     * $value itself — the "top level" case the mission's grammar allows).
     * PHP coerces any canonical-decimal-integer array key to int
     * automatically regardless of how the array was built, so no
     * int/string key-type bookkeeping is needed here — serialize() (on
     * apply's way back through maybe_serialize()) emits `i:N;` for an int
     * key the same way the original PHP-serialized option/meta value did.
     */
    private function rewrite_keys(&$value, array $keyRefs, bool $capture): void {
        $kind = $keyRefs['kind'];
        $rewrite = function (&$container, $key, string $locator) use ($kind, $capture) {
            $map = $container[$key];
            if (!is_array($map)) {
                return;
            }
            $out = [];
            foreach ($map as $k => $sub) {
                if ($capture) {
                    $tok = is_numeric($k) ? $this->id_to_token((int) $k, $kind) : null;
                    if ($tok === null) {
                        $this->warnings[] = "key_refs: unmapped $kind id '$k' at $locator dropped (dangling reference)";
                        continue;
                    }
                    $out[$tok] = $sub;
                } else {
                    $id = (is_string($k) && str_starts_with($k, '{{')) ? $this->token_to_id($k) : (int) $k;
                    $out[$id] = $sub;
                }
            }
            $container[$key] = $out;
        };
        if (isset($keyRefs['path'])) {
            JsonRefs::walk($value, JsonRefs::parse_path($keyRefs['path']), $rewrite, '');
            return;
        }
        // No path declared: $value's OWN keys are the ids. Wrap it so the
        // SAME closure (which expects container[$key]) applies unmodified.
        $wrapper = ['root' => $value];
        $rewrite($wrapper, 'root', '');
        $value = $wrapper['root'];
    }

    /** Recursively tokenize_text()/detokenize_text() every string leaf of
     *  an arbitrarily nested array/scalar — the whole-blob URL pass that
     *  makes a per-path "url" declaration unnecessary (see
     *  struct_capture()'s docblock). Never touches array KEYS. */
    private function tokenize_leaves(&$value, bool $capture): void {
        if (is_string($value)) {
            $value = $capture ? $this->tokenize_text($value) : $this->detokenize_text($value);
            return;
        }
        if (is_array($value)) {
            foreach ($value as &$v) {
                $this->tokenize_leaves($v, $capture);
            }
            unset($v);
        }
    }

    /**
     * Capture direction for a ref-typed value per manifest rule
     * ('post' | 'term' | 'post[]'). Unmapped ids stay numeric (warned).
     */
    public function value_to_tokens($value, string $ref) {
        if (str_ends_with($ref, '[]')) {
            $kind = substr($ref, 0, -2);
            $out = [];
            foreach ((array) $value as $v) {
                $out[] = $this->id_to_token((int) $v, $kind) ?? (int) $v;
            }
            return $out;
        }
        return $this->id_to_token((int) $value, $ref) ?? (int) $value;
    }

    /** Apply direction: restore ids (numeric) from a tokenized option/meta value. */
    public function tokens_to_value($value, string $ref) {
        if (str_ends_with($ref, '[]')) {
            $out = [];
            foreach ((array) $value as $v) {
                $out[] = is_string($v) && str_starts_with($v, '{{') ? $this->token_to_id($v) : (int) $v;
            }
            return $out;
        }
        return is_string($value) && str_starts_with($value, '{{') ? $this->token_to_id($value) : (int) $value;
    }
}

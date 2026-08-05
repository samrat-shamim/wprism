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
    }

    public function home(): string {
        return $this->home;
    }

    // ---- text (URLs) ----

    public function tokenize_text(string $s): string {
        if ($s === '') {
            return $s;
        }
        $s = str_replace($this->uploadsUrl, '{{uploads}}', $s);
        $s = str_replace($this->home, '{{home}}', $s);
        return $s;
    }

    public function detokenize_text(string $s): string {
        if ($s === '') {
            return $s;
        }
        $s = str_replace('{{uploads}}', $this->uploadsUrl, $s);
        $s = str_replace('{{home}}', $this->home, $s);
        return $s;
    }

    // ---- typed id refs ----

    private const KIND_MAP = [
        'post' => Ledger::KIND_POST,
        'term' => Ledger::KIND_TERM,
        'tt'   => Ledger::KIND_TT,
    ];

    /** id -> "{{post:uuid}}" (capture direction). Returns null when unmapped. */
    public function id_to_token(int $id, string $refKind): ?string {
        $kind = self::KIND_MAP[$refKind] ?? null;
        if ($kind === null || $id <= 0) {
            return null;
        }
        $uuid = Ledger::uuid_for($id, $kind);
        if ($uuid === null) {
            $this->warnings[] = "unmapped $refKind id $id left as-is (broken or out-of-scope reference)";
            return null;
        }
        return '{{' . $refKind . ':' . $uuid . '}}';
    }

    /** "{{post:uuid}}" -> id (apply direction). Throws when unresolvable. */
    public function token_to_id(string $token): int {
        if (!preg_match('/^\{\{(post|term|tt):([0-9a-f-]{36})\}\}$/', $token, $m)) {
            throw new \RuntimeException("duo: malformed ref token '$token'");
        }
        $id = Ledger::id_for($m[2], self::KIND_MAP[$m[1]]);
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

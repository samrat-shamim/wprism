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

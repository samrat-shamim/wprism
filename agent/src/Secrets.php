<?php
namespace Duo;

/**
 * Secret guard (DESIGN.md 3.1 "Secret guard"; finding #6's minor rider:
 * entropy detection alone underperforms pattern denylists and false-positives
 * on hashes — patterns first, heuristics only as a backstop). Two tiers:
 *
 *   - hard_match(): high-confidence vendor token shapes. A hit is a fact, not
 *     a guess — this is what ABORTS capture (Capture's authored-value guard)
 *     and what `wp duo classify --set ...=authored` refuses by default.
 *   - suspicious(): key-name-plus-shape heuristic. A weak signal only — never
 *     blocks anything by itself; it exists purely to put a flag on `wp duo
 *     pending` items so a human looks twice.
 *
 * Scope boundary (DUO-3232, stated explicitly rather than left an implicit
 * gap): this class only ever scans values Capture pulls OUT of a live
 * WordPress environment on their way INTO the repo — it has no call site
 * anywhere that reads `.duo-env-values.json` (the optional, gitignored,
 * per-environment scratch file `wp duo env-set` operators may keep next to
 * site.duo.json — see cli/README.md's "Env-bound value provisioning") and
 * never will, by construction: that file is orchestrator/operator-side and
 * is never captured, so a value living in it is never on a path this class
 * inspects. Its secrecy is guarded by a different, complementary mechanism
 * entirely — cli/src/Doctor.php's git-tracked hygiene check plus
 * sandbox/site-repo.gitignore.template — not by this scanner declining to
 * flag it.
 */
final class Secrets {
    /** Values larger than this are never scanned (cheap-scan requirement;
     *  centralized here so every call site gets it for free). */
    private const MAX_LEN = 65536;

    /** @var array<string,string> PCRE body (no delimiters) => short label */
    private const HARD_PATTERNS = [
        '\bsk_live_[A-Za-z0-9]{10,}\b' => 'stripe key',
        '\brk_live_[A-Za-z0-9]{10,}\b' => 'stripe key',
        '\b(AKIA|ASIA)[A-Z0-9]{16}\b' => 'aws key',
        '\bghp_[A-Za-z0-9]{20,}\b' => 'github token',
        '\bgho_[A-Za-z0-9]{20,}\b' => 'github token',
        '\bgithub_pat_[A-Za-z0-9_]{20,}\b' => 'github token',
        '\bxox[baprs]-[A-Za-z0-9-]{10,}\b' => 'slack token',
        '-----BEGIN [A-Z0-9 ]{0,64}PRIVATE KEY-----' => 'private key',
        '\beyJ[A-Za-z0-9_-]{4,}\.eyJ[A-Za-z0-9_-]{4,}(?:\.[A-Za-z0-9_-]+)?\b' => 'jwt',
    ];

    /** Key-name signal for the heuristic tier (never sufficient alone). */
    private const SUSPICIOUS_KEY = '/(api_?key|secret|token|passw|private_key)/i';

    /**
     * High-confidence match. Returns a short label ('stripe key', 'aws key',
     * 'github token', 'slack token', 'private key', 'jwt') or null.
     */
    public static function hard_match(string $v): ?string {
        if ($v === '' || strlen($v) > self::MAX_LEN) {
            return null;
        }
        foreach (self::HARD_PATTERNS as $pat => $label) {
            if (preg_match('/' . $pat . '/', $v)) {
                return $label;
            }
        }
        return null;
    }

    /**
     * Heuristic tier: the key NAME reads like a credential AND the value has
     * the shape of one (>=16 chars, mixed character classes — "mixed" here
     * means at least two of {lowercase, uppercase, digit, other} are
     * present, which a plain word or sentence rarely satisfies but a
     * generated token almost always does). Used only to flag `wp duo
     * pending` items for human triage — never to block by itself.
     */
    public static function suspicious(string $key, string $v): bool {
        if (strlen($v) < 16 || strlen($v) > self::MAX_LEN) {
            return false;
        }
        if (!preg_match(self::SUSPICIOUS_KEY, $key)) {
            return false;
        }
        $classes = 0;
        $classes += preg_match('/[a-z]/', $v);
        $classes += preg_match('/[A-Z]/', $v);
        $classes += preg_match('/[0-9]/', $v);
        $classes += preg_match('/[^a-zA-Z0-9]/', $v);
        return $classes >= 2;
    }

    /**
     * Recursive hard_match(): walk an array to any depth and hard_match()
     * every string leaf, short-circuiting on the first hit (returns that
     * label). DUO-3214 — added for callers that hold a VALUE of unknown
     * shape (Snapshot.php's typed-snapshot capture: a table column or
     * attached-meta value that may itself be a decoded array, not just a
     * scalar) and need the same "no secret anywhere inside this" guarantee
     * hard_match()'s existing scalar callers already get. Every existing
     * string-typed call site (Capture.php's guard_secret(), which always
     * hands hard_match() an already-known scalar) is untouched — this is a
     * new, additional entry point, not a signature change.
     *
     * Non-string, non-array leaves (int/float/bool/null) can never match a
     * pattern built entirely from PCRE over text, so they're skipped without
     * a wasted call; MAX_LEN is still enforced per-leaf, for free, since
     * every leaf ultimately calls the same hard_match($string).
     */
    public static function hard_match_deep($v): ?string {
        if (is_string($v)) {
            return self::hard_match($v);
        }
        if (is_array($v)) {
            foreach ($v as $x) {
                $label = self::hard_match_deep($x);
                if ($label !== null) {
                    return $label;
                }
            }
        }
        return null;
    }

    /**
     * Recursive suspicious(): same array walk as hard_match_deep(), for the
     * heuristic tier. $key is the OUTERMOST key (the meta key / column
     * name) — suspicious() was never nested-key-aware for scalars either
     * (it always took one flat key), so this only widens WHICH VALUES get
     * compared against that one key name; it does not re-derive a key name
     * per nesting level. Flag-only, exactly like suspicious() — never blocks.
     */
    public static function suspicious_deep(string $key, $v): bool {
        if (is_string($v)) {
            return self::suspicious($key, $v);
        }
        if (is_array($v)) {
            foreach ($v as $x) {
                if (self::suspicious_deep($key, $x)) {
                    return true;
                }
            }
        }
        return false;
    }
}

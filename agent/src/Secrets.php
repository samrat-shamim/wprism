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
        '-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----' => 'private key',
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
}

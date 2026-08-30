<?php
namespace WPrism;

/**
 * Secret guard (DESIGN.md 3.1 "Secret guard"; finding #6's minor rider:
 * entropy detection alone underperforms pattern denylists and false-positives
 * on hashes — patterns first, heuristics only as a backstop). Two tiers:
 *
 *   - hard_match(): high-confidence vendor token shapes. A hit is a fact, not
 *     a guess — this is what ABORTS capture (Capture's authored-value guard)
 *     and what `wp wprism classify --set ...=authored` refuses by default.
 *   - suspicious(): key-name-plus-shape heuristic. Pending uses it as review
 *     evidence; capture's clearance_match_deep() promotes the same signal to
 *     a refusal once the operator classifies that surface authored.
 *
 * Scope boundary (issue #3232, stated explicitly rather than left an implicit
 * gap): this class only ever scans values Capture pulls OUT of a live
 * WordPress environment on their way INTO the repo — it has no call site
 * anywhere that reads `.wprism-env-values.json`, the gitignored target-local
 * intended-value authority written by `wp wprism env-set`. That file is never
 * captured, so a value living in it is never on a path this class inspects.
 * Its secrecy is guarded by owner-only permissions plus
 * cli/src/Onboarding/Doctor.php's git-tracked hygiene check and
 * sandbox/site-repo.gitignore.template — not by this scanner declining to
 * flag it.
 */
final class Secrets {
    /** Scalar helpers stay bounded; clearance scans long values in overlapping windows. */
    private const MAX_LEN = 65536;

    /** @var array<string,string> PCRE body (no delimiters) => short label */
    private const HARD_PATTERNS = [
        '\bsk_live_[A-Za-z0-9]{4097}' => 'stripe key',
        '\bsk_live_[A-Za-z0-9]{10,4096}\b' => 'stripe key',
        '\brk_live_[A-Za-z0-9]{4097}' => 'stripe key',
        '\brk_live_[A-Za-z0-9]{10,4096}\b' => 'stripe key',
        '\b(AKIA|ASIA)[A-Z0-9]{16}\b' => 'aws key',
        '\bghp_[A-Za-z0-9]{4097}' => 'github token',
        '\bghp_[A-Za-z0-9]{20,4096}\b' => 'github token',
        '\bgho_[A-Za-z0-9]{4097}' => 'github token',
        '\bgho_[A-Za-z0-9]{20,4096}\b' => 'github token',
        '\bgithub_pat_[A-Za-z0-9_]{4097}' => 'github token',
        '\bgithub_pat_[A-Za-z0-9_]{20,4096}\b' => 'github token',
        '\bxox[baprs]-[A-Za-z0-9-]{4097}' => 'slack token',
        '\bxox[baprs]-[A-Za-z0-9-]{10,4096}\b' => 'slack token',
        // A private key is the PEM marker FOLLOWED BY key material — at least
        // one base64 line. The bare marker string is what a JOSE/crypto
        // library carries in its format check (`if (!pem.includes("-----BEGIN
        // PRIVATE KEY-----")) throw …`) and it sits inside bundled JavaScript
        // shipped by published plugins (Yoast SEO's aiFrontend.js); matching
        // the marker alone refused `wprism init` on every such site (T7 grind A4)
        // without a single key byte present.
        // Key material may follow a real newline, an escaped `\n` (a PEM
        // inside a JSON/JS string), or nothing at all.
        '-----BEGIN [A-Z0-9 ]{0,64}PRIVATE KEY-----(?:\\\\[rn]|[\r\n \t])*[A-Za-z0-9+\/=]{40,}' => 'private key',
        // A JWT is the complete three-segment compact form. A bare overlong
        // base64url blob beginning `eyJ` is common in bundled JavaScript and
        // is not a credential fact; blocking it would reject certified plugin
        // bytes without ever observing the JWT separators. Keep each segment
        // bounded so streaming callers can prove a finite overlap.
        '\beyJ[A-Za-z0-9_-]{4,8192}\.eyJ[A-Za-z0-9_-]{4,8192}\.[A-Za-z0-9_-]{1,8192}\b' => 'jwt',
    ];

    /** Key-name signal for the heuristic tier (never sufficient alone). */
    private const SUSPICIOUS_KEY = '/(^|_)(?:api_?key|authorization(?:_header)?|credentials?'
        . '|licen[cs]e_?key|secret|token|passw(?:or)?d|private_?key)$/i';

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
     * generated token almost always does). Pending remains flag-only; capture
     * deliberately consumes it through clearance_match_deep().
     */
    public static function suspicious(string $key, string $v): bool {
        if (strlen($v) < 16 || strlen($v) > self::MAX_LEN) {
            return false;
        }
        // Credential fields are terminal semantic coordinates. Substring
        // matching made `authorization_endpoint` URLs and `credential_label`
        // help copy look like secrets merely because prose mixes character
        // classes; camel/bracket normalization keeps actual apiKey/token
        // leaves covered without granting those descriptive suffixes weight.
        $key = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '_', $key) ?? $key;
        $key = preg_replace('/[^A-Za-z0-9]+/', '_', $key) ?? $key;
        $key = trim($key, '_');
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
     * label). issue #3214 — added for callers that hold a VALUE of unknown
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
     * Blocking capture scan: hard signatures, credential-shaped classified
     * keys, and labelled credentials embedded in prose. Long strings are
     * windowed rather than silently exempted by hard_match()'s cheap-scan
     * ceiling; the overlap exceeds the longest supported token signature.
     */
    public static function clearance_match_deep(string $key, $v): ?string {
        if (is_string($v)) {
            foreach (self::windows($v) as $window) {
                $hard = self::hard_match($window);
                if ($hard !== null) {
                    return $hard;
                }
                if (self::suspicious($key, $window)) {
                    return 'credential-shaped value';
                }
                if (preg_match_all(
                    '/(?:api[ _-]?key|authorization|credential|licen[cs]e[ _-]?key|secret|token|passw(?:or)?d|private[ _-]?key)'
                    . '\s*[:=]\s*["\']?(?:Bearer[ \t]+)?([A-Za-z0-9_+\/.=-]{16,4096})/i',
                    $window,
                    $matches
                )) {
                    foreach ($matches[1] as $candidate) {
                        if (self::suspicious('secret', (string) $candidate)) {
                            return 'labelled credential-shaped value';
                        }
                    }
                }
            }
            return null;
        }
        if (!is_array($v)) {
            return null;
        }
        foreach ($v as $childKey => $child) {
            $nestedKey = is_string($childKey) ? $childKey : $key;
            $label = self::clearance_match_deep($nestedKey, $child);
            if ($label !== null) {
                return $label;
            }
        }
        return null;
    }

    /** @return \Generator<int,string> */
    private static function windows(string $value): \Generator {
        $length = strlen($value);
        if ($length <= self::MAX_LEN) {
            yield $value;
            return;
        }
        for ($offset = 0; $offset < $length; $offset += 32768) {
            yield substr($value, $offset, self::MAX_LEN);
        }
    }

    /**
     * Recursive suspicious(): same array walk as hard_match_deep(), for the
     * heuristic tier. $key is the OUTERMOST key (the meta key / column
     * name) — suspicious() was never nested-key-aware for scalars either
     * (it always took one flat key), so this only widens WHICH VALUES get
     * compared against that one key name; it does not re-derive a key name
     * per nesting level. Pending uses this as evidence; clearance_match_deep()
     * is the explicit blocking consumer.
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

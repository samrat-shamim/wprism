<?php
namespace WPrism;

/**
 * Pure text substitution for the two environment-bound URL tokens,
 * `{{home}}`/`{{uploads}}`.
 *
 * Tokens owns the rest of tokenize_text()/detokenize_text() -- the
 * `{{home}}`-anchored `?p=`/`?page_id=`/`?attachment_id=` query-ref rewrite
 * that follows this substitution needs Ledger-backed id resolution and the
 * unscoped-ref classification `Tokens::$policy`/`$forceUnresolvedRefs` gate
 * (see Tokens::queue_unscoped_url_query_ref()'s own docblock), so it is not
 * a pure text operation and stays on Tokens; this collaborator is only the
 * literal URL-prefix swap, which never opens WordPress, a database, or
 * plugin code.
 */
final class TextTokenizer {
    private string $home;
    private string $uploadsUrl;
    /** JSON-escaped forms (every "/" -> "\/") of the two URLs above -- every
     *  "/" in a wp_json_encode()'d string is escaped this way when the
     *  JSON_UNESCAPED_SLASHES flag is absent, which is Elementor's own
     *  convention for _elementor_data (confirmed byte-level via `xxd`: the
     *  stored bytes are `5c 2f` — one literal backslash then "/", genuinely
     *  escaped, not a display artifact) and is legal, unremarkable JSON. */
    private string $homeEscaped;
    private string $uploadsUrlEscaped;

    public function __construct(string $home, string $uploadsUrl) {
        $this->home = $home;
        $this->uploadsUrl = $uploadsUrl;
        $this->homeEscaped = str_replace('/', '\/', $home);
        $this->uploadsUrlEscaped = str_replace('/', '\/', $uploadsUrl);
    }

    /**
     * Matches BOTH the plain form (`http://host/path`) and the JSON-escaped
     * form (`http:\/\/host\/path`) of {{home}}/{{uploads}}, collapsing both
     * to the SAME plain-spelled token -- DESIGN.md §3.3's documented-but-
     * unshipped claim ("the tokenizer also understands JSON-escaped URL
     * forms"), now actually implemented. See detokenize() for why a single
     * canonical (always-plain) token spelling is deliberately chosen over
     * trying to preserve which form each occurrence originally used.
     *
     * Only the matched substring (the URL prefix) is touched; any residual
     * escaped bytes immediately after it (e.g. the rest of an escaped path)
     * are left completely alone -- `{{uploads}}\/2026\/08\/x.png` is exactly
     * what an escaped `http:\/\/host\/wp-content\/uploads\/2026\/08\/x.png`
     * becomes, matching the shape DESIGN.md's own illustrative example uses.
     *
     * Uploads is matched before home in BOTH forms: the uploads URL is
     * normally home-prefixed (`{home}/wp-content/uploads`), so replacing
     * home first would destroy the literal substring the uploads match
     * needs -- same ordering constraint the original (plain-only) code
     * already respected.
     */
    public function tokenize(string $s): string {
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
     * Always restores the PLAIN (unescaped) form -- never the JSON-escaped
     * one -- regardless of which form the token replaced at capture. This is
     * lossless where it matters: RFC 8259 makes escaping "/" inside a JSON
     * string OPTIONAL (`/` and `\/` decode identically), so emitting the
     * plain form at a position that sits inside JSON text is still valid,
     * correctly-parseable JSON -- Elementor's own json_decode() (or any
     * conformant parser) reads it the same either way. Values that need
     * Elementor's OWN escaped-everywhere convention on the wire get it back
     * for free at the structural re-encode step (Tokens::struct_apply() /
     * Capture.php's json_refs handling), which re-escapes the WHOLE
     * reconstructed string uniformly -- not by this method trying to guess,
     * per-occurrence, whether THIS spot was originally escaped (genuinely
     * undecidable from the token alone, since both forms collapse to one
     * canonical spelling above).
     */
    public function detokenize(string $s): string {
        if ($s === '') {
            return $s;
        }
        $s = str_replace('{{uploads}}', $this->uploadsUrl, $s);
        $s = str_replace('{{home}}', $this->home, $s);
        return $s;
    }
}

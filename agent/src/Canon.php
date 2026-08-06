<?php
namespace Duo;

/**
 * Canonical serialization (spec v0): UTF-8, LF, keys sorted at every level,
 * 2-space pretty JSON, unescaped slashes/unicode, trailing newline.
 *
 * Post files are front-matter + raw body:
 *   ---\n<canonical JSON>---\n<body>\n
 * The stored body is the DB body plus exactly one trailing newline; parsing
 * strips exactly one, so the round trip is byte-exact in both directions.
 */
final class Canon {
    public static function normalize($v) {
        if (is_object($v)) {
            $arr = (array) $v;
            ksort($arr, SORT_STRING);
            $out = new \stdClass();
            foreach ($arr as $k => $x) {
                $out->$k = self::normalize($x);
            }
            return $out;
        }
        if (is_array($v)) {
            $isList = array_is_list($v);
            $out = [];
            foreach ($v as $k => $x) {
                $out[$k] = self::normalize($x);
            }
            if (!$isList) {
                ksort($out, SORT_STRING);
            }
            return $out;
        }
        return $v;
    }

    public static function encode($data): string {
        $json = json_encode(
            self::normalize($data),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            throw new \RuntimeException('duo: unencodable data: ' . json_last_error_msg());
        }
        return $json . "\n";
    }

    public static function decode(string $json) {
        $v = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('duo: invalid JSON: ' . json_last_error_msg());
        }
        return $v;
    }

    public static function post_file(array $front, string $body): string {
        return "---\n" . self::encode($front) . "---\n" . $body . "\n";
    }

    /**
     * The HASH BASIS for drift/plan comparison and Ledger's duo_state —
     * deliberately NOT the same bytes post_file() above produces. A
     * post_type may classify certain FIELDS 'derived' (Policy::field_class(),
     * task #88 — the product_variation post_title case, #72's confirmed
     * root cause): a plugin recomputes the value at ITS OWN read time,
     * hook-free, via raw $wpdb, so two environments — or a repo file vs.
     * the live environment that will apply it — can legitimately hold
     * different bytes for that ONE field while every AUTHORED byte is
     * identical. If Capture::run()/snapshot() and Apply::load_tree() hashed
     * the literal file bytes for posts the way every other entity type
     * still does, Ledger's drift check, Apply::build_plan()'s three-way
     * compare, and the env-vs-file comparison would all read pure self-heal
     * TIMING as authored update/drift/conflict — defeating the entire
     * point of the classification. Stripping a derived field from the
     * basis (an absent key, not a sentinel value — simpler, unambiguous,
     * and this return value is never written anywhere) is what keeps two
     * environments that captured a self-healing field at two different
     * moments comparing as "unchanged" against each other.
     *
     * post_file() itself is untouched by this and remains the only thing
     * that ever reaches disk: capture always writes the field's CURRENT
     * observed value verbatim (never omitted — criterion 2 of task #88's
     * acceptance criteria), so a human reading state/ still sees what the
     * title actually is right now. This function only ever feeds hash().
     *
     * Callers hand this TWO structurally different $front shapes for the
     * identical semantic entity, and it must hash them identically:
     * Capture builds $front natively, with `meta`/`terms` explicitly cast
     * (object) (Capture::build_post()); Apply::load_tree() gets $front
     * back from Canon::parse_post_file() on the REPO FILE, i.e. through
     * json_decode(..., true), which returns plain PHP arrays for every
     * JSON object it decodes. Both are semantically "{}" when empty, but
     * normalize() below re-derives JSON shape from PHP type — is_object()
     * always re-encodes as `{}`/`{...}`, while is_array() checks
     * array_is_list() and an EMPTY array is a list by PHP's own
     * definition, so it re-encodes as `[]` instead. That's invisible for
     * non-empty meta/terms (an associative array with string keys is
     * never a list either, so both shapes already converge) but very
     * visible the moment `terms` is empty — the ordinary case for a
     * product_variation, an attachment, or any adopted installer page —
     * producing a spurious hash mismatch with nothing to do with any
     * derived field (found empirically: adopted Shop/Cart/Checkout pages
     * and the WooCommerce placeholder attachment showed up in `duo plan`
     * drift right alongside the variations, despite declaring no derived
     * fields at all). Round-tripping through encode()/decode() FIRST
     * forces every empty sub-structure to the SAME PHP type (json_decode
     * always gives arrays) before either the exclusion loop or post_file()'s
     * own re-encode ever runs, so the two call sites can no longer
     * disagree about something neither of them was ever asked to exclude.
     */
    public static function post_hash_basis(array $front, string $body, Policy $policy): string {
        $front = self::decode(self::encode($front));
        $postType = (string) ($front['type'] ?? '');
        foreach (array_keys($front) as $key) {
            if ($policy->field_class($postType, (string) $key) === 'derived') {
                unset($front[$key]);
            }
        }
        return self::post_file($front, $body);
    }

    /** @return array{0: array, 1: string} [front, body] */
    public static function parse_post_file(string $text): array {
        if (!str_starts_with($text, "---\n")) {
            throw new \RuntimeException('duo: bad post file (missing front matter fence)');
        }
        $end = strpos($text, "\n---\n", 3);
        if ($end === false) {
            throw new \RuntimeException('duo: bad post file (unterminated front matter)');
        }
        $front = self::decode(substr($text, 4, $end - 3));
        $body  = substr($text, $end + 5);
        if (str_ends_with($body, "\n")) {
            $body = substr($body, 0, -1);
        }
        return [$front, $body];
    }

    public static function write_file(string $path, string $content): void {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("duo: cannot create directory $dir");
        }
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException("duo: cannot write $path");
        }
    }

    public static function read_file(string $path): string {
        $c = file_get_contents($path);
        if ($c === false) {
            throw new \RuntimeException("duo: cannot read $path");
        }
        return $c;
    }
}

<?php
namespace WPrism;

require_once __DIR__ . '/IdentityTokenCodec.php';

/**
 * Pure structural codec for WordPress's post-id URL query references.
 *
 * It operates only on already-tokenized `{{home}}` URL spans and the three
 * query parameter shapes WordPress resolves as post references: `p`,
 * `page_id`, and `attachment_id`. The caller supplies identity lookup plus
 * warning and unresolved-reference sinks, keeping this collaborator free of
 * Ledger, WordPress, Capture, policy, and persistent capture state.
 */
final class UrlQueryReferenceCodec {
    private const QUERY_PARAMETERS = 'p|page_id|attachment_id';

    /** A dynamic suffix cannot be bound as the static numeric prefix before it. */
    public static function assert_complete_fragment(string $literal, string $where): void {
        if (preg_match('/[?&](?:' . self::QUERY_PARAMETERS . ')=([^\s&#]*)$/D', $literal, $match) !== 1) return;
        $numeric = preg_replace_callback('/\{\{[^{}]*\}\}/', static function (array $part): string {
            try {
                $identity = IdentityTokenCodec::decode($part[0]);
                if ($identity['kind'] === 'post' && IdentityTokenCodec::encode('post', $identity['uuid']) === $part[0]) return '0';
            } catch (\RuntimeException) {}
            return $part[0];
        }, $match[1]);
        $reference = preg_match('/^[0-9]+$/D', $numeric) === 1;
        if ($reference) throw new \RuntimeException("wprism: $where cannot bind a query-reference prefix before a dynamic field");
    }

    /**
     * Capture direction: rewrite numeric references inside `{{home}}` URL
     * spans. An unresolved reference drops its entire separator/parameter
     * span, emits the historical warning, then reports the raw observation to
     * the caller for dangling-vs-unscoped policy triage.
     *
     * WordPress's redirect_canonical() recognizes exactly `p`, `page_id`,
     * and `attachment_id` as post-id parameters. `page` is deliberately not
     * here: it is WordPress pagination, not an entity reference. The home
     * anchor is equally deliberate: an external URL may use the same common
     * parameter names and must remain byte-identical. Relative internal URLs
     * stay outside this pre-existing absolute-URL tokenizer boundary.
     *
     * A dropped parameter leaves query separators otherwise untouched. This
     * may leave a cosmetic `?&` shape, but preserves the historical behavior
     * and is semantically equivalent for normal query parsers.
     *
     * @param callable(int):?string $idToToken
     * @param callable(string):void $warn
     * @param callable(string,string,int):void $unresolved
     */
    public static function capture(
        string $value,
        string $contextLabel,
        callable $idToToken,
        callable $warn,
        callable $unresolved
    ): string {
        if (!str_contains($value, '{{home}}')) {
            return $value;
        }
        $out = preg_replace_callback('/\{\{home\}\}[^\s"\'<>]*/', function (array $span) use (
            $contextLabel,
            $idToToken,
            $warn,
            $unresolved
        ) {
            $rewritten = preg_replace_callback(
                '/([?&])(' . self::QUERY_PARAMETERS . ')=(\d+)/',
                function (array $match) use ($contextLabel, $idToToken, $warn, $unresolved) {
                    $id = (int) $match[3];
                    $token = $idToToken($id);
                    if ($token === null) {
                        $warn("url query ref '{$match[2]}=$id' unmapped post id $id dropped (dangling reference)");
                        $unresolved($contextLabel, $match[2], $id);
                        return '';
                    }
                    return $match[1] . $match[2] . '=' . $token;
                },
                $span[0]
            );
            return $rewritten ?? $span[0];
        }, $value);
        return $out ?? $value;
    }

    /**
     * Apply direction: restore a post-id token only at the exact query-param
     * positions capture() writes. The supplied lookup deliberately throws for
     * an unavailable target rather than silently preserving a token.
     *
     * No home anchor is needed in this direction: the token at one of these
     * three exact positions is self-identifying canonical output from
     * capture(), and nothing else emits that shape.
     *
     * @param callable(string):int $tokenToId
     */
    public static function apply(string $value, callable $tokenToId): string {
        if (!str_contains($value, '{{post:')) {
            return $value;
        }
        $out = preg_replace_callback(
            '/([?&](?:' . self::QUERY_PARAMETERS . ')=)\{\{post:([0-9a-f-]{36})\}\}/',
            fn(array $match) => $match[1] . $tokenToId('{{post:' . $match[2] . '}}'),
            $value
        );
        return $out ?? $value;
    }
}

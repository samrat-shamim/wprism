<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/HtmlAttributeReader.php';
require_once __DIR__ . '/IdentityTokenCodec.php';

/** WordPress's reserved media class is an identity position, independent of the block that emits it. */
final class HtmlMediaReferences {
    /** @param callable(array{reference:int|string,suffix:string,block:string,offset:int,literal:bool}):?string $replace */
    public static function rewrite(string $html, callable $replace): string {
        $blocks = [];
        return HtmlAttributeReader::rewrite($html, ['class'],
            static function (array $attribute) use ($replace, &$blocks): ?string {
                [$value, $entities] = self::decode($attribute['value']);
                $found = preg_match_all('/(^|[\t\n\f\r ]+)([^\t\n\f\r ]+)/', $value, $words, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                if ($found === false) throw new \RuntimeException('wprism: HTML media class scan failed');
                $pieces = [];
                $cursor = 0;
                foreach ($words as $word) {
                    if (!str_starts_with($word[2][0], 'wp-image-')) continue;
                    $suffix = substr($word[2][0], 9);
                    $reference = self::reference($suffix, $attribute['offset']);
                    if ($reference === null) continue;
                    $wordStart = self::raw_offset($word[2][1], $entities);
                    $end = self::raw_offset($word[2][1] + strlen($word[2][0]), $entities);
                    $literal = substr($attribute['value'], $wordStart, $end - $wordStart) === $word[2][0];
                    $replacement = $replace(['reference' => $reference, 'suffix' => $suffix, 'literal' => $literal,
                        'block' => $blocks === [] ? 'core/freeform' : $blocks[array_key_last($blocks)], 'offset' => $attribute['offset']]);
                    if ($replacement === $suffix && $literal) continue;
                    if ($replacement !== null && self::reference($replacement, $attribute['offset']) === null) {
                        throw new \RuntimeException('wprism: HTML media replacement is outside its identity grammar');
                    }
                    $start = self::raw_offset($replacement === null ? $word[0][1] : $word[2][1], $entities);
                    $pieces[] = substr($attribute['value'], $cursor, $start - $cursor);
                    // Both admitted suffixes contain only ASCII characters
                    // valid in quoted and unquoted HTML attribute values.
                    $pieces[] = $replacement === null ? '' : 'wp-image-' . $replacement;
                    $cursor = $end;
                }
                if ($pieces === []) return null;
                $pieces[] = substr($attribute['value'], $cursor);
                return implode('', $pieces);
            },
            static function (string $comment) use (&$blocks): void {
                // Context improves the existing scope diagnostic; only HTML
                // class attributes grant rewriting authority, never comments.
                if (preg_match('~^\s+(/)?wp:([a-z][a-z0-9_-]*(?:/[a-z][a-z0-9_-]*)?)\s~', $comment, $match) !== 1) return;
                $name = str_contains($match[2], '/') ? $match[2] : 'core/' . $match[2];
                if (($match[1] ?? '') === '/') {
                    $index = array_search($name, array_reverse($blocks, true), true);
                    if ($index !== false) $blocks = array_slice($blocks, 0, $index);
                } elseif (preg_match('~/\s*$~D', $comment) !== 1) {
                    $blocks[] = $name;
                }
            });
    }

    /** @return list<array{reference:int|string,suffix:string,block:string,offset:int,literal:bool}> */
    public static function references(string $html): array {
        $references = [];
        self::rewrite($html, static function (array $reference) use (&$references): string {
            $references[] = $reference;
            return $reference['suffix'];
        });
        return $references;
    }

    // ReferenceGraph reads literal identity tokens from canonical bytes.
    // Capture normalizes encoded native spelling; immutable input must never
    // hide an edge behind HTML entities that only the apply reader decodes.
    public static function assert_canonical(string $html): void {
        foreach (self::references($html) as $reference) {
            if (is_int($reference['reference']) || !$reference['literal']) {
                throw new \RuntimeException('wprism: HTML media class at byte ' . $reference['offset'] . ' requires a canonical post token');
            }
        }
    }

    /**
     * Preserve every byte outside the reserved class token, including unusual
     * entity spelling in unrelated classes. Only semicolon-terminated named
     * references can produce the ASCII letters, braces or whitespace used by
     * this grammar; legacy semicolonless names cannot create such a boundary.
     *
     * @return array{string,list<array{int,int,int,int}>}
     */
    private static function decode(string $raw): array {
        $found = preg_match_all('/&(?:\#[xX][0-9a-fA-F]+;?|\#[0-9]+;?|[a-zA-Z][a-zA-Z0-9]*;)/', $raw, $matches, PREG_OFFSET_CAPTURE);
        if ($found === false) throw new \RuntimeException('wprism: HTML class entity scan failed');
        $pieces = [];
        $entities = [];
        $cursor = 0;
        $decodedLength = 0;
        foreach ($matches[0] as [$encoded, $offset]) {
            $before = substr($raw, $cursor, $offset - $cursor);
            $pieces[] = $before;
            $decodedLength += strlen($before);
            $closed = str_starts_with($encoded, '&#') ? rtrim($encoded, ';') . ';' : $encoded;
            $decoded = html_entity_decode($closed, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $closed) $decoded = $encoded;
            $entities[] = [$decodedLength, strlen($decoded), $offset, strlen($encoded)];
            $pieces[] = $decoded;
            $decodedLength += strlen($decoded);
            $cursor = $offset + strlen($encoded);
        }
        $pieces[] = substr($raw, $cursor);
        return [implode('', $pieces), $entities];
    }

    /** @param list<array{int,int,int,int}> $entities */
    private static function raw_offset(int $position, array $entities): int {
        $low = 0;
        $high = count($entities) - 1;
        $last = -1;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            if ($entities[$middle][0] <= $position) { $last = $middle;
            $low = $middle + 1; } else $high = $middle - 1;
        }
        if ($last < 0) return $position;
        [$decodedStart, $decodedLength, $rawStart, $rawLength] = $entities[$last];
        if ($position === $decodedStart) return $rawStart;
        if ($position < $decodedStart + $decodedLength) throw new \RuntimeException('wprism: HTML media boundary splits a character reference');
        return $rawStart + $rawLength + $position - $decodedStart - $decodedLength;
    }

    private static function reference(string $suffix, int $offset): int|string|null {
        if (preg_match('/^[0-9]+$/D', $suffix) === 1) {
            $digits = ltrim($suffix, '0');
            if ($digits === '') return 0;
            if ((string) (int) $digits !== $digits) throw new \RuntimeException("wprism: HTML media identity at byte $offset exceeds the integer range");
            return (int) $digits;
        }
        if (!str_starts_with($suffix, '{{')) return null;
        try {
            $identity = IdentityTokenCodec::decode($suffix);
            if ($identity['kind'] === 'post' && IdentityTokenCodec::encode('post', $identity['uuid']) === $suffix) return $suffix;
        } catch (\RuntimeException) {}
        throw new \RuntimeException("wprism: HTML media identity at byte $offset requires an exact post token");
    }
}

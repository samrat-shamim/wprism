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
        $valueTokens = 0;
        return HtmlAttributeReader::rewrite($html, ['class'],
            static function (array $attribute) use ($replace, &$blocks, &$valueTokens): ?string {
                [$value, $entities] = self::decode($attribute['value'], $valueTokens);
                $pieces = [];
                $cursor = 0;
                $wordCursor = 0;
                // preg_match_all allocated over 32 MiB for a 360 KB class
                // value. Streaming also lets the shared budget refuse before
                // allocating an unbounded word/entity inventory.
                while (true) {
                    $found = preg_match('/(^|[\t\n\f\r ]+)([^\t\n\f\r ]+)/', $value, $word, PREG_OFFSET_CAPTURE, $wordCursor);
                    if ($found === false) throw new \RuntimeException('wprism: HTML media class scan failed');
                    if ($found === 0) break;
                    self::consume_value_token($valueTokens);
                    $wordCursor = $word[0][1] + strlen($word[0][0]);
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
        self::rewrite($html, static function (array $reference): string {
            if (is_int($reference['reference']) || !$reference['literal']) {
                throw new \RuntimeException('wprism: HTML media class at byte ' . $reference['offset'] . ' requires a canonical post token');
            }
            return $reference['suffix'];
        });
    }

    /**
     * Preserve every byte outside the reserved class token, including unusual
     * entity spelling in unrelated classes. Only semicolon-terminated named
     * references can produce the ASCII letters, braces or whitespace used by
     * this grammar; legacy semicolonless names cannot create such a boundary.
     *
     * @return array{string,list<array{int,int,int,int}>}
     */
    private static function decode(string $raw, int &$valueTokens): array {
        $pieces = [];
        $entities = [];
        $cursor = 0;
        $decodedLength = 0;
        while (true) {
            $found = preg_match('/&(?:\#[xX][0-9a-fA-F]+;?|\#[0-9]+;?|[a-zA-Z][a-zA-Z0-9]*;)/', $raw, $match, PREG_OFFSET_CAPTURE, $cursor);
            if ($found === false) throw new \RuntimeException('wprism: HTML class entity scan failed');
            if ($found === 0) break;
            self::consume_value_token($valueTokens);
            [$encoded, $offset] = $match[0];
            $before = substr($raw, $cursor, $offset - $cursor);
            $pieces[] = $before;
            $decodedLength += strlen($before);
            $decoded = str_starts_with($encoded, '&#')
                ? self::numeric_character_reference($encoded)
                : html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $entities[] = [$decodedLength, strlen($decoded), $offset, strlen($encoded)];
            $pieces[] = $decoded;
            $decodedLength += strlen($decoded);
            $cursor = $offset + strlen($encoded);
        }
        $pieces[] = substr($raw, $cursor);
        return [implode('', $pieces), $entities];
    }

    private static function consume_value_token(int &$valueTokens): void {
        if (++$valueTokens > 100000) throw new \RuntimeException('wprism: HTML media document exceeds its value token budget');
    }

    /**
     * PHP's HTML5 decoder leaves &#13; literal, hiding a class separator.
     * HTML 13.2.5.84 instead emits that control character, maps C1 legacy
     * references and replaces invalid Unicode scalars. Bound before intval:
     * arbitrarily many source digits must never wrap into an ASCII identity.
     */
    private static function numeric_character_reference(string $encoded): string {
        $digits = rtrim(substr($encoded, 2), ';');
        $hex = ($digits[0] ?? '') === 'x' || ($digits[0] ?? '') === 'X';
        if ($hex) $digits = substr($digits, 1);
        $digits = ltrim($digits, '0');
        if (strlen($digits) > ($hex ? 6 : 7)) return "\xef\xbf\xbd";
        $point = $digits === '' ? 0 : intval($digits, $hex ? 16 : 10);
        if ($point === 0 || $point > 0x10ffff || ($point >= 0xd800 && $point <= 0xdfff)) return "\xef\xbf\xbd";
        $point = [
            0x80 => 0x20ac, 0x82 => 0x201a, 0x83 => 0x0192, 0x84 => 0x201e,
            0x85 => 0x2026, 0x86 => 0x2020, 0x87 => 0x2021, 0x88 => 0x02c6,
            0x89 => 0x2030, 0x8a => 0x0160, 0x8b => 0x2039, 0x8c => 0x0152,
            0x8e => 0x017d, 0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201c,
            0x94 => 0x201d, 0x95 => 0x2022, 0x96 => 0x2013, 0x97 => 0x2014,
            0x98 => 0x02dc, 0x99 => 0x2122, 0x9a => 0x0161, 0x9b => 0x203a,
            0x9c => 0x0153, 0x9e => 0x017e, 0x9f => 0x0178,
        ][$point] ?? $point;
        if ($point <= 0x7f) return chr($point);
        if ($point <= 0x7ff) return chr(0xc0 | ($point >> 6)) . chr(0x80 | ($point & 0x3f));
        if ($point <= 0xffff) return chr(0xe0 | ($point >> 12)) . chr(0x80 | (($point >> 6) & 0x3f)) . chr(0x80 | ($point & 0x3f));
        return chr(0xf0 | ($point >> 18)) . chr(0x80 | (($point >> 12) & 0x3f)) . chr(0x80 | (($point >> 6) & 0x3f)) . chr(0x80 | ($point & 0x3f));
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

<?php
declare(strict_types=1);

namespace WPrism;

/** Read declared opening-comment attributes without booting WordPress or rebuilding body bytes. */
final class BlockAttributeReader {
    /** @param ?list<string> $names null selects every block; [] selects none. @return list<array{blockName:string,attrs:array,offset:int}> */
    public static function read(string $body, ?array $names): array {
        if ($names === [] || $body === '') return [];
        if (strlen($body) > 16777216) throw new \RuntimeException('wprism: block attribute document exceeds 16 MiB');
        $out = [];
        $offset = 0;
        $count = 0;
        // WP's delimiter grammar requires whitespace on both sides of the
        // name. The JSON boundary is a closing brace followed by its comment
        // terminator; native serialize_block_attributes escapes embedded --.
        while (true) {
            $matched = preg_match('~<!--\s+(/)?wp:([a-z][a-z0-9_-]*(?:/[a-z][a-z0-9_-]*)?)\s+~',
                $body, $match, PREG_OFFSET_CAPTURE, $offset);
            if ($matched === false) throw new \RuntimeException('wprism: block attribute delimiter scan failed');
            if ($matched === 0) break;
            if (++$count > 100000) throw new \RuntimeException('wprism: block attribute document exceeds its delimiter budget');
            $start = $match[0][1];
            $offset = $start + strlen($match[0][0]);
            $name = str_contains($match[2][0], '/') ? $match[2][0] : 'core/' . $match[2][0];
            $selected = ($names === null || in_array($name, $names, true)) && $match[1][0] !== '/';
            $json = null;
            if (($body[$offset] ?? '') === '{') {
                if (preg_match('~}\s+/?-->~', $body, $end, PREG_OFFSET_CAPTURE, $offset) !== 1) {
                    if ($selected) throw new \RuntimeException('wprism: declared block attribute object has no delimiter terminator');
                    continue;
                }
                $json = substr($body, $offset, $end[0][1] - $offset + 1);
                $offset = $end[0][1] + strlen($end[0][0]);
            } elseif (substr($body, $offset, 3) === '-->') {
                $offset += 3;
            } elseif (substr($body, $offset, 4) === '/-->') {
                $offset += 4;
            } else {
                if ($selected) throw new \RuntimeException('wprism: declared block has a malformed attribute delimiter');
                continue;
            }
            if (!$selected) continue;
            try {
                $attrs = $json === null ? [] : json_decode($json, true, 66, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new \RuntimeException('wprism: declared block attribute object is invalid JSON');
            }
            if (!is_array($attrs) || ($attrs !== [] && array_is_list($attrs))) {
                throw new \RuntimeException('wprism: declared block attributes require an object');
            }
            $out[] = ['blockName' => $name, 'attrs' => $attrs, 'offset' => $start];
        }
        return $out;
    }

    /**
     * Native block serialization escapes inner quotes as \u0022. Scanning
     * only that framing misses a secret prefixed by the escape's final digit;
     * scan decoded attribute values as well, without rewriting repository bytes.
     */
    public static function clearance_value(string $body): string|array {
        $attributes = array_column(self::read($body, null), 'attrs');
        return $attributes === [] ? $body : [$body, $attributes];
    }
}

<?php
declare(strict_types=1);

namespace WPrism;

/** A bounded lexical pass preserves document bytes and never constructs or repairs a DOM. */
final class HtmlAttributeReader {
    /**
     * Only the first occurrence of a duplicate attribute is active in HTML.
     * Comments and raw-text elements cannot grant authority to apparent tags.
     * Callbacks return an already-escaped replacement for the exact value span,
     * or null to preserve it. They never replace attribute names or tag framing.
     *
     * @param list<string> $names
     * @param callable(array{tag:string,namespace:string,name:string,value:string,quote:string,offset:int}):?string $attribute
     * @param null|callable(string,int):void $comment
     */
    public static function rewrite(string $html, array $names, callable $attribute, ?callable $comment = null): string {
        $count = 0;
        return self::document($html, $names, $attribute, $comment, $count, 0);
    }

    /** @param list<string> $names */
    private static function document(string $html, array $names, callable $attribute, ?callable $comment, int &$count, int $depth): string {
        if ($depth > 32) throw new \RuntimeException('wprism: HTML fallback content exceeds its depth budget');
        $length = strlen($html);
        if ($length > 16777216) throw new \RuntimeException('wprism: HTML attribute document exceeds 16 MiB');
        $offset = 0;
        $edits = [];
        $elements = [];
        $watched = array_values(array_unique([...$names, 'encoding', 'color', 'face', 'size']));
        while (($start = strpos($html, '<', $offset)) !== false) {
            if (++$count > 100000) throw new \RuntimeException('wprism: HTML attribute document exceeds its token budget');
            if (substr($html, $start, 4) === '<!--') {
                if (preg_match('/\G<!--(?:>|->)|--!?>/', $html, $closing, PREG_OFFSET_CAPTURE, $start) !== 1) break;
                $end = $closing[0][1];
                if ($comment !== null && $end >= $start + 4) $comment(substr($html, $start + 4, $end - $start - 4), $start);
                $offset = $end + strlen($closing[0][0]);
                continue;
            }
            if (substr($html, $start, 9) === '<![CDATA[' && ($elements[array_key_last($elements)]['namespace'] ?? 'html') !== 'html') {
                $end = strpos($html, ']]>', $start + 9);
                if ($end === false) break;
                $offset = $end + 3;
                continue;
            }
            if (in_array($html[$start + 1] ?? '', ['!', '?'], true)) {
                $end = strpos($html, '>', $start + 2);
                if ($end === false) break;
                $offset = $end + 1;
                continue;
            }
            if (preg_match('/\G<(\/?)([a-zA-Z][^\t\n\f\r \/>]*)/', $html, $match, 0, $start) !== 1) {
                if (substr($html, $start, 2) === '</') {
                    $bogusEnd = strpos($html, '>', $start + 2);
                    if ($bogusEnd === false) break;
                    $offset = $bogusEnd + 1;
                    continue;
                }
                $offset = $start + 1;
                continue;
            }
            $tag = strtolower($match[2]);
            $cursor = $start + strlen($match[0]);
            $parsed = self::attributes($html, $cursor, $count, $watched);
            if ($parsed === null) break;
            $offset = $parsed['end'] + 1;
            if ($match[1] === '/') {
                self::close_element($elements, $tag);
                continue;
            }
            $namespace = self::namespace_for($elements, $tag, $parsed['attributes']);
            foreach ($parsed['attributes'] as $name => $span) {
                if (!in_array($name, $names, true)) continue;
                $value = substr($html, $span['offset'], $span['length']);
                $replacement = $attribute(['tag' => $tag, 'namespace' => $namespace, 'name' => $name, 'value' => $value,
                    'quote' => $span['quote'], 'offset' => $span['offset']]);
                if ($replacement !== null && $replacement !== $value) $edits[] = [$span['offset'], $span['length'], $replacement];
            }
            if ($namespace === 'html' && $tag === 'noscript') {
                // The fallback must remain portable when scripting is off,
                // but an unclosed raw-text child cannot swallow the outer
                // document that a scripting-enabled browser resumes here.
                $found = preg_match('~</noscript(?=[\t\n\f\r />])~i', $html, $closing, PREG_OFFSET_CAPTURE, $offset);
                if ($found === false) throw new \RuntimeException('wprism: HTML fallback scan failed');
                $end = $found === 1 ? $closing[0][1] : strlen($html);
                $inner = substr($html, $offset, $end - $offset);
                $base = $offset;
                $replacement = self::document($inner, $names,
                    static function (array $span) use ($attribute, $base): ?string { $span['offset'] += $base;
                    return $attribute($span); },
                    $comment === null ? null : static fn(string $text, int $position) => $comment($text, $position + $base), $count, $depth + 1);
                if ($replacement !== $inner) $edits[] = [$offset, strlen($inner), $replacement];
                $offset = $end;
                continue;
            }
            if ($namespace === 'html' && $tag === 'plaintext') break;
            if ($namespace === 'html' && in_array($tag, ['script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes'], true)) {
                // HTML raw-text closing names are ASCII-insensitive and need
                // a delimiter: </scripted> cannot end a script string.
                $pattern = '~</' . preg_quote($tag, '~') . '(?=[\t\n\f\r />])~i';
                $found = preg_match($pattern, $html, $closing, PREG_OFFSET_CAPTURE, $offset);
                if ($tag === 'script') {
                    $scriptEnd = self::script_end($html, $offset, $count);
                    if ($scriptEnd === null) break;
                    $closing = [['</script', $scriptEnd]];
                    $found = 1;
                }
                if ($found === false) throw new \RuntimeException('wprism: HTML raw-text scan failed');
                if ($found === 0) break;
                $rawEnd = self::attributes($html, $closing[0][1] + strlen($closing[0][0]), $count, []);
                if ($rawEnd === null) break;
                $offset = $rawEnd['end'] + 1;
                continue;
            }
            if (($namespace !== 'html' && !$parsed['self_closing'])
                || ($elements !== [] && $namespace === 'html' && !in_array($tag, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true))) {
                if (count($elements) >= 512) throw new \RuntimeException('wprism: HTML attribute document exceeds its element depth budget');
                $integration = $namespace === 'svg' && in_array($tag, ['foreignobject', 'desc', 'title'], true);
                if ($namespace === 'math' && $tag === 'annotation-xml' && isset($parsed['attributes']['encoding'])) {
                    $encoding = $parsed['attributes']['encoding'];
                    $integration = in_array(strtolower(html_entity_decode(substr($html, $encoding['offset'], $encoding['length']), ENT_QUOTES | ENT_HTML5, 'UTF-8')), ['text/html', 'application/xhtml+xml'], true);
                }
                $elements[] = ['tag' => $tag, 'namespace' => $namespace, 'integration' => $integration];
            }
        }
        $pieces = [];
        $cursor = 0;
        foreach ($edits as [$start, $length, $value]) {
            $pieces[] = substr($html, $cursor, $start - $cursor);
            $pieces[] = $value;
            $cursor = $start + $length;
        }
        $pieces[] = substr($html, $cursor);
        return implode('', $pieces);
    }

    /**
     * The script tokenizer has a second escape level. The first </script>
     * inside <!-- <script> only leaves that level; treating it as an end tag
     * would rewrite string literals as document markup (HTML 13.2.5.27-31).
     */
    private static function script_end(string $html, int $offset, int &$count): ?int {
        $state = 'data';
        while (true) {
            $found = preg_match('~<!--|-->|</?script(?=[\t\n\f\r />])~i', $html, $match, PREG_OFFSET_CAPTURE, $offset);
            if ($found === false) throw new \RuntimeException('wprism: HTML script scan failed');
            if ($found === 0) return null;
            if (++$count > 100000) throw new \RuntimeException('wprism: HTML attribute document exceeds its token budget');
            $event = strtolower($match[0][0]);
            if ($event === '</script') {
                if ($state !== 'double') return $match[0][1];
                $state = 'escaped';
            } elseif ($event === '<!--' && $state === 'data') $state = 'escaped';
            elseif ($event === '<script' && $state === 'escaped') $state = 'double';
            elseif ($event === '-->') $state = 'data';
            $offset = $match[0][1] + strlen($match[0][0]);
        }
    }

    /** @param list<array{tag:string,namespace:string,integration:bool}> $elements */
    private static function close_element(array &$elements, string $tag): void {
        for ($i = count($elements) - 1; $i >= 0; --$i) {
            if ($elements[$i]['tag'] === $tag) { $elements = array_slice($elements, 0, $i);
            return; }
        }
    }

    /**
     * Foreign namespaces change tokenization: SVG title admits HTML children,
     * and CDATA is text only when the current element is foreign. Track those
     * boundaries without reserializing or repairing the source tree.
     *
     * @param list<array{tag:string,namespace:string,integration:bool}> $elements
     * @param array<string,array{offset:int,length:int,quote:string}> $attributes
     */
    private static function namespace_for(array &$elements, string $tag, array $attributes): string {
        $parent = $elements[array_key_last($elements)] ?? ['tag' => '', 'namespace' => 'html', 'integration' => false];
        $html = $parent['namespace'] === 'html' || $parent['integration']
            || ($parent['namespace'] === 'math' && in_array($parent['tag'], ['mi', 'mo', 'mn', 'ms', 'mtext'], true) && !in_array($tag, ['mglyph', 'malignmark'], true));
        if (!$html && (in_array($tag, ['b', 'big', 'blockquote', 'body', 'br', 'center', 'code', 'dd', 'div', 'dl', 'dt', 'em', 'embed', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'head', 'hr', 'i', 'img', 'li', 'listing', 'menu', 'meta', 'nobr', 'ol', 'p', 'pre', 'ruby', 's', 'small', 'span', 'strong', 'strike', 'sub', 'sup', 'table', 'tt', 'u', 'ul', 'var'], true)
            || ($tag === 'font' && (isset($attributes['color']) || isset($attributes['face']) || isset($attributes['size']))))) {
            do {
                array_pop($elements);
                $parent = $elements[array_key_last($elements)] ?? ['tag' => '', 'namespace' => 'html', 'integration' => false];
            } while ($parent['namespace'] !== 'html' && !$parent['integration']
                && !($parent['namespace'] === 'math' && in_array($parent['tag'], ['mi', 'mo', 'mn', 'ms', 'mtext'], true)));
            $html = true;
        }
        if ($html) return in_array($tag, ['svg', 'math'], true) ? $tag : 'html';
        if ($parent['namespace'] === 'math' && $parent['tag'] === 'annotation-xml' && $tag === 'svg') return 'svg';
        return $parent['namespace'];
    }

    private static function space(string $char): bool {
        return str_contains("\t\n\f\r ", $char);
    }

    /**
     * Quotes start quoted values only after '='. A quote in an unquoted value
     * or attribute name is literal error-recovery text (HTML 13.2.5.33/38),
     * so a blind quote-aware search for '>' would hide subsequent real tags.
     * No callback runs for the incomplete token discarded at EOF.
     *
     * @param list<string> $names retain only requested attributes and namespace controls
     * @return null|array{end:int,self_closing:bool,attributes:array<string,array{offset:int,length:int,quote:string}>}
     */
    private static function attributes(string $html, int $cursor, int &$count, array $names): ?array {
        $length = strlen($html);
        $attributes = [];
        while ($cursor < $length) {
            while ($cursor < $length && self::space($html[$cursor])) ++$cursor;
            if ($cursor >= $length) return null;
            if ($html[$cursor] === '>') return ['end' => $cursor, 'self_closing' => false, 'attributes' => $attributes];
            if ($html[$cursor] === '/') {
                ++$cursor;
                if (($html[$cursor] ?? '') === '>') return ['end' => $cursor, 'self_closing' => true, 'attributes' => $attributes];
                continue;
            }
            $nameStart = $cursor++;
            while ($cursor < $length && !self::space($html[$cursor]) && !str_contains('=/>', $html[$cursor])) ++$cursor;
            if (++$count > 100000) throw new \RuntimeException('wprism: HTML attribute document exceeds its token budget');
            $name = strtolower(substr($html, $nameStart, $cursor - $nameStart));
            while ($cursor < $length && self::space($html[$cursor])) ++$cursor;
            $quote = '';
            $valueStart = $cursor;
            $valueLength = 0;
            if (($html[$cursor] ?? '') === '=') {
                ++$cursor;
                while ($cursor < $length && self::space($html[$cursor])) ++$cursor;
                $quote = in_array($html[$cursor] ?? '', ['"', "'"], true) ? $html[$cursor++] : '';
                $valueStart = $cursor;
                if ($quote !== '') {
                    $end = strpos($html, $quote, $cursor);
                    if ($end === false) return null;
                    $cursor = $end;
                } else {
                    while ($cursor < $length && !self::space($html[$cursor]) && $html[$cursor] !== '>') ++$cursor;
                }
                $valueLength = $cursor - $valueStart;
                if ($quote !== '') ++$cursor;
            }
            if (in_array($name, $names, true)) $attributes[$name] ??= ['offset' => $valueStart, 'length' => $valueLength, 'quote' => $quote];
        }
        return null;
    }
}

<?php
declare(strict_types=1);

namespace WPrism;

/**
 * Bounded data-only reader for generated `<?php return <literal>;` files.
 *
 * Target-generated PHP is executable input, even when a plugin intends it as
 * an array cache. Adapter providers must never `require` those bytes in the
 * privileged engine process. This parser admits only scalar/array literals,
 * binds the read to an already observed SHA-256, and rejects every callable,
 * constant, interpolation, include, or other executable PHP form.
 *
 * @phpstan-type LiteralCursor array{source:string,offset:int,phase:string,ready:bool,token:array{0:int,1:string}|string|null}
 */
final class PhpLiteralData {
    private const MAX_BYTES = 16777216;
    private const MAX_DEPTH = 64;
    private const MAX_NODES = 100000;

    // No production setter: the offline regression reaches this through
    // Reflection to mutate the named path at exact read boundaries.
    private static ?\Closure $testFileObservationHook = null;

    /** @return mixed */
    public static function read(string $path, string $expectedSha256): mixed {
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedSha256) !== 1) {
            throw new \RuntimeException('wprism: PHP literal data requires an exact SHA-256 witness');
        }
        $cursor = [
            'source' => self::read_exact_file($path, $expectedSha256),
            'offset' => 0,
            'phase' => 'opening',
            'ready' => false,
            'token' => null,
        ];
        $nodes = 0;
        self::consume_token($cursor, T_OPEN_TAG);
        self::consume_optional_abspath_guard($cursor);
        self::consume_token($cursor, T_RETURN);
        $value = self::parse_value($cursor, $nodes, 0);
        self::consume_token($cursor, ';');
        $token = self::peek_token($cursor);
        if (is_array($token) && $token[0] === T_CLOSE_TAG) {
            self::advance_token($cursor);
        }
        if (self::peek_token($cursor) !== null) {
            throw new \RuntimeException('wprism: PHP literal data contains executable or trailing syntax');
        }
        return $value;
    }

    private static function read_exact_file(string $path, string $expectedSha256): string {
        clearstatcache(true, $path);
        $named = @lstat($path);
        if (!is_array($named)
            || (((int) ($named['mode'] ?? 0)) & 0170000) !== 0100000
            || is_link($path)) {
            throw new \RuntimeException('wprism: PHP literal data source is absent, symlinked, or nonregular');
        }
        $size = $named['size'] ?? null;
        if (!is_int($size) || $size < 0 || $size > self::MAX_BYTES) {
            throw new \RuntimeException('wprism: PHP literal data exceeds its byte bound');
        }
        self::file_observation_checkpoint('after-file-stat', $path);
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('wprism: PHP literal data source is unreadable');
        }
        try {
            $opened = @fstat($handle);
            if (!is_array($opened) || !self::same_file_version($named, $opened)) {
                throw new \RuntimeException('wprism: PHP literal data source changed while being inspected');
            }
            $source = '';
            $remaining = $size;
            while ($remaining > 0) {
                $chunk = fread($handle, min(1048576, $remaining));
                if (!is_string($chunk) || $chunk === '') {
                    throw new \RuntimeException('wprism: PHP literal data source changed while being read');
                }
                $source .= $chunk;
                $remaining -= strlen($chunk);
            }
            self::file_observation_checkpoint('before-file-revalidation', $path);
            if (fseek($handle, 0) !== 0) {
                throw new \RuntimeException('wprism: PHP literal data source changed while being read');
            }
            $verifiedSource = '';
            $remaining = $size;
            while ($remaining > 0) {
                $chunk = fread($handle, min(1048576, $remaining));
                if (!is_string($chunk) || $chunk === '') {
                    throw new \RuntimeException('wprism: PHP literal data source changed while being read');
                }
                $verifiedSource .= $chunk;
                $remaining -= strlen($chunk);
            }
            $extra = fread($handle, 1);
            $after = @fstat($handle);
            clearstatcache(true, $path);
            $current = @lstat($path);
            if (!is_string($extra) || $extra !== ''
                || !is_array($after)
                || !is_array($current)
                || !self::same_file_version($opened, $after)
                || !self::same_file_version($opened, $current)
                || $source !== $verifiedSource) {
                throw new \RuntimeException('wprism: PHP literal data source changed while being read');
            }
        } finally {
            fclose($handle);
        }
        if (!hash_equals($expectedSha256, hash('sha256', $source))) {
            throw new \RuntimeException('wprism: PHP literal data source disagrees with its observed SHA-256');
        }
        return $source;
    }

    /**
     * @param LiteralCursor $cursor
     * @return mixed
     */
    private static function parse_value(array &$cursor, int &$nodes, int $depth): mixed {
        if ($depth > self::MAX_DEPTH) {
            throw new \RuntimeException('wprism: PHP literal data exceeds its depth bound');
        }
        if ($nodes >= self::MAX_NODES) {
            throw new \RuntimeException('wprism: PHP literal data exceeds its node bound');
        }
        $nodes++;
        $token = self::peek_token($cursor);
        if ($token === '[') {
            return self::parse_array($cursor, $nodes, $depth, '[', ']');
        }
        if (is_array($token) && $token[0] === T_ARRAY) {
            self::advance_token($cursor);
            return self::parse_array($cursor, $nodes, $depth, '(', ')');
        }
        if ($token === '-' || $token === '+') {
            $negative = $token === '-';
            self::advance_token($cursor);
            $number = self::peek_token($cursor);
            if ($negative === false || !is_array($number) || $number[0] !== T_LNUMBER) {
                throw new \RuntimeException('wprism: PHP literal data has a nonliteral signed value');
            }
            self::advance_token($cursor);
            $value = self::number_value($number);
            return $negative ? -$value : $value;
        }
        if (!is_array($token)) {
            throw new \RuntimeException('wprism: PHP literal data contains a nonliteral value');
        }
        self::advance_token($cursor);
        if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
            if (!str_starts_with($token[1], "'")) {
                throw new \RuntimeException('wprism: PHP literal data strings must use var_export single quotes');
            }
            return self::decode_string($token[1]);
        }
        if ($token[0] === T_LNUMBER) {
            return self::number_value($token);
        }
        if ($token[0] === T_STRING) {
            return match (strtolower($token[1])) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => throw new \RuntimeException('wprism: PHP literal data contains a named or callable value'),
            };
        }
        throw new \RuntimeException('wprism: PHP literal data contains executable syntax');
    }

    /**
     * @param LiteralCursor $cursor
     * @return array<mixed>
     */
    private static function parse_array(
        array &$cursor,
        int &$nodes,
        int $depth,
        string $open,
        string $close
    ): array {
        self::consume_token($cursor, $open);
        $result = [];
        if (self::peek_token($cursor) === $close) {
            self::advance_token($cursor);
            return $result;
        }
        while (true) {
            $first = self::parse_value($cursor, $nodes, $depth + 1);
            $next = self::peek_token($cursor);
            if (is_array($next) && $next[0] === T_DOUBLE_ARROW) {
                if (!is_int($first) && !is_string($first)) {
                    throw new \RuntimeException('wprism: PHP literal data array keys must be integers or strings');
                }
                if (is_string($first) && (string) (int) $first === $first) {
                    throw new \RuntimeException('wprism: PHP literal data refuses coercing numeric-string keys');
                }
                self::advance_token($cursor);
                $value = self::parse_value($cursor, $nodes, $depth + 1);
                if (array_key_exists($first, $result)) {
                    throw new \RuntimeException('wprism: PHP literal data repeats an array key');
                }
                $result[$first] = $value;
            } else {
                $result[] = $first;
            }
            if (self::peek_token($cursor) === $close) {
                self::advance_token($cursor);
                return $result;
            }
            self::consume_token($cursor, ',');
            if (self::peek_token($cursor) === $close) {
                self::advance_token($cursor);
                return $result;
            }
        }
    }

    /** @param array{0:int,1:string} $token */
    private static function number_value(array $token): int {
        $literal = $token[1];
        $length = strlen($literal);
        $base = 10;
        $start = 0;
        if ($length > 1 && $literal[0] === '0') {
            $prefix = strtolower($literal[1]);
            $base = match ($prefix) {
                'x' => 16,
                'b' => 2,
                default => 8,
            };
            $start = in_array($prefix, ['x', 'b', 'o'], true) ? 2 : 0;
        }
        if ($start >= $length) {
            throw new \RuntimeException('wprism: PHP literal data contains an unsupported integer spelling');
        }
        $value = 0;
        $previousDigit = false;
        for ($offset = $start; $offset < $length; $offset++) {
            $digit = $literal[$offset];
            if ($digit === '_') {
                if (!$previousDigit || $offset + 1 === $length) {
                    throw new \RuntimeException('wprism: PHP literal data contains an unsupported integer spelling');
                }
                $previousDigit = false;
                continue;
            }
            $number = strpos('0123456789abcdef', strtolower($digit));
            if (!is_int($number) || $number >= $base) {
                throw new \RuntimeException('wprism: PHP literal data contains an unsupported integer spelling');
            }
            if ($value > intdiv(PHP_INT_MAX - $number, $base)) {
                // PHP classifies overflowing positive tokens as T_DNUMBER,
                // even beneath unary minus. Do not clamp them into integers.
                throw new \RuntimeException('wprism: PHP literal data contains executable syntax');
            }
            $value = $value * $base + $number;
            $previousDigit = true;
        }
        return $value;
    }

    private static function decode_string(string $bytes): string {
        if (strlen($bytes) < 2) {
            throw new \RuntimeException('wprism: PHP literal data contains a malformed string');
        }
        $quote = $bytes[0];
        $inner = substr($bytes, 1, -1);
        if ($quote !== "'") {
            throw new \RuntimeException('wprism: PHP literal data contains a non-var_export string');
        }
        return str_replace(['\\\\', "\\'"], ['\\', "'"], $inner);
    }

    /** @param LiteralCursor $cursor */
    private static function consume_optional_abspath_guard(array &$cursor): void {
        $token = self::peek_token($cursor);
        if (!is_array($token) || $token[0] !== T_IF) {
            return;
        }
        self::consume_token($cursor, T_IF);
        self::consume_token($cursor, '(');
        self::consume_token($cursor, '!');
        $defined = self::peek_token($cursor);
        if (!is_array($defined)
            || $defined[0] !== T_STRING
            || strtolower($defined[1]) !== 'defined') {
            throw new \RuntimeException('wprism: PHP literal data contains a noncanonical preamble');
        }
        self::advance_token($cursor);
        self::consume_token($cursor, '(');
        $constant = self::peek_token($cursor);
        if (!is_array($constant)
            || $constant[0] !== T_CONSTANT_ENCAPSED_STRING
            || !str_starts_with($constant[1], "'")
            || self::decode_string($constant[1]) !== 'ABSPATH') {
            throw new \RuntimeException('wprism: PHP literal data contains a noncanonical preamble');
        }
        self::advance_token($cursor);
        self::consume_token($cursor, ')');
        self::consume_token($cursor, ')');
        self::consume_token($cursor, '{');
        self::consume_token($cursor, T_RETURN);
        self::consume_token($cursor, ';');
        self::consume_token($cursor, '}');
    }

    private static function file_observation_checkpoint(string $phase, string $path): void {
        if (self::$testFileObservationHook !== null) {
            (self::$testFileObservationHook)($phase, $path);
        }
    }

    /**
     * @param LiteralCursor $cursor
     * @return array{0:int,1:string}|string|null
     */
    private static function peek_token(array &$cursor): array|string|null {
        if (!$cursor['ready']) {
            $cursor['token'] = self::scan_token($cursor);
            $cursor['ready'] = true;
        }
        return $cursor['token'];
    }

    /** @param LiteralCursor $cursor */
    private static function advance_token(array &$cursor): void {
        $cursor['token'] = null;
        $cursor['ready'] = false;
    }

    /**
     * Consume only the next token needed by the closed literal parser.
     *
     * token_get_all() inflated a 10 MB, two-million-null array past 512 MiB
     * before the 100000-node refusal could run. Single-token lookahead keeps
     * allocation proportional to the bounded source and accepted value, not
     * to all later tokens. Trivia never becomes a PHP string or token array.
     *
     * @param LiteralCursor $cursor
     * @return array{0:int,1:string}|string|null
     */
    private static function scan_token(array &$cursor): array|string|null {
        $source = $cursor['source'];
        $length = strlen($source);
        $offset = &$cursor['offset'];
        if ($cursor['phase'] === 'opening') {
            if (strncasecmp($source, '<?php', 5) !== 0
                || ($length > 5 && !str_contains(" \t\r\n", $source[5]))) {
                throw new \RuntimeException('wprism: PHP literal data does not match the closed return-literal grammar');
            }
            $offset = 5;
            $cursor['phase'] = 'php';
            return [T_OPEN_TAG, '<?php'];
        }
        if ($cursor['phase'] === 'closed') {
            if ($offset !== $length) {
                throw new \RuntimeException('wprism: PHP literal data contains executable or trailing syntax');
            }
            return null;
        }
        while ($offset < $length) {
            $offset += strspn($source, " \t\r\n", $offset);
            if ($offset === $length) {
                return null;
            }
            $byte = $source[$offset];
            $next = $source[$offset + 1] ?? '';
            if ($byte === '/' && $next === '*') {
                $end = strpos($source, '*/', $offset + 2);
                // Like PHP's tokenizer, an EOF block comment is trivia; it
                // cannot satisfy a still-missing literal or semicolon.
                $offset = $end === false ? $length : $end + 2;
                continue;
            }
            if (($byte === '/' && $next === '/') || ($byte === '#' && $next !== '[')) {
                $offset += $byte === '#' ? 1 : 2;
                while ($offset < $length
                    && $source[$offset] !== "\r" && $source[$offset] !== "\n"
                    && !($source[$offset] === '?' && ($source[$offset + 1] ?? '') === '>')) {
                    $offset++;
                }
                continue;
            }
            break;
        }
        if ($offset === $length) {
            return null;
        }
        $start = $offset;
        $byte = $source[$offset++];
        if ($byte === '?' && ($source[$offset] ?? '') === '>') {
            $offset++;
            // PHP's closing token absorbs exactly one CR, LF, or CRLF.
            // Every subsequent byte is inline HTML, including whitespace.
            if (($source[$offset] ?? '') === "\r") {
                $offset++;
                if (($source[$offset] ?? '') === "\n") {
                    $offset++;
                }
            } elseif (($source[$offset] ?? '') === "\n") {
                $offset++;
            }
            $cursor['phase'] = 'closed';
            return [T_CLOSE_TAG, '?>'];
        }
        if ($byte === "'") {
            while ($offset < $length) {
                if ($source[$offset] === '\\') {
                    $offset += 2;
                    continue;
                }
                if ($source[$offset++] === "'") {
                    return [T_CONSTANT_ENCAPSED_STRING, substr($source, $start, $offset - $start)];
                }
            }
            throw new \RuntimeException('wprism: PHP literal data contains a malformed string');
        }
        if ($byte >= '0' && $byte <= '9') {
            $offset += strspn($source, '0123456789abcdefABCDEFxXoObB_.eE', $offset);
            return [T_LNUMBER, substr($source, $start, $offset - $start)];
        }
        if (str_contains('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_', $byte)) {
            $offset += strspn($source, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_0123456789', $offset);
            $word = substr($source, $start, $offset - $start);
            $kind = match (strtolower($word)) {
                'array' => T_ARRAY,
                'if' => T_IF,
                'return' => T_RETURN,
                default => T_STRING,
            };
            return [$kind, $word];
        }
        if ($byte === '=' && ($source[$offset] ?? '') === '>') {
            $offset++;
            return [T_DOUBLE_ARROW, '=>'];
        }
        if (str_contains('[](){},;!-+', $byte)) {
            return $byte;
        }
        throw new \RuntimeException('wprism: PHP literal data contains executable syntax');
    }

    /** @param LiteralCursor $cursor */
    private static function consume_token(array &$cursor, int|string $expected): void {
        $token = self::peek_token($cursor);
        $matches = is_int($expected)
            ? is_array($token) && $token[0] === $expected
            : $token === $expected;
        if (!$matches) {
            throw new \RuntimeException('wprism: PHP literal data does not match the closed return-literal grammar');
        }
        self::advance_token($cursor);
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private static function same_file_version(array $left, array $right): bool {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            if (!isset($left[$field], $right[$field])
                || !is_int($left[$field])
                || !is_int($right[$field])
                || $left[$field] !== $right[$field]) {
                return false;
            }
        }
        return (((int) $left['mode']) & 0170000) === 0100000;
    }
}

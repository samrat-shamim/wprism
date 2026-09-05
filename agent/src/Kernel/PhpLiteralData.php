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
        $source = self::read_exact_file($path, $expectedSha256);
        $tokens = token_get_all($source);
        $offset = 0;
        $nodes = 0;
        self::skip_trivia($tokens, $offset);
        self::consume_token($tokens, $offset, T_OPEN_TAG);
        self::consume_optional_abspath_guard($tokens, $offset);
        self::skip_trivia($tokens, $offset);
        self::consume_token($tokens, $offset, T_RETURN);
        $value = self::parse_value($tokens, $offset, $nodes, 0);
        self::skip_trivia($tokens, $offset);
        self::consume_token($tokens, $offset, ';');
        self::skip_trivia($tokens, $offset);
        if (isset($tokens[$offset]) && is_array($tokens[$offset]) && $tokens[$offset][0] === T_CLOSE_TAG) {
            $offset++;
            self::skip_trivia($tokens, $offset);
        }
        if ($offset !== count($tokens)) {
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
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return mixed
     */
    private static function parse_value(array $tokens, int &$offset, int &$nodes, int $depth): mixed {
        if ($depth > self::MAX_DEPTH) {
            throw new \RuntimeException('wprism: PHP literal data exceeds its depth bound');
        }
        if ($nodes >= self::MAX_NODES) {
            throw new \RuntimeException('wprism: PHP literal data exceeds its node bound');
        }
        $nodes++;
        self::skip_trivia($tokens, $offset);
        $token = $tokens[$offset] ?? null;
        if ($token === '[') {
            return self::parse_array($tokens, $offset, $nodes, $depth, '[', ']');
        }
        if (is_array($token) && $token[0] === T_ARRAY) {
            $offset++;
            self::skip_trivia($tokens, $offset);
            return self::parse_array($tokens, $offset, $nodes, $depth, '(', ')');
        }
        if ($token === '-' || $token === '+') {
            $negative = $token === '-';
            $offset++;
            self::skip_trivia($tokens, $offset);
            $number = $tokens[$offset] ?? null;
            if ($negative === false || !is_array($number) || $number[0] !== T_LNUMBER) {
                throw new \RuntimeException('wprism: PHP literal data has a nonliteral signed value');
            }
            $offset++;
            $value = self::number_value($number);
            return $negative ? -$value : $value;
        }
        if (!is_array($token)) {
            throw new \RuntimeException('wprism: PHP literal data contains a nonliteral value');
        }
        $offset++;
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
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return array<mixed>
     */
    private static function parse_array(
        array $tokens,
        int &$offset,
        int &$nodes,
        int $depth,
        string $open,
        string $close
    ): array {
        self::consume_token($tokens, $offset, $open);
        $result = [];
        self::skip_trivia($tokens, $offset);
        if (($tokens[$offset] ?? null) === $close) {
            $offset++;
            return $result;
        }
        while (true) {
            $first = self::parse_value($tokens, $offset, $nodes, $depth + 1);
            self::skip_trivia($tokens, $offset);
            $next = $tokens[$offset] ?? null;
            if (is_array($next) && $next[0] === T_DOUBLE_ARROW) {
                if (!is_int($first) && !is_string($first)) {
                    throw new \RuntimeException('wprism: PHP literal data array keys must be integers or strings');
                }
                if (is_string($first) && (string) (int) $first === $first) {
                    throw new \RuntimeException('wprism: PHP literal data refuses coercing numeric-string keys');
                }
                $offset++;
                $value = self::parse_value($tokens, $offset, $nodes, $depth + 1);
                if (array_key_exists($first, $result)) {
                    throw new \RuntimeException('wprism: PHP literal data repeats an array key');
                }
                $result[$first] = $value;
            } else {
                $result[] = $first;
            }
            self::skip_trivia($tokens, $offset);
            if (($tokens[$offset] ?? null) === $close) {
                $offset++;
                return $result;
            }
            self::consume_token($tokens, $offset, ',');
            self::skip_trivia($tokens, $offset);
            if (($tokens[$offset] ?? null) === $close) {
                $offset++;
                return $result;
            }
        }
    }

    /** @param array{0:int,1:string,2:int} $token */
    private static function number_value(array $token): int {
        $bytes = str_replace('_', '', $token[1]);
        if (preg_match('/^0[oO]([0-7]+)$/D', $bytes, $octal) === 1) {
            return intval($octal[1], 8);
        }
        if (preg_match('/^(?:0|[1-9][0-9]*|0[xX][0-9a-fA-F]+|0[bB][01]+|0[0-7]+)$/D', $bytes) !== 1) {
            throw new \RuntimeException('wprism: PHP literal data contains an unsupported integer spelling');
        }
        return intval($bytes, 0);
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

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function consume_optional_abspath_guard(array $tokens, int &$offset): void {
        self::skip_trivia($tokens, $offset);
        $token = $tokens[$offset] ?? null;
        if (!is_array($token) || $token[0] !== T_IF) {
            return;
        }
        self::consume_token($tokens, $offset, T_IF);
        self::consume_token($tokens, $offset, '(');
        self::consume_token($tokens, $offset, '!');
        self::skip_trivia($tokens, $offset);
        $defined = $tokens[$offset] ?? null;
        if (!is_array($defined)
            || $defined[0] !== T_STRING
            || strtolower($defined[1]) !== 'defined') {
            throw new \RuntimeException('wprism: PHP literal data contains a noncanonical preamble');
        }
        $offset++;
        self::consume_token($tokens, $offset, '(');
        self::skip_trivia($tokens, $offset);
        $constant = $tokens[$offset] ?? null;
        if (!is_array($constant)
            || $constant[0] !== T_CONSTANT_ENCAPSED_STRING
            || !str_starts_with($constant[1], "'")
            || self::decode_string($constant[1]) !== 'ABSPATH') {
            throw new \RuntimeException('wprism: PHP literal data contains a noncanonical preamble');
        }
        $offset++;
        self::consume_token($tokens, $offset, ')');
        self::consume_token($tokens, $offset, ')');
        self::consume_token($tokens, $offset, '{');
        self::consume_token($tokens, $offset, T_RETURN);
        self::consume_token($tokens, $offset, ';');
        self::consume_token($tokens, $offset, '}');
    }

    private static function file_observation_checkpoint(string $phase, string $path): void {
        if (self::$testFileObservationHook !== null) {
            (self::$testFileObservationHook)($phase, $path);
        }
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function skip_trivia(array $tokens, int &$offset): void {
        while (isset($tokens[$offset])
            && is_array($tokens[$offset])
            && in_array($tokens[$offset][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $offset++;
        }
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function consume_token(array $tokens, int &$offset, int|string $expected): void {
        self::skip_trivia($tokens, $offset);
        $token = $tokens[$offset] ?? null;
        $matches = is_int($expected)
            ? is_array($token) && $token[0] === $expected
            : $token === $expected;
        if (!$matches) {
            throw new \RuntimeException('wprism: PHP literal data does not match the closed return-literal grammar');
        }
        $offset++;
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

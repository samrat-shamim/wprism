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

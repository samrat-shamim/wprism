<?php
namespace WPrism;

/** The single manifest JSON-reference path grammar/parser. */
final class ReferencePath {
    /** @return array<int,array{type:'child'|'desc'|'wild',key:?string}> */
    public static function parse(string $path): array {
        $path = trim($path);
        if (!str_starts_with($path, '$')) {
            throw new \RuntimeException("wprism: json_refs/key_refs path '$path' must start with '\$'");
        }
        $rest = substr($path, 1);
        $segments = [];
        while ($rest !== '') {
            if (str_starts_with($rest, '..')) {
                if (!preg_match('/^\.\.([A-Za-z0-9_-]+)/', $rest, $match)) {
                    throw new \RuntimeException("wprism: bad '..' segment in json_refs/key_refs path '$path'");
                }
                $segments[] = ['type' => 'desc', 'key' => $match[1]];
                $rest = substr($rest, strlen($match[0]));
            } elseif (str_starts_with($rest, '.*')) {
                $segments[] = ['type' => 'wild', 'key' => null];
                $rest = substr($rest, 2);
            } elseif (str_starts_with($rest, '.')) {
                if (!preg_match('/^\.([A-Za-z0-9_-]+)/', $rest, $match)) {
                    throw new \RuntimeException("wprism: bad '.' segment in json_refs/key_refs path '$path'");
                }
                $segments[] = ['type' => 'child', 'key' => $match[1]];
                $rest = substr($rest, strlen($match[0]));
            } else {
                throw new \RuntimeException("wprism: bad json_refs/key_refs path syntax '$path' near '$rest'");
            }
        }
        if ($segments === []) {
            throw new \RuntimeException(
                "wprism: json_refs/key_refs path '$path' names no segments (bare '\$' — declare at least one)"
            );
        }
        return $segments;
    }
}

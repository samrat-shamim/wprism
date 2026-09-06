<?php
declare(strict_types=1);

namespace WPrism;

/** Translate one logical scalar string into WordPress's raw option bytes. */
final class WordPressOptionValueCodec {
    /**
     * WordPress double-serializes a string that already looks serialized so
     * get_option() returns the original string rather than changing its type.
     * maybe_serialize() is a pure Core codec: it invokes no plugin hooks and
     * therefore remains safe before the exact writer's isolated transaction.
     */
    public static function encode_scalar_string(string $value): string {
        if (!\function_exists('maybe_serialize')) {
            throw new \RuntimeException(
                'wprism: WordPress option scalar encoding is unavailable'
            );
        }
        $encoded = \maybe_serialize($value);
        if (!is_string($encoded)) {
            throw new \RuntimeException(
                'wprism: WordPress option scalar encoding returned malformed bytes'
            );
        }
        return $encoded;
    }
}

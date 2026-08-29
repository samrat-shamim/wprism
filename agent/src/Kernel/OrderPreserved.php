<?php
namespace WPrism;

/**
 * Marker wrapper for issue #3214(b) / task #123: a value whose PHP array key
 * order is semantically load-bearing and must survive Canon::normalize()'s
 * usual alphabetical ksort() untouched. See Canon::normalize()'s own
 * docblock for the full mechanism; this class only ever exists transiently
 * in memory between Capture::build_post() constructing a post's front
 * matter and the FIRST Canon::encode()/normalize() call that consumes it
 * (Canon::post_file() or Canon::post_hash_basis()) — normalize() unwraps
 * it immediately, so nothing downstream of that point (JSON on disk,
 * Apply's json_decode(), any other code) ever sees or needs to know about
 * this type. Not meant to be constructed anywhere outside the entity-meta
 * capture path.
 */
final class OrderPreserved {
    /** @var mixed */
    public $value;

    /** @param mixed $value */
    public function __construct($value) {
        $this->value = $value;
    }
}

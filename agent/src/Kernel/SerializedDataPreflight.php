<?php
declare(strict_types=1);

namespace WPrism;

/** Reject enum records before PHP can invoke an autoloader during decoding. */
final class SerializedDataPreflight {
    public const MAX_DEPTH = 256;

    /** PHP itself owns ordinary framing; enum records are screened first. */
    public static function decode(string $bytes, string $context, bool $allowStdClass = false): mixed {
        self::assert_no_enum($bytes, $context, self::MAX_DEPTH);
        return @unserialize($bytes, ['allowed_classes' => $allowStdClass ? [\stdClass::class] : false]);
    }

    private static function assert_no_enum(string $bytes, string $context, int $maxDepth): void {
        // allowed_classes does not govern E records (PHP manual, enum
        // serialization). Length-framed strings may contain the same bytes;
        // rejecting a substring would refuse ordinary authored text.
        if (!str_contains($bytes, 'E:')) return;
        $reader = new self($bytes, $context, $maxDepth);
        $reader->value(0);
        if ($reader->offset !== strlen($bytes)) {
            throw new \RuntimeException("wprism: $context contains trailing or noncanonical PHP-serialized data");
        }
    }

    private int $offset = 0;

    private function __construct(private string $bytes, private string $context, private int $maxDepth) {}

    private function value(int $depth): void {
        if ($depth > $this->maxDepth) {
            throw new \RuntimeException(
                "wprism: serialized data in {$this->context} is nested too deeply; refusing recursive/reference-shaped input"
            );
        }
        $tag = $this->bytes[$this->offset++] ?? '';
        if ($tag === 'E') {
            throw new \RuntimeException(
                "wprism: non-plain serialized data (PHP enum) in {$this->context} — refusing before autoload"
            );
        }
        if ($tag === 'N') {
            $this->expect(';');
            return;
        }
        $this->expect(':');
        if (in_array($tag, ['b', 'i', 'd', 'R', 'r'], true)) {
            $end = strpos($this->bytes, ';', $this->offset);
            if ($end === false) $this->malformed();
            // Scalar spelling and reference topology remain PHP/PlainData's
            // existing round-trip checks; no scalar payload can load code.
            $payload = substr($this->bytes, $this->offset, $end - $this->offset);
            if (!preg_match('/^(?:[-+0-9.eE]+|NAN|-?INF)$/D', $payload)) $this->malformed();
            $this->offset = $end + 1;
            return;
        }
        if ($tag === 's') {
            $this->string(';');
            return;
        }
        if ($tag === 'a') {
            $count = $this->count();
        } elseif ($tag === 'O' || $tag === 'C') {
            $this->string(':');
            $count = $this->count();
            if ($tag === 'C') {
                // A Serializable payload is opaque to PHP's outer parser.
                // allowed_classes=false (or stdClass-only) still prevents
                // its class from being instantiated by either caller.
                $this->expect('{');
                $this->skip($count);
                $this->expect('}');
                return;
            }
        } else {
            $this->malformed();
        }
        $this->expect('{');
        for ($entry = 0; $entry < $count; ++$entry) {
            $this->value($depth + 1);
            $this->value($depth + 1);
        }
        $this->expect('}');
    }

    private function string(string $terminator): void {
        $length = $this->count();
        $this->expect('"');
        $this->skip($length);
        $this->expect('"' . $terminator);
    }

    private function count(): int {
        $end = strpos($this->bytes, ':', $this->offset);
        if ($end === false) $this->malformed();
        $value = substr($this->bytes, $this->offset, $end - $this->offset);
        if (!preg_match('/^(?:0|[1-9][0-9]*)$/D', $value)
            || strlen($value) > strlen((string) strlen($this->bytes))
            || (int) $value > strlen($this->bytes)) $this->malformed();
        $this->offset = $end + 1;
        return (int) $value;
    }

    private function skip(int $length): void {
        if ($length > strlen($this->bytes) - $this->offset) $this->malformed();
        $this->offset += $length;
    }

    private function expect(string $value): void {
        if (substr($this->bytes, $this->offset, strlen($value)) !== $value) $this->malformed();
        $this->offset += strlen($value);
    }

    private function malformed(): never {
        throw new \RuntimeException(
            "wprism: {$this->context} contains malformed or noncanonical PHP-serialized data"
        );
    }
}

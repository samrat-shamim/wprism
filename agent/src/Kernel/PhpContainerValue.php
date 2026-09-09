<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/SerializedDataPreflight.php';
require_once __DIR__ . '/KeyBoundStrings.php';

/** Portable, ordered PHP arrays and stdClass values without executable classes. */
final class PhpContainerValue {
    public const FEATURE = 'php-container-values/v1';
    public const FIELD = 'php_containers';
    public const FORMAT = 'wprism-php-containers/v1';
    public const MAX_DEPTH = 64;
    public const MAX_NODES = 100000;
    public const MAX_BYTES = 16777216;

    public static function declaration_grammar(): array {
        return [
            'field' => self::FIELD, 'value' => true, 'surfaces' => ['options', 'option_patterns'],
            'class' => 'authored', 'static_only' => true, 'format' => self::FORMAT,
            'containers' => ['array', 'stdClass'], 'ordered_key_refs_container' => 'php',
            'float_encoding' => 'tagged canonical PHP decimal',
            'max_depth' => self::MAX_DEPTH, 'max_nodes' => self::MAX_NODES, 'max_bytes' => self::MAX_BYTES,
        ];
    }

    public static function uses(array $rule): bool {
        if (array_key_exists(self::FIELD, $rule)
            || (is_array($rule['key_refs'] ?? null) && (array_key_exists('container', $rule['key_refs']) || array_key_exists(KeyBoundStrings::FIELD, $rule['key_refs'])))) return true;
        foreach ((array) ($rule['sub_keys'] ?? []) as $child) {
            if (is_array($child) && self::uses($child)) return true;
        }
        return false;
    }

    public static function assert_rule(array $rule, string $context, bool $allowed): void {
        if (!array_key_exists(self::FIELD, $rule)
            && !(is_array($rule['key_refs'] ?? null) && (array_key_exists('container', $rule['key_refs']) || array_key_exists(KeyBoundStrings::FIELD, $rule['key_refs'])))) return;
        if (!$allowed) self::refuse($context, 'PHP containers belong only to whole authored options in a v3 adapter declaring ' . self::FEATURE);
        if (($rule[self::FIELD] ?? null) !== true || ($rule['class'] ?? null) !== 'authored') {
            self::refuse($context, 'must declare php_containers: true and class: authored');
        }
        foreach (['ref', 'cast', 'json_encoded', 'sub_keys', 'repeated_rows', 'order_preserving'] as $incompatible) {
            if (array_key_exists($incompatible, $rule)) self::refuse($context, "PHP containers cannot combine with $incompatible");
        }
        if (empty($rule['plain_data']) && empty($rule['json_refs']) && empty($rule['key_refs'])) {
            self::refuse($context, 'PHP containers require plain_data or structured reference declarations');
        }
        if (isset($rule['key_refs']) && ($rule['key_refs']['container'] ?? null) !== 'php') {
            self::refuse($context, 'PHP container key_refs must declare container: php to preserve ordering');
        }
        if (isset($rule['key_refs']) && !isset($rule['key_refs']['path'])) {
            self::refuse($context, 'PHP container key_refs requires a path to a typed node inside the document');
        }
    }

    /** Scope exclusions remain valid; a site override cannot change storage types. */
    public static function assert_site_override(array $declared, array $override, string $context): void {
        if (!array_key_exists(self::FIELD, $declared)) return;
        if (in_array($override['class'] ?? null, ['runtime', 'derived', 'env'], true)
            && !array_key_exists('sub_keys', $override)) return;
        self::refuse($context, 'cannot replace a manifest PHP container codec with authored site policy; exclude the whole option or retain its manifest value contract');
    }

    /**
     * Canonical JSON alone cannot retain an associative PHP array versus a
     * stdClass, or the order Canon deliberately sorts away. Every container
     * therefore has explicit kind, order and items. All payload containers
     * are wrapped, so authored keys can never impersonate codec metadata.
     * Existing reference paths address this ordinary JSON representation.
     * A typed key_refs map rewrites its items and order as one value, so a
     * dangling-key drop cannot leave a stale ordering entry behind.
     */
    public static function capture_raw(string $raw, string $context): array {
        if (strlen($raw) > self::MAX_BYTES) self::refuse($context, 'serialized container exceeds its byte bound');
        $trimmed = trim($raw);
        $native = SerializedDataPreflight::decode($trimmed, $context, true);
        if (!is_array($native) && !($native instanceof \stdClass)) {
            self::refuse($context, 'requires one serialized PHP array or stdClass');
        }
        $state = ['nodes' => 0, 'bytes' => 0, 'objects' => []];
        $root = self::pack($native, $context, 0, $state);
        // Only already-checked acyclic plain containers reach serialize().
        // No object supplied by storage can run __serialize or __sleep here.
        if (serialize($native) !== $trimmed) self::refuse($context, 'has trailing or noncanonical PHP serialization');
        return ['format' => self::FORMAT, 'root' => $root];
    }

    /** Reconstruct only data containers, after generic reference materialization. */
    public static function restore(mixed $value, string $context): array|\stdClass {
        if (!is_array($value) || !self::fields($value, ['format', 'root'])
            || $value['format'] !== self::FORMAT || !is_array($value['root'])) {
            self::refuse($context, 'requires a closed ' . self::FORMAT . ' document');
        }
        $state = ['nodes' => 0, 'bytes' => 0];
        $native = self::unpack($value['root'], $context, 0, $state);
        if (!is_array($native) && !($native instanceof \stdClass)) {
            self::refuse($context, 'root must be a container');
        }
        return $native;
    }

    /** Immutable revisions are checked without WordPress, callbacks or a ledger. */
    public static function assert_canonical(mixed $value, string $context): void {
        self::restore($value, $context);
    }

    /** The map view used by generic reference validation and lint. */
    public static function map(mixed $node, string $context): array {
        self::restore(['format' => self::FORMAT, 'root' => $node], $context);
        return $node['items'];
    }

    /**
     * @param callable(int|string):(int|string|null) $rewrite null drops the
     * complete entry, preserving key_refs' existing dangling-reference rule.
     * @param ?callable(mixed,int|string,int|string):mixed $rewriteValue pure entry transform
     */
    public static function rewrite_keys(mixed $node, callable $rewrite, string $context, ?callable $rewriteValue = null): array {
        $map = self::map($node, $context);
        $items = [];
        $order = [];
        $state = ['bytes' => 0];
        foreach ($node['order'] as $position) {
            $oldKey = $position['key'];
            $key = $rewrite($oldKey);
            if ($key === null) continue;
            $key = $node['kind'] === 'stdClass' ? (string) $key : $key;
            self::key($key, $node['kind'], $context, $state);
            if (array_key_exists($key, $items)) self::refuse($context, 'reference rewrite collided with another container key');
            $items[$key] = $rewriteValue === null ? $map[$oldKey] : $rewriteValue($map[$oldKey], $oldKey, $key);
            $order[] = ['key' => $key];
        }
        $result = ['kind' => $node['kind'], 'order' => $order, 'items' => $items];
        if ($rewriteValue !== null) self::map($result, $context);
        return $result;
    }

    private static function pack(mixed $value, string $context, int $depth, array &$state): mixed {
        self::bound($depth, $context, $state);
        if (!is_array($value) && !is_object($value)) {
            self::scalar($value, $context, $state);
            // Canon's ordinary JSON intentionally does not preserve 2.0
            // versus 2 or the sign of -0.0. A tagged decimal spelling keeps
            // those native PHP scalar types without changing legacy JSON.
            if (is_float($value)) return ['kind' => 'float', 'value' => substr(serialize($value), 2, -1)];
            return $value;
        }
        $kind = 'array';
        $members = $value;
        if (is_object($value)) {
            if (get_class($value) !== \stdClass::class) self::refuse($context, 'contains an executable or incomplete PHP class');
            $identity = spl_object_id($value);
            if (isset($state['objects'][$identity])) self::refuse($context, 'contains shared or recursive PHP objects');
            $state['objects'][$identity] = true;
            $kind = 'stdClass';
            $members = get_object_vars($value);
        }
        $order = [];
        $items = [];
        foreach ($members as $key => $child) {
            if (\ReflectionReference::fromArrayElement($members, $key) !== null) {
                self::refuse($context, 'contains a PHP reference or recursive array');
            }
            $key = $kind === 'stdClass' ? (string) $key : $key;
            self::key($key, $kind, $context, $state);
            $order[] = ['key' => $key];
            $items[$key] = self::pack($child, $context, $depth + 1, $state);
        }
        return ['kind' => $kind, 'order' => $order, 'items' => $items];
    }

    private static function unpack(mixed $value, string $context, int $depth, array &$state): mixed {
        self::bound($depth, $context, $state);
        if (!is_array($value)) {
            self::scalar($value, $context, $state);
            if (is_float($value)) self::refuse($context, 'floating-point values require an explicit float node');
            return $value;
        }
        if (($value['kind'] ?? null) === 'float') {
            if (!self::fields($value, ['kind', 'value']) || !is_string($value['value'])) {
                self::refuse($context, 'has a malformed float node');
            }
            $number = (float) $value['value'];
            if (!is_finite($number) || serialize($number) !== 'd:' . $value['value'] . ';') {
                self::refuse($context, 'float spelling is not canonical for PHP');
            }
            self::scalar($value['value'], $context, $state);
            return $number;
        }
        if (!self::fields($value, ['items', 'kind', 'order'])
            || !in_array($value['kind'], ['array', 'stdClass'], true)
            || !is_array($value['order']) || !array_is_list($value['order'])
            || !is_array($value['items']) || count($value['order']) !== count($value['items'])) {
            self::refuse($context, 'has a malformed typed container');
        }
        $kind = $value['kind'];
        $native = $kind === 'stdClass' ? new \stdClass() : [];
        $seen = [];
        foreach ($value['order'] as $position) {
            if (!is_array($position) || !self::fields($position, ['key'])) {
                self::refuse($context, 'container order entries must contain exactly key');
            }
            $key = $position['key'];
            self::key($key, $kind, $context, $state);
            if (isset($seen[$key]) || !array_key_exists($key, $value['items'])) {
                self::refuse($context, 'container order must name every item exactly once');
            }
            $seen[$key] = true;
            $child = self::unpack($value['items'][$key], $context, $depth + 1, $state);
            if ($native instanceof \stdClass) {
                $native->{$key} = $child;
            } else {
                $native[$key] = $child;
            }
        }
        return $native;
    }

    private static function key(mixed $key, string $kind, string $context, array &$state): void {
        if (!is_int($key) && !is_string($key)) self::refuse($context, 'container keys must be integers or strings');
        if ($kind === 'stdClass') {
            if (!is_string($key) || str_contains($key, "\0")) self::refuse($context, 'stdClass properties must be unmangled string names');
        } else {
            // PHP coerces a canonical decimal string array key to an int.
            // Accepting a different key type in order would make recapture
            // change the supposedly canonical document after materializing.
            $probe = [$key => true];
            if (array_key_first($probe) !== $key) self::refuse($context, 'array key type is not canonical for PHP');
        }
        self::scalar($key, $context, $state);
    }

    private static function scalar(mixed $value, string $context, array &$state): void {
        if (is_string($value)) {
            $state['bytes'] += strlen($value);
            if ($state['bytes'] > self::MAX_BYTES || preg_match('//u', $value) !== 1) {
                self::refuse($context, 'text exceeds its byte bound or is not UTF-8');
            }
            return;
        }
        if ($value === null || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) return;
        self::refuse($context, 'contains a nonportable scalar or an untagged object');
    }

    private static function bound(int $depth, string $context, array &$state): void {
        if ($depth > self::MAX_DEPTH || ++$state['nodes'] > self::MAX_NODES) {
            self::refuse($context, 'container depth or node count exceeds its bound');
        }
    }

    private static function fields(array $value, array $expected): bool {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        return $keys === $expected;
    }

    private static function refuse(string $context, string $reason): never {
        throw new \RuntimeException("wprism: $context $reason");
    }
}

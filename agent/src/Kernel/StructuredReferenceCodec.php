<?php
namespace WPrism;

require_once __DIR__ . '/JsonRefs.php';
require_once __DIR__ . '/ReferenceCondition.php';
require_once __DIR__ . '/PhpContainerValue.php';
require_once __DIR__ . '/IdentityTokenCodec.php';
require_once __DIR__ . '/KeyBoundStrings.php';

/**
 * Pure structural codec for manifest-declared json_refs and key_refs.
 *
 * The caller supplies environment-bound identity lookup and warning sinks.
 * That keeps this collaborator free of Ledger, WordPress, Capture, policy,
 * and plugin code while preserving Tokens as the compatibility facade for
 * its stateful text-leaf and unscoped-reference handling.  This codec owns
 * only the declared scalar/key rewrite protocol and its historical ordering.
 */
final class StructuredReferenceCodec {
    /** A declared container codec changes only the selected map's framing. */
    public static function key_ref_map($value, array $rule, string $context) {
        $map = ($rule['container'] ?? null) === 'php' ? PhpContainerValue::map($value, $context) : $value;
        if (array_key_exists(KeyBoundStrings::FIELD, $rule)) KeyBoundStrings::assert_map($map, $rule, $context);
        return $map;
    }

    /**
     * Capture direction: rewrite declared scalar ids and id-keyed maps.
     *
     * @param callable(int,string):?string $idToToken
     * @param callable(string):void $warn
     */
    public static function capture(
        $value,
        array $jsonRefs,
        ?array $keyRefs,
        callable $idToToken,
        callable $warn
    ) {
        foreach ($jsonRefs as $rule) {
            $segments = JsonRefs::parse_path($rule['path']);
            JsonRefs::walk($value, $segments, function (&$container, $key, string $locator) use (
                $rule,
                $idToToken,
                $warn
            ): void {
                if (!ReferenceCondition::matches($container, $rule, $locator)) return;
                $current = $container[$key];
                ReferenceCondition::assert_native($current, $rule, $locator);
                if (is_array($current)) {
                    return; // A declared path resolved to a container, not a scalar id.
                }
                $id = (int) $current;
                if ($id <= 0) {
                    return; // Preserve WordPress's unset 0/empty/null convention.
                }
                $kind = (string) $rule['kind'];
                $token = $idToToken($id, $kind);
                if ($token === null) {
                    $warn("json_refs path '$locator': unmapped $kind id $id dropped (dangling reference)");
                }
                // A missing mapping is a null canonical value, never a raw id.
                $container[$key] = $token;
            }, '');
        }
        if ($keyRefs !== null) {
            self::rewriteKeys($value, $keyRefs, true, $idToToken, null, $warn);
        }
        return $value;
    }

    /**
     * Apply direction: restore declared scalar ids and id-keyed maps.
     *
     * @param callable(string):int $tokenToId
     */
    public static function apply($value, array $jsonRefs, ?array $keyRefs, callable $tokenToId) {
        foreach ($jsonRefs as $rule) {
            $segments = JsonRefs::parse_path($rule['path']);
            JsonRefs::walk($value, $segments, function (&$container, $key, string $locator) use ($rule, $tokenToId): void {
                if (!ReferenceCondition::matches($container, $rule, $locator)) return;
                $current = $container[$key];
                ReferenceCondition::assert_canonical($current, $rule, $locator);
                if ($current === null || $current === '' || is_array($current)) {
                    return;
                }
                $id = (is_string($current) && str_starts_with($current, '{{'))
                    ? $tokenToId($current)
                    : (int) $current;
                $container[$key] = (($rule['cast'] ?? null) === 'string') ? (string) $id : $id;
            }, '');
        }
        if ($keyRefs !== null) {
            self::rewriteKeys($value, $keyRefs, false, null, $tokenToId, null);
        }
        return $value;
    }

    /**
     * Rewrite the keys of a declared map.  The wrapper keeps the top-level
     * form on the identical callback path as a path-addressed map.
     *
     * @param ?callable(int,string):?string $idToToken
     * @param ?callable(string):int $tokenToId
     * @param ?callable(string):void $warn
     */
    private static function rewriteKeys(
        &$value,
        array $keyRefs,
        bool $capture,
        ?callable $idToToken,
        ?callable $tokenToId,
        ?callable $warn
    ): void {
        $kind = (string) $keyRefs['kind'];
        $rewrite = function (&$container, $key, string $locator) use (
            $capture,
            $idToToken,
            $tokenToId,
            $warn,
            $kind,
            $keyRefs
        ): void {
            if (($keyRefs['container'] ?? null) === 'php') {
                if (array_key_exists(KeyBoundStrings::FIELD, $keyRefs)) self::key_ref_map($container[$key], $keyRefs, $locator);
                $container[$key] = PhpContainerValue::rewrite_keys(
                    $container[$key],
                    static function ($mapKey) use ($capture, $idToToken, $tokenToId, $warn, $kind, $locator) {
                        if ($capture) {
                            if (!((is_int($mapKey) && $mapKey > 0)
                                || (is_string($mapKey) && preg_match('/^[1-9][0-9]*$/D', $mapKey)
                                    && (string) (int) $mapKey === $mapKey))) {
                                throw new \RuntimeException("wprism: typed key_refs map $locator requires canonical positive integer keys");
                            }
                            $token = $idToToken((int) $mapKey, $kind);
                            if ($token === null) $warn("key_refs: unmapped $kind id '$mapKey' at $locator dropped (dangling reference)");
                            return $token;
                        }
                        $identity = null;
                        if (is_string($mapKey)) {
                            try { $identity = IdentityTokenCodec::decode($mapKey); } catch (\RuntimeException) {}
                        }
                        if ($identity === null || $identity['kind'] !== $kind
                            || IdentityTokenCodec::encode($identity['kind'], $identity['uuid']) !== $mapKey) {
                            throw new \RuntimeException("wprism: typed key_refs map $locator requires tokens in its declared keyspace");
                        }
                        $id = $tokenToId($mapKey);
                        if (!is_int($id) || $id <= 0) {
                            throw new \RuntimeException("wprism: typed key_refs map $locator resolved an invalid local id");
                        }
                        return $id;
                    },
                    "typed key_refs map $locator",
                    array_key_exists(KeyBoundStrings::FIELD, $keyRefs)
                        ? static fn($entry, $oldKey, $newKey) => KeyBoundStrings::rewrite_entry($entry, $oldKey, $newKey, $keyRefs, $locator)
                        : null
                );
                return;
            }
            $map = $container[$key];
            if (!is_array($map)) {
                return;
            }
            $out = [];
            foreach ($map as $mapKey => $entry) {
                if ($capture) {
                    $token = is_numeric($mapKey) ? $idToToken((int) $mapKey, $kind) : null;
                    if ($token === null) {
                        $warn("key_refs: unmapped $kind id '$mapKey' at $locator dropped (dangling reference)");
                        continue;
                    }
                    $out[$token] = $entry;
                    continue;
                }
                $id = (is_string($mapKey) && str_starts_with($mapKey, '{{'))
                    ? $tokenToId($mapKey)
                    : (int) $mapKey;
                $out[$id] = $entry;
            }
            $container[$key] = $out;
        };
        if (isset($keyRefs['path'])) {
            JsonRefs::walk($value, JsonRefs::parse_path($keyRefs['path']), $rewrite, '');
            return;
        }
        $wrapper = ['root' => $value];
        $rewrite($wrapper, 'root', '');
        $value = $wrapper['root'];
    }
}

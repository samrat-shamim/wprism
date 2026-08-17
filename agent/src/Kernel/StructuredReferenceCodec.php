<?php
namespace Duo;

require_once __DIR__ . '/JsonRefs.php';

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
                $current = $container[$key];
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
            JsonRefs::walk($value, $segments, function (&$container, $key) use ($rule, $tokenToId): void {
                $current = $container[$key];
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
            $kind
        ): void {
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

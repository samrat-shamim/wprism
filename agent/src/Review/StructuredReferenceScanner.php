<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/JsonRefs.php';
require_once __DIR__ . '/Pending.php';
require_once __DIR__ . '/LintFinding.php';

/**
 * Pure traversal for the structured-reference half of the suspicious-ref
 * linter.  Lint owns filesystem traversal and finding orchestration; this
 * collaborator owns only declared json_refs/key_refs positions plus the
 * deliberately narrow undeclared key heuristic.  The resolver is injected so
 * this class never opens WordPress or a database itself.
 */
final class StructuredReferenceScanner {
    /**
     * @param callable(int):(?array) $resolveId
     * @return list<array{class:string,path:string,locator:string,value:mixed,matches?:array,note:string}>
     */
    public static function scan(
        $node,
        string $rel,
        string $locator,
        array $jsonRefs = [],
        ?array $keyRefs = null,
        ?callable $resolveId = null
    ): array {
        $resolveId ??= static fn(int $id): ?array => Pending::resolve_id($id);
        $findings = [];
        $declaredLocators = [];
        foreach ($jsonRefs as $rule) {
            $copy = $node;
            JsonRefs::walk(
                $copy,
                JsonRefs::parse_path((string) $rule['path']),
                function (&$container, $key, string $matchedLocator) use (
                    &$declaredLocators, &$findings, $rel, $rule, $resolveId
                ): void {
                    $declaredLocators[$matchedLocator] = true;
                    $value = $container[$key];
                    if (is_array($value)) {
                        return;
                    }
                    foreach (Pending::numeric_candidates($value) as [$id, $locSuffix]) {
                        if ($id <= 0) {
                            continue;
                        }
                        $hit = $resolveId($id);
                        $kind = (string) ($rule['kind'] ?? 'entity');
                        $findings[] = LintFinding::make(
                            'unrewritten_registered_ref',
                            $rel,
                            $matchedLocator . $locSuffix,
                            $id,
                            $hit,
                            "json_refs path '" . (string) $rule['path'] . "' declares this value as a $kind "
                                . 'reference, but it is still numeric in captured state — the declared rewrite '
                                . 'to a {{...}} token never ran. This id is silently environment-bound and will '
                                . 'point at the wrong entity (or nothing) once ids diverge on another environment.'
                        );
                    }
                },
                $locator
            );
        }

        $declaredKeyLocators = [];
        if ($keyRefs !== null) {
            $scanDeclaredMap = function ($map, string $mapLocator) use (
                &$declaredKeyLocators, &$findings, $rel, $keyRefs, $resolveId
            ): void {
                if (!is_array($map) || array_is_list($map)) {
                    return;
                }
                foreach ($map as $key => $_value) {
                    if (!(is_int($key) || (is_string($key) && preg_match('/^[1-9][0-9]*$/', $key)))) {
                        continue;
                    }
                    $id = (int) $key;
                    $rawLocator = "$mapLocator KEY $key";
                    $declaredKeyLocators[$rawLocator] = true;
                    $kind = (string) $keyRefs['kind'];
                    $findings[] = LintFinding::make(
                        'unrewritten_registered_ref',
                        $rel,
                        $rawLocator,
                        $id,
                        $resolveId($id),
                        "key_refs declares this map key as a $kind reference, but it is still numeric in "
                            . 'captured state — the declared rewrite to a {{...}} token never ran'
                    );
                }
            };
            if (isset($keyRefs['path'])) {
                $copy = $node;
                JsonRefs::walk(
                    $copy,
                    JsonRefs::parse_path((string) $keyRefs['path']),
                    function (&$container, $key, string $matchedLocator) use ($scanDeclaredMap): void {
                        $scanDeclaredMap($container[$key], $matchedLocator);
                    },
                    $locator
                );
            } else {
                $scanDeclaredMap($node, $locator);
            }
        }

        self::scanUndeclared(
            $node,
            $rel,
            $locator,
            $findings,
            $declaredLocators,
            $declaredKeyLocators,
            $resolveId
        );
        return $findings;
    }

    /** @param list<array> $findings @param array<string,bool> $declaredLocators @param array<string,bool> $declaredKeyLocators */
    private static function scanUndeclared(
        $node,
        string $rel,
        string $locator,
        array &$findings,
        array $declaredLocators,
        array $declaredKeyLocators,
        callable $resolveId
    ): void {
        if (!is_array($node)) {
            return;
        }
        $isList = array_is_list($node);
        foreach ($node as $key => $v) {
            $childLocator = is_int($key) ? "{$locator}[{$key}]" : "{$locator}.{$key}";
            if (is_int($key) && !$isList && !isset($declaredKeyLocators["$locator KEY $key"])) {
                $hit = $resolveId($key);
                if ($hit !== null) {
                    $findings[] = LintFinding::make('bare_id', $rel, "$locator KEY $key", $key, $hit, sprintf(
                        "this structured value has an integer ARRAY KEY that matches an existing %s id "
                        . "(#%d \"%s\", %s), with no declared key_refs path covering it — an id-keyed map "
                        . "(an associative array whose integer KEYS are themselves entity ids) is exactly the "
                        . "shape key_refs exists to rewrite; a resolved key_refs match is never still a raw integer key by this point, "
                        . "so this is a genuine gap, not a false read. Small ids coincide; this is a signal to "
                        . "investigate, not proof.",
                        $hit['kind'], $hit['id'], $hit['title'], $hit['post_type']
                    ));
                }
            } elseif (is_string($key)
                && self::looksLikeIdKey($key)
                && !isset($declaredLocators[$childLocator])) {
                foreach (Pending::numeric_candidates($v) as [$id, $locSuffix]) {
                    $hit = $resolveId($id);
                    if ($hit === null) {
                        continue;
                    }
                    $findings[] = LintFinding::make('bare_id', $rel, $childLocator . $locSuffix, $id, $hit, sprintf(
                        "key '%s' inside a json_refs/key_refs-declared structure looks like an id (matches the "
                        . "id/ids/ref/*Id/*Ids naming heuristic) and its value coincides with an existing %s id "
                        . "(#%d \"%s\", %s), but no declared json_refs path covers this exact position — a "
                        . "resolved json_refs match is never still a raw number by this point (it becomes a "
                        . "token, or null if unmapped), so this is a genuine manifest gap, not a false read. "
                        . "Small ids coincide; this is a signal to investigate, not proof.",
                        $key, $hit['kind'], $hit['id'], $hit['title'], $hit['post_type']
                    ));
                }
            }
            self::scanUndeclared($v, $rel, $childLocator, $findings, $declaredLocators, $declaredKeyLocators, $resolveId);
        }
    }

    private static function looksLikeIdKey(string $key): bool {
        // Keep this byte-for-byte aligned with Lint's historical structured
        // key predicate: the hyphenated `...-image-id` family is a shipped
        // Yoast shape, while the case-preserved suffix covers camelCase keys.
        return $key === 'id'
            || $key === 'ids'
            || $key === 'ref'
            || (bool) preg_match('/([-_][iI][dD]s?|(Id|ID)s?)$/', $key);
    }

}

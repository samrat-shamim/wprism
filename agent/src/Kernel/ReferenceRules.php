<?php
namespace Duo;

require_once __DIR__ . '/ReferencePath.php';

/**
 * Manifest-time normalization and validation for the shared structural-ref
 * vocabulary. Runtime consumers receive one shape and never re-interpret a
 * plugin declaration independently.
 */
final class ReferenceRules {
    private const KIND_RE = '/^[a-z][a-z0-9_]{0,15}$/';

    /** One source of truth for attached EAV key ownership and behavior. */
    public static function attached_meta_key(array $declaration, string $key): array {
        $rules = (array) ($declaration['keys'] ?? []);
        return $rules[$key] ?? [
            'class' => (string) ($declaration['default_class'] ?? 'authored'),
        ];
    }

    /**
     * Preserve the shipped flat-map shorthand while exposing the ordinary
     * json_refs/key_refs rule to every runtime consumer.
     *
     * @return array{json_refs:array,key_refs:?array,legacy_flat_map:bool}
     */
    public static function description(mixed $declaration, string $where): array {
        if (!is_array($declaration) || array_is_list($declaration)) {
            throw new \RuntimeException("duo: $where must be an object");
        }
        if (array_key_exists('kind', $declaration)) {
            if (array_diff_key($declaration, ['kind' => true])) {
                throw new \RuntimeException(
                    "duo: $where legacy kind shorthand cannot be mixed with json_refs/key_refs or other fields"
                );
            }
            self::assert_kind($declaration['kind'], "$where.kind");
            return [
                'json_refs' => [['path' => '$.*', 'kind' => (string) $declaration['kind']]],
                'key_refs' => null,
                'legacy_flat_map' => true,
            ];
        }
        if (array_diff_key($declaration, ['json_refs' => true, 'key_refs' => true])) {
            throw new \RuntimeException("duo: $where accepts only json_refs and key_refs in full form");
        }
        self::validate_structured($declaration, $where, true);
        return [
            'json_refs' => array_values((array) ($declaration['json_refs'] ?? [])),
            'key_refs' => isset($declaration['key_refs']) ? $declaration['key_refs'] : null,
            'legacy_flat_map' => false,
        ];
    }

    /** Validate an ordinary option/meta/attached-meta rule's ref fields. */
    public static function value_rule(array $rule, string $where): void {
        $structured = array_key_exists('json_refs', $rule) || array_key_exists('key_refs', $rule);
        if (array_key_exists('cast', $rule)
            && (!is_string($rule['cast']) || !in_array($rule['cast'], ['string', 'csv'], true))) {
            throw new \RuntimeException("duo: $where.cast must be 'string' or 'csv'");
        }
        foreach (['allow_secret', 'order_preserving', 'plain_data'] as $booleanField) {
            if (array_key_exists($booleanField, $rule) && !is_bool($rule[$booleanField])) {
                throw new \RuntimeException("duo: $where.$booleanField must be a boolean");
            }
        }
        if (!empty($rule['plain_data'])
            && (array_key_exists('cast', $rule) || array_key_exists('json_encoded', $rule))) {
            throw new \RuntimeException(
                "duo: $where plain_data cannot combine with cast or json_encoded; it preserves native PHP plain data"
            );
        }
        if (array_key_exists('ref', $rule)) {
            if ($structured || !empty($rule['plain_data'])) {
                throw new \RuntimeException(
                    "duo: $where cannot combine scalar ref with json_refs/key_refs or plain_data; the ownership is ambiguous"
                );
            }
            $ref = $rule['ref'];
            if (!is_string($ref)) {
                throw new \RuntimeException("duo: $where.ref must name a reference keyspace");
            }
            $kind = str_ends_with($ref, '[]') ? substr($ref, 0, -2) : $ref;
            self::assert_kind($kind, "$where.ref");
        }
        if ($structured) {
            if (!empty($rule['plain_data'])) {
                throw new \RuntimeException(
                    "duo: $where cannot combine json_refs/key_refs with plain_data; the ownership is ambiguous"
                );
            }
            self::validate_structured($rule, $where, true);
            if (array_key_exists('cast', $rule)) {
                throw new \RuntimeException(
                    "duo: $where.cast is ambiguous for a structured value; put cast on each json_refs entry"
                );
            }
        }
        if (array_key_exists('json_encoded', $rule)) {
            if (!is_bool($rule['json_encoded'])) {
                throw new \RuntimeException("duo: $where.json_encoded must be a boolean");
            }
            if (!$structured) {
                throw new \RuntimeException("duo: $where.json_encoded requires json_refs or key_refs");
            }
        }
    }

    /** @return string[] referenced keyspace names */
    public static function kinds(array $rule): array {
        $out = [];
        if (isset($rule['ref']) && is_string($rule['ref'])) {
            $out[] = rtrim($rule['ref'], '[]');
        }
        foreach ((array) ($rule['json_refs'] ?? []) as $ref) {
            if (is_array($ref) && isset($ref['kind'])) {
                $out[] = (string) $ref['kind'];
            }
        }
        if (is_array($rule['key_refs'] ?? null) && isset($rule['key_refs']['kind'])) {
            $out[] = (string) $rule['key_refs']['kind'];
        }
        return array_values(array_unique($out));
    }

    /** @param string[] $allowed */
    public static function assert_keyspaces(array $rule, array $allowed, string $where): void {
        $kinds = [];
        if (isset($rule['ref']) && is_string($rule['ref'])) {
            $scalarKind = rtrim($rule['ref'], '[]');
            if ($scalarKind !== 'user') {
                $kinds[] = $scalarKind;
            }
        }
        foreach ((array) ($rule['json_refs'] ?? []) as $ref) {
            if (is_array($ref) && isset($ref['kind'])) {
                $kinds[] = (string) $ref['kind'];
            }
        }
        if (is_array($rule['key_refs'] ?? null) && isset($rule['key_refs']['kind'])) {
            $kinds[] = (string) $rule['key_refs']['kind'];
        }
        foreach (array_values(array_unique($kinds)) as $kind) {
            if (!in_array($kind, $allowed, true)) {
                throw new \RuntimeException(
                    "duo: $where declares unknown reference keyspace '$kind'; expected post, term, tt, "
                    . 'or an authored_snapshot table id_kind'
                );
            }
        }
    }

    private static function validate_structured(array $rule, string $where, bool $requireRef): void {
        $jsonRefs = $rule['json_refs'] ?? [];
        if (!is_array($jsonRefs) || !array_is_list($jsonRefs)) {
            throw new \RuntimeException("duo: $where.json_refs must be a list");
        }
        $paths = [];
        foreach ($jsonRefs as $i => $ref) {
            if (!is_array($ref) || array_is_list($ref)
                || array_diff_key($ref, ['path' => true, 'kind' => true, 'cast' => true])) {
                throw new \RuntimeException(
                    "duo: $where.json_refs[$i] must be an object containing only path, kind, and optional cast"
                );
            }
            if (!is_string($ref['path'] ?? null)) {
                throw new \RuntimeException("duo: $where.json_refs[$i].path must be a string");
            }
            self::assert_kind($ref['kind'] ?? null, "$where.json_refs[$i].kind");
            if (isset($ref['cast']) && $ref['cast'] !== 'string') {
                throw new \RuntimeException(
                    "duo: $where.json_refs[$i].cast must be 'string' when present"
                );
            }
            $segments = ReferencePath::parse($ref['path']);
            foreach ($paths as $prior) {
                if (self::paths_overlap($prior['segments'], $segments)) {
                    throw new \RuntimeException(
                        "duo: $where has ambiguous overlapping json_refs paths '{$prior['path']}' and '{$ref['path']}'"
                    );
                }
            }
            $paths[] = ['path' => $ref['path'], 'segments' => $segments];
        }

        $keyRefs = $rule['key_refs'] ?? null;
        if ($keyRefs !== null) {
            if (!is_array($keyRefs) || array_is_list($keyRefs)
                || array_diff_key($keyRefs, ['path' => true, 'kind' => true])) {
                throw new \RuntimeException(
                    "duo: $where.key_refs must be an object containing kind and optional path"
                );
            }
            self::assert_kind($keyRefs['kind'] ?? null, "$where.key_refs.kind");
            if (array_key_exists('path', $keyRefs)) {
                if (!is_string($keyRefs['path'])) {
                    throw new \RuntimeException("duo: $where.key_refs.path must be a string");
                }
                $keySegments = ReferencePath::parse($keyRefs['path']);
                foreach ($paths as $valuePath) {
                    if (self::path_can_be_ancestor($valuePath['segments'], $keySegments)) {
                        throw new \RuntimeException(
                            "duo: $where json_refs path '{$valuePath['path']}' is equal to or an ancestor of "
                            . "key_refs path '{$keyRefs['path']}'; one location cannot be both a scalar "
                            . 'reference and an id-keyed map'
                        );
                    }
                }
            }
        }
        if ($requireRef && $jsonRefs === [] && $keyRefs === null) {
            throw new \RuntimeException("duo: $where must declare at least one json_refs or key_refs path");
        }
    }

    private static function assert_kind(mixed $kind, string $where): void {
        if (!is_string($kind) || !preg_match(self::KIND_RE, $kind)) {
            throw new \RuntimeException(
                "duo: $where must be a lowercase reference keyspace name of at most 16 characters"
            );
        }
    }

    /**
     * JsonRefs paths are regular languages over object-key sequences:
     * child consumes one exact key, wildcard consumes any key, and desc
     * consumes any number of keys followed by its named key. Intersect the
     * two small NFAs so wildcard/recursive aliases of the same leaf refuse
     * at manifest load instead of rewriting one value twice at runtime.
     */
    private static function paths_overlap(array $left, array $right): bool {
        $queue = [[0, 0]];
        $seen = [];
        while ($queue) {
            [$li, $ri] = array_shift($queue);
            $state = "$li:$ri";
            if (isset($seen[$state])) {
                continue;
            }
            $seen[$state] = true;
            if ($li === count($left) && $ri === count($right)) {
                return true;
            }
            foreach (self::path_transitions($left, $li) as [$llabel, $lnext]) {
                foreach (self::path_transitions($right, $ri) as [$rlabel, $rnext]) {
                    if ($llabel === null || $rlabel === null || $llabel === $rlabel) {
                        $queue[] = [$lnext, $rnext];
                    }
                }
            }
        }
        return false;
    }

    /**
     * Whether some node selected by $ancestor can be the same node as, or a
     * parent of, a node selected by $descendant. This is the same product-NFA
     * walk as paths_overlap(), except the left path accepting while the right
     * still has segments is success rather than requiring both to accept.
     */
    private static function path_can_be_ancestor(array $ancestor, array $descendant): bool {
        $queue = [[0, 0]];
        $seen = [];
        while ($queue) {
            [$ai, $di] = array_shift($queue);
            $state = "$ai:$di";
            if (isset($seen[$state])) {
                continue;
            }
            $seen[$state] = true;
            if ($ai === count($ancestor)) {
                return true;
            }
            foreach (self::path_transitions($ancestor, $ai) as [$alabel, $anext]) {
                foreach (self::path_transitions($descendant, $di) as [$dlabel, $dnext]) {
                    if ($alabel === null || $dlabel === null || $alabel === $dlabel) {
                        $queue[] = [$anext, $dnext];
                    }
                }
            }
        }
        return false;
    }

    /** @return array<int,array{0:?string,1:int}> null label means any key */
    private static function path_transitions(array $segments, int $i): array {
        if ($i >= count($segments)) {
            return [];
        }
        $segment = $segments[$i];
        if ($segment['type'] === 'wild') {
            return [[null, $i + 1]];
        }
        if ($segment['type'] === 'desc') {
            return [[null, $i], [(string) $segment['key'], $i + 1]];
        }
        return [[(string) $segment['key'], $i + 1]];
    }
}

<?php
namespace WPrism;

// Deletion guards may attest that an option NAME carries a table-row id.
// Keep that grammar explicitly loaded without pulling Policy or WordPress
// into this pure declaration resolver.
require_once __DIR__ . '/../Grammar/OptionNameReferenceResolver.php';

/**
 * Pure resolution and validation of manifest-declared deletion authority.
 *
 * Policy remains the compatibility facade and owns the public cast
 * vocabulary.  This collaborator receives only resolved manifest rows, the
 * option-name grammar, and that vocabulary: it never opens a repository,
 * consults WordPress, or chooses a live deletion target.
 */
final class DeletionCapabilityResolver {
    /**
     * @param list<array<string,mixed>> $manifests
     * @param list<string> $casts
     */
    public function __construct(
        private array $manifests,
        private OptionNameReferenceResolver $optionNameReferences,
        private array $casts
    ) {}

    /**
     * @return ?array{cascades:string[],guards:array<int,array<string,mixed>>,declared_by:string[]}
     */
    public function capability(string $selector): ?array {
        $out = null;
        foreach ($this->manifests as $manifest) {
            $decl = $manifest['deletions'][$selector] ?? null;
            if ($decl === null) {
                continue;
            }
            if (!is_array($decl) || !isset($decl['cascades']) || !is_array($decl['cascades'])) {
                throw new \RuntimeException("wprism: manifest deletion capability '$selector' must declare a cascades list");
            }
            $cascades = array_values(array_unique(array_map('strval', $decl['cascades'])));
            sort($cascades, SORT_STRING);
            $guards = $decl['guards'] ?? [];
            if (!is_array($guards) || !array_is_list($guards)) {
                throw new \RuntimeException("wprism: manifest deletion capability '$selector' guards must be a list");
            }
            foreach ($guards as $i => $guard) {
                if (!is_array($guard)
                    || !preg_match('/^[A-Za-z0-9_]+$/', (string) ($guard['table'] ?? ''))
                    || !preg_match('/^[A-Za-z0-9_]+$/', (string) ($guard['column'] ?? ''))
                    || !preg_match('/^[a-z][a-z0-9_]*$/', (string) ($guard['id_kind'] ?? ''))) {
                    throw new \RuntimeException(
                        "wprism: manifest deletion capability '$selector' guard[$i] must declare table, column, and id_kind"
                    );
                }
                $hasMetaKey = array_key_exists('meta_key', $guard);
                $hasMetaRef = array_key_exists('ref', $guard);
                if ($hasMetaKey !== $hasMetaRef) {
                    throw new \RuntimeException(
                        "wprism: manifest deletion capability '$selector' guard[$i] metadata guards must declare meta_key and ref together"
                    );
                }
                $hasOptionNameRef = array_key_exists('option_name_ref', $guard);
                if ($hasOptionNameRef && $guard['option_name_ref'] !== true) {
                    throw new \RuntimeException(
                        "wprism: manifest deletion capability '$selector' guard[$i].option_name_ref must be true when declared"
                    );
                }
                if ($hasOptionNameRef && ($hasMetaKey || $hasMetaRef)) {
                    throw new \RuntimeException(
                        "wprism: manifest deletion capability '$selector' guard[$i] cannot combine option_name_ref with a metadata guard"
                    );
                }
                if ($hasOptionNameRef
                    && ((string) $guard['table'] !== 'options' || (string) $guard['column'] !== 'option_name')) {
                    throw new \RuntimeException(
                        "wprism: manifest deletion capability '$selector' guard[$i].option_name_ref must target options.option_name"
                    );
                }
                if ($hasOptionNameRef) {
                    if (isset($guard['source_id_kind'], $guard['source_pk'])
                        || !empty($guard['where'])
                        || !empty($guard['exclude_where'])) {
                        throw new \RuntimeException(
                            "wprism: manifest deletion capability '$selector' guard[$i].option_name_ref cannot declare table-row source or predicate qualifiers"
                        );
                    }
                    $hasRule = false;
                    foreach ($this->optionNameReferences->rules() as $optionNameRule) {
                        if ((string) ($optionNameRule['id_kind'] ?? '') === (string) $guard['id_kind']
                            && ($optionNameRule['class'] ?? '') === 'authored') {
                            $hasRule = true;
                            break;
                        }
                    }
                    if (!$hasRule) {
                        throw new \RuntimeException(
                            "wprism: manifest deletion capability '$selector' guard[$i].option_name_ref has no loaded authored option_name_refs rule for id_kind '{$guard['id_kind']}'"
                        );
                    }
                }
                if ($hasOptionNameRef
                    && isset($guard['identity_column'])
                    && !preg_match('/^[A-Za-z0-9_]+$/', (string) $guard['identity_column'])) {
                    throw new \RuntimeException(
                        "wprism: manifest deletion capability '$selector' guard[$i].identity_column must be a column name"
                    );
                }
                if ($hasMetaKey) {
                    if ((string) $guard['table'] !== 'postmeta'
                        || !preg_match('/^[A-Za-z0-9_]+$/', (string) $guard['meta_key'])
                        || !preg_match('/^[a-z][a-z0-9_]*(?:\[\])?$/', (string) $guard['ref'])
                        || rtrim((string) $guard['ref'], '[]') !== (string) $guard['id_kind']) {
                        throw new \RuntimeException(
                            "wprism: manifest deletion capability '$selector' guard[$i] metadata guard must target postmeta with a ref matching id_kind"
                        );
                    }
                    if (!isset($guard['source_id_kind'], $guard['source_pk'])
                        || !preg_match('/^[a-z][a-z0-9_]*$/', (string) $guard['source_id_kind'])
                        || !preg_match('/^[A-Za-z0-9_]+$/', (string) $guard['source_pk'])) {
                        throw new \RuntimeException(
                            "wprism: manifest deletion capability '$selector' guard[$i] metadata guard must declare source_id_kind and source_pk for owner exclusion"
                        );
                    }
                    if (isset($guard['cast']) && !in_array((string) $guard['cast'], $this->casts, true)) {
                        throw new \RuntimeException(
                            "wprism: manifest deletion capability '$selector' guard[$i].cast must be string or csv"
                        );
                    }
                    if (isset($guard['identity_column'])
                        && !preg_match('/^[A-Za-z0-9_]+$/', (string) $guard['identity_column'])) {
                        throw new \RuntimeException(
                            "wprism: manifest deletion capability '$selector' guard[$i].identity_column must be a column name"
                        );
                    }
                }
                foreach (['where', 'exclude_where'] as $predicate) {
                    $values = $guard[$predicate] ?? [];
                    if (!is_array($values) || (isset($guard[$predicate]) && array_is_list($values))) {
                        throw new \RuntimeException(
                            "wprism: manifest deletion capability '$selector' guard[$i].$predicate must be an object"
                        );
                    }
                    foreach ($values as $column => $value) {
                        if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $column)
                            || (!is_string($value) && !is_int($value))) {
                            throw new \RuntimeException(
                                "wprism: manifest deletion capability '$selector' guard[$i].$predicate must contain scalar column predicates"
                            );
                        }
                    }
                }
                $hasSourceKind = isset($guard['source_id_kind']);
                $hasSourcePk = isset($guard['source_pk']);
                if ($hasSourceKind !== $hasSourcePk
                    || ($hasSourceKind && !preg_match('/^[a-z][a-z0-9_]*$/', (string) $guard['source_id_kind']))
                    || ($hasSourcePk && !preg_match('/^[A-Za-z0-9_]+$/', (string) $guard['source_pk']))) {
                    throw new \RuntimeException(
                        "wprism: manifest deletion capability '$selector' guard[$i] must declare source_id_kind and source_pk together"
                    );
                }
            }
            $source = (string) ($manifest['name'] ?? '?');
            if ($out === null) {
                $out = ['cascades' => $cascades, 'guards' => [], 'declared_by' => []];
            } elseif ($out['cascades'] !== $cascades) {
                throw new \RuntimeException(
                    "wprism: pinned manifests disagree on cascade effects for deletion capability '$selector'"
                );
            }
            $out['guards'] = array_merge($out['guards'], $guards);
            $out['declared_by'][] = $source;
        }
        return $out;
    }
}

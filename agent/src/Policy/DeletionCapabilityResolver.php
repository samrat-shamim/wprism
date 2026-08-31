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
     * @return ?array{cascades:string[],guards:array<int,array<string,mixed>>,declared_by:string[],executable_owner_boundary?:string,declaring_executable_owners?:string[],declaring_executable_owner_identities?:array<string,list<array{format:string,root:string,sha256:string}>>}
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
            $ownerBoundary = $decl['executable_owner_boundary'] ?? null;
            if (array_key_exists('active_plugin_boundary', $decl)) {
                throw new \RuntimeException(
                    "wprism: manifest deletion capability '$selector' active_plugin_boundary is retired; declare executable_owner_boundary=all_active_owners"
                );
            }
            if ($ownerBoundary !== null && $ownerBoundary !== 'all_active_owners') {
                throw new \RuntimeException(
                    "wprism: manifest deletion capability '$selector' executable_owner_boundary must be all_active_owners"
                );
            }
            if ($out !== null && ($out['executable_owner_boundary'] ?? null) !== $ownerBoundary) {
                throw new \RuntimeException(
                    "wprism: every declaration of deletion capability '$selector' must agree on its executable-owner boundary"
                );
            }
            $declarationOwners = [];
            if ($ownerBoundary !== null) {
                $plugin = $manifest['plugin'] ?? null;
                $theme = $manifest['theme'] ?? null;
                if (is_string($plugin) && $plugin !== '') {
                    $declarationOwners[] = 'plugin:' . $plugin;
                }
                if (is_string($theme) && $theme !== '') {
                    $declarationOwners[] = 'theme:' . $theme;
                }
                if ($declarationOwners === []) {
                    throw new \RuntimeException(
                        "wprism: deletion capability '$selector' all_active_owners boundary requires a plugin- or theme-owned manifest"
                    );
                }
                sort($declarationOwners, SORT_STRING);
            }
            $ownerIdentities = $decl['executable_owner_identities'] ?? null;
            if ($ownerBoundary === null && array_key_exists('executable_owner_identities', $decl)) {
                throw new \RuntimeException(
                    "wprism: manifest deletion capability '$selector' executable_owner_identities requires executable_owner_boundary=all_active_owners"
                );
            }
            $ownerIdentities = $ownerBoundary === null
                ? []
                : self::executable_owner_identities($selector, $declarationOwners, $ownerIdentities);
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
                if (array_key_exists('forceable', $guard) && $guard['forceable'] !== false) {
                    throw new \RuntimeException(
                        "wprism: manifest deletion capability '$selector' guard[$i].forceable may only be false"
                    );
                }
                if (array_key_exists('optional_table', $guard) && $guard['optional_table'] !== true) {
                    throw new \RuntimeException(
                        "wprism: manifest deletion capability '$selector' guard[$i].optional_table may only be true"
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
                if (isset($guard['lock_column'])) {
                    $lockColumn = (string) $guard['lock_column'];
                    if (!preg_match('/^[A-Za-z0-9_]+$/', $lockColumn)
                        || !array_key_exists($lockColumn, (array) ($guard['where'] ?? []))) {
                        throw new \RuntimeException(
                            "wprism: manifest deletion capability '$selector' guard[$i].lock_column must name an exact where predicate"
                        );
                    }
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
            if ($ownerBoundary !== null) {
                $out['executable_owner_boundary'] = $ownerBoundary;
                $out['declaring_executable_owners'] = array_values(array_unique(array_merge(
                    (array) ($out['declaring_executable_owners'] ?? []),
                    $declarationOwners
                )));
                sort($out['declaring_executable_owners'], SORT_STRING);
                $resolvedIdentities = (array) ($out['declaring_executable_owner_identities'] ?? []);
                foreach ($ownerIdentities as $owner => $identities) {
                    if (isset($resolvedIdentities[$owner]) && $resolvedIdentities[$owner] !== $identities) {
                        throw new \RuntimeException(
                            "wprism: manifests disagree on reviewed executable identities for deletion capability '$selector' owner '$owner'"
                        );
                    }
                    $resolvedIdentities[$owner] = $identities;
                }
                ksort($resolvedIdentities, SORT_STRING);
                $out['declaring_executable_owner_identities'] = $resolvedIdentities;
            }
        }
        return $out;
    }

    /**
     * @param list<string> $owners
     * @return array<string,list<array{format:string,root:string,sha256:string}>>
     */
    private static function executable_owner_identities(string $selector, array $owners, mixed $declared): array {
        if (!is_array($declared) || array_is_list($declared)) {
            throw new \RuntimeException(
                "wprism: manifest deletion capability '$selector' must declare executable_owner_identities as an owner-keyed object"
            );
        }
        $declaredOwners = array_keys($declared);
        sort($declaredOwners, SORT_STRING);
        if ($declaredOwners !== $owners) {
            throw new \RuntimeException(
                "wprism: manifest deletion capability '$selector' executable_owner_identities must cover exactly its declaring plugin/theme owners"
            );
        }
        $out = [];
        foreach ($owners as $owner) {
            $rows = $declared[$owner] ?? null;
            if (!is_array($rows) || !array_is_list($rows) || $rows === [] || count($rows) > 64) {
                throw new \RuntimeException(
                    "wprism: manifest deletion capability '$selector' owner '$owner' must declare 1 to 64 reviewed executable identities"
                );
            }
            $expectedRoot = self::executable_owner_root($owner);
            $normalized = [];
            foreach ($rows as $index => $identity) {
                if (!is_array($identity) || array_is_list($identity)) {
                    throw new \RuntimeException(
                        "wprism: manifest deletion capability '$selector' owner '$owner' identity[$index] is malformed"
                    );
                }
                $keys = array_keys($identity);
                sort($keys, SORT_STRING);
                if ($keys !== ['format', 'root', 'sha256']
                    || ($identity['format'] ?? null) !== 'wprism-executable-tree/v1'
                    || ($identity['root'] ?? null) !== $expectedRoot
                    || preg_match('/^[a-f0-9]{64}$/D', (string) ($identity['sha256'] ?? '')) !== 1) {
                    throw new \RuntimeException(
                        "wprism: manifest deletion capability '$selector' owner '$owner' identity[$index] is malformed"
                    );
                }
                $normalized[] = [
                    'format' => 'wprism-executable-tree/v1',
                    'root' => $expectedRoot,
                    'sha256' => (string) $identity['sha256'],
                ];
            }
            usort($normalized, static fn(array $left, array $right): int => strcmp($left['sha256'], $right['sha256']));
            if (count(array_unique(array_column($normalized, 'sha256'))) !== count($normalized)) {
                throw new \RuntimeException(
                    "wprism: manifest deletion capability '$selector' owner '$owner' repeats an executable identity"
                );
            }
            $out[$owner] = $normalized;
        }
        return $out;
    }

    private static function executable_owner_root(string $owner): string {
        if (str_starts_with($owner, 'plugin:')) {
            $plugin = substr($owner, strlen('plugin:'));
            $directory = str_replace('\\', '/', dirname($plugin));
            return $directory === '.' ? 'plugins/' . $plugin : 'plugins/' . $directory;
        }
        if (str_starts_with($owner, 'theme:')) {
            return 'themes/' . substr($owner, strlen('theme:'));
        }
        throw new \RuntimeException('wprism: manifest deletion capability has an unsupported executable owner');
    }
}

<?php
namespace Duo;

require_once __DIR__ . '/CanonicalSurfaces.php';
require_once __DIR__ . '/OptionState.php';
require_once __DIR__ . '/ScopeClosure.php';

/**
 * A mutation consumer may not infer per-option authority merely because the
 * immutable contract can name an exact option record. Consumers without the
 * record-aware target/overlay protocol must keep refusing this valid evidence
 * rather than accidentally widening it to the whole options document.
 */
final class ScopedOptionMutationUnsupported extends \RuntimeException {}

/**
 * Immutable, target-independent evidence for one resolved scope.
 *
 * This object deliberately is NOT a scoped apply/capture/promote request. It
 * has no target ids, guard witnesses, provider negotiation, work plan, or
 * mutation authority. A later mutation workflow must re-verify this complete
 * contract against its compiled artifact and must independently prove every
 * target-side precondition before it can write anything.
 */
final class ScopeContract {
    public const FORMAT = 'duo-scope-contract/v1';
    public const TOMBSTONE_PREFIX = 'tombstone:';

    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /**
     * Resolve a read-only, artifact-bound contract from user selectors.
     *
     * @param list<string> $requestedSelectors
     * @return array<string,mixed>
     */
    public static function resolve(CompiledRepository $compiled, Policy $policy, array $requestedSelectors): array {
        $selectors = self::normalize_selectors($requestedSelectors);
        $all = $selectors === [ScopeClosure::SELECTOR_ALL];
        $liveSelectors = $all ? [ScopeClosure::SELECTOR_ALL] : array_values(array_filter(
            $selectors,
            static fn(string $selector): bool => !str_starts_with($selector, self::TOMBSTONE_PREFIX)
        ));
        $tombstoneSelectors = $all ? [] : array_values(array_filter(
            $selectors,
            static fn(string $selector): bool => str_starts_with($selector, self::TOMBSTONE_PREFIX)
        ));

        $tree = $compiled->tree();
        $closure = $liveSelectors === []
            ? ['roots' => [], 'included' => []]
            : ScopeClosure::resolve($compiled, $policy, $liveSelectors);
        $live = self::live_rows($closure, $tree);
        $tombstones = self::tombstone_rows($compiled, $policy, $all, $tombstoneSelectors);

        $entities = [];
        $optionRecords = [];
        foreach (array_merge($live['roots'], $live['closure']) as $row) {
            if (ScopeClosure::is_option_root((string) $row['entity'])) {
                $records = OptionState::records((array) (($tree['options/core'] ?? [])['data'] ?? []));
                $name = (string) $row['option'];
                if (!isset($records[$name])) {
                    throw new \RuntimeException('duo: scope option root disappeared from compiled options/core');
                }
                $optionRecords[$name] = $records[$name];
                continue;
            }
            $entity = $tree[(string) $row['entity']] ?? null;
            if (is_array($entity)) {
                $entities[] = $entity;
            }
        }
        $tombstoneData = array_map(static fn(array $row): array => $row['deletion'], $tombstones);
        $eligibleSurfaces = CanonicalSurfaces::for_scope($entities, $tombstoneData, $policy);
        foreach ($optionRecords as $name => $record) {
            foreach (CanonicalSurfaces::for_option_scope($name, $record, $policy) as $surface) {
                $eligibleSurfaces[] = $surface;
            }
        }
        $eligibleSurfaces = self::sorted_strings($eligibleSurfaces);
        $potentialActions = self::potential_actions($policy, $eligibleSurfaces);
        $potentialProviders = self::potential_providers($policy, $potentialActions);
        $potentialEffects = self::potential_effects(
            $policy,
            $entities,
            $tombstoneData,
            $potentialActions
        );

        $artifact = $compiled->export();
        $contract = [
            'format' => self::FORMAT,
            'purpose' => 'read-only scope evidence; never mutation authority',
            'read_only_evidence' => true,
            'mutation_authority' => false,
            // Keep canonical state identity explicit. artifact_hash is the
            // outer binding (including effects/adapters/code descriptor);
            // state_revision_hash is never silently repurposed as code id.
            'source' => [
                'artifact_hash' => $compiled->artifact_hash(),
                'state_revision_hash' => $compiled->revision_hash(),
                'manifest_hash' => $compiled->manifest_hash(),
            ],
            'code_diagnostic' => $compiled->code_revision() === null ? null : [
                'code_revision' => $compiled->code_revision(),
                'statement' => 'diagnostic only; code identity is not a scope selector or state-revision input',
            ],
            'selectors' => $selectors,
            'resolution' => [
                'live_root_entities' => self::sorted_strings(array_column($live['roots'], 'entity')),
                'tombstone_uuids' => self::sorted_strings(array_column($tombstones, 'uuid')),
            ],
            'live' => $live,
            'tombstones' => $tombstones,
            'uploads' => self::filtered_uploads($compiled, $live),
            'media' => self::filtered_media($artifact, $live),
            // These are exact trigger vocabulary facts, not a plan or an
            // action-selection/authorization decision.
            'eligible_surfaces' => $eligibleSurfaces,
            'potential_actions' => $potentialActions,
            'potential_providers' => $potentialProviders,
            'potential_effects' => $potentialEffects,
            'exclusions' => [
                'code' => 'excluded from scope semantics; code identity appears only as a separate diagnostic',
                'lifecycle' => 'excluded; lifecycle effects are not scoped state effects',
                'target_guard_witnesses' => 'excluded; only static policy obligations are recorded',
                'mutation_execution' => 'deferred; no action/provider negotiation, guard check, or effect execution occurred',
            ],
        ];
        $contract['scope_hash'] = self::hash($contract);
        return $contract;
    }

    /**
     * Validate a serialized contract's exact public schema and intrinsic
     * digest. This checks structural integrity only; use assert_associated()
     * before consuming it for a particular compiled/policy pair.
     *
     * @return array<string,mixed> validated contract
     */
    public static function from_array(array $contract): array {
        self::assert_keys($contract, [
            'code_diagnostic', 'eligible_surfaces', 'exclusions', 'format', 'live', 'media',
            'mutation_authority', 'potential_actions', 'potential_effects', 'potential_providers',
            'purpose', 'read_only_evidence', 'resolution', 'scope_hash', 'source', 'selectors', 'tombstones', 'uploads',
        ], 'scope contract');
        if (($contract['format'] ?? null) !== self::FORMAT) {
            throw new \RuntimeException('duo: scope contract has an unsupported format');
        }
        if (($contract['purpose'] ?? null) !== 'read-only scope evidence; never mutation authority'
            || ($contract['read_only_evidence'] ?? null) !== true
            || ($contract['mutation_authority'] ?? null) !== false) {
            throw new \RuntimeException('duo: scope contract must explicitly be read-only evidence with no mutation authority');
        }
        self::assert_source($contract['source'] ?? null);
        self::assert_code_diagnostic($contract['code_diagnostic'] ?? null);
        self::assert_selector_list($contract['selectors'] ?? null);
        self::assert_resolution($contract['resolution'] ?? null);
        self::assert_live($contract['live'] ?? null);
        self::assert_tombstones($contract['tombstones'] ?? null);
        self::assert_uploads($contract['uploads'] ?? null);
        self::assert_media($contract['media'] ?? null);
        self::assert_string_set($contract['eligible_surfaces'] ?? null, 'eligible_surfaces');
        self::assert_potential_actions($contract['potential_actions'] ?? null, $contract['eligible_surfaces']);
        self::assert_potential_providers($contract['potential_providers'] ?? null);
        self::assert_potential_effects($contract['potential_effects'] ?? null);
        self::assert_keys($contract['exclusions'] ?? null, [
            'code', 'lifecycle', 'mutation_execution', 'target_guard_witnesses',
        ], 'scope contract exclusions');
        foreach ($contract['exclusions'] as $key => $value) {
            if (!is_string($value) || $value === '') {
                throw new \RuntimeException("duo: scope contract exclusions.$key must be a non-empty string");
            }
        }
        $scopeHash = $contract['scope_hash'] ?? null;
        if (!is_string($scopeHash) || !self::is_hash($scopeHash)) {
            throw new \RuntimeException('duo: scope contract has no valid scope_hash');
        }
        $withoutHash = $contract;
        unset($withoutHash['scope_hash']);
        if (!hash_equals(self::hash($withoutHash), $scopeHash)) {
            throw new \RuntimeException('duo: scope contract scope_hash does not verify');
        }
        return $contract;
    }

    /** Alias for callers that prefer an assertion-style API. */
    public static function assert_valid(array $contract): void {
        self::from_array($contract);
    }

    /** @return list<string> option names named by the request or its exact root proofs */
    public static function option_root_names(array $contract): array {
        $contract = self::from_array($contract);
        $names = [];
        // Host boundaries have only a serialized contract, not the compiled
        // source needed for assert_associated().  Honor a selector here too:
        // a self-hashed but semantically unassociated object must not omit
        // its synthetic live root to reach a target before that target can
        // re-resolve and refuse it.
        foreach ((array) ($contract['selectors'] ?? []) as $selector) {
            if (is_string($selector) && str_starts_with($selector, 'option:')) {
                $names[] = substr($selector, strlen('option:'));
            }
        }
        foreach ((array) ($contract['live']['roots'] ?? []) as $row) {
            $entity = (string) ($row['entity'] ?? '');
            if (ScopeClosure::is_option_root($entity)) {
                $names[] = ScopeClosure::option_name_from_root($entity);
            }
        }
        return self::sorted_strings($names);
    }

    /**
     * This is deliberately a per-consumer guard. Refresh and capture have
     * record-aware carrier overlays, while plan/apply/promote still own
     * whole-document mechanics and must reject an option root before contact.
     */
    public static function assert_mutation_supported(array $contract, string $operation): void {
        if (self::option_root_names($contract) !== []) {
            throw new ScopedOptionMutationUnsupported(
                "duo: $operation does not support per-option scoped mutation; select the whole 'options' surface instead"
            );
        }
    }

    /**
     * Strong future-consumer guard: verify intrinsic schema/hash, exact
     * source association, then recompute the entire canonical contract from
     * its normalized request. A caller therefore cannot remove a closure row
     * (or edit action/effect evidence), recompute scope_hash, and retain a
     * false association with the same compiled artifact.
     */
    public static function assert_associated(array $contract, CompiledRepository $compiled, Policy $policy): void {
        $contract = self::from_array($contract);
        $source = $contract['source'];
        if (!hash_equals($compiled->artifact_hash(), (string) $source['artifact_hash'])
            || !hash_equals($compiled->revision_hash(), (string) $source['state_revision_hash'])
            || !hash_equals($compiled->manifest_hash(), (string) $source['manifest_hash'])) {
            throw new \RuntimeException('duo: scope contract is not associated with this exact compiled artifact/policy');
        }
        $expected = self::resolve($compiled, $policy, $contract['selectors']);
        if (!hash_equals(Canon::encode($expected), Canon::encode($contract))) {
            throw new \RuntimeException('duo: scope contract does not match the complete resolved evidence for this artifact/policy');
        }
    }

    /**
     * Refuse a scoped candidate whose live dependency closure escapes the
     * immutable source contract. Version 1 grants no resurrection authority;
     * deleting a selected live identity is accepted only when the consuming
     * transaction supplies its separately proven, execution-local deletion
     * authority. Exact excluded-byte preservation is a separate overlay
     * assertion.
     */
    public static function assert_candidate_bounded(
        array $contract,
        CompiledRepository $candidate,
        Policy $policy,
        array $authorizedDeletions = []
    ): void {
        $contract = self::from_array($contract);
        $isAll = ($contract['selectors'] ?? null) === [ScopeClosure::SELECTOR_ALL];
        $allowed = [];
        $walk = [];
        $expectedTypes = [];
        foreach (['roots', 'closure'] as $field) {
            foreach ((array) $contract['live'][$field] as $row) {
                $identity = (string) $row['entity'];
                $allowed[$identity] = true;
                $walk[$identity] = true;
                $expectedTypes[$identity] = (string) $row['type'];
            }
        }
        foreach ((array) $contract['tombstones'] as $row) {
            $identity = (string) $row['uuid'];
            $allowed[$identity] = true;
        }

        $tree = $candidate->tree();
        $deletions = $candidate->deletions();
        if ($isAll) {
            foreach (array_merge(array_keys($tree), array_keys($deletions)) as $identity) {
                if (!isset($allowed[(string) $identity])) {
                    throw new \RuntimeException(
                        "duo: scoped all target observation introduced identity '$identity' outside the immutable source contract"
                    );
                }
            }
        }
        $selectedTombstones = array_fill_keys(
            array_map('strval', array_column((array) $contract['tombstones'], 'uuid')),
            true
        );
        $authorizedDeletionSet = array_fill_keys(array_map('strval', $authorizedDeletions), true);
        foreach (array_keys($authorizedDeletionSet) as $identity) {
            if (!isset($allowed[$identity]) || isset($selectedTombstones[$identity])) {
                throw new \RuntimeException(
                    "duo: scoped candidate deletion authority escaped to '$identity'"
                );
            }
        }
        foreach (array_keys($allowed) as $identity) {
            if (isset($selectedTombstones[$identity])) {
                if (!isset($deletions[$identity]) || isset($tree[$identity])) {
                    throw new \RuntimeException(
                        "duo: scoped candidate changed selected tombstone '$identity' without deletion/resurrection authority"
                    );
                }
                continue;
            }
            if (ScopeClosure::is_option_root($identity)) {
                $name = ScopeClosure::option_name_from_root($identity);
                $options = $tree['options/core'] ?? null;
                $records = is_array($options)
                    ? OptionState::records((array) ($options['data'] ?? []))
                    : [];
                if (!array_key_exists($name, $records)) {
                    throw new \RuntimeException(
                        "duo: scoped candidate lost selected option '$name' without bounded option evidence"
                    );
                }
                continue;
            }
            if (isset($tree[$identity]) && !isset($deletions[$identity])) {
                if ((string) ($tree[$identity]['type'] ?? '') !== (string) ($expectedTypes[$identity] ?? '')) {
                    throw new \RuntimeException(
                        "duo: scoped candidate changed selected identity '$identity' to a different entity type"
                    );
                }
                continue;
            }
            if (isset($authorizedDeletionSet[$identity]) && isset($deletions[$identity])) {
                continue;
            }
            if (!isset($tree[$identity]) || isset($deletions[$identity])) {
                throw new \RuntimeException(
                    "duo: scoped candidate changed selected live identity '$identity' without deletion/resurrection authority"
                );
            }
        }
        $selectors = [];
        // Rewalk every retained frozen live identity, not only the original
        // roots. A root may legitimately stop referring to one of its old
        // dependencies while that still-selected row gains a different edge;
        // walking roots alone would silently stop checking the detached row.
        foreach (array_keys($walk) as $identity) {
            if (isset($authorizedDeletionSet[$identity])) {
                continue;
            }
            if (ScopeClosure::is_option_root($identity)) {
                $selectors[] = 'option:' . ScopeClosure::option_name_from_root($identity);
                continue;
            }
            $selectors[] = 'path:' . (string) $tree[$identity]['path'];
        }
        if ($selectors !== []) {
            $closure = ScopeClosure::resolve($candidate, $policy, $selectors);
            foreach ((array) ($closure['included'] ?? []) as $row) {
                $identity = (string) ($row['entity'] ?? '');
                if (!isset($allowed[$identity])) {
                    throw new \RuntimeException(
                        "duo: scoped candidate dependency closure escaped to excluded identity '$identity'"
                    );
                }
            }
        }

        // On the complete live-target probe, a new out-of-scope referrer
        // must not be erased from consideration merely because the final
        // repository overlay preserves old source bytes. Deleting a selected
        // identity would strand that target row, so target drift blocks before
        // any publication exactly like source-bound inbound evidence does.
        if ($authorizedDeletionSet !== []) {
            $owners = ReferenceGraph::owners($tree);
            foreach (ReferenceGraph::edges($tree, $policy) as $edge) {
                $rawTarget = (string) ($edge['target'] ?? '');
                $rawFrom = (string) ($edge['from'] ?? '');
                $target = (string) ($owners[$rawTarget]['entity'] ?? $rawTarget);
                $from = (string) ($owners[$rawFrom]['entity'] ?? $rawFrom);
                if (isset($authorizedDeletionSet[$target]) && !isset($allowed[$from])) {
                    throw new \RuntimeException(
                        "duo: scoped target drift added an out-of-scope inbound reference to selected deletion '$target'"
                    );
                }
            }
        }
    }

    /**
     * Normalize request spelling before any resolution. `all` is the whole
     * compiled revision INCLUDING every compiled tombstone, so it subsumes
     * every narrower live or tombstone selector. `tombstone:<uuid>` names
     * immutable deletion intent; it is deliberately not a `delete` verb and
     * never enters ScopeClosure's live-root grammar.
     *
     * @param list<string> $requestedSelectors
     * @return list<string>
     */
    public static function normalize_selectors(array $requestedSelectors): array {
        $seen = [];
        $all = false;
        foreach ($requestedSelectors as $raw) {
            if (!is_string($raw)) {
                throw new \RuntimeException('duo: scope-contract selectors must be strings');
            }
            $selector = trim($raw);
            if ($selector === '') {
                continue;
            }
            if ($selector === ScopeClosure::SELECTOR_ALL) {
                $all = true;
                continue;
            }
            if (str_starts_with($selector, self::TOMBSTONE_PREFIX)) {
                $uuid = substr($selector, strlen(self::TOMBSTONE_PREFIX));
                if (preg_match(self::UUID_RE, $uuid) !== 1) {
                    throw new \RuntimeException("duo: tombstone selector '$selector' must be exactly tombstone:<lowercase-uuid>");
                }
            } elseif (str_starts_with($selector, 'delete:')) {
                throw new \RuntimeException(
                    "duo: selector '$selector' is not mutation authority; use tombstone:<uuid> to name immutable deletion evidence"
                );
            }
            $seen[$selector] = true;
        }
        if ($all) {
            return [ScopeClosure::SELECTOR_ALL];
        }
        $out = array_keys($seen);
        sort($out, SORT_STRING);
        if ($out === []) {
            throw new \RuntimeException('duo: a scope contract needs at least one selector (or "all")');
        }
        return $out;
    }

    /** @param array<string,mixed> $closure @param array<string,array<string,mixed>> $tree @return array{roots:list<array<string,mixed>>,closure:list<array<string,mixed>>,inbound:list<array<string,mixed>>,excluded:list<array<string,mixed>>} */
    private static function live_rows(array $closure, array $tree): array {
        $roots = [];
        foreach ((array) ($closure['roots'] ?? []) as $row) {
            $identity = (string) ($row['entity'] ?? '');
            if (ScopeClosure::is_option_root($identity)) {
                $roots[] = self::option_live_row($tree, $identity, [
                    'kind' => 'root',
                    'selector' => (string) ($row['selector'] ?? ''),
                ]);
                continue;
            }
            $entity = $tree[$identity] ?? null;
            if (!is_array($entity)) {
                throw new \RuntimeException('duo: scope closure root disappeared from compiled tree');
            }
            $roots[] = self::live_row($entity, $identity, [
                'kind' => 'root',
                'selector' => (string) ($row['selector'] ?? ''),
            ]);
        }
        $closed = [];
        foreach ((array) ($closure['included'] ?? []) as $row) {
            if (($row['reason'] ?? null) === 'root') {
                continue;
            }
            $entity = $tree[(string) ($row['entity'] ?? '')] ?? null;
            if (!is_array($entity)) {
                throw new \RuntimeException('duo: scope closure inclusion disappeared from compiled tree');
            }
            $closed[] = self::live_row($entity, (string) $row['entity'], [
                'kind' => 'closure',
                'reason' => (string) ($row['reason'] ?? ''),
                'from' => $row['from'] === null ? null : (string) $row['from'],
                'from_path' => $row['from_path'] === null ? null : (string) $row['from_path'],
                'locator' => $row['locator'] === null ? null : (string) $row['locator'],
            ]);
        }
        usort($roots, static fn(array $a, array $b): int => [$a['path'], $a['entity']] <=> [$b['path'], $b['entity']]);
        usort($closed, static fn(array $a, array $b): int => [$a['path'], $a['entity']] <=> [$b['path'], $b['entity']]);
        $included = [];
        foreach (array_merge($roots, $closed) as $row) {
            $included[(string) $row['entity']] = true;
        }
        $hasOptionRoot = self::option_root_names_from_live_rows($roots) !== [];
        $excluded = [];
        foreach ($tree as $entityId => $entity) {
            if (isset($included[(string) $entityId]) || ($entityId === 'options/core' && $hasOptionRoot)) {
                continue;
            }
            $excluded[] = self::live_row($entity, (string) $entityId, [
                'kind' => 'excluded',
                'reason' => 'outside_resolved_live_closure',
            ]);
        }
        usort($excluded, static fn(array $a, array $b): int => [$a['path'], $a['entity']] <=> [$b['path'], $b['entity']]);

        $inbound = [];
        foreach ((array) ($closure['inbound'] ?? []) as $row) {
            $entityId = (string) ($row['entity'] ?? '');
            $targetId = (string) ($row['target'] ?? '');
            $entity = $tree[$entityId] ?? null;
            $target = $tree[$targetId] ?? null;
            if (!is_array($entity) || !is_array($target)) {
                throw new \RuntimeException('duo: scope closure inbound evidence disappeared from compiled tree');
            }
            $inbound[] = [
                'entity' => $entityId,
                'type' => (string) ($entity['type'] ?? ''),
                'path' => (string) ($entity['path'] ?? ''),
                'entity_hash' => (string) ($entity['hash'] ?? ''),
                'source_hash' => (string) ($entity['source_hash'] ?? ''),
                'locator' => (string) ($row['locator'] ?? ''),
                'relation' => (string) ($row['relation'] ?? ''),
                'target' => $targetId,
                'target_path' => (string) ($target['path'] ?? ''),
                'target_hash' => (string) ($target['hash'] ?? ''),
            ];
        }
        usort($inbound, static fn(array $a, array $b): int => [
            $a['path'], $a['locator'], $a['target'],
        ] <=> [
            $b['path'], $b['locator'], $b['target'],
        ]);
        return ['roots' => $roots, 'closure' => $closed, 'inbound' => $inbound, 'excluded' => $excluded];
    }

    /** @param array<string,mixed> $entity @param array<string,mixed> $provenance @return array<string,mixed> */
    private static function live_row(array $entity, string $entityId, array $provenance): array {
        return [
            'entity' => $entityId,
            'type' => (string) ($entity['type'] ?? ''),
            'path' => (string) ($entity['path'] ?? ''),
            'entity_hash' => (string) ($entity['hash'] ?? ''),
            'source_hash' => (string) ($entity['source_hash'] ?? ''),
            'provenance' => $provenance,
        ];
    }

    /** @param array<string,array<string,mixed>> $tree @param array<string,mixed> $provenance */
    private static function option_live_row(array $tree, string $identity, array $provenance): array {
        $options = $tree['options/core'] ?? null;
        if (!is_array($options)) {
            throw new \RuntimeException('duo: scope option root disappeared from compiled options/core');
        }
        $name = ScopeClosure::option_name_from_root($identity);
        $records = OptionState::records((array) ($options['data'] ?? []));
        if (!array_key_exists($name, $records)) {
            throw new \RuntimeException('duo: scope option root disappeared from compiled options/core');
        }
        return [
            'entity' => $identity,
            'type' => 'option',
            'option' => $name,
            'path' => (string) ($options['path'] ?? ''),
            // The record hash is the selected logical identity; source_hash
            // still binds the complete owning document until an option-aware
            // mutation overlay replaces the whole-file model.
            'entity_hash' => OptionState::record_hash($records[$name]),
            'source_hash' => (string) ($options['source_hash'] ?? ''),
            'provenance' => $provenance,
        ];
    }

    /** @param list<array<string,mixed>> $rows @return list<string> */
    private static function option_root_names_from_live_rows(array $rows): array {
        $names = [];
        foreach ($rows as $row) {
            $entity = (string) ($row['entity'] ?? '');
            if (ScopeClosure::is_option_root($entity)) {
                $names[] = ScopeClosure::option_name_from_root($entity);
            }
        }
        return self::sorted_strings($names);
    }

    /** @param list<string> $tombstoneSelectors @return list<array<string,mixed>> */
    private static function tombstone_rows(CompiledRepository $compiled, Policy $policy, bool $all, array $tombstoneSelectors): array {
        $deletions = $compiled->deletions();
        $wanted = [];
        if ($all) {
            $wanted = array_keys($deletions);
        } else {
            foreach ($tombstoneSelectors as $selector) {
                $uuid = substr($selector, strlen(self::TOMBSTONE_PREFIX));
                if (!isset($deletions[$uuid])) {
                    throw new \RuntimeException("duo: tombstone selector '$selector' names no compiled tombstone in this revision");
                }
                $wanted[] = $uuid;
            }
        }
        $wanted = array_values(array_unique($wanted));
        sort($wanted, SORT_STRING);
        $out = [];
        foreach ($wanted as $uuid) {
            $entry = $deletions[$uuid] ?? null;
            if (!is_array($entry)) {
                throw new \RuntimeException("duo: compiled tombstone '$uuid' is missing");
            }
            $deletion = (array) ($entry['data'] ?? []);
            $kind = (string) ($deletion['kind'] ?? '');
            $type = (string) ($deletion['type'] ?? '');
            $capability = Deletion::capability($policy, $kind, $type);
            $guards = array_values((array) ($capability['guards'] ?? []));
            usort($guards, static fn(array $a, array $b): int => strcmp(Canon::encode($a), Canon::encode($b)));
            $declaredBy = array_values(array_unique(array_map('strval', (array) ($capability['declared_by'] ?? []))));
            sort($declaredBy, SORT_STRING);
            $out[] = [
                'uuid' => $uuid,
                'path' => (string) ($entry['path'] ?? ''),
                'tombstone_hash' => (string) ($entry['hash'] ?? ''),
                'deletion' => $deletion,
                // Purely static policy obligations. No target table was read,
                // no reverse-reference witness was gathered, and no cascade
                // is authorization for future mutation.
                'policy_deletion_obligations' => [
                    'selector' => Deletion::selector($kind, $type),
                    'cascades' => array_values((array) ($capability['cascades'] ?? [])),
                    'guards' => $guards,
                    'declared_by' => $declaredBy,
                    'static_only' => true,
                ],
            ];
        }
        usort($out, static fn(array $a, array $b): int => [$a['path'], $a['uuid']] <=> [$b['path'], $b['uuid']]);
        return $out;
    }

    /** @param array{roots:list<array<string,mixed>>,closure:list<array<string,mixed>>,inbound:list<array<string,mixed>>,excluded:list<array<string,mixed>>} $live @return list<array<string,string>> */
    private static function filtered_uploads(CompiledRepository $compiled, array $live): array {
        $uuids = [];
        foreach (array_merge($live['roots'], $live['closure']) as $row) {
            $uuids[(string) $row['entity']] = true;
        }
        $out = array_values(array_filter(
            $compiled->uploads_inventory(),
            static fn(array $row): bool => isset($uuids[(string) ($row['attachment_uuid'] ?? '')])
        ));
        usort($out, static fn(array $a, array $b): int => strcmp((string) $a['original_path'], (string) $b['original_path']));
        return $out;
    }

    /** @param array<string,mixed> $artifact @param array{roots:list<array<string,mixed>>,closure:list<array<string,mixed>>,inbound:list<array<string,mixed>>,excluded:list<array<string,mixed>>} $live @return list<array{name:string,sha256:string}> */
    private static function filtered_media(array $artifact, array $live): array {
        $tree = (array) ($artifact['tree'] ?? []);
        $names = [];
        foreach (array_merge($live['roots'], $live['closure']) as $row) {
            $entity = $tree[(string) $row['entity']] ?? null;
            if (!is_array($entity) || ($entity['type'] ?? '') !== 'post'
                || (($entity['data']['type'] ?? '') !== 'attachment')) {
                continue;
            }
            $name = (string) ($entity['data']['media'] ?? '');
            if ($name !== '') {
                $names[$name] = true;
            }
        }
        $out = [];
        foreach (array_keys($names) as $name) {
            $row = $artifact['media'][$name] ?? null;
            if (!is_array($row) || !is_string($row['sha256'] ?? null) || !self::is_hash($row['sha256'])) {
                throw new \RuntimeException("duo: compiled artifact lacks verified media '$name' for scope contract");
            }
            $out[] = ['name' => $name, 'sha256' => $row['sha256']];
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $out;
    }

    /** @param list<string> $surfaces @return list<array<string,mixed>> */
    private static function potential_actions(Policy $policy, array $surfaces): array {
        $out = [];
        foreach ($policy->actions_for($surfaces) as $action) {
            $triggers = array_key_exists('triggers', $action) ? (array) $action['triggers'] : [];
            $matching = $triggers === [] ? $surfaces : array_values(array_intersect($triggers, $surfaces));
            sort($matching, SORT_STRING);
            $out[] = [
                'manifest' => (string) ($action['manifest'] ?? ''),
                'index' => (int) ($action['index'] ?? 0),
                'source' => Policy::action_source($action, (int) ($action['index'] ?? 0)),
                'declaration' => $action,
                'eligible_surfaces' => $matching,
                'potential_only' => true,
            ];
        }
        usort($out, static fn(array $a, array $b): int => [$a['manifest'], $a['index'], $a['source']] <=> [$b['manifest'], $b['index'], $b['source']]);
        return $out;
    }

    /** @param list<array<string,mixed>> $actions @return list<array<string,mixed>> */
    private static function potential_providers(Policy $policy, array $actions): array {
        $declarations = $policy->provider_declarations();
        $wanted = [];
        foreach ($actions as $action) {
            $declaration = (array) ($action['declaration'] ?? []);
            if (($declaration['kind'] ?? null) !== 'provider') {
                continue;
            }
            $id = (string) ($declaration['provider'] ?? '');
            if ($id !== '') {
                $wanted[$id][] = (string) $action['source'];
            }
        }
        ksort($wanted, SORT_STRING);
        $out = [];
        foreach ($wanted as $id => $sources) {
            $declaration = $declarations[$id] ?? null;
            if (!is_array($declaration)) {
                // Policy::validate_actions() prevents this. Keep the read-only
                // contract fail-closed if a malformed frozen Policy reaches it.
                throw new \RuntimeException("duo: potential provider '$id' has no policy declaration");
            }
            $sources = array_values(array_unique($sources));
            sort($sources, SORT_STRING);
            $out[] = [
                'id' => $id,
                'declaration' => $declaration,
                'potential_action_sources' => $sources,
                'potential_only' => true,
            ];
        }
        return $out;
    }

    /**
     * Filter the complete effect inventory instead of publishing it whole.
     * Scope evidence includes only effects a later scoped state operation
     * could reach from its closure/tombstones: relevant core rebuild work,
     * exact eligible action declarations, and matching post-type
     * regenerator declarations. Lifecycle/code rows are intentionally absent.
     *
     * @param list<array<string,mixed>> $entities
     * @param list<array<string,mixed>> $tombstones
     * @param list<array<string,mixed>> $actions
     * @return list<array<string,mixed>>
     */
    private static function potential_effects(Policy $policy, array $entities, array $tombstones, array $actions): array {
        $coreSources = [];
        $postTypes = [];
        if ($entities !== [] || $tombstones !== []) {
            // Apply recounts declared taxonomies in its rebuild phase; a
            // contract conservatively records that static possibility.
            $coreSources['taxonomy-counts'] = 'core_rebuild';
        }
        foreach ($entities as $entity) {
            if (($entity['type'] ?? '') !== 'post') {
                continue;
            }
            $postType = (string) ($entity['data']['type'] ?? '');
            if ($postType === '') {
                continue;
            }
            $postTypes[$postType] = true;
            $coreSources['future-post-schedule'] = 'core_rebuild';
            if ($postType === 'attachment') {
                $coreSources['attachment-metadata'] = 'core_rebuild';
            }
        }
        foreach ($tombstones as $deletion) {
            if (($deletion['kind'] ?? '') === 'post' && (string) ($deletion['type'] ?? '') !== '') {
                $postTypes[(string) $deletion['type']] = true;
            }
        }
        if ($actions !== []) {
            $coreSources['object-cache-flush'] = 'core_rebuild';
        }
        $out = [];
        // Do not filter action effects by effects_inventory(). Native actions
        // deliberately share a closed source spelling (for example two
        // transient.delete declarations), so a source-only filter would leak
        // an unrelated action row. This Policy helper returns the exact
        // declaration/fallback for this exact action index.
        foreach ($actions as $action) {
            $declaration = (array) ($action['declaration'] ?? []);
            $index = (int) ($action['index'] ?? 0);
            foreach (Policy::action_effects($declaration, $index) as $effect) {
                $out[] = [
                    'manifest' => (string) $action['manifest'],
                    'phase' => 'rebuild',
                    'source' => (string) $action['source'],
                    'effect' => $effect,
                    'basis' => 'eligible_action',
                    'potential_only' => true,
                ];
            }
        }
        foreach ($policy->effects_inventory() as $row) {
            $phase = (string) ($row['phase'] ?? '');
            $source = (string) ($row['source'] ?? '');
            $basis = null;
            if ($phase === 'rebuild' && isset($coreSources[$source])) {
                $basis = $coreSources[$source];
            } elseif ($phase === 'regenerator' && isset($postTypes[$source])) {
                $basis = 'eligible_regenerator';
            }
            if ($basis === null) {
                continue;
            }
            $out[] = [
                'manifest' => (string) ($row['manifest'] ?? ''),
                'phase' => $phase,
                'source' => $source,
                'effect' => (array) ($row['effect'] ?? []),
                'basis' => $basis,
                'potential_only' => true,
            ];
        }
        usort($out, static fn(array $a, array $b): int => [
            $a['phase'], $a['manifest'], (string) ($a['effect']['id'] ?? ''), $a['source'],
        ] <=> [
            $b['phase'], $b['manifest'], (string) ($b['effect']['id'] ?? ''), $b['source'],
        ]);
        return $out;
    }

    /** @param mixed $source */
    private static function assert_source(mixed $source): void {
        self::assert_keys($source, ['artifact_hash', 'manifest_hash', 'state_revision_hash'], 'scope contract source');
        foreach ($source as $key => $value) {
            if (!is_string($value) || !self::is_hash($value)) {
                throw new \RuntimeException("duo: scope contract source.$key must be a lowercase SHA-256 hash");
            }
        }
    }

    /** @param mixed $diagnostic */
    private static function assert_code_diagnostic(mixed $diagnostic): void {
        if ($diagnostic === null) {
            return;
        }
        self::assert_keys($diagnostic, ['code_revision', 'statement'], 'scope contract code_diagnostic');
        if (!is_string($diagnostic['code_revision']) || $diagnostic['code_revision'] === ''
            || !is_string($diagnostic['statement']) || $diagnostic['statement'] === '') {
            throw new \RuntimeException('duo: scope contract code_diagnostic is malformed');
        }
    }

    /** @param mixed $selectors */
    private static function assert_selector_list(mixed $selectors): void {
        if (!is_array($selectors) || !array_is_list($selectors)) {
            throw new \RuntimeException('duo: scope contract selectors must be a list');
        }
        $normalized = self::normalize_selectors($selectors);
        if ($normalized !== $selectors) {
            throw new \RuntimeException('duo: scope contract selectors are not normalized');
        }
    }

    /** @param mixed $live */
    private static function assert_live(mixed $live): void {
        self::assert_keys($live, ['closure', 'excluded', 'inbound', 'roots'], 'scope contract live');
        foreach (['roots' => 'root', 'closure' => 'closure', 'excluded' => 'excluded'] as $field => $kind) {
            if (!is_array($live[$field]) || !array_is_list($live[$field])) {
                throw new \RuntimeException("duo: scope contract live.$field must be a list");
            }
            foreach ($live[$field] as $row) {
                $isOption = ($row['type'] ?? null) === 'option';
                self::assert_keys(
                    $row,
                    $isOption
                        ? ['entity', 'entity_hash', 'option', 'path', 'provenance', 'source_hash', 'type']
                        : ['entity', 'entity_hash', 'path', 'provenance', 'source_hash', 'type'],
                    "scope contract live.$field row"
                );
                foreach (['entity', 'path', 'type'] as $key) {
                    if (!is_string($row[$key]) || $row[$key] === '') {
                        throw new \RuntimeException("duo: scope contract live.$field row.$key must be a non-empty string");
                    }
                }
                if ($isOption) {
                    if ($kind !== 'root') {
                        throw new \RuntimeException("duo: scope contract live.$field option evidence must be a root");
                    }
                    if (!ScopeClosure::is_option_root((string) $row['entity'])
                        || !is_string($row['option'] ?? null)
                        || $row['option'] === ''
                        || !hash_equals(
                            ScopeClosure::option_name_from_root((string) $row['entity']),
                            (string) $row['option']
                        )) {
                        throw new \RuntimeException("duo: scope contract live.$field option root is malformed");
                    }
                } elseif (ScopeClosure::is_option_root((string) $row['entity'])) {
                    throw new \RuntimeException("duo: scope contract live.$field synthetic option root has an invalid type");
                }
                foreach (['entity_hash', 'source_hash'] as $key) {
                    if (!is_string($row[$key]) || !self::is_hash($row[$key])) {
                        throw new \RuntimeException("duo: scope contract live.$field row.$key must be a SHA-256 hash");
                    }
                }
                $expected = match ($kind) {
                    'root' => ['kind', 'selector'],
                    'closure' => ['from', 'from_path', 'kind', 'locator', 'reason'],
                    default => ['kind', 'reason'],
                };
                self::assert_keys($row['provenance'], $expected, "scope contract live.$field provenance");
                if (($row['provenance']['kind'] ?? null) !== $kind) {
                    throw new \RuntimeException("duo: scope contract live.$field provenance kind is invalid");
                }
            }
        }
        if (!is_array($live['inbound']) || !array_is_list($live['inbound'])) {
            throw new \RuntimeException('duo: scope contract live.inbound must be a list');
        }
        foreach ($live['inbound'] as $row) {
            self::assert_keys($row, [
                'entity', 'entity_hash', 'locator', 'path', 'relation', 'source_hash', 'target', 'target_hash', 'target_path', 'type',
            ], 'scope contract live.inbound row');
            foreach (['entity', 'locator', 'path', 'relation', 'target', 'target_path', 'type'] as $key) {
                if (!is_string($row[$key]) || $row[$key] === '') {
                    throw new \RuntimeException("duo: scope contract live.inbound row.$key must be a non-empty string");
                }
            }
            foreach (['entity_hash', 'source_hash', 'target_hash'] as $key) {
                if (!is_string($row[$key]) || !self::is_hash($row[$key])) {
                    throw new \RuntimeException("duo: scope contract live.inbound row.$key must be a SHA-256 hash");
                }
            }
        }
    }

    /** @param mixed $resolution */
    private static function assert_resolution(mixed $resolution): void {
        self::assert_keys($resolution, ['live_root_entities', 'tombstone_uuids'], 'scope contract resolution');
        self::assert_string_set($resolution['live_root_entities'] ?? null, 'resolution live_root_entities');
        self::assert_string_set($resolution['tombstone_uuids'] ?? null, 'resolution tombstone_uuids');
        foreach ($resolution['tombstone_uuids'] as $uuid) {
            if (preg_match(self::UUID_RE, $uuid) !== 1) {
                throw new \RuntimeException('duo: scope contract resolution tombstone_uuids must be UUIDs');
            }
        }
    }

    /** @param mixed $tombstones */
    private static function assert_tombstones(mixed $tombstones): void {
        if (!is_array($tombstones) || !array_is_list($tombstones)) {
            throw new \RuntimeException('duo: scope contract tombstones must be a list');
        }
        foreach ($tombstones as $row) {
            self::assert_keys($row, ['deletion', 'path', 'policy_deletion_obligations', 'tombstone_hash', 'uuid'], 'scope contract tombstone');
            if (!is_string($row['uuid']) || preg_match(self::UUID_RE, $row['uuid']) !== 1
                || !is_string($row['path']) || $row['path'] === ''
                || !is_string($row['tombstone_hash']) || !self::is_hash($row['tombstone_hash'])) {
                throw new \RuntimeException('duo: scope contract tombstone identity/hash is malformed');
            }
            self::assert_keys($row['deletion'], [
                'expected_hash', 'expected_revision', 'format', 'kind', 'source_path', 'type', 'uuid',
            ], 'scope contract tombstone deletion');
            if (($row['deletion']['format'] ?? null) !== Deletion::FORMAT
                || ($row['deletion']['uuid'] ?? null) !== $row['uuid']) {
                throw new \RuntimeException('duo: scope contract tombstone deletion does not match its row');
            }
            self::assert_keys($row['policy_deletion_obligations'], [
                'cascades', 'declared_by', 'guards', 'selector', 'static_only',
            ], 'scope contract policy deletion obligations');
            if (($row['policy_deletion_obligations']['static_only'] ?? null) !== true
                || !is_string($row['policy_deletion_obligations']['selector'] ?? null)
                || !is_array($row['policy_deletion_obligations']['cascades'] ?? null)
                || !is_array($row['policy_deletion_obligations']['guards'] ?? null)
                || !is_array($row['policy_deletion_obligations']['declared_by'] ?? null)) {
                throw new \RuntimeException('duo: scope contract policy deletion obligations are malformed');
            }
        }
    }

    /** @param mixed $uploads */
    private static function assert_uploads(mixed $uploads): void {
        if (!is_array($uploads) || !array_is_list($uploads)) {
            throw new \RuntimeException('duo: scope contract uploads must be a list');
        }
        foreach ($uploads as $row) {
            self::assert_keys($row, [
                'attachment_uuid', 'derivative_basename_prefix', 'derivative_directory', 'media_blob', 'original_path', 'original_sha256',
            ], 'scope contract upload');
            foreach ($row as $value) {
                if (!is_string($value)) {
                    throw new \RuntimeException('duo: scope contract upload values must be strings');
                }
            }
        }
    }

    /** @param mixed $media */
    private static function assert_media(mixed $media): void {
        if (!is_array($media) || !array_is_list($media)) {
            throw new \RuntimeException('duo: scope contract media must be a list');
        }
        foreach ($media as $row) {
            self::assert_keys($row, ['name', 'sha256'], 'scope contract media');
            if (!is_string($row['name']) || $row['name'] === '' || !is_string($row['sha256']) || !self::is_hash($row['sha256'])) {
                throw new \RuntimeException('duo: scope contract media row is malformed');
            }
        }
    }

    /** @param mixed $set */
    private static function assert_string_set(mixed $set, string $where): void {
        if (!is_array($set) || !array_is_list($set)) {
            throw new \RuntimeException("duo: scope contract $where must be a list");
        }
        $previous = null;
        foreach ($set as $value) {
            if (!is_string($value) || $value === '' || ($previous !== null && strcmp($previous, $value) >= 0)) {
                throw new \RuntimeException("duo: scope contract $where must be sorted unique non-empty strings");
            }
            $previous = $value;
        }
    }

    /** @param mixed $actions @param mixed $surfaces */
    private static function assert_potential_actions(mixed $actions, mixed $surfaces): void {
        if (!is_array($actions) || !array_is_list($actions) || !is_array($surfaces)) {
            throw new \RuntimeException('duo: scope contract potential_actions must be a list');
        }
        $surfaceSet = array_fill_keys($surfaces, true);
        foreach ($actions as $row) {
            self::assert_keys($row, ['declaration', 'eligible_surfaces', 'index', 'manifest', 'potential_only', 'source'], 'scope contract potential action');
            if (!is_string($row['manifest']) || $row['manifest'] === '' || !is_int($row['index'])
                || !is_string($row['source']) || $row['source'] === '' || !is_array($row['declaration'])
                || ($row['potential_only'] ?? null) !== true) {
                throw new \RuntimeException('duo: scope contract potential action is malformed');
            }
            self::assert_string_set($row['eligible_surfaces'], 'potential action eligible_surfaces');
            foreach ($row['eligible_surfaces'] as $surface) {
                if (!isset($surfaceSet[$surface])) {
                    throw new \RuntimeException('duo: scope contract potential action names an ineligible surface');
                }
            }
        }
    }

    /** @param mixed $providers */
    private static function assert_potential_providers(mixed $providers): void {
        if (!is_array($providers) || !array_is_list($providers)) {
            throw new \RuntimeException('duo: scope contract potential_providers must be a list');
        }
        foreach ($providers as $row) {
            self::assert_keys($row, ['declaration', 'id', 'potential_action_sources', 'potential_only'], 'scope contract potential provider');
            if (!is_string($row['id']) || $row['id'] === '' || !is_array($row['declaration'])
                || ($row['potential_only'] ?? null) !== true) {
                throw new \RuntimeException('duo: scope contract potential provider is malformed');
            }
            self::assert_string_set($row['potential_action_sources'], 'potential provider action sources');
        }
    }

    /** @param mixed $effects */
    private static function assert_potential_effects(mixed $effects): void {
        if (!is_array($effects) || !array_is_list($effects)) {
            throw new \RuntimeException('duo: scope contract potential_effects must be a list');
        }
        foreach ($effects as $row) {
            self::assert_keys($row, ['basis', 'effect', 'manifest', 'phase', 'potential_only', 'source'], 'scope contract potential effect');
            if (!is_string($row['basis']) || $row['basis'] === '' || !is_array($row['effect'])
                || !is_string($row['manifest']) || $row['manifest'] === '' || !in_array($row['phase'], ['rebuild', 'regenerator'], true)
                || !is_string($row['source']) || $row['source'] === '' || ($row['potential_only'] ?? null) !== true) {
                throw new \RuntimeException('duo: scope contract potential effect is malformed or includes a forbidden phase');
            }
        }
    }

    /** @param mixed $value @param list<string> $expected */
    private static function assert_keys(mixed $value, array $expected, string $where): void {
        if (!is_array($value) || array_is_list($value)) {
            throw new \RuntimeException("duo: $where must be an object");
        }
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException("duo: $where has an unexpected schema");
        }
    }

    /** @param array<string,mixed> $withoutHash */
    private static function hash(array $withoutHash): string {
        unset($withoutHash['scope_hash']);
        return hash('sha256', Canon::encode($withoutHash));
    }

    private static function is_hash(mixed $value): bool {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/', $value) === 1;
    }

    /** @param list<mixed> $values @return list<string> */
    private static function sorted_strings(array $values): array {
        $out = array_values(array_unique(array_map('strval', $values)));
        sort($out, SORT_STRING);
        return $out;
    }
}

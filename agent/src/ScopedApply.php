<?php
namespace Duo;

/** Atomic duo_kv adapter for the generic scoped-session protocol. */
final class LedgerScopedApplySessionStorage implements ScopedApplySessionStorage {
    private static function assert_key(string $key): void {
        if ($key === ScopedApplySession::STORAGE_KEY) {
            return;
        }
        $suffix = '';
        foreach ([
            ScopedApplySession::TERMINAL_KEY_PREFIX,
            ScopedApplySession::TERMINAL_REQUEST_KEY_PREFIX,
        ] as $prefix) {
            if (str_starts_with($key, $prefix)) {
                $suffix = substr($key, strlen($prefix));
                break;
            }
        }
        if (preg_match('/^[a-f0-9]{64}$/', $suffix) !== 1) {
            throw new \RuntimeException('duo: scoped apply session storage key is outside the closed vocabulary');
        }
    }

    public function read(string $key): ?string {
        self::assert_key($key);
        return Ledger::kv_get($key);
    }

    public function compare_and_swap(string $key, ?string $expected, ?string $replacement): bool {
        self::assert_key($key);
        if ($replacement === '') {
            throw new \RuntimeException('duo: scoped apply session storage request is malformed');
        }
        global $wpdb;
        $table = $wpdb->prefix . 'duo_kv';
        if ($expected === null) {
            if ($replacement === null) {
                return $this->read($key) === null;
            }
            return (int) Db::query($wpdb->prepare(
                "INSERT IGNORE INTO `$table` (k, v) VALUES (%s, %s)",
                $key,
                $replacement
            ), 'scoped apply session begin CAS') === 1;
        }
        if ($replacement === null) {
            return (int) Db::query($wpdb->prepare(
                "DELETE FROM `$table` WHERE k = %s AND BINARY v = BINARY %s",
                $key,
                $expected
            ), 'scoped apply session archive CAS') === 1;
        }
        return (int) Db::query($wpdb->prepare(
            "UPDATE `$table` SET v = %s WHERE k = %s AND BINARY v = BINARY %s",
            $replacement,
            $key,
            $expected
        ), 'scoped apply session update CAS') === 1;
    }
}

/**
 * Pure projection and target-observation boundary for scoped plan/apply.
 *
 * `duo-scope-contract/v1` remains immutable source evidence. This class never
 * turns its potential action/effect rows into authority; it only resolves the
 * complete contract against the frozen artifact, projects the ordinary
 * three-way plan to its selected identities, and compiles a complete target
 * candidate so the existing ScopeContract/ReferenceGraph closure checks remain
 * the single source of truth.
 */
final class ScopedApply {
    public const PLAN_FORMAT = 'duo-scoped-plan/v1';
    public const CONVERGENCE_FORMAT = 'duo-scoped-convergence/v1';

    /** @return array<string,mixed> */
    public static function resolve_contract(
        array $request,
        CompiledRepository $compiled,
        Policy $policy
    ): array {
        if (($request['format'] ?? null) === ScopeContract::FORMAT) {
            $contract = ScopeContract::from_array($request);
            ScopeContract::assert_associated($contract, $compiled, $policy);
            return $contract;
        }
        $keys = array_keys($request);
        sort($keys, SORT_STRING);
        if ($keys !== ['format', 'scope_hash', 'selectors']
            || ($request['format'] ?? null) !== 'duo-scope-request/v1'
            || !is_array($request['selectors'] ?? null)
            || !array_is_list($request['selectors'])) {
            throw new \RuntimeException('duo: scoped plan/apply request has an unexpected schema');
        }
        return ScopedStateOverlay::resolve_request(
            $compiled,
            $policy,
            ScopeContract::normalize_selectors($request['selectors']),
            (string) ($request['scope_hash'] ?? '')
        );
    }

    /** @return array<string,true> */
    public static function selected_set(array $contract): array {
        return array_fill_keys(ScopedStateOverlay::selected_identities($contract), true);
    }

    /**
     * Project only entity-indexed target decisions. Global code/recovery facts
     * remain present because they are safety preconditions, not unrelated
     * authored rows a scoped caller may ignore.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    public static function project_plan(array $plan, array $contract): array {
        $selected = self::selected_set($contract);
        foreach ([
            'create', 'update', 'unchanged', 'drift', 'conflict', 'adopt',
            'collision', 'delete', 'delete_conflict', 'deleted', 'missing_user',
            'skipped_user_meta',
        ] as $bucket) {
            $plan[$bucket] = array_values(array_filter(
                (array) ($plan[$bucket] ?? []),
                static fn(array $row): bool => isset($selected[(string) ($row['uuid'] ?? '')])
            ));
        }
        // Global retry debt is intentionally not projected away. Scoped apply
        // refuses it rather than consuming or clearing somebody else's queue.
        foreach (['regen_pending', 'regen_context'] as $bucket) {
            $plan[$bucket] = array_values((array) ($plan[$bucket] ?? []));
        }
        return $plan;
    }

    /**
     * Complete strict target observation plus hash-only durable witnesses.
     *
     * The compiled candidate replaces selected target rows with the immutable
     * source intent and retains every target-observed out-of-scope row. That is
     * the state which would exist after the bounded authored transaction, so
     * ScopeContract::assert_candidate_bounded() catches target-only inbound
     * referrers, declared children, and selected outbound closure escapes before
     * any write. Target-only media which cannot be proven from repository-owned
     * content causes compilation to refuse conservatively.
     *
     * @param array<string,array{type:string,hash:string,content:string,path:string}> $actual
     * @return array<string,mixed>
     */
    public static function observe_target(
        string $repo,
        CompiledRepository $compiled,
        Policy $policy,
        array $contract,
        array $actual,
        ?array $sealedLedgerMapIdentityHashes = null
    ): array {
        ScopeContract::assert_associated($contract, $compiled, $policy);
        $selected = self::selected_set($contract);
        $rows = [];
        foreach ($actual as $identity => $row) {
            $identity = (string) $identity;
            if (isset($selected[$identity])) {
                continue;
            }
            $rows[] = [
                'uuid' => $identity,
                'type' => (string) ($row['type'] ?? ''),
                'path' => (string) ($row['path'] ?? ''),
                'content' => (string) ($row['content'] ?? ''),
            ];
        }
        foreach ($compiled->tree() as $identity => $row) {
            if (!isset($selected[(string) $identity])) {
                continue;
            }
            $rows[] = self::compiled_row((string) $identity, $row);
        }
        foreach ($compiled->deletions() as $identity => $row) {
            if (!isset($selected[(string) $identity])) {
                continue;
            }
            $rows[] = self::compiled_row((string) $identity, $row, 'deletion');
        }

        $stateView = ScopedStateOverlay::stage_state_view($rows);
        $mediaView = null;
        try {
            $mediaView = ScopedStateOverlay::stage_candidate_media_view($repo, []);
            $candidate = RepositoryCompiler::compile_staged($stateView, $repo, $policy, $mediaView);
            ScopeContract::assert_candidate_bounded($contract, $candidate, $policy);
        } finally {
            if (is_string($mediaView)) {
                ScopedStateOverlay::discard_media_view($mediaView);
            }
            ScopedStateOverlay::discard_state_view($stateView);
        }

        $selectedRows = [];
        $protectedRows = [];
        foreach ($actual as $identity => $row) {
            $evidence = [
                'identity_hash' => hash('sha256', (string) $identity),
                'type' => (string) ($row['type'] ?? ''),
                'content_hash' => (string) ($row['hash'] ?? ''),
            ];
            if (isset($selected[(string) $identity])) {
                $selectedRows[(string) $identity] = $evidence + ['state' => 'live'];
            } else {
                $protectedRows[] = $evidence;
            }
        }
        foreach (array_keys($selected) as $identity) {
            if (!isset($selectedRows[$identity])) {
                $selectedRows[$identity] = [
                    'identity_hash' => hash('sha256', $identity),
                    'state' => 'absent',
                    'type' => '',
                    'content_hash' => hash('sha256', 'duo:absent'),
                ];
            }
        }
        ksort($selectedRows, SORT_STRING);
        usort($protectedRows, static fn(array $a, array $b): int =>
            [$a['identity_hash'], $a['type'], $a['content_hash']]
                <=> [$b['identity_hash'], $b['type'], $b['content_hash']]
        );
        // The first pre-mutation observation derives opaque UUID hashes from
        // both the frozen source and the selected target rows. Once authority
        // exists, reuse its sealed hashes: a widget/menu-item removal must not
        // turn its former ledger row into protected state merely because it is
        // absent from the post-mutation tree.
        $ledgerMapIdentityHashes = $sealedLedgerMapIdentityHashes === null
            ? self::ledger_map_identity_hashes($contract, $compiled, $actual)
            : self::normalize_ledger_map_identity_hashes($sealedLedgerMapIdentityHashes);
        $mapRoots = self::ledger_map_roots($ledgerMapIdentityHashes);
        $roots = [
            'selected_before_root' => self::hash_rows(array_values($selectedRows)),
            'protected_out_of_scope_root' => self::hash_rows($protectedRows),
        ] + $mapRoots;
        $roots['target_observation_hash'] = hash('sha256', Canon::encode($roots));
        return $roots + [
            // Private, in-process recovery comparison only. Durable/public
            // session records consume the roots above, never these raw rows.
            '_selected_rows' => $selectedRows,
            '_protected_rows' => $protectedRows,
            '_ledger_map_identity_hashes' => $ledgerMapIdentityHashes,
        ];
    }

    /**
     * Expand selected file identities to opaque UUID hashes whose ledger rows
     * their owned nested records may legitimately change. A selected
     * sidebar/menu is a file-level scope root, while its widget/menu-item
     * UUIDs are nested identities, so both the frozen source (new children)
     * and the selected pre-mutation target record (removed/target-only
     * children) contribute. Raw UUIDs never leave this helper.
     *
     * @param array<string,array{type:string,hash:string,content:string,path:string}> $actual
     * @return list<string>
     */
    public static function ledger_map_identity_hashes(
        array $contract,
        CompiledRepository $compiled,
        array $actual
    ): array {
        $selectedEntities = self::selected_set($contract);
        $selectedMapIdentities = [];
        foreach (array_keys($selectedEntities) as $identity) {
            if (Uuid::is((string) $identity)) {
                $selectedMapIdentities[(string) $identity] = true;
            }
        }
        foreach ([
            ReferenceGraph::owners($compiled->tree()),
            ReferenceGraph::owners(self::selected_observed_owner_tree($actual, $selectedEntities)),
        ] as $owners) {
            foreach ($owners as $uuid => $owner) {
                if (isset($selectedEntities[(string) ($owner['entity'] ?? '')])) {
                    $uuid = (string) $uuid;
                    if (!Uuid::is($uuid)) {
                        throw new \RuntimeException('duo: scoped ledger map owner has an invalid UUID identity');
                    }
                    $selectedMapIdentities[$uuid] = true;
                }
            }
        }
        return self::normalize_ledger_map_identity_hashes(array_map(
            static fn(string $uuid): string => hash('sha256', $uuid),
            array_keys($selectedMapIdentities)
        ));
    }

    /**
     * Hash-only identity-map roots for one sealed selected/protected partition.
     *
     * @return array{ledger_map_root:string,protected_ledger_map_root:string,selected_ledger_map_root:string}
     */
    public static function ledger_map_roots(array $ledgerMapIdentityHashes): array {
        $selected = array_fill_keys(self::normalize_ledger_map_identity_hashes($ledgerMapIdentityHashes), true);
        $map = Ledger::all_map();
        usort($map, static fn(array $a, array $b): int => [
            (string) ($a['uuid'] ?? ''), (string) ($a['id_kind'] ?? ''), (int) ($a['local_id'] ?? 0),
        ] <=> [
            (string) ($b['uuid'] ?? ''), (string) ($b['id_kind'] ?? ''), (int) ($b['local_id'] ?? 0),
        ]);
        $protectedMap = array_values(array_filter(
            $map,
            static fn(array $row): bool => !isset($selected[hash('sha256', (string) ($row['uuid'] ?? ''))])
        ));
        $selectedMap = array_values(array_filter(
            $map,
            static fn(array $row): bool => isset($selected[hash('sha256', (string) ($row['uuid'] ?? ''))])
        ));
        return [
            'ledger_map_root' => hash('sha256', Canon::encode($map)),
            'protected_ledger_map_root' => hash('sha256', Canon::encode($protectedMap)),
            'selected_ledger_map_root' => hash('sha256', Canon::encode($selectedMap)),
        ];
    }

    /**
     * ReferenceGraph expects typed tree rows, while a target observation is a
     * compact capture projection. Only sidebar/menu records can own nested
     * ledger identities, so decode just selected records of those two generic
     * owner types instead of introducing a second owner walker.
     *
     * @param array<string,array{type:string,hash:string,content:string,path:string}> $actual
     * @param array<string,true> $selectedEntities
     * @return array<string,array<string,mixed>>
     */
    private static function selected_observed_owner_tree(array $actual, array $selectedEntities): array {
        $tree = [];
        foreach ($actual as $identity => $row) {
            $identity = (string) $identity;
            if (!isset($selectedEntities[$identity])) {
                continue;
            }
            $type = (string) ($row['type'] ?? '');
            if ($type !== 'menu' && $type !== SidebarState::ENTITY_TYPE) {
                continue;
            }
            try {
                $data = Canon::decode((string) ($row['content'] ?? ''));
            } catch (\Throwable $failure) {
                throw new \RuntimeException(
                    "duo: scoped target observation has malformed selected $type owner '$identity'",
                    0,
                    $failure
                );
            }
            if (!is_array($data)) {
                throw new \RuntimeException(
                    "duo: scoped target observation has non-object selected $type owner '$identity'"
                );
            }
            $tree[$identity] = ['type' => $type, 'data' => $data];
        }
        return $tree;
    }

    /** @param list<string> $identityHashes @return list<string> */
    private static function normalize_ledger_map_identity_hashes(array $identityHashes): array {
        $out = [];
        foreach ($identityHashes as $identityHash) {
            if (!is_string($identityHash) || preg_match('/^[a-f0-9]{64}$/D', $identityHash) !== 1) {
                throw new \RuntimeException('duo: scoped ledger map selection has an invalid opaque identity hash');
            }
            $out[$identityHash] = true;
        }
        $identityHashes = array_keys($out);
        sort($identityHashes, SORT_STRING);
        return $identityHashes;
    }

    /**
     * Decide whether an exact terminal receipt is still replayable without
     * mutation. The authority owns pre-transaction witnesses; the terminal
     * receipt owns the selected identity-map root after finalization.
     *
     * @param array<string,array<string,mixed>> $actual
     * @param array<string,mixed> $authority
     * @param array<string,mixed> $terminal
     * @param array<string,mixed> $observation
     */
    public static function terminal_replay_matches(
        array $actual,
        CompiledRepository $compiled,
        Policy $policy,
        array $contract,
        array $authority,
        array $terminal,
        array $observation
    ): bool {
        return self::authored_state(
            $actual,
            $compiled,
            $policy,
            $contract,
            (string) ($authority['target']['selected_before_hash'] ?? '')
        ) === 'desired'
            && hash_equals(
                (string) ($authority['target']['protected_out_of_scope_hash'] ?? ''),
                (string) ($observation['protected_out_of_scope_root'] ?? '')
            )
            && hash_equals(
                (string) ($authority['target']['protected_ledger_map_hash'] ?? ''),
                (string) ($observation['protected_ledger_map_root'] ?? '')
            )
            && hash_equals(
                (string) ($terminal['selected_ledger_map_hash'] ?? ''),
                (string) ($observation['selected_ledger_map_root'] ?? '')
            );
    }

    /** @return array<string,mixed> */
    private static function compiled_row(string $identity, array $row, ?string $type = null): array {
        return [
            'uuid' => $identity,
            'type' => $type ?? (string) ($row['type'] ?? ''),
            'path' => (string) ($row['path'] ?? ''),
            'content' => (string) ($row['content'] ?? ''),
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    public static function hash_rows(array $rows): string {
        return hash('sha256', Canon::encode(array_values($rows)));
    }

    /**
     * Classify an ambiguous authored-transaction boundary without writing.
     * `before` means the exact selected pre-root is intact; `desired` means
     * every selected live entity semantically equals the frozen artifact and
     * every selected tombstone is absent; anything else is mixed/changed and
     * must enter recovery_required rather than replay.
     *
     * @param array<string,array<string,mixed>> $actual
     */
    public static function authored_state(
        array $actual,
        CompiledRepository $compiled,
        Policy $policy,
        array $contract,
        string $selectedBeforeRoot
    ): string {
        $selected = self::selected_set($contract);
        $rows = [];
        foreach (array_keys($selected) as $identity) {
            $observed = $actual[$identity] ?? null;
            if (is_array($observed)) {
                $rows[$identity] = [
                    'identity_hash' => hash('sha256', $identity),
                    'type' => (string) ($observed['type'] ?? ''),
                    'content_hash' => (string) ($observed['hash'] ?? ''),
                    'state' => 'live',
                ];
            } else {
                $rows[$identity] = [
                    'identity_hash' => hash('sha256', $identity),
                    'state' => 'absent',
                    'type' => '',
                    'content_hash' => hash('sha256', 'duo:absent'),
                ];
            }
        }
        ksort($rows, SORT_STRING);
        $matchesBefore = hash_equals($selectedBeforeRoot, self::hash_rows(array_values($rows)));

        $matchesDesired = true;
        foreach (array_keys($selected) as $identity) {
            $expected = $compiled->tree()[$identity] ?? null;
            $observed = $actual[$identity] ?? null;
            if (is_array($expected)) {
                if (!is_array($observed)) {
                    if (($expected['type'] ?? '') === 'user-meta'
                        && $policy->user_meta_missing_behavior((array) ($expected['data']['meta'] ?? [])) === 'warn') {
                        continue;
                    }
                    $matchesDesired = false;
                    break;
                }
                if (!hash_equals((string) ($expected['type'] ?? ''), (string) ($observed['type'] ?? ''))) {
                    $matchesDesired = false;
                    break;
                }
                $expectedHash = ($expected['type'] ?? '') === 'post'
                    ? (string) ($expected['hash'] ?? '')
                    : hash('sha256', Canon::encode((array) ($expected['data'] ?? [])));
                try {
                    $observedHash = ($expected['type'] ?? '') === 'post'
                        ? (string) ($observed['hash'] ?? '')
                        : hash('sha256', Canon::encode(Canon::decode((string) ($observed['content'] ?? ''))));
                } catch (\Throwable $failure) {
                    $matchesDesired = false;
                    break;
                }
                if (!hash_equals($expectedHash, $observedHash)) {
                    $matchesDesired = false;
                    break;
                }
                continue;
            }
            if (isset($compiled->deletions()[$identity])) {
                if (is_array($observed)) {
                    $matchesDesired = false;
                    break;
                }
                continue;
            }
            $matchesDesired = false;
            break;
        }
        if ($matchesDesired) {
            // When selected before-state and desired-state are identical, the
            // operation is a genuine no-op. Classify it as converged so the
            // session records an authored receipt without opening an empty
            // transaction and then falsely demanding a changed readback.
            return 'desired';
        }
        return $matchesBefore ? 'before' : 'mixed';
    }

    /** Hash the exact target code/lifecycle observation sealed by scoped authority. */
    public static function code_witness_hash(array $plan, CompiledRepository $compiled): string {
        return hash('sha256', Canon::encode([
            'code_revision' => $compiled->code_revision(),
            'code_mismatch' => (array) ($plan['code_mismatch'] ?? []),
            'code_drift' => (array) ($plan['code_drift'] ?? []),
        ]));
    }
}

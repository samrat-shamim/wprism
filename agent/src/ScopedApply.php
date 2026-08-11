<?php
namespace Duo;

require_once __DIR__ . '/CommandRefusal.php';

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
        $map = Ledger::all_map();
        self::assert_selected_ledger_map_observation_from_rows(
            $policy,
            $contract,
            $actual,
            $ledgerMapIdentityHashes,
            $map
        );
        $mapRoots = self::ledger_map_roots($ledgerMapIdentityHashes, $map);
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
    public static function ledger_map_roots(array $ledgerMapIdentityHashes, ?array $map = null): array {
        $selected = array_fill_keys(self::normalize_ledger_map_identity_hashes($ledgerMapIdentityHashes), true);
        $map ??= Ledger::all_map();
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
     * Refuse selected ledger rows which a strict target capture did not prove.
     *
     * Capture::snapshot_read_only() already proves, without DML, the physical
     * row, embedded UUID, entity type, id_kind, and local id for every live
     * canonical identity it returns. This check closes the inverse: every
     * existing row in the sealed selected ledger partition must correspond to
     * one of those exact observed direct or nested identities, and every
     * observed selected identity must remain in the sealed partition. A
     * source-new identity may have no target map yet; if a stale map bearing
     * that UUID exists, it is refused instead of being trusted as a live id.
     * Unselected map rows are deliberately neither inspected nor pruned.
     *
     * @param array<string,array{type:string,hash:string,content:string,path:string}> $actual
     * @param list<string> $ledgerMapIdentityHashes
     */
    public static function assert_selected_ledger_map_observation(
        Policy $policy,
        array $contract,
        array $actual,
        array $ledgerMapIdentityHashes
    ): void {
        self::assert_selected_ledger_map_observation_from_rows(
            $policy,
            $contract,
            $actual,
            $ledgerMapIdentityHashes,
            Ledger::all_map()
        );
    }

    /**
     * @param array<string,array{type:string,hash:string,content:string,path:string}> $actual
     * @param list<string> $ledgerMapIdentityHashes
     * @param list<array{uuid:string,entity_type:string,id_kind:string,local_id:int}> $map
     */
    private static function assert_selected_ledger_map_observation_from_rows(
        Policy $policy,
        array $contract,
        array $actual,
        array $ledgerMapIdentityHashes,
        array $map
    ): void {
        $selectedEntities = self::selected_set($contract);
        $sealed = array_fill_keys(self::normalize_ledger_map_identity_hashes($ledgerMapIdentityHashes), true);
        $expected = [];
        $addExpected = static function (
            string $uuid,
            string $kind,
            string $entityType,
            array $details = []
        ) use (&$expected): void {
            if (!Uuid::is($uuid) || $kind === '' || $entityType === '') {
                throw CommandRefusalException::scopedIdentityRecoveryRequired();
            }
            $evidence = ['entity_type' => $entityType] + $details;
            if (isset($expected[$uuid][$kind])
                && Canon::encode($expected[$uuid][$kind]) !== Canon::encode($evidence)) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired();
            }
            $expected[$uuid][$kind] = $evidence;
        };

        $rowTables = Snapshot::row_tables($policy);
        foreach ($actual as $identity => $row) {
            $identity = (string) $identity;
            if (!isset($selectedEntities[$identity]) || !Uuid::is($identity)) {
                continue;
            }
            $type = (string) ($row['type'] ?? '');
            try {
                $data = Canon::decode((string) ($row['content'] ?? ''));
            } catch (\Throwable $failure) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired($failure);
            }
            if (!is_array($data)) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired();
            }
            if ($type === 'post') {
                $addExpected($identity, Ledger::KIND_POST, 'post', [
                    'post_type' => (string) ($data['type'] ?? ''),
                ]);
            } elseif ($type === 'term' || $type === 'menu') {
                $taxonomy = $type === 'menu' ? 'nav_menu' : (string) ($data['taxonomy'] ?? '');
                $addExpected($identity, Ledger::KIND_TERM, $type, ['taxonomy' => $taxonomy]);
                $addExpected($identity, Ledger::KIND_TT, $type, ['taxonomy' => $taxonomy]);
            } elseif (isset($rowTables[$type])) {
                $addExpected(
                    $identity,
                    (string) ($rowTables[$type]['id_kind'] ?? ''),
                    $type,
                    ['table' => $type]
                );
            }
        }

        foreach (self::selected_observed_owner_tree($actual, $selectedEntities) as $ownerIdentity => $owner) {
            $type = (string) ($owner['type'] ?? '');
            if ($type === 'menu') {
                foreach ((array) ($owner['data']['items'] ?? []) as $item) {
                    $addExpected(
                        (string) ($item['uuid'] ?? ''),
                        Ledger::KIND_POST,
                        'menu_item',
                        ['owner' => (string) $ownerIdentity, 'post_type' => 'nav_menu_item']
                    );
                }
            } elseif ($type === SidebarState::ENTITY_TYPE) {
                $sidebar = str_starts_with((string) $ownerIdentity, 'sidebar/')
                    ? substr((string) $ownerIdentity, strlen('sidebar/'))
                    : '';
                foreach ((array) ($owner['data']['widgets'] ?? []) as $widget) {
                    $widgetType = (string) ($widget['type'] ?? '');
                    $addExpected(
                        (string) ($widget['uuid'] ?? ''),
                        SidebarState::kind($widgetType),
                        'widget',
                        ['owner' => $sidebar, 'widget_type' => $widgetType]
                    );
                }
            }
        }

        $tombstones = [];
        foreach ((array) ($contract['tombstones'] ?? []) as $tombstone) {
            $uuid = (string) ($tombstone['uuid'] ?? '');
            if (Uuid::is($uuid) && isset($selectedEntities[$uuid])) {
                $tombstones[$uuid] = (array) ($tombstone['deletion'] ?? []);
            }
        }
        $mapByIdentityKind = [];
        foreach ($map as $row) {
            $mapByIdentityKind[(string) ($row['uuid'] ?? '')][(string) ($row['id_kind'] ?? '')] = $row;
        }
        $observed = [];
        foreach ($expected as $uuid => $kinds) {
            if (!isset($sealed[hash('sha256', $uuid)])) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired();
            }
            foreach ($kinds as $kind => $_evidence) {
                $observed[$uuid][$kind] = false;
            }
        }
        foreach ($map as $row) {
            $uuid = (string) ($row['uuid'] ?? '');
            if (!isset($sealed[hash('sha256', $uuid)])) {
                continue;
            }
            $kind = (string) ($row['id_kind'] ?? '');
            $entityType = (string) ($row['entity_type'] ?? '');
            if (!isset($expected[$uuid][$kind])) {
                if (!isset($expected[$uuid])
                    && isset($tombstones[$uuid])
                    && self::settled_tombstone_map_row_is_absent($policy, $tombstones[$uuid], $row)) {
                    continue;
                }
                throw CommandRefusalException::scopedIdentityRecoveryRequired();
            }
            if ((string) ($expected[$uuid][$kind]['entity_type'] ?? '') !== $entityType) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired();
            }
            self::assert_selected_map_row_physical(
                $policy,
                $uuid,
                $kind,
                $row,
                $expected[$uuid][$kind],
                $mapByIdentityKind
            );
            $observed[$uuid][$kind] = true;
        }
        foreach ($observed as $kinds) {
            if (in_array(false, $kinds, true)) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired();
            }
        }
    }

    /**
     * Re-bind one selected tuple's exact local_id to the current physical row
     * after the strict snapshot transaction has closed.
     *
     * @param array{uuid:string,entity_type:string,id_kind:string,local_id:int} $row
     * @param array<string,mixed> $evidence
     * @param array<string,array<string,array<string,mixed>>> $mapByIdentityKind
     */
    private static function assert_selected_map_row_physical(
        Policy $policy,
        string $uuid,
        string $kind,
        array $row,
        array $evidence,
        array $mapByIdentityKind
    ): void {
        global $wpdb;
        $localId = (int) ($row['local_id'] ?? 0);
        $entityType = (string) ($evidence['entity_type'] ?? '');
        if ($localId <= 0) {
            throw CommandRefusalException::scopedIdentityRecoveryRequired();
        }
        if ($entityType === 'post' || $entityType === 'menu_item') {
            if ($entityType === 'menu_item') {
                $owner = (string) ($evidence['owner'] ?? '');
                $ownerTt = (int) ($mapByIdentityKind[$owner][Ledger::KIND_TT]['local_id'] ?? 0);
                if ($ownerTt <= 0) {
                    throw CommandRefusalException::scopedIdentityRecoveryRequired();
                }
                $query = $wpdb->prepare(
                    "SELECT p.ID, p.post_type, pm.meta_value AS duo_uuid FROM {$wpdb->posts} p"
                    . " LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s"
                    . " JOIN {$wpdb->term_relationships} tr"
                    . ' ON tr.object_id = p.ID AND tr.term_taxonomy_id = %d'
                    . ' WHERE p.ID = %d ORDER BY pm.meta_id ASC LIMIT 1',
                    '_duo_uuid',
                    $ownerTt,
                    $localId
                );
            } else {
                $query = $wpdb->prepare(
                    "SELECT p.ID, p.post_type, pm.meta_value AS duo_uuid FROM {$wpdb->posts} p"
                    . " LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s"
                    . ' WHERE p.ID = %d ORDER BY pm.meta_id ASC LIMIT 1',
                    '_duo_uuid',
                    $localId
                );
            }
            $physical = self::checked_target_row($query);
            if ($physical === null
                || (int) ($physical['ID'] ?? 0) !== $localId
                || (string) ($physical['duo_uuid'] ?? '') !== $uuid
                || (string) ($physical['post_type'] ?? '') !== (string) ($evidence['post_type'] ?? '')) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired();
            }
            try {
                Ledger::require_read_only_mapping($uuid, $entityType, $kind, $localId, 'selected post identity');
            } catch (\Throwable $failure) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired($failure);
            }
            return;
        }
        if ($entityType === 'term' || $entityType === 'menu') {
            $termMap = $mapByIdentityKind[$uuid][Ledger::KIND_TERM] ?? null;
            $ttMap = $mapByIdentityKind[$uuid][Ledger::KIND_TT] ?? null;
            $termId = (int) ($termMap['local_id'] ?? 0);
            $ttId = (int) ($ttMap['local_id'] ?? 0);
            if ($termId <= 0 || $ttId <= 0) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired();
            }
            $physical = self::checked_target_row($wpdb->prepare(
                "SELECT t.term_id, tt.term_taxonomy_id, tt.taxonomy, tm.meta_value AS duo_uuid"
                . " FROM {$wpdb->terms} t"
                . " JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id"
                . " LEFT JOIN {$wpdb->termmeta} tm ON tm.term_id = t.term_id AND tm.meta_key = %s"
                . ' WHERE t.term_id = %d AND tt.term_taxonomy_id = %d'
                . ' ORDER BY tm.meta_id ASC LIMIT 1',
                '_duo_uuid',
                $termId,
                $ttId
            ));
            if ($physical === null
                || (int) ($physical['term_id'] ?? 0) !== $termId
                || (int) ($physical['term_taxonomy_id'] ?? 0) !== $ttId
                || (string) ($physical['taxonomy'] ?? '') !== (string) ($evidence['taxonomy'] ?? '')
                || (string) ($physical['duo_uuid'] ?? '') !== $uuid) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired();
            }
            try {
                Ledger::require_read_only_mapping($uuid, $entityType, Ledger::KIND_TERM, $termId, 'selected term identity');
                Ledger::require_read_only_mapping($uuid, $entityType, Ledger::KIND_TT, $ttId, 'selected taxonomy identity');
            } catch (\Throwable $failure) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired($failure);
            }
            return;
        }
        if ($entityType === 'widget') {
            SidebarState::assert_read_only_selected_mapping(
                $policy,
                $uuid,
                (string) ($evidence['widget_type'] ?? ''),
                $localId,
                (string) ($evidence['owner'] ?? '')
            );
            return;
        }
        if (($evidence['table'] ?? null) === $entityType) {
            Snapshot::assert_read_only_selected_mapping($policy, $entityType, $uuid, $kind, $localId);
            return;
        }
        throw CommandRefusalException::scopedIdentityRecoveryRequired();
    }

    /**
     * Selected tombstones which are already physically settled may retain
     * their direct map rows until the final selected ledger transaction calls
     * Ledger::forget(). Any surviving physical row is partial deletion state,
     * not permission to trust the stale tuple.
     *
     * @param array<string,mixed> $deletion
     * @param array{uuid:string,entity_type:string,id_kind:string,local_id:int} $row
     */
    private static function settled_tombstone_map_row_is_absent(
        Policy $policy,
        array $deletion,
        array $row
    ): bool {
        global $wpdb;
        $kind = (string) ($row['id_kind'] ?? '');
        $entityType = (string) ($row['entity_type'] ?? '');
        $localId = (int) ($row['local_id'] ?? 0);
        $deletionKind = (string) ($deletion['kind'] ?? '');
        $deletionType = (string) ($deletion['type'] ?? '');
        if ($localId <= 0) {
            return false;
        }
        if ($deletionKind === 'post') {
            return $kind === Ledger::KIND_POST
                && $entityType === 'post'
                && !self::checked_target_exists($wpdb->prepare(
                    "SELECT 1 FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
                    $localId
                ));
        }
        if ($deletionKind === 'term' || $deletionKind === 'menu') {
            $expectedType = $deletionKind === 'menu' ? 'menu' : 'term';
            if ($entityType !== $expectedType || !in_array($kind, [Ledger::KIND_TERM, Ledger::KIND_TT], true)) {
                return false;
            }
            if ($kind === Ledger::KIND_TERM) {
                return !self::checked_target_exists($wpdb->prepare(
                    "SELECT 1 FROM {$wpdb->terms} WHERE term_id = %d LIMIT 1",
                    $localId
                )) && !self::checked_target_exists($wpdb->prepare(
                    "SELECT 1 FROM {$wpdb->term_taxonomy} WHERE term_id = %d LIMIT 1",
                    $localId
                ));
            }
            return !self::checked_target_exists($wpdb->prepare(
                "SELECT 1 FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d LIMIT 1",
                $localId
            ));
        }
        if ($deletionKind === 'table') {
            $decl = Snapshot::row_tables($policy)[$deletionType] ?? null;
            return is_array($decl)
                && (string) ($decl['id_kind'] ?? '') === $kind
                && $entityType === $deletionType
                && !Snapshot::read_only_mapped_row_exists($policy, $deletionType, $localId);
        }
        return false;
    }

    /** @return array<string,mixed>|null */
    private static function checked_target_row(string $sql): ?array {
        global $wpdb;
        $wpdb->last_error = '';
        $row = $wpdb->get_row($sql, ARRAY_A);
        if ($row === false || !empty($wpdb->last_error)) {
            throw CommandRefusalException::scopedIdentityRecoveryRequired();
        }
        return is_array($row) ? $row : null;
    }

    private static function checked_target_exists(string $sql): bool {
        global $wpdb;
        $wpdb->last_error = '';
        $value = $wpdb->get_var($sql);
        if ($value === false || !empty($wpdb->last_error)) {
            throw CommandRefusalException::scopedIdentityRecoveryRequired();
        }
        return $value !== null;
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

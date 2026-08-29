<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Kernel/OptionState.php';
require_once __DIR__ . '/../Apply/ApplyPlanner.php';
require_once __DIR__ . '/../Policy/ScopeClosure.php';
require_once __DIR__ . '/../Repository/CanonicalMapWitness.php';
require_once __DIR__ . '/ScopedApplySession.php';

/** Atomic wprism_kv adapter for the generic scoped-session protocol. */
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
            throw new \RuntimeException('wprism: scoped apply session storage key is outside the closed vocabulary');
        }
    }

    public function read(string $key): ?string {
        self::assert_key($key);
        return Ledger::kv_get($key);
    }

    public function compare_and_swap(string $key, ?string $expected, ?string $replacement): bool {
        self::assert_key($key);
        if ($replacement === '') {
            throw new \RuntimeException('wprism: scoped apply session storage request is malformed');
        }
        global $wpdb;
        $table = $wpdb->prefix . 'wprism_kv';
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
 * `wprism-scope-contract/v1` remains immutable source evidence. This class never
 * turns its potential action/effect rows into authority; it only resolves the
 * complete contract against the frozen artifact, projects the ordinary
 * three-way plan to its selected identities, and compiles a complete target
 * candidate so the existing ScopeContract/ReferenceGraph closure checks remain
 * the single source of truth.
 */
final class ScopedApply {
    public const PLAN_FORMAT = 'wprism-scoped-plan/v1';
    public const CONVERGENCE_FORMAT = 'wprism-scoped-convergence/v1';

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
            || ($request['format'] ?? null) !== 'wprism-scope-request/v1'
            || !is_array($request['selectors'] ?? null)
            || !array_is_list($request['selectors'])) {
            throw new \RuntimeException('wprism: scoped plan/apply request has an unexpected schema');
        }
        $contract = ScopedStateOverlay::resolve_request(
            $compiled,
            $policy,
            ScopeContract::normalize_selectors($request['selectors']),
            (string) ($request['scope_hash'] ?? '')
        );
        return $contract;
    }

    /** @return array<string,true> */
    public static function selected_set(array $contract): array {
        $selected = array_fill_keys(ScopedStateOverlay::selected_identities($contract), true);
        // `options` owns the complete physical carrier. A redundant
        // `option:<name>` selector must not turn into a second synthetic
        // selected row alongside it: keep the whole-carrier contract's
        // ordinary semantics rather than manufacturing an absent virtual row.
        if (isset($selected['options/core'])) {
            foreach (array_keys($selected) as $identity) {
                if (ScopeClosure::is_option_root($identity)) {
                    unset($selected[$identity]);
                }
            }
        }
        return $selected;
    }

    /** True when virtual option roots, rather than the whole carrier, are selected. */
    public static function has_record_scoped_options(array $contract): bool {
        return ScopeContract::option_root_names($contract) !== []
            && !isset(self::selected_set($contract)['options/core']);
    }

    /** @return list<string> */
    public static function option_root_names(array $contract): array {
        return ScopeContract::option_root_names($contract);
    }

    /**
     * `wprism_state.uuid` is deliberately limited to 64 characters, while an
     * authored wp_options name can be longer than a virtual
     * `options/core#<name>` identity. Keep option bases in the same durable
     * table using a disjoint, domain-separated 252-bit token. The leading
     * non-hex byte cannot collide with ordinary UUID/hash state identities.
     */
    public static function option_state_identity(string $name): string {
        if ($name === '' || str_contains($name, "\0")) {
            throw new \RuntimeException('wprism: scoped option state identity has an invalid option name');
        }
        return 'o' . substr(hash('sha256', "wprism:scoped-option-state/v1\0" . $name), 0, 63);
    }

    /** @return array<string,array<string,mixed>> selected name => canonical record */
    public static function selected_option_records(array $document, array $contract): array {
        $records = OptionState::records($document);
        $out = [];
        foreach (self::option_root_names($contract) as $name) {
            if (!array_key_exists($name, $records)) {
                throw new \RuntimeException("wprism: scoped option '$name' disappeared from the frozen carrier");
            }
            $out[$name] = $records[$name];
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @return array<string,string> durable option-state identity => record hash */
    public static function option_state_hashes(array $document, array $contract, ?array $names = null): array {
        $allowed = array_fill_keys(self::option_root_names($contract), true);
        $wanted = $names === null
            ? null
            : array_fill_keys(array_map('strval', $names), true);
        $out = [];
        foreach (OptionState::records($document) as $name => $record) {
            if (!isset($allowed[$name])) {
                if ($wanted !== null) {
                    throw new \RuntimeException('wprism: scoped option state row escaped its selected records');
                }
                continue;
            }
            if ($wanted === null || isset($wanted[$name])) {
                $out[self::option_state_identity($name)] = OptionState::record_hash($record);
            }
        }
        if ($wanted !== null) {
            $documentRecords = OptionState::records($document);
            foreach (array_keys($wanted) as $name) {
                if (!isset($allowed[$name]) || !array_key_exists($name, $documentRecords)) {
                    throw new \RuntimeException('wprism: scoped option state row omitted a selected record');
                }
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * Give OptionsMaterializer exactly the names authorized by one bounded
     * plan row. The caller still supplies the complete frozen document as
     * read-only classification context: a manifest-owned interpreter may
     * need an excluded companion record to classify a selected record, but
     * that companion never reaches the materialization write set.
     */
    public static function selected_option_document(array $document, array $contract, array $row): array {
        $allowed = array_fill_keys(self::option_root_names($contract), true);
        $requested = (array) ($row['rebuild_option_names'] ?? []);
        $records = self::selected_option_records($document, $contract);
        $out = [];
        foreach ($requested as $name) {
            if (!is_string($name) || !isset($allowed[$name]) || !isset($records[$name])) {
                throw new \RuntimeException('wprism: scoped option plan row escaped its selected records');
            }
            $out[$name] = $records[$name];
        }
        return OptionState::document($out);
    }

    /**
     * Recovery replays the selected virtual records, not an ordinary physical
     * carrier retry.  The latter is intentionally widened by
     * CanonicalSurfaces and would make a sibling mutable after a lost response.
     *
     * @param array<string,mixed>|null $plannedRow
     * @param list<string> $names
     * @param array<string,mixed> $sourceRow
     * @return array<string,mixed>
     */
    public static function recovery_option_row(?array $plannedRow, array $names, array $sourceRow): array {
        $names = array_values(array_unique(array_map('strval', $names)));
        sort($names, SORT_STRING);
        if ($names === []) {
            throw new \RuntimeException('wprism: scoped option recovery has no selected records');
        }
        $row = $plannedRow ?? [
            'uuid' => 'options/core',
            'type' => 'options',
            'path' => (string) ($sourceRow['path'] ?? ''),
        ];
        if (($row['uuid'] ?? null) !== 'options/core' || ($row['type'] ?? null) !== 'options') {
            throw new \RuntimeException('wprism: scoped option recovery row is not the options carrier');
        }
        unset($row['retry']);
        $row['rebuild_option_names'] = $names;
        return $row;
    }

    /**
     * Build the complete post-apply candidate carrier from the target's
     * current sibling records plus source-owned selected records. The caller
     * stages this row beside every other observed target row before the
     * ordinary contract closure re-walk; no sibling is taken from source.
     *
     * @param array<string,mixed> $sourceRow
     * @param array<string,mixed> $targetRow
     * @return array<string,mixed>
     */
    public static function target_option_candidate_row(array $sourceRow, array $targetRow, array $contract): array {
        try {
            $source = OptionState::records(Canon::decode((string) ($sourceRow['content'] ?? '')));
            $target = OptionState::records(Canon::decode((string) ($targetRow['content'] ?? '')));
        } catch (\Throwable $failure) {
            throw new \RuntimeException('wprism: scoped options carrier is malformed during target observation', 0, $failure);
        }
        foreach (self::option_root_names($contract) as $name) {
            if (!array_key_exists($name, $source)) {
                throw new \RuntimeException("wprism: selected option '$name' disappeared from the scoped target carrier");
            }
            // `absent` is explicit no-value/no-delete intent. It is not a
            // request to erase a target-owned value, so the projected
            // post-apply carrier must retain that exact target record.
            if (($source[$name]['state'] ?? '') === 'absent') {
                continue;
            }
            if (!array_key_exists($name, $target)) {
                throw new \RuntimeException("wprism: selected option '$name' disappeared from the scoped target carrier");
            }
            $target[$name] = $source[$name];
        }
        $row = $targetRow;
        $row['uuid'] = 'options/core';
        $row['type'] = 'options';
        $row['path'] = (string) ($sourceRow['path'] ?? 'options/core.json');
        $row['content'] = Canon::encode(OptionState::document($target));
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private static function option_observation_rows(array $actual, array $contract, bool $selected): array {
        $carrier = $actual['options/core'] ?? null;
        if (!is_array($carrier)) {
            throw new \RuntimeException('wprism: scoped options target observation could not read options/core');
        }
        try {
            $records = OptionState::records(Canon::decode((string) ($carrier['content'] ?? '')));
        } catch (\Throwable $failure) {
            throw new \RuntimeException('wprism: scoped options target observation has malformed options/core', 0, $failure);
        }
        $roots = array_fill_keys(self::option_root_names($contract), true);
        $rows = [];
        foreach ($records as $name => $record) {
            if (isset($roots[$name]) !== $selected) {
                continue;
            }
            $identity = 'options/core#' . $name;
            $rows[] = [
                'identity_hash' => hash('sha256', $identity),
                'type' => 'option',
                'content_hash' => OptionState::record_hash($record),
                'state' => 'live',
            ];
        }
        usort($rows, static fn(array $a, array $b): int =>
            [$a['identity_hash'], $a['type'], $a['content_hash']]
                <=> [$b['identity_hash'], $b['type'], $b['content_hash']]
        );
        return $rows;
    }

    /**
     * Record-aware three-way plan decision for the physical options carrier.
     * A sibling carrier change is neither selected drift nor a conflict: it
     * remains protected target state and is never given to the materializer.
     *
     * @param array<string,array{entity_type:string,content_hash:string}> $base
     * @param list<string> $eligibleNames ApplyPlanner's existing non-managed,
     *   changed-record projection for this exact snapshot.
     * @return array{bucket:string,row:array<string,mixed>}
     */
    public static function option_plan_decision(
        array $desiredDocument,
        ?array $observed,
        array $base,
        array $contract,
        array $eligibleNames
    ): array {
        $eligible = array_fill_keys(array_map('strval', $eligibleNames), true);
        $desired = self::selected_option_records($desiredDocument, $contract);
        $observedRecords = [];
        if ($observed !== null) {
            try {
                $observedRecords = OptionState::records(Canon::decode((string) ($observed['content'] ?? '')));
            } catch (\Throwable $failure) {
                throw new \RuntimeException('wprism: scoped options target observation is malformed', 0, $failure);
            }
        }

        $updates = [];
        $drift = [];
        $conflicts = [];
        $deletes = [];
        $firstSync = false;
        foreach ($desired as $name => $record) {
            if (!isset($eligible[$name]) || ($record['state'] ?? '') === 'absent') {
                continue;
            }
            $actual = $observedRecords[$name] ?? OptionState::absent();
            if (($record['state'] ?? '') === 'deleted') {
                if (($actual['state'] ?? '') !== 'present') {
                    continue;
                }
                if (!hash_equals((string) ($record['expected_hash'] ?? ''), OptionState::record_hash($actual))) {
                    $conflicts[] = $name;
                    continue;
                }
                $baseHash = $base[self::option_state_identity($name)]['content_hash'] ?? null;
                if (is_string($baseHash) && hash_equals($baseHash, OptionState::record_hash($record))) {
                    // The selected tombstone was already the durable base;
                    // an exact-value recreation is still target drift, not
                    // fresh deletion authority.
                    $conflicts[] = $name;
                    continue;
                }
                $updates[] = $name;
                $deletes[] = $name;
                continue;
            }

            $desiredHash = OptionState::record_hash($record);
            $actualHash = OptionState::record_hash($actual);
            if (hash_equals($desiredHash, $actualHash)) {
                continue;
            }
            $baseHash = $base[self::option_state_identity($name)]['content_hash'] ?? null;
            if (!is_string($baseHash) || $baseHash === '') {
                $updates[] = $name;
                $firstSync = true;
            } elseif (hash_equals($actualHash, $baseHash)) {
                $updates[] = $name;
            } elseif (hash_equals($desiredHash, $baseHash)) {
                $drift[] = $name;
            } else {
                $conflicts[] = $name;
            }
        }
        sort($updates, SORT_STRING);
        sort($drift, SORT_STRING);
        sort($conflicts, SORT_STRING);
        sort($deletes, SORT_STRING);

        $row = ['rebuild_option_names' => array_values(array_unique(array_merge($updates, $conflicts)))];
        if ($deletes !== []) {
            $row['option_deletes'] = $deletes;
        }
        if ($drift !== []) {
            $row['option_drift_names'] = $drift;
        }
        if ($conflicts !== []) {
            $row['option_conflict_names'] = $conflicts;
            $row['conflict_view'] = ApplyPlanner::conflict_view(
                'selected_option_and_target_changed_since_base',
                'update',
                'record-scoped',
                null,
                null,
                null,
                null,
                null,
                array_merge($deletes === [] ? [] : ['--with-deletes'], ['--force-theirs'])
            );
            return ['bucket' => 'conflict', 'row' => $row];
        }
        if ($updates !== []) {
            if ($firstSync) {
                $row['first_sync'] = true;
            }
            return ['bucket' => 'update', 'row' => $row];
        }
        if ($drift !== []) {
            return ['bucket' => 'drift', 'row' => $row];
        }
        $row['rebuild_option_names'] = [];
        return ['bucket' => 'unchanged', 'row' => $row];
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
        $recordScopedOptions = self::has_record_scoped_options($contract);
        foreach ([
            'create', 'update', 'unchanged', 'drift', 'conflict', 'adopt',
            'collision', 'delete', 'delete_conflict', 'deleted', 'missing_user',
            'skipped_user_meta',
        ] as $bucket) {
            $plan[$bucket] = array_values(array_filter(
                (array) ($plan[$bucket] ?? []),
                static fn(array $row): bool => isset($selected[(string) ($row['uuid'] ?? '')])
                    || ($recordScopedOptions && ($row['uuid'] ?? null) === 'options/core')
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
        ?array $sealedLedgerMapIdentityHashes = null,
        bool $allowTargetOldMenuItems = false
    ): array {
        ScopeContract::assert_associated($contract, $compiled, $policy);
        $selected = self::selected_set($contract);
        $recordScopedOptions = self::has_record_scoped_options($contract);
        $rows = [];
        foreach ($actual as $identity => $row) {
            $identity = (string) $identity;
            if ($recordScopedOptions && $identity === 'options/core') {
                continue;
            }
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
            if ($recordScopedOptions && (string) $identity === 'options/core') {
                $targetCarrier = $actual['options/core'] ?? null;
                if (!is_array($targetCarrier)) {
                    throw new \RuntimeException('wprism: scoped options target observation could not read options/core');
                }
                $rows[] = self::target_option_candidate_row($row, $targetCarrier, $contract);
                continue;
            }
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
            if ($recordScopedOptions && (string) $identity === 'options/core') {
                foreach (self::option_observation_rows($actual, $contract, true) as $evidence) {
                    $selectedRows[(string) $evidence['identity_hash']] = $evidence;
                }
                foreach (self::option_observation_rows($actual, $contract, false) as $evidence) {
                    unset($evidence['state']);
                    $protectedRows[] = $evidence;
                }
                continue;
            }
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
            if ($recordScopedOptions && ScopeClosure::is_option_root($identity)) {
                $key = hash('sha256', $identity);
                if (!isset($selectedRows[$key])) {
                    throw new \RuntimeException("wprism: selected option '$identity' disappeared from the target observation");
                }
                continue;
            }
            if (!isset($selectedRows[$identity])) {
                $selectedRows[$identity] = [
                    'identity_hash' => hash('sha256', $identity),
                    'state' => 'absent',
                    'type' => '',
                    'content_hash' => hash('sha256', 'wprism:absent'),
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
        $map = Ledger::all_map();
        $ledgerMapIdentityHashes = $sealedLedgerMapIdentityHashes === null
            ? self::ledger_map_identity_hashes_from_map(
                $contract,
                $compiled,
                $actual,
                $map,
                $allowTargetOldMenuItems
            )
            : self::normalize_ledger_map_identity_hashes($sealedLedgerMapIdentityHashes);
        self::assert_selected_ledger_map_observation_from_rows(
            $policy,
            $contract,
            $actual,
            $ledgerMapIdentityHashes,
            $map,
            $compiled,
            $allowTargetOldMenuItems
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
     * children) contribute. Canonical menu content intentionally contains
     * published items only, but finalize_menu() considers every all-status
     * nonempty menu-item sidecar under its selected menu; strict physical
     * target ownership therefore adds only safe otherwise-hidden identities.
     * Raw UUIDs never leave this helper.
     *
     * @param array<string,array{type:string,hash:string,content:string,path:string}> $actual
     * @return list<string>
     */
    public static function ledger_map_identity_hashes(
        array $contract,
        CompiledRepository $compiled,
        array $actual,
        bool $allowTargetOldMenuItems = false
    ): array {
        return self::ledger_map_identity_hashes_from_map(
            $contract,
            $compiled,
            $actual,
            Ledger::all_map(),
            $allowTargetOldMenuItems
        );
    }

    /**
     * @param array<string,array{type:string,hash:string,content:string,path:string}> $actual
     * @param list<array{uuid:string,entity_type:string,id_kind:string,local_id:int}> $map
     * @return list<string>
     */
    private static function ledger_map_identity_hashes_from_map(
        array $contract,
        CompiledRepository $compiled,
        array $actual,
        array $map,
        bool $allowTargetOldMenuItems
    ): array {
        $selectedEntities = self::selected_set($contract);
        $selectedMapIdentities = [];
        foreach (array_keys($selectedEntities) as $identity) {
            if (Uuid::is((string) $identity)) {
                $selectedMapIdentities[(string) $identity] = true;
            }
        }
        $frozenOwners = ReferenceGraph::owners($compiled->tree());
        $targetOwners = ReferenceGraph::owners(
            self::selected_observed_owner_tree($actual, $selectedEntities)
        );
        foreach ($targetOwners as $uuid => $owner) {
            $ownerEntity = (string) ($owner['entity'] ?? '');
            $ownerKind = (string) ($owner['kind'] ?? '');
            if (!isset($selectedEntities[$ownerEntity])) {
                continue;
            }
            $uuid = (string) $uuid;
            if (!Uuid::is($uuid)) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired();
            }
            // Nested records are selected through their owning entity. A
            // target observation may introduce a genuinely target-only child,
            // but it may not move a source-known child from another owner
            // into this selection. That rule is generic for both widgets and
            // menu items and runs before the source/target ownership union.
            $frozenOwner = $frozenOwners[$uuid] ?? null;
            if (in_array($ownerKind, ['menu_item', 'widget'], true)
                && is_array($frozenOwner)
                && (string) ($frozenOwner['entity'] ?? '') !== $ownerEntity) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired();
            }
            $selectedMapIdentities[$uuid] = true;
        }
        foreach ($frozenOwners as $uuid => $owner) {
            if (isset($selectedEntities[(string) ($owner['entity'] ?? '')])) {
                $uuid = (string) $uuid;
                if (!Uuid::is($uuid)) {
                    throw CommandRefusalException::scopedIdentityRecoveryRequired();
                }
                $selectedMapIdentities[$uuid] = true;
            }
        }
        foreach (self::selected_physical_menu_item_owners(
            $compiled,
            $contract,
            $actual,
            $selectedEntities,
            $map,
            $allowTargetOldMenuItems
        ) as $uuid => $_owner) {
            $selectedMapIdentities[$uuid] = true;
        }
        foreach (self::selected_tombstone_menu_item_owners(
            $compiled,
            $contract,
            $actual,
            $selectedEntities,
            $map,
            $allowTargetOldMenuItems
        ) as $uuid => $_owner) {
            $selectedMapIdentities[$uuid] = true;
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

    /** Bind the physical selected map generation committed with authored rows. */
    public static function authored_ledger_map_hash(array $observation): string {
        $selectedLedgerMapRoot = $observation['selected_ledger_map_root'] ?? null;
        if (!is_string($selectedLedgerMapRoot)
            || preg_match('/^[a-f0-9]{64}$/D', $selectedLedgerMapRoot) !== 1) {
            throw new \RuntimeException('wprism: scoped authored readback has a malformed selected ledger-map root');
        }
        return hash('sha256', "wprism-scoped-authored-map-witness/v1\0" . $selectedLedgerMapRoot);
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
        array $ledgerMapIdentityHashes,
        ?CompiledRepository $compiled = null,
        bool $allowTargetOldMenuItems = false
    ): void {
        self::assert_selected_ledger_map_observation_from_rows(
            $policy,
            $contract,
            $actual,
            $ledgerMapIdentityHashes,
            Ledger::all_map(),
            $compiled,
            $allowTargetOldMenuItems
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
        array $map,
        ?CompiledRepository $compiled,
        bool $allowTargetOldMenuItems
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
                if ($type === 'post') {
                    [$data] = Canon::parse_post_file((string) ($row['content'] ?? ''));
                } else {
                    $data = Canon::decode((string) ($row['content'] ?? ''));
                }
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
        // Capture's public menu document deliberately omits non-published
        // items. Apply still examines every all-status sidecar attached to
        // the selected menu term taxonomy. Add only exact read-only physical
        // map/owner tuples so any Ledger::forget() stays inside the sealed
        // selected partition without widening canonical menu content/scope.
        foreach (self::selected_physical_menu_item_owners(
            $compiled,
            $contract,
            $actual,
            $selectedEntities,
            $map,
            $allowTargetOldMenuItems
        ) as $itemUuid => $ownerIdentity) {
            $addExpected(
                $itemUuid,
                Ledger::KIND_POST,
                'menu_item',
                ['owner' => $ownerIdentity, 'post_type' => 'nav_menu_item']
            );
        }
        foreach (self::selected_tombstone_menu_item_owners(
            $compiled,
            $contract,
            $actual,
            $selectedEntities,
            $map,
            $allowTargetOldMenuItems
        ) as $itemUuid => $ownerIdentity) {
            $addExpected(
                $itemUuid,
                Ledger::KIND_POST,
                'menu_item',
                ['owner' => $ownerIdentity, 'post_type' => 'nav_menu_item']
            );
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
            try {
                CanonicalMapWitness::assert_exact(
                    $policy,
                    $uuid,
                    $kind,
                    $row,
                    $expected[$uuid][$kind],
                    $mapByIdentityKind
                );
            } catch (\Throwable $failure) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired($failure);
            }
            $observed[$uuid][$kind] = true;
        }
        foreach ($observed as $kinds) {
            if (in_array(false, $kinds, true)) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired();
            }
        }
    }

    /** @return array<string,true> */
    private static function selected_menu_tombstone_set(array $contract, array $selectedEntities): array {
        $menus = [];
        foreach ((array) ($contract['tombstones'] ?? []) as $tombstone) {
            $uuid = (string) ($tombstone['uuid'] ?? '');
            $deletion = (array) ($tombstone['deletion'] ?? []);
            if (Uuid::is($uuid)
                && isset($selectedEntities[$uuid])
                && (string) ($deletion['kind'] ?? '') === 'menu'
                && (string) ($deletion['type'] ?? '') === 'nav_menu') {
                $menus[$uuid] = true;
            }
        }
        return $menus;
    }

    /**
     * @param list<array{uuid:string,entity_type:string,id_kind:string,local_id:int}> $map
     * @return array{by_identity_kind:array,rows_by_uuid:array,rows_by_local_kind:array}
     */
    private static function ledger_map_indexes(array $map): array {
        $byIdentityKind = [];
        $rowsByUuid = [];
        $rowsByLocalKind = [];
        foreach ($map as $row) {
            $uuid = (string) ($row['uuid'] ?? '');
            $kind = (string) ($row['id_kind'] ?? '');
            $localId = (int) ($row['local_id'] ?? 0);
            $byIdentityKind[$uuid][$kind] = $row;
            $rowsByUuid[$uuid][] = $row;
            $rowsByLocalKind[$kind][$localId][] = $row;
        }
        return [
            'by_identity_kind' => $byIdentityKind,
            'rows_by_uuid' => $rowsByUuid,
            'rows_by_local_kind' => $rowsByLocalKind,
        ];
    }

    /**
     * @param array<string,list<array{uuid:string,entity_type:string,id_kind:string,local_id:int}>> $rowsByUuid
     * @param array<string,array<int,list<array{uuid:string,entity_type:string,id_kind:string,local_id:int}>>> $rowsByLocalKind
     * @return ?array{uuid:string,entity_type:string,id_kind:string,local_id:int}
     */
    private static function exact_menu_item_map_tuple(
        string $uuid,
        int $localId,
        array $rowsByUuid,
        array $rowsByLocalKind
    ): ?array {
        $byUuid = (array) ($rowsByUuid[$uuid] ?? []);
        $byLocal = (array) ($rowsByLocalKind[Ledger::KIND_POST][$localId] ?? []);
        if (!Uuid::is($uuid) || count($byUuid) !== 1 || count($byLocal) !== 1) {
            return null;
        }
        $row = $byUuid[0];
        if ((string) ($row['uuid'] ?? '') !== $uuid
            || (string) ($row['entity_type'] ?? '') !== 'menu_item'
            || (string) ($row['id_kind'] ?? '') !== Ledger::KIND_POST
            || (int) ($row['local_id'] ?? 0) !== $localId
            || (string) ($byLocal[0]['uuid'] ?? '') !== $uuid
            || (string) ($byLocal[0]['entity_type'] ?? '') !== 'menu_item') {
            return null;
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private static function physical_menu_item_rows(int $termTaxonomyId): array {
        global $wpdb;
        if ($termTaxonomyId <= 0) {
            throw CommandRefusalException::scopedIdentityRecoveryRequired();
        }
        return self::checked_target_rows($wpdb->prepare(
            "SELECT p.ID, p.post_type, p.post_status, pm.meta_value AS wprism_uuid FROM {$wpdb->posts} p"
            . " JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID AND tr.term_taxonomy_id = %d"
            . " LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s"
            . " WHERE p.post_type = 'nav_menu_item' ORDER BY p.ID ASC, pm.meta_id ASC",
            $termTaxonomyId,
            '_wprism_uuid'
        ));
    }

    /**
     * Read the non-canonical part of a selected target menu's deletion set.
     *
     * Capture quite intentionally serializes only published menu items, but
     * finalize_menu() enumerates every nav_menu_item relationship and uses
     * each nonempty sidecar UUID to select an item or call Ledger::forget().
     * Treating draft/trash map rows as protected would therefore bless a
     * known protected-map mutation after commit. This private read proves the
     * exact menu term-taxonomy owner, post identity, and durable tuple before
     * allowing a target-only UUID into the hash-only selected partition. It
     * never adds content to the canonical menu document. Target-only rows are
     * admissible only during initial or locked pre-authoring observation;
     * later observations fail closed if they reappear.
     *
     * @param array<string,array{type:string,hash:string,content:string,path:string}> $actual
     * @param array<string,true> $selectedEntities
     * @param list<array{uuid:string,entity_type:string,id_kind:string,local_id:int}> $map
     * @return array<string,string> mapped menu-item UUID => selected menu UUID
     */
    private static function selected_physical_menu_item_owners(
        ?CompiledRepository $compiled,
        array $contract,
        array $actual,
        array $selectedEntities,
        array $map,
        bool $allowTargetOldMenuItems
    ): array {
        global $wpdb;
        $mapIndexes = self::ledger_map_indexes($map);
        $mapByIdentityKind = $mapIndexes['by_identity_kind'];
        $mapRowsByUuid = $mapIndexes['rows_by_uuid'];
        $mapRowsByLocalKind = $mapIndexes['rows_by_local_kind'];

        $observedOwnerTree = self::selected_observed_owner_tree($actual, $selectedEntities);
        $canonicalMenuOwners = [];
        foreach (ReferenceGraph::owners($observedOwnerTree) as $uuid => $owner) {
            if (($owner['kind'] ?? '') === 'menu_item'
                && isset($selectedEntities[(string) ($owner['entity'] ?? '')])) {
                $canonicalMenuOwners[(string) $uuid] = (string) $owner['entity'];
            }
        }
        $frozenOwners = $compiled === null ? [] : ReferenceGraph::owners($compiled->tree());
        $tombstoneMenus = self::selected_menu_tombstone_set($contract, $selectedEntities);

        // Prove each selected menu's durable term/taxonomy tuple before using
        // its local taxonomy id as an ownership boundary for nested rows.
        $selectedMenuTts = [];
        foreach ($observedOwnerTree as $menuUuid => $owner) {
            if (($owner['type'] ?? '') !== 'menu') {
                continue;
            }
            $menuUuid = (string) $menuUuid;
            // delete_entity() owns the complete physical item set for a
            // selected menu tombstone, including sidecarless/unmapped rows.
            // Its inventory below is intentionally separate from normal
            // finalization's UUID-keyed deletion set.
            if (isset($tombstoneMenus[$menuUuid])) {
                continue;
            }
            $termMap = $mapByIdentityKind[$menuUuid][Ledger::KIND_TERM] ?? null;
            $ttMap = $mapByIdentityKind[$menuUuid][Ledger::KIND_TT] ?? null;
            $termId = (int) ($termMap['local_id'] ?? 0);
            $ttId = (int) ($ttMap['local_id'] ?? 0);
            if (!Uuid::is($menuUuid)
                || !is_array($termMap)
                || !is_array($ttMap)
                || (string) ($termMap['entity_type'] ?? '') !== 'menu'
                || (string) ($ttMap['entity_type'] ?? '') !== 'menu'
                || $termId <= 0
                || $ttId <= 0) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired();
            }
            $physical = self::checked_target_row($wpdb->prepare(
                'SELECT t.term_id, tt.term_taxonomy_id, tt.taxonomy, tm.meta_value AS wprism_uuid'
                . " FROM {$wpdb->terms} t"
                . " JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id"
                . " LEFT JOIN {$wpdb->termmeta} tm ON tm.term_id = t.term_id AND tm.meta_key = %s"
                . ' WHERE t.term_id = %d AND tt.term_taxonomy_id = %d'
                . ' ORDER BY tm.meta_id ASC LIMIT 1',
                '_wprism_uuid',
                $termId,
                $ttId
            ));
            if ($physical === null
                || (int) ($physical['term_id'] ?? 0) !== $termId
                || (int) ($physical['term_taxonomy_id'] ?? 0) !== $ttId
                || (string) ($physical['taxonomy'] ?? '') !== 'nav_menu'
                || (string) ($physical['wprism_uuid'] ?? '') !== $menuUuid) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired();
            }
            try {
                Ledger::require_read_only_mapping($menuUuid, 'menu', Ledger::KIND_TERM, $termId, 'selected menu identity');
                Ledger::require_read_only_mapping($menuUuid, 'menu', Ledger::KIND_TT, $ttId, 'selected menu taxonomy identity');
            } catch (\Throwable $failure) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired($failure);
            }
            $selectedMenuTts[$menuUuid] = $ttId;
        }

        // This is the same all-status physical item population finalization
        // uses for envByUuid. It deliberately does not depend on Capture's
        // published-only canonical menu document: every nonempty sidecar can
        // cause finalize_menu() to call Ledger::forget().
        $physicalItemsByLocal = [];
        $physicalItemsByUuid = [];
        foreach ($selectedMenuTts as $menuUuid => $ttId) {
            $physicalRows = self::physical_menu_item_rows($ttId);
            foreach ($physicalRows as $physical) {
                $localId = (int) ($physical['ID'] ?? 0);
                $sidecarUuid = (string) ($physical['wprism_uuid'] ?? '');
                if ($localId <= 0
                    || (string) ($physical['post_type'] ?? '') !== 'nav_menu_item'
                    || isset($physicalItemsByLocal[$localId])) {
                    throw CommandRefusalException::scopedIdentityRecoveryRequired();
                }
                $physicalItemsByLocal[$localId] = [
                    'owner' => (string) $menuUuid,
                    'term_taxonomy_id' => $ttId,
                    'uuid' => $sidecarUuid,
                ];
                if ($sidecarUuid === '') {
                    continue;
                }
                if (!Uuid::is($sidecarUuid) || isset($physicalItemsByUuid[$sidecarUuid])) {
                    throw CommandRefusalException::scopedIdentityRecoveryRequired();
                }
                $physicalItemsByUuid[$sidecarUuid] = [
                    'owner' => (string) $menuUuid,
                    'local_id' => $localId,
                    'term_taxonomy_id' => $ttId,
                ];
            }
        }

        // Union the all-status sidecar population above with every physically
        // attached `post` map tuple. A malformed type, stale local id, or
        // mismatched sidecar is a refusal rather than a protected map that
        // finalization could erase by UUID.
        foreach ($map as $row) {
            if (($row['id_kind'] ?? '') !== Ledger::KIND_POST) {
                continue;
            }
            $uuid = (string) ($row['uuid'] ?? '');
            $localId = (int) ($row['local_id'] ?? 0);
            $physical = $physicalItemsByLocal[$localId] ?? null;
            if ($physical === null) {
                continue;
            }
            if (!Uuid::is($uuid)
                || (string) ($row['entity_type'] ?? '') !== 'menu_item'
                || (string) ($physical['uuid'] ?? '') === ''
                || (string) ($physical['uuid'] ?? '') !== $uuid) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired();
            }
        }

        $owners = [];
        foreach ($physicalItemsByUuid as $uuid => $physical) {
            $localId = (int) ($physical['local_id'] ?? 0);
            $ownerIdentity = (string) ($physical['owner'] ?? '');
            $ownerTt = (int) ($physical['term_taxonomy_id'] ?? 0);
            $hasExactMap = self::exact_menu_item_map_tuple(
                $uuid,
                $localId,
                $mapRowsByUuid,
                $mapRowsByLocalKind
            ) !== null;

            $frozenOwner = $frozenOwners[$uuid] ?? null;
            $canonicalOwner = $canonicalMenuOwners[$uuid] ?? null;
            if ($canonicalOwner !== null) {
                if (!$hasExactMap
                    || $canonicalOwner !== $ownerIdentity
                    || (is_array($frozenOwner)
                        && (string) ($frozenOwner['entity'] ?? '') !== $canonicalOwner)) {
                    throw CommandRefusalException::scopedIdentityRecoveryRequired();
                }
                try {
                    Ledger::require_read_only_mapping($uuid, 'menu_item', Ledger::KIND_POST, $localId, 'selected menu-item identity');
                } catch (\Throwable $failure) {
                    throw CommandRefusalException::scopedIdentityRecoveryRequired($failure);
                }
                // A target-only canonical item is removed by
                // finalize_menu(). Its post deletion clears every eligible
                // taxonomy relationship, so no unrelated relationship may
                // share the physical row.
                if (!is_array($frozenOwner)) {
                    self::assert_only_selected_menu_item_relationship($localId, $ownerTt);
                }
                // Canonical target content remains governed by the generic
                // source/target owner union and normal strict assertion.
                continue;
            }
            if (!$allowTargetOldMenuItems || $compiled === null) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired();
            }

            // Unlike canonical items, this sidecar is hidden from Capture's
            // menu document. finalize_menu() can nevertheless publish,
            // update, or delete its physical row. It must therefore have the
            // selected menu as its sole nav-menu owner before either the
            // source-reuse or target-old branch can admit it.
            self::assert_exclusive_nav_menu_item_owner($localId, $ownerTt);

            $sourceOwnsThisMenuItem = is_array($frozenOwner)
                && (string) ($frozenOwner['entity'] ?? '') === $ownerIdentity
                && (string) ($frozenOwner['kind'] ?? '') === 'menu_item';
            if ($sourceOwnsThisMenuItem) {
                // A selected source item may still be draft/trash in the
                // target. With no map at all, finalize_menu() safely reuses
                // the physical row and creates its map via Ledger::set().
                // With a map, require the one exact tuple before admitting it
                // to the strict selected observation.
                if (!isset($mapRowsByUuid[$uuid])) {
                    // Ledger::set() would collide if another identity already
                    // occupies this physical post row, even though this UUID
                    // itself has no map tuple.
                    if (($mapRowsByLocalKind[Ledger::KIND_POST][$localId] ?? []) !== []) {
                        throw CommandRefusalException::scopedIdentityRecoveryRequired();
                    }
                    continue;
                }
                if (!$hasExactMap) {
                    throw CommandRefusalException::scopedIdentityRecoveryRequired();
                }
                try {
                    Ledger::require_read_only_mapping($uuid, 'menu_item', Ledger::KIND_POST, $localId, 'selected source menu-item identity');
                } catch (\Throwable $failure) {
                    throw CommandRefusalException::scopedIdentityRecoveryRequired($failure);
                }
                $owners[$uuid] = $ownerIdentity;
                continue;
            }
            // A hidden source-known item under any other owner is not a
            // target-old deletion candidate. Never transfer it into this
            // selected partition.
            if (is_array($frozenOwner) || !$hasExactMap) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired();
            }
            self::assert_only_selected_menu_item_relationship($localId, $ownerTt);
            try {
                Ledger::require_read_only_mapping($uuid, 'menu_item', Ledger::KIND_POST, $localId, 'selected target-old menu-item identity');
            } catch (\Throwable $failure) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired($failure);
            }
            $owners[$uuid] = $ownerIdentity;
        }
        ksort($owners, SORT_STRING);
        return $owners;
    }

    /**
     * Inventory the complete physical cascade of a selected menu tombstone.
     *
     * delete_entity() deletes every attached nav_menu_item, unlike normal
     * finalization which is UUID-keyed. A sidecarless/unmapped row therefore
     * needs no ledger partition entry, but every mapped row must be an exact
     * menu-item tuple and every physical row must be exclusively related to
     * the selected menu before the cascade can remove its post relationships.
     *
     * @param array<string,array{type:string,hash:string,content:string,path:string}> $actual
     * @param array<string,true> $selectedEntities
     * @param list<array{uuid:string,entity_type:string,id_kind:string,local_id:int}> $map
     * @return array<string,string> mapped menu-item UUID => selected tombstone menu UUID
     */
    private static function selected_tombstone_menu_item_owners(
        ?CompiledRepository $compiled,
        array $contract,
        array $actual,
        array $selectedEntities,
        array $map,
        bool $allowTargetOldMenuItems
    ): array {
        $tombstoneMenus = self::selected_menu_tombstone_set($contract, $selectedEntities);
        if ($tombstoneMenus === []) {
            return [];
        }

        $mapIndexes = self::ledger_map_indexes($map);
        $mapByIdentityKind = $mapIndexes['by_identity_kind'];
        $mapRowsByUuid = $mapIndexes['rows_by_uuid'];
        $mapRowsByLocalKind = $mapIndexes['rows_by_local_kind'];
        $frozenOwners = $compiled === null ? [] : ReferenceGraph::owners($compiled->tree());
        $owners = [];

        foreach (array_keys($tombstoneMenus) as $menuUuid) {
            $termMap = $mapByIdentityKind[$menuUuid][Ledger::KIND_TERM] ?? null;
            $ttMap = $mapByIdentityKind[$menuUuid][Ledger::KIND_TT] ?? null;
            if (!is_array($termMap) || !is_array($ttMap)) {
                // A physically settled tombstone may retain only one direct
                // map row until terminal cleanup. If Capture still observes
                // the menu, its ordinary strict mapping check refuses the
                // incomplete tuple below instead of treating it as settled.
                if (isset($actual[$menuUuid])) {
                    throw CommandRefusalException::scopedIdentityRecoveryRequired();
                }
                continue;
            }
            $termId = (int) ($termMap['local_id'] ?? 0);
            $ttId = (int) ($ttMap['local_id'] ?? 0);
            if ((string) ($termMap['entity_type'] ?? '') !== 'menu'
                || (string) ($ttMap['entity_type'] ?? '') !== 'menu'
                || $termId <= 0
                || $ttId <= 0) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired();
            }

            $physicalRows = self::physical_menu_item_rows($ttId);
            if (!$allowTargetOldMenuItems && $physicalRows !== []) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired();
            }

            $seenLocalIds = [];
            foreach ($physicalRows as $physical) {
                $localId = (int) ($physical['ID'] ?? 0);
                $sidecarUuid = (string) ($physical['wprism_uuid'] ?? '');
                if ($localId <= 0
                    || (string) ($physical['post_type'] ?? '') !== 'nav_menu_item'
                    || isset($seenLocalIds[$localId])) {
                    throw CommandRefusalException::scopedIdentityRecoveryRequired();
                }
                $seenLocalIds[$localId] = true;

                // delete_post_relationships() runs before the row delete and
                // removes every registered post relationship. A tombstone may
                // not delete an item shared by another taxonomy or menu.
                self::assert_only_selected_menu_item_relationship($localId, $ttId);

                $localMapRows = (array) ($mapRowsByLocalKind[Ledger::KIND_POST][$localId] ?? []);
                if ($localMapRows === []) {
                    // A sidecar that names a mapped UUID must point at that
                    // UUID's exact post tuple. Otherwise delete_entity()
                    // would remove P while Ledger::uuid_for(P) sees nothing,
                    // leaving a stale/rebound identity behind.
                    if ($sidecarUuid !== ''
                        && (isset($mapRowsByUuid[$sidecarUuid])
                            || is_array($frozenOwners[$sidecarUuid] ?? null))) {
                        throw CommandRefusalException::scopedIdentityRecoveryRequired();
                    }
                    continue;
                }
                if (count($localMapRows) !== 1) {
                    throw CommandRefusalException::scopedIdentityRecoveryRequired();
                }
                $uuid = (string) ($localMapRows[0]['uuid'] ?? '');
                $row = self::exact_menu_item_map_tuple(
                    $uuid,
                    $localId,
                    $mapRowsByUuid,
                    $mapRowsByLocalKind
                );
                if ($row === null
                    || $sidecarUuid !== $uuid
                    || is_array($frozenOwners[$uuid] ?? null)) {
                    throw CommandRefusalException::scopedIdentityRecoveryRequired();
                }
                try {
                    Ledger::require_read_only_mapping(
                        $uuid,
                        'menu_item',
                        Ledger::KIND_POST,
                        $localId,
                        'selected tombstone menu-item identity'
                    );
                } catch (\Throwable $failure) {
                    throw CommandRefusalException::scopedIdentityRecoveryRequired($failure);
                }
                $owners[$uuid] = $menuUuid;
            }
        }
        ksort($owners, SORT_STRING);
        return $owners;
    }

    /**
     * A hidden item can be reused/published without deleting non-nav
     * relationships, but it must have exactly one nav-menu owner and that
     * owner must be the selected menu.
     */
    private static function assert_exclusive_nav_menu_item_owner(int $localId, int $selectedTt): void {
        global $wpdb;
        if ($localId <= 0 || $selectedTt <= 0) {
            throw CommandRefusalException::scopedIdentityRecoveryRequired();
        }
        $relationships = self::checked_target_rows($wpdb->prepare(
            "SELECT tt.term_taxonomy_id, tt.taxonomy FROM {$wpdb->term_relationships} tr"
            . " JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id"
            . ' WHERE tr.object_id = %d AND tt.taxonomy = %s ORDER BY tt.term_taxonomy_id ASC',
            $localId,
            'nav_menu'
        ));
        if (count($relationships) !== 1
            || (int) ($relationships[0]['term_taxonomy_id'] ?? 0) !== $selectedTt
            || (string) ($relationships[0]['taxonomy'] ?? '') !== 'nav_menu') {
            throw CommandRefusalException::scopedIdentityRecoveryRequired();
        }
    }

    /**
     * A source-absent item will be physically deleted, so no other taxonomy
     * relationship may share its post row. This is deliberately stricter
     * than the nav-menu-only reuse check above.
     */
    private static function assert_only_selected_menu_item_relationship(int $localId, int $selectedTt): void {
        global $wpdb;
        if ($localId <= 0 || $selectedTt <= 0) {
            throw CommandRefusalException::scopedIdentityRecoveryRequired();
        }
        $relationships = self::checked_target_rows($wpdb->prepare(
            "SELECT tt.term_taxonomy_id, tt.taxonomy FROM {$wpdb->term_relationships} tr"
            . " JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id"
            . ' WHERE tr.object_id = %d ORDER BY tt.term_taxonomy_id ASC',
            $localId
        ));
        if (count($relationships) !== 1
            || (int) ($relationships[0]['term_taxonomy_id'] ?? 0) !== $selectedTt
            || (string) ($relationships[0]['taxonomy'] ?? '') !== 'nav_menu') {
            throw CommandRefusalException::scopedIdentityRecoveryRequired();
        }
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

    /** @return list<array<string,mixed>> */
    private static function checked_target_rows(string $sql): array {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if ($rows === false || !empty($wpdb->last_error) || !is_array($rows)) {
            throw CommandRefusalException::scopedIdentityRecoveryRequired();
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired();
            }
        }
        return array_values($rows);
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
                    "wprism: scoped target observation has malformed selected $type owner '$identity'",
                    0,
                    $failure
                );
            }
            if (!is_array($data)) {
                throw new \RuntimeException(
                    "wprism: scoped target observation has non-object selected $type owner '$identity'"
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
                throw new \RuntimeException('wprism: scoped ledger map selection has an invalid opaque identity hash');
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
     * Pure selected-target root used by both authored-boundary recovery and
     * pre-authoring authority admission. It intentionally contains only
     * scope-selected file identities; map rows have their own sealed roots.
     *
     * @param array<string,array<string,mixed>> $actual
     */
    public static function selected_observation_root(array $actual, array $contract): string {
        $selected = self::selected_set($contract);
        $recordScopedOptions = self::has_record_scoped_options($contract);
        $rows = [];
        foreach (array_keys($selected) as $identity) {
            if ($recordScopedOptions && ScopeClosure::is_option_root($identity)) {
                $carrier = $actual['options/core'] ?? null;
                try {
                    $records = is_array($carrier)
                        ? OptionState::records(Canon::decode((string) ($carrier['content'] ?? '')))
                        : [];
                } catch (\Throwable $failure) {
                    $records = [];
                }
                $name = ScopeClosure::option_name_from_root($identity);
                if (!array_key_exists($name, $records)) {
                    $rows[$identity] = [
                        'identity_hash' => hash('sha256', $identity),
                        'state' => 'absent',
                        'type' => '',
                        'content_hash' => hash('sha256', 'wprism:absent'),
                    ];
                } else {
                    $rows[$identity] = [
                        'identity_hash' => hash('sha256', $identity),
                        'type' => 'option',
                        'content_hash' => OptionState::record_hash($records[$name]),
                        'state' => 'live',
                    ];
                }
                continue;
            }
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
                    'content_hash' => hash('sha256', 'wprism:absent'),
                ];
            }
        }
        ksort($rows, SORT_STRING);
        return self::hash_rows(array_values($rows));
    }

    /** @param array<string,array<string,mixed>> $actual */
    public static function selected_observation_matches_before(
        array $actual,
        array $contract,
        string $selectedBeforeRoot
    ): bool {
        return hash_equals($selectedBeforeRoot, self::selected_observation_root($actual, $contract));
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
        $matchesBefore = self::selected_observation_matches_before(
            $actual,
            $contract,
            $selectedBeforeRoot
        );

        $matchesDesired = true;
        foreach (array_keys($selected) as $identity) {
            if (self::has_record_scoped_options($contract) && ScopeClosure::is_option_root($identity)) {
                $name = ScopeClosure::option_name_from_root($identity);
                $expectedCarrier = $compiled->tree()['options/core'] ?? null;
                $observedCarrier = $actual['options/core'] ?? null;
                try {
                    $expectedRecords = is_array($expectedCarrier)
                        ? OptionState::records((array) ($expectedCarrier['data'] ?? []))
                        : [];
                    $observedRecords = is_array($observedCarrier)
                        ? OptionState::records(Canon::decode((string) ($observedCarrier['content'] ?? '')))
                        : [];
                } catch (\Throwable $failure) {
                    $matchesDesired = false;
                    break;
                }
                // `absent` deliberately carries no target mutation intent.
                // A present/absent target record is therefore already the
                // desired terminal state, while its observed value remains
                // sealed in selected_before_root for preflight race checks.
                if (($expectedRecords[$name]['state'] ?? null) === 'absent') {
                    continue;
                }
                if (!isset($expectedRecords[$name], $observedRecords[$name])
                    || !hash_equals(
                        OptionState::record_hash($expectedRecords[$name]),
                        OptionState::record_hash($observedRecords[$name])
                    )) {
                    $matchesDesired = false;
                    break;
                }
                continue;
            }
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

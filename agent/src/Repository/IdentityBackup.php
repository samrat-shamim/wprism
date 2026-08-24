<?php
namespace Duo;

if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/../Kernel/Db.php';
}

/** Versioned disaster-recovery sidecar for environment-bound identity. */
final class IdentityBackup {
    public const FORMAT = 'duo-identity-ledger/v1';

    public static function create(string $repo): array {
        Ledger::ensure();
        $policy = Policy::load($repo);
        $compiled = RepositoryCompiler::compile($repo, $policy);
        $tables = self::tables_by_kind($policy);
        $transactionStarted = false;
        try {
            Db::start_consistent_snapshot('starting identity export snapshot');
            $transactionStarted = true;
            Identity::assert_embedded_unique();
            Ledger::prune_dead_map();
            Snapshot::prune_dead_map($policy);
            SidebarState::prune_dead_map($policy);
            Snapshot::assert_mapped_history_present($policy, $repo);
            SidebarState::assert_mapped_history_present($repo);
            Snapshot::assert_all_mapped_rows_managed($policy);
            SidebarState::assert_all_owned_widgets_mapped($policy, $repo);

            $plainMaps = Ledger::all_map();
            $mapLookup = [];
            foreach ($plainMaps as $row) {
                $mapLookup[$row['id_kind'] . '|' . $row['local_id']] = $row['uuid'];
            }
            $maps = [];
            foreach ($plainMaps as $row) {
                $row['witness'] = self::witness($row, $tables, $mapLookup);
                $maps[] = $row;
            }
            $states = [];
            foreach (Ledger::all_state() as $uuid => $state) {
                $states[] = ['uuid' => $uuid] + $state;
            }
            usort($states, static fn(array $a, array $b): int => $a['uuid'] <=> $b['uuid']);

            $artifact = [
                'format' => self::FORMAT,
                'repository_revision' => $compiled->revision_hash(),
                'site_hash' => $compiled->site_hash(),
                'manifest_hash' => $compiled->manifest_hash(),
                'applied_revision' => Ledger::kv_get('applied_revision'),
                'maps' => $maps,
                'states' => $states,
            ];
            $artifact['integrity_sha256'] = self::hash($artifact);
            Db::commit('committing identity export snapshot');
            $transactionStarted = false;
        } catch (\Throwable $t) {
            if ($transactionStarted) {
                self::rollback_after_failure($t, 'rolling back identity export snapshot');
            }
            throw $t;
        }
        return $artifact;
    }

    public static function restore(string $repo, string $path): array {
        Ledger::ensure();
        $artifact = Canon::decode(Canon::read_file($path));
        if (!is_array($artifact) || ($artifact['format'] ?? '') !== self::FORMAT) {
            throw new \RuntimeException('duo: identity sidecar has an unsupported or missing format');
        }
        $expected = (string) ($artifact['integrity_sha256'] ?? '');
        if (!preg_match('/^[0-9a-f]{64}$/', $expected) || !hash_equals($expected, self::hash($artifact))) {
            throw new \RuntimeException('duo: identity sidecar integrity hash does not verify');
        }

        $policy = Policy::load($repo);
        $compiled = RepositoryCompiler::compile($repo, $policy);
        foreach (['repository_revision' => $compiled->revision_hash(), 'site_hash' => $compiled->site_hash(), 'manifest_hash' => $compiled->manifest_hash()] as $key => $active) {
            if (!hash_equals($active, (string) ($artifact[$key] ?? ''))) {
                throw new \RuntimeException("duo: identity sidecar $key does not match the active repository");
            }
        }
        global $wpdb;
        $transactionStarted = false;
        try {
            Db::start_consistent_snapshot('starting identity import transaction');
            $transactionStarted = true;
            Identity::assert_embedded_unique();
            $maps = self::validate_maps($artifact['maps'] ?? null, self::tables_by_kind($policy));
            $states = self::validate_states($artifact['states'] ?? null);

            $incomingPlain = array_map(static function (array $r): array {
                unset($r['witness']);
                return $r;
            }, $maps);
            $currentMaps = Ledger::all_map();
            $incomingMapIndex = [];
            foreach ($incomingPlain as $row) {
                $incomingMapIndex[$row['uuid'] . '|' . $row['id_kind']] = $row;
            }
            foreach ($currentMaps as $row) {
                $expectedRow = $incomingMapIndex[$row['uuid'] . '|' . $row['id_kind']] ?? null;
                if ($expectedRow === null || Canon::encode($expectedRow) !== Canon::encode($row)) {
                    throw new \RuntimeException('duo: current identity ledger conflicts with the sidecar; refusing partial or implicit rebinding');
                }
            }
            $currentStates = [];
            foreach (Ledger::all_state() as $uuid => $state) {
                $currentStates[] = ['uuid' => $uuid] + $state;
            }
            usort($currentStates, static fn(array $a, array $b): int => $a['uuid'] <=> $b['uuid']);
            $incomingStateIndex = [];
            foreach ($states as $row) {
                $incomingStateIndex[$row['uuid']] = $row;
            }
            foreach ($currentStates as $row) {
                $expectedRow = $incomingStateIndex[$row['uuid']] ?? null;
                if ($expectedRow === null || Canon::encode($expectedRow) !== Canon::encode($row)) {
                    throw new \RuntimeException('duo: current sync-state ledger conflicts with the sidecar');
                }
            }

            Db::query(
                "DELETE FROM {$wpdb->prefix}duo_map",
                'clearing identity mappings for restore'
            );
            Db::query(
                "DELETE FROM {$wpdb->prefix}duo_state",
                'clearing sync state for restore'
            );
            foreach ($incomingPlain as $row) {
                Ledger::set($row['uuid'], $row['entity_type'], $row['id_kind'], $row['local_id']);
            }
            foreach ($states as $row) {
                Ledger::set_state_hash($row['uuid'], $row['entity_type'], $row['content_hash']);
                if ($wpdb->last_error) {
                    throw new \RuntimeException("duo: failed restoring sync state: {$wpdb->last_error}");
                }
            }
            Ledger::kv_set('applied_revision', (string) ($artifact['applied_revision'] ?? ''));
            if ($wpdb->last_error) {
                throw new \RuntimeException("duo: failed restoring applied revision: {$wpdb->last_error}");
            }
            Db::commit('committing identity import');
            $transactionStarted = false;
        } catch (\Throwable $t) {
            if ($transactionStarted) {
                self::rollback_after_failure($t, 'rolling back identity import');
            }
            throw $t;
        }
        return [
            'format' => self::FORMAT,
            'maps' => count($maps),
            'states' => count($states),
            'applied_revision' => (string) ($artifact['applied_revision'] ?? ''),
        ];
    }

    private static function validate_maps($value, array $tables): array {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \RuntimeException('duo: identity sidecar maps must be a list');
        }
        $seenUuidKind = [];
        $seenLocal = [];
        $uuidKinds = [];
        $mapLookup = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $mapLookup[(string) ($row['id_kind'] ?? '') . '|' . (int) ($row['local_id'] ?? 0)]
                    = (string) ($row['uuid'] ?? '');
            }
        }
        foreach ($value as $i => $row) {
            if (!is_array($row)) {
                throw new \RuntimeException("duo: identity sidecar maps[$i] is not an object");
            }
            $uuid = (string) ($row['uuid'] ?? '');
            $kind = (string) ($row['id_kind'] ?? '');
            $local = (int) ($row['local_id'] ?? 0);
            $entityType = (string) ($row['entity_type'] ?? '');
            if (!Uuid::is($uuid) || $kind === '' || $local <= 0 || $entityType === '') {
                throw new \RuntimeException("duo: identity sidecar maps[$i] has an invalid identity tuple");
            }
            if (isset($seenUuidKind["$uuid|$kind"]) || isset($seenLocal["$kind|$local"])) {
                throw new \RuntimeException("duo: identity sidecar maps[$i] duplicates a UUID/kind or kind/local tuple");
            }
            $seenUuidKind["$uuid|$kind"] = true;
            $seenLocal["$kind|$local"] = true;
            $uuidKinds[$uuid][$kind] = true;
            $actualWitness = self::witness([
                'uuid' => $uuid, 'entity_type' => $entityType, 'id_kind' => $kind, 'local_id' => $local,
            ], $tables, $mapLookup);
            if (!hash_equals($actualWitness, (string) ($row['witness'] ?? ''))) {
                throw new \RuntimeException(
                    "duo: identity sidecar witness mismatch for $uuid ($kind:$local); database row is missing or stale"
                );
            }
            $value[$i] = [
                'uuid' => $uuid, 'entity_type' => $entityType, 'id_kind' => $kind,
                'local_id' => $local, 'witness' => $actualWitness,
            ];
        }
        foreach ($uuidKinds as $uuid => $kinds) {
            if (count($kinds) > 1) {
                $names = array_keys($kinds);
                sort($names, SORT_STRING);
                if ($names !== [Ledger::KIND_TERM, Ledger::KIND_TT]) {
                    throw new \RuntimeException("duo: identity sidecar UUID $uuid is reused across incompatible kinds");
                }
            }
        }
        usort($value, static fn(array $a, array $b): int => [$a['id_kind'], $a['local_id'], $a['uuid']] <=> [$b['id_kind'], $b['local_id'], $b['uuid']]);
        return $value;
    }

    private static function validate_states($value): array {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \RuntimeException('duo: identity sidecar states must be a list');
        }
        $seen = [];
        foreach ($value as $i => $row) {
            if (!is_array($row)) {
                throw new \RuntimeException("duo: identity sidecar states[$i] is not an object");
            }
            $uuid = (string) ($row['uuid'] ?? '');
            $hash = (string) ($row['content_hash'] ?? '');
            $type = (string) ($row['entity_type'] ?? '');
            if ($uuid === '' || $type === '' || !preg_match('/^[0-9a-f]{64}$/', $hash) || isset($seen[$uuid])) {
                throw new \RuntimeException("duo: identity sidecar states[$i] is invalid or duplicated");
            }
            $seen[$uuid] = true;
            $value[$i] = ['uuid' => $uuid, 'entity_type' => $type, 'content_hash' => $hash];
        }
        usort($value, static fn(array $a, array $b): int => $a['uuid'] <=> $b['uuid']);
        return $value;
    }

    /** @return array<string,array> id_kind => table declaration or widget_type */
    private static function tables_by_kind(Policy $policy): array {
        $out = [];
        foreach (Snapshot::row_tables($policy) as $table => $decl) {
            $out[$decl['id_kind']] = ['table' => $table] + $decl;
        }
        foreach ($policy->widget_types() as $type => $_decl) {
            $kind = SidebarState::kind((string) $type);
            if (isset($out[$kind])) {
                throw new \RuntimeException("duo: identity kind '$kind' is declared by both a table and widget type");
            }
            $out[$kind] = ['widget_type' => (string) $type];
        }
        return $out;
    }

    private static function witness(array $map, array $tables, array $mapLookup): string {
        global $wpdb;
        $kind = $map['id_kind'];
        $local = (int) $map['local_id'];
        $uuid = $map['uuid'];
        if ($kind === Ledger::KIND_POST || $kind === Ledger::KIND_TERM) {
            $metaTable = $kind === Ledger::KIND_POST ? $wpdb->postmeta : $wpdb->termmeta;
            $ownerCol = $kind === Ledger::KIND_POST ? 'post_id' : 'term_id';
            self::assert_embedded_uuid($metaTable, $ownerCol, $local, $uuid, "$kind identity witness");
            return hash('sha256', Canon::encode(['kind' => $kind, 'local_id' => $local, 'uuid' => $uuid]));
        }
        if ($kind === Ledger::KIND_TT) {
            self::assert_identifier($wpdb->term_taxonomy, 'term-taxonomy identity witness table');
            $rows = self::checked_rows($wpdb->prepare(
                "SELECT term_taxonomy_id, term_id FROM `{$wpdb->term_taxonomy}` "
                . 'WHERE term_taxonomy_id = %d ORDER BY term_taxonomy_id ASC LIMIT 2',
                $local
            ), 'term-taxonomy identity witness');
            if ($rows === []) {
                throw new \RuntimeException("duo: term-taxonomy identity row $local is missing");
            }
            if (count($rows) !== 1
                || !is_array($rows[0])
                || array_keys($rows[0]) !== ['term_taxonomy_id', 'term_id']
                || self::positive_integer($rows[0]['term_taxonomy_id'] ?? null) !== $local
                || ($termId = self::positive_integer($rows[0]['term_id'] ?? null)) === null) {
                throw new \RuntimeException('duo: term-taxonomy identity witness returned a malformed/ambiguous row');
            }
            self::assert_embedded_uuid(
                $wpdb->termmeta,
                'term_id',
                $termId,
                $uuid,
                "$kind identity witness"
            );
            return hash('sha256', Canon::encode([
                'kind' => $kind, 'local_id' => $local, 'term_id' => $termId, 'uuid' => $uuid,
            ]));
        }
        $widgetType = $tables[$kind]['widget_type'] ?? null;
        if (is_string($widgetType)) {
            return SidebarState::witness($widgetType, $local);
        }
        $decl = $tables[$kind] ?? null;
        if ($decl === null) {
            throw new \RuntimeException("duo: cannot export or restore unsupported identity kind '$kind'");
        }
        $table = $wpdb->prefix . preg_replace('/[^A-Za-z0-9_]/', '', $decl['table']);
        if (($decl['identity']['mode'] ?? 'mapped') === 'composite_ref') {
            $cols = $decl['identity']['columns'];
            $componentMax = (1 << 31) - 1;
            $localByCol = [
                $cols[0] => $local >> 31,
                $cols[1] => $local & $componentMax,
            ];
            $kindByCol = [];
            foreach ($decl['refs'] as $ref) {
                $kindByCol[$ref['column']] = $ref['kind'];
            }
            $uuidParts = [$decl['table']];
            foreach ($cols as $col) {
                $refUuid = $mapLookup[$kindByCol[$col] . '|' . $localByCol[$col]] ?? null;
                if (!is_string($refUuid) || !Uuid::is($refUuid)) {
                    throw new \RuntimeException(
                        "duo: composite identity {$decl['table']}:$local cannot resolve {$kindByCol[$col]} "
                        . "ref {$localByCol[$col]} from the sidecar"
                    );
                }
                $uuidParts[] = "$col=$refUuid";
            }
            $derived = Uuid::v5(Uuid::NAMESPACE_DUO, implode(':', $uuidParts));
            if (!hash_equals($derived, $uuid)) {
                throw new \RuntimeException(
                    "duo: composite identity $uuid ({$decl['table']}:$local) does not match its referenced identities"
                );
            }
            $where = [];
            $args = [];
            foreach ($cols as $col) {
                $where[] = "`$col` = %d";
                $args[] = $localByCol[$col];
            }
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `$table` WHERE " . implode(' AND ', $where), ...$args
            ), ARRAY_A);
            if ($row === null) {
                throw new \RuntimeException("duo: composite identity row {$decl['table']}:$local is missing");
            }
            return hash('sha256', Canon::encode([
                'kind' => $kind, 'local_id' => $local, 'table' => $decl['table'], 'row' => $row,
            ]));
        }
        $pk = preg_replace('/[^A-Za-z0-9_]/', '', $decl['pk']);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE `$pk` = %d", $local), ARRAY_A);
        if ($row === null) {
            throw new \RuntimeException("duo: mapped identity row {$decl['table']}:$local is missing");
        }
        return hash('sha256', Canon::encode([
            'kind' => $kind, 'local_id' => $local, 'table' => $decl['table'], 'row' => $row,
        ]));
    }

    private static function assert_embedded_uuid(
        string $metaTable,
        string $ownerColumn,
        int $ownerId,
        string $uuid,
        string $purpose
    ): void {
        global $wpdb;
        self::assert_identifier($metaTable, "$purpose table");
        self::assert_identifier($ownerColumn, "$purpose owner column");
        $rows = self::checked_rows($wpdb->prepare(
            'SELECT meta_id, meta_key, OCTET_LENGTH(meta_key) AS meta_key_bytes, '
            . 'OCTET_LENGTH(meta_value) AS meta_value_bytes '
            . "FROM `$metaTable` WHERE `$ownerColumn` = %d "
            . "AND meta_key = '_duo_uuid' ORDER BY meta_id ASC LIMIT 3",
            $ownerId
        ), $purpose);
        if (count($rows) !== 1
            || !is_array($rows[0])
            || array_keys($rows[0]) !== [
                'meta_id', 'meta_key', 'meta_key_bytes', 'meta_value_bytes',
            ]
            || ($metaId = self::positive_integer($rows[0]['meta_id'] ?? null)) === null
            || !is_string($rows[0]['meta_key'] ?? null)
            || !hash_equals('_duo_uuid', $rows[0]['meta_key'])
            || self::nonnegative_integer($rows[0]['meta_key_bytes'] ?? null) !== strlen('_duo_uuid')
            || self::nonnegative_integer($rows[0]['meta_value_bytes'] ?? null) !== strlen($uuid)) {
            throw new \RuntimeException(
                "duo: embedded identity does not verify for $uuid ($purpose:$ownerId)"
            );
        }
        $payload = self::checked_rows($wpdb->prepare(
            "SELECT meta_id, meta_key, meta_value FROM `$metaTable` "
            . "WHERE `$ownerColumn` = %d AND meta_id = %d ORDER BY meta_id ASC LIMIT 2",
            $ownerId,
            $metaId
        ), $purpose . ' bounded payload');
        if (count($payload) !== 1
            || !is_array($payload[0])
            || array_keys($payload[0]) !== ['meta_id', 'meta_key', 'meta_value']
            || self::positive_integer($payload[0]['meta_id'] ?? null) !== $metaId
            || !is_string($payload[0]['meta_key'] ?? null)
            || !hash_equals('_duo_uuid', $payload[0]['meta_key'])
            || !is_string($payload[0]['meta_value'] ?? null)
            || !hash_equals($uuid, $payload[0]['meta_value'])) {
            throw new \RuntimeException(
                "duo: embedded identity bounded payload does not verify for $uuid ($purpose:$ownerId)"
            );
        }
    }

    /** @return list<array<string,mixed>> */
    private static function checked_rows(string $sql, string $purpose): array {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)
            || !array_is_list($rows)
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("duo: database error while reading $purpose");
        }
        return $rows;
    }

    private static function assert_identifier(string $identifier, string $purpose): void {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $identifier) !== 1) {
            throw new \RuntimeException("duo: $purpose is not a safe database identifier");
        }
    }

    private static function positive_integer(mixed $value): ?int {
        $integer = self::nonnegative_integer($value);
        return $integer !== null && $integer > 0 ? $integer : null;
    }

    private static function nonnegative_integer(mixed $value): ?int {
        if (is_int($value)) return $value >= 0 ? $value : null;
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) return null;
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return is_int($integer) ? $integer : null;
    }

    private static function hash(array $artifact): string {
        unset($artifact['integrity_sha256']);
        return hash('sha256', Canon::encode($artifact));
    }

    private static function rollback_after_failure(\Throwable $primary, string $context): void {
        Db::rollback_after_failure($primary, $context);
    }
}

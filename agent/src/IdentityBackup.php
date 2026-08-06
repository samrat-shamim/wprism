<?php
namespace Duo;

/** Versioned disaster-recovery sidecar for environment-bound identity. */
final class IdentityBackup {
    public const FORMAT = 'duo-identity-ledger/v1';

    public static function create(string $repo): array {
        Ledger::ensure();
        $policy = Policy::load($repo);
        $compiled = RepositoryCompiler::compile($repo, $policy);
        $tables = self::tables_by_kind($policy);
        global $wpdb;
        $wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        self::assert_db('starting identity export snapshot');
        try {
            Identity::assert_embedded_unique();
            Ledger::prune_dead_map();
            Snapshot::prune_dead_map($policy);
            Snapshot::assert_mapped_history_present($policy, $repo);
            Snapshot::assert_all_mapped_rows_managed($policy);

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
            $wpdb->query('COMMIT');
            self::assert_db('committing identity export snapshot');
        } catch (\Throwable $t) {
            $wpdb->query('ROLLBACK');
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
        $wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        self::assert_db('starting identity import transaction');
        try {
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

            $wpdb->query("DELETE FROM {$wpdb->prefix}duo_map");
            self::assert_db('clearing identity mappings for restore');
            $wpdb->query("DELETE FROM {$wpdb->prefix}duo_state");
            self::assert_db('clearing sync state for restore');
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
            $wpdb->query('COMMIT');
            self::assert_db('committing identity import');
        } catch (\Throwable $t) {
            $wpdb->query('ROLLBACK');
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

    /** @return array<string,array> id_kind => table declaration plus table name */
    private static function tables_by_kind(Policy $policy): array {
        $out = [];
        foreach (Snapshot::row_tables($policy) as $table => $decl) {
            $out[$decl['id_kind']] = ['table' => $table] + $decl;
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
            $values = $wpdb->get_col($wpdb->prepare(
                "SELECT meta_value FROM `$metaTable` WHERE `$ownerCol` = %d AND meta_key = '_duo_uuid' ORDER BY meta_id ASC",
                $local
            )) ?: [];
            if ($values !== [$uuid]) {
                throw new \RuntimeException("duo: embedded identity does not verify for $uuid ($kind:$local)");
            }
            return hash('sha256', Canon::encode(['kind' => $kind, 'local_id' => $local, 'uuid' => $uuid]));
        }
        if ($kind === Ledger::KIND_TT) {
            $termId = $wpdb->get_var($wpdb->prepare(
                "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $local
            ));
            if ($termId === null) {
                throw new \RuntimeException("duo: term-taxonomy identity row $local is missing");
            }
            $values = $wpdb->get_col($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = '_duo_uuid' ORDER BY meta_id ASC",
                (int) $termId
            )) ?: [];
            if ($values !== [$uuid]) {
                throw new \RuntimeException("duo: embedded identity does not verify for $uuid ($kind:$local)");
            }
            return hash('sha256', Canon::encode([
                'kind' => $kind, 'local_id' => $local, 'term_id' => (int) $termId, 'uuid' => $uuid,
            ]));
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

    private static function hash(array $artifact): string {
        unset($artifact['integrity_sha256']);
        return hash('sha256', Canon::encode($artifact));
    }

    private static function assert_db(string $action): void {
        global $wpdb;
        if ($wpdb->last_error) {
            throw new \RuntimeException("duo: database error while $action: {$wpdb->last_error}");
        }
    }
}

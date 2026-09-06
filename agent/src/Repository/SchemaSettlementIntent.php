<?php
declare(strict_types=1);

namespace WPrism;

if (!class_exists(Canon::class, false)) {
    require_once __DIR__ . '/../Kernel/Canon.php';
}
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}
if (!class_exists(Ledger::class, false)) {
    require_once __DIR__ . '/Ledger.php';
}

/** Durable recovery evidence for one checkpointed, non-resumable DDL pass. */
final class SchemaSettlementIntent {
    public const FORMAT = 'wprism-schema-settlement-intent/v1';
    private const KEY = 'schema_settlement_in_progress';

    /**
     * Publish the exact artifact, effect set, checkpoint, and pre-DDL table
     * state before plugin code runs. An interrupted pass is never replayed:
     * MySQL DDL may have committed between provider statements, so only the
     * authenticated pre-phase checkpoint can recover it.
     *
     * @param array{path:string,cipher_sha256:string} $checkpoint
     * @param list<array{table:string,present:bool}> $presence
     * @return array<string,mixed>
     */
    public static function begin(
        Policy $policy,
        string $artifactHash,
        array $checkpoint,
        array $presence
    ): array {
        self::assert_artifact($artifactHash);
        $expected = self::expected($policy, $artifactHash);
        $current = self::read();
        if ($current !== null) {
            self::assert_matches($current, $expected);
            self::throw_recovery_required($current);
        }
        if (Ledger::kv_get('apply_in_progress') !== null) {
            throw new \RuntimeException(
                'wprism: schema settlement refused while an authored apply recovery marker is active; '
                . 'recover that exact apply before preparing target schema'
            );
        }
        self::assert_no_ambiguous_lifecycle_window();
        $checkpoint = self::validate_checkpoint($checkpoint);
        $presence = self::validate_presence($presence, $expected['tables']);
        if (!in_array(false, array_column($presence, 'present'), true)) {
            throw new \RuntimeException(
                'wprism: schema settlement intent requires at least one checked absent table'
            );
        }
        self::assert_preparable_absence($policy, $presence);

        $intent = $expected + [
            'checkpoint' => $checkpoint,
            'presence' => $presence,
        ];
        Ledger::kv_set(self::KEY, Canon::encode($intent));
        $written = self::read();
        if ($written === null || !hash_equals(Canon::encode($intent), Canon::encode($written))) {
            throw new \RuntimeException('wprism: schema settlement intent readback failed before provider mutation');
        }
        return $written;
    }

    /** Refuse every retry until the exact pre-DDL checkpoint has been restored. */
    public static function assert_clear(Policy $policy, string $artifactHash): void {
        $current = self::read();
        if ($current === null) {
            return;
        }
        self::assert_matches($current, self::expected($policy, $artifactHash));
        self::throw_recovery_required($current);
    }

    /** Fence capture, planning, apply, and scoped work without needing an artifact. */
    public static function assert_no_incomplete(): void {
        $current = self::read();
        if ($current !== null) {
            self::throw_recovery_required($current);
        }
    }

    /**
     * Refuse loss before the host takes a promotion lease or advertises a new
     * checkpoint. Re-run the same proof under the lease in begin(): absence is
     * preparable only when no durable canonical identity or state ever named
     * the table's entity authority.
     *
     * @param list<array{table:string,present:bool}> $presence
     */
    public static function assert_preparable_absence(Policy $policy, array $presence): void {
        $tables = array_keys($policy->schema_settle_tables());
        sort($tables, SORT_STRING);
        $presence = self::validate_presence($presence, $tables);
        foreach ($presence as $row) {
            if (!$row['present']) {
                self::assert_no_canonical_history_for_absence($policy, $row['table']);
            }
        }
    }

    /** Clear only the exact artifact/action/effect intent which reached readback. */
    public static function complete(Policy $policy, string $artifactHash): void {
        $current = self::read();
        if ($current === null) {
            throw new \RuntimeException('wprism: schema settlement completion lost its durable intent');
        }
        self::assert_matches($current, self::expected($policy, $artifactHash));
        Ledger::kv_delete(self::KEY);
        if (self::read() !== null) {
            throw new \RuntimeException('wprism: schema settlement intent persisted after verified completion');
        }
    }

    /** @return array<string,mixed>|null */
    public static function current(): ?array {
        return self::read();
    }

    /**
     * Before an exact-topology reset destroys the ledger, bind recovery to
     * the one ciphertext named before non-transactional DDL began. Ordinary
     * retained-checkpoint restores have no schema intent and remain valid.
     */
    public static function assert_recovery_checkpoint(string $path, string $cipherSha256): bool {
        $current = self::read();
        if ($current === null) {
            return false;
        }
        $checkpoint = self::validate_checkpoint([
            'path' => $path,
            'cipher_sha256' => $cipherSha256,
        ]);
        if (($current['checkpoint'] ?? null) !== $checkpoint) {
            throw new \RuntimeException(
                'wprism: selected recovery checkpoint does not match the incomplete schema settlement witness'
            );
        }
        return true;
    }

    /** @return array{actions_sha256:string,artifact_hash:string,effects_sha256:string,format:string,tables:list<string>} */
    private static function expected(Policy $policy, string $artifactHash): array {
        self::assert_artifact($artifactHash);
        $actions = $policy->schema_settle_actions();
        $tables = array_keys($policy->schema_settle_tables());
        sort($tables, SORT_STRING);
        $effects = array_values(array_filter(
            $policy->effects_inventory(),
            static fn(array $row): bool => ($row['phase'] ?? null) === 'schema-settle'
        ));
        return [
            'actions_sha256' => hash('sha256', Canon::encode($actions)),
            'artifact_hash' => $artifactHash,
            'effects_sha256' => hash('sha256', Canon::encode($effects)),
            'format' => self::FORMAT,
            'tables' => $tables,
        ];
    }

    /** @return array<string,mixed>|null */
    private static function read(): ?array {
        $raw = Ledger::kv_get(self::KEY);
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            $intent = Canon::decode($raw);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('wprism: malformed schema settlement recovery intent', 0, $failure);
        }
        if (!is_array($intent) || array_is_list($intent)) {
            throw new \RuntimeException('wprism: malformed schema settlement recovery intent');
        }
        $keys = array_keys($intent);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'actions_sha256', 'artifact_hash', 'checkpoint', 'effects_sha256',
            'format', 'presence', 'tables',
        ]
            || ($intent['format'] ?? null) !== self::FORMAT
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($intent['artifact_hash'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($intent['actions_sha256'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($intent['effects_sha256'] ?? '')) !== 1
            || !is_array($intent['tables'] ?? null)
            || !array_is_list($intent['tables'])) {
            throw new \RuntimeException('wprism: malformed schema settlement recovery intent');
        }
        $tables = [];
        foreach ($intent['tables'] as $table) {
            if (!is_string($table)
                || preg_match('/^[a-z0-9][a-z0-9_]{0,63}$/D', $table) !== 1
                || isset($tables[$table])) {
                throw new \RuntimeException('wprism: malformed schema settlement recovery intent');
            }
            $tables[$table] = true;
        }
        $sorted = array_keys($tables);
        sort($sorted, SORT_STRING);
        if ($intent['tables'] !== $sorted) {
            throw new \RuntimeException('wprism: malformed schema settlement recovery intent');
        }
        self::validate_checkpoint($intent['checkpoint'] ?? null);
        $presence = self::validate_presence($intent['presence'] ?? null, $sorted);
        if (!in_array(false, array_column($presence, 'present'), true)) {
            throw new \RuntimeException('wprism: malformed schema settlement recovery intent');
        }
        return $intent;
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $expected */
    private static function assert_matches(array $current, array $expected): void {
        foreach ($expected as $key => $value) {
            if (($current[$key] ?? null) !== $value) {
                throw new \RuntimeException(
                    'wprism: incomplete schema settlement belongs to a different artifact, action, or effect set; '
                    . 'restore its exact checkpoint before preparing another release'
                );
            }
        }
    }

    /** @return array{path:string,cipher_sha256:string} */
    private static function validate_checkpoint(mixed $checkpoint): array {
        $keys = is_array($checkpoint) ? array_keys($checkpoint) : [];
        sort($keys, SORT_STRING);
        if ($keys !== ['cipher_sha256', 'path']
            || !is_string($checkpoint['path'] ?? null)
            || $checkpoint['path'] === ''
            || str_contains($checkpoint['path'], "\0")
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($checkpoint['cipher_sha256'] ?? '')) !== 1) {
            throw new \RuntimeException('wprism: malformed schema settlement checkpoint witness');
        }
        return [
            'path' => $checkpoint['path'],
            'cipher_sha256' => $checkpoint['cipher_sha256'],
        ];
    }

    /**
     * @param list<string> $tables
     * @return list<array{table:string,present:bool}>
     */
    private static function validate_presence(mixed $presence, array $tables): array {
        if (!is_array($presence) || !array_is_list($presence) || count($presence) !== count($tables)) {
            throw new \RuntimeException('wprism: malformed schema settlement table precondition');
        }
        foreach ($presence as $index => $row) {
            $keys = is_array($row) ? array_keys($row) : [];
            sort($keys, SORT_STRING);
            if ($keys !== ['present', 'table']
                || ($row['table'] ?? null) !== $tables[$index]
                || !is_bool($row['present'] ?? null)) {
                throw new \RuntimeException('wprism: malformed schema settlement table precondition');
            }
        }
        return $presence;
    }

    private static function throw_recovery_required(array $intent): never {
        $path = basename((string) ($intent['checkpoint']['path'] ?? 'checkpoint'));
        throw new \RuntimeException(
            "wprism: incomplete schema settlement is not replayable; restore authenticated checkpoint '$path' "
            . 'before retrying the exact release'
        );
    }

    private static function assert_no_ambiguous_lifecycle_window(): void {
        $raw = Ledger::kv_get('promotion_session');
        if ($raw === null || $raw === '') {
            return;
        }
        $session = json_decode($raw, true);
        if (!is_array($session)) {
            throw new \RuntimeException('wprism: malformed promotion session blocks schema settlement');
        }
        if (array_key_exists('lifecycle_attempt', $session)
            || array_key_exists('pending_state_transition', $session)) {
            throw new \RuntimeException(
                'wprism: unresolved lifecycle recovery evidence blocks schema settlement; '
                . 'restore the exact pre-lifecycle checkpoint first'
            );
        }
    }

    /** Missing schema plus durable canonical identity is loss evidence. */
    private static function assert_no_canonical_history_for_absence(Policy $policy, string $table): void {
        $declaration = $policy->declared_tables()[$table] ?? null;
        if (!is_array($declaration)) {
            return;
        }
        $entityType = $table;
        $idKind = (string) ($declaration['id_kind'] ?? '');
        if (($declaration['class'] ?? null) === 'authored_snapshot_meta') {
            $entityType = (string) ($declaration['attached_to']['table'] ?? '');
            $owner = $policy->declared_tables()[$entityType] ?? null;
            $idKind = is_array($owner) ? (string) ($owner['id_kind'] ?? '') : '';
        }
        if ($entityType === '') {
            return;
        }
        foreach (Ledger::all_map() as $mapping) {
            if ((string) ($mapping['entity_type'] ?? '') === $entityType
                || ($idKind !== '' && (string) ($mapping['id_kind'] ?? '') === $idKind)) {
                throw new \RuntimeException(
                    "wprism: schema-settle table '$table' is absent but durable identity history remains; "
                    . 'restore the database-matched table instead of preparing an empty replacement'
                );
            }
        }
        foreach (Ledger::all_state() as $state) {
            if ((string) ($state['entity_type'] ?? '') === $entityType) {
                throw new \RuntimeException(
                    "wprism: schema-settle table '$table' is absent but durable canonical history remains; "
                    . 'restore the database-matched table instead of preparing an empty replacement'
                );
            }
        }
    }

    private static function assert_artifact(string $artifactHash): void {
        if (preg_match('/^[a-f0-9]{64}$/D', $artifactHash) !== 1) {
            throw new \InvalidArgumentException('wprism: schema settlement requires a valid artifact hash');
        }
    }
}

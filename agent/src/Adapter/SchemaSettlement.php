<?php
declare(strict_types=1);

namespace WPrism;

if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}
if (!class_exists(CompiledRepository::class, false)) {
    require_once __DIR__ . '/../Repository/CompiledArtifact.php';
}
if (!class_exists(SchemaSettlementIntent::class, false)) {
    require_once __DIR__ . '/../Repository/SchemaSettlementIntent.php';
}
if (!class_exists(Ledger::class, false)) {
    require_once __DIR__ . '/../Repository/Ledger.php';
}
if (!class_exists(RetainedCheckpointCipher::class, false)) {
    require_once __DIR__ . '/../Recovery/RetainedCheckpointCipher.php';
}
if (!class_exists(DatabaseTargetIdentity::class, false)) {
    require_once __DIR__ . '/../Recovery/DatabaseTargetIdentity.php';
}
if (!class_exists(ProviderSettlementIntent::class, false)) {
    require_once __DIR__ . '/../Kernel/ProviderSettlementIntent.php';
}
if (!class_exists(PromotionLock::class, false)) {
    require_once __DIR__ . '/../Promotion/PromotionLock.php';
}
if (!class_exists(ProviderPhaseExecutor::class, false)) {
    require_once __DIR__ . '/ProviderPhaseExecutor.php';
}

/** Host-checkpointed establishment of adapter-declared table structure. */
final class SchemaSettlement {
    public const STATUS_FORMAT = 'wprism-schema-settlement-status/v1';
    public const RESULT_FORMAT = 'wprism-schema-settlement/v1';

    public static function assert_ready(Policy $policy): void {
        ProviderPhaseExecutor::assert_ready(
            $policy,
            $policy->schema_settle_actions(),
            'schema settlement'
        );
    }

    /**
     * Read-only host preflight. Planning remains strict: this reports whether
     * a separately checkpointed phase is needed; it never projects absence as
     * an empty authored table.
     *
     * @return array{format:string,declared:bool,required:bool,state:string,tables:list<array{table:string,present:bool}>}
     */
    public static function status(
        string $repo,
        string $artifactPath,
        string $artifactHash,
        bool $presenceOnly = false
    ): array {
        self::assert_artifact($artifactHash);
        if (!class_exists(RepositoryCompiler::class, false)) {
            require_once __DIR__ . '/../Repository/RepositoryCompiler.php';
        }
        $policy = Policy::load($repo);
        $compiled = RepositoryCompiler::read_artifact($artifactPath, $policy);
        if (!hash_equals($artifactHash, $compiled->artifact_hash())) {
            throw new \RuntimeException('wprism: schema-status artifact does not match the host-compiled artifact hash');
        }
        return self::status_locked($policy, $artifactHash, $presenceOnly);
    }

    /**
     * @return array{format:string,declared:bool,required:bool,state:string,tables:list<array{table:string,present:bool}>}
     */
    public static function status_locked(
        Policy $policy,
        string $artifactHash,
        bool $presenceOnly = false
    ): array {
        self::assert_artifact($artifactHash);
        $ledgerReady = self::status_ledger_ready();
        if ($ledgerReady) {
            SchemaSettlementIntent::assert_clear($policy, $artifactHash);
        }
        $actions = $policy->schema_settle_actions();
        $tables = array_keys($policy->schema_settle_tables());
        sort($tables, SORT_STRING);
        $presence = self::checked_presence($tables);
        $required = in_array(false, array_column($presence, 'present'), true);
        if ($actions !== [] && $required && $ledgerReady) {
            SchemaSettlementIntent::assert_preparable_absence($policy, $presence);
        }
        $mode = $presenceOnly ? 'presence' : 'exact';
        if ($actions !== [] && !$presenceOnly) {
            $presence = self::readiness_presence($policy, $presence);
            $required = in_array(false, array_column($presence, 'present'), true);
        }
        return [
            'format' => self::STATUS_FORMAT,
            'declared' => $actions !== [],
            'mode' => $mode,
            'required' => $required,
            'state' => $actions === []
                ? 'none'
                : ($required ? 'required' : ($presenceOnly ? 'present' : 'ready')),
            'tables' => $presence,
        ];
    }

    /**
     * `schema-status` runs before promotion-begin creates the platform ledger
     * on a fresh target. Exact absence of all four tables proves there can be
     * no database-resident schema intent or canonical identity history; any
     * partial set is loss/corruption and must refuse rather than be repaired
     * before the host checkpoint exists.
     */
    private static function status_ledger_ready(): bool {
        global $wpdb;
        $present = [];
        foreach (Ledger::OWN_TABLES as $table) {
            $physical = (string) $wpdb->prefix . $table;
            $wpdb->last_error = '';
            $found = $wpdb->get_var($wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like($physical)
            ));
            if ($found === false || (string) ($wpdb->last_error ?? '') !== '') {
                throw new \RuntimeException(
                    'wprism: schema-status could not inspect the target ledger boundary'
                );
            }
            if ($found === null) {
                $present[$table] = false;
                continue;
            }
            if (!is_string($found) || !hash_equals($physical, $found)) {
                throw new \RuntimeException(
                    'wprism: schema-status target ledger inventory returned an ambiguous identity'
                );
            }
            $present[$table] = true;
        }
        if (!in_array(true, $present, true)) {
            return false;
        }
        if (in_array(false, $present, true)) {
            throw new \RuntimeException(
                'wprism: schema-status target ledger boundary is incomplete; '
                . 'restore the database-matched platform ledger before deployment'
            );
        }
        return true;
    }

    /** @return array{format:string,actions:int,receipts:list<array<string,mixed>>,tables:list<string>} */
    public static function run(
        string $repo,
        string $artifactPath,
        string $artifactHash,
        string $promotionOwner,
        string $checkpointPath,
        bool $afterCodeTransition,
        bool $releaseOnSuccess = false
    ): array {
        return ProviderSettlementIntent::with_phase(
            $repo,
            $artifactPath,
            $checkpointPath,
            $promotionOwner,
            $artifactHash,
            'schema-settle',
            static fn(array $providerIntent): array => self::run_continued(
                $repo,
                $artifactPath,
                $artifactHash,
                $promotionOwner,
                $checkpointPath,
                $afterCodeTransition,
                $releaseOnSuccess,
                (string) ($providerIntent['checkpoint']['cipher_sha256'] ?? '')
            )
        );
    }

    /** @return array{format:string,actions:int,receipts:list<array<string,mixed>>,tables:list<string>} */
    private static function run_continued(
        string $repo,
        string $artifactPath,
        string $artifactHash,
        string $promotionOwner,
        string $checkpointPath,
        bool $afterCodeTransition,
        bool $releaseOnSuccess,
        string $expectedCipherSha256
    ): array {
        self::assert_identity($artifactHash, $promotionOwner);
        self::assert_release_pair($repo, $artifactPath, $checkpointPath, $promotionOwner);
        if (!class_exists(RepositoryCompiler::class, false)) {
            require_once __DIR__ . '/../Repository/RepositoryCompiler.php';
        }
        $policy = Policy::load($repo);
        $compiled = RepositoryCompiler::read_artifact($artifactPath, $policy);
        if (!hash_equals($artifactHash, $compiled->artifact_hash())) {
            throw new \RuntimeException('wprism: schema-settle artifact does not match the host-compiled artifact hash');
        }
        $checkpoint = RetainedCheckpointCipher::verify($repo, $checkpointPath);
        DatabaseTargetIdentity::assertWordPressConfig(
            (string) ($checkpoint['database_target_sha256'] ?? '')
        );
        if (!hash_equals($expectedCipherSha256, (string) ($checkpoint['cipher_sha256'] ?? ''))) {
            throw new \RuntimeException(
                'wprism: schema-settle checkpoint ciphertext changed after provider settlement authorization'
            );
        }
        PromotionLock::acquire($promotionOwner, $artifactHash, 'schema-settle', null, true);
        try {
            if ($afterCodeTransition) {
                PromotionLock::assert_lifecycle_complete($promotionOwner, $artifactHash);
            } else {
                PromotionLock::assert_no_lifecycle_attempt($promotionOwner, $artifactHash, 'schema-settle');
            }
            $summary = self::run_locked(
                $policy,
                $compiled,
                $promotionOwner,
                $artifactHash,
                [
                    'path' => $checkpointPath,
                    'cipher_sha256' => (string) $checkpoint['cipher_sha256'],
                ]
            );
            if ($releaseOnSuccess) {
                PromotionLock::release($promotionOwner, $artifactHash);
            }
            return $summary;
        } catch (\Throwable $failure) {
            try {
                PromotionLock::release($promotionOwner, $artifactHash);
            } catch (\Throwable $_releaseFailure) {
                // Preserve the provider refusal. The intent names the exact
                // authenticated checkpoint which remains recovery authority.
            }
            throw $failure;
        }
    }

    /**
     * @param array{path:string,cipher_sha256:string} $checkpoint
     * @return array{format:string,actions:int,receipts:list<array<string,mixed>>,tables:list<string>}
     */
    public static function run_locked(
        Policy $policy,
        CompiledRepository $compiled,
        string $promotionOwner,
        string $artifactHash,
        array $checkpoint
    ): array {
        self::assert_identity($artifactHash, $promotionOwner);
        if (!hash_equals($artifactHash, $compiled->artifact_hash())) {
            throw new \RuntimeException('wprism: schema settlement lost its exact compiled artifact');
        }
        PromotionLock::assert_no_lifecycle_attempt($promotionOwner, $artifactHash, 'schema-settle');
        $status = self::status_locked($policy, $artifactHash);
        $actions = $policy->schema_settle_actions();
        $tables = array_keys($policy->schema_settle_tables());
        sort($tables, SORT_STRING);
        if (!$status['required']) {
            PromotionLock::heartbeat($promotionOwner, $artifactHash, 'schema-ready');
            return [
                'format' => self::RESULT_FORMAT,
                'actions' => 0,
                'receipts' => [],
                'tables' => $tables,
            ];
        }

        $intent = SchemaSettlementIntent::begin(
            $policy,
            $artifactHash,
            $checkpoint,
            $status['tables']
        );
        PromotionLock::heartbeat($promotionOwner, $artifactHash, 'schema-settle-intent');
        $receipts = ProviderPhaseExecutor::run(
            $policy,
            $actions,
            'schema settlement',
            static fn(): mixed => PromotionLock::heartbeat(
                $promotionOwner,
                $artifactHash,
                'schema-settle-provider'
            )
        );
        self::assert_receipts($actions, $receipts, (array) $intent['presence']);
        self::assert_prepared_tables($tables, (array) $intent['presence']);
        SchemaSettlementIntent::complete($policy, $artifactHash);
        PromotionLock::heartbeat($promotionOwner, $artifactHash, 'schema-settled');

        return [
            'format' => self::RESULT_FORMAT,
            'actions' => count($actions),
            'receipts' => $receipts,
            'tables' => $tables,
        ];
    }

    /**
     * Schema receipts have one portable meaning: present tables are unchanged,
     * absent tables become present, and every table has a checked schema hash.
     *
     * @param list<array<string,mixed>> $actions
     * @param list<array<string,mixed>> $receipts
     * @param list<array{table:string,present:bool}> $presence
     */
    private static function assert_receipts(array $actions, array $receipts, array $presence): void {
        if (count($actions) !== count($receipts)) {
            throw new \RuntimeException('wprism: schema settlement returned incomplete provider receipts');
        }
        $present = [];
        foreach ($presence as $row) {
            $present[(string) $row['table']] = (bool) $row['present'];
        }
        foreach ($actions as $index => $action) {
            $expectedTables = array_values(array_map('strval', (array) ($action['prepares'] ?? [])));
            $receipt = $receipts[$index]['receipt'] ?? null;
            if (!is_array($receipt)
                || ($receipt['verified'] ?? null) !== true
                || !is_array($receipt['before'] ?? null)
                || !is_array($receipt['after'] ?? null)) {
                throw new \RuntimeException('wprism: schema settlement provider returned malformed structure evidence');
            }
            $before = $receipt['before'];
            $after = $receipt['after'];
            $beforeKeys = array_keys($before);
            $afterKeys = array_keys($after);
            sort($beforeKeys, SORT_STRING);
            sort($afterKeys, SORT_STRING);
            if ($beforeKeys !== $expectedTables || $afterKeys !== $expectedTables) {
                throw new \RuntimeException('wprism: schema settlement provider evidence does not exactly cover prepares');
            }
            foreach ($expectedTables as $table) {
                $beforeRow = self::schema_evidence_row($before[$table] ?? null, true, true);
                $afterRow = self::schema_evidence_row($after[$table] ?? null, false, true);
                if ($beforeRow['present'] !== ($present[$table] ?? null)) {
                    throw new \RuntimeException(
                        "wprism: schema settlement provider precondition disagrees for table '$table'"
                    );
                }
                if (!$afterRow['present']) {
                    throw new \RuntimeException(
                        "wprism: schema settlement provider did not establish table '$table'"
                    );
                }
                if ($beforeRow['present'] && $beforeRow !== $afterRow) {
                    throw new \RuntimeException(
                        "wprism: schema settlement provider changed existing table '$table'; the phase is create-only"
                    );
                }
                if (!$beforeRow['present']
                    && (($afterRow['row_count'] ?? null) !== 0
                        || !is_string($afterRow['rows_sha256'] ?? null))) {
                    throw new \RuntimeException(
                        "wprism: schema settlement provider seeded formerly absent table '$table'; the phase is create-only"
                    );
                }
            }
        }
    }

    /**
     * Invoke only the capability whose declared writes set is empty. Its
     * digest-bound projection is the adapter-specific half of readiness;
     * generic table presence alone cannot distinguish a stale schema.
     *
     * @param list<array{table:string,present:bool}> $observedPresence
     * @return list<array{table:string,present:bool}>
     */
    private static function readiness_presence(Policy $policy, array $observedPresence): array {
        $actions = $policy->schema_readiness_actions();
        $receipts = ProviderPhaseExecutor::run(
            $policy,
            $actions,
            'schema readiness',
            static function (): void {}
        );
        if (count($actions) !== count($receipts)) {
            throw new \RuntimeException('wprism: schema readiness returned incomplete provider receipts');
        }
        $observed = [];
        foreach ($observedPresence as $row) {
            $observed[(string) $row['table']] = (bool) $row['present'];
        }
        $ready = [];
        foreach ($actions as $index => $action) {
            $tables = array_values(array_map(
                'strval',
                (array) ($action['_schema_readiness_tables'] ?? [])
            ));
            $receipt = $receipts[$index]['receipt'] ?? null;
            if (!is_array($receipt)
                || ($receipt['verified'] ?? null) !== true
                || !is_array($receipt['before'] ?? null)
                || $receipt['before'] !== ($receipt['after'] ?? null)) {
                throw new \RuntimeException(
                    'wprism: schema readiness provider returned malformed or mutating evidence'
                );
            }
            $keys = array_keys($receipt['before']);
            sort($keys, SORT_STRING);
            if ($keys !== $tables) {
                throw new \RuntimeException(
                    'wprism: schema readiness provider evidence does not exactly cover prepares'
                );
            }
            foreach ($tables as $table) {
                if (isset($ready[$table])) {
                    throw new \RuntimeException(
                        "wprism: schema readiness returned duplicate table authority '$table'"
                    );
                }
                $row = self::schema_evidence_row($receipt['before'][$table] ?? null, true);
                if ($row['present'] !== ($observed[$table] ?? null)) {
                    throw new \RuntimeException(
                        "wprism: schema readiness provider disagrees with checked presence for table '$table'"
                    );
                }
                $ready[$table] = ['table' => $table, 'present' => $row['present']];
            }
        }
        ksort($ready, SORT_STRING);
        if (array_keys($ready) !== array_keys($observed)) {
            throw new \RuntimeException('wprism: schema readiness did not exactly cover declared tables');
        }
        return array_values($ready);
    }

    /** @return array<string,mixed> */
    private static function schema_evidence_row(
        mixed $row,
        bool $allowAbsent,
        bool $requireRows = false
    ): array {
        $keys = is_array($row) ? array_keys($row) : [];
        sort($keys, SORT_STRING);
        $expectedKeys = $requireRows
            ? ['present', 'row_count', 'rows_sha256', 'schema_hash']
            : ['present', 'schema_hash'];
        if ($keys !== $expectedKeys || !is_bool($row['present'] ?? null)) {
            throw new \RuntimeException('wprism: schema settlement provider returned malformed table evidence');
        }
        $hash = $row['schema_hash'] ?? null;
        if (($row['present'] && (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1))
            || (!$row['present'] && (!$allowAbsent || $hash !== null))) {
            throw new \RuntimeException('wprism: schema settlement provider returned malformed table evidence');
        }
        $validated = ['present' => $row['present'], 'schema_hash' => $hash];
        if (!$requireRows) {
            return $validated;
        }
        $rowCount = $row['row_count'] ?? null;
        $rowsHash = $row['rows_sha256'] ?? null;
        if (!is_int($rowCount)
            || $rowCount < 0
            || ($row['present']
                && (!is_string($rowsHash) || preg_match('/^[a-f0-9]{64}$/D', $rowsHash) !== 1))
            || (!$row['present'] && ($rowCount !== 0 || $rowsHash !== null))) {
            throw new \RuntimeException('wprism: schema settlement provider returned malformed table content evidence');
        }
        return $validated + ['row_count' => $rowCount, 'rows_sha256' => $rowsHash];
    }

    /** @param list<string> $tables @param list<array{table:string,present:bool}> $presence */
    private static function assert_prepared_tables(array $tables, array $presence): void {
        global $wpdb;
        $before = [];
        foreach ($presence as $row) {
            $before[(string) $row['table']] = (bool) $row['present'];
        }
        $after = self::checked_presence($tables);
        foreach ($after as $row) {
            $table = $row['table'];
            if (!$row['present']) {
                throw new \RuntimeException(
                    "wprism: schema settlement provider did not create declared table '$table'"
                );
            }
            if ($before[$table] ?? true) {
                continue;
            }
            $physical = $wpdb->prefix . $table;
            $wpdb->last_error = '';
            $count = $wpdb->get_var("SELECT COUNT(*) FROM `$physical`");
            if ($count === false || (string) ($wpdb->last_error ?? '') !== '') {
                throw new \RuntimeException(
                    "wprism: schema settlement could not verify virgin table '$table'"
                );
            }
            if ((int) $count !== 0) {
                throw new \RuntimeException(
                    "wprism: schema settlement seeded rows into formerly absent table '$table'; "
                    . 'the phase may establish structure only'
                );
            }
        }
    }

    /** @param list<string> $tables @return list<array{table:string,present:bool}> */
    private static function checked_presence(array $tables): array {
        global $wpdb;
        $rows = [];
        foreach ($tables as $table) {
            if (preg_match('/^[a-z0-9][a-z0-9_]{0,63}$/D', $table) !== 1) {
                throw new \RuntimeException('wprism: schema settlement received an invalid declared table name');
            }
            $physical = $wpdb->prefix . $table;
            $wpdb->last_error = '';
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $physical));
            if ($found === false || (string) ($wpdb->last_error ?? '') !== '') {
                throw new \RuntimeException(
                    'wprism: schema settlement table inventory could not be read; refusing to infer absence'
                );
            }
            $present = is_string($found) && hash_equals($physical, $found);
            if ($found !== null && !$present) {
                throw new \RuntimeException(
                    'wprism: schema settlement table inventory returned an ambiguous identity'
                );
            }
            if ($present) {
                $wpdb->last_error = '';
                $columns = $wpdb->get_results("SHOW COLUMNS FROM `$physical`", ARRAY_A);
                if (!is_array($columns) || $columns === [] || (string) ($wpdb->last_error ?? '') !== '') {
                    throw new \RuntimeException(
                        "wprism: schema settlement could not read columns for present table '$table'"
                    );
                }
            }
            $rows[] = ['table' => $table, 'present' => $present];
        }
        return $rows;
    }

    private static function assert_artifact(string $artifactHash): void {
        if (preg_match('/^[a-f0-9]{64}$/D', $artifactHash) !== 1) {
            throw new \InvalidArgumentException('wprism: schema settlement requires a valid artifact hash');
        }
    }

    /**
     * Recovery discovers a retained checkpoint's lease identity from the
     * sibling artifact with the same stem (RetainedCheckpoints::script()). A
     * cross-stem checkpoint would let schema DDL publish debt which the
     * recovery product can never match, so target authority rejects the pair
     * before authenticating the checkpoint or publishing an intent.
     */
    private static function assert_release_pair(
        string $repo,
        string $artifactPath,
        string $checkpointPath,
        string $promotionOwner
    ): void {
        $repoInput = rtrim($repo, '/');
        $root = realpath($repoInput);
        $artifactDirectory = $root === false ? '' : $root . '/.wprism/artifacts';
        $checkpointDirectory = $root === false ? '' : $root . '/.wprism/checkpoints';
        $artifactName = basename($artifactPath);
        $checkpointName = basename($checkpointPath);
        if ($root === false
            || is_link($repoInput)
            || !is_dir($artifactDirectory)
            || is_link($artifactDirectory)
            || realpath($artifactDirectory) !== $artifactDirectory
            || !is_dir($checkpointDirectory)
            || is_link($checkpointDirectory)
            || realpath($checkpointDirectory) !== $checkpointDirectory
            || $artifactPath !== $artifactDirectory . '/' . $artifactName
            || !is_file($artifactPath)
            || is_link($artifactPath)
            || realpath($artifactPath) !== $artifactPath
            || $checkpointPath !== $checkpointDirectory . '/' . $checkpointName
            || !is_file($checkpointPath)
            || is_link($checkpointPath)
            || realpath($checkpointPath) !== $checkpointPath) {
            throw new \RuntimeException(
                'wprism: schema-settle artifact/checkpoint boundary is invalid'
            );
        }
        if (preg_match(
            '/^(deploy|promote|materialize)-([A-Za-z0-9][A-Za-z0-9._-]{0,127})\.json$/D',
            $artifactName,
            $artifact
        ) !== 1
            || preg_match(
                '/^(deploy|promote|materialize)-([A-Za-z0-9][A-Za-z0-9._-]{0,127})\.sql\.enc$/D',
                $checkpointName,
                $checkpoint
            ) !== 1
            || $artifact[1] !== $checkpoint[1]
            || $artifact[2] !== $checkpoint[2]) {
            throw new \RuntimeException(
                'wprism: schema-settle artifact and checkpoint do not name the same release'
            );
        }
        $expectedOwner = $artifact[1] === 'materialize'
            ? 'wprism-env-promotion-' . $artifact[2]
            : $artifact[2];
        if (!hash_equals($expectedOwner, $promotionOwner)) {
            throw new \RuntimeException(
                'wprism: schema-settle checkpoint release does not match the promotion owner'
            );
        }
    }

    private static function assert_identity(string $artifactHash, string $promotionOwner): void {
        self::assert_artifact($artifactHash);
        if ($promotionOwner === '') {
            throw new \InvalidArgumentException('wprism: schema-settle requires the host promotion owner');
        }
    }
}

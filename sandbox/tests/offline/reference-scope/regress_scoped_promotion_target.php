<?php
declare(strict_types=1);

/**
 * Offline target-handoff regression for issue #3344 scoped promotion.
 *
 * This deliberately supplies only PromotionLock's Ledger/Db seam: the
 * random target generation plus its signed external receipt must survive an
 * exact host retry. A profile-less completed ordinary residue with no lease
 * is replaced only through PromotionLock's fresh fenced acquire path, while
 * scoped mismatches and lifecycle ambiguity must never be silently replaced.
 * Storage/query authority is exercised with real Db/Ledger and the shared
 * FakeWpdb in regress_promotion_begin_atomicity.php, not this semantic seam.
 * The Apply checks use reflection because these are pre-mutation gates; no
 * WordPress bootstrap, database, cache backend, or Docker target is needed
 * to prove their closed selection vocabulary.
 */

namespace WPrism {
    /** @internal Test-only in-memory substitute for the target kv seam. */
    final class ScopedPromotionTargetLedger {
        /** @var array<string,string> */
        public static array $values = [];
        /** @var array<string,string>|null */
        private static ?array $transactionValues = null;
        public static bool $failNextPromotionSessionUpsert = false;
        public static bool $terminalCommitFailure = false;
        public static int $promotionLockWrites = 0;
        public static int $transactionStarts = 0;
        public static int $transactionRollbacks = 0;
        public static int $promotionSessionWrites = 0;
        public static ?string $injectAfterAbsentPromotionSessionRead = null;
        /** @var list<string> */
        public static array $transactionReadKeys = [];
        /** @var list<string> */
        public static array $lastTransactionReadKeys = [];

        public static function get(string $key): ?string {
            if (self::$transactionValues !== null) {
                self::$transactionReadKeys[] = $key;
            }
            $values = self::$transactionValues ?? self::$values;
            $value = $values[$key] ?? null;
            if ($key === 'promotion_session'
                && $value === null
                && self::$transactionValues === null
                && self::$injectAfterAbsentPromotionSessionRead !== null) {
                // Return the absent value seen by begin_scoped(), then place
                // ordinary recovery residue before acquire_internal() takes
                // its fenced re-read. This is the exact outer-read race that
                // must never let initial scoped begin overwrite a receipt.
                self::$values[$key] = self::$injectAfterAbsentPromotionSessionRead;
                self::$injectAfterAbsentPromotionSessionRead = null;
            }
            return $value;
        }

        public static function set(string $key, string $value): void {
            if ($key === 'promotion_session' && self::$failNextPromotionSessionUpsert) {
                self::$failNextPromotionSessionUpsert = false;
                throw new \RuntimeException('injected scoped promotion session upsert failure');
            }
            if ($key === 'promotion_session') {
                self::$promotionSessionWrites++;
            }
            if ($key === 'promotion_lock') {
                self::$promotionLockWrites++;
            }
            if (self::$transactionValues !== null) {
                self::$transactionValues[$key] = $value;
                return;
            }
            self::$values[$key] = $value;
        }

        public static function delete(string $key): void {
            if (self::$transactionValues !== null) {
                unset(self::$transactionValues[$key]);
                return;
            }
            unset(self::$values[$key]);
        }

        public static function has(string $key): bool {
            $values = self::$transactionValues ?? self::$values;
            return array_key_exists($key, $values);
        }

        public static function start(): void {
            if (self::$transactionValues !== null) {
                throw new \RuntimeException('fake scoped promotion transaction already open');
            }
            self::$transactionStarts++;
            self::$transactionValues = self::$values;
            self::$transactionReadKeys = [];
        }

        public static function commit(): void {
            if (self::$transactionValues === null) {
                throw new \RuntimeException('fake scoped promotion transaction is absent at commit');
            }
            self::$values = self::$transactionValues;
            self::$transactionValues = null;
            self::$lastTransactionReadKeys = self::$transactionReadKeys;
            self::$transactionReadKeys = [];
        }

        public static function rollback(): void {
            if (self::$transactionValues === null) {
                throw new \RuntimeException('fake scoped promotion transaction is absent at rollback');
            }
            self::$transactionRollbacks++;
            self::$lastTransactionReadKeys = self::$transactionReadKeys;
            self::$transactionReadKeys = [];
            self::$transactionValues = null;
        }

        public static function transactionOpen(): bool {
            return self::$transactionValues !== null;
        }
    }

    // PromotionLock refers to these names directly. This file loads it before
    // the production Ledger/Db implementation so its storage contract, rather
    // than a WordPress database, is the exercised dependency.
    final class Ledger {
        public static function kv_get(string $key): ?string {
            return ScopedPromotionTargetLedger::get($key);
        }

        public static function kv_set(string $key, string $value): void {
            ScopedPromotionTargetLedger::set($key, $value);
        }
    }

    final class Db {
        public static function query(string $sql, string $context): int {
            return $GLOBALS['wpdb']->execute($sql);
        }

        public static function start(
            string $context,
            NativeDatabaseProfile $profile
        ): void {
            ScopedPromotionTargetLedger::start();
        }

        public static function mutation(
            string $head,
            string $condition,
            string $tail,
            string $context,
            array $readTables = []
        ): int {
            return $GLOBALS['wpdb']->executeMutation($head, $condition, $tail);
        }

        public static function commit(string $context = 'transaction commit'): void {
            ScopedPromotionTargetLedger::commit();
            if (ScopedPromotionTargetLedger::$terminalCommitFailure) {
                throw new \RuntimeException('injected terminal scoped replacement commit outcome');
            }
        }

        public static function rollback(string $context = 'transaction rollback'): void {
            ScopedPromotionTargetLedger::rollback();
        }

        public static function rollback_after_failure(\Throwable $primary, string $context): void {
            if (ScopedPromotionTargetLedger::$terminalCommitFailure
                && !ScopedPromotionTargetLedger::transactionOpen()) {
                throw new \RuntimeException(
                    'injected terminal scoped replacement recovery_required',
                    0,
                    $primary
                );
            }
            self::rollback($context);
        }
    }

    function wp_json_encode(mixed $value): string {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}

namespace {
    use WPrism\Apply;
    use WPrism\PromotionLock;
    use WPrism\ScopedPromotionAuthority;
    use WPrism\VerifiedPromotionAuthority;
    use WPrism\ScopedPromotionTargetLedger;
    use WPrism\Orchestrator\CodeDeploy;
    use WPrism\Recovery\RollbackControl;

    /**
     * Enough of wpdb's promotion-lock surface to drive the real SQL-facing
     * code. prepare() keeps values out of SQL parsing so the fake is a narrow
     * protocol seam, not a second implementation of WordPress escaping.
     */
    final class ScopedPromotionTargetFakeWpdb {
        public string $prefix = 'wp_';
        public string $dbname = 'wprism_scoped_promotion_target_test';
        public string $last_error = '';
        private int $connection = 4401;
        private bool $fenceHeld = false;

        public function prepare(string $query, mixed ...$args): string {
            return 'wprism-test-sql:' . base64_encode(serialize([$query, $args]));
        }

        public function get_var(string $query): string|false|null {
            if ($query === 'SELECT CONNECTION_ID()') {
                return (string) $this->connection;
            }
            [$template, $args] = $this->decode($query);
            if (str_contains($template, 'GET_LOCK')) {
                $this->fenceHeld = true;
                return '1';
            }
            if (str_contains($template, 'IS_USED_LOCK')) {
                return $this->fenceHeld ? (string) $this->connection : null;
            }
            if (str_contains($template, 'RELEASE_LOCK')) {
                $this->fenceHeld = false;
                return '1';
            }
            throw new RuntimeException('unexpected promotion-lock scalar query');
        }

        public function execute(string $sql): int {
            [$template, $args] = $this->decode($sql);
            if (str_contains($template, 'INSERT INTO') && str_contains($template, 'ON DUPLICATE KEY UPDATE')) {
                [$key, $encoded, $expiredAt, $differentOwner, $liveAt, $sameOwner, $sameArtifact] = $args;
                $current = $this->current((string) $key);
                $replace = $current === null
                    || ((int) $current['expires_at'] <= (int) $expiredAt
                        && (string) $current['owner'] !== (string) $differentOwner)
                    || ((int) $current['expires_at'] > (int) $liveAt
                        && (string) $current['owner'] === (string) $sameOwner
                        && (string) $current['artifact_hash'] === (string) $sameArtifact);
                if ($replace) {
                    ScopedPromotionTargetLedger::set((string) $key, (string) $encoded);
                    return 1;
                }
                return 0;
            }
            if (str_contains($template, 'DELETE FROM') && str_contains($template, 'expires_at')) {
                [$key, $owner, $artifact, $now] = $args;
                $current = $this->current((string) $key);
                if ($current !== null
                    && (string) $current['owner'] === (string) $owner
                    && (string) $current['artifact_hash'] === (string) $artifact
                    && (int) $current['expires_at'] <= (int) $now) {
                    ScopedPromotionTargetLedger::delete((string) $key);
                    return 1;
                }
                return 0;
            }
            if (str_contains($template, 'INSERT IGNORE INTO')) {
                [$key, $encoded] = $args;
                if (ScopedPromotionTargetLedger::has((string) $key)) {
                    return 0;
                }
                ScopedPromotionTargetLedger::set((string) $key, (string) $encoded);
                return 1;
            }
            if (str_contains($template, "JSON_EXTRACT(v, '$.profile')")) {
                [$key, $owner, $artifact, $profile, $receipt, $scopeHash,
                    $receiptId, $generation, $targetId] = $args;
                $current = $this->current((string) $key);
                if ($current !== null
                    && (string) $current['owner'] === (string) $owner
                    && (string) $current['artifact_hash'] === (string) $artifact
                    && (string) ($current['profile'] ?? '') === (string) $profile
                    && (string) ($current['scoped_receipt_sha256'] ?? '') === (string) $receipt
                    && (string) ($current['scoped_scope_hash'] ?? '') === (string) $scopeHash
                    && (string) ($current['scoped_receipt_id'] ?? '') === (string) $receiptId
                    && (int) ($current['scoped_generation'] ?? 0) === (int) $generation
                    && (string) ($current['scoped_target_id'] ?? '') === (string) $targetId) {
                    ScopedPromotionTargetLedger::delete((string) $key);
                    return 1;
                }
                return 0;
            }
            if (str_contains($template, 'DELETE FROM')) {
                [$key, $owner, $artifact] = $args;
                $current = $this->current((string) $key);
                if ($current !== null
                    && (string) $current['owner'] === (string) $owner
                    && (string) $current['artifact_hash'] === (string) $artifact) {
                    ScopedPromotionTargetLedger::delete((string) $key);
                    return 1;
                }
                return 0;
            }
            throw new RuntimeException('unexpected promotion-lock mutation query');
        }

        public function executeMutation(string $head, string $condition, string $tail): int {
            [$headTemplate, $headArgs] = $this->decodeFragment($head);
            [$conditionTemplate, $conditionArgs] = $this->decodeFragment($condition);
            [$tailTemplate, $tailArgs] = $this->decodeFragment($tail);
            $template = $headTemplate
                . ($conditionTemplate === '' ? '' : ' WHERE ' . $conditionTemplate)
                . ($tailTemplate === '' ? '' : ' ' . $tailTemplate);
            return $this->execute('wprism-test-sql:' . base64_encode(serialize([
                $template,
                array_merge($headArgs, $conditionArgs, $tailArgs),
            ])));
        }

        /** @return array{0:string,1:list<mixed>} */
        private function decodeFragment(string $fragment): array {
            if ($fragment === '') {
                return ['', []];
            }
            if (!str_starts_with($fragment, 'wprism-test-sql:')) {
                return [$fragment, []];
            }
            return $this->decode($fragment);
        }

        /** @return array{0:string,1:list<mixed>} */
        private function decode(string $query): array {
            if (!str_starts_with($query, 'wprism-test-sql:')) {
                throw new RuntimeException('unexpected unprepared promotion-lock query');
            }
            $decoded = unserialize(base64_decode(substr($query, strlen('wprism-test-sql:'))), [
                'allowed_classes' => false,
            ]);
            if (!is_array($decoded) || !is_string($decoded[0] ?? null) || !is_array($decoded[1] ?? null)) {
                throw new RuntimeException('malformed fake promotion-lock query');
            }
            return [$decoded[0], $decoded[1]];
        }

        /** @return array<string,mixed>|null */
        private function current(string $key): ?array {
            $raw = ScopedPromotionTargetLedger::get($key);
            return $raw === null ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }
    }

    /** The only direct Cli path tested below stops at its pre-apply gate. */
    final class WP_CLI {
        public static function add_command(string $name, mixed $callable): void {}

        public static function error(string $message): void {
            throw new RuntimeException('wp-cli-error: ' . $message);
        }
    }

    $root = dirname(__DIR__, 4);
    $GLOBALS['wpdb'] = new ScopedPromotionTargetFakeWpdb();
    require_once "$root/agent/src/Promotion/PromotionLock.php";
    require_once "$root/agent/src/Promotion/ScopedPromotionAuthority.php";
    require_once "$root/agent/src/Promotion/VerifiedPromotionAuthority.php";
    require_once "$root/recovery/rollback-control.php";
    require_once "$root/agent/src/Apply/Apply.php";
    require_once "$root/agent/src/Command/Cli.php";
    require_once "$root/cli/src/Transport/CodeDeploy.php";

    $checks = 0;
    $failures = 0;
    $check = static function (bool $condition, string $message) use (&$checks, &$failures): void {
        $checks++;
        echo ($condition ? 'ok: ' : 'FAIL: ') . $message . "\n";
        if (!$condition) {
            $failures++;
        }
    };
    $expect = static function (callable $operation, string $needle, string $message) use ($check): void {
        try {
            $operation();
            $check(false, $message . ' (did not refuse)');
        } catch (Throwable $failure) {
            $check(str_contains($failure->getMessage(), $needle), $message . ' (' . $failure->getMessage() . ')');
        }
    };

    $owner = 'scoped-owner';
    $artifact = str_repeat('a', 64);
    $receipt = str_repeat('c', 64);
    $otherReceipt = str_repeat('d', 64);
    $scopeHash = str_repeat('e', 64);
    $witness = [
        'active' => true,
        'allow_deletes' => false,
        'artifact_hash' => $artifact,
        'exclusion_state' => 'held',
        'format' => 'wprism-scoped-promotion-witness/v1',
        'generation' => 7,
        'ok' => true,
        'owner' => $owner,
        'receipt_format' => 'wprism-scoped-promotion-receipt/v1',
        'receipt_id' => str_repeat('r', 32),
        'receipt_payload_sha256' => $receipt,
        'recovery_ready' => true,
        'scope_hash' => $scopeHash,
        'signing_key_id' => 'offline-key-1',
        'state' => 'promoting',
        'target_id' => str_repeat('f', 32),
        'terminal' => false,
    ];
    try {
        ScopedPromotionAuthority::validate(
            $witness, $owner, $artifact, $receipt, $scopeHash, ['promoting']
        );
        $check(true, 'closed external witness accepts the exact signed authority/exclusion tuple');
    } catch (Throwable $failure) {
        $check(false, 'valid external witness was refused (' . $failure->getMessage() . ')');
    }
    $expect(
        static fn() => ScopedPromotionAuthority::validate(
            array_replace($witness, ['scope_hash' => str_repeat('1', 64)]),
            $owner, $artifact, $receipt, $scopeHash, ['promoting']
        ),
        'does not match the exact held checkpoint generation',
        'a valid signed receipt cannot authorize a different compact scope'
    );
    foreach (['owner', 'artifact_hash', 'receipt_payload_sha256'] as $mismatchedField) {
        $mismatched = $mismatchedField === 'owner' ? 'other-owner' : str_repeat('1', 64);
        $expect(
            static fn() => ScopedPromotionAuthority::validate(
                array_replace($witness, [$mismatchedField => $mismatched]),
                $owner, $artifact, $receipt, $scopeHash, ['promoting']
            ),
            'does not match the exact held checkpoint generation',
            "a signed witness with mismatched $mismatchedField cannot authorize target mutation"
        );
    }
    $expect(
        static fn() => ScopedPromotionAuthority::validate(
            $witness + ['unexpected_target_value' => 'must-refuse'],
            $owner, $artifact, $receipt, $scopeHash, ['promoting']
        ),
        'does not match the exact held checkpoint generation',
        'unknown witness fields are refused by the closed target authority schema'
    );
    $expect(
        static fn() => ScopedPromotionAuthority::validate(
            array_replace($witness, ['exclusion_state' => 'released']),
            $owner, $artifact, $receipt, $scopeHash, ['promoting']
        ),
        'does not match the exact held checkpoint generation',
        'a released exclusion cannot authorize target mutation'
    );
    try {
        ScopedPromotionAuthority::validate(
            array_replace($witness, ['state' => 'committed', 'terminal' => true]),
            $owner, $artifact, $receipt, $scopeHash, ['committed']
        );
        $check(true, 'delayed target completion accepts the exact committed held-exclusion witness');
    } catch (Throwable $failure) {
        $check(false, 'valid committed completion witness was refused (' . $failure->getMessage() . ')');
    }
    $verifiedWitness = [
        'active' => true,
        'allow_deletes' => true,
        'artifact_hash' => $artifact,
        'exclusion_state' => 'held',
        'format' => 'wprism-verified-promotion-witness/v1',
        'generation' => 8,
        'ok' => true,
        'owner' => $owner,
        'receipt_format' => 'wprism-rollback-receipt/v3',
        'receipt_id' => str_repeat('8', 48),
        'receipt_payload_sha256' => $receipt,
        'recovery_ready' => true,
        'resources_inventory_sha256' => hash('sha256', 'verified-plan-resources'),
        'signing_key_id' => 'offline-key-1',
        'state' => 'promoting',
        'target_id' => str_repeat('f', 32),
        'terminal' => false,
    ];
    try {
        VerifiedPromotionAuthority::validate($verifiedWitness, $owner, $artifact, $receipt);
        $check(true, 'full promotion witness accepts the exact v3 deletion-admitting recovery generation');
    } catch (Throwable $failure) {
        $check(false, 'valid full promotion witness was refused (' . $failure->getMessage() . ')');
    }
    $resourcePlan = [
        'code' => ['code_revision' => str_repeat('1', 64)],
        'effects_inventory' => [[
            'effect' => ['id' => 'selected-rebuild-effect'],
            'manifest' => 'fixture',
            'phase' => 'rebuild',
            'source' => 'provider:fixture/selected',
        ]],
        'lifecycle_effects_inventory' => [[
            'effect' => ['id' => 'selected-lifecycle-effect'],
            'manifest' => 'fixture',
            'phase' => 'lifecycle',
            'source' => 'fixture/plugin.php',
        ]],
        'selected_actions' => [[
            'declaration_hash' => hash('sha256', 'selected-action'),
            'index' => 2,
            'manifest' => 'fixture',
        ]],
        'uploads_inventory' => [],
    ];
    $resourceHash = hash('sha256', RollbackControl::canonical([
        'code' => $resourcePlan['code'],
        'effects_inventory' => $resourcePlan['effects_inventory'],
        'selected_actions' => $resourcePlan['selected_actions'],
        'uploads_inventory' => [],
    ]));
    try {
        VerifiedPromotionAuthority::assert_plan_resources(
            $resourcePlan,
            array_replace($verifiedWitness, ['resources_inventory_sha256' => $resourceHash])
        );
        $check(true, 'the fresh target plan re-proves the exact controller-selected action and execution effects');
    } catch (Throwable $failure) {
        $check(false, 'valid plan-bound resource authority was refused (' . $failure->getMessage() . ')');
    }
    $lifecycleResourceHash = hash('sha256', RollbackControl::canonical([
        'code' => $resourcePlan['code'],
        'effects_inventory' => array_merge(
            $resourcePlan['lifecycle_effects_inventory'],
            $resourcePlan['effects_inventory']
        ),
        'selected_actions' => $resourcePlan['selected_actions'],
        'uploads_inventory' => [],
    ]));
    try {
        VerifiedPromotionAuthority::assert_plan_resources(
            $resourcePlan,
            array_replace($verifiedWitness, ['resources_inventory_sha256' => $lifecycleResourceHash])
        );
        $check(true, 'the same fresh plan re-proves a receipt that signed lifecycle effects for an actual code transition');
    } catch (Throwable $failure) {
        $check(false, 'valid lifecycle-bound resource authority was refused (' . $failure->getMessage() . ')');
    }
    $applyCoordinatorSource = (string) file_get_contents(
        "$root/agent/src/Apply/ApplyRequestCoordinator.php"
    );
    $compiledCodeAt = strpos(
        $applyCoordinatorSource,
        "array_replace(\$freshPlan, ['code' => \$compiled->code_descriptor()])"
    );
    $resourceAssertionAt = strpos(
        $applyCoordinatorSource,
        'VerifiedPromotionAuthority::assert_plan_resources('
    );
    $check($compiledCodeAt !== false && $resourceAssertionAt !== false && $compiledCodeAt > $resourceAssertionAt,
        'fresh target resource verification composes the immutable compiled code descriptor with plan-selected effects');
    $expect(
        static fn() => VerifiedPromotionAuthority::assert_plan_resources(
            array_replace($resourcePlan, ['selected_actions' => [[
                'declaration_hash' => hash('sha256', 'substituted-action'),
                'index' => 2,
                'manifest' => 'fixture',
            ]]]),
            array_replace($verifiedWitness, ['resources_inventory_sha256' => $resourceHash])
        ),
        'receipt resources do not match the fresh target plan selection',
        'a substituted fresh-plan action is refused before deletion can enter its writer transaction'
    );
    foreach ([
        'legacy receipt' => ['receipt_format' => 'wprism-rollback-receipt/v2'],
        'unsigned delete intent' => ['allow_deletes' => false],
        'terminal generation' => ['state' => 'committed', 'terminal' => true],
        'released exclusion' => ['exclusion_state' => 'released'],
    ] as $label => $replacement) {
        $expect(
            static fn() => VerifiedPromotionAuthority::validate(
                array_replace($verifiedWitness, $replacement),
                $owner,
                $artifact,
                $receipt
            ),
            'does not match the exact held full-recovery generation',
            "$label cannot authorize full-promotion deletion"
        );
    }
    $first = PromotionLock::begin_scoped($owner, $artifact, $receipt, $scopeHash, $witness, 300);
    $sessionBytes = ScopedPromotionTargetLedger::$values['promotion_session'] ?? '';
    $session = json_decode($sessionBytes, true, 512, JSON_THROW_ON_ERROR);
    $check(
        preg_match('/^ps-[a-f0-9]{32}$/D', (string) ($first['session_id'] ?? '')) === 1,
        'initial scoped begin creates one random ps-* target generation'
    );
    $check(
        ($session['profile'] ?? null) === 'scoped-checkpoint-v1'
            && ($session['scoped_receipt_sha256'] ?? null) === $receipt
            && ($session['scoped_scope_hash'] ?? null) === $scopeHash
            && ($session['scoped_allow_deletes'] ?? null) === false
            && ($session['scoped_generation'] ?? null) === 7
            && ($first['scoped_receipt_sha256'] ?? null) === $receipt,
        'initial scoped begin persists the closed profile and exact signed authority tuple'
    );
    $expect(
        static fn() => PromotionLock::assert_no_unbound_scoped_continuation(),
        'requires its exact signed continuation',
        'receipt-less recovery is refused even when no caller-supplied promotion owner is present'
    );
    $malformedScopedSession = $session;
    unset($malformedScopedSession['profile']);
    $malformedScopedBytes = json_encode($malformedScopedSession, JSON_THROW_ON_ERROR);
    ScopedPromotionTargetLedger::$values['promotion_session'] = $malformedScopedBytes;
    $expect(
        static fn() => PromotionLock::assert_no_unbound_scoped_continuation(),
        'malformed promotion scoped session metadata',
        'a scoped receipt with its profile marker stripped remains a refused receipt-less continuation'
    );
    $check(
        (ScopedPromotionTargetLedger::$values['promotion_session'] ?? '') === $malformedScopedBytes,
        'malformed scoped receipt remains byte-stable while receipt-less recovery is refused'
    );
    ScopedPromotionTargetLedger::$values['promotion_session'] = $sessionBytes;
    // issue #3353 keeps PromotionLock as a compatibility facade; the lease
    // implementation owns the scoped handoff and replacement transaction.
    $lockSource = (string) file_get_contents("$root/agent/src/Promotion/PromotionLease.php");
    $beginScopedOffset = strpos($lockSource, 'public static function begin_scoped');
    $profileAssertionOffset = strpos($lockSource, '/** Prove the live target session');
    $acquireInternalOffset = strpos($lockSource, 'private static function acquire_internal');
    $heartbeatOffset = strpos($lockSource, 'public static function heartbeat');
    $beginScopedSource = substr(
        $lockSource,
        (int) $beginScopedOffset,
        (int) $profileAssertionOffset - (int) $beginScopedOffset
    );
    $acquireInternalSource = substr(
        $lockSource,
        (int) $acquireInternalOffset,
        (int) $heartbeatOffset - (int) $acquireInternalOffset
    );
    $metadataWrite = strpos($acquireInternalSource, '$sessionMetadata');
    $sessionWrite = strpos($acquireInternalSource, 'PromotionSessionJournal::start(');
    $check(
        str_contains($beginScopedSource, 'self::acquire_internal(')
            && str_contains($beginScopedSource, 'self::scoped_session_metadata(')
            && !str_contains($beginScopedSource, '$begun = self::begin(')
            && $metadataWrite !== false
            && $sessionWrite !== false,
        'scoped begin supplies profile/receipt metadata to the first session write rather than decorating a prior ordinary session'
    );
    $replacementStorageOffset = strpos(
        $acquireInternalSource,
        'new NativeDatabaseProfile([$ledgerTable], [$ledgerTable])'
    );
    $replacementTransactionStartOffset = strpos(
        $acquireInternalSource,
        "'scoped ordinary session replacement transaction start',"
    );
    $replacementLockReadOffset = strpos($acquireInternalSource, '$before = self::current();');
    $replacementSessionReadOffset = strpos($acquireInternalSource, '$existingSession = self::current_session(');
    $check(
        $replacementStorageOffset !== false
            && $replacementTransactionStartOffset !== false
            && $replacementLockReadOffset !== false
            && $replacementSessionReadOffset !== false
            && $replacementTransactionStartOffset < $replacementStorageOffset
            && $replacementStorageOffset < $replacementLockReadOffset
            && $replacementLockReadOffset < $replacementSessionReadOffset
            && !str_contains($acquireInternalSource, 'self::assert_transactional_replacement_storage();'),
        'ordinary-session replacement delegates exact read/write storage authority to Db before its handoff reads'
    );
    $second = PromotionLock::begin_scoped($owner, $artifact, $receipt, $scopeHash, $witness, 300);
    $check(
        ($second['session_id'] ?? null) === ($first['session_id'] ?? null)
            && (ScopedPromotionTargetLedger::$values['promotion_session'] ?? '') === $sessionBytes
            && ($second['scoped_receipt_sha256'] ?? null) === $receipt,
        'same owner/artifact/receipt scoped begin recovers the exact generation without rewriting its session receipt'
    );
    $check(
        ($second['phase'] ?? null) === 'scoped-recovery',
        'same-generation retry uses the bounded scoped-recovery continuation rather than a new begin'
    );
    $expect(
        static fn() => PromotionLock::begin_scoped(
            'other-owner', $artifact, $receipt, $scopeHash,
            array_replace($witness, ['owner' => 'other-owner']), 300
        ),
        'different target promotion session',
        'different owner cannot replace a live scoped target generation'
    );
    $expect(
        static fn() => PromotionLock::begin_scoped(
            $owner, str_repeat('b', 64), $receipt, $scopeHash,
            array_replace($witness, ['artifact_hash' => str_repeat('b', 64)]), 300
        ),
        'different target promotion session',
        'different artifact cannot reuse a scoped target generation'
    );
    $expect(
        static fn() => PromotionLock::begin_scoped(
            $owner, $artifact, $otherReceipt, $scopeHash,
            array_replace($witness, ['receipt_payload_sha256' => $otherReceipt]), 300
        ),
        'does not match its external signed receipt',
        'same owner/artifact cannot swap the external signed receipt on retry'
    );
    $check(
        (ScopedPromotionTargetLedger::$values['promotion_session'] ?? '') === $sessionBytes,
        'mismatched scoped begin attempts leave the exact target session receipt unchanged'
    );
    PromotionLock::release($owner, $artifact);

    $initialRacePendingSession = [
        'owner' => 'ordinary-race-owner',
        'artifact_hash' => str_repeat('b', 64),
        'begun_at' => time(),
        'session_id' => 'ps-' . str_repeat('d', 32),
        'pending_state_transition' => [
            'entity' => 'options/core',
            'before_hash' => str_repeat('3', 64),
            'after_hash' => str_repeat('4', 64),
        ],
    ];
    $initialRacePendingBytes = json_encode($initialRacePendingSession, JSON_THROW_ON_ERROR);
    ScopedPromotionTargetLedger::$values = [];
    $initialRacePendingWrites = ScopedPromotionTargetLedger::$promotionSessionWrites;
    $initialRacePendingLockWrites = ScopedPromotionTargetLedger::$promotionLockWrites;
    ScopedPromotionTargetLedger::$injectAfterAbsentPromotionSessionRead = $initialRacePendingBytes;
    $expect(
        static fn() => PromotionLock::begin_scoped($owner, $artifact, $receipt, $scopeHash, $witness, 300),
        'initial begin found a target promotion session after fencing',
        'initial scoped begin refuses an ordinary pending receipt injected after its outer absent-session read'
    );
    $check(
        (ScopedPromotionTargetLedger::$values['promotion_session'] ?? '') === $initialRacePendingBytes
            && !array_key_exists('promotion_lock', ScopedPromotionTargetLedger::$values)
            && ScopedPromotionTargetLedger::$promotionSessionWrites === $initialRacePendingWrites
            && ScopedPromotionTargetLedger::$promotionLockWrites === $initialRacePendingLockWrites
            && ScopedPromotionTargetLedger::$injectAfterAbsentPromotionSessionRead === null,
        'initial scoped pending-race refusal preserves the exact ordinary receipt without a scoped write or lease'
    );

    $initialRaceUnknownSession = [
        'owner' => 'ordinary-race-owner',
        'artifact_hash' => str_repeat('b', 64),
        'begun_at' => time(),
        'future_recovery_receipt' => ['format' => 'future-ordinary-recovery-v1'],
    ];
    $initialRaceUnknownBytes = json_encode($initialRaceUnknownSession, JSON_THROW_ON_ERROR);
    ScopedPromotionTargetLedger::$values = [];
    $initialRaceUnknownWrites = ScopedPromotionTargetLedger::$promotionSessionWrites;
    $initialRaceUnknownLockWrites = ScopedPromotionTargetLedger::$promotionLockWrites;
    ScopedPromotionTargetLedger::$injectAfterAbsentPromotionSessionRead = $initialRaceUnknownBytes;
    $expect(
        static fn() => PromotionLock::begin_scoped($owner, $artifact, $receipt, $scopeHash, $witness, 300),
        'initial begin found a target promotion session after fencing',
        'initial scoped begin refuses an unknown ordinary receipt injected after its outer absent-session read'
    );
    $check(
        (ScopedPromotionTargetLedger::$values['promotion_session'] ?? '') === $initialRaceUnknownBytes
            && !array_key_exists('promotion_lock', ScopedPromotionTargetLedger::$values)
            && ScopedPromotionTargetLedger::$promotionSessionWrites === $initialRaceUnknownWrites
            && ScopedPromotionTargetLedger::$promotionLockWrites === $initialRaceUnknownLockWrites
            && ScopedPromotionTargetLedger::$injectAfterAbsentPromotionSessionRead === null,
        'initial scoped unknown-race refusal preserves the exact ordinary receipt without a scoped write or lease'
    );

    $ordinaryCompletedOwner = 'ordinary-completed-owner';
    $ordinaryCompletedArtifact = str_repeat('b', 64);
    $ordinaryCompletedSessionId = 'ps-' . str_repeat('b', 32);
    $ordinaryCompletedSession = [
        'owner' => $ordinaryCompletedOwner,
        'artifact_hash' => $ordinaryCompletedArtifact,
        'begun_at' => time(),
        'session_id' => $ordinaryCompletedSessionId,
        'lifecycle_phases' => ['retire', 'activate'],
        'state_transition' => [
            'entity' => 'options/core',
            'before_hash' => str_repeat('2', 64),
            'after_hash' => str_repeat('3', 64),
        ],
    ];
    ScopedPromotionTargetLedger::$values = [
        'promotion_session' => json_encode($ordinaryCompletedSession, JSON_THROW_ON_ERROR),
    ];
    $ordinaryCompletedBytes = ScopedPromotionTargetLedger::$values['promotion_session'];
    $ordinaryReplacementLockWrites = ScopedPromotionTargetLedger::$promotionLockWrites;
    ScopedPromotionTargetLedger::$failNextPromotionSessionUpsert = true;
    $expect(
        static fn() => PromotionLock::begin_scoped($owner, $artifact, $receipt, $scopeHash, $witness, 300),
        'injected scoped promotion session upsert failure',
        'replacement session-upsert failure is raised after the provisional lock write'
    );
    $check(
        (ScopedPromotionTargetLedger::$values['promotion_session'] ?? '') === $ordinaryCompletedBytes
            && !array_key_exists('promotion_lock', ScopedPromotionTargetLedger::$values)
            && !ScopedPromotionTargetLedger::transactionOpen()
            && ScopedPromotionTargetLedger::$promotionLockWrites === $ordinaryReplacementLockWrites + 1,
        'replacement session-upsert failure rolls back its provisional lock and preserves the exact ordinary session bytes'
    );

    $terminalReplacementRollbacks = ScopedPromotionTargetLedger::$transactionRollbacks;
    ScopedPromotionTargetLedger::$terminalCommitFailure = true;
    $expect(
        static fn() => PromotionLock::begin_scoped($owner, $artifact, $receipt, $scopeHash, $witness, 300),
        'terminal scoped replacement recovery_required',
        'terminal ordinary-session replacement outcome refuses without autocommit compensation'
    );
    $terminalSession = json_decode(
        (string) (ScopedPromotionTargetLedger::$values['promotion_session'] ?? ''),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $check(
        !ScopedPromotionTargetLedger::transactionOpen()
            && ScopedPromotionTargetLedger::$transactionRollbacks === $terminalReplacementRollbacks
            && ($terminalSession['profile'] ?? null) === 'scoped-checkpoint-v1'
            && array_key_exists('promotion_lock', ScopedPromotionTargetLedger::$values),
        'terminal replacement preserves its physical postimage for exact recovery classification'
    );
    ScopedPromotionTargetLedger::$terminalCommitFailure = false;
    ScopedPromotionTargetLedger::$values = [
        'promotion_session' => $ordinaryCompletedBytes,
    ];
    $ordinaryReclaimed = PromotionLock::begin_scoped(
        $owner, $artifact, $receipt, $scopeHash, $witness, 300
    );
    $ordinaryReclaimedSession = json_decode(
        (string) (ScopedPromotionTargetLedger::$values['promotion_session'] ?? ''),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $ordinaryReclaimedLock = PromotionLock::current();
    $check(
        (ScopedPromotionTargetLedger::$values['promotion_session'] ?? '') !== $ordinaryCompletedBytes
            && preg_match('/^ps-[a-f0-9]{32}$/D', (string) ($ordinaryReclaimed['session_id'] ?? '')) === 1
            && ($ordinaryReclaimed['session_id'] ?? null) !== $ordinaryCompletedSessionId
            && ($ordinaryReclaimedSession['session_id'] ?? null) === ($ordinaryReclaimed['session_id'] ?? null)
            && ($ordinaryReclaimedSession['profile'] ?? null) === 'scoped-checkpoint-v1'
            && ($ordinaryReclaimedSession['scoped_receipt_sha256'] ?? null) === $receipt
            && ($ordinaryReclaimedSession['scoped_scope_hash'] ?? null) === $scopeHash
            && ($ordinaryReclaimedSession['scoped_receipt_id'] ?? null) === $witness['receipt_id']
            && ($ordinaryReclaimedSession['scoped_generation'] ?? null) === $witness['generation']
            && ($ordinaryReclaimedSession['scoped_target_id'] ?? null) === $witness['target_id']
            && ($ordinaryReclaimedSession['scoped_signing_key_id'] ?? null) === $witness['signing_key_id']
            && ($ordinaryReclaimedSession['scoped_allow_deletes'] ?? null) === false
            && ($ordinaryReclaimedLock['owner'] ?? null) === $owner
            && ($ordinaryReclaimedLock['artifact_hash'] ?? null) === $artifact,
        'stale completed ordinary session without a lease is atomically replaced by one fresh receipt-bound scoped generation'
    );
    $ordinaryReclaimedBytes = ScopedPromotionTargetLedger::$values['promotion_session'] ?? '';
    $ordinaryReclaimedRetry = PromotionLock::begin_scoped(
        $owner, $artifact, $receipt, $scopeHash, $witness, 300
    );
    $check(
        ($ordinaryReclaimedRetry['session_id'] ?? null) === ($ordinaryReclaimed['session_id'] ?? null)
            && (ScopedPromotionTargetLedger::$values['promotion_session'] ?? '') === $ordinaryReclaimedBytes,
        'exact scoped retry after a rolled-back replacement failure recovers the same fresh generation without rotation'
    );
    PromotionLock::release($owner, $artifact);

    $ordinaryFutureRecoveryReceipt = $ordinaryCompletedSession;
    $ordinaryFutureRecoveryReceipt['future_recovery_receipt'] = [
        'format' => 'future-ordinary-recovery-v1',
    ];
    ScopedPromotionTargetLedger::$values = [
        'promotion_session' => json_encode($ordinaryFutureRecoveryReceipt, JSON_THROW_ON_ERROR),
    ];
    $ordinaryFutureRecoveryReceiptBytes = ScopedPromotionTargetLedger::$values['promotion_session'];
    $expect(
        static fn() => PromotionLock::begin_scoped($owner, $artifact, $receipt, $scopeHash, $witness, 300),
        'unknown ordinary promotion session recovery field',
        'ordinary future recovery receipt refuses scoped replacement'
    );
    $check(
        (ScopedPromotionTargetLedger::$values['promotion_session'] ?? '') === $ordinaryFutureRecoveryReceiptBytes
            && !array_key_exists('promotion_lock', ScopedPromotionTargetLedger::$values),
        'ordinary future recovery receipt refusal leaves the retained session and absent lock untouched'
    );

    $ordinaryPendingTransition = $ordinaryCompletedSession;
    $ordinaryPendingTransition['pending_state_transition'] = [
        'entity' => 'options/core',
        'before_hash' => str_repeat('3', 64),
        'after_hash' => str_repeat('4', 64),
    ];
    ScopedPromotionTargetLedger::$values = [
        'promotion_session' => json_encode($ordinaryPendingTransition, JSON_THROW_ON_ERROR),
    ];
    $ordinaryPendingTransitionBytes = ScopedPromotionTargetLedger::$values['promotion_session'];
    $expect(
        static fn() => PromotionLock::begin_scoped($owner, $artifact, $receipt, $scopeHash, $witness, 300),
        'pending lifecycle state transition',
        'ordinary pending lifecycle handoff refuses scoped replacement'
    );
    $check(
        (ScopedPromotionTargetLedger::$values['promotion_session'] ?? '') === $ordinaryPendingTransitionBytes
            && !array_key_exists('promotion_lock', ScopedPromotionTargetLedger::$values),
        'ordinary pending lifecycle handoff refusal leaves the retained session and absent lock untouched'
    );

    $ordinaryRetireOnly = $ordinaryCompletedSession;
    $ordinaryRetireOnly['lifecycle_phases'] = ['retire'];
    ScopedPromotionTargetLedger::$values = [
        'promotion_session' => json_encode($ordinaryRetireOnly, JSON_THROW_ON_ERROR),
    ];
    $ordinaryRetireOnlyBytes = ScopedPromotionTargetLedger::$values['promotion_session'];
    $expect(
        static fn() => PromotionLock::begin_scoped($owner, $artifact, $receipt, $scopeHash, $witness, 300),
        'incomplete lifecycle phase receipt',
        'ordinary retirement-only lifecycle receipt refuses scoped replacement'
    );
    $check(
        (ScopedPromotionTargetLedger::$values['promotion_session'] ?? '') === $ordinaryRetireOnlyBytes
            && !array_key_exists('promotion_lock', ScopedPromotionTargetLedger::$values),
        'ordinary retirement-only lifecycle refusal leaves the retained session and absent lock untouched'
    );

    $ordinaryMalformedPhases = $ordinaryCompletedSession;
    $ordinaryMalformedPhases['lifecycle_phases'] = ['retire', 'activate', 'unexpected'];
    ScopedPromotionTargetLedger::$values = [
        'promotion_session' => json_encode($ordinaryMalformedPhases, JSON_THROW_ON_ERROR),
    ];
    $ordinaryMalformedPhasesBytes = ScopedPromotionTargetLedger::$values['promotion_session'];
    $expect(
        static fn() => PromotionLock::begin_scoped($owner, $artifact, $receipt, $scopeHash, $witness, 300),
        'malformed completed lifecycle phase receipt',
        'ordinary malformed lifecycle phase receipt refuses scoped replacement'
    );
    $check(
        (ScopedPromotionTargetLedger::$values['promotion_session'] ?? '') === $ordinaryMalformedPhasesBytes
            && !array_key_exists('promotion_lock', ScopedPromotionTargetLedger::$values),
        'ordinary malformed lifecycle phase refusal leaves the retained session and absent lock untouched'
    );

    $ordinaryMalformedSessionId = $ordinaryCompletedSession;
    $ordinaryMalformedSessionId['session_id'] = 'ordinary-session-id-without-ps-prefix';
    ScopedPromotionTargetLedger::$values = [
        'promotion_session' => json_encode($ordinaryMalformedSessionId, JSON_THROW_ON_ERROR),
    ];
    $ordinaryMalformedSessionIdBytes = ScopedPromotionTargetLedger::$values['promotion_session'];
    $expect(
        static fn() => PromotionLock::begin_scoped($owner, $artifact, $receipt, $scopeHash, $witness, 300),
        'malformed promotion session generation',
        'ordinary malformed session generation refuses scoped replacement'
    );
    $check(
        (ScopedPromotionTargetLedger::$values['promotion_session'] ?? '') === $ordinaryMalformedSessionIdBytes
            && !array_key_exists('promotion_lock', ScopedPromotionTargetLedger::$values),
        'ordinary malformed session-generation refusal leaves the retained session and absent lock untouched'
    );
    $expect(
        static fn() => PromotionLock::begin(
            'ordinary-new-owner',
            str_repeat('1', 64),
            300
        ),
        'malformed promotion session generation',
        'ordinary begin refuses a malformed retained session before acquiring a replacement lease'
    );
    $check(
        (ScopedPromotionTargetLedger::$values['promotion_session'] ?? '') === $ordinaryMalformedSessionIdBytes
            && !array_key_exists('promotion_lock', ScopedPromotionTargetLedger::$values),
        'ordinary malformed-session refusal preserves exact bytes and absent lease'
    );

    $ordinaryLegacySessionId = $ordinaryCompletedSession;
    unset($ordinaryLegacySessionId['session_id']);
    ScopedPromotionTargetLedger::$values = [
        'promotion_session' => json_encode($ordinaryLegacySessionId, JSON_THROW_ON_ERROR),
    ];
    $ordinaryLegacySessionIdBytes = ScopedPromotionTargetLedger::$values['promotion_session'];
    $ordinaryLegacyReclaimed = PromotionLock::begin_scoped(
        $owner, $artifact, $receipt, $scopeHash, $witness, 300
    );
    $ordinaryLegacyReclaimedSession = json_decode(
        (string) (ScopedPromotionTargetLedger::$values['promotion_session'] ?? ''),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $check(
        (ScopedPromotionTargetLedger::$values['promotion_session'] ?? '') !== $ordinaryLegacySessionIdBytes
            && preg_match('/^ps-[a-f0-9]{32}$/D', (string) ($ordinaryLegacyReclaimed['session_id'] ?? '')) === 1
            && ($ordinaryLegacyReclaimedSession['profile'] ?? null) === 'scoped-checkpoint-v1',
        'an absent legacy session generation remains reclaimable as one fresh scoped generation'
    );
    PromotionLock::release($owner, $artifact);

    foreach ([
        'empty string' => '',
        'null' => null,
        'false' => false,
        'zero' => 0,
    ] as $invalidSessionIdType => $invalidSessionId) {
        $ordinaryNonStringSessionId = $ordinaryCompletedSession;
        $ordinaryNonStringSessionId['session_id'] = $invalidSessionId;
        ScopedPromotionTargetLedger::$values = [
            'promotion_session' => json_encode($ordinaryNonStringSessionId, JSON_THROW_ON_ERROR),
        ];
        $ordinaryNonStringSessionIdBytes = ScopedPromotionTargetLedger::$values['promotion_session'];
        $expect(
            static fn() => PromotionLock::begin_scoped($owner, $artifact, $receipt, $scopeHash, $witness, 300),
            'malformed promotion session generation',
            "ordinary $invalidSessionIdType session generation refuses scoped replacement"
        );
        $check(
            (ScopedPromotionTargetLedger::$values['promotion_session'] ?? '') === $ordinaryNonStringSessionIdBytes
                && !array_key_exists('promotion_lock', ScopedPromotionTargetLedger::$values),
            "ordinary $invalidSessionIdType session-generation refusal leaves the retained session and absent lock untouched"
        );
    }

    $ordinaryMalformedTransition = $ordinaryCompletedSession;
    $ordinaryMalformedTransition['state_transition'] = ['entity' => 'options/core'];
    ScopedPromotionTargetLedger::$values = [
        'promotion_session' => json_encode($ordinaryMalformedTransition, JSON_THROW_ON_ERROR),
    ];
    $ordinaryMalformedTransitionBytes = ScopedPromotionTargetLedger::$values['promotion_session'];
    $expect(
        static fn() => PromotionLock::begin_scoped($owner, $artifact, $receipt, $scopeHash, $witness, 300),
        'malformed completed lifecycle state transition',
        'ordinary malformed completed state transition refuses scoped replacement'
    );
    $check(
        (ScopedPromotionTargetLedger::$values['promotion_session'] ?? '') === $ordinaryMalformedTransitionBytes
            && !array_key_exists('promotion_lock', ScopedPromotionTargetLedger::$values),
        'ordinary malformed completed state-transition refusal leaves the retained session and absent lock untouched'
    );

    $ordinaryAttempt = [
        'owner' => 'ordinary-ambiguous-owner',
        'artifact_hash' => $ordinaryCompletedArtifact,
        'begun_at' => time(),
        'session_id' => 'ps-' . str_repeat('c', 32),
        'lifecycle_attempt' => [
            'entity' => 'options/core',
            'phase' => 'activate',
            'before_hash' => str_repeat('1', 64),
        ],
    ];
    ScopedPromotionTargetLedger::$values = [
        'promotion_session' => json_encode($ordinaryAttempt, JSON_THROW_ON_ERROR),
    ];
    $ordinaryAttemptBytes = ScopedPromotionTargetLedger::$values['promotion_session'];
    $expect(
        static fn() => PromotionLock::begin_scoped($owner, $artifact, $receipt, $scopeHash, $witness, 300),
        'unresolved lifecycle attempt',
        'ordinary lifecycle ambiguity refuses scoped replacement'
    );
    $check(
        (ScopedPromotionTargetLedger::$values['promotion_session'] ?? '') === $ordinaryAttemptBytes
            && !array_key_exists('promotion_lock', ScopedPromotionTargetLedger::$values),
        'ordinary lifecycle-attempt refusal leaves the retained session and absent lock untouched'
    );

    $ordinaryMalformedAttempt = $ordinaryAttempt;
    $ordinaryMalformedAttempt['lifecycle_attempt'] = ['phase' => 'activate'];
    ScopedPromotionTargetLedger::$values = [
        'promotion_session' => json_encode($ordinaryMalformedAttempt, JSON_THROW_ON_ERROR),
    ];
    $ordinaryMalformedAttemptBytes = ScopedPromotionTargetLedger::$values['promotion_session'];
    $expect(
        static fn() => PromotionLock::begin_scoped($owner, $artifact, $receipt, $scopeHash, $witness, 300),
        'malformed unresolved lifecycle attempt',
        'malformed ordinary lifecycle ambiguity refuses scoped replacement'
    );
    $check(
        (ScopedPromotionTargetLedger::$values['promotion_session'] ?? '') === $ordinaryMalformedAttemptBytes
            && !array_key_exists('promotion_lock', ScopedPromotionTargetLedger::$values),
        'malformed ordinary lifecycle-attempt refusal leaves the retained session and absent lock untouched'
    );

    ScopedPromotionTargetLedger::$values = [];
    $ordinaryLiveOwner = 'ordinary-live-owner';
    $ordinaryLiveArtifact = str_repeat('d', 64);
    PromotionLock::begin($ordinaryLiveOwner, $ordinaryLiveArtifact, 300);
    $ordinaryLiveSessionBytes = ScopedPromotionTargetLedger::$values['promotion_session'] ?? '';
    $ordinaryLiveLockBytes = ScopedPromotionTargetLedger::$values['promotion_lock'] ?? '';
    $expect(
        static fn() => PromotionLock::begin_scoped($owner, $artifact, $receipt, $scopeHash, $witness, 300),
        'live ordinary target promotion lock',
        'live ordinary promotion lock refuses scoped replacement'
    );
    $check(
        (ScopedPromotionTargetLedger::$values['promotion_session'] ?? '') === $ordinaryLiveSessionBytes
            && (ScopedPromotionTargetLedger::$values['promotion_lock'] ?? '') === $ordinaryLiveLockBytes,
        'live ordinary lock refusal leaves the exact ordinary session and lease untouched'
    );
    PromotionLock::release($ordinaryLiveOwner, $ordinaryLiveArtifact);

    // Simulate the remaining initial interruption: the target lock was
    // written, but process loss occurred before any promotion_session row.
    // The exact caller must be able to fill that single atomic session row.
    $now = time();
    ScopedPromotionTargetLedger::$values = [
        'promotion_lock' => json_encode([
            'owner' => $owner,
            'artifact_hash' => $artifact,
            'phase' => 'checkpoint',
            'acquired_at' => $now,
            'expires_at' => $now + 300,
        ], JSON_THROW_ON_ERROR),
    ];
    $crashRetry = PromotionLock::begin_scoped($owner, $artifact, $receipt, $scopeHash, $witness, 300);
    $crashSession = json_decode(
        (string) (ScopedPromotionTargetLedger::$values['promotion_session'] ?? ''),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $check(
        preg_match('/^ps-[a-f0-9]{32}$/D', (string) ($crashRetry['session_id'] ?? '')) === 1
            && ($crashSession['profile'] ?? null) === 'scoped-checkpoint-v1'
            && ($crashSession['scoped_receipt_sha256'] ?? null) === $receipt,
        'lock-without-session crash retry publishes the first scoped profile session with the exact receipt'
    );
    $expect(
        static fn() => PromotionLock::complete_scoped($owner, $artifact, $receipt, $scopeHash, $witness),
        'requires its target lease to be absent',
        'scoped completion refuses while the exact target lease is still live'
    );
    $expect(
        static fn() => PromotionLock::begin('ordinary-owner', $artifact, 300),
        'externally checkpointed scoped promotion session',
        'ordinary promotion cannot begin while a scoped profile session is retained'
    );
    PromotionLock::release($owner, $artifact);
    $expect(
        static fn() => PromotionLock::complete_scoped(
            $owner, $artifact, $otherReceipt, $scopeHash,
            array_replace($witness, ['receipt_payload_sha256' => $otherReceipt])
        ),
        'does not match its external signed receipt',
        'scoped completion refuses a mismatched receipt and retains the target session'
    );
    $committedWitness = array_replace($witness, ['state' => 'committed', 'terminal' => true]);
    $completed = PromotionLock::complete_scoped(
        $owner, $artifact, $receipt, $scopeHash, $committedWitness
    );
    $check(
        ($completed['released'] ?? false) === true
            && ($completed['already_absent'] ?? true) === false
            && !array_key_exists('promotion_session', ScopedPromotionTargetLedger::$values),
        'exact scoped completion removes the retained scoped-profile target session after lease release'
    );
    $completedReplay = PromotionLock::complete_scoped(
        $owner, $artifact, $receipt, $scopeHash, $committedWitness
    );
    $check(
        ($completedReplay['released'] ?? true) === false
            && ($completedReplay['already_absent'] ?? false) === true,
        'exact scoped completion is idempotent after a lost completion response'
    );

    // Restore an exact scoped profile session for Apply's pre-terminal gate.
    ScopedPromotionTargetLedger::$values = [];
    PromotionLock::begin_scoped($owner, $artifact, $receipt, $scopeHash, $witness, 300);

    $applyReflection = new ReflectionClass(\WPrism\ApplyRequestCoordinator::class);
    $requestGate = $applyReflection->getMethod('assert_scoped_promotion_request');
    $validScopedOpts = [
        'scope_request' => ['format' => 'wprism-scope-request/v1', 'scope_hash' => $scopeHash],
        'promotion_owner' => $owner,
        'artifact_hash' => $artifact,
        'scoped_promotion_receipt' => $receipt,
    ];
    $expect(
        static fn() => $requestGate->invoke(null, [
            'scope_request' => ['format' => 'wprism-scope-request/v1', 'scope_hash' => $scopeHash],
            'promotion_owner' => $owner,
            'artifact_hash' => $artifact,
        ], true),
        'requires its exact signed continuation',
        'receipt omission cannot fall through to generic full or scoped continuation'
    );
    try {
        $requestGate->invoke(null, $validScopedOpts, true, $witness);
        $check(true, 'structured scoped-promotion option accepts compact scope, owner, and exact receipt hash');
    } catch (Throwable $failure) {
        $check(false, 'valid structured scoped-promotion option was refused (' . $failure->getMessage() . ')');
    }
    $expect(
        static fn() => $requestGate->invoke(
            null,
            $validScopedOpts + ['with_deletes' => true],
            true,
            $witness
        ),
        'deletion authority mismatch',
        'a no-delete signed generation cannot be widened by target --with-deletes'
    );
    $expect(
        static fn() => $requestGate->invoke(null, [
            'promotion_owner' => $owner,
            'scoped_promotion_receipt' => $receipt,
        ], false, $witness),
        'invalid scoped promotion continuation',
        'receipt-only CLI injection is refused without a compact scope request'
    );
    $expect(
        static fn() => $requestGate->invoke(null, [
            'scope_request' => ['format' => 'wprism-scope-request/v1'],
            'scoped_promotion_receipt' => $receipt,
        ], true, $witness),
        'invalid scoped promotion continuation',
        'scoped receipt is refused without the host continuation owner'
    );
    $expect(
        static fn() => $requestGate->invoke(null, [
            'scope_request' => ['format' => 'wprism-scope-request/v1'],
            'promotion_owner' => $owner,
            'scoped_promotion_receipt' => 'not-a-sha256',
        ], true, $witness),
        'invalid scoped promotion continuation',
        'malformed scoped receipt identity is refused before mutation'
    );
    $expect(
        static fn() => $requestGate->invoke(
            null,
            array_replace($validScopedOpts, ['scoped_promotion_receipt' => $otherReceipt]),
            true,
            array_replace($witness, ['receipt_payload_sha256' => $otherReceipt])
        ),
        'does not match its external signed receipt',
        'Apply proves the signed receipt against the target profile before terminal replay'
    );
    $expect(
        static fn() => $requestGate->invoke(
            null,
            $validScopedOpts + ['force_theirs' => true],
            true,
            $witness
        ),
        'scoped promotion force override refused',
        'structured scoped promotion refuses every widening force flag'
    );

    $cliSource = (string) file_get_contents("$root/agent/src/Command/Cli.php");
    $applySource = (string) file_get_contents("$root/agent/src/Apply/ApplyRequestCoordinator.php");
    $preparationSource = (string) file_get_contents("$root/agent/src/Apply/ApplyPreparationCoordinator.php");
    $nativeRebuildSource = (string) file_get_contents("$root/agent/src/Rebuild/NativeRebuildExecutor.php");
    $cliBeginStart = strpos($cliSource, 'public function promotion_begin_scoped');
    $cliBeginEnd = strpos($cliSource, 'public function promotion_complete_scoped');
    $cliBeginSource = substr($cliSource, (int) $cliBeginStart, (int) $cliBeginEnd - (int) $cliBeginStart);
    $check(
        str_contains($cliBeginSource, 'ScopedPromotionAuthority::require_installed(')
            && str_contains($cliBeginSource, "['promoting', 'committed']")
            && strpos($cliBeginSource, 'ScopedPromotionAuthority::require_installed(')
                < strpos($cliBeginSource, 'Ledger::ensure();')
            && str_contains($cliSource, "'scoped_promotion_receipt' => \$assoc['scoped-promotion-receipt'] ?? ''"),
        'CLI verifies the adoption-pinned signed witness before begin can ensure ledger schema'
    );
    $authoritySource = (string) file_get_contents("$root/agent/src/Promotion/ScopedPromotionAuthority.php");
    $check(
        str_contains($authoritySource, '& 0777) !== 0600')
            && str_contains($authoritySource, 'scoped promotion control configuration is not protected mode 0600'),
        'target witness refuses an installed trust-root file whose protected mode changed'
    );
    $check(
        str_contains($cliSource, '@subcommand promotion-complete-scoped')
            && str_contains($cliSource, "['committed']")
            && str_contains($applySource, "['promoting']")
            && substr_count($applySource . $preparationSource, 'ScopedPromotionAuthority::require_installed(') >= 3,
        'begin/apply/pre-write/complete all re-prove the fixed external authority state'
    );
    $check(
        str_contains($applySource, '$currentGeneration <= $archivedGeneration')
            && str_contains($applySource, 'external scoped terminal generation binding mismatch'),
        'terminal replacement requires a strictly newer signed external generation'
    );
    $recoveringOffset = strpos($applySource, '$recoveringScopedSession = $scoped');
    $unboundRecoveryOffset = strpos(
        $applySource,
        'PromotionLock::assert_no_unbound_scoped_continuation();',
        (int) $recoveringOffset
    );
    $recoverSessionOffset = strpos(
        $applySource,
        'PromotionLock::recover_session(',
        (int) $recoveringOffset
    );
    $check(
        is_int($recoveringOffset)
            && is_int($unboundRecoveryOffset)
            && is_int($recoverSessionOffset)
            && $recoveringOffset < $unboundRecoveryOffset
            && $unboundRecoveryOffset < $recoverSessionOffset,
        'stored scoped-apply recovery cannot derive a scoped profile lease before the exact receipt witness guard'
    );
    $check(
        str_contains($cliSource, "&& !array_key_exists('scope-request-b64', \$assoc)")
            && str_contains($cliSource, 'direct scope contract cannot enter scoped promotion'),
        'CLI structurally refuses a receipt-bearing direct local contract unless the compact orchestrator wire is present'
    );
    $cli = new \WPrism\Cli();
    $expect(
        static fn() => $cli->promotion_begin_scoped([], [
            'promotion-owner' => $owner,
            'artifact-hash' => $artifact,
            'scoped-promotion-receipt' => $receipt,
            'scope-hash' => $scopeHash,
        ]),
        'scoped promotion control configuration is absent or unsafe',
        'a forged direct begin is refused by the adoption-pinned witness before ledger ensure'
    );
    $expect(
        static fn() => $cli->apply([], [
            'repo' => '/never-read-before-provenance-gate',
            'promotion-owner' => $owner,
            'artifact-hash' => $artifact,
            'scoped-promotion-receipt' => $receipt,
        ]),
        'direct scope contract cannot enter scoped promotion',
        'receipt-bearing direct agent apply is refused before compilation or target mutation without compact wire provenance'
    );

    $beginArgs = CodeDeploy::beginScopedArgs($owner, $artifact, $receipt, $scopeHash);
    $check(
        in_array('wprism', $beginArgs, true)
            && in_array('promotion-begin-scoped', $beginArgs, true)
            && in_array('--promotion-owner=' . $owner, $beginArgs, true)
            && in_array('--artifact-hash=' . $artifact, $beginArgs, true)
            && in_array('--scoped-promotion-receipt=' . $receipt, $beginArgs, true)
            && in_array('--scope-hash=' . $scopeHash, $beginArgs, true)
            && in_array('--format=json', $beginArgs, true)
            && !array_filter($beginArgs, static fn(string $arg): bool => str_starts_with($arg, '--repo=')),
        'CodeDeploy scoped handoff emits a control-plane JSON command bound to owner, artifact, and signed receipt'
    );
    $check(
        count(array_filter($beginArgs, static fn(string $arg): bool => str_starts_with($arg, '--exec='))) === 1
            && in_array('--skip-plugins', $beginArgs, true)
            && in_array('--skip-themes', $beginArgs, true),
        'CodeDeploy scoped handoff remains reachable through the isolated control-plane bootstrap'
    );
    $completeArgs = CodeDeploy::completeScopedArgs($owner, $artifact, $receipt, $scopeHash);
    $check(
        in_array('wprism', $completeArgs, true)
            && in_array('promotion-complete-scoped', $completeArgs, true)
            && in_array('--promotion-owner=' . $owner, $completeArgs, true)
            && in_array('--artifact-hash=' . $artifact, $completeArgs, true)
            && in_array('--scoped-promotion-receipt=' . $receipt, $completeArgs, true)
            && in_array('--scope-hash=' . $scopeHash, $completeArgs, true)
            && in_array('--format=json', $completeArgs, true)
            && !array_filter($completeArgs, static fn(string $arg): bool => str_starts_with($arg, '--repo=')),
        'CodeDeploy scoped completion emits the exact receipt-bound control-plane cleanup command'
    );

    PromotionLock::release($owner, $artifact);
    ScopedPromotionTargetLedger::$values = [
        'promotion_session' => json_encode([
            'owner' => $owner,
            'artifact_hash' => $artifact,
            'begun_at' => time(),
            'session_id' => 'ps-' . str_repeat('e', 32),
        ], JSON_THROW_ON_ERROR),
    ];
    $expect(
        static fn() => $requestGate->invoke(null, $validScopedOpts, true, $witness),
        'does not match its external signed receipt',
        'Apply rejects ordinary-profile session replay before it can return a terminal scoped result'
    );

    $apply = null;
    $selectionState = (object) ['actions' => []];
    $selectedActions = new class($selectionState) {
        public function __construct(private readonly object $state) {}
        public function setValue(mixed $_, array $actions): void { $this->state->actions = $actions; }
    };
    $selectionGate = new class($selectionState) {
        public function __construct(private readonly object $state) {}
        public function invoke(mixed $_, array $work, array $deletions, array $tree): void {
            \WPrism\RebuildActionNegotiator::assert_scoped_promotion_selection(
                $this->state->actions,
                $work,
                $deletions,
                $tree
            );
        }
    };
    $taxonomyGate = new class() {
        public function invoke(mixed $_, array $work, array $tree, array $deletions): bool {
            return \WPrism\NativeRebuildExecutor::needs_taxonomy_recount($work, $tree, $deletions);
        }
    };
    $dbContainedTree = [
        'option-uuid' => ['type' => 'option'],
        'table-uuid' => ['type' => 'table'],
        'sidebar-uuid' => ['type' => 'sidebar'],
        'user-meta-uuid' => ['type' => 'user_meta'],
    ];
    $dbContainedWork = array_map(
        static fn(string $uuid): array => ['uuid' => $uuid],
        array_keys($dbContainedTree)
    );
    $selectedActions->setValue($apply, []);
    try {
        $selectionGate->invoke($apply, $dbContainedWork, [
            ['deletion_kind' => 'option'],
            ['deletion_kind' => 'options'],
            ['deletion_kind' => 'table'],
        ], $dbContainedTree);
        $check(true, 'checkpoint-safe option/table/sidebar/user-meta work and option/table tombstones are accepted');
    } catch (Throwable $failure) {
        $check(false, 'checkpoint-safe scoped selection was refused (' . $failure->getMessage() . ')');
    }
    foreach (['post', 'term', 'menu'] as $unsupportedType) {
        $expect(
            static fn() => $selectionGate->invoke(
                $apply,
                [['uuid' => 'unsupported']],
                [],
                ['unsupported' => ['type' => $unsupportedType]]
            ),
            'unsupported derived effects',
            "$unsupportedType work is refused from the checkpoint-only scoped-promotion profile"
        );
    }
    $expect(
        static fn() => $selectionGate->invoke($apply, [['uuid' => 'missing']], [], []),
        'unsupported derived effects',
        'unknown scoped work identity is refused rather than treated as DB-contained'
    );
    $selectedActions->setValue($apply, [['manifest' => 'core', 'index' => 0]]);
    $expect(
        static fn() => $selectionGate->invoke($apply, [], [], []),
        'external effect refused',
        'any selected provider/native effect is refused from the initial scoped-promotion profile'
    );
    $selectedActions->setValue($apply, []);
    $expect(
        static fn() => $selectionGate->invoke($apply, [], [['deletion_kind' => 'post']], []),
        'unsupported deletion effects',
        'post tombstones are refused from the checkpoint-only scoped-promotion profile'
    );
    $check(
        $taxonomyGate->invoke($apply, $dbContainedWork, $dbContainedTree, [
            ['deletion_kind' => 'option'], ['deletion_kind' => 'table'],
        ]) === false,
        'option/table/sidebar/user-meta scoped work skips taxonomy callbacks'
    );
    $check(
        str_contains($nativeRebuildSource, '$needsTaxonomyRecount = !$suppressExternalEffects')
            && str_contains($applySource, 'scopedCoreComplete: $scopedCoreComplete === null'),
        'taxonomy callback suppression is explicit to scoped promotion and preserves ordinary scoped apply behavior'
    );
    foreach (['post', 'term', 'menu'] as $taxonomyType) {
        $check(
            $taxonomyGate->invoke(
                $apply,
                [['uuid' => 'taxonomy-sensitive']],
                ['taxonomy-sensitive' => ['type' => $taxonomyType]],
                []
            ) === true,
            "$taxonomyType work still requires a taxonomy recount"
        );
    }
    $check(
        $taxonomyGate->invoke($apply, [], [], [['deletion_kind' => 'term']]) === true,
        'taxonomy-sensitive tombstones still require a taxonomy recount'
    );

    $applySource = (string) file_get_contents("$root/agent/src/Apply/ApplyRequestCoordinator.php");
    $actionDispatcherSource = (string) file_get_contents("$root/agent/src/Rebuild/RebuildActionDispatcher.php");
    $check(
        str_contains($applySource, 'scopedCoreComplete: $scopedCoreComplete === null')
            && preg_match('/if \(\$selectedActions !== \[\]\) \{.{0,320}wp_cache_flush\(\)/s', $actionDispatcherSource) === 1,
        'the initial profile admits only derived cache eviction: action-free scoped work cannot dispatch a pre-action cache/effect path'
    );

    if ($failures !== 0) {
        fwrite(STDERR, "FAIL: $failures scoped-promotion target assertion(s) failed after $checks checks\n");
        exit(1);
    }
    echo "scoped promotion target regression passed ($checks checks)\n";
}

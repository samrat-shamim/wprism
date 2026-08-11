<?php
declare(strict_types=1);

/**
 * Offline target-handoff regression for DUO-3344 scoped promotion.
 *
 * This deliberately supplies only PromotionLock's Ledger/Db seam: the
 * random target generation plus its signed external receipt must survive an
 * exact host retry, while a mismatched, ordinary-profile, or pre-random
 * session record must never be silently replaced.
 * The Apply checks use reflection because these are pre-mutation gates; no
 * WordPress bootstrap, database, cache backend, or Docker target is needed
 * to prove their closed selection vocabulary.
 */

namespace Duo {
    /** @internal Test-only in-memory substitute for the target kv seam. */
    final class ScopedPromotionTargetLedger {
        /** @var array<string,string> */
        public static array $values = [];
    }

    // PromotionLock refers to these names directly. This file loads it before
    // the production Ledger/Db implementation so its storage contract, rather
    // than a WordPress database, is the exercised dependency.
    final class Ledger {
        public static function kv_get(string $key): ?string {
            return ScopedPromotionTargetLedger::$values[$key] ?? null;
        }

        public static function kv_set(string $key, string $value): void {
            ScopedPromotionTargetLedger::$values[$key] = $value;
        }
    }

    final class Db {
        public static function query(string $sql, string $context): int {
            return $GLOBALS['wpdb']->execute($sql);
        }
    }

    function wp_json_encode(mixed $value): string {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}

namespace {
    use Duo\Apply;
    use Duo\CommandRefusalException;
    use Duo\PromotionLock;
    use Duo\ScopedPromotionTargetLedger;
    use Duo\Orchestrator\CodeDeploy;

    /**
     * Enough of wpdb's promotion-lock surface to drive the real SQL-facing
     * code. prepare() keeps values out of SQL parsing so the fake is a narrow
     * protocol seam, not a second implementation of WordPress escaping.
     */
    final class ScopedPromotionTargetFakeWpdb {
        public string $prefix = 'wp_';
        public string $dbname = 'duo_scoped_promotion_target_test';
        private int $connection = 4401;
        private bool $fenceHeld = false;

        public function prepare(string $query, mixed ...$args): string {
            return 'duo-test-sql:' . base64_encode(serialize([$query, $args]));
        }

        public function get_var(string $query): string|false|null {
            if ($query === 'SELECT CONNECTION_ID()') {
                return (string) $this->connection;
            }
            [$template] = $this->decode($query);
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
                    ScopedPromotionTargetLedger::$values[(string) $key] = (string) $encoded;
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
                    unset(ScopedPromotionTargetLedger::$values[(string) $key]);
                    return 1;
                }
                return 0;
            }
            if (str_contains($template, 'INSERT IGNORE INTO')) {
                [$key, $encoded] = $args;
                if (array_key_exists((string) $key, ScopedPromotionTargetLedger::$values)) {
                    return 0;
                }
                ScopedPromotionTargetLedger::$values[(string) $key] = (string) $encoded;
                return 1;
            }
            if (str_contains($template, "JSON_EXTRACT(v, '$.profile')")) {
                [$key, $owner, $artifact, $profile, $receipt] = $args;
                $current = $this->current((string) $key);
                if ($current !== null
                    && (string) $current['owner'] === (string) $owner
                    && (string) $current['artifact_hash'] === (string) $artifact
                    && (string) ($current['profile'] ?? '') === (string) $profile
                    && (string) ($current['scoped_receipt_sha256'] ?? '') === (string) $receipt) {
                    unset(ScopedPromotionTargetLedger::$values[(string) $key]);
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
                    unset(ScopedPromotionTargetLedger::$values[(string) $key]);
                    return 1;
                }
                return 0;
            }
            throw new RuntimeException('unexpected promotion-lock mutation query');
        }

        /** @return array{0:string,1:list<mixed>} */
        private function decode(string $query): array {
            if (!str_starts_with($query, 'duo-test-sql:')) {
                throw new RuntimeException('unexpected unprepared promotion-lock query');
            }
            $decoded = unserialize(base64_decode(substr($query, strlen('duo-test-sql:'))), [
                'allowed_classes' => false,
            ]);
            if (!is_array($decoded) || !is_string($decoded[0] ?? null) || !is_array($decoded[1] ?? null)) {
                throw new RuntimeException('malformed fake promotion-lock query');
            }
            return [$decoded[0], $decoded[1]];
        }

        /** @return array<string,mixed>|null */
        private function current(string $key): ?array {
            $raw = ScopedPromotionTargetLedger::$values[$key] ?? null;
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

    $root = dirname(__DIR__, 2);
    $GLOBALS['wpdb'] = new ScopedPromotionTargetFakeWpdb();
    require_once "$root/agent/src/PromotionLock.php";
    require_once "$root/agent/src/Apply.php";
    require_once "$root/agent/src/Cli.php";
    require_once "$root/cli/src/CodeDeploy.php";

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
    $first = PromotionLock::begin_scoped($owner, $artifact, $receipt, 300);
    $sessionBytes = ScopedPromotionTargetLedger::$values['promotion_session'] ?? '';
    $session = json_decode($sessionBytes, true, 512, JSON_THROW_ON_ERROR);
    $check(
        preg_match('/^ps-[a-f0-9]{32}$/D', (string) ($first['session_id'] ?? '')) === 1,
        'initial scoped begin creates one random ps-* target generation'
    );
    $check(
        ($session['profile'] ?? null) === 'scoped-checkpoint-v1'
            && ($session['scoped_receipt_sha256'] ?? null) === $receipt
            && ($first['scoped_receipt_sha256'] ?? null) === $receipt,
        'initial scoped begin persists the closed profile and exact signed receipt hash'
    );
    $lockSource = (string) file_get_contents("$root/agent/src/PromotionLock.php");
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
    $metadataWrite = strpos($acquireInternalSource, '$session += $sessionMetadata;');
    $sessionWrite = strpos($acquireInternalSource, 'Ledger::kv_set(self::SESSION_KEY');
    $check(
        str_contains($beginScopedSource, 'self::acquire_internal(')
            && str_contains($beginScopedSource, "'profile' => 'scoped-checkpoint-v1'")
            && !str_contains($beginScopedSource, '$begun = self::begin(')
            && $metadataWrite !== false
            && $sessionWrite !== false
            && $metadataWrite < $sessionWrite,
        'scoped begin supplies profile/receipt metadata to the first session write rather than decorating a prior ordinary session'
    );
    $second = PromotionLock::begin_scoped($owner, $artifact, $receipt, 300);
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
        static fn() => PromotionLock::begin_scoped('other-owner', $artifact, $receipt, 300),
        'different target promotion session',
        'different owner cannot replace a live scoped target generation'
    );
    $expect(
        static fn() => PromotionLock::begin_scoped($owner, str_repeat('b', 64), $receipt, 300),
        'different target promotion session',
        'different artifact cannot reuse a scoped target generation'
    );
    $expect(
        static fn() => PromotionLock::begin_scoped($owner, $artifact, $otherReceipt, 300),
        'does not match its external signed receipt',
        'same owner/artifact cannot swap the external signed receipt on retry'
    );
    $check(
        (ScopedPromotionTargetLedger::$values['promotion_session'] ?? '') === $sessionBytes,
        'mismatched scoped begin attempts leave the exact target session receipt unchanged'
    );
    PromotionLock::release($owner, $artifact);

    ScopedPromotionTargetLedger::$values = [
        'promotion_session' => json_encode([
            'owner' => $owner,
            'artifact_hash' => $artifact,
            'begun_at' => time(),
        ], JSON_THROW_ON_ERROR),
    ];
    $expect(
        static fn() => PromotionLock::begin_scoped($owner, $artifact, $receipt, 300),
        'exact random promotion session generation',
        'legacy pre-random promotion sessions are refused instead of being silently upgraded'
    );
    $check(
        !array_key_exists('promotion_lock', ScopedPromotionTargetLedger::$values),
        'legacy scoped-begin refusal creates no replacement lease row'
    );

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
    $crashRetry = PromotionLock::begin_scoped($owner, $artifact, $receipt, 300);
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
        static fn() => PromotionLock::complete_scoped($owner, $artifact, $receipt),
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
        static fn() => PromotionLock::complete_scoped($owner, $artifact, $otherReceipt),
        'does not match its external signed receipt',
        'scoped completion refuses a mismatched receipt and retains the target session'
    );
    $completed = PromotionLock::complete_scoped($owner, $artifact, $receipt);
    $check(
        ($completed['released'] ?? false) === true
            && ($completed['already_absent'] ?? true) === false
            && !array_key_exists('promotion_session', ScopedPromotionTargetLedger::$values),
        'exact scoped completion removes the retained scoped-profile target session after lease release'
    );
    $completedReplay = PromotionLock::complete_scoped($owner, $artifact, $receipt);
    $check(
        ($completedReplay['released'] ?? true) === false
            && ($completedReplay['already_absent'] ?? false) === true,
        'exact scoped completion is idempotent after a lost completion response'
    );

    // Restore an exact scoped profile session for Apply's pre-terminal gate.
    ScopedPromotionTargetLedger::$values = [];
    PromotionLock::begin_scoped($owner, $artifact, $receipt, 300);

    $applyReflection = new ReflectionClass(Apply::class);
    $requestGate = $applyReflection->getMethod('assert_scoped_promotion_request');
    $validScopedOpts = [
        'scope_request' => ['format' => 'duo-scope-request/v1'],
        'promotion_owner' => $owner,
        'artifact_hash' => $artifact,
        'scoped_promotion_receipt' => $receipt,
    ];
    try {
        $requestGate->invoke(null, $validScopedOpts, true);
        $check(true, 'structured scoped-promotion option accepts compact scope, owner, and exact receipt hash');
    } catch (Throwable $failure) {
        $check(false, 'valid structured scoped-promotion option was refused (' . $failure->getMessage() . ')');
    }
    $expect(
        static fn() => $requestGate->invoke(null, [
            'promotion_owner' => $owner,
            'scoped_promotion_receipt' => $receipt,
        ], false),
        'invalid scoped promotion continuation',
        'receipt-only CLI injection is refused without a compact scope request'
    );
    $expect(
        static fn() => $requestGate->invoke(null, [
            'scope_request' => ['format' => 'duo-scope-request/v1'],
            'scoped_promotion_receipt' => $receipt,
        ], true),
        'invalid scoped promotion continuation',
        'scoped receipt is refused without the host continuation owner'
    );
    $expect(
        static fn() => $requestGate->invoke(null, [
            'scope_request' => ['format' => 'duo-scope-request/v1'],
            'promotion_owner' => $owner,
            'scoped_promotion_receipt' => 'not-a-sha256',
        ], true),
        'invalid scoped promotion continuation',
        'malformed scoped receipt identity is refused before mutation'
    );
    $expect(
        static fn() => $requestGate->invoke(
            null,
            array_replace($validScopedOpts, ['scoped_promotion_receipt' => $otherReceipt]),
            true
        ),
        'does not match its external signed receipt',
        'Apply proves the signed receipt against the target profile before terminal replay'
    );
    $expect(
        static fn() => $requestGate->invoke(null, $validScopedOpts + ['force_theirs' => true], true),
        'scoped promotion force override refused',
        'structured scoped promotion refuses every widening force flag'
    );

    $cliSource = (string) file_get_contents("$root/agent/src/Cli.php");
    $check(
        str_contains($cliSource, '@subcommand promotion-begin-scoped')
            && preg_match(
                '/PromotionLock::begin_scoped\(\s*\(string\) \$owner,\s*\(string\) \$artifactHash,\s*\(string\) \$receiptHash\s*\)/s',
                $cliSource
            ) === 1
            && str_contains($cliSource, "'scoped_promotion_receipt' => \$assoc['scoped-promotion-receipt'] ?? ''"),
        'CLI requires the receipt for scoped begin and maps that same receipt into the internal apply option'
    );
    $check(
        str_contains($cliSource, '@subcommand promotion-complete-scoped')
            && preg_match(
                '/PromotionLock::complete_scoped\(\s*\(string\) \$owner,\s*\(string\) \$artifactHash,\s*\(string\) \$receiptHash\s*\)/s',
                $cliSource
            ) === 1,
        'CLI exposes only the exact owner/artifact/receipt scoped-completion control boundary'
    );
    $check(
        str_contains($cliSource, "&& !array_key_exists('scope-request-b64', \$assoc)")
            && str_contains($cliSource, 'direct scope contract cannot enter scoped promotion'),
        'CLI structurally refuses a receipt-bearing direct local contract unless the compact orchestrator wire is present'
    );
    $cli = new \Duo\Cli();
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

    $beginArgs = CodeDeploy::beginScopedArgs($owner, $artifact, $receipt);
    $check(
        in_array('duo', $beginArgs, true)
            && in_array('promotion-begin-scoped', $beginArgs, true)
            && in_array('--promotion-owner=' . $owner, $beginArgs, true)
            && in_array('--artifact-hash=' . $artifact, $beginArgs, true)
            && in_array('--scoped-promotion-receipt=' . $receipt, $beginArgs, true)
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
    $completeArgs = CodeDeploy::completeScopedArgs($owner, $artifact, $receipt);
    $check(
        in_array('duo', $completeArgs, true)
            && in_array('promotion-complete-scoped', $completeArgs, true)
            && in_array('--promotion-owner=' . $owner, $completeArgs, true)
            && in_array('--artifact-hash=' . $artifact, $completeArgs, true)
            && in_array('--scoped-promotion-receipt=' . $receipt, $completeArgs, true)
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
        static fn() => PromotionLock::begin_scoped($owner, $artifact, $receipt, 300),
        'does not match its external signed receipt',
        'ordinary random promotion sessions cannot be reinterpreted as scoped-checkpoint generations'
    );
    $expect(
        static fn() => $requestGate->invoke(null, $validScopedOpts, true),
        'does not match its external signed receipt',
        'Apply rejects ordinary-profile session replay before it can return a terminal scoped result'
    );

    $apply = $applyReflection->newInstanceWithoutConstructor();
    $selectedActions = $applyReflection->getProperty('selectedActions');
    $selectionGate = $applyReflection->getMethod('assert_scoped_promotion_selection');
    $taxonomyGate = $applyReflection->getMethod('scoped_work_needs_taxonomy_recount');
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

    $applySource = (string) file_get_contents("$root/agent/src/Apply.php");
    $check(
        str_contains($applySource, 'plus derived cache eviction')
            && preg_match('/if \(\$this->selectedActions !== \[\]\) \{.{0,320}wp_cache_flush\(\)/s', $applySource) === 1,
        'the initial profile admits only derived cache eviction: action-free scoped work cannot dispatch a pre-action cache/effect path'
    );

    if ($failures !== 0) {
        fwrite(STDERR, "FAIL: $failures scoped-promotion target assertion(s) failed after $checks checks\n");
        exit(1);
    }
    echo "scoped promotion target regression passed ($checks checks)\n";
}

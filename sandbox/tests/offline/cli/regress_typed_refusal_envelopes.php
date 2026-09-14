<?php
/**
 * The lock/concurrency and repository-state refusals reach a `--format=json`
 * caller as their own reason code, and their operator sentences do not move.
 *
 * WHAT WAS BROKEN
 * ---------------
 * Every refusal converted here was a bare `\RuntimeException`.
 * `Cli::halt_json_failure()` publishes `CommandRefusalException::payload()`
 * verbatim (agent/src/Command/Cli.php:60-64) and sends everything else to its
 * catch-all (:83-98), which mints `<command>_failed`, the constant sentence
 * "<command> refused at an unclassified safety gate", and
 * `details_redacted: true`. So an orchestrator running capture/plan/apply/deploy
 * with `--format=json` could not distinguish, at the top level of the envelope:
 *
 *   - "another process on this target is mid-mutation, retry later"
 *     (process_fence_held) from a corrupt repository;
 *   - "your --repo is not a wprism repository" (repository_missing) from a real
 *     policy defect;
 *   - "this deployed site's manifest pins moved, recompile and re-pin"
 *     (compiled_artifact_manifest_mismatch) from a repository that failed to
 *     compile — that one arrived as `repository_compilation_failed` with the
 *     real code buried in `diagnostics[0].code` AND the absolute artifact path
 *     copied into public JSON.
 *
 * WHAT THIS SUITE PROVES, per converted throw site
 * ------------------------------------------------
 *  1. THE MECHANISM. Each refusal is produced by the REAL product code — the
 *     real `ProcessFence`, `PromotionLease`, `Policy` and
 *     `CompiledArtifactReader` running against `FakeWpdb` and real files — not
 *     by a hand-built exception object. Reverting any throw to
 *     `\RuntimeException` fails here.
 *  2. THE CODE AND THE ENVELOPE. That same exception is then carried across the
 *     REAL `Cli` handler's catch boundary, and the published record must name
 *     the reason code at the top level with NO `details_redacted` key. On the
 *     pre-change tree every one of these assertions reports
 *     `<command>_failed` + `details_redacted: true`.
 *  3. THE HUMAN BYTES. `getMessage()` is byte-identical to the sentence
 *     `WP_CLI::error($t->getMessage())` printed before. Three consumers read
 *     exactly these bytes: sandbox/tests/live/regress_capture_concurrency.sh:500,656,
 *     sandbox/tests/live/regress_promotion_lock.sh:194, and cli/wprism:3267, which
 *     `str_contains()` "promotion lock held by" to tell a definite live
 *     contender from an uncertain begin. Anyone who "improves" the operator
 *     wording breaks those, and this suite says so offline first.
 *  4. THE §5.2 BOUNDARY. The public half is a reviewed constant, because the
 *     operator half is not: it carries lease owner tokens, phases, expiry
 *     epochs, 64-hex artifact hashes, and absolute repository/artifact paths.
 *     An absolute path under /Users or /home would additionally trip
 *     `CommandRefusalException::containsSensitivePublicDetail()`
 *     (agent/src/Kernel/CommandRefusal.php:199) and redact the WHOLE payload,
 *     turning a named refusal back into the generic one — so `detailsRedacted`
 *     must be false everywhere here.
 *
 * OFFLINE: no WordPress, no MySQL, no docker. `FakeWpdb` carries the advisory
 * lock/connection model `ProcessFence` needs (agent/src/Kernel/ProcessFence.php:29,56-61)
 * and the `wprism_kv` rows `Ledger::kv_get()` reads.
 */
declare(strict_types=1);

namespace {
    /** WP_CLI::halt() has no return; this is how the suite observes the exit. */
    final class TypedRefusalHalt extends RuntimeException {
        public function __construct(public int $status) {
            parent::__construct("halt:$status");
        }
    }

    /** WP_CLI::error() exits too; reaching it at all is a JSON-mode defect. */
    final class TypedRefusalHumanError extends RuntimeException {}

    final class WP_CLI {
        /** @var list<string> */
        public static array $lines = [];
        /** @var list<string> */
        public static array $errors = [];

        public static function add_command($name, $class): void {}

        public static function line($line): void {
            self::$lines[] = (string) $line;
        }

        public static function warning($message): void {
            self::$lines[] = 'warning:' . (string) $message;
        }

        public static function success($message): void {
            self::$lines[] = 'success:' . (string) $message;
        }

        public static function halt($status): void {
            throw new TypedRefusalHalt((int) $status);
        }

        public static function error($message, $exit = true): void {
            self::$errors[] = (string) $message;
            if ($exit !== false) {
                throw new TypedRefusalHumanError((string) $message);
            }
        }

        public static function reset(): void {
            self::$lines = [];
            self::$errors = [];
        }
    }
}

namespace WPrism {
    /**
     * The three backends `Cli` calls on the paths under test.
     *
     * They are the ONLY stubs in this suite, and they hold no refusal logic of
     * their own: each one runs the closure the case installed, which calls the
     * real engine gate. `Cli`'s routing, catch boundary, envelope, redaction
     * and exit behaviour are all the unmodified product code. The real
     * Capture/Apply/Deploy sources are deliberately never required — they would
     * pull the whole materializer stack for no added coverage of the refusal
     * boundary this suite is about.
     */
    final class Capture {
        public static function run($repo, $out, $force, $scopeRequest = null, $hostEnvironment = null): array {
            return \typed_refusal_mechanism();
        }
    }

    final class Apply {
        public static function plan($repo, array $options): array {
            return \typed_refusal_mechanism();
        }

        public static function apply($repo, array $options): array {
            return \typed_refusal_mechanism();
        }
    }

    final class Deploy {
        public static function run($repo, array $options): array {
            return \typed_refusal_mechanism();
        }
    }
}

namespace {
    // From offline/<domain>/: two hops to the corpus root, four to the repo root.
    require_once __DIR__ . '/../../lib/check.php';
    require_once __DIR__ . '/../../lib/wp_stubs.php';
    require_once __DIR__ . '/../../lib/FakeWpdb.php';

    require_once __DIR__ . '/../../../../agent/src/Kernel/CommandRefusal.php';
    require_once __DIR__ . '/../../../../agent/src/Kernel/Db.php';
    require_once __DIR__ . '/../../../../agent/src/Kernel/ProcessFence.php';
    require_once __DIR__ . '/../../../../agent/src/Repository/Ledger.php';
    require_once __DIR__ . '/../../../../agent/src/Promotion/PromotionLease.php';
    require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
    require_once __DIR__ . '/../../../../agent/src/Repository/RepositoryCompiler.php';
    require_once __DIR__ . '/../../../../agent/src/Command/Cli.php';

    use WPrism\ArtifactPolicyIdentity;
    use WPrism\Canon;
    use WPrism\CommandRefusalException;
    use WPrism\CompiledArtifactReader;
    use WPrism\CompiledRepository;
    use WPrism\Policy;
    use WPrism\ProcessFence;
    use WPrism\PromotionLease;
    use WPrismTest\FakeWpdb;
    use WPrismTest\WpStore;

    // A PHP warning is itself an unclassified output channel on a suite that
    // certifies public failure output, so it must make this run non-green.
    set_error_handler(
        static function (int $severity, string $message, string $file, int $line): bool {
            throw new ErrorException($message, 0, $severity, $file, $line);
        },
        E_WARNING | E_USER_WARNING
    );

    /** The gate the installed case runs; the WPrism backends above call only this. */
    function typed_refusal_mechanism(): array {
        $mechanism = $GLOBALS['wprism_typed_refusal_mechanism'] ?? null;
        if (!$mechanism instanceof Closure) {
            throw new RuntimeException('no engine gate was installed for this case');
        }
        $mechanism();
        return [];
    }

    /**
     * `$wpdb` for the promotion-lease cases.
     *
     * A DECORATOR over the shared FakeWpdb, not an eleventh bespoke fake: it
     * forwards everything and intercepts exactly the two `wprism_kv` statements
     * whose `JSON_EXTRACT` predicate FakeWpdb's interpreter deliberately
     * refuses — `PromotionLease`'s acquire upsert
     * (agent/src/Promotion/PromotionLease.php:373-394) and its heartbeat UPDATE
     * (:520-528).
     *
     * Both modes are REAL MySQL outcomes of those statements, which is what
     * makes this faithful rather than convenient:
     *
     *   'keep'  — the upsert's `IF(...)` chose `v` over `VALUES(v)`, so the
     *             existing lease row survives and zero rows changed. That is
     *             exactly what happens for a live foreign lease, for an expired
     *             own lease, and for an own lease under a different artifact:
     *             the three cases below.
     *   'clear' — the row is gone by the time the statement lands, which is the
     *             lost-lease race the post-write readback at :539-545 exists to
     *             catch.
     *
     * The REPLACEMENT outcome (the `VALUES(v)` branch, i.e. a successful
     * acquire) is deliberately not modelled here; regress_promotion_unit.sh and
     * the live promotion pin own that path. Teaching FakeWpdb the full
     * JSON_EXTRACT upsert would let it be exercised offline too, and is
     * recorded as follow-up work rather than done inside this change.
     */
    final class LeaseStatementWpdb extends FakeWpdb {
        public int $intercepted = 0;

        public function __construct(public string $mode) {
            parent::__construct();
        }

        public function query(string $query): int|bool {
            if (str_contains($query, 'JSON_EXTRACT')) {
                $this->intercepted++;
                // Send the exact statement through the shared wpdb transport
                // first so the engine's query/isolation gates observe it. The
                // fixture then selects the real server outcome this suite is
                // characterizing: conditional upsert kept the prior row, or a
                // heartbeat raced with deletion and changed zero rows.
                $before = $this->rows('wp_wprism_kv');
                try {
                    parent::query($query);
                } catch (\LogicException $unsupportedFixtureGrammar) {
                    if ($this->mode !== 'clear' || !str_starts_with(ltrim($query), 'UPDATE ')) {
                        throw $unsupportedFixtureGrammar;
                    }
                    // The shared fake deliberately has no JSON_EXTRACT DML
                    // grammar for this authority-fenced renewal. Its parent
                    // call has still consumed the exact engine permit; this
                    // fixture supplies only the selected zero-row server
                    // outcome after that transport proof.
                }
                $after = $this->mode === 'clear'
                    ? array_values(array_filter(
                        $before,
                        static fn(array $row): bool => ($row['k'] ?? '') !== 'promotion_lock'
                    ))
                    : $before;
                $this->seedTable('wp_wprism_kv', $after);
                return 0;
            }
            return parent::query($query);
        }
    }

    $tmp = sys_get_temp_dir() . '/wprism-typed-refusal-envelopes-' . bin2hex(random_bytes(6));
    if (!mkdir($tmp, 0777, true) && !is_dir($tmp)) {
        throw new RuntimeException("could not create $tmp");
    }
    register_shutdown_function(static function () use ($tmp): void {
        foreach (glob($tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($tmp);
    });

    /** A lease owner token and a 64-hex artifact hash: both operator-only. */
    const LEASE_OWNER = 'deploy-run-typed-refusal-owner';
    const LEASE_ARTIFACT = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
    const OTHER_OWNER = 'deploy-run-other-release-owner';
    const OTHER_ARTIFACT = '0f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c4b5a69788796a5b4c3d2e1f0';

    /**
     * Install a fresh `$wpdb` holding the given `wprism_kv` rows.
     *
     * @param array<string,array<string,mixed>> $rows key => decoded payload
     */
    function typed_refusal_wpdb(array $rows = [], ?string $leaseMode = null, int $lockResult = 1): object {
        WpStore::reset();
        $wpdb = ($leaseMode === null ? new FakeWpdb() : new LeaseStatementWpdb($leaseMode))
            ->enableInformationSchema()
            ->enableFullApplySqlExtensions()
            ->setColumns('wprism_kv', ['k' => 'varchar(191)', 'v' => 'longtext'])
            ->setUniqueKey('wprism_kv', ['k'])
            ->setTableEngine('wprism_kv', 'InnoDB');
        $wpdb->setLockResult($lockResult);
        $wpdb->seedTable('wp_wprism_kv', array_values(array_map(
            static fn(string $key, array $payload): array => [
                'k' => $key,
                'v' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            ],
            array_keys($rows),
            array_values($rows)
        )));
        $GLOBALS['wpdb'] = $wpdb;
        // ProcessFence caches the fence name and connection id in statics; a
        // new $wpdb without this would look like a continuously-held fence.
        ProcessFence::release();
        return $wpdb;
    }

    /** The live `promotion_lock` row a promotion holds while it runs. */
    function typed_refusal_lease(string $owner, string $artifact, int $expiresAt): array {
        return [
            'acquired_at' => $expiresAt - 3600,
            'artifact_hash' => $artifact,
            'expires_at' => $expiresAt,
            'owner' => $owner,
            'phase' => 'deploy',
        ];
    }

    /**
     * Run one case end to end.
     *
     * The engine gate runs TWICE on purpose: once directly, so the refusal
     * object itself can be inspected (property 1 and 3 above), and once through
     * the real `Cli` handler, so the published record can be (property 2). Both
     * runs execute the same closure against the same seeded state.
     *
     * @param callable():void $gate      the real engine call that must refuse
     * @param callable():void $reseed    restores the state the gate consumed
     * @return array{0:?CommandRefusalException,1:array<string,mixed>}
     */
    function typed_refusal_case(
        string $case,
        string $command,
        callable $handler,
        callable $gate,
        callable $reseed
    ): array {
        $reseed();
        $GLOBALS['wprism_typed_refusal_mechanism'] = Closure::fromCallable($gate);

        $refusal = null;
        try {
            $gate();
            wprism_check(false, "$case: the engine gate did not refuse at all");
        } catch (CommandRefusalException $typed) {
            wprism_check(true, "$case: the engine gate refuses with a typed, machine-readable refusal");
            $refusal = $typed;
        } catch (Throwable $other) {
            wprism_check(false, "$case: the engine gate threw " . get_class($other) . ' instead of CommandRefusalException');
            wprism_check_detail('message: ' . $other->getMessage());
        }

        $reseed();
        WP_CLI::reset();
        try {
            $handler();
            wprism_check(false, "$case: the $command handler returned instead of halting");
        } catch (TypedRefusalHalt $halt) {
            wprism_check_same(1, $halt->status, "$case: the $command JSON refusal exits with status 1");
        } catch (Throwable $other) {
            wprism_check(false, "$case: the $command handler halted through " . get_class($other) . ', not the structured path');
            wprism_check_detail('message: ' . $other->getMessage());
        }
        wprism_check_same([], WP_CLI::$errors, "$case: the $command JSON refusal never enters WP_CLI::error human rendering");
        wprism_check_same(1, count(WP_CLI::$lines), "$case: the $command JSON refusal emits exactly one machine-readable record");
        $decoded = json_decode(WP_CLI::$lines[0] ?? '', true);
        wprism_check(is_array($decoded), "$case: the $command JSON refusal record decodes");

        return [$refusal, is_array($decoded) ? $decoded : []];
    }

    /**
     * Assert everything that must hold for one converted refusal.
     *
     * @param array<string,mixed> $published
     */
    function typed_refusal_assert(
        string $case,
        string $command,
        ?CommandRefusalException $refusal,
        array $published,
        string $reasonCode,
        string $publicMessage,
        string $remediation,
        string $operatorSentence
    ): void {
        if ($refusal !== null) {
            wprism_check_same($reasonCode, $refusal->reasonCode, "$case: names $reasonCode");
            wprism_check_same($publicMessage, $refusal->publicMessage, "$case: its public message is the reviewed constant");
            wprism_check_same($remediation, $refusal->remediation, "$case: its remediation is the reviewed constant");
            // Byte-identity with what WP_CLI::error() printed before this
            // change, which the live pins and cli/wprism:3267 read.
            wprism_check_same($operatorSentence, $refusal->getMessage(), "$case: getMessage() is byte-identical to the operator sentence");
            wprism_check_same(false, $refusal->detailsRedacted, "$case: the reviewed public fields survive the constructor's own screen");
            wprism_check(
                $refusal instanceof RuntimeException,
                "$case: it is still a \\RuntimeException, so every existing catch classifies it identically"
            );
        }

        // The envelope an orchestrator actually branches on. Every assertion
        // below reports `{$command}_failed` on the pre-change tree.
        wprism_check_same($reasonCode, $published['error'] ?? null, "$case: the published envelope names $reasonCode at the top level");
        wprism_check_same($reasonCode, $published['reason_code'] ?? null, "$case: reason_code agrees with error");
        wprism_check_same($publicMessage, $published['message'] ?? null, "$case: the published message is the reviewed public half");
        wprism_check_same($remediation, $published['remediation'] ?? null, "$case: the published remediation is the reviewed public half");
        wprism_check_same($command, $published['command'] ?? null, "$case: the record identifies its public command");
        wprism_check_same('wprism-command-refusal/v1', $published['format'] ?? null, "$case: the record keeps the versioned refusal envelope");
        wprism_check(
            !array_key_exists('details_redacted', $published),
            "$case: the refusal is classified, so it is not the redacted catch-all it used to fall into"
        );

        // §5.2: nothing value-bearing may reach the published record.
        $json = (string) json_encode($published, JSON_UNESCAPED_SLASHES);
        wprism_check(
            !str_contains($json, LEASE_OWNER) && !str_contains($json, OTHER_OWNER),
            "$case: no lease owner token reaches the published record"
        );
        wprism_check(
            preg_match('/(?<![0-9a-f])[0-9a-f]{64}(?![0-9a-f])/', $json) !== 1,
            "$case: no 64-hex artifact hash reaches the published record"
        );
        wprism_check(
            preg_match('~(?:^|[\s:(\'"])/[^\s\'"),]+~', $json) !== 1,
            "$case: no absolute filesystem path reaches the published record"
        );
        wprism_check(
            preg_match('/\b[12]\d{9}\b/', $json) !== 1,
            "$case: no epoch timestamp reaches the published record"
        );
        wprism_check_same(
            false,
            CommandRefusalException::containsSensitivePublicDetail($published),
            "$case: the record passes the same screen Cli applies at the serialization boundary"
        );
    }

    $cli = new \WPrism\Cli();

    // ------------------------------------------------------------- the fence
    echo "\n== the connection-scoped process fence ==\n";

    // The former synthetic 63-byte live probe missed the actual 66-byte
    // branded name. Enforce the server limit on the product-derived name
    // through acquisition, continuity, and release instead of copying its SQL.
    $fenceNames = [];
    $wpdb = typed_refusal_wpdb()->onQuery(static function (string $sql) use (&$fenceNames): ?string {
        if (preg_match("/^SELECT (?:GET_LOCK|IS_USED_LOCK|RELEASE_LOCK)\\('([^']+)'/D", $sql, $match) === 1) {
            $fenceNames[] = $match[1];
            if (strlen($match[1]) > 64) {
                return 'User-level lock name should not exceed 64 characters';
            }
        }
        return null;
    });
    $name = ProcessFence::name();
    wprism_check(
        strlen($name) === 64 && preg_match('/^wprism:[a-f0-9]{57}$/D', $name) === 1,
        'the actual product process-fence name fits exactly within the MySQL 64-byte limit'
    );
    wprism_check_same($name, ProcessFence::name(), 'the same database and site prefix derive the same process fence');
    $database = $wpdb->dbname;
    $wpdb->dbname = $database . '_other';
    wprism_check($name !== ProcessFence::name(), 'different databases do not share the target process fence');
    $wpdb->dbname = $database;
    $prefix = $wpdb->prefix;
    $wpdb->prefix = $prefix . 'other_';
    wprism_check($name !== ProcessFence::name(), 'different site prefixes do not share the target process fence');
    $wpdb->prefix = $prefix;
    $roundTrip = false;
    try {
        ProcessFence::acquire();
        $roundTrip = ProcessFence::isContinuous();
    } catch (Throwable) {
        // The old name must fail this assertion without terminating the suite.
    } finally {
        ProcessFence::release();
    }
    wprism_check($roundTrip, 'the real process fence acquires and verifies under the server name limit');
    wprism_check_same([$name, $name, $name], $fenceNames, 'acquire, continuity, and release use one exact bounded name');

    $suppressedDuringFailure = false;
    $wpdb = typed_refusal_wpdb()->onQuery(
        static function (string $sql, string $method, FakeWpdb $db) use (&$suppressedDuringFailure): ?string {
            if (str_starts_with($sql, 'SELECT GET_LOCK(')) {
                $suppressedDuringFailure = $db->suppress_errors();
                return 'private database transport detail';
            }
            return null;
        }
    );
    foreach ([false, true] as $previousSuppression) {
        $wpdb->suppress_errors($previousSuppression);
        try {
            ProcessFence::acquire();
        } catch (CommandRefusalException) {
            // The envelope cases below independently pin this refusal's type.
        }
        wprism_check($suppressedDuringFailure, 'wpdb cannot render private SQL or driver text during fence transport failure');
        wprism_check_same(
            $previousSuppression,
            $wpdb->suppress_errors(),
            'fence transport restores either prior wpdb error-display policy after refusal'
        );
    }

    // GET_LOCK returns 0: another live process on this target holds the fence.
    // This is the single most common refusal a working operator meets, because
    // it is what two concurrent captures or a capture racing an apply produce.
    $reseedHeld = static fn(): object => typed_refusal_wpdb([], null, 0);
    [$refusal, $published] = typed_refusal_case(
        'a contended process fence',
        'capture',
        static fn() => $cli->capture([], ['repo' => '/fixture', 'format' => 'json']),
        static fn() => ProcessFence::acquire(),
        $reseedHeld
    );
    typed_refusal_assert(
        'a contended process fence',
        'capture',
        $refusal,
        $published,
        'process_fence_held',
        'another live process on this target holds the promotion fence; concurrent target mutation was refused',
        'wait for the capture, apply, or promotion already running on this target to finish or release its fence, then retry this command',
        'wprism: promotion lock held by another live target process; concurrent target mutation refused'
    );

    foreach ([
        'a failed fence acquisition query' => static fn(): object => typed_refusal_wpdb()
            ->failNextQuery('private database detail must not escape', 'SELECT GET_LOCK('),
        'a NULL fence result without driver text' => static fn(): object => typed_refusal_wpdb()
            ->failNextQuery('', 'SELECT GET_LOCK('),
        'a malformed fence result' => static fn(): object => typed_refusal_wpdb([], null, 2),
        'an unreadable fence connection' => static fn(): object => typed_refusal_wpdb()
            ->failNextQuery('private connection detail must not escape', 'SELECT CONNECTION_ID()'),
        'a malformed fence connection' => static fn(): object => typed_refusal_wpdb()->setConnectionId(0),
        'a thrown fence transport failure' => static fn(): object => typed_refusal_wpdb()
            ->onQuery(static function (string $sql): ?string {
                if (str_starts_with($sql, 'SELECT GET_LOCK(')) {
                    throw new RuntimeException('private thrown driver detail must not escape');
                }
                return null;
            }),
        'an unreadable held-fence witness' => static function (): object {
            $db = typed_refusal_wpdb();
            ProcessFence::acquire();
            return $db->failNextQuery('private lock-holder detail must not escape', 'SELECT IS_USED_LOCK(');
        },
    ] as $case => $reseedUnavailable) {
        [$refusal, $published] = typed_refusal_case(
            $case,
            'capture',
            static fn() => $cli->capture([], ['repo' => '/fixture', 'format' => 'json']),
            static fn() => ProcessFence::acquire(),
            $reseedUnavailable
        );
        typed_refusal_assert(
            $case,
            'capture',
            $refusal,
            $published,
            'process_fence_unavailable',
            'the target database could not establish or verify the process fence; target mutation was refused',
            'restore database connectivity and advisory-lock support, then rerun the command; inspect recorded apply, promotion, and recovery evidence first if a mutation was already in flight',
            'wprism: the target process fence is unavailable because its database lock state could not be established or verified'
        );
        wprism_check(
            !str_contains((string) json_encode($published), 'private '),
            "$case: driver detail never enters the public refusal"
        );
        wprism_check_same(false, $GLOBALS['wpdb']->suppress_errors(), "$case: the prior wpdb error-display policy is restored");
    }

    // assertHeld() with no fence ever taken: the continuity break. A DIFFERENT
    // operator answer from contention, so a different code.
    [$refusal, $published] = typed_refusal_case(
        'a broken fence continuity',
        'capture',
        static fn() => $cli->capture([], ['repo' => '/fixture', 'format' => 'json']),
        static fn() => ProcessFence::assertHeld(),
        static fn(): object => typed_refusal_wpdb()
    );
    typed_refusal_assert(
        'a broken fence continuity',
        'capture',
        $refusal,
        $published,
        'process_fence_not_held',
        'the promotion process fence is no longer continuously held by this command\'s database connection; target mutation was refused',
        'do not retry in place: rerun the command so it acquires a fresh fence, and inspect the recorded apply, promotion, and recovery evidence first if a mutation was already in flight',
        'wprism: promotion process fence is not continuously held by this database connection'
    );

    // ------------------------------------------------------- the promotion lease
    // These direct handler probes begin after the real MU bootstrap would
    // prepare deployment. Keep the native context prerequisite so each case
    // reaches its intended PromotionLease gate and preserves that envelope.
    \WPrism\LifecycleCommandContext::bootstrap(['wprism', 'deploy'], []);
    echo "\n== the durable promotion lease ==\n";

    // A FIXED, FAR-FUTURE expiry, not time()+n: the operator sentence
    // interpolates this epoch and is pinned byte-for-byte below, and a foreign
    // lease that is ALSO expired would take the upsert's replace branch in real
    // MySQL, which would make the decorator's 'keep' mode unfaithful the moment
    // a nearer epoch fell into the past.
    $liveForeign = static fn(): object => typed_refusal_wpdb(
        ['promotion_lock' => typed_refusal_lease(OTHER_OWNER, OTHER_ARTIFACT, 4102444800)],
        'keep'
    );
    [$refusal, $published] = typed_refusal_case(
        'a lease held by another release',
        'deploy',
        static fn() => $cli->deploy([], ['repo' => '/fixture', 'format' => 'json']),
        static fn() => PromotionLease::begin(LEASE_OWNER, LEASE_ARTIFACT),
        $liveForeign
    );
    typed_refusal_assert(
        'a lease held by another release',
        'deploy',
        $refusal,
        $published,
        'promotion_lease_held',
        'a promotion lease on this target is held by another release; concurrent target mutation was refused',
        'wait for the recorded promotion to finish, or release its lease through the release that holds it, before promoting this target again',
        "wprism: promotion lock held by '" . OTHER_OWNER . "' in phase 'deploy' until epoch 4102444800; "
            . 'concurrent target mutation refused'
    );

    // The same owner, but its lease died before the handoff.
    [$refusal, $published] = typed_refusal_case(
        'an expired own lease',
        'deploy',
        static fn() => $cli->deploy([], ['repo' => '/fixture', 'format' => 'json']),
        static fn() => PromotionLease::begin(LEASE_OWNER, LEASE_ARTIFACT),
        static fn(): object => typed_refusal_wpdb(
            ['promotion_lock' => typed_refusal_lease(LEASE_OWNER, LEASE_ARTIFACT, 1000000000)],
            'keep'
        )
    );
    typed_refusal_assert(
        'an expired own lease',
        'deploy',
        $refusal,
        $published,
        'promotion_lease_expired',
        'the promotion lease expired before this handoff; the promotion was refused rather than revived',
        'begin a new promotion instead of reviving this one; an expired owner must not resume a half-finished release',
        'wprism: promotion lock expired before handoff; start a new promotion rather than reviving this owner'
    );

    // The same owner, a live lease, a different compiled artifact.
    [$refusal, $published] = typed_refusal_case(
        'a lease owner changing its artifact',
        'deploy',
        static fn() => $cli->deploy([], ['repo' => '/fixture', 'format' => 'json']),
        static fn() => PromotionLease::begin(LEASE_OWNER, LEASE_ARTIFACT),
        static fn(): object => typed_refusal_wpdb(
            ['promotion_lock' => typed_refusal_lease(LEASE_OWNER, OTHER_ARTIFACT, 4102444800)],
            'keep'
        )
    );
    typed_refusal_assert(
        'a lease owner changing its artifact',
        'deploy',
        $refusal,
        $published,
        'promotion_lease_artifact_mismatch',
        'the promotion lease owner attempted to change its compiled artifact mid-promotion; target mutation was refused',
        'abort this promotion and begin a new one for the exact compiled artifact you intend to release',
        'wprism: promotion lock owner attempted to change its compiled artifact'
    );

    // Two internal detection points, ONE public code. First: the pre-write
    // readback finds no lease at all.
    [$refusal, $published] = typed_refusal_case(
        'a heartbeat with no lease left',
        'deploy',
        static fn() => $cli->deploy([], ['repo' => '/fixture', 'format' => 'json']),
        static fn() => PromotionLease::heartbeat(LEASE_OWNER, LEASE_ARTIFACT, 'deploy'),
        static fn(): object => typed_refusal_wpdb([], 'keep')
    );
    typed_refusal_assert(
        'a heartbeat with no lease left',
        'deploy',
        $refusal,
        $published,
        'promotion_lease_lost',
        'the promotion lease this command holds is gone or expired; target mutation was refused',
        'do not retry in place: abort this promotion, inspect the recorded lifecycle and recovery evidence, then begin a new promotion',
        'wprism: promotion lock lost or expired; mutation refused'
    );

    // Second: the lease was still there before the renewal UPDATE and gone
    // after it. Same code and same guidance to a machine caller; the operator
    // sentence still says which readback saw it.
    [$refusal, $published] = typed_refusal_case(
        'a lease lost during renewal',
        'deploy',
        static fn() => $cli->deploy([], ['repo' => '/fixture', 'format' => 'json']),
        static fn() => PromotionLease::heartbeat(LEASE_OWNER, LEASE_ARTIFACT, 'deploy'),
        static fn(): object => typed_refusal_wpdb(
            ['promotion_lock' => typed_refusal_lease(LEASE_OWNER, LEASE_ARTIFACT, 4102444800)],
            'clear'
        )
    );
    typed_refusal_assert(
        'a lease lost during renewal',
        'deploy',
        $refusal,
        $published,
        'promotion_lease_lost',
        'the promotion lease this command holds is gone or expired; target mutation was refused',
        'do not retry in place: abort this promotion, inspect the recorded lifecycle and recovery evidence, then begin a new promotion',
        'wprism: promotion lock lost during renewal; mutation refused'
    );

    // ----------------------------------------------------- the repository state
    echo "\n== repository state: not a wprism repository ==\n";

    // The first thing an orchestrator meets on a mistyped --repo. The operator
    // sentence carries the absolute site.wprism.json path; the public half must
    // not, or CommandRefusal.php:199 would redact the whole payload.
    $absentRepo = $tmp . '/not-a-wprism-repository';
    [$refusal, $published] = typed_refusal_case(
        'a --repo that is not a wprism repository',
        'plan',
        static fn() => $cli->plan([], ['repo' => $absentRepo, 'format' => 'json']),
        static fn() => Policy::load($absentRepo),
        static fn(): object => typed_refusal_wpdb()
    );
    typed_refusal_assert(
        'a --repo that is not a wprism repository',
        'plan',
        $refusal,
        $published,
        'repository_missing',
        'the given repository path is not a wprism site repository: it has no site.wprism.json',
        'point --repo at an initialized wprism site repository, or run wprism init against that directory first',
        "wprism: $absentRepo/site.wprism.json not found (not a wprism site repo?)"
    );

    // ------------------------------------------- the compiled artifact identity
    echo "\n== repository state: compiled artifact identity ==\n";

    // Every gate in read_artifact() comes out of one helper
    // (agent/src/Repository/CompiledArtifactReader.php:86-91), so all of its
    // codes are typed together rather than leaving a half-typed helper and a
    // second public vocabulary to maintain. compiled_artifact_manifest_mismatch
    // is the one a working operator meets most: it is what a single byte moved
    // under manifests/ produces on every deployed site holding a compiled
    // artifact.
    $identityPolicy = new Policy();
    /** @param array<string,mixed> $overrides */
    $writeArtifact = static function (string $path, array $overrides) use ($identityPolicy): void {
        $payload = [
            'compiler_version' => 1,
            'spec_version' => 2,
            'site_hash' => ArtifactPolicyIdentity::site_hash($identityPolicy),
            'manifest_hash' => ArtifactPolicyIdentity::manifest_hash($identityPolicy),
            'revision_hash' => str_repeat('a', 64),
            'resolved_adapters' => [],
            'effects_inventory' => [],
            'media_catalog' => [],
            'media' => [],
            'tree' => [],
            'deletions' => [],
        ];
        file_put_contents($path, Canon::encode(CompiledRepository::create($overrides + $payload)->export()));
    };

    $manifestArtifact = $tmp . '/manifest-mismatch.json';
    $writeArtifact($manifestArtifact, ['manifest_hash' => str_repeat('b', 64)]);
    [$refusal, $published] = typed_refusal_case(
        'a compiled artifact whose manifest pins moved',
        'apply',
        static fn() => $cli->apply([], ['repo' => '/fixture', 'format' => 'json']),
        static fn() => CompiledArtifactReader::read_artifact($manifestArtifact, $identityPolicy),
        static fn(): object => typed_refusal_wpdb()
    );
    typed_refusal_assert(
        'a compiled artifact whose manifest pins moved',
        'apply',
        $refusal,
        $published,
        'compiled_artifact_manifest_mismatch',
        'the compiled manifest and interpreter set does not match this repository\'s active manifest pins',
        'recompile the repository and re-pin it with the object wp wprism manifest-pin emits, then rerun this command with the new artifact',
        "wprism: repository compilation failed (1 blocking diagnostic(s)); no target contact or mutation attempted:\n"
            . "  - [compiled_artifact_manifest_mismatch] $manifestArtifact — compiled manifest/interpreter set "
            . 'does not match active pins'
    );

    $policyArtifact = $tmp . '/policy-mismatch.json';
    $writeArtifact($policyArtifact, ['site_hash' => str_repeat('c', 64)]);
    [$refusal, $published] = typed_refusal_case(
        'a compiled artifact built from another site policy',
        'apply',
        static fn() => $cli->apply([], ['repo' => '/fixture', 'format' => 'json']),
        static fn() => CompiledArtifactReader::read_artifact($policyArtifact, $identityPolicy),
        static fn(): object => typed_refusal_wpdb()
    );
    typed_refusal_assert(
        'a compiled artifact built from another site policy',
        'apply',
        $refusal,
        $published,
        'compiled_artifact_policy_mismatch',
        'the compiled artifact was built from a different site policy than the one active on this repository',
        'recompile the repository against its current site.wprism.json, then rerun this command with that artifact',
        "wprism: repository compilation failed (1 blocking diagnostic(s)); no target contact or mutation attempted:\n"
            . "  - [compiled_artifact_policy_mismatch] $policyArtifact — compiled site policy does not match the "
            . 'active site.wprism.json'
    );

    $unreadableArtifact = $tmp . '/unreadable.json';
    file_put_contents($unreadableArtifact, "{not canonical json\n");
    [$refusal, $published] = typed_refusal_case(
        'an unreadable compiled artifact',
        'apply',
        static fn() => $cli->apply([], ['repo' => '/fixture', 'format' => 'json']),
        static fn() => CompiledArtifactReader::read_artifact($unreadableArtifact, $identityPolicy),
        static fn(): object => typed_refusal_wpdb()
    );
    if ($refusal !== null) {
        wprism_check_same('compiled_artifact_invalid', $refusal->reasonCode, 'an unreadable compiled artifact: names compiled_artifact_invalid');
        // The nested Throwable text is operator evidence and can itself carry
        // the artifact path, which is exactly why the published diagnostic
        // carries the reviewed constant instead of $message.
        wprism_check(
            str_contains($refusal->getMessage(), '[compiled_artifact_invalid] ' . $unreadableArtifact),
            'an unreadable compiled artifact: the operator sentence keeps the compiler-batch framing, path included'
        );
    }
    wprism_check_same('compiled_artifact_invalid', $published['error'] ?? null, 'an unreadable compiled artifact: the envelope names compiled_artifact_invalid');
    wprism_check(
        !array_key_exists('details_redacted', $published)
            && !str_contains((string) json_encode($published), $unreadableArtifact),
        'an unreadable compiled artifact: the envelope is classified and the artifact path never reaches it'
    );

    // The reviewed diagnostic row every reader refusal publishes: code plus the
    // same constant guidance, and NEVER the artifact path or the nested
    // exception text that used to be copied verbatim into public JSON.
    wprism_check_same(
        [['code' => 'compiled_artifact_invalid',
          'message' => 'the compiled artifact is unreadable or disagrees with its own recorded content',
          'remediation' => 'recompile the repository and rerun this command against the newly compiled artifact']],
        $published['diagnostics'] ?? null,
        'an unreadable compiled artifact: its published diagnostic row is reviewed, constant, and path-free'
    );

    wprism_check_summary('regress_typed_refusal_envelopes');
}

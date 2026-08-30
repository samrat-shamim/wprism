<?php
/**
 * Offline product-command regression for issue #3345's stable JSON refusal
 * surface.  Backend stubs do only one thing: make the real Cli handlers
 * cross their catch boundary with a chosen Throwable.  Formatting, command
 * routing, redaction, exit behavior, and preservation of typed compiler
 * diagnostics are all exercised through the unmodified public handlers.
 */

namespace {
    final class CliJsonHalt extends RuntimeException {
        public function __construct(public int $status) {
            parent::__construct("halt:$status");
        }
    }

    final class CliJsonHumanError extends RuntimeException {}

    final class WP_CLI {
        /** @var list<string> */
        public static array $lines = [];
        /** @var list<string> */
        public static array $errors = [];

        public static function add_command($name, $class): void {}

        public static function line($line): void {
            self::$lines[] = (string) $line;
        }

        public static function halt($status): void {
            throw new CliJsonHalt((int) $status);
        }

        /**
         * WP_CLI::error()'s real second parameter. It was elided while every
         * caller exited; issue #3489 added one that does not (verify-canonical
         * writes its operator sentence to STDERR and then still halts through
         * the envelope), and a stub that ignores $exit would report that as a
         * command which never reaches its own envelope at all.
         */
        public static function error($message, $exit = true): void {
            self::$errors[] = (string) $message;
            if ($exit !== false) {
                throw new CliJsonHumanError((string) $message);
            }
        }

        public static function reset(): void {
            self::$lines = [];
            self::$errors = [];
        }
    }
}

namespace WPrism {
    final class Capture {
        public static ?\Throwable $failure = null;

        public static function run($repo, $out, $force): array {
            if (self::$failure !== null) {
                throw self::$failure;
            }
            return [];
        }
    }

    /**
     * issue #3522: enough of Init to drive Cli::init() down its refusal path.
     * $onCall runs BEFORE the throw, which is how the suite reproduces the
     * shape that mattered -- an init that publishes site.wprism.json and only then
     * fails, so the directory becomes a WPrism repository mid-command.
     */
    final class Init {
        public const FORMAT = 'wprism-init-plan/v1';
        public static ?\Throwable $failure = null;
        /** @var ?callable */
        public static $onCall = null;

        private static function answer(): array {
            if (self::$onCall !== null) {
                (self::$onCall)();
            }
            if (self::$failure !== null) {
                throw self::$failure;
            }
            return ['format' => self::FORMAT, 'ready' => true];
        }

        public static function proposal($repo, $allowUnmanagedPlugins = false, $lockPlan = null): array {
            return self::answer();
        }

        public static function confirm($repo, $digest, $allowUnmanagedPlugins = false, $lockPlan = null): array {
            return self::answer();
        }
    }

    final class Apply {
        public static ?\Throwable $planFailure = null;
        public static ?\Throwable $applyFailure = null;
        public static array $planResult = [];
        public static array $lastPlanOptions = [];

        public static function plan($repo, array $options): array {
            self::$lastPlanOptions = $options;
            if (self::$planFailure !== null) {
                throw self::$planFailure;
            }
            return self::$planResult;
        }

        public static function apply($repo, array $options): array {
            if (self::$applyFailure !== null) {
                throw self::$applyFailure;
            }
            return [];
        }

        public static function explain($repo, string $selector, array $options): array {
            if (self::$planFailure !== null) {
                throw self::$planFailure;
            }
            return [];
        }

        public static function verify_canonical($repo, array $options): array {
            if (self::$verifyFailure !== null) {
                throw self::$verifyFailure;
            }
            return [];
        }

        public static function set_env_option($repo, string $name, string $value): array {
            if (self::$envFailure !== null) {
                throw self::$envFailure;
            }
            return [];
        }

        public static ?\Throwable $verifyFailure = null;
        public static ?\Throwable $envFailure = null;
    }

    final class Deploy {
        public static function run($repo, array $options): array {
            return [];
        }
    }

    /**
     * issue #3397: refresh-export and scope reach their catch boundaries through
     * their own real backends, so both stubs do exactly what the others do —
     * throw the chosen Throwable and nothing else.
     */
    final class RefreshExport {
        public static ?\Throwable $failure = null;

        public static function run($repo): array {
            if (self::$failure !== null) {
                throw self::$failure;
            }
            return [];
        }
    }

    final class Policy {
        public static ?\Throwable $failure = null;

        // Keep the test double's call boundary aligned with Policy::load().
        // lint() passes the object-only adapter library by name, so omitting
        // this parameter would replace the planted refusal with PHP's own
        // "Unknown named parameter" Error before the backend is reached.
        public static function load(
            $repo,
            ?array $manifestNames = null,
            bool $allowUnsupportedSiteForReadOnlyCapabilities = false,
            ?string $adapterRepo = null,
            ?AdapterLibrary $adapterLibrary = null
        ): array {
            if (self::$failure !== null) {
                throw self::$failure;
            }
            return [];
        }
    }

    /**
     * issue #3399: journal-report is the one newly enveloped command with no
     * required argument at all, so its catch boundary can only be reached
     * through its backend.  Everything else in this file's per-command loop
     * refuses at a gate before any backend is touched.
     */
    final class Journal {
        public static ?\Throwable $failure = null;

        /** adapter-observe must suspend before even its argument gates. */
        public static function suspend_for_observation(): void {}

        public static function report(array $names): array {
            if (self::$failure !== null) {
                throw self::$failure;
            }
            return [];
        }
    }

    /**
     * These three exist because PHP resolves a static callee BEFORE it
     * evaluates that call's arguments: a `?? throw` gate written inside
     * Pending::scan(...)/Coverage::report(...)/Orphans::run(...) never fires
     * while the class or method is missing — the suite would instead see an
     * unclassified Error and quietly agree that "some refusal happened".
     * Stubbing them keeps each assertion about the gate it names.
     */
    final class Pending {
        public static ?\Throwable $failure = null;

        public static function scan($repo): array {
            if (self::$failure !== null) {
                throw self::$failure;
            }
            return [];
        }
    }

    final class Coverage {
        public const LARGE_LISTING_THRESHOLD = 20;
        public static ?\Throwable $failure = null;

        public static function report($repo): array {
            if (self::$failure !== null) {
                throw self::$failure;
            }
            return [];
        }
    }

    final class Orphans {
        public static ?\Throwable $failure = null;

        public static function run($repo, string $table, array $options): array {
            if (self::$failure !== null) {
                throw self::$failure;
            }
            return [];
        }
    }

    /**
     * issue #3421: the one refusal CLASS halt_json_failure() publishes on its own
     * audited vocabulary, independent of the command allowlist. Declared here
     * like every other collaborator this suite stubs -- requiring the real
     * agent/src/Publication/Publish.php would drag in unrelated publication
     * behavior. The pin below asserts BOTH halves of the identity that makes
     * this narrow stub legitimate: that Cli's constant names exactly this fully
     * qualified class, and that agent/src/Publication/Publish.php is where the engine
     * really declares it.
     */
    final class InitialStateBoundaryException extends \RuntimeException {}
}

namespace {
    require __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
    require __DIR__ . '/../../../../agent/src/Kernel/Secrets.php';
    require __DIR__ . '/../../../../agent/src/Kernel/CommandRefusal.php';
    require __DIR__ . '/../../../../agent/src/Delete/Deletion.php';
    require __DIR__ . '/../../../../agent/src/Repository/RepositoryAuthorization.php';
    require __DIR__ . '/../../../../agent/src/Code/Code.php';
    require __DIR__ . '/../../../../agent/src/Repository/RepositoryCompiler.php';
    require __DIR__ . '/../../../../agent/src/Command/Cli.php';

    // The topology fixture. Declared here because SiteTopology's guard is
    // `function_exists('is_multisite') && is_multisite()`, evaluated per call,
    // so every assertion above runs against the single-site default and the
    // topology section below flips one global. Same name as
    // sandbox/tests/offline/policy/regress_topology_gate.php, which explains in
    // its header why it is a third name beside the two pre-existing ones.
    $GLOBALS['wprism_topology_multisite'] = false;
    function is_multisite(): bool {
        return (bool) $GLOBALS['wprism_topology_multisite'];
    }

    // This suite certifies public failure output. A PHP warning is itself an
    // unclassified output channel, so it must make the regression non-green.
    set_error_handler(
        static function (int $severity, string $message, string $file, int $line): bool {
            throw new ErrorException($message, 0, $severity, $file, $line);
        },
        E_WARNING | E_USER_WARNING
    );

    $failures = 0;
    function check(bool $condition, string $message): void {
        global $failures;
        if ($condition) {
            echo "ok: $message\n";
            return;
        }
        echo "FAIL: $message\n";
        $failures++;
    }

    /**
     * @param bool $operatorSentenceOnStderr issue #3489: verify-canonical is the
     *        one command whose only --format=json caller is this same product
     *        — apply's in-process convergence gate reads the diagnosis from
     *        STDERR — so its operator sentence is deliberately written there
     *        as well. Named per case rather than relaxed globally: for every
     *        other command "JSON mode never enters human rendering" is still
     *        the contract, and the record on STDOUT is unchanged for all of
     *        them, verify-canonical included.
     * @return array<string,mixed>
     */
    function invoke_json(callable $command, bool $operatorSentenceOnStderr = false): array {
        WP_CLI::reset();
        try {
            $command();
            check(false, 'JSON refusal exits non-zero (command unexpectedly returned)');
        } catch (CliJsonHalt $e) {
            check($e->status === 1, 'JSON refusal exits with status 1');
        } catch (Throwable $e) {
            check(false, 'JSON refusal halts through the structured path, not ' . get_class($e) . ': ' . $e->getMessage());
        }
        if ($operatorSentenceOnStderr) {
            check(count(WP_CLI::$errors) === 1, 'verify-canonical also states its refusal once on STDERR');
        } else {
            check(WP_CLI::$errors === [], 'JSON refusal never enters WP_CLI::error human rendering');
        }
        check(count(WP_CLI::$lines) === 1, 'JSON refusal emits exactly one machine-readable record');
        $decoded = json_decode(WP_CLI::$lines[0] ?? '', true);
        check(is_array($decoded), 'JSON refusal record decodes');
        return is_array($decoded) ? $decoded : [];
    }

    $cli = new \WPrism\Cli();

    echo "\n== env-set stdin framing preserves credential bytes ==\n";
    $maskedLineValue = new ReflectionMethod(\WPrism\Cli::class, 'masked_line_value');
    $maskedCases = [
        "  leading and trailing\t  \n" => "  leading and trailing\t  ",
        "  leading and trailing\t  \r\n" => "  leading and trailing\t  ",
        " \t \n" => " \t ",
        " \t \r\n" => " \t ",
        "credential\r\r\n" => "credential\r",
        "\n" => '',
        "\r\n" => '',
    ];
    foreach ($maskedCases as $framed => $expected) {
        check(
            $maskedLineValue->invoke(null, $framed) === $expected,
            'masked input removes exactly terminal LF and optional CR while preserving every other byte ('
                . strlen($expected) . '-byte value)'
        );
    }
    $maskedSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Command/Cli.php');
    check(
        str_contains($maskedSource, 'return self::masked_line_value($line);'),
        'the real masked stdin reader routes its complete line through the byte-preserving framing helper'
    );

    echo "\n== every primary JSON command owns a stable missing-argument refusal ==\n";
    $commands = [
        'compile' => 'compile',
        'code_preflight' => 'code-preflight',
        'code_stage' => 'code-stage',
        'code_finalize' => 'code-finalize',
        'init' => 'init',
        'adapter_observe' => 'adapter-observe',
        'scope' => 'scope',
        'capture' => 'capture',
        'plan' => 'plan',
        'explain' => 'explain',
        'apply' => 'apply',
        'deploy' => 'deploy',
        // issue #3397: refresh-export used a private JSON catch path and scope
        // refused its selectors before its boundary; both now answer a
        // machine caller with the same envelope every other command does.
        'refresh_export' => 'refresh-export',
        'scope' => 'scope',
        // issue #3399: the remaining eleven commands that advertise
        // --format=json all caught \Throwable straight into WP_CLI::error(),
        // and most gated their arguments outside the try as well, so a
        // machine caller got human stderr and ZERO records.  With these the
        // envelope set is closed: every --format=json command is here.
        // journal-report is deliberately absent — it has no required
        // argument, so it has no missing-argument refusal to assert; its
        // backend refusal is exercised on its own below.
        'promotion_begin' => 'promotion-begin',
        'promotion_abort' => 'promotion-abort',
        'promotion_begin_scoped' => 'promotion-begin-scoped',
        'promotion_complete_scoped' => 'promotion-complete-scoped',
        'env_set' => 'env-set',
        'orphans' => 'orphans',
        'verify_canonical' => 'verify-canonical',
        'pending' => 'pending',
        'coverage' => 'coverage',
        'classify' => 'classify',
        'lint' => 'lint',
        'capabilities' => 'capabilities',
    ];
    foreach ($commands as $method => $command) {
        $payload = invoke_json(
            static fn() => $cli->$method([], ['format' => 'json']),
            $command === 'verify-canonical'
        );
        check(($payload['format'] ?? null) === 'wprism-command-refusal/v1', "$command refusal names the versioned format");
        check(($payload['ok'] ?? null) === false, "$command refusal is unambiguously not ok");
        check(($payload['command'] ?? null) === $command, "$command refusal names the public command");
        check(($payload['error'] ?? null) === 'invalid_arguments', "$command refusal has a stable argument error code");
        check(($payload['reason_code'] ?? null) === $payload['error'], "$command refusal exposes the error as a finite reason code");
        // Each command's FIRST gate, in its own declaration order — the one a
        // caller invoking with nothing actually hits.
        $missing = match ($command) {
            'explain' => '<bucket>:<entity-key>',
            'orphans' => '<table>',
            'promotion-begin', 'promotion-abort',
            'promotion-begin-scoped', 'promotion-complete-scoped' => '--promotion-owner',
            default => '--repo',
        };
        check(($payload['message'] ?? null) === "$missing is required for $command", "$command refusal identifies the missing argument");
        check(is_string($payload['remediation'] ?? null) && $payload['remediation'] !== '', "$command refusal carries remediation");
    }

    $scopeRoots = invoke_json(static fn() => $cli->scope([], [
        'repo' => '/fixture',
        'format' => 'json',
    ]));
    check(($scopeRoots['command'] ?? null) === 'scope', 'scope secondary argument refusal stays on the scope contract');
    check(($scopeRoots['error'] ?? null) === 'invalid_arguments', 'scope missing roots has a stable argument error code');
    check(($scopeRoots['message'] ?? null) === '--roots is required for scope', 'scope refusal identifies the missing roots argument');

    $scopedView = invoke_json(static fn() => $cli->plan([], [
        'repo' => '/fixture',
        'scope-request-b64' => 'deliberately-not-decoded',
        'action' => 'create',
        'format' => 'json',
    ]));
    check(
        ($scopedView['command'] ?? null) === 'plan'
            && ($scopedView['reason_code'] ?? null) === 'plan_view_unavailable'
            && !str_contains(json_encode($scopedView), 'deliberately-not-decoded'),
        'direct agent plan typed-refuses scoped view flags before decoding or echoing scope evidence'
    );

    \WPrism\Apply::$planResult = ['plan_view' => [
        'format' => 'wprism-plan-view/v2',
        'page' => ['next_cursor' => null, 'has_more' => false],
        'rows' => [],
    ]];
    WP_CLI::reset();
    $cli->plan([], [
        'repo' => '/fixture',
        'action' => 'create',
        'limit' => '20',
        'view-only' => true,
        'format' => 'json',
    ]);
    $viewOnly = json_decode(WP_CLI::$lines[0] ?? '', true);
    check(
        ($viewOnly['format'] ?? null) === 'wprism-plan-view/v2'
            && array_keys($viewOnly) === ['format', 'page', 'rows']
            && array_key_exists('cursor', \WPrism\Apply::$lastPlanOptions['plan_view'] ?? [])
            && \WPrism\Apply::$lastPlanOptions['plan_view']['cursor'] === null,
        'plan --view-only emits only the bounded page while Apply still builds it through the real view request path'
    );
    $priorPlanOptions = \WPrism\Apply::$lastPlanOptions;
    $missingView = invoke_json(static fn() => $cli->plan([], [
        'repo' => '/fixture',
        'view-only' => true,
        'format' => 'json',
    ]));
    check(
        ($missingView['reason_code'] ?? null) === 'invalid_arguments'
            && \WPrism\Apply::$lastPlanOptions === $priorPlanOptions,
        'plan --view-only without a view selector refuses before plan execution'
    );
    \WPrism\Apply::$planResult = [];

    echo "\n== deliberately public gates keep stable diagnostics ==\n";
    \WPrism\Capture::$failure = new \WPrism\CommandRefusalException(
        'incomplete_state_discovery',
        'capture found state that has no reviewed classification',
        'review it with wprism pending, then classify or exclude it before another capture',
        [[
            'code' => 'unclassified_state',
            'surface' => 'options:acme_widget_color',
            'message' => 'state surface has no reviewed classification',
            'remediation' => 'review it with wprism pending, then classify or exclude it explicitly',
        ]],
        'wprism: operator-only path /private/repo contains unclassified option acme_widget_color'
    );
    $capture = invoke_json(static fn() => $cli->capture([], ['repo' => '/fixture', 'json' => true]));
    check(($capture['error'] ?? null) === 'incomplete_state_discovery', 'capture retains the source-owned refusal code');
    check(
        ($capture['diagnostics'][0]['surface'] ?? null) === 'options:acme_widget_color'
            && str_contains((string) ($capture['diagnostics'][0]['remediation'] ?? ''), 'classify'),
        'capture JSON keeps the exact state surface and per-finding remedy'
    );
    check(!str_contains((string) json_encode($capture), '/private/repo'), 'operator-only typed evidence is absent from JSON');

    $unsupportedDeletionFactory = new ReflectionMethod(\WPrism\Deletion::class, 'unsupported_capability_refusal');
    \WPrism\Capture::$failure = $unsupportedDeletionFactory->invoke(null, 'table:nf3_forms');
    $unsupportedDeletion = invoke_json(static fn() => $cli->capture([], ['repo' => '/fixture', 'format' => 'json']));
    check(
        ($unsupportedDeletion['format'] ?? null) === 'wprism-command-refusal/v1'
            && ($unsupportedDeletion['ok'] ?? null) === false
            && ($unsupportedDeletion['command'] ?? null) === 'capture',
        'unsupported deletion uses the primary capture refusal envelope'
    );
    check(
        ($unsupportedDeletion['error'] ?? null) === 'unsupported_deletion'
            && ($unsupportedDeletion['reason_code'] ?? null) === 'unsupported_deletion',
        'unsupported deletion retains its finite source-owned reason'
    );
    check(
        ($unsupportedDeletion['diagnostics'][0]['code'] ?? null) === 'unsupported_deletion'
            && ($unsupportedDeletion['diagnostics'][0]['surface'] ?? null) === 'table:nf3_forms',
        'unsupported deletion JSON identifies the exact generic selector'
    );
    check(
        !isset($unsupportedDeletion['details_redacted'])
            && !str_contains((string) json_encode($unsupportedDeletion), 'reverse-reference checks'),
        'safe selector evidence stays public while richer operator prose stays private'
    );

    \WPrism\Capture::$failure = new \WPrism\CommandRefusalException(
        'policy_refused',
        'policy rejected a reviewed field',
        'review the policy diagnostic',
        [[
            'code' => 'policy_refused',
            'surface' => 'options:sk_live_1234567890STRUCTUREDLEAK',
            'message' => 'policy rejected this field',
            'remediation' => 'review it privately',
        ]],
        'wprism: operator may inspect the private policy refusal'
    );
    $structuredRedaction = invoke_json(static fn() => $cli->capture([], ['repo' => '/fixture', 'format' => 'json']));
    check(($structuredRedaction['reason_code'] ?? null) === 'policy_refused', 'structured redaction retains the stable source reason');
    check(($structuredRedaction['details_redacted'] ?? null) === true, 'structured redaction records its witness');
    check(!array_key_exists('diagnostics', $structuredRedaction), 'sensitive structured diagnostics are removed as a whole');
    check(!str_contains((string) json_encode($structuredRedaction), 'STRUCTUREDLEAK'), 'sensitive structured fields are absent from JSON');

    $signedQueryShapes = [
        'AWS session token' => 'https://example.test/object?X-Amz-Security-Token=MUSTNOTLEAK',
        'Google signature' => 'https://example.test/object?X-Goog-Signature=MUSTNOTLEAK',
        'generic signature' => 'https://example.test/object?sig=MUSTNOTLEAK',
        'Postgres userinfo' => 'postgres://wprism:MUSTNOTLEAK@localhost/wordpress',
        'MySQL userinfo' => 'mysql://root:MUSTNOTLEAK@db:3306/wordpress',
        'SSH userinfo' => 'ssh://deploy:MUSTNOTLEAK@host/repository',
        'OAuth client secret' => 'https://example.test/callback?client_secret=MUSTNOTLEAK',
        'generic secret query' => 'https://example.test/callback?secret=MUSTNOTLEAK',
        'fragment access token' => 'https://example.test/callback#access_token=MUSTNOTLEAK',
        'OAuth authorization code' => 'https://example.test/callback?code=MUSTNOTLEAK',
        'native-app OAuth query' => 'com.example.app:/oauth2redirect?code=MUSTNOTLEAK',
        'custom-scheme OAuth fragment' => 'myapp:/oauth/callback#code=MUSTNOTLEAK',
        'opaque absolute URI' => 'urn:wprism:callback?code=MUSTNOTLEAK',
        'macOS private home' => '/Users/private-customer/MUSTNOTLEAK',
        'Linux private home' => '/home/private-customer/MUSTNOTLEAK',
        'Windows private home' => 'C:\\Users\\private-customer\\MUSTNOTLEAK',
        'UNC private home' => '\\\\fileserver\\Users\\private-customer\\MUSTNOTLEAK',
        'apostrophe Windows home' => "C:\\Users\\o'connor\\MUSTNOTLEAK",
        'at-sign UNC home' => '\\\\fileserver\\Users\\dev@example.test\\MUSTNOTLEAK',
        'Unicode Windows home' => 'C:\\Users\\শামীম\\MUSTNOTLEAK',
        'spaced Windows home' => 'C:\\Users\\Jane Doe\\MUSTNOTLEAK',
        'horizontal-tab control byte' => "reviewed\tMUSTNOTLEAK",
        'line-feed control byte' => "reviewed\nMUSTNOTLEAK",
        'carriage-return control byte' => "reviewed\rMUSTNOTLEAK",
    ];
    foreach ($signedQueryShapes as $shape => $signedUrl) {
        \WPrism\Capture::$failure = new \WPrism\CommandRefusalException(
            'provider_refused',
            'provider returned reviewed evidence',
            'inspect the provider diagnostic',
            [[
                'code' => 'provider_refused',
                'message' => $signedUrl,
                'remediation' => 'inspect privately',
            ]]
        );
        $signedRedaction = invoke_json(static fn() => $cli->capture([], ['repo' => '/fixture', 'format' => 'json']));
        check(($signedRedaction['details_redacted'] ?? null) === true, "$shape structured refusal is redacted");
        check(!str_contains((string) json_encode($signedRedaction), 'MUSTNOTLEAK'), "$shape value is absent from structured JSON");
    }

    // issue #3404 closes the prefix-as-authority hole globally. A raw Throwable
    // remains unreviewed operator evidence even when its message begins with
    // the human-facing `wprism: ` convention. Public machine evidence must come
    // from CommandRefusalException (or one of the established typed compiler
    // diagnostics), never from punctuation in arbitrary caught prose.
    \WPrism\Apply::$planFailure = new RuntimeException('wprism: target drift requires a fresh capture');
    $plan = invoke_json(static fn() => $cli->plan([], ['repo' => '/fixture', 'format' => 'json']));
    check(($plan['error'] ?? null) === 'plan_failed', 'plan keeps a raw wprism:-prefixed Throwable on the unclassified code');
    check(
        ($plan['details_redacted'] ?? null) === true
            && ($plan['message'] ?? null) === 'plan refused at an unclassified safety gate'
            && !str_contains((string) json_encode($plan), 'target drift requires'),
        'a wprism:-prefixed raw Throwable publishes none of its prose'
    );

    \WPrism\Capture::$failure = new RuntimeException(
        'wprism: deletion intent for table:nf3_forms is unsupported — no pinned adapter declares its reverse-reference checks and cascade effects'
    );
    $captureRefusal = invoke_json(static fn() => $cli->capture([], ['repo' => '/fixture', 'format' => 'json']));
    check(
        ($captureRefusal['error'] ?? null) === 'capture_failed'
            && ($captureRefusal['details_redacted'] ?? null) === true
            && !str_contains((string) json_encode($captureRefusal), 'table:nf3_forms')
            && !str_contains((string) json_encode($captureRefusal), 'reverse-reference checks'),
        'an untyped copy of the deletion prose is redacted instead of impersonating the typed contract'
    );
    check(
        ($captureRefusal['remediation'] ?? null)
            === 'inspect private operator evidence and capture recovery state; classify, correct, or recover the blocker before another attempt',
        'an unclassified capture refusal carries only the reviewed generic remediation'
    );

    \WPrism\Capture::$failure = new RuntimeException(
        'wprism: refusing capture — option sk_live_1234567890ABCDEFGHIJ looks like a live secret'
    );
    $secretRefusal = invoke_json(static fn() => $cli->capture([], ['repo' => '/fixture', 'format' => 'json']));
    check(
        ($secretRefusal['error'] ?? null) === 'capture_failed'
            && ($secretRefusal['details_redacted'] ?? null) === true
            && !str_contains((string) json_encode($secretRefusal), 'sk_live_1234567890ABCDEFGHIJ'),
        'a wprism:-prefixed refusal embedding a secret-shaped value stays on the same generic redacted shape'
    );

    // The `wprism: ` prefix is not proof of authorship: three wrapper families
    // re-prefix text the engine does not write. Each must stay redacted.
    foreach ([
        'wprism: required manifest action \'provider:probe/x\' failed — exit 1: PHP Warning with a payload tail',
        'wprism: batch regenerator \'woocommerce-product-lookups\' failed: wpdb said something with an option value in it',
        'wprism: deletion guard lock refused for post 7: guard query failed for wp_posts : Deadlock found MUSTNOTLEAK',
    ] as $wrapped) {
        \WPrism\Capture::$failure = new RuntimeException($wrapped);
        $wrappedRefusal = invoke_json(static fn() => $cli->capture([], ['repo' => '/fixture', 'format' => 'json']));
        check(
            ($wrappedRefusal['error'] ?? null) === 'capture_failed'
                && ($wrappedRefusal['details_redacted'] ?? null) === true
                && !str_contains((string) json_encode($wrappedRefusal), 'MUSTNOTLEAK')
                && !str_contains((string) json_encode($wrappedRefusal), 'payload tail'),
        'a wrapper-prefixed refusal carrying foreign text stays redacted: ' . substr($wrapped, 0, 40)
        );
    }

    \WPrism\Capture::$failure = new RuntimeException(
        "wprism: user-meta exact login 'privateperson' disappeared after preflight; transaction rolled back"
    );
    $loginRefusal = invoke_json(static fn() => $cli->capture([], ['repo' => '/fixture', 'format' => 'json']));
    check(
        ($loginRefusal['details_redacted'] ?? null) === true
            && !str_contains((string) json_encode($loginRefusal), 'privateperson'),
        'a refusal naming an exact login stays redacted — logins are in the redaction contract by name'
    );

    \WPrism\Capture::$failure = new RuntimeException(
        'wprism: canonical state at /var/www/site/state is not writable'
    );
    $pathRefusal = invoke_json(static fn() => $cli->capture([], ['repo' => '/fixture', 'format' => 'json']));
    check(
        ($pathRefusal['details_redacted'] ?? null) === true
            && !str_contains((string) json_encode($pathRefusal), '/var/www'),
        'a refusal embedding an absolute filesystem path stays redacted'
    );

    \WPrism\Capture::$failure = new RuntimeException('TypeError-shaped accident with no refusal prefix');
    $accident = invoke_json(static fn() => $cli->capture([], ['repo' => '/fixture', 'format' => 'json']));
    check(
        ($accident['error'] ?? null) === 'capture_failed'
            && ($accident['details_redacted'] ?? null) === true
            && !str_contains((string) json_encode($accident), 'TypeError-shaped'),
        'an unprefixed Throwable stays fully redacted under the same catch-all rule'
    );

    \WPrism\Apply::$applyFailure = \WPrism\CommandRefusalException::applyRefused(
        'scoped apply selected live tombstones but --with-deletes was not supplied; no scoped session or authored target mutation was created',
        'review the selected tombstones and rerun scoped apply with --with-deletes to authorize their removal',
        'wprism: scoped apply selected live tombstones but --with-deletes was not supplied; no scoped session or authored target mutation was created'
    );
    $scopedApplyRefusal = invoke_json(static fn() => $cli->apply([], ['repo' => '/fixture', 'format' => 'json']));
    check(
        ($scopedApplyRefusal['reason_code'] ?? null) === 'apply_refused'
            && str_contains((string) ($scopedApplyRefusal['message'] ?? ''), '--with-deletes')
            && ($scopedApplyRefusal['details_redacted'] ?? false) === false,
        'known value-free scoped apply preconditions retain actionable typed machine guidance'
    );

    $forcedEntityHash = hash('sha256', 'private-option-or-user-identity');
    \WPrism\Apply::$applyFailure = new \WPrism\CommandRefusalException(
        'apply_forced_override_failed',
        'apply failed after explicit plan conflict overrides were authorized',
        'inspect private operator evidence and apply recovery state; reconcile the failed gate before another attempt and do not assume the authorized override committed',
        [],
        'Warning: FORCED conflict private-option-or-user-identity\nwprism: later provider detail stays operator-only',
        new RuntimeException('wprism: later provider detail stays operator-only'),
        [[
            'format' => 'wprism-forced-plan-override/v1',
            'plan_bucket' => 'conflict',
            'entity_identity_sha256' => $forcedEntityHash,
            'conflict_kind' => 'concurrent_change',
            'reason_code' => 'option_delete_and_target_changed_since_base',
            'choice' => 'apply_repository',
            'effect' => 'replace_target_authored_state',
            'required_flags' => ['--with-deletes', '--force-theirs'],
            'supplied_flags' => ['--with-deletes', '--force-theirs'],
            'status' => 'authorized',
        ]]
    );
    $forcedApply = invoke_json(static fn() => $cli->apply([], ['repo' => '/fixture', 'format' => 'json']));
    check(($forcedApply['error'] ?? null) === 'apply_forced_override_failed', 'forced apply failure has a stable typed refusal code');
    check(
        ($forcedApply['forced_overrides'][0]['entity_identity_sha256'] ?? null) === $forcedEntityHash
            && ($forcedApply['forced_overrides'][0]['status'] ?? null) === 'authorized',
        'forced apply failure retains reviewed structured override evidence for machine callers'
    );
    check(
        !str_contains((string) json_encode($forcedApply), 'private-option-or-user-identity')
            && !str_contains((string) json_encode($forcedApply), 'provider detail'),
        'forced apply failure JSON omits raw entity and later runtime details'
    );
    \WPrism\Apply::$applyFailure = new \WPrism\CommandRefusalException(
        'apply_forced_override_failed',
        'apply failed after explicit plan conflict overrides were authorized',
        'inspect private operator evidence before another attempt',
        [],
        'wprism: private operator detail',
        null,
        [['entity_identity_sha256' => 'sk_live_1234567890FORCEDLEAK']]
    );
    $forcedRedaction = invoke_json(static fn() => $cli->apply([], ['repo' => '/fixture', 'format' => 'json']));
    check(($forcedRedaction['details_redacted'] ?? null) === true, 'sensitive forced override evidence activates the final redaction guard');
    check(!array_key_exists('forced_overrides', $forcedRedaction), 'sensitive forced override evidence is omitted as a whole');
    check(!str_contains((string) json_encode($forcedRedaction), 'FORCEDLEAK'), 'sensitive forced override bytes are absent from JSON');

    \WPrism\Capture::$failure = \WPrism\CommandRefusalException::ambiguousCaptureRecovery(
        'wprism: operator-only malformed database commit marker detail'
    );
    $recovery = invoke_json(static fn() => $cli->capture([], ['repo' => '/fixture', 'format' => 'json']));
    check(($recovery['error'] ?? null) === 'capture_recovery_ambiguous', 'known capture recovery ambiguity retains its stable source code');
    check(str_contains((string) ($recovery['remediation'] ?? ''), 'do not retry or discard'), 'known capture recovery ambiguity preserves its no-retry/no-discard remedy');
    check(!str_contains((string) json_encode($recovery), 'malformed database commit marker'), 'capture recovery JSON omits operator-only marker evidence');

    echo "\n== typed diagnostics remain intact inside the common envelope ==\n";
    \WPrism\Apply::$planFailure = new \WPrism\RepositoryCompilationException([[
        'severity' => 'error',
        'code' => 'conflict_marker',
        'path' => 'state/posts/page/example.md',
        'locator' => 'body',
        'message' => 'Git conflict marker remains',
    ]]);
    $typed = invoke_json(static fn() => $cli->plan([], ['repo' => '/fixture', 'format' => 'json']));
    check(($typed['error'] ?? null) === 'repository_compilation_failed', 'typed compiler error code is backward-compatible');
    check(
        ($typed['diagnostics'][0]['code'] ?? null) === 'conflict_marker'
            && ($typed['diagnostics'][0]['path'] ?? null) === 'state/posts/page/example.md',
        'typed compiler diagnostics retain their exact code and path'
    );
    check(($typed['command'] ?? null) === 'plan', 'typed diagnostics also identify their public command');

    $typedSensitiveShapes = [
        'hard token' => 'malformed reference sk_live_1234567890TYPEDLEAK',
        'AWS signed query' => 'https://example.test/object?X-Amz-Security-Token=TYPEDLEAK',
        'Google signed query' => 'https://example.test/object?X-Goog-Signature=TYPEDLEAK',
        'generic signed query' => 'https://example.test/object?sig=TYPEDLEAK',
        'Postgres credential URL' => 'postgres://wprism:TYPEDLEAK@localhost/wordpress',
        'MySQL credential URL' => 'mysql://root:TYPEDLEAK@db:3306/wordpress',
        'SSH credential URL' => 'ssh://deploy:TYPEDLEAK@host/repository',
        'OAuth client secret' => 'https://example.test/callback?client_secret=TYPEDLEAK',
        'generic secret query' => 'https://example.test/callback?secret=TYPEDLEAK',
        'fragment access token' => 'https://example.test/callback#access_token=TYPEDLEAK',
        'OAuth authorization code' => 'https://example.test/callback?code=TYPEDLEAK',
        'native-app OAuth query' => 'com.example.app:/oauth2redirect?code=TYPEDLEAK',
        'custom-scheme OAuth fragment' => 'myapp:/oauth/callback#code=TYPEDLEAK',
        'opaque absolute URI' => 'urn:wprism:callback?code=TYPEDLEAK',
        'macOS private home' => '/Users/private-customer/TYPEDLEAK',
        'Linux private home' => '/home/private-customer/TYPEDLEAK',
        'Windows private home' => 'C:\\Users\\private-customer\\TYPEDLEAK',
        'UNC private home' => '\\\\fileserver\\Users\\private-customer\\TYPEDLEAK',
        'apostrophe Windows home' => "C:\\Users\\o'connor\\TYPEDLEAK",
        'at-sign UNC home' => '\\\\fileserver\\Users\\dev@example.test\\TYPEDLEAK',
        'Unicode Windows home' => 'C:\\Users\\শামীম\\TYPEDLEAK',
        'spaced Windows home' => 'C:\\Users\\Jane Doe\\TYPEDLEAK',
        'horizontal-tab control byte' => "reviewed\tTYPEDLEAK",
        'line-feed control byte' => "reviewed\nTYPEDLEAK",
        'carriage-return control byte' => "reviewed\rTYPEDLEAK",
    ];
    foreach ($typedSensitiveShapes as $shape => $diagnosticMessage) {
        \WPrism\Apply::$planFailure = new \WPrism\RepositoryCompilationException([[
            'severity' => 'error',
            'code' => 'malformed_reference',
            'path' => 'state/options/core.json',
            'locator' => 'records.fixture',
            'message' => $diagnosticMessage,
        ]]);
        $typedRedaction = invoke_json(static fn() => $cli->plan([], ['repo' => '/fixture', 'format' => 'json']));
        check(($typedRedaction['error'] ?? null) === 'repository_compilation_failed', "$shape typed refusal retains its stable error");
        check(($typedRedaction['details_redacted'] ?? null) === true, "$shape typed refusal records redaction");
        check(!array_key_exists('diagnostics', $typedRedaction), "$shape typed diagnostic batch is omitted as a whole");
        check(!str_contains((string) json_encode($typedRedaction), 'TYPEDLEAK'), "$shape typed diagnostic bytes are absent");
    }

    echo "\n== unclassified Throwable details never enter machine output ==\n";
    $secretMessages = [
        'hard token' => 'provider failed with sk_live_1234567890MUSTNOTLEAK',
        'credential URL' => 'provider failed at https://refusal-user:refusal-password@example.test/path',
        'signed query' => 'provider failed at https://example.test/path?X-Amz-Signature=MUSTNOTLEAK',
        'personal login and path' => 'failure for admin@example.test at /Users/private-customer/site',
    ];
    foreach ($secretMessages as $shape => $secretMessage) {
        \WPrism\Capture::$failure = new RuntimeException($secretMessage);
        $redacted = invoke_json(static fn() => $cli->capture([], ['repo' => '/fixture', 'format' => 'json']));
        $redactedBytes = json_encode($redacted, JSON_UNESCAPED_SLASHES);
        check(($redacted['details_redacted'] ?? null) === true, "$shape failure records an explicit redaction witness");
        check(!str_contains((string) $redactedBytes, $secretMessage), "$shape exception bytes are absent from JSON");
        check(($redacted['message'] ?? null) === 'capture refused at an unclassified safety gate', "$shape gets the constant safe message");
    }

    echo "\n== a redacted refusal writes its sentence privately under <repo>/.wprism/refusals/ ==\n";
    // "inspect private operator evidence" has to point somewhere: the host
    // runs the agent in --format=json for a rehearsal's promotion apply, so
    // the operator never had a human-mode sentence to reread (grind_adoption
    // A6 lost two rehearsals to a bare `apply_failed`). The envelope stays
    // byte-identical; the record lives beside the promotion checkpoints.
    $evidenceRepo = sys_get_temp_dir() . '/wprism-cli-json-refusal-evidence-' . bin2hex(random_bytes(6));
    mkdir($evidenceRepo, 0700, true);
    // issue #3516: the record goes to a directory that is ALREADY a WPrism
    // repository. It used to be written into whatever the raw --repo string
    // named, creating .wprism/ on the way -- which, after an identity-gate
    // refusal, planted WPrism state in an unrelated operator directory (and
    // through a swapped-in symlink, outside the repository entirely). This
    // fixture therefore carries the marker a real repository has.
    file_put_contents($evidenceRepo . '/site.wprism.json', "{}\n");
    $evidenceCause = new RuntimeException('provider capability failed: X-Amz-Signature=EVIDENCECAUSE');
    \WPrism\Capture::$failure = new RuntimeException(
        'wprism: required manifest action failed with sk_live_EVIDENCESENTENCE',
        0,
        $evidenceCause
    );
    $withEvidence = invoke_json(static fn() => $cli->capture([], ['repo' => $evidenceRepo, 'format' => 'json']));
    check(($withEvidence['details_redacted'] ?? null) === true, 'the redacted envelope is unchanged by evidence recording');
    check(!str_contains((string) json_encode($withEvidence), 'EVIDENCE'), 'evidence recording publishes nothing new in the envelope');
    check(!array_key_exists('private_evidence', $withEvidence), 'the envelope carries no evidence path (byte-identical contract)');
    $evidenceFiles = glob($evidenceRepo . '/.wprism/refusals/*-capture-*.json') ?: [];
    check(count($evidenceFiles) === 1, 'exactly one private evidence record is written under <repo>/.wprism/refusals/');
    $evidenceRecord = json_decode((string) file_get_contents($evidenceFiles[0] ?? ''), true);
    check(($evidenceRecord['format'] ?? null) === 'wprism-private-refusal-evidence/v1', 'the record names its private format');
    check(($evidenceRecord['command'] ?? null) === 'capture' && ($evidenceRecord['reason_code'] ?? null) === 'capture_failed', 'the record binds command and reason code');
    check(($evidenceRecord['throwable'][0]['message'] ?? null) === 'wprism: required manifest action failed with sk_live_EVIDENCESENTENCE', 'the record carries the primary sentence verbatim');
    check(($evidenceRecord['throwable'][1]['message'] ?? null) === 'provider capability failed: X-Amz-Signature=EVIDENCECAUSE', 'the record carries the cause chain');
    check(($evidenceRecord['throwable'][0]['class'] ?? null) === 'RuntimeException' && is_int($evidenceRecord['throwable'][0]['line'] ?? null), 'the record names class and origin line');
    check((fileperms($evidenceFiles[0]) & 0777) === 0600, 'the record is private (0600) like every other .wprism/ artifact');
    // The typed-diagnostic redaction and the final defense pass are the same
    // contract: any details_redacted envelope leaves a record.
    \WPrism\Apply::$planFailure = new \WPrism\RepositoryCompilationException([[
        'severity' => 'error', 'code' => 'malformed_reference', 'path' => 'state/options/core.json',
        'locator' => 'records.fixture', 'message' => "reviewed\tTYPEDEVIDENCE",
    ]]);
    invoke_json(static fn() => $cli->plan([], ['repo' => $evidenceRepo, 'format' => 'json']));
    check(count(glob($evidenceRepo . '/.wprism/refusals/*-plan-*.json') ?: []) === 1, 'a redacted typed-diagnostic refusal also leaves a private record');
    // No repository, no record — and never a second failure.
    \WPrism\Capture::$failure = new RuntimeException('wprism: refused with sk_live_NOREPO');
    $withoutRepo = invoke_json(static fn() => $cli->capture([], ['repo' => '/fixture-does-not-exist', 'format' => 'json']));
    check(($withoutRepo['details_redacted'] ?? null) === true, 'a missing repository still yields the redacted envelope');
    check(!is_dir('/fixture-does-not-exist'), 'no repository is conjured to hold evidence');

    // issue #3516: an ordinary directory that is NOT a WPrism repository gets
    // nothing — the negative case the live swap-directory failure was.
    $strangerDir = sys_get_temp_dir() . '/wprism-cli-json-refusal-stranger-' . bin2hex(random_bytes(6));
    mkdir($strangerDir, 0700, true);
    \WPrism\Capture::$failure = new RuntimeException('wprism: refused with sk_live_STRANGER');
    $inStranger = invoke_json(static fn() => $cli->capture([], ['repo' => $strangerDir, 'format' => 'json']));
    check(($inStranger['details_redacted'] ?? null) === true, 'a non-repository directory still yields the redacted envelope');
    check(!is_dir($strangerDir . '/.wprism'), 'and no .wprism/ is created inside a directory that is not a WPrism repository');
    check(scandir($strangerDir) === ['.', '..'], 'the stranger directory is left exactly as it was found');
    @rmdir($strangerDir);

    // A real `.wprism/` alone qualifies it, beside the promotion checkpoints the
    // record was always meant to sit next to — no site.wprism.json required.
    $checkpointRepo = sys_get_temp_dir() . '/wprism-cli-json-refusal-checkpoints-' . bin2hex(random_bytes(6));
    mkdir($checkpointRepo . '/.wprism/checkpoints', 0700, true);
    \WPrism\Capture::$failure = new RuntimeException('wprism: refused with sk_live_CHECKPOINTS');
    invoke_json(static fn() => $cli->capture([], ['repo' => $checkpointRepo, 'format' => 'json']));
    check(
        count(glob($checkpointRepo . '/.wprism/refusals/*-capture-*.json') ?: []) === 1,
        'an existing .wprism/ qualifies a repository even with no site.wprism.json'
    );

    // A symlinked repository root is never followed, whatever sits behind it —
    // the live symlink swap case, where the target even carried a poisoned
    // site.wprism.json.
    $linkTarget = sys_get_temp_dir() . '/wprism-cli-json-refusal-external-' . bin2hex(random_bytes(6));
    mkdir($linkTarget, 0700, true);
    file_put_contents($linkTarget . '/site.wprism.json', "{poisoned\n");
    $linkPath = sys_get_temp_dir() . '/wprism-cli-json-refusal-link-' . bin2hex(random_bytes(6));
    symlink($linkTarget, $linkPath);
    \WPrism\Capture::$failure = new RuntimeException('wprism: refused with sk_live_SYMLINK');
    invoke_json(static fn() => $cli->capture([], ['repo' => $linkPath, 'format' => 'json']));
    check(!is_dir($linkTarget . '/.wprism'), 'a symlinked repository root is not followed, even to a directory holding a site.wprism.json');

    // init is held to one extra condition: the directory must STILL be the one
    // it started against. That is the live failure's exact shape -- the root is
    // replaced between the lease and the refusal -- so it is asserted against
    // the predicate directly rather than through a stubbed init transaction.
    $gate = new ReflectionMethod(\WPrism\Cli::class, 'refusal_evidence_repository');
    $snapshot = new ReflectionProperty(\WPrism\Cli::class, 'initRepositoryIdentityAtEntry');
    $identityOf = new ReflectionMethod(\WPrism\Cli::class, 'directory_identity');

    $snapshot->setValue(null, null);
    check(
        $gate->invoke(null, $evidenceRepo, 'init') === false,
        'init records nothing when it never snapshotted a repository identity'
    );
    check(
        $gate->invoke(null, $evidenceRepo, 'capture') === true,
        'while every other command still records into the same WPrism repository'
    );

    $snapshot->setValue(null, $identityOf->invoke(null, $evidenceRepo));
    (new ReflectionProperty(\WPrism\Cli::class, 'initRepositoryWasWPrismAtEntry'))->setValue(null, true);
    check(
        $gate->invoke(null, $evidenceRepo, 'init') === true,
        'init records while the directory it started against is still the one at that path'
    );

    // issue #3522: the moment the "already a WPrism repository?" question is asked.
    // init is the command that CREATES that marker, so asking at refusal time
    // let a half-published init answer its own question: it published
    // site.wprism.json, failed during capture, recorded `.wprism/refusals`, and left
    // it behind -- the rollback has no deletion authority over `.wprism`, so the
    // repository was not byte-empty after a recovery that correctly reported it
    // restored. Both facts are therefore snapshotted at ENTRY.
    // Driven through Cli::init() itself, not through the predicate: the whole
    // defect was WHEN the question gets asked, and only the real entry point
    // takes the entry-time snapshots.
    $freshRepo = sys_get_temp_dir() . '/wprism-cli-json-refusal-fresh-init-' . bin2hex(random_bytes(6));
    mkdir($freshRepo, 0700, true);
    \WPrism\Init::$onCall = static function () use ($freshRepo): void {
        // The half-published init: the marker exists by the time it fails.
        file_put_contents($freshRepo . '/site.wprism.json', "{}\n");
    };
    \WPrism\Init::$failure = new RuntimeException('wprism: init refused with sk_live_FRESHINIT');
    $freshInit = invoke_json(static fn() => $cli->init([], ['repo' => $freshRepo, 'format' => 'json']));
    check(($freshInit['details_redacted'] ?? null) === true, 'the half-published init still yields the redacted envelope');
    check(
        !is_dir($freshRepo . '/.wprism'),
        'a fresh init records nothing even after it has published site.wprism.json mid-command'
    );
    check(
        is_file($freshRepo . '/site.wprism.json'),
        'and the marker it published is left alone -- the recorder skips, it does not clean up after init'
    );

    // Entered against a repository that was ALREADY real: still records, which
    // is the interrupted-attempt refusal actually worth reading.
    \WPrism\Init::$onCall = null;
    \WPrism\Init::$failure = new RuntimeException('wprism: init refused with sk_live_EXISTINGINIT');
    invoke_json(static fn() => $cli->init([], ['repo' => $freshRepo, 'format' => 'json']));
    check(
        count(glob($freshRepo . '/.wprism/refusals/*-init-*.json') ?: []) === 1,
        'an init entered against an existing WPrism repository still records'
    );
    \WPrism\Init::$failure = null;
    foreach (glob($freshRepo . '/.wprism/refusals/*') ?: [] as $f) unlink($f);
    @rmdir($freshRepo . '/.wprism/refusals'); @rmdir($freshRepo . '/.wprism');
    @unlink($freshRepo . '/site.wprism.json'); @rmdir($freshRepo);

    // The live case: same path, different inode.
    $swapped = sys_get_temp_dir() . '/wprism-cli-json-refusal-swapped-' . bin2hex(random_bytes(6));
    $reviewed = $swapped . '-reviewed';
    mkdir($swapped, 0700, true);
    file_put_contents($swapped . '/site.wprism.json', "{}\n");
    $snapshot->setValue(null, $identityOf->invoke(null, $swapped));
    rename($swapped, $reviewed);
    mkdir($swapped, 0700, true);
    file_put_contents($swapped . '/site.wprism.json', "{}\n");
    check(
        $gate->invoke(null, $swapped, 'init') === false,
        'and refuses once that path names a different directory, even one that is itself a WPrism repository'
    );
    $snapshot->setValue(null, null);
    @unlink($swapped . '/site.wprism.json'); @rmdir($swapped);
    @unlink($reviewed . '/site.wprism.json'); @rmdir($reviewed);

    foreach (glob($evidenceRepo . '/.wprism/refusals/*') ?: [] as $f) unlink($f);
    @rmdir($evidenceRepo . '/.wprism/refusals'); @rmdir($evidenceRepo . '/.wprism');
    @unlink($evidenceRepo . '/site.wprism.json'); @rmdir($evidenceRepo);
    foreach (glob($checkpointRepo . '/.wprism/refusals/*') ?: [] as $f) unlink($f);
    @rmdir($checkpointRepo . '/.wprism/refusals'); @rmdir($checkpointRepo . '/.wprism/checkpoints');
    @rmdir($checkpointRepo . '/.wprism'); @rmdir($checkpointRepo);
    @unlink($linkPath); @unlink($linkTarget . '/site.wprism.json'); @rmdir($linkTarget);

    echo "\n== issue #3397: refresh-export and scope answer machines with the same envelope ==\n";
    $productLeakShapes = [
        'hard token' => 'sk_live_1234567890PRODUCTLEAK',
        'credential URL' => 'https://user:SECRETPASS@db.example/production',
        'private path' => '/Users/private-customer/sites/production/site.wprism.json',
        'distinctive operator token' => 'PRODUCTLEAK-0f9c2d41',
    ];
    foreach ($productLeakShapes as $shape => $token) {
        $refreshOperator = "wprism: refresh export refused while observing production $token";
        \WPrism\RefreshExport::$failure = new RuntimeException($refreshOperator);
        $refresh = invoke_json(static fn() => $cli->refresh_export([], ['repo' => '/fixture', 'format' => 'json']));
        $refreshBytes = (string) json_encode($refresh, JSON_UNESCAPED_SLASHES);
        check(($refresh['format'] ?? null) === 'wprism-command-refusal/v1', "refresh-export $shape refusal names the versioned format");
        check(($refresh['ok'] ?? null) === false, "refresh-export $shape refusal is unambiguously not ok");
        check(($refresh['command'] ?? null) === 'refresh-export', "refresh-export $shape refusal names the public command");
        check(
            ($refresh['error'] ?? null) === 'refresh_export_failed'
                && ($refresh['reason_code'] ?? null) === 'refresh_export_failed',
            "refresh-export $shape refusal keeps the established refresh_export_failed code"
        );
        check(
            ($refresh['message'] ?? null) === 'refresh-export refused at an unclassified safety gate',
            "refresh-export $shape refusal states only the constant safe message"
        );
        check(
            str_contains((string) ($refresh['remediation'] ?? ''), 'before observing production again'),
            "refresh-export $shape refusal carries this command's reviewed remediation"
        );
        check(($refresh['details_redacted'] ?? null) === true, "refresh-export $shape refusal records an explicit redaction witness");
        check(
            !str_contains($refreshBytes, $token) && !str_contains($refreshBytes, $refreshOperator),
            "refresh-export $shape bytes are absent from machine output"
        );

        $scopeOperator = "wprism: scope refused while compiling $token";
        \WPrism\Policy::$failure = new RuntimeException($scopeOperator);
        $scope = invoke_json(static fn() => $cli->scope([], ['repo' => '/fixture', 'roots' => 'all', 'format' => 'json']));
        $scopeBytes = (string) json_encode($scope, JSON_UNESCAPED_SLASHES);
        check(($scope['format'] ?? null) === 'wprism-command-refusal/v1', "scope $shape refusal names the versioned format");
        check(($scope['command'] ?? null) === 'scope', "scope $shape refusal names the public command");
        check(
            ($scope['error'] ?? null) === 'scope_failed' && ($scope['reason_code'] ?? null) === 'scope_failed',
            "scope $shape refusal has a stable unclassified reason code"
        );
        check(($scope['details_redacted'] ?? null) === true, "scope $shape refusal records an explicit redaction witness");
        check(
            !str_contains($scopeBytes, $token) && !str_contains($scopeBytes, $scopeOperator),
            "scope $shape bytes are absent from machine output"
        );
    }

    // explain declares a stricter value-free posture than any other command
    // (its catch: "never forwards exception text ... merely because JSON was
    // not requested") — the allowlist keeps its JSON channel no more
    // revealing than its human one.
    \WPrism\Apply::$planFailure = new RuntimeException('wprism: explain refused — plan unavailable');
    $explainRefusal = invoke_json(static fn() => $cli->explain(['post:x'], ['repo' => '/fixture', 'format' => 'json']));
    \WPrism\Apply::$planFailure = null;
    check(
        ($explainRefusal['details_redacted'] ?? null) === true
            && !str_contains((string) json_encode($explainRefusal), 'plan unavailable'),
        'explain\'s wprism: refusal stays redacted — a command absent from the allowlist publishes nothing'
    );

    // The observation-command exclusion is the reason, not the token
    // patterns: a VALUE-FREE wprism:-prefixed refresh-export refusal must also
    // stay redacted, or the exclusion is decorative and the next value-free-
    // looking production identifier leaks.
    \WPrism\RefreshExport::$failure = new RuntimeException('wprism: refresh export refused — an apply is in progress');
    $valueFree = invoke_json(static fn() => $cli->refresh_export([], ['repo' => '/fixture', 'format' => 'json']));
    check(
        ($valueFree['error'] ?? null) === 'refresh_export_failed'
            && ($valueFree['details_redacted'] ?? null) === true
            && !str_contains((string) json_encode($valueFree), 'apply is in progress'),
        'a value-free wprism: refusal from an OBSERVATION command stays redacted — the command exclusion is load-bearing, not the patterns'
    );

    // wp-cli rewrites a bare --json into format=json, but refresh-export
    // derives its own $format from both spellings, so both must reach the
    // same formatter rather than only the one the dispatcher happens to use.
    \WPrism\RefreshExport::$failure = new RuntimeException('wprism: refresh export refused with sk_live_1234567890BARELEAK');
    $bareJson = invoke_json(static fn() => $cli->refresh_export([], ['repo' => '/fixture', 'json' => true]));
    check(($bareJson['reason_code'] ?? null) === 'refresh_export_failed', 'refresh-export --json spelling reaches the common formatter');
    check(!str_contains((string) json_encode($bareJson), 'BARELEAK'), 'refresh-export --json spelling redacts the same bytes');

    // refresh-export runs the real capture builder, so its reviewed typed
    // refusals must survive the change instead of flattening to the
    // unclassified code.
    \WPrism\RefreshExport::$failure = new \WPrism\CommandRefusalException(
        'incomplete_state_discovery',
        'refresh export found state that has no reviewed classification',
        'review it with wprism pending, then classify or exclude it before observing production again',
        [[
            'code' => 'unclassified_state',
            'surface' => 'options:acme_widget_color',
            'message' => 'state surface has no reviewed classification',
            'remediation' => 'review it with wprism pending, then classify or exclude it explicitly',
        ]],
        'wprism: operator-only path /Users/private-customer/site holds unclassified option acme_widget_color'
    );
    $typedRefresh = invoke_json(static fn() => $cli->refresh_export([], ['repo' => '/fixture', 'format' => 'json']));
    check(($typedRefresh['error'] ?? null) === 'incomplete_state_discovery', 'known typed refresh-export refusal retains its reviewed reason code');
    check(
        ($typedRefresh['remediation'] ?? null) === 'review it with wprism pending, then classify or exclude it before observing production again',
        'known typed refresh-export refusal retains its reviewed remediation'
    );
    check(
        ($typedRefresh['diagnostics'][0]['surface'] ?? null) === 'options:acme_widget_color',
        'known typed refresh-export refusal keeps its reviewed diagnostic'
    );
    check(!str_contains((string) json_encode($typedRefresh), 'private-customer'), 'known typed refresh-export refusal omits operator-only evidence');
    \WPrism\RefreshExport::$failure = null;

    // The selector gates themselves: scope refused --roots before its catch
    // boundary, so a machine caller got human stderr and no record at all.
    \WPrism\Policy::$failure = null;
    $missingRoots = invoke_json(static fn() => $cli->scope([], ['repo' => '/fixture', 'format' => 'json']));
    check(($missingRoots['command'] ?? null) === 'scope', 'scope missing --roots refusal names the public command');
    check(($missingRoots['error'] ?? null) === 'invalid_arguments', 'scope missing --roots has the stable argument error code');
    check(($missingRoots['message'] ?? null) === '--roots is required for scope', 'scope missing --roots identifies the missing selector');
    check(
        str_contains((string) ($missingRoots['remediation'] ?? ''), '--roots=all'),
        'scope missing --roots keeps the whole-revision hint in machine remediation'
    );

    // scope's unclassified remediation must be its reviewed arm, not the
    // default "correct the named $command blocker" — details are redacted on
    // this path, so nothing IS named and the default contradicts itself.
    \WPrism\Policy::$failure = new RuntimeException('wprism: scope refused while compiling /Users/private-customer/site');
    $scopeArm = invoke_json(static fn() => $cli->scope([], ['repo' => '/fixture', 'roots' => 'all', 'format' => 'json']));
    check(
        ($scopeArm['remediation'] ?? null) === 'inspect private operator evidence, then compile the revision or correct the root selectors before resolving scope again',
        'scope unclassified refusal carries its reviewed remediation arm'
    );

    // --contract is scope's machine-evidence mode and the mode whose gate
    // ordering moved the most; its JSON failure must reach the same formatter.
    $contractScope = invoke_json(static fn() => $cli->scope([], ['repo' => '/fixture', 'roots' => 'all', 'contract' => true, 'format' => 'json']));
    check(($contractScope['format'] ?? null) === 'wprism-command-refusal/v1', 'scope --contract JSON failure names the versioned format');
    check(($contractScope['command'] ?? null) === 'scope', 'scope --contract JSON failure names the public command');
    check(!str_contains((string) json_encode($contractScope, JSON_UNESCAPED_SLASHES), 'private-customer'), 'scope --contract JSON failure redacts operator bytes');
    \WPrism\Policy::$failure = null;

    echo "\n== issue #3399: the envelope set is closed over every --format=json command ==\n";
    // The contract sentence in spec/repo-format.md and cli/README.md stopped
    // enumerating commands and now says "every command that advertises
    // --format=json".  That is only true while it is structurally true, so
    // assert it against the source rather than against a list this file would
    // have to be remembered to update.
    $cliSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Command/Cli.php');
    preg_match_all('/\/\*\*(.*?)\*\/\s*public function (\w+)\((.*?)\n    \}/s', $cliSource, $handlers, PREG_SET_ORDER);
    check(count($handlers) >= 22, 'source scan found the product command handlers');
    $advertised = [];
    foreach ($handlers as [, $doc, $method, $body]) {
        if (!str_contains($doc, '--format=<format>')) {
            continue;
        }
        // Resolve the name exactly as WP-CLI does -- the @subcommand tag, else
        // the RAW method name. This used to hyphenate the fallback, which let a
        // handler whose envelope names `code-inventory` pass while WP-CLI had
        // registered it as `code_inventory` (issue #3517).
        $command = preg_match('/@subcommand\s+(\S+)/', $doc, $sub) === 1
            ? $sub[1]
            : $method;
        $advertised[] = $command;
        check(
            str_contains($body, "self::halt_json_failure(\$t, \$assoc, '$command')"),
            "$command advertises --format=json and routes its refusals through the common envelope"
        );
    }
    // Deliberately two-sided: a NEW --format=json command arriving without an
    // envelope fails the per-command check above, and DROPPING an
    // advertisement (or a handler) fails this count instead of silently
    // shrinking the set the contract sentence claims is closed.
    // 27 with issue #3326's `code-preflight` plus scoped promotion's two
    // orchestrator-only handoff commands; 28 with round-3 MUP §4.5's
    // `assess-inventory`; 29 with issue #3499's read-only `code-inventory`, which
    // reports one repository's lockable code components for `wprism code-classify`;
    // 30 with `adapter-probe`, the read-only live-schema half `wprism adapter-draft
    // --evidence=` consumes; 31 with WP-3.2's report-only `effect-coverage`,
    // whose only refusals are the journal prerequisite and manifest resolution
    // — a scoring verdict is never one, which is the point of that command;
    // 32 with WP-2.5's `adapter-deletion-feasibility`, which answers
    // DeleteGuardEvaluator::lock_index() for a PROPOSED deletion selector's
    // guards at authoring time; 33 with the asynchronous `lifecycle-settle`
    // completion gate.
    // Every advertised handler is covered by the common envelope contract, so
    // this count moves with the set rather than around it.
    check(count($advertised) === 33, 'every one of the 33 --format=json commands was scanned (' . count($advertised) . ')');

    // Each newly enveloped command got a reviewed remediation arm, because the
    // default arm promises to "correct the named blocker" on exactly the path
    // that redacts every name.  A command silently falling back to it is the
    // regression this closes.
    $armed = new ReflectionMethod(\WPrism\Cli::class, 'refusal_remediation');
    foreach ([
        'code-preflight', 'promotion-begin', 'promotion-abort', 'promotion-begin-scoped',
        'promotion-complete-scoped', 'env-set', 'orphans', 'verify-canonical',
        'journal-report', 'effect-coverage', 'pending', 'coverage', 'classify', 'lint', 'capabilities',
    ] as $command) {
        $arm = (string) $armed->invoke(null, $command);
        check(
            $arm !== "correct the named $command blocker, then retry the command" && $arm !== '',
            "$command carries a reviewed remediation arm, not the self-contradicting default"
        );
    }

    echo "\n== issue #3404: no command inherits prefix-based publication authority ==\n";
    // The allowlist and its message-shape helper no longer exist. Typed
    // refusals above retain their public contract; every raw Throwable takes
    // the same redacted catch-all path regardless of command or prose.
    $cliReflection = new ReflectionClass(\WPrism\Cli::class);
    check(
        !$cliReflection->hasConstant('PUBLIC_REFUSAL_COMMANDS')
            && !$cliReflection->hasMethod('publishable_refusal'),
        'the prefix publication constant and helper are absent'
    );
    $testSource = file_get_contents(__FILE__);
    $retiredCommandVariableTokens = is_string($testSource)
        ? array_values(array_filter(
            token_get_all($testSource),
            static fn($token): bool => is_array($token)
                && $token[0] === T_VARIABLE
                && $token[1] === '$publicRefusalCommands'
        ))
        : [['test source unreadable']];
    check(
        $retiredCommandVariableTokens === [],
        'the regression source does not recreate the retired command-allowlist variable'
    );
    // Live coverage on two additional command paths proves the rule is not a
    // plan/capture special case. The message is deliberately value-free and
    // `wprism: `-prefixed, so only the absence of prefix authority keeps it out.
    foreach (['lint' => 'lint', 'capabilities' => 'capabilities'] as $method => $command) {
        $valueFree = "wprism: $command refused for a perfectly value-free reason";
        \WPrism\Policy::$failure = new RuntimeException($valueFree);
        $unpublished = invoke_json(static fn() => $cli->$method([], ['repo' => '/fixture', 'format' => 'json']));
        check(
            ($unpublished['error'] ?? null) === str_replace('-', '_', $command) . '_failed',
            "$command keeps the redacted _failed code for a value-free wprism: refusal"
        );
        check(($unpublished['details_redacted'] ?? null) === true, "$command records redaction for a value-free wprism: refusal");
        check(
            !str_contains((string) json_encode($unpublished), 'value-free reason'),
            "$command publishes none of a value-free wprism: refusal's prose"
        );
    }
    \WPrism\Policy::$failure = null;

    echo "\n== issue #3421: publication by reviewed CLASS, on the same #180 terms ==\n";
    // #180's rule, one axis over. halt_json_failure() also admits a refusal
    // whose CLASS has a closed, audited message vocabulary, independent of the
    // command or its prose — because the class, not the command, is what
    // makes those messages safe to publish. The motivating one: init's
    // bound-copy digest boundary ("copy source digest changed") is the whole
    // operator answer when code changes underneath a confirmed baseline, and
    // it was arriving as "init refused at an unclassified safety gate". `init`
    // has no command-level publication authority, so its injected-fault and
    // ownership internals keep redacting — they are ordinary Throwables.
    //
    // Every half is pinned here: the membership itself, that an admitted class
    // publishes across commands, that the identical message from an ordinary
    // Throwable on the SAME command still redacts
    // (so the class is doing the work, not the wording), and that the
    // sensitivity screen still governs an admitted class — a boundary refusal
    // carrying an absolute path goes back to the redacted envelope.
    $publicRefusalClasses = (new ReflectionClass(\WPrism\Cli::class))
        ->getConstant('PUBLIC_REFUSAL_CLASSES');
    check(
        $publicRefusalClasses === [\WPrism\InitialStateBoundaryException::class],
        'the publication class allowlist is readable and holds exactly the one audited class'
    );
    // The stub above stands in for the engine's class; this is what keeps that
    // substitution honest -- the admitted name must be the one the engine
    // really declares, in the file whose throw sites were audited.
    check(
        str_contains(
            (string) file_get_contents(__DIR__ . '/../../../../agent/src/Publication/Publish.php'),
            'final class InitialStateBoundaryException extends \RuntimeException'
        ),
        'the admitted class is the one agent/src/Publication/Publish.php declares'
    );
    foreach (['lint' => 'lint', 'capabilities' => 'capabilities'] as $method => $command) {
        $boundary = 'wprism: initial ' . $command . ' staging file refused at its inode-bound parent: copy source digest changed';
        \WPrism\Policy::$failure = new \WPrism\InitialStateBoundaryException($boundary);
        $published = invoke_json(static fn() => $cli->$method([], ['repo' => '/fixture', 'format' => 'json']));
        check(
            ($published['error'] ?? null) === 'initial_state_boundary'
                && ($published['message'] ?? null) === $boundary
                && !array_key_exists('details_redacted', $published),
            "$command publishes an admitted-class boundary refusal through class authority alone"
        );
        // Same sentence, ordinary class: still redacted. The class admission
        // is the only difference between these two answers.
        \WPrism\Policy::$failure = new RuntimeException($boundary);
        $stillRedacted = invoke_json(static fn() => $cli->$method([], ['repo' => '/fixture', 'format' => 'json']));
        check(
            ($stillRedacted['error'] ?? null) === str_replace('-', '_', $command) . '_failed'
                && ($stillRedacted['details_redacted'] ?? null) === true
                && !str_contains((string) json_encode($stillRedacted), 'copy source digest changed'),
            "$command still redacts the identical sentence thrown as an ordinary Throwable"
        );
        // The planted path: the bound helper's reason is its own STDERR, so a
        // diagnostic from inside it could arrive carrying a path. Admission by
        // class must not exempt it from the screen.
        \WPrism\Policy::$failure = new \WPrism\InitialStateBoundaryException(
            'wprism: initial staging file refused at its inode-bound parent: copy failed for /srv/private/tenant-42/wp-content/secret.php'
        );
        $planted = invoke_json(static fn() => $cli->$method([], ['repo' => '/fixture', 'format' => 'json']));
        check(
            ($planted['error'] ?? null) === str_replace('-', '_', $command) . '_failed'
                && ($planted['details_redacted'] ?? null) === true
                && !str_contains((string) json_encode($planted), '/srv/private/tenant-42'),
            "$command sends an admitted-class refusal carrying an absolute path back to the redacted envelope"
        );
    }
    \WPrism\Policy::$failure = null;

    echo "\n== issue #3399: gates behind the first one refuse through the same envelope ==\n";
    // The per-command loop above only ever reaches each command's FIRST gate.
    // These are the ones behind it, including the two contradictory-argument
    // gates that are not "missing" anything at all.
    $laterGates = [
        'code-preflight missing --compiled' => [
            static fn() => $cli->code_preflight([], ['repo' => '/fixture', 'format' => 'json']),
            '--compiled is required for code-preflight',
            null,
        ],
        'code-preflight missing --artifact-hash' => [
            static fn() => $cli->code_preflight([], [
                'repo' => '/fixture',
                'compiled' => '/fixture/artifact.json',
                'format' => 'json',
            ]),
            '--artifact-hash is required for code-preflight',
            null,
        ],
        'orphans missing --repo behind its table selector' => [
            static fn() => $cli->orphans(['wp_fixture'], ['format' => 'json']),
            '--repo is required for orphans',
            null,
        ],
        'promotion-begin missing --artifact-hash' => [
            static fn() => $cli->promotion_begin([], ['promotion-owner' => 'owner-fixture', 'format' => 'json']),
            '--artifact-hash is required for promotion-begin',
            null,
        ],
        'promotion-abort missing --artifact-hash' => [
            static fn() => $cli->promotion_abort([], ['promotion-owner' => 'owner-fixture', 'format' => 'json']),
            '--artifact-hash is required for promotion-abort',
            null,
        ],
        'promotion-begin-scoped missing --artifact-hash' => [
            static fn() => $cli->promotion_begin_scoped([], ['promotion-owner' => 'owner-fixture', 'format' => 'json']),
            '--artifact-hash is required for promotion-begin-scoped',
            null,
        ],
        'promotion-begin-scoped missing --scoped-promotion-receipt' => [
            static fn() => $cli->promotion_begin_scoped([], [
                'promotion-owner' => 'owner-fixture',
                'artifact-hash' => str_repeat('a', 64),
                'format' => 'json',
            ]),
            '--scoped-promotion-receipt is required for promotion-begin-scoped',
            null,
        ],
        'promotion-begin-scoped missing --scope-hash' => [
            static fn() => $cli->promotion_begin_scoped([], [
                'promotion-owner' => 'owner-fixture',
                'artifact-hash' => str_repeat('a', 64),
                'scoped-promotion-receipt' => str_repeat('b', 64),
                'format' => 'json',
            ]),
            '--scope-hash is required for promotion-begin-scoped',
            null,
        ],
        'promotion-complete-scoped missing --artifact-hash' => [
            static fn() => $cli->promotion_complete_scoped([], ['promotion-owner' => 'owner-fixture', 'format' => 'json']),
            '--artifact-hash is required for promotion-complete-scoped',
            null,
        ],
        'promotion-complete-scoped missing --scoped-promotion-receipt' => [
            static fn() => $cli->promotion_complete_scoped([], [
                'promotion-owner' => 'owner-fixture',
                'artifact-hash' => str_repeat('a', 64),
                'format' => 'json',
            ]),
            '--scoped-promotion-receipt is required for promotion-complete-scoped',
            null,
        ],
        'promotion-complete-scoped missing --scope-hash' => [
            static fn() => $cli->promotion_complete_scoped([], [
                'promotion-owner' => 'owner-fixture',
                'artifact-hash' => str_repeat('a', 64),
                'scoped-promotion-receipt' => str_repeat('b', 64),
                'format' => 'json',
            ]),
            '--scope-hash is required for promotion-complete-scoped',
            null,
        ],
        'verify-canonical missing --expected-artifact' => [
            static fn() => $cli->verify_canonical([], ['repo' => '/fixture', 'format' => 'json']),
            '--expected-artifact is required for verify-canonical',
            null,
        ],
        'verify-canonical missing --compiled' => [
            static fn() => $cli->verify_canonical([], ['repo' => '/fixture', 'expected-artifact' => str_repeat('a', 64), 'format' => 'json']),
            '--compiled is required for verify-canonical',
            null,
        ],
        // The deepest gate of the four, and the only one whose absence the
        // three checks above cannot distinguish from a short-circuit.
        'verify-canonical missing --policy-snapshot' => [
            static fn() => $cli->verify_canonical([], [
                'repo' => '/fixture',
                'expected-artifact' => str_repeat('a', 64),
                'compiled' => '/fixture/artifact.json',
                'format' => 'json',
            ]),
            '--policy-snapshot is required for verify-canonical',
            null,
        ],
        'env-set missing --name' => [
            static fn() => $cli->env_set([], ['repo' => '/fixture', 'format' => 'json']),
            '--name is required for env-set',
            null,
        ],
        'env-set with command-line value' => [
            static fn() => $cli->env_set([], ['repo' => '/fixture', 'name' => 'acme_key', 'value' => 'v', 'stdin' => true, 'format' => 'json']),
            'env-set does not accept --value because command-line arguments are observable',
            'remove --value and pipe one newline-terminated value through --stdin',
        ],
        'env-set without stdin' => [
            static fn() => $cli->env_set([], ['repo' => '/fixture', 'name' => 'acme_key', 'format' => 'json']),
            'env-set requires --stdin',
            'pipe one newline-terminated value through --stdin',
        ],
        'classify missing --set' => [
            static fn() => $cli->classify([], ['repo' => '/fixture', 'format' => 'json']),
            '--set is required for classify',
            'post_meta:foo=runtime',
        ],
        'capabilities with both selectors' => [
            static fn() => $cli->capabilities([], ['all' => true, 'repo' => '/fixture', 'format' => 'json']),
            'capabilities accepts either --all or --repo, not both',
            'exactly one of --all or --repo',
        ],
    ];
    foreach ($laterGates as $case => [$run, $expectedMessage, $remediationHint]) {
        $gate = invoke_json($run, str_starts_with($case, 'verify-canonical '));
        check(($gate['format'] ?? null) === 'wprism-command-refusal/v1', "$case names the versioned format");
        check(($gate['error'] ?? null) === 'invalid_arguments', "$case has the stable argument error code");
        check(($gate['message'] ?? null) === $expectedMessage, "$case states exactly which argument contract it refused");
        if ($remediationHint !== null) {
            check(
                str_contains((string) ($gate['remediation'] ?? ''), $remediationHint),
                "$case keeps its operator hint in machine remediation"
            );
        }
    }
    // capabilities' own missing-selector refusal must keep the --all escape in
    // remediation, the way scope keeps --roots=all.
    $capabilitiesMissing = invoke_json(static fn() => $cli->capabilities([], ['format' => 'json']));
    check(
        str_contains((string) ($capabilitiesMissing['remediation'] ?? ''), '--all'),
        'capabilities missing --repo keeps the --all alternative in machine remediation'
    );

    echo "\n== issue #3399: journal-report's only refusal path is its backend ==\n";
    // No required argument means no argument gate, so this command's catch
    // boundary is the whole of its machine contract.
    \WPrism\Journal::$failure = new RuntimeException('wprism: journal report refused at /Users/private-customer/site with sk_live_1234567890JOURNALLEAK');
    $journal = invoke_json(static fn() => $cli->journal_report([], ['format' => 'json']));
    check(($journal['command'] ?? null) === 'journal-report', 'journal-report refusal names the public command');
    check(
        ($journal['error'] ?? null) === 'journal_report_failed' && ($journal['reason_code'] ?? null) === 'journal_report_failed',
        'journal-report refusal has a stable unclassified reason code'
    );
    check(($journal['details_redacted'] ?? null) === true, 'journal-report refusal records an explicit redaction witness');
    check(
        str_contains((string) ($journal['remediation'] ?? ''), '--manifests'),
        'journal-report refusal carries its reviewed remediation arm'
    );
    check(!str_contains((string) json_encode($journal), 'JOURNALLEAK'), 'journal-report refusal omits operator-only bytes');
    // This command reads BOTH --json and --format=json on its success path, so
    // both spellings must reach the same formatter on its refusal path too.
    $journalBare = invoke_json(static fn() => $cli->journal_report([], ['json' => true]));
    check(($journalBare['reason_code'] ?? null) === 'journal_report_failed', 'journal-report --json spelling reaches the common formatter');
    check(!str_contains((string) json_encode($journalBare), 'JOURNALLEAK'), 'journal-report --json spelling redacts the same bytes');
    \WPrism\Journal::$failure = null;

    echo "\n== issue #3399: planted operator bytes stay out of a sample of the new machine paths ==\n";
    // Deliberately a SAMPLE, not 4 shapes x 11 commands.  Redaction lives
    // entirely in halt_json_failure()/containsSensitivePublicDetail(), which
    // the shape matrix above already exercises exhaustively through capture
    // and plan; per-command repetition would assert the same 260 lines of
    // formatter over and over.  What is genuinely per-command is that the
    // command reaches that formatter at all with its own reason code and its
    // own reviewed arm, so two of the eleven carry the planted tokens: lint
    // (whose refusal and whose findings-bearing success share exit 1) and
    // capabilities (the external ratification surface a reviewer polls).
    foreach ($productLeakShapes as $shape => $token) {
        $lintOperator = "wprism: lint refused reading the captured tree $token";
        \WPrism\Policy::$failure = new RuntimeException($lintOperator);
        $lint = invoke_json(static fn() => $cli->lint([], ['repo' => '/fixture', 'format' => 'json']));
        $lintBytes = (string) json_encode($lint, JSON_UNESCAPED_SLASHES);
        check(($lint['format'] ?? null) === 'wprism-command-refusal/v1', "lint $shape refusal names the versioned format");
        check(($lint['command'] ?? null) === 'lint', "lint $shape refusal names the public command");
        check(
            ($lint['error'] ?? null) === 'lint_failed' && ($lint['reason_code'] ?? null) === 'lint_failed',
            "lint $shape refusal has a stable unclassified reason code"
        );
        check(
            ($lint['message'] ?? null) === 'lint refused at an unclassified safety gate',
            "lint $shape refusal states only the constant safe message"
        );
        check(($lint['details_redacted'] ?? null) === true, "lint $shape refusal records an explicit redaction witness");
        check(
            !str_contains($lintBytes, $token) && !str_contains($lintBytes, $lintOperator),
            "lint $shape bytes are absent from machine output"
        );

        $capabilitiesOperator = "wprism: capability registry refused $token";
        \WPrism\Policy::$failure = new RuntimeException($capabilitiesOperator);
        $capabilities = invoke_json(static fn() => $cli->capabilities([], ['repo' => '/fixture', 'format' => 'json']));
        $capabilitiesBytes = (string) json_encode($capabilities, JSON_UNESCAPED_SLASHES);
        check(($capabilities['format'] ?? null) === 'wprism-command-refusal/v1', "capabilities $shape refusal names the versioned format");
        check(($capabilities['command'] ?? null) === 'capabilities', "capabilities $shape refusal names the public command");
        check(
            ($capabilities['error'] ?? null) === 'capabilities_failed' && ($capabilities['reason_code'] ?? null) === 'capabilities_failed',
            "capabilities $shape refusal has a stable unclassified reason code"
        );
        check(($capabilities['details_redacted'] ?? null) === true, "capabilities $shape refusal records an explicit redaction witness");
        check(
            !str_contains($capabilitiesBytes, $token) && !str_contains($capabilitiesBytes, $capabilitiesOperator),
            "capabilities $shape bytes are absent from machine output"
        );
    }
    // Drive a FRESH, explicitly sensitive refusal for the arm assertions
    // rather than reading whichever record the loop above happened to leave
    // behind. A trailing loop variable silently re-points if the shape list is
    // reordered, and the reviewed arm is only the contractual answer on the
    // REDACTED branch — so assert the redaction witness in the same breath,
    // which is what makes this a check of the arm and not of a leftover.
    // `capabilities` pins `platform boundary` deliberately: the arm it replaced
    // ALSO said "registry" (it named the retired generated capability registry
    // beside the disposition one), so a fragment those two strings share would
    // have gone on passing while the arm sent operators after a document the
    // teardown deleted. The pin has to be a phrase only the current arm holds.
    foreach ([
        'lint' => ['lint', 'captured state tree'],
        'capabilities' => ['capabilities', 'platform boundary'],
    ] as $method => [$command, $armFragment]) {
        \WPrism\Policy::$failure = new RuntimeException("wprism: $command refused at /Users/private-customer/site");
        $armRecord = invoke_json(static fn() => $cli->$method([], ['repo' => '/fixture', 'format' => 'json']));
        check(
            ($armRecord['details_redacted'] ?? null) === true
                && ($armRecord['error'] ?? null) === str_replace('-', '_', $command) . '_failed',
            "$command arm assertion reads an explicitly redacted record"
        );
        check(
            str_contains((string) ($armRecord['remediation'] ?? ''), $armFragment),
            "$command unclassified refusal carries its reviewed remediation arm"
        );
    }
    \WPrism\Policy::$failure = null;

    // A typed refusal raised by one of these backends must still pass through
    // with its own reviewed code, not flatten to the unclassified one.
    \WPrism\Policy::$failure = new \WPrism\CommandRefusalException(
        'adapter_certification_missing',
        'an adapter claims a capability with no named conformance evidence',
        'certify the adapter bundle or drop the claim, then report capabilities again',
        [[
            'code' => 'adapter_certification_missing',
            'surface' => 'options:acme_widget_color',
            'message' => 'claimed capability has no reviewed evidence bundle',
            'remediation' => 'certify the adapter bundle before claiming the capability',
        ]],
        'wprism: /Users/private-customer/site adapter claims promote without evidence'
    );
    $typedCapabilities = invoke_json(static fn() => $cli->capabilities([], ['repo' => '/fixture', 'format' => 'json']));
    check(($typedCapabilities['error'] ?? null) === 'adapter_certification_missing', 'typed capabilities refusal retains its reviewed reason code');
    check(
        ($typedCapabilities['diagnostics'][0]['surface'] ?? null) === 'options:acme_widget_color',
        'typed capabilities refusal keeps its reviewed diagnostic'
    );
    check(!str_contains((string) json_encode($typedCapabilities), 'private-customer'), 'typed capabilities refusal omits operator-only evidence');
    \WPrism\Policy::$failure = null;

    echo "\n== serialization failure still emits exactly one valid JSON value ==\n";
    \WPrism\Apply::$planFailure = new \WPrism\RepositoryCompilationException([[
        'severity' => 'error',
        'code' => 'non_finite_fixture',
        'path' => 'state/options/core.json',
        'locator' => 'records.fixture',
        'message' => INF,
    ]]);
    $serialization = invoke_json(static fn() => $cli->plan([], ['repo' => '/fixture', 'format' => 'json']));
    check(($serialization['error'] ?? null) === 'refusal_serialization_failed', 'serialization fallback has a stable reason code');
    check(($serialization['details_redacted'] ?? null) === true, 'serialization fallback refuses diagnostic details');

    echo "\n== the topology gate names itself in JSON and keeps its bytes in human mode ==\n";
    // Before the gate moved to Kernel and became typed, every one of these
    // answered a machine caller with `<command>_failed` /
    // "refused at an unclassified safety gate" / `details_redacted: true` --
    // the word "multisite" never reached JSON at all.
    $topologySentence = 'wprism: multisite is unsupported by the certified v1 contract; '
        . 'this command is single-site only and refuses before loading policy or mutating state';
    $GLOBALS['wprism_topology_multisite'] = true;
    // capture builds a Policy, which is stubbed here, so its gate is reached
    // the way every other backend refusal in this suite is: by making the real
    // handler cross its catch boundary with the REAL refusal object the Kernel
    // gate throws.
    try {
        \WPrism\SiteTopology::assert_single_site();
        $topologyRefusal = null;
    } catch (\WPrism\CommandRefusalException $refusal) {
        $topologyRefusal = $refusal;
    }
    check($topologyRefusal !== null, 'the Kernel topology gate throws a CommandRefusalException on a network');
    \WPrism\Capture::$failure = $topologyRefusal;

    $topologyCases = [
        // command       => handler invocation
        'capture' => static fn() => $cli->capture([], ['repo' => '/fixture', 'format' => 'json']),
        // journal-reset gained its boundary with the gate: it had no try/catch
        // at all, so a machine caller got human stderr and zero records.
        'journal-reset' => static fn() => $cli->journal_reset([], ['format' => 'json']),
        // Deliberately invoked with BOTH required selectors present: the gate
        // has to precede the argument checks AND Ledger::ensure(). Neither
        // \WPrism\Ledger nor \WPrism\PromotionLock is stubbed in this process, so a
        // gate that ran late would surface as an Error and a
        // `promotion_begin_failed` envelope instead of the code asserted below.
        'promotion-begin' => static fn() => $cli->promotion_begin([], [
            'promotion-owner' => 'topology-fixture-owner',
            'artifact-hash' => str_repeat('ab', 32),
            'format' => 'json',
        ]),
    ];
    foreach ($topologyCases as $command => $invoke) {
        $payload = invoke_json($invoke);
        check(($payload['format'] ?? null) === 'wprism-command-refusal/v1', "$command topology refusal names the versioned format");
        check(($payload['command'] ?? null) === $command, "$command topology refusal names the public command");
        check(
            ($payload['error'] ?? null) === 'multisite_unsupported'
                && ($payload['reason_code'] ?? null) === 'multisite_unsupported',
            "$command topology refusal carries the finite reason code, not " . str_replace('-', '_', $command) . '_failed'
        );
        check(
            !array_key_exists('details_redacted', $payload),
            "$command topology refusal is NOT redacted — the reason is public, so no evidence file is written for it"
        );
        check(
            str_contains((string) ($payload['message'] ?? ''), 'multisite is unsupported by the certified v1 contract')
                && str_contains((string) ($payload['message'] ?? ''), 'single-site only'),
            "$command topology refusal states the boundary in its public message"
        );
        check(
            !str_starts_with((string) ($payload['message'] ?? ''), 'wprism: '),
            "$command topology refusal keeps the `wprism: ` human convention out of the machine record"
        );
        check(
            is_string($payload['remediation'] ?? null) && str_contains((string) $payload['remediation'], 'single-site'),
            "$command topology refusal carries an actionable remediation"
        );
    }

    foreach ($topologyCases as $command => $_) {
        WP_CLI::reset();
        $human = match ($command) {
            'capture' => static fn() => $cli->capture([], ['repo' => '/fixture']),
            'journal-reset' => static fn() => $cli->journal_reset([], []),
            default => static fn() => $cli->promotion_begin([], [
                'promotion-owner' => 'topology-fixture-owner',
                'artifact-hash' => str_repeat('ab', 32),
            ]),
        };
        try {
            $human();
            check(false, "$command human topology refusal exits through WP_CLI::error");
        } catch (CliJsonHumanError $e) {
            check(
                $e->getMessage() === $topologySentence,
                "$command human mode prints the byte-identical sentence Policy.php printed before the gate moved"
            );
        }
        check(WP_CLI::$lines === [], "$command human topology refusal emits no JSON record");
    }
    $GLOBALS['wprism_topology_multisite'] = false;
    \WPrism\Capture::$failure = null;

    echo "\n== human mode remains human and unchanged ==\n";
    WP_CLI::reset();
    \WPrism\Capture::$failure = new RuntimeException('wprism: human refusal stays human');
    try {
        $cli->capture([], ['repo' => '/fixture']);
        check(false, 'human refusal exits through WP_CLI::error');
    } catch (CliJsonHumanError $e) {
        check($e->getMessage() === 'wprism: human refusal stays human', 'human refusal preserves the original actionable message');
    }
    check(WP_CLI::$lines === [], 'human refusal emits no JSON record');
    check(WP_CLI::$errors === ['wprism: human refusal stays human'], 'human refusal reaches WP_CLI::error exactly once');

    WP_CLI::reset();
    try {
        $cli->capture([], []);
        check(false, 'human missing argument exits through WP_CLI::error');
    } catch (CliJsonHumanError $e) {
        check($e->getMessage() === '--repo required', 'human missing-argument prose remains backward-compatible');
    }
    check(WP_CLI::$lines === [], 'human missing argument emits no JSON record');

    WP_CLI::reset();
    $explainSecret = 'provider failed with sk_live_1234567890EXPLAINHUMANMUSTNOTLEAK at /Users/private-customer/site';
    \WPrism\Apply::$planFailure = new RuntimeException($explainSecret);
    try {
        $cli->explain(['update:sha256:' . str_repeat('a', 64)], ['repo' => '/fixture']);
        check(false, 'human explain failure exits through WP_CLI::error');
    } catch (CliJsonHumanError $e) {
        check(
            $e->getMessage() === 'wprism: explain refused at a private safety gate; run plan or capture for operator diagnosis, then rerun explain',
            'human explain uses constant safe failure guidance'
        );
        check(!str_contains($e->getMessage(), $explainSecret), 'human explain never forwards private exception detail');
    }
    check(WP_CLI::$lines === [], 'human explain failure emits no JSON record');
    // issue #3397: the operator evidence these two commands print is the whole
    // point of keeping the raw message private, so assert it byte for byte.
    $humanCases = [
        'refresh-export backend refusal' => [
            static function () use ($cli): void {
                \WPrism\RefreshExport::$failure = new RuntimeException(
                    'wprism: refresh export refused — apply_in_progress is present; recover or complete the interrupted apply before observing production'
                );
                $cli->refresh_export([], ['repo' => '/Users/private-customer/site']);
            },
            'wprism: refresh export refused — apply_in_progress is present; recover or complete the interrupted apply before observing production',
        ],
        'refresh-export missing --repo' => [
            static function () use ($cli): void {
                $cli->refresh_export([], []);
            },
            '--repo required',
        ],
        'refresh-export empty --repo' => [
            static function () use ($cli): void {
                $cli->refresh_export([], ['repo' => '']);
            },
            '--repo required',
        ],
        'scope backend refusal' => [
            static function () use ($cli): void {
                \WPrism\Policy::$failure = new RuntimeException('wprism: scope refused — /Users/private-customer/site has no revision');
                $cli->scope([], ['repo' => '/Users/private-customer/site', 'roots' => 'all']);
            },
            'wprism: scope refused — /Users/private-customer/site has no revision',
        ],
        'scope missing --repo' => [
            static function () use ($cli): void {
                $cli->scope([], ['roots' => 'all']);
            },
            '--repo required',
        ],
        'scope missing --roots' => [
            static function () use ($cli): void {
                $cli->scope([], ['repo' => '/fixture']);
            },
            '--roots required (or --roots=all for the whole revision)',
        ],
        // issue #3399: every gate moved into a try below became a typed refusal,
        // and a typed refusal carries a SEPARATE operator message from its
        // public one.  Human mode still prints the operator message, so these
        // assert the pre-change prose byte for byte — sampled on lint and
        // capabilities for the backend path, and exhaustive over the gates,
        // because a gate's prose is the only place the change could silently
        // reword an operator's evidence.
        'lint backend refusal' => [
            static function () use ($cli): void {
                \WPrism\Policy::$failure = new RuntimeException('wprism: state dir not found: /Users/private-customer/site/state (nothing captured yet?)');
                $cli->lint([], ['repo' => '/Users/private-customer/site']);
            },
            'wprism: state dir not found: /Users/private-customer/site/state (nothing captured yet?)',
        ],
        'lint missing --repo' => [
            static fn() => $cli->lint([], []),
            '--repo required',
        ],
        'capabilities backend refusal' => [
            static function () use ($cli): void {
                \WPrism\Policy::$failure = new RuntimeException('wprism: /Users/private-customer/site has no generated capability registry');
                $cli->capabilities([], ['repo' => '/Users/private-customer/site']);
            },
            'wprism: /Users/private-customer/site has no generated capability registry',
        ],
        'capabilities missing --repo' => [
            static fn() => $cli->capabilities([], []),
            '--repo required unless --all is used',
        ],
        'capabilities with both selectors' => [
            static fn() => $cli->capabilities([], ['all' => true, 'repo' => '/fixture']),
            '--all and --repo are mutually exclusive',
        ],
        'promotion-begin missing --promotion-owner' => [
            static fn() => $cli->promotion_begin([], []),
            '--promotion-owner required',
        ],
        'promotion-begin missing --artifact-hash' => [
            static fn() => $cli->promotion_begin([], ['promotion-owner' => 'owner-fixture']),
            '--artifact-hash required',
        ],
        'promotion-abort missing --promotion-owner' => [
            static fn() => $cli->promotion_abort([], []),
            '--promotion-owner required',
        ],
        'promotion-abort missing --artifact-hash' => [
            static fn() => $cli->promotion_abort([], ['promotion-owner' => 'owner-fixture']),
            '--artifact-hash required',
        ],
        'promotion-begin-scoped missing --promotion-owner' => [
            static fn() => $cli->promotion_begin_scoped([], []),
            '--promotion-owner required',
        ],
        'promotion-begin-scoped missing --artifact-hash' => [
            static fn() => $cli->promotion_begin_scoped([], ['promotion-owner' => 'owner-fixture']),
            '--artifact-hash required',
        ],
        'promotion-begin-scoped missing --scoped-promotion-receipt' => [
            static fn() => $cli->promotion_begin_scoped([], [
                'promotion-owner' => 'owner-fixture',
                'artifact-hash' => str_repeat('a', 64),
            ]),
            '--scoped-promotion-receipt required',
        ],
        'promotion-begin-scoped missing --scope-hash' => [
            static fn() => $cli->promotion_begin_scoped([], [
                'promotion-owner' => 'owner-fixture',
                'artifact-hash' => str_repeat('a', 64),
                'scoped-promotion-receipt' => str_repeat('b', 64),
            ]),
            '--scope-hash required',
        ],
        'promotion-complete-scoped missing --promotion-owner' => [
            static fn() => $cli->promotion_complete_scoped([], []),
            '--promotion-owner required',
        ],
        'promotion-complete-scoped missing --artifact-hash' => [
            static fn() => $cli->promotion_complete_scoped([], ['promotion-owner' => 'owner-fixture']),
            '--artifact-hash required',
        ],
        'promotion-complete-scoped missing --scoped-promotion-receipt' => [
            static fn() => $cli->promotion_complete_scoped([], [
                'promotion-owner' => 'owner-fixture',
                'artifact-hash' => str_repeat('a', 64),
            ]),
            '--scoped-promotion-receipt required',
        ],
        'promotion-complete-scoped missing --scope-hash' => [
            static fn() => $cli->promotion_complete_scoped([], [
                'promotion-owner' => 'owner-fixture',
                'artifact-hash' => str_repeat('a', 64),
                'scoped-promotion-receipt' => str_repeat('b', 64),
            ]),
            '--scope-hash required',
        ],
        'env-set missing --repo' => [
            static fn() => $cli->env_set([], []),
            '--repo required',
        ],
        'env-set missing --name' => [
            static fn() => $cli->env_set([], ['repo' => '/fixture']),
            '--name required',
        ],
        'env-set with command-line value' => [
            static fn() => $cli->env_set([], ['repo' => '/fixture', 'name' => 'acme_key', 'value' => 'v', 'stdin' => true]),
            'remove --value and use --stdin',
        ],
        'env-set without stdin' => [
            static fn() => $cli->env_set([], ['repo' => '/fixture', 'name' => 'acme_key']),
            '--stdin is required',
        ],
        'orphans missing <table>' => [
            static fn() => $cli->orphans([], []),
            '<table> required',
        ],
        'orphans missing --repo' => [
            static fn() => $cli->orphans(['wp_fixture'], []),
            '--repo required',
        ],
        'verify-canonical missing --repo' => [
            static fn() => $cli->verify_canonical([], []),
            '--repo required',
        ],
        'verify-canonical missing --expected-artifact' => [
            static fn() => $cli->verify_canonical([], ['repo' => '/fixture']),
            '--expected-artifact required',
        ],
        'verify-canonical missing --compiled' => [
            static fn() => $cli->verify_canonical([], ['repo' => '/fixture', 'expected-artifact' => 'a']),
            '--compiled required',
        ],
        'verify-canonical missing --policy-snapshot' => [
            static fn() => $cli->verify_canonical([], ['repo' => '/fixture', 'expected-artifact' => 'a', 'compiled' => '/fixture/artifact.json']),
            '--policy-snapshot required',
        ],
        'pending missing --repo' => [
            static fn() => $cli->pending([], []),
            '--repo required',
        ],
        'coverage missing --repo' => [
            static fn() => $cli->coverage([], []),
            '--repo required',
        ],
        'classify missing --repo' => [
            static fn() => $cli->classify([], []),
            '--repo required',
        ],
        'classify missing --set' => [
            static fn() => $cli->classify([], ['repo' => '/fixture']),
            '--set required, e.g. --set="post_meta:foo=runtime"',
        ],
        'journal-report backend refusal' => [
            static function () use ($cli): void {
                \WPrism\Journal::$failure = new RuntimeException('wprism: journal report refused — core manifest is not installed');
                $cli->journal_report([], []);
            },
            'wprism: journal report refused — core manifest is not installed',
        ],
    ];
    foreach ($humanCases as $case => [$run, $expected]) {
        WP_CLI::reset();
        \WPrism\RefreshExport::$failure = null;
        \WPrism\Policy::$failure = null;
        \WPrism\Journal::$failure = null;
        try {
            $run();
            check(false, "human $case exits through WP_CLI::error");
        } catch (CliJsonHumanError $e) {
            check($e->getMessage() === $expected, "human $case preserves its exact actionable operator evidence");
        } catch (Throwable $e) {
            check(false, "human $case refuses through WP_CLI::error, not " . get_class($e) . ': ' . $e->getMessage());
        }
        check(WP_CLI::$errors === [$expected], "human $case reaches WP_CLI::error exactly once");
        check(WP_CLI::$lines === [], "human $case emits no JSON record");
    }
    \WPrism\RefreshExport::$failure = null;
    \WPrism\Policy::$failure = null;
    \WPrism\Journal::$failure = null;

    echo "\n== host preflight mirrors the same one-value contract ==\n";
    $tmp = sys_get_temp_dir() . '/wprism-cli-json-refusal-' . bin2hex(random_bytes(6));
    check(mkdir($tmp, 0700), 'host fixture directory is created');
    $registry = $tmp . '/envs.json';
    file_put_contents($registry, json_encode(['envs' => new stdClass()], JSON_UNESCAPED_SLASHES));
    $host = realpath(__DIR__ . '/../../../../cli/wprism');
    $root = realpath(__DIR__ . '/../../../..');
    $runHost = static function (array $arguments, ?string $pathPrefix = null) use ($host, $root): array {
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $command = array_merge([PHP_BINARY, $host], $arguments);
        if ($pathPrefix !== null) {
            $command = array_merge(['env', 'PATH=' . $pathPrefix . ':' . (string) getenv('PATH')], $command);
        }
        $process = proc_open($command, $spec, $pipes, $root);
        if (!is_resource($process)) {
            return ['status' => -1, 'stdout' => '', 'stderr' => 'proc_open failed'];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    };

    $sourceFixture = __DIR__ . '/../../fixtures/cli-json-refusal-sources.php';
    $sourceProcess = proc_open(
        [PHP_BINARY, $sourceFixture],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $sourcePipes,
        $root
    );
    if (!is_resource($sourceProcess)) {
        check(false, 'real source-refusal fixture starts');
    } else {
        fclose($sourcePipes[0]);
        $sourceStdout = stream_get_contents($sourcePipes[1]);
        $sourceStderr = stream_get_contents($sourcePipes[2]);
        fclose($sourcePipes[1]);
        fclose($sourcePipes[2]);
        $sourceStatus = proc_close($sourceProcess);
        check(
            $sourceStatus === 0 && $sourceStderr === '' && str_contains($sourceStdout, 'ALL PASSED'),
            'real Capture scope, uncertain-commit, and marker-recovery sources expose reviewed refusals'
        );
    }
    $hostMissing = $runHost(['plan', 'missing', '--envs-file=' . $registry, '--format=json']);
    $hostPayload = json_decode($hostMissing['stdout'], true);
    check($hostMissing['status'] === 1, 'host registry refusal exits 1');
    check($hostMissing['stderr'] === '', 'host JSON registry refusal emits no human stderr');
    check(is_array($hostPayload) && trim($hostMissing['stdout']) === json_encode($hostPayload, JSON_UNESCAPED_SLASHES), 'whole host stdout is exactly one JSON value');
    check(($hostPayload['reason_code'] ?? null) === 'host_preflight_failed', 'host registry refusal has a stable preflight code');
    $hostArgument = $runHost(['capture', '--format=json', '--envs-file=' . $registry]);
    $hostArgumentPayload = json_decode($hostArgument['stdout'], true);
    check($hostArgument['status'] === 1 && $hostArgument['stderr'] === '', 'host missing-environment JSON refusal is machine-only');
    check(($hostArgumentPayload['reason_code'] ?? null) === 'invalid_arguments', 'host missing environment has a stable argument code');
    $refreshArgument = $runHost(['refresh', '--field-diff', '--format=json', '--envs-file=' . $registry]);
    $refreshArgumentPayload = json_decode($refreshArgument['stdout'], true);
    check($refreshArgument['status'] === 1 && $refreshArgument['stderr'] === '',
        'refresh missing-environment JSON refusal is machine-only');
    check(is_array($refreshArgumentPayload)
        && ($refreshArgumentPayload['format'] ?? null) === 'wprism-command-refusal/v1'
        && ($refreshArgumentPayload['command'] ?? null) === 'refresh'
        && ($refreshArgumentPayload['reason_code'] ?? null) === 'invalid_arguments',
        'refresh joins the established host JSON-refusal envelope before driver creation');
    $hostSpacedFormat = $runHost(['apply', 'missing', '--envs-file=' . $registry, '--format', 'json']);
    $hostSpacedPayload = json_decode($hostSpacedFormat['stdout'], true);
    check($hostSpacedFormat['status'] === 1 && $hostSpacedFormat['stderr'] === '', 'host spaced JSON format refusal is machine-only');
    check(($hostSpacedPayload['reason_code'] ?? null) === 'host_preflight_failed', 'host recognizes the spaced --format json spelling');

    $fakeWp = $tmp . '/wp';
    $statusRefusal = [
        'format' => 'wprism-command-refusal/v1',
        'ok' => false,
        'command' => 'plan',
        'error' => 'plan_failed',
        'reason_code' => 'plan_failed',
        'message' => 'plan refused at an unclassified safety gate',
        'remediation' => 'inspect private operator evidence before another attempt',
        'details_redacted' => true,
    ];
    file_put_contents($fakeWp, "#!/bin/sh\nprintf '%s\\n' '" . json_encode($statusRefusal, JSON_UNESCAPED_SLASHES) . "'\nexit 1\n");
    chmod($fakeWp, 0700);
    file_put_contents($registry, json_encode(['envs' => [
        'status-fixture' => [
            'transport' => 'local',
            'wp_path' => $tmp,
            'repo_path' => $tmp,
        ],
    ]], JSON_UNESCAPED_SLASHES));
    $refreshScope = $runHost([
        'refresh', 'status-fixture', '--envs-file=' . $registry,
        '--production-ref=PRODUCTION-REF-OMITTED', '--scope-contract=PRIVATE-SCOPE-OMITTED',
        '--field-diff', '--format=json',
    ], $tmp);
    $refreshScopePayload = json_decode($refreshScope['stdout'], true);
    check($refreshScope['status'] === 1 && $refreshScope['stderr'] === '',
        'scoped field-diff JSON refusal emits no human stderr');
    check(is_array($refreshScopePayload)
        && ($refreshScopePayload['format'] ?? null) === 'wprism-command-refusal/v1'
        && ($refreshScopePayload['command'] ?? null) === 'refresh'
        && ($refreshScopePayload['reason_code'] ?? null) === 'scoped_unsupported'
        && str_contains((string) ($refreshScopePayload['remediation'] ?? ''), 'legacy whole-record resolver'),
        'scoped field-diff JSON refusal uses the stable actionable host envelope');
    check(!str_contains($refreshScope['stdout'], 'PRODUCTION-REF-OMITTED')
        && !str_contains($refreshScope['stdout'], 'PRIVATE-SCOPE-OMITTED'),
        'scoped field-diff JSON refusal omits supplied production and scope inputs');
    // `--format=json` WITHOUT `--field-diff` used to be an argument refusal:
    // the machine caller could never read the semantic plan as a document at
    // all. It is now a valid request that reaches the planner, so its refusal
    // names the artifact that was unavailable instead of blaming the
    // arguments — the distinction this check exists to hold.
    $refreshPlanJson = $runHost([
        'refresh', 'status-fixture', '--envs-file=' . $registry,
        '--production-ref=PRIVATE-PRODUCTION-REF-OMITTED', '--format=json',
    ], $tmp);
    $refreshPlanJsonPayload = json_decode($refreshPlanJson['stdout'], true);
    check($refreshPlanJson['status'] === 1 && $refreshPlanJson['stderr'] === ''
        && is_array($refreshPlanJsonPayload)
        && ($refreshPlanJsonPayload['reason_code'] ?? null) === 'plan_unavailable'
        && !str_contains($refreshPlanJson['stdout'], 'PRIVATE-PRODUCTION-REF-OMITTED'),
        'plan JSON without --field-diff refuses as an unavailable plan, not as invalid arguments');
    $refreshInvalid = $runHost([
        'refresh', 'status-fixture', '--envs-file=' . $registry,
        '--production-ref=production', '--not-a-refresh-flag=1', '--format=json',
    ], $tmp);
    $refreshInvalidPayload = json_decode($refreshInvalid['stdout'], true);
    check($refreshInvalid['status'] === 1 && $refreshInvalid['stderr'] === ''
        && is_array($refreshInvalidPayload)
        && ($refreshInvalidPayload['reason_code'] ?? null) === 'invalid_arguments',
        'invalid field-diff JSON grammar stays distinct from scoped or unavailable refusal');
    $refreshHumanScope = $runHost([
        'refresh', 'status-fixture', '--envs-file=' . $registry,
        '--production-ref=PRODUCTION-REF-OMITTED', '--scope-contract=PRIVATE-SCOPE-OMITTED', '--field-diff',
    ], $tmp);
    check($refreshHumanScope['status'] === 1 && $refreshHumanScope['stdout'] === ''
        && str_contains($refreshHumanScope['stderr'], 'redacted field-level change diff is unavailable for scoped refresh')
        && !str_contains($refreshHumanScope['stderr'], 'PRODUCTION-REF-OMITTED')
        && !str_contains($refreshHumanScope['stderr'], 'PRIVATE-SCOPE-OMITTED'),
        'human field-diff refusal uses closed redacted prose rather than exception input');
    $refreshUnavailable = $runHost([
        'refresh', 'status-fixture', '--envs-file=' . $registry,
        '--production-ref=PRIVATE-PRODUCTION-REF-OMITTED', '--field-diff',
    ], $tmp);
    check($refreshUnavailable['status'] === 1 && $refreshUnavailable['stdout'] === ''
        && str_contains($refreshUnavailable['stderr'], 'verify the production target is reachable, clean, at --production-ref, and supports refresh-export')
        && str_contains($refreshUnavailable['stderr'], 'legacy whole-record resolver')
        && !str_contains($refreshUnavailable['stderr'], 'PRIVATE-PRODUCTION-REF-OMITTED'),
        'unavailable field-diff refresh gives closed target, export, policy, and legacy recovery without echoing input');
    $rebaseExplicitManual = $runHost([
        'rebase', 'status-fixture', '--envs-file=' . $registry,
        '--production-ref=production', '--new-branch=refresh-explicit-manual',
        '--interactive', '--strategy=manual',
    ], $tmp);
    check($rebaseExplicitManual['status'] === 1 && $rebaseExplicitManual['stdout'] === ''
        && str_contains($rebaseExplicitManual['stderr'], 'redacted field-level resolution cannot be mixed')
        && !str_contains($rebaseExplicitManual['stderr'], 'production'),
        'rebase parser rejects explicit legacy --strategy=manual with closed field-mode prose before candidate work');
    $interactiveTargetTouch = $tmp . '/interactive-target-touched';
    file_put_contents($fakeWp, "#!/bin/sh\n: > " . escapeshellarg($interactiveTargetTouch) . "\nexit 0\n");
    chmod($fakeWp, 0700);
    $rebaseNonTty = $runHost([
        'rebase', 'status-fixture', '--envs-file=' . $registry,
        '--production-ref=PRIVATE-INTERACTIVE-PRODUCTION-OMITTED',
        '--new-branch=PRIVATE-INTERACTIVE-BRANCH-OMITTED', '--interactive',
    ], $tmp);
    check($rebaseNonTty['status'] === 1 && $rebaseNonTty['stdout'] === ''
        && str_contains($rebaseNonTty['stderr'], 'redacted field-level interactive resolution requires TTY stdin and stdout')
        && str_contains($rebaseNonTty['stderr'], 'use --field-resolution=<local-path> for automation')
        && !str_contains($rebaseNonTty['stderr'], 'PRIVATE-INTERACTIVE-PRODUCTION-OMITTED')
        && !str_contains($rebaseNonTty['stderr'], 'PRIVATE-INTERACTIVE-BRANCH-OMITTED')
        && !file_exists($interactiveTargetTouch)
        && !is_dir($tmp . '/.git/wprism-refresh/worktrees'),
        'non-TTY interactive rebase refuses before production target access or candidate work');
    file_put_contents($fakeWp, "#!/bin/sh\nprintf '%s\\n' '" . json_encode($statusRefusal, JSON_UNESCAPED_SLASHES) . "'\nexit 1\n");
    chmod($fakeWp, 0700);
    $abortExplicitManual = $runHost([
        'rebase', 'status-fixture', '--envs-file=' . $registry,
        '--abort=refresh-abort-explicit-manual', '--strategy=manual',
    ], $tmp);
    check($abortExplicitManual['status'] === 1 && $abortExplicitManual['stdout'] === ''
        && str_contains($abortExplicitManual['stderr'], '--abort=<run-id> accepts no production-ref or new-branch flags'),
        'rebase abort rejects even an explicit legacy --strategy=manual');
    $abortFieldInput = $runHost([
        'rebase', 'status-fixture', '--envs-file=' . $registry,
        '--abort=PRIVATE-ABORT-ID-OMITTED', '--field-resolution=PRIVATE-RESOLUTION-PATH-OMITTED',
    ], $tmp);
    check($abortFieldInput['status'] === 1 && $abortFieldInput['stdout'] === ''
        && str_contains($abortFieldInput['stderr'], 'refresh rebase abort accepts no field-resolution or interactive flags')
        && str_contains($abortFieldInput['stderr'], 'retry --abort=<run-id> alone')
        && !str_contains($abortFieldInput['stderr'], 'PRIVATE-ABORT-ID-OMITTED')
        && !str_contains($abortFieldInput['stderr'], 'PRIVATE-RESOLUTION-PATH-OMITTED'),
        'rebase abort with field input gives closed abort-only recovery without echoing id or path');
    $hostStatus = $runHost(['status', 'status-fixture', '--envs-file=' . $registry], $tmp);
    check($hostStatus['status'] === 1, 'human status preserves the refused plan exit');
    check($hostStatus['stdout'] === '', 'human status does not dump refusal JSON to stdout');
    check(str_contains($hostStatus['stderr'], '[plan_failed] plan refused'), 'human status renders the refusal code and message');
    check(!str_contains($hostStatus['stderr'], '"format"'), 'human status never dumps the raw JSON envelope');

    // issue #3399: `wprism pending`'s human table is fed by fetch_pending(), which
    // reads the agent's --format=json channel. Now that a pending refusal
    // answers there with the envelope, its stderr-else-stdout fallback dumped
    // raw JSON at the operator — the exact thing render_command_refusal_human()
    // exists to prevent. Driven the same way cmd_status's case is: a fake wp on
    // PATH that prints one envelope and exits 1.
    $pendingRefusal = [
        'format' => 'wprism-command-refusal/v1',
        'ok' => false,
        'command' => 'pending',
        'error' => 'pending_failed',
        'reason_code' => 'pending_failed',
        'message' => 'pending refused at an unclassified safety gate',
        'remediation' => 'inspect the repository policy and provenance journal state, then correct the policy or ledger blocker before scanning the review queue again',
        'details_redacted' => true,
    ];
    file_put_contents($fakeWp, "#!/bin/sh\nprintf '%s\\n' '" . json_encode($pendingRefusal, JSON_UNESCAPED_SLASHES) . "'\nexit 1\n");
    chmod($fakeWp, 0700);
    $hostPending = $runHost(['pending', 'status-fixture', '--envs-file=' . $registry], $tmp);
    check($hostPending['status'] === 1, 'human pending preserves the refused agent exit');
    check($hostPending['stdout'] === '', 'human pending does not dump refusal JSON to stdout');
    check(
        str_contains($hostPending['stderr'], '[pending_failed] pending refused'),
        'human pending renders the refusal code and message'
    );
    check(!str_contains($hostPending['stderr'], '"format"'), 'human pending never dumps the raw JSON envelope');
    check(
        str_contains($hostPending['stderr'], 'remedy: inspect the repository policy'),
        'human pending forwards the reviewed remediation as a remedy line'
    );
    @unlink($registry);
    @unlink($fakeWp);
    @rmdir($tmp);

    if ($failures > 0) {
        fwrite(STDERR, "\n$failures failure(s)\n");
        exit(1);
    }
    echo "\nALL PASSED\n";
}

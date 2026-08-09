<?php
/**
 * Offline product-command regression for DUO-3345's stable JSON refusal
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

        public static function error($message): void {
            self::$errors[] = (string) $message;
            throw new CliJsonHumanError((string) $message);
        }

        public static function reset(): void {
            self::$lines = [];
            self::$errors = [];
        }
    }
}

namespace Duo {
    final class Capture {
        public static ?\Throwable $failure = null;

        public static function run($repo, $out, $force): array {
            if (self::$failure !== null) {
                throw self::$failure;
            }
            return [];
        }
    }

    final class Apply {
        public static ?\Throwable $planFailure = null;
        public static ?\Throwable $applyFailure = null;

        public static function plan($repo, array $options): array {
            if (self::$planFailure !== null) {
                throw self::$planFailure;
            }
            return [];
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
     * DUO-3397: refresh-export and scope reach their catch boundaries through
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

    /**
     * Nothing on a refusal path may serialize through the canonical encoder
     * any more — the common formatter owns every refusal byte.  The stub is
     * deliberately present and deliberately unused: a reintroduced private
     * catch path would encode through Canon, and this suite must then report
     * the leaked bytes rather than a confusing "class not found" fatal.
     */
    final class Canon {
        public static function encode($value): string {
            return json_encode($value, JSON_UNESCAPED_SLASHES) . "\n";
        }
    }

    final class Policy {
        public static ?\Throwable $failure = null;

        public static function load($repo, ?array $pins = null, bool $capabilities = false): array {
            if (self::$failure !== null) {
                throw self::$failure;
            }
            return [];
        }
    }

    /**
     * DUO-3399: journal-report is the one newly enveloped command with no
     * required argument at all, so its catch boundary can only be reached
     * through its backend.  Everything else in this file's per-command loop
     * refuses at a gate before any backend is touched.
     */
    final class Journal {
        public static ?\Throwable $failure = null;

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
}

namespace {
    require __DIR__ . '/../../agent/src/Secrets.php';
    require __DIR__ . '/../../agent/src/CommandRefusal.php';
    require __DIR__ . '/../../agent/src/Deletion.php';
    require __DIR__ . '/../../agent/src/RepositoryAuthorization.php';
    require __DIR__ . '/../../agent/src/Code.php';
    require __DIR__ . '/../../agent/src/RepositoryCompiler.php';
    require __DIR__ . '/../../agent/src/Cli.php';

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

    /** @return array<string,mixed> */
    function invoke_json(callable $command): array {
        WP_CLI::reset();
        try {
            $command();
            check(false, 'JSON refusal exits non-zero (command unexpectedly returned)');
        } catch (CliJsonHalt $e) {
            check($e->status === 1, 'JSON refusal exits with status 1');
        } catch (Throwable $e) {
            check(false, 'JSON refusal halts through the structured path, not ' . get_class($e) . ': ' . $e->getMessage());
        }
        check(WP_CLI::$errors === [], 'JSON refusal never enters WP_CLI::error human rendering');
        check(count(WP_CLI::$lines) === 1, 'JSON refusal emits exactly one machine-readable record');
        $decoded = json_decode(WP_CLI::$lines[0] ?? '', true);
        check(is_array($decoded), 'JSON refusal record decodes');
        return is_array($decoded) ? $decoded : [];
    }

    $cli = new \Duo\Cli();

    echo "\n== every primary JSON command owns a stable missing-argument refusal ==\n";
    $commands = [
        'compile' => 'compile',
        'code_stage' => 'code-stage',
        'code_finalize' => 'code-finalize',
        'init' => 'init',
        'scope' => 'scope',
        'capture' => 'capture',
        'plan' => 'plan',
        'explain' => 'explain',
        'apply' => 'apply',
        'deploy' => 'deploy',
        // DUO-3397: refresh-export used a private JSON catch path and scope
        // refused its selectors before its boundary; both now answer a
        // machine caller with the same envelope every other command does.
        'refresh_export' => 'refresh-export',
        'scope' => 'scope',
        // DUO-3399: the remaining eleven commands that advertise
        // --format=json all caught \Throwable straight into WP_CLI::error(),
        // and most gated their arguments outside the try as well, so a
        // machine caller got human stderr and ZERO records.  With these the
        // envelope set is closed: every --format=json command is here.
        // journal-report is deliberately absent — it has no required
        // argument, so it has no missing-argument refusal to assert; its
        // backend refusal is exercised on its own below.
        'promotion_begin' => 'promotion-begin',
        'promotion_abort' => 'promotion-abort',
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
        $payload = invoke_json(static fn() => $cli->$method([], ['format' => 'json']));
        check(($payload['format'] ?? null) === 'duo-command-refusal/v1', "$command refusal names the versioned format");
        check(($payload['ok'] ?? null) === false, "$command refusal is unambiguously not ok");
        check(($payload['command'] ?? null) === $command, "$command refusal names the public command");
        check(($payload['error'] ?? null) === 'invalid_arguments', "$command refusal has a stable argument error code");
        check(($payload['reason_code'] ?? null) === $payload['error'], "$command refusal exposes the error as a finite reason code");
        // Each command's FIRST gate, in its own declaration order — the one a
        // caller invoking with nothing actually hits.
        $missing = match ($command) {
            'explain' => '<bucket>:<entity-key>',
            'orphans' => '<table>',
            'promotion-begin', 'promotion-abort' => '--promotion-owner',
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

    echo "\n== deliberately public gates keep stable diagnostics ==\n";
    \Duo\Capture::$failure = new \Duo\CommandRefusalException(
        'incomplete_state_discovery',
        'capture found state that has no reviewed classification',
        'review it with duo pending, then classify or exclude it before another capture',
        [[
            'code' => 'unclassified_state',
            'surface' => 'options:acme_widget_color',
            'message' => 'state surface has no reviewed classification',
            'remediation' => 'review it with duo pending, then classify or exclude it explicitly',
        ]],
        'duo: operator-only path /private/repo contains unclassified option acme_widget_color'
    );
    $capture = invoke_json(static fn() => $cli->capture([], ['repo' => '/fixture', 'json' => true]));
    check(($capture['error'] ?? null) === 'incomplete_state_discovery', 'capture retains the source-owned refusal code');
    check(
        ($capture['diagnostics'][0]['surface'] ?? null) === 'options:acme_widget_color'
            && str_contains((string) ($capture['diagnostics'][0]['remediation'] ?? ''), 'classify'),
        'capture JSON keeps the exact state surface and per-finding remedy'
    );
    check(!str_contains((string) json_encode($capture), '/private/repo'), 'operator-only typed evidence is absent from JSON');

    $unsupportedDeletionFactory = new ReflectionMethod(\Duo\Deletion::class, 'unsupported_capability_refusal');
    \Duo\Capture::$failure = $unsupportedDeletionFactory->invoke(null, 'table:nf3_forms');
    $unsupportedDeletion = invoke_json(static fn() => $cli->capture([], ['repo' => '/fixture', 'format' => 'json']));
    check(
        ($unsupportedDeletion['format'] ?? null) === 'duo-command-refusal/v1'
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

    \Duo\Capture::$failure = new \Duo\CommandRefusalException(
        'policy_refused',
        'policy rejected a reviewed field',
        'review the policy diagnostic',
        [[
            'code' => 'policy_refused',
            'surface' => 'options:sk_live_1234567890STRUCTUREDLEAK',
            'message' => 'policy rejected this field',
            'remediation' => 'review it privately',
        ]],
        'duo: operator may inspect the private policy refusal'
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
        'Postgres userinfo' => 'postgres://duo:MUSTNOTLEAK@localhost/wordpress',
        'MySQL userinfo' => 'mysql://root:MUSTNOTLEAK@db:3306/wordpress',
        'SSH userinfo' => 'ssh://deploy:MUSTNOTLEAK@host/repository',
        'OAuth client secret' => 'https://example.test/callback?client_secret=MUSTNOTLEAK',
        'generic secret query' => 'https://example.test/callback?secret=MUSTNOTLEAK',
        'fragment access token' => 'https://example.test/callback#access_token=MUSTNOTLEAK',
        'OAuth authorization code' => 'https://example.test/callback?code=MUSTNOTLEAK',
        'native-app OAuth query' => 'com.example.app:/oauth2redirect?code=MUSTNOTLEAK',
        'custom-scheme OAuth fragment' => 'myapp:/oauth/callback#code=MUSTNOTLEAK',
        'opaque absolute URI' => 'urn:duo:callback?code=MUSTNOTLEAK',
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
        \Duo\Capture::$failure = new \Duo\CommandRefusalException(
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

    // DUO-3398 REVERSES the original expectation of this case. The first
    // envelope redacted EVERY raw Throwable, including the engine's own
    // `duo: `-prefixed refusals — messages deliberately authored for the
    // operator at their throw sites. That broke the older, certification-
    // carrying contract (ninja-forms conformance asserts the parent-deletion
    // refusal names table:nf3_forms through --format=json) and the refusal
    // doctrine itself: "duo: target drift requires a fresh capture" IS the
    // remediation, and sending the operator to private evidence for it is
    // the envelope withholding the answer. The message is public; the
    // Throwable's class, chain, file, and trace stay private, and the
    // sensitivity screen (both entry and final pass) still redacts a
    // refusal embedding a secret-shaped value — pinned two cases below.
    \Duo\Apply::$planFailure = new RuntimeException('duo: target drift requires a fresh capture');
    $plan = invoke_json(static fn() => $cli->plan([], ['repo' => '/fixture', 'format' => 'json']));
    check(($plan['error'] ?? null) === 'plan_refused', 'plan maps an engine-authored refusal to plan_refused');
    check(
        !array_key_exists('details_redacted', $plan)
            && ($plan['message'] ?? null) === 'duo: target drift requires a fresh capture'
            && ($plan['diagnostics'][0]['message'] ?? null) === 'duo: target drift requires a fresh capture',
        'a duo:-prefixed refusal is PUBLIC — the operator reads the engine\'s own words, not a redaction notice'
    );

    \Duo\Capture::$failure = new RuntimeException(
        'duo: deletion intent for table:nf3_forms is unsupported — no pinned adapter declares its reverse-reference checks and cascade effects'
    );
    $captureRefusal = invoke_json(static fn() => $cli->capture([], ['repo' => '/fixture', 'format' => 'json']));
    check(
        ($captureRefusal['error'] ?? null) === 'capture_refused'
            && str_contains((string) ($captureRefusal['message'] ?? ''), 'deletion intent for table:nf3_forms is unsupported')
            && !array_key_exists('details_redacted', $captureRefusal),
        'the conformance-carried deletion refusal reaches JSON naming its table (the DUO-3398 regression)'
    );
    check(
        ($captureRefusal['remediation'] ?? null) === 'the refusal message names the blocker; correct it, then retry the command',
        'a public refusal\'s remediation says the message is the answer — never a pointer at private evidence'
    );

    \Duo\Capture::$failure = new RuntimeException(
        'duo: refusing capture — option sk_live_1234567890ABCDEFGHIJ looks like a live secret'
    );
    $secretRefusal = invoke_json(static fn() => $cli->capture([], ['repo' => '/fixture', 'format' => 'json']));
    check(
        ($secretRefusal['error'] ?? null) === 'capture_failed'
            && ($secretRefusal['details_redacted'] ?? null) === true
            && !str_contains((string) json_encode($secretRefusal), 'sk_live_1234567890ABCDEFGHIJ'),
        'a duo:-prefixed refusal embedding a secret-shaped value is still redacted by the sensitivity screen — '
        . 'and falls all the way back to the generic capture_failed shape, never a half-public _refused'
    );

    // The `duo: ` prefix is not proof of authorship: three wrapper families
    // re-prefix text the engine does not write. Each must stay redacted.
    foreach ([
        'duo: required manifest action \'provider:probe/x\' failed — exit 1: PHP Warning with a payload tail',
        'duo: batch regenerator \'woocommerce-product-lookups\' failed: wpdb said something with an option value in it',
        'duo: deletion guard lock refused for post 7: guard query failed for wp_posts : Deadlock found MUSTNOTLEAK',
    ] as $wrapped) {
        \Duo\Capture::$failure = new RuntimeException($wrapped);
        $wrappedRefusal = invoke_json(static fn() => $cli->capture([], ['repo' => '/fixture', 'format' => 'json']));
        check(
            ($wrappedRefusal['error'] ?? null) === 'capture_failed'
                && ($wrappedRefusal['details_redacted'] ?? null) === true
                && !str_contains((string) json_encode($wrappedRefusal), 'MUSTNOTLEAK')
                && !str_contains((string) json_encode($wrappedRefusal), 'payload tail'),
        'a wrapper-prefixed refusal carrying foreign text stays redacted: ' . substr($wrapped, 0, 40)
        );
    }

    \Duo\Capture::$failure = new RuntimeException(
        "duo: user-meta exact login 'privateperson' disappeared after preflight; transaction rolled back"
    );
    $loginRefusal = invoke_json(static fn() => $cli->capture([], ['repo' => '/fixture', 'format' => 'json']));
    check(
        ($loginRefusal['details_redacted'] ?? null) === true
            && !str_contains((string) json_encode($loginRefusal), 'privateperson'),
        'a refusal naming an exact login stays redacted — logins are in the redaction contract by name'
    );

    \Duo\Capture::$failure = new RuntimeException(
        'duo: canonical state at /var/www/site/state is not writable'
    );
    $pathRefusal = invoke_json(static fn() => $cli->capture([], ['repo' => '/fixture', 'format' => 'json']));
    check(
        ($pathRefusal['details_redacted'] ?? null) === true
            && !str_contains((string) json_encode($pathRefusal), '/var/www'),
        'a refusal embedding an absolute filesystem path stays redacted in the public branch'
    );

    \Duo\Capture::$failure = new RuntimeException('TypeError-shaped accident with no refusal prefix');
    $accident = invoke_json(static fn() => $cli->capture([], ['repo' => '/fixture', 'format' => 'json']));
    check(
        ($accident['error'] ?? null) === 'capture_failed'
            && ($accident['details_redacted'] ?? null) === true
            && !str_contains((string) json_encode($accident), 'TypeError-shaped'),
        'an unprefixed Throwable stays fully redacted — the reversal is scoped to the engine\'s own refusal convention'
    );

    $forcedEntityHash = hash('sha256', 'private-option-or-user-identity');
    \Duo\Apply::$applyFailure = new \Duo\CommandRefusalException(
        'apply_forced_override_failed',
        'apply failed after explicit plan conflict overrides were authorized',
        'inspect private operator evidence and apply recovery state; reconcile the failed gate before another attempt and do not assume the authorized override committed',
        [],
        'Warning: FORCED conflict private-option-or-user-identity\nduo: later provider detail stays operator-only',
        new RuntimeException('duo: later provider detail stays operator-only'),
        [[
            'format' => 'duo-forced-plan-override/v1',
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
    \Duo\Apply::$applyFailure = new \Duo\CommandRefusalException(
        'apply_forced_override_failed',
        'apply failed after explicit plan conflict overrides were authorized',
        'inspect private operator evidence before another attempt',
        [],
        'duo: private operator detail',
        null,
        [['entity_identity_sha256' => 'sk_live_1234567890FORCEDLEAK']]
    );
    $forcedRedaction = invoke_json(static fn() => $cli->apply([], ['repo' => '/fixture', 'format' => 'json']));
    check(($forcedRedaction['details_redacted'] ?? null) === true, 'sensitive forced override evidence activates the final redaction guard');
    check(!array_key_exists('forced_overrides', $forcedRedaction), 'sensitive forced override evidence is omitted as a whole');
    check(!str_contains((string) json_encode($forcedRedaction), 'FORCEDLEAK'), 'sensitive forced override bytes are absent from JSON');

    \Duo\Capture::$failure = \Duo\CommandRefusalException::ambiguousCaptureRecovery(
        'duo: operator-only malformed database commit marker detail'
    );
    $recovery = invoke_json(static fn() => $cli->capture([], ['repo' => '/fixture', 'format' => 'json']));
    check(($recovery['error'] ?? null) === 'capture_recovery_ambiguous', 'known capture recovery ambiguity retains its stable source code');
    check(str_contains((string) ($recovery['remediation'] ?? ''), 'do not retry or discard'), 'known capture recovery ambiguity preserves its no-retry/no-discard remedy');
    check(!str_contains((string) json_encode($recovery), 'malformed database commit marker'), 'capture recovery JSON omits operator-only marker evidence');

    echo "\n== typed diagnostics remain intact inside the common envelope ==\n";
    \Duo\Apply::$planFailure = new \Duo\RepositoryCompilationException([[
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
        'Postgres credential URL' => 'postgres://duo:TYPEDLEAK@localhost/wordpress',
        'MySQL credential URL' => 'mysql://root:TYPEDLEAK@db:3306/wordpress',
        'SSH credential URL' => 'ssh://deploy:TYPEDLEAK@host/repository',
        'OAuth client secret' => 'https://example.test/callback?client_secret=TYPEDLEAK',
        'generic secret query' => 'https://example.test/callback?secret=TYPEDLEAK',
        'fragment access token' => 'https://example.test/callback#access_token=TYPEDLEAK',
        'OAuth authorization code' => 'https://example.test/callback?code=TYPEDLEAK',
        'native-app OAuth query' => 'com.example.app:/oauth2redirect?code=TYPEDLEAK',
        'custom-scheme OAuth fragment' => 'myapp:/oauth/callback#code=TYPEDLEAK',
        'opaque absolute URI' => 'urn:duo:callback?code=TYPEDLEAK',
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
        \Duo\Apply::$planFailure = new \Duo\RepositoryCompilationException([[
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
        \Duo\Capture::$failure = new RuntimeException($secretMessage);
        $redacted = invoke_json(static fn() => $cli->capture([], ['repo' => '/fixture', 'format' => 'json']));
        $redactedBytes = json_encode($redacted, JSON_UNESCAPED_SLASHES);
        check(($redacted['details_redacted'] ?? null) === true, "$shape failure records an explicit redaction witness");
        check(!str_contains((string) $redactedBytes, $secretMessage), "$shape exception bytes are absent from JSON");
        check(($redacted['message'] ?? null) === 'capture refused at an unclassified safety gate', "$shape gets the constant safe message");
    }

    echo "\n== DUO-3397: refresh-export and scope answer machines with the same envelope ==\n";
    $productLeakShapes = [
        'hard token' => 'sk_live_1234567890PRODUCTLEAK',
        'credential URL' => 'https://user:SECRETPASS@db.example/production',
        'private path' => '/Users/private-customer/sites/production/site.duo.json',
        'distinctive operator token' => 'PRODUCTLEAK-0f9c2d41',
    ];
    foreach ($productLeakShapes as $shape => $token) {
        $refreshOperator = "duo: refresh export refused while observing production $token";
        \Duo\RefreshExport::$failure = new RuntimeException($refreshOperator);
        $refresh = invoke_json(static fn() => $cli->refresh_export([], ['repo' => '/fixture', 'format' => 'json']));
        $refreshBytes = (string) json_encode($refresh, JSON_UNESCAPED_SLASHES);
        check(($refresh['format'] ?? null) === 'duo-command-refusal/v1', "refresh-export $shape refusal names the versioned format");
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

        $scopeOperator = "duo: scope refused while compiling $token";
        \Duo\Policy::$failure = new RuntimeException($scopeOperator);
        $scope = invoke_json(static fn() => $cli->scope([], ['repo' => '/fixture', 'roots' => 'all', 'format' => 'json']));
        $scopeBytes = (string) json_encode($scope, JSON_UNESCAPED_SLASHES);
        check(($scope['format'] ?? null) === 'duo-command-refusal/v1', "scope $shape refusal names the versioned format");
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
    \Duo\Apply::$planFailure = new RuntimeException('duo: explain refused — plan unavailable');
    $explainRefusal = invoke_json(static fn() => $cli->explain(['post:x'], ['repo' => '/fixture', 'format' => 'json']));
    \Duo\Apply::$planFailure = null;
    check(
        ($explainRefusal['details_redacted'] ?? null) === true
            && !str_contains((string) json_encode($explainRefusal), 'plan unavailable'),
        'explain\'s duo: refusal stays redacted — a command absent from the allowlist publishes nothing'
    );

    // The observation-command exclusion is the reason, not the token
    // patterns: a VALUE-FREE duo:-prefixed refresh-export refusal must also
    // stay redacted, or the exclusion is decorative and the next value-free-
    // looking production identifier leaks.
    \Duo\RefreshExport::$failure = new RuntimeException('duo: refresh export refused — an apply is in progress');
    $valueFree = invoke_json(static fn() => $cli->refresh_export([], ['repo' => '/fixture', 'format' => 'json']));
    check(
        ($valueFree['error'] ?? null) === 'refresh_export_failed'
            && ($valueFree['details_redacted'] ?? null) === true
            && !str_contains((string) json_encode($valueFree), 'apply is in progress'),
        'a value-free duo: refusal from an OBSERVATION command stays redacted — the command exclusion is load-bearing, not the patterns'
    );

    // wp-cli rewrites a bare --json into format=json, but refresh-export
    // derives its own $format from both spellings, so both must reach the
    // same formatter rather than only the one the dispatcher happens to use.
    \Duo\RefreshExport::$failure = new RuntimeException('duo: refresh export refused with sk_live_1234567890BARELEAK');
    $bareJson = invoke_json(static fn() => $cli->refresh_export([], ['repo' => '/fixture', 'json' => true]));
    check(($bareJson['reason_code'] ?? null) === 'refresh_export_failed', 'refresh-export --json spelling reaches the common formatter');
    check(!str_contains((string) json_encode($bareJson), 'BARELEAK'), 'refresh-export --json spelling redacts the same bytes');

    // refresh-export runs the real capture builder, so its reviewed typed
    // refusals must survive the change instead of flattening to the
    // unclassified code.
    \Duo\RefreshExport::$failure = new \Duo\CommandRefusalException(
        'incomplete_state_discovery',
        'refresh export found state that has no reviewed classification',
        'review it with duo pending, then classify or exclude it before observing production again',
        [[
            'code' => 'unclassified_state',
            'surface' => 'options:acme_widget_color',
            'message' => 'state surface has no reviewed classification',
            'remediation' => 'review it with duo pending, then classify or exclude it explicitly',
        ]],
        'duo: operator-only path /Users/private-customer/site holds unclassified option acme_widget_color'
    );
    $typedRefresh = invoke_json(static fn() => $cli->refresh_export([], ['repo' => '/fixture', 'format' => 'json']));
    check(($typedRefresh['error'] ?? null) === 'incomplete_state_discovery', 'known typed refresh-export refusal retains its reviewed reason code');
    check(
        ($typedRefresh['remediation'] ?? null) === 'review it with duo pending, then classify or exclude it before observing production again',
        'known typed refresh-export refusal retains its reviewed remediation'
    );
    check(
        ($typedRefresh['diagnostics'][0]['surface'] ?? null) === 'options:acme_widget_color',
        'known typed refresh-export refusal keeps its reviewed diagnostic'
    );
    check(!str_contains((string) json_encode($typedRefresh), 'private-customer'), 'known typed refresh-export refusal omits operator-only evidence');
    \Duo\RefreshExport::$failure = null;

    // The selector gates themselves: scope refused --roots before its catch
    // boundary, so a machine caller got human stderr and no record at all.
    \Duo\Policy::$failure = null;
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
    \Duo\Policy::$failure = new RuntimeException('duo: scope refused while compiling /Users/private-customer/site');
    $scopeArm = invoke_json(static fn() => $cli->scope([], ['repo' => '/fixture', 'roots' => 'all', 'format' => 'json']));
    check(
        ($scopeArm['remediation'] ?? null) === 'inspect private operator evidence, then compile the revision or correct the root selectors before resolving scope again',
        'scope unclassified refusal carries its reviewed remediation arm'
    );

    // --contract is scope's machine-evidence mode and the mode whose gate
    // ordering moved the most; its JSON failure must reach the same formatter.
    $contractScope = invoke_json(static fn() => $cli->scope([], ['repo' => '/fixture', 'roots' => 'all', 'contract' => true, 'format' => 'json']));
    check(($contractScope['format'] ?? null) === 'duo-command-refusal/v1', 'scope --contract JSON failure names the versioned format');
    check(($contractScope['command'] ?? null) === 'scope', 'scope --contract JSON failure names the public command');
    check(!str_contains((string) json_encode($contractScope, JSON_UNESCAPED_SLASHES), 'private-customer'), 'scope --contract JSON failure redacts operator bytes');
    \Duo\Policy::$failure = null;

    echo "\n== DUO-3399: the envelope set is closed over every --format=json command ==\n";
    // The contract sentence in spec/repo-format.md and cli/README.md stopped
    // enumerating commands and now says "every command that advertises
    // --format=json".  That is only true while it is structurally true, so
    // assert it against the source rather than against a list this file would
    // have to be remembered to update.
    $cliSource = (string) file_get_contents(__DIR__ . '/../../agent/src/Cli.php');
    preg_match_all('/\/\*\*(.*?)\*\/\s*public function (\w+)\((.*?)\n    \}/s', $cliSource, $handlers, PREG_SET_ORDER);
    check(count($handlers) >= 21, 'source scan found the product command handlers');
    $advertised = [];
    foreach ($handlers as [, $doc, $method, $body]) {
        if (!str_contains($doc, '--format=<format>')) {
            continue;
        }
        $command = preg_match('/@subcommand\s+(\S+)/', $doc, $sub) === 1
            ? $sub[1]
            : str_replace('_', '-', $method);
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
    // 22 since DUO-3339/B2 added `adapter-survey`, the target-side half of the
    // adapter catalog. It advertises --format=json, so the contract sentence
    // covers it and this count moves with the set rather than around it.
    check(count($advertised) === 22, 'every one of the 22 --format=json commands was scanned (' . count($advertised) . ')');

    // Each newly enveloped command got a reviewed remediation arm, because the
    // default arm promises to "correct the named blocker" on exactly the path
    // that redacts every name.  A command silently falling back to it is the
    // regression this closes.
    $armed = new ReflectionMethod(\Duo\Cli::class, 'refusal_remediation');
    foreach ([
        'promotion-begin', 'promotion-abort', 'env-set', 'orphans', 'verify-canonical',
        'journal-report', 'pending', 'coverage', 'classify', 'lint', 'capabilities',
    ] as $command) {
        $arm = (string) $armed->invoke(null, $command);
        check(
            $arm !== "correct the named $command blocker, then retry the command" && $arm !== '',
            "$command carries a reviewed remediation arm, not the self-contradicting default"
        );
    }

    echo "\n== DUO-3399: none of the eleven inherits DUO-3398's publication branch ==\n";
    // DUO-3398 (#178) added a middle branch to halt_json_failure(): a
    // `duo: `-prefixed message that passes the sensitivity screen publishes
    // VERBATIM as <command>_refused. Its fix-forward (#180) then made that an
    // ALLOWLIST after refresh-export/scope inherited publication by accident,
    // and its own docblock states the rule these checks enforce: "A command
    // not on this list is redacted until someone audits it and adds it here
    // WITH its suite pins; silently inheriting publication is how the
    // DUO-3398 fix-forward incident happened."
    //
    // None of DUO-3399's eleven was part of that audit, so all eleven are
    // absent from the allowlist and stay fully redacted. Nothing pinned that
    // until now — these commands arrived after #180 was written, so adding
    // one to the allowlist would have changed its public contract with no
    // test objecting. Both halves are asserted: the membership itself, and a
    // live value-free `duo: ` refusal per command, so the pin cannot pass
    // just because a token pattern happened to trip the screen.
    $publicRefusalCommands = (new ReflectionClass(\Duo\Cli::class))
        ->getConstant('PUBLIC_REFUSAL_COMMANDS');
    check(is_array($publicRefusalCommands), 'the publication allowlist is readable as a constant');
    $duo3399Commands = [
        'promotion-begin', 'promotion-abort', 'env-set', 'orphans', 'verify-canonical',
        'journal-report', 'pending', 'coverage', 'classify', 'lint', 'capabilities',
    ];
    check(
        array_values(array_intersect($duo3399Commands, (array) $publicRefusalCommands)) === [],
        'no DUO-3399 command is on the publication allowlist'
    );

    // The live half, on the two commands this file already drives end to end.
    // The message is deliberately value-free and correctly `duo: `-prefixed —
    // exactly the shape that DOES publish for an allowlisted command — so the
    // command's absence from the list is the only thing keeping it redacted.
    foreach (['lint' => 'lint', 'capabilities' => 'capabilities'] as $method => $command) {
        $valueFree = "duo: $command refused for a perfectly value-free reason";
        \Duo\Policy::$failure = new RuntimeException($valueFree);
        $unpublished = invoke_json(static fn() => $cli->$method([], ['repo' => '/fixture', 'format' => 'json']));
        check(
            ($unpublished['error'] ?? null) === str_replace('-', '_', $command) . '_failed',
            "$command keeps the redacted _failed code for a value-free duo: refusal"
        );
        check(($unpublished['details_redacted'] ?? null) === true, "$command records redaction for a value-free duo: refusal");
        check(
            !str_contains((string) json_encode($unpublished), 'value-free reason'),
            "$command publishes none of a value-free duo: refusal's prose"
        );
    }
    \Duo\Policy::$failure = null;

    echo "\n== DUO-3399: gates behind the first one refuse through the same envelope ==\n";
    // The per-command loop above only ever reaches each command's FIRST gate.
    // These are the ones behind it, including the two contradictory-argument
    // gates that are not "missing" anything at all.
    $laterGates = [
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
        'env-set with both value sources' => [
            static fn() => $cli->env_set([], ['repo' => '/fixture', 'name' => 'acme_key', 'value' => 'v', 'stdin' => true, 'format' => 'json']),
            'env-set accepts exactly one value source',
            '--value or --stdin',
        ],
        'env-set with neither value source' => [
            static fn() => $cli->env_set([], ['repo' => '/fixture', 'name' => 'acme_key', 'format' => 'json']),
            'env-set requires a value source',
            '--value=<value> or --stdin',
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
        $gate = invoke_json($run);
        check(($gate['format'] ?? null) === 'duo-command-refusal/v1', "$case names the versioned format");
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

    echo "\n== DUO-3399: journal-report's only refusal path is its backend ==\n";
    // No required argument means no argument gate, so this command's catch
    // boundary is the whole of its machine contract.
    \Duo\Journal::$failure = new RuntimeException('duo: journal report refused at /Users/private-customer/site with sk_live_1234567890JOURNALLEAK');
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
    \Duo\Journal::$failure = null;

    echo "\n== DUO-3399: planted operator bytes stay out of a sample of the new machine paths ==\n";
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
        $lintOperator = "duo: lint refused reading the captured tree $token";
        \Duo\Policy::$failure = new RuntimeException($lintOperator);
        $lint = invoke_json(static fn() => $cli->lint([], ['repo' => '/fixture', 'format' => 'json']));
        $lintBytes = (string) json_encode($lint, JSON_UNESCAPED_SLASHES);
        check(($lint['format'] ?? null) === 'duo-command-refusal/v1', "lint $shape refusal names the versioned format");
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

        $capabilitiesOperator = "duo: capability registry refused $token";
        \Duo\Policy::$failure = new RuntimeException($capabilitiesOperator);
        $capabilities = invoke_json(static fn() => $cli->capabilities([], ['repo' => '/fixture', 'format' => 'json']));
        $capabilitiesBytes = (string) json_encode($capabilities, JSON_UNESCAPED_SLASHES);
        check(($capabilities['format'] ?? null) === 'duo-command-refusal/v1', "capabilities $shape refusal names the versioned format");
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
    foreach ([
        'lint' => ['lint', 'captured state tree'],
        'capabilities' => ['capabilities', 'capability registry'],
    ] as $method => [$command, $armFragment]) {
        \Duo\Policy::$failure = new RuntimeException("duo: $command refused at /Users/private-customer/site");
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
    \Duo\Policy::$failure = null;

    // A typed refusal raised by one of these backends must still pass through
    // with its own reviewed code, not flatten to the unclassified one.
    \Duo\Policy::$failure = new \Duo\CommandRefusalException(
        'adapter_certification_missing',
        'an adapter claims a capability with no named conformance evidence',
        'certify the adapter bundle or drop the claim, then report capabilities again',
        [[
            'code' => 'adapter_certification_missing',
            'surface' => 'options:acme_widget_color',
            'message' => 'claimed capability has no reviewed evidence bundle',
            'remediation' => 'certify the adapter bundle before claiming the capability',
        ]],
        'duo: /Users/private-customer/site adapter claims promote without evidence'
    );
    $typedCapabilities = invoke_json(static fn() => $cli->capabilities([], ['repo' => '/fixture', 'format' => 'json']));
    check(($typedCapabilities['error'] ?? null) === 'adapter_certification_missing', 'typed capabilities refusal retains its reviewed reason code');
    check(
        ($typedCapabilities['diagnostics'][0]['surface'] ?? null) === 'options:acme_widget_color',
        'typed capabilities refusal keeps its reviewed diagnostic'
    );
    check(!str_contains((string) json_encode($typedCapabilities), 'private-customer'), 'typed capabilities refusal omits operator-only evidence');
    \Duo\Policy::$failure = null;

    echo "\n== serialization failure still emits exactly one valid JSON value ==\n";
    \Duo\Apply::$planFailure = new \Duo\RepositoryCompilationException([[
        'severity' => 'error',
        'code' => 'non_finite_fixture',
        'path' => 'state/options/core.json',
        'locator' => 'records.fixture',
        'message' => INF,
    ]]);
    $serialization = invoke_json(static fn() => $cli->plan([], ['repo' => '/fixture', 'format' => 'json']));
    check(($serialization['error'] ?? null) === 'refusal_serialization_failed', 'serialization fallback has a stable reason code');
    check(($serialization['details_redacted'] ?? null) === true, 'serialization fallback refuses diagnostic details');

    echo "\n== human mode remains human and unchanged ==\n";
    WP_CLI::reset();
    \Duo\Capture::$failure = new RuntimeException('duo: human refusal stays human');
    try {
        $cli->capture([], ['repo' => '/fixture']);
        check(false, 'human refusal exits through WP_CLI::error');
    } catch (CliJsonHumanError $e) {
        check($e->getMessage() === 'duo: human refusal stays human', 'human refusal preserves the original actionable message');
    }
    check(WP_CLI::$lines === [], 'human refusal emits no JSON record');
    check(WP_CLI::$errors === ['duo: human refusal stays human'], 'human refusal reaches WP_CLI::error exactly once');

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
    \Duo\Apply::$planFailure = new RuntimeException($explainSecret);
    try {
        $cli->explain(['update:sha256:' . str_repeat('a', 64)], ['repo' => '/fixture']);
        check(false, 'human explain failure exits through WP_CLI::error');
    } catch (CliJsonHumanError $e) {
        check(
            $e->getMessage() === 'duo: explain refused at a private safety gate; run plan or capture for operator diagnosis, then rerun explain',
            'human explain uses constant safe failure guidance'
        );
        check(!str_contains($e->getMessage(), $explainSecret), 'human explain never forwards private exception detail');
    }
    check(WP_CLI::$lines === [], 'human explain failure emits no JSON record');
    // DUO-3397: the operator evidence these two commands print is the whole
    // point of keeping the raw message private, so assert it byte for byte.
    $humanCases = [
        'refresh-export backend refusal' => [
            static function () use ($cli): void {
                \Duo\RefreshExport::$failure = new RuntimeException(
                    'duo: refresh export refused — apply_in_progress is present; recover or complete the interrupted apply before observing production'
                );
                $cli->refresh_export([], ['repo' => '/Users/private-customer/site']);
            },
            'duo: refresh export refused — apply_in_progress is present; recover or complete the interrupted apply before observing production',
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
                \Duo\Policy::$failure = new RuntimeException('duo: scope refused — /Users/private-customer/site has no revision');
                $cli->scope([], ['repo' => '/Users/private-customer/site', 'roots' => 'all']);
            },
            'duo: scope refused — /Users/private-customer/site has no revision',
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
        // DUO-3399: every gate moved into a try below became a typed refusal,
        // and a typed refusal carries a SEPARATE operator message from its
        // public one.  Human mode still prints the operator message, so these
        // assert the pre-change prose byte for byte — sampled on lint and
        // capabilities for the backend path, and exhaustive over the gates,
        // because a gate's prose is the only place the change could silently
        // reword an operator's evidence.
        'lint backend refusal' => [
            static function () use ($cli): void {
                \Duo\Policy::$failure = new RuntimeException('duo: state dir not found: /Users/private-customer/site/state (nothing captured yet?)');
                $cli->lint([], ['repo' => '/Users/private-customer/site']);
            },
            'duo: state dir not found: /Users/private-customer/site/state (nothing captured yet?)',
        ],
        'lint missing --repo' => [
            static fn() => $cli->lint([], []),
            '--repo required',
        ],
        'capabilities backend refusal' => [
            static function () use ($cli): void {
                \Duo\Policy::$failure = new RuntimeException('duo: /Users/private-customer/site has no generated capability registry');
                $cli->capabilities([], ['repo' => '/Users/private-customer/site']);
            },
            'duo: /Users/private-customer/site has no generated capability registry',
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
        'env-set missing --repo' => [
            static fn() => $cli->env_set([], []),
            '--repo required',
        ],
        'env-set missing --name' => [
            static fn() => $cli->env_set([], ['repo' => '/fixture']),
            '--name required',
        ],
        'env-set with both value sources' => [
            static fn() => $cli->env_set([], ['repo' => '/fixture', 'name' => 'acme_key', 'value' => 'v', 'stdin' => true]),
            'pass exactly one of --value or --stdin, not both',
        ],
        'env-set with neither value source' => [
            static fn() => $cli->env_set([], ['repo' => '/fixture', 'name' => 'acme_key']),
            'one of --value=<value> or --stdin is required',
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
            '--set required, e.g. --set "post_meta:foo=runtime"',
        ],
        'journal-report backend refusal' => [
            static function () use ($cli): void {
                \Duo\Journal::$failure = new RuntimeException('duo: journal report refused — core manifest is not installed');
                $cli->journal_report([], []);
            },
            'duo: journal report refused — core manifest is not installed',
        ],
    ];
    foreach ($humanCases as $case => [$run, $expected]) {
        WP_CLI::reset();
        \Duo\RefreshExport::$failure = null;
        \Duo\Policy::$failure = null;
        \Duo\Journal::$failure = null;
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
    \Duo\RefreshExport::$failure = null;
    \Duo\Policy::$failure = null;
    \Duo\Journal::$failure = null;

    echo "\n== host preflight mirrors the same one-value contract ==\n";
    $tmp = sys_get_temp_dir() . '/duo-cli-json-refusal-' . bin2hex(random_bytes(6));
    check(mkdir($tmp, 0700), 'host fixture directory is created');
    $registry = $tmp . '/envs.json';
    file_put_contents($registry, json_encode(['envs' => new stdClass()], JSON_UNESCAPED_SLASHES));
    $host = realpath(__DIR__ . '/../../cli/duo');
    $root = realpath(__DIR__ . '/../..');
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

    $sourceFixture = __DIR__ . '/fixtures/cli-json-refusal-sources.php';
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
    $hostSpacedFormat = $runHost(['apply', 'missing', '--envs-file=' . $registry, '--format', 'json']);
    $hostSpacedPayload = json_decode($hostSpacedFormat['stdout'], true);
    check($hostSpacedFormat['status'] === 1 && $hostSpacedFormat['stderr'] === '', 'host spaced JSON format refusal is machine-only');
    check(($hostSpacedPayload['reason_code'] ?? null) === 'host_preflight_failed', 'host recognizes the spaced --format json spelling');

    $fakeWp = $tmp . '/wp';
    $statusRefusal = [
        'format' => 'duo-command-refusal/v1',
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
    $hostStatus = $runHost(['status', 'status-fixture', '--envs-file=' . $registry], $tmp);
    check($hostStatus['status'] === 1, 'human status preserves the refused plan exit');
    check($hostStatus['stdout'] === '', 'human status does not dump refusal JSON to stdout');
    check(str_contains($hostStatus['stderr'], '[plan_failed] plan refused'), 'human status renders the refusal code and message');
    check(!str_contains($hostStatus['stderr'], '"format"'), 'human status never dumps the raw JSON envelope');

    // DUO-3399: `duo pending`'s human table is fed by fetch_pending(), which
    // reads the agent's --format=json channel. Now that a pending refusal
    // answers there with the envelope, its stderr-else-stdout fallback dumped
    // raw JSON at the operator — the exact thing render_command_refusal_human()
    // exists to prevent. Driven the same way cmd_status's case is: a fake wp on
    // PATH that prints one envelope and exits 1.
    $pendingRefusal = [
        'format' => 'duo-command-refusal/v1',
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

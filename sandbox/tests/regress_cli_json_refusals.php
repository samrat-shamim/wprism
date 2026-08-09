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
    }

    final class Deploy {
        public static function run($repo, array $options): array {
            return [];
        }
    }
}

namespace {
    require __DIR__ . '/../../agent/src/Secrets.php';
    require __DIR__ . '/../../agent/src/CommandRefusal.php';
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
        'capture' => 'capture',
        'plan' => 'plan',
        'apply' => 'apply',
        'deploy' => 'deploy',
    ];
    foreach ($commands as $method => $command) {
        $payload = invoke_json(static fn() => $cli->$method([], ['format' => 'json']));
        check(($payload['format'] ?? null) === 'duo-command-refusal/v1', "$command refusal names the versioned format");
        check(($payload['ok'] ?? null) === false, "$command refusal is unambiguously not ok");
        check(($payload['command'] ?? null) === $command, "$command refusal names the public command");
        check(($payload['error'] ?? null) === 'invalid_arguments', "$command refusal has a stable argument error code");
        check(($payload['reason_code'] ?? null) === $payload['error'], "$command refusal exposes the error as a finite reason code");
        check(($payload['message'] ?? null) === "--repo is required for $command", "$command refusal identifies the missing argument");
        check(is_string($payload['remediation'] ?? null) && $payload['remediation'] !== '', "$command refusal carries remediation");
    }

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

    \Duo\Apply::$planFailure = new RuntimeException('duo: target drift requires a fresh capture');
    $plan = invoke_json(static fn() => $cli->plan([], ['repo' => '/fixture', 'format' => 'json']));
    check(($plan['error'] ?? null) === 'plan_failed', 'plan maps an unclassified runtime gate to plan_failed');
    check(
        ($plan['details_redacted'] ?? null) === true
            && !str_contains((string) json_encode($plan), 'target drift')
            && ($plan['diagnostics'][0]['code'] ?? null) === 'plan_failed',
        'unclassified plan exceptions expose only a safe generic diagnostic'
    );

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
    @unlink($registry);
    @unlink($fakeWp);
    @rmdir($tmp);

    if ($failures > 0) {
        fwrite(STDERR, "\n$failures failure(s)\n");
        exit(1);
    }
    echo "\nALL PASSED\n";
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../cli/src/Transport/Transport.php';
require_once __DIR__ . '/../../../../cli/src/Command/InitCommand.php';

use WPrism\Orchestrator\DriverCapabilityReport;
use WPrism\Orchestrator\EnvironmentDriver;
use WPrism\Orchestrator\InitCommand;
use WPrism\Orchestrator\Transport;

function fail_init_command(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function check_init_command(bool $ok, string $message): void {
    if (!$ok) fail_init_command($message);
    echo "ok: $message\n";
}

final class InitCommandTransport extends Transport {
    /** @var list<array{exit:int,stdout:string,stderr:string}> */
    private array $responses;
    public int $captureCalls = 0;
    /** @var list<list<string>> every wp argv this transport was handed, in order */
    public array $requests = [];

    /** @param list<array{exit:int,stdout:string,stderr:string}> $responses */
    public function __construct(array $responses, string $repo = '/fixture/repo') {
        parent::__construct('init-command-fixture', ['repo_path' => $repo]);
        $this->responses = $responses;
    }

    public function describe(): string { return 'init command fixture'; }
    protected function wpCommand(array $wpArgs): string { return 'unused'; }
    protected function rawCommand(string $script): string { return 'unused'; }

    public function captureWp(array $wpArgs): array {
        ++$this->captureCalls;
        $this->requests[] = array_values(array_map('strval', $wpArgs));
        return array_shift($this->responses)
            ?? ['exit' => 97, 'stdout' => '', 'stderr' => 'unexpected extra init command request'];
    }
}

function init_command_response(array $body): array {
    return [
        'exit' => 0,
        'stdout' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        'stderr' => '',
    ];
}

$digest = str_repeat('a', 64);
$stateRevision = str_repeat('b', 64);
$codeRevision = str_repeat('c', 64);
$proposal = [
    'format' => 'wprism-init-plan/v1',
    'digest' => $digest,
    'ready' => true,
    'advisories' => [],
    'environment' => [
        'wordpress' => '7.0.2',
        'php' => '8.3.33',
        'database' => ['access' => 'verified-read', 'server' => '11.8.8-MariaDB'],
        'home' => 'https://fixture.example.test',
    ],
    'code' => [
        'management' => 'managed-baseline-proposed',
        'files' => 1,
        'bytes' => 10,
        'source_revision' => $codeRevision,
        'roots' => [
            'content' => '/fixture/wp-content',
            'mu_plugins' => '/fixture/wp-content/mu-plugins',
            'plugins' => '/fixture/wp-content/plugins',
            'themes' => '/fixture/wp-content/themes',
        ],
        'components' => ['plugins' => [], 'themes' => []],
        // issue #3499: deliberately empty. This suite is about the command's
        // orchestration (refusal rendering, confirmation gating, exit codes),
        // and an empty inventory is what makes the host skip classification
        // entirely -- the offline corpus contacts no release registry. The
        // classification path itself is covered by
        // sandbox/tests/offline/code-half/regress_init_code_split.php against a
        // local file:// archive fixture.
        'component_inventory' => [],
        'split' => [],
        'declaration' => ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'],
        'active_plugins' => [],
        'active_theme' => ['stylesheet' => 'fixture', 'template' => 'fixture'],
    ],
    'state' => [
        'baseline' => 'capture-consistent-snapshot',
        'config_identity' => 'absent',
        'existing_config' => 'absent',
        'repository' => '/fixture/repo',
        'repository_identity' => 'sha256:' . str_repeat('d', 64),
        'adapters' => [],
        'config' => [
            'code' => ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'],
            'manifests' => [],
            'policy' => ['post_types' => ['post'], 'taxonomies' => ['category']],
            'spec_version' => 2,
        ],
        'git' => ['mode' => 'initialize-on-confirm', 'version' => 'git version 2.51.0'],
        'git_lfs' => [
            'required' => false,
            'version' => 'unavailable',
            'config_identity' => 'initialize-on-confirm',
        ],
        'gitattributes_identity' => 'absent',
        'gitignore_identity' => 'absent',
        'ledger' => ['rows' => 0, 'tables' => 0],
        'media' => ['strategy' => 'local', 'attachments' => 0, 'unavailable' => 0],
        'risk_surfaces' => [
            'options' => [], 'user_meta' => [],
            'oversized' => ['options' => 0, 'user_meta' => 0],
            'scanned' => ['options' => 0, 'user_meta' => 0],
            'limits' => ['rows_per_surface' => 5000, 'bytes_per_surface' => 8388608],
            'truncated' => false,
        ],
    ],
    'unsupported' => [],
];
$result = [
    'format' => 'wprism-init-result/v1',
    'proposal_digest' => $digest,
    'baseline' => ['kind' => 'state-capture', 'revision_hash' => $stateRevision],
    'capture' => [
        'revision_hash' => $stateRevision,
        'initial_code_baseline' => [
            'enabled' => true, 'completed' => true, 'code_revision' => $codeRevision,
        ],
        'initial_publication_cleanup' => 'clean',
    ],
    'code' => [
        'descriptor' => ['code_revision' => $codeRevision],
        'lifecycle' => [
            'enabled' => true, 'completed' => true, 'code_revision' => $codeRevision,
        ],
        'management' => 'managed-baseline',
        'revision_hash' => $codeRevision,
        'source' => 'code/wp-content',
    ],
    'state' => [
        'git' => 'existing-worktree',
        'repository' => '/fixture/repo',
        'site_config' => '/fixture/repo/site.wprism.json',
    ],
    'unsupported' => [],
];

$argumentDriver = new InitCommandTransport([]);
$renderedRefusals = [];
$renderRefusal = static function (array $refusal) use (&$renderedRefusals): void {
    $renderedRefusals[] = $refusal;
    fwrite(STDERR, '[fixture refusal] ' . ($refusal['message'] ?? 'unknown') . "\n");
};
$statusNever = static fn(EnvironmentDriver $driver): int => 99;
$readNever = static fn(): mixed => null;
$exit = InitCommand::run($argumentDriver, ['--unsupported'], $renderRefusal, $statusNever, $readNever);
check_init_command($exit === 1, 'unsupported init arguments refuse at the command boundary');
check_init_command($argumentDriver->captureCalls === 0, 'argument refusal occurs before proposal target contact');

$blockedProposal = $proposal;
$blockedProposal['ready'] = false;
$blockedProposal['unsupported'] = [[
    'code' => 'fixture_blocked', 'kind' => 'fixture', 'extension' => 'fixture',
    'reason' => 'fixture is blocked', 'remediation' => 'repair the fixture',
]];
$blockedDriver = new InitCommandTransport([init_command_response($blockedProposal)]);
$blockedExit = InitCommand::run($blockedDriver, ['--yes'], $renderRefusal, $statusNever, $readNever);
check_init_command($blockedExit === 2, 'blocked init proposals preserve the readiness exit');
check_init_command($blockedDriver->captureCalls === 1, 'blocked proposal does not confirm or invoke status');

$refusal = [
    'format' => 'wprism-command-refusal/v1',
    'ok' => false,
    'command' => 'init',
    'error' => 'init_refused',
    'reason_code' => 'init_refused',
    'message' => 'fixture refused initialization',
    'remediation' => 'repair the fixture before retrying',
    'diagnostics' => [[
        'code' => 'fixture', 'surface' => 'state',
        'message' => 'fixture refusal', 'remediation' => 'inspect the fixture',
    ]],
];
$refusalDriver = new InitCommandTransport([[
    'exit' => 1,
    'stdout' => json_encode($refusal, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    'stderr' => "Container fixture Creating\n",
]]);
$refusalExit = InitCommand::run($refusalDriver, ['--yes'], $renderRefusal, $statusNever, $readNever);
check_init_command($refusalExit === 1, 'typed init refusal preserves the host failure exit');
check_init_command($refusalDriver->captureCalls === 1, 'proposal refusal does not invoke confirmation or status');

$confirmRefusalDriver = new InitCommandTransport([
    init_command_response($proposal),
    [
        'exit' => 1,
        'stdout' => json_encode($refusal, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        'stderr' => "Container fixture Created\n",
    ],
]);
$renderedRefusals = [];
$confirmRefusalExit = InitCommand::run(
    $confirmRefusalDriver,
    ['--yes'],
    $renderRefusal,
    $statusNever,
    $readNever
);
check_init_command($confirmRefusalExit === 1, 'typed confirmation refusal preserves the host failure exit');
check_init_command($confirmRefusalDriver->captureCalls === 2, 'confirmation refusal performs proposal then confirmation only');
check_init_command(count($renderedRefusals) === 1 && $renderedRefusals[0] === $refusal, 'confirmation refusal forwards the exact v1 envelope to the renderer');

$successDriver = new InitCommandTransport([init_command_response($proposal), init_command_response($result)]);
$statusCalls = 0;
$statusDriver = null;
ob_start();
$successExit = InitCommand::run(
    $successDriver,
    ['--yes'],
    $renderRefusal,
    static function (EnvironmentDriver $driver) use (&$statusCalls, &$statusDriver): int {
        ++$statusCalls;
        $statusDriver = $driver;
        return 0;
    },
    $readNever
);
$successOutput = (string) ob_get_clean();
check_init_command($successExit === 0, 'successful init returns the status runner result');
check_init_command($successDriver->captureCalls === 2, 'successful init performs proposal then exact-digest confirmation');
check_init_command($statusCalls === 1 && $statusDriver === $successDriver, 'successful init verifies managed scope through the injected status boundary');
check_init_command(str_contains($successOutput, 'Initialized canonical state baseline ' . $stateRevision), 'successful init preserves baseline output');
check_init_command(str_contains($successOutput, 'Verifying selected managed scope:'), 'successful init preserves post-confirmation verification output');

$affirmativeDriver = new InitCommandTransport([init_command_response($proposal), init_command_response($result)]);
$affirmativeStatusCalls = 0;
$affirmativeExit = InitCommand::run(
    $affirmativeDriver,
    [],
    $renderRefusal,
    static function (EnvironmentDriver $driver) use (&$affirmativeStatusCalls): int {
        ++$affirmativeStatusCalls;
        return 0;
    },
    static fn(): mixed => "yes\n"
);
check_init_command($affirmativeExit === 0, 'affirmative interactive input reaches confirmation successfully');
check_init_command($affirmativeDriver->captureCalls === 2 && $affirmativeStatusCalls === 1, 'affirmative interactive input confirms once and verifies status once');

$cancelDriver = new InitCommandTransport([init_command_response($proposal)]);
$cancelExit = InitCommand::run($cancelDriver, [], $renderRefusal, $statusNever, static fn(): mixed => null);
check_init_command($cancelExit === 1, 'interactive init cancellation preserves the failure exit');
check_init_command($cancelDriver->captureCalls === 1, 'interactive cancellation does not confirm after the proposal');

$statusFailureDriver = new InitCommandTransport([init_command_response($proposal), init_command_response($result)]);
ob_start();
$statusFailureExit = InitCommand::run(
    $statusFailureDriver,
    ['--yes'],
    $renderRefusal,
    static fn(EnvironmentDriver $driver): int => 7,
    $readNever
);
$statusFailureOutput = (string) ob_get_clean();
check_init_command($statusFailureExit === 7, 'post-confirmation status failures propagate their exact exit');
check_init_command(!str_contains($statusFailureOutput, 'Managed state scope is clean.'), 'status failure stops before init next steps');

// ------------------------------------------------- T6 §3.4: --allow-unmanaged-plugins

$exit = InitCommand::run(
    new InitCommandTransport([]),
    ['--allow-unmanaged-plugins', '--unsupported'],
    $renderRefusal,
    $statusNever,
    $readNever
);
check_init_command($exit === 1, 'the flag does not widen the argument grammar to anything else');

$plainDriver = new InitCommandTransport([init_command_response($proposal), init_command_response($result)]);
ob_start();
InitCommand::run($plainDriver, ['--yes'], $renderRefusal, static fn(EnvironmentDriver $d): int => 0, $readNever);
ob_end_clean();
check_init_command(
    !in_array('--allow-unmanaged-plugins', $plainDriver->requests[0] ?? [], true)
    && !in_array('--allow-unmanaged-plugins', $plainDriver->requests[1] ?? [], true),
    'without the flag neither target call carries it'
);

$allowDriver = new InitCommandTransport([init_command_response($proposal), init_command_response($result)]);
ob_start();
$allowExit = InitCommand::run(
    $allowDriver,
    ['--yes', '--allow-unmanaged-plugins'],
    $renderRefusal,
    static fn(EnvironmentDriver $d): int => 0,
    $readNever
);
ob_end_clean();
check_init_command($allowExit === 0, 'init succeeds with the flag');
// BOTH calls, not just the proposal. The proposal digest binds the plan the
// flag produced, so a confirmation that re-planned without it would fail the
// digest bind — the right failure, but an unreadable one.
check_init_command(
    in_array('--allow-unmanaged-plugins', $allowDriver->requests[0] ?? [], true),
    'the flag is forwarded to the proposal call'
);
check_init_command(
    in_array('--allow-unmanaged-plugins', $allowDriver->requests[1] ?? [], true),
    'and to the confirmation call, so both describe the same site'
);

// T6 §3.4's exact advisory line. The walk greps for it, so it is asserted as a
// whole line rather than as a substring of a longer sentence.
$unmanagedProposal = $proposal;
$unmanagedProposal['advisories'] = [[
    'code' => 'active_plugin_without_adapter',
    'extension' => 'wpforms-lite/wpforms.php',
    'kind' => 'plugin',
    'reason' => 'no installed manifest declares this active plugin identity',
    'remediation' => 'install or author one versioned adapter, then rerun wprism init',
]];
$unmanagedDriver = new InitCommandTransport([
    init_command_response($unmanagedProposal),
    init_command_response($result),
]);
ob_start();
InitCommand::run(
    $unmanagedDriver,
    ['--yes', '--allow-unmanaged-plugins'],
    $renderRefusal,
    static fn(EnvironmentDriver $d): int => 0,
    $readNever
);
$unmanagedOutput = (string) ob_get_clean();
check_init_command(
    str_contains(
        $unmanagedOutput,
        "\n  UNMANAGED PLUGIN wpforms-lite/wpforms.php [active_plugin_without_adapter]\n"
    ),
    'an unmanaged plugin prints T6 §3.4\'s exact line, on its own'
);
check_init_command(
    str_contains($unmanagedOutput, 'advisories (init proceeds'),
    'under an advisories heading, so the flag visibly did something'
);
check_init_command(
    str_contains($unmanagedOutput, '    no installed manifest declares this active plugin identity'),
    'with the reason on its own indented line, keeping the identity line greppable'
);
check_init_command(
    !str_contains($unmanagedOutput, 'ADVISORY PLUGIN'),
    'and never as an ordinary ADVISORY row, which reads as one more caveat in a list'
);

// An ordinary advisory keeps its shape: T6 changed one code's rendering, not
// the block's.
$mixedProposal = $proposal;
$mixedProposal['advisories'] = [[
    'code' => 'active_theme_code_only',
    'extension' => 'shop-theme',
    'kind' => 'theme',
    'reason' => 'theme bytes will be inventoried as code',
    'remediation' => 'install a certified theme adapter',
]];
$mixedDriver = new InitCommandTransport([
    init_command_response($mixedProposal),
    init_command_response($result),
]);
ob_start();
InitCommand::run($mixedDriver, ['--yes'], $renderRefusal, static fn(EnvironmentDriver $d): int => 0, $readNever);
$mixedOutput = (string) ob_get_clean();
check_init_command(
    str_contains($mixedOutput, 'ADVISORY THEME shop-theme [active_theme_code_only]'),
    'every other advisory code renders exactly as it did before'
);

echo "PASS: init command\n";

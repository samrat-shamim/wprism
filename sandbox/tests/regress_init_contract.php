<?php
// Offline regression for DUO-3336's public proposal/confirmation boundary.

declare(strict_types=1);

require_once __DIR__ . '/../../cli/src/Transport/Transport.php';
require_once __DIR__ . '/../../cli/src/Onboarding/Init.php';

use Duo\Orchestrator\Init;
use Duo\Orchestrator\Transport;

final class InitTransport extends Transport {
    /** @var list<array{exit:int,stdout:string,stderr:string}> */
    private array $responses;
    /** @var list<list<string>> */
    public array $calls = [];

    /** @param list<array{exit:int,stdout:string,stderr:string}> $responses */
    public function __construct(array $responses, string $repo = '/srv/shop-state') {
        parent::__construct('shop', ['repo_path' => $repo]);
        $this->responses = $responses;
    }

    public function describe(): string { return 'init regression transport'; }
    protected function wpCommand(array $wpArgs): string { return 'unused'; }
    protected function rawCommand(string $script): string { return 'unused'; }

    public function captureWp(array $wpArgs): array {
        $this->calls[] = $wpArgs;
        return array_shift($this->responses)
            ?? ['exit' => 97, 'stdout' => '', 'stderr' => 'unexpected extra init request'];
    }
}

function fail(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function check(bool $ok, string $message): void {
    if (!$ok) fail($message);
    echo "ok: $message\n";
}

function response(array $body): array {
    return ['exit' => 0, 'stdout' => json_encode($body, JSON_UNESCAPED_SLASHES) . "\n", 'stderr' => ''];
}

$digest = str_repeat('a', 64);
$stateRevision = str_repeat('b', 64);
$codeRevision = str_repeat('c', 64);
$lifecycle = [
    'enabled' => true,
    'completed' => true,
    'code_revision' => $codeRevision,
];
$proposal = [
    'format' => 'duo-init-plan/v1',
    'digest' => $digest,
    'ready' => true,
    'advisories' => [[
        'code' => 'active_theme_code_only', 'extension' => 'shop-theme', 'kind' => 'theme',
        'reason' => 'theme bytes are code-only', 'remediation' => 'install a theme adapter if needed',
    ], [
        'code' => 'repository_external_writer_exclusion', 'extension' => '/srv/shop-state', 'kind' => 'repository',
        'reason' => 'Duo locks serialize Duo writers only', 'remediation' => 'quiesce non-Duo repository writers',
    ]],
    'environment' => [
        'wordpress' => '7.0.2', 'php' => '8.3.33',
        'database' => ['access' => 'verified-read', 'server' => '11.8.8-MariaDB'],
        'home' => 'https://shop.example.test',
    ],
    'code' => [
        'management' => 'managed-baseline-proposed',
        'files' => 42,
        'bytes' => 8192,
        'source_revision' => $codeRevision,
        'roots' => [
            'content' => '/var/www/html/wp-content',
            'mu_plugins' => '/var/www/html/wp-content/mu-plugins',
            'plugins' => '/var/www/html/wp-content/plugins',
            'themes' => '/var/www/html/wp-content/themes',
        ],
        'components' => ['plugins' => ['woocommerce'], 'themes' => ['shop-theme']],
        'declaration' => ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'],
        'active_plugins' => [['basename' => 'woocommerce/woocommerce.php', 'version' => '11.0.0']],
        'active_theme' => ['stylesheet' => 'shop-theme', 'template' => 'shop-theme'],
    ],
    'state' => [
        'baseline' => 'capture-consistent-snapshot',
        'config_identity' => 'absent',
        'existing_config' => 'absent',
        'repository' => '/srv/shop-state',
        'repository_identity' => 'sha256:' . str_repeat('d', 64),
        'adapters' => [['name' => 'core'], ['name' => 'woocommerce']],
        'config' => [
            'code' => ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'],
            'manifests' => [['digest' => str_repeat('d', 64), 'name' => 'core']],
            'policy' => ['post_types' => ['post'], 'taxonomies' => ['category']],
            'spec_version' => 2,
        ],
        'git' => ['mode' => 'initialize-on-confirm', 'version' => 'git version 2.51.0'],
        'gitignore_identity' => 'absent',
        'ledger' => ['rows' => 0, 'tables' => 0],
        'media' => ['strategy' => 'local', 'attachments' => 2, 'unavailable' => 0],
        'risk_surfaces' => [
            'options' => ['stripe key' => 1],
            'user_meta' => ['email address' => 2],
            'oversized' => ['options' => 3, 'user_meta' => 4],
            'scanned' => ['options' => 120, 'user_meta' => 80],
            'limits' => ['rows_per_surface' => 5000, 'bytes_per_surface' => 8388608],
            'truncated' => true,
        ],
    ],
    'unsupported' => [],
];
$result = [
    'format' => 'duo-init-result/v1',
    'proposal_digest' => $digest,
    'baseline' => ['kind' => 'state-capture', 'revision_hash' => $stateRevision],
    'capture' => [
        'revision_hash' => $stateRevision,
        'initial_code_baseline' => $lifecycle,
        'initial_publication_cleanup' => 'clean',
    ],
    'code' => [
        'descriptor' => ['code_revision' => $codeRevision],
        'lifecycle' => $lifecycle,
        'management' => 'managed-baseline',
        'revision_hash' => $codeRevision,
        'source' => 'code/wp-content',
    ],
    'state' => [
        'git' => 'existing-worktree',
        'repository' => '/srv/shop-state',
        'site_config' => '/srv/shop-state/site.duo.json',
    ],
    'unsupported' => [],
];

$transport = new InitTransport([response($proposal), response($result)]);
check(Init::proposal($transport) === $proposal, 'proposal JSON is returned without host-side reinterpretation');
check($transport->calls[0] === ['duo', 'init', '--repo=/srv/shop-state', '--format=json'], 'proposal uses the authenticated target agent and repository path');
check(Init::confirm($transport, $digest) === $result, 'confirmation result is returned');
check($transport->calls[1] === ['duo', 'init', '--repo=/srv/shop-state', '--confirm=' . $digest, '--format=json'], 'confirmation sends only the reviewed digest, never a mutable config payload');
$recoveryResult = array_replace($result, ['recovery' => 'committed-finalized']);
check(
    Init::confirm(new InitTransport([response($recoveryResult)]), $digest) === $recoveryResult,
    'verified committed-init finalization uses the same fail-closed result contract'
);

$slashTransport = new InitTransport([response($proposal), response($result)], '/srv/shop-state/');
check(Init::proposal($slashTransport) === $proposal, 'host normalizes a configured trailing slash when validating the target proposal');
check(Init::confirm($slashTransport, $digest) === $result, 'host normalizes a configured trailing slash when validating the target result');

$rendered = implode("\n", Init::render($proposal));
check(str_contains($rendered, 'code: managed-baseline-proposed'), 'rendering preserves the separate code/state contract');
check(str_contains($rendered, 'active plugin: woocommerce/woocommerce.php 11.0.0'), 'rendering inventories active plugin versions');
check(str_contains($rendered, 'core, woocommerce'), 'rendering names selected adapters');
check(str_contains($rendered, 'ADVISORY THEME shop-theme [active_theme_code_only]'), 'rendering exposes code-only active theme state coverage');
check(str_contains($rendered, 'ADVISORY REPOSITORY /srv/shop-state [repository_external_writer_exclusion]'), 'rendering exposes the first-init external-writer exclusion');
check(str_contains($rendered, '1 secret-shaped option value(s), 2 PII-shaped user-meta value(s)'), 'rendering exposes redacted risk counts');
check(str_contains($rendered, '3 oversized option value(s) and 4 oversized user-meta value(s) were not scanned'), 'rendering exposes redacted oversized omissions');
check(str_contains($rendered, 'after scanning 120 option value(s) and 80 user-meta value(s)'), 'rendering exposes deterministic bounded-scan coverage');
check(str_contains($rendered, 'redacted counts are incomplete'), 'rendering discloses a bounded risk scan instead of implying completeness');
check(!str_contains($rendered, 'sk_live_') && !str_contains($rendered, '@example.'), 'rendering cannot expose secret or PII values from the count-only report');

$next = implode("\n", Init::nextSteps('shop', '/srv/shop-state'));
foreach (['branch', '"$DUO_CLI" capture \'shop\'', '"$DUO_CLI" plan \'shop\'', '"$DUO_CLI" promote \'shop\'', 'rollback'] as $step) {
    check(str_contains($next, $step), "workflow guide includes $step");
}
check(str_contains($next, 'Coverage outside the selected adapters remains advisory'), 'guide does not turn a managed-scope proof into a whole-site guarantee');
check(str_contains($next, "git -C '/srv/shop-state'"), 'guide runs Git in the target-owned worktree');
check(str_contains($next, "remote add origin 'YOUR_GIT_URL'")
    && str_contains($next, 'symbolic-ref --quiet --short HEAD')
    && str_contains($next, 'push -u origin "HEAD:refs/heads/$TARGET_BRANCH"')
    && str_contains($next, "git clone --branch \"\$TARGET_BRANCH\" 'YOUR_GIT_URL' 'YOUR_WORKSPACE'"),
    'guide carries the initialized target repository through publish and developer checkout');
check(
    str_contains($next, "Duo commands for 'shop' always operate on its configured repo_path (/srv/shop-state)")
        && str_contains($next, 'never on \'YOUR_WORKSPACE\'')
        && str_contains($next, 'untracked .duo-envs.json overlay')
        && str_contains($next, 'Point or materialize that target environment')
        && str_contains($next, "export DUO_CLI='YOUR_DUO_CLI'")
        && str_contains($next, 'it is not in the site repo'),
    'guide distinguishes the developer checkout from the target-bound environment before any Duo mutation'
);
$hostileNext = implode("\n", Init::nextSteps('prod; echo PWNED', '/srv/shop-state'));
check(
    str_contains($hostileNext, "capture 'prod; echo PWNED'")
        && !str_contains($hostileNext, 'capture prod; echo PWNED'),
    'rendered Duo handoff shell-quotes an environment name containing metacharacters'
);

// Run every rendered Git handoff command against paths containing spaces.
// This keeps the first-run guide copyable and catches shell-significant
// placeholders or a missing -C/argument quote instead of merely asserting
// that some promising words were printed.
$handoffRoot = sys_get_temp_dir() . '/duo-init-handoff-' . bin2hex(random_bytes(6));
$handoffTarget = $handoffRoot . '/target repo';
$handoffRemote = $handoffRoot . '/remote repo.git';
$handoffWorkspace = $handoffRoot . '/developer workspace';
register_shutdown_function(static function () use ($handoffRoot): void {
    exec('rm -rf ' . escapeshellarg($handoffRoot));
});
mkdir($handoffTarget . '/code', 0777, true);
mkdir($handoffTarget . '/state', 0777, true);
mkdir($handoffTarget . '/media', 0777, true);
file_put_contents(
    $handoffTarget . '/.gitignore',
    (string) file_get_contents(__DIR__ . '/../site-repo.gitignore.template')
);
file_put_contents($handoffTarget . '/site.duo.json', "{}\n");
file_put_contents($handoffTarget . '/code/.keep', "\n");
file_put_contents($handoffTarget . '/state/.keep', "\n");
file_put_contents($handoffTarget . '/media/.keep', "\n");
mkdir($handoffTarget . '/code/wp-content/plugins/acme/.duo', 0777, true);
file_put_contents($handoffTarget . '/code/wp-content/plugins/acme/.duo/config.json', "{}\n");
file_put_contents($handoffTarget . '/code/wp-content/plugins/acme/.duo-envs.json', "{}\n");
file_put_contents($handoffTarget . '/code/wp-content/plugins/acme/.duo-env-values.json', "{}\n");
$nestedProtocolFiles = [
    '.tmp-cache', '.duo-init-code-example', '.duo-init-attempt', '.duo-init-attempt.next',
    '.x.duo-init-y', 'state.capture.lock', 'state.capture-staging/payload',
    'state.capture-backup/payload', 'state.capture-intent', 'state.capture-receipt',
    'state.capture-intent.tmp.1', 'state.capture-receipt.tmp.1',
    'state.capture-intent.previous', 'state.capture-intent.next',
    'state.capture-receipt.previous', 'state.capture-receipt.next',
];
foreach ($nestedProtocolFiles as $relative) {
    $path = $handoffTarget . '/code/wp-content/plugins/acme/' . $relative;
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
    file_put_contents($path, "nested vendored payload\n");
}
foreach ([
    'git init --initial-branch=develop ' . escapeshellarg($handoffTarget),
    'git -C ' . escapeshellarg($handoffTarget) . ' config user.name ' . escapeshellarg('Duo Regression'),
    'git -C ' . escapeshellarg($handoffTarget) . ' config user.email ' . escapeshellarg('duo-regression@example.invalid'),
    'git init --bare --initial-branch=main ' . escapeshellarg($handoffRemote),
] as $setupCommand) {
    exec($setupCommand . ' 2>&1', $setupOutput, $setupExit);
    check($setupExit === 0, "handoff fixture setup executes: $setupCommand");
}
$handoffCommands = [];
foreach (Init::nextSteps('shop', $handoffTarget) as $line) {
    if (preg_match('/^  ([1-7])\. (.+?)(?: +#.*)?$/', $line, $match) === 1) {
        $handoffCommands[(int) $match[1]] = $match[2];
    }
}
check(array_keys($handoffCommands) === [1, 2, 3, 4, 5, 6, 7], 'guide renders a complete executable repository handoff');
foreach ($handoffCommands as $number => $command) {
    $handoffCommands[$number] = str_replace(
        ["'YOUR_GIT_URL'", "'YOUR_WORKSPACE'", "'YOUR_BRANCH'"],
        [escapeshellarg($handoffRemote), escapeshellarg($handoffWorkspace), escapeshellarg('feature/first-change')],
        $command
    );
}
$handoffCommands[6] = str_replace("'YOUR_DUO_CLI'", escapeshellarg('/bin/true'), $handoffCommands[6]);
exec(implode("\n", $handoffCommands) . ' 2>&1', $commandOutput, $commandExit);
check($commandExit === 0, 'complete rendered handoff executes after placeholder replacement');
exec('git -C ' . escapeshellarg($handoffWorkspace) . ' branch --show-current 2>&1', $branchOutput, $branchExit);
check($branchExit === 0 && implode("\n", $branchOutput) === 'feature/first-change', 'rendered handoff leaves the developer checkout on the requested branch');
exec('git --git-dir=' . escapeshellarg($handoffRemote) . ' show-ref --verify refs/heads/develop 2>&1', $developOutput, $developExit);
check($developExit === 0, 'rendered handoff publishes the existing non-main branch despite a different remote default');
exec('git -C ' . escapeshellarg($handoffWorkspace) . ' merge-base --is-ancestor origin/develop HEAD 2>&1', $baselineOutput, $baselineExit);
check($baselineExit === 0, 'developer feature branch starts from the exact initialized target baseline');
check(
    is_file($handoffWorkspace . '/code/wp-content/plugins/acme/.duo/config.json')
        && is_file($handoffWorkspace . '/code/wp-content/plugins/acme/.duo-envs.json')
        && is_file($handoffWorkspace . '/code/wp-content/plugins/acme/.duo-env-values.json'),
    'root-anchored authority ignores preserve legitimate same-named files inside vendored plugin code'
);
foreach ($nestedProtocolFiles as $relative) {
    check(
        is_file($handoffWorkspace . '/code/wp-content/plugins/acme/' . $relative),
        "root-anchored protocol ignores preserve nested vendored $relative"
    );
}

$badJson = new InitTransport([['exit' => 0, 'stdout' => "not-json\n", 'stderr' => '']]);
try {
    Init::proposal($badJson);
    fail('invalid target JSON was accepted');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(), 'invalid JSON'), 'invalid target JSON fails closed');
}

// DUO-3421: the target's refusal envelope arrives on STDOUT while a docker
// transport's stderr always carries `docker compose run` progress noise. The
// stderr-first rule handed the operator that noise and dropped the reason
// code, the remediation, and the redaction witness — a refusal-transparency
// loss of exactly the DUO-3398 shape, reproduced here without docker by
// planting the noise the live transport really emits.
$refusalEnvelope = [
    'format' => 'duo-command-refusal/v1',
    'ok' => false,
    'command' => 'init',
    'error' => 'init_failed',
    'reason_code' => 'init_failed',
    'message' => 'init refused at an unclassified safety gate',
    'remediation' => 'correct the named init blocker, then retry the command',
    'details_redacted' => true,
    'diagnostics' => [[
        'code' => 'init_failed',
        'message' => 'init refused at an unclassified safety gate',
        'remediation' => 'correct the named init blocker, then retry the command',
    ]],
];
$composeNoise = " Container duo-pair-cli1-run-6a2f Creating \n Container duo-pair-cli1-run-6a2f Created\n";
foreach (['proposal', 'confirmation'] as $noisyPhase) {
    $noisy = new InitTransport([[
        'exit' => 1,
        'stdout' => json_encode($refusalEnvelope, JSON_UNESCAPED_SLASHES) . "\n",
        'stderr' => $composeNoise,
    ]]);
    try {
        $noisyPhase === 'proposal'
            ? Init::proposal($noisy)
            : Init::confirm($noisy, $digest);
        fail("a refused init $noisyPhase was accepted");
    } catch (\Duo\Orchestrator\InitRefusalException $refused) {
        check(
            $refused->refusal === $refusalEnvelope,
            "a refused init $noisyPhase carries the target's complete v1 envelope for rendering, not a flattened string"
        );
        check(
            !str_contains($refused->getMessage(), 'Container duo-')
                && str_contains($refused->getMessage(), "init $noisyPhase failed"),
            "a refused init $noisyPhase names its phase without pasting transport progress noise"
        );
    } catch (RuntimeException $wrong) {
        fail("refused init $noisyPhase surfaced transport noise instead of the envelope: {$wrong->getMessage()}");
    }
}
// Anything that is not a v1 envelope keeps the original stderr-else-stdout
// passthrough, so non-docker transports and non-envelope failures are
// untouched by the change above.
try {
    Init::proposal(new InitTransport([[
        'exit' => 255,
        'stdout' => '',
        'stderr' => "Error: 'duo' is not a registered wp command.\n",
    ]]));
    fail('a non-envelope init failure was accepted');
} catch (\Duo\Orchestrator\InitRefusalException) {
    fail('a non-envelope init failure was misread as a refusal envelope');
} catch (RuntimeException $passthrough) {
    check(
        str_contains($passthrough->getMessage(), 'not a registered wp command'),
        'a non-envelope init failure still passes the target stderr through unchanged'
    );
}
$initCommandSource = (string) file_get_contents(__DIR__ . '/../../cli/duo');
$initHandlerSource = (string) file_get_contents(__DIR__ . '/../../cli/src/Command/InitCommand.php');
check(
    substr_count($initCommandSource, 'return InitCommand::run(') === 1
        && substr_count($initCommandSource, 'function cmd_init(EnvironmentDriver $t, array $extra, ?string $envsFileOverride = null): int {') === 1
        && substr_count($initHandlerSource, '$renderRefusal($e->refusal);') === 2
        && substr_count($initHandlerSource, 'callable $readLine') === 1,
    'init keeps a thin cli facade while both refusal phases use the shared host renderer'
);

foreach ([
    'empty proposal' => [],
    'wrong proposal format' => array_replace($proposal, ['format' => 'duo-init-plan/v0']),
    'malformed proposal digest' => array_replace($proposal, ['digest' => 'abc']),
    'non-boolean proposal readiness' => array_replace($proposal, ['ready' => 1]),
    'sparse ready proposal' => [
        'format' => 'duo-init-plan/v1', 'digest' => $digest, 'ready' => true,
        'environment' => [], 'code' => [],
        'state' => ['repository' => '/srv/shop-state', 'repository_identity' => 'sha256:' . str_repeat('d', 64)],
        'unsupported' => [], 'advisories' => [],
    ],
    'blocked proposal without blockers' => array_replace($proposal, ['ready' => false]),
    'proposal for another repository' => array_replace_recursive($proposal, ['state' => ['repository' => '/srv/other']]),
] as $label => $invalidProposal) {
    try {
        Init::proposal(new InitTransport([response($invalidProposal)]));
        fail("$label was accepted");
    } catch (RuntimeException $expected) {
        check(str_contains($expected->getMessage(), 'incompatible or incomplete contract'), "$label fails closed");
    }
}

foreach ([
    'empty result' => [],
    'wrong result format' => array_replace($result, ['format' => 'duo-init-result/v0']),
    'mismatched proposal digest' => array_replace($result, ['proposal_digest' => str_repeat('e', 64)]),
    'malformed state baseline hash' => array_replace_recursive($result, ['baseline' => ['revision_hash' => 'bad']]),
    'capture baseline mismatch' => array_replace_recursive($result, ['capture' => ['revision_hash' => str_repeat('d', 64)]]),
    'malformed code baseline hash' => array_replace_recursive($result, ['code' => ['revision_hash' => 'bad']]),
    'descriptor revision mismatch' => array_replace_recursive($result, ['code' => ['descriptor' => ['code_revision' => str_repeat('d', 64)]]]),
    'incomplete lifecycle receipt' => array_replace_recursive($result, ['code' => ['lifecycle' => ['completed' => false]]]),
    'capture lifecycle mismatch' => array_replace_recursive($result, ['capture' => ['initial_code_baseline' => ['completed' => false]]]),
    'retained initial publication cleanup' => array_replace_recursive($result, ['capture' => ['initial_publication_cleanup' => 'retained']]),
    'result for another repository' => array_replace_recursive($result, ['state' => ['repository' => '/srv/other']]),
    'result retaining unsupported coverage' => array_replace($result, ['unsupported' => [['code' => 'still-blocked']]]),
] as $label => $invalidResult) {
    try {
        Init::confirm(new InitTransport([response($invalidResult)]), $digest);
        fail("$label was accepted");
    } catch (RuntimeException $expected) {
        check(str_contains($expected->getMessage(), 'incompatible or incomplete result'), "$label fails closed");
    }
}

try {
    Init::confirm(new InitTransport([]), 'not-a-digest');
    fail('malformed caller confirmation digest was accepted');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(), 'exact 64-hex'), 'malformed caller digest refuses before target contact');
}

$failed = new InitTransport([['exit' => 17, 'stdout' => '', 'stderr' => 'adapter unsupported']]);
try {
    Init::proposal($failed);
    fail('target failure was accepted');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(), 'adapter unsupported'), 'target refusal remains visible to the operator');
}

$initFacadeSource = file_get_contents(__DIR__ . '/../../agent/src/Init/Init.php');
check(is_string($initFacadeSource), 'target init source is readable');
$plannerSource = file_get_contents(__DIR__ . '/../../agent/src/Init/InitPlanner.php');
check(is_string($plannerSource), 'target init planner source is readable');
$confirmationSource = file_get_contents(__DIR__ . '/../../agent/src/Init/InitConfirmation.php');
check(is_string($confirmationSource), 'target init confirmation source is readable');
$codeBaselineSource = file_get_contents(__DIR__ . '/../../agent/src/Init/InitCodeBaseline.php');
check(is_string($codeBaselineSource), 'target init code-baseline source is readable');
$initExceptionSource = file_get_contents(__DIR__ . '/../../agent/src/Init/InitExceptions.php');
check(is_string($initExceptionSource), 'target init exception source is readable');
$siteProbeSource = file_get_contents(__DIR__ . '/../../agent/src/Init/InitSiteProbe.php');
check(is_string($siteProbeSource), 'target init site-probe source is readable');
$codeInventorySource = file_get_contents(__DIR__ . '/../../agent/src/Init/InitCodeInventory.php');
check(is_string($codeInventorySource), 'target init code-inventory source is readable');
$ownedArtifactsSource = file_get_contents(__DIR__ . '/../../agent/src/Init/InitOwnedArtifacts.php');
check(is_string($ownedArtifactsSource), 'target init owned-artifact source is readable');
$faultSource = file_get_contents(__DIR__ . '/../../agent/src/Init/InitFaults.php');
check(is_string($faultSource), 'target init fault-checkpoint source is readable');
$repositorySource = file_get_contents(__DIR__ . '/../../agent/src/Init/InitRepositoryBoundary.php');
check(is_string($repositorySource), 'target init repository-boundary source is readable');
$attemptJournalSource = file_get_contents(__DIR__ . '/../../agent/src/Init/InitAttemptJournal.php');
check(is_string($attemptJournalSource), 'target init attempt-journal source is readable');
$recoverySource = file_get_contents(__DIR__ . '/../../agent/src/Init/InitRecovery.php');
check(is_string($recoverySource), 'target init recovery source is readable');
$protocolSource = file_get_contents(__DIR__ . '/../../agent/src/Init/InitProtocol.php');
check(is_string($protocolSource), 'target init protocol source is readable');

require_once __DIR__ . '/../../agent/src/Init/Init.php';
$agentInit = new ReflectionClass(\Duo\Init::class);
$proposalMethod = $agentInit->getMethod('proposal');
$confirmMethod = $agentInit->getMethod('confirm');
$proposalParameters = $proposalMethod->getParameters();
$confirmParameters = $confirmMethod->getParameters();
$publicAgentInitMethods = array_map(
    static fn(ReflectionMethod $method): string => $method->getName(),
    $agentInit->getMethods(ReflectionMethod::IS_PUBLIC)
);
sort($publicAgentInitMethods, SORT_STRING);
check(
    $agentInit->getFileName() === realpath(__DIR__ . '/../../agent/src/Init/Init.php')
        && $publicAgentInitMethods === ['confirm', 'proposal']
        && $proposalMethod->isPublic() && $proposalMethod->isStatic()
        && count($proposalParameters) === 2
        && $proposalParameters[0]->getName() === 'repo'
        && (string) $proposalParameters[0]->getType() === 'string'
        && $proposalParameters[1]->getName() === 'allowUnmanagedPlugins'
        && (string) $proposalParameters[1]->getType() === 'bool'
        && $proposalParameters[1]->isDefaultValueAvailable()
        && $proposalParameters[1]->getDefaultValue() === false
        && (string) $proposalMethod->getReturnType() === 'array'
        && $confirmMethod->isPublic() && $confirmMethod->isStatic()
        && count($confirmParameters) === 3
        && $confirmParameters[0]->getName() === 'repo'
        && (string) $confirmParameters[0]->getType() === 'string'
        && $confirmParameters[1]->getName() === 'expectedDigest'
        && (string) $confirmParameters[1]->getType() === 'string'
        && $confirmParameters[2]->getName() === 'allowUnmanagedPlugins'
        && (string) $confirmParameters[2]->getType() === 'bool'
        && $confirmParameters[2]->getDefaultValue() === false
        && (string) $proposalMethod->getReturnType() === 'array'
        && str_contains($initFacadeSource, 'return InitPlanner::proposal($repo, $allowUnmanagedPlugins);')
        && str_contains(
            $initFacadeSource,
            'return InitConfirmation::run($repo, $expectedDigest, $allowUnmanagedPlugins);'
        ),
    'target Init facade preserves its exact public API — now with the reviewed unmanaged-plugin decision, '
    . 'defaulted off — and delegates both operations to their owning collaborators'
);

require_once __DIR__ . '/../../agent/src/Init/InitAttemptJournal.php';
check(
    \Duo\Init::FORMAT === 'duo-init-plan/v1'
        && \Duo\InitPlanner::FORMAT === \Duo\Init::FORMAT
        && \Duo\InitRecovery::PLAN_FORMAT === \Duo\Init::FORMAT
        && \Duo\InitProtocol::PLAN_FORMAT === \Duo\Init::FORMAT
        && \Duo\InitProtocol::ATTEMPT_FORMAT === 'duo-init-attempt/v1'
        && \Duo\InitAttemptJournal::FILE === '.duo-init-attempt'
        && \Duo\InitAttemptJournal::NEXT_FILE === '.duo-init-attempt.next'
        && \Duo\InitAttemptJournal::FILE === \Duo\InitProtocol::ATTEMPT_FILE
        && \Duo\InitAttemptJournal::NEXT_FILE === \Duo\InitProtocol::ATTEMPT_NEXT_FILE,
    'init protocol aliases preserve the exact public formats and fixed journal filenames'
);
$attemptEnvelope = [
    'format' => 'duo-init-attempt/v1',
    'owned' => [],
    'phase' => 'preparing',
    'proposal' => ['digest' => str_repeat('d', 64)],
    'repository' => '/srv/shop-state',
    'repository_identity' => 'sha256:' . str_repeat('e', 64),
];
$preparingAttempt = \Duo\InitAttemptRecord::fromArray($attemptEnvelope, 'typed fixture');
$lockedAttempt = \Duo\InitAttemptRecord::fromArray(
    array_replace($attemptEnvelope, ['phase' => 'locked']),
    'typed fixture next'
);
$preparingAttempt->assertForwardTo($lockedAttempt);
check($lockedAttempt->toArray()['phase'] === 'locked', 'typed init journal accepts a forward phase transition');
try {
    $lockedAttempt->assertForwardTo($preparingAttempt);
    fail('typed init journal accepted a backward phase transition');
} catch (RuntimeException $expected) {
    check(
        str_contains($expected->getMessage(), 'not a forward transition'),
        'typed init journal rejects a backward phase transition'
    );
}

$initCompensationSource = $confirmationSource;
check(str_contains($plannerSource, "'code' => 'repository_external_writer_exclusion'"), 'target proposal binds the generic repository writer-exclusion advisory');
check(
    !str_contains(
        strtolower(implode("\n", [
            $initFacadeSource, $plannerSource, $confirmationSource, $codeBaselineSource,
            $codeInventorySource, $siteProbeSource, $recoverySource, $repositorySource,
            $ownedArtifactsSource, $attemptJournalSource, $protocolSource, $faultSource,
            $initExceptionSource,
        ])),
        'woocommerce'
    ),
    'generic target init has no plugin-name branch'
);
check(str_contains($plannerSource, "(\$rule['class'] ?? null) === 'authored'"), 'post-type scope expands only from authored manifest rulings');
check(substr_count($plannerSource, "(\$rule['class'] ?? null) === 'authored'") >= 2, 'post-type and taxonomy scope expand only from explicit authored manifest rulings');
$lockedRecheck = strrpos($confirmationSource, 'InitPlanner::assert_confirmed_proposal($proposal, $expectedDigest);');
$siteWrite = $lockedRecheck === false ? false : strpos($confirmationSource, '$sitePublication = InitOwnedArtifacts::publish_owned_file(', $lockedRecheck);
check($lockedRecheck !== false && $siteWrite !== false && $lockedRecheck < $siteWrite, 'under-lock digest recheck precedes the site-config write');
check(str_contains($plannerSource, "'code' => ['format' => 1, 'layout' => 'wp-content', 'source' => Code::SOURCE]"), 'site config declares code independently from state policy');
check(str_contains($codeBaselineSource, 'Code::descriptor_from_source($stage)'), 'captured code is validated by the existing descriptor contract before publication');
check(
    str_contains($confirmationSource, 'Capture::run_initial_baseline(')
        && str_contains($confirmationSource, '(string) $stateIdentity')
        && str_contains($confirmationSource, '(string) $mediaIdentity'),
    'confirmed baseline uses the init-wide strict publication transaction'
);
check(str_contains($confirmationSource, 'SELECT GET_LOCK(%s, 0)'), 'concurrent confirmations share a target advisory lease');
check(
    str_contains($plannerSource, "'existing_state_payload'")
        && str_contains($plannerSource, "'existing_media_payload'")
        && str_contains($plannerSource, "'existing_capture_receipt'")
        && str_contains($plannerSource, "'existing_duo_ledger'"),
    'stale state, media, capture-receipt, and ledger ownership block initialization'
);
check(str_contains($codeInventorySource, 'Secrets::hard_match($window)'), 'every code byte crosses the high-confidence secret matcher');
check(str_contains($codeInventorySource, 'substr($window, -32768)'), 'streaming secret scan retains one full bounded-pattern chunk');
check(str_contains($siteProbeSource, "['allowed_classes' => false]"), 'risk discovery cannot instantiate serialized user-meta objects');
check(!str_contains($siteProbeSource, 'maybe_unserialize('), 'read-only risk discovery never uses class-enabled WordPress unserialization');
check(
    str_contains($siteProbeSource, 'ORDER BY $idColumn ASC LIMIT $fetchLimit')
        && str_contains($siteProbeSource, "'option_id'") && str_contains($siteProbeSource, "'umeta_id'"),
    'bounded risk discovery uses deterministic primary-key keyset ordering'
);
check(str_contains($repositorySource, "git', 'init', '--initial-branch=main"), 'confirmation creates a verified Git worktree when absent');
check(str_contains($confirmationSource, "\$finalGit['mode'] !== 'existing-worktree'"), 'success re-verifies Git readiness after the baseline transaction');
$gitAttempt = strpos($confirmationSource, '$gitCreated = true;');
$gitInitialize = strpos($confirmationSource, 'InitRepositoryBoundary::initialize_git($repo);');
check(
    $gitAttempt !== false && $gitInitialize !== false && $gitAttempt < $gitInitialize
        && str_contains($confirmationSource, "file_exists(\$repo . '/.git') || is_link(\$repo . '/.git')"),
    'partial first Git initialization is marked before invocation and fully compensated'
);
$rootLinkCheck = strpos($repositorySource, 'if (is_link($repo))');
$rootAbsentCheck = strpos($repositorySource, 'if (!file_exists($repo))');
check(
    $rootLinkCheck !== false && $rootAbsentCheck !== false && $rootLinkCheck < $rootAbsentCheck,
    'dangling repository-root links refuse before the absent-root path'
);
check(
    str_contains($plannerSource, 'InitRepositoryBoundary::root_blocker($logicalRepo)')
        && str_contains($plannerSource, "return self::proposal_bound(\n                '.',\n                \$logicalRepo,\n                \$binding['identity'],")
        && str_contains($confirmationSource, "\$repo = '.';")
        && str_contains($repositorySource, 'self::freshLstat($repo)'),
    'proposal and confirmation bind a freshly inspected ordinary repository inode before child traversal'
);
$reviewedIdentity = strpos($confirmationSource, "\$reviewedIdentity = \$proposal['state']['repository_identity'] ?? null;");
$publicationLock = strpos($confirmationSource, '$publicationLock = Publish::lock_new($stateDir);');
check(
    $reviewedIdentity !== false && $publicationLock !== false && $reviewedIdentity < $publicationLock
        && str_contains($confirmationSource, "hash_equals(\$reviewedIdentity, \$binding['identity'])"),
    'a replacement ordinary directory refuses before the first publication-lock write'
);
check(
    str_contains($repositorySource, "'repository_root_missing'")
        && str_contains($repositorySource, 'the repository root and every parent must already exist'),
    'missing repository roots are an explicit bootstrap prerequisite rather than a racy init mutation'
);
check(
    str_contains($plannerSource, "'unsafe_site_config'")
        && str_contains($confirmationSource, 'InitOwnedArtifacts::publish_owned_file(')
        && str_contains($ownedArtifactsSource, 'self::regular_file_identity($path, $label)')
        && str_contains($ownedArtifactsSource, 'if (!@rename($tmp, $path))'),
    'site config publication verifies reviewed bytes before its crash-atomic same-parent replacement'
);
check(
    str_contains($plannerSource, "'unsafe_code_root'")
        && str_contains($confirmationSource, "assert_absent_owned_path(\$codeRoot, 'code publication root')")
        && str_contains($confirmationSource, "mkdir(\$codeRoot, 0700)")
        && str_contains($confirmationSource, "rename(\$stagedCode, \$codeRoot . '/wp-content')"),
    'code baseline reserves an owned root before publishing its verified child'
);
$publishSource = (string) file_get_contents(__DIR__ . '/../../agent/src/Publication/PublicationJournal.php');
$captureSource = (string) file_get_contents(__DIR__ . '/../../agent/src/Capture/CapturePublicationWorkflow.php')
    . (string) file_get_contents(__DIR__ . '/../../agent/src/Capture/InitialCaptureBoundary.php');
$liveHarness = (string) file_get_contents(__DIR__ . '/regress_duo_init.sh');
check(
    str_contains($publishSource, 'public static function lock_new(')
        && str_contains($publishSource, 'public static function assert_lock_path(')
        && str_contains($publishSource, 'public static function write_entities_fresh(')
        && str_contains($publishSource, 'public static function swap_initial(')
        && str_contains($publishSource, 'public static function cleanup_committed_initial('),
    'initial publication has fresh lock, strict staging, swap, and exact cleanup primitives'
);
check(
    substr_count($captureSource, "Publish::intent_path(\$stateDir) . '.previous'") >= 2
        && substr_count($captureSource, "Publish::receipt_path(\$stateDir) . '.previous'") >= 2
        && str_contains($publishSource, 'self::assert_record_slot_absent($previousPath, "$label previous-transition")'),
    'fresh and final first-publication gates include every fixed record-transition slot'
);
$repoFormat = (string) file_get_contents(__DIR__ . '/../../spec/repo-format.md');
check(
    str_contains($repoFormat, 'requires non-Duo tools to leave the')
        && str_contains($repoFormat, 'complete `state.capture*` protocol namespace untouched')
        && str_contains($repoFormat, 'adversarial namespace-race sandbox for these siblings'),
    'ordinary capture states its protocol-namespace exclusion without overclaiming portable PHP race safety'
);
$attemptWrite = strpos($confirmationSource, 'InitAttemptJournal::write($repo, $attemptRecord, \'absent\')');
$firstPublicationLock = strpos($confirmationSource, '$publicationLock = Publish::lock_new($stateDir);');
check(
    str_contains($protocolSource, "public const ATTEMPT_FILE = '.duo-init-attempt';")
        && str_contains($protocolSource, "public const ATTEMPT_NEXT_FILE = '.duo-init-attempt.next';")
        && str_contains($attemptJournalSource, 'public const FILE = InitProtocol::ATTEMPT_FILE;')
        && str_contains($attemptJournalSource, 'public const NEXT_FILE = InitProtocol::ATTEMPT_NEXT_FILE;')
        && str_contains($repositorySource, 'private const ATTEMPT_FILE = InitProtocol::ATTEMPT_FILE;')
        && str_contains($repositorySource, 'private const ATTEMPT_NEXT_FILE = InitProtocol::ATTEMPT_NEXT_FILE;')
        && str_contains($captureSource, 'InitProtocol::ATTEMPT_FILE')
        && str_contains($captureSource, 'InitProtocol::ATTEMPT_NEXT_FILE')
        && $attemptWrite !== false
        && $firstPublicationLock !== false
        && $attemptWrite < $firstPublicationLock,
    'sealed init recovery journal is durable before the first persistent capture lock mutation'
);
check(
    str_contains($recoverySource, "'verify-interrupted-precommit-init'")
        && str_contains($recoverySource, 'roll back only payloads carrying complete deletion authority')
        && str_contains($recoverySource, 'partial or ambiguous artifacts are retained')
        && str_contains($recoverySource, "'verify-interrupted-committed-init'")
        && str_contains($recoverySource, 'appears to have durable committed-state proof')
        && str_contains($recoverySource, 'only exact proof permits clearing the sealed journal'),
    'interrupted-init proposals promise verification, never cleanup before exact recovery authority is proven'
);
check(
    str_contains($recoverySource, 'public static function interrupted_attempt_manual_recovery_reason(')
        && str_contains($recoverySource, "'manual-interrupted-init-recovery'")
        && str_contains($recoverySource, "'interrupted_init_manual_recovery'")
        && str_contains($recoverySource, 'state.capture-intent.tmp.')
        && str_contains($recoverySource, "str_contains(\$entry, '.duo-claim-')")
        && str_contains($recoverySource, "str_contains(\$entry, '.duo-init-')")
        && str_contains($recoverySource, 'the interrupted-init repository contains an unjournaled Init temporary or claim artifact')
        && str_contains($repositorySource, "'.*.duo-init-*'")
        && str_contains($recoverySource, "\$proposal['ready'] = false;")
        && str_contains($liveHarness, 'unmanifested state recovery is non-confirmable')
        && str_contains($liveHarness, 'partial code-stage recovery is non-confirmable')
        && str_contains($liveHarness, 'partial state-stage recovery is non-confirmable'),
    'known partial init payloads produce a non-confirmable manual-recovery plan before mutation'
);
check(
    str_contains($publishSource, "self::fault_checkpoint('record-create-temp');")
        && str_contains($publishSource, 'public static function remove_owned_tree_initial(')
        && str_contains($publishSource, 'public static function remove_owned_file_initial(')
        && str_contains($publishSource, 'remove_owned_tree_initial($backup')
        && str_contains($publishSource, 'remove_owned_file_initial($intent'),
    'strict first-publication cleanup has a temp crash seam and no unjournaled claim rename'
);
check(
    str_contains($ownedArtifactsSource, "InitFaults::checkpoint('owned-file-temp');")
        && str_contains($ownedArtifactsSource, "InitFaults::checkpoint('owned-file-claim');")
        && str_contains($ownedArtifactsSource, "InitFaults::checkpoint('owned-tree-claim');"),
    'Init hidden temp and claim boundaries have explicit crash seams for live evidence'
);
check(
    str_contains($confirmationSource, "InitFaults::checkpoint('lock-created')")
        && str_contains($attemptJournalSource, "InitFaults::checkpoint('attempt-transition-pre-rename')")
        && str_contains($attemptJournalSource, "'attempt-transition-pre-rename-' . (string) \$attempt['phase']")
        && str_contains($confirmationSource, "InitFaults::checkpoint('capture-complete')")
        && str_contains($ownedArtifactsSource, "InitFaults::checkpoint('attempt-remove-pre-unlink')")
        && str_contains($ownedArtifactsSource, "InitFaults::checkpoint('attempt-remove-post-unlink')")
        && str_contains($faultSource, 'posix_kill(getmypid()')
        && str_contains($recoverySource, "return ['outcome' => 'precommit-rolled-back']")
        && str_contains($recoverySource, "return ['outcome' => 'committed-finalized']"),
    'init exposes fresh-process crash seams and distinct precommit/committed recovery outcomes'
);
check(
    str_contains($confirmationSource, "\$attemptRecord['owned']['code_stage_planned'] = true;")
        && str_contains($recoverySource, 'partial code staging tree without a complete descriptor'),
    'code-stage creation is write-ahead journaled before the staging-root mutation'
);
check(
    str_contains($initExceptionSource, 'final class InitAttemptRetentionException')
        && str_contains($confirmationSource, 'if ($error instanceof InitAttemptRetentionException)')
        && str_contains($codeBaselineSource, "DUO_TEST_INIT_FAIL_PHASE') === 'code-copy-after-file'")
        && str_contains($liveHarness, 'changed code source left a staging tree, journal, lock, or canonical payload')
        && str_contains($publishSource, "if (\$stillSame) @unlink(\$name);"),
    'post-create code-copy failures either compensate the exact partial stage or retain sealed recovery authority'
);
check(
    str_contains($confirmationSource, 'git-initialized-before-identity')
        && str_contains($recoverySource, 'incomplete Git metadata without a complete ownership manifest')
        && str_contains($liveHarness, 'Git initialization failure left an unjournaled or unlocked metadata root'),
    'planned-to-mutated Git failures retain their sealed journal when no complete ownership manifest exists'
);
check(
    str_contains($publishSource, 'lock-acquire-after-create')
        && str_contains($liveHarness, 'first-lock acquisition failure stranded a lock, journal, or repository payload'),
    'first-lock acquisition failure cannot erase its journal while leaving an unowned canonical lock'
);
check(
    str_contains($confirmationSource, 'state-reserved-before-identity')
        && str_contains($recoverySource, 'incomplete state reservation without a complete ownership manifest')
        && str_contains($liveHarness, 'state recovery refusal deleted the unmanifested sentinel'),
    'state reservation is not deletion authority until its complete identity is sealed'
);
check(
    str_contains($confirmationSource, "'capture-payload-ready'")
        && str_contains($confirmationSource, "\$attemptRecord['owned']['state_staging_manifest'] = \$stagingManifest;")
        && str_contains($confirmationSource, "\$attemptRecord['owned']['media_manifest'] = \$mediaManifest;")
        && str_contains($recoverySource, 'the interrupted-init state root no longer matches any sealed ownership manifest')
        && str_contains($recoverySource, 'Publish::tree_ownership_manifest($path)')
        && str_contains($recoverySource, 'no longer matches its sealed ownership manifest')
        && str_contains($recoverySource, 'partial state staging tree without a complete deletion manifest')
        && str_contains($recoverySource, 'partial code staging tree without a complete descriptor')
        && str_contains($liveHarness, 'DUO_TEST_PUBLISH_KILL_PHASE=initial-staging-partial')
        && str_contains($liveHarness, 'record-create-next intent-written after-state-rename')
        && str_contains($liveHarness, 'partial manifest-bound tree is non-confirmable')
        && str_contains($publishSource, 'recover_initial_unpublished_intent_next')
        && str_contains($publishSource, 'remove_matching_record_temps')
        && str_contains($publishSource, 'public static function recover_initial(')
        && str_contains($liveHarness, 'unmanifested-empty-directory'),
    'interrupted first publication deletes only journaled complete manifests and retains partial payloads'
);
check(
    str_contains($captureSource, 'post-swap-unmanifested-empty')
        && substr_count($captureSource, "Publish::assert_owned_tree(\n                        \$stateDir") >= 2
        && str_contains($liveHarness, 'post-swap recovery deleted the unmanifested directory or cleared its journal'),
    'initial success revalidates the exact published candidate before receipt cleanup and final result'
);
check(
    str_contains($publishSource, 'public static function intent_record(')
        && str_contains($publishSource, 'public static function receipt_record(')
        && str_contains($recoverySource, 'self::assert_interrupted_committed_attempt(')
        && str_contains($confirmationSource, "'recovery' => 'committed-finalized'"),
    'committed journal recovery verifies durable intent/receipt state before returning a truthful result'
);
check(
    substr_count($captureSource, 'InitialCaptureBoundary::assertNoInterruptedInit($repoPath);') >= 2
        && str_contains($captureSource, 'sealed init recovery journal exists')
        && str_contains($recoverySource, "hash_equals((string) (\$receipt['previous_sha256'] ?? ''), hash('sha256', ''))"),
    'ordinary capture cannot replace a retained initial receipt and committed recovery proves a first publication'
);
// DUO-3427: and it says so to a MACHINE caller. A retained init recovery
// journal is the operator's whole answer — what exists, and the one command
// that resolves it — in a fixed engine sentence carrying no path, selector, or
// value. As a bare RuntimeException it reached `--format=json` as "capture
// refused at an unclassified safety gate" with details_redacted, which sends
// an operator holding an interrupted init to private evidence for public
// guidance. Typed now, like the init side's proven rollback (DUO-3421), with
// the human sentence preserved verbatim as the operator message.
check(
    str_contains($captureSource, "'interrupted_init_recovery_pending',")
        && str_contains($captureSource, "'capture refused while a sealed init recovery journal exists',")
        && str_contains($captureSource, "'run duo init for the same environment to verify or roll back that interrupted attempt, then capture again',")
        && !preg_match(
            '/private static function assert_no_interrupted_init.{0,400}throw new \\\\RuntimeException/s',
            $captureSource
        ),
    'the retained init-recovery capture gate is a typed public refusal, not an unclassified redacted envelope'
);
// DUO-3427: and it must not answer a LIVE race. The pre-lock arm of that gate
// exists for one reason — acquiring the destination lock CREATES its file, and
// no ordinary capture may write into a repository holding an interrupted init.
// When the canonical lock already exists nothing can be created, so an
// unconditional early exit only pre-empted the truth: a live init holds that
// lock and has already written its journal, so a running race was answered
// with "run duo init to verify or roll back that interrupted attempt" for an
// init that was mid-publication and went on to succeed. Gated on the lock's
// ABSENCE the no-write guarantee is identical, and a live race falls through
// to Publish::lock(), whose typed refusal names the held destination lock —
// which is also the only point at which "interrupted" can be told apart from
// "in progress", because holding that lock is what proves nobody else is
// alive.
$preLockGate = strpos(
    $captureSource,
    "if (!\$initialBaseline && !file_exists(\$canonicalLock) && !is_link(\$canonicalLock)) {"
);
$lockAcquire = strpos($captureSource, '$lock = $publicationLock ?? Publish::lock($stateDir);');
$postLockGate = strpos($captureSource, 'InitialCaptureBoundary::assertNoInterruptedInit($repoPath);', (int) $lockAcquire);
check(
    $preLockGate !== false && $lockAcquire !== false && $postLockGate !== false
        && $preLockGate < $lockAcquire && $lockAcquire < $postLockGate
        && substr_count($captureSource, 'InitialCaptureBoundary::assertNoInterruptedInit($repoPath);') === 2,
    'the pre-lock init-recovery gate fires only where acquiring the lock would create it; a live race is answered by the lock itself'
);
$publishSourceLock = (string) file_get_contents(__DIR__ . '/../../agent/src/Publication/PublicationJournal.php');
check(
    str_contains($publishSourceLock, "'capture_lock_held',")
        && str_contains($publishSourceLock, "'capture refused because another publisher holds the destination lock',")
        && str_contains($liveHarness, "\$CAPTURE_OUT) >/dev/null 2>&1 \\") === false
        && str_contains($liveHarness, ".reason_code == \"capture_lock_held\"")
        && !str_contains($liveHarness, "grep -q 'another capture is already publishing'"),
    'the live concurrency case asserts the machine refusal contract, not the operator-message wording'
);
check(
    str_contains($ownedArtifactsSource, 'public static function remove_exact_owned_file(')
        && str_contains($ownedArtifactsSource, 'if (!@unlink($path))'),
    'completed journal removal uses an identity-checked atomic unlink instead of an unjournalled hidden claim'
);
check(
    str_contains($repositorySource, "'unreadable_repository_root'")
        && str_contains($repositorySource, '$entries = @scandir($repo);')
        && str_contains($repositorySource, 'if ($entries === false)'),
    'repository ownership fails closed when the root cannot be enumerated'
);
check(
    str_contains($repositorySource, "'state.capture.lock', 'state.capture-receipt'")
        && str_contains($repositorySource, "'state.capture-intent', 'state.capture-receipt'"),
    'final Git readiness allowlists the retained capture receipt and ignores no in-flight publication root'
);
$cliSource = (string) file_get_contents(__DIR__ . '/../../agent/src/Command/Cli.php');
check(
    str_contains($cliSource, "CommandRefusalException::invalidArgument('init', '--repo')")
        && str_contains($cliSource, "self::halt_json_failure(\$t, \$assoc, 'init');"),
    'target init owns the stable JSON invalid-argument and exception-refusal contract'
);
$adapterSource = (string) file_get_contents(__DIR__ . '/../../agent/src/Adapter/AdapterSources.php');
check(str_contains($adapterSource, 'file_exists($siteDir) || is_link($siteDir)'), 'adapter allowlist refuses every present non-directory boundary');

$codeSource = file_get_contents(__DIR__ . '/../../agent/src/Code/Code.php');
check(is_string($codeSource), 'code lifecycle source is readable');
check(str_contains($codeSource, 'public static function complete_initial_baseline'), 'code lifecycle exposes a narrow initial-baseline primitive');
check(str_contains($codeSource, 'complete_initial_baseline_in_active_transaction'), 'initial lifecycle can join capture transaction without a nested commit');
check(str_contains($codeSource, 'lifecycle metadata') && str_contains($codeSource, 'already exists'), 'initial baseline refuses to overwrite existing lifecycle metadata');
check(str_contains($codeSource, 'self::verify_payload($descriptor)') && str_contains($codeSource, 'self::owned_extra_files($descriptor)'), 'initial baseline verifies live bytes and rejects unrecorded managed files');

$woo = json_decode((string) file_get_contents(__DIR__ . '/../../manifests/woocommerce.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (['product_cat', 'product_tag', 'product_shipping_class', 'product_type'] as $taxonomy) {
    check(($woo['taxonomies'][$taxonomy]['class'] ?? null) === 'authored', "Woo adapter owns authored init scope for $taxonomy");
}
check(($woo['taxonomies']['product_visibility']['class'] ?? null) === 'runtime', 'Woo adapter keeps mixed product visibility out of authored state');

$ignoreTemplate = (string) file_get_contents(__DIR__ . '/../site-repo.gitignore.template');
check(
    str_contains($liveHarness, 'attempt-transition-pre-rename-code-staging')
        && str_contains($liveHarness, 'partial code staging tree without a complete descriptor'),
    'live crash recovery retains a code-stage transition without manufacturing deletion authority'
);
check(
    str_contains($attemptJournalSource, 'self::assert_transition($record, $next);')
        && str_contains($attemptJournalSource, 'next-record exists without its canonical sealed attempt')
        && str_contains($liveHarness, 'orphan init next-record blocks proposal before writes')
        && str_contains($liveHarness, 'malformed next-record blocks recovery before cleanup'),
    'init validates orphan and canonical-plus-next journal shapes before any recovery mutation'
);
check(
    str_contains($liveHarness, 'DUO_TEST_INIT_KILL_PHASE=attempt-remove-pre-unlink')
        && str_contains($liveHarness, 'DUO_TEST_INIT_KILL_PHASE=attempt-remove-post-unlink')
        && str_contains($liveHarness, ".duo-init-compensate-*"),
    'live coverage proves both sides of the completed-journal unlink crash boundary'
);
check(
    str_contains($ignoreTemplate, '/.tmp*')
        && str_contains($ignoreTemplate, '/.duo-init-attempt')
        && str_contains($ignoreTemplate, '/.duo-init-attempt.next')
        && str_contains($ignoreTemplate, '/.duo-init-code-*')
        && str_contains($ignoreTemplate, '/.*.duo-init-*')
        && str_contains($ignoreTemplate, "/.duo/\n")
        && str_contains($ignoreTemplate, "/.duo-envs.json\n")
        && str_contains($ignoreTemplate, "/.duo-env-values.json\n")
        && str_contains($ignoreTemplate, 'state.capture-intent.previous')
        && str_contains($ignoreTemplate, 'state.capture-intent.next')
        && str_contains($ignoreTemplate, 'state.capture-receipt.previous')
        && str_contains($ignoreTemplate, 'state.capture-receipt.next'),
    'canonical site-repo ignore template protects the init journal and fixed capture transition slots'
);
$sourceBinding = strpos($liveHarness, 'export DUO_EXPECTED_SOURCE_SHA="$SOURCE_SHA"');
$pairUp = strpos($liveHarness, 'bash sandbox/bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" "${PAIR_UP_FLAGS[@]}"');
check(
    str_contains($liveHarness, "git rev-parse --verify 'HEAD^{commit}'")
        && str_contains($liveHarness, '[[ ! -d "$REPO_ROOT/.git" ]]')
        && str_contains($liveHarness, 'git status --porcelain --untracked-files=all')
        && str_contains($liveHarness, 'PAIR_UP_FLAGS=(--headless --artifacts)')
        && str_contains($liveHarness, 'PAIR_UP_FLAGS+=(--wordpress-offline)')
        && $sourceBinding !== false && $pairUp !== false && $sourceBinding < $pairUp,
    'live init evidence binds a clean standalone exact Git HEAD before the first pair mutation'
);
$pairValidation = strpos($liveHarness, '[[ ! "$PAIR" =~ ^[a-z][a-z0-9]*$ ]]');
$pathDerivation = strpos($liveHarness, 'HOST_REPO="$REPO_ROOT/sandbox/siterepo/${PAIR}1"');
check(
    $pairValidation !== false && $pathDerivation !== false && $pairValidation < $pathDerivation,
    'live harness validates the pair name before deriving any cleanup path'
);
check(
    str_contains($liveHarness, 'PORT1 % 2 != 0 || PORT2 != PORT1 + 1'),
    'live harness validates the owned even/adjacent port pair before cleanup is armed'
);
$destroyCall = strpos($liveHarness, 'if bash sandbox/bin/pair.sh destroy "$PAIR"');
$deadReadback = strpos($liveHarness, 'label=com.docker.compose.project=duo-${PAIR}');
$rootRemoval = strpos($liveHarness, 'rm -rf "$HOST_REPO"');
check(
    $destroyCall !== false && $deadReadback !== false && $rootRemoval !== false
        && $destroyCall < $deadReadback && $deadReadback < $rootRemoval,
    'live cleanup proves pair destruction and Docker absence before removing bind roots'
);
check(
    !str_contains($liveHarness, 'pair.sh destroy "$PAIR" >/dev/null 2>&1 || true'),
    'live cleanup never suppresses pair-destroy failure before root removal'
);
check(
    substr_count($liveHarness, '-e DUO_TEST_INIT_PUBLICATION_PAUSE_MS=5000') >= 2,
    'both concurrent init contenders pause whichever winner holds the publication lock'
);
check(
    str_contains($liveHarness, 'wait_for_init_lease /siterepo/swap-link')
        && str_contains($liveHarness, 'wait_for_init_lease /siterepo/swap-directory')
        && substr_count($liveHarness, '-e DUO_TEST_INIT_PAUSE_MS=10000') >= 2,
    'live root replacement races wait for the post-proposal init lease before swapping paths'
);
check(
    str_contains($liveHarness, 'symlinked repository ancestor blocks init')
        && str_contains($liveHarness, 'repository_root_missing')
        && str_contains($liveHarness, 'replacement ordinary directory received a repository write')
        && str_contains($liveHarness, 'post-proposal symlink replacement received a repository write'),
    'live root suite covers missing, ancestor-link, symlink-swap, and ordinary-directory replacement boundaries'
);

// DUO-3428: `completed_within_fifteen_minutes` is exported into the reference
// bundle as a CERTIFIED member of init_golden_assertions, and DUO-3336 states
// it per init. It was implemented as a whole-suite stopwatch over a harness
// that installs WooCommerce and drives ~20 injected-failure confirmations, so
// the certified number described the harness and could only fail once the
// suite went green end to end. Pinned as a shape, not a duration: the budget
// is applied per timed init, the whole-suite stopwatch is informational and
// carries no assertion, and the certified set cannot silently empty out.
$suiteStopwatch = (bool) preg_match('/^SUITE_STARTED_AT=\$SECONDS$/m', $liveHarness);
check(
    $suiteStopwatch
        && str_contains($liveHarness, 'time_golden_init() {')
        && str_contains($liveHarness, 'INIT_BUDGET_SECONDS=900')
        && str_contains($liveHarness, 'over the per-init fifteen-minute budget')
        && str_contains($liveHarness, 'time_golden_init 0 "duo init Woo golden path"')
        && !preg_match('/^STARTED_AT=\$SECONDS$/m', $liveHarness)
        && !str_contains($liveHarness, 'golden path exceeded 15 minutes'),
    'the certified fifteen-minute clock budgets each golden-path init on its own proposal-to-confirmation wall, not the whole suite'
);
check(
    str_contains($liveHarness, 'SUITE_ELAPSED=$((SECONDS - SUITE_STARTED_AT))')
        && str_contains($liveHarness, 'informational: whole suite took %ss')
        && !preg_match('/\[ "\$SUITE_ELAPSED" -\w+ /', $liveHarness),
    'the whole-suite wall is reported as informational operational data and no assertion rests on it'
);
check(
    str_contains($liveHarness, 'INIT_TIMED_CASES_EXPECTED=1')
        && str_contains($liveHarness, '[ "${#INIT_TIMINGS[@]}" -eq "$INIT_TIMED_CASES_EXPECTED" ]')
        && str_contains($liveHarness, 'golden-path init(s), not the $INIT_TIMED_CASES_EXPECTED it certifies'),
    'the per-init clock refuses a certified set that timed nothing, so the claim cannot go vacuous'
);

// DUO-3421. This leg and the live golden path (bundle legs 13-14) both run on
// bundle-owing branches BY CONSTRUCTION, where the checked-in attestation is
// expired and every certified claim therefore carries evidence_not_current. An
// init proposal with unsupported rows is not ready, refuses confirmation
// instantly, and the live harness's paused root-replacement races then time out
// waiting for an init lease no confirmation ever took — the bundle blocked on
// the evidence it exists to mint. The live harness now mounts a hermetic
// library (the shipped manifests byte for byte, with independent subject
// records re-sealed against the working tree) instead of the live one. Pinned
// here because the ordering
// is the whole property: sealed and asserted BEFORE the pair exists, and the
// live library never mounted at all.
$fixtureBuild = strpos($liveHarness, 'php sandbox/tests/certification_fixture.php "$HERMETIC_ROOT"');
$fixtureMount = strpos($liveHarness, 'export DUO_MANIFESTS_SRC="$HERMETIC_MANIFESTS"');
check(
    $fixtureBuild !== false && $fixtureMount !== false && $pairUp !== false
        && $fixtureBuild < $fixtureMount && $fixtureMount < $pairUp
        && !str_contains($liveHarness, 'export DUO_MANIFESTS_SRC="$REPO_ROOT/manifests"'),
    'live init evidence seals and mounts a hermetic certified library before pair bring-up, never the live one'
);
check(
    str_contains($liveHarness, 'fixture manufacture failed: the sealed registry does not read current evidence')
        && str_contains($liveHarness, 'fixture manufacture failed: the sealed library is not the shipped library outside capabilities/')
        && str_contains($liveHarness, 'fixture manufacture failed: sealing the fixture modified the shipped manifest library'),
    'live harness asserts its own fixture manufacture — current, byte-identical, and non-destructive — before any behavior'
);
// The fixture builder itself, exercised offline: if it cannot produce a current
// attestation on this tree, legs 13-14 cannot pass and this says so in seconds
// rather than an hour into a live pair.
require_once __DIR__ . '/certification_fixture.php';
$fixtureRoot = sys_get_temp_dir() . '/duo-init-contract-fixture-' . bin2hex(random_bytes(6));
register_shutdown_function(static function () use ($fixtureRoot): void {
    exec('rm -rf ' . escapeshellarg($fixtureRoot));
});
$sealedDir = duo_cert_seal_library(dirname(__DIR__, 2), $fixtureRoot);
$sealedRegistry = \Duo\Canon::decode(\Duo\Canon::read_file("$sealedDir/capabilities/registry.json"));
$sealedStatuses = [];
foreach (['manifests', 'profiles'] as $sealedSection) {
    foreach ($sealedRegistry[$sealedSection] as $sealedClaim) {
        if (($sealedClaim['status'] ?? null) === 'certified') {
            $sealedStatuses[(string) ($sealedClaim['evidence']['status'] ?? '?')] = true;
        }
    }
}
check(
    array_keys($sealedStatuses) === ['current']
        && duo_cert_library_bytes($sealedDir) === duo_cert_library_bytes(dirname(__DIR__, 2) . '/manifests'),
    'the shared certification fixture seals every certified subject independently over byte-identical shipped manifests'
);

// DUO-3421. The confirmation logs are the only place a paused confirmation's
// own answer is written, so a failed run must keep them; a green one still
// cleans up after itself, and the owned pair is destroyed either way.
check(
    str_contains($liveHarness, 'trap cleanup_on_exit EXIT')
        && str_contains($liveHarness, 'cleanup 1')
        && str_contains($liveHarness, 'preserved init evidence for %s (this run failed; nothing below was deleted)')
        && str_contains($liveHarness, 'if [ "$preserve" != 1 ]; then')
        && str_contains($liveHarness, 'rm -f "${INIT_LOGS[@]}"'),
    'live cleanup preserves and names the failed confirmation logs, and deletes them only on success'
);
check(
    str_contains($liveHarness, 'confirmation never acquired the init lease for $repo; its own answer, from $log:')
        && str_contains($liveHarness, '$(cat -- "$log" 2>&1)')
        && str_contains($liveHarness, 'wait_for_init_lease /siterepo/swap-link "/tmp/${PAIR}-init-root-symlink.log"')
        && str_contains($liveHarness, 'wait_for_init_lease /siterepo/swap-directory "/tmp/${PAIR}-init-root-directory.log"'),
    'a lease-wait timeout pastes the confirmation log that already holds the diagnosis'
);
// DUO-3421 (DUO-3381 family). Every injected-failure case takes a fresh
// proposal and confirms its digest; a compose run starved to empty with exit 0
// yields an empty digest, a confirmation that refuses before its first
// mutation, and a case that blames the ENGINE for losing the journal that was
// never created. The manufacture is asserted before any of them consume it,
// and nothing may reach a confirmation through the old unchecked shape.
check(
    str_contains($liveHarness, 'assert_init_plan() {')
        && str_contains($liveHarness, 'fixture manufacture failed: $label proposal is not ready')
        && str_contains($liveHarness, '[[ "$digest" =~ ^[a-f0-9]{64}$ ]]')
        && substr_count($liveHarness, 'assert_init_plan wp') >= 20
        && !preg_match('/_PLAN=\$\(wp[12] duo init/', $liveHarness)
        && !str_contains($liveHarness, '_DIGEST=$(jq -r .digest <<<'),
    'every confirmed live proposal asserts its own manufacture before the confirmation consumes the digest'
);

$unsafeRoot = __DIR__ . '/../unsafe1';
$unsafeSentinel = $unsafeRoot . '/sentinel';
if (!is_dir($unsafeRoot) && !mkdir($unsafeRoot, 0777, true) && !is_dir($unsafeRoot)) {
    fail('could not create invalid-pair cleanup sentinel');
}
file_put_contents($unsafeSentinel, "preserve\n");
register_shutdown_function(static function () use ($unsafeSentinel, $unsafeRoot): void {
    if (is_file($unsafeSentinel)) unlink($unsafeSentinel);
    if (is_dir($unsafeRoot)) rmdir($unsafeRoot);
});
$invalidOutput = [];
$invalidExit = 0;
exec(
    'DUO_INIT_PAIR=' . escapeshellarg('../unsafe') . ' bash '
        . escapeshellarg(__DIR__ . '/regress_duo_init.sh') . ' 2>&1',
    $invalidOutput,
    $invalidExit
);
check($invalidExit === 2, 'invalid live pair name refuses before Docker or cleanup');
check(str_contains(implode("\n", $invalidOutput), 'invalid DUO_INIT_PAIR'), 'invalid-pair regression reaches the pair guard');
check(is_file($unsafeSentinel), 'invalid live pair name cannot escape siterepo and delete the sentinel');

// Exercise the target-only bounded risk probe without WordPress. Query
// failures must not become clean zero counts, and oversized omissions must be
// explicit even when the bounded row queries themselves return no rows.
require_once __DIR__ . '/../../agent/src/Kernel/Secrets.php';
require_once __DIR__ . '/../../agent/src/Kernel/PersonalData.php';
require_once __DIR__ . '/../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../agent/src/Adapter/AdapterSources.php';
require_once __DIR__ . '/../../agent/src/Init/InitSiteProbe.php';
require_once __DIR__ . '/../../agent/src/Init/Init.php';
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

// The proposal-time manual-recovery gate and the recovery-time deletion
// authority must answer the SAME question about a Git root. A populated
// git-ready identity is deletion authority only while it still describes the
// complete current tree: strict cleanup can restore the root after deleting a
// child, which leaves a partial canonical tree behind. Exercised against the
// real private predicate with real directory identities, offline: no docker,
// no WordPress.
// The predicate resolves the fixed capture-record slots through Publish.
require_once __DIR__ . '/../../agent/src/Publication/Publish.php';
$ignoreFixture = sys_get_temp_dir() . '/duo-init-ignore-' . bin2hex(random_bytes(6));
if (!mkdir($ignoreFixture, 0777, true)) fail('could not create the init ignore fixture');
register_shutdown_function(static function () use ($ignoreFixture): void {
    if (is_file($ignoreFixture . '/.gitignore')) unlink($ignoreFixture . '/.gitignore');
    if (is_dir($ignoreFixture)) rmdir($ignoreFixture);
});
$ignorePublication = \Duo\InitRepositoryBoundary::ensure_gitignore($ignoreFixture, 'absent');
$generatedIgnore = (string) file_get_contents($ignoreFixture . '/.gitignore');
check(is_array($ignorePublication)
    && str_contains($generatedIgnore, "/.tmp*\n")
    && str_contains($generatedIgnore, "/.duo/\n")
    && str_contains($generatedIgnore, "/.duo-envs.json\n")
    && str_contains($generatedIgnore, "/.duo-env-values.json\n")
    && str_contains($generatedIgnore, "/.duo-init-code-*\n")
    && str_contains($generatedIgnore, "/state.capture.lock\n"),
    'first init writes every target-local authority and environment overlay ignore rule');
$legacyIgnoreFixture = sys_get_temp_dir() . '/duo-init-legacy-ignore-' . bin2hex(random_bytes(6));
if (!mkdir($legacyIgnoreFixture, 0777, true)) fail('could not create the legacy ignore fixture');
register_shutdown_function(static function () use ($legacyIgnoreFixture): void {
    if (is_file($legacyIgnoreFixture . '/.gitignore')) unlink($legacyIgnoreFixture . '/.gitignore');
    if (is_dir($legacyIgnoreFixture)) rmdir($legacyIgnoreFixture);
});
$legacyRules = [
    '.tmp*', '.duo/', '.duo-envs.json', '.duo-init-code-*', '.*.duo-init-*',
    '.duo-init-attempt', '.duo-init-attempt.next', 'state.capture.lock',
    'state.capture-staging/', 'state.capture-backup/', 'state.capture-intent',
    'state.capture-receipt', 'state.capture-intent.tmp.*', 'state.capture-receipt.tmp.*',
    'state.capture-intent.previous', 'state.capture-intent.next',
    'state.capture-receipt.previous', 'state.capture-receipt.next', '.duo-env-values.json',
];
$legacyIgnore = "vendor/\n" . implode("\n", $legacyRules) . "\n";
file_put_contents($legacyIgnoreFixture . '/.gitignore', $legacyIgnore);
$legacyIdentity = \Duo\InitOwnedArtifacts::regular_file_identity(
    $legacyIgnoreFixture . '/.gitignore',
    '.gitignore'
);
\Duo\InitRepositoryBoundary::ensure_gitignore($legacyIgnoreFixture, $legacyIdentity);
$migratedIgnore = (string) file_get_contents($legacyIgnoreFixture . '/.gitignore');
$migratedLines = preg_split('/\r?\n/', $migratedIgnore);
check(str_contains($migratedIgnore, "vendor/\n"), 'init ignore migration preserves unrelated rules');
foreach ($legacyRules as $legacyRule) {
    check(
        is_array($migratedLines)
            && in_array('/' . $legacyRule, $migratedLines, true)
            && !in_array($legacyRule, $migratedLines, true),
        "init root-anchors the prior broad Duo rule $legacyRule"
    );
}
$recoveryReason = static fn(string $repo, array $attempt): ?string =>
    \Duo\InitRecovery::interrupted_attempt_manual_recovery_reason($repo, $attempt);
$directoryIdentity = static fn(string $path, string $label): string =>
    \Duo\InitOwnedArtifacts::directory_identity($path, $label);
$removeOwnedTree = static function (string $path, string $identity, string $label): void {
    \Duo\InitOwnedArtifacts::remove_owned_tree($path, $identity, $label);
};
$treeCleanupFixture = sys_get_temp_dir() . '/duo-init-tree-cleanup-' . bin2hex(random_bytes(6));
if (!mkdir($treeCleanupFixture, 0777, true)) fail('could not create the exact-owned tree cleanup fixture');
register_shutdown_function(static function () use ($treeCleanupFixture): void {
    exec('rm -rf ' . escapeshellarg($treeCleanupFixture));
});
// Filesystem permissions cannot make unlink/rmdir fail under a root test
// runner. Exercise the real private claim-and-remove boundary through the
// test-only operation seam instead, then prove that the exact root returns to
// its canonical name rather than being hidden behind a cleanup claim.
foreach ([
    ['name' => 'unlink', 'phase' => 'unlink', 'child' => 'file', 'description' => 'unlink'],
    ['name' => 'child-rmdir', 'phase' => 'rmdir', 'child' => 'directory', 'description' => 'child rmdir'],
    // This root is deliberately empty: CHILD_FIRST has no child rmdir to
    // intercept, so the seam proves the claimed root rmdir itself is checked.
    ['name' => 'root-rmdir', 'phase' => 'rmdir', 'child' => null, 'description' => 'root rmdir'],
] as $treeCleanupCase) {
    $name = (string) $treeCleanupCase['name'];
    $phase = (string) $treeCleanupCase['phase'];
    $description = (string) $treeCleanupCase['description'];
    $ownedTree = $treeCleanupFixture . '/' . $name;
    if (!mkdir($ownedTree, 0777, true)) fail("could not create the $description cleanup root");
    if ($treeCleanupCase['child'] === 'directory') {
        if (!mkdir($ownedTree . '/child', 0777, true)) fail("could not create the $description cleanup child");
    } elseif ($treeCleanupCase['child'] === 'file') {
        file_put_contents($ownedTree . '/child', 'payload');
    }
    $ownedIdentity = $directoryIdentity($ownedTree, "exact-owned $description cleanup root");
    $cleanupFailure = null;
    putenv('DUO_TEST_MODE=1');
    putenv('DUO_TEST_INIT_FAIL_PHASE=owned-tree-remove-' . $phase);
    try {
        $removeOwnedTree($ownedTree, $ownedIdentity, "exact-owned $description cleanup root");
    } catch (ReflectionException $unexpected) {
        throw $unexpected;
    } catch (Throwable $failure) {
        $cleanupFailure = $failure;
    } finally {
        putenv('DUO_TEST_INIT_FAIL_PHASE');
        putenv('DUO_TEST_MODE');
    }
    check(
        $cleanupFailure instanceof RuntimeException
            && str_contains($cleanupFailure->getMessage(), "injected exact-owned tree $phase refusal"),
        "an exact-owned tree $description failure is surfaced instead of being reported as clean"
    );
    check(
        is_dir($ownedTree)
            && !is_link($ownedTree)
            && hash_equals(
                $ownedIdentity,
                $directoryIdentity($ownedTree, "exact-owned $description cleanup root")
            )
            && glob($treeCleanupFixture . '/.' . $name . '.duo-init-remove-*') === [],
        "a failed exact-owned tree $description cleanup restores its canonical authority without a hidden claim"
    );
}
$gitFixtureRepo = sys_get_temp_dir() . '/duo-init-git-authority-' . bin2hex(random_bytes(6));
if (!mkdir($gitFixtureRepo . '/.git', 0777, true)) fail('could not create the Git authority fixture');
register_shutdown_function(static function () use ($gitFixtureRepo): void {
    exec('rm -rf ' . escapeshellarg($gitFixtureRepo));
});
$emptyRootIdentity = $directoryIdentity($gitFixtureRepo . '/.git', 'Git metadata root');
$gitAttempt = static fn(array $owned): array => ['owned' => ['git_created' => true] + $owned];
check(
    $recoveryReason($gitFixtureRepo,
        $gitAttempt(['git_empty_identity' => $emptyRootIdentity])
    ) === null,
    'a Git root reserved and still untouched keeps complete deletion authority and stays automatically recoverable'
);
$incompleteGitReason = 'the sealed attempt has incomplete Git metadata without a complete ownership manifest';
check(
    $recoveryReason($gitFixtureRepo, $gitAttempt([])) === $incompleteGitReason,
    'a reserved Git root with no ownership manifest at all is non-confirmable'
);
// The exact `git-initialized-before-identity` window the live harness injects.
file_put_contents($gitFixtureRepo . '/.git/HEAD', "ref: refs/heads/main\n");
check(
    $recoveryReason($gitFixtureRepo,
        $gitAttempt(['git_empty_identity' => $emptyRootIdentity])
    ) === $incompleteGitReason,
    'a Git root written into after its empty-root manifest was sealed is non-confirmable — presence is not deletion authority'
);
$gitReadyConfig = $gitFixtureRepo . '/.git/config';
file_put_contents($gitReadyConfig, "[core]\nrepositoryformatversion = 0\n");
$gitReadyIdentity = $directoryIdentity(
    $gitFixtureRepo . '/.git',
    'Git metadata root'
);
$gitReadyAttempt = $gitAttempt([
    'git_empty_identity' => $emptyRootIdentity,
    'git_identity' => $gitReadyIdentity,
]);
check(
    $recoveryReason($gitFixtureRepo, $gitReadyAttempt) === null,
    'a completed git-ready manifest remains automatically recoverable'
);
unlink($gitReadyConfig);
check(
    $recoveryReason($gitFixtureRepo, $gitReadyAttempt) === $incompleteGitReason,
    'a partially deleted Git root restored to its canonical path is non-confirmable before recovery mutates it'
);
check(
    substr_count($recoverySource, 'self::git_deletion_identity_current($repo, $owned)') === 2
        && str_contains($recoverySource, 'private static function git_deletion_identity_current('),
    'the proposal gate and recovery resolve full and empty Git manifests through one shared exact-tree predicate'
);
// A full confirmation needs the WordPress lease, capture lock, and Git
// worktree, so this offline contract exercises its private filesystem boundary
// directly. The live post-Git marker below invokes confirm() through that
// catch; this ordering pin proves its failure branch sets retention before the
// journal and both lock teardown paths can run.
$precommitRetentionMessage = 'duo: init retained its sealed recovery journal and capture lock because pre-COMMIT compensation could not safely complete:';
$precommitRetentionMessageAt = strpos($confirmationSource, $precommitRetentionMessage);
$precommitRetainAt = $precommitRetentionMessageAt === false
    ? false
    : strrpos(substr($confirmationSource, 0, $precommitRetentionMessageAt), '$retainPublicationLock = true;');
$precommitThrowAt = $precommitRetainAt === false
    ? false
    : strpos($confirmationSource, 'throw new InitAttemptRetentionException(', $precommitRetainAt);
$attemptVerificationAt = $precommitRetentionMessageAt === false
    ? false
    : strpos($confirmationSource, 'if (is_array($attemptPublication) && is_resource($publicationLock))', $precommitRetentionMessageAt);
$catchLockTeardownAt = $precommitRetentionMessageAt === false
    ? false
    : strpos($confirmationSource, 'if (!$retainPublicationLock && $lockOwnedAndCreated', $precommitRetentionMessageAt);
$finallyLockTeardownAt = $precommitRetentionMessageAt === false
    ? false
    : strpos($confirmationSource, 'if (!$succeeded && !$retainPublicationLock && $lockOwnedAndCreated', $precommitRetentionMessageAt);
check(
    $precommitRetainAt !== false && $precommitThrowAt !== false
        && $attemptVerificationAt !== false && $catchLockTeardownAt !== false && $finallyLockTeardownAt !== false
        && $precommitRetainAt < $precommitThrowAt
        && $precommitThrowAt < $attemptVerificationAt
        && $precommitThrowAt < $catchLockTeardownAt
        && $precommitThrowAt < $finallyLockTeardownAt
        && str_contains($liveHarness, 'DUO_TEST_INIT_FAIL_AFTER_GIT_CREATE=1')
        && str_contains($liveHarness, 'post-Git-create failure left repository artifacts'),
    'a pre-COMMIT cleanup failure retains the sealed journal and capture lock before either teardown; the live post-Git marker covers the enclosing confirm catch'
);

// DUO-3427: the same asymmetry family, one authority over. Every ownership
// manifest a recovery consumes has made a round trip through the sealed init
// journal, and Canon::encode() ksorts object keys — so a journaled entry comes
// back {dev,ino,path,sha256,type} while tree_ownership_manifest() builds
// {type,dev,ino,sha256,path}. PHP's `===` on arrays is order-sensitive, so
// Publish::assert_owned_tree() reported "changed after Duo created it" for a
// tree nothing had touched, and it did so on EVERY fresh-process rollback:
// the strict first-publication recovery path could only refuse. Its two
// siblings — the proposal-time gate above and remove_owned_file_initial()'s
// file-level twin — already compared canonically, both since the same commit.
// Exercised against the real predicate with a real tree and a real journal
// round trip, offline.
require_once __DIR__ . '/../../agent/src/Publication/Publish.php';
$manifestFixture = sys_get_temp_dir() . '/duo-init-manifest-order-' . bin2hex(random_bytes(6));
if (!mkdir($manifestFixture . '/posts/page', 0777, true)) fail('could not create the manifest-order fixture');
register_shutdown_function(static function () use ($manifestFixture): void {
    exec('rm -rf ' . escapeshellarg($manifestFixture));
});
file_put_contents($manifestFixture . '/posts/page/hello.md', "hello\n");
$liveManifest = \Duo\Publish::tree_ownership_manifest($manifestFixture);
$sealedManifest = \Duo\Canon::decode(\Duo\Canon::encode($liveManifest));
check(
    $liveManifest !== $sealedManifest && \Duo\Canon::encode($liveManifest) === \Duo\Canon::encode($sealedManifest),
    'a journaled ownership manifest really does come back with reordered keys, so the comparison is the whole question'
);
$ownedTreeVerdict = static function (array $manifest) use ($manifestFixture): ?string {
    try {
        \Duo\Publish::assert_owned_tree($manifestFixture, $manifest, 'initial capture staging');
        return null;
    } catch (\Throwable $refusal) {
        return $refusal->getMessage();
    }
};
check(
    $ownedTreeVerdict($sealedManifest) === null,
    'a tree that still matches its SEALED manifest carries complete deletion authority'
);
$changedManifest = $sealedManifest;
$changedManifest['entries'][0]['ino'] = '999999999999';
check(
    $ownedTreeVerdict($changedManifest) === 'duo: initial capture staging changed after Duo created it; preserving it',
    'a re-inoded entry is still refused and preserved'
);
file_put_contents($manifestFixture . '/posts/page/unmanifested.md', "added\n");
check(
    $ownedTreeVerdict($sealedManifest) === 'duo: initial capture staging changed after Duo created it; preserving it',
    'an entry absent from the sealed manifest is still refused and preserved'
);

// DUO-3427: the same asymmetry a third time, on the capture-record temporaries.
// Publish::remove_matching_record_temps()'s docblock states the rule — resolve
// only a temp that is a hard link to its sealed next slot carrying that exact
// record, never sweep "by name pattern" — and the proposal gate swept by name
// pattern. write_record() creates the temp, hard links it to `.next`, and only
// then reaches the record-create-next fault boundary, so a crash there leaves
// the bound shape the authority is built to resolve; the gate sent it to
// manual archive-and-recreate instead, and the confirmation that would have
// rolled it back completely was never offered. Exercised against the real
// shared predicate with real inodes, offline.
$tempFixture = sys_get_temp_dir() . '/duo-init-record-temp-' . bin2hex(random_bytes(6));
if (!mkdir($tempFixture, 0777, true)) fail('could not create the record-temp fixture');
register_shutdown_function(static function () use ($tempFixture): void {
    exec('rm -rf ' . escapeshellarg($tempFixture));
});
$tempStateDir = $tempFixture . '/state';
$sealIntent = static function (string $candidate): string {
    $record = [
        'format' => 'duo-capture-intent/v1',
        'id' => bin2hex(random_bytes(16)),
        'phase' => 'prepared',
        'candidate_sha256' => $candidate,
        'previous_sha256' => hash('sha256', ''),
        'created_at' => gmdate('c'),
    ];
    $record['record_sha256'] = hash('sha256', \Duo\Canon::encode($record));
    return \Duo\Canon::encode($record);
};
// The literal fixed slot name, as the site-repo ignore template pins it.
$intentNext = \Duo\Publish::intent_path($tempStateDir) . '.next';
$boundTemp = \Duo\Publish::intent_path($tempStateDir) . '.tmp.4242.' . bin2hex(random_bytes(6));
file_put_contents($boundTemp, $sealIntent(str_repeat('a', 64)));
if (!link($boundTemp, $intentNext)) fail('could not hard link the record-temp fixture');
check(
    \Duo\Publish::record_temp_is_resolvable($tempStateDir, basename($boundTemp)) === true,
    'a record temp hard-linked to its sealed next slot is resolvable, exactly as the removal authority resolves it'
);
$strayTemp = \Duo\Publish::intent_path($tempStateDir) . '.tmp.4243.' . bin2hex(random_bytes(6));
file_put_contents($strayTemp, $sealIntent(str_repeat('b', 64)));
check(
    \Duo\Publish::record_temp_is_resolvable($tempStateDir, basename($strayTemp)) === false,
    'a record temp on its own inode is not resolvable and still means manual recovery'
);
unlink($intentNext);
check(
    \Duo\Publish::record_temp_is_resolvable($tempStateDir, basename($boundTemp)) === false,
    'a record temp with no sealed next slot at all is not resolvable — the record-create-temp crash window is unchanged'
);
check(
    substr_count($recoverySource, 'Publish::record_temp_is_resolvable($stateDir, $entry)') === 1
        && str_contains($recoverySource, "str_starts_with(\$entry, 'state.capture-intent.tmp.')"),
    'the proposal gate resolves record temporaries through the authority\'s own binding predicate, not a name sweep'
);

// DUO-3427: committed-init FINALIZATION re-proved the published site.duo.json
// by comparing its BYTES to a re-encoding of the journal's copy of the
// confirmed config — and those bytes can never agree. The file is written from
// the LIVE proposal, where an empty policy map is a JSON object; the journal
// stores the proposal as JSON and Canon::decode() reads it back with assoc
// arrays, so `{}` returns as `[]`. Every core-only site has at least one empty
// policy map, so the crash-after-COMMIT path this function exists for refused
// unconditionally. Byte-exactness now rides on the publication identity Duo
// recorded (content digest folded with dev/ino, a string the journal carries
// intact) and the proposal binding is structural, both sides normalized
// through one decode/encode. The collapse itself is demonstrated here, on the
// real encoder, with the real shape.
$liveInitConfig = [
    'code' => ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'],
    'manifests' => [['digest' => str_repeat('a', 64), 'name' => 'core']],
    'policy' => [
        'options' => new stdClass(),
        'post_meta' => new stdClass(),
        'post_types' => ['attachment', 'page', 'post'],
        'taxonomies' => ['category', 'post_tag'],
        'term_meta' => new stdClass(),
    ],
    'spec_version' => 2,
];
$committedBytes = \Duo\Canon::encode($liveInitConfig);
$journaledConfig = \Duo\Canon::decode(
    \Duo\Canon::encode(['proposal' => ['state' => ['config' => $liveInitConfig]]])
)['proposal']['state']['config'];
check(
    str_contains($committedBytes, '"options": {}')
        && \Duo\Canon::encode($journaledConfig) !== $committedBytes,
    'the sealed journal cannot round-trip an empty policy map, so re-encoding its config never reproduces the committed bytes'
);
check(
    \Duo\Canon::encode(\Duo\Canon::decode($committedBytes)) === \Duo\Canon::encode($journaledConfig),
    'normalizing both sides through one decode/encode makes the proposal binding answerable'
);
check(
    str_contains($recoverySource, 'Canon::encode(Canon::decode(Canon::read_file($siteFile))) !== Canon::encode($expectedConfig)')
        && str_contains($recoverySource, "\$sitePublication['published'],")
        && !str_contains($recoverySource, 'Canon::read_file($siteFile) !== Canon::encode($expectedConfig)'),
    'committed-init finalization proves site.duo.json byte-exactly through its journaled publication identity, and structurally against the confirmed proposal'
);
// DUO-3427: the second unconditional gate on the same path. The finalization
// compared the compiled payload's `code_revision` to the proposal's
// `source_revision` — a digest of the LIVE SOURCE inventory, verified against
// that source in capture_code(), computed over a different root from different
// inputs (the payload excludes Duo's own loader, which the live suite asserts
// by name). They are never equal, so this refused every committed
// finalization on arithmetic. The payload is now proved against the journaled
// publication identity of the code root, beside the completed_code_mismatch()
// check that binds the same payload to the committed ledger.
check(
    str_contains($recoverySource, "\$codeIdentity = ((array) (\$attempt['owned'] ?? []))['code_identity'] ?? null;")
        && str_contains($recoverySource, "hash_equals(\$codeIdentity, InitOwnedArtifacts::directory_identity(\$codeRoot, 'code publication root'))")
        && str_contains($recoverySource, 'Code::completed_code_mismatch($compiled)')
        && !str_contains($recoverySource, "\$expectedRevision = is_array(\$proposal) ? (\$proposal['code']['source_revision'] ?? null) : null;"),
    'committed-init finalization proves the code payload against its journaled publication identity, not against the live source digest'
);
// The source digest keeps its own, correct verification site: the confirmation
// still refuses when the target's code changed between proposal and capture.
check(
    str_contains($codeBaselineSource, "if (!hash_equals((string) (\$code['source_revision'] ?? ''), \$revision)) {")
        && str_contains($codeBaselineSource, 'duo: code changed after proposal review; rerun init and review the new digest'),
    'the live source digest is still enforced where it belongs, against the source it describes'
);

// DUO-3427: a rolled-back init must leave ZERO Duo ledger rows — a non-pristine
// ledger is `existing_duo_ledger`, so residue is the difference between a
// retryable environment and one that refuses the next init. Capture's test-only
// `capture_test_phase` marker is committed outside the consistent snapshot on
// purpose (its reader must see it while the writer is paused inside the held
// flock) and deleted in `finally` so no ordinary failure leaves residue — but
// `finally` does not run through a SIGKILL, and #151 later pointed init's
// SIGKILL fault seams at this same path. Every killed init committed one row
// nothing would read and no rollback would clear. It is now written only when
// a READING seam is requested — the bounded pause, or DUO-3430's
// wait-for-release gate, whose controller (regress_capture_concurrency) polls
// this exact marker cross-process; a run requesting neither seam writes no
// marker. Pinned as an ordering, because the behaviour itself needs a
// database: gate, then marker, then the wait branch, then the pause.
$captureSource = (string) file_get_contents(__DIR__ . '/../../agent/src/Capture/CapturePublicationWorkflow.php');
$markerGate = strpos($captureSource, '&& (($pauseMs > 0 && $pauseMs <= 10000) || $waitForRelease)) {');
$markerSet = strpos($captureSource, "Ledger::kv_set('capture_test_phase', 'locked');");
$markerWait = strpos($captureSource, 'if ($waitForRelease) {');
$markerPause = strpos($captureSource, 'usleep($pauseMs * 1000);');
check(
    $markerGate !== false && $markerSet !== false && $markerWait !== false
        && $markerPause !== false
        && $markerGate < $markerSet && $markerSet < $markerWait
        && $markerWait < $markerPause
        && substr_count($captureSource, "Ledger::kv_set('capture_test_phase'") === 1
        && substr_count($captureSource, "Ledger::kv_delete('capture_test_phase')") === 1,
    'the test-only capture phase marker is written only for a reading seam — pause or wait-for-release — so a killed init leaves no ledger residue'
);

// DUO-3421: the interrupted-init compensation runs over the same artifacts
// twice by design — Init::confirm()'s catch compensates its own publications,
// then re-enters recover_interrupted_attempt() to PROVE the rollback from the
// sealed journal. Every branch of that proof is presence-guarded and therefore
// idempotent except the two owned-file publications, which met a file they had
// just deleted and refused; the caller turned that into a retained journal,
// a retained lock, and an unclassified refusal where the contract promises a
// clean rollback.
$compensatedFixture = sys_get_temp_dir() . '/duo-init-compensated-' . bin2hex(random_bytes(6));
if (!mkdir($compensatedFixture, 0777, true)) fail('could not create the compensation fixture');
register_shutdown_function(static function () use ($compensatedFixture): void {
    exec('rm -rf ' . escapeshellarg($compensatedFixture));
});
$absentFile = $compensatedFixture . '/site.duo.json';
$presentFile = $compensatedFixture . '/.gitignore';
file_put_contents($presentFile, "published\n");
check(
    \Duo\InitOwnedArtifacts::owned_file_already_compensated(
        $absentFile,
        ['previous' => null, 'published' => 'x']
    ) === true,
    'a deleted owned file with no prior version to restore reads as already compensated'
);
check(
    \Duo\InitOwnedArtifacts::owned_file_already_compensated(
        $absentFile,
        ['previous' => "prior\n", 'published' => 'x']
    ) === false,
    'a deleted owned file whose record carries a prior version is still a compensation to perform'
);
check(
    \Duo\InitOwnedArtifacts::owned_file_already_compensated(
        $presentFile,
        ['previous' => null, 'published' => 'x']
    ) === false
        && \Duo\InitOwnedArtifacts::owned_file_already_compensated(
            $presentFile,
            ['previous' => "prior\n", 'published' => 'x']
        ) === false,
    'a present owned file is never skipped, whatever its record says'
);
check(
    substr_count($recoverySource, 'InitOwnedArtifacts::owned_file_already_compensated(') === 2
        && str_contains($ownedArtifactsSource, 'public static function owned_file_already_compensated('),
    'both owned-file publications — site.duo.json and .gitignore — carry the same idempotence guard as their sibling branches'
);

// DUO-3421: both owned-file publications are strictly write-ahead — the plan,
// carrying the previous bytes, is journaled BEFORE the path is touched — so an
// artifact that is present while the journal holds no plan for it predates the
// attempt and is none of recovery's business. Refusing it made every ordinary
// pre-existing .gitignore (i.e. every existing Git worktree, which is what the
// live harness sets up by name) an unprovable ownership situation and demanded
// manual recovery for a file Duo had never opened.
$gitignoreFixture = sys_get_temp_dir() . '/duo-init-unbound-' . bin2hex(random_bytes(6));
if (!mkdir($gitignoreFixture, 0777, true)) fail('could not create the pre-existing-artifact fixture');
register_shutdown_function(static function () use ($gitignoreFixture): void {
    exec('rm -rf ' . escapeshellarg($gitignoreFixture));
});
file_put_contents($gitignoreFixture . '/.gitignore', "state.capture-staging/\n");
check(
    $recoveryReason($gitignoreFixture, ['owned' => []]) === null,
    'a pre-existing .gitignore with no journaled plan leaves the interrupted attempt automatically recoverable'
);
file_put_contents($gitignoreFixture . '/site.duo.json', "{}\n");
check(
    $recoveryReason($gitignoreFixture, ['owned' => []]) === null,
    'a pre-existing adoption seed with no journaled plan is likewise not this attempt to prove'
);
unlink($gitignoreFixture . '/.gitignore');
symlink('/nonexistent', $gitignoreFixture . '/.gitignore');
check(
    $recoveryReason($gitignoreFixture, ['owned' => []])
        === 'the sealed attempt has a non-regular .gitignore boundary',
    'a non-regular owned-file boundary is still non-confirmable'
);
// DUO-3421: the proposal blocker and the recovery-time refusal describe the
// SAME artifact, and the harness (like both pins above) greps the proposal's
// words. The proposal said "partial code staging root" while every sibling
// message, the recovery refusal it precedes, and every pin said "tree", so the
// blocker fired correctly and named itself in words nothing else used.
check(
    substr_count($recoverySource, 'partial code staging tree without a complete descriptor') === 2
        && substr_count($recoverySource, 'partial state staging tree without a complete deletion manifest') === 2
        && !str_contains($recoverySource, 'partial code staging root')
        && !str_contains($recoverySource, 'partial state staging root'),
    'the proposal blockers and the recovery refusals name the partial code and state staging trees identically'
);
check(
    !str_contains($recoverySource, 'unbound site.duo.json')
        && !str_contains($recoverySource, 'unbound .gitignore')
        && substr_count($recoverySource, "is_array(\$owned['site_plan'] ?? null)") >= 1,
    'neither owned-file arm refuses an artifact the journal never planned; both require the plan they compensate against'
);

// DUO-3421: the pre-COMMIT rollback is a SUCCESSFUL outcome delivered as a
// non-zero exit — the interrupted attempt was proven and undone, and the
// operator simply reruns. Thrown as a bare RuntimeException on a command that
// is rightly absent from Cli::PUBLIC_REFUSAL_COMMANDS, it reached JSON callers
// as "init refused at an unclassified safety gate" with details_redacted:
// DUO-3398's shape on the recovery path. It has a reviewable shape, so per
// DUO-3399 it carries one.
require_once __DIR__ . '/../../agent/src/Kernel/CommandRefusal.php';
check(
    str_contains($initCompensationSource, "throw new CommandRefusalException(\n                    'interrupted_init_rolled_back',")
        && !str_contains(
            $initCompensationSource,
            "throw new \\RuntimeException(\n                    'duo: interrupted pre-COMMIT init was safely rolled back"
        ),
    'the proven pre-COMMIT rollback answers with a reviewed reason code, not the unclassified arm'
);
$rolledBack = new \Duo\CommandRefusalException(
    'interrupted_init_rolled_back',
    'duo: interrupted pre-COMMIT init was safely rolled back; rerun duo init and confirm the fresh proposal',
    'rerun duo init and confirm the fresh proposal it prints'
);
check(
    $rolledBack->reasonCode === 'interrupted_init_rolled_back'
        && str_contains($rolledBack->publicMessage, 'safely rolled back')
        && $rolledBack->detailsRedacted === false,
    'the rollback outcome survives the refusal class own sensitivity screen as a public answer'
);

// DUO-3421: init must be able to STAGE the payload it is certified to manage.
// The staging walk applied safe_component()'s identifier charset — the one for
// slugs Duo selects — to directory names the SITE owns, so WooCommerce
// 11.0.0's assets/client/blocks/@woocommerce made `duo init` refuse its own
// golden path after the journal and lock existed. Staging components now use
// the traversal/control-byte predicate the code half applies to these exact
// paths for the rest of their lifecycle (Code::safe_relative()).
require_once __DIR__ . '/../../agent/src/Code/Code.php';
require_once __DIR__ . '/../../agent/src/Init/InitCodeInventory.php';
require_once __DIR__ . '/../../agent/src/Init/InitCodeBaseline.php';
$stageComponent = (new ReflectionClass(\Duo\InitCodeBaseline::class))->getMethod('safe_stage_component');
$codeComponent = (new ReflectionClass(\Duo\Code::class))->getMethod('safe_component');
$ecosystemNames = [
    '@woocommerce' => true,
    'Inter-VariableFont_slnt,wght.woff2' => true,
    'akismet-refresh-logo@2x.png' => true,
    'woocommerce' => true,
    'twentytwentyone' => true,
    '' => false,
    '.' => false,
    '..' => false,
    'a/b' => false,
    'a\\b' => false,
    "a\0b" => false,
    "a\tb" => false,
];
$stageVerdicts = [];
$codeVerdicts = [];
foreach ($ecosystemNames as $name => $expected) {
    $stageVerdicts[$name] = (bool) $stageComponent->invoke(null, $name);
    $codeVerdicts[$name] = (bool) $codeComponent->invoke(null, $name);
}
check(
    $stageVerdicts === $ecosystemNames,
    'code staging accepts the real ecosystem component names and still refuses traversal, separators, and control bytes'
);
check(
    $stageVerdicts === $codeVerdicts,
    'init stages exactly the components the code half will carry afterwards — one predicate, no init-only refusal'
);
check(
    \Duo\InitCodeInventory::safeIdentifier('@woocommerce') === false
        && \Duo\InitCodeInventory::safeIdentifier('woocommerce') === true
        && substr_count($codeBaselineSource, 'self::safe_stage_component($part)') === 1
        && substr_count($codeInventorySource, 'self::safeIdentifier($component)') === 1
        && substr_count($codeInventorySource, 'self::safeIdentifier($theme)') === 1,
    'the selected plugin basename and theme slug keep the strict identifier charset; only the staging walk was widened'
);

final class InitRiskWpdb {
    public string $options = 'wp_options';
    public string $usermeta = 'wp_usermeta';
    public string $last_error = '';
    public bool $failOptions = false;
    public int $oversizedOptions = 0;
    public int $oversizedUserMeta = 0;
    /** @var list<array<string,mixed>> */
    public array $optionRows = [];
    /** @var list<array<string,mixed>> */
    public array $userMetaRows = [];

    public function get_results(string $sql, mixed $format): ?array {
        $this->last_error = '';
        if ($this->failOptions && str_contains($sql, $this->options)) {
            $this->last_error = 'sensitive database detail';
            return null;
        }
        $rows = str_contains($sql, $this->usermeta) ? $this->userMetaRows : $this->optionRows;
        preg_match('/> ([0-9]+).*LIMIT ([0-9]+)/s', $sql, $matches);
        $lastId = (int) ($matches[1] ?? 0);
        $limit = (int) ($matches[2] ?? 101);
        $idColumn = str_contains($sql, $this->usermeta) ? 'umeta_id' : 'option_id';
        $rows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => (int) ($row[$idColumn] ?? 0) > $lastId
        ));
        return array_slice($rows, 0, $limit);
    }

    public function get_var(string $sql): int {
        $this->last_error = '';
        return str_contains($sql, $this->usermeta) ? $this->oversizedUserMeta : $this->oversizedOptions;
    }
}

$secretFixture = tempnam(sys_get_temp_dir(), 'duo-init-long-secret-');
if (!is_string($secretFixture)) fail('could not create long-secret scanner fixture');
file_put_contents($secretFixture, "\n" . 'sk_live_' . str_repeat('A', 40000));
check(\Duo\InitCodeInventory::secretLabel($secretFixture) === 'stripe key', 'overlong boundary-less token is refused during streaming scan');
unlink($secretFixture);
$jwtFixture = tempnam(sys_get_temp_dir(), 'duo-init-jwt-shape-');
if (!is_string($jwtFixture)) fail('could not create JWT scanner fixture');
file_put_contents($jwtFixture, "\n" . 'eyJ' . str_repeat('A', 9000));
check(\Duo\InitCodeInventory::secretLabel($jwtFixture) === null, 'bare bundled base64url payload is not mislabeled as a JWT');
file_put_contents($jwtFixture, "\n" . 'eyJ' . str_repeat('A', 700) . '.eyJ' . str_repeat('B', 24) . '.signature');
check(\Duo\InitCodeInventory::secretLabel($jwtFixture) === 'jwt', 'complete long JWT remains blocked');
unlink($jwtFixture);
$originalWpdb = $GLOBALS['wpdb'] ?? null;
$fakeWpdb = new InitRiskWpdb();
$fakeWpdb->oversizedOptions = 2;
$fakeWpdb->oversizedUserMeta = 3;
$GLOBALS['wpdb'] = $fakeWpdb;
$boundedRisk = \Duo\InitSiteProbe::risk();
check(($boundedRisk['oversized'] ?? null) === ['options' => 2, 'user_meta' => 3], 'risk probe reports only redacted oversized omission counts');
check(($boundedRisk['truncated'] ?? false) === true, 'oversized values make risk readback explicitly incomplete');

$fakeWpdb->oversizedOptions = 0;
$fakeWpdb->oversizedUserMeta = 0;
for ($i = 1; $i <= 130; $i++) {
    $fakeWpdb->optionRows[] = [
        'option_id' => $i,
        'option_name' => "near_limit_$i",
        'option_value' => str_repeat('O', 65536),
    ];
}
$byteBoundedRisk = \Duo\InitSiteProbe::risk();
check(($byteBoundedRisk['scanned']['options'] ?? null) === 128, 'near-limit values stop at the deterministic 8 MiB surface budget');
check(($byteBoundedRisk['truncated'] ?? false) === true, 'byte-budget omission is reported as incomplete');

$fakeWpdb->failOptions = true;
try {
    \Duo\InitSiteProbe::risk();
    fail('failed risk query was reported as clean');
} catch (ReflectionException $unexpected) {
    throw $unexpected;
} catch (Throwable $expected) {
    check(str_contains($expected->getMessage(), 'could not read option values safely'), 'risk query error fails closed with a redacted diagnostic');
    check(!str_contains($expected->getMessage(), 'sensitive database detail'), 'risk query error does not disclose database details');
}
$GLOBALS['wpdb'] = $originalWpdb;

$adapterRepo = sys_get_temp_dir() . '/duo-init-adapter-permissions-' . bin2hex(random_bytes(6));
mkdir($adapterRepo . '/adapters', 0777, true);
file_put_contents($adapterRepo . '/adapters/foreign.json', "{}\n");
chmod($adapterRepo . '/adapters', 0000);
try {
    \Duo\AdapterSources::discover(__DIR__ . '/../../manifests', $adapterRepo);
    fail('unreadable adapter source was silently treated as empty');
} catch (RuntimeException $expected) {
    check(
        str_contains($expected->getMessage(), 'not readable')
            || str_contains($expected->getMessage(), 'could not be enumerated'),
        'unreadable adapter source fails closed instead of laundering inert bytes'
    );
} finally {
    chmod($adapterRepo . '/adapters', 0777);
    unlink($adapterRepo . '/adapters/foreign.json');
    rmdir($adapterRepo . '/adapters');
    rmdir($adapterRepo);
}

$nestedAdapterRepo = sys_get_temp_dir() . '/duo-init-nested-adapter-permissions-' . bin2hex(random_bytes(6));
mkdir($nestedAdapterRepo . '/adapters/nested', 0777, true);
file_put_contents($nestedAdapterRepo . '/adapters/nested/hidden.json', "{}\n");
chmod($nestedAdapterRepo . '/adapters/nested', 0000);
try {
    \Duo\AdapterSources::discover(__DIR__ . '/../../manifests', $nestedAdapterRepo);
    fail('unreadable nested adapter content was silently treated as empty');
} catch (RuntimeException $expected) {
    check(
        str_contains($expected->getMessage(), 'nested site adapter source')
            && str_contains($expected->getMessage(), 'could not be enumerated'),
        'unreadable nested adapter content fails closed instead of becoming inert bytes'
    );
} finally {
    chmod($nestedAdapterRepo . '/adapters/nested', 0777);
    unlink($nestedAdapterRepo . '/adapters/nested/hidden.json');
    rmdir($nestedAdapterRepo . '/adapters/nested');
    rmdir($nestedAdapterRepo . '/adapters');
    rmdir($nestedAdapterRepo);
}

// =====================================================================
// T6 §3.4 — the reviewed unmanaged-plugin decision.
//
// proposal_bound() needs a live WordPress for everything around this
// (SELECT VERSION(), the ledger probe, the code inventory walk), so the ONE
// decision the flag changes is its own private seam and is exercised
// directly. What is asserted here is the whole of it: which plugins are
// selected, which row each unselected plugin gets, and that the flag relaxes
// exactly one reason code.
// =====================================================================
$pluginSelection = new ReflectionMethod(\Duo\InitPlanner::class, 'plugin_selection');
$activeFixture = ['acme-catalog/acme-catalog.php', 'wpforms-lite/wpforms.php', 'woocommerce/woocommerce.php'];
$ownersFixture = [
    'woocommerce/woocommerce.php' => ['woocommerce'],
    'acme-catalog/acme-catalog.php' => ['acme-catalog', 'acme-catalog-alt'],
];

$blocked = $pluginSelection->invoke(null, $activeFixture, $ownersFixture, false);
check(
    $blocked['selected'] === ['woocommerce'],
    'without the flag only a plugin with exactly one declaring adapter is selected'
);
check(
    array_column($blocked['unsupported'], 'code') === ['ambiguous_plugin_adapter', 'active_plugin_without_adapter']
        && $blocked['advisories'] === [],
    'an unmanaged plugin still blocks init by default, alongside the ambiguous one'
);
$unmanagedRow = $blocked['unsupported'][1];
check(
    $unmanagedRow['extension'] === 'wpforms-lite/wpforms.php'
        && $unmanagedRow['remediation']
            === 'rerun duo init --allow-unmanaged-plugins to leave it unmanaged, or install/certify an '
                . 'adapter (duo adapter certify)',
    'the blocker names the flag AND the certification verb — before T6 it named only "install or review one '
    . 'versioned adapter", which no operator could finish'
);

$allowed = $pluginSelection->invoke(null, $activeFixture, $ownersFixture, true);
check(
    $allowed['selected'] === ['woocommerce'],
    'the flag selects nothing extra: an unmanaged plugin is still not managed'
);
check(
    array_column($allowed['unsupported'], 'code') === ['ambiguous_plugin_adapter'],
    'the flag relaxes exactly active_plugin_without_adapter — an ambiguous adapter is a different fact and '
    . 'leaving it unmanaged is not its remedy'
);
check(
    count($allowed['advisories']) === 1
        && $allowed['advisories'][0]['code'] === 'active_plugin_without_adapter'
        && $allowed['advisories'][0]['kind'] === 'plugin'
        && $allowed['advisories'][0]['extension'] === 'wpforms-lite/wpforms.php',
    'the unmanaged plugin is reported by name and reason code as an advisory, never silently dropped'
);
check(
    str_contains($allowed['advisories'][0]['remediation'], 'plugin:wpforms-lite')
        && str_contains($allowed['advisories'][0]['remediation'], 'left local (see UNMANAGED SCOPE)'),
    'the advisory says what init selects (nothing) and leaves local (its typed rows), and points at the assess surface that carries the decision'
);
// The same decision, carried through to the types those plugins register:
// init's own confirmation runs the baseline capture, and capture's scope gate
// refuses any plugin-registered type with rows that no rule names, so
// "leave the plugin unmanaged" must mean "its types stay local" or init
// cannot finish (grind_adapter_walk.sh S1 found exactly that).
$left = \Duo\InitPlanner::unmanaged_scope(
    ['post', 'page', 'attachment', 'product', 'wpforms', 'wpforms-template', 'scheduled-action'],
    ['category', 'post_tag', 'product_cat', 'form_group'],
    ['attachment', 'page', 'post', 'product'],
    ['category', 'post_tag', 'product_cat'],
    ['product', 'product_variation', 'shop_order', 'scheduled-action'],
    ['product_cat', 'product_visibility'],
    ['post' => 3, 'page' => 2, 'product' => 4, 'wpforms' => 2, 'scheduled-action' => 9],
    ['category' => 1, 'product_cat' => 2, 'form_group' => 1]
);
check(
    $left['scope'] === [
        'post_type' => ['wpforms' => ['class' => 'runtime']],
        'taxonomy' => ['form_group' => ['class' => 'runtime']],
    ],
    'a registered type with rows outside the proposed scope and undeclared by every selected adapter is left local as runtime; '
    . 'proposed, declared (any class) and empty types are not touched'
);
check(
    array_column($left['advisories'], 'extension') === ['post_type:wpforms', 'taxonomy:form_group']
        && $left['advisories'][0]['code'] === 'unmanaged_scope_left_local'
        && $left['advisories'][0]['kind'] === 'scope'
        && str_contains($left['advisories'][0]['reason'], 'holding 2 row(s)')
        && str_contains($left['advisories'][0]['remediation'], 'scope:post_type:wpforms'),
    'each left-local type is an advisory naming the type, its row count and the classify decision that re-manages it'
);
check(
    \Duo\InitPlanner::unmanaged_scope(['wpforms'], [], [], [], [], [], ['wpforms' => 0], [])
        === ['advisories' => [], 'scope' => ['post_type' => [], 'taxonomy' => []]],
    'a type with no rows is left alone: nothing is decided about a type that holds nothing yet'
);

// T6 §3.4's order — install, certify (--pin), rerun init — hands init an
// adoption seed that already carries the operator's explicit out-of-tree
// pins. Those pins are what init recomputes and republishes exactly, so
// they do not make the repository init-owned; a hand-added name-only pin or
// any policy edit still does. (grind_adapter_walk.sh S2 found `certify --pin`
// then `init` refusing existing_configuration.)
if (!defined('DUO_SPEC_VERSION')) {
    // existing_config() spells the seed with the engine's spec version; this
    // suite runs InitPlanner without duo.php, so the constant is the engine's
    // current value here (agent/duo.php defines it as 2).
    define('DUO_SPEC_VERSION', 2);
}
$seedRoot = sys_get_temp_dir() . '/duo_init_seed_' . bin2hex(random_bytes(4));
mkdir($seedRoot, 0777, true);
$existingConfig = new \ReflectionMethod(\Duo\InitPlanner::class, 'existing_config');
$seedBody = [
    'manifests' => ['core'],
    'policy' => [
        'options' => new \stdClass(), 'post_meta' => new \stdClass(), 'term_meta' => new \stdClass(),
        'post_types' => ['post', 'page', 'attachment'],
        'taxonomies' => ['category', 'post_tag'],
    ],
    'spec_version' => DUO_SPEC_VERSION,
];
$seedMode = static function (array $manifests, ?callable $edit = null) use ($seedRoot, $seedBody, $existingConfig): string {
    $body = $seedBody;
    $body['manifests'] = $manifests;
    if ($edit !== null) {
        $body = $edit($body);
    }
    \Duo\Canon::write_file($seedRoot . '/site.duo.json', \Duo\Canon::encode($body));

    return (string) $existingConfig->invoke(null, $seedRoot)['mode'];
};
$sitePin = ['digest' => str_repeat('a', 64), 'name' => 'wpforms', 'source' => 'site'];
$pluginPin = ['digest' => str_repeat('b', 64), 'name' => 'acme-catalog', 'source' => 'plugin'];
check($seedMode(['core']) === 'adoption-seed', 'the bare adoption seed reads adoption-seed');
check(
    $seedMode(['core', $sitePin]) === 'adoption-seed',
    'a seed carrying a certified site adapter pin ({name, source:"site", digest}) is still the adoption seed'
);
check(
    $seedMode(['core', $sitePin, $pluginPin]) === 'adoption-seed',
    'and so is one carrying an explicit plugin-source pin beside it'
);
check(
    $seedMode(['core', ['name' => 'woocommerce', 'source' => 'site', 'digest' => str_repeat('c', 64)]]) === 'adoption-seed',
    'an explicit override pin for a shipped name is set aside the same way — init republishes it exactly'
);
check($seedMode(['core', 'wpforms']) === 'owned', 'a hand-added NAME-ONLY pin is not a seed: it is an owned configuration');
check(
    $seedMode(['core', $sitePin], static function (array $b): array {
        $b['policy']['post_types'][] = 'product';

        return $b;
    }) === 'owned',
    'and any policy edit beside the pins still reads owned'
);
check($seedMode([$sitePin]) === 'owned', 'a pin set without core is not the seed either');
@unlink($seedRoot . '/site.duo.json');
@rmdir($seedRoot);

// T7 grind A2: a block theme's site-editor customisations live in core's
// non-public, _builtin FSE types, which the scope gate never names. Init
// proposes the certified core FSE profile's scope for a block theme and says
// so; a classic theme proposes nothing; an uncertified/absent profile is
// named as such and proposes nothing.
$fseProfiles = ['fse' => ['status' => 'certified', 'scope' => [
    'post_types' => ['wp_block', 'wp_navigation', 'wp_template', 'wp_template_part'],
    'taxonomies' => ['wp_pattern_category', 'wp_template_part_area', 'wp_theme'],
]]];
check(\Duo\InitPlanner::fse_profile_scope(false, $fseProfiles) === null, 'a classic theme proposes no FSE scope and says nothing');
$fseSelected = \Duo\InitPlanner::fse_profile_scope(true, $fseProfiles);
check(
    is_array($fseSelected)
        && $fseSelected['scope'] === $fseProfiles['fse']['scope']
        && $fseSelected['advisory']['code'] === 'fse_profile_scope_selected'
        && $fseSelected['advisory']['extension'] === 'profile:fse'
        && str_contains($fseSelected['advisory']['reason'], 'wp_template_part'),
    'a block theme proposes the certified FSE profile scope and prints which types it selected'
);
$fseMissing = \Duo\InitPlanner::fse_profile_scope(true, []);
check(
    is_array($fseMissing) && $fseMissing['scope'] === null
        && $fseMissing['advisory']['code'] === 'fse_profile_not_certified',
    'a block theme with no certified FSE profile proposes nothing and names the gap'
);
$fseUncertified = \Duo\InitPlanner::fse_profile_scope(true, ['fse' => ['status' => 'candidate', 'scope' => $fseProfiles['fse']['scope']]]);
check(
    is_array($fseUncertified) && $fseUncertified['scope'] === null
        && $fseUncertified['advisory']['code'] === 'fse_profile_not_certified',
    'an uncertified FSE profile is not proposed either'
);

check(
    \Duo\InitPlanner::ALLOW_UNMANAGED_PLUGINS === 'allow-unmanaged-plugins'
        && str_contains(
            (string) file_get_contents(__DIR__ . '/../../agent/src/Command/Cli.php'),
            '[--allow-unmanaged-plugins]'
        )
        && str_contains(
            (string) file_get_contents(__DIR__ . '/../../agent/src/Command/Cli.php'),
            "\$allowUnmanagedPlugins = isset(\$assoc['allow-unmanaged-plugins']);"
        ),
    'the wp-cli assoc key, the documented option and InitPlanner\'s own constant are one spelling — Cli.php '
    . 'uses the literal so the command surface does not drag the Init loader graph into every process that '
    . 'opens it, and this check is what keeps the two from drifting'
);

$blockerRow = new ReflectionMethod(\Duo\InitPlanner::class, 'capability_blocker_row');
$uncertified = $blockerRow->invoke(null, [
    'code' => 'adapter_source_uncertified',
    'name' => 'acme-catalog',
    'reason' => "'acme-catalog' is installed from the site adapter source and is uncertified by construction",
    'remediation' => 'obtain an externally signed certificate from an authority trusted by this agent',
    'source' => 'site',
    'trust_tier' => 'declarative_manifest',
]);
check(
    $uncertified['remediation']
        === 'certify it with duo adapter certify <site-repo> --name=acme-catalog, or remove it, then rerun duo init',
    'an installed-but-uncertified adapter blocks init with the certify-or-remove instruction, in that order'
);
// A PLUGIN-bundled adapter cannot be certified in place (the certificate
// binds source "site" and adapters/<name>.json), so its row keeps the
// registry's promotion-path remediation and only appends the rerun (walk S3
// read "certify it with duo adapter certify" against a bundled copy the verb
// would refuse).
$bundled = $blockerRow->invoke(null, [
    'code' => 'adapter_source_uncertified',
    'name' => 'acme-catalog',
    'reason' => "'acme-catalog' is installed from the plugin adapter source (plugins/acme-catalog/duo-adapter.json) and is uncertified by construction",
    'remediation' => 'install this adapter as a repository package at adapters/acme-catalog.json, obtain a certificate signed by an authority this agent trusts at adapters/certifications/acme-catalog.json, then run `wp duo manifest-pin --repo=... --name=acme-catalog` and commit the emitted {name,source:"site",digest} pin. The site copy wins by precedence and the bundled copy reports as not installed; the plugin stays active throughout',
    'source' => 'plugin',
    'trust_tier' => 'declarative_manifest',
]);
check(
    str_starts_with((string) $bundled['remediation'], 'install this adapter as a repository package at adapters/acme-catalog.json')
        && str_ends_with((string) $bundled['remediation'], ' — then rerun duo init (duo adapter certify <site-repo> --name=acme-catalog --pin signs and pins the promoted copy)'),
    'a plugin-bundled uncertified adapter keeps the promotion path as its remediation and appends the rerun'
);
$otherBlocker = $blockerRow->invoke(null, [
    'code' => 'authored_state_not_certified',
    'name' => 'woocommerce',
    'remediation' => 'regenerate the reviewed capability registry',
]);
check(
    $otherBlocker['remediation'] === 'regenerate the reviewed capability registry',
    'every other capability blocker keeps the reviewed registry\'s own remediation byte-for-byte'
);

echo "REGRESS_INIT_CONTRACT PASSED\n";

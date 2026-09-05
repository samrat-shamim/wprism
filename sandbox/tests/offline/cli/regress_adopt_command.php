<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../cli/src/Command/AdoptCommand.php';

use WPrism\Orchestrator\AdoptionTransport;
use WPrism\Orchestrator\Adopt;
use WPrism\Orchestrator\AdoptCommand;
use WPrism\Orchestrator\DriverCapability;
use WPrism\Orchestrator\DriverCapabilityReport;
use WPrism\Orchestrator\EnvironmentDriver;

function fail_adopt_command(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function assert_adopt_command(bool $condition, string $message): void {
    if (!$condition) {
        fail_adopt_command($message);
    }
}

final class AdoptCommandPlainDriver implements EnvironmentDriver {
    public int $calls = 0;

    public function name(): string { return 'plain-fixture'; }
    public function driverId(): string { return 'plain-fixture'; }
    public function repoPath(): string { return '/fixture/repo'; }
    public function describe(): string { return 'plain fixture'; }
    public function captureRaw(string $script): array {
        $this->calls++;
        return ['exit' => 99, 'stdout' => '', 'stderr' => 'unexpected target contact'];
    }
    public function captureWp(array $wpArgs): array {
        $this->calls++;
        return ['exit' => 99, 'stdout' => '', 'stderr' => 'unexpected target contact'];
    }
    public function streamWp(array $wpArgs): int {
        $this->calls++;
        return 99;
    }
    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver('plain-fixture', 'plain-fixture', $operation, []);
    }
}

final class AdoptCommandFakeTransport implements AdoptionTransport, EnvironmentDriver {
    /** @var list<string> */
    public array $rawScripts = [];
    /** @var list<array> */
    public array $wpArgs = [];
    public int $uploadCalls = 0;
    private string $distributionSha256;

    public function __construct(
        private string $sourceRoot,
        private bool $failUpload = false,
        public string $topology = 'wprism-single-site',
        public int $topologyExit = 0,
        public string $loaderGeneration = 'absent',
        public bool $directGlobalProcess = true
    ) {
        $this->distributionSha256 = Adopt::distributionDigest($sourceRoot);
    }

    public function bootstrapCapability(): array {
        return ['supported' => true, 'reason' => 'fixture bootstrap authority', 'remediation' => ''];
    }
    public function name(): string { return 'fake-adoption'; }
    public function driverId(): string { return 'fake-adoption'; }
    public function repoPath(): string { return '/fixture/repo'; }
    public function wpPath(): string { return '/fixture/wp'; }
    public function describe(): string { return 'fake adoption fixture'; }

    public function captureRaw(string $script): array {
        $this->rawScripts[] = $script;
        if ($script === 'echo wprism-reachable') {
            return ['exit' => 0, 'stdout' => "wprism-reachable\n", 'stderr' => ''];
        }
        // issue #3511: doctor's repo-path and tracked-status answers are line 1
        // and line 2 of one script now, so adopt's own transactional doctor
        // run sees the same two-line payload a real target prints.
        if (str_contains($script, 'site.wprism.json')
            && str_contains($script, 'git ls-files --error-unmatch .wprism-env-values.json')) {
            return ['exit' => 0, 'stdout' => "wprism-repo-ok\nwprism-untracked\n", 'stderr' => ''];
        }
        if (str_contains($script, 'archive=') && str_contains($script, 'agent_new=')) {
            return ['exit' => 0, 'stdout' => "wprism-repo-created\n", 'stderr' => ''];
        }
        if (str_contains($script, 'loader_generation_fence=absent')) {
            $loaderSha256 = match ($this->loaderGeneration) {
                'absent' => 'absent',
                'legacy-unfenced' => (string) hash_file('sha256', dirname(__DIR__, 2) . '/fixtures/legacy-wprism-loader.php'),
                'fenced-v1' => (string) hash_file('sha256', $this->sourceRoot . '/agent/wprism-loader.php'),
                default => hash('sha256', 'foreign-loader'),
            };
            return [
                'exit' => 0,
                'stdout' => "loader_generation_fence={$this->loaderGeneration}\nloader_sha256=$loaderSha256\n",
                'stderr' => '',
            ];
        }
        if (str_contains($script, 'agent/scoped-promotion-control.json')
            && str_contains($script, 'hash_final($ctx)')) {
            return ['exit' => 0, 'stdout' => $this->distributionSha256, 'stderr' => ''];
        }
        return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
    }

    public function captureWp(array $args): array {
        $this->wpArgs[] = $args;
        if ($args === ['core', 'is-installed']) {
            return ['exit' => 0, 'stdout' => "\n", 'stderr' => ''];
        }
        $snippet = (string) ($args[1] ?? '');
        if (str_contains($snippet, 'WPMU_PLUGIN_DIR')) {
            return ['exit' => 0, 'stdout' => "/fixture/mu\n", 'stderr' => ''];
        }
        // Existing SSH updates retain this target-loaded pre-swap probe;
        // initial adoption instead proves topology in BootstrapEligibility's
        // agent-independent isolated bootstrap.
        // Matched on the probe's own distinctive literal, not on
        // `is_multisite`: doctor's composed SITE_FACTS eval now names
        // is_multisite() too, and a looser pattern would shadow it
        // (first-match-wins).
        if (str_contains($snippet, 'wprism-single-site')) {
            if ($this->topologyExit !== 0) {
                return ['exit' => $this->topologyExit, 'stdout' => '', 'stderr' => 'fixture topology probe refused'];
            }
            return ['exit' => 0, 'stdout' => $this->topology . "\n", 'stderr' => ''];
        }
        if (str_contains($snippet, 'WPRISM_AGENT_VERSION')) {
            $source = (string) file_get_contents($this->sourceRoot . '/agent/wprism.php');
            preg_match("/define\\(\\s*'WPRISM_AGENT_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $source, $match);
            return ['exit' => 0, 'stdout' => ($match[1] ?? 'unknown') . "\n", 'stderr' => ''];
        }
        if (str_contains($snippet, 'wprism-policy-ok')) {
            return ['exit' => 0, 'stdout' => "wprism-policy-ok\n", 'stderr' => ''];
        }
        // issue #3511: doctor's three WordPress-side facts arrive in one eval.
        if (str_contains($snippet, 'class_exists')
            && str_contains($snippet, 'DISALLOW_FILE_MODS')
            && str_contains($snippet, 'db_server_info')) {
            return ['exit' => 0, 'stdout' => (string) json_encode([
                'agent' => 'wprism-ok',
                'file_mods' => 'wprism-set',
                'php' => '8.3.33',
                'db_version' => '11.8.8',
                'db_engine' => 'mariadb',
                'database_mutation' => [
                    'direct_global_process' => $this->directGlobalProcess,
                    'metadata_source' => 'INNODB_SYS_FOREIGN',
                    'metadata_source_readable' => $this->directGlobalProcess,
                ],
                'filesystem' => [
                    'directory_separator' => '/',
                    'os_family' => 'Linux',
                    'functions' => [
                        'chmod' => true,
                        'flock' => true,
                        'fsync' => true,
                        'lstat' => true,
                        'rename' => true,
                    ],
                ],
                'process' => [
                    'os_family' => 'Linux',
                    'functions' => [
                        'passthru' => true,
                        'posix_kill' => true,
                        'posix_setsid' => true,
                        'proc_close' => true,
                        'proc_get_status' => true,
                        'proc_open' => true,
                        'proc_terminate' => true,
                    ],
                    'shell' => ['executable' => true, 'path' => '/bin/sh'],
                    'wp_cli_opcache_enabled' => false,
                ],
                'wp' => '7.0.3',
                'site_mode' => 'single-site',
            ]) . "\n", 'stderr' => ''];
        }
        return ['exit' => 99, 'stdout' => '', 'stderr' => 'unexpected WordPress probe'];
    }

    public function uploadFile(string $localPath, string $remotePath): array {
        $this->uploadCalls++;
        if ($this->failUpload) {
            return ['exit' => 73, 'stdout' => '', 'stderr' => 'fixture upload refused'];
        }
        return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
    }

    public function streamWp(array $wpArgs): int { return 99; }
    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver(
            'fake-adoption',
            'fake-adoption',
            $operation,
            [
                DriverCapability::ATTACH => true,
                DriverCapability::RAW_CONTROL => true,
                DriverCapability::WP_CONTROL => true,
                DriverCapability::BOOTSTRAP => true,
                DriverCapability::CODE_TRANSFER => true,
            ]
        );
    }
}

$sourceRoot = dirname(__DIR__, 4);

$plain = new AdoptCommandPlainDriver();
$extraExit = AdoptCommand::run($plain, ['--unexpected'], $sourceRoot);
assert_adopt_command($extraExit === 1, 'extra adopt arguments refuse before any target call');
assert_adopt_command($plain->calls === 0, 'extra-argument refusal is target-free');

$plainExit = AdoptCommand::run($plain, [], $sourceRoot);
assert_adopt_command($plainExit === 1, 'non-adoption drivers refuse the adopt command');
assert_adopt_command($plain->calls === 0, 'transport-capability refusal is target-free');

$failed = new AdoptCommandFakeTransport($sourceRoot, true);
ob_start();
$failedExit = AdoptCommand::run($failed, [], $sourceRoot);
$failedOutput = (string) ob_get_clean();
assert_adopt_command($failedExit === 73, 'archive upload failure preserves the transport exit code');
assert_adopt_command(str_contains($failedOutput, 'adopt phase: staged install + transactional doctor'), 'failure prints the adopt phase');
assert_adopt_command($failed->uploadCalls === 1, 'failed adoption stops at the first upload');
assert_adopt_command(count($failed->rawScripts) === 2, 'upload failure performs only transport and loader-generation preflights');
// 2 -> 3: the pre-swap topology probe runs inside the try, ahead of the tar
// and the upload, so every path past the preflight now carries it.
assert_adopt_command(count($failed->wpArgs) === 3, 'upload failure performs only the WordPress preflight plus the pre-swap topology probe');

$healthy = new AdoptCommandFakeTransport($sourceRoot);
ob_start();
$healthyExit = AdoptCommand::run($healthy, [], $sourceRoot);
$healthyOutput = (string) ob_get_clean();
assert_adopt_command($healthyExit === 0, 'successful adoption returns zero after doctor verification');
assert_adopt_command(str_contains($healthyOutput, 'adopt: installed agent '), 'success reports the installed agent');
assert_adopt_command(str_contains($healthyOutput, '[PASS] transport reachable'), 'success renders the doctor result');
assert_adopt_command(str_contains($healthyOutput, '[WARN] DISALLOW_FILE_MODS set') === false, 'healthy fixture does not invent an advisory warning');
assert_adopt_command($healthy->uploadCalls === 1, 'successful adoption uploads one archive');
// The post-swap distribution readback adds one raw proof before the commit
// barrier. Doctor's composed probes remain 2 raw + 2 wp.
assert_adopt_command(count($healthy->rawScripts) === 10, 'adoption plus doctor performs the bounded raw probe set (' . count($healthy->rawScripts) . ')');
assert_adopt_command(count($healthy->wpArgs) === 7, 'adoption plus doctor performs the bounded WordPress probe set (' . count($healthy->wpArgs) . ')');

$withoutProcess = new AdoptCommandFakeTransport($sourceRoot, directGlobalProcess: false);
ob_start();
$withoutProcessExit = AdoptCommand::run($withoutProcess, [], $sourceRoot);
$withoutProcessOutput = (string) ob_get_clean();
assert_adopt_command($withoutProcessExit === 0, 'adoption remains available without the mutation-scoped PROCESS grant');
assert_adopt_command(
    str_contains($withoutProcessOutput, 'adopt: installed agent ')
        && str_contains($withoutProcessOutput, '[WARN] transactional database mutation (mariadb)')
        && str_contains($withoutProcessOutput, 'direct global PROCESS is missing'),
    'adoption succeeds but names its unavailable transactional database-mutation scope'
);

$legacyWithoutAttestation = new AdoptCommandFakeTransport(
    $sourceRoot,
    false,
    'wprism-single-site',
    0,
    'legacy-unfenced'
);
$legacyWithoutAttestationExit = AdoptCommand::run($legacyWithoutAttestation, [], $sourceRoot);
assert_adopt_command($legacyWithoutAttestationExit !== 0, 'the host adopt command refuses an unfenced legacy loader without attestation');
assert_adopt_command($legacyWithoutAttestation->uploadCalls === 0, 'legacy refusal occurs before distribution upload or target mutation');

$legacyAttested = new AdoptCommandFakeTransport(
    $sourceRoot,
    false,
    'wprism-single-site',
    0,
    'legacy-unfenced'
);
ob_start();
$legacyAttestedExit = AdoptCommand::run(
    $legacyAttested,
    ['--attest-legacy-loader-quiesced'],
    $sourceRoot
);
$legacyAttestedOutput = (string) ob_get_clean();
assert_adopt_command($legacyAttestedExit === 0, 'the host adopt command carries an explicit legacy-loader quiescence attestation');
assert_adopt_command(
    str_contains($legacyAttestedOutput, 'legacy unfenced loader transition used the explicit quiescence attestation'),
    'successful legacy adoption reports the one-time attested transition'
);

$blanketAttestation = new AdoptCommandFakeTransport($sourceRoot);
$blanketAttestationExit = AdoptCommand::run(
    $blanketAttestation,
    ['--attest-legacy-loader-quiesced'],
    $sourceRoot
);
assert_adopt_command($blanketAttestationExit !== 0, 'adopt rejects a blanket quiescence attestation when no legacy loader is installed');
assert_adopt_command($blanketAttestation->uploadCalls === 0, 'misapplied adoption attestation changes no target byte');

// The topology question is asked BEFORE the swap, and a network is refused
// with nothing installed. mu-plugins are network-wide, so the window between
// the install script and the post-swap Policy probe loads the drop-in on every
// blog of every request; on a WPRISM_JOURNAL target that window created per-blog
// `wp_N_wprism_*` tables Adopt::rollbackScript() cannot remove (it restores
// filesystem paths only, and the shipped tree has no DROP TABLE). Before this,
// adoption refused only from the post-swap Policy probe -- i.e. after a fully
// installed, network-wide swap, followed by a rollback whose story did not
// cover what the window could create.
// Driven through Adopt::install() rather than AdoptCommand::run(): the refusal
// PHASE is the fact under test, and install() returns it as data while the
// command renders it to STDERR.
$network = new AdoptCommandFakeTransport($sourceRoot, false, 'wprism-multisite');
$networkResult = Adopt::install($network, $sourceRoot);
assert_adopt_command($networkResult['exit'] !== 0, 'a network refuses adoption');
assert_adopt_command(
    $networkResult['phase'] === 'topology probe',
    "the refusal names the topology probe as its phase (not 'policy verification', which is post-swap): got '"
        . $networkResult['phase'] . "'"
);
assert_adopt_command(
    str_contains($networkResult['stderr'], 'multisite is unsupported by the certified v1 contract')
        && str_contains($networkResult['stderr'], 'single-site only'),
    'the refusal carries the same sentence the agent prints'
);
assert_adopt_command($network->uploadCalls === 0, 'a refused network adoption uploads nothing');
assert_adopt_command(
    count(array_filter($network->rawScripts, static fn(string $sc): bool => str_contains($sc, 'agent_new='))) === 0,
    'and never runs the install script, so $swapped never became true'
);
assert_adopt_command(
    count(array_filter($network->rawScripts, static fn(string $sc): bool => str_contains($sc, 'agent_prev='))) === 0,
    'and never runs a rollback, because there is nothing to roll back'
);
assert_adopt_command(
    count(array_filter(
        $network->wpArgs,
        static fn(array $a): bool => str_contains((string) ($a[1] ?? ''), 'wprism-policy-ok')
    )) === 0,
    'the post-swap Policy probe is never reached'
);
assert_adopt_command(
    count(array_filter(
        $network->wpArgs,
        static fn(array $a): bool => in_array('--skip-plugins', $a, true)
    )) === 0,
    'and the pre-swap probe never uses the isolated control bootstrap, which requires the not-yet-installed agent'
);

// Fail-closed: an adoption that cannot establish the topology must not swap
// either. A probe that exits non-zero is not a single-site answer.
$unreadable = new AdoptCommandFakeTransport($sourceRoot, false, 'wprism-single-site', 77);
$unreadableResult = Adopt::install($unreadable, $sourceRoot);
assert_adopt_command($unreadableResult['exit'] === 77, 'an unreadable topology answer preserves the transport exit code');
assert_adopt_command($unreadableResult['phase'] === 'topology probe', 'and refuses at the same phase');
assert_adopt_command($unreadable->uploadCalls === 0, 'fail-closed: an unanswerable topology probe uploads nothing');

$frontdoorCode = 0;
passthru(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../fixtures/adopt-public-preflight.php'),
    $frontdoorCode
);
assert_adopt_command($frontdoorCode === 0, 'the public dispatcher admits only independently proved initial bootstrap authority');
echo "PASS: adopt command\n";

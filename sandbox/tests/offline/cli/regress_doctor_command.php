<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../cli/src/Command/DoctorCommand.php';

use Duo\Orchestrator\AdoptionTransport;
use Duo\Orchestrator\DriverCapability;
use Duo\Orchestrator\DriverCapabilityReport;
use Duo\Orchestrator\DoctorCommand;
use Duo\Orchestrator\EnvironmentDriver;

function fail_doctor_command(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function assert_doctor_command(bool $condition, string $message): void {
    if (!$condition) fail_doctor_command($message);
}

final class UnreachableDoctorDriver implements EnvironmentDriver {
    public int $rawCalls = 0;
    public int $wpCalls = 0;
    public function name(): string { return 'fixture'; }
    public function driverId(): string { return 'fixture'; }
    public function repoPath(): string { return '/fixture/repo'; }
    public function describe(): string { return 'fixture'; }
    public function captureRaw(string $script): array {
        $this->rawCalls++;
        return ['exit' => 7, 'stdout' => '', 'stderr' => 'fixture transport down'];
    }
    public function captureWp(array $wpArgs): array {
        $this->wpCalls++;
        return ['exit' => 99, 'stdout' => '', 'stderr' => 'unexpected target contact'];
    }
    public function streamWp(array $wpArgs): int { return 99; }
    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver('fixture', 'fixture', $operation, []);
    }
}

class HealthyDoctorDriver implements EnvironmentDriver {
    public int $rawCalls = 0;
    public int $wpCalls = 0;

    /**
     * DUO-3511: an injected {exit,stdout,stderr} for the composed eval (null
     * = the healthy payload), and the production snippet exactly as Doctor
     * sent it. The suite runs that snippet at the bottom of this file, so the
     * per-field isolation the composition rests on is PROVEN rather than
     * imitated by a fixture that could agree with a broken snippet.
     */
    public ?array $factsResult = null;
    public string $factsSnippet = '';

    public function __construct(private bool $agentPresent = true) {}

    public function name(): string { return 'healthy-fixture'; }
    public function driverId(): string { return 'healthy-fixture'; }
    public function repoPath(): string { return '/fixture/repo'; }
    public function describe(): string { return 'healthy fixture'; }
    public function captureRaw(string $script): array {
        $this->rawCalls++;
        if ($script === 'echo duo-reachable') return ['exit' => 0, 'stdout' => "duo-reachable\n", 'stderr' => ''];
        // DUO-3511: ONE script now carries the repo-path question and the
        // .duo-env-values.json question, so it answers in the two lines the
        // production script prints. Both str_contains() are load-bearing:
        // matching the git half alone (what this fake did when they were two
        // probes) would answer without line 1 and sink the repo row, and a
        // re-split would fall through to the refusal below instead of
        // silently passing.
        if (str_contains($script, 'site.duo.json')
            && str_contains($script, 'git ls-files --error-unmatch .duo-env-values.json')) {
            return ['exit' => 0, 'stdout' => "duo-repo-ok\nduo-untracked\n", 'stderr' => ''];
        }
        return ['exit' => 99, 'stdout' => '', 'stderr' => 'unexpected raw probe'];
    }
    public function captureWp(array $wpArgs): array {
        $this->wpCalls++;
        if ($wpArgs === ['core', 'is-installed']) return ['exit' => 0, 'stdout' => "\n", 'stderr' => ''];
        $snippet = (string) ($wpArgs[1] ?? '');
        // DUO-3511: the composed eval, recognised by all three facts it must
        // carry — a snippet that lost one is not this call and must not be
        // answered as if it were.
        if (str_contains($snippet, 'class_exists')
            && str_contains($snippet, 'DISALLOW_FILE_MODS')
            && str_contains($snippet, 'db_server_info')) {
            $this->factsSnippet = $snippet;
            if ($this->factsResult !== null) {
                return $this->factsResult;
            }
            return ['exit' => 0, 'stdout' => (string) json_encode([
                'agent' => $this->agentPresent ? 'duo-ok' : 'duo-missing',
                'file_mods' => 'duo-unset',
                'php' => '8.3.33',
                'db_version' => '11.8.8',
                'db_engine' => 'mariadb',
                'wp' => '7.0.3',
            ]) . "\n", 'stderr' => ''];
        }
        return ['exit' => 99, 'stdout' => '', 'stderr' => 'unexpected WordPress probe'];
    }
    public function streamWp(array $wpArgs): int { return 99; }
    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver('healthy-fixture', 'healthy-fixture', $operation, []);
    }
}

class AdoptableDoctorDriver extends HealthyDoctorDriver implements AdoptionTransport {
    public function __construct(bool $agentPresent = true, private bool $adoptionAuthorized = true) {
        parent::__construct($agentPresent);
    }
    public function wpPath(): string { return '/fixture/wordpress'; }
    public function bootstrapCapability(): array {
        return $this->adoptionAuthorized
            ? ['supported' => true, 'reason' => '', 'remediation' => '']
            : [
                'supported' => false,
                'reason' => 'fixture bootstrap is not authorized',
                'remediation' => 'authorize bootstrap in the untracked machine-local overlay',
            ];
    }
    public function uploadFile(string $localPath, string $remotePath): array {
        return ['exit' => 99, 'stdout' => '', 'stderr' => 'unexpected upload'];
    }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        $supported = [
            DriverCapability::ATTACH => true,
            DriverCapability::RAW_CONTROL => true,
            DriverCapability::WP_CONTROL => true,
        ];
        $unsupported = [];
        if ($this->adoptionAuthorized) {
            $supported[DriverCapability::BOOTSTRAP] = true;
            $supported[DriverCapability::CODE_TRANSFER] = true;
        } else {
            $detail = [
                'reason' => 'fixture bootstrap is not authorized',
                'remediation' => 'authorize bootstrap in the untracked machine-local overlay',
            ];
            $unsupported[DriverCapability::BOOTSTRAP] = $detail;
            $unsupported[DriverCapability::CODE_TRANSFER] = $detail;
        }
        return DriverCapabilityReport::forDriver(
            'healthy-fixture', 'healthy-fixture', $operation, $supported, $unsupported
        );
    }
}

$driver = new UnreachableDoctorDriver();
ob_start();
$exit = DoctorCommand::run($driver);
$output = (string) ob_get_clean();
assert_doctor_command($exit === 1, 'unreachable doctor exits with failure');
assert_doctor_command($driver->rawCalls === 1, 'doctor performs only the reachability probe before refusing');
assert_doctor_command($driver->wpCalls === 0, 'doctor does not contact WordPress after reachability fails');
assert_doctor_command(str_contains($output, '[FAIL] transport reachable — fixture transport down'), 'doctor renders the transport failure');
assert_doctor_command(str_contains($output, '[FAIL] WordPress installed — skipped: transport unreachable'), 'doctor renders skipped downstream checks');

$healthy = new HealthyDoctorDriver();
ob_start();
$healthyExit = DoctorCommand::run($healthy);
$healthyOutput = (string) ob_get_clean();
assert_doctor_command($healthyExit === 0, 'healthy doctor exits successfully');
// DUO-3511: the shape, not just the rendering. Doctor answered these same
// ten rows in SEVEN target calls before this — three of the four `wp eval`s
// were independent siblings under one `if ($installed)`, and the git probe
// re-entered a target the repo probe had just left. Every row below renders
// identically either way, so a re-split is only ever visible as a count.
assert_doctor_command($healthy->rawCalls === 2, 'healthy doctor makes exactly two raw round trips: reachability, then the composed repo/git script (made ' . $healthy->rawCalls . ')');
assert_doctor_command($healthy->wpCalls === 2, 'healthy doctor makes exactly two wp round trips: core is-installed, then the composed facts eval (made ' . $healthy->wpCalls . ')');
assert_doctor_command(str_contains($healthyOutput, '[PASS] transport reachable'), 'healthy doctor renders a pass row');
assert_doctor_command(str_contains($healthyOutput, '[WARN] DISALLOW_FILE_MODS set'), 'healthy doctor renders advisory warning');

/** @return array{exit:int,output:string,driver:HealthyDoctorDriver} */
$compatibilityCase = static function (array $override): array {
    $facts = [
        'agent' => 'duo-ok',
        'file_mods' => 'duo-set',
        'php' => '8.3.33',
        'db_version' => '11.8.8',
        'db_engine' => 'mariadb',
        'wp' => '7.0.3',
    ];
    foreach ($override as $key => $value) {
        $facts[$key] = $value;
    }
    $driver = new HealthyDoctorDriver();
    $driver->factsResult = [
        'exit' => 0,
        'stdout' => (string) json_encode($facts) . "\n",
        'stderr' => '',
    ];
    ob_start();
    $exit = DoctorCommand::run($driver);
    return ['exit' => $exit, 'output' => (string) ob_get_clean(), 'driver' => $driver];
};

foreach ([
    'PHP inclusive minimum' => ['php' => '8.3.0'],
    'PHP value below the exclusive maximum' => ['php' => '8.3.99'],
    'MariaDB inclusive minimum' => ['db_version' => '11.0.0'],
    'MariaDB value below the exclusive maximum' => ['db_version' => '11.99.99'],
] as $label => $override) {
    $case = $compatibilityCase($override);
    assert_doctor_command($case['exit'] === 0, "$label remains inside the declared platform boundary");
}

foreach ([
    'PHP below minimum' => [['php' => '8.2.99'], '[FAIL] PHP version (8.2.99)'],
    'PHP exact exclusive maximum' => [['php' => '8.4.0'], '[FAIL] PHP version (8.4.0)'],
    'MariaDB below minimum' => [['db_version' => '10.11.0'], '[FAIL] database (mariadb 10.11.0)'],
    'MariaDB exact exclusive maximum' => [['db_version' => '12.0.0'], '[FAIL] database (mariadb 12.0.0)'],
    'different database engine' => [['db_engine' => 'mysql'], '[FAIL] database (mysql 11.8.8)'],
    'older WordPress core' => [['wp' => '7.0.2'], '[FAIL] WordPress core (7.0.2)'],
    'newer WordPress core' => [['wp' => '7.0.4'], '[FAIL] WordPress core (7.0.4)'],
] as $label => [$override, $needle]) {
    $case = $compatibilityCase($override);
    assert_doctor_command($case['exit'] === 1, "$label is a blocking platform refusal");
    assert_doctor_command(str_contains($case['output'], $needle), "$label names the exact failing platform row");
    assert_doctor_command(
        $case['driver']->rawCalls === 2 && $case['driver']->wpCalls === 2,
        "$label uses the composed four-round-trip doctor path"
    );
}

$missingAgent = new AdoptableDoctorDriver(false);
ob_start();
$missingAgentExit = DoctorCommand::run($missingAgent);
$missingAgentOutput = (string) ob_get_clean();
assert_doctor_command($missingAgentExit === 1, 'pre-adoption doctor remains a blocking failure');
assert_doctor_command(str_contains(
    $missingAgentOutput,
    "[FAIL] duo agent present — agent class not found (wp eval returned 'duo-missing'); next step: duo adopt 'healthy-fixture'"
), 'pre-adoption doctor prints the exact host-side adopt command');

$unauthorizedLocalAgent = new AdoptableDoctorDriver(false, false);
ob_start();
$unauthorizedLocalExit = DoctorCommand::run($unauthorizedLocalAgent);
$unauthorizedLocalOutput = (string) ob_get_clean();
assert_doctor_command($unauthorizedLocalExit === 1, 'missing agent remains blocking when local adoption lacks authorization');
assert_doctor_command(
    str_contains($unauthorizedLocalOutput, "host-side duo adopt is not ready for driver 'healthy-fixture'")
        && str_contains($unauthorizedLocalOutput, 'authorize bootstrap in the untracked machine-local overlay')
        && !str_contains($unauthorizedLocalOutput, 'next step: duo adopt'),
    'adoption-capable doctor relays its target-free capability remediation instead of suggesting a blocked adopt'
);

$missingDockerAgent = new HealthyDoctorDriver(false);
ob_start();
$missingDockerAgentExit = DoctorCommand::run($missingDockerAgent);
$missingDockerAgentOutput = (string) ob_get_clean();
assert_doctor_command($missingDockerAgentExit === 1, 'missing agent remains blocking when host-side adoption is unavailable');
assert_doctor_command(
    str_contains($missingDockerAgentOutput, "host-side duo adopt is unavailable for driver 'healthy-fixture'")
        && str_contains($missingDockerAgentOutput, "install or mount the Duo agent through that environment's control plane")
        && !str_contains($missingDockerAgentOutput, "next step: duo adopt"),
    'non-adoptable doctor directs the operator to the environment control plane instead of an impossible adopt command'
);

// DUO-3511: a per-field sentinel. The target's own try/catch caught a
// $wpdb->db_version() throw and left that ONE field null; the rows that never
// needed the database must still print their real answers. This is the
// assertion a naive composition fails — one try around the whole snippet, or
// a host-side read that demands every field before trusting any, turns a
// compatibility-row failure into "agent class not found" on a site whose
// agent is installed and fine.
$sunkDb = new HealthyDoctorDriver();
$sunkDb->factsResult = ['exit' => 0, 'stdout' => (string) json_encode([
    'agent' => 'duo-ok',
    'file_mods' => 'duo-unset',
    'php' => '8.3.33',
    'db_version' => null,
    'db_engine' => null,
    'wp' => '7.0.2',
]) . "\n", 'stderr' => ''];
ob_start();
$sunkDbExit = DoctorCommand::run($sunkDb);
$sunkDbOutput = (string) ob_get_clean();
assert_doctor_command($sunkDbExit === 1, 'an unreadable database fact remains a blocking doctor failure');
assert_doctor_command(str_contains($sunkDbOutput, '[PASS] duo agent present'), 'a sunk database fact did not sink the agent-presence row');
assert_doctor_command(str_contains($sunkDbOutput, '[WARN] DISALLOW_FILE_MODS set'), 'a sunk database fact did not sink the DISALLOW_FILE_MODS row');
assert_doctor_command(!str_contains($sunkDbOutput, 'agent class not found'), 'a sunk database fact did not fabricate a missing agent');
assert_doctor_command(str_contains(
    $sunkDbOutput,
    '[FAIL] compatibility baseline (docs/compatibility-baseline.json) — could not read PHP/database/WordPress facts from the environment:'
), 'a sunk database fact fails the compatibility row it actually belongs to');

// DUO-3511: an undecodable payload is the one case where every row falls back
// to exactly what it printed when it owned its own eval — including this
// sentence, which has been doctor's answer to an unreadable environment since
// DUO-3222, and including quoting back what the target really printed rather
// than a JSON blob it never sent.
$garbled = new HealthyDoctorDriver();
$garbled->factsResult = ['exit' => 0, 'stdout' => "PHP Notice: a plugin wrote to stdout\n", 'stderr' => ''];
ob_start();
$garbledExit = DoctorCommand::run($garbled);
$garbledOutput = (string) ob_get_clean();
assert_doctor_command($garbledExit === 1, 'an undecodable facts payload remains a blocking doctor failure');
assert_doctor_command(str_contains(
    $garbledOutput,
    '[FAIL] compatibility baseline (docs/compatibility-baseline.json) — could not read PHP/database/WordPress facts from the environment: PHP Notice: a plugin wrote to stdout'
), 'an undecodable payload reproduces the pre-composition compatibility refusal verbatim');
assert_doctor_command(str_contains(
    $garbledOutput,
    "agent class not found (wp eval returned 'PHP Notice: a plugin wrote to stdout')"
), 'an undecodable payload quotes what the target printed, as the single-purpose eval did');
assert_doctor_command(str_contains($garbledOutput, '[WARN] DISALLOW_FILE_MODS set'), 'an undecodable payload leaves DISALLOW_FILE_MODS advisory, never blocking');

ob_start();
DoctorCommand::render([
    'ok' => true,
    'checks' => [
        ['label' => 'hard check', 'ok' => true, 'detail' => ''],
        ['label' => 'advisory check', 'ok' => false, 'detail' => 'recommended setting missing', 'advisory' => true],
    ],
]);
$rendered = (string) ob_get_clean();
assert_doctor_command(str_contains($rendered, '[PASS] hard check'), 'render preserves pass rows');
assert_doctor_command(str_contains($rendered, '[WARN] advisory check'), 'render labels advisory failures as warnings');
assert_doctor_command(str_contains($rendered, 'recommended setting missing'), 'render preserves advisory detail');

// DUO-3511: the isolation the whole composition rests on, proven by RUNNING
// the production snippet Doctor just sent (captured above) instead of
// trusting a fixture to imitate it — a fixture can only agree with a snippet
// that is already broken. $wpdb->db_version() throws here the way it does on
// a target whose database handle is gone; class_exists() and defined() cannot
// throw, so the agent and DISALLOW_FILE_MODS fields MUST survive it. One try
// around the whole snippet — the obvious naive composition — emits
// {"agent":null,…} and fails this block.
//
// A two-method duck type, deliberately not sandbox/tests/lib's FakeWpdb:
// that class is a SQL interpreter for agent-side suites, ships no
// db_server_info() at all, and offers no seam for a throwing db_version() —
// the only two behaviours this block needs.
final class ThrownDbWpdb {
    public function db_version(): string {
        throw new RuntimeException('MySQL server has gone away');
    }
    public function db_server_info(): string {
        return '11.8.8-MariaDB-1:11.8.8+maria~ubu2404';
    }
}
if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show): string { return $show === 'version' ? '7.0.3' : ''; }
}
class_alias(stdClass::class, 'Duo\\Capture');
$GLOBALS['wpdb'] = new ThrownDbWpdb();
assert_doctor_command($healthy->factsSnippet !== '', 'the healthy run recorded the composed eval snippet');
ob_start();
eval($healthy->factsSnippet);
$payload = (string) ob_get_clean();
$facts = json_decode($payload, true);
assert_doctor_command(is_array($facts), "the composed snippet emitted a JSON object through a throwing \$wpdb (got '$payload')");
assert_doctor_command(($facts['agent'] ?? null) === 'duo-ok', 'a throwing $wpdb sank the agent-presence field');
assert_doctor_command(($facts['file_mods'] ?? null) === 'duo-unset', 'a throwing $wpdb sank the DISALLOW_FILE_MODS field');
assert_doctor_command(($facts['php'] ?? null) === PHP_VERSION, 'a throwing $wpdb sank the PHP version field');
assert_doctor_command(($facts['wp'] ?? null) === '7.0.3', 'a throwing $wpdb sank the WordPress version field');
assert_doctor_command(array_key_exists('db_version', $facts) && $facts['db_version'] === null, 'the thrown field did not leave its own null sentinel');
assert_doctor_command(($facts['db_engine'] ?? null) === 'mariadb', 'the sibling database field did not answer independently of the thrown one');

echo "PASS: doctor command\n";

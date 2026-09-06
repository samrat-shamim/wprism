<?php
declare(strict_types=1);

/**
 * Doctor::read_baseline() resolves docs/compatibility-baseline.json from
 * dirname(__DIR__, 3) of cli/src/Onboarding/Doctor.php and has no injection
 * seam — cli/ ships as one tree, not as a path-configurable library, and
 * adding a seam to the production path just to test it is not a change this
 * boundary needs. So the truncated-baseline case at the bottom of this file
 * re-invokes THIS suite against a COPY of cli/ whose baseline is deliberately
 * incomplete; WPRISM_DOCTOR_BASELINE_PROBE_ROOT is that copy's root and is set
 * only by this file. Re-entering the same file is what lets the probe reuse
 * the drivers below instead of a second fixture that could agree with a
 * broken Doctor.
 */
$doctorCommandRoot = (string) (getenv('WPRISM_DOCTOR_BASELINE_PROBE_ROOT') ?: dirname(__DIR__, 4));
require_once $doctorCommandRoot . '/cli/src/Command/DoctorCommand.php';

use WPrism\Orchestrator\AdoptionTransport;
use WPrism\Orchestrator\DriverCapability;
use WPrism\Orchestrator\DriverCapabilityReport;
use WPrism\Orchestrator\DoctorCommand;
use WPrism\Orchestrator\EnvironmentDriver;

function fail_doctor_command(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function assert_doctor_command(bool $condition, string $message): void {
    if (!$condition) fail_doctor_command($message);
}

/** @return array{os_family:string,functions:array<string,bool>,shell:array{executable:bool,path:string}} */
function healthy_doctor_process_facts(): array {
    return [
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
    ];
}

/** @return array{direct_global_process:bool,metadata_source:string,metadata_source_readable:bool} */
function healthy_doctor_database_mutation_facts(string $engine = 'mariadb'): array {
    return [
        'direct_global_process' => true,
        'metadata_source' => $engine === 'mariadb' ? 'INNODB_SYS_FOREIGN' : 'INNODB_FOREIGN',
        'metadata_source_readable' => true,
    ];
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
     * issue #3511: an injected {exit,stdout,stderr} for the composed eval (null
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
        if ($script === 'echo wprism-reachable') return ['exit' => 0, 'stdout' => "wprism-reachable\n", 'stderr' => ''];
        // issue #3511: ONE script now carries the repo-path question and the
        // .wprism-env-values.json question, so it answers in the two lines the
        // production script prints. Both str_contains() are load-bearing:
        // matching the git half alone (what this fake did when they were two
        // probes) would answer without line 1 and sink the repo row, and a
        // re-split would fall through to the refusal below instead of
        // silently passing.
        if (str_contains($script, 'site.wprism.json')
            && str_contains($script, 'git ls-files --error-unmatch .wprism-env-values.json')) {
            return ['exit' => 0, 'stdout' => "wprism-repo-ok\nwprism-untracked\n", 'stderr' => ''];
        }
        return ['exit' => 99, 'stdout' => '', 'stderr' => 'unexpected raw probe'];
    }
    public function captureWp(array $wpArgs): array {
        $this->wpCalls++;
        if ($wpArgs === ['core', 'is-installed']) return ['exit' => 0, 'stdout' => "\n", 'stderr' => ''];
        $snippet = (string) ($wpArgs[1] ?? '');
        // issue #3511: the composed eval, recognised by all three facts it must
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
                'agent' => $this->agentPresent ? 'wprism-ok' : 'wprism-missing',
                'file_mods' => 'wprism-unset',
                'php' => '8.3.33',
                'db_version' => '11.8.8',
                'db_engine' => 'mariadb',
                'database_mutation' => healthy_doctor_database_mutation_facts(),
                'filesystem' => [
                    'directory_separator' => '/',
                    'os_family' => 'Linux',
                    'functions' => ['chmod' => true, 'flock' => true, 'fsync' => true, 'lstat' => true, 'rename' => true],
                ],
                'process' => healthy_doctor_process_facts(),
                'wp' => '7.0.3',
                'site_mode' => 'single-site',
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
            DriverCapability::BOUNDED_CONTROL => true,
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

// Probe mode: one healthy run against the copied tree, nothing else. The exit
// status and rendered rows are the product answer the parent process asserts.
if (getenv('WPRISM_DOCTOR_BASELINE_PROBE_ROOT') !== false) {
    exit(DoctorCommand::run(new HealthyDoctorDriver()));
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
// issue #3511: the shape, not just the rendering. Doctor answered these same
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
        'agent' => 'wprism-ok',
        'file_mods' => 'wprism-set',
        'php' => '8.3.33',
        'db_version' => '11.8.8',
        'db_engine' => 'mariadb',
        'database_mutation' => healthy_doctor_database_mutation_facts(),
        'filesystem' => [
            'directory_separator' => '/',
            'os_family' => 'Linux',
            'functions' => ['chmod' => true, 'flock' => true, 'fsync' => true, 'lstat' => true, 'rename' => true],
        ],
        'process' => healthy_doctor_process_facts(),
        'wp' => '7.0.3',
        'site_mode' => 'single-site',
    ];
    foreach ($override as $key => $value) {
        $facts[$key] = $value;
    }
    if (array_key_exists('db_engine', $override)
        && !array_key_exists('database_mutation', $override)
        && is_string($facts['db_engine'])) {
        $facts['database_mutation'] = healthy_doctor_database_mutation_facts($facts['db_engine']);
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

// Doctor's WordPress and PHP predicates are independent host-side copies of
// the agent's (Doctor.php's own read_baseline()/in_range() comment records why
// cli/ and agent/src/ do not share code), so each needs its own coverage of
// the same two conditions or the two halves drift silently: a runtime doctor
// calls compatible that the direct product path refuses is the exact failure
// the issue #3222 rationale comment above the check forbids.
//
// One asymmetry with the agent-side suite is deliberate and worth naming:
// doctor reads the REAL docs/compatibility-baseline.json off disk, so no cell
// here can hole an axis the way sandbox/tests/offline/policy/
// regress_platform_compatibility.php does with an in-memory fixture. The
// series half of the PHP predicate is therefore proven agent-side only; what
// these cells prove is that doctor's answer agrees with the agent's on every
// value the shipped claim actually admits or refuses.
foreach ([
    'PHP inclusive minimum' => [['php' => '8.3.0'], ''],
    'PHP value below the exclusive maximum' => [['php' => '8.3.99'], ''],
    // 8.4.0 was a blocking FAIL until the claim widened to [8.3.0, 8.5.0):
    // these two cells fail against the prior boundary.
    'PHP inclusive minimum of the newly exercised 8.4 series' => [['php' => '8.4.0'], '[PASS] PHP version (8.4.0)'],
    'PHP proof patch of the newly exercised 8.4 series' => [['php' => '8.4.24'], '[PASS] PHP version (8.4.24)'],
    'MariaDB inclusive minimum' => [['db_version' => '11.0.0'], ''],
    'MariaDB value below the exclusive maximum' => [['db_version' => '11.99.99'], ''],
    // The engines map is what makes this a PASS: it was the '[FAIL] database
    // (mysql ...)' cell below until MySQL got its own claimed range. The
    // engine name arrives from the target lower-cased, so this also pins that
    // doctor's lookup stays case-insensitive against a claim spelled MySQL.
    'claimed MySQL engine inside its own range' => [['db_engine' => 'mysql', 'db_version' => '8.4.3'], '[PASS] database (mysql 8.4.3)'],
    'WordPress older exercised series' => [['wp' => '6.9.2'], '[PASS] WordPress core (6.9.2)'],
    'WordPress inclusive minimum' => [['wp' => '6.9.0'], '[PASS] WordPress core (6.9.0)'],
    'WordPress unrun patch inside an exercised series' => [['wp' => '7.0.4'], '[PASS] WordPress core (7.0.4)'],
    'WordPress patch below last_verified inside its series' => [['wp' => '7.0.2'], '[PASS] WordPress core (7.0.2)'],
    // The two-component core string WordPress ships for a series' first
    // release (wp-includes/version.php:19 of 7.1 reads $wp_version = '7.1').
    // Doctor's own dotted-shape guard is /^\d+(?:\.\d+){1,3}$/D, the agent's
    // regex exactly, so both halves must call this compatible or the host
    // screen and the product path disagree about the newest claimed core.
    'WordPress two-component core for a series first release' => [['wp' => '7.1'], '[PASS] WordPress core (7.1)'],
    'WordPress unrun patch inside the newly exercised 7.1 series' => [['wp' => '7.1.0'], '[PASS] WordPress core (7.1.0)'],
] as $label => [$override, $needle]) {
    $case = $compatibilityCase($override);
    assert_doctor_command($case['exit'] === 0, "$label remains inside the declared platform boundary");
    assert_doctor_command(
        $needle === '' || str_contains($case['output'], $needle),
        "$label renders its exact passing platform row"
    );
}

$missingProcessAuthority = $compatibilityCase(['database_mutation' => [
    'direct_global_process' => false,
    'metadata_source' => 'INNODB_SYS_FOREIGN',
    'metadata_source_readable' => false,
]]);
assert_doctor_command(
    $missingProcessAuthority['exit'] === 0,
    'missing PROCESS authority does not turn the scoped mutation prerequisite into a blanket read-only refusal'
);
assert_doctor_command(
    str_contains($missingProcessAuthority['output'], '[WARN] transactional database mutation (mariadb)')
        && str_contains($missingProcessAuthority['output'], 'requires direct `GRANT PROCESS ON *.*` authority')
        && str_contains($missingProcessAuthority['output'], 'schema/table SELECT grants cannot reveal a cascading child in another schema')
        && str_contains($missingProcessAuthority['output'], 'every transactional database mutation will refuse before its first write')
        && str_contains($missingProcessAuthority['output'], 'direct global PROCESS is missing'),
    'doctor names the exact scoped grant, hidden cross-schema hazard, and fail-closed mutation outcome'
);

$unreadableMysqlSource = $compatibilityCase([
    'db_engine' => 'mysql',
    'db_version' => '8.4.3',
    'database_mutation' => [
        'direct_global_process' => true,
        'metadata_source' => 'INNODB_FOREIGN',
        'metadata_source_readable' => false,
    ],
]);
assert_doctor_command(
    $unreadableMysqlSource['exit'] === 0
        && str_contains($unreadableMysqlSource['output'], '[WARN] transactional database mutation (mysql)')
        && str_contains($unreadableMysqlSource['output'], 'information_schema.INNODB_FOREIGN')
        && str_contains($unreadableMysqlSource['output'], 'the metadata source was not readable'),
    'doctor diagnoses the MySQL 8.4 source independently of the direct PROCESS row'
);

$unknownMutationFacts = $compatibilityCase(['database_mutation' => null]);
assert_doctor_command(
    $unknownMutationFacts['exit'] === 0
        && str_contains($unknownMutationFacts['output'], '[WARN] transactional database mutation (unknown)')
        && str_contains($unknownMutationFacts['output'], 'read-only workflows remain available'),
    'an unreadable mutation probe is loud but does not erase read-only product availability'
);

foreach ([
    'PHP below minimum' => [['php' => '8.2.99'], '[FAIL] PHP version (8.2.99)'],
    'PHP exact exclusive maximum' => [['php' => '8.5.0'], '[FAIL] PHP version (8.5.0)'],
    // The agent refuses an observed value that is not a plain dotted version
    // (PlatformCompatibility::inside_range()); doctor's PHP row carries the
    // same guard the WordPress row already did, or it would call a
    // pre-release engine compatible that `wp wprism` refuses.
    'PHP pre-release runtime inside an exercised series' => [['php' => '8.4.0RC1'], '[FAIL] PHP version (8.4.0RC1)'],
    'MariaDB below minimum' => [['db_version' => '10.11.0'], '[FAIL] database (mariadb 10.11.0)'],
    'MariaDB exact exclusive maximum' => [['db_version' => '12.0.0'], '[FAIL] database (mariadb 12.0.0)'],
    // MySQL is measured against ITS OWN range, never MariaDB's: 11.8.8 is
    // comfortably inside the MariaDB claim and inside no MySQL claim at all.
    'claimed MySQL engine below its own minimum' => [['db_engine' => 'mysql', 'db_version' => '8.3.9'], '[FAIL] database (mysql 8.3.9)'],
    'claimed MySQL engine at its own exclusive maximum' => [['db_engine' => 'mysql', 'db_version' => '8.5.0'], '[FAIL] database (mysql 8.5.0)'],
    'claimed MySQL engine carrying a MariaDB-shaped version' => [['db_engine' => 'mysql'], '[FAIL] database (mysql 11.8.8)'],
    // The engine-refusal cell is REPLACED, not deleted: with two claimed
    // engines it takes an engine the map does not name at all to reach
    // doctor's fail-closed engine path.
    'engine the claim does not name' => [['db_engine' => 'postgres'], '[FAIL] database (postgres 11.8.8)'],
    'WordPress below minimum' => [['wp' => '6.8.3'], '[FAIL] WordPress core (6.8.3)'],
    'WordPress exact exclusive maximum' => [['wp' => '7.2.0'], '[FAIL] WordPress core (7.2.0)'],
    // Inside [min, max) and still unexercised: 6.10 is a minor line the
    // claim's `verified` map does not name, so the range half alone would
    // wrongly pass it here while the agent gate refuses it.
    'WordPress unexercised minor line inside the range' => [['wp' => '6.10.0'], '[FAIL] WordPress core (6.10.0)'],
    // A pre-release core version_compare ranks inside the window: the agent
    // refuses it on the observed value's dotted shape
    // (PlatformCompatibility::inside_range()), so doctor must too or it would
    // label a core compatible that direct product commands refuse.
    'WordPress pre-release core inside an exercised series' => [['wp' => '7.0.4-alpha'], '[FAIL] WordPress core (7.0.4-alpha)'],
] as $label => [$override, $needle]) {
    $case = $compatibilityCase($override);
    assert_doctor_command($case['exit'] === 1, "$label is a blocking platform refusal");
    assert_doctor_command(str_contains($case['output'], $needle), "$label names the exact failing platform row");
    assert_doctor_command(
        $case['driver']->rawCalls === 2 && $case['driver']->wpCalls === 2,
        "$label uses the composed four-round-trip doctor path"
    );
}

// The certified v1 contract is single-site only, and until this row existed a
// network read as an all-green pre-adoption screen while
// docs/compatibility-baseline.json already claimed "the agent pre-policy gate
// and wprism doctor block outside these values". A FAIL, not an advisory.
$multisiteCase = $compatibilityCase(['site_mode' => 'multisite']);
assert_doctor_command($multisiteCase['exit'] === 1, 'a network is a blocking doctor failure');
assert_doctor_command(
    str_contains($multisiteCase['output'], '[FAIL] site topology (multisite)'),
    'the site-topology row names the observed topology in its label'
);
assert_doctor_command(
    str_contains($multisiteCase['output'], 'the certified v1 contract is single-site only; the agent pre-policy gate refuses every mutating command on a network'),
    'and states the blocking reason rather than an advisory hint'
);
assert_doctor_command(
    !str_contains($multisiteCase['output'], '[WARN] site topology'),
    'the row is never rendered as an advisory warning'
);
assert_doctor_command(
    str_contains($multisiteCase['output'], '[PASS] PHP version (8.3.33)')
        && str_contains($multisiteCase['output'], '[PASS] WordPress core (7.0.3)'),
    'and a network sinks only its own row: the compatibility rows still answer independently'
);

// The row is sourced from SITE_FACTS' own key, so a target that could not
// answer at all reports `unknown` and still fails — fail-closed, like every
// other blocking row here.
$unknownTopology = $compatibilityCase([]);
assert_doctor_command(
    str_contains($unknownTopology['output'], '[PASS] site topology (single-site)'),
    'a single-site target passes the topology row'
);

$darwinFilesystem = $compatibilityCase(['filesystem' => [
    'directory_separator' => '/',
    'os_family' => 'Darwin',
    'functions' => ['chmod' => true, 'flock' => true, 'fsync' => true, 'lstat' => true, 'rename' => true],
]]);
assert_doctor_command(
    $darwinFilesystem['exit'] === 0
        && str_contains($darwinFilesystem['output'], '[PASS] filesystem process profile (Darwin)'),
    'doctor accepts the measured Darwin local-POSIX profile'
);
$windowsFilesystem = $compatibilityCase(['filesystem' => [
    'directory_separator' => '\\',
    'os_family' => 'Windows',
    'functions' => ['chmod' => true, 'flock' => true, 'fsync' => true, 'lstat' => true, 'rename' => true],
]]);
assert_doctor_command(
    $windowsFilesystem['exit'] === 1
        && str_contains($windowsFilesystem['output'], '[FAIL] filesystem process profile (Windows)')
        && str_contains($windowsFilesystem['output'], 'requires OS Darwin, Linux'),
    'doctor blocks an unexercised filesystem OS/separator profile with the declared requirement'
);
$missingFsync = $compatibilityCase(['filesystem' => [
    'directory_separator' => '/',
    'os_family' => 'Linux',
    'functions' => ['chmod' => true, 'flock' => true, 'fsync' => false, 'lstat' => true, 'rename' => true],
]]);
assert_doctor_command(
    $missingFsync['exit'] === 1
        && str_contains($missingFsync['output'], '[FAIL] filesystem process profile (Linux)')
        && str_contains($missingFsync['output'], 'missing fsync'),
    'doctor blocks a process missing one durable-filesystem function and names it'
);
$healthyProcessFacts = healthy_doctor_process_facts();
foreach (array_keys($healthyProcessFacts['functions']) as $function) {
    $missingFacts = $healthyProcessFacts;
    $missingFacts['functions'][$function] = false;
    $missingProcessFunction = $compatibilityCase(['process' => $missingFacts]);
    assert_doctor_command(
        $missingProcessFunction['exit'] === 1
            && str_contains($missingProcessFunction['output'], '[FAIL] process group profile (Linux)')
            && str_contains($missingProcessFunction['output'], 'missing ' . $function),
        "doctor blocks a process missing $function and names the exact primitive"
    );
}
$differentShellFacts = $healthyProcessFacts;
$differentShellFacts['shell']['path'] = '/usr/bin/sh';
$differentShell = $compatibilityCase(['process' => $differentShellFacts]);
assert_doctor_command(
    $differentShell['exit'] === 1
        && str_contains($differentShell['output'], '[FAIL] process group profile (Linux)')
        && str_contains($differentShell['output'], 'observed shell "/usr/bin/sh"'),
    'doctor blocks a substitute shell even when that shell is executable'
);
$nonExecutableShellFacts = $healthyProcessFacts;
$nonExecutableShellFacts['shell']['executable'] = false;
$nonExecutableShell = $compatibilityCase(['process' => $nonExecutableShellFacts]);
assert_doctor_command(
    $nonExecutableShell['exit'] === 1
        && str_contains($nonExecutableShell['output'], '[FAIL] process group profile (Linux)')
        && str_contains($nonExecutableShell['output'], 'shell is not executable'),
    'doctor blocks the exact /bin/sh path when it is not executable'
);
$enabledOpcacheFacts = $healthyProcessFacts;
$enabledOpcacheFacts['wp_cli_opcache_enabled'] = true;
$enabledOpcache = $compatibilityCase(['process' => $enabledOpcacheFacts]);
assert_doctor_command(
    $enabledOpcache['exit'] === 1
        && str_contains($enabledOpcache['output'], '[FAIL] process group profile (Linux)')
        && str_contains($enabledOpcache['output'], 'opcache.enable_cli is enabled'),
    'doctor blocks WP-CLI OPcache before adoption can claim a generation-fenced process'
);
$windowsProcessFacts = $healthyProcessFacts;
$windowsProcessFacts['os_family'] = 'Windows';
$windowsProcess = $compatibilityCase(['process' => $windowsProcessFacts]);
assert_doctor_command(
    $windowsProcess['exit'] === 1
        && str_contains($windowsProcess['output'], '[FAIL] process group profile (Windows)')
        && str_contains($windowsProcess['output'], 'requires OS Darwin, Linux'),
    'doctor blocks an unexercised process-group OS and names the declared requirement'
);
$noTopologyDriver = new HealthyDoctorDriver();
$noTopologyDriver->factsResult = ['exit' => 0, 'stdout' => (string) json_encode([
    'agent' => 'wprism-ok',
    'file_mods' => 'wprism-set',
    'php' => '8.3.33',
    'db_version' => '11.8.8',
    'db_engine' => 'mariadb',
    'database_mutation' => healthy_doctor_database_mutation_facts(),
    'filesystem' => [
        'directory_separator' => '/',
        'os_family' => 'Linux',
        'functions' => ['chmod' => true, 'flock' => true, 'fsync' => true, 'lstat' => true, 'rename' => true],
    ],
    'process' => healthy_doctor_process_facts(),
    'wp' => '7.0.3',
    'site_mode' => null,
]) . "\n", 'stderr' => ''];
ob_start();
$noTopologyExit = DoctorCommand::run($noTopologyDriver);
$noTopologyOutput = (string) ob_get_clean();
assert_doctor_command($noTopologyExit === 1, 'a target-side throw that sank the topology fact is a blocking failure');
assert_doctor_command(
    str_contains($noTopologyOutput, '[FAIL] site topology (unknown)'),
    'and the unreadable answer is labelled unknown rather than guessed single-site'
);

// The FAIL detail names the whole matrix the operator has to move onto — the
// range AND the exercised series — mirroring the agent diagnostic's `required`
// label (>=6.9.0 <7.2.0 exercised 6.9, 7.0, 7.1). The sentence this replaced said
// 'A wider claim requires a real core-version matrix'; this claim is that
// matrix, so the sentence must not survive anywhere in doctor's output.
$unclaimedCore = $compatibilityCase(['wp' => '6.8.3']);
assert_doctor_command(str_contains(
    $unclaimedCore['output'],
    '[FAIL] WordPress core (6.8.3) — outside the exercised core matrix (>=6.9.0 <7.2.0, exercised series 6.9, 7.0, 7.1'
        . ' — docs/compatibility-baseline.json).'
), 'the WordPress FAIL detail names the declared range and every exercised series');
assert_doctor_command(
    !str_contains($unclaimedCore['output'], 'A wider claim requires a real core-version matrix'),
    'doctor no longer tells an operator the core matrix does not exist'
);

// The same standard for the two rows this claim reshaped. The PHP detail
// gained the exercised series for the same reason the WordPress one carries
// them — a bare range would name a window wider than what ran — and the
// engine detail names EVERY claimed engine, because with a per-engine map
// 'expected MariaDB' would be a false statement about the contract.
$unclaimedPhp = $compatibilityCase(['php' => '8.5.0']);
assert_doctor_command(str_contains(
    $unclaimedPhp['output'],
    '[FAIL] PHP version (8.5.0) — outside the exercised platform matrix (>=8.3.0 <8.5.0, exercised series 8.3, 8.4'
        . ' — docs/compatibility-baseline.json). Classification and apply behavior are only tested inside this matrix.'
), 'the PHP FAIL detail names the declared range and every exercised series');

$unclaimedEngine = $compatibilityCase(['db_engine' => 'postgres']);
assert_doctor_command(str_contains(
    $unclaimedEngine['output'],
    '[FAIL] database (postgres 11.8.8) — claimed engines are MariaDB, MySQL, found postgres — a different database'
        . ' engine is genuinely untested, not merely unpinned (docs/compatibility-baseline.json).'
), 'the database engine FAIL detail names every claimed engine, not one of them');
assert_doctor_command(
    !str_contains($unclaimedEngine['output'], 'expected MariaDB'),
    'and never restates the retired single-engine claim'
);

$outOfRangeMysql = $compatibilityCase(['db_engine' => 'mysql', 'db_version' => '11.8.8']);
assert_doctor_command(str_contains(
    $outOfRangeMysql['output'],
    '[FAIL] database (mysql 11.8.8) — outside the declared baseline for MySQL (>=8.4.0 <8.5.0'
        . ' — docs/compatibility-baseline.json).'
), 'a version refusal names the engine whose range it applied, because the range is now a function of the engine');

// A baseline whose wordpress axis cannot state the matrix must sink the whole
// compatibility block into "baseline file missing or malformed" — the widened
// read_baseline() guard's only job. Without it a missing min/max/verified
// evaluates to a version_compare against '' plus an empty series list, which
// is a silent answer about a core nothing exercised, on the host half of a
// boundary whose agent half refuses fail-closed
// (PlatformCompatibility::valid_wordpress_axis()). Proven through
// DoctorCommand::run against a copy of cli/, per the note at the top of this
// file; scratch lives under sandbox/tmp/ (AGENTS.md rule 3).
// One stable scratch path, cleared before use rather than made unique per
// run: an assertion failure below exits before the cleanup, and a per-pid name
// would leave a new copied tree behind on every red run.
$probeRoot = dirname(__DIR__, 3) . '/tmp/doctor-baseline-probe';
$repoRoot = dirname(__DIR__, 4);
$removeProbeRoot = static function () use ($probeRoot): void {
    exec('rm -rf ' . escapeshellarg($probeRoot));
};
$removeProbeRoot();
assert_doctor_command(mkdir($probeRoot . '/docs', 0o777, true), 'the baseline probe root is created under sandbox/tmp');
exec('cp -R ' . escapeshellarg($repoRoot . '/cli') . ' ' . escapeshellarg($probeRoot . '/cli'), $copyOut, $copyStatus);
assert_doctor_command($copyStatus === 0, 'the baseline probe root carries its own copy of cli/');

/** @return array{exit:int,output:string} */
$baselineProbe = static function (array $baseline) use ($probeRoot): array {
    file_put_contents($probeRoot . '/docs/compatibility-baseline.json', (string) json_encode($baseline));
    $lines = [];
    $status = 0;
    exec(
        'WPRISM_DOCTOR_BASELINE_PROBE_ROOT=' . escapeshellarg($probeRoot) . ' '
            . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>&1',
        $lines,
        $status
    );
    return ['exit' => $status, 'output' => implode("\n", $lines) . "\n"];
};

$shippedBaseline = json_decode((string) file_get_contents($repoRoot . '/docs/compatibility-baseline.json'), true);
assert_doctor_command(is_array($shippedBaseline), 'the shipped compatibility baseline decodes');
// The control leg: the same copied tree with the WHOLE baseline still passes,
// so the truncated leg below cannot pass merely because the copy is broken.
$intactProbe = $baselineProbe($shippedBaseline);
assert_doctor_command($intactProbe['exit'] === 0, 'the copied tree with an intact baseline still passes doctor: ' . $intactProbe['output']);
assert_doctor_command(
    str_contains($intactProbe['output'], '[PASS] WordPress core (7.0.3)'),
    'the copied tree answers the WordPress row from its own baseline copy'
);

$truncatedBaseline = $shippedBaseline;
unset(
    $truncatedBaseline['wordpress']['min'],
    $truncatedBaseline['wordpress']['max'],
    $truncatedBaseline['wordpress']['verified']
);
$truncatedProbe = $baselineProbe($truncatedBaseline);
assert_doctor_command($truncatedProbe['exit'] === 1, 'a baseline that cannot state the core matrix is a blocking doctor failure');
assert_doctor_command(
    str_contains(
        $truncatedProbe['output'],
        '[FAIL] compatibility baseline (docs/compatibility-baseline.json) — baseline file missing or malformed'
    ),
    'a wordpress axis without min/max/verified is malformed, not a silent pass: ' . $truncatedProbe['output']
);
assert_doctor_command(
    !str_contains($truncatedProbe['output'], 'WordPress core ('),
    'no core version is judged at all against a baseline that cannot state the matrix'
);
$truncatedFilesystem = $shippedBaseline;
unset($truncatedFilesystem['filesystem']['required_functions']);
$truncatedFilesystemProbe = $baselineProbe($truncatedFilesystem);
assert_doctor_command(
    $truncatedFilesystemProbe['exit'] === 1
        && str_contains(
            $truncatedFilesystemProbe['output'],
            '[FAIL] compatibility baseline (docs/compatibility-baseline.json) — baseline file missing or malformed'
        )
        && !str_contains($truncatedFilesystemProbe['output'], 'filesystem process profile ('),
    'a baseline missing the durable function roster is malformed before any healthy target is judged'
);
$truncatedProcess = $shippedBaseline;
unset($truncatedProcess['process']['required_functions']);
$truncatedProcessProbe = $baselineProbe($truncatedProcess);
assert_doctor_command(
    $truncatedProcessProbe['exit'] === 1
        && str_contains(
            $truncatedProcessProbe['output'],
            '[FAIL] compatibility baseline (docs/compatibility-baseline.json) — baseline file missing or malformed'
        )
        && !str_contains($truncatedProcessProbe['output'], 'process group profile ('),
    'a baseline missing the exact process-function roster is malformed before any healthy target is judged'
);
$truncatedForeignKeyCensus = $shippedBaseline;
unset($truncatedForeignKeyCensus['database']['foreign_key_census']);
$truncatedForeignKeyCensusProbe = $baselineProbe($truncatedForeignKeyCensus);
assert_doctor_command(
    $truncatedForeignKeyCensusProbe['exit'] === 1
        && str_contains(
            $truncatedForeignKeyCensusProbe['output'],
            '[FAIL] compatibility baseline (docs/compatibility-baseline.json) — baseline file missing or malformed'
        )
        && !str_contains($truncatedForeignKeyCensusProbe['output'], 'transactional database mutation ('),
    'a baseline missing the scoped FK-census profile is malformed before any target privilege is judged'
);
$missingShellBaseline = $shippedBaseline;
unset($missingShellBaseline['process']['shell']);
$missingShellProbe = $baselineProbe($missingShellBaseline);
assert_doctor_command(
    $missingShellProbe['exit'] === 1
        && str_contains(
            $missingShellProbe['output'],
            '[FAIL] compatibility baseline (docs/compatibility-baseline.json) — baseline file missing or malformed'
        )
        && !str_contains($missingShellProbe['output'], 'process group profile ('),
    'a baseline missing the exact child shell is malformed before any healthy target is judged'
);
$removeProbeRoot();

$missingAgent = new AdoptableDoctorDriver(false);
ob_start();
$missingAgentExit = DoctorCommand::run($missingAgent);
$missingAgentOutput = (string) ob_get_clean();
assert_doctor_command($missingAgentExit === 1, 'pre-adoption doctor remains a blocking failure');
assert_doctor_command(str_contains(
    $missingAgentOutput,
    "[FAIL] wprism agent present — agent class not found (wp eval returned 'wprism-missing'); next step: wprism adopt 'healthy-fixture'"
), 'pre-adoption doctor prints the exact host-side adopt command');

$unauthorizedLocalAgent = new AdoptableDoctorDriver(false, false);
ob_start();
$unauthorizedLocalExit = DoctorCommand::run($unauthorizedLocalAgent);
$unauthorizedLocalOutput = (string) ob_get_clean();
assert_doctor_command($unauthorizedLocalExit === 1, 'missing agent remains blocking when local adoption lacks authorization');
assert_doctor_command(
    str_contains($unauthorizedLocalOutput, "host-side wprism adopt is not ready for driver 'healthy-fixture'")
        && str_contains($unauthorizedLocalOutput, 'authorize bootstrap in the untracked machine-local overlay')
        && !str_contains($unauthorizedLocalOutput, 'next step: wprism adopt'),
    'adoption-capable doctor relays its target-free capability remediation instead of suggesting a blocked adopt'
);

$missingDockerAgent = new HealthyDoctorDriver(false);
ob_start();
$missingDockerAgentExit = DoctorCommand::run($missingDockerAgent);
$missingDockerAgentOutput = (string) ob_get_clean();
assert_doctor_command($missingDockerAgentExit === 1, 'missing agent remains blocking when host-side adoption is unavailable');
assert_doctor_command(
    str_contains($missingDockerAgentOutput, "host-side wprism adopt is unavailable for driver 'healthy-fixture'")
        && str_contains($missingDockerAgentOutput, "install or mount the WPrism agent through that environment's control plane")
        && !str_contains($missingDockerAgentOutput, 'next step: wprism adopt'),
    'non-adoptable doctor directs the operator to the environment control plane instead of an impossible adopt command'
);

// issue #3511: a per-field sentinel. The target's own try/catch caught a
// $wpdb->db_version() throw and left that ONE field null; the rows that never
// needed the database must still print their real answers. This is the
// assertion a naive composition fails — one try around the whole snippet, or
// a host-side read that demands every field before trusting any, turns a
// compatibility-row failure into "agent class not found" on a site whose
// agent is installed and fine.
$sunkDb = new HealthyDoctorDriver();
$sunkDb->factsResult = ['exit' => 0, 'stdout' => (string) json_encode([
    'agent' => 'wprism-ok',
    'file_mods' => 'wprism-unset',
    'php' => '8.3.33',
    'db_version' => null,
    'db_engine' => null,
    'database_mutation' => null,
    'filesystem' => [
        'directory_separator' => '/',
        'os_family' => 'Linux',
        'functions' => ['chmod' => true, 'flock' => true, 'fsync' => true, 'lstat' => true, 'rename' => true],
    ],
    'process' => healthy_doctor_process_facts(),
    'wp' => '7.0.3',
    'site_mode' => 'single-site',
]) . "\n", 'stderr' => ''];
ob_start();
$sunkDbExit = DoctorCommand::run($sunkDb);
$sunkDbOutput = (string) ob_get_clean();
assert_doctor_command($sunkDbExit === 1, 'an unreadable database fact remains a blocking doctor failure');
assert_doctor_command(str_contains($sunkDbOutput, '[PASS] wprism agent present'), 'a sunk database fact did not sink the agent-presence row');
assert_doctor_command(str_contains($sunkDbOutput, '[WARN] DISALLOW_FILE_MODS set'), 'a sunk database fact did not sink the DISALLOW_FILE_MODS row');
assert_doctor_command(!str_contains($sunkDbOutput, 'agent class not found'), 'a sunk database fact did not fabricate a missing agent');
assert_doctor_command(str_contains(
    $sunkDbOutput,
    '[FAIL] compatibility baseline (docs/compatibility-baseline.json) — could not read PHP/database/WordPress facts from the environment:'
), 'a sunk database fact fails the compatibility row it actually belongs to');
assert_doctor_command(
    str_contains($sunkDbOutput, '[PASS] process group profile (Linux)'),
    'a sunk database fact does not sink the independently readable process prerequisites'
);

// issue #3511: an undecodable payload is the one case where every row falls back
// to exactly what it printed when it owned its own eval — including this
// sentence, which has been doctor's answer to an unreadable environment since
// issue #3222, and including quoting back what the target really printed rather
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

// issue #3511: the isolation the whole composition rests on, proven by RUNNING
// the production snippet Doctor just sent (captured above) instead of
// trusting a fixture to imitate it — a fixture can only agree with a snippet
// that is already broken. $wpdb->db_version() throws here the way it does on
// a target whose database handle is gone; class_exists() and defined() cannot
// throw, so the agent and DISALLOW_FILE_MODS fields MUST survive it. One try
// around the whole snippet — the obvious naive composition — emits
// {"agent":null,…} and fails this block.
//
// A two-method duck type plus wpdb's public error slot, deliberately not
// sandbox/tests/lib's FakeWpdb:
// that class is a SQL interpreter for agent-side suites, ships no
// db_server_info() at all, and offers no seam for a throwing db_version() —
// the only two behaviours this block needs.
final class ThrownDbWpdb {
    public string $last_error = '';

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
class_alias(stdClass::class, 'WPrism\\Capture');
$GLOBALS['wpdb'] = new ThrownDbWpdb();
assert_doctor_command($healthy->factsSnippet !== '', 'the healthy run recorded the composed eval snippet');
ob_start();
eval($healthy->factsSnippet);
$payload = (string) ob_get_clean();
$facts = json_decode($payload, true);
assert_doctor_command(is_array($facts), "the composed snippet emitted a JSON object through a throwing \$wpdb (got '$payload')");
assert_doctor_command(($facts['agent'] ?? null) === 'wprism-ok', 'a throwing $wpdb sank the agent-presence field');
assert_doctor_command(($facts['file_mods'] ?? null) === 'wprism-unset', 'a throwing $wpdb sank the DISALLOW_FILE_MODS field');
assert_doctor_command(($facts['php'] ?? null) === PHP_VERSION, 'a throwing $wpdb sank the PHP version field');
assert_doctor_command(($facts['wp'] ?? null) === '7.0.3', 'a throwing $wpdb sank the WordPress version field');
assert_doctor_command(array_key_exists('db_version', $facts) && $facts['db_version'] === null, 'the thrown field did not leave its own null sentinel');
assert_doctor_command(($facts['db_engine'] ?? null) === 'mariadb', 'the sibling database field did not answer independently of the thrown one');
assert_doctor_command(
    array_key_exists('database_mutation', $facts) && $facts['database_mutation'] === null,
    'a failed scoped mutation probe leaves its own null sentinel without sinking sibling platform facts'
);
$expectedProcessFunctions = [];
foreach (['passthru', 'posix_kill', 'posix_setsid', 'proc_close', 'proc_get_status', 'proc_open', 'proc_terminate'] as $function) {
    $expectedProcessFunctions[$function] = function_exists($function);
}
assert_doctor_command(
    ($facts['process'] ?? null) === [
        'os_family' => PHP_OS_FAMILY,
        'functions' => $expectedProcessFunctions,
        'shell' => [
            'executable' => function_exists('is_executable') && @is_executable('/bin/sh'),
            'path' => '/bin/sh',
        ],
        'wp_cli_opcache_enabled' => false,
    ],
    'the production SITE_FACTS snippet emits process primitives, the exact shell, and the WP-CLI OPcache witness'
);
// The topology field is computed with function_exists() rather than a bare
// call: this snippet also runs under the isolated control bootstrap, where
// is_multisite() may not be defined yet. A bare call would be an Error, and
// Error is not \Throwable's only subtype the per-field catch sees -- but a
// snippet that fataled here would sink the whole payload, not one field.
assert_doctor_command(
    ($facts['site_mode'] ?? null) === 'single-site',
    'a throwing $wpdb sank the site-topology field, and an undefined is_multisite() is answered single-site rather than fataling'
);

echo "PASS: doctor command\n";

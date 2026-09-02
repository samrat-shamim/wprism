<?php
/**
 * Offline conformance for the PUBLISHED branch-environment provider protocol
 * and the `wprism env provider-check` harness that diagnoses one.
 *
 * WHAT WOULD DRIFT WITHOUT THIS SUITE
 * -----------------------------------
 * cli/src/Environment/EnvironmentProviderProtocol.php states the closed result
 * key sets AND a per-field type token; CommandEnvironmentProvider states the
 * same types a second time as private assertions
 * (EnvironmentLifecycle.php:478-535). docs/branch-environment-provider.md is
 * projected from the first. If the two ever disagree, the document tells a
 * customer to send a field the orchestrator will refuse — and the only place
 * that refusal surfaces is `wprism env materialize`, whose second provider action
 * FREEZES production (EnvironmentLifecycle.php:1013-1021). So property 1 below
 * is the load-bearing one: for every action it drops, retypes and extra-keys
 * every declared field and requires the LIVE validator to refuse each time.
 *
 * No docker, no pair, no WordPress: every provider here is a PHP script this
 * suite writes into its own mktemp directory.
 */
declare(strict_types=1);

// From offline/environment/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../../../cli/src/Command/EnvironmentProviderCheckCommand.php';
require_once __DIR__ . '/../../../../cli/src/Command/EnvironmentCommandOptions.php';

use WPrism\Orchestrator\CommandEnvironmentProvider;
use WPrism\Orchestrator\EnvironmentCommandOptions;
use WPrism\Orchestrator\EnvironmentProviderCapability;
use WPrism\Orchestrator\EnvironmentProviderCheckCommand;
use WPrism\Orchestrator\EnvironmentProviderProtocol;

$repoRoot = dirname(__DIR__, 4);
$tmp = sys_get_temp_dir() . '/wprism-env-provider-conformance-' . bin2hex(random_bytes(7));
if (!mkdir($tmp, 0700, true)) {
    fwrite(STDERR, "FAIL: could not create scratch directory\n");
    exit(1);
}

function pc_remove(string $path): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $name) if ($name !== '.' && $name !== '..') pc_remove($path . '/' . $name);
    @rmdir($path);
}

function pc_run(array $command, ?string $cwd = null): void {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, null, ['bypass_shell' => true]);
    if (!is_resource($process)) { fwrite(STDERR, "FAIL: could not start fixture command\n"); exit(1); }
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        fwrite(STDERR, 'FAIL: fixture command failed: ' . implode(' ', $command) . "\n$err\n");
        exit(1);
    }
}

/** A value the LIVE validator accepts for one declared type token. */
function pc_good(string $type): mixed {
    if (str_starts_with($type, EnvironmentProviderProtocol::TYPE_ENUM_PREFIX)) {
        return explode('|', substr($type, strlen(EnvironmentProviderProtocol::TYPE_ENUM_PREFIX)))[0];
    }
    return match ($type) {
        EnvironmentProviderProtocol::TYPE_IDENTIFIER => 'wprism-conformance-identity-0001',
        EnvironmentProviderProtocol::TYPE_SHA256 => str_repeat('ab', 32),
        EnvironmentProviderProtocol::TYPE_POSITIVE_INT => 3,
        EnvironmentProviderProtocol::TYPE_UTC_SECOND => '2030-01-02T03:04:05Z',
        EnvironmentProviderProtocol::TYPE_GIT_OID => str_repeat('a', 40),
        EnvironmentProviderProtocol::TYPE_GIT_REF => 'feature/provider-contract',
        EnvironmentProviderProtocol::TYPE_BASE_URL => 'https://branch.example.test',
        EnvironmentProviderProtocol::TYPE_TRUE => true,
        EnvironmentProviderProtocol::TYPE_CAPABILITY_LIST => EnvironmentProviderCapability::all(),
        default => throw new RuntimeException("no conformant sample for '$type'"),
    };
}

/** A JSON value of the WRONG shape for one declared type token. */
function pc_bad(string $type): mixed {
    if (str_starts_with($type, EnvironmentProviderProtocol::TYPE_ENUM_PREFIX)) {
        return 'wprism-conformance-not-an-enum-member';
    }
    return match ($type) {
        // 5 characters, so it fails the {8,256} identifier bound.
        EnvironmentProviderProtocol::TYPE_IDENTIFIER => 'short',
        EnvironmentProviderProtocol::TYPE_SHA256 => 'not-a-sha256-digest',
        EnvironmentProviderProtocol::TYPE_POSITIVE_INT => 0,
        EnvironmentProviderProtocol::TYPE_UTC_SECOND => '2030-01-02 03:04:05',
        EnvironmentProviderProtocol::TYPE_GIT_OID => 'zzzz',
        EnvironmentProviderProtocol::TYPE_GIT_REF => '',
        // userinfo AND a query: two independent reasons the URL rule refuses.
        EnvironmentProviderProtocol::TYPE_BASE_URL => 'https://user:secret@branch.example.test/?token=1',
        EnvironmentProviderProtocol::TYPE_TRUE => false,
        EnvironmentProviderProtocol::TYPE_CAPABILITY_LIST => 'not-a-list',
        default => throw new RuntimeException("no counterexample for '$type'"),
    };
}

/** @return array<string,mixed> a fully conformant result for one action */
function pc_result(string $action): array {
    $result = [];
    foreach (EnvironmentProviderProtocol::resultFields($action) as $field => $type) {
        $result[$field] = pc_good($type);
    }
    return $result;
}

try {
    /* ---------------------------------------------------------------- *
     * Property 0 — the table IS the vocabulary.
     * ---------------------------------------------------------------- */
    $actions = EnvironmentProviderProtocol::actions();
    wprism_check_same([
        'capabilities', 'inspect', 'attach', 'create', 'snapshot-prepare', 'snapshot-create', 'snapshot-abort',
        'snapshot-read', 'snapshot-restore', 'repository-materialize', 'url-set',
        'mutation-acquire', 'mutation-read', 'mutation-release', 'containment-verify', 'ttl-set', 'ttl-read',
        'destroy', 'detach',
    ], $actions, 'the protocol table declares exactly the 19 orchestrator actions');

    $vocabulary = EnvironmentProviderCapability::all();
    $gated = [];
    foreach ($actions as $action) {
        $capability = EnvironmentProviderProtocol::capabilityFor($action);
        if ($action === 'capabilities') {
            wprism_check($capability === null, 'capability negotiation is itself ungated');
            continue;
        }
        wprism_check(
            is_string($capability) && in_array($capability, $vocabulary, true),
            "action '$action' is gated by a capability id in EnvironmentProviderCapability::all()"
        );
        $gated[] = $capability;
    }
    foreach (EnvironmentProviderProtocol::requirementSets() as $set) {
        $unknown = array_diff(array_merge($set['capabilities'], $set['conditional']), $vocabulary);
        wprism_check($unknown === [], "requirement set '{$set['id']}' names only known capability ids");
        wprism_check($set['operation'] !== '', "requirement set '{$set['id']}' carries the operation phrase require() prints");
    }

    /* ---------------------------------------------------------------- *
     * Property 1 — table <-> live validator agreement, all 19 actions.
     *
     * The provider below echoes whatever result the spec file names, so the
     * suite can post an arbitrary response INTO the real client and let
     * CommandEnvironmentProvider be the judge.
     * ---------------------------------------------------------------- */
    $echoScript = $tmp . '/echo-provider.php';
    file_put_contents($echoScript, <<<'PHP'
<?php
declare(strict_types=1);
$request = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$spec = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
function pc_canon(mixed $value): string {
    if (is_array($value)) {
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = json_decode(pc_canon($item), true);
    }
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
$action = (string) $request['action'];
$result = $spec['results'][$action] ?? ['capabilities' => []];
echo pc_canon([
    'action' => $action,
    'environment' => $request['environment'],
    'format' => 'wprism-branch-environment-provider-response/v1',
    'operation_id' => $request['operation_id'],
    'provider' => ['id' => $spec['provider_id'], 'protocol' => 1],
    'result' => $result,
    'status' => 'ok',
]) . "\n";
PHP);
    $specPath = $tmp . '/echo-spec.json';
    $writeSpec = static function (array $results) use ($specPath): void {
        file_put_contents($specPath, json_encode(
            ['provider_id' => 'conformance-provider', 'results' => $results],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    };
    $echoConfig = [
        '_machine_local' => true,
        'environment_provider' => [
            'command' => [PHP_BINARY, $echoScript, $specPath],
            'timeout_seconds' => 10,
        ],
    ];
    $operationId = 'wprism-conformance-operation-0001';
    $openEcho = static function () use ($echoConfig, $operationId): CommandEnvironmentProvider {
        $provider = CommandEnvironmentProvider::fromEnvironment('branch', $echoConfig);
        $provider->capabilities($operationId);
        return $provider;
    };

    foreach ($actions as $action) {
        $good = pc_result($action);
        $writeSpec(['capabilities' => pc_result('capabilities'), $action => $good]);
        if ($action === 'capabilities') {
            // `capabilities` is validated by capabilities(), not perform():
            // validateActionResult() only closes its key set (:359-362).
            $report = CommandEnvironmentProvider::fromEnvironment('branch', $echoConfig)->capabilities($operationId);
            wprism_check_same($vocabulary, $report->toArray()['capabilities'], 'the conformant capabilities result negotiates');
        } else {
            $result = $openEcho()->perform($action, $operationId, []);
            wprism_check(
                ($result['_response_sha256'] ?? '') !== '',
                "the table's conformant '$action' result passes the live validator"
            );
        }

        foreach (EnvironmentProviderProtocol::resultFields($action) as $field => $type) {
            $missing = $good;
            unset($missing[$field]);
            $writeSpec(['capabilities' => pc_result('capabilities'), $action => $missing]);
            wprism_check_throws(
                static fn(): mixed => $action === 'capabilities'
                    ? CommandEnvironmentProvider::fromEnvironment('branch', $echoConfig)->capabilities($operationId)
                    : $openEcho()->perform($action, $operationId, []),
                RuntimeException::class,
                "'$action' without `$field` is refused by the live validator"
            );
            $finding = EnvironmentProviderProtocol::diagnose($action, $missing);
            wprism_check(
                $finding !== null && $finding['field'] === $field && $finding['observed'] === 'absent',
                "the harness names `$field` as the absent field of '$action'"
            );

            $wrong = $good;
            $wrong[$field] = pc_bad($type);
            $writeSpec(['capabilities' => pc_result('capabilities'), $action => $wrong]);
            wprism_check_throws(
                static fn(): mixed => $action === 'capabilities'
                    ? CommandEnvironmentProvider::fromEnvironment('branch', $echoConfig)->capabilities($operationId)
                    : $openEcho()->perform($action, $operationId, []),
                RuntimeException::class,
                "'$action' with a wrongly typed `$field` is refused by the live validator"
            );
            $finding = EnvironmentProviderProtocol::diagnose($action, $wrong);
            wprism_check(
                $finding !== null && $finding['field'] === $field,
                "the harness names `$field` as the wrongly typed field of '$action'"
            );
        }

        $extra = $good + ['wprism_unexpected_field' => 'x'];
        $writeSpec(['capabilities' => pc_result('capabilities'), $action => $extra]);
        wprism_check_throws(
            static fn(): mixed => $action === 'capabilities'
                ? CommandEnvironmentProvider::fromEnvironment('branch', $echoConfig)->capabilities($operationId)
                : $openEcho()->perform($action, $operationId, []),
            RuntimeException::class,
            "'$action' with one extra key is refused: the result key set is closed"
        );
        $finding = EnvironmentProviderProtocol::diagnose($action, $extra);
        wprism_check(
            $finding !== null && $finding['field'] === 'wprism_unexpected_field',
            "the harness names the unknown key of '$action'"
        );
    }

    /* ---------------------------------------------------------------- *
     * Property 3 — the four machine-local config refusals, byte for byte.
     * These are the strings this stream deliberately does NOT move, and the
     * generated document quotes them verbatim.
     * ---------------------------------------------------------------- */
    $configCases = [
        [[], "env 'branch': branch materialization requires machine-local environment_provider configuration"],
        [
            ['environment_provider' => ['command' => ['/bin/true'], 'timeout_seconds' => 5]],
            "env 'branch': environment_provider is privileged host configuration and is allowed only in .wprism-envs.json",
        ],
        [
            ['_machine_local' => true, 'environment_provider' => ['command' => ['relative/provider'], 'timeout_seconds' => 5]],
            "env 'branch': environment_provider executable must be absolute",
        ],
        [
            ['_machine_local' => true, 'environment_provider' => ['command' => ['/bin/true'], 'timeout_seconds' => 0]],
            "env 'branch': environment_provider.timeout_seconds must be 1..3600",
        ],
        [
            ['_machine_local' => true, 'environment_provider' => ['command' => ['/bin/true'], 'timeout_seconds' => 3601]],
            "env 'branch': environment_provider.timeout_seconds must be 1..3600",
        ],
        [
            ['_machine_local' => true, 'environment_provider' => ['command' => ['/bin/true'], 'timeout_seconds' => 5, 'shell' => true]],
            "env 'branch': environment_provider has missing or unknown fields",
        ],
    ];
    foreach ($configCases as [$config, $expected]) {
        wprism_check_throws(
            static fn(): CommandEnvironmentProvider => CommandEnvironmentProvider::fromEnvironment('branch', $config),
            RuntimeException::class,
            'machine-local config refusal is unchanged: ' . $expected,
            $expected
        );
    }
    $document = (string) file_get_contents($repoRoot . '/docs/branch-environment-provider.md');
    foreach ($configCases as [, $expected]) {
        wprism_check(
            str_contains($document, str_replace("env 'branch'", "env '<env>'", $expected)),
            'the published document quotes the refusal verbatim: ' . $expected
        );
    }

    /* ---------------------------------------------------------------- *
     * Property 4 — the option grammar, including the mandatory
     * --confirm-disposable gate on the mutating tier.
     * ---------------------------------------------------------------- */
    wprism_check_same(
        ['from' => null, 'cycle' => false, 'confirm' => false, 'create' => false, 'role' => 'target', 'branch' => null, 'json' => false],
        EnvironmentCommandOptions::providerCheck([]),
        'provider-check defaults to the non-mutating target-side tier'
    );
    wprism_check_same(
        ['from' => 'prod', 'cycle' => true, 'confirm' => true, 'create' => true, 'role' => 'target', 'branch' => 'feature/x', 'json' => true],
        EnvironmentCommandOptions::providerCheck(['--cycle', '--from=prod', '--confirm-disposable', '--create', '--branch', 'feature/x', '--format=json']),
        'the mutating tier parses to its exact typed options'
    );
    wprism_check_throws(
        static fn(): array => EnvironmentCommandOptions::providerCheck(['--cycle', '--from=prod']),
        RuntimeException::class,
        '--cycle without --confirm-disposable refuses: snapshot-prepare freezes the named source',
        'provider-check --cycle requires --from <disposable-source-env> and --confirm-disposable'
    );
    wprism_check_throws(
        static fn(): array => EnvironmentCommandOptions::providerCheck(['--cycle', '--confirm-disposable']),
        RuntimeException::class,
        '--cycle without --from refuses',
        'provider-check --cycle requires --from <disposable-source-env> and --confirm-disposable'
    );
    wprism_check_throws(
        static fn(): array => EnvironmentCommandOptions::providerCheck(['--from=prod', '--confirm-disposable']),
        RuntimeException::class,
        'a disposable source without --cycle refuses rather than being silently ignored',
        'only used with --cycle'
    );
    wprism_check_throws(
        static fn(): array => EnvironmentCommandOptions::providerCheck(['--role=neither']),
        RuntimeException::class,
        '--role is closed to source|target',
        '--role must be source or target'
    );

    /* ---------------------------------------------------------------- *
     * Property 2 — the harness's negotiation verdict, through the real
     * command boundary and the real registry.
     * ---------------------------------------------------------------- */
    $work = $tmp . '/work';
    mkdir($work . '/repo', 0700, true);
    pc_run(['git', 'init', '-q', '-b', 'feature'], $work);
    pc_run(['git', 'config', 'user.email', 'test@example.invalid'], $work);
    pc_run(['git', 'config', 'user.name', 'Provider Check Test'], $work);
    file_put_contents($work . '/tracked.txt', "branch\n");
    pc_run(['git', 'add', 'tracked.txt'], $work);
    pc_run(['git', 'commit', '-q', '-m', 'feature'], $work);

    $harnessSpec = $work . '/harness-spec.json';
    $writeHarnessSpec = static function (array $capabilities) use ($harnessSpec, $actions): void {
        $results = [];
        foreach ($actions as $action) {
            $results[$action] = pc_result($action);
        }
        $results['capabilities'] = ['capabilities' => $capabilities];
        file_put_contents($harnessSpec, json_encode(
            ['provider_id' => 'harness-provider', 'results' => $results],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    };
    $writeEnvs = static function (array $providerCommand) use ($work): void {
        file_put_contents($work . '/.wprism-envs.json', json_encode([
            'envs' => [
                'branch' => [
                    'transport' => 'local',
                    'repo_path' => $work . '/repo',
                    'wp_path' => $work . '/repo',
                    'environment_provider' => ['command' => $providerCommand, 'timeout_seconds' => 10],
                ],
                'prod' => [
                    'transport' => 'local',
                    'repo_path' => $work . '/repo',
                    'wp_path' => $work . '/repo',
                    'environment_provider' => ['command' => $providerCommand, 'timeout_seconds' => 10],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    };
    $writeEnvs([PHP_BINARY, $echoScript, $harnessSpec]);

    $previousCwd = getcwd() ?: '.';
    $runHarness = static function (string $env, array $options) use ($work, $previousCwd): array {
        chdir($work);
        ob_start();
        try {
            $exit = EnvironmentProviderCheckCommand::run($env, $options + [
                'from' => null, 'cycle' => false, 'confirm' => false, 'create' => false,
                'role' => 'target', 'branch' => null, 'json' => true,
            ], null);
        } finally {
            $stdout = (string) ob_get_clean();
            chdir($previousCwd);
        }
        return ['exit' => $exit, 'stdout' => $stdout, 'body' => json_decode($stdout, true)];
    };

    $writeHarnessSpec($vocabulary);
    $ready = $runHarness('branch', []);
    wprism_check($ready['exit'] === 0, 'a fully capable provider negotiates READY with exit 0');
    wprism_check(($ready['body']['verdict'] ?? '') === 'READY', 'the READY verdict is in the machine envelope');
    wprism_check(
        ($ready['body']['format'] ?? '') === EnvironmentProviderCheckCommand::FORMAT
            && ($ready['body']['tier'] ?? '') === 'negotiate',
        'the negotiate envelope names its own format and tier'
    );
    wprism_check(
        is_string($ready['body']['pin']['capabilities_sha256'] ?? null)
            && str_starts_with((string) $ready['body']['pin']['capabilities_sha256'], 'sha256:'),
        'the harness reports the capability digest a resumed operation would be pinned to'
    );
    $performed = array_filter(
        $ready['body']['checks'] ?? [],
        static fn(array $check): bool => $check['check'] === 'action inspect'
    );
    wprism_check($performed !== [], 'the non-mutating tier exercises exactly one real action: inspect');
    wprism_check(
        !str_contains($ready['stdout'], 'snapshot-prepare'),
        'the non-mutating tier never prepares a snapshot, so it never freezes a source'
    );

    // The stub provider the materializer suite already uses for its
    // `attach-only` host: no environment.create, no environment.destroy.
    $writeHarnessSpec(array_values(array_diff($vocabulary, ['environment.create', 'environment.destroy'])));
    $attachOnly = $runHarness('branch', []);
    wprism_check($attachOnly['exit'] === 1, 'an attach-only provider is BLOCKED with exit 1');
    $blocked = [];
    foreach ($attachOnly['body']['checks'] ?? [] as $check) {
        if ($check['state'] !== 'pass') {
            $blocked[$check['check']] = $check['detail'];
        }
    }
    wprism_check(
        ($blocked['capability set materialize-target-create'] ?? '')
            === "environment provider 'harness-provider' cannot materialize a create branch environment;"
                . ' missing environment.create, environment.destroy',
        'the missing ids are named exactly as EnvironmentProviderCapabilityReport::require() names them'
    );
    wprism_check(
        ($attachOnly['body']['profiles']['materialize-target-attach'] ?? null) === true,
        'the attach requirement set is still reported complete, so the operator sees WHICH mode is available'
    );

    $writeHarnessSpec(array_merge($vocabulary, ['environment.teleport']));
    $unknown = $runHarness('branch', []);
    wprism_check($unknown['exit'] === 1, 'a provider advertising an unknown capability id is BLOCKED');
    wprism_check(
        str_contains($unknown['stdout'], "declared unknown capability 'environment.teleport'"),
        'the unknown capability id is named back to the operator'
    );

    $writeEnvs(['relative/provider']);
    $badConfig = $runHarness('branch', []);
    wprism_check($badConfig['exit'] === 1, 'a misconfigured provider is diagnosed rather than fataling');
    wprism_check(
        str_contains($badConfig['stdout'], 'environment_provider executable must be absolute'),
        'the config refusal reaches the operator through the harness unchanged'
    );

    /* ---------------------------------------------------------------- *
     * Property 4b — the provider-check cycle: all non-rehearsal actions, both
     * ownership modes, snapshot aborted and target reaped in a finally.
     * ---------------------------------------------------------------- */
    $cycleScript = $work . '/cycle-provider.php';
    file_put_contents($cycleScript, <<<'PHP'
<?php
// Stateful conformant provider, derived from the direct-argv stub at
// sandbox/tests/offline/environment/regress_environment_materializer.php:156-221.
// `wrong` corrupts exactly one <action>.<field> so the harness's typed finding
// can be asserted against a known answer.
declare(strict_types=1);
// '-' is the no-corruption sentinel: the argv contract forbids an empty element.
$wrong = ($argv[1] ?? '-') === '-' ? '' : (string) $argv[1];
$request = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
function pc_canon(mixed $value): string {
    if (is_array($value)) {
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = json_decode(pc_canon($item), true);
    }
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
$h = static fn(string $v): string => hash('sha256', $v);
$action = (string) $request['action'];
$input = $request['input'] ?? [];
$identity = [
    'environment_identity' => 'environment-identity-0001',
    'lease_generation' => 3,
    'lease_id' => 'lease-identity-0001',
    'ownership_receipt_sha256' => $h('owner'),
    'resource_id' => 'resource-identity-0001',
    'url' => 'https://branch.example.test',
];
$owner = (string) ($input['mutation_owner'] ?? $input['expected_mutation_owner'] ?? 'wprism-provider-check-owner-0001');
// Per-operation fence exclusivity, the property BOTH real providers share:
// tools/reference-env-provider.php keys each fence to
// resource|generation|operation and refuses a second owner under the same
// operation id ('mutation acquire is not idempotent/exclusive'), and the
// proven environment-materializer fixture does the same keyed on environment|operation. The
// first live run of regress_env_provider_conformance_live.sh blocked on
// exactly this: the harness re-used the materialize operation id for its
// terminal reap acquire, which only a stateless stub would grant. Persisting
// the owner per operation id here makes THIS suite fail against that harness
// defect instead of leaving it to a live pair to find.
if ($action === 'mutation-acquire') {
    $fencesPath = __DIR__ . '/cycle-provider-fences.json';
    $fences = is_file($fencesPath)
        ? (array) json_decode((string) file_get_contents($fencesPath), true)
        : [];
    $operationId = (string) $request['operation_id'];
    if (isset($fences[$operationId]) && $fences[$operationId] !== $owner) {
        fwrite(STDERR, 'cycle provider: mutation acquire is not idempotent/exclusive' . "\n");
        exit(1);
    }
    $fences[$operationId] = $owner;
    file_put_contents($fencesPath, json_encode($fences));
}
$released = $action === 'mutation-release';
$fence = $identity + [
    'mutation_generation' => 1,
    'mutation_id' => 'mutation-fence-' . substr(hash('sha256', $owner), 0, 12),
    'mutation_owner' => $owner,
    'mutation_receipt_sha256' => $h($released ? 'released:' . $owner : 'held:' . $owner),
    'state' => $released ? 'released' : 'held',
];
$ttl = $identity + [
    'expires_at' => '2030-01-02T03:04:05Z', 'ttl_generation' => 1,
    'ttl_lease_id' => 'ttl-lease-identity-0001', 'ttl_receipt_sha256' => $h('ttl'), 'ttl_state' => 'active',
];
$session = [
    'lease_generation' => 1, 'lease_id' => 'snapshot-lease-0001', 'lease_receipt_sha256' => $h('snapshot-lease'),
    'snapshot_session_id' => (string) ($input['snapshot_session_id'] ?? $input['expected_snapshot_session_id'] ?? 'snapshot-session-0001'),
    'source_identity' => 'environment-identity-0001',
];
$snapshotSet = $session + [
    'database_sha256' => $h('db'), 'media_sha256' => $h('media'), 'retention_receipt_sha256' => $h('retention'),
    'semantic_snapshot_sha256' => (string) ($input['expected_semantic_snapshot_sha256'] ?? $h('semantic')),
    'snapshot_set_id' => 'snapshot-set-0001', 'snapshot_set_receipt_sha256' => $h('snapshot-set'),
];
$result = match ($action) {
    'capabilities' => ['capabilities' => [
        'environment.attach', 'environment.create', 'environment.destroy', 'environment.detach',
        'environment.inspect', 'environment.mutation.acquire', 'environment.mutation.read',
        'environment.mutation.release', 'environment.ttl', 'environment.ttl.read',
        'environment.url.discover', 'environment.url.set', 'operation.receipts', 'repository.materialize',
        'snapshot.set.abort', 'snapshot.set.create', 'snapshot.set.prepare', 'snapshot.set.read',
        'snapshot.set.restore',
    ]],
    'inspect', 'attach', 'create' => $identity + ['presence' => 'present'],
    'snapshot-prepare' => $session,
    'snapshot-create' => $snapshotSet,
    'snapshot-read' => $snapshotSet + ['immutable' => true],
    'snapshot-abort' => $session + ['disposition' => 'aborted'],
    'snapshot-restore' => $identity + ['snapshot_set_id' => (string) $input['snapshot_set_id']],
    'repository-materialize' => $identity + [
        'branch_commit' => (string) $input['branch_commit'], 'repository_receipt_sha256' => $h('repo'),
        'target_branch' => (string) $input['target_branch'],
    ],
    'url-set' => $identity,
    'mutation-acquire', 'mutation-read', 'mutation-release' => $fence,
    'ttl-set', 'ttl-read' => $ttl,
    'destroy', 'detach' => [
        'absence_proof_sha256' => $h('absence'),
        'disposition' => $action === 'destroy' ? 'destroyed' : 'detached',
        'environment_identity' => $identity['environment_identity'], 'lease_generation' => $identity['lease_generation'],
        'lease_id' => $identity['lease_id'], 'ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
        'resource_id' => $identity['resource_id'],
    ],
    default => [],
};
if ($wrong !== '' && str_starts_with($wrong, $action . '.')) {
    $field = substr($wrong, strlen($action) + 1);
    $result[$field] = $field === 'target_branch' ? '' : 'x';
}
echo pc_canon([
    'action' => $action, 'environment' => $request['environment'],
    'format' => 'wprism-branch-environment-provider-response/v1', 'operation_id' => $request['operation_id'],
    'provider' => ['id' => 'cycle-provider', 'protocol' => 1], 'result' => $result, 'status' => 'ok',
]) . "\n";
PHP);

    foreach ([['attach', false], ['create', true]] as [$mode, $create]) {
        $writeEnvs([PHP_BINARY, $cycleScript, '-']);
        $cycle = $runHarness('branch', ['cycle' => true, 'confirm' => true, 'from' => 'prod', 'create' => $create]);
        $names = $cycle['body']['actions'] ?? [];
        wprism_check(
            $cycle['exit'] === 0 && ($cycle['body']['verdict'] ?? '') === 'READY',
            "the synthetic $mode cycle completes READY against a conformant provider"
        );
        wprism_check(
            in_array('action ' . $mode, $names, true)
                && in_array('teardown action snapshot-abort', $names, true),
            "the $mode cycle acquires with `$mode` and ABORTS its snapshot rather than retaining it"
        );
        $reap = $mode === 'create' ? 'destroy' : 'detach';
        wprism_check(
            in_array('action ' . $reap, $names, true),
            "the $mode cycle reaps its own target with `$reap`, leaving nothing behind"
        );
    }

    // Provider-check deliberately cannot claim the host-specific containment
    // profile; the real rehearsal materializer exercises that final action.
    $writeEnvs([PHP_BINARY, $cycleScript, '-']);
    $attachCycle = $runHarness('branch', ['cycle' => true, 'confirm' => true, 'from' => 'prod']);
    $createCycle = $runHarness('branch', ['cycle' => true, 'confirm' => true, 'from' => 'prod', 'create' => true]);
    $exercised = [];
    foreach (array_merge($attachCycle['body']['actions'] ?? [], $createCycle['body']['actions'] ?? []) as $name) {
        $exercised[preg_replace('/^(?:teardown )?action /', '', $name)] = true;
    }
    $exercised['capabilities'] = true; // negotiated on both sides, reported as its own check
    wprism_check_same(
        ['containment-verify'],
        array_values(array_diff($actions, array_keys($exercised))),
        'the two provider-check cycles drive every action except the rehearsal-only containment proof'
    );

    // One field deliberately wrong per run: the orchestrator refuses, and the
    // harness names the field the orchestrator's own message may not.
    foreach ([
        ['repository-materialize', 'branch_commit'],
        ['repository-materialize', 'target_branch'],
        ['ttl-set', 'expires_at'],
        ['snapshot-create', 'database_sha256'],
        ['mutation-acquire', 'mutation_generation'],
    ] as [$action, $field]) {
        $writeEnvs([PHP_BINARY, $cycleScript, "$action.$field"]);
        $broken = $runHarness('branch', ['cycle' => true, 'confirm' => true, 'from' => 'prod']);
        wprism_check($broken['exit'] === 1, "a wrong `$field` in '$action' fails the cycle");
        $named = false;
        foreach ($broken['body']['findings'] ?? [] as $finding) {
            if ($finding['action'] === $action && $finding['field'] === $field) {
                $named = true;
            }
        }
        wprism_check($named, "the typed finding names $action.$field, which the orchestrator's own refusal does not");
        wprism_check(
            str_contains($broken['stdout'], 'has missing or unknown fields')
                || str_contains($broken['stdout'], 'environment provider'),
            "the orchestrator's own refusal for $action is reported unchanged beside the finding"
        );
    }

    /* ---------------------------------------------------------------- *
     * Property 5 — the published document cannot drift from the code,
     * offline, not only at `make release-gate`.
     * ---------------------------------------------------------------- */
    $checker = proc_open(
        [PHP_BINARY, $repoRoot . '/tools/provider-protocol-doc.php', '--check'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $repoRoot,
        null,
        ['bypass_shell' => true]
    );
    $docCheck = 1;
    if (is_resource($checker)) {
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $docCheck = proc_close($checker);
    }
    wprism_check($docCheck === 0, 'docs/branch-environment-provider.md agrees with the code that enforces it');
} finally {
    pc_remove($tmp);
}

wprism_check_summary('branch-environment provider protocol and conformance harness');

<?php
/**
 * Offline checks that tools/reference-env-provider.php really is a provider
 * `\Duo\Orchestrator\CommandEnvironmentProvider` will talk to (round-3 MUP
 * §2.2, last bullet).
 *
 * The `capabilities` action is the one action that needs no pair, no docker
 * and no state — it answers from the config alone — so the whole capability
 * negotiation half of the provider contract is exercisable on a machine with
 * no docker at all. That is what this file does: it drives the REAL reference
 * provider through the REAL orchestrator client, which re-encodes the
 * provider's response canonically and byte-compares it, closes the response's
 * key set, and pins the protocol version. A provider whose bytes drifted from
 * the duo3324 fixture's shape fails here rather than on a live pair.
 *
 * The never-emulation property is checked the same way: with a capability
 * withheld, an action that needs it must refuse BEFORE reaching docker, and
 * must name the id it is missing. The orchestrator redacts provider output on
 * every failure by design, so the named id is read out of the provider's own
 * `provider-errors.log` beside its state root — the operator-visible half of
 * the same refusal.
 *
 * usage: php provider-negotiation-checks.php <scratch-dir>
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/check.php';
require_once dirname(__DIR__, 4) . '/cli/src/Environment/EnvironmentLifecycle.php';

use Duo\Orchestrator\CommandEnvironmentProvider;
use Duo\Orchestrator\EnvironmentProviderCapability;

$scratch = $argv[1] ?? '';
if ($scratch === '') {
    fwrite(STDERR, "usage: provider-negotiation-checks.php <scratch-dir>\n");
    exit(2);
}
$root = dirname(__DIR__, 4);
$providerScript = $root . '/tools/reference-env-provider.php';
$makeConfig = __DIR__ . '/make-provider-config.php';

/** @return array{config:string,state:string} */
function rn_config(string $scratch, string $makeConfig, string $suffix, string $withheld = ''): array {
    $config = $scratch . '/provider-' . $suffix . '.json';
    $command = [PHP_BINARY, $makeConfig, $config];
    if ($withheld !== '') {
        $command[] = $withheld;
    }
    $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        fwrite(STDERR, "FAIL: could not build the provider config\n");
        exit(1);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $decoded = json_decode((string) file_get_contents($config), true, 512, JSON_THROW_ON_ERROR);
    // Redirect the state root into this run's scratch: the committed config
    // points at sandbox/tmp, and a suite must never share provider state with
    // a developer's live pair.
    $decoded['state_root'] = $scratch . '/state-' . $suffix;
    if (!is_dir($decoded['state_root']) && !mkdir($decoded['state_root'], 0700, true)) {
        fwrite(STDERR, "FAIL: could not create the provider state root\n");
        exit(1);
    }
    file_put_contents($config, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

    return ['config' => $config, 'state' => $decoded['state_root']];
}

/** @return array<string,mixed> */
function rn_environment_config(string $providerScript, string $config): array {
    return [
        '_machine_local' => true,
        'environment_provider' => [
            'command' => [PHP_BINARY, $providerScript, $config],
            'timeout_seconds' => 20,
        ],
    ];
}

$operationId = '20260817-090000-0000000000000000abcdefab';

// --------------------------------------------------- the full advertised set
$full = rn_config($scratch, $makeConfig, 'full');
$provider = CommandEnvironmentProvider::fromEnvironment('mup2', rn_environment_config($providerScript, $full['config']));
$report = $provider->capabilities($operationId);

duo_check_same(
    EnvironmentProviderCapability::all(),
    $report->toArray()['capabilities'],
    'the reference provider advertises exactly the protocol\'s capability set, so a rehearsal never '
        . 'fails negotiation for a capability the pair could actually serve'
);
duo_check_same(
    'duo-reference-env-provider',
    $report->providerId(),
    'the provider identity is the one the orchestrator pins operations against'
);
duo_check_same(
    ['capabilities_sha256', 'environment', 'provider'],
    array_keys($report->pin()),
    'the negotiated pin is the orchestrator\'s own closed shape'
);
duo_check_same(
    1,
    $report->pin()['provider']['protocol'],
    'the provider speaks protocol 1, the version CommandEnvironmentProvider accepts'
);
duo_check_same(
    'duo-branch-environment-capabilities/v1',
    $report->toArray()['format'],
    'the capability report is the shipped evidence format'
);

// The response survived CommandEnvironmentProvider::call()'s canonical
// re-encode and byte-compare; reaching this line at all IS that proof, so
// state it rather than leaving it implicit.
duo_check(
    true,
    'the provider response is canonical, request-bound, protocol-1 evidence: the orchestrator '
        . 're-encodes and byte-compares every response before accepting it'
);

// The exact requirement set a branch materialization negotiates.
$report->require([
    EnvironmentProviderCapability::SNAPSHOT_SET_PREPARE,
    EnvironmentProviderCapability::SNAPSHOT_SET_CREATE,
    EnvironmentProviderCapability::SNAPSHOT_SET_READ,
    EnvironmentProviderCapability::SNAPSHOT_SET_ABORT,
    EnvironmentProviderCapability::SNAPSHOT_SET_RESTORE,
    EnvironmentProviderCapability::ENVIRONMENT_ATTACH,
    EnvironmentProviderCapability::ENVIRONMENT_CREATE,
    EnvironmentProviderCapability::REPOSITORY_MATERIALIZE,
    EnvironmentProviderCapability::URL_DISCOVER,
    EnvironmentProviderCapability::OPERATION_RECEIPTS,
], 'rehearse');
duo_check(true, 'the provider satisfies MUP §2.2\'s named requirement set: snapshot.set.*, '
    . 'environment.attach|create, repository.materialize, environment.url.discover, operation.receipts');

// -------------------------------------------------------- a withheld subset
$subset = rn_config($scratch, $makeConfig, 'subset', 'repository.materialize,environment.create');
$partial = CommandEnvironmentProvider::fromEnvironment('mup2', rn_environment_config($providerScript, $subset['config']));
$partialReport = $partial->capabilities($operationId);

duo_check(
    !in_array(EnvironmentProviderCapability::REPOSITORY_MATERIALIZE, $partialReport->toArray()['capabilities'], true),
    'a withheld capability really is absent from what the provider advertises'
);
duo_check_throws(
    static fn () => $partialReport->require(
        [EnvironmentProviderCapability::REPOSITORY_MATERIALIZE, EnvironmentProviderCapability::ENVIRONMENT_CREATE],
        'rehearse'
    ),
    RuntimeException::class,
    'negotiation refuses when the provider cannot serve the operation',
    'missing environment.create, repository.materialize'
);

// Never emulation: the action itself refuses, and names the id, before any
// docker or git command is reached. The orchestrator redacts provider output,
// so the named id is read from the provider's own operator log.
duo_check_throws(
    static fn () => $partial->perform('repository-materialize', $operationId, [
        'branch_commit' => str_repeat('a', 40),
        'expected_environment_identity' => 'duo-pair-mup-side-2',
    ]),
    RuntimeException::class,
    'an action whose capability is withheld fails rather than being emulated',
    'provider failed'
);
$errors = (string) @file_get_contents($subset['state'] . '/provider-errors.log');
duo_check(
    str_contains($errors, 'repository.materialize') && str_contains($errors, 'refusing rather than emulating it'),
    'the provider names the missing capability id and says it refuses rather than emulating it'
);
// Capability negotiation is state-independent, and the refused action reaches
// no mutation capability. An absent state document is therefore the strongest
// no-mutation evidence; tolerate a pre-existing empty document as well.
$stateBytes = @file_get_contents($subset['state'] . '/state.json');
$state = $stateBytes === false
    ? ['fences' => [], 'resources' => [], 'sessions' => [], 'snapshots' => [], 'ttls' => []]
    : json_decode($stateBytes, true, 512, JSON_THROW_ON_ERROR);
duo_check_same(
    [[], [], [], [], []],
    [$state['fences'], $state['resources'], $state['sessions'], $state['snapshots'], $state['ttls']],
    'a refused action publishes no resource, fence, session, snapshot or TTL: nothing was performed'
);

duo_check_summary('reference environment provider negotiation');

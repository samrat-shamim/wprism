<?php
/**
 * Real-provider source snapshot -> contained preview cycle for the three-env
 * live regression. The release target is deliberately outside this provider.
 *
 * usage:
 *   php contained-provider-live-cycle.php materialize <config> <receipt>
 *   php contained-provider-live-cycle.php probe <config> <receipt>
 *   php contained-provider-live-cycle.php reap <config> <receipt>
 */
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/cli/src/Environment/EnvironmentLifecycle.php';

use WPrism\Orchestrator\CommandEnvironmentProvider;

[$script, $mode, $configPath, $receiptPath] = array_pad($_SERVER['argv'] ?? [], 4, null);
if (!in_array($mode, ['materialize', 'probe', 'reap'], true) || !is_string($configPath) || !is_string($receiptPath)) {
    fwrite(STDERR, "usage: php contained-provider-live-cycle.php materialize|probe|reap <config> <receipt>\n");
    exit(2);
}
$config = json_decode((string) file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
$sourceName = (string) $config['source_environment'];
$targetName = '';
foreach ($config['environments'] as $name => $environment) {
    if (($environment['role'] ?? null) === 'target') $targetName = (string) $name;
}
if ($targetName === '') throw new RuntimeException('live-cycle config has no target');
$providerScript = dirname(__DIR__, 4) . '/tools/reference-env-provider.php';
$provider = static fn (string $environment): CommandEnvironmentProvider => CommandEnvironmentProvider::fromEnvironment($environment, [
    '_machine_local' => true,
    'environment_provider' => [
        'command' => [PHP_BINARY, $providerScript, $configPath],
        'timeout_seconds' => 300,
    ],
]);
$source = $provider($sourceName);
$target = $provider($targetName);
$operation = 'contained-live-operation-0000000000000001';
$identityInput = static fn (array $identity): array => [
    'expected_environment_identity' => $identity['environment_identity'],
    'expected_lease_generation' => $identity['lease_generation'],
    'expected_lease_id' => $identity['lease_id'],
    'expected_ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
    'expected_resource_id' => $identity['resource_id'],
];
$fenceInput = static fn (array $fence): array => [
    'expected_mutation_generation' => $fence['mutation_generation'],
    'expected_mutation_id' => $fence['mutation_id'],
    'expected_mutation_owner' => $fence['mutation_owner'],
    'expected_mutation_receipt_sha256' => $fence['mutation_receipt_sha256'],
];

if ($mode === 'reap') {
    $receipt = json_decode((string) file_get_contents($receiptPath), true, 512, JSON_THROW_ON_ERROR);
    $target->capabilities($operation);
    $result = $target->perform('destroy', $operation, $receipt['fenced_input'] + ['compare_and_reap' => true]);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}

if ($mode === 'probe') {
    $target->capabilities($operation);
    $create = $target->perform('create', $operation, [
        'intent_sha256' => hash('sha256', 'contained-live-create'),
        'mode' => 'create',
        'target_environment' => $targetName,
    ]);
    $targetIdentity = $identityInput($create);
    $fence = $target->perform('mutation-acquire', $operation, $targetIdentity + [
        'mutation_owner' => 'contained-live-owner-0001',
    ]);
    $fenced = $targetIdentity + $fenceInput($fence);
    $containment = $target->perform('containment-verify', $operation, $fenced + [
        'profile' => 'agency-rehearsal-v1',
    ]);
    $receipt = [
        'containment' => $containment,
        'fenced_input' => $fenced,
        'format' => 'wprism-contained-preview-live-cycle/v1',
        'target_identity' => $create['environment_identity'],
    ];
    file_put_contents(
        $receiptPath,
        json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        LOCK_EX
    );
    chmod($receiptPath, 0600);
    echo json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}

$source->capabilities($operation);
$target->capabilities($operation);
$sourceIdentity = $source->perform('inspect', $operation, ['role' => 'source']);
$sessionId = 'contained-live-snapshot-session-0001';
$prepared = $source->perform('snapshot-prepare', $operation, $identityInput($sourceIdentity) + [
    'snapshot_session_id' => $sessionId,
]);
$sessionInput = [
    'expected_snapshot_session_id' => $prepared['snapshot_session_id'],
    'expected_source_identity' => $prepared['source_identity'],
    'expected_source_lease_generation' => $prepared['lease_generation'],
    'expected_source_lease_id' => $prepared['lease_id'],
    'expected_source_lease_receipt_sha256' => $prepared['lease_receipt_sha256'],
];
$snapshot = $source->perform('snapshot-create', $operation, $sessionInput + [
    'expected_semantic_snapshot_sha256' => hash('sha256', 'contained-live-semantic-snapshot'),
    'production_commit' => str_repeat('a', 40),
]);
$snapshot = $source->perform('snapshot-read', $operation, $sessionInput + [
    'expected_snapshot_set_id' => $snapshot['snapshot_set_id'],
    'expected_snapshot_set_receipt_sha256' => $snapshot['snapshot_set_receipt_sha256'],
]);
$create = $target->perform('create', $operation, [
    'intent_sha256' => hash('sha256', 'contained-live-create'),
    'mode' => 'create',
    'target_environment' => $targetName,
]);
$targetIdentity = $identityInput($create);
$fence = $target->perform('mutation-acquire', $operation, $targetIdentity + [
    'mutation_owner' => 'contained-live-owner-0001',
]);
$fenced = $targetIdentity + $fenceInput($fence);
$containment = $target->perform('containment-verify', $operation, $fenced + [
    'profile' => 'agency-rehearsal-v1',
]);
$restored = $target->perform('snapshot-restore', $operation, $fenced + [
    'database_sha256' => $snapshot['database_sha256'],
    'media_sha256' => $snapshot['media_sha256'],
    'snapshot_set_id' => $snapshot['snapshot_set_id'],
]);
$target->perform('url-set', $operation, $fenced + ['url' => $create['url']]);
$receipt = [
    'containment' => $containment,
    'fenced_input' => $fenced,
    'format' => 'wprism-contained-preview-live-cycle/v1',
    'restored_snapshot_set_id' => $restored['snapshot_set_id'],
    'source_identity' => $sourceIdentity['environment_identity'],
    'target_identity' => $create['environment_identity'],
];
file_put_contents($receiptPath, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
chmod($receiptPath, 0600);
echo json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

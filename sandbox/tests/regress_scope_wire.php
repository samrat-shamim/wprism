<?php
/**
 * Offline host/agent scope wire regression for DUO-3344.
 *
 * The fake local wp transport records every target argument. A valid
 * read-only contract proves the host emits only the compact request, while
 * malformed, duplicate, reserved, and unsupported inputs prove refusal
 * before the target process starts. No WordPress target is required.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once "$root/agent/src/Canon.php";
require_once "$root/agent/src/CanonicalSurfaces.php";
require_once "$root/agent/src/ScopeClosure.php";
require_once "$root/agent/src/ScopeContract.php";

use Duo\Canon;
use Duo\ScopeContract;

$failures = 0;
function check_wire(bool $condition, string $message): void {
    global $failures;
    echo ($condition ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$condition) {
        $failures++;
    }
}

function put_wire(string $path, string $bytes): void {
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
        throw new RuntimeException('could not create wire fixture directory');
    }
    if (file_put_contents($path, $bytes) === false) {
        throw new RuntimeException('could not write wire fixture');
    }
}

$tmp = sys_get_temp_dir() . '/duo-scope-wire-' . bin2hex(random_bytes(6));
if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
    throw new RuntimeException('could not create scope wire fixture root');
}
register_shutdown_function(static function () use ($tmp): void {
    if (!is_dir($tmp)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($tmp);
});

$hash = str_repeat('a', 64);
$contract = [
    'code_diagnostic' => null,
    'eligible_surfaces' => [],
    'exclusions' => [
        'code' => 'excluded from scope semantics',
        'lifecycle' => 'excluded from scope semantics',
        'mutation_execution' => 'deferred to the target operation',
        'target_guard_witnesses' => 'excluded from immutable evidence',
    ],
    'format' => ScopeContract::FORMAT,
    'live' => ['closure' => [], 'excluded' => [], 'inbound' => [], 'roots' => []],
    'media' => [],
    'mutation_authority' => false,
    'potential_actions' => [],
    'potential_effects' => [],
    'potential_providers' => [],
    'purpose' => 'read-only scope evidence; never mutation authority',
    'read_only_evidence' => true,
    'resolution' => ['live_root_entities' => [], 'tombstone_uuids' => []],
    'selectors' => ['all'],
    'source' => [
        'artifact_hash' => $hash,
        'manifest_hash' => $hash,
        'state_revision_hash' => $hash,
    ],
    'tombstones' => [],
    'uploads' => [],
];
$contract['scope_hash'] = hash('sha256', Canon::encode($contract));
check_wire(ScopeContract::from_array($contract) === $contract, 'fixture is a real canonical ScopeContract');
$contractPath = "$tmp/selected.scope.json";
put_wire($contractPath, Canon::encode($contract));

$argsPath = "$tmp/target-args.json";
$fakeBin = "$tmp/bin";
mkdir($fakeBin, 0700, true);
put_wire(
    "$fakeBin/wp",
    "#!/usr/bin/env php\n<?php\nfile_put_contents(getenv('DUO_SCOPE_WIRE_ARGS'), json_encode(array_slice(\$argv, 1), JSON_UNESCAPED_SLASHES));\n"
);
chmod("$fakeBin/wp", 0700);
$envsPath = "$tmp/envs.json";
put_wire($envsPath, json_encode([
    'envs' => ['fixture' => [
        'transport' => 'local',
        'wp_path' => "$tmp/wordpress",
        'repo_path' => '/target/repo',
    ]],
], JSON_UNESCAPED_SLASHES));

/** @return array{exit:int,stdout:string,stderr:string,args:?array} */
function invoke_wire(
    string $root,
    string $envsPath,
    string $fakeBin,
    string $argsPath,
    string $verb,
    array $extra
): array {
    @unlink($argsPath);
    $oldPath = getenv('PATH') ?: '';
    putenv("PATH=$fakeBin:$oldPath");
    putenv("DUO_SCOPE_WIRE_ARGS=$argsPath");
    $command = array_merge(
        [PHP_BINARY, "$root/cli/duo", "--envs-file=$envsPath", $verb, 'fixture'],
        $extra
    );
    $process = proc_open($command, [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('could not start public duo CLI');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    putenv("PATH=$oldPath");
    putenv('DUO_SCOPE_WIRE_ARGS');
    $args = is_file($argsPath) ? json_decode((string) file_get_contents($argsPath), true) : null;
    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr, 'args' => $args];
}

/** @return array{request:array<string,mixed>,args:list<string>} */
function scope_wire_observation(array $result, array $contract): array {
    $args = array_values(array_filter(
        (array) ($result['args'] ?? []),
        static fn($arg): bool => is_string($arg) && !str_starts_with($arg, '--path=')
    ));
    $wire = array_values(array_filter(
        $args,
        static fn($arg): bool => str_starts_with((string) $arg, '--scope-request-b64=')
    ));
    check_wire(count($wire) === 1, 'target receives exactly one compact scope request');
    $encoded = $wire[0] ?? '';
    $decoded = base64_decode(substr($encoded, strlen('--scope-request-b64=')), true);
    $request = is_string($decoded) ? json_decode($decoded, true) : null;
    check_wire(
        is_array($request)
            && ($request['format'] ?? null) === 'duo-scope-request/v1'
            && ($request['scope_hash'] ?? null) === $contract['scope_hash']
            && ($request['selectors'] ?? null) === $contract['selectors'],
        'compact request is canonical format/hash/selectors evidence'
    );
    return ['request' => is_array($request) ? $request : [], 'args' => $args];
}

$plan = invoke_wire($root, $envsPath, $fakeBin, $argsPath, 'plan', [
    "--scope-contract=$contractPath", '--format=json',
]);
$planObservation = scope_wire_observation($plan, $contract);
check_wire(
    $plan['exit'] === 0
        && !in_array("--scope-contract=$contractPath", $planObservation['args'], true)
        && !in_array(Canon::encode($contract), $planObservation['args'], true),
    'plan forwards the compact request and never the local contract path or full input'
);

$apply = invoke_wire($root, $envsPath, $fakeBin, $argsPath, 'apply', [
    "--scope-contract=$contractPath", '--format=json',
]);
$applyObservation = scope_wire_observation($apply, $contract);
check_wire(
    $apply['exit'] === 0
        && !in_array("--scope-contract=$contractPath", $applyObservation['args'], true),
    'apply remains the ordinary apply transport operation while forwarding exact scope evidence'
);

$unscoped = invoke_wire($root, $envsPath, $fakeBin, $argsPath, 'plan', ['--format=json']);
$unscopedArgs = array_values(array_filter(
    (array) ($unscoped['args'] ?? []),
    static fn($arg): bool => is_string($arg) && !str_starts_with($arg, '--path=')
));
check_wire(
    $unscoped['exit'] === 0
        && $unscopedArgs === ['duo', 'plan', '--repo=/target/repo', '--format=json'],
    'unscoped plan forwarding remains byte-for-byte unchanged'
);

$tampered = $contract;
$tampered['scope_hash'] = str_repeat('b', 64);
$tamperedPath = "$tmp/tampered.scope.json";
put_wire($tamperedPath, Canon::encode($tampered));
$bad = invoke_wire($root, $envsPath, $fakeBin, $argsPath, 'plan', [
    "--scope-contract=$tamperedPath", '--format=json',
]);
$badEnvelope = json_decode(trim($bad['stdout']), true);
check_wire(
    $bad['exit'] !== 0
        && is_array($badEnvelope)
        && ($badEnvelope['format'] ?? null) === 'duo-command-refusal/v1'
        && ($badEnvelope['reason_code'] ?? null) === 'scope_contract_invalid'
        && !is_file($argsPath)
        && !str_contains($bad['stdout'], $tamperedPath)
        && !str_contains($bad['stderr'], $tamperedPath),
    'tampered input is refused as one JSON value before target invocation without path echo'
);

$reservedInput = 'reserved-wire-secret-probe';
$reserved = invoke_wire($root, $envsPath, $fakeBin, $argsPath, 'apply', [
    '--scope-request-b64=' . base64_encode($reservedInput), '--format=json',
]);
$reservedEnvelope = json_decode(trim($reserved['stdout']), true);
check_wire(
    $reserved['exit'] !== 0
        && is_array($reservedEnvelope)
        && ($reservedEnvelope['reason_code'] ?? null) === 'invalid_arguments'
        && !is_file($argsPath)
        && !str_contains($reserved['stdout'], $reservedInput)
        && !str_contains($reserved['stderr'], $reservedInput),
    'reserved request injection is refused before target invocation without input echo'
);

$duplicate = invoke_wire($root, $envsPath, $fakeBin, $argsPath, 'plan', [
    "--scope-contract=$contractPath", "--scope-contract=$contractPath", '--format=json',
]);
$duplicateEnvelope = json_decode(trim($duplicate['stdout']), true);
check_wire(
    $duplicate['exit'] !== 0
        && is_array($duplicateEnvelope)
        && ($duplicateEnvelope['reason_code'] ?? null) === 'invalid_arguments'
        && !is_file($argsPath),
    'duplicate local contracts are refused before target invocation'
);

$promote = invoke_wire($root, $envsPath, $fakeBin, $argsPath, 'promote', [
    "--scope-contract=$contractPath", '--format=json',
]);
$promoteEnvelope = json_decode(trim($promote['stdout']), true);
check_wire(
    $promote['exit'] !== 0
        && is_array($promoteEnvelope)
        && ($promoteEnvelope['reason_code'] ?? null) === 'invalid_arguments'
        && !is_file($argsPath)
        && !str_contains($promote['stdout'], $contractPath)
        && !str_contains($promote['stderr'], $contractPath),
    'promote explicitly refuses scope contracts without contacting the target'
);

$cliSource = (string) file_get_contents("$root/agent/src/Cli.php");
check_wire(
    substr_count($cliSource, "\$opts['scope_request'] = \$scopeRequest;") >= 2
        && str_contains($cliSource, "self::scope_request(\$assoc, 'verify-canonical', false)"),
    'agent plan/apply and internal verifier carry scope_request only when present'
);

if ($failures !== 0) {
    fwrite(STDERR, "FAIL: $failures scope wire assertion(s) failed\n");
    exit(1);
}
echo "scope wire regression passed\n";

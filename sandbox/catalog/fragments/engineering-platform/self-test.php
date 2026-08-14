#!/usr/bin/env php
<?php

declare(strict_types=1);

use Duo\EngineeringPlatform\Catalog;
use Duo\EngineeringPlatform\CatalogException;

require_once __DIR__ . '/CatalogException.php';
require_once __DIR__ . '/Catalog.php';

$temporary = sys_get_temp_dir() . '/duo-catalog-self-test-' . bin2hex(random_bytes(8));
$fragmentDirectory = $temporary . '/sandbox/catalog/fragments/engineering-platform';
if (!mkdir($fragmentDirectory, 0700, true) || file_put_contents($temporary . '/Makefile', "self-test:\n\t@true\n") === false) {
    fwrite(STDERR, "catalog self-test: cannot create fixture repository\n");
    exit(1);
}

/**
 * @return array{
 *     format:string,
 *     owner:string,
 *     suites:non-empty-list<array<string,mixed>>,
 *     inventory:non-empty-list<array<string,mixed>>,
 *     profiles:non-empty-list<array<string,mixed>>
 * }
 */
function valid_fragment(): array
{
    return [
        'format' => 'duo-test-catalog-fragment/v1',
        'owner' => 'thread-1',
        'suites' => [[
            'id' => 'catalog-fixture',
            'command' => ['php', '-v'],
            'layer' => 'platform',
            'owner' => 'thread-1',
            'timeout_seconds' => 10,
            'parallel_safe' => true,
            'resource_locks' => [],
            'temporary_directory' => 'unique',
            'workspace_mode' => 'read_only',
            'required_tools' => ['php'],
            'required_services' => [],
            'covered_paths' => ['sandbox/catalog/**'],
            'covered_contracts' => [],
            'evidence_inputs' => [],
            'evidence_role' => 'none',
            'required_review_gate' => null,
            'environment_class' => 'offline',
            'expected_outputs' => [],
        ]],
        'inventory' => [[
            'kind' => 'file',
            'name' => 'sandbox/catalog/fragments/engineering-platform/fixture.catalog.json',
            'role' => 'self_test',
            'suite_ids' => ['catalog-fixture'],
        ]],
        'profiles' => [[
            'id' => 'catalog-fixture-profile',
            'owner' => 'thread-1',
            'suite_ids' => ['catalog-fixture'],
            'environment_class' => 'offline',
            'blocking' => true,
            'expected_outputs' => ['artifacts/test-results/catalog-fixture.json'],
            'evidence_staleness' => 'forbidden',
        ]],
    ];
}

/** @param array<string,mixed> $fragment */
function write_fragment(string $path, array $fragment): void
{
    $bytes = json_encode($fragment, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($path, $bytes) !== strlen($bytes)) {
        throw new RuntimeException('cannot write test fragment');
    }
}

function expect_failure(string $name, string $needle, callable $operation): void
{
    try {
        $operation();
    } catch (CatalogException $exception) {
        if (!str_contains($exception->getMessage(), $needle)) {
            throw new RuntimeException("$name failed for the wrong reason: " . $exception->getMessage());
        }
        printf("ok: %s\n", $name);
        return;
    }
    throw new RuntimeException("$name unexpectedly passed");
}

function remove_fixture(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        if (file_exists($path)) {
            unlink($path);
        }
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            remove_fixture($path . '/' . $entry);
        }
    }
    rmdir($path);
}

/** @param list<string> $argv */
function self_test_process(array $argv, string $root): void
{
    $process = proc_open(
        $argv,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        ['PATH' => (string) getenv('PATH'), 'LC_ALL' => 'C'],
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new RuntimeException('cannot start fixture git process');
    }
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('fixture git process failed: ' . trim((string) $stderr));
    }
}

$path = $fragmentDirectory . '/fixture.catalog.json';
try {
    write_fragment($path, valid_fragment());
    $ledgerDirectory = $temporary . '/docs/proposals';
    if (!mkdir($ledgerDirectory, 0700, true)) {
        throw new RuntimeException('cannot create fixture ledger directory');
    }
    $ledger = [
        'format' => 'duo-refactor-ownership/v1',
        'files' => [],
        'new_prefixes' => [[
            'prefix' => 'sandbox/catalog/fragments/engineering-platform/',
            'owner' => 'thread-1',
        ]],
    ];
    $ledgerBytes = json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($ledgerDirectory . '/refactor-ownership.json', $ledgerBytes) !== strlen($ledgerBytes)) {
        throw new RuntimeException('cannot write fixture ownership ledger');
    }
    self_test_process(['git', 'init', '--quiet'], $temporary);
    self_test_process(['git', 'add', 'Makefile', 'docs/proposals/refactor-ownership.json', 'sandbox/catalog/fragments/engineering-platform/fixture.catalog.json'], $temporary);
    $catalog = (new Catalog($temporary))->validate('thread-1', false);
    if (count($catalog['suites']) !== 1 || $catalog['suites'][0]['id'] !== 'catalog-fixture') {
        throw new RuntimeException('valid fragment did not produce the expected aggregate');
    }
    echo "ok: valid partial fragment\n";

    $duplicate = valid_fragment();
    $duplicate['suites'][] = $duplicate['suites'][0];
    write_fragment($path, $duplicate);
    expect_failure('duplicate suite ids fail', 'duplicate suite id', static fn() => (new Catalog($temporary))->validate('thread-1', false));

    $unknown = valid_fragment();
    $unknown['inventory'][0]['suite_ids'] = ['absent-suite'];
    write_fragment($path, $unknown);
    expect_failure('unknown inventory links fail', 'unknown suite', static fn() => (new Catalog($temporary))->validate('thread-1', false));

    $live = valid_fragment();
    $live['suites'][0]['environment_class'] = 'live';
    write_fragment($path, $live);
    expect_failure('live suites require authority references', 'authority_requirements', static fn() => (new Catalog($temporary))->validate('thread-1', false));

    $literalReference = valid_fragment();
    $literalReference['suites'][0]['environment_class'] = 'live';
    $literalReference['suites'][0]['authority_requirements'] = [
        'provisioning_reference_id' => 'local-provisioning',
        'environment_role_reference_id' => 'local-environment',
        'data_profile' => 'synthetic',
        'credential_reference_id' => 'local-credential',
        'effect_policy_reference_id' => 'local-effect-policy',
        'sandbox_destination_reference_id' => 'local-sandbox',
        'output_authority' => 'non_authorizing',
        'output_adoptability' => 'forbidden',
    ];
    $literalReference['profiles'][0]['environment_class'] = 'live';
    write_fragment($path, $literalReference);
    expect_failure('authority references are content addressed', 'content-addressed reference id', static fn() => (new Catalog($temporary))->validate('thread-1', false));

    $unresolvedReference = valid_fragment();
    $unresolvedReference['suites'][0]['environment_class'] = 'live';
    $unresolvedReference['suites'][0]['authority_requirements'] = [
        'provisioning_reference_id' => 'sha256:' . str_repeat('a', 64),
        'environment_role_reference_id' => 'sha256:' . str_repeat('b', 64),
        'data_profile' => 'synthetic',
        'credential_reference_id' => 'sha256:' . str_repeat('c', 64),
        'effect_policy_reference_id' => 'sha256:' . str_repeat('d', 64),
        'sandbox_destination_reference_id' => 'sha256:' . str_repeat('e', 64),
        'output_authority' => 'non_authorizing',
        'output_adoptability' => 'forbidden',
    ];
    $unresolvedReference['profiles'][0]['environment_class'] = 'live';
    write_fragment($path, $unresolvedReference);
    expect_failure('authority references resolve to owner definitions', 'unresolved provisioning authority reference', static fn() => (new Catalog($temporary))->validate('thread-1', false));

    $unsafeOutput = valid_fragment();
    $unsafeOutput['profiles'][0]['expected_outputs'] = ['../outside.json'];
    write_fragment($path, $unsafeOutput);
    expect_failure('artifact paths cannot escape', 'unsafe artifact path', static fn() => (new Catalog($temporary))->validate('thread-1', false));

    $reservedProfile = valid_fragment();
    $reservedProfile['profiles'][0]['id'] = 'thread-2-host-cli';
    write_fragment($path, $reservedProfile);
    expect_failure('reserved profiles enforce their owner', 'reserved to thread-2', static fn() => (new Catalog($temporary))->validate('thread-1', false));

    write_fragment($path, valid_fragment());
    expect_failure('complete validation requires all profiles', 'required profile is missing', static fn() => (new Catalog($temporary))->validate());

    echo "catalog self-test: 9 checks passed\n";
} finally {
    remove_fixture($temporary);
}

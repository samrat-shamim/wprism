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
            'name' => 'sandbox/catalog/self-test.php',
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

$path = $fragmentDirectory . '/fixture.catalog.json';
try {
    write_fragment($path, valid_fragment());
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

    write_fragment($path, valid_fragment());
    expect_failure('complete validation requires all profiles', 'required profile is missing', static fn() => (new Catalog($temporary))->validate());

    echo "catalog self-test: 5 checks passed\n";
} finally {
    remove_fixture($temporary);
}

#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$ledgerPath = $root . '/docs/proposals/refactor-ownership.json';
$bytes = file_get_contents($ledgerPath);
try {
    $ledger = is_string($bytes) ? json_decode($bytes, true, 128, JSON_THROW_ON_ERROR) : null;
} catch (JsonException $exception) {
    fwrite(STDERR, 'architecture-check: Thread 0 boundary ledger is invalid: ' . $exception->getMessage() . "\n");
    exit(1);
}
$expected = [
    'from' => 'production',
    'to' => 'sandbox/** or development tooling',
    'rule' => 'forbidden',
];
$found = false;
if (is_array($ledger) && is_array($ledger['boundary_rules'] ?? null)) {
    foreach ($ledger['boundary_rules'] as $rule) {
        if ($rule === $expected) {
            $found = true;
            break;
        }
    }
}
if (!$found) {
    fwrite(STDERR, "architecture-check: required Thread 0 production/development boundary rule is absent or changed\n");
    exit(1);
}

$process = proc_open(
    [
        $root . '/vendor/bin/deptrac',
        '--config-file=' . __DIR__ . '/deptrac.yaml',
        '--no-cache',
        '--no-interaction',
    ],
    [0 => STDIN, 1 => STDOUT, 2 => STDERR],
    $pipes,
    $root,
    [
        'HOME' => (string) getenv('HOME'),
        'PATH' => (string) getenv('PATH'),
        'LC_ALL' => 'C',
        'TZ' => 'UTC',
    ],
    ['bypass_shell' => true],
);
if (!is_resource($process)) {
    fwrite(STDERR, "architecture-check: cannot start the locked architecture analyzer\n");
    exit(1);
}
exit(proc_close($process));

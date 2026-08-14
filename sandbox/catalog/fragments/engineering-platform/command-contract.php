#!/usr/bin/env php
<?php

declare(strict_types=1);

use Duo\EngineeringPlatform\CatalogException;
use Duo\EngineeringPlatform\CommandContract;

require_once __DIR__ . '/CatalogException.php';
require_once __DIR__ . '/CommandContract.php';

$root = dirname(__DIR__, 4);
$command = $argv[1] ?? 'validate';
$output = $root . '/docs/contracts/commands/generated/developer-commands.json';

try {
    $contract = new CommandContract($root);
    $canonical = $contract->canonical();
    if ($command === 'validate') {
        fwrite(STDOUT, "developer-command-contract: valid\n");
        exit(0);
    }
    if ($command === 'generate') {
        if (!is_dir(dirname($output)) && !mkdir(dirname($output), 0755, true) && !is_dir(dirname($output))) {
            throw new CatalogException('cannot create generated command-contract directory');
        }
        if (file_put_contents($output, $canonical) !== strlen($canonical)) {
            throw new CatalogException('cannot publish generated developer command contract');
        }
        fwrite(STDOUT, "developer-command-contract: generated\n");
        exit(0);
    }
    if ($command === 'verify') {
        $actual = file_get_contents($output);
        if (!is_string($actual) || !hash_equals(hash('sha256', $canonical), hash('sha256', $actual))) {
            throw new CatalogException('generated developer command contract is absent or stale');
        }
        fwrite(STDOUT, "developer-command-contract: generated aggregate agrees\n");
        exit(0);
    }
    throw new CatalogException("unknown developer-command-contract command: $command");
} catch (CatalogException $exception) {
    fwrite(STDERR, 'developer-command-contract: ' . $exception->getMessage() . "\n");
    exit(1);
}

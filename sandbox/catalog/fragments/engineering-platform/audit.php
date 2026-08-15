#!/usr/bin/env php
<?php

declare(strict_types=1);

use Duo\EngineeringPlatform\Audit;
use Duo\EngineeringPlatform\CatalogException;

require_once __DIR__ . '/CatalogException.php';
require_once __DIR__ . '/Audit.php';

$root = dirname(__DIR__, 4);
$result = 'artifacts/test-results/audit/result.json';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--result=')) {
        $result = substr($argument, strlen('--result='));
    } else {
        fwrite(STDERR, "audit: unknown argument: $argument\n");
        exit(2);
    }
}
try {
    exit((new Audit($root))->run($result));
} catch (CatalogException $exception) {
    fwrite(STDERR, 'audit: ' . $exception->getMessage() . "\n");
    exit(2);
}

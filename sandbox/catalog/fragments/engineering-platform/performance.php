#!/usr/bin/env php
<?php

declare(strict_types=1);

use Duo\EngineeringPlatform\CatalogException;
use Duo\EngineeringPlatform\Performance;

require_once __DIR__ . '/CatalogException.php';
require_once __DIR__ . '/DeterministicArchive.php';
require_once __DIR__ . '/Build.php';
require_once __DIR__ . '/PerformanceHarness.php';
require_once __DIR__ . '/Performance.php';

$root = dirname(__DIR__, 4);
$command = $argv[1] ?? '';
$result = null;
foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--result=')) {
        $result = substr($argument, strlen('--result='));
    } else {
        fwrite(STDERR, "performance: unknown argument: $argument\n");
        exit(2);
    }
}
if (!in_array($command, ['smoke', 'budget', 'baseline-proposal'], true) || $result === null || $result === '') {
    fwrite(STDERR, "performance: expected smoke|budget|baseline-proposal and --result\n");
    exit(2);
}

try {
    $performance = new Performance($root);
    $receipt = match ($command) {
        'smoke' => $performance->smoke(),
        'budget' => $performance->budget(),
        'baseline-proposal' => $performance->baselineProposal(),
    };
    $path = str_starts_with($result, '/') ? $result : $root . '/' . $result;
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
        throw new CatalogException('cannot create performance result directory');
    }
    $bytes = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($path, $bytes) !== strlen($bytes)) {
        throw new CatalogException('cannot publish performance result');
    }
    $state = $receipt['state'] ?? null;
    if (!is_string($state)) {
        throw new CatalogException('performance result has no state');
    }
    printf("performance: %s %s\n", $command, $state);
    exit($state === 'pass' ? 0 : ($state === 'infra_error' ? 2 : 1));
} catch (CatalogException|JsonException $exception) {
    fwrite(STDERR, 'performance: ' . $exception->getMessage() . "\n");
    exit(1);
}

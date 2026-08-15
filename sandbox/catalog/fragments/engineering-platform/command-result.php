#!/usr/bin/env php
<?php

declare(strict_types=1);

use Duo\EngineeringPlatform\CatalogException;
use Duo\EngineeringPlatform\CommandResult;

require_once __DIR__ . '/CatalogException.php';
require_once __DIR__ . '/CommandResult.php';

$root = dirname(__DIR__, 4);
$id = null;
$result = null;
$separator = array_search('--', $argv, true);
if (!is_int($separator)) {
    fwrite(STDERR, "command-result: -- separator is required\n");
    exit(2);
}
foreach (array_slice($argv, 1, $separator - 1) as $argument) {
    if (str_starts_with($argument, '--id=')) {
        $id = substr($argument, strlen('--id='));
    } elseif (str_starts_with($argument, '--result=')) {
        $result = substr($argument, strlen('--result='));
    } else {
        fwrite(STDERR, "command-result: unknown argument: $argument\n");
        exit(2);
    }
}
$command = array_slice($argv, $separator + 1);
if ($id === null || $result === null || $result === '' || $command === []) {
    fwrite(STDERR, "command-result: --id, --result, and command argv are required\n");
    exit(2);
}

try {
    $runner = new CommandResult($root);
    $evaluation = $runner->run($id, $command);
    $runner->publish($evaluation['receipt'], $result);
    $state = $evaluation['receipt']['state'] ?? null;
    printf("command-result: %s %s\n", $id, is_string($state) ? $state : 'infra_error');
    exit($evaluation['exit']);
} catch (CatalogException|JsonException $exception) {
    fwrite(STDERR, 'command-result: ' . $exception->getMessage() . "\n");
    exit(2);
}

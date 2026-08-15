#!/usr/bin/env php
<?php

declare(strict_types=1);

use Duo\EngineeringPlatform\Aggregate;
use Duo\EngineeringPlatform\CatalogException;

require_once __DIR__ . '/CatalogException.php';
require_once __DIR__ . '/Aggregate.php';

$root = dirname(__DIR__, 4);
$profile = null;
$result = null;
$dependencies = [];
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--profile=')) {
        $profile = substr($argument, strlen('--profile='));
    } elseif (str_starts_with($argument, '--result=')) {
        $result = substr($argument, strlen('--result='));
    } elseif (str_starts_with($argument, '--dependency=')) {
        $value = substr($argument, strlen('--dependency='));
        $separator = strpos($value, ':');
        if ($separator === false) {
            fwrite(STDERR, "aggregate: dependency must be id:artifacts-path\n");
            exit(2);
        }
        $dependencies[substr($value, 0, $separator)] = substr($value, $separator + 1);
    } else {
        fwrite(STDERR, "aggregate: unknown argument: $argument\n");
        exit(2);
    }
}
if ($profile === null || $result === null) {
    fwrite(STDERR, "aggregate: --profile and --result are required\n");
    exit(2);
}
try {
    $aggregate = new Aggregate($root);
    $evaluation = $aggregate->evaluate($profile, $dependencies);
    $aggregate->publish($evaluation['receipt'], $result);
    $state = $evaluation['receipt']['state'] ?? null;
    if (!is_string($state)) {
        throw new CatalogException('aggregate evaluation returned an invalid state');
    }
    printf("aggregate: %s\n", $state);
    exit($evaluation['exit']);
} catch (CatalogException $exception) {
    fwrite(STDERR, 'aggregate: ' . $exception->getMessage() . "\n");
    exit(2);
}

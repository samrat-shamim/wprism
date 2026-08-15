#!/usr/bin/env php
<?php

declare(strict_types=1);

use Duo\EngineeringPlatform\Catalog;
use Duo\EngineeringPlatform\CatalogException;
use Duo\EngineeringPlatform\Runner;

require_once __DIR__ . '/CatalogException.php';
require_once __DIR__ . '/CatalogValidator.php';
require_once __DIR__ . '/SuiteRunner.php';

$root = dirname(__DIR__, 4);
$profile = null;
$owner = null;
$suites = [];
$result = $root . '/artifacts/test-results/catalog/result.json';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--profile=')) {
        $profile = substr($argument, strlen('--profile='));
    } elseif (str_starts_with($argument, '--suite=')) {
        $suites[] = substr($argument, strlen('--suite='));
    } elseif (str_starts_with($argument, '--partial-owner=')) {
        $owner = substr($argument, strlen('--partial-owner='));
    } elseif (str_starts_with($argument, '--result=')) {
        $candidate = substr($argument, strlen('--result='));
        $result = str_starts_with($candidate, '/') ? $candidate : $root . '/' . $candidate;
    } else {
        fwrite(STDERR, "runner: unknown argument: $argument\n");
        exit(2);
    }
}
if (($profile === null) === ($suites === [])) {
    fwrite(STDERR, "runner: select exactly one --profile or one-or-more --suite arguments\n");
    exit(2);
}

try {
    $catalog = (new Catalog($root))->validate($owner, $owner === null);
    exit((new Runner($root, $catalog, $result))->run($suites, $profile));
} catch (CatalogException $exception) {
    fwrite(STDERR, 'runner: ' . $exception->getMessage() . "\n");
    exit(1);
}

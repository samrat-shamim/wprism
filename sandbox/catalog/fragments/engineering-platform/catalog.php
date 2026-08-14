#!/usr/bin/env php
<?php

declare(strict_types=1);

use Duo\EngineeringPlatform\Catalog;
use Duo\EngineeringPlatform\CatalogException;

require_once __DIR__ . '/CatalogException.php';
require_once __DIR__ . '/Catalog.php';

$root = dirname(__DIR__, 4);
$command = $argv[1] ?? 'validate';
$owner = null;
$output = __DIR__ . '/generated/catalog.json';
foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--owner=')) {
        $owner = substr($argument, strlen('--owner='));
    } elseif (str_starts_with($argument, '--output=')) {
        $candidate = substr($argument, strlen('--output='));
        $output = str_starts_with($candidate, '/') ? $candidate : $root . '/' . $candidate;
    } else {
        fwrite(STDERR, "catalog: unknown argument: $argument\n");
        exit(2);
    }
}

try {
    $catalog = new Catalog($root);
    if ($command === 'validate') {
        $aggregate = $catalog->validate($owner, $owner === null);
        printf(
            "catalog: valid (%d suites, %d inventory entries, %d profiles)%s\n",
            count($aggregate['suites']),
            count($aggregate['inventory']),
            count($aggregate['profiles']),
            $owner === null ? '' : " for $owner (partial)",
        );
        exit(0);
    }
    if ($command === 'generate') {
        if ($owner !== null) {
            throw new CatalogException('generate does not accept --owner; only a complete aggregate is authoritative');
        }
        $bytes = $catalog->encode($catalog->validate());
        $directory = dirname($output);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new CatalogException('cannot create generated catalog directory');
        }
        $temporary = tempnam($directory, '.catalog.');
        if (!is_string($temporary)) {
            throw new CatalogException('cannot create generated catalog temporary file');
        }
        try {
            if (file_put_contents($temporary, $bytes) !== strlen($bytes) || !rename($temporary, $output)) {
                throw new CatalogException('cannot publish generated catalog');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        printf("catalog: generated %s (%s)\n", substr($output, strlen($root) + 1), 'sha256:' . hash('sha256', $bytes));
        exit(0);
    }
    throw new CatalogException("unknown command: $command");
} catch (CatalogException $exception) {
    fwrite(STDERR, 'catalog: ' . $exception->getMessage() . "\n");
    exit(1);
}

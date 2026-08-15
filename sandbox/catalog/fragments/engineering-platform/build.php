#!/usr/bin/env php
<?php

declare(strict_types=1);

use Duo\EngineeringPlatform\Build;
use Duo\EngineeringPlatform\CatalogException;

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';
require_once __DIR__ . '/CatalogException.php';
require_once __DIR__ . '/DeterministicArchive.php';
require_once __DIR__ . '/Build.php';

$root = dirname(__DIR__, 4);
$command = $argv[1] ?? '';
$output = 'artifacts/dist';
$result = null;
foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $output = substr($argument, strlen('--output='));
    } elseif (str_starts_with($argument, '--result=')) {
        $result = substr($argument, strlen('--result='));
    } else {
        fwrite(STDERR, "build: unknown argument: $argument\n");
        exit(2);
    }
}

$publish = static function (string $path, array $document) use ($root): void {
    $absolute = str_starts_with($path, '/') ? $path : $root . '/' . $path;
    if (!is_dir(dirname($absolute)) && !mkdir(dirname($absolute), 0755, true) && !is_dir(dirname($absolute))) {
        throw new CatalogException('cannot create build result directory');
    }
    $bytes = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($absolute, $bytes) !== strlen($bytes)) {
        throw new CatalogException('cannot publish build result');
    }
};

try {
    $builder = new Build($root);
    if ($command === 'build') {
        $receipt = $builder->build($output);
        $components = $receipt['components'] ?? null;
        printf("build: pass (%d components)\n", is_array($components) ? count($components) : 0);
        exit(0);
    }
    if ($command === 'payload-dist-check') {
        if ($result === null || $result === '') {
            throw new CatalogException('payload-dist-check requires --result');
        }
        $receipt = $builder->verify($output);
        $publish($result, $receipt);
        echo "payload-dist-check: pass\n";
        exit(0);
    }
    if ($command === 'payload-reproducibility-check') {
        if ($result === null || $result === '') {
            throw new CatalogException('payload-reproducibility-check requires --result');
        }
        $receipt = $builder->reproducibility();
        $publish($result, $receipt);
        echo "payload-reproducibility-check: pass\n";
        exit(0);
    }
    fwrite(STDERR, "build: expected build, payload-dist-check, or payload-reproducibility-check\n");
    exit(2);
} catch (CatalogException|JsonException $exception) {
    fwrite(STDERR, 'build: ' . $exception->getMessage() . "\n");
    exit(1);
}

#!/usr/bin/env php
<?php

declare(strict_types=1);

use Duo\EngineeringPlatform\CatalogException;
use Duo\EngineeringPlatform\Loader;

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';
require_once __DIR__ . '/CatalogException.php';
require_once __DIR__ . '/Loader.php';

$root = dirname(__DIR__, 4);
$result = null;
$dist = 'artifacts/dist';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--result=')) {
        $result = substr($argument, strlen('--result='));
    } elseif (str_starts_with($argument, '--dist=')) {
        $dist = substr($argument, strlen('--dist='));
    } else {
        fwrite(STDERR, "loader-check: unknown argument: $argument\n");
        exit(2);
    }
}
if ($result === null || $result === '') {
    fwrite(STDERR, "loader-check: --result is required\n");
    exit(2);
}

try {
    $receipt = (new Loader($root))->check($dist);
    $path = str_starts_with($result, '/') ? $result : $root . '/' . $result;
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
        throw new CatalogException('cannot create loader result directory');
    }
    $bytes = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($path, $bytes) !== strlen($bytes)) {
        throw new CatalogException('cannot publish loader result');
    }
    echo "loader-check: pass\n";
    exit(0);
} catch (CatalogException|JsonException $exception) {
    fwrite(STDERR, 'loader-check: ' . $exception->getMessage() . "\n");
    exit(1);
}

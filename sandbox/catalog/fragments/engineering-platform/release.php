#!/usr/bin/env php
<?php

declare(strict_types=1);

use Duo\EngineeringPlatform\CatalogException;
use Duo\EngineeringPlatform\Release;

require_once __DIR__ . '/CatalogException.php';
require_once __DIR__ . '/DeterministicArchive.php';
require_once dirname(__DIR__, 4) . '/cli/src/HostContracts/ReleaseSelection.php';
require_once dirname(__DIR__, 4) . '/cli/src/ArtifactTrust/ArtifactTrustVerifier.php';
require_once __DIR__ . '/Release.php';

$root = dirname(__DIR__, 4);
$command = $argv[1] ?? null;
$bundle = null;
$selection = null;
$pinRecord = null;
$result = null;
foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--release-family=')) {
        $bundle = substr($argument, strlen('--release-family='));
    } elseif (str_starts_with($argument, '--selection=')) {
        $selection = substr($argument, strlen('--selection='));
    } elseif (str_starts_with($argument, '--pin-record=')) {
        $pinRecord = substr($argument, strlen('--pin-record='));
    } elseif (str_starts_with($argument, '--result=')) {
        $result = substr($argument, strlen('--result='));
    } else {
        fwrite(STDERR, "release: unknown argument: $argument\n");
        exit(2);
    }
}
if (!in_array($command, ['verify', 'reproducibility'], true)
    || $bundle === null || $selection === null || $pinRecord === null || $result === null) {
    fwrite(STDERR, "release: command, --release-family, --selection, --pin-record, and --result are required\n");
    exit(2);
}
try {
    $release = new Release($root);
    $receipt = $command === 'verify'
        ? $release->verify($bundle, $selection, $pinRecord)
        : $release->reproducibility($bundle, $selection, $pinRecord);
    if (preg_match('~^artifacts/(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]+$~D', $result) !== 1 || str_contains($result, '..')) {
        throw new CatalogException('release result path must be artifacts-relative');
    }
    $output = $root . '/' . $result;
    if (!is_dir(dirname($output)) && !mkdir(dirname($output), 0700, true) && !is_dir(dirname($output))) {
        throw new CatalogException('cannot create release result directory');
    }
    $bytes = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($output, $bytes) !== strlen($bytes) || !chmod($output, 0600)) {
        throw new CatalogException('cannot publish release result');
    }
    printf("release: %s pass; result %s\n", $command, $result);
    exit(0);
} catch (Throwable $exception) {
    $message = str_replace($root, '<repo>', $exception->getMessage());
    fwrite(STDERR, 'release: ' . $message . "\n");
    exit($exception instanceof CatalogException ? 1 : 2);
}

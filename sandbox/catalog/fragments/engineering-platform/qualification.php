#!/usr/bin/env php
<?php

declare(strict_types=1);

use Duo\EngineeringPlatform\CatalogException;
use Duo\EngineeringPlatform\Qualification;

require_once __DIR__ . '/CatalogException.php';
require_once __DIR__ . '/DeterministicArchive.php';
require_once __DIR__ . '/HarnessApproval.php';
require_once __DIR__ . '/Qualification.php';

$root = dirname(__DIR__, 4);
$command = $argv[1] ?? null;
$approval = null;
$result = null;
$releaseFamilyDigest = null;
$releaseFamily = null;
foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--approval=')) {
        $approval = substr($argument, strlen('--approval='));
    } elseif (str_starts_with($argument, '--release-family-sha256=')) {
        $releaseFamilyDigest = substr($argument, strlen('--release-family-sha256='));
    } elseif (str_starts_with($argument, '--result=')) {
        $result = substr($argument, strlen('--result='));
    } elseif (str_starts_with($argument, '--release-family=')) {
        $releaseFamily = substr($argument, strlen('--release-family='));
    } else {
        fwrite(STDERR, "qualification: unknown argument: $argument\n");
        exit(2);
    }
}
$mode = match ($command) {
    'candidate-adoption' => 'candidate_adoption',
    'release-validation' => 'release_validation',
    default => null,
};
if ($mode === null || $approval === null || $approval === '' || $result === null) {
    fwrite(STDERR, "qualification: command, --approval, and --result are required\n");
    exit(2);
}
if ($mode === 'release_validation' && ($releaseFamily === null || $releaseFamily === '')) {
    fwrite(STDERR, "qualification: release-validation requires --release-family\n");
    exit(2);
}
try {
    $receipt = (new Qualification($root))->run($mode, $approval, $releaseFamilyDigest, $releaseFamily);
    if (preg_match('~^artifacts/(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]+$~D', $result) !== 1 || str_contains($result, '..')) {
        throw new CatalogException('qualification result path must be artifacts-relative');
    }
    $output = $root . '/' . $result;
    if (!is_dir(dirname($output)) && !mkdir(dirname($output), 0700, true) && !is_dir(dirname($output))) {
        throw new CatalogException('cannot create qualification result directory');
    }
    $bytes = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($output, $bytes) !== strlen($bytes) || !chmod($output, 0600)) {
        throw new CatalogException('cannot publish qualification result');
    }
    printf("qualification: %s pass; result %s\n", $mode, $result);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'qualification: ' . str_replace($root, '<repo>', $exception->getMessage()) . "\n");
    exit($exception instanceof CatalogException ? 1 : 2);
}

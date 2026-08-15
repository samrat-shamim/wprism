#!/usr/bin/env php
<?php

declare(strict_types=1);

use Duo\EngineeringPlatform\Catalog;
use Duo\EngineeringPlatform\CatalogException;
use Duo\EngineeringPlatform\HarnessApproval;
use Duo\EngineeringPlatform\Runner;

require_once __DIR__ . '/CatalogException.php';
require_once __DIR__ . '/Catalog.php';
require_once __DIR__ . '/Runner.php';
require_once __DIR__ . '/Reports.php';
require_once __DIR__ . '/Selection.php';
require_once __DIR__ . '/ShardPlan.php';
require_once __DIR__ . '/HarnessApproval.php';

$root = dirname(__DIR__, 4);
$profile = null;
$owner = null;
$suites = [];
$selectionPath = null;
$shardPlanPath = null;
$shardIndex = null;
$junitPath = null;
$tapPath = null;
$approvalPath = null;
$keyringPath = null;
$provisioningPath = null;
$probePath = null;
$result = 'artifacts/test-results/catalog/result.json';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--profile=')) {
        $profile = substr($argument, strlen('--profile='));
    } elseif (str_starts_with($argument, '--suite=')) {
        $suites[] = substr($argument, strlen('--suite='));
    } elseif (str_starts_with($argument, '--partial-owner=')) {
        $owner = substr($argument, strlen('--partial-owner='));
    } elseif (str_starts_with($argument, '--selection=')) {
        $selectionPath = substr($argument, strlen('--selection='));
    } elseif (str_starts_with($argument, '--shard-plan=')) {
        $shardPlanPath = substr($argument, strlen('--shard-plan='));
    } elseif (str_starts_with($argument, '--shard-index=')) {
        $value = substr($argument, strlen('--shard-index='));
        $shardIndex = preg_match('/^[0-9]+$/D', $value) === 1 ? (int) $value : null;
    } elseif (str_starts_with($argument, '--result=')) {
        $candidate = substr($argument, strlen('--result='));
        $result = $candidate;
    } elseif (str_starts_with($argument, '--junit=')) {
        $junitPath = substr($argument, strlen('--junit='));
    } elseif (str_starts_with($argument, '--tap=')) {
        $tapPath = substr($argument, strlen('--tap='));
    } elseif (str_starts_with($argument, '--harness-approval=')) {
        $approvalPath = substr($argument, strlen('--harness-approval='));
    } elseif (str_starts_with($argument, '--harness-keyring=')) {
        $keyringPath = substr($argument, strlen('--harness-keyring='));
    } elseif (str_starts_with($argument, '--harness-provisioning=')) {
        $provisioningPath = substr($argument, strlen('--harness-provisioning='));
    } elseif (str_starts_with($argument, '--harness-probe=')) {
        $probePath = substr($argument, strlen('--harness-probe='));
    } else {
        fwrite(STDERR, "runner: unknown argument: $argument\n");
        exit(2);
    }
}
$shardSelected = $shardPlanPath !== null || $shardIndex !== null;
if (($shardPlanPath === null) !== ($shardIndex === null)
    || (int) ($profile !== null) + (int) ($suites !== []) + (int) ($selectionPath !== null) + (int) $shardSelected !== 1) {
    fwrite(STDERR, "runner: select exactly one --profile, one-or-more --suite, --selection, or --shard-plan with --shard-index\n");
    exit(2);
}

try {
    $catalog = (new Catalog($root))->validate($owner, $owner === null);
    $harnessPaths = [$approvalPath, $keyringPath, $provisioningPath, $probePath];
    $suppliedHarnessPaths = count(array_filter($harnessPaths, static fn(?string $path): bool => $path !== null));
    if (!in_array($suppliedHarnessPaths, [0, 4], true)) {
        throw new CatalogException('harness preflight requires approval, keyring, provisioning, and probe together');
    }
    $harness = $suppliedHarnessPaths === 4
        ? (new HarnessApproval($root))->verify(
            (string) $approvalPath,
            (string) $keyringPath,
            (string) $provisioningPath,
            (string) $probePath,
        )
        : null;
    $selection = $selectionPath === null ? null : (new \Duo\EngineeringPlatform\Selection($root, $catalog))->load($selectionPath);
    if ($selection !== null) {
        $suites = $selection['selected_suite_ids'];
    }
    $shard = $shardSelected && $shardPlanPath !== null && $shardIndex !== null
        ? (new \Duo\EngineeringPlatform\ShardPlan($root, $catalog))->context($shardPlanPath, $shardIndex)
        : null;
    if ($shard !== null) {
        $suites = $shard['suite_ids'];
    }
    exit((new Runner($root, $catalog, $result, $owner, $selection, $shard, $junitPath, $tapPath, $harness))->run($suites, $profile));
} catch (Throwable $exception) {
    $message = str_replace($root, '<repo>', $exception->getMessage());
    $message = (string) preg_replace('#(?<![A-Za-z0-9:])/(?:[A-Za-z0-9._@%+=,~\-]+/?)+#', '<redacted-path>', $message);
    try {
        Runner::publishPreflightFailure($root, $result, $profile, $owner, $message);
    } catch (Throwable) {
        // Unsafe output paths are refused rather than replaced or normalized.
    }
    fwrite(STDERR, 'runner: ' . $message . "\n");
    exit(1);
}

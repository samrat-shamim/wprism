#!/usr/bin/env php
<?php

declare(strict_types=1);

use Duo\EngineeringPlatform\Catalog;
use Duo\EngineeringPlatform\CatalogException;
use Duo\EngineeringPlatform\ShardPlan;

require_once __DIR__ . '/CatalogException.php';
require_once __DIR__ . '/Catalog.php';
require_once __DIR__ . '/Runner.php';
require_once __DIR__ . '/ShardPlan.php';

$root = dirname(__DIR__, 4);
$command = $argv[1] ?? null;
$profile = null;
$count = null;
$planPath = null;
$output = null;
$history = [];
$receipts = [];
foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--profile=')) {
        $profile = substr($argument, strlen('--profile='));
    } elseif (str_starts_with($argument, '--count=')) {
        $value = substr($argument, strlen('--count='));
        $count = preg_match('/^[1-9][0-9]*$/D', $value) === 1 ? (int) $value : null;
    } elseif (str_starts_with($argument, '--plan=')) {
        $planPath = substr($argument, strlen('--plan='));
    } elseif (str_starts_with($argument, '--output=')) {
        $output = substr($argument, strlen('--output='));
    } elseif (str_starts_with($argument, '--history=')) {
        $history[] = substr($argument, strlen('--history='));
    } elseif (str_starts_with($argument, '--receipt=')) {
        $value = substr($argument, strlen('--receipt='));
        $separator = strpos($value, ':');
        if ($separator === false || preg_match('/^[0-9]+$/D', substr($value, 0, $separator)) !== 1) {
            fwrite(STDERR, "shard: receipt must be index:artifacts-path\n");
            exit(2);
        }
        $receipts[(int) substr($value, 0, $separator)] = substr($value, $separator + 1);
    } else {
        fwrite(STDERR, "shard: unknown argument: $argument\n");
        exit(2);
    }
}
try {
    $planner = new ShardPlan($root, (new Catalog($root))->validate());
    if ($command === 'plan') {
        if ($profile === null || $count === null || $output === null) {
            throw new CatalogException('shard plan requires --profile, --count, and --output');
        }
        $document = $planner->create($profile, $count, $history);
        $planner->publish($document, $output);
        printf("shard: planned %d shard(s)\n", count($document['shards']));
        exit(0);
    }
    if ($command === 'aggregate') {
        if ($planPath === null || $output === null) {
            throw new CatalogException('shard aggregate requires --plan and --output');
        }
        $evaluation = $planner->aggregate($planPath, $receipts);
        $planner->publishAggregate($evaluation['receipt'], $output);
        $state = $evaluation['receipt']['state'] ?? null;
        if (!is_string($state)) {
            throw new CatalogException('shard aggregate returned an invalid state');
        }
        printf("shard: aggregate %s\n", $state);
        exit($evaluation['exit']);
    }
    if ($command === 'run') {
        if ($planPath === null || $output === null) {
            throw new CatalogException('shard run requires --plan and --output');
        }
        $document = $planner->load($planPath);
        $receiptPaths = [];
        $runIds = [];
        $ownedTrees = [];
        foreach ($document['shards'] as $row) {
            $receiptPath = dirname($output) . '/shards/shard-' . $row['index'] . '.json';
            $receiptPaths[$row['index']] = $receiptPath;
            $runId = gmdate('Ymd\THis\Z') . '-' . substr($document['candidate_sha'], 0, 12) . '-' . bin2hex(random_bytes(4));
            $runIds[$row['index']] = $runId;
            $ownedTrees[] = 'artifacts/test-results/runs/' . $runId;
        }
        $catalog = (new Catalog($root))->validate();
        $runShard = static function (int $index) use (
            $planner,
            $planPath,
            $receiptPaths,
            $runIds,
            $ownedTrees,
            $catalog,
            $root,
        ): int {
            $context = $planner->context($planPath, $index);
            return (new \Duo\EngineeringPlatform\Runner(
                $root,
                $catalog,
                $receiptPaths[$index],
                null,
                null,
                $context,
                null,
                null,
                null,
                $runIds[$index],
                $ownedTrees,
                array_values($receiptPaths),
            ))->run($context['suite_ids'], null);
        };
        foreach ($document['execution_waves'] as $wave) {
            if (count($wave) === 1) {
                $runShard($wave[0]);
                continue;
            }
            if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
                throw new CatalogException('resource-aware parallel shards require PCNTL fork/wait support');
            }
            $children = [];
            foreach ($wave as $index) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new CatalogException('cannot create resource-aware shard worker');
                }
                if ($pid === 0) {
                    exit($runShard($index));
                }
                $children[$pid] = $index;
            }
            foreach ($children as $pid => $index) {
                $status = 0;
                $waited = pcntl_waitpid($pid, $status);
                if ($waited !== $pid || !is_int($status) || !pcntl_wifexited($status)) {
                    fwrite(STDERR, "shard: worker $index did not exit cleanly\n");
                    continue;
                }
                if (pcntl_wexitstatus($status) !== 0) {
                    fwrite(STDERR, "shard: worker $index reported a non-passing result\n");
                }
            }
        }
        $evaluation = $planner->aggregate($planPath, $receiptPaths);
        $planner->publishAggregate($evaluation['receipt'], $output);
        $state = $evaluation['receipt']['state'] ?? null;
        if (!is_string($state)) {
            throw new CatalogException('shard run aggregate returned an invalid state');
        }
        printf("shard: run aggregate %s\n", $state);
        exit($evaluation['exit']);
    }
    throw new CatalogException('shard command must be plan, run, or aggregate');
} catch (CatalogException $exception) {
    fwrite(STDERR, 'shard: ' . $exception->getMessage() . "\n");
    exit(2);
}

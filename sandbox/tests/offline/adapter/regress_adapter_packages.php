<?php

declare(strict_types=1);

use WPrism\Tooling\AdapterPackageTestRunner;
use WPrism\Tooling\AdapterIntegrationScenarios;
use WPrism\Tooling\OfflineScenarioDelegation;

require_once __DIR__ . '/../../../../tools/src/AdapterPackageTestRunner.php';
require_once __DIR__ . '/../../../../tools/src/AdapterIntegrationScenarios.php';
require_once __DIR__ . '/../../../../tools/src/OfflineScenarioDelegation.php';

$repo = dirname(__DIR__, 4);
$scenarios = null;
try {
    $scenarios = AdapterIntegrationScenarios::discover($repo);
    $delegatedScenarios = OfflineScenarioDelegation::checkedSet(
        OfflineScenarioDelegation::decode(getenv(OfflineScenarioDelegation::ENVIRONMENT)),
        $scenarios
    );
} catch (Throwable $failure) {
    fwrite(STDERR, "regress-adapter-packages: integration scenario discovery refused: {$failure->getMessage()}\n");
    exit(1);
}
$packages = $repo . '/adapter-packages';
$platform = $repo . '/platform/adapter-library';
if (!is_dir($platform) || is_link($platform)) {
    fwrite(STDERR, "regress-adapter-packages: platform library is absent or not an ordinary directory: $platform\n");
    exit(1);
}
$entries = scandir($packages);
if ($entries === false) {
    fwrite(STDERR, "regress-adapter-packages: cannot read $packages\n");
    exit(1);
}

$slugs = [];
foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }
    if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $entry) !== 1
        || !is_dir($packages . '/' . $entry)
        || is_link($packages . '/' . $entry)) {
        fwrite(STDERR, "regress-adapter-packages: non-canonical package entry: $entry\n");
        exit(1);
    }
    $slugs[] = $entry;
}
sort($slugs, SORT_STRING);

$failed = 0;
$tests = 0;
foreach ($slugs as $slug) {
    try {
        $run = AdapterPackageTestRunner::run($repo, $slug);
    } catch (Throwable $failure) {
        fwrite(STDERR, "regress-adapter-packages: $slug discovery refused: {$failure->getMessage()}\n");
        $failed++;
        continue;
    }
    $tests += count($run['tests']);
    if ($run['exit_code'] === 0) {
        fwrite(STDOUT, "✔ adapter-packages/$slug: " . count($run['tests']) . " offline tests\n");
        continue;
    }
    $failed++;
    fwrite(STDERR, "✘ adapter-packages/$slug: package gate failed\n");
    foreach ($run['tests'] as $test) {
        if ($test['exit_code'] === 0) {
            continue;
        }
        fwrite(STDERR, "  {$test['path']} exited {$test['exit_code']}\n");
        if ($test['stdout'] !== '') {
            fwrite(STDERR, $test['stdout'] . (str_ends_with($test['stdout'], "\n") ? '' : "\n"));
        }
        if ($test['stderr'] !== '') {
            fwrite(STDERR, $test['stderr'] . (str_ends_with($test['stderr'], "\n") ? '' : "\n"));
        }
    }
}

$scenarioTests = 0;
foreach ($scenarios['scenarios'] as $scenario) {
    foreach ($scenario['gates'] as $gate) {
        if ($gate['class'] !== 'offline') {
            continue;
        }
        $target = OfflineScenarioDelegation::target($scenario, $gate);
        if (isset($delegatedScenarios[$target])) {
            continue;
        }
        $scenarioTests++;
        $command = $gate['command'];
        if ($command[0] === 'php') {
            $command[0] = PHP_BINARY;
        }
        $pipes = [];
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo
        );
        if (!is_resource($process)) {
            fwrite(STDERR, "regress-adapter-packages: cannot start {$gate['path']}\n");
            $failed++;
            continue;
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit === 0) {
            fwrite(STDOUT, "✔ integration-scenarios/{$scenario['name']}: {$gate['path']}\n");
            continue;
        }
        $failed++;
        fwrite(STDERR, "✘ integration-scenarios/{$scenario['name']}: {$gate['path']} exited $exit\n");
        if ($stdout !== '') {
            fwrite(STDERR, $stdout . (str_ends_with($stdout, "\n") ? '' : "\n"));
        }
        if ($stderr !== '') {
            fwrite(STDERR, $stderr . (str_ends_with($stderr, "\n") ? '' : "\n"));
        }
    }
}

if ($failed !== 0) {
    fwrite(STDERR, "regress-adapter-packages: $failed of " . count($slugs) . " package gates failed\n");
    exit(1);
}
fwrite(STDOUT, '✔ REGRESS_ADAPTER_PACKAGES PASSED: ' . count($slugs) . " packages, $tests package tests, "
    . "$scenarioTests offline scenario gates, " . count($scenarios['scenarios']) . " integration scenarios\n");

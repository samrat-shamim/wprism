<?php

declare(strict_types=1);

use Duo\Tooling\AdapterPackageTestRunner;

require_once __DIR__ . '/../../../../tools/src/AdapterPackageTestRunner.php';

$repo = dirname(__DIR__, 4);
$packages = $repo . '/adapter-packages';
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

if ($failed !== 0) {
    fwrite(STDERR, "regress-adapter-packages: $failed of " . count($slugs) . " package gates failed\n");
    exit(1);
}
fwrite(STDOUT, "✔ REGRESS_ADAPTER_PACKAGES PASSED: " . count($slugs) . " packages, $tests tests\n");

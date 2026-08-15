#!/usr/bin/env php
<?php

declare(strict_types=1);

use Duo\EngineeringPlatform\Doctor;
use Duo\EngineeringPlatform\Toolchain;

require_once __DIR__ . '/CatalogException.php';
require_once __DIR__ . '/Doctor.php';
require_once __DIR__ . '/Toolchain.php';

/** @param list<string> $argv */
function bootstrap_probe(array $argv, string $root): string
{
    $process = proc_open(
        $argv,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        ['LC_ALL' => 'C', 'TZ' => 'UTC', 'PATH' => (string) getenv('PATH')],
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new RuntimeException('cannot execute tool identity probe');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $identity = trim((string) $stdout . "\n" . (string) $stderr);
    if ($exit !== 0 || $identity === '') {
        throw new RuntimeException(sprintf('tool identity probe failed for %s', basename($argv[0])));
    }
    return $identity;
}

$root = dirname(__DIR__, 4);
$doctor = new Doctor();
$checks = $doctor->inspect($root);
foreach ($checks as $check) {
    if ($check['state'] === 'fail' || ($check['name'] === 'development-dependencies' && $check['state'] !== 'pass')) {
        fwrite(STDERR, 'bootstrap-dev: prerequisite failed: ' . $check['name'] . ' (' . $check['detail'] . ")\n");
        exit(1);
    }
}
$lockPath = $root . '/composer.lock';
$lockDigest = hash_file('sha256', $lockPath);
if (!is_string($lockDigest)) {
    fwrite(STDERR, "bootstrap-dev: cannot hash composer.lock\n");
    exit(1);
}
try {
    $toolchain = new Toolchain($root);
    $lockedTools = $toolchain->install();
    $receipt = [
        'format' => 'duo-development-bootstrap-receipt/v1',
        'composer_lock_sha256' => 'sha256:' . $lockDigest,
        'toolchain_lock_sha256' => $toolchain->lockDigest(),
        'platform' => $toolchain->platformId(),
        'php_version' => PHP_VERSION,
        'tools' => [
            'composer' => bootstrap_probe(['composer', '--version', '--no-ansi'], $root),
            'php-cs-fixer' => bootstrap_probe([$root . '/vendor/bin/php-cs-fixer', '--version'], $root),
            'phpstan' => bootstrap_probe([$root . '/vendor/bin/phpstan', '--version'], $root),
            'phpunit' => bootstrap_probe([$root . '/vendor/bin/phpunit', '--version'], $root),
        ],
        'locked_tools' => $lockedTools,
    ];
} catch (RuntimeException $exception) {
    fwrite(STDERR, 'bootstrap-dev: ' . $exception->getMessage() . "\n");
    exit(1);
}
ksort($receipt['tools'], SORT_STRING);
$bytes = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
$output = $root . '/artifacts/bootstrap-dev/receipt.json';
if (!is_dir(dirname($output)) && !mkdir(dirname($output), 0755, true) && !is_dir(dirname($output))) {
    fwrite(STDERR, "bootstrap-dev: cannot create receipt directory\n");
    exit(1);
}
$temporary = tempnam(dirname($output), '.bootstrap.');
if (!is_string($temporary)
    || file_put_contents($temporary, $bytes) !== strlen($bytes)
    || !chmod($temporary, 0600)
    || !rename($temporary, $output)) {
    if (is_string($temporary) && is_file($temporary)) {
        unlink($temporary);
    }
    fwrite(STDERR, "bootstrap-dev: cannot publish receipt\n");
    exit(1);
}
printf("bootstrap-dev: lock-pinned tools installed; receipt %s\n", substr($output, strlen($root) + 1));

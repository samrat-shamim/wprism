#!/usr/bin/env php
<?php

declare(strict_types=1);

use Duo\EngineeringPlatform\Doctor;

require_once __DIR__ . '/DoctorService.php';

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
$receipt = [
    'format' => 'duo-development-bootstrap-receipt/v1',
    'composer_lock_sha256' => 'sha256:' . hash_file('sha256', $lockPath),
    'php_version' => PHP_VERSION,
    'tools' => [
        'php-cs-fixer' => trim((string) shell_exec(escapeshellarg($root . '/vendor/bin/php-cs-fixer') . ' --version 2>/dev/null')),
        'phpstan' => trim((string) shell_exec(escapeshellarg($root . '/vendor/bin/phpstan') . ' --version 2>/dev/null')),
        'phpunit' => trim((string) shell_exec(escapeshellarg($root . '/vendor/bin/phpunit') . ' --version 2>/dev/null')),
    ],
];
ksort($receipt['tools'], SORT_STRING);
$bytes = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
$output = $root . '/artifacts/bootstrap/tool-lock-receipt.json';
if (!is_dir(dirname($output)) && !mkdir(dirname($output), 0755, true) && !is_dir(dirname($output))) {
    fwrite(STDERR, "bootstrap-dev: cannot create receipt directory\n");
    exit(1);
}
$temporary = tempnam(dirname($output), '.bootstrap.');
if (!is_string($temporary) || file_put_contents($temporary, $bytes) !== strlen($bytes) || !rename($temporary, $output)) {
    if (is_string($temporary) && is_file($temporary)) {
        unlink($temporary);
    }
    fwrite(STDERR, "bootstrap-dev: cannot publish receipt\n");
    exit(1);
}
printf("bootstrap-dev: lock-pinned tools installed; receipt %s\n", substr($output, strlen($root) + 1));

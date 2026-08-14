#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$separator = array_search('--', $argv, true);
if (!is_int($separator)) {
    fwrite(STDERR, "delegate-check: expected -- before delegated arguments\n");
    exit(64);
}

$label = null;
$delegate = null;
$requiredShas = [];
$requiredValues = [];
foreach (array_slice($argv, 1, $separator - 1) as $argument) {
    if (str_starts_with($argument, '--label=')) {
        $label = substr($argument, strlen('--label='));
    } elseif (str_starts_with($argument, '--delegate=')) {
        $delegate = substr($argument, strlen('--delegate='));
    } elseif (str_starts_with($argument, '--required-sha=')) {
        $requiredShas[] = substr($argument, strlen('--required-sha='));
    } elseif (str_starts_with($argument, '--required-value=')) {
        $requiredValues[] = substr($argument, strlen('--required-value='));
    } else {
        fwrite(STDERR, "delegate-check: unknown argument $argument\n");
        exit(64);
    }
}

if (!is_string($label) || preg_match('/^[a-z][a-z0-9-]*$/D', $label) !== 1
    || !is_string($delegate) || $delegate === '' || str_starts_with($delegate, '/')
    || str_contains($delegate, '\\') || in_array('..', explode('/', $delegate), true)) {
    fwrite(STDERR, "delegate-check: invalid label or repository-relative delegate path\n");
    exit(64);
}

foreach ($requiredShas as $requirement) {
    $parts = explode('=', $requirement, 2);
    if (count($parts) !== 2 || preg_match('/^[A-Z][A-Z0-9_]*$/D', $parts[0]) !== 1
        || preg_match('/^[a-f0-9]{40}$/D', $parts[1]) !== 1) {
        fwrite(STDERR, "$label: expected a full lowercase 40-hex " . $parts[0] . "\n");
        exit(64);
    }
}
foreach ($requiredValues as $requirement) {
    $parts = explode('=', $requirement, 2);
    if (count($parts) !== 2 || preg_match('/^[A-Z][A-Z0-9_]*$/D', $parts[0]) !== 1
        || $parts[1] === '' || preg_match('/[\x00-\x1f\x7f]/', $parts[1]) === 1) {
        fwrite(STDERR, "$label: expected a nonempty " . $parts[0] . "\n");
        exit(64);
    }
}

$absolute = $root . '/' . $delegate;
if (!is_file($absolute) || !is_executable($absolute)) {
    fwrite(STDERR, "$label: owner export is unavailable or non-executable: $delegate\n");
    exit(69);
}

$command = array_merge([$absolute], array_slice($argv, $separator + 1));
$process = proc_open(
    $command,
    [0 => STDIN, 1 => STDOUT, 2 => STDERR],
    $pipes,
    $root,
    null,
    ['bypass_shell' => true],
);
if (!is_resource($process)) {
    fwrite(STDERR, "$label: owner export could not be started\n");
    exit(69);
}
exit(proc_close($process));

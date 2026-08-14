#!/usr/bin/env php
<?php

declare(strict_types=1);

if ($argc < 4) {
    fwrite(STDERR, "process-entry: expected pid-file, status-file, and executable\n");
    exit(126);
}
$pidFile = $argv[1];
$statusFile = $argv[2];
$executable = $argv[3];
$pid = getmypid();
if ($pid === false || file_put_contents($pidFile, (string) $pid . "\n", LOCK_EX) === false || !chmod($pidFile, 0600)) {
    fwrite(STDERR, "process-entry: cannot publish process-group identity\n");
    exit(126);
}
$process = proc_open(
    array_merge([$executable], array_slice($argv, 4)),
    [0 => STDIN, 1 => STDOUT, 2 => STDERR],
    $pipes,
    null,
    null,
    ['bypass_shell' => true],
);
if (!is_resource($process)) {
    fwrite(STDERR, "process-entry: cannot execute selected suite\n");
    exit(126);
}
$lastStatus = proc_get_status($process);
while ($lastStatus['running']) {
    usleep(10000);
    $lastStatus = proc_get_status($process);
}
$exitCode = proc_close($process);
if ($exitCode === -1 && $lastStatus['exitcode'] >= 0) {
    $exitCode = $lastStatus['exitcode'];
}
$status = [
    'exit_code' => $exitCode,
    'signaled' => $lastStatus['signaled'],
    'signal' => $lastStatus['signaled'] ? $lastStatus['termsig'] : null,
];
$statusBytes = json_encode($status, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($statusFile, $statusBytes, LOCK_EX) !== strlen($statusBytes) || !chmod($statusFile, 0600)) {
    fwrite(STDERR, "process-entry: cannot publish process status\n");
    exit(126);
}
exit($lastStatus['signaled'] ? 128 + $lastStatus['termsig'] : max(0, min(255, $exitCode)));

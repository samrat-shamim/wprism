<?php
declare(strict_types=1);

// A release created by the controller after refusal establishes ordering;
// a one-second sleep could expire before a descheduled controller returned.
$directory = $argv[1];
$nonce = $argv[2];
file_put_contents($directory . '/ready.tmp', json_encode(['pid' => getmypid(), 'nonce' => $nonce], JSON_THROW_ON_ERROR));
rename($directory . '/ready.tmp', $directory . '/ready');
$deadline = hrtime(true) + 15000000000;
do {
    clearstatcache(true, $directory . '/release');
    if (file_exists($directory . '/release')) {
        file_put_contents($directory . '/marker', $nonce);
        exit(0);
    }
    usleep(10000);
} while (hrtime(true) < $deadline);
exit(70);

<?php
/**
 * Support script for regress_capture_publish.php's P6 (real SIGKILL
 * mid-publish). Run as a CHILD PROCESS (`php capture_publish_kill_driver.php
 * <stateDir>`) — never included directly by the harness.
 *
 * Runs the exact same sequence Capture::run() does around
 * agent/src/Publication/Publish.php (lock -> recover -> write staged entities -> swap),
 * then sends itself SIGKILL immediately after its first staged file. The
 * former parent-side 300ms sleep could kill a slow-starting child before any
 * staging existed (the delayed-startup lane reproduces this). A real signal
 * at the completed write leaves pending entities and never reaches swap(),
 * application cleanup or a shutdown function, exactly like an OOM-kill.
 */

require __DIR__ . '/../../../agent/src/Kernel/Canon.php';
require __DIR__ . '/../../../agent/src/Publication/Publish.php';

use WPrism\Canon;
use WPrism\Publish;

$stateDir = $argv[1] ?? null;
$startup = $argv[2] ?? 'immediate';
if (!$stateDir || !in_array($startup, ['immediate', 'delayed-startup'], true)) {
    fwrite(STDERR, "usage: capture_publish_kill_driver.php <stateDir> [immediate|delayed-startup]\n");
    exit(2);
}
if ($startup === 'delayed-startup') {
    usleep(1_000_000);
}

$lock = Publish::lock($stateDir);
Publish::recover($stateDir);

$entities = [];
for ($i = 1; $i <= 200; $i++) {
    $entities[] = ['path' => "posts/post/entity-$i.json", 'content' => str_repeat("x", 200) . "\n"];
}

$killWriter = function (string $path, string $content): void {
    Canon::write_file($path, $content);
    posix_kill(getmypid(), SIGKILL);
    throw new RuntimeException('staged-write SIGKILL did not terminate the driver');
};

Publish::write_entities(Publish::stage_dir($stateDir), $entities, $killWriter);

// Reaching publication means the crash checkpoint was bypassed.
Publish::swap($stateDir);
Publish::unlock($lock);
echo "driver: completed without being killed\n";

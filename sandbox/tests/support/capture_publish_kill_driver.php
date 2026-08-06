<?php
/**
 * Support script for regress_capture_publish.php's P6 (real SIGKILL
 * mid-publish). Run as a CHILD PROCESS (`php capture_publish_kill_driver.php
 * <stateDir>`) — never included directly by the harness.
 *
 * Runs the exact same sequence Capture::run() does around
 * agent/src/Publish.php (lock -> recover -> write staged entities -> swap),
 * deliberately slowed down (usleep between each staged file) so the parent
 * test has a wide, reliable window to SIGKILL this process partway through
 * writing the staging directory — well before it could reach swap(), the
 * only step that ever touches the published dir. If the parent's kill
 * lands as intended, execution simply stops dead mid-loop: no shutdown
 * function, no catch block, nothing — exactly what a real OOM-kill does.
 */

require __DIR__ . '/../../../agent/src/Canon.php';
require __DIR__ . '/../../../agent/src/Publish.php';

use Duo\Canon;
use Duo\Publish;

$stateDir = $argv[1] ?? null;
if (!$stateDir) {
    fwrite(STDERR, "usage: capture_publish_kill_driver.php <stateDir>\n");
    exit(2);
}

$lock = Publish::lock($stateDir);
Publish::recover($stateDir);

$entities = [];
for ($i = 1; $i <= 200; $i++) {
    $entities[] = ['path' => "posts/post/entity-$i.json", 'content' => str_repeat("x", 200) . "\n"];
}

$slowWriter = function (string $path, string $content): void {
    Canon::write_file($path, $content);
    usleep(5_000); // 5ms/file * 200 files = ~1s of wall time to write the staging dir
};

Publish::write_entities(Publish::stage_dir($stateDir), $entities, $slowWriter);

// Only reached if the parent's kill missed its window entirely.
Publish::swap($stateDir);
Publish::unlock($lock);
echo "driver: completed without being killed\n";

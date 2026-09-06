<?php
declare(strict_types=1);

// File admission is not native acceptance. The live caller checks its declared
// source intent only after complete transport and private ownership survive.
try {
    if (count($argv) !== 3 || !str_starts_with($argv[1], '/') || preg_match('/^[a-z][a-z0-9]{2,23}$/D', $argv[2]) !== 1) {
        throw new RuntimeException('source native diagnostic arguments are invalid');
    }
    $stem = $argv[1];
    $directory = @lstat(dirname($stem));
    if (!is_array($directory) || ($directory['mode'] & 0177777) !== 0040700) {
        throw new RuntimeException('source native diagnostic directory is not private');
    }
    $streams = [];
    foreach (['stdout' => 1048576, 'stderr' => 1048576, 'exit' => 8] as $suffix => $limit) {
        $path = "$stem.$suffix";
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0177777) !== 0100600 || $stat['nlink'] !== 1 || $stat['uid'] !== $directory['uid'] || $stat['size'] > $limit) {
            throw new RuntimeException('source native diagnostic stream is unsafe or oversized');
        }
        $bytes = @file_get_contents($path, false, null, 0, $limit + 1);
        if (!is_string($bytes) || strlen($bytes) !== $stat['size']) {
            throw new RuntimeException('source native diagnostic stream changed while reading');
        }
        $streams[$suffix] = $bytes;
    }
    if ($streams['exit'] !== "0\n") {
        throw new RuntimeException('source native observation did not succeed');
    }
    foreach (explode("\n", $streams['stderr']) as $line) {
        if ($line !== '' && preg_match('/^ ?Container wprism-' . preg_quote($argv[2], '/') . '-cli1-run-[a-f0-9]+ (Creating|Created) *$/D', $line) !== 1) {
            throw new RuntimeException('source native observation has an unexpected diagnostic');
        }
    }
    $record = json_decode($streams['stdout'], true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($record) || array_is_list($record)) {
        throw new RuntimeException('source native observation is not one object');
    }
    echo $streams['stdout'];
} catch (Throwable) {
    fwrite(STDERR, "combined source native diagnostic admission failed; inspect private capture\n");
    exit(1);
}

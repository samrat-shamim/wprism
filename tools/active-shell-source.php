#!/usr/bin/env php
<?php

declare(strict_types=1);

use Duo\Tooling\ActiveShellSource;

require_once __DIR__ . '/src/ActiveShellSource.php';

$arguments = $_SERVER['argv'] ?? [];
$statement = ($arguments[1] ?? null) === '--statement';
$participant = ($arguments[1] ?? null) === '--participant';
$path = ($statement || $participant) ? ($arguments[2] ?? null) : ($arguments[1] ?? null);
$expectedCount = ($statement || $participant) ? 4 : 2;
if (!is_string($path) || count($arguments) !== $expectedCount || !is_file($path) || is_link($path)) {
    fwrite(
        STDERR,
        "usage: php tools/active-shell-source.php FILE | --statement FILE NEEDLE | --participant FILE SLUG\n"
    );
    exit(2);
}
$source = file_get_contents($path);
if ($source === false) {
    fwrite(STDERR, "active-shell-source: cannot read $path\n");
    exit(1);
}
try {
    if (!$statement && !$participant) {
        fwrite(STDOUT, ActiveShellSource::source($source));
        exit(0);
    }
    if ($participant) {
        $slug = $arguments[3] ?? null;
        $participants = ActiveShellSource::manifestParticipants($source);
        exit(is_string($slug) && in_array($slug, $participants, true) ? 0 : 1);
    }
    $needle = $arguments[3] ?? null;
    $match = is_string($needle) ? ActiveShellSource::statement($source, $needle) : null;
    if ($match === null) {
        exit(1);
    }
    fwrite(STDOUT, $match['comment']);
} catch (\RuntimeException $exception) {
    fwrite(STDERR, 'active-shell-source: ' . $exception->getMessage() . "\n");
    exit(1);
}

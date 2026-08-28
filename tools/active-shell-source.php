#!/usr/bin/env php
<?php

declare(strict_types=1);

use Duo\Tooling\ActiveShellSource;

require_once __DIR__ . '/src/ActiveShellSource.php';

$arguments = $_SERVER['argv'] ?? null;
$path = is_array($arguments) ? ($arguments[1] ?? null) : null;
if (!is_string($path) || !is_array($arguments) || count($arguments) !== 2 || !is_file($path) || is_link($path)) {
    fwrite(STDERR, "usage: php tools/active-shell-source.php FILE\n");
    exit(2);
}
$source = file_get_contents($path);
if ($source === false) {
    fwrite(STDERR, "active-shell-source: cannot read $path\n");
    exit(1);
}
fwrite(STDOUT, ActiveShellSource::source($source));

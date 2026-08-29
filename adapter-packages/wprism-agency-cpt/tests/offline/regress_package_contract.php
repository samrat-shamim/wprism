<?php
declare(strict_types=1);
$root = dirname(__DIR__, 4);
$slug = basename(dirname(__DIR__, 2));
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/adapter-package-validate.php')
    . ' --adapter=' . escapeshellarg($slug), $status);
exit($status);

#!/usr/bin/env php
<?php

declare(strict_types=1);

use Duo\Tooling\AdapterPackageMakeTarget;

require_once __DIR__ . '/src/AdapterPackageMakeTarget.php';

$repo = dirname(__DIR__);
$target = null;
$repoSeen = false;
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $argument) {
    if (is_string($argument) && str_starts_with($argument, '--repo=') && !$repoSeen) {
        $repo = substr($argument, strlen('--repo='));
        $repoSeen = true;
        continue;
    }
    if (is_string($argument) && str_starts_with($argument, '--target=') && $target === null) {
        $target = substr($argument, strlen('--target='));
        continue;
    }
    fwrite(STDERR, "usage: php tools/adapter-package-make-target.php --target=regress-NAME [--repo=PATH]\n");
    exit(2);
}
if (!is_string($target) || $target === '') {
    fwrite(STDERR, "usage: php tools/adapter-package-make-target.php --target=regress-NAME [--repo=PATH]\n");
    exit(2);
}

try {
    exit(AdapterPackageMakeTarget::run($repo, $target));
} catch (Throwable $failure) {
    fwrite(STDERR, 'adapter-package-make-target: ' . $failure->getMessage() . "\n");
    exit(1);
}

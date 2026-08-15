#!/usr/bin/env php
<?php

declare(strict_types=1);

use Duo\EngineeringPlatform\CatalogException;
use Duo\EngineeringPlatform\Quality;

require_once __DIR__ . '/CatalogException.php';
require_once __DIR__ . '/Toolchain.php';
require_once __DIR__ . '/Quality.php';

try {
    (new Quality(dirname(__DIR__, 4)))->run();
    fwrite(STDOUT, "lint: PHP, JSON, shell, and workflow checks passed\n");
} catch (CatalogException $exception) {
    fwrite(STDERR, 'lint: ' . $exception->getMessage() . "\n");
    exit(1);
}

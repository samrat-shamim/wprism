<?php

declare(strict_types=1);

use Duo\EngineeringPlatform\CatalogException;
use Duo\EngineeringPlatform\Hygiene;

require_once __DIR__ . '/CatalogException.php';
require_once __DIR__ . '/Hygiene.php';

try {
    (new Hygiene(dirname(__DIR__, 4)))->run();
    fwrite(STDOUT, "repository hygiene: PASS\n");
} catch (CatalogException $exception) {
    fwrite(STDERR, "repository hygiene: FAIL\n{$exception->getMessage()}\n");
    exit(1);
}

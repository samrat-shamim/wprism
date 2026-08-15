#!/usr/bin/env php
<?php

declare(strict_types=1);

use Duo\EngineeringPlatform\Catalog;
use Duo\EngineeringPlatform\CatalogException;
use Duo\EngineeringPlatform\Selection;

require_once __DIR__ . '/CatalogException.php';
require_once __DIR__ . '/Catalog.php';
require_once __DIR__ . '/Selection.php';

$root = dirname(__DIR__, 4);
$kind = null;
$rawIds = null;
$output = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--kind=')) {
        $kind = substr($argument, strlen('--kind='));
    } elseif (str_starts_with($argument, '--ids=')) {
        $rawIds = substr($argument, strlen('--ids='));
    } elseif (str_starts_with($argument, '--output=')) {
        $output = substr($argument, strlen('--output='));
    } else {
        fwrite(STDERR, "suite-selection: unknown argument: $argument\n");
        exit(2);
    }
}
if ($kind === null || $rawIds === null || $output === null) {
    fwrite(STDERR, "suite-selection: --kind, --ids, and --output are required\n");
    exit(2);
}
$ids = array_values(array_filter(explode(',', $rawIds), static fn(string $id): bool => $id !== ''));
try {
    $catalog = (new Catalog($root))->validate();
    $selection = new Selection($root, $catalog);
    $document = $selection->explicit($kind, $ids);
    $selection->publish($document, $output);
    printf("suite-selection: selected %d diagnostic suite(s)\n", count($document['selected_suite_ids']));
    exit(0);
} catch (CatalogException $exception) {
    fwrite(STDERR, 'suite-selection: ' . $exception->getMessage() . "\n");
    exit(1);
}

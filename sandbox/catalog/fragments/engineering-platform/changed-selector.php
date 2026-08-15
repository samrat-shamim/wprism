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
$base = null;
$head = null;
$output = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--base-sha=')) {
        $base = substr($argument, strlen('--base-sha='));
    } elseif (str_starts_with($argument, '--head-sha=')) {
        $head = substr($argument, strlen('--head-sha='));
    } elseif (str_starts_with($argument, '--output=')) {
        $output = substr($argument, strlen('--output='));
    } else {
        fwrite(STDERR, "changed-selector: unknown argument: $argument\n");
        exit(2);
    }
}
if ($base === null || $head === null || $output === null) {
    fwrite(STDERR, "changed-selector: --base-sha, --head-sha, and --output are required\n");
    exit(2);
}
try {
    $catalog = (new Catalog($root))->validate();
    $selection = new Selection($root, $catalog);
    $document = $selection->changed($base, $head);
    $selection->publish($document, $output);
    printf("changed-selector: %s (%d suites)\n", $document['state'], count($document['selected_suite_ids']));
    exit(0);
} catch (CatalogException $exception) {
    fwrite(STDERR, 'changed-selector: ' . $exception->getMessage() . "\n");
    exit(1);
}

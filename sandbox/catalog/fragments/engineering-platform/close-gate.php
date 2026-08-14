#!/usr/bin/env php
<?php

declare(strict_types=1);

use Duo\EngineeringPlatform\CatalogException;
use Duo\EngineeringPlatform\CloseGate;

require_once __DIR__ . '/CatalogException.php';
require_once __DIR__ . '/DeterministicArchive.php';
require_once dirname(__DIR__, 4) . '/cli/src/HostContracts/ReleaseSelection.php';
require_once dirname(__DIR__, 4) . '/cli/src/ArtifactTrust/ArtifactTrustVerifier.php';
require_once __DIR__ . '/Release.php';
require_once __DIR__ . '/CloseGate.php';

$values = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z-]+)=(.*)$/D', $argument, $match) !== 1) {
        fwrite(STDERR, "final-integration-close-gate: invalid argument\n");
        exit(2);
    }
    $values[$match[1]] = $match[2];
}
$required = ['candidate', 'evidence-child', 'main-ref', 'release-family', 'selection', 'pin-record', 'result'];
$keys = array_keys($values);
sort($keys, SORT_STRING);
$expected = $required;
sort($expected, SORT_STRING);
if ($keys !== $expected) {
    fwrite(STDERR, "final-integration-close-gate: exact candidate, evidence child, main ref, release inputs, and result are required\n");
    exit(2);
}
try {
    $gate = new CloseGate(dirname(__DIR__, 4));
    $receipt = $gate->verify(
        $values['candidate'],
        $values['evidence-child'],
        $values['main-ref'],
        $values['release-family'],
        $values['selection'],
        $values['pin-record'],
    );
    $gate->publish($receipt, $values['result']);
    fwrite(STDOUT, "final-integration-close-gate: pass\n");
} catch (CatalogException $exception) {
    fwrite(STDERR, 'final-integration-close-gate: ' . $exception->getMessage() . "\n");
    exit(1);
}

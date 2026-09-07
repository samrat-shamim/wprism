<?php
declare(strict_types=1);
require_once __DIR__ . '/probe.php';
if ($argc !== 6) {
    throw new RuntimeException('WPForms capture probe: expected stem, pair, phase, repository and port');
}
WPFormsCaptureProbe::verify($argv[1], $argv[2], $argv[3], $argv[4], $argv[5]);
echo "WPForms native ", $argv[3], " observation admitted\n";

<?php
declare(strict_types=1);

require __DIR__ . '/../../agent/src/CaptureGateScanner.php';

function check(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    echo "ok: $message\n";
}

$scannerSource = file_get_contents(__DIR__ . '/../../agent/src/CaptureGateScanner.php');
$captureSource = file_get_contents(__DIR__ . '/../../agent/src/Capture.php');

check(class_exists(Duo\CaptureGateScanner::class, false), 'gate scanner loads as a direct boundary');
check(!class_exists(Duo\Capture::class, false), 'gate scanner does not load Capture');
check(str_contains($scannerSource, 'public function scan(): array'), 'gate scanner owns one focused read-only entry point');
check(str_contains($scannerSource, '$this->scopeDiscovery->gaps()'), 'gate scanner reuses the extracted scope reader');
check(str_contains($scannerSource, '$this->entityMetaCapture->postMetaMap('), 'gate scanner reuses the extracted ordered metadata reader');
check(!str_contains($scannerSource, 'Ledger::'), 'gate scanner has no ledger mutation or repair path');
check(!str_contains($scannerSource, 'Publish::'), 'gate scanner has no publication path');
check(
    str_contains($captureSource, 'return (new CaptureGateScanner($policy, $observationReadCheckpoint))->scan();'),
    'Capture retains a thin public gate-scan compatibility wrapper'
);

echo "REGRESS_CAPTURE_GATE_SCANNER PASSED\n";

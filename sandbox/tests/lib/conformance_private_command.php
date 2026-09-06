<?php
declare(strict_types=1);

require_once __DIR__ . '/PrivateRefusalReceipt.php';

use WPrismTest\EvidenceSizeProfile;
use WPrismTest\PrivateCommandOutput;
use WPrismTest\PrivateRefusalReceipt;

// The native reader loads no WordPress and uses the CLI uid. The host admits
// its complete retained transport; neither side turns a diagnostic into proof.
try {
    $mode = $argv[1] ?? '';
    $command = $argv[2] ?? '';
    PrivateRefusalReceipt::validateDiagnosticBaseline('[]', $command);
    $baseline = static function (array $record) use ($command): string {
        if (array_keys($record) !== ['command', 'baseline'] || $record['command'] !== $command || !is_string($record['baseline'])) {
            throw new RuntimeException('conformance diagnostic baseline is not its exact command envelope');
        }
        PrivateRefusalReceipt::validateDiagnosticBaseline($record['baseline'], $command);
        return $record['baseline'];
    };
    if (in_array($mode, ['snapshot', 'collect'], true) && count($argv) === 4) {
        if ($mode === 'snapshot') {
            echo json_encode(['command' => $command, 'baseline' => PrivateRefusalReceipt::diagnosticSnapshot($argv[3], $command)], JSON_THROW_ON_ERROR), "\n";
        } else {
            $input = stream_get_contents(STDIN, 1048577);
            if (!is_string($input) || strlen($input) > 1048576) {
                throw new RuntimeException('conformance diagnostic baseline exceeds its transport boundary');
            }
            $record = json_decode($input, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($record)) throw new RuntimeException('conformance diagnostic baseline is not an object');
            echo PrivateRefusalReceipt::diagnosticNewRecords($argv[3], $baseline($record), $command), "\n";
        }
    } elseif ($mode === 'validate' && count($argv) === 6) {
        require_once __DIR__ . '/PrivateCommandOutput.php';
        [$pair, $service, $stem] = array_slice($argv, 3);
        if (preg_match('/\A[a-z][a-z0-9]*\z/', $pair) !== 1 || !in_array($service, ['cli1', 'cli2'], true)
            || !in_array(basename($stem), ['baseline', 'private'], true)) {
            throw new RuntimeException('conformance diagnostic transport binding is malformed');
        }
        $prelude = '/\A Container wprism-' . preg_quote($pair, '/') . '-' . $service . '-run-[a-f0-9]{12} (?:Creating|Created) \z/';
        // Four 256-KiB raw records exceed the compact 1-MiB stdout once
        // base64 encoded. Select the existing 2-MiB profile explicitly.
        $record = json_decode(PrivateCommandOutput::readObject($stem, $prelude, EvidenceSizeProfile::CONFORMANCE_TREE), true, 32, JSON_THROW_ON_ERROR);
        if (basename($stem) === 'baseline') $baseline($record);
        else PrivateRefusalReceipt::assertDiagnostic($record, $command);
    } else {
        throw new RuntimeException('conformance diagnostic operation is unsupported');
    }
} catch (Throwable $failure) {
    fwrite(STDERR, json_encode(['error' => get_class($failure), 'message_sha256' => hash('sha256', $failure->getMessage())], JSON_THROW_ON_ERROR) . "\n");
    exit(1);
}

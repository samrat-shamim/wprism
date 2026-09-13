<?php
declare(strict_types=1);

require_once __DIR__ . '/lifecycle-evidence.php';
require_once __DIR__ . '/deletion-evidence.php';
require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/PrivateCommandOutput.php';

use WPrismTest\EvidenceSizeProfile;
use WPrismTest\PrivateCommandOutput;

final class MapVersionMatrixEvidence {
    public static function validate(string $kind, string $pair, string $version, string $stem): ?array {
        if (preg_match('/^[a-z][a-z0-9]*$/D', $pair) !== 1 || !in_array($version, ['1.35', '1.34'], true)) {
            throw new RuntimeException('version matrix subject is malformed');
        }
        $prelude = '/\A Container wprism-' . preg_quote($pair, '/') . '-cli2-run-[a-f0-9]{12} (?:Creating|Created) \z/';
        $exit = $kind === 'capability' && $version === '1.34' ? 3 : 0;
        $bytes = PrivateCommandOutput::readBytes($stem, $kind === 'positive' ? null : $prelude, EvidenceSizeProfile::CONFORMANCE_TREE, $exit);
        if (in_array($kind, ['positive', 'install'], true)) {
            $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $bytes);
            if (!is_string($plain) || preg_match('/(?:^|\s)(?:Warning|Notice|Deprecated|Fatal error|Parse error|Error|FAIL):/mi', $plain) === 1) {
                throw new RuntimeException('version matrix success emitted diagnostics');
            }
            $lines = array_values(array_filter(explode("\n", trim($plain)), static fn(string $line): bool => trim($line) !== ''));
            if ($kind === 'positive') {
                $terminal = '✔ CONFORMANCE PASSED (map-block-gutenberg)';
                if ($version !== '1.35' || end($lines) !== $terminal || count(array_keys($lines, $terminal, true)) !== 1
                    || str_contains($plain, 'AGENT ROUNDTRIP PASSED')) throw new RuntimeException('certified positive roundtrip evidence is missing');
            } elseif ($version !== '1.34' || $lines !== ['Unpacking the package...', 'Installing the plugin...',
                'Removing the old version of the plugin...', 'Plugin updated successfully.', 'Success: Installed 1 of 1 plugins.']) {
                throw new RuntimeException('official old artifact replacement evidence differs');
            }
            return null;
        }
        $record = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($record) || array_is_list($record)) throw new RuntimeException('version matrix observation is not one object');
        if ($kind === 'native') {
            MapLifecycleEvidence::assertObservation($record);
            if (($record['installed'] ?? null) !== $version || ($record['active'] ?? null) !== true
                || ($record['native_loaded'] ?? null) !== true || ($record['wrong_active'] ?? null) !== false) {
                throw new RuntimeException('version matrix native version or activation differs');
            }
            return $record['state'];
        }
        if ($kind === 'credentials') {
            MapDeletionEvidence::assertObservation($record);
            if ($record['deleted']) throw new RuntimeException('version matrix retained credential intent is deleted');
            return $record;
        }
        if ($kind === 'capability') {
            MapLifecycleEvidence::assertCapability($record, true, $version);
            return null;
        }
        throw new RuntimeException('unsupported version matrix evidence');
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        if (count($argv) !== 5) throw new RuntimeException('version matrix evidence invocation differs');
        $record = MapVersionMatrixEvidence::validate($argv[1], $argv[2], $argv[3], $argv[4]);
        if ($record !== null) echo json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    } catch (Throwable $failure) {
        fwrite(STDERR, "Map version matrix evidence refused\n");
        exit(1);
    }
}

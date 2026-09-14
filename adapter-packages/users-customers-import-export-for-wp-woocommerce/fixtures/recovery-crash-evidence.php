<?php
declare(strict_types=1);

require_once __DIR__ . '/settings-evidence.php';
require_once $root . '/sandbox/tests/lib/ContainerProcessEvidence.php';
require_once $root . '/sandbox/tests/lib/SqlDumpEvidence.php';

use WPrismTest\ContainerProcessEvidence;
use WPrismTest\PrivateCommandOutput;
use WPrismTest\PrivateRefusalReceipt;

final class ImporterRecoveryCrashEvidence {
    public static function command(string $root, string $sink, string $pair, string $command, string $phase, string $revision, array $window): void {
        ImporterSettingsEvidence::check(in_array($phase, ['authored-failure', 'ledger-failure'], true)
            && preg_match('/^[a-f0-9]{40}$/D', $revision) === 1, 'exact crash phase and requested revision');
        $stem = $sink . '/' . $command;
        ImporterSettingsEvidence::command($root, $stem, $pair, 'apply', 137);
        $transport = '/^ ?Container wprism-' . preg_quote($pair, '/') . '-cli[12]-run-[a-f0-9]{12} (Creating|Created) *$/D';
        $directory = substr(explode("\n", file_get_contents($stem . '.stderr'), 2)[0], strlen('private command diagnostics (unverified): '));
        ImporterSettingsEvidence::check(PrivateCommandOutput::readBytes($directory . '/command', $transport, expectedExit: 137) === '',
            'SIGKILL has no completed public result or PHP diagnostics');
        $read = static fn(string $path): array => json_decode(PrivateCommandOutput::readObject($path, $transport), true, 32, JSON_THROW_ON_ERROR);
        $baseline = $read($directory . '/baseline');
        ImporterSettingsEvidence::check(array_keys($baseline) === ['command', 'baseline'] && $baseline['command'] === 'apply', 'exact crash diagnostic baseline');
        PrivateRefusalReceipt::validateDiagnosticBaseline($baseline['baseline'], 'apply');
        $diagnostic = $read($directory . '/private');
        PrivateRefusalReceipt::assertDiagnostic($diagnostic, 'apply');
        ImporterSettingsEvidence::check($diagnostic['new_records'] === 0 && $diagnostic['records'] === [], 'abrupt death did not run refusal cleanup');
        $requested = $read($stem . '-container');
        ImporterSettingsEvidence::check(array_keys($requested) === ['name'] && is_string($requested['name'])
            && preg_match('/^wprism-' . preg_quote($pair, '/') . '-cli2-run-[a-f0-9]{12}$/D', $requested['name']) === 1, 'independently allocated owned crash container');
        ContainerProcessEvidence::assertKilled($read($stem . '-process'), $requested['name'], 'wprism-' . $pair, 'cli2',
            ['wp', 'wprism', 'apply', '--repo=/siterepo', '--revision=' . $revision, '--default-author=admin', '--format=json'],
            ['WPRISM_TEST_MODE' => '1', 'WPRISM_TEST_FAIL_DB_CONTEXT' => $phase === 'authored-failure' ? 'apply transaction commit' : 'ledger transaction commit',
                'WPRISM_TEST_DB_FAULT_MODE' => 'kill', 'WPRISM_TEST_PROMOTION_TTL' => '20'], $window);
    }

    /** The complete image has already passed transition verification; waiting changes no target row. */
    public static function deadline(string $sink, string $after, string $pair): int {
        $transport = '/^ ?Container wprism-' . preg_quote($pair, '/') . '-cli[12]-run-[a-f0-9]{12} (Creating|Created) *$/D';
        $rows = WPrismTest\SqlDumpEvidence::projectColumns(PrivateCommandOutput::readBytes($sink . '/' . $after . '-database', $transport,
            WPrismTest\EvidenceSizeProfile::CONFORMANCE_TREE), 'wp_wprism_kv', ['k', 'v']);
        $kv = array_column($rows, 'v', 'k');
        $lease = json_decode($kv['promotion_lock'], true, flags: JSON_THROW_ON_ERROR);
        ImporterSettingsEvidence::check(is_int($lease['expires_at'] ?? null) && $lease['expires_at'] <= time() + 20,
            'bounded natural lease expiry wait');
        return $lease['expires_at'];
    }

    public static function removed(string $sink, string $command): void {
        $requested = json_decode(PrivateCommandOutput::readObject($sink . '/' . $command . '-container'), true, flags: JSON_THROW_ON_ERROR);
        ImporterSettingsEvidence::check(PrivateCommandOutput::readBytes($sink . '/' . $command . '-removed') === $requested['name'] . "\n", 'exact stopped crash container removed');
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (count($argv) === 4 && $argv[1] === 'removed') ImporterRecoveryCrashEvidence::removed($argv[2], $argv[3]);
    elseif (count($argv) === 5 && $argv[1] === 'deadline') echo ImporterRecoveryCrashEvidence::deadline($argv[2], $argv[3], $argv[4]), "\n";
    else throw new RuntimeException('unsupported crash evidence operation');
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/dependency-evidence.php';

use WPrismTest\EvidenceSizeProfile;
use WPrismTest\FilesystemTreeEvidence;
use WPrismTest\PrivateCommandOutput;
use WPrismTest\PrivateRefusalReceipt;
use WPrismTest\SqlDumpEvidence;

final class ImporterVersionMatrixEvidence {
    public static function transcript(string $kind, string $stem): void {
        $bytes = PrivateCommandOutput::readBytes($stem, profile: EvidenceSizeProfile::CONFORMANCE_TREE);
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $bytes);
        ImporterSettingsEvidence::check(is_string($plain)
            && preg_match('/(?:^|\s)(?:Warning|Notice|Deprecated|Fatal error|Parse error|Error|FAIL):/mi', $plain) !== 1,
            'successful matrix transcript has no diagnostics');
        $lines = array_values(array_filter(explode("\n", trim($plain)), static fn(string $line): bool => trim($line) !== ''));
        $terminal = '✔ CONFORMANCE PASSED (users-customers-import-export-for-wp-woocommerce)';
        ImporterSettingsEvidence::check($kind === 'positive' && end($lines) === $terminal
            && count(array_keys($lines, $terminal, true)) === 1 && !str_contains($plain, 'production promotion withheld'),
            'complete certified roundtrip reaches the ordinary host workflow');
    }

    public static function transport(string $pair): string {
        ImporterSettingsEvidence::check(preg_match('/^[a-z][a-z0-9]*$/D', $pair) === 1, 'exact version-matrix pair');
        return '/^ ?Container wprism-' . preg_quote($pair, '/') . '-cli2-run-[a-f0-9]{12} (Creating|Created) *$/D';
    }

    public static function object(string $stem, string $pair): array {
        return json_decode(PrivateCommandOutput::readObject($stem, self::transport($pair)), true, 32, JSON_THROW_ON_ERROR);
    }

    public static function installed(string $sink, string $pair): void {
        $transport = self::transport($pair);
        $bytes = PrivateCommandOutput::readBytes($sink . '/install', $transport);
        ImporterSettingsEvidence::check($bytes === "Unpacking the package...\nInstalling the plugin...\nRemoving the old version of the plugin...\nPlugin updated successfully.\nSuccess: Installed 1 of 1 plugins.\n",
            'official prior artifact replacement completed cleanly');
        foreach (['supported' => '2.7.5', 'prior' => '2.7.4'] as $stage => $version) {
            $expected = ['version' => $version, 'active' => true, 'loaded' => false, 'marker' => '1',
                'tables' => ['wt_iew_mapping_template' => true, 'wt_iew_action_history' => true]];
            ImporterSettingsEvidence::check(self::object($sink . '/' . $stage, $pair) === $expected,
                'exact active artifact observed before plugin bootstrap: ' . $stage);
        }
    }

    public static function snapshot(string $stem, string $pair): array {
        $transport = self::transport($pair);
        $tables = PrivateCommandOutput::readBytes($stem . '-tables', $transport);
        $dump = PrivateCommandOutput::readBytes($stem . '-database', $transport, EvidenceSizeProfile::CONFORMANCE_TREE);
        SqlDumpEvidence::assertComplete($dump, SqlDumpEvidence::tables($tables), [
            'wp_options', 'wp_users', 'wp_wt_iew_mapping_template', 'wp_wt_iew_action_history',
            'wp_wprism_map', 'wp_wprism_state', 'wp_wprism_kv',
        ]);
        $tree = self::object($stem . '-state', $pair);
        ImporterSettingsEvidence::check(array_keys($tree) === ['state', 'policy'], 'complete canonical and policy census');
        FilesystemTreeEvidence::assertRecord($tree['state'], 'state');
        FilesystemTreeEvidence::assertRecord($tree['policy'], 'site.wprism.json');
        ImporterSettingsEvidence::check(count($tree['state']['files']) > 5 && count($tree['policy']['files']) === 1,
            'populated compiled intent and policy');
        $native = self::object($stem . '-native', $pair);
        ImporterSettingsEvidence::check(ImporterDependencyEvidence::retained($native, $native)
            && count($native['tables']['wt_iew_mapping_template']) === 7 && count($native['tables']['users']) >= 8
            && count($native['tables']['wt_iew_action_history']) >= 4 && count($native['files']) >= 6,
            'complete populated native templates, users, history and operational files');
        return [$tables, $dump, $tree, $native];
    }

    public static function refusal(string $sink, string $pair, string $verb): void {
        ImporterSettingsEvidence::check(in_array($verb, ['apply', 'deploy'], true), 'known version-refusal command');
        $stem = $sink . '/' . $verb . '-refusal';
        ImporterSettingsEvidence::command(dirname(__DIR__, 3), $stem, $pair, $verb, 1);
        $directory = substr(explode("\n", (string) file_get_contents($stem . '.stderr'), 2)[0], strlen('private command diagnostics (unverified): '));
        $public = json_decode(PrivateCommandOutput::readObject($directory . '/command', self::transport($pair), expectedExit: 1), true, 32, JSON_THROW_ON_ERROR);
        ImporterSettingsEvidence::check(($public['format'] ?? null) === 'wprism-command-refusal/v1'
            && ($public['ok'] ?? null) === false && ($public['command'] ?? null) === $verb
            && ($public['reason_code'] ?? null) === $verb . '_failed' && ($public['details_redacted'] ?? null) === true,
            'public exact-version refusal retains its redaction boundary');
        $baseline = self::object($directory . '/baseline', $pair);
        ImporterSettingsEvidence::check(array_keys($baseline) === ['command', 'baseline'] && $baseline['command'] === $verb,
            'version refusal belongs to its exact command baseline');
        PrivateRefusalReceipt::validateDiagnosticBaseline($baseline['baseline'], $verb);
        $diagnostic = self::object($directory . '/private', $pair);
        PrivateRefusalReceipt::verifyDiagnostic($diagnostic, ImporterDependencyEvidence::profile('prior', $verb));
        ImporterSettingsEvidence::check(!in_array($diagnostic['records'][0]['name'], json_decode($baseline['baseline'], true, 32, JSON_THROW_ON_ERROR), true),
            'version refusal is new to this invocation');
        ImporterSettingsEvidence::check(self::snapshot($sink . '/' . $verb . '-before', $pair)
            === self::snapshot($sink . '/' . $verb . '-after', $pair),
            'version refusal preserves every database byte, canonical file, policy and operational file');
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
[$mode, $sink, $pair] = array_slice($argv, 1);
if ($mode === 'positive') ImporterVersionMatrixEvidence::transcript($mode, $sink . '/positive');
elseif ($mode === 'installed') ImporterVersionMatrixEvidence::installed($sink, $pair);
else ImporterVersionMatrixEvidence::refusal($sink, $pair, $mode);
echo 'PASS: Importer version matrix ' . $mode . "\n";

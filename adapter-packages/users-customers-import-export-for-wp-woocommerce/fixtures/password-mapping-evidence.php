<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/PrivateCommandOutput.php';
require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/PrivateRefusalReceipt.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';

use WPrism\Canon;
use WPrismTest\PrivateCommandOutput;
use WPrismTest\PrivateRefusalReceipt;

final class ImporterPasswordMappingEvidence {
    public static function verify(
        array $before,
        array $seed,
        array $seeded,
        array $public,
        array $baseline,
        array $diagnostic,
        array $after,
        array $cleaned
    ): void {
        self::check(($seed['phase'] ?? null) === 'seed-passwordless' && is_int($seed['id'] ?? null) && $seed['id'] > 0,
            'native Save returns one positive template identity');
        $row = $seed['row'] ?? null;
        self::check(is_array($row) && (int) ($row['id'] ?? 0) === $seed['id']
            && ($row['template_type'] ?? null) === 'import' && ($row['item_type'] ?? null) === 'user'
            && ($row['name'] ?? null) === 'Generated password mapping', 'exact native passwordless row identity');
        $form = json_decode((string) ($row['data'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
        self::check(($form['mapping_form_data']['mapping_fields']['user_pass'] ?? null) === ['', 0]
            && !array_key_exists('user_pass', $form['mapping_form_data']['mapping_selected_fields'] ?? []),
            'native Save persisted the unsupported disabled and absent password mapping');

        self::check(($before['format'] ?? null) === 'wprism-importer-native-settings/v1'
            && array_keys($before['tables'] ?? []) === ['posts', 'postmeta', 'options', 'terms', 'term_taxonomy',
                'term_relationships', 'termmeta', 'users', 'usermeta', 'wt_iew_action_history', 'wt_iew_mapping_template'],
            'complete native observation roster');
        $expected = $before;
        $expected['tables']['wt_iew_mapping_template'][] = $row;
        usort($expected['tables']['wt_iew_mapping_template'], static fn(array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);
        self::same($expected, $seeded, 'native Save adds only the unsupported template row');
        self::same($seeded, $after, 'Capture refusal preserves the complete native target');
        self::same($before, $cleaned, 'fixture cleanup restores the complete native baseline');

        self::check(($public['format'] ?? null) === 'wprism-command-refusal/v1'
            && ($public['ok'] ?? null) === false && ($public['command'] ?? null) === 'capture'
            && ($public['reason_code'] ?? null) === 'capture_failed' && ($public['error'] ?? null) === 'capture_failed'
            && ($public['message'] ?? null) === 'capture refused at an unclassified safety gate'
            && ($public['details_redacted'] ?? null) === true, 'exact public Capture refusal');
        self::check(array_keys($baseline) === ['command', 'baseline'] && $baseline['command'] === 'capture',
            'exact private refusal baseline');
        PrivateRefusalReceipt::validateDiagnosticBaseline($baseline['baseline'], 'capture');
        PrivateRefusalReceipt::verifyDiagnostic($diagnostic, [
            'command' => 'capture',
            'reason_code' => 'capture_failed',
            'nodes' => [[
                'class' => RuntimeException::class,
                'message' => "wprism: table 'wt_iew_mapping_template' column 'data' (row {$seed['id']}).mapping_form_data.mapping_fields requires nonempty field template 'user_pass'",
                'parent_index' => null,
                'relation' => 'root',
            ]],
        ]);
        self::check(!in_array($diagnostic['records'][0]['name'], json_decode($baseline['baseline'], true, 32, JSON_THROW_ON_ERROR), true),
            'password-mapping refusal is fresh to this invocation');
    }

    private static function same(array $expected, array $actual, string $reason): void {
        self::check(Canon::encode($expected) === Canon::encode($actual), $reason);
    }

    private static function check(bool $ok, string $reason): void {
        if (!$ok) throw new RuntimeException('Importer password mapping evidence: ' . $reason);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
if (($argv[1] ?? '') !== 'verify') throw new RuntimeException('unknown password-mapping evidence mode');
$pair = $argv[2] ?? '';
ImporterPasswordMappingEvidence::verify(...array_map(static function (string $stem, int $index) use ($pair): array {
    $transport = '/^ ?Container wprism-' . preg_quote($pair, '/') . '-cli[12]-run-[a-f0-9]{12} (Creating|Created) *$/D';
    return json_decode(PrivateCommandOutput::readObject($stem, $transport, expectedExit: $index === 3 ? 1 : 0), true, 64, JSON_THROW_ON_ERROR);
}, array_slice($argv, 3), array_keys(array_slice($argv, 3))));
echo "PASS: Importer required password mapping refusal and restoration\n";

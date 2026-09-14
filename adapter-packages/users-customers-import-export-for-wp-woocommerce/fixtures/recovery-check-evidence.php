<?php
declare(strict_types=1);

require_once __DIR__ . '/recovery-evidence.php';

use WPrismTest\PrivateCommandOutput;
use WPrismTest\PrivateRefusalReceipt;
use WPrismTest\EvidenceSizeProfile;

[$phase, $sink, $pair, $beforeLabel, $afterLabel, $command, $revision] = array_slice($argv, 1);
$transport = '/^ ?Container wprism-' . preg_quote($pair, '/') . '-cli[12]-run-[a-f0-9]{12} (Creating|Created) *$/D';
$read = static fn(string $label): array => json_decode(PrivateCommandOutput::readObject($sink . '/' . $label, $transport), true, 32, JSON_THROW_ON_ERROR);
$columns = [];
foreach (ImporterRecoveryEvidence::TABLES as $table) $columns[$table] = PrivateCommandOutput::readBytes($sink . '/recovery-columns-' . str_replace('_', '-', $table), $transport);
$image = static function (string $label) use ($sink, $transport, $columns, $read): array {
    return ['native' => $read($label . '-native'), 'repository' => $read($label . '-state'),
        'database' => ImporterRecoveryEvidence::database(
            PrivateCommandOutput::readBytes($sink . '/' . $label . '-database', $transport, EvidenceSizeProfile::CONFORMANCE_TREE),
            PrivateCommandOutput::readBytes($sink . '/' . $label . '-tables', $transport), $columns)];
};
$before = $image($beforeLabel); $after = $image($afterLabel);
$intent = ImporterRecoveryEvidence::intent($before['repository']['state']);
$plan = $read('recovery-plan');
if ($phase === 'plan') ImporterRecoveryEvidence::plan($before, $after, $plan, $intent);
else {
    ImporterRecoveryEvidence::transition($before, $after, $read('recovery-source-after'), $intent,
        $plan['artifact_hash'], $read($command . '-window'), $phase, $revision);
    if (str_ends_with($phase, '-failure')) {
        $stem = $sink . '/' . $command;
        ImporterSettingsEvidence::command($root, $stem, $pair, 'apply', 1);
        $directory = substr(explode("\n", file_get_contents($stem . '.stderr'), 2)[0], strlen('private command diagnostics (unverified): '));
        $public = json_decode(PrivateCommandOutput::readObject($directory . '/command', $transport, expectedExit: 1), true, flags: JSON_THROW_ON_ERROR);
        ImporterSettingsEvidence::check($public['format'] === 'wprism-command-refusal/v1' && $public['ok'] === false
            && $public['command'] === 'apply' && $public['reason_code'] === 'apply_failed' && $public['error'] === 'apply_failed'
            && $public['message'] === 'apply refused at an unclassified safety gate', 'exact public transaction failure');
        $context = $phase === 'authored-failure' ? 'apply transaction commit' : 'ledger transaction commit';
        $profile = ['command' => 'apply', 'reason_code' => 'apply_failed', 'nodes' => [[
            'class' => 'WPrism\\DatabaseMutationException', 'parent_index' => null, 'relation' => 'root',
            'message' => 'wprism: database mutation failed: ' . $context . ' (injected)',
        ]]];
        $baseline = json_decode(PrivateCommandOutput::readObject($directory . '/baseline', $transport), true, flags: JSON_THROW_ON_ERROR);
        ImporterSettingsEvidence::check(array_keys($baseline) === ['command', 'baseline'] && $baseline['command'] === 'apply', 'exact failure freshness baseline');
        PrivateRefusalReceipt::validateDiagnosticBaseline($baseline['baseline'], 'apply');
        $diagnostic = json_decode(PrivateCommandOutput::readObject($directory . '/private', $transport), true, flags: JSON_THROW_ON_ERROR);
        PrivateRefusalReceipt::verifyDiagnostic($diagnostic, $profile);
        ImporterSettingsEvidence::check(!in_array($diagnostic['records'][0]['name'], json_decode($baseline['baseline'], true, flags: JSON_THROW_ON_ERROR), true), 'failure is fresh to this exact invocation');
    }
}
echo 'PASS: Importer recovery ' . $phase . "\n";

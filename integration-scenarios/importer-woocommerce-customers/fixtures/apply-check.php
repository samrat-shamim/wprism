<?php
declare(strict_types=1);

require_once __DIR__ . '/apply-evidence.php';
require_once __DIR__ . '/catalog-evidence.php';
require_once __DIR__ . '/scoped-apply-evidence.php';
$root = dirname(__DIR__, 3);
require_once $root . '/adapter-packages/users-customers-import-export-for-wp-woocommerce/fixtures/settings-evidence.php';

use WPrismTest\PrivateCommandOutput;
use WPrismTest\EvidenceSizeProfile;

if ($argc !== 8) throw new RuntimeException('Apply evidence requires sink, pair, before, after, phase, revision and mode');
[$sink, $pair, $beforeLabel, $afterLabel, $phase, $revision, $mode] = array_slice($argv, 1);
if (!in_array($phase, ['update', 'repeat', 'remaining'], true)) throw new RuntimeException('unknown combined Apply phase');
if (!in_array($mode, ['full', 'scoped'], true) || ($phase === 'remaining' && $mode !== 'scoped')) throw new RuntimeException('unknown combined Apply mode');
$transport = ImporterWooDatabaseEvidence::transport($pair, 2);
$object = static fn(string $label): array => json_decode(PrivateCommandOutput::readObject($sink . '/' . $label,
    $transport, EvidenceSizeProfile::CONFORMANCE_TREE), true, flags: JSON_THROW_ON_ERROR);
$database = static function (string $label) use ($sink, $pair, $transport): array {
    $image = ImporterWooDatabaseEvidence::fromSink($sink, $pair, 2, $label);
    return ImporterWooApplyEvidence::read(PrivateCommandOutput::readBytes($sink . '/' . $label . '-database',
        $transport, EvidenceSizeProfile::NATIVE_DATABASE), PrivateCommandOutput::readBytes($sink . '/' . $label . '-tables', $transport), $image['columns']);
};
$command = static function (string $label, string $verb) use ($root, $sink, $pair, $transport): array {
    $stem = $sink . '/' . $label;
    ImporterSettingsEvidence::command($root, $stem, $pair, $verb);
    $stderr = file_get_contents($stem . '.stderr');
    $directory = substr(explode("\n", $stderr, 2)[0], strlen('private command diagnostics (unverified): '));
    return json_decode(PrivateCommandOutput::readObject($directory . '/command', $transport), true, flags: JSON_THROW_ON_ERROR);
};
$before = $database($beforeLabel); $after = $database($afterLabel);
// Retain the original pre-Apply identity binding even when evaluating repeat.
$intent = ImporterWooApplyEvidence::intent($object('baseline-state'), $object('desired-state'), $database('apply-before'));
$plan = $command('changed-plan', 'plan');
$artifact = ImporterWooApplyEvidence::plan($plan, $intent, $database('apply-before'));
if ($phase === 'remaining') {
    $remaining = array_values(array_filter($intent, static fn(array $row): bool => $row['kind'] === 'import'));
    ImporterWooApplyEvidence::plan($command('remaining-plan', 'plan'), $remaining, $after);
    echo "PASS: protected import remains the sole pending full-plan update\n";
    exit(0);
}
$receipt = $command($phase . '-apply', 'apply');
if ($mode === 'scoped') {
    $contract = ImporterWooScopedApplyEvidence::sourceContract($sink . '/export-scope', $pair);
    ImporterWooScopedApplyEvidence::plan($command('scoped-plan', 'plan'), $contract, $intent);
    ImporterWooScopedApplyEvidence::transition($before, $after, $intent, $contract['source'], $contract['scope_hash'],
        'importer-woo-export-batch', $object($phase . '-window'), $receipt, $phase === 'repeat');
} else {
    ImporterWooApplyEvidence::transition($before, $after, $intent, $revision, $artifact, $object($phase . '-window'), $phase === 'repeat');
    ImporterWooApplyEvidence::receipt($receipt, $phase === 'repeat');
}
ImporterWooCatalogEvidence::preserved($object($beforeLabel . '-catalog'), $object($afterLabel . '-catalog'));
ImporterWooApplyEvidence::files($object($beforeLabel . '-files'), $object($afterLabel . '-files'));
$oldRepository = $object($beforeLabel . '-state'); $newRepository = $object($afterLabel . '-state');
foreach ([$oldRepository, $newRepository] as $repository) {
    if (array_keys($repository) !== ['state', 'site.wprism.json', 'media']) throw new RuntimeException('incomplete repository artifact roster');
    foreach ($repository as $relative => $tree) WPrismTest\FilesystemTreeEvidence::assertRecord($tree, $relative);
}
if ($oldRepository !== $newRepository) throw new RuntimeException('Apply changed canonical repository bytes or metadata');
echo 'PASS: combined ' . $mode . ' Apply ' . $phase . ' preserves all unselected native data and operational files', "\n";

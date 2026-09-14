<?php
declare(strict_types=1);

require_once __DIR__ . '/apply-evidence.php';
$root = dirname(__DIR__, 3);
require_once $root . '/adapter-packages/users-customers-import-export-for-wp-woocommerce/fixtures/settings-evidence.php';

use WPrismTest\PrivateCommandOutput;
use WPrismTest\EvidenceSizeProfile;

if ($argc !== 7) throw new RuntimeException('Apply evidence requires sink, pair, before, after, phase and revision');
[$sink, $pair, $beforeLabel, $afterLabel, $phase, $revision] = array_slice($argv, 1);
if (!in_array($phase, ['update', 'repeat'], true)) throw new RuntimeException('unknown combined Apply phase');
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
ImporterWooApplyEvidence::transition($before, $after, $intent, $revision, $artifact, $object($phase . '-window'), $phase === 'repeat');
ImporterWooApplyEvidence::files($object($beforeLabel . '-files'), $object($afterLabel . '-files'));
$oldRepository = $object($beforeLabel . '-state'); $newRepository = $object($afterLabel . '-state');
foreach ([$oldRepository, $newRepository] as $repository) {
    if (array_keys($repository) !== ['state', 'site.wprism.json', 'media']) throw new RuntimeException('incomplete repository artifact roster');
    foreach ($repository as $relative => $tree) WPrismTest\FilesystemTreeEvidence::assertRecord($tree, $relative);
}
if ($oldRepository !== $newRepository) throw new RuntimeException('Apply changed canonical repository bytes or metadata');
$receipt = $command($phase . '-apply', 'apply');
ImporterWooApplyEvidence::receipt($receipt, $phase === 'repeat');
echo 'PASS: combined full Apply ' . $phase . ' preserves all unselected native data and operational files', "\n";

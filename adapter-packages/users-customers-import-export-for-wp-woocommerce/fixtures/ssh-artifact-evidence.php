<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/PrivateCommandOutput.php';

final class ImporterSshArtifactEvidence {
    public static function verify(array $states): void {
        $versions = ['initial' => null, 'prior' => '2.7.4', 'supported' => '2.7.5', 'activated' => '2.7.5', 'admin' => '2.7.5'];
        if (array_keys($states) !== array_keys($versions)) throw new RuntimeException('Importer SSH artifact observations are incomplete');
        foreach ($versions as $stage => $version) {
            $active = in_array($stage, ['activated', 'admin'], true);
            $expected = ['version' => $version, 'active' => $active, 'loaded' => $stage === 'admin',
                'marker' => $active ? '1' : null,
                'tables' => ['wt_iew_mapping_template' => $active, 'wt_iew_action_history' => $active]];
            if ($states[$stage] !== $expected) throw new RuntimeException('Importer SSH artifact native premise failed: ' . $stage);
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
if (($argv[1] ?? '') === 'admit') {
    WPrismTest\PrivateCommandOutput::readBytes($argv[2]);
    return;
}
if (($argv[1] ?? '') !== 'verify') throw new RuntimeException('Importer SSH artifact evidence mode is invalid');
$sink = $argv[2];
foreach (['upload', 'install-prior', 'delete-prior', 'install-supported', 'activate'] as $stage) {
    if (WPrismTest\PrivateCommandOutput::readBytes($sink . '/importer-artifact-' . $stage) !== '') {
        throw new RuntimeException('Importer SSH artifact command emitted unexpected output: ' . $stage);
    }
}
$states = [];
foreach (['initial', 'prior', 'supported', 'activated', 'admin'] as $stage) {
    $states[$stage] = json_decode(WPrismTest\PrivateCommandOutput::readObject($sink . '/importer-artifact-' . $stage), true, 32, JSON_THROW_ON_ERROR);
}
ImporterSshArtifactEvidence::verify($states);
echo "PASS: Importer pinned SSH artifacts and native activation\n";

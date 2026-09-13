<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once "$root/sandbox/tests/lib/PrivateCommandOutput.php";
require_once "$root/agent/src/Kernel/Canon.php";

final class ImporterSettingsEvidence {
    public static function check(bool $ok, string $reason): void {
        if (!$ok) throw new RuntimeException('Importer settings admission: ' . $reason);
    }

    public static function command(string $root, string $stem, string $pair, string $verb = '', int $expectedExit = 0): void {
        self::check(preg_match('/^[a-z][a-z0-9]*$/D', $pair) === 1, 'exact owned pair');
        $transport = ' ?Container wprism-' . preg_quote($pair, '/') . '-cli[12]-run-[a-f0-9]{12} (Creating|Created) *';
        if ($verb === '') {
            WPrismTest\PrivateCommandOutput::readBytes($stem, '/^' . $transport . '$/D', expectedExit: $expectedExit);
            return;
        }
        self::check(in_array($verb, ['capture', 'apply'], true), 'known wrapped native command');
        $pointer = 'private command diagnostics (unverified): ';
        $prefix = $root . '/sandbox/tmp/wprism-conformance-' . $verb . '.' . $pair . '.';
        $pattern = '/^(?:' . $transport . '|' . preg_quote($pointer . $prefix, '/') . '[A-Za-z0-9]{6})$/D';
        $stdout = WPrismTest\PrivateCommandOutput::readBytes($stem, $pattern, expectedExit: $expectedExit);
        $stderr = (string) file_get_contents($stem . '.stderr');
        $notice = explode("\n", $stderr, 2)[0];
        self::check(str_starts_with($notice, $pointer . $prefix), 'one leading wrapper diagnostic pointer');
        $directory = substr($notice, strlen($pointer));
        $original = WPrismTest\PrivateCommandOutput::readBytes($directory . '/command', '/^' . $transport . '$/D', expectedExit: $expectedExit);
        self::check($stdout === $original && $stderr === $notice . "\n" . file_get_contents($directory . '/command.stderr'),
            'wrapper streams exactly reproduce the admitted original command');
    }

}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
if (($argv[1] ?? '') === 'admit-command') {
    ImporterSettingsEvidence::command($root, $argv[2], $argv[3], $argv[4] ?? '');
    exit(0);
}
$sink = $argv[1];
$pair = $argv[2];
$check = ImporterSettingsEvidence::check(...);
$read = static function (string $name) use ($sink, $pair, $root): array {
    $stem = "$sink/$name";
    $verb = preg_match('/-(apply|repeat|capture)$/D', $name, $match) === 1 ? $match[1] : '';
    if ($verb === 'repeat') $verb = 'apply';
    ImporterSettingsEvidence::command($root, $stem, $pair, $verb);
    $stderr = (string) file_get_contents($stem . '.stderr');
    if (str_starts_with($stderr, 'private command diagnostics (unverified): ')) {
        $stem = substr(explode("\n", $stderr, 2)[0], strlen('private command diagnostics (unverified): ')) . '/command';
    }
    return json_decode(WPrismTest\PrivateCommandOutput::readObject($stem,
        '/^ ?Container wprism-' . preg_quote($pair, '/') . '-cli[12]-run-[a-f0-9]{12} (Creating|Created) *$/D'), true, 64, JSON_THROW_ON_ERROR);
};
$before = $read('baseline-target');
$check(count($read('jobs')['jobs']) === 3 && count($before['tables']['wt_iew_action_history']) === 3,
    'three real completed exports precede Apply');
foreach (['target', 'source', 'retention'] as $phase) {
    $check($read("$phase-save")['status'] === true, 'native settings Save succeeded');
    $source = $read("$phase-source");
    $contract = $read("$phase-scope");
    $plan = $read("$phase-plan");
    $apply = $read("$phase-apply");
    $after = $read("$phase-target");
    $repeat = $read("$phase-repeat");
    $check($plan['format'] === 'wprism-scoped-plan/v1' && count($plan['update']) === 1
        && $plan['update'][0]['rebuild_option_names'] === ['wt_iew_advanced_settings'], 'plan selects exactly the shared settings option');
    $check($contract['potential_actions'] === [], 'ordinary settings need no executable action');
    $check($apply['format'] === 'wprism-scoped-apply-result/v1' && $apply['applied'] === 1
        && $apply['canary'] === 'clean' && $apply['drift'] === [] && $apply['warnings'] === []
        && $apply['verification']['result'] === 'pass'
        && $apply['verification']['source_artifact_hash'] === $contract['source']['artifact_hash'], 'public scoped Apply verifies exact source authority');
    $check($repeat['replayed'] === true && $repeat['applied'] === 0 && $repeat['actions'] === []
        && $repeat['scoped_receipt'] === $apply['scoped_receipt'] && $repeat['warnings'] === [], 'same request terminal replay is inert');
    $check($after === $read("$phase-stable"), 'repeat preserves complete native observation');
    $oldOptions = array_column($before['tables']['options'], null, 'option_name');
    $newOptions = array_column($after['tables']['options'], null, 'option_name');
    $desired = array_column($source['tables']['options'], null, 'option_name')['wt_iew_advanced_settings'];
    $sourceSettings = unserialize($desired['option_value'], ['allowed_classes' => false]);
    $targetSettings = unserialize($newOptions['wt_iew_advanced_settings']['option_value'], ['allowed_classes' => false]);
    $local = $targetSettings['other_module_key'] ?? null;
    unset($targetSettings['other_module_key']);
    $check($local === ['nested' => 'keep-target-local'], 'nested foreign module settings survive');
    $check(WPrism\Canon::encode($targetSettings) === WPrism\Canon::encode($sourceSettings)
        && $desired['autoload'] === $newOptions['wt_iew_advanced_settings']['autoload'], 'all native settings and autoload match source exactly');
    foreach ($sourceSettings as $key => $value) $check($after['settings'][$key] === $value, 'native getter reopens exact authored setting ' . $key);
    unset($oldOptions['wt_iew_advanced_settings'], $newOptions['wt_iew_advanced_settings']);
    $check($oldOptions === $newOptions, 'all unrelated native option rows survive');
    foreach ($before['tables'] as $name => $rows) {
        if ($name !== 'options') $check($rows === $after['tables'][$name], 'complete native table survives: ' . $name);
    }
    $check($before['files'] === $after['files'], 'all operational export/import files survive');
    $before = $after;
}
$check($before['settings']['wt_iew_auto_delete_history_count'] === 1 && count($before['tables']['wt_iew_action_history']) === 3,
    'applying retention one did not execute operational purge');
$check($read('purge-save')['status'] === true, 'native purge control Save succeeds');
$purged = $read('purge-target');
$check(count($purged['tables']['wt_iew_action_history']) === 1, 'native Save control deletes two completed history rows');
$removed = array_diff_key($before['files'], $purged['files']);
$check(count($removed) === 2 && count(array_diff_key($purged['files'], $before['files'])) === 0,
    'native Save control deletes the two old export files');
$check($purged['tables']['users'] === $before['tables']['users'] && $purged['tables']['usermeta'] === $before['tables']['usermeta'],
    'native purge control leaves users and sessions unchanged');
echo "PASS: importer public settings Apply, repeat, recapture and populated-history purge control\n";

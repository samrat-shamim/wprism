<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once "$root/sandbox/tests/lib/PrivateCommandOutput.php";
require_once "$root/sandbox/tests/lib/PrivateRefusalReceipt.php";
require_once "$root/sandbox/tests/lib/FilesystemTreeEvidence.php";
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
        self::check(in_array($verb, ['capture', 'compile', 'plan', 'apply'], true), 'known wrapped native command');
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

    public static function repositoryRefusal(array $public, string $command): void {
        self::check(in_array($command, ['compile', 'plan', 'apply'], true)
            && ($public['format'] ?? null) === 'wprism-command-refusal/v1'
            && ($public['ok'] ?? null) === false && ($public['command'] ?? null) === $command
            && ($public['reason_code'] ?? null) === 'repository_authorization_failed'
            && ($public['error'] ?? null) === 'repository_authorization_failed'
            && ($public['message'] ?? null) === 'repository authorization refused this command'
            && !isset($public['details_redacted']) && count($public['diagnostics'] ?? []) === 1,
            'one public immutable repository authorization refusal for ' . $command);
        $finding = $public['diagnostics'][0];
        self::check(($finding['code'] ?? null) === 'repository_scalar_value_invalid'
            && ($finding['uuid'] ?? null) === 'options/core' && ($finding['surface'] ?? null) === 'option_sub_key'
            && ($finding['field'] ?? null) === 'wt_iew_advanced_settings.wt_iew_maximum_execution_time'
            && ($finding['classification'] ?? null) === 'malformed', 'exact scalar declaration caused the repository refusal');
    }

    public static function nativeRefusal(array $public, array $baseline, array $diagnostic): void {
        self::check(($public['format'] ?? null) === 'wprism-command-refusal/v1'
            && ($public['ok'] ?? null) === false && ($public['command'] ?? null) === 'capture'
            && ($public['reason_code'] ?? null) === 'capture_failed' && ($public['details_redacted'] ?? null) === true
            && ($public['message'] ?? null) === 'capture refused at an unclassified safety gate', 'existing public Capture redaction boundary');
        self::check(array_keys($baseline) === ['command', 'baseline'] && $baseline['command'] === 'capture', 'exact command freshness baseline');
        WPrismTest\PrivateRefusalReceipt::validateDiagnosticBaseline($baseline['baseline'], 'capture');
        WPrismTest\PrivateRefusalReceipt::verifyDiagnostic($diagnostic, [
            'command' => 'capture', 'reason_code' => 'capture_failed', 'nodes' => [[
                'class' => RuntimeException::class, 'parent_index' => null, 'relation' => 'root',
                'message' => 'wprism: option wt_iew_advanced_settings.wt_iew_maximum_execution_time scalar value constraint requires an integer within its declared inclusive bounds',
            ]],
        ]);
        self::check(!in_array($diagnostic['records'][0]['name'], json_decode($baseline['baseline'], true, 32, JSON_THROW_ON_ERROR), true),
            'exact scalar refusal graph is new to this invocation');
    }

    public static function settings(array $before, array $after, array $desired, string $autoload): void {
        $oldOptions = array_column($before['tables']['options'], null, 'option_name');
        $newOptions = array_column($after['tables']['options'], null, 'option_name');
        $target = unserialize($newOptions['wt_iew_advanced_settings']['option_value'], ['allowed_classes' => false]);
        $local = $target['other_module_key'] ?? null;
        unset($target['other_module_key']);
        self::check($local === ['nested' => 'keep-target-local'], 'nested foreign module settings survive');
        self::check(WPrism\Canon::encode($target) === WPrism\Canon::encode($desired)
            && $autoload === $newOptions['wt_iew_advanced_settings']['autoload'], 'all native settings and autoload match authored intent exactly');
        foreach ($desired as $key => $value) self::check($after['settings'][$key] === $value, 'native getter reopens exact authored setting ' . $key);
        unset($oldOptions['wt_iew_advanced_settings'], $newOptions['wt_iew_advanced_settings']);
        self::check($oldOptions === $newOptions, 'all unrelated native option rows survive');
        self::check(array_keys($before['tables']) === array_keys($after['tables']), 'complete native table roster survives');
        foreach ($before['tables'] as $name => $rows) {
            if ($name !== 'options') self::check($rows === $after['tables'][$name], 'complete native table survives: ' . $name);
        }
        self::check($before['files'] === $after['files'], 'all operational export/import files survive');
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
if (($argv[1] ?? '') === 'admit-command') {
    ImporterSettingsEvidence::command($root, $argv[2], $argv[3], $argv[4] ?? '', (int) ($argv[5] ?? 0));
    exit(0);
}
if (($argv[1] ?? '') === 'snapshot') {
    echo json_encode(WPrismTest\FilesystemTreeEvidence::capture($argv[2], 'state'), JSON_THROW_ON_ERROR), "\n";
    exit(0);
}
$sink = $argv[1];
$pair = $argv[2];
$check = ImporterSettingsEvidence::check(...);
$read = static function (string $name, int $expectedExit = 0, ?string $verb = null) use ($sink, $pair, $root): array {
    $stem = "$sink/$name";
    $verb ??= preg_match('/-(apply|repeat|capture)$/D', $name, $match) === 1 ? $match[1] : '';
    if ($verb === 'repeat') $verb = 'apply';
    ImporterSettingsEvidence::command($root, $stem, $pair, $verb, $expectedExit);
    $stderr = (string) file_get_contents($stem . '.stderr');
    if (str_starts_with($stderr, 'private command diagnostics (unverified): ')) {
        $stem = substr(explode("\n", $stderr, 2)[0], strlen('private command diagnostics (unverified): ')) . '/command';
    }
    return json_decode(WPrismTest\PrivateCommandOutput::readObject($stem,
        '/^ ?Container wprism-' . preg_quote($pair, '/') . '-cli[12]-run-[a-f0-9]{12} (Creating|Created) *$/D', expectedExit: $expectedExit), true, 64, JSON_THROW_ON_ERROR);
};
$before = $read('baseline-target');
$check(count($read('jobs')['jobs']) === 3 && count($before['tables']['wt_iew_action_history']) === 3,
    'three real completed exports precede Apply');
foreach (['compile', 'plan', 'apply'] as $command) ImporterSettingsEvidence::repositoryRefusal($read("repository-$command", 1, $command), $command);
$check($before === $read('repository-target'), 'invalid repository refusals preserve every native table, setting and operational file');
foreach (['repository', 'native-state'] as $case) {
    $prior = $read("$case-before"); $after = $read("$case-after");
    WPrismTest\FilesystemTreeEvidence::assertRecord($prior, 'state');
    WPrismTest\FilesystemTreeEvidence::assertRecord($after, 'state');
    $check($prior === $after && count($prior['files']) > 0, 'refusal preserves the complete nonempty canonical state: ' . $case);
}
$edge = $read('edge-edit');
ImporterSettingsEvidence::settings($before, $read('edge-target'), $edge['settings'], $edge['autoload']);
foreach (['edge', 'restore', 'boundary'] as $phase) {
    $apply = $read("$phase-apply");
    $check($apply['applied'] > 0 && $apply['canary'] === 'clean' && $apply['drift'] === []
        && $apply['warnings'] === [] && $apply['actions'] === [], 'manual settings Apply completes without executable actions: ' . $phase);
}
$check($read('edge-reader') === ['batch' => 1, 'first_rows' => 1, 'second_rows' => 1, 'zero_control_rows' => 3],
    'applied native import batch advances one row while the rejected zero control reads all remaining rows');
$check($read('edge-target') === $read('edge-reader-stable'), 'native reader controls preserve every native table and operational file');
$check($read('boundary-edit')['settings']['wt_iew_default_export_batch'] === 1
    && count($read('boundary-export')['jobs']) === 1, 'applied native export batch one completes one real selected-user export without a batch override');
$read('edge-plan', 0, 'plan');
$check($read('edge-capture')['warnings'] === [], 'manual boundary settings recapture succeeds cleanly');
$check($before === $read('restore-target'), 'restoring original canonical settings restores the complete native observation');
$nativeRefusal = $read('native-capture', 1);
$private = substr(explode("\n", (string) file_get_contents("$sink/native-capture.stderr"), 2)[0], strlen('private command diagnostics (unverified): '));
$transport = '/^ ?Container wprism-' . preg_quote($pair, '/') . '-cli[12]-run-[a-f0-9]{12} (Creating|Created) *$/D';
ImporterSettingsEvidence::nativeRefusal($nativeRefusal,
    json_decode(WPrismTest\PrivateCommandOutput::readObject($private . '/baseline', $transport), true, 32, JSON_THROW_ON_ERROR),
    json_decode(WPrismTest\PrivateCommandOutput::readObject($private . '/private', $transport), true, 32, JSON_THROW_ON_ERROR));
$check($read('native-before')['settings']['wt_iew_maximum_execution_time'] === 'invalid-seconds'
    && $read('native-before') === $read('native-after'), 'Capture refuses the seeded invalid native scalar without any native mutation');
$check($read('baseline-source') === $read('restored-source'), 'fixture restoration returns source to its complete original native observation');
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
    $desired = array_column($source['tables']['options'], null, 'option_name')['wt_iew_advanced_settings'];
    $sourceSettings = unserialize($desired['option_value'], ['allowed_classes' => false]);
    ImporterSettingsEvidence::settings($before, $after, $sourceSettings, $desired['autoload']);
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
echo "PASS: importer scalar refusals, manual boundary values, public settings Apply, repeat, recapture and populated-history purge control\n";

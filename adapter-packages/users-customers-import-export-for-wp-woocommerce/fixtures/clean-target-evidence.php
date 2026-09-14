<?php
declare(strict_types=1);

require_once __DIR__ . '/roundtrip-evidence.php';

final class ImporterCleanTargetEvidence {
    public static function empty(array $record): void {
        ImporterSettingsEvidence::check(($record['format'] ?? null) === 'wprism-importer-native-settings/v1'
            && array_keys($record['tables'] ?? []) === ['posts', 'postmeta', 'options', 'terms', 'term_taxonomy',
                'term_relationships', 'termmeta', 'users', 'usermeta', 'wt_iew_action_history', 'wt_iew_mapping_template'], 'complete clean-target native census');
        ImporterSettingsEvidence::check($record['tables']['wt_iew_mapping_template'] === []
            && $record['tables']['wt_iew_action_history'] === [] && count($record['tables']['users']) >= 1, 'empty template/history tables with an installed administrator');
    }

    public static function pristine(array $record): void {
        self::empty($record);
        ImporterSettingsEvidence::check(count($record['tables']['users']) === 1
            && $record['tables']['users'][0]['user_login'] === 'admin', 'fresh target contains only its bootstrap administrator');
        foreach ($record['files'] as $name => $hash) ImporterSettingsEvidence::check(
            preg_match('~^webtoffee_(?:import|export)/(?:index\.php|\.htaccess)$~D', $name) === 1
            && preg_match('/^[a-f0-9]{64}$/D', $hash) === 1, 'no pre-existing native job files');
    }

    public static function prepared(array $record, array $prerequisites, array $pristine): void {
        self::pristine($pristine);
        self::empty($record);
        $users = array_column($record['tables']['users'], 'ID', 'user_login');
        ImporterSettingsEvidence::check(count($users) === 4 && isset($users['admin'])
            && array_diff_key($users, ['admin' => true]) === $prerequisites['users'], 'only the three explicit user prerequisites were provisioned');
        ImporterSettingsEvidence::check(($record['files']['webtoffee_import/target-input.csv'] ?? null) === $prerequisites['input_sha256']
            && preg_match('/^[a-f0-9]{64}$/D', $prerequisites['input_sha256']) === 1
            && str_ends_with($prerequisites['input'], '/webtoffee_import/target-input.csv'), 'exact independently provisioned target CSV');
        $expectedFiles = $pristine['files'];
        // Locked 2.7.5 admin/modules/import/import.php:766-779 creates these
        // absent protection files when get_file_path opens the CSV directory.
        foreach (['index.php' => "<?php\n// Silence is golden", '.htaccess' => 'deny from all'] as $name => $bytes) {
            $expectedFiles['webtoffee_import/' . $name] ??= hash('sha256', $bytes);
        }
        $expectedFiles['webtoffee_import/target-input.csv'] = $prerequisites['input_sha256'];
        $actualFiles = $record['files'];
        ksort($expectedFiles); ksort($actualFiles);
        ImporterRoundtripEvidence::same($expectedFiles, $actualFiles, 'prerequisites add only the CSV and exact missing protection files, preserving existing files');
    }

    public static function form(array $source, array $after, string $type, string $name, string $input): array {
        $from = ImporterRoundtripEvidence::row($source, $type, $name);
        $to = ImporterRoundtripEvidence::row($after, $type, $name);
        ImporterSettingsEvidence::check(preg_match('/^[1-9][0-9]*$/D', $to['id']) === 1, 'positive target template identity');
        ImporterRoundtripEvidence::same(array_diff_key($from, ['id' => true, 'data' => true]),
            array_diff_key($to, ['id' => true, 'data' => true]), 'complete created template metadata');
        ImporterRoundtripEvidence::same(ImporterRoundtripEvidence::portable($from, $source['tables']['users']),
            ImporterRoundtripEvidence::portable($to, $after['tables']['users']), 'complete target-local authored form');
        $form = json_decode($to['data'], true, 32, JSON_THROW_ON_ERROR);
        ImporterSettingsEvidence::check(!isset($form['method_' . $type . '_form_data']['selected_template']), 'created/updated form excludes the source cursor');
        if ($type === 'import') ImporterSettingsEvidence::check($form['method_import_form_data']['wt_iew_local_file']
            === ($name === 'Draft input mapping' ? '' : $input), 'created form uses the exact target CSV or empty draft');
        if ($type === 'export') foreach ($form['filter_form_data']['wt_iew_email'] as $id) ImporterSettingsEvidence::check(
            is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id) === 1, 'created form retains native string user references');
        return $to;
    }

    public static function created(array $source, array $before, array $after, array $prerequisites, array $pristine): void {
        self::prepared($before, $prerequisites, $pristine);
        ImporterSettingsEvidence::check(count($source['tables']['wt_iew_mapping_template']) === 5
            && count($after['tables']['wt_iew_mapping_template']) === 5, 'all five templates are created without adoption');
        $ids = [];
        foreach (['export' => ['Selected users', 'Selected users copy'],
            'import' => ['Reusable input mapping', 'Reusable input copy', 'Draft input mapping']] as $type => $names) {
            foreach ($names as $name) $ids[] = self::form($source, $after, $type, $name, $prerequisites['input'])['id'];
        }
        ImporterSettingsEvidence::check(count(array_unique($ids)) === 5, 'five distinct created identities');
        $after['tables']['wt_iew_mapping_template'] = [];
        [$before, $after] = ImporterRoundtripEvidence::withoutCoreApplyEffects($source, $before, $after);
        $old = array_column($before['tables']['options'], null, 'option_name');
        $new = array_column($after['tables']['options'], null, 'option_name');
        $sourceOptions = array_column($source['tables']['options'], null, 'option_name');
        $setting = $new['wt_iew_advanced_settings'];
        ImporterSettingsEvidence::check(count($source['settings']) === 9 && is_string($setting['option_id'])
            && preg_match('/^[1-9][0-9]*$/D', $setting['option_id']) === 1, 'nine settings in one positive native option row');
        ImporterRoundtripEvidence::same($source['settings'], unserialize($setting['option_value'], ['allowed_classes' => false]), 'all nine authored settings reach the fresh target');
        ImporterRoundtripEvidence::same($source['settings'], $after['settings'], 'raw settings readback is exact');
        ImporterSettingsEvidence::check($setting['autoload'] === ($old['wt_iew_advanced_settings']['autoload']
            ?? $sourceOptions['wt_iew_advanced_settings']['autoload']), 'settings preserve existing autoload or create the source value');
        if (isset($old['wt_iew_advanced_settings'])) ImporterSettingsEvidence::check($setting['option_id'] === $old['wt_iew_advanced_settings']['option_id'], 'existing settings row identity is retained');
        else ImporterSettingsEvidence::check(!in_array($setting['option_id'], array_column($old, 'option_id'), true), 'created settings row has a distinct identity');
        unset($old['wt_iew_advanced_settings'], $new['wt_iew_advanced_settings']);
        ImporterRoundtripEvidence::same($old, $new, 'all unrelated options survive creation');
        unset($before['tables']['options'], $after['tables']['options']);
        ImporterRoundtripEvidence::same($before['tables'], $after['tables'], 'all other complete native tables survive creation');
        ImporterRoundtripEvidence::same($before['files'], $after['files'], 'creation does not run jobs or alter files');
        ImporterRoundtripEvidence::same(array_diff_key($before, ['tables' => true, 'settings' => true, 'files' => true]),
            array_diff_key($after, ['tables' => true, 'settings' => true, 'files' => true]), 'complete native observation metadata agrees');
    }

    public static function updated(array $source, array $before, array $after, string $input): void {
        $old = ImporterRoundtripEvidence::row($before, 'export', 'Selected users');
        $new = self::form($source, $after, 'export', 'Renamed selection', $input);
        ImporterSettingsEvidence::check($new['id'] === $old['id'] && $new !== $old, 'native update retains its created target identity');
        foreach ($after['tables']['wt_iew_mapping_template'] as &$row) if ($row['id'] === $new['id']) $row = $old;
        unset($row);
        ImporterRoundtripEvidence::same($before, $after, 'update preserves every other native value, table and operational file');
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
$mode = $argv[1]; $directory = $argv[2]; $pair = $argv[3];
$read = static function (string $label) use ($directory, $pair): array {
    $stem = $directory . '/' . $label;
    ImporterSettingsEvidence::command(dirname(__DIR__, 3), $stem, $pair);
    return json_decode(WPrismTest\PrivateCommandOutput::readObject($stem,
        '/^ ?Container wprism-' . preg_quote($pair, '/') . '-cli[12]-run-[a-f0-9]{12} (Creating|Created) *$/D'), true, 32, JSON_THROW_ON_ERROR);
};
if ($mode === 'pristine') ImporterCleanTargetEvidence::pristine($read('pristine'));
elseif ($mode === 'prepared') ImporterCleanTargetEvidence::prepared($read('before'), $read('prerequisites'), $read('pristine'));
elseif ($mode === 'created') ImporterCleanTargetEvidence::created($read('source-enrolled'), $read('before'), $read('after'), $read('prerequisites'), $read('pristine'));
elseif ($mode === 'updated') ImporterCleanTargetEvidence::updated($read('updated-source'), $read('update-before'), $read('update-after'), $read('prerequisites')['input']);
elseif ($mode === 'repeat') ImporterRoundtripEvidence::same($read($argv[4]), $read($argv[5]), 'repeated Apply has no native mutation');
elseif ($mode === 'finished') {
    foreach (['update-capture', 'updated-capture'] as $label) {
        $capture = $read($label);
        ImporterSettingsEvidence::check($capture['warnings'] === [] && $capture['counts']['wt_iew_mapping_template'] === 5, 'complete warning-free update Capture');
    }
    ImporterSettingsEvidence::check($read('updated-consume')['job']['records'][0] === ['user_login', 'user_email', 'Renamed_Display'], 'native exporter consumes the updated header');
}
else throw new RuntimeException('unknown clean-target evidence mode');
echo 'PASS: Importer clean target ' . $mode . "\n";

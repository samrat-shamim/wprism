<?php
declare(strict_types=1);

require_once __DIR__ . '/settings-evidence.php';

final class ImporterRoundtripEvidence {
    public static function same(array $expected, array $actual, string $why): void {
        ImporterSettingsEvidence::check(WPrism\Canon::encode($expected) === WPrism\Canon::encode($actual), $why);
    }

    public static function row(array $record, string $type, string $name): array {
        $rows = array_values(array_filter($record['tables']['wt_iew_mapping_template'], static fn(array $row): bool =>
            $row['template_type'] === $type && $row['item_type'] === 'user' && $row['name'] === $name));
        ImporterSettingsEvidence::check(count($rows) === 1, 'one native ' . $type . ' template ' . $name);
        return $rows[0];
    }

    public static function portable(array $row, array $users): array {
        $form = json_decode($row['data'], true, 32, JSON_THROW_ON_ERROR);
        $type = $row['template_type'];
        unset($form['method_' . $type . '_form_data']['selected_template']);
        if ($type === 'export') {
            $logins = array_column($users, 'user_login', 'ID');
            $form['filter_form_data']['wt_iew_email'] = array_map(static function (string $id) use ($logins): string {
                ImporterSettingsEvidence::check(isset($logins[$id]), 'selected user has an exact local login binding');
                return $logins[$id];
            }, $form['filter_form_data']['wt_iew_email']);
        } elseif ($form['method_import_form_data']['wt_iew_local_file'] !== '') {
            $form['method_import_form_data']['wt_iew_local_file'] = ['environment' => 'input_file'];
        }
        return $form;
    }

    public static function coreWidgets(array $source, array $before, array $after): array {
        $check = ImporterSettingsEvidence::check(...);
        foreach (['widget_block', 'widget_text', 'sidebars_widgets'] as $name) {
            $check($source[$name]['option_value'] === $before[$name]['option_value'], 'known identical default widget preimages');
        }
        $decode = static fn(string $bytes): array => unserialize($bytes, ['allowed_classes' => false]);
        $sourceBlocks = $decode($source['widget_block']['option_value']);
        $targetBlocks = $decode($after['widget_block']['option_value']);
        $check(($sourceBlocks['_multiwidget'] ?? null) === 1 && ($targetBlocks['_multiwidget'] ?? null) === 1, 'exact native widget markers');
        unset($sourceBlocks['_multiwidget'], $targetBlocks['_multiwidget']);
        $check(count($sourceBlocks) === 5 && count($targetBlocks) === 5, 'five complete default block widgets');
        self::same(array_values($sourceBlocks), array_values($targetBlocks), 'all default widget bodies survive in order');
        $bindings = [];
        foreach (array_keys($sourceBlocks) as $index => $id) {
            $targetId = array_keys($targetBlocks)[$index];
            $check(is_int($id) && $id > 0 && is_int($targetId) && $targetId > 0, 'positive local widget identities');
            $bindings['block-' . $id] = 'block-' . $targetId;
        }
        $sidebar = $decode($source['sidebars_widgets']['option_value']);
        $check(array_keys($sidebar) === ['wp_inactive_widgets', 'sidebar-1', 'array_version']
            && $sidebar['wp_inactive_widgets'] === [] && $sidebar['array_version'] === 3
            && $sidebar['sidebar-1'] === array_keys($bindings), 'exact default sidebar order');
        $sidebar['sidebar-1'] = array_values($bindings);
        self::same($sidebar, $decode($after['sidebars_widgets']['option_value']), 'sidebar references follow the corresponding target widget IDs');
        $check($decode($source['widget_text']['option_value']) === []
            && $decode($after['widget_text']['option_value']) === ['_multiwidget' => 1], 'empty text-family native marker materialization');
        return ['widget_block' => $after['widget_block']['option_value'],
            'sidebars_widgets' => serialize($sidebar), 'widget_text' => serialize(['_multiwidget' => 1])];
    }

    public static function applied(array $source, array $before, array $after): void {
        $check = ImporterSettingsEvidence::check(...);
        $tables = ['posts', 'postmeta', 'options', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta',
            'users', 'usermeta', 'wt_iew_action_history', 'wt_iew_mapping_template'];
        foreach ([$source, $before, $after] as $record) $check(($record['format'] ?? '') === 'wprism-importer-native-settings/v1'
            && array_keys($record['tables'] ?? []) === $tables, 'complete native table roster');
        $check(count($source['tables']['wt_iew_mapping_template']) === 5 && count($before['tables']['wt_iew_mapping_template']) === 4
            && count($after['tables']['wt_iew_mapping_template']) === 7 && count($before['tables']['users']) >= 8
            && count($before['tables']['wt_iew_action_history']) === 4 && count($before['files']) >= 6, 'non-vacuous source and dirty target witnesses');
        $targetInput = json_decode(self::row($before, 'import', 'Reusable input mapping')['data'], true, 32, JSON_THROW_ON_ERROR)
            ['method_import_form_data']['wt_iew_local_file'];
        $check(is_string($targetInput) && str_ends_with($targetInput, '/target-input.csv'), 'independently prepared target CSV');
        $ids = [];
        foreach (['export' => ['Selected users', 'Selected users copy'],
            'import' => ['Reusable input mapping', 'Reusable input copy', 'Draft input mapping']] as $type => $names) {
            foreach ($names as $index => $name) {
                $from = self::row($source, $type, $name);
                $to = self::row($after, $type, $name);
                $check($from['id'] !== $to['id'] && preg_match('/^[1-9][0-9]*$/D', $to['id']) === 1, 'target-local template identity');
                if ($index === 0) $check($to['id'] === self::row($before, $type, $name)['id'], 'explicit adoption preserves the existing target row');
                $ids[] = $to['id'];
                self::same(array_diff_key($from, ['id' => true, 'data' => true]), array_diff_key($to, ['id' => true, 'data' => true]), 'complete native template row metadata');
                self::same(self::portable($from, $source['tables']['users']), self::portable($to, $after['tables']['users']), 'complete authored template form');
                $form = json_decode($to['data'], true, 32, JSON_THROW_ON_ERROR);
                $check(!isset($form['method_' . $type . '_form_data']['selected_template']), 'source wizard cursor is excluded');
                if ($type === 'export') {
                    // Login normalization alone also admits coincident IDs and
                    // integer references; neither proves the declared string user[] transport.
                    $sourceIds = json_decode($from['data'], true, 32, JSON_THROW_ON_ERROR)['filter_form_data']['wt_iew_email'];
                    $targetIds = $form['filter_form_data']['wt_iew_email'];
                    foreach ([$sourceIds, $targetIds] as $references) $check(is_array($references) && array_is_list($references)
                        && count($references) === 2 && count(array_unique($references)) === 2
                        && count(array_filter($references, static fn($id): bool => is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id) === 1)) === 2,
                        'two distinct native string user references');
                    $check(array_intersect($sourceIds, $targetIds) === [], 'every selected user ID differs across environments');
                }
                if ($type === 'import') $check($form['method_import_form_data']['wt_iew_local_file'] === ($index === 2 ? '' : $targetInput), 'exact local CSV or blank draft');
            }
        }
        $check(count(array_unique($ids)) === 5, 'all five target identities are distinct');
        $foreign = static fn(array $record): array => array_values(array_filter($record['tables']['wt_iew_mapping_template'],
            static fn(array $row): bool => $row['item_type'] !== 'user' || !in_array($row['template_type'], ['import', 'export'], true)));
        $check(count($foreign($before)) === 2, 'two excluded native templates are present');
        self::same($foreign($before), $foreign($after), 'excluded template bytes survive');
        // Full core Apply binds default widgets to new local IDs. Prove their
        // complete bodies and ordered references before comparing all options.
        $normalizedAfter = $after;
        $normalizedAfter['tables']['wt_iew_mapping_template'] = $before['tables']['wt_iew_mapping_template'];
        // The fresh target adopts the one default core category. Its identity
        // must be the exact source Capture UUID; all other metadata stays local.
        $sourceIdentity = array_values(array_filter($source['tables']['termmeta'], static fn(array $r): bool => $r['meta_key'] === '_wprism_uuid'));
        $targetIdentity = array_values(array_filter($after['tables']['termmeta'], static fn(array $r): bool => ($r['meta_key'] ?? '') === '_wprism_uuid'));
        $check(count($sourceIdentity) === 1 && count($targetIdentity) === 1
            && preg_match('/^[1-9][0-9]*$/D', $targetIdentity[0]['meta_id']) === 1, 'one enrolled core term identity');
        foreach ([$source, $before, $after] as $record) $check($record['tables']['terms'] === $source['tables']['terms']
            && count($record['tables']['terms']) === 1 && $record['tables']['terms'][0]['slug'] === 'uncategorized', 'one unchanged default category');
        self::same(array_diff_key($sourceIdentity[0], ['meta_id' => true]), array_diff_key($targetIdentity[0], ['meta_id' => true]), 'adopted default category uses the source UUID');
        $check($targetIdentity[0]['term_id'] === $before['tables']['terms'][0]['term_id'], 'category identity points to the retained target row');
        $normalizedAfter['tables']['termmeta'] = array_values(array_filter($after['tables']['termmeta'], static fn(array $r): bool => ($r['meta_key'] ?? '') !== '_wprism_uuid'));

        $sourceOptions = array_column($source['tables']['options'], null, 'option_name');
        $coreValues = self::coreWidgets($sourceOptions, array_column($before['tables']['options'], null, 'option_name'),
            array_column($after['tables']['options'], null, 'option_name'));
        $coreValues['blogname'] = $sourceOptions['blogname']['option_value'];
        foreach ($before['tables']['options'] as &$option) {
            if (array_key_exists($option['option_name'], $coreValues)) $option['option_value'] = $coreValues[$option['option_name']];
        }
        unset($option);
        ImporterSettingsEvidence::settings($before, $normalizedAfter, $source['settings'], $sourceOptions['wt_iew_advanced_settings']['autoload']);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
$mode = $argv[1] ?? '';
$path = $argv[2];
$pair = $argv[3];
$read = static function (string $stem) use ($pair): array {
    ImporterSettingsEvidence::command(dirname(__DIR__, 3), $stem, $pair);
    return json_decode(WPrismTest\PrivateCommandOutput::readObject($stem,
        '/^ ?Container wprism-' . preg_quote($pair, '/') . '-cli[12]-run-[a-f0-9]{12} (Creating|Created) *$/D'), true, 32, JSON_THROW_ON_ERROR);
};
if ($mode === 'admit') { $read($path); return; }
if ($mode === 'applied') ImporterRoundtripEvidence::applied($read($path . '/source-enrolled'), $read($path . '/before'), $read($path . '/after'));
elseif ($mode === 'consumers') {
    foreach (['export-original', 'export-copy'] as $label) {
        $job = $read($path . '/' . $label . '-consume')['job'];
        ImporterSettingsEvidence::check($job['records'][0] === ['user_login', 'user_email', 'Display_Name'] && count($job['records']) === 3, 'native CSV uses authored headers and both selected users');
        foreach (array_slice($job['records'], 1) as $row) ImporterSettingsEvidence::check(str_ends_with($row[1], '-target@example.test') && str_starts_with($row[2], 'Target '), 'native export consumes target-local profiles');
    }
    foreach (['import-original', 'import-copy'] as $label) ImporterSettingsEvidence::check($read($path . '/' . $label . '-consume')['display_name'] === 'Target input', 'native import consumes the bound target CSV');
    $capture = $read($path . '/consumed-capture');
    ImporterSettingsEvidence::check($capture['warnings'] === [] && $capture['counts']['wt_iew_mapping_template'] === 5, 'all five templates recapture without warnings after native consumption');
} else throw new RuntimeException('unknown Importer roundtrip evidence mode');
echo 'PASS: Importer roundtrip ' . $mode . "\n";

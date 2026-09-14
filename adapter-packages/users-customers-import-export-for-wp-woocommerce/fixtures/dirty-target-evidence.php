<?php
declare(strict_types=1);

require_once __DIR__ . '/clean-target-evidence.php';
require_once $root . '/sandbox/tests/lib/SqlDumpEvidence.php';
require_once $root . '/agent/src/Repository/Snapshot.php';
require_once $root . '/agent/src/Kernel/Uuid.php';

use WPrismTest\PrivateCommandOutput;
use WPrismTest\PrivateRefusalReceipt;
use WPrismTest\FilesystemTreeEvidence;
use WPrismTest\SqlDumpEvidence;
use WPrismTest\EvidenceSizeProfile;

final class ImporterDirtyTargetEvidence {
    public static function native(array $record): void {
        ImporterSettingsEvidence::check(($record['format'] ?? null) === 'wprism-importer-native-settings/v1'
            && array_keys($record['tables'] ?? []) === ['posts', 'postmeta', 'options', 'terms', 'term_taxonomy',
                'term_relationships', 'termmeta', 'users', 'usermeta', 'wt_iew_action_history', 'wt_iew_mapping_template']
            && count($record['settings'] ?? []) === 9 && count($record['files'] ?? []) >= 3
            && count($record['tables']['users']) >= 4 && count($record['tables']['wt_iew_action_history']) >= 1,
            'complete populated native census');
    }

    public static function retained(array $before, array $after, array $oldMap, array $newMap, array $tree): void {
        self::native($before); self::native($after);
        $rows = $before['tables']['wt_iew_mapping_template'];
        ImporterSettingsEvidence::check(count($rows) === 5 && array_keys($oldMap) === ['identities']
            && array_is_list($oldMap['identities']) && count($oldMap['identities']) === 5, 'five captured source identities');
        ImporterRoundtripEvidence::same($oldMap, $newMap, 'native rename and recapture retain every durable identity');
        $decl = json_decode(file_get_contents(__DIR__ . '/../package/manifest.json'), true, flags: JSON_THROW_ON_ERROR)['tables']['wt_iew_mapping_template'];
        $byId = []; $uuids = [];
        foreach ($oldMap['identities'] as $identity) {
            $id = $identity['local_id']; $uuid = $identity['uuid'];
            ImporterSettingsEvidence::check(is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id) === 1
                && $identity['entity_type'] === 'wt_iew_mapping_template' && $identity['id_kind'] === 'iew_template'
                && WPrism\Uuid::is($uuid) && !isset($byId[$id]) && !isset($uuids[$uuid]), 'unique exact durable source coordinates');
            $byId[$id] = $uuid; $uuids[$uuid] = true;
        }
        $expected = $before; $renamed = []; $currentRows = [];
        foreach ($expected['tables']['wt_iew_mapping_template'] as &$row) {
            ImporterSettingsEvidence::check($row['item_type'] === 'user' && in_array($row['template_type'], ['import', 'export'], true), 'owned native source row');
            $uuid = $byId[$row['id']] ?? null;
            $derived = WPrism\Uuid::v5(WPrism\Uuid::NAMESPACE_WPRISM, WPrism\Snapshot::natural_key_name('wt_iew_mapping_template', $decl, $row));
            ImporterSettingsEvidence::check($uuid === $derived, 'initial Capture enrolled the exact historical natural key');
            $name = $row['template_type'] === 'export' ? 'Selected users' : 'Reusable input mapping';
            if ($row['name'] === 'Historical ' . $name) {
                $row['name'] = $name;
                $fresh = WPrism\Uuid::v5(WPrism\Uuid::NAMESPACE_WPRISM, WPrism\Snapshot::natural_key_name('wt_iew_mapping_template', $decl, $row));
                ImporterSettingsEvidence::check($uuid !== $fresh, 'current key on a fresh target differs from the retained UUID');
                $renamed[$row['template_type']] = true;
            }
            $currentRows[$uuid] = $row;
        }
        unset($row);
        ImporterSettingsEvidence::check(count($renamed) === 2, 'both import and export originals were genuinely renamed');
        ImporterRoundtripEvidence::same($expected, $after, 'native Save changes only the two names and retains all source rows, forms and files');
        FilesystemTreeEvidence::assertRecord($tree, 'state');
        $captured = [];
        foreach ($tree['files'] as $file) {
            if (!str_starts_with($file['path'], 'tables/wt_iew_mapping_template/')) continue;
            $front = json_decode(base64_decode($file['contents_base64'], true), true, flags: JSON_THROW_ON_ERROR);
            $uuid = $front['uuid'];
            ImporterSettingsEvidence::check(isset($currentRows[$uuid]) && !isset($captured[$uuid])
                && $front['table'] === 'wt_iew_mapping_template', 'recapture retains the exact source UUID roster');
            foreach (['name', 'item_type', 'template_type'] as $column) ImporterSettingsEvidence::check(
                $front['columns'][$column] === $currentRows[$uuid][$column], 'recapture publishes the current native identity component');
            $captured[$uuid] = true;
        }
        ImporterSettingsEvidence::check(count($captured) === 5, 'all five templates appear in recaptured canonical state');
    }

    public static function selected(array $tree, array $native, string $case): array {
        self::native($native);
        ImporterSettingsEvidence::check(count($native['tables']['wt_iew_mapping_template']) === ($case === 'collision' ? 4 : 7), 'complete dirty template roster');
        FilesystemTreeEvidence::assertRecord($tree, 'state');
        $selected = [];
        foreach ($tree['files'] as $file) {
            if (!str_starts_with($file['path'], 'tables/wt_iew_mapping_template/')) continue;
            $record = json_decode(base64_decode($file['contents_base64'], true), true, flags: JSON_THROW_ON_ERROR);
            $columns = $record['columns'];
            $type = $columns['template_type'];
            $name = $type === 'export' ? 'Selected users' : 'Reusable input mapping';
            if ($columns['name'] !== $name) continue;
            ImporterSettingsEvidence::check(in_array($type, ['import', 'export'], true) && $columns['item_type'] === 'user'
                && $record['table'] === 'wt_iew_mapping_template' && !isset($selected[$record['uuid']]), 'unique captured owned table identity');
            $row = ImporterRoundtripEvidence::row($native, $type, $name);
            $selected[$record['uuid']] = ['path' => $file['path'], 'id' => $row['id']];
        }
        ImporterSettingsEvidence::check(count($selected) === 2, 'both exact same-key native originals are present');
        if ($case !== 'collision') $selected['options/core'] = ['path' => 'options/core.json'];
        return $selected;
    }

    public static function plan(array $plan, array $selected, string $case): void {
        ImporterSettingsEvidence::check(in_array($case, ['collision', 'drift', 'conflict'], true), 'known dirty-target decision');
        foreach (['code_mismatch', 'code_drift', 'incomplete_apply', 'incomplete_lifecycle', 'missing_user',
            'skipped_user_meta', 'uploads_inventory', 'selected_actions', 'regen_pending', 'regen_context',
            'env_missing', 'warnings', 'provider_problems'] as $field) {
            ImporterSettingsEvidence::check(($plan[$field] ?? null) === [], 'no ancillary plan findings in ' . $field);
        }
        $actual = [];
        foreach ($plan[$case] ?? [] as $row) {
            $uuid = $row['uuid'];
            ImporterSettingsEvidence::check(isset($selected[$uuid]) && !isset($actual[$uuid])
                && $row['path'] === $selected[$uuid]['path'], 'exact independently captured plan identity');
            if ($case === 'collision') ImporterSettingsEvidence::check(is_int($row['env_id'])
                && (string) $row['env_id'] === $selected[$uuid]['id'] && $row['type'] === 'wt_iew_mapping_template', 'collision names the independent native row');
            if ($case === 'conflict') {
                $view = $row['conflict_view'];
                $hashes = array_map(static fn(string $part): mixed => $view[$part]['content_hash'], ['base', 'repository', 'target']);
                foreach ($hashes as $hash) ImporterSettingsEvidence::check(is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash) === 1, 'complete conflict hashes');
                ImporterSettingsEvidence::check(count(array_unique($hashes)) === 3
                    && $view['reason_code'] === 'repository_and_target_changed_since_base'
                    && $view['recommended_choice'] === 'reconcile_in_repository'
                    && $view['choices'][1]['requires'] === ['--force-theirs'], 'three distinct intents require explicit reconciliation');
            }
            $actual[$uuid] = true;
        }
        $ids = array_keys($selected); $seen = array_keys($actual); sort($ids); sort($seen);
        ImporterSettingsEvidence::check($ids === $seen, 'complete expected decision inventory');
        foreach (['create', 'update', 'adopt', 'collision', 'drift', 'conflict', 'delete', 'delete_conflict', 'deleted'] as $bucket) {
            if ($bucket === $case || ($case === 'collision' && in_array($bucket, ['create', 'update', 'adopt'], true))) continue;
            ImporterSettingsEvidence::check(($plan[$bucket] ?? null) === [], 'no hidden work in ' . $bucket);
        }
    }

    public static function profile(string $case, array $plan): array {
        $paths = array_column($plan[$case], 'path');
        $message = match ($case) {
            'collision' => "wprism: slug collisions need explicit resolution (--adopt-by-slug=posts,terms,menus,tables adopts unmanaged rows):\n  - "
                . implode("\n  - ", array_map(static fn(array $row): string => "{$row['type']} {$row['path']} collides with env id {$row['env_id']} (same slug, different/no uuid)", $plan[$case])),
            'drift' => "wprism: ordinary target drift requires capture/reconciliation before apply; no target mutation attempted:\n  - " . implode("\n  - ", $paths),
            'conflict' => "wprism: conflicts (env and repo both changed since last sync) — capture first or --force-theirs:\n  - " . implode("\n  - ", $paths),
        };
        return ['command' => 'apply', 'reason_code' => 'apply_refused', 'nodes' => [[
            'class' => 'WPrism\\CommandRefusalException', 'parent_index' => null, 'relation' => 'root', 'message' => $message,
        ]]];
    }

    public static function edited(array $before, array $after, string $side): void {
        self::native($before); self::native($after);
        ImporterSettingsEvidence::check(in_array($side, ['source', 'target'], true), 'native edit side');
        $expected = $before;
        $settings = json_decode(file_get_contents(__DIR__ . '/native-settings.json'), true, flags: JSON_THROW_ON_ERROR)['source'];
        $settings['wt_iew_default_import_batch'] = $side === 'source' ? 19 : 29;
        $expected['settings'] = $settings;
        foreach ($expected['tables']['options'] as &$row) if ($row['option_name'] === 'wt_iew_advanced_settings') {
            $old = unserialize($row['option_value'], ['allowed_classes' => false]);
            $row['option_value'] = serialize(array_replace($old, $settings));
        }
        unset($row);
        foreach (['export' => 'Selected users', 'import' => 'Reusable input mapping'] as $type => $name) {
            $old = ImporterRoundtripEvidence::row($before, $type, $name);
            $new = ImporterRoundtripEvidence::row($after, $type, $name);
            $form = json_decode($old['data'], true, flags: JSON_THROW_ON_ERROR);
            $form['advanced_form_data']['wt_iew_batch_count'] = $side === 'source' ? 7 : 11;
            $form['method_' . $type . '_form_data']['selected_template'] = $old['id'];
            ImporterRoundtripEvidence::same($form, json_decode($new['data'], true, flags: JSON_THROW_ON_ERROR), 'native Save changes only the chosen field and local cursor');
            ImporterRoundtripEvidence::same(array_diff_key($old, ['data' => true]), array_diff_key($new, ['data' => true]), 'native edit retains all row identity metadata');
            foreach ($expected['tables']['wt_iew_mapping_template'] as &$row) if ($row['id'] === $old['id']) $row = $new;
            unset($row);
        }
        ImporterRoundtripEvidence::same($expected, $after, 'three native Saves preserve every other native table, field and operational file');
    }

    public static function resolved(array $source, array $before, array $after): void {
        self::native($source); self::native($before); self::native($after);
        $expected = $before;
        $expected['settings'] = $source['settings'];
        foreach ($expected['tables']['options'] as &$row) if ($row['option_name'] === 'wt_iew_advanced_settings') {
            $row['option_value'] = serialize(array_replace(unserialize($row['option_value'], ['allowed_classes' => false]), $source['settings']));
        }
        unset($row);
        foreach (['export' => 'Selected users', 'import' => 'Reusable input mapping'] as $type => $name) {
            $old = ImporterRoundtripEvidence::row($before, $type, $name);
            $input = json_decode($old['data'], true, flags: JSON_THROW_ON_ERROR)['method_import_form_data']['wt_iew_local_file'] ?? '';
            $new = ImporterCleanTargetEvidence::form($source, $after, $type, $name, $input);
            ImporterSettingsEvidence::check($old['id'] === $new['id'], 'explicit resolution retains the managed local ID');
            foreach ($expected['tables']['wt_iew_mapping_template'] as &$row) if ($row['id'] === $old['id']) $row = $new;
            unset($row);
        }
        ImporterRoundtripEvidence::same($expected, $after, 'resolution replaces only the three conflicted authored values');
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
[$mode, $sink, $pair] = array_slice($argv, 1);
$transport = '/^ ?Container wprism-' . preg_quote($pair, '/') . '-cli[12]-run-[a-f0-9]{12} (Creating|Created) *$/D';
$read = static fn(string $label): array => json_decode(PrivateCommandOutput::readObject($sink . '/' . $label, $transport), true, 32, JSON_THROW_ON_ERROR);
if ($mode === 'retained') ImporterDirtyTargetEvidence::retained($read('identity-before-native'), $read('identity-after-native'),
    $read('identity-before-map'), $read('identity-after-map'), $read('identity-after-state')['state']);
elseif ($mode === 'edited') ImporterDirtyTargetEvidence::edited($read($argv[4]), $read($argv[5]), $argv[6]);
elseif ($mode === 'resolved') ImporterDirtyTargetEvidence::resolved($read('dirty-source-after'), $read('conflict-after-native'), $read('resolved-native'));
elseif ($mode === 'refusal') {
    $case = $argv[4];
    $images = [];
    foreach (['before', 'after'] as $when) {
        $stem = "$sink/$case-$when";
        $tables = PrivateCommandOutput::readBytes($stem . '-tables', $transport);
        $dump = PrivateCommandOutput::readBytes($stem . '-database', $transport, EvidenceSizeProfile::CONFORMANCE_TREE);
        SqlDumpEvidence::assertComplete($dump, SqlDumpEvidence::tables($tables), array_merge(
            ['wp_options', 'wp_users', 'wp_wt_iew_mapping_template', 'wp_wt_iew_action_history', 'wp_wprism_kv'],
            $case === 'collision' ? [] : ['wp_wprism_map', 'wp_wprism_state']));
        $state = $read("$case-$when-state");
        foreach (['state' => 'state', 'policy' => 'site.wprism.json'] as $key => $relative) FilesystemTreeEvidence::assertRecord($state[$key], $relative);
        $native = $read("$case-$when-native");
        $images[$when] = [$tables, $dump, $state, $native];
    }
    ImporterSettingsEvidence::check($images['before'] === $images['after'], 'plan and refusal preserve complete database, canonical tree, policy and native files');
    $plan = $read("$case-plan");
    ImporterDirtyTargetEvidence::plan($plan, ImporterDirtyTargetEvidence::selected($images['before'][2]['state'], $images['before'][3], $case), $case);
    $stem = "$sink/$case-refusal";
    ImporterSettingsEvidence::command($root, $stem, $pair, 'apply', 1);
    $directory = substr(explode("\n", file_get_contents($stem . '.stderr'), 2)[0], strlen('private command diagnostics (unverified): '));
    $public = json_decode(PrivateCommandOutput::readObject($directory . '/command', $transport, expectedExit: 1), true, flags: JSON_THROW_ON_ERROR);
    $messages = ['collision' => 'unmanaged target rows have colliding slugs; apply requires an explicit identity decision',
        'drift' => 'the target changed after the repository baseline, so this plan is stale and cannot be partially applied',
        'conflict' => 'both target and repository changed since the last sync; apply requires an explicit conflict decision'];
    ImporterSettingsEvidence::check($public['format'] === 'wprism-command-refusal/v1' && $public['ok'] === false
        && $public['command'] === 'apply' && $public['reason_code'] === 'apply_refused' && $public['message'] === $messages[$case], 'exact public dirty-target refusal');
    $baseline = json_decode(PrivateCommandOutput::readObject($directory . '/baseline', $transport), true, flags: JSON_THROW_ON_ERROR);
    ImporterSettingsEvidence::check(array_keys($baseline) === ['command', 'baseline'] && $baseline['command'] === 'apply', 'exact command freshness baseline');
    PrivateRefusalReceipt::validateDiagnosticBaseline($baseline['baseline'], 'apply');
    $diagnostic = json_decode(PrivateCommandOutput::readObject($directory . '/private', $transport), true, flags: JSON_THROW_ON_ERROR);
    PrivateRefusalReceipt::verifyDiagnostic($diagnostic, ImporterDirtyTargetEvidence::profile($case, $plan));
    ImporterSettingsEvidence::check(!in_array($diagnostic['records'][0]['name'], json_decode($baseline['baseline'], true, flags: JSON_THROW_ON_ERROR), true), 'refusal is fresh to this invocation');
} else throw new RuntimeException('unknown dirty-target evidence mode');
echo 'PASS: Importer dirty target ' . $mode . "\n";

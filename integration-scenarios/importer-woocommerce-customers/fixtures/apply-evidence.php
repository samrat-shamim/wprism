<?php
declare(strict_types=1);

require_once __DIR__ . '/database-evidence.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Uuid.php';
require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/FilesystemTreeEvidence.php';

use WPrismTest\EvidenceSizeProfile;
use WPrismTest\SqlDumpEvidence;

/** Exact update-only contract; initial adoption and native CSV jobs have separate windows. */
final class ImporterWooApplyEvidence {
    private const CONTROLS = ['wp_wt_iew_mapping_template', 'wp_wprism_state', 'wp_wprism_kv', 'wp_wprism_map'];

    private static function check(bool $ok, string $why): void {
        if (!$ok) throw new RuntimeException('Importer/Woo Apply evidence: ' . $why);
    }

    public static function read(string $dump, string $inventory, array $columns): array {
        $database = ImporterWooDatabaseEvidence::read($dump, $inventory, $columns);
        $controls = [];
        foreach (self::CONTROLS as $table) {
            self::check(isset($columns[$table]), 'all existing template and ledger tables');
            // Decode only bounded control identities/text. Customer/order DECIMAL
            // and unsigned values remain untouched in the complete literal image.
            $controls[$table] = SqlDumpEvidence::fullRows($dump, $table,
                SqlDumpEvidence::columnRoster($columns[$table]), EvidenceSizeProfile::NATIVE_DATABASE);
        }
        return ['database' => $database, 'controls' => $controls];
    }

    /** Baseline convergence precedes this comparison; only two native batch Saves are authored. */
    public static function intent(array $baseline, array $desired, array $target): array {
        foreach ([$baseline, $desired] as $tree) WPrismTest\FilesystemTreeEvidence::assertRecord($tree, 'state');
        self::check($baseline['directories'] === $desired['directories'], 'canonical directory roster survives the two Saves');
        $old = array_column($baseline['files'], null, 'path'); $new = array_column($desired['files'], null, 'path');
        self::check(array_keys($old) === array_keys($new), 'no added or removed canonical file');
        $intent = [];
        foreach ($old as $path => $file) {
            if ($file['sha256'] === $new[$path]['sha256']) continue;
            self::check(str_starts_with($path, 'tables/wt_iew_mapping_template/'), 'only saved user-template canonical files change');
            $prior = json_decode(base64_decode($file['contents_base64'], true), true, flags: JSON_THROW_ON_ERROR);
            $front = json_decode(base64_decode($new[$path]['contents_base64'], true), true, flags: JSON_THROW_ON_ERROR);
            $form = json_decode($prior['columns']['data'], true, flags: JSON_THROW_ON_ERROR);
            $wanted = $form; $wanted['advanced_form_data']['wt_iew_batch_count'] = 7;
            self::check($wanted !== $form && json_decode($front['columns']['data'], true, flags: JSON_THROW_ON_ERROR) === $wanted,
                'native Save changed exactly the desired batch setting');
            $prior['columns']['data'] = $front['columns']['data'];
            self::check($prior === $front && $front['table'] === 'wt_iew_mapping_template'
                && $front['columns']['item_type'] === 'user', 'all other canonical template fields are unchanged');
            $kind = $front['columns']['template_type'];
            self::check(in_array($kind, ['import', 'export'], true)
                && $front['columns']['name'] === ($kind === 'import' ? 'Reusable input mapping' : 'Selected users'),
                'only the two named native Save targets');
            $matches = array_values(array_filter($target['controls']['wp_wt_iew_mapping_template'], static fn(array $row): bool =>
                $row['template_type'] === $kind && $row['item_type'] === 'user' && $row['name'] === $front['columns']['name']));
            self::check(count($matches) === 1, 'one preexisting independently observed target binding');
            $resolved = json_decode($matches[0]['data'], true, flags: JSON_THROW_ON_ERROR);
            self::check(!isset($resolved['method_' . $kind . '_form_data']['selected_template']), 'target baseline was materialized before the measured update');
            $resolved['advanced_form_data']['wt_iew_batch_count'] = 7;
            $intent[] = ['id' => $matches[0]['id'], 'uuid' => $front['uuid'], 'kind' => $kind, 'hash' => $new[$path]['sha256'], 'form' => $resolved, 'path' => $path];
        }
        self::check(count($intent) === 2 && count(array_unique(array_column($intent, 'kind'))) === 2, 'exactly one changed import and export');
        return $intent;
    }

    private static function index(array $rows, string $column): array {
        $out = [];
        foreach ($rows as $index => $row) {
            $key = $row[$column] ?? null;
            self::check((is_string($key) || is_int($key)) && !isset($out[$key]), 'unique observed control identity');
            $out[$key] = $index;
        }
        return $out;
    }

    public static function transition(array $before, array $after, array $intent, string $revision,
        string $artifact, array $window, bool $repeat = false): void {
        self::check(count($intent) === 2 && preg_match('/^[a-f0-9]{40}$/D', $revision) === 1
            && preg_match('/^[a-f0-9]{64}$/D', $artifact) === 1, 'two independently captured template updates and exact revision/artifact');
        $expected = $before['database'];
        $old = $before['controls']; $new = $after['controls'];
        $table = 'wp_wt_iew_mapping_template';
        $templates = self::index($old[$table], 'id');
        $states = self::index($old['wp_wprism_state'], 'uuid');
        $kinds = []; $ids = []; $uuids = [];
        foreach ($intent as $entity) {
            $kind = $entity['kind'] ?? null; $id = $entity['id'] ?? null; $uuid = $entity['uuid'] ?? null;
            self::check(in_array($kind, ['import', 'export'], true) && !isset($kinds[$kind])
                && is_int($id) && $id > 0 && isset($templates[$id]) && !isset($ids[$id])
                && is_string($uuid) && WPrism\Uuid::is($uuid)
                && isset($states[$uuid]) && !isset($uuids[$uuid])
                && is_string($entity['hash'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $entity['hash']) === 1
                && is_array($entity['form'] ?? null), 'unique existing import/export intent');
            $bindings = array_values(array_filter($old['wp_wprism_map'], static fn(array $row): bool =>
                $row['uuid'] === $uuid && $row['entity_type'] === 'wt_iew_mapping_template' && $row['id_kind'] === 'iew_template'));
            self::check(count($bindings) === 1 && $bindings[0]['local_id'] === $id, 'canonical UUID binds the independently observed target template ID');
            $kinds[$kind] = $ids[$id] = $uuids[$uuid] = true;
            $i = $templates[$id]; $row = $old[$table][$i]; $next = $new[$table][$i] ?? [];
            self::check($row['template_type'] === $kind && $row['item_type'] === 'user'
                && $row['name'] === ($kind === 'import' ? 'Reusable input mapping' : 'Selected users')
                && ($next['id'] ?? null) === $id, 'exact preexisting owned template and stable row order');
            $form = json_decode($next['data'] ?? '', true, flags: JSON_THROW_ON_ERROR);
            self::check($form === $entity['form'] && !isset($form['method_' . $kind . '_form_data']['selected_template']),
                'complete intended native form without a stale wizard cursor');
            self::check($repeat ? $row['data'] === $next['data'] : $row['data'] !== $next['data'], 'stale update or exact repeated template');
            $expected['rows'][$table][$i]['data'] = $after['database']['rows'][$table][$i]['data'];
            $j = $states[$uuid];
            self::check($old['wp_wprism_state'][$j]['entity_type'] === 'wt_iew_mapping_template'
                && ($new['wp_wprism_state'][$j]['uuid'] ?? null) === $uuid
                && ($new['wp_wprism_state'][$j]['content_hash'] ?? null) === $entity['hash']
                && ($repeat ? $old['wp_wprism_state'][$j]['content_hash'] === $entity['hash']
                    : $old['wp_wprism_state'][$j]['content_hash'] !== $entity['hash']), 'exact intended ledger baseline advance');
            $expected['rows']['wp_wprism_state'][$j]['content_hash'] = $after['database']['rows']['wp_wprism_state'][$j]['content_hash'];
        }
        $kv = self::index($old['wp_wprism_kv'], 'k');
        self::check(isset($kv['applied_revision'], $kv['promotion_session'])
            && !isset($kv['apply_in_progress']) && !isset($kv['promotion_lock']), 'settled prior Apply without an incomplete marker or lease');
        foreach (['applied_revision', 'promotion_session'] as $key) {
            $i = $kv[$key];
            self::check(($new['wp_wprism_kv'][$i]['k'] ?? null) === $key, 'stable control row identity');
            $value = $new['wp_wprism_kv'][$i]['v'];
            if ($key === 'applied_revision') {
                self::check($value === $revision && ($repeat ? $old['wp_wprism_kv'][$i]['v'] === $revision
                    : $old['wp_wprism_kv'][$i]['v'] !== $revision), 'exact intended revision advance');
            } else {
                $prior = json_decode($old['wp_wprism_kv'][$i]['v'], true, flags: JSON_THROW_ON_ERROR);
                $session = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
                $keys = array_keys($session); sort($keys, SORT_STRING);
                self::check($keys === ['artifact_hash', 'begun_at', 'owner', 'session_id']
                    && $session['artifact_hash'] === $artifact && is_string($session['owner'])
                    && preg_match('/^direct-[a-f0-9]{32}$/D', $session['owner']) === 1 && $session['owner'] !== $prior['owner']
                    && is_string($session['session_id']) && preg_match('/^ps-[a-f0-9]{32}$/D', $session['session_id']) === 1
                    && $session['session_id'] !== $prior['session_id'] && array_keys($window) === ['before', 'after']
                    && is_int($window['before']) && is_int($window['after']) && $window['before'] <= $window['after']
                    && is_int($session['begun_at']) && $session['begun_at'] >= $window['before']
                    && $session['begun_at'] <= $window['after'], 'fresh bounded direct Apply session');
            }
            $expected['rows']['wp_wprism_kv'][$i]['v'] = $after['database']['rows']['wp_wprism_kv'][$i]['v'];
        }
        self::check($expected === $after['database'], 'every schema, column, row and other cell is preserved');
    }

    public static function plan(array $plan, array $intent, array $before): string {
        foreach (['create', 'adopt', 'collision', 'drift', 'conflict', 'delete', 'delete_conflict', 'deleted',
            'code_mismatch', 'code_drift', 'incomplete_apply', 'incomplete_lifecycle', 'missing_user', 'skipped_user_meta',
            'selected_actions', 'regen_pending', 'regen_context', 'warnings'] as $field) {
            self::check(($plan[$field] ?? null) === [], 'Plan has no additional work or diagnostic: ' . $field);
        }
        // PlanCategorySummary distinguishes upload inventory and declared unselected
        // provider problems from selected actions. Optional env rows are likewise
        // reports (ApplyPlanner::env_missing_projection); required rows still block
        // this fixture. Receipt + complete DB/filesystem comparisons prove execution.
        foreach (['uploads_inventory', 'provider_problems', 'env_missing'] as $field) {
            self::check(is_array($plan[$field] ?? null) && array_is_list($plan[$field]), 'complete Plan inventory: ' . $field);
            foreach ($plan[$field] as $row) {
                self::check(is_array($row), 'Plan inventory row: ' . $field);
                if ($field === 'env_missing') {
                    self::check(is_string($row['name'] ?? null) && $row['name'] !== '' && ($row['required'] ?? null) === false,
                        'only explicitly optional environment values may be missing');
                }
            }
        }
        $wanted = array_column($intent, null, 'uuid'); $updates = [];
        foreach ($plan['update'] ?? [] as $row) {
            $uuid = $row['uuid'];
            self::check(isset($wanted[$uuid]) && !isset($updates[$uuid]) && $row['type'] === 'wt_iew_mapping_template'
                && $row['path'] === $wanted[$uuid]['path'], 'Plan identifies one exact canonical template update');
            $updates[$uuid] = true;
        }
        $expected = array_keys($wanted); $actual = array_keys($updates); sort($expected); sort($actual);
        self::check($expected === $actual && count($expected) === 2, 'Plan includes both intended updates');
        $remaining = array_values(array_diff(array_column($before['controls']['wp_wprism_state'], 'uuid'), $expected));
        $unchanged = array_column($plan['unchanged'] ?? [], 'uuid'); sort($remaining); sort($unchanged);
        self::check($remaining === $unchanged && is_string($plan['artifact_hash'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', $plan['artifact_hash']) === 1, 'complete unchanged roster and compiled artifact identity');
        return $plan['artifact_hash'];
    }

    public static function receipt(array $receipt, bool $repeat): void {
        self::check(($receipt['applied'] ?? null) === ($repeat ? 0 : 2) && ($receipt['canary'] ?? null) === 'clean'
            && ($receipt['drift'] ?? null) === [] && ($receipt['warnings'] ?? null) === [] && ($receipt['actions'] ?? null) === [],
            'public Apply completed exactly the intended work without diagnostics or executable actions');
    }

    public static function files(array $before, array $after): void {
        self::check(array_keys($before) === ['webtoffee_export', 'webtoffee_import'] && array_keys($after) === array_keys($before),
            'both operational file roots');
        foreach ($before as $root => $tree) {
            WPrismTest\FilesystemTreeEvidence::assertRecord($tree, $root);
            WPrismTest\FilesystemTreeEvidence::assertRecord($after[$root], $root);
            self::check($tree['files'] !== [] && $after[$root]['files'] !== [], 'populated operational file witnesses');
        }
        self::check($before === $after, 'every operational directory and file byte is preserved');
    }
}

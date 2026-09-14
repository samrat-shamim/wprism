<?php
declare(strict_types=1);

require_once __DIR__ . '/database-evidence.php';
require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/FilesystemTreeEvidence.php';

use WPrismTest\EvidenceSizeProfile;
use WPrismTest\SqlDumpEvidence;

/** Exact update-only contract; initial adoption and native CSV jobs have separate windows. */
final class ImporterWooApplyEvidence {
    private const CONTROLS = ['wp_wt_iew_mapping_template', 'wp_wprism_state', 'wp_wprism_kv'];

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
                && is_string($uuid) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $uuid) === 1
                && isset($states[$uuid]) && !isset($uuids[$uuid])
                && is_string($entity['hash'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $entity['hash']) === 1
                && is_array($entity['form'] ?? null), 'unique existing import/export intent');
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

<?php
declare(strict_types=1);

require_once __DIR__ . '/dirty-target-evidence.php';

use WPrism\Canon;
use WPrismTest\FilesystemTreeEvidence;
use WPrismTest\SqlDumpEvidence;

final class ImporterRecoveryEvidence {
    public const TABLES = ['wp_commentmeta', 'wp_comments', 'wp_links', 'wp_options', 'wp_postmeta', 'wp_posts',
        'wp_term_relationships', 'wp_term_taxonomy', 'wp_termmeta', 'wp_terms', 'wp_usermeta', 'wp_users',
        'wp_wprism_journal', 'wp_wprism_kv', 'wp_wprism_map', 'wp_wprism_state',
        'wp_wt_iew_action_history', 'wp_wt_iew_mapping_template'];

    public static function database(string $dump, string $inventory, array $columns): array {
        $tables = SqlDumpEvidence::tables($inventory);
        self::check($tables === self::TABLES && array_keys($columns) === $tables, 'complete independent database and column inventories');
        $schemas = SqlDumpEvidence::structures($dump, $tables,
            ['wp_options', 'wp_users', 'wp_wprism_kv', 'wp_wprism_map', 'wp_wprism_state', 'wp_wt_iew_action_history', 'wp_wt_iew_mapping_template']);
        $rows = [];
        foreach ($tables as $table) $rows[$table] = SqlDumpEvidence::fullRows($dump, $table, SqlDumpEvidence::columnRoster($columns[$table]));
        return ['schemas' => $schemas, 'columns' => $columns, 'rows' => $rows];
    }

    /** Independently captured canonical bytes, before target Apply touches them. */
    public static function intent(array $tree): array {
        FilesystemTreeEvidence::assertRecord($tree, 'state');
        $intent = []; $types = [];
        foreach ($tree['files'] as $file) {
            if ($file['path'] === 'options/core.json') {
                $intent['options/core'] = ['type' => 'options', 'path' => $file['path'], 'hash' => $file['sha256']];
                continue;
            }
            if (!str_starts_with($file['path'], 'tables/wt_iew_mapping_template/')) continue;
            $front = json_decode(base64_decode($file['contents_base64'], true), true, flags: JSON_THROW_ON_ERROR);
            $columns = $front['columns'];
            $type = $columns['template_type'];
            if (!in_array($type, ['import', 'export'], true) || $columns['item_type'] !== 'user'
                || $columns['name'] !== ($type === 'export' ? 'Selected users' : 'Reusable input mapping')) continue;
            self::check(!isset($intent[$front['uuid']]) && !isset($types[$type]) && WPrism\Uuid::is($front['uuid'])
                && $front['table'] === 'wt_iew_mapping_template', 'unique captured recovery identity');
            $types[$type] = true;
            $intent[$front['uuid']] = ['type' => $front['table'], 'path' => $file['path'], 'hash' => $file['sha256']];
        }
        self::check(count($intent) === 3 && isset($intent['options/core']), 'exact three-entity recovery intent');
        ksort($intent, SORT_STRING);
        return $intent;
    }

    public static function plan(array $before, array $after, array $plan, array $intent): string {
        self::nativeDatabase($before); self::nativeDatabase($after);
        self::check($before === $after, 'Plan preserves the complete database, native files and repository');
        self::check($intent === self::intent($before['repository']['state']), 'Plan intent comes from captured canonical bytes');
        foreach (['create', 'adopt', 'collision', 'drift', 'conflict', 'delete', 'delete_conflict', 'deleted',
            'code_mismatch', 'code_drift', 'incomplete_apply', 'incomplete_lifecycle', 'missing_user', 'skipped_user_meta',
            'uploads_inventory', 'selected_actions', 'regen_pending', 'regen_context', 'env_missing', 'warnings', 'provider_problems'] as $field) {
            self::check(($plan[$field] ?? null) === [], 'no additional Plan work or diagnostics: ' . $field);
        }
        $updates = [];
        foreach ($plan['update'] ?? [] as $row) {
            $uuid = $row['uuid'];
            self::check(isset($intent[$uuid]) && !isset($updates[$uuid]) && $row['type'] === $intent[$uuid]['type']
                && $row['path'] === $intent[$uuid]['path'], 'exact independent planned update');
            $updates[$uuid] = true;
        }
        $wanted = array_keys($intent); $actual = array_keys($updates); sort($wanted); sort($actual);
        self::check($wanted === $actual, 'all three intended updates are planned');
        $remaining = array_diff(array_column($before['database']['rows']['wp_wprism_state'], 'uuid'), $wanted);
        $unchanged = array_column($plan['unchanged'] ?? [], 'uuid'); sort($remaining); sort($unchanged);
        self::check($remaining === $unchanged && is_string($plan['artifact_hash'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', $plan['artifact_hash']) === 1, 'complete unchanged roster and compiled artifact identity');
        return $plan['artifact_hash'];
    }

    public static function marker(array $intent, array $replayed = []): string {
        $ids = array_values(array_unique(array_merge(array_keys($intent), $replayed))); sort($ids, SORT_STRING);
        return Canon::encode(['format' => 'wprism-apply-in-progress/v2', 'preserved_drift' => [], 'write_set' => $ids]);
    }

    private static function nativeDatabase(array $image): void {
        ImporterDirtyTargetEvidence::native($image['native']);
        foreach (['schemas', 'columns', 'rows'] as $key) self::check(array_keys($image['database'][$key]) === self::TABLES, 'complete database ' . $key);
        foreach ($image['native']['tables'] as $table => $rows) {
            $native = static fn(array $rows): array => array_map(static fn(array $row): array =>
                array_map(static fn(mixed $value): mixed => is_int($value) ? (string) $value : $value, $row), $rows);
            ImporterRoundtripEvidence::same($native($image['database']['rows']['wp_' . $table]), $rows,
                'independent SQL and native observations agree for every column of ' . $table);
        }
        FilesystemTreeEvidence::assertRecord($image['repository']['state'], 'state');
        FilesystemTreeEvidence::assertRecord($image['repository']['policy'], 'site.wprism.json');
    }

    private static function keyed(array $rows, string $key): array {
        $out = [];
        foreach ($rows as $row) {
            self::check(is_string($row[$key] ?? null) && !isset($out[$row[$key]]), 'unique complete control row');
            $out[$row[$key]] = $row;
        }
        return $out;
    }

    /** Validate the only session replacement an ordinary direct Apply may publish. */
    private static function session(string $before, string $after, string $artifact, array $window): void {
        $old = json_decode($before, true, flags: JSON_THROW_ON_ERROR);
        $new = json_decode($after, true, flags: JSON_THROW_ON_ERROR);
        $keys = array_keys($new); sort($keys, SORT_STRING);
        self::check($keys === ['artifact_hash', 'begun_at', 'owner', 'session_id']
            && $new['artifact_hash'] === $artifact && preg_match('/^[a-f0-9]{64}$/D', $artifact) === 1
            && is_string($new['owner']) && preg_match('/^direct-[a-f0-9]{32}$/D', $new['owner']) === 1
            && $new['owner'] !== $old['owner'] && is_string($new['session_id'])
            && preg_match('/^ps-[a-f0-9]{32}$/D', $new['session_id']) === 1 && $new['session_id'] !== $old['session_id']
            && array_keys($window) === ['before', 'after'] && is_int($window['before']) && is_int($window['after'])
            && $window['before'] <= $window['after'] && is_int($new['begun_at'])
            && $new['begun_at'] >= $window['before'] && $new['begun_at'] <= $window['after'], 'exact fresh direct Apply checkpoint');
    }

    public static function transition(array $before, array $after, array $source, array $intent,
        string $artifact, array $window, string $phase, string $revision, string $mode = 'throw'): void {
        self::check(in_array($phase, ['authored-failure', 'ledger-failure', 'retry', 'repeat'], true), 'known recovery phase');
        self::check(in_array($mode, ['throw', 'kill'], true), 'known recovery fault mode');
        self::check(preg_match('/^[a-f0-9]{40}$/D', $revision) === 1, 'exact repository commit for recovery');
        self::nativeDatabase($before); self::nativeDatabase($after);
        self::check($intent === self::intent($before['repository']['state']), 'intent binds the complete observed repository');
        self::check($before['repository'] === $after['repository'], 'recovery preserves complete canonical tree and policy');
        $expected = $before['database'];
        if ($phase === 'ledger-failure' || $phase === 'retry') {
            ImporterDirtyTargetEvidence::resolved($source, $before['native'], $after['native']);
            foreach (array_keys($before['native']['tables']) as $table) $expected['rows']['wp_' . $table] = $after['database']['rows']['wp_' . $table];
        } else ImporterRoundtripEvidence::same($before['native'], $after['native'], 'rollback or repeat preserves every native row and file');
        $old = self::keyed($before['database']['rows']['wp_wprism_kv'], 'k');
        $new = self::keyed($after['database']['rows']['wp_wprism_kv'], 'k');
        self::check(isset($old['applied_revision'], $old['promotion_session'], $new['promotion_session']), 'populated recovery baseline');
        $pending = in_array($phase, ['ledger-failure', 'retry'], true);
        // ApplyPlanner::project_incomplete_apply_retry also replays unchanged rows;
        // the second interrupted attempt therefore records the complete baseline roster.
        $replayed = array_column($before['database']['rows']['wp_wprism_state'], 'uuid');
        $priorMarker = self::marker($intent, $phase === 'retry' ? $replayed : []);
        self::check(($pending && ($old['apply_in_progress']['v'] ?? null) === $priorMarker)
            || (!$pending && !isset($old['apply_in_progress'])), 'phase has its exact pending or settled preimage');
        $baseline = self::keyed($before['database']['rows']['wp_wprism_state'], 'uuid');
        foreach ($intent as $uuid => $entity) self::check(isset($baseline[$uuid])
            && $baseline[$uuid]['entity_type'] === $entity['type']
            && ($phase === 'repeat' ? $baseline[$uuid]['content_hash'] === $entity['hash']
                : $baseline[$uuid]['content_hash'] !== $entity['hash']), 'phase has three independently stale or settled base hashes');
        self::check($phase === 'repeat' ? $old['applied_revision']['v'] === $revision
            : $old['applied_revision']['v'] !== $revision, 'phase has the exact prior revision boundary');
        self::session($old['promotion_session']['v'], $new['promotion_session']['v'], $artifact, $window);
        $oldSession = json_decode($old['promotion_session']['v'], true, flags: JSON_THROW_ON_ERROR);
        $newSession = json_decode($new['promotion_session']['v'], true, flags: JSON_THROW_ON_ERROR);
        if ($mode === 'kill' && $pending) {
            self::check(isset($old['promotion_lock']), 'crash leaves its durable lease until natural expiry');
            $lease = json_decode($old['promotion_lock']['v'], true, flags: JSON_THROW_ON_ERROR);
            self::lease($lease, $oldSession, $phase === 'ledger-failure' ? 'apply-session-begin' : 'apply-rebuild');
            self::check($lease['expires_at'] <= $window['before'], 'retry waits for the prior crashed lease to expire');
            unset($old['promotion_lock']);
        } else self::check(!isset($old['promotion_lock']), 'settled or ordinary-failure preimage has no lease');
        if ($mode === 'kill' && str_ends_with($phase, '-failure')) {
            self::check(isset($new['promotion_lock']), 'actual crash retains its own durable lease');
            $lease = json_decode($new['promotion_lock']['v'], true, flags: JSON_THROW_ON_ERROR);
            self::lease($lease, $newSession, $phase === 'authored-failure' ? 'apply-session-begin' : 'apply-rebuild');
            self::check($lease['acquired_at'] >= $window['before'] && $lease['acquired_at'] <= $window['after']
                && $lease['expires_at'] - 20 >= $window['before'] && $lease['expires_at'] - 20 <= $window['after'],
                'crash lease has the exact bounded fixture TTL and invocation timestamps');
            $old['promotion_lock'] = $new['promotion_lock'];
        }
        $old['promotion_session'] = $new['promotion_session'];
        if (str_ends_with($phase, '-failure')) $old['apply_in_progress'] = ['k' => 'apply_in_progress',
            'v' => self::marker($intent, $phase === 'ledger-failure' ? $replayed : [])];
        elseif ($phase === 'retry') {
            unset($old['apply_in_progress']);
            $old['applied_revision']['v'] = $revision;
            $state = self::keyed($expected['rows']['wp_wprism_state'], 'uuid');
            foreach ($intent as $uuid => $entity) {
                self::check(isset($state[$uuid]) && $state[$uuid]['entity_type'] === $entity['type'], 'existing exact baseline identity');
                $state[$uuid]['content_hash'] = $entity['hash'];
            }
            ksort($state, SORT_STRING);
            $expected['rows']['wp_wprism_state'] = array_values($state);
        }
        ksort($old, SORT_STRING);
        $expected['rows']['wp_wprism_kv'] = array_values($old);
        ImporterRoundtripEvidence::same($expected, $after['database'], 'complete database has only the phase-authorized changes');
    }

    private static function lease(array $lease, array $session, string $phase): void {
        $keys = array_keys($lease); sort($keys, SORT_STRING);
        self::check($keys === ['acquired_at', 'artifact_hash', 'expires_at', 'owner', 'phase']
            && $lease['owner'] === $session['owner'] && $lease['artifact_hash'] === $session['artifact_hash']
            && $lease['phase'] === $phase && is_int($lease['acquired_at']) && is_int($lease['expires_at'])
            && $lease['acquired_at'] <= $session['begun_at'] && $lease['expires_at'] >= $lease['acquired_at'] + 20,
            'exact crashed owner, artifact, durable phase and lease shape');
    }

    private static function check(bool $condition, string $reason): void {
        ImporterSettingsEvidence::check($condition, 'recovery: ' . $reason);
    }
}

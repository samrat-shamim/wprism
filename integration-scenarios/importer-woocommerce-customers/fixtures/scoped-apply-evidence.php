<?php
declare(strict_types=1);

require_once __DIR__ . '/apply-evidence.php';
require_once dirname(__DIR__, 3) . '/agent/src/Policy/ScopeContract.php';
require_once dirname(__DIR__, 3) . '/agent/src/Scope/ScopedApplySession.php';

use WPrism\Canon;
use WPrism\ScopedApplyRequest;
use WPrism\ScopedApplySession;

/** First scoped template update after full adoption; replay must be byte-stable. */
final class ImporterWooScopedApplyEvidence {
    private static function check(bool $ok, string $why): void {
        if (!$ok) throw new RuntimeException('Importer/Woo scoped Apply evidence: ' . $why);
    }

    public static function sourceContract(string $stem, string $pair): array {
        $bytes = \WPrismTest\PrivateCommandOutput::readObject($stem,
            ImporterWooDatabaseEvidence::transport($pair, 1), \WPrismTest\EvidenceSizeProfile::CONFORMANCE_TREE);
        return \WPrism\ScopeContract::from_array(json_decode($bytes, true, flags: JSON_THROW_ON_ERROR));
    }

    public static function selected(array $intent): array {
        self::check(count($intent) === 2 && array_unique(array_column($intent, 'kind')) === array_column($intent, 'kind'),
            'two independently captured pending templates');
        $selected = array_values(array_filter($intent, static fn(array $row): bool => $row['kind'] === 'export'));
        self::check(count($selected) === 1 && count(array_filter($intent, static fn(array $row): bool => $row['kind'] === 'import')) === 1,
            'one selected export and one protected pending import');
        return $selected;
    }

    public static function plan(array $plan, array $contract, array $intent): void {
        $selected = self::selected($intent)[0];
        \WPrism\ScopeContract::from_array($contract);
        self::check($contract['selectors'] === ['table:wt_iew_mapping_template:' . $selected['uuid']]
            && ($plan['format'] ?? null) === 'wprism-scoped-plan/v1'
            && ($plan['scope']['scope_hash'] ?? null) === $contract['scope_hash']
            && ($plan['artifact_hash'] ?? null) === $contract['source']['artifact_hash'], 'exact one-template source scope');
        foreach (['create', 'adopt', 'collision', 'drift', 'conflict', 'delete', 'delete_conflict', 'deleted',
            'code_mismatch', 'code_drift', 'incomplete_apply', 'incomplete_lifecycle', 'missing_user', 'skipped_user_meta',
            'selected_actions', 'regen_pending', 'regen_context', 'warnings'] as $field) {
            self::check(($plan[$field] ?? null) === [], 'scoped Plan has no other work or diagnostic: ' . $field);
        }
        $rows = $plan['update'] ?? [];
        self::check(count($rows) === 1 && ($rows[0]['uuid'] ?? null) === $selected['uuid']
            && ($rows[0]['type'] ?? null) === 'wt_iew_mapping_template' && ($rows[0]['path'] ?? null) === $selected['path'],
            'scoped Plan selects only the intended export update');
        foreach ($plan['env_missing'] ?? [] as $row) self::check(($row['required'] ?? null) === false, 'missing environment values are explicitly optional');
        $identities = array_column(array_merge($contract['live']['roots'], $contract['live']['closure']), 'entity');
        $unchanged = array_column($plan['unchanged'] ?? [], 'uuid');
        $expected = array_values(array_diff($identities, [$selected['uuid']]));
        sort($unchanged); sort($expected);
        self::check($unchanged === $expected, 'all and only unchanged scope dependencies are reported');
    }

    public static function transition(array $before, array $after, array $intent, array $source,
        string $scopeHash, string $requestId, array $window, array $receipt, bool $repeat): void {
        $selected = self::selected($intent);
        $expected = ImporterWooApplyEvidence::authoredTransition($before, $after, $selected, $repeat);
        $old = array_column($before['controls']['wp_wprism_kv'], 'v', 'k');
        $new = array_column($after['controls']['wp_wprism_kv'], 'v', 'k');
        foreach ([$before, $after] as $image) {
            $keys = array_column($image['controls']['wp_wprism_kv'], 'k');
            self::check(count($keys) === count(array_unique($keys)), 'unique ledger control keys');
        }
        self::check(isset($old['applied_revision'], $old['promotion_session'], $new['scoped_apply_session'])
            && !isset($old['promotion_lock'], $old['apply_in_progress']), 'settled full baseline and scoped terminal session');
        $session = ScopedApplySession::validate_session(json_decode($new['scoped_apply_session'], true, flags: JSON_THROW_ON_ERROR));
        self::check(Canon::encode($session) === $new['scoped_apply_session'] && $session['phase'] === 'complete'
            && $session['authority']['scope_hash'] === $scopeHash && Canon::encode($session['authority']['source']) === Canon::encode($source)
            && Canon::encode($session['authority']['request'] ?? []) === Canon::encode(ScopedApplyRequest::binding($requestId, false)),
            'canonical terminal session binds independent source, scope and caller request');
        $work = $session['authority']['selection'];
        self::check($work['work_items'] === [[
            'desired_hash' => $selected[0]['desired_hash'], 'identity_hash' => hash('sha256', $selected[0]['uuid']),
            'type' => 'wt_iew_mapping_template',
        ]] && $work['deletion_items'] === [] && $work['action_items'] === [] && $work['effect_items'] === [],
            'only the selected export is work; no deletion, provider or effect');
        self::check(($receipt['format'] ?? null) === 'wprism-scoped-apply-result/v1'
            && ($receipt['applied'] ?? null) === ($repeat ? 0 : 1) && ($repeat ? ($receipt['replayed'] ?? null) === true : !array_key_exists('replayed', $receipt))
            && ($receipt['canary'] ?? null) === 'clean' && ($receipt['drift'] ?? null) === []
            && ($receipt['warnings'] ?? null) === [] && ($receipt['actions'] ?? null) === []
            && Canon::encode($receipt['scoped_receipt'] ?? null) === Canon::encode($session['terminal_receipt'])
            && ($repeat ? array_key_exists('verification', $receipt) && $receipt['verification'] === null : ($receipt['verification']['result'] ?? null) === 'pass'),
            'public selected result and terminal replay bind the durable receipt');
        if ($repeat) {
            self::check($before['database'] === $after['database'], 'replay changes no database byte');
            return;
        }
        foreach (array_keys($old) as $key) self::check(!str_starts_with($key, 'scoped_apply_'), 'first scoped request after full adoption');
        $promotion = json_decode($new['promotion_session'] ?? '', true, flags: JSON_THROW_ON_ERROR);
        $prior = json_decode($old['promotion_session'], true, flags: JSON_THROW_ON_ERROR);
        $keys = array_keys($promotion); sort($keys, SORT_STRING);
        self::check($keys === ['artifact_hash', 'begun_at', 'owner', 'session_id']
            && $promotion['artifact_hash'] === $source['artifact_hash']
            && $promotion['owner'] === $session['lease']['owner'] && $promotion['session_id'] === $session['lease']['session_id']
            && $promotion['owner'] !== $prior['owner'] && $promotion['session_id'] !== $prior['session_id']
            && preg_match('/^direct-[a-f0-9]{32}$/D', $promotion['owner']) === 1
            && preg_match('/^ps-[a-f0-9]{32}$/D', $promotion['session_id']) === 1
            && array_keys($window) === ['before', 'after'] && is_int($window['before']) && is_int($window['after'])
            && $window['before'] <= $window['after'] && is_int($promotion['begun_at'])
            && $promotion['begun_at'] >= $window['before'] && $promotion['begun_at'] <= $window['after'],
            'fresh bounded session matches the scoped lease');
        $wanted = $old;
        $wanted['promotion_session'] = $new['promotion_session'];
        $wanted['scoped_apply_session'] = $new['scoped_apply_session'];
        ksort($wanted, SORT_STRING); ksort($new, SORT_STRING);
        self::check($wanted === $new, 'only the scoped session and matching promotion session may change; full revision stays exact');
        $expected['rows']['wp_wprism_kv'] = $after['database']['rows']['wp_wprism_kv'];
        self::check($expected === $after['database'], 'every schema, column, unselected row and other cell remains exact');
    }
}

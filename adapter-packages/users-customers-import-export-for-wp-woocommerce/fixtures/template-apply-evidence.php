<?php
declare(strict_types=1);

final class ImporterTemplateApplyEvidence {
    public static function receipt(array $receipt, ?string $targetId = null, ?string $slug = null): void {
        self::check(is_int($receipt['applied'] ?? null) && $receipt['applied'] > 0
            && ($receipt['canary'] ?? null) === 'clean'
            && ($receipt['drift'] ?? null) === [] && ($receipt['actions'] ?? null) === [],
            'successful Apply with no drift or executable action');
        $warnings = $receipt['warnings'] ?? null;
        self::check(is_array($warnings) && array_is_list($warnings), 'explicit warning list');
        if ($targetId === null && $slug === null) {
            self::check($warnings === [], 'non-adopting Apply has no warnings');
            return;
        }
        self::check(is_string($targetId) && preg_match('/^[1-9][0-9]*$/D', $targetId) === 1
            && is_string($slug) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) === 1,
            'exact adopted native identity');
        $uuid = '([a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})';
        $prefix = 'adopted env table row wt_iew_mapping_template:' . preg_quote($targetId, '#') . ' as ';
        $path = ' \(tables/wt_iew_mapping_template/\\1--' . preg_quote($slug, '#') . '\.json\)';
        self::check(count($warnings) === 1 && is_string($warnings[0])
            && preg_match('#^' . $prefix . $uuid . $path . '$#D', $warnings[0]) === 1,
            'one exact table-adoption disclosure bound to its canonical path');
    }

    public static function replay(array $applied, array $replay): void {
        self::receipt($applied);
        self::check(($applied['format'] ?? null) === 'wprism-scoped-apply-result/v1',
            'initial Apply is a scoped result');
        self::check(array_keys($replay) === ['format', 'replayed', 'applied', 'plan', 'drift', 'warnings',
            'actions', 'canary', 'verification', 'scoped_receipt'], 'exact replay result members');
        self::check($replay['format'] === 'wprism-scoped-apply-result/v1' && $replay['replayed'] === true
            && $replay['applied'] === 0 && $replay['plan'] === [] && $replay['drift'] === []
            && $replay['warnings'] === [] && $replay['actions'] === [] && $replay['canary'] === 'clean'
            && $replay['verification'] === null, 'terminal replay reports no work or diagnostic');
        $receipt = $applied['scoped_receipt'] ?? null;
        self::check(is_array($receipt) && array_keys($receipt) === ['authority_hash', 'convergence_hash', 'intents_hash',
            'lease_hash', 'phase', 'protected_ledger_map_hash', 'receipts_hash', 'selected_ledger_map_hash',
            'session_id', 'terminal_hash'] && $receipt['phase'] === 'complete', 'initial Apply has one complete scoped receipt');
        foreach (['authority_hash', 'convergence_hash', 'intents_hash', 'lease_hash', 'protected_ledger_map_hash',
            'receipts_hash', 'selected_ledger_map_hash', 'terminal_hash'] as $name) {
            self::check(is_string($receipt[$name]) && preg_match('/^[a-f0-9]{64}$/D', $receipt[$name]) === 1,
                'scoped receipt retains canonical ' . $name);
        }
        self::check(is_string($receipt['session_id']) && preg_match('/^ps-[a-f0-9]{32}$/D', $receipt['session_id']) === 1,
            'scoped receipt retains its promotion session');
        self::check($replay['scoped_receipt'] === $receipt,
            'terminal replay returns the exact completed receipt instead of minting new authority');
    }

    private static function check(bool $ok, string $reason): void {
        if (!$ok) throw new RuntimeException('Importer template Apply admission: ' . $reason);
    }
}

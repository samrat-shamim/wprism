<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/PrivateCommandOutput.php';
require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/PrivateRefusalReceipt.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';

final class ImporterTemplateDeletionEvidence {
    public static function check(bool $ok, string $why): void {
        if (!$ok) throw new RuntimeException('Importer deletion evidence: ' . $why);
    }

    public static function selected(array $rows): array {
        $selected = [];
        foreach (['export-original' => ['export', 'Selected users'], 'import-original' => ['import', 'Selected users'],
            'import-draft' => ['import', 'Draft input mapping']] as $label => [$type, $name]) {
            $matches = array_values(array_filter($rows, static fn(array $row): bool => $row['template_type'] === $type
                && $row['item_type'] === 'user' && $row['name'] === $name));
            self::check(count($matches) === 1, 'one native preimage ' . $label);
            self::check(preg_match('/^[1-9][0-9]*$/D', $matches[0]['id']) === 1, 'canonical native identity');
            $selected[$label] = $matches[0];
        }
        self::check(count(array_unique(array_column($selected, 'id'))) === 3, 'distinct selected identities');
        return $selected;
    }

    public static function removed(array $before, array $after): void {
        self::check(($before['format'] ?? '') === 'wprism-importer-native-settings/v1'
            && array_keys($before['tables'] ?? []) === ['posts', 'postmeta', 'options', 'terms', 'term_taxonomy',
                'term_relationships', 'termmeta', 'users', 'usermeta', 'wt_iew_action_history', 'wt_iew_mapping_template'], 'complete native table roster');
        $rows = $before['tables']['wt_iew_mapping_template'];
        self::check(count($rows) === 7 && count($before['tables']['users']) >= 4
            && count($before['tables']['wt_iew_action_history']) >= 2 && count($before['files']) >= 5, 'non-vacuous complete native witnesses');
        $ids = array_column(self::selected($rows), 'id');
        $before['tables']['wt_iew_mapping_template'] = array_values(array_filter($rows, static fn(array $row): bool => !in_array($row['id'], $ids, true)));
        self::same($before, $after, 'only three selected native rows disappear; every other row and file survives');
    }

    public static function same(array $before, array $after, string $why): void {
        self::check(WPrism\Canon::encode($before) === WPrism\Canon::encode($after), $why);
    }

    public static function tombstones(array $before, array $after): void {
        self::check($before['deletions'] === [] && count($after['deletions']) === 3, 'three new native-derived tombstones');
        $remaining = $before['tree'];
        $names = [];
        foreach ($after['deletions'] as $uuid => $deletion) {
            $entity = $remaining[$uuid] ?? null;
            self::check(is_array($entity) && $entity['type'] === 'wt_iew_mapping_template', 'existing typed preimage');
            $columns = $entity['data']['columns'];
            $names[] = $columns['template_type'] . ':' . $columns['name'];
            self::same(['format' => 'wprism-deletion/v1', 'uuid' => $uuid, 'kind' => 'table', 'type' => 'wt_iew_mapping_template',
                'expected_hash' => $entity['hash'], 'expected_revision' => $before['revision'], 'source_path' => $entity['path']],
                $deletion['data'], 'intent binds the previous compiled preimage and revision');
            unset($remaining[$uuid]);
        }
        sort($names, SORT_STRING);
        self::check($names === ['export:Selected users', 'import:Draft input mapping', 'import:Selected users'], 'exact native deletion selections');
        self::same($remaining, $after['tree'], 'Capture preserves every surviving canonical entity');
    }

    public static function reopened(array $before, array $export, array $import, array $history, int $historyId): void {
        $rows = $before['tables']['wt_iew_mapping_template'];
        foreach (['Selected users copy' => $export, 'Reusable input copy' => $import] as $name => $actual) {
            $matches = array_values(array_filter($rows, static fn(array $row): bool => $row['name'] === $name));
            self::check(count($matches) === 1 && (string) $actual['id'] === $matches[0]['id']
                && in_array($matches[0]['id'], $actual['selected'], true), 'native copy controls select their own identity');
            self::same(json_decode($matches[0]['data'], true, 32, JSON_THROW_ON_ERROR), $actual['form'], 'native copy reopens its complete independent form');
        }
        $jobs = array_values(array_filter($before['tables']['wt_iew_action_history'], static fn(array $row): bool => (int) $row['id'] === $historyId));
        self::check(count($jobs) === 1 && $history['status'] === 1, 'one successful native history reopen');
        self::same(json_decode($jobs[0]['data'], true, 32, JSON_THROW_ON_ERROR), $history['template_data'], 'native history reopens its complete form after original deletion');
    }

    public static function identities(array $before, array $after, array $native): void {
        self::check(($before['format'] ?? null) === 'wprism-importer-template-identities/v1'
            && array_keys($before) === ['format', 'rows'] && count($before['rows']) === 3,
            'complete target identity preimage');
        $ids = array_map('intval', array_column(self::selected($native['tables']['wt_iew_mapping_template'] ?? []), 'id'));
        sort($ids, SORT_NUMERIC);
        self::check(array_map('intval', array_column($before['rows'], 'local_id')) === $ids,
            'identity preimage belongs to the three restored native rows');
        foreach ($before['rows'] as $row) {
            self::check(array_keys($row) === ['uuid', 'entity_type', 'id_kind', 'local_id']
                && preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $row['uuid']) === 1
                && $row['entity_type'] === 'wt_iew_mapping_template' && $row['id_kind'] === 'iew_template',
                'exact typed target identity binding');
        }
        self::check(count(array_unique(array_column($before['rows'], 'uuid'))) === 3, 'distinct target identity bindings');
        self::same($before, $after, 'target restoration preserves every selected identity binding');
    }

    public static function initialCapture(array $agent, array $host): void {
        $warning = <<<'WARNING'
users-customers-import-export-for-wp-woocommerce/users-customers-import-export-for-wp-woocommerce.php 2.7.5 is active on this environment but absent from the existing WPrism code-version baseline. Its activation or first version change therefore cannot be distinguished from an out-of-band update. Run 'wprism deploy' to reconcile and record the installed bytes, or remove the undeclared activation before capture/apply. — 'wprism capture' observed this and did NOT accept it as the new baseline: capture reports what it sees, it does not reconcile code. The recorded versions are unchanged, so this finding is still there on the next 'wprism status'.
WARNING;
        self::check(($agent['warnings'] ?? null) === [$warning] && ($agent['notes'] ?? null) === []
            && ($agent['counts']['wt_iew_mapping_template'] ?? null) === 5, 'exact newly activated Importer code-baseline finding');
        self::check(($host['format'] ?? '') === 'wprism-capture-result/v1'
            && ($host['environment'] ?? '') === 'target' && ($host['branch'] ?? '') === 'wprism-live-evidence'
            && ($host['capture']['warnings_count'] ?? null) === 1 && ($host['capture']['notes_count'] ?? null) === 0
            && ($host['capture']['state_revision'] ?? '') === ($agent['revision_hash'] ?? null), 'host receipt retains the exact initial capture finding');
        self::same($agent['counts'], $host['capture']['counts'], 'host receipt retains every captured entity count');
    }

    public static function reconcile(array $capture, array $deploy): void {
        $marker = " — 'wprism capture' observed this";
        $warning = $capture['warnings'][0] ?? '';
        $boundary = strpos($warning, $marker);
        self::check(is_int($boundary) && $boundary > 0, 'previous exact code-baseline finding');
        self::check(($deploy['lifecycle_phase'] ?? '') === 'all' && ($deploy['code_mismatch'] ?? null) === []
            && count($deploy['code_drift'] ?? []) === 1
            && ($deploy['code_drift'][0]['issue'] ?? '') === 'code_baseline_missing'
            && ($deploy['code_drift'][0]['plugin'] ?? '') === 'users-customers-import-export-for-wp-woocommerce/users-customers-import-export-for-wp-woocommerce.php'
            && ($deploy['code_drift'][0]['installed_version'] ?? '') === '2.7.5'
            && ($deploy['code_drift'][0]['recorded_version'] ?? null) === ''
            && ($deploy['warnings'] ?? null) === ['FORCED past code_drift: ' . substr($warning, 0, $boundary)],
            'public agent deploy reconciles only the explicitly reviewed fixture activation');
    }

    public static function refusal(array $answer, string $kind): void {
        $reason = 'deletion_writer_exclusion_required';
        $message = 'deletion requires a signed recovery promotion whose external exclusion blocks all target writers';
        self::check($kind === 'direct' && ($answer['format'] ?? '') === 'wprism-command-refusal/v1'
            && ($answer['ok'] ?? null) === false && ($answer['command'] ?? '') === 'apply'
            && ($answer['error'] ?? '') === $reason && ($answer['reason_code'] ?? '') === $reason
            && ($answer['message'] ?? '') === $message && !isset($answer['details_redacted']), 'exact public deletion refusal at its intended frontier');
    }

    public static function privateRefusal(array $diagnostic, string $kind): void {
        self::check($kind === 'direct', 'known private refusal premise');
        $nodes = [['class' => 'WPrism\\CommandRefusalException', 'message' => 'wprism: deletion refused before mutation — no exact held external writer exclusion is bound',
            'parent_index' => null, 'relation' => 'root']];
        WPrismTest\PrivateRefusalReceipt::verifyDiagnostic($diagnostic, ['command' => 'apply',
            'reason_code' => 'deletion_writer_exclusion_required', 'nodes' => $nodes]);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
$mode = $argv[1] ?? '';
$read = static fn(string $stem): array => json_decode(WPrismTest\PrivateCommandOutput::readObject($stem, null,
    WPrismTest\EvidenceSizeProfile::NATIVE_DATABASE), true, 32, JSON_THROW_ON_ERROR);
if ($mode === 'admit') {
    $kind = $argv[3];
    $bytes = WPrismTest\PrivateCommandOutput::readBytes($argv[2], null, WPrismTest\EvidenceSizeProfile::NATIVE_DATABASE, $kind === 'direct' ? 1 : 0);
    if ($kind === 'empty') ImporterTemplateDeletionEvidence::check($bytes === '', 'quiet command output');
    elseif ($kind === 'json') $read($argv[2]);
    elseif ($kind === 'list') ImporterTemplateDeletionEvidence::check(array_is_list(json_decode($bytes, true, 32, JSON_THROW_ON_ERROR)), 'complete private filename baseline');
    elseif ($kind === 'direct') ImporterTemplateDeletionEvidence::refusal(json_decode($bytes, true, 32, JSON_THROW_ON_ERROR), 'direct');
    else throw new RuntimeException('unknown capture admission');
    return;
}
if ($mode === 'initial-capture') ImporterTemplateDeletionEvidence::initialCapture($read($argv[2]), $read($argv[3]));
elseif ($mode === 'reconcile') ImporterTemplateDeletionEvidence::reconcile($read($argv[2]), $read($argv[3]));
elseif ($mode === 'removed') ImporterTemplateDeletionEvidence::removed($read($argv[2]), $read($argv[3]));
elseif ($mode === 'same') ImporterTemplateDeletionEvidence::same($read($argv[2]), $read($argv[3]), 'complete native preservation');
elseif ($mode === 'tombstones') ImporterTemplateDeletionEvidence::tombstones($read($argv[2]), $read($argv[3]));
elseif ($mode === 'reopened') ImporterTemplateDeletionEvidence::reopened($read($argv[2]), $read($argv[3]), $read($argv[4]), $read($argv[5]), (int) $argv[6]);
elseif ($mode === 'identities') ImporterTemplateDeletionEvidence::identities($read($argv[2]), $read($argv[3]), $read($argv[4]));
elseif ($mode === 'private-refusal') ImporterTemplateDeletionEvidence::privateRefusal($read($argv[2]), $argv[3]);
else throw new RuntimeException('unknown deletion evidence mode');
echo 'PASS: Importer deletion ' . $mode . "\n";

<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/PrivateCommandOutput.php';
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
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
$mode = $argv[1] ?? '';
$read = static fn(string $stem): array => json_decode(WPrismTest\PrivateCommandOutput::readObject($stem, null,
    WPrismTest\EvidenceSizeProfile::NATIVE_DATABASE), true, 32, JSON_THROW_ON_ERROR);
if ($mode === 'admit') {
    $kind = $argv[3];
    $bytes = WPrismTest\PrivateCommandOutput::readBytes($argv[2], null, WPrismTest\EvidenceSizeProfile::NATIVE_DATABASE);
    if ($kind === 'empty') ImporterTemplateDeletionEvidence::check($bytes === '', 'quiet command output');
    elseif ($kind === 'json') $read($argv[2]);
    elseif ($kind === 'deploy') ImporterTemplateDeletionEvidence::check(preg_match('/^deploy complete: .+$/m', $bytes) === 1
        && preg_match('/(?:Warning|Fatal error|Deprecated|Notice):/i', $bytes) !== 1, 'complete clean public deployment');
    else throw new RuntimeException('unknown capture admission');
    return;
}
if ($mode === 'removed') ImporterTemplateDeletionEvidence::removed($read($argv[2]), $read($argv[3]));
elseif ($mode === 'same') ImporterTemplateDeletionEvidence::same($read($argv[2]), $read($argv[3]), 'complete native preservation');
elseif ($mode === 'tombstones') ImporterTemplateDeletionEvidence::tombstones($read($argv[2]), $read($argv[3]));
elseif ($mode === 'reopened') ImporterTemplateDeletionEvidence::reopened($read($argv[2]), $read($argv[3]), $read($argv[4]), $read($argv[5]), (int) $argv[6]);
else throw new RuntimeException('unknown deletion evidence mode');
echo 'PASS: Importer deletion ' . $mode . "\n";

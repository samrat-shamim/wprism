<?php
declare(strict_types=1);

// Bounded native consumer evidence, not an import job or template qualification.
require_once '/wprism-tests/lib/check.php';
require_once '/wprism-tests/lib/frozen_policy.php';
$agent = WPMU_PLUGIN_DIR . '/wprism';
require_once $agent . '/src/Capture/TypedTableCapture.php';
require_once $agent . '/src/Apply/TypedTableMaterializer.php';
require_once $agent . '/src/Grammar/Tokens.php';
require_once $agent . '/src/Repository/Snapshot.php';

use WPrism\Canon;
use WPrism\Db;
use WPrism\NativeDatabaseProfile;
use WPrism\Tokens;

if (!is_admin() || !current_user_can('manage_options') || !defined('WT_U_IEW_VERSION') || WT_U_IEW_VERSION !== '2.7.5') {
    throw new RuntimeException('native expression evidence requires the locked plugin and administrator context');
}
global $wpdb, $wp_filter;
$owners = [];
foreach ($wp_filter['wp_ajax_iew_import_ajax_basic']->callbacks ?? [] as $group) foreach ($group as $entry) {
    $callback = $entry['function'];
    if (is_array($callback) && is_object($callback[0]) && $callback[1] === 'ajax_main') $owners[] = $callback[0];
}
if (count($owners) !== 1) throw new RuntimeException('native expression evidence requires the actual registered importer');
$import = $owners[0];
$cases = [
    'first_name' => ['{First name}', 'Synthetic'],
    'user_pass' => ['{Customer Password Column}', 'Synthetic password cell'],
    'literal' => ['Public constant', 'Public constant'],
    'prefix' => ['Dr. {First name}', 'Dr. Synthetic'],
    'adjacent' => ['{First name}{Last name}', 'SyntheticPerson'],
    'space_trim' => ['{ First name }', 'Synthetic'],
    'missing' => ['{Absent}', ''],
    'empty_braces' => ['{}', '{}'],
    'empty' => ['', ''],
    'unclosed' => ['{First name', '{First name'],
    'unopened' => ['First name}', 'First name}'],
    'nested_open' => ['{{home}}', 'Nested header}'],
    'date' => ['{Birth' . Wt_Import_Export_For_Woo_User_Admin_Basic::$wt_iew_prefix . '@!Y-m-d}', '2026-09-14 00:00:00'],
    'arithmetic' => ['[{Count}+1]', '3'],
    'zero_no_arithmetic' => ['[{Zero}+1]', '[0+1]'],
    'sequential_substitution' => ['{A}{B}', 'secondsecond'],
    'url_header' => ['{https://source.test/header}', 'URL header value'],
    'url_query_header' => ['{https://source.test/?p=41}', 'Query header value'],
    'description' => ['https://source.test/public/{https://source.test/header}', 'https://source.test/public/URL header value'],
];
$csv = ['First name' => 'Synthetic', 'Last name' => 'Person', 'Customer Password Column' => 'Synthetic password cell',
    'Count' => '2', 'Zero' => '0', '{home' => 'Nested header', 'Birth' => '2026-09-14', 'A' => '{B}', 'B' => 'second',
    'https://source.test/header' => 'URL header value', 'https://source.test/?p=41' => 'Query header value'];
$form = ['mapping_form_data' => ['mapping_fields' => [], 'mapping_selected_fields' => []]];
foreach ($cases as $field => [$expression]) {
    $form['mapping_form_data']['mapping_fields'][$field] = [$expression, 1];
    $form['mapping_form_data']['mapping_selected_fields'][$field] = $expression;
}
$form['mapping_form_data']['mapping_fields']['disabled'] = ['{First name}', 0];
$expected = array_map(static fn($case) => $case[1], $cases);
wprism_check_same($expected, $import->process_column_val($csv, $form)['mapping_fields'],
    'registered native consumer evaluates the full source expression matrix');

$table = 'wprism_expression_probe';
$physical = $wpdb->prefix . $table;
$query = static function (string $sql) use ($wpdb): void {
    if ($wpdb->query($sql) === false) throw new RuntimeException('native expression fixture SQL failed');
};
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($physical))) !== null) {
    throw new RuntimeException('native expression fixture refuses to replace an existing table');
}
$query("CREATE TABLE `$physical` (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name varchar(64) NOT NULL, data longtext NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
try {
    $decl = ['class' => 'authored_snapshot', 'pk' => 'id', 'id_kind' => 'expression_probe', 'slug_column' => 'name',
        'identity' => ['mode' => 'mapped'], 'columns' => ['name' => ['class' => 'authored'], 'data' => ['class' => 'authored']], 'refs' => []];
    $contract = ['class' => 'authored', 'object_fields' => ['mapping_form_data' => ['class' => 'authored', 'object_fields' => [
        'mapping_fields' => ['class' => 'authored', 'field_templates' => 'brace_enabled'],
        'mapping_selected_fields' => ['class' => 'authored', 'field_templates' => 'brace']]]]];
    $manifest = ['name' => 'expression-native', 'spec_version' => 3, 'option_autoload' => 'preserve',
        'engine_features' => ['column-field-templates/v1', 'json-column-codecs/v1', 'spec-window/v1', 'typed-column-codecs/v1', 'typed-column-values/v1'],
        'tables' => [$table => $decl], 'column_codecs' => [$table => ['data' => ['container' => 'json', 'value' => $contract]]]];
    $policy = WPrismTest\FrozenPolicy::policy([$manifest], WPrismTest\FrozenPolicy::site([$manifest], 3));
    $codecs = $policy->column_codec_rules($table);
    $query($wpdb->prepare("INSERT INTO `$physical` (id,name,data) VALUES (2,'Expressions',%s)", wp_json_encode($form)));
    WPrism\Snapshot::assert_row_schema($table, $decl);
    $uuid = '11111111-1111-4111-8111-111111111111';
    $identity = new class($uuid) {
        public function __construct(private string $uuid) {}
        public function identifyRow(string $table, array $decl, array $row, int $id): string { return $this->uuid; }
    };
    $capture = new WPrism\TypedTableCapture($identity, static function (): void {}, static fn() => null, static fn($v) => strtolower($v));
    $sourceTokens = new Tokens('https://source.test', 'https://source.test/uploads');
    $targetTokens = new Tokens('https://target.test/longer', 'https://target.test/longer/uploads');
    $captureRows = static fn(Tokens $tokens): array => $capture->capture_table($table, $decl, [], $tokens, true, false, $codecs);
    $entity = $captureRows($sourceTokens)[0];
    $entity['data'] = Canon::decode($entity['content']);
    $canonical = json_decode($entity['data']['columns']['data'], true, flags: JSON_THROW_ON_ERROR);
    wprism_check_same([['text' => '{{home}}/public/'], ['field' => 'https://source.test/header']],
        $canonical['mapping_form_data']['mapping_selected_fields']['description'], 'native table Capture separates literals and CSV identities');
    $writer = new WPrism\TypedTableMaterializer(static fn() => [$table => $decl], static fn() => [],
        static fn() => 2, static function (): void {}, static fn() => 0, static fn() => [],
        static fn() => false, static fn($v) => $v, static function (): void {}, static fn() => $codecs);
    $transaction = static function (callable $action) use ($physical): void {
        Db::start_repeatable_read('native expression transport', new NativeDatabaseProfile([$physical], [$physical]));
        try { $action(); Db::commit('native expression transport'); }
        catch (Throwable $failure) { Db::rollback_after_failure($failure, 'native expression transport'); throw $failure; }
    };
    $read = static fn(): array => $wpdb->get_row("SELECT * FROM `$physical` WHERE id=2", ARRAY_A);
    $apply = static function () use ($writer, $entity, $targetTokens): void { $writer->ensureRow($entity); $writer->finalizeRow($targetTokens, $entity); };
    $transaction($apply);
    $after = $read();
    $targetForm = json_decode($after['data'], true, flags: JSON_THROW_ON_ERROR);
    $targetExpected = $expected;
    $targetExpected['description'] = 'https://target.test/longer/public/URL header value';
    $actual = $import->process_column_val($csv, $targetForm)['mapping_fields'];
    foreach ($targetExpected as $field => $value) wprism_check_same($value, $actual[$field], 'native consumer after typed-table Apply: ' . $field);
    wprism_check(!array_key_exists('disabled', $actual), 'native consumer excludes unselected disabled definitions');
    wprism_check_same(['{First name}', 0], $targetForm['mapping_form_data']['mapping_fields']['disabled'], 'Apply retains disabled definition bytes');
    wprism_check_same($entity['content'], $captureRows($targetTokens)[0]['content'], 'native expression table reaches a canonical fixed point');
    $transaction($apply);
    wprism_check_same($after, $read(), 'repeated native Apply retains complete row bytes');
    foreach (['p', 'page_id', 'attachment_id'] as $parameter) {
        foreach ([['', '1', '41'], ['%', '31', '4%31'], ['%3', '1', '4%31'],
            ['%31', '2', '4%312'], ['-', 'Tail', '4-Tail'], ['x', 'Tail', '4xTail']] as [$suffix, $cell, $expectedQuery]) {
            $partial = "https://source.test/?$parameter=4{$suffix}{Suffix}";
            $partialForm = ['mapping_form_data' => ['mapping_fields' => ['description' => [$partial, 1]],
                'mapping_selected_fields' => ['description' => $partial]]];
            wprism_check_same("https://source.test/?$parameter=$expectedQuery",
                $import->process_column_val(['Suffix' => $cell], $partialForm)['mapping_fields']['description'],
                'native dynamic query values differ from their static numeric prefix');
            wprism_check_throws(static fn() => WPrism\ColumnCodecGrammar::capture_value(wp_json_encode($partialForm), $codecs['data'],
                $sourceTokens, 'native dynamic query'), RuntimeException::class,
                'native dynamic query prefixes refuse before token lookup', 'query-reference prefix');
            $bad = $entity;
            $badData = $canonical;
            $badData['mapping_form_data']['mapping_selected_fields']['description'] = [
                ['text' => "{{home}}/?$parameter=4$suffix"], ['field' => 'Suffix']];
            $bad['data']['columns']['data'] = wp_json_encode($badData);
            wprism_check_throws(static fn() => $writer->ensureRow($bad), RuntimeException::class,
                'native phase one rejects dynamic query prefixes', 'query-reference prefix');
            wprism_check_same($after, $read(), 'native dynamic-query refusal preserves complete stored bytes');
        }
    }

    $changed = $entity;
    $canonical['mapping_form_data']['mapping_selected_fields']['description'] = [['text' => 'Changed public constant']];
    $changed['data']['columns']['data'] = wp_json_encode($canonical);
    $observed = false;
    wprism_check_throws(static function () use ($transaction, $writer, $changed, $targetTokens, $read, $import, $csv, &$observed): void {
        $transaction(static function () use ($writer, $changed, $targetTokens, $read, $import, $csv, &$observed): void {
            $writer->ensureRow($changed); $writer->finalizeRow($targetTokens, $changed);
            $native = json_decode($read()['data'], true, flags: JSON_THROW_ON_ERROR);
            $observed = $import->process_column_val($csv, $native)['mapping_fields']['description'] === 'Changed public constant';
            throw new RuntimeException('later native failure');
        });
    }, RuntimeException::class, 'later native failure rolls back expression materialization', 'later native failure');
    wprism_check($observed, 'native consumer saw the changed expression before rollback');
    wprism_check_same($after, $read(), 'native rollback restores complete expression bytes');
} finally {
    $query("DROP TABLE `$physical`");
}
wprism_check_summary('native importer expression transport');

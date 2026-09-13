<?php
declare(strict_types=1);

// Native wpdb, user-binding and Gutenberg confirmation for shared typed values.
// The offline typed-column suite owns compiler isolation and hostile input coverage.
require_once __DIR__ . '/../../lib/check.php';
$agent = WPMU_PLUGIN_DIR . '/wprism';
require_once $agent . '/src/Capture/TypedTableCapture.php';
require_once $agent . '/src/Apply/TypedTableMaterializer.php';
require_once $agent . '/src/Grammar/Tokens.php';
require_once $agent . '/src/Repository/Snapshot.php';
require_once $agent . '/src/Grammar/Blocks.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use WPrism\Canon;
use WPrism\Db;
use WPrism\NativeDatabaseProfile;
use WPrism\Snapshot;
use WPrism\Tokens;
use WPrism\TypedTableCapture;
use WPrism\TypedTableMaterializer;

global $wpdb;
$table = 'wprism_typed_column_probe';
$physical = $wpdb->prefix . $table;
$decl = ['class' => 'authored_snapshot', 'pk' => 'id', 'id_kind' => 'typed_column_probe', 'slug_column' => 'name',
    'identity' => ['mode' => 'mapped'], 'row_scope' => ['item_type' => 'user'],
    'columns' => ['item_type' => ['class' => 'authored'], 'name' => ['class' => 'authored'],
        'data' => ['class' => 'authored'], 'hits' => ['class' => 'runtime']], 'refs' => []];
$valueRule = ['class' => 'authored', 'object_fields' => [
    'filter_form_data' => ['class' => 'authored', 'object_fields' => [
        'wt_iew_email' => ['class' => 'authored', 'ref' => 'user[]', 'cast' => 'string', 'on_unmapped' => 'refuse']]],
    'url' => ['class' => 'authored', 'plain_data' => true],
    'nested' => ['class' => 'authored', 'plain_data' => true]]];
$blockManifest = ['name' => 'typed-column-native', 'spec_version' => 3, 'option_autoload' => 'preserve',
    'engine_features' => ['block-attribute-values/v1', 'block-value-contracts/v1', 'spec-window/v1'],
    'block_values' => ['fixture/typed-column' => ['query' => $valueRule]]];
$blockPolicy = WPrismTest\FrozenPolicy::policy([$blockManifest], WPrismTest\FrozenPolicy::site([$blockManifest], 3));
$valueRule['object_fields']['label_fields'] = ['class' => 'authored', 'field_labels' => 'label_enabled'];
$valueRule['object_fields']['selected_labels'] = ['class' => 'authored', 'field_labels' => 'label'];
$valueRule['object_fields']['method_export_form_data'] = ['class' => 'authored', 'plain_data' => true,
    'record_fields' => ['container' => 'object', 'fields' => ['method_export', 'mapping_enabled_fields']]];
$columnManifest = ['name' => 'field-label-native', 'spec_version' => 3, 'option_autoload' => 'preserve',
    'engine_features' => ['column-field-labels/v1', 'column-record-fields/v1', 'json-column-codecs/v1', 'spec-window/v1',
        'table-row-scopes/v1', 'typed-column-codecs/v1', 'typed-column-values/v1'],
    'tables' => [$table => $decl], 'column_codecs' => [$table => ['data' => ['container' => 'json', 'value' => $valueRule]]]];
$columnPolicy = WPrismTest\FrozenPolicy::policy([$columnManifest], WPrismTest\FrozenPolicy::site([$columnManifest], 3));
$codecs = $columnPolicy->column_codec_rules($table);
$logins = ['wprism_column_reader', 'wprism_column_editor'];
foreach ($logins as $login) {
    if (username_exists($login)) throw new RuntimeException('native typed column fixture refuses an existing user');
}
$ownedUsers = [];
$createUsers = static function () use ($logins, &$ownedUsers): array {
    $ids = [];
    foreach ($logins as $login) {
        $id = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(32), 'role' => 'subscriber']);
        if (is_wp_error($id)) throw new RuntimeException('native typed column fixture user creation failed');
        $ownedUsers[] = $id;
        $ids[] = (string) $id;
    }
    return $ids;
};
$uuid = '11111111-1111-4111-8111-111111111111';
$query = static function (string $sql) use ($wpdb): void {
    if ($wpdb->query($sql) === false) throw new RuntimeException('native typed column fixture SQL failed');
};
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($physical))) !== null) {
    throw new RuntimeException('native typed column fixture refuses to replace an existing table');
}
$query("CREATE TABLE `$physical` (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
    item_type varchar(32) NOT NULL, name varchar(64) NOT NULL, data longtext NOT NULL, hits int NOT NULL DEFAULT 0)
    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
try {
    $sourceUsers = $createUsers();
    $native = wp_json_encode(['filter_form_data' => ['wt_iew_email' => $sourceUsers], 'url' => 'https://source.test/path?q="quoted"',
        'method_export_form_data' => ['method_export' => 'template', 'selected_template' => '1', 'mapping_enabled_fields' => []],
        'label_fields' => ['user_email' => ['Email address', 1], 'display_name' => ['Display name', 0],
            'first_name' => ['First name', 1], 'last_name' => ['Last name', 0], 'nickname' => ['Nickname', 0]],
        'selected_labels' => ['user_email' => 'Email address', 'display_name' => 'Display name'],
        'nested' => ['note' => 'বাংলা', 'empty' => [], 'enabled' => true, 'nothing' => null, 'count' => 7, 'fraction' => 1.25]]);
    $query($wpdb->prepare("INSERT INTO `$physical` (id,item_type,name,data,hits) VALUES
        (2,'user','Selected',%s,42),(3,'product','Foreign','{broken',91)", $native));
    Snapshot::assert_row_schema($table, $decl);
    $rows = static fn(): array => $wpdb->get_results("SELECT * FROM `$physical` ORDER BY id", ARRAY_A);
    $before = $rows();
    $identity = new class($uuid) {
        public function __construct(private string $uuid) {}
        public function identifyRow(string $table, array $decl, array $row, int $id): string { return $this->uuid; }
    };
    $capture = new TypedTableCapture($identity, static function (): void {}, static fn() => null,
        static fn(string $name): string => strtolower($name));
    $sourceTokens = new Tokens('https://source.test', 'https://source.test/wp-content/uploads');
    $targetTokens = new Tokens('https://target.test/longer', 'https://target.test/longer/wp-content/uploads');
    $captureJson = static fn(Tokens $tokens): array => $capture->capture_table($table, $decl, [], $tokens, true, false, $codecs);
    $entities = $captureJson($sourceTokens);
    wprism_check_same(1, count($entities), 'native scoped Capture excludes foreign malformed JSON');
    $entity = $entities[0];
    $entity['data'] = Canon::decode($entity['content']);
    $canonical = $entity['data']['columns']['data'];
    $expected = json_decode($native, true);
    $expected['filter_form_data']['wt_iew_email'] = array_map(static fn(string $login): string => 'user:' . $login, $logins);
    $expected['url'] = '{{home}}/path?q="quoted"';
    unset($expected['method_export_form_data']['selected_template']);
    wprism_check_same(wp_json_encode($expected), $canonical, 'native Capture rewrites user references and text through one value contract');
    $blockValue = json_decode($native, true);
    unset($blockValue['label_fields'], $blockValue['selected_labels'], $blockValue['method_export_form_data']);
    $blockNative = '<!-- wp:fixture/typed-column ' . wp_json_encode(['query' => $blockValue]) . ' /-->';
    $blockCanonical = WPrism\Blocks::capture_rewrite($blockNative, $blockPolicy, $sourceTokens);
    foreach ($ownedUsers as $id) {
        if (!wp_delete_user($id)) throw new RuntimeException('native source binding deletion failed');
    }
    $ownedUsers = [];
    $targetUsers = $createUsers();
    wprism_check($sourceUsers !== $targetUsers, 'native user logins bind to different target IDs');
    $blockTarget = WPrism\Blocks::apply_rewrite($blockCanonical, $blockPolicy, $targetTokens);
    wprism_check_same($targetUsers, parse_blocks($blockTarget)[0]['attrs']['query']['filter_form_data']['wt_iew_email'],
        'native block parsing uses the same extracted value codec with target string IDs');
    wprism_check_same($blockCanonical, WPrism\Blocks::capture_rewrite($blockTarget, $blockPolicy, $targetTokens),
        'native block serialization retains its complete canonical fixed point');
    wprism_check(!array_key_exists('hits', $entity['data']['columns']), 'native runtime counter is absent from authored state');
    // Force real user reads inside the authored transaction, independent of
    // the preceding block test's token cache. Production admits users read-only
    // in AuthoredTransactionExecutor::authored_transaction_profile().
    $targetTokens = new Tokens('https://target.test/longer', 'https://target.test/longer/wp-content/uploads');
    $writer = new TypedTableMaterializer(static fn() => [$table => $decl], static fn() => [],
        static fn() => 2, static function (): void {}, static fn() => 0, static fn() => [],
        static fn() => false, static fn($value) => $value, static function (): void {}, static fn() => $codecs);
    $transaction = static function (callable $action) use ($physical, $wpdb): void {
        Db::start_repeatable_read('native typed column', new NativeDatabaseProfile([$physical, $wpdb->users], [$physical]));
        try {
            $action();
            Db::commit('native typed column');
        } catch (Throwable $failure) {
            Db::rollback_after_failure($failure, 'native typed column');
            throw $failure;
        }
    };
    $apply = static function () use ($writer, $entity, $targetTokens): void {
        wprism_check(!$writer->ensureRow($entity), 'native Apply retains the existing owned identity');
        $writer->finalizeRow($targetTokens, $entity);
    };
    $transaction($apply);
    $after = $rows();
    $expected['filter_form_data']['wt_iew_email'] = $targetUsers;
    $expected['url'] = 'https://target.test/longer/path?q="quoted"';
    wprism_check_same(wp_json_encode($expected), $after[0]['data'], 'native Apply stores target IDs as strings and re-encodes JSON');
    wprism_check_same($before[0]['hits'], $after[0]['hits'], 'native Apply preserves the runtime counter');
    wprism_check_same($before[1], $after[1], 'native Apply preserves every foreign byte');
    wprism_check_same($entity['content'], $captureJson($targetTokens)[0]['content'], 'native typed recapture reaches complete canonical equality');
    $transaction($apply);
    wprism_check_same($after, $rows(), 'native repeated Apply is a fixed point');
    $changed = $entity;
    $changed['data']['columns']['data'] = wp_json_encode(['url' => '{{home}}/changed']);
    $observed = false;
    wprism_check_throws(static function () use ($transaction, $writer, $changed, $targetTokens, $rows, &$observed): void {
        $transaction(static function () use ($writer, $changed, $targetTokens, $rows, &$observed): void {
            $writer->finalizeRow($targetTokens, $changed);
            $observed = json_decode($rows()[0]['data'], true)['url'] === 'https://target.test/longer/changed';
            throw new RuntimeException('injected later native JSON failure');
        });
    }, RuntimeException::class, 'native later failure aborts the JSON write', 'injected later native JSON failure');
    wprism_check($observed, 'native rollback probe reached the rewritten JSON value');
    wprism_check_same($after, $rows(), 'native rollback restores the complete table');
    $changed['data']['columns']['data'] = wp_json_encode(['filter_form_data' => ['wt_iew_email' => ['user:wprism_missing_column_user']]]);
    wprism_check_throws(static fn() => $transaction(static fn() => $writer->finalizeRow($targetTokens, $changed)),
        RuntimeException::class, 'native missing user binding aborts materialization', 'strict user reference');
    wprism_check_same($after, $rows(), 'native missing binding preserves the complete table');
    $changed['data']['columns']['data'] = '{broken';
    wprism_check_throws(static fn() => $transaction(static fn() => $writer->finalizeRow($targetTokens, $changed)),
        RuntimeException::class, 'native Apply refuses malformed canonical JSON', 'not valid JSON');
    wprism_check_same($after, $rows(), 'native malformed Apply preserves the complete table');
    $changed['data']['columns']['data'] = wp_json_encode(['label_fields' => ['user_email' => ['Email address', '1']]]);
    wprism_check_throws(static fn() => $transaction(static fn() => $writer->finalizeRow($targetTokens, $changed)),
        RuntimeException::class, 'native Apply refuses a noninteger field enable flag', 'integer 0 or 1');
    wprism_check_same($after, $rows(), 'malformed field metadata preserves every native row');
    $changed['data']['columns']['data'] = wp_json_encode(['method_export_form_data' => ['method_export' => 'template', 'selected_template' => '99']]);
    wprism_check_throws(static fn() => $transaction(static fn() => $writer->finalizeRow($targetTokens, $changed)),
        RuntimeException::class, 'native Apply refuses a reintroduced saved wizard cursor', 'excluded canonical record fields');
    wprism_check_same($after, $rows(), 'excluded canonical fields preserve every native row');
    foreach ([['email' => 'reader@example.test'], ['api_key' => 'private-credential-material']] as $private) {
        $query($wpdb->prepare("UPDATE `$physical` SET data=%s WHERE id=2", wp_json_encode([
            'method_export_form_data' => ['method_export' => 'template'] + $private])));
        $privateRows = $rows();
        wprism_check_throws(static fn() => $captureJson($targetTokens), RuntimeException::class,
            'native record projection retains original clearance for discarded fields');
        wprism_check_same($privateRows, $rows(), 'refused record Capture preserves every native byte');
    }
    foreach ([['user_email' => ['reader@example.test', 1]], ['reader@example.test' => ['Email', 1]],
        ['user_pass' => ['s3cr3t-Credential-0123456789!', 1]]] as $private) {
        $query($wpdb->prepare("UPDATE `$physical` SET data=%s WHERE id=2", wp_json_encode(['label_fields' => $private])));
        $privateRows = $rows();
        wprism_check_throws(static fn() => $captureJson($targetTokens), RuntimeException::class,
            'native label metadata retains key, value and credential clearance');
        wprism_check_same($privateRows, $rows(), 'native refused label Capture preserves stored bytes');
    }
    $query($wpdb->prepare("UPDATE `$physical` SET data=%s WHERE id=2", $after[0]['data']));
    $transaction(static fn() => $writer->deleteLocalRow($table, 2));
    wprism_check_same([$before[1]], $rows(), 'native deletion preserves foreign malformed JSON');

    // The same native column now carries two explicit semantic owners. Keep
    // real user reads and row writes inside the existing checked transaction.
    $query("ALTER TABLE `$physical` ADD mode varchar(32) NOT NULL DEFAULT ''");
    $caseDecl = $decl;
    $caseDecl['columns']['mode'] = ['class' => 'authored'];
    $caseDecl['row_scope']['mode'] = ['export', 'import'];
    $caseManifest = $columnManifest;
    $caseManifest['engine_features'] = array_merge($caseManifest['engine_features'], ['column-value-cases/v1', 'table-row-scope-sets/v1']);
    sort($caseManifest['engine_features'], SORT_STRING);
    $caseManifest['tables'][$table] = $caseDecl;
    $caseManifest['column_codecs'][$table]['data'] = ['container' => 'json', 'value_cases' => ['column' => 'mode', 'cases' => [
        ['equals' => 'export', 'value' => ['class' => 'authored', 'field_labels' => 'label_enabled']],
        ['equals' => 'import', 'value' => ['class' => 'authored', 'ref' => 'user[]', 'cast' => 'string', 'on_unmapped' => 'refuse']],
    ]]];
    $casePolicy = WPrismTest\FrozenPolicy::policy([$caseManifest], WPrismTest\FrozenPolicy::site([$caseManifest], 3));
    $caseCodecs = $casePolicy->column_codec_rules($table);
    $caseLabels = wp_json_encode(['first_name' => ['First name', 1]]);
    $query($wpdb->prepare("INSERT INTO `$physical` (id,item_type,name,mode,data,hits) VALUES
        (101,'user','Export','export',%s,17),(102,'user','Import','import',%s,19)", $caseLabels, wp_json_encode($targetUsers)));
    $caseBefore = $rows();
    $caseUuids = ['export' => '33333333-3333-4333-8333-333333333333', 'import' => '44444444-4444-4444-8444-444444444444'];
    $caseIdentity = new class($caseUuids) {
        public function __construct(private array $uuids) {}
        public function identifyRow(string $table, array $decl, array $row, int $id): string { return $this->uuids[$row['mode']]; }
    };
    $caseCapture = new TypedTableCapture($caseIdentity, static function (): void {}, static fn() => null,
        static fn(string $name): string => strtolower($name));
    $captureCases = static fn(Tokens $input): array => $caseCapture->capture_table($table, $caseDecl, [], $input, true, false, $caseCodecs);
    $caseEntities = $captureCases(new Tokens('https://source.test', 'https://source.test/uploads'));
    foreach ($caseEntities as &$caseEntity) $caseEntity['data'] = Canon::decode($caseEntity['content']);
    unset($caseEntity);
    wprism_check_same(2, count($caseEntities), 'native row cases capture both owners and exclude foreign malformed data');
    wprism_check_same($caseLabels, $caseEntities[0]['data']['columns']['data'], 'native export selects label metadata');
    wprism_check_same(wp_json_encode(array_map(static fn($login) => 'user:' . $login, $logins)),
        $caseEntities[1]['data']['columns']['data'], 'native import selects user bindings in the same physical column');
    foreach ($ownedUsers as $id) {
        if (!wp_delete_user($id)) throw new RuntimeException('case source binding deletion failed');
    }
    $ownedUsers = [];
    $caseTargetUsers = $createUsers();
    wprism_check($caseTargetUsers !== $targetUsers, 'native case target user IDs diverge');
    $caseTokens = new Tokens('https://target.test', 'https://target.test/uploads');
    $caseMapping = [$caseUuids['export'] => 101, $caseUuids['import'] => 102];
    $caseWriter = new TypedTableMaterializer(static fn() => [$table => $caseDecl], static fn() => [],
        static function (string $uuid) use (&$caseMapping): ?int { return $caseMapping[$uuid] ?? null; },
        static function (string $uuid, string $table, string $kind, int $id) use (&$caseMapping): void { $caseMapping[$uuid] = $id; },
        static fn() => 0, static fn() => [], static fn() => false, static fn($value) => $value,
        static function (): void {}, static fn() => $caseCodecs);
    $caseApply = static function () use ($caseWriter, $caseEntities, $caseTokens): void {
        foreach ($caseEntities as $entity) { $caseWriter->ensureRow($entity); $caseWriter->finalizeRow($caseTokens, $entity); }
    };
    $transaction($caseApply);
    $caseAfter = $rows();
    wprism_check_same($caseLabels, $caseAfter[1]['data'], 'native selected Apply preserves export labels');
    wprism_check_same(wp_json_encode($caseTargetUsers), $caseAfter[2]['data'], 'native selected Apply binds import users to target string IDs');
    wprism_check_same($caseBefore[0], $caseAfter[0], 'native selected Apply preserves foreign bytes');
    wprism_check_same(array_column($caseBefore, 'hits'), array_column($caseAfter, 'hits'), 'native selected Apply preserves runtime counters');
    wprism_check_same(array_column($caseEntities, 'content'), array_column($captureCases($caseTokens), 'content'),
        'native selected row recapture is a complete fixed point');
    $transaction($caseApply);
    wprism_check_same($caseAfter, $rows(), 'native repeated selected Apply is a fixed point');
    $badCase = $caseEntities[0];
    $badCase['data']['uuid'] = '55555555-5555-4555-8555-555555555555';
    $badCase['data']['columns']['data'] = '["user:reader"]';
    $mappingBefore = $caseMapping;
    wprism_check_throws(static fn() => $caseWriter->ensureRow($badCase), RuntimeException::class,
        'native phase one refuses a mismatched selected payload before writing', 'field labels');
    wprism_check_same($caseAfter, $rows(), 'native phase one refusal makes no row write');
    wprism_check_same($mappingBefore, $caseMapping, 'native phase one refusal publishes no identity');
    $switched = $caseEntities[0];
    $switched['data']['columns']['mode'] = 'import';
    $switched['data']['columns']['data'] = $caseEntities[1]['data']['columns']['data'];
    $observedSwitch = false;
    wprism_check_throws(static function () use ($transaction, $caseWriter, $switched, $caseTokens, $rows, $caseTargetUsers, &$observedSwitch): void {
        $transaction(static function () use ($caseWriter, $switched, $caseTokens, $rows, $caseTargetUsers, &$observedSwitch): void {
            $caseWriter->ensureRow($switched);
            $caseWriter->finalizeRow($caseTokens, $switched);
            $current = $rows()[1];
            $observedSwitch = $current['mode'] === 'import' && $current['data'] === wp_json_encode($caseTargetUsers);
            throw new RuntimeException('later native case failure');
        });
    }, RuntimeException::class, 'native variant transition retains checked rollback', 'later native case failure');
    wprism_check($observedSwitch, 'native transition selects the authored destination contract before writing');
    wprism_check_same($caseAfter, $rows(), 'native rollback restores discriminator and payload together');
    $query("UPDATE `$physical` SET mode='import' WHERE id=101");
    $misclassified = $rows();
    wprism_check_throws(static fn() => $captureCases($caseTokens), RuntimeException::class,
        'native row cannot borrow another case contract');
    wprism_check_same($misclassified, $rows(), 'native refused selected Capture preserves every byte');

    echo 'Database: ', $wpdb->get_var('SELECT VERSION()'), '; WordPress: ', get_bloginfo('version'), '; PHP: ', PHP_VERSION, "\n";
} finally {
    foreach ($ownedUsers as $id) wp_delete_user($id);
    $query("DROP TABLE `$physical`");
}
wprism_check_summary('native typed column codecs');

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
$codecs = ['data' => ['container' => 'json', 'value' => $valueRule]];
$blockManifest = ['name' => 'typed-column-native', 'spec_version' => 3, 'option_autoload' => 'preserve',
    'engine_features' => ['block-attribute-values/v1', 'block-value-contracts/v1', 'spec-window/v1'],
    'block_values' => ['fixture/typed-column' => ['query' => $valueRule]]];
$blockPolicy = WPrismTest\FrozenPolicy::policy([$blockManifest], WPrismTest\FrozenPolicy::site([$blockManifest], 3));
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
    wprism_check_same(wp_json_encode($expected), $canonical, 'native Capture rewrites user references and text through one value contract');
    $blockNative = '<!-- wp:fixture/typed-column ' . wp_json_encode(['query' => json_decode($native, true)]) . ' /-->';
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
    $writer = new TypedTableMaterializer(static fn() => [$table => $decl], static fn() => [],
        static fn() => 2, static function (): void {}, static fn() => 0, static fn() => [],
        static fn() => false, static fn($value) => $value, static function (): void {}, static fn() => $codecs);
    $transaction = static function (callable $action) use ($physical): void {
        Db::start_repeatable_read('native typed column', new NativeDatabaseProfile([$physical], [$physical]));
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
    $transaction(static fn() => $writer->deleteLocalRow($table, 2));
    wprism_check_same([$before[1]], $rows(), 'native deletion preserves foreign malformed JSON');
    echo 'Database: ', $wpdb->get_var('SELECT VERSION()'), '; WordPress: ', get_bloginfo('version'), '; PHP: ', PHP_VERSION, "\n";
} finally {
    foreach ($ownedUsers as $id) wp_delete_user($id);
    $query("DROP TABLE `$physical`");
}
wprism_check_summary('native typed column codecs');

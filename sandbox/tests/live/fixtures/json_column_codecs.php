<?php
declare(strict_types=1);

// Native storage confirmation for the JSON arm of column_codecs. Grammar,
// compiler and adversarial framing coverage live in regress_column_codec_grammar.php.
require_once __DIR__ . '/../../lib/check.php';
$agent = WPMU_PLUGIN_DIR . '/wprism';
require_once $agent . '/src/Capture/TypedTableCapture.php';
require_once $agent . '/src/Apply/TypedTableMaterializer.php';
require_once $agent . '/src/Grammar/Tokens.php';
require_once $agent . '/src/Repository/Snapshot.php';

use WPrism\Canon;
use WPrism\Db;
use WPrism\NativeDatabaseProfile;
use WPrism\Snapshot;
use WPrism\Tokens;
use WPrism\TypedTableCapture;
use WPrism\TypedTableMaterializer;

global $wpdb;
$table = 'wprism_json_column_probe';
$physical = $wpdb->prefix . $table;
$decl = ['class' => 'authored_snapshot', 'pk' => 'id', 'id_kind' => 'json_column_probe', 'slug_column' => 'name',
    'identity' => ['mode' => 'mapped'], 'row_scope' => ['item_type' => 'user'],
    'columns' => ['item_type' => ['class' => 'authored'], 'name' => ['class' => 'authored'],
        'data' => ['class' => 'authored'], 'hits' => ['class' => 'runtime']], 'refs' => []];
$codecs = ['data' => ['container' => 'json', 'leaves' => 'text']];
$uuid = '11111111-1111-4111-8111-111111111111';
$query = static function (string $sql) use ($wpdb): void {
    if ($wpdb->query($sql) === false) throw new RuntimeException('native JSON column fixture SQL failed');
};
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($physical))) !== null) {
    throw new RuntimeException('native JSON column fixture refuses to replace an existing table');
}
$query("CREATE TABLE `$physical` (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
    item_type varchar(32) NOT NULL, name varchar(64) NOT NULL, data longtext NOT NULL, hits int NOT NULL DEFAULT 0)
    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
try {
    $native = wp_json_encode(['url' => 'https://source.test/path?q="quoted"',
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
    wprism_check_same(str_replace('https:\/\/source.test', '{{home}}', $native), $canonical,
        'native JSON Capture decodes escaped URL bytes and preserves the remaining payload');
    wprism_check(!array_key_exists('hits', $entity['data']['columns']), 'native runtime counter is absent from authored state');
    $writer = new TypedTableMaterializer(static fn() => [$table => $decl], static fn() => [],
        static fn() => 2, static function (): void {}, static fn() => 0, static fn() => [],
        static fn() => false, static fn($value) => $value, static function (): void {}, static fn() => $codecs);
    $transaction = static function (callable $action) use ($physical): void {
        Db::start_repeatable_read('native JSON column', new NativeDatabaseProfile([$physical], [$physical]));
        try {
            $action();
            Db::commit('native JSON column');
        } catch (Throwable $failure) {
            Db::rollback_after_failure($failure, 'native JSON column');
            throw $failure;
        }
    };
    $apply = static function () use ($writer, $entity, $targetTokens): void {
        wprism_check(!$writer->ensureRow($entity), 'native Apply retains the existing owned identity');
        $writer->finalizeRow($targetTokens, $entity);
    };
    $transaction($apply);
    $after = $rows();
    wprism_check_same(str_replace('https:\/\/source.test', 'https:\/\/target.test\/longer', $native), $after[0]['data'],
        'native Apply re-encodes valid JSON with the target URL');
    wprism_check_same($before[0]['hits'], $after[0]['hits'], 'native Apply preserves the runtime counter');
    wprism_check_same($before[1], $after[1], 'native Apply preserves every foreign byte');
    wprism_check_same($entity['content'], $captureJson($targetTokens)[0]['content'], 'native JSON recapture reaches complete canonical equality');
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
    $changed['data']['columns']['data'] = '{broken';
    wprism_check_throws(static fn() => $transaction(static fn() => $writer->finalizeRow($targetTokens, $changed)),
        RuntimeException::class, 'native Apply refuses malformed canonical JSON', 'not valid JSON');
    wprism_check_same($after, $rows(), 'native malformed Apply preserves the complete table');
    $transaction(static fn() => $writer->deleteLocalRow($table, 2));
    wprism_check_same([$before[1]], $rows(), 'native deletion preserves foreign malformed JSON');
    echo 'Database: ', $wpdb->get_var('SELECT VERSION()'), '; WordPress: ', get_bloginfo('version'), '; PHP: ', PHP_VERSION, "\n";
} finally {
    $query("DROP TABLE `$physical`");
}
wprism_check_summary('native JSON column codecs');

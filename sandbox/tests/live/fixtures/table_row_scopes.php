<?php
declare(strict_types=1);

// Run only in the disposable pair owned by regress_table_row_scopes_native.sh.
// The external mysqli connection is a concurrent native writer; all candidate
// writes still pass through TypedTableMaterializer and Db's tracked transaction.
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
$table = 'wprism_row_scope_probe';
$physical = $wpdb->prefix . $table;
$decl = ['class' => 'authored_snapshot', 'pk' => 'id', 'id_kind' => 'row_scope_probe', 'slug_column' => 'name',
    'identity' => ['mode' => 'mapped'], 'row_scope' => ['item_type' => 'user'],
    'columns' => ['item_type' => ['class' => 'authored'], 'name' => ['class' => 'authored'],
        'data' => ['class' => 'authored']], 'refs' => []];
$uuid = '11111111-1111-4111-8111-111111111111';
$query = static function (string $sql) use ($wpdb): void {
    if ($wpdb->query($sql) === false) throw new RuntimeException('native row-scope fixture SQL failed');
};
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($physical))) !== null) {
    throw new RuntimeException('native row-scope fixture refuses to replace an existing table');
}
$query("CREATE TABLE `$physical` (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
    item_type varchar(32) NOT NULL, name varchar(64) NOT NULL, data longtext NOT NULL)
    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$external = null;
try {
    $query("INSERT INTO `$physical` (id,item_type,name,data) VALUES
        (2,'user','Owned','https://source.test/path'),
        (3,'product','Foreign','alice@example.test'),
        (4,'User','Case','sk_live_FOREIGNCREDENTIAL1234567890'),
        (5,'user ','Space','foreign trailing space')");
    Snapshot::assert_row_schema($table, $decl);
    $rows = static fn(): array => $wpdb->get_results("SELECT * FROM `$physical` ORDER BY id", ARRAY_A);
    $before = $rows();
    $foreign = array_slice($before, 1);
    $identity = new class($uuid) {
        public array $seen = [];
        public function __construct(private string $uuid) {}
        public function identifyRow(string $table, array $decl, array $row, int $id): string {
            $this->seen[] = $id;
            return $this->uuid;
        }
    };
    $capture = new TypedTableCapture($identity, static function (): void {}, static fn() => null,
        static fn(string $name): string => strtolower($name));
    $sourceTokens = new Tokens('https://source.test', 'https://source.test/wp-content/uploads');
    $targetTokens = new Tokens('https://target.test/longer', 'https://target.test/longer/wp-content/uploads');
    $entities = $capture->capture_table($table, $decl, [], $sourceTokens, true);
    wprism_check_same([2], $identity->seen, 'native case-insensitive table Capture admits only byte-exact ownership');
    wprism_check_same(1, count($entities), 'foreign payloads never enter native Capture');
    $entity = $entities[0];
    $entity['data'] = Canon::decode($entity['content']);
    $writer = new TypedTableMaterializer(static fn() => [$table => $decl], static fn() => [],
        static fn() => 2, static function (): void {}, static fn() => 0, static fn() => [],
        static fn() => false, static fn($value) => $value, static function (): void {}, static fn() => []);
    $transaction = static function (callable $action) use ($physical): mixed {
        Db::start_repeatable_read('native row scope', new NativeDatabaseProfile([$physical], [$physical]));
        try {
            $result = $action();
            Db::commit('native row scope');
            return $result;
        } catch (Throwable $failure) {
            try {
                Db::rollback('native row scope');
            } catch (Throwable $cleanupFailure) {
                throw new RuntimeException('native fixture rollback failed; primary=' . $failure->getMessage()
                    . '; cleanup=' . $cleanupFailure->getMessage(), 0, $failure);
            }
            throw $failure;
        }
    };
    $transaction(static function () use ($writer, $entity, $targetTokens): void {
        wprism_check(!$writer->ensureRow($entity), 'native Apply retains the owned local id');
        $writer->finalizeRow($targetTokens, $entity);
    });
    wprism_check_same('https://target.test/longer/path', $rows()[0]['data'], 'native Apply rebinds the owned payload');
    wprism_check_same($foreign, array_slice($rows(), 1), 'native Apply preserves every foreign byte');
    wprism_check_same($entity['content'], $capture->capture_table($table, $decl, [], $targetTokens, true)[0]['content'],
        'native recapture reaches the same canonical bytes');
    $changed = $entity;
    $changed['data']['columns']['data'] = 'written before later failure';
    $observed = false;
    $stable = $rows();
    wprism_check_throws(static function () use ($transaction, $writer, $changed, $targetTokens, $rows, &$observed): void {
        $transaction(static function () use ($writer, $changed, $targetTokens, $rows, &$observed): void {
            $writer->finalizeRow($targetTokens, $changed);
            $observed = $rows()[0]['data'] === 'written before later failure';
            throw new RuntimeException('injected later native failure');
        });
    }, RuntimeException::class, 'later failure aborts an actual native write', 'injected later native failure');
    wprism_check($observed, 'rollback probe reached the candidate update');
    wprism_check_same($stable, $rows(), 'native rollback restores the complete table');

    // wpdb parses DB_HOST and creates the second connection before the product
    // query isolation starts. Its raw handle models an independent process;
    // routing it through global WordPress query hooks would not do so.
    $external = new class(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST) extends wpdb {
        public function nativeHandle(): mysqli { return $this->dbh; }
    };
    wprism_check($wpdb->get_var('SELECT CONNECTION_ID()') !== $external->get_var('SELECT CONNECTION_ID()'),
        'concurrent native writer has a distinct database connection');
    wprism_check_throws(static fn() => $transaction(static function () use ($wpdb, $physical, $external, $writer, $entity, $targetTokens): void {
        $sql = "SELECT item_type FROM `$physical` WHERE id=2";
        wprism_check_same('user', $wpdb->get_var($sql), 'repeatable-read snapshot begins with the owned row');
        if (!$external->nativeHandle()->query("UPDATE `$physical` SET item_type='product',data='external committed payload' WHERE id=2")) {
            throw new RuntimeException('concurrent native fixture update failed');
        }
        wprism_check_same('user', $wpdb->get_var($sql), 'ordinary repeatable read still sees the stale owned preimage');
        $writer->finalizeRow($targetTokens, $entity);
    }), RuntimeException::class, 'Apply locks and refuses the current foreign owner', 'outside declared row_scope');
    $after = $rows();
    wprism_check_same('product', $after[0]['item_type'], 'refusal preserves the externally committed owner');
    wprism_check_same('external committed payload', $after[0]['data'], 'refusal preserves the externally committed payload');
    wprism_check_same($foreign, array_slice($after, 1), 'ownership-race refusal preserves the other foreign rows');
    wprism_check_same([], $capture->capture_table($table, $decl, [], $targetTokens, true), 'native recapture excludes the changed owner');
    wprism_check_throws(static fn() => $transaction(static fn() => $writer->deleteLocalRow($table, 2)),
        RuntimeException::class, 'native deletion refuses the foreign retained id', 'outside declared row_scope');
    $query("UPDATE `$physical` SET item_type='user' WHERE id=2");
    $transaction(static fn() => $writer->deleteLocalRow($table, 2));
    wprism_check_same($foreign, $rows(), 'native owned deletion preserves every foreign row');
    echo 'Database: ', $wpdb->get_var('SELECT VERSION()'), '; WordPress: ', get_bloginfo('version'), '; PHP: ', PHP_VERSION, "\n";
} finally {
    if ($external !== null) $external->close();
    $query("DROP TABLE `$physical`");
}
wprism_check_summary('native typed-table row scopes');

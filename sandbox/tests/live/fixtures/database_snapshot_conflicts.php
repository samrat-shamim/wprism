<?php
declare(strict_types=1);

// A separate stock wpdb connection models the concurrent native writer.
// Candidate reads, writes and cleanup use the ordinary engine boundaries.
require_once __DIR__ . '/../../lib/check.php';
require_once WPMU_PLUGIN_DIR . '/wprism/src/Adapter/ProviderDatabaseSession.php';

use WPrism\Db;
use WPrism\NativeDatabaseProfile;
use WPrism\ProviderDatabaseSession;
use WPrism\TransientDbException;

global $wpdb;
$version = (string) $wpdb->get_var('SELECT VERSION()');
if (!str_contains($version, 'MariaDB')) throw new RuntimeException('snapshot conflict probe requires MariaDB');
$table = $wpdb->prefix . 'wprism_snapshot_conflict_probe';
$query = static function (string $sql) use ($wpdb): void {
    if ($wpdb->query($sql) === false) throw new RuntimeException('native snapshot-conflict fixture SQL failed');
};
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== null) {
    throw new RuntimeException('native snapshot-conflict fixture refuses to replace an existing table');
}
$query("CREATE TABLE `$table` (id bigint unsigned PRIMARY KEY, value varchar(64) NOT NULL) ENGINE=InnoDB");
$external = null;
try {
    // This is a connection-local test premise, never a fleet-global setting.
    $query('SET SESSION innodb_snapshot_isolation=ON');
    wprism_check_same('1', $wpdb->get_var('SELECT @@innodb_snapshot_isolation'), 'native probe enables snapshot conflict detection');
    $external = new class(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST) extends wpdb {
        public function nativeHandle(): mysqli { return $this->dbh; }
    };
    wprism_check($wpdb->get_var('SELECT CONNECTION_ID()') !== $external->get_var('SELECT CONNECTION_ID()'),
        'concurrent native writer uses a distinct connection');
    $query("INSERT INTO `$table` VALUES (1,'original'),(2,'untouched')");
    $rows = static fn(): array => $wpdb->get_results("SELECT * FROM `$table` ORDER BY id", ARRAY_A);
    $profile = new NativeDatabaseProfile([$table], [$table]);
    foreach (['locking read', 'mutation'] as $operation) {
        $query("UPDATE `$table` SET value=CASE id WHEN 1 THEN 'original' ELSE 'untouched' END");
        $classifierCalled = false;
        $failure = null;
        try {
            ProviderDatabaseSession::repeatable_read_write(
                "native snapshot $operation",
                $profile,
                static function () use ($wpdb, $table, $external, $operation): void {
                    $sql = "SELECT * FROM `$table` WHERE id=1 LIMIT 1";
                    $authority = Db::transaction_authority('native snapshot read authority');
                    wprism_check_same([['id' => '1', 'value' => 'original']],
                        Db::transactional_rows($sql, $authority, 'native initial read'),
                        "$operation begins with the original row in its snapshot");
                    Db::update($table, ['value' => 'pending write'], ['id' => 2], null, null, 'native prior mutation');
                    if (!$external->nativeHandle()->query("UPDATE `$table` SET value='external committed value' WHERE id=1")) {
                        throw new RuntimeException('concurrent snapshot fixture update failed');
                    }
                    wprism_check_same('original', $wpdb->get_var("SELECT value FROM `$table` WHERE id=1"),
                        "$operation retains the original consistent-read preimage");
                    if ($operation === 'locking read') {
                        Db::transactional_rows($sql . ' FOR UPDATE', $authority, 'native conflicting read');
                    } else {
                        Db::update($table, ['value' => 'candidate overwrite'], ['id' => 1], null, null, 'native conflicting mutation');
                    }
                },
                static function () use (&$classifierCalled): string {
                    $classifierCalled = true;
                    return ProviderDatabaseSession::POSTIMAGE_UNKNOWN;
                }
            );
        } catch (Throwable $caught) {
            $failure = $caught;
        }
        wprism_check($failure instanceof TransientDbException, "$operation exposes a retryable native snapshot conflict");
        wprism_check(str_contains($failure?->getMessage() ?? '', 'database snapshot conflict'),
            "$operation preserves the specific conflict instead of transaction uncertainty");
        wprism_check(!$classifierCalled, "$operation rollback does not invoke ambiguous-commit classification");
        wprism_check_same([['id' => '1', 'value' => 'external committed value'], ['id' => '2', 'value' => 'untouched']],
            $rows(), "$operation restores the prior write and preserves the external commit");
        ProviderDatabaseSession::repeatable_read_write('native post-conflict follow-up', $profile,
            static fn() => Db::update($table, ['value' => 'follow-up committed'], ['id' => 2], null, null, 'native post-conflict mutation'),
            static fn() => ProviderDatabaseSession::POSTIMAGE_UNKNOWN);
        wprism_check_same('follow-up committed', $rows()[1]['value'], "$operation leaves a settled connection for a fresh transaction");
    }
    echo 'Database: ', $version, '; WordPress: ', get_bloginfo('version'), '; PHP: ', PHP_VERSION, "\n";
} finally {
    if ($external !== null) $external->close();
    $query("DROP TABLE `$table`");
}
wprism_check_summary('native database snapshot conflicts');

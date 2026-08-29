<?php
/**
 * Offline regression for RepositoryIdentityRegistry (issue #3348 slice 39).
 *
 * UUID ownership and natural-key uniqueness are pure canonical-tree facts.
 * The registry reports through an injected compiler sink; it neither walks
 * files nor throws, so RepositoryCompiler can retain one aggregate refusal
 * and deterministic final diagnostic sort.
 */
declare(strict_types=1);

namespace WPrism {
    final class Canon {
        public static function encode(mixed $value): string {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }
    }

    final class Policy {
        /** @var array<string,list<string>> */
        public static array $naturalColumns = [];

        /** @return list<string> */
        public static function natural_key_columns(array $declaration): array {
            return self::$naturalColumns[(string) ($declaration['id'] ?? '')] ?? [];
        }
    }

    final class Snapshot {
        /** @var array<string,array<string,mixed>> */
        public static array $rows = [];

        /** @return array<string,array<string,mixed>> */
        public static function row_tables(Policy $policy): array {
            return self::$rows;
        }
    }
}

namespace {
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    final class IdentityBackupFakeWpdb {
        public string $last_error = '';
        /** @var list<array{meta_id:string,meta_key:string,meta_value:string}> */
        public array $rows = [];
        /** @var list<string> */
        public array $queries = [];
        public ?string $failure = null;
        public bool $mutatePayload = false;
        public string $connectionId = '8101';
        public string $activeTransaction = '0';
        public bool $ambiguousCommit = false;
        public int $rollbackQueries = 0;

        public function query(string $sql): int|false {
            $this->queries[] = $sql;
            if ($sql === 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') {
                return 1;
            }
            if ($sql === 'START TRANSACTION WITH CONSISTENT SNAPSHOT') {
                $this->activeTransaction = '1';
                return 1;
            }
            if ($sql === 'COMMIT') {
                $this->activeTransaction = '0';
                return $this->ambiguousCommit ? false : 1;
            }
            if ($sql === 'ROLLBACK') {
                $this->rollbackQueries++;
                $this->activeTransaction = '0';
                return 1;
            }
            throw new \RuntimeException("unsupported identity-backup mutation query: $sql");
        }

        public function get_var(string $sql): mixed {
            $this->queries[] = $sql;
            return match ($sql) {
                'SELECT CONNECTION_ID()' => $this->connectionId,
                'SELECT @@in_transaction' => $this->activeTransaction,
                default => throw new \RuntimeException("unsupported identity-backup scalar query: $sql"),
            };
        }

        public function prepare(string $sql, ...$args): string {
            foreach ($args as $arg) {
                $replacement = is_int($arg)
                    ? (string) $arg
                    : "'" . str_replace("'", "''", (string) $arg) . "'";
                $sql = preg_replace('/%[ds]/', $replacement, $sql, 1);
            }
            return $sql;
        }

        public function get_results(string $sql, mixed $mode): mixed {
            $this->queries[] = $sql;
            if ($mode !== ARRAY_A) {
                throw new \RuntimeException('identity-backup fixture expected ARRAY_A');
            }
            if ($this->failure === 'false') return false;
            if ($this->failure === 'null') return null;
            if ($this->failure === 'error') {
                $this->last_error = 'simulated identity witness read failure';
                return [];
            }
            if (str_contains($sql, 'OCTET_LENGTH(meta_key)')) {
                return array_map(static fn(array $row): array => [
                    'meta_id' => $row['meta_id'],
                    'meta_key' => $row['meta_key'],
                    'meta_key_bytes' => (string) strlen($row['meta_key']),
                    'meta_value_bytes' => (string) strlen($row['meta_value']),
                ], array_slice(array_values(array_filter(
                    $this->rows,
                    static fn(array $row): bool => strcasecmp($row['meta_key'], '_wprism_uuid') === 0
                )), 0, 3));
            }
            if (str_contains($sql, 'meta_id =')) {
                preg_match('/meta_id = ([0-9]+)/', $sql, $match);
                $metaId = (string) ($match[1] ?? '');
                return array_map(function (array $row): array {
                    return [
                        'meta_id' => $row['meta_id'],
                        'meta_key' => $row['meta_key'],
                        'meta_value' => $this->mutatePayload
                            ? str_repeat('f', strlen($row['meta_value']))
                            : $row['meta_value'],
                    ];
                }, array_slice(array_values(array_filter(
                    $this->rows,
                    static fn(array $row): bool => $row['meta_id'] === $metaId
                )), 0, 2));
            }
            throw new \RuntimeException("unsupported identity-backup query: $sql");
        }
    }

    $root = dirname(__DIR__, 4);
    $registryPath = "$root/agent/src/Repository/RepositoryIdentityRegistry.php";

    $failures = [];
    $check = static function (bool $ok, string $message) use (&$failures): void {
        echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
        if (!$ok) {
            $failures[] = $message;
        }
    };

    $child = proc_open(
        [PHP_BINARY, '-r', 'require $argv[1]; echo class_exists(\\WPrism\\Canon::class, false) && class_exists(\\WPrism\\Policy::class, false) && class_exists(\\WPrism\\Snapshot::class, false) && class_exists(\\WPrism\\RepositoryIdentityRegistry::class, false) && !class_exists(\\WPrism\\RepositoryCompiler::class, false) ? "loaded\\n" : "broken\\n";', $registryPath],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $childOut = is_resource($child) ? stream_get_contents($pipes[1]) : '';
    $childErr = is_resource($child) ? stream_get_contents($pipes[2]) : '';
    if (is_resource($child)) {
        fclose($pipes[1]);
        fclose($pipes[2]);
        $childExit = proc_close($child);
    } else {
        $childExit = 1;
    }
    $check(
        $childExit === 0 && $childOut === "loaded\n" && $childErr === '',
        'normal direct loading closes Canon, Policy, and Snapshot without loading RepositoryCompiler'
    );

    require_once $registryPath;
    require_once "$root/agent/src/Repository/IdentityBackup.php";

    use WPrism\Policy;
    use WPrism\Db;
    use WPrism\IdentityBackup;
    use WPrism\RepositoryIdentityRegistry;
    use WPrism\Snapshot;

    $diagnostics = [];
    $registry = new RepositoryIdentityRegistry(
        new Policy(),
        static function (string $code, string $path, string $locator, string $message, ?string $relatedPath = null) use (&$diagnostics): void {
            $diagnostics[] = compact('code', 'path', 'locator', 'message', 'relatedPath');
        }
    );
    $uuid = '11111111-1111-4111-8111-111111111111';
    $check(!$registry->register('not-a-uuid', 'post', 'posts/page/bad.md') && $diagnostics === [],
        'invalid UUID registration remains caller-owned and emits no duplicate diagnosis');
    $check($registry->register($uuid, 'post', 'posts/page/one.md'), 'the first valid UUID claimant registers');
    $check(!$registry->register($uuid, 'term', 'terms/category/two.json'), 'a second claimant is refused');
    $check(
        $registry->find($uuid) === ['kind' => 'post', 'path' => 'posts/page/one.md']
        && $diagnostics === [[
            'code' => 'duplicate_uuid', 'path' => 'terms/category/two.json', 'locator' => 'uuid',
            'message' => "uuid $uuid is already used by posts/page/one.md", 'relatedPath' => 'posts/page/one.md',
        ]],
        'the first UUID claimant remains the lookup and related-path witness'
    );

    $diagnostics = [];
    Snapshot::$rows = [
        'booking' => ['id' => 'booking', 'identity' => ['mode' => 'natural_key']],
    ];
    Policy::$naturalColumns = ['booking' => ['room', 'slot']];
    $registry->validate_natural_identities([
        'post-a' => ['type' => 'post', 'path' => 'posts/page/a.md', 'data' => ['type' => 'page', 'slug' => 'same', 'parent' => null]],
        'post-b' => ['type' => 'post', 'path' => 'posts/page/b.md', 'data' => ['type' => 'page', 'slug' => 'same', 'parent' => null]],
        'term-a' => ['type' => 'term', 'path' => 'terms/category/a.json', 'data' => ['taxonomy' => 'category', 'slug' => 'same']],
        'menu-a' => ['type' => 'menu', 'path' => 'menus/a.json', 'data' => ['slug' => 'same']],
        'booking-a' => ['type' => 'booking', 'path' => 'tables/booking/a.json', 'data' => ['columns' => ['room' => 'red', 'slot' => 'morning']]],
        'booking-b' => ['type' => 'booking', 'path' => 'tables/booking/b.json', 'data' => ['columns' => ['room' => 'red', 'slot' => 'evening']]],
        'booking-c' => ['type' => 'booking', 'path' => 'tables/booking/c.json', 'data' => ['columns' => ['room' => 'red', 'slot' => 'morning']]],
    ]);
    $check(
        count($diagnostics) === 2
        && $diagnostics[0]['code'] === 'duplicate_natural_identity'
        && $diagnostics[0]['path'] === 'posts/page/b.md'
        && $diagnostics[0]['relatedPath'] === 'posts/page/a.md'
        && $diagnostics[1]['code'] === 'duplicate_natural_identity'
        && $diagnostics[1]['path'] === 'tables/booking/c.json'
        && $diagnostics[1]['relatedPath'] === 'tables/booking/a.json',
        'post natural keys and complete ordered table tuples reject only exact first-wins collisions'
    );

    $compiler = (string) file_get_contents("$root/agent/src/Repository/RepositoryCompiler.php");
    $registrySource = (string) file_get_contents($registryPath);
    $graphValidatorSource = (string) file_get_contents("$root/agent/src/Repository/RepositoryReferenceGraphValidator.php");
    $check(
        substr_count($compiler, 'new RepositoryIdentityRegistry(') === 1
        && substr_count($compiler, '->identityRegistry->register(') === 3
        && substr_count($compiler, '->identityRegistry->validate_natural_identities($tree)') === 1
        && substr_count($compiler, '->identityRegistry->find(') === 1
        && substr_count($graphValidatorSource, '->identities->find(') === 1
        && !str_contains($compiler, 'private function register_identity(')
        && !str_contains($compiler, 'private function validate_natural_identities(')
        && str_contains($registrySource, 'private function add('),
        'RepositoryCompiler and its graph validator share one identity registry without retaining duplicate bodies'
    );

    $identityWitness = new ReflectionMethod(IdentityBackup::class, 'assert_embedded_uuid');
    $identityUuid = '11111111-1111-7111-8111-111111111111';
    $invokeIdentityWitness = static function (IdentityBackupFakeWpdb $wpdb) use (
        $identityWitness,
        $identityUuid
    ): ?Throwable {
        $GLOBALS['wpdb'] = $wpdb;
        try {
            $identityWitness->invoke(null, 'wp_postmeta', 'post_id', 41, $identityUuid, 'identity backup regression');
            return null;
        } catch (Throwable $failure) {
            return $failure;
        }
    };

    $wpdb = new IdentityBackupFakeWpdb();
    $wpdb->rows = [[
        'meta_id' => '7', 'meta_key' => '_wprism_uuid', 'meta_value' => $identityUuid,
    ]];
    $check(
        $invokeIdentityWitness($wpdb) === null
            && count($wpdb->queries) === 2
            && str_contains($wpdb->queries[0], 'OCTET_LENGTH(meta_value)')
            && !str_contains($wpdb->queries[0], 'SHA2(')
            && str_contains($wpdb->queries[1], 'meta_id = 7'),
        'identity backup binds one exact 36-byte embedded UUID through a compact frontier before payload transfer'
    );

    foreach ([
        'alias-only' => [['_WPRISM_UUID', $identityUuid]],
        'exact-plus-alias' => [['_wprism_uuid', $identityUuid], ['_WPRISM_UUID', $identityUuid]],
        'duplicate-exact' => [['_wprism_uuid', $identityUuid], ['_wprism_uuid', $identityUuid]],
        'oversized' => [['_wprism_uuid', $identityUuid . 'x']],
    ] as $case => $fixtureRows) {
        $wpdb = new IdentityBackupFakeWpdb();
        foreach ($fixtureRows as $index => [$key, $value]) {
            $wpdb->rows[] = [
                'meta_id' => (string) ($index + 1), 'meta_key' => $key, 'meta_value' => $value,
            ];
        }
        $failure = $invokeIdentityWitness($wpdb);
        $check(
            $failure instanceof Throwable
                && count($wpdb->queries) === 1
                && !str_contains($wpdb->queries[0], 'SHA2('),
            "identity backup refuses $case embedded identity before payload transfer or hashing"
        );
    }

    foreach (['false', 'null', 'error'] as $mode) {
        $wpdb = new IdentityBackupFakeWpdb();
        $wpdb->failure = $mode;
        $failure = $invokeIdentityWitness($wpdb);
        $check(
            $failure instanceof Throwable && count($wpdb->queries) === 1,
            "identity backup treats a $mode compact identity read as failure"
        );
    }

    $wpdb = new IdentityBackupFakeWpdb();
    $wpdb->rows = [[
        'meta_id' => '7', 'meta_key' => '_wprism_uuid', 'meta_value' => $identityUuid,
    ]];
    $wpdb->mutatePayload = true;
    $failure = $invokeIdentityWitness($wpdb);
    $check(
        $failure instanceof Throwable && count($wpdb->queries) === 2,
        'identity backup refuses payload drift after the compact identity witness'
    );

    $transactionWpdb = new IdentityBackupFakeWpdb();
    $transactionWpdb->ambiguousCommit = true;
    $GLOBALS['wpdb'] = $transactionWpdb;
    Db::forget_transaction_tracking();
    Db::start_consistent_snapshot('identity transaction outcome regression start');
    try {
        Db::commit('identity transaction outcome regression commit');
        $commitFailure = null;
    } catch (Throwable $failure) {
        $commitFailure = $failure;
    }
    $identityRollback = new ReflectionMethod(IdentityBackup::class, 'rollback_after_failure');
    try {
        $identityRollback->invoke(
            null,
            $commitFailure ?? new RuntimeException('missing commit failure'),
            'identity transaction outcome regression rollback'
        );
        $identityRecovery = null;
    } catch (Throwable $failure) {
        $identityRecovery = $failure;
    }
    try {
        Db::start('identity transaction outcome accidental retry');
        $identityRetryBlocked = false;
    } catch (Throwable $failure) {
        $identityRetryBlocked = $failure instanceof \WPrism\DatabaseTransactionOutcomeException;
    }
    $check(
        $commitFailure instanceof \WPrism\DatabaseTransactionOutcomeException
            && $identityRecovery instanceof \WPrism\DatabaseTransactionOutcomeException
            && $identityRecovery->getPrevious() === $commitFailure
            && $transactionWpdb->rollbackQueries === 0
            && $identityRetryBlocked,
        'identity recovery retains an inactive ambiguous COMMIT and never compensates through autocommit'
    );
    Db::forget_transaction_tracking();

    if ($failures !== []) {
        fwrite(STDERR, "\nFAILED " . count($failures) . " assertion(s)\n");
        exit(1);
    }
    echo "\nALL PASSED\n";
}

<?php
/**
 * Product-path regression for exact-login authored user-meta reconciliation.
 * Unknown SQL is fatal, so a refactor cannot silently turn a lock into an
 * empty read. Failures restore the fixture snapshot like the outer authored
 * transaction executor's rollback boundary.
 */
declare(strict_types=1);

if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
if (!function_exists('untrailingslashit')) {
    function untrailingslashit($value): string { return rtrim((string) $value, '/\\'); }
}
if (!function_exists('maybe_serialize')) {
    function maybe_serialize($value) {
        return is_array($value) || is_object($value) || $value === null || is_bool($value)
            ? serialize($value) : $value;
    }
}
$cacheEvents = [];
$cacheGenerationEvents = [];
$cacheGenerationCalls = 0;
$cacheGenerationThrowAt = null;
$cacheDeleteResult = false;
$cacheDeleteCalls = 0;
$cacheDeleteThrowAt = null;
function wp_cache_delete($key, $group = ''): bool {
    global $cacheEvents, $cacheDeleteResult, $cacheDeleteCalls, $cacheDeleteThrowAt;
    ++$cacheDeleteCalls;
    $cacheEvents[] = [(string) $group, (string) $key];
    if ($cacheDeleteThrowAt === $cacheDeleteCalls) {
        throw new RuntimeException('fixture first cache primitive failed');
    }
    return $cacheDeleteResult;
}
function wp_cache_set($key, $value, $group = '', $expire = 0): bool {
    global $cacheGenerationEvents, $cacheGenerationCalls, $cacheGenerationThrowAt;
    ++$cacheGenerationCalls;
    $cacheGenerationEvents[] = [(string) $group, (string) $key];
    if ($cacheGenerationThrowAt === $cacheGenerationCalls) {
        throw new RuntimeException('fixture last cache primitive failed');
    }
    return true;
}

require_once __DIR__ . '/../../../../agent/src/Kernel/TransientDbException.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Grammar/Tokens.php';
require_once __DIR__ . '/../../../../agent/src/Apply/ApplyFieldMaterializer.php';
require_once __DIR__ . '/../../../../agent/src/Apply/UserMetaMaterializer.php';

use WPrism\ApplyFieldMaterializer;
use WPrism\MetaRows;
use WPrism\Policy;
use WPrism\Tokens;
use WPrism\UserMetaMaterializer;

final class UserMetaMaterializerWpdb {
    public string $prefix = 'wp_';
    public string $users = 'wp_users';
    public string $usermeta = 'wp_usermeta';
    public string $last_error = '';
    public int $insert_id = 0;
    public mixed $transactionState = '1';
    public mixed $isolation = 'REPEATABLE-READ';
    public bool $savepointExists = false;
    public ?string $metadataFailureTable = null;
    public ?string $engineFailureTable = null;
    public ?string $missingIndexTable = null;
    public mixed $forcedLoginRows = null;
    public mixed $forcedMetaRows = null;
    public bool $metaLookupError = false;
    public ?string $mutationFailure = null;
    /** @var list<array{ID:int,user_login:string}> */
    public array $userRows = [];
    /** @var list<array{umeta_id:int,user_id:int,meta_key:string,meta_value:?string}> */
    public array $metaRows = [];
    /** @var list<string> */
    public array $queries = [];
    /** @var list<string> */
    public array $mutations = [];

    public function prepare(string $sql, ...$args): string {
        foreach ($args as $arg) {
            $replacement = is_int($arg) ? (string) $arg : "'" . str_replace("'", "''", (string) $arg) . "'";
            $sql = preg_replace('/%[ds]/', $replacement, $sql, 1);
        }
        return $sql;
    }

    public function query(string $sql): int|false {
        $this->queries[] = $sql;
        if (preg_match('/^SAVEPOINT `wprism_authored_[0-9a-f]{24}`$/D', $sql) === 1) {
            $this->savepointExists = true;
            return 1;
        }
        if (preg_match('/^RELEASE SAVEPOINT `wprism_authored_[0-9a-f]{24}`$/D', $sql) === 1) {
            if (!$this->savepointExists) {
                $this->last_error = 'SAVEPOINT does not exist';
                return false;
            }
            $this->savepointExists = false;
            return 1;
        }
        throw new RuntimeException("unrecognized query: $sql");
    }

    public function get_var(string $sql): mixed {
        $this->queries[] = $sql;
        $trimmed = trim($sql);
        if ($trimmed === 'SELECT @@in_transaction') return $this->transactionState;
        if ($trimmed === 'SELECT @@transaction_isolation') return $this->isolation;
        if (preg_match('/^SELECT 1 FROM `(wp_users|wp_usermeta)` LIMIT 1$/D', $trimmed, $m) === 1) {
            if ($this->metadataFailureTable === $m[1]) {
                $this->last_error = 'simulated metadata-lock failure';
                return false;
            }
            return '1';
        }
        if (str_contains($sql, 'SELECT `umeta_id` FROM `wp_usermeta`')) {
            if ($this->metaLookupError) {
                $this->last_error = 'simulated meta identity lookup failure';
                return null;
            }
            preg_match('/`user_id` = ([0-9]+)/', $sql, $ownerMatch);
            preg_match("/meta_key = '((?:''|[^'])*)'/", $sql, $keyMatch);
            $owner = (int) ($ownerMatch[1] ?? 0);
            $key = str_replace("''", "'", (string) ($keyMatch[1] ?? ''));
            foreach ($this->metaRows as $row) {
                if ($row['user_id'] === $owner && strcasecmp($row['meta_key'], $key) === 0) {
                    return (string) $row['umeta_id'];
                }
            }
            return null;
        }
        throw new RuntimeException("unrecognized get_var query: $sql");
    }

    public function get_results(string $sql, mixed $mode): mixed {
        $this->queries[] = $sql;
        if ($mode !== ARRAY_A) throw new RuntimeException('fixture expected ARRAY_A');
        if (str_contains($sql, 'information_schema.TABLES')) {
            return array_map(fn(string $table): array => [
                'TABLE_NAME' => $table,
                'ENGINE' => $this->engineFailureTable === $table ? 'MyISAM' : 'InnoDB',
            ], [$this->usermeta, $this->users]);
        }
        if (str_starts_with($sql, 'SHOW INDEX FROM `wp_users`')) {
            return $this->missingIndexTable === $this->users ? [] : [$this->index('user_login_key', 'user_login')];
        }
        if (str_starts_with($sql, 'SHOW INDEX FROM `wp_usermeta`')) {
            return $this->missingIndexTable === $this->usermeta ? [] : [$this->index('user_id', 'user_id')];
        }
        if (str_contains($sql, 'SELECT ID, user_login FROM wp_users FORCE INDEX (`user_login_key`)')) {
            if ($this->forcedLoginRows !== null) return $this->forcedRead($this->forcedLoginRows, 'login');
            preg_match("/user_login = '((?:''|[^'])*)'/", $sql, $match);
            $login = str_replace("''", "'", (string) ($match[1] ?? ''));
            $rows = array_values(array_filter($this->userRows,
                static fn(array $row): bool => strcasecmp($row['user_login'], $login) === 0));
            usort($rows, static fn(array $a, array $b): int => $a['ID'] <=> $b['ID']);
            return array_map(static fn(array $row): array => [
                'ID' => (string) $row['ID'], 'user_login' => $row['user_login'],
            ], array_slice($rows, 0, 3));
        }
        if (str_contains($sql, 'FROM `wp_usermeta` FORCE INDEX (`user_id`)')) {
            $sizePreflight = str_contains($sql, 'OCTET_LENGTH(meta_key)');
            $hashWitness = str_contains($sql, 'SHA2(meta_key, 256)');
            if ($this->forcedMetaRows !== null) {
                if ($this->forcedMetaRows === 'oversized-value') {
                    return [['meta_id' => '51', 'meta_key_bytes' => '15',
                        'meta_value_bytes' => (string) (MetaRows::MAX_META_VALUE_BYTES + 1)]];
                }
                $forced = $this->forcedRead($this->forcedMetaRows, 'meta');
                if (($sizePreflight || $hashWitness) && is_array($forced) && array_is_list($forced)) {
                    return array_map(static function ($row) use ($sizePreflight) {
                        if (!is_array($row)
                            || array_keys($row) !== ['meta_id', 'meta_key', 'meta_value']
                            || !is_string($row['meta_key'] ?? null)
                            || !(is_string($row['meta_value'] ?? null) || ($row['meta_value'] ?? null) === null)) {
                            return $row;
                        }
                        return $sizePreflight ? [
                            'meta_id' => $row['meta_id'],
                            'meta_key_bytes' => (string) strlen($row['meta_key']),
                            'meta_value_bytes' => $row['meta_value'] === null
                                ? null
                                : (string) strlen($row['meta_value']),
                        ] : [
                            'meta_id' => $row['meta_id'],
                            'meta_key_sha256' => hash('sha256', $row['meta_key']),
                            'meta_value_sha256' => $row['meta_value'] === null
                                ? null
                                : hash('sha256', $row['meta_value']),
                        ];
                    }, $forced);
                }
                return $forced;
            }
            preg_match('/`user_id` = ([0-9]+)/', $sql, $match);
            $owner = (int) ($match[1] ?? 0);
            $rows = array_values(array_filter($this->metaRows,
                static fn(array $row): bool => $row['user_id'] === $owner));
            usort($rows, static fn(array $a, array $b): int => $a['umeta_id'] <=> $b['umeta_id']);
            return array_map(static fn(array $row): array => $sizePreflight ? [
                'meta_id' => (string) $row['umeta_id'],
                'meta_key_bytes' => (string) strlen($row['meta_key']),
                'meta_value_bytes' => $row['meta_value'] === null
                    ? null
                    : (string) strlen($row['meta_value']),
            ] : ($hashWitness ? [
                'meta_id' => (string) $row['umeta_id'],
                'meta_key_sha256' => hash('sha256', $row['meta_key']),
                'meta_value_sha256' => $row['meta_value'] === null
                    ? null
                    : hash('sha256', $row['meta_value']),
            ] : [
                'meta_id' => (string) $row['umeta_id'],
                'meta_key' => $row['meta_key'],
                'meta_value' => $row['meta_value'],
            ]), $rows);
        }
        throw new RuntimeException("unrecognized get_results query: $sql");
    }

    private function index(string $name, string $column): array {
        return ['Key_name' => $name, 'Seq_in_index' => '1', 'Column_name' => $column,
            'Sub_part' => null, 'Non_unique' => '1', 'Index_type' => 'BTREE'];
    }

    private function forcedRead(mixed $value, string $kind): mixed {
        if ($value === 'false') return false;
        if ($value === 'null') return null;
        if ($value === 'error') {
            $this->last_error = "simulated locked $kind read failure";
            return [];
        }
        return $value;
    }

    public function update(string $table, array $data, array $where, $format = null, $whereFormat = null): int|false {
        $this->mutations[] = 'update';
        if ($this->mutationFailure === 'update') {
            $this->last_error = 'simulated update failure';
            return false;
        }
        foreach ($this->metaRows as &$row) {
            if ($table === $this->usermeta && $row['umeta_id'] === (int) ($where['umeta_id'] ?? 0)) {
                $row = array_merge($row, $data);
                unset($row);
                return 1;
            }
        }
        unset($row);
        return 0;
    }

    public function insert(string $table, array $data, $format = null): int|false {
        $this->mutations[] = 'insert';
        if ($this->mutationFailure === 'insert') {
            $this->last_error = 'simulated insert failure';
            return false;
        }
        $ids = array_column($this->metaRows, 'umeta_id');
        $this->insert_id = $ids === [] ? 1 : max($ids) + 1;
        $this->metaRows[] = ['umeta_id' => $this->insert_id] + $data;
        return 1;
    }

    public function delete(string $table, array $where, $whereFormat = null): int|false {
        $this->mutations[] = 'delete';
        if ($this->mutationFailure === 'delete') {
            $this->last_error = 'simulated delete failure';
            return false;
        }
        $before = count($this->metaRows);
        $id = (int) ($where['umeta_id'] ?? 0);
        $this->metaRows = array_values(array_filter($this->metaRows,
            static fn(array $row): bool => $row['umeta_id'] !== $id));
        return $before - count($this->metaRows);
    }
}

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) $failures[] = $message;
};

$policy = new Policy();
$policy->manifests = [['name' => 'user-meta-lock-fixture', 'interpreter' => 'nullable-fixture', 'user_meta' => [
    'description_en' => ['class' => 'authored', 'allow_pii' => true, 'missing_user' => 'block'],
    'authored_absent' => ['class' => 'authored'],
    'authored_new' => ['class' => 'authored'],
    'runtime_sibling' => ['class' => 'runtime'],
]]];
$nullableInterpreter = new class {
    public function post_meta_rule(string $key, array $flat): ?array { return null; }
    public function user_meta_rule(string $key, array $flat): ?array {
        if ($key !== 'nullable_owned') {
            return null;
        }
        // UserMetaCapture deliberately maps SQL NULL to WordPress's historic
        // empty-string view; apply must use that same first-row context.
        return array_key_exists('nullable_marker', $flat) && $flat['nullable_marker'] === ''
            ? ['class' => 'authored']
            : ['class' => 'runtime'];
    }
};
$interpreterInstances = new ReflectionProperty(Policy::class, 'interpreterInstances');
$interpreterInstances->setValue($policy, ['nullable-fixture' => $nullableInterpreter]);
$tokens = new Tokens('https://source.test', 'https://source.test/wp-content/uploads');
$field = new ApplyFieldMaterializer($policy, $tokens);
$subject = new UserMetaMaterializer($policy, $tokens, $field);
\WPrism\CacheInvalidationTransaction::begin();
$wpdb = new UserMetaMaterializerWpdb();
$wpdb->userRows = [['ID' => 17, 'user_login' => 'Editor'], ['ID' => 18, 'user_login' => 'Viewer']];
$wpdb->metaRows = [
    ['umeta_id' => 40, 'user_id' => 17, 'meta_key' => 'DESCRIPTION_EN', 'meta_value' => 'preserve-alias'],
    ['umeta_id' => 41, 'user_id' => 17, 'meta_key' => 'description_en', 'meta_value' => 'old'],
    ['umeta_id' => 42, 'user_id' => 17, 'meta_key' => 'description_en', 'meta_value' => 'duplicate'],
    ['umeta_id' => 43, 'user_id' => 17, 'meta_key' => 'authored_absent', 'meta_value' => 'remove'],
    ['umeta_id' => 44, 'user_id' => 17, 'meta_key' => 'runtime_sibling', 'meta_value' => 'preserve'],
    ['umeta_id' => 46, 'user_id' => 17, 'meta_key' => 'runtime_sibling_null', 'meta_value' => null],
    ['umeta_id' => 47, 'user_id' => 17, 'meta_key' => 'nullable_marker', 'meta_value' => null],
    ['umeta_id' => 48, 'user_id' => 17, 'meta_key' => 'nullable_marker', 'meta_value' => 'later-must-not-win'],
    ['umeta_id' => 49, 'user_id' => 17, 'meta_key' => 'nullable_owned', 'meta_value' => 'remove'],
    ['umeta_id' => 45, 'user_id' => 18, 'meta_key' => 'runtime_sibling', 'meta_value' => 'viewer-preserve'],
];

$apply = static function (array $front) use ($field, $subject, $wpdb): ?Throwable {
    $before = $wpdb->metaRows;
    try {
        $field->begin_authored_transaction();
        $subject->finalize_user_meta($front);
        return null;
    } catch (Throwable $failure) {
        $wpdb->metaRows = $before;
        return $failure;
    }
};

$field->begin_authored_transaction();
$subject->finalize_user_meta(['login' => 'Editor',
    'meta' => ['description_en' => 'new', 'authored_new' => 'created']]);
$editor = array_values(array_filter($wpdb->metaRows, static fn(array $row): bool => $row['user_id'] === 17));
$check(
    count(array_filter($editor, static fn(array $row): bool => $row['meta_key'] === 'description_en'
        && $row['meta_value'] === 'new')) === 1
        && count(array_filter($editor, static fn(array $row): bool => $row['meta_key'] === 'authored_absent')) === 0
        && count(array_filter($editor, static fn(array $row): bool => $row['meta_key'] === 'authored_new'
            && $row['meta_value'] === 'created')) === 1
        && count(array_filter($editor, static fn(array $row): bool => $row['meta_key'] === 'runtime_sibling'
            && $row['meta_value'] === 'preserve')) === 1
        && count(array_filter($editor, static fn(array $row): bool => $row['meta_key'] === 'runtime_sibling_null'
            && $row['meta_value'] === null)) === 1
        && count(array_filter($editor, static fn(array $row): bool => $row['meta_key'] === 'DESCRIPTION_EN'
            && $row['meta_value'] === 'preserve-alias')) === 1
        && count(array_filter($editor, static fn(array $row): bool => $row['meta_key'] === 'nullable_owned')) === 0,
    'product path canonicalizes exact authored rows, preserves collation-equal aliases/runtime SQL NULL, and uses its empty-string first-row context'
);
$check(
    $cacheEvents === [['user_meta', '17']]
        && $cacheGenerationEvents === [['users', 'last_changed']],
    'successful user-meta reconciliation purges the owner cache and advances the users query generation'
);
$loginLock = static fn(string $sql): bool => str_contains($sql, 'FROM wp_users FORCE INDEX (`user_login_key`)')
    && str_contains($sql, 'LIMIT 3 FOR UPDATE');
$ownerLock = static fn(string $sql): bool => str_contains($sql, 'FROM `wp_usermeta` FORCE INDEX (`user_id`)')
    && str_contains($sql, 'LIMIT 100001 FOR UPDATE');
$check(count(array_filter($wpdb->queries, $loginLock)) === 1,
    'exact-login identity uses one bounded indexed FOR UPDATE range');
$check(count(array_filter($wpdb->queries, $ownerLock)) === 3
    && count(array_filter($wpdb->queries,
        static fn(string $sql): bool => str_contains($sql, 'OCTET_LENGTH(meta_key)'))) === 1
    && count(array_filter($wpdb->queries,
        static fn(string $sql): bool => str_contains($sql, 'SHA2(meta_key, 256)'))) === 1,
    'bounded size, hash, and payload witnesses lock the complete user-meta owner range before writes');

$wpdb->queries = [];
$field->begin_authored_transaction();
$subject->finalize_user_meta(['login' => 'Editor', 'meta' => ['description_en' => 'editor-2']]);
$subject->finalize_user_meta(['login' => 'Viewer', 'meta' => ['description_en' => 'viewer-2']]);
$check(
    count(array_filter($wpdb->queries, static fn(string $sql): bool => str_contains($sql, 'information_schema.TABLES'))) === 1
        && count(array_filter($wpdb->queries, static fn(string $sql): bool => str_starts_with($sql, 'SHOW INDEX FROM `wp_users`'))) === 1
        && count(array_filter($wpdb->queries, static fn(string $sql): bool => str_starts_with($sql, 'SHOW INDEX FROM `wp_usermeta`'))) === 1
        && count(array_filter($wpdb->queries, $loginLock)) === 2
        && count(array_filter($wpdb->queries, $ownerLock)) === 6,
    'multi-user transaction reuses engine/index descriptors while locking each login and owner independently'
);

$beforeLostTransaction = $wpdb->metaRows;
$beforeLostMutations = count($wpdb->mutations);
$wpdb->transactionState = '0';
try {
    $subject->finalize_user_meta(['login' => 'Editor', 'meta' => ['description_en' => 'outside-txn']]);
    $lostTransactionRefused = false;
} catch (Throwable $failure) {
    $lostTransactionRefused = str_contains($failure->getMessage(), 'requires an active transaction');
}
$check($lostTransactionRefused && $wpdb->metaRows === $beforeLostTransaction
    && count($wpdb->mutations) === $beforeLostMutations,
    'transaction loss between owners refuses owner two despite cached engine/index descriptors');
$wpdb->transactionState = '1';
$proofCount = count(array_filter($wpdb->queries,
    static fn(string $sql): bool => str_contains($sql, 'information_schema.TABLES')));
$field->end_authored_transaction();
$field->begin_authored_transaction();
$subject->finalize_user_meta(['login' => 'Editor', 'meta' => ['description_en' => 'new-invocation']]);
$check(count(array_filter($wpdb->queries,
    static fn(string $sql): bool => str_contains($sql, 'information_schema.TABLES'))) === $proofCount + 1,
    'ending an authored transaction clears descriptors before a second executor invocation');

foreach (['0', '01', '1.0', '1junk', 1, 1.0, true, false, null] as $state) {
    $wpdb->transactionState = $state;
    $beforeMutations = count($wpdb->mutations);
    $failure = $apply(['login' => 'Editor', 'meta' => ['description_en' => 'unsafe']]);
    $check($failure !== null && str_contains($failure->getMessage(), 'requires an active transaction')
        && count($wpdb->mutations) === $beforeMutations,
        'malformed/inactive transaction state ' . json_encode($state) . ' refuses before mutation');
}
$wpdb->transactionState = '1';

$field->begin_authored_transaction();
$wpdb->savepointExists = false;
$beforeRestartMutations = count($wpdb->mutations);
try {
    $subject->finalize_user_meta(['login' => 'Editor', 'meta' => ['description_en' => 'restarted']]);
    $restartRefused = false;
} catch (Throwable $failure) {
    $restartRefused = str_contains($failure->getMessage(), 'lost authored transaction continuity');
}
$check(
    $restartRefused && count($wpdb->mutations) === $beforeRestartMutations,
    'same-isolation COMMIT plus START cannot reuse cached user/login lock descriptors'
);

$cases = [
    'metadata lock error' => static fn() => $wpdb->metadataFailureTable = $wpdb->users,
    'unsupported engine' => static fn() => $wpdb->engineFailureTable = $wpdb->usermeta,
    'missing login index' => static fn() => $wpdb->missingIndexTable = $wpdb->users,
    'missing owner index' => static fn() => $wpdb->missingIndexTable = $wpdb->usermeta,
    'failed login read' => static fn() => $wpdb->forcedLoginRows = 'error',
    'ambiguous login range' => static fn() => $wpdb->forcedLoginRows = [
        ['ID' => '17', 'user_login' => 'Editor'], ['ID' => '19', 'user_login' => 'editor']],
    'malformed login identity' => static fn() => $wpdb->forcedLoginRows = [['ID' => '01', 'user_login' => 'Editor']],
    'failed owner read' => static fn() => $wpdb->forcedMetaRows = 'error',
    'malformed owner row' => static fn() => $wpdb->forcedMetaRows = [[
        'meta_id' => '51', 'meta_key' => ['bad'], 'meta_value' => 'value']],
    'oversized owner value' => static fn() => $wpdb->forcedMetaRows = 'oversized-value',
];
foreach ($cases as $label => $configure) {
    $wpdb->isolation = 'REPEATABLE-READ';
    $wpdb->metadataFailureTable = $wpdb->engineFailureTable = $wpdb->missingIndexTable = null;
    $wpdb->forcedLoginRows = $wpdb->forcedMetaRows = null;
    $configure();
    $before = $wpdb->metaRows;
    $beforeMutations = count($wpdb->mutations);
    $failure = $apply(['login' => 'Editor', 'meta' => ['description_en' => 'unsafe']]);
    $check($failure !== null && $wpdb->metaRows === $before && count($wpdb->mutations) === $beforeMutations,
        "$label refuses through finalize_user_meta before mutation");
}
$wpdb->isolation = 'REPEATABLE-READ';
$wpdb->forcedLoginRows = $wpdb->forcedMetaRows = null;

$wpdb->metaRows[] = ['umeta_id' => 90, 'user_id' => 17,
    'meta_key' => 'description_en', 'meta_value' => 'duplicate-again'];
$beforeWriteFailure = $wpdb->metaRows;
$wpdb->mutationFailure = 'update';
$failure = $apply(['login' => 'Editor', 'meta' => ['description_en' => 'after-retry']]);
$check($failure !== null && str_contains($failure->getMessage(), 'database mutation failed')
    && $wpdb->metaRows === $beforeWriteFailure,
    'write failure after an earlier delete is restored by the authored rollback boundary');
$wpdb->mutationFailure = null;
$failure = $apply(['login' => 'Editor', 'meta' => ['description_en' => 'after-retry']]);
$editorDescriptions = array_values(array_filter($wpdb->metaRows, static fn(array $row): bool =>
    $row['user_id'] === 17 && $row['meta_key'] === 'description_en'));
$check($failure === null && count($editorDescriptions) === 1
    && $editorDescriptions[0]['meta_value'] === 'after-retry',
    'same-process retry re-locks and converges to one authored row');

$check(count(array_filter(
    $wpdb->queries,
    static fn(string $sql): bool => str_contains($sql, 'SELECT `umeta_id` FROM `wp_usermeta`')
        && str_contains($sql, 'meta_key =')
)) === 0, 'user-meta authored upsert uses the locked exact-key identities without a collation-equality lookup');

$wpdb->userRows = array_values(array_filter($wpdb->userRows,
    static fn(array $row): bool => $row['user_login'] !== 'Editor'));
$beforeMissing = $wpdb->metaRows;
$failure = $apply(['login' => 'Editor', 'meta' => ['description_en' => 'orphan']]);
$check($failure !== null && str_contains($failure->getMessage(), 'disappeared after preflight')
    && $wpdb->metaRows === $beforeMissing,
    'a deleted exact user refuses before orphaning user-meta state');

\WPrism\CacheInvalidationTransaction::end();
\WPrism\CacheInvalidationTransaction::begin();
$cacheEvents = [];
$cacheGenerationEvents = [];
$cacheDeleteCalls = 0;
$cacheDeleteThrowAt = 1;
try {
    \WPrism\CacheInvalidationTransaction::queue_user_meta(17, 'user-meta composite failure fixture');
    $compositeFailure = null;
} catch (Throwable $failure) {
    $compositeFailure = $failure;
}
$check(
    $compositeFailure !== null
        && str_contains($compositeFailure->getMessage(), 'complete composite')
        && $cacheEvents === [['user_meta', '17']]
        && $cacheGenerationEvents === [['users', 'last_changed']],
    'a first owner-cache failure still attempts the already-registered users generation'
);
$cacheDeleteThrowAt = null;
\WPrism\CacheInvalidationTransaction::finish();
$check(
    $cacheEvents === [['user_meta', '17'], ['user_meta', '17']]
        && $cacheGenerationEvents === [['users', 'last_changed'], ['users', 'last_changed']],
    'post-outcome finish retries every user-meta composite primitive registered before the failure'
);
\WPrism\CacheInvalidationTransaction::end();
\WPrism\CacheInvalidationTransaction::begin();
$cacheEvents = [];
$cacheGenerationEvents = [];
$cacheDeleteCalls = 0;
$cacheDeleteThrowAt = null;
$cacheGenerationCalls = 0;
$cacheGenerationThrowAt = 1;
try {
    \WPrism\CacheInvalidationTransaction::queue_user_meta(17, 'user-meta generation failure fixture');
    $generationFailure = null;
} catch (Throwable $failure) {
    $generationFailure = $failure;
}
$check(
    $generationFailure !== null
        && str_contains($generationFailure->getMessage(), 'complete composite')
        && $cacheEvents === [['user_meta', '17']]
        && $cacheGenerationEvents === [['users', 'last_changed']],
    'a last generation failure occurs only after the owner-cache primitive was attempted'
);
$cacheGenerationThrowAt = null;
\WPrism\CacheInvalidationTransaction::finish();
$check(
    $cacheEvents === [['user_meta', '17'], ['user_meta', '17']]
        && $cacheGenerationEvents === [['users', 'last_changed'], ['users', 'last_changed']],
    'post-outcome finish retries both user-meta primitives after a last-position failure'
);
\WPrism\CacheInvalidationTransaction::end();
\WPrism\CacheInvalidationTransaction::begin();
$cacheEvents = [];
$cacheGenerationEvents = [];
$cacheDeleteCalls = 0;
$cacheDeleteThrowAt = 2;
$cacheGenerationCalls = 0;
$cacheGenerationThrowAt = null;
try {
    \WPrism\CacheInvalidationTransaction::queue_option('fixture_option', 'middle cache failure fixture');
    $middleFailure = null;
} catch (Throwable $failure) {
    $middleFailure = $failure;
}
$optionComposite = [
    ['options', 'fixture_option'],
    ['options', 'alloptions'],
    ['options', 'notoptions'],
];
$check(
    $middleFailure !== null
        && str_contains($middleFailure->getMessage(), 'complete composite')
        && $cacheEvents === $optionComposite,
    'a middle primitive failure still attempts every pre-registered member of a larger cache composite'
);
$cacheDeleteThrowAt = null;
\WPrism\CacheInvalidationTransaction::finish();
$check(
    $cacheEvents === array_merge($optionComposite, $optionComposite),
    'post-outcome finish retries the complete larger composite after a middle-position failure'
);

if ($failures) {
    \WPrism\CacheInvalidationTransaction::end();
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $failure) echo "  - $failure\n";
    exit(1);
}
\WPrism\CacheInvalidationTransaction::end();
echo "\nall UserMetaMaterializer product-path checks passed\n";

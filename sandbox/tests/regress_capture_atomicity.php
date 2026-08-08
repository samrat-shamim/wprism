<?php
/**
 * Offline regression for Capture's DB-side publication boundary.
 *
 * This is deliberately a small in-memory wpdb fixture rather than a source
 * grep. It invokes Capture::run_in_consistent_snapshot() itself, exercises
 * the real Db/Ledger/Deletion code, and verifies that both transaction-start
 * contention and an unsupported deletion have the required retry/rollback
 * semantics without a WordPress bootstrap or Docker.
 */
declare(strict_types=1);

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

/** Minimal wpdb surface used by the real Db/Ledger calls below. */
final class CaptureAtomicityFakeWpdb {
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $terms = 'wp_terms';
    public string $term_taxonomy = 'wp_term_taxonomy';
    public string $postmeta = 'wp_postmeta';
    public string $termmeta = 'wp_termmeta';
    public string $last_error = '';
    public int $insert_id = 0;

    /** @var array<string,array{uuid:string,entity_type:string,id_kind:string,local_id:int}> */
    public array $map = [];
    /** @var array<string,array{uuid:string,entity_type:string,content_hash:string}> */
    public array $state = [];
    /** @var array<string,string> */
    public array $kv = [];

    public int $starts = 0;
    public int $commits = 0;
    public int $rollbacks = 0;
    public int $ddlQueries = 0;
    public bool $failStartBeforeOpen = false;
    public bool $failStartAfterOpen = false;
    public ?string $commitCheckpointError = null;
    /** @var ?array{map:array,state:array,kv:array} */
    private ?array $transactionSnapshot = null;

    public function prepare(string $query, ...$args): string {
        foreach ($args as $arg) {
            $s = strpos($query, '%s');
            $d = strpos($query, '%d');
            if ($s === false && $d === false) {
                break;
            }
            if ($d === false || ($s !== false && $s < $d)) {
                $token = '%s';
                $position = $s;
                $replacement = "'" . str_replace("'", "''", (string) $arg) . "'";
            } else {
                $token = '%d';
                $position = $d;
                $replacement = (string) (int) $arg;
            }
            $query = substr_replace($query, $replacement, $position, strlen($token));
        }
        return $query;
    }

    public function get_charset_collate(): string {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function query(string $sql) {
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        $this->last_error = '';

        if (stripos($sql, 'START TRANSACTION') === 0) {
            $this->starts++;
            if ($this->failStartBeforeOpen) {
                $this->failStartBeforeOpen = false;
                $this->last_error = 'Deadlock found when trying to get lock';
                return false;
            }
            $this->transactionSnapshot = $this->snapshot();
            if ($this->failStartAfterOpen) {
                $this->failStartAfterOpen = false;
                $this->last_error = 'Deadlock found when trying to get lock';
            }
            return true;
        }
        if (strcasecmp($sql, 'COMMIT') === 0) {
            $this->commits++;
            $this->transactionSnapshot = null;
            if ($this->commitCheckpointError !== null) {
                $this->last_error = $this->commitCheckpointError;
                $this->commitCheckpointError = null;
            }
            return true;
        }
        if (strcasecmp($sql, 'ROLLBACK') === 0) {
            $this->rollbacks++;
            if ($this->transactionSnapshot !== null) {
                $this->restore($this->transactionSnapshot);
            }
            $this->transactionSnapshot = null;
            return true;
        }

        // MySQL/MariaDB implicitly commit an open transaction before DDL,
        // including CREATE TABLE IF NOT EXISTS. Model that real behavior so
        // this regression catches schema helpers accidentally called from
        // Capture's transactional candidate build.
        if (preg_match('/^(?:CREATE|ALTER) TABLE\b/i', $sql)) {
            $this->ddlQueries++;
            $this->transactionSnapshot = null;
            return 0;
        }

        if (preg_match(
            "/INSERT INTO wp_duo_map .* VALUES \\('([^']*)', '([^']*)', '([^']*)', ([0-9]+)\\)/i",
            $sql,
            $m
        )) {
            $key = $m[1] . '|' . $m[3];
            $this->map[$key] = [
                'uuid' => $m[1], 'entity_type' => $m[2], 'id_kind' => $m[3], 'local_id' => (int) $m[4],
            ];
            return 1;
        }
        if (preg_match(
            "/INSERT INTO wp_duo_state .* VALUES \\('([^']*)', '([^']*)', '([^']*)'\\)/i",
            $sql,
            $m
        )) {
            $this->state[$m[1]] = ['uuid' => $m[1], 'entity_type' => $m[2], 'content_hash' => $m[3]];
            return 1;
        }
        if (preg_match(
            "/INSERT INTO wp_duo_kv .* VALUES \\('([^']*)', '([^']*)'\\)/i",
            $sql,
            $m
        )) {
            $this->kv[$m[1]] = $m[2];
            return 1;
        }

        if (preg_match("/DELETE FROM wp_duo_map WHERE uuid = '([^']*)'/i", $sql, $m)) {
            foreach (array_keys($this->map) as $key) {
                if ($this->map[$key]['uuid'] === $m[1]) {
                    unset($this->map[$key]);
                }
            }
            return 1;
        }
        if (preg_match("/DELETE FROM wp_duo_state WHERE uuid = '([^']*)'/i", $sql, $m)) {
            unset($this->state[$m[1]]);
            return 1;
        }
        if (preg_match("/DELETE FROM wp_duo_kv WHERE k = '([^']*)'/i", $sql, $m)) {
            unset($this->kv[$m[1]]);
            return 1;
        }

        throw new RuntimeException("fixture does not understand SQL: $sql");
    }

    public function get_row(string $sql, $output = null): ?array {
        $this->last_error = '';
        if (preg_match(
            "/SELECT entity_type, local_id FROM wp_duo_map WHERE uuid = '([^']*)' AND id_kind = '([^']*)'/i",
            $sql,
            $m
        )) {
            $row = $this->map[$m[1] . '|' . $m[2]] ?? null;
            return $row === null ? null : ['entity_type' => $row['entity_type'], 'local_id' => $row['local_id']];
        }
        if (preg_match(
            "/SELECT uuid, entity_type FROM wp_duo_map WHERE id_kind = '([^']*)' AND local_id = ([0-9]+)/i",
            $sql,
            $m
        )) {
            foreach ($this->map as $row) {
                if ($row['id_kind'] === $m[1] && $row['local_id'] === (int) $m[2]) {
                    return ['uuid' => $row['uuid'], 'entity_type' => $row['entity_type']];
                }
            }
            return null;
        }
        throw new RuntimeException("fixture does not understand get_row SQL: $sql");
    }

    public function get_var(string $sql) {
        $this->last_error = '';
        if (stripos($sql, 'INFORMATION_SCHEMA.COLUMNS') !== false) {
            if (stripos($sql, "COLUMN_NAME = 'entity_type'") !== false) {
                return 64;
            }
            if (stripos($sql, "COLUMN_NAME = 'id_kind'") !== false) {
                return 32;
            }
        }
        throw new RuntimeException("fixture does not understand get_var SQL: $sql");
    }

    public function get_results(string $sql, $output = null): array {
        $this->last_error = '';
        throw new RuntimeException("fixture does not understand get_results SQL: $sql");
    }

    /** @return array{map:array,state:array,kv:array} */
    private function snapshot(): array {
        return ['map' => $this->map, 'state' => $this->state, 'kv' => $this->kv];
    }

    /** @param array{map:array,state:array,kv:array} $snapshot */
    private function restore(array $snapshot): void {
        $this->map = $snapshot['map'];
        $this->state = $snapshot['state'];
        $this->kv = $snapshot['kv'];
    }
}

$root = dirname(__DIR__, 2);
require_once "$root/agent/src/Canon.php";
require_once "$root/agent/src/OptionState.php";
require_once "$root/agent/src/PlainData.php";
require_once "$root/agent/src/Uuid.php";
require_once "$root/agent/src/TransientDbException.php";
require_once "$root/agent/src/Db.php";
require_once "$root/agent/src/Ledger.php";
require_once "$root/agent/src/Policy.php";
require_once "$root/agent/src/Snapshot.php";
require_once "$root/agent/src/SidebarState.php";
require_once "$root/agent/src/RepositoryCompiler.php";
require_once "$root/agent/src/Deletion.php";
require_once "$root/agent/src/Publish.php";
require_once "$root/agent/src/Capture.php";

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

use Duo\Capture;
use Duo\CompiledRepository;
use Duo\Db;
use Duo\Deletion;
use Duo\Ledger;
use Duo\Snapshot;

$wpdb = new CaptureAtomicityFakeWpdb();

function assert_capture_atomicity(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("FAIL: $message");
    }
    echo "ok: $message\n";
}

$consistentSnapshot = new ReflectionMethod(Capture::class, 'run_in_consistent_snapshot');
$consistentSnapshot->setAccessible(true);

// A START failure is retryable even though no transaction was opened by the
// driver. The callback must run exactly once, only after the fresh start.
$wpdb->failStartBeforeOpen = true;
$callbackRuns = 0;
$result = $consistentSnapshot->invoke(null, static function () use (&$callbackRuns): array {
    $callbackRuns++;
    return ['captured' => true];
});
assert_capture_atomicity($result === ['captured' => true], 'transaction-start contention retries and returns the candidate');
assert_capture_atomicity($wpdb->starts === 2, 'a transient START failure enters a bounded retry attempt');
assert_capture_atomicity($callbackRuns === 1, 'the capture callback is not run against the failed transaction start');
assert_capture_atomicity($wpdb->commits === 1 && $wpdb->rollbacks === 0, 'a pre-open START failure does not issue a spurious rollback');

// A driver can report a transient error at the START checkpoint after the
// transaction has actually opened. This second injection proves the wrapper
// rolls that snapshot back before retrying too.
$wpdb->starts = $wpdb->commits = $wpdb->rollbacks = 0;
$wpdb->ddlQueries = 0;
$wpdb->failStartAfterOpen = true;
$wpdb->map = ['stable|post' => [
    'uuid' => 'stable', 'entity_type' => 'post', 'id_kind' => 'post', 'local_id' => 7,
]];
$wpdb->state = ['stable' => ['uuid' => 'stable', 'entity_type' => 'post', 'content_hash' => 'base']];
$wpdb->kv = ['stable' => 'base'];
$beforeRetry = ['map' => $wpdb->map, 'state' => $wpdb->state, 'kv' => $wpdb->kv];
$result = $consistentSnapshot->invoke(null, static function () use (&$wpdb): array {
    $wpdb->kv['stable'] = 'candidate';
    return ['retried' => true];
});
assert_capture_atomicity($result === ['retried' => true], 'post-open START checkpoint contention retries successfully');
assert_capture_atomicity($wpdb->rollbacks === 1, 'post-open START checkpoint retry rolls back the opened snapshot');
assert_capture_atomicity($wpdb->commits === 1, 'the retry commits exactly one successful snapshot');
assert_capture_atomicity($wpdb->kv === ['stable' => 'candidate'], 'the successful retry owns the committed candidate mutation');
assert_capture_atomicity($beforeRetry['map'] === $wpdb->map && $beforeRetry['state'] === $wpdb->state, 'post-open START rollback restores untouched map/state before retry');

// A normal COMMIT is a terminal success: its callback is not rerun and no
// rollback is attempted after the driver has accepted COMMIT.
$wpdb->starts = $wpdb->commits = $wpdb->rollbacks = 0;
$callbackRuns = 0;
$result = $consistentSnapshot->invoke(null, static function () use (&$callbackRuns): array {
    $callbackRuns++;
    return ['committed' => true];
});
assert_capture_atomicity($result === ['committed' => true], 'a successful COMMIT returns the candidate');
assert_capture_atomicity($callbackRuns === 1 && $wpdb->starts === 1, 'a successful COMMIT runs the callback exactly once');
assert_capture_atomicity($wpdb->commits === 1 && $wpdb->rollbacks === 0, 'a successful COMMIT has no rollback');

// If COMMIT returns but the following checkpoint reports contention, the
// outcome is ambiguous: the server may already have committed the writes.
// The wrapper must fail closed, never retrying the callback or rolling back.
$wpdb->starts = $wpdb->commits = $wpdb->rollbacks = 0;
$wpdb->commitCheckpointError = 'Deadlock found when trying to get lock';
$callbackRuns = 0;
$ambiguousCommit = null;
try {
    $consistentSnapshot->invoke(null, static function () use (&$callbackRuns, &$wpdb): array {
        $callbackRuns++;
        $wpdb->kv['ambiguous_candidate'] = 'may-be-durable';
        return ['ambiguous' => true];
    });
} catch (ReflectionException $e) {
    throw $e;
} catch (Throwable $e) {
    $ambiguousCommit = $e;
}
assert_capture_atomicity($ambiguousCommit instanceof RuntimeException, 'an ambiguous COMMIT fails closed');
assert_capture_atomicity($ambiguousCommit !== null && str_contains($ambiguousCommit->getMessage(), 'commit outcome uncertain'), 'an ambiguous COMMIT names the uncertain outcome');
assert_capture_atomicity($callbackRuns === 1 && $wpdb->starts === 1, 'an ambiguous COMMIT never retries the callback');
assert_capture_atomicity($wpdb->commits === 1 && $wpdb->rollbacks === 0, 'an ambiguous COMMIT never rolls back after COMMIT returned');

// A real unsupported deletion must roll back the mutations that preceded the
// capability check: a dead-map prune, an identity mint, a state hash, and a
// kv marker. Deletion::capture_tombstones() is the actual refusal boundary,
// not a synthetic exception standing in for it.
$uuid = '12345678-1234-4123-8123-123456789012';
$wpdb->starts = $wpdb->commits = $wpdb->rollbacks = 0;
$wpdb->map = ['stale|post' => [
    'uuid' => 'stale', 'entity_type' => 'post', 'id_kind' => 'post', 'local_id' => 99,
]];
$wpdb->state = ['stale' => ['uuid' => 'stale', 'entity_type' => 'post', 'content_hash' => 'old']];
$wpdb->kv = ['capture_phase' => 'before'];
$beforeRefusal = ['map' => $wpdb->map, 'state' => $wpdb->state, 'kv' => $wpdb->kv];
$previous = CompiledRepository::create([
    'revision_hash' => str_repeat('a', 64),
    'tree' => [
        $uuid => [
            'uuid' => $uuid,
            'type' => 'post',
            'path' => 'posts/product/' . $uuid . '.md',
            'hash' => str_repeat('b', 64),
            'data' => ['type' => 'product'],
        ],
    ],
]);
$policy = Duo\Policy::load(null, ['core']);
$refused = null;
try {
    $consistentSnapshot->invoke(null, static function () use ($uuid, $previous, $policy): array {
        global $wpdb;
        Db::query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}duo_map WHERE uuid = %s",
                'stale'
            ),
            'ledger prune dead post identities'
        );
        // Snapshot::capture() invokes this repair before it captures declared
        // table rows. It must remain DML-only here: Ledger::ensure() would
        // issue DDL, implicitly commit the prune above, and make the refusal
        // below incapable of restoring the ledger.
        Snapshot::repair_truncated_entity_types($policy);
        Ledger::set($uuid, 'post', Ledger::KIND_POST, 42);
        Ledger::set_state_hash($uuid, 'post', str_repeat('c', 64));
        Ledger::kv_set('capture_phase', 'candidate');
        Deletion::capture_tombstones($previous, [], $policy);
        return [];
    });
} catch (ReflectionException $e) {
    throw $e;
} catch (Throwable $e) {
    $refused = $e;
}
assert_capture_atomicity($refused instanceof RuntimeException, 'unsupported deletion refuses through the real Deletion capability boundary');
assert_capture_atomicity($refused !== null && str_contains($refused->getMessage(), 'post:product'), 'unsupported deletion names the refused selector');
assert_capture_atomicity($wpdb->map === $beforeRefusal['map'], 'refusal rolls back duo_map pruning and identity minting');
assert_capture_atomicity($wpdb->state === $beforeRefusal['state'], 'refusal rolls back duo_state mutation');
assert_capture_atomicity($wpdb->kv === $beforeRefusal['kv'], 'refusal rolls back duo_kv mutation');
assert_capture_atomicity($wpdb->rollbacks === 1 && $wpdb->commits === 0, 'refusal rolls back once and never commits a candidate');
assert_capture_atomicity($wpdb->ddlQueries === 0, 'transactional capture performs no implicit-commit schema DDL');

echo "ALL PASSED\n";

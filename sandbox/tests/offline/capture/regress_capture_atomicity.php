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

require_once __DIR__ . '/../../lib/FakeWpdb.php';

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

/** Minimal wpdb surface used by the real Db/Ledger calls below. */
final class CaptureAtomicityFakeWpdb {
    public string $prefix = 'wp_';
    public string $dbname = 'wordpress';
    public string $posts = 'wp_posts';
    public string $terms = 'wp_terms';
    public string $term_taxonomy = 'wp_term_taxonomy';
    public string $term_relationships = 'wp_term_relationships';
    public string $postmeta = 'wp_postmeta';
    public string $termmeta = 'wp_termmeta';
    public string $options = 'wp_options';
    public string $users = 'wp_users';
    public string $usermeta = 'wp_usermeta';
    public string $last_error = '';
    public int $insert_id = 0;

    /** @var array<string,array{uuid:string,entity_type:string,id_kind:string,local_id:int}> */
    public array $map = [];
    /** @var array<string,array{uuid:string,entity_type:string,content_hash:string}> */
    public array $state = [];
    /** @var array<string,string> */
    public array $kv = [];

    public int $starts = 0;
    public int $isolationSets = 0;
    public int $commits = 0;
    public int $rollbacks = 0;
    public int $ddlQueries = 0;
    public int $connectionId = 41;
    public ?int $fenceHolder = null;
    public bool $fenceBusy = false;
    public int $fenceAcquires = 0;
    public int $fenceReleases = 0;
    public bool $kvTableExists = true;
    public bool $failStartBeforeOpen = false;
    public bool $failStartAfterOpen = false;
    public bool $failIsolation = false;
    public string $sessionIsolation = 'READ-COMMITTED';
    public ?string $nextIsolation = null;
    public ?string $activeIsolation = null;
    public ?string $engineAfterStartFailure = null;
    public ?string $commitCheckpointError = null;
    public bool $replaceConnectionOnCommit = false;
    private ?int $warningCode = null;
    /** @var array<string,string> table name -> storage engine */
    public array $tableEngines = [];
    /** @var list<string> logical wpdb-property.column locations hidden from schema inventory */
    public array $missingCoreColumns = [];
    /** @var ?array{map:array,state:array,kv:array} */
    private ?array $transactionSnapshot = null;

    public function prepare(string $query, ...$args): string {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
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

        if (strcasecmp($sql, 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === 0) {
            $this->isolationSets++;
            if ($this->failIsolation) {
                $this->failIsolation = false;
                $this->last_error = 'Deadlock found when trying to get lock';
                return false;
            }
            $this->nextIsolation = 'REPEATABLE-READ';
            return true;
        }

        if (stripos($sql, 'START TRANSACTION') === 0) {
            $this->starts++;
            if ($this->failStartBeforeOpen) {
                $this->failStartBeforeOpen = false;
                $this->last_error = 'Deadlock found when trying to get lock';
                if ($this->engineAfterStartFailure !== null) {
                    $this->tableEngines[$this->posts] = $this->engineAfterStartFailure;
                    $this->engineAfterStartFailure = null;
                }
                return false;
            }
            $this->activeIsolation = $this->nextIsolation ?? $this->sessionIsolation;
            $this->nextIsolation = null;
            $this->transactionSnapshot = $this->snapshot();
            if ($this->failStartAfterOpen) {
                $this->failStartAfterOpen = false;
                $this->last_error = 'Deadlock found when trying to get lock';
                return false;
            }
            return true;
        }
        if (strcasecmp($sql, 'COMMIT') === 0) {
            $this->commits++;
            $this->transactionSnapshot = null;
            $this->activeIsolation = null;
            if ($this->commitCheckpointError !== null) {
                $this->last_error = $this->commitCheckpointError;
                $this->commitCheckpointError = null;
            }
            if ($this->replaceConnectionOnCommit) {
                $this->connectionId++;
                $this->replaceConnectionOnCommit = false;
            }
            return true;
        }
        if (strcasecmp($sql, 'ROLLBACK') === 0) {
            $this->rollbacks++;
            if ($this->transactionSnapshot !== null) {
                $this->restore($this->transactionSnapshot);
            }
            $this->transactionSnapshot = null;
            $this->activeIsolation = null;
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
            "/INSERT INTO wp_wprism_map .* VALUES \\('([^']*)', '([^']*)', '([^']*)', ([0-9]+)\\)/i",
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
            "/INSERT INTO wp_wprism_state .* VALUES \\('([^']*)', '([^']*)', '([^']*)'\\)/i",
            $sql,
            $m
        )) {
            $this->state[$m[1]] = ['uuid' => $m[1], 'entity_type' => $m[2], 'content_hash' => $m[3]];
            return 1;
        }
        if (preg_match(
            "/INSERT INTO wp_wprism_kv .* VALUES \\('([^']*)', '([^']*)'\\)/i",
            $sql,
            $m
        )) {
            $this->kv[$m[1]] = $m[2];
            return 1;
        }

        if (preg_match("/DELETE FROM wp_wprism_map WHERE uuid = '([^']*)'/i", $sql, $m)) {
            foreach (array_keys($this->map) as $key) {
                if ($this->map[$key]['uuid'] === $m[1]) {
                    unset($this->map[$key]);
                }
            }
            return 1;
        }
        if (preg_match("/DELETE FROM wp_wprism_state WHERE uuid = '([^']*)'/i", $sql, $m)) {
            unset($this->state[$m[1]]);
            return 1;
        }
        if (preg_match("/DELETE FROM wp_wprism_kv WHERE k = '([^']*)'/i", $sql, $m)) {
            unset($this->kv[$m[1]]);
            return 1;
        }

        throw new RuntimeException("fixture does not understand SQL: $sql");
    }

    public function get_row(string $sql, $output = null): ?array {
        $this->last_error = '';
        if (preg_match('/^SHOW CREATE TABLE `([A-Za-z0-9_]+)`$/D', $sql, $match) === 1) {
            return $this->kvTableExists && $match[1] === 'wp_wprism_kv'
                ? ['wp_wprism_kv', 'CREATE TABLE `wp_wprism_kv` (`k` varchar(191)) ENGINE=InnoDB']
                : null;
        }
        if (preg_match("/SELECT k, v FROM wp_wprism_kv WHERE k = '([^']*)'/i", $sql, $m)) {
            return array_key_exists($m[1], $this->kv)
                ? ['k' => $m[1], 'v' => $this->kv[$m[1]]]
                : null;
        }
        if (preg_match(
            "/SELECT entity_type, local_id FROM wp_wprism_map WHERE uuid = '([^']*)' AND id_kind = '([^']*)'/i",
            $sql,
            $m
        )) {
            $row = $this->map[$m[1] . '|' . $m[2]] ?? null;
            return $row === null ? null : ['entity_type' => $row['entity_type'], 'local_id' => $row['local_id']];
        }
        if (preg_match(
            "/SELECT uuid, entity_type FROM wp_wprism_map WHERE id_kind = '([^']*)' AND local_id = ([0-9]+)/i",
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
        if (strcasecmp($sql, 'SELECT CONNECTION_ID()') === 0) {
            return (string) $this->connectionId;
        }
        if (strcasecmp($sql, 'SELECT @@in_transaction') === 0) {
            return $this->transactionSnapshot === null ? '0' : '1';
        }
        if (preg_match("/SELECT GET_LOCK\('([^']+)', 0\)/i", $sql)) {
            if ($this->fenceBusy) {
                return 0;
            }
            $this->fenceHolder = $this->connectionId;
            $this->fenceAcquires++;
            return 1;
        }
        if (preg_match("/SELECT IS_USED_LOCK\('([^']+)'\)/i", $sql)) {
            return $this->fenceHolder;
        }
        if (preg_match("/SELECT RELEASE_LOCK\('([^']+)'\)/i", $sql)) {
            $released = $this->fenceHolder === $this->connectionId;
            if ($released) {
                $this->fenceHolder = null;
                $this->fenceReleases++;
            }
            return $released ? 1 : 0;
        }
        if (stripos($sql, 'information_schema.TABLES') !== false
            && stripos($sql, "TABLE_NAME = 'wp_wprism_kv'") !== false) {
            return $this->kvTableExists ? 'wp_wprism_kv' : null;
        }
        if ($sql === 'SELECT 1 FROM `wp_wprism_kv` LIMIT 0') {
            if ($this->kvTableExists) {
                $this->warningCode = null;
                return null;
            }
            $this->last_error = "Table 'wordpress.wp_wprism_kv' doesn't exist";
            $this->warningCode = 1146;
            return null;
        }
        if (preg_match("/SELECT v FROM wp_wprism_kv WHERE k = '([^']*)'/i", $sql, $m)) {
            return $this->kv[$m[1]] ?? null;
        }
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
        if ($sql === 'SHOW WARNINGS') {
            return $this->warningCode === null ? [] : [[
                'Level' => 'Error',
                'Code' => $this->warningCode,
                'Message' => 'simulated table-presence diagnostic',
            ]];
        }
        if (stripos($sql, 'information_schema.COLUMNS') !== false
            && stripos($sql, 'TABLE_NAME, COLUMN_NAME') !== false) {
            $rows = [];
            foreach (WPrism\TableSchema::core_capture_required_columns() as $property => $columns) {
                $table = (string) $this->$property;
                if (!str_contains($sql, "'" . $table . "'")) {
                    continue;
                }
                foreach ($columns as $column) {
                    if (in_array("$property.$column", $this->missingCoreColumns, true)) {
                        continue;
                    }
                    $rows[] = ['TABLE_NAME' => $table, 'COLUMN_NAME' => $column];
                }
            }
            return $rows;
        }
        if (stripos($sql, 'information_schema.TABLES') !== false) {
            $rows = [];
            foreach ($this->tableEngines as $table => $engine) {
                if (str_contains($sql, "'" . $table . "'")) {
                    $rows[] = ['TABLE_NAME' => $table, 'ENGINE' => $engine];
                }
            }
            return $rows;
        }
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

$root = dirname(__DIR__, 4);
require_once "$root/agent/src/Kernel/Canon.php";
require_once "$root/agent/src/Kernel/OptionState.php";
require_once "$root/agent/src/Kernel/PlainData.php";
require_once "$root/agent/src/Kernel/Uuid.php";
require_once "$root/agent/src/Kernel/TransientDbException.php";
require_once "$root/agent/src/Kernel/Db.php";
require_once "$root/agent/src/Repository/Ledger.php";
require_once "$root/agent/src/Policy/Policy.php";
require_once "$root/agent/src/Repository/Snapshot.php";
require_once "$root/agent/src/Repository/SidebarState.php";
require_once "$root/agent/src/Repository/RepositoryCompiler.php";
require_once "$root/agent/src/Delete/Deletion.php";
require_once "$root/agent/src/Publication/Publish.php";
require_once "$root/agent/src/Capture/Capture.php";
require_once "$root/sandbox/tests/lib/wp_stubs.php";
require_once "$root/sandbox/tests/lib/FakeWpdb.php";

if (!defined('WPRISM_SPEC_VERSION')) {
    define('WPRISM_SPEC_VERSION', 3);
}

use WPrism\Capture;
use WPrism\CapturePublicationWorkflow;
use WPrism\CaptureTransaction;
use WPrism\CommandRefusalException;
use WPrism\CompiledRepository;
use WPrism\Db;
use WPrism\Deletion;
use WPrism\Ledger;
use WPrism\Policy;
use WPrism\ProcessFence;
use WPrism\Snapshot;
use WPrismTest\FakeWpdb;

$wpdb = new CaptureAtomicityFakeWpdb();

function assert_capture_atomicity(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("FAIL: $message");
    }
    echo "ok: $message\n";
}

/** Install the complete InnoDB read set CaptureTransaction validates. */
function capture_atomicity_database(array $missingCoreColumns = []): FakeWpdb {
    Db::forget_transaction_tracking();
    $db = FakeWpdb::install()->enableInformationSchema();
    foreach (WPrism\TableSchema::core_capture_required_columns() as $property => $columns) {
        $columns = array_values(array_diff($columns, $missingCoreColumns[$property] ?? []));
        $db
            ->setColumns($db->$property, array_fill_keys($columns, 'longtext'))
            ->setTableEngine($db->$property, 'InnoDB');
    }
    return $db
        ->setColumns('wp_wprism_map', [
            'uuid' => 'char(36)',
            'entity_type' => 'varchar(64)',
            'id_kind' => 'varchar(32)',
            'local_id' => 'bigint unsigned',
        ])
        ->setUniqueKey('wp_wprism_map', ['uuid', 'id_kind'])
        ->setUniqueKey('wp_wprism_map', ['id_kind', 'local_id'])
        ->setTableEngine('wp_wprism_map', 'InnoDB')
        ->setColumns('wp_wprism_state', [
            'uuid' => 'char(36)',
            'entity_type' => 'varchar(64)',
            'content_hash' => 'char(64)',
        ])
        ->setUniqueKey('wp_wprism_state', ['uuid'])
        ->setTableEngine('wp_wprism_state', 'InnoDB')
        ->setColumns('wp_wprism_kv', ['k' => 'varchar(191)', 'v' => 'longtext'])
        ->setUniqueKey('wp_wprism_kv', ['k'])
        ->setTableEngine('wp_wprism_kv', 'InnoDB');
}

/** @return array{starts:int,commits:int,rollbacks:int} */
function capture_atomicity_transaction_counts(FakeWpdb $db): array {
    $counts = ['starts' => 0, 'commits' => 0, 'rollbacks' => 0];
    foreach ($db->queries() as $sql) {
        if (preg_match('/^START TRANSACTION\b/i', $sql) === 1) {
            $counts['starts']++;
        } elseif (preg_match('/^COMMIT\b/i', $sql) === 1) {
            $counts['commits']++;
        } elseif (preg_match('/^ROLLBACK(?:\s+AND|\s*$)/i', $sql) === 1) {
            // ROLLBACK TO SAVEPOINT is an observation, not a terminal control.
            $counts['rollbacks']++;
        }
    }
    return $counts;
}

/** @return array<string,string> */
function capture_atomicity_kv(FakeWpdb $db): array {
    $kv = [];
    foreach ($db->rows('wp_wprism_kv') as $row) {
        $kv[(string) ($row['k'] ?? '')] = (string) ($row['v'] ?? '');
    }
    ksort($kv, SORT_STRING);
    return $kv;
}

$captureSource = (string) file_get_contents("$root/agent/src/Capture/Capture.php");
$transactionSource = (string) file_get_contents("$root/agent/src/Capture/CaptureTransaction.php");
assert_capture_atomicity(
    str_contains($captureSource, "require_once __DIR__ . '/CaptureTransaction.php';"),
    'Capture directly loads its transaction collaborator without bootstrap-order coupling'
);
assert_capture_atomicity(
    str_contains($transactionSource, "Db::start_consistent_snapshot('capture transaction start', \$profile)")
        && str_contains($transactionSource, 'Db::start_read_only_consistent_snapshot(')
        && str_contains($transactionSource, '$profile = self::database_profile(')
        && !str_contains($captureSource, "Db::query('START TRANSACTION WITH CONSISTENT SNAPSHOT'"),
    'capture delegates one-shot isolation, exact START outcome, and its complete database profile to Db'
);
$transactionRun = new ReflectionMethod(CaptureTransaction::class, 'run');
assert_capture_atomicity(
    $transactionRun->isPublic() && $transactionRun->isStatic(),
    'CaptureTransaction exposes one focused static transaction entry point'
);

// A destination lock protects one filesystem tree; the advisory fence must
// independently serialize every capture against all other target writers.
// Exercise the real private boundary so failure typing, durable-row refusal,
// and exact ownership cleanup cannot drift apart from the workflow.
$captureFence = new ReflectionMethod(CapturePublicationWorkflow::class, 'acquireTargetWriterFence');
$ownsCaptureFence = $captureFence->invoke(null);
assert_capture_atomicity($ownsCaptureFence === true, 'capture acquires the target-wide process fence when it owns no prior fence');
assert_capture_atomicity($wpdb->fenceHolder === $wpdb->connectionId, 'capture process fence is held by the current database connection');
$nestedCaptureFence = $captureFence->invoke(null);
assert_capture_atomicity($nestedCaptureFence === false, 'same-process nested acquisition does not claim ownership of an existing continuous fence');
ProcessFence::release();
assert_capture_atomicity($wpdb->fenceHolder === null && $wpdb->fenceReleases === 1, 'the owned capture process fence releases exactly once');

$wpdb->fenceBusy = true;
$busyCapture = null;
try {
    $captureFence->invoke(null);
} catch (ReflectionException $e) {
    throw $e;
} catch (Throwable $e) {
    $busyCapture = $e;
}
$wpdb->fenceBusy = false;
assert_capture_atomicity($busyCapture instanceof CommandRefusalException, 'a competing live target writer produces a reviewed capture refusal');
assert_capture_atomicity(
    $busyCapture instanceof CommandRefusalException
        && ($busyCapture->payload()['error'] ?? null) === 'capture_target_writer_active',
    'live target-writer contention has the stable capture_target_writer_active reason code'
);
assert_capture_atomicity($wpdb->fenceHolder === null, 'a refused busy-fence acquisition leaves no process-fence residue');

// Capture must not overwrite the engine's transport verdict with a claim
// about another writer. Use the shared wpdb failure seam rather than adding
// yet another meaning for failure to this suite's older transaction fixture.
$captureDatabase = $wpdb;
foreach (['private database transport error', ''] as $driverError) {
    $wpdb = (new \WPrismTest\FakeWpdb())->failNextQuery($driverError, 'SELECT GET_LOCK(');
    $unavailableCapture = null;
    try {
        $captureFence->invoke(null);
    } catch (Throwable $failure) {
        $unavailableCapture = $failure;
    }
    assert_capture_atomicity(
        $unavailableCapture instanceof CommandRefusalException
            && ($unavailableCapture->payload()['error'] ?? null) === 'process_fence_unavailable'
            && !str_contains($unavailableCapture->getMessage(), 'another live target process'),
        'capture preserves the database-unavailable refusal for failed or NULL advisory-lock transport'
    );
    assert_capture_atomicity(
        $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)', ProcessFence::name())) === null,
        'failed capture fence transport neither claims nor retains a target lock'
    );
    ProcessFence::release();
}
$wpdb = $captureDatabase;

$wpdb->kv['promotion_lock'] = '{"retained":"opaque"}';
$ownsCaptureFence = $captureFence->invoke(null);
$assertNoPromotion = new ReflectionMethod(CapturePublicationWorkflow::class, 'assertNoPromotionSession');
$assertNoPromotionIfLedger = new ReflectionMethod(
    CapturePublicationWorkflow::class,
    'assertNoPromotionSessionIfLedgerExists'
);
$durablePromotion = null;
try {
    $assertNoPromotionIfLedger->invoke(null);
} catch (ReflectionException $e) {
    throw $e;
} catch (Throwable $e) {
    $durablePromotion = $e;
}
assert_capture_atomicity($durablePromotion instanceof CommandRefusalException, 'a retained between-process promotion lease blocks capture');
assert_capture_atomicity(
    $durablePromotion instanceof CommandRefusalException
        && ($durablePromotion->payload()['error'] ?? null) === 'capture_target_writer_active',
    'durable promotion contention shares the finite target-writer reason code'
);
assert_capture_atomicity($ownsCaptureFence === true && $wpdb->fenceHolder === $wpdb->connectionId, 'durable promotion check remains inside the capture-owned process fence');
assert_capture_atomicity(isset($wpdb->kv['promotion_lock']), 'capture never consumes retained promotion recovery authority');
ProcessFence::release();
assert_capture_atomicity($wpdb->fenceHolder === null, 'outer capture cleanup can release the fence after durable promotion refusal');
$wpdb->kvTableExists = false;
$assertNoPromotionIfLedger->invoke(null);
assert_capture_atomicity(isset($wpdb->kv['promotion_lock']), 'a first-capture target with no ledger table skips only the impossible pre-schema row read');
$wpdb->kvTableExists = true;
$durableAfterEnsure = null;
try {
    $assertNoPromotion->invoke(null);
} catch (ReflectionException $e) {
    throw $e;
} catch (Throwable $e) {
    $durableAfterEnsure = $e;
}
assert_capture_atomicity($durableAfterEnsure instanceof CommandRefusalException, 'the unconditional post-schema check still refuses a promotion row');
unset($wpdb->kv['promotion_lock']);

$workflowSource = (string) file_get_contents("$root/agent/src/Capture/CapturePublicationWorkflow.php");
assert_capture_atomicity(
    strpos($workflowSource, '$ownsTargetFence = self::acquireTargetWriterFence();')
        < strpos($workflowSource, 'self::assertNoPromotionSessionIfLedgerExists();')
        && strpos($workflowSource, 'self::assertNoPromotionSessionIfLedgerExists();')
            < strpos($workflowSource, 'Ledger::ensure();')
        && strpos($workflowSource, 'Ledger::ensure();')
            < strpos($workflowSource, 'self::assertNoPromotionSession();'),
    'capture fences and checks established promotion authority before ledger repair, then checks again after first-capture schema creation'
);
assert_capture_atomicity(
    str_contains($workflowSource, 'Publish::unlock($lock);')
        && str_contains($workflowSource, 'if ($ownsTargetFence) {')
        && str_contains($workflowSource, 'ProcessFence::release();'),
    'capture releases its target process fence on the outer publication cleanup path'
);
$transactionRunParameters = $transactionRun->getParameters();
assert_capture_atomicity(
    count($transactionRunParameters) === 5
        && ($transactionRunParameters[0]->getType()?->getName() ?? null) === Policy::class
        && $transactionRunParameters[2]->isPassedByReference()
        && $transactionRunParameters[2]->isDefaultValueAvailable()
        && $transactionRunParameters[2]->getDefaultValue() === null
        && $transactionRunParameters[3]->getDefaultValue() === false
        && $transactionRunParameters[4]->getDefaultValue() === false,
    'CaptureTransaction requires a path policy and preserves its phase, options-only, and read-only contracts'
);

$consistentSnapshot = new ReflectionMethod(Capture::class, 'run_in_consistent_snapshot');
$captureLines = file("$root/agent/src/Capture/Capture.php");
$facadeSource = implode('', array_slice(
    $captureLines === false ? [] : $captureLines,
    $consistentSnapshot->getStartLine() - 1,
    $consistentSnapshot->getEndLine() - $consistentSnapshot->getStartLine() + 1
));
assert_capture_atomicity(
    str_contains($facadeSource, 'CaptureTransaction::run($policy, $fn, $phase, $optionsOnly)')
        && !str_contains($facadeSource, 'while (true)'),
    'Capture retains a thin historical facade without duplicating transaction policy'
);

$transactionPolicy = Policy::load(null, ['core']);

// A declared plugin table may be absent before its host-checkpointed schema
// settlement. Capture still probes that exact physical name inside the bound
// snapshot so the planner can return its established absent-table refusal;
// presence authority must not depend on the pre-START result being positive.
$rankMathPolicy = Policy::load(
    null,
    ['rank-math'],
    adapterLibrary: \WPrism\AdapterLibrary::fromSourcePackage($root, 'rank-math')
);
$wpdb = capture_atomicity_database();
foreach ([
    'ordinary plan' => CaptureTransaction::database_profile($rankMathPolicy),
    'strict observation' => CaptureTransaction::database_profile($rankMathPolicy, readOnly: true),
] as $profileLabel => $virginTableProfile) {
    assert_capture_atomicity(
        !in_array('wp_rank_math_redirections', $virginTableProfile->readable_tables(), true)
            && in_array('wp_rank_math_redirections', $virginTableProfile->table_presence_reads(), true),
        "$profileLabel gives an absent declared table exact presence authority without row-read authority"
    );
}
$virginTableFailure = null;
try {
    CaptureTransaction::run(
        $rankMathPolicy,
        static function () use ($rankMathPolicy): array {
            Snapshot::assert_row_schema(
                'rank_math_redirections',
                $rankMathPolicy->declared_tables()['rank_math_redirections']
            );
            return [];
        }
    );
} catch (Throwable $failure) {
    $virginTableFailure = $failure;
}
$virginTableCounts = capture_atomicity_transaction_counts($wpdb);
assert_capture_atomicity(
    $virginTableFailure instanceof RuntimeException
        && !$virginTableFailure instanceof \WPrism\DatabaseQueryIsolationViolationException
        && $virginTableFailure->getMessage()
            === "wprism: declared table 'rank_math_redirections' does not exist on this environment "
                . '(plugin inactive, or manifest stale?)',
    'a virgin target reaches the established absent declared-table refusal through its profiled presence probe'
);
assert_capture_atomicity(
    $virginTableCounts === ['starts' => 1, 'commits' => 0, 'rollbacks' => 1],
    'the absent-table plan observation closes its read snapshot without mutation'
);

// MySQL 1213 rolls back the complete server transaction before wpdb reports
// the error. Exercise the product retry seam with the shared row-backed fake:
// the first attempt's successful DML must disappear, no second ROLLBACK may be
// issued against the already-ended transaction, and only a fresh snapshot may
// publish the rebuilt candidate.
$deadlockDb = capture_atomicity_database();
$deadlockAttempts = 0;
$deadlockResult = CaptureTransaction::run(
    $transactionPolicy,
    static function () use (&$deadlockAttempts, $deadlockDb): array {
        $deadlockAttempts++;
        if ($deadlockAttempts === 1) {
            Ledger::kv_set('deadlock_prior', 'must-roll-back');
            $deadlockDb->simulateDeadlock('deadlock_trigger');
            Ledger::kv_set('deadlock_trigger', 'must-not-persist');
        }
        Ledger::kv_set('deadlock_retry', 'committed');
        return ['attempt' => $deadlockAttempts];
    }
);
$deadlockQueries = $deadlockDb->queries();
assert_capture_atomicity(
    $deadlockResult === ['attempt' => 2] && $deadlockAttempts === 2,
    'a product-path 1213 rebuilds the candidate exactly once from a fresh snapshot'
);
assert_capture_atomicity(
    $deadlockDb->rows('wp_wprism_kv') === [['k' => 'deadlock_retry', 'v' => 'committed']],
    'the server-side 1213 rollback removes all first-attempt DML before the retry commits'
);
assert_capture_atomicity(
    count(array_filter(
        $deadlockQueries,
        static fn(string $sql): bool => $sql === 'START TRANSACTION WITH CONSISTENT SNAPSHOT'
    )) === 2
        && count(array_filter(
            $deadlockQueries,
            static fn(string $sql): bool => $sql === 'COMMIT AND NO CHAIN NO RELEASE'
        )) === 1
        && !in_array('ROLLBACK AND NO CHAIN NO RELEASE', $deadlockQueries, true),
    '1213 cleanup proves the old witness absent without a second terminal control'
);

$invokeConsistentSnapshot = static function (
    callable $fn,
    ?array &$phase = null,
    bool $optionsOnly = false
) use ($consistentSnapshot, $transactionPolicy) {
    return $consistentSnapshot->invokeArgs(null, [
        $fn,
        &$phase,
        $transactionPolicy,
        $optionsOnly,
    ]);
};

// Keep the historical private facade callable with only its original callback
// argument. Production paths pass their exact policy; reflection probes fall
// back to core and still receive engine validation before START.
$wpdb = capture_atomicity_database()->setTransactionIsolation('READ-COMMITTED');
$legacyCallbackRuns = 0;
$observedCaptureIsolation = null;
$legacyResult = $consistentSnapshot->invoke(null, static function () use (
    &$legacyCallbackRuns,
    &$observedCaptureIsolation,
    $wpdb
): array {
    $legacyCallbackRuns++;
    $observedCaptureIsolation = $wpdb->activeTransactionIsolation();
    return ['legacy' => true];
});
$legacyCounts = capture_atomicity_transaction_counts($wpdb);
assert_capture_atomicity(
    $legacyResult === ['legacy' => true] && $legacyCallbackRuns === 1,
    'the callback-only historical reflection facade remains invocation-compatible'
);
assert_capture_atomicity(
    $legacyCounts === ['starts' => 1, 'commits' => 1, 'rollbacks' => 0],
    'the callback-only facade still runs one validated transaction'
);
assert_capture_atomicity(
    $observedCaptureIsolation === 'REPEATABLE-READ'
        && in_array('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', $wpdb->queries(), true),
    'a READ COMMITTED session is overridden by the one-shot repeatable-read capture boundary'
);

// The extracted public seam must enforce the same storage-engine precondition
// as Capture's higher-level entry points rather than trusting callers to have
// performed an earlier private preflight.
$wpdb = capture_atomicity_database()->setTableEngine('wp_posts', 'MyISAM');
$callbackRuns = 0;
$unsupportedEngine = null;
try {
    CaptureTransaction::run($transactionPolicy, static function () use (&$callbackRuns): array {
        $callbackRuns++;
        return [];
    });
} catch (Throwable $failure) {
    $unsupportedEngine = $failure;
}
$unsupportedEngineCounts = capture_atomicity_transaction_counts($wpdb);
assert_capture_atomicity(
    $unsupportedEngine instanceof CommandRefusalException
        && $unsupportedEngine->reasonCode === 'capture_snapshot_unsupported',
    'the direct transaction seam refuses a non-InnoDB read set with the existing reason code'
);
assert_capture_atomicity(
    $unsupportedEngineCounts['starts'] === 0 && $callbackRuns === 0,
    'storage-engine refusal happens before START and before candidate execution'
);

$wpdb = capture_atomicity_database(['posts' => ['post_excerpt']]);
$callbackRuns = 0;
$schemaFailure = null;
try {
    CaptureTransaction::run($transactionPolicy, static function () use (&$callbackRuns): array {
        $callbackRuns++;
        return [];
    });
} catch (Throwable $failure) {
    $schemaFailure = $failure;
}
$schemaFailureCounts = capture_atomicity_transaction_counts($wpdb);
$schemaPayload = $schemaFailure instanceof CommandRefusalException ? $schemaFailure->payload() : [];
assert_capture_atomicity(
    ($schemaPayload['error'] ?? null) === 'capture_schema_unsupported'
        && ($schemaPayload['diagnostics'][0]['missing'] ?? null) === [[
            'table' => 'posts',
            'column' => 'post_excerpt',
        ]],
    'schema refusal publishes the fixed logical table/column location while retaining the typed reason code'
);
assert_capture_atomicity(
    $schemaFailureCounts['starts'] === 0 && $callbackRuns === 0,
    'schema refusal happens before START and before candidate execution'
);

// A retry opens a new snapshot against database state that may have changed
// since the first attempt. Revalidate engines on every attempt rather than
// allowing the first check to authorize all later STARTs.
$wpdb = capture_atomicity_database();
$wpdb->simulateDeadlock('START TRANSACTION WITH CONSISTENT SNAPSHOT');
$engineChanged = false;
$wpdb->onQuery(static function (string $sql) use ($wpdb, &$engineChanged): null {
    if (!$engineChanged && $sql === 'START TRANSACTION WITH CONSISTENT SNAPSHOT') {
        $wpdb->setTableEngine('wp_posts', 'MyISAM');
        $engineChanged = true;
    }
    return null;
});
$callbackRuns = 0;
$retryEngineFailure = null;
try {
    $invokeConsistentSnapshot(static function () use (&$callbackRuns): array {
        $callbackRuns++;
        return [];
    });
} catch (Throwable $failure) {
    $retryEngineFailure = $failure;
}
$retryEngineCounts = capture_atomicity_transaction_counts($wpdb);
assert_capture_atomicity(
    $retryEngineFailure instanceof CommandRefusalException
        && $retryEngineFailure->reasonCode === 'capture_snapshot_unsupported',
    'a retry refuses when its fresh read set no longer uses InnoDB'
);
assert_capture_atomicity(
    $retryEngineCounts === ['starts' => 2, 'commits' => 0, 'rollbacks' => 1]
        && $callbackRuns === 0,
    'retry engine validation runs after bounded isolation cleanup but before a second capture START and callback'
);

// A START failure is retryable even though no transaction was opened by the
// driver. The callback must run exactly once, only after the fresh start.
$wpdb = capture_atomicity_database();
$wpdb->simulateDeadlock('START TRANSACTION WITH CONSISTENT SNAPSHOT');
$callbackRuns = 0;
$result = $invokeConsistentSnapshot(static function () use (&$callbackRuns): array {
    $callbackRuns++;
    return ['captured' => true];
});
$startRetryCounts = capture_atomicity_transaction_counts($wpdb);
assert_capture_atomicity($result === ['captured' => true], 'transaction-start contention retries and returns the candidate');
assert_capture_atomicity(
    $startRetryCounts['starts'] === 3,
    'a transient START failure consumes its one-shot isolation before the bounded retry attempt'
);
assert_capture_atomicity($callbackRuns === 1, 'the capture callback is not run against the failed transaction start');
assert_capture_atomicity(
    $startRetryCounts['commits'] === 1 && $startRetryCounts['rollbacks'] === 1,
    'a pre-open START failure rolls back only its isolation-cleanup transaction'
);

// A driver can return false after the server has actually opened START. The
// same-connection active-state proof is authoritative: replaying or rolling
// back this healthy snapshot merely because the client result was false would
// mix two different database observations.
$wpdb = capture_atomicity_database();
$wpdb->seedTable('wp_wprism_map', [[
    'uuid' => 'stable', 'entity_type' => 'post', 'id_kind' => 'post', 'local_id' => 7,
]])
    ->seedTable('wp_wprism_state', [[
        'uuid' => 'stable', 'entity_type' => 'post', 'content_hash' => 'base',
    ]])
    ->seedTable('wp_wprism_kv', [['k' => 'stable', 'v' => 'base']])
    ->injectTransactionOutcome('START', 'after_false');
$beforeRetry = [
    'map' => $wpdb->rows('wp_wprism_map'),
    'state' => $wpdb->rows('wp_wprism_state'),
];
$result = $invokeConsistentSnapshot(static function (): array {
    Ledger::kv_set('stable', 'candidate');
    return ['retried' => true];
});
$appliedStartCounts = capture_atomicity_transaction_counts($wpdb);
assert_capture_atomicity($result === ['retried' => true], 'an applied-but-false START proceeds on its proven exact snapshot');
assert_capture_atomicity($appliedStartCounts['starts'] === 1 && $appliedStartCounts['rollbacks'] === 0,
    'an applied-but-false START is neither replayed nor spuriously rolled back');
assert_capture_atomicity($appliedStartCounts['commits'] === 1, 'the proven started snapshot commits exactly once');
assert_capture_atomicity(capture_atomicity_kv($wpdb) === ['stable' => 'candidate'], 'the proven snapshot owns the committed candidate mutation');
assert_capture_atomicity(
    $beforeRetry['map'] === $wpdb->rows('wp_wprism_map')
        && $beforeRetry['state'] === $wpdb->rows('wp_wprism_state'),
    'the applied-but-false START preserves untouched map/state in the same snapshot');

// A normal COMMIT is a terminal success: its callback is not rerun and no
// rollback is attempted after the driver has accepted COMMIT.
$wpdb = capture_atomicity_database();
$callbackRuns = 0;
$result = $invokeConsistentSnapshot(static function () use (&$callbackRuns): array {
    $callbackRuns++;
    return ['committed' => true];
});
$successCounts = capture_atomicity_transaction_counts($wpdb);
assert_capture_atomicity($result === ['committed' => true], 'a successful COMMIT returns the candidate');
assert_capture_atomicity($callbackRuns === 1 && $successCounts['starts'] === 1, 'a successful COMMIT runs the callback exactly once');
assert_capture_atomicity($successCounts['commits'] === 1 && $successCounts['rollbacks'] === 0, 'a successful COMMIT has no rollback');

// Active->inactive does not distinguish an accepted COMMIT from a server-side
// rollback/rejection when the client also reports an error. Capture must keep
// the physical candidate for recovery inspection without replay or rollback.
$wpdb = capture_atomicity_database();
$commitErrorInjected = false;
$wpdb->onQuery(static function (string $sql) use ($wpdb, &$commitErrorInjected): null {
    if (!$commitErrorInjected && $sql === 'COMMIT AND NO CHAIN NO RELEASE') {
        $wpdb->last_error = FakeWpdb::DEADLOCK_ERROR;
        $commitErrorInjected = true;
    }
    return null;
});
$callbackRuns = 0;
$uncertainClientErrorCommit = null;
try {
    $invokeConsistentSnapshot(static function () use (&$callbackRuns): array {
        $callbackRuns++;
        Ledger::kv_set('client_error_candidate', 'physically-ambiguous');
        return ['must-not-return' => true];
    });
} catch (Throwable $failure) {
    $uncertainClientErrorCommit = $failure;
}
$uncertainClientErrorCounts = capture_atomicity_transaction_counts($wpdb);
assert_capture_atomicity(
    $uncertainClientErrorCommit instanceof CommandRefusalException
        && $uncertainClientErrorCommit->reasonCode === 'capture_commit_uncertain',
    'truthy COMMIT plus a driver error becomes the typed capture recovery refusal'
);
assert_capture_atomicity($callbackRuns === 1 && $uncertainClientErrorCounts['starts'] === 1,
    'an ambiguous inactive COMMIT never replays the callback');
assert_capture_atomicity(
    $uncertainClientErrorCounts['commits'] === 1 && $uncertainClientErrorCounts['rollbacks'] === 0,
    'an ambiguous inactive COMMIT never runs a compensating rollback');
assert_capture_atomicity(
    (capture_atomicity_kv($wpdb)['client_error_candidate'] ?? null) === 'physically-ambiguous',
    'capture preserves the physical postimage for exact recovery classification'
);
Db::forget_transaction_tracking();

// A reconnect during COMMIT destroys that proof. Capture must translate the
// exact Db outcome exception into its durable recovery refusal and never run
// rollback or replay on the replacement connection.
$wpdb = capture_atomicity_database()->injectTransactionOutcome('COMMIT', 'after_reconnect');
$callbackRuns = 0;
$uncertainCommit = null;
try {
    $invokeConsistentSnapshot(static function () use (&$callbackRuns): array {
        $callbackRuns++;
        return ['must-not-return' => true];
    });
} catch (Throwable $failure) {
    $uncertainCommit = $failure;
}
$uncertainCommitCounts = capture_atomicity_transaction_counts($wpdb);
assert_capture_atomicity(
    $uncertainCommit instanceof CommandRefusalException
        && $uncertainCommit->reasonCode === 'capture_commit_uncertain',
    'a connection replacement during COMMIT becomes the typed capture recovery refusal'
);
assert_capture_atomicity($callbackRuns === 1 && $uncertainCommitCounts['starts'] === 1,
    'an uncertain reconnected COMMIT never replays the candidate');
assert_capture_atomicity($uncertainCommitCounts['commits'] === 1 && $uncertainCommitCounts['rollbacks'] === 0,
    'an uncertain reconnected COMMIT never rolls back on the replacement connection');
Db::connection_transaction_active('capture uncertain reconnect idle settlement');
Db::forget_transaction_tracking();

// The production publication sequence keeps the database transaction open
// across the retained-backup swap, writes the intent-bound commit marker as
// its final DML, then advances the on-disk intent immediately before COMMIT.
// Exercise that exact ordering with the real private wrapper + real Publish
// implementation instead of source-text assertions.
$publicationKey = new ReflectionMethod(Capture::class, 'publication_marker_key');
$publicationMarker = new ReflectionMethod(Capture::class, 'publication_marker');
$publicationStatus = new ReflectionMethod(Capture::class, 'publication_commit_status');
$protocolRoot = sys_get_temp_dir() . '/wprism_capture_atomicity_protocol_' . bin2hex(random_bytes(4));
$protocolState = $protocolRoot . '/state';
register_shutdown_function(static function () use ($protocolRoot): void {
    if (is_dir($protocolRoot)) {
        WPrism\Publish::rrmdir($protocolRoot);
    }
});
WPrism\Canon::write_file($protocolState . '/revision.txt', "old\n");
WPrism\Canon::write_file(WPrism\Publish::stage_dir($protocolState) . '/revision.txt', "candidate\n");
$wpdb = capture_atomicity_database()->seedTable(
    'wp_wprism_kv',
    [['k' => 'prior', 'v' => 'keep']]
);
$phase = [];
$result = $invokeConsistentSnapshot(
    static function () use (
        $protocolState, $publicationKey, $publicationMarker, &$phase
    ): array {
        $intent = WPrism\Publish::begin_intent($protocolState, WPrism\Publish::stage_dir($protocolState));
        $phase['filesystem_swapped'] = true;
        WPrism\Publish::swap($protocolState, true);
        $intent = WPrism\Publish::mark_swapped($protocolState, $intent);
        $intent = WPrism\Publish::mark_commit_ready($protocolState, $intent);
        $key = $publicationKey->invoke(null, $protocolState);
        $marker = $publicationMarker->invoke(null, $protocolState, $intent);
        Ledger::kv_set($key, $marker);
        $phase['state_dir'] = $protocolState;
        $phase['intent'] = $intent;
        return $intent;
    },
    $phase
);
$publicationCounts = capture_atomicity_transaction_counts($wpdb);
$diskIntent = WPrism\Canon::decode((string) file_get_contents(WPrism\Publish::intent_path($protocolState)));
assert_capture_atomicity(
    $publicationCounts === ['starts' => 1, 'commits' => 1, 'rollbacks' => 0],
    'filesystem publication and commit marker share one committed snapshot'
);
assert_capture_atomicity(($diskIntent['phase'] ?? null) === 'committing', 'durable intent advances immediately before database COMMIT');
assert_capture_atomicity(is_dir(WPrism\Publish::backup_dir($protocolState)), 'previous tree remains retained after COMMIT until receipt cleanup');
assert_capture_atomicity($publicationStatus->invoke(null, $protocolState, $diskIntent) === true, 'transaction-bound database marker proves the exact on-disk intent committed');
$markerKey = $publicationKey->invoke(null, $protocolState);
assert_capture_atomicity(
    $markerKey === $publicationKey->invoke(null, $protocolRoot . '/./state'),
    'destination marker identity is stable across equivalent lexical paths'
);
$validMarker = Ledger::kv_get($markerKey);
assert_capture_atomicity(is_string($validMarker), 'the committed publication marker is durably readable');
$malformedMarker = WPrism\Canon::decode($validMarker);
$malformedMarker['intent_id'] = 'not-an-intent-id';
unset($malformedMarker['record_sha256']);
$malformedMarker['record_sha256'] = hash('sha256', WPrism\Canon::encode($malformedMarker));
Ledger::kv_set($markerKey, WPrism\Canon::encode($malformedMarker));
$malformedMarkerFailure = null;
try {
    $publicationStatus->invoke(null, $protocolState, $diskIntent);
} catch (Throwable $e) {
    $malformedMarkerFailure = $e;
}
assert_capture_atomicity(
    $malformedMarkerFailure instanceof RuntimeException
        && str_contains($malformedMarkerFailure->getMessage(), 'malformed database commit marker fields'),
    'self-hashed but malformed database commit marker fails closed'
);
Ledger::kv_set($markerKey, $validMarker);
WPrism\Publish::recover(
    $protocolState,
    static fn(array $intent): bool => $publicationStatus->invoke(null, $protocolState, $intent)
);
assert_capture_atomicity(file_get_contents($protocolState . '/revision.txt') === "candidate\n", 'marker-backed recovery keeps the committed candidate');
assert_capture_atomicity(!is_dir(WPrism\Publish::backup_dir($protocolState)) && !is_file(WPrism\Publish::intent_path($protocolState)), 'marker-backed recovery finalizes backup and intent artifacts');

// A deterministic failure after swap but before COMMIT must roll back the DB
// marker and leave a hash-bound filesystem backup for the next lock holder.
WPrism\Publish::rrmdir($protocolRoot);
WPrism\Canon::write_file($protocolState . '/revision.txt', "stable\n");
WPrism\Canon::write_file(WPrism\Publish::stage_dir($protocolState) . '/revision.txt', "refused\n");
$wpdb = capture_atomicity_database()->seedTable(
    'wp_wprism_kv',
    [['k' => 'prior', 'v' => 'keep']]
);
$phase = [];
$preCommitFailure = null;
putenv('WPRISM_TEST_MODE=1');
putenv('WPRISM_TEST_PUBLISH_FAIL_PHASE=commit-attempt');
try {
    $invokeConsistentSnapshot(
        static function () use (
            $protocolState, $publicationKey, $publicationMarker, &$phase
        ): array {
            $intent = WPrism\Publish::begin_intent($protocolState, WPrism\Publish::stage_dir($protocolState));
            $phase['filesystem_swapped'] = true;
            WPrism\Publish::swap($protocolState, true);
            $intent = WPrism\Publish::mark_swapped($protocolState, $intent);
            $intent = WPrism\Publish::mark_commit_ready($protocolState, $intent);
            Ledger::kv_set(
                $publicationKey->invoke(null, $protocolState),
                $publicationMarker->invoke(null, $protocolState, $intent)
            );
            $phase['state_dir'] = $protocolState;
            $phase['intent'] = $intent;
            return $intent;
        },
        $phase
    );
} catch (ReflectionException $e) {
    throw $e;
} catch (Throwable $e) {
    $preCommitFailure = $e;
} finally {
    putenv('WPRISM_TEST_PUBLISH_FAIL_PHASE');
    putenv('WPRISM_TEST_MODE');
}
$preCommitCounts = capture_atomicity_transaction_counts($wpdb);
$diskIntent = WPrism\Canon::decode((string) file_get_contents(WPrism\Publish::intent_path($protocolState)));
assert_capture_atomicity($preCommitFailure instanceof RuntimeException && str_contains($preCommitFailure->getMessage(), 'commit-attempt'), 'deterministic pre-COMMIT fault propagates without retry');
assert_capture_atomicity(
    $preCommitCounts['rollbacks'] === 1 && $preCommitCounts['commits'] === 0,
    'deterministic pre-COMMIT fault rolls back the database snapshot'
);
assert_capture_atomicity(($diskIntent['phase'] ?? null) === 'ready', 'pre-COMMIT exception does not falsely record that COMMIT was attempted');
assert_capture_atomicity($publicationStatus->invoke(null, $protocolState, $diskIntent) === false, 'rolled-back transaction leaves no current-intent commit proof');
WPrism\Publish::recover(
    $protocolState,
    static fn(array $intent): bool => $publicationStatus->invoke(null, $protocolState, $intent)
);
assert_capture_atomicity(file_get_contents($protocolState . '/revision.txt') === "stable\n", 'next recovery restores the exact previous tree after rolled-back publication');

// A real unsupported deletion must roll back the mutations that preceded the
// capability check: a dead-map prune, an identity mint, a state hash, and a
// kv marker. Deletion::capture_tombstones() is the actual refusal boundary,
// not a synthetic exception standing in for it.
$uuid = '12345678-1234-4123-8123-123456789012';
$wpdb = capture_atomicity_database()
    ->seedTable('wp_wprism_map', [[
    'uuid' => 'stale', 'entity_type' => 'post', 'id_kind' => 'post', 'local_id' => 99,
    ]])
    ->seedTable('wp_wprism_state', [[
        'uuid' => 'stale', 'entity_type' => 'post', 'content_hash' => 'old',
    ]])
    ->seedTable('wp_wprism_kv', [['k' => 'capture_phase', 'v' => 'before']]);
$beforeRefusal = [
    'map' => $wpdb->rows('wp_wprism_map'),
    'state' => $wpdb->rows('wp_wprism_state'),
    'kv' => $wpdb->rows('wp_wprism_kv'),
];
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
$policy = WPrism\Policy::load(null, ['core']);
$refused = null;
try {
    $invokeConsistentSnapshot(static function () use ($uuid, $previous, $policy): array {
        global $wpdb;
        Db::delete(
            $wpdb->prefix . 'wprism_map',
            ['uuid' => 'stale'],
            null,
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
$refusalCounts = capture_atomicity_transaction_counts($wpdb);
assert_capture_atomicity(
    $refused instanceof CommandRefusalException,
    'unsupported deletion is a reviewed public refusal from the real Deletion capability boundary'
);
$refusalPayload = $refused instanceof CommandRefusalException ? $refused->payload() : [];
assert_capture_atomicity(
    ($refusalPayload['error'] ?? null) === 'unsupported_deletion',
    'unsupported deletion has a finite source-owned reason code'
);
assert_capture_atomicity(
    ($refusalPayload['diagnostics'][0]['surface'] ?? null) === 'post:product',
    'unsupported deletion public evidence names the exact generic selector'
);
assert_capture_atomicity($refused !== null && str_contains($refused->getMessage(), 'post:product'), 'unsupported deletion names the refused selector');
assert_capture_atomicity($wpdb->rows('wp_wprism_map') === $beforeRefusal['map'], 'refusal rolls back wprism_map pruning and identity minting');
assert_capture_atomicity($wpdb->rows('wp_wprism_state') === $beforeRefusal['state'], 'refusal rolls back wprism_state mutation');
assert_capture_atomicity($wpdb->rows('wp_wprism_kv') === $beforeRefusal['kv'], 'refusal rolls back wprism_kv mutation');
assert_capture_atomicity(
    $refusalCounts['rollbacks'] === 1 && $refusalCounts['commits'] === 0,
    'refusal rolls back once and never commits a candidate'
);
assert_capture_atomicity(
    array_filter(
        $wpdb->ddlLog(),
        static fn(string $sql): bool => preg_match('/^(?:CREATE|ALTER) TABLE\b/i', $sql) === 1
    ) === [],
    'transactional capture performs no implicit-commit schema DDL'
);

echo "ALL PASSED\n";

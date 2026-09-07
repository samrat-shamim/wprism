<?php
/**
 * Offline product contract for `wprism deploy`'s database checkpoint and its
 * recoverability.
 *
 * Three things can drift independently, and only the round trip catches the
 * third:
 *
 *   1. WHERE the export sits. It has to be under the promotion lease, after
 *      `promotion-begin` and before `code-stage` — the position promote uses
 *      (cli/wprism:2385-2388). Taken earlier the dump carries no lease row or a
 *      stale one, and `wprism recover`'s abort -> begin -> import -> final abort
 *      sequence (RecoverCommand::ORDERED_STEPS) would restore a database
 *      inconsistent with the lease those four steps re-take
 *      (cli/wprism:3289-3297). Taken later it already describes mutated code.
 *   2. WHAT it is named. `deploy-<runId>.sql` is the sibling of the
 *      `deploy-<runId>.json` artifact, which is the only reason
 *      `RetainedCheckpoints::script()`'s `artifacts/$b.json` identity grep
 *      (RetainedCheckpoints.php) finds this deploy's artifact_hash at all.
 *   3. Whether the catalog can READ that name back and rebuild the same path.
 *      Against the build before this suite, `parse()` throws
 *      `checkpoint_listing_malformed` on a `deploy-` basename, so the round
 *      trip below fails through the product path rather than by inspection.
 *
 * Pure fakes: no target, no docker, no WordPress, no filesystem writes.
 */
declare(strict_types=1);

// From offline/recovery/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';

require_once __DIR__ . '/../../../../agent/src/Recovery/RetainedCheckpointCipher.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/CheckpointRecoveryIntent.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/ProviderSettlementIntent.php';
require_once __DIR__ . '/../../../../agent/src/Repository/SchemaSettlementIntent.php';
require_once __DIR__ . '/../../../../recovery/CanonicalJson.php';
require_once __DIR__ . '/../../../../recovery/AtomicStore.php';
require_once __DIR__ . '/../../../../recovery/ProtocolLock.php';
require_once __DIR__ . '/../../../../recovery/CheckpointRecoveryIntent.php';
require_once __DIR__ . '/../../../../cli/src/Command/DeployCommand.php';
require_once __DIR__ . '/../../../../cli/src/Recovery/RetainedCheckpoints.php';

use WPrism\Orchestrator\DeployCommand;
use WPrism\Orchestrator\CodeDeploy;
use WPrism\Orchestrator\DriverCapabilityReport;
use WPrism\Orchestrator\EnvironmentDriver;
use WPrism\Orchestrator\RetainedCheckpoints;
use WPrism\Canon as AgentCanon;
use WPrism\Ledger as AgentLedger;
use WPrism\Policy as AgentPolicy;
use WPrism\RetainedCheckpointCipher;
use WPrism\SchemaSettlementIntent as AgentSchemaSettlementIntent;
use WPrism\Recovery\CanonicalJson;
use WPrism\Recovery\CheckpointRecoveryIntent as DurableCheckpointRecoveryIntent;
use WPrism\ProviderSettlementIntent as AgentProviderSettlementIntent;
use WPrism\Recovery\ProviderSettlementIntent as DurableProviderSettlementIntent;
use WPrismTest\FakeWpdb;

const DEPLOY_CHECKPOINT_REPO = '/fixture/repo';
const DEPLOY_CHECKPOINT_RUN_ID = 'checkpoint-test-owner';
const DEPLOY_CHECKPOINT_HASH = '4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b4b';

if (!function_exists('is_multisite')) {
    function is_multisite(): bool {
        return false;
    }
}

// FakeWpdb crosses WordPress's real `query` filter surface when it is
// available. This fixture needs only the engine-installed isolation gates,
// not wp_stubs.php's unrelated application APIs used by full WP simulations.
if (!function_exists('apply_filters')) {
    function apply_filters(string $hookName, mixed $value, mixed ...$args): mixed {
        $allGate = is_array($GLOBALS['wp_filter'] ?? null)
            ? ($GLOBALS['wp_filter']['all'] ?? null)
            : null;
        if (is_object($allGate)
            && method_exists($allGate, 'hook_name')
            && method_exists($allGate, 'do_all_hook')) {
            $allArgs = array_merge([$hookName, $value], $args);
            $allGate->do_all_hook($allArgs);
        }
        $gate = is_array($GLOBALS['wp_filter'] ?? null)
            ? ($GLOBALS['wp_filter'][$hookName] ?? null)
            : null;
        if (is_object($gate)
            && method_exists($gate, 'hook_name')
            && method_exists($gate, 'apply_filters')) {
            return $gate->apply_filters($value, array_merge([$value], $args));
        }
        return $value;
    }
}

/** Non-cooperating writer that replaces the named ciphertext on first plaintext output. */
final class SameInodeCheckpointSwapOutput {
    public mixed $context;
    public static string $target = '';
    public static string $replacement = '';
    public static string $output = '';
    public static bool $swapped = false;
    public static bool $sameInode = false;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
        return true;
    }

    public function stream_write(string $data): int {
        if (!self::$swapped) {
            $before = @lstat(self::$target);
            $writer = @fopen(self::$target, 'c+b');
            if (!is_resource($writer) || !ftruncate($writer, 0) || !rewind($writer)) {
                if (is_resource($writer)) fclose($writer);
                return 0;
            }
            $offset = 0;
            while ($offset < strlen(self::$replacement)) {
                $written = fwrite($writer, substr(self::$replacement, $offset));
                if (!is_int($written) || $written <= 0) {
                    fclose($writer);
                    return 0;
                }
                $offset += $written;
            }
            fflush($writer);
            fclose($writer);
            clearstatcache(true, self::$target);
            $after = @lstat(self::$target);
            self::$sameInode = is_array($before) && is_array($after)
                && ($before['dev'] ?? null) === ($after['dev'] ?? null)
                && ($before['ino'] ?? null) === ($after['ino'] ?? null);
            self::$swapped = true;
        }
        self::$output .= $data;
        return strlen($data);
    }

    public function stream_flush(): bool { return true; }

    /** @return array<string,int> */
    public function stream_stat(): array { return []; }
}

foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY'] as $saltName) {
    if (!defined($saltName)) {
        define($saltName, hash('sha256', 'deploy-checkpoint-fixture-' . $saltName));
    }
}
if (!defined('DB_HOST')) {
    define('DB_HOST', 'database.internal:3306');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', 'wordpress');
}
$table_prefix = 'wp_';
$cipherDatabaseTargetSha256 = \WPrism\DatabaseTargetIdentity::fromWordPressConfig();

// A full authentication pass precedes the output pass, so corruption near
// EOF cannot feed an importer a valid SQL prefix before failing.
$cipherRoot = sys_get_temp_dir() . '/wprism-retained-cipher-' . bin2hex(random_bytes(6));
mkdir($cipherRoot . '/.wprism/checkpoints', 0700, true);
$cipherRoot = (string) realpath($cipherRoot);
$cipherPath = $cipherRoot . '/.wprism/checkpoints/deploy-cipher-test.sql.enc';
$plain = "CREATE TABLE prior_state (id bigint);\n" . str_repeat('checkpoint-payload-', 140000);
$plainInput = fopen('php://temp', 'w+b');
fwrite($plainInput, $plain);
rewind($plainInput);
RetainedCheckpointCipher::seal(
    $cipherRoot,
    $cipherPath,
    $plainInput,
    $cipherDatabaseTargetSha256
);
fclose($plainInput);
$cipherBytes = (string) file_get_contents($cipherPath);
wprism_check(!str_contains($cipherBytes, 'CREATE TABLE prior_state'), 'the retained file contains no plaintext SQL');
wprism_check_same(0600, fileperms($cipherPath) & 0777, 'retained ciphertext is owner-readable only');
$verification = RetainedCheckpointCipher::verify($cipherRoot, $cipherPath);
wprism_check_same(
    'wprism-retained-checkpoint-verification/v2',
    $verification['format'],
    'checkpoint verification returns its closed protocol format'
);
wprism_check_same(
    $cipherDatabaseTargetSha256,
    $verification['database_target_sha256'],
    'checkpoint verification authenticates its pre-mutation database target'
);
wprism_check_same(
    hash('sha256', $cipherBytes),
    $verification['cipher_sha256'],
    'checkpoint verification binds the complete authenticated ciphertext'
);
$plainOutput = fopen('php://temp', 'w+b');
RetainedCheckpointCipher::open(
    $cipherRoot,
    $cipherPath,
    $plainOutput,
    $verification['cipher_sha256'],
    $verification['database_target_sha256']
);
rewind($plainOutput);
wprism_check_same($plain, stream_get_contents($plainOutput), 'authenticated ciphertext streams back byte-identically');
fclose($plainOutput);
$wrongTargetOutput = fopen('php://temp', 'w+b');
wprism_check_throws(
    static fn() => RetainedCheckpointCipher::open(
        $cipherRoot,
        $cipherPath,
        $wrongTargetOutput,
        $verification['cipher_sha256'],
        \WPrism\DatabaseTargetIdentity::hash('database.internal:3306', 'other_wordpress', 'wp_')
    ),
    \RuntimeException::class,
    'a target substituted before first recovery refuses against authenticated checkpoint metadata',
    'database target differs from pre-restore authentication'
);
rewind($wrongTargetOutput);
wprism_check_same('', stream_get_contents($wrongTargetOutput), 'pre-recovery target substitution emits no SQL');
fclose($wrongTargetOutput);

// flock is advisory: a same-UID writer can truncate the locked inode after
// authentication. The importer must still read only the private ciphertext
// identity authenticated before its first output byte.
$concurrentReplacementPath = $cipherRoot . '/.wprism/checkpoints/deploy-cipher-concurrent.sql.enc';
$concurrentUnit = 'different-checkpoint-byte-';
$concurrentPlain = str_repeat($concurrentUnit, intdiv(strlen($plain), strlen($concurrentUnit)) + 1);
$concurrentPlain = substr($concurrentPlain, 0, strlen($plain));
$concurrentPlainInput = fopen('php://temp', 'w+b');
fwrite($concurrentPlainInput, $concurrentPlain);
rewind($concurrentPlainInput);
RetainedCheckpointCipher::seal(
    $cipherRoot,
    $concurrentReplacementPath,
    $concurrentPlainInput,
    $cipherDatabaseTargetSha256
);
fclose($concurrentPlainInput);
$concurrentCipher = (string) file_get_contents($concurrentReplacementPath);
wprism_check_same(
    strlen($cipherBytes),
    strlen($concurrentCipher),
    'same-inode substitution fixture is a same-size, valid multi-chunk checkpoint'
);
SameInodeCheckpointSwapOutput::$target = $cipherPath;
SameInodeCheckpointSwapOutput::$replacement = $concurrentCipher;
SameInodeCheckpointSwapOutput::$output = '';
SameInodeCheckpointSwapOutput::$swapped = false;
SameInodeCheckpointSwapOutput::$sameInode = false;
$swapScheme = 'wprismcheckpointswap';
stream_wrapper_register($swapScheme, SameInodeCheckpointSwapOutput::class);
$swapOutput = fopen($swapScheme . '://output', 'wb');
$swapFailure = null;
try {
    RetainedCheckpointCipher::open(
        $cipherRoot,
        $cipherPath,
        $swapOutput,
        $verification['cipher_sha256'],
        $verification['database_target_sha256']
    );
} catch (Throwable $failure) {
    $swapFailure = $failure;
} finally {
    fclose($swapOutput);
    stream_wrapper_unregister($swapScheme);
}
wprism_check($swapFailure === null, 'same-inode ciphertext substitution cannot perturb authenticated restore');
wprism_check(SameInodeCheckpointSwapOutput::$swapped, 'regression performs substitution during the plaintext emission pass');
wprism_check(SameInodeCheckpointSwapOutput::$sameInode, 'regression substitutes bytes without changing the locked inode');
wprism_check_same(
    $plain,
    SameInodeCheckpointSwapOutput::$output,
    'restore emits the complete pre-reset authenticated checkpoint from one private ciphertext identity'
);
wprism_check_same(
    hash('sha256', $concurrentCipher),
    hash_file('sha256', $cipherPath),
    'named locked inode now contains the valid substitute while restore remains bound to its snapshot'
);
@unlink($concurrentReplacementPath);

$tampered = $cipherBytes;
$tampered[strlen($tampered) - 8] = chr(ord($tampered[strlen($tampered) - 8]) ^ 1);
file_put_contents($cipherPath, $tampered);
$refusedOutput = fopen('php://temp', 'w+b');
wprism_check_throws(
    static fn () => RetainedCheckpointCipher::open($cipherRoot, $cipherPath, $refusedOutput),
    \RuntimeException::class,
    'tampered retained ciphertext refuses before restore',
    'failed authentication'
);
rewind($refusedOutput);
wprism_check_same('', stream_get_contents($refusedOutput), 'late ciphertext tampering emits no SQL prefix');
fclose($refusedOutput);

// A different, valid checkpoint can pass its own authentication but cannot be
// substituted after recovery's pre-reset verification.
$replacementInput = fopen('php://temp', 'w+b');
fwrite($replacementInput, "CREATE TABLE substituted_state (id bigint);\n");
rewind($replacementInput);
$replacementPath = $cipherRoot . '/.wprism/checkpoints/deploy-cipher-replacement.sql.enc';
RetainedCheckpointCipher::seal(
    $cipherRoot,
    $replacementPath,
    $replacementInput,
    $cipherDatabaseTargetSha256
);
fclose($replacementInput);
unlink($cipherPath);
rename($replacementPath, $cipherPath);
$changedOutput = fopen('php://temp', 'w+b');
wprism_check_throws(
    static fn () => RetainedCheckpointCipher::open(
        $cipherRoot,
        $cipherPath,
        $changedOutput,
        $verification['cipher_sha256']
    ),
    \RuntimeException::class,
    'valid ciphertext replacement refuses before restore',
    'changed after pre-restore authentication'
);
rewind($changedOutput);
wprism_check_same('', stream_get_contents($changedOutput), 'changed valid ciphertext emits no SQL prefix');
fclose($changedOutput);
@unlink($cipherPath);
@rmdir($cipherRoot . '/.wprism/checkpoints');
@rmdir($cipherRoot . '/.wprism');
@rmdir($cipherRoot);

// The destructive import identity lives outside both managed code and the DB.
// Exact A may resume after reset; B cannot reinterpret the partial database as
// a new attempt, and only exact terminal cleanup removes the fence.
$intentRepo = sys_get_temp_dir() . '/wprism-checkpoint-intent-東京-' . bin2hex(random_bytes(6));
mkdir($intentRepo . '/.wprism/control', 0700, true);
mkdir($intentRepo . '/.wprism/checkpoints', 0700, true);
$intentRepo = (string) realpath($intentRepo);
$intentRoot = $intentRepo . '/.wprism/control';
$checkpointA = $intentRepo . '/.wprism/checkpoints/deploy-intent-a.sql.enc';
$checkpointB = $intentRepo . '/.wprism/checkpoints/deploy-intent-b.sql.enc';
file_put_contents($checkpointA, 'ciphertext-a');
file_put_contents($checkpointB, 'ciphertext-b');
$digestA = hash_file('sha256', $checkpointA);
$digestB = hash_file('sha256', $checkpointB);
$databaseTargetSha256 = DurableCheckpointRecoveryIntent::databaseTargetHash(
    'database.internal:3306',
    'wordpress',
    'wp_'
);
$differentDatabaseTargetSha256 = DurableCheckpointRecoveryIntent::databaseTargetHash(
    'database.internal:3306',
    'wordpress_replacement',
    'wp_'
);
$priorWpdbExists = array_key_exists('wpdb', $GLOBALS);
$priorWpdb = $GLOBALS['wpdb'] ?? null;
$producerDb = FakeWpdb::install();
$producerDb
    ->seedTable('wp_wprism_kv', [])
    ->setTableEngine('wp_wprism_kv', 'InnoDB')
    ->setColumns('wp_wprism_kv', ['k' => 'varchar(191)', 'v' => 'longtext'])
    ->setUniqueKey('wp_wprism_kv', ['k'])
    ->setIndexes('wp_wprism_kv', [[
        'Key_name' => 'PRIMARY', 'Non_unique' => 0, 'Seq_in_index' => 1,
        'Column_name' => 'k', 'Sub_part' => null, 'Index_type' => 'BTREE', 'Visible' => 'YES', 'Ignored' => 'NO',
    ]])
    ->enableInformationSchema();
$producerPolicy = new AgentPolicy();
$producerPolicy->manifests = [[
    'name' => 'schema-wire-fixture',
    'actions' => [[
        'kind' => 'provider',
        'phase' => 'schema_settle',
        'provider' => 'fixture-schema',
        'capability' => 'prepare_schema',
        'prepares' => ['rank_math_internal_links'],
        'effects' => [[
            'id' => 'fixture-schema-table',
            'kind' => 'database',
            'mode' => 'restorable',
            'selector' => [
                'scope' => 'database_checkpoint',
                'type' => 'table',
                'value' => 'rank_math_internal_links',
            ],
        ]],
    ]],
]];
$schemaIntentDocument = AgentSchemaSettlementIntent::begin(
    $producerPolicy,
    DEPLOY_CHECKPOINT_HASH,
    ['path' => $checkpointA, 'cipher_sha256' => $digestA],
    [['table' => 'rank_math_internal_links', 'present' => false]]
);
$schemaIntentRaw = AgentLedger::kv_get('schema_settlement_in_progress');
wprism_check(is_string($schemaIntentRaw), 'the real schema producer persists its recovery witness in the ledger');
$schemaIntent = (string) $schemaIntentRaw;
// This value crosses from the loaded agent into the standalone recovery
// runtime. Retain parity with the producer while exercising the actual
// SchemaSettlementIntent -> Ledger call path that the live failure crossed.
wprism_check(
    hash_equals(AgentCanon::encode($schemaIntentDocument), $schemaIntent)
        && $schemaIntent !== CanonicalJson::encode($schemaIntentDocument)
        && str_ends_with($schemaIntent, "\n")
        && str_contains($schemaIntent, '東京'),
    'schema recovery fixture carries the exact sorted, pretty, Unicode agent bytes with one trailing LF'
);
$beginIntent = static fn(string $topology, string $intent): array => DurableCheckpointRecoveryIntent::begin(
    $intentRoot,
    $intentRepo,
    $checkpointA,
    $digestA,
    DEPLOY_CHECKPOINT_RUN_ID,
    DEPLOY_CHECKPOINT_HASH,
    $databaseTargetSha256,
    $topology,
    $intent
);
wprism_check_throws(
    static fn() => $beginIntent('multisite', $schemaIntent),
    \RuntimeException::class,
    'a network topology cannot be durably authorized for whole-database restore',
    'single-site topology only'
);
$wrongArtifactIntent = $schemaIntentDocument;
$wrongArtifactIntent['artifact_hash'] = str_repeat('f', 64);
wprism_check_throws(
    static fn() => $beginIntent('single-site', AgentCanon::encode($wrongArtifactIntent)),
    \RuntimeException::class,
    'schema debt from another artifact cannot bind this checkpoint recovery',
    'does not match the incomplete schema settlement'
);
$badDigestIntent = $schemaIntentDocument;
$badDigestIntent['actions_sha256'] = 'not-a-digest';
wprism_check_throws(
    static fn() => $beginIntent('single-site', AgentCanon::encode($badDigestIntent)),
    \RuntimeException::class,
    'malformed schema action identity cannot cross the durable recovery boundary',
    'schema settlement intent is malformed'
);
$allPresentIntent = $schemaIntentDocument;
$allPresentIntent['presence'][0]['present'] = true;
wprism_check_throws(
    static fn() => $beginIntent('single-site', AgentCanon::encode($allPresentIntent)),
    \RuntimeException::class,
    'schema recovery debt must retain the absent-table cause that made settlement destructive',
    'schema settlement intent is malformed'
);
$assertNonProducerIntent = static function (string $wire, string $label) use (
    $beginIntent,
    $intentRoot,
    $intentRepo,
    $checkpointA
): void {
    $accepted = false;
    $message = '';
    try {
        $beginIntent('single-site', $wire);
        $accepted = true;
    } catch (\RuntimeException $error) {
        $message = $error->getMessage();
    }
    if ($accepted) {
        DurableCheckpointRecoveryIntent::complete(
            $intentRoot,
            $intentRepo,
            $checkpointA,
            DEPLOY_CHECKPOINT_RUN_ID,
            DEPLOY_CHECKPOINT_HASH
        );
    }
    wprism_check(
        !$accepted && str_contains($message, 'schema settlement intent is malformed'),
        $label
    );
};
$assertNonProducerIntent(
    CanonicalJson::encode($schemaIntentDocument),
    'recovery refuses consumer-canonical bytes that the agent producer can never publish'
);
$reorderedIntentDocument = [
    'tables' => $schemaIntentDocument['tables'],
    'presence' => [[
        'table' => $schemaIntentDocument['presence'][0]['table'],
        'present' => $schemaIntentDocument['presence'][0]['present'],
    ]],
    'format' => $schemaIntentDocument['format'],
    'effects_sha256' => $schemaIntentDocument['effects_sha256'],
    'checkpoint' => [
        'cipher_sha256' => $schemaIntentDocument['checkpoint']['cipher_sha256'],
        'path' => $schemaIntentDocument['checkpoint']['path'],
    ],
    'artifact_hash' => $schemaIntentDocument['artifact_hash'],
    'actions_sha256' => $schemaIntentDocument['actions_sha256'],
];
$reorderedIntent = (string) json_encode(
    $reorderedIntentDocument,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
) . "\n";
wprism_check(
    AgentCanon::encode(json_decode($reorderedIntent, true, 16, JSON_THROW_ON_ERROR)) === $schemaIntent
        && $reorderedIntent !== $schemaIntent,
    'key-order mutation is semantically identical but cannot be emitted by the recursive producer codec'
);
$assertNonProducerIntent(
    $reorderedIntent,
    'recovery refuses a pretty semantic twin with reordered top-level and nested keys'
);
$nestedReorderedDocument = $schemaIntentDocument;
$nestedReorderedDocument['presence'] = [[
    'table' => $schemaIntentDocument['presence'][0]['table'],
    'present' => $schemaIntentDocument['presence'][0]['present'],
]];
$nestedReorderedIntent = (string) json_encode(
    $nestedReorderedDocument,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
) . "\n";
wprism_check(
    AgentCanon::encode(json_decode($nestedReorderedIntent, true, 16, JSON_THROW_ON_ERROR)) === $schemaIntent
        && $nestedReorderedIntent !== $schemaIntent,
    'nested-order mutation retains producer top-level order and semantic identity'
);
$assertNonProducerIntent(
    $nestedReorderedIntent,
    'recovery refuses a semantic twin whose nested keys alone evade recursive producer order'
);
$assertNonProducerIntent(
    substr($schemaIntent, 0, -1),
    'recovery refuses otherwise-valid agent bytes without their terminal LF'
);
$assertNonProducerIntent(
    $schemaIntent . "\n",
    'recovery refuses otherwise-valid agent bytes with an extra terminal LF'
);

// Execute the generated post-config child program: it must read the producer's
// raw ledger value and hand those bytes unchanged to standalone recovery.
$GLOBALS['wprism_checkpoint_recovery_payload'] = [
    'artifact_hash' => DEPLOY_CHECKPOINT_HASH,
    'checkpoint' => $checkpointA,
    'owner' => DEPLOY_CHECKPOINT_RUN_ID,
    'repo' => $intentRepo,
];
$GLOBALS['wprism_checkpoint_recovery_verification'] = ['cipher_sha256' => $digestA];
$GLOBALS['wprism_checkpoint_recovery_database_target_sha256'] = $databaseTargetSha256;
$GLOBALS['wprism_checkpoint_recovery_summary'] = null;
$beginArgs = CodeDeploy::checkpointRecoveryBeginArgs(
    $intentRepo,
    $checkpointA,
    DEPLOY_CHECKPOINT_RUN_ID,
    DEPLOY_CHECKPOINT_HASH
);
$generatedBegin = (string) ($beginArgs[4] ?? '');
ob_start();
try {
    eval($generatedBegin);
    $generatedSummary = (string) ob_get_clean();
} catch (\Throwable $failure) {
    ob_end_clean();
    throw $failure;
} finally {
    unset(
        $GLOBALS['wprism_checkpoint_recovery_payload'],
        $GLOBALS['wprism_checkpoint_recovery_verification'],
        $GLOBALS['wprism_checkpoint_recovery_database_target_sha256'],
        $GLOBALS['wprism_checkpoint_recovery_summary']
    );
    if ($priorWpdbExists) {
        $GLOBALS['wpdb'] = $priorWpdb;
    } else {
        unset($GLOBALS['wpdb']);
    }
}
$begunIntent = json_decode($generatedSummary, true, 16, JSON_THROW_ON_ERROR);
wprism_check(
    is_array($begunIntent) && ($begunIntent['schema_intent'] ?? null) === true,
    'the generated CodeDeploy child forwards the real ledger bytes into standalone recovery'
);
wprism_check_same(false, $begunIntent['resumed'], 'first destructive attempt durably publishes recovery identity');
wprism_check_same(true, $begunIntent['schema_intent'], 'external recovery identity retains the pre-reset schema debt');
wprism_check_same(
    $databaseTargetSha256,
    $begunIntent['database_target_sha256'],
    'destructive authorization reports only the bound database target digest'
);
$intentPath = $intentRoot . '/checkpoint-recovery-intent.json';
wprism_check_same(0600, fileperms($intentPath) & 0777, 'external recovery identity is owner-readable only');
$persistedIntent = json_decode((string) file_get_contents($intentPath), true, 16, JSON_THROW_ON_ERROR);
wprism_check_same('single-site', $persistedIntent['topology'] ?? null, 'durable recovery identity binds the pre-reset topology');
wprism_check_same(
    $databaseTargetSha256,
    $persistedIntent['database_target_sha256'] ?? null,
    'durable recovery identity binds the effective wp-config database target'
);
wprism_check_same(
    hash('sha256', $schemaIntent),
    $persistedIntent['schema_intent_sha256'] ?? null,
    'durable recovery identity hashes the exact agent-produced schema intent bytes'
);
wprism_check(
    !str_contains((string) file_get_contents($intentPath), 'database.internal')
        && !str_contains((string) file_get_contents($intentPath), 'wordpress_replacement'),
    'durable recovery identity does not disclose configured database coordinates'
);
DurableCheckpointRecoveryIntent::assertDatabaseTarget($intentRoot, $databaseTargetSha256);
wprism_check_throws(
    static fn() => \WPrism\CheckpointRecoveryIntent::assert_clear($intentRepo),
    \RuntimeException::class,
    'agent policy observation is fenced while external recovery debt exists',
    'incomplete checkpoint recovery blocks'
);
$resumedIntent = DurableCheckpointRecoveryIntent::resume(
    $intentRoot,
    $intentRepo,
    $checkpointA,
    $digestA,
    DEPLOY_CHECKPOINT_RUN_ID,
    DEPLOY_CHECKPOINT_HASH,
    $databaseTargetSha256
);
wprism_check_same(true, $resumedIntent['resumed'] ?? null, 'exact A resumes without consulting the reset database');
wprism_check_throws(
    static fn() => DurableCheckpointRecoveryIntent::resume(
        $intentRoot,
        $intentRepo,
        $checkpointA,
        $digestA,
        DEPLOY_CHECKPOINT_RUN_ID,
        DEPLOY_CHECKPOINT_HASH,
        $differentDatabaseTargetSha256
    ),
    \RuntimeException::class,
    'an exact checkpoint retry cannot reset a newly configured database target',
    'database target differs from the incomplete recovery'
);
wprism_check_throws(
    static fn() => DurableCheckpointRecoveryIntent::assertDatabaseTarget(
        $intentRoot,
        $differentDatabaseTargetSha256
    ),
    \RuntimeException::class,
    'a fresh reset or import process cannot address a newly configured database target',
    'database target differs from the incomplete recovery'
);
wprism_check_throws(
    static fn() => DurableCheckpointRecoveryIntent::resume(
        $intentRoot,
        $intentRepo,
        $checkpointB,
        $digestB,
        DEPLOY_CHECKPOINT_RUN_ID,
        DEPLOY_CHECKPOINT_HASH,
        $databaseTargetSha256
    ),
    \RuntimeException::class,
    'checkpoint B refuses while failed checkpoint A owns recovery',
    'different checkpoint or release'
);
wprism_check_same(true, is_file($intentPath), 'substitution refusal retains checkpoint A recovery debt');
wprism_check_throws(
    static fn() => DurableCheckpointRecoveryIntent::complete(
        $intentRoot,
        $intentRepo,
        $checkpointB,
        DEPLOY_CHECKPOINT_RUN_ID,
        DEPLOY_CHECKPOINT_HASH
    ),
    \RuntimeException::class,
    'completion cannot clear a different checkpoint identity',
    'different checkpoint or release'
);
$completedIntent = DurableCheckpointRecoveryIntent::complete(
    $intentRoot,
    $intentRepo,
    $checkpointA,
    DEPLOY_CHECKPOINT_RUN_ID,
    DEPLOY_CHECKPOINT_HASH
);
wprism_check_same(false, $completedIntent['active'], 'exact terminal completion durably clears recovery debt');
wprism_check_same(false, DurableCheckpointRecoveryIntent::status($intentRoot)['active'], 'cleared recovery status is exact');
\WPrism\CheckpointRecoveryIntent::assert_clear($intentRepo);
@unlink($intentRoot . '/checkpoint-recovery-intent.lock');
@unlink($checkpointA);
@unlink($checkpointB);
@rmdir($intentRepo . '/.wprism/checkpoints');
@rmdir($intentRoot);
@rmdir($intentRepo . '/.wprism');
@rmdir($intentRepo);

// Provider work is one ordered, checkpoint-backed transaction across fresh
// WordPress processes. Schema success cannot disappear before lifecycle
// settlement, and checkpoint recovery is the only operation allowed to clear
// an incomplete transaction.
$providerRepo = sys_get_temp_dir() . '/wprism-provider-intent-' . bin2hex(random_bytes(6));
mkdir($providerRepo . '/.wprism/control', 0700, true);
mkdir($providerRepo . '/.wprism/artifacts', 0700, true);
mkdir($providerRepo . '/.wprism/checkpoints', 0700, true);
$providerRepo = (string) realpath($providerRepo);
$providerRoot = $providerRepo . '/.wprism/control';
$providerOwner = 'provider-owner';
$providerArtifact = $providerRepo . '/.wprism/artifacts/deploy-' . $providerOwner . '.json';
$providerCheckpoint = $providerRepo . '/.wprism/checkpoints/deploy-' . $providerOwner . '.sql.enc';
file_put_contents($providerArtifact, '{}');
file_put_contents($providerCheckpoint, 'provider-checkpoint-ciphertext');
$providerCipherSha256 = hash_file('sha256', $providerCheckpoint);
$providerPhases = ['schema-settle', 'lifecycle-settle'];
$providerBegin = DurableProviderSettlementIntent::begin(
    $providerRoot,
    $providerRepo,
    $providerArtifact,
    $providerCheckpoint,
    $providerCipherSha256,
    $providerOwner,
    DEPLOY_CHECKPOINT_HASH,
    $providerPhases
);
wprism_check_same(false, $providerBegin['resumed'], 'first provider pass durably publishes its transaction');
$providerIntentPath = $providerRoot . '/provider-settlement-intent.json';
wprism_check_same(0600, fileperms($providerIntentPath) & 0777, 'provider debt is owner-readable only');
$providerRecoveryStatus = DurableProviderSettlementIntent::recoveryStatus($providerRoot);
wprism_check_same(
    [
        'active' => true,
        'artifact_hash' => DEPLOY_CHECKPOINT_HASH,
        'checkpoint' => [
            'cipher_sha256' => $providerCipherSha256,
            'path' => $providerCheckpoint,
        ],
        'format' => 'wprism-provider-settlement-recovery/v1',
        'owner' => $providerOwner,
    ],
    $providerRecoveryStatus,
    'database-external provider debt exposes exactly one recovery row before host profile selection'
);
wprism_check_throws(
    static fn() => AgentProviderSettlementIntent::assert_clear($providerRepo),
    \RuntimeException::class,
    'ordinary policy observation is fenced during provider settlement',
    'incomplete provider settlement blocks'
);
wprism_check_throws(
    static fn() => DurableProviderSettlementIntent::advance(
        $providerRoot,
        $providerRepo,
        $providerArtifact,
        $providerCheckpoint,
        $providerOwner,
        DEPLOY_CHECKPOINT_HASH,
        $providerPhases,
        'lifecycle-settle'
    ),
    \RuntimeException::class,
    'lifecycle settlement cannot skip the declared schema phase',
    'out of order'
);
wprism_check_throws(
    static fn() => DurableProviderSettlementIntent::complete(
        $providerRoot,
        $providerRepo,
        $providerArtifact,
        $providerCheckpoint,
        $providerOwner,
        DEPLOY_CHECKPOINT_HASH,
        $providerPhases
    ),
    \RuntimeException::class,
    'provider debt cannot clear before any declared phase succeeds',
    'before every declared phase advanced'
);
$continuedFormat = AgentProviderSettlementIntent::with_phase(
    $providerRepo,
    $providerArtifact,
    $providerCheckpoint,
    $providerOwner,
    DEPLOY_CHECKPOINT_HASH,
    'schema-settle',
    static function (array $intent) use ($providerRepo): string {
        AgentProviderSettlementIntent::assert_clear($providerRepo);
        return (string) ($intent['format'] ?? '');
    }
);
wprism_check_same(
    DurableProviderSettlementIntent::FORMAT,
    $continuedFormat,
    'only the exact next provider process receives a policy-load continuation'
);
$schemaProgress = DurableProviderSettlementIntent::advance(
    $providerRoot,
    $providerRepo,
    $providerArtifact,
    $providerCheckpoint,
    $providerOwner,
    DEPLOY_CHECKPOINT_HASH,
    $providerPhases,
    'schema-settle'
);
wprism_check_same(
    ['schema-settle'],
    $schemaProgress['completed_phases'],
    'schema completion is durably retained before lifecycle settlement starts'
);
$persistedProviderIntent = json_decode(
    (string) file_get_contents($providerIntentPath),
    true,
    16,
    JSON_THROW_ON_ERROR
);
wprism_check_same(
    ['schema-settle'],
    $persistedProviderIntent['completed_phases'] ?? null,
    'a fresh process observes exact provider phase progress'
);
wprism_check_throws(
    static fn() => DurableProviderSettlementIntent::complete(
        $providerRoot,
        $providerRepo,
        $providerArtifact,
        $providerCheckpoint,
        $providerOwner,
        DEPLOY_CHECKPOINT_HASH,
        $providerPhases
    ),
    \RuntimeException::class,
    'schema completion alone cannot clear lifecycle-provider debt',
    'before every declared phase advanced'
);
$providerRecovery = DurableCheckpointRecoveryIntent::begin(
    $providerRoot,
    $providerRepo,
    $providerCheckpoint,
    $providerCipherSha256,
    $providerOwner,
    DEPLOY_CHECKPOINT_HASH,
    DurableCheckpointRecoveryIntent::databaseTargetHash('database.internal', 'wordpress', 'wp_'),
    'single-site',
    null
);
wprism_check_same(true, $providerRecovery['provider_intent'], 'checkpoint recovery binds the exact provider transaction');
DurableCheckpointRecoveryIntent::complete(
    $providerRoot,
    $providerRepo,
    $providerCheckpoint,
    $providerOwner,
    DEPLOY_CHECKPOINT_HASH
);
wprism_check_same(
    false,
    DurableProviderSettlementIntent::status($providerRoot)['active'],
    'successful exact checkpoint recovery clears unfinished provider debt'
);
AgentProviderSettlementIntent::assert_clear($providerRepo);

// The non-recovery terminal path consumes every phase in order before it may
// clear the same database-external fence.
DurableProviderSettlementIntent::begin(
    $providerRoot,
    $providerRepo,
    $providerArtifact,
    $providerCheckpoint,
    $providerCipherSha256,
    $providerOwner,
    DEPLOY_CHECKPOINT_HASH,
    $providerPhases
);
foreach ($providerPhases as $phase) {
    DurableProviderSettlementIntent::advance(
        $providerRoot,
        $providerRepo,
        $providerArtifact,
        $providerCheckpoint,
        $providerOwner,
        DEPLOY_CHECKPOINT_HASH,
        $providerPhases,
        $phase
    );
}
$providerComplete = DurableProviderSettlementIntent::complete(
    $providerRoot,
    $providerRepo,
    $providerArtifact,
    $providerCheckpoint,
    $providerOwner,
    DEPLOY_CHECKPOINT_HASH,
    $providerPhases
);
wprism_check_same(false, $providerComplete['active'], 'ordered provider completion clears its fence');
AgentProviderSettlementIntent::assert_clear($providerRepo);
foreach ([
    'checkpoint-recovery-intent.lock',
    'provider-settlement-intent.lock',
] as $lockFile) {
    @unlink($providerRoot . '/' . $lockFile);
}
@unlink($providerArtifact);
@unlink($providerCheckpoint);
@rmdir($providerRepo . '/.wprism/artifacts');
@rmdir($providerRepo . '/.wprism/checkpoints');
@rmdir($providerRoot);
@rmdir($providerRepo . '/.wprism');
@rmdir($providerRepo);

/**
 * The same fake-driver shape `offline/cli/regress_deploy_command.php` proves
 * the phase graph with, extended with the one primitive the checkpoint uses:
 * `captureWp(['db','export',...])`. No `$wpdb` fake is needed — the export is
 * a wp-cli call, not SQL.
 */
final class DeployCheckpointDriver implements EnvironmentDriver {
    /** @var list<string> every target interaction, in order */
    public array $events = [];
    /** @var list<array<int,string>> */
    public array $calls = [];
    /** @var list<string> every raw script this driver was handed */
    public array $rawScripts = [];
    public int $exportExit = 0;
    public int $verifyExit = 0;
    public int $resetExit = 0;
    public int $importExit = 0;
    public int $stageExit = 0;
    public int $targetExit = 0;
    public bool $codeEnabled = true;
    public bool $lifecycleChangeRequired = false;

    public function name(): string { return 'checkpoint-fixture'; }
    public function driverId(): string { return 'checkpoint-fixture'; }
    public function repoPath(): string { return DEPLOY_CHECKPOINT_REPO; }
    public function describe(): string { return 'deploy checkpoint fixture'; }

    public function captureRaw(string $script): array {
        $this->rawScripts[] = $script;
        $this->events[] = 'raw:mkdir';
        return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
    }

    public function captureWp(array $wpArgs): array {
        $this->calls[] = $wpArgs;
        if (count(array_filter(
            $wpArgs,
            static fn(string $arg): bool => str_contains($arg, 'wprismRecoveryPreflight')
        )) === 1) {
            $this->events[] = 'capture:checkpoint-recovery-preflight';
            return [
                'exit' => $this->verifyExit,
                'stdout' => json_encode([
                    'cipher_sha256' => str_repeat('c', 64),
                    'database_target_sha256' => str_repeat('d', 64),
                    'format' => 'wprism-retained-checkpoint-verification/v2',
                ], JSON_THROW_ON_ERROR),
                'stderr' => $this->verifyExit === 0 ? '' : 'target mismatch',
            ];
        }
        if (($wpArgs[0] ?? null) === 'db' && ($wpArgs[1] ?? null) === 'export') {
            $this->events[] = 'capture:db-export';
            return ['exit' => $this->exportExit, 'stdout' => (string) ($wpArgs[2] ?? ''), 'stderr' => ''];
        }
        if (count(array_filter(
            $wpArgs,
            static fn(string $arg): bool => str_contains($arg, 'CheckpointRecoveryIntent::resume')
        )) === 1) {
            $this->events[] = 'capture:checkpoint-recovery-begin';
            return [
                'exit' => $this->verifyExit,
                'stdout' => json_encode([
                    'cipher_sha256' => str_repeat('c', 64),
                    'database_target_sha256' => str_repeat('d', 64),
                    'format' => 'wprism-checkpoint-recovery-intent/v1',
                    'provider_intent' => false,
                    'resumed' => false,
                    'schema_intent' => true,
                ], JSON_THROW_ON_ERROR),
                'stderr' => $this->verifyExit === 0 ? '' : 'verification failed',
            ];
        }
        if (in_array('db', $wpArgs, true)
            && in_array('reset', $wpArgs, true)) {
            $this->events[] = 'capture:db-reset';
            return [
                'exit' => $this->resetExit,
                'stdout' => '',
                'stderr' => $this->resetExit === 0 ? '' : 'reset failed',
            ];
        }
        $command = $this->command($wpArgs);
        $this->events[] = 'capture:' . $command;
        if ($command === 'checkpoint-target') {
            return [
                'exit' => $this->targetExit,
                'stdout' => json_encode([
                    'database_target_sha256' => str_repeat('d', 64),
                    'format' => 'wprism-database-target/v1',
                ], JSON_THROW_ON_ERROR),
                'stderr' => $this->targetExit === 0 ? '' : 'target preflight failed',
            ];
        }
        $revision = str_repeat('b', 64);
        if ($command === 'compile') {
            $summary = ['artifact_hash' => DEPLOY_CHECKPOINT_HASH];
            if ($this->codeEnabled) {
                $summary['code'] = ['code_revision' => $revision, 'format' => 1];
            }
            return ['exit' => 0, 'stdout' => json_encode($summary, JSON_THROW_ON_ERROR), 'stderr' => ''];
        }
        if ($command === 'code-preflight') {
            return ['exit' => 0, 'stdout' => json_encode([
                'format' => 'wprism-code-runtime/v1', 'enabled' => true, 'change_required' => true, 'compatible' => true,
                'code_revision' => $revision,
                'target' => ['php' => '8.3', 'wordpress' => '6.8', 'source' => 'target-control-plane'],
                'requirements' => [], 'diagnostics' => [],
            ], JSON_THROW_ON_ERROR), 'stderr' => ''];
        }
        if ($command === 'lifecycle-status') {
            return ['exit' => 0, 'stdout' => json_encode([
                'format' => 'wprism-lifecycle-status/v2',
                'reasons' => $this->lifecycleChangeRequired ? ['inactive_in_environment'] : [],
                'required' => $this->lifecycleChangeRequired,
                'baseline_state' => 'exact',
                'code_drift' => [],
                'code_boundary_sha256' => str_repeat('1', 64),
                'findings_sha256' => str_repeat('2', 64),
                'observation_sha256' => str_repeat('3', 64),
                'warnings' => [],
            ], JSON_THROW_ON_ERROR), 'stderr' => ''];
        }
        if ($command === 'promotion-begin') {
            return ['exit' => 0, 'stdout' => 'begun', 'stderr' => ''];
        }
        throw new \RuntimeException("unexpected capture command $command");
    }

    public function captureWpPipeline(array $producer, array $consumer): array {
        $this->calls[] = $producer;
        $this->calls[] = $consumer;
        $opensCheckpoint = count(array_filter(
            $producer,
            static fn(string $arg): bool => str_contains($arg, 'RetainedCheckpointCipher::open')
        )) === 1;
        $this->events[] = $opensCheckpoint
            ? 'capture:checkpoint-import'
            : 'capture:db-export';

        $exit = $opensCheckpoint ? $this->importExit : $this->exportExit;
        return ['exit' => $exit, 'stdout' => '', 'stderr' => ''];
    }

    public function streamWp(array $wpArgs): int {
        $this->calls[] = $wpArgs;
        $command = $this->command($wpArgs);
        $phase = null;
        foreach ($wpArgs as $arg) {
            if (str_starts_with($arg, '--lifecycle-phase=')) {
                $phase = substr($arg, strlen('--lifecycle-phase='));
            }
        }
        $this->events[] = 'stream:' . $command . ($phase === null ? '' : ':' . $phase);
        return $command === 'code-stage' ? $this->stageExit : 0;
    }

    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }

    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver('checkpoint-fixture', 'checkpoint-fixture', $operation, []);
    }

    /** @param array<int,string> $args */
    private function command(array $args): string {
        $index = array_search('wprism', $args, true);
        if (!is_int($index) || !isset($args[$index + 1])) {
            throw new \RuntimeException('driver did not receive a wprism command');
        }
        return $args[$index + 1];
    }
}

// A whole-database checkpoint is topology authority, not merely a list of SQL
// writes. Authenticate first, reset every current object, then import only the
// exact ciphertext identity which passed that pre-destructive gate.
$restoreDriver = new DeployCheckpointDriver();
$recoveryPreflight = CodeDeploy::checkpointRecoveryPreflight(
    $restoreDriver,
    DEPLOY_CHECKPOINT_REPO,
    DEPLOY_CHECKPOINT_REPO . '/.wprism/checkpoints/deploy-restore.sql.enc'
);
wprism_check_same(
    str_repeat('d', 64),
    $recoveryPreflight['summary']['database_target_sha256'] ?? null,
    'recovery authenticates the checkpointed database target before lease mutation'
);
$preflightExec = implode(' ', $restoreDriver->calls[0] ?? []);
wprism_check(
    str_contains($preflightExec, 'RetainedCheckpointCipher::verify')
        && str_contains($preflightExec, 'DatabaseTargetIdentity::assertWordPressConfig'),
    'recovery preflight compares authenticated metadata to wp-config before step 1'
);
$cliSource = (string) file_get_contents(dirname(__DIR__, 4) . '/agent/src/Command/Cli.php');
wprism_check(
    !str_contains($cliSource, 'public function checkpoint_verify')
        && !str_contains($cliSource, 'public function checkpoint_open'),
    'checkpoint authentication and opening have no post-WordPress public command authority'
);
$restoreDriver->events = [];
$restoreDriver->calls = [];
$restoreResult = CodeDeploy::encryptedCheckpointImport(
    $restoreDriver,
    DEPLOY_CHECKPOINT_REPO,
    DEPLOY_CHECKPOINT_REPO . '/.wprism/checkpoints/deploy-restore.sql.enc',
    DEPLOY_CHECKPOINT_RUN_ID,
    DEPLOY_CHECKPOINT_HASH
);
wprism_check_same(0, $restoreResult['exit'], 'topology-exact encrypted restore succeeds');
wprism_check_same(
    ['capture:checkpoint-recovery-begin', 'capture:db-reset', 'capture:checkpoint-import'],
    $restoreDriver->events,
    'restore authenticates before reset and imports only after exact topology removal'
);
$restoreOpen = $restoreDriver->calls[2] ?? [];
$openExec = array_values(array_filter(
    $restoreOpen,
    static fn(string $arg): bool => str_starts_with($arg, '--exec=')
))[0] ?? '';
$openPayload = null;
if (preg_match("/base64_decode\\('([^']+)'/", $openExec, $match) === 1) {
    $openPayload = json_decode((string) base64_decode($match[1], true), true);
}
wprism_check_same(
    str_repeat('c', 64),
    $openPayload['cipher_sha256'] ?? null,
    'post-reset checkpoint-open is bound to the pre-reset authenticated ciphertext'
);
$restoreReset = $restoreDriver->calls[1] ?? [];
wprism_check(
    in_array('reset', $restoreReset, true) && in_array('--yes', $restoreReset, true),
    'restore uses an explicit noninteractive exact database reset'
);
$restoreImport = $restoreDriver->calls[3] ?? [];
foreach (['reset' => $restoreReset, 'import' => $restoreImport] as $operation => $call) {
    $exec = array_values(array_filter(
        $call,
        static fn(string $arg): bool => str_starts_with($arg, '--exec=')
    ))[0] ?? '';
    wprism_check(
        str_contains($exec, 'CheckpointRecoveryIntent::assertDatabaseTarget')
            && str_contains($exec, 'DatabaseTargetIdentity::fromWordPressConfig'),
        "$operation process rechecks the durable and effective database target before access"
    );
}

$unverifiedRestore = new DeployCheckpointDriver();
$unverifiedRestore->verifyExit = 17;
$unverifiedResult = CodeDeploy::encryptedCheckpointImport(
    $unverifiedRestore,
    DEPLOY_CHECKPOINT_REPO,
    DEPLOY_CHECKPOINT_REPO . '/.wprism/checkpoints/deploy-unverified.sql.enc',
    DEPLOY_CHECKPOINT_RUN_ID,
    DEPLOY_CHECKPOINT_HASH
);
wprism_check_same(17, $unverifiedResult['exit'], 'checkpoint authentication failure propagates');
wprism_check_same(
    ['capture:checkpoint-recovery-begin'],
    $unverifiedRestore->events,
    'failed authentication performs no destructive topology reset'
);

/**
 * Drive `DeployCommand::run()` with recording collaborators.
 *
 * Only STDOUT is captured. `DeployCommand` writes its refusals to the STDERR
 * constant, which an in-process suite cannot rebind without leaving a closed
 * stream behind — so the STDERR sentences are pinned end to end, through a real
 * `cli/wprism` subprocess, by `offline/code-half/regress_code_deploy_unit.sh`.
 * What matters here is what the collaborators were HANDED, which is the part a
 * shell suite cannot see.
 *
 * @param list<string> $extra
 * @return array{exit:int,callbacks:list<string>,stdout:string}
 */
function run_deploy_checkpoint(DeployCheckpointDriver $driver, array $extra): array {
    $callbacks = [];
    ob_start();
    try {
        $exit = DeployCommand::run(
            $driver,
            $extra,
            static function (array $args) use (&$callbacks): ?int {
                $callbacks[] = 'scope';
                return null;
            },
            static function (EnvironmentDriver $transport) use (&$callbacks): bool {
                $callbacks[] = 'fence:' . $transport->name();
                return true;
            },
            static function () use (&$callbacks): string {
                $callbacks[] = 'run-id';
                return DEPLOY_CHECKPOINT_RUN_ID;
            },
            static function (EnvironmentDriver $t, array $begin, string $owner, string $hash) use (&$callbacks): void {
                $callbacks[] = "compensate:$owner:$hash";
            },
            static function (EnvironmentDriver $t, string $owner, string $hash) use (&$callbacks): bool {
                $callbacks[] = "abort:$owner:$hash";
                return true;
            },
            // issue #3525 narrowed this callback to (driver, checkpoint,
            // codeMayHaveChanged). The recovery guidance names one verb —
            // `wprism recover <env> --restore=<id> …`, whose `<id>` is the
            // checkpoint's own basename — so the lease identity is no longer
            // an input to what is PRINTED. `abort` above still receives it,
            // and this suite still asserts that pair on that call.
            static function (
                EnvironmentDriver $t,
                string $checkpoint,
                bool $codeMayHaveChanged
            ) use (&$callbacks): void {
                $callbacks[] = 'recovery:' . $checkpoint . ':' . ($codeMayHaveChanged ? 'code' : 'nocode');
            }
        );
    } finally {
        $stdout = (string) ob_get_clean();
    }

    return ['exit' => $exit, 'callbacks' => $callbacks, 'stdout' => $stdout];
}

$checkpointPath = DEPLOY_CHECKPOINT_REPO . '/.wprism/checkpoints/deploy-' . DEPLOY_CHECKPOINT_RUN_ID . '.sql.enc';
$artifactPath = DEPLOY_CHECKPOINT_REPO . '/.wprism/artifacts/deploy-' . DEPLOY_CHECKPOINT_RUN_ID . '.json';

// ------------------------------------------------------------------ (1) where
// The whole event list is asserted, not just the presence of an export: an
// export moved before promotion-begin or after code-stage fails here.
$happy = new DeployCheckpointDriver();
$happyResult = run_deploy_checkpoint($happy, ['--force-code-mismatch']);
wprism_check_same(0, $happyResult['exit'], 'a default code-enabled deploy succeeds with its checkpoint');
wprism_check_same(
    [
        'raw:mkdir', 'capture:compile', 'capture:code-preflight', 'capture:promotion-begin',
        'capture:checkpoint-target', 'capture:db-export',
        'stream:code-stage', 'stream:deploy:retire', 'stream:deploy:activate',
        'stream:lifecycle-settle', 'stream:code-finalize',
    ],
    $happy->events,
    'the checkpoint sits under the lease: after promotion-begin, before code-stage'
);
$export = null;
foreach ($happy->calls as $call) {
    if (in_array('db', $call, true) && in_array('export', $call, true)) {
        $export = $call;
    }
}
wprism_check_same(['db', 'export', '-'], array_slice((array) $export, -3), 'the database export has no durable plaintext output path');
wprism_check(
    is_array($export)
        && count(array_filter(
            $export,
            static fn(string $arg): bool => str_contains($arg, 'DatabaseTargetIdentity::fromWordPressConfig')
                && str_contains($arg, 'require_recovery_intent')
        )) === 1,
    'the export process rechecks its preflight database target without requiring recovery debt'
);
$seal = null;
foreach ($happy->calls as $call) {
    if (in_array('checkpoint-seal', $call, true)) {
        $seal = $call;
    }
}
wprism_check(
    is_array($seal)
        && in_array('--output=' . $checkpointPath, $seal, true)
        && in_array('--database-target-sha256=' . str_repeat('d', 64), $seal, true),
    'the export stream terminates at the authenticated checkpoint sealer'
);

// ------------------------------------------------------------------- (2) what
wprism_check_same(
    'deploy-' . DEPLOY_CHECKPOINT_RUN_ID,
    basename($checkpointPath, '.sql.enc'),
    'the checkpoint basename is deploy-<runId>'
);
wprism_check_same(
    basename($artifactPath, '.json'),
    basename($checkpointPath, '.sql.enc'),
    'checkpoint and artifact share a stem, which is what the identity grep needs'
);
wprism_check(
    str_contains($happy->rawScripts[0], escapeshellarg(dirname($checkpointPath)))
        && str_contains($happy->rawScripts[0], escapeshellarg(dirname($artifactPath))),
    'one mkdir creates both directories, so the export cannot fail for a reason that is not the database'
);
wprism_check_same(1, count($happy->rawScripts), 'still exactly one raw mkdir event');
wprism_check(
    str_contains($happyResult['stdout'], "deploy phase: checkpoint\n"),
    'the checkpoint phase announces itself in promote\'s words'
);
wprism_check(
    str_contains($happyResult['stdout'], "database checkpoint: $checkpointPath\n"),
    'the checkpoint path is printed when it is taken'
);
wprism_check(
    str_contains(
        $happyResult['stdout'],
        "deploy complete: code-stage -> lifecycle-retire -> lifecycle-activate -> lifecycle-settle -> code-finalize\n"
            . "database checkpoint retained: $checkpointPath\n"
    ),
    'the completion line is unchanged and the retained line follows it, as promote does (cli/wprism:2465)'
);

// A code-only deploy with no descriptor has nothing to mutate. In particular,
// it must not manufacture lifecycle side effects (agency audit #77), so it
// exits before a lease/checkpoint as a disclosed hook-free no-op.
$legacy = new DeployCheckpointDriver();
$legacy->codeEnabled = false;
$legacyResult = run_deploy_checkpoint($legacy, []);
wprism_check_same(0, $legacyResult['exit'], 'a descriptor-free deploy succeeds as a no-op');
wprism_check_same(
    ['raw:mkdir', 'capture:compile', 'capture:lifecycle-status'],
    $legacy->events,
    'a deploy with no code descriptor takes no lease/checkpoint and invokes no lifecycle phase'
);
wprism_check(
    str_contains(
        $legacyResult['stdout'],
        "deploy complete: no code descriptor; lifecycle hooks not run\n"
    ),
    'the no-op completion line explicitly discloses that lifecycle hooks did not run'
);

// ----------------------------------------------------- a failed export aborts
$failedExport = new DeployCheckpointDriver();
$failedExport->exportExit = 23;
$failedExportResult = run_deploy_checkpoint($failedExport, []);
wprism_check_same(23, $failedExportResult['exit'], 'a non-zero export exit propagates unchanged');
wprism_check_same(
    [
        'raw:mkdir', 'capture:compile', 'capture:code-preflight', 'capture:promotion-begin',
        'capture:checkpoint-target', 'capture:db-export',
    ],
    $failedExport->events,
    'a failed export starts no code or lifecycle phase'
);
wprism_check_same(
    ['scope', 'fence:checkpoint-fixture', 'run-id',
        'abort:' . DEPLOY_CHECKPOINT_RUN_ID . ':' . DEPLOY_CHECKPOINT_HASH],
    $failedExportResult['callbacks'],
    'a failed export aborts the exact lease it took, and prints no recovery guidance for a dump that does not exist'
);

// ------------------------------------ a post-checkpoint failure guides recovery
$stageFail = new DeployCheckpointDriver();
$stageFail->stageExit = 8;
$stageFailResult = run_deploy_checkpoint($stageFail, []);
wprism_check_same(8, $stageFailResult['exit'], 'a code-stage exit propagates unchanged');
wprism_check_same(
    ['scope', 'fence:checkpoint-fixture', 'run-id',
        'abort:' . DEPLOY_CHECKPOINT_RUN_ID . ':' . DEPLOY_CHECKPOINT_HASH,
        'recovery:' . $checkpointPath . ':code'],
    $stageFailResult['callbacks'],
    'a post-checkpoint stage failure guides recovery of THIS checkpoint, after aborting THIS lease pair'
);

// ------------------------------------------------------------- --no-checkpoint
$optOut = new DeployCheckpointDriver();
$optOutResult = run_deploy_checkpoint($optOut, ['--no-checkpoint']);
wprism_check_same(0, $optOutResult['exit'], '--no-checkpoint deploys successfully');
wprism_check_same(
    [
        'raw:mkdir', 'capture:compile', 'capture:code-preflight', 'capture:promotion-begin',
        'stream:code-stage', 'stream:deploy:retire', 'stream:deploy:activate',
        'stream:lifecycle-settle', 'stream:code-finalize',
    ],
    $optOut->events,
    '--no-checkpoint reproduces the pre-change wp-call sequence exactly'
);
wprism_check_same(
    "deploy phase: compile\ndeploy phase: code-preflight\ndeploy phase: promotion-begin\n"
        . "deploy phase: code-stage\ndeploy phase: lifecycle-retire\ndeploy phase: lifecycle-activate\n"
        . "deploy phase: lifecycle-settle\ndeploy phase: code-finalize\n"
        . "deploy complete: code-stage -> lifecycle-retire -> lifecycle-activate -> lifecycle-settle -> code-finalize\n",
    $optOutResult['stdout'],
    '--no-checkpoint reproduces the pre-change stdout byte for byte'
);
wprism_check(
    !str_contains($optOut->rawScripts[0], '/checkpoints'),
    '--no-checkpoint does not even create the checkpoint directory'
);
$optOutStage = new DeployCheckpointDriver();
$optOutStage->stageExit = 8;
$optOutStageResult = run_deploy_checkpoint($optOutStage, ['--no-checkpoint']);
wprism_check_same(
    ['scope', 'fence:checkpoint-fixture', 'run-id',
        'abort:' . DEPLOY_CHECKPOINT_RUN_ID . ':' . DEPLOY_CHECKPOINT_HASH],
    $optOutStageResult['callbacks'],
    'under --no-checkpoint a phase failure prints no recovery guidance for a checkpoint that was never taken'
);
wprism_check_same(
    "deploy phase: compile\ndeploy phase: code-preflight\ndeploy phase: promotion-begin\n"
        . "deploy phase: code-stage\n",
    $optOutStageResult['stdout'],
    '--no-checkpoint reproduces the pre-change failure stdout byte for byte'
);

// The flag gate itself. forceFlags() has one caller, so it is the single gate.
wprism_check_same(true, DeployCommand::checkpointRequested([]), 'the checkpoint is the default');
wprism_check_same(
    false,
    DeployCommand::checkpointRequested(['--force-code-drift', '--no-checkpoint']),
    'an exact --no-checkpoint opts out'
);
wprism_check_same(
    [],
    DeployCommand::forceFlags(['--no-checkpoint'], 'deploy'),
    '--no-checkpoint is consumed by the host and never forwarded to a lifecycle phase'
);
wprism_check_throws(
    static fn () => DeployCommand::forceFlags(['--no-checkpoint=false'], 'deploy'),
    \RuntimeException::class,
    'a --no-checkpoint=value spelling is refused rather than reinterpreted',
    'only --force-code-mismatch, --force-code-drift and --no-checkpoint are accepted'
);

// ---------------------------------------------------------- (3) the round trip
// The one assertion that fails against the prior defect through the product
// path: feed the basename DeployCommand just wrote back through the catalog and
// require the rebuilt path to be the written one. On the prior build parse()
// throws checkpoint_listing_malformed here.
$listing = basename($checkpointPath, '.sql.enc') . "\t" . DEPLOY_CHECKPOINT_HASH . "\t1786961410\n";
$rows = RetainedCheckpoints::parse($listing, '2026-08-17T10:20:10Z');
wprism_check_same(1, count($rows), 'a deploy checkpoint line becomes one catalog row');
wprism_check_same('deploy-' . DEPLOY_CHECKPOINT_RUN_ID, $rows[0]['id'], 'the row id is the deploy file name');
wprism_check_same(DEPLOY_CHECKPOINT_RUN_ID, $rows[0]['owner'], 'the owner is the lease owner deploy used');
wprism_check_same(
    DEPLOY_CHECKPOINT_HASH,
    $rows[0]['artifact_hash'],
    'the artifact hash comes from the sibling deploy-<runId>.json, so the restore can name the lease'
);
wprism_check_same(
    RetainedCheckpoints::DEPLOY_ID_PREFIX,
    RetainedCheckpoints::prefixForRow($rows[0]),
    'prefixForRow reads the deploy prefix back off the row id'
);
wprism_check_same(
    $checkpointPath,
    RetainedCheckpoints::checkpointPath(
        DEPLOY_CHECKPOINT_REPO,
        $rows[0],
        RetainedCheckpoints::prefixForRow($rows[0])
    ),
    'the catalog rebuilds exactly the path DeployCommand wrote'
);

// Both halves of prefixForRow()'s condition are load-bearing: a signed receipt
// row's id is a receipt id, not a file name, so only the retained kind may let
// a `deploy-` id choose deploy's prefix.
wprism_check_same(
    RetainedCheckpoints::ID_PREFIX,
    RetainedCheckpoints::prefixForRow(['kind' => 'rollback-receipt', 'id' => 'deploy-looking-receipt']),
    'a signed receipt row keeps promote\'s prefix even when its id starts with deploy-'
);
wprism_check_same(
    RetainedCheckpoints::ID_PREFIX,
    RetainedCheckpoints::prefixForRow(['kind' => RetainedCheckpoints::KIND, 'id' => 'promote-owner']),
    'a retained promote row keeps promote\'s prefix'
);

// ------------------------------------------------- the glob stays a closed set
$script = RetainedCheckpoints::script(DEPLOY_CHECKPOINT_REPO);
wprism_check(
    str_contains($script, 'checkpoints/promote-*.sql.enc')
        && str_contains($script, 'checkpoints/deploy-*.sql.enc'),
    'the listing script asks the target for both prefixes'
);
wprism_check(
    !preg_match('~checkpoints/\*\.sql\.enc~', $script),
    'no bare *.sql.enc glob: materialize-<operation_id>.sql.enc stays outside this catalog'
);
wprism_check_refuses(
    static fn () => RetainedCheckpoints::parse("materialize-abc\t\t1\n", '2026-08-17T10:20:10Z'),
    'checkpoint_listing_malformed',
    'a name carrying neither prefix refuses loudly rather than being dropped from the inventory'
);

wprism_check_summary('deploy checkpoint');

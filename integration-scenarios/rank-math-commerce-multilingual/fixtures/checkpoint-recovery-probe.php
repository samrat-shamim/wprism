<?php
declare(strict_types=1);

/** Native boundary for the scenario shell probe; never a live recovery certificate. */
$root = dirname(__DIR__, 3);
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
require_once $root . '/agent/src/Repository/Ledger.php';
require_once $root . '/agent/src/Recovery/DatabaseTargetIdentity.php';
require_once $root . '/recovery/CanonicalJson.php';
require_once $root . '/recovery/AtomicStore.php';
require_once $root . '/recovery/ProtocolLock.php';
require_once $root . '/recovery/ProviderSettlementIntent.php';
require_once $root . '/recovery/CheckpointRecoveryIntent.php';

use WPrism\Recovery\CheckpointRecoveryIntent;
use WPrism\Recovery\ProviderSettlementIntent;
use WPrismTest\FakeWpdb;

[$script, $verb, $target, $fault] = $argv;
$target = (string) realpath($target);
$control = $target . '/.wprism/control';
$checkpoint = $target . '/.wprism/checkpoints/deploy-recover-fixture-owner.sql.enc';
$artifact = $target . '/.wprism/artifacts/deploy-recover-fixture-owner.json';
$owner = 'recover-fixture-owner';
$hash = str_repeat('a1', 32);
$cipher = (string) hash_file('sha256', $checkpoint);

if ($verb === 'begin') {
    ProviderSettlementIntent::begin($control, $target, $artifact, $checkpoint, $cipher,
        $owner, $hash, ['lifecycle-retire', 'lifecycle-activate', 'schema-settle', 'lifecycle-settle']);
    foreach (['lifecycle-retire', 'lifecycle-activate'] as $phase) {
        ProviderSettlementIntent::advance($control, $target, $artifact, $checkpoint, $owner, $hash,
            ['lifecycle-retire', 'lifecycle-activate', 'schema-settle', 'lifecycle-settle'], $phase);
    }
    echo json_encode(ProviderSettlementIntent::recoveryStatus($control), JSON_THROW_ON_ERROR);
} elseif ($verb === 'complete') {
    // The shared make-recover-site fixture simulates WP/import. Preserve that
    // boundary while using the real durable owner to model its completion;
    // only the separately allocated live run proves encrypted DB restoration.
    CheckpointRecoveryIntent::begin($control, $target, $checkpoint, $cipher, $owner, $hash,
        CheckpointRecoveryIntent::databaseTargetHash('fixture', 'fixture', 'wp_'), 'single-site', null);
    if ($fault !== 'retained-debt') {
        CheckpointRecoveryIntent::complete($control, $target, $checkpoint, $owner, $hash);
    }
} elseif ($verb === 'observe') {
    $wpdb = FakeWpdb::install();
    $wpdb->seedTable('wp_wprism_kv', $fault === 'schema-debt'
        ? [['k' => 'schema_settlement_in_progress', 'v' => 'retained']] : []);
    if ($fault === 'control-read-error') $wpdb->failNextQuery('fixture read failure', 'SELECT k, v');
    // A local offline target and /siterepo are the two physical bindings of
    // the same native invocation. Execute its actual PHP, not a canned status.
    $program = str_replace('/siterepo/.wprism/control/recovery-runtime/', $root . '/recovery/', $argv[4]);
    $program = str_replace('/siterepo/.wprism/control', $control, $program);
    ob_start();
    try {
        eval($program);
        $answer = (string) ob_get_clean();
    } catch (Throwable $failure) {
        ob_end_clean();
        fwrite(STDERR, "fixture control observation failed\n");
        exit(7);
    }
    echo str_replace($target . '/', '/siterepo/', $answer);
} else {
    throw new LogicException('unknown checkpoint recovery probe action');
}

<?php
/** Controlled isolation is a separate fact from physical-session continuity. */
declare(strict_types=1);

$runtimeRoot = realpath($argv[1] ?? dirname(__DIR__, 4));
if (!is_string($runtimeRoot) || !is_file($runtimeRoot . '/agent/src/Kernel/Db.php')) {
    throw new RuntimeException('repeatable-read fixture needs one complete runtime tree');
}
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once $runtimeRoot . '/agent/src/Kernel/Db.php';

use WPrism\DatabaseMutationException;
use WPrism\DatabaseTransactionOutcomeException;
use WPrism\Db;
use WPrism\NativeDatabaseProfile;
use WPrismTest\FakeWpdb;

wprism_check(method_exists(Db::class, 'repeatable_read_authority'),
    'Db exposes controlled isolation separately from ordinary transaction continuity');
if (!method_exists(Db::class, 'repeatable_read_authority')) {
    wprism_check_summary('database repeatable-read authority');
}

function rr_fixture(): FakeWpdb {
    Db::forget_transaction_tracking();
    return FakeWpdb::install()
        ->seedTable('wp_wprism_kv', [])
        ->setTableEngine('wp_wprism_kv', 'InnoDB')
        ->setUniqueKey('wp_wprism_kv', ['k'])
        ->enableInformationSchema();
}

/** @param callable():mixed $operation */
function rr_failure(callable $operation): ?Throwable {
    try {
        $operation();
    } catch (Throwable $failure) {
        return $failure;
    }
    return null;
}

function rr_refused(string $label): void {
    $failure = rr_failure(static fn() => Db::repeatable_read_authority($label));
    wprism_check($failure instanceof DatabaseTransactionOutcomeException,
        "$label cannot grant controlled repeatable-read authority");
}

rr_fixture();
rr_refused('idle connection');

foreach (['READ-COMMITTED', 'READ-UNCOMMITTED', 'REPEATABLE-READ', 'SERIALIZABLE'] as $default) {
    $wpdb = rr_fixture()->setTransactionIsolation($default);
    Db::start('uncontrolled default', new NativeDatabaseProfile([]));
    wprism_check_same($default, $wpdb->activeTransactionIsolation(),
        "plain START retains its $default session default");
    rr_refused("uncontrolled $default default");
    Db::rollback('uncontrolled default rollback');
}

foreach (['start_repeatable_read', 'start_consistent_snapshot', 'start_read_only_consistent_snapshot'] as $entry) {
    foreach (['commit', 'rollback'] as $terminal) {
        $wpdb = rr_fixture()->setTransactionIsolation('READ-COMMITTED')
            ->setNextTransactionIsolation('SERIALIZABLE');
        Db::$entry("$entry controlled start", new NativeDatabaseProfile([]));
        $authority = Db::repeatable_read_authority("$entry live witness");
        wprism_check($authority->equals(Db::transaction_authority("$entry same session")),
            "$entry returns the original transaction's exact authority");
        wprism_check_same('REPEATABLE-READ', $wpdb->activeTransactionIsolation(),
            "$entry overrides both the session default and a prior one-shot setting");
        wprism_check_same(1, count(array_filter($wpdb->queries(),
            static fn(string $sql): bool => $sql === 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')),
            "$entry positively controls isolation exactly once");
        wprism_check_same([], $wpdb->rows('wp_wprism_kv'),
            "$entry does not write product data to establish isolation");
        Db::$terminal("$entry $terminal");
        rr_refused("$entry confirmed $terminal");
        Db::start('subsequent plain transaction', new NativeDatabaseProfile([]));
        rr_refused("$entry witness cannot leak into a later plain transaction");
        Db::rollback('subsequent plain rollback');
    }
}

foreach (['before_false', 'before_throw', 'after_false', 'after_throw', 'after_reconnect_same_id_replay'] as $outcome) {
    $wpdb = rr_fixture()->injectTransactionOutcome('SET TRANSACTION', $outcome);
    $failure = rr_failure(static fn() => Db::start_repeatable_read(
        "SET $outcome", new NativeDatabaseProfile([])
    ));
    $expectedFailure = $outcome === 'after_reconnect_same_id_replay'
        ? DatabaseTransactionOutcomeException::class : DatabaseMutationException::class;
    wprism_check($failure instanceof $expectedFailure,
        "SET $outcome refuses its unconfirmed isolation control");
    wprism_check_same(null, $wpdb->activeTransactionIsolation(),
        "SET $outcome settles any data-free cleanup transaction");
    wprism_check_same(null, $wpdb->transactionIsolationState()['next'],
        "SET $outcome retains no unconsumed one-shot setting");
    rr_refused("SET $outcome cleanup");
    Db::start('post-SET plain transaction', new NativeDatabaseProfile([]));
    rr_refused("SET $outcome cannot authorize subsequent plain START");
    Db::rollback('post-SET rollback');
}

foreach (['before_false', 'before_throw', 'success_no_apply', 'after_reconnect_same_id_replay'] as $outcome) {
    $wpdb = rr_fixture()->injectTransactionOutcome('START', $outcome);
    $failure = rr_failure(static fn() => Db::start_repeatable_read(
        "START $outcome", new NativeDatabaseProfile([])
    ));
    $expectedFailure = $outcome === 'after_reconnect_same_id_replay'
        ? DatabaseTransactionOutcomeException::class : DatabaseMutationException::class;
    wprism_check($failure instanceof $expectedFailure,
        "START $outcome refuses an unproved original transaction");
    wprism_check_same(null, $wpdb->activeTransactionIsolation(),
        "START $outcome settles without a product transaction");
    rr_refused("START $outcome cleanup");
    Db::start('post-START plain transaction', new NativeDatabaseProfile([]));
    rr_refused("START $outcome cleanup cannot grant isolation authority");
    Db::rollback('post-START rollback');
}

// START's response is not its outcome: unlike SET's one-shot state, the
// exact active transaction can be positively witnessed after response loss.
foreach (['after_false', 'after_throw'] as $outcome) {
    $wpdb = rr_fixture()->injectTransactionOutcome('START', $outcome);
    Db::start_repeatable_read("START $outcome", new NativeDatabaseProfile([]));
    $authority = Db::repeatable_read_authority("START $outcome exact postimage");
    wprism_check($authority->equals(Db::transaction_authority('surviving original transaction')),
        "START $outcome has controlled authority only after its original active postimage is proven");
    Db::rollback("START $outcome rollback");
    rr_refused("START $outcome settled rollback");
}

$wpdb = rr_fixture()->setTableEngine('wp_wprism_kv', 'MyISAM');
$failure = rr_failure(static fn() => Db::start_repeatable_read(
    'rejected physical profile', NativeDatabaseProfile::read_only(['wp_wprism_kv'])
));
wprism_check($failure instanceof Throwable, 'a nontransactional read profile still refuses');
wprism_check_same(null, $wpdb->activeTransactionIsolation(), 'failed profile admission rolls back its controlled START');
rr_refused('failed physical profile admission');

foreach (['new-id', 'same-id', 'implicit-commit', 'replacement-transaction'] as $loss) {
    $wpdb = rr_fixture();
    Db::start_repeatable_read("$loss start", new NativeDatabaseProfile([]));
    $authority = Db::repeatable_read_authority("$loss entry witness");
    if ($loss === 'new-id' || $loss === 'same-id') {
        $wpdb->setConnectionId((int) $authority->connection_id() + ($loss === 'new-id' ? 1 : 0));
    } else {
        $wpdb->simulateImplicitCommit();
        if ($loss === 'replacement-transaction') {
            $wpdb->simulateExternalTransactionControl('START TRANSACTION');
        }
    }
    rr_refused("$loss continuity break");
    if ($loss === 'replacement-transaction') {
        $wpdb->simulateExternalTransactionControl('ROLLBACK AND NO CHAIN NO RELEASE');
    }
    Db::connection_transaction_active("$loss explicit idle settlement");
    Db::forget_transaction_tracking();
    rr_refused("$loss settled tracking");
}

foreach (['after_false', 'after_throw'] as $outcome) {
    $wpdb = rr_fixture();
    Db::start_repeatable_read("COMMIT $outcome start", new NativeDatabaseProfile([], ['wp_wprism_kv']));
    Db::insert('wp_wprism_kv', ['k' => 'receipt', 'v' => 'committed'], null, 'test receipt');
    $wpdb->injectTransactionOutcome('COMMIT', $outcome);
    $failure = rr_failure(static fn() => Db::commit("COMMIT $outcome"));
    wprism_check($failure instanceof DatabaseTransactionOutcomeException,
        "COMMIT $outcome keeps terminal uncertainty explicit");
    rr_refused("COMMIT $outcome uncertainty");
    wprism_check_same([['k' => 'receipt', 'v' => 'committed']], $wpdb->rows('wp_wprism_kv'),
        "COMMIT $outcome witness refusal does not compensate an already committed row");
    $restart = rr_failure(static fn() => Db::start_repeatable_read(
        'forbidden uncertain restart', new NativeDatabaseProfile([])
    ));
    wprism_check($restart instanceof DatabaseTransactionOutcomeException,
        "COMMIT $outcome cannot bury uncertainty behind a fresh controlled START");
    Db::connection_transaction_active('test-owned committed postimage idle settlement');
    Db::forget_transaction_tracking();
    rr_refused("COMMIT $outcome explicit forget");
}

foreach (['SAVEPOINT', 'ROLLBACK TO SAVEPOINT', 'RELEASE SAVEPOINT'] as $control) {
    $wpdb = rr_fixture();
    Db::start_repeatable_read("$control response-loss start", new NativeDatabaseProfile([]));
    $wpdb->injectTransactionOutcome($control, 'after_false');
    rr_refused("$control uncertain witness rotation");
    rr_refused("$control cleanup-only transaction");
    Db::rollback("$control rollback-only settlement");
    rr_refused("$control completed cleanup");
}

wprism_check_summary('database repeatable-read authority');

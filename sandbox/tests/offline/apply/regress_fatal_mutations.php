<?php
/**
 * Offline regression for issue #3206's checked mutation primitive. The live
 * companion shell test exercises Apply/Ledger ordering; this file pins the
 * wpdb return-value semantics that made the original defect possible.
 */

require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require_once __DIR__ . '/../../../../cli/src/Plan/PlanSummary.php';

use WPrism\DatabaseMutationException;
use WPrism\Db;
use WPrism\NativeDatabaseProfile;
use WPrism\TransientDbException;
use WPrism\Orchestrator\PlanSummary;
use WPrismTest\FakeWpdb;

function fatal_mutation_db(array $rows = []): FakeWpdb {
    Db::forget_transaction_tracking();
    return FakeWpdb::install()
        ->seedTable('wp_posts', $rows)
        ->setColumns('wp_posts', [
            'ID' => 'bigint(20) unsigned',
            'post_title' => 'text',
        ])
        ->setTableEngine('wp_posts', 'InnoDB')
        ->enableInformationSchema();
}

$wpdb = fatal_mutation_db();
$failures = [];

$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$expectFailure = static function (callable $fn, string $context) use ($check): void {
    try {
        $fn();
        $check(false, "$context did not throw");
    } catch (DatabaseMutationException $e) {
        $check($e->mutationContext === $context, "$context lost typed context");
        $check(!str_contains($e->getMessage(), 'sk_live_must_not_leak'), "$context leaked wpdb last_error data");
    } catch (Throwable $e) {
        $check(false, "$context threw " . get_class($e) . ' instead of DatabaseMutationException');
    }
};

$expectTransient = static function (callable $fn, string $context) use ($check): void {
    try {
        $fn();
        $check(false, "$context did not throw");
    } catch (TransientDbException $e) {
        $check(str_contains($e->getMessage(), $context), "$context lost operation context");
        $check(!str_contains($e->getMessage(), 'sk_live_must_not_leak'), "$context leaked wpdb last_error data");
    } catch (Throwable $e) {
        $check(false, "$context threw " . get_class($e) . ' instead of TransientDbException');
    }
};

$wpdb->failNextQuery("Duplicate entry 'sk_live_must_not_leak'", 'INSERT INTO `wp_posts`');
$expectFailure(
    fn() => Db::insert('wp_posts', ['post_title' => 'new'], ['%s'], 'test insert'),
    'test insert'
);
$wpdb = fatal_mutation_db([['ID' => 1, 'post_title' => 'old']]);
$wpdb->failNextQuery("Duplicate entry 'sk_live_must_not_leak'", 'UPDATE `wp_posts`');
$expectFailure(
    fn() => Db::update(
        'wp_posts',
        ['post_title' => 'new'],
        ['ID' => 1],
        ['%s'],
        ['%d'],
        'test update'
    ),
    'test update'
);
$wpdb = fatal_mutation_db([['ID' => 1, 'post_title' => 'old']]);
$wpdb->failNextQuery("Duplicate entry 'sk_live_must_not_leak'", 'DELETE FROM `wp_posts`');
$expectFailure(
    fn() => Db::delete('wp_posts', ['ID' => 1], ['%d'], 'test delete'),
    'test delete'
);
$check(!method_exists(Db::class, 'query'), 'Db still exposes an unaudited raw-query mutation escape');

// issue #3213 capture retries only deadlocks/timeouts. The central checked
// layer must preserve that typed distinction without exposing driver text.
$wpdb = fatal_mutation_db();
$wpdb->simulateDeadlock('INSERT INTO `wp_posts`');
$expectTransient(
    fn() => Db::insert('wp_posts', ['post_title' => 'capture'], ['%s'], 'capture identity insert'),
    'capture identity insert'
);

// wpdb returns 0 for a successful UPDATE/DELETE that matched no changed rows.
$wpdb = fatal_mutation_db([['ID' => 1, 'post_title' => 'old']]);
$check(
    Db::update(
        'wp_posts',
        ['post_title' => 'new'],
        ['ID' => 999],
        ['%s'],
        ['%d'],
        'zero-row update'
    ) === 0,
    'zero-row update was treated as failure'
);
$check(
    Db::delete('wp_posts', ['ID' => 999], ['%d'], 'zero-row delete') === 0,
    'zero-row delete was treated as failure'
);

// A nominally successful insert with no generated id is still unusable for
// WPrism identity and must fail before local id zero can enter the ledger.
$wpdb->insert_id = 0;
$expectFailure(fn() => Db::insert_id('test insert id'), 'test insert id did not produce an id');

putenv('WPRISM_TEST_MODE=1');
putenv('WPRISM_TEST_FAIL_DB_CONTEXT=injected update,injected rollback');
$expectFailure(
    fn() => Db::update(
        'wp_options',
        ['option_value' => 'new'],
        ['option_name' => 'test'],
        ['%s'],
        ['%s'],
        'injected update'
    ),
    'injected update (injected)'
);
$wpdb = fatal_mutation_db();
Db::start('injected rollback transaction', new NativeDatabaseProfile([]));
$expectFailure(fn() => Db::rollback('injected rollback'), 'injected rollback (injected)');
putenv('WPRISM_TEST_MODE');
putenv('WPRISM_TEST_FAIL_DB_CONTEXT');
Db::rollback('injected rollback cleanup');

$emptyPlan = array_fill_keys(
    ['create', 'update', 'adopt', 'unchanged', 'drift', 'conflict', 'collision', 'delete'],
    []
);
$emptyPlan['code_mismatch'] = [];
$emptyPlan['warnings'] = ['previous apply did not complete required rebuilds; canonical entities require retry'];
$emptyPlan['incomplete_apply'] = [['reason' => 'retry required']];
$status = PlanSummary::render($emptyPlan);
$check($status['ok'] === false, 'incomplete apply marker did not make status non-zero');
$check(
    count(array_filter($status['lines'], fn(string $line): bool => str_contains($line, 'INCOMPLETE_APPLY'))) === 1,
    'status did not render the incomplete apply condition'
);

$widgetPlan = $emptyPlan;
$widgetPlan['incomplete_apply'] = [];
$widgetPlan['warnings'] = [];
$widgetPlan['update'] = [[
    'uuid' => 'sidebar/sidebar-1', 'type' => 'sidebar', 'path' => 'sidebars/sidebar-1.json',
    'widget_deletes' => [[
        'uuid' => '00000000-0000-4000-8000-000000000001',
        'type' => 'block', 'unmanaged' => true,
    ]],
]];
$widgetStatus = PlanSummary::render($widgetPlan);
$check(
    count(array_filter($widgetStatus['lines'], fn(string $line): bool => str_contains($line, 'WIDGET_DELETE'))) === 1,
    'status did not render a plan-visible scoped widget deletion'
);

if ($failures) {
    fwrite(STDERR, "FAIL\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "ok: checked mutations are typed/value-redacted, transient contention stays retryable, zero-row writes remain valid, insert id zero is refused, incomplete status is non-zero\n";

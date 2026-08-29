<?php
/**
 * Offline regression for issue #3206's checked mutation primitive. The live
 * companion shell test exercises Apply/Ledger ordering; this file pins the
 * wpdb return-value semantics that made the original defect possible.
 */

require_once __DIR__ . '/../../../../agent/src/Kernel/TransientDbException.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require_once __DIR__ . '/../../../../cli/src/Plan/PlanSummary.php';

use WPrism\DatabaseMutationException;
use WPrism\Db;
use WPrism\TransientDbException;
use WPrism\Orchestrator\PlanSummary;

final class FakeWpdb {
    public string $prefix = 'wp_';
    public string $last_error = "Duplicate entry 'sk_live_must_not_leak'";
    public int $insert_id = 1;
    public $next = 1;

    public function query($sql) { return $this->next; }
    public function insert($table, $data, $format = null) { return $this->next; }
    public function update($table, $data, $where, $format = null, $whereFormat = null) { return $this->next; }
    public function delete($table, $where, $whereFormat = null) { return $this->next; }
}

$wpdb = new FakeWpdb();
$GLOBALS['wpdb'] = $wpdb;
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

$wpdb->next = false;
$expectFailure(fn() => Db::insert('wp_posts', [], null, 'test insert'), 'test insert');
$expectFailure(fn() => Db::update('wp_posts', [], [], null, null, 'test update'), 'test update');
$expectFailure(fn() => Db::delete('wp_posts', [], null, 'test delete'), 'test delete');
$expectFailure(fn() => Db::query('COMMIT', 'test commit'), 'test commit');

// issue #3213 capture retries only deadlocks/timeouts. The central checked
// layer must preserve that typed distinction without exposing driver text.
$wpdb->last_error = "Deadlock found while handling sk_live_must_not_leak";
$expectTransient(fn() => Db::insert('wp_posts', [], null, 'capture identity insert'), 'capture identity insert');
$wpdb->last_error = "Duplicate entry 'sk_live_must_not_leak'";

// wpdb returns 0 for a successful UPDATE/DELETE that matched no changed rows.
$wpdb->next = 0;
$check(Db::update('wp_posts', [], [], null, null, 'zero-row update') === 0, 'zero-row update was treated as failure');
$check(Db::delete('wp_posts', [], null, 'zero-row delete') === 0, 'zero-row delete was treated as failure');

// A nominally successful insert with no generated id is still unusable for
// WPrism identity and must fail before local id zero can enter the ledger.
$wpdb->next = 1;
$wpdb->insert_id = 0;
$expectFailure(fn() => Db::insert_id('test insert id'), 'test insert id did not produce an id');

putenv('WPRISM_TEST_MODE=1');
putenv('WPRISM_TEST_FAIL_DB_CONTEXT=injected update,injected rollback');
$expectFailure(
    fn() => Db::update('wp_options', [], [], null, null, 'injected update'),
    'injected update (injected)'
);
$expectFailure(fn() => Db::rollback('injected rollback'), 'injected rollback (injected)');
putenv('WPRISM_TEST_MODE');
putenv('WPRISM_TEST_FAIL_DB_CONTEXT');

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

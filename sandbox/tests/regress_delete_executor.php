<?php
/**
 * Offline regression for DeleteExecutor (DUO-3347 slice 12: the executor
 * half of the "DeleteGuardEvaluator/DeleteExecutor" target seam).
 * Deliberately narrow, the same wiring/shape idiom the earlier materializer
 * extractions in this series established: this file does not re-implement
 * or re-assert row-deletion behavior -- doing so from a hand-copied twin of
 * the logic would only add a second copy that could silently drift from the
 * real one. Full behavioral coverage already exists in
 * regress_lifecycle_options_snapshot.php (assign_locations()'s own exact
 * serialized-array merge/deletion semantics, via reflection) and every live
 * conformance manifest sweep's own entity-delete paths (typed-table rows,
 * posts with revisions, terms/menus with cascaded items), unchanged by this
 * extraction; regress_scoped_apply_recovery.php also exercises the menu
 * tombstone cascade specifically.
 *
 * The constructor takes (Policy, RelationshipMaterializer, MenuMaterializer)
 * -- narrower than a mechanical carry-over of delete_entity()'s original
 * body would suggest. $scopeContract was NOT carried over: the only place
 * delete_entity() read it was the trailing REGEN_PENDING_PREFIX marker
 * cleanup, which stayed on Apply's own facade rather than moving here (see
 * DeleteExecutor.php's own docblock) -- reconciliation bookkeeping for a
 * deleted uuid, not "execute this entity's row deletion." Once that one
 * line moved out with it, this class never needed scopeContract as a
 * dependency at all.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../agent/src/Canon.php';
require_once __DIR__ . '/../../agent/src/OptionState.php';
require_once __DIR__ . '/../../agent/src/Ledger.php';
require_once __DIR__ . '/../../agent/src/Policy.php';
require_once __DIR__ . '/../../agent/src/Tokens.php';
require_once __DIR__ . '/../../agent/src/ApplyFieldMaterializer.php';
require_once __DIR__ . '/../../agent/src/RelationshipMaterializer.php';
require_once __DIR__ . '/../../agent/src/MenuMaterializer.php';
require_once __DIR__ . '/../../agent/src/DeleteExecutor.php';

use Duo\ApplyFieldMaterializer;
use Duo\DeleteExecutor;
use Duo\MenuMaterializer;
use Duo\Policy;
use Duo\RelationshipMaterializer;
use Duo\Tokens;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

// newInstanceWithoutConstructor(): this is a wiring/shape test, not a
// behavioral one (see the file docblock) -- a real Policy/Tokens each need
// a live compiled manifest set or WordPress runtime to construct, which
// this offline suite deliberately does not stand up.
$policy = (new ReflectionClass(Policy::class))->newInstanceWithoutConstructor();
$tokens = (new ReflectionClass(Tokens::class))->newInstanceWithoutConstructor();
$fieldMaterializer = new ApplyFieldMaterializer($policy, $tokens);
$relationshipMaterializer = new RelationshipMaterializer($policy);
$menuMaterializer = new MenuMaterializer($policy, $tokens, $fieldMaterializer);
$deleteExecutor = new DeleteExecutor($policy, $relationshipMaterializer, $menuMaterializer);

$check($deleteExecutor instanceof DeleteExecutor, 'DeleteExecutor is directly constructible with (Policy, RelationshipMaterializer, MenuMaterializer)');
$check((new ReflectionMethod(DeleteExecutor::class, 'delete_entity'))->isPublic(), 'delete_entity() is public on DeleteExecutor');
$check((new ReflectionMethod(DeleteExecutor::class, 'assert_zero'))->isPrivate(), 'assert_zero() stays private -- an internal duplicate, not a shared API');

// The constructor takes exactly these three collaborators, in this order --
// no $scopeContract (see the file docblock for why not) and no Apply
// instance.
$constructorParams = (new ReflectionClass(DeleteExecutor::class))->getConstructor()->getParameters();
$check(
    array_map(static fn(ReflectionParameter $p): string => (string) $p->getType(), $constructorParams) === [
        'Duo\\Policy', 'Duo\\RelationshipMaterializer', 'Duo\\MenuMaterializer',
    ],
    'constructor depends on exactly Policy, RelationshipMaterializer, MenuMaterializer -- no scopeContract, no Apply instance'
);

// $rowTables (Apply::snapshotRowTables()'s roster) and $warnings travel as
// explicit parameters, the latter by reference, matching every prior
// slice's established idiom for shared, memoized/mutable, per-call-computed
// state (OptionsMaterializer's apply_options()/PostMaterializer's
// finalize_post(), etc).
$deleteParams = (new ReflectionMethod(DeleteExecutor::class, 'delete_entity'))->getParameters();
$check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $deleteParams) === ['uuid', 'type', 'rowTables', 'warnings'],
    'delete_entity() keeps its original two parameters and gains the two new explicit ones (rowTables, warnings)'
);
$check(
    $deleteParams[3]->isPassedByReference(),
    'delete_entity()\'s $warnings parameter is by-reference, matching apply_options()\'s existing array-output-parameter idiom'
);

// === Prove the extraction itself. delete_entity() keeps a thin Apply
// facade (its one external caller, run()'s own delete loop, is unchanged).
// assign_locations() ALSO keeps its own thin Apply facade -- not because
// delete_entity() still calls it (it doesn't: DeleteExecutor calls
// $this->menuMaterializer->assign_locations() directly, the same
// "calling back through Apply would be circular" reasoning
// PostMaterializer's finalize_post() already established), but because
// regress_lifecycle_options_snapshot.php invokes Apply::assign_locations()
// via ReflectionMethod for a genuine behavioral test -- a hidden
// reflection-based caller against the OLD class, caught by grepping for it
// specifically before this slice's code was written, not just a bare
// method-name mention. delete_post_relationships()/
// delete_term_relationships() had no such caller and were removed entirely
// -- covered by regress_relationship_materializer.php, not repeated here.
$applySource = file_get_contents(__DIR__ . '/../../agent/src/Apply.php');
$check(
    !preg_match('/private function delete_entity\(string \$uuid, string \$type\): void \{\s*global \$wpdb;\s*if \(isset\(\$this->snapshotRowTables/', $applySource),
    'Apply.php no longer inlines delete_entity()\'s own body (only the facade remains)'
);
$check(
    str_contains($applySource, '$this->delete_executor()->delete_entity($uuid, $type, $this->snapshotRowTables(), $this->warnings);'),
    'Apply::delete_entity() is a thin facade delegating to DeleteExecutor, passing snapshotRowTables()/warnings'
);
$check(
    str_contains($applySource, 'Ledger::kv_delete(self::REGEN_PENDING_PREFIX . $uuid);'),
    'Apply::delete_entity() keeps the trailing REGEN_PENDING_PREFIX marker cleanup itself -- reconciliation bookkeeping, not row-deletion execution'
);
$check(
    !str_contains($applySource, 'private function assert_zero('),
    'Apply.php no longer defines assert_zero() at all (moved to DeleteExecutor, no facade needed -- it had no other caller)'
);
$check(
    str_contains($applySource, 'private function assign_locations(int $menuTermId, array $locations): void {')
        && str_contains($applySource, '$this->menu_materializer()->assign_locations($menuTermId, $locations);'),
    'Apply::assign_locations() is still a thin facade delegating to MenuMaterializer, kept solely for regress_lifecycle_options_snapshot.php\'s reflection-based caller'
);

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "\nall DeleteExecutor checks passed\n";
exit(0);

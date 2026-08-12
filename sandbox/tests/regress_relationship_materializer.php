<?php
/**
 * Offline regression for RelationshipMaterializer (DUO-3347 slice 8: the
 * relationship materializer extracted from Apply.php). Deliberately narrow,
 * the same wiring/shape idiom Menu/UserMeta/Term/Options's own regressions
 * already established: this file does not re-implement or re-assert
 * reconciliation behavior -- doing so from a hand-copied twin of the logic
 * would only add a second copy that could silently drift from the real one.
 * It proves the two things genuinely new here instead: RelationshipMaterializer
 * is a real, directly constructible, standalone public API with a narrower
 * (Policy) contract than its siblings (no Tokens/ApplyFieldMaterializer
 * needed -- verified, not assumed, by grepping the moved bodies), and its
 * one non-narrow dependency -- Apply's own memoized taxes_by_object_type()
 * roster, scoped to one post type -- is genuinely parameterized rather than
 * silently reaching back into Apply. Full behavioral coverage already
 * exists in every live conformance manifest sweep (every post/term
 * relationship write and every entity delete exercises this code),
 * unchanged by this extraction.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../agent/src/Canon.php';
require_once __DIR__ . '/../../agent/src/OptionState.php';
require_once __DIR__ . '/../../agent/src/Policy.php';
require_once __DIR__ . '/../../agent/src/Ledger.php';
require_once __DIR__ . '/../../agent/src/RelationshipMaterializer.php';

use Duo\Policy;
use Duo\RelationshipMaterializer;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

// newInstanceWithoutConstructor(): this is a wiring/shape test, not a
// behavioral one (see the file docblock) -- a real Policy needs a compiled
// manifest set to construct, which this offline suite deliberately does not
// stand up.
$policy = (new ReflectionClass(Policy::class))->newInstanceWithoutConstructor();
$relationshipMaterializer = new RelationshipMaterializer($policy);

$check($relationshipMaterializer instanceof RelationshipMaterializer, 'RelationshipMaterializer is directly constructible with (Policy)');

foreach (['reconcile_relationships', 'delete_post_relationships', 'delete_term_relationships'] as $method) {
    $check((new ReflectionMethod(RelationshipMaterializer::class, $method))->isPublic(), "$method() is public on RelationshipMaterializer");
}
$check((new ReflectionMethod(RelationshipMaterializer::class, 'assert_zero'))->isPrivate(), 'assert_zero() stays private -- an internal duplicate, not a shared API');

// The constructor takes exactly this one collaborator -- a narrower, more
// explicit data contract than Menu/UserMeta/Term/Options's (Policy, Tokens,
// ApplyFieldMaterializer): neither Tokens nor ApplyFieldMaterializer is
// referenced anywhere in the moved bodies (verified by the require-hygiene
// scanner finding no gap against either class).
$constructorParams = (new ReflectionClass(RelationshipMaterializer::class))->getConstructor()->getParameters();
$check(
    array_map(static fn(ReflectionParameter $p): string => (string) $p->getType(), $constructorParams) === ['Duo\\Policy'],
    'constructor depends on exactly Policy -- no Tokens, no ApplyFieldMaterializer, no Apply instance'
);

// The one dependency that does NOT fit that narrow contract -- Apply's own
// memoized, WordPress-registry-reading taxes_by_object_type(), scoped to one
// post type -- was never smuggled in as a second constructor collaborator or
// a hidden Apply back-reference; it travels as an explicit method parameter
// instead, the same pattern TermMaterializer's $termObjectTaxes established.
$check(
    !(new ReflectionClass(RelationshipMaterializer::class))->hasMethod('taxes_by_object_type')
        && !(new ReflectionClass(RelationshipMaterializer::class))->hasMethod('taxes_for_post_type'),
    'RelationshipMaterializer does not carry its own copy of Apply\'s taxes_by_object_type()/taxes_for_post_type() -- it receives the resolved roster as a parameter'
);
$reconcileParams = (new ReflectionMethod(RelationshipMaterializer::class, 'reconcile_relationships'))->getParameters();
$check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $reconcileParams)
        === ['postId', 'postType', 'termsField', 'termOrders', 'taxesForPostType'],
    'reconcile_relationships() keeps its four original parameters and gains the new explicit "taxesForPostType" one'
);

// === Prove the extraction itself: Apply.php no longer inlines these
// bodies, and its remaining call sites are thin facades.
$applySource = file_get_contents(__DIR__ . '/../../agent/src/Apply.php');
$check(
    str_contains($applySource, '$this->relationship_materializer()->reconcile_relationships(')
        && str_contains($applySource, '$this->relationship_materializer()->delete_post_relationships($id, $postType);')
        && str_contains($applySource, '$this->relationship_materializer()->delete_term_relationships($termId);'),
    'Apply\'s three relationship methods are thin facades delegating to RelationshipMaterializer'
);
$check(
    !preg_match('/private function reconcile_relationships\(\s*int \$postId,\s*string \$postType,\s*array \$termsField,\s*array \$termOrders = \[\]\s*\): void \{\s*global \$wpdb;/', $applySource),
    'Apply.php no longer inlines reconcile_relationships()\'s own body (only the facade remains)'
);

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "\nall RelationshipMaterializer checks passed\n";
exit(0);

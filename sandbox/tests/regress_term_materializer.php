<?php
/**
 * Offline regression for TermMaterializer (DUO-3347 slice 6: the term entity
 * materializer extracted from Apply.php). Deliberately narrow: unlike
 * MenuMaterializer/UserMetaMaterializer, term finalization has no single
 * dedicated live/behavioral test file to point to -- terms (categories,
 * tags, every custom taxonomy) are core WordPress content exercised broadly
 * by essentially every conformance manifest sweep (core, woocommerce,
 * polylang, elementor, ...), not one narrow scenario. This file does not
 * re-implement or re-assert reconciliation behavior -- doing so from a
 * hand-copied twin of the logic would only add a second copy that could
 * silently drift from the real one, the anti-pattern regress_conflict_view.php
 * was fixed to stop doing earlier in this same decomposition effort. It
 * proves the one thing genuinely new here instead: TermMaterializer is a
 * real, directly constructible, standalone public API, and its one
 * non-narrow dependency (the policy-scoped term-object taxonomy roster) is
 * genuinely parameterized rather than silently reaching back into Apply.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../agent/src/Canon.php';
require_once __DIR__ . '/../../agent/src/OptionState.php';
require_once __DIR__ . '/../../agent/src/Policy.php';
require_once __DIR__ . '/../../agent/src/Ledger.php';
require_once __DIR__ . '/../../agent/src/Tokens.php';
require_once __DIR__ . '/../../agent/src/ApplyFieldMaterializer.php';
require_once __DIR__ . '/../../agent/src/TermMaterializer.php';

use Duo\ApplyFieldMaterializer;
use Duo\Policy;
use Duo\Tokens;
use Duo\TermMaterializer;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

// newInstanceWithoutConstructor(): this is a wiring/shape test, not a
// behavioral one (see the file docblock) -- a real Tokens needs a live
// WordPress runtime (untrailingslashit(), site options) to construct, which
// this offline suite deliberately does not stand up.
$policy = (new ReflectionClass(Policy::class))->newInstanceWithoutConstructor();
$tokens = (new ReflectionClass(Tokens::class))->newInstanceWithoutConstructor();
$fieldMaterializer = new ApplyFieldMaterializer($policy, $tokens);
$termMaterializer = new TermMaterializer($policy, $tokens, $fieldMaterializer);

$check($termMaterializer instanceof TermMaterializer, 'TermMaterializer is directly constructible with (Policy, Tokens, ApplyFieldMaterializer)');

foreach (['finalize_term', 'encode_description', 'reconcile_term_relationships'] as $method) {
    $check((new ReflectionMethod(TermMaterializer::class, $method))->isPublic(), "$method() is public on TermMaterializer");
}

$check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), (new ReflectionMethod(TermMaterializer::class, 'finalize_term'))->getParameters())
        === ['front', 'termObjectTaxes'],
    'finalize_term() keeps its original "front" parameter and gains the new explicit "termObjectTaxes" one'
);
$check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), (new ReflectionMethod(TermMaterializer::class, 'reconcile_term_relationships'))->getParameters())
        === ['termId', 'taxonomy', 'relField', 'termObjectTaxes'],
    'reconcile_term_relationships() keeps its three original parameters and gains the new explicit "termObjectTaxes" one'
);

// The constructor takes exactly these three collaborators, in this order --
// a narrow, explicit data contract rather than a whole Apply instance.
$constructorParams = (new ReflectionClass(TermMaterializer::class))->getConstructor()->getParameters();
$check(
    array_map(static fn(ReflectionParameter $p): string => (string) $p->getType(), $constructorParams)
        === ['Duo\\Policy', 'Duo\\Tokens', 'Duo\\ApplyFieldMaterializer'],
    'constructor depends on exactly Policy, Tokens, and ApplyFieldMaterializer -- no Apply instance'
);

// The one dependency that does NOT fit that narrow contract -- Apply's own
// memoized, WordPress-registry-reading taxes_by_object_type() -- was never
// smuggled in as a fourth constructor collaborator or a hidden Apply
// back-reference; it travels as a plain, explicit method parameter instead.
$check(
    !(new ReflectionClass(TermMaterializer::class))->hasProperty('taxesByObjectType')
        && !(new ReflectionClass(TermMaterializer::class))->hasMethod('term_object_taxes'),
    'TermMaterializer does not carry its own copy of Apply\'s taxes_by_object_type()/term_object_taxes() -- it receives the resolved roster as a parameter'
);

// === Prove the extraction itself: Apply.php no longer inlines these bodies,
// and its one remaining call site is a thin facade.
$applySource = file_get_contents(__DIR__ . '/../../agent/src/Apply.php');
$check(
    !str_contains($applySource, 'private function encode_description(')
        && !str_contains($applySource, 'private function reconcile_term_relationships('),
    'Apply.php no longer defines encode_description()/reconcile_term_relationships() itself (moved to TermMaterializer.php, no facade needed -- neither had any other caller)'
);
$transactionSource = file_get_contents(__DIR__ . '/../../agent/src/AuthoredTransactionExecutor.php');
$check(!str_contains($applySource, 'function finalize_term(')
    && str_contains($transactionSource, '$this->termMaterializer->finalize_term('),
    'AuthoredTransactionExecutor calls TermMaterializer directly without an Apply facade');

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "\nall TermMaterializer checks passed\n";
exit(0);

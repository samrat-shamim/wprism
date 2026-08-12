<?php
/**
 * Offline regression for OptionsMaterializer (DUO-3347 slice 7: the options
 * entity materializer extracted from Apply.php). Deliberately narrow, the
 * same wiring/shape idiom TermMaterializer/UserMetaMaterializer/
 * MenuMaterializer's own regressions already established: this file does not
 * re-implement or re-assert reconciliation behavior -- doing so from a
 * hand-copied twin of the logic would only add a second copy that could
 * silently drift from the real one. It proves the two things genuinely new
 * here instead: OptionsMaterializer is a real, directly constructible,
 * standalone public API with a narrow (Policy, Tokens, ApplyFieldMaterializer)
 * contract, and its one non-narrow dependency -- Apply's own $warnings
 * collection -- is genuinely parameterized (by reference) rather than
 * silently reaching back into Apply or carrying a second copy. Full
 * behavioral coverage (plain/tokenized/managed/sub_keys option reconciliation)
 * already exists in regress_lifecycle_options_snapshot.php and every live
 * conformance manifest sweep, unchanged by this extraction.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../agent/src/Canon.php';
require_once __DIR__ . '/../../agent/src/OptionState.php';
require_once __DIR__ . '/../../agent/src/Policy.php';
require_once __DIR__ . '/../../agent/src/Ledger.php';
require_once __DIR__ . '/../../agent/src/Tokens.php';
require_once __DIR__ . '/../../agent/src/ApplyFieldMaterializer.php';
require_once __DIR__ . '/../../agent/src/OptionsMaterializer.php';

use Duo\ApplyFieldMaterializer;
use Duo\OptionsMaterializer;
use Duo\Policy;
use Duo\Tokens;

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
$optionsMaterializer = new OptionsMaterializer($policy, $tokens, $fieldMaterializer);

$check($optionsMaterializer instanceof OptionsMaterializer, 'OptionsMaterializer is directly constructible with (Policy, Tokens, ApplyFieldMaterializer)');

$check((new ReflectionMethod(OptionsMaterializer::class, 'apply_options'))->isPublic(), 'apply_options() is public on OptionsMaterializer');
foreach (['option_apply_target', 'dynamic_option_rule_for_name', 'dynamic_option_resolver_values', 'apply_value', 'apply_option_sub_keys'] as $method) {
    $check((new ReflectionMethod(OptionsMaterializer::class, $method))->isPrivate(), "$method() stays private on OptionsMaterializer -- apply_options() is the only external entry point");
}

// The constructor takes exactly these three collaborators, in this order --
// a narrow, explicit data contract rather than a whole Apply instance.
$constructorParams = (new ReflectionClass(OptionsMaterializer::class))->getConstructor()->getParameters();
$check(
    array_map(static fn(ReflectionParameter $p): string => (string) $p->getType(), $constructorParams)
        === ['Duo\\Policy', 'Duo\\Tokens', 'Duo\\ApplyFieldMaterializer'],
    'constructor depends on exactly Policy, Tokens, and ApplyFieldMaterializer -- no Apply instance'
);

// The one dependency that does NOT fit that narrow contract -- Apply's own
// $warnings collection, appended to from dozens of call sites across the
// whole file -- was never smuggled in as a fourth constructor collaborator
// or a hidden Apply back-reference; it travels as an explicit by-reference
// method parameter instead, on both methods that append to it.
$check(
    !(new ReflectionClass(OptionsMaterializer::class))->hasProperty('warnings'),
    'OptionsMaterializer does not carry its own copy of Apply\'s $warnings collection'
);
$applyOptionsParams = (new ReflectionMethod(OptionsMaterializer::class, 'apply_options'))->getParameters();
$check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $applyOptionsParams) === ['document', 'withDeletes', 'warnings']
        && $applyOptionsParams[2]->isPassedByReference(),
    'apply_options() takes the caller\'s warnings collection as an explicit by-reference third parameter'
);
$applyOptionSubKeysParams = (new ReflectionMethod(OptionsMaterializer::class, 'apply_option_sub_keys'))->getParameters();
$check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $applyOptionSubKeysParams) === ['name', 'captured', 'subKeys', 'autoload', 'warnings']
        && $applyOptionSubKeysParams[4]->isPassedByReference(),
    'apply_option_sub_keys() takes the caller\'s warnings collection as an explicit by-reference fifth parameter'
);

// === Prove the extraction itself: Apply.php no longer inlines these bodies,
// and its one remaining call site is a thin facade.
$applySource = file_get_contents(__DIR__ . '/../../agent/src/Apply.php');
foreach (['option_apply_target', 'dynamic_option_rule_for_name', 'dynamic_option_resolver_values', 'apply_value', 'apply_option_sub_keys'] as $method) {
    $check(
        !str_contains($applySource, "private function $method("),
        "Apply.php no longer defines $method() itself (moved to OptionsMaterializer.php, no facade needed -- called only from within the extracted cluster)"
    );
}
$check(
    str_contains($applySource, '$this->options_materializer()->apply_options($document, $withDeletes, $this->warnings);'),
    'Apply::apply_options() is a thin facade delegating to OptionsMaterializer, passing its own $warnings collection through by reference'
);

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "\nall OptionsMaterializer checks passed\n";
exit(0);

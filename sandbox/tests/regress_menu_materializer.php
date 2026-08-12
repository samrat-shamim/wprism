<?php
/**
 * Offline regression for MenuMaterializer (DUO-3347 slice 4: the menu entity
 * materializer extracted from Apply.php). Deliberately narrow: the full
 * behavioral proof of finalize_menu()/assign_locations() -- exact serialized
 * merge semantics, scalar/object rejection, menu-item field/meta
 * reconciliation -- already lives in
 * sandbox/tests/regress_lifecycle_options_snapshot.php, a Reflection-based
 * test against Apply's own facade with a complete $wpdb stub (confirmed
 * still green, unchanged, through this extraction), and in this project's
 * live conformance sweeps (every fixture with a menu exercises
 * finalize_menu() end to end through a real WordPress + MySQL target). This
 * file does not re-implement or re-assert that behavior -- doing so from a
 * hand-copied twin of the merge logic would only add a second copy that
 * could silently drift from the real one, exactly the anti-pattern
 * regress_conflict_view.php was fixed to stop doing earlier in this same
 * decomposition effort. It proves the one thing genuinely new here instead:
 * MenuMaterializer is a real, directly constructible, standalone public API.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../agent/src/Canon.php';
require_once __DIR__ . '/../../agent/src/OptionState.php';
require_once __DIR__ . '/../../agent/src/Policy.php';
require_once __DIR__ . '/../../agent/src/Ledger.php';
require_once __DIR__ . '/../../agent/src/Tokens.php';
require_once __DIR__ . '/../../agent/src/ApplyFieldMaterializer.php';
require_once __DIR__ . '/../../agent/src/MenuMaterializer.php';

use Duo\ApplyFieldMaterializer;
use Duo\MenuMaterializer;
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
$menuMaterializer = new MenuMaterializer($policy, $tokens, $fieldMaterializer);

$check($menuMaterializer instanceof MenuMaterializer, 'MenuMaterializer is directly constructible with (Policy, Tokens, ApplyFieldMaterializer)');

$finalizeMenu = new ReflectionMethod(MenuMaterializer::class, 'finalize_menu');
$assignLocations = new ReflectionMethod(MenuMaterializer::class, 'assign_locations');
$check($finalizeMenu->isPublic(), 'finalize_menu() is public on MenuMaterializer (was private on Apply)');
$check($assignLocations->isPublic(), 'assign_locations() is public on MenuMaterializer (was private on Apply)');
$check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $assignLocations->getParameters()) === ['menuTermId', 'locations'],
    'assign_locations() keeps its exact original parameter names and order'
);
$check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $finalizeMenu->getParameters()) === ['front'],
    'finalize_menu() keeps its exact original parameter name'
);

// The constructor takes exactly these three collaborators, in this order --
// a narrow, explicit data contract rather than a whole Apply instance.
$constructorParams = (new ReflectionClass(MenuMaterializer::class))->getConstructor()->getParameters();
$check(
    array_map(static fn(ReflectionParameter $p): string => (string) $p->getType(), $constructorParams)
        === ['Duo\\Policy', 'Duo\\Tokens', 'Duo\\ApplyFieldMaterializer'],
    'constructor depends on exactly Policy, Tokens, and ApplyFieldMaterializer -- no Apply instance'
);

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "\nall MenuMaterializer checks passed\n";
exit(0);

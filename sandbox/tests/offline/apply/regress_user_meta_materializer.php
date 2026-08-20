<?php
/**
 * Offline regression for UserMetaMaterializer (DUO-3347 slice 5: the user
 * entity materializer extracted from Apply.php). Deliberately narrow: the
 * full behavioral proof of finalize_user_meta() -- exact-login resolution
 * across divergent numeric ids, authored/runtime classification, ref
 * decoding, duplicate-row collapse, missing-user handling -- already lives
 * in sandbox/tests/live/regress_user_meta.sh, a live conformance script that
 * exercises this exact code path end to end through a real duo apply against
 * two real WordPress + MySQL targets. This file does not re-implement or
 * re-assert that behavior -- doing so from a hand-copied twin of the
 * reconciliation logic would only add a second copy that could silently
 * drift from the real one, the anti-pattern regress_conflict_view.php was
 * fixed to stop doing earlier in this same decomposition effort. It proves
 * the one thing genuinely new here instead: UserMetaMaterializer is a real,
 * directly constructible, standalone public API.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Repository/Ledger.php';
require_once __DIR__ . '/../../../../agent/src/Grammar/Tokens.php';
require_once __DIR__ . '/../../../../agent/src/Apply/ApplyFieldMaterializer.php';
require_once __DIR__ . '/../../../../agent/src/Apply/UserMetaMaterializer.php';

use Duo\ApplyFieldMaterializer;
use Duo\Policy;
use Duo\Tokens;
use Duo\UserMetaMaterializer;

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
$userMetaMaterializer = new UserMetaMaterializer($policy, $tokens, $fieldMaterializer);

$check($userMetaMaterializer instanceof UserMetaMaterializer, 'UserMetaMaterializer is directly constructible with (Policy, Tokens, ApplyFieldMaterializer)');

$finalizeUserMeta = new ReflectionMethod(UserMetaMaterializer::class, 'finalize_user_meta');
$check($finalizeUserMeta->isPublic(), 'finalize_user_meta() is public on UserMetaMaterializer (was private on Apply)');
$check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $finalizeUserMeta->getParameters()) === ['front'],
    'finalize_user_meta() keeps its exact original parameter name'
);

$check(
    (new ReflectionClass(UserMetaMaterializer::class))->hasMethod('resolve_exact_login')
        && (new ReflectionMethod(UserMetaMaterializer::class, 'resolve_exact_login'))->isPrivate(),
    'resolve_exact_login() moved along with its only caller and stays private (no external call site ever used it)'
);

// The constructor takes exactly these three collaborators, in this order --
// a narrow, explicit data contract rather than a whole Apply instance.
$constructorParams = (new ReflectionClass(UserMetaMaterializer::class))->getConstructor()->getParameters();
$check(
    array_map(static fn(ReflectionParameter $p): string => (string) $p->getType(), $constructorParams)
        === ['Duo\\Policy', 'Duo\\Tokens', 'Duo\\ApplyFieldMaterializer'],
    'constructor depends on exactly Policy, Tokens, and ApplyFieldMaterializer -- no Apply instance'
);

// Its own exact-login memoization cache moved with it -- Apply.php no longer
// declares $exactUserIds at all (grep-proven in the PR body), so this is the
// only place that state can now live.
$check(
    (new ReflectionClass(UserMetaMaterializer::class))->hasProperty('exactUserIds'),
    'the exact-login memoization cache moved into UserMetaMaterializer, not left dangling on Apply'
);

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "\nall UserMetaMaterializer checks passed\n";
exit(0);

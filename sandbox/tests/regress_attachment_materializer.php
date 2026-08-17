<?php
/**
 * Offline regression for AttachmentMaterializer (DUO-3347 slice 9: the
 * attachment materializer extracted from Apply.php). Deliberately narrow,
 * the same wiring/shape idiom Menu/UserMeta/Term/Options/Relationship's own
 * regressions already established: this file does not re-implement or
 * re-assert attachment placement behavior -- doing so from a hand-copied
 * twin of the logic would only add a second copy that could silently drift
 * from the real one. It proves the two things genuinely new here instead:
 * AttachmentMaterializer is a real, directly constructible, standalone
 * public API with a (ApplyFieldMaterializer, CompiledRepository) contract
 * -- no Policy, no Tokens, an even narrower shape than
 * RelationshipMaterializer's (Policy) -- and CompiledRepository is
 * genuinely constructor-injected as the immutable value object it is,
 * rather than threaded through per-call the way the shared, memoized
 * $termObjectTaxes/$taxesForPostType parameters had to be. Full behavioral
 * coverage already exists in every live conformance manifest sweep (every
 * apply that carries a captured attachment exercises this code), unchanged
 * by this extraction.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../agent/src/Repository/Ledger.php';
require_once __DIR__ . '/../../agent/src/Grammar/Tokens.php';
require_once __DIR__ . '/../../agent/src/Apply/ApplyFieldMaterializer.php';
require_once __DIR__ . '/../../agent/src/Repository/CompiledArtifact.php';
require_once __DIR__ . '/../../agent/src/Apply/AttachmentMaterializer.php';

use Duo\ApplyFieldMaterializer;
use Duo\AttachmentMaterializer;
use Duo\CompiledRepository;
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
// WordPress runtime and a real CompiledRepository needs a compiled
// repository tree to construct, neither of which this offline suite stands
// up.
$policy = (new ReflectionClass(Policy::class))->newInstanceWithoutConstructor();
$tokens = (new ReflectionClass(Tokens::class))->newInstanceWithoutConstructor();
$fieldMaterializer = new ApplyFieldMaterializer($policy, $tokens);
$compiled = (new ReflectionClass(CompiledRepository::class))->newInstanceWithoutConstructor();
$attachmentMaterializer = new AttachmentMaterializer($fieldMaterializer, $compiled);

$check($attachmentMaterializer instanceof AttachmentMaterializer, 'AttachmentMaterializer is directly constructible with (ApplyFieldMaterializer, CompiledRepository)');
$check((new ReflectionMethod(AttachmentMaterializer::class, 'place_attachment'))->isPublic(), 'place_attachment() is public on AttachmentMaterializer');

// The constructor takes exactly these two collaborators, in this order --
// a narrower, more explicit data contract than Menu/UserMeta/Term/Options's
// (Policy, Tokens, ApplyFieldMaterializer), and even narrower than
// RelationshipMaterializer's (Policy): neither Policy nor Tokens is
// referenced anywhere in the moved body (verified by the require-hygiene
// scanner finding no gap against either class).
$constructorParams = (new ReflectionClass(AttachmentMaterializer::class))->getConstructor()->getParameters();
$check(
    array_map(static fn(ReflectionParameter $p): string => (string) $p->getType(), $constructorParams)
        === ['Duo\\ApplyFieldMaterializer', 'Duo\\CompiledRepository'],
    'constructor depends on exactly ApplyFieldMaterializer and CompiledRepository -- no Policy, no Tokens, no Apply instance'
);

// CompiledRepository is genuinely constructor-injected (the immutable
// value object precedent this class's own docblock claims), not passed as
// a per-call method parameter the way the shared, memoized
// $termObjectTaxes/$taxesForPostType rosters had to be.
$placeAttachmentParams = (new ReflectionMethod(AttachmentMaterializer::class, 'place_attachment'))->getParameters();
$check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $placeAttachmentParams) === ['id', 'front'],
    'place_attachment() keeps its original two parameters -- CompiledRepository travels via the constructor, not a third parameter'
);

// === Prove the extraction itself. place_attachment()'s only caller,
// finalize_post(), itself moved to PostMaterializer in DUO-3347 slice 11 --
// the new PostMaterializer::finalize_post() calls
// AttachmentMaterializer::place_attachment() directly (calling back through
// Apply's own facade would be circular), so Apply's place_attachment()
// facade is now genuinely dead code and was removed entirely rather than
// kept, the same "no other caller, no facade needed" treatment
// TermMaterializer's encode_description()/reconcile_term_relationships()
// already established (slice 6).
$applySource = file_get_contents(__DIR__ . '/../../agent/src/Apply/Apply.php');
$check(
    !str_contains($applySource, 'private function place_attachment('),
    'Apply.php no longer defines place_attachment() at all (moved to PostMaterializer\'s own call site, no facade needed -- it had no other caller)'
);

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "\nall AttachmentMaterializer checks passed\n";
exit(0);

<?php
/**
 * Offline regression for PostMaterializer (DUO-3347 slice 10: the post
 * entity materializer -- currently just ensure_post_row(), phase 1 of post
 * materialization -- extracted from Apply.php). Deliberately narrow, the
 * same wiring/shape idiom the earlier materializer extractions in this
 * series established: this file does not re-implement or re-assert row
 * creation behavior -- doing so from a hand-copied twin of the logic would
 * only add a second copy that could silently drift from the real one. It
 * proves the two things genuinely new here instead: PostMaterializer is a
 * real, directly constructible, standalone public API with a (Tokens)-only
 * contract -- no Policy, matching AttachmentMaterializer's precedent of an
 * even-narrower-than-usual shape -- and finalize_post() deliberately stays
 * on Apply this slice (not silently dropped). Full behavioral coverage
 * already exists in sandbox/tests/regress_post_field_classification.php and
 * every live conformance manifest sweep (every apply creating a new post
 * exercises this code), unchanged by this extraction.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../agent/src/Ledger.php';
require_once __DIR__ . '/../../agent/src/Tokens.php';
require_once __DIR__ . '/../../agent/src/PostMaterializer.php';

use Duo\PostMaterializer;
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
$tokens = (new ReflectionClass(Tokens::class))->newInstanceWithoutConstructor();
$postMaterializer = new PostMaterializer($tokens);

$check($postMaterializer instanceof PostMaterializer, 'PostMaterializer is directly constructible with (Tokens)');
$check((new ReflectionMethod(PostMaterializer::class, 'ensure_post_row'))->isPublic(), 'ensure_post_row() is public on PostMaterializer');

// The constructor takes exactly this one collaborator -- no Policy, an
// even narrower contract than RelationshipMaterializer's (Policy)-only
// shape: Policy is never referenced anywhere in the moved body (verified
// by the require-hygiene scanner finding no gap against it).
$constructorParams = (new ReflectionClass(PostMaterializer::class))->getConstructor()->getParameters();
$check(
    array_map(static fn(ReflectionParameter $p): string => (string) $p->getType(), $constructorParams) === ['Duo\\Tokens'],
    'constructor depends on exactly Tokens -- no Policy, no Apply instance'
);
$check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), (new ReflectionMethod(PostMaterializer::class, 'ensure_post_row'))->getParameters()) === ['front'],
    'ensure_post_row() keeps its original single parameter'
);

// === Prove the extraction itself: Apply.php no longer inlines the body,
// its remaining call site is a thin facade, and finalize_post() -- the
// other half of post materialization -- deliberately stays on Apply this
// slice rather than being silently dropped.
$applySource = file_get_contents(__DIR__ . '/../../agent/src/Apply.php');
$check(
    !preg_match('/private function ensure_post_row\(array \$front\): bool \{\s*global \$wpdb;\s*if \(Ledger::id_for/', $applySource),
    'Apply.php no longer inlines ensure_post_row()\'s own body (only the facade remains)'
);
$check(
    str_contains($applySource, '$this->post_materializer()->ensure_post_row($front);'),
    'Apply::ensure_post_row() is a thin facade delegating to PostMaterializer'
);
$check(
    str_contains($applySource, 'private function finalize_post(array $front, string $body): void {'),
    'Apply::finalize_post() is still present -- deliberately not moved this slice, not silently dropped'
);

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "\nall PostMaterializer checks passed\n";
exit(0);

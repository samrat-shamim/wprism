<?php
/**
 * Offline regression for PostMaterializer (DUO-3347 slices 10-11: the post
 * entity materializer). Deliberately narrow, the same wiring/shape idiom the
 * earlier materializer extractions in this series established: this file
 * does not re-implement or re-assert row-creation/finalization behavior --
 * doing so from a hand-copied twin of the logic would only add a second
 * copy that could silently drift from the real one.
 *
 * Slice 10 moved ensure_post_row() (phase 1) alone, with a (Tokens)-only
 * contract, and deliberately left finalize_post() (phase 2) on Apply.
 * Slice 11 (this file's current version) moves finalize_post() too, along
 * with resolve_login()/its $userIds memoization cache (the shared-state
 * coupling that blocked slice 10 from moving it) -- widening the
 * constructor to (Policy, Tokens, ApplyFieldMaterializer,
 * RelationshipMaterializer, AttachmentMaterializer), the three additional
 * collaborators finalize_post() itself calls directly now (reconcile_
 * authored_meta(), reconcile_relationships(), place_attachment()) rather
 * than through Apply's own now-removed facades over them, which would be
 * circular. Full behavioral coverage already exists in
 * sandbox/tests/offline/grammar/regress_post_field_classification.php (finalize_post(),
 * via Apply's own facade and reflection) and every live conformance
 * manifest sweep, unchanged by this extraction.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Repository/Ledger.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Grammar/Tokens.php';
require_once __DIR__ . '/../../../../agent/src/Apply/ApplyFieldMaterializer.php';
require_once __DIR__ . '/../../../../agent/src/Apply/RelationshipMaterializer.php';
require_once __DIR__ . '/../../../../agent/src/Repository/CompiledArtifact.php';
require_once __DIR__ . '/../../../../agent/src/Apply/AttachmentMaterializer.php';
require_once __DIR__ . '/../../../../agent/src/Apply/PostMaterializer.php';

use Duo\ApplyFieldMaterializer;
use Duo\AttachmentMaterializer;
use Duo\CompiledRepository;
use Duo\Policy;
use Duo\PostMaterializer;
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
// behavioral one (see the file docblock) -- a real Policy/Tokens/
// CompiledRepository each need a live compiled manifest set or WordPress
// runtime to construct, which this offline suite deliberately does not
// stand up.
$policy = (new ReflectionClass(Policy::class))->newInstanceWithoutConstructor();
$tokens = (new ReflectionClass(Tokens::class))->newInstanceWithoutConstructor();
$fieldMaterializer = new ApplyFieldMaterializer($policy, $tokens);
$relationshipMaterializer = new RelationshipMaterializer($policy, $fieldMaterializer);
$compiled = (new ReflectionClass(CompiledRepository::class))->newInstanceWithoutConstructor();
$attachmentMaterializer = new AttachmentMaterializer($policy, $fieldMaterializer, $compiled, '/fixture/repository');
$postMaterializer = new PostMaterializer($policy, $tokens, $fieldMaterializer, $relationshipMaterializer, $attachmentMaterializer);

$check($postMaterializer instanceof PostMaterializer, 'PostMaterializer is directly constructible with (Policy, Tokens, ApplyFieldMaterializer, RelationshipMaterializer, AttachmentMaterializer)');
foreach (['ensure_post_row', 'finalize_post', 'resolve_login'] as $method) {
    $check((new ReflectionMethod(PostMaterializer::class, $method))->isPublic(), "$method() is public on PostMaterializer");
}

// The constructor takes exactly these five collaborators, in this order --
// widened from slice 10's lone Tokens because finalize_post() itself calls
// three already-extracted sibling materializers directly (calling back
// through Apply would be circular).
$constructorParams = (new ReflectionClass(PostMaterializer::class))->getConstructor()->getParameters();
$check(
    array_map(static fn(ReflectionParameter $p): string => (string) $p->getType(), $constructorParams) === [
        'Duo\\Policy', 'Duo\\Tokens', 'Duo\\ApplyFieldMaterializer', 'Duo\\RelationshipMaterializer', 'Duo\\AttachmentMaterializer',
    ],
    'constructor depends on exactly Policy, Tokens, ApplyFieldMaterializer, RelationshipMaterializer, AttachmentMaterializer -- no Apply instance'
);
$check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), (new ReflectionMethod(PostMaterializer::class, 'ensure_post_row'))->getParameters()) === ['front'],
    'ensure_post_row() keeps its original single parameter'
);

// The one caller-scoped value RelationshipMaterializer's own
// reconcile_relationships() needs (Apply's memoized taxes_for_post_type()
// roster) travels into finalize_post() as an explicit parameter, the same
// pattern TermMaterializer's/RelationshipMaterializer's own
// $termObjectTaxes/$taxesForPostType established (slices 6/8);
// $defaultAuthor and $warnings travel the same way, the latter by
// reference, extending OptionsMaterializer's array-output-parameter idiom
// (slice 7) across this class boundary too.
$finalizeParams = (new ReflectionMethod(PostMaterializer::class, 'finalize_post'))->getParameters();
$check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $finalizeParams) === ['front', 'body', 'defaultAuthor', 'warnings', 'taxesForPostType'],
    'finalize_post() keeps its original two parameters and gains the three new explicit ones (defaultAuthor, warnings, taxesForPostType)'
);
$check(
    $finalizeParams[3]->isPassedByReference(),
    'finalize_post()\'s $warnings parameter is by-reference, matching apply_options()\'s existing array-output-parameter idiom'
);
$check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), (new ReflectionMethod(PostMaterializer::class, 'resolve_login'))->getParameters()) === ['login'],
    'resolve_login() keeps its original single parameter'
);

// === Prove the extraction itself. ensure_post_row() and finalize_post()
// each keep a thin Apply facade (both still have an external caller: run()
// for ensure_post_row(), and run() again for finalize_post()); resolve_login()
// also keeps one, since run() still calls it directly to seed
// $defaultAuthor. Apply's own reconcile_relationships()/place_attachment()/
// reconcile_authored_meta() facades, by contrast, had finalize_post() as
// their ONLY caller and were removed entirely now that finalize_post() calls
// the underlying materializers directly -- covered by
// regress_relationship_materializer.php/regress_attachment_materializer.php/
// regress_apply_field_materializer.php, not repeated here.
$applySource = file_get_contents(__DIR__ . '/../../../../agent/src/Apply/Apply.php');
$transactionSource = file_get_contents(__DIR__ . '/../../../../agent/src/Apply/AuthoredTransactionExecutor.php');
$check(
    !preg_match('/private function ensure_post_row\(array \$front\): bool \{\s*global \$wpdb;\s*if \(Ledger::id_for/', $applySource),
    'Apply.php no longer inlines ensure_post_row()\'s own body (only the facade remains)'
);
$check(!str_contains($applySource, 'function ensure_post_row(')
    && str_contains($transactionSource, '$this->postMaterializer->ensure_post_row($front);'),
    'AuthoredTransactionExecutor calls PostMaterializer::ensure_post_row() directly');
$check(
    !preg_match('/private function finalize_post\(array \$front, string \$body\): void \{\s*global \$wpdb;\s*\$id = Ledger::id_for.*\$parentId = 0;/s', $applySource),
    'Apply.php no longer inlines finalize_post()\'s own body (only the facade remains)'
);
$check(!str_contains($applySource, 'function finalize_post(')
    && str_contains($transactionSource, '$this->postMaterializer->finalize_post(')
    && str_contains($transactionSource, '$defaultAuthor,')
    && str_contains($transactionSource, '$warnings,'),
    'AuthoredTransactionExecutor calls PostMaterializer::finalize_post() with explicit state');
$check(
    !preg_match('/private function resolve_login\(string \$login\): \?int \{\s*global \$wpdb;\s*if \(\$login/', $applySource),
    'Apply.php no longer inlines resolve_login()\'s own body (only the facade remains)'
);
$check(!str_contains($applySource, 'function resolve_login('),
    'Apply has no dead resolve_login compatibility facade');
$check(
    !str_contains($applySource, 'private array $userIds'),
    'Apply.php no longer declares $userIds itself (moved to PostMaterializer alongside resolve_login())'
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

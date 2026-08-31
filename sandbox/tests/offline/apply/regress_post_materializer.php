<?php
/**
 * Offline regression for PostMaterializer (issue #3347 slices 10-11: the post
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
require_once __DIR__ . '/../../../../agent/src/Apply/ProtectedPostIdentity.php';

use WPrism\ApplyFieldMaterializer;
use WPrism\AttachmentMaterializer;
use WPrism\CompiledRepository;
use WPrism\EnvironmentValues;
use WPrism\Policy;
use WPrism\PostMaterializer;
use WPrism\ProtectedPostIdentity;
use WPrism\RelationshipMaterializer;
use WPrism\Tokens;

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
$passwordUuid = '019200cc-0000-7000-8000-0000000000c7';
$passwordBinding = EnvironmentValues::postPasswordName($passwordUuid);
$postMaterializer = new PostMaterializer(
    $policy,
    $tokens,
    $fieldMaterializer,
    $relationshipMaterializer,
    $attachmentMaterializer,
    [$passwordBinding => 'target-local-password']
);

$check($postMaterializer instanceof PostMaterializer, 'PostMaterializer is directly constructible with (Policy, Tokens, ApplyFieldMaterializer, RelationshipMaterializer, AttachmentMaterializer)');
foreach (['ensure_post_row', 'finalize_post', 'resolve_login'] as $method) {
    $check((new ReflectionMethod(PostMaterializer::class, $method))->isPublic(), "$method() is public on PostMaterializer");
}

// The constructor takes these collaborators in this order --
// widened from slice 10's lone Tokens because finalize_post() itself calls
// three already-extracted sibling materializers directly (calling back
// through Apply would be circular).
$constructorParams = (new ReflectionClass(PostMaterializer::class))->getConstructor()->getParameters();
$check(
    array_map(static fn(ReflectionParameter $p): string => (string) $p->getType(), $constructorParams) === [
        'WPrism\\Policy', 'WPrism\\Tokens', 'WPrism\\ApplyFieldMaterializer', 'WPrism\\RelationshipMaterializer', 'WPrism\\AttachmentMaterializer', 'array',
    ],
    'constructor receives the five materializer collaborators plus target-local environment values -- no Apply instance'
);
$passwordMethod = new ReflectionMethod(PostMaterializer::class, 'postPassword');
$check(
    $passwordMethod->invoke($postMaterializer, ['uuid' => $passwordUuid]) === ''
        && $passwordMethod->invoke($postMaterializer, [
            'uuid' => $passwordUuid,
            'password_binding' => $passwordBinding,
        ]) === 'target-local-password',
    'post password materialization resolves only the target-local binding and clears an unbound desired post'
);
$missingPassword = new PostMaterializer(
    $policy,
    $tokens,
    $fieldMaterializer,
    $relationshipMaterializer,
    $attachmentMaterializer
);
$missingRefusal = null;
try {
    $passwordMethod->invoke($missingPassword, [
        'uuid' => $passwordUuid,
        'password_binding' => $passwordBinding,
    ]);
} catch (RuntimeException $failure) {
    $missingRefusal = $failure->getMessage();
}
$check(
    is_string($missingRefusal) && str_contains($missingRefusal, 'is not provisioned on this environment'),
    'post materialization refuses a protected post before mutation when its local password binding is absent'
);
$maximumPassword = str_repeat('p', 255);
$maximumPasswordMaterializer = new PostMaterializer(
    $policy,
    $tokens,
    $fieldMaterializer,
    $relationshipMaterializer,
    $attachmentMaterializer,
    [$passwordBinding => $maximumPassword]
);
$check(
    $passwordMethod->invoke($maximumPasswordMaterializer, [
        'uuid' => $passwordUuid,
        'password_binding' => $passwordBinding,
    ]) === $maximumPassword,
    'post materialization accepts the exact 255-byte wp_posts.post_password boundary'
);
$overlongPasswordMaterializer = new PostMaterializer(
    $policy,
    $tokens,
    $fieldMaterializer,
    $relationshipMaterializer,
    $attachmentMaterializer,
    [$passwordBinding => str_repeat('p', 256)]
);
$overlongPasswordRefusal = null;
try {
    $passwordMethod->invoke($overlongPasswordMaterializer, [
        'uuid' => $passwordUuid,
        'password_binding' => $passwordBinding,
    ]);
} catch (RuntimeException $failure) {
    $overlongPasswordRefusal = $failure->getMessage();
}
$check(
    $overlongPasswordRefusal === 'wprism: protected post password must contain 1 to 255 bytes',
    'post materialization refuses an impossible 256-byte protected-post value before a wp_posts write'
);
$protectedIdentitySource = (string) file_get_contents(
    __DIR__ . '/../../../../agent/src/Apply/ProtectedPostIdentity.php'
);
$requestCoordinatorSource = (string) file_get_contents(
    __DIR__ . '/../../../../agent/src/Apply/ApplyRequestCoordinator.php'
);
$planEnvironmentSource = (string) file_get_contents(
    __DIR__ . '/../../../../agent/src/Apply/ApplyPlanEnvironment.php'
);
$identityLockPosition = strpos($requestCoordinatorSource, 'ProtectedPostIdentity::lock(');
$intendedValuePosition = strpos($requestCoordinatorSource, 'EnvironmentValues::set($repo, $name, $value);');
$check(
    str_contains($protectedIdentitySource, 'DeleteGuardEvaluator::assert_innodb_tables([')
        && substr_count($protectedIdentitySource, 'DeleteGuardEvaluator::full_width_composite_unique_lock_index(') === 1
        && substr_count($protectedIdentitySource, 'DeleteGuardEvaluator::full_width_lock_index(') === 2
        && substr_count($protectedIdentitySource, 'DeleteGuardEvaluator::bounded_prefix_lock_index(') === 2
        && str_contains($protectedIdentitySource, '$wpdb->terms,')
        && str_contains($protectedIdentitySource, 'SELECT uuid, id_kind, local_id, entity_type')
        && str_contains($protectedIdentitySource, 'SELECT ID, post_type, post_password FROM {$wpdb->posts} FORCE INDEX')
        && substr_count($protectedIdentitySource, 'FOR UPDATE') >= 4
        && str_contains($protectedIdentitySource, 'self::locked_meta_rows($wpdb->postmeta')
        && str_contains($protectedIdentitySource, 'self::locked_meta_rows($wpdb->termmeta')
        && str_contains($protectedIdentitySource, 'self::locked_live_owner_ids(')
        && str_contains($protectedIdentitySource, 'EXISTS (SELECT 1 FROM {$wpdb->posts} gpo')
        && str_contains($protectedIdentitySource, 'EXISTS (SELECT 1 FROM {$wpdb->terms} gto')
        && str_contains($protectedIdentitySource, 'CONNECTION_ID() = %s AND @@in_transaction = 1')
        && str_contains($protectedIdentitySource, 'BINARY post_password = BINARY %s')
        && str_contains($protectedIdentitySource, 'Db::transaction_connection_id('),
    'protected-post provisioning proves transactional tables/indexes and locks the map, metadata ranges, and live-owner rows or gaps'
);
$identityAssertion = new ReflectionMethod(ProtectedPostIdentity::class, 'assert_locked_global_identity');
$canonicalIdentityRow = [
    'meta_id' => '1',
    'owner_id' => '41',
    'meta_key' => '_wprism_uuid',
    'meta_value_prefix' => $passwordUuid,
    'meta_value_bytes' => '36',
];
$orphanPostIdentityRow = array_replace($canonicalIdentityRow, ['meta_id' => '2', 'owner_id' => '901']);
$orphanTermIdentityRow = array_replace($canonicalIdentityRow, ['meta_id' => '3', 'owner_id' => '902']);
$orphanRefusal = null;
try {
    $identityAssertion->invoke(
        null,
        $passwordUuid,
        41,
        [$canonicalIdentityRow, $orphanPostIdentityRow],
        [$orphanTermIdentityRow],
        [41 => true],
        []
    );
} catch (RuntimeException $failure) {
    $orphanRefusal = $failure->getMessage();
}
$check(
    $orphanRefusal === null,
    'protected-post UUID uniqueness ignores exact orphan postmeta and termmeta sidecars after locking their missing-owner gaps'
);
$liveTermDuplicateRefusal = null;
try {
    $identityAssertion->invoke(
        null,
        $passwordUuid,
        41,
        [$canonicalIdentityRow, $orphanPostIdentityRow],
        [$orphanTermIdentityRow],
        [41 => true],
        [902 => true]
    );
} catch (RuntimeException $failure) {
    $liveTermDuplicateRefusal = $failure->getMessage();
}
$check(
    $liveTermDuplicateRefusal
        === 'wprism: protected post binding identity does not match its unique exact live backing row',
    'protected-post UUID uniqueness refuses an exact termmeta duplicate when that term owner is live'
);
$check(
    is_int($identityLockPosition)
        && is_int($intendedValuePosition)
        && $identityLockPosition < $intendedValuePosition
        && str_contains($requestCoordinatorSource, "Db::start_repeatable_read('env-set protected post transaction start')")
        && str_contains($requestCoordinatorSource, 'DeleteGuardEvaluator::begin_authored_transaction();')
        && str_contains($requestCoordinatorSource, 'DeleteGuardEvaluator::end_authored_transaction();')
        && str_contains($requestCoordinatorSource, 'ProtectedPostIdentity::update_password(')
        && str_contains($requestCoordinatorSource, 'env-set protected post publication continuity')
        && str_contains($requestCoordinatorSource, 'env-set protected post precommit continuity')
        && str_contains($planEnvironmentSource, 'ProtectedPostIdentity::observe((string) $uuid, $postType)')
        && str_contains($planEnvironmentSource, '$live = $postWitness[\'post_password\'] ?? null;')
        && !str_contains($planEnvironmentSource, 'SELECT post_password'),
    'env-set carries verified session continuity through identity/write/commit and plan consumes one coherent identity/password witness'
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

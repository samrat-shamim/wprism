<?php
/**
 * Offline regression for DUO-3347 slice 1: ConvergenceVerifier extracted
 * from Apply's post-apply convergence gate.
 *
 * The live success path (a real re-capture proving byte-semantic
 * convergence) stays covered by the scoped-apply and core-conformance live
 * suites — this suite proves the extraction itself landed correctly without
 * a database: the pure hash function is byte-identical to Apply's prior
 * inline logic, Apply's facade methods delegate to the new class rather
 * than reimplementing it, the fully-moved methods are actually gone from
 * Apply (not duplicated), and the five fields Apply hands to the new
 * class's constructor land in their own slots — a same-type (?array,
 * ?array) transposition of scopeContract/scopedObservation would compile
 * and pass every type check while silently breaking scoped verification.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../agent/src/Canon.php';
require_once __DIR__ . '/../../agent/src/Policy.php';
require_once __DIR__ . '/../../agent/src/ScopedApplySession.php';
require_once __DIR__ . '/../../agent/src/ConvergenceVerifier.php';
require_once __DIR__ . '/../../agent/src/Apply.php';

use Duo\Apply;
use Duo\Canon;
use Duo\ConvergenceVerifier;
use Duo\Policy;
use Duo\ScopedApplySession;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

// ---- 1. ConvergenceVerifier::hash() is byte-identical to Apply's prior
// inline verification_hash(): the post-type fast path returns the stored
// hash verbatim (never recomputed from data), the general path canonically
// encodes the entity's data.
$postEntity = ['type' => 'post', 'hash' => str_repeat('ab', 32), 'data' => ['unused' => true]];
$check(
    ConvergenceVerifier::hash($postEntity) === $postEntity['hash'],
    'post-type entities hash to the stored hash verbatim, not a recomputation'
);

$termEntity = ['type' => 'term', 'data' => ['name' => 'Widgets', 'slug' => 'widgets']];
$expectedTermHash = hash('sha256', Canon::encode($termEntity['data']));
$check(
    ConvergenceVerifier::hash($termEntity) === $expectedTermHash,
    'non-post entities hash the canonically-encoded data payload'
);

$optionEntity = ['type' => 'option', 'data' => ['name' => 'blogname', 'value' => 'Example']];
$check(
    ConvergenceVerifier::hash($optionEntity) === hash('sha256', Canon::encode($optionEntity['data'])),
    'the canonical-encode path is not special-cased to a single non-post type'
);

// ---- 2. Apply's private facades genuinely delegate rather than duplicating
// the logic. Source-scraped, not behavior-tested: both are private instance
// methods, and a bypassed-constructor Apply has no cheap way to prove which
// code path executed short of reading the source that will actually ship.
// Reflection alone (hasMethod()) only proves the method still exists, not
// that its BODY still delegates -- a future edit could reinline the ~90-line
// verify_convergence() logic and every other check in this suite would keep
// passing, so both facades get the same one-line-body regex, not just one.
$applySource = (string) file_get_contents(__DIR__ . '/../../agent/src/Apply.php');
$check(
    (bool) preg_match(
        '/private function verification_hash\(array \$entity\): string \{\s*return ConvergenceVerifier::hash\(\$entity\);/',
        $applySource
    ),
    'Apply::verification_hash() must delegate to ConvergenceVerifier::hash(), not reimplement it'
);
$check(
    (bool) preg_match(
        '/private function verify_convergence\(array \$opts, CompiledRepository \$compiled\): array \{\s*'
            . 'return \$this->convergence_verifier\(\)->verify\(\$opts, \$compiled\);\s*\}/',
        $applySource
    ),
    'Apply::verify_convergence() must delegate to convergence_verifier()->verify(), not reimplement it'
);

// Isolate verify_canonical()'s own body: `plan()` and `apply()` legitimately
// keep unrelated `$a = new self(...)` constructions of the real mutating
// Apply instance, so a whole-file scrape for that pattern would false-fail
// on those, not on this method.
$verifyCanonicalMatched = preg_match(
    '/public static function verify_canonical\(.*?\n    \}\n/s',
    $applySource,
    $verifyCanonicalMatch
);
$check($verifyCanonicalMatched === 1, 'verify_canonical() must be present and isolatable for inspection');
$verifyCanonicalBody = $verifyCanonicalMatch[0] ?? '';
$check(
    str_contains($verifyCanonicalBody, 'new ConvergenceVerifier('),
    'Apply::verify_canonical() must construct ConvergenceVerifier for its local verification paths'
);
$check(
    !str_contains($verifyCanonicalBody, '$a = new self('),
    'verify_canonical() must no longer build a full Apply instance just to reach convergence verification'
);

// ---- 3. The four fully-moved methods are gone from Apply, not duplicated
// alongside the new class (the facade wraps ConvergenceVerifier; it does not
// keep a second copy of the logic it forwards to).
$applyReflection = new ReflectionClass(Apply::class);
foreach (['verify_scoped_convergence', 'verify_scoped_convergence_local', 'verify_convergence_local'] as $moved) {
    $check(
        !$applyReflection->hasMethod($moved),
        "Apply must no longer declare $moved() -- it belongs to ConvergenceVerifier now"
    );
}
$check(
    $applyReflection->hasMethod('verify_convergence') && $applyReflection->hasMethod('verification_hash'),
    'Apply must keep its two call-site-preserving facade methods'
);

// ---- 4. ConvergenceVerifier exposes exactly the surface verify_canonical()
// and Apply::convergence_verifier() need.
$verifierReflection = new ReflectionClass(ConvergenceVerifier::class);
foreach (['verify', 'verify_scoped_local', 'verify_local'] as $public) {
    $method = $verifierReflection->hasMethod($public) ? $verifierReflection->getMethod($public) : null;
    $check($method !== null && $method->isPublic(), "ConvergenceVerifier::$public() must exist and be public");
}
$hashMethod = $verifierReflection->hasMethod('hash') ? $verifierReflection->getMethod('hash') : null;
$check(
    $hashMethod !== null && $hashMethod->isPublic() && $hashMethod->isStatic(),
    'ConvergenceVerifier::hash() must exist as a public static method'
);

// ---- 5. Apply::convergence_verifier() wires its five fields into
// ConvergenceVerifier's constructor without transposing scopeContract and
// scopedObservation -- both are plain ?array, so a swapped argument order
// would satisfy every type check and only show up as a wrong-data failure
// deep inside a live scoped apply.
$apply = $applyReflection->newInstanceWithoutConstructor();
$policySentinel = (new ReflectionClass(Policy::class))->newInstanceWithoutConstructor();
$sessionSentinel = (new ReflectionClass(ScopedApplySession::class))->newInstanceWithoutConstructor();

$setApplyProp = static function (string $name, $value) use ($applyReflection, $apply): void {
    $prop = $applyReflection->getProperty($name);
    $prop->setAccessible(true);
    $prop->setValue($apply, $value);
};
$setApplyProp('repo', '/sentinel-repo');
$setApplyProp('policy', $policySentinel);
$setApplyProp('scopeContract', ['sentinel' => 'scope-contract']);
$setApplyProp('scopedObservation', ['sentinel' => 'scoped-observation']);
$setApplyProp('scopedSession', $sessionSentinel);

$buildVerifier = $applyReflection->getMethod('convergence_verifier');
$buildVerifier->setAccessible(true);
$verifier = $buildVerifier->invoke($apply);
$check($verifier instanceof ConvergenceVerifier, 'convergence_verifier() must return a ConvergenceVerifier');

$getVerifierProp = static function (string $name) use ($verifierReflection, $verifier) {
    $prop = $verifierReflection->getProperty($name);
    $prop->setAccessible(true);
    return $prop->getValue($verifier);
};
$check($getVerifierProp('repo') === '/sentinel-repo', 'repo must pass through to ConvergenceVerifier unchanged');
$check($getVerifierProp('policy') === $policySentinel, 'policy must pass through by identity, unchanged');
$check(
    $getVerifierProp('scopeContract') === ['sentinel' => 'scope-contract'],
    'scopeContract must land in its own constructor slot'
);
$check(
    $getVerifierProp('scopedObservation') === ['sentinel' => 'scoped-observation'],
    'scopedObservation must land in its own constructor slot, not transposed with scopeContract'
);
$check($getVerifierProp('scopedSession') === $sessionSentinel, 'scopedSession must pass through by identity, unchanged');

// ---- 6/7. DUO-3440/DUO-3441: both Apply.php and ConvergenceVerifier.php
// must carry their OWN collaborator requires (Apply -> ConvergenceVerifier;
// ConvergenceVerifier -> Canon), the same way every sibling extraction
// already self-requires its own dependency. This suite's own top-of-file
// requires (lines 19-23, above) load Canon.php and ConvergenceVerifier.php
// before Apply.php regardless, so every check above would keep passing even
// if either file's own require were missing -- that masking is exactly how
// both gaps shipped undetected. Proving either one needs a genuinely
// separate process: PHP class loading is process-global, so nothing already
// inside THIS process can "unrequire" a class to recreate the standalone
// scenario the bugs are actually about.
$isolatedProbeCheck = static function (string $requiredFile, string $probeBody, string $needle, string $label) use ($check): void {
    $absolutePath = realpath($requiredFile);
    $probe = tempnam(sys_get_temp_dir(), 'duo-isolation-probe-');
    file_put_contents($probe, "<?php\nrequire " . var_export($absolutePath, true) . ";\n" . $probeBody);
    $output = [];
    $exit = null;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' 2>&1', $output, $exit);
    unlink($probe);
    $output = implode("\n", $output);
    $check(
        $exit === 0 && str_contains($output, $needle),
        "$label (exit=" . var_export($exit, true) . ($exit === 0 ? '' : "; output: $output") . ')'
    );
};

$isolatedProbeCheck(
    __DIR__ . '/../../agent/src/Apply.php',
    <<<'PHP'
$rm = new ReflectionMethod(\Duo\Apply::class, 'verification_hash');
$rm->setAccessible(true);
$apply = (new ReflectionClass(\Duo\Apply::class))->newInstanceWithoutConstructor();
echo $rm->invoke($apply, ['type' => 'post', 'hash' => 'duo-3440-isolation-probe']);
PHP,
    'duo-3440-isolation-probe',
    'Apply.php must carry its OWN collaborator requires when required standalone: a fresh process '
        . 'requiring only agent/src/Apply.php (not the full agent/duo.php bootstrap) must reach a '
        . 'ConvergenceVerifier-touching method without a "Class ...ConvergenceVerifier not found" fatal'
);

$isolatedProbeCheck(
    __DIR__ . '/../../agent/src/ConvergenceVerifier.php',
    <<<'PHP'
echo \Duo\ConvergenceVerifier::hash(['type' => 'term', 'data' => ['name' => 'duo-3441-isolation-probe']]);
PHP,
    hash('sha256', \Duo\Canon::encode(['name' => 'duo-3441-isolation-probe'])),
    'ConvergenceVerifier.php must carry its OWN Canon.php require when required standalone: a fresh '
        . 'process requiring only agent/src/ConvergenceVerifier.php must reach the non-post hash() path '
        . '(the only path that touches Canon) without a "Class ...Canon not found" fatal'
);

if ($failures) {
    fwrite(STDERR, "FAIL\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "ok: ConvergenceVerifier extraction preserves hash bytes, delegates rather than duplicates, and wires state without transposition\n";

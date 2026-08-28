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

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Scope/ScopedApplySession.php';
require_once __DIR__ . '/../../../../agent/src/Apply/ConvergenceVerifier.php';
require_once __DIR__ . '/../../../../agent/src/Apply/Apply.php';

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

// ---- 2. The public facade contains no duplicate convergence implementation;
// the request coordinator constructs the real collaborator at both call sites.
$applySource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Apply/Apply.php');
$coordinatorSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Apply/ApplyRequestCoordinator.php');
$check(
    !str_contains($applySource, 'verification_hash') && !str_contains($applySource, 'verify_convergence'),
    'Apply must not retain private convergence compatibility facades'
);
$check(
    substr_count($coordinatorSource, 'new ConvergenceVerifier(') >= 2,
    'request verification paths construct ConvergenceVerifier directly'
);

// Isolate verify_canonical()'s own body: `plan()` and `apply()` legitimately
// keep unrelated `$a = new self(...)` constructions of the real mutating
// Apply instance, so a whole-file scrape for that pattern would false-fail
// on those, not on this method.
$verifyCanonicalMatched = preg_match(
    '/public static function verify_canonical\(.*?\n    \}\n/s',
    $coordinatorSource,
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

// ---- 3. All private convergence methods are gone from Apply.
$applyReflection = new ReflectionClass(Apply::class);
$applyConstructor = $applyReflection->getConstructor();
$check(
    $applyConstructor !== null && $applyConstructor->isPrivate(),
    'Apply remains a static-only, non-instantiable public facade'
);
foreach (['verify_scoped_convergence', 'verify_scoped_convergence_local', 'verify_convergence_local', 'verify_convergence', 'verification_hash'] as $moved) {
    $check(
        !$applyReflection->hasMethod($moved),
        "Apply must no longer declare $moved() -- it belongs to ConvergenceVerifier now"
    );
}

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

// ---- 5. The collaborator constructor keeps the two adjacent nullable-array
// slots distinct; this directly pins its true owner rather than a dead facade.
$policySentinel = (new ReflectionClass(Policy::class))->newInstanceWithoutConstructor();
$sessionSentinel = (new ReflectionClass(ScopedApplySession::class))->newInstanceWithoutConstructor();
$verifier = new ConvergenceVerifier(
    '/sentinel-repo',
    $policySentinel,
    ['sentinel' => 'scope-contract'],
    ['sentinel' => 'scoped-observation'],
    $sessionSentinel
);

$getVerifierProp = static function (string $name) use ($verifierReflection, $verifier) {
    $prop = $verifierReflection->getProperty($name);
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

// ---- 6/7. The coordinator and ConvergenceVerifier carry their own requires,
// the same way every sibling extraction
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
    __DIR__ . '/../../../../agent/src/Apply/ApplyRequestCoordinator.php',
    <<<'PHP'
echo \Duo\ConvergenceVerifier::hash(['type' => 'post', 'hash' => 'duo-3440-isolation-probe']);
PHP,
    'duo-3440-isolation-probe',
    'ApplyRequestCoordinator.php must carry its own ConvergenceVerifier dependency when required standalone'
);

// Canon is the one ConvergenceVerifier dependency worth an isolation check.
// hash()'s live siblings (verify()/verify_local()/verify_scoped_local()) also
// reference Capture/ScopedApply/ScopedApplySession without requiring them,
// but that's not a gap this suite can usefully pin: each of those classes'
// OWN require chain bottoms out in ANOTHER unrequired class one level
// further down (Capture -> Canary, ScopedApply -> Ledger) before ever
// reaching a $wpdb-bound WordPress call -- so no standalone probe of them
// can terminate in a clean pass or a specific, attributable failure either
// way. Canon is different: requiring it is enough, full stop, because
// Canon.php is itself a dependency-free leaf.
$isolatedProbeCheck(
    __DIR__ . '/../../../../agent/src/Apply/ConvergenceVerifier.php',
    <<<'PHP'
echo \Duo\ConvergenceVerifier::hash(['type' => 'term', 'data' => ['name' => 'duo-3441-isolation-probe']]);
PHP,
    hash('sha256', \Duo\Canon::encode(['name' => 'duo-3441-isolation-probe'])),
    'ConvergenceVerifier.php must carry its OWN Canon.php require when required standalone: a fresh '
        . 'process requiring only agent/src/Apply/ConvergenceVerifier.php must reach the non-post hash() path '
        . '(the only path that touches Canon) without a "Class ...Canon not found" fatal'
);

if ($failures) {
    fwrite(STDERR, "FAIL\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "ok: ConvergenceVerifier extraction preserves hash bytes, delegates rather than duplicates, and wires state without transposition\n";

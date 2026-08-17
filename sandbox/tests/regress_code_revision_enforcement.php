<?php
/**
 * Offline contract regression for descriptor revision enforcement. This
 * intentionally loads Deploy.php without WordPress: the detector and the
 * staged-path predicate only need an immutable compiled descriptor plus the
 * Ledger marker values, so fake those two boundaries rather than hiding the
 * behavior behind a Docker fixture. Staged authorization is covered by the
 * payload materializer regression, where Code can re-hash a real target.
 */

namespace Duo;

final class CompiledRepository {
    public function __construct(private ?array $descriptor, private ?string $revision) {}
    public function code_descriptor(): ?array { return $this->descriptor; }
    public function code_revision(): ?string { return $this->revision; }
}

final class Ledger {
    /** @var array<string,string> */
    public static array $kv = [];

    public static function kv_get(string $key): ?string {
        return self::$kv[$key] ?? null;
    }
}

// Deploy is intentionally loaded in isolation here. This tiny test double
// stands in for the proof-owning Code class and makes the delegation explicit;
// the real payload/hash cases live in the materializer regressions.
final class Code {
    public const CODE_REVISION_KEY = 'code_revision';
    public static int $proofCalls = 0;

    public static function completed_code_mismatch(CompiledRepository $compiled): ?string {
        self::$proofCalls++;
        $revision = $compiled->code_revision();
        $completed = Ledger::kv_get(self::CODE_REVISION_KEY);
        if ($completed !== null && hash_equals((string) $revision, $completed)) {
            return null;
        }
        return $completed === null
            ? 'no completed code_revision marker exists on this environment'
            : "this environment completed code revision '$completed'";
    }
}

require_once __DIR__ . '/../../agent/src/Promotion/Deploy.php';
require_once __DIR__ . '/../../agent/src/Apply/ApplyPreparationCoordinator.php';
require_once __DIR__ . '/../../agent/src/Apply/Apply.php';

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$legacy = new CompiledRepository(null, null);
Ledger::$kv = [];
$check(
    Deploy::code_revision_mismatch($legacy) === [],
    'legacy artifact with no descriptor must retain pre-code behavior'
);

$revision = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$compiled = new CompiledRepository(['format' => 1, 'layout' => 'wp-content'], $revision);
Ledger::$kv = [];
$stale = Deploy::code_revision_mismatch($compiled);
$check(Code::$proofCalls === 1, 'Deploy stale detection must delegate to Code proof implementation');
$check(count($stale) === 1, 'missing completed marker must produce one stale finding');
$check(($stale[0]['issue'] ?? null) === 'code_revision_stale', 'stale finding must use the code_revision_stale issue');
$check(($stale[0]['expected_revision'] ?? null) === $revision, 'stale finding must identify the compiled revision');
$check(array_key_exists('completed_revision', $stale[0] ?? []) && $stale[0]['completed_revision'] === null, 'missing completed marker must remain structured as null');

Ledger::$kv = ['code_revision' => $revision];
$check(
    Deploy::code_revision_mismatch($compiled) === [],
    'matching completed marker must clear descriptor staleness'
);

$old = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
Ledger::$kv = ['code_revision' => $old];
$stale = Deploy::code_revision_mismatch($compiled);
$check(($stale[0]['completed_revision'] ?? null) === $old, 'mismatched completed marker must be reported exactly');

// The stale revision is an ordering invariant, not a generic compatibility
// finding. Exercise Apply's real gate directly: no ordinary force flag may
// turn a state write into an allowed operation before finalize records the
// completed marker. Keeping this pure/offline makes the safety contract cheap
// to run while still testing the method Apply::run() calls before mutations.
$applyGate = new \ReflectionMethod(ApplyPreparationCoordinator::class, 'enforce_code_mismatch_gate');
$staleRow = Deploy::code_revision_mismatch($compiled)[0];
foreach ([
    ['opts' => [], 'label' => 'without force flags'],
    ['opts' => ['force_code_mismatch' => true], 'label' => 'with --force-code-mismatch'],
    ['opts' => ['force_code_drift' => true], 'label' => 'with --force-code-drift'],
    ['opts' => ['force_code_mismatch' => true, 'force_code_drift' => true], 'label' => 'with both code force flags'],
    ['opts' => [
        'force_theirs' => true,
        'force_delete_referenced' => true,
        'force_code_mismatch' => true,
        'force_code_drift' => true,
    ], 'label' => 'with every apply force flag'],
] as $case) {
    $blocked = false;
    try {
        $applyGate->invoke(null, [$staleRow], $case['opts']);
    } catch (\RuntimeException $e) {
        $blocked = true;
        $check(str_contains($e->getMessage(), 'code_revision_stale'), $case['label'] . ' must name stale code revision');
        $check(str_contains($e->getMessage(), "duo deploy <env>"), $case['label'] . ' must direct host deploy recovery');
        $check(str_contains($e->getMessage(), 'non-forceable'), $case['label'] . ' must explain non-forceable ordering');
    }
    $check($blocked, 'stale code revision must refuse ' . $case['label']);
}

$lifecycle = [['issue' => 'inactive_in_environment', 'message' => 'plugin activation needs lifecycle reconciliation']];
$blocked = false;
try {
    $applyGate->invoke(null, $lifecycle, []);
} catch (\RuntimeException $e) {
    $blocked = true;
    $check(str_contains($e->getMessage(), '--force-code-mismatch'), 'ordinary lifecycle mismatch must retain its explicit force advice');
}
$check($blocked, 'ordinary lifecycle mismatch must still refuse without its force flag');
$check(
    $applyGate->invoke(null, $lifecycle, ['force_code_mismatch' => true]) === $lifecycle,
    'ordinary lifecycle mismatch must remain the only forceable code mismatch class'
);

// --materializing-code is not a public escape hatch. It is accepted only
// alongside an explicit host continuation; PromotionLock then proves that
// continuation against the durable begun session and live row.
$materializingGate = new \ReflectionMethod(Deploy::class, 'assert_materializing_continuation');
$blocked = false;
try {
    $materializingGate->invoke(null, ['materializing_code' => true], false);
} catch (\RuntimeException $e) {
    $blocked = true;
    $check(str_contains($e->getMessage(), 'host orchestrator promotion continuation'), 'standalone materializing refusal must identify the required boundary');
}
$check($blocked, 'standalone --materializing-code must be refused');
$check(
    $materializingGate->invoke(null, ['materializing_code' => true, 'promotion_owner' => 'host'], true) === null,
    'host continuation may proceed to strict PromotionLock validation'
);
$check(
    $materializingGate->invoke(null, [], false) === null,
    'ordinary standalone lifecycle deploy must remain supported'
);

// The fresh verifier's artifact identity is mandatory, not an optional
// comparison. Pin this before any snapshot/artifact read so even an internal
// empty-value invocation cannot reach Ledger::ensure or target inspection.
foreach ([
    ['opts' => [], 'label' => 'missing expected artifact'],
    ['opts' => ['expected_artifact' => ''], 'label' => 'empty expected artifact'],
    ['opts' => ['expected_artifact' => 'not-a-sha256'], 'label' => 'malformed expected artifact'],
] as $case) {
    $blocked = false;
    try {
        Apply::verify_canonical('/must-not-be-read', $case['opts']);
    } catch (\RuntimeException $e) {
        $blocked = true;
        $check(str_contains($e->getMessage(), 'expected artifact sha256'), $case['label'] . ' refusal must name the required binding');
    }
    $check($blocked, $case['label'] . ' must fail before frozen-input reads');
}

// A stale descriptor must win even when a forceable lifecycle finding is in
// the same plan. This pins the split-gate order, not just its two isolated
// branches.
$mixed = [$lifecycle[0], $staleRow];
$blocked = false;
try {
    $applyGate->invoke(null, $mixed, ['force_code_mismatch' => true]);
} catch (\RuntimeException $e) {
    $blocked = true;
    $check(str_contains($e->getMessage(), 'code_revision_stale'), 'stale code revision must be reported before a forceable lifecycle mismatch');
    $check(!str_contains($e->getMessage(), 'code_mismatch:\n\n  - plugin activation'), 'mixed mismatch gate must not fall through to generic lifecycle force handling');
}
$check($blocked, 'a forceable lifecycle mismatch must not let a stale descriptor cross the ordering boundary');

if ($failures) {
    fwrite(STDERR, "FAIL\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "ok: descriptor revision staleness is structured, legacy-safe, stage-gated, and non-forceable\n";

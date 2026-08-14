<?php
/** Real-source refusal factories/gates used by regress_cli_json_refusals.php. */

require __DIR__ . '/../../../agent/src/Ledger.php';
require __DIR__ . '/../../../agent/src/Tokens.php';
require __DIR__ . '/../../../agent/src/Capture.php';
require_once __DIR__ . '/../../../agent/src/CaptureSafetyGates.php';

use Duo\Capture;
use Duo\CaptureSafetyGates;
use Duo\Canon;
use Duo\CommandRefusalException;
use Duo\Tokens;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo "ok: $message\n";
        return;
    }
    echo "FAIL: $message\n";
    $failures++;
};

$newGateFixture = static function (array $captureValues = [], array $tokenValues = []): array {
    $tokens = (new ReflectionClass(Tokens::class))->newInstanceWithoutConstructor();
    foreach ($tokenValues as $property => $value) {
        $tokens->$property = $value;
    }
    return [new CaptureSafetyGates('/operator/private/repository'), $tokens, $captureValues];
};

$invoke = static function (array $fixture, string $method, array $arguments = []): ?CommandRefusalException {
    [$gates, $tokens, $values] = $fixture;
    try {
        if ($method === 'assert_option_gates') {
            $gates->assertOptions(
                $values['unclassified'] ?? [],
                $values['unscopedRefs'] ?? [],
                $values['unscopedOptionNameRefs'] ?? [],
                $tokens
            );
        } elseif ($method === 'assert_content_ref_gates') {
            $gates->assertContentReferences($tokens);
        } elseif ($method === 'assert_scope_gaps') {
            $gates->assertScopeGaps($arguments[0] ?? []);
        } else {
            throw new RuntimeException("unknown gate fixture method $method");
        }
    } catch (CommandRefusalException $e) {
        return $e;
    }
    return null;
};

$cases = [
    'option ref' => [
        'method' => 'assert_option_gates',
        'capture' => ['unscopedRefs' => [[
            'option' => 'widget_target', 'kind' => 'post', 'id' => 17, 'target_type' => 'elementor_library',
        ]]],
        'tokens' => [],
        'reason' => 'unresolved_reference_scope',
        'diagnostic' => 'unresolved_option_reference_scope',
        'operator' => 'unresolvable ref-typed option(s)',
    ],
    'table ref' => [
        'method' => 'assert_option_gates',
        'capture' => ['unscopedOptionNameRefs' => [[
            'option' => 'form_target', 'id_kind' => 'nf3_form', 'id' => 23,
        ]]],
        'tokens' => [],
        'reason' => 'unresolved_reference_scope',
        'diagnostic' => 'unminted_table_reference_scope',
        'operator' => 'option_name_refs option(s)',
    ],
    'URL query ref' => [
        'method' => 'assert_option_gates',
        'capture' => [],
        'tokens' => ['unscopedUrlQueryRefs' => [[
            'context' => "page 'private-title'", 'param' => 'page_id', 'id' => 29, 'target_type' => 'landing',
        ]]],
        'reason' => 'unresolved_reference_scope',
        'diagnostic' => 'unresolved_url_query_reference_scope',
        'operator' => 'unresolvable url-query-typed reference(s)',
    ],
    'block ref' => [
        'method' => 'assert_content_ref_gates',
        'capture' => [],
        'tokens' => ['unscopedBlockRefs' => [[
            'post' => "page 'private-title'", 'block' => 'core/image', 'attr' => 'id',
            'kind' => 'post', 'id' => 31, 'target_type' => 'landing',
        ]]],
        'reason' => 'unresolved_reference_scope',
        'diagnostic' => 'unresolved_block_reference_scope',
        'operator' => 'unresolvable ref-typed block attribute(s)',
    ],
    'shortcode ref' => [
        'method' => 'assert_content_ref_gates',
        'capture' => [],
        'tokens' => ['unscopedShortcodeRefs' => [[
            'post' => "page 'private-title'", 'shortcode' => 'gallery', 'attr' => 'ids[0]',
            'kind' => 'post', 'id' => 37, 'target_type' => 'landing',
        ]]],
        'reason' => 'unresolved_reference_scope',
        'diagnostic' => 'unresolved_shortcode_reference_scope',
        'operator' => 'unresolvable ref-typed shortcode attribute(s)',
    ],
];

foreach ($cases as $label => $case) {
    $failure = $invoke($newGateFixture($case['capture'], $case['tokens']), $case['method']);
    $payload = $failure?->payload() ?? [];
    $check($failure instanceof CommandRefusalException, "$label is a deliberate public refusal");
    $check(($payload['error'] ?? null) === $case['reason'], "$label has a finite source-owned reason");
    $check(($payload['diagnostics'][0]['code'] ?? null) === $case['diagnostic'], "$label has a stable per-finding diagnostic");
    $check(str_contains((string) ($payload['diagnostics'][0]['remediation'] ?? ''), '--force-unresolved-refs'), "$label preserves the explicit force remedy");
    $check(str_contains((string) $failure?->getMessage(), $case['operator']), "$label preserves rich human evidence");
    $check(!str_contains((string) json_encode($payload), 'private-title'), "$label omits operator-only content labels from public JSON");
}

$scopeFailure = $invoke(
    $newGateFixture(),
    'assert_scope_gaps',
    [['post_type:landing' => ['entities' => 2]]]
);
$check(($scopeFailure?->payload()['error'] ?? null) === 'incomplete_policy_scope', 'policy scope gap has a finite source-owned reason');
$check(($scopeFailure?->payload()['diagnostics'][0]['surface'] ?? null) === 'scope:post_type:landing', 'policy scope gap identifies its exact reviewed surface');
$check(str_contains((string) $scopeFailure?->getMessage(), 'registered or adapter-declared authored state'), 'policy scope gap preserves rich human evidence');

$commitFactory = new ReflectionMethod(Capture::class, 'commit_outcome_uncertain');
$cause = new RuntimeException('operator-only database detail');
$commitFailure = $commitFactory->invoke(null, 'duo: exact human uncertain-commit evidence', $cause);
$check($commitFailure instanceof CommandRefusalException, 'uncertain commit uses a deliberate public refusal');
$check($commitFailure->reasonCode === 'capture_commit_uncertain', 'uncertain commit has a stable recovery reason');
$check(str_contains($commitFailure->remediation, 'do not retry or discard'), 'uncertain commit explicitly prohibits unsafe replay');
$check($commitFailure->getPrevious() === $cause, 'uncertain commit preserves its private throwable chain for operators');
$check(!str_contains((string) json_encode($commitFailure->payload()), 'operator-only'), 'uncertain commit never serializes its private cause');

$markerRoot = sys_get_temp_dir() . '/duo-cli-json-marker-' . bin2hex(random_bytes(6));
$check(mkdir($markerRoot, 0700), 'database marker refusal fixture root is created');
$stateDir = $markerRoot . '/state';
$destinationMethod = new ReflectionMethod(Capture::class, 'publication_destination_sha256');
$destination = $destinationMethod->invoke(null, $stateDir);
$intent = [
    'id' => str_repeat('1', 32),
    'candidate_sha256' => str_repeat('2', 64),
    'previous_sha256' => str_repeat('3', 64),
];
$sealMarker = static function (array $record): string {
    $record['record_sha256'] = hash('sha256', Canon::encode($record));
    return Canon::encode($record);
};
$markerRecord = static function (
    string $stateSha,
    string $intentId,
    string $candidateSha,
    string $previousSha
) use ($sealMarker): string {
    return $sealMarker([
        'format' => 'duo-capture-commit-marker/v1',
        'state_sha256' => $stateSha,
        'intent_id' => $intentId,
        'candidate_sha256' => $candidateSha,
        'previous_sha256' => $previousSha,
    ]);
};
$wpdb = new class {
    public string $prefix = 'wp_';
    public string $last_error = '';
    public string $marker = '';

    public function prepare(string $query, mixed ...$arguments): string {
        return $query;
    }

    public function get_var(mixed $query): string {
        return $this->marker;
    }
};
$markerStatus = new ReflectionMethod(Capture::class, 'publication_commit_status');
$markerCases = [
    'malformed marker' => '{',
    'different-destination marker' => $markerRecord(
        str_repeat('4', 64),
        $intent['id'],
        $intent['candidate_sha256'],
        $intent['previous_sha256']
    ),
    'current-intent digest mismatch' => $markerRecord(
        $destination,
        $intent['id'],
        str_repeat('5', 64),
        $intent['previous_sha256']
    ),
];
foreach ($markerCases as $label => $marker) {
    $wpdb->marker = $marker;
    $failure = null;
    try {
        $markerStatus->invoke(null, $stateDir, $intent);
    } catch (ReflectionException $e) {
        throw $e;
    } catch (Throwable $e) {
        $failure = $e;
    }
    $check($failure instanceof CommandRefusalException, "$label is a deliberate recovery refusal");
    $reasonCode = $failure instanceof CommandRefusalException ? $failure->reasonCode : null;
    $remediation = $failure instanceof CommandRefusalException ? $failure->remediation : '';
    $payload = $failure instanceof CommandRefusalException ? $failure->payload() : [];
    $check($reasonCode === 'capture_recovery_ambiguous', "$label has the stable ambiguous-recovery code");
    $check(str_contains($remediation, 'do not retry or discard'), "$label explicitly forbids unsafe replay or evidence disposal");
    $check(!str_contains((string) json_encode($payload), 'database commit marker'), "$label keeps marker details operator-only");
}
$wpdb->marker = $markerRecord(
    $destination,
    str_repeat('6', 32),
    $intent['candidate_sha256'],
    $intent['previous_sha256']
);
$check($markerStatus->invoke(null, $stateDir, $intent) === false, 'a valid prior-intent marker remains a deterministic rollback signal');
unset($wpdb);
@rmdir($markerRoot);

if ($failures > 0) {
    fwrite(STDERR, "$failures source refusal check(s) failed\n");
    exit(1);
}
echo "ALL PASSED\n";

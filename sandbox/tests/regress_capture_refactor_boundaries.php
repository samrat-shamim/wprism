<?php
declare(strict_types=1);

/** Structural regression for Capture's stable façade and collaborator split. */
$root = dirname(__DIR__, 2);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        fwrite(STDOUT, "ok: $message\n");
        return;
    }
    $failures[] = $message;
    fwrite(STDERR, "FAIL: $message\n");
};
$source = static function (string $name) use ($root): string {
    $bytes = file_get_contents("$root/agent/src/$name.php");
    if (!is_string($bytes)) {
        throw new RuntimeException("cannot read $name.php");
    }
    return $bytes;
};

$capture = $source('Capture');
$candidate = $source('CaptureCandidateBuilder');
$snapshot = $source('CaptureSnapshotService');
$workflow = $source('CapturePublicationWorkflow');
$recovery = $source('CapturePublicationRecovery');
$initial = $source('InitialCaptureBoundary');
$scoped = $source('ScopedCaptureProjector');
$identity = $source('CaptureIdentity');
$post = $source('PostCapture');
$term = $source('TermCapture');
$captureCode = '';
foreach (token_get_all($capture) as $token) {
    if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
        continue;
    }
    $captureCode .= is_array($token) ? $token[1] : $token;
}
$buildStart = strpos($candidate, 'public function build(');
$buildEnd = strpos($candidate, 'private function reset(', (int) $buildStart);
$candidateBuild = substr($candidate, (int) $buildStart, (int) $buildEnd - (int) $buildStart);

$check(substr_count($capture, "\n") + 1 <= 650, 'Capture stays a small command-facing façade');
$check(!str_contains($capture, '$this->'), 'Capture has no per-build mutable instance state');
$check(!str_contains($captureCode, 'Db::') && !str_contains($captureCode, '$wpdb'),
    'Capture owns neither database discovery nor transaction implementation');
$check(!str_contains($capture, 'Publish::begin_intent(') && !str_contains($capture, 'Publish::swap('),
    'Capture owns no publication implementation');

preg_match_all('/public static function ([A-Za-z_][A-Za-z0-9_]*)\(/', $capture, $matches);
$public = $matches[1] ?? [];
$check($public === [
    'run',
    'run_initial_baseline',
    'snapshot',
    'snapshot_read_only',
    'build_read_only_export',
    'assert_read_only_export_engine_support',
    'snapshot_options_core',
    'gate_scan',
    'gate_scan_read_only',
    'publication_commit_status',
    'ref_target_type',
    'classify_unscoped_ref',
], 'Capture preserves the complete historical public static API');

foreach ([
    'CapturePublicationWorkflow::run(' => 'publication workflow',
    'CaptureSnapshotService::snapshot(' => 'ordinary snapshot service',
    'CaptureSnapshotService::snapshotReadOnly(' => 'strict snapshot service',
    'CaptureSnapshotService::buildReadOnlyExport(' => 'read-only export service',
    'CaptureSnapshotService::snapshotOptionsCore(' => 'lifecycle options service',
    'CaptureGateScanner(' => 'pending gate scanner',
    'CapturePublicationRecovery::commitStatus(' => 'publication recovery proof',
    'ReferenceScopeClassifier::targetType(' => 'reference target classifier',
    'ReferenceScopeClassifier::classify(' => 'reference scope classifier',
] as $needle => $label) {
    $check(str_contains($capture, $needle), "Capture delegates to the $label");
}

$order = [];
foreach ([
    '$this->scopeDiscovery->discover(' => 'scope',
    '$this->captureIdentity->ensurePost(' => 'post identity',
    '$this->captureIdentity->ensureTerm(' => 'term identity',
    '$this->menuCapture->capture(' => 'menu identity/capture',
    '$this->termCapture->capture(' => 'term capture',
    '$this->postCapture->capture(' => 'post capture',
    '$this->buildOptions(' => 'options capture',
    '$this->safetyGates->assertContentReferences($this->tokens);' => 'final safety gates',
] as $needle => $label) {
    $offset = strpos($candidateBuild, $needle);
    $check($offset !== false, "candidate builder owns $label");
    $order[] = (int) $offset;
}
$check($order === array_values($order) && $order === array_unique($order)
    && $order === (function (array $positions): array { sort($positions); return $positions; })($order),
    'candidate construction preserves its identity/entity/gate ordering');

$check(str_contains($identity, 'public function ensurePost(')
    && str_contains($identity, 'public function ensureTerm(')
    && str_contains($identity, 'Ledger::require_read_only_mapping('),
    'identity collaborator owns minting plus strict durable-map verification');
$check(str_contains($post, '$this->mediaCapture->capture(')
    && str_contains($post, '$this->entityMetaCapture->classifyValue('),
    'post collaborator owns post/meta/media projection');
$check(str_contains($term, '$this->entityMetaCapture->termMetaByKey(')
    && str_contains($term, "'relationships'"),
    'term collaborator owns term/meta/relationship projection');

$check(str_contains($snapshot, 'Ledger::assert_read_only_schema()')
    && !str_contains($snapshot, "Snapshot::repair_truncated_entity_types(")
    && str_contains($snapshot, '$capture->build('),
    'snapshot service keeps strict reads separate from maintenance-aware observation');
$check(str_contains($workflow, 'CaptureTransaction::run(')
    && str_contains($workflow, 'Publish::begin_intent(')
    && str_contains($workflow, 'Publish::mark_commit_ready('),
    'publication workflow owns the ordered transaction and filesystem protocol');
$check(str_contains($recovery, "'duo-capture-commit-marker/v1'")
    && str_contains($recovery, 'CommandRefusalException::ambiguousCaptureRecovery('),
    'publication recovery owns exact self-hashed commit proof and ambiguity refusal');
$check(str_contains($initial, "require_once __DIR__ . '/InitProtocol.php';")
    && str_contains($initial, 'InitProtocol::ATTEMPT_FILE')
    && str_contains($initial, 'InitProtocol::ATTEMPT_NEXT_FILE')
    && str_contains($initial, 'assertProtocolBoundaries(')
    && str_contains($initial, 'assertStateReservation('),
    'initial boundary owns interrupted-init and first-publication ownership checks');
$check(str_contains($scoped, 'ScopeContract::assert_associated(')
    && str_contains($scoped, 'ScopedStateOverlay::resolve_request('),
    'scoped projector owns full and compact request association');

if ($failures !== []) {
    fwrite(STDERR, 'REGRESS_CAPTURE_REFACTOR_BOUNDARIES FAILED: ' . count($failures) . " check(s)\n");
    exit(1);
}
fwrite(STDOUT, "REGRESS_CAPTURE_REFACTOR_BOUNDARIES PASSED\n");

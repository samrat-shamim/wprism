<?php
/** Architecture regression for the pure lifecycle planner and exact writers. */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';

$root = dirname(__DIR__, 4);
$sources = [];
foreach ([
    'deploy' => 'agent/src/Promotion/Deploy.php',
    'planner' => 'agent/src/Promotion/LifecyclePlanner.php',
    'observation' => 'agent/src/Promotion/CodeLifecycleObservation.php',
    'acceptance' => 'agent/src/Promotion/CodeBaselineAcceptance.php',
    'capture' => 'agent/src/Promotion/CodeBaselineCapture.php',
    'publication' => 'agent/src/Promotion/CodeBaselinePublication.php',
    'transaction' => 'agent/src/Promotion/CodeBaselineTransaction.php',
    'workflow' => 'agent/src/Capture/CapturePublicationWorkflow.php',
] as $name => $relative) {
    $source = file_get_contents($root . '/' . $relative);
    wprism_check(is_string($source), "$relative is readable");
    $sources[$name] = (string) $source;
}

wprism_check(
    str_contains($sources['deploy'], "require_once __DIR__ . '/LifecyclePlanner.php';")
        && str_contains($sources['deploy'], "require_once __DIR__ . '/CodeBaselinePublication.php';"),
    'Deploy loads the pure planner and terminal baseline publisher directly'
);
wprism_check(
    str_contains($sources['workflow'], "require_once __DIR__ . '/../Promotion/CodeBaselineCapture.php';")
        && str_contains($sources['workflow'], 'CodeBaselineCapture::observe_or_publish($capturedDesired)')
        && str_contains($sources['workflow'], 'Ledger::kv_table_installed()'),
    'capture delegates baseline writes and the shared first-install ledger fact to their owners'
);
wprism_check(
    str_contains($sources['acceptance'], 'final class CodeBaselineAcceptance')
        && str_contains($sources['acceptance'], 'acquire_deploy_preflight_with_external_fence(')
        && str_contains($sources['acceptance'], 'RepositoryCompiler::read_artifact(')
        && str_contains($sources['acceptance'], 'CodeBaselineTransaction::lock_current(')
        && !str_contains($sources['acceptance'], 'LifecycleExecutor::')
        && !str_contains($sources['acceptance'], 'ProviderPhaseExecutor::')
        && !str_contains($sources['acceptance'], 'RetainedCheckpoint'),
    'baseline acceptance is fenced and artifact-bound without becoming lifecycle/provider/checkpoint work'
);
wprism_check(
    str_contains($sources['transaction'], 'LockedOptionRows::read_required(')
        && str_contains($sources['transaction'], 'Ledger::kv_get_for_update(')
        && str_contains($sources['transaction'], 'Ledger::kv_set_transactional(')
        && str_contains($sources['transaction'], 'Ledger::kv_delete_transactional('),
    'one shared transaction boundary owns lifecycle locks and atomic baseline/receipt publication'
);
wprism_check(
    strpos($sources['transaction'], 'CodeLifecycleObservation::option_names()')
        < strpos($sources['transaction'], 'self::RECEIPT_KEY')
        && strpos($sources['transaction'], 'self::RECEIPT_KEY')
            < strpos($sources['transaction'], 'self::BASELINE_KEY'),
    'shared writer source pins lifecycle rows before lexical receipt/baseline locks'
);
wprism_check(
    !str_contains($sources['planner'], 'Db::')
        && !str_contains($sources['planner'], 'Ledger::kv_set')
        && !str_contains($sources['planner'], 'Ledger::kv_delete')
        && !str_contains($sources['planner'], 'update_option('),
    'LifecyclePlanner remains a pure observation/projection layer with no database writer'
);
wprism_check(
    str_contains($sources['planner'], 'CodeLifecycleObservation::read_unlocked($desired, $recordedRaw)'),
    'LifecyclePlanner delegates live option observation to its responsibility-focused collaborator'
);

foreach ([
    ['code_mismatch', 'return LifecyclePlanner::code_mismatch($policy, $desired);'],
    ['code_revision_mismatch', 'return LifecyclePlanner::code_revision_mismatch($compiled);'],
    ['code_drift', 'return LifecyclePlanner::code_drift($policy, $desired);'],
] as [$method, $delegate]) {
    wprism_check(str_contains($sources['deploy'], $delegate), "Deploy::$method() remains a thin planner facade");
}
wprism_check(
    preg_match('/function\s+record_code_versions\s*\(/', $sources['deploy']) !== 1
        && preg_match('/function\s+(?:record|observe|accept)_code_versions\s*\(/', $sources['planner']) !== 1,
    'obsolete unfenced baseline writers are absent from Deploy and LifecyclePlanner'
);
wprism_check(
    str_contains($sources['deploy'], 'CodeBaselinePublication::publish_terminal($desired, $expectedObservationSha256);'),
    'only terminal lifecycle reconciliation reaches the deploy baseline publisher'
);

require_once $root . '/agent/src/Promotion/LifecyclePlanner.php';
require_once $root . '/agent/src/Promotion/CodeLifecycleObservation.php';
require_once $root . '/agent/src/Promotion/CodeBaselineAcceptance.php';
require_once $root . '/agent/src/Promotion/CodeBaselineCapture.php';
require_once $root . '/agent/src/Promotion/CodeBaselinePublication.php';
require_once $root . '/agent/src/Promotion/CodeBaselineTransaction.php';

$planner = new ReflectionClass(\WPrism\LifecyclePlanner::class);
$parameters = static fn(ReflectionMethod $method): array => array_map(
    static fn(ReflectionParameter $parameter): string => $parameter->getName(),
    $method->getParameters()
);
foreach ([
    'deployment_status' => ['policy', 'compiled', 'forceCodeMismatch'],
    'deployment_status_from_observation' => ['policy', 'desired', 'forceCodeMismatch', 'observation'],
    'code_mismatch' => ['policy', 'desired'],
    'code_revision_mismatch' => ['compiled'],
    'code_drift' => ['policy', 'desired'],
    'code_baseline_observation_sha256' => ['policy', 'desired'],
    'deployment_observation_sha256' => ['policy', 'desired', 'forceCodeMismatch'],
    'deployment_phase_observation' => ['policy', 'desired', 'forceCodeMismatch'],
    'code_baseline_acceptance_snapshot' => ['policy', 'desired', 'forceCodeMismatch', 'observation'],
    'code_baseline_publication_snapshot' => ['desired', 'observation'],
    'code_baseline_capture_snapshot' => ['desired', 'observation'],
    'assert_code_baseline_publication' => ['desired', 'expectedLiveFactsSha256', 'expectedBaselineBytes', 'observation'],
] as $method => $expected) {
    wprism_check($planner->hasMethod($method), "LifecyclePlanner exposes $method()");
    if ($planner->hasMethod($method)) {
        $reflection = $planner->getMethod($method);
        wprism_check($reflection->isPublic(), "$method() is public on LifecyclePlanner");
        wprism_check_same($expected, $parameters($reflection), "$method() keeps its reviewed parameter boundary");
    }
}
foreach (['record_code_versions', 'observe_code_versions', 'accept_code_versions'] as $removed) {
    wprism_check(!$planner->hasMethod($removed), "$removed() cannot bypass the exact writer boundary");
}

foreach ([
    \WPrism\CodeLifecycleObservation::class => [
        'option_names' => [],
        'read_unlocked' => ['desired', 'recordedRaw'],
        'from_locked_rows' => ['desired', 'optionRows', 'recordedRaw'],
    ],
    \WPrism\CodeBaselineAcceptance::class => ['run' => ['repo', 'compiledPath', 'artifactHash', 'opts']],
    \WPrism\CodeBaselineCapture::class => ['observe_or_publish' => ['desired']],
    \WPrism\CodeBaselinePublication::class => ['publish_terminal' => ['desired', 'expectedCodeBoundary']],
    \WPrism\CodeBaselineTransaction::class => ['lock_current' => ['desired', 'authority', 'purpose']],
] as $class => $methods) {
    $reflection = new ReflectionClass($class);
    wprism_check($reflection->isFinal(), "$class is a closed responsibility-focused collaborator");
    foreach ($methods as $method => $expected) {
        wprism_check_same($expected, $parameters($reflection->getMethod($method)), "$class::$method() keeps its reviewed parameter boundary");
    }
}

$scanner = file_get_contents($root . '/sandbox/tests/offline/policy/regress_manifest_validate.sh');
wprism_check(is_string($scanner), 'regress_manifest_validate.sh is readable');
foreach ([
    'LifecyclePlanner.php:baseline_bytes' => 'planner JSON encoding',
    'CodeLifecycleObservation.php:read_unlocked_options' => 'direct lifecycle option observation',
    'CodeBaselinePublication.php:publish_terminal' => 'terminal baseline publication',
    'CodeBaselineTransaction.php:lock_current' => 'locked lifecycle and ledger boundary',
] as $entry => $owner) {
    wprism_check(
        str_contains((string) $scanner, $entry),
        "WordPress-reach review explicitly names the $owner owner ($entry)"
    );
}
foreach ([
    'LifecyclePlanner.php:code_mismatch',
    'LifecyclePlanner.php:code_version_observation',
    'LifecyclePlanner.php:check_theme_range',
] as $stale) {
    wprism_check(
        !str_contains((string) $scanner, $stale),
        "WordPress-reach review contains no stale deferred owner $stale"
    );
}

wprism_check_summary('LifecyclePlanner architecture regression');

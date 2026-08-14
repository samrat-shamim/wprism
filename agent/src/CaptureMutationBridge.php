<?php
declare(strict_types=1);

namespace Duo;

require_once __DIR__ . '/CaptureMutationPort.php';
require_once __DIR__ . '/Code.php';
require_once __DIR__ . '/Deploy.php';

/** Composition-root adapter from Capture's port to mutation-owned services. */
final class CaptureMutationBridge implements CaptureMutationPort {
    public function completeInitialCodeBaseline(string $repo, CompiledRepository $compiled): array {
        return Code::complete_initial_baseline_in_active_transaction($repo, $compiled);
    }

    public function recordCodeVersions(Policy $policy): void {
        Deploy::record_code_versions($policy);
    }
}

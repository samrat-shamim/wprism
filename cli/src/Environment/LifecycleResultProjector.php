<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/**
 * Presentation-only projection for the established lifecycle receipt. It
 * never infers readiness or success; the phase machine has already produced
 * the receipt and this class only renders its existing fields.
 */
final class LifecycleResultProjector
{
    /** @param array<string,mixed> $receipt */
    public static function render(array $receipt, bool $json, string $action): void
    {
        if ($json) {
            echo json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
            return;
        }
        echo 'environment ' . $action . ' complete: operation=' . ($receipt['operation_id'] ?? '?')
            . ' resource=' . ($receipt['resource_id'] ?? '?') . "\n";
        if ($action === 'materialize') {
            echo 'mode=' . ($receipt['mode'] ?? '?')
                . ' branch=' . ($receipt['branch_commit'] ?? '?')
                . ' snapshot=' . ($receipt['snapshot_set_id'] ?? '?') . "\n";
            echo 'release code=' . ($receipt['code_revision'] ?? '?')
                . ' state=' . ($receipt['state_revision'] ?? '?')
                . ' outer=' . ($receipt['outer_artifact_hash'] ?? '?') . "\n";
            echo 'url=' . ($receipt['url'] ?? '?')
                . ' expires_at=' . ($receipt['expires_at'] ?? 'none') . "\n";
        } else {
            echo 'disposition=' . ($receipt['disposition'] ?? '?')
                . ' absence_proof=' . ($receipt['absence_proof_sha256'] ?? '?') . "\n";
        }
        echo 'receipt=' . ($receipt['receipt_sha256'] ?? '?') . "\n";
    }
}

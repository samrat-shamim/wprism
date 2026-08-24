<?php
namespace Duo;

require_once __DIR__ . '/ScopedApplyCoordinator.php';
require_once __DIR__ . '/ScopedApplySession.php';
require_once __DIR__ . '/../Policy/Policy.php';
if (!class_exists(CompiledRepository::class, false)) {
    require_once __DIR__ . '/../Repository/CompiledArtifact.php';
}

/** Mutable state and protocol operations for one bounded apply workflow. */
final class ScopedApplyWorkflow {
    public ?array $scopeContract = null;
    public ?array $observation = null;
    public ?ScopedApplySession $session = null;
    public ?array $promotionWitness = null;
    public ?ScopedApplySession $terminalSessionToArchive = null;

    public function authority(
        array $plan,
        array $work,
        array $deleteWork,
        array $negotiation,
        CompiledRepository $compiled,
        bool $allowDeletes,
        array $selectedActions,
        string $promotionOwner,
        string $promotionArtifact
    ): array {
        if ($this->scopeContract === null || $this->observation === null) {
            throw new \RuntimeException('duo: scoped mutation authority has no complete source/target evidence');
        }
        return ScopedApplyCoordinator::authority(
            $plan,
            $work,
            $deleteWork,
            $negotiation,
            $compiled,
            $allowDeletes,
            $this->scopeContract,
            $this->observation,
            $selectedActions,
            $promotionOwner,
            $promotionArtifact,
            $this->promotionWitness
        );
    }

    /** @return ?list<string> */
    public function ledger_map_identity_hashes(): ?array {
        return ScopedApplyCoordinator::session_ledger_map_identity_hashes($this->session);
    }

    public function allows_target_old_menu_items(array $actual): bool {
        return ScopedApplyCoordinator::allows_target_old_menu_items(
            $this->session,
            $this->scopeContract,
            $actual
        );
    }

    /** Re-gate any normal retained session before surfacing observation drift. */
    public function recheck_target_observation(callable $observer): array {
        try {
            $result = $observer();
            if (!is_array($result)) {
                throw new \RuntimeException('duo: scoped target observation returned a malformed result');
            }
            return $result;
        } catch (\Throwable $failure) {
            $this->recover_once('duo:scoped-target-observation-failed');
            throw $failure;
        }
    }

    public function guard_witnesses_hash(array $deleteWork): string {
        return ScopedApplyCoordinator::guard_witnesses_hash($deleteWork);
    }

    public function assert_recovery_selection(array $selectedActions, array $negotiation): void {
        if ($this->session === null) {
            throw new \RuntimeException('duo: scoped action recovery has no durable authority');
        }
        try {
            ScopedApplyCoordinator::assert_recovery_selection($this->session, $selectedActions, $negotiation);
        } catch (\RuntimeException $failure) {
            $this->recover_once('duo:scoped-action-capability-drift');
            throw $failure;
        }
    }

    /**
     * Recheck the phase-appropriate authored boundary while a retained
     * recovery witness is still durable. Normal nonterminal retries use this
     * same path, so a crash immediately after resume cannot bypass it.
     */
    public function assert_authored_recovery_boundary(
        string $authoredState,
        array $authorIntent,
        array $observation,
        string $currentPlanPreconditionHash,
        string $currentGuardWitnessesHash
    ): string {
        if ($this->session === null) {
            throw new \RuntimeException('duo: scoped authored recovery has no durable authority');
        }
        $recordedPhase = $this->session->recorded_recovery_phase();
        $effectivePhase = $recordedPhase ?? $this->session->phase();
        $preAuthor = in_array($effectivePhase, [
            ScopedApplySession::PHASE_PLANNED,
            ScopedApplySession::PHASE_AUTHORING,
        ], true);
        if (!$preAuthor && !in_array($effectivePhase, [
            ScopedApplySession::PHASE_AUTHORED_COMMITTED,
            ScopedApplySession::PHASE_EFFECTS_PENDING,
            ScopedApplySession::PHASE_VERIFYING,
        ], true)) {
            $this->recover_once('duo:scoped-authored-phase-invalid');
            throw new \RuntimeException('duo: scoped apply recovery has no resumable authored boundary');
        }

        $authority = $this->session->authority();
        $intents = $this->session->intents();
        $existingIntent = $intents[0] ?? null;
        if (is_array($existingIntent)
            && hash_equals(
                hash('sha256', 'duo-scoped-authored-transaction/v1'),
                (string) ($existingIntent['action_hash'] ?? '')
            )) {
            $this->recover_once('duo:scoped-obsolete-author-evidence');
            throw new \RuntimeException(
                'duo: scoped apply recovery found obsolete v1 author evidence; '
                . 'restore the retained checkpoint or start a fresh scoped apply before target effects'
            );
        }
        if ($preAuthor && $authoredState === 'before') {
            if (!hash_equals(
                (string) ($authority['target']['selected_before_ledger_map_hash'] ?? ''),
                (string) ($observation['selected_ledger_map_root'] ?? '')
            )) {
                $this->recover_once('duo:scoped-selected-ledger-drift');
                throw new \RuntimeException(
                    'duo: scoped apply recovery found selected identity-map drift before authored mutation'
                );
            }
            if (!hash_equals(
                (string) ($authority['plan']['precondition_hash'] ?? ''),
                $currentPlanPreconditionHash
            ) || !hash_equals(
                (string) ($authority['plan']['guard_witnesses_hash'] ?? ''),
                $currentGuardWitnessesHash
            )) {
                $this->recover_once('duo:scoped-plan-or-guard-drift');
                throw new \RuntimeException(
                    'duo: scoped apply recovery found changed locked plan or deletion-guard evidence'
                );
            }
            return $effectivePhase;
        }

        if ($preAuthor && $authoredState === 'desired') {
            if ($effectivePhase !== ScopedApplySession::PHASE_PLANNED
                || $existingIntent !== null
                || $this->receipt_at(1) !== null) {
                $this->recover_once('duo:scoped-unreceipted-authored-state');
                throw new \RuntimeException(
                    'duo: scoped apply recovery found desired authored state without its atomic author receipt; '
                    . 'restore the retained checkpoint before retrying'
                );
            }
            if (!hash_equals(
                (string) ($authority['target']['selected_before_hash'] ?? ''),
                (string) ($observation['selected_before_root'] ?? '')
            ) || !hash_equals(
                (string) ($authority['target']['selected_before_ledger_map_hash'] ?? ''),
                (string) ($observation['selected_ledger_map_root'] ?? '')
            ) || !hash_equals(
                (string) ($authority['plan']['precondition_hash'] ?? ''),
                $currentPlanPreconditionHash
            ) || !hash_equals(
                (string) ($authority['plan']['guard_witnesses_hash'] ?? ''),
                $currentGuardWitnessesHash
            )) {
                $this->recover_once('duo:scoped-noop-authority-drift');
                throw new \RuntimeException(
                    'duo: scoped apply no-op recovery no longer matches its exact pre-author state, map, plan, and guards'
                );
            }
            return $effectivePhase;
        }

        if ($authoredState !== 'desired') {
            $cause = $preAuthor
                ? 'duo:scoped-authored-boundary-mixed'
                : 'duo:scoped-authored-state-regressed';
            $this->recover_once($cause);
            throw new \RuntimeException($preAuthor
                ? 'duo: scoped apply recovery found a mixed authored boundary; no replay was attempted'
                : 'duo: scoped apply recovery found selected target drift after authored commit');
        }

        try {
            $authoredReadbackHash = ScopedApplyCoordinator::authored_ledger_map_hash($observation);
        } catch (\RuntimeException $failure) {
            $this->recover_once('duo:scoped-authored-receipt-drift');
            throw $failure;
        }
        $expectedReceipt = $this->receipt($authorIntent, $authoredReadbackHash);
        if ($existingIntent === null
            || Canon::encode((array) $existingIntent) !== Canon::encode($authorIntent)) {
            $this->recover_once('duo:scoped-authored-intent-drift');
            throw new \RuntimeException('duo: scoped apply recovery author intent no longer matches');
        }
        $existingReceipt = $this->receipt_at(1);
        if ($existingReceipt === null
            || Canon::encode($existingReceipt) !== Canon::encode($expectedReceipt)) {
            $this->recover_once('duo:scoped-authored-receipt-drift');
            throw new \RuntimeException(
                'duo: scoped apply recovery author receipt does not match selected state and identity map'
            );
        }
        return $effectivePhase;
    }

    public function intent(
        int $ordinal,
        string $actionIdentity,
        string $operationIdentity,
        string $inputHash,
        string $effectHash,
        string $beforeHash
    ): array {
        if ($this->session === null) {
            throw new \RuntimeException('duo: scoped mutation intent has no durable session');
        }
        return ScopedApplyCoordinator::intent(
            $this->session,
            $ordinal,
            $actionIdentity,
            $operationIdentity,
            $inputHash,
            $effectHash,
            $beforeHash
        );
    }

    public function receipt(array $intent, string $afterHash): array {
        return ScopedApplyCoordinator::receipt($intent, $afterHash);
    }

    public function receipt_at(int $ordinal): ?array {
        return ScopedApplyCoordinator::receipt_at($this->session, $ordinal);
    }

    public function core_readback_hash(Policy $policy, array $work, array $tree): string {
        return ScopedApplyCoordinator::core_readback_hash($policy, $work, $tree);
    }

    private function recover_once(string $cause): void {
        if ($this->session !== null && !$this->session->is_recovery_required()) {
            $this->session->recover(hash('sha256', $cause));
        }
    }

}

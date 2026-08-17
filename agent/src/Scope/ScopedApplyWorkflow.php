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

    public function guard_witnesses_hash(array $deleteWork): string {
        return ScopedApplyCoordinator::guard_witnesses_hash($deleteWork);
    }

    public function assert_recovery_selection(array $selectedActions, array $negotiation): void {
        if ($this->session === null) {
            throw new \RuntimeException('duo: scoped action recovery has no durable authority');
        }
        ScopedApplyCoordinator::assert_recovery_selection($this->session, $selectedActions, $negotiation);
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

}

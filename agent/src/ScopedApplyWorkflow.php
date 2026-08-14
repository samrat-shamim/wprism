<?php
namespace Duo;

require_once __DIR__ . '/ScopedApplyCoordinator.php';
require_once __DIR__ . '/ScopedApplySession.php';
require_once __DIR__ . '/Policy.php';
if (!class_exists(CompiledRepository::class, false)) {
    require_once __DIR__ . '/CompiledArtifact.php';
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

    /** @return list<string> */
    public function observation_ledger_map_identity_hashes(): array {
        if ($this->observation === null) {
            throw new \RuntimeException('duo: scoped mutation authority has no ledger-map identity observation');
        }
        return ScopedApplyCoordinator::observation_ledger_map_identity_hashes($this->observation);
    }

    /** @return list<string> */
    public function assert_ledger_map_identity_hashes(mixed $hashes, string $source): array {
        return ScopedApplyCoordinator::assert_ledger_map_identity_hashes($hashes, $source);
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

    public function action_effect_hash(array $action): string {
        return ScopedApplyCoordinator::action_effect_hash($action);
    }

    public function effect_operation(int $ordinal, string $inputHash, string $effectHash): array {
        if ($this->session === null) {
            throw new \RuntimeException('duo: scoped effect has no durable session');
        }
        return ScopedApplyCoordinator::effect_operation($this->session, $ordinal, $inputHash, $effectHash);
    }

    public function assert_effect_result(
        array $result,
        array $operation,
        string $capabilityDigest
    ): void {
        ScopedApplyCoordinator::assert_effect_result($result, $operation, $capabilityDigest);
    }

    public function public_action_receipt(
        string $source,
        string $kind,
        array $operation,
        string $capabilityDigest,
        ?array $receipt,
        string $status = 'verified'
    ): array {
        return ScopedApplyCoordinator::public_action_receipt(
            $source,
            $kind,
            $operation,
            $capabilityDigest,
            $receipt,
            $status
        );
    }

    public function receipt_at(int $ordinal): ?array {
        return ScopedApplyCoordinator::receipt_at($this->session, $ordinal);
    }

    public function core_readback_hash(Policy $policy, array $work, array $tree): string {
        return ScopedApplyCoordinator::core_readback_hash($policy, $work, $tree);
    }

    public function archive_terminal_candidate(): void {
        if ($this->terminalSessionToArchive === null) {
            return;
        }
        $this->terminalSessionToArchive->archive_terminal();
        $this->terminalSessionToArchive = null;
    }
}

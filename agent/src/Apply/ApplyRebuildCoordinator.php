<?php
namespace WPrism;

require_once __DIR__ . '/ApplyServices.php';
require_once __DIR__ . '/../Rebuild/RebuildRequest.php';
require_once __DIR__ . '/../Rebuild/RebuildSelection.php';

/** Orchestrates derived-state repair after the authored transaction commits. */
final class ApplyRebuildCoordinator {
    public function __construct(
        private readonly ApplyServices $services,
        private readonly RebuildSelection $selection
    ) {}

    /**
     * @param string[] $warnings
     * @param list<array<string,mixed>> $actionReceipts
     */
    public function rebuild(
        RebuildRequest $request,
        array &$warnings,
        array &$actionReceipts
    ): void {
        $appliedDeletions = array_merge(
            $request->withDeletes ? $request->deleteWork : [],
            $request->retryingIncompleteApply ? $request->absentTombstones : []
        );
        $durableReparents = $request->scoped
            ? []
            : $this->services->regeneration_context_store()->durable_reparents();
        $durableDeletions = $request->scoped
            ? []
            : $this->services->regeneration_context_store()->durable_deletions();

        try {
            if (!$request->scoped) {
                $this->services->dependency_regenerator()->run(
                    $request->work,
                    $request->tree,
                    $request->regenerationContext,
                    $warnings
                );
            }

            if (!$request->scoped || !$request->skipScopedCore) {
                $this->services->native_rebuild_executor()->run(
                    $request->attachmentIds,
                    $request->work,
                    $request->tree,
                    $appliedDeletions,
                    $request->suppressScopedExternalEffects
                );
                if ($request->scoped && $request->scopedCoreComplete !== null) {
                    ($request->scopedCoreComplete)();
                }
            }

            $this->services->rebuild_action_dispatcher()->dispatch(
                $this->selection->selected_actions(),
                (array) $this->selection->negotiated_providers(),
                $request->work,
                $request->tree,
                $appliedDeletions,
                $request->regenerationContext,
                $durableReparents,
                $durableDeletions,
                $request->retryingIncompleteApply,
                $request->scoped,
                $request->scopedSession,
                $request->scopedObservation,
                $warnings,
                $actionReceipts
            );
        } finally {
            $this->services->attachment_materializer()->discard_native_rebuild_authority();
        }
    }
}

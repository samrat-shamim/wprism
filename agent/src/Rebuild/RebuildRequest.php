<?php
namespace WPrism;

require_once __DIR__ . '/../Scope/ScopedApplySession.php';

/** Named inputs for the post-transaction derived-state rebuild phase. */
final class RebuildRequest {
    public function __construct(
        public readonly array $attachmentIds,
        public readonly array $work,
        public readonly array $tree,
        public readonly array $regenerationContext,
        public readonly array $deleteWork,
        public readonly bool $withDeletes,
        public readonly array $absentTombstones,
        public readonly bool $retryingIncompleteApply,
        public readonly bool $scoped,
        public readonly bool $skipScopedCore,
        public readonly ?\Closure $scopedCoreComplete,
        public readonly bool $suppressScopedExternalEffects,
        public readonly ?ScopedApplySession $scopedSession,
        public readonly ?array $scopedObservation
    ) {}
}

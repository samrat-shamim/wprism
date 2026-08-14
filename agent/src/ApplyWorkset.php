<?php
namespace Duo;

/** Immutable authored-state inputs selected by apply preparation. */
final class ApplyWorkset {
    public function __construct(
        public readonly array $plan,
        public readonly array $tree,
        public readonly array $work,
        public readonly array $deleteWork,
        public readonly array $deleteUuids,
        public readonly array $guardRepairUuids,
        public readonly array $compiledDeletions
    ) {}
}

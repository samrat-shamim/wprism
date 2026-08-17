<?php
namespace Duo;

/** Immutable, fully rechecked authority inputs handed to mutation execution. */
final readonly class PreparedApply {
    public function __construct(
        public array $freshPlan,
        public array $work,
        public array $deleteWork,
        public array $rebuildDeleteWork,
        public array $deleteUuids,
        public array $guardRepairUuids,
        public array $negotiation,
        public bool $executeDeletes,
        public ?int $defaultAuthor,
        /** @var array<string,mixed>|null */
        public ?array $freshActual
    ) {}
}

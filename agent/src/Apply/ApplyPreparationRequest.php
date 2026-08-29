<?php
namespace WPrism;

if (!class_exists(CompiledRepository::class, false)) {
    require_once __DIR__ . '/../Repository/CompiledArtifact.php';
}

/** Immutable inputs for the pre-mutation apply boundary. */
final readonly class ApplyPreparationRequest {
    public function __construct(
        public array $options,
        public CompiledRepository $compiled,
        public array $plan,
        public array $tree,
        public bool $scoped,
        public bool $scopedPromotion,
        public bool $recoveringScoped,
        public bool $retryingIncompleteApply,
        public string $promotionOwner,
        public string $promotionArtifact
    ) {}
}

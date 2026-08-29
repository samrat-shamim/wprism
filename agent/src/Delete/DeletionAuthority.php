<?php
namespace WPrism;

/** Named deletion permissions; none of these booleans are interchangeable. */
final class DeletionAuthority {
    public function __construct(
        public readonly bool $execute,
        public readonly bool $withDeletes,
        public readonly bool $forceReferenced
    ) {}
}

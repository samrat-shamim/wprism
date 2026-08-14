<?php
declare(strict_types=1);

namespace Duo;

/**
 * Capture-facing mutation boundary.
 *
 * Capture owns observation and atomic repository publication. Code lifecycle
 * markers are target mutation, so the agent composition root supplies this
 * port instead of Capture importing Code, Deploy, or lifecycle internals.
 */
interface CaptureMutationPort {
    /** @return array{enabled:bool,completed:bool,code_revision:?string,files:int} */
    public function completeInitialCodeBaseline(string $repo, CompiledRepository $compiled): array;

    public function recordCodeVersions(Policy $policy): void;
}

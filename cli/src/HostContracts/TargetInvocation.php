<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/** Minimal command transport consumed by target workflows. */
interface TargetInvocation {
    public function name(): string;
    public function repoPath(): string;

    /** @return array{exit:int,stdout:string,stderr:string} */
    public function captureRaw(string $script): array;

    /** @return array{exit:int,stdout:string,stderr:string} */
    public function captureWp(array $wpArgs): array;

    public function streamWp(array $wpArgs): int;
    public function wpInstruction(array $wpArgs): string;
}

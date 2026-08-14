<?php
declare(strict_types=1);

namespace Duo;

/** Capability-side port for ephemeral target runtime facts. */
interface TargetRuntimeInspectionPort {
    public function available(): bool;

    /** @return array{installed:bool,active:bool,version:string} */
    public function plugin(string $basename): array;

    public function wordpressVersion(): string;
    public function phpVersion(): string;
}

<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/** Injectable host output boundary for extracted command handlers. */
interface CommandOutputPort {
    public function stdout(string $bytes): void;
    public function stderr(string $bytes): void;
}

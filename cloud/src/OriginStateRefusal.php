<?php
declare(strict_types=1);

namespace Duo\Cloud;

/** An authenticated authority-state failure that is safe to sign for a caller. */
final class OriginStateRefusal extends \RuntimeException {
    public function __construct(
        private readonly string $reasonCode,
        private readonly bool $retryable = false
    ) {
        parent::__construct($reasonCode);
    }

    public function reasonCode(): string {
        return $this->reasonCode;
    }

    public function retryable(): bool {
        return $this->retryable;
    }
}

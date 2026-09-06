<?php
declare(strict_types=1);

namespace WPrism;

/**
 * Typed engine refusals keep RuntimeException's public contract while retaining
 * private causes. Final methods are deliberate: evidence traversal must not
 * dispatch adapter-defined callbacks after its database authority has ended.
 */
abstract class PrivateEvidenceCarrierException extends \RuntimeException {
    /** @var list<\Throwable> */
    private array $privateEvidenceCauses = [];

    /** Bind once, without replacing a typed refusal or its standard previous. */
    final public function retain_private_evidence(
        \Throwable $cause,
        \Throwable ...$additionalCauses
    ): void {
        if ($this->privateEvidenceCauses !== []) {
            throw new \LogicException('private exception evidence is already bound');
        }
        $this->privateEvidenceCauses = [$cause, ...$additionalCauses];
    }

    /** @return list<\Throwable> */
    final public function private_evidence_causes(): array {
        return $this->privateEvidenceCauses;
    }
}

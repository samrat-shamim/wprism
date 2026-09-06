<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/PrivateEvidenceCarrierException.php';

/**
 * Stable public/operator sentence with a cause reserved for private evidence.
 *
 * Passing an opaque provider throwable as RuntimeException::$previous makes
 * PHP's default string rendering include its message and trace. Provider
 * failures may contain credentials, payload values, or local paths, so that
 * standard chain is not a safe diagnostic channel. The CLI refusal recorder
 * explicitly follows private_evidence_causes() only while writing a 0600
 * `.wprism/refusals/` record; ordinary getMessage(), getPrevious(), and
 * Throwable string rendering retain only the reviewed wrapper sentence.
 */
final class PrivateEvidenceException extends PrivateEvidenceCarrierException {
    public function __construct(
        string $message,
        \Throwable $privateEvidenceCause,
        \Throwable ...$additionalPrivateEvidenceCauses
    ) {
        $this->retain_private_evidence($privateEvidenceCause, ...$additionalPrivateEvidenceCauses);
        parent::__construct($message);
    }
}

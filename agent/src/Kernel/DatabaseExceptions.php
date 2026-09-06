<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/PrivateEvidenceCarrierException.php';

/** A checked database mutation failed without exposing rendered SQL values. */
final class DatabaseMutationException extends \RuntimeException {
    public string $mutationContext;

    public function __construct(string $context, ?\Throwable $previous = null) {
        $this->mutationContext = $context;
        parent::__construct("wprism: database mutation failed: $context", 0, $previous);
    }
}

/** A transaction crossed a boundary whose durable outcome is not provable. */
final class DatabaseTransactionOutcomeException extends \RuntimeException {
    public string $transactionContext;

    public function __construct(string $context, ?\Throwable $previous = null) {
        $this->transactionContext = $context;
        parent::__construct(
            "wprism: database transaction outcome is uncertain: $context; recovery_required",
            0,
            $previous
        );
    }
}

/** WordPress's mutable query-hook topology changed inside an isolated boundary. */
final class DatabaseQueryIsolationViolationException extends PrivateEvidenceCarrierException {}

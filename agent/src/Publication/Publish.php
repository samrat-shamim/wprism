<?php
namespace Duo;

// Keep the historical declaration/load path stable for CLI refusal audits and
// existing integrations while the implementation lives in PublicationJournal.
if (!class_exists(InitialStateBoundaryException::class, false)) {
    final class InitialStateBoundaryException extends \RuntimeException {}
}

require_once __DIR__ . '/AtomicTreePublisher.php';

/**
 * Backward-compatible publication facade.
 *
 * New code should depend on PublicationJournal (or its AtomicTreePublisher
 * facade). This class preserves the
 * historical Publish::* static API and the protected readback hook used by
 * existing offline mutation tests.
 */
final class Publish extends AtomicTreePublisher {
    protected static function read_record(string $path, string $label): ?array {
        return PublicationJournal::read_record($path, $label);
    }
}

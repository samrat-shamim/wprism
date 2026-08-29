<?php
namespace WPrism;

/**
 * Compatibility facade for the publication engine's historical service name.
 * The durable record/tree protocol is implemented by PublicationJournal;
 * this forwarding facade keeps every existing AtomicTreePublisher::* call
 * byte-stable without putting protocol state back in the facade.
 */
require_once __DIR__ . '/PublicationJournal.php';

class AtomicTreePublisher {
    /** Preserve the historical static engine entry point. */
    public static function recover(string $stateDir, ?callable $commitStatus = null): array {
        return PublicationJournal::recoverProtocol($stateDir, $commitStatus);
    }

    /** Forward every other historical static primitive to the journal owner. */
    public static function __callStatic(string $name, array $arguments): mixed {
        return PublicationJournal::$name(...$arguments);
    }
}

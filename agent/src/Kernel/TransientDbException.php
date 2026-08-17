<?php
namespace Duo;

/**
 * Signals a transient (retryable) SQL failure — a deadlock or lock-wait
 * timeout caught at one of Capture's own write checkpoints (DUO-3213's
 * "documented retry": a concurrent, ordinary WordPress write colliding
 * with capture's own identity-minting writes). Caught ONLY by Capture's own
 * retry loop, which rolls back and retries the whole consistent-snapshot
 * transaction; never meant to escape to wp-cli or a caller.
 */
final class TransientDbException extends \RuntimeException {}

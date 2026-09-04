<?php
namespace WPrism;

/**
 * Signals transient database contention caught at one of Capture's own write
 * checkpoints (issue #3213's
 * "documented retry": a concurrent, ordinary WordPress write colliding
 * with capture's own identity-minting writes). Caught ONLY by Capture's own
 * retry loop, which proves whether the server preserved or already rolled
 * back the whole consistent-snapshot transaction before retrying; never meant
 * to escape to wp-cli or a caller.
 */
class TransientDbException extends \RuntimeException {}

/**
 * InnoDB error 1213 ends the whole transaction before control returns. It is
 * still retryable, but rollback_after_failure() must prove that server-aborted
 * state rather than issuing a second rollback against an inactive boundary.
 */
final class DeadlockTransactionAbortedException extends TransientDbException {}

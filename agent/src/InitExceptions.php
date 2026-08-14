<?php
namespace Duo;

/**
 * Signals that an init-owned artifact could not be safely compensated in the
 * current process. The sealed attempt journal and canonical capture lock must
 * remain in place so a fresh process can verify and recover the exact tuple.
 */
final class InitAttemptRetentionException extends \RuntimeException {}

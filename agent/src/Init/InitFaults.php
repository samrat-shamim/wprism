<?php
namespace Duo;

/** Test-only process fault checkpoints for first-init crash evidence. */
final class InitFaults {
    public static function checkpoint(string $phase): void {
        if (getenv('DUO_TEST_MODE') !== '1'
            || (string) getenv('DUO_TEST_INIT_KILL_PHASE') !== $phase) {
            return;
        }
        if (function_exists('posix_kill')) {
            @posix_kill(getmypid(), defined('SIGKILL') ? SIGKILL : 9);
        }
        exit(137);
    }
}

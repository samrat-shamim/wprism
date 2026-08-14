<?php
declare(strict_types=1);

namespace Duo;

/** Capture-owned WordPress execution adapter. */
final class CaptureWordPressExecution {
    private static bool $cronSuppressed = false;

    /** Prevent a Duo read/capture bootstrap from spawning wp-cron. */
    public static function suppressCronSpawn(): void {
        if (self::$cronSuppressed) {
            return;
        }
        self::$cronSuppressed = true;
        add_filter('pre_http_request', static function ($preempt, $parsedArgs, $url) {
            return is_string($url) && str_contains($url, '/wp-cron.php')
                ? new \WP_Error(
                    'duo_cron_suppressed',
                    'duo: suppressed a wp-cron spawn triggered by a duo command'
                )
                : $preempt;
        }, 10, 3);
    }
}

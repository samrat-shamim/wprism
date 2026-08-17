<?php
namespace Duo;

/**
 * Side-effect canary, armed during apply: any content-CRUD hook fire, wp_mail
 * attempt, or outbound HTTP request is a hard failure ("no mails fired" alone
 * is trivially true under direct SQL — the hook assertion is the real claim).
 * Mail/HTTP are short-circuited while armed so nothing escapes even on a bug.
 * Deploy uses a separate reporting-only external observer below; it never sets
 * $armed and therefore cannot weaken or overload this apply invariant.
 */
final class Canary {
    private static bool $armed = false;
    private static bool $registered = false;
    private static bool $cronSuppressed = false;
    private static bool $externalObserverActive = false;
    private static bool $externalObserverRegistered = false;
    /** @var string[] */
    private static array $violations = [];
    /** @var string[] */
    private static array $externalObservations = [];

    public const HOOKS = ['save_post', 'transition_post_status', 'created_term', 'wp_insert_comment'];

    public static function arm(): void {
        self::$violations = [];
        self::$armed = true;
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        foreach (self::HOOKS as $hook) {
            add_action($hook, function () use ($hook) {
                if (self::$armed) {
                    self::$violations[] = "hook fired: $hook";
                }
            }, -2147483646);
        }
        add_filter('pre_wp_mail', function ($short, $atts) {
            if (self::$armed) {
                self::$violations[] = 'wp_mail attempted: ' . (is_array($atts) ? ($atts['subject'] ?? '') : '');
                return true; // swallow the mail
            }
            return $short;
        }, -2147483646, 2);
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (self::$armed) {
                self::$violations[] = "http request attempted: $url";
                return new \WP_Error('duo_canary', 'duo canary blocked outbound http during apply');
            }
            return $pre;
        }, -2147483646, 3);
    }

    public static function disarm(): void {
        self::$armed = false;
    }

    /** @return string[] */
    public static function violations(): array {
        return self::$violations;
    }

    /**
     * Observe deploy's deliberately hook-firing lifecycle window without
     * changing the apply canary's fixed meaning. These filters never
     * short-circuit mail/HTTP and their findings are reporting-only: plugin
     * activation is allowed to perform its normal migrations and external
     * calls, but the promotion evidence names every attempted escape.
     */
    public static function begin_external_observation(): void {
        self::$externalObservations = [];
        self::$externalObserverActive = true;
        if (self::$externalObserverRegistered) {
            return;
        }
        self::$externalObserverRegistered = true;
        add_filter('pre_wp_mail', function ($short, $atts) {
            if (self::$externalObserverActive) {
                self::$externalObservations[] = 'wp_mail attempted: '
                    . (is_array($atts) ? ($atts['subject'] ?? '') : '');
            }
            return $short;
        }, -2147483647, 2);
        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (self::$externalObserverActive) {
                self::$externalObservations[] = "http request attempted: $url";
            }
            return $pre;
        }, -2147483647, 3);
    }

    /** @return string[] */
    public static function end_external_observation(): array {
        self::$externalObserverActive = false;
        return self::$externalObservations;
    }

    /**
     * Every WordPress bootstrap — wp-cli's included — fires 'init', where core
     * decides whether scheduled cron events are due (wp-includes/cron.php
     * wp_cron()); if so it defers to 'shutdown' and dispatches a non-blocking
     * HTTP POST to this site's own wp-cron.php (spawn_cron()). None of duo's
     * own commands (capture/plan/apply) should be the trigger for that
     * background side-effect request — the same posture as the apply canary,
     * extended to the command's own bootstrap. Suppressed for the life of
     * this process only; a later real visitor request spawns cron normally.
     * (History: this was once suspected of causing a transient wrong render
     * after apply; that symptom was later root-caused to a test-harness
     * `curl | grep -q` EPIPE-under-pipefail bug, not to cron or any engine
     * write. The suppression stays on its own merits.)
     */
    public static function suppress_cron_spawn(): void {
        if (self::$cronSuppressed) {
            return;
        }
        self::$cronSuppressed = true;
        add_filter('pre_http_request', function ($preempt, $parsed_args, $url) {
            return str_contains($url, '/wp-cron.php')
                ? new \WP_Error('duo_cron_suppressed', 'duo: suppressed a wp-cron spawn triggered by a duo command')
                : $preempt;
        }, 10, 3);
    }
}

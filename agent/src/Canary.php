<?php
namespace Duo;

/**
 * Side-effect canary, armed during apply: any content-CRUD hook fire, wp_mail
 * attempt, or outbound HTTP request is a hard failure ("no mails fired" alone
 * is trivially true under direct SQL — the hook assertion is the real claim).
 * Mail/HTTP are short-circuited while armed so nothing escapes even on a bug.
 */
final class Canary {
    private static bool $armed = false;
    private static bool $registered = false;
    /** @var string[] */
    private static array $violations = [];

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
}

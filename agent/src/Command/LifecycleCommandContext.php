<?php
declare(strict_types=1);

namespace WPrism;

/** Establish native lifecycle registration before normal/network plugin loading. */
final class LifecycleCommandContext {
    private static bool $prepared = false;
    private static bool $skippedParticipants = false;

    /**
     * @param list<string> $command WP-CLI's parsed command words, without global options.
     * @param array<string,mixed> $config WP-CLI's parsed global options.
     */
    public static function bootstrap(array $command, array $config): void {
        if (array_slice($command, 0, 2) !== ['wprism', 'deploy']) {
            return;
        }
        foreach (['skip-plugins', 'skip-themes'] as $option) {
            if (!in_array($config[$option] ?? '', ['', false], true)) {
                self::$skippedParticipants = true;
                return;
            }
        }
        // Importer 2.7.5 returns at main-file:20 unless is_admin(). Changing
        // context inside LifecycleExecutor is too late for active plugins to
        // register their deactivation hooks. The MU bootstrap precedes both
        // network and ordinary plugins, and WordPress's vars.php entry setup.
        if ((function_exists('did_action') && did_action('muplugins_loaded') > 0)
            || (defined('WP_ADMIN') && WP_ADMIN !== true)) {
            return;
        }
        if (!defined('WP_ADMIN')) {
            define('WP_ADMIN', true);
            $_SERVER['PHP_SELF'] = '/wp-admin/wprism-deploy.php';
        }
        self::$prepared = true;
    }

    public static function assert_ready(): void {
        if (self::$skippedParticipants) {
            throw new \RuntimeException(
                'wprism: deploy requires all lifecycle participants to load; '
                . 'remove --skip-plugins and --skip-themes and retry in a fresh WordPress process'
            );
        }
        if (!self::$prepared || !defined('WP_ADMIN') || WP_ADMIN !== true || !is_admin()) {
            throw new \RuntimeException(
                'wprism: deploy requires administrative context from the MU bootstrap before plugins load; '
                . 'remove any WP_ADMIN=false override and retry in a fresh WordPress process'
            );
        }
    }
}

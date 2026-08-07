<?php
/*
Plugin Name: Duo Ecosystem Base
Version: 1.0.0
*/

final class DuoEcosystemBase {
    public static function trace(string $event): void {
        $trace = get_option('duo_ecosystem_trace', []);
        $trace = is_array($trace) ? $trace : [];
        $trace[] = $event;
        update_option('duo_ecosystem_trace', $trace, false);
    }
}

register_activation_hook(__FILE__, static function (): void {
    DuoEcosystemBase::trace('activate:base-v1');
});

register_deactivation_hook(__FILE__, static function (): void {
    DuoEcosystemBase::trace(
        'deactivate:base-v1:base=' . (is_dir(WP_PLUGIN_DIR . '/duo-ecosystem-base') ? 'present' : 'absent')
        . ':dependent=' . (is_dir(WP_PLUGIN_DIR . '/duo-ecosystem-dependent') ? 'present' : 'absent')
    );
});

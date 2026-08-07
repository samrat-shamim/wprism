<?php
/*
Plugin Name: Duo Ecosystem Replacement
Version: 2.0.1
*/

// This deliberately reuses v1 dependent's symbol. A successful activation
// proves the host ran activation in a fresh process after retirement.
if (class_exists('DuoEcosystemRuntime', false)) {
    throw new RuntimeException('duo ecosystem replacement requires a fresh activation process after retirement');
}

if (!class_exists('DuoEcosystemRuntime', false)) {
    final class DuoEcosystemRuntime {
        public static function trace(string $event): void {
            $trace = get_option('duo_ecosystem_trace', []);
            $trace = is_array($trace) ? $trace : [];
            $trace[] = $event;
            update_option('duo_ecosystem_trace', $trace, false);
        }
    }
}

register_activation_hook(__FILE__, static function (): void {
    DuoEcosystemRuntime::trace('activate:replacement-fixed');
});

register_deactivation_hook(__FILE__, static function (): void {
    DuoEcosystemRuntime::trace(
        'deactivate:replacement-fixed:replacement='
        . (is_dir(WP_PLUGIN_DIR . '/duo-ecosystem-replacement') ? 'present' : 'absent')
    );
});

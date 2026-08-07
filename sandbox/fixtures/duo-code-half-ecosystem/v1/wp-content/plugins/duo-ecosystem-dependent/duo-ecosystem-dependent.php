<?php
/*
Plugin Name: Duo Ecosystem Dependent
Version: 1.0.0
Requires Plugins: duo-ecosystem-base
*/

// This runtime-conditional declaration is intentional: PHP pre-registers an
// unconditional class before executing the preceding statement. The v2
// replacement uses the same shape so its guard can detect a class loaded by
// the retirement process, rather than its own source declaration.
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
    DuoEcosystemRuntime::trace('activate:dependent-v1');
});

register_deactivation_hook(__FILE__, static function (): void {
    DuoEcosystemRuntime::trace(
        'deactivate:dependent-v1:base=' . (is_dir(WP_PLUGIN_DIR . '/duo-ecosystem-base') ? 'present' : 'absent')
        . ':dependent=' . (is_dir(WP_PLUGIN_DIR . '/duo-ecosystem-dependent') ? 'present' : 'absent')
    );
});

<?php
/*
Plugin Name: Duo Ecosystem Replacement
Version: 2.0.0
*/

// This deliberately reuses v1 dependent's symbol. If retirement and
// activation share a PHP process, this fails before the controlled hook
// failure below and makes the missing fresh-process boundary obvious.
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
    DuoEcosystemRuntime::trace('activate:replacement-broken');
    throw new RuntimeException('duo ecosystem reviewed activation failure');
});

register_deactivation_hook(__FILE__, static function (): void {
    DuoEcosystemRuntime::trace('deactivate:replacement-broken');
});

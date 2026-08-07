<?php
/*
Plugin Name: Duo Ecosystem Single File
Version: 1.0.0
*/

function duo_ecosystem_single_trace(string $event): void {
    $trace = get_option('duo_ecosystem_trace', []);
    $trace = is_array($trace) ? $trace : [];
    $trace[] = $event;
    update_option('duo_ecosystem_trace', $trace, false);
}

register_activation_hook(__FILE__, static function (): void {
    duo_ecosystem_single_trace('activate:single-v1');
});

register_deactivation_hook(__FILE__, static function (): void {
    duo_ecosystem_single_trace('deactivate:single-v1');
});

<?php
/*
Plugin Name: Duo First-Sync Hook Probe
Version: 1.0.1
*/

register_activation_hook(__FILE__, static function (): void {
    update_option('duo_first_sync_hook_trace', ['fixed-hook-completed'], false);
});

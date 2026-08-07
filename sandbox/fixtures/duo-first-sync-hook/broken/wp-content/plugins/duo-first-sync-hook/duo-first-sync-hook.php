<?php
/*
Plugin Name: Duo First-Sync Hook Probe
Version: 1.0.0-broken
*/

register_activation_hook(__FILE__, static function (): void {
    // WordPress persists this write immediately. Throwing afterward proves
    // that a hook exception is not a database rollback boundary and that Duo
    // must retain a durable pre-hook ambiguity receipt.
    update_option('blogname', 'hook-mutated-first-sync', false);
    update_option('duo_first_sync_hook_trace', ['broken-hook-wrote'], false);
    throw new RuntimeException('duo first-sync intentional activation failure');
});

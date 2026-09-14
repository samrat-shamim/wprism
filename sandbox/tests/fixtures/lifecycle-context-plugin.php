<?php
declare(strict_types=1);
/* Plugin Name: WPrism lifecycle context fixture
 * Version: 1.0.0
 */
if (!is_admin()) return;
$record = static function (string $phase): void {
    $state = get_option('_wp_session_lifecycle_context_probe', ['activate' => 0, 'deactivate' => 0]);
    $state[$phase]++;
    $state['admin'] = is_admin();
    $state['user'] = get_current_user_id();
    $state['entry'] = $GLOBALS['pagenow'];
    update_option('_wp_session_lifecycle_context_probe', $state);
};
register_activation_hook(__FILE__, static fn() => $record('activate'));
register_deactivation_hook(__FILE__, static fn() => $record('deactivate'));

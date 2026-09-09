<?php
declare(strict_types=1);
global $wpdb;
$rows = $wpdb->get_results("SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE 'aio\\_login%' OR option_name LIKE 'rwl\\_%' ORDER BY option_name",ARRAY_A);
if (!is_array($rows)) throw new RuntimeException('owned options unreadable');
$tables = [];
foreach (['aio_login_enumeration_logs','aio_login_login_attempts','aio_login_login_lockouts','wprism_map','wprism_state','wprism_kv'] as $suffix) {
    $table = $wpdb->prefix . $suffix;
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table))) === $table;
    $data = $exists ? $wpdb->get_results("SELECT * FROM `$table` ORDER BY 1",ARRAY_A) : null;
    if ($exists && !is_array($data)) throw new RuntimeException('owned table unreadable');
    $tables[$suffix] = ['exists'=>$exists,'count'=>$exists ? count($data) : 0,'sha256'=>hash('sha256',wp_json_encode($data))];
}
echo wp_json_encode(['options_sha256'=>hash('sha256',wp_json_encode($rows)), 'option_names'=>array_column($rows,'option_name'), 'tables'=>$tables]);

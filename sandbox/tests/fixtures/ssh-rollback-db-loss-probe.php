#!/usr/bin/env php
<?php
declare(strict_types=1);

// Certification-only target-owned probe: prove a real database connection
// loss without placing credentials in Duo configuration, argv, or output.

$path = $argv[1] ?? '';
if ($path === '' || is_link($path) || !is_file($path)) {
    fwrite(STDERR, "database probe configuration unavailable\n");
    exit(2);
}
$config = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($config)) exit(2);
fwrite(STDOUT, (string) getmypid() . "\n");
fflush(STDOUT);
$pipes = [];
$process = proc_open([
    'mariadb', '--batch', '--skip-column-names', '--protocol=tcp',
    '--host=127.0.0.254', '--port=' . (string) $config['port'],
    '--user=' . (string) $config['user'], '--connect-timeout=1',
    (string) $config['database'], '--execute=SELECT 1',
], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, null, [
    'MYSQL_PWD'=>(string)$config['password'], 'PATH'=>(string)getenv('PATH'),
], ['bypass_shell'=>true]);
if (!is_resource($process)) exit(3);
fclose($pipes[0]);stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
$exit=proc_close($process);
if ($exit === 0) {
    fwrite(STDERR, "database-loss probe unexpectedly connected\n");
    exit(4);
}
fwrite(STDERR, "database connection lost as injected\n");
exit(87);

#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = (string) getenv('DUO_OCC_ROOT');
$endpoint = (string) getenv('DUO_OCC_ENDPOINT');
$serviceKeyId = (string) getenv('DUO_OCC_SERVICE_KEY_ID');
$servicePublicKey = (string) getenv('DUO_OCC_SERVICE_PUBLIC_KEY');
$log = (string) getenv('DUO_OCC_WP_LOG');
if ($root === '' || $endpoint === '' || $serviceKeyId === '' || $servicePublicKey === '' || $log === '') {
    fwrite(STDERR, "origin connector target configuration is incomplete\n");
    exit(2);
}
$privateErrorLog = (string) getenv('DUO_OCC_WP_ERROR_LOG');
register_shutdown_function(static function () use ($privateErrorLog): void {
    $error = error_get_last();
    if ($privateErrorLog !== '' && is_array($error)
        && in_array($error['type'] ?? null, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        file_put_contents(
            $privateErrorLog,
            ($error['message'] ?? 'unknown fatal error') . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
});

$bootstrap = (string) file_get_contents($root . '/agent/duo.php');
if (preg_match("/define\\('DUO_AGENT_VERSION', '([^']+)'\\);/", $bootstrap, $agentMatch) !== 1
    || preg_match("/define\\('DUO_SPEC_VERSION', ([0-9]+)\\);/", $bootstrap, $specMatch) !== 1) {
    fwrite(STDERR, "origin connector target could not read the shipped version pins\n");
    exit(2);
}

define('WP_CLI', true);
define('DUO_CONTROL_PLANE', true);
define('DUO_AGENT_VERSION', $agentMatch[1]);
define('DUO_SPEC_VERSION', (int) $specMatch[1]);
define('DUO_CLOUD_ORIGIN_ENDPOINT', $endpoint);
define('DUO_CLOUD_ORIGIN_SERVICE_KEY_ID', $serviceKeyId);
define('DUO_CLOUD_ORIGIN_SERVICE_PUBLIC_KEY', $servicePublicKey);
foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY'] as $saltName) {
    define($saltName, hash('sha512', "duo-origin-command-regression\0$saltName"));
}

function home_url(string $path = ''): string {
    return 'https://origin-command.example.test' . ($path === '' ? '' : '/' . ltrim($path, '/'));
}

function site_url(string $path = ''): string {
    return 'https://origin-command.example.test/wp' . ($path === '' ? '' : '/' . ltrim($path, '/'));
}

function get_bloginfo(string $show = ''): string {
    return $show === 'version' ? '6.9' : '';
}

function is_multisite(): bool {
    return false;
}

final class WP_CLI {
    public static function add_command(string $name, string $class): void {
    }

    public static function line(string $line): void {
        fwrite(STDOUT, $line . "\n");
    }

    public static function success(string $line): void {
        fwrite(STDOUT, $line . "\n");
    }

    public static function error(string $line): never {
        $errorLog = (string) getenv('DUO_OCC_WP_ERROR_LOG');
        if ($errorLog !== '') {
            file_put_contents($errorLog, $line . "\n", FILE_APPEND | LOCK_EX);
        }
        fwrite(STDERR, $line . "\n");
        exit(1);
    }

    public static function halt(int $exitCode): never {
        exit($exitCode);
    }
}

require_once $root . '/agent/src/Command/Cli.php';

$arguments = $_SERVER['argv'] ?? null;
if (!is_array($arguments)) {
    fwrite(STDERR, "origin connector target did not receive a process argument vector\n");
    exit(2);
}
array_shift($arguments);
if (file_put_contents(
    $log,
    json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    FILE_APPEND | LOCK_EX
) === false) {
    fwrite(STDERR, "origin connector target could not append its command log\n");
    exit(2);
}
$duo = array_search('duo', $arguments, true);
$verb = is_int($duo) ? ($arguments[$duo + 1] ?? null) : null;
if (!is_string($verb) || !in_array($verb, [
    'origin-export', 'origin-pair', 'origin-revoke', 'origin-rotate',
    'origin-status', 'origin-uninstall',
], true)) {
    fwrite(STDERR, "origin connector target received an unsupported command\n");
    exit(2);
}
$assoc = [];
foreach (array_slice($arguments, $duo + 2) as $argument) {
    if (!is_string($argument) || !str_starts_with($argument, '--')) {
        fwrite(STDERR, "origin connector target received a positional argument\n");
        exit(2);
    }
    $pair = explode('=', substr($argument, 2), 2);
    $assoc[$pair[0]] = count($pair) === 1 ? true : $pair[1];
}
$method = str_replace('-', '_', $verb);
(new \Duo\Cli())->{$method}([], $assoc);

#!/usr/bin/env php
<?php
/** Deterministic Docker/Compose boundary for contained-provider offline tests. */
declare(strict_types=1);

$statePath = (string) getenv('WPRISM_CONTAINED_FAKE_STATE');
$logPath = (string) getenv('WPRISM_CONTAINED_FAKE_LOG');
$driftPath = (string) getenv('WPRISM_CONTAINED_FAKE_DRIFT');
$cliDriftPath = (string) getenv('WPRISM_CONTAINED_FAKE_CLI_DRIFT');
if ($statePath === '' || $logPath === '') exit(90);
$arguments = array_slice($_SERVER['argv'] ?? [], 1);
$stdin = (string) stream_get_contents(STDIN);
file_put_contents($logPath, json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
$state = is_file($statePath)
    ? json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR)
    : ['database' => false, 'proxy' => false, 'wordpress' => false];
$state['proxy'] ??= false;
$value = static function (string $key) use ($state): string {
    $live = getenv($key);
    if (is_string($live) && $live !== '') return $live;
    return (string) ($state['runtime'][$key] ?? '');
};
$save = static function (array $next) use ($statePath): void {
    file_put_contents($statePath, json_encode($next, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
};
$pair = $value('WPRISM_PAIR');
$project = "wprism-$pair-preview";
$network = "$project-internal";
$ingress = "$project-ingress";
$databaseName = "$project-db-1";
$wordpressName = "$project-wp-1";
$proxyName = "$project-proxy-1";
$databaseId = str_repeat('d', 64);
$wordpressId = str_repeat('a', 64);
$proxyId = str_repeat('7', 64);
$sourceDatabaseDump = "CREATE TABLE `wp_options` (`option_id` bigint unsigned NOT NULL,`option_name` varchar(191) NOT NULL,`option_value` longtext NOT NULL,`autoload` varchar(20) NOT NULL);\n"
    . "INSERT INTO `wp_options` VALUES (1,'siteurl','http://127.0.0.1:8181','yes');\n"
    . "INSERT INTO `wp_options` VALUES (2,'wprism_payment_token','source-payment-secret-0001','no');\n"
    . "INSERT INTO `wp_options` VALUES (3,'wprism_mail_token','source-mail-secret-0001','no');\n"
    . "CREATE TABLE `wp_users` (`ID` bigint unsigned NOT NULL);\n"
    . "INSERT INTO `wp_users` VALUES (1,'admin','source-password-hash-0001','admin','admin@example.invalid','','2026-01-01 00:00:00','source-activation-key-0001',0,'Admin');\n"
    . "CREATE TABLE `wp_usermeta` (`umeta_id` bigint unsigned NOT NULL);\n"
    . "INSERT INTO `wp_usermeta` VALUES (1,1,'session_tokens','source-session-token-0001');\n"
    . "INSERT INTO `wp_usermeta` VALUES (2,1,'_application_passwords','source-application-password-0001');\n"
    . "INSERT INTO `wp_usermeta` VALUES (3,1,'ordinary_profile','ordinary-value');\n";

$phpBase = [
    'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
    'PHPIZE_DEPS=fixture-build-dependencies',
    'PHP_INI_DIR=/usr/local/etc/php',
    'PHP_CFLAGS=-fixture',
    'PHP_CPPFLAGS=-fixture',
    'PHP_LDFLAGS=-fixture',
    'GPG_KEYS=fixture',
    'PHP_VERSION=8.3.33',
    'PHP_URL=https://example.invalid/php.tar.xz',
    'PHP_ASC_URL=https://example.invalid/php.tar.xz.asc',
    'PHP_SHA256=' . str_repeat('1', 64),
];
$wordpressExtra = "define('WP_ENVIRONMENT_TYPE', 'local');\ndefine('DISABLE_WP_CRON', true);\ndefine('AUTOMATIC_UPDATER_DISABLED', true);\n";
$wpEnvironment = array_merge($phpBase, [
    'APACHE_CONFDIR=/etc/apache2',
    'APACHE_ENVVARS=/etc/apache2/envvars',
    'GIT_CONFIG_COUNT=1',
    'GIT_CONFIG_KEY_0=safe.directory',
    'GIT_CONFIG_VALUE_0=/siterepo',
    'WORDPRESS_CONFIG_EXTRA=' . $wordpressExtra,
    'WORDPRESS_DB_HOST=db',
    'WORDPRESS_DB_NAME=' . $value('WPRISM_PREVIEW_DB_NAME'),
    'WORDPRESS_DB_PASSWORD=' . $value('WPRISM_PREVIEW_DB_PASSWORD'),
    'WORDPRESS_DB_USER=' . $value('WPRISM_PREVIEW_DB_USER'),
]);
$dbEnvironment = [
    'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
    'GOSU_VERSION=1.19',
    'LANG=C.UTF-8',
    'MARIADB_VERSION=fixture',
    'MARIADB_DATABASE=' . $value('WPRISM_PREVIEW_DB_NAME'),
    'MARIADB_PASSWORD=' . $value('WPRISM_PREVIEW_DB_PASSWORD'),
    'MARIADB_ROOT_PASSWORD=' . $value('WPRISM_PREVIEW_DB_ROOT_PASSWORD'),
    'MARIADB_USER=' . $value('WPRISM_PREVIEW_DB_USER'),
];
$cliEnvironment = array_merge($phpBase, [
    'HOME=/home/www-data',
    'HOSTNAME=' . str_repeat('3', 12),
    'PWD=/var/www/html',
    'SHLVL=1',
    'WORDPRESS_CLI_GPG_KEY=fixture',
    'WORDPRESS_CLI_SHA512=' . str_repeat('2', 128),
    'WORDPRESS_CLI_VERSION=2.12.0',
    'GIT_CONFIG_COUNT=1',
    'GIT_CONFIG_KEY_0=safe.directory',
    'GIT_CONFIG_VALUE_0=/siterepo',
    'WORDPRESS_CONFIG_EXTRA=' . $wordpressExtra,
    'WORDPRESS_DB_HOST=db',
    'WORDPRESS_DB_NAME=' . $value('WPRISM_PREVIEW_DB_NAME'),
    'WORDPRESS_DB_PASSWORD=' . $value('WPRISM_PREVIEW_DB_PASSWORD'),
    'WORDPRESS_DB_USER=' . $value('WPRISM_PREVIEW_DB_USER'),
]);
$proxyEnvironment = [
    'ACME_VERSION=0.3.1',
    'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
    'NGINX_VERSION=1.29.0',
    'NJS_VERSION=0.9.0',
    'NJS_RELEASE=1',
    'PKG_RELEASE=1',
    'DYNPKG_RELEASE=1',
];
$environmentMap = static function (array $rows): array {
    $map = [];
    foreach ($rows as $row) {
        [$key, $entry] = explode('=', $row, 2);
        $map[$key] = $entry;
    }
    return $map;
};

$mount = static fn (string $type, string $source, string $destination, bool $rw): array => [
    'Destination' => $destination, 'RW' => $rw, 'Source' => $source, 'Type' => $type,
];
$wpMounts = [
    $mount('volume', "$project-wordpress", '/var/www/html', true),
    $mount('bind', $value('WPRISM_PREVIEW_AGENT_SRC'), '/var/www/html/wp-content/mu-plugins/wprism', false),
    $mount('bind', $value('WPRISM_PREVIEW_AGENT_SRC') . '/wprism-loader.php', '/var/www/html/wp-content/mu-plugins/wprism-loader.php', false),
    $mount('bind', $value('WPRISM_PREVIEW_ADAPTER_PACKAGES_SRC'), '/var/www/html/wp-content/mu-plugins/adapter-packages', false),
    $mount('bind', $value('WPRISM_PREVIEW_CRON_GUARD'), '/var/www/html/wp-content/mu-plugins/00-wprism-containment-cron-guard.php', false),
    $mount('bind', $value('WPRISM_PREVIEW_PLATFORM_SRC'), '/var/www/html/wp-content/mu-plugins/platform', false),
    $mount('bind', $value('WPRISM_PREVIEW_REPO'), '/siterepo', true),
    $mount('bind', $value('WPRISM_PREVIEW_MAIL_SHIM'), '/usr/local/bin/wprism-refuse-sendmail', false),
    $mount('bind', $value('WPRISM_PREVIEW_PHP_INI'), '/usr/local/etc/php/conf.d/zz-wprism-containment.ini', false),
];
$container = static function (string $service) use (
    $databaseId, $databaseName, $dbEnvironment, $ingress, $network, $project, $proxyEnvironment,
    $proxyId, $proxyName, $state, $wordpressId, $wordpressName, $wpEnvironment, $wpMounts,
    $driftPath, $mount, $value
): array {
    $database = $service === 'db';
    $proxy = $service === 'proxy';
    $id = $database ? $databaseId : ($proxy ? $proxyId : $wordpressId);
    $name = $database ? $databaseName : ($proxy ? $proxyName : $wordpressName);
    $networks = [$network => ['Gateway' => '', 'NetworkID' => str_repeat('e', 64)]];
    if ($proxy) {
        $networks[$ingress] = ['NetworkID' => str_repeat('9', 64)];
    }
    if (!$database && !$proxy && $driftPath !== '' && is_file($driftPath)) {
        $networks['wprism-shared'] = ['NetworkID' => str_repeat('f', 64)];
    }
    return [
        'Config' => [
            'Env' => $database ? $dbEnvironment : ($proxy ? $proxyEnvironment : $wpEnvironment),
            'User' => $proxy ? '101:101' : '',
        ],
        'HostConfig' => [
            'CapAdd' => null, 'CapDrop' => $proxy ? ['ALL'] : ['NET_RAW'],
            'NetworkMode' => $network,
            'PortBindings' => $proxy ? [
                '8080/tcp' => [['HostIp' => '127.0.0.1', 'HostPort' => $value('WPRISM_PREVIEW_PORT')]],
            ] : [],
            'Privileged' => false, 'SecurityOpt' => ['no-new-privileges:true'],
            'ReadonlyRootfs' => $proxy,
        ],
        'Id' => $id,
        'Image' => $database ? str_repeat('b', 64) : ($proxy ? str_repeat('6', 64) : str_repeat('c', 64)),
        'Mounts' => $database
            ? [$mount('volume', "$project-database", '/var/lib/mysql', true)]
            : ($proxy ? [$mount('bind', $value('WPRISM_PREVIEW_PROXY_CONFIG'), '/etc/nginx/nginx.conf', false)] : $wpMounts),
        'Name' => '/' . $name,
        'NetworkSettings' => [
            'Networks' => $networks,
            'Ports' => $database ? ['3306/tcp' => null] : ($proxy ? [
                '8080/tcp' => [['HostIp' => '127.0.0.1', 'HostPort' => $value('WPRISM_PREVIEW_PORT')]],
            ] : ['80/tcp' => null]),
        ],
        'State' => ['Health' => ['Status' => 'healthy'], 'Running' => $database ? $state['database'] : ($proxy ? $state['proxy'] : $state['wordpress'])],
    ];
};

if (($arguments[0] ?? null) === 'compose') {
    $command = null;
    foreach (['config', 'down', 'exec', 'ps', 'run', 'up'] as $candidate) {
        if (in_array($candidate, $arguments, true)) {
            $command = $candidate;
            break;
        }
    }
    if ($command === 'ps') {
        if ($state['database']) echo $databaseId . "\n";
        if ($state['wordpress']) echo $wordpressId . "\n";
        if ($state['proxy']) echo $proxyId . "\n";
        exit(0);
    }
    if ($command === 'up') {
        $state['database'] = true;
        if (in_array('wp', $arguments, true)) $state['wordpress'] = true;
        if (in_array('proxy', $arguments, true)) $state['proxy'] = true;
        foreach ([
            'WPRISM_PAIR', 'WPRISM_PREVIEW_ADAPTER_PACKAGES_SRC', 'WPRISM_PREVIEW_AGENT_SRC',
            'WPRISM_PREVIEW_DB_NAME', 'WPRISM_PREVIEW_DB_PASSWORD', 'WPRISM_PREVIEW_DB_ROOT_PASSWORD',
            'WPRISM_PREVIEW_DB_USER', 'WPRISM_PREVIEW_MAIL_SHIM', 'WPRISM_PREVIEW_PHP_INI',
            'WPRISM_PREVIEW_PLATFORM_SRC', 'WPRISM_PREVIEW_PORT', 'WPRISM_PREVIEW_REPO',
            'WPRISM_PREVIEW_PROXY_CONFIG', 'WPRISM_PREVIEW_CRON_GUARD',
        ] as $key) {
            $state['runtime'][$key] = (string) getenv($key);
        }
        $save($state);
        exit(0);
    }
    if ($command === 'down') {
        $save(['database' => false, 'proxy' => false, 'wordpress' => false]);
        exit(0);
    }
    if ($command === 'config') {
        $renderedEnvironment = [
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'safe.directory',
            'GIT_CONFIG_VALUE_0' => '/siterepo',
            'WORDPRESS_CONFIG_EXTRA' => $wordpressExtra,
            'WORDPRESS_DB_HOST' => 'db',
            'WORDPRESS_DB_NAME' => $value('WPRISM_PREVIEW_DB_NAME'),
            'WORDPRESS_DB_PASSWORD' => $value('WPRISM_PREVIEW_DB_PASSWORD'),
            'WORDPRESS_DB_USER' => $value('WPRISM_PREVIEW_DB_USER'),
        ];
        $renderedVolumes = [
            ['type' => 'volume', 'source' => 'wordpress', 'target' => '/var/www/html'],
            ['type' => 'bind', 'source' => $value('WPRISM_PREVIEW_AGENT_SRC'), 'target' => '/var/www/html/wp-content/mu-plugins/wprism', 'read_only' => true],
            ['type' => 'bind', 'source' => $value('WPRISM_PREVIEW_AGENT_SRC') . '/wprism-loader.php', 'target' => '/var/www/html/wp-content/mu-plugins/wprism-loader.php', 'read_only' => true],
            ['type' => 'bind', 'source' => $value('WPRISM_PREVIEW_ADAPTER_PACKAGES_SRC'), 'target' => '/var/www/html/wp-content/mu-plugins/adapter-packages', 'read_only' => true],
            ['type' => 'bind', 'source' => $value('WPRISM_PREVIEW_CRON_GUARD'), 'target' => '/var/www/html/wp-content/mu-plugins/00-wprism-containment-cron-guard.php', 'read_only' => true],
            ['type' => 'bind', 'source' => $value('WPRISM_PREVIEW_PLATFORM_SRC'), 'target' => '/var/www/html/wp-content/mu-plugins/platform', 'read_only' => true],
            ['type' => 'bind', 'source' => $value('WPRISM_PREVIEW_REPO'), 'target' => '/siterepo'],
            ['type' => 'bind', 'source' => $value('WPRISM_PREVIEW_MAIL_SHIM'), 'target' => '/usr/local/bin/wprism-refuse-sendmail', 'read_only' => true],
            ['type' => 'bind', 'source' => $value('WPRISM_PREVIEW_PHP_INI'), 'target' => '/usr/local/etc/php/conf.d/zz-wprism-containment.ini', 'read_only' => true],
        ];
        $base = [
            'cap_drop' => ['NET_RAW'],
            'command' => null,
            'entrypoint' => null,
            'networks' => ['contained' => null],
            'restart' => 'no',
            'security_opt' => ['no-new-privileges:true'],
        ];
        $cliService = $base + [
            'environment' => $renderedEnvironment,
            'image' => $value('WPRISM_PREVIEW_CLI_IMAGE'),
            'profiles' => ['cli'],
            'user' => '33:33',
            'volumes' => $renderedVolumes,
        ];
        if ($cliDriftPath !== '' && is_file($cliDriftPath)) {
            $cliService['volumes'][] = [
                'type' => 'bind', 'source' => '/var/run/docker.sock',
                'target' => '/var/run/docker.sock',
            ];
        }
        echo json_encode([
            'networks' => [
                'contained' => ['internal' => true, 'ipam' => [], 'name' => $network],
                'ingress' => [
                    'driver' => 'bridge',
                    'driver_opts' => [
                        'com.docker.network.bridge.enable_ip_masquerade' => 'false',
                        'com.docker.network.bridge.host_binding_ipv4' => '127.0.0.1',
                    ],
                    'ipam' => [],
                    'name' => $ingress,
                ],
            ],
            'services' => [
                'cli' => $cliService,
                'db' => $base + [
                    'environment' => [
                        'MARIADB_DATABASE' => $value('WPRISM_PREVIEW_DB_NAME'),
                        'MARIADB_PASSWORD' => $value('WPRISM_PREVIEW_DB_PASSWORD'),
                        'MARIADB_ROOT_PASSWORD' => $value('WPRISM_PREVIEW_DB_ROOT_PASSWORD'),
                        'MARIADB_USER' => $value('WPRISM_PREVIEW_DB_USER'),
                    ],
                    'image' => $value('WPRISM_PREVIEW_DB_IMAGE'),
                    'volumes' => [['type' => 'volume', 'source' => 'database', 'target' => '/var/lib/mysql']],
                ],
                'wp' => array_replace($base, [
                    'environment' => $renderedEnvironment,
                    'image' => $value('WPRISM_PREVIEW_WP_IMAGE'),
                    'volumes' => $renderedVolumes,
                ]),
                'proxy' => array_replace($base, [
                    'cap_drop' => ['ALL'],
                    'command' => [],
                    'entrypoint' => ['nginx', '-g', 'daemon off;'],
                    'image' => $value('WPRISM_PREVIEW_PROXY_IMAGE'),
                    'networks' => ['contained' => null, 'ingress' => null],
                    'ports' => [[
                        'mode' => 'ingress', 'host_ip' => '127.0.0.1', 'target' => 8080,
                        'published' => $value('WPRISM_PREVIEW_PORT'), 'protocol' => 'tcp',
                    ]],
                    'read_only' => true,
                    'tmpfs' => ['/tmp:rw,noexec,nosuid,size=16m'],
                    'user' => '101:101',
                    'volumes' => [[
                        'type' => 'bind', 'source' => $value('WPRISM_PREVIEW_PROXY_CONFIG'),
                        'target' => '/etc/nginx/nginx.conf', 'read_only' => true,
                    ]],
                ]),
            ],
            'volumes' => [
                'database' => ['name' => "$project-database"],
                'wordpress' => ['name' => "$project-wordpress"],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit(0);
    }
    if ($command === 'exec') {
        if (in_array('SHOW GRANTS FOR CURRENT_USER', $arguments, true)) {
            // The SQL is one shell argument, so match it by substring below.
        }
        $joined = implode(' ', $arguments);
        if (str_contains($joined, 'SHOW GRANTS FOR CURRENT_USER')) {
            echo "GRANT USAGE ON *.* TO `fixture`@`%`\n";
            $grantDatabase = strtr($value('WPRISM_PREVIEW_DB_NAME'), ['\\' => '\\\\', '_' => '\\_', '%' => '\\%']);
            echo 'GRANT ALL PRIVILEGES ON `' . $grantDatabase . '`.* TO `fixture`@`%`' . "\n";
        } elseif (str_contains($joined, 'mariadb-dump')) {
            echo (string) ($state['restored_dump'] ?? '');
        } elseif (str_contains($joined, 'exec mariadb -uroot')) {
            if (!str_starts_with($stdin, 'DROP DATABASE')) {
                $state['restored_dump'] = $stdin;
                $save($state);
            }
        }
        exit(0);
    }
    if ($command === 'run') {
        $map = $environmentMap($cliEnvironment);
        echo json_encode([
            'env' => $map,
            'routes' => "Iface\tDestination\tGateway\tFlags\neth0\t00A3A8C0\t00000000\t0001\n",
            'sendmail_path' => '/usr/local/bin/wprism-refuse-sendmail',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit(0);
    }
    exit(91);
}

if (($arguments[0] ?? null) === 'inspect') {
    $rows = [];
    foreach (array_slice($arguments, 1) as $name) {
        if ($name === $databaseName && $state['database']) $rows[] = $container('db');
        if ($name === $wordpressName && $state['wordpress']) $rows[] = $container('wp');
        if ($name === $proxyName && $state['proxy']) $rows[] = $container('proxy');
    }
    if ($rows === []) exit(1);
    echo json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit(0);
}
if (($arguments[0] ?? null) === 'network' && ($arguments[1] ?? null) === 'inspect') {
    if (!$state['database'] && !$state['wordpress'] && !$state['proxy']) exit(1);
    $requestedNetwork = (string) ($arguments[2] ?? '');
    $attached = [];
    if ($requestedNetwork === $network && $state['database']) $attached[$databaseId] = ['Name' => $databaseName];
    if ($requestedNetwork === $network && $state['wordpress']) {
        $attached[$wordpressId] = ['Name' => $wordpressName];
    }
    if (($requestedNetwork === $network || $requestedNetwork === $ingress) && $state['proxy']) {
        $attached[$proxyId] = ['Name' => $proxyName];
    }
    echo json_encode([[
        'Containers' => $attached,
        'Driver' => 'bridge',
        'Id' => $requestedNetwork === $ingress ? str_repeat('9', 64) : str_repeat('e', 64),
        'Internal' => $requestedNetwork !== $ingress,
        'Name' => $requestedNetwork,
        'Options' => $requestedNetwork === $ingress ? [
            'com.docker.network.bridge.enable_ip_masquerade' => 'false',
            'com.docker.network.bridge.host_binding_ipv4' => '127.0.0.1',
        ] : [],
    ]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit(0);
}
if (($arguments[0] ?? null) === 'volume' && ($arguments[1] ?? null) === 'inspect') {
    exit($state['database'] || $state['wordpress'] || $state['proxy'] ? 0 : 1);
}
if (($arguments[0] ?? null) === 'ps') {
    if ($state['database']) echo $databaseName . "\n";
    if ($state['wordpress']) echo $wordpressName . "\n";
    if ($state['proxy']) echo $proxyName . "\n";
    exit(0);
}
if (($arguments[0] ?? null) === 'port') {
    if (($arguments[1] ?? null) === 'wprism-mup-wp1-1') {
        echo "127.0.0.1:8181\n";
        exit(0);
    }
    if (!$state['proxy']) exit(1);
    echo '127.0.0.1:' . $value('WPRISM_PREVIEW_PORT') . "\n";
    exit(0);
}
if (($arguments[0] ?? null) === 'cp') {
    $source = (string) ($arguments[1] ?? '');
    $destination = (string) ($arguments[2] ?? '');
    if ($source === 'wprism-mup-wp1-1:/var/www/html/wp-content/uploads/.') {
        if (!is_dir($destination . '/private') && !mkdir($destination . '/private', 0700, true)) exit(93);
        file_put_contents($destination . '/ordinary.txt', "ordinary-media\n");
        file_put_contents($destination . '/private/source-media-secret.txt', "source-media-secret-0001\n");
    }
    exit(0);
}
if (($arguments[0] ?? null) === 'exec') {
    if (($arguments[1] ?? null) === 'wprism-shared-db' && in_array('mariadb-dump', $arguments, true)) {
        echo $sourceDatabaseDump;
        exit(0);
    }
    if (in_array('cat', $arguments, true) && in_array('/proc/net/route', $arguments, true)) {
        echo "Iface\tDestination\tGateway\tFlags\neth0\t00A3A8C0\t00000000\t0001\n";
        exit(0);
    }
    if (in_array('sha256sum', $arguments, true)) {
        if (in_array('/tmp/wprism-mail-capture.ndjson', $arguments, true)) {
            echo hash('sha256', 'fixture-mail-capture') . "  /tmp/wprism-mail-capture.ndjson\n";
        } elseif (in_array('/etc/nginx/nginx.conf', $arguments, true)) {
            echo hash_file('sha256', $value('WPRISM_PREVIEW_PROXY_CONFIG')) . "  /etc/nginx/nginx.conf\n";
        } else {
            $mail = $value('WPRISM_PREVIEW_MAIL_SHIM');
            $ini = $value('WPRISM_PREVIEW_PHP_INI');
            $cron = $value('WPRISM_PREVIEW_CRON_GUARD');
            echo hash_file('sha256', $mail) . "  /usr/local/bin/wprism-refuse-sendmail\n";
            echo hash_file('sha256', $ini) . "  /usr/local/etc/php/conf.d/zz-wprism-containment.ini\n";
            echo hash_file('sha256', $cron) . "  /var/www/html/wp-content/mu-plugins/00-wprism-containment-cron-guard.php\n";
        }
    }
    exit(0);
}
exit(92);

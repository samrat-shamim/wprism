<?php
/**
 * Write a `wprism-reference-env-provider-config/v1` config for
 * tools/reference-env-provider.php. The plan fixture may point it at a pair
 * that does not exist; the reusable-slot fixtures supply isolated fake pair,
 * Docker and Git boundaries for live protocol calls.
 *
 * usage: php make-provider-config.php <out-file> [withheld,capability,ids] [contained]
 */
declare(strict_types=1);

$out = $argv[1] ?? '';
if ($out === '') {
    fwrite(STDERR, "usage: make-provider-config.php <out-file> [withheld,ids]\n");
    exit(2);
}
$withheld = isset($argv[2]) && $argv[2] !== '' ? explode(',', $argv[2]) : [];
$contained = ($argv[3] ?? null) === 'contained';
$sandbox = dirname(__DIR__, 3);
$root = dirname($sandbox);

$config = [
    'format' => 'wprism-reference-env-provider-config/v1',
    'pair' => 'mup',
    'pair_script' => $sandbox . '/bin/pair.sh',
    'compose_dir' => $sandbox,
    'compose_files' => [$sandbox . '/pair.yml', $sandbox . '/pair.http.yml'],
    'controller_repo' => $sandbox . '/tmp/reference-env-provider/origin.git',
    'db_container' => 'wprism-shared-db',
    'state_root' => $sandbox . '/tmp/reference-env-provider/mup',
    'source_environment' => 'mup1',
    'destroy_scope' => 'side',
    'withheld_capabilities' => array_values($withheld),
    'environments' => [
        'mup1' => [
            'role' => 'source', 'side' => 1, 'port' => 8181,
            'container' => 'wprism-mup-wp1-1', 'service' => 'cli1',
            'database' => 'wp_mup1', 'repo' => $sandbox . '/siterepo/mup1',
        ],
        'mup2' => [
            'role' => 'target', 'side' => 2, 'port' => 8182,
            'container' => 'wprism-mup-wp2-1', 'service' => 'cli2',
            'database' => 'wp_mup2', 'repo' => $sandbox . '/siterepo/mup2',
        ],
    ],
];

if ($contained) {
    $config['contained_preview'] = [
        'format' => 'wprism-reference-contained-preview/v1',
        'compose_file' => $sandbox . '/contained-preview.yml',
        'project' => 'wprism-mup-preview',
        'network' => 'wprism-mup-preview-internal',
        'database_container' => 'wprism-mup-preview-db-1',
        'database_service' => 'db',
        'ingress_network' => 'wprism-mup-preview-ingress',
        'proxy_container' => 'wprism-mup-preview-proxy-1',
        'proxy_service' => 'proxy',
        'wordpress_service' => 'wp',
        'cli_service' => 'cli',
        'database' => 'wprism_preview',
        'wordpress_image' => 'wordpress@sha256:65919a9ca10940feb10d9400fead0d639bf86241f47c91e2b9ea4703aa8452cf',
        'cli_image' => 'wordpress@sha256:2b5e9d4d3e51909dca1aaa4732e9f5e5bf0377c2114dbd8ff39f060bff202586',
        'database_image' => 'mariadb@sha256:d9f7eb2637296652f24b484afd5d246f759f49f5babcadc6a9e344c9acb75fbf',
        'proxy_image' => 'nginx@sha256:5616878291a2eed594aee8db4dade5878cf7edcb475e59193904b198d9b830de',
        'cron_guard' => $sandbox . '/containment/block-cron.php',
        'mail_shim' => $sandbox . '/containment/refuse-sendmail.sh',
        'php_ini' => $sandbox . '/containment/php.ini',
        'proxy_config' => $sandbox . '/containment/nginx.conf',
        'sanitization_policy' => __DIR__ . '/contained-sanitization-policy.json',
        'sanitization_policy_sha256' => hash_file('sha256', __DIR__ . '/contained-sanitization-policy.json'),
        'runtime_sources' => [
            'adapter_packages' => $root . '/adapter-packages',
            'agent' => $root . '/agent',
            'platform' => $root . '/platform',
        ],
    ];
    $config['environments']['mup2']['container'] = 'wprism-mup-preview-wp-1';
    $config['environments']['mup2']['service'] = 'cli';
    $config['environments']['mup2']['database'] = 'wprism_preview';
}

file_put_contents(
    $out,
    json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
);

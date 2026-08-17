<?php
/**
 * Write a `duo-reference-env-provider-config/v1` config for
 * tools/reference-env-provider.php. The plan fixture may point it at a pair
 * that does not exist; the reusable-slot fixtures supply isolated fake pair,
 * Docker and Git boundaries for live protocol calls.
 *
 * usage: php make-provider-config.php <out-file> [withheld,capability,ids]
 */
declare(strict_types=1);

$out = $argv[1] ?? '';
if ($out === '') {
    fwrite(STDERR, "usage: make-provider-config.php <out-file> [withheld,ids]\n");
    exit(2);
}
$withheld = isset($argv[2]) && $argv[2] !== '' ? explode(',', $argv[2]) : [];
$sandbox = dirname(__DIR__, 3);

$config = [
    'format' => 'duo-reference-env-provider-config/v1',
    'pair' => 'mup',
    'pair_script' => $sandbox . '/bin/pair.sh',
    'compose_dir' => $sandbox,
    'compose_files' => [$sandbox . '/pair.yml', $sandbox . '/pair.http.yml'],
    'controller_repo' => $sandbox . '/tmp/reference-env-provider/origin.git',
    'db_container' => 'duo-shared-db',
    'state_root' => $sandbox . '/tmp/reference-env-provider/mup',
    'source_environment' => 'mup1',
    'destroy_scope' => 'side',
    'withheld_capabilities' => array_values($withheld),
    'environments' => [
        'mup1' => [
            'role' => 'source', 'side' => 1, 'port' => 8181,
            'container' => 'duo-mup-wp1-1', 'service' => 'cli1',
            'database' => 'wp_mup1', 'repo' => $sandbox . '/siterepo/mup1',
        ],
        'mup2' => [
            'role' => 'target', 'side' => 2, 'port' => 8182,
            'container' => 'duo-mup-wp2-1', 'service' => 'cli2',
            'database' => 'wp_mup2', 'repo' => $sandbox . '/siterepo/mup2',
        ],
    ],
];

file_put_contents(
    $out,
    json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
);

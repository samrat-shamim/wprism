#!/usr/bin/env php
<?php
declare(strict_types=1);

// Test-only target-owned exclusion provider used by the standalone SSH
// adoption regression. Production hosts configure their own provider.

function fixture_canonical(array $value): string {
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        if (is_array($item)) {
            $value[$key] = array_is_list($item)
                ? array_map(static fn(mixed $entry): mixed => is_array($entry)
                    ? json_decode(fixture_canonical($entry), true, 512, JSON_THROW_ON_ERROR)
                    : $entry, $item)
                : json_decode(fixture_canonical($item), true, 512, JSON_THROW_ON_ERROR);
        }
    }
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

$request = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$statePath = $argv[1] ?? '';
$action = (string) ($request['action'] ?? '');
if (($request['format'] ?? '') !== 'wprism-exclusion-provider-request/v2') {
    fwrite(STDERR, "fixture requires exclusion provider request v2\n");
    exit(42);
}
$state = is_file($statePath)
    ? json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR)
    : null;

if ($action === 'keepalive' && is_file($statePath . '.fail-keepalive')) {
    unlink($statePath . '.fail-keepalive');
    fwrite(STDERR, "fixture keepalive unavailable; exclusion remains held\n");
    exit(43);
}

if ($action === 'probe') {
    $token = null;
    $providerState = 'ready';
} elseif ($action === 'acquire') {
    $token = is_array($state) && ($state['state'] ?? '') === 'held'
        ? (string) $state['token']
        : 'ssh-fixture-' . hash('sha256', fixture_canonical($request));
    $state = ['state' => 'held', 'token' => $token];
    file_put_contents($statePath, fixture_canonical($state) . "\n");
    chmod($statePath, 0600);
    $providerState = 'held';
} else {
    if (!is_array($state) || ($state['state'] ?? '') !== 'held'
        || !hash_equals((string) $state['token'], (string) ($request['token'] ?? ''))) {
        fwrite(STDERR, "fixture exclusion is not held\n");
        exit(41);
    }
    $token = (string) $state['token'];
    $providerState = $action === 'release' ? 'released' : 'held';
    if ($action === 'release') {
        $state['state'] = 'released';
        file_put_contents($statePath, fixture_canonical($state) . "\n");
    }
}

echo fixture_canonical([
    'available' => true,
    'disconnect_behavior' => 'remain_excluded',
    'format' => 'wprism-exclusion-provider-response/v2',
    'provider_id' => 'ssh-fixture-provider',
    'provider_version' => '1.0.0',
    'scopes' => [
        'background_jobs' => true,
        'database_writers' => true,
        'filesystem_writers' => true,
        'package_updates' => true,
        'public_traffic' => true,
    ],
    'state' => $providerState,
    'target_id' => $request['target_id'],
    'token' => $token,
]) . "\n";

<?php
declare(strict_types=1);

// Isolated DUO-3298 provider fixture. It captures exact filesystem
// before-images, journals prevented external calls to a receipt outbox, and
// restores/verifies in separate provider processes without loading WordPress.

function ep_canonical(mixed $value): string {
    $sort = static function (mixed $v) use (&$sort): mixed {
        if (!is_array($v)) return $v;
        if (array_is_list($v)) return array_map($sort, $v);
        ksort($v, SORT_STRING);
        foreach ($v as $k => $item) $v[$k] = $sort($item);
        return $v;
    };
    return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
function ep_emit(array $value): never { echo ep_canonical($value) . "\n"; exit(0); }
function ep_fail(string $message): never { fwrite(STDERR, $message . "\n"); exit(1); }
function ep_hash(string $bytes): string { return hash('sha256', $bytes); }
function ep_read(string $path): array {
    $raw = file_get_contents($path);
    $value = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value) || array_is_list($value) || ep_canonical($value) . "\n" !== $raw) ep_fail('noncanonical input');
    return $value;
}
function ep_write(string $path, string $bytes): void {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) ep_fail('write failed');
    chmod($path, 0600);
}
function ep_target(string $state, string $siteRoot, array $effect): string {
    $selector = $effect['selector'] ?? [];
    if (($selector['type'] ?? '') !== 'path') {
        return $state . '.external-' . ep_hash(ep_canonical($selector));
    }
    $value = (string) ($selector['value'] ?? '');
    if ($value === '' || str_starts_with($value, '/') || str_contains($value, '\\')
        || in_array('..', explode('/', $value), true)) ep_fail('unsafe selector path');
    return rtrim($siteRoot, '/') . '/' . $value;
}
function ep_result(array $inventory, string $state, string $siteRoot): string {
    $rows = [];
    foreach ($inventory['effects'] as $row) {
        if (($row['effect']['mode'] ?? '') !== 'reversible') continue;
        $path = ep_target($state, $siteRoot, $row['effect']);
        $rows[] = [
            'effect_id' => $row['effect']['id'],
            'manifest' => $row['manifest'],
            'sha256' => is_file($path) ? hash_file('sha256', $path) : ep_hash('absent'),
        ];
    }
    return ep_hash(ep_canonical($rows));
}

$state = $argv[1] ?? '';
$siteRoot = $argv[2] ?? '';
if ($state === '' || $siteRoot === '') ep_fail('usage: effect-provider STATE SITE_ROOT');
$raw = stream_get_contents(STDIN);
$request = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($request) || array_is_list($request) || ep_canonical($request) . "\n" !== $raw) ep_fail('noncanonical request');
$base = ['format' => 'duo-effect-provider-response/v1', 'provider_id' => 'fixture-effects', 'provider_version' => '1.0.0'];
if (is_file($state . '.leak')) ep_emit($base + ['credentials_exposed' => false, 'secret_token' => 'redacted-test']);
$action = $request['action'] ?? '';
if ($action === 'probe') {
    ep_emit($base + [
        'available' => true, 'credentials_exposed' => false, 'inverse_readback' => true,
        'outbox_prevention' => true, 'state' => 'ready',
        'supports_database_checkpoint' => true, 'supports_external' => true,
    ]);
}
$inventory = ep_read((string) ($request['inventory_path'] ?? ''));
if (!hash_equals((string) ($request['inventory_sha256'] ?? ''), (string) hash_file('sha256', (string) $request['inventory_path']))) ep_fail('inventory hash mismatch');
if ($action === 'prepare') {
    if (is_file($state . '.called')) ep_fail('provider called after a blocked preflight');
    ep_write($state . '.prepared', "prepared\n");
    $prior = ['effects' => [], 'format' => 'duo-effect-prior-evidence/v1'];
    foreach ($inventory['effects'] as $row) {
        $effect = $row['effect'];
        $mode = (string) $effect['mode'];
        $entry = [
            'effect_id' => $effect['id'], 'inverse_input_sha256' => null,
            'manifest' => $row['manifest'], 'mode' => $mode, 'outbox_id' => null,
            'prior_sha256' => null, 'verifier_input_sha256' => null,
        ];
        if ($mode === 'reversible') {
            $path = ep_target($state, $siteRoot, $effect);
            $bytes = is_file($path) ? file_get_contents($path) : false;
            $snapshot = [
                'bytes' => is_string($bytes) ? base64_encode($bytes) : null,
                'effect_id' => $effect['id'], 'manifest' => $row['manifest'],
                'path' => $effect['selector']['value'],
            ];
            $snapshotPath = (string) $request['artifact_directory'] . '/prior-' . ep_hash($row['manifest'] . ':' . $effect['id']) . '.json';
            ep_write($snapshotPath, ep_canonical($snapshot) . "\n");
            $entry['inverse_input_sha256'] = ep_hash(ep_canonical([$effect['adapter']['inverse_inputs'], $snapshot]));
            $entry['prior_sha256'] = is_string($bytes) ? ep_hash($bytes) : ep_hash('absent');
            $entry['verifier_input_sha256'] = ep_hash(ep_canonical([$effect['adapter']['verifier_inputs'], $snapshot]));
        } elseif ($mode === 'restorable') {
            $entry['prior_sha256'] = ep_hash(ep_canonical($effect['selector']));
        } elseif ($mode === 'prevented') {
            $entry['outbox_id'] = 'outbox-' . substr(ep_hash($row['manifest'] . ':' . $effect['id']), 0, 24);
        } else {
            ep_fail('unsupported irreversible effect');
        }
        $prior['effects'][] = $entry;
    }
    $priorBytes = ep_canonical($prior) . "\n";
    ep_write((string) $request['prior_evidence_path'], $priorBytes);
    ep_emit($base + [
        'credentials_exposed' => false, 'inverse_readback' => true,
        'inventory_sha256' => $request['inventory_sha256'], 'outbox_prevention' => true,
        'prior_evidence_sha256' => ep_hash($priorBytes),
        'receipt_inputs_sha256' => ep_hash(ep_canonical($prior)),
        'state' => 'prepared', 'unsupported_effects' => [],
    ]);
}
if ($action === 'observe') {
    $actual = $request['actual_effect'] ?? null;
    if (!is_array($actual)) ep_fail('missing actual effect');
    $declared = null;
    foreach ($inventory['effects'] as $row) {
        if (($row['manifest'] ?? '') === ($actual['manifest'] ?? '')
            && ($row['phase'] ?? '') === ($actual['phase'] ?? '')
            && ($row['effect']['id'] ?? '') === ($actual['effect_id'] ?? '')) $declared = $row;
    }
    if (!is_array($declared)) ep_fail('undeclared effect');
    $mode = (string) $declared['effect']['mode'];
    $prevented = $mode === 'prevented' && !is_file($state . '.report-only');
    $outbox = null;
    if ($prevented) {
        $event = ['actual' => $actual, 'receipt_id' => $request['receipt_id']];
        $outbox = ep_hash(ep_canonical($event));
        ep_write($state . '.outbox', ep_canonical($event) . "\n");
    }
    ep_emit($base + [
        'credentials_exposed' => false, 'effect_event_sha256' => ep_hash(ep_canonical($actual)),
        'mode' => $mode, 'outbox_receipt_sha256' => $outbox,
        'prevented' => $prevented, 'state' => 'observed',
    ]);
}
if ($action === 'inverse') {
    foreach ($inventory['effects'] as $row) {
        if (($row['effect']['mode'] ?? '') !== 'reversible') continue;
        $path = ep_target($state, $siteRoot, $row['effect']);
        $snapshotPath = (string) $request['artifact_directory'] . '/prior-' . ep_hash($row['manifest'] . ':' . $row['effect']['id']) . '.json';
        $snapshot = ep_read($snapshotPath);
        if ($snapshot['bytes'] === null) {
            if (is_file($path)) unlink($path);
        } else {
            $bytes = base64_decode((string) $snapshot['bytes'], true);
            if (!is_string($bytes)) ep_fail('bad before-image');
            ep_write($path, $bytes);
        }
    }
    ep_emit($base + ['credentials_exposed' => false, 'result_sha256' => ep_result($inventory, $state, $siteRoot), 'state' => 'restored', 'undeclared_effects' => []]);
}
if ($action === 'verify-prior') {
    $prior = ep_read((string) $request['prior_evidence_path']);
    foreach ($prior['effects'] as $entry) {
        if (($entry['mode'] ?? '') !== 'reversible') continue;
        $row = null;
        foreach ($inventory['effects'] as $candidate) if ($candidate['manifest'] === $entry['manifest'] && $candidate['effect']['id'] === $entry['effect_id']) $row = $candidate;
        if (!is_array($row)) ep_fail('missing verifier declaration');
        $path = ep_target($state, $siteRoot, $row['effect']);
        $current = is_file($path) ? hash_file('sha256', $path) : ep_hash('absent');
        if (!hash_equals((string) $entry['prior_sha256'], (string) $current)) ep_fail('fresh prior verification failed');
    }
    ep_emit($base + ['credentials_exposed' => false, 'result_sha256' => ep_result($inventory, $state, $siteRoot), 'state' => 'verified', 'undeclared_effects' => []]);
}
ep_fail('unsupported action');

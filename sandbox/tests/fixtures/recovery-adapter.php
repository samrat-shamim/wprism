#!/usr/bin/env php
<?php
declare(strict_types=1);

// Test-only isolated adapter used by the standalone SSH adoption regression.

function adapter_canonical(array $value): string {
    ksort($value, SORT_STRING);
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

$request = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$execute = ($request['action'] ?? '') === 'execute';
echo adapter_canonical([
    'adapter' => (string) $request['adapter'],
    'adapter_version' => '1.0.0',
    'available' => true,
    'format' => 'wprism-recovery-adapter-response/v1',
    'input_sha256' => $request['input_sha256'],
    'loads_site_code' => false,
    'result_sha256' => $execute ? hash('sha256', (string) $request['input_sha256']) : null,
    'status' => $execute ? 'completed' : 'ready',
]) . "\n";

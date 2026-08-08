#!/usr/bin/env php
<?php
declare(strict_types=1);

// Test-only checkpoint capability probe for standalone SSH adoption. The
// complete prepare/restore/verify/delete protocol is covered by the offline
// authenticated-encryption regression; adoption only calls probe.

function checkpoint_fixture_canonical(array $value): string {
    ksort($value, SORT_STRING);
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

$request = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (($request['action'] ?? '') !== 'probe') {
    fwrite(STDERR, "checkpoint fixture supports probe only\n");
    exit(41);
}
echo checkpoint_fixture_canonical([
    'available' => true,
    'format' => 'duo-checkpoint-provider-response/v1',
    'plaintext_durable' => false,
    'provider_id' => 'ssh-checkpoint-fixture',
    'provider_version' => '1.0.0',
    'state' => 'ready',
    'streaming_authenticated_encryption' => true,
    'temporary_plaintext_cleaned' => true,
]) . "\n";

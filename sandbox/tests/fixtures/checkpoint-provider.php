#!/usr/bin/env php
<?php
declare(strict_types=1);

// Test-only checkpoint capability fixture for standalone SSH adoption and
// controller claim preparation. The complete restore/verify/delete protocol
// is covered by the offline authenticated-encryption regression.

function checkpoint_fixture_canonical(array $value): string {
    ksort($value, SORT_STRING);
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

$request = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$action = $request['action'] ?? '';
if ($action === 'probe') {
    echo checkpoint_fixture_canonical([
        'available' => true,
        'format' => 'wprism-checkpoint-provider-response/v1',
        'plaintext_durable' => false,
        'provider_id' => 'ssh-checkpoint-fixture',
        'provider_version' => '1.0.0',
        'state' => 'ready',
        'streaming_authenticated_encryption' => true,
        'temporary_plaintext_cleaned' => true,
    ]) . "\n";
    exit;
}
if ($action !== 'prepare') {
    fwrite(STDERR, "checkpoint fixture supports probe and prepare only\n");
    exit(41);
}
$directory = (string) ($request['artifact_directory'] ?? '');
$ciphertext = $directory . '/checkpoint.enc';
$verifier = $directory . '/prior-verifier-inputs.json';
$bytes = "fixture checkpoint\n";
if (file_put_contents($ciphertext, $bytes) !== strlen($bytes)) {
    fwrite(STDERR, "checkpoint fixture could not write ciphertext\n");
    exit(42);
}
$inputs = [
    'canonical_tree_sha256' => hash('sha256', 'fixture-tree'),
    'code_revision_sha256' => hash('sha256', 'fixture-code'),
    'database_schema_sha256' => hash('sha256', 'fixture-schema'),
    'format' => 'wprism-prior-verifier-inputs/v1',
    'ledger_session_sha256' => hash('sha256', 'fixture-ledger'),
    'lifecycle_receipts_sha256' => hash('sha256', 'fixture-lifecycle'),
    'manifest_inputs_sha256' => hash('sha256', 'fixture-manifest'),
    'map_state_sha256' => hash('sha256', 'fixture-map'),
    'policy_sha256' => hash('sha256', 'fixture-policy'),
    'runtime_fingerprints_sha256' => hash('sha256', 'fixture-runtime'),
    'state_revision_sha256' => hash('sha256', 'fixture-state'),
];
ksort($inputs, SORT_STRING);
$verifierBytes = json_encode($inputs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($verifier, $verifierBytes) !== strlen($verifierBytes)) {
    fwrite(STDERR, "checkpoint fixture could not write verifier inputs\n");
    exit(43);
}
$cipherHash = hash_file('sha256', $ciphertext);
$verifierHash = hash_file('sha256', $verifier);
$runtime = $inputs['runtime_fingerprints_sha256'];
$ledger = $inputs['ledger_session_sha256'];
echo checkpoint_fixture_canonical([
    'algorithm' => 'fixture-checkpoint',
    'available' => true,
    'ciphertext_path' => $ciphertext,
    'ciphertext_sha256' => $cipherHash,
    'ciphertext_size' => filesize($ciphertext),
    'database_identity_sha256' => hash('sha256', 'fixture-database'),
    'disposable_import_sha256' => hash('sha256', $bytes),
    'disposable_import_verified' => true,
    'export_evidence_sha256' => hash('sha256', $bytes),
    'format' => 'wprism-checkpoint-provider-response/v1',
    'key_id' => (string) ($request['encryption_key_id'] ?? ''),
    'ledger_session_sha256' => $ledger,
    'physical_erasure' => 'fixture-provider-limited',
    'plaintext_durable' => false,
    'prior_verifier_inputs_path' => $verifier,
    'prior_verifier_inputs_sha256' => $verifierHash,
    'provider_id' => 'ssh-checkpoint-fixture',
    'provider_version' => '1.0.0',
    'runtime_fingerprints_sha256' => $runtime,
    'state' => 'prepared',
    'streaming_authenticated_encryption' => true,
    'temporary_plaintext_cleaned' => true,
]) . "\n";

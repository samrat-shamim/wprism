#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Build and verify issue #3299's signed SSH rollback certification evidence.
 *
 * matrix
 * build <spec.json> <secret-key> <bundle.json>
 * verify <bundle.json> <public-key>
 *
 * Stdout is one canonical JSON verdict and the exit status agrees. The
 * matrix is deliberately compiled into this verifier: a harness cannot make
 * a partial run look complete by describing a smaller set of cases.
 */

const SSH_ROLLBACK_INPUT_FORMAT = 'wprism-ssh-rollback-certification-input/v1';
const SSH_ROLLBACK_BUNDLE_FORMAT = 'wprism-ssh-rollback-certification/v1';
const SSH_ROLLBACK_MATRIX_FORMAT = 'wprism-ssh-rollback-crash-matrix/v1';
const SSH_ROLLBACK_SIGNATURE_FORMAT = 'wprism-ssh-rollback-certification-signature/v1';

/** @return list<string> */
function ssh_cert_boundaries(): array {
    return [
        'generation-claim',
        'exclusion-acquire',
        'checkpoint-export',
        'checkpoint-verify',
        'prepared-publication',
        'code-upload',
        'lifecycle-retire',
        'lifecycle-activate',
        'code-prune',
        'code-pointer',
        'upload-local-publish',
        'upload-offload-publish',
        'upload-derivative-publish',
        'authored-database-commit',
        'rebuild',
        'new-verification',
        'committed-publication',
        'rollback-pending-publication',
        'effects-inverse',
        'code-restore',
        'upload-local-restore',
        'upload-offload-restore',
        'upload-created-path-delete',
        'database-abort-before',
        'database-import',
        'database-abort-final',
        'prior-verification',
        'rolled-back-publication',
        'operator-takeover',
        'ssh-loss',
        'checkpoint-retention-delete',
        'code-retention-delete',
        'upload-retention-delete',
    ];
}

/** @return list<string> */
function ssh_cert_faults(): array {
    return ['controller-process-kill', 'remote-command-kill', 'database-connection-loss'];
}

/** @return list<string> */
function ssh_cert_edges(): array {
    return ['before', 'after'];
}

/** @return list<string> */
function ssh_cert_negative_ids(): array {
    return [
        'changed-target-identity',
        'stale-generation',
        'wrong-owner',
        'wrong-artifact',
        'checkpoint-substitution',
        'checkpoint-corruption',
        'unavailable-key',
        'unavailable-adapter',
        'changed-file',
        'concurrent-claimant',
        'concurrent-writer',
        'failed-maintenance-keepalive',
        'terminal-without-prior-verify',
    ];
}

/** @return list<string> */
function ssh_cert_verifier_fields(): array {
    return [
        'adapter_invariants_sha256',
        'artifact_sha256',
        'canonical_first_sha256',
        'canonical_second_sha256',
        'code_inventory_sha256',
        'database_schema_sha256',
        'fresh_processes',
        'ledger_session_sha256',
        'mutable_checkout',
        'process_ids',
        'report_sha256',
        'runtime_fingerprints_sha256',
        'upload_inventory_sha256',
        'world',
    ];
}

function ssh_cert_sort(mixed $value): mixed {
    if (!is_array($value)) return $value;
    if (array_is_list($value)) return array_map('ssh_cert_sort', $value);
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = ssh_cert_sort($item);
    return $value;
}

function ssh_cert_json(mixed $value): string {
    return json_encode(
        ssh_cert_sort($value),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . "\n";
}

function ssh_cert_emit(array $value, int $exit): never {
    fwrite(STDOUT, ssh_cert_json($value));
    exit($exit);
}

/** @return array<string,mixed> */
function ssh_cert_read(string $path, bool $canonical = false): array {
    if (is_link($path) || !is_file($path)) throw new RuntimeException("missing or unsafe JSON file: $path");
    $raw = file_get_contents($path);
    $value = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value) || array_is_list($value)) throw new RuntimeException("JSON root is not an object: $path");
    if ($canonical && ssh_cert_json($value) !== $raw) throw new RuntimeException("JSON is not canonical: $path");
    return $value;
}

/** @param list<string> $expected */
function ssh_cert_exact_keys(array $value, array $expected, string $label): void {
    $actual = array_keys($value);
    sort($actual, SORT_STRING);
    sort($expected, SORT_STRING);
    if ($actual !== $expected) throw new RuntimeException("$label has missing or unknown fields");
}

function ssh_cert_hash(mixed $value, string $label): string {
    if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
        throw new RuntimeException("$label must be a sha256 digest");
    }
    return $value;
}

function ssh_cert_id(mixed $value, string $label): string {
    if (!is_string($value) || preg_match('/^[a-z0-9][a-z0-9._:-]{0,199}$/', $value) !== 1) {
        throw new RuntimeException("$label is malformed");
    }
    return $value;
}

function ssh_cert_time(mixed $value, string $label): string {
    if (!is_string($value)) throw new RuntimeException("$label must be canonical UTC seconds");
    $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
    if (!$time || $time->format('Y-m-d\TH:i:s\Z') !== $value) {
        throw new RuntimeException("$label must be canonical UTC seconds");
    }
    return $value;
}

/** @return array<string,mixed> */
function ssh_cert_matrix(): array {
    $ids = [];
    foreach (ssh_cert_boundaries() as $boundary) {
        foreach (ssh_cert_faults() as $fault) {
            foreach (ssh_cert_edges() as $edge) $ids[] = "$boundary--$fault--$edge";
        }
    }
    $matrix = [
        'boundaries' => ssh_cert_boundaries(),
        'case_ids' => $ids,
        'edges' => ssh_cert_edges(),
        'failure_modes' => ssh_cert_faults(),
        'format' => SSH_ROLLBACK_MATRIX_FORMAT,
    ];
    $matrix['sha256'] = hash('sha256', ssh_cert_json($matrix));
    return $matrix;
}

/** @param array<string,mixed> $verifier */
function ssh_cert_validate_verifier(array $verifier, array $case, string $label): void {
    ssh_cert_exact_keys($verifier, ssh_cert_verifier_fields(), $label);
    foreach ([
        'adapter_invariants_sha256', 'artifact_sha256', 'canonical_first_sha256',
        'canonical_second_sha256', 'code_inventory_sha256', 'database_schema_sha256',
        'ledger_session_sha256', 'report_sha256', 'runtime_fingerprints_sha256',
        'upload_inventory_sha256',
    ] as $field) ssh_cert_hash($verifier[$field] ?? null, "$label.$field");
    if (($verifier['fresh_processes'] ?? null) !== true || ($verifier['mutable_checkout'] ?? null) !== false) {
        throw new RuntimeException("$label did not use immutable fresh processes");
    }
    $processes = $verifier['process_ids'] ?? null;
    if (!is_array($processes) || !array_is_list($processes) || count($processes) !== 2
        || count(array_unique($processes)) !== 2) {
        throw new RuntimeException("$label must bind two distinct verifier processes");
    }
    foreach ($processes as $index => $process) ssh_cert_id($process, "$label.process_ids[$index]");
    if (in_array((string) ($case['injection_process_id'] ?? ''), $processes, true)) {
        throw new RuntimeException("$label reused the injected promotion process");
    }
    if (!hash_equals((string) $verifier['canonical_first_sha256'], (string) $verifier['canonical_second_sha256'])) {
        throw new RuntimeException("$label canonical recaptures differ");
    }
    $terminal = (string) (($case['terminal']['state'] ?? ''));
    $world = $terminal === 'committed' ? 'new' : 'prior';
    if (($verifier['world'] ?? null) !== $world) throw new RuntimeException("$label verifies the wrong world");
    $expectedArtifact = $world === 'new'
        ? (string) ($case['new_artifact_sha256'] ?? '')
        : (string) ($case['prior_artifact_sha256'] ?? '');
    if (!hash_equals($expectedArtifact, (string) $verifier['artifact_sha256'])) {
        throw new RuntimeException("$label artifact does not match the terminal world");
    }
}

/** @param array<string,mixed> $case */
function ssh_cert_validate_case(array $case, string $expectedId): void {
    ssh_cert_exact_keys($case, [
        'boundary', 'case_id', 'edge', 'event_chain_sha256', 'failure_mode',
        'injection_process_id', 'inputs_sha256', 'intermediate', 'new_artifact_sha256',
        'prior_artifact_sha256', 'receipt_sha256', 'resource_fingerprints', 'terminal',
        'verifier', 'versions',
    ], "case $expectedId");
    if (($case['case_id'] ?? null) !== $expectedId) throw new RuntimeException("case id/order mismatch: $expectedId");
    [$boundary, $failure, $edge] = explode('--', $expectedId, 3);
    if (($case['boundary'] ?? null) !== $boundary || ($case['failure_mode'] ?? null) !== $failure
        || ($case['edge'] ?? null) !== $edge) throw new RuntimeException("case dimensions mismatch: $expectedId");
    foreach (['event_chain_sha256', 'inputs_sha256', 'new_artifact_sha256', 'prior_artifact_sha256', 'receipt_sha256'] as $field) {
        ssh_cert_hash($case[$field] ?? null, "case $expectedId.$field");
    }
    ssh_cert_id($case['injection_process_id'] ?? null, "case $expectedId.injection_process_id");
    $intermediate = $case['intermediate'] ?? null;
    if (!is_array($intermediate) || array_is_list($intermediate)) throw new RuntimeException("case $expectedId intermediate is malformed");
    ssh_cert_exact_keys($intermediate, ['authority_state', 'exclusion_held', 'green', 'traffic_closed'], "case $expectedId intermediate");
    if (!in_array($intermediate['authority_state'] ?? null, [
        'none', 'preparing', 'prepared', 'promoting', 'verifying_new',
        'rollback_pending', 'rolling_back', 'verifying_prior', 'committed', 'rolled_back',
    ], true) || ($intermediate['green'] ?? null) !== false
        || ($intermediate['exclusion_held'] ?? null) !== true
        || ($intermediate['traffic_closed'] ?? null) !== true) {
        throw new RuntimeException("case $expectedId intermediate state was not fail-closed");
    }
    $terminal = $case['terminal'] ?? null;
    if (!is_array($terminal) || array_is_list($terminal)) throw new RuntimeException("case $expectedId terminal is malformed");
    ssh_cert_exact_keys($terminal, ['exclusion_released', 'fresh_process_verified', 'green', 'state'], "case $expectedId terminal");
    if (!in_array($terminal['state'] ?? null, ['committed', 'rolled_back'], true)
        || ($terminal['green'] ?? null) !== true || ($terminal['fresh_process_verified'] ?? null) !== true
        || ($terminal['exclusion_released'] ?? null) !== true) {
        throw new RuntimeException("case $expectedId lacks an allowed verified terminal outcome");
    }
    $fingerprints = $case['resource_fingerprints'] ?? null;
    if (!is_array($fingerprints) || array_is_list($fingerprints)) throw new RuntimeException("case $expectedId resource fingerprints are malformed");
    ssh_cert_exact_keys($fingerprints, ['code', 'database', 'effects', 'runtime', 'target', 'uploads'], "case $expectedId resource fingerprints");
    foreach ($fingerprints as $name => $hash) ssh_cert_hash($hash, "case $expectedId resource_fingerprints.$name");
    $versions = $case['versions'] ?? null;
    if (!is_array($versions) || array_is_list($versions)) throw new RuntimeException("case $expectedId versions are malformed");
    ssh_cert_exact_keys($versions, ['checkpoint', 'code_release', 'database', 'effects', 'exclusion', 'harness', 'runtime', 'uploads'], "case $expectedId versions");
    foreach ($versions as $name => $version) ssh_cert_id($version, "case $expectedId versions.$name");
    $verifier = $case['verifier'] ?? null;
    if (!is_array($verifier) || array_is_list($verifier)) throw new RuntimeException("case $expectedId verifier is malformed");
    ssh_cert_validate_verifier($verifier, $case, "case $expectedId verifier");
}

/** @param array<string,mixed> $negative */
function ssh_cert_validate_negative(array $negative, string $expectedId): void {
    ssh_cert_exact_keys($negative, [
        'case_id', 'error_sha256', 'evidence_sha256', 'exclusion_held',
        'mutation_observed', 'refused', 'traffic_closed',
    ], "negative $expectedId");
    if (($negative['case_id'] ?? null) !== $expectedId || ($negative['refused'] ?? null) !== true
        || ($negative['mutation_observed'] ?? null) !== false || ($negative['exclusion_held'] ?? null) !== true
        || ($negative['traffic_closed'] ?? null) !== true) {
        throw new RuntimeException("negative $expectedId was not a mutation-free fail-closed refusal");
    }
    ssh_cert_hash($negative['error_sha256'] ?? null, "negative $expectedId.error_sha256");
    ssh_cert_hash($negative['evidence_sha256'] ?? null, "negative $expectedId.evidence_sha256");
}

/** @return array<string,mixed> normalized payload */
function ssh_cert_validate_spec(array $spec): array {
    ssh_cert_exact_keys($spec, [
        'cases', 'cleanup', 'created_at', 'format', 'harness_revision', 'key_id',
        'negative_cases', 'source', 'target',
    ], 'certification input');
    if (($spec['format'] ?? null) !== SSH_ROLLBACK_INPUT_FORMAT) throw new RuntimeException('unsupported certification input format');
    ssh_cert_time($spec['created_at'] ?? null, 'created_at');
    ssh_cert_hash($spec['harness_revision'] ?? null, 'harness_revision');
    ssh_cert_id($spec['key_id'] ?? null, 'key_id');
    foreach (['source', 'target'] as $side) {
        $identity = $spec[$side] ?? null;
        if (!is_array($identity) || array_is_list($identity)) throw new RuntimeException("$side identity is malformed");
        ssh_cert_exact_keys($identity, ['database_sha256', 'host_sha256'], "$side identity");
        ssh_cert_hash($identity['database_sha256'] ?? null, "$side.database_sha256");
        ssh_cert_hash($identity['host_sha256'] ?? null, "$side.host_sha256");
    }
    if (hash_equals((string) $spec['source']['host_sha256'], (string) $spec['target']['host_sha256'])
        || hash_equals((string) $spec['source']['database_sha256'], (string) $spec['target']['database_sha256'])) {
        throw new RuntimeException('source and target must bind independent SSH hosts and databases');
    }
    $matrix = ssh_cert_matrix();
    $cases = $spec['cases'] ?? null;
    if (!is_array($cases) || !array_is_list($cases) || count($cases) !== count($matrix['case_ids'])) {
        throw new RuntimeException('certification cases do not close the required crash matrix');
    }
    foreach ($matrix['case_ids'] as $index => $id) {
        if (!is_array($cases[$index] ?? null) || array_is_list($cases[$index])) throw new RuntimeException("case $id is malformed");
        ssh_cert_validate_case($cases[$index], $id);
    }
    $negatives = $spec['negative_cases'] ?? null;
    $negativeIds = ssh_cert_negative_ids();
    if (!is_array($negatives) || !array_is_list($negatives) || count($negatives) !== count($negativeIds)) {
        throw new RuntimeException('certification negatives do not close the required refusal matrix');
    }
    foreach ($negativeIds as $index => $id) {
        if (!is_array($negatives[$index] ?? null) || array_is_list($negatives[$index])) throw new RuntimeException("negative $id is malformed");
        ssh_cert_validate_negative($negatives[$index], $id);
    }
    $cleanup = $spec['cleanup'] ?? null;
    if (!is_array($cleanup) || array_is_list($cleanup)) throw new RuntimeException('cleanup evidence is malformed');
    ssh_cert_exact_keys($cleanup, [
        'active_receipt_absent', 'maintenance_lock_absent', 'owned_ssh_fixture_absent',
        'plaintext_checkpoint_absent',
    ], 'cleanup');
    foreach ($cleanup as $name => $ok) if ($ok !== true) throw new RuntimeException("cleanup.$name is not proven");
    unset($spec['format']);
    return [
        'case_count' => count($cases),
        'cases' => $cases,
        'cleanup' => $cleanup,
        'created_at' => $spec['created_at'],
        'format' => SSH_ROLLBACK_BUNDLE_FORMAT,
        'harness_revision' => $spec['harness_revision'],
        'key_id' => $spec['key_id'],
        'matrix_sha256' => $matrix['sha256'],
        'negative_cases' => $negatives,
        'source' => $spec['source'],
        'target' => $spec['target'],
    ];
}

function ssh_cert_key(string $path, int $bytes, string $label): string {
    if (is_link($path) || !is_file($path)) throw new RuntimeException("$label key is missing or unsafe");
    $mode = fileperms($path);
    if ($label === 'secret' && is_int($mode) && (($mode & 0077) !== 0)) {
        throw new RuntimeException('secret key must not be group/world accessible');
    }
    $raw = file_get_contents($path);
    $key = is_string($raw) ? base64_decode(trim($raw), true) : false;
    if (!is_string($key) || strlen($key) !== $bytes || trim((string) $raw) !== base64_encode($key)) {
        throw new RuntimeException("$label key is not canonical base64");
    }
    return $key;
}

function ssh_cert_atomic_write(string $path, string $bytes): void {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('could not create output directory');
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(8));
    $handle = fopen($tmp, 'x+b');
    if (!$handle || fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || !fsync($handle)) {
        if (is_resource($handle)) fclose($handle);
        @unlink($tmp);
        throw new RuntimeException('could not durably write certification bundle');
    }
    fclose($handle);
    chmod($tmp, 0600);
    if (!rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('could not publish certification bundle'); }
}

function ssh_cert_build(string $specPath, string $secretPath, string $output): never {
    $secret = '';
    try {
        $payload = ssh_cert_validate_spec(ssh_cert_read($specPath));
        $secret = ssh_cert_key($secretPath, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 'secret');
        $public = sodium_crypto_sign_publickey_from_secretkey($secret);
        $signature = sodium_crypto_sign_detached(ssh_cert_json($payload), $secret);
        $bundle = [
            'format' => SSH_ROLLBACK_SIGNATURE_FORMAT,
            'key_id' => $payload['key_id'],
            'payload' => $payload,
            'public_key_sha256' => hash('sha256', $public),
            'signature' => base64_encode($signature),
        ];
        ssh_cert_atomic_write($output, ssh_cert_json($bundle));
        ssh_cert_emit([
            'bundle_sha256' => hash('sha256', ssh_cert_json($bundle)),
            'case_count' => $payload['case_count'],
            'format' => SSH_ROLLBACK_BUNDLE_FORMAT,
            'verdict' => 'pass',
        ], 0);
    } catch (Throwable $e) {
        ssh_cert_emit(['format' => SSH_ROLLBACK_BUNDLE_FORMAT, 'reason' => $e->getMessage(), 'verdict' => 'failed'], 1);
    } finally {
        if ($secret !== '') sodium_memzero($secret);
    }
}

function ssh_cert_verify(string $bundlePath, string $publicPath): never {
    try {
        $bundle = ssh_cert_read($bundlePath, true);
        ssh_cert_exact_keys($bundle, ['format', 'key_id', 'payload', 'public_key_sha256', 'signature'], 'signed bundle');
        if (($bundle['format'] ?? null) !== SSH_ROLLBACK_SIGNATURE_FORMAT) throw new RuntimeException('unsupported signed bundle format');
        $public = ssh_cert_key($publicPath, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'public');
        if (!hash_equals(hash('sha256', $public), ssh_cert_hash($bundle['public_key_sha256'] ?? null, 'public_key_sha256'))) {
            throw new RuntimeException('bundle public key fingerprint mismatch');
        }
        $signature = base64_decode((string) ($bundle['signature'] ?? ''), true);
        if (!is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !is_array($bundle['payload'] ?? null) || array_is_list($bundle['payload'])
            || !sodium_crypto_sign_verify_detached($signature, ssh_cert_json($bundle['payload']), $public)) {
            throw new RuntimeException('bundle signature is invalid');
        }
        $payload = $bundle['payload'];
        if (($payload['format'] ?? null) !== SSH_ROLLBACK_BUNDLE_FORMAT) throw new RuntimeException('unsupported payload format');
        $spec = $payload;
        $spec['format'] = SSH_ROLLBACK_INPUT_FORMAT;
        unset($spec['case_count'], $spec['matrix_sha256']);
        $validated = ssh_cert_validate_spec($spec);
        if (!hash_equals((string) $payload['matrix_sha256'], (string) $validated['matrix_sha256'])
            || (int) $payload['case_count'] !== (int) $validated['case_count']
            || !hash_equals((string) $bundle['key_id'], (string) $payload['key_id'])) {
            throw new RuntimeException('signed payload summary does not match verified evidence');
        }
        ssh_cert_emit([
            'bundle_sha256' => hash_file('sha256', $bundlePath),
            'case_count' => $payload['case_count'],
            'format' => SSH_ROLLBACK_BUNDLE_FORMAT,
            'verdict' => 'valid',
        ], 0);
    } catch (Throwable $e) {
        ssh_cert_emit(['format' => SSH_ROLLBACK_BUNDLE_FORMAT, 'reason' => $e->getMessage(), 'verdict' => 'corrupt'], 1);
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $command = $argv[1] ?? '';
    if ($command === 'matrix' && $argc === 2) ssh_cert_emit(ssh_cert_matrix(), 0);
    if ($command === 'build' && $argc === 5) ssh_cert_build($argv[2], $argv[3], $argv[4]);
    if ($command === 'verify' && $argc === 4) ssh_cert_verify($argv[2], $argv[3]);
    ssh_cert_emit(['format' => SSH_ROLLBACK_BUNDLE_FORMAT, 'reason' => 'usage', 'verdict' => 'failed'], 1);
}

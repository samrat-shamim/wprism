<?php
// DUO-3324 live fixture: a machine-local, direct-argv branch-environment
// provider.  It intentionally knows only Docker resource locations and
// opaque hashes/receipts; WordPress state continues through the public duo
// CLI and its configured drivers.
declare(strict_types=1);

/** @return mixed */
function live_json_file(string $path, mixed $fallback): mixed {
    if (!is_file($path)) return $fallback;
    $bytes = file_get_contents($path);
    if (!is_string($bytes)) throw new RuntimeException("could not read '$path'");
    return json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
}

/** @return mixed */
function live_normalize(mixed $value): mixed {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as $key => $child) $value[$key] = live_normalize($child);
    return $value;
}

function live_json(mixed $value): string {
    return json_encode(live_normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function live_hash(mixed $value): string {
    return hash('sha256', is_string($value) ? $value : live_json($value));
}

function live_require(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

/** @return array{exit:int,stdout:string,stderr:string} */
function live_run(array $argv, ?string $stdin = null): array {
    $pipes = [];
    $proc = proc_open(
        $argv,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($proc)) throw new RuntimeException('could not start fixture provider command');
    if ($stdin !== null && fwrite($pipes[0], $stdin) !== strlen($stdin)) {
        fclose($pipes[0]); fclose($pipes[1]); fclose($pipes[2]); proc_terminate($proc);
        throw new RuntimeException('could not send fixture provider command input');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return ['exit' => proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
}

function live_checked(array $argv, ?string $stdin = null): string {
    $result = live_run($argv, $stdin);
    if ($result['exit'] !== 0) {
        throw new RuntimeException('fixture provider command failed: ' . implode(' ', $argv) . ' :: ' . trim($result['stderr']));
    }
    return $result['stdout'];
}

function live_remove_tree(string $path): void {
    if (is_link($path) || is_file($path)) {
        @chmod($path, 0600);
        if (!unlink($path)) throw new RuntimeException("could not remove '$path'");
        return;
    }
    if (!is_dir($path)) return;
    // Immutable snapshot/readback evidence is intentionally published
    // read-only.  It is still fixture-owned state, so recover its exact
    // owner permissions before a later idempotent abort/cleanup removes it.
    live_chmod_tree($path, 0700);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $name = $item->getPathname();
        if ($item->isLink() || $item->isFile()) {
            if (!unlink($name)) throw new RuntimeException("could not remove '$name'");
        } elseif (!rmdir($name)) {
            throw new RuntimeException("could not remove '$name'");
        }
    }
    if (!rmdir($path)) throw new RuntimeException("could not remove '$path'");
}

function live_chmod_tree(string $path, int $mode = 0777): void {
    if (!file_exists($path) && !is_link($path)) return;
    @chmod($path, $mode);
    if (!is_dir($path) || is_link($path)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) @chmod($item->getPathname(), $mode);
}

/** Deterministic byte/tree digest for the fixture's opaque media store. */
function live_tree_hash(string $root): string {
    $context = hash_init('sha256');
    if (!is_dir($root)) {
        hash_update($context, "absent\0");
        return hash_final($context);
    }
    $rows = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        $relative = substr($path, strlen(rtrim($root, '/')) + 1);
        $rows[] = [$relative, $item->isDir() ? 'd' : ($item->isLink() ? 'l' : 'f'), $path];
    }
    usort($rows, static fn(array $a, array $b): int => strcmp($a[0], $b[0]));
    foreach ($rows as [$relative, $type, $path]) {
        hash_update($context, $type . "\0" . $relative . "\0");
        if ($type === 'f') {
            $bytes = file_get_contents($path);
            if (!is_string($bytes)) throw new RuntimeException("could not hash media '$path'");
            hash_update($context, hash('sha256', $bytes, true));
        } elseif ($type === 'l') {
            hash_update($context, (string) readlink($path));
        }
    }
    return hash_final($context);
}

/** @return array<string,mixed> */
function live_identity(array $environment): array {
    return [
        'environment_identity' => $environment['environment_identity'],
        'lease_generation' => 1,
        'lease_id' => $environment['lease_id'],
        'ownership_receipt_sha256' => live_hash('ownership:' . $environment['environment_identity']),
        'resource_id' => $environment['resource_id'],
        'url' => $environment['url'],
    ];
}

function live_assert_identity_input(array $input, array $identity): void {
    $expected = [
        'expected_environment_identity' => $identity['environment_identity'],
        'expected_lease_generation' => $identity['lease_generation'],
        'expected_lease_id' => $identity['lease_id'],
        'expected_ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
        'expected_resource_id' => $identity['resource_id'],
    ];
    foreach ($expected as $key => $value) {
        live_require(array_key_exists($key, $input) && $input[$key] === $value, "identity input differs at '$key'");
    }
}

/** @return list<string> */
function live_capabilities(): array {
    return [
        'environment.attach', 'environment.create', 'environment.destroy', 'environment.detach', 'environment.inspect',
        'environment.mutation.acquire', 'environment.mutation.read', 'environment.mutation.release',
        'environment.ttl', 'environment.ttl.read', 'environment.url.discover', 'environment.url.set',
        'operation.receipts', 'repository.materialize', 'snapshot.set.abort', 'snapshot.set.create',
        'snapshot.set.prepare', 'snapshot.set.read', 'snapshot.set.restore',
    ];
}

/** @return array<string,mixed> */
function live_load_state(string $root): array {
    $state = live_json_file($root . '/state.json', [
        'fences' => [], 'sessions' => [], 'snapshots' => [], 'ttls' => [], 'resources' => [],
    ]);
    live_require(is_array($state) && !array_is_list($state), 'fixture provider state is malformed');
    foreach (['fences', 'sessions', 'snapshots', 'ttls', 'resources'] as $key) {
        live_require(is_array($state[$key] ?? null), "fixture provider state '$key' is malformed");
    }
    return $state;
}

function live_save_state(string $root, array $state): void {
    $path = $root . '/state.json';
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
    $bytes = live_json($state) . "\n";
    if (file_put_contents($tmp, $bytes, LOCK_EX) !== strlen($bytes) || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('could not publish fixture provider state');
    }
    chmod($path, 0600);
}

function live_log(string $root, array $request): void {
    $record = [
        'action' => $request['action'],
        'environment' => $request['environment'],
        'input_sha256' => live_hash($request['input']),
        'operation_id' => $request['operation_id'],
    ];
    file_put_contents($root . '/actions.ndjson', live_json($record) . "\n", FILE_APPEND | LOCK_EX);
}

function live_snapshot_key(string $environment, string $operation): string { return $environment . '|' . $operation; }
function live_fence_key(string $id): string { return $id; }
function live_ttl_key(string $environment): string { return $environment; }

/** @return array<string,mixed> */
function live_snapshot_result(array $snapshot, bool $read = false): array {
    $result = [
        'database_sha256' => $snapshot['database_sha256'],
        'lease_generation' => $snapshot['lease_generation'],
        'lease_id' => $snapshot['lease_id'],
        'lease_receipt_sha256' => $snapshot['lease_receipt_sha256'],
        'media_sha256' => $snapshot['media_sha256'],
        'retention_receipt_sha256' => $snapshot['retention_receipt_sha256'],
        'semantic_snapshot_sha256' => $snapshot['semantic_snapshot_sha256'],
        'snapshot_session_id' => $snapshot['snapshot_session_id'],
        'snapshot_set_id' => $snapshot['snapshot_set_id'],
        'snapshot_set_receipt_sha256' => $snapshot['snapshot_set_receipt_sha256'],
        'source_identity' => $snapshot['source_identity'],
    ];
    if ($read) $result['immutable'] = true;
    return $result;
}

/** @return array<string,mixed> */
function live_fence_result(array $identity, array $fence): array {
    return $identity + [
        'mutation_generation' => $fence['generation'],
        'mutation_id' => $fence['id'],
        'mutation_owner' => $fence['owner'],
        'mutation_receipt_sha256' => $fence['receipt'],
        'state' => $fence['state'],
    ];
}

/** @return array<string,mixed> */
function live_require_fence(
    array &$state,
    array $input,
    array $identity,
    bool $mustBeHeld = true,
    bool $allowHeldReceiptAfterRelease = false
): array {
    live_assert_identity_input($input, $identity);
    foreach (['expected_mutation_generation', 'expected_mutation_id', 'expected_mutation_owner', 'expected_mutation_receipt_sha256'] as $key) {
        live_require(array_key_exists($key, $input), "mutation input is missing '$key'");
    }
    $id = (string) $input['expected_mutation_id'];
    $fence = $state['fences'][live_fence_key($id)] ?? null;
    live_require(is_array($fence), 'mutation fence is unknown');
    live_require($fence['generation'] === $input['expected_mutation_generation']
        && $fence['owner'] === $input['expected_mutation_owner'], 'mutation fence lineage changed');
    // A normal read is bound to the provider's current immutable receipt: a
    // released->released read must authenticate the release acknowledgement,
    // not silently accept the old held CAS receipt.  The sole exception is an
    // idempotent reissue of mutation-release after its response was lost; that
    // action deliberately retains the original held CAS tuple.
    $expectedReceipt = $allowHeldReceiptAfterRelease
        ? $fence['held_receipt']
        : ($fence['state'] === 'held' ? $fence['held_receipt'] : $fence['receipt']);
    live_require($expectedReceipt === $input['expected_mutation_receipt_sha256'], 'mutation fence receipt differs');
    if ($mustBeHeld) live_require($fence['state'] === 'held', 'mutation fence is not held');
    return $fence;
}

function live_driver_wp(array $config, string $service, array $args): string {
    return live_checked(array_merge([
        'docker', 'compose', '-f', $config['driver_compose'], 'run', '--rm', '-T', $service, 'wp',
    ], $args));
}

function live_dump_database(array $config, string $database): string {
    return live_checked([
        'docker', 'exec', $config['db_container'], 'mariadb-dump', '--single-transaction', '--skip-comments',
        '-uroot', '-proot', $database,
    ]);
}

function live_restore_database(array $config, string $database, string $dump): void {
    live_checked(['docker', 'exec', '-i', $config['db_container'], 'mariadb', '-uroot', '-proot'],
        "DROP DATABASE IF EXISTS `$database`; CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n");
    live_checked(['docker', 'exec', '-i', $config['db_container'], 'mariadb', '-uroot', '-proot', $database], $dump);
    if ($dump !== '') {
        live_require(
            hash('sha256', live_dump_database($config, $database)) === hash('sha256', $dump),
            'database restore readback differs from the immutable snapshot bytes'
        );
    }
}

function live_copy_media_from_container(string $container, string $destination): void {
    if (!is_dir($destination) && !mkdir($destination, 0700, true)) {
        throw new RuntimeException("could not create media destination '$destination'");
    }
    // docker cp preserves container ownership, which can strand root-owned
    // fixture snapshots on the host.  Stream a tar archive instead: host tar
    // owns the extracted exact bytes, so immutable evidence remains safely
    // removable by this operation's deterministic cleanup.
    $archive = live_checked([
        'docker', 'exec', $container, 'tar', '-C', '/var/www/html/wp-content/uploads', '-cf', '-', '.',
    ]);
    live_checked(['tar', '-xf', '-', '-C', $destination], $archive);
}

function live_restore_media_to_container(string $source, string $container): void {
    live_checked(['docker', 'exec', $container, 'sh', '-c', 'rm -rf /var/www/html/wp-content/uploads && mkdir -p /var/www/html/wp-content/uploads']);
    live_checked(['docker', 'cp', rtrim($source, '/') . '/.', $container . ':/var/www/html/wp-content/uploads']);
}

function live_clear_target(array $environment, array $config): void {
    live_restore_database($config, $environment['database'], '');
    live_checked(['docker', 'exec', $environment['container'], 'sh', '-c', 'rm -rf /var/www/html/wp-content/uploads && mkdir -p /var/www/html/wp-content/uploads']);
    $repo = $environment['repo'];
    live_remove_tree($repo);
    if (!mkdir($repo, 0777, true) && !is_dir($repo)) throw new RuntimeException('could not recreate destroyed target repository');
    chmod($repo, 0777);
}

/** @return array<string,mixed> */
function live_dispatch(array $request, array $config, array &$state): array {
    $environmentName = $request['environment'];
    $environment = $config['environments'][$environmentName] ?? null;
    live_require(is_array($environment), "unknown fixture environment '$environmentName'");
    $identity = live_identity($environment);
    $input = $request['input'];
    $operation = $request['operation_id'];
    $action = $request['action'];
    $source = $config['environments'][$config['source_environment']] ?? null;
    live_require(is_array($source), 'fixture source environment is unavailable');

    if ($action === 'capabilities') return ['capabilities' => live_capabilities()];
    if ($action === 'inspect') return $identity + ['presence' => 'present'];
    if ($action === 'attach' || $action === 'create') {
        live_require(($input['mode'] ?? null) === ($action === 'attach' ? 'attach' : 'create'), 'target acquisition mode is malformed');
        $state['resources'][$environmentName] = ['mode' => $action, 'operation_id' => $operation, 'state' => 'present'];
        return $identity + ['presence' => 'present'];
    }

    if ($action === 'snapshot-prepare') {
        live_require(($environment['role'] ?? null) === 'source', 'only the source can prepare a snapshot');
        live_assert_identity_input($input, $identity);
        $session = $input['snapshot_session_id'] ?? null;
        live_require(is_string($session) && $session !== '', 'snapshot session is absent');
        $key = live_snapshot_key($environmentName, $operation);
        $prepared = $state['sessions'][$key] ?? null;
        if (!is_array($prepared)) {
            $staging = $config['state_root'] . '/prepared/' . hash('sha256', $key);
            live_remove_tree($staging);
            if (!mkdir($staging . '/media', 0700, true)) throw new RuntimeException('could not create source snapshot staging');
            $dump = live_dump_database($config, $environment['database']);
            file_put_contents($staging . '/database.sql', $dump, LOCK_EX);
            live_copy_media_from_container($environment['container'], $staging . '/media');
            $prepared = [
                'database_sha256' => hash_file('sha256', $staging . '/database.sql'),
                'lease_generation' => 1,
                'lease_id' => 'snapshot-lease-' . substr(hash('sha256', $key), 0, 20),
                'lease_receipt_sha256' => live_hash('snapshot-lease:' . $key),
                'media_sha256' => live_tree_hash($staging . '/media'),
                'path' => $staging,
                'snapshot_session_id' => $session,
                'source_identity' => $identity['environment_identity'],
                'state' => 'prepared',
            ];
            $state['sessions'][$key] = $prepared;
        }
        live_require($prepared['snapshot_session_id'] === $session, 'snapshot prepare session changed');
        return [
            'lease_generation' => $prepared['lease_generation'],
            'lease_id' => $prepared['lease_id'],
            'lease_receipt_sha256' => $prepared['lease_receipt_sha256'],
            'snapshot_session_id' => $prepared['snapshot_session_id'],
            'source_identity' => $prepared['source_identity'],
        ];
    }

    if ($action === 'snapshot-create') {
        live_require(($environment['role'] ?? null) === 'source', 'only the source can create a snapshot');
        $key = live_snapshot_key($environmentName, $operation);
        $prepared = $state['sessions'][$key] ?? null;
        live_require(is_array($prepared) && $prepared['state'] === 'prepared', 'snapshot create has no prepared source session');
        foreach ([
            'expected_snapshot_session_id' => 'snapshot_session_id', 'expected_source_identity' => 'source_identity',
            'expected_source_lease_generation' => 'lease_generation', 'expected_source_lease_id' => 'lease_id',
            'expected_source_lease_receipt_sha256' => 'lease_receipt_sha256',
        ] as $provided => $stored) {
            live_require(($input[$provided] ?? null) === $prepared[$stored], "snapshot create differs at '$provided'");
        }
        $snapshot = $state['snapshots'][$key] ?? null;
        if (!is_array($snapshot)) {
            $currentDump = live_dump_database($config, $environment['database']);
            $currentDb = hash('sha256', $currentDump);
            $mediaScratch = $config['state_root'] . '/create-media-' . hash('sha256', $key);
            live_remove_tree($mediaScratch);
            live_copy_media_from_container($environment['container'], $mediaScratch);
            $currentMedia = live_tree_hash($mediaScratch);
            // The fixture's freeze witness is deliberately fail-closed: a
            // source write between prepare and create refuses rather than
            // quietly combining semantic P with a different physical set.
            $changed = [];
            if ($currentDb !== $prepared['database_sha256']) $changed[] = 'database';
            if ($currentMedia !== $prepared['media_sha256']) $changed[] = 'media';
            live_require($changed === [],
                'source changed while its prepared snapshot session was frozen (' . implode(',', $changed) . ')');
            $snapshotDir = $config['state_root'] . '/snapshots/' . hash('sha256', $key);
            live_remove_tree($snapshotDir);
            if (!mkdir($snapshotDir, 0700, true)) throw new RuntimeException('could not create immutable snapshot directory');
            file_put_contents($snapshotDir . '/database.sql', $currentDump, LOCK_EX);
            if (!rename($mediaScratch, $snapshotDir . '/media')) throw new RuntimeException('could not publish immutable media snapshot');
            $snapshot = [
                'database_path' => $snapshotDir . '/database.sql',
                'database_sha256' => $currentDb,
                'lease_generation' => $prepared['lease_generation'],
                'lease_id' => $prepared['lease_id'],
                'lease_receipt_sha256' => $prepared['lease_receipt_sha256'],
                'media_path' => $snapshotDir . '/media',
                'media_sha256' => $currentMedia,
                'retention_receipt_sha256' => live_hash('retention:' . $key),
                'semantic_snapshot_sha256' => $input['expected_semantic_snapshot_sha256'],
                'snapshot_session_id' => $prepared['snapshot_session_id'],
                'snapshot_set_id' => 'snapshot-set-' . substr(hash('sha256', $key), 0, 20),
                'source_identity' => $prepared['source_identity'],
            ];
            $snapshot['snapshot_set_receipt_sha256'] = live_hash([
                'database_sha256' => $snapshot['database_sha256'], 'media_sha256' => $snapshot['media_sha256'],
                'semantic_snapshot_sha256' => $snapshot['semantic_snapshot_sha256'], 'snapshot_set_id' => $snapshot['snapshot_set_id'],
            ]);
            live_chmod_tree($snapshotDir, 0555);
            $state['snapshots'][$key] = $snapshot;
        }
        live_require(($snapshot['semantic_snapshot_sha256'] ?? null) === ($input['expected_semantic_snapshot_sha256'] ?? null),
            'snapshot create semantic identity changed');
        return live_snapshot_result($snapshot);
    }

    if ($action === 'snapshot-read') {
        live_require(($environment['role'] ?? null) === 'source', 'only the source can read a snapshot');
        $key = live_snapshot_key($environmentName, $operation);
        $snapshot = $state['snapshots'][$key] ?? null;
        live_require(is_array($snapshot), 'snapshot read has no created set');
        foreach (['expected_snapshot_set_id' => 'snapshot_set_id', 'expected_snapshot_set_receipt_sha256' => 'snapshot_set_receipt_sha256',
            'expected_snapshot_session_id' => 'snapshot_session_id', 'expected_source_identity' => 'source_identity',
            'expected_source_lease_generation' => 'lease_generation', 'expected_source_lease_id' => 'lease_id',
            'expected_source_lease_receipt_sha256' => 'lease_receipt_sha256'] as $provided => $stored) {
            live_require(($input[$provided] ?? null) === $snapshot[$stored], "snapshot read differs at '$provided'");
        }
        live_require(hash_file('sha256', $snapshot['database_path']) === $snapshot['database_sha256']
            && live_tree_hash($snapshot['media_path']) === $snapshot['media_sha256'], 'immutable snapshot bytes changed');
        return live_snapshot_result($snapshot, true);
    }

    if ($action === 'snapshot-abort') {
        live_require(($environment['role'] ?? null) === 'source', 'only the source can abort a snapshot');
        $key = live_snapshot_key($environmentName, $operation);
        $prepared = $state['sessions'][$key] ?? null;
        live_require(is_array($prepared), 'snapshot abort has no prepared session');
        foreach (['expected_snapshot_session_id' => 'snapshot_session_id', 'expected_source_identity' => 'source_identity',
            'expected_source_lease_generation' => 'lease_generation', 'expected_source_lease_id' => 'lease_id',
            'expected_source_lease_receipt_sha256' => 'lease_receipt_sha256'] as $provided => $stored) {
            live_require(($input[$provided] ?? null) === $prepared[$stored], "snapshot abort differs at '$provided'");
        }
        $prepared['state'] = 'aborted';
        $state['sessions'][$key] = $prepared;
        return [
            'disposition' => 'aborted', 'lease_generation' => $prepared['lease_generation'], 'lease_id' => $prepared['lease_id'],
            'lease_receipt_sha256' => $prepared['lease_receipt_sha256'], 'snapshot_session_id' => $prepared['snapshot_session_id'],
            'source_identity' => $prepared['source_identity'],
        ];
    }

    if ($action === 'mutation-acquire') {
        live_assert_identity_input($input, $identity);
        $owner = $input['mutation_owner'] ?? null;
        live_require(is_string($owner) && $owner !== '', 'mutation acquire has no owner');
        $id = 'mutation-' . substr(hash('sha256', $environmentName . '|' . $operation), 0, 20);
        $fence = $state['fences'][live_fence_key($id)] ?? null;
        if (!is_array($fence)) {
            $receipt = live_hash('held:' . $id . ':' . $owner);
            $fence = [
                'generation' => 1, 'held_receipt' => $receipt, 'id' => $id, 'owner' => $owner,
                'receipt' => $receipt, 'state' => 'held',
            ];
            $state['fences'][live_fence_key($id)] = $fence;
        }
        live_require($fence['owner'] === $owner && $fence['state'] === 'held', 'mutation acquire is not idempotent/exclusive');
        return live_fence_result($identity, $fence);
    }

    if ($action === 'mutation-read') {
        $fence = live_require_fence($state, $input, $identity, false);
        return live_fence_result($identity, $fence);
    }

    if ($action === 'mutation-release') {
        $fence = live_require_fence($state, $input, $identity, false, true);
        if ($fence['state'] === 'held') {
            $fence['state'] = 'released';
            $fence['receipt'] = live_hash('released:' . $fence['id'] . ':' . $fence['owner']);
            $state['fences'][live_fence_key($fence['id'])] = $fence;
        }
        live_require($fence['state'] === 'released', 'mutation release has an invalid fence state');
        return live_fence_result($identity, $fence);
    }

    if ($action === 'snapshot-restore') {
        $fence = live_require_fence($state, $input, $identity);
        $snapshotId = $input['snapshot_set_id'] ?? null;
        $snapshot = null;
        foreach ($state['snapshots'] as $candidate) if (is_array($candidate) && ($candidate['snapshot_set_id'] ?? null) === $snapshotId) $snapshot = $candidate;
        live_require(is_array($snapshot) && ($input['database_sha256'] ?? null) === $snapshot['database_sha256']
            && ($input['media_sha256'] ?? null) === $snapshot['media_sha256'], 'snapshot restore is not bound to the immutable set');
        $dump = file_get_contents($snapshot['database_path']);
        if (!is_string($dump)) throw new RuntimeException('could not read immutable database snapshot');
        live_restore_database($config, $environment['database'], $dump);
        live_restore_media_to_container($snapshot['media_path'], $environment['container']);
        return $identity + ['snapshot_set_id' => $snapshot['snapshot_set_id']];
    }

    if ($action === 'repository-materialize') {
        live_require_fence($state, $input, $identity);
        $commit = $input['branch_commit'] ?? null;
        live_require(is_string($commit) && preg_match('/^[a-f0-9]{40}$/D', $commit) === 1, 'repository materialization commit is invalid');
        $repo = $environment['repo'];
        $tmp = $repo . '.incoming-' . substr(hash('sha256', $operation), 0, 12);
        live_remove_tree($tmp);
        live_remove_tree($repo);
        live_checked(['git', 'clone', '--no-hardlinks', '--no-local', $config['controller_repo'], $tmp]);
        live_checked(['git', '-C', $tmp, 'checkout', '--detach', $commit]);
        if (!rename($tmp, $repo)) throw new RuntimeException('could not publish independent target repository');
        live_chmod_tree($repo);
        return $identity + ['branch_commit' => $commit, 'repository_receipt_sha256' => live_hash(['commit' => $commit, 'repo' => $repo])];
    }

    if ($action === 'url-set') {
        live_require_fence($state, $input, $identity);
        live_require(($input['url'] ?? null) === $identity['url'], 'provider URL differs from target identity');
        $service = $environment['service'];
        live_driver_wp($config, $service, ['option', 'update', 'home', $identity['url'], '--quiet']);
        live_driver_wp($config, $service, ['option', 'update', 'siteurl', $identity['url'], '--quiet']);
        return $identity;
    }

    if ($action === 'ttl-set') {
        live_require_fence($state, $input, $identity);
        $seconds = $input['ttl_seconds'] ?? null;
        live_require(is_int($seconds) && $seconds >= 60, 'TTL set is invalid');
        $ttl = $state['ttls'][live_ttl_key($environmentName)] ?? null;
        if (!is_array($ttl) || ($ttl['operation_id'] ?? null) !== $operation) {
            $ttl = [
                'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', time() + $seconds), 'generation' => 1,
                'lease_id' => 'ttl-lease-' . substr(hash('sha256', $environmentName . '|' . $operation), 0, 20),
                'operation_id' => $operation,
            ];
            $ttl['receipt'] = live_hash([
                'expires_at' => $ttl['expires_at'], 'generation' => $ttl['generation'], 'lease_id' => $ttl['lease_id'],
                'operation_id' => $ttl['operation_id'],
            ]);
            $state['ttls'][live_ttl_key($environmentName)] = $ttl;
        }
        return $identity + [
            'expires_at' => $ttl['expires_at'], 'ttl_generation' => $ttl['generation'], 'ttl_lease_id' => $ttl['lease_id'],
            'ttl_receipt_sha256' => $ttl['receipt'], 'ttl_state' => 'active',
        ];
    }

    if ($action === 'ttl-read') {
        live_assert_identity_input($input, $identity);
        $ttl = $state['ttls'][live_ttl_key($environmentName)] ?? null;
        live_require(is_array($ttl), 'TTL read has no active lease');
        foreach (['expected_expires_at' => 'expires_at', 'expected_ttl_generation' => 'generation',
            'expected_ttl_lease_id' => 'lease_id', 'expected_ttl_receipt_sha256' => 'receipt'] as $provided => $stored) {
            live_require(($input[$provided] ?? null) === $ttl[$stored], "TTL read differs at '$provided'");
        }
        return $identity + [
            'expires_at' => $ttl['expires_at'], 'ttl_generation' => $ttl['generation'], 'ttl_lease_id' => $ttl['lease_id'],
            'ttl_receipt_sha256' => $ttl['receipt'], 'ttl_state' => 'active',
        ];
    }

    if ($action === 'destroy' || $action === 'detach') {
        live_require_fence($state, $input, $identity);
        live_require(($input['compare_and_reap'] ?? null) === true, 'reap lacks compare-and-reap intent');
        if ($action === 'destroy') live_clear_target($environment, $config);
        $state['resources'][$environmentName] = ['mode' => $action, 'operation_id' => $operation, 'state' => 'absent'];
        return [
            'absence_proof_sha256' => live_hash([$action, $identity['environment_identity'], $identity['resource_id'], $operation]),
            'disposition' => $action === 'destroy' ? 'destroyed' : 'detached',
            'environment_identity' => $identity['environment_identity'], 'lease_generation' => $identity['lease_generation'],
            'lease_id' => $identity['lease_id'], 'ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
            'resource_id' => $identity['resource_id'],
        ];
    }
    throw new RuntimeException("unsupported fixture provider action '$action'");
}

function live_control(string $verb, string $configPath, string $environment): void {
    $config = live_json_file($configPath, null);
    live_require(is_array($config), 'fixture provider config is malformed');
    $root = $config['state_root'] ?? null;
    live_require(is_string($root) && $root !== '', 'fixture provider state root is absent');
    $lock = fopen($root . '/state.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('could not lock fixture provider state');
    try {
        $state = live_load_state($root);
        $key = live_ttl_key($environment);
        $ttl = $state['ttls'][$key] ?? null;
        live_require(is_array($ttl), 'fixture control has no TTL to mutate');
        if ($verb === '--mutate-ttl') {
            $ttl['_original'] = [
                'expires_at' => $ttl['expires_at'], 'generation' => $ttl['generation'], 'lease_id' => $ttl['lease_id'], 'receipt' => $ttl['receipt'],
            ];
            $ttl['generation']++;
            $ttl['lease_id'] = 'ttl-lease-mutated-' . substr(hash('sha256', $environment), 0, 16);
            $ttl['receipt'] = live_hash($ttl);
            $state['ttls'][$key] = $ttl;
        } elseif ($verb === '--restore-ttl') {
            live_require(is_array($ttl['_original'] ?? null), 'fixture control has no original TTL');
            $original = $ttl['_original'];
            $state['ttls'][$key] = $original;
        } else {
            throw new RuntimeException("unknown fixture control '$verb'");
        }
        live_save_state($root, $state);
    } finally {
        flock($lock, LOCK_UN); fclose($lock);
    }
}

try {
    if ($argc === 4 && in_array($argv[1], ['--mutate-ttl', '--restore-ttl'], true)) {
        live_control($argv[1], $argv[2], $argv[3]);
        exit(0);
    }
    live_require($argc === 2, 'usage: duo3324-live-provider.php <config.json>');
    $config = live_json_file($argv[1], null);
    live_require(is_array($config) && !array_is_list($config), 'fixture provider config is malformed');
    $root = $config['state_root'] ?? null;
    live_require(is_string($root) && $root !== '' && is_dir($root), 'fixture provider state root is unavailable');
    $raw = (string) stream_get_contents(STDIN);
    $request = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    live_require(is_array($request) && !array_is_list($request), 'provider request is malformed');
    foreach (['action', 'environment', 'format', 'input', 'operation_id'] as $key) live_require(array_key_exists($key, $request), "provider request lacks '$key'");
    // CommandEnvironmentProvider uses [] for an empty object input (the
    // capabilities probe), while rejecting non-empty JSON lists.  Mirror
    // that boundary exactly so the fixture accepts the canonical v1 probe
    // without accepting a list-shaped operation payload.
    live_require($request['format'] === 'duo-branch-environment-provider-request/v1'
        && is_array($request['input'])
        && (!array_is_list($request['input']) || $request['input'] === []), 'provider request has an invalid protocol shape');
    $lock = fopen($root . '/state.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('could not lock fixture provider state');
    try {
        $state = live_load_state($root);
        live_log($root, $request);
        $result = live_dispatch($request, $config, $state);
        live_save_state($root, $state);
    } finally {
        flock($lock, LOCK_UN); fclose($lock);
    }
    $response = [
        'action' => $request['action'], 'environment' => $request['environment'],
        'format' => 'duo-branch-environment-provider-response/v1', 'operation_id' => $request['operation_id'],
        'provider' => ['id' => 'duo3324-live-fixture', 'protocol' => 1], 'result' => $result, 'status' => 'ok',
    ];
    echo live_json($response) . "\n";
} catch (Throwable $error) {
    if (isset($config) && is_array($config) && is_string($config['state_root'] ?? null)
        && is_dir($config['state_root'])) {
        @file_put_contents(
            $config['state_root'] . '/provider-errors.log',
            $error->getMessage() . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
    fwrite(STDERR, 'duo3324 live provider: ' . $error->getMessage() . "\n");
    exit(1);
}

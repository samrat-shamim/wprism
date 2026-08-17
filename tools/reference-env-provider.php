<?php
// Reference branch-environment provider for the sandbox pair (round-3 MUP
// §2.2, last bullet). DEV-ONLY, free zone: tools/ never ships, and this file
// is not in the certification closure.
//
// WHAT THIS IS
// ------------
// `duo rehearse` needs a machine-local provider that can snapshot a source
// environment, acquire a disposable target, materialize a repository into it
// and reap it again. Duo orchestrates providers; it does not supply hosting,
// so every customer writes their own. This is the worked example, driving the
// one host Duo's own estate has: `sandbox/bin/pair.sh`'s two-sided pair on the
// shared MariaDB (sandbox/db.yml).
//
// It is DERIVED FROM sandbox/tests/fixtures/duo3324-live-provider.php and
// keeps that file's argv and JSON contract with
// `\Duo\Orchestrator\CommandEnvironmentProvider` byte-for-byte:
//
//   * argv is exactly `<provider> <config.json>`; the request is one canonical
//     JSON object on stdin and the response is one canonical JSON object plus
//     a newline on stdout (CommandEnvironmentProvider::call() re-encodes the
//     response and compares bytes, so any other spacing or key order is
//     "noncanonical evidence" and the operation refuses).
//   * the request's closed key set is {action, environment, format, input,
//     operation_id}; `format` must be
//     `duo-branch-environment-provider-request/v1`; `input` is an object, and
//     the empty LIST `[]` is accepted for the capabilities probe only —
//     mirroring CommandEnvironmentProvider's own boundary exactly.
//   * the response is {action, environment, format, operation_id, provider,
//     result, status} with `provider.protocol` 1 and `status` "ok"; every
//     per-action result key set is the one
//     CommandEnvironmentProvider::validateActionResult() closes over.
//   * capability negotiation happens through the `capabilities` action, and
//     the advertised ids are members of
//     `\Duo\Orchestrator\EnvironmentProviderCapability::all()`.
//
// NEVER EMULATION
// ---------------
// A provider that quietly serves `create` with an attach, or `destroy` with a
// detach, converts a missing capability into a silent data-loss class. This
// provider therefore refuses any action whose capability id it does not
// advertise, naming that id. `withheld_capabilities` in the config is a
// dev-only knob that makes that refusal reachable offline: it removes ids from
// the advertised set, so the orchestrator's own negotiation refusal
// (EnvironmentProviderCapabilityReport::require(), "missing <id>") and this
// provider's action-level refusal can both be exercised without docker.
//
// --print-plan / --dry-run
// ------------------------
// `reference-env-provider.php --print-plan <config.json>` (alias `--dry-run`)
// reads the same request from stdin, performs the same capability negotiation
// and the same argument validation, and then prints a
// `duo-reference-env-provider-plan/v1` document naming the exact external
// commands it WOULD run — without running any of them, without touching the
// pair, and without writing provider state. It exists so the provider's
// negotiation and validation paths are covered by an offline suite
// (sandbox/tests/regress_rehearse_provider.sh) on a machine with no docker at
// all. The flag is never passed by CommandEnvironmentProvider: the argv it is
// configured with is the two-token form above.
//
// The plan document is deliberately NOT a provider response. It carries its
// own format id and `executed: false`, so no consumer can mistake a dry run
// for evidence that anything happened.
//
// CONFIG (`duo-reference-env-provider-config/v1`)
// ----------------------------------------------
// {
//   "format": "duo-reference-env-provider-config/v1",
//   "pair": "mup",                          // sandbox/bin/pair.sh pair name
//   "pair_script": "/abs/sandbox/bin/pair.sh",
//   "compose_dir": "/abs/sandbox",          // cwd for docker compose (loads .env)
//   "compose_files": ["/abs/sandbox/pair.yml", "/abs/sandbox/pair.http.yml"],
//   "controller_repo": "/abs/origin.git",   // clone source for repository.materialize
//   "db_container": "duo-shared-db",        // sandbox/db.yml's container_name
//   "state_root": "/abs/sandbox/tmp/reference-env-provider/mup",
//   "source_environment": "mup1",
//   "destroy_scope": "side",                // "side" (default) or "pair"
//   "withheld_capabilities": [],            // dev-only; see above
//   "environments": {
//     "mup1": {"role":"source","side":1,"port":8181,
//              "container":"duo-mup-wp1-1","service":"cli1",
//              "database":"wp_mup1","repo":"/abs/sandbox/siterepo/mup1"},
//     "mup2": {"role":"target","side":2,"port":8182, ...}
//   }
// }
//
// `port` is the pair's PUBLISHED host port. Live, the URL is discovered from
// the pair's port map (`docker port <container> 80/tcp`) and the configured
// port is the cross-check; under --print-plan the configured port is used and
// the document says so in `url_source`.
//
// WHY THE PAIR MAPPING IS WHAT IT IS
// ----------------------------------
// `pair.sh` has no per-SIDE lifecycle: `up` converges both sides, `reset` and
// `destroy` act on the whole pair. A branch environment is one side. So:
//
//   attach  -> `pair.sh up <pair> <port1> <port2>` (idempotent converge)
//   create  -> the same converge, then clear exactly this side (drop/create
//              its database, empty its uploads, empty its site repo)
//   destroy -> clear exactly this side and leave the pair running, unless
//              `destroy_scope` is "pair", in which case `pair.sh destroy`
//   detach  -> no physical action; the absence proof records the release
//
// That mapping is stated rather than hidden because it is the one place this
// reference provider is weaker than a real host: `create` cannot allocate a
// NEW resource, only clean an existing side. A hosting provider that can
// allocate should advertise `environment.create` and actually allocate.
declare(strict_types=1);

/** @return mixed */
function ref_json_file(string $path, mixed $fallback): mixed {
    if (!is_file($path)) return $fallback;
    $bytes = file_get_contents($path);
    if (!is_string($bytes)) throw new RuntimeException("could not read '$path'");
    return json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
}

/** @return mixed */
function ref_normalize(mixed $value): mixed {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as $key => $child) $value[$key] = ref_normalize($child);
    return $value;
}

function ref_json(mixed $value): string {
    return json_encode(ref_normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function ref_hash(mixed $value): string {
    return hash('sha256', is_string($value) ? $value : ref_json($value));
}

function ref_require(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

/**
 * @param list<string> $argv
 * @return array{exit:int,stdout:string,stderr:string}
 */
function ref_run(array $argv, ?string $stdin = null, ?string $cwd = null): array {
    $pipes = [];
    $proc = proc_open(
        $argv,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($proc)) throw new RuntimeException('could not start reference provider command');
    if ($stdin !== null && fwrite($pipes[0], $stdin) !== strlen($stdin)) {
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_terminate($proc);
        throw new RuntimeException('could not send reference provider command input');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
}

/** @param list<string> $argv */
function ref_checked(array $argv, ?string $stdin = null, ?string $cwd = null): string {
    $result = ref_run($argv, $stdin, $cwd);
    if ($result['exit'] !== 0) {
        throw new RuntimeException(
            'reference provider command failed: ' . implode(' ', $argv) . ' :: ' . trim($result['stderr'])
        );
    }
    return $result['stdout'];
}

function ref_remove_tree(string $path): void {
    if (is_link($path) || is_file($path)) {
        @chmod($path, 0600);
        if (!unlink($path)) throw new RuntimeException("could not remove '$path'");
        return;
    }
    if (!is_dir($path)) return;
    ref_chmod_tree($path, 0700);
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

function ref_chmod_tree(string $path, int $mode = 0777): void {
    if (!file_exists($path) && !is_link($path)) return;
    @chmod($path, $mode);
    if (!is_dir($path) || is_link($path)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) @chmod($item->getPathname(), $mode);
}

/** Deterministic byte/tree digest for the provider's opaque media store. */
function ref_tree_hash(string $root): string {
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
    usort($rows, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
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

// --------------------------------------------------------------------------
// Capability negotiation
// --------------------------------------------------------------------------

/**
 * Every capability id this provider can honestly serve against a pair.
 *
 * The list is the same 19 the proven duo3324 fixture advertises, because
 * EnvironmentMaterializer::materialize() requires all of them for a create
 * with a TTL and reap requires the rest. Every id is a member of
 * \Duo\Orchestrator\EnvironmentProviderCapability::all(); an id outside that
 * set makes EnvironmentProviderCapabilityReport's constructor refuse.
 *
 * @return list<string>
 */
function ref_all_capabilities(): array {
    return [
        'environment.attach', 'environment.create', 'environment.destroy', 'environment.detach',
        'environment.inspect', 'environment.mutation.acquire', 'environment.mutation.read',
        'environment.mutation.release', 'environment.ttl', 'environment.ttl.read',
        'environment.url.discover', 'environment.url.set', 'operation.receipts',
        'repository.materialize', 'snapshot.set.abort', 'snapshot.set.create',
        'snapshot.set.prepare', 'snapshot.set.read', 'snapshot.set.restore',
    ];
}

/**
 * The capability id each action IS. One action, one capability: an action
 * served without its own id would be emulation by another name.
 *
 * @return array<string,string>
 */
function ref_action_capability(): array {
    return [
        'inspect' => 'environment.inspect',
        'attach' => 'environment.attach',
        'create' => 'environment.create',
        'snapshot-prepare' => 'snapshot.set.prepare',
        'snapshot-create' => 'snapshot.set.create',
        'snapshot-read' => 'snapshot.set.read',
        'snapshot-abort' => 'snapshot.set.abort',
        'snapshot-restore' => 'snapshot.set.restore',
        'repository-materialize' => 'repository.materialize',
        'url-set' => 'environment.url.set',
        'mutation-acquire' => 'environment.mutation.acquire',
        'mutation-read' => 'environment.mutation.read',
        'mutation-release' => 'environment.mutation.release',
        'ttl-set' => 'environment.ttl',
        'ttl-read' => 'environment.ttl.read',
        'destroy' => 'environment.destroy',
        'detach' => 'environment.detach',
    ];
}

/**
 * Actions whose result carries the full environment identity, including the
 * `url` field. Returning a URL is what `environment.url.discover` IS in this
 * protocol — there is no separate discover action — so every one of these
 * requires that id as well.
 *
 * @return list<string>
 */
function ref_identity_actions(): array {
    return [
        'inspect', 'attach', 'create', 'snapshot-restore', 'repository-materialize',
        'url-set', 'mutation-acquire', 'mutation-read', 'mutation-release', 'ttl-set', 'ttl-read',
    ];
}

/**
 * The complete capability set one action needs from this provider.
 *
 * `operation.receipts` is on every action because every result is durable
 * evidence the orchestrator journals; a provider that cannot produce receipts
 * cannot serve any of them.
 *
 * @return list<string>
 */
function ref_required_capabilities(string $action): array {
    if ($action === 'capabilities') return [];
    $required = [ref_action_capability()[$action], 'operation.receipts'];
    if (in_array($action, ref_identity_actions(), true)) $required[] = 'environment.url.discover';
    sort($required, SORT_STRING);
    return $required;
}

/**
 * @param array<string,mixed> $config
 * @return list<string>
 */
function ref_advertised_capabilities(array $config): array {
    $withheld = $config['withheld_capabilities'] ?? [];
    ref_require(is_array($withheld) && array_is_list($withheld), 'withheld_capabilities must be a list');
    foreach ($withheld as $id) {
        ref_require(
            is_string($id) && in_array($id, ref_all_capabilities(), true),
            'withheld_capabilities names an id this provider never advertises'
        );
    }
    $advertised = array_values(array_diff(ref_all_capabilities(), $withheld));
    sort($advertised, SORT_STRING);
    return $advertised;
}

/** @param array<string,mixed> $config */
function ref_assert_capability(array $config, string $action): void {
    $advertised = ref_advertised_capabilities($config);
    $missing = array_values(array_diff(ref_required_capabilities($action), $advertised));
    sort($missing, SORT_STRING);
    ref_require(
        $missing === [],
        "action '$action' requires capability " . implode(', ', $missing)
            . ' which this provider does not advertise; refusing rather than emulating it'
    );
}

// --------------------------------------------------------------------------
// Config, identity and the pair
// --------------------------------------------------------------------------

/** @return array<string,mixed> */
function ref_config(string $path): array {
    $config = ref_json_file($path, null);
    ref_require(is_array($config) && !array_is_list($config), 'reference provider config is malformed');
    ref_require(
        ($config['format'] ?? null) === 'duo-reference-env-provider-config/v1',
        'reference provider config format is not duo-reference-env-provider-config/v1'
    );
    foreach (['pair', 'pair_script', 'compose_dir', 'controller_repo', 'db_container', 'state_root', 'source_environment'] as $key) {
        ref_require(
            is_string($config[$key] ?? null) && $config[$key] !== '',
            "reference provider config is missing '$key'"
        );
    }
    ref_require(
        preg_match('/^[a-z][a-z0-9]*$/D', (string) $config['pair']) === 1,
        'reference provider pair name must match pair.sh\'s own grammar (lowercase letters/digits, leading letter)'
    );
    ref_require(is_array($config['compose_files'] ?? null) && array_is_list($config['compose_files']) && $config['compose_files'] !== [], 'reference provider config needs a non-empty compose_files list');
    foreach ($config['compose_files'] as $file) {
        ref_require(is_string($file) && $file !== '', 'reference provider compose_files entry is invalid');
    }
    $scope = $config['destroy_scope'] ?? 'side';
    ref_require(in_array($scope, ['side', 'pair'], true), "reference provider destroy_scope must be 'side' or 'pair'");
    $environments = $config['environments'] ?? null;
    ref_require(is_array($environments) && !array_is_list($environments) && $environments !== [], 'reference provider config has no environments');
    foreach ($environments as $name => $environment) {
        ref_require(is_array($environment) && !array_is_list($environment), "environment '$name' is malformed");
        foreach (['role', 'container', 'service', 'database', 'repo'] as $key) {
            ref_require(is_string($environment[$key] ?? null) && $environment[$key] !== '', "environment '$name' is missing '$key'");
        }
        ref_require(in_array($environment['role'], ['source', 'target'], true), "environment '$name' role must be source or target");
        ref_require(in_array($environment['side'] ?? null, [1, 2], true), "environment '$name' side must be 1 or 2");
        $port = $environment['port'] ?? null;
        ref_require(is_int($port) && $port >= 1 && $port <= 65535, "environment '$name' port must be a published host port");
    }
    ref_require(
        isset($environments[(string) $config['source_environment']]),
        'reference provider source_environment names no configured environment'
    );
    return $config;
}

/**
 * Deterministic, pair-derived identity. Every field satisfies
 * CommandEnvironmentProvider::assertIdentifier()'s 8..256 [A-Za-z0-9._:@+-]
 * grammar, and none of them is a secret: an identity is public journal
 * evidence.
 *
 * @param array<string,mixed> $config
 * @param array<string,mixed> $environment
 * @return array<string,mixed>
 */
function ref_identity(array $config, array $environment, string $url): array {
    $pair = (string) $config['pair'];
    $side = (int) $environment['side'];
    $environmentIdentity = 'duo-pair-' . $pair . '-side-' . $side;
    return [
        'environment_identity' => $environmentIdentity,
        'lease_generation' => 1,
        'lease_id' => 'pair-lease-' . substr(hash('sha256', $environmentIdentity), 0, 20),
        'ownership_receipt_sha256' => ref_hash('ownership:' . $environmentIdentity),
        'resource_id' => 'duo-' . $pair . '-wp' . $side,
        'url' => $url,
    ];
}

/**
 * The published URL, discovered from the pair's own port map.
 *
 * Live, `docker port <container> 80/tcp` is the authority and the configured
 * port is only a cross-check: a pair brought up on a different port than the
 * config remembers must not silently mint a URL nobody can reach. Under
 * --print-plan there is no docker, so the configured port is used and the
 * document records `url_source: "config"`.
 *
 * @param array<string,mixed> $environment
 */
function ref_discover_url(array $environment, bool $planOnly): string {
    $port = (int) $environment['port'];
    if ($planOnly) return 'http://127.0.0.1:' . $port;
    $mapped = trim(ref_checked(ref_port_command($environment)));
    $line = trim((string) strtok($mapped, "\n"));
    ref_require($line !== '', 'the pair publishes no host port for this environment');
    $observed = (int) substr($line, (int) strrpos($line, ':') + 1);
    ref_require(
        $observed === $port,
        "the pair's port map publishes $observed for this environment but the config declares $port"
    );
    return 'http://127.0.0.1:' . $observed;
}

/**
 * @param array<string,mixed> $environment
 * @return list<string>
 */
function ref_port_command(array $environment): array {
    return ['docker', 'port', (string) $environment['container'], '80/tcp'];
}

/**
 * `pair.sh up` needs both host ports, in side order.
 *
 * @param array<string,mixed> $config
 * @return list<string>
 */
function ref_pair_up_command(array $config): array {
    $ports = [];
    foreach ($config['environments'] as $environment) {
        $ports[(int) $environment['side']] = (string) (int) $environment['port'];
    }
    ref_require(isset($ports[1], $ports[2]), 'pair.sh up needs both sides of the pair configured');
    return ['bash', (string) $config['pair_script'], 'up', (string) $config['pair'], $ports[1], $ports[2]];
}

/**
 * @param array<string,mixed> $config
 * @return list<string>
 */
function ref_compose_command(array $config, array $tail): array {
    $argv = ['docker', 'compose', '-p', 'duo-' . (string) $config['pair']];
    foreach ($config['compose_files'] as $file) {
        $argv[] = '-f';
        $argv[] = (string) $file;
    }
    return array_merge($argv, $tail);
}

/** @param array<string,mixed> $config @return list<string> */
function ref_dump_command(array $config, string $database): array {
    return [
        'docker', 'exec', (string) $config['db_container'], 'mariadb-dump',
        '--single-transaction', '--skip-comments', '--skip-dump-date', '-uroot', '-proot', $database,
    ];
}

/** @param array<string,mixed> $config */
function ref_dump_database(array $config, string $database): string {
    return ref_checked(ref_dump_command($config, $database));
}

/**
 * The freeze-witness digest of a database dump: the dump with WordPress
 * core's own cron lock neutralised.
 *
 * `spawn_cron()` rewrites the `doing_cron` transient's value (a float
 * timestamp) on any WordPress bootstrap that finds cron due — ordinary
 * traffic on a live source does it, and so does the orchestrator's own
 * `wp duo refresh-export` on the source between snapshot-prepare and
 * snapshot-create. That row is a lock, not authored or runtime state; a
 * witness that treated its timestamp as "the source changed" refused every
 * live WordPress source (grind_mup.sh step 5). The snapshot bytes
 * themselves stay raw and complete — only the two-sided comparison ignores
 * the lock's value. Nothing else is normalised: a real write between prepare
 * and create still refuses.
 */
function ref_freeze_witness(string $dump): string {
    $normalised = preg_replace(
        "/\\((\\d+),'_transient_doing_cron','[^']*','([^']*)'\\)/",
        "(\\1,'_transient_doing_cron','<cron-lock>','\\2')",
        $dump
    );
    return hash('sha256', is_string($normalised) ? $normalised : $dump);
}

/** @param array<string,mixed> $config */
function ref_restore_database(array $config, string $database, string $dump): void {
    ref_checked(
        ['docker', 'exec', '-i', (string) $config['db_container'], 'mariadb', '-uroot', '-proot'],
        "DROP DATABASE IF EXISTS `$database`; CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
    );
    ref_checked(['docker', 'exec', '-i', (string) $config['db_container'], 'mariadb', '-uroot', '-proot', $database], $dump);
    if ($dump !== '') {
        ref_require(
            hash('sha256', ref_dump_database($config, $database)) === hash('sha256', $dump),
            'database restore readback differs from the immutable snapshot bytes'
        );
    }
}

/** @return list<string> */
function ref_media_copy_command(string $container, string $destination): array {
    return ['docker', 'cp', $container . ':/var/www/html/wp-content/uploads/.', $destination];
}

/** @return list<string> */
function ref_media_clear_command(string $container): array {
    return [
        'docker', 'exec', $container, 'sh', '-c',
        'rm -rf /var/www/html/wp-content/uploads && mkdir -p /var/www/html/wp-content/uploads',
    ];
}

function ref_copy_media_from_container(string $container, string $destination): void {
    if (!is_dir($destination) && !mkdir($destination, 0700, true)) {
        throw new RuntimeException("could not create media destination '$destination'");
    }
    ref_checked(ref_media_copy_command($container, $destination));
    // Docker Desktop can preserve container modes in docker-cp output; prove
    // the host can still clean up its own evidence before relying on it.
    ref_chmod_tree($destination, 0700);
}

function ref_restore_media_to_container(string $source, string $container): void {
    ref_checked(ref_media_clear_command($container));
    ref_checked(['docker', 'cp', rtrim($source, '/') . '/.', $container . ':/var/www/html/wp-content/uploads']);
}

// --------------------------------------------------------------------------
// Provider state
// --------------------------------------------------------------------------

/** @return array<string,mixed> */
function ref_load_state(string $root): array {
    $state = ref_json_file($root . '/state.json', [
        'fences' => [], 'sessions' => [], 'snapshots' => [], 'ttls' => [], 'resources' => [],
    ]);
    ref_require(is_array($state) && !array_is_list($state), 'reference provider state is malformed');
    foreach (['fences', 'sessions', 'snapshots', 'ttls', 'resources'] as $key) {
        ref_require(is_array($state[$key] ?? null), "reference provider state '$key' is malformed");
    }
    return $state;
}

/** @param array<string,mixed> $state */
function ref_save_state(string $root, array $state): void {
    $path = $root . '/state.json';
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
    $bytes = ref_json($state) . "\n";
    if (file_put_contents($tmp, $bytes, LOCK_EX) !== strlen($bytes) || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('could not publish reference provider state');
    }
    chmod($path, 0600);
}

/** @param array<string,mixed> $request */
function ref_log(string $root, array $request): void {
    $record = [
        'action' => $request['action'],
        'environment' => $request['environment'],
        'input_sha256' => ref_hash($request['input']),
        'operation_id' => $request['operation_id'],
    ];
    file_put_contents($root . '/actions.ndjson', ref_json($record) . "\n", FILE_APPEND | LOCK_EX);
}

function ref_snapshot_key(string $environment, string $operation): string { return $environment . '|' . $operation; }

/** @param array<string,mixed> $snapshot @return array<string,mixed> */
function ref_snapshot_result(array $snapshot, bool $read = false): array {
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

/** @param array<string,mixed> $identity @param array<string,mixed> $fence @return array<string,mixed> */
function ref_fence_result(array $identity, array $fence): array {
    return $identity + [
        'mutation_generation' => $fence['generation'],
        'mutation_id' => $fence['id'],
        'mutation_owner' => $fence['owner'],
        'mutation_receipt_sha256' => $fence['receipt'],
        'state' => $fence['state'],
    ];
}

/**
 * @param array<string,mixed> $input
 * @param array<string,mixed> $identity
 */
function ref_assert_identity_input(array $input, array $identity): void {
    $expected = [
        'expected_environment_identity' => $identity['environment_identity'],
        'expected_lease_generation' => $identity['lease_generation'],
        'expected_lease_id' => $identity['lease_id'],
        'expected_ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
        'expected_resource_id' => $identity['resource_id'],
    ];
    foreach ($expected as $key => $value) {
        ref_require(array_key_exists($key, $input) && $input[$key] === $value, "identity input differs at '$key'");
    }
}

/**
 * @param array<string,mixed> $state
 * @param array<string,mixed> $input
 * @param array<string,mixed> $identity
 * @return array<string,mixed>
 */
function ref_require_fence(
    array &$state,
    array $input,
    array $identity,
    bool $mustBeHeld = true,
    bool $allowHeldReceiptAfterRelease = false
): array {
    ref_assert_identity_input($input, $identity);
    foreach (['expected_mutation_generation', 'expected_mutation_id', 'expected_mutation_owner', 'expected_mutation_receipt_sha256'] as $key) {
        ref_require(array_key_exists($key, $input), "mutation input is missing '$key'");
    }
    $id = (string) $input['expected_mutation_id'];
    $fence = $state['fences'][$id] ?? null;
    ref_require(is_array($fence), 'mutation fence is unknown');
    ref_require(
        $fence['generation'] === $input['expected_mutation_generation'] && $fence['owner'] === $input['expected_mutation_owner'],
        'mutation fence lineage changed'
    );
    // A released->released read must authenticate the release acknowledgement,
    // not the old held CAS receipt. The sole exception is an idempotent
    // reissue of mutation-release after its response was lost.
    $expectedReceipt = $allowHeldReceiptAfterRelease
        ? $fence['held_receipt']
        : ($fence['state'] === 'held' ? $fence['held_receipt'] : $fence['receipt']);
    ref_require($expectedReceipt === $input['expected_mutation_receipt_sha256'], 'mutation fence receipt differs');
    if ($mustBeHeld) ref_require($fence['state'] === 'held', 'mutation fence is not held');
    return $fence;
}

/** @param array<string,mixed> $config @param array<string,mixed> $environment */
function ref_clear_side(array $config, array $environment): void {
    if (($config['destroy_scope'] ?? 'side') === 'pair') {
        ref_checked(['bash', (string) $config['pair_script'], 'destroy', (string) $config['pair']]);
        return;
    }
    ref_restore_database($config, (string) $environment['database'], '');
    ref_checked(ref_media_clear_command((string) $environment['container']));
    $repo = (string) $environment['repo'];
    ref_remove_tree($repo);
    if (!mkdir($repo, 0777, true) && !is_dir($repo)) throw new RuntimeException('could not recreate the cleared side repository');
    chmod($repo, 0777);
}

// --------------------------------------------------------------------------
// Dispatch
// --------------------------------------------------------------------------

/**
 * @param array<string,mixed> $request
 * @param array<string,mixed> $config
 * @param array<string,mixed> $state
 * @return array<string,mixed>
 */
function ref_dispatch(array $request, array $config, array &$state): array {
    $environmentName = (string) $request['environment'];
    $environment = $config['environments'][$environmentName] ?? null;
    ref_require(is_array($environment), "unknown reference environment '$environmentName'");
    $action = (string) $request['action'];
    $input = $request['input'];
    $operation = (string) $request['operation_id'];

    if ($action === 'capabilities') return ['capabilities' => ref_advertised_capabilities($config)];
    ref_assert_capability($config, $action);

    $identity = ref_identity($config, $environment, ref_discover_url($environment, false));

    if ($action === 'inspect') return $identity + ['presence' => 'present'];

    if ($action === 'attach' || $action === 'create') {
        ref_require(($input['mode'] ?? null) === $action, 'target acquisition mode is malformed');
        ref_checked(ref_pair_up_command($config));
        if ($action === 'create') ref_clear_side($config, $environment);
        $state['resources'][$environmentName] = ['mode' => $action, 'operation_id' => $operation, 'state' => 'present'];
        return $identity + ['presence' => 'present'];
    }

    if ($action === 'snapshot-prepare') {
        ref_require(($environment['role'] ?? null) === 'source', 'only the source can prepare a snapshot');
        ref_assert_identity_input($input, $identity);
        $session = $input['snapshot_session_id'] ?? null;
        ref_require(is_string($session) && $session !== '', 'snapshot session is absent');
        $key = ref_snapshot_key($environmentName, $operation);
        $prepared = $state['sessions'][$key] ?? null;
        if (!is_array($prepared)) {
            $staging = (string) $config['state_root'] . '/prepared/' . hash('sha256', $key);
            ref_remove_tree($staging);
            if (!mkdir($staging . '/media', 0700, true)) throw new RuntimeException('could not create source snapshot staging');
            $prepared = [
                'lease_generation' => 1,
                'lease_id' => 'snapshot-lease-' . substr(hash('sha256', $key), 0, 20),
                'lease_receipt_sha256' => ref_hash('snapshot-lease:' . $key),
                'path' => $staging,
                'snapshot_session_id' => $session,
                'source_identity' => $identity['environment_identity'],
                'state' => 'preparing',
            ];
            $state['sessions'][$key] = $prepared;
            ref_save_state((string) $config['state_root'], $state);
        }
        ref_require(($prepared['snapshot_session_id'] ?? null) === $session, 'snapshot prepare session changed');
        ref_require(($prepared['source_identity'] ?? null) === $identity['environment_identity'], 'snapshot prepare source changed');
        ref_require(in_array($prepared['state'] ?? null, ['preparing', 'prepared'], true), 'snapshot prepare session is no longer resumable');
        if (($prepared['state'] ?? null) === 'preparing') {
            $staging = (string) $prepared['path'];
            ref_remove_tree($staging);
            if (!mkdir($staging . '/media', 0700, true)) throw new RuntimeException('could not recreate source snapshot staging');
            $dump = ref_dump_database($config, (string) $environment['database']);
            ref_require(
                file_put_contents($staging . '/database.sql', $dump, LOCK_EX) === strlen($dump),
                'could not write source database evidence'
            );
            ref_copy_media_from_container((string) $environment['container'], $staging . '/media');
            $databaseHash = hash_file('sha256', $staging . '/database.sql');
            ref_require(is_string($databaseHash), 'could not hash source database evidence');
            $prepared['database_sha256'] = $databaseHash;
            $prepared['database_witness_sha256'] = ref_freeze_witness($dump);
            $prepared['media_sha256'] = ref_tree_hash($staging . '/media');
            $prepared['state'] = 'prepared';
            $state['sessions'][$key] = $prepared;
            ref_save_state((string) $config['state_root'], $state);
        }
        return [
            'lease_generation' => $prepared['lease_generation'],
            'lease_id' => $prepared['lease_id'],
            'lease_receipt_sha256' => $prepared['lease_receipt_sha256'],
            'snapshot_session_id' => $prepared['snapshot_session_id'],
            'source_identity' => $prepared['source_identity'],
        ];
    }

    if ($action === 'snapshot-create') {
        ref_require(($environment['role'] ?? null) === 'source', 'only the source can create a snapshot');
        $key = ref_snapshot_key($environmentName, $operation);
        $prepared = $state['sessions'][$key] ?? null;
        ref_require(is_array($prepared) && $prepared['state'] === 'prepared', 'snapshot create has no prepared source session');
        foreach ([
            'expected_snapshot_session_id' => 'snapshot_session_id', 'expected_source_identity' => 'source_identity',
            'expected_source_lease_generation' => 'lease_generation', 'expected_source_lease_id' => 'lease_id',
            'expected_source_lease_receipt_sha256' => 'lease_receipt_sha256',
        ] as $provided => $stored) {
            ref_require(($input[$provided] ?? null) === $prepared[$stored], "snapshot create differs at '$provided'");
        }
        $snapshot = $state['snapshots'][$key] ?? null;
        if (!is_array($snapshot)) {
            $currentDump = ref_dump_database($config, (string) $environment['database']);
            $currentDb = hash('sha256', $currentDump);
            $mediaScratch = (string) $config['state_root'] . '/create-media-' . hash('sha256', $key);
            ref_remove_tree($mediaScratch);
            ref_copy_media_from_container((string) $environment['container'], $mediaScratch);
            $currentMedia = ref_tree_hash($mediaScratch);
            // Fail-closed freeze witness: a source write between prepare and
            // create refuses rather than quietly combining semantic P with a
            // different physical set. pair.sh has no pause verb, so the
            // witness is the whole freeze this provider can offer, and it is
            // stated rather than implied.
            $changed = [];
            $preparedWitness = (string) ($prepared['database_witness_sha256'] ?? $prepared['database_sha256']);
            if (ref_freeze_witness($currentDump) !== $preparedWitness) $changed[] = 'database';
            if ($currentMedia !== $prepared['media_sha256']) $changed[] = 'media';
            ref_require(
                $changed === [],
                'source changed between its prepared snapshot session and create (' . implode(',', $changed) . ')'
            );
            $snapshotDir = (string) $config['state_root'] . '/snapshots/' . hash('sha256', $key);
            ref_remove_tree($snapshotDir);
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
                'retention_receipt_sha256' => ref_hash('retention:' . $key),
                'semantic_snapshot_sha256' => $input['expected_semantic_snapshot_sha256'],
                'snapshot_session_id' => $prepared['snapshot_session_id'],
                'snapshot_set_id' => 'snapshot-set-' . substr(hash('sha256', $key), 0, 20),
                'source_identity' => $prepared['source_identity'],
            ];
            $snapshot['snapshot_set_receipt_sha256'] = ref_hash([
                'database_sha256' => $snapshot['database_sha256'], 'media_sha256' => $snapshot['media_sha256'],
                'semantic_snapshot_sha256' => $snapshot['semantic_snapshot_sha256'], 'snapshot_set_id' => $snapshot['snapshot_set_id'],
            ]);
            ref_chmod_tree($snapshotDir, 0555);
            $state['snapshots'][$key] = $snapshot;
            ref_save_state((string) $config['state_root'], $state);
        }
        ref_require(
            ($snapshot['semantic_snapshot_sha256'] ?? null) === ($input['expected_semantic_snapshot_sha256'] ?? null),
            'snapshot create semantic identity changed'
        );
        return ref_snapshot_result($snapshot);
    }

    if ($action === 'snapshot-read') {
        ref_require(($environment['role'] ?? null) === 'source', 'only the source can read a snapshot');
        $key = ref_snapshot_key($environmentName, $operation);
        $snapshot = $state['snapshots'][$key] ?? null;
        ref_require(is_array($snapshot), 'snapshot read has no created set');
        foreach ([
            'expected_snapshot_set_id' => 'snapshot_set_id', 'expected_snapshot_set_receipt_sha256' => 'snapshot_set_receipt_sha256',
            'expected_snapshot_session_id' => 'snapshot_session_id', 'expected_source_identity' => 'source_identity',
            'expected_source_lease_generation' => 'lease_generation', 'expected_source_lease_id' => 'lease_id',
            'expected_source_lease_receipt_sha256' => 'lease_receipt_sha256',
        ] as $provided => $stored) {
            ref_require(($input[$provided] ?? null) === $snapshot[$stored], "snapshot read differs at '$provided'");
        }
        ref_require(
            hash_file('sha256', (string) $snapshot['database_path']) === $snapshot['database_sha256']
                && ref_tree_hash((string) $snapshot['media_path']) === $snapshot['media_sha256'],
            'immutable snapshot bytes changed'
        );
        return ref_snapshot_result($snapshot, true);
    }

    if ($action === 'snapshot-abort') {
        ref_require(($environment['role'] ?? null) === 'source', 'only the source can abort a snapshot');
        $key = ref_snapshot_key($environmentName, $operation);
        $prepared = $state['sessions'][$key] ?? null;
        ref_require(is_array($prepared), 'snapshot abort has no prepared session');
        foreach ([
            'expected_snapshot_session_id' => 'snapshot_session_id', 'expected_source_identity' => 'source_identity',
            'expected_source_lease_generation' => 'lease_generation', 'expected_source_lease_id' => 'lease_id',
            'expected_source_lease_receipt_sha256' => 'lease_receipt_sha256',
        ] as $provided => $stored) {
            ref_require(($input[$provided] ?? null) === $prepared[$stored], "snapshot abort differs at '$provided'");
        }
        ref_require(in_array($prepared['state'] ?? null, ['preparing', 'prepared', 'aborted'], true), 'snapshot abort session is not resumable');
        if (($prepared['state'] ?? null) !== 'aborted') {
            $staging = $prepared['path'] ?? null;
            if (is_string($staging) && $staging !== '') ref_remove_tree($staging);
            $snapshot = $state['snapshots'][$key] ?? null;
            if (is_array($snapshot)) {
                $snapshotDir = dirname((string) ($snapshot['database_path'] ?? ''));
                $expectedDir = (string) $config['state_root'] . '/snapshots/' . hash('sha256', $key);
                ref_require($snapshotDir === $expectedDir, 'snapshot abort refuses a foreign immutable set path');
                ref_remove_tree($snapshotDir);
                unset($state['snapshots'][$key]);
            }
        }
        $prepared['state'] = 'aborted';
        $state['sessions'][$key] = $prepared;
        ref_save_state((string) $config['state_root'], $state);
        return [
            'disposition' => 'aborted', 'lease_generation' => $prepared['lease_generation'], 'lease_id' => $prepared['lease_id'],
            'lease_receipt_sha256' => $prepared['lease_receipt_sha256'], 'snapshot_session_id' => $prepared['snapshot_session_id'],
            'source_identity' => $prepared['source_identity'],
        ];
    }

    if ($action === 'mutation-acquire') {
        ref_assert_identity_input($input, $identity);
        $owner = $input['mutation_owner'] ?? null;
        ref_require(is_string($owner) && $owner !== '', 'mutation acquire has no owner');
        $id = 'mutation-' . substr(hash('sha256', $environmentName . '|' . $operation), 0, 20);
        $fence = $state['fences'][$id] ?? null;
        if (!is_array($fence)) {
            $receipt = ref_hash('held:' . $id . ':' . $owner);
            $fence = ['generation' => 1, 'held_receipt' => $receipt, 'id' => $id, 'owner' => $owner, 'receipt' => $receipt, 'state' => 'held'];
            $state['fences'][$id] = $fence;
        }
        ref_require($fence['owner'] === $owner && $fence['state'] === 'held', 'mutation acquire is not idempotent/exclusive');
        return ref_fence_result($identity, $fence);
    }

    if ($action === 'mutation-read') {
        return ref_fence_result($identity, ref_require_fence($state, $input, $identity, false));
    }

    if ($action === 'mutation-release') {
        $fence = ref_require_fence($state, $input, $identity, false, true);
        if ($fence['state'] === 'held') {
            $fence['state'] = 'released';
            $fence['receipt'] = ref_hash('released:' . $fence['id'] . ':' . $fence['owner']);
            $state['fences'][$fence['id']] = $fence;
        }
        ref_require($fence['state'] === 'released', 'mutation release has an invalid fence state');
        return ref_fence_result($identity, $fence);
    }

    if ($action === 'snapshot-restore') {
        ref_require_fence($state, $input, $identity);
        $snapshotId = $input['snapshot_set_id'] ?? null;
        $snapshot = null;
        foreach ($state['snapshots'] as $candidate) {
            if (is_array($candidate) && ($candidate['snapshot_set_id'] ?? null) === $snapshotId) $snapshot = $candidate;
        }
        ref_require(
            is_array($snapshot) && ($input['database_sha256'] ?? null) === $snapshot['database_sha256']
                && ($input['media_sha256'] ?? null) === $snapshot['media_sha256'],
            'snapshot restore is not bound to the immutable set'
        );
        $dump = file_get_contents((string) $snapshot['database_path']);
        if (!is_string($dump)) throw new RuntimeException('could not read immutable database snapshot');
        ref_restore_database($config, (string) $environment['database'], $dump);
        ref_restore_media_to_container((string) $snapshot['media_path'], (string) $environment['container']);
        return $identity + ['snapshot_set_id' => $snapshot['snapshot_set_id']];
    }

    if ($action === 'repository-materialize') {
        ref_require_fence($state, $input, $identity);
        $commit = $input['branch_commit'] ?? null;
        ref_require(is_string($commit) && preg_match('/^[a-f0-9]{40}$/D', $commit) === 1, 'repository materialization commit is invalid');
        $repo = (string) $environment['repo'];
        $tmp = $repo . '.incoming-' . substr(hash('sha256', $operation), 0, 12);
        ref_remove_tree($tmp);
        ref_remove_tree($repo);
        ref_checked(['git', 'clone', '--no-hardlinks', '--no-local', (string) $config['controller_repo'], $tmp]);
        ref_checked(['git', '-C', $tmp, 'checkout', '--detach', $commit]);
        if (!rename($tmp, $repo)) throw new RuntimeException('could not publish the independent target repository');
        ref_chmod_tree($repo);
        return $identity + ['branch_commit' => $commit, 'repository_receipt_sha256' => ref_hash(['commit' => $commit, 'repo' => $repo])];
    }

    if ($action === 'url-set') {
        ref_require_fence($state, $input, $identity);
        ref_require(($input['url'] ?? null) === $identity['url'], 'provider URL differs from target identity');
        foreach (['home', 'siteurl'] as $option) {
            ref_checked(
                ref_compose_command($config, ['run', '--rm', '-T', (string) $environment['service'], 'wp', 'option', 'update', $option, (string) $identity['url'], '--quiet']),
                null,
                (string) $config['compose_dir']
            );
        }
        return $identity;
    }

    if ($action === 'ttl-set') {
        ref_require_fence($state, $input, $identity);
        $seconds = $input['ttl_seconds'] ?? null;
        ref_require(is_int($seconds) && $seconds >= 60, 'TTL set is invalid');
        $ttl = $state['ttls'][$environmentName] ?? null;
        if (!is_array($ttl) || ($ttl['operation_id'] ?? null) !== $operation) {
            $ttl = [
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $seconds), 'generation' => 1,
                'lease_id' => 'ttl-lease-' . substr(hash('sha256', $environmentName . '|' . $operation), 0, 20),
                'operation_id' => $operation,
            ];
            $ttl['receipt'] = ref_hash([
                'expires_at' => $ttl['expires_at'], 'generation' => $ttl['generation'],
                'lease_id' => $ttl['lease_id'], 'operation_id' => $ttl['operation_id'],
            ]);
            $state['ttls'][$environmentName] = $ttl;
        }
        return $identity + [
            'expires_at' => $ttl['expires_at'], 'ttl_generation' => $ttl['generation'], 'ttl_lease_id' => $ttl['lease_id'],
            'ttl_receipt_sha256' => $ttl['receipt'], 'ttl_state' => 'active',
        ];
    }

    if ($action === 'ttl-read') {
        ref_assert_identity_input($input, $identity);
        $ttl = $state['ttls'][$environmentName] ?? null;
        ref_require(is_array($ttl), 'TTL read has no active lease');
        foreach ([
            'expected_expires_at' => 'expires_at', 'expected_ttl_generation' => 'generation',
            'expected_ttl_lease_id' => 'lease_id', 'expected_ttl_receipt_sha256' => 'receipt',
        ] as $provided => $stored) {
            ref_require(($input[$provided] ?? null) === $ttl[$stored], "TTL read differs at '$provided'");
        }
        return $identity + [
            'expires_at' => $ttl['expires_at'], 'ttl_generation' => $ttl['generation'], 'ttl_lease_id' => $ttl['lease_id'],
            'ttl_receipt_sha256' => $ttl['receipt'], 'ttl_state' => 'active',
        ];
    }

    if ($action === 'destroy' || $action === 'detach') {
        ref_require_fence($state, $input, $identity);
        ref_require(($input['compare_and_reap'] ?? null) === true, 'reap lacks compare-and-reap intent');
        if ($action === 'destroy') ref_clear_side($config, $environment);
        $state['resources'][$environmentName] = ['mode' => $action, 'operation_id' => $operation, 'state' => 'absent'];
        return [
            'absence_proof_sha256' => ref_hash([$action, $identity['environment_identity'], $identity['resource_id'], $operation]),
            'disposition' => $action === 'destroy' ? 'destroyed' : 'detached',
            'environment_identity' => $identity['environment_identity'], 'lease_generation' => $identity['lease_generation'],
            'lease_id' => $identity['lease_id'], 'ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
            'resource_id' => $identity['resource_id'],
        ];
    }

    throw new RuntimeException("unsupported reference provider action '$action'");
}

// --------------------------------------------------------------------------
// --print-plan
// --------------------------------------------------------------------------

/**
 * Validate one request exactly as ref_dispatch() would, then describe the
 * external commands it would run. No docker, no git, no filesystem write.
 *
 * State-dependent checks (is there a prepared snapshot session, is the fence
 * held) are deliberately NOT performed: they are facts about a live pair, and
 * asserting them here would either require provider state or invent it. The
 * document records that boundary in `state_dependent` so a reader is never
 * left believing a dry run proved more than it did.
 *
 * @param array<string,mixed> $request
 * @param array<string,mixed> $config
 * @return array<string,mixed>
 */
function ref_plan(array $request, array $config): array {
    $environmentName = (string) $request['environment'];
    $environment = $config['environments'][$environmentName] ?? null;
    ref_require(is_array($environment), "unknown reference environment '$environmentName'");
    $action = (string) $request['action'];
    $input = $request['input'];
    ref_require(
        $action === 'capabilities' || isset(ref_action_capability()[$action]),
        "unsupported reference provider action '$action'"
    );
    ref_assert_capability($config, $action);

    $url = ref_discover_url($environment, true);
    $identity = ref_identity($config, $environment, $url);
    $commands = [];
    $stateDependent = true;
    $identityChecked = false;

    if (is_array($input) && array_key_exists('expected_environment_identity', $input)) {
        ref_assert_identity_input($input, $identity);
        $identityChecked = true;
    }

    switch ($action) {
        case 'capabilities':
        case 'inspect':
        case 'detach':
            $stateDependent = $action !== 'capabilities';
            break;
        case 'attach':
        case 'create':
            ref_require(($input['mode'] ?? null) === $action, 'target acquisition mode is malformed');
            $commands[] = ['argv' => ref_pair_up_command($config)];
            if ($action === 'create') {
                $commands = array_merge($commands, ref_clear_side_plan($config, $environment));
            }
            break;
        case 'snapshot-prepare':
            ref_require(($environment['role'] ?? null) === 'source', 'only the source can prepare a snapshot');
            ref_require(is_string($input['snapshot_session_id'] ?? null) && $input['snapshot_session_id'] !== '', 'snapshot session is absent');
            $commands[] = ['argv' => ref_dump_command($config, (string) $environment['database'])];
            $commands[] = ['argv' => ref_media_copy_command((string) $environment['container'], '<staging>/media')];
            break;
        case 'snapshot-create':
            ref_require(($environment['role'] ?? null) === 'source', 'only the source can create a snapshot');
            $commands[] = ['argv' => ref_dump_command($config, (string) $environment['database'])];
            $commands[] = ['argv' => ref_media_copy_command((string) $environment['container'], '<scratch>')];
            break;
        case 'snapshot-read':
        case 'snapshot-abort':
            ref_require(($environment['role'] ?? null) === 'source', "only the source can $action a snapshot");
            break;
        case 'snapshot-restore':
            $commands[] = ['argv' => ['docker', 'exec', '-i', (string) $config['db_container'], 'mariadb', '-uroot', '-proot'], 'stdin' => 'drop-create'];
            $commands[] = ['argv' => ['docker', 'exec', '-i', (string) $config['db_container'], 'mariadb', '-uroot', '-proot', (string) $environment['database']], 'stdin' => 'immutable-dump'];
            $commands[] = ['argv' => ref_media_clear_command((string) $environment['container'])];
            break;
        case 'repository-materialize':
            $commit = $input['branch_commit'] ?? null;
            ref_require(is_string($commit) && preg_match('/^[a-f0-9]{40}$/D', $commit) === 1, 'repository materialization commit is invalid');
            $commands[] = ['argv' => ['git', 'clone', '--no-hardlinks', '--no-local', (string) $config['controller_repo'], (string) $environment['repo'] . '.incoming']];
            $commands[] = ['argv' => ['git', '-C', (string) $environment['repo'] . '.incoming', 'checkout', '--detach', $commit]];
            break;
        case 'url-set':
            ref_require(($input['url'] ?? null) === $identity['url'], 'provider URL differs from target identity');
            foreach (['home', 'siteurl'] as $option) {
                $commands[] = [
                    'argv' => ref_compose_command($config, ['run', '--rm', '-T', (string) $environment['service'], 'wp', 'option', 'update', $option, $url, '--quiet']),
                    'cwd' => (string) $config['compose_dir'],
                ];
            }
            break;
        case 'ttl-set':
            ref_require(is_int($input['ttl_seconds'] ?? null) && $input['ttl_seconds'] >= 60, 'TTL set is invalid');
            break;
        case 'destroy':
            ref_require(($input['compare_and_reap'] ?? null) === true, 'reap lacks compare-and-reap intent');
            $commands = array_merge($commands, ref_clear_side_plan($config, $environment));
            break;
        default:
            break;
    }
    if ($action === 'detach') {
        ref_require(($input['compare_and_reap'] ?? null) === true, 'reap lacks compare-and-reap intent');
    }

    return [
        'action' => $action,
        'capabilities_advertised' => ref_advertised_capabilities($config),
        'capabilities_required' => ref_required_capabilities($action),
        'commands' => $commands,
        'environment' => $environmentName,
        'executed' => false,
        'format' => 'duo-reference-env-provider-plan/v1',
        'identity' => $identity,
        'identity_input_checked' => $identityChecked,
        'operation_id' => (string) $request['operation_id'],
        'provider' => ['id' => 'duo-reference-env-provider', 'protocol' => 1],
        'state_dependent' => $stateDependent,
        'url_source' => 'config',
    ];
}

/**
 * @param array<string,mixed> $config
 * @param array<string,mixed> $environment
 * @return list<array<string,mixed>>
 */
function ref_clear_side_plan(array $config, array $environment): array {
    if (($config['destroy_scope'] ?? 'side') === 'pair') {
        return [['argv' => ['bash', (string) $config['pair_script'], 'destroy', (string) $config['pair']]]];
    }
    return [
        ['argv' => ['docker', 'exec', '-i', (string) $config['db_container'], 'mariadb', '-uroot', '-proot'], 'stdin' => 'drop-create'],
        ['argv' => ref_media_clear_command((string) $environment['container'])],
    ];
}

// --------------------------------------------------------------------------
// Entry point
// --------------------------------------------------------------------------

/**
 * @param array<string,mixed> $request
 */
function ref_assert_request(array $request): void {
    foreach (['action', 'environment', 'format', 'input', 'operation_id'] as $key) {
        ref_require(array_key_exists($key, $request), "provider request lacks '$key'");
    }
    // CommandEnvironmentProvider uses [] for an empty object input (the
    // capabilities probe) while rejecting non-empty JSON lists. Mirror that
    // boundary exactly.
    ref_require(
        $request['format'] === 'duo-branch-environment-provider-request/v1'
            && is_array($request['input'])
            && (!array_is_list($request['input']) || $request['input'] === []),
        'provider request has an invalid protocol shape'
    );
    ref_require(is_string($request['action']) && $request['action'] !== '', 'provider request action is invalid');
    ref_require(is_string($request['environment']) && $request['environment'] !== '', 'provider request environment is invalid');
    ref_require(is_string($request['operation_id']) && $request['operation_id'] !== '', 'provider request operation id is invalid');
}

$configPath = null;
try {
    // $_SERVER['argv'] rather than the bare $argv superglobal: PHPStan cannot
    // prove $argv is defined at file scope (it depends on register_argc_argv),
    // and tools/codemod/move-modules.php already settled that question the
    // same way.
    $arguments = array_slice(is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [], 1);
    $planOnly = false;
    if ($arguments !== [] && in_array($arguments[0], ['--print-plan', '--dry-run'], true)) {
        $planOnly = true;
        array_shift($arguments);
    }
    ref_require(
        count($arguments) === 1,
        'usage: reference-env-provider.php [--print-plan|--dry-run] <config.json>'
    );
    $configPath = $arguments[0];
    $config = ref_config($configPath);

    $raw = (string) stream_get_contents(STDIN);
    $request = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    ref_require(is_array($request) && !array_is_list($request), 'provider request is malformed');
    ref_assert_request($request);

    if ($planOnly) {
        echo ref_json(ref_plan($request, $config)) . "\n";
        exit(0);
    }

    $root = (string) $config['state_root'];
    ref_require(is_dir($root), 'reference provider state root is unavailable');
    $lock = fopen($root . '/state.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('could not lock reference provider state');
    try {
        $state = ref_load_state($root);
        ref_log($root, $request);
        $result = ref_dispatch($request, $config, $state);
        ref_save_state($root, $state);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    $response = [
        'action' => $request['action'], 'environment' => $request['environment'],
        'format' => 'duo-branch-environment-provider-response/v1', 'operation_id' => $request['operation_id'],
        'provider' => ['id' => 'duo-reference-env-provider', 'protocol' => 1], 'result' => $result, 'status' => 'ok',
    ];
    echo ref_json($response) . "\n";
} catch (Throwable $error) {
    // Provider output is redacted by CommandEnvironmentProvider on every
    // failure, so the operator-readable detail is kept beside the state root
    // exactly the way the duo3324 fixture does it.
    if (isset($config) && is_array($config) && is_string($config['state_root'] ?? null) && is_dir($config['state_root'])) {
        @file_put_contents($config['state_root'] . '/provider-errors.log', $error->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    }
    fwrite(STDERR, 'duo reference env provider: ' . $error->getMessage() . "\n");
    exit(1);
}

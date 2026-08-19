<?php
// Reference branch-environment provider for the sandbox pair (round-3 MUP
// §2.2, last bullet). DEV-ONLY: tools/ never ships — cli/src/Onboarding/Adopt.php
// tars only agent/, manifests/ and recovery/.
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
// `duo-reference-env-provider-plan/v1` document naming the external command
// boundary the action may use — without running any command, touching the
// pair, or writing provider state. Acquisition/reap plans consult provider
// state and distinguish fresh, retry and receipt-replay paths; other
// state-dependent retry suppression remains explicitly marked. It exists so
// the provider's
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
//   "destroy_scope": "side",                // the only safe scope for one target lease
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
// `pair.sh` has no per-SIDE lifecycle: `up` converges both sides, while this
// provider's target lease owns only one side. A branch environment is one side.
// So:
//
//   attach  -> prove the configured side already exists through its port map;
//              never run `pair.sh up` or allocate it
//   create  -> `pair.sh up <pair> <port1> <port2>`, then clear exactly this side (drop/create
//              its database, empty its uploads, empty its site repo)
//   destroy -> clear exactly this side and leave the pair running
//   detach  -> no physical action; the absence proof records the release
//
// A side is therefore one reusable preview slot, not one allocation per
// branch. Its physical resource id stays stable, while every absent->present
// acquisition increments a persisted lease generation and rotates ownership.
// Exact terminal reap requests retain their old receipts after reuse; any new
// request carrying an old lease refuses before physical cleanup. The state
// lock covers that compare and every pair/docker action for processes sharing
// this config's state_root. A production provider needs one canonical,
// provider-owned state authority; separate state roots cannot safely control
// the same physical slot.
//
// This development provider passes its state-lock descriptor to each
// synchronous Docker/Git child, so killing the PHP parent does not let a retry
// overlap that child. A CLI or daemon-side job that closes the descriptor or
// returns before its mutation completes is outside that local proof.
// Production fixed-slot providers must bind such host jobs to the lease
// generation (or cancel and await them) at the resource service.
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
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $stateLock = $GLOBALS['duo_reference_provider_state_lock'] ?? null;
    if (is_resource($stateLock)) {
        // Explicit inheritance keeps the physical-mutation lock alive if the
        // controller kills this PHP parent while its child is still running.
        $descriptors[3] = $stateLock;
    }
    $proc = proc_open(
        $argv,
        $descriptors,
        $pipes,
        $cwd,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($proc)) throw new RuntimeException('could not start reference provider command');
    foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
    $input = $stdin ?? '';
    $inputOffset = 0;
    $open = [true, true, true];
    $stdout = '';
    $stderr = '';
    if ($input === '') {
        fclose($pipes[0]);
        $open[0] = false;
    }
    try {
        while ($open[0] || $open[1] || $open[2]) {
            $read = [];
            if ($open[1]) $read[] = $pipes[1];
            if ($open[2]) $read[] = $pipes[2];
            $write = $open[0] ? [$pipes[0]] : [];
            $except = [];
            $selected = stream_select($read, $write, $except, 1);
            if ($selected === false) throw new RuntimeException('could not multiplex reference provider command pipes');
            foreach ($write as $pipe) {
                $written = fwrite($pipe, substr($input, $inputOffset, 65536));
                if ($written === false) throw new RuntimeException('could not send reference provider command input');
                $inputOffset += $written;
                if ($inputOffset === strlen($input)) {
                    fclose($pipes[0]);
                    $open[0] = false;
                }
            }
            foreach ($read as $pipe) {
                $bytes = fread($pipe, 65536);
                if ($bytes === false) throw new RuntimeException('could not read reference provider command output');
                if ($pipe === $pipes[1]) {
                    $stdout .= $bytes;
                    $index = 1;
                } else {
                    $stderr .= $bytes;
                    $index = 2;
                }
                if (feof($pipe)) {
                    fclose($pipe);
                    $open[$index] = false;
                }
            }
        }
    } catch (Throwable $error) {
        foreach ($pipes as $index => $pipe) {
            if ($open[$index] && is_resource($pipe)) fclose($pipe);
        }
        proc_terminate($proc, 9);
        proc_close($proc);
        throw $error;
    }
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

function ref_config_path(string $path, string $label): string {
    ref_require(str_starts_with($path, '/') && $path !== '/', "reference provider $label must be absolute and non-root");
    $segments = array_values(array_filter(explode('/', substr(rtrim($path, '/'), 1)), static fn (string $segment): bool => $segment !== ''));
    ref_require($segments !== [], "reference provider $label must be absolute and non-root");
    foreach ($segments as $segment) {
        ref_require($segment !== '.' && $segment !== '..', "reference provider $label must be lexically normalized");
    }

    // Resolve the longest existing prefix. This catches `/tmp`-style aliases
    // without requiring a repository or state leaf to exist yet, and it keeps
    // overlap checks tied to the path the kernel will actually traverse.
    $probe = '/' . implode('/', $segments);
    $tail = [];
    while (!file_exists($probe) && !is_link($probe)) {
        array_unshift($tail, basename($probe));
        $parent = dirname($probe);
        ref_require($parent !== $probe, "reference provider $label has no resolvable ancestor");
        $probe = $parent;
    }
    $resolved = realpath($probe);
    ref_require(is_string($resolved) && $resolved !== '/', "reference provider $label has no safe non-root ancestor");
    return rtrim($resolved, '/') . ($tail === [] ? '' : '/' . implode('/', $tail));
}

function ref_paths_overlap(string $left, string $right): bool {
    return $left === $right || str_starts_with($left, $right . '/') || str_starts_with($right, $left . '/');
}

/** Removing $target is unsafe only when it would also remove $protected. */
function ref_target_contains_path(string $target, string $protected): bool {
    return $target === $protected || str_starts_with($protected, $target . '/');
}

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
    foreach (['compose_dir', 'controller_repo', 'pair_script', 'state_root'] as $pathKey) {
        $config[$pathKey] = ref_config_path((string) $config[$pathKey], $pathKey);
    }
    $config['_config_path'] = ref_config_path($path, 'config file');
    ref_require(
        preg_match('/^[a-z][a-z0-9]*$/D', (string) $config['pair']) === 1,
        'reference provider pair name must match pair.sh\'s own grammar (lowercase letters/digits, leading letter)'
    );
    ref_require(is_array($config['compose_files'] ?? null) && array_is_list($config['compose_files']) && $config['compose_files'] !== [], 'reference provider config needs a non-empty compose_files list');
    foreach ($config['compose_files'] as $index => $file) {
        ref_require(is_string($file), 'reference provider compose_files entry must be a string');
        $config['compose_files'][$index] = ref_config_path($file, 'compose_files entry');
    }
    $scope = $config['destroy_scope'] ?? 'side';
    ref_require($scope === 'side', "reference provider destroy_scope must be 'side'; one target lease cannot authorize pair-wide source deletion");
    $environments = $config['environments'] ?? null;
    ref_require(is_array($environments) && !array_is_list($environments) && $environments !== [], 'reference provider config has no environments');
    ref_require(count($environments) === 2, 'reference provider config must map exactly the pair\'s two physical sides');
    $roles = [];
    $sides = [];
    foreach ($environments as $name => $environment) {
        ref_require(is_string($name) && $name !== '', 'reference provider environment name is invalid');
        ref_require(is_array($environment) && !array_is_list($environment), "environment '$name' is malformed");
        foreach (['role', 'container', 'service', 'database', 'repo'] as $key) {
            ref_require(is_string($environment[$key] ?? null) && $environment[$key] !== '', "environment '$name' is missing '$key'");
        }
        $environment['repo'] = ref_config_path((string) $environment['repo'], "environment '$name' repo");
        ref_require(in_array($environment['role'], ['source', 'target'], true), "environment '$name' role must be source or target");
        ref_require(in_array($environment['side'] ?? null, [1, 2], true), "environment '$name' side must be 1 or 2");
        ref_require(!isset($sides[$environment['side']]), "environment '$name' aliases an already configured physical side");
        $roles[] = $environment['role'];
        $sides[$environment['side']] = true;
        $port = $environment['port'] ?? null;
        ref_require(is_int($port) && $port >= 1 && $port <= 65535, "environment '$name' port must be a published host port");
        $side = (int) $environment['side'];
        $pair = (string) $config['pair'];
        ref_require($name === $pair . $side, "environment '$name' must use pair.sh's canonical logical name '$pair$side'");
        ref_require($environment['container'] === "duo-$pair-wp$side-1", "environment '$name' container does not belong to its pair side");
        ref_require($environment['service'] === "cli$side", "environment '$name' service does not belong to its pair side");
        ref_require($environment['database'] === "wp_$pair$side", "environment '$name' database does not belong to its pair side");
        ref_require(
            $environment['repo'] === $config['compose_dir'] . "/siterepo/$name",
            "environment '$name' repo does not belong to pair.sh's canonical siterepo root"
        );
        $environments[$name] = $environment;
    }
    sort($roles, SORT_STRING);
    ref_require(isset($sides[1], $sides[2]), 'reference provider config must map physical sides 1 and 2 exactly once');
    ref_require($roles === ['source', 'target'], 'reference provider config must map exactly one source and one target');
    foreach (['container', 'database', 'port', 'repo', 'service'] as $field) {
        $values = array_map(static fn (array $environment): mixed => $environment[$field], array_values($environments));
        ref_require(count(array_unique($values, SORT_REGULAR)) === 2, "reference provider source and target must not share '$field'");
    }
    ref_require(
        isset($environments[(string) $config['source_environment']]),
        'reference provider source_environment names no configured environment'
    );
    ref_require(
        $environments[(string) $config['source_environment']]['role'] === 'source',
        'reference provider source_environment must name the configured source role'
    );
    ref_require($config['db_container'] === 'duo-shared-db', 'reference provider db_container must name sandbox/db.yml\'s canonical database container');
    $source = $environments[(string) $config['source_environment']];
    $target = current(array_filter($environments, static fn (array $environment): bool => $environment['role'] === 'target'));
    ref_require(is_array($target), 'reference provider config has no target environment');
    ref_require(!ref_paths_overlap((string) $source['repo'], (string) $target['repo']), 'reference provider source and target repo paths overlap');
    ref_require(
        !ref_paths_overlap((string) $config['state_root'], (string) $source['repo']),
        "reference provider state authority path '{$config['state_root']}' overlaps the source repo"
    );
    foreach ([(string) $config['controller_repo'], (string) $config['state_root']] as $protectedPath) {
        ref_require(!ref_paths_overlap($protectedPath, (string) $target['repo']), "reference provider authority path '$protectedPath' overlaps the target repo");
    }
    $containedPaths = [
        (string) $config['compose_dir'],
        (string) $config['_config_path'],
        (string) $config['pair_script'],
        ...array_map('strval', $config['compose_files']),
    ];
    foreach ($containedPaths as $protectedPath) {
        ref_require(!ref_target_contains_path((string) $target['repo'], $protectedPath), "reference provider target repo contains protected path '$protectedPath'");
    }
    $config['environments'] = $environments;
    return $config;
}

/** @param array<string,mixed> $config @param array<string,mixed> $environment */
function ref_resource_id(array $config, array $environment): string {
    return 'duo-' . (string) $config['pair'] . '-wp' . (int) $environment['side'];
}

/** @param array<string,mixed> $environment */
function ref_configured_url(array $environment): string {
    return 'http://127.0.0.1:' . (int) $environment['port'];
}

/**
 * Bind every path/name that can redirect a pair, database, media or repository
 * mutation. Capability withholding changes negotiation, not configured mutation topology,
 * and is intentionally excluded.
 *
 * @param array<string,mixed> $config
 */
function ref_resource_config_sha256(array $config): string {
    return ref_hash([
        'compose_dir' => $config['compose_dir'],
        'compose_files' => $config['compose_files'],
        'controller_repo' => $config['controller_repo'],
        'db_container' => $config['db_container'],
        'destroy_scope' => $config['destroy_scope'] ?? 'side',
        'environments' => $config['environments'],
        'format' => $config['format'],
        'pair' => $config['pair'],
        'pair_script' => $config['pair_script'],
        'source_environment' => $config['source_environment'],
        'state_root' => $config['state_root'],
    ]);
}

/** @param array<string,mixed> $config @param array<string,mixed> $environment */
function ref_source_config_sha256(array $config, string $environmentName, array $environment): string {
    return ref_hash([
        'compose_dir' => $config['compose_dir'],
        'compose_files' => $config['compose_files'],
        'db_container' => $config['db_container'],
        'environment' => $environmentName,
        'environment_config' => $environment,
        'format' => $config['format'],
        'pair' => $config['pair'],
        'state_root' => $config['state_root'],
    ]);
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
function ref_identity(array $config, array $environment, string $url, ?array $resource = null): array {
    $pair = (string) $config['pair'];
    $side = (int) $environment['side'];
    $environmentIdentity = 'duo-pair-' . $pair . '-side-' . $side;
    if (($environment['role'] ?? null) === 'source') {
        // Preserve the public pre-slot source identity byte-for-byte. New
        // operations pin the configured source topology separately.
        return [
            'environment_identity' => $environmentIdentity,
            'lease_generation' => 1,
            'lease_id' => 'pair-lease-' . substr(hash('sha256', $environmentIdentity), 0, 20),
            'ownership_receipt_sha256' => ref_hash('ownership:' . $environmentIdentity),
            'resource_id' => ref_resource_id($config, $environment),
            'url' => $url,
        ];
    }
    ref_require(is_array($resource), 'target identity requires preview-slot state');
    $generation = (int) ($resource['generation'] ?? 1);
    $operation = (string) ($resource['operation_id'] ?? 'unallocated-target');
    $resourceConfig = (string) ($resource['resource_config_sha256'] ?? '');
    ref_require($generation >= 1, 'resource lease generation must be positive');
    ref_require(preg_match('/^[a-f0-9]{64}$/D', $resourceConfig) === 1, 'target identity has no configured-topology receipt');
    return [
        'environment_identity' => $environmentIdentity,
        'lease_generation' => $generation,
        'lease_id' => 'pair-lease-' . substr(hash('sha256', $environmentIdentity . '|' . $generation . '|' . $operation . '|' . $resourceConfig), 0, 20),
        'ownership_receipt_sha256' => ref_hash([
            'environment' => $environmentIdentity,
            'generation' => $generation,
            'operation' => $operation,
            'resource_config_sha256' => $resourceConfig,
        ]),
        'resource_id' => ref_resource_id($config, $environment),
        'url' => $url,
    ];
}

function ref_acquisition_key(string $resourceId, string $operation): string {
    return hash('sha256', ref_json(['operation_id' => $operation, 'resource_id' => $resourceId]));
}

/**
 * The physical target survives reap, so its logical owner must not. A missing
 * record is generation zero/absent; every later acquisition rotates the lease
 * while retaining the stable resource id that identifies the reusable slot.
 *
 * @param array<string,mixed> $state
 * @param array<string,mixed> $config
 * @param array<string,mixed> $environment
 * @return array<string,mixed>
 */
function ref_resource(array &$state, array $config, string $environmentName, array $environment): array {
    if (($environment['role'] ?? null) === 'source') {
        return [
            'acquisition_input_sha256' => ref_hash('stable-source'),
            'generation' => 1,
            'mode' => 'source',
            'operation_id' => 'stable-source',
            'resource_config_sha256' => ref_resource_config_sha256($config),
            'state' => 'present',
            'url' => ref_configured_url($environment),
        ];
    }
    $slot = ref_resource_id($config, $environment);
    $resource = $state['resources'][$slot] ?? null;
    $legacy = $state['resources'][$environmentName] ?? null;
    if ($resource === null && is_array($legacy)) {
        ref_require(
            false,
            'legacy preview-slot state has no acquisition history; exclude all controllers, prove the side absent, then remove the legacy resource row before reuse'
        );
    }
    if ($resource === null) {
        return [
            'acquisition_input_sha256' => null,
            'generation' => 0,
            'mode' => null,
            'operation_id' => null,
            'resource_config_sha256' => null,
            'state' => 'absent',
            'url' => ref_configured_url($environment),
        ];
    }
    ref_require(is_array($resource) && !array_is_list($resource), 'preview slot resource state is malformed');
    foreach (['acquisition_input_sha256', 'generation', 'mode', 'operation_id', 'resource_config_sha256', 'state', 'url'] as $key) {
        ref_require(array_key_exists($key, $resource), "preview slot resource state is missing '$key'");
    }
    ref_require(is_int($resource['generation']) && $resource['generation'] >= 1, 'preview slot resource generation is invalid');
    ref_require(in_array($resource['mode'], ['attach', 'create'], true), 'preview slot acquisition mode is invalid');
    ref_require(is_string($resource['operation_id']) && $resource['operation_id'] !== '', 'preview slot operation is invalid');
    ref_require(is_string($resource['acquisition_input_sha256']) && preg_match('/^[a-f0-9]{64}$/D', $resource['acquisition_input_sha256']) === 1, 'preview slot acquisition receipt is invalid');
    ref_require(in_array($resource['state'], ['absent', 'acquiring', 'present', 'reaping'], true), 'preview slot presence state is invalid');
    ref_require(
        is_string($resource['resource_config_sha256'])
            && preg_match('/^[a-f0-9]{64}$/D', $resource['resource_config_sha256']) === 1,
        'preview slot configured-topology receipt is invalid'
    );
    ref_require(
        is_string($resource['url']) && preg_match('#^http://127\.0\.0\.1:[1-9][0-9]{0,4}$#D', $resource['url']) === 1,
        'preview slot URL state is invalid'
    );
    if ($resource['state'] === 'reaping') {
        foreach (['reap_action', 'reap_input_sha256', 'reap_operation_id'] as $key) {
            ref_require(is_string($resource[$key] ?? null) && $resource[$key] !== '', "preview slot reaping state is missing '$key'");
        }
        ref_require(in_array($resource['reap_action'], ['destroy', 'detach'], true), 'preview slot reap action is invalid');
        ref_require(preg_match('/^[a-f0-9]{64}$/D', $resource['reap_input_sha256']) === 1, 'preview slot reap input receipt is invalid');
    }
    return $resource;
}

/** @param array<string,mixed> $request */
function ref_reap_receipt_key(array $request): string {
    return hash('sha256', ref_json([
        'action' => $request['action'],
        'environment' => $request['environment'],
        'input' => $request['input'],
        'operation_id' => $request['operation_id'],
    ]));
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

/**
 * The immutable snapshot is published read-only (ref_chmod_tree 0555) and
 * owned by the host user; `docker cp` carries both into the container, and
 * ref_media_clear_command's `mkdir -p` runs as root. The web and cli
 * containers run as 33:33 (sandbox/pair.yml `user: "33:33"`), so without
 * this hand-back the restored site cannot write inside its own uploads:
 * grind_adoption A6 saw `duo apply` on the rehearsal target fail its required
 * `provider:elementor-css/regenerate_css` action with "file_put_contents(
 * …/uploads/elementor/css/post-1.css): Failed to open stream: Permission
 * denied". Owner and mode are provider-side plumbing, not media bytes: the
 * restore stays bound to media_sha256 (a byte/tree digest) either way.
 *
 * @return list<string>
 */
function ref_media_handback_command(string $container): array {
    return [
        'docker', 'exec', $container, 'sh', '-c',
        'chown -R 33:33 /var/www/html/wp-content/uploads && chmod -R u+rwX,go+rX /var/www/html/wp-content/uploads',
    ];
}

function ref_restore_media_to_container(string $source, string $container): void {
    ref_checked(ref_media_clear_command($container));
    ref_checked(['docker', 'cp', rtrim($source, '/') . '/.', $container . ':/var/www/html/wp-content/uploads']);
    ref_checked(ref_media_handback_command($container));
}

// --------------------------------------------------------------------------
// Provider state
// --------------------------------------------------------------------------

/** @return array<string,mixed> */
function ref_empty_state(): array {
    return [
        'acquisitions' => [], 'fences' => [], 'reap_receipts' => [], 'resources' => [],
        'sessions' => [], 'slot_authorities' => [], 'snapshots' => [], 'source_inspections' => [], 'ttls' => [],
    ];
}

function ref_path_entry_exists(string $path): bool {
    return file_exists($path) || is_link($path);
}

/** @return array<string,mixed> */
function ref_load_state(string $root): array {
    $path = $root . '/state.json';
    $marker = $root . '/state.initialized';
    $stateExists = ref_path_entry_exists($path);
    $markerExists = ref_path_entry_exists($marker);
    ref_require(!$stateExists || is_file($path), 'reference provider state path is malformed');
    ref_require(!$markerExists || is_file($marker), 'reference provider initialization marker is malformed');
    ref_require(
        $stateExists || !$markerExists,
        'reference provider state is missing after initialization; exclude all controllers and restore its last durable state before reuse'
    );
    $state = ref_json_file($path, ref_empty_state());
    ref_require(is_array($state) && !array_is_list($state), 'reference provider state is malformed');
    // These maps are additive provider evidence. Every legacy resource row
    // fails closed in ref_resource(): its acquisition lineage and safe lease
    // generation cannot be reconstructed from the old tombstone.
    if (!array_key_exists('acquisitions', $state)) $state['acquisitions'] = [];
    if (!array_key_exists('reap_receipts', $state)) $state['reap_receipts'] = [];
    if (!array_key_exists('slot_authorities', $state)) $state['slot_authorities'] = [];
    if (!array_key_exists('source_inspections', $state)) $state['source_inspections'] = [];
    foreach (['acquisitions', 'fences', 'reap_receipts', 'resources', 'sessions', 'slot_authorities', 'snapshots', 'source_inspections', 'ttls'] as $key) {
        ref_require(
            is_array($state[$key] ?? null) && ($state[$key] === [] || !array_is_list($state[$key])),
            "reference provider state '$key' is malformed"
        );
    }
    return $state;
}

/** @param array<string,mixed> $state */
function ref_save_state(string $root, array $state): void {
    $path = $root . '/state.json';
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
    $bytes = ref_json($state) . "\n";
    $handle = fopen($tmp, 'x+b');
    if ($handle === false) throw new RuntimeException('could not create reference provider state staging file');
    try {
        chmod($tmp, 0600);
        if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || !fsync($handle)) {
            throw new RuntimeException('could not durably stage reference provider state');
        }
    } catch (Throwable $error) {
        fclose($handle);
        @unlink($tmp);
        throw $error;
    }
    fclose($handle);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('could not publish reference provider state');
    }
    chmod($path, 0600);
    $marker = $root . '/state.initialized';
    if (!is_file($marker)) {
        $markerHandle = fopen($marker, 'x+b');
        if ($markerHandle === false) throw new RuntimeException('could not create reference provider initialization marker');
        $markerBytes = "duo-reference-env-provider-state/v1\n";
        try {
            chmod($marker, 0600);
            if (fwrite($markerHandle, $markerBytes) !== strlen($markerBytes) || !fflush($markerHandle) || !fsync($markerHandle)) {
                throw new RuntimeException('could not durably publish reference provider initialization marker');
            }
        } catch (Throwable $error) {
            fclose($markerHandle);
            @unlink($marker);
            throw $error;
        }
        fclose($markerHandle);
    }
    $directory = fopen($root, 'r');
    if ($directory === false) throw new RuntimeException('could not open reference provider state directory for durability');
    try {
        if (!fsync($directory)) throw new RuntimeException('could not durably publish reference provider state directory entries');
    } finally {
        fclose($directory);
    }
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
    string $resourceConfig,
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
    foreach (ref_lease_tuple($identity, $resourceConfig) as $key => $value) {
        ref_require(($fence[$key] ?? null) === $value, "mutation fence lease differs at '$key'");
    }
    ref_require(
        $fence['generation'] === $input['expected_mutation_generation']
            && $fence['owner'] === $input['expected_mutation_owner'],
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
    ref_restore_database($config, (string) $environment['database'], '');
    ref_checked(ref_media_clear_command((string) $environment['container']));
    $repo = (string) $environment['repo'];
    ref_remove_tree($repo);
    if (!mkdir($repo, 0777, true) && !is_dir($repo)) throw new RuntimeException('could not recreate the cleared side repository');
    chmod($repo, 0777);
}

/** @param array<string,mixed> $environment */
function ref_assert_action_role(string $action, array $environment): void {
    $source = ['snapshot-abort', 'snapshot-create', 'snapshot-prepare', 'snapshot-read'];
    $target = [
        'attach', 'create', 'destroy', 'detach', 'mutation-acquire', 'mutation-read',
        'mutation-release', 'repository-materialize', 'snapshot-restore', 'ttl-read',
        'ttl-set', 'url-set',
    ];
    if (in_array($action, $source, true)) {
        ref_require(($environment['role'] ?? null) === 'source', "action '$action' requires the configured source role");
    }
    if (in_array($action, $target, true)) {
        ref_require(($environment['role'] ?? null) === 'target', "action '$action' requires the configured target role");
    }
}

/** @param array<string,mixed> $resource @param array<string,mixed> $config */
function ref_assert_resource_config(array $resource, array $config): void {
    ref_require(
        ($resource['resource_config_sha256'] ?? null) === ref_resource_config_sha256($config),
        'preview-slot configured mutation topology changed while its lease is active'
    );
}

/**
 * One state authority owns exactly one target side for its lifetime. Otherwise
 * changing `pair` or `side` creates a fresh map key while retaining access to
 * the old database/container/repository endpoints.
 *
 * @param array<string,mixed> $state
 * @param array<string,mixed> $config
 * @param array<string,mixed> $environment
 */
function ref_assert_slot_authority(array &$state, array $config, array $environment, bool $initialize): void {
    $expected = [
        'resource_config_sha256' => ref_resource_config_sha256($config),
        'resource_id' => ref_resource_id($config, $environment),
    ];
    $authority = $state['slot_authorities']['target'] ?? null;
    if ($authority === null) {
        if ($initialize) $state['slot_authorities']['target'] = $expected + ['generation' => 0];
        return;
    }
    ref_require(is_array($authority) && !array_is_list($authority), 'preview-slot state authority is malformed');
    ref_require(
        ($authority['resource_config_sha256'] ?? null) === $expected['resource_config_sha256']
            && ($authority['resource_id'] ?? null) === $expected['resource_id'],
        'preview-slot state authority is bound to another configured target'
    );
    ref_require(is_int($authority['generation'] ?? null) && $authority['generation'] >= 0, 'preview-slot state authority generation is invalid');
    $resource = $state['resources'][$expected['resource_id']] ?? null;
    if ($authority['generation'] > 0) {
        ref_require(is_array($resource), 'preview-slot resource state is missing beneath its initialized authority');
        ref_require(($resource['generation'] ?? null) === $authority['generation'], 'preview-slot resource generation differs from its state authority');
    } else {
        ref_require($resource === null, 'preview-slot generation-zero authority has unexpected resource state');
    }
}

/** @param array<string,mixed> $record */
function ref_assert_acquisition(array $record): void {
    foreach (['generation', 'input_sha256', 'mode', 'operation_id', 'resource_config_sha256', 'resource_id', 'state'] as $key) {
        ref_require(array_key_exists($key, $record), "preview-slot acquisition history is missing '$key'");
    }
    ref_require(is_int($record['generation']) && $record['generation'] >= 1, 'preview-slot acquisition generation is invalid');
    ref_require(is_string($record['input_sha256']) && preg_match('/^[a-f0-9]{64}$/D', $record['input_sha256']) === 1, 'preview-slot acquisition input receipt is invalid');
    ref_require(in_array($record['mode'], ['attach', 'create'], true), 'preview-slot acquisition history mode is invalid');
    ref_require(is_string($record['operation_id']) && $record['operation_id'] !== '', 'preview-slot acquisition history operation is invalid');
    ref_require(
        is_string($record['resource_config_sha256']) && preg_match('/^[a-f0-9]{64}$/D', $record['resource_config_sha256']) === 1,
        'preview-slot acquisition configured-topology receipt is invalid'
    );
    ref_require(is_string($record['resource_id']) && $record['resource_id'] !== '', 'preview-slot acquisition resource is invalid');
    ref_require(in_array($record['state'], ['acquiring', 'present', 'terminal'], true), 'preview-slot acquisition history state is invalid');
}

/** @param array<string,mixed> $state @param array<string,mixed> $resource */
function ref_assert_absent_lineage(array $state, string $resourceId, array $resource): void {
    if ($resource['state'] !== 'absent' || (int) $resource['generation'] === 0) return;
    $key = ref_acquisition_key($resourceId, (string) $resource['operation_id']);
    $acquisition = $state['acquisitions'][$key] ?? null;
    ref_require(is_array($acquisition), 'absent preview slot has no terminal acquisition history');
    ref_assert_acquisition($acquisition);
    ref_require(
        $acquisition['generation'] === $resource['generation']
            && $acquisition['input_sha256'] === $resource['acquisition_input_sha256']
            && $acquisition['mode'] === $resource['mode']
            && $acquisition['operation_id'] === $resource['operation_id']
            && $acquisition['resource_config_sha256'] === $resource['resource_config_sha256']
            && $acquisition['resource_id'] === $resourceId
            && $acquisition['state'] === 'terminal',
        'absent preview-slot lineage differs from its terminal acquisition'
    );
}

/** @param array<string,mixed> $identity @return array<string,mixed> */
function ref_lease_tuple(array $identity, string $resourceConfig): array {
    return [
        'environment_identity' => $identity['environment_identity'],
        'lease_generation' => $identity['lease_generation'],
        'lease_id' => $identity['lease_id'],
        'ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
        'resource_config_sha256' => $resourceConfig,
        'resource_id' => $identity['resource_id'],
    ];
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
    ref_assert_action_role($action, $environment);

    if (in_array($action, ['destroy', 'detach'], true)) {
        $receiptKey = ref_reap_receipt_key($request);
        $cached = $state['reap_receipts'][$receiptKey] ?? null;
        if (is_array($cached)) return $cached;
    }

    $resourceId = ref_resource_id($config, $environment);
    $resourceConfig = ref_resource_config_sha256($config);
    if (($environment['role'] ?? null) === 'target') {
        ref_assert_slot_authority($state, $config, $environment, true);
    }
    $resource = ref_resource($state, $config, $environmentName, $environment);
    if (($environment['role'] ?? null) === 'target') {
        ref_assert_absent_lineage($state, $resourceId, $resource);
        if ($resource['state'] === 'reaping' && !in_array($action, ['destroy', 'detach'], true)) {
            ref_require(false, 'preview-slot reap is incomplete; retry the exact reap request');
        }
    }
    if (($environment['role'] ?? null) === 'source'
        && in_array($action, ['snapshot-abort', 'snapshot-create', 'snapshot-prepare', 'snapshot-read'], true)) {
        $sourceConfig = ref_source_config_sha256($config, $environmentName, $environment);
        ref_require(
            ($state['source_inspections'][ref_snapshot_key($environmentName, $operation)] ?? null) === $sourceConfig,
            'source topology differs from the inspected operation; inspect again under a new operation'
        );
    }

    if ($action === 'attach' || $action === 'create') {
        ref_require(($input['mode'] ?? null) === $action, 'target acquisition mode is malformed');
        $inputSha = ref_hash($input);
        $acquisitionKey = ref_acquisition_key($resourceId, $operation);
        $acquisition = $state['acquisitions'][$acquisitionKey] ?? null;
        if (is_array($acquisition)) {
            ref_assert_acquisition($acquisition);
            ref_require(
                $acquisition['operation_id'] === $operation
                    && $acquisition['resource_id'] === $resourceId
                    && $acquisition['mode'] === $action
                    && $acquisition['input_sha256'] === $inputSha
                    && $acquisition['resource_config_sha256'] === $resourceConfig,
                'preview-slot acquisition retry differs from its persisted intent'
            );
            ref_require($acquisition['state'] !== 'terminal', 'a terminal preview-slot acquisition cannot be resurrected');
            ref_require(
                in_array($resource['state'], ['acquiring', 'present'], true)
                    && $resource['generation'] === $acquisition['generation']
                    && $resource['mode'] === $acquisition['mode']
                    && $resource['operation_id'] === $operation
                    && $resource['acquisition_input_sha256'] === $inputSha
                    && $resource['state'] === $acquisition['state'],
                'preview-slot acquisition history differs from current ownership'
            );
            ref_assert_resource_config($resource, $config);
        } else {
            ref_require($resource['state'] === 'absent', 'preview slot is already owned by another acquisition');
            ref_require((int) $resource['generation'] < PHP_INT_MAX, 'preview slot lease generation is exhausted');
            $resource = [
                'acquisition_input_sha256' => $inputSha,
                'generation' => (int) $resource['generation'] + 1,
                'mode' => $action,
                'operation_id' => $operation,
                'resource_config_sha256' => $resourceConfig,
                'state' => $action === 'create' ? 'acquiring' : 'present',
                'url' => ref_configured_url($environment),
            ];
            $acquisition = [
                'generation' => $resource['generation'],
                'input_sha256' => $inputSha,
                'mode' => $action,
                'operation_id' => $operation,
                'resource_config_sha256' => $resourceConfig,
                'resource_id' => $resourceId,
                'state' => $resource['state'],
            ];
            $state['acquisitions'][$acquisitionKey] = $acquisition;
            $state['resources'][$resourceId] = $resource;
            $state['slot_authorities']['target']['generation'] = $resource['generation'];
            unset($state['ttls'][$resourceId]);
            if ($action === 'create') {
                // Publish create intent before allocation/cleanup. A crash may
                // make the exact operation clear its unpopulated side again,
                // but another operation cannot claim an ambiguous slot.
                ref_save_state((string) $config['state_root'], $state);
            }
        }

        if ($action === 'attach') {
            // Attach is proof of an independently existing slot, never an
            // allocation alias. Failed discovery publishes no new ownership.
            $observedUrl = ref_discover_url($environment, false);
            ref_require($observedUrl === $resource['url'], 'preview-slot URL changed during attach');
            $resource['state'] = 'present';
            $acquisition['state'] = 'present';
            $state['acquisitions'][$acquisitionKey] = $acquisition;
            $state['resources'][$resourceId] = $resource;
            ref_save_state((string) $config['state_root'], $state);
            return ref_identity($config, $environment, $observedUrl, $resource) + ['presence' => 'present'];
        }

        // Only an acquiring intent may allocate/converge the pair. A retry of
        // a present lease proves the existing side and never recreates either
        // source or target under old ownership evidence.
        if ($resource['state'] === 'acquiring') ref_checked(ref_pair_up_command($config));
        $observedUrl = ref_discover_url($environment, false);
        ref_require($observedUrl === $resource['url'], 'preview-slot URL changed during acquisition');
        $identity = ref_identity($config, $environment, $observedUrl, $resource);
        if ($resource['state'] === 'acquiring') {
            ref_clear_side($config, $environment);
            $resource['state'] = 'present';
            $acquisition['state'] = 'present';
            $state['acquisitions'][$acquisitionKey] = $acquisition;
            $state['resources'][$resourceId] = $resource;
            ref_save_state((string) $config['state_root'], $state);
        }
        return $identity + ['presence' => 'present'];
    }

    if (in_array($action, ['destroy', 'detach'], true) && in_array($resource['state'], ['present', 'reaping'], true)) {
        $expectedAction = $resource['mode'] === 'create' ? 'destroy' : 'detach';
        ref_require($action === $expectedAction, "preview slot acquired with '{$resource['mode']}' must be reaped with '$expectedAction'");
    }

    if ($action === 'inspect') {
        if (array_key_exists('role', $input)) {
            ref_require($input['role'] === $environment['role'], 'inspect role differs from configured environment role');
        }
        ref_require($resource['state'] !== 'acquiring', 'preview slot acquisition is incomplete; retry the exact acquisition');
        ref_require($resource['state'] !== 'reaping', 'preview-slot reap is incomplete; retry the exact reap request');
        if (($environment['role'] ?? null) === 'source') {
            $sourceKey = ref_snapshot_key($environmentName, $operation);
            $sourceConfig = ref_source_config_sha256($config, $environmentName, $environment);
            $inspectedConfig = $state['source_inspections'][$sourceKey] ?? null;
            ref_require(
                $inspectedConfig === null || $inspectedConfig === $sourceConfig,
                'source topology changed after its operation identity was inspected'
            );
            $state['source_inspections'][$sourceKey] = $sourceConfig;
            $url = ref_discover_url($environment, false);
            $identityResource = $resource;
        } elseif ((int) $resource['generation'] === 0) {
            $url = ref_configured_url($environment);
            $identityResource = $resource;
            $identityResource['generation'] = 1;
            $identityResource['operation_id'] = 'unallocated-target';
            $identityResource['resource_config_sha256'] = $resourceConfig;
        } else {
            $url = (string) $resource['url'];
            $identityResource = $resource;
            if ($resource['state'] === 'present') {
                ref_assert_resource_config($resource, $config);
                ref_require(ref_discover_url($environment, false) === $url, 'active preview-slot URL differs from its lease');
            }
        }
        $identity = ref_identity($config, $environment, $url, $identityResource);
        if (array_key_exists('expected_environment_identity', $input)) ref_assert_identity_input($input, $identity);
        return $identity + ['presence' => $resource['state'] === 'present' ? 'present' : 'absent'];
    }

    if (($environment['role'] ?? null) === 'target') {
        ref_require(
            $resource['state'] === 'present'
                || $resource['state'] === 'reaping' && in_array($action, ['destroy', 'detach'], true),
            'preview slot has no active owner'
        );
        ref_assert_resource_config($resource, $config);
    }
    $identity = ref_identity(
        $config,
        $environment,
        ($environment['role'] ?? null) === 'target' ? (string) $resource['url'] : ref_discover_url($environment, false),
        $resource
    );

    if ($action === 'snapshot-prepare') {
        ref_require(($environment['role'] ?? null) === 'source', 'only the source can prepare a snapshot');
        ref_assert_identity_input($input, $identity);
        $session = $input['snapshot_session_id'] ?? null;
        ref_require(is_string($session) && $session !== '', 'snapshot session is absent');
        $key = ref_snapshot_key($environmentName, $operation);
        $sourceConfig = ref_source_config_sha256($config, $environmentName, $environment);
        ref_require(
            ($state['source_inspections'][$key] ?? null) === $sourceConfig,
            'source topology differs from the inspected operation; inspect again under a new operation'
        );
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
                'source_config_sha256' => $sourceConfig,
                'source_identity' => $identity['environment_identity'],
                'state' => 'preparing',
            ];
            $state['sessions'][$key] = $prepared;
            ref_save_state((string) $config['state_root'], $state);
        }
        ref_require(($prepared['snapshot_session_id'] ?? null) === $session, 'snapshot prepare session changed');
        ref_require(($prepared['source_identity'] ?? null) === $identity['environment_identity'], 'snapshot prepare source changed');
        ref_require(($prepared['source_config_sha256'] ?? null) === $sourceConfig, 'snapshot prepare source topology changed');
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
        ref_require(
            ($prepared['source_config_sha256'] ?? null) === ref_source_config_sha256($config, $environmentName, $environment),
            'snapshot create source topology changed after prepare'
        );
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
        $leaseTuple = ref_lease_tuple($identity, $resourceConfig);
        $id = 'mutation-' . substr(hash('sha256', $resourceId . '|' . $identity['lease_generation'] . '|' . $operation), 0, 20);
        $fence = $state['fences'][$id] ?? null;
        if (!is_array($fence)) {
            foreach ($state['fences'] as $candidate) {
                if (is_array($candidate)
                    && ($candidate['resource_id'] ?? null) === $identity['resource_id']
                    && ($candidate['lease_generation'] ?? null) === $identity['lease_generation']
                    && ($candidate['state'] ?? null) === 'held') {
                    ref_require(false, 'preview slot already has a held mutation fence');
                }
            }
            $receipt = ref_hash('held:' . $id . ':' . $owner);
            $fence = $leaseTuple + [
                'generation' => 1,
                'held_receipt' => $receipt,
                'id' => $id,
                'owner' => $owner,
                'receipt' => $receipt,
                'state' => 'held',
            ];
            $state['fences'][$id] = $fence;
        }
        foreach ($leaseTuple as $key => $value) {
            ref_require(($fence[$key] ?? null) === $value, "mutation acquire lease differs at '$key'");
        }
        ref_require($fence['owner'] === $owner && $fence['state'] === 'held', 'mutation acquire is not idempotent/exclusive');
        return ref_fence_result($identity, $fence);
    }

    if ($action === 'mutation-read') {
        return ref_fence_result($identity, ref_require_fence($state, $input, $identity, $resourceConfig, false));
    }

    if ($action === 'mutation-release') {
        $fence = ref_require_fence($state, $input, $identity, $resourceConfig, false, true);
        if ($fence['state'] === 'held') {
            $fence['state'] = 'released';
            $fence['receipt'] = ref_hash('released:' . $fence['id'] . ':' . $fence['owner']);
            $state['fences'][$fence['id']] = $fence;
        }
        ref_require($fence['state'] === 'released', 'mutation release has an invalid fence state');
        return ref_fence_result($identity, $fence);
    }

    if ($action === 'snapshot-restore') {
        ref_require_fence($state, $input, $identity, $resourceConfig);
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
        ref_require_fence($state, $input, $identity, $resourceConfig);
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
        ref_require_fence($state, $input, $identity, $resourceConfig);
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
        ref_require_fence($state, $input, $identity, $resourceConfig);
        $seconds = $input['ttl_seconds'] ?? null;
        ref_require(is_int($seconds) && $seconds >= 60, 'TTL set is invalid');
        $ttl = $state['ttls'][$resourceId] ?? null;
        if (!is_array($ttl) || ($ttl['operation_id'] ?? null) !== $operation) {
            $ttl = [
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $seconds), 'generation' => 1,
                'lease_id' => 'ttl-lease-' . substr(hash('sha256', $resourceId . '|' . $operation), 0, 20),
                'operation_id' => $operation,
            ];
            $ttl['receipt'] = ref_hash([
                'expires_at' => $ttl['expires_at'], 'generation' => $ttl['generation'],
                'lease_id' => $ttl['lease_id'], 'operation_id' => $ttl['operation_id'],
            ]);
            $state['ttls'][$resourceId] = $ttl;
        }
        return $identity + [
            'expires_at' => $ttl['expires_at'], 'ttl_generation' => $ttl['generation'], 'ttl_lease_id' => $ttl['lease_id'],
            'ttl_receipt_sha256' => $ttl['receipt'], 'ttl_state' => 'active',
        ];
    }

    if ($action === 'ttl-read') {
        ref_assert_identity_input($input, $identity);
        $ttl = $state['ttls'][$resourceId] ?? null;
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
        ref_require_fence($state, $input, $identity, $resourceConfig);
        ref_require(($input['compare_and_reap'] ?? null) === true, 'reap lacks compare-and-reap intent');
        $acquisitionKey = ref_acquisition_key($resourceId, (string) $resource['operation_id']);
        $acquisition = $state['acquisitions'][$acquisitionKey] ?? null;
        ref_require(is_array($acquisition), 'preview-slot reap has no acquisition history');
        ref_assert_acquisition($acquisition);
        ref_require(
            $acquisition['generation'] === $resource['generation']
                && $acquisition['input_sha256'] === $resource['acquisition_input_sha256']
                && $acquisition['mode'] === $resource['mode']
                && $acquisition['operation_id'] === $resource['operation_id']
                && $acquisition['resource_config_sha256'] === $resource['resource_config_sha256']
                && $acquisition['resource_id'] === $resourceId
                && $acquisition['state'] === 'present',
            'preview-slot reap acquisition history differs from current ownership'
        );
        $reapInputSha = ref_hash($input);
        if ($resource['state'] === 'reaping') {
            ref_require(
                $resource['reap_action'] === $action
                    && $resource['reap_input_sha256'] === $reapInputSha
                    && $resource['reap_operation_id'] === $operation,
                'preview-slot reap retry differs from its persisted intent'
            );
        } else {
            $resource['reap_action'] = $action;
            $resource['reap_input_sha256'] = $reapInputSha;
            $resource['reap_operation_id'] = $operation;
            $resource['state'] = 'reaping';
            $state['resources'][$resourceId] = $resource;
            // Persist the exact reap intent before cleanup. Any partial clear
            // can then only be resumed by this compare-and-reap request under
            // the same held mutation fence.
            ref_save_state((string) $config['state_root'], $state);
        }
        // Every provider-owned comparison precedes physical cleanup. A corrupt
        // history row is a refusal, never a delete-then-discover-the-gap path.
        if ($action === 'destroy') ref_clear_side($config, $environment);
        $resource['state'] = 'absent';
        unset($resource['reap_action'], $resource['reap_input_sha256'], $resource['reap_operation_id']);
        $state['resources'][$resourceId] = $resource;
        unset($state['ttls'][$resourceId]);
        $acquisition['state'] = 'terminal';
        $state['acquisitions'][$acquisitionKey] = $acquisition;
        $result = [
            'absence_proof_sha256' => ref_hash([$action, $identity['environment_identity'], $identity['resource_id'], $operation]),
            'disposition' => $action === 'destroy' ? 'destroyed' : 'detached',
            'environment_identity' => $identity['environment_identity'], 'lease_generation' => $identity['lease_generation'],
            'lease_id' => $identity['lease_id'], 'ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
            'resource_id' => $identity['resource_id'],
        ];
        $state['reap_receipts'][ref_reap_receipt_key($request)] = $result;
        // Persist terminal evidence before acknowledging the destructive call.
        // An exact replay can now return this receipt without touching a later
        // generation that reuses the same physical container.
        ref_save_state((string) $config['state_root'], $state);
        return $result;
    }

    throw new RuntimeException("unsupported reference provider action '$action'");
}

// --------------------------------------------------------------------------
// --print-plan
// --------------------------------------------------------------------------

/**
 * Validate one request's static boundary as ref_dispatch() would, then
 * describe its external-command boundary. No docker, git or filesystem write.
 *
 * State-dependent checks (is there a prepared snapshot session, is the fence
 * held) are deliberately NOT performed. If atomic provider state exists, it is
 * read only to validate the target's real generation-bound identity; otherwise
 * a fenced target plan refuses instead of inventing an identity. The document
 * records the remaining boundary in `state_dependent`.
 *
 * @param array<string,mixed> $request
 * @param array<string,mixed> $config
 * @param ?array<string,mixed> $state
 * @return array<string,mixed>
 */
function ref_plan(array $request, array $config, ?array $state = null): array {
    $environmentName = (string) $request['environment'];
    $environment = $config['environments'][$environmentName] ?? null;
    ref_require(is_array($environment), "unknown reference environment '$environmentName'");
    $action = (string) $request['action'];
    $input = $request['input'];
    $operation = (string) $request['operation_id'];
    ref_require(
        $action === 'capabilities' || isset(ref_action_capability()[$action]),
        "unsupported reference provider action '$action'"
    );
    ref_assert_capability($config, $action);
    if ($action !== 'capabilities') ref_assert_action_role($action, $environment);

    $planState = $state ?? [
        'acquisitions' => [], 'fences' => [], 'reap_receipts' => [], 'resources' => [],
        'sessions' => [], 'slot_authorities' => [], 'snapshots' => [], 'source_inspections' => [], 'ttls' => [],
    ];
    $url = ref_configured_url($environment);
    $urlSource = 'config';
    $identityAuthoritative = ($environment['role'] ?? null) === 'source';
    $identityResource = null;
    $cachedReap = null;

    // Capabilities are deliberately state-independent. A corrupt/legacy slot
    // must not prevent the client from learning the provider's protocol, and a
    // target identity shown here is explicitly non-authoritative.
    if ($action === 'capabilities') {
        if (($environment['role'] ?? null) === 'target') {
            $identityResource = [
                'generation' => 1,
                'operation_id' => 'unallocated-target',
                'resource_config_sha256' => ref_resource_config_sha256($config),
            ];
        }
    } elseif (($environment['role'] ?? null) === 'target') {
        if (in_array($action, ['destroy', 'detach'], true)) {
            $cached = $planState['reap_receipts'][ref_reap_receipt_key($request)] ?? null;
            if (is_array($cached)) $cachedReap = $cached;
        }
        if (is_array($cachedReap)) {
            $identity = [
                'environment_identity' => $cachedReap['environment_identity'],
                'lease_generation' => $cachedReap['lease_generation'],
                'lease_id' => $cachedReap['lease_id'],
                'ownership_receipt_sha256' => $cachedReap['ownership_receipt_sha256'],
                'resource_id' => $cachedReap['resource_id'],
                'url' => $url,
            ];
            $identityAuthoritative = true;
        } else {
            ref_assert_slot_authority($planState, $config, $environment, false);
            $identityResource = ref_resource($planState, $config, $environmentName, $environment);
            ref_assert_absent_lineage($planState, ref_resource_id($config, $environment), $identityResource);
            if (in_array($identityResource['state'], ['acquiring', 'present', 'reaping'], true)) {
                ref_assert_resource_config($identityResource, $config);
                $url = (string) $identityResource['url'];
                $urlSource = 'provider-state';
                $identityAuthoritative = true;
            } else {
                $identityResource['generation'] = max(1, (int) $identityResource['generation']);
                $identityResource['operation_id'] = $identityResource['operation_id'] ?? 'unallocated-target';
                $identityResource['resource_config_sha256'] = $identityResource['resource_config_sha256']
                    ?? ref_resource_config_sha256($config);
            }
        }
    }
    if (!isset($identity)) $identity = ref_identity($config, $environment, $url, $identityResource);
    $commands = [];
    if ($action !== 'capabilities' && ($environment['role'] ?? null) === 'source') {
        $commands[] = ['argv' => ref_port_command($environment)];
    }
    $stateDependent = true;
    $identityChecked = false;

    if ($action === 'repository-materialize') {
        $commit = $input['branch_commit'] ?? null;
        ref_require(is_string($commit) && preg_match('/^[a-f0-9]{40}$/D', $commit) === 1, 'repository materialization commit is invalid');
    }
    if ($action === 'ttl-set') {
        ref_require(is_int($input['ttl_seconds'] ?? null) && $input['ttl_seconds'] >= 60, 'TTL set is invalid');
    }
    if (in_array($action, ['destroy', 'detach'], true)) {
        ref_require(($input['compare_and_reap'] ?? null) === true, 'reap lacks compare-and-reap intent');
    }
    if (!is_array($cachedReap) && is_array($input) && array_key_exists('expected_environment_identity', $input)) {
        ref_require($identityAuthoritative, 'cannot validate a fenced target plan without an active provider lease');
        ref_assert_identity_input($input, $identity);
        $identityChecked = true;
    }

    if ($action !== 'capabilities' && !is_array($cachedReap) && ($environment['role'] ?? null) === 'target' && is_array($identityResource)) {
        if ($identityResource['state'] === 'reaping' && !in_array($action, ['destroy', 'detach'], true)) {
            ref_require(false, 'preview-slot reap is incomplete; retry the exact reap request');
        }
        if ($action === 'inspect') {
            ref_require($identityResource['state'] !== 'acquiring', 'preview slot acquisition is incomplete; retry the exact acquisition');
            ref_require($identityResource['state'] !== 'reaping', 'preview-slot reap is incomplete; retry the exact reap request');
        }
        if (!in_array($action, ['attach', 'capabilities', 'create', 'inspect'], true)) {
            ref_require(
                $identityResource['state'] === 'present'
                    || $identityResource['state'] === 'reaping' && in_array($action, ['destroy', 'detach'], true),
                'preview slot has no active owner'
            );
        }
    }

    if (!is_array($cachedReap) && $identityAuthoritative && in_array($action, ['destroy', 'detach'], true) && is_array($identityResource)) {
        $expectedAction = $identityResource['mode'] === 'create' ? 'destroy' : 'detach';
        ref_require($action === $expectedAction, "preview slot acquired with '{$identityResource['mode']}' must be reaped with '$expectedAction'");
        if ($identityResource['state'] === 'reaping') {
            ref_require(
                $identityResource['reap_action'] === $action
                    && $identityResource['reap_input_sha256'] === ref_hash($input)
                    && $identityResource['reap_operation_id'] === $operation,
                'preview-slot reap retry differs from its persisted intent'
            );
        }
    }

    switch ($action) {
        case 'capabilities':
            $stateDependent = false;
            break;
        case 'inspect':
            if (($environment['role'] ?? null) === 'target'
                && is_array($identityResource) && $identityResource['state'] === 'present') {
                $commands[] = ['argv' => ref_port_command($environment)];
            }
            break;
        case 'detach':
            ref_require(($input['compare_and_reap'] ?? null) === true, 'reap lacks compare-and-reap intent');
            break;
        case 'attach':
        case 'create':
            ref_require(($input['mode'] ?? null) === $action, 'target acquisition mode is malformed');
            $resourceId = ref_resource_id($config, $environment);
            $inputSha = ref_hash($input);
            $acquisition = $planState['acquisitions'][ref_acquisition_key($resourceId, $operation)] ?? null;
            $phase = 'fresh';
            if (is_array($acquisition)) {
                ref_assert_acquisition($acquisition);
                ref_require(
                    $acquisition['operation_id'] === $operation
                        && $acquisition['resource_id'] === $resourceId
                        && $acquisition['mode'] === $action
                        && $acquisition['input_sha256'] === $inputSha
                        && $acquisition['resource_config_sha256'] === ref_resource_config_sha256($config),
                    'preview-slot acquisition retry differs from its persisted intent'
                );
                ref_require($acquisition['state'] !== 'terminal', 'a terminal preview-slot acquisition cannot be resurrected');
                ref_require(
                    is_array($identityResource)
                        && in_array($identityResource['state'], ['acquiring', 'present'], true)
                        && $identityResource['generation'] === $acquisition['generation']
                        && $identityResource['mode'] === $acquisition['mode']
                        && $identityResource['operation_id'] === $operation
                        && $identityResource['acquisition_input_sha256'] === $inputSha
                        && $identityResource['state'] === $acquisition['state'],
                    'preview-slot acquisition history differs from current ownership'
                );
                $phase = (string) $identityResource['state'];
            } else {
                ref_require(
                    is_array($identityResource) && $identityResource['state'] === 'absent',
                    'preview slot is already owned by another acquisition'
                );
            }
            if ($action === 'create' && $phase !== 'present') {
                $commands[] = ['argv' => ref_pair_up_command($config)];
            }
            $commands[] = ['argv' => ref_port_command($environment)];
            if ($action === 'create' && $phase !== 'present') {
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
            $commands[] = ['argv' => ['docker', 'cp', '<immutable-media>/.', (string) $environment['container'] . ':/var/www/html/wp-content/uploads']];
            $commands[] = ['argv' => ref_media_handback_command((string) $environment['container'])];
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
            if (!is_array($cachedReap)) {
                $commands = array_merge($commands, ref_clear_side_plan($config, $environment));
            }
            break;
        default:
            break;
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
        'identity_authoritative' => $identityAuthoritative,
        'identity_input_checked' => $identityChecked,
        'operation_id' => (string) $request['operation_id'],
        'provider' => ['id' => 'duo-reference-env-provider', 'protocol' => 1],
        'state_dependent' => $stateDependent,
        'url_source' => $urlSource,
    ];
}

/**
 * @param array<string,mixed> $config
 * @param array<string,mixed> $environment
 * @return list<array<string,mixed>>
 */
function ref_clear_side_plan(array $config, array $environment): array {
    return [
        ['argv' => ['docker', 'exec', '-i', (string) $config['db_container'], 'mariadb', '-uroot', '-proot'], 'stdin' => 'drop-create'],
        ['argv' => ['docker', 'exec', '-i', (string) $config['db_container'], 'mariadb', '-uroot', '-proot', (string) $environment['database']], 'stdin' => 'empty-dump'],
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
$planOnly = false;
try {
    // $_SERVER['argv'] rather than the bare $argv superglobal: PHPStan cannot
    // prove $argv is defined at file scope (it depends on register_argc_argv),
    // and tools/codemod/move-modules.php already settled that question the
    // same way.
    $arguments = array_slice(is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [], 1);
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
        $planRoot = (string) $config['state_root'];
        $hasPlanEvidence = ref_path_entry_exists($planRoot . '/state.json')
            || ref_path_entry_exists($planRoot . '/state.initialized');
        $planState = $request['action'] !== 'capabilities' && $hasPlanEvidence ? ref_load_state($planRoot) : null;
        echo ref_json(ref_plan($request, $config, $planState)) . "\n";
        exit(0);
    }

    if ($request['action'] === 'capabilities') {
        $state = ref_empty_state();
        $result = ref_dispatch($request, $config, $state);
    } else {
        $root = (string) $config['state_root'];
        ref_require(is_dir($root), 'reference provider state root is unavailable');
        $lock = fopen($root . '/state.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('could not lock reference provider state');
        $GLOBALS['duo_reference_provider_state_lock'] = $lock;
        try {
            $state = ref_load_state($root);
            ref_log($root, $request);
            $result = ref_dispatch($request, $config, $state);
            ref_save_state($root, $state);
        } finally {
            unset($GLOBALS['duo_reference_provider_state_lock']);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
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
    if (!$planOnly && isset($config) && is_array($config) && is_string($config['state_root'] ?? null) && is_dir($config['state_root'])) {
        @file_put_contents($config['state_root'] . '/provider-errors.log', $error->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    }
    fwrite(STDERR, 'duo reference env provider: ' . $error->getMessage() . "\n");
    exit(1);
}

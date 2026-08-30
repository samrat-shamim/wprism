<?php
// Reference branch-environment provider for the sandbox pair (round-3 MUP
// §2.2, last bullet). DEV-ONLY: tools/ never ships — cli/src/Onboarding/Adopt.php
// assembles the source capsules into agent/adapter-library and tars only the
// closed agent/ plus recovery/.
//
// WHAT THIS IS
// ------------
// `wprism rehearse` needs a machine-local provider that can snapshot a source
// environment, acquire a disposable target, materialize a repository into it
// and reap it again. WPrism orchestrates providers; it does not supply hosting,
// so every customer writes their own. This worked example has two explicit
// modes. Ordinary pair mode drives `sandbox/bin/pair.sh`'s two-sided pair on
// the shared MariaDB and withholds `environment.containment.verify`. Opt-in
// `contained_preview` mode creates a separate internal-only target topology
// and advertises containment only while its preboot controls and live probes
// remain enforceable.
//
// It is DERIVED FROM sandbox/tests/fixtures/environment-materializer-live-provider.php and
// keeps that file's argv and JSON contract with
// `\WPrism\Orchestrator\CommandEnvironmentProvider` byte-for-byte:
//
//   * argv is exactly `<provider> <config.json>`; the request is one canonical
//     JSON object on stdin and the response is one canonical JSON object plus
//     a newline on stdout (CommandEnvironmentProvider::call() re-encodes the
//     response and compares bytes, so any other spacing or key order is
//     "noncanonical evidence" and the operation refuses).
//   * the request's closed key set is {action, environment, format, input,
//     operation_id}; `format` must be
//     `wprism-branch-environment-provider-request/v1`; `input` is an object, and
//     the empty LIST `[]` is accepted for the capabilities probe only —
//     mirroring CommandEnvironmentProvider's own boundary exactly.
//   * the response is {action, environment, format, operation_id, provider,
//     result, status} with `provider.protocol` 1 and `status` "ok"; every
//     per-action result key set is the one
//     CommandEnvironmentProvider::validateActionResult() closes over.
//   * capability negotiation happens through the `capabilities` action, and
//     the advertised ids are members of
//     `\WPrism\Orchestrator\EnvironmentProviderCapability::all()`.
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
// `wprism-reference-env-provider-plan/v1` document naming the external command
// boundary the action may use — without running any command, touching the
// pair, or writing provider state. Acquisition/reap plans consult provider
// state and distinguish fresh, retry and receipt-replay paths; other
// state-dependent retry suppression remains explicitly marked. It exists so
// the provider's
// negotiation and validation paths are covered by an offline suite
// (sandbox/tests/offline/assess-contract/regress_rehearse_provider.sh) on a machine with no docker at
// all. The flag is never passed by CommandEnvironmentProvider: the argv it is
// configured with is the two-token form above.
//
// The plan document is deliberately NOT a provider response. It carries its
// own format id and `executed: false`, so no consumer can mistake a dry run
// for evidence that anything happened.
//
// CONFIG (`wprism-reference-env-provider-config/v1`)
// ----------------------------------------------
// {
//   "format": "wprism-reference-env-provider-config/v1",
//   "pair": "mup",                          // sandbox/bin/pair.sh pair name
//   "pair_script": "/abs/sandbox/bin/pair.sh",
//   "compose_dir": "/abs/sandbox",          // cwd for docker compose (loads .env)
//   "compose_files": ["/abs/sandbox/pair.yml", "/abs/sandbox/pair.http.yml"],
//   "controller_repo": "/abs/origin.git",   // clone source for repository.materialize
//   "db_container": "wprism-shared-db",        // sandbox/db.yml's container_name
//   "state_root": "/abs/sandbox/tmp/reference-env-provider/mup",
//   "source_environment": "mup1",
//   "destroy_scope": "side",                // the only safe scope for one target lease
//   "withheld_capabilities": [],            // dev-only; see above
//   "environments": {
//     "mup1": {"role":"source","side":1,"port":8181,
//              "container":"wprism-mup-wp1-1","service":"cli1",
//              "database":"wp_mup1","repo":"/abs/sandbox/siterepo/mup1"},
//     "mup2": {"role":"target","side":2,"port":8182, ...}
//   }
//   // Optional `contained_preview` is the complete object generated in
//   // docs/branch-environment-provider.md §7. It changes the target to the
//   // standalone sandbox/contained-preview.yml project. Before constructing
//   // DockerTransport, make state_root a current-uid-owned, non-symlink 0700
//   // directory and create `<state_root>/contained-preview.env` mode 0600;
//   // create atomically replaces that placeholder with per-lease credentials.
//   // Contained mode also requires the hash-pinned machine-local exhaustive
//   // sanitization policy described in that guide; unsupported locators refuse.
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
function ref_run(array $argv, ?string $stdin = null, ?string $cwd = null, ?array $env = null): array {
    $pipes = [];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $stateLock = $GLOBALS['wprism_reference_provider_state_lock'] ?? null;
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
        $env,
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
                // A child that stops reading closes the read end, and the next
                // write(2) fails with EPIPE (errno=32) — `docker exec -i …
                // mariadb` that rejects the first statement and exits, or any
                // child that never reads its stdin at all. Whether that beats
                // this parent's write is pure scheduling: issue #3492 saw
                // `create` refuse with "could not send reference provider
                // command input" on a loaded host while the same call had
                // succeeded on an idle one, 3 failures in 32 concurrent runs.
                // The child's own exit status and stderr are the verdict —
                // that is what ref_checked() reports (:259-263) and what the
                // readback compare in ref_restore_database() (:869-873) binds
                // the restore's effect to — so stop feeding a reader that is
                // gone and let the child answer. `@` suppresses only PHP's
                // "Write of N bytes failed with errno=32" notice — output
                // hygiene, not gate evasion: the false return already carries
                // the fact this branch handles, and the offline guard polices
                // warnings and fatals, not notices
                // (sandbox/tests/offline_diagnostics_guard.sh:28-29).
                $written = @fwrite($pipe, substr($input, $inputOffset, 65536));
                if ($written === false) {
                    fclose($pipes[0]);
                    $open[0] = false;
                    break;
                }
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
function ref_checked(array $argv, ?string $stdin = null, ?string $cwd = null, ?array $env = null): string {
    $result = ref_run($argv, $stdin, $cwd, $env);
    if ($result['exit'] !== 0) {
        throw new RuntimeException(
            'reference provider command failed: ' . implode(' ', $argv) . ' :: ' . trim($result['stderr'])
        );
    }
    return $result['stdout'];
}

function ref_remove_tree(string $path): void {
    if (is_link($path) || is_file($path)) {
        if (!is_link($path)) @chmod($path, 0600);
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
    // PHP chmod follows links. Never change a path until lstat-equivalent
    // is_link has proved it is not a link, including nested state injected
    // after the provider's earlier shape validation.
    if (is_link($path) || !file_exists($path)) return;
    @chmod($path, $mode);
    if (!is_dir($path)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        if (!$item->isLink()) @chmod($item->getPathname(), $mode);
    }
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
 * Every capability id either explicit mode can honestly serve.
 *
 * Ordinary pair mode removes containment. Contained-preview mode removes
 * attach/detach because controls cannot be established before an existing
 * target boot. Every id is a member of
 * \WPrism\Orchestrator\EnvironmentProviderCapability::all(); an id outside that
 * set makes EnvironmentProviderCapabilityReport's constructor refuse.
 *
 * @return list<string>
 */
function ref_all_capabilities(): array {
    return [
        'environment.attach', 'environment.create', 'environment.destroy', 'environment.detach',
        'environment.inspect', 'environment.containment.verify', 'environment.mutation.acquire',
        'environment.mutation.read', 'environment.mutation.release', 'environment.ttl', 'environment.ttl.read',
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
        'containment-verify' => 'environment.containment.verify',
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
        'url-set', 'mutation-acquire', 'mutation-read', 'mutation-release', 'containment-verify',
        'ttl-set', 'ttl-read',
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
    $available = ref_all_capabilities();
    if (!ref_containment_enabled($config)) {
        $available = array_values(array_diff($available, ['environment.containment.verify']));
    } else {
        // A contained slot is born under its controls. Retrofitting an
        // already-running side cannot establish that history, so this mode
        // intentionally has no attach/detach claim.
        $available = array_values(array_diff($available, ['environment.attach', 'environment.detach']));
    }
    $advertised = array_values(array_diff($available, $withheld));
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

/** @param list<string> $expected */
function ref_assert_key_set(array $value, array $expected, string $label): void {
    $actual = array_keys($value);
    sort($actual, SORT_STRING);
    sort($expected, SORT_STRING);
    ref_require($actual === $expected, "$label has a noncanonical key set");
}

function ref_effective_uid(): int {
    ref_require(function_exists('posix_geteuid'), 'contained-preview ownership checks require posix_geteuid');
    $uid = posix_geteuid();
    ref_require(is_int($uid) && $uid >= 0, 'could not read the contained-preview provider uid');
    return $uid;
}

function ref_assert_current_owner(string $path, string $label): void {
    $owner = fileowner($path);
    ref_require(is_int($owner) && $owner === ref_effective_uid(), "$label is not owned by the current provider uid");
}

function ref_assert_contained_state_root(string $requested, string $resolved): void {
    ref_require(!is_link($requested), 'contained-preview state_root must not be a symlink');
    ref_require(is_dir($requested) && realpath($requested) === $resolved, 'contained-preview state_root is unavailable');
    ref_assert_current_owner($resolved, 'contained-preview state_root');
    ref_require((fileperms($resolved) & 0777) === 0700, 'contained-preview state_root must be mode 0700');
}

/** @return array<string,mixed> */
function ref_load_sanitization_policy(string $requestedPath, string $resolvedPath, string $expectedSha, string $sourceEnvironment): array {
    ref_require(!is_link($requestedPath), 'contained-preview sanitization policy must not be a symlink');
    ref_require(is_file($resolvedPath), 'contained-preview sanitization policy is unavailable');
    $actualSha = hash_file('sha256', $resolvedPath);
    ref_require(
        is_string($actualSha) && preg_match('/^[a-f0-9]{64}$/D', $expectedSha) === 1
            && hash_equals($expectedSha, $actualSha),
        'contained-preview sanitization policy differs from its configured sha256 pin'
    );
    $policy = ref_json_file($resolvedPath, null);
    ref_require(is_array($policy) && !array_is_list($policy), 'contained-preview sanitization policy is malformed');
    ref_assert_key_set($policy, ['assertion', 'database', 'format', 'media', 'source_environment', 'wordpress_auth'], 'contained-preview sanitization policy');
    ref_require(
        ($policy['format'] ?? null) === 'wprism-reference-snapshot-sanitization-policy/v1',
        'contained-preview sanitization policy format is unsupported'
    );
    ref_require(($policy['source_environment'] ?? null) === $sourceEnvironment, 'contained-preview sanitization policy names another source environment');

    $assertion = $policy['assertion'] ?? null;
    ref_require(is_array($assertion) && !array_is_list($assertion), 'contained-preview sanitization assertion is malformed');
    ref_assert_key_set($assertion, ['credential_inventory', 'review_id', 'revision'], 'contained-preview sanitization assertion');
    ref_require(($assertion['credential_inventory'] ?? null) === 'exhaustive', 'contained-preview policy must assert an exhaustive reviewed credential inventory');
    ref_require(
        is_string($assertion['review_id'] ?? null)
            && preg_match('/^[A-Za-z0-9._:@+-]{8,128}$/D', $assertion['review_id']) === 1,
        'contained-preview sanitization review_id is invalid'
    );
    ref_require(is_int($assertion['revision'] ?? null) && $assertion['revision'] >= 1, 'contained-preview sanitization revision must be positive');

    $database = $policy['database'] ?? null;
    ref_require(is_array($database) && !array_is_list($database), 'contained-preview database sanitization policy is malformed');
    ref_assert_key_set($database, ['options', 'table_prefix'], 'contained-preview database sanitization policy');
    ref_require(($database['table_prefix'] ?? null) === 'wp_', 'reference contained-preview sanitizer supports only the reviewed wp_ table prefix');
    $options = $database['options'] ?? null;
    ref_require(is_array($options) && array_is_list($options), 'contained-preview policy wp_options credential locators must be a list');
    $optionNames = [];
    foreach ($options as $index => $option) {
        ref_require(is_array($option) && !array_is_list($option), "contained-preview option locator $index is malformed");
        ref_assert_key_set($option, ['action', 'name', 'replacement'], "contained-preview option locator $index");
        ref_require(($option['action'] ?? null) === 'replace', "contained-preview option locator $index has an unsupported action");
        ref_require(
            is_string($option['name'] ?? null) && preg_match('/^[A-Za-z0-9_.:-]{1,191}$/D', $option['name']) === 1,
            "contained-preview option locator $index has an invalid name"
        );
        ref_require(
            is_string($option['replacement'] ?? null)
                && preg_match('#^[A-Za-z0-9._:@+/=-]{1,256}$#D', $option['replacement']) === 1,
            "contained-preview option locator $index has an invalid sandbox replacement"
        );
        ref_require(
            str_contains(strtolower((string) $option['replacement']), 'sandbox')
                || str_contains(strtolower((string) $option['replacement']), 'disabled'),
            "contained-preview option locator $index replacement is not an explicit sandbox/disabled handle"
        );
        ref_require(!isset($optionNames[$option['name']]), "contained-preview option locator '{$option['name']}' is duplicated");
        $optionNames[$option['name']] = true;
    }

    $media = $policy['media'] ?? null;
    ref_require(is_array($media) && array_is_list($media), 'contained-preview policy media credential locators must be a list');
    $mediaPaths = [];
    foreach ($media as $index => $locator) {
        ref_require(is_array($locator) && !array_is_list($locator), "contained-preview media locator $index is malformed");
        ref_assert_key_set($locator, ['action', 'path'], "contained-preview media locator $index");
        ref_require(($locator['action'] ?? null) === 'remove', "contained-preview media locator $index has an unsupported action");
        $relative = $locator['path'] ?? null;
        ref_require(is_string($relative) && $relative !== '' && !str_starts_with($relative, '/'), "contained-preview media locator $index is not relative");
        $segments = explode('/', $relative);
        foreach ($segments as $segment) {
            ref_require($segment !== '' && $segment !== '.' && $segment !== '..', "contained-preview media locator $index is not normalized");
            ref_require(preg_match('/^[A-Za-z0-9._@+-]{1,255}$/D', $segment) === 1, "contained-preview media locator $index has an unsupported path segment");
        }
        ref_require(!isset($mediaPaths[$relative]), "contained-preview media locator '$relative' is duplicated");
        $mediaPaths[$relative] = true;
    }
    $wordpressAuth = $policy['wordpress_auth'] ?? null;
    ref_require(is_array($wordpressAuth) && !array_is_list($wordpressAuth), 'contained-preview WordPress auth policy is malformed');
    ref_assert_key_set(
        $wordpressAuth,
        ['activation_key_replacement', 'password_replacement', 'remove_usermeta_keys'],
        'contained-preview WordPress auth policy'
    );
    ref_require(
        is_string($wordpressAuth['password_replacement'] ?? null)
            && preg_match('/^[A-Za-z0-9!._:@+-]{8,128}$/D', $wordpressAuth['password_replacement']) === 1
            && (str_contains(strtolower($wordpressAuth['password_replacement']), 'sandbox')
                || str_contains(strtolower($wordpressAuth['password_replacement']), 'disabled')),
        'contained-preview WordPress password replacement must be an explicit sandbox/disabled non-password value'
    );
    ref_require($wordpressAuth['activation_key_replacement'] === '', 'contained-preview WordPress activation keys must be cleared');
    $removeKeys = $wordpressAuth['remove_usermeta_keys'] ?? null;
    ref_require(is_array($removeKeys) && array_is_list($removeKeys), 'contained-preview WordPress auth usermeta removals must be a list');
    sort($removeKeys, SORT_STRING);
    ref_require($removeKeys === ['_application_passwords', 'session_tokens'], 'contained-preview WordPress auth policy must remove sessions and application passwords');
    $policy['_sha256'] = $actualSha;
    return $policy;
}

/** @return array<string,mixed> */
function ref_config(string $path): array {
    $config = ref_json_file($path, null);
    ref_require(is_array($config) && !array_is_list($config), 'reference provider config is malformed');
    ref_require(
        ($config['format'] ?? null) === 'wprism-reference-env-provider-config/v1',
        'reference provider config format is not wprism-reference-env-provider-config/v1'
    );
    foreach (['pair', 'pair_script', 'compose_dir', 'controller_repo', 'db_container', 'state_root', 'source_environment'] as $key) {
        ref_require(
            is_string($config[$key] ?? null) && $config[$key] !== '',
            "reference provider config is missing '$key'"
        );
    }
    $requestedStateRoot = (string) $config['state_root'];
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
    $contained = $config['contained_preview'] ?? null;
    if ($contained !== null) {
        ref_require(is_array($contained) && !array_is_list($contained), 'contained_preview must be an object');
        ref_require(
            ($contained['format'] ?? null) === 'wprism-reference-contained-preview/v1',
            'contained_preview format is not wprism-reference-contained-preview/v1'
        );
        $requestedPolicyPath = $contained['sanitization_policy'] ?? null;
        foreach (['compose_file', 'cron_guard', 'mail_shim', 'php_ini', 'proxy_config', 'sanitization_policy'] as $pathKey) {
            ref_require(is_string($contained[$pathKey] ?? null) && $contained[$pathKey] !== '', "contained_preview is missing '$pathKey'");
            $contained[$pathKey] = ref_config_path((string) $contained[$pathKey], "contained_preview $pathKey");
        }
        $runtimeSources = $contained['runtime_sources'] ?? null;
        ref_require(is_array($runtimeSources) && !array_is_list($runtimeSources), 'contained_preview runtime_sources must be an object');
        $runtimeSourceKeys = array_keys($runtimeSources);
        sort($runtimeSourceKeys, SORT_STRING);
        ref_require(
            $runtimeSourceKeys === ['adapter_packages', 'agent', 'platform'],
            'contained_preview runtime_sources has a noncanonical key set'
        );
        foreach ($runtimeSources as $key => $source) {
            ref_require(is_string($source) && $source !== '', "contained_preview runtime source '$key' is invalid");
            $runtimeSources[$key] = ref_config_path($source, "contained_preview runtime source '$key'");
            ref_require(is_dir($runtimeSources[$key]), "contained_preview runtime source '$key' is unavailable");
        }
        $contained['runtime_sources'] = $runtimeSources;
        $pair = (string) $config['pair'];
        $expected = [
            'cli_service' => 'cli',
            'database' => 'wprism_preview',
            'database_container' => "wprism-$pair-preview-db-1",
            'database_service' => 'db',
            'ingress_network' => "wprism-$pair-preview-ingress",
            'network' => "wprism-$pair-preview-internal",
            'project' => "wprism-$pair-preview",
            'proxy_container' => "wprism-$pair-preview-proxy-1",
            'proxy_service' => 'proxy',
            'wordpress_service' => 'wp',
        ];
        foreach ($expected as $key => $value) {
            ref_require(($contained[$key] ?? null) === $value, "contained_preview '$key' must be '$value'");
        }
        foreach (['cli_image', 'database_image', 'proxy_image', 'wordpress_image'] as $imageKey) {
            ref_require(
                is_string($contained[$imageKey] ?? null) && preg_match('/^[A-Za-z0-9._:@+\/-]{3,256}$/D', $contained[$imageKey]) === 1,
                "contained_preview '$imageKey' is invalid"
            );
        }
        ref_require(is_file($contained['compose_file']), 'contained_preview compose file is unavailable');
        ref_require(is_file($contained['cron_guard']), 'contained_preview cron guard is unavailable');
        ref_require(is_file($contained['mail_shim']) && is_executable($contained['mail_shim']), 'contained_preview mail shim must be executable');
        ref_require(is_file($contained['php_ini']), 'contained_preview PHP configuration is unavailable');
        ref_require(is_string($requestedPolicyPath), 'contained_preview sanitization policy path is invalid');
        ref_require(is_string($contained['sanitization_policy_sha256'] ?? null), 'contained_preview sanitization policy sha256 pin is absent');
        $contained['_sanitization_policy'] = ref_load_sanitization_policy(
            $requestedPolicyPath,
            (string) $contained['sanitization_policy'],
            (string) $contained['sanitization_policy_sha256'],
            (string) $config['source_environment']
        );
        ref_assert_contained_state_root($requestedStateRoot, (string) $config['state_root']);
        $config['contained_preview'] = $contained;
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
        $containedTarget = $environment['role'] === 'target' && $contained !== null;
        $expectedContainer = $containedTarget ? "wprism-$pair-preview-wp-1" : "wprism-$pair-wp$side-1";
        $expectedService = $containedTarget ? 'cli' : "cli$side";
        $expectedDatabase = $containedTarget ? 'wprism_preview' : "wp_$pair$side";
        $topologyLabel = $containedTarget ? 'configured contained-preview topology' : 'pair side';
        ref_require($environment['container'] === $expectedContainer, "environment '$name' container does not belong to its $topologyLabel");
        ref_require($environment['service'] === $expectedService, "environment '$name' service does not belong to its $topologyLabel");
        ref_require($environment['database'] === $expectedDatabase, "environment '$name' database does not belong to its $topologyLabel");
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
    ref_require($config['db_container'] === 'wprism-shared-db', 'reference provider db_container must name sandbox/db.yml\'s canonical database container');
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
    if ($contained !== null) {
        foreach ([
            $contained['compose_file'], $contained['cron_guard'], $contained['mail_shim'], $contained['php_ini'], $contained['proxy_config'],
            $contained['sanitization_policy'],
            ...array_values($contained['runtime_sources']),
        ] as $protectedPath) {
            ref_require(!ref_target_contains_path((string) $target['repo'], (string) $protectedPath), "reference provider target repo contains contained-preview authority '$protectedPath'");
        }
    }
    $config['environments'] = $environments;
    return $config;
}

/** @param array<string,mixed> $config */
function ref_containment_enabled(array $config): bool {
    return is_array($config['contained_preview'] ?? null);
}

/** @param array<string,mixed> $config @return array<string,mixed> */
function ref_target_environment(array $config): array {
    foreach ($config['environments'] as $environment) {
        if (is_array($environment) && ($environment['role'] ?? null) === 'target') return $environment;
    }
    throw new RuntimeException('reference provider config has no target environment');
}

/** @param array<string,mixed> $config @param array<string,mixed> $environment */
function ref_resource_id(array $config, array $environment): string {
    if (($environment['role'] ?? null) === 'target' && ref_containment_enabled($config)) {
        return 'wprism-' . (string) $config['pair'] . '-contained-preview';
    }
    return 'wprism-' . (string) $config['pair'] . '-wp' . (int) $environment['side'];
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
    $containedIdentity = null;
    if (ref_containment_enabled($config)) {
        $containedIdentity = ref_contained_config($config);
        foreach (['compose_file', 'cron_guard', 'mail_shim', 'php_ini', 'proxy_config', 'sanitization_policy'] as $key) {
            $digest = hash_file('sha256', (string) $containedIdentity[$key]);
            ref_require(is_string($digest), "could not hash contained-preview '$key'");
            $containedIdentity[$key . '_sha256'] = $digest;
        }
    }
    return ref_hash([
        'compose_dir' => $config['compose_dir'],
        'compose_files' => $config['compose_files'],
        'controller_repo' => $config['controller_repo'],
        'contained_preview' => $containedIdentity,
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
        'sanitization_policy_sha256' => ref_containment_enabled($config)
            ? ref_contained_config($config)['sanitization_policy_sha256']
            : null,
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
    $environmentIdentity = 'wprism-pair-' . $pair . '-side-' . $side;
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
    if (ref_containment_enabled($config)) {
        if ($resource['state'] === 'absent') {
            ref_require(!array_key_exists('contained_runtime', $resource), 'absent contained preview retains lease credentials');
        } else {
            ref_contained_runtime($config, $resource);
        }
        if (array_key_exists('initial_containment_topology_sha256', $resource)) {
            ref_require(
                is_string($resource['initial_containment_topology_sha256'])
                    && preg_match('/^[a-f0-9]{64}$/D', $resource['initial_containment_topology_sha256']) === 1,
                'contained preview initial topology receipt is malformed'
            );
        }
    }
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
function ref_discover_url(array $config, array $environment, bool $planOnly): string {
    $port = (int) $environment['port'];
    if ($planOnly) return 'http://127.0.0.1:' . $port;
    $mapped = trim(ref_checked(ref_port_command($config, $environment)));
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
function ref_port_command(array $config, array $environment): array {
    if (($environment['role'] ?? null) === 'target' && ref_containment_enabled($config)) {
        return ['docker', 'port', (string) ref_contained_config($config)['proxy_container'], '8080/tcp'];
    }
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
/**
 * The environment a compose invocation needs on top of the caller's own.
 *
 * pair.yml interpolates ${WPRISM_PAIR} into the cli services' WORDPRESS_DB_NAME
 * and pair.http.yml interpolates ${WPRISM_PORT1}/${WPRISM_PORT2} into port mappings;
 * pair.sh exports all three for its own compose calls (sandbox/lib/
 * pair_compose.sh:62 notes callers must re-export them). This provider is NOT
 * such a caller: it is spawned by the provider-check harness with whatever
 * environment the operator's shell had, so a bare compose run interpolated
 * blanks — compose warned 'The "WPRISM_PORT1" variable is not set' and the cli
 * container's database name collapsed to wp_2, and `wp option update` died
 * with 'Error establishing a database connection' (observed as the url-set
 * BLOCKED verdict in regress_env_provider_conformance_live.sh's first run).
 * The provider's config names the pair and both ports, so it supplies them.
 *
 * @param array<string,mixed> $config @return array<string,string>
 */
function ref_compose_environment(array $config): array {
    $env = [];
    foreach (getenv() as $key => $value) {
        if (is_string($value)) $env[(string) $key] = $value;
    }
    $env['WPRISM_PAIR'] = (string) $config['pair'];
    foreach ($config['environments'] as $environment) {
        if (($environment['side'] ?? null) === 1) $env['WPRISM_PORT1'] = (string) $environment['port'];
        if (($environment['side'] ?? null) === 2) $env['WPRISM_PORT2'] = (string) $environment['port'];
    }
    return $env;
}

function ref_compose_command(array $config, array $tail): array {
    $argv = ['docker', 'compose', '-p', 'wprism-' . (string) $config['pair']];
    foreach ($config['compose_files'] as $file) {
        $argv[] = '-f';
        $argv[] = (string) $file;
    }
    return array_merge($argv, $tail);
}

/** @param array<string,mixed> $config @return array<string,mixed> */
function ref_contained_config(array $config): array {
    $contained = $config['contained_preview'] ?? null;
    ref_require(is_array($contained) && !array_is_list($contained), 'contained-preview mode is not configured');
    return $contained;
}

/** @param array<string,mixed> $config @param array<string,mixed> $resource */
function ref_contained_runtime_root(array $config, array $resource): string {
    return (string) $config['state_root'] . '/contained/' . hash('sha256', ref_json([
        'generation' => $resource['generation'] ?? null,
        'operation_id' => $resource['operation_id'] ?? null,
        'resource_id' => ref_resource_id($config, ref_target_environment($config)),
    ]));
}

/**
 * @param array<string,mixed> $config
 * @param array<string,mixed> $resource
 * @return array<string,mixed>
 */
function ref_contained_new_runtime(array $config, array $resource): array {
    $token = bin2hex(random_bytes(16));
    return [
        'database_name' => (string) ref_contained_config($config)['database'],
        'database_password' => bin2hex(random_bytes(32)),
        'database_root_password' => bin2hex(random_bytes(32)),
        'database_user' => 'wprism_' . substr($token, 0, 16),
        'runtime_root' => ref_contained_runtime_root($config, $resource),
    ];
}

/**
 * @param array<string,mixed> $config
 * @param array<string,mixed> $resource
 * @return array<string,mixed>
 */
function ref_contained_runtime(array $config, array $resource): array {
    $runtime = $resource['contained_runtime'] ?? null;
    ref_require(is_array($runtime) && !array_is_list($runtime), 'contained preview has no lease-owned runtime authority');
    foreach (['database_name', 'database_password', 'database_root_password', 'database_user', 'runtime_root'] as $key) {
        ref_require(is_string($runtime[$key] ?? null) && $runtime[$key] !== '', "contained preview runtime is missing '$key'");
    }
    ref_require($runtime['database_name'] === ref_contained_config($config)['database'], 'contained preview database name changed');
    ref_require(preg_match('/^wprism_[a-f0-9]{16}$/D', $runtime['database_user']) === 1, 'contained preview database principal is malformed');
    foreach (['database_password', 'database_root_password'] as $key) {
        ref_require(preg_match('/^[a-f0-9]{64}$/D', $runtime[$key]) === 1, "contained preview '$key' is malformed");
    }
    ref_require($runtime['runtime_root'] === ref_contained_runtime_root($config, $resource), 'contained preview runtime path changed');
    return $runtime;
}

/** @param array<string,mixed> $config @param array<string,mixed> $resource @return array<string,string> */
function ref_contained_compose_environment(array $config, array $resource): array {
    $contained = ref_contained_config($config);
    $runtime = ref_contained_runtime($config, $resource);
    $env = ref_compose_environment($config);
    $env['WPRISM_PREVIEW_ADAPTER_PACKAGES_SRC'] = $runtime['runtime_root'] . '/adapter-packages';
    $env['WPRISM_PREVIEW_AGENT_SRC'] = $runtime['runtime_root'] . '/agent';
    $env['WPRISM_PREVIEW_CLI_IMAGE'] = (string) $contained['cli_image'];
    $env['WPRISM_PREVIEW_DB_IMAGE'] = (string) $contained['database_image'];
    $env['WPRISM_PREVIEW_DB_NAME'] = (string) $runtime['database_name'];
    $env['WPRISM_PREVIEW_DB_PASSWORD'] = (string) $runtime['database_password'];
    $env['WPRISM_PREVIEW_DB_ROOT_PASSWORD'] = (string) $runtime['database_root_password'];
    $env['WPRISM_PREVIEW_DB_USER'] = (string) $runtime['database_user'];
    $env['WPRISM_PREVIEW_CRON_GUARD'] = $runtime['runtime_root'] . '/block-cron.php';
    $env['WPRISM_PREVIEW_MAIL_SHIM'] = $runtime['runtime_root'] . '/refuse-sendmail.sh';
    $env['WPRISM_PREVIEW_PHP_INI'] = $runtime['runtime_root'] . '/php.ini';
    $env['WPRISM_PREVIEW_PLATFORM_SRC'] = $runtime['runtime_root'] . '/platform';
    $target = ref_target_environment($config);
    $env['WPRISM_PREVIEW_PORT'] = (string) $target['port'];
    $env['WPRISM_PREVIEW_REPO'] = (string) $target['repo'];
    $env['WPRISM_PREVIEW_PROXY_CONFIG'] = $runtime['runtime_root'] . '/nginx.conf';
    $env['WPRISM_PREVIEW_PROXY_IMAGE'] = (string) $contained['proxy_image'];
    $env['WPRISM_PREVIEW_WP_IMAGE'] = (string) $contained['wordpress_image'];
    return $env;
}

function ref_assert_tree_has_no_symlinks(string $root, string $label): void {
    ref_require(is_dir($root) && !is_link($root), "$label root is not a plain directory");
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        ref_require(!$item->isLink(), "$label contains unsupported symlink '{$item->getPathname()}'");
    }
}

function ref_private_tree(string $root, string $label): void {
    ref_require(is_dir($root) && !is_link($root), "$label root is not a plain directory");
    chmod($root, 0700);
    ref_assert_current_owner($root, $label);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        ref_require(!$item->isLink(), "$label contains unsupported symlink '$path'");
        chmod($path, $item->isDir() ? 0700 : 0600);
        ref_assert_current_owner($path, $label);
        ref_require(
            (fileperms($path) & 0777) === ($item->isDir() ? 0700 : 0600),
            "$label path '$path' is not private"
        );
    }
}

/** @param array<string,mixed> $config @return list<string> */
function ref_contained_compose_command(array $config, array $tail): array {
    $contained = ref_contained_config($config);
    return array_merge([
        'docker', 'compose', '-p', (string) $contained['project'],
        '-f', (string) $contained['compose_file'], '--profile', 'cli',
    ], $tail);
}

/** @param array<string,mixed> $config */
function ref_contained_driver_environment_path(array $config): string {
    return (string) $config['state_root'] . '/contained-preview.env';
}

/** @param array<string,mixed> $config @param array<string,mixed> $resource */
function ref_write_contained_driver_environment(array $config, array $resource): void {
    $rows = [];
    foreach (ref_contained_compose_environment($config, $resource) as $key => $value) {
        if ($key === 'WPRISM_PAIR' || str_starts_with($key, 'WPRISM_PREVIEW_')) {
            $rows[$key] = $key . '=' . json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
    }
    ksort($rows, SORT_STRING);
    ref_write_private_file(ref_contained_driver_environment_path($config), implode("\n", $rows) . "\n");
}

/** @param array<string,mixed> $config */
function ref_write_contained_driver_placeholder(array $config): void {
    ref_write_private_file(
        ref_contained_driver_environment_path($config),
        "# Lease absent. The provider atomically replaces this file during create.\n"
    );
}

function ref_write_private_file(string $path, string $bytes): void {
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
    $handle = fopen($tmp, 'x+b');
    if ($handle === false) throw new RuntimeException("could not create private staging file for '$path'");
    try {
        chmod($tmp, 0600);
        if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || !fsync($handle)) {
            throw new RuntimeException("could not durably stage private file '$path'");
        }
    } catch (Throwable $error) {
        fclose($handle);
        @unlink($tmp);
        throw $error;
    }
    fclose($handle);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("could not publish private file '$path'");
    }
    chmod($path, 0600);
    $readback = file_get_contents($path);
    ref_require(
        is_string($readback) && hash_equals(hash('sha256', $bytes), hash('sha256', $readback))
            && (fileperms($path) & 0777) === 0600,
        "private file '$path' failed exact readback"
    );
    $directory = fopen(dirname($path), 'r');
    if ($directory === false) throw new RuntimeException("could not open private file directory for '$path'");
    try {
        if (!fsync($directory)) throw new RuntimeException("could not durably publish private file '$path'");
    } finally {
        fclose($directory);
    }
}

/** @param array<string,mixed> $config @param array<string,mixed> $resource */
function ref_stage_contained_runtime(array $config, array $resource): void {
    $contained = ref_contained_config($config);
    $runtime = ref_contained_runtime($config, $resource);
    $root = (string) $runtime['runtime_root'];
    // Reject every source link before cp or chmod. Otherwise cp can preserve a
    // link and the final chmod walk can mutate authority outside the staged
    // tree through its referent.
    foreach ($contained['runtime_sources'] as $name => $source) {
        ref_assert_tree_has_no_symlinks((string) $source, "contained-preview runtime source '$name'");
    }
    ref_remove_tree($root);
    if (!mkdir($root, 0700, true)) throw new RuntimeException('could not create contained-preview runtime staging');
    foreach ($contained['runtime_sources'] as $name => $source) {
        $destination = $root . '/' . str_replace('_', '-', (string) $name);
        if (!mkdir($destination, 0700)) throw new RuntimeException("could not stage contained-preview runtime '$name'");
        ref_checked(['cp', '-R', rtrim((string) $source, '/') . '/.', $destination]);
    }
    foreach (['cron_guard' => 'block-cron.php', 'mail_shim' => 'refuse-sendmail.sh', 'php_ini' => 'php.ini', 'proxy_config' => 'nginx.conf'] as $key => $name) {
        ref_require(copy((string) $contained[$key], $root . '/' . $name), "could not stage contained-preview '$key'");
    }
    ref_assert_tree_has_no_symlinks($root, 'contained-preview staged runtime');
    ref_chmod_tree($root, 0555);
}

/** @param array<string,mixed> $config @param array<string,mixed> $resource */
function ref_assert_staged_contained_runtime(array $config, array $resource): void {
    $runtime = ref_contained_runtime($config, $resource);
    $root = (string) $runtime['runtime_root'];
    foreach (['adapter-packages', 'agent', 'platform'] as $directory) {
        ref_require(is_dir($root . '/' . $directory), "contained-preview staged '$directory' is absent");
    }
    ref_require(is_file($root . '/block-cron.php'), 'contained-preview staged cron guard is absent');
    ref_require(is_file($root . '/refuse-sendmail.sh') && is_executable($root . '/refuse-sendmail.sh'), 'contained-preview staged mail shim is absent');
    ref_require(is_file($root . '/php.ini'), 'contained-preview staged PHP configuration is absent');
    ref_require(is_file($root . '/nginx.conf'), 'contained-preview staged proxy configuration is absent');
}

/** @param mixed $value @return mixed */
function ref_redact_secrets(mixed $value): mixed {
    if (!is_array($value)) return $value;
    foreach ($value as $key => $child) {
        if (is_string($key) && preg_match('/(?:password|secret|token|credential)/i', $key) === 1) {
            $value[$key] = is_string($child) ? 'sha256:' . hash('sha256', $child) : '<redacted>';
        } else {
            $value[$key] = ref_redact_secrets($child);
        }
    }
    return $value;
}

/** @param list<mixed> $rows @return array<string,string> */
function ref_environment_map(array $rows): array {
    $result = [];
    foreach ($rows as $row) {
        ref_require(is_string($row) && str_contains($row, '='), 'container environment row is malformed');
        [$key, $value] = explode('=', $row, 2);
        ref_require($key !== '' && !array_key_exists($key, $result), 'container environment keys are malformed or duplicated');
        $result[$key] = $value;
    }
    ksort($result, SORT_STRING);
    return $result;
}

/** @return list<string> */
function ref_contained_allowed_environment(string $service): array {
    if ($service === 'db') {
        return [
            'GOSU_VERSION', 'LANG', 'MARIADB_DATABASE', 'MARIADB_PASSWORD', 'MARIADB_ROOT_PASSWORD',
            'MARIADB_USER', 'MARIADB_VERSION', 'PATH',
        ];
    }
    $keys = [
        'GIT_CONFIG_COUNT', 'GIT_CONFIG_KEY_0', 'GIT_CONFIG_VALUE_0', 'GPG_KEYS', 'PATH',
        'PHPIZE_DEPS', 'PHP_ASC_URL', 'PHP_CFLAGS', 'PHP_CPPFLAGS', 'PHP_INI_DIR',
        'PHP_LDFLAGS', 'PHP_SHA256', 'PHP_URL', 'PHP_VERSION', 'WORDPRESS_CONFIG_EXTRA',
        'WORDPRESS_DB_HOST', 'WORDPRESS_DB_NAME', 'WORDPRESS_DB_PASSWORD', 'WORDPRESS_DB_USER',
    ];
    if ($service === 'wp') $keys = array_merge($keys, ['APACHE_CONFDIR', 'APACHE_ENVVARS']);
    if ($service === 'cli') {
        $keys = array_merge($keys, [
            'HOME', 'HOSTNAME', 'PWD', 'SHLVL',
            'WORDPRESS_CLI_GPG_KEY', 'WORDPRESS_CLI_SHA512', 'WORDPRESS_CLI_VERSION',
        ]);
    }
    sort($keys, SORT_STRING);
    return $keys;
}

/**
 * @param array<string,string> $environment
 * @param array<string,mixed> $runtime
 * @return array{environment_keys:list<string>,environment_sha256:string}
 */
function ref_contained_environment_witness(string $service, array $environment, array $runtime): array {
    $keys = array_keys($environment);
    sort($keys, SORT_STRING);
    $allowed = ref_contained_allowed_environment($service);
    sort($allowed, SORT_STRING);
    ref_require(
        $keys === $allowed,
        "contained preview '$service' environment differs from its closed allowlist (observed "
            . ref_json($keys) . ', expected ' . ref_json($allowed) . ')'
    );
    $expected = $service === 'db' ? [
        'MARIADB_DATABASE' => $runtime['database_name'],
        'MARIADB_PASSWORD' => $runtime['database_password'],
        'MARIADB_ROOT_PASSWORD' => $runtime['database_root_password'],
        'MARIADB_USER' => $runtime['database_user'],
    ] : [
        'GIT_CONFIG_COUNT' => '1',
        'GIT_CONFIG_KEY_0' => 'safe.directory',
        'GIT_CONFIG_VALUE_0' => '/siterepo',
        'WORDPRESS_CONFIG_EXTRA' => "define('WP_ENVIRONMENT_TYPE', 'local');\ndefine('DISABLE_WP_CRON', true);\ndefine('AUTOMATIC_UPDATER_DISABLED', true);\n",
        'WORDPRESS_DB_HOST' => 'db',
        'WORDPRESS_DB_NAME' => $runtime['database_name'],
        'WORDPRESS_DB_PASSWORD' => $runtime['database_password'],
        'WORDPRESS_DB_USER' => $runtime['database_user'],
    ];
    foreach ($expected as $key => $value) {
        ref_require(($environment[$key] ?? null) === $value, "contained preview '$service' environment differs at '$key'");
    }
    if ($service === 'cli') {
        ref_require(
            is_string($environment['HOME'] ?? null)
                && preg_match('#^/(?:[A-Za-z0-9/._-]{1,255})?$#D', $environment['HOME']) === 1,
            'contained preview CLI HOME drifted (observed ' . ref_json($environment['HOME'] ?? null) . ')'
        );
        ref_require(($environment['PWD'] ?? null) === '/var/www/html', 'contained preview CLI PWD drifted');
        ref_require(($environment['SHLVL'] ?? null) === '1', 'contained preview CLI SHLVL drifted');
        ref_require(
            is_string($environment['HOSTNAME'] ?? null)
                && preg_match('/^[a-f0-9]{12,64}$/D', $environment['HOSTNAME']) === 1,
            'contained preview CLI HOSTNAME is not a Docker container identity'
        );
    }
    $redacted = $environment;
    foreach (['MARIADB_PASSWORD', 'MARIADB_ROOT_PASSWORD', 'WORDPRESS_DB_PASSWORD'] as $secret) {
        if (isset($redacted[$secret])) $redacted[$secret] = 'sha256:' . hash('sha256', $redacted[$secret]);
    }
    if ($service === 'cli') $redacted['HOSTNAME'] = '<one-off-container>';
    return ['environment_keys' => $keys, 'environment_sha256' => ref_hash($redacted)];
}

/** @param array<string,string> $environment @return array{environment_keys:list<string>,environment_sha256:string} */
function ref_contained_proxy_environment_witness(array $environment): array {
    $keys = array_keys($environment);
    sort($keys, SORT_STRING);
    $allowed = ['ACME_VERSION', 'DYNPKG_RELEASE', 'NGINX_VERSION', 'NJS_RELEASE', 'NJS_VERSION', 'PATH', 'PKG_RELEASE'];
    ref_require(
        $keys === $allowed,
        'contained preview proxy image environment differs from its credential-free allowlist (observed '
            . ref_json($keys) . ')'
    );
    foreach ($keys as $key) {
        ref_require(
            preg_match('/(?:credential|password|secret|token)/i', $key) !== 1,
            'contained preview proxy inherited a credential-shaped environment key'
        );
    }
    return ['environment_keys' => $keys, 'environment_sha256' => ref_hash($environment)];
}

/** @return array{no_default_route:bool,route_sha256:string} */
function ref_contained_route_witness(string $routes, string $service): array {
    $lines = preg_split('/\R/', trim($routes));
    ref_require(is_array($lines) && count($lines) >= 2, "contained preview '$service' route table is unavailable");
    foreach (array_slice($lines, 1) as $line) {
        $columns = preg_split('/\s+/', trim($line));
        if (is_array($columns) && ($columns[1] ?? null) === '00000000') {
            ref_require(false, "contained preview '$service' has a default network route");
        }
    }
    return ['no_default_route' => true, 'route_sha256' => hash('sha256', trim($routes))];
}

/** @param list<mixed> $mounts @return list<array{destination:string,rw:bool,source:string,type:string}> */
function ref_contained_mount_rows(array $mounts): array {
    $rows = [];
    foreach ($mounts as $mount) {
        ref_require(is_array($mount) && !array_is_list($mount), 'contained preview mount evidence is malformed');
        $type = (string) ($mount['Type'] ?? '');
        $source = $type === 'volume' && is_string($mount['Name'] ?? null) && $mount['Name'] !== ''
            ? $mount['Name']
            : (string) ($mount['Source'] ?? '');
        $rows[] = [
            'destination' => (string) ($mount['Destination'] ?? ''),
            'rw' => ($mount['RW'] ?? null) === true,
            'source' => $source,
            'type' => $type,
        ];
    }
    usort($rows, static fn (array $left, array $right): int => strcmp($left['destination'], $right['destination']));
    return $rows;
}

/** @return list<string> */
function ref_contained_capability_rows(mixed $capabilities): array {
    if (!is_array($capabilities)) return [];
    $rows = array_map(
        static fn (mixed $capability): string => preg_replace('/^CAP_/', '', strtoupper((string) $capability)) ?? '',
        $capabilities
    );
    sort($rows, SORT_STRING);
    return $rows;
}

function ref_contained_grant_database(string $database): string {
    // SHOW GRANTS quotes database patterns, so MariaDB escapes `_` and `%`
    // even inside backticks. Compare that server-rendered spelling exactly.
    return strtr($database, ['\\' => '\\\\', '_' => '\\_', '%' => '\\%']);
}

/** @param list<mixed> $mounts @return list<array{destination:string,rw:bool,source:string,type:string}> */
function ref_contained_rendered_mount_rows(array $mounts): array {
    $rows = [];
    foreach ($mounts as $mount) {
        ref_require(is_array($mount) && !array_is_list($mount), 'contained preview rendered mount is malformed');
        $rows[] = [
            'destination' => (string) ($mount['target'] ?? ''),
            'rw' => ($mount['read_only'] ?? false) !== true,
            'source' => (string) ($mount['source'] ?? ''),
            'type' => (string) ($mount['type'] ?? ''),
        ];
    }
    usort($rows, static fn (array $left, array $right): int => strcmp($left['destination'], $right['destination']));
    return $rows;
}

/**
 * Read the fully interpolated Compose model before any container starts. The
 * CLI is normally one-off, so this is its host-authority boundary: a live
 * in-process socket probe alone cannot reveal a host mount, host namespace,
 * added capability, or entrypoint that already acted before the probe.
 *
 * @param array<string,mixed> $config
 * @param array<string,mixed> $environment
 * @param array<string,mixed> $resource
 * @return array<string,mixed>
 */
function ref_probe_contained_rendered_model(array $config, array $environment, array $resource): array {
    $contained = ref_contained_config($config);
    $runtime = ref_contained_runtime($config, $resource);
    $renderedBytes = ref_checked(
        ref_contained_compose_command($config, ['config', '--format', 'json']),
        null,
        (string) $config['compose_dir'],
        ref_contained_compose_environment($config, $resource)
    );
    $rendered = json_decode($renderedBytes, true, 512, JSON_THROW_ON_ERROR);
    ref_require(is_array($rendered) && !array_is_list($rendered), 'contained preview rendered Compose model is malformed');
    $services = $rendered['services'] ?? null;
    ref_require(is_array($services) && !array_is_list($services), 'contained preview rendered services are malformed');
    $serviceKeys = array_keys($services);
    sort($serviceKeys, SORT_STRING);
    ref_require($serviceKeys === ['cli', 'db', 'proxy', 'wp'], 'contained preview Compose project has an undeclared worker or service');
    ref_require(
        ref_json($rendered['networks'] ?? null) === ref_json([
            'contained' => [
                'internal' => true,
                'ipam' => [],
                'name' => $contained['network'],
            ],
            'ingress' => [
                'driver' => 'bridge',
                'driver_opts' => [
                    'com.docker.network.bridge.enable_ip_masquerade' => 'false',
                    'com.docker.network.bridge.host_binding_ipv4' => '127.0.0.1',
                ],
                'ipam' => [],
                'name' => $contained['ingress_network'],
            ],
        ]),
        'contained preview rendered networks differ from its internal data and no-masquerade ingress bridges'
    );
    ref_require(
        ref_json($rendered['volumes'] ?? null) === ref_json([
            'database' => ['name' => $contained['project'] . '-database'],
            'wordpress' => ['name' => $contained['project'] . '-wordpress'],
        ]),
        'contained preview rendered volumes differ from its two lease-owned volumes'
    );

    $wordpressEnvironment = [
        'GIT_CONFIG_COUNT' => '1',
        'GIT_CONFIG_KEY_0' => 'safe.directory',
        'GIT_CONFIG_VALUE_0' => '/siterepo',
        'WORDPRESS_CONFIG_EXTRA' => "define('WP_ENVIRONMENT_TYPE', 'local');\ndefine('DISABLE_WP_CRON', true);\ndefine('AUTOMATIC_UPDATER_DISABLED', true);\n",
        'WORDPRESS_DB_HOST' => 'db',
        'WORDPRESS_DB_NAME' => $runtime['database_name'],
        'WORDPRESS_DB_PASSWORD' => $runtime['database_password'],
        'WORDPRESS_DB_USER' => $runtime['database_user'],
    ];
    $expectedEnvironment = [
        'cli' => $wordpressEnvironment,
        'db' => [
            'MARIADB_DATABASE' => $runtime['database_name'],
            'MARIADB_PASSWORD' => $runtime['database_password'],
            'MARIADB_ROOT_PASSWORD' => $runtime['database_root_password'],
            'MARIADB_USER' => $runtime['database_user'],
        ],
        'proxy' => null,
        'wp' => $wordpressEnvironment,
    ];
    $expectedImages = [
        'cli' => $contained['cli_image'],
        'db' => $contained['database_image'],
        'proxy' => $contained['proxy_image'],
        'wp' => $contained['wordpress_image'],
    ];
    $runtimeRoot = (string) $runtime['runtime_root'];
    $wordpressMounts = [
        ['destination' => '/siterepo', 'rw' => true, 'source' => (string) $environment['repo'], 'type' => 'bind'],
        ['destination' => '/usr/local/bin/wprism-refuse-sendmail', 'rw' => false, 'source' => $runtimeRoot . '/refuse-sendmail.sh', 'type' => 'bind'],
        ['destination' => '/usr/local/etc/php/conf.d/zz-wprism-containment.ini', 'rw' => false, 'source' => $runtimeRoot . '/php.ini', 'type' => 'bind'],
        ['destination' => '/var/www/html', 'rw' => true, 'source' => 'wordpress', 'type' => 'volume'],
        ['destination' => '/var/www/html/wp-content/mu-plugins/adapter-packages', 'rw' => false, 'source' => $runtimeRoot . '/adapter-packages', 'type' => 'bind'],
        ['destination' => '/var/www/html/wp-content/mu-plugins/00-wprism-containment-cron-guard.php', 'rw' => false, 'source' => $runtimeRoot . '/block-cron.php', 'type' => 'bind'],
        ['destination' => '/var/www/html/wp-content/mu-plugins/platform', 'rw' => false, 'source' => $runtimeRoot . '/platform', 'type' => 'bind'],
        ['destination' => '/var/www/html/wp-content/mu-plugins/wprism', 'rw' => false, 'source' => $runtimeRoot . '/agent', 'type' => 'bind'],
        ['destination' => '/var/www/html/wp-content/mu-plugins/wprism-loader.php', 'rw' => false, 'source' => $runtimeRoot . '/agent/wprism-loader.php', 'type' => 'bind'],
    ];
    usort($wordpressMounts, static fn (array $left, array $right): int => strcmp($left['destination'], $right['destination']));

    foreach (['cli', 'db', 'proxy', 'wp'] as $service) {
        $model = $services[$service];
        ref_require(is_array($model) && !array_is_list($model), "contained preview rendered '$service' service is malformed");
        $networks = array_keys(is_array($model['networks'] ?? null) ? $model['networks'] : []);
        $expectedNetworks = $service === 'proxy' ? ['contained', 'ingress'] : ['contained'];
        ref_require($networks === $expectedNetworks, "contained preview '$service' has an unexpected rendered network attachment");
        ref_require(ref_json($model['environment'] ?? null) === ref_json($expectedEnvironment[$service]), "contained preview rendered '$service' environment differs from its closed allowlist");
        ref_require(($model['image'] ?? null) === $expectedImages[$service], "contained preview rendered '$service' image drifted");
        if ($service === 'proxy') {
            ref_require(
                ($model['command'] ?? null) === []
                    && ($model['entrypoint'] ?? null) === ['nginx', '-g', 'daemon off;'],
                'contained preview rendered proxy has another process'
            );
        } else {
            ref_require(($model['command'] ?? null) === null && ($model['entrypoint'] ?? null) === null, "contained preview rendered '$service' overrides its image process");
        }
        ref_require(($model['restart'] ?? null) === 'no', "contained preview rendered '$service' can restart as an ambient worker");
        ref_require(($model['privileged'] ?? false) === false, "contained preview rendered '$service' is privileged");
        ref_require(($model['cap_add'] ?? []) === [], "contained preview rendered '$service' adds Linux capabilities");
        $expectedCapDrop = $service === 'proxy' ? ['ALL'] : ['NET_RAW'];
        ref_require(ref_contained_capability_rows($model['cap_drop'] ?? []) === $expectedCapDrop, "contained preview rendered '$service' has another capability boundary");
        ref_require(($model['security_opt'] ?? null) === ['no-new-privileges:true'], "contained preview rendered '$service' lost no-new-privileges");
        foreach (['build', 'configs', 'credential_spec', 'devices', 'extra_hosts', 'ipc', 'pid', 'runtime', 'secrets', 'userns_mode', 'uts', 'volumes_from'] as $authorityKey) {
            ref_require(!array_key_exists($authorityKey, $model) || $model[$authorityKey] === null || $model[$authorityKey] === [], "contained preview rendered '$service' has forbidden '$authorityKey' authority");
        }
        if ($service === 'cli') {
            ref_require(($model['user'] ?? null) === '33:33', 'contained preview CLI does not run as uid/gid 33:33');
            ref_require(($model['profiles'] ?? null) === ['cli'], 'contained preview CLI is not explicitly profile-scoped');
        }
        if ($service === 'db') {
            $expectedMounts = [[
                'destination' => '/var/lib/mysql', 'rw' => true, 'source' => 'database', 'type' => 'volume',
            ]];
        } elseif ($service === 'proxy') {
            $expectedMounts = [[
                'destination' => '/etc/nginx/nginx.conf', 'rw' => false,
                'source' => $runtimeRoot . '/nginx.conf', 'type' => 'bind',
            ]];
            ref_require(($model['user'] ?? null) === '101:101', 'contained preview proxy does not run as uid/gid 101:101');
            ref_require(($model['read_only'] ?? null) === true, 'contained preview proxy root filesystem is writable');
            ref_require(($model['tmpfs'] ?? null) === ['/tmp:rw,noexec,nosuid,size=16m'], 'contained preview proxy has another writable tmpfs');
        } else {
            $expectedMounts = $wordpressMounts;
        }
        ref_require(
            ref_contained_rendered_mount_rows(is_array($model['volumes'] ?? null) ? $model['volumes'] : []) === $expectedMounts,
            "contained preview rendered '$service' mounts differ from its closed lease-owned set"
        );
        if ($service !== 'proxy') {
            ref_require(($model['ports'] ?? []) === [], "contained preview rendered '$service' publishes a host port");
        }
    }
    ref_require(($services['proxy']['ports'] ?? null) === [[
        'mode' => 'ingress',
        'host_ip' => '127.0.0.1',
        'target' => 8080,
        'published' => (string) $environment['port'],
        'protocol' => 'tcp',
    ]], 'contained preview rendered proxy ingress differs from its exact loopback port');

    return $rendered;
}

/**
 * @param array<string,mixed> $config
 * @param array<string,mixed> $environment
 * @param array<string,mixed> $resource
 * @return array<string,mixed>
 */
function ref_probe_contained_topology(array $config, array $environment, array $resource): array {
    $contained = ref_contained_config($config);
    $runtime = ref_contained_runtime($config, $resource);
    $composeEnv = ref_contained_compose_environment($config, $resource);
    $cwd = (string) $config['compose_dir'];
    $driverEnvironment = ref_contained_driver_environment_path($config);
    ref_require(is_file($driverEnvironment) && (fileperms($driverEnvironment) & 0777) === 0600, 'contained preview driver environment is absent or not mode 0600');
    $driverEnvironmentBytes = file_get_contents($driverEnvironment);
    ref_require(is_string($driverEnvironmentBytes) && str_contains($driverEnvironmentBytes, 'WPRISM_PREVIEW_DB_PASSWORD='), 'contained preview driver environment is not lease-bound');
    $rendered = ref_probe_contained_rendered_model($config, $environment, $resource);

    $containersBytes = ref_checked([
        'docker', 'inspect', (string) $environment['container'], (string) $contained['database_container'],
        (string) $contained['proxy_container'],
    ]);
    $containers = json_decode($containersBytes, true, 512, JSON_THROW_ON_ERROR);
    ref_require(is_array($containers) && array_is_list($containers) && count($containers) === 3, 'contained preview container inspection is incomplete');
    $byName = [];
    foreach ($containers as $container) {
        ref_require(is_array($container) && !array_is_list($container), 'contained preview container evidence is malformed');
        $byName[ltrim((string) ($container['Name'] ?? ''), '/')] = $container;
    }
    $securityWitness = [];
    foreach ([(string) $environment['container'] => 'wp', (string) $contained['database_container'] => 'db'] as $name => $service) {
        $container = $byName[$name] ?? null;
        ref_require(is_array($container), "contained preview '$service' container is absent");
        ref_require(($container['State']['Running'] ?? null) === true, "contained preview '$service' container is not running");
        if ($service === 'db') ref_require(($container['State']['Health']['Status'] ?? null) === 'healthy', 'contained preview database is not healthy');
        ref_require(($container['HostConfig']['Privileged'] ?? null) === false, "contained preview '$service' is privileged");
        ref_require(($container['HostConfig']['CapAdd'] ?? null) === null || ($container['HostConfig']['CapAdd'] ?? null) === [], "contained preview '$service' adds Linux capabilities");
        $capDrop = ref_contained_capability_rows($container['HostConfig']['CapDrop'] ?? []);
        ref_require(
            $capDrop === ['NET_RAW'],
            "contained preview '$service' regained NET_RAW (observed " . ref_json($capDrop) . ')'
        );
        $allowedNetworkModes = [$contained['network']];
        ref_require(in_array($container['HostConfig']['NetworkMode'] ?? null, $allowedNetworkModes, true), "contained preview '$service' network mode drifted");
        $security = $container['HostConfig']['SecurityOpt'] ?? [];
        ref_require(is_array($security) && in_array('no-new-privileges:true', $security, true), "contained preview '$service' lost no-new-privileges");
        $attached = array_keys(is_array($container['NetworkSettings']['Networks'] ?? null) ? $container['NetworkSettings']['Networks'] : []);
        sort($attached, SORT_STRING);
        $expectedAttached = [$contained['network']];
        sort($expectedAttached, SORT_STRING);
        ref_require($attached === $expectedAttached, "contained preview '$service' has an extra network attachment");
        ref_require(
            ($container['NetworkSettings']['Networks'][$contained['network']]['Gateway'] ?? null) === '',
            "contained preview '$service' internal attachment exposes a bridge gateway"
        );
        $securityWitness[$service] = [
            'cap_add' => [],
            'cap_drop' => $capDrop,
            'network_mode' => (string) $container['HostConfig']['NetworkMode'],
            'no_new_privileges' => true,
            'privileged' => false,
        ];
    }

    $wp = $byName[(string) $environment['container']];
    $db = $byName[(string) $contained['database_container']];
    $proxy = $byName[(string) $contained['proxy_container']] ?? null;
    ref_require(is_array($proxy) && ($proxy['State']['Running'] ?? null) === true, 'contained preview ingress proxy is absent');
    ref_require(($proxy['HostConfig']['Privileged'] ?? null) === false, 'contained preview ingress proxy is privileged');
    ref_require(($proxy['HostConfig']['CapAdd'] ?? null) === null || ($proxy['HostConfig']['CapAdd'] ?? null) === [], 'contained preview ingress proxy adds Linux capabilities');
    ref_require(ref_contained_capability_rows($proxy['HostConfig']['CapDrop'] ?? []) === ['ALL'], 'contained preview ingress proxy did not drop all Linux capabilities');
    ref_require(($proxy['HostConfig']['ReadonlyRootfs'] ?? null) === true, 'contained preview ingress proxy root filesystem is writable');
    ref_require(($proxy['Config']['User'] ?? null) === '101:101', 'contained preview ingress proxy is not uid/gid 101:101');
    ref_require(($proxy['HostConfig']['SecurityOpt'] ?? null) === ['no-new-privileges:true'], 'contained preview ingress proxy lost no-new-privileges');
    $proxyNetworks = array_keys(is_array($proxy['NetworkSettings']['Networks'] ?? null) ? $proxy['NetworkSettings']['Networks'] : []);
    sort($proxyNetworks, SORT_STRING);
    $expectedProxyNetworks = [$contained['ingress_network'], $contained['network']];
    sort($expectedProxyNetworks, SORT_STRING);
    ref_require($proxyNetworks === $expectedProxyNetworks, 'contained preview ingress proxy has another network attachment');
    $proxyEnv = ref_contained_proxy_environment_witness(ref_environment_map($proxy['Config']['Env'] ?? []));
    $observedProxyMounts = ref_contained_mount_rows($proxy['Mounts'] ?? []);
    $expectedProxyMounts = [[
        'destination' => '/etc/nginx/nginx.conf', 'rw' => false,
        'source' => $runtime['runtime_root'] . '/nginx.conf', 'type' => 'bind',
    ]];
    ref_require($observedProxyMounts === $expectedProxyMounts, 'contained preview ingress proxy mounts host authority');
    $securityWitness['proxy'] = [
        'cap_add' => [],
        'cap_drop' => ['ALL'],
        'network_mode' => (string) ($proxy['HostConfig']['NetworkMode'] ?? ''),
        'no_new_privileges' => true,
        'privileged' => false,
        'read_only_root' => true,
        'user' => '101:101',
    ];
    $wpEnv = ref_contained_environment_witness('wp', ref_environment_map($wp['Config']['Env'] ?? []), $runtime);
    $dbEnv = ref_contained_environment_witness('db', ref_environment_map($db['Config']['Env'] ?? []), $runtime);
    $expectedWpMounts = [
        ['destination' => '/siterepo', 'rw' => true, 'source' => (string) $environment['repo'], 'type' => 'bind'],
        ['destination' => '/usr/local/bin/wprism-refuse-sendmail', 'rw' => false, 'source' => $runtime['runtime_root'] . '/refuse-sendmail.sh', 'type' => 'bind'],
        ['destination' => '/usr/local/etc/php/conf.d/zz-wprism-containment.ini', 'rw' => false, 'source' => $runtime['runtime_root'] . '/php.ini', 'type' => 'bind'],
        ['destination' => '/var/www/html', 'rw' => true, 'source' => (string) $contained['project'] . '-wordpress', 'type' => 'volume'],
        ['destination' => '/var/www/html/wp-content/mu-plugins/adapter-packages', 'rw' => false, 'source' => $runtime['runtime_root'] . '/adapter-packages', 'type' => 'bind'],
        ['destination' => '/var/www/html/wp-content/mu-plugins/00-wprism-containment-cron-guard.php', 'rw' => false, 'source' => $runtime['runtime_root'] . '/block-cron.php', 'type' => 'bind'],
        ['destination' => '/var/www/html/wp-content/mu-plugins/platform', 'rw' => false, 'source' => $runtime['runtime_root'] . '/platform', 'type' => 'bind'],
        ['destination' => '/var/www/html/wp-content/mu-plugins/wprism', 'rw' => false, 'source' => $runtime['runtime_root'] . '/agent', 'type' => 'bind'],
        ['destination' => '/var/www/html/wp-content/mu-plugins/wprism-loader.php', 'rw' => false, 'source' => $runtime['runtime_root'] . '/agent/wprism-loader.php', 'type' => 'bind'],
    ];
    usort($expectedWpMounts, static fn (array $left, array $right): int => strcmp($left['destination'], $right['destination']));
    $observedWpMounts = ref_contained_mount_rows($wp['Mounts'] ?? []);
    ref_require(
        $observedWpMounts === $expectedWpMounts,
        'contained preview WordPress mounts differ from the closed lease-owned set (observed '
            . ref_json($observedWpMounts) . ', expected ' . ref_json($expectedWpMounts) . ')'
    );
    $expectedDbMounts = [[
        'destination' => '/var/lib/mysql', 'rw' => true,
        'source' => (string) $contained['project'] . '-database', 'type' => 'volume',
    ]];
    $observedDbMounts = ref_contained_mount_rows($db['Mounts'] ?? []);
    ref_require(
        $observedDbMounts === $expectedDbMounts,
        'contained preview database mounts differ from its lease-owned volume (observed '
            . ref_json($observedDbMounts) . ', expected ' . ref_json($expectedDbMounts) . ')'
    );

    foreach (($wp['HostConfig']['PortBindings'] ?? []) as $bindings) {
        ref_require($bindings === null || $bindings === [], 'contained preview WordPress requests a host port');
    }
    $ports = $proxy['HostConfig']['PortBindings']['8080/tcp'] ?? null;
    ref_require(
        is_array($ports) && count($ports) === 1
            && ($ports[0]['HostIp'] ?? null) === '127.0.0.1'
            && ($ports[0]['HostPort'] ?? null) === (string) $environment['port'],
        'contained preview web ingress is not bound to its exact loopback port (observed '
            . ref_json($ports) . ')'
    );
    $publishedPort = trim(ref_checked(ref_port_command($config, $environment)));
    ref_require(
        $publishedPort === '127.0.0.1:' . (string) $environment['port'],
        'contained preview Docker port readback differs from its exact loopback ingress'
    );
    foreach (($db['HostConfig']['PortBindings'] ?? []) as $bindings) {
        ref_require($bindings === null || $bindings === [], 'contained preview database requests a host port');
    }
    foreach (($db['NetworkSettings']['Ports'] ?? []) as $bindings) {
        ref_require($bindings === null || $bindings === [], 'contained preview database publishes a host port');
    }

    $networkBytes = ref_checked(['docker', 'network', 'inspect', (string) $contained['network']]);
    $networks = json_decode($networkBytes, true, 512, JSON_THROW_ON_ERROR);
    $network = is_array($networks) && array_is_list($networks) ? ($networks[0] ?? null) : null;
    ref_require(is_array($network) && ($network['Internal'] ?? null) === true, 'contained preview live network is not internal');
    ref_require(($network['Name'] ?? null) === $contained['network'], 'contained preview live network has another name');
    $attachedIds = array_keys(is_array($network['Containers'] ?? null) ? $network['Containers'] : []);
    sort($attachedIds, SORT_STRING);
    $expectedIds = [(string) $wp['Id'], (string) $db['Id']];
    $expectedIds[] = (string) $proxy['Id'];
    sort($expectedIds, SORT_STRING);
    ref_require($attachedIds === $expectedIds, 'contained preview internal network has a foreign attachment');
    $ingressRows = json_decode(
        ref_checked(['docker', 'network', 'inspect', (string) $contained['ingress_network']]),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $ingress = is_array($ingressRows) && array_is_list($ingressRows) ? ($ingressRows[0] ?? null) : null;
    ref_require(
        is_array($ingress)
            && ($ingress['Internal'] ?? null) === false
            && ($ingress['Name'] ?? null) === $contained['ingress_network']
            && ($ingress['Driver'] ?? null) === 'bridge'
            && ($ingress['Options']['com.docker.network.bridge.enable_ip_masquerade'] ?? null) === 'false'
            && ($ingress['Options']['com.docker.network.bridge.host_binding_ipv4'] ?? null) === '127.0.0.1',
        'contained preview ingress network lost its loopback/no-masquerade boundary'
    );
    $ingressIds = array_keys(is_array($ingress['Containers'] ?? null) ? $ingress['Containers'] : []);
    ref_require($ingressIds === [(string) $proxy['Id']], 'contained preview ingress network has a foreign attachment');

    $projectContainers = trim(ref_checked([
        'docker', 'ps', '--filter', 'label=com.docker.compose.project=' . (string) $contained['project'],
        '--format', '{{.Names}}',
    ]));
    $runningNames = $projectContainers === '' ? [] : preg_split('/\R/', $projectContainers);
    if (!is_array($runningNames)) $runningNames = [];
    sort($runningNames, SORT_STRING);
    $expectedNames = [
        (string) $environment['container'], (string) $contained['database_container'],
        (string) $contained['proxy_container'],
    ];
    sort($expectedNames, SORT_STRING);
    ref_require($runningNames === $expectedNames, 'contained preview has an ambient worker or foreign running container');

    $grantBytes = ref_checked(
        ref_contained_compose_command($config, [
            'exec', '-T', (string) $contained['database_service'], 'sh', '-c',
            'MYSQL_PWD="$MARIADB_PASSWORD" exec mariadb -N -B --raw -u"$MARIADB_USER" -e "SHOW GRANTS FOR CURRENT_USER"',
        ]),
        null,
        $cwd,
        $composeEnv
    );
    ref_require(
        str_contains($grantBytes, 'ON `' . ref_contained_grant_database((string) $runtime['database_name']) . '`.*')
            && preg_match('/^GRANT (?!USAGE )[^\n]+ ON \*\.\*/m', $grantBytes) !== 1,
        'contained preview database principal is not scoped only to its lease database'
    );

    $cronGuardHash = hash_file('sha256', $runtime['runtime_root'] . '/block-cron.php');
    $mailShimHash = hash_file('sha256', $runtime['runtime_root'] . '/refuse-sendmail.sh');
    $phpIniHash = hash_file('sha256', $runtime['runtime_root'] . '/php.ini');
    $proxyConfigHash = hash_file('sha256', $runtime['runtime_root'] . '/nginx.conf');
    ref_require(
        is_string($cronGuardHash) && is_string($mailShimHash) && is_string($phpIniHash) && is_string($proxyConfigHash),
        'contained preview control files cannot be hashed'
    );
    $observedHashes = trim(ref_checked([
        'docker', 'exec', (string) $environment['container'], 'sha256sum',
        '/usr/local/bin/wprism-refuse-sendmail', '/usr/local/etc/php/conf.d/zz-wprism-containment.ini',
        '/var/www/html/wp-content/mu-plugins/00-wprism-containment-cron-guard.php',
    ]));
    ref_require(
        str_contains($observedHashes, $cronGuardHash)
            && str_contains($observedHashes, $mailShimHash)
            && str_contains($observedHashes, $phpIniHash),
        'contained preview mail or cron controls differ inside WordPress'
    );
    $observedProxyConfig = trim(ref_checked([
        'docker', 'exec', (string) $contained['proxy_container'], 'sha256sum', '/etc/nginx/nginx.conf',
    ]));
    ref_require(str_contains($observedProxyConfig, $proxyConfigHash), 'contained preview ingress proxy configuration drifted');
    ref_checked([
        'docker', 'exec', (string) $environment['container'], 'php', '-r',
        'exit(ini_get("sendmail_path") === "/usr/local/bin/wprism-refuse-sendmail" ? 0 : 41);',
    ]);
    ref_checked([
        'docker', 'exec', (string) $environment['container'], 'php', '-r',
        'foreach(["tcp://1.1.1.1:443","tcp://host.docker.internal:443"] as $u){$s=@stream_socket_client($u,$e,$m,1);if(is_resource($s)){fclose($s);exit(42);}}exit(0);',
    ]);
    $wpRoutes = ref_checked(['docker', 'exec', (string) $environment['container'], 'cat', '/proc/net/route']);
    $wpRouteWitness = ref_contained_route_witness($wpRoutes, 'wordpress');
    ref_checked([
        'docker', 'exec', (string) $environment['container'], 'php', '-r',
        'exit(@mail("sink@example.invalid","containment probe","refuse") ? 43 : 0);',
    ]);
    $mailCapture = trim(ref_checked([
        'docker', 'exec', (string) $environment['container'], 'sha256sum',
        '/tmp/wprism-mail-capture.ndjson',
    ]));
    ref_require(preg_match('/^[a-f0-9]{64}\s/', $mailCapture) === 1, 'contained preview mail refusal left no capture witness');
    $cliProbeBytes = ref_checked(
        ref_contained_compose_command($config, [
            'run', '--rm', '--no-deps', '-T', (string) $contained['cli_service'], 'php', '-r',
            'foreach(["tcp://1.1.1.1:443","tcp://host.docker.internal:443"] as $u){$s=@stream_socket_client($u,$n,$m,1);if(is_resource($s)){fclose($s);exit(44);}}'
                . 'if(@mail("sink@example.invalid","containment probe","refuse")){exit(45);}'
                . '$e=getenv();ksort($e,SORT_STRING);echo json_encode(["env"=>$e,"routes"=>file_get_contents("/proc/net/route"),"sendmail_path"=>ini_get("sendmail_path")]);',
        ]),
        null,
        $cwd,
        $composeEnv
    );
    $cliProbe = json_decode($cliProbeBytes, true, 512, JSON_THROW_ON_ERROR);
    ref_require(is_array($cliProbe) && ($cliProbe['sendmail_path'] ?? null) === '/usr/local/bin/wprism-refuse-sendmail', 'contained preview CLI lost its mail refusal control');
    $cliEnv = $cliProbe['env'] ?? null;
    ref_require(is_array($cliEnv) && !array_is_list($cliEnv), 'contained preview CLI environment probe is malformed');
    $cliWitness = ref_contained_environment_witness('cli', array_map('strval', $cliEnv), $runtime);
    $cliRouteWitness = ref_contained_route_witness((string) ($cliProbe['routes'] ?? ''), 'cli');

    return [
        'compose_model_sha256' => ref_hash(ref_redact_secrets($rendered)),
        'container_ids' => [
            'database' => (string) $db['Id'], 'proxy' => (string) $proxy['Id'],
            'wordpress' => (string) $wp['Id'],
        ],
        'database_authority' => [
            'database' => $runtime['database_name'],
            'grants_sha256' => hash('sha256', trim($grantBytes)),
            'principal_sha256' => hash('sha256', $runtime['database_user']),
        ],
        'driver_environment_sha256' => hash('sha256', $driverEnvironmentBytes),
        'environment' => [
            'cli' => $cliWitness, 'database' => $dbEnv, 'proxy' => $proxyEnv, 'wordpress' => $wpEnv,
        ],
        'format' => 'wprism-reference-contained-topology/v1',
        'image_ids' => [
            'database' => (string) $db['Image'], 'proxy' => (string) $proxy['Image'],
            'wordpress' => (string) $wp['Image'],
        ],
        'live_probes' => [
            'cli_http_egress_denied' => true,
            'cli_mail_refused' => true,
            'wordpress_http_egress_denied' => true,
            'wordpress_mail_refused' => true,
        ],
        'mail_capture_present' => true,
        'mail_refusal_sha256' => $mailShimHash,
        'cron_guard_sha256' => $cronGuardHash,
        'mounts' => [
            'database' => $observedDbMounts, 'proxy' => $observedProxyMounts,
            'wordpress' => $observedWpMounts,
        ],
        'network' => [
            'attached_container_ids' => $attachedIds,
            'id' => (string) ($network['Id'] ?? ''),
            'internal' => true,
            'name' => (string) $contained['network'],
        ],
        'loopback_ingress_network' => [
            'attached_container_ids' => $ingressIds,
            'id' => (string) ($ingress['Id'] ?? ''),
            'ip_masquerade' => false,
            'name' => (string) $contained['ingress_network'],
        ],
        'php_configuration_sha256' => $phpIniHash,
        'proxy_configuration_sha256' => $proxyConfigHash,
        'routes' => ['cli' => $cliRouteWitness, 'wordpress' => $wpRouteWitness],
        'runtime_sources' => [
            'adapter_packages_sha256' => ref_tree_hash($runtime['runtime_root'] . '/adapter-packages'),
            'agent_sha256' => ref_tree_hash($runtime['runtime_root'] . '/agent'),
            'platform_sha256' => ref_tree_hash($runtime['runtime_root'] . '/platform'),
        ],
        'security' => $securityWitness,
        'web_ingress' => '127.0.0.1:' . (string) $environment['port'],
        'wordpress_runtime' => [
            'automatic_updater' => false,
            'direct_wp_cron_guard' => true,
            'wp_cli_cron_event_run_guard' => true,
            'wp_cron' => false,
        ],
        'ingress_services' => ['proxy'],
        'worker_services' => [],
    ];
}

/**
 * A reaping retry may follow a lost `compose down` response. Docker list
 * commands distinguish a closed, exactly empty project from either a live
 * resource or an unavailable daemon; inspect's generic nonzero status cannot.
 *
 * @param array<string,mixed> $config
 */
function ref_contained_physical_absent(array $config): bool {
    $contained = ref_contained_config($config);
    $target = ref_target_environment($config);
    $probes = [
        [
            'docker', 'ps', '-a', '--filter',
            'label=com.docker.compose.project=' . (string) $contained['project'],
            '--format', '{{.ID}}',
        ],
        ['docker', 'ps', '-a', '--filter', 'name=^/' . (string) $target['container'] . '$', '--format', '{{.Names}}'],
        ['docker', 'ps', '-a', '--filter', 'name=^/' . (string) $contained['database_container'] . '$', '--format', '{{.Names}}'],
        ['docker', 'ps', '-a', '--filter', 'name=^/' . (string) $contained['proxy_container'] . '$', '--format', '{{.Names}}'],
        ['docker', 'network', 'ls', '--filter', 'name=^' . (string) $contained['network'] . '$', '--format', '{{.Name}}'],
        ['docker', 'network', 'ls', '--filter', 'name=^' . (string) $contained['ingress_network'] . '$', '--format', '{{.Name}}'],
        ['docker', 'volume', 'ls', '--filter', 'name=^' . (string) $contained['project'] . '-database$', '--format', '{{.Name}}'],
        ['docker', 'volume', 'ls', '--filter', 'name=^' . (string) $contained['project'] . '-wordpress$', '--format', '{{.Name}}'],
    ];
    foreach ($probes as $probe) {
        $result = ref_run($probe);
        ref_require($result['exit'] === 0, 'could not establish contained-preview physical absence');
        if (trim($result['stdout']) !== '') return false;
    }
    return true;
}

/** @param array<string,mixed> $config @param array<string,mixed> $resource */
function ref_assert_contained_absent(array $config, array $resource): void {
    ref_contained_runtime($config, $resource);
    ref_require(ref_contained_physical_absent($config), 'contained-preview supposedly absent lease retains physical authority');
}

/**
 * The untrusted WordPress image starts only after Docker has created the exact
 * internal network and attached the fresh lease database to nothing else.
 *
 * @param array<string,mixed> $config
 * @param array<string,mixed> $resource
 */
function ref_assert_contained_preboot_boundary(array $config, array $resource): void {
    $contained = ref_contained_config($config);
    $runtime = ref_contained_runtime($config, $resource);
    $composeEnv = ref_contained_compose_environment($config, $resource);
    $database = json_decode(
        ref_checked(['docker', 'inspect', (string) $contained['database_container']]),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $db = is_array($database) && array_is_list($database) ? ($database[0] ?? null) : null;
    ref_require(is_array($db) && ($db['State']['Running'] ?? null) === true, 'contained-preview database did not start');
    ref_require(($db['State']['Health']['Status'] ?? null) === 'healthy', 'contained-preview database is not healthy before WordPress boot');
    ref_require(($db['HostConfig']['NetworkMode'] ?? null) === $contained['network'], 'contained-preview database booted on another network');
    ref_require(($db['HostConfig']['Privileged'] ?? null) === false, 'contained-preview database booted privileged');
    ref_require(($db['HostConfig']['CapAdd'] ?? null) === null || ($db['HostConfig']['CapAdd'] ?? null) === [], 'contained-preview database added Linux capabilities before WordPress boot');
    ref_require(ref_contained_capability_rows($db['HostConfig']['CapDrop'] ?? []) === ['NET_RAW'], 'contained-preview database retained NET_RAW before WordPress boot');
    ref_require(
        ($db['HostConfig']['SecurityOpt'] ?? null) === ['no-new-privileges:true'],
        'contained-preview database lost no-new-privileges before WordPress boot'
    );
    ref_contained_environment_witness('db', ref_environment_map($db['Config']['Env'] ?? []), $runtime);
    $expectedDbMounts = [[
        'destination' => '/var/lib/mysql', 'rw' => true,
        'source' => (string) $contained['project'] . '-database', 'type' => 'volume',
    ]];
    ref_require(
        ref_contained_mount_rows($db['Mounts'] ?? []) === $expectedDbMounts,
        'contained-preview database did not boot on its one lease-owned volume'
    );
    foreach (($db['NetworkSettings']['Ports'] ?? []) as $bindings) {
        ref_require($bindings === null || $bindings === [], 'contained-preview database published a host port before WordPress boot');
    }
    $networkRows = json_decode(
        ref_checked(['docker', 'network', 'inspect', (string) $contained['network']]),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $network = is_array($networkRows) && array_is_list($networkRows) ? ($networkRows[0] ?? null) : null;
    ref_require(is_array($network) && ($network['Internal'] ?? null) === true, 'contained-preview network is not internal before WordPress boot');
    $attached = array_keys(is_array($network['Containers'] ?? null) ? $network['Containers'] : []);
    ref_require($attached === [(string) ($db['Id'] ?? '')], 'contained-preview network has a foreign attachment before WordPress boot');
    $projectContainers = trim(ref_checked([
        'docker', 'ps', '--filter', 'label=com.docker.compose.project=' . (string) $contained['project'],
        '--format', '{{.Names}}',
    ]));
    ref_require($projectContainers === $contained['database_container'], 'contained-preview project has a non-database worker before WordPress boot');
    $grantBytes = ref_checked(
        ref_contained_compose_command($config, [
            'exec', '-T', (string) $contained['database_service'], 'sh', '-c',
            'MYSQL_PWD="$MARIADB_PASSWORD" exec mariadb -N -B --raw -u"$MARIADB_USER" -e "SHOW GRANTS FOR CURRENT_USER"',
        ]),
        null,
        (string) $config['compose_dir'],
        $composeEnv
    );
    ref_require(
        str_contains($grantBytes, 'ON `' . ref_contained_grant_database((string) $runtime['database_name']) . '`.*')
            && preg_match('/^GRANT (?!USAGE )[^\n]+ ON \*\.\*/m', $grantBytes) !== 1,
        'contained-preview database principal is not lease-database-only before WordPress boot (observed '
            . ref_json(preg_split('/\R/', trim($grantBytes)) ?: []) . ')'
    );
}

/**
 * @param array<string,mixed> $config
 * @param array<string,mixed> $environment
 * @param array<string,mixed> $resource
 * @return array<string,mixed>
 */
function ref_create_contained_preview(array $config, array $environment, array $resource): array {
    $contained = ref_contained_config($config);
    $composeEnv = ref_contained_compose_environment($config, $resource);
    $cwd = (string) $config['compose_dir'];
    $physical = ref_run(ref_contained_compose_command($config, ['ps', '-aq']), null, $cwd, $composeEnv);
    ref_require($physical['exit'] === 0, 'could not inspect contained-preview acquisition state');
    if (trim($physical['stdout']) === '') {
        ref_stage_contained_runtime($config, $resource);
        ref_write_contained_driver_environment($config, $resource);
        ref_assert_contained_absent($config, $resource);
    } else {
        // Acquisition intent and the random lease credentials were durably
        // published before the first `up`. An exact retry reuses those bytes;
        // replacing a live bind tree would invalidate the running container.
        ref_assert_staged_contained_runtime($config, $resource);
    }
    // Close every service's namespace, mount, environment and network model
    // before starting even the database; the post-start probes below prove
    // that Docker instantiated this exact authority boundary.
    ref_probe_contained_rendered_model($config, $environment, $resource);
    ref_checked(
        ref_contained_compose_command($config, ['up', '-d', '--wait', (string) $contained['database_service']]),
        null,
        $cwd,
        $composeEnv
    );
    ref_assert_contained_preboot_boundary($config, $resource);
    ref_checked(
        ref_contained_compose_command($config, [
            'up', '-d', '--wait', (string) $contained['wordpress_service'], (string) $contained['proxy_service'],
        ]),
        null,
        $cwd,
        $composeEnv
    );
    return ref_probe_contained_topology($config, $environment, $resource);
}

/** @param array<string,mixed> $config @param array<string,mixed> $environment @param array<string,mixed> $resource */
function ref_destroy_contained_preview(array $config, array $environment, array $resource, bool $alreadyAbsent = false): void {
    $runtime = ref_contained_runtime($config, $resource);
    if (!$alreadyAbsent) {
        ref_checked(
            ref_contained_compose_command($config, ['down', '--volumes', '--remove-orphans']),
            null,
            (string) $config['compose_dir'],
            ref_contained_compose_environment($config, $resource)
        );
    }
    ref_assert_contained_absent($config, $resource);
    ref_remove_tree((string) $runtime['runtime_root']);
    ref_write_contained_driver_placeholder($config);
    $repo = (string) $environment['repo'];
    ref_remove_tree($repo);
    if (!mkdir($repo, 0777, true) && !is_dir($repo)) throw new RuntimeException('could not recreate the contained preview repository');
    chmod($repo, 0777);
}

/** @param array<string,mixed> $config @param array<string,mixed> $resource @return list<string> */
function ref_contained_database_command(array $config, array $resource, bool $dump = false, bool $selectDatabase = false): array {
    $contained = ref_contained_config($config);
    $program = $dump ? 'mariadb-dump' : 'mariadb';
    $script = 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec ' . $program . ' -uroot';
    if ($dump) $script .= ' --single-transaction --skip-comments --skip-dump-date --skip-extended-insert "$MARIADB_DATABASE"';
    if ($selectDatabase) $script .= ' "$MARIADB_DATABASE"';
    return ref_contained_compose_command($config, [
        'exec', '-T', (string) $contained['database_service'], 'sh', '-c', $script,
    ]);
}

/** @param array<string,mixed> $config @param array<string,mixed> $environment @param array<string,mixed> $resource */
function ref_restore_contained_database(array $config, array $environment, array $resource, string $dump): void {
    $runtime = ref_contained_runtime($config, $resource);
    $database = (string) $runtime['database_name'];
    $composeEnv = ref_contained_compose_environment($config, $resource);
    $cwd = (string) $config['compose_dir'];
    ref_checked(
        ref_contained_database_command($config, $resource),
        "DROP DATABASE IF EXISTS `$database`; CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n",
        $cwd,
        $composeEnv
    );
    ref_checked(ref_contained_database_command($config, $resource, false, true), $dump, $cwd, $composeEnv);
    if ($dump !== '') {
        $readback = ref_checked(ref_contained_database_command($config, $resource, true), null, $cwd, $composeEnv);
        ref_require(hash('sha256', $readback) === hash('sha256', $dump), 'contained-preview database restore readback differs from the immutable snapshot bytes');
    }
}

/**
 * @param array<string,mixed> $identity
 * @param array<string,mixed> $fence
 * @param array<string,mixed> $input
 * @param array<string,mixed> $sanitization
 * @param array<string,mixed> $topology
 * @return array<string,mixed>
 */
function ref_containment_preimage(array $identity, array $fence, array $input, array $sanitization, array $topology): array {
    return [
        'environment_identity' => $identity['environment_identity'],
        'format' => 'wprism-reference-containment-receipt/v1',
        'lease_generation' => $identity['lease_generation'],
        'lease_id' => $identity['lease_id'],
        'mutation_generation' => $fence['generation'],
        'mutation_id' => $fence['id'],
        'mutation_owner' => $fence['owner'],
        'mutation_receipt_sha256' => $fence['held_receipt'],
        'operation_id' => $input['_operation_id'],
        'ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
        'profile' => $input['profile'],
        'provider' => ['id' => 'wprism-reference-env-provider', 'protocol' => 1],
        'resource_id' => $identity['resource_id'],
        'snapshot_sanitization' => $sanitization,
        'topology' => $topology,
    ];
}

/** @param array<string,mixed> $state @param array<string,mixed> $config @param array<string,mixed> $input @return array<string,mixed> */
function ref_containment_sanitization_evidence(array $state, array $config, string $operation, array $input): array {
    $key = ref_snapshot_key((string) $config['source_environment'], $operation);
    $snapshot = $state['snapshots'][$key] ?? null;
    $policy = ref_contained_config($config)['_sanitization_policy'];
    ref_require(is_array($policy), 'contained-preview sanitization policy is unavailable');
    if (!is_array($snapshot)) {
        ref_require(($input['topology_only'] ?? null) === true, 'containment verification requires this operation\'s sanitized immutable snapshot');
        return [
            'admission' => 'no-production-bytes',
            'format' => 'wprism-reference-containment-sanitization/v1',
            'policy_sha256' => $policy['_sha256'],
            'snapshot_set_id' => null,
        ];
    }
    ref_require(!array_key_exists('topology_only', $input), 'topology-only containment cannot be used when an operation snapshot exists');
    $sanitization = $snapshot['sanitization'] ?? null;
    ref_require(is_array($sanitization) && !array_is_list($sanitization), 'contained snapshot has no sanitization receipt');
    ref_require(
        ($sanitization['policy_sha256'] ?? null) === $policy['_sha256']
            && is_array($sanitization['preimage'] ?? null)
            && ref_hash($sanitization['preimage']) === ($sanitization['receipt_sha256'] ?? null),
        'contained snapshot sanitization receipt is invalid or uses another policy'
    );
    return [
        'admission' => 'sanitized-snapshot',
        'database_sha256' => $snapshot['database_sha256'],
        'format' => 'wprism-reference-containment-sanitization/v1',
        'media_sha256' => $snapshot['media_sha256'],
        'policy_sha256' => $sanitization['policy_sha256'],
        'receipt_sha256' => $sanitization['receipt_sha256'],
        'snapshot_set_id' => $snapshot['snapshot_set_id'],
    ];
}

/**
 * Reconstruct the restore request whose terminal receipt permits an exact
 * containment replay after the provider has disposed the immutable set.
 *
 * @param array<string,mixed> $identity
 * @param array<string,mixed> $fence
 * @param array<string,mixed> $sanitization
 * @return array<string,mixed>
 */
function ref_containment_restore_input(array $identity, array $fence, array $sanitization): array {
    return [
        'database_sha256' => $sanitization['database_sha256'],
        'expected_environment_identity' => $identity['environment_identity'],
        'expected_lease_generation' => $identity['lease_generation'],
        'expected_lease_id' => $identity['lease_id'],
        'expected_mutation_generation' => $fence['generation'],
        'expected_mutation_id' => $fence['id'],
        'expected_mutation_owner' => $fence['owner'],
        'expected_mutation_receipt_sha256' => $fence['held_receipt'],
        'expected_ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
        'expected_resource_id' => $identity['resource_id'],
        'media_sha256' => $sanitization['media_sha256'],
        'snapshot_set_id' => $sanitization['snapshot_set_id'],
    ];
}

/**
 * A successful restore deliberately removes the snapshot/session bytes and
 * active rows. Exact containment replay may then reuse only the persisted
 * sanitized-admission evidence when a closed, lease/fence-bound terminal
 * restore independently proves that exact set was applied and disposed.
 *
 * @param array<string,mixed> $state
 * @param array<string,mixed> $config
 * @param array<string,mixed> $input
 * @param array<string,mixed> $identity
 * @param array<string,mixed> $fence
 * @param array<string,mixed> $existing
 * @return array<string,mixed>
 */
function ref_containment_replay_sanitization_evidence(
    array $state,
    array $config,
    string $operation,
    array $input,
    array $identity,
    array $fence,
    array $existing
): array {
    $snapshotKey = ref_snapshot_key((string) $config['source_environment'], $operation);
    if (is_array($state['snapshots'][$snapshotKey] ?? null) || ($input['topology_only'] ?? null) === true) {
        return ref_containment_sanitization_evidence($state, $config, $operation, $input);
    }
    ref_require(!array_key_exists('topology_only', $input), 'sanitized containment replay cannot change to topology-only admission');
    $preimage = $existing['preimage'] ?? null;
    $sanitization = is_array($preimage) ? ($preimage['snapshot_sanitization'] ?? null) : null;
    ref_require(is_array($sanitization) && !array_is_list($sanitization), 'persisted containment sanitization evidence is malformed');
    ref_assert_key_set(
        $sanitization,
        ['admission', 'database_sha256', 'format', 'media_sha256', 'policy_sha256', 'receipt_sha256', 'snapshot_set_id'],
        'persisted containment sanitization evidence'
    );
    $policy = ref_contained_config($config)['_sanitization_policy'];
    ref_require(
        is_array($policy)
            && ($sanitization['admission'] ?? null) === 'sanitized-snapshot'
            && ($sanitization['format'] ?? null) === 'wprism-reference-containment-sanitization/v1'
            && ($sanitization['policy_sha256'] ?? null) === ($policy['_sha256'] ?? null),
        'persisted containment sanitization evidence does not match the current reviewed policy'
    );
    foreach (['database_sha256', 'media_sha256', 'policy_sha256', 'receipt_sha256'] as $key) {
        ref_require(
            is_string($sanitization[$key] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $sanitization[$key]) === 1,
            "persisted containment sanitization evidence has invalid '$key'"
        );
    }
    ref_require(
        is_string($sanitization['snapshot_set_id'] ?? null) && $sanitization['snapshot_set_id'] !== '',
        'persisted containment sanitization evidence has no snapshot set'
    );

    $restoreKey = ref_restore_key($identity['resource_id'], $operation);
    $restore = $state['restores'][$restoreKey] ?? null;
    ref_require(is_array($restore) && !array_is_list($restore), 'disposed sanitized containment replay has no matching restore receipt');
    ref_assert_key_set(
        $restore,
        [
            'disposal_receipt_sha256', 'environment_identity', 'input_sha256', 'lease_generation', 'lease_id',
            'operation_id', 'ownership_receipt_sha256', 'resource_config_sha256', 'resource_id', 'result',
            'sanitization_receipt_sha256', 'snapshot_set_id', 'state',
        ],
        'disposed sanitized containment restore receipt'
    );
    foreach (ref_lease_tuple($identity, ref_resource_config_sha256($config)) as $key => $value) {
        ref_require(($restore[$key] ?? null) === $value, "disposed sanitized containment restore lease differs at '$key'");
    }
    ref_require(
        ($restore['operation_id'] ?? null) === $operation
            && ($restore['state'] ?? null) === 'disposed-success'
            && ($restore['snapshot_set_id'] ?? null) === $sanitization['snapshot_set_id']
            && ($restore['sanitization_receipt_sha256'] ?? null) === $sanitization['receipt_sha256'],
        'disposed sanitized containment replay requires the exact successful terminal restore'
    );
    ref_require(
        ($restore['input_sha256'] ?? null) === ref_hash(ref_containment_restore_input($identity, $fence, $sanitization)),
        'disposed sanitized containment restore request differs from its admitted snapshot'
    );
    ref_require(
        is_array($restore['result'] ?? null)
            && ref_json($restore['result']) === ref_json($identity + ['snapshot_set_id' => $sanitization['snapshot_set_id']]),
        'disposed sanitized containment restore result is malformed'
    );
    $expectedDisposal = ref_hash([
        'disposition' => 'logically-deleted',
        'format' => 'wprism-reference-snapshot-disposal/v1',
        'operation_id' => $operation,
        'snapshot_key_sha256' => hash('sha256', $snapshotKey),
    ]);
    ref_require(
        ($restore['disposal_receipt_sha256'] ?? null) === $expectedDisposal
            && !isset($state['sessions'][$snapshotKey])
            && !isset($state['snapshots'][$snapshotKey])
            && !isset($state['source_inspections'][$snapshotKey]),
        'disposed sanitized containment restore has incomplete snapshot/session disposal evidence'
    );
    return $sanitization;
}

/** @param array<string,mixed> $identity @return array<string,mixed> */
function ref_containment_result(array $identity, string $receipt): array {
    return $identity + [
        'containment_receipt_sha256' => $receipt,
        'credential_isolation' => true,
        'http_egress_default_denied' => true,
        'mail_default_denied' => true,
        'payment_default_denied' => true,
        'profile' => 'agency-rehearsal-v1',
        'queue_default_denied' => true,
        'webhook_default_denied' => true,
    ];
}

/**
 * @param array<string,mixed> $state
 * @param array<string,mixed> $config
 * @param array<string,mixed> $environment
 * @param array<string,mixed> $resource
 * @param array<string,mixed> $identity
 */
function ref_revalidate_containment(array $state, array $config, array $environment, array $resource, array $identity): void {
    if (!ref_containment_enabled($config)) return;
    $record = $state['containments'][$identity['resource_id']] ?? null;
    if ($record === null) return;
    ref_require(is_array($record) && !array_is_list($record), 'contained-preview receipt state is malformed');
    foreach (ref_lease_tuple($identity, ref_resource_config_sha256($config)) as $key => $value) {
        ref_require(($record[$key] ?? null) === $value, "contained-preview receipt lease differs at '$key'");
    }
    $topology = ref_probe_contained_topology($config, $environment, $resource);
    ref_require(ref_hash($topology) === ($record['topology_sha256'] ?? null), 'contained-preview live topology drifted after verification');
    $preimage = $record['preimage'] ?? null;
    ref_require(is_array($preimage) && ref_hash($preimage) === ($record['receipt_sha256'] ?? null), 'contained-preview receipt preimage changed');
    ref_require(
        is_array($preimage['topology'] ?? null)
            && ref_json($preimage['topology']) === ref_json($topology),
        'contained-preview receipt no longer describes the live topology'
    );
}

/** @param array<string,mixed> $config @return list<string> */
function ref_dump_command(array $config, string $database): array {
    return [
        'docker', 'exec', (string) $config['db_container'], 'mariadb-dump',
        '--single-transaction', '--skip-comments', '--skip-dump-date', '--skip-extended-insert',
        '-uroot', '-proot', $database,
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
 * `wp wprism refresh-export` on the source between snapshot-prepare and
 * snapshot-create. That row is a lock, not authored or runtime state; a
 * witness that treated its timestamp as "the source changed" refused every
 * live WordPress source (grind_mup.sh step 5). Only the two-sided comparison
 * ignores the lock's value. In contained mode the reviewed sanitizer
 * transforms configured credential locators before durable publication;
 * nothing else is normalised.
 */
function ref_freeze_witness(string $dump): string {
    $normalised = preg_replace(
        "/\\((\\d+),'_transient_doing_cron','[^']*','([^']*)'\\)/",
        "(\\1,'_transient_doing_cron','<cron-lock>','\\2')",
        $dump
    );
    return hash('sha256', is_string($normalised) ? $normalised : $dump);
}

function ref_mysql_string_decode(string $literal): string {
    ref_require(
        strlen($literal) >= 2 && $literal[0] === "'" && $literal[strlen($literal) - 1] === "'",
        'wp_options sanitizer encountered a non-string field'
    );
    $body = substr($literal, 1, -1);
    $decoded = '';
    for ($index = 0, $length = strlen($body); $index < $length; $index++) {
        $byte = $body[$index];
        if ($byte !== '\\') {
            $decoded .= $byte;
            continue;
        }
        ref_require(++$index < $length, 'wp_options sanitizer encountered a truncated SQL escape');
        $escaped = $body[$index];
        $decoded .= match ($escaped) {
            '0' => "\0", 'b' => "\x08", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1a",
            default => $escaped,
        };
    }
    return $decoded;
}

function ref_mysql_string_encode(string $value): string {
    return "'" . strtr($value, [
        '\\' => '\\\\', "\0" => '\\0', "\x08" => '\\b', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t',
        "\x1a" => '\\Z', "'" => "\\'",
    ]) . "'";
}

/** @param array<string,mixed> $policy @return array{database:string,witness:array<string,mixed>} */
function ref_sanitize_wordpress_auth(string $dump, array $policy): array {
    $quoted = "'(?>[^'\\\\]+|\\\\.)*'";
    $userRows = substr_count($dump, 'INSERT INTO `wp_users` VALUES ');
    $parsedUsers = 0;
    $auth = $policy['wordpress_auth'];
    $usersPattern = "~^INSERT INTO `wp_users` VALUES \\(([0-9]+),($quoted),($quoted),($quoted),($quoted),($quoted),($quoted),($quoted),([0-9]+),($quoted)\\);$~m";
    $sanitized = preg_replace_callback(
        $usersPattern,
        static function (array $match) use ($auth, &$parsedUsers): string {
            $parsedUsers++;
            return 'INSERT INTO `wp_users` VALUES (' . $match[1] . ',' . $match[2] . ','
                . ref_mysql_string_encode((string) $auth['password_replacement']) . ','
                . $match[4] . ',' . $match[5] . ',' . $match[6] . ',' . $match[7] . ','
                . ref_mysql_string_encode((string) $auth['activation_key_replacement']) . ','
                . $match[9] . ',' . $match[10] . ');';
        },
        $dump
    );
    ref_require(is_string($sanitized) && $parsedUsers === $userRows, 'WordPress user credential rows have an unsupported dump shape');

    $metaRows = substr_count($sanitized, 'INSERT INTO `wp_usermeta` VALUES ');
    $parsedMeta = 0;
    $removed = array_fill_keys($auth['remove_usermeta_keys'], 0);
    $metaPattern = "~^INSERT INTO `wp_usermeta` VALUES \\(([0-9]+),([0-9]+),($quoted),($quoted)\\);(?:\\r?\\n)?~m";
    $sanitized = preg_replace_callback(
        $metaPattern,
        static function (array $match) use (&$parsedMeta, &$removed): string {
            $parsedMeta++;
            $key = ref_mysql_string_decode($match[3]);
            if (!array_key_exists($key, $removed)) return $match[0];
            $removed[$key]++;
            return '';
        },
        $sanitized
    );
    ref_require(is_string($sanitized) && $parsedMeta === $metaRows, 'WordPress usermeta credential rows have an unsupported dump shape');
    ksort($removed, SORT_STRING);
    return [
        'database' => $sanitized,
        'witness' => [
            'activation_keys_cleared' => $parsedUsers,
            'application_password_rows_removed' => $removed['_application_passwords'],
            'passwords_disabled' => $parsedUsers,
            'session_rows_removed' => $removed['session_tokens'],
            'usermeta_rows_parsed' => $parsedMeta,
            'users_rows_parsed' => $parsedUsers,
        ],
    ];
}

/**
 * Transform only exact, reviewed wp_options locators. `--skip-extended-insert`
 * makes each row independently parseable; an unfamiliar dump shape or a
 * missing/duplicated reviewed row refuses instead of guessing at secrets.
 *
 * @param array<string,mixed> $policy
 * @return array{database:string,witnesses:array<string,mixed>}
 */
function ref_sanitize_database(string $dump, array $policy): array {
    $configured = [];
    foreach ($policy['database']['options'] as $locator) {
        $configured[(string) $locator['name']] = (string) $locator['replacement'];
    }
    if ($configured === []) {
        $auth = ref_sanitize_wordpress_auth($dump, $policy);
        return ['database' => $auth['database'], 'witnesses' => ['options' => [], 'wordpress_auth' => $auth['witness']]];
    }
    $witnesses = [];
    $sanitized = $dump;
    $quoted = "'(?>[^'\\\\]+|\\\\.)*'";
    foreach ($configured as $name => $replacement) {
        $nameLiteral = preg_quote(ref_mysql_string_encode($name), '~');
        $pattern = "~^(INSERT INTO `wp_options` VALUES \\([0-9]+,$nameLiteral,)($quoted)(,($quoted)\\);)$~m";
        $count = 0;
        $sanitized = preg_replace_callback(
            $pattern,
            static function (array $match) use ($name, $replacement, &$witnesses): string {
                $source = ref_mysql_string_decode($match[2]);
                ref_require(!hash_equals($source, $replacement), "reviewed wp_options locator '$name' is already its sandbox replacement");
                $witnesses[] = [
                    'action' => 'replace',
                    'name' => $name,
                    'replacement_sha256' => hash('sha256', $replacement),
                ];
                return $match[1] . ref_mysql_string_encode($replacement) . $match[3];
            },
            $sanitized,
            -1,
            $count
        );
        ref_require(is_string($sanitized), "wp_options sanitizer failed for reviewed locator '$name'");
        ref_require($count === 1, "reviewed wp_options locator '$name' is absent, duplicated or has an unsupported dump shape");
    }
    usort($witnesses, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));
    $auth = ref_sanitize_wordpress_auth($sanitized, $policy);
    return [
        'database' => $auth['database'],
        'witnesses' => ['options' => $witnesses, 'wordpress_auth' => $auth['witness']],
    ];
}

/** @param array<string,mixed> $policy @return list<array<string,string>> */
function ref_sanitize_media(string $root, array $policy): array {
    ref_assert_tree_has_no_symlinks($root, 'contained-preview source media');
    $witnesses = [];
    foreach ($policy['media'] as $locator) {
        $relative = (string) $locator['path'];
        $path = $root . '/' . $relative;
        ref_require(is_file($path) && !is_link($path), "reviewed media credential locator '$relative' is absent or not a regular file");
        ref_require(unlink($path), "reviewed media credential locator '$relative' cannot be removed");
        ref_require(!ref_path_entry_exists($path), "reviewed media credential locator '$relative' remains after sanitization");
        $witnesses[] = ['action' => 'remove', 'path' => $relative];
    }
    usort($witnesses, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
    ref_private_tree($root, 'contained-preview sanitized media');
    return $witnesses;
}

/**
 * @param array<string,mixed> $config
 * @return array{database:string,raw_database_witness_sha256:string,raw_media_sha256:string,sanitization:array<string,mixed>,sanitized_media_sha256:string}
 */
function ref_sanitize_snapshot(array $config, string $rawDump, string $mediaRoot): array {
    $contained = ref_contained_config($config);
    $policy = $contained['_sanitization_policy'];
    ref_require(is_array($policy), 'contained-preview sanitization policy is unavailable');
    $rawMedia = ref_tree_hash($mediaRoot);
    $database = ref_sanitize_database($rawDump, $policy);
    $mediaWitnesses = ref_sanitize_media($mediaRoot, $policy);
    $sanitizedMedia = ref_tree_hash($mediaRoot);
    $preimage = [
        'database_witnesses' => $database['witnesses'],
        'format' => 'wprism-reference-snapshot-sanitization-receipt/v1',
        'media_witnesses' => $mediaWitnesses,
        'policy' => [
            'credential_inventory' => $policy['assertion']['credential_inventory'],
            'review_id' => $policy['assertion']['review_id'],
            'revision' => $policy['assertion']['revision'],
            'sha256' => $policy['_sha256'],
        ],
        'raw_database_witness_sha256' => ref_freeze_witness($rawDump),
        'raw_media_sha256' => $rawMedia,
        'sanitized_database_witness_sha256' => ref_freeze_witness($database['database']),
        'sanitized_media_sha256' => $sanitizedMedia,
    ];
    return [
        'database' => $database['database'],
        'raw_database_witness_sha256' => $preimage['raw_database_witness_sha256'],
        'raw_media_sha256' => $rawMedia,
        'sanitization' => [
            'policy_sha256' => $policy['_sha256'],
            'preimage' => $preimage,
            'receipt_sha256' => ref_hash($preimage),
        ],
        'sanitized_media_sha256' => $sanitizedMedia,
    ];
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
 * grind_adoption A6 saw `wprism apply` on the rehearsal target fail its required
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
        'acquisitions' => [], 'containments' => [], 'fences' => [], 'reap_receipts' => [], 'resources' => [], 'restores' => [],
        'sessions' => [], 'slot_authorities' => [], 'snapshot_abort_receipts' => [], 'snapshots' => [],
        'source_inspections' => [], 'ttls' => [],
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
    if (!array_key_exists('containments', $state)) $state['containments'] = [];
    if (!array_key_exists('reap_receipts', $state)) $state['reap_receipts'] = [];
    if (!array_key_exists('restores', $state)) $state['restores'] = [];
    if (!array_key_exists('snapshot_abort_receipts', $state)) $state['snapshot_abort_receipts'] = [];
    if (!array_key_exists('slot_authorities', $state)) $state['slot_authorities'] = [];
    if (!array_key_exists('source_inspections', $state)) $state['source_inspections'] = [];
    foreach (['acquisitions', 'containments', 'fences', 'reap_receipts', 'resources', 'restores', 'sessions', 'slot_authorities', 'snapshot_abort_receipts', 'snapshots', 'source_inspections', 'ttls'] as $key) {
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
        $markerBytes = "wprism-reference-env-provider-state/v1\n";
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
    $path = $root . '/actions.ndjson';
    ref_require(!is_link($path), 'reference provider action log must not be a symlink');
    ref_require(file_put_contents($path, ref_json($record) . "\n", FILE_APPEND | LOCK_EX) !== false, 'could not append reference provider action log');
    chmod($path, 0600);
    ref_assert_current_owner($path, 'reference provider action log');
}

function ref_snapshot_key(string $environment, string $operation): string { return $environment . '|' . $operation; }

/**
 * Delete only the operation-derived snapshot paths under the private provider
 * root. This is logical disposal, not a physical secure-erasure claim.
 *
 * @param array<string,mixed> $state
 * @param array<string,mixed> $config
 */
function ref_dispose_snapshot_operation(array &$state, array $config, string $operation): string {
    $key = ref_snapshot_key((string) $config['source_environment'], $operation);
    $suffix = hash('sha256', $key);
    $preparedPath = (string) $config['state_root'] . '/prepared/' . $suffix;
    $snapshotPath = (string) $config['state_root'] . '/snapshots/' . $suffix;
    $scratchPath = (string) $config['state_root'] . '/create-media-' . $suffix;
    $session = $state['sessions'][$key] ?? null;
    if (is_array($session) && isset($session['path'])) {
        ref_require($session['path'] === $preparedPath, 'snapshot disposal refuses a foreign prepared-session path');
    }
    $snapshot = $state['snapshots'][$key] ?? null;
    if (is_array($snapshot)) {
        ref_require(
            dirname((string) ($snapshot['database_path'] ?? '')) === $snapshotPath
                && dirname((string) ($snapshot['media_path'] ?? '')) === $snapshotPath,
            'snapshot disposal refuses a foreign immutable-set path'
        );
    }
    ref_remove_tree($preparedPath);
    ref_remove_tree($snapshotPath);
    ref_remove_tree($scratchPath);
    unset($state['sessions'][$key], $state['snapshots'][$key], $state['source_inspections'][$key]);
    return ref_hash([
        'disposition' => 'logically-deleted',
        'format' => 'wprism-reference-snapshot-disposal/v1',
        'operation_id' => $operation,
        'snapshot_key_sha256' => hash('sha256', $key),
    ]);
}

function ref_restore_key(string $resourceId, string $operation): string {
    return $resourceId . '|' . $operation;
}

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
        'mutation-release', 'containment-verify', 'repository-materialize', 'snapshot-restore',
        'ttl-read', 'ttl-set', 'url-set',
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
    if ($action === 'snapshot-abort') {
        $abortKey = ref_snapshot_key($environmentName, $operation);
        $cached = $state['snapshot_abort_receipts'][$abortKey] ?? null;
        if (is_array($cached)) {
            ref_require(($cached['input_sha256'] ?? null) === ref_hash($input), 'snapshot abort retry differs from its disposed session request');
            ref_require(is_array($cached['result'] ?? null), 'snapshot abort receipt has no result');
            return $cached['result'];
        }
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
            unset($state['containments'][$resourceId]);
            if ($action === 'create') {
                if (ref_containment_enabled($config)) {
                    $resource['contained_runtime'] = ref_contained_new_runtime($config, $resource);
                    $state['resources'][$resourceId] = $resource;
                }
                // Publish create intent before allocation/cleanup. A crash may
                // make the exact operation clear its unpopulated side again,
                // but another operation cannot claim an ambiguous slot.
                ref_save_state((string) $config['state_root'], $state);
            }
        }

        if ($action === 'attach') {
            // Attach is proof of an independently existing slot, never an
            // allocation alias. Failed discovery publishes no new ownership.
            $observedUrl = ref_discover_url($config, $environment, false);
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
        if ($resource['state'] === 'acquiring') {
            if (ref_containment_enabled($config)) {
                $topology = ref_create_contained_preview($config, $environment, $resource);
                $resource['initial_containment_topology_sha256'] = ref_hash($topology);
                $state['resources'][$resourceId] = $resource;
            } else {
                ref_checked(ref_pair_up_command($config));
            }
        } elseif (ref_containment_enabled($config)) {
            ref_probe_contained_topology($config, $environment, $resource);
        }
        $observedUrl = ref_discover_url($config, $environment, false);
        ref_require($observedUrl === $resource['url'], 'preview-slot URL changed during acquisition');
        $identity = ref_identity($config, $environment, $observedUrl, $resource);
        if ($resource['state'] === 'acquiring') {
            if (!ref_containment_enabled($config)) ref_clear_side($config, $environment);
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
            $url = ref_discover_url($config, $environment, false);
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
                ref_require(ref_discover_url($config, $environment, false) === $url, 'active preview-slot URL differs from its lease');
            }
        }
        $identity = ref_identity($config, $environment, $url, $identityResource);
        if (array_key_exists('expected_environment_identity', $input)) ref_assert_identity_input($input, $identity);
        if ($resource['state'] === 'present') ref_revalidate_containment($state, $config, $environment, $resource, $identity);
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
        ($environment['role'] ?? null) === 'target' ? (string) $resource['url'] : ref_discover_url($config, $environment, false),
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
            try {
                $dump = ref_dump_database($config, (string) $environment['database']);
                ref_copy_media_from_container((string) $environment['container'], $staging . '/media');
                if (ref_containment_enabled($config)) {
                    $sanitized = ref_sanitize_snapshot($config, $dump, $staging . '/media');
                    ref_write_private_file($staging . '/database.sql', $sanitized['database']);
                    ref_private_tree($staging, 'contained-preview prepared snapshot');
                    $prepared['database_sha256'] = hash('sha256', $sanitized['database']);
                    $prepared['database_witness_sha256'] = $sanitized['raw_database_witness_sha256'];
                    $prepared['media_sha256'] = $sanitized['sanitized_media_sha256'];
                    $prepared['raw_media_sha256'] = $sanitized['raw_media_sha256'];
                    $prepared['sanitization'] = $sanitized['sanitization'];
                } else {
                    ref_require(
                        file_put_contents($staging . '/database.sql', $dump, LOCK_EX) === strlen($dump),
                        'could not write source database evidence'
                    );
                    $databaseHash = hash_file('sha256', $staging . '/database.sql');
                    ref_require(is_string($databaseHash), 'could not hash source database evidence');
                    $prepared['database_sha256'] = $databaseHash;
                    $prepared['database_witness_sha256'] = ref_freeze_witness($dump);
                    $prepared['media_sha256'] = ref_tree_hash($staging . '/media');
                }
                $prepared['state'] = 'prepared';
                $state['sessions'][$key] = $prepared;
                ref_save_state((string) $config['state_root'], $state);
            } catch (Throwable $error) {
                ref_remove_tree($staging);
                unset($state['sessions'][$key]);
                ref_save_state((string) $config['state_root'], $state);
                throw $error;
            }
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
            $mediaScratch = (string) $config['state_root'] . '/create-media-' . hash('sha256', $key);
            ref_remove_tree($mediaScratch);
            try {
                ref_copy_media_from_container((string) $environment['container'], $mediaScratch);
                $snapshotDump = $currentDump;
                $currentRawMedia = ref_tree_hash($mediaScratch);
                $sanitization = null;
                if (ref_containment_enabled($config)) {
                    $sanitized = ref_sanitize_snapshot($config, $currentDump, $mediaScratch);
                    $snapshotDump = $sanitized['database'];
                    $currentRawMedia = $sanitized['raw_media_sha256'];
                    $sanitization = $sanitized['sanitization'];
                    ref_require(
                        is_array($prepared['sanitization'] ?? null)
                            && ref_json($sanitization) === ref_json($prepared['sanitization']),
                        'source sanitization receipt changed between prepare and create'
                    );
                }
                // Fail-closed freeze witness: a source write between prepare
                // and create refuses. The one ignored WordPress cron lock is
                // documented by ref_freeze_witness(); reviewed credential
                // transformation must independently reproduce its receipt.
                $changed = [];
                $preparedWitness = (string) ($prepared['database_witness_sha256'] ?? $prepared['database_sha256']);
                if (ref_freeze_witness($currentDump) !== $preparedWitness) $changed[] = 'database';
                $preparedRawMedia = ref_containment_enabled($config)
                    ? ($prepared['raw_media_sha256'] ?? null)
                    : ($prepared['media_sha256'] ?? null);
                if ($currentRawMedia !== $preparedRawMedia) $changed[] = 'media';
                ref_require(
                    $changed === [],
                    'source changed between its prepared snapshot session and create (' . implode(',', $changed) . ')'
                );
                $snapshotDir = (string) $config['state_root'] . '/snapshots/' . hash('sha256', $key);
                ref_remove_tree($snapshotDir);
                if (!mkdir($snapshotDir, 0700, true)) throw new RuntimeException('could not create immutable snapshot directory');
                if (ref_containment_enabled($config)) {
                    ref_write_private_file($snapshotDir . '/database.sql', $snapshotDump);
                } else {
                    ref_require(
                        file_put_contents($snapshotDir . '/database.sql', $snapshotDump, LOCK_EX) === strlen($snapshotDump),
                        'could not publish immutable database snapshot'
                    );
                }
                if (!rename($mediaScratch, $snapshotDir . '/media')) throw new RuntimeException('could not publish immutable media snapshot');
                $snapshot = [
                    'database_path' => $snapshotDir . '/database.sql',
                    'database_sha256' => hash('sha256', $snapshotDump),
                    'lease_generation' => $prepared['lease_generation'],
                    'lease_id' => $prepared['lease_id'],
                    'lease_receipt_sha256' => $prepared['lease_receipt_sha256'],
                    'media_path' => $snapshotDir . '/media',
                    'media_sha256' => ref_tree_hash($snapshotDir . '/media'),
                    'retention_receipt_sha256' => ref_hash('retention:' . $key),
                    'semantic_snapshot_sha256' => $input['expected_semantic_snapshot_sha256'],
                    'snapshot_session_id' => $prepared['snapshot_session_id'],
                    'snapshot_set_id' => 'snapshot-set-' . substr(hash('sha256', $key), 0, 20),
                    'source_identity' => $prepared['source_identity'],
                ];
                if (is_array($sanitization)) $snapshot['sanitization'] = $sanitization;
                $snapshot['snapshot_set_receipt_sha256'] = ref_hash([
                    'database_sha256' => $snapshot['database_sha256'], 'media_sha256' => $snapshot['media_sha256'],
                    'sanitization_receipt_sha256' => $sanitization['receipt_sha256'] ?? null,
                    'semantic_snapshot_sha256' => $snapshot['semantic_snapshot_sha256'], 'snapshot_set_id' => $snapshot['snapshot_set_id'],
                ]);
                if (ref_containment_enabled($config)) {
                    ref_private_tree($snapshotDir, 'contained-preview immutable snapshot');
                } else {
                    ref_chmod_tree($snapshotDir, 0555);
                }
                $state['snapshots'][$key] = $snapshot;
                ref_save_state((string) $config['state_root'], $state);
            } catch (Throwable $error) {
                ref_remove_tree($mediaScratch);
                ref_remove_tree((string) $config['state_root'] . '/snapshots/' . hash('sha256', $key));
                $staging = $prepared['path'] ?? null;
                if (is_string($staging) && $staging !== '') ref_remove_tree($staging);
                unset($state['sessions'][$key], $state['snapshots'][$key]);
                ref_save_state((string) $config['state_root'], $state);
                throw $error;
            }
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
        ref_require(in_array($prepared['state'] ?? null, ['preparing', 'prepared'], true), 'snapshot abort session is not resumable');
        $result = [
            'disposition' => 'aborted', 'lease_generation' => $prepared['lease_generation'], 'lease_id' => $prepared['lease_id'],
            'lease_receipt_sha256' => $prepared['lease_receipt_sha256'], 'snapshot_session_id' => $prepared['snapshot_session_id'],
            'source_identity' => $prepared['source_identity'],
        ];
        $disposalReceipt = ref_dispose_snapshot_operation($state, $config, $operation);
        $state['snapshot_abort_receipts'][$key] = [
            'disposal_receipt_sha256' => $disposalReceipt,
            'input_sha256' => ref_hash($input),
            'result' => $result,
        ];
        ref_save_state((string) $config['state_root'], $state);
        return $result;
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
        ref_revalidate_containment($state, $config, $environment, $resource, $identity);
        return ref_fence_result($identity, $fence);
    }

    if ($action === 'mutation-read') {
        $fence = ref_require_fence($state, $input, $identity, $resourceConfig, false);
        ref_revalidate_containment($state, $config, $environment, $resource, $identity);
        return ref_fence_result($identity, $fence);
    }

    if ($action === 'mutation-release') {
        $fence = ref_require_fence($state, $input, $identity, $resourceConfig, false, true);
        ref_revalidate_containment($state, $config, $environment, $resource, $identity);
        if ($fence['state'] === 'held') {
            $fence['state'] = 'released';
            $fence['receipt'] = ref_hash('released:' . $fence['id'] . ':' . $fence['owner']);
            $state['fences'][$fence['id']] = $fence;
        }
        ref_require($fence['state'] === 'released', 'mutation release has an invalid fence state');
        return ref_fence_result($identity, $fence);
    }

    if ($action === 'containment-verify') {
        ref_require(($input['profile'] ?? null) === 'agency-rehearsal-v1', 'contained preview requires profile agency-rehearsal-v1');
        $fence = ref_require_fence($state, $input, $identity, $resourceConfig);
        $receiptInput = $input + ['_operation_id' => $operation];
        $inputSha = ref_hash($receiptInput);
        $existing = $state['containments'][$resourceId] ?? null;
        ref_require($existing === null || is_array($existing) && !array_is_list($existing), 'persisted contained-preview verification receipt is malformed');
        if (is_array($existing)) {
            ref_assert_key_set(
                $existing,
                [
                    'environment_identity', 'input_sha256', 'lease_generation', 'lease_id', 'operation_id',
                    'ownership_receipt_sha256', 'preimage', 'receipt_sha256', 'resource_config_sha256',
                    'resource_id', 'result', 'topology_sha256',
                ],
                'persisted contained-preview verification receipt'
            );
            foreach (ref_lease_tuple($identity, $resourceConfig) as $key => $value) {
                ref_require(($existing[$key] ?? null) === $value, "contained-preview verification retry lease differs at '$key'");
            }
            ref_require(
                ($existing['input_sha256'] ?? null) === $inputSha
                    && ($existing['operation_id'] ?? null) === $operation,
                'contained-preview verification retry differs from its persisted request'
            );
            $persistedPreimage = $existing['preimage'] ?? null;
            $persistedResult = $existing['result'] ?? null;
            $persistedReceipt = $existing['receipt_sha256'] ?? null;
            $persistedTopology = is_array($persistedPreimage) ? ($persistedPreimage['topology'] ?? null) : null;
            ref_require(
                is_array($persistedPreimage) && !array_is_list($persistedPreimage)
                    && is_array($persistedResult) && !array_is_list($persistedResult)
                    && is_string($persistedReceipt) && preg_match('/^[a-f0-9]{64}$/D', $persistedReceipt) === 1
                    && ref_hash($persistedPreimage) === $persistedReceipt,
                'persisted contained-preview verification evidence is malformed'
            );
            ref_require(
                is_array($persistedTopology) && !array_is_list($persistedTopology)
                    && is_string($existing['topology_sha256'] ?? null)
                    && ref_hash($persistedTopology) === $existing['topology_sha256'],
                'persisted contained-preview topology evidence is malformed'
            );
            $sanitizationEvidence = ref_containment_replay_sanitization_evidence(
                $state,
                $config,
                $operation,
                $input,
                $identity,
                $fence,
                $existing
            );
            $topology = ref_probe_contained_topology($config, $environment, $resource);
            ref_require(
                ref_hash($topology) === $existing['topology_sha256'],
                'contained-preview live topology drifted after verification'
            );
            $preimage = ref_containment_preimage($identity, $fence, $receiptInput, $sanitizationEvidence, $topology);
            $receipt = ref_hash($preimage);
            $result = ref_containment_result($identity, $receipt);
            ref_require(
                ref_json($persistedPreimage) === ref_json($preimage)
                    && $persistedReceipt === $receipt
                    && ref_json($persistedResult) === ref_json($result),
                'persisted contained-preview verification evidence differs from its exact replay'
            );
            return $result;
        }
        $topology = ref_probe_contained_topology($config, $environment, $resource);
        $sanitizationEvidence = ref_containment_sanitization_evidence($state, $config, $operation, $input);
        $preimage = ref_containment_preimage($identity, $fence, $receiptInput, $sanitizationEvidence, $topology);
        $receipt = ref_hash($preimage);
        $result = ref_containment_result($identity, $receipt);
        $state['containments'][$resourceId] = ref_lease_tuple($identity, $resourceConfig) + [
            'input_sha256' => $inputSha,
            'operation_id' => $operation,
            'preimage' => $preimage,
            'receipt_sha256' => $receipt,
            'result' => $result,
            'topology_sha256' => ref_hash($topology),
        ];
        return $result;
    }

    if ($action === 'snapshot-restore') {
        ref_require_fence($state, $input, $identity, $resourceConfig);
        ref_revalidate_containment($state, $config, $environment, $resource, $identity);
        $restoreKey = ref_restore_key($resourceId, $operation);
        $restoreInputSha = ref_hash($input);
        $restore = $state['restores'][$restoreKey] ?? null;
        if (is_array($restore)) {
            foreach (ref_lease_tuple($identity, $resourceConfig) as $key => $value) {
                ref_require(($restore[$key] ?? null) === $value, "snapshot restore lease differs at '$key'");
            }
            ref_require(($restore['input_sha256'] ?? null) === $restoreInputSha, 'snapshot restore retry differs from its journaled request');
            if (($restore['state'] ?? null) === 'disposed-success') {
                ref_require(is_array($restore['result'] ?? null), 'disposed snapshot restore has no result');
                return $restore['result'];
            }
            if (in_array($restore['state'] ?? null, ['failed', 'disposed-failure'], true)) {
                if ($restore['state'] === 'failed') {
                    $restore['disposal_receipt_sha256'] = ref_dispose_snapshot_operation($state, $config, $operation);
                    $restore['state'] = 'disposed-failure';
                    $state['restores'][$restoreKey] = $restore;
                    ref_save_state((string) $config['state_root'], $state);
                }
                throw new RuntimeException('snapshot restore previously failed and its bytes were logically disposed (receipt ' . $restore['failure_receipt_sha256'] . ')');
            }
        }
        $snapshotId = $input['snapshot_set_id'] ?? null;
        $snapshotKey = ref_snapshot_key((string) $config['source_environment'], $operation);
        $snapshot = $state['snapshots'][$snapshotKey] ?? null;
        if (!is_array($restore)) {
            ref_require(
                is_array($snapshot) && ($snapshot['snapshot_set_id'] ?? null) === $snapshotId
                    && ($input['database_sha256'] ?? null) === $snapshot['database_sha256']
                    && ($input['media_sha256'] ?? null) === $snapshot['media_sha256'],
                'snapshot restore is not bound to this operation\'s immutable set'
            );
            if (ref_containment_enabled($config)) {
                $containment = $state['containments'][$resourceId] ?? null;
                $proved = is_array($containment) ? ($containment['preimage']['snapshot_sanitization'] ?? null) : null;
                $current = ref_containment_sanitization_evidence($state, $config, $operation, $input);
                ref_require(
                    is_array($proved) && ref_json($proved) === ref_json($current)
                        && ($proved['admission'] ?? null) === 'sanitized-snapshot',
                    'snapshot restore is not bound to the containment proof\'s sanitized admission receipt'
                );
            }
            $result = $identity + ['snapshot_set_id' => $snapshot['snapshot_set_id']];
            $restore = ref_lease_tuple($identity, $resourceConfig) + [
                'input_sha256' => $restoreInputSha,
                'operation_id' => $operation,
                'result' => $result,
                'sanitization_receipt_sha256' => $snapshot['sanitization']['receipt_sha256'] ?? null,
                'snapshot_set_id' => $snapshot['snapshot_set_id'],
                'state' => 'restoring',
            ];
            $state['restores'][$restoreKey] = $restore;
            ref_save_state((string) $config['state_root'], $state);
        }
        if (($restore['state'] ?? null) === 'restoring') {
            try {
                ref_require(is_array($snapshot), 'journaled snapshot restore lost its immutable set before application');
                $dump = file_get_contents((string) $snapshot['database_path']);
                if (!is_string($dump)) throw new RuntimeException('could not read immutable database snapshot');
                if (ref_containment_enabled($config)) {
                    ref_restore_contained_database($config, $environment, $resource, $dump);
                } else {
                    ref_restore_database($config, (string) $environment['database'], $dump);
                }
                ref_restore_media_to_container((string) $snapshot['media_path'], (string) $environment['container']);
                $restore['state'] = 'restored';
                $state['restores'][$restoreKey] = $restore;
                // Journal successful application before deleting the only
                // provider copy. A crash can then resume disposal without
                // replaying or inventing restore success.
                ref_save_state((string) $config['state_root'], $state);
            } catch (Throwable $error) {
                $restore['failure_receipt_sha256'] = ref_hash([
                    'error_sha256' => hash('sha256', $error->getMessage()),
                    'format' => 'wprism-reference-snapshot-restore-failure/v1',
                    'input_sha256' => $restoreInputSha,
                    'operation_id' => $operation,
                    'snapshot_set_id' => $restore['snapshot_set_id'],
                ]);
                $restore['state'] = 'failed';
                $state['restores'][$restoreKey] = $restore;
                ref_save_state((string) $config['state_root'], $state);
                $restore['disposal_receipt_sha256'] = ref_dispose_snapshot_operation($state, $config, $operation);
                $restore['state'] = 'disposed-failure';
                $state['restores'][$restoreKey] = $restore;
                ref_save_state((string) $config['state_root'], $state);
                throw $error;
            }
        }
        ref_require(($restore['state'] ?? null) === 'restored', 'snapshot restore journal has an invalid state');
        $restore['disposal_receipt_sha256'] = ref_dispose_snapshot_operation($state, $config, $operation);
        $restore['state'] = 'disposed-success';
        $state['restores'][$restoreKey] = $restore;
        ref_save_state((string) $config['state_root'], $state);
        return $restore['result'];
    }

    if ($action === 'repository-materialize') {
        ref_require_fence($state, $input, $identity, $resourceConfig);
        ref_revalidate_containment($state, $config, $environment, $resource, $identity);
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
        ref_revalidate_containment($state, $config, $environment, $resource, $identity);
        ref_require(($input['url'] ?? null) === $identity['url'], 'provider URL differs from target identity');
        foreach (['home', 'siteurl'] as $option) {
            ref_checked(
                ref_containment_enabled($config)
                    ? ref_contained_compose_command($config, ['run', '--rm', '-T', (string) $environment['service'], 'wp', 'option', 'update', $option, (string) $identity['url'], '--quiet'])
                    : ref_compose_command($config, ['run', '--rm', '-T', (string) $environment['service'], 'wp', 'option', 'update', $option, (string) $identity['url'], '--quiet']),
                null,
                (string) $config['compose_dir'],
                ref_containment_enabled($config) ? ref_contained_compose_environment($config, $resource) : ref_compose_environment($config)
            );
        }
        return $identity;
    }

    if ($action === 'ttl-set') {
        ref_require_fence($state, $input, $identity, $resourceConfig);
        ref_revalidate_containment($state, $config, $environment, $resource, $identity);
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
        ref_revalidate_containment($state, $config, $environment, $resource, $identity);
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
        $reaping = $resource['state'] === 'reaping';
        if ($reaping) {
            ref_require(
                $resource['reap_action'] === $action
                    && $resource['reap_input_sha256'] === $reapInputSha
                    && $resource['reap_operation_id'] === $operation,
                'preview-slot reap retry differs from its persisted intent'
            );
        }
        $containedAlreadyAbsent = false;
        if (ref_containment_enabled($config) && $reaping) {
            $containedAlreadyAbsent = ref_contained_physical_absent($config);
            if (!$containedAlreadyAbsent) {
                // A nonempty project is not inferred to be an interrupted
                // teardown. It must still prove the exact contained topology
                // before this lease is allowed to continue destruction.
                ref_revalidate_containment($state, $config, $environment, $resource, $identity);
            }
        } else {
            ref_revalidate_containment($state, $config, $environment, $resource, $identity);
        }
        if (!$reaping) {
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
        if (ref_containment_enabled($config)) {
            $resource['snapshot_disposal_receipt_sha256'] = ref_dispose_snapshot_operation(
                $state,
                $config,
                (string) $resource['operation_id']
            );
            $state['resources'][$resourceId] = $resource;
        }
        if ($action === 'destroy') {
            if (ref_containment_enabled($config)) {
                ref_destroy_contained_preview($config, $environment, $resource, $containedAlreadyAbsent);
            } else {
                ref_clear_side($config, $environment);
            }
        }
        $resource['state'] = 'absent';
        unset($resource['reap_action'], $resource['reap_input_sha256'], $resource['reap_operation_id']);
        $state['resources'][$resourceId] = $resource;
        unset($state['ttls'][$resourceId]);
        unset($state['containments'][$resourceId]);
        unset($resource['contained_runtime'], $resource['initial_containment_topology_sha256']);
        $state['resources'][$resourceId] = $resource;
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
        'acquisitions' => [], 'containments' => [], 'fences' => [], 'reap_receipts' => [], 'resources' => [], 'restores' => [],
        'sessions' => [], 'slot_authorities' => [], 'snapshot_abort_receipts' => [], 'snapshots' => [],
        'source_inspections' => [], 'ttls' => [],
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
        $commands[] = ['argv' => ref_port_command($config, $environment)];
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
                $commands[] = ['argv' => ref_port_command($config, $environment)];
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
                if (ref_containment_enabled($config)) {
                    $commands[] = ['argv' => ['cp', '-R', '<runtime-sources>', '<lease-runtime>']];
                    $commands[] = ['argv' => ref_contained_compose_command($config, ['up', '-d', '--wait', 'db'])];
                    $commands[] = ['argv' => ['docker', 'network', 'inspect', (string) ref_contained_config($config)['network']]];
                    $commands[] = ['argv' => ref_contained_compose_command($config, ['up', '-d', '--wait', 'wp', 'proxy'])];
                } else {
                    $commands[] = ['argv' => ref_pair_up_command($config)];
                }
            }
            $commands[] = ['argv' => ref_port_command($config, $environment)];
            if ($action === 'create' && $phase !== 'present' && !ref_containment_enabled($config)) {
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
            if (ref_containment_enabled($config)) {
                $commands[] = ['argv' => ref_contained_compose_command($config, ['exec', '-T', 'db', 'sh', '-c', 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -uroot']), 'stdin' => 'drop-create'];
                $commands[] = ['argv' => ref_contained_compose_command($config, ['exec', '-T', 'db', 'sh', '-c', 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -uroot "$MARIADB_DATABASE"']), 'stdin' => 'immutable-dump'];
            } else {
                $commands[] = ['argv' => ['docker', 'exec', '-i', (string) $config['db_container'], 'mariadb', '-uroot', '-proot'], 'stdin' => 'drop-create'];
                $commands[] = ['argv' => ['docker', 'exec', '-i', (string) $config['db_container'], 'mariadb', '-uroot', '-proot', (string) $environment['database']], 'stdin' => 'immutable-dump'];
            }
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
                    'argv' => ref_containment_enabled($config)
                        ? ref_contained_compose_command($config, ['run', '--rm', '-T', (string) $environment['service'], 'wp', 'option', 'update', $option, $url, '--quiet'])
                        : ref_compose_command($config, ['run', '--rm', '-T', (string) $environment['service'], 'wp', 'option', 'update', $option, $url, '--quiet']),
                    'cwd' => (string) $config['compose_dir'],
                ];
            }
            break;
        case 'containment-verify':
            ref_require(($input['profile'] ?? null) === 'agency-rehearsal-v1', 'contained preview requires profile agency-rehearsal-v1');
            $commands[] = ['argv' => ref_contained_compose_command($config, ['config', '--format', 'json'])];
            $commands[] = ['argv' => ['docker', 'inspect', (string) $environment['container'], (string) ref_contained_config($config)['database_container']]];
            $commands[] = ['argv' => ['docker', 'network', 'inspect', (string) ref_contained_config($config)['network']]];
            $commands[] = ['argv' => ['docker', 'ps', '--filter', 'label=com.docker.compose.project=' . (string) ref_contained_config($config)['project'], '--format', '{{.Names}}']];
            $commands[] = ['argv' => ['docker', 'exec', (string) $environment['container'], 'php', '-r', '<egress-and-mail-refusal-probes>']];
            break;
        case 'ttl-set':
            ref_require(is_int($input['ttl_seconds'] ?? null) && $input['ttl_seconds'] >= 60, 'TTL set is invalid');
            break;
        case 'destroy':
            ref_require(($input['compare_and_reap'] ?? null) === true, 'reap lacks compare-and-reap intent');
            if (!is_array($cachedReap)) {
                if (ref_containment_enabled($config)) {
                    $commands[] = ['argv' => ref_contained_compose_command($config, ['down', '--volumes', '--remove-orphans'])];
                } else {
                    $commands = array_merge($commands, ref_clear_side_plan($config, $environment));
                }
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
        'format' => 'wprism-reference-env-provider-plan/v1',
        'identity' => $identity,
        'identity_authoritative' => $identityAuthoritative,
        'identity_input_checked' => $identityChecked,
        'operation_id' => (string) $request['operation_id'],
        'provider' => ['id' => 'wprism-reference-env-provider', 'protocol' => 1],
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
        $request['format'] === 'wprism-branch-environment-provider-request/v1'
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
        $lockPath = $root . '/state.lock';
        ref_require(!is_link($lockPath), 'reference provider state lock must not be a symlink');
        $lock = fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('could not lock reference provider state');
        chmod($lockPath, 0600);
        ref_assert_current_owner($lockPath, 'reference provider state lock');
        $GLOBALS['wprism_reference_provider_state_lock'] = $lock;
        try {
            $state = ref_load_state($root);
            ref_log($root, $request);
            $result = ref_dispatch($request, $config, $state);
            ref_save_state($root, $state);
        } finally {
            unset($GLOBALS['wprism_reference_provider_state_lock']);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    $response = [
        'action' => $request['action'], 'environment' => $request['environment'],
        'format' => 'wprism-branch-environment-provider-response/v1', 'operation_id' => $request['operation_id'],
        'provider' => ['id' => 'wprism-reference-env-provider', 'protocol' => 1], 'result' => $result, 'status' => 'ok',
    ];
    echo ref_json($response) . "\n";
} catch (Throwable $error) {
    // Provider output is redacted by CommandEnvironmentProvider on every
    // failure, so the operator-readable detail is kept beside the state root
    // exactly the way the live materializer fixture does it.
    if (!$planOnly && isset($config) && is_array($config) && is_string($config['state_root'] ?? null) && is_dir($config['state_root'])) {
        $errorPath = $config['state_root'] . '/provider-errors.log';
        if (!is_link($errorPath) && @file_put_contents($errorPath, $error->getMessage() . "\n", FILE_APPEND | LOCK_EX) !== false) {
            @chmod($errorPath, 0600);
        }
    }
    fwrite(STDERR, 'wprism reference env provider: ' . $error->getMessage() . "\n");
    exit(1);
}

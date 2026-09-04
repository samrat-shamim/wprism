<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Kernel/WpCliChildProcess.php';
require_once __DIR__ . '/ManifestProviderRuntime.php';
if (!class_exists(AdapterLibrary::class, false)) {
    require_once __DIR__ . '/../Policy/AdapterLibrary.php';
}
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}
if (!class_exists(Providers::class, false)) {
    require_once __DIR__ . '/Providers.php';
}

/**
 * Execute one artifact-bound manifest capability and verify it from a second
 * fresh WordPress process.
 *
 * Both children reconstruct the parent's frozen Policy against the exact
 * resolved AdapterLibrary. The first performs the idempotent operation and
 * projects its claimed durable postimage; the second projects a separately
 * booted observation while the provider callback is database-read-only through
 * canonical wpdb. One absolute deadline covers both boots,
 * execution, readback, transport, and comparison. No adapter supplies command
 * text, SQL transaction control, request parsing, or receipt parsing.
 */
final class ProviderOperationProcess {
    public const REQUEST_FORMAT = 'wprism-provider-operation-request/v2';
    public const RECEIPT_FORMAT = 'wprism-provider-operation-response/v2';

    private const MAX_REQUEST_BYTES = 1048576;
    private const MAX_ARGUMENT_BYTES = 524288;
    private const MAX_POLICY_SNAPSHOT_BYTES = 16777216;
    private const MAX_PATH_BYTES = 4096;
    private const MAX_SEMANTIC_RESULT_BYTES = 524288;
    private const STDOUT_BYTES = 786432;
    private const STDERR_BYTES = 262144;
    private const CHILD_COMMAND = 'eval \'\\WPrism\\ProviderOperationProcess::child_main();\'';
    private const OPERATIONS = ['invoke', 'observe'];

    /** @var null|array{request_sha256:string,adapter:string,adapter_digest:string,provider:string,capability:string,operation:string} */
    private static ?array $pendingIdentity = null;
    /** @var null|array{provider:ManifestProviderRuntime,operation:string,capability:string,args_sha256:string,consumed:bool} */
    private static ?array $childAuthority = null;

    /**
     * Prove every selected fresh action fits the fixed transport before any
     * authored mutation. The runtime repeats these bounds at dispatch; this
     * earlier gate is what makes an oversized site-policy argument a plan
     * refusal instead of an ambiguous post-commit provider outcome.
     *
     * @param array<string,mixed> $declaration
     * @param list<array<string,mixed>> $selectedActions
     */
    public static function preflight(array $declaration, array $selectedActions): void {
        $fresh = array_fill_keys(
            array_values(array_filter(
                (array) ($declaration['fresh_process_capabilities'] ?? []),
                'is_string'
            )),
            true
        );
        $selectedFresh = [];
        foreach ($selectedActions as $action) {
            $capability = $action['capability'] ?? null;
            if (($action['kind'] ?? null) === 'provider'
                && ($action['manifest'] ?? null) === ($declaration['manifest'] ?? null)
                && ($action['provider'] ?? null) === ($declaration['id'] ?? null)
                && is_string($capability)
                && isset($fresh[$capability])) {
                $selectedFresh[] = $action;
            }
        }
        if ($selectedFresh === [] || ($declaration['_wprism_execution_bound'] ?? null) !== true) {
            return;
        }

        $snapshot = $declaration['_wprism_policy_snapshot'] ?? null;
        $libraryRoot = $declaration['_wprism_adapter_library_root'] ?? null;
        $executionIdentity = $declaration['_wprism_execution_identity'] ?? null;
        $runtimeState = $declaration['_wprism_plugin_runtime'] ?? null;
        if (!is_array($snapshot)
            || array_is_list($snapshot)
            || !is_string($libraryRoot)
            || $libraryRoot === ''
            || strlen($libraryRoot) > self::MAX_PATH_BYTES
            || !self::valid_execution_identity($executionIdentity)
            || !self::valid_runtime_state($runtimeState)) {
            throw new \RuntimeException(
                'wprism: manifest-provider fresh-process preflight has no bounded artifact descriptor'
            );
        }
        $snapshotBytes = Canon::encode($snapshot);
        if ($snapshotBytes === '' || strlen($snapshotBytes) > self::MAX_POLICY_SNAPSHOT_BYTES) {
            throw new \RuntimeException(
                'wprism: manifest-provider frozen policy exceeds its fixed file handoff boundary'
            );
        }
        $placeholderPath = '/' . str_repeat('p', self::MAX_PATH_BYTES - 1);
        $snapshotDigest = hash('sha256', $snapshotBytes);
        foreach ($selectedFresh as $action) {
            $args = (array) ($action['args'] ?? []);
            if (strlen(Canon::encode($args)) > self::MAX_ARGUMENT_BYTES) {
                throw new \RuntimeException(
                    'wprism: manifest-provider fresh-process action arguments exceed their fixed byte boundary'
                );
            }
            $request = self::request_envelope(
                $declaration,
                (string) $action['capability'],
                $args,
                'observe',
                $placeholderPath,
                $snapshotDigest
            );
            if (strlen(Canon::encode($request)) > self::MAX_REQUEST_BYTES) {
                throw new \RuntimeException(
                    'wprism: manifest-provider fresh-process action exceeds its fixed request boundary'
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $declaration
     * @param array<string,mixed> $args
     * @return array{before:mixed,after:mixed,verified:true}
     */
    public static function invoke(
        ManifestProviderRuntime $provider,
        array $declaration,
        string $capability,
        array $args,
        int $deadlineNanoseconds
    ): array {
        if (self::$childAuthority !== null) {
            throw new \RuntimeException(
                'wprism: manifest-provider fresh process cannot launch recursively from child execution'
            );
        }
        $identity = $provider->identity();
        $adapter = $declaration['manifest'] ?? null;
        $adapterDigest = $declaration['_wprism_adapter_digest'] ?? null;
        $adapterLibraryRoot = $declaration['_wprism_adapter_library_root'] ?? null;
        $executionIdentity = $declaration['_wprism_execution_identity'] ?? null;
        $runtimeState = $declaration['_wprism_plugin_runtime'] ?? null;
        $policySnapshot = $declaration['_wprism_policy_snapshot'] ?? null;
        $providerFile = $declaration['_wprism_provider_file'] ?? null;
        $providerDigest = $declaration['_wprism_provider_sha256'] ?? null;
        $realFile = is_string($providerFile) ? realpath($providerFile) : false;
        $reflectedFile = (new \ReflectionClass($provider))->getFileName();
        $reflectedReal = is_string($reflectedFile) ? realpath($reflectedFile) : false;
        $libraryReal = is_string($adapterLibraryRoot) ? realpath($adapterLibraryRoot) : false;
        if (($declaration['_wprism_execution_bound'] ?? null) !== true
            || !is_string($adapter)
            || preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $adapter) !== 1
            || !is_string($adapterDigest)
            || preg_match('/^[a-f0-9]{64}$/D', $adapterDigest) !== 1
            || !is_string($providerDigest)
            || preg_match('/^[a-f0-9]{64}$/D', $providerDigest) !== 1
            || !is_string($identity['id'] ?? null)
            || ($declaration['id'] ?? null) !== $identity['id']
            || preg_match('/^[a-z0-9_]{1,64}$/D', $capability) !== 1
            || !self::valid_execution_identity($executionIdentity)
            || !self::valid_runtime_state($runtimeState)
            || !is_array($policySnapshot)
            || array_is_list($policySnapshot)
            || $libraryReal === false
            || $libraryReal !== $adapterLibraryRoot
            || strlen($libraryReal) > self::MAX_PATH_BYTES
            || $realFile === false
            || $reflectedReal === false
            || $realFile !== $reflectedReal
            || basename($realFile) !== $identity['id'] . '.php'
            || !is_file($realFile)
            || !hash_equals($providerDigest, (string) hash_file('sha256', $realFile))) {
            throw new \RuntimeException(
                'wprism: manifest-provider fresh process received an invalid artifact-bound source descriptor'
            );
        }

        $snapshotBytes = Canon::encode($policySnapshot);
        if ($snapshotBytes === '' || strlen($snapshotBytes) > self::MAX_POLICY_SNAPSHOT_BYTES) {
            throw new \RuntimeException(
                'wprism: manifest-provider frozen policy exceeds its fixed file handoff boundary'
            );
        }
        if (strlen(Canon::encode($args)) > self::MAX_ARGUMENT_BYTES) {
            throw new \RuntimeException(
                'wprism: manifest-provider fresh-process action arguments exceed their fixed byte boundary'
            );
        }
        $snapshotPath = tempnam(sys_get_temp_dir(), 'wprism-provider-policy-');
        if (!is_string($snapshotPath)
            || !@chmod($snapshotPath, 0600)
            || @file_put_contents($snapshotPath, $snapshotBytes) !== strlen($snapshotBytes)) {
            if (is_string($snapshotPath)) {
                @unlink($snapshotPath);
            }
            throw new \RuntimeException('wprism: manifest-provider could not freeze its validated policy');
        }
        $snapshotReal = realpath($snapshotPath);
        if ($snapshotReal === false
            || $snapshotReal !== $snapshotPath
            || strlen($snapshotReal) > self::MAX_PATH_BYTES) {
            @unlink($snapshotPath);
            throw new \RuntimeException('wprism: manifest-provider frozen policy path is unsafe');
        }
        $request = self::request_envelope(
            $declaration,
            $capability,
            $args,
            'invoke',
            $snapshotReal,
            hash('sha256', $snapshotBytes)
        );
        try {
            $invoked = self::run_operation($request, $deadlineNanoseconds);
            $receipt = $invoked['receipt'] ?? null;
            $claimed = $invoked['postimage'] ?? null;
            if (!is_array($receipt)
                || !is_array($claimed)
                || array_keys($invoked) !== ['postimage', 'receipt']) {
                throw new \RuntimeException(
                    'wprism: manifest-provider mutation child returned malformed evidence; recovery_required'
                );
            }

            $request['operation'] = 'observe';
            $observedResult = self::run_operation($request, $deadlineNanoseconds);
            $observed = $observedResult['postimage'] ?? null;
            if (!is_array($observed)
                || array_keys($observedResult) !== ['postimage']
                || $claimed !== $observed) {
                throw new \RuntimeException(
                    "wprism: manifest-provider '$adapter' capability '$capability' fresh-process receipt "
                    . 'disagrees with independent parent readback (performed in a second fresh child); '
                    . 'recovery_required'
                );
            }
            if (hrtime(true) >= $deadlineNanoseconds) {
                throw new \RuntimeException(
                    'wprism: manifest-provider fresh operation exhausted its total execution/readback deadline; '
                    . 'recovery_required'
                );
            }
        } finally {
            @unlink($snapshotPath);
        }
        /** @var array{before:mixed,after:mixed,verified:true} $receipt */
        return $receipt;
    }

    /** Execute the fixed stdin protocol inside a WP-CLI child. */
    public static function child_main(): void {
        try {
            if (!defined('STDIN') || !is_resource(STDIN)) {
                throw new \RuntimeException('missing child stdin');
            }
            $encoded = stream_get_contents(STDIN, self::MAX_REQUEST_BYTES + 1);
            if (!is_string($encoded)
                || $encoded === ''
                || strlen($encoded) > self::MAX_REQUEST_BYTES
                || !feof(STDIN)) {
                throw new \RuntimeException('invalid child request boundary');
            }
            fwrite(STDOUT, Canon::encode(self::dispatch($encoded)));
        } catch (\Throwable) {
            fwrite(STDERR, "wprism-provider-operation-failed\n");
            throw new \RuntimeException('wprism provider operation child refused');
        }
    }

    /**
     * Closed child dispatcher. The private one-shot authority closes the
     * engine's ordinary call graph and prevents accidental nested execution;
     * it is not a sandbox from arbitrary active-plugin PHP in the same process.
     * The parent transport, watchdog, and independent readback remain the
     * operation boundary.
     *
     * @return array<string,mixed>
     */
    private static function dispatch(string $encoded): array {
        if (self::$childAuthority !== null) {
            throw new \RuntimeException('wprism: provider-operation child attempted nested dispatch');
        }
        try {
            $request = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('wprism: provider-operation request is not canonical JSON', 0, $failure);
        }
        if (!is_array($request)
            || Canon::encode($request) !== $encoded
            || array_keys($request) !== [
                'adapter', 'adapter_digest', 'adapter_library_root', 'args',
                'capability', 'execution_identity', 'format', 'operation',
                'plugin_runtime', 'policy_snapshot_path', 'policy_snapshot_sha256',
                'provider',
            ]) {
            throw new \RuntimeException('wprism: provider-operation request has an invalid envelope');
        }
        $adapter = $request['adapter'];
        $adapterDigest = $request['adapter_digest'];
        $adapterLibraryRoot = $request['adapter_library_root'];
        $args = $request['args'];
        $capability = $request['capability'];
        $executionIdentity = $request['execution_identity'];
        $operation = $request['operation'];
        $runtimeState = $request['plugin_runtime'];
        $policySnapshotPath = $request['policy_snapshot_path'];
        $policySnapshotDigest = $request['policy_snapshot_sha256'];
        $providerId = $request['provider'];
        $libraryReal = is_string($adapterLibraryRoot) ? realpath($adapterLibraryRoot) : false;
        if (($request['format'] ?? null) !== self::REQUEST_FORMAT
            || !is_string($adapter) || preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $adapter) !== 1
            || !is_string($adapterDigest) || preg_match('/^[a-f0-9]{64}$/D', $adapterDigest) !== 1
            || !is_string($providerId) || preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $providerId) !== 1
            || !is_string($capability) || preg_match('/^[a-z0-9_]{1,64}$/D', $capability) !== 1
            || !is_string($operation) || !in_array($operation, self::OPERATIONS, true)
            || !is_array($args) || (array_is_list($args) && $args !== [])
            || strlen(Canon::encode($args)) > self::MAX_ARGUMENT_BYTES
            || !self::valid_execution_identity($executionIdentity)
            || !self::valid_runtime_state($runtimeState)
            || !is_string($policySnapshotPath)
            || $policySnapshotPath === ''
            || str_contains($policySnapshotPath, "\0")
            || strlen($policySnapshotPath) > self::MAX_PATH_BYTES
            || !is_string($policySnapshotDigest)
            || preg_match('/^[a-f0-9]{64}$/D', $policySnapshotDigest) !== 1
            || $libraryReal === false || $libraryReal !== $adapterLibraryRoot) {
            throw new \RuntimeException('wprism: provider-operation request carries malformed fields');
        }

        // A fresh PHP PID is not necessarily a fresh WordPress cache view:
        // persistent object-cache backends outlive both children. Flush before
        // policy/owner replay and again in the observer's independent boot;
        // declared durable option decisions still use ExactOptionReader.
        try {
            $cacheFlushed = function_exists('wp_cache_flush') ? wp_cache_flush() : null;
        } catch (\Throwable) {
            $cacheFlushed = null;
        }
        if ($cacheFlushed !== true) {
            throw new \RuntimeException(
                'wprism: provider-operation child could not establish a fresh WordPress cache view'
            );
        }

        $policySnapshot = self::read_policy_snapshot($policySnapshotPath, $policySnapshotDigest);
        $library = AdapterLibrary::reopenResolvedRoot($libraryReal);
        $policy = Policy::from_snapshot($policySnapshot, $library);
        $provider = Providers::fresh_process_provider(
            $policy,
            $adapter,
            $providerId,
            $capability,
            $adapterDigest,
            $executionIdentity,
            $runtimeState,
            $args
        );
        if (self::$childAuthority !== null) {
            throw new \RuntimeException('wprism: provider-operation child attempted nested dispatch');
        }
        self::$childAuthority = [
            'provider' => $provider,
            'operation' => $operation,
            'capability' => $capability,
            'args_sha256' => hash('sha256', Canon::encode($args)),
            'consumed' => false,
        ];
        try {
            $result = $provider->execute_in_fresh_process($operation, $capability, $args);
        } finally {
            self::$childAuthority = null;
        }
        if (!is_array($result) || array_is_list($result)) {
            throw new \RuntimeException('wprism: provider-operation child returned malformed semantic evidence');
        }
        $response = [
            'adapter' => $adapter,
            'adapter_digest' => $adapterDigest,
            'capability' => $capability,
            'format' => self::RECEIPT_FORMAT,
            'operation' => $operation,
            'provider' => $providerId,
            'request_sha256' => hash('sha256', $encoded),
            'result' => $result,
        ];
        if (strlen(Canon::encode($result)) > self::MAX_SEMANTIC_RESULT_BYTES
            || strlen(Canon::encode($response)) > self::STDOUT_BYTES) {
            throw new \RuntimeException(
                'wprism: provider-operation child semantic evidence exceeds its fixed output boundary'
            );
        }
        return $response;
    }

    /** Consume once but retain executing state until dispatch's finally. */
    public static function consume_child_authority(
        ManifestProviderRuntime $provider,
        string $operation,
        string $capability,
        array $args
    ): bool {
        $authority = self::$childAuthority;
        if ($authority === null
            || $authority['consumed']
            || $authority['provider'] !== $provider
            || $authority['operation'] !== $operation
            || $authority['capability'] !== $capability
            || !hash_equals($authority['args_sha256'], hash('sha256', Canon::encode($args)))) {
            return false;
        }
        self::$childAuthority['consumed'] = true;
        return true;
    }

    /**
     * One closed request constructor shared by preflight and execution.
     *
     * @param array<string,mixed> $declaration
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private static function request_envelope(
        array $declaration,
        string $capability,
        array $args,
        string $operation,
        string $snapshotPath,
        string $snapshotDigest
    ): array {
        return [
            'adapter' => $declaration['manifest'],
            'adapter_digest' => $declaration['_wprism_adapter_digest'],
            'adapter_library_root' => $declaration['_wprism_adapter_library_root'],
            'args' => $args,
            'capability' => $capability,
            'execution_identity' => $declaration['_wprism_execution_identity'],
            'format' => self::REQUEST_FORMAT,
            'operation' => $operation,
            'plugin_runtime' => $declaration['_wprism_plugin_runtime'],
            'policy_snapshot_path' => $snapshotPath,
            'policy_snapshot_sha256' => $snapshotDigest,
            'provider' => $declaration['id'],
        ];
    }

    private static function valid_execution_identity(mixed $identity): bool {
        if (!is_array($identity) || array_is_list($identity)) {
            return false;
        }
        $keys = array_keys($identity);
        sort($keys, SORT_STRING);
        if ($keys !== ['artifact_hash', 'manifest_hash', 'resolved_adapters_sha256', 'site_hash']) {
            return false;
        }
        foreach ($identity as $digest) {
            if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                return false;
            }
        }
        return true;
    }

    private static function valid_runtime_state(mixed $state): bool {
        if (!is_array($state) || array_is_list($state)) {
            return false;
        }
        $keys = array_keys($state);
        sort($keys, SORT_STRING);
        return $keys === ['active', 'installed', 'version']
            && is_bool($state['active'] ?? null)
            && is_bool($state['installed'] ?? null)
            && is_string($state['version'] ?? null);
    }

    /** Identity-only seam reflected by offline transport fakes; never exposes args. */
    private static function pending_identity(): ?array {
        return self::$pendingIdentity;
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private static function run_operation(array $request, int $deadlineNanoseconds): array {
        $encoded = Canon::encode($request);
        if (strlen($encoded) > self::MAX_REQUEST_BYTES) {
            throw new \RuntimeException(
                'wprism: manifest-provider fresh-process request exceeds its fixed byte boundary'
            );
        }
        $remaining = $deadlineNanoseconds - hrtime(true);
        if ($remaining <= 0
            || $remaining
                > ManifestProviderRuntime::FRESH_PROCESS_MAX_TIMEOUT_SECONDS * 1000000000) {
            throw new \RuntimeException(
                'wprism: manifest-provider fresh operation has no bounded deadline remaining; recovery_required'
            );
        }
        // Persistent object-cache state can outlive both PHP PIDs and is
        // consulted during WordPress/plugin bootstrap. The parent must flush
        // before proc_open; a flush inside child_main() is already too late to
        // undo plugins or singleton state selected during that child boot.
        try {
            $cacheFlushed = function_exists('wp_cache_flush') ? wp_cache_flush() : null;
        } catch (\Throwable) {
            $cacheFlushed = null;
        }
        if ($cacheFlushed !== true) {
            throw new \RuntimeException(
                'wprism: manifest-provider fresh process could not establish a pre-boot cache view; '
                . 'recovery_required'
            );
        }
        // The flush consumes the same operation deadline as both children.
        $remaining = $deadlineNanoseconds - hrtime(true);
        if ($remaining <= 0
            || $remaining
                > ManifestProviderRuntime::FRESH_PROCESS_MAX_TIMEOUT_SECONDS * 1000000000) {
            throw new \RuntimeException(
                'wprism: manifest-provider fresh operation has no bounded deadline remaining; recovery_required'
            );
        }
        $requestDigest = hash('sha256', $encoded);
        self::$pendingIdentity = [
            'request_sha256' => $requestDigest,
            'adapter' => $request['adapter'],
            'adapter_digest' => $request['adapter_digest'],
            'provider' => $request['provider'],
            'capability' => $request['capability'],
            'operation' => $request['operation'],
        ];
        try {
            $result = WpCliChildProcess::capture_with_input_until(
                self::CHILD_COMMAND,
                $encoded,
                $deadlineNanoseconds,
                self::STDOUT_BYTES,
                self::STDERR_BYTES
            );
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'wprism: manifest-provider fresh process transport outcome is unknown; recovery_required',
                0,
                $failure
            );
        } finally {
            self::$pendingIdentity = null;
        }
        if ($result['return_code'] !== 0 || $result['stderr'] !== '') {
            throw new \RuntimeException(
                'wprism: manifest-provider fresh process did not complete cleanly; recovery_required'
            );
        }
        try {
            $response = json_decode(trim($result['stdout']), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'wprism: manifest-provider fresh process returned malformed evidence; recovery_required',
                0,
                $failure
            );
        }
        if (!is_array($response)
            || Canon::encode($response) !== $result['stdout']
            || array_keys($response) !== [
                'adapter', 'adapter_digest', 'capability', 'format', 'operation',
                'provider', 'request_sha256', 'result',
            ]
            || $response['format'] !== self::RECEIPT_FORMAT
            || $response['request_sha256'] !== $requestDigest
            || $response['adapter'] !== $request['adapter']
            || $response['adapter_digest'] !== $request['adapter_digest']
            || $response['provider'] !== $request['provider']
            || $response['capability'] !== $request['capability']
            || $response['operation'] !== $request['operation']
            || !is_array($response['result'])
            || array_is_list($response['result'])) {
            throw new \RuntimeException(
                'wprism: manifest-provider fresh process returned identity-mismatched evidence; recovery_required'
            );
        }
        return $response['result'];
    }

    /** @return array<string,mixed> */
    private static function read_policy_snapshot(string $path, string $expectedDigest): array {
        $pathStat = @lstat($path);
        $handle = !is_link($path) ? @fopen($path, 'rb') : false;
        $opened = is_resource($handle) ? @fstat($handle) : false;
        $size = is_array($opened) ? ($opened['size'] ?? null) : null;
        if (!is_array($pathStat)
            || !is_array($opened)
            || !is_file($path)
            || realpath($path) !== $path
            || (($opened['mode'] ?? 0) & 0170000) !== 0100000
            || (($opened['mode'] ?? 0) & 0777) !== 0600
            || ($pathStat['dev'] ?? null) !== ($opened['dev'] ?? null)
            || ($pathStat['ino'] ?? null) !== ($opened['ino'] ?? null)
            || !is_int($size)
            || $size < 1
            || $size > self::MAX_POLICY_SNAPSHOT_BYTES) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException('wprism: provider-operation frozen policy file is unsafe');
        }
        $bytes = stream_get_contents($handle, self::MAX_POLICY_SNAPSHOT_BYTES + 1);
        $readStat = @fstat($handle);
        fclose($handle);
        $pathAfter = @lstat($path);
        if (!is_string($bytes)
            || strlen($bytes) !== $size
            || !is_array($readStat)
            || !is_array($pathAfter)
            || ($readStat['dev'] ?? null) !== ($opened['dev'] ?? null)
            || ($readStat['ino'] ?? null) !== ($opened['ino'] ?? null)
            || ($readStat['size'] ?? null) !== $size
            || ($pathAfter['dev'] ?? null) !== ($opened['dev'] ?? null)
            || ($pathAfter['ino'] ?? null) !== ($opened['ino'] ?? null)
            || !hash_equals($expectedDigest, hash('sha256', $bytes))
            || realpath($path) !== $path) {
            throw new \RuntimeException('wprism: provider-operation frozen policy identity changed');
        }
        try {
            $snapshot = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('wprism: provider-operation frozen policy is malformed', 0, $failure);
        }
        if (!is_array($snapshot)
            || array_is_list($snapshot)
            || Canon::encode($snapshot) !== $bytes) {
            throw new \RuntimeException('wprism: provider-operation frozen policy is not canonical');
        }
        return $snapshot;
    }
}

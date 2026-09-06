<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/../Kernel/WpCliChildProcess.php';

/**
 * Engine-owned protocol shell for manifest-shipped provider behavior.
 *
 * A manifest-provider file still owns the plugin-specific calls and the
 * value-level postcondition that makes them safe. Identity, capability
 * advertising, dispatch, scoped receipt construction, and recovery routing
 * are invariant protocol mechanics, so a v3 manifest may declare those bytes
 * once under providers[].contracts and let this runtime enforce them.
 *
 * The feature is opt-in because v2 manifest providers remain independent
 * duck-typed provider objects. Plugin-sourced providers also keep that public
 * contract: their independently shipped code must advertise its own identity
 * and capabilities for negotiation. This class is only the smaller execution
 * seam for code whose identity bytes already ship beside the manifest.
 */
abstract class ManifestProviderRuntime {
    public const FEATURE = 'manifest-provider-runtime/v1';
    public const FRESH_PROCESS_FEATURE = 'manifest-provider-fresh-process/v1';
    public const FRESH_PROCESS_MAX_TIMEOUT_SECONDS = WpCliChildProcess::MAX_TIMEOUT_SECONDS;

    /** @var array<string,mixed> */
    private array $declaration;

    /**
     * One engine-bound, digest-validated handler owns the provider SDK contract
     * at a time.
     *
     * PHP provider execution is synchronous. A process-global slot therefore
     * makes a nested provider invocation a refusal instead of letting its
     * declaration replace the table authority still in use by the outer
     * handler.
     *
     * An unbound object may still execute its ordinary PHP behavior (package
     * tests do this), but its null contract cannot authorize any ProviderSdk
     * database scope. The separate occupied slot preserves non-reentrancy for
     * both engine-loaded and directly constructed runtimes.
     *
     * @var null|array{provider:self,capability:string,contract:?array<string,mixed>}
     */
    private static ?array $activeInvocation = null;

    /**
     * @param array<string,mixed> $declaration one validated providers[] row
     */
    final public function __construct(array $declaration) {
        $id = $declaration['id'] ?? null;
        $plugin = $declaration['plugin'] ?? null;
        $version = $declaration['version'] ?? null;
        $contracts = $declaration['contracts'] ?? null;
        if (($declaration['source'] ?? null) !== 'manifest'
            || !is_string($id) || preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $id) !== 1
            || !is_string($plugin) || $plugin === ''
            || !is_string($version) || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version) !== 1
            || !is_array($contracts) || $contracts === [] || array_is_list($contracts)) {
            throw new \RuntimeException(
                'wprism: manifest-provider runtime requires one validated source: manifest declaration with contracts'
            );
        }

        $declared = $declaration['capabilities'] ?? null;
        if (!is_array($declared) || !array_is_list($declared)
            || $declared !== array_keys($contracts)) {
            throw new \RuntimeException(
                "wprism: manifest-provider '$id' contracts must exactly follow its declared capability list"
            );
        }

        foreach ($contracts as $capability => $contract) {
            if (!is_string($capability)
                || preg_match('/^[a-z0-9_]{1,64}$/D', $capability) !== 1
                || !is_array($contract)) {
                throw new \RuntimeException(
                    "wprism: manifest-provider '$id' carries a malformed capability contract"
                );
            }
            $this->assertHandler($id, $capability, 'invoke');
            if (($contract['scoped']['reconcile'] ?? null) === true) {
                $this->assertHandler($id, $capability, 'reconcile');
            }
            if (($contract['scoped']['receipt_projection'] ?? null) === 'handler') {
                $this->assertHandler($id, $capability, 'project');
            }
        }

        $fresh = $declaration['fresh_process_capabilities'] ?? [];
        if (!is_array($fresh) || !array_is_list($fresh)) {
            throw new \RuntimeException(
                "wprism: manifest-provider '$id' fresh_process_capabilities must be a list"
            );
        }
        if ($fresh !== []) {
            $adapterDigest = $declaration['_wprism_adapter_digest'] ?? null;
            $adapterLibraryRoot = $declaration['_wprism_adapter_library_root'] ?? null;
            $executionBound = $declaration['_wprism_execution_bound'] ?? null;
            $executionIdentity = $declaration['_wprism_execution_identity'] ?? null;
            $pluginRuntime = $declaration['_wprism_plugin_runtime'] ?? null;
            $policySnapshot = $declaration['_wprism_policy_snapshot'] ?? null;
            $providerFile = $declaration['_wprism_provider_file'] ?? null;
            $providerDigest = $declaration['_wprism_provider_sha256'] ?? null;
            if (!is_string($adapterDigest)
                || preg_match('/^[a-f0-9]{64}$/D', $adapterDigest) !== 1
                || !is_string($adapterLibraryRoot)
                || $adapterLibraryRoot === ''
                || str_contains($adapterLibraryRoot, "\0")
                || !is_bool($executionBound)
                || ($executionBound && (!is_array($executionIdentity) || array_is_list($executionIdentity)))
                || !is_array($pluginRuntime)
                || array_is_list($pluginRuntime)
                || !is_bool($pluginRuntime['installed'] ?? null)
                || !is_bool($pluginRuntime['active'] ?? null)
                || !is_string($pluginRuntime['version'] ?? null)
                || !is_array($policySnapshot)
                || array_is_list($policySnapshot)
                || !is_string($providerFile)
                || $providerFile === ''
                || !is_string($providerDigest)
                || preg_match('/^[a-f0-9]{64}$/D', $providerDigest) !== 1) {
                throw new \RuntimeException(
                    "wprism: manifest-provider '$id' fresh process requires a frozen policy and digest-bound source"
                );
            }
            $seen = [];
            foreach ($fresh as $capability) {
                if (!is_string($capability)
                    || !array_key_exists($capability, $contracts)
                    || isset($seen[$capability])) {
                    throw new \RuntimeException(
                        "wprism: manifest-provider '$id' carries an invalid fresh-process capability"
                    );
                }
                $seen[$capability] = true;
                $contract = $contracts[$capability];
                if (($contract['scope'] ?? null) !== 'site'
                    || ($contract['idempotent'] ?? null) !== true
                    || !is_int($contract['timeout_seconds'] ?? null)
                    || $contract['timeout_seconds'] < 1
                    || $contract['timeout_seconds'] > self::FRESH_PROCESS_MAX_TIMEOUT_SECONDS) {
                    throw new \RuntimeException(
                        "wprism: manifest-provider '$id' fresh-process capability '$capability' requires "
                        . 'scope: site, idempotent: true, and timeout_seconds within the bounded child-process ceiling'
                    );
                }
                $this->assertHandler($id, $capability, 'observe_fresh_postimage');
                $this->assertHandler($id, $capability, 'project_fresh_postimage');
            }
        }

        $this->declaration = $declaration;
    }

    /** @return array{id:string,plugin:string,version:string} */
    final public function identity(): array {
        return [
            'id' => $this->declaration['id'],
            'plugin' => $this->declaration['plugin'],
            'version' => $this->declaration['version'],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    final public function capabilities(): array {
        return $this->declaration['contracts'];
    }

    /** @param array<string,mixed> $args */
    final public function invoke(string $capability, array $args): array {
        $contract = $this->contract($capability);
        if (in_array($capability, $this->fresh_process_capabilities(), true)) {
            return $this->invoke_with_deadline(
                $capability,
                $args,
                hrtime(true) + ((int) $contract['timeout_seconds'] * 1000000000)
            );
        }
        return $this->invokeDirect($capability, $args);
    }

    /** True only for capabilities whose whole execution belongs to the engine child boundary. */
    final public function uses_fresh_process(string $capability): bool {
        $this->contract($capability);
        return in_array($capability, $this->fresh_process_capabilities(), true);
    }

    /** @param array<string,mixed> $args */
    final public function invoke_with_deadline(
        string $capability,
        array $args,
        int $deadlineNanoseconds
    ): array {
        if (!$this->uses_fresh_process($capability)) {
            return $this->invokeDirect($capability, $args);
        }
        if (($this->declaration['_wprism_execution_bound'] ?? null) !== true) {
            throw new \RuntimeException(
                "wprism: manifest-provider '{$this->declaration['id']}' fresh execution is not bound to a validated compiled artifact"
            );
        }
        if (!class_exists(ProviderOperationProcess::class, false)) {
            require_once __DIR__ . '/ProviderOperationProcess.php';
        }
        $receipt = ProviderOperationProcess::invoke(
            $this,
            $this->declaration,
            $capability,
            $args,
            $deadlineNanoseconds
        );
        $this->assertReceipt($capability, $receipt);
        return $receipt;
    }

    /**
     * Consume one engine child authority and run either mutation or readback.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    final public function execute_in_fresh_process(
        string $operation,
        string $capability,
        array $args
    ): array {
        if (!in_array($capability, $this->fresh_process_capabilities(), true)
            || !class_exists(ProviderOperationProcess::class, false)
            || !ProviderOperationProcess::consume_child_authority($this, $operation, $capability, $args)) {
            throw new \RuntimeException(
                'wprism: manifest-provider fresh-process handler has no engine child authority'
            );
        }
        if ($operation === 'invoke') {
            $receipt = $this->invokeDirect($capability, $args);
            if (!is_array($receipt['after'])) {
                throw new \RuntimeException(
                    "wprism: manifest-provider '{$this->declaration['id']}' capability '$capability' "
                    . 'fresh-process receipt has no projectable postimage; recovery_required'
                );
            }
            return [
                'postimage' => $this->projectFreshPostimage($capability, $receipt['after']),
                'receipt' => $receipt,
            ];
        }
        if ($operation === 'observe') {
            $id = $this->declaration['id'];
            $this->contract($capability);
            $this->beginContractInvocation($capability);
            try {
                if (!class_exists(ProviderSdk::class, false)) {
                    require_once __DIR__ . '/ProviderSdk.php';
                }
                return ProviderSdk::database_read_contract_snapshot(
                    "manifest-provider '$id' capability '$capability' fresh observation",
                    function () use ($capability, $args): array {
                        return [
                            'postimage' => $this->projectFreshPostimage(
                                $capability,
                                $this->observeFreshPostimage($capability, $args)
                            ),
                        ];
                    }
                );
            } finally {
                $this->endContractInvocation();
            }
        }
        throw new \RuntimeException('wprism: manifest-provider child operation is unknown');
    }

    /** @param array<string,mixed> $args */
    private function invokeDirect(string $capability, array $args): array {
        $this->contract($capability);
        $this->beginContractInvocation($capability);
        try {
            $method = 'invoke_' . $capability;
            /** @var array<string,mixed> $receipt */
            $receipt = $this->{$method}($args);
            $this->assertReceipt($capability, $receipt);
            return $receipt;
        } finally {
            $this->endContractInvocation();
        }
    }

    /** @param array<string,mixed> $receipt */
    private function assertReceipt(string $capability, array $receipt): void {
        if (!array_key_exists('before', $receipt)
            || !array_key_exists('after', $receipt)
            || ($receipt['verified'] ?? null) !== true) {
            throw new \RuntimeException(
                "wprism: manifest-provider '{$this->declaration['id']}' capability '$capability' "
                . 'did not return before, after, and verified: true'
            );
        }
    }

    /** @param array<string,mixed> $args @param array<string,mixed> $operation */
    final public function invoke_scoped(string $capability, array $args, array $operation): array {
        $contract = $this->contract($capability);
        $receipt = $this->invoke($capability, $args);
        $after = (($contract['scoped']['invoke_after'] ?? 'receipt') === 'reconcile')
            ? $this->reconcile($capability, $args)
            : $receipt['after'];
        return [
            'operation' => $operation,
            'before' => $this->project($capability, $receipt['before']),
            'after' => $this->project($capability, $after),
            'verified' => true,
        ];
    }

    /** @param array<string,mixed> $args @param array<string,mixed> $operation */
    final public function reconcile_scoped(string $capability, array $args, array $operation): array {
        $this->contract($capability);
        return [
            'operation' => $operation,
            'after' => $this->project($capability, $this->reconcile($capability, $args)),
            'verified' => true,
        ];
    }

    /** @return array<string,mixed> */
    private function contract(string $capability): array {
        $contract = $this->declaration['contracts'][$capability] ?? null;
        if (!is_array($contract)) {
            throw new \RuntimeException(
                "wprism: manifest-provider '{$this->declaration['id']}' does not implement capability '$capability'"
            );
        }
        return $contract;
    }

    /** @param array<string,mixed> $args @return array<string,mixed> */
    private function reconcile(string $capability, array $args): array {
        $contract = $this->contract($capability);
        if (($contract['scoped']['reconcile'] ?? null) !== true) {
            throw new \RuntimeException(
                "wprism: manifest-provider '{$this->declaration['id']}' capability '$capability' "
                . 'does not declare scoped reconciliation'
            );
        }
        $this->beginContractInvocation($capability);
        try {
            $method = 'reconcile_' . $capability;
            /** @var array<string,mixed> $after */
            $after = $this->{$method}($args);
            return $after;
        } finally {
            $this->endContractInvocation();
        }
    }

    private function project(string $capability, mixed $value): mixed {
        $contract = $this->contract($capability);
        if (($contract['scoped']['receipt_projection'] ?? null) !== 'handler') {
            return $value;
        }
        $method = 'project_' . $capability;
        return $this->{$method}($value);
    }

    /** @param array<string,mixed> $args @return array<string,mixed> */
    private function observeFreshPostimage(string $capability, array $args): array {
        $method = 'observe_fresh_postimage_' . $capability;
        /** @var array<string,mixed> $postimage */
        $postimage = $this->{$method}($args);
        return $postimage;
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function projectFreshPostimage(string $capability, array $value): array {
        $method = 'project_fresh_postimage_' . $capability;
        /** @var array<string,mixed> $postimage */
        $postimage = $this->{$method}($value);
        return $postimage;
    }

    /** @return list<string> */
    private function fresh_process_capabilities(): array {
        return (array) ($this->declaration['fresh_process_capabilities'] ?? []);
    }

    /** @return array<string,mixed> */
    final public static function active_validated_contract(string $context): array {
        if (self::$activeInvocation === null
            || !is_array(self::$activeInvocation['contract'])) {
            throw new \RuntimeException(
                "wprism: $context requires an active validated manifest-provider contract"
            );
        }
        return self::$activeInvocation['contract'];
    }

    private function beginContractInvocation(string $capability): void {
        if (self::$activeInvocation !== null) {
            throw new \RuntimeException(
                'wprism: manifest-provider handlers cannot re-enter an active capability contract'
            );
        }
        if (!class_exists(Providers::class, false)) {
            require_once __DIR__ . '/Providers.php';
        }
        // Partial-load package tests historically define a minimal Providers
        // double for protocol constants. Such a class is not the engine and
        // therefore grants no SDK authority; requiring a second definition is
        // impossible, while treating it as unbound is the fail-closed result.
        $contract = method_exists(Providers::class, 'bound_manifest_runtime_contract')
            ? Providers::bound_manifest_runtime_contract($this, $capability)
            : null;
        self::$activeInvocation = [
            'provider' => $this,
            'capability' => $capability,
            'contract' => $contract,
        ];
    }

    private function endContractInvocation(): void {
        self::$activeInvocation = null;
    }

    private function assertHandler(string $id, string $capability, string $phase): void {
        $method = $phase . '_' . $capability;
        if (!method_exists($this, $method)) {
            throw new \RuntimeException(
                "wprism: manifest-provider '$id' capability '$capability' requires protected $method(array): array"
            );
        }
        $reflection = new \ReflectionMethod($this, $method);
        $parameters = $reflection->getParameters();
        $parameterType = count($parameters) === 1 ? $parameters[0]->getType() : null;
        $returnType = $reflection->getReturnType();
        if (!$reflection->isProtected()
            || $reflection->isStatic()
            || count($parameters) !== 1
            || $parameters[0]->isVariadic()
            || !$parameterType instanceof \ReflectionNamedType
            || $parameterType->getName() !== 'array'
            || !$returnType instanceof \ReflectionNamedType
            || $returnType->getName() !== 'array') {
            throw new \RuntimeException(
                "wprism: manifest-provider '$id' capability '$capability' requires protected $method(array): array"
            );
        }
    }
}

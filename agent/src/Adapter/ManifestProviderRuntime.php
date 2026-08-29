<?php
declare(strict_types=1);

namespace WPrism;

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

    /** @var array<string,mixed> */
    private array $declaration;

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
        $this->contract($capability);
        $method = 'invoke_' . $capability;
        /** @var array<string,mixed> $receipt */
        $receipt = $this->{$method}($args);
        if (!array_key_exists('before', $receipt)
            || !array_key_exists('after', $receipt)
            || ($receipt['verified'] ?? null) !== true) {
            throw new \RuntimeException(
                "wprism: manifest-provider '{$this->declaration['id']}' capability '$capability' "
                . 'did not return before, after, and verified: true'
            );
        }
        return $receipt;
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
        $method = 'reconcile_' . $capability;
        /** @var array<string,mixed> $after */
        $after = $this->{$method}($args);
        return $after;
    }

    private function project(string $capability, mixed $value): mixed {
        $contract = $this->contract($capability);
        if (($contract['scoped']['receipt_projection'] ?? null) !== 'handler') {
            return $value;
        }
        $method = 'project_' . $capability;
        return $this->{$method}($value);
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

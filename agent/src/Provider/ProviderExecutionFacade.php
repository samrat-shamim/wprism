<?php
declare(strict_types=1);

namespace Duo\Provider;

require_once __DIR__ . '/ProviderReceiptBoundary.php';
require_once __DIR__ . '/ProviderStateBoundary.php';

/**
 * Provider invocation boundary consumed by mutation/recovery code.
 * Negotiation remains a separate facade so target facts cannot enter policy
 * loading and invocation is never reachable from an offline query object.
 */
final class ProviderExecutionFacade {
    /** @return array<string,mixed> */
    public static function invoke(
        object $provider,
        array $action,
        array $capability,
        array $entities,
        array $context = []
    ): array {
        return \Duo\Providers::invoke($provider, $action, $capability, $entities, $context);
    }

    /** @return array<string,mixed> */
    public static function invokeScoped(
        object $provider,
        array $action,
        array $capability,
        array $operation,
        array $entities = [],
        array $context = []
    ): array {
        return \Duo\Providers::invoke_scoped($provider, $action, $capability, $operation, $entities, $context);
    }

    /** @return array<string,mixed> */
    public static function reconcileScoped(
        object $provider,
        array $action,
        array $capability,
        array $operation,
        array $entities = [],
        array $context = []
    ): array {
        return \Duo\Providers::reconcile_scoped($provider, $action, $capability, $operation, $entities, $context);
    }

    /** @return array<string,mixed> */
    public static function validateScopedOperation(array $operation): array {
        return \Duo\Providers::validate_scoped_operation($operation);
    }

    /** @return array<string,mixed> */
    public static function boundReceipt(array $receipt, string $providerId, string $capability): array {
        return ProviderReceiptBoundary::publish($receipt, $providerId, $capability);
    }

    public static function scopedInputHash(
        array $action,
        array $capability,
        array $entities = [],
        array $context = []
    ): string {
        return \Duo\Providers::scoped_input_hash($action, $capability, $entities, $context);
    }

    public static function scopedCapabilityDigest(
        string $providerId,
        string $capability,
        array $declaration
    ): string {
        return \Duo\Providers::scoped_capability_digest($providerId, $capability, $declaration);
    }

    public static function scopedEvidenceDigest(mixed $evidence): string {
        return \Duo\Providers::scoped_evidence_digest($evidence);
    }

    /** @return array<string,mixed> */
    public static function operationState(string $owner, string $operation, array $envelope): array {
        return ProviderStateBoundary::read($owner, $operation, $envelope);
    }

    public static function beginOperation(string $owner, string $operation, array $envelope): void {
        ProviderStateBoundary::begin($owner, $operation, $envelope);
    }

    /** @return array{status:string,before_hash:string,after_hash:string} */
    public static function completeOperation(
        string $owner,
        string $operation,
        array $envelope,
        mixed $before,
        mixed $after
    ): array {
        return ProviderStateBoundary::complete($owner, $operation, $envelope, $before, $after);
    }
}

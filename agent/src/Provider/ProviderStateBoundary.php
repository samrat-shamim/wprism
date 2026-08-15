<?php
declare(strict_types=1);

namespace Duo\Provider;

require_once dirname(__DIR__) . '/Providers.php';

/**
 * Narrow durable-state boundary for provider-scoped operation receipts.
 * Ordering and multi-provider workflow ownership remain outside this class.
 */
final class ProviderStateBoundary {
    public static function begin(string $owner, string $operationName, array $operation): void {
        \Duo\Providers::begin_scoped_operation($owner, $operationName, $operation);
    }

    /** @return array{status:string,before_hash:string,after_hash:string} */
    public static function complete(
        string $owner,
        string $operationName,
        array $operation,
        mixed $before,
        mixed $after
    ): array {
        return \Duo\Providers::complete_scoped_operation(
            $owner,
            $operationName,
            $operation,
            $before,
            $after
        );
    }

    /** @return array<string,mixed> */
    public static function read(string $owner, string $operationName, array $operation): array {
        return \Duo\Providers::scoped_operation_state($owner, $operationName, $operation);
    }
}

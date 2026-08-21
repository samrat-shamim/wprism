<?php
declare(strict_types=1);

namespace Duo\Cloud;

/** A host authority was healthy enough to reach its fail-fast writer lock. */
final class HostAuthorityBusy extends \RuntimeException {
    public const EXIT_STATUS = 75;
    public const FIREWALL_AUTHORITY_STDERR = "duo-cloud-firewall-authority: busy\n";
    public const FIREWALL_CLIENT_STDERR = "duo-cloud-firewall-client: busy\n";
    public const ROUTE_AUTHORITY_STDERR = "duo-cloud-route-authority: busy\n";
    public const STORAGE_AUTHORITY_STDERR = "duo-cloud-storage-authority: busy\n";
    public const STORAGE_CLIENT_STDERR = "duo-cloud-storage-client: busy\n";

    /** @param array{exit:int,stderr:string,stdout:string} $result */
    public static function matchesProcessResult(array $result, string $stderr): bool {
        return $result['exit'] === self::EXIT_STATUS
            && $result['stdout'] === ''
            && $result['stderr'] === $stderr;
    }
}

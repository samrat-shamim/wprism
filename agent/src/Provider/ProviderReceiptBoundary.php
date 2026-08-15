<?php
declare(strict_types=1);

namespace Duo\Provider;

require_once dirname(__DIR__) . '/Providers.php';

/** Single receipt projection/redaction seam for provider output. */
final class ProviderReceiptBoundary {
    /** @return array<string,mixed> */
    public static function publish(array $receipt, string $providerId, string $capability): array {
        return \Duo\Providers::bound_receipt($receipt, $providerId, $capability);
    }
}

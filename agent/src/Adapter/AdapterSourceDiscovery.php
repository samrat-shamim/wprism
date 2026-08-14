<?php
declare(strict_types=1);

namespace Duo\Adapter;

require_once __DIR__ . '/AdapterCatalog.php';
require_once dirname(__DIR__) . '/AdapterSources.php';

/** Inert source-discovery boundary; no provider/interpreter code is loaded. */
final class AdapterSourceDiscovery {
    public static function discover(string $manifestDir, ?string $repo): AdapterCatalog {
        return AdapterCatalog::fromSources(\Duo\AdapterSources::discover($manifestDir, $repo));
    }

    /** @return array<string,mixed> */
    public static function survey(?string $repo): array {
        return \Duo\AdapterSources::survey($repo);
    }
}

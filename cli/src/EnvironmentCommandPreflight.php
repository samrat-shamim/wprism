<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/** Target-free registry, transport, and capability preflight for host commands. */
final class EnvironmentCommandPreflight {
    /** @var list<string> */
    private const ENVIRONMENT_VERBS = [
        'doctor', 'driver-capabilities', 'adopt', 'init', 'status', 'capabilities',
        'adapter-observe', 'capture', 'plan', 'explain', 'apply', 'deploy', 'env-set',
        'promote', 'pending', 'classify', 'coverage', 'scope', 'refresh', 'rebase',
    ];

    public static function requiresEnvironment(string $verb): bool {
        return in_array($verb, self::ENVIRONMENT_VERBS, true);
    }

    /** @return list<string> */
    public static function environmentVerbs(): array {
        return self::ENVIRONMENT_VERBS;
    }

    public static function resolveTransport(?string $envsFileOverride, string $startDir, string $environment): Transport {
        $envs = Registry::load($envsFileOverride, $startDir);
        return Transport::make($environment, Registry::get($envs, $environment));
    }

    public static function capabilityReport(EnvironmentDriver $driver, string $operation): DriverCapabilityReport {
        return $driver->capabilityReport($operation);
    }
}

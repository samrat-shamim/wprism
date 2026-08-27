<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/** Target-free registry, transport, and capability preflight for host commands. */
final class EnvironmentCommandPreflight {
    /** @var list<string> */
    private const ENVIRONMENT_VERBS = [
        'doctor', 'driver-capabilities', 'adopt', 'onboard', 'init', 'status', 'capabilities',
        'adapter-observe', 'capture', 'lint', 'plan', 'explain', 'apply', 'deploy', 'env-set',
        'promote', 'pending', 'classify', 'coverage', 'scope', 'refresh', 'rebase',
        // Round-3 MUP §2.1/§2.6. Both are environment-bound because both
        // take <env>: assess reads that target, and every contract
        // subcommand except `show` re-runs an assessment against it. `show`
        // reads only committed files, but registering the verb per-
        // subcommand would make one word of `duo contract` resolve its
        // environment and another not — a difference an operator would
        // discover as an inconsistent error message rather than a feature.
        'assess', 'contract',
        // Round-3 MUP §2.2-§2.5. All four take <env> and all four reach the
        // target: release drives the promote path, verify re-reads it,
        // recover drives the rollback authority runtime on it, and rehearse
        // materializes into it. Appended in MUP's own section order so this
        // list reads as the document does; `regress_environment_command_preflight.php`
        // pins the order as a two-sided ratchet.
        'release', 'verify', 'recover', 'rehearse',
        // DUO-3499. `code-classify` is environment-bound for the same reason
        // `assess` is: it takes <env> and asks that target for its own code
        // inventory, so the trees it stops tracking are provably the trees
        // that target compiles.
        'code-classify',
        // DUO-3500. `code-resolve` is environment-bound because <env> is what
        // decides WHERE the bytes go: the transport says whether the host can
        // reach the repository at all, and `repo_path` is the local one for a
        // local environment. It runs no target command on the path it
        // supports, which is why its capability requirement is attach alone
        // (cli/src/Transport/EnvironmentDriver.php).
        'code-resolve',
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

<?php
declare(strict_types=1);

namespace Duo\Provider;

require_once dirname(__DIR__) . '/Providers.php';
require_once __DIR__ . '/ProviderCatalog.php';

/**
 * Read-only provider negotiation boundary. It never invokes a capability.
 */
final class ProviderNegotiationFacade {
    public function __construct(private readonly \Duo\Policy $policy) {
    }

    public function catalog(): ProviderCatalog {
        return ProviderCatalog::fromPolicy($this->policy);
    }

    /** @return array<string,mixed> */
    public function negotiate(
        array $selectedActions,
        ?\Duo\TargetRuntimeInspectionPort $runtime = null
    ): array {
        return \Duo\Providers::negotiate($this->policy, $selectedActions, $runtime);
    }

    /** @return array<string,mixed> */
    public function negotiateScoped(
        array $selectedActions,
        ?\Duo\TargetRuntimeInspectionPort $runtime = null
    ): array {
        return \Duo\Providers::negotiate_scoped($this->policy, $selectedActions, $runtime);
    }

    /** @return list<array<string,mixed>> */
    public function packagingProblems(array $selectedActions): array {
        return \Duo\Providers::packaging_problems($this->policy, $selectedActions);
    }

    /** @return array<string,mixed> */
    public function packagingProblem(\Duo\ProviderPackagingException $failure): array {
        return \Duo\Providers::packaging_problem($failure);
    }

    /** @return array<string,mixed> */
    public function diagnose(
        array $selectedActions,
        ?\Duo\TargetRuntimeInspectionPort $runtime = null
    ): array {
        return \Duo\Providers::diagnose($this->policy, $selectedActions, $runtime);
    }

    /** @return list<array<string,mixed>> */
    public function problems(array $gating = []): array {
        return \Duo\Providers::problems($this->policy, $gating);
    }

    public function declaresChannel(array $declaration, string $channel): bool {
        return \Duo\Providers::declares_channel($declaration, $channel);
    }

    public function runtimeAvailable(?\Duo\TargetRuntimeInspectionPort $runtime = null): bool {
        return \Duo\Providers::runtime_negotiation_available($runtime);
    }
}

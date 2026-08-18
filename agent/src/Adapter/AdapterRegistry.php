<?php
namespace Duo;

require_once __DIR__ . '/CapabilityRegistry.php';

/**
 * Adapter provenance and capability-readiness resolution, extracted from
 * Policy (DUO-3348 slice 4): the source/evidence view of which pinned
 * adapters are usable, and the provider-negotiation view of which selected
 * actions can actually run, over the manifests/dispositions/registry a
 * Policy has already resolved.
 *
 * Extracted from Policy: the constructor's three fields — the owning Policy
 * itself, plus its already-resolved manifestDispositions/capabilityRegistry —
 * are exactly what this cluster needs. The Policy field is a back-reference,
 * not a narrow slice: methods below also reach `$policy->manifests`,
 * `$policy->actions()`, and `$policy->adapter_sources()` through it, and
 * `provider_readiness_blockers()` passes it on to `Providers::negotiate()`/
 * `packaging_problems()`, which are strictly typed to `Policy` and would
 * reject anything else. `Policy::adapter_sources()` deliberately stays on
 * Policy itself rather than moving here — it owns a lazily-cached field
 * (`$this->adapterSources ??= AdapterSources::discover(...)`) that must keep
 * observing and caching on the SAME Policy instance across repeated calls;
 * this class is constructed fresh per facade call (cheap, no state of its
 * own to lose — every field here is set once at Policy's own load()/
 * from_snapshot() time and never mutated), so caching a lazy fallback
 * locally would silently stop matching a policy built by an offline test
 * harness that never ran either. Every method below reaches adapter
 * provenance via `$this->policy->adapter_sources()` instead.
 *
 * Deliberately does not require_once Policy.php: every reference to Policy
 * here is either the constructor's own type hint or an instance method call
 * on the $policy this class was handed — never a `Policy::` static call —
 * so, like ConvergenceVerifier's identical relationship to its own $policy
 * field (DUO-3347/DUO-3441), no fresh-process load of this file alone can
 * reach a Policy-class-not-found fatal through it. CapabilityRegistry IS
 * required below because `CapabilityRegistry::probe_target()` is a genuine
 * static call this file makes on its own — and because CapabilityRegistry.php
 * itself require_once's AdapterSources.php, that one require also covers
 * this file's own `AdapterSources::SHIPPED` reference in
 * certification_readiness_blockers() below; a future edit that drops the
 * CapabilityRegistry require without noticing this would reopen exactly the
 * standalone-load gap DUO-3440/DUO-3441/DUO-3442 each fixed one file at a
 * time.
 */
final class AdapterRegistry {
    public function __construct(
        private readonly Policy $policy,
        private readonly ?ManifestDispositions $manifestDispositions,
        private readonly ?CapabilityRegistry $capabilityRegistry,
    ) {
    }

    /**
     * The reviewed support boundary for one pinned adapter, if this library has
     * a registry. An out-of-tree adapter has no reviewed entry and never
     * acquires one: it answers with the synthesized provenance record instead,
     * so the disposition slot that feeds RepositoryCompiler::manifest_rows()
     * binds its origin into the adapter digest exactly where a reviewed entry
     * would sit — and every shipped row keeps hashing the bytes it always did.
     */
    public function manifest_disposition(string $name): ?array {
        return $this->policy->adapter_sources()->provenance($name) ?? $this->manifestDispositions?->entry($name);
    }

    /**
     * The generated evidence-bound claim for one pinned adapter.
     *
     * An out-of-tree adapter (site or plugin source, including a site copy
     * that overrides a shipped name) has exactly the claim its own verified
     * certificate projects, or none: it never borrows the registry's entry
     * for the same NAME. That fallback made an uncertified override read the
     * shipped adapter's certified claim (walk S4: `Ready` beside
     * `Uncertified` on post_type:product).
     */
    public function capability_claim(string $name): ?array {
        $sources = $this->policy->adapter_sources();
        if ($sources->is_out_of_tree($name)) {
            return $sources->claim($name);
        }

        return $this->capabilityRegistry?->claim($name);
    }

    /**
     * Certification/source-only blockers for a pinned adapter set.
     *
     * This intentionally does not contact provider code. Apply::build_plan()
     * starts with this stable source/evidence view, then appends provider
     * problems for only the actions its own work/deletion surfaces selected.
     * Calling the global provider view here would turn an unrelated or empty
     * plan into a blocker for a declaration it cannot execute on that plan.
     */
    public function certification_readiness_blockers(): array {
        if ($this->manifestDispositions === null) {
            return [];
        }
        if ($this->capabilityRegistry === null) {
            return [[
                'name' => 'registry',
                'status' => 'unsupported',
                'code' => 'missing_capability_registry',
                'reason' => 'manifest dispositions exist but the generated capability registry is absent',
                // DUO-3339: every field the two blocker renderers print now
                // rides on the row, because both of them used to invent these
                // two (`source=shipped tier=unknown`) from a `??` default for
                // the one row shape that never carried them. `shipped` is
                // true and load-bearing — a missing registry is a fault in
                // the agent's own manifest library, never in a site's
                // adapters/ source, and an operator sent to the wrong
                // directory is exactly what DUO-3314 put source on these rows
                // to prevent. `trust_tier` is deliberately NOT one of the
                // four tiers: this row is about the library's registry file,
                // not about one adapter's declarations, so it has no tier to
                // report and says so instead of borrowing one.
                'source' => AdapterSources::SHIPPED,
                'trust_tier' => 'unknown',
                'remediation' => 'regenerate the capability registry with scripts/capability-registry.php, or point '
                    . 'DUO_MANIFESTS_DIR at a library that carries both dispositions.json and capabilities/'
                    . 'registry.json — a reviewed disposition set with no generated projection makes no product claim',
            ]];
        }
        return $this->capabilityRegistry->blockers(
            $this->policy->manifests,
            ['operation' => 'promote'],
            CapabilityRegistry::probe_target(),
            $this->policy->adapter_sources()->diagnostics($this->policy->manifests),
            $this->policy->adapter_sources()->certification_contexts()
        );
    }

    /**
     * Every source/evidence and runtime-provider blocker for this policy.
     *
     * This is the global readiness answer used by callers that ask whether
     * the installed adapter set is usable at all. It deliberately negotiates
     * every declared provider ACTION, irrespective of that action's trigger:
     * a capability report must not be green merely because this particular
     * moment has no matching authored surface. Plan construction uses
     * certification_readiness_blockers() plus the selected-action method
     * below instead.
     */
    public function adapter_readiness_blockers(): array {
        return array_merge(
            $this->certification_readiness_blockers(),
            $this->provider_readiness_blockers($this->policy->actions())
        );
    }

    /**
     * Negotiate selected provider actions for a target-facing diagnostic.
     *
     * Providers::negotiate() calls only identity() and capabilities() on a
     * provider; it never calls invoke(). Its structured problems are promoted
     * into the same adapter_dispositions wire shape plan/status already render
     * so provider, plugin, source, tier, and operator remediation remain
     * visible instead of a signed claim masking a live incompatibility.
     *
     * @param list<array<string,mixed>> $actions
     * @return list<array<string,mixed>>
     */
    public function provider_readiness_blockers(array $actions): array {
        $providerActions = array_values(array_filter(
            $actions,
            static fn(array $action): bool => ($action['kind'] ?? null) === 'provider'
        ));
        if ($providerActions === []) {
            return [];
        }

        // These classes are intentionally late-bound: Policy retains its
        // pure/offline loading entry point, while a real target path gains the
        // one runtime contract Deploy and Providers already share.
        require_once __DIR__ . '/../Promotion/Deploy.php';
        require_once __DIR__ . '/Providers.php';
        $packagingProblems = Providers::packaging_problems($this->policy, $providerActions);
        if (!Providers::runtime_negotiation_available()
            || $this->manifestDispositions === null
            || $this->capabilityRegistry === null) {
            // A missing manifest-shipped provider is a packaging fault, not a
            // target fact. Keep the WordPress-free adapter doctor readable by
            // reporting that static fact without loading provider PHP, while
            // leaving plugin-owned/runtime negotiation deferred until a target
            // is available.
            $negotiation = ['problems' => $packagingProblems];
        } else {
            try {
                $negotiation = Providers::negotiate($this->policy, $providerActions);
            } catch (ProviderPackagingException $failure) {
                // A missing manifest-shipped provider is a packaging fault, not
                // a target fact. Keep plan/status/doctor readable by projecting
                // the same structured row Providers::problems() uses, while
                // leaving Providers::negotiate() itself throwing for apply's
                // fail-before-mutation gate.
                $negotiation = ['problems' => [Providers::packaging_problem($failure)]];
            }
        }
        $sources = $this->policy->adapter_sources()->diagnostics($this->policy->manifests);
        $rows = [];
        foreach ($negotiation['problems'] as $problem) {
            $manifest = (string) ($problem['manifest'] ?? '?');
            $source = $sources[$manifest] ?? [
                'certification' => 'unknown',
                'source' => 'unknown',
                'trust_tier' => 'unknown',
            ];
            $rows[] = [
                'name' => $manifest,
                'status' => 'blocked',
                'code' => (string) ($problem['code'] ?? 'provider_negotiation_failed'),
                'reason' => (string) ($problem['message'] ?? 'provider negotiation failed'),
                'remediation' => (string) ($problem['remediation'] ?? ''),
                'provider' => (string) ($problem['provider'] ?? '?'),
                'manifest' => $manifest,
                'plugin' => (string) ($problem['plugin'] ?? '?'),
                'expected' => (string) ($problem['expected'] ?? ''),
                'found' => (string) ($problem['found'] ?? ''),
                'source' => (string) ($source['source'] ?? 'unknown'),
                'trust_tier' => (string) ($source['trust_tier'] ?? 'unknown'),
                'certification' => (string) ($source['certification'] ?? 'unknown'),
            ];
        }
        return $rows;
    }

    /** Resolve CLI capability output from the same manifests and external review bytes. */
    public function capability_report(array $query = []): array {
        if ($this->manifestDispositions === null || $this->capabilityRegistry === null) {
            return [
                'schema_version' => CapabilityRegistry::FORMAT,
                'registry_sha256' => null,
                'ready' => false,
                'blockers' => [[
                    'name' => 'registry',
                    'status' => 'unreviewed',
                    'reason' => 'this manifest directory has no external disposition registry',
                ]],
                'manifests' => [],
                'profiles' => new \stdClass(),
            ];
        }
        $report = $this->capabilityRegistry->report(
            $this->policy->manifests,
            $query,
            CapabilityRegistry::probe_target(),
            $this->policy->adapter_sources()->diagnostics($this->policy->manifests),
            $this->policy->adapter_sources()->certification_contexts()
        );
        $providerBlockers = $this->provider_readiness_blockers($this->policy->actions());
        if ($providerBlockers === []) {
            return $report;
        }

        // Preserve the registry claim (the signed/certified source fact), but
        // make its executable provider state a separate blocked verdict. The
        // provider fields travel both on the top-level blocker and the row's
        // reason so JSON consumers do not have to reconstruct responsibility
        // from a human-formatted string.
        $rowsByName = [];
        foreach ($report['manifests'] as $index => $row) {
            $rowsByName[(string) ($row['name'] ?? '?')][] = $index;
        }
        foreach ($providerBlockers as $blocker) {
            $reason = [
                'code' => $blocker['code'],
                'message' => $blocker['reason'],
                'remediation' => $blocker['remediation'],
                'provider' => $blocker['provider'],
                'manifest' => $blocker['manifest'],
                'plugin' => $blocker['plugin'],
                'expected' => $blocker['expected'],
                'found' => $blocker['found'],
                'source' => $blocker['source'],
                'trust_tier' => $blocker['trust_tier'],
                'certification' => $blocker['certification'],
            ];
            foreach ($rowsByName[(string) $blocker['name']] ?? [] as $index) {
                $report['manifests'][$index]['verdict']['status'] = 'blocked';
                $report['manifests'][$index]['verdict']['reasons'][] = $reason;
            }
            $report['blockers'][] = $blocker;
        }
        $report['ready'] = $report['blockers'] === [];
        return $report;
    }
}

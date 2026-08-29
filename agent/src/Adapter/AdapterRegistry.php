<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Policy/ManifestDispositions.php';
require_once __DIR__ . '/AdapterSources.php';
require_once __DIR__ . '/TargetProbe.php';

/**
 * Adapter provenance and capability-readiness resolution, extracted from
 * Policy (issue #3348 slice 4): the source view of which pinned adapters are
 * usable, and the provider-negotiation view of which selected actions can
 * actually run, over the manifests and dispositions a Policy has already
 * resolved.
 *
 * Extracted from Policy: the constructor's two fields — the owning Policy
 * itself, plus its already-resolved manifestDispositions — are exactly what
 * this cluster needs. The Policy field is a back-reference,
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
 * field (issue #3347/issue #3441), no fresh-process load of this file alone can
 * reach a Policy-class-not-found fatal through it. The four requires above
 * are every class this file names statically on its own — Canon, Manifest
 * Dispositions, `AdapterSources::SHIPPED`, `TargetProbe::probe_target()` —
 * each stated here rather than inherited through some other file's require,
 * because an unstated dependency is exactly the standalone-load gap
 * issue #3440/issue #3441/issue #3442 each fixed one file at a time.
 */
final class AdapterRegistry {
    /**
     * The capability report's own wire version.
     *
     * A new string rather than the retired `wprism-capability-registry/v2`: the
     * document is now projected from the reviewed dispositions alone, so no
     * row carries a generated adapter digest, a subject certification record,
     * or a bound evidence status. A consumer pinned to the old version string
     * would otherwise read those absences as data loss in a document it
     * believed was the same shape.
     */
    public const REPORT_FORMAT = 'wprism-capability-report/v1';

    public function __construct(
        private readonly Policy $policy,
        private readonly ?ManifestDispositions $manifestDispositions,
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
     * The reviewed capability claim for one pinned adapter.
     *
     * An out-of-tree adapter (site or plugin source, including a site copy
     * that overrides a shipped name) has exactly the claim its own verified
     * certificate projects, or none: it never borrows the shipped entry
     * for the same NAME. That fallback made an uncertified override read the
     * shipped adapter's certified claim (walk S4: `Ready` beside
     * `Uncertified` on post_type:product).
     */
    public function capability_claim(string $name): ?array {
        $sources = $this->policy->adapter_sources();
        if ($sources->is_out_of_tree($name)) {
            return $sources->claim($name);
        }
        $disposition = $this->manifestDispositions?->entry($name);
        if ($disposition === null) {
            return null;
        }
        foreach ($this->policy->manifests as $manifest) {
            if ((string) ($manifest['name'] ?? '') === $name) {
                return self::shipped_claim($manifest, $disposition, $this->policy->adapter_platform_boundary());
            }
        }

        return null;
    }

    /**
     * One shipped adapter's claim, bound to the evidence its own disposition
     * cites and to nothing else.
     *
     * `evidence` is the authored citation verbatim — the bundle schema and the
     * named tests a reviewer wrote down — not a status this code decides. A
     * synthesized `current` here would be the agent vouching for itself, which
     * is the whole reason the disposition document is separate from the
     * manifest it describes.
     *
     * It is also the ONE funnel every shipped claim passes through —
     * capability_claim() above and report() below both land here — which is
     * why the per-entry rules are asserted at this line. Since WP-1.2 the
     * pinned-subset check in Policy::load() no longer speaks for the LIBRARY
     * view: `wp wprism capabilities --all` reads entry() for manifests nobody
     * pinned, and an entry tampered after review (evidence deleted, an
     * invented entity_section, a version range the manifest does not declare)
     * projected `certified`/`verified` from here with no validator between the
     * bytes and the claim. Same validator, same wording as load time; only the
     * moment moved.
     */
    private static function shipped_claim(array $manifest, array $disposition, array $platform): array {
        ManifestDispositions::assert_entry((string) ($manifest['name'] ?? ''), $disposition, $manifest);
        return ManifestDispositions::claim_from_disposition(
            $manifest,
            $disposition,
            is_array($disposition['evidence'] ?? null) ? $disposition['evidence'] : [],
            $platform
        );
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
        return self::report(
            $this->manifestDispositions,
            $this->policy->manifests,
            ['operation' => 'promote'],
            TargetProbe::probe_target(),
            $this->policy->adapter_sources()->diagnostics($this->policy->manifests),
            $this->policy->adapter_sources()->certification_contexts(),
            $this->policy->adapter_platform_boundary()
        )['blockers'];
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
            || $this->manifestDispositions === null) {
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
        if ($this->manifestDispositions === null) {
            return [
                'schema_version' => self::REPORT_FORMAT,
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
        $report = self::report(
            $this->manifestDispositions,
            $this->policy->manifests,
            $query,
            TargetProbe::probe_target(),
            $this->policy->adapter_sources()->diagnostics($this->policy->manifests),
            $this->policy->adapter_sources()->certification_contexts(),
            $this->policy->adapter_platform_boundary()
        );
        $providerBlockers = $this->provider_readiness_blockers($this->policy->actions());
        if ($providerBlockers === []) {
            return $report;
        }

        // Preserve the reviewed claim (the signed/certified source fact), but
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

    /**
     * The machine-readable capability view, projected from reviewed
     * dispositions and per-adapter provenance.
     *
     * Static, and taking its inputs rather than reading them off a Policy,
     * because `wp wprism capabilities --all` reports the whole shipped library
     * with no site repository to load. One implementation serves both: the
     * library view and the pinned-set view disagreeing about a verdict would
     * be worse than either being wrong.
     *
     * $sources (issue #3314) carries AdapterSources::diagnostics() — where each
     * manifest was installed from, the trust tier it reaches, and its
     * certification state. $target is TargetProbe::probe_target(), or null
     * where there is no live target to evaluate against: with no target facts
     * this is the source/authorship gate only, and a real wp-cli request
     * additionally proves the installed plugin is inside the reviewed range.
     */
    public static function report(
        ManifestDispositions $dispositions,
        array $manifests,
        array $query = [],
        ?array $target = null,
        array $sources = [],
        array $externalContexts = [],
        ?array $platformBoundary = null
    ): array {
        $operation = (string) ($query['operation'] ?? 'promote');
        $surface = isset($query['surface']) ? (string) $query['surface'] : null;
        $platform = $platformBoundary ?? ManifestDispositions::platform_boundary();
        $rows = [];
        $blockers = [];
        $perRowEvidence = [];
        $hasOutOfTree = false;

        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '?');
            // `registry` stays right here where AdapterSources::diagnostics()
            // now answers `null` for a shipped row in a library with no
            // dispositions document (issue #3486): this method cannot be reached
            // by such a library at all. Every caller holds a non-null
            // ManifestDispositions before it calls — certification_readiness_
            // blockers() and capability_report() return early without one, `wp
            // wprism capabilities --all` refuses, and AdapterCatalog only enters
            // its block when one loaded. So the library HAS a reviewed
            // registry, this default describes a row whose diagnostics entry is
            // merely ABSENT (an empty $sources, or a manifest the scan did not
            // attribute), and `registry` is exactly what diagnostics() would
            // have said for it.
            $source = $sources[$name] ?? [
                'certification' => 'registry',
                'path' => null,
                'remediation' => '',
                'source' => AdapterSources::SHIPPED,
                'trust_tier' => AdapterSources::trust_tier($manifest),
            ];
            $outOfTree = ($source['source'] ?? AdapterSources::SHIPPED) !== AdapterSources::SHIPPED;
            $hasOutOfTree = $hasOutOfTree || $outOfTree;
            $external = is_array($externalContexts[$name] ?? null) ? $externalContexts[$name] : null;
            $externalClaim = is_array($external['claim'] ?? null) ? $external['claim'] : null;
            $explicitPin = ($external['explicit_pin'] ?? false) === true;
            if ($externalClaim !== null && !$outOfTree) {
                throw new \RuntimeException(
                    "wprism: external capability context for shipped adapter '$name' would replace its registry claim"
                );
            }
            // An out-of-tree adapter answers with its own (external, signed)
            // claim or with none — never with the reviewed disposition for the
            // same NAME. That name-keyed fallback is exactly how an uncertified
            // site copy of a SHIPPED adapter (T6 §3.3's override) read `Ready`
            // beside `Uncertified` in `wprism assess`: the shipped woocommerce
            // claim was borrowed for a manifest nobody had reviewed.
            $disposition = $outOfTree ? null : $dispositions->entry($name);
            $claim = $externalClaim ?? ($disposition === null
                ? null
                : self::shipped_claim($manifest, $disposition, $platform));
            $reasons = [];
            if ($claim === null) {
                // An out-of-tree adapter has no reviewed entry BY CONSTRUCTION,
                // which is a different fact from a shipped adapter whose entry
                // went missing. Collapsing them would tell an operator to go
                // review a library that was never supposed to name this
                // adapter, so each gets its own code and its own remediation.
                $reasons[] = $outOfTree
                    ? self::reason(
                        'adapter_source_uncertified',
                        "'$name' is installed from the " . (string) ($source['source'] ?? '?')
                        . ' adapter source (' . (string) ($source['path'] ?? '?')
                        . ') and is uncertified by construction: out-of-tree adapters carry no reviewed '
                        . 'certification evidence',
                        (string) ($source['remediation'] ?? '')
                    )
                    : self::reason('missing_disposition_entry', "no reviewed disposition entry exists for '$name'");
                $claim = [
                    'name' => $name,
                    'status' => $outOfTree ? 'uncertified' : 'unsupported',
                    'reason' => $outOfTree
                        ? "installed out-of-tree from the {$source['source']} adapter source; not reviewed"
                        : "no reviewed disposition entry exists for '$name'",
                    'plugin_execution' => ['mode' => 'unknown', 'status' => 'unsupported'],
                    'authored_state' => ['status' => 'unsupported'],
                    'supported_versions' => new \stdClass(),
                    'operations' => [],
                    'surfaces' => [],
                    'unsupported' => [],
                ];
            } else {
                if ($externalClaim !== null && !$explicitPin) {
                    $reasons[] = self::reason(
                        'adapter_certification_unpinned',
                        "'$name' has valid signed third-party evidence, but its repository pin does not bind both "
                        . 'source "site" and the final certificate-derived digest',
                        (string) ($source['remediation'] ?? '')
                    );
                }
                if (($claim['status'] ?? null) !== 'certified') {
                    $reasons[] = self::reason(
                        'authored_state_not_certified',
                        "$name authored state is " . ($claim['status'] ?? 'unsupported') . ', not certified'
                    );
                }
                if (!in_array($operation, $claim['operations'] ?? [], true)) {
                    $reasons[] = self::reason(
                        'operation_not_certified',
                        "$operation is not certified for '$name'"
                    );
                }
                if ($surface !== null && $surface !== '') {
                    $surfaceReason = self::surface_reason($claim, $surface, $operation);
                    if ($surfaceReason !== null) {
                        $reasons[] = $surfaceReason;
                    }
                }
            }

            $selectedEvidence = $externalClaim !== null
                ? (is_array($externalClaim['evidence'] ?? null) ? $externalClaim['evidence'] : [])
                : ($outOfTree ? [] : (is_array($claim['evidence'] ?? null) ? $claim['evidence'] : []));
            $selectedPlatform = $externalClaim !== null
                ? (is_array($externalClaim['platform'] ?? null) ? $externalClaim['platform'] : [])
                : ($outOfTree ? [] : $platform);
            if ($target !== null && (!$outOfTree || $externalClaim !== null)) {
                $reasons = array_merge($reasons, self::target_reasons($claim, $target));
            }

            $verdict = $reasons === [] ? 'certified' : 'blocked';
            $row = $claim;
            // Source, trust tier, and certification state ride on every row and
            // every blocker: the doctrine requires certified, uncertified, and
            // missing capabilities to stay visibly different wherever they are
            // reported, and `wprism status` renders blockers without the rows.
            $row['source'] = $source;
            $row['verdict'] = ['status' => $verdict, 'reasons' => $reasons];
            $rows[] = $row;
            $perRowEvidence[] = [
                'evidence' => $selectedEvidence,
                'platform' => $selectedPlatform,
                'scope' => $outOfTree
                    ? ($externalClaim === null ? 'none' : 'site_certificate')
                    : 'authored_disposition',
            ];
            foreach ($reasons as $reason) {
                $blockers[] = [
                    'name' => $name,
                    'status' => $verdict,
                    'code' => $reason['code'],
                    'reason' => $reason['message'],
                    'remediation' => (string) ($reason['remediation'] ?? ''),
                    'source' => (string) ($source['source'] ?? AdapterSources::SHIPPED),
                    'trust_tier' => (string) ($source['trust_tier'] ?? ''),
                    'certification' => (string) ($source['certification'] ?? 'registry'),
                ];
            }
        }

        // Topology is a whole-target fact, so it is reported ONCE, at report
        // level, under `name: 'platform'` -- never as a per-surface reason.
        // `ready` below is `$blockers === []`, so this is the entire mechanism
        // that stops `wp wprism capabilities` reporting a network as ready; every
        // `verdict.reasons` stays untouched, so the code never reaches
        // `ProjectionVocabulary::project()` (which reads
        // `report()['manifests'][]['verdict']['reasons']` through
        // `SurfaceCatalog::registryFacts()`, not this list) and the retirement
        // recorded at cli/src/Contract/ProjectionVocabulary.php:219-235 stands.
        // `wprism-capability-report/v1` gains only a row in an existing list of
        // open-vocabulary blocker objects, so the change is additive.
        if (($target['multisite'] ?? false) === true) {
            $blockers[] = [
                'name' => 'platform',
                'status' => 'blocked',
                'code' => 'site_mode_unsupported',
                'reason' => 'the evaluated target is a WordPress network; the certified v1 contract is single-site only',
                'remediation' => 'evaluate a single-site installation',
                'source' => AdapterSources::SHIPPED,
                'trust_tier' => 'registry',
                'certification' => 'registry',
            ];
        }

        // Evidence authority is always per subject. This makes the absence of
        // one adapter's citation visible without contaminating another row.
        foreach ($rows as $i => &$row) {
            $row['evidence'] = $perRowEvidence[$i]['evidence'];
            $row['platform'] = $perRowEvidence[$i]['platform'];
            $row['evidence_scope'] = $perRowEvidence[$i]['scope'];
        }
        unset($row);

        // Profiles are independently reviewed subjects, not aliases for their
        // parent manifest. Evaluate a profile only when its explicit surface is
        // selected; an uncertified FSE profile must block profile:fse without
        // contaminating unrelated core operations.
        $profileRows = [];
        foreach ($dispositions->profiles() as $profileName => $profile) {
            $profileName = (string) $profileName;
            // The disposition document keys profiles by name and does not
            // repeat it inside the entry; the report row carries it, because a
            // blocker naming `profile:` and nothing else is unreadable.
            $profile['name'] = $profileName;
            $profileSurface = 'profile:' . $profileName;
            $profileReasons = [];
            if ($surface === $profileSurface && ($profile['status'] ?? null) !== 'certified') {
                $profileReasons[] = self::reason(
                    'profile_not_certified',
                    "profile '$profileName' is " . ($profile['status'] ?? 'unsupported') . ', not certified'
                );
            }
            $profile['evidence_scope'] = 'authored_disposition';
            if ($surface === $profileSurface) {
                $profile['verdict'] = [
                    'status' => $profileReasons === [] ? 'certified' : 'blocked',
                    'reasons' => $profileReasons,
                ];
            }
            $profileRows[] = $profile;
            foreach ($profileReasons as $reason) {
                $blockers[] = [
                    'name' => $profileSurface,
                    'status' => 'blocked',
                    'code' => $reason['code'],
                    'reason' => $reason['message'],
                    'remediation' => (string) ($reason['remediation'] ?? ''),
                    'source' => AdapterSources::SHIPPED,
                    'trust_tier' => 'registry',
                    'certification' => 'registry',
                ];
            }
        }

        return [
            'schema_version' => self::REPORT_FORMAT,
            // The content address of the reviewed bytes this verdict was read
            // from. A host contract pins it and re-observes it later, so it
            // must address the document that actually decided the verdict.
            'registry_sha256' => $dispositions->sha256(),
            'platform' => $hasOutOfTree ? null : $platform,
            'evidence' => null,
            'query' => [
                'operation' => $operation,
                'surface' => $surface,
            ],
            'target' => $target,
            'ready' => $blockers === [],
            'blockers' => $blockers,
            'manifests' => $rows,
            'profiles' => $profileRows,
            'evidence_scope' => 'per_subject',
        ];
    }

    /**
     * Runtime compatibility for one claim against live target facts.
     *
     * Only the adapter's OWN plugin contract is checked here. The WordPress,
     * PHP, database, and multisite boundaries were reported against a
     * generated evidence record that no longer exists; re-deriving them from
     * the shipped platform note would be this code vouching for a range nobody
     * measured, so they are not reported at all rather than reported on a
     * guess. The plugin window is different in kind: `supported_versions` is
     * authored in the disposition and pinned to the manifest's own
     * `version_range` (ManifestDispositions::validate_entry()), so it is a
     * reviewed fact and stays enforced.
     *
     * Topology in particular is still NOT a per-surface reason. It is reported
     * once, at report level, under `name: 'platform'` with code
     * `site_mode_unsupported` (see report() above), so no surface row ever
     * carries a code `ProjectionVocabulary::project()` has no entry for.
     */
    private static function target_reasons(array $claim, array $target): array {
        $reasons = [];
        $supported = is_array($claim['supported_versions'] ?? null) ? $claim['supported_versions'] : [];
        $plugin = $supported['plugin'] ?? null;
        $range = $supported['range'] ?? null;
        if (is_string($plugin) && $plugin !== '' && $plugin !== 'unbound') {
            $installed = isset($target['plugins'][$plugin])
                ? (string) $target['plugins'][$plugin]
                : TargetProbe::installed_plugin_version($plugin);
            // The three machine facts the mutation gate re-observes each of
            // these conditions against. They ride BESIDE `message`, whose bytes
            // are unchanged: every prose consumer (the projection's
            // `conditions` list, the blocker rows, docs/assess-vocabulary.md's
            // table) reads the sentence and is untouched, while
            // `SurfaceCatalog::conditionRows()` reads these and mints the row
            // `AuthorizationPlanRenderer::conditionLine()` (:387-401) has
            // always known how to print and nothing has ever produced. Without
            // `subject` the gate has no name to re-probe and the condition is
            // uncheckable, which docs/product-spec.md:302-303 says must block.
            if ($installed === null || !self::inside_range($installed, $range)) {
                $reasons[] = self::reason(
                    'plugin_version_mismatch',
                    "$plugin " . ($installed ?? '(missing)') . ' is outside the certified range',
                    '',
                    [
                        'check' => self::certified_window($plugin, $range),
                        // '' rather than '(missing)': `observed` is compared,
                        // never rendered as a sentence, and an unreadable
                        // plugin that later reports a version must register at
                        // the gate as a MOVED observation.
                        'observed' => $installed ?? '',
                        'subject' => $plugin,
                    ]
                );
            }
            if (isset($target['active_plugins'])
                && !in_array($plugin, (array) $target['active_plugins'], true)) {
                $reasons[] = self::reason(
                    'plugin_not_active',
                    "$plugin is not active on the evaluated target",
                    '',
                    ['check' => "$plugin active", 'observed' => 'inactive', 'subject' => $plugin]
                );
            }
        }
        return $reasons;
    }

    private static function surface_reason(array $claim, string $surface, string $operation): ?array {
        foreach ($claim['unsupported'] ?? [] as $unsupported) {
            $unsupportedOperation = (string) ($unsupported['operation'] ?? '');
            if (($unsupported['surface'] ?? null) === $surface
                && in_array($unsupportedOperation, ['all', $operation], true)) {
                return self::reason('surface_explicitly_unsupported', (string) $unsupported['reason']);
            }
        }
        if (!in_array($surface, $claim['surfaces'] ?? [], true)) {
            return self::reason('surface_not_registered', "surface '$surface' is absent from the certified registry entry");
        }
        return null;
    }

    /** min inclusive, max exclusive — the one window arithmetic Policy::assert_min_max_range() authors. */
    private static function inside_range(string $version, $range): bool {
        return $version !== '' && is_array($range)
            && is_string($range['min'] ?? null) && is_string($range['max'] ?? null)
            && version_compare($version, $range['min'], '>=')
            && version_compare($version, $range['max'], '<');
    }

    /**
     * A remediation string is attached only where one exists, rather than
     * back-filling every pre-existing reason code with invented advice: the
     * shipped codes' wording is reviewed evidence text, and an empty
     * `remediation` in a blocker row already reads as "no specific action
     * beyond the reason itself".
     */
    private static function reason(
        string $code,
        string $message,
        string $remediation = '',
        array $facts = []
    ): array {
        $reason = ['code' => $code, 'message' => $message];
        if ($remediation !== '') {
            $reason['remediation'] = $remediation;
        }
        // Additive and closed: only the three keys the mutation gate re-probes
        // against, and only where the raising site actually observed them. A
        // reason that carries none is byte-identical to what this build
        // shipped before, so `surface_reason()`, `report()`'s blocker rows and
        // every prose consumer see exactly the document they always saw.
        foreach (['check', 'observed', 'subject'] as $key) {
            if (array_key_exists($key, $facts)) {
                $reason[$key] = (string) $facts[$key];
            }
        }
        return $reason;
    }

    /**
     * The certified plugin window as one printable check, e.g.
     * `woocommerce in 10.0.0-11.0.0` — min inclusive, max exclusive, exactly
     * the arithmetic inside_range() above applies. A claim whose range is
     * unreadable still names its subject rather than printing half a window:
     * the host COMPARES this string at the gate, it never parses it.
     *
     * @param mixed $range
     */
    private static function certified_window(string $plugin, $range): string {
        if (!is_array($range) || !is_string($range['min'] ?? null) || !is_string($range['max'] ?? null)) {
            return $plugin . ' inside its certified range';
        }
        return $plugin . ' in ' . $range['min'] . '-' . $range['max'];
    }
}

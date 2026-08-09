<?php
namespace Duo;

// Manifest validation is a pure offline pass with several entry points of
// its own (the frozen-snapshot path, the offline harnesses that load this
// file directly). The native-action vocabulary is part of that pass, so it
// is required here rather than left to duo.php's bootstrap order — same
// precedent as Deploy.php requiring CodeCompatibility.php.
require_once __DIR__ . '/NativeActions.php';
// DUO-3314: adapter provenance is decided inside the same offline pass, before
// any manifest reaches a policy consumer, so it is required here for the same
// reason NativeActions is.
require_once __DIR__ . '/AdapterSources.php';
require_once __DIR__ . '/ReferenceRules.php';

/**
 * Layered classification policy: site policy overrides > pinned manifests
 * (in pin order) > option name-patterns. Anything unmatched is unclassified,
 * and unclassified is a loud abort at the call sites (never a silent guess).
 */
final class Policy {
    // v4 adds the required `adapter_sources` record (DUO-3314). It is required
    // rather than optional on purpose: if a snapshot could omit it and have
    // every manifest default to "shipped", dropping one key would silently
    // launder an out-of-tree adapter into a shipped one on the verification
    // path, which is exactly the provenance guarantee this record exists for.
    // v5 carries signed site-adapter certification envelopes. from_snapshot()
    // retains v4 reads only for the prior uncertified adapter-sources/v1 form.
    private const SNAPSHOT_FORMAT = 'duo-policy-snapshot/v5';
    private const LEGACY_SNAPSHOT_FORMAT = 'duo-policy-snapshot/v4';
    /** Object keyspaces supported by the canonical taxonomy relationship contract. */
    private const TAXONOMY_RELATIONSHIP_OBJECTS = ['post', 'term'];

    /**
     * The exact canonical-surface literal grammar. Apply derives these keys
     * from authored work as a pure projection (Apply::rebuild_surfaces()) and
     * manifests match them literally in `actions[].triggers`; DUO-3338's
     * provider capabilities describe their own reads/writes in the same
     * vocabulary, so it is a shared constant rather than two regexes that can
     * drift into accepting different names for the same surface.
     */
    public const SURFACE_PATTERN = '/^(post|term|table|option|entity):[a-z0-9][a-z0-9._-]{0,127}$/D';

    public array $site = [];
    /** @var array<int, array> */
    public array $manifests = [];
    /** External review state; null for legacy/custom manifest directories without a registry. */
    private ?ManifestDispositions $manifestDispositions = null;
    /** Which source installed each pinned adapter, and what that origin may do (DUO-3314). */
    private ?AdapterSources $adapterSources = null;
    /** Generated evidence/platform projection of the reviewed dispositions. */
    private ?CapabilityRegistry $capabilityRegistry = null;
    /** @var array<string, object>|null lazily-built interpreter instances */
    private ?array $interpreterInstances = null;
    /** @var array<string, object>|null lazily-built regenerator instances (DUO-3234) */
    private ?array $regeneratorInstances = null;

    /**
     * Interpreter contract. An interpreter is a class with the required
     *   post_meta_rule(string $key, array $allMeta): ?array
     * hook and may additionally define any of the optional hooks
     *   term_meta_rule(string $key, array $allMeta): ?array
     *   user_meta_rule(string $key, array $allMeta): ?array
     *   option_rule(string $name, array $allOptions): ?array
     * Each returns a classification rule (same shape as the corresponding
     * static meta rule, optionally with 'cast') or null to defer. An option
     * interpreter may additionally return `deletion_witness: true` when
     * that exact option value is the minimum context required to classify
     * related tombstones from an immutable tree; OptionState binds the
     * retained value/autoload to the prior record hash and apply never
     * treats it as desired data. Manifests opt in via
     * {"interpreter": "<name>"} — for schema-driven plugins (ACF) whose meta
     * semantics live in data, not in a static key list. option_rule() (DUO-3263)
     * is consulted only for an option NAME already namespace-owned by some
     * manifest's option_namespaces declaration — unlike the meta hooks, an
     * interpreter has no implicit reach over every option in the table.
     *
     * Interpreter CODE is part of the manifest artifact, never the engine:
     * a declared name resolves to <manifests_dir>/interpreters/<name>.php,
     * which must define \Duo\Interpreters\<CamelCase(name)>. The engine holds
     * only this loading contract — no plugin names, no plugin logic. Trust
     * boundary: the manifests dir is operator-controlled and ships/mounts
     * with the agent itself (ro in the sandbox), so loading PHP from it is
     * the same trust decision as running the agent.
     */

    public static function manifests_dir(): string {
        $env = getenv('DUO_MANIFESTS_DIR');
        if ($env && is_dir($env)) {
            return $env;
        }
        $local = dirname(__DIR__, 2) . '/manifests';
        if (is_dir($local)) {
            return $local;
        }
        return '/duo-manifests';
    }

    /**
     * V1 is deliberately single-site. Refuse before policy/repository reads
     * so a network install cannot be mistaken for a supported convergence
     * surface and no command can publish a partial single-blog projection.
     * The function guard keeps the pure offline policy validators usable
     * outside WordPress while the real product path always has is_multisite().
     */
    private static function assert_single_site(): void {
        if (function_exists('is_multisite') && is_multisite()) {
            throw new \RuntimeException(
                'duo: multisite is unsupported by the certified v1 contract; '
                . 'this command is single-site only and refuses before loading policy or mutating state'
            );
        }
    }

    public static function load(
        ?string $repo,
        ?array $manifestNames = null,
        bool $allowUnsupportedSiteForReadOnlyCapabilities = false
    ): self {
        if (!$allowUnsupportedSiteForReadOnlyCapabilities) {
            self::assert_single_site();
        }
        $p = new self();
        if ($repo !== null) {
            $siteFile = rtrim($repo, '/') . '/site.duo.json';
            if (!is_file($siteFile)) {
                throw new \RuntimeException("duo: $siteFile not found (not a duo site repo?)");
            }
            $p->site = Canon::decode(Canon::read_file($siteFile));
            self::validate_code_config($p->site, 'site.duo.json');
            self::validate_scope_classes($p->site, 'site.duo.json', true);
            self::validate_option_storage($p->site['policy'] ?? [], 'site.duo.json');
            self::validate_env_options($p->site['policy'] ?? [], 'site.duo.json');
            self::validate_user_meta_rules($p->site['policy'] ?? [], 'site.duo.json');
            self::validate_tables($p->site['policy'] ?? [], 'site.duo.json');
            self::validate_sub_keys($p->site['policy'] ?? [], 'site.duo.json');
            self::validate_reference_shapes($p->site['policy'] ?? [], 'site.duo.json');
        }
        $rawPins = $manifestNames ?? ($p->site['manifests'] ?? ['core']);
        $pins = self::normalize_manifest_pins($rawPins);
        $dir = self::manifests_dir();
        // DUO-3314: every installed source is scanned, and ambiguous identity or
        // shadowing refused, before the first pin resolves — a broken adapter
        // installation must not wait for a pin to reveal itself.
        $p->adapterSources = AdapterSources::discover($dir, $repo);
        self::validate_manifest_sources($pins, $p->adapterSources);
        $p->manifestDispositions = class_exists(ManifestDispositions::class)
            ? ManifestDispositions::load($dir)
            : null;
        foreach ($pins as $pin) {
            $name = $pin['name'];
            // normalize_manifest_pins() has already proved this exact identity
            // path-free and canonical; never rewrite it into a different key.
            $key = $name;
            $manifest = Canon::decode(Canon::read_file($p->adapterSources->file($key, $dir)));
            // DUO-3371: the earliest point on the live load path where a
            // manifest's FILE name and its DECLARED name are both in hand, and
            // therefore the only place one identity can be enforced for both
            // keyings. The pin, the file, and ManifestDispositions::load()'s
            // coverage check all key off the file name; ManifestDispositions::
            // entry(), RepositoryCompiler::manifest_rows()'s per-adapter digest,
            // and the capability registry's claims all key off the declared
            // name. Every one of those declared-name lookups is downstream of
            // this line — nothing reads $p->manifests before it exists — so
            // refusing here, ahead of the first validator, is what keeps one
            // adapter from answering to two keys. from_snapshot() has always
            // refused the same disagreement against the frozen pin; this is the
            // live path's half of that, and AdapterSources owns the sentence so
            // the site source (DUO-3314) and the shipped source say it once.
            AdapterSources::assert_declared_name(
                $manifest,
                $key,
                $p->adapterSources->source($key),
                (string) $p->adapterSources->path($key)
            );
            if ($p->adapterSources->is_out_of_tree($key)) {
                // The instance picks the noun and the path from the origin it
                // actually resolved: with three sources, a hardcoded "site
                // adapter" would have named the wrong directory to go fix for
                // every plugin-bundled manifest.
                $p->adapterSources->assert_installed_contract($key, $manifest);
            }
            self::validate_field_classes($manifest);
            self::validate_menu_field_classes($manifest);
self::validate_post_type_children($manifest);
            self::validate_post_type_contracts($manifest);
            self::validate_tables($manifest, "manifest '$name'");
            self::validate_attr_rules($manifest);
            self::validate_widgets($manifest);
            self::validate_regen_dependencies($manifest);
            self::validate_providers($manifest);
            self::validate_actions($manifest);
            self::validate_env_options($manifest, "manifest '$name'");
            self::validate_user_meta_rules($manifest, "manifest '$name'");
            self::validate_scope_classes($manifest, "manifest '$name'", false);
            self::validate_sub_keys($manifest, "manifest '$name'");
            self::validate_object_type_option_refs($manifest);
            self::validate_taxonomy_object_keyspace_declarations($manifest);
            self::validate_dynamic_options($manifest);
            self::validate_option_name_refs($manifest);
            self::validate_option_storage($manifest, "manifest '$name'");
            self::validate_adapter_contract($manifest);
            self::validate_effect_contracts($manifest);
            self::validate_discovery_contract($manifest);
            self::validate_reference_shapes($manifest, "manifest '$name'");
            $p->manifests[] = $manifest;
        }
        self::validate_no_conflicting_option_rules(
            $p->manifests,
            $p->site['policy']['options'] ?? []
        );
        self::validate_no_overlapping_option_name_refs($p->manifests);
        self::validate_no_conflicting_adapter_claims($p->manifests);
        self::validate_no_conflicting_provider_ids($p->manifests);
        self::validate_no_conflicting_post_type_contracts($p->manifests);
        self::validate_one_owner_per_declared_name($p->manifests);
        self::validate_ref_kinds($p->manifests, $p->site['policy'] ?? []);
        self::validate_unique_table_id_kinds($p->declared_tables());
        self::validate_no_conflicting_taxonomy_object_keyspaces($p->manifests);
        self::validate_no_conflicting_description_reference_rules($p->manifests);
        self::validate_reference_keyspaces_and_sidecars($p);
        self::validate_manifest_pins($pins, $p);
        $p->adapterSources->bind_explicit_pins($pins);
        if ($p->manifestDispositions !== null && class_exists(CapabilityRegistry::class)) {
            // Only the shipped subset is a registry claim. Handing an
            // out-of-tree manifest to registry validation would demand a claim
            // that cannot exist, so one site-installed adapter would refuse
            // every unrelated shipped adapter along with itself — the exact
            // failure DUO-3314 exists to remove.
            $p->capabilityRegistry = CapabilityRegistry::load(
                $dir,
                $p->manifestDispositions,
                $p->adapterSources->shipped_manifests($p->manifests)
            );
            if ($p->capabilityRegistry === null) {
                throw new \RuntimeException(
                    "duo: $dir has manifest dispositions but no generated capability registry; "
                    . 'missing registry data is unsupported'
                );
            }
        }
        return $p;
    }

    /**
     * Serialize the already-validated policy inputs for a fresh verification
     * process. The compiled artifact independently binds the site and
     * manifest hashes; this snapshot carries the bytes needed to reconstruct
     * that exact policy without reopening mutable repository files.
     */
    public function export_snapshot(): array {
        $adapterSources = $this->adapter_sources()->export();
        return [
            'format' => ($adapterSources['format'] ?? null) === AdapterSources::LEGACY_FORMAT
                ? self::LEGACY_SNAPSHOT_FORMAT
                : self::SNAPSHOT_FORMAT,
            'site' => $this->site,
            'manifests' => $this->manifests,
            'adapter_sources' => $adapterSources,
            'dispositions' => $this->manifestDispositions?->data(),
            'capabilities' => $this->capabilityRegistry?->data(),
        ];
    }

    /** Reconstruct and fully validate a policy exported by export_snapshot(). */
    public static function from_snapshot(array $snapshot): self {
        self::assert_single_site();
        $keys = array_keys($snapshot);
        sort($keys, SORT_STRING);
        $snapshotFormat = $snapshot['format'] ?? null;
        if ($keys !== ['adapter_sources', 'capabilities', 'dispositions', 'format', 'manifests', 'site']
            || !in_array($snapshotFormat, [self::LEGACY_SNAPSHOT_FORMAT, self::SNAPSHOT_FORMAT], true)
            || !is_array($snapshot['adapter_sources'] ?? null)
            || !is_array($snapshot['site'] ?? null)
            || !is_array($snapshot['manifests'] ?? null)
            || !array_is_list($snapshot['manifests'])) {
            throw new \RuntimeException('duo: frozen policy snapshot has an unsupported or malformed shape');
        }
        $adapterSourceFormat = $snapshot['adapter_sources']['format'] ?? null;
        if (($snapshotFormat === self::LEGACY_SNAPSHOT_FORMAT
                && $adapterSourceFormat !== AdapterSources::LEGACY_FORMAT)
            || ($snapshotFormat === self::SNAPSHOT_FORMAT
                && $adapterSourceFormat !== AdapterSources::FORMAT)) {
            throw new \RuntimeException(
                'duo: frozen policy snapshot format disagrees with its adapter source record format'
            );
        }

        $p = new self();
        $p->site = $snapshot['site'];
        self::validate_code_config($p->site, 'frozen site.duo.json');
        self::validate_scope_classes($p->site, 'frozen site.duo.json', true);
        self::validate_option_storage($p->site['policy'] ?? [], 'frozen site.duo.json');
        self::validate_env_options($p->site['policy'] ?? [], 'frozen site.duo.json');
        self::validate_user_meta_rules($p->site['policy'] ?? [], 'frozen site.duo.json');
        self::validate_tables($p->site['policy'] ?? [], 'frozen site.duo.json');
        self::validate_sub_keys($p->site['policy'] ?? [], 'frozen site.duo.json');
        self::validate_reference_shapes($p->site['policy'] ?? [], 'frozen site.duo.json');

        $pins = self::normalize_manifest_pins($p->site['manifests'] ?? ['core']);
        if (count($pins) !== count($snapshot['manifests'])) {
            throw new \RuntimeException('duo: frozen policy snapshot manifest count disagrees with site pins');
        }
        foreach ($snapshot['manifests'] as $i => $manifest) {
            if (!is_array($manifest) || array_is_list($manifest)) {
                throw new \RuntimeException("duo: frozen policy snapshot manifests[$i] is not an object");
            }
            $name = (string) ($manifest['name'] ?? '');
            if ($name === '' || !hash_equals((string) $pins[$i]['name'], $name)) {
                throw new \RuntimeException("duo: frozen policy snapshot manifest order/name disagrees with site pin $i");
            }
            self::validate_field_classes($manifest);
            self::validate_menu_field_classes($manifest);
self::validate_post_type_children($manifest);
            self::validate_post_type_contracts($manifest);
            self::validate_tables($manifest, "frozen manifest '$name'");
            self::validate_attr_rules($manifest);
            self::validate_widgets($manifest);
            self::validate_regen_dependencies($manifest);
            self::validate_providers($manifest);
            self::validate_actions($manifest);
            self::validate_env_options($manifest, "frozen manifest '$name'");
            self::validate_user_meta_rules($manifest, "frozen manifest '$name'");
            self::validate_scope_classes($manifest, "frozen manifest '$name'", false);
            self::validate_sub_keys($manifest, "frozen manifest '$name'");
            self::validate_object_type_option_refs($manifest);
            // DUO-3318: validate_dynamic_options() was missing here while
            // load() had called it since DUO-3264. A frozen snapshot is
            // re-validated precisely so a verification process reaches the
            // same verdict as the process that froze it; one skipped
            // validator makes that promise conditional on which entry point
            // ran, which is exactly the class of divergence this method
            // exists to rule out.
            self::validate_dynamic_options($manifest);
            self::validate_taxonomy_object_keyspace_declarations($manifest);
            self::validate_option_name_refs($manifest);
            self::validate_option_storage($manifest, "frozen manifest '$name'");
            self::validate_adapter_contract($manifest);
            self::validate_effect_contracts($manifest);
            self::validate_discovery_contract($manifest);
            self::validate_reference_shapes($manifest, "frozen manifest '$name'");
            $p->manifests[] = $manifest;
        }
        // Provenance is reconstructed before the reviewed registries so both of
        // them see the same shipped subset load() gave them (DUO-3314).
        $p->adapterSources = AdapterSources::from_snapshot($snapshot['adapter_sources'], $p->manifests);
        self::validate_manifest_sources($pins, $p->adapterSources);
        $shipped = $p->adapterSources->shipped_manifests($p->manifests);
        $dispositions = $snapshot['dispositions'] ?? null;
        if ($dispositions !== null) {
            if (!is_array($dispositions) || !class_exists(ManifestDispositions::class)) {
                throw new \RuntimeException('duo: frozen policy snapshot disposition registry is unavailable or malformed');
            }
            $p->manifestDispositions = ManifestDispositions::from_snapshot($dispositions, $shipped);
        }
        $capabilities = $snapshot['capabilities'] ?? null;
        if ($capabilities !== null) {
            if (!is_array($capabilities) || $p->manifestDispositions === null
                || !class_exists(CapabilityRegistry::class)) {
                throw new \RuntimeException('duo: frozen policy snapshot capability registry is unavailable or malformed');
            }
            $p->capabilityRegistry = CapabilityRegistry::from_snapshot(
                $capabilities,
                $p->manifestDispositions,
                $shipped
            );
        } elseif ($p->manifestDispositions !== null) {
            throw new \RuntimeException('duo: frozen policy snapshot has dispositions but no capability registry');
        }
        self::validate_no_conflicting_option_rules(
            $p->manifests,
            $p->site['policy']['options'] ?? []
        );
        self::validate_no_overlapping_option_name_refs($p->manifests);
        self::validate_no_conflicting_adapter_claims($p->manifests);
        self::validate_no_conflicting_provider_ids($p->manifests);
        self::validate_no_conflicting_post_type_contracts($p->manifests);
        self::validate_one_owner_per_declared_name($p->manifests);
        self::validate_ref_kinds($p->manifests, $p->site['policy'] ?? []);
        self::validate_unique_table_id_kinds($p->declared_tables());
        self::validate_no_conflicting_taxonomy_object_keyspaces($p->manifests);
        self::validate_no_conflicting_description_reference_rules($p->manifests);
        self::validate_reference_keyspaces_and_sidecars($p);
        self::validate_manifest_pins($pins, $p);
        $p->adapterSources->bind_explicit_pins($pins);
        return $p;
    }

    /**
     * Adapter provenance for this policy. Never null after load()/from_
     * snapshot(); the fallback covers only a policy built by an offline test
     * harness that never ran either, and it claims nothing (no sources known,
     * so nothing is out-of-tree and nothing is laundered).
     */
    public function adapter_sources(): AdapterSources {
        return $this->adapterSources ??= AdapterSources::discover(self::manifests_dir(), null);
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
        return $this->adapter_sources()->provenance($name) ?? $this->manifestDispositions?->entry($name);
    }

    /** The generated evidence-bound claim for one pinned adapter. */
    public function capability_claim(string $name): ?array {
        return $this->adapter_sources()->claim($name) ?? $this->capabilityRegistry?->claim($name);
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
            $this->manifests,
            ['operation' => 'promote'],
            CapabilityRegistry::probe_target(),
            $this->adapter_sources()->diagnostics($this->manifests),
            $this->adapter_sources()->certification_contexts()
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
            $this->provider_readiness_blockers($this->actions())
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
        if ($providerActions === [] || $this->manifestDispositions === null || $this->capabilityRegistry === null) {
            return [];
        }

        // These classes are intentionally late-bound: Policy retains its
        // pure/offline loading entry point, while a real target path gains the
        // one runtime contract Deploy and Providers already share.
        require_once __DIR__ . '/Deploy.php';
        require_once __DIR__ . '/Providers.php';
        if (!Providers::runtime_negotiation_available()) {
            return [];
        }

        $negotiation = Providers::negotiate($this, $providerActions);
        $sources = $this->adapter_sources()->diagnostics($this->manifests);
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
            $this->manifests,
            $query,
            CapabilityRegistry::probe_target(),
            $this->adapter_sources()->diagnostics($this->manifests),
            $this->adapter_sources()->certification_contexts()
        );
        $providerBlockers = $this->provider_readiness_blockers($this->actions());
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

    /**
     * The v0 code half is deliberately opt-in and deliberately narrow. Do
     * not accept a tempting near-miss here: a future layout must get a new
     * format rather than silently being interpreted as this payload format.
     */
    private static function validate_code_config(array $site, string $label): void {
        if (!array_key_exists('code', $site)) {
            return; // legacy state-only repositories remain fully supported
        }
        $code = $site['code'];
        if (!is_array($code) || array_is_list($code)) {
            throw new \RuntimeException(
                "duo: $label code must be an object with exactly format, layout, and source"
            );
        }
        try {
            Code::assert_config($code);
        } catch (\Throwable $t) {
            throw new \RuntimeException("duo: $label code declaration is invalid: {$t->getMessage()}", 0, $t);
        }
    }

    /** @return ?array{format:int,layout:string,source:string} */
    public function code_config(): ?array {
        $code = $this->site['code'] ?? null;
        return is_array($code) ? $code : null;
    }

    /**
     * `site.duo.json` originally accepted a flat list of manifest names. A
     * content pin is additive, never a flag day: each entry may instead be
     * {name,digest}, while strings keep their exact historical meaning. Keep
     * the declared digest separate from the loaded manifest so it cannot
     * accidentally participate in policy precedence or the manifest's own
     * content hash.
     *
     * DUO-3314 adds an equally optional `source`. Declaring it asserts WHICH
     * adapter source must answer this pin, and validate_manifest_sources()
     * refuses a mismatch: without it, removing a site-installed adapter and
     * later installing a shipped one under the same name would silently swap
     * which definition a site runs. An unknown key is refused outright rather
     * than ignored — a pin whose author believed it constrained something is
     * the failure this whole record exists to prevent.
     *
     * @return list<array{name:string,digest:?string,source:?string}>
     */
    private static function normalize_manifest_pins($rawPins): array {
        if (!is_array($rawPins) || !array_is_list($rawPins)) {
            throw new \RuntimeException('duo: site.duo.json manifests must be a JSON array');
        }
        $pins = [];
        foreach ($rawPins as $i => $raw) {
            if (is_string($raw) && $raw !== '') {
                AdapterSources::assert_name($raw, "site.duo.json manifests[$i]");
                $pins[] = ['name' => $raw, 'digest' => null, 'source' => null];
                continue;
            }
            if (!is_array($raw) || !is_string($raw['name'] ?? null) || $raw['name'] === '') {
                throw new \RuntimeException(
                    "duo: site.duo.json manifests[$i] must be a non-empty name string or an object with "
                    . 'a non-empty string name and optional digest and source'
                );
            }
            AdapterSources::assert_name($raw['name'], "site.duo.json manifests[$i].name");
            $unknown = array_diff(array_keys($raw), ['name', 'digest', 'source']);
            if ($unknown !== []) {
                throw new \RuntimeException(
                    "duo: site.duo.json manifest '{$raw['name']}' declares unknown pin key(s) "
                    . implode(',', $unknown) . ' — a pin accepts exactly name, digest, and source'
                );
            }
            $digest = $raw['digest'] ?? null;
            if ($digest !== null && (!is_string($digest) || !preg_match('/^[a-f0-9]{64}$/', $digest))) {
                throw new \RuntimeException(
                    "duo: site.duo.json manifest '{$raw['name']}' has an invalid digest; expected 64 lowercase "
                    . 'hexadecimal characters'
                );
            }
            $source = $raw['source'] ?? null;
            // DUO-3339 adds the third source word. It is accepted in a pin for
            // the same reason the other two are: validate_manifest_sources()
            // below refuses a pin whose named source stops answering, which is
            // the only thing that makes writing one down worth anything. A
            // `plugin` pin is a deliberate statement that this site runs a
            // definition a plugin bundles — and because precedence ranks the
            // sources, a site or shipped adapter later claiming that name makes
            // the pin refuse loudly rather than silently swapping the winner.
            if ($source !== null && !in_array(
                $source,
                [AdapterSources::SHIPPED, AdapterSources::SITE, AdapterSources::PLUGIN],
                true
            )) {
                throw new \RuntimeException(
                    "duo: site.duo.json manifest '{$raw['name']}' declares source " . var_export($source, true)
                    . ' — the installed adapter sources are "' . AdapterSources::SHIPPED . '", "'
                    . AdapterSources::SITE . '", and "' . AdapterSources::PLUGIN . '"'
                );
            }
            $pins[] = ['name' => $raw['name'], 'digest' => $digest, 'source' => $source];
        }
        return $pins;
    }

    /**
     * A declared pin source is a refusal, not a preference: the overlay is
     * resolved by name, so an operator who wrote down where an adapter comes
     * from must be told when that stops being true rather than quietly served
     * the other source's definition.
     *
     * @param list<array{name:string,digest:?string,source:?string}> $pins
     */
    private static function validate_manifest_sources(array $pins, AdapterSources $sources): void {
        foreach ($pins as $pin) {
            if ($pin['source'] === null) {
                continue;
            }
            // A name nothing installed has no source to disagree with, and
            // source() answers `shipped` by default. Reporting that as "you
            // pinned plugin but it resolves from the shipped source" describes
            // a shipped adapter that does not exist, and — since DUO-3339 —
            // hides the honest answer: file() below throws the plugin
            // source's own recorded refusal for exactly this name, with its
            // remediation, or a not-found naming every source searched.
            if ($sources->path($pin['name']) === null) {
                continue;
            }
            $actual = $sources->source($pin['name']);
            if ($actual !== $pin['source']) {
                throw new \RuntimeException(
                    "duo: manifest '{$pin['name']}' is pinned to the {$pin['source']} adapter source but resolves "
                    . "from the $actual source — review which adapter this site intends to run, then update the "
                    . 'site.duo.json pin'
                );
            }
        }
    }

    /**
     * Compare against DUO-3222's resolved_adapters() result instead of
     * inventing a second digest implementation. Validation happens only
     * after every manifest and cross-manifest contract has passed, so a pin
     * can never turn malformed adapter content into a trusted artifact.
     *
     * @param list<array{name:string,digest:?string,source:?string}> $pins
     */
    private static function validate_manifest_pins(array $pins, self $policy): void {
        if (!array_filter($pins, fn($pin) => $pin['digest'] !== null)) {
            return;
        }
        $resolved = RepositoryCompiler::resolved_adapters($policy);
        foreach ($pins as $i => $pin) {
            if ($pin['digest'] === null) {
                continue;
            }
            $actual = (string) ($resolved[$i]['digest'] ?? '');
            if (!hash_equals($pin['digest'], $actual)) {
                throw new \RuntimeException(
                    "duo: manifest '{$pin['name']}' digest mismatch: expected {$pin['digest']}, actual $actual — "
                    . 'review the manifest change, then update its site.duo.json pin'
                );
            }
        }
    }

    /**
     * Pattern-fallback manifest arrays, keyed by the section they apply to.
     * `option_patterns` predates this map (kept as its original name for
     * backward compat with shipped manifests, e.g. core.json's
     * `^_transient_` rule); `meta_patterns` is new (task #11 wave 2 /
     * docs/frontier/elementor.md's finding: "post_meta/term_meta
     * classification has no pattern-matching escape hatch" — Elementor's
     * `_elementor_migrations_state_<hash>` is exactly the versioned-suffix
     * shape that needs it). Deliberately NOT post-type-scoped, unlike the
     * report's own suggestion: exact-match post_meta/term_meta rules
     * already aren't post-type-scoped in this engine (Policy::rule() has
     * never taken a post type), so a pattern fallback that suddenly needed
     * one would be a new, inconsistent axis rather than "mirroring
     * option_patterns" — a meta key name is either safe to classify by
     * pattern everywhere it appears, or it isn't; a plugin's own key-naming
     * convention already makes collisions with an unrelated plugin's keys
     * exceedingly unlikely, the same trust the exact-match case already
     * extends. term_meta gets the same fallback for free, at zero extra
     * cost, since it shares this one lookup path.
     *
     * @var array<string, string>
     */
    private const PATTERN_KEYS = [
        'options' => 'option_patterns',
        'post_meta' => 'meta_patterns',
        'term_meta' => 'meta_patterns',
    ];

    /**
     * Resolve a policy rule together with the declaration that won. Apply's
     * repository authorization gate needs the source as evidence: a refusal
     * that only says "runtime" but not whether site.duo.json or which pinned
     * manifest made that decision is not actionable enough to repair safely.
     *
     * DUO-3249: a NON-core manifest's own declaration of a name ALSO
     * declared by the core manifest always outranks core's, regardless of
     * relative pin order. This is not a general "later pin wins" rule (see
     * the loop below — among two or more NON-core manifests declaring the
     * same name, the FIRST one in pin order still wins, completely
     * unchanged from before this fix; that remaining ambiguity is a
     * separate, undecided question, DUO-3255, deliberately not touched
     * here). It specifically encodes DESIGN.md §3.1's own numbered
     * precedence order — "1. Core schema rules" then "2. Plugin manifests"
     * — as an actual load-bearing precedence rather than merely descriptive
     * prose: every shipped site.duo.json pins `core` FIRST (grep-verified,
     * not assumed), so a plain first-pin-order walk would have let core's
     * own declaration win over ANY later plugin manifest's deliberate
     * reclassification of the same option, every single time, silently —
     * exactly backwards from "layer 2 refines layer 1", and exactly what
     * left Polylang's per-language `default_category` divergence
     * undetected until live grind evidence forced the question (DUO-3249).
     * A plugin manifest reclassifying a core option is therefore always
     * loud and deliberate by construction (it only ever WINS, never
     * silently collides) — `Policy::active_reclassifications()` is what
     * makes it plan-visible too, so "loud" extends to runtime output, not
     * just load-time precedence.
     *
     * @return array{rule:?array, source:?string}
     */
    private function rule_details(string $section, string $name): array {
        $sitePolicy = $this->site['policy'][$section][$name] ?? null;
        if ($sitePolicy !== null) {
            return [
                'rule' => $section === 'options'
                    ? self::with_option_autoload($sitePolicy, $this->site['policy'] ?? [])
                    : $sitePolicy,
                'source' => 'site.duo.json',
            ];
        }
        $coreMatch = null;
        foreach ($this->manifests as $m) {
            if (!isset($m[$section][$name])) {
                continue;
            }
            $found = [
                'rule' => $section === 'options'
                    ? self::with_option_autoload($m[$section][$name], $m)
                    : $m[$section][$name],
                'source' => (string) ($m['name'] ?? '?'),
            ];
            if ($found['source'] === 'core') {
                $coreMatch = $found; // keep scanning: a non-core manifest's own declaration still outranks this
                continue;
            }
            return $found;
        }
        if ($coreMatch !== null) {
            return $coreMatch;
        }
        $patternKey = self::PATTERN_KEYS[$section] ?? null;
        if ($patternKey !== null) {
            foreach ($this->manifests as $m) {
                foreach ($m[$patternKey] ?? [] as $pat) {
                    if (preg_match('/' . $pat['match'] . '/', $name)) {
                        return [
                            'rule' => $section === 'options'
                                ? self::with_option_autoload(array_diff_key($pat, ['match' => true]), $m)
                                : array_diff_key($pat, ['match' => true]),
                            'source' => (string) ($m['name'] ?? '?'),
                        ];
                    }
                }
            }
        }
        return ['rule' => null, 'source' => null];
    }

    private function rule(string $section, string $name): ?array {
        return $this->rule_details($section, $name)['rule'];
    }

    public function option_rule(string $name): ?array {
        return $this->rule('options', $name);
    }

    /** @return array{rule:?array, source:?string} */
    public function option_rule_details(string $name): array {
        return $this->rule_details('options', $name);
    }

    /**
     * Return the manifest namespace that claims discovery responsibility for
     * an option name. A namespace is deliberately ownership-only: it does
     * not classify the value. The ordinary exact/pattern rule must still do
     * that, otherwise Capture/Pending report the row as an unknown.
     *
     * @return ?array{owner:string, match:string}
     */
    public function option_namespace(string $name): ?array {
        $matches = [];
        foreach ($this->manifests as $m) {
            foreach ($m['option_namespaces'] ?? [] as $decl) {
                if (preg_match('/' . $decl['match'] . '/', $name)) {
                    $matches[] = [
                        'owner' => (string) ($m['name'] ?? '?'),
                        'match' => (string) $decl['match'],
                    ];
                }
            }
        }
        if (count($matches) > 1) {
            throw new \RuntimeException(
                "duo: option '$name' is claimed by overlapping namespaces from "
                . implode(', ', array_map(fn($m) => $m['owner'], $matches))
                . ' — discovery ownership must not depend on manifest load order'
            );
        }
        return $matches[0] ?? null;
    }

    /** Classification for a namespace-owned option, with owner agreement. */
    public function owned_option_rule(string $name): ?array {
        $owner = $this->option_namespace($name);
        if ($owner === null) {
            return null;
        }
        $details = $this->option_rule_details($name);
        if ($details['rule'] !== null && $details['source'] !== 'site.duo.json'
            && $details['source'] !== $owner['owner']) {
            throw new \RuntimeException(
                "duo: option '$name' namespace is owned by '{$owner['owner']}' but its classification comes from "
                . "'{$details['source']}' — cross-manifest ownership is ambiguous"
            );
        }
        return $details['rule'];
    }

    public function post_meta_rule(string $key): ?array {
        return $this->rule('post_meta', $key);
    }

    /** @return array{rule:?array, source:?string} */
    public function post_meta_rule_details(string $key): array {
        return $this->rule_details('post_meta', $key);
    }

    public function term_meta_rule(string $key): ?array {
        return $this->rule('term_meta', $key);
    }

    public function user_meta_rule(string $key): ?array {
        return $this->rule('user_meta', $key);
    }

    public function table_rule(string $unprefixedTable): ?array {
        return $this->declared_table_details($unprefixedTable)['rule'];
    }

    /**
     * Snapshot consumes declared_tables(), whose established precedence is
     * last pinned manifest then site override. Report the source of that same
     * effective declaration; using rule_details() here would reproduce the
     * older first-manifest single-name inconsistency instead of the rule the
     * typed-snapshot writer actually follows.
     *
     * @return array{rule:?array, source:?string}
     */
    public function declared_table_details(string $name): array {
        $rule = null;
        $source = null;
        foreach ($this->manifests as $m) {
            if (isset($m['tables'][$name])) {
                $rule = $m['tables'][$name];
                $source = (string) ($m['name'] ?? '?');
            }
        }
        if (isset($this->site['policy']['tables'][$name])) {
            $rule = $this->site['policy']['tables'][$name];
            $source = 'site.duo.json';
        }
        return ['rule' => $rule, 'source' => $source];
    }

    /**
     * Option names classified authored (the capture whitelist).
     *
     * DUO-3255: resolved_exact_options() is the single precedence path for
     * this and the env/sub-key bulk enumerators. It delegates every name to
     * rule_details(), so bulk lookup can never silently use a different pin
     * winner than the per-name capture/apply path. Policy::load() has
     * already refused contradictory non-core declarations; identical ones
     * dedupe here, while core-yields-to-plugin and site override precedence
     * remain exactly the rule_details() contract.
     */
    public function authored_options(): array {
        $out = [];
        foreach ($this->resolved_exact_options() as $name => $r) {
            if (($r['class'] ?? '') === 'authored') {
                $out[$name] = $r;
            }
        }
        return $out;
    }

    /**
     * Options declaring `sub_keys` (DUO-3233): name => full rule (including
     * the `sub_keys` map). Sibling enumeration to authored_options() above,
     * same merge precedence (site policy replaces a manifest's whole rule
     * wholesale, never a deep merge — a site overriding options.<name> is
     * expected to repeat sub_keys if it still wants any of it, exactly like
     * it already must repeat 'class' today) — but keyed on "declares
     * sub_keys" instead of "class === authored", because a sub_keys option's
     * OWN top-level class is legitimately something else (Polylang's
     * `polylang`/Yoast's `wpseo` are both 'env': excluded whole, except the
     * named sub-keys carved out below them).
     *
     * This is the engine capability manifests/polylang.json's own notes
     * long flagged as missing: "v0's options model classifies a whole
     * option name at once ... there is no way to keep force_lang/
     * default_lang/etc authored while excluding first_activation/version
     * without capturing them too. Building that sub-key classification
     * split is a genuinely separate, unscoped engine capability." This is
     * that capability — a NAMED sub-key of one option blob captured/
     * excluded independently, with apply-side merge into the live blob
     * (Apply::apply_option_sub_keys()) so the undeclared remainder is never
     * clobbered. DUO-3211's review comment asked for exactly this: "'exact'
     * [option] reconciliation should be written so per-key ownership can
     * later narrow to sub-key ownership without another format change" —
     * `sub_keys` on an ordinary options.<name> rule IS that narrowing, not
     * a parallel format.
     *
     * @return array<string, array{class:string, sub_keys:array<string,array>}>
     */
    public function sub_keyed_options(): array {
        $out = [];
        foreach ($this->resolved_exact_options() as $name => $r) {
            if (!empty($r['sub_keys'])) {
                $out[$name] = $r;
            }
        }
        return $out;
    }

    /**
     * Resolve every exact option name once through rule_details() — never
     * reimplement its precedence with a bulk foreach overwrite. Names from
     * site policy are included even when no manifest declares them. Pattern
     * rules remain discovery/classification rules and are deliberately not
     * enumerated here, matching these bulk APIs' historical exact-name
     * contract.
     *
     * @return array<string,array>
     */
    private function resolved_exact_options(): array {
        $names = [];
        foreach ($this->manifests as $manifest) {
            foreach ($manifest['options'] ?? [] as $name => $_rule) {
                $names[(string) $name] = true;
            }
        }
        foreach ($this->site['policy']['options'] ?? [] as $name => $_rule) {
            $names[(string) $name] = true;
        }
        $out = [];
        foreach (array_keys($names) as $name) {
            $rule = $this->option_rule_details($name)['rule'];
            if ($rule !== null) {
                $out[$name] = $rule;
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * DUO-3264 (owner ruling, fork A, issue comment 9fd882a6): "a manifest-
     * level dynamic-name resolution primitive... one new primitive, reusable
     * for any future active-theme-bound option, instead of a second bespoke
     * path beside nav_menu_locations." theme_mods_<stylesheet> is the proven
     * case (see manifests/core.json's own declaration + note) but this
     * section is deliberately not theme_mods-specific: any manifest may
     * declare an entry under any key.
     *
     * Shape: `{"<declaration key>": {"prefix": "...", "resolver": "...",
     * "sub_keys": {...}}}`. `prefix` + the resolver's live value (supplied
     * by the CALLER — Capture.php/Apply.php, which already call get_option()
     * freely; this class stays WordPress-free/offline-testable by design,
     * same posture as every other Policy.php method) concatenate to the one
     * option name that is authored-eligible right now (e.g. "theme_mods_" .
     * "storefront" = "theme_mods_storefront"). `sub_keys` is the ordinary
     * sub_keys grammar (Policy::sub_keyed_options()'s own shape,
     * Apply::apply_option_sub_keys()'s own merge-into-live-blob machinery),
     * applied against that ONE resolved name — no new capture/apply
     * machinery, only a new way to find the option's own NAME.
     *
     * Every OTHER live option name sharing the same `prefix` (a theme_mods_*
     * row for a theme that is not currently active) is env-local residue by
     * this SAME declaration, per the ruling's own required semantics — see
     * is_dynamic_option_residue() below, the query surface a caller uses to
     * recognize and skip such a row (never unclassified-pending, never
     * captured) without a second, separately-maintained exclusion list.
     *
     * First-declaring-manifest wins per declaration key, matching this
     * class's own established enumeration precedence elsewhere (no shipped
     * manifest is expected to collide on a key here — only core.json is
     * expected to ever declare theme_mods — but the tie-break is defined
     * for the same reason it is everywhere else in this file: consistency,
     * not because a real collision is anticipated).
     *
     * @return array<string, array{prefix:string, resolver:string, sub_keys:array<string,array>, autoload:?string}>
     */
    public function dynamic_options(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['dynamic_options'] ?? [] as $key => $r) {
                if (isset($out[$key])) {
                    continue; // first-declaring-manifest wins
                }
                $out[$key] = self::with_option_autoload($r, $m);
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * Resolve one declared dynamic_options entry against a caller-supplied
     * resolved value (e.g. the live active stylesheet slug for the
     * "active_stylesheet" resolver) into the one concrete option name that
     * is authored-eligible right now, plus its sub_keys rule. 'class' is
     * always 'env' — a dynamic_options row's own containing blob is, by
     * definition, mostly environment-local except its declared sub_keys,
     * the identical "whole value env, named sub-keys authored" shape
     * sub_keyed_options() already uses for polylang/wpseo; not made
     * manifest-declarable since no other value has ever been a real,
     * grounded need (widen this the day one is).
     *
     * @return ?array{name:string, class:string, sub_keys:array<string,array>, autoload:?string}
     */
    public function resolve_dynamic_option(string $key, string $resolvedValue): ?array {
        $decl = $this->dynamic_options()[$key] ?? null;
        if ($decl === null) {
            return null;
        }
        return [
            'name' => $decl['prefix'] . $resolvedValue,
            'class' => 'env',
            'sub_keys' => $decl['sub_keys'],
            'autoload' => $decl['autoload'] ?? null,
        ];
    }

    /**
     * True when $liveName shares a declared dynamic_options entry's prefix
     * but is NOT the one name resolve_dynamic_option() would currently
     * produce for that same entry — a theme_mods_* row for a theme that
     * used to be active, kept by WordPress itself so nothing is lost if the
     * site switches back (confirmed empirically, DUO-3264: this is the same
     * shape of residue as the nested sidebars_widgets/wp_classic_sidebars
     * theme-switch bookkeeping already excluded in manifests/core.json's own
     * note). $resolvedValues maps resolver name => this environment's own
     * live value (e.g. `['active_stylesheet' => get_option('stylesheet')]`)
     * — plural because a future second resolver is anticipated by the
     * ruling's own "reusable for any future active-theme-bound option"
     * framing, not because more than one exists yet.
     *
     * @param array<string,string> $resolvedValues
     */
    public function is_dynamic_option_residue(string $liveName, array $resolvedValues): bool {
        foreach ($this->dynamic_options() as $decl) {
            $prefix = $decl['prefix'];
            if (!str_starts_with($liveName, $prefix)) {
                continue;
            }
            $resolvedValue = $resolvedValues[$decl['resolver']] ?? null;
            if ($resolvedValue === null) {
                continue; // this environment supplied no live value for the declared resolver -- not this method's call to guess
            }
            return $liveName !== ($prefix . $resolvedValue);
        }
        return false;
    }

    /**
     * The lookup Apply::option_apply_target() needs at actual apply time:
     * given a captured document's own option key, find the one
     * dynamic_options declaration (if any) it currently, EXACTLY resolves
     * to on THIS environment, and return its sub_keys rule — or null if
     * $name matches no declared prefix, or matches one but is NOT the
     * currently-resolved row (residue; see is_dynamic_option_residue() —
     * never guessed at, never silently applied to the wrong theme's own
     * row). Safe to require an exact match here specifically because
     * Apply::apply()'s own theme-mismatch refuse-gate (DUO-3216) already
     * guarantees the target's active theme matches what was captured by
     * the time this method is ever reached (the identical invariant
     * assign_locations()/nav_menu_locations already depends on) — this is
     * NOT the method RepositoryAuthorization::authorize_options() uses;
     * see dynamic_option_rule_for_prefix() below for why authorization
     * needs a looser check.
     *
     * @param array<string,string> $resolvedValues
     * @return ?array{class:string, sub_keys:array<string,array>, autoload:?string}
     */
    public function dynamic_option_rule_for_name(string $name, array $resolvedValues): ?array {
        foreach ($this->dynamic_options() as $key => $decl) {
            if (!str_starts_with($name, $decl['prefix'])) {
                continue;
            }
            $resolvedValue = $resolvedValues[$decl['resolver']] ?? null;
            if ($resolvedValue === null) {
                // DUO-3318: a silent `continue` here used to turn an ENGINE
                // wiring gap into an unclassified option. $resolvedValues is
                // assembled by the caller from the resolver names this policy
                // itself declares (Apply::dynamic_option_resolver_values()),
                // so a missing entry can only mean the caller's own resolver
                // map fell behind DYNAMIC_OPTION_RESOLVERS — never a data
                // state a target can be in. Continuing produced a null return
                // indistinguishable from "no declaration matches", which
                // capture/apply then reports as an unclassified row: a
                // confusing symptom arbitrarily far from the missing map
                // entry that caused it. is_dynamic_option_residue() keeps its
                // own `continue` deliberately — that method answers a
                // yes/no question ABOUT a live environment and is documented
                // as refusing to guess when the environment supplied nothing.
                throw new \RuntimeException(
                    "duo: dynamic_options.$key declares resolver '{$decl['resolver']}' but this caller supplied no "
                    . 'value for it (supplied: ' . (($resolvedValues === []) ? 'none' : implode(', ', array_keys($resolvedValues)))
                    . ") — the resolver vocabulary is engine-owned and every declared resolver must be resolved by the "
                    . 'engine call site, not skipped'
                );
            }
            $resolved = $this->resolve_dynamic_option($key, $resolvedValue);
            if ($resolved !== null && $resolved['name'] === $name) {
                return ['class' => $resolved['class'], 'sub_keys' => $resolved['sub_keys'], 'autoload' => $resolved['autoload']];
            }
        }
        return null;
    }

    /**
     * The lookup RepositoryAuthorization::authorize_options() needs
     * instead — deliberately LOOSER than dynamic_option_rule_for_name()
     * above: matches $name against a declared prefix alone, independent of
     * which theme is active on THIS environment right now. Authorization
     * runs as part of RepositoryCompiler::compile(), which BOTH `wp duo
     * deploy` and `wp duo apply` go through — including deploy itself, the
     * command that reconciles a theme mismatch in the first place (DUO-3216).
     * Requiring an exact match here would make deploy unable to compile the
     * very repository it needs to read to know which theme to switch to, a
     * genuine circular dependency (caught live, not by inspection: `wp duo
     * deploy` itself refused with 'theme_mods_<captured-theme>' unclassified
     * while the target was still on its PREVIOUS theme). Authorization's own
     * checks (declared sub_keys class, autoload) are already theme-agnostic
     * in substance — they validate the DECLARATION, not the live
     * environment — so this method never needed the exact-match constraint
     * dynamic_option_rule_for_name() correctly enforces for the different
     * question (never write to the wrong theme's own row), which only
     * matters once actual mutation is about to happen, well after DUO-3216's
     * own refuse-gate has already run.
     *
     * @return ?array{class:string, sub_keys:array<string,array>, autoload:?string}
     */
    public function dynamic_option_rule_for_prefix(string $name): ?array {
        foreach ($this->dynamic_options() as $decl) {
            if (str_starts_with($name, $decl['prefix'])) {
                return ['class' => 'env', 'sub_keys' => $decl['sub_keys'], 'autoload' => $decl['autoload'] ?? null];
            }
        }
        return null;
    }

    /**
     * Every declared table rule, keyed by unprefixed table name, merged
     * across manifests (last pinned manifest declaring a given table wins,
     * matching table_rule()/declared_table_details()) with site policy
     * overrides applied last. Snapshot.php filters this by `class` itself (row-shaped
     * "authored_snapshot" vs attached-meta "authored_snapshot_meta" vs the
     * honest-intent-only "authored_typed_snapshot_post_v1" markers that have
     * no engine effect) — this accessor just answers "what did every pinned
     * manifest + this site's own policy say about tables," mirroring
     * authored_options()'s shape for the tables section.
     */
    public function declared_tables(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['tables'] ?? [] as $name => $r) {
                $out[$name] = $r;
            }
        }
        foreach ($this->site['policy']['tables'] ?? [] as $name => $r) {
            $out[$name] = $r;
        }
        return $out;
    }

    /**
     * The closed `tables.<name>.class` vocabulary (DUO-3318).
     *
     * Exactly two values carry engine behavior: Snapshot.php selects
     * `authored_snapshot` (a row table with identity of its own) and
     * `authored_snapshot_meta` (an EAV sidecar with none) and deliberately
     * gives no meaning to anything else. The remaining four are honest
     * dispositions rather than mechanisms — `authored_typed_snapshot_post_v1`
     * is the pre-existing "declared, and loudly not implemented yet" marker,
     * and runtime/derived/env record a reviewed decision that a table's
     * contents are target-local (core.json alone classifies 44 tables that
     * way, which is exactly what keeps them out of `duo pending`'s unknown
     * queue). The two literals repeat Snapshot::CLASS_ROW/CLASS_META rather
     * than referencing them: this file must stay loadable with no other
     * engine class present (RepositoryCompiler validates a revision in a
     * process that never constructs Ledger/Tokens/Snapshot), and both
     * spellings are wire format a manifest already carries, not an internal
     * name either side is free to change.
     *
     * Closed because a typo was previously indistinguishable from a
     * deliberate inert marker: `authored_snaphot` simply never matched
     * Snapshot's own filter, so the table silently dropped out of capture
     * with no diagnostic anywhere — a whole plugin's authored rows missing
     * from canonical state because of one transposed letter. The vocabulary
     * is engine-owned: a new class value means new engine behavior, so it is
     * an engine change with a spec bump, never a manifest declaration.
     */
    private const TABLE_CLASSES = [
        'authored_snapshot',
        'authored_snapshot_meta',
        'authored_typed_snapshot_post_v1',
        'runtime',
        'derived',
        'env',
    ];

    /** The closed `tables.<t>.identity.mode` vocabulary (see assert_table_grammar()). */
    private const IDENTITY_MODES = ['mapped', 'natural_key', 'composite_ref'];

    /**
     * The natural-key identity components of one table declaration, in the
     * exact order the manifest declared them (DUO-3318).
     *
     * `{"column": "<col>"}` is the original single-column spelling and stays
     * valid as the 1-component case; `{"columns": [...]}` is the parent-scoped
     * form, for a table whose authored key is unique only WITHIN a parent row
     * (a slot code unique per room, an option key unique per form). Declared
     * order is load-bearing — it is part of the derivation input, so
     * reordering `columns` is an identity change, not a cosmetic edit; that
     * is why this returns the list as written rather than sorting it.
     *
     * Returns [] for any other identity mode, so a caller can branch on
     * emptiness without repeating the mode check.
     *
     * @return list<string>
     */
    public static function natural_key_columns(array $decl): array {
        if (!is_array($decl['identity'] ?? null) || ($decl['identity']['mode'] ?? 'mapped') !== 'natural_key') {
            return [];
        }
        $identity = $decl['identity'];
        if (array_key_exists('columns', $identity)) {
            return array_values(array_map('strval', (array) $identity['columns']));
        }
        return array_key_exists('column', $identity) ? [(string) $identity['column']] : [];
    }

    /**
     * The pure-grammar half of a `tables.<name>` declaration (DUO-3318).
     *
     * Everything checkable from the manifest bytes alone lives here, and this
     * is the only implementation of it: Policy::load()/from_snapshot() run it
     * for every declared table so a malformed declaration refuses offline,
     * before any target contact, and Snapshot::assert_row_schema()/
     * assert_meta_schema() run it again immediately before their own LIVE
     * `SHOW COLUMNS` half (re-checking a pure function of already-loaded bytes
     * costs nothing, and keeps a directly-constructed Policy — the shape
     * several offline harnesses build — covered by the same rules).
     *
     * The split is exactly "does answering this need the database": every
     * check below reads only $decl. The two facts that stay in Snapshot are
     * the ledger COLUMN WIDTHS (duo_map.id_kind, duo_map.entity_type), which
     * are Ledger's schema rather than the manifest's grammar — and, decisively,
     * naming Ledger here would drag a second engine class into a file whose
     * whole point is that it loads alone.
     *
     * $source names the declaring manifest when one is known. It is appended,
     * never interpolated into the existing sentences, so Snapshot's long-
     * standing "duo: table '<t>' …" wordings stay byte-identical for the
     * capture-time caller that has no manifest name to report.
     */
    public static function assert_table_grammar(string $table, mixed $decl, ?string $source = null): void {
        $where = $source === null ? '' : " (declared by $source)";
        // `mixed`, not `array`, so a scalar or list declaration produces this
        // engine's ordinary "duo: " refusal rather than a PHP TypeError at the
        // call site — a promise assert_table_section_shapes() below now keeps
        // for the declaration's INNER sections too, which used to reach
        // array_column()/array_keys() as scalars and raise a TypeError. An
        // EMPTY object decodes to `[]`, which array_is_list() calls a list, so
        // it deliberately falls through to the class check below — "declares
        // class=NULL" says far more than "not an object".
        if (!is_array($decl) || (array_is_list($decl) && $decl !== [])) {
            throw new \RuntimeException(
                "duo: table '$table'$where must be declared as an object of table rules, got " . gettype($decl)
            );
        }
        $class = $decl['class'] ?? null;
        if (!in_array($class, self::TABLE_CLASSES, true)) {
            throw new \RuntimeException(
                "duo: table '$table'$where declares class=" . var_export($class, true)
                . ' but the table class vocabulary is closed (' . implode(', ', self::TABLE_CLASSES)
                . ') — it is engine-owned, because only the engine can act on a class; a new one is an engine '
                . 'change with a spec bump, not a manifest declaration'
            );
        }
        if ($class === 'authored_snapshot_meta') {
            // assert_meta_schema()'s pure half. The remaining columns of an
            // EAV sidecar are checked against the live schema there; what a
            // manifest alone can get wrong is failing to say which row table
            // owns the sidecar and through which column.
            $attachCol = (string) ($decl['attached_to']['column'] ?? '');
            if ((string) ($decl['attached_to']['table'] ?? '') === '' || $attachCol === '') {
                throw new \RuntimeException("duo: table '$table'$where declares authored_snapshot_meta with no attached_to.{table,column}");
            }
            return;
        }
        if ($class !== 'authored_snapshot') {
            return; // an inert marker or a target-local disposition: nothing further is declarable
        }

        self::assert_table_section_shapes($table, $decl, $where);
        $mode = $decl['identity']['mode'] ?? 'mapped';
        if (!in_array($mode, self::IDENTITY_MODES, true)) {
            throw new \RuntimeException(
                "duo: table '$table'$where declares unknown identity.mode " . var_export($mode, true)
                . ' — the identity vocabulary is closed and engine-owned: "mapped" (default; a surrogate primary '
                . 'key with no portable key of its own, identity minted into duo_map), "natural_key" (a stable '
                . 'authored column, or an ordered tuple of them, identity derived from the value), "composite_ref" '
                . '(a pure join table with no primary key, identity derived from the referenced rows\' own uuids). '
                . 'Each serves a different table SHAPE; a new mode is an engine change with a spec bump'
            );
        }
        $refCols = array_column($decl['refs'] ?? [], 'column');
        $colKeys = array_keys($decl['columns'] ?? []);
        $overlap = array_intersect($colKeys, $refCols);
        if ($overlap) {
            throw new \RuntimeException(
                "duo: table '$table'$where declares column(s) in BOTH columns and refs: " . implode(', ', $overlap)
            );
        }
        if ($mode === 'composite_ref') {
            self::assert_composite_ref_grammar($table, $decl, $refCols, $where);
            return;
        }

        $pk = (string) ($decl['pk'] ?? '');
        if ($pk === '') {
            throw new \RuntimeException("duo: table '$table'$where declares authored_snapshot with no 'pk'");
        }
        if ($mode === 'natural_key') {
            self::assert_natural_key_grammar($table, $decl, $pk, $refCols, $colKeys, $where);
        }
        if (array_key_exists('slug_column', $decl)) {
            $slugCol = $decl['slug_column'];
            $slugRule = is_string($slugCol) && $slugCol !== ''
                ? ($decl['columns'][$slugCol] ?? null)
                : null;
            if (!is_array($slugRule) || ($slugRule['class'] ?? null) !== 'authored') {
                throw new \RuntimeException(
                    "duo: table '$table'$where slug_column must name a non-empty authored columns entry — "
                    . 'primary keys, refs, runtime, derived, and env columns are environment-local and cannot name canonical files'
                );
            }
        }
        self::assert_invalidate_grammar($table, $decl, $where);
    }

    /**
     * The SHAPE of an authored_snapshot declaration's four structural
     * sections, checked before anything reads them (DUO-3318 review, S1).
     *
     * Every check here closes a case where PHP's own coercion answered a
     * malformed declaration instead of this engine doing so:
     *   - `"identity": "natural_key"` — a string, not an object — makes
     *     `$decl['identity']['mode'] ?? 'mapped'` evaluate to 'mapped' (the
     *     `??` swallows the illegal string offset), so the table silently
     *     becomes surrogate-identity: every row mints a UUIDv7 that is
     *     environment-local, which is precisely what declaring natural_key
     *     was meant to prevent.
     *   - `"refs": ["room_id"]` — a list of strings — makes
     *     array_column($refs, 'column') return `[]`, so the ref column is
     *     treated as an ordinary scalar and its raw local id reaches
     *     canonical state; a scalar `refs` or `columns` reached array_column()
     *     /array_keys() and raised a PHP TypeError instead of this engine's
     *     "duo: " refusal (the docblock above already promised otherwise).
     *   - `"pk": ["id"]` casts to the string "Array" (a Warning, not an
     *     error), which is non-empty and therefore passed the pk check, then
     *     named a column no table has.
     *
     * The messages name the object/list form the author meant, because the
     * mistake is almost always a spelling of the right intent.
     */
    private static function assert_table_section_shapes(string $table, array $decl, string $where): void {
        if (array_key_exists('pk', $decl) && (!is_string($decl['pk']) || $decl['pk'] === '')) {
            throw new \RuntimeException(
                "duo: table '$table'$where declares pk=" . var_export($decl['pk'], true)
                . ' — pk must be a non-empty string naming this table\'s own primary key column'
            );
        }
        $refs = $decl['refs'] ?? [];
        if (!is_array($refs) || !array_is_list($refs)) {
            throw new \RuntimeException(
                "duo: table '$table'$where declares refs=" . var_export($refs, true)
                . ' — refs must be a LIST of {"column": "<col>", "kind": "<ref kind>"} objects (an empty list when '
                . 'the table references nothing); a ref that is not declared in this shape is captured as an '
                . 'ordinary scalar, which puts an environment-local id into canonical state'
            );
        }
        foreach ($refs as $i => $ref) {
            if (!is_array($ref) || (array_is_list($ref) && $ref !== [])) {
                throw new \RuntimeException(
                    "duo: table '$table'$where declares refs[$i]=" . var_export($ref, true)
                    . ' — every refs[] entry must be an object declaring both `column` and `kind`'
                );
            }
            foreach (['column', 'kind'] as $key) {
                if (!is_string($ref[$key] ?? null) || $ref[$key] === '') {
                    throw new \RuntimeException(
                        "duo: table '$table'$where declares refs[$i].$key=" . var_export($ref[$key] ?? null, true)
                        . " — every refs[] entry needs a non-empty string `column` (the column holding the id) and "
                        . '`kind` (the keyspace it points into)'
                    );
                }
            }
        }
        $columns = $decl['columns'] ?? [];
        if (!is_array($columns) || (array_is_list($columns) && $columns !== [])) {
            throw new \RuntimeException(
                "duo: table '$table'$where declares columns=" . var_export($columns, true)
                . ' — columns must be an object keyed by column name, each value a rule declaring its `class`'
            );
        }
        $identity = $decl['identity'] ?? [];
        if (!is_array($identity) || (array_is_list($identity) && $identity !== [])) {
            throw new \RuntimeException(
                "duo: table '$table'$where declares identity=" . var_export($identity, true)
                . ' — identity must be an OBJECT naming the mode, e.g. {"mode": "natural_key", "column": "<col>"}; '
                . 'a bare string is read as no identity declaration at all, which silently means '
                . 'identity.mode=mapped (surrogate, environment-local identity)'
            );
        }
        if (array_key_exists('column', $identity)
            && (!is_string($identity['column']) || $identity['column'] === '')) {
            throw new \RuntimeException(
                "duo: table '$table'$where declares identity.column=" . var_export($identity['column'], true)
                . ' — identity.column is the SINGLE-component spelling and must be one non-empty column name; the '
                . 'ordered multi-component form is identity.columns: ["<col>", ...]'
            );
        }
        if (array_key_exists('columns', $identity)) {
            $idCols = $identity['columns'];
            $ok = is_array($idCols) && array_is_list($idCols) && $idCols !== [];
            foreach ($ok ? $idCols : [] as $col) {
                $ok = $ok && is_string($col) && $col !== '';
            }
            if (!$ok) {
                throw new \RuntimeException(
                    "duo: table '$table'$where declares identity.columns=" . var_export($idCols, true)
                    . ' — identity.columns is the ordered LIST spelling and must be a non-empty list of column '
                    . 'names; the one-component case is spelled identity.column: "<col>" instead, and exactly one '
                    . 'of the two may be declared'
                );
            }
        }
    }

    /**
     * composite_ref's own grammar half — kept a separate function for the
     * same reason Snapshot::assert_composite_row_schema() is separate from
     * assert_row_schema(): the invariants genuinely differ (no pk at all;
     * identity.columns must EQUAL refs[] columns), so interleaving them
     * would obscure both.
     *
     * @param list<string> $refCols
     */
    private static function assert_composite_ref_grammar(string $table, array $decl, array $refCols, string $where): void {
        if (isset($decl['pk'])) {
            throw new \RuntimeException(
                "duo: table '$table'$where declares identity.mode=composite_ref AND a 'pk' — "
                . "composite_ref tables have no scalar primary key; remove 'pk'"
            );
        }
        if (!empty($decl['invalidate'])) {
            throw new \RuntimeException(
                "duo: table '$table'$where declares identity.mode=composite_ref with 'invalidate' — "
                . "run_invalidate()'s {id} substitution assumes a single scalar local id, which this mode has no "
                . 'equivalent of; unsupported, not silently ignored (no composite_ref fixture has needed it — see docblock)'
            );
        }
        $idCols = $decl['identity']['columns'] ?? null;
        if (!is_array($idCols) || count($idCols) !== 2) {
            throw new \RuntimeException(
                "duo: table '$table'$where declares identity.mode=composite_ref with identity.columns != exactly 2 entries "
                . '— this is the only shape this engine has proven (see assert_composite_row_schema()\'s docblock)'
            );
        }
        $sortedIdCols = $idCols;
        sort($sortedIdCols);
        $sortedRefCols = $refCols;
        sort($sortedRefCols);
        if ($sortedIdCols !== $sortedRefCols) {
            throw new \RuntimeException(
                "duo: table '$table'$where identity.columns [" . implode(', ', $idCols)
                . "] must be EXACTLY its refs[] columns [" . implode(', ', $refCols)
                . '] — composite_ref is only for pure join tables: every identity column is a ref, every ref is an identity column'
            );
        }
    }

    /**
     * natural_key's grammar half, including DUO-3318's parent-scoped
     * multi-column form.
     *
     * Every component must be a declared ref column or a declared `columns{}`
     * entry — the two buckets whose values capture actually carries into the
     * canonical file, and therefore the only ones a derivation can read back
     * on another environment. The primary key is refused by name rather than
     * merely being absent from those buckets: a surrogate auto-increment id
     * is the exact category of value this engine's whole token grammar exists
     * to keep out of portable identity, so a manifest reaching for it has
     * made a specific mistake worth naming.
     *
     * A multi-column key REQUIRES slug_column. Single-column tables have a
     * long-standing fallback (the literal `record` suffix), but a tuple has
     * no honest one-line spelling — joining component display values would
     * put a resolved ref's raw local id or a foreign row's label into a
     * filename, and both change across environments. Requiring the declaration
     * keeps filenames portable by construction instead of by convention.
     *
     * @param list<string> $refCols
     * @param list<string> $colKeys
     */
    private static function assert_natural_key_grammar(
        string $table,
        array $decl,
        string $pk,
        array $refCols,
        array $colKeys,
        string $where
    ): void {
        $identity = $decl['identity'];
        if (array_key_exists('column', $identity) && array_key_exists('columns', $identity)) {
            throw new \RuntimeException(
                "duo: table '$table'$where declares identity.mode=natural_key with BOTH 'column' and 'columns' — "
                . "these are one vocabulary with two spellings ('column' is the 1-component case); declare exactly one"
            );
        }
        $columns = self::natural_key_columns($decl);
        if ($columns === []) {
            throw new \RuntimeException(
                "duo: table '$table'$where declares identity.mode=natural_key with no 'column' and no non-empty "
                . "'columns' — a derived identity needs at least one authored component to derive from"
            );
        }
        $seen = [];
        foreach ($columns as $column) {
            if ($column === '') {
                throw new \RuntimeException(
                    "duo: table '$table'$where declares an empty identity column name for identity.mode=natural_key"
                );
            }
            if (isset($seen[$column])) {
                throw new \RuntimeException(
                    "duo: table '$table'$where repeats identity column '$column' — a repeated component adds no "
                    . 'distinguishing power and makes the declared order ambiguous'
                );
            }
            $seen[$column] = true;
            if ($column === $pk) {
                throw new \RuntimeException(
                    "duo: table '$table'$where names its primary key '$pk' as a natural_key identity column — a "
                    . 'surrogate primary key is an environment-local auto-increment value, so deriving identity '
                    . 'from it would mint a different uuid per environment for the same authored fact; use '
                    . 'identity.mode=mapped when a table has no portable key of its own'
                );
            }
            if (!in_array($column, $refCols, true) && !in_array($column, $colKeys, true)) {
                throw new \RuntimeException(
                    "duo: table '$table'$where names identity column '$column', which is neither a declared "
                    . 'refs[] column nor a declared columns{} entry — an identity component must be a column this '
                    . 'manifest actually classifies, or capture has nothing portable to derive from'
                );
            }
        }
        if (count($columns) > 1 && (string) ($decl['slug_column'] ?? '') === '') {
            throw new \RuntimeException(
                "duo: table '$table'$where declares a multi-column natural_key (" . implode(', ', $columns)
                . ") without 'slug_column' — a tuple has no portable one-line filename spelling (a resolved ref "
                . "component is an environment-local id), so the declaration must name the authored column that "
                . 'supplies the human-readable half of the path'
            );
        }
    }

    /**
     * `invalidate[]` grammar (DUO-3318): each entry is exactly one targeted
     * row delete `{table, column}` or one named option `{option_pattern}`.
     *
     * Both branches are generic, plugin-blind primitives, and both are
     * silently no-ops when misspelled: Snapshot::run_invalidate() dispatches
     * on `isset($inv['table'])` / `isset($inv['option_pattern'])`, so a
     * mistyped key used to mean "this cache is never invalidated" with no
     * diagnostic — the exact failure Ninja Forms' stale-cache finding was
     * filed for in the first place. `{id}` is required in an option_pattern
     * for the same reason: a pattern with no substitution point names ONE
     * fixed option row for every row of the table, which is either a no-op or
     * a delete of an unrelated option.
     */
    private static function assert_invalidate_grammar(string $table, array $decl, string $where): void {
        if (!array_key_exists('invalidate', $decl)) {
            return;
        }
        $entries = $decl['invalidate'];
        if (!is_array($entries) || !array_is_list($entries) || $entries === []) {
            throw new \RuntimeException("duo: table '$table'$where invalidate must be a non-empty list");
        }
        foreach ($entries as $i => $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new \RuntimeException("duo: table '$table'$where invalidate[$i] must be an object");
            }
            $keys = array_keys($entry);
            sort($keys, SORT_STRING);
            if ($keys === ['column', 'table']) {
                foreach (['table', 'column'] as $key) {
                    if (!is_string($entry[$key]) || $entry[$key] === '') {
                        throw new \RuntimeException(
                            "duo: table '$table'$where invalidate[$i].$key must be a non-empty string"
                        );
                    }
                }
                continue;
            }
            if ($keys === ['option_pattern']) {
                if (!is_string($entry['option_pattern']) || !str_contains($entry['option_pattern'], '{id}')) {
                    throw new \RuntimeException(
                        "duo: table '$table'$where invalidate[$i].option_pattern must be a string containing the "
                        . '{id} substitution point — without it every row of this table would name the same one '
                        . 'option row'
                    );
                }
                continue;
            }
            throw new \RuntimeException(
                "duo: table '$table'$where invalidate[$i] declares [" . implode(', ', $keys)
                . '] but the invalidation vocabulary is closed and engine-owned: exactly {table, column} for a '
                . 'targeted row delete, or exactly {option_pattern} for a named option. Anything a plugin owns '
                . 'beyond those two generic primitives belongs in a native action or a provider capability'
            );
        }
    }

    /** @return ?array{name:string,rule:array} effective EAV sidecar for one row table */
    public function attached_meta_table_for_owner(string $ownerTable): ?array {
        foreach ($this->declared_tables() as $name => $rule) {
            if (($rule['class'] ?? '') === 'authored_snapshot_meta'
                && (string) ($rule['attached_to']['table'] ?? '') === $ownerTable) {
                return ['name' => (string) $name, 'rule' => $rule];
            }
        }
        return null;
    }

    /** Closed widget type registry. Last pinned manifest wins per type. */
    public function widget_types(): array {
        $out = [];
        foreach ($this->manifests as $manifest) {
            foreach ((array) ($manifest['widgets'] ?? []) as $type => $rule) {
                $out[(string) $type] = (array) $rule;
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * The declaration source for the same last-pinned-wins widget rule that
     * widget_types() returns. Reporting callers must not reconstruct a
     * different precedence walk merely to name its owner.
     *
     * @return array{rule:?array,source:?string}
     */
    public function widget_type_rule_details(string $type): array {
        $rule = null;
        $source = null;
        foreach ($this->manifests as $manifest) {
            if (isset($manifest['widgets'][$type]) && is_array($manifest['widgets'][$type])) {
                $rule = $manifest['widgets'][$type];
                $source = (string) ($manifest['name'] ?? '?');
            }
        }
        return ['rule' => $rule, 'source' => $source];
    }

    /** blockName => list of {path, kind, type} rules, merged across manifests. */
    public function block_attr_rules(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['block_attrs'] ?? [] as $block => $rules) {
                $out[$block] = $rules;
            }
        }
        return $out;
    }

    /**
     * `shortcode_attrs` (DUO-3259): the shortcode twin of `block_attrs()`
     * above, same precedence (last pin wins per tag name, a structural
     * fact about a shortcode's own attribute grammar, not a site-local
     * policy choice — no site.duo.json override, mirroring block_attrs'
     * own reasoning exactly), but a flatter rule shape: tagName => list of
     * {path, kind, cast?} rules — no `type`, unlike block_attrs — a
     * shortcode attribute value is always flat text in the source (never
     * a native JSON array the way a block attr can be), so `cast: "csv"`
     * alone signals "comma-joined id list" (WordPress's own convention
     * for gallery's `ids`/`include`/`exclude` attributes, confirmed by
     * reading gallery_shortcode() directly, not assumed); anything not
     * csv-cast is a plain scalar id. `path` names a shortcode ATTRIBUTE
     * (not a JSON path; shortcode attributes are already a flat key=value
     * grammar, `shortcode_parse_atts()`'s own return shape).
     */
    public function shortcode_attr_rules(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['shortcode_attrs'] ?? [] as $tag => $rules) {
                $out[$tag] = $rules;
            }
        }
        return $out;
    }

    /**
     * `taxonomies.<tax>.description_refs` (spec v0.8 / docs/frontier/
     * polylang.md's "typed serialized-description rewriting"): declares
     * that a taxonomy's term_taxonomy.description column holds PHP-
     * serialized data (Polylang's post_translations/term_translations
     * `{lang_slug: local_id}` shape, verified byte-for-byte) with ref-typed
     * values reachable via the ordinary json_refs primitive at path "$.*"
     * (Capture::term_description() / Apply::encode_description() own
     * deciding how to (un)serialize; this only returns the declared rule).
     *
     * Manifest-only structural fact about the taxonomy's OWN data shape
     * (like block_attrs is a structural fact about a block type's shape),
     * not a site-local policy choice, so
     * — unlike options/post_meta/term_meta — there is no site.duo.json
     * policy override. This also sidesteps a real naming collision:
     * site.duo.json's policy.taxonomies is already the flat taxonomy-scope
     * LIST (Policy::taxonomies() below); reusing that key for a name-keyed
     * rule map would silently shadow it instead of erroring, since PHP's
     * array access on a list by an unknown string key just returns null.
     *
     * Duplicate declarations must normalize identically; load() and
     * from_snapshot() reject pin-order-dependent shapes before this accessor
     * can run.
     *
     * @return ?array{json_refs:array,key_refs:?array,legacy_flat_map:bool}
     */
    public function description_reference_rule(string $tax): ?array {
        foreach ($this->manifests as $m) {
            if (isset($m['taxonomies'][$tax]['description_refs'])) {
                return ReferenceRules::description(
                    $m['taxonomies'][$tax]['description_refs'],
                    "manifest '" . ($m['name'] ?? '?') . "'.taxonomies.$tax.description_refs"
                );
            }
        }
        return null;
    }

    /** @deprecated Use description_reference_rule(); retained for extensions. */
    public function description_refs_for_taxonomy(string $tax): ?array {
        return $this->description_reference_rule($tax);
    }

    /**
     * The canonical keyspace for a taxonomy relationship's `object_id`.
     *
     * `wp_term_relationships.object_id` is deliberately ambiguous at the
     * database level: WordPress posts and terms are minted from independent
     * counters, so the same integer can name one of each. A taxonomy's
     * runtime `object_type` is useful for choosing WHICH post types a
     * post-keyspace taxonomy belongs to, but it is not a portable statement
     * of whether the relationship rows themselves belong to posts or terms:
     * plugins may use arbitrary object-type strings, and an engine sentinel
     * such as literal `term` would make that plugin implementation detail a
     * hidden part of Duo's wire contract.
     *
     * A manifest can therefore declare `object_keyspace: "post"|"term"`
     * under an exact `taxonomies.<name>` rule or a matching
     * `taxonomy_patterns` rule. All declarations that apply to a concrete
     * taxonomy must agree. Omission preserves legacy post-only behavior for
     * an ordinary runtime post taxonomy, so existing manifests and already-
     * canonical state keep their exact bytes. A runtime taxonomy whose
     * object_type contains `term` is NOT a legacy post taxonomy, however:
     * term and mixed keyspaces must declare this field or the resolver
     * refuses rather than reviving the old sentinel inference. Capture,
     * lint, compile, apply, and fresh-process verification all ask this one
     * resolver.
     *
     * @return "post"|"term"
     */
    public function taxonomy_object_keyspace(string $tax, ?array $runtimeObjectTypes = null): string {
        /** @var array<string,string[]> $declared value => declaration locations */
        $declared = [];
        foreach ($this->manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '?');
            if (array_key_exists($tax, (array) ($manifest['taxonomies'] ?? []))) {
                $rule = $manifest['taxonomies'][$tax];
                if (is_array($rule)) {
                    $value = array_key_exists('object_keyspace', $rule)
                        ? (string) $rule['object_keyspace']
                        : 'post';
                    $source = "manifest '$name' taxonomies.$tax";
                    $declared[$value][] = array_key_exists('object_keyspace', $rule)
                        ? $source . '.object_keyspace'
                        : $source . ' (legacy post default)';
                }
            }
        }
        $pattern = $this->matching_taxonomy_pattern_rule($tax);
        if ($pattern !== null) {
            $declared[$pattern['object_keyspace']][] = $pattern['source'] . '.object_keyspace';
        }
        if ($declared === []) {
            if ($runtimeObjectTypes !== null && in_array('term', $runtimeObjectTypes, true)) {
                throw new \RuntimeException(
                    "duo: taxonomy '$tax' has runtime object_type containing 'term' but no manifest "
                    . 'object_keyspace declaration — term or mixed relationship ownership must declare '
                    . 'object_keyspace="term" or object_keyspace="post" explicitly'
                );
            }
            return 'post'; // explicit compatibility default for pre-DUO-3316 manifests
        }
        if (count($declared) !== 1) {
            $claims = [];
            foreach ($declared as $value => $sources) {
                $claims[] = "$value from " . implode(', ', $sources);
            }
            throw new \RuntimeException(
                "duo: taxonomy '$tax' has ambiguous object_keyspace declarations ("
                . implode('; ', $claims) . ') — every exact or matching pattern declaration must agree'
            );
        }
        $resolved = (string) array_key_first($declared);
        if ($runtimeObjectTypes !== null) {
            $runtimeObjectTypes = array_values(array_unique(array_map('strval', $runtimeObjectTypes)));
            $hasTermSentinel = in_array('term', $runtimeObjectTypes, true);
            if ($hasTermSentinel && count($runtimeObjectTypes) > 1) {
                throw new \RuntimeException(
                    "duo: taxonomy '$tax' is registered with mixed runtime object_type values ("
                    . implode(', ', $runtimeObjectTypes) . '); one object_keyspace declaration cannot safely '
                    . 'describe both post- and term-owned relationship rows'
                );
            }
            if ($hasTermSentinel && $resolved !== 'term') {
                throw new \RuntimeException(
                    "duo: taxonomy '$tax' declares object_keyspace='$resolved' but its runtime object_type "
                    . "contains 'term' — declaration/runtime relationship ownership contradicts"
                );
            }
        }
        return $resolved;
    }

    /**
     * `taxonomies.<tax>.object_type_from_option` (DUO-3280): declares that
     * $tax's registered object_type is additionally, DYNAMICALLY driven by
     * a sub_keys-declared option's own named sub-key (Polylang: `language`/
     * `post_translations` additionally cover whatever post types the
     * `polylang` option's own `post_types` sub-key currently names — see
     * polylang.json's own note). Pure declaration data — WHICH option,
     * WHICH sub-key — never a live value; this class stays WordPress-free
     * by design, the identical "class holds the declaration, caller does
     * the live read" split dynamic_options() above already uses. Apply's
     * own taxes_by_object_type() consults this, then resolves it against
     * the CURRENT apply's own compiled tree (see Apply::
     * option_driven_object_type()'s own comment for why the compiled
     * tree, not a live database read, is the correct — and safer —
     * source: phase-2's own stable-sort ordering can finalize a brand-new
     * post before the declaring option's sub_keys merge in the SAME
     * apply, so "the committed row" is not yet a fixed point at the
     * moment a live read would happen). The declared sub-key is assumed
     * to hold plain, non-ref-typed values (post-type/taxonomy slugs are
     * inherently portable, unlike ids) — validate_object_type_option_refs()
     * enforces that at load time.
     *
     * Deliberately a DIFFERENT primitive than taxonomy_pattern_rules()
     * (task #92), not a mode of it: that mechanism answers "is $tax
     * registered at ALL this request" (a yes/no gate for a taxonomy
     * get_taxonomy() cannot find yet); this answers "what ELSE does an
     * already-found taxonomy's object_type cover" — additive, never a
     * substitute registration source, and consulted regardless of whether
     * get_taxonomy() succeeded or the pattern fallback did.
     *
     * Same first-manifest-wins, exact-name lookup as
     * description_refs_for_taxonomy() immediately above.
     *
     * @return ?array{option:string, sub_key:string}
     */
    public function object_type_option_ref(string $tax): ?array {
        foreach ($this->manifests as $m) {
            $decl = $m['taxonomies'][$tax]['object_type_from_option'] ?? null;
            if ($decl !== null) {
                return ['option' => (string) $decl['option'], 'sub_key' => (string) $decl['sub_key']];
            }
        }
        return null;
    }

    public function post_types(): array {
        $exact = $this->site['policy']['post_types'] ?? ['post', 'page', 'attachment'];
        foreach ($this->site['policy']['scope']['post_type'] ?? [] as $name => $rule) {
            if (($rule['class'] ?? null) === 'authored') {
                $exact[] = (string) $name;
            }
        }
        return array_values(array_unique($exact));
    }

    /** @return string[] post types for which a pinned manifest declares a whole-type contract. */
    public function declared_post_types(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            $out = array_merge($out, array_keys($m['post_types'] ?? []));
        }
        return array_values(array_unique($out));
    }

    /**
     * Declared direct child post types for a parent CPT.  This is a
     * structural manifest fact about wp_posts.post_parent, not a deletion
     * capability or a request to discover an arbitrary post hierarchy.
     *
     * Pinned manifests compose additively for this relationship because an
     * adapter may name additional child types for the same parent.  Sorting
     * makes the result independent of manifest pin order and safe for query
     * construction and future scoped dependency closure.
     *
     * @return string[]
     */
    public function child_post_types(string $postType): array {
        $out = [];
        foreach ($this->manifests as $manifest) {
            $decl = $manifest['post_types'][$postType] ?? null;
            if (!is_array($decl)) {
                continue;
            }
            foreach ((array) ($decl['children'] ?? []) as $childPostType) {
                if (is_string($childPostType) && $childPostType !== '') {
                    $out[$childPostType] = true;
                }
            }
        }
        $types = array_keys($out);
        sort($types, SORT_STRING);
        return $types;
    }

    /**
     * Declared direct parent post types for a child CPT.  A child row has one
     * wp_posts.post_parent value at a time, while this type-level reverse
     * lookup intentionally permits more than one adapter-declared parent
     * type.  Callers that need a concrete relationship still inspect the
     * local parent id; this method never manufactures one.
     *
     * @return string[]
     */
    public function parent_post_types(string $postType): array {
        $out = [];
        foreach ($this->manifests as $manifest) {
            foreach ((array) ($manifest['post_types'] ?? []) as $parentPostType => $decl) {
                if (!is_array($decl)
                    || !in_array($postType, (array) ($decl['children'] ?? []), true)) {
                    continue;
                }
                $out[(string) $parentPostType] = true;
            }
        }
        $types = array_keys($out);
        sort($types, SORT_STRING);
        return $types;
    }

    /**
     * Return an input type set plus every type connected to it by declared
     * parent/child edges. Traversal is deliberately bidirectional and
     * transitive: scoped-operation callers expand parent/child dependencies
     * through this API, closing a bounded declared scope without a
     * target-wide post query. Unknown input types survive as isolated
     * members; undeclared edges are never inferred.
     *
     * @param array<int,mixed> $postTypes
     * @return string[] lexical, duplicate-free order
     */
    public function post_type_relation_closure(array $postTypes): array {
        $pending = [];
        foreach ($postTypes as $postType) {
            if (is_string($postType) && $postType !== '') {
                $pending[$postType] = true;
            }
        }

        $seen = [];
        while ($pending) {
            ksort($pending, SORT_STRING);
            $postType = (string) array_key_first($pending);
            unset($pending[$postType]);
            if (isset($seen[$postType])) {
                continue;
            }
            $seen[$postType] = true;
            foreach (array_merge(
                $this->child_post_types($postType),
                $this->parent_post_types($postType)
            ) as $relatedPostType) {
                if (!isset($seen[$relatedPostType])) {
                    $pending[$relatedPostType] = true;
                }
            }
        }

        $types = array_keys($seen);
        sort($types, SORT_STRING);
        return $types;
    }

    /** @return string[] taxonomies for which a pinned manifest declares a structural contract. */
    public function declared_taxonomies(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            $out = array_merge($out, array_keys($m['taxonomies'] ?? []));
        }
        return array_values(array_unique($out));
    }

    /**
     * taxonomy_patterns (task #92): dynamic-taxonomy-NAME scope, the
     * mirror-in-INTENT (not in mechanism) of option_patterns/meta_patterns.
     * Deliberately NOT added to PATTERN_KEYS/rule() above: that map's shape
     * is "classify a single key some OTHER enumeration already produced"
     * (an options/post_meta row is discovered some other way, THEN
     * classified by pattern); taxonomy scope has no outer enumeration to
     * piggyback on — answering "which taxonomy NAMES are in scope" is
     * itself the job, so the pattern consultation has to happen inside
     * taxonomies() below, a structurally different shape by necessity, not
     * an inconsistency with the existing mechanism.
     *
     * @return array<int, array{match:string, object_type:string[], update_count_callback:?string, object_keyspace:string, source:string}>
     */
    public function taxonomy_pattern_rules(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['taxonomy_patterns'] ?? [] as $i => $pat) {
                $objectTypes = array_values(array_unique(array_map(
                    'strval',
                    (array) ($pat['object_type'] ?? [])
                )));
                sort($objectTypes, SORT_STRING);
                $out[] = [
                    'match' => (string) $pat['match'],
                    'object_type' => $objectTypes,
                    'update_count_callback' => isset($pat['update_count_callback'])
                        ? (string) $pat['update_count_callback']
                        : null,
                    'object_keyspace' => array_key_exists('object_keyspace', $pat)
                        ? (string) $pat['object_keyspace']
                        : 'post',
                    'source' => "manifest '" . (string) ($m['name'] ?? '?') . "' taxonomy_patterns[$i]",
                ];
            }
        }
        return $out;
    }

    /**
     * The unambiguous declared object_type for every taxonomy_pattern
     * matching $tax, or null. Consulted by Capture's/Apply's taxes_by_object_type()
     * ONLY as a fallback when get_taxonomy() fails — WooCommerce registers
     * pa_* taxonomies from a DB table read on `init`, which already ran
     * before Snapshot's own phase-1 write of that table's row this same
     * request/apply — so a taxonomy_patterns-matched name can be genuinely
     * in scope (its term_taxonomy rows exist, found live) without being
     * registered yet THIS request. A manifest-declared object_type is a
     * fact about the PLUGIN's own registration code (confirmed against
     * WooCommerce's actual source for pa_*: object_type defaults to
     * `['product']`), sidestepping the need for get_taxonomy() to have
     * caught up. get_taxonomy() stays authoritative whenever it succeeds —
     * this is a narrow fallback for one specific timing gap, never a
     * general override.
     */
    public function pattern_object_type(string $tax): ?array {
        return $this->matching_taxonomy_pattern_rule($tax)['object_type'] ?? null;
    }

    /**
     * Registered taxonomy state can lag a taxonomy_patterns-backed table
     * write until the next request. A version-pinned manifest may declare
     * the plugin's real count callback so Apply can honor the identical
     * contract during that one timing window instead of guessing a COUNT.
     */
    public function pattern_update_count_callback(string $tax): ?string {
        return $this->matching_taxonomy_pattern_rule($tax)['update_count_callback'] ?? null;
    }

    /**
     * Resolve every pattern matching one concrete taxonomy as a single
     * structural contract. Arbitrary PCRE intersection is not decidable at
     * load time, so differently-spelled overlapping patterns are checked at
     * the first concrete name; identical regex conflicts are also rejected
     * eagerly by validate_no_conflicting_taxonomy_object_keyspaces().
     *
     * @return ?array{match:string,object_type:string[],update_count_callback:?string,object_keyspace:string,source:string}
     */
    private function matching_taxonomy_pattern_rule(string $tax): ?array {
        $effective = null;
        foreach ($this->taxonomy_pattern_rules() as $pattern) {
            if (!self::taxonomy_pattern_matches($pattern['match'], $tax)) {
                continue;
            }
            if ($effective === null) {
                $effective = $pattern;
                continue;
            }
            foreach (['object_type', 'update_count_callback', 'object_keyspace'] as $field) {
                if ($effective[$field] != $pattern[$field]) {
                    $ambiguity = $field === 'object_keyspace'
                        ? 'ambiguous object_keyspace declarations'
                        : 'ambiguous taxonomy_patterns contracts';
                    throw new \RuntimeException(
                        "duo: taxonomy '$tax' matches $ambiguity: "
                        . "{$effective['source']} and {$pattern['source']} disagree on $field; "
                        . 'pin order may not choose runtime relationship behavior'
                    );
                }
            }
        }
        return $effective;
    }

    /** taxonomy_patterns stores an undelimited PCRE fragment by contract. */
    private static function taxonomy_pattern_matches(string $match, string $tax): bool {
        return @preg_match('/' . $match . '/', $tax) === 1;
    }

    /**
     * The full in-scope taxonomy list: site.duo.json's exact
     * `policy.taxonomies` PLUS every taxonomy name actually present in
     * wp_term_taxonomy that matches a manifest's taxonomy_patterns regex
     * (WooCommerce's pa_* — task #92). Empirically confirmed live (not
     * just reasoned) which source is timing-safe: after a raw-SQL insert
     * into wp_woocommerce_attribute_taxonomies (Snapshot's own phase 1),
     * get_taxonomy('pa_x') still returns false for the REST of that SAME
     * request/process, but a term_taxonomy row for the new taxonomy (ALSO
     * written raw-SQL in phase 1, unconditionally, no registration check)
     * is immediately visible to a live SELECT DISTINCT — so expansion
     * reads LIVE TABLE DATA, never get_taxonomies()'s in-memory registry,
     * which is exactly the thing that's stale mid-request.
     *
     * SCOPE-GATED, not a blanket widen: only names that match a DECLARED
     * pattern are ever added to the exact list — never every distinct
     * taxonomy the database happens to hold. This is the identical posture
     * task #73 established for ref-typed options (a real-but-out-of-scope
     * target aborts loudly rather than silently entering canonical state);
     * silently widening scope to "whatever's in the database" would be the
     * same failure class in the opposite direction, and is deliberately
     * not what this does. When no manifest declares taxonomy_patterns,
     * this method's behavior (and its DB query) is byte-for-byte unchanged
     * from before task #92 — an empty pattern list is a fast exact-return,
     * no query at all, so every manifest that doesn't use this pays zero
     * cost.
     *
     * This is the one deliberate exception to this class's DB-free-ness
     * elsewhere (Snapshot.php's own docblock states that purity as a
     * layering principle): unlike a post_meta/option KEY (already
     * enumerated by its caller before Policy::rule() is ever consulted),
     * the taxonomy SCOPE LIST has no outer enumeration of its own to
     * piggyback on. Centralizing the live-DB expansion HERE — rather than
     * duplicating a DISTINCT-query-and-filter snippet at every one of
     * taxonomies()'s several call sites in Capture.php/Apply.php — means
     * every caller (scope_terms(), taxes_by_object_type()'s input,
     * Capture's unscoped-ref scope check, Apply::rebuild()'s recount list)
     * gets pattern support for free with zero changes of their own beyond
     * this one method.
     */
    public function taxonomies(): array {
        $exact = $this->site['policy']['taxonomies'] ?? ['category', 'post_tag'];
        foreach ($this->site['policy']['scope']['taxonomy'] ?? [] as $name => $rule) {
            if (($rule['class'] ?? null) === 'authored') {
                $exact[] = (string) $name;
            }
        }
        $exact = array_values(array_unique($exact));
        $patterns = $this->taxonomy_pattern_rules();
        if (!$patterns) {
            return $exact;
        }
        global $wpdb;
        $live = $wpdb->get_col("SELECT DISTINCT taxonomy FROM {$wpdb->term_taxonomy}") ?: [];
        $matched = [];
        foreach ($live as $tax) {
            if (in_array($tax, $exact, true)) {
                continue;
            }
            foreach ($patterns as $pat) {
                if (preg_match('/' . $pat['match'] . '/', $tax)) {
                    $matched[] = $tax;
                    break;
                }
            }
        }
        return array_values(array_unique(array_merge($exact, $matched)));
    }

    /**
     * option_name_refs (task #93): options discovered by NAME PATTERN, not
     * exact-key whitelist — for options whose NAME embeds another declared
     * table's local id (WooCommerce's woocommerce_<method_id>_<instance_id>
     * _settings, instance_id being a woocommerce_shipping_zone_methods
     * row's own pk). Pure manifest merge (flat concatenated list, manifest
     * pin order then declaration order — same first-match-wins semantics
     * as the PATTERN_KEYS fallback loop in rule() above); the live
     * wp_options NAME scan this declares is Capture::build_options()'s job,
     * not this accessor's — mirrors declared_tables()/block_attr_rules()'s
     * existing split between "what did manifests declare" (pure, here) and
     * "what do we do about it against a live environment" (the DB-touching
     * caller).
     *
     * A DELIBERATE sibling of option_patterns, not a variant of it:
     * option_patterns is consulted only to CLASSIFY a key some other
     * enumeration already produced (Policy::rule()'s fallback loop);
     * Capture::build_options() is exact-whitelist-only and NEVER consults
     * option_patterns for DISCOVERY (confirmed by reading it — r1b-shop.md's
     * own finding). option_name_refs entries drive their OWN discovery scan
     * because these rows are otherwise invisible to every existing option
     * mechanism. Consumers must resolve a concrete name through
     * option_name_ref_match_details(); that resolver rejects malformed names
     * and every concrete overlap rather than applying pin/declaration order.
     *
     * @return array<int, array{match:string, id_kind:string, class:string, malformed_match?:string, json_refs?:array, key_refs?:array}>
     */
    public function option_name_ref_rules(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['option_name_refs'] ?? [] as $rule) {
                $out[] = self::with_option_autoload($rule, $m);
            }
        }
        return $out;
    }

    /**
     * Resolve one live option name against the complete option-name-ref
     * grammar.  This is deliberately one resolver rather than separate
     * first-match loops in capture, preservation, and apply: two declarations
     * owning the same name (including declarations for different id_kinds)
     * are ambiguous and must never be selected by pin/declaration order.
     *
     * `malformed_match` is an optional manifest-owned sibling namespace for
     * names which look like this rule's family but contain an invalid local
     * id (Woo's leading-zero instance ids are the first shipped example).
     * A malformed candidate is refused even when another broad declaration
     * would otherwise happen to match it.
     *
     * @return ?array{rule:array,matches:array,source:string}
     */
    public function option_name_ref_match_details(string $realOptionName): ?array {
        $matches = [];
        $malformed = [];
        foreach ($this->manifests as $manifest) {
            foreach ($manifest['option_name_refs'] ?? [] as $index => $rule) {
                $rule = self::with_option_autoload($rule, $manifest);
                $pattern = '/' . (string) ($rule['match'] ?? '') . '/';
                $captured = [];
                if (preg_match($pattern, $realOptionName, $captured, PREG_OFFSET_CAPTURE) === 1) {
                    if (!isset($captured['id'][0], $captured['id'][1])) {
                        throw new \RuntimeException(
                            "duo: option_name_refs rule for option '$realOptionName' did not expose its named id capture"
                        );
                    }
                    if (self::strict_positive_local_id($captured['id'][0]) === null) {
                        throw new \RuntimeException(
                            "duo: option '$realOptionName' captures an invalid local id in option_name_refs; "
                            . 'leading-zero, zero, and overflow spellings are refused'
                        );
                    }
                    $matches[] = [
                        'rule' => $rule,
                        'matches' => $captured,
                        'source' => (string) ($manifest['name'] ?? '?'),
                        'index' => (int) $index,
                    ];
                }
                $malformedPattern = $rule['malformed_match'] ?? null;
                if (is_string($malformedPattern) && $malformedPattern !== ''
                    && preg_match('/' . $malformedPattern . '/', $realOptionName) === 1) {
                    $malformed[] = [
                        'rule' => $rule,
                        'source' => (string) ($manifest['name'] ?? '?'),
                        'index' => (int) $index,
                    ];
                }
            }
        }
        if ($malformed) {
            $owners = array_map(
                static fn(array $entry): string => (string) $entry['source'],
                $malformed
            );
            throw new \RuntimeException(
                "duo: option '$realOptionName' matches a malformed option_name_refs namespace "
                . '(invalid local id; refusing capture/apply) declared by ' . implode(', ', array_unique($owners))
            );
        }
        if (count($matches) > 1) {
            $owners = array_map(
                static fn(array $entry): string => (string) $entry['source'],
                $matches
            );
            throw new \RuntimeException(
                "duo: option '$realOptionName' matches multiple option_name_refs rules (ambiguous ownership; "
                . 'refusing pin-order resolution): ' . implode(', ', $owners)
            );
        }
        if (!$matches) {
            return null;
        }
        return $matches[0];
    }

    /** @return int|null only an exact positive decimal local id is accepted. */
    public static function strict_positive_local_id($value): ?int {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)) {
            return null;
        }
        $digits = ltrim($value, '0');
        $id = (int) $digits;
        // Reject overflow rather than letting a huge decimal string saturate
        // to PHP_INT_MAX and accidentally resolve a different row.
        return $id > 0 && (string) $id === $digits ? $id : null;
    }

    /**
     * Resolve a canonical option name containing one identity token against
     * option_name_refs without consulting a target ledger. Replacing the
     * token with a representative positive integer lets the declaration's
     * existing numeric-name regex decide ownership, while checking id_kind
     * separately prevents a hand-edited token of the wrong keyspace from
     * borrowing that authorization. The shared concrete-name resolver also
     * rejects same-kind and cross-kind overlaps.
     *
     * @return array{rule:?array, source:?string}
     */
    public function canonical_option_name_ref_details(string $name): array {
        $tokenCount = preg_match_all(
            '/\{\{([a-z][a-z0-9_]*):[0-9a-f-]{36}\}\}/',
            $name,
            $tokens,
            PREG_SET_ORDER
        );
        if ($tokenCount === false || $tokenCount === 0) {
            return ['rule' => null, 'source' => null];
        }
        if ($tokenCount !== 1) {
            throw new \RuntimeException(
                "duo: canonical option key '$name' contains multiple embedded identity tokens; refusing ambiguity"
            );
        }
        $token = $tokens[0][0] ?? '';
        $tokenKind = (string) ($tokens[0][1] ?? '');
        $representative = str_replace($token, '1', $name);
        $details = $this->option_name_ref_match_details($representative);
        if ($details === null) {
            $knownKind = false;
            foreach ($this->option_name_ref_rules() as $rule) {
                if ((string) ($rule['id_kind'] ?? '') === $tokenKind) {
                    $knownKind = true;
                    break;
                }
            }
            if ($knownKind) {
                throw new \RuntimeException(
                    "duo: canonical option token for id_kind '$tokenKind' is not owned by exactly one authored "
                    . 'option_name_refs rule'
                );
            }
            return ['rule' => null, 'source' => null];
        }
        if ((string) ($details['rule']['id_kind'] ?? '') !== $tokenKind
            || ($details['rule']['class'] ?? '') !== 'authored') {
            throw new \RuntimeException(
                "duo: canonical option key '$name' has an identity token whose id_kind does not match its "
                . 'sole authored option_name_refs owner'
            );
        }
        return [
            'rule' => $details['rule'],
            'source' => $details['source'],
            'matches' => $details['matches'],
            'token_kind' => $tokenKind,
        ];
    }

    /**
     * The sole option_name_refs rule whose `match` regex matches
     * $realOptionName (a name with any embedded id already in its REAL,
     * numeric form — never a token) — or null. Shared by Capture's
     * discovery pass (matching a live wp_options row's actual name),
     * Snapshot preservation, and Apply's apply-direction path (matching the
     * DETOKENIZED name, i.e. after splicing the resolved local id back in).
     * Ambiguous or malformed names throw, so capture and apply cannot
     * disagree about which rows this mechanism owns.
     */
    public function match_option_name_ref(string $realOptionName): ?array {
        $details = $this->option_name_ref_match_details($realOptionName);
        return $details['rule'] ?? null;
    }

    /**
     * Resolve every declared interpreter name to a loaded, instantiated class.
     *
     * Public for the same reason regenerators() below is: a second caller
     * outside this class needs it. `duo manifest-validate` (DUO-3327) calls it
     * immediately after each successful load, because a manifest naming an
     * interpreter file that does not exist — or a file that does not define the
     * contract class — is a manifest that is wrong offline, and leaving that
     * discovery to the first live meta lookup meant an offline check reported
     * `ok` for a declaration no target could ever run. Resolution is a pure
     * file-system + class-contract question about the manifests directory the
     * checker was handed, so it belongs to the offline half.
     *
     * @return array<string, object>
     */
    public function interpreters(): array {
        if ($this->interpreterInstances !== null) {
            return $this->interpreterInstances;
        }
        $this->interpreterInstances = [];
        foreach ($this->manifests as $m) {
            $name = $m['interpreter'] ?? null;
            if ($name === null || isset($this->interpreterInstances[$name])) {
                continue;
            }
            if (!preg_match('/^[a-z0-9_-]+$/', $name)) {
                throw new \RuntimeException("duo: manifest '{$m['name']}' declares invalid interpreter name '$name'");
            }
            $file = self::manifests_dir() . '/interpreters/' . $name . '.php';
            if (!is_file($file)) {
                throw new \RuntimeException(
                    "duo: manifest '{$m['name']}' wants interpreter '$name' but $file is missing — "
                    . 'interpreter code ships with its manifest, not the engine'
                );
            }
            require_once $file;
            $class = '\\Duo\\Interpreters\\' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
            if (!class_exists($class) || !method_exists($class, 'post_meta_rule')) {
                throw new \RuntimeException(
                    "duo: interpreter file $file must define $class with post_meta_rule(string, array): ?array"
                );
            }
            $this->interpreterInstances[$name] = new $class($this);
        }
        return $this->interpreterInstances;
    }

    /**
     * DUO-3234 regenerator loading — the exact same trust boundary and
     * validate/load/instantiate shape as interpreters() above (same
     * rationale: manifest-shipped PHP, engine holds only the loading
     * contract, never plugin-specific logic), deliberately mirrored rather
     * than sharing code with interpreters(), for the same reason
     * assert_meta_schema() stays separate from assert_row_schema() in
     * Snapshot.php — the two mechanisms' discovery differs enough
     * (interpreters: one name per manifest, off a top-level `interpreter`
     * key; regenerators: potentially several names per manifest, one per
     * declaring post_types{} entry's `regen_dependency.regenerator`) that
     * a shared helper would need its own branching, buying nothing over two
     * short, independently-readable methods.
     *
     * A declared name resolves to <manifests_dir>/regenerators/<name>.php,
     * which must define \Duo\Regenerators\<CamelCase(name)> with
     * regenerate(int $localId): void. Any exception it throws is the
     * caller's (Apply::regen_dependencies()) hard-failure signal — there is
     * no success/failure return-value protocol, matching interpreters' own
     * all-or-throw shape.
     *
     * Public, like interpreters() above, and for the same reason: the callers
     * are in other classes — Apply::regen_dependencies() drives it live, and
     * `duo manifest-validate` resolves it offline so a declared-but-missing
     * regenerator file is refused by the authoring check rather than by the
     * first apply that needs it.
     *
     * @return array<string, object> regenerator name => instance
     */
    public function regenerators(): array {
        if ($this->regeneratorInstances !== null) {
            return $this->regeneratorInstances;
        }
        $this->regeneratorInstances = [];
        foreach ($this->manifests as $m) {
            foreach ($m['post_types'] ?? [] as $postType => $decl) {
                $name = $decl['regen_dependency']['regenerator'] ?? null;
                if ($name === null || isset($this->regeneratorInstances[$name])) {
                    continue;
                }
                if (!preg_match('/^[a-z0-9_-]+$/', $name)) {
                    throw new \RuntimeException(
                        "duo: manifest '{$m['name']}' post_types.$postType declares invalid regenerator name '$name'"
                    );
                }
                $file = self::manifests_dir() . '/regenerators/' . $name . '.php';
                if (!is_file($file)) {
                    throw new \RuntimeException(
                        "duo: manifest '{$m['name']}' post_types.$postType wants regenerator '$name' but $file is missing — "
                        . 'regenerator code ships with its manifest, not the engine'
                    );
                }
                require_once $file;
                $class = '\\Duo\\Regenerators\\' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
                if (!class_exists($class) || !method_exists($class, 'regenerate')) {
                    throw new \RuntimeException(
                        "duo: regenerator file $file must define $class with regenerate(int \$localId): void"
                    );
                }
                $this->regeneratorInstances[$name] = new $class($this);
            }
        }
        return $this->regeneratorInstances;
    }

    /**
     * Context-aware meta classification: interpreters see the owning
     * entity's full meta map (shadow keys and all) and win over static rules.
     * post_meta_rule() is the required baseline contract; term/user hooks are
     * deliberately optional, so an existing post-only interpreter retains
     * byte-for-byte lookup behavior on the two new dispatch paths.
     */
    public function meta_rule_for_post(string $key, array $allMeta): ?array {
        return $this->meta_rule_for_interpreter_hook('post_meta_rule', 'post_meta', $key, $allMeta);
    }

    public function meta_rule_for_term(string $key, array $allMeta): ?array {
        return $this->meta_rule_for_interpreter_hook('term_meta_rule', 'term_meta', $key, $allMeta);
    }

    public function meta_rule_for_user(string $key, array $allMeta): ?array {
        $rule = $this->meta_rule_for_interpreter_hook('user_meta_rule', 'user_meta', $key, $allMeta);
        if ($rule !== null) {
            self::validate_user_meta_rule($rule, "user_meta.$key");
        }
        return $rule;
    }

    /**
     * DUO-3263: a third optional interpreter hook, same contract shape as
     * term/user above, for options whose NAME a manifest's option_namespaces
     * claims but whose per-name classification can't be a static exact/
     * pattern rule (ACF options-page fields: arbitrary field names, ref kind
     * determined by a shadow-key-pointed schema, exactly like post/term meta
     * — see manifests/interpreters/acf.php's option_rule()). $allOptions is
     * the full option-name classification context (mirroring $allMeta's
     * "owning scope, shadow keys and all" shape) — options have no single
     * owning entity to scope the map to. Live capture passes raw wp_options
     * values; immutable-tree callers pass OptionState::classification_values(),
     * which adds only valid v2 witness context to ordinary present values.
     *
     * Routes through option_rule_details_for_option() rather than the plain
     * meta_rule_for_interpreter_hook() every other meta_rule_for_*() uses —
     * caught live (regress_acf_term_options_fields.sh's first run):
     * OptionState::assert_rule_autoload() requires every options rule to
     * declare 'autoload' (or 'preserve'), and the static options path
     * always gets that via with_option_autoload()'s manifest-level
     * option_autoload default; an interpreter-returned rule bypassed it
     * entirely. Only the details() path knows which manifest's interpreter
     * answered, so autoload injection lives there (see its own docblock).
     */
    public function meta_rule_for_option(string $name, array $allOptions): ?array {
        return $this->option_rule_details_for_option($name, $allOptions)['rule'];
    }

    /**
     * Interpreter-aware sibling of owned_option_rule() (DUO-3263): same
     * namespace-ownership gate and cross-manifest-ambiguity check, but
     * consulting a declared interpreter's option_rule() before the static
     * options rule. A separate method rather than changing owned_option_rule()
     * itself — that accessor has callers uninterested in live/repository
     * option context (mirrors post_meta_rule()/meta_rule_for_post() staying
     * two separate methods rather than one changing shape underneath its
     * existing callers).
     */
    public function owned_option_rule_via_interpreter(string $name, array $allOptions): ?array {
        $owner = $this->option_namespace($name);
        if ($owner === null) {
            return null;
        }
        $details = $this->option_rule_details_for_option($name, $allOptions);
        if ($details['rule'] !== null && $details['source'] !== 'site.duo.json'
            && $details['source'] !== $owner['owner']
            && !str_starts_with((string) $details['source'], $owner['owner'] . ' (interpreter')) {
            throw new \RuntimeException(
                "duo: option '$name' namespace is owned by '{$owner['owner']}' but its classification comes from "
                . "'{$details['source']}' — cross-manifest ownership is ambiguous"
            );
        }
        return $details['rule'];
    }

    /**
     * Backward-compatible facade kept for callers introduced by DUO-3262.
     * Authored user meta is representable now, so classification itself is
     * no longer a blocker; Capture performs the value-level PII/secret and
     * shape checks while building the login-keyed sidecar.
     */
    public function user_meta_capture_blocker(string $key, array $allMeta): ?string {
        $this->meta_rule_for_user($key, $allMeta);
        return null;
    }

    /**
     * Missing owning-user behavior for one sidecar. Any authored key using
     * the fail-closed default wins over warn-and-skip, so mixed declarations
     * can never partially apply a sidecar.
     */
    public function user_meta_missing_behavior(array $meta): string {
        $hasAuthored = false;
        foreach ($meta as $key => $_) {
            $rule = $this->meta_rule_for_user((string) $key, $meta);
            if (($rule['class'] ?? '') !== 'authored') {
                continue;
            }
            $hasAuthored = true;
            if (($rule['missing_user'] ?? 'block') !== 'warn') {
                return 'block';
            }
        }
        return $hasAuthored ? 'warn' : 'block';
    }

    private function meta_rule_for_interpreter_hook(
        string $hook,
        string $section,
        string $key,
        array $allMeta
    ): ?array {
        foreach ($this->interpreters() as $i) {
            if (!method_exists($i, $hook)) {
                continue;
            }
            $rule = $i->{$hook}($key, $allMeta);
            if ($rule !== null) {
                return $rule;
            }
        }
        return $this->rule($section, $key);
    }

    /** @return array{rule:?array, source:?string} */
    public function meta_rule_details_for_post(string $key, array $allMeta): array {
        return $this->rule_details_for_interpreter_hook(
            'post_meta_rule',
            $key,
            $allMeta,
            fn() => $this->post_meta_rule_details($key)
        );
    }

    /**
     * DUO-3263: option_rule() sibling of meta_rule_details_for_post() above,
     * for the same "which interpreter/manifest actually decided this"
     * provenance RepositoryAuthorization/RepositoryCompiler need (they
     * report a mismatched source, not just a classification).
     *
     * @return array{rule:?array, source:?string}
     */
    public function option_rule_details_for_option(string $name, array $allOptions): array {
        return $this->rule_details_for_interpreter_hook(
            'option_rule',
            $name,
            $allOptions,
            fn() => $this->option_rule_details($name)
        );
    }

    /**
     * Shared by meta_rule_details_for_post() (post_meta_rule is mandatory —
     * every interpreter already satisfies method_exists() by the load-time
     * check in interpreters(), so the guard below is a no-op there) and
     * option_rule_details_for_option() (option_rule is optional, so the
     * guard is load-bearing there, mirroring meta_rule_for_interpreter_hook()'s
     * own method_exists() gate).
     *
     * The $hook === 'option_rule' branch below is the one hook-specific
     * exception to this being a generic dispatcher: every STATIC options
     * rule already gets the owning manifest's own option_autoload default
     * injected (with_option_autoload(), called at every static rule()
     * options lookup) — OptionState::assert_rule_autoload() hard-requires
     * every options rule to declare 'autoload' (or 'preserve') before a row
     * can be captured. An interpreter-returned options rule needs the exact
     * same treatment or it can never pass that check (caught live:
     * regress_acf_term_options_fields.sh's first run failed capture outright
     * with "option '...' has autoload 'off' but policy declares NULL").
     * term_meta/user_meta rules have no such concept, so this is scoped to
     * the one hook name that does, not a general behavior change.
     *
     * @return array{rule:?array, source:?string}
     */
    private function rule_details_for_interpreter_hook(
        string $hook,
        string $key,
        array $allMeta,
        callable $staticDetails
    ): array {
        foreach ($this->interpreters() as $name => $i) {
            if (!method_exists($i, $hook)) {
                continue;
            }
            $rule = $i->{$hook}($key, $allMeta);
            if ($rule === null) {
                continue;
            }
            foreach ($this->manifests as $m) {
                if (($m['interpreter'] ?? null) === $name) {
                    if ($hook === 'option_rule') {
                        $rule = self::with_option_autoload($rule, $m);
                    }
                    return [
                        'rule' => $rule,
                        'source' => (string) ($m['name'] ?? '?') . " (interpreter $name)",
                    ];
                }
            }
            return ['rule' => $rule, 'source' => "interpreter $name"];
        }
        return $staticDetails();
    }

    /** @return array{rule:?array, source:?string} */
    public function meta_rule_details_for_term(string $key, array $allMeta): array {
        foreach ($this->interpreters() as $name => $i) {
            if (!method_exists($i, 'term_meta_rule')) {
                continue;
            }
            $rule = $i->term_meta_rule($key, $allMeta);
            if ($rule === null) {
                continue;
            }
            foreach ($this->manifests as $m) {
                if (($m['interpreter'] ?? null) === $name) {
                    return [
                        'rule' => $rule,
                        'source' => (string) ($m['name'] ?? '?') . " (interpreter $name)",
                    ];
                }
            }
            return ['rule' => $rule, 'source' => "interpreter $name"];
        }
        return $this->rule_details('term_meta', $key);
    }

    /** @return array{rule:?array, source:?string} */
    public function meta_rule_details_for_user(string $key, array $allMeta): array {
        foreach ($this->interpreters() as $name => $i) {
            if (!method_exists($i, 'user_meta_rule')) {
                continue;
            }
            $rule = $i->user_meta_rule($key, $allMeta);
            if ($rule === null) {
                continue;
            }
            self::validate_user_meta_rule($rule, "interpreter $name user_meta.$key");
            foreach ($this->manifests as $m) {
                if (($m['interpreter'] ?? null) === $name) {
                    return [
                        'rule' => $rule,
                        'source' => (string) ($m['name'] ?? '?') . " (interpreter $name)",
                    ];
                }
            }
            return ['rule' => $rule, 'source' => "interpreter $name"];
        }
        $details = $this->rule_details('user_meta', $key);
        if ($details['rule'] !== null) {
            self::validate_user_meta_rule($details['rule'], "user_meta.$key");
        }
        return $details;
    }

    /**
     * Give schema-driven interpreters the immutable repository tree before
     * authorization. ACF field definitions are themselves canonical posts;
     * priming from those files keeps a fresh target and an already-mapped
     * target from classifying the same payload differently merely because
     * only one target has the definitions in its database yet.
     */
    public function prime_interpreters_from_repository(array $tree): void {
        foreach ($this->interpreters() as $i) {
            if (method_exists($i, 'prime_repository')) {
                $i->prime_repository($tree);
            }
        }
    }

    /**
     * Optional offline cross-entity constraints supplied by the same pinned
     * interpreter artifact that already classifies schema-driven meta. This
     * is deliberately not a second extension loader or a plugin callback:
     * compiler constraints execute only manifest-shipped interpreter code,
     * and that interpreter's bytes are part of the compiled artifact hash.
     *
     * @return array<int,array<string,mixed>> stable compiler diagnostics
     */
    public function repository_constraint_diagnostics(array $tree): array {
        $out = [];
        foreach ($this->interpreters() as $name => $i) {
            if (!method_exists($i, 'repository_diagnostics')) {
                continue;
            }
            foreach ((array) $i->repository_diagnostics($tree) as $d) {
                if (!is_array($d)) {
                    throw new \RuntimeException("duo: interpreter '$name' returned a non-array repository diagnostic");
                }
                $d['adapter'] ??= $name;
                $out[] = $d;
            }
        }
        return $out;
    }

    /** @return array{rule:?array, source:?string} */
    public function post_type_rule_details(string $postType): array {
        $site = $this->site['policy']['scope']['post_type'][$postType] ?? null;
        if (is_array($site)) {
            return ['rule' => $site, 'source' => 'site.duo.json'];
        }
        foreach ($this->manifests as $m) {
            if (isset($m['post_types'][$postType]['class'])) {
                return [
                    'rule' => ['class' => $m['post_types'][$postType]['class']],
                    'source' => (string) ($m['name'] ?? '?'),
                ];
            }
        }
        return ['rule' => null, 'source' => null];
    }

    /** Whole-taxonomy disposition, parallel to post_type_rule_details().
     * A structural manifest declaration without an explicit class defaults
     * to authored: it names portable data the adapter understands, but the
     * site must still opt that taxonomy into its authored scope. */
    public function taxonomy_rule_details(string $taxonomy): array {
        $site = $this->site['policy']['scope']['taxonomy'][$taxonomy] ?? null;
        if (is_array($site)) {
            return ['rule' => $site, 'source' => 'site.duo.json'];
        }
        foreach ($this->manifests as $m) {
            if (isset($m['taxonomies'][$taxonomy])) {
                return [
                    'rule' => ['class' => (string) ($m['taxonomies'][$taxonomy]['class'] ?? 'authored')],
                    'source' => (string) ($m['name'] ?? '?'),
                ];
            }
        }
        return ['rule' => null, 'source' => null];
    }

    /**
     * Pure repository-side taxonomy authorization. taxonomies() expands
     * pattern matches from the live target database, which is right for
     * capture discovery but wrong for immutable-revision preflight: the same
     * repository must not pass on a mapped target and fail on a fresh one.
     *
     * @return array{authorized:bool, source:?string}
     */
    public function taxonomy_scope_details(string $taxonomy): array {
        if (in_array($taxonomy, $this->site['policy']['taxonomies'] ?? ['category', 'post_tag'], true)
            || (($this->site['policy']['scope']['taxonomy'][$taxonomy]['class'] ?? null) === 'authored')) {
            return ['authorized' => true, 'source' => 'site.duo.json'];
        }
        foreach ($this->manifests as $m) {
            foreach ($m['taxonomy_patterns'] ?? [] as $pat) {
                if (preg_match('/' . $pat['match'] . '/', $taxonomy)) {
                    return ['authorized' => true, 'source' => (string) ($m['name'] ?? '?')];
                }
            }
        }
        return ['authorized' => false, 'source' => null];
    }

    /**
     * 'blocks' (default: block-parser rewriting + URL tokenization) or
     * 'verbatim' (byte-preserved — for post types whose content is serialized
     * data, e.g. acf-field, where URL substitution would corrupt lengths).
     */
    public function body_mode(string $postType): string {
        foreach ($this->manifests as $m) {
            $mode = $m['post_types'][$postType]['body'] ?? null;
            if ($mode !== null) {
                return $mode;
            }
        }
        return 'blocks';
    }

    /**
     * 'early' post types finalize before everything else in apply phase 2:
     * definition CPTs (acf-field*) whose content interpreters read to type
     * OTHER entities' meta — declared ordering, never glob-alphabetical luck.
     */
    public function post_type_phase(string $postType): string {
        foreach ($this->manifests as $m) {
            $phase = $m['post_types'][$postType]['phase'] ?? null;
            if ($phase !== null) {
                return $phase;
            }
        }
        return 'normal';
    }

    /**
     * DUO-3234 — a post type's derived-table hard-dependency declaration, if
     * any: `{"regenerator": "<name>", "verify": {"table": "<t>", "column": "<c>"}}`.
     * v2 scope, stated loudly: post_types{}-keyed only (a per-post-type
     * property, matching the phase/fields precedents immediately above and
     * below — never a `tables{}` declaration, since the derived table itself
     * has no independent identity to declare; see agent/src/Snapshot.php's
     * own "gives no special meaning to any class value besides
     * authored_snapshot/authored_snapshot_meta" precedent, unchanged by
     * this). If a derived table ever hangs off a TERM or a declared
     * custom-table row instead of a post, that needs its own design — not
     * assumed covered here. First-declaring-manifest wins, same precedence
     * as its post_types{} siblings (phase/body) immediately around it, for
     * the same reason: consistency with how this section already resolves,
     * not an independently-chosen precedence rule for this one field.
     */
    public function regen_dependency(string $postType): ?array {
        foreach ($this->manifests as $m) {
            $decl = $m['post_types'][$postType]['regen_dependency'] ?? null;
            if ($decl !== null) {
                return $decl;
            }
        }
        return null;
    }

    /**
     * Return the optional batch/refresh contract for a post regeneration
     * dependency.
     *
     * The original regen_dependency contract is intentionally still the
     * default: callers get one id at a time and only a missing verification
     * row triggers regenerate().  A manifest may opt into the newer batch
     * boundary with either
     *
     *     "batch": {"enabled": true, "always_on_write": true}
     *
     * or the equivalent "refresh" spelling.  The latter exists because the
     * useful distinction for derived lookup tables is that a row can exist
     * and still be stale.  Both spellings are normalized here so the engine
     * has one contract and plugin code remains in the manifest regenerator.
     * A bare true is shorthand for an enabled, always-on-write batch.
     *
     * @return array{enabled:bool,always_on_write:bool}|null
     */
    public function regen_batch(string $postType): ?array {
        $decl = $this->regen_dependency($postType);
        if ($decl === null) {
            return null;
        }
        $raw = $decl['batch'] ?? ($decl['refresh'] ?? null);
        if ($raw === null && array_key_exists('always_on_write', $decl)) {
            $raw = ['enabled' => true, 'always_on_write' => $decl['always_on_write']];
        }
        if ($raw === null || $raw === false) {
            return null;
        }
        if ($raw === true) {
            return ['enabled' => true, 'always_on_write' => true];
        }
        if (!is_array($raw)) {
            // Load-time validation catches this; keep this method defensive
            // for frozen/third-party Policy instances constructed by tests.
            return null;
        }
        $config = [
            'enabled' => array_key_exists('enabled', $raw) ? (bool) $raw['enabled'] : true,
            'always_on_write' => array_key_exists('always_on_write', $raw)
                ? (bool) $raw['always_on_write']
                : true,
        ];
        return $config['enabled'] ? $config : null;
    }

    /** Compatibility alias for callers that prefer the explicit name. */
    public function regen_batch_dependency(string $postType): ?array {
        return $this->regen_batch($postType);
    }

    /** @return array<string,array{enabled:bool,always_on_write:bool}> */
    public function regen_batch_post_types(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['post_types'] ?? [] as $postType => $_decl) {
                if (isset($out[$postType])) {
                    continue;
                }
                $batch = $this->regen_batch((string) $postType);
                if ($batch !== null && !empty($batch['enabled'])) {
                    $out[(string) $postType] = $batch;
                }
            }
        }
        return $out;
    }

    /**
     * v2-supported post FIELD classification surface (task #88 origin,
     * extended by the evidence-backed Woo timestamp case). A field
     * name must appear here before ANY manifest may declare it under
     * `post_types.<type>.fields.<field>` — validate_field_classes() below
     * enforces this at load() time, loudly, rather than silently ignoring
     * an unsupported declaration. `title` is derived for Woo variation
     * self-healing; `modified`/`modified_gmt` are derived for Woo products
     * and variations because WooCommerce-mediated saves own those timestamps
     * even when the authored product inputs did not change. 'slug' remains
     * excluded on purpose: a post's slug participates in its canonical
     * FILENAME and in collision/identity checks (Apply::find_collision()),
     * so "derived" would need to answer questions (does the filename track
     * the live value? does identity?) this mechanism does not answer.
     * status/menu_order/comment_status/ping_status/excerpt have no proven
     * self-healing precedent. Widening this list is a deliberate, separate
     * decision per field, not a mechanical extension of the mechanism.
     *
     * A MAP, front-matter field => the wp_posts column it writes (DUO-3318).
     * Apply::finalize_post() has to drop the mapped COLUMN from its UPDATE
     * payload, and it used to carry its own independent copy of this
     * translation: widening the allowlist here without also widening that
     * literal would have produced a field a manifest may legally declare
     * `derived` and that apply then overwrites anyway — a silent
     * half-implementation of the very classification the declaration asked
     * for. One declaration site makes that combination unrepresentable.
     * Public because the consumer lives in another class; the value is the
     * engine's own vocabulary, not a manifest input.
     */
    public const DERIVABLE_FIELD_COLUMNS = [
        'title' => 'post_title',
        'modified' => 'post_modified',
        'modified_gmt' => 'post_modified_gmt',
    ];

    /** @see DERIVABLE_FIELD_COLUMNS */
    private const FIELD_CLASSES = ['derived'];

    /**
     * Post-FIELD classification — NOT post_meta/options (Policy::rule()'s
     * 'post_meta'/'term_meta'/'options' sections), and not the same thing
     * as this class's own post_types()/body_mode()/post_type_phase()
     * either: post_types() is the site-policy SCOPE list (which types
     * capture at all), body_mode()/post_type_phase() classify how a whole
     * post TYPE behaves. This classifies one of the ~13 keys every post
     * FILE carries unconditionally (Capture::build_post()'s $front /
     * Apply::finalize_post()'s $wpdb->update() payload — title, slug,
     * status, dates, parent, menu_order, comment_status, ping_status,
     * excerpt) — fields no manifest could classify at all before task #88
     * introduced this mechanism,
     * unlike meta/options which have supported `class: derived` from v0.
     *
     * The proven cases are WooCommerce-owned fields:
     * WC_Product_Variation_Data_Store_CPT::read() (task #72's confirmed root
     * cause) silently recomputes a variation's post_title from the parent's
     * attribute order + the variation's own current attribute values on
     * EVERY wc_get_product() load, writing it via a raw $wpdb->update()
     * specifically to dodge wp_update_post()/save_post — hook-free and
     * invisible to Apply's canary. Separately, ordinary WooCommerce product
     * and variation saves update post_modified/post_modified_gmt as a
     * persistence timestamp even when only runtime stock or another plugin-
     * owned value changed. Two environments can therefore disagree on these
     * bytes while every authored input is identical.
     *
     * Manifest-only, first declaring manifest wins — same precedence as
     * body_mode()/post_type_phase() immediately above, for the identical
     * reason description_refs_for_taxonomy() gives for its own no-site-
     * override stance: this is a structural fact about how a PLUGIN's post
     * type behaves (a fact this manifest is asserting about WooCommerce's
     * own code), not a site-local policy choice. It also sidesteps the
     * same real naming collision body_mode()/post_type_phase() already
     * avoid: site.duo.json's policy.post_types is already the flat SCOPE
     * LIST post_types() reads above — a site-policy override here would
     * need a different key or silently shadow that list.
     *
     * Default 'authored': every field is authored unless a manifest says
     * otherwise, matching how every post type captures fully today with
     * zero manifest declarations. See DERIVABLE_FIELD_COLUMNS for what a manifest
     * may actually declare — anything else fails loudly at load() time,
     * never silently here. Capture still records a derived field verbatim;
     * Canon strips it only from the hash basis, and Apply omits its mapped
     * database column only for an existing row.
     */
    public function field_class(string $postType, string $field): string {
        return $this->field_rule_details($postType, $field)['class'];
    }

    /** @return array{class:string, source:string} */
    public function field_rule_details(string $postType, string $field): array {
        foreach ($this->manifests as $m) {
            $class = $m['post_types'][$postType]['fields'][$field]['class'] ?? null;
            if ($class !== null) {
                return [
                    'class' => $class,
                    'source' => (string) ($m['name'] ?? '?'),
                ];
            }
        }
        return ['class' => 'authored', 'source' => 'repo-format'];
    }

    /**
     * v2-supported MENU FIELD classification surface (DUO-3272) — same
     * purpose as DERIVABLE_FIELD_COLUMNS above (task #88), but for `menus/*.json`
     * entities: a field name must appear here before ANY manifest may
     * declare it under top-level `menu_fields.<field>` —
     * validate_menu_field_classes() below enforces this at load() time.
     * Deliberately just 'locations': it is the only menu field with a
     * proven self-healing precedent under a real plugin (Polylang).
     *
     * Structurally different from DERIVABLE_FIELD_COLUMNS/field_class() even
     * though the intent rhymes: field_class() is a narrow opt-in (implicit
     * 'authored', a manifest may only ever DECLARE 'derived' — no core
     * declaration exists to yield to, since every shipped user is a PLUGIN
     * manifest asserting a fact about its own post type). menu_fields
     * needs the full DUO-3249 core-yields-to-plugin precedence instead:
     * core.json declares 'locations' authored as its v0 baseline (every
     * ordinary, non-Polylang site), and a pinned plugin manifest may
     * reclassify it — see menu_field_rule_details() below, which reuses
     * rule_details('menu_fields', $field) directly rather than
     * field_rule_details()'s simpler first-match-wins loop. That is why
     * MENU_FIELD_CLASSES (unlike FIELD_CLASSES) allows both 'authored' and
     * 'derived': core's own declaration must be expressible too.
     */
    private const MENU_DERIVABLE_FIELDS = ['locations'];

    /** @see MENU_DERIVABLE_FIELDS */
    private const MENU_FIELD_CLASSES = ['authored', 'derived'];

    /**
     * Menu-FIELD classification (DUO-3272). Proven case: Polylang's own
     * Languages::update_default() (wp-content/plugins/polylang/src/Model/
     * Languages.php:774) unconditionally rewrites
     * theme_mods_<stylesheet>['nav_menu_locations'] — the exact raw value
     * Capture::scope_menus() reads and Apply::assign_locations() writes —
     * from Polylang's OWN nav_menus[theme][loc][lang] bookkeeping, any
     * time the default language changes OR Languages::get_default()'s own
     * fallback fires ("the default language is lost... let's select one
     * arbitrarily" — an environment-dependent term-query-ordering pick
     * this engine does not control). Two environments processing the
     * identical captured state can end up with a DIFFERENT menu owning
     * the same location, entirely outside Duo's own capture/apply cycle —
     * DUO-3272's filed repro is 100% reproducible on demand: calling
     * update_default() with a different language flips which menu file's
     * `locations` holds a given slot, byte-for-byte matching the original
     * flake.
     *
     * Owner ruling (issue comment 8e0edde6): the raw slot is a PROJECTION
     * of state Duo already carries losslessly elsewhere — polylang.json's
     * own sub_keys mechanism (DUO-3233/task #121) already propagates both
     * `nav_menus` (which menu belongs at which location, PER LANGUAGE) and
     * `default_lang` inside the `polylang` option itself. So classifying
     * `locations` 'derived' under Polylang does not drop authored
     * information — it stops Duo from ALSO separately carrying a value
     * Polylang's own machinery treats as its mutable cache and rewrites at
     * will, which is exactly what made the flake possible. Default
     * 'authored' if nothing declares a rule at all (defensive fallback
     * only — core.json's own menu_fields.locations declaration means this
     * branch is not expected to be reached in practice).
     */
    public function menu_field_class(string $field): string {
        return $this->menu_field_rule($field)['class'] ?? 'authored';
    }

    private function menu_field_rule(string $field): ?array {
        return $this->rule('menu_fields', $field);
    }

    /** @return array{rule:?array, source:?string} */
    public function menu_field_rule_details(string $field): array {
        return $this->rule_details('menu_fields', $field);
    }

    /** Load-time validation for every surface using the shared ref grammar. */
    private static function validate_reference_shapes(array $source, string $label): void {
        foreach (['options', 'post_meta', 'term_meta', 'user_meta'] as $section) {
            foreach (($source[$section] ?? []) as $name => $rule) {
                if (!is_array($rule) || array_is_list($rule)) {
                    continue; // the section's existing validator owns its base shape
                }
                self::validate_reference_value_rule(
                    $rule,
                    "$label.$section.$name",
                    $section === 'options'
                );
            }
        }
        foreach (['option_patterns', 'meta_patterns', 'option_name_refs'] as $section) {
            foreach (($source[$section] ?? []) as $i => $rule) {
                if (is_array($rule) && !array_is_list($rule)) {
                    self::validate_reference_value_rule($rule, "$label.{$section}[$i]");
                }
            }
        }
        foreach (($source['dynamic_options'] ?? []) as $name => $declaration) {
            foreach (($declaration['sub_keys'] ?? []) as $key => $rule) {
                if (is_array($rule) && !array_is_list($rule)) {
                    self::validate_reference_value_rule(
                        $rule,
                        "$label.dynamic_options.$name.sub_keys.$key"
                    );
                }
            }
        }
        foreach (($source['taxonomies'] ?? []) as $taxonomy => $declaration) {
            if (isset($declaration['description_refs'])) {
                ReferenceRules::description(
                    $declaration['description_refs'],
                    "$label.taxonomies.$taxonomy.description_refs"
                );
            }
        }
        foreach (($source['tables'] ?? []) as $table => $declaration) {
            if (($declaration['class'] ?? '') !== 'authored_snapshot_meta') {
                continue;
            }
            foreach (($declaration['keys'] ?? []) as $key => $rule) {
                if (!is_array($rule) || array_is_list($rule)) {
                    throw new \RuntimeException(
                        "duo: $label.tables.$table.keys.$key must be an attached-meta rule object"
                    );
                }
                self::validate_reference_value_rule($rule, "$label.tables.$table.keys.$key");
            }
        }
    }

    private static function validate_reference_value_rule(
        array $rule,
        string $where,
        bool $allowSubKeys = false
    ): void {
        ReferenceRules::value_rule($rule, $where);
        if (array_key_exists('sub_keys', $rule) && !$allowSubKeys) {
            throw new \RuntimeException(
                "duo: $where cannot declare sub_keys; the one-level sub_keys map belongs only on an exact or dynamic option declaration"
            );
        }
        foreach (($rule['sub_keys'] ?? []) as $name => $subRule) {
            if (is_array($subRule) && !array_is_list($subRule)) {
                self::validate_reference_value_rule($subRule, "$where.sub_keys.$name", false);
            }
        }
    }

    /**
     * Resolve keyspace names only after every pinned manifest is loaded, so
     * one adapter may safely refer to an authored table declared by another
     * without making pin order semantic. Also reject the pre-existing flat
     * wire ambiguity where two EAV sidecars attach to one row table.
     */
    private static function validate_reference_keyspaces_and_sidecars(self $policy): void {
        $allowed = ['post', 'term', 'tt'];
        foreach ($policy->declared_tables() as $table => $declaration) {
            if (($declaration['class'] ?? '') === 'authored_snapshot') {
                $kind = (string) ($declaration['id_kind'] ?? '');
                if ($kind !== '') {
                    $allowed[] = $kind;
                }
            }
        }
        $allowed = array_values(array_unique($allowed));

        $checkSource = static function (array $source, string $label) use ($allowed): void {
            foreach (['options', 'post_meta', 'term_meta', 'user_meta'] as $section) {
                foreach (($source[$section] ?? []) as $name => $rule) {
                    if (is_array($rule) && !array_is_list($rule)) {
                        self::assert_reference_rule_keyspaces($rule, $allowed, "$label.$section.$name");
                    }
                }
            }
            foreach (['option_patterns', 'meta_patterns', 'option_name_refs'] as $section) {
                foreach (($source[$section] ?? []) as $i => $rule) {
                    if (is_array($rule) && !array_is_list($rule)) {
                        self::assert_reference_rule_keyspaces($rule, $allowed, "$label.{$section}[$i]");
                    }
                }
            }
            foreach (($source['dynamic_options'] ?? []) as $name => $declaration) {
                foreach (($declaration['sub_keys'] ?? []) as $key => $rule) {
                    if (is_array($rule) && !array_is_list($rule)) {
                        self::assert_reference_rule_keyspaces(
                            $rule,
                            $allowed,
                            "$label.dynamic_options.$name.sub_keys.$key"
                        );
                    }
                }
            }
            foreach (($source['taxonomies'] ?? []) as $taxonomy => $declaration) {
                if (isset($declaration['description_refs'])) {
                    $normalized = ReferenceRules::description(
                        $declaration['description_refs'],
                        "$label.taxonomies.$taxonomy.description_refs"
                    );
                    ReferenceRules::assert_keyspaces(
                        $normalized,
                        $allowed,
                        "$label.taxonomies.$taxonomy.description_refs"
                    );
                }
            }
            foreach (($source['tables'] ?? []) as $table => $declaration) {
                if (($declaration['class'] ?? '') !== 'authored_snapshot_meta') {
                    continue;
                }
                foreach (($declaration['keys'] ?? []) as $key => $rule) {
                    if (is_array($rule) && !array_is_list($rule)) {
                        self::assert_reference_rule_keyspaces(
                            $rule,
                            $allowed,
                            "$label.tables.$table.keys.$key"
                        );
                    }
                }
            }
        };

        $checkSource($policy->site['policy'] ?? [], 'site.duo.json');
        foreach ($policy->manifests as $manifest) {
            $checkSource($manifest, "manifest '" . ($manifest['name'] ?? '?') . "'");
        }

        $owners = [];
        foreach ($policy->declared_tables() as $table => $declaration) {
            if (($declaration['class'] ?? '') !== 'authored_snapshot_meta') {
                continue;
            }
            $attached = $declaration['attached_to'] ?? null;
            if (!is_array($attached) || array_is_list($attached)
                || !is_string($attached['table'] ?? null) || $attached['table'] === ''
                || !is_string($attached['column'] ?? null) || $attached['column'] === '') {
                throw new \RuntimeException(
                    "duo: attached-meta table '$table' must declare attached_to {table, column}"
                );
            }
            $owner = $attached['table'];
            if (isset($owners[$owner])) {
                throw new \RuntimeException(
                    "duo: attached-meta tables '{$owners[$owner]}' and '$table' both attach to '$owner'; "
                    . 'the canonical row has one flat meta map, so multiple sidecars are ambiguous'
                );
            }
            $owners[$owner] = $table;
        }
    }

    /** @param string[] $allowed */
    private static function assert_reference_rule_keyspaces(array $rule, array $allowed, string $where): void {
        ReferenceRules::assert_keyspaces($rule, $allowed, $where);
        foreach (($rule['sub_keys'] ?? []) as $name => $subRule) {
            if (is_array($subRule) && !array_is_list($subRule)) {
                self::assert_reference_rule_keyspaces($subRule, $allowed, "$where.sub_keys.$name");
            }
        }
    }

    /**
     * Loud, load-time guard for field_class()'s manifest input (mirrors
     * interpreters()'s "throw immediately, never degrade silently" posture
     * for a bad manifest declaration): a manifest naming an unsupported
     * field, or an unsupported class for a supported field, fails EVERY
     * command that loads this manifest (capture/plan/apply/lint/pending),
     * not just the specific post_type/field it misdeclares — the original
     * task #88 "start scope tight" instruction, extended only by the
     * evidence-backed Woo timestamp case, is enforced structurally rather
     * than left as a convention. Called from load() for every manifest, so
     * a bad declaration can never reach field_class()'s per-post lookup.
     */
    private static function validate_field_classes(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        foreach ($manifest['post_types'] ?? [] as $postType => $decl) {
            foreach ($decl['fields'] ?? [] as $field => $rule) {
                if (!array_key_exists($field, self::DERIVABLE_FIELD_COLUMNS)) {
                    throw new \RuntimeException(
                        "duo: manifest '$name' declares post_types.$postType.fields.$field, but only "
                        . implode(', ', array_keys(self::DERIVABLE_FIELD_COLUMNS))
                        . ' may be field-classified in v2 (the evidence-backed allowlist remains deliberately '
                        . 'tight — see Policy::DERIVABLE_FIELD_COLUMNS\' docblock). The post-field vocabulary is '
                        . 'engine-owned: a new derivable field is an engine change with a spec bump (allowlist '
                        . 'entry, wp_posts column mapping, and its own evidence), never a manifest declaration'
                    );
                }
                $class = $rule['class'] ?? null;
                if (!in_array($class, self::FIELD_CLASSES, true)) {
                    throw new \RuntimeException(
                        "duo: manifest '$name' declares post_types.$postType.fields.$field.class="
                        . var_export($class, true) . ' but only ' . implode(', ', self::FIELD_CLASSES)
                        . ' is supported for post fields in v2 — the class vocabulary for this surface is '
                        . 'engine-owned (field_class() defaults every undeclared field to authored, so '
                        . 'declaring authored is a no-op and any other class has no defined apply semantics)'
                    );
                }
            }
        }
    }

    /**
     * Loud, load-time guard for menu_field_class()'s manifest input —
     * DUO-3272's own version of validate_field_classes() immediately
     * above, kept as its own function rather than merged into it (the same
     * "mirrored for its own key shape rather than extended" posture
     * validate_regen_dependencies() documents for itself): `menu_fields.
     * <field>` is a flat, single-level top-level manifest key — menus have
     * no "type" dimension the way posts do, so there is no per-type
     * declaration to nest under. Also unlike validate_field_classes(),
     * MENU_FIELD_CLASSES accepts 'authored' as well as 'derived' — core.
     * json needs to express its own v0 baseline declaration here (DUO-3249
     * precedence needs a core declaration to exist at all), not just a
     * plugin's override. A manifest naming an unsupported menu field, or
     * an unsupported class for a supported one, fails EVERY command that
     * loads this manifest — exactly like its post-field counterpart.
     */
    private static function validate_menu_field_classes(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        foreach ($manifest['menu_fields'] ?? [] as $field => $rule) {
            if (!in_array($field, self::MENU_DERIVABLE_FIELDS, true)) {
                throw new \RuntimeException(
                    "duo: manifest '$name' declares menu_fields.$field, but only "
                    . implode(', ', self::MENU_DERIVABLE_FIELDS) . ' may be field-classified in v2 (DUO-3272 '
                    . 'scoped this deliberately tight, mirroring task #88 — see Policy::MENU_DERIVABLE_FIELDS\' docblock)'
                );
            }
            $class = $rule['class'] ?? null;
            if (!in_array($class, self::MENU_FIELD_CLASSES, true)) {
                throw new \RuntimeException(
                    "duo: manifest '$name' declares menu_fields.$field.class="
                    . var_export($class, true) . ' but only ' . implode(', ', self::MENU_FIELD_CLASSES)
                    . ' is supported for menu fields in v2'
                );
            }
        }
    }

    /**
     * The closed `post_types.<t>.body` vocabulary. 'blocks' (the default) runs
     * the block parser and URL tokenizer over post_content; 'verbatim'
     * byte-preserves it, for a definition CPT whose body is serialized data
     * where a URL substitution would corrupt the encoded string lengths.
     * Consumers compare against 'verbatim' EXACTLY (Capture, Apply, Lint), so
     * every other spelling — including a plausible-looking 'raw' or 'none' —
     * silently meant 'blocks' and quietly corrupted the very bodies the
     * declaration was written to protect.
     */
    private const BODY_MODES = ['blocks', 'verbatim'];

    /**
     * The closed `post_types.<t>.phase` vocabulary. 'early' finalizes a type
     * before all others in apply phase 2 — for definition CPTs whose content
     * interpreters read to type OTHER entities' meta. Same silent-failure
     * shape as `body`: Apply compares against 'early' exactly, so a misspelled
     * phase reverted the type to glob-alphabetical ordering, which is the
     * precise accident declared ordering exists to remove.
     */
    private const POST_TYPE_PHASES = ['normal', 'early'];

    /**
     * Loud, load-time guard for the two per-post-type behavior switches
     * body_mode()/post_type_phase() read (DUO-3318).
     *
     * Both accessors default an absent OR unrecognized value to the safe
     * spelling and return it silently — correct as a lookup contract (a
     * consumer may not invent a mode), wrong as the ONLY check, because it
     * makes a typo indistinguishable from an intentional omission. This is
     * the same posture validate_field_classes() takes for the sibling
     * `fields` key, mirrored rather than merged for the same reason
     * validate_menu_field_classes() gives for itself: these are flat scalar
     * switches on the post-type declaration, not a nested per-name rule map.
     */
    private static function validate_post_type_contracts(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        foreach ($manifest['post_types'] ?? [] as $postType => $decl) {
            if (!is_array($decl)) {
                continue; // shape already refused by validate_scope_classes()
            }
            foreach ([['body', self::BODY_MODES], ['phase', self::POST_TYPE_PHASES]] as [$key, $legal]) {
                if (!array_key_exists($key, $decl)) {
                    continue;
                }
                if (!in_array($decl[$key], $legal, true)) {
                    throw new \RuntimeException(
                        "duo: manifest '$name' declares post_types.$postType.$key="
                        . var_export($decl[$key], true) . ' but the vocabulary is closed ('
                        . implode(', ', $legal) . ') — it is engine-owned, because each value names engine '
                        . 'behavior the engine implements; a new one is an engine change with a spec bump, not a '
                        . 'manifest declaration'
                    );
                }
            }
        }
    }

    /**
     * Loud, load-time guard for every declared table (DUO-3318), for
     * manifests AND for site.duo.json's own policy.tables overrides —
     * declared_tables() merges the site's last, so a malformed override is
     * exactly as fatal as a malformed manifest and deserves the same
     * offline refusal.
     *
     * The grammar itself is assert_table_grammar()'s (see its docblock for
     * why it lives beside declared_tables() rather than in Snapshot.php);
     * this is only the enumeration that feeds it every declaration.
     */
    private static function validate_tables(array $source, string $label): void {
        $tables = $source['tables'] ?? [];
        if (!is_array($tables) || (array_is_list($tables) && $tables !== [])) {
            throw new \RuntimeException("duo: $label tables must be an object keyed by unprefixed table name");
        }
        foreach ($tables as $table => $decl) {
            self::assert_table_grammar((string) $table, $decl, $label);
        }
    }

    /**
     * Loud, load-time guard for the two attribute-rewriting registries
     * (DUO-3318): `block_attrs` (blockName => list of rules) and its flatter
     * shortcode twin `shortcode_attrs` (tagName => list of rules).
     *
     * Structure only — the ref KIND vocabulary is checked once, across every
     * pinned manifest, by validate_ref_kinds() below, because a legal kind
     * includes any declared table's id_kind and no single manifest can see
     * that set. What this catches is the shape errors that used to fail
     * silently: Blocks::apply_rewrite() skips any rule whose `path` names an
     * attribute the block does not carry, so a misspelled `path` is
     * indistinguishable from "this block simply had no such attribute" — a
     * declared ref that is never rewritten, leaving a raw environment-local
     * id in canonical state. `type` decides scalar-vs-list handling and
     * defaults to 'int', so 'array' or 'int[] ' quietly truncated a gallery's
     * id list to one dropped attribute.
     *
     * Every rule must carry exactly one disposition, because the four are
     * mutually exclusive dispatch branches in Blocks.php, not composable
     * flags: `lint_ok` (declared non-ref, nothing to rewrite),
     * `tokenize: "text"` (a URL-bearing string attribute), `kind` (a static
     * ref kind), or `kind_from` (a ref kind dispatched from a sibling
     * attribute's value). A rule with none of them reaches
     * Blocks::resolve_kind()'s own throw at REWRITE time, mid-capture, on
     * whichever post happened to contain that block first.
     */
    private static function validate_attr_rules(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        foreach (['block_attrs', 'shortcode_attrs'] as $section) {
            $registry = $manifest[$section] ?? [];
            if (!is_array($registry) || (array_is_list($registry) && $registry !== [])) {
                throw new \RuntimeException("duo: manifest '$name' $section must be an object keyed by name");
            }
            foreach ($registry as $subject => $rules) {
                $where = "manifest '$name' $section.$subject";
                if (!is_array($rules) || !array_is_list($rules) || $rules === []) {
                    throw new \RuntimeException("duo: $where must be a non-empty list of rules");
                }
                foreach ($rules as $i => $rule) {
                    self::validate_attr_rule($rule, $section, $where . "[$i]");
                }
            }
        }
    }

    /** The closed `block_attrs`/`shortcode_attrs` `type` vocabulary. */
    private const ATTR_VALUE_TYPES = ['int', 'int[]'];
    /** The closed attribute `tokenize` codec vocabulary (the home/uploads URL pass). */
    private const ATTR_TOKENIZE_CODECS = ['text'];

    /** One `block_attrs`/`shortcode_attrs` entry. @see validate_attr_rules() */
    private static function validate_attr_rule(mixed $rule, string $section, string $where): void {
        if (!is_array($rule) || (array_is_list($rule) && $rule !== [])) {
            throw new \RuntimeException("duo: $where must be an object");
        }
        if (!is_string($rule['path'] ?? null) || $rule['path'] === '') {
            throw new \RuntimeException(
                "duo: $where.path must be a non-empty attribute name — an unmatched path is silently skipped at "
                . 'rewrite time, so a declared ref would never actually be tokenized'
            );
        }
        if (array_key_exists('lint_ok', $rule) && !is_bool($rule['lint_ok'])) {
            throw new \RuntimeException("duo: $where.lint_ok must be a boolean");
        }
        if (array_key_exists('type', $rule) && !in_array($rule['type'], self::ATTR_VALUE_TYPES, true)) {
            throw new \RuntimeException(
                "duo: $where.type=" . var_export($rule['type'], true) . ' but the attribute-value vocabulary is '
                . 'closed and engine-owned (int, int[]); an id-bearing attribute is either one id or a native '
                . 'list of them, and any other shape needs engine support before it can be declared'
            );
        }
        if (array_key_exists('cast', $rule) && !in_array($rule['cast'], self::CASTS, true)) {
            throw new \RuntimeException(
                "duo: $where.cast=" . var_export($rule['cast'], true) . ' but only '
                . implode('|', self::CASTS) . ' are supported'
            );
        }
        if (array_key_exists('tokenize', $rule) && !in_array($rule['tokenize'], self::ATTR_TOKENIZE_CODECS, true)) {
            throw new \RuntimeException(
                "duo: $where.tokenize=" . var_export($rule['tokenize'], true)
                . " but the only supported codec for an attribute is \"text\" (the ordinary home/uploads URL pass)"
            );
        }
        if (array_key_exists('kind_from', $rule)) {
            $from = $rule['kind_from'];
            if (!is_array($from) || !is_string($from['attr'] ?? null) || ($from['attr'] ?? '') === ''
                || !is_array($from['map'] ?? null) || ($from['map'] ?? []) === []) {
                throw new \RuntimeException(
                    "duo: $where.kind_from must declare a non-empty sibling `attr` and a non-empty `map` of that "
                    . "attribute's values to ref kinds"
                );
            }
            if (array_key_exists('kind', $rule)) {
                throw new \RuntimeException(
                    "duo: $where declares BOTH kind and kind_from — a rule's ref kind is either static or "
                    . 'dispatched from a sibling attribute, never both'
                );
            }
        }
        $dispositions = array_filter([
            'lint_ok' => !empty($rule['lint_ok']),
            'tokenize' => array_key_exists('tokenize', $rule),
            'kind' => array_key_exists('kind', $rule),
            'kind_from' => array_key_exists('kind_from', $rule),
        ]);
        if ($dispositions === []) {
            throw new \RuntimeException(
                "duo: $where declares none of kind, kind_from, tokenize, or lint_ok — every "
                . ($section === 'block_attrs' ? 'block' : 'shortcode') . ' attribute rule must say what the '
                . 'engine should do with the value it names; a rule with no disposition is refused here rather '
                . 'than reaching its throw mid-capture, on whichever entity happened to carry it first'
            );
        }
    }

    /**
     * Loud, load-time guard for the widget registry (DUO-3318).
     *
     * SidebarState::assert_declared_types() has always checked this shape,
     * but only once a sidebar is actually captured or applied, which needs a
     * live WordPress. The grammar half is a pure function of the manifest, so
     * it belongs at load time where every command pays for it and an offline
     * validation can reach it — same split, and the same rationale, as the
     * table grammar above. SidebarState keeps the one check that is genuinely
     * its own (the id_kind column budget its derived kind name has to fit).
     *
     * `codec`/`ref` stay deliberately narrow: 'blocks' is the only settings
     * codec the engine implements, and 'term' the only ref kind a core widget
     * setting has ever carried. Both are engine-owned — a widget setting's
     * value passes through engine codecs, not adapter code — so widening
     * either is an engine change with a spec bump, not a manifest
     * declaration.
     */
    private static function validate_widgets(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        $widgets = $manifest['widgets'] ?? [];
        if (!is_array($widgets) || (array_is_list($widgets) && $widgets !== [])) {
            throw new \RuntimeException("duo: manifest '$name' widgets must be an object keyed by widget type");
        }
        foreach ($widgets as $type => $decl) {
            self::assert_widget_grammar((string) $type, $decl, "manifest '$name'");
        }
    }

    /**
     * The pure-grammar half of ONE `widgets.<type>` declaration — the exact
     * mirror of assert_table_grammar() above, and for the same reason
     * (DUO-3318 review, S4).
     *
     * This is the only implementation of these rules: validate_widgets() runs
     * it for every declared type at load, and SidebarState::assert_policy()
     * runs it again immediately before its own genuinely-live work (re-checking
     * a pure function of already-loaded bytes costs nothing, and keeps a
     * directly-constructed Policy — the shape several offline harnesses build —
     * covered by the same rules). SidebarState keeps exactly one check of its
     * own, the one that is genuinely its own: the duo_map.id_kind width budget
     * its DERIVED `widget_<type>` kind has to fit, which is Ledger's schema
     * rather than the manifest's grammar.
     *
     * The two copies used to disagree in three places, all of them the same
     * direction — SidebarState accepted what a load-time check refused, so a
     * declaration could pass sidebar capture and still fail the next `Policy::
     * load()`: an EMPTY settings map, a settings LIST rather than an object,
     * and `"codec": null`/`"ref": null` (isset() reads a declared null as
     * absent). The stricter reading is the correct one in all three: a widget
     * whose allowlist names no field can never capture an instance, and an
     * explicitly-null codec is a declaration the author meant to write.
     *
     * $source names the declaring manifest when one is known; it is prefixed
     * to the existing "widgets.<type>…" wordings rather than interpolated into
     * them, so the live caller (which has no manifest name to report) still
     * gets a complete sentence.
          * A JSON key "0" decodes to an int PHP key; the declared
     * ^[a-z0-9_-]+$ rule legally admits it after string coercion, so a
     * mixed int/string key map loads — a syntactically legal id_base,
     * not a validation gap.
     */
    /** The closed `widgets.<t>.settings.<s>.codec` vocabulary. @see assert_widget_grammar() */
    private const WIDGET_SETTING_CODECS = ['blocks'];
    /** The closed `widgets.<t>.settings.<s>.ref` vocabulary. @see assert_widget_grammar() */
    private const WIDGET_SETTING_REFS = ['term'];

    public static function assert_widget_grammar(string $type, mixed $decl, ?string $source = null): void {
        $where = ($source === null ? '' : "$source ") . "widgets.$type";
        if (!preg_match('/^[a-z0-9_-]+$/', $type)) {
            throw new \RuntimeException(
                "duo: $where names an invalid widget type — a type is WordPress's own id_base "
                . '(the widget_<type> option name), matching ^[a-z0-9_-]+$'
            );
        }
        $settings = is_array($decl) ? ($decl['settings'] ?? null) : null;
        if (!is_array($settings) || $settings === [] || array_is_list($settings)) {
            throw new \RuntimeException(
                "duo: $where must declare a non-empty `settings` object — capture refuses any live setting "
                . 'this map does not name, so an absent map makes every instance of the type uncapturable'
            );
        }
        foreach ($settings as $setting => $rule) {
            if (!is_string($setting) || $setting === '' || !is_array($rule)
                || ($rule['class'] ?? null) !== 'authored') {
                throw new \RuntimeException(
                    "duo: $where.settings.$setting must declare class=authored — a widget settings map is an "
                    . 'allowlist of portable fields, so a non-authored entry has nothing to mean (leave the '
                    . 'field out to exclude it)'
                );
            }
            if (array_key_exists('codec', $rule) && !in_array($rule['codec'], self::WIDGET_SETTING_CODECS, true)) {
                throw new \RuntimeException(
                    "duo: $where.settings.$setting declares codec=" . var_export($rule['codec'], true)
                    . ' but the widget settings codec vocabulary is closed and engine-owned (blocks)'
                );
            }
            if (array_key_exists('ref', $rule) && !in_array($rule['ref'], self::WIDGET_SETTING_REFS, true)) {
                throw new \RuntimeException(
                    "duo: $where.settings.$setting declares ref=" . var_export($rule['ref'], true)
                    . ' but the widget settings ref vocabulary is closed and engine-owned (term)'
                );
            }
            if (array_key_exists('codec', $rule) && array_key_exists('ref', $rule)) {
                throw new \RuntimeException(
                    "duo: $where.settings.$setting cannot declare codec and ref — a setting value is either a "
                    . 'structured document the engine decodes or a single entity reference it resolves'
                );
            }
        }
    }

    /** Validate option-name reference patterns before capture/apply uses them. */
    private static function validate_option_name_refs(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        $rules = $manifest['option_name_refs'] ?? [];
        if (!is_array($rules) || !array_is_list($rules)) {
            throw new \RuntimeException("duo: manifest '$name' option_name_refs must be a list");
        }
        foreach ($rules as $i => $rule) {
            if (!is_array($rule)
                || !in_array($rule['class'] ?? null, self::CLASSES, true)
                || !is_string($rule['id_kind'] ?? null)
                || !preg_match('/^[a-z][a-z0-9_]*$/', (string) $rule['id_kind'])
                || !is_string($rule['match'] ?? null)
                || (string) $rule['match'] === ''
                || @preg_match('/' . $rule['match'] . '/', '') === false) {
                throw new \RuntimeException(
                    "duo: manifest '$name' option_name_refs[$i] must declare class, id_kind, and a valid match regex"
                );
            }
            if (substr_count((string) $rule['match'], '(?<id>') !== 1) {
                throw new \RuntimeException(
                    "duo: manifest '$name' option_name_refs[$i].match must contain exactly one named (?<id>...) capture"
                );
            }
            if (array_key_exists('malformed_match', $rule)
                && (!is_string($rule['malformed_match'])
                    || $rule['malformed_match'] === ''
                    || @preg_match('/' . $rule['malformed_match'] . '/', '') === false)) {
                throw new \RuntimeException(
                    "duo: manifest '$name' option_name_refs[$i].malformed_match must be a non-empty valid regex"
                );
            }
        }
    }

    /**
     * Identical option-name-ref regexes are unconditionally ambiguous.  The
     * full regex-intersection problem is not decidable in this grammar, so
     * runtime consumers also use option_name_ref_match_details() and reject
     * every concrete live/canonical name matched by multiple declarations.
     */
    private static function validate_no_overlapping_option_name_refs(array $manifests): void {
        $seen = [];
        foreach ($manifests as $manifest) {
            foreach ($manifest['option_name_refs'] ?? [] as $index => $rule) {
                $pattern = (string) ($rule['match'] ?? '');
                if ($pattern === '') {
                    continue;
                }
                if (isset($seen[$pattern])) {
                    $prior = $seen[$pattern];
                    throw new \RuntimeException(
                        "duo: option_name_refs rules '{$prior['manifest']}[{$prior['index']}]' and "
                        . "'" . (string) ($manifest['name'] ?? '?') . "[$index]' have identical overlapping match regexes"
                    );
                }
                $seen[$pattern] = [
                    'manifest' => (string) ($manifest['name'] ?? '?'),
                    'index' => (int) $index,
                ];
            }
        }
    }

    /** Validate the journal-independent discovery vocabulary at load time. */
    private static function validate_discovery_contract(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        $namespaces = $manifest['option_namespaces'] ?? [];
        if (!is_array($namespaces)) {
            throw new \RuntimeException("duo: manifest '$name' option_namespaces must be an array");
        }
        foreach ($namespaces as $i => $decl) {
            $match = is_array($decl) ? ($decl['match'] ?? null) : null;
            if (!is_string($match) || $match === '' || @preg_match('/' . $match . '/', '') === false) {
                throw new \RuntimeException(
                    "duo: manifest '$name' option_namespaces[$i].match must be a non-empty valid regex"
                );
            }
        }

        foreach ($manifest['tables'] ?? [] as $table => $decl) {
            if (($decl['class'] ?? '') !== 'authored_snapshot_meta' || !isset($decl['keyspace'])) {
                continue;
            }
            $keyspace = $decl['keyspace'];
            $range = is_array($keyspace) ? ($keyspace['version_range'] ?? null) : null;
            $min = is_array($range) ? ($range['min'] ?? null) : null;
            $max = is_array($range) ? ($range['max'] ?? null) : null;
            if (!is_string($min) || $min === '' || !is_string($max) || $max === ''
                || version_compare($min, $max, '>=')) {
                throw new \RuntimeException(
                    "duo: manifest '$name' table '$table' keyspace needs version_range {min,max} with min < max"
                );
            }
            $keys = $keyspace['keys'] ?? [];
            $patterns = $keyspace['patterns'] ?? [];
            if (!is_array($keys) || !is_array($patterns)) {
                throw new \RuntimeException(
                    "duo: manifest '$name' table '$table' keyspace keys/patterns must be arrays"
                );
            }
            foreach ($keys as $key) {
                if (!is_string($key) || $key === '') {
                    throw new \RuntimeException(
                        "duo: manifest '$name' table '$table' keyspace.keys must contain non-empty strings"
                    );
                }
            }
            foreach ($patterns as $i => $pattern) {
                $match = is_array($pattern) ? ($pattern['match'] ?? null) : null;
                if (!is_string($match) || $match === '' || @preg_match('/' . $match . '/', '') === false) {
                    throw new \RuntimeException(
                        "duo: manifest '$name' table '$table' keyspace.patterns[$i].match must be a valid regex"
                    );
                }
            }
        }
    }

    /**
     * Validate a CPT parent/child declaration before any query can use it.
     *
     * `post_types.<parent>.children` is deliberately a very small grammar:
     * a non-empty list of distinct CPT names.  It describes only direct
     * wp_posts.post_parent edges; it is not a cascade grammar, SQL surface,
     * or a generic hierarchy-discovery escape hatch. Every child endpoint
     * must be another post_types key in this same manifest, so a typo cannot
     * reach a target query; runtime checks still prove the local rows.
     */
    private static function validate_post_type_children(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        $postTypes = (array) ($manifest['post_types'] ?? []);
        foreach ($postTypes as $parentPostType => $decl) {
            if (!is_array($decl) || !array_key_exists('children', $decl)) {
                continue;
            }
            if (!is_string($parentPostType)
                || !preg_match('/^[a-z0-9_-]{1,20}$/', $parentPostType)) {
                throw new \RuntimeException(
                    "duo: manifest '$name' post_types key " . var_export($parentPostType, true)
                    . ' cannot declare children: expected a WordPress post-type name'
                );
            }
            $children = $decl['children'];
            if (!is_array($children) || !array_is_list($children) || !$children) {
                throw new \RuntimeException(
                    "duo: manifest '$name' post_types.$parentPostType.children must be a non-empty list"
                );
            }
            $seen = [];
            foreach ($children as $index => $childPostType) {
                if (!is_string($childPostType)
                    || !preg_match('/^[a-z0-9_-]{1,20}$/', $childPostType)) {
                    throw new \RuntimeException(
                        "duo: manifest '$name' post_types.$parentPostType.children[$index] "
                        . 'must be a WordPress post-type name'
                    );
                }
                if ($childPostType === $parentPostType) {
                    throw new \RuntimeException(
                        "duo: manifest '$name' post_types.$parentPostType.children "
                        . 'cannot declare a CPT as its own child'
                    );
                }
                if (!array_key_exists($childPostType, $postTypes)) {
                    throw new \RuntimeException(
                        "duo: manifest '$name' post_types.$parentPostType.children[$index] "
                        . "names undeclared child CPT '$childPostType'"
                    );
                }
                if (isset($seen[$childPostType])) {
                    throw new \RuntimeException(
                        "duo: manifest '$name' post_types.$parentPostType.children "
                        . "contains duplicate child CPT '$childPostType'"
                    );
                }
                $seen[$childPostType] = true;
            }
        }
    }

    /**
     * Validate `post_types.<type>.regen_dependency` shape at load time
     * (DUO-3234) — same "catch a bad declaration before it reaches a lookup
     * call site" posture as validate_field_classes() immediately above,
     * mirrored for its own key shape rather than extended, for the same
     * reason regenerators() doesn't share code with interpreters(). Checks
     * SHAPE only (required keys present, correct scalar types, and a strict
     * top-level key set) — same as validate_field_classes() never touches
     * interpreter files, this never touches the regenerator PHP file or
     * class; that stays regenerators()'s lazy-load-on-first-use job, so a
     * manifest pinning a regen_dependency declaration it never actually
     * exercises this run pays no file-system cost merely for being loaded.
     * The optional `effects` list is deliberately admitted here, but its
     * entries remain solely the responsibility of validate_effect_contracts()
     * below; keeping those schemas in one validator prevents two subtly
     * different effect grammars from drifting apart.
     */
    private static function validate_regen_dependencies(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        foreach ($manifest['post_types'] ?? [] as $postType => $decl) {
            $regen = $decl['regen_dependency'] ?? null;
            if ($regen === null) {
                continue;
            }
            if (!is_array($regen) || array_is_list($regen)) {
                throw new \RuntimeException(
                    "duo: manifest '$name' post_types.$postType.regen_dependency must be an object"
                );
            }
            $regenerator = $regen['regenerator'] ?? null;
            if (!is_string($regenerator) || $regenerator === '') {
                throw new \RuntimeException(
                    "duo: manifest '$name' post_types.$postType.regen_dependency needs a non-empty string 'regenerator'"
                );
            }
            $verify = $regen['verify'] ?? null;
            if (!is_array($verify) || !is_string($verify['table'] ?? null) || ($verify['table'] ?? '') === ''
                || !is_string($verify['column'] ?? null) || ($verify['column'] ?? '') === '') {
                throw new \RuntimeException(
                    "duo: manifest '$name' post_types.$postType.regen_dependency needs "
                    . "verify: {table: <non-empty string>, column: <non-empty string>}"
                );
            }

            $unknown = array_diff(
                array_keys($regen),
                ['regenerator', 'verify', 'batch', 'refresh', 'always_on_write', 'effects']
            );
            if ($unknown) {
                throw new \RuntimeException(
                    "duo: manifest '$name' post_types.$postType.regen_dependency contains unknown key(s): "
                    . implode(', ', $unknown)
                );
            }
            if (array_key_exists('batch', $regen) && array_key_exists('refresh', $regen)) {
                throw new \RuntimeException(
                    "duo: manifest '$name' post_types.$postType.regen_dependency cannot declare both 'batch' and 'refresh'"
                );
            }
            if (array_key_exists('always_on_write', $regen)
                && (array_key_exists('batch', $regen) || array_key_exists('refresh', $regen))) {
                throw new \RuntimeException(
                    "duo: manifest '$name' post_types.$postType.regen_dependency.always_on_write is ambiguous beside batch/refresh"
                );
            }

            // DUO-329x: batch/refresh is deliberately opt-in.  Existing
            // declarations (TEC included) retain the missing-row,
            // regenerate(int) behavior above.  Accept both names as a small
            // compatibility affordance for manifest authors: "refresh"
            // describes the always-on-write intent, while "batch" names the
            // callable boundary.  The engine normalizes either spelling via
            // regen_batch().
            foreach (['batch', 'refresh'] as $batchKey) {
                if (!array_key_exists($batchKey, $regen)) {
                    continue;
                }
                $batch = $regen[$batchKey];
                if ($batch !== true && $batch !== false && !is_array($batch)) {
                    throw new \RuntimeException(
                        "duo: manifest '$name' post_types.$postType.regen_dependency.$batchKey must be "
                        . 'a boolean or object'
                    );
                }
                if (is_array($batch)) {
                    $unknownBatch = array_diff(array_keys($batch), ['enabled', 'always_on_write']);
                    if ($unknownBatch) {
                        throw new \RuntimeException(
                            "duo: manifest '$name' post_types.$postType.regen_dependency.$batchKey contains unknown key(s): "
                            . implode(', ', $unknownBatch)
                        );
                    }
                    foreach (['enabled', 'always_on_write'] as $flag) {
                        if (array_key_exists($flag, $batch) && !is_bool($batch[$flag])) {
                            throw new \RuntimeException(
                                "duo: manifest '$name' post_types.$postType.regen_dependency.$batchKey.$flag must be boolean"
                            );
                        }
                    }
                }
            }
            if (array_key_exists('always_on_write', $regen) && !is_bool($regen['always_on_write'])) {
                throw new \RuntimeException(
                    "duo: manifest '$name' post_types.$postType.regen_dependency.always_on_write must be boolean"
                );
            }
        }
    }

    /**
     * Validate every top-level `options.<name>` rule classified `env` at
     * load time (DUO-3232): `required` (bool) is MANDATORY, no silent
     * default either way — same posture DUO-3229 already established for
     * post_type/taxonomy scope ("every entity gets an audited decision,
     * neither noisy-by-default nor silent-by-default"), applied here to
     * env rules. A manifest declaring `class: "env"` with no `required`
     * key refuses to load, naming the exact manifest and key, so every
     * env-classified option is a deliberate author decision (worth
     * checklisting via env_options()/env_missing, or plugin-internal
     * bookkeeping that self-populates and isn't) rather than an implicit
     * one a future maintainer has to reverse-engineer from silence.
     *
     * Deliberately narrow, matching env_options()'s own scope: only
     * top-level `options.<name>.class === "env"` rules. A `sub_keys`
     * entry's OWN class (DUO-3233's per-sub-key carve-out) is out of
     * v2 scope for the identical reason post_meta/term_meta env values
     * are (see env_options()'s docblock) — no shipped manifest declares
     * one today (confirmed empirically, not assumed), so this is a named
     * scope cut, not an oversight.
     */
    private static function validate_env_options(array $source, string $label): void {
        foreach ((array) ($source['options'] ?? []) as $name => $rule) {
            if (!is_array($rule) || ($rule['class'] ?? '') !== 'env') {
                continue;
            }
            if (!array_key_exists('required', $rule) || !is_bool($rule['required'])) {
                throw new \RuntimeException(
                    "duo: $label options.$name.class=\"env\" needs an explicit boolean 'required' "
                    . '(true: an operator must provision this value on a fresh environment — a genuine '
                    . 'secret or site-identity value; false: plugin-internal bookkeeping that '
                    . 'self-populates and is not worth checklisting) — no silent default either way'
                );
            }
        }
    }
    /** Validate the user-meta-only safety vocabulary at policy load time. */
    private static function validate_user_meta_rules(array $source, string $label): void {
        foreach ((array) ($source['user_meta'] ?? []) as $key => $rule) {
            if (!is_array($rule)) {
                throw new \RuntimeException("duo: $label user_meta.$key must be a rule object");
            }
            self::validate_user_meta_rule($rule, "$label user_meta.$key");
        }
    }

    /** Interpreter-returned rules pass through this same check at lookup. */
    /**
     * The closed `user_meta.<key>.missing_user` vocabulary: whether an authored
     * user-meta row whose owning user is absent on the target blocks the apply
     * or degrades to a warning. Engine-owned — each value binds apply to a
     * different refusal posture.
     */
    private const MISSING_USER_MODES = ['block', 'warn'];

    private static function validate_user_meta_rule(array $rule, string $where): void {
        $class = $rule['class'] ?? null;
        if (!in_array($class, self::CLASSES, true)) {
            throw new \RuntimeException(
                "duo: $where has an invalid or missing class (expected " . implode('|', self::CLASSES) . ')'
            );
        }
        if (isset($rule['allow_pii']) && !is_bool($rule['allow_pii'])) {
            throw new \RuntimeException("duo: $where allow_pii must be a boolean");
        }
        if (isset($rule['allow_secret']) && !is_bool($rule['allow_secret'])) {
            throw new \RuntimeException("duo: $where allow_secret must be a boolean");
        }
        if ($class !== 'authored' && (!empty($rule['allow_pii']) || !empty($rule['allow_secret']))) {
            throw new \RuntimeException(
                "duo: $where PII/secret capture exceptions are valid only for class=authored"
            );
        }
        if (isset($rule['missing_user'])) {
            if ($class !== 'authored') {
                throw new \RuntimeException("duo: $where missing_user is valid only for class=authored");
            }
            if (!in_array($rule['missing_user'], self::MISSING_USER_MODES, true)) {
                throw new \RuntimeException("duo: $where missing_user must be block or warn");
            }
        }
    }

    /**
     * Option names classified `env`, keyed by name, value = the full rule
     * (including the mandatory `required` flag validate_env_options()
     * already guaranteed is present and boolean). Sibling enumerator to
     * authored_options() above, same merge precedence (site policy
     * replaces a manifest's whole rule wholesale, never a deep merge).
     *
     * DUO-3232: the enumeration half of "env-bound value provisioning" —
     * feeds Apply::build_plan()'s env_missing bucket (which entity of
     * this list actually looks unset on THIS environment) and, indirectly
     * via `wp duo env-set`'s own lookup, the write-time guard that refuses
     * to write to any option name NOT in this map (never an arbitrary
     * option, only a manifest-declared env-classified one).
     *
     * Scope: OPTIONS ONLY for v2, deliberately. post_meta/term_meta
     * classification can be interpreter-driven (Policy::meta_rule_for_post()
     * dispatches to schema-driven code reading a SPECIFIC post's whole
     * meta map — Policy.php's own interpreter contract docblock at the top
     * of this file), so "enumerate every env-classified meta key globally"
     * has no well-defined answer without live per-post data a fresh target
     * doesn't have yet. Every real env-classified value across every
     * shipped manifest today is an option (verified empirically, not
     * assumed) — a genuine env-classified meta key, if one ever surfaces,
     * is a separate, scoped follow-up, not solved speculatively here.
     */
    public function env_options(): array {
        $out = [];
        foreach ($this->resolved_exact_options() as $name => $r) {
            if (($r['class'] ?? '') === 'env') {
                $out[$name] = $r;
            }
        }
        return $out;
    }

    /**
     * DUO-3249: every core-manifest OPTION name where a pinned, NON-core
     * manifest's own declaration outranks core's per rule_details()'s
     * core-yields-to-plugin precedence AND actually resolves to a
     * DIFFERENT class — the loud, plan-visible half of that precedence fix
     * (owner ruling, issue comment 8e6d4aeb: "Plan emits a note whenever a
     * reclassification override is active — loud, never silent").
     * Apply::build_plan() turns each entry into a plain plan warning.
     *
     * Deliberately narrow, matching the ruling's own scope: options only
     * (mirrors env_options()'s identical "options only for v2" cut — no
     * shipped manifest reclassifies a core post_meta/term_meta/table key
     * today), and core-vs-PLUGIN-MANIFEST only — a site.duo.json override
     * of a core option is the operator's own explicit, already-visible
     * choice (it's sitting in a file they wrote), not a silent manifest-
     * pinning side effect, so it does not need this same loud treatment
     * and is excluded here on purpose, not by oversight. This says nothing
     * about, and does not resolve, the SEPARATE question of two non-core
     * manifests declaring the same option name (DUO-3255) — that
     * collision (if it exists in this policy at all) does not surface
     * here regardless of which of the two manifests rule_details() picks.
     *
     * @return list<array{name:string, core_class:?string, active_class:?string, overridden_by:string}>
     */
    public function active_reclassifications(): array {
        $core = null;
        foreach ($this->manifests as $m) {
            if (($m['name'] ?? '') === 'core') {
                $core = $m;
                break;
            }
        }
        if ($core === null) {
            return [];
        }
        $out = [];
        foreach ($core['options'] ?? [] as $name => $coreRule) {
            $winner = $this->option_rule_details($name);
            $source = $winner['source'] ?? null;
            if ($source === null || $source === 'core' || $source === 'site.duo.json') {
                continue;
            }
            $activeClass = $winner['rule']['class'] ?? null;
            $coreClass = $coreRule['class'] ?? null;
            if ($activeClass === $coreClass) {
                continue; // same-name declaration in both, but not actually a DIFFERENT classification -- nothing to warn about
            }
            $out[] = [
                'name' => (string) $name,
                'core_class' => $coreClass,
                'active_class' => $activeClass,
                'overridden_by' => $source,
            ];
        }
        return $out;
    }

    /**
     * DUO-3272's own version of active_reclassifications() immediately
     * above — same purpose (the loud, plan-visible half of the DUO-3249
     * core-yields-to-plugin precedence, this time for menu_fields instead
     * of options), kept as a separate function rather than a generalized
     * shared one for the same reason validate_menu_field_classes() stays
     * separate from validate_field_classes(): the two sections don't share
     * a manifest shape (menu_fields is flat; options is too, but the two
     * are semantically unrelated surfaces with their own core-declaration
     * sets), and Apply::build_plan() needs to report them as distinct,
     * clearly-labeled warnings rather than one merged list a reader has to
     * disambiguate by field name alone.
     *
     * @return list<array{name:string, core_class:?string, active_class:?string, overridden_by:string}>
     */
    public function active_menu_field_reclassifications(): array {
        $core = null;
        foreach ($this->manifests as $m) {
            if (($m['name'] ?? '') === 'core') {
                $core = $m;
                break;
            }
        }
        if ($core === null) {
            return [];
        }
        $out = [];
        foreach ($core['menu_fields'] ?? [] as $name => $coreRule) {
            $winner = $this->menu_field_rule_details($name);
            $source = $winner['source'] ?? null;
            if ($source === null || $source === 'core' || $source === 'site.duo.json') {
                continue;
            }
            $activeClass = $winner['rule']['class'] ?? null;
            $coreClass = $coreRule['class'] ?? null;
            if ($activeClass === $coreClass) {
                continue; // same-name declaration in both, but not actually a DIFFERENT classification -- nothing to warn about
            }
            $out[] = [
                'name' => (string) $name,
                'core_class' => $coreClass,
                'active_class' => $activeClass,
                'overridden_by' => $source,
            ];
        }
        return $out;
    }

    /** Validate whole-entity scope dispositions at policy load time. Site
     * rules live under policy.scope.{post_type,taxonomy}; manifests reuse
     * their existing post_types/taxonomies declarations. Invalid scope
     * input must fail every consumer, never turn into an implicit include
     * or exclusion. */
    private static function validate_scope_classes(array $source, string $label, bool $site): void {
        $groups = $site
            ? ($source['policy']['scope'] ?? [])
            : ['post_type' => $source['post_types'] ?? [], 'taxonomy' => $source['taxonomies'] ?? []];
        foreach (['post_type', 'taxonomy'] as $kind) {
            foreach ($groups[$kind] ?? [] as $name => $rule) {
                if (!is_string($name) || $name === '' || !is_array($rule)) {
                    throw new \RuntimeException("duo: $label has an invalid scope.$kind declaration");
                }
                $class = $rule['class'] ?? ($site ? null : 'authored');
                if (!in_array($class, self::SCOPE_CLASSES, true)) {
                    throw new \RuntimeException(
                        "duo: $label scope.$kind.$name.class=" . var_export($class, true)
                        . ' (expected ' . implode('|', self::SCOPE_CLASSES) . ')'
                    );
                }
            }
        }
    }

    /**
     * Loud, load-time guard for sub_keyed_options()'s manifest input (same
     * "throw immediately, never degrade silently" posture as
     * validate_field_classes() above — a bad sub_keys declaration must fail
     * every command that loads this manifest, not surface as a confusing
     * runtime shape error deep inside Capture/Apply). Two invariants:
     *   - sub_keys, when present, is a non-empty object of NAME => rule,
     *     and every named rule declares a recognized class;
     *   - class=authored and sub_keys are mutually exclusive on the SAME
     *     option rule: class=authored already captures the WHOLE value
     *     (authored_options()), so a manifest declaring both is stating two
     *     contradictory capture strategies for the same option name;
     *   - whole-value codec/safety fields are likewise mutually exclusive
     *     with sub_keys. Every capture/apply/lint/compiler consumer delegates
     *     value semantics to the named sub-key rules once sub_keys exists, so
     *     accepting one of those fields on the parent would silently ignore a
     *     declaration rather than establish a second ownership layer.
     *
     * These are the kind of ambiguous manifest states this project's posture
     * (DESIGN.md 3.1.5, "loud-and-blocking default") requires rejecting
     * outright rather than silently picking one.
     */
    private static function validate_sub_keys(array $source, string $label): void {
        foreach ($source['options'] ?? [] as $optName => $rule) {
            $subKeys = $rule['sub_keys'] ?? null;
            if ($subKeys === null) {
                continue;
            }
            if (!is_array($subKeys) || !$subKeys) {
                throw new \RuntimeException(
                    "duo: $label declares options.$optName.sub_keys but it is not a non-empty object"
                );
            }
            if (($rule['class'] ?? '') === 'authored') {
                throw new \RuntimeException(
                    "duo: $label declares options.$optName with BOTH class=authored and sub_keys — "
                    . 'these are mutually exclusive (class=authored already captures the WHOLE value; sub_keys '
                    . 'narrows independent capture to named keys of an otherwise-excluded blob). Pick one.'
                );
            }
            self::assert_sub_key_parent_has_no_value_fields(
                $rule,
                "$label options.$optName"
            );
            foreach ($subKeys as $subKey => $subRule) {
                if (!is_array($subRule) || !in_array($subRule['class'] ?? null, self::CLASSES, true)) {
                    throw new \RuntimeException(
                        "duo: $label declares options.$optName.sub_keys.$subKey with an invalid or "
                        . 'missing class (expected one of ' . implode('|', self::CLASSES) . ')'
                    );
                }
            }
        }
    }

    private const SUB_KEY_PARENT_VALUE_FIELDS = [
        'ref',
        'json_refs',
        'key_refs',
        'json_encoded',
        'cast',
        'order_preserving',
        'allow_secret',
        'lint_ok',
    ];

    private static function assert_sub_key_parent_has_no_value_fields(array $rule, string $where): void {
        $ambiguous = array_values(array_filter(
            self::SUB_KEY_PARENT_VALUE_FIELDS,
            static fn(string $field): bool => array_key_exists($field, $rule)
        ));
        if ($ambiguous) {
            throw new \RuntimeException(
                "duo: $where declares sub_keys together with whole-value field(s) "
                . implode(', ', $ambiguous) . '; put value/reference/secret/lint behavior on each named '
                . 'sub-key rule instead'
            );
        }
    }

    /**
     * Loud, load-time guard for object_type_option_ref()'s manifest input
     * (DUO-3280) — same posture as validate_sub_keys() immediately above: a
     * malformed declaration must fail every command that loads this
     * manifest, not surface as a confusing null-vs-array shape error deep
     * inside Apply/Capture's taxes_by_object_type(). Two invariants:
     *   - object_type_from_option, when present, is an object naming a
     *     non-empty string `option` and a non-empty string `sub_key`;
     *   - when the SAME manifest also declares options.<option>.sub_keys
     *     (as polylang.json does for `polylang`), `sub_key` must actually
     *     be one of its named keys — catches a typo'd cross-reference
     *     between the two declarations at load time rather than a silent
     *     always-empty supplement at apply time. Only checked when both
     *     declarations live in the same manifest file; a declaration
     *     pointing at an option some OTHER manifest or site policy owns is
     *     not flagged (no reasonable single-manifest validator can see
     *     across manifests, matching validate_sub_keys()'s own scope);
     *   - that named sub-key rule declares no json_refs/key_refs.
     *     Apply::option_driven_object_type() deliberately reads the
     *     compiled tree's raw captured value with no ref-resolution step
     *     (object types are plugin/taxonomy-registration slugs, never
     *     environment-local ids — there is no plausible ref-typed use of
     *     this primitive) — a manifest declaring one anyway would silently
     *     get UNRESOLVED token strings (e.g. "{{term:<uuid>}}") fed
     *     straight into object_type, a confusing failure far from its
     *     cause; refusing it at load time is cheaper than debugging that.
     */
    private static function validate_object_type_option_refs(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        foreach ($manifest['taxonomies'] ?? [] as $tax => $rule) {
            $decl = $rule['object_type_from_option'] ?? null;
            if ($decl === null) {
                continue;
            }
            if (!is_array($decl)
                || !is_string($decl['option'] ?? null) || $decl['option'] === ''
                || !is_string($decl['sub_key'] ?? null) || $decl['sub_key'] === '') {
                throw new \RuntimeException(
                    "duo: manifest '$name' declares taxonomies.$tax.object_type_from_option without both a "
                    . 'non-empty string `option` and `sub_key`'
                );
            }
            $ownSubKeys = $manifest['options'][$decl['option']]['sub_keys'] ?? null;
            if ($ownSubKeys !== null) {
                $subRule = $ownSubKeys[$decl['sub_key']] ?? null;
                if ($subRule === null) {
                    throw new \RuntimeException(
                        "duo: manifest '$name' declares taxonomies.$tax.object_type_from_option.sub_key="
                        . var_export($decl['sub_key'], true) . " but options.{$decl['option']}.sub_keys never "
                        . 'declares that key'
                    );
                }
                if (!empty($subRule['json_refs']) || !empty($subRule['key_refs'])) {
                    throw new \RuntimeException(
                        "duo: manifest '$name' declares taxonomies.$tax.object_type_from_option pointing at "
                        . "options.{$decl['option']}.sub_keys.{$decl['sub_key']}, but that sub-key declares "
                        . 'json_refs/key_refs — object_type_from_option only supports plain, non-ref-typed '
                        . 'sub-key values (post-type/taxonomy slugs, never ids)'
                    );
                }
            }
        }
    }

    /**
     * `object_keyspace` is a structural taxonomy claim, not a convenient
     * runtime hint. Reject a malformed value while loading its manifest so
     * no capture/lint/apply path can silently reinterpret a relationship
     * row's shared numeric object_id later. Dynamic taxonomy patterns use
     * the same declaration, and their regex must be usable before a future
     * concrete taxonomy name reaches the resolver.
     */
    private static function validate_taxonomy_object_keyspace_declarations(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        foreach ((array) ($manifest['taxonomies'] ?? []) as $tax => $rule) {
            if (!is_array($rule) || !array_key_exists('object_keyspace', $rule)) {
                continue;
            }
            self::validate_taxonomy_object_keyspace_value(
                $rule['object_keyspace'],
                "manifest '$name' taxonomies.$tax.object_keyspace"
            );
        }

        if (!array_key_exists('taxonomy_patterns', $manifest)) {
            return;
        }
        $patterns = $manifest['taxonomy_patterns'];
        if (!is_array($patterns) || !array_is_list($patterns)) {
            throw new \RuntimeException("duo: manifest '$name' declares taxonomy_patterns that is not a list");
        }
        foreach ($patterns as $i => $pattern) {
            if (!is_array($pattern) || array_is_list($pattern)) {
                throw new \RuntimeException("duo: manifest '$name' declares taxonomy_patterns[$i] that is not an object");
            }
            $match = $pattern['match'] ?? null;
            if (!is_string($match) || $match === '' || @preg_match('/' . $match . '/', '') === false) {
                throw new \RuntimeException(
                    "duo: manifest '$name' declares taxonomy_patterns[$i].match with an invalid or empty regex"
                );
            }
            if (array_key_exists('object_keyspace', $pattern)) {
                self::validate_taxonomy_object_keyspace_value(
                    $pattern['object_keyspace'],
                    "manifest '$name' taxonomy_patterns[$i].object_keyspace"
                );
            }
        }
    }

    private static function validate_taxonomy_object_keyspace_value(mixed $value, string $where): void {
        if (!is_string($value) || !in_array($value, self::TAXONOMY_RELATIONSHIP_OBJECTS, true)) {
            throw new \RuntimeException(
                "duo: $where must be one of " . implode('|', self::TAXONOMY_RELATIONSHIP_OBJECTS)
                . '; no other relationship object keyspace is supported'
            );
        }
    }

    /**
     * v1-supported dynamic_options resolvers (DUO-3264, fork A) — a
     * manifest's `resolver` value must appear here, mirroring
     * MENU_DERIVABLE_FIELDS/DERIVABLE_FIELD_COLUMNS' own "start v1 scope tight"
     * posture elsewhere in this file. Deliberately just 'active_stylesheet':
     * the one proven case (theme_mods_<stylesheet>). A future resolver is
     * anticipated by the ruling's own wording but not invented ahead of a
     * second real, grounded need.
     */
    private const DYNAMIC_OPTION_RESOLVERS = ['active_stylesheet'];

    /**
     * Loud, load-time guard for dynamic_options' manifest input — DUO-3264's
     * own version of validate_sub_keys() immediately above, kept as its own
     * function rather than merged into it for the same "mirrored for its
     * own key shape rather than extended" reason validate_menu_field_classes()
     * documents for itself: dynamic_options is a flat, top-level manifest
     * key with a DIFFERENT declaration shape (prefix + resolver, no bare
     * class of its own), not a per-option-name sub_keys nesting. Reuses
     * sub_keys' own per-sub-key class validation rule (same self::CLASSES
     * set) since that inner shape genuinely is identical once you are past
     * the top-level prefix/resolver fields.
     */
    private static function validate_dynamic_options(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        foreach ($manifest['dynamic_options'] ?? [] as $key => $decl) {
            if (!is_array($decl)) {
                throw new \RuntimeException("duo: manifest '$name' declares dynamic_options.$key that is not an object");
            }
            $prefix = $decl['prefix'] ?? null;
            if (!is_string($prefix) || $prefix === '') {
                throw new \RuntimeException("duo: manifest '$name' declares dynamic_options.$key with a missing or empty 'prefix'");
            }
            $resolver = $decl['resolver'] ?? null;
            if (!in_array($resolver, self::DYNAMIC_OPTION_RESOLVERS, true)) {
                throw new \RuntimeException(
                    "duo: manifest '$name' declares dynamic_options.$key.resolver=" . var_export($resolver, true)
                    . ' but only ' . implode('|', self::DYNAMIC_OPTION_RESOLVERS) . ' is supported in v1'
                );
            }
            $subKeys = $decl['sub_keys'] ?? null;
            if (!is_array($subKeys) || !$subKeys) {
                throw new \RuntimeException("duo: manifest '$name' declares dynamic_options.$key.sub_keys that is missing, empty, or not an object");
            }
            self::assert_sub_key_parent_has_no_value_fields(
                $decl,
                "manifest '$name' dynamic_options.$key"
            );
            foreach ($subKeys as $subKey => $subRule) {
                if (!is_array($subRule) || !in_array($subRule['class'] ?? null, self::CLASSES, true)) {
                    throw new \RuntimeException(
                        "duo: manifest '$name' declares dynamic_options.$key.sub_keys.$subKey with an invalid or "
                        . 'missing class (expected one of ' . implode('|', self::CLASSES) . ')'
                    );
                }
            }
        }
    }

    /**
     * A portable authored option must say how its wp_options row is stored.
     * `preserve` authorizes capture of the source row's exact autoload flag;
     * a concrete value is a stronger adapter contract and capture refuses a
     * source row that disagrees. Omitting this declaration is never allowed:
     * insertion would otherwise fall back to WordPress/version-local policy.
     */
    /**
     * The one non-value autoload declaration: `preserve` authorizes replaying
     * the source row's own flag instead of naming a literal one. Closed and
     * engine-owned — every other spelling has to be a real storage value,
     * because insertion may never guess.
     */
    private const OPTION_AUTOLOAD_SENTINELS = ['preserve'];

    private static function validate_option_storage(array $source, string $label): void {
        $default = $source['option_autoload'] ?? null;
        $check = static function (array $rule, string $where) use ($label, $default): void {
            $hasAuthoredSubKey = false;
            foreach ((array) ($rule['sub_keys'] ?? []) as $subRule) {
                if (($subRule['class'] ?? null) === 'authored') {
                    $hasAuthoredSubKey = true;
                    break;
                }
            }
            if (!in_array($rule['class'] ?? null, ['authored', 'managed'], true) && !$hasAuthoredSubKey) {
                return;
            }
            $autoload = $rule['autoload'] ?? $default;
            if (!in_array($autoload, self::OPTION_AUTOLOAD_SENTINELS, true)
                && !in_array($autoload, OptionState::AUTOLOAD_VALUES, true)) {
                throw new \RuntimeException(
                    "duo: $label $where needs autoload=preserve or an explicit supported autoload value "
                    . '(' . implode('|', OptionState::AUTOLOAD_VALUES) . '); insertion may never guess'
                );
            }
        };
        foreach ((array) ($source['options'] ?? []) as $name => $rule) {
            if (is_array($rule)) {
                $check($rule, "options.$name");
            }
        }
        foreach ((array) ($source['option_patterns'] ?? []) as $i => $rule) {
            if (is_array($rule)) {
                $check($rule, "option_patterns[$i]");
            }
        }
        foreach ((array) ($source['option_name_refs'] ?? []) as $i => $rule) {
            if (is_array($rule)) {
                $check($rule, "option_name_refs[$i]");
            }
        }
        // DUO-3264: dynamic_options entries are sub_keys-shaped (no bare
        // top-level class of their own) — $check()'s existing
        // $hasAuthoredSubKey detection already handles that correctly,
        // reused as-is rather than duplicated.
        foreach ((array) ($source['dynamic_options'] ?? []) as $key => $rule) {
            if (is_array($rule)) {
                $check($rule, "dynamic_options.$key");
            }
        }
    }

    /** Apply a source-level default without mutating the loaded artifact. */
    private static function with_option_autoload(array $rule, array $source): array {
        if (!array_key_exists('autoload', $rule) && array_key_exists('option_autoload', $source)) {
            $rule['autoload'] = $source['option_autoload'];
        }
        return $rule;
    }

    /**
     * A relationship keyspace decides which independent id counter owns a
     * wp_term_relationships.object_id. Pin order cannot choose between two
     * different answers. Exact declarations are checked eagerly; identical
     * pattern regexes are checked eagerly too; and an exact taxonomy that
     * already matches a declared pattern is checked before any WordPress
     * read. Different, potentially-overlapping dynamic patterns are finally
     * checked by taxonomy_object_keyspace() when a concrete name is used.
     * Regex intersection is not safely decidable from arbitrary PCRE, while
     * resolving a concrete name is exact and happens before a query/mutation.
     *
     * @param list<array> $manifests
     */
    private static function validate_no_conflicting_taxonomy_object_keyspaces(array $manifests): void {
        /** @var array<string,array<string,string[]>> $exact taxonomy => keyspace => sources */
        $exact = [];
        /** @var list<array{match:string,value:string,object_type:string[],callback:?string,source:string}> $patterns */
        $patterns = [];
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '?');
            foreach ((array) ($manifest['taxonomies'] ?? []) as $tax => $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $value = array_key_exists('object_keyspace', $rule)
                    ? (string) $rule['object_keyspace']
                    : 'post';
                $source = array_key_exists('object_keyspace', $rule)
                    ? "manifest '$name' taxonomies.$tax.object_keyspace"
                    : "manifest '$name' taxonomies.$tax (legacy post default)";
                $exact[(string) $tax][$value][] = $source;
            }
            foreach ((array) ($manifest['taxonomy_patterns'] ?? []) as $i => $pattern) {
                if (!is_array($pattern)) {
                    continue;
                }
                $objectTypes = array_values(array_unique(array_map(
                    'strval',
                    (array) ($pattern['object_type'] ?? [])
                )));
                sort($objectTypes, SORT_STRING);
                $patterns[] = [
                    'match' => (string) $pattern['match'],
                    'value' => array_key_exists('object_keyspace', $pattern)
                        ? (string) $pattern['object_keyspace']
                        : 'post',
                    'object_type' => $objectTypes,
                    'callback' => isset($pattern['update_count_callback'])
                        ? (string) $pattern['update_count_callback']
                        : null,
                    'source' => "manifest '$name' taxonomy_patterns[$i]",
                ];
            }
        }

        foreach ($exact as $tax => $claims) {
            if (count($claims) > 1) {
                self::throw_conflicting_taxonomy_object_keyspace($tax, $claims);
            }
        }

        $patternClaims = [];
        foreach ($patterns as $pattern) {
            $patternClaims[$pattern['match']][$pattern['value']][] = $pattern['source'] . '.object_keyspace';
        }

        $contractsByRegex = [];
        foreach ($patterns as $pattern) {
            $contractsByRegex[$pattern['match']][] = $pattern;
        }
        foreach ($contractsByRegex as $match => $contracts) {
            $first = $contracts[0];
            foreach (array_slice($contracts, 1) as $candidate) {
                foreach (['object_type', 'callback'] as $field) {
                    if ($candidate[$field] != $first[$field]) {
                        throw new \RuntimeException(
                            "duo: taxonomy_patterns regex '$match' has conflicting $field declarations from "
                            . "{$first['source']} and {$candidate['source']} — pin order may not choose "
                            . 'dynamic taxonomy behavior'
                        );
                    }
                }
            }
        }
        foreach ($patternClaims as $match => $claims) {
            if (count($claims) > 1) {
                $rendered = [];
                foreach ($claims as $value => $sources) {
                    $rendered[] = "$value from " . implode(', ', $sources);
                }
                throw new \RuntimeException(
                    "duo: taxonomy_patterns regex '$match' has conflicting object_keyspace declarations ("
                    . implode('; ', $rendered) . ') — matching patterns must agree'
                );
            }
        }

        foreach ($exact as $tax => $claims) {
            $value = (string) array_key_first($claims);
            foreach ($patterns as $pattern) {
                if (self::taxonomy_pattern_matches($pattern['match'], $tax) && $pattern['value'] !== $value) {
                    throw new \RuntimeException(
                        "duo: taxonomy '$tax' has conflicting object_keyspace declarations: "
                        . implode(', ', $claims[$value]) . " says $value, but {$pattern['source']}.object_keyspace says "
                        . "{$pattern['value']} — exact and matching pattern declarations must agree"
                    );
                }
            }
        }
    }

    /**
     * A taxonomy description has one physical carrier and therefore one
     * structural-reference grammar. Pin order may not select between two
     * adapters that describe that same carrier differently. Identical
     * declarations remain shareable, including the legacy flat-map mode;
     * normalized comparison deliberately retains legacy_flat_map because it
     * controls the byte-compatible empty-map representation.
     *
     * @param list<array> $manifests
     */
    private static function validate_no_conflicting_description_reference_rules(array $manifests): void {
        /** @var array<string,array{rule:array,source:string}> $claims */
        $claims = [];
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '?');
            foreach ((array) ($manifest['taxonomies'] ?? []) as $taxonomy => $declaration) {
                if (!is_array($declaration) || !array_key_exists('description_refs', $declaration)) {
                    continue;
                }
                $source = "manifest '$name' taxonomies.$taxonomy.description_refs";
                $rule = ReferenceRules::description($declaration['description_refs'], $source);
                if (!isset($claims[$taxonomy])) {
                    $claims[$taxonomy] = ['rule' => $rule, 'source' => $source];
                    continue;
                }
                if ($claims[$taxonomy]['rule'] != $rule) {
                    throw new \RuntimeException(
                        "duo: taxonomy '$taxonomy' has conflicting description_refs declarations from "
                        . "{$claims[$taxonomy]['source']} and $source — pin order may not choose a "
                        . 'serialized-description reference grammar'
                    );
                }
            }
        }
    }

    /** @param array<string,string[]> $claims */
    private static function throw_conflicting_taxonomy_object_keyspace(string $tax, array $claims): never {
        $rendered = [];
        foreach ($claims as $value => $sources) {
            $rendered[] = "$value from " . implode(', ', $sources);
        }
        throw new \RuntimeException(
            "duo: taxonomy '$tax' has conflicting object_keyspace declarations ("
            . implode('; ', $rendered) . ') — pin order may not choose a relationship keyspace'
        );
    }

    /**
     * DUO-3255: two non-core manifests may share an exact option name only
     * when their effective rules are identical. Pin order is incidental and
     * must never choose between contradictory authored/env/runtime/derived
     * contracts. Core-vs-plugin declarations are deliberately exempt: the
     * DUO-3249 core-yields-to-plugin rule is a ratified reclassification
     * layer and active_reclassifications() makes it plan-visible.
     *
     * A site policy rule for the colliding name is the explicit resolution
     * path. It outranks every manifest in rule_details(), so its presence
     * makes the operator's choice unambiguous and this guard skips that
     * name. `note` is the sole non-semantic option-rule annotation; every
     * other field (including required, ref/json/key/sub-key shape, lint_ok,
     * and effective autoload storage) participates in the comparison.
     *
     * @param list<array> $manifests
     * @param array<string,array> $siteOptions
     */
    private static function validate_no_conflicting_option_rules(array $manifests, array $siteOptions): void {
        $seen = [];
        foreach ($manifests as $manifest) {
            $manifestName = (string) ($manifest['name'] ?? '?');
            if ($manifestName === 'core') {
                continue;
            }
            foreach ($manifest['options'] ?? [] as $optionName => $rule) {
                $optionName = (string) $optionName;
                if (array_key_exists($optionName, $siteOptions) || !is_array($rule)) {
                    continue;
                }
                $effective = self::with_option_autoload($rule, $manifest);
                unset($effective['note']);
                $fingerprint = Canon::encode($effective);
                if (!isset($seen[$optionName])) {
                    $seen[$optionName] = [
                        'manifest' => $manifestName,
                        'class' => $rule['class'] ?? null,
                        'rule' => $effective,
                        'fingerprint' => $fingerprint,
                    ];
                    continue;
                }
                $prior = $seen[$optionName];
                if ($prior['fingerprint'] === $fingerprint) {
                    continue;
                }
                throw new \RuntimeException(
                    "duo: manifests '{$prior['manifest']}' and '$manifestName' declare contradictory rules"
                    . " for options.$optionName ({$prior['manifest']} class="
                    . var_export($prior['class'], true) . ", $manifestName class="
                    . var_export($rule['class'] ?? null, true) . '); effective rules differ ('
                    . Canon::encode($prior['rule']) . ' vs ' . Canon::encode($effective) . '). '
                    . "Add an explicit site.duo.json policy.options.$optionName override to resolve this option."
                );
            }
        }
    }

    /**
     * DUO-3318: one owner per post-type behavior key, across every pinned
     * manifest — the cross-manifest guard, run once after the whole set has
     * loaded (no single manifest's own validator could ever see this), and
     * the acceptance-4 half of this issue for the post surface: extension
     * must not grant one adapter authority over another adapter's entities.
     *
     * Unlike options — where DUO-3249 established a ratified
     * core-yields-to-plugin reclassification layer, which is exactly why
     * validate_no_conflicting_option_rules() exempts core — every post-type
     * behavior lookup in this class (body_mode(), post_type_phase(),
     * field_rule_details(), regen_dependency()) is a plain
     * first-declaration-in-pin-order walk with no precedence layer to appeal
     * to. So a second manifest declaring `fields` for WooCommerce's `product`
     * either silently loses or silently wins depending on where an operator
     * happened to put it in site.duo.json's list — one adapter's declaration
     * changing another adapter's entities, decided by an ordering nobody
     * intended as a decision. No exemption for core here for the same reason:
     * there is no ratified layer for this surface to express.
     *
     * Guarded per KEY rather than per whole declaration, because precedence
     * is per key: two manifests may legitimately say different THINGS about
     * one post type (a scope disposition from one, a derived-field claim from
     * another) as long as they do not contradict each other about the same
     * one. Identical declarations of the same key are redundant, not
     * ambiguous, and pass — the same allowance
     * validate_no_conflicting_adapter_claims() makes for a repeated range.
     *
     * DUO-3255 remains the open umbrella for the general "two non-core
     * manifests, one name" question; this instantiates its answer for one
     * concrete surface rather than waiting for the general ruling.
     *
     * @param list<array> $manifests
     */
    private static function validate_no_conflicting_post_type_contracts(array $manifests): void {
        $seen = [];
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '?');
            foreach ((array) ($manifest['post_types'] ?? []) as $postType => $decl) {
                if (!is_array($decl)) {
                    continue;
                }
                foreach ($decl as $key => $value) {
                    $fingerprint = Canon::encode([$value]);
                    $slot = "$postType\0$key";
                    if (!isset($seen[$slot])) {
                        $seen[$slot] = ['manifest' => $name, 'fingerprint' => $fingerprint];
                        continue;
                    }
                    if ($seen[$slot]['manifest'] === $name || $seen[$slot]['fingerprint'] === $fingerprint) {
                        continue;
                    }
                    throw new \RuntimeException(
                        "duo: manifests '{$seen[$slot]['manifest']}' and '$name' both declare "
                        . "post_types.$postType.$key with different values (" . $seen[$slot]['fingerprint']
                        . ' vs ' . $fingerprint . ") — a post type's behavior contract has exactly one owner, and "
                        . 'this lookup resolves by pin order, so accepting both would let one adapter silently '
                        . "change another adapter's entities. The extension path is the owning adapter's own "
                        . 'manifest, or an explicit site.duo.json decision for a site-local need — never a second '
                        . 'manifest reaching into the first'
                    );
                }
            }
        }
    }

    /**
     * One owner per NAME on the three bulk-enumerated declaration surfaces —
     * `post_types.<t>`, `tables.<t>`, `widgets.<t>` (DUO-3318 review, B1).
     *
     * The per-key post-type guard above is the sharper diagnostic and runs
     * first, but it can only see a contradiction about the SAME key. Two
     * manifests declaring DISJOINT keys of one post type — or one whole table
     * / widget type — never contradicted anything under it, and yet the
     * lookups behind those surfaces resolve by pin order in three different
     * directions: post-type behavior takes the FIRST declaration, while
     * declared_tables()/widget_types() take the LAST. Which adapter wins is
     * therefore decided by where an operator happened to put a name in
     * site.duo.json's list, on a surface where the loser's declaration
     * disappears silently and completely. That is the same class of hazard
     * validate_no_conflicting_option_rules() and
     * validate_no_conflicting_adapter_claims() already refuse, and it is
     * acceptance-4 of this issue: extension must never grant one adapter
     * authority over another adapter's state.
     *
     * Byte-identical declarations pass, exactly as the option-rule and
     * version-range guards allow a repeated identical claim: two adapters
     * saying the SAME thing is redundant, not ambiguous, and there is no
     * winner to pick. Equality is Canon-encoded, so it is the wire bytes that
     * must agree, not PHP's loose comparison.
     *
     * `core` is NOT exempt here. The DUO-3249 core-yields-to-plugin layer is
     * an option/meta RULE mechanism (rule_details()); no lookup on these three
     * surfaces implements it, so exempting core would silently reintroduce the
     * pin-order coin flip it is meant to resolve.
     *
     * site.duo.json's own policy.tables is deliberately outside this walk. A
     * site override is the operator's own authority over their own site — the
     * documented, wholesale, last-word layer declared_tables() applies after
     * every manifest — not a second adapter reaching into the first.
     *
     * @param list<array> $manifests
     */
    private static function validate_one_owner_per_declared_name(array $manifests): void {
        $seen = [];
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '?');
            // taxonomies joined the walk on independent review: its three
            // lookups (description_refs_for_taxonomy(), object_type_from_
            // option, the taxonomy class rule) are all first-pin-wins with
            // no precedence layer to appeal to — the identical takeover
            // shape the other three surfaces refuse. Only polylang declares
            // any taxonomy today and nothing overlaps; site
            // policy.taxonomies is a plain scope-name list, not a
            // declaration map, so no site exemption arises.
            foreach (['post_types', 'tables', 'taxonomies', 'widgets'] as $surface) {
                foreach ((array) ($manifest[$surface] ?? []) as $declared => $decl) {
                    $slot = "$surface\0$declared";
                    $fingerprint = Canon::encode([$decl]);
                    if (!isset($seen[$slot])) {
                        $seen[$slot] = ['manifest' => $name, 'fingerprint' => $fingerprint];
                        continue;
                    }
                    if ($seen[$slot]['manifest'] === $name || $seen[$slot]['fingerprint'] === $fingerprint) {
                        continue;
                    }
                    throw new \RuntimeException(
                        "duo: manifests '{$seen[$slot]['manifest']}' and '$name' both declare $surface.$declared "
                        . "with different declarations — $surface.<name> has exactly ONE owner, and this lookup "
                        . 'resolves by pin order, so accepting both would let one adapter silently redefine '
                        . "another adapter's state depending on the order site.duo.json happens to list them. "
                        . 'Pin only one declaring manifest, or make the two declarations byte-identical; there is '
                        . 'no composition grammar for this surface in v1. Reclassifying an individual FIELD of '
                        . "another adapter's surface is what the menu_fields-style precedence layers exist for — "
                        . 'never a whole-declaration takeover'
                    );
                }
            }
        }
    }

    /**
     * Two declared tables may never share one `id_kind` (DUO-3318 review, N4).
     *
     * duo_map's unique key is (id_kind, local_id), so two tables sharing a
     * kind collide their rows' identities the instant both hold a row with the
     * same local id — one table's uuid silently resolving to the other
     * table's row. Snapshot::row_tables() has always refused this and keeps
     * doing so as the defensive twin (it is reached by directly-constructed
     * Policy objects that never went through load()); what it cannot do is
     * refuse OFFLINE, before any target contact, on the cross-manifest case
     * this rule mostly exists for — two independently-authored adapters
     * picking the same short abbreviation. Checked against the RESOLVED
     * declaration set (declared_tables()), so a site.duo.json override that
     * retypes a table is judged on the declaration that will actually be used.
     *
     * @param array<string,array> $declaredTables
     */
    private static function validate_unique_table_id_kinds(array $declaredTables): void {
        $seen = [];
        foreach ($declaredTables as $table => $decl) {
            // The literal, not Snapshot::CLASS_ROW: this file must stay
            // loadable with no other engine class present (see TABLE_CLASSES).
            if (!is_array($decl) || ($decl['class'] ?? '') !== 'authored_snapshot') {
                continue;
            }
            $kind = (string) ($decl['id_kind'] ?? '');
            if ($kind === '') {
                continue; // width/emptiness is Snapshot::assert_id_kind_width()'s own refusal
            }
            if (isset($seen[$kind])) {
                throw new \RuntimeException(
                    "duo: id_kind '$kind' is declared by both '{$seen[$kind]}' and '$table' — each "
                    . 'authored_snapshot table needs its own unique id_kind, because duo_map is keyed by '
                    . '(id_kind, local_id): two tables sharing one kind resolve each other\'s rows the moment '
                    . 'both hold the same local id. An id_kind is the adapter\'s own namespace to choose; pick a '
                    . 'distinct one (typically a short prefix of the owning plugin)'
                );
            }
            $seen[$kind] = (string) $table;
        }
    }

    /**
     * The two remaining surfaces that name a duo_map keyspace directly
     * (DUO-3318 review, S6), closed the same way the ref/token vocabularies
     * above are.
     *
     * These are LEDGER kinds, a third vocabulary rather than a restatement of
     * the token one, because both reach Ledger::id_for() with the declared
     * string verbatim (Apply::count_guard_refs(), and the option-name-ref
     * resolution path) instead of going through Tokens. So the engine-owned
     * base here is the ledger's own LONG spellings — `term_taxonomy`, never
     * the `tt` a manifest writes in a token kind — and `user` is absent
     * because duo_map has no user keyspace at all (a user reference is
     * serialized as a login, never an id).
     *
     * `option_name_refs[].id_kind` is narrower still: its own contract is
     * that the captured id names a row of a declared table (that is what
     * makes the id in an option NAME portable), so post/term/term_taxonomy
     * are not legal there — only an id_kind some pinned manifest declared for
     * a table it owns. Both were previously validated for SHAPE only
     * (`^[a-z][a-z0-9_]*$`), so a typo produced a keyspace with no rows:
     * a guard whose identity mapping is "absent" blocks every delete it
     * guards with a message about missing identity, and an option-name ref
     * that resolves to nothing drops the option from canonical state.
     *
     * @param list<array> $manifests
     * @param list<string> $declaredIdKinds every pinned/site-declared table's id_kind
     */
    private static function validate_ledger_kind_claims(array $manifests, array $declaredIdKinds): void {
        // ENGINE_LEDGER_KINDS repeats Ledger::KIND_POST/KIND_TERM/KIND_TT
        // rather than referencing them, for the same reason TABLE_CLASSES
        // repeats Snapshot's two class names: this file must stay loadable with
        // no other engine class present, and these spellings are wire format a
        // manifest already carries, not an internal name either side may change.
        $ledgerKinds = array_merge(self::ENGINE_LEDGER_KINDS, $declaredIdKinds);
        sort($ledgerKinds, SORT_STRING);
        sort($declaredIdKinds, SORT_STRING);
        foreach ($manifests as $manifest) {
            $label = "manifest '" . (string) ($manifest['name'] ?? '?') . "'";
            $claims = [];
            foreach ((array) ($manifest['deletions'] ?? []) as $selector => $decl) {
                foreach ((array) (is_array($decl) ? ($decl['guards'] ?? []) : []) as $i => $guard) {
                    foreach (['id_kind', 'source_id_kind'] as $key) {
                        if (is_array($guard) && array_key_exists($key, $guard)) {
                            $claims[] = ["deletions.$selector.guards[$i].$key", $guard[$key], $ledgerKinds];
                        }
                    }
                }
            }
            foreach ((array) ($manifest['option_name_refs'] ?? []) as $i => $rule) {
                if (is_array($rule) && array_key_exists('id_kind', $rule)) {
                    $claims[] = ["option_name_refs[$i].id_kind", $rule['id_kind'], $declaredIdKinds];
                }
            }
            foreach ($claims as [$path, $value, $legal]) {
                if (is_string($value) && in_array($value, $legal, true)) {
                    continue;
                }
                throw new \RuntimeException(
                    "duo: $label declares $path=" . var_export($value, true) . ' but the ledger kind vocabulary '
                    . 'is closed here (' . ($legal === [] ? '<no table id_kind is declared by any pinned manifest>'
                        : implode(', ', $legal)) . '). This value is looked up in duo_map verbatim, so an '
                    . 'unrecognized one names a keyspace with no rows rather than failing — '
                    . ($legal === $declaredIdKinds
                        ? 'an option-name reference resolves against a declared TABLE, so name the id_kind of a '
                          . 'table some pinned manifest declares'
                        : 'post/term/term_taxonomy are the engine\'s own (the ledger\'s LONG spellings, not the '
                          . '"tt" a token kind uses), and every other legal value is an id_kind a pinned manifest '
                          . 'declared for a table it owns')
                );
            }
        }
    }

    private const ENGINE_TOKEN_KINDS = ['post', 'term', 'tt'];
    /** @see ENGINE_TOKEN_KINDS */
    private const ENGINE_REF_KINDS = ['post', 'term', 'tt', 'user'];
    /** @see ENGINE_TOKEN_KINDS — duo_map's own long spellings, not the token short ones. */
    private const ENGINE_LEDGER_KINDS = ['post', 'term', 'term_taxonomy'];

    /**
     * The ref-kind vocabulary, closed across every pinned manifest and the
     * site's own policy (DUO-3318).
     *
     * A ref kind is the engine's typed-identity keyspace name: three are the
     * engine's own (`post`, `term`, `tt` — Tokens::KIND_MAP's historical
     * short spellings), `user` is the login-serialized form a classification
     * rule may name, and every other legal value is an `id_kind` some pinned
     * manifest declared for a table it owns. That last clause is the whole
     * extension path, and it is deliberately the only one: an adapter widens
     * this vocabulary by declaring a TABLE it owns, never by naming a kind
     * out of thin air.
     *
     * Why closed at all: Tokens::id_to_token() passes an unrecognized kind
     * straight through as a ledger lookup key (KIND_MAP is a rename table,
     * not a gate — deliberately, so a declared id_kind needs no engine-side
     * registration). A typo therefore resolved to a keyspace with no rows,
     * returned null, and took the ordinary dangling-reference path: the value
     * was DROPPED from canonical state with a warning that named the id but
     * not the misspelled kind. `"ref": "psot"` silently deleted authored
     * references — the loudest possible symptom being a warning that looks
     * exactly like an ordinary unmapped id.
     *
     * Two vocabularies, not one, because they answer different questions. A
     * classification rule's `ref` may name `user` and may carry the `[]`
     * plural suffix. A TOKEN kind — a table's `refs[]`, a `block_attrs`/
     * `shortcode_attrs` rule, a `json_refs`/`key_refs` entry — is resolved
     * through duo_map, which has no user keyspace, and carries its plurality
     * in a separate `type`/`cast` field rather than in the kind name.
     *
     * The three ENGINE_* consts declared just above are the engine-owned BASE
     * of each vocabulary — the part that is a fixed fact about this engine
     * rather than a function of which manifests happen to be pinned. Each
     * validator still unions its base with the declared id_kinds; splitting the
     * base out as a const is what lets closed_vocabularies() publish "what the
     * engine owns" without a second copy of these spellings existing anywhere.
     *
     * @param list<array> $manifests
     * @param array<string,mixed> $sitePolicy site.duo.json's `policy` object
     */
    private static function validate_ref_kinds(array $manifests, array $sitePolicy): void {
        $idKinds = [];
        foreach (array_merge($manifests, [['tables' => $sitePolicy['tables'] ?? []]]) as $source) {
            foreach ((array) ($source['tables'] ?? []) as $decl) {
                $kind = is_array($decl) ? ($decl['id_kind'] ?? null) : null;
                if (is_string($kind) && $kind !== '') {
                    $idKinds[$kind] = true;
                }
            }
        }
        $tokenKinds = array_merge(self::ENGINE_TOKEN_KINDS, array_keys($idKinds));
        $refKinds = array_merge(self::ENGINE_REF_KINDS, array_keys($idKinds));
        sort($tokenKinds, SORT_STRING);
        sort($refKinds, SORT_STRING);
        // The third vocabulary built on the same declared-id_kind set, called
        // from here rather than from load() so that set is computed once and
        // the three can never be derived from different inputs.
        self::validate_ledger_kind_claims($manifests, array_keys($idKinds));

        $sources = [];
        foreach ($manifests as $manifest) {
            $sources["manifest '" . (string) ($manifest['name'] ?? '?') . "'"] = $manifest;
        }
        $sources['site.duo.json policy'] = $sitePolicy;
        foreach ($sources as $label => $source) {
            $claims = [];
            self::collect_ref_kind_claims($source, '', $claims);
            self::validate_attr_kind_claims($source, $claims);
            foreach ($claims as [$path, $value, $token]) {
                $legal = $token ? $tokenKinds : $refKinds;
                $bare = (!$token && is_string($value) && str_ends_with($value, '[]'))
                    ? substr($value, 0, -2)
                    : $value;
                if (is_string($bare) && in_array($bare, $legal, true)) {
                    continue;
                }
                throw new \RuntimeException(
                    "duo: $label declares $path=" . var_export($value, true) . ' but the '
                    . ($token ? 'token' : 'reference') . ' kind vocabulary is closed ('
                    . implode(', ', $legal) . ($token ? '' : ', each optionally suffixed with [] for a list')
                    . '). post/term/tt' . ($token ? '' : '/user') . ' are engine-owned; every other kind is an '
                    . 'id_kind a pinned manifest declared for a table it owns, which is the only way to extend '
                    . 'this vocabulary — declare the table, then name its id_kind'
                );
            }
        }
    }

    /**
     * Every ref-kind claim reachable from a manifest's classification
     * sections, as [path, value, isTokenKind] triples.
     *
     * Four top-level channels are skipped rather than walked: `notes` is
     * free-form human prose keyed by arbitrary strings, `actions`/`providers`
     * carry structured arguments whose key names belong to the declaring
     * plugin's own capability schema (a provider argument may legitimately be
     * called `ref` and mean something entirely unrelated), and
     * `lifecycle_effects` is the reversibility grammar, whose `kind` is a
     * category rather than a keyspace. Everywhere else in a manifest, `ref`
     * has exactly one meaning, which is what makes a blind walk correct rather
     * than fragile — and why a bare `kind` is NOT walked blindly, but read at
     * its four exact declared locations instead.
     *
     * Unbounded recursion by construction, deliberately: the input is an
     * already-decoded manifest, so its depth is bounded by json_decode()'s own
     * 512-level default (Canon::decode() takes it) long before PHP's stack is,
     * and the manifests directory is operator-controlled — the same trust
     * decision as running the agent at all (see manifests_dir()). Every other
     * structural walker in this engine — Canon::normalize(), JsonRefs::walk(),
     * SidebarState::rewrite_strings() — recurses on the same terms; adding a
     * depth counter to this one alone would claim a threat model the rest of
     * the engine does not share.
     *
     * @param list<array{0:string,1:mixed,2:bool}> $out
     */
    private static function collect_ref_kind_claims(mixed $node, string $path, array &$out): void {
        if (!is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            $childPath = $path === '' ? (string) $key : $path . '.' . $key;
            if ($path === '' && in_array($key, ['notes', 'actions', 'providers', 'lifecycle_effects'], true)) {
                continue;
            }
            if ($key === 'ref') {
                $out[] = [$childPath, $value, false];
                continue;
            }
            if ($key === 'refs' && is_array($value) && array_is_list($value)) {
                foreach ($value as $i => $entry) {
                    if (is_array($entry) && array_key_exists('kind', $entry)) {
                        $out[] = [$childPath . "[$i].kind", $entry['kind'], true];
                    }
                }
                continue;
            }
            if ($key === 'json_refs' && is_array($value)) {
                foreach ($value as $i => $entry) {
                    if (is_array($entry) && array_key_exists('kind', $entry)) {
                        $out[] = [$childPath . "[$i].kind", $entry['kind'], true];
                    }
                }
                continue;
            }
            if ($key === 'key_refs' && is_array($value) && array_key_exists('kind', $value)) {
                $out[] = ["$childPath.kind", $value['kind'], true];
                continue;
            }
            self::collect_ref_kind_claims($value, $childPath, $out);
        }
    }

    /**
     * The two attribute registries' own kind claims, added here rather than
     * in the blind walk because a bare `kind` key means different things in
     * different channels (an effect's `kind` is a reversibility category, not
     * a keyspace) — so these two are read by their exact declared location.
     *
     * @param list<array{0:string,1:mixed,2:bool}> $out
     */
    private static function validate_attr_kind_claims(array $source, array &$out): void {
        foreach (['block_attrs', 'shortcode_attrs'] as $section) {
            foreach ((array) ($source[$section] ?? []) as $subject => $rules) {
                foreach ((array) $rules as $i => $rule) {
                    if (!is_array($rule)) {
                        continue;
                    }
                    $at = $section . '.' . $subject . '[' . $i . ']';
                    if (array_key_exists('kind', $rule)) {
                        $out[] = ["$at.kind", $rule['kind'], true];
                    }
                    $from = $rule['kind_from'] ?? null;
                    if (!is_array($from)) {
                        continue;
                    }
                    foreach ((array) ($from['map'] ?? []) as $attrValue => $kind) {
                        $out[] = ["$at.kind_from.map.$attrValue", $kind, true];
                    }
                    if (array_key_exists('default', $from)) {
                        $out[] = ["$at.kind_from.default", $from['default'], true];
                    }
                }
            }
        }
    }

    /**
     * DUO-3222: loud, load-time guard for the adapter compatibility
     * contract — same "throw immediately" posture as every validator
     * above. A manifest that names a plugin/theme without an exact,
     * well-formed version range is exactly the "unbounded support" this
     * issue's own non-negotiable constraint forbids ("No latest, wildcard,
     * or unbounded version support may be certified") —
     * Policy::version_ranges()'s own pre-existing behavior of silently
     * SKIPPING a plugin with no/malformed version_range (rather than
     * rejecting) is the failure mode DUO-3222 was filed to close, so this
     * validator now makes that combination a hard load-time error instead
     * of a silent no-op that would otherwise surface (if at all) only much
     * later, at deploy time.
     *
     * spec_version is MANDATORY (DUO-3247): every manifest must declare it,
     * and it must equal DUO_SPEC_VERSION exactly — absent and
     * declared-and-wrong are now the same failure. This was not always the
     * rule: DUO-3222's original validator treated ABSENT as lenient, because
     * no shipped manifest declared it yet and DUO_SPEC_VERSION had exactly
     * one historical value (an absence can't be "wrong" when there is
     * nothing else it could have meant). DUO-3222's own design review
     * pre-committed, in writing, to flipping that leniency the moment
     * DUO_SPEC_VERSION got a second historical value — "that bump's own
     * checklist item, not a future debate." DUO-3210 performed that bump
     * (0→1) while this validator's PR was still open, so the two landed on
     * `main` separately; DUO-3247 is the follow-up that actions the
     * pre-committed flip. A manifest making no checkable claim about spec
     * compatibility is exactly the "unsupported behavior hidden behind a
     * broad compatibility claim" DESIGN.md's vision invariant forbids, same
     * as an active wrong claim — so both throw through the same site below.
     */
    private static function validate_adapter_contract(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        $spec = $manifest['spec_version'] ?? null;
        $supported = defined('DUO_SPEC_VERSION') ? DUO_SPEC_VERSION : 0;
        if (!is_int($spec) || $spec !== $supported) {
            $declared = $spec === null ? 'no spec_version' : ('spec_version ' . var_export($spec, true));
            throw new \RuntimeException(
                "duo: manifest '$name' declares $declared"
                . " but this engine requires spec_version $supported — pin a compatible manifest or update it"
            );
        }
        // The interpreter NAME is validated at load rather than only in
        // interpreters(), which reaches it lazily and would hand a non-string
        // straight to preg_match(). Type first, shape second — both here, so
        // every manifest from every source is held to the same contract and a
        // malformed declaration cannot survive as far as a use site.
        if (array_key_exists('interpreter', $manifest) && $manifest['interpreter'] !== null) {
            $interpreter = $manifest['interpreter'];
            if (!is_string($interpreter) || preg_match('/^[a-z0-9_-]+$/D', $interpreter) !== 1) {
                throw new \RuntimeException(
                    "duo: manifest '$name' declares interpreter " . var_export($interpreter, true)
                    . ' — an interpreter name must be a non-empty string matching ^[a-z0-9_-]+$, since it resolves '
                    . 'to <manifests_dir>/interpreters/<name>.php'
                );
            }
        }
        foreach ([['plugin', 'version_range'], ['theme', 'theme_version_range']] as [$idKey, $rangeKey]) {
            $id = $manifest[$idKey] ?? null;
            if ($id === null) {
                continue;
            }
            if (!is_string($id) || $id === '') {
                throw new \RuntimeException("duo: manifest '$name' declares a non-string or empty '$idKey'");
            }
            if ($idKey === 'plugin') {
                AdapterSources::assert_plugin_basename($id, "manifest '$name' declares 'plugin'");
            }
            // DUO-3314: this field became site-controlled the moment adapters
            // could be installed out-of-tree, and three call sites concatenate
            // it into a filesystem path (CapabilityRegistry::
            // installed_plugin_version(), Deploy's code-half version reads,
            // Providers' owning-plugin resolution). None is reachable with a
            // traversing value today — they all miss and report "missing"
            // — but "no reachable sink today" is not a property a manifest
            // field should have to keep proving. A plugin id is
            // `<dir>/<file>.php` or a bare `<file>.php`; a theme id is a bare
            // directory slug. Anything with a `..` segment, an absolute root,
            // or a backslash is refused at load, before any consumer.
            $segments = explode('/', $id);
            $depthOk = $idKey === 'plugin' ? count($segments) <= 2 : count($segments) === 1;
            if ($idKey !== 'plugin' && (!$depthOk || $id[0] === '/' || str_contains($id, '\\')
                || in_array('..', $segments, true) || in_array('.', $segments, true)
                || in_array('', $segments, true))) {
                throw new \RuntimeException(
                    "duo: manifest '$name' declares '$idKey' " . var_export($id, true)
                    . ' — a ' . $idKey . ' identifier is '
                    . ($idKey === 'plugin' ? "'<directory>/<file>.php' or '<file>.php'" : 'a bare directory slug')
                    . ', never an absolute path and never one containing a ".." segment; it is concatenated into '
                    . 'filesystem paths by the code-half version checks'
                );
            }
            $range = $manifest[$rangeKey] ?? null;
            if (!is_array($range)) {
                throw new \RuntimeException(
                    "duo: manifest '$name' declares '$idKey' ('$id') but no '$rangeKey' — an adapter naming a "
                    . "$idKey with no exact version range is unbounded support, which this project's contract "
                    . 'forbids (DUO-3222). Declare {"min":..,"max":..} or drop the ' . "$idKey claim."
                );
            }
            $min = $range['min'] ?? null;
            $max = $range['max'] ?? null;
            if (!is_string($min) || $min === '' || !is_string($max) || $max === ''
                || version_compare($min, $max, '>=')
            ) {
                throw new \RuntimeException(
                    "duo: manifest '$name' declares '$rangeKey' with a malformed range (min="
                    . var_export($min, true) . ', max=' . var_export($max, true) . ') — both must be non-empty '
                    . 'version strings with min strictly less than max; wildcards/empty/unbounded are not '
                    . 'certifiable'
                );
            }
        }
    }

    /**
     * DUO-3222: cross-manifest guard, run once after every pinned manifest
     * has loaded (not per-manifest, unlike every validator above — this is
     * inherently a comparison BETWEEN manifests, so no single manifest's
     * own validator could ever catch it). Two PINNED manifests naming the
     * SAME plugin or theme with DIFFERENT version_range/theme_version_range
     * is "conflicting ownership" / "overlapping rules without explicit
     * composition" (DUO-3222's own acceptance criteria) — today's
     * version_ranges()/theme_ranges() silently let the first-in-pin-order
     * declaration win, which is exactly the load-order-dependent
     * precedence this issue's own non-negotiable constraint forbids
     * ("Manifest precedence cannot depend on load order").
     *
     * v2 has NO composition/override escape hatch (no "supersedes" field
     * or similar): every manifest pinned by every real site in this
     * project models a DISTINCT plugin or theme today, so there is no
     * genuine case requiring two manifests to legitimately co-declare the
     * same one — adding override grammar for a need nobody has yet is
     * exactly the untested-guess discipline this project avoids elsewhere
     * (manifests/yoast.json's own notes make the identical call
     * repeatedly, e.g. declining to guess wpseo_rss's shape). A real case,
     * if one ever appears, is a fast-follow with its own evidence, not a
     * default baked in speculatively here.
     *
     * Two manifests declaring the IDENTICAL range for the same plugin/
     * theme are deliberately allowed through (redundant, not ambiguous —
     * they produce the same answer regardless of load order, which is the
     * only thing this guard actually protects against).
     */
    private static function validate_no_conflicting_adapter_claims(array $manifests): void {
        foreach ([['plugin', 'version_range'], ['theme', 'theme_version_range']] as [$idKey, $rangeKey]) {
            $seen = [];
            foreach ($manifests as $m) {
                $id = $m[$idKey] ?? null;
                if (!is_string($id) || $id === '') {
                    continue;
                }
                $range = $m[$rangeKey] ?? [];
                $name = (string) ($m['name'] ?? '?');
                if (isset($seen[$id])) {
                    $prev = $seen[$id];
                    if ($prev['range'] != $range) {
                        throw new \RuntimeException(
                            "duo: manifests '{$prev['name']}' and '$name' both declare $idKey '$id' with "
                            . "different $rangeKey values (" . json_encode($prev['range']) . ' vs '
                            . json_encode($range) . ') — conflicting ownership with no v2 composition rule; '
                            . 'pin only one, or narrow one range to a disjoint window'
                        );
                    }
                    continue; // identical range declared twice — redundant, not conflicting; allow
                }
                $seen[$id] = ['name' => $name, 'range' => $range];
            }
        }
    }

    /**
     * Manifest-declared rebuild actions, flattened in pin order.
     *
     * Every row is annotated with `manifest` (the declaring manifest's name)
     * and `index` (its position in that manifest's own `actions` list). Both
     * are load-bearing for the consumers, not decoration: a provider-kind
     * action resolves its `provider` id against the SAME manifest's
     * declarations (validate_actions() enforces that scope, so the id alone
     * is not a global key until provider_declarations() has proven global
     * uniqueness), and effects_inventory()/negotiation diagnostics name the
     * exact declaration a human has to go edit.
     *
     * @return list<array<string,mixed>>
     */
    public function actions(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            $name = (string) ($m['name'] ?? '?');
            foreach ((array) ($m['actions'] ?? []) as $i => $action) {
                $out[] = $action + ['manifest' => $name, 'index' => (int) $i];
            }
        }
        return $out;
    }

    /**
     * Manifest actions with an exact canonical surface intersection.
     *
     * A declaration without `triggers` is deliberately unscoped: it remains
     * required for every authored mutation, preserving the semantics the
     * retired `rebuilders` channel gave an un-triggered declaration. A
     * declaration with triggers is selected only when Apply has derived the
     * exact same canonical surface from this request. The empty surface set
     * is a no-op, so a read-only apply cannot fire an action.
     *
     * @param list<string> $surfaces
     * @return list<array<string,mixed>>
     */
    public function actions_for(array $surfaces): array {
        $wanted = [];
        foreach ($surfaces as $surface) {
            if (is_string($surface) && $surface !== '') {
                $wanted[$surface] = true;
            }
        }
        if ($wanted === []) {
            return [];
        }

        $out = [];
        foreach ($this->actions() as $action) {
            if (!array_key_exists('triggers', $action)) {
                $out[] = $action;
                continue;
            }
            foreach ((array) $action['triggers'] as $trigger) {
                if (isset($wanted[$trigger])) {
                    $out[] = $action;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Every pinned manifest's provider declarations, keyed by provider id.
     *
     * The key is global because validate_no_conflicting_provider_ids() has
     * already refused two pinned manifests declaring the same id — the same
     * posture validate_no_conflicting_adapter_claims() takes for a plugin or
     * theme claim, and for the same reason: a provider id is an identity
     * assertion about installed executable code, so letting pin order pick a
     * winner would make which code runs depend on load order.
     *
     * Each row carries the declaring manifest's name and its `version_range`
     * (null when the manifest pins no range) because negotiation bounds a
     * provider by exactly the range that bounds its manifest's classification
     * guarantees — the provider is that adapter's executable half, so letting
     * it run outside the window the declarative half was certified for would
     * make the version pin mean two different things.
     *
     * @return array<string, array<string,mixed>> id => declaration + `manifest` + `version_range`
     */
    public function provider_declarations(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            $name = (string) ($m['name'] ?? '?');
            $range = is_array($m['version_range'] ?? null)
                ? ['min' => (string) $m['version_range']['min'], 'max' => (string) $m['version_range']['max']]
                : null;
            foreach ((array) ($m['providers'] ?? []) as $declaration) {
                $out[(string) $declaration['id']] = $declaration
                    + ['manifest' => $name, 'version_range' => $range];
            }
        }
        return $out;
    }

    /**
     * Complete target-independent effect declaration for the automatic
     * rollback profile. Missing lifecycle/rebuilder/regenerator declarations
     * are represented as explicit irreversible rows instead of disappearing:
     * manual promotion remains available, while receipt preparation must
     * refuse before code stage. The manifest digest already binds these bytes;
     * this projection gives the recovery controller a stable, minimal input.
     *
     * @return list<array<string,mixed>>
     */
    public function effects_inventory(): array {
        // Engine-owned rebuild effects are declarations too. Database rows
        // are covered by the encrypted checkpoint; the external object cache
        // needs a real provider inverse/readback and therefore cannot hide
        // behind the fact that its contents are derived.
        $out = [
            [
                'manifest' => 'core', 'phase' => 'rebuild', 'source' => 'future-post-schedule',
                'effect' => [
                    'id' => 'core-future-post-schedule', 'kind' => 'database', 'mode' => 'restorable',
                    'selector' => ['scope' => 'database_checkpoint', 'type' => 'option', 'value' => 'cron'],
                ],
            ],
            [
                'manifest' => 'core', 'phase' => 'rebuild', 'source' => 'taxonomy-counts',
                'effect' => [
                    'id' => 'core-taxonomy-counts', 'kind' => 'database', 'mode' => 'restorable',
                    'selector' => ['scope' => 'database_checkpoint', 'type' => 'table', 'value' => 'term_taxonomy'],
                ],
            ],
            [
                'manifest' => 'core', 'phase' => 'rebuild', 'source' => 'object-cache-flush',
                'effect' => [
                    'id' => 'core-object-cache', 'kind' => 'cache', 'mode' => 'reversible',
                    'selector' => ['scope' => 'external', 'type' => 'namespace', 'value' => 'wordpress-object-cache'],
                    'adapter' => [
                        'id' => 'core-object-cache', 'version' => '1.0.0',
                        'inverse' => 'flush-prior-generation',
                        'inverse_inputs' => ['namespace', 'prior_generation'],
                        'verifier' => 'fresh-cache-generation',
                        'verifier_inputs' => ['namespace', 'expected_generation'],
                    ],
                ],
            ],
            [
                'manifest' => 'core', 'phase' => 'rebuild', 'source' => 'attachment-metadata',
                'effect' => [
                    'id' => 'core-attachment-metadata-db', 'kind' => 'database', 'mode' => 'restorable',
                    'selector' => ['scope' => 'database_checkpoint', 'type' => 'table', 'value' => 'postmeta'],
                ],
            ],
            [
                'manifest' => 'core', 'phase' => 'rebuild', 'source' => 'attachment-metadata',
                'effect' => [
                    'id' => 'core-attachment-derivatives', 'kind' => 'filesystem', 'mode' => 'reversible',
                    'selector' => ['scope' => 'external', 'type' => 'provider_resource', 'value' => 'compiled-upload-inventory'],
                    'adapter' => [
                        'id' => 'upload-bundle', 'version' => '1.0.0',
                        'inverse' => 'storage-restore',
                        'inverse_inputs' => ['uploads_inventory_sha256', 'prior_inventory_sha256'],
                        'verifier' => 'fresh-storage-readback',
                        'verifier_inputs' => ['uploads_inventory_sha256', 'prior_inventory_sha256'],
                    ],
                ],
            ],
        ];
        foreach ($this->manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '?');
            $adapter = isset($manifest['plugin']) ? (string) $manifest['plugin']
                : (isset($manifest['theme']) ? (string) $manifest['theme'] : null);
            if ($adapter !== null) {
                $effects = $manifest['lifecycle_effects'] ?? null;
                if (!is_array($effects) || $effects === []) {
                    $effects = [self::missing_effect($name . '-lifecycle', 'plugin_lifecycle', $adapter)];
                }
                foreach ($effects as $effect) {
                    $out[] = ['manifest' => $name, 'phase' => 'lifecycle', 'source' => $adapter, 'effect' => $effect];
                }
            }
            foreach ((array) ($manifest['actions'] ?? []) as $i => $action) {
                // The source string is the effect inventory's stable name for
                // "what performs this effect". Under the retired free-form
                // channel that was the wp-cli command text; a structured
                // action's equivalent is its closed identity — the native
                // vocabulary entry, or the exact provider capability — which
                // is what a recovery operator can look up and re-run.
                $source = self::action_source($action, $i);
                $effects = self::action_effects($action + ['manifest' => $name], $i);
                foreach ($effects as $effect) {
                    $out[] = ['manifest' => $name, 'phase' => 'rebuild', 'source' => $source, 'effect' => $effect];
                }
            }
            foreach ((array) ($manifest['post_types'] ?? []) as $postType => $declaration) {
                $regen = is_array($declaration) ? ($declaration['regen_dependency'] ?? null) : null;
                if (!is_array($regen)) {
                    continue;
                }
                $effects = $regen['effects'] ?? null;
                if (!is_array($effects) || $effects === []) {
                    $effects = [self::missing_effect($name . '-regenerator-' . $postType, 'provider_resource', (string) $postType)];
                }
                foreach ($effects as $effect) {
                    $out[] = ['manifest' => $name, 'phase' => 'regenerator', 'source' => (string) $postType, 'effect' => $effect];
                }
            }
        }
        usort($out, static fn(array $a, array $b): int => strcmp(
            implode("\0", [$a['phase'], $a['manifest'], (string) $a['effect']['id']]),
            implode("\0", [$b['phase'], $b['manifest'], (string) $b['effect']['id']])
        ));
        return $out;
    }

    /**
     * The stable, closed identity of one action declaration.
     *
     * Shared by effects_inventory() above and Apply's rebuild receipts so a
     * recovery operator correlating a receipt with a declared effect compares
     * one string produced in one place, never two independently-formatted
     * spellings of the same fact. `$index` is only reached by a declaration
     * that failed validation (validate_actions() requires `kind`), which
     * effects_inventory() can still be asked about through a frozen snapshot.
     */
    public static function action_source(array $action, int $index): string {
        $kind = $action['kind'] ?? null;
        if ($kind === 'native') {
            return 'native:' . (string) ($action['action'] ?? '?');
        }
        if ($kind === 'provider') {
            return 'provider:' . (string) ($action['provider'] ?? '?')
                . '/' . (string) ($action['capability'] ?? '?');
        }
        return "actions[$index]";
    }

    /**
     * Exact effect declaration for one structured action. Public read-only
     * evidence needs this helper because two native actions can share a
     * closed source spelling while carrying different triggers/effects.
     *
     * @return list<array<string,mixed>>
     */
    public static function action_effects(array $action, int $index): array {
        $effects = $action['effects'] ?? null;
        if (is_array($effects) && $effects !== []) {
            return $effects;
        }
        $manifest = (string) ($action['manifest'] ?? '?');
        return [self::missing_effect(
            $manifest . "-action-$index",
            'provider_resource',
            self::action_source($action, $index)
        )];
    }

    /** @return array<string,mixed> */
    private static function missing_effect(string $id, string $type, string $value): array {
        return [
            'id' => preg_replace('/[^a-z0-9._:-]+/', '-', strtolower($id)),
            'kind' => 'external',
            'mode' => 'irreversible',
            'selector' => ['scope' => 'external', 'type' => $type, 'value' => $value],
        ];
    }

    /**
     * Validate the structured rebuild-action channel (DUO-3338).
     *
     * The retired `rebuilders` channel let a manifest name a wp-cli command
     * string — including `eval '<php>'` — that Apply then executed verbatim.
     * The boundary doctrine (docs/proposals/engine-adapter-boundary.md §1)
     * forbids engine core executing manifest-supplied PHP/shell/WP-CLI
     * strings, so the key is refused rather than ignored: manifests carry no
     * unknown-top-level-key validator, so silently dropping the channel would
     * leave a pinned adapter's derived-state repair quietly not happening,
     * which is exactly the false-green class this project refuses.
     *
     * Two kinds, both data-only. `native` names an entry in the engine's own
     * closed vocabulary (NativeActions), so a manifest cannot mint action
     * names or smuggle an executable string through an argument — the arg
     * schema is closed and checked HERE, at load time, before any target
     * contact. `provider` names executable code owned by the installed plugin
     * or its adapter package; the manifest carries only identity (which
     * provider, which capability, which structured arguments), and the
     * provider's own declared schema is what the arguments are finally
     * validated against at negotiation time, when the code is present.
     *
     * `triggers` keeps the retired channel's grammar and semantics exactly:
     * surface names are a small literal matching vocabulary, never patterns
     * or command fragments, so a manifest can narrow an action to one
     * canonical post type/table/option/taxonomy without gaining authority to
     * name arbitrary ids. Membership is deliberately not forced to this
     * manifest's own declaration lists: an action may observe a core/site
     * surface owned by another pinned manifest, while exact literal matching
     * still prevents that declaration from widening its authority. An absent
     * `triggers` key remains unscoped (selected for any non-empty surface
     * set), preserving what an un-triggered rebuilder meant.
     */
    /** The closed `actions[].kind` vocabulary — the two trust tiers, nothing else. */
    private const ACTION_KINDS = ['native', 'provider'];

    private static function validate_actions(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        if (array_key_exists('rebuilders', $manifest)) {
            throw new \RuntimeException(
                "duo: manifest '$name' declares the retired free-form `rebuilders` channel; "
                . 'migrate to structured `actions` (native or provider) — see spec/repo-format.md'
            );
        }
        if (!array_key_exists('actions', $manifest)) {
            return;
        }
        $actions = $manifest['actions'];
        if (!is_array($actions) || !array_is_list($actions)) {
            throw new \RuntimeException("duo: manifest '$name' actions must be a list");
        }
        $providers = [];
        foreach ((array) ($manifest['providers'] ?? []) as $declaration) {
            if (is_array($declaration) && is_string($declaration['id'] ?? null)) {
                $providers[$declaration['id']] = $declaration;
            }
        }
        foreach ($actions as $i => $action) {
            $where = "manifest '$name' actions[$i]";
            if (!is_array($action) || array_is_list($action)) {
                throw new \RuntimeException("duo: $where must be an object");
            }
            $kind = $action['kind'] ?? null;
            if (!in_array($kind, self::ACTION_KINDS, true)) {
                throw new \RuntimeException("duo: $where.kind must be \"native\" or \"provider\"");
            }
            // `effects` is optional; it is in the allowed set so the effect
            // validator can supply its normal explicit irreversible fallback
            // when omitted, exactly as the retired channel did.
            $allowed = $kind === 'native'
                ? ['action', 'args', 'effects', 'kind', 'triggers']
                : ['args', 'capability', 'effects', 'kind', 'provider', 'triggers'];
            $keys = array_keys($action);
            sort($keys, SORT_STRING);
            $unknown = array_diff($keys, $allowed);
            if ($unknown !== []) {
                throw new \RuntimeException(
                    "duo: $where contains unknown key(s): " . implode(', ', $unknown)
                );
            }
            $declaredArgs = $action['args'] ?? null;
            // `{}` decodes to an empty PHP array, which array_is_list() calls
            // a list — an argument-free action must stay expressible.
            if (!is_array($declaredArgs) || (array_is_list($declaredArgs) && $declaredArgs !== [])) {
                throw new \RuntimeException("duo: $where.args must be an object");
            }
            if ($kind === 'native') {
                if (!is_string($action['action'] ?? null)) {
                    throw new \RuntimeException("duo: $where.action must be a string");
                }
                NativeActions::validate((string) $action['action'], $action['args'], "$where");
            } else {
                self::validate_provider_action($action, $providers, $where, $name);
            }
            if (!array_key_exists('triggers', $action)) {
                continue;
            }
            $triggers = $action['triggers'];
            if (!is_array($triggers) || !array_is_list($triggers) || $triggers === []) {
                throw new \RuntimeException("duo: $where.triggers must be a non-empty list");
            }
            $seen = [];
            foreach ($triggers as $triggerIndex => $trigger) {
                if (!is_string($trigger) || preg_match(self::SURFACE_PATTERN, $trigger) !== 1) {
                    throw new \RuntimeException(
                        "duo: $where.triggers[$triggerIndex] must be one exact canonical surface "
                        . '(post|term|table|option|entity):<lowercase-name>'
                    );
                }
                if (isset($seen[$trigger])) {
                    throw new \RuntimeException("duo: $where.triggers repeats exact surface '$trigger'");
                }
                $seen[$trigger] = true;
            }
        }
    }

    /**
     * The provider grammar's offline bounds. A capability name and a provider
     * argument key share one pattern deliberately: both are keys in the
     * provider's own declared schema, so a name legal in a declaration and
     * illegal in the action that reaches it would be a grammar with two
     * spellings. Consts rather than inline literals for the same reason as
     * EFFECT_KINDS — closed_vocabularies() publishes exactly what refuses.
     */
    private const PROVIDER_ID_PATTERN = '/^[a-z][a-z0-9-]{0,63}$/D';
    /** @see PROVIDER_ID_PATTERN */
    private const PROVIDER_VERSION_PATTERN = '/^[0-9]+\.[0-9]+\.[0-9]+$/D';
    /** @see PROVIDER_ID_PATTERN */
    private const CAPABILITY_NAME_PATTERN = '/^[a-z0-9_]{1,64}$/D';
    /** @see PROVIDER_ID_PATTERN */
    private const PROVIDER_SOURCES = ['manifest', 'plugin'];

    /**
     * The load-time half of a provider-kind action's contract.
     *
     * A manifest is data, so this is everything checkable without the
     * provider's code: the referenced provider is declared by THIS manifest
     * (a manifest may not reach into another pinned adapter's provider — that
     * would make one adapter's behavior depend on another's pin), the
     * capability name is one this manifest's declaration advertises, and the
     * arguments are a flat structure of scalars, scalar lists, or (DUO-3369)
     * lists of flat objects whose own values are scalars. That last bound is
     * what keeps an argument from carrying a nested payload a provider might
     * interpret as code: the depth is fixed at exactly one level HERE, where
     * no provider code exists yet, while the provider's own declared arg
     * schema completes the check at negotiation time, when the schema exists.
     * The two gates answer different questions on purpose — this one bounds
     * the SHAPE a manifest may carry at all, negotiation bounds which fields
     * this particular capability accepts and of what type — so an object list
     * reaching a provider has passed both.
     *
     * @param array<string, array<string,mixed>> $providers this manifest's declarations, keyed by id
     */
    private static function validate_provider_action(
        array $action,
        array $providers,
        string $where,
        string $manifestName
    ): void {
        $id = $action['provider'] ?? null;
        if (!is_string($id) || !isset($providers[$id])) {
            throw new \RuntimeException(
                "duo: $where.provider must name a `providers` entry declared by manifest '$manifestName'"
            );
        }
        $capability = $action['capability'] ?? null;
        if (!is_string($capability) || preg_match(self::CAPABILITY_NAME_PATTERN, $capability) !== 1) {
            throw new \RuntimeException("duo: $where.capability must match ^[a-z0-9_]{1,64}$");
        }
        if (!in_array($capability, (array) ($providers[$id]['capabilities'] ?? []), true)) {
            throw new \RuntimeException(
                "duo: $where.capability '$capability' is not listed in provider '$id' declaration's capabilities"
            );
        }
        foreach ((array) $action['args'] as $key => $value) {
            if (!is_string($key) || preg_match(self::CAPABILITY_NAME_PATTERN, $key) !== 1) {
                throw new \RuntimeException("duo: $where.args keys must match ^[a-z0-9_]{1,64}$");
            }
            // The wording keeps the pre-DUO-3369 sentence intact (existing
            // refusal coverage matches on it) and states the one shape that
            // was added, rather than describing a looser rule than the code.
            $shapeRefusal = "duo: $where.args.$key must be a scalar or a list of scalars"
                . ' (or a list of flat objects whose own values are scalars — a provider argument nests'
                . ' exactly one level)';
            if (is_array($value)) {
                if (!array_is_list($value)) {
                    throw new \RuntimeException($shapeRefusal);
                }
                foreach ($value as $index => $member) {
                    if (is_scalar($member)) {
                        continue;
                    }
                    // An empty array is both a list and a map to PHP; read it
                    // as an empty object, since a row whose fields are all
                    // optional is a legitimate thing for a manifest to write.
                    if (!is_array($member) || (array_is_list($member) && $member !== [])) {
                        throw new \RuntimeException($shapeRefusal);
                    }
                    foreach ($member as $field => $fieldValue) {
                        if (!is_string($field) || preg_match('/^[a-z0-9_]{1,64}$/D', $field) !== 1) {
                            throw new \RuntimeException(
                                "duo: $where.args.$key row $index field names must match ^[a-z0-9_]{1,64}$"
                            );
                        }
                        if (!is_scalar($fieldValue)) {
                            throw new \RuntimeException(
                                "duo: $where.args.$key row $index field '$field' must be a scalar — "
                                . 'a provider argument nests exactly one level'
                            );
                        }
                    }
                }
                continue;
            }
            if (!is_scalar($value)) {
                throw new \RuntimeException($shapeRefusal);
            }
        }
    }

    /**
     * Validate one manifest's `providers` declarations.
     *
     * A declaration is an identity assertion about executable code the engine
     * does not own: which package supplies it (`source`), which plugin owns
     * the semantics (`plugin`), which exact provider version the manifest was
     * authored against, and the closed set of capability names actions may
     * reference. Everything here is checkable offline; whether the code is
     * actually present, matches this identity, and advertises these
     * capabilities is negotiated against the live environment before any
     * mutation (Providers::negotiate()).
     *
     * `plugin` must agree with the manifest's own `plugin` claim when it has
     * one: a manifest already declares exactly one plugin plus the version
     * range its classification guarantees hold for (validate_adapter_contract
     * above), and a provider naming a different plugin would silently escape
     * that version-bounded claim.
     */
    private static function validate_providers(array $manifest): void {
        if (!array_key_exists('providers', $manifest)) {
            return;
        }
        $name = (string) ($manifest['name'] ?? '?');
        $providers = $manifest['providers'];
        if (!is_array($providers) || !array_is_list($providers)) {
            throw new \RuntimeException("duo: manifest '$name' providers must be a list");
        }
        $seenIds = [];
        foreach ($providers as $i => $declaration) {
            $where = "manifest '$name' providers[$i]";
            if (!is_array($declaration) || array_is_list($declaration)) {
                throw new \RuntimeException("duo: $where must be an object");
            }
            $keys = array_keys($declaration);
            sort($keys, SORT_STRING);
            if ($keys !== ['capabilities', 'id', 'plugin', 'source', 'version']) {
                throw new \RuntimeException(
                    "duo: $where requires exactly capabilities, id, plugin, source, and version"
                );
            }
            $id = $declaration['id'];
            if (!is_string($id) || preg_match(self::PROVIDER_ID_PATTERN, $id) !== 1) {
                throw new \RuntimeException("duo: $where.id must match ^[a-z][a-z0-9-]{0,63}$");
            }
            if (isset($seenIds[$id])) {
                throw new \RuntimeException("duo: manifest '$name' declares provider id '$id' more than once");
            }
            $seenIds[$id] = true;
            if (!is_string($declaration['version'])
                || preg_match(self::PROVIDER_VERSION_PATTERN, $declaration['version']) !== 1) {
                throw new \RuntimeException(
                    "duo: $where.version must be an exact <major>.<minor>.<patch> string"
                );
            }
            if (!in_array($declaration['source'], self::PROVIDER_SOURCES, true)) {
                throw new \RuntimeException("duo: $where.source must be \"manifest\" or \"plugin\"");
            }
            $plugin = AdapterSources::assert_plugin_basename($declaration['plugin'], "$where.plugin");
            $manifestPlugin = $manifest['plugin'] ?? null;
            if (is_string($manifestPlugin) && $manifestPlugin !== '' && $manifestPlugin !== $plugin) {
                throw new \RuntimeException(
                    "duo: $where.plugin '$plugin' disagrees with manifest '$name' plugin '$manifestPlugin' — "
                    . "a provider's owning plugin must be the plugin whose version_range bounds this adapter"
                );
            }
            $capabilities = $declaration['capabilities'];
            if (!is_array($capabilities) || !array_is_list($capabilities) || $capabilities === []) {
                throw new \RuntimeException("duo: $where.capabilities must be a non-empty list");
            }
            $seenCapabilities = [];
            foreach ($capabilities as $j => $capability) {
                if (!is_string($capability) || preg_match(self::CAPABILITY_NAME_PATTERN, $capability) !== 1) {
                    throw new \RuntimeException("duo: $where.capabilities[$j] must match ^[a-z0-9_]{1,64}$");
                }
                if (isset($seenCapabilities[$capability])) {
                    throw new \RuntimeException("duo: $where.capabilities repeats '$capability'");
                }
                $seenCapabilities[$capability] = true;
            }
        }
    }

    /**
     * Cross-manifest guard, run once after every pinned manifest has loaded —
     * the provider twin of validate_no_conflicting_adapter_claims() above,
     * with the same rationale: a provider id resolves to concrete executable
     * code (a manifests/providers/<id>.php file, or a `duo_providers`
     * registration), so two pinned manifests claiming one id makes which code
     * runs depend on pin order. There is no composition grammar in v1;
     * rename one of the ids.
     *
     * @param list<array<string,mixed>> $manifests
     */
    private static function validate_no_conflicting_provider_ids(array $manifests): void {
        $seen = [];
        foreach ($manifests as $m) {
            $name = (string) ($m['name'] ?? '?');
            foreach ((array) ($m['providers'] ?? []) as $declaration) {
                $id = (string) ($declaration['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                if (isset($seen[$id])) {
                    throw new \RuntimeException(
                        "duo: manifests '{$seen[$id]}' and '$name' both declare provider id '$id' — "
                        . 'a provider id names one concrete implementation and may not depend on pin order; '
                        . 'rename one declaration'
                    );
                }
                $seen[$id] = $name;
            }
        }
    }

    /** Validate the bounded reversibility grammar without target contact. */
    private static function validate_effect_contracts(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        $groups = [];
        if (array_key_exists('lifecycle_effects', $manifest)) {
            $groups['lifecycle_effects'] = $manifest['lifecycle_effects'];
        }
        foreach ((array) ($manifest['actions'] ?? []) as $i => $action) {
            if (is_array($action) && array_key_exists('effects', $action)) {
                $groups["actions[$i].effects"] = $action['effects'];
            }
        }
        foreach ((array) ($manifest['post_types'] ?? []) as $postType => $declaration) {
            $regen = is_array($declaration) ? ($declaration['regen_dependency'] ?? null) : null;
            if (is_array($regen) && array_key_exists('effects', $regen)) {
                $groups["post_types.$postType.regen_dependency.effects"] = $regen['effects'];
            }
        }
        $seen = [];
        foreach ($groups as $where => $effects) {
            if (!is_array($effects) || !array_is_list($effects) || $effects === []) {
                throw new \RuntimeException("duo: manifest '$name' $where must be a non-empty list");
            }
            foreach ($effects as $i => $effect) {
                self::validate_effect($effect, "manifest '$name' {$where}[$i]");
                $id = (string) $effect['id'];
                if (isset($seen[$id])) {
                    throw new \RuntimeException("duo: manifest '$name' repeats effect id '$id' in {$where}[$i] and {$seen[$id]}");
                }
                $seen[$id] = "{$where}[$i]";
            }
        }
    }

    /**
     * The four closed vocabularies of the effect grammar, plus the bound on an
     * effect id. They were local arrays inside validate_effect() until
     * closed_vocabularies() needed to publish them; they are consts now for the
     * same one-declaration-site reason DERIVABLE_FIELD_COLUMNS gives — a
     * published set that restated the validator's literals would be a second
     * spelling of the grammar, free to drift from the one that actually
     * refuses. Values, order, and every refusal message are unchanged.
     */
    private const EFFECT_KINDS = ['database', 'filesystem', 'schedule', 'cache', 'queue', 'mail', 'http', 'external'];
    /** @see EFFECT_KINDS */
    private const EFFECT_MODES = ['restorable', 'reversible', 'prevented', 'irreversible'];
    /** @see EFFECT_KINDS */
    private const SELECTOR_SCOPES = ['database_checkpoint', 'external'];
    /** @see EFFECT_KINDS */
    private const SELECTOR_TYPES = ['table', 'option', 'path', 'hook', 'namespace', 'queue', 'mail_subject', 'url_prefix', 'provider_resource', 'plugin_lifecycle'];
    /** @see EFFECT_KINDS */
    private const EFFECT_ID_PATTERN = '/^[a-z][a-z0-9._:-]{0,127}$/';

    private static function validate_effect(mixed $effect, string $where): void {
        if (!is_array($effect) || array_is_list($effect)) {
            throw new \RuntimeException("duo: $where must be an object");
        }
        $mode = $effect['mode'] ?? null;
        $kind = $effect['kind'] ?? null;
        $expected = ['id', 'kind', 'mode', 'selector'];
        if ($mode === 'reversible') {
            $expected[] = 'adapter';
        } elseif ($mode === 'prevented') {
            $expected[] = 'prevention';
        }
        $actual = array_keys($effect); sort($actual, SORT_STRING); sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException("duo: $where has missing or unknown fields for mode " . var_export($mode, true));
        }
        if (!is_string($effect['id'] ?? null)
            || preg_match(self::EFFECT_ID_PATTERN, (string) $effect['id']) !== 1) {
            throw new \RuntimeException("duo: $where.id must be a bounded lowercase identifier");
        }
        // DUO-3318: two closed vocabularies, two messages. One combined
        // refusal made an author guess which half they got wrong, and never
        // printed either legal set — the same declaration would be edited,
        // re-run, and refused again on the other field.
        $effectKinds = self::EFFECT_KINDS;
        $effectModes = self::EFFECT_MODES;
        if (!in_array($kind, $effectKinds, true)) {
            throw new \RuntimeException(
                "duo: $where.kind=" . var_export($kind, true) . ' is not one of the engine-owned effect kinds ('
                . implode(', ', $effectKinds) . ') — a kind names the CATEGORY of thing an effect touches, which '
                . 'the recovery controller has to understand to plan a rollback, so the set is an engine change '
                . 'with a spec bump, not a manifest declaration'
            );
        }
        if (!in_array($mode, $effectModes, true)) {
            throw new \RuntimeException(
                "duo: $where.mode=" . var_export($mode, true) . ' is not one of the engine-owned reversibility '
                . 'modes (' . implode(', ', $effectModes) . ') — a mode states how this effect is UNDONE, and each '
                . 'value binds the declaration to different required evidence (restorable: checkpoint coverage; '
                . 'reversible: a version-pinned inverse+verifier adapter; prevented: receipt-outbox isolation; '
                . 'irreversible: an explicit automatic-promotion blocker). Widening the set is an engine change '
                . 'with a spec bump'
            );
        }
        $selector = $effect['selector'] ?? null;
        if (!is_array($selector) || array_is_list($selector)) {
            throw new \RuntimeException("duo: $where.selector must be an object");
        }
        $scope = $selector['scope'] ?? null;
        $type = $selector['type'] ?? null;
        $value = $selector['value'] ?? null;
        $keys = array_keys($selector); sort($keys, SORT_STRING);
        $expectedSelectorKeys = ['scope', 'type', 'value'];
        if ($type === 'provider_resource' && array_key_exists('members', $selector)) {
            $expectedSelectorKeys[] = 'members';
        }
        sort($expectedSelectorKeys, SORT_STRING);
        if ($keys !== $expectedSelectorKeys) {
            throw new \RuntimeException(
                $type === 'provider_resource'
                    ? "duo: $where.selector requires exactly scope, type, value, and optional members"
                    : "duo: $where.selector requires exactly scope, type, and value"
            );
        }
        // DUO-3318: four independent causes used to share one message that
        // named none of them, so an author saw "empty, unbounded,
        // secret-shaped, or unsupported" for a selector that was, in fact,
        // exactly one of those — and had to bisect their own declaration to
        // find out which. Each cause now names itself and, where it is a
        // closed set, prints the set.
        $selectorScopes = self::SELECTOR_SCOPES;
        $selectorTypes = self::SELECTOR_TYPES;
        if (!in_array($scope, $selectorScopes, true)) {
            throw new \RuntimeException(
                "duo: $where.selector.scope=" . var_export($scope, true) . ' is not one of the engine-owned scopes '
                . '(' . implode(', ', $selectorScopes) . ') — the scope states whether the encrypted database '
                . 'checkpoint already covers this effect or whether it reaches outside it, which is the recovery '
                . "controller's own decision to make; the set is an engine change with a spec bump"
            );
        }
        if (!in_array($type, $selectorTypes, true)) {
            throw new \RuntimeException(
                "duo: $where.selector.type=" . var_export($type, true) . ' is not one of the engine-owned selector '
                . 'types (' . implode(', ', $selectorTypes) . ') — each type is a resource shape the engine knows '
                . 'how to bound and verify; an adapter names a resource the engine cannot bound through '
                . '`provider_resource` plus its own provider, never by minting a type'
            );
        }
        if (!is_string($value) || $value === '' || strlen($value) > 512) {
            throw new \RuntimeException(
                "duo: $where.selector.value must be a non-empty string of at most 512 bytes (got "
                . (is_string($value) ? strlen($value) . ' bytes' : gettype($value)) . ')'
            );
        }
        if (preg_match('/[\x00-\x1f\x7f*]/', $value) === 1) {
            throw new \RuntimeException(
                "duo: $where.selector.value " . var_export($value, true) . ' contains a wildcard or control '
                . 'character — a selector names exact resources, because rollback authority is bounded by what '
                . 'the declaration can enumerate; use selector.members to declare an aggregate instead'
            );
        }
        if (preg_match('/secret|credential|password|authorization|signed.?url|access.?token|api.?key/i', $value) === 1) {
            throw new \RuntimeException(
                "duo: $where.selector.value " . var_export($value, true) . ' is secret-shaped — a selector travels '
                . 'into receipts and diagnostics, so a value naming a credential surface is refused rather than '
                . 'recorded'
            );
        }
        if ($type === 'provider_resource' && array_key_exists('members', $selector)) {
            self::validate_provider_resource_members($selector['members'], $value, "$where.selector.members");
        }
        if ($type === 'path' && (str_starts_with($value, '/') || str_contains($value, '\\')
            || in_array('.', explode('/', $value), true) || in_array('..', explode('/', $value), true))) {
            throw new \RuntimeException("duo: $where.selector path must be relative and traversal-free");
        }
        if ($type === 'url_prefix' && (!str_starts_with($value, 'https://') || str_contains($value, '?'))) {
            throw new \RuntimeException("duo: $where.selector url_prefix must be bounded HTTPS without a query string");
        }
        if ($kind === 'database' && ($scope !== 'database_checkpoint' || !in_array($type, ['table', 'option'], true))) {
            throw new \RuntimeException("duo: $where database effects must name a checkpoint-covered table or option");
        }
        if ($kind !== 'database' && $scope !== 'external') {
            throw new \RuntimeException("duo: $where non-database effects must be explicitly external");
        }
        if ($mode === 'restorable' && $scope !== 'database_checkpoint') {
            throw new \RuntimeException("duo: $where restorable effects require database_checkpoint coverage");
        }
        if ($mode === 'prevented') {
            if (!in_array($kind, ['mail', 'http', 'queue'], true) || ($effect['prevention'] ?? null) !== 'receipt_outbox') {
                throw new \RuntimeException("duo: $where prevented effects require mail/http/queue receipt_outbox isolation");
            }
        }
        if ($mode === 'reversible') {
            self::validate_effect_adapter($effect['adapter'] ?? null, "$where.adapter");
        }
        if ($type === 'plugin_lifecycle' && $mode !== 'irreversible') {
            throw new \RuntimeException("duo: $where plugin_lifecycle is an honest unsupported selector and must be irreversible");
        }
    }

    /** The closed typed-placeholder vocabulary a provider-resource template may use. */
    private const MEMBER_PLACEHOLDERS = ['positive_uint', 'slug'];

    /**
     * Validate a declarative provider-resource aggregate without knowing the
     * provider. Exact members are literal concrete resources; templates may
     * use only the two core bounded placeholder types. Runtime reconciliation
     * expands these same templates against one concrete selector value.
     */
    private static function validate_provider_resource_members(mixed $members, string $aggregate, string $where): void {
        if (!is_array($members) || array_is_list($members)) {
            throw new \RuntimeException("duo: $where must be an object");
        }
        $keys = array_keys($members); sort($keys, SORT_STRING);
        if ($keys !== ['exact', 'templates']) {
            throw new \RuntimeException("duo: $where requires exactly exact and templates lists");
        }
        foreach (['exact', 'templates'] as $key) {
            if (!is_array($members[$key]) || !array_is_list($members[$key])) {
                throw new \RuntimeException("duo: $where.$key must be a list");
            }
        }
        if ($members['exact'] === [] && $members['templates'] === []) {
            throw new \RuntimeException("duo: $where must declare at least one exact member or template");
        }
        $seen = [];
        foreach ($members['exact'] as $i => $member) {
            if (!is_string($member) || $member === '' || strlen($member) > 512
                || $member === $aggregate
                || preg_match('/[\x00-\x1f\x7f*?<>{}]/', $member) === 1
                || preg_match('/secret|credential|password|authorization|signed.?url|access.?token|api.?key/i', $member) === 1) {
                throw new \RuntimeException("duo: $where.exact[$i] is malformed, broad, or secret-shaped");
            }
            $identity = 'exact:' . $member;
            if (isset($seen[$identity])) {
                throw new \RuntimeException("duo: $where contains duplicate member '$member'");
            }
            $seen[$identity] = true;
        }
        foreach ($members['templates'] as $i => $template) {
            if (!is_string($template) || $template === '' || strlen($template) > 512
                || preg_match('/[\x00-\x1f\x7f*?<>]/', $template) === 1
                || preg_match('/secret|credential|password|authorization|signed.?url|access.?token|api.?key/i', $template) === 1) {
                throw new \RuntimeException("duo: $where.templates[$i] is malformed, broad, or secret-shaped");
            }
            $placeholderCount = 0;
            preg_match_all('/\{([^{}]*)\}/', $template, $matches, PREG_OFFSET_CAPTURE);
            $cursor = 0;
            foreach ($matches[0] as $matchIndex => $wholeMatch) {
                $offset = (int) $wholeMatch[1];
                $literal = substr($template, $cursor, $offset - $cursor);
                if (str_contains($literal, '{') || str_contains($literal, '}')) {
                    throw new \RuntimeException("duo: $where.templates[$i] has unmatched braces");
                }
                $placeholder = (string) ($matches[1][$matchIndex][0] ?? '');
                if (!in_array($placeholder, self::MEMBER_PLACEHOLDERS, true)) {
                    // DUO-3318: naming the offending placeholder matters more
                    // here than almost anywhere else — a template may carry
                    // several, so "has an unknown placeholder" left an author
                    // reading a 512-byte string looking for which one.
                    throw new \RuntimeException(
                        "duo: $where.templates[$i] has an unknown placeholder '{" . $placeholder . '}\' — the '
                        . 'placeholder vocabulary is closed and engine-owned ({'
                        . implode('}, {', self::MEMBER_PLACEHOLDERS) . '}), because runtime reconciliation has to '
                        . 'expand a template into exact members it can verify; a new placeholder type is an engine '
                        . 'change with a spec bump, not a manifest declaration'
                    );
                }
                $placeholderCount++;
                $cursor = $offset + strlen((string) $wholeMatch[0]);
            }
            if (str_contains(substr($template, $cursor), '{') || str_contains(substr($template, $cursor), '}')) {
                throw new \RuntimeException("duo: $where.templates[$i] has unmatched braces");
            }
            if ($placeholderCount === 0) {
                throw new \RuntimeException("duo: $where.templates[$i] must contain a typed placeholder");
            }
            $identity = 'template:' . $template;
            if (isset($seen[$identity])) {
                throw new \RuntimeException("duo: $where contains duplicate template '$template'");
            }
            $seen[$identity] = true;
        }
    }

    private static function validate_effect_adapter(mixed $adapter, string $where): void {
        if (!is_array($adapter) || array_is_list($adapter)) {
            throw new \RuntimeException("duo: $where must be an object");
        }
        $keys = array_keys($adapter); sort($keys, SORT_STRING);
        $expected = ['id', 'inverse', 'inverse_inputs', 'verifier', 'verifier_inputs', 'version'];
        if ($keys !== $expected) {
            throw new \RuntimeException("duo: $where requires version-pinned inverse and verifier inputs");
        }
        foreach (['id', 'inverse', 'verifier'] as $key) {
            if (!is_string($adapter[$key]) || preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $adapter[$key]) !== 1) {
                throw new \RuntimeException("duo: $where.$key is malformed");
            }
        }
        if (!is_string($adapter['version'])
            || preg_match('/^[0-9]+(?:\.[0-9A-Za-z-]+)+$/', $adapter['version']) !== 1) {
            throw new \RuntimeException("duo: $where.version must be exact, never latest/wildcard/unbounded");
        }
        foreach (['inverse_inputs', 'verifier_inputs'] as $key) {
            $inputs = $adapter[$key];
            if (!is_array($inputs) || !array_is_list($inputs) || $inputs === []
                || count(array_unique($inputs)) !== count($inputs)) {
                throw new \RuntimeException("duo: $where.$key must be a non-empty unique input list");
            }
            foreach ($inputs as $input) {
                if (!is_string($input) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $input) !== 1
                    || preg_match('/secret|credential|password|authorization|token|api_?key/i', $input) === 1) {
                    throw new \RuntimeException("duo: $where.$key contains a malformed receipt input name");
                }
            }
        }
    }

    /**
     * docs/proposals/code-half.md §4.3's version_range mechanism: a manifest
     * may declare a top-level `"plugin"` (the plugin's basename, e.g.
     * "woocommerce/woocommerce.php" — the same string active_plugins/
     * get_plugins() key on) alongside `"version_range": {"min","max"}`
     * (min inclusive, max exclusive). Deliberately {min,max} + two
     * version_compare() calls, not a semver-range constraint string: the
     * agent is dependency-free (DESIGN.md §4 — "a drop-in agent must not
     * vendor libraries"), and a real semver-range parser is exactly the
     * dependency that rules out. First declaration in pin order wins per
     * plugin — same precedence as block_attr_rules(); in practice
     * validate_no_conflicting_adapter_claims() has already refused two
     * pinned manifests naming one plugin with different ranges, so this
     * accessor never actually arbitrates.
     *
     * Deploy::code_mismatch() / Apply::build_plan()'s code_mismatch bucket
     * and DUO-3338's provider negotiation (Providers::negotiate(), which
     * bounds a plugin-owned provider by the same declared range that bounds
     * its manifest's classification guarantees) are this accessor's readers.
     *
     * @return array<string, array{min:string, max:string, manifest:string}> keyed by plugin basename
     */
    public function version_ranges(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            $plugin = $m['plugin'] ?? null;
            $range = $m['version_range'] ?? null;
            if (!is_string($plugin) || $plugin === '' || !is_array($range) || isset($out[$plugin])) {
                continue;
            }
            $out[$plugin] = [
                'min' => (string) ($range['min'] ?? '0'),
                'max' => (string) ($range['max'] ?? '999999999'),
                'manifest' => (string) ($m['name'] ?? '?'),
            ];
        }
        return $out;
    }

    /**
     * DUO-3222: theme twin of version_ranges() above — same {min,max} +
     * version_compare() shape, same first-pin-order-wins internal fallback
     * (never actually exercised in practice: validate_no_conflicting_
     * adapter_claims() at load time already refuses two pinned manifests
     * naming the same theme with different ranges, so this accessor's only
     * reader — Deploy::code_mismatch() — always sees a pre-validated,
     * unambiguous answer by the time it asks). Deliberately theme-
     * directory-keyed (not template/stylesheet-slot-keyed), for the same
     * reason version_ranges() is plugin-basename-keyed rather than
     * active_plugins-index-keyed — the CONTRACT is about an installed
     * artifact's identity, not which options field happens to name it on a
     * given environment; a manifest pinning a parent theme applies equally
     * whether that theme is loaded via `template` or `stylesheet`.
     *
     * @return array<string, array{min:string, max:string, manifest:string}> keyed by theme directory name
     */
    public function theme_ranges(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            $theme = $m['theme'] ?? null;
            $range = $m['theme_version_range'] ?? null;
            if (!is_string($theme) || $theme === '' || !is_array($range) || isset($out[$theme])) {
                continue;
            }
            $out[$theme] = [
                'min' => (string) ($range['min'] ?? '0'),
                'max' => (string) ($range['max'] ?? '999999999'),
                'manifest' => (string) ($m['name'] ?? '?'),
            ];
        }
        return $out;
    }

    /**
     * Version-1 deletion capabilities are exact entity selectors
     * (`post:page`, `term:category`, `menu:nav_menu`, or
     * `table:nf3_forms`). Multiple pinned manifests may add guards to the
     * same selector, but their cascade contract must agree exactly.
     *
     * @return ?array{cascades:string[],guards:array<int,array<string,mixed>>,declared_by:string[]}
     */
    public function deletion_capability(string $selector): ?array {
        $out = null;
        foreach ($this->manifests as $manifest) {
            $decl = $manifest['deletions'][$selector] ?? null;
            if ($decl === null) {
                continue;
            }
            if (!is_array($decl) || !isset($decl['cascades']) || !is_array($decl['cascades'])) {
                throw new \RuntimeException("duo: manifest deletion capability '$selector' must declare a cascades list");
            }
            $cascades = array_values(array_unique(array_map('strval', $decl['cascades'])));
            sort($cascades, SORT_STRING);
            $guards = $decl['guards'] ?? [];
            if (!is_array($guards) || !array_is_list($guards)) {
                throw new \RuntimeException("duo: manifest deletion capability '$selector' guards must be a list");
            }
            foreach ($guards as $i => $guard) {
                if (!is_array($guard)
                    || !preg_match('/^[A-Za-z0-9_]+$/', (string) ($guard['table'] ?? ''))
                    || !preg_match('/^[A-Za-z0-9_]+$/', (string) ($guard['column'] ?? ''))
                    || !preg_match('/^[a-z][a-z0-9_]*$/', (string) ($guard['id_kind'] ?? ''))) {
                    throw new \RuntimeException(
                        "duo: manifest deletion capability '$selector' guard[$i] must declare table, column, and id_kind"
                    );
                }
                $hasMetaKey = array_key_exists('meta_key', $guard);
                $hasMetaRef = array_key_exists('ref', $guard);
                if ($hasMetaKey !== $hasMetaRef) {
                    throw new \RuntimeException(
                        "duo: manifest deletion capability '$selector' guard[$i] metadata guards must declare meta_key and ref together"
                    );
                }
                $hasOptionNameRef = array_key_exists('option_name_ref', $guard);
                if ($hasOptionNameRef && $guard['option_name_ref'] !== true) {
                    throw new \RuntimeException(
                        "duo: manifest deletion capability '$selector' guard[$i].option_name_ref must be true when declared"
                    );
                }
                if ($hasOptionNameRef && ($hasMetaKey || $hasMetaRef)) {
                    throw new \RuntimeException(
                        "duo: manifest deletion capability '$selector' guard[$i] cannot combine option_name_ref with a metadata guard"
                    );
                }
                if ($hasOptionNameRef
                    && ((string) $guard['table'] !== 'options' || (string) $guard['column'] !== 'option_name')) {
                    throw new \RuntimeException(
                        "duo: manifest deletion capability '$selector' guard[$i].option_name_ref must target options.option_name"
                    );
                }
                if ($hasOptionNameRef) {
                    if (isset($guard['source_id_kind'], $guard['source_pk'])
                        || !empty($guard['where'])
                        || !empty($guard['exclude_where'])) {
                        throw new \RuntimeException(
                            "duo: manifest deletion capability '$selector' guard[$i].option_name_ref cannot declare table-row source or predicate qualifiers"
                        );
                    }
                    $hasRule = false;
                    foreach ($this->option_name_ref_rules() as $optionNameRule) {
                        if ((string) ($optionNameRule['id_kind'] ?? '') === (string) $guard['id_kind']
                            && ($optionNameRule['class'] ?? '') === 'authored') {
                            $hasRule = true;
                            break;
                        }
                    }
                    if (!$hasRule) {
                        throw new \RuntimeException(
                            "duo: manifest deletion capability '$selector' guard[$i].option_name_ref has no loaded authored option_name_refs rule for id_kind '{$guard['id_kind']}'"
                        );
                    }
                }
                if ($hasOptionNameRef
                    && isset($guard['identity_column'])
                    && !preg_match('/^[A-Za-z0-9_]+$/', (string) $guard['identity_column'])) {
                    throw new \RuntimeException(
                        "duo: manifest deletion capability '$selector' guard[$i].identity_column must be a column name"
                    );
                }
                if ($hasMetaKey) {
                    if ((string) $guard['table'] !== 'postmeta'
                        || !preg_match('/^[A-Za-z0-9_]+$/', (string) $guard['meta_key'])
                        || !preg_match('/^[a-z][a-z0-9_]*(?:\[\])?$/', (string) $guard['ref'])
                        || rtrim((string) $guard['ref'], '[]') !== (string) $guard['id_kind']) {
                        throw new \RuntimeException(
                            "duo: manifest deletion capability '$selector' guard[$i] metadata guard must target postmeta with a ref matching id_kind"
                        );
                    }
                    if (!isset($guard['source_id_kind'], $guard['source_pk'])
                        || !preg_match('/^[a-z][a-z0-9_]*$/', (string) $guard['source_id_kind'])
                        || !preg_match('/^[A-Za-z0-9_]+$/', (string) $guard['source_pk'])) {
                        throw new \RuntimeException(
                            "duo: manifest deletion capability '$selector' guard[$i] metadata guard must declare source_id_kind and source_pk for owner exclusion"
                        );
                    }
                    if (isset($guard['cast']) && !in_array((string) $guard['cast'], self::CASTS, true)) {
                        throw new \RuntimeException(
                            "duo: manifest deletion capability '$selector' guard[$i].cast must be string or csv"
                        );
                    }
                    if (isset($guard['identity_column'])
                        && !preg_match('/^[A-Za-z0-9_]+$/', (string) $guard['identity_column'])) {
                        throw new \RuntimeException(
                            "duo: manifest deletion capability '$selector' guard[$i].identity_column must be a column name"
                        );
                    }
                }
                foreach (['where', 'exclude_where'] as $predicate) {
                    $values = $guard[$predicate] ?? [];
                    if (!is_array($values) || (isset($guard[$predicate]) && array_is_list($values))) {
                        throw new \RuntimeException(
                            "duo: manifest deletion capability '$selector' guard[$i].$predicate must be an object"
                        );
                    }
                    foreach ($values as $column => $value) {
                        if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $column)
                            || (!is_string($value) && !is_int($value))) {
                            throw new \RuntimeException(
                                "duo: manifest deletion capability '$selector' guard[$i].$predicate must contain scalar column predicates"
                            );
                        }
                    }
                }
                $hasSourceKind = isset($guard['source_id_kind']);
                $hasSourcePk = isset($guard['source_pk']);
                if ($hasSourceKind !== $hasSourcePk
                    || ($hasSourceKind && !preg_match('/^[a-z][a-z0-9_]*$/', (string) $guard['source_id_kind']))
                    || ($hasSourcePk && !preg_match('/^[A-Za-z0-9_]+$/', (string) $guard['source_pk']))) {
                    throw new \RuntimeException(
                        "duo: manifest deletion capability '$selector' guard[$i] must declare source_id_kind and source_pk together"
                    );
                }
            }
            $source = (string) ($manifest['name'] ?? '?');
            if ($out === null) {
                $out = ['cascades' => $cascades, 'guards' => [], 'declared_by' => []];
            } elseif ($out['cascades'] !== $cascades) {
                throw new \RuntimeException(
                    "duo: pinned manifests disagree on cascade effects for deletion capability '$selector'"
                );
            }
            $out['guards'] = array_merge($out['guards'], $guards);
            $out['declared_by'][] = $source;
        }
        return $out;
    }

    private const SECTIONS = ['options', 'post_meta', 'term_meta', 'user_meta'];
    private const CLASSES = ['authored', 'runtime', 'derived', 'env', 'managed'];
    private const SCOPE_CLASSES = ['authored', 'runtime', 'derived', 'env'];
    private const CASTS = ['string', 'csv'];

    /**
     * Write one classification rule into site.duo.json's policy overrides
     * (`wp duo classify`'s only write path — DESIGN.md 3.1.5: "accepted
     * decisions persist to policy.yml/json"). Validates shape, then loads +
     * rewrites the file via Canon::encode so formatting stays canonical.
     */
    public static function set_rule(string $repo, string $section, string $key, array $rule): void {
        if ($section === 'scope') {
            if (!preg_match('/^(post_type|taxonomy):(.+)$/', $key, $m)) {
                throw new \RuntimeException(
                    "duo: scope key '$key' must be post_type:<name> or taxonomy:<name>"
                );
            }
            $class = $rule['class'] ?? '';
            if (!in_array($class, self::SCOPE_CLASSES, true)) {
                throw new \RuntimeException(
                    "duo: unknown scope class '$class' (expected " . implode('|', self::SCOPE_CLASSES) . ')'
                );
            }
            if (array_diff_key($rule, ['class' => true])) {
                throw new \RuntimeException('duo: scope rules accept class only (no ref, cast, or secret override)');
            }
            $siteFile = rtrim($repo, '/') . '/site.duo.json';
            if (!is_file($siteFile)) {
                throw new \RuntimeException("duo: $siteFile not found (not a duo site repo?)");
            }
            $site = Canon::decode(Canon::read_file($siteFile));
            $site['policy']['scope'][$m[1]][$m[2]] = $rule;
            Canon::write_file($siteFile, Canon::encode($site));
            return;
        }
        if (!in_array($section, self::SECTIONS, true)) {
            throw new \RuntimeException(
                'duo: unknown policy section \'' . $section . '\' (expected '
                . implode('|', array_merge(self::SECTIONS, ['scope'])) . ')'
            );
        }
        if ($key === '') {
            throw new \RuntimeException('duo: policy key must not be empty');
        }
        $class = $rule['class'] ?? '';
        if (!in_array($class, self::CLASSES, true)) {
            throw new \RuntimeException(
                "duo: unknown class '$class' (expected " . implode('|', self::CLASSES) . ')'
            );
        }
        if (isset($rule['ref']) && !preg_match('/^(post|term|user)(\[\])?$/', (string) $rule['ref'])) {
            throw new \RuntimeException(
                "duo: invalid ref '{$rule['ref']}' (expected post|term|user, optionally suffixed with [])"
            );
        }
        if (isset($rule['cast']) && !in_array($rule['cast'], self::CASTS, true)) {
            throw new \RuntimeException("duo: invalid cast '{$rule['cast']}' (expected " . implode('|', self::CASTS) . ')');
        }
        if (isset($rule['allow_secret']) && !is_bool($rule['allow_secret'])) {
            throw new \RuntimeException('duo: allow_secret must be a boolean');
        }
        if ($section === 'user_meta') {
            self::validate_user_meta_rule($rule, "user_meta.$key");
        } elseif (isset($rule['allow_pii']) || isset($rule['missing_user'])) {
            throw new \RuntimeException('duo: allow_pii and missing_user are valid only for user_meta rules');
        }

        $siteFile = rtrim($repo, '/') . '/site.duo.json';
        if (!is_file($siteFile)) {
            throw new \RuntimeException("duo: $siteFile not found (not a duo site repo?)");
        }
        $site = Canon::decode(Canon::read_file($siteFile));
        $site['policy'][$section][$key] = $rule;
        Canon::write_file($siteFile, Canon::encode($site));
    }

    /**
     * Draft-manifest export (DESIGN.md 3.1.5: "accepted decisions ...
     * shareable upstream as draft manifests"): every rule in THIS site's own
     * policy overrides (not inherited manifest rules — the human is
     * promoting decisions they made) whose key matches $matchRegex, grouped
     * into a manifest-shaped {name, options, post_meta, term_meta, user_meta}
     * structure. Reads site.duo.json; never writes it — promotion is a
     * deliberate, separate human act (`wp duo policy-to-manifest` only
     * prints to stdout).
     */
    public static function export_manifest(string $repo, string $matchRegex, string $name): array {
        $policy = self::load($repo);
        $sitePolicy = $policy->site['policy'] ?? [];

        // DUO-3247 made spec_version mandatory at load() — sourced from the
        // canonical constant, never a literal, so this can never drift out
        // of sync with what load() actually requires the way it silently
        // did before (this export wrote no spec_version at all until
        // DUO-3284 caught it live: an exported manifest the engine's own
        // loader refused, found via a sandbox/tests/ run that finally
        // exercised the full export-then-reload path).
        $specVersion = defined('DUO_SPEC_VERSION') ? DUO_SPEC_VERSION : 0;
        $out = ['name' => $name, 'spec_version' => $specVersion, 'options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []];
        foreach (self::SECTIONS as $section) {
            foreach ($sitePolicy[$section] ?? [] as $key => $rule) {
                $matched = @preg_match('/' . $matchRegex . '/', $key);
                if ($matched === false) {
                    throw new \RuntimeException("duo: invalid --match regex '$matchRegex'");
                }
                if ($matched === 1) {
                    $out[$section][$key] = $rule;
                }
            }
            $out[$section] = (object) $out[$section]; // force {} not [] when empty, matching manifest style
        }
        return $out;
    }

    /**
     * The closed VALUE vocabularies this class refuses against — the legal
     * values of a declared field — keyed by the grammar name an adapter author
     * sees (DUO-3327).
     *
     * Bounded on purpose, and the boundary is published with the document (see
     * ManifestValidate::emitSchema()'s `coverage` field) rather than left for a
     * consumer to discover:
     *
     *   - VALUE vocabularies only. The closed KEY vocabularies — which keys an
     *     `actions[]` entry may carry, the exact five a `providers[]` entry
     *     requires, the `invalidate` key set, a table declaration's own section
     *     names — are equally closed and equally refused, and none of them is
     *     here. They are per-surface allowlists computed at their refusal site
     *     (several depend on a sibling value, e.g. an action's legal key set is
     *     a function of its `kind`), so publishing them as flat sets would
     *     publish something the engine does not have.
     *   - Unconditional sets only. Where a value is legal only in combination
     *     with another (`prevented` needs kind ∈ mail/http/queue, a `database`
     *     effect needs selector.type ∈ table/option, `restorable` needs
     *     database_checkpoint scope), the CONDITION is not expressible here and
     *     is not expressed: this map says what the engine's alphabet is, never
     *     which sentences are well-formed.
     *   - Pin-dependent vocabularies publish their ENGINE-OWNED BASE only, named
     *     as such (see below).
     *
     * Additive, read-only, and deliberately assembled from the same consts the
     * validators themselves read — never from a second list. An offline
     * validator, an editor completion source, or a published grammar document
     * that restated these sets would be a second spelling of the engine's
     * vocabulary, free to say `verbatim` is legal a release after the engine
     * stopped accepting it. The one honest way to publish a closed set is to
     * hand back the exact value the refusal consults, which is all this does:
     * no computation, no normalization, no ordering change (declared order is
     * load-bearing in the refusal messages that print these sets).
     *
     * Not every vocabulary here is a flat list. `pattern_keys` and
     * `post_derivable_fields` are maps because the engine's own declaration is
     * a map, and flattening them here would lose the half a reader needs.
     *
     * Vocabularies whose legal values depend on which manifests are pinned —
     * ref/token/ledger kinds, which union their engine base with every declared
     * table `id_kind` — publish the ENGINE-OWNED BASE only, named as such. The
     * declared half is a property of a pin set, not of this engine, and is
     * reported per-run by whatever loaded those manifests.
     *
     * @return array<string, array<int|string, mixed>>
     */
    public static function closed_vocabularies(): array {
        return [
            'classification_classes' => self::CLASSES,
            'classification_sections' => self::SECTIONS,
            'scope_classes' => self::SCOPE_CLASSES,
            'value_casts' => self::CASTS,
            'pattern_keys' => self::PATTERN_KEYS,
            'option_autoload_values' => OptionState::AUTOLOAD_VALUES,
            'option_autoload_sentinels' => self::OPTION_AUTOLOAD_SENTINELS,
            'dynamic_option_resolvers' => self::DYNAMIC_OPTION_RESOLVERS,
            'user_meta_missing_user_modes' => self::MISSING_USER_MODES,
            'post_derivable_fields' => self::DERIVABLE_FIELD_COLUMNS,
            'post_field_classes' => self::FIELD_CLASSES,
            'post_type_body_modes' => self::BODY_MODES,
            'post_type_phases' => self::POST_TYPE_PHASES,
            'menu_derivable_fields' => self::MENU_DERIVABLE_FIELDS,
            'menu_field_classes' => self::MENU_FIELD_CLASSES,
            'table_classes' => self::TABLE_CLASSES,
            'table_identity_modes' => self::IDENTITY_MODES,
            'engine_ref_kinds' => self::ENGINE_REF_KINDS,
            'engine_token_kinds' => self::ENGINE_TOKEN_KINDS,
            'engine_ledger_kinds' => self::ENGINE_LEDGER_KINDS,
            'attribute_value_types' => self::ATTR_VALUE_TYPES,
            'attribute_tokenize_codecs' => self::ATTR_TOKENIZE_CODECS,
            'widget_setting_codecs' => self::WIDGET_SETTING_CODECS,
            'widget_setting_refs' => self::WIDGET_SETTING_REFS,
            'action_kinds' => self::ACTION_KINDS,
            'provider_sources' => self::PROVIDER_SOURCES,
            'effect_kinds' => self::EFFECT_KINDS,
            'effect_modes' => self::EFFECT_MODES,
            'effect_selector_scopes' => self::SELECTOR_SCOPES,
            'effect_selector_types' => self::SELECTOR_TYPES,
            'provider_resource_placeholders' => self::MEMBER_PLACEHOLDERS,
        ];
    }

    /**
     * The NAMED SUBSET of bounded string patterns the manifest grammar checks
     * against, as the exact PCRE this class hands to preg_match() (DUO-3327).
     *
     * A subset, and it says so: these five are the patterns that have an
     * engine-owned NAME (a `*_PATTERN` const, referenced from more than one
     * refusal), which is what makes publishing them meaningful — a consumer can
     * bind to the name and get whatever the engine currently means by it.
     * Policy.php alone applies roughly twenty further inline PCREs (identity and
     * column-name shapes, sha-256 digests, the secret-shaped-value screens, the
     * placeholder-brace scan) that have no such name; they are deliberately
     * absent rather than scraped, because a scraped anonymous pattern would be a
     * consumer contract nobody on this side agreed to keep. The boundary is
     * published with the document (ManifestValidate::emitSchema()'s `coverage`).
     *
     * Additive companion to closed_vocabularies(), same discipline and same
     * reason: a published pattern that is not the pattern that refuses is worse
     * than no published pattern. Delimiters and modifiers are kept rather than
     * stripped so a consumer can run the identical match; a consumer that wants
     * the bare body can strip them, but this side may not decide that for it.
     *
     * @return array<string, string>
     */
    public static function grammar_patterns(): array {
        return [
            'action_trigger_surface' => self::SURFACE_PATTERN,
            'capability_name' => self::CAPABILITY_NAME_PATTERN,
            'effect_id' => self::EFFECT_ID_PATTERN,
            'provider_id' => self::PROVIDER_ID_PATTERN,
            'provider_version' => self::PROVIDER_VERSION_PATTERN,
        ];
    }
}

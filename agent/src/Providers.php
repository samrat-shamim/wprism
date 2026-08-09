<?php
namespace Duo;

/**
 * Plugin-owned provider contract: discovery, negotiation, and invocation of
 * executable semantics the engine deliberately does not own.
 *
 * The boundary doctrine's fourth extension surface (docs/proposals/
 * engine-adapter-boundary.md, "Plugin-owned provider") is what this file
 * implements literally. A provider supplies behavior the plugin already owns;
 * the engine holds only the loading contract, the negotiation gate, and the
 * receipt/verification posture. Nothing here dispatches on a plugin name, and
 * nothing here reads a command string out of manifest data: a manifest names
 * an id, a capability, and typed arguments, and the code that runs ships with
 * the plugin or its adapter package.
 *
 * Negotiation happens before the first target mutation, by construction of
 * where Apply calls it. That ordering is the doctrine's "capability
 * negotiation before mutation" and "an unsupported or unverifiable capability
 * fails before destructive writes" — a missing, mismatched, inactive, or
 * out-of-range provider is a refusal with remediation, never a half-applied
 * target with a warning attached.
 */
final class Providers {
    /**
     * Reserved argument key the engine injects for `scope: entity`
     * capabilities. It is engine-supplied, so a manifest may not pass it and a
     * capability may not declare it in its own argument schema — otherwise the
     * batch the engine assembled and the batch the manifest asked for could
     * silently disagree about which entities were repaired.
     *
     * DUO-3369: its VALUE now has two shapes, and which one a capability
     * receives is decided by that capability's OWN declaration, never by what
     * this run happened to produce:
     *   - no `context` key declared — the bare `list<array{kind,id}>` batch,
     *     byte-identical to what DUO-3338 injected. An already-shipped
     *     provider cannot observe that this channel grew at all;
     *     regress_provider_contract.php freezes the exact serialized bytes a
     *     channel-less capability receives, captured from the pre-change
     *     engine, so "byte-compatible" is a failing check rather than a claim.
     *   - one or more declared channels — an envelope
     *     `{entities: [...], always_on_write?: bool, deletions?: [...],
     *     reparents?: [...], retry?: bool}`
     *     carrying ONLY the declared channels, always in that key order. An
     *     undeclared channel is ABSENT rather than empty, so a capability can
     *     tell "declared, and nothing happened this run" from "never asked
     *     for" without consulting its own manifest.
     */
    public const ENTITIES_ARG = 'entities';

    /**
     * The engine batch channels a `scope: entity` capability may opt into
     * (DUO-3369). This is the doctrine's "declared … required lifecycle
     * context" and "batching … and retry semantics" (docs/proposals/
     * engine-adapter-boundary.md, "The provider contract must define"), made
     * declarable instead of implicit. Closed for the same reason the
     * native-action vocabulary is closed: every name is engine-assembled
     * evidence with one fixed meaning, so an adapter may opt IN to a channel
     * but may never mint one — a name the engine does not assemble would be a
     * claim nothing ever satisfies.
     *
     *   - `deletions`        tombstone rows for the deletions this run actually
     *                        APPLIED (`--with-deletes`), plus the ones a
     *                        previous incomplete apply had already made absent,
     *                        narrowed to the action's own triggers. Never a
     *                        tombstone that was only PLANNED: the selection
     *                        projects surfaces from planned tombstones so a
     *                        capability can be selected before the delete gate,
     *                        but a run without `--with-deletes` never executes
     *                        them and the entities are all still there
     *                        (Apply::action_deletions()).
     *   - `reparents`        the reparent receipts this apply captured before
     *                        moving a post_parent, unioned with the durable
     *                        `regen_reparent_context:<uuid>` markers an earlier
     *                        incomplete apply left outstanding, narrowed the
     *                        same way.
     *   - `retry`            whether this apply is retrying an incomplete one
     *                        (the `apply_in_progress` marker the selection
     *                        already consults for its tombstone surfaces).
     *   - `always_on_write`  a boolean FLAG about this invocation rather than
     *                        evidence about the revision: true whenever
     *                        declared, stating that the declaring action fired
     *                        on an always-on basis. It mirrors regen_dependency's
     *                        flag of the same name precisely — there
     *                        (Apply::regen_batch_dependencies()) the flag
     *                        SUPPRESSES a per-candidate verify check on a write
     *                        candidate the engine already had; IT NEVER CREATES
     *                        JOBS, since candidates still come only from this
     *                        run's work, pending markers, and deletion
     *                        receipts. So it manufactures no work here either:
     *                        a capability fires when its entity batch or one of
     *                        its evidence channels is non-empty, and this flag
     *                        only tells it which basis it fired on.
     */
    public const CONTEXT_CHANNELS = ['always_on_write', 'deletions', 'reparents', 'retry'];

    /**
     * The channels whose assembled value is a boolean flag rather than a list
     * of engine-assembled rows.
     *
     * Every declared channel carries a payload key, so the injection order is
     * CONTEXT_CHANNELS itself. That order is the engine's, not the
     * declaration's: two capabilities declaring the same channels in different
     * orders must receive byte-identical envelopes, or a provider comparing
     * receipts across runs would see a difference that means nothing.
     */
    public const FLAG_CHANNELS = ['always_on_write', 'retry'];

    /**
     * The flags that carry no work of their own: their truth must never, by
     * itself, make a batch fire. `retry` is deliberately NOT here — a retried
     * apply IS work evidence, because the capability must re-fire over
     * whatever the interrupted run half-did. Apply consults this set instead
     * of naming channels, so a future no-work flag is skipped there by
     * declaration rather than by remembering to edit a string comparison.
     */
    public const NO_WORK_CHANNELS = ['always_on_write'];

    private const ID_PATTERN = '/^[a-z][a-z0-9-]{0,63}$/D';
    private const CAPABILITY_PATTERN = '/^[a-z0-9_]{1,64}$/D';
    private const ARG_NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/D';
    private const ARG_TYPES = ['bool', 'int', 'list<object>', 'list<string>', 'string'];
    /**
     * Types a `list<object>` row FIELD may declare. Deliberately the scalars
     * only: the object grammar is exactly one level deep, so neither a nested
     * `list<object>` nor a `list<string>` field can open a second nesting axis
     * the validator would then have to walk to an unbounded depth. A shape
     * that genuinely needs two levels is a second argument, not a deeper one.
     */
    private const FIELD_TYPES = ['bool', 'int', 'string'];

    /**
     * Whether this process has the live WordPress seams negotiation reads.
     *
     * Policy and registry validators deliberately have offline entry points:
     * loading a manifest library must not manufacture a "missing provider"
     * result merely because there is no WordPress target in this PHP process.
     * A target-facing capability/status/plan path has this WordPress context
     * and may therefore ask providers for identity/capabilities without
     * invoking an action. Do NOT require validate_plugin()/get_plugins() here:
     * Deploy::plugin_runtime_state() deliberately loads wp-admin's plugin API
     * on demand under WP-CLI. Keep that distinction here rather than making
     * Policy learn the WordPress lifecycle primitives Deploy owns.
     */
    public static function runtime_negotiation_available(): bool {
        return defined('ABSPATH')
            && defined('WP_PLUGIN_DIR')
            && function_exists('apply_filters')
            && function_exists('get_option');
    }

    /**
     * Resolve, verify, and bind every provider the selected actions reach.
     *
     * Returns problems rather than throwing them so one refusal can name every
     * unmet capability at once: an operator fixing a promotion needs the whole
     * list (activate this, upgrade that), not the first failure followed by
     * another round trip. The one deliberate exception is a manifest-sourced
     * provider whose file or class is missing — that is a packaging fault in
     * the adapter itself rather than a fact about this environment, and it
     * throws with the same message shape Policy::regenerators() uses for the
     * identical situation.
     *
     * @param list<array<string,mixed>> $selectedActions Policy::actions_for()
     * @return array{problems:list<array<string,mixed>>, providers:array<string,object>, capabilities:array<string,array<string,array<string,mixed>>>}
     */
    public static function negotiate(Policy $policy, array $selectedActions): array {
        $declarations = $policy->provider_declarations();
        $wanted = [];
        foreach ($selectedActions as $action) {
            if (($action['kind'] ?? '') !== 'provider') {
                continue;
            }
            // Keyed by provider only, and a LIST per provider: two actions may
            // legitimately select the same capability with different triggers
            // and different arguments, and each declaration's own arguments
            // have to be checked against the schema. Keying by capability too
            // would let the last declaration's validation stand in for the
            // rest, so an unchecked argument set could reach invoke().
            $wanted[(string) $action['provider']][] = $action;
        }
        ksort($wanted, SORT_STRING);

        $problems = [];
        $instances = [];
        $capabilities = [];
        $pluginSupplied = null;
        foreach ($wanted as $id => $wantedActions) {
            usort($wantedActions, static fn(array $a, array $b): int =>
                strcmp((string) $a['capability'], (string) $b['capability'])
                ?: strcmp((string) $a['manifest'], (string) $b['manifest'])
                ?: ((int) $a['index'] <=> (int) $b['index']));
            $declaration = $declarations[$id] ?? null;
            if ($declaration === null) {
                // Unreachable through Policy::load(), which refuses an action
                // naming a provider its own manifest never declared. Kept as a
                // fail-closed guard for any future caller assembling actions
                // from somewhere other than a validated policy.
                $problems[] = self::problem($id, '?', '?', 'undeclared_provider', 'a declared provider', 'no declaration in any pinned manifest', 'pin the manifest that declares this provider');
                continue;
            }
            $manifest = (string) $declaration['manifest'];
            $plugin = (string) $declaration['plugin'];
            $range = $declaration['version_range'];

            // Liveness before loading: a plugin-sourced provider cannot even
            // register its filter while its plugin is inactive, so checking
            // activation first turns "no provider answered" into the accurate
            // "the owning plugin is not active here".
            $live = Deploy::plugin_runtime_state($plugin);
            if (!$live['installed']) {
                $problems[] = self::problem(
                    $id, $manifest, $plugin, 'missing_plugin',
                    "$plugin installed",
                    'not present in this environment',
                    "install and activate $plugin" . ($range === null ? '' : " >={$range['min']} <{$range['max']}")
                        . ", or unpin manifest '$manifest'"
                );
                continue;
            }
            if (!$live['active']) {
                $problems[] = self::problem(
                    $id, $manifest, $plugin, 'inactive_plugin',
                    "$plugin active",
                    'not in active_plugins',
                    "run 'duo deploy <env>' so activation hooks complete before apply, "
                        . "or unpin manifest '$manifest'"
                );
                continue;
            }
            $installed = $live['version'];
            if ($range !== null && ($installed === '' || !Deploy::in_range($installed, $range['min'], $range['max']))) {
                $problems[] = self::problem(
                    $id, $manifest, $plugin, 'outside_version_range',
                    ">={$range['min']} <{$range['max']}",
                    $installed !== '' ? $installed : '(unknown version)',
                    "upgrade or downgrade $plugin into >={$range['min']} <{$range['max']}, "
                        . "or pin a manifest whose range covers the installed version"
                );
                continue;
            }

            if ($declaration['source'] === 'manifest') {
                $provider = self::manifest_provider($policy, $declaration);
            } else {
                if ($pluginSupplied === null) {
                    $pluginSupplied = self::plugin_supplied_providers();
                }
                if (!$pluginSupplied['available']) {
                    $problems[] = self::problem(
                        $id, $manifest, $plugin, 'provider_registry_unavailable',
                        'a readable `duo_providers` registry',
                        'provider registry callback failed',
                        'upgrade or disable the faulty provider plugin and retry'
                    );
                    continue;
                }
                $provider = $pluginSupplied['providers'][$id] ?? null;
                if ($provider === null) {
                    $problems[] = self::problem(
                        $id, $manifest, $plugin, 'missing_plugin_provider',
                        "a `duo_providers` filter entry with identity id '$id'",
                        'no registered provider matched the declared identity',
                        "upgrade $plugin to a version that registers the '$id' provider, or install its adapter package"
                    );
                    continue;
                }
            }

            if ($declaration['source'] === 'plugin') {
                $anchorProblem = self::plugin_anchor_problem($provider, $declaration);
                if ($anchorProblem !== null) {
                    $problems[] = $anchorProblem;
                    continue;
                }
            }

            $contractProblem = self::contract_problem($provider, $declaration);
            if ($contractProblem !== null) {
                $problems[] = $contractProblem;
                continue;
            }

            try {
                $advertised = $provider->capabilities();
            } catch (\Throwable $t) {
                $problems[] = self::problem(
                    $id, $manifest, $plugin, 'contract_shape',
                    'capabilities() returning a name => declaration map',
                    'capabilities() threw',
                    'upgrade the provider to the current adapter contract'
                );
                continue;
            }
            if (!is_array($advertised) || array_is_list($advertised)) {
                $problems[] = self::problem(
                    $id, $manifest, $plugin, 'contract_shape',
                    'capabilities() returning a name => declaration map',
                    'capabilities() did not return a name => declaration map',
                    'upgrade the provider to the current adapter contract'
                );
                continue;
            }
            $bound = [];
            $failed = false;
            foreach ($wantedActions as $action) {
                // Per ACTION, not per capability: each declaration's own
                // argument set validates against the schema (see the
                // accumulator comment above — the last-writer-wins collapse
                // is exactly what this loop shape exists to prevent). Two
                // actions selecting one capability bind it once but produce
                // one problem row EACH when both are invalid, so the refusal
                // names every declaration a human has to fix.
                $capability = (string) $action['capability'];
                $problem = self::capability_problem($declaration, $capability, $action, $advertised);
                if ($problem !== null) {
                    $problems[] = $problem;
                    $failed = true;
                    continue;
                }
                $bound[$capability] = $advertised[$capability];
            }
            // A count comparison cannot express completeness here: the wanted
            // rows are actions while the bound rows are capabilities, and the
            // two only coincide while no capability is selected twice.
            if (!$failed) {
                $instances[$id] = $provider;
                $capabilities[$id] = $bound;
            }
        }
        return ['problems' => $problems, 'providers' => $instances, 'capabilities' => $capabilities];
    }

    /**
     * Run one negotiated capability and return its receipt.
     *
     * `verified` must be exactly true. Command-success-only verification is
     * what the doctrine forbids ("no provider success without declared effects
     * and value-level verification"), and a provider that returns a receipt
     * without proving the value it wrote is indistinguishable from one that
     * did nothing, so the engine refuses to record it as done.
     *
     * The timeout is a post-hoc wall-clock budget, checked after the call
     * returns. In-process PHP cannot preempt a running plugin call, and
     * WP_CLI::runcommand() exposes no timeout either, so the honest claim is:
     * the declared budget bounds what the receipt may assert about duration
     * and hard-fails an overrun, but it does not stop the work mid-flight. A
     * process-boundary launch would be needed for real preemption.
     *
     * @param array<string,mixed> $actionEntry one Policy::actions_for() row
     * @param array<string,mixed> $capabilityDecl the negotiated declaration
     * @param list<array{kind:string,id:int}> $entities batch for scope=entity
     * @param array<string,mixed> $context engine batch channels the caller
     *   assembled, keyed by channel name — exactly the declared
     *   CONTEXT_CHANNELS, no more and no fewer (see batch_payload()).
     * @return array{before:mixed, after:mixed, verified:true, duration_seconds:float}
     */
    public static function invoke(
        object $provider,
        array $actionEntry,
        array $capabilityDecl,
        array $entities,
        array $context = []
    ): array {
        $id = (string) $actionEntry['provider'];
        $capability = (string) $actionEntry['capability'];
        $args = (array) ($actionEntry['args'] ?? []);
        if (($capabilityDecl['scope'] ?? '') === 'entity') {
            $args[self::ENTITIES_ARG] = self::batch_payload($id, $capability, $capabilityDecl, $entities, $context);
        } elseif ($context !== []) {
            // Unreachable through Apply, which only assembles channels for an
            // entity-scoped declaration (and negotiation refuses `context` on
            // scope: site outright). Fail closed rather than drop the context
            // silently: a caller that assembled deletion evidence and had it
            // discarded would report a repair that never saw the tombstones.
            throw new \RuntimeException(
                "duo: provider '$id' capability '$capability' is scope: site but was handed engine batch "
                . 'context (' . implode(', ', array_keys($context)) . ') — only scope: entity capabilities '
                . 'receive a batch'
            );
        }
        $started = microtime(true);
        try {
            $receipt = $provider->invoke($capability, $args);
        } catch (\Throwable $t) {
            throw new \RuntimeException("duo: provider '$id' capability '$capability' failed");
        }
        $elapsed = microtime(true) - $started;

        $keys = is_array($receipt) ? array_keys($receipt) : [];
        sort($keys, SORT_STRING);
        if ($keys !== ['after', 'before', 'verified']) {
            throw new \RuntimeException(
                "duo: provider '$id' capability '$capability' returned a malformed receipt — "
                . 'exactly before, after, and verified are required'
            );
        }
        if ($receipt['verified'] !== true) {
            throw new \RuntimeException(
                "duo: provider '$id' capability '$capability' reported no value-level verification — "
                . 'a receipt must prove the state it wrote, not that a call returned'
            );
        }
        $budget = (int) $capabilityDecl['timeout_seconds'];
        if ($elapsed > $budget) {
            throw new \RuntimeException(
                "duo: provider '$id' capability '$capability' overran its declared budget ("
                . round($elapsed, 3) . "s > {$budget}s) — raise timeout_seconds if the work is "
                . 'legitimately this large, or reduce the batch it is given'
            );
        }
        return $receipt + ['duration_seconds' => round($elapsed, 3)];
    }

    /**
     * Does this negotiated declaration opt into one engine batch channel?
     *
     * The lookup is the engine's own, not a string comparison scattered across
     * Apply: asking for a name outside CONTEXT_CHANNELS is a programming error
     * that throws here rather than quietly answering "no" — a mistyped channel
     * that reads as undeclared would silently withhold evidence the capability
     * did ask for.
     *
     * @param array<string,mixed> $capabilityDecl the negotiated declaration
     */
    public static function declares_channel(array $capabilityDecl, string $channel): bool {
        if (!in_array($channel, self::CONTEXT_CHANNELS, true)) {
            throw new \RuntimeException(
                "duo: '$channel' is not an engine batch channel — the closed set is "
                . implode(', ', self::CONTEXT_CHANNELS)
            );
        }
        return in_array($channel, (array) ($capabilityDecl['context'] ?? []), true);
    }

    /**
     * The value injected under ENTITIES_ARG: the bare batch, or the envelope.
     *
     * Byte-compatibility is the load-bearing property, and it is structural
     * rather than careful: a declaration with no `context` key takes the early
     * return and hands back the caller's `$entities` array itself, so there is
     * no code path on which an existing capability's injected argument could
     * acquire a key, a reordering, or a re-encoding. Everything below the
     * early return is reachable only for a capability that asked for it.
     *
     * Both mismatch directions are refusals, because both are engine bugs with
     * silent, wrong-looking-correct outcomes: a channel supplied but never
     * declared would hand a provider evidence its negotiated contract never
     * promised, and a channel declared but not supplied would let it verify a
     * deletion sweep against a batch that simply omitted the tombstones.
     *
     * @param array<string,mixed> $decl the negotiated capability declaration
     * @param list<array{kind:string,id:int}> $entities
     * @param array<string,mixed> $context channel name => assembled value
     * @return list<array{kind:string,id:int}>|array<string,mixed>
     */
    private static function batch_payload(
        string $id,
        string $capability,
        array $decl,
        array $entities,
        array $context
    ): array {
        $channels = (array) ($decl['context'] ?? []);
        if ($channels === []) {
            if ($context !== []) {
                throw new \RuntimeException(
                    "duo: provider '$id' capability '$capability' was handed engine batch context ("
                    . implode(', ', array_keys($context)) . ') it never declared — a capability receives '
                    . 'only the channels its own declaration names'
                );
            }
            return $entities;
        }
        $unknown = array_diff(array_keys($context), self::CONTEXT_CHANNELS);
        if ($unknown !== []) {
            throw new \RuntimeException(
                "duo: provider '$id' capability '$capability' received engine batch context under name(s) the "
                . 'engine does not assemble: ' . implode(', ', $unknown) . ' — the closed set is '
                . implode(', ', self::CONTEXT_CHANNELS)
            );
        }
        $payload = [self::ENTITIES_ARG => $entities];
        foreach (self::CONTEXT_CHANNELS as $channel) {
            $declared = in_array($channel, $channels, true);
            if (!$declared) {
                if (array_key_exists($channel, $context)) {
                    throw new \RuntimeException(
                        "duo: provider '$id' capability '$capability' was handed the '$channel' batch channel "
                        . 'it never declared — declared: ' . implode(', ', $channels)
                    );
                }
                continue;
            }
            if (!array_key_exists($channel, $context)) {
                throw new \RuntimeException(
                    "duo: provider '$id' capability '$capability' declared the '$channel' batch channel but "
                    . 'the engine assembled none — an absent declared channel would be indistinguishable '
                    . 'from an empty one'
                );
            }
            $value = $context[$channel];
            $flag = in_array($channel, self::FLAG_CHANNELS, true);
            $wellFormed = $flag
                ? is_bool($value)
                : is_array($value) && array_is_list($value);
            if (!$wellFormed) {
                throw new \RuntimeException(
                    "duo: provider '$id' capability '$capability' batch channel '$channel' was assembled as "
                    . get_debug_type($value) . ' — ' . ($flag ? 'that channel is a boolean flag'
                        : 'that channel is a list of engine-assembled rows')
                );
            }
            $payload[$channel] = $value;
        }
        return $payload;
    }

    /**
     * Manifest-sourced provider loading — the same trust boundary and
     * validate/load/instantiate shape as Policy::interpreters()/
     * regenerators(), deliberately mirrored rather than shared for the same
     * reason those two are mirrors of each other: the discovery differs (one
     * interpreter name per manifest; one regenerator name per declaring
     * post_types entry; here, one class per declared `providers` entry, keyed
     * by an id that is also the negotiation identity), so a shared helper
     * would need its own branching and buy nothing over short, independently
     * readable methods.
     *
     * The declared id resolves to <manifests_dir>/providers/<id>.php, which
     * must define \Duo\Providers\<CamelCase(id)>. A missing file is a
     * packaging fault, not an environment fact: the adapter claimed to ship
     * this code, so the engine says so rather than degrading to "capability
     * unavailable here".
     *
     * @param array<string,mixed> $declaration
     */
    private static function manifest_provider(Policy $policy, array $declaration): object {
        $id = (string) $declaration['id'];
        $manifest = (string) $declaration['manifest'];
        $file = Policy::manifests_dir() . '/providers/' . $id . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException(
                "duo: manifest '$manifest' declares provider '$id' but $file is missing — "
                . 'provider code ships with its manifest, not the engine'
            );
        }
        require_once $file;
        $class = '\\Duo\\Providers\\' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $id)));
        if (!class_exists($class)) {
            throw new \RuntimeException(
                "duo: provider file $file must define $class with identity(): array, "
                . 'capabilities(): array, and invoke(string $capability, array $args): array'
            );
        }
        return new $class($policy);
    }

    /**
     * Plugin-sourced provider discovery. One registry filter rather than a
     * per-id filter name: the filter runs under wp-cli exactly as it does
     * under a web request, and a single registry keeps the engine from
     * synthesizing a hook name out of manifest data — the id is matched
     * against each entry's own declared identity instead.
     *
     * @return array{available:bool,providers:array<string,object>}
     */
    private static function plugin_supplied_providers(): array {
        if (!function_exists('apply_filters')) {
            return ['available' => true, 'providers' => []];
        }
        try {
            $supplied = apply_filters('duo_providers', []);
        } catch (\Throwable $t) {
            return ['available' => false, 'providers' => []];
        }
        $out = [];
        foreach ((array) $supplied as $entry) {
            if (!is_object($entry) || !is_callable([$entry, 'identity'])) {
                continue;
            }
            // Guarded: this loop touches EVERY registration on the filter,
            // including providers for manifests this site never pinned. One
            // third-party provider whose identity() throws must not turn an
            // unrelated apply's negotiation into an uncaught fatal with no
            // remediation — a registration that cannot even say its own id is
            // simply not discoverable, and the wanted-provider path then
            // reports the accurate missing_plugin_provider problem.
            try {
                $identity = $entry->identity();
                // A provider registration is untrusted until its id survives
                // this bounded, string-only discovery gate. In particular, do
                // not coerce a Stringable here: its __toString() is plugin code
                // and may throw or carry data that must never escape readiness
                // diagnostics.
                $id = is_array($identity) ? ($identity['id'] ?? null) : null;
                if (!is_string($id) || $id === ''
                    || preg_match(self::ID_PATTERN, $id) !== 1 || isset($out[$id])) {
                    continue;
                }
            } catch (\Throwable $t) {
                continue;
            }
            $out[$id] = $entry;
        }
        return ['available' => true, 'providers' => $out];
    }

    /**
     * A plugin-sourced provider's code must actually live inside the plugin
     * it claims. The filter registry is open (any active plugin can register
     * anything, first id wins), and identity() is self-reported — without
     * this anchor, plugin B could answer for plugin A's declared id and
     * negotiation would truthfully report "A installed, active, in range,
     * identity matched" while B's code runs. Not an execution-privilege
     * boundary (every active plugin already runs arbitrary code); it is what
     * keeps the negotiation's identity claim falsifiable, per the doctrine's
     * "trusted as part of the installed plugin". Single-file plugins anchor
     * to the plugins directory itself — the strongest true statement
     * available for a plugin with no directory of its own.
     *
     * @param array<string,mixed> $declaration
     */
    private static function plugin_anchor_problem(object $provider, array $declaration): ?array {
        $id = (string) $declaration['id'];
        $manifest = (string) $declaration['manifest'];
        $plugin = (string) $declaration['plugin'];
        $pluginsDir = defined('WP_PLUGIN_DIR')
            ? (string) WP_PLUGIN_DIR
            : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/plugins' : '');
        if ($pluginsDir === '') {
            return self::problem(
                $id, $manifest, $plugin, 'unresolvable_plugin_dir',
                'a resolvable WP_PLUGIN_DIR to anchor the provider class against',
                'neither WP_PLUGIN_DIR nor WP_CONTENT_DIR is defined',
                'run negotiation through the ordinary WordPress apply path'
            );
        }
        $pluginSubdir = dirname($plugin);
        $anchor = realpath(
            $pluginSubdir === '.' ? $pluginsDir : $pluginsDir . '/' . $pluginSubdir
        );
        $file = (new \ReflectionClass($provider))->getFileName();
        $real = is_string($file) ? realpath($file) : false;
        if ($anchor === false || $real === false
            || !str_starts_with($real . '', rtrim($anchor, '/') . '/')) {
            return self::problem(
                $id, $manifest, $plugin, 'provider_outside_owning_plugin',
                'provider class defined under the owning plugin directory',
                'provider class is not anchored under the owning plugin',
                "register the '$id' provider from $plugin itself, or declare it source: manifest"
            );
        }
        return null;
    }

    /**
     * Duck-typed contract check plus exact identity match. Identity is
     * deterministic on purpose: the manifest states which id, version, and
     * owning plugin it negotiated against, so a provider answering with
     * anything else is a different artifact than the one this revision was
     * validated for, however plausible the difference looks.
     *
     * @param array<string,mixed> $declaration
     */
    private static function contract_problem(object $provider, array $declaration): ?array {
        $id = (string) $declaration['id'];
        $manifest = (string) $declaration['manifest'];
        $plugin = (string) $declaration['plugin'];
        foreach (['identity', 'capabilities', 'invoke'] as $method) {
            if (!is_callable([$provider, $method])) {
                return self::problem(
                    $id, $manifest, $plugin, 'contract_shape',
                    'identity(): array, capabilities(): array, invoke(string, array): array',
                    "provider lacks required public $method()",
                    'upgrade the provider to the current adapter contract'
                );
            }
        }
        $expected = ['id' => $id, 'plugin' => $plugin, 'version' => (string) $declaration['version']];
        try {
            $found = $provider->identity();
        } catch (\Throwable $t) {
            return self::problem(
                $id, $manifest, $plugin, 'contract_shape',
                'identity() returning an array',
                'identity() threw',
                'upgrade the provider to the current adapter contract'
            );
        }
        $found = is_array($found) ? $found : [];
        ksort($expected, SORT_STRING);
        ksort($found, SORT_STRING);
        if ($found !== $expected) {
            return self::problem(
                $id, $manifest, $plugin, 'identity_mismatch',
                self::describe($expected),
                'identity() did not match the declared provider identity',
                "align the provider's identity() with manifest '$manifest', or pin the manifest revision "
                    . 'that matches the installed provider'
            );
        }
        return null;
    }

    /**
     * One referenced capability's negotiation.
     *
     * Idempotency is required rather than advisory: apply's retry machinery
     * re-runs the whole rebuild pass after an incomplete apply, so a
     * capability that is not safe to re-fire would be re-fired anyway. Until
     * a receipt-guard mechanism exists to make at-most-once true, refusing is
     * the honest bound.
     *
     * @param array<string,mixed> $declaration
     * @param array<string,mixed> $action
     * @param array<string,mixed> $advertised
     */
    private static function capability_problem(
        array $declaration,
        string $capability,
        array $action,
        array $advertised
    ): ?array {
        $id = (string) $declaration['id'];
        $manifest = (string) $declaration['manifest'];
        $plugin = (string) $declaration['plugin'];
        $decl = $advertised[$capability] ?? null;
        if (!is_array($decl)) {
            return self::problem(
                $id, $manifest, $plugin, 'missing_capability',
                "capability '$capability'",
                'provider did not advertise the declared capability',
                "upgrade $plugin (or its adapter package) to a version advertising '$capability'"
            );
        }
        try {
            self::validate_capability_declaration($decl, "provider '$id' capability '$capability'");
        } catch (\Throwable $t) {
            return self::problem(
                $id, $manifest, $plugin, 'malformed_capability',
                'a well-formed capability declaration',
                'provider advertised a malformed capability declaration',
                'upgrade the provider to the current adapter contract'
            );
        }
        if ($decl['idempotent'] !== true) {
            return self::problem(
                $id, $manifest, $plugin, 'non_idempotent_capability',
                'idempotent: true',
                'idempotent: false',
                "apply re-runs the rebuild pass on retry, so '$capability' must be safe to re-fire; "
                    . 'make it idempotent or stop declaring it from an action'
            );
        }
        if (($decl['scope'] ?? null) === 'entity') {
            // Entity scope is only meaningful when the engine can actually
            // assemble a batch: the batch rows come from applied work whose
            // canonical surface matches the action's own triggers, and only
            // post/term/table surfaces resolve to a ledger-minted local id.
            // An unscoped declaration would batch [] on every apply, and an
            // option:/entity: trigger would fail id resolution in the rebuild
            // pass — after the authored commit. Both are knowable here, so
            // both refuse before any mutation instead (independent review
            // caught the fail-open/fail-late pair this check closes).
            $triggers = $action['triggers'] ?? null;
            if (!is_array($triggers) || $triggers === []) {
                return self::problem(
                    $id, $manifest, $plugin, 'entity_scope_unscoped_action',
                    "explicit triggers on the action declaring '$capability' (scope: entity)",
                    'no triggers (unscoped declaration)',
                    'declare exact post:/term:/table: triggers so the engine can assemble the entity batch, '
                        . 'or make the capability scope: site'
                );
            }
            foreach ($triggers as $trigger) {
                $trigger = (string) $trigger;
                if (preg_match('/^(post|term|table):/', $trigger) !== 1) {
                    return self::problem(
                        $id, $manifest, $plugin, 'entity_scope_unresolvable_trigger',
                        "only post:/term:/table: triggers on the action declaring '$capability' (scope: entity)",
                        "trigger '$trigger' has no ledger-resolvable per-entity id",
                        'narrow the trigger to an id-bearing surface, or make the capability scope: site'
                    );
                }
            }
        }
        try {
            self::validate_args((array) ($action['args'] ?? []), $decl['args'], "manifest '$manifest' action args");
        } catch (\Throwable $t) {
            return self::problem(
                $id, $manifest, $plugin, 'invalid_capability_args',
                'arguments matching the capability schema',
                'action arguments do not match advertised schema',
                "correct the action arguments in manifest '$manifest', or pin a manifest matching this "
                    . 'provider version'
            );
        }
        return null;
    }

    /**
     * The capability declaration grammar. Closed on every axis a negotiation
     * decision reads from — an unknown key here would be a claim the engine
     * silently ignores while the operator believes it was honored.
     *
     * `reads`/`writes` use the same exact canonical-surface literals manifest
     * triggers use, at the granularity that grammar supports (a table, a post
     * type, an option name). The restorable/irreversible boundary of what a
     * capability actually touches is carried separately and more precisely by
     * the action's `effects` list, which has its own selector grammar; these
     * two lists are the negotiation-time summary, not a second effects
     * channel.
     *
     * `context` (DUO-3369) is the one OPTIONAL key: absent means a capability
     * declared exactly what DUO-3338 allowed and receives exactly what it
     * received then. It is validated here, at negotiation, for the same reason
     * every other axis is — an unhonored channel name in a declaration is a
     * claim the engine would otherwise silently ignore while the operator
     * believes deletion evidence was being delivered.
     */
    private static function validate_capability_declaration(array $decl, string $where): void {
        $keys = array_keys($decl);
        sort($keys, SORT_STRING);
        $required = ['args', 'idempotent', 'reads', 'scope', 'timeout_seconds', 'writes'];
        $optional = ['context'];
        $missing = array_diff($required, $keys);
        $unknown = array_diff($keys, $required, $optional);
        if ($missing !== [] || $unknown !== []) {
            throw new \RuntimeException(
                "$where must declare exactly " . implode(', ', $required)
                . ' (optional: ' . implode(', ', $optional) . ')'
                . ' (found: ' . ($keys === [] ? 'nothing' : implode(', ', $keys)) . ')'
            );
        }
        if (!in_array($decl['scope'], ['site', 'entity'], true)) {
            throw new \RuntimeException("$where.scope must be \"site\" or \"entity\"");
        }
        if (!is_bool($decl['idempotent'])) {
            throw new \RuntimeException("$where.idempotent must be a boolean");
        }
        if (!is_int($decl['timeout_seconds']) || $decl['timeout_seconds'] <= 0) {
            throw new \RuntimeException("$where.timeout_seconds must be a positive integer");
        }
        foreach (['reads', 'writes'] as $key) {
            $surfaces = $decl[$key];
            if (!is_array($surfaces) || !array_is_list($surfaces)) {
                throw new \RuntimeException("$where.$key must be a list of exact canonical surfaces");
            }
            $seen = [];
            foreach ($surfaces as $surface) {
                if (!is_string($surface) || preg_match(Policy::SURFACE_PATTERN, $surface) !== 1) {
                    throw new \RuntimeException(
                        "$where.$key must contain only exact canonical surfaces "
                        . '(post|term|table|option|entity):<lowercase-name>'
                    );
                }
                if (isset($seen[$surface])) {
                    throw new \RuntimeException("$where.$key repeats exact surface '$surface'");
                }
                $seen[$surface] = true;
            }
        }
        if (array_key_exists('context', $decl)) {
            $channels = $decl['context'];
            if (!is_array($channels) || !array_is_list($channels) || $channels === []) {
                // An empty list is refused rather than treated as "no
                // channels": it would declare the envelope shape while
                // carrying nothing, so a provider author reading their own
                // declaration and the payload they receive would disagree
                // about whether the engine honored the key at all.
                throw new \RuntimeException(
                    "$where.context must be a non-empty list of engine batch channels ("
                    . implode(', ', self::CONTEXT_CHANNELS) . ')'
                );
            }
            $seenChannel = [];
            foreach ($channels as $channel) {
                $channel = is_string($channel) ? $channel : get_debug_type($channel);
                if (!in_array($channel, self::CONTEXT_CHANNELS, true)) {
                    throw new \RuntimeException(
                        "$where.context names '$channel', which is not an engine batch channel — the closed "
                        . 'set is ' . implode(', ', self::CONTEXT_CHANNELS)
                        . ' (the engine assembles these; a capability may opt in, never mint one)'
                    );
                }
                if (isset($seenChannel[$channel])) {
                    throw new \RuntimeException("$where.context repeats channel '$channel'");
                }
                $seenChannel[$channel] = true;
            }
            if ($decl['scope'] !== 'entity') {
                // Every channel is assembled from per-entity work the action's
                // own triggers narrow. A site-scoped capability receives no
                // batch at all, so honoring `context` there would mean
                // inventing a scope the declaration did not ask for.
                throw new \RuntimeException(
                    "$where.context is only meaningful for scope: entity, but this declaration is scope: "
                    . (is_string($decl['scope']) ? $decl['scope'] : get_debug_type($decl['scope']))
                    . ' (declared channels: ' . implode(', ', $channels)
                    . ') — make the capability scope: entity, or drop the context key'
                );
            }
        }
        $args = $decl['args'];
        if (!is_array($args) || (array_is_list($args) && $args !== [])) {
            throw new \RuntimeException("$where.args must be an object mapping argument name to its type");
        }
        foreach ($args as $arg => $rule) {
            $arg = (string) $arg;
            if (preg_match(self::ARG_NAME_PATTERN, $arg) !== 1) {
                throw new \RuntimeException("$where.args has an unbounded or malformed argument name '$arg'");
            }
            if ($arg === self::ENTITIES_ARG) {
                throw new \RuntimeException(
                    "$where.args may not declare '" . self::ENTITIES_ARG . "' — the engine supplies that batch "
                    . 'for scope=entity capabilities'
                );
            }
            $ruleKeys = is_array($rule) ? array_keys($rule) : [];
            sort($ruleKeys, SORT_STRING);
            $objectList = is_array($rule) && ($rule['type'] ?? null) === 'list<object>';
            if ($ruleKeys !== ($objectList ? ['fields', 'required', 'type'] : ['required', 'type'])) {
                throw new \RuntimeException(
                    "$where.args.$arg must declare exactly type and required"
                    . ($objectList ? ' and fields (list<object> carries its own closed field vocabulary)' : '')
                );
            }
            if (!in_array($rule['type'], self::ARG_TYPES, true)) {
                throw new \RuntimeException(
                    "$where.args.$arg.type must be one of " . implode(', ', self::ARG_TYPES)
                );
            }
            if (!is_bool($rule['required'])) {
                throw new \RuntimeException("$where.args.$arg.required must be a boolean");
            }
            if ($objectList) {
                self::validate_field_declarations($rule['fields'], "$where.args.$arg");
            }
        }
    }

    /**
     * The row-field vocabulary of one `list<object>` argument (DUO-3369).
     *
     * A typed object list exists so a capability can receive STRUCTURED rows —
     * a tombstone's surface, uuid, and prior local id — without the contract
     * degrading into "pass whatever you like as an array". That only holds if
     * the field vocabulary is as closed as the argument vocabulary above it,
     * which is why an empty `fields` map refuses: a list<object> with no
     * declared fields would accept nothing but empty objects while READING as
     * a free-form payload channel, the exact shape the DUO-3338 contract
     * exists to keep out of manifests.
     *
     * Exactly one level of nesting, enforced by FIELD_TYPES rather than by a
     * depth counter: an object grammar that can contain itself has no bound a
     * reviewer can state, and every case this issue's regenerator-channel
     * migration needs is one level deep.
     *
     * @param mixed $fields the declared `fields` map
     */
    private static function validate_field_declarations(mixed $fields, string $where): void {
        if (!is_array($fields) || $fields === [] || array_is_list($fields)) {
            throw new \RuntimeException(
                "$where.fields must be a non-empty object mapping row field name to its type — a list<object> "
                . 'without a closed field vocabulary would be a free-form payload'
            );
        }
        foreach ($fields as $field => $rule) {
            $field = (string) $field;
            if (preg_match(self::ARG_NAME_PATTERN, $field) !== 1) {
                throw new \RuntimeException("$where.fields has an unbounded or malformed field name '$field'");
            }
            $ruleKeys = is_array($rule) ? array_keys($rule) : [];
            sort($ruleKeys, SORT_STRING);
            if ($ruleKeys !== ['required', 'type']) {
                throw new \RuntimeException("$where.fields.$field must declare exactly type and required");
            }
            if (!in_array($rule['type'], self::FIELD_TYPES, true)) {
                throw new \RuntimeException(
                    "$where.fields.$field.type must be one of " . implode(', ', self::FIELD_TYPES)
                    . ' — a list<object> nests exactly one level, so a row field may not itself be a list '
                    . 'or an object'
                );
            }
            if (!is_bool($rule['required'])) {
                throw new \RuntimeException("$where.fields.$field.required must be a boolean");
            }
        }
    }

    /**
     * Manifest arguments against the provider-declared schema. Unknown keys
     * refuse for the same reason the native-action vocabulary is closed: an
     * argument a manifest believes it passed, silently dropped, is a repair
     * that quietly does something other than what the declaration says.
     *
     * @param array<string,mixed> $args
     * @param array<string,array{type:string,required:bool,fields?:array<string,array{type:string,required:bool}>}> $schema
     */
    private static function validate_args(array $args, array $schema, string $where): void {
        $unknown = array_diff(array_keys($args), array_keys($schema));
        if ($unknown !== []) {
            throw new \RuntimeException(
                "$where contains argument(s) the capability does not declare: " . implode(', ', $unknown)
            );
        }
        foreach ($schema as $name => $rule) {
            if (!array_key_exists($name, $args)) {
                if ($rule['required']) {
                    throw new \RuntimeException("$where is missing required argument '$name'");
                }
                continue;
            }
            $value = $args[$name];
            $ok = match ($rule['type']) {
                'string' => is_string($value),
                'int' => is_int($value),
                'bool' => is_bool($value),
                'list<string>' => is_array($value) && array_is_list($value)
                    && array_filter($value, 'is_string') === $value,
                'list<object>' => is_array($value) && array_is_list($value),
            };
            if (!$ok) {
                throw new \RuntimeException("$where argument '$name' must be of type {$rule['type']}");
            }
            if ($rule['type'] === 'list<object>') {
                // A validated declaration always carries `fields` (the
                // declaration check above refuses the type without it); the
                // fallback keeps this fail-closed for any future caller
                // validating against a hand-built schema — an absent field
                // vocabulary then rejects every row rather than accepting any.
                self::validate_object_rows(
                    $value,
                    (array) ($rule['fields'] ?? []),
                    "$where argument '$name'"
                );
            }
        }
    }

    /**
     * The rows of one `list<object>` argument value against its declared field
     * vocabulary (DUO-3369).
     *
     * Same posture as the argument-level check one frame up, one level down: a
     * field the manifest believes it passed and the capability never declared
     * is silently dropped work, and a row that is really a nested list arrives
     * here as integer keys — reported as the unknown fields they are, so the
     * one-level bound is enforced on VALUES too and not only on declarations.
     *
     * @param list<mixed> $rows
     * @param array<string,array{type:string,required:bool}> $fields
     */
    private static function validate_object_rows(array $rows, array $fields, string $where): void {
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new \RuntimeException(
                    "$where row $index must be an object of " . implode(', ', array_keys($fields))
                    . ' (found: ' . get_debug_type($row) . ')'
                );
            }
            $unknown = array_diff(array_map('strval', array_keys($row)), array_keys($fields));
            if ($unknown !== []) {
                throw new \RuntimeException(
                    "$where row $index contains field(s) the capability does not declare: "
                    . implode(', ', $unknown) . ' (declared: ' . implode(', ', array_keys($fields)) . ')'
                );
            }
            foreach ($fields as $field => $rule) {
                if (!array_key_exists($field, $row)) {
                    if ($rule['required']) {
                        throw new \RuntimeException("$where row $index is missing required field '$field'");
                    }
                    continue;
                }
                $value = $row[$field];
                $ok = match ($rule['type']) {
                    'string' => is_string($value),
                    'int' => is_int($value),
                    'bool' => is_bool($value),
                };
                if (!$ok) {
                    throw new \RuntimeException(
                        "$where row $index field '$field' must be of type {$rule['type']} (found: "
                        . get_debug_type($value) . ')'
                    );
                }
            }
        }
    }

    /** @return array<string,mixed> */
    private static function problem(
        string $id,
        string $manifest,
        string $plugin,
        string $code,
        string $expected,
        string $found,
        string $remediation
    ): array {
        return [
            'provider' => $id,
            'manifest' => $manifest,
            'plugin' => $plugin,
            'code' => $code,
            'expected' => $expected,
            'found' => $found,
            'remediation' => $remediation,
            'message' => "provider '$id' (manifest '$manifest', plugin '$plugin'): $code — "
                . "expected $expected, found $found. $remediation.",
        ];
    }

    /** @param array<string,mixed> $identity */
    private static function describe(array $identity): string {
        if ($identity === []) {
            return 'nothing';
        }
        $parts = [];
        foreach ($identity as $key => $value) {
            $parts[] = $key . '=' . (is_scalar($value) ? (string) $value : get_debug_type($value));
        }
        return implode(' ', $parts);
    }
}

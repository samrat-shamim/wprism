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
     */
    public const ENTITIES_ARG = 'entities';

    private const ID_PATTERN = '/^[a-z][a-z0-9-]{0,63}$/D';
    private const CAPABILITY_PATTERN = '/^[a-z0-9_]{1,64}$/D';
    private const ARG_NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/D';
    private const ARG_TYPES = ['bool', 'int', 'list<string>', 'string'];

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
                $provider = $pluginSupplied[$id] ?? null;
                if ($provider === null) {
                    $problems[] = self::problem(
                        $id, $manifest, $plugin, 'missing_plugin_provider',
                        "a `duo_providers` filter entry with identity id '$id'",
                        $pluginSupplied === [] ? 'no plugin supplied any provider' : 'supplied: ' . implode(', ', array_keys($pluginSupplied)),
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

            $advertised = $provider->capabilities();
            if (!is_array($advertised) || array_is_list($advertised)) {
                $problems[] = self::problem(
                    $id, $manifest, $plugin, 'contract_shape',
                    'capabilities() returning a name => declaration map',
                    get_debug_type($advertised),
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
     * @return array{before:mixed, after:mixed, verified:true, duration_seconds:float}
     */
    public static function invoke(
        object $provider,
        array $actionEntry,
        array $capabilityDecl,
        array $entities
    ): array {
        $id = (string) $actionEntry['provider'];
        $capability = (string) $actionEntry['capability'];
        $args = (array) ($actionEntry['args'] ?? []);
        if (($capabilityDecl['scope'] ?? '') === 'entity') {
            $args[self::ENTITIES_ARG] = $entities;
        }
        $started = microtime(true);
        try {
            $receipt = $provider->invoke($capability, $args);
        } catch (\Throwable $t) {
            throw new \RuntimeException("duo: provider '$id' capability '$capability' failed", 0, $t);
        }
        $elapsed = microtime(true) - $started;

        $keys = is_array($receipt) ? array_keys($receipt) : [];
        sort($keys, SORT_STRING);
        if ($keys !== ['after', 'before', 'verified']) {
            throw new \RuntimeException(
                "duo: provider '$id' capability '$capability' returned a malformed receipt — "
                . 'exactly before, after, and verified are required (found: '
                . ($keys === [] ? get_debug_type($receipt) : implode(', ', $keys)) . ')'
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
     * @return array<string,object>
     */
    private static function plugin_supplied_providers(): array {
        if (!function_exists('apply_filters')) {
            return [];
        }
        $supplied = apply_filters('duo_providers', []);
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
            } catch (\Throwable $t) {
                continue;
            }
            $id = is_array($identity) ? (string) ($identity['id'] ?? '') : '';
            if ($id === '' || preg_match(self::ID_PATTERN, $id) !== 1 || isset($out[$id])) {
                continue;
            }
            $out[$id] = $entry;
        }
        return $out;
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
                "the provider class defined under $anchor",
                $real === false ? 'an unresolvable class file' : "class file $real",
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
                    get_class($provider) . " has no public $method()",
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
                'identity() threw: ' . $t->getMessage(),
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
                self::describe($found),
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
            $names = array_keys($advertised);
            sort($names, SORT_STRING);
            return self::problem(
                $id, $manifest, $plugin, 'missing_capability',
                "capability '$capability'",
                $names === [] ? 'no capabilities advertised' : 'advertised: ' . implode(', ', $names),
                "upgrade $plugin (or its adapter package) to a version advertising '$capability'"
            );
        }
        try {
            self::validate_capability_declaration($decl, "provider '$id' capability '$capability'");
        } catch (\Throwable $t) {
            return self::problem(
                $id, $manifest, $plugin, 'malformed_capability',
                'a well-formed capability declaration',
                $t->getMessage(),
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
                $t->getMessage(),
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
     */
    private static function validate_capability_declaration(array $decl, string $where): void {
        $keys = array_keys($decl);
        sort($keys, SORT_STRING);
        $expected = ['args', 'idempotent', 'reads', 'scope', 'timeout_seconds', 'writes'];
        if ($keys !== $expected) {
            throw new \RuntimeException(
                "$where must declare exactly " . implode(', ', $expected)
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
            if ($ruleKeys !== ['required', 'type']) {
                throw new \RuntimeException("$where.args.$arg must declare exactly type and required");
            }
            if (!in_array($rule['type'], self::ARG_TYPES, true)) {
                throw new \RuntimeException(
                    "$where.args.$arg.type must be one of " . implode(', ', self::ARG_TYPES)
                );
            }
            if (!is_bool($rule['required'])) {
                throw new \RuntimeException("$where.args.$arg.required must be a boolean");
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
     * @param array<string,array{type:string,required:bool}> $schema
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
            };
            if (!$ok) {
                throw new \RuntimeException("$where argument '$name' must be of type {$rule['type']}");
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

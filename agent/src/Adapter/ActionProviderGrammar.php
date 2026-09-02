<?php
namespace WPrism;

require_once __DIR__ . '/../Rebuild/NativeActions.php';
require_once __DIR__ . '/../Kernel/ActionTriggerMatcher.php';
require_once __DIR__ . '/AdapterSources.php';
// Circular with Policy.php's own require_once of this file: safe because
// require_once tracks Policy.php's path as included the moment Policy.php's
// OWN require statement for this file runs, before Policy.php's body
// finishes executing, so this resolves to a no-op rather than a re-include.
require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/ManifestProviderRuntime.php';

/**
 * The action/provider/effect half of issue #3348's "ManifestValidator with
 * grammar-specific validators" target seam (issue #3335), extracted from
 * `agent/src/Policy/Policy.php` — a coherent, self-contained cluster (its own
 * constants used nowhere outside it plus `Policy::closed_vocabularies()`/
 * `grammar_patterns()`) covering the structured rebuild-action channel
 * (issue #3338), provider identity/capability declarations, issue #3317's provider
 * `requires` environment grammar, and the bounded reversibility/effect
 * grammar (issue #3318). Everything here is checkable offline, before any
 * provider code runs or target contact happens; negotiation against the
 * live environment (`Providers::negotiate()`) is a separate, later gate.
 *
 * Moved verbatim. `validate_actions()`, `validate_providers()`,
 * `validate_no_conflicting_provider_ids()`, and `validate_effect_contracts()`
 * are the only methods any external caller ever reached (Policy's own
 * `load()`/`from_snapshot()`, grep-verified) — matching PinResolver's
 * precedent (issue #3348 slice 5), no compatibility facade exists on Policy for
 * them; the four call sites now call this class directly. Every other method
 * here is a private internal helper with no external caller.
 *
 * `Policy::SURFACE_PATTERN` (the `post|term|table|option|entity:<name>`
 * shape) stays on Policy — it is used well beyond this cluster — and is
 * referenced from here as `Policy::SURFACE_PATTERN` rather than moved.
 */
final class ActionProviderGrammar {
    /** Feature gate for the one bounded post-kind action trigger. */
    public const POST_KIND_TRIGGER_FEATURE = 'post-kind-action-trigger/v1';

    /** Feature gate for host-checkpointed, pre-observation table establishment. */
    public const SCHEMA_SETTLEMENT_FEATURE = 'schema-settlement/v1';

    /** The closed `actions[].kind` vocabulary — the two trust tiers, nothing else. */
    private const ACTION_KINDS = ['native', 'provider'];

    /** Provider-only execution phases which are not ordinary state-triggered rebuilds. */
    private const ACTION_PHASES = ['lifecycle_settle', 'schema_settle'];

    /** @return list<string> Policy::closed_vocabularies()'s read of ACTION_KINDS. */
    public static function actionKinds(): array {
        return self::ACTION_KINDS;
    }

    /** @return list<string> Policy::closed_vocabularies()'s read of ACTION_PHASES. */
    public static function actionPhases(): array {
        return self::ACTION_PHASES;
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

    /** @return string Policy::grammar_patterns()'s read of PROVIDER_ID_PATTERN. */
    public static function providerIdPattern(): string {
        return self::PROVIDER_ID_PATTERN;
    }

    /** @return string Policy::grammar_patterns()'s read of PROVIDER_VERSION_PATTERN. */
    public static function providerVersionPattern(): string {
        return self::PROVIDER_VERSION_PATTERN;
    }

    /** @return string Policy::grammar_patterns()'s read of CAPABILITY_NAME_PATTERN. */
    public static function capabilityNamePattern(): string {
        return self::CAPABILITY_NAME_PATTERN;
    }

    /** @return list<string> Policy::closed_vocabularies()'s read of PROVIDER_SOURCES. */
    public static function providerSources(): array {
        return self::PROVIDER_SOURCES;
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

    /** @return list<string> Policy::closed_vocabularies()'s read of EFFECT_KINDS. */
    public static function effectKinds(): array {
        return self::EFFECT_KINDS;
    }

    /** @return list<string> Policy::closed_vocabularies()'s read of EFFECT_MODES. */
    public static function effectModes(): array {
        return self::EFFECT_MODES;
    }

    /** @return list<string> Policy::closed_vocabularies()'s read of SELECTOR_SCOPES. */
    public static function effectSelectorScopes(): array {
        return self::SELECTOR_SCOPES;
    }

    /** @return list<string> Policy::closed_vocabularies()'s read of SELECTOR_TYPES. */
    public static function effectSelectorTypes(): array {
        return self::SELECTOR_TYPES;
    }

    /** @return string Policy::grammar_patterns()'s read of EFFECT_ID_PATTERN. */
    public static function effectIdPattern(): string {
        return self::EFFECT_ID_PATTERN;
    }

    /** The closed typed-placeholder vocabulary a provider-resource template may use. */
    private const MEMBER_PLACEHOLDERS = ['positive_uint', 'slug'];

    /** @return list<string> Policy::closed_vocabularies()'s read of MEMBER_PLACEHOLDERS. */
    public static function providerResourcePlaceholders(): array {
        return self::MEMBER_PLACEHOLDERS;
    }

    /**
     * Validate the structured rebuild-action channel (issue #3338).
     *
     * The retired `rebuilders` channel let a manifest name a wp-cli command
     * string — including `eval '<php>'` — that Apply then executed verbatim.
     * The boundary doctrine (docs/adapter-boundary.md §1)
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
    public static function validate_actions(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        if (array_key_exists('rebuilders', $manifest)) {
            throw new \RuntimeException(
                "wprism: manifest '$name' declares the retired free-form `rebuilders` channel; "
                . 'migrate to structured `actions` (native or provider) — see spec/repo-format.md'
            );
        }
        if (!array_key_exists('actions', $manifest)) {
            return;
        }
        $actions = $manifest['actions'];
        if (!is_array($actions) || !array_is_list($actions)) {
            throw new \RuntimeException("wprism: manifest '$name' actions must be a list");
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
                throw new \RuntimeException("wprism: $where must be an object");
            }
            $kind = $action['kind'] ?? null;
            if (!in_array($kind, self::ACTION_KINDS, true)) {
                throw new \RuntimeException("wprism: $where.kind must be \"native\" or \"provider\"");
            }
            // `effects` is optional; it is in the allowed set so the effect
            // validator can supply its normal explicit irreversible fallback
            // when omitted, exactly as the retired channel did.
            $allowed = $kind === 'native'
                ? ['action', 'args', 'effects', 'kind', 'triggers']
                : [
                    'args', 'capability', 'effects', 'kind', 'phase', 'prepares',
                    'provider', 'readiness', 'triggers',
                ];
            $keys = array_keys($action);
            sort($keys, SORT_STRING);
            $unknown = array_diff($keys, $allowed);
            if ($unknown !== []) {
                throw new \RuntimeException(
                    "wprism: $where contains unknown key(s): " . implode(', ', $unknown)
                );
            }
            $declaredArgs = $action['args'] ?? null;
            // `{}` decodes to an empty PHP array, which array_is_list() calls
            // a list — an argument-free action must stay expressible.
            if (!is_array($declaredArgs) || (array_is_list($declaredArgs) && $declaredArgs !== [])) {
                throw new \RuntimeException("wprism: $where.args must be an object");
            }
            if ($kind === 'native') {
                if (!is_string($action['action'] ?? null)) {
                    throw new \RuntimeException("wprism: $where.action must be a string");
                }
                NativeActions::validate((string) $action['action'], $action['args'], "$where");
            } else {
                self::validate_provider_action($action, $providers, $where, $name);
            }
            if (array_key_exists('phase', $action)) {
                $phase = $action['phase'] ?? null;
                if ($kind !== 'provider' || !in_array($phase, self::ACTION_PHASES, true)) {
                    throw new \RuntimeException(
                        "wprism: $where.phase must be lifecycle_settle or schema_settle on a provider action"
                    );
                }
                if (array_key_exists('triggers', $action)) {
                    throw new \RuntimeException(
                        $phase === 'lifecycle_settle'
                            ? "wprism: $where lifecycle settlement is selected by a verified code transition, not state triggers"
                            : "wprism: $where schema preparation is selected by an exact compiled policy, not state triggers"
                    );
                }
                if ($phase === 'schema_settle') {
                    if (!in_array(
                        self::SCHEMA_SETTLEMENT_FEATURE,
                        (array) ($manifest['engine_features'] ?? []),
                        true
                    )) {
                        throw new \RuntimeException(
                            "wprism: $where uses schema_settle without engine feature '"
                            . self::SCHEMA_SETTLEMENT_FEATURE
                            . "' — declare it in this manifest's sorted engine_features list"
                        );
                    }
                    self::validate_schema_preparation($action, $manifest, $where);
                } elseif (array_key_exists('prepares', $action)
                    || array_key_exists('readiness', $action)) {
                    throw new \RuntimeException(
                        "wprism: $where.prepares/readiness are valid only on a schema_settle provider action"
                    );
                }
            } elseif (array_key_exists('prepares', $action)
                || array_key_exists('readiness', $action)) {
                throw new \RuntimeException(
                    "wprism: $where.prepares/readiness are valid only on a schema_settle provider action"
                );
            }
            if (!array_key_exists('triggers', $action)) {
                continue;
            }
            $triggers = $action['triggers'];
            if (!is_array($triggers) || !array_is_list($triggers) || $triggers === []) {
                throw new \RuntimeException("wprism: $where.triggers must be a non-empty list");
            }
            $seen = [];
            foreach ($triggers as $triggerIndex => $trigger) {
                if ($trigger === ActionTriggerMatcher::POST_KIND_TRIGGER
                    && !in_array(self::POST_KIND_TRIGGER_FEATURE, (array) ($manifest['engine_features'] ?? []), true)) {
                    throw new \RuntimeException(
                        "wprism: $where.triggers[$triggerIndex] uses bounded trigger 'post:*' without engine feature '"
                        . self::POST_KIND_TRIGGER_FEATURE
                        . "' — declare it in this manifest's sorted engine_features list"
                    );
                }
                if (!is_string($trigger) || preg_match(Policy::SURFACE_PATTERN, $trigger) !== 1) {
                    if ($trigger === ActionTriggerMatcher::POST_KIND_TRIGGER) {
                        // The feature-gated value is the only intentional
                        // exception to the exact canonical-surface grammar.
                    } else {
                        throw new \RuntimeException(
                            "wprism: $where.triggers[$triggerIndex] must be one exact canonical surface "
                            . '(post|term|table|option|entity):<lowercase-name>, or the feature-gated bounded post:* trigger'
                        );
                    }
                }
                if (isset($seen[$trigger])) {
                    throw new \RuntimeException("wprism: $where.triggers repeats exact surface '$trigger'");
                }
                $seen[$trigger] = true;
            }
        }
        self::validate_manifest_channel_ownership($actions, $providers, $name);
    }

    /**
     * Refuse a manifest-runtime collision while the package is still data.
     *
     * ManifestProviderRuntime advertises its validated `contracts` map
     * byte-for-byte, so waiting for live negotiation to discover two
     * capabilities claiming one durable post/channel keyspace adds no target
     * evidence. Plugin-sourced providers remain a runtime question because
     * their executable advertises the contract; Providers::negotiate() keeps
     * the same fail-closed gate for that case and for cross-manifest claims.
     *
     * @param list<array<string,mixed>> $actions
     * @param array<string,array<string,mixed>> $providers
     */
    private static function validate_manifest_channel_ownership(
        array $actions,
        array $providers,
        string $manifestName
    ): void {
        $claims = [];
        foreach ($actions as $action) {
            if (($action['kind'] ?? null) !== 'provider') {
                continue;
            }
            $provider = (string) ($action['provider'] ?? '');
            $capability = (string) ($action['capability'] ?? '');
            $declaration = $providers[$provider] ?? null;
            $contract = is_array($declaration)
                ? ($declaration['contracts'][$capability] ?? null)
                : null;
            if (($declaration['source'] ?? null) !== 'manifest' || !is_array($contract)) {
                continue;
            }
            foreach ((array) ($contract['context'] ?? []) as $channel) {
                foreach ((array) ($action['triggers'] ?? []) as $trigger) {
                    if (!is_string($trigger) || !str_starts_with($trigger, 'post:')) {
                        continue;
                    }
                    $claims[(string) $channel][$trigger]["$provider/$capability"] = true;
                }
            }
        }
        ksort($claims, SORT_STRING);
        foreach ($claims as $channel => $surfaces) {
            ksort($surfaces, SORT_STRING);
            foreach ($surfaces as $surface => $claimants) {
                if (count($claimants) < 2) {
                    continue;
                }
                $names = array_keys($claimants);
                sort($names, SORT_STRING);
                throw new \RuntimeException(
                    "wprism: manifest '$manifestName' declares " . count($names)
                    . " capabilities consuming the '$channel' channel for $surface: " . implode(', ', $names)
                    . '; exactly one capability may own a durable channel/surface keyspace — narrow the '
                    . 'contexts or triggers, or fold the repairs into one capability'
                );
            }
        }
    }

    /**
     * Bound the host-only phase which establishes tables before strict target
     * observation. `prepares`, provider writes, and recovery effects are one
     * exact set: a schema capability cannot smuggle an unrelated mutation into
     * the checkpoint window, and planning never fabricates any of these tables.
     */
    private static function validate_schema_preparation(array $action, array $manifest, string $where): void {
        $prepares = $action['prepares'] ?? null;
        if (!is_array($prepares) || !array_is_list($prepares) || $prepares === []) {
            throw new \RuntimeException(
                "wprism: $where.prepares must be a non-empty sorted list of declared table names"
            );
        }
        $normalized = [];
        foreach ($prepares as $index => $table) {
            if (!is_string($table)
                || preg_match('/^[a-z0-9][a-z0-9_]{0,63}$/D', $table) !== 1) {
                throw new \RuntimeException(
                    "wprism: $where.prepares[$index] must be a lowercase declared table name"
                );
            }
            if (!is_array($manifest['tables'][$table] ?? null)) {
                throw new \RuntimeException(
                    "wprism: $where.prepares names undeclared table '$table'"
                );
            }
            if (isset($normalized[$table])) {
                throw new \RuntimeException("wprism: $where.prepares repeats table '$table'");
            }
            $normalized[$table] = true;
        }
        $sorted = array_keys($normalized);
        sort($sorted, SORT_STRING);
        if ($sorted !== $prepares) {
            throw new \RuntimeException("wprism: $where.prepares must be sorted lexically");
        }
        if (($action['args'] ?? null) !== []) {
            throw new \RuntimeException("wprism: $where schema_settle args must be an empty object");
        }

        $readiness = $action['readiness'] ?? null;
        $providerId = $action['provider'] ?? null;
        $provider = null;
        foreach ((array) ($manifest['providers'] ?? []) as $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? null) === $providerId) {
                $provider = $candidate;
                break;
            }
        }
        if (!is_string($readiness)
            || preg_match(self::CAPABILITY_NAME_PATTERN, $readiness) !== 1
            || $readiness === ($action['capability'] ?? null)
            || !is_array($provider)
            || !in_array($readiness, (array) ($provider['capabilities'] ?? []), true)) {
            throw new \RuntimeException(
                "wprism: $where.readiness must name a distinct capability on the same provider"
            );
        }
        $requiredSurfaces = array_map(static fn(string $table): string => 'table:' . $table, $prepares);
        sort($requiredSurfaces, SORT_STRING);
        $contractSurfaces = static function (mixed $contract, string $key): ?array {
            if (!is_array($contract)) {
                return null;
            }
            $surfaces = $contract[$key] ?? null;
            if (!is_array($surfaces) || !array_is_list($surfaces)) {
                return null;
            }
            foreach ($surfaces as $surface) {
                if (!is_string($surface)) {
                    return null;
                }
            }
            sort($surfaces, SORT_STRING);
            return $surfaces;
        };

        $readinessContract = $provider['contracts'][$readiness] ?? null;
        if (array_key_exists('contracts', $provider)) {
            if (!is_array($readinessContract)
                || ($readinessContract['args'] ?? null) !== []
                || ($readinessContract['idempotent'] ?? null) !== true
                || ($readinessContract['scope'] ?? null) !== 'site'
                || $contractSurfaces($readinessContract, 'reads') !== $requiredSurfaces
                || ($readinessContract['writes'] ?? null) !== []) {
                throw new \RuntimeException(
                    "wprism: $where.readiness must be an idempotent argument-free site capability "
                    . 'which reads exactly prepares and writes nothing'
                );
            }

            $prepareContract = $provider['contracts'][$action['capability']] ?? null;
            if (!is_array($prepareContract)
                || ($prepareContract['args'] ?? null) !== []
                || ($prepareContract['idempotent'] ?? null) !== true
                || ($prepareContract['scope'] ?? null) !== 'site'
                || $contractSurfaces($prepareContract, 'reads') !== $requiredSurfaces
                || $contractSurfaces($prepareContract, 'writes') !== $requiredSurfaces) {
                throw new \RuntimeException(
                    "wprism: $where.capability must be an idempotent argument-free site capability "
                    . 'which reads and writes exactly prepares'
                );
            }
        }

        $effectTables = [];
        $effects = $action['effects'] ?? null;
        if (!is_array($effects) || !array_is_list($effects)) {
            throw new \RuntimeException(
                "wprism: $where schema_settle effects must exactly cover prepares tables"
            );
        }
        foreach ($effects as $effect) {
            if (!is_array($effect)
                || ($effect['kind'] ?? null) !== 'database'
                || ($effect['mode'] ?? null) !== 'restorable'
                || ($effect['selector']['scope'] ?? null) !== 'database_checkpoint'
                || ($effect['selector']['type'] ?? null) !== 'table'
                || !is_string($effect['selector']['value'] ?? null)) {
                throw new \RuntimeException(
                    "wprism: $where schema_settle effects must be restorable database_checkpoint table selectors"
                );
            }
            $effectTable = $effect['selector']['value'];
            if (isset($effectTables[$effectTable])) {
                throw new \RuntimeException(
                    "wprism: $where schema_settle effects repeat table '$effectTable'"
                );
            }
            $effectTables[$effectTable] = true;
        }
        $effectTableNames = array_keys($effectTables);
        sort($effectTableNames, SORT_STRING);
        if ($effectTableNames !== $prepares) {
            throw new \RuntimeException(
                "wprism: $where schema_settle effects must exactly cover prepares tables"
            );
        }
    }

    /**
     * The load-time half of a provider-kind action's contract.
     *
     * A manifest is data, so this is everything checkable without the
     * provider's code: the referenced provider is declared by THIS manifest
     * (a manifest may not reach into another pinned adapter's provider — that
     * would make one adapter's behavior depend on another's pin), the
     * capability name is one this manifest's declaration advertises, and the
     * arguments are a flat structure of scalars, scalar lists, or (issue #3369)
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
                "wprism: $where.provider must name a `providers` entry declared by manifest '$manifestName'"
            );
        }
        $capability = $action['capability'] ?? null;
        if (!is_string($capability) || preg_match(self::CAPABILITY_NAME_PATTERN, $capability) !== 1) {
            throw new \RuntimeException("wprism: $where.capability must match ^[a-z0-9_]{1,64}$");
        }
        if (!in_array($capability, (array) ($providers[$id]['capabilities'] ?? []), true)) {
            throw new \RuntimeException(
                "wprism: $where.capability '$capability' is not listed in provider '$id' declaration's capabilities"
            );
        }
        foreach ((array) $action['args'] as $key => $value) {
            if (!is_string($key) || preg_match(self::CAPABILITY_NAME_PATTERN, $key) !== 1) {
                throw new \RuntimeException("wprism: $where.args keys must match ^[a-z0-9_]{1,64}$");
            }
            // The wording keeps the pre-issue #3369 sentence intact (existing
            // refusal coverage matches on it) and states the one shape that
            // was added, rather than describing a looser rule than the code.
            $shapeRefusal = "wprism: $where.args.$key must be a scalar or a list of scalars"
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
                                "wprism: $where.args.$key row $index field names must match ^[a-z0-9_]{1,64}$"
                            );
                        }
                        if (!is_scalar($fieldValue)) {
                            throw new \RuntimeException(
                                "wprism: $where.args.$key row $index field '$field' must be a scalar — "
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
     * issue #3317: the closed `requires` grammar a provider declaration may add,
     * naming the environment its executable half needs before negotiation will
     * load it. Plugin-agnostic and checked entirely offline: `functions` and
     * `classes` are non-empty lists of PHP symbol names (a leading backslash
     * and namespace separators allowed, because the names land verbatim in the
     * operator-facing found/expected strings and must stay a bounded charset);
     * `php_version`, `plugin_version`, and `wordpress_version` are {min,max}
     * windows sharing the one predicate above. An empty object is refused for
     * the same reason validate_capability_declaration() refuses an empty
     * `context`: it would declare the envelope while carrying nothing, so an
     * author reading their own declaration and the negotiation it drives would
     * disagree about whether the engine honored the key at all.
     *
     * @param mixed $requires
     */
    private static function validate_provider_requires(mixed $requires, string $where): void {
        if (!is_array($requires) || array_is_list($requires) || $requires === []) {
            throw new \RuntimeException(
                "wprism: $where must be a non-empty object naming the environment the provider needs "
                . '(one or more of classes, functions, php_version, plugin_version, wordpress_version)'
            );
        }
        $closed = ['classes', 'functions', 'php_version', 'plugin_version', 'wordpress_version'];
        $unknown = array_diff(array_keys($requires), $closed);
        if ($unknown !== []) {
            throw new \RuntimeException(
                "wprism: $where names unknown requirement(s) " . implode(', ', $unknown)
                . ' — the closed set is ' . implode(', ', $closed)
            );
        }
        foreach (['functions', 'classes'] as $key) {
            if (!array_key_exists($key, $requires)) {
                continue;
            }
            $names = $requires[$key];
            if (!is_array($names) || !array_is_list($names) || $names === []) {
                throw new \RuntimeException("wprism: $where.$key must be a non-empty list of PHP symbol names");
            }
            $seen = [];
            foreach ($names as $symbol) {
                if (!is_string($symbol) || preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $symbol) !== 1) {
                    throw new \RuntimeException(
                        "wprism: $where.$key must contain only PHP symbol names "
                        . 'matching ^\?[A-Za-z_][A-Za-z0-9_\\\\]*$ (a leading backslash and namespace separators allowed)'
                    );
                }
                if (isset($seen[$symbol])) {
                    throw new \RuntimeException("wprism: $where.$key repeats '$symbol'");
                }
                $seen[$symbol] = true;
            }
        }
        foreach (['php_version', 'plugin_version', 'wordpress_version'] as $key) {
            if (!array_key_exists($key, $requires)) {
                continue;
            }
            $range = $requires[$key];
            Policy::assert_min_max_range(is_array($range) ? $range : [], "$where.$key");
        }
    }

    /**
     * Validate one manifest's `providers` declarations.
     *
     * A declaration identifies executable plugin semantics: which package
     * supplies them (`source`), which plugin owns them (`plugin`), which exact
     * provider version the manifest was authored against, and the closed set
     * of capability names actions may reference. A v3 manifest-owned provider
     * may additionally declare `contracts`, moving advertising and dispatch
     * protocol into core; a plugin-owned provider advertises the same contract
     * from its independently shipped code. Everything here is checkable
     * offline; presence and identity are still negotiated against the live
     * environment before mutation (Providers::negotiate()).
     *
     * `plugin` must agree with the manifest's own `plugin` claim when it has
     * one: a manifest already declares exactly one plugin plus the version
     * range its classification guarantees hold for (validate_adapter_contract
     * above), and a provider naming a different plugin would silently escape
     * that version-bounded claim.
     */
    public static function validate_providers(array $manifest): void {
        if (!array_key_exists('providers', $manifest)) {
            return;
        }
        $name = (string) ($manifest['name'] ?? '?');
        $providers = $manifest['providers'];
        if (!is_array($providers) || !array_is_list($providers)) {
            throw new \RuntimeException("wprism: manifest '$name' providers must be a list");
        }
        $seenIds = [];
        foreach ($providers as $i => $declaration) {
            $where = "manifest '$name' providers[$i]";
            if (!is_array($declaration) || array_is_list($declaration)) {
                throw new \RuntimeException("wprism: $where must be an object");
            }
            $keys = array_keys($declaration);
            sort($keys, SORT_STRING);
            // Required/optional split (the validate_capability_declaration()
            // idiom) rather than an exact match, so issue #3317's `requires` can
            // join as the one OPTIONAL key without every other declaration
            // having to carry it.
            $required = ['capabilities', 'id', 'plugin', 'source', 'version'];
            $optional = ['contracts', 'requires'];
            $missing = array_diff($required, $keys);
            $unknown = array_diff($keys, $required, $optional);
            if ($missing !== [] || $unknown !== []) {
                throw new \RuntimeException(
                    "wprism: $where must declare exactly " . implode(', ', $required)
                    . ' (optional: ' . implode(', ', $optional) . ')'
                    . ' (found: ' . ($keys === [] ? 'nothing' : implode(', ', $keys)) . ')'
                );
            }
            $id = $declaration['id'];
            if (!is_string($id) || preg_match(self::PROVIDER_ID_PATTERN, $id) !== 1) {
                throw new \RuntimeException("wprism: $where.id must match ^[a-z][a-z0-9-]{0,63}$");
            }
            if (isset($seenIds[$id])) {
                throw new \RuntimeException("wprism: manifest '$name' declares provider id '$id' more than once");
            }
            $seenIds[$id] = true;
            if (!is_string($declaration['version'])
                || preg_match(self::PROVIDER_VERSION_PATTERN, $declaration['version']) !== 1) {
                throw new \RuntimeException(
                    "wprism: $where.version must be an exact <major>.<minor>.<patch> string"
                );
            }
            if (!in_array($declaration['source'], self::PROVIDER_SOURCES, true)) {
                throw new \RuntimeException("wprism: $where.source must be \"manifest\" or \"plugin\"");
            }
            $plugin = AdapterSources::assert_plugin_basename($declaration['plugin'], "$where.plugin");
            $manifestPlugin = $manifest['plugin'] ?? null;
            if (is_string($manifestPlugin) && $manifestPlugin !== '' && $manifestPlugin !== $plugin) {
                throw new \RuntimeException(
                    "wprism: $where.plugin '$plugin' disagrees with manifest '$name' plugin '$manifestPlugin' — "
                    . "a provider's owning plugin must be the plugin whose version_range bounds this adapter"
                );
            }
            $capabilities = $declaration['capabilities'];
            if (!is_array($capabilities) || !array_is_list($capabilities) || $capabilities === []) {
                throw new \RuntimeException("wprism: $where.capabilities must be a non-empty list");
            }
            $seenCapabilities = [];
            foreach ($capabilities as $j => $capability) {
                if (!is_string($capability) || preg_match(self::CAPABILITY_NAME_PATTERN, $capability) !== 1) {
                    throw new \RuntimeException("wprism: $where.capabilities[$j] must match ^[a-z0-9_]{1,64}$");
                }
                if (isset($seenCapabilities[$capability])) {
                    throw new \RuntimeException("wprism: $where.capabilities repeats '$capability'");
                }
                $seenCapabilities[$capability] = true;
            }
            if (array_key_exists('contracts', $declaration)) {
                if (($declaration['source'] ?? null) !== 'manifest') {
                    throw new \RuntimeException(
                        "wprism: $where.contracts is only valid for source: manifest — plugin-sourced providers "
                        . 'must advertise their own executable contract'
                    );
                }
                $features = (array) ($manifest['engine_features'] ?? []);
                if (!in_array(ManifestProviderRuntime::FEATURE, $features, true)) {
                    throw new \RuntimeException(
                        "wprism: $where.contracts requires engine feature '" . ManifestProviderRuntime::FEATURE
                        . "' — declare it in this manifest's sorted engine_features list"
                    );
                }
                $contracts = $declaration['contracts'];
                if (!is_array($contracts) || $contracts === [] || array_is_list($contracts)) {
                    throw new \RuntimeException(
                        "wprism: $where.contracts must be a non-empty capability name => contract object map"
                    );
                }
                if (array_keys($contracts) !== $capabilities) {
                    throw new \RuntimeException(
                        "wprism: $where.contracts keys must exactly follow capabilities (expected: "
                        . implode(', ', $capabilities) . '; found: ' . implode(', ', array_keys($contracts)) . ')'
                    );
                }
                // Lazy to preserve Policy.php's standalone load shape: v2
                // declarations never need the runtime capability grammar,
                // while a v3 contracts map must be checked by the SAME
                // validator live negotiation uses rather than a second copy.
                require_once __DIR__ . '/Providers.php';
                foreach ($contracts as $capability => $contract) {
                    if (!is_array($contract) || array_is_list($contract)) {
                        throw new \RuntimeException(
                            "wprism: $where.contracts.$capability must be a capability contract object"
                        );
                    }
                    Providers::validate_capability_declaration(
                        $contract,
                        "$where.contracts.$capability"
                    );
                    if (array_key_exists('scoped', $contract)) {
                        Providers::validate_scoped_capability_declaration(
                            $contract,
                            "$where.contracts.$capability"
                        );
                    }
                }
            }
            if (array_key_exists('requires', $declaration)) {
                self::validate_provider_requires($declaration['requires'], "$where.requires");
            }
        }
    }

    /**
     * Cross-manifest guard, run once after every pinned manifest has loaded —
     * the provider twin of AdapterContractGrammar::validate_no_conflicting_adapter_claims() above,
     * with the same rationale: a provider id resolves to concrete executable
     * code (a manifests/providers/<id>.php file, or a `wprism_providers`
     * registration), so two pinned manifests claiming one id makes which code
     * runs depend on pin order. There is no composition grammar in v1;
     * rename one of the ids.
     *
     * @param list<array<string,mixed>> $manifests
     */
    public static function validate_no_conflicting_provider_ids(array $manifests): void {
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
                        "wprism: manifests '{$seen[$id]}' and '$name' both declare provider id '$id' — "
                        . 'a provider id names one concrete implementation and may not depend on pin order; '
                        . 'rename one declaration'
                    );
                }
                $seen[$id] = $name;
            }
        }
    }

    /**
     * One physical table has one schema authority. Two pinned providers may
     * both write a table after apply, but they may not both claim authority to
     * make its schema exist before the first target observation.
     *
     * @param list<array<string,mixed>> $manifests
     */
    public static function validate_no_conflicting_schema_settlements(array $manifests): void {
        $seen = [];
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '?');
            foreach ((array) ($manifest['actions'] ?? []) as $index => $action) {
                if (!is_array($action) || ($action['phase'] ?? null) !== 'schema_settle') {
                    continue;
                }
                foreach ((array) ($action['prepares'] ?? []) as $table) {
                    if (!is_string($table) || $table === '') {
                        continue;
                    }
                    if (isset($seen[$table])) {
                        throw new \RuntimeException(
                            "wprism: manifests '{$seen[$table]['manifest']}' and '$name' both settle schema for "
                            . "table '$table' — pre-observation schema authority may not depend on pin order"
                        );
                    }
                    $seen[$table] = ['manifest' => $name, 'index' => (int) $index];
                }
            }
        }
    }

    /** Validate the bounded reversibility grammar without target contact. */
    public static function validate_effect_contracts(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        $groups = [];
        if (array_key_exists('lifecycle_effects', $manifest)) {
            $groups['lifecycle_effects'] = $manifest['lifecycle_effects'];
        }
        foreach ((array) ($manifest['actions'] ?? []) as $i => $action) {
            if (is_array($action) && array_key_exists('effects', $action)) {
                $effects = $action['effects'];
                if ($effects === [] && ($action['kind'] ?? null) === 'provider') {
                    // An explicit empty list is a positive read-only claim,
                    // not the same thing as an omitted effect contract. The
                    // target-facing negotiation gate independently requires
                    // this exact capability to advertise `writes: []`; that
                    // keeps a manifest from erasing a writer's recovery
                    // obligations while allowing a pure prerequisite/readback
                    // capability to exist without inventing an effect.
                    continue;
                }
                if ($effects === [] && ($action['kind'] ?? null) === 'native') {
                    throw new \RuntimeException(
                        "wprism: manifest '$name' actions[$i].effects may be empty only for a provider action "
                        . 'whose negotiated capability advertises writes: []'
                    );
                }
                $groups["actions[$i].effects"] = $effects;
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
                throw new \RuntimeException("wprism: manifest '$name' $where must be a non-empty list");
            }
            foreach ($effects as $i => $effect) {
                self::validate_effect($effect, "manifest '$name' {$where}[$i]");
                $id = (string) $effect['id'];
                if (isset($seen[$id])) {
                    throw new \RuntimeException("wprism: manifest '$name' repeats effect id '$id' in {$where}[$i] and {$seen[$id]}");
                }
                $seen[$id] = "{$where}[$i]";
            }
        }
    }

    private static function validate_effect(mixed $effect, string $where): void {
        if (!is_array($effect) || array_is_list($effect)) {
            throw new \RuntimeException("wprism: $where must be an object");
        }
        $mode = $effect['mode'] ?? null;
        $kind = $effect['kind'] ?? null;
        $expected = ['id', 'kind', 'mode', 'selector'];
        if ($mode === 'reversible') {
            $expected[] = 'adapter';
        } elseif ($mode === 'prevented') {
            $expected[] = 'prevention';
        }
        $actual = array_keys($effect);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException("wprism: $where has missing or unknown fields for mode " . var_export($mode, true));
        }
        if (!is_string($effect['id'] ?? null)
            || preg_match(self::EFFECT_ID_PATTERN, (string) $effect['id']) !== 1) {
            throw new \RuntimeException("wprism: $where.id must be a bounded lowercase identifier");
        }
        // issue #3318: two closed vocabularies, two messages. One combined
        // refusal made an author guess which half they got wrong, and never
        // printed either legal set — the same declaration would be edited,
        // re-run, and refused again on the other field.
        $effectKinds = self::EFFECT_KINDS;
        $effectModes = self::EFFECT_MODES;
        if (!in_array($kind, $effectKinds, true)) {
            throw new \RuntimeException(
                "wprism: $where.kind=" . var_export($kind, true) . ' is not one of the engine-owned effect kinds ('
                . implode(', ', $effectKinds) . ') — a kind names the CATEGORY of thing an effect touches, which '
                . 'the recovery controller has to understand to plan a rollback, so the set is an engine change '
                . 'with a spec bump, not a manifest declaration'
            );
        }
        if (!in_array($mode, $effectModes, true)) {
            throw new \RuntimeException(
                "wprism: $where.mode=" . var_export($mode, true) . ' is not one of the engine-owned reversibility '
                . 'modes (' . implode(', ', $effectModes) . ') — a mode states how this effect is UNDONE, and each '
                . 'value binds the declaration to different required evidence (restorable: checkpoint coverage; '
                . 'reversible: a version-pinned inverse+verifier adapter; prevented: receipt-outbox isolation; '
                . 'irreversible: an explicit automatic-promotion blocker). Widening the set is an engine change '
                . 'with a spec bump'
            );
        }
        $selector = $effect['selector'] ?? null;
        if (!is_array($selector) || array_is_list($selector)) {
            throw new \RuntimeException("wprism: $where.selector must be an object");
        }
        $scope = $selector['scope'] ?? null;
        $type = $selector['type'] ?? null;
        $value = $selector['value'] ?? null;
        $keys = array_keys($selector);
        sort($keys, SORT_STRING);
        $expectedSelectorKeys = ['scope', 'type', 'value'];
        if ($type === 'provider_resource' && array_key_exists('members', $selector)) {
            $expectedSelectorKeys[] = 'members';
        }
        sort($expectedSelectorKeys, SORT_STRING);
        if ($keys !== $expectedSelectorKeys) {
            throw new \RuntimeException(
                $type === 'provider_resource'
                    ? "wprism: $where.selector requires exactly scope, type, value, and optional members"
                    : "wprism: $where.selector requires exactly scope, type, and value"
            );
        }
        // issue #3318: four independent causes used to share one message that
        // named none of them, so an author saw "empty, unbounded,
        // secret-shaped, or unsupported" for a selector that was, in fact,
        // exactly one of those — and had to bisect their own declaration to
        // find out which. Each cause now names itself and, where it is a
        // closed set, prints the set.
        $selectorScopes = self::SELECTOR_SCOPES;
        $selectorTypes = self::SELECTOR_TYPES;
        if (!in_array($scope, $selectorScopes, true)) {
            throw new \RuntimeException(
                "wprism: $where.selector.scope=" . var_export($scope, true) . ' is not one of the engine-owned scopes '
                . '(' . implode(', ', $selectorScopes) . ') — the scope states whether the encrypted database '
                . 'checkpoint already covers this effect or whether it reaches outside it, which is the recovery '
                . "controller's own decision to make; the set is an engine change with a spec bump"
            );
        }
        if (!in_array($type, $selectorTypes, true)) {
            throw new \RuntimeException(
                "wprism: $where.selector.type=" . var_export($type, true) . ' is not one of the engine-owned selector '
                . 'types (' . implode(', ', $selectorTypes) . ') — each type is a resource shape the engine knows '
                . 'how to bound and verify; an adapter names a resource the engine cannot bound through '
                . '`provider_resource` plus its own provider, never by minting a type'
            );
        }
        if (!is_string($value) || $value === '' || strlen($value) > 512) {
            throw new \RuntimeException(
                "wprism: $where.selector.value must be a non-empty string of at most 512 bytes (got "
                . (is_string($value) ? strlen($value) . ' bytes' : gettype($value)) . ')'
            );
        }
        if (preg_match('/[\x00-\x1f\x7f*]/', $value) === 1) {
            throw new \RuntimeException(
                "wprism: $where.selector.value " . var_export($value, true) . ' contains a wildcard or control '
                . 'character — a selector names exact resources, because rollback authority is bounded by what '
                . 'the declaration can enumerate; use selector.members to declare an aggregate instead'
            );
        }
        if (preg_match('/secret|credential|password|authorization|signed.?url|access.?token|api.?key/i', $value) === 1) {
            throw new \RuntimeException(
                "wprism: $where.selector.value " . var_export($value, true) . ' is secret-shaped — a selector travels '
                . 'into receipts and diagnostics, so a value naming a credential surface is refused rather than '
                . 'recorded'
            );
        }
        if ($type === 'provider_resource' && array_key_exists('members', $selector)) {
            self::validate_provider_resource_members($selector['members'], $value, "$where.selector.members");
        }
        if ($type === 'path' && (str_starts_with($value, '/') || str_contains($value, '\\')
            || in_array('.', explode('/', $value), true) || in_array('..', explode('/', $value), true))) {
            throw new \RuntimeException("wprism: $where.selector path must be relative and traversal-free");
        }
        if ($type === 'url_prefix' && (!str_starts_with($value, 'https://') || str_contains($value, '?'))) {
            throw new \RuntimeException("wprism: $where.selector url_prefix must be bounded HTTPS without a query string");
        }
        if ($kind === 'database' && ($scope !== 'database_checkpoint' || !in_array($type, ['table', 'option'], true))) {
            throw new \RuntimeException("wprism: $where database effects must name a checkpoint-covered table or option");
        }
        if ($kind !== 'database' && $scope !== 'external') {
            throw new \RuntimeException("wprism: $where non-database effects must be explicitly external");
        }
        if ($mode === 'restorable' && $scope !== 'database_checkpoint') {
            throw new \RuntimeException("wprism: $where restorable effects require database_checkpoint coverage");
        }
        if ($mode === 'prevented') {
            if (!in_array($kind, ['mail', 'http', 'queue'], true) || ($effect['prevention'] ?? null) !== 'receipt_outbox') {
                throw new \RuntimeException("wprism: $where prevented effects require mail/http/queue receipt_outbox isolation");
            }
        }
        if ($mode === 'reversible') {
            self::validate_effect_adapter($effect['adapter'] ?? null, "$where.adapter");
        }
        if ($type === 'plugin_lifecycle' && $mode !== 'irreversible') {
            throw new \RuntimeException("wprism: $where plugin_lifecycle is an honest unsupported selector and must be irreversible");
        }
    }

    /**
     * Validate a declarative provider-resource aggregate without knowing the
     * provider. Exact members are literal concrete resources; templates may
     * use only the two core bounded placeholder types. Runtime reconciliation
     * expands these same templates against one concrete selector value.
     */
    private static function validate_provider_resource_members(mixed $members, string $aggregate, string $where): void {
        if (!is_array($members) || array_is_list($members)) {
            throw new \RuntimeException("wprism: $where must be an object");
        }
        $keys = array_keys($members);
        sort($keys, SORT_STRING);
        if ($keys !== ['exact', 'templates']) {
            throw new \RuntimeException("wprism: $where requires exactly exact and templates lists");
        }
        foreach (['exact', 'templates'] as $key) {
            if (!is_array($members[$key]) || !array_is_list($members[$key])) {
                throw new \RuntimeException("wprism: $where.$key must be a list");
            }
        }
        if ($members['exact'] === [] && $members['templates'] === []) {
            throw new \RuntimeException("wprism: $where must declare at least one exact member or template");
        }
        $seen = [];
        foreach ($members['exact'] as $i => $member) {
            if (!is_string($member) || $member === '' || strlen($member) > 512
                || $member === $aggregate
                || preg_match('/[\x00-\x1f\x7f*?<>{}]/', $member) === 1
                || preg_match('/secret|credential|password|authorization|signed.?url|access.?token|api.?key/i', $member) === 1) {
                throw new \RuntimeException("wprism: $where.exact[$i] is malformed, broad, or secret-shaped");
            }
            $identity = 'exact:' . $member;
            if (isset($seen[$identity])) {
                throw new \RuntimeException("wprism: $where contains duplicate member '$member'");
            }
            $seen[$identity] = true;
        }
        foreach ($members['templates'] as $i => $template) {
            if (!is_string($template) || $template === '' || strlen($template) > 512
                || preg_match('/[\x00-\x1f\x7f*?<>]/', $template) === 1
                || preg_match('/secret|credential|password|authorization|signed.?url|access.?token|api.?key/i', $template) === 1) {
                throw new \RuntimeException("wprism: $where.templates[$i] is malformed, broad, or secret-shaped");
            }
            $placeholderCount = 0;
            preg_match_all('/\{([^{}]*)\}/', $template, $matches, PREG_OFFSET_CAPTURE);
            $cursor = 0;
            foreach ($matches[0] as $matchIndex => $wholeMatch) {
                $offset = (int) $wholeMatch[1];
                $literal = substr($template, $cursor, $offset - $cursor);
                if (str_contains($literal, '{') || str_contains($literal, '}')) {
                    throw new \RuntimeException("wprism: $where.templates[$i] has unmatched braces");
                }
                $placeholder = (string) ($matches[1][$matchIndex][0] ?? '');
                if (!in_array($placeholder, self::MEMBER_PLACEHOLDERS, true)) {
                    // issue #3318: naming the offending placeholder matters more
                    // here than almost anywhere else — a template may carry
                    // several, so "has an unknown placeholder" left an author
                    // reading a 512-byte string looking for which one.
                    throw new \RuntimeException(
                        "wprism: $where.templates[$i] has an unknown placeholder '{" . $placeholder . '}\' — the '
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
                throw new \RuntimeException("wprism: $where.templates[$i] has unmatched braces");
            }
            if ($placeholderCount === 0) {
                throw new \RuntimeException("wprism: $where.templates[$i] must contain a typed placeholder");
            }
            $identity = 'template:' . $template;
            if (isset($seen[$identity])) {
                throw new \RuntimeException("wprism: $where contains duplicate template '$template'");
            }
            $seen[$identity] = true;
        }
    }

    private static function validate_effect_adapter(mixed $adapter, string $where): void {
        if (!is_array($adapter) || array_is_list($adapter)) {
            throw new \RuntimeException("wprism: $where must be an object");
        }
        $keys = array_keys($adapter);
        sort($keys, SORT_STRING);
        $expected = ['id', 'inverse', 'inverse_inputs', 'verifier', 'verifier_inputs', 'version'];
        if ($keys !== $expected) {
            throw new \RuntimeException("wprism: $where requires version-pinned inverse and verifier inputs");
        }
        foreach (['id', 'inverse', 'verifier'] as $key) {
            if (!is_string($adapter[$key]) || preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $adapter[$key]) !== 1) {
                throw new \RuntimeException("wprism: $where.$key is malformed");
            }
        }
        if (!is_string($adapter['version'])
            || preg_match('/^[0-9]+(?:\.[0-9A-Za-z-]+)+$/', $adapter['version']) !== 1) {
            throw new \RuntimeException("wprism: $where.version must be exact, never latest/wildcard/unbounded");
        }
        foreach (['inverse_inputs', 'verifier_inputs'] as $key) {
            $inputs = $adapter[$key];
            if (!is_array($inputs) || !array_is_list($inputs) || $inputs === []
                || count(array_unique($inputs)) !== count($inputs)) {
                throw new \RuntimeException("wprism: $where.$key must be a non-empty unique input list");
            }
            foreach ($inputs as $input) {
                if (!is_string($input) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $input) !== 1
                    || preg_match('/secret|credential|password|authorization|token|api_?key/i', $input) === 1) {
                    throw new \RuntimeException("wprism: $where.$key contains a malformed receipt input name");
                }
            }
        }
    }
}

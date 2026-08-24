<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/Canon.php';
// DUO-3383: receipt bounding screens provider strings through the same
// public-output authority the JSON refusal envelope uses, so there is one
// secret grammar in this engine rather than a second one written here. Pulled
// in the way CommandRefusal.php pulls in Secrets.php — this file's callers all
// load it directly, so it cannot rely on someone else having loaded the screen.
require_once __DIR__ . '/../Kernel/CommandRefusal.php';
// The engine's own reading of the surfaces a capability declared, which is
// what turns invoke()'s `verified === true` gate from a claim into a check.
// Required here for the same reason as the two above: every caller of this
// file loads it directly, so it cannot assume someone else loaded the observer.
require_once __DIR__ . '/ProviderSurfaces.php';

/**
 * Plugin-owned provider contract: discovery, negotiation, and invocation of
 * executable semantics the engine deliberately does not own.
 *
 * The boundary doctrine's fourth extension surface
 * (docs/adapter-boundary.md, "Plugin-owned provider") is what this file
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
     * Operation-bound scoped effects deliberately use a smaller envelope than
     * a session record.  The session owns authority construction; this seam
     * only proves that a particular opaque effect was asked to carry the exact
     * authority, lease session, input, and declared effect witnesses the
     * caller sealed.  Do not add free-form provider fields here: the envelope
     * is persisted verbatim in a target-owned receipt and is therefore a
     * durable protocol boundary.
     */
    public const SCOPED_OPERATION_FORMAT = 'duo-scoped-effect-operation/v1';
    public const SCOPED_RECEIPT_FORMAT = 'duo-scoped-effect-receipt/v1';
    public const SCOPED_OPERATION_RECEIPT_FORMAT = 'duo-scoped-provider-operation-receipt/v1';

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
     * context" and "batching … and retry semantics" (docs/adapter-boundary.md,
     * "The provider contract must define"), made declarable instead of
     * implicit. Closed for the same reason the
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

    /**
     * Publication bounds for a SUCCESSFUL receipt's `before`/`after` values
     * (DUO-3383). DUO-3314 hardened the FAILURE diagnostics; a successful
     * receipt was the remaining path on which arbitrary provider-returned
     * bytes reached `wp duo apply --format=json` (Apply::rebuild() copies
     * them into its `actions` rows, Cli::apply() serializes the summary
     * whole) with no secrecy and no size contract at all.
     *
     * The split this fixes is a trust-domain split, not a formatting one.
     * `verified` is the provider's own value-level proof and stays exactly
     * what it was; the VALUES it is proved from are third-party bytes, and
     * everything downstream of invoke() is public output. So bounding happens
     * here, at the one place provider bytes enter the engine, rather than at
     * each renderer: there is then no code path on which a raw provider value
     * is retained anywhere — not in Apply's receipts, not in the JSON
     * summary, not in the human warning line (which carries duration only).
     *
     * The bounds are deliberately generous against what the shipped providers
     * really return (the widest is elementor-css's per-file CSS inventory, a
     * depth-3 map of `name => {bytes, mtime}`) and deliberately finite against
     * what an arbitrary one could. Publication depth is 4 because that
     * inventory is 3; the scan depth and node budget below are separate, much
     * wider stack/time bounds on what the engine will WALK at all.
     */
    public const RECEIPT_MAX_DEPTH = 4;
    public const RECEIPT_MAX_ENTRIES = 128;
    public const RECEIPT_MAX_STRING_BYTES = 512;
    public const RECEIPT_MAX_KEY_BYTES = 128;
    public const RECEIPT_MAX_VALUE_BYTES = 8192;
    public const RECEIPT_MAX_SCAN_DEPTH = 64;
    public const RECEIPT_MAX_NODES = 65536;

    /**
     * The value-level redaction witness, and the property that makes bounding
     * safe to do at all.
     *
     * A witness is `<duo:receipt-witness/v1:<reason>:sha256:<digest>>`, where
     * the digest is a canonical, type-tagged hash of the RAW value it stands
     * for. That is what keeps value-level verification intact while the
     * plaintext never publishes: equal raw values always project to the same
     * bytes and unequal ones never do (modulo sha256), so a reader of the
     * PUBLISHED receipt can still decide `before === after` — the question
     * DUO-3338 receipts exist to answer — without the engine handing it the
     * secret, the control bytes, or the megabyte.
     *
     * The house convention is PlanExplanation::publicCoordinate()'s
     * `sha256:<digest>` substitution; this adds the reason (a receipt is
     * evidence, so "summarized, and why" is part of the evidence) and the
     * bracketing prefix that makes the substitution unambiguous — a raw
     * string already shaped like a witness is itself witnessed
     * (`ambiguous`), so no published verbatim string can ever be mistaken for
     * one.
     */
    public const RECEIPT_WITNESS_PREFIX = '<duo:receipt-witness/v1:';

    /**
     * Why a value was replaced. Closed, and engine-owned: every reason names
     * one bound above, so a reason a manifest or a provider could influence
     * cannot exist.
     *
     *   - `secret`     failed the shared public-output sensitivity screen
     *                  (CommandRefusalException::containsSensitivePublicDetail(),
     *                  which is Secrets::hard_match() plus the credentialed
     *                  URI / query-secret / email / home-path shapes DUO-3345
     *                  reviewed for exactly this surface).
     *   - `control`    carries C0/DEL bytes — the injection half of the
     *                  acceptance, told apart from `secret` because the screen
     *                  above would flag it too and "why" would then be wrong.
     *   - `binary`     is not valid UTF-8. Today this is also the only shape
     *                  that could make Cli::apply()'s json_encode() return
     *                  false and print an empty line where the summary should
     *                  be, so it is a correctness fix as much as a secrecy one.
     *   - `oversized`  a string past RECEIPT_MAX_STRING_BYTES / a key past
     *                  RECEIPT_MAX_KEY_BYTES / a whole value whose bounded
     *                  projection is still past RECEIPT_MAX_VALUE_BYTES.
     *   - `deep`       a container nested past RECEIPT_MAX_DEPTH.
     *   - `wide`       a container holding more than RECEIPT_MAX_ENTRIES.
     *   - `ambiguous`  a raw string already shaped like a witness.
     */
    public const RECEIPT_WITNESS_REASONS = [
        'ambiguous', 'binary', 'control', 'deep', 'oversized', 'secret', 'wide',
    ];

    private const ID_PATTERN = '/^[a-z][a-z0-9-]{0,63}$/D';
    private const CAPABILITY_PATTERN = '/^[a-z0-9_]{1,64}$/D';
    private const ARG_NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/D';
    private const SCOPED_OPERATION_NAME_PATTERN = '/^[a-z][a-z0-9._-]{0,63}$/D';
    private const SCOPED_HASH_PATTERN = '/^[a-f0-9]{64}$/D';
    private const SCOPED_TOKEN_PATTERN = '/^[A-Za-z0-9._:-]{8,128}$/D';
    private const MAX_SCOPED_EVIDENCE_BYTES = 65536;
    private const MAX_SCOPED_EVIDENCE_DEPTH = 12;
    private const MAX_SCOPED_EVIDENCE_NODES = 2048;
    private const SCOPED_SECRET_KEY_PATTERN = '/(?:api[_-]?key|authorization|credential|password|passphrase|private[_-]?key|secret|token)/i';
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
     * @return array{problems:list<array<string,mixed>>, providers:array<string,object>, capabilities:array<string,array<string,array<string,mixed>>>, surface_observation:array<string,array<string,array<string,mixed>>>}
     */
    public static function negotiate(Policy $policy, array $selectedActions): array {
        return self::diagnose($policy, $selectedActions);
    }

    /**
     * Report manifest-provider files that are absent without loading provider
     * PHP or asking WordPress for plugin state. The host-side adapter doctor is
     * intentionally WordPress-free, but a missing file in the manifest-owned
     * package is still a packaging fact rather than a target fact. Plugin-owned
     * providers remain deferred to the target negotiation path.
     *
     * @param list<array<string,mixed>> $selectedActions Policy::actions_for()
     * @return list<array<string,mixed>>
     */
    public static function packaging_problems(Policy $policy, array $selectedActions): array {
        $declarations = $policy->provider_declarations();
        $problems = [];
        $seen = [];
        foreach ($selectedActions as $action) {
            if (($action['kind'] ?? '') !== 'provider') {
                continue;
            }
            $id = (string) ($action['provider'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $declaration = $declarations[$id] ?? null;
            if (!is_array($declaration) || ($declaration['source'] ?? '') !== 'manifest') {
                continue;
            }
            $manifest = (string) ($declaration['manifest'] ?? ($action['manifest'] ?? '?'));
            $file = Policy::manifests_dir() . '/providers/' . $id . '.php';
            if (is_file($file)) {
                continue;
            }
            $problems[] = self::packaging_problem(new ProviderPackagingException(
                $id,
                $manifest,
                "duo: manifest '$manifest' declares provider '$id' but $file is missing — "
                    . 'provider code ships with its manifest, not the engine'
            ));
        }
        return $problems;
    }

    /**
     * Scoped-effect negotiation is deliberately additive to negotiate().
     *
     * Legacy plan/status/full-apply callers continue to negotiate only the
     * long-standing identity/capability/invoke contract.  A bounded apply is
     * the one caller that needs at-most-once recovery, so it separately
     * requires the opt-in declaration and the two scoped hooks here, before
     * any authored target mutation.  This keeps an already-installed provider
     * usable on ordinary applies while making a scoped selection fail closed
     * until its adapter explicitly supports operation receipts.
     *
     * @param list<array<string,mixed>> $selectedActions Policy::actions_for()
     * @return array{problems:list<array<string,mixed>>, providers:array<string,object>, capabilities:array<string,array<string,array<string,mixed>>>, surface_observation:array<string,array<string,array<string,mixed>>>, scoped_capabilities:array<string,array<string,array{operation_envelope:string,receipt_format:string,capability_digest:string}>>}
     */
    public static function negotiate_scoped(Policy $policy, array $selectedActions): array {
        // Keep the scoped declaration internal to this opt-in path. Ordinary
        // negotiate()/diagnose() return the pre-existing capability shape so
        // an unscoped caller cannot observe a new declaration key.
        $negotiated = self::diagnose_internal($policy, $selectedActions, true);
        $scopedCapabilities = [];
        foreach ($selectedActions as $action) {
            if (($action['kind'] ?? '') !== 'provider') {
                continue;
            }
            $id = (string) ($action['provider'] ?? '');
            $capability = (string) ($action['capability'] ?? '');
            $provider = $negotiated['providers'][$id] ?? null;
            $decl = $negotiated['capabilities'][$id][$capability] ?? null;
            // Ordinary negotiation already emitted the actionable problem for
            // any unbound action. Do not manufacture a second, less useful
            // scoped-contract symptom on top of it.
            if (!is_object($provider) || !is_array($decl)) {
                continue;
            }
            $manifest = (string) ($action['manifest'] ?? '?');
            $providerDeclaration = $policy->provider_declarations()[$id] ?? [];
            $plugin = is_array($providerDeclaration) && is_string($providerDeclaration['plugin'] ?? null)
                ? $providerDeclaration['plugin']
                : '?';
            try {
                self::validate_scoped_capability_declaration(
                    $decl,
                    "provider '$id' capability '$capability'"
                );
            } catch (\Throwable $t) {
                $negotiated['problems'][] = self::problem(
                    $id,
                    $manifest,
                    $plugin,
                    'missing_scoped_capability',
                    'scoped operation_envelope: ' . self::SCOPED_OPERATION_FORMAT . ' and reconcile: true',
                    'provider did not advertise the scoped operation-receipt contract',
                    'upgrade the provider to an adapter that supports operation-bound scoped apply'
                );
                continue;
            }
            foreach (['invoke_scoped', 'reconcile_scoped'] as $method) {
                if (!is_callable([$provider, $method])) {
                    $negotiated['problems'][] = self::problem(
                        $id,
                        $manifest,
                        $plugin,
                        'scoped_contract_shape',
                        'public invoke_scoped(string, array, array): array and reconcile_scoped(string, array, array): array',
                        "provider lacks required public $method()",
                        'upgrade the provider to an adapter that supports operation-bound scoped apply'
                    );
                    continue 2;
                }
            }
            $scopedCapabilities[$id][$capability] = [
                'operation_envelope' => self::SCOPED_OPERATION_FORMAT,
                'receipt_format' => self::SCOPED_RECEIPT_FORMAT,
                'capability_digest' => self::scoped_capability_digest($id, $capability, $decl),
            ];
        }
        return $negotiated + ['scoped_capabilities' => $scopedCapabilities];
    }

    /**
     * The negotiation itself, as a READ-ONLY question (DUO-3339).
     *
     * negotiate() is this method — one body, not two — so that `plan` and
     * `status` can answer "which declared provider capability is missing or
     * incompatible here?" with exactly the rows apply will refuse on, rather
     * than with a second implementation that would start agreeing and end up
     * approximating. spec/repo-format.md's bound (4) was the standing debt:
     * negotiation ran at apply only, so a missing provider was invisible until
     * the promotion that needed it.
     *
     * "Read-only" is a precise claim, not a comfortable one. This method
     * invokes NO capability and writes nothing — invoke() is the only thing
     * that runs provider work, and nothing here calls it. It does LOAD code:
     * a manifest-sourced provider's file is required and constructed, a
     * plugin-sourced one is pulled off the `duo_providers` filter, and both
     * are asked for identity() and capabilities(). There is no way to check a
     * contract without the object, so that cost is negotiation's, was always
     * negotiation's, and is now also plan's — which is why plan reports these
     * rows rather than gating on them (see Apply::plan()).
     *
     * The one deliberate throw stays a throw: a manifest-sourced provider
     * whose file or class is missing is a packaging fault in the adapter, not
     * a fact about this environment. problems() below is what turns that into
     * a reportable row for the surfaces that must not die on it.
     *
     * `surface_observation` is the read-only half of invoke()'s receipt check:
     * per bound capability, which of its declared `reads`/`writes` surfaces
     * the engine can read either side of the call, which ones it has no reader
     * for, and what the check costs in queries. It is computed by
     * ProviderSurfaces::observation_plan() from the declaration alone — no
     * query, no provider call — so this stays exactly as read-only as it
     * claims, and an unobservable surface is a fact available BEFORE the apply
     * writes anything instead of one the engine runs into mid-mutation.
     *
     * @param list<array<string,mixed>> $selectedActions Policy::actions_for()
     * @return array{problems:list<array<string,mixed>>, providers:array<string,object>, capabilities:array<string,array<string,array<string,mixed>>>, surface_observation:array<string,array<string,array<string,mixed>>>}
     */
    public static function diagnose(Policy $policy, array $selectedActions): array {
        return self::diagnose_internal($policy, $selectedActions, false);
    }

    /**
     * @param list<array<string,mixed>> $selectedActions Policy::actions_for()
     * @return array{problems:list<array<string,mixed>>, providers:array<string,object>, capabilities:array<string,array<string,array<string,mixed>>>, surface_observation:array<string,array<string,array<string,mixed>>>}
     */
    private static function diagnose_internal(Policy $policy, array $selectedActions, bool $includeScoped): array {
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
        $surfaceObservation = [];
        $channelClaims = [];
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
                        . 'or pin a manifest whose range covers the installed version'
                );
                continue;
            }

            // DUO-3317: the declared `requires` contract is enforced here,
            // BEFORE the provider file is loaded or its `duo_providers`
            // registry is consulted — strictly earlier than the retired
            // per-adapter assert_runtime_contract(), which only guarded
            // invoke()/regenerate_batch() after the object already existed. An
            // environment that cannot satisfy the contract refuses without the
            // engine ever constructing the adapter.
            $requirementProblem = self::requirement_problem($declaration, $live);
            if ($requirementProblem !== null) {
                $problems[] = $requirementProblem;
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
            $observed = [];
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
                $problem = self::dual_claimant_problem(
                    $policy,
                    $declaration,
                    $capability,
                    $action,
                    (array) $advertised[$capability]
                );
                if ($problem !== null) {
                    $problems[] = $problem;
                    $failed = true;
                    continue;
                }
                $bound[$capability] = $includeScoped
                    ? $advertised[$capability]
                    : self::ordinary_capability_declaration($advertised[$capability]);
                // Which of this capability's declared surfaces the engine can
                // actually read either side of invoke(), decided HERE — in the
                // read-only negotiation, before Apply has mutated anything —
                // rather than discovered mid-write. Deliberately not a
                // problem row: a surface this engine has no reader for
                // (`entity:woocommerce-cache-groups` and every other
                // adapter-minted name) is the normal shape of a cache-flush
                // capability, not a defect, and unbinding for it would refuse
                // most of the shipped library. It is carried BESIDE the
                // declaration, never inside it: scoped_capability_digest()
                // hashes the declaration, so a key added there would move
                // every scoped capability digest.
                $observed[$capability] = ProviderSurfaces::observation_plan((array) $advertised[$capability]);
                // Accumulated across providers, resolved after the loop: a
                // channel collision is a fact about two DIFFERENT capabilities,
                // which may live in two different providers, so it cannot be
                // decided while looking at one.
                foreach ((array) ($advertised[$capability]['context'] ?? []) as $channel) {
                    foreach ((array) ($action['triggers'] ?? []) as $trigger) {
                        if (!is_string($trigger) || !str_starts_with($trigger, 'post:')) {
                            continue;
                        }
                        $claimant = "$id/$capability";
                        $channelClaims[(string) $channel][$trigger][$claimant] = [
                            'provider' => $id,
                            'manifest' => $manifest,
                            'plugin' => $plugin,
                            'capability' => $capability,
                        ];
                    }
                }
            }
            // A count comparison cannot express completeness here: the wanted
            // rows are actions while the bound rows are capabilities, and the
            // two only coincide while no capability is selected twice.
            if (!$failed) {
                $instances[$id] = $provider;
                $capabilities[$id] = $bound;
                $surfaceObservation[$id] = $observed;
            }
        }
        $collisions = self::channel_collision_problems($channelClaims);
        foreach ($collisions['problems'] as $problem) {
            $problems[] = $problem;
        }
        // Neither claimant may bind: which of them would have cleared the
        // shared marker is exactly the question that has no answer, so leaving
        // either one bound would pick a winner by accident. Carried beside the
        // problems rather than ON them (DUO-3314 rebase): a problem row is now
        // promoted verbatim into the operator-facing readiness wire shape
        // (Policy::provider_readiness_blockers()), so every row this method
        // emits stays exactly what self::problem() returns — no extra key some
        // renderer has to know to ignore.
        foreach ($collisions['unbind'] as $claimantProvider) {
            unset($instances[$claimantProvider], $capabilities[$claimantProvider], $surfaceObservation[$claimantProvider]);
        }
        return [
            'problems' => $problems,
            'providers' => $instances,
            'capabilities' => $capabilities,
            'surface_observation' => $surfaceObservation,
        ];
    }

    /**
     * Ordinary negotiation intentionally projects the declaration shape that
     * existed before scoped effects. The scoped capability opt-in is not an
     * unscoped behavior change and is recovered from the same advertised row
     * only by diagnose_internal(..., true).
     *
     * @param array<string,mixed> $declaration
     * @return array<string,mixed>
     */
    private static function ordinary_capability_declaration(array $declaration): array {
        unset($declaration['scoped']);
        return $declaration;
    }

    /**
     * Every problem this environment has with every provider capability the
     * PINNED manifests declare — the plan/status view.
     *
     * Two things are deliberately wider than apply's own gate, and both are
     * the point rather than an accident:
     *
     *   1. The action set is `Policy::actions()`, not one run's selection.
     *      The NARROWED set already exists and already gates: DUO-3314's
     *      `Policy::provider_readiness_blockers($selectedActions)` negotiates
     *      exactly what this plan's work touches and merges its rows into
     *      `adapter_dispositions`, which IS part of `duo status`'s `ok`. That
     *      is correct — an unrelated adapter's missing plugin must not refuse a
     *      promotion that never reaches it. This method is the complement: a
     *      readiness report answering only "for this diff" goes quiet the
     *      moment a run happens to touch nothing, and "we found no problems"
     *      would then mean "we did not look". So these rows are the wider,
     *      deliberately NON-gating superset, and $gating below keeps the two
     *      from double-reporting one fact.
     *
     *   2. A throw is reported here rather than propagated. Apply still throws
     *      — that behavior is pinned byte for byte by
     *      regress_provider_contract.php — because at apply a throw is a
     *      refusal before mutation. On a reporting surface, a command that
     *      died on one broken adapter would be hiding every other adapter's
     *      verdict behind it, which is the failure mode this whole surface
     *      exists to remove.
     *
     *      The two throws are told apart rather than collapsed, because they
     *      call for opposite actions. A ProviderPackagingException is the
     *      adapter's own fault, it names the exact file, and "repair
     *      providers/<id>.php" is real advice. Anything ELSE reaching here is
     *      third-party code misbehaving inside the diagnosis — a `duo_providers`
     *      callback whose identity() throws, a provider whose capabilities()
     *      throws, a lifecycle read that failed — and telling that operator to
     *      go repair a `providers/<id>.php` (with a LITERAL `<id>`, since
     *      nothing here knows which provider it was) would be a fabricated
     *      coordinate on top of a real failure. That case gets its own code and
     *      points at the message, which is the only thing actually known.
     *
     * @return list<array<string,mixed>>
     */
    public static function problems(Policy $policy, array $gating = []): array {
        // The same gate Policy::provider_readiness_blockers() takes, and for
        // the same reason: without a loaded WordPress there is no plugin state
        // to negotiate against, so every declared provider would report
        // `missing_plugin` and an offline manifest-library load would
        // manufacture a wall of findings about an environment it cannot see.
        if (!self::runtime_negotiation_available()) {
            return [];
        }
        // Rows the narrowed, GATING diagnosis already reported are dropped
        // here. Both lists reach one operator in one plan render, so a
        // selected inactive plugin appearing once as a blocked
        // adapter_dispositions row and again as a PROVIDER_PROBLEM row is one
        // fact stated twice — the shape AdapterSources::refuse() already
        // refuses for installed files. What survives is exactly the useful
        // remainder: declared capabilities this environment cannot supply that
        // THIS revision's work does not reach, which is the gap the wider set
        // exists to expose.
        $reported = [];
        foreach ($gating as $row) {
            $key = (string) ($row['provider'] ?? '') . "\0" . (string) ($row['manifest'] ?? '')
                . "\0" . (string) ($row['code'] ?? '');
            $reported[$key] = true;
        }
        try {
            $problems = self::diagnose($policy, $policy->actions())['problems'];
            return array_values(array_filter(
                $problems,
                static function (array $problem) use ($reported): bool {
                    $key = (string) ($problem['provider'] ?? '') . "\0" . (string) ($problem['manifest'] ?? '')
                        . "\0" . (string) ($problem['code'] ?? '');
                    return !isset($reported[$key]);
                }
            ));
        } catch (ProviderPackagingException $t) {
            return [self::packaging_problem($t)];
        } catch (\Throwable $t) {
            return [self::problem(
                '?',
                '?',
                '?',
                'provider_diagnosis_failed',
                'every declared provider to answer the negotiation questions without throwing',
                self::publishable_foreign_detail(get_class($t) . ': ' . $t->getMessage()),
                'see the message — it comes from code this engine does not own, and no provider identity was '
                    . 'established before it threw'
            )];
        }
    }

    /**
     * Project the one packaging fault that reporting callers may recover from
     * without weakening the direct negotiation gate. Policy uses this exact
     * row when plan/status needs to keep a missing manifest provider visible;
     * apply still calls negotiate() and therefore still throws before target
     * mutation.
     */
    public static function packaging_problem(ProviderPackagingException $failure): array {
        return self::problem(
            $failure->providerId(),
            $failure->manifest(),
            '?',
            'provider_code_unavailable',
            "the manifest-sourced provider '{$failure->providerId()}' to resolve to its shipped class",
            $failure->getMessage(),
            "repair manifests/providers/{$failure->providerId()}.php, which ships with manifest "
                . "'{$failure->manifest()}', or unpin that manifest"
        );
    }

    /**
     * The secret/path FLOOR under the one deliberately third-party-transparent
     * field any problem row carries.
     *
     * Every OTHER string a problem row publishes is engine-authored and
     * value-free by construction — channel_collision_problems()'s own note
     * enumerates why ("no exception text, no class name, no third-party
     * free-form data"). problems()'s generic `\Throwable` catch is the single,
     * deliberate exception: it publishes a message from code this engine does
     * not own, on purpose, so an operator sees the actual fault a misbehaving
     * `duo_providers` callback (or a provider identity()/capabilities() read)
     * threw during diagnosis. That transparency is the intended behavior and is
     * kept — but a third-party exception is unreviewed prose, and nothing stops
     * one embedding an absolute path or a credential it happened to interpolate
     * into its own error text. This runs that one detail through the shared
     * secret/path screen reviewed typed refusals use
     * (CommandRefusalException::containsSensitivePublicDetail(), i.e.
     * Secrets::hard_match() plus the credential/URI and HOME-dir path shapes) —
     * alongside bound_receipt_string()'s DUO-3383 `secret` witness — and,
     * ONLY if it
     * trips, replaces the whole detail with a bounded, secret-free placeholder.
     * This is a secret/home-dir FLOOR inherited from that shared screen, not a
     * full redaction: a non-home absolute path (`/var/www/…`, `/etc/…`) or a
     * relative path is not a screen shape, so a clean-but-unreviewed
     * third-party message — path-bearing or not — still publishes verbatim, by
     * the deliberate-transparency design above. The floor closes the one class
     * the screen names (secrets, credentials, home paths, control bytes), which
     * is what a misbehaving callback can leak without the operator's consent.
     * A clean message publishes verbatim (the common, intended case); a
     * secret-bearing one is withheld without withholding the fact that the
     * diagnosis failed, which is what the surrounding row still states. The
     * placeholder carries no captured bytes, so it cannot itself leak.
     */
    private static function publishable_foreign_detail(string $detail): string {
        if (CommandRefusalException::containsSensitivePublicDetail($detail)) {
            return 'third-party diagnosis fault carried secret-, credential-, or home-path-shaped bytes; '
                . 'inspect private operator evidence for the raw provider exception';
        }
        return $detail;
    }

    /**
     * One channel, one surface, one consumer — refused before any mutation.
     *
     * The durable `regen_delete_context:`/`regen_reparent_context:` markers a
     * channel delivers are cleared by the engine on the FIRST verified receipt
     * of a run (Apply::rebuild()), because a marker is engine bookkeeping about
     * one entity rather than per-consumer state. That is correct while a
     * surface has one consumer and silently wrong the moment it has two:
     * capability A verifies, the engine retires the receipt, capability B fails,
     * and B's retry never sees the tombstone again — a convergence claim for
     * work that never happened, which is precisely the failure class the
     * clear-on-verified rule exists to prevent (independent review, F2, driven
     * against the real rebuild pass).
     *
     * Refused rather than fixed by making the clear per-consumer, deliberately.
     * Per-consumer clearing would need a second durable keyspace keyed by
     * (marker, capability) whose own lifetime nothing owns — an unbounded
     * accumulation for a shape no shipped adapter has and no reviewer could
     * bound. One consumer per channel per surface is the property the whole
     * marker design already assumes; this makes the assumption a refusal
     * instead of a comment.
     *
     * Scoped to `post:` triggers because that is the whole domain of the three
     * marker keyspaces (they are keyed by a post uuid). Two capabilities may
     * still declare the same channel on DIFFERENT surfaces, or different
     * channels on the same surface — neither shares a marker.
     *
     * The dedupe key is capability IDENTITY, not the declaring action: one
     * capability selected by two actions is one consumer, because the code
     * that verifies is the code that repairs. Bound worth naming: those two
     * actions may pass different `args`, and invocation #1's verified receipt
     * discharges a marker invocation #2 (doing different work) may also have
     * owed. The marker records engine inventory, not per-args intent, so
     * this is accepted — but it is a bound, not an accident.
     *
     * Every string this method puts in a problem row is bounded by a closed
     * vocabulary or an already-validated identifier — the channel name is a
     * CONTEXT_CHANNELS member (validate_capability_declaration() ran before the
     * capability was bound), the surface came from the action's own
     * SURFACE_PATTERN-checked triggers, and the claimant names are
     * ID_PATTERN/CAPABILITY_PATTERN identities. That is the property DUO-3314's
     * fail-closed readiness posture needs from a row it renders to an operator:
     * no exception text, no class name, no third-party free-form data.
     *
     * @param array<string,array<string,array<string,array<string,string>>>> $claims
     *   channel => surface => "provider/capability" => claimant row
     * @return array{problems:list<array<string,mixed>>, unbind:list<string>}
     */
    private static function channel_collision_problems(array $claims): array {
        $problems = [];
        $unbind = [];
        ksort($claims, SORT_STRING);
        foreach ($claims as $channel => $surfaces) {
            ksort($surfaces, SORT_STRING);
            foreach ($surfaces as $surface => $claimants) {
                if (count($claimants) < 2) {
                    continue;
                }
                ksort($claimants, SORT_STRING);
                $names = array_keys($claimants);
                // The row is attributed to the alphabetically-first claimant
                // (provider/manifest/plugin are its), so in a readiness view
                // it appears under ONE adapter's name — `found` names every
                // claimant pair, which is where an operator reading that
                // adapter's row finds the other. All claimants unbind.
                $first = $claimants[$names[0]];
                $problem = self::problem(
                    (string) $first['provider'],
                    (string) $first['manifest'],
                    (string) $first['plugin'],
                    'channel_claimed_twice',
                    "exactly one capability consuming the '$channel' channel for $surface",
                    count($names) . ' capabilities consuming it: ' . implode(', ', $names),
                    "the durable $channel receipts for $surface are one keyspace with one lifetime — the "
                        . 'engine clears them on the first verified receipt, so a second consumer would lose '
                        . 'the evidence its own retry depends on. Narrow the triggers so one capability owns '
                        . "$surface, or fold the two repairs into one capability"
                );
                $problems[] = $problem;
                foreach ($claimants as $claimant) {
                    $unbind[(string) $claimant['provider']] = (string) $claimant['provider'];
                }
            }
        }
        return ['problems' => $problems, 'unbind' => array_values($unbind)];
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
     * This engine-level timeout is a post-hoc wall-clock budget, checked after
     * the provider call returns; arbitrary in-process PHP cannot be preempted.
     * A few shipped providers launch their reviewed native command through
     * WpCliChildProcess and enforce a separate preemptive child deadline, but
     * that caller-owned guarantee is not inferred for an arbitrary provider.
     * Here the declared budget only bounds what the receipt may assert about
     * duration and hard-fails an overrun after control returns. What the
     * surface observation below adds is a verdict that does not depend on the
     * clock at all: a call that finished well inside its budget and wrote
     * nothing it claimed — or wrote a surface it declared it would only read —
     * is now caught by evidence rather than escaping because it was fast. An
     * overrun still refuses as an overrun, first and on the clock, because
     * that is the refusal an operator acts on and the observation is only
     * reached by a receipt that already passed it.
     *
     * @param array<string,mixed> $actionEntry one Policy::actions_for() row
     * @param array<string,mixed> $capabilityDecl the negotiated declaration
     * @param list<array{kind:string,id:int}> $entities batch for scope=entity
     * The returned `before`/`after` are the PUBLISHED PROJECTION of what the
     * provider observed, not its bytes — see bound_receipt() and the
     * RECEIPT_* bounds. The provider's own value-level comparison already
     * happened, against the raw values, inside its invoke(); this file still
     * cannot interpret those values, which are provider-shaped and mean
     * nothing here, so bounding still cannot reach a verification verdict.
     * What it can do is compare its OWN reading of the surfaces the capability
     * declared, either side of the call (ProviderSurfaces::observe()), and
     * refuse a receipt those readings contradict. That check reaches exactly
     * the surfaces ProviderSurfaces has a complete, bounded reader for —
     * `option:` today — so a capability whose declared surfaces are all
     * `table:`/`post:`/`entity:` is bounded by the clock and the receipt shape
     * as before; negotiation publishes which surfaces those are under
     * `surface_observation`, so the gap is stated rather than implied.
     *
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
        // Read the declared surfaces BEFORE the clock starts, for two reasons:
        // a target that cannot answer these checked reads refuses here, ahead
        // of any provider work rather than after it, and the engine's own
        // queries are not charged to the budget the receipt is measured
        // against below. This read is silent for every capability that
        // declared no `option:` surface — observation_plan() watches nothing,
        // and observe() returns before it touches $wpdb — so it costs the nine
        // shipped adapters exactly zero queries for the surfaces they declare
        // as `table:`/`post:`/`entity:`.
        $observation = ProviderSurfaces::observation_plan($capabilityDecl);
        $before = ProviderSurfaces::observe($observation['watched'], $id, $capability);
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
        // The second reading runs only after those three, so the precedence
        // and the wording regress_provider_contract.php pins are untouched: a
        // receipt that is malformed, unverified, or over budget is still
        // refused as such, and a failed checked read here cannot displace any
        // of them. Everything below is a check on a receipt that already
        // passed all three.
        $after = ProviderSurfaces::observe($observation['watched'], $id, $capability);
        // Proven-positive before proven-negative. A read-only surface that
        // moved is a fact the engine measured; the unshown-write refusal is a
        // claim about an absence, so it is the weaker of the two and goes
        // second. Surface names are Policy::SURFACE_PATTERN-bounded
        // (Policy.php:217 — lowercase, no whitespace, no control bytes) and
        // are the provider's own declaration rather than target state, so
        // naming one carries no provider bytes into the message.
        foreach ($observation['read_only'] as $surface) {
            if ($before[$surface] !== $after[$surface]) {
                throw new \RuntimeException(
                    "duo: provider '$id' capability '$capability' declared '$surface' under reads but the "
                    . 'surface changed across the call — a capability may only change what it declared in '
                    . "writes; declare '$surface' there if this capability writes it, or re-run the apply if "
                    . 'another process on this target wrote it'
                );
            }
        }
        // Only when every declared write surface is one the engine can read
        // completely: with an unobservable surface in the list the write may
        // legitimately have landed somewhere this engine cannot see, and
        // refusing then would punish a provider for the reader's gap.
        //
        // `before !== after` is the receipt's own claim to have written
        // something, and it is read RAW rather than from bound_receipt()'s
        // projection only because the raw values are already in hand; the
        // projection is injective by construction (see bound_receipt() and the
        // witness digest below it), so the two comparisons agree.
        //
        // The mirror case — `before === after` while a declared write surface
        // moved — is deliberately NOT a refusal. `before`/`after` are the
        // provider's own chosen projection of what it compared, not a claim
        // about every byte of the surface, so a capability that rewrote a row
        // and honestly reports the two projections equal is not lying. Only
        // the positive claim ("I changed something") is checkable against a
        // surface that shows nothing changed.
        if ($observation['writes_fully_observable'] && $receipt['before'] !== $receipt['after']) {
            $unchanged = [];
            foreach ($observation['writes'] as $surface) {
                if ($before[$surface] === $after[$surface]) {
                    $unchanged[] = $surface;
                }
            }
            if (count($unchanged) === count($observation['writes'])) {
                throw new \RuntimeException(
                    "duo: provider '$id' capability '$capability' reported a value-level change that its own "
                    . 'declared writes surfaces do not show (' . implode(', ', $unchanged) . ' unchanged) — a '
                    . 'receipt must prove the state it wrote; declare the surface the capability actually '
                    . 'writes, or return before === after when the target was already converged'
                );
            }
        }
        // Bounding stays last, so a receipt that is both unpublishable and
        // any of the above is still refused as the earlier fault.
        return self::bound_receipt($receipt, $id, $capability) + ['duration_seconds' => round($elapsed, 3)];
    }

    /**
     * Project one accepted receipt's `before`/`after` onto what may publish.
     *
     * Public because it is the contract, not an implementation detail: this
     * is the whole of what `wp duo apply --format=json` is allowed to say
     * about provider-observed values, and a suite proving that must be able
     * to call it directly.
     *
     * Key order is preserved by assigning through the existing keys, so a
     * receipt whose values were all already in bounds is byte-identical to
     * what the pre-DUO-3383 engine returned.
     *
     * @param array{before:mixed, after:mixed, verified:true} $receipt
     * @return array{before:mixed, after:mixed, verified:true}
     */
    public static function bound_receipt(array $receipt, string $id, string $capability): array {
        foreach (['before', 'after'] as $field) {
            $nodes = 0;
            [$projection, $digest] = self::bound_receipt_value($receipt[$field], 1, $id, $capability, $nodes);
            // The published byte count, measured on exactly the encoder
            // Cli::apply() will use. A projection can still be large after
            // per-string and per-container bounding — many small entries, all
            // of them legal — so the whole value gets one final bound, and
            // the witness stands for the RAW value rather than the
            // projection, which is what keeps equality decidable.
            if (strlen((string) json_encode($projection, JSON_UNESCAPED_SLASHES))
                > self::RECEIPT_MAX_VALUE_BYTES) {
                $projection = self::receipt_witness('oversized', $digest);
            }
            $receipt[$field] = $projection;
        }
        return $receipt;
    }

    /**
     * One value's projection and its canonical digest, in one walk.
     *
     * The digest is computed from the RAW value at every level, including the
     * levels that do not publish — a `before` and an `after` differing only
     * below RECEIPT_MAX_DEPTH must still project to different witnesses, or
     * the summary would claim a repair changed nothing. It is type-tagged
     * (serialize() of each scalar and of each key, folded through one
     * streaming context per container) so no two distinct values share one,
     * and streaming so a wide container costs no memory to digest.
     *
     * Walking a subtree the engine will not publish is deliberate for the
     * same reason: the alternative — hashing an unwalked subtree wholesale —
     * would serialize values this method has not yet proved are serializable.
     *
     * @param int $nodes by-reference budget, shared across the whole walk of
     *   one field and reset for the other — `before` and `after` are each
     *   independently bounded evidence, not two halves of one budget
     * @return array{0:mixed, 1:string} projection, raw digest
     */
    private static function bound_receipt_value(
        mixed $value,
        int $depth,
        string $id,
        string $capability,
        int &$nodes
    ): array {
        if (++$nodes > self::RECEIPT_MAX_NODES) {
            throw self::unpublishable_receipt(
                $id,
                $capability,
                'more than ' . self::RECEIPT_MAX_NODES . ' values in one field'
            );
        }
        if (is_array($value)) {
            if ($depth > self::RECEIPT_MAX_SCAN_DEPTH) {
                throw self::unpublishable_receipt(
                    $id,
                    $capability,
                    'nested deeper than ' . self::RECEIPT_MAX_SCAN_DEPTH
                );
            }
            $context = hash_init('sha256');
            hash_update($context, 'a:' . count($value) . ':');
            $summarize = $depth > self::RECEIPT_MAX_DEPTH ? 'deep'
                : (count($value) > self::RECEIPT_MAX_ENTRIES ? 'wide' : '');
            $out = [];
            foreach ($value as $key => $item) {
                [$child, $childDigest] = self::bound_receipt_value($item, $depth + 1, $id, $capability, $nodes);
                hash_update($context, serialize($key) . '=' . $childDigest . ';');
                if ($summarize !== '') {
                    continue;
                }
                $boundedKey = is_int($key)
                    ? $key
                    : self::bound_receipt_string((string) $key, self::RECEIPT_MAX_KEY_BYTES);
                if (array_key_exists($boundedKey, $out)) {
                    // Unreachable through the witness grammar (distinct keys
                    // digest distinctly, and a witness-shaped key is itself
                    // witnessed), so this is the structural proof of that
                    // rather than a handled case: two keys collapsing into one
                    // would silently drop half a receipt.
                    throw self::unpublishable_receipt($id, $capability, 'keys that collide once bounded');
                }
                $out[$boundedKey] = $child;
            }
            $digest = hash_final($context);
            return [$summarize === '' ? $out : self::receipt_witness($summarize, $digest), $digest];
        }
        if (is_float($value) && !is_finite($value)) {
            // INF/NAN survive serialize() but not json_encode(), so a receipt
            // carrying one publishes as an empty line rather than a summary.
            throw self::unpublishable_receipt($id, $capability, 'a non-finite number');
        }
        if ($value !== null && !is_scalar($value)) {
            // Objects and resources, the shape CommandRefusal.php already
            // fails closed on for public payloads: their serialization can
            // expose bytes an array walk never sees, so the engine will not
            // summarize one either.
            throw self::unpublishable_receipt($id, $capability, 'a value that is neither scalar nor array');
        }
        $digest = hash('sha256', serialize($value));
        return [is_string($value)
            ? self::bound_receipt_string($value, self::RECEIPT_MAX_STRING_BYTES, $digest)
            : $value, $digest];
    }

    /**
     * One string leaf or map key: verbatim, or the witness that stands for it.
     *
     * The test order is cheap-structural-first, not the vocabulary's order,
     * and that is not an accident: the sensitivity screen flags control bytes
     * too, so running the structural tests first is what makes `secret` mean
     * what it says.
     */
    private static function bound_receipt_string(string $value, int $maxBytes, ?string $digest = null): string {
        $witness = static fn(string $reason): string => self::receipt_witness(
            $reason,
            $digest ?? hash('sha256', serialize($value))
        );
        if (strlen($value) > $maxBytes) {
            return $witness('oversized');
        }
        if (preg_match('//u', $value) !== 1) {
            return $witness('binary');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return $witness('control');
        }
        if (str_starts_with($value, self::RECEIPT_WITNESS_PREFIX)) {
            return $witness('ambiguous');
        }
        if (CommandRefusalException::containsSensitivePublicDetail($value)) {
            return $witness('secret');
        }
        return $value;
    }

    private static function receipt_witness(string $reason, string $digest): string {
        if (!in_array($reason, self::RECEIPT_WITNESS_REASONS, true)) {
            throw new \RuntimeException('duo: receipt witness reason is outside the closed vocabulary');
        }
        return self::RECEIPT_WITNESS_PREFIX . $reason . ':sha256:' . $digest . '>';
    }

    /**
     * The fail-closed half. `$reason` is engine-authored and value-free by
     * construction — a message quoting the offending value to explain why it
     * cannot be published would publish it, and the offending value has no
     * coordinate worth naming either, since a key path through a malformed
     * receipt is provider bytes too.
     */
    private static function unpublishable_receipt(
        string $id,
        string $capability,
        string $reason
    ): \RuntimeException {
        return new \RuntimeException(
            "duo: provider '$id' capability '$capability' returned a receipt the engine cannot publish ($reason) — "
            . 'before and after must be bounded arrays of scalars; summarize the observation in the provider '
            . 'rather than returning raw state'
        );
    }

    /**
     * Hash the exact provider input a scoped call will receive.  This is not a
     * hash of a manifest row: entity batches and declared context channels are
     * engine-assembled evidence, so they must be inside the same binding that
     * invoke_scoped() recomputes before it crosses into plugin code.
     *
     * @param array<string,mixed> $actionEntry
     * @param array<string,mixed> $capabilityDecl
     * @param list<array{kind:string,id:int}> $entities
     * @param array<string,mixed> $context
     */
    public static function scoped_input_hash(
        array $actionEntry,
        array $capabilityDecl,
        array $entities = [],
        array $context = []
    ): string {
        $id = (string) ($actionEntry['provider'] ?? '');
        $capability = (string) ($actionEntry['capability'] ?? '');
        if (preg_match(self::ID_PATTERN, $id) !== 1
            || preg_match(self::CAPABILITY_PATTERN, $capability) !== 1) {
            throw new \RuntimeException('duo: scoped provider input has an invalid provider or capability identity');
        }
        return self::scoped_hash([
            'kind' => 'provider',
            'provider' => $id,
            'capability' => $capability,
            'args' => self::scoped_invocation_args($actionEntry, $capabilityDecl, $entities, $context),
        ]);
    }

    /**
     * A capability digest binds the adapter's advertised behavioral surface to
     * scoped authority without serializing that declaration into a durable
     * session.  It intentionally covers the whole declaration, including the
     * ordinary argument/scope contract: changing either changes the exact
     * input/effect meaning an authority approved.
     *
     * @param array<string,mixed> $capabilityDecl
     */
    public static function scoped_capability_digest(
        string $providerId,
        string $capability,
        array $capabilityDecl
    ): string {
        if (preg_match(self::ID_PATTERN, $providerId) !== 1
            || preg_match(self::CAPABILITY_PATTERN, $capability) !== 1) {
            throw new \RuntimeException('duo: scoped capability digest has an invalid provider or capability identity');
        }
        self::validate_capability_declaration(
            $capabilityDecl,
            "provider '$providerId' capability '$capability'"
        );
        self::validate_scoped_capability_declaration(
            $capabilityDecl,
            "provider '$providerId' capability '$capability'"
        );
        return self::scoped_hash([
            'format' => self::SCOPED_OPERATION_FORMAT,
            'provider' => $providerId,
            'capability' => $capability,
            'declaration' => $capabilityDecl,
        ]);
    }

    /**
     * Canonically screen and hash one internal receipt value without exposing
     * it. NativeActions uses this same gate so every scoped effect has one
     * bounded/secret-safe evidence rule.
     */
    public static function scoped_evidence_digest(mixed $evidence): string {
        return self::scoped_evidence_hash($evidence);
    }

    /**
     * Validate and return the closed operation envelope in its protocol key
     * order.  Keeping this public lets the native action boundary use the
     * identical witness grammar without copying a second subtly-drifting
     * validator into NativeActions.
     *
     * @param array<string,mixed> $operation
     * @return array{authority_hash:string,lease_session_id:string,operation_id:string,input_hash:string,effect_hash:string}
     */
    public static function validate_scoped_operation(array $operation): array {
        $keys = array_keys($operation);
        sort($keys, SORT_STRING);
        $expected = ['authority_hash', 'effect_hash', 'input_hash', 'lease_session_id', 'operation_id'];
        if ($keys !== $expected) {
            throw new \RuntimeException(
                'duo: scoped effect operation envelope must contain exactly authority_hash, lease_session_id, operation_id, input_hash, and effect_hash'
            );
        }
        foreach (['authority_hash', 'input_hash', 'effect_hash'] as $field) {
            if (!is_string($operation[$field] ?? null)
                || preg_match(self::SCOPED_HASH_PATTERN, $operation[$field]) !== 1) {
                throw new \RuntimeException("duo: scoped effect operation $field must be a lowercase SHA-256 hash");
            }
        }
        foreach (['lease_session_id', 'operation_id'] as $field) {
            if (!is_string($operation[$field] ?? null)
                || preg_match(self::SCOPED_TOKEN_PATTERN, $operation[$field]) !== 1) {
                throw new \RuntimeException("duo: scoped effect operation $field is not a bounded identity token");
            }
        }
        return [
            'authority_hash' => $operation['authority_hash'],
            'lease_session_id' => $operation['lease_session_id'],
            'operation_id' => $operation['operation_id'],
            'input_hash' => $operation['input_hash'],
            'effect_hash' => $operation['effect_hash'],
        ];
    }

    /**
     * Invoke one provider capability under a durable operation intent.
     *
     * The intent is committed before the opaque provider call.  A crash or a
     * malformed response after that point is deliberately recovery_required,
     * not an excuse to call the provider a second time.  Only once reviewed
     * raw evidence has been bounded, screened, and hashed is the intent
     * upgraded to a verified receipt.  The return value is closed, contains no
     * provider evidence values, and is safe for a session/public receipt.
     *
     * @param array<string,mixed> $actionEntry
     * @param array<string,mixed> $capabilityDecl
     * @param array<string,mixed> $operation
     * @param list<array{kind:string,id:int}> $entities
     * @param array<string,mixed> $context
     * @return array{format:string,operation:array{authority_hash:string,lease_session_id:string,operation_id:string,input_hash:string,effect_hash:string},capability_digest:string,before_hash:string,after_hash:string,verified:true,status:'verified'}
     */
    public static function invoke_scoped(
        object $provider,
        array $actionEntry,
        array $capabilityDecl,
        array $operation,
        array $entities = [],
        array $context = []
    ): array {
        [$id, $capability, $args, $operation, $digest] = self::scoped_call_context(
            $actionEntry,
            $capabilityDecl,
            $operation,
            $entities,
            $context
        );
        if (!is_callable([$provider, 'invoke_scoped'])) {
            throw new \RuntimeException("duo: provider '$id' capability '$capability' lacks scoped invocation support");
        }
        self::begin_scoped_operation($id, $capability, $operation);
        $started = microtime(true);
        try {
            $raw = $provider->invoke_scoped($capability, $args, $operation);
        } catch (\Throwable $t) {
            throw new \RuntimeException("duo: provider '$id' capability '$capability' scoped invocation failed");
        }
        $elapsed = microtime(true) - $started;
        self::assert_scoped_budget($id, $capability, $capabilityDecl, $elapsed);
        $evidence = self::review_scoped_invoke_response($id, $capability, $operation, $raw);
        $stored = self::complete_scoped_operation(
            $id,
            $capability,
            $operation,
            $evidence['before'],
            $evidence['after']
        );
        if (!hash_equals($evidence['before_hash'], $stored['before_hash'])
            || !hash_equals($evidence['after_hash'], $stored['after_hash'])) {
            throw new \RuntimeException("duo: provider '$id' capability '$capability' scoped receipt readback did not bind reviewed evidence");
        }
        return self::reviewed_scoped_result($operation, $digest, $stored, true);
    }

    /**
     * Read-only recovery for an exact operation.  Absence of an operation row
     * is the sole not_started result: an intent without a completed receipt is
     * unknowable, and a completed receipt whose checked postcondition differs
     * is a recovery-required refusal.  Neither case may re-invoke the effect.
     *
     * @param array<string,mixed> $actionEntry
     * @param array<string,mixed> $capabilityDecl
     * @param array<string,mixed> $operation
     * @param list<array{kind:string,id:int}> $entities
     * @param array<string,mixed> $context
     * @return array{format:string,operation:array{authority_hash:string,lease_session_id:string,operation_id:string,input_hash:string,effect_hash:string},capability_digest:string,before_hash:?string,after_hash:?string,verified:bool,status:'verified'|'not_started'}
     */
    public static function reconcile_scoped(
        object $provider,
        array $actionEntry,
        array $capabilityDecl,
        array $operation,
        array $entities = [],
        array $context = []
    ): array {
        [$id, $capability, $args, $operation, $digest] = self::scoped_call_context(
            $actionEntry,
            $capabilityDecl,
            $operation,
            $entities,
            $context
        );
        $stored = self::scoped_operation_state($id, $capability, $operation);
        if ($stored['status'] === 'not_started') {
            return self::reviewed_scoped_result($operation, $digest, $stored, false);
        }
        if (!is_callable([$provider, 'reconcile_scoped'])) {
            throw new \RuntimeException("duo: provider '$id' capability '$capability' lacks scoped reconciliation support");
        }
        try {
            $raw = $provider->reconcile_scoped($capability, $args, $operation);
        } catch (\Throwable $t) {
            throw new \RuntimeException("duo: provider '$id' capability '$capability' scoped reconciliation failed");
        }
        $after = self::review_scoped_reconcile_response($id, $capability, $operation, $raw);
        $afterHash = self::scoped_evidence_hash($after);
        if (!hash_equals($stored['after_hash'], $afterHash)) {
            throw new \RuntimeException(
                "duo: provider '$id' capability '$capability' scoped effect readback does not match its durable operation receipt; recovery_required"
            );
        }
        return self::reviewed_scoped_result($operation, $digest, $stored, true);
    }

    /**
     * Begin an owner-namespaced operation receipt.  This is public for the
     * closed native action vocabulary; plugin adapters themselves never need
     * to implement persistence plumbing or expose raw evidence in an option.
     *
     * @param array<string,mixed> $operation
     */
    public static function begin_scoped_operation(string $owner, string $operationName, array $operation): void {
        $operation = self::validate_scoped_operation($operation);
        self::assert_scoped_operation_owner($owner, $operationName);
        $key = self::scoped_operation_option_name($owner, $operationName, $operation);
        $existing = self::read_scoped_operation_record($key, $owner, $operationName, $operation);
        if ($existing !== null) {
            throw new \RuntimeException(
                "duo: scoped operation '$owner/$operationName' already has a durable intent or receipt; reconcile that exact operation instead"
            );
        }
        $record = [
            'format' => self::SCOPED_OPERATION_RECEIPT_FORMAT,
            'owner' => $owner,
            'operation_name' => $operationName,
            'operation' => $operation,
            'state' => 'intent',
        ];
        $record['receipt_hash'] = self::scoped_hash($record);
        if (!function_exists('add_option')) {
            throw new \RuntimeException('duo: scoped operation receipt storage requires add_option()');
        }
        $encoded = Canon::encode($record);
        $added = add_option($key, $encoded, '', 'no');
        $readback = self::read_scoped_operation_record($key, $owner, $operationName, $operation);
        if (!$added) {
            if ($readback === null) {
                throw new \RuntimeException("duo: scoped operation '$owner/$operationName' intent was not durable");
            }
            throw new \RuntimeException(
                "duo: scoped operation '$owner/$operationName' collided with another durable operation; reconcile before retrying"
            );
        }
        if ($readback === null || $readback['state'] !== 'intent') {
            throw new \RuntimeException(
                "duo: scoped operation '$owner/$operationName' collided with another durable operation; reconcile before retrying"
            );
        }
    }

    /**
     * Complete an existing intent with hashes of reviewed evidence only.
     *
     * @param array<string,mixed> $operation
     * @return array{status:'verified',before_hash:string,after_hash:string}
     */
    public static function complete_scoped_operation(
        string $owner,
        string $operationName,
        array $operation,
        mixed $before,
        mixed $after
    ): array {
        $operation = self::validate_scoped_operation($operation);
        self::assert_scoped_operation_owner($owner, $operationName);
        $key = self::scoped_operation_option_name($owner, $operationName, $operation);
        $current = self::read_scoped_operation_record($key, $owner, $operationName, $operation);
        if ($current === null || $current['state'] !== 'intent') {
            throw new \RuntimeException(
                "duo: scoped operation '$owner/$operationName' cannot complete without its exact durable intent; recovery_required"
            );
        }
        $record = [
            'format' => self::SCOPED_OPERATION_RECEIPT_FORMAT,
            'owner' => $owner,
            'operation_name' => $operationName,
            'operation' => $operation,
            'state' => 'verified',
            'before_hash' => self::scoped_evidence_hash($before),
            'after_hash' => self::scoped_evidence_hash($after),
        ];
        $record['receipt_hash'] = self::scoped_hash($record);
        self::write_scoped_operation_record($key, $record, $owner, $operationName, $operation);
        $stored = self::read_scoped_operation_record($key, $owner, $operationName, $operation);
        if ($stored === null || $stored['state'] !== 'verified') {
            throw new \RuntimeException("duo: scoped operation '$owner/$operationName' verified receipt was not durable");
        }
        return [
            'status' => 'verified',
            'before_hash' => $stored['before_hash'],
            'after_hash' => $stored['after_hash'],
        ];
    }

    /**
     * Read one receipt without exposing its storage representation.  An intent
     * is intentionally an exception, not a third returned status: allowing a
     * caller to interpret it as not_started is how a crash would duplicate a
     * provider effect.
     *
     * @param array<string,mixed> $operation
     * @return array{status:'not_started',before_hash?:null,after_hash?:null}|array{status:'verified',before_hash:string,after_hash:string}
     */
    public static function scoped_operation_state(string $owner, string $operationName, array $operation): array {
        $operation = self::validate_scoped_operation($operation);
        self::assert_scoped_operation_owner($owner, $operationName);
        $record = self::read_scoped_operation_record(
            self::scoped_operation_option_name($owner, $operationName, $operation),
            $owner,
            $operationName,
            $operation
        );
        if ($record === null) {
            return ['status' => 'not_started'];
        }
        if ($record['state'] !== 'verified') {
            throw new \RuntimeException(
                "duo: scoped operation '$owner/$operationName' has a durable intent without a verified receipt; recovery_required"
            );
        }
        return [
            'status' => 'verified',
            'before_hash' => $record['before_hash'],
            'after_hash' => $record['after_hash'],
        ];
    }

    /**
     * Prepare and bind the exact arguments for one scoped provider call.
     *
     * @return array{0:string,1:string,2:array<string,mixed>,3:array{authority_hash:string,lease_session_id:string,operation_id:string,input_hash:string,effect_hash:string},4:string}
     */
    private static function scoped_call_context(
        array $actionEntry,
        array $capabilityDecl,
        array $operation,
        array $entities,
        array $context
    ): array {
        $id = (string) ($actionEntry['provider'] ?? '');
        $capability = (string) ($actionEntry['capability'] ?? '');
        if (preg_match(self::ID_PATTERN, $id) !== 1
            || preg_match(self::CAPABILITY_PATTERN, $capability) !== 1) {
            throw new \RuntimeException('duo: scoped provider invocation has an invalid provider or capability identity');
        }
        self::validate_capability_declaration($capabilityDecl, "provider '$id' capability '$capability'");
        self::validate_scoped_capability_declaration($capabilityDecl, "provider '$id' capability '$capability'");
        $args = self::scoped_invocation_args($actionEntry, $capabilityDecl, $entities, $context);
        $operation = self::validate_scoped_operation($operation);
        $expectedInput = self::scoped_hash([
            'kind' => 'provider',
            'provider' => $id,
            'capability' => $capability,
            'args' => $args,
        ]);
        if (!hash_equals($expectedInput, $operation['input_hash'])) {
            throw new \RuntimeException(
                "duo: scoped provider '$id' capability '$capability' operation input_hash does not bind the exact prepared invocation"
            );
        }
        return [
            $id,
            $capability,
            $args,
            $operation,
            self::scoped_capability_digest($id, $capability, $capabilityDecl),
        ];
    }

    /** @return array<string,mixed> */
    private static function scoped_invocation_args(
        array $actionEntry,
        array $capabilityDecl,
        array $entities,
        array $context
    ): array {
        $id = (string) ($actionEntry['provider'] ?? '');
        $capability = (string) ($actionEntry['capability'] ?? '');
        $args = (array) ($actionEntry['args'] ?? []);
        if (($capabilityDecl['scope'] ?? '') === 'entity') {
            $args[self::ENTITIES_ARG] = self::batch_payload($id, $capability, $capabilityDecl, $entities, $context);
        } elseif ($context !== []) {
            throw new \RuntimeException(
                "duo: provider '$id' capability '$capability' is scope: site but was handed engine batch "
                . 'context (' . implode(', ', array_keys($context)) . ') — only scope: entity capabilities '
                . 'receive a batch'
            );
        }
        return $args;
    }

    /** @param array<string,mixed> $capabilityDecl */
    private static function assert_scoped_budget(
        string $id,
        string $capability,
        array $capabilityDecl,
        float $elapsed
    ): void {
        $budget = (int) $capabilityDecl['timeout_seconds'];
        if ($elapsed > $budget) {
            throw new \RuntimeException(
                "duo: provider '$id' capability '$capability' scoped invocation overran its declared budget ("
                . round($elapsed, 3) . "s > {$budget}s)"
            );
        }
    }

    /**
     * @param mixed $raw
     * @return array{before:mixed,after:mixed,before_hash:string,after_hash:string}
     */
    private static function review_scoped_invoke_response(
        string $id,
        string $capability,
        array $operation,
        mixed $raw
    ): array {
        $keys = is_array($raw) ? array_keys($raw) : [];
        sort($keys, SORT_STRING);
        if ($keys !== ['after', 'before', 'operation', 'verified']) {
            throw new \RuntimeException(
                "duo: provider '$id' capability '$capability' returned a malformed scoped invocation receipt"
            );
        }
        if (($raw['verified'] ?? null) !== true || !is_array($raw['operation'] ?? null)) {
            throw new \RuntimeException(
                "duo: provider '$id' capability '$capability' returned an unverified scoped invocation receipt"
            );
        }
        self::assert_same_scoped_operation($operation, self::validate_scoped_operation($raw['operation']));
        return [
            'before' => $raw['before'],
            'after' => $raw['after'],
            'before_hash' => self::scoped_evidence_hash($raw['before']),
            'after_hash' => self::scoped_evidence_hash($raw['after']),
        ];
    }

    /** @return mixed */
    private static function review_scoped_reconcile_response(
        string $id,
        string $capability,
        array $operation,
        mixed $raw
    ): mixed {
        $keys = is_array($raw) ? array_keys($raw) : [];
        sort($keys, SORT_STRING);
        if ($keys !== ['after', 'operation', 'verified']) {
            throw new \RuntimeException(
                "duo: provider '$id' capability '$capability' returned a malformed scoped reconciliation receipt"
            );
        }
        if (($raw['verified'] ?? null) !== true || !is_array($raw['operation'] ?? null)) {
            throw new \RuntimeException(
                "duo: provider '$id' capability '$capability' returned an unverified scoped reconciliation receipt"
            );
        }
        self::assert_same_scoped_operation($operation, self::validate_scoped_operation($raw['operation']));
        // Hashing screens/bounds the readback before any value reaches a
        // durable/public result. The caller hashes again only to compare with
        // the completed receipt; no raw evidence escapes this stack frame.
        self::scoped_evidence_hash($raw['after']);
        return $raw['after'];
    }

    /**
     * @param array{authority_hash:string,lease_session_id:string,operation_id:string,input_hash:string,effect_hash:string} $operation
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private static function reviewed_scoped_result(
        array $operation,
        string $capabilityDigest,
        array $state,
        bool $verified
    ): array {
        if (!$verified) {
            if (($state['status'] ?? null) !== 'not_started') {
                throw new \RuntimeException('duo: scoped effect receipt has an invalid not_started state');
            }
            return [
                'format' => self::SCOPED_RECEIPT_FORMAT,
                'operation' => $operation,
                'capability_digest' => $capabilityDigest,
                'before_hash' => null,
                'after_hash' => null,
                'verified' => false,
                'status' => 'not_started',
            ];
        }
        if (($state['status'] ?? null) !== 'verified'
            || !is_string($state['before_hash'] ?? null)
            || !is_string($state['after_hash'] ?? null)
            || preg_match(self::SCOPED_HASH_PATTERN, $state['before_hash']) !== 1
            || preg_match(self::SCOPED_HASH_PATTERN, $state['after_hash']) !== 1) {
            throw new \RuntimeException('duo: scoped effect receipt has an invalid verified state');
        }
        return [
            'format' => self::SCOPED_RECEIPT_FORMAT,
            'operation' => $operation,
            'capability_digest' => $capabilityDigest,
            'before_hash' => $state['before_hash'],
            'after_hash' => $state['after_hash'],
            'verified' => true,
            'status' => 'verified',
        ];
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function assert_same_scoped_operation(array $left, array $right): void {
        foreach (['authority_hash', 'lease_session_id', 'operation_id', 'input_hash', 'effect_hash'] as $field) {
            if (!hash_equals((string) $left[$field], (string) $right[$field])) {
                throw new \RuntimeException('duo: scoped provider receipt does not bind the exact operation envelope');
            }
        }
    }

    private static function assert_scoped_operation_owner(string $owner, string $operationName): void {
        if (preg_match(self::ID_PATTERN, $owner) !== 1
            || preg_match(self::SCOPED_OPERATION_NAME_PATTERN, $operationName) !== 1) {
            throw new \RuntimeException('duo: scoped operation receipt owner or operation name is outside the closed vocabulary');
        }
    }

    /** @param array<string,mixed> $operation */
    private static function scoped_operation_option_name(string $owner, string $operationName, array $operation): string {
        return 'duo_scoped_effect_' . self::scoped_hash([
            'owner' => $owner,
            'operation_name' => $operationName,
            'operation_id' => $operation['operation_id'],
        ]);
    }

    /**
     * @param array<string,mixed> $operation
     * @return array<string,mixed>|null
     */
    private static function read_scoped_operation_record(
        string $key,
        string $owner,
        string $operationName,
        array $operation
    ): ?array {
        $database = $GLOBALS['wpdb'] ?? null;
        if (!is_object($database)
            || !isset($database->options)
            || !is_callable([$database, 'prepare'])
            || !is_callable([$database, 'get_var'])) {
            throw new \RuntimeException('duo: scoped operation receipt storage requires a readable WordPress options table');
        }
        $database->last_error = '';
        $raw = $database->get_var($database->prepare(
            "SELECT option_value FROM {$database->options} WHERE option_name = %s LIMIT 1",
            $key
        ));
        if ($raw === false || (string) ($database->last_error ?? '') !== '') {
            throw new \RuntimeException('duo: scoped operation receipt read failed');
        }
        if ($raw === null) {
            return null;
        }
        if (!is_string($raw)) {
            throw new \RuntimeException('duo: scoped operation receipt is not a canonical string');
        }
        try {
            $record = Canon::decode($raw);
        } catch (\Throwable $t) {
            throw new \RuntimeException('duo: scoped operation receipt is malformed; recovery_required');
        }
        return self::assert_scoped_operation_record($record, $owner, $operationName, $operation);
    }

    /**
     * @param array<string,mixed> $record
     * @param array<string,mixed> $operation
     */
    private static function write_scoped_operation_record(
        string $key,
        array $record,
        string $owner,
        string $operationName,
        array $operation
    ): void {
        if (!function_exists('update_option')) {
            throw new \RuntimeException('duo: scoped operation receipt storage requires update_option()');
        }
        update_option($key, Canon::encode($record), false);
        $readback = self::read_scoped_operation_record($key, $owner, $operationName, $operation);
        if ($readback === null || !hash_equals((string) $record['receipt_hash'], (string) $readback['receipt_hash'])) {
            throw new \RuntimeException('duo: scoped operation receipt write was not durable');
        }
    }

    /**
     * @param mixed $record
     * @param array<string,mixed> $operation
     * @return array<string,mixed>
     */
    private static function assert_scoped_operation_record(
        mixed $record,
        string $owner,
        string $operationName,
        array $operation
    ): array {
        if (!is_array($record) || (array_is_list($record) && $record !== [])) {
            throw new \RuntimeException('duo: scoped operation receipt has an invalid schema; recovery_required');
        }
        $state = $record['state'] ?? null;
        $keys = array_keys($record);
        sort($keys, SORT_STRING);
        $expected = $state === 'intent'
            ? ['format', 'operation', 'operation_name', 'owner', 'receipt_hash', 'state']
            : ['after_hash', 'before_hash', 'format', 'operation', 'operation_name', 'owner', 'receipt_hash', 'state'];
        if ($keys !== $expected || !in_array($state, ['intent', 'verified'], true)
            || ($record['format'] ?? null) !== self::SCOPED_OPERATION_RECEIPT_FORMAT
            || ($record['owner'] ?? null) !== $owner
            || ($record['operation_name'] ?? null) !== $operationName
            || !is_array($record['operation'] ?? null)
            || !is_string($record['receipt_hash'] ?? null)
            || preg_match(self::SCOPED_HASH_PATTERN, $record['receipt_hash']) !== 1) {
            throw new \RuntimeException('duo: scoped operation receipt has an invalid schema; recovery_required');
        }
        self::assert_same_scoped_operation($operation, self::validate_scoped_operation($record['operation']));
        if ($state === 'verified') {
            foreach (['before_hash', 'after_hash'] as $field) {
                if (!is_string($record[$field]) || preg_match(self::SCOPED_HASH_PATTERN, $record[$field]) !== 1) {
                    throw new \RuntimeException('duo: scoped operation receipt evidence hash is malformed; recovery_required');
                }
            }
        }
        $withoutHash = $record;
        unset($withoutHash['receipt_hash']);
        if (!hash_equals(self::scoped_hash($withoutHash), $record['receipt_hash'])) {
            throw new \RuntimeException('duo: scoped operation receipt hash does not verify; recovery_required');
        }
        return $record;
    }

    /** @param mixed $evidence */
    private static function scoped_evidence_hash(mixed $evidence): string {
        $bytes = 0;
        $nodes = 0;
        self::assert_scoped_evidence($evidence, $bytes, $nodes, 0);
        try {
            return self::scoped_hash($evidence);
        } catch (\Throwable $t) {
            throw new \RuntimeException('duo: scoped effect evidence could not be canonically encoded');
        }
    }

    /** @param mixed $value */
    private static function assert_scoped_evidence(mixed $value, int &$bytes, int &$nodes, int $depth): void {
        $nodes++;
        if ($nodes > self::MAX_SCOPED_EVIDENCE_NODES || $depth > self::MAX_SCOPED_EVIDENCE_DEPTH) {
            throw new \RuntimeException('duo: scoped effect evidence exceeds the bounded receipt grammar');
        }
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if (!is_int($key) && !is_string($key)) {
                    throw new \RuntimeException('duo: scoped effect evidence has a non-scalar key');
                }
                if (is_string($key)) {
                    $bytes += strlen($key);
                    if (strlen($key) > 128 || preg_match(self::SCOPED_SECRET_KEY_PATTERN, $key) === 1) {
                        throw new \RuntimeException('duo: scoped effect evidence contains a sensitive or unbounded field name');
                    }
                }
                if ($bytes > self::MAX_SCOPED_EVIDENCE_BYTES) {
                    throw new \RuntimeException('duo: scoped effect evidence exceeds the bounded receipt grammar');
                }
                self::assert_scoped_evidence($child, $bytes, $nodes, $depth + 1);
            }
            return;
        }
        if (is_string($value)) {
            $bytes += strlen($value);
            if (CommandRefusalException::containsSensitivePublicDetail($value)) {
                throw new \RuntimeException('duo: scoped effect evidence contains a secret-shaped value');
            }
        } elseif (is_int($value) || is_bool($value) || $value === null) {
            $bytes += strlen((string) $value);
        } elseif (is_float($value) && is_finite($value)) {
            $bytes += strlen((string) $value);
        } else {
            throw new \RuntimeException('duo: scoped effect evidence contains an unsupported value type');
        }
        if ($bytes > self::MAX_SCOPED_EVIDENCE_BYTES) {
            throw new \RuntimeException('duo: scoped effect evidence exceeds the bounded receipt grammar');
        }
    }

    /** @param mixed $value */
    private static function scoped_hash(mixed $value): string {
        return hash('sha256', Canon::encode($value));
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
        // ProviderPackagingException, not a bare RuntimeException: the message
        // and the fail-before-mutation behavior are unchanged (it IS a
        // RuntimeException), but a reporting caller can now tell "the adapter
        // is packaged wrong, and here is exactly which file" apart from
        // "somebody else's code threw during diagnosis" — see problems().
        if (!is_file($file)) {
            throw new ProviderPackagingException(
                $id,
                $manifest,
                "duo: manifest '$manifest' declares provider '$id' but $file is missing — "
                . 'provider code ships with its manifest, not the engine'
            );
        }
        require_once $file;
        $class = '\\Duo\\Providers\\' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $id)));
        if (!class_exists($class)) {
            throw new ProviderPackagingException(
                $id,
                $manifest,
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
     * One post type, two dispatchers — refused before the first mutation.
     *
     * The batch `regen_dependency` channel and the channel-declaring provider
     * contract are two dispatchers over the SAME durable bookkeeping: the
     * `regen_pending:`, `regen_delete_context:`, and `regen_reparent_context:`
     * keyspaces. Sharing them is deliberate (DUO-3342) — one retry vocabulary,
     * one `duo plan`/`duo status` projection, one meaning for an operator
     * auditing duo_kv — and it is only coherent while exactly one dispatcher
     * OWNS a given post type. Two claimants would each treat the other's
     * markers as theirs to consume and clear: the batch pass would sweep a
     * receipt the provider had not yet been delivered, and a verified provider
     * receipt would retire a marker the batch regenerator still owed work for.
     * Both are silent convergence claims about work that never happened.
     *
     * Refused at negotiation rather than at load, because the question is about
     * this run's SELECTION: the same manifest may legitimately declare a batch
     * regenerator for one post type and a channel-declaring capability for
     * another, and only the negotiated declaration says which channels the
     * installed capability actually asked for. It is still before any target
     * mutation, which is the property that matters.
     *
     * The remediation names the extension path rather than "pick one at
     * random": a post type migrating from the batch channel to a provider drops
     * its `regen_dependency` (batch, verify, and effects move to the action), so
     * the refusal is what a half-finished migration looks like.
     *
     * Every string below is bounded by a closed vocabulary or an
     * already-validated identifier (post types from the action's own
     * SURFACE_PATTERN-checked triggers, the capability name from
     * CAPABILITY_PATTERN, the channels from CONTEXT_CHANNELS — the declaration
     * was validated before this method is reached). DUO-3314 made a problem row
     * operator-facing wire data (Policy::provider_readiness_blockers() promotes
     * it into adapter_dispositions), so "no third-party free-form text in a
     * refusal" is a property this row has to keep, not a style preference.
     *
     * @param array<string,mixed> $providerDeclaration the manifest's providers[] row
     * @param array<string,mixed> $action the selected action
     * @param array<string,mixed> $decl the advertised capability declaration
     */
    private static function dual_claimant_problem(
        Policy $policy,
        array $providerDeclaration,
        string $capability,
        array $action,
        array $decl
    ): ?array {
        $channels = (array) ($decl['context'] ?? []);
        if ($channels === []) {
            return null;
        }
        $claimed = [];
        foreach ((array) ($action['triggers'] ?? []) as $trigger) {
            $trigger = is_string($trigger) ? $trigger : '';
            if (!str_starts_with($trigger, 'post:')) {
                continue;
            }
            $postType = substr($trigger, strlen('post:'));
            if ($postType !== '' && $policy->regen_batch($postType) !== null) {
                $claimed[$postType] = $postType;
            }
        }
        if ($claimed === []) {
            return null;
        }
        $types = implode(', ', array_keys($claimed));
        return self::problem(
            (string) $providerDeclaration['id'],
            (string) $providerDeclaration['manifest'],
            (string) $providerDeclaration['plugin'],
            'post_type_claimed_by_regen_batch',
            "post type(s) $types dispatched by exactly one of the batch regen_dependency channel "
                . "or capability '$capability' (context: " . implode(', ', $channels) . ')',
            "both: post_types.$types declares an enabled batch regen_dependency AND this action triggers "
                . "'$capability' on it",
            "finish the migration — remove the batch regen_dependency for $types (its batch, verify, and "
                . 'effects belong on this provider action) so one dispatcher owns the regen_pending / '
                . 'regen_delete_context / regen_reparent_context markers, or drop the `context` declaration '
                . 'and leave the batch channel in charge'
        );
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
        // `scoped` is intentionally accepted but NOT interpreted on the
        // ordinary negotiation path. Existing full applies retain their
        // contract byte-for-byte; only negotiate_scoped() asks the extra
        // declaration to make an at-most-once claim.
        $optional = ['context', 'scoped'];
        $missing = array_diff($required, $keys);
        $unknown = array_diff($keys, $required, $optional);
        if ($missing !== [] || $unknown !== []) {
            $shownOptional = array_key_exists('scoped', $decl) ? $optional : ['context'];
            throw new \RuntimeException(
                "$where must declare exactly " . implode(', ', $required)
                . ' (optional: ' . implode(', ', $shownOptional) . ')'
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
     * Scoped operation recovery opt-in. This validator is purposefully not
     * called from validate_capability_declaration(): an ordinary apply neither
     * needs nor is authorized to require a recovery implementation.
     *
     * @param array<string,mixed> $decl
     */
    private static function validate_scoped_capability_declaration(array $decl, string $where): void {
        $scoped = $decl['scoped'] ?? null;
        if (!is_array($scoped) || (array_is_list($scoped) && $scoped !== [])) {
            throw new \RuntimeException("$where.scoped must declare the operation-bound recovery contract");
        }
        $keys = array_keys($scoped);
        sort($keys, SORT_STRING);
        if ($keys !== ['operation_envelope', 'reconcile']
            || ($scoped['operation_envelope'] ?? null) !== self::SCOPED_OPERATION_FORMAT
            || ($scoped['reconcile'] ?? null) !== true) {
            throw new \RuntimeException(
                "$where.scoped must declare exactly operation_envelope: " . self::SCOPED_OPERATION_FORMAT
                . ' and reconcile: true'
            );
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
    /**
     * DUO-3317: the closed `requires` grammar, enforced at negotiation before
     * the provider file is loaded or its plugin registry is consulted. Returns
     * ONE aggregated problem naming every unmet requirement at once — the same
     * posture diagnose()'s docblock states for capabilities: an operator
     * repairing an environment needs the whole list (define this, upgrade
     * that), not the first miss followed by another round trip. The single
     * `provider_requirement_unmet` code carries one fact — "the declared
     * contract is not met here" — and lets remediation branch on kind within
     * the row.
     *
     * Null in two cases that must negotiate byte-for-byte as before: a
     * declaration with no `requires` (every provider that predates this
     * grammar), and one whose every declared requirement is satisfied.
     *
     * Every token placed in expected/found is bounded and non-secret: a
     * load-validated symbol name, a declared version window, an installed
     * plugin/WordPress version, or the system PHP_VERSION — the same value
     * kind (and source) outside_version_range already prints. No SQL, no
     * last_error, and no plugin literal beyond the basename problem() already
     * carries.
     *
     * @param array<string,mixed> $declaration
     * @param array{installed:bool,active:bool,version:string} $live
     */
    private static function requirement_problem(array $declaration, array $live): ?array {
        $req = $declaration['requires'] ?? null;
        if (!is_array($req) || $req === []) {
            return null;
        }
        $expected = [];
        $found = [];

        if (isset($req['functions'])) {
            $names = array_map('strval', (array) $req['functions']);
            $expected[] = 'functions ' . implode(', ', $names);
            $missing = array_values(array_filter($names, static fn(string $fn): bool => !function_exists($fn)));
            if ($missing !== []) {
                $found[] = 'missing functions: ' . implode(', ', $missing);
            }
        }
        if (isset($req['classes'])) {
            $names = array_map('strval', (array) $req['classes']);
            $expected[] = 'classes ' . implode(', ', $names);
            $missing = array_values(array_filter($names, static fn(string $cls): bool => !class_exists($cls)));
            if ($missing !== []) {
                $found[] = 'missing classes: ' . implode(', ', $missing);
            }
        }
        if (isset($req['plugin_version'])) {
            $min = (string) $req['plugin_version']['min'];
            $max = (string) $req['plugin_version']['max'];
            $expected[] = "plugin_version >=$min <$max";
            $installed = (string) $live['version'];
            if ($installed === '' || !Deploy::in_range($installed, $min, $max)) {
                $found[] = 'plugin ' . ($installed !== '' ? $installed : '(unknown version)');
            }
        }
        if (isset($req['wordpress_version'])) {
            $min = (string) $req['wordpress_version']['min'];
            $max = (string) $req['wordpress_version']['max'];
            $expected[] = "wordpress_version >=$min <$max";
            $wp = function_exists('get_bloginfo') ? (string) get_bloginfo('version') : '';
            if ($wp === '' || !Deploy::in_range($wp, $min, $max)) {
                $found[] = 'wordpress ' . ($wp !== '' ? $wp : '(unknown version)');
            }
        }
        if (isset($req['php_version'])) {
            $min = (string) $req['php_version']['min'];
            $max = (string) $req['php_version']['max'];
            $expected[] = "php_version >=$min <$max";
            if (!Deploy::in_range(PHP_VERSION, $min, $max)) {
                $found[] = 'php ' . PHP_VERSION;
            }
        }

        if ($found === []) {
            return null;
        }
        return self::problem(
            (string) $declaration['id'],
            (string) $declaration['manifest'],
            (string) $declaration['plugin'],
            'provider_requirement_unmet',
            implode('; ', $expected),
            implode('; ', $found),
            'install, activate, or upgrade the owning plugin and platform until this environment satisfies the '
                . "provider's declared requirements, or unpin the manifest that declares them"
        );
    }

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

/**
 * An adapter that declares manifest-sourced provider code the manifest does
 * not actually ship.
 *
 * A RuntimeException subclass, so every existing catch, message, and
 * fail-before-mutation behavior is unchanged — `Providers::negotiate()` throws
 * exactly what it always threw, with exactly the wording
 * regress_provider_contract.php pins. What the subclass adds is the ability to
 * tell this apart from a throw that came out of code the engine does not own,
 * and to do it WITHOUT string-matching a message or reading a stack trace.
 * `Providers::problems()` is the caller that needs the distinction: this fault
 * has a repairable file and a named owner, and everything else has neither, so
 * one remediation could not honestly serve both.
 *
 * Co-located with the only class that throws it, the way
 * RepositoryCompilationException sits in RepositoryCompiler.php.
 */
final class ProviderPackagingException extends \RuntimeException {
    private string $providerId;
    private string $manifest;

    public function __construct(string $providerId, string $manifest, string $message) {
        parent::__construct($message);
        $this->providerId = $providerId;
        $this->manifest = $manifest;
    }

    public function providerId(): string {
        return $this->providerId;
    }

    public function manifest(): string {
        return $this->manifest;
    }
}

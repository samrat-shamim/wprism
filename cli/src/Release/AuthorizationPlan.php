<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once dirname(__DIR__) . '/Contract/ProjectionVocabulary.php';
require_once dirname(__DIR__) . '/Plan/PlanContract.php';

use WPrism\Canon;
use WPrism\CommandRefusalException;

/**
 * The frozen authorization plan — `wprism-authorization-plan/v1` (round-3 MUP
 * §2.3, §2.3.1; product spec *Release and verify*).
 *
 * The spec's requirement is a sequencing one before it is a document one:
 * *"Before any production-visible code, data, filesystem, lifecycle, or
 * external-effect mutation, WPrism must durably bind and present an
 * authorization plan"*. Four mechanics implement that, and each exists
 * because the obvious cheaper version is wrong:
 *
 *  1. **`build()` is pure.** No clock (`frozen_at` is an argument), no I/O,
 *     no target read. Identical inputs produce identical bytes, which is what
 *     makes `plan_digest` an identity rather than a timestamp, and what lets
 *     `reverify()` compare two plans at all.
 *  2. **`freeze()` writes before the caller mutates anything**, through a
 *     same-directory `tempnam()` + `rename(2)`. A partially written
 *     authorization is worse than none: a reader would see a plan that
 *     authorizes less than the release is about to do.
 *  3. **`reverify()` treats ANY input change as invalidation.** The spec:
 *     *"Any plan change invalidates that authorization."* Not "a material
 *     change" — any. The comparison basis is therefore digests of the whole
 *     agent plan envelope and the whole target fact vector, not a hand-picked
 *     subset of fields somebody thought were the important ones.
 *  4. **Refusals are pure and pre-freeze.** `refusals()` returns refusal
 *     SPECS; it raises nothing and writes nothing. MUP §2.3 puts these
 *     strictly before step 1, so by construction none of them can produce a
 *     frozen plan, and each carries a §2.1 gap action rather than a release
 *     next action — the fix is an assessment fix. The verb boundary in
 *     `cli/src/Command/` turns a spec into a `CommandRefusalException`.
 *
 * ## Three documented extensions to §2.3.1's example
 *
 * §2.3.1 shows an illustrative document, not a closed schema. Three keys are
 * added, each because a §2.3 requirement has nowhere else to live:
 *
 *  - **`inputs_digest`** — `{conditions_sha256, plan_sha256,
 *    target_facts_sha256}`. §2.3 step 3 invalidates authorization on *any*
 *    difference between the frozen plan and a recomputed one. The frozen
 *    document must therefore carry a digest of every input it was computed
 *    from, or "recompute and compare" reduces to comparing the handful of
 *    fields that happened to be printed. `conditions_sha256` is the third key
 *    and the one this build added: `plan_sha256` and `target_facts_sha256`
 *    are recomputed from a fresh target read, but `capabilities` was COPIED
 *    into the frozen plan and therefore re-hashed to its own frozen value by
 *    construction — so the two conditions §1.3 promises are "re-evaluated at
 *    the mutation gate" could not move the comparison at all. It digests the
 *    per-MANIFEST condition vector (`conditionVector()`), because that is the
 *    only projection of a condition the gate can recompute: the gate re-reads
 *    one `wp wprism capabilities` document and has no surface join.
 *    `validate()` deliberately does NOT require the key, so a plan frozen by
 *    an older build still reads back for `wprism verify --plan=<digest>`; a
 *    missing key compares `''` at the gate and refuses loudly as
 *    `plan_changed` rather than being defaulted away.
 *  - **`recovery_profile.claim`** — the `wprism-recovery-claim/v1` array,
 *    embedded verbatim instead of flattened into `restores` /
 *    `does_not_restore` / `writer_exclusion` / `maximum_loss_boundary`.
 *    §2.5 requires the claim printed at recovery to be the claim printed at
 *    authorization; embedding the canonical array (with its own digest)
 *    makes that byte-identity checkable, whereas re-flattening it would make
 *    it a coincidence. `selected` and `selected_because` are still mirrored
 *    at the `recovery_profile` level exactly as §2.3.1 shows them.
 *  - **`recovery_profile.checkpoint_at`** — the instant the checkpoint the
 *    claim's loss boundary refers to pins, or null when no checkpoint is
 *    taken. §2.3.1 shows that instant interpolated into the claim's
 *    `maximum_loss_boundary` sentence; it cannot live there, because the
 *    claim is digested WHOLE into `plan_digest` and a clock value inside the
 *    digest makes the digest a timestamp rather than an identity — two
 *    `--plan-only` runs a second apart would name one decision twice.
 *    `digest()` therefore excludes this key exactly as it excludes
 *    `frozen_at`, and both printings put it next to the claim instead of in
 *    it (`AuthorizationPlanRenderer::recoverySection()`, `wprism recover`).
 *
 * ## Why Release never references a Recovery class
 *
 * The recovery claim arrives as a canonical array in `inputs['recovery']`.
 * That is the module map's rule 9 in practice: `cli:Release` may depend on
 * `Contract`, `Plan`, `Transport`, `agent:Kernel` and `agent:Policy` and on
 * nothing in `cli:Recovery`, so the verb boundary composes
 * `RecoveryProfileSelection` and hands its result down as data.
 */
final class AuthorizationPlan {
    public const FORMAT = 'wprism-authorization-plan/v1';

    /** MUP §3.1: frozen plans live in the site repository, committed. */
    public const DIRECTORY = '.wprism/releases';

    /**
     * The closed code lifecycle vocabulary a release may name.
     *
     * `retire` and `activate` are the engine's own phases
     * (agent/src/Promotion/LifecycleJournal.php, which accepts exactly
     * `retire` then `activate`); `deploy`, `finalize` and `verify` are the
     * host-side code-release steps `wprism deploy` / `wprism release` drive
     * (`code-preflight`, `code-stage`, `code-finalize`, `verify-canonical`).
     * §2.3.1's own example lists `["retire", "activate", "verify"]`.
     *
     * @var list<string>
     */
    public const LIFECYCLE_PHASES = ['deploy', 'retire', 'activate', 'finalize', 'verify'];

    /**
     * The subset that puts the target inside MUP §1.5's lifecycle window —
     * the window where WordPress hooks DO fire, so mail, webhooks and
     * payment code can run. `verify` is excluded: it is a read-back.
     *
     * @var list<string>
     */
    public const CODE_LIFECYCLE_PHASES = ['deploy', 'retire', 'activate', 'finalize'];

    /**
     * The canonical surface name for the code lifecycle window in a
     * contract's `external_effects[].surfaces`. `ContractProposal` generates
     * exactly this string, so a reviewed proposal satisfies the §1.6 gate
     * without the operator inventing a name.
     */
    public const LIFECYCLE_WINDOW_SURFACE = 'plugins/themes';

    /** §2.3.1's `authority_still_required[].kind` vocabulary. */
    public const AUTHORITY_KINDS = ['operator_confirmation', 'business_owner', 'declared_live_effect'];

    /**
     * MUP §2.3: a surface in scope projecting any of these refuses BEFORE
     * the plan is frozen. `Experimental` is here because the product spec
     * makes it unable to authorize production "including through a
     * conditional path"; the other three are the safety invariants' "zero
     * unsupported Ready claims".
     *
     * @var list<string>
     */
    public const BLOCKING_READINESS = [
        'Experimental', 'Not qualified', 'Unsupported', 'Requalification required',
    ];

    /** MUP §1.6: recovery semantics that block an operation reaching a live system. */
    public const BLOCKING_RECOVERY_SEMANTICS = 'unknown';

    /** Closed input key set for build() and refusals(). */
    private const INPUT_KEYS = [
        'authority',
        'capabilities',
        'contract',
        'deletion_semantics',
        'environment',
        'flags',
        'frozen_at',
        'plan',
        'projection',
        'recovery',
        'scope',
        'target',
    ];

    /**
     * Build the frozen-plan document. Pure: no clock, no I/O.
     *
     * @param array<string,mixed> $inputs closed keys:
     *   - `environment` (string) the target environment name;
     *   - `frozen_at` (string) canonical UTC seconds, supplied by the caller;
     *   - `plan` (array) the complete `wp wprism plan --format=json` envelope —
     *     validated through `PlanContract::requireComplete()`, because every
     *     count below is only trustworthy if the bucket exists;
     *   - `contract` (?array) a validated `wprism-application-contract/v2`, or
     *     null when the site has none;
     *   - `projection` (list<array>) the `projection.json` surface rows for
     *     the surfaces in scope, each `{id, label?, operations: {release: …}}`;
     *   - `recovery` (array) `{selected, selected_because, claim,
     *     checkpoint_at?}` — the `RecoveryProfileSelection::decide()` result,
     *     as data. `checkpoint_at` is optional and may be null: it is the
     *     clock value the claim deliberately does not carry, and it is
     *     excluded from `plan_digest`;
     *   - `target` (array) the target fact vector (artifact hash, revisions,
     *     stack probe). Digested whole into `inputs_digest`;
     *   - `scope` (array) `{surfaces: list<string>, code: {plugins_changed:int,
     *     themes_changed:int, lifecycle_phases: list<string>, paths?: list<string>}}`;
     *   - `capabilities` (list<array>) the capability + condition rows;
     *   - `authority` (list<array>) extra `{kind, reason}` rows the caller knows;
     *   - `flags` (array) `{with_deletes: bool, plan_only: bool}`;
     *   - `deletion_semantics` (array) `{unsupported: list<string>}` from the registry.
     * @return array<string,mixed>
     */
    public static function build(array $inputs): array {
        $inputs = self::normalizeInputs($inputs);
        $plan = $inputs['plan'];
        $contract = $inputs['contract'];
        $scope = $inputs['scope'];
        $recovery = $inputs['recovery'];

        $lifecycle = self::lifecyclePhases($scope);
        $window = self::lifecycleWindow($contract, $lifecycle);

        $document = [
            'artifact_hash' => (string) ($inputs['target']['artifact_hash'] ?? ''),
            'authority_still_required' => self::authority($inputs, $window),
            'capabilities' => $inputs['capabilities'],
            'code_revision_from' => (string) ($inputs['target']['code_revision_from'] ?? ''),
            'contract_digest' => $contract === null ? null : (string) ($contract['contract_digest'] ?? ''),
            'effects' => self::effects($inputs, $window),
            'environment' => (string) $inputs['environment'],
            'format' => self::FORMAT,
            'frozen_at' => (string) $inputs['frozen_at'],
            'inputs_digest' => [
                'conditions_sha256' => self::conditionsDigest($inputs['capabilities']),
                'plan_sha256' => 'sha256:' . hash('sha256', Canon::encode($plan)),
                'target_facts_sha256' => 'sha256:' . hash('sha256', Canon::encode($inputs['target'])),
            ],
            'may_change' => self::mayChange($inputs),
            'recovery_profile' => [
                'checkpoint_at' => $recovery['checkpoint_at'] ?? null,
                'claim' => $recovery['claim'],
                'selected' => (string) $recovery['selected'],
                'selected_because' => (string) $recovery['selected_because'],
            ],
            'scope' => [
                'code' => [
                    'lifecycle_phases' => $lifecycle,
                    'plugins_changed' => (int) ($scope['code']['plugins_changed'] ?? 0),
                    'themes_changed' => (int) ($scope['code']['themes_changed'] ?? 0),
                ],
                'entities' => [
                    'create' => count($plan['create']),
                    'delete' => count($plan['delete']),
                    'update' => count($plan['update']),
                ],
                'surfaces' => self::stringList($scope['surfaces'] ?? [], 'scope.surfaces'),
            ],
        ];
        $document['plan_digest'] = self::digest($document);

        return $document;
    }

    /**
     * `sha256:` + the digest of the canonical encoding of everything except
     * `plan_digest`, `frozen_at` and `recovery_profile.checkpoint_at`.
     *
     * The two excluded clock values are excluded for one reason: the digest
     * identifies the AUTHORIZATION, not the moment it was printed. Re-running
     * `wprism release --plan-only` twice against an unchanged target must
     * produce the same identity, or the `.wprism/releases/` directory fills with
     * duplicates of one decision and `wprism verify --plan=<digest>` has no
     * stable name to cite. Excluding `frozen_at` alone was not enough while
     * the embedded recovery claim interpolated the checkpoint instant into
     * its own loss-boundary sentence and digested it: the plan then changed
     * every second, which is exactly what this docblock has always promised
     * it does not. A digest that includes a clock is a timestamp, not an
     * identity — so every clock value in this document is either excluded
     * here or does not belong in it.
     *
     * `reverify()` is unaffected either way: it compares `inputs_digest`,
     * never this one.
     *
     * @param array<string,mixed> $document
     */
    public static function digest(array $document): string {
        unset($document['plan_digest'], $document['frozen_at']);
        if (is_array($document['recovery_profile'] ?? null)) {
            // Guarded: validate() computes this digest before it has proved
            // the recovery block is even an array, and unsetting an offset of
            // a string is a fatal Error rather than the refusal a malformed
            // frozen plan is owed.
            unset($document['recovery_profile']['checkpoint_at']);
        }

        return 'sha256:' . hash('sha256', Canon::encode($document));
    }

    /** Canonical bytes, exactly as they land in `.wprism/releases/<digest>.json`. */
    public static function encode(array $document): string {
        return Canon::encode($document);
    }

    /**
     * Refuse anything that is not a well-formed frozen plan.
     *
     * @param array<string,mixed> $document
     */
    public static function validate(array $document): void {
        if (($document['format'] ?? null) !== self::FORMAT) {
            throw self::refuse('authorization_plan_format_invalid', 'the document is not a ' . self::FORMAT);
        }
        foreach (['environment', 'frozen_at'] as $key) {
            if (!is_string($document[$key] ?? null) || ($document[$key] ?? '') === '') {
                throw self::refuse('authorization_plan_shape_invalid', "the authorization plan has no $key");
            }
        }
        $digest = $document['plan_digest'] ?? null;
        if (!is_string($digest) || preg_match('/^sha256:[a-f0-9]{64}$/D', $digest) !== 1) {
            throw self::refuse('authorization_plan_shape_invalid', 'the authorization plan carries no sha256: digest');
        }
        if (!hash_equals(self::digest($document), $digest)) {
            throw self::refuse(
                'authorization_plan_digest_mismatch',
                'the authorization plan digest does not match its own content'
            );
        }
        $inputs = $document['inputs_digest'] ?? null;
        if (!is_array($inputs)
            || !is_string($inputs['plan_sha256'] ?? null)
            || !is_string($inputs['target_facts_sha256'] ?? null)) {
            throw self::refuse(
                'authorization_plan_shape_invalid',
                'the authorization plan carries no inputs_digest to re-verify against'
            );
        }
        $effects = $document['effects'] ?? null;
        if (!is_array($effects) || !is_array($effects['unknown_blocking'] ?? null)) {
            throw self::refuse('authorization_plan_shape_invalid', 'the authorization plan has no effects block');
        }
        if ($effects['unknown_blocking'] !== []) {
            // MUP §2.3 refuses an unknown blocking effect before step 1, so a
            // frozen plan carrying one means the refusal gate was bypassed.
            throw self::refuse(
                'authorization_plan_unknown_effect',
                'the frozen authorization plan carries an unknown effect that should have blocked the release'
            );
        }
        $recovery = $document['recovery_profile'] ?? null;
        if (!is_array($recovery) || !is_array($recovery['claim'] ?? null)
            || !is_string($recovery['selected'] ?? null)) {
            throw self::refuse(
                'authorization_plan_shape_invalid',
                'the authorization plan names no recovery profile and claim'
            );
        }
        $checkpointAt = $recovery['checkpoint_at'] ?? null;
        if ($checkpointAt !== null && (!is_string($checkpointAt) || $checkpointAt === '')) {
            // Checked even though it is outside the digest — precisely
            // BECAUSE it is outside the digest. A hand-edited plan cannot be
            // caught here by the digest mismatch every other field enjoys, so
            // the shape gate is the only thing between a garbage value and
            // the line `wprism recover` prints beside the claim.
            throw self::refuse(
                'authorization_plan_shape_invalid',
                "the authorization plan's recovery checkpoint_at is neither a timestamp nor absent"
            );
        }
    }

    /**
     * Where a frozen plan lives.
     *
     * The digest is stored as `sha256:<hex>` inside the document, but the
     * FILE is named `<hex>.json`: a colon is legal on POSIX yet breaks on
     * Windows checkouts and in enough tooling to be a poor identifier for a
     * committed artifact. Both spellings are accepted here so a caller may
     * pass whatever `wprism verify --plan=` was given.
     */
    public static function path(string $siteRepo, string $planDigest): string {
        $hex = str_starts_with($planDigest, 'sha256:') ? substr($planDigest, 7) : $planDigest;
        if (preg_match('/^[a-f0-9]{64}$/D', $hex) !== 1) {
            throw self::refuse(
                'authorization_plan_digest_invalid',
                'the plan digest is not a sha256 digest'
            );
        }

        return rtrim($siteRepo, '/') . '/' . self::DIRECTORY . '/' . $hex . '.json';
    }

    /**
     * Persist the plan durably, before any mutation.
     *
     * `$planOnly` is a structural gate, not a courtesy: MUP §2.3 says
     * `--plan-only` "prints it and exits 0 without mutating", and writing a
     * file into the site repository's working tree is a mutation of the site
     * repository. Passing `true` here is therefore a caller bug and refuses,
     * so the property is enforced by the class rather than by remembering
     * not to call it.
     *
     * Re-freezing an identical plan is a no-op that keeps the ORIGINAL bytes:
     * the digest excludes `frozen_at` and `recovery_profile.checkpoint_at`,
     * so a second run of the same authorization must not silently re-date
     * the record of when it was given, or of the checkpoint instant the
     * operator was shown with it.
     *
     * @param array<string,mixed> $document
     * @return string the path written (or the path that already held it)
     */
    public static function freeze(array $document, string $siteRepo, bool $planOnly = false): string {
        if ($planOnly) {
            throw self::refuse(
                'plan_only_must_not_freeze',
                '--plan-only prints the authorization plan and mutates nothing, including the site repository'
            );
        }
        self::validate($document);
        $path = self::path($siteRepo, (string) $document['plan_digest']);
        if (is_file($path)) {
            return $path;
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw self::refuse(
                'release_directory_unwritable',
                'the .wprism/releases directory could not be created',
                'check write permission on the site repository working tree'
            );
        }
        $temporary = @tempnam($directory, '.wprism-release-');
        if (!is_string($temporary) || $temporary === '') {
            throw self::refuse(
                'release_plan_write_failed',
                'a temporary file could not be created beside the frozen plan',
                'check write permission and free space on the site repository working tree'
            );
        }
        try {
            if (@file_put_contents($temporary, self::encode($document), LOCK_EX) === false) {
                throw self::refuse(
                    'release_plan_write_failed',
                    'the authorization plan could not be written',
                    'check write permission and free space on the site repository working tree'
                );
            }
            @chmod($temporary, 0666 & ~umask());
            if (!@rename($temporary, $path)) {
                throw self::refuse(
                    'release_plan_write_failed',
                    'the authorization plan could not be published atomically',
                    'check write permission on the .wprism/releases directory'
                );
            }
            $temporary = null;
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return $path;
    }

    /**
     * Read a frozen plan back, validated, or null when it is not there.
     *
     * @return ?array<string,mixed>
     */
    public static function read(string $siteRepo, string $planDigest): ?array {
        $path = self::path($siteRepo, $planDigest);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw self::refuse(
                'release_plan_unreadable',
                'a frozen authorization plan could not be read',
                'check the file permissions on the .wprism/releases directory'
            );
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw self::refuse(
                'release_plan_unreadable',
                'a frozen authorization plan is not a JSON object',
                'restore .wprism/releases from git, or re-run wprism release --plan-only to produce a fresh plan'
            );
        }
        self::validate($decoded);

        return $decoded;
    }

    /**
     * The fact vector `reverify()` compares against a frozen plan.
     *
     * Built from the SAME inputs `build()` takes, so a caller cannot
     * accidentally re-verify against a differently shaped fact vector.
     *
     * @param array<string,mixed> $inputs
     * @return array<string,string>
     */
    public static function currentFacts(array $inputs): array {
        $inputs = self::normalizeInputs($inputs);
        $contract = $inputs['contract'];

        return [
            'artifact_hash' => (string) ($inputs['target']['artifact_hash'] ?? ''),
            'code_revision_from' => (string) ($inputs['target']['code_revision_from'] ?? ''),
            'contract_digest' => $contract === null ? '' : (string) ($contract['contract_digest'] ?? ''),
            'environment' => (string) $inputs['environment'],
            'conditions_sha256' => self::conditionsDigest($inputs['capabilities']),
            'plan_sha256' => 'sha256:' . hash('sha256', Canon::encode($inputs['plan'])),
            'target_facts_sha256' => 'sha256:' . hash('sha256', Canon::encode($inputs['target'])),
        ];
    }

    /**
     * Re-verify a frozen plan against the target as it is now.
     *
     * ANY difference invalidates the authorization (product spec,
     * *Authorization*). The refusal names the fields that moved, never their
     * values: a target fact vector can carry paths and hostnames, and this
     * refusal is a public envelope.
     *
     * @param array<string,mixed> $planDocument a frozen plan
     * @param array<string,string> $currentFacts from currentFacts()
     */
    public static function reverify(array $planDocument, array $currentFacts): void {
        self::validate($planDocument);
        $frozen = [
            'artifact_hash' => (string) ($planDocument['artifact_hash'] ?? ''),
            'code_revision_from' => (string) ($planDocument['code_revision_from'] ?? ''),
            'contract_digest' => (string) ($planDocument['contract_digest'] ?? ''),
            'environment' => (string) ($planDocument['environment'] ?? ''),
            'conditions_sha256' => (string) ($planDocument['inputs_digest']['conditions_sha256'] ?? ''),
            'plan_sha256' => (string) ($planDocument['inputs_digest']['plan_sha256'] ?? ''),
            'target_facts_sha256' => (string) ($planDocument['inputs_digest']['target_facts_sha256'] ?? ''),
        ];
        $changed = [];
        foreach ($frozen as $field => $value) {
            if (!array_key_exists($field, $currentFacts)) {
                $changed[] = $field;
                continue;
            }
            if (!hash_equals($value, (string) $currentFacts[$field])) {
                $changed[] = $field;
            }
        }
        if ($changed !== []) {
            throw new CommandRefusalException(
                'plan_changed',
                'the target changed after the authorization plan was frozen, so that authorization no longer applies',
                'stage the intended source again, prepare a fresh release subject against the current target, '
                    . 'obtain a new external signature, then run release execute with every new expected digest',
                [['changed_fields' => $changed]]
            );
        }
    }

    /**
     * The per-MANIFEST condition vector a frozen plan is digested over.
     *
     * Per manifest, not per surface, and that is forced rather than chosen:
     * the mutation gate re-observes conditions by re-reading ONE
     * `wp wprism capabilities` document (`AssessCommand::capabilityReport()`),
     * which knows manifests and knows nothing about which surface a claim was
     * joined to — `SurfaceCatalog::catalog()` needs the inventory and the
     * contract to make that join, and running it again at the gate would be a
     * second full assessment inside the confirmation window. Grouping both
     * sides the same way is what makes the frozen digest and the gate-time
     * digest comparable at all.
     *
     * Rows are deduped and sorted inside each manifest, so two surfaces
     * carrying the same adapter's one condition contribute one entry and the
     * digest does not move when a surface is added to scope without changing
     * a single observation.
     *
     * `rechecked_at` is deliberately NOT in the vector: it is the constant
     * word `mutation gate`, a label rather than an observation, and a value
     * that never varies contributes nothing to a comparison.
     *
     * @param list<array<string,mixed>> $capabilities the plan's capability rows
     * @return array<string,list<array<string,mixed>>>
     */
    public static function conditionVector(array $capabilities): array {
        $byManifest = [];
        foreach ($capabilities as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ((array) ($row['conditions'] ?? []) as $condition) {
                // A prose condition is a plan frozen by a build older than the
                // gate. It contributes nothing here rather than being coerced
                // into a fact vector nobody observed; the gate refuses it out
                // loud as uncheckable (recheckConditions() below).
                if (!is_array($condition)) {
                    continue;
                }
                $byManifest[(string) ($condition['manifest'] ?? '')][] = self::conditionFacts($condition);
            }
        }
        foreach ($byManifest as $manifest => $rows) {
            $unique = [];
            foreach ($rows as $facts) {
                $unique[Canon::encode($facts)] = $facts;
            }
            ksort($unique, SORT_STRING);
            $byManifest[$manifest] = array_values($unique);
        }
        ksort($byManifest, SORT_STRING);

        return $byManifest;
    }

    /**
     * The frozen capability rows with every condition replaced by what the
     * target reports NOW, for the same manifest.
     *
     * This is what `currentFacts()` is handed at the mutation gate, and it is
     * the fail-closed backstop behind `recheckConditions()`: even if the named
     * refusal below missed a shape, `reverify()`'s digest comparison still
     * refuses, because the vector it hashes is built from the fresh
     * observation rather than from the copy the plan already carried.
     *
     * @param list<array<string,mixed>> $capabilities
     * @param array<string,list<array<string,mixed>>> $observedByManifest
     * @return list<array<string,mixed>>
     */
    public static function withObservedConditions(array $capabilities, array $observedByManifest): array {
        $out = [];
        foreach ($capabilities as $row) {
            if (is_array($row)) {
                $manifest = (string) ($row['manifest'] ?? '');
                $row['conditions'] = $manifest === ''
                    ? []
                    : array_values($observedByManifest[$manifest] ?? []);
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Re-check every machine-checkable condition the frozen plan names,
     * against the target as it is at the mutation gate.
     *
     * The product spec is one sentence and this method is it:
     * *"execution is permitted only when every named, machine-checkable
     * condition is satisfied and rechecked at the mutation gate. An unmet or
     * uncheckable condition blocks."* (docs/product-spec.md:302-303.)
     *
     * Two named refusals, both post-freeze failure class `capability_expired`
     * and therefore next action `requalify`:
     *
     *  - **`release_condition_uncheckable`** — a manifest the plan named is
     *    absent from the fresh report, or a condition row carries no `subject`
     *    to re-probe. Uncheckable blocks; it never passes quietly.
     *  - **`release_condition_changed`** — an observation moved, a condition
     *    appeared for a plan-named manifest, or one was withdrawn.
     *
     * Both are raised BEFORE `reverify()`'s generic `plan_changed`, and that
     * ordering is the whole point of having them: `plan_changed` is documented
     * as "a post-freeze failure that mutated nothing", which is exactly why
     * its next action is `retry` (ReleaseCommand.php:444-446). Telling an
     * operator to retry after a plugin was deactivated sends them straight
     * into an identical refusal — the argument `NextAction`'s own docblock
     * makes about never letting one failure class acquire two answers.
     *
     * The diagnostics name `code`, `subject`, `manifest` and `state`, and
     * never an observed VALUE. Same public-envelope rule `reverify()` states
     * above: a refusal an operator pastes into a ticket must not carry a fact
     * about the target that the operator did not choose to publish.
     *
     * @param array<string,mixed> $planDocument a frozen plan
     * @param array<string,list<array<string,mixed>>> $observedByManifest from
     *        `SurfaceCatalog::conditionsByManifest()` on a freshly read report
     * @param string $at the instant the re-observation was made
     * @return array{at:string,checked:int,conditions:int,manifests:list<string>}
     */
    public static function recheckConditions(array $planDocument, array $observedByManifest, string $at): array {
        $capabilities = is_array($planDocument['capabilities'] ?? null) ? $planDocument['capabilities'] : [];
        $frozen = [];
        foreach ($capabilities as $row) {
            if (!is_array($row)) {
                continue;
            }
            $manifest = (string) ($row['manifest'] ?? '');
            $conditions = (array) ($row['conditions'] ?? []);
            if ($manifest === '') {
                // A surface no claim covers names no manifest and therefore
                // carries no condition to re-check. It is not silently
                // skipped: if such a row DID carry conditions, the plan is
                // shaped in a way this gate cannot re-probe, and that blocks.
                if ($conditions !== []) {
                    throw self::conditionRefusal(
                        'release_condition_uncheckable',
                        'a condition in the frozen plan names no manifest, so it cannot be re-observed',
                        [['manifest' => '', 'state' => 'unattributed']]
                    );
                }
                continue;
            }
            $frozen[$manifest] = array_merge($frozen[$manifest] ?? [], $conditions);
        }

        $names = array_keys($frozen);
        sort($names, SORT_STRING);
        $rowCount = 0;
        foreach ($names as $manifest) {
            if (!array_key_exists($manifest, $observedByManifest)) {
                throw self::conditionRefusal(
                    'release_condition_uncheckable',
                    'a manifest this release was authorized against is absent from the capability report the '
                        . 'target answers with now, so its conditions cannot be re-observed',
                    [['manifest' => $manifest, 'state' => 'absent_from_report']]
                );
            }
            $frozenRows = self::conditionIndex($frozen[$manifest], $manifest);
            $observedRows = self::conditionIndex($observedByManifest[$manifest], $manifest);
            foreach ($observedRows as $key => $facts) {
                if (!array_key_exists($key, $frozenRows)) {
                    throw self::conditionDrift($manifest, $facts, 'appeared');
                }
                if (!hash_equals(Canon::encode($frozenRows[$key]), Canon::encode($facts))) {
                    throw self::conditionDrift($manifest, $facts, 'moved');
                }
            }
            foreach ($frozenRows as $key => $facts) {
                if (!array_key_exists($key, $observedRows)) {
                    throw self::conditionDrift($manifest, $facts, 'withdrawn');
                }
            }
            $rowCount += count($observedRows);
        }

        return ['at' => $at, 'checked' => count($names), 'conditions' => $rowCount, 'manifests' => $names];
    }

    /**
     * The five compared facts of one condition row, normalized.
     *
     * @param array<string,mixed> $condition
     * @return array<string,mixed>
     */
    private static function conditionFacts(array $condition): array {
        return [
            'check' => (string) ($condition['check'] ?? ''),
            'code' => (string) ($condition['code'] ?? ''),
            'observed' => (string) ($condition['observed'] ?? ''),
            'satisfied' => (bool) ($condition['satisfied'] ?? false),
            'subject' => (string) ($condition['subject'] ?? ''),
        ];
    }

    /** `sha256:` over the canonical per-manifest condition vector. */
    private static function conditionsDigest(array $capabilities): string {
        return 'sha256:' . hash('sha256', Canon::encode(self::conditionVector($capabilities)));
    }

    /**
     * One manifest's condition rows, keyed by the identity the gate compares
     * on — `<code>\0<subject>`. A row with no subject names nothing to
     * re-probe and refuses here rather than being compared as if it had.
     *
     * @param array<int,mixed> $conditions
     * @return array<string,array<string,mixed>>
     */
    private static function conditionIndex(array $conditions, string $manifest): array {
        $index = [];
        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                // A prose condition: a plan frozen before conditions carried
                // machine facts, or a report from an agent that does not emit
                // them. Either way there is nothing to re-observe.
                throw self::conditionRefusal(
                    'release_condition_uncheckable',
                    'a condition carries prose only, with no code or subject this build can re-observe',
                    [['manifest' => $manifest, 'state' => 'not_machine_checkable']]
                );
            }
            $facts = self::conditionFacts($condition);
            if ($facts['subject'] === '' || $facts['code'] === '') {
                throw self::conditionRefusal(
                    'release_condition_uncheckable',
                    'a condition names no subject to re-observe at the mutation gate',
                    [[
                        'code' => $facts['code'],
                        'manifest' => $manifest,
                        'state' => 'no_subject',
                        'subject' => $facts['subject'],
                    ]]
                );
            }
            $index[$facts['code'] . "\0" . $facts['subject']] = $facts;
        }

        return $index;
    }

    /**
     * @param array<string,mixed> $facts a conditionFacts() row
     */
    private static function conditionDrift(string $manifest, array $facts, string $state): CommandRefusalException {
        return self::conditionRefusal(
            'release_condition_changed',
            'a condition this release was authorized against no longer holds on the target, so that '
                . 'authorization no longer applies',
            // `code`, `subject`, `manifest`, `state` — and never the observed
            // value. reverify()'s rule, restated: this is a public envelope.
            [[
                'code' => (string) $facts['code'],
                'manifest' => $manifest,
                'state' => $state,
                'subject' => (string) $facts['subject'],
            ]]
        );
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     */
    private static function conditionRefusal(
        string $code,
        string $message,
        array $diagnostics
    ): CommandRefusalException {
        return new CommandRefusalException(
            $code,
            $message,
            'nothing was written. Re-run wprism assess to re-observe the target, then wprism release to freeze a '
                . 'fresh authorization plan against the conditions that hold now. Do not retry this release: it '
                . 'was authorized against a condition the target no longer reports.',
            $diagnostics
        );
    }

    /**
     * Every reason this release must refuse BEFORE freezing a plan.
     *
     * Pure, and returns specs rather than raising: the verb boundary owns the
     * refusal channel (`CommandOutput::renderRefusalJson()` for the host,
     * `CommandRefusalException` for the agent), and an offline suite must be
     * able to enumerate the whole gate without a target.
     *
     * The order is the order MUP §2.3 lists them, and a caller raises the
     * first: readiness first because it is the assessment fact everything
     * else depends on, containment second because it is §1.6's consequence,
     * deletion authority last because it is a flag, not a site fact.
     *
     * @param array<string,mixed> $inputs the same closed set build() takes
     * @return list<array{reason_code:string,message:string,remediation:string,gap_action:?string,diagnostics:list<array<string,mixed>>}>
     */
    public static function refusals(array $inputs): array {
        $inputs = self::normalizeInputs($inputs);
        $refusals = [];

        foreach ($inputs['projection'] as $row) {
            $id = (string) ($row['id'] ?? '');
            $operation = self::releaseOperation($row);
            if ($operation === null) {
                continue;
            }
            $readiness = (string) ($operation['readiness'] ?? '');
            if (self::isPreservedLocal($operation)) {
                // Not in this release's scope: WPrism neither copies nor writes
                // a preserve-local surface, so its `Unsupported` (spec's
                // orders row) is the boundary the release respects, not a
                // gap the release must refuse over.
                continue;
            }
            if (in_array($readiness, self::BLOCKING_READINESS, true)) {
                $refusals[] = self::refusalSpec(
                    'release_surface_not_releasable',
                    "a surface in scope is $readiness for release",
                    (string) ($operation['remediation'] ?? '')
                        ?: 'resolve the surface gap this assessment names, then re-run wprism assess and wprism release',
                    self::gapActionFor($row, $operation),
                    [['surface' => $id, 'readiness' => $readiness]]
                );
            }
        }

        foreach ($inputs['projection'] as $row) {
            $id = (string) ($row['id'] ?? '');
            $operation = self::releaseOperation($row);
            if ($operation === null) {
                continue;
            }
            if (self::isPreservedLocal($operation)) {
                continue;
            }
            if ((string) ($operation['effect_recovery_semantics'] ?? '') === self::BLOCKING_RECOVERY_SEMANTICS) {
                $refusals[] = self::refusalSpec(
                    'release_recovery_semantics_unknown',
                    'a surface this release mutates has unknown effect recovery semantics',
                    'declare the effect and its recovery semantics in the application contract, or exclude the '
                        . 'surface from this release',
                    'declare in contract',
                    [['surface' => $id]]
                );
            }
        }

        $lifecycle = self::lifecyclePhases($inputs['scope']);
        if (array_intersect($lifecycle, self::CODE_LIFECYCLE_PHASES) !== []
            && self::lifecycleWindow($inputs['contract'], $lifecycle) === null) {
            // MUP §1.6's consequence, stated as a gate. The declaration
            // contains nothing; it converts an unknown into a known, bounded
            // live effect, which is the only form the spec permits to
            // proceed — and then only with the frozen plan's own authority.
            $refusals[] = self::refusalSpec(
                'release_live_effect_undeclared',
                'this release enters the code lifecycle window, where WordPress hooks fire, and the application '
                    . 'contract declares no reviewed live external effect for that window',
                'add an external_effects[] entry to the contract naming the ' . self::LIFECYCLE_WINDOW_SURFACE
                    . ' surfaces with containment "live", an explicit effect_recovery_semantics value and a '
                    . 'reviewed reason, then accept the proposal and re-run wprism release',
                'declare in contract',
                [['lifecycle_phases' => array_values(array_intersect($lifecycle, self::CODE_LIFECYCLE_PHASES))]]
            );
        }

        $deletes = count($inputs['plan']['delete']) + count($inputs['plan']['delete_conflict']);
        if ($deletes > 0 && ($inputs['flags']['with_deletes'] ?? false) !== true) {
            $refusals[] = self::refusalSpec(
                'release_deletes_not_authorized',
                'this release plan deletes owned entities and --with-deletes was not given',
                're-run wprism release --with-deletes once the deletions in the plan are the deletions you intend',
                // Deliberately no gap action: this is an authorization flag,
                // not an assessment gap, and §2.1's set is not a place to
                // put "type another flag".
                null,
                [['deletions' => $deletes]]
            );
        }
        if ($deletes > 0) {
            foreach (self::unsupportedDeleteSurfaces($inputs) as $surface) {
                $refusals[] = self::refusalSpec(
                    'release_delete_unsupported',
                    'this release deletes on a surface whose deletion semantics are declared unsupported',
                    'exclude the surface from this release, or remove the deletion from the plan; the stated '
                        . 'reason is not a defect to repair',
                    'exclude',
                    [['surface' => $surface]]
                );
            }
        }

        return $refusals;
    }

    /**
     * @param array<string,mixed> $inputs
     * @return array<string,mixed>
     */
    private static function normalizeInputs(array $inputs): array {
        $unknown = array_diff(array_keys($inputs), self::INPUT_KEYS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'authorization plan inputs carry unknown key(s): ' . implode(', ', $unknown)
            );
        }
        foreach (['environment', 'frozen_at'] as $key) {
            if (!is_string($inputs[$key] ?? null) || ($inputs[$key] ?? '') === '') {
                throw new \InvalidArgumentException("authorization plan inputs need a non-empty $key");
            }
        }
        $plan = PlanContract::requireComplete($inputs['plan'] ?? null, 'wprism release authorization plan');
        $contract = $inputs['contract'] ?? null;
        if ($contract !== null && !is_array($contract)) {
            throw new \InvalidArgumentException('authorization plan contract must be an array or null');
        }
        $recovery = $inputs['recovery'] ?? null;
        if (!is_array($recovery) || !is_array($recovery['claim'] ?? null)
            || !is_string($recovery['selected'] ?? null)
            || !is_string($recovery['selected_because'] ?? null)) {
            throw new \InvalidArgumentException(
                'authorization plan recovery must be {selected, selected_because, claim}'
            );
        }
        $checkpointAt = $recovery['checkpoint_at'] ?? null;
        if ($checkpointAt !== null && (!is_string($checkpointAt) || $checkpointAt === '')) {
            throw new \InvalidArgumentException(
                'authorization plan recovery checkpoint_at must be a non-empty string or null'
            );
        }

        return [
            'authority' => self::rowList($inputs['authority'] ?? [], 'authority'),
            'capabilities' => self::rowList($inputs['capabilities'] ?? [], 'capabilities'),
            'contract' => $contract,
            'deletion_semantics' => is_array($inputs['deletion_semantics'] ?? null)
                ? $inputs['deletion_semantics']
                : [],
            'environment' => (string) $inputs['environment'],
            'flags' => is_array($inputs['flags'] ?? null) ? $inputs['flags'] : [],
            'frozen_at' => (string) $inputs['frozen_at'],
            'plan' => $plan,
            'projection' => self::rowList($inputs['projection'] ?? [], 'projection'),
            'recovery' => $recovery,
            'scope' => is_array($inputs['scope'] ?? null) ? $inputs['scope'] : [],
            'target' => is_array($inputs['target'] ?? null) ? $inputs['target'] : [],
        ];
    }

    /**
     * The `release` operation projection for one surface row, or null when
     * the row does not project `release` at all.
     *
     * @param array<string,mixed> $row
     * @return ?array<string,mixed>
     */
    /**
     * A surface whose release handling is `preserve local` is outside the
     * release's mutation scope by definition — the target keeps its own copy
     * and the plan never reads or writes it — so none of the per-surface
     * gates apply to it. Only the literal handling word counts; a missing or
     * different word keeps the surface in scope.
     *
     * @param array<string,mixed> $operation
     */
    private static function isPreservedLocal(array $operation): bool {
        return (string) ($operation['handling'] ?? '') === 'preserve local';
    }

    private static function releaseOperation(array $row): ?array {
        $operations = $row['operations'] ?? null;
        if (!is_array($operations) || !is_array($operations['release'] ?? null)) {
            return null;
        }

        return $operations['release'];
    }

    /**
     * The §2.1 gap action for a blocked surface.
     *
     * `ContractProjection::generate()` already stores
     * `ProjectionVocabulary::gapAction()`'s answer on every operation block,
     * so the projection's own word is used verbatim — a release refusal must
     * name the action `wprism assess` printed for that row, or an operator
     * would be told two different smallest-safe-next-actions for one fact.
     * The recomputation below covers a row assembled by something other than
     * `ContractProjection` (state class lives at row level, so it is folded
     * back in), and the last resort stays inside the same closed set.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $operation
     */
    private static function gapActionFor(array $row, array $operation): string {
        $stored = $operation['gap_action'] ?? null;
        if (is_string($stored) && in_array($stored, ProjectionVocabulary::GAP_ACTIONS, true)) {
            return $stored;
        }
        try {
            $action = ProjectionVocabulary::gapAction(
                $operation + ['state_class' => $row['state_class'] ?? '']
            );
            if (in_array($action, ProjectionVocabulary::GAP_ACTIONS, true)) {
                return $action;
            }
        } catch (\Throwable $unreadable) {
            // A projection row gapAction() cannot read is still a blocked
            // surface; falling through gives the operator an action from the
            // closed set rather than no action at all.
            unset($unreadable);
        }

        // T6 §3.6 retires `qualify in rehearsal` from the emitted set, and
        // this catch-all was its last emitter. `classify` is the honest
        // replacement for exactly the case that reaches here: a projection
        // row too malformed to read is a surface nobody has established
        // anything about, which is what `classify` means — and unlike
        // rehearsal, it is a command that can move the row.
        return 'classify';
    }

    /**
     * Surfaces in scope whose deletion semantics are declared unsupported —
     * from the contract's `declarations.unsupported[]` (operation `delete`)
     * and from the registry's own `deletion_semantics.unsupported` list.
     *
     * @param array<string,mixed> $inputs
     * @return list<string>
     */
    private static function unsupportedDeleteSurfaces(array $inputs): array {
        $scope = self::stringList($inputs['scope']['surfaces'] ?? [], 'scope.surfaces');
        $named = [];
        $contract = $inputs['contract'];
        if (is_array($contract) && is_array($contract['declarations']['unsupported'] ?? null)) {
            foreach ($contract['declarations']['unsupported'] as $row) {
                if (is_array($row) && ($row['operation'] ?? null) === 'delete') {
                    $named[] = (string) ($row['surface'] ?? '');
                }
            }
        }
        $registry = $inputs['deletion_semantics']['unsupported'] ?? [];
        if (is_array($registry)) {
            foreach ($registry as $surface) {
                $named[] = (string) $surface;
            }
        }

        return array_values(array_unique(array_intersect($named, $scope)));
    }

    /**
     * @param array<string,mixed> $scope
     * @return list<string>
     */
    private static function lifecyclePhases(array $scope): array {
        $phases = $scope['code']['lifecycle_phases'] ?? [];
        if (!is_array($phases) || !array_is_list($phases)) {
            throw new \InvalidArgumentException('scope.code.lifecycle_phases must be a list');
        }
        $out = [];
        foreach ($phases as $phase) {
            if (!is_string($phase) || !in_array($phase, self::LIFECYCLE_PHASES, true)) {
                throw new \InvalidArgumentException(
                    'scope.code.lifecycle_phases must be drawn from ' . implode(', ', self::LIFECYCLE_PHASES)
                );
            }
            $out[] = $phase;
        }

        return $out;
    }

    /**
     * The contract's reviewed declaration for the code lifecycle window, or
     * null when there is none.
     *
     * All five conditions matter, and each is one of MUP §1.6's words: the
     * entry must name the lifecycle window surface, declare containment
     * `live`, state a recovery-semantics value that is not itself `unknown`,
     * carry a non-empty reason, and be decided by a human rather than left at
     * the generator's `unresolved` placeholder.
     *
     * @param ?array<string,mixed> $contract
     * @param list<string> $lifecycle
     * @return ?array<string,mixed>
     */
    private static function lifecycleWindow(?array $contract, array $lifecycle): ?array {
        if ($contract === null || array_intersect($lifecycle, self::CODE_LIFECYCLE_PHASES) === []) {
            return null;
        }
        $effects = $contract['declarations']['external_effects'] ?? null;
        if (!is_array($effects)) {
            return null;
        }
        foreach ($effects as $index => $effect) {
            if (!is_array($effect)) {
                continue;
            }
            $surfaces = $effect['surfaces'] ?? [];
            if (!is_array($surfaces) || !in_array(self::LIFECYCLE_WINDOW_SURFACE, $surfaces, true)) {
                continue;
            }
            if (($effect['containment'] ?? null) !== 'live') {
                continue;
            }
            $semantics = (string) ($effect['effect_recovery_semantics'] ?? '');
            if ($semantics === '' || $semantics === self::BLOCKING_RECOVERY_SEMANTICS) {
                continue;
            }
            if ((string) ($effect['reason'] ?? '') === ''
                || ($effect['decided_by'] ?? null) === 'unresolved') {
                continue;
            }

            return [
                'containment' => 'live',
                'containment_basis' => ProjectionVocabulary::CONTAINMENT_BASIS_UNKNOWN,
                'declared_in' => "contract.declarations.external_effects[$index]",
                'effect_recovery_semantics' => $semantics,
                'restored_by' => is_string($effect['restored_by'] ?? null) ? $effect['restored_by'] : null,
                'reviewed_reason' => (string) $effect['reason'],
            ];
        }

        return null;
    }

    /**
     * §2.3.1's `effects` block.
     *
     * @param array<string,mixed> $inputs
     * @param ?array<string,mixed> $window
     * @return array<string,mixed>
     */
    private static function effects(array $inputs, ?array $window): array {
        $containment = 'prevented';
        $irreversible = [];
        $unknown = [];
        foreach ($inputs['projection'] as $row) {
            $operation = self::releaseOperation($row);
            if ($operation === null) {
                continue;
            }
            $rowContainment = (string) ($operation['effect_containment'] ?? '');
            if ($rowContainment === 'live') {
                $containment = 'live';
            } elseif ($rowContainment === 'unknown' && $containment !== 'live') {
                $containment = 'unknown';
            }
            $semantics = (string) ($operation['effect_recovery_semantics'] ?? '');
            if ($semantics === 'irreversible') {
                $irreversible[] = [
                    'reason' => (string) ($operation['remediation'] ?? 'no restore coverage exists for this surface'),
                    'surface' => (string) ($row['id'] ?? ''),
                ];
            } elseif ($semantics === self::BLOCKING_RECOVERY_SEMANTICS) {
                $unknown[] = [
                    'reason' => ProjectionVocabulary::ANNOTATION_CONTAINMENT_BLOCK,
                    'surface' => (string) ($row['id'] ?? ''),
                ];
            }
        }
        if ($window !== null) {
            $containment = 'live';
        }

        return [
            'containment' => $containment,
            'containment_basis' => $containment === 'prevented'
                ? ProjectionVocabulary::CONTAINMENT_BASIS_PREVENTED
                : ProjectionVocabulary::CONTAINMENT_BASIS_UNKNOWN,
            'known_irreversible' => $irreversible,
            'lifecycle_window' => $window,
            'unknown_blocking' => $unknown,
        ];
    }

    /**
     * §2.3.1's `may_change` block: what code, authored state,
     * runtime-adjacent state and external systems may change.
     *
     * `authored_state` and `external` come from documents the operator
     * reviewed (the scope surfaces and the contract's declarations);
     * `code` and `runtime_adjacent` come from the plan's OWN value-free
     * `category_summary` projection (`\WPrism\PlanCategorySummary`, validated
     * here through `PlanContract::validCategorySummary()`), so this class
     * reads counts and closed identifiers and never a path, a UUID or a row
     * value. When a caller has real code paths it may pass them as
     * `scope.code.paths` and they are printed instead of the counts.
     *
     * @param array<string,mixed> $inputs
     * @return array<string,mixed>
     */
    private static function mayChange(array $inputs): array {
        $scope = $inputs['scope'];
        $code = [];
        $paths = $scope['code']['paths'] ?? [];
        if (is_array($paths) && $paths !== []) {
            $code = self::stringList($paths, 'scope.code.paths');
        } else {
            $plugins = (int) ($scope['code']['plugins_changed'] ?? 0);
            $themes = (int) ($scope['code']['themes_changed'] ?? 0);
            if ($plugins > 0) {
                $code[] = "$plugins plugin" . ($plugins === 1 ? '' : 's');
            }
            if ($themes > 0) {
                $code[] = "$themes theme" . ($themes === 1 ? '' : 's');
            }
        }

        return [
            'authored_state' => self::stringList($scope['surfaces'] ?? [], 'scope.surfaces'),
            'code' => $code,
            'external' => self::externalRows($inputs),
            'runtime_adjacent' => self::runtimeAdjacent($inputs['plan']),
        ];
    }

    /**
     * Runtime-adjacent change, derived from the plan's category summary.
     *
     * The phrase table is fixed and the numbers are the summary's own
     * metrics: a renderer that invented a phrase per site would be a second
     * classification of derived state, and MUP §1 allows exactly one.
     *
     * @param array<string,mixed> $plan
     * @return list<string>
     */
    private static function runtimeAdjacent(array $plan): array {
        $summary = $plan['category_summary'] ?? null;
        if (!PlanContract::validCategorySummary($summary)) {
            // A plan emitted without the optional projection is still a
            // complete plan (PlanContract::optionalProjections()), so the
            // absence is disclosed rather than guessed at.
            return ['not projected: this plan carries no category summary'];
        }
        /** @var array<string,mixed> $summary */
        $rows = [];
        $phrases = [
            'generated_effects' => [
                'declared_rebuild_effects' => 'declared rebuild effects',
                'declared_regenerator_effects' => 'declared regenerator effects',
                'selected_native_actions' => 'native rebuild actions',
                'selected_provider_actions' => 'provider rebuild actions',
                'regen_pending' => 'pending regenerations',
            ],
            'environment_state' => [
                'state_drift' => 'environment-bound state drift',
                'code_drift' => 'environment-bound code drift',
                'required_env_missing' => 'required environment values not provisioned',
            ],
        ];
        $ids = ['code', 'lifecycle', 'authored_state', 'generated_effects', 'media',
            'secrets', 'environment_state', 'capabilities', 'deletions'];
        foreach ($phrases as $categoryId => $metrics) {
            $index = array_search($categoryId, $ids, true);
            if (!is_int($index) || !is_array($summary['categories'][$index] ?? null)) {
                continue;
            }
            $category = $summary['categories'][$index];
            foreach ($metrics as $metric => $label) {
                $count = $category['metrics'][$metric] ?? 0;
                if (is_int($count) && $count > 0) {
                    $rows[] = "$label ($count)";
                }
            }
        }

        return $rows;
    }

    /**
     * The declared external effects this release may reach.
     *
     * @param array<string,mixed> $inputs
     * @return list<array<string,mixed>>
     */
    private static function externalRows(array $inputs): array {
        $contract = $inputs['contract'];
        if (!is_array($contract) || !is_array($contract['declarations']['external_effects'] ?? null)) {
            return [];
        }
        $scope = self::stringList($inputs['scope']['surfaces'] ?? [], 'scope.surfaces');
        $lifecycle = self::lifecyclePhases($inputs['scope']);
        $inWindow = array_intersect($lifecycle, self::CODE_LIFECYCLE_PHASES) !== [];
        $rows = [];
        foreach ($contract['declarations']['external_effects'] as $effect) {
            if (!is_array($effect)) {
                continue;
            }
            $surfaces = is_array($effect['surfaces'] ?? null) ? $effect['surfaces'] : [];
            $touchesWindow = $inWindow && in_array(self::LIFECYCLE_WINDOW_SURFACE, $surfaces, true);
            if (!$touchesWindow && array_intersect(array_map('strval', $surfaces), $scope) === []) {
                continue;
            }
            $rows[] = [
                'containment' => (string) ($effect['containment'] ?? ''),
                'effect_recovery_semantics' => (string) ($effect['effect_recovery_semantics'] ?? ''),
                'id' => (string) ($effect['id'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * §2.3.1's `authority_still_required` rows.
     *
     * @param array<string,mixed> $inputs
     * @param ?array<string,mixed> $window
     * @return list<array<string,mixed>>
     */
    private static function authority(array $inputs, ?array $window): array {
        $plan = $inputs['plan'];
        $mutating = count($plan['create']) + count($plan['update']) + count($plan['delete'])
            + count($plan['code_mismatch']) + count($plan['code_drift']);
        $rows = [];
        if ($mutating > 0 || self::lifecyclePhases($inputs['scope']) !== []) {
            $rows[] = ['kind' => 'operator_confirmation', 'reason' => 'production-visible mutation'];
        }
        if ($window !== null) {
            $rows[] = [
                'kind' => 'declared_live_effect',
                'reason' => 'lifecycle window declared live in the contract; plan-bound authority required',
            ];
        }
        foreach ($inputs['authority'] as $row) {
            $kind = (string) ($row['kind'] ?? '');
            if (!in_array($kind, self::AUTHORITY_KINDS, true)) {
                throw new \InvalidArgumentException(
                    'authority_still_required kind must be one of ' . implode(', ', self::AUTHORITY_KINDS)
                );
            }
            $rows[] = ['kind' => $kind, 'reason' => (string) ($row['reason'] ?? '')];
        }

        return $rows;
    }

    /**
     * @param mixed $value
     * @return list<array<string,mixed>>
     */
    private static function rowList($value, string $label): array {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException("authorization plan $label must be a list");
        }
        $rows = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException("authorization plan $label rows must be objects");
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function stringList($value, string $label): array {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException("authorization plan $label must be a list");
        }
        $rows = [];
        foreach ($value as $row) {
            if (!is_string($row) || $row === '') {
                throw new \InvalidArgumentException("authorization plan $label rows must be non-empty strings");
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     * @return array{reason_code:string,message:string,remediation:string,gap_action:?string,diagnostics:list<array<string,mixed>>}
     */
    private static function refusalSpec(
        string $reasonCode,
        string $message,
        string $remediation,
        ?string $gapAction,
        array $diagnostics
    ): array {
        if ($gapAction !== null && !in_array($gapAction, ProjectionVocabulary::GAP_ACTIONS, true)) {
            throw new \LogicException("pre-authorization refusal used a gap action outside the closed set: $gapAction");
        }

        return [
            'diagnostics' => $diagnostics,
            'gap_action' => $gapAction,
            'message' => $message,
            'reason_code' => $reasonCode,
            'remediation' => $remediation,
        ];
    }

    private static function refuse(
        string $code,
        string $message,
        string $remediation = 'run wprism release --plan-only to produce a fresh authorization plan and review it'
    ): CommandRefusalException {
        return new CommandRefusalException($code, $message, $remediation);
    }
}

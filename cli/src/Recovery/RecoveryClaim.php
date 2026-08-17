<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';

use Duo\Canon;
use Duo\CommandRefusalException;

/**
 * The literal recovery claim — `duo-recovery-claim/v1` (round-3 MUP §2.3.1,
 * §2.5; product spec safety invariant 10, *Recovery claims are literal*).
 *
 * The spec's sentence is the whole design: *"Rollback names exactly which
 * resources and effects it restores. It never implies that emails, payments,
 * webhooks, or other external reality were undone."* Two structural
 * consequences follow, and both are asserted here rather than documented:
 *
 *  1. **`does_not_restore` is non-empty for EVERY profile, `verified-automatic`
 *     included.** A profile that proved every rollback capability still cannot
 *     un-send a mail or un-capture a payment, because those left this system.
 *     `UNIVERSAL_DOES_NOT_RESTORE` is therefore a floor, not a fallback: it is
 *     merged into every claim before any per-profile row is added, and
 *     build() refuses to emit a claim whose `does_not_restore` came out empty.
 *     A claim that says "everything is restored" is the failure this class
 *     exists to make impossible.
 *
 *  2. **One claim, two printings, identical bytes.** MUP §2.5 requires that
 *     "the claim the operator saw at authorization is the claim they see at
 *     recovery". The claim is therefore a canonical array with its own
 *     digest, embedded verbatim in the frozen authorization plan
 *     (`recovery_profile.claim`) and re-printed verbatim by `duo recover`.
 *     Nothing downstream re-derives it: `AuthorizationPlan` takes it as an
 *     array and never references this class, which is also what keeps the
 *     Release module free of a Recovery edge (module map rule 9).
 *
 * ## Why the contract's declarations reach this class as plain arrays
 *
 * `cli:Recovery` may depend only on `Recovery`, `Transport` and
 * `agent:Kernel` (docs/modules/cli-Recovery.md). MUP §3.4 nevertheless says
 * "`duo recover` reads `external_effects[]` for its does-not-restore list",
 * so the caller — the verb boundary in `cli/src/Command/` — reads the
 * contract and hands the rows in as `declared_external_effects`. That is the
 * same composition rule every other cross-module document already follows,
 * and it means this class holds no contract-format knowledge beyond the four
 * keys it actually reads.
 *
 * ## What `restores` means, exactly
 *
 * The four named resources are the covered inventory the shipped profiles
 * actually operate on: `VerifiedRollbackProfile::rollback()` restores effects,
 * uploads, the code release and the database checkpoint
 * (cli/src/Recovery/VerifiedRollbackProfile.php), while
 * `ScopedRollbackProfile` is deliberately checkpoint-only — "it never
 * prepares/releases code, uploads, lifecycle, or effect providers" (that
 * class's own docblock). The operator-directed profile is the same
 * checkpoint-only guarantee driven by hand, so it restores the database
 * checkpoint and nothing else, and every other resource moves into
 * `does_not_restore` with the remedy stated on the row.
 */
final class RecoveryClaim {
    public const FORMAT = 'duo-recovery-claim/v1';

    /** Product spec, *Release and verify*: the closed recovery-profile enum. */
    public const VERIFIED_AUTOMATIC = 'verified-automatic';
    public const OPERATOR_DIRECTED = 'operator-directed';
    public const NONE = 'none';

    /** @var list<string> */
    public const PROFILES = [self::VERIFIED_AUTOMATIC, self::OPERATOR_DIRECTED, self::NONE];

    /** The named resources a profile can restore (MUP §1.6's covered inventory). */
    public const RESOURCE_DATABASE_CHECKPOINT = 'database checkpoint';
    public const RESOURCE_CODE_RELEASE = 'code release';
    public const RESOURCE_UPLOAD_BUNDLE = 'upload bundle';
    public const RESOURCE_EFFECT_BUNDLE = 'effect bundle';

    /** @var list<string> */
    public const RESOURCES = [
        self::RESOURCE_DATABASE_CHECKPOINT,
        self::RESOURCE_CODE_RELEASE,
        self::RESOURCE_UPLOAD_BUNDLE,
        self::RESOURCE_EFFECT_BUNDLE,
    ];

    /**
     * What each profile restores, in the order an operator reads them.
     *
     * @var array<string,list<string>>
     */
    public const RESTORES = [
        self::VERIFIED_AUTOMATIC => [
            self::RESOURCE_DATABASE_CHECKPOINT,
            self::RESOURCE_CODE_RELEASE,
            self::RESOURCE_UPLOAD_BUNDLE,
            self::RESOURCE_EFFECT_BUNDLE,
        ],
        self::OPERATOR_DIRECTED => [self::RESOURCE_DATABASE_CHECKPOINT],
        self::NONE => [],
    ];

    /**
     * The floor. True of every profile this platform can select, including
     * the strongest one, because each row names something that already left
     * this WordPress install.
     *
     * @var list<string>
     */
    public const UNIVERSAL_DOES_NOT_RESTORE = [
        'emails already sent',
        'payment captures or refunds already made',
        'webhooks already delivered',
        'third-party systems that observed the change',
        'orders and sessions written by live traffic after the checkpoint',
    ];

    /**
     * Rows a weaker profile adds on top of the floor, each stating the
     * manual remedy rather than only the absence.
     *
     * @var array<string,list<string>>
     */
    public const PROFILE_DOES_NOT_RESTORE = [
        self::VERIFIED_AUTOMATIC => [],
        self::OPERATOR_DIRECTED => [
            'code releases — reconcile code by hand to the pre-release revision before importing the checkpoint',
            'uploaded media bundles — the target keeps whatever the release wrote',
            'provider effect bundles — no inverse effect operation is authorized in this profile',
        ],
        self::NONE => [
            'database state — no checkpoint is taken, so nothing bounds the loss',
            'code releases — the target keeps the released revision',
            'uploaded media bundles — the target keeps whatever the release wrote',
            'provider effect bundles — no inverse effect operation is authorized in this profile',
        ],
    ];

    /**
     * Who holds the writer exclusion, per profile. `required` answers one
     * operational question only: must the OPERATOR assert exclusion (MUP
     * §2.5's `--writers-excluded`) before recovery starts?
     *
     * @var array<string,array{required:bool,mechanism:string}>
     */
    public const WRITER_EXCLUSION = [
        self::VERIFIED_AUTOMATIC => [
            'required' => false,
            'mechanism' => 'the rollback authority reserves and releases the exclusion for the window itself; '
                . 'the operator asserts nothing',
        ],
        self::OPERATOR_DIRECTED => [
            'required' => true,
            'mechanism' => 'an external maintenance window: exclude every other writer, then assert it with '
                . '--writers-excluded (a lock inside the database being imported cannot protect the window)',
        ],
        self::NONE => [
            'required' => false,
            'mechanism' => 'not applicable: nothing is restored, so no window protects anything',
        ],
    ];

    /** MUP §1.6's recovery-semantics word that means "this resource comes back". */
    public const SEMANTICS_RESTORABLE = 'provider-state restorable';

    /** Recovery semantics that need no does-not-restore row: no effect exists. */
    public const SEMANTICS_NOT_APPLICABLE = 'not applicable';

    /** Closed input key set for build(). */
    private const FACT_KEYS = [
        'additional_does_not_restore',
        'checkpoint_at',
        'covered_resources',
        'declared_external_effects',
        'profile',
    ];

    /**
     * Build the canonical claim.
     *
     * @param array<string,mixed> $facts closed keys:
     *   - `profile` (required) one of PROFILES;
     *   - `checkpoint_at` (?string) the canonical UTC second the checkpoint
     *     pins, or null when the release has not taken it yet — the boundary
     *     sentence changes, the claim never claims a time it does not have;
     *   - `covered_resources` (list<string>) the concrete inventory entries
     *     this target/plan covers ("code release e2f1a09", "upload bundle
     *     (12 entries)"). Distinct from `restores`, which names resource
     *     KINDS: `restores` is the guarantee, `covered_resources` is what the
     *     guarantee was measured against on this run;
     *   - `declared_external_effects` (list<array>) the contract's
     *     `declarations.external_effects[]` rows, handed in by the verb
     *     boundary (see the class docblock);
     *   - `additional_does_not_restore` (list<string>) caller-known rows, e.g.
     *     a surface the registry lists in `deletion_semantics.unsupported`.
     * @return array<string,mixed> the canonical claim, digest included
     */
    public static function build(array $facts): array {
        $unknown = array_diff(array_keys($facts), self::FACT_KEYS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'recovery claim facts carry unknown key(s): ' . implode(', ', $unknown)
            );
        }
        $profile = $facts['profile'] ?? null;
        if (!is_string($profile) || !in_array($profile, self::PROFILES, true)) {
            throw new \InvalidArgumentException(
                'recovery claim profile must be one of ' . implode(', ', self::PROFILES)
            );
        }
        $checkpointAt = $facts['checkpoint_at'] ?? null;
        if ($checkpointAt !== null && (!is_string($checkpointAt) || $checkpointAt === '')) {
            throw new \InvalidArgumentException('recovery claim checkpoint_at must be a non-empty string or null');
        }

        $covered = self::stringList($facts['covered_resources'] ?? [], 'covered_resources');
        $additional = self::stringList($facts['additional_does_not_restore'] ?? [], 'additional_does_not_restore');
        $effects = $facts['declared_external_effects'] ?? [];
        if (!is_array($effects) || !array_is_list($effects)) {
            throw new \InvalidArgumentException('recovery claim declared_external_effects must be a list');
        }

        $restores = self::RESTORES[$profile];
        $doesNotRestore = self::UNIVERSAL_DOES_NOT_RESTORE;
        foreach (self::PROFILE_DOES_NOT_RESTORE[$profile] as $row) {
            $doesNotRestore[] = $row;
        }
        foreach (self::externalEffectRows($effects, $profile, $restores) as $row) {
            $doesNotRestore[] = $row;
        }
        foreach ($additional as $row) {
            $doesNotRestore[] = $row;
        }
        // array_unique preserves first-seen order, which is the reading
        // order chosen above: the universal floor first, then what this
        // profile specifically gives up, then the site's own declarations.
        $doesNotRestore = array_values(array_unique($doesNotRestore));
        if ($doesNotRestore === []) {
            // Unreachable while UNIVERSAL_DOES_NOT_RESTORE is non-empty, and
            // that is exactly why the assertion is here: a future edit that
            // empties the floor must fail loudly rather than start emitting
            // a claim that implies external reality was undone.
            throw new \LogicException('recovery claim does_not_restore must never be empty');
        }

        $claim = [
            'covered_resources' => $covered,
            'does_not_restore' => $doesNotRestore,
            'format' => self::FORMAT,
            'maximum_loss_boundary' => self::lossBoundary($profile, $checkpointAt),
            'profile' => $profile,
            'restores' => $restores,
            'writer_exclusion' => self::WRITER_EXCLUSION[$profile],
        ];
        $claim['claim_digest'] = self::digest($claim);

        return $claim;
    }

    /**
     * `sha256:` + the digest of the canonical encoding of everything except
     * the digest itself — the same self-exclusion rule
     * `ApplicationContract::digest()` uses, so the two documents are read the
     * same way.
     *
     * @param array<string,mixed> $claim
     */
    public static function digest(array $claim): string {
        unset($claim['claim_digest']);

        return 'sha256:' . hash('sha256', Canon::encode($claim));
    }

    /** Canonical bytes: what the plan embeds and what `duo recover` re-prints. */
    public static function encode(array $claim): string {
        return Canon::encode($claim);
    }

    /**
     * Refuse a claim that is not a literal one.
     *
     * Used at both printings — the plan freeze and the recovery run — so a
     * hand-edited frozen plan cannot smuggle an empty `does_not_restore`
     * past `duo recover`.
     *
     * @param array<string,mixed> $claim
     */
    public static function validate(array $claim): void {
        if (($claim['format'] ?? null) !== self::FORMAT) {
            throw self::refuse('recovery_claim_format_invalid', 'the recovery claim is not a ' . self::FORMAT);
        }
        $profile = $claim['profile'] ?? null;
        if (!is_string($profile) || !in_array($profile, self::PROFILES, true)) {
            throw self::refuse('recovery_claim_profile_invalid', 'the recovery claim names no known recovery profile');
        }
        foreach (['covered_resources', 'does_not_restore', 'restores'] as $key) {
            $value = $claim[$key] ?? null;
            if (!is_array($value) || !array_is_list($value)) {
                throw self::refuse('recovery_claim_shape_invalid', "the recovery claim's $key is not a list");
            }
            foreach ($value as $row) {
                if (!is_string($row) || $row === '') {
                    throw self::refuse('recovery_claim_shape_invalid', "the recovery claim's $key has an empty row");
                }
            }
        }
        if ($claim['does_not_restore'] === []) {
            throw self::refuse(
                'recovery_claim_not_literal',
                'the recovery claim lists nothing it does not restore, which no profile can honestly say'
            );
        }
        if ($claim['restores'] !== self::RESTORES[$profile]) {
            throw self::refuse(
                'recovery_claim_not_literal',
                'the recovery claim restores a resource set the named profile does not operate on'
            );
        }
        $exclusion = $claim['writer_exclusion'] ?? null;
        if (!is_array($exclusion) || !is_bool($exclusion['required'] ?? null)
            || !is_string($exclusion['mechanism'] ?? null) || ($exclusion['mechanism'] ?? '') === '') {
            throw self::refuse(
                'recovery_claim_shape_invalid',
                "the recovery claim's writer_exclusion is not {required, mechanism}"
            );
        }
        if (!is_string($claim['maximum_loss_boundary'] ?? null) || ($claim['maximum_loss_boundary'] ?? '') === '') {
            throw self::refuse(
                'recovery_claim_shape_invalid',
                'the recovery claim states no maximum loss boundary'
            );
        }
        $stated = $claim['claim_digest'] ?? null;
        if (!is_string($stated) || preg_match('/^sha256:[a-f0-9]{64}$/D', $stated) !== 1) {
            throw self::refuse('recovery_claim_shape_invalid', 'the recovery claim carries no sha256: digest');
        }
        if (!hash_equals(self::digest($claim), $stated)) {
            throw self::refuse(
                'recovery_claim_digest_mismatch',
                'the recovery claim digest does not match its own content'
            );
        }
    }

    /**
     * The verbatim block printed before authorization and again before
     * recovery. Bounded per MUP §4.6.
     *
     * @param array<string,mixed> $claim
     * @return list<string>
     */
    public static function humanLines(array $claim, int $limit = 50): array {
        if ($limit < 1) {
            throw new \InvalidArgumentException('recovery claim listing limit must be at least 1');
        }
        $lines = ['recovery profile: ' . (string) $claim['profile']];
        $lines[] = '  restores:';
        foreach (self::bounded(self::rows($claim, 'restores'), $limit) as $line) {
            $lines[] = '    ' . $line;
        }
        if (self::rows($claim, 'restores') === []) {
            $lines[] = '    nothing';
        }
        $lines[] = '  does NOT restore:';
        foreach (self::bounded(self::rows($claim, 'does_not_restore'), $limit) as $line) {
            $lines[] = '    ' . $line;
        }
        if (self::rows($claim, 'covered_resources') !== []) {
            $lines[] = '  covered on this target:';
            foreach (self::bounded(self::rows($claim, 'covered_resources'), $limit) as $line) {
                $lines[] = '    ' . $line;
            }
        }
        $exclusion = is_array($claim['writer_exclusion'] ?? null) ? $claim['writer_exclusion'] : [];
        $lines[] = '  writer exclusion: '
            . (($exclusion['required'] ?? false) === true ? 'required — ' : 'not asserted by the operator — ')
            . (string) ($exclusion['mechanism'] ?? '');
        $lines[] = '  maximum loss boundary: ' . (string) ($claim['maximum_loss_boundary'] ?? '');

        return $lines;
    }

    /**
     * @param array<string,mixed> $claim
     * @return list<string>
     */
    private static function rows(array $claim, string $key): array {
        $value = $claim[$key] ?? [];

        return is_array($value) && array_is_list($value) ? array_values(array_map('strval', $value)) : [];
    }

    /**
     * MUP §4.6: never print an unbounded list, and never let the tail line
     * imply the list was complete.
     *
     * @param list<string> $rows
     * @return list<string>
     */
    private static function bounded(array $rows, int $limit): array {
        if (count($rows) <= $limit) {
            return $rows;
        }
        $shown = array_slice($rows, 0, $limit);
        $shown[] = (count($rows) - $limit) . ' more (use --format=json)';

        return $shown;
    }

    /**
     * Rows a site's own declarations add to `does_not_restore`.
     *
     * A declared effect is covered only when the contract says it is
     * `provider-state restorable` AND names a `restored_by` resource this
     * profile actually restores. Everything else is named, including the
     * common trap: an effect the contract calls restorable by "code release"
     * is NOT restored by the operator-directed profile, which restores only
     * the database checkpoint.
     *
     * @param list<mixed> $effects
     * @param list<string> $restores
     * @return list<string>
     */
    private static function externalEffectRows(array $effects, string $profile, array $restores): array {
        $rows = [];
        foreach ($effects as $effect) {
            if (!is_array($effect)) {
                throw new \InvalidArgumentException('recovery claim declared_external_effects rows must be objects');
            }
            $id = (string) ($effect['id'] ?? '');
            if ($id === '') {
                throw new \InvalidArgumentException('recovery claim declared_external_effects row has no id');
            }
            $semantics = (string) ($effect['effect_recovery_semantics'] ?? '');
            if ($semantics === self::SEMANTICS_NOT_APPLICABLE) {
                continue;
            }
            if ($semantics !== self::SEMANTICS_RESTORABLE) {
                $rows[] = "declared external effect \"$id\" — recovery semantics: "
                    . ($semantics === '' ? 'undeclared' : $semantics);
                continue;
            }
            $restoredBy = (string) ($effect['restored_by'] ?? '');
            if ($restoredBy !== '' && in_array($restoredBy, $restores, true)) {
                continue;
            }
            $rows[] = "declared external effect \"$id\" — restored by "
                . ($restoredBy === '' ? 'an unnamed resource' : "\"$restoredBy\"")
                . ", which the $profile profile does not restore";
        }

        return $rows;
    }

    private static function lossBoundary(string $profile, ?string $checkpointAt): string {
        if ($profile === self::NONE) {
            return 'everything this release writes: no checkpoint is taken, so nothing bounds the loss';
        }
        if ($checkpointAt === null) {
            return 'writes committed after the checkpoint this release takes immediately before mutation';
        }

        return "writes committed after checkpoint $checkpointAt";
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function stringList($value, string $label): array {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException("recovery claim $label must be a list");
        }
        $rows = [];
        foreach ($value as $row) {
            if (!is_string($row) || $row === '') {
                throw new \InvalidArgumentException("recovery claim $label rows must be non-empty strings");
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private static function refuse(string $code, string $message): CommandRefusalException {
        return new CommandRefusalException(
            $code,
            $message,
            'do not act on this claim; re-run duo release --plan-only to regenerate it from the current target'
        );
    }
}

<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/RecoveryClaim.php';
require_once __DIR__ . '/ScopedRollbackProfile.php';
require_once __DIR__ . '/VerifiedRollbackProfile.php';

/**
 * Which recovery profile this release runs under, and why — round-3 MUP
 * §2.3 ("`--profile` may only *strengthen* silently") over DUO-3310's
 * existing auto-selection.
 *
 * ## This class decides nothing new
 *
 * The capability decision already exists and is already certified:
 * `VerifiedRollbackProfile::select()` returns `automatic: true` only when the
 * target proves the rollback signing key, the recovery executor and the
 * checkpoint, code-release, upload and effect providers, plus the
 * `verified_rollback` policy, a compiled code release identity and canonical
 * upload/effect inventories; `ScopedRollbackProfile::select()` is the
 * checkpoint-only sibling for a scoped window. `cli/duo`'s promotion state
 * machine consumes exactly that boolean today — verified when it is true, a
 * `WARN automatic verified rollback unavailable (<reason>); using
 * operator-directed checkpoint/recovery` line when it is false
 * (cli/duo, the `promote` arm). This class does three things that decision
 * does not do, and nothing else:
 *
 *  1. **Names the profile in the product's words.** `automatic: true|false`
 *     becomes `verified-automatic | operator-directed`, the enum the product
 *     spec's *Release and verify* section requires a plan to declare.
 *  2. **Applies the operator's `--profile` request under the strengthen-only
 *     rule.** A weaker profile — `none` included — is a refusal spec unless
 *     `--accept-weaker-recovery` is present, because the spec makes that a
 *     named human authority rather than a flag with a default.
 *  3. **Produces the claim.** `RecoveryClaim::build()` is called here, once,
 *     so the claim that reaches the authorization plan and the claim `duo
 *     recover` re-prints are the same bytes.
 *
 * ## Why the decision is split into prove/decide
 *
 * `proveVerified()` needs an `SshTransport` and (unless the caller already
 * read one) a live authority status; `decide()` is pure. Splitting them is
 * what makes the strengthen-only rule, the warning line and the claim
 * testable offline against a fixture proof, without a target and without a
 * fake transport pretending to be a signing key. It also means the verb
 * boundary can read the authority status once and hand the same array to
 * both the selection and the checkpoint catalog.
 *
 * ## `none`
 *
 * `none` is never *provable* — it is only ever *requested*. There is no
 * target state that makes "no recovery" the right default, so it can only
 * enter through `--profile=none` plus the explicit weaker-recovery flag.
 */
final class RecoveryProfileSelection {
    public const FORMAT = 'duo-recovery-profile-selection/v1';

    /** @var list<string> */
    public const PROFILES = RecoveryClaim::PROFILES;

    /** MUP §2.3's named human authority for a weaker-than-provable profile. */
    public const WEAKER_FLAG = '--accept-weaker-recovery';

    /**
     * Strength order. `--profile` may only strengthen silently, so a request
     * is compared against what the target proved, never against a policy
     * constant.
     *
     * @var array<string,int>
     */
    public const STRENGTH = [
        RecoveryClaim::VERIFIED_AUTOMATIC => 2,
        RecoveryClaim::OPERATOR_DIRECTED => 1,
        RecoveryClaim::NONE => 0,
    ];

    /**
     * The operator warning DUO-3310's fallback already prints, kept
     * word-for-word so an operator who has seen `duo promote` reads the same
     * sentence in `duo release`. `cli/duo`'s own byte stream is untouched:
     * release composes promote, it does not fork it.
     */
    public const WARN_PREFIX = 'WARN automatic verified rollback unavailable (';
    public const WARN_SUFFIX = '); using operator-directed checkpoint/recovery';

    /** Closed input key set for decide()'s `$request`. */
    private const REQUEST_KEYS = [
        'accept_weaker_recovery',
        'additional_does_not_restore',
        'checkpoint_at',
        'covered_resources',
        'declared_external_effects',
        'requested_profile',
    ];

    /**
     * DUO-3310's decision for a full promotion, in product words.
     *
     * @param array<string,mixed> $plan the compiled plan summary
     *        `VerifiedRollbackProfile::select()` already consumes
     * @param ?array<string,mixed> $status a previously read authority status;
     *        passing it avoids a second remote read and is what lets an
     *        offline suite exercise the decision without a live target
     * @return array{profile:string,reason:string,automatic:bool,scoped:bool,status:array<string,mixed>}
     */
    public static function proveVerified(SshTransport $transport, array $plan, ?array $status = null): array {
        return self::fromSelect(VerifiedRollbackProfile::select($transport, $plan, $status), false);
    }

    /**
     * The checkpoint-only sibling for a scoped promotion window.
     *
     * A scoped window excludes code/lifecycle semantics by construction, so
     * even a successful scoped selection is `operator-directed` in product
     * terms: the covered inventory is the database checkpoint alone, which
     * is exactly what `RecoveryClaim::RESTORES[operator-directed]` says.
     * Reporting it as `verified-automatic` would claim a code and upload
     * restore that `ScopedRollbackProfile` deliberately never authorizes.
     *
     * @param array<string,mixed> $plan
     * @param ?array<string,mixed> $status
     * @return array{profile:string,reason:string,automatic:bool,scoped:bool,status:array<string,mixed>}
     */
    public static function proveScoped(
        SshTransport $transport,
        array $plan,
        string $scopeHash,
        bool $allowDeletes = false,
        ?array $status = null
    ): array {
        $selection = ScopedRollbackProfile::select($transport, $plan, $scopeHash, $allowDeletes, $status);
        $proof = self::fromSelect($selection, true);
        if ($proof['automatic']) {
            $proof['profile'] = RecoveryClaim::OPERATOR_DIRECTED;
        }

        return $proof;
    }

    /**
     * Apply the operator's request to the proof and build the claim. Pure.
     *
     * @param array{profile:string,reason:string,automatic:bool,scoped:bool,status:array<string,mixed>} $proof
     * @param array<string,mixed> $request closed keys: `requested_profile`
     *        (?string, the `--profile` value), `accept_weaker_recovery`
     *        (bool), plus the four `RecoveryClaim::build()` facts that are
     *        not the profile itself.
     * @return array<string,mixed> keys: format, provable, selected,
     *         selected_because, requested, weaker_than_provable, warning,
     *         refusal (?refusal spec), claim, claim_digest.
     */
    public static function decide(array $proof, array $request = []): array {
        $unknown = array_diff(array_keys($request), self::REQUEST_KEYS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'recovery profile request carries unknown key(s): ' . implode(', ', $unknown)
            );
        }
        $provable = $proof['profile'] ?? null;
        if (!is_string($provable) || !array_key_exists($provable, self::STRENGTH)) {
            throw new \InvalidArgumentException('recovery profile proof names no known profile');
        }
        $reason = (string) ($proof['reason'] ?? '');
        $requested = $request['requested_profile'] ?? null;
        if ($requested !== null && (!is_string($requested) || !in_array($requested, self::PROFILES, true))) {
            throw new \InvalidArgumentException(
                '--profile must be one of ' . implode(', ', self::PROFILES)
            );
        }
        $accepted = ($request['accept_weaker_recovery'] ?? false) === true;

        $selected = $provable;
        $weaker = false;
        $refusal = null;
        if ($requested !== null && $requested !== $provable) {
            if (self::STRENGTH[$requested] > self::STRENGTH[$provable]) {
                // Strengthening beyond what the target proved is not a
                // policy choice an operator may make: the capability is
                // simply absent, and pretending otherwise would put a
                // restore guarantee in the frozen plan that no provider
                // can honour.
                $refusal = self::refusal(
                    'recovery_profile_unprovable',
                    "the target cannot prove the requested $requested recovery profile",
                    'run duo release without --profile to use the profile the target proves, or configure the '
                        . 'missing recovery providers and re-run duo assess',
                    [['requested' => $requested, 'provable' => $provable, 'reason' => $reason]]
                );
            } elseif (!$accepted) {
                $refusal = self::refusal(
                    'recovery_profile_weaker_than_provable',
                    "the requested $requested recovery profile is weaker than the $provable profile this target proves",
                    'a weaker recovery profile is a named human authority: re-run with ' . self::WEAKER_FLAG
                        . ' and the typed acknowledgement, or drop --profile',
                    [['requested' => $requested, 'provable' => $provable]]
                );
            } else {
                $selected = $requested;
                $weaker = true;
            }
        }

        $claim = RecoveryClaim::build([
            'additional_does_not_restore' => $request['additional_does_not_restore'] ?? [],
            'checkpoint_at' => $request['checkpoint_at'] ?? null,
            'covered_resources' => $request['covered_resources'] ?? [],
            'declared_external_effects' => $request['declared_external_effects'] ?? [],
            'profile' => $selected,
        ]);

        $selection = [
            'claim' => $claim,
            'claim_digest' => (string) $claim['claim_digest'],
            'format' => self::FORMAT,
            'provable' => $provable,
            'refusal' => $refusal,
            'requested' => $requested,
            'selected' => $selected,
            'selected_because' => self::because($selected, $provable, $reason, $weaker),
            'weaker_than_provable' => $weaker,
        ];
        $selection['warning'] = self::warningLine($selection, $proof);

        return $selection;
    }

    /**
     * prove + decide in one call, for the common full-promotion path.
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public static function select(SshTransport $transport, array $plan, array $request = []): array {
        // A pre-read authority status belongs on proveVerified(), whose
        // whole point is to let the verb boundary read the target once;
        // decide()'s closed key set refuses it here rather than silently
        // performing a second remote read.
        return self::decide(self::proveVerified($transport, $plan), $request);
    }

    /**
     * The single warning line, or null when there is nothing to warn about.
     *
     * @param array<string,mixed> $selection
     * @param array<string,mixed> $proof
     */
    public static function warningLine(array $selection, array $proof = []): ?string {
        if (($selection['weaker_than_provable'] ?? false) === true) {
            return 'WARN releasing under the ' . (string) $selection['selected']
                . ' recovery profile, which is weaker than the '
                . (string) $selection['provable'] . ' profile this target proves ('
                . self::WEAKER_FLAG . ' was given)';
        }
        if (($selection['provable'] ?? null) === RecoveryClaim::VERIFIED_AUTOMATIC) {
            return null;
        }
        $reason = (string) ($proof['reason'] ?? '');
        if (($proof['scoped'] ?? false) === true) {
            // A scoped window is checkpoint-only by design, not by failure:
            // there is nothing degraded to warn about, and saying otherwise
            // would train operators to ignore the line that matters.
            return null;
        }

        return self::WARN_PREFIX . ($reason === '' ? 'reason unavailable' : $reason) . self::WARN_SUFFIX;
    }

    /**
     * @param array{automatic:bool,reason:string,status:array<string,mixed>} $selection
     * @return array{profile:string,reason:string,automatic:bool,scoped:bool,status:array<string,mixed>}
     */
    private static function fromSelect(array $selection, bool $scoped): array {
        $automatic = ($selection['automatic'] ?? false) === true;

        return [
            'automatic' => $automatic,
            'profile' => $automatic ? RecoveryClaim::VERIFIED_AUTOMATIC : RecoveryClaim::OPERATOR_DIRECTED,
            'reason' => (string) ($selection['reason'] ?? ''),
            'scoped' => $scoped,
            'status' => is_array($selection['status'] ?? null) ? $selection['status'] : [],
        ];
    }

    private static function because(string $selected, string $provable, string $reason, bool $weaker): string {
        if ($weaker) {
            return "operator selected $selected with " . self::WEAKER_FLAG
                . "; the target proves $provable ($reason)";
        }
        if ($selected === RecoveryClaim::VERIFIED_AUTOMATIC) {
            return 'target proved every rollback capability: ' . $reason;
        }

        return "automatic verified rollback is unavailable ($reason), so the operator-directed "
            . 'checkpoint/recovery profile is selected';
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     * @return array{reason_code:string,message:string,remediation:string,gap_action:?string,diagnostics:list<array<string,mixed>>}
     */
    private static function refusal(
        string $reasonCode,
        string $message,
        string $remediation,
        array $diagnostics = []
    ): array {
        return [
            'diagnostics' => $diagnostics,
            // A recovery-profile refusal is an authority question, not an
            // assessment gap, so it deliberately carries no §2.1 gap action:
            // the two closed sets are not interchangeable (MUP §2.3).
            'gap_action' => null,
            'message' => $message,
            'reason_code' => $reasonCode,
            'remediation' => $remediation,
        ];
    }
}

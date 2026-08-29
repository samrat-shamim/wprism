<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once dirname(__DIR__) . '/Contract/ProjectionVocabulary.php';

use Duo\CommandRefusalException;

/**
 * The containment disclosure `duo rehearse` prints once, at the top of its
 * report (round-3 MUP §2.2, §4.4, §8; product spec *Core product workflows
 * → 2. Rehearse*).
 *
 * ## Why a class for two sentences
 *
 * The spec's rehearsal is a *sandbox*: it strips or rebinds production
 * credentials before the environment boots, default-denies outbound HTTP,
 * mail, payment, webhook and queue destinations, and verifies containment
 * before any workflow is exercised. MUP ships none of that (§8, first row),
 * so what it provides is a **preview**. The gap between the two is not a
 * missing feature to be quietly deferred — it is a safety property an
 * operator will otherwise assume, because every other environment tool they
 * have used calls this a staging site. The disclosure is therefore output,
 * not documentation, and it lives in one place so the human banner, the
 * JSON block and any future consumer cannot drift into three differently
 * worded promises.
 *
 * ## The two sentences, and why each is load-bearing
 *
 * `BANNER` is MUP §2.2's literal line. It is composed from
 * `ProjectionVocabulary::CONTAINMENT_BASIS_UNKNOWN` rather than retyped:
 * that constant is the same `unknown — not enforced in this profile` string
 * every projected surface row carries as its containment basis, so the
 * banner and the table are provably the same claim. A second literal here
 * would be a second vocabulary (docs/modules/cli-Contract.md).
 *
 * `CONSEQUENCE` is the sentence MUP §2.2 draws from it: because containment
 * is unproven, this rehearsal **cannot** authorize an `Experimental`
 * readiness or an `Uncertified` certification provenance — the spec permits
 * that only after containment is proven (*Qualification and requalification*
 * step 3). Stating it beside the banner is what stops "I rehearsed it" from
 * being read as "I qualified it". `blocksAuthorization()` is the same rule
 * as a predicate, so a caller that wants to act on it does not re-derive the
 * word list.
 *
 * ## What this class deliberately does not do
 *
 * It performs no I/O, reads no environment, and takes no target. Containment
 * in this profile is `unknown` **structurally** — there is no egress control
 * to interrogate — so a disclosure that accepted an environment name would
 * imply it had measured something. `sandboxed` is never emitted anywhere in
 * MUP (`ProjectionVocabulary::NEVER_EMITTED`); this class asserts that its
 * own containment word is the one MUP can prove, so a future edit that
 * softened the banner fails here rather than in review.
 */
final class RehearsalDisclosure {
    public const FORMAT = 'duo-rehearsal-disclosure/v1';
    public const VERIFIED_FORMAT = 'duo-rehearsal-containment-proof/v1';
    public const VERIFIED_PROFILE = 'agency-rehearsal-v1';
    public const PREFLIGHT = 'containment: required — production-derived bytes will not enter the rehearsal '
        . 'until its machine-local provider proves credential isolation and default-denied HTTP, mail, payment, '
        . 'webhook, and queue destinations.';

    /**
     * The one containment word MUP can honestly emit for a rehearsal
     * environment. A member of `ProjectionVocabulary::EFFECT_CONTAINMENT`,
     * asserted below rather than merely intended.
     */
    public const CONTAINMENT = 'unknown';

    /**
     * MUP §2.2's literal banner. The prefix and the trailing clause are the
     * only bytes minted here; the middle is the shared vocabulary constant.
     */
    public const BANNER = 'containment: ' . ProjectionVocabulary::CONTAINMENT_BASIS_UNKNOWN
        . '; do not point this environment at live payment or mail credentials.';

    /**
     * MUP §2.2's consequence, stated in the same breath as the banner: a
     * preview cannot stand in for qualification.
     */
    public const CONSEQUENCE = 'consequence: this rehearsal cannot authorize an Experimental or '
        . 'Uncertified capability — the spec permits that only after containment is proven. '
        . 'Rehearsal in this profile is a preview and evidence-gathering environment, '
        . 'not a qualification environment.';

    /**
     * The readiness word this profile cannot authorize from a rehearsal
     * (`ProjectionVocabulary::READINESS`).
     */
    public const UNAUTHORIZABLE_READINESS = 'Experimental';

    /**
     * The certification provenance this profile cannot authorize from a
     * rehearsal (`ProjectionVocabulary::CERTIFICATION_PROVENANCE`).
     */
    public const UNAUTHORIZABLE_PROVENANCE = 'Uncertified';

    /**
     * The banner and its consequence, in the order they are printed.
     *
     * Two lines, always both, always in this order: the banner alone reads
     * as a caveat about credentials, and the consequence alone reads as an
     * unexplained restriction. Together they are the disclosure.
     *
     * @return list<string>
     */
    public static function lines(): array {
        self::assertVocabulary();

        return [self::BANNER, self::CONSEQUENCE];
    }

    /** @return list<string> */
    public static function preflightLines(): array {
        return [self::PREFLIGHT];
    }

    /**
     * Reduce a complete materialization receipt to the public containment
     * proof a rehearsal preview binds. A missing field is a refusal even
     * after materialization: no caller may infer isolation from success.
     *
     * @return array{containment_receipt_sha256:string,environment_identity:string,format:string,materialization_receipt_sha256:string,operation_id:string,profile:string}
     */
    public static function verifiedProof(mixed $receipt): array {
        if (!is_array($receipt) || array_is_list($receipt)
            || ($receipt['containment_profile'] ?? null) !== self::VERIFIED_PROFILE) {
            throw self::proofRefusal('the materialization returned no agency rehearsal containment profile');
        }
        foreach (['containment_receipt_sha256', 'environment_identity', 'operation_id', 'receipt_sha256'] as $field) {
            if (!is_string($receipt[$field] ?? null) || $receipt[$field] === '') {
                throw self::proofRefusal("the materialization containment evidence has no '$field'");
            }
        }
        foreach (['containment_receipt_sha256', 'receipt_sha256'] as $field) {
            if (preg_match('/^[a-f0-9]{64}$/D', $receipt[$field]) !== 1) {
                throw self::proofRefusal("the materialization containment evidence has a malformed '$field'");
            }
        }

        return [
            'containment_receipt_sha256' => $receipt['containment_receipt_sha256'],
            'environment_identity' => $receipt['environment_identity'],
            'format' => self::VERIFIED_FORMAT,
            'materialization_receipt_sha256' => $receipt['receipt_sha256'],
            'operation_id' => $receipt['operation_id'],
            'profile' => self::VERIFIED_PROFILE,
        ];
    }

    /** @param array<string,mixed> $proof @return list<string> */
    public static function verifiedLines(array $proof): array {
        $block = self::verifiedBlock($proof);

        return [
            'containment: sandboxed — provider verified ' . self::VERIFIED_PROFILE
                . '; receipt=' . $block['receipt_sha256'] . '.',
            'consequence: this receipt permits contained evidence gathering; capability authorization still '
                . 'requires the applicable reviewed disposition and certification evidence.',
        ];
    }

    /**
     * @param array<string,mixed> $proof
     * @return array<string,mixed>
     */
    public static function verifiedBlock(array $proof): array {
        $expected = [
            'containment_receipt_sha256', 'environment_identity', 'format',
            'materialization_receipt_sha256', 'operation_id', 'profile',
        ];
        $actual = array_keys($proof);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected || ($proof['format'] ?? null) !== self::VERIFIED_FORMAT
            || ($proof['profile'] ?? null) !== self::VERIFIED_PROFILE
            || !is_string($proof['containment_receipt_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $proof['containment_receipt_sha256']) !== 1
            || !is_string($proof['materialization_receipt_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $proof['materialization_receipt_sha256']) !== 1) {
            throw self::proofRefusal('the rehearsal containment proof is malformed or incomplete');
        }

        return [
            'containment' => 'sandboxed',
            'enforced' => true,
            'evidence' => $proof,
            'format' => self::FORMAT,
            'note' => 'credential isolation and default-denied HTTP, mail, payment, webhook, and queue destinations',
            'receipt_sha256' => $proof['containment_receipt_sha256'],
        ];
    }

    /**
     * The disclosure block a `--format=json` document embeds (MUP §4.4).
     *
     * `enforced` is a separate boolean from `containment` on purpose. A
     * consumer that only reads the word `unknown` could be written to treat
     * a future `sandboxed` as "enforced"; a consumer that reads `enforced`
     * cannot, because this profile has no code path that sets it true. The
     * two sentences travel with it so a machine consumer rendering the
     * document has the operator text without re-deriving it.
     *
     * @return array{consequence:string,containment:string,enforced:bool,format:string,note:string}
     */
    public static function block(): array {
        self::assertVocabulary();

        return [
            'consequence' => self::CONSEQUENCE,
            'containment' => self::CONTAINMENT,
            'enforced' => false,
            'format' => self::FORMAT,
            'note' => self::BANNER,
        ];
    }

    /**
     * Whether one projected surface row is a capability this rehearsal is
     * unable to authorize (MUP §2.2's consequence, as a predicate).
     *
     * Takes a `ProjectionVocabulary::project()` result — the same object
     * that sits under an assess row's `operations.<op>` key — so the caller
     * never has to know which two of the six dimensions carry the rule.
     * Anything that is not a projection is `false`: this answers "does the
     * disclosure block this row", and a malformed row is a caller bug that
     * the document validators upstream already refuse, not a licence to
     * claim authorization.
     *
     * @param array<string,mixed> $projection
     */
    public static function blocksAuthorization(array $projection): bool {
        return ($projection['readiness'] ?? null) === self::UNAUTHORIZABLE_READINESS
            || ($projection['certification_provenance'] ?? null) === self::UNAUTHORIZABLE_PROVENANCE;
    }

    /**
     * Fail loudly if the shared vocabulary moved out from under the banner.
     *
     * The banner is a concatenation of a vocabulary constant, so a rename or
     * a reworded basis string would silently change the sentence MUP §2.2
     * fixes literally. Checking the two ends here turns that into a refusal
     * on the machine that produced the report.
     */
    private static function assertVocabulary(): void {
        if (!in_array(self::CONTAINMENT, ProjectionVocabulary::EFFECT_CONTAINMENT, true)
            || in_array(self::CONTAINMENT, ProjectionVocabulary::NEVER_EMITTED, true)) {
            throw self::refuse('the rehearsal containment word is not one this profile can emit');
        }
        if (!str_starts_with(self::BANNER, 'containment: ' . self::CONTAINMENT . ' ')
            || !str_ends_with(self::BANNER, 'live payment or mail credentials.')) {
            throw self::refuse('the rehearsal containment banner no longer states MUP §2.2\'s literal disclosure');
        }
        if (!in_array(self::UNAUTHORIZABLE_READINESS, ProjectionVocabulary::READINESS, true)
            || !in_array(self::UNAUTHORIZABLE_PROVENANCE, ProjectionVocabulary::CERTIFICATION_PROVENANCE, true)) {
            throw self::refuse('the rehearsal consequence names a word outside the projection vocabulary');
        }
    }

    private static function refuse(string $message): CommandRefusalException {
        return new CommandRefusalException(
            'rehearsal_disclosure_invalid',
            $message,
            'this is an orchestrator defect, not a site condition: restore the containment disclosure '
                . 'in cli/src/Rehearse/RehearsalDisclosure.php before rehearsing again'
        );
    }

    private static function proofRefusal(string $message): CommandRefusalException {
        return new CommandRefusalException(
            'rehearsal_containment_unproven',
            $message,
            'configure a machine-local environment provider that implements environment.containment.verify '
                . 'and rerun duo rehearse; production-derived bytes are not admitted without its exact receipt'
        );
    }
}

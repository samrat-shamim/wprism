<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once __DIR__ . '/ApplicationContract.php';
require_once __DIR__ . '/ProjectionVocabulary.php';

use Duo\Canon;
use Duo\CommandRefusalException;

/**
 * `proposed.json`: the assess report turned into a draft application
 * contract, and the accept-time bind that stops a stale draft becoming
 * authority (round-3 MUP §2.6, §3.1, §3.4).
 *
 * The product spec is explicit that assessment "produce[s] a proposed
 * application contract without granting it authority" and that "a
 * declaration cannot certify itself". Two mechanics carry that here.
 *
 * **`assess_digest`.** The proposal records the digest of the exact assess
 * report it was generated from. `accept()` takes that proposal *and* the
 * digest of a freshly-run assess, and refuses when they differ. It does not
 * reconcile them: a site that moved under a review is a site whose review
 * was about a different site. MUP §2.6 states this as "accept refuses a
 * stale proposal rather than reconciling it".
 *
 * **The unreviewed external-effect placeholder.** The proposal seeds the
 * code-lifecycle-window entry MUP §1.6 requires, with `decided_by:
 * "unresolved"` and a reason that says out loud that it has not been
 * reviewed. `ApplicationContract::validate()` refuses that value, so the
 * only way to accept the proposal is to have edited it — the human review
 * step is enforced by the schema rather than requested in a guide.
 *
 * ## `duo-assess-report/v1`
 *
 * The input document, validated here as a closed key set because it crosses
 * a module boundary (`cli/src/Assess/AssessReport.php` emits it, this class
 * consumes it) and a silently-ignored key would be a silently-missing
 * declaration:
 *
 * ```
 * {"format":"duo-assess-report/v1","generated_at":"<RFC3339>","env":"<name>",
 *  "target":{"wordpress":..,"php":..,"database":{"engine":..,"version":..},
 *            "site_mode":..,"home":..,"siteurl":..},
 *  "authority":{...},                       // assess owns this shape
 *  "surfaces":[{"id":..,"label":..,"kind":..,"state_class":..,"handling":..,
 *               "operations":{"<op>":<ProjectionVocabulary::project() result>},
 *               "next_action":..,"decided_by":..,"meaning":..}],
 *  "unknown":{"pending_count":<int>,"invisible_names_count":<int>,"names_sample":[..]},
 *  "evidence":{"registry_sha256":..,"generated_from":{"dispositions_sha256":..}},
 *  "dispositions":{"host_registry_sha256":..,"target_registry_sha256":..,
 *                  "agree":<bool>,"meaning":".."},   // optional, see below
 *  "assess_digest":"sha256:.."}
 * ```
 *
 * `target` is `duo-assess-inventory/v1`'s `target` block verbatim.
 * `authority` is deliberately not key-closed: it is assess's own rendering
 * of "what access Duo actually has", the contract reads none of it, and
 * pinning its shape here would couple two modules for nothing.
 *
 * Three contract fields have no field in that report and arrive as the
 * `$seed` argument instead of by inventing an assess key: the site's name
 * and spec version, the pinned manifests, and the environment bindings. All
 * three come from `duo-assess-inventory/v1`, which the same command already
 * holds — see fromAssessReport().
 */
final class ContractProposal {
    public const FORMAT = 'duo-application-contract-proposal/v1';
    public const ASSESS_REPORT_FORMAT = 'duo-assess-report/v1';

    /** MUP §6.1 step 4: the entry an operator reviews before the first release. */
    public const LIFECYCLE_EFFECT_ID = 'code-lifecycle-window';

    /** MUP §4.6: no listing in a generated document is unbounded either. */
    public const MAX_NAMES_SAMPLE = 200;
    public const MAX_REVIEW_ITEMS = 50;

    public const UNREVIEWED_REASON =
        'proposed, not reviewed: plugin and theme activation hooks run with normal WordPress '
        . 'semantics in the lifecycle window and this profile enforces no egress control. '
        . 'Replace this reason with a reviewed statement about this site, and decided_by with '
        . '"operator", or exclude the surfaces from release.';

    public const ATTESTATION_REASON =
        'the certification gate is deferred for the minimum usable platform';

    /**
     * The two sentences the report's `dispositions` block may carry, and no
     * third: `validateDispositions()` re-derives which one belongs beside a
     * given pair of hashes and refuses the other, so a hand-edited report
     * cannot carry the reassuring sentence over disagreeing numbers.
     *
     * Both are under `AssessRenderer::safe()`'s 160-byte bound, because the
     * human view prints them verbatim and a truncated explanation of a gate
     * is worse than none.
     */
    public const DISPOSITIONS_AGREE_MEANING =
        'the target answered from the reviewed library this checkout ships';

    public const DISPOSITIONS_MISMATCH_MEANING =
        'the target answered from a different reviewed library than this checkout ships; '
        . 'the assessment is honest, but no contract may pin it until the two agree';

    private const REPORT_KEYS = [
        'format', 'generated_at', 'env', 'target', 'authority',
        'surfaces', 'unknown', 'evidence', 'assess_digest',
    ];

    private const TARGET_KEYS = ['wordpress', 'php', 'database', 'site_mode', 'home', 'siteurl'];

    private const SURFACE_KEYS = [
        'id', 'label', 'kind', 'state_class', 'handling',
        'operations', 'next_action', 'decided_by', 'meaning',
    ];

    private const SEED_KEYS = ['site', 'manifest_pins', 'plugins', 'environment_bindings'];

    /**
     * The digest an assess report binds itself with: sha256 over the
     * canonical encoding of everything except the digest field, exactly the
     * rule `contract_digest` follows, so one reader can check both.
     *
     * @param array<string,mixed> $report
     */
    public static function assessDigest(array $report): string {
        unset($report['assess_digest']);

        return 'sha256:' . hash('sha256', Canon::encode($report));
    }

    /**
     * Build `proposed.json` from one assess report.
     *
     * @param array<string,mixed> $report a `duo-assess-report/v1` document
     * @param array<string,mixed> $seed the four facts the report has no
     *        field for, read from `duo-assess-inventory/v1` by the caller:
     *        `site` ({name, spec_version}), `manifest_pins` (the inventory's
     *        `policy.manifests` rows narrowed to name/source/adapter_digest),
     *        `plugins` (active plugins as {slug, version}) and
     *        `environment_bindings` ({required: [...]}).
     * @return array<string,mixed>
     */
    public static function fromAssessReport(array $report, array $seed): array {
        self::validateAssessReport($report);
        self::validateSeed($seed);

        $contract = ApplicationContract::withDigest([
            'format' => ApplicationContract::FORMAT,
            'site' => $seed['site'],
            'declarations' => [
                'stack' => self::proposeStack($report['target'], $seed['plugins']),
                'manifest_pins' => array_values($seed['manifest_pins']),
                'surfaces' => self::proposeSurfaces($report['surfaces']),
                'external_effects' => [self::proposeLifecycleEffect()],
                'journeys' => [],
                'unsupported' => self::proposeUnsupported($report['surfaces']),
                'surface_labels' => self::proposeSurfaceLabels($report['surfaces']),
            ],
            'evidence_pins' => $report['evidence'],
            'attestation' => [
                'format' => ApplicationContract::ATTESTATION_FORMAT,
                'state' => 'unsigned',
                'reason' => self::ATTESTATION_REASON,
                'approving_principal' => null,
                'policy_version' => null,
                'signature' => null,
                'expires_at' => null,
            ],
            'environment_bindings' => $seed['environment_bindings'],
        ]);

        // Structural self-check: a proposal that cannot even parse under the
        // relaxed rule is a generator bug, and finding it here is cheaper
        // than finding it at accept time on an operator's machine.
        ApplicationContract::validate($contract, false);

        $review = self::reviewItems($contract);

        return [
            'format' => self::FORMAT,
            'generated_at' => (string) $report['generated_at'],
            'environment' => (string) $report['env'],
            'assess_digest' => (string) $report['assess_digest'],
            'contract' => $contract,
            'review_required' => array_slice($review, 0, self::MAX_REVIEW_ITEMS),
            'review_required_count' => count($review),
        ];
    }

    /**
     * Validate a proposal against a freshly-computed assess digest and
     * return the contract it carries, ready for `ContractStore::write()`.
     *
     * @param array<string,mixed> $proposal
     * @param string $freshAssessDigest assessDigest() of an assess run made
     *        now, by the caller, against the same environment
     * @return array<string,mixed>
     */
    public static function accept(array $proposal, string $freshAssessDigest): array {
        self::validateProposal($proposal);

        if (!hash_equals($freshAssessDigest, (string) $proposal['assess_digest'])) {
            throw new CommandRefusalException(
                'assess_digest_stale',
                'the proposal was generated from a different assessment than the site returns now',
                'run duo assess and duo contract propose again, review the fresh proposal, then accept it',
                [['proposed_from' => (string) $proposal['assess_digest'], 'observed' => $freshAssessDigest]]
            );
        }

        /** @var array<string,mixed> $contract */
        $contract = $proposal['contract'];
        // Re-derive rather than trust: a hand-edited proposal is the normal
        // path (MUP §3.4's review step), so the digest it carries describes
        // the bytes before the edit.
        $contract = ApplicationContract::withDigest($contract);
        ApplicationContract::validate($contract);

        return $contract;
    }

    /** @param array<string,mixed> $report */
    public static function validateAssessReport(array $report): void {
        if (($report['format'] ?? null) !== self::ASSESS_REPORT_FORMAT) {
            throw self::refuse(
                'assess_report_invalid',
                'the document is not a ' . self::ASSESS_REPORT_FORMAT . ' report'
            );
        }
        // `dispositions` is OPTIONAL for the same reason
        // `unknown.undeclared_tables_count` is (see below): `duo contract
        // accept` compares a stored proposal against a fresh report, and a
        // proposal written before DUO-3484 must refuse with the staleness
        // message the operator can act on, not with a schema error about a
        // key its build had no way to emit. Every report THIS build produces
        // carries it — `AssessReport::build()` takes it as an argument.
        self::closedKeys($report, self::REPORT_KEYS, ['dispositions'], 'assess_report');
        foreach (['generated_at', 'env', 'assess_digest'] as $key) {
            if (!is_string($report[$key]) || $report[$key] === '') {
                throw self::refuse('assess_report_invalid', "assess_report.$key must be a non-empty string");
            }
        }
        $target = self::object($report, 'target', 'assess_report');
        self::closedKeys($target, self::TARGET_KEYS, [], 'assess_report.target');
        $database = self::object($target, 'database', 'assess_report.target');
        self::closedKeys($database, ['engine', 'version'], [], 'assess_report.target.database');
        if (!is_array($report['authority'])) {
            throw self::refuse('assess_report_invalid', 'assess_report.authority must be an object');
        }

        $unknown = self::object($report, 'unknown', 'assess_report');
        self::closedKeys(
            $unknown,
            ['pending_count', 'invisible_names_count', 'names_sample'],
            // T6 §3.7 item 2 added the third count. It is OPTIONAL here on
            // purpose: `duo contract accept` compares a stored proposal
            // against a fresh report, and making an additive count mandatory
            // would refuse every proposal written before this build with a
            // schema error instead of the stale-review message the operator
            // needs. The renderer defaults it to 0, which is what an older
            // report meant by its absence.
            ['undeclared_tables_count'],
            'assess_report.unknown'
        );
        foreach (['pending_count', 'invisible_names_count'] as $key) {
            if (!is_int($unknown[$key])) {
                throw self::refuse('assess_report_invalid', "assess_report.unknown.$key must be an integer");
            }
        }
        if (array_key_exists('undeclared_tables_count', $unknown) && !is_int($unknown['undeclared_tables_count'])) {
            throw self::refuse(
                'assess_report_invalid',
                'assess_report.unknown.undeclared_tables_count must be an integer'
            );
        }
        if (!is_array($unknown['names_sample']) || !array_is_list($unknown['names_sample'])) {
            throw self::refuse('assess_report_invalid', 'assess_report.unknown.names_sample must be a list');
        }
        if (count($unknown['names_sample']) > self::MAX_NAMES_SAMPLE) {
            // MUP §4.6: even the machine document is bounded, because the
            // human renderer is a projection of it and an unbounded sample
            // would make the bound unreachable.
            throw self::refuse(
                'assess_report_unbounded',
                'assess_report.unknown.names_sample exceeds ' . self::MAX_NAMES_SAMPLE . ' entries'
            );
        }

        self::validateEvidence(self::object($report, 'evidence', 'assess_report'));
        self::validateDispositions($report);
        self::validateReportSurfaces($report);

        $stated = (string) $report['assess_digest'];
        $computed = self::assessDigest($report);
        if (!hash_equals($computed, $stated)) {
            throw self::refuse(
                'assess_digest_mismatch',
                'the assess report digest does not match its own content'
            );
        }
    }

    /** @param array<string,mixed> $proposal */
    public static function validateProposal(array $proposal): void {
        if (($proposal['format'] ?? null) !== self::FORMAT) {
            throw self::refuse('proposal_invalid', 'the document is not a ' . self::FORMAT . ' proposal');
        }
        self::closedKeys(
            $proposal,
            ['format', 'generated_at', 'environment', 'assess_digest', 'contract',
                'review_required', 'review_required_count'],
            [],
            'proposal'
        );
        foreach (['generated_at', 'environment', 'assess_digest'] as $key) {
            if (!is_string($proposal[$key]) || $proposal[$key] === '') {
                throw self::refuse('proposal_invalid', "proposal.$key must be a non-empty string");
            }
        }
        if (!is_array($proposal['contract']) || array_is_list($proposal['contract'])) {
            throw self::refuse('proposal_invalid', 'proposal.contract must be an object');
        }
        if (!is_array($proposal['review_required']) || !array_is_list($proposal['review_required'])) {
            throw self::refuse('proposal_invalid', 'proposal.review_required must be a list');
        }
        if (!is_int($proposal['review_required_count'])) {
            throw self::refuse('proposal_invalid', 'proposal.review_required_count must be an integer');
        }
    }

    /**
     * The stack the site is *observed* on, with no ceiling.
     *
     * A generated proposal can honestly state the floor it measured. It
     * cannot state a ceiling: "how far will you let this move before you
     * want it re-reviewed" is a policy question with no fact behind it, and
     * inventing a plausible-looking range would make an unreviewed guess
     * look like a decision. `max: null` is the visible gap the review step
     * in MUP §3.4 exists to close.
     *
     * @param array<string,mixed> $target
     * @param list<array<string,mixed>> $plugins
     * @return array<string,mixed>
     */
    private static function proposeStack(array $target, array $plugins): array {
        $rows = [];
        foreach ($plugins as $plugin) {
            $rows[] = [
                'slug' => (string) $plugin['slug'],
                'min' => (string) $plugin['version'],
                'max' => null,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['slug'], (string) $b['slug']));

        return [
            'wordpress' => ['min' => (string) $target['wordpress'], 'max' => null],
            'php' => ['min' => (string) $target['php'], 'max' => null],
            'database' => [
                'engine' => (string) $target['database']['engine'],
                'min' => (string) $target['database']['version'],
                'max' => null,
            ],
            'site_mode' => (string) $target['site_mode'],
            'plugins' => $rows,
        ];
    }

    /**
     * @param list<array<string,mixed>> $surfaces
     * @return list<array<string,mixed>>
     */
    private static function proposeSurfaces(array $surfaces): array {
        $rows = [];
        foreach ($surfaces as $surface) {
            $row = [
                'id' => (string) $surface['id'],
                'label' => (string) $surface['label'],
                'state_class' => (string) $surface['state_class'],
                'handling' => (string) $surface['handling'],
                'operations' => self::proposedOperations($surface['operations']),
                'decided_by' => (string) $surface['decided_by'],
            ];
            $nextAction = (string) $surface['next_action'];
            if ($nextAction !== 'nothing — supported') {
                $row['next_action'] = $nextAction;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * An operation is proposed only where the projection already says it can
     * run: handling that is not `block`, and readiness that is `Ready` or
     * `Ready with conditions`. Everything else is left out of
     * `operations[]`, which is what makes the declaration a summary of the
     * projection rather than a wish.
     *
     * @param array<string,mixed> $operations
     * @return list<string>
     */
    private static function proposedOperations(array $operations): array {
        $accepted = [];
        foreach (ProjectionVocabulary::OPERATIONS as $operation) {
            $projection = $operations[$operation] ?? null;
            if (!is_array($projection)) {
                continue;
            }
            if (($projection['handling'] ?? null) === 'block') {
                continue;
            }
            if (!in_array($projection['readiness'] ?? null, ['Ready', 'Ready with conditions'], true)) {
                continue;
            }
            $accepted[] = $operation;
        }

        return $accepted;
    }

    /**
     * @param list<array<string,mixed>> $surfaces
     * @return list<array<string,mixed>>
     */
    private static function proposeUnsupported(array $surfaces): array {
        $rows = [];
        foreach ($surfaces as $surface) {
            /** @var array<string,mixed> $operations */
            $operations = $surface['operations'];
            foreach (ProjectionVocabulary::OPERATIONS as $operation) {
                $projection = $operations[$operation] ?? null;
                if (!is_array($projection) || ($projection['readiness'] ?? null) !== 'Unsupported') {
                    continue;
                }
                $reason = $projection['remediation'] ?? null;
                if (!is_string($reason) || $reason === '') {
                    $reason = (string) $projection['meaning'];
                }
                $rows[] = [
                    'surface' => (string) $surface['id'],
                    'operation' => $operation,
                    'reason' => $reason,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $surfaces
     * @return array<string,string>
     */
    private static function proposeSurfaceLabels(array $surfaces): array {
        $labels = [];
        foreach ($surfaces as $surface) {
            $labels[(string) $surface['id']] = (string) $surface['label'];
        }
        ksort($labels, SORT_STRING);

        return $labels;
    }

    /** @return array<string,mixed> */
    private static function proposeLifecycleEffect(): array {
        return [
            'id' => self::LIFECYCLE_EFFECT_ID,
            'surfaces' => ['plugins/themes'],
            'containment' => 'live',
            'effect_recovery_semantics' => 'provider-state restorable',
            'restored_by' => 'code release',
            'reason' => self::UNREVIEWED_REASON,
            'decided_by' => ApplicationContract::UNREVIEWED_DECIDED_BY,
        ];
    }

    /**
     * @param array<string,mixed> $contract
     * @return list<string>
     */
    private static function reviewItems(array $contract): array {
        $items = [];
        /** @var array<string,mixed> $declarations */
        $declarations = $contract['declarations'];
        foreach ($declarations['external_effects'] as $effect) {
            if (($effect['decided_by'] ?? null) === ApplicationContract::UNREVIEWED_DECIDED_BY) {
                $items[] = 'review and decide external effect ' . (string) $effect['id'];
            }
        }
        foreach ($declarations['surfaces'] as $surface) {
            if (($surface['decided_by'] ?? null) === ApplicationContract::UNREVIEWED_DECIDED_BY) {
                $items[] = 'decide surface ' . (string) $surface['id']
                    . ': ' . (string) ($surface['next_action'] ?? 'classify');
            }
        }
        if ($declarations['journeys'] === []) {
            $items[] = 'declare at least one journey, or verify stays byte-level only';
        }
        foreach (['wordpress', 'php'] as $key) {
            if (($declarations['stack'][$key]['max'] ?? null) === null) {
                $items[] = 'declare the supported ' . $key . ' ceiling (max is currently unbounded)';
            }
        }

        return $items;
    }

    /**
     * The same two pins `ApplicationContract::validateEvidencePins()` accepts —
     * this block is copied verbatim into the contract below, so a shape this
     * validator admitted and that one refused would fail at the generator's own
     * self-check with the contract's key path, one module away from the cause.
     *
     * @param array<string,mixed> $evidence
     */
    private static function validateEvidence(array $evidence): void {
        self::closedKeys(
            $evidence,
            ['registry_sha256', 'generated_from'],
            [],
            'assess_report.evidence'
        );
        if (!is_string($evidence['registry_sha256']) || $evidence['registry_sha256'] === '') {
            throw self::refuse('assess_report_invalid', 'assess_report.evidence.registry_sha256 must be a string');
        }
        if (!is_array($evidence['generated_from']) || array_is_list($evidence['generated_from'])) {
            throw self::refuse('assess_report_invalid', 'assess_report.evidence.generated_from must be an object');
        }
    }

    /**
     * The host/target reviewed-library comparison, checked against itself.
     *
     * The block publishes three derived things beside one new fact, and each
     * derivation is re-run here rather than trusted, because the document is
     * digest-bound and hand-edited proposals are the normal path (§3.4's
     * review step): `agree` must be what `hash_equals()` says about the two
     * hashes, `meaning` must be the sentence that belongs beside that
     * verdict, and `target_registry_sha256` must be the same number
     * `evidence.registry_sha256` pins — the block restates it so a reader
     * sees the comparison without joining two blocks, and a restatement that
     * could drift from its source would be worse than no restatement.
     *
     * `AssessReport::requireDispositionsAgree()` reads `agree` and refuses on
     * it, so these three checks are what keep that gate reading a fact.
     *
     * @param array<string,mixed> $report
     */
    private static function validateDispositions(array $report): void {
        if (!array_key_exists('dispositions', $report)) {
            return;
        }
        $block = self::object($report, 'dispositions', 'assess_report');
        $path = 'assess_report.dispositions';
        self::closedKeys(
            $block,
            ['host_registry_sha256', 'target_registry_sha256', 'agree', 'meaning'],
            [],
            $path
        );
        foreach (['host_registry_sha256', 'target_registry_sha256', 'meaning'] as $key) {
            if (!is_string($block[$key]) || $block[$key] === '') {
                throw self::refuse('assess_report_invalid', "$path.$key must be a non-empty string");
            }
        }
        if (!is_bool($block['agree'])) {
            throw self::refuse('assess_report_invalid', "$path.agree must be a boolean");
        }
        $agree = hash_equals((string) $block['host_registry_sha256'], (string) $block['target_registry_sha256']);
        if ($block['agree'] !== $agree) {
            throw self::refuse('assess_report_invalid', "$path.agree contradicts its own two hashes");
        }
        if ($block['meaning'] !== ($agree ? self::DISPOSITIONS_AGREE_MEANING : self::DISPOSITIONS_MISMATCH_MEANING)) {
            throw self::refuse('assess_report_invalid', "$path.meaning is not the sentence this verdict carries");
        }
        if (!hash_equals((string) ($report['evidence']['registry_sha256'] ?? ''), (string) $block['target_registry_sha256'])) {
            throw self::refuse(
                'assess_report_invalid',
                "$path.target_registry_sha256 is not the hash assess_report.evidence pins"
            );
        }
    }

    /** @param array<string,mixed> $report */
    private static function validateReportSurfaces(array $report): void {
        if (!is_array($report['surfaces']) || !array_is_list($report['surfaces'])) {
            throw self::refuse('assess_report_invalid', 'assess_report.surfaces must be a list');
        }
        foreach ($report['surfaces'] as $index => $surface) {
            $path = "assess_report.surfaces[$index]";
            if (!is_array($surface) || array_is_list($surface)) {
                throw self::refuse('assess_report_invalid', "$path must be an object");
            }
            self::closedKeys($surface, self::SURFACE_KEYS, [], $path);
            foreach (['id', 'label', 'kind', 'meaning'] as $key) {
                if (!is_string($surface[$key]) || $surface[$key] === '') {
                    throw self::refuse('assess_report_invalid', "$path.$key must be a non-empty string");
                }
            }
            self::enum($surface['state_class'], ProjectionVocabulary::STATE_CLASSES, "$path.state_class");
            self::enum($surface['handling'], ProjectionVocabulary::HANDLINGS, "$path.handling");
            self::enum($surface['next_action'], ProjectionVocabulary::GAP_ACTIONS, "$path.next_action");
            self::enum($surface['decided_by'], ApplicationContract::DECIDED_BY, "$path.decided_by");
            if (!is_array($surface['operations']) || array_is_list($surface['operations'])) {
                throw self::refuse('assess_report_invalid', "$path.operations must be an operation -> projection object");
            }
            foreach ($surface['operations'] as $operation => $projection) {
                self::enum($operation, ProjectionVocabulary::OPERATIONS, "$path.operations key");
                if (!is_array($projection) || array_is_list($projection)) {
                    throw self::refuse('assess_report_invalid', "$path.operations.$operation must be an object");
                }
                // Required floor only: ProjectionVocabulary owns the
                // projection's shape, and assess is free to carry additive
                // display keys beside it (gap_action, expiry lists) without
                // this consumer having to learn them.
                foreach (['readiness', 'handling', 'state_class', 'certification_provenance',
                    'effect_containment', 'effect_recovery_semantics', 'meaning'] as $key) {
                    if (!array_key_exists($key, $projection)) {
                        throw self::refuse(
                            'assess_report_invalid',
                            "$path.operations.$operation is missing '$key'"
                        );
                    }
                }
                self::enum($projection['readiness'], ProjectionVocabulary::READINESS, "$path.operations.$operation.readiness");
                self::enum($projection['handling'], ProjectionVocabulary::HANDLINGS, "$path.operations.$operation.handling");
            }
        }
    }

    /** @param array<string,mixed> $seed */
    private static function validateSeed(array $seed): void {
        self::closedKeys($seed, self::SEED_KEYS, [], 'proposal_seed');
        foreach (['manifest_pins', 'plugins'] as $key) {
            if (!is_array($seed[$key]) || !array_is_list($seed[$key])) {
                throw self::refuse('proposal_seed_invalid', "proposal_seed.$key must be a list");
            }
        }
        foreach (['site', 'environment_bindings'] as $key) {
            if (!is_array($seed[$key]) || array_is_list($seed[$key])) {
                throw self::refuse('proposal_seed_invalid', "proposal_seed.$key must be an object");
            }
        }
        foreach ($seed['plugins'] as $index => $plugin) {
            if (!is_array($plugin) || !is_string($plugin['slug'] ?? null) || !is_string($plugin['version'] ?? null)) {
                throw self::refuse(
                    'proposal_seed_invalid',
                    "proposal_seed.plugins[$index] must carry a slug and a version"
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $value
     * @param list<string> $required
     * @param list<string> $optional
     */
    private static function closedKeys(array $value, array $required, array $optional, string $path): void {
        $known = array_merge($required, $optional);
        foreach (array_keys($value) as $key) {
            if (!in_array((string) $key, $known, true)) {
                throw self::refuse(
                    'assess_report_invalid',
                    "$path carries the unrecognised key '" . (string) $key . "'"
                );
            }
        }
        foreach ($required as $key) {
            if (!array_key_exists($key, $value)) {
                throw self::refuse('assess_report_invalid', "$path is missing the required key '$key'");
            }
        }
    }

    /**
     * @param array<string,mixed> $parent
     * @return array<string,mixed>
     */
    private static function object(array $parent, string $key, string $path): array {
        $value = $parent[$key] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            throw self::refuse('assess_report_invalid', "$path.$key must be an object");
        }

        return $value;
    }

    /** @param list<string> $allowed */
    private static function enum(mixed $value, array $allowed, string $path): void {
        if (!in_array($value, $allowed, true)) {
            throw self::refuse(
                'assess_report_invalid',
                "$path must be one of " . implode(' | ', $allowed)
            );
        }
    }

    private static function refuse(string $code, string $message): CommandRefusalException {
        return new CommandRefusalException(
            $code,
            $message,
            'run duo assess again and regenerate the proposal with duo contract propose'
        );
    }
}

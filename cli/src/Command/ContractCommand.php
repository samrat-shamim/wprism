<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/../Adapter/AdapterCertify.php';
require_once __DIR__ . '/../Contract/ApplicationContract.php';
require_once __DIR__ . '/../Contract/ContractAttestation.php';
require_once __DIR__ . '/../Contract/ContractProjection.php';
require_once __DIR__ . '/../Contract/ContractProposal.php';
require_once __DIR__ . '/../Contract/ContractStore.php';
require_once __DIR__ . '/../Contract/ProjectionVocabulary.php';
require_once __DIR__ . '/../Assess/AssessReport.php';
require_once __DIR__ . '/../Assess/AssessRenderer.php';
require_once __DIR__ . '/AssessCommand.php';
require_once __DIR__ . '/CommandOutput.php';

use Duo\Canon;
use Duo\CommandRefusalException;

/**
 * `duo contract <env> show|propose|accept` — the per-site application
 * contract as an operator verb (round-3 MUP §2.6, §3; verb boundary per the
 * module map's rule 9).
 *
 * The three subcommands are three different trust postures and the
 * differences are the whole point:
 *
 * **`propose`** runs a fresh assessment and writes `proposed.json`. A
 * proposal is never authority — MUP §3 and the product spec are explicit
 * that "a declaration cannot certify itself" — and the generated document
 * carries an `external_effects[]` entry with `decided_by: "unresolved"`
 * that `ApplicationContract::validate()` refuses. The only way to accept a
 * proposal is therefore to have edited it: the human review step is
 * enforced by the schema, not requested in a guide.
 *
 * **`show`** renders what is on disk and contacts nothing. `contract.json`
 * and `projection.json` are committed review artifacts; showing them is a
 * question about the repository, not about the site, and answering it with
 * a fresh probe would mean an operator could never see what their
 * colleagues actually reviewed.
 *
 * **`accept`** re-runs the assessment, refuses a stale proposal rather than
 * reconciling it, writes both documents under compare-and-swap, and stages
 * them. It never commits: the commit is the human's signature on the
 * review, and a tool that made it would be signing on their behalf.
 *
 * **`attest`** is the fourth posture and the newest. It signs the ALREADY
 * ACCEPTED contract under an Ed25519 key the operator provisioned in this
 * repository's own `.duo/contract/authorities.json`, so the document carries
 * a machine-checkable statement of who approved it and until when. It
 * contacts nothing, it never proposes or edits declarations — attest signs
 * what review produced — and on every site that has not provisioned a key it
 * refuses with `contract_attestation_unsigned_anchor` before it reads a byte.
 * The trust root ships empty and no command but this one creates it, which is
 * why the honesty line every assessment prints (`; contract attestation
 * unsigned`) stays literally true until an organization decides to hold a key.
 *
 * ## The staleness bind, stated precisely
 *
 * `proposed.json` records the `assess_digest` of the assessment it was
 * generated from. Accept re-runs assess and compares. Two assessments of an
 * *unchanged* site differ only by their `generated_at`, so the fresh report
 * is restamped with the proposal's timestamp before the comparison
 * (`AssessReport::rebind()`): the bind is over the facts, and "the clock
 * moved" never reads as "the site moved". Anything else that differs — a
 * plugin version, a new table, an expired bundle, a changed policy class —
 * moves the digest and refuses with `assess_digest_stale`.
 *
 * Staleness is a claim about the SITE, so it must never be the way an
 * environment mix-up is reported. The proposal lives at
 * `.duo/contract/<env>/proposed.json` and stamps the environment it was
 * generated for, and `accept` compares that stamp against the environment
 * it was invoked for before it contacts anything
 * (`contract_proposal_environment_mismatch`). Before DUO-3503 the one
 * shared slot made a cross-environment overwrite surface as
 * `contract_proposal_stale` — "the site returns something different now"
 * about a site that had not moved.
 */
final class ContractCommand {
    /** @var list<string> */
    public const SUBCOMMANDS = ['show', 'propose', 'accept', 'attest'];

    /**
     * The closed option set `attest` accepts, and the reason it is closed at
     * all: every one of these values ends up inside the signed statement, so a
     * mistyped `--policy-versoin` that was silently ignored would mint an
     * attestation stating a policy version nobody chose.
     *
     * @var list<string>
     */
    private const ATTEST_OPTIONS = [
        '--secret-key-file', '--principal', '--policy-version', '--expires', '--key-id', '--reason', '--format',
    ];

    /**
     * The default expiry window, in days, when the operator names none.
     *
     * A year, and finite rather than absent, because the expiry is ENFORCED at
     * read time (`ContractAttestation::verify()`): an attestation that never
     * expired would let a review from three agent versions ago keep vouching
     * for a site nobody has looked at since.
     */
    private const DEFAULT_EXPIRY_DAYS = 365;

    /** What the signed document says it is, when the operator states nothing else. */
    private const DEFAULT_REASON =
        'attested under an operator-provisioned contract trust root: a customer-organization '
        . 'statement about this site, explicitly not a Duo endorsement';

    /**
     * @param list<string> $extra everything after `<env>`
     * @param string $sourceRoot this checkout's root (for the bootstrap probe)
     * @param callable(array):array|null $hostCatalog injection seam, see AssessCommand
     * @param callable():string|null $clock null reads the wall clock
     */
    public static function run(
        EnvironmentDriver $driver,
        array $extra,
        string $sourceRoot,
        ?callable $hostCatalog = null,
        ?callable $clock = null
    ): int {
        $json = AssessCommand::wantsJson($extra);
        try {
            $subcommand = self::subcommand($extra);
            $siteRepo = AssessCommand::siteRepo(getcwd() ?: '.');
            $store = new ContractStore($siteRepo);

            $lines = match ($subcommand) {
                'show' => self::show($store, $json),
                'propose' => self::propose($driver, $sourceRoot, $hostCatalog, $clock, $json),
                'accept' => self::accept($driver, $store, $siteRepo, $sourceRoot, $hostCatalog, $clock, $json),
                'attest' => self::attest($store, $siteRepo, $extra, $json),
            };
        } catch (CommandRefusalException $refusal) {
            return AssessCommand::renderRefusal($refusal, $json, 'contract');
        }
        foreach ($lines as $line) {
            echo $line . "\n";
        }

        return 0;
    }

    /**
     * Render the accepted contract and its generated projection, bounded.
     *
     * @return list<string>
     */
    private static function show(ContractStore $store, bool $json): array {
        $contract = $store->readContract();
        if ($contract === null) {
            throw new CommandRefusalException(
                'contract_missing',
                'this site repository has no accepted application contract',
                'run duo contract <env> propose, review the proposal, then duo contract <env> accept'
            );
        }
        $projection = $store->readProjection();
        if ($json) {
            return [rtrim(Canon::encode(['contract' => $contract, 'projection' => $projection]), "\n")];
        }

        return self::renderContract($contract, $projection);
    }

    /**
     * Regenerate `proposed.json` from a fresh assessment.
     *
     * @param callable(array):array|null $hostCatalog
     * @param callable():string|null $clock
     * @return list<string>
     */
    private static function propose(
        EnvironmentDriver $driver,
        string $sourceRoot,
        ?callable $hostCatalog,
        ?callable $clock,
        bool $json
    ): array {
        $result = self::freshAssessment($driver, $sourceRoot, $hostCatalog, $clock);
        // A proposal binds this checkout's provenance to the target's
        // verdicts, so proposing across two different reviewed libraries is
        // the unsafe act DUO-3484 gates (AssessReport::
        // requireDispositionsAgree()). `duo assess` still answers under the
        // same skew — it withholds the file and says so.
        AssessReport::requireDispositionsAgree($result['report']);
        AssessCommand::writeLocalArtifacts($result);
        $proposal = $result['store']->readProposal($driver->name()) ?? [];
        if ($json) {
            return [rtrim(Canon::encode($proposal), "\n")];
        }

        $lines = [
            'proposed contract written: ' . $result['store']->proposalRelativePath($driver->name()),
            'review required: ' . (int) ($proposal['review_required_count'] ?? 0) . ' item(s)',
        ];
        foreach (array_slice((array) ($proposal['review_required'] ?? []), 0, ContractProposal::MAX_REVIEW_ITEMS) as $item) {
            $lines[] = '  - ' . self::safe($item);
        }
        $remaining = (int) ($proposal['review_required_count'] ?? 0)
            - count((array) ($proposal['review_required'] ?? []));
        if ($remaining > 0) {
            $lines[] = '  ' . $remaining . ' more (use --format=json)';
        }
        $lines[] = 'a proposal is not authority: edit the reviewed fields, then run duo contract '
            . self::token($driver->name()) . ' accept';

        return $lines;
    }

    /**
     * Validate the reviewed proposal against a fresh assessment, write both
     * documents, and stage them.
     *
     * @param callable(array):array|null $hostCatalog
     * @param callable():string|null $clock
     * @return list<string>
     */
    private static function accept(
        EnvironmentDriver $driver,
        ContractStore $store,
        string $siteRepo,
        string $sourceRoot,
        ?callable $hostCatalog,
        ?callable $clock,
        bool $json
    ): array {
        // Refused before the target is contacted: staging is part of accept,
        // so a repository that cannot be staged into cannot accept, and
        // discovering that after four round-trips helps nobody.
        AssessCommand::requireGitWorktreeRoot($siteRepo);

        $proposal = $store->readProposal($driver->name());
        if ($proposal === null) {
            throw new CommandRefusalException(
                'contract_proposal_missing',
                'this site repository has no proposed application contract',
                'run duo contract <env> propose, review the proposal, then accept it'
            );
        }
        ContractProposal::validateProposal($proposal);

        // The environment bind, and like the worktree check above it is
        // refused BEFORE the target is contacted: this is answerable from
        // two strings already in hand, and four round-trips of assess to
        // reach the same answer helps nobody. `validateProposal()` has
        // required a non-empty `environment` since the format existed
        // (ContractProposal.php:313-318) and nothing had ever compared it,
        // so the field was a promise the product did not keep (DUO-3503).
        // Per-environment paths make a mismatch here mean the file was
        // moved, copied or hand-edited — the residual case the path alone
        // cannot rule out, and exactly the one where accepting a contract
        // reviewed against another environment is the harm.
        if (!hash_equals($driver->name(), (string) $proposal['environment'])) {
            throw new CommandRefusalException(
                'contract_proposal_environment_mismatch',
                'the proposal was generated for a different environment than this accept',
                'run duo contract ' . self::token($driver->name())
                    . ' propose for this environment, review the fresh proposal, then accept it',
                [[
                    'proposed_for' => self::token((string) $proposal['environment']),
                    'accepting' => self::token($driver->name()),
                ]]
            );
        }

        // The digest the caller read before it began. `writeContract()`
        // refuses if the stored contract has moved since, which is the
        // lost-update guard across the human review step.
        $expectedDigest = $store->currentDigest();

        $result = self::freshAssessment($driver, $sourceRoot, $hostCatalog, $clock);
        // Before the staleness bind below, not after: the `dispositions` block
        // is inside the digest, so a checkout that moved makes the proposal
        // stale as well — and `contract_proposal_stale` says "the site returns
        // something different now" about a site that did not move. The
        // operator gets the cause, not the consequence (DUO-3484).
        AssessReport::requireDispositionsAgree($result['report']);
        $rebound = AssessReport::rebind($result['report'], (string) $proposal['generated_at']);
        $freshDigest = ContractProposal::assessDigest($rebound);

        if (!hash_equals($freshDigest, (string) $proposal['assess_digest'])) {
            throw new CommandRefusalException(
                'contract_proposal_stale',
                'the proposal was generated from a different assessment than the site returns now',
                'run duo contract ' . self::token($driver->name())
                    . ' propose again, review the fresh proposal, then accept it',
                [[
                    'code' => 'contract_proposal_stale',
                    'message' => 'the proposal and the current assessment describe different sites',
                    'remediation' => 'propose again and review the differences before accepting',
                ]]
            );
        }

        $contract = ContractProposal::accept($proposal, $freshDigest);
        $store->writeContract($contract, $expectedDigest);
        $store->writeProjection(AssessCommand::projection($result, $contract));
        $staged = self::stage($siteRepo, [
            ContractStore::DIRECTORY . '/' . ContractStore::CONTRACT_FILE,
            ContractStore::DIRECTORY . '/' . ContractStore::PROJECTION_FILE,
        ]);

        if ($json) {
            return [rtrim(Canon::encode([
                'format' => 'duo-contract-accept/v1',
                'environment' => $driver->name(),
                'contract_digest' => (string) $contract['contract_digest'],
                'staged' => $staged,
            ]), "\n")];
        }

        return [
            'accepted: ' . ContractStore::DIRECTORY . '/' . ContractStore::CONTRACT_FILE,
            'generated: ' . ContractStore::DIRECTORY . '/' . ContractStore::PROJECTION_FILE,
            $staged
                ? 'staged for commit; the commit is yours to make — it is your signature on this review'
                : 'not staged: git could not stage the review artifacts; add them before committing',
        ];
    }

    /**
     * Sign the accepted contract under a key in this repository's own
     * contract trust root.
     *
     * Contacts nothing, exactly like `show`: the document being signed is a
     * committed review artifact and the key is on this machine, so asking a
     * site about it would answer a question nobody asked. `<env>` is verb
     * grammar only — the contract is per site (ContractStore's "two tiers"
     * docblock), and an attestation of it is too.
     *
     * ## Ordering, and why it is this ordering
     *
     * The public key is registered in `.duo/contract/authorities.json` BEFORE
     * anything is signed, because that file is where `verify()` resolves a key
     * from and a signature under an unregistered key is a document that cannot
     * be read back. If signing or writing then fails, the trust root is
     * restored to exactly the bytes this run found — the same rollback
     * `AdapterCertify::certify()` performs for the same reason
     * (AdapterCertify.php:326-334): a failed attest leaves the repository
     * exactly as it found it.
     *
     * ## What it refuses on a shipped site
     *
     * `contract_attestation_unsigned_anchor`, before it reads a key or touches
     * a byte, because no site ships with a trust root. That refusal IS this
     * feature on every site that has not made the organizational decision to
     * hold a signing key.
     *
     * @param list<string> $extra
     * @return list<string>
     */
    private static function attest(ContractStore $store, string $siteRepo, array $extra, bool $json): array {
        // Refused before anything is written, as accept does (:195): staging is
        // part of attest, so a repository that cannot be staged into cannot
        // attest, and finding that out after the trust root was written would
        // leave a key registered by a run that produced no attestation.
        AssessCommand::requireGitWorktreeRoot($siteRepo);
        $options = self::attestOptions($extra);

        // The unverified read, and the one caller of it. Every verification
        // refusal (`_expired`, `_platform_moved`, `_key_revoked`) has
        // re-attesting as its remedy, so this verb has to be able to read the
        // document it is about to re-sign — see readContractUnverified().
        $contract = $store->readContractUnverified();
        if ($contract === null) {
            throw new CommandRefusalException(
                'contract_missing',
                'this site repository has no accepted application contract',
                'run duo contract <env> propose, review the proposal, then duo contract <env> accept; '
                    . 'attest signs what review produced, never a fresh document'
            );
        }
        $expectedDigest = $store->currentDigest();

        $secret = self::secretKey($options['--secret-key-file'] ?? '');
        $public = sodium_crypto_sign_publickey_from_secretkey($secret);
        // `site-<12 hex of sha256(public key)>` is AdapterCertify's derivation
        // (AdapterCertify.php:942-944) and the reason is the same one it gives:
        // an operator who names no id still gets one they can read off two
        // machines and compare. The prefix says which root the key belongs to,
        // and `contract-` is not `site-` because these are different files
        // holding differently-scoped records.
        $keyId = (string) ($options['--key-id'] ?? ('contract-' . substr(hash('sha256', $public), 0, 12)));
        $expires = (string) ($options['--expires']
            ?? gmdate('Y-m-d\TH:i:s\Z', time() + self::DEFAULT_EXPIRY_DAYS * 86400));

        $authoritiesPath = ContractAttestation::authoritiesPath($siteRepo);
        $authoritiesBefore = is_file($authoritiesPath) ? (string) file_get_contents($authoritiesPath) : null;
        ContractAttestation::registerAuthority($siteRepo, $keyId, $public);
        try {
            $signed = ContractAttestation::sign($contract, $siteRepo, $keyId, $secret, [
                'approving_principal' => (string) ($options['--principal'] ?? ''),
                'policy_version' => (string) ($options['--policy-version'] ?? ''),
                'expires_at' => $expires,
                'reason' => (string) ($options['--reason'] ?? self::DEFAULT_REASON),
            ]);
            // The verifying door: writeAttestedContract() runs the same
            // verification every later read runs, over the bytes about to land.
            $store->writeAttestedContract($signed, $expectedDigest);
        } catch (\Throwable $t) {
            if ($authoritiesBefore === null) {
                @unlink($authoritiesPath);
            } else {
                file_put_contents($authoritiesPath, $authoritiesBefore, LOCK_EX);
            }
            throw $t;
        }

        $verified = ContractAttestation::verify($signed, $siteRepo);
        $staged = self::stage($siteRepo, [
            ContractStore::DIRECTORY . '/' . ContractStore::CONTRACT_FILE,
            ContractAttestation::AUTHORITIES_RELATIVE,
        ]);

        if ($json) {
            return [rtrim(Canon::encode([
                'format' => 'duo-contract-attest/v1',
                'contract_digest' => (string) $signed['contract_digest'],
                'attestation' => $verified,
                'staged' => $staged,
            ]), "\n")];
        }

        return [
            'attested: ' . ContractStore::DIRECTORY . '/' . ContractStore::CONTRACT_FILE,
            'principal: ' . self::safe($verified['principal'])
                . ' (' . self::safe($verified['trust_root']) . ' trust root, key '
                . self::safe($verified['key_id']) . ')',
            'policy: ' . self::safe($verified['policy_version'])
                . ' · expires ' . self::safe($verified['expires_at']),
            'trust root: ' . ContractAttestation::AUTHORITIES_RELATIVE,
            $staged
                ? 'staged for commit; the commit is yours to make — it is your signature on this review'
                : 'not staged: git could not stage the attestation; add the two files before committing',
            'an agent upgrade moves the platform boundary this attestation binds, and every consumer '
                . 'then refuses until you attest again',
        ];
    }

    /**
     * The attest option set, parsed closed.
     *
     * @param list<string> $extra
     * @return array<string,string>
     */
    private static function attestOptions(array $extra): array {
        $options = [];
        foreach ($extra as $arg) {
            if (!is_string($arg) || !str_starts_with($arg, '-')) {
                continue;
            }
            $name = str_contains($arg, '=') ? explode('=', $arg, 2)[0] : $arg;
            if (!in_array($name, self::ATTEST_OPTIONS, true)) {
                throw new CommandRefusalException(
                    'invalid_arguments',
                    'attest received an option it does not define',
                    'attest accepts --secret-key-file=<path>, --principal=<who>, --policy-version=<v>, '
                        . '--expires=<ISO8601>, --key-id=<id>, --reason=<text> and --format=json'
                );
            }
            if ($name !== '--format' && str_contains($arg, '=')) {
                $options[$name] = explode('=', $arg, 2)[1];
            }
        }
        foreach (['--secret-key-file', '--principal', '--policy-version'] as $required) {
            if (($options[$required] ?? '') === '') {
                throw new CommandRefusalException(
                    'invalid_arguments',
                    "attest requires $required",
                    'run duo contract <env> attest --secret-key-file=<path> --principal=<who> '
                        . '--policy-version=<v>; an attestation that states nothing proves nothing'
                );
            }
        }

        return $options;
    }

    /**
     * The operator's private key, as bytes.
     *
     * `AdapterCertify::readSecretKey()` is reused rather than re-implemented —
     * including its 0077 mode check, whose rationale is written there
     * (AdapterCertify.php:908-915): a key readable by the group or the world
     * is not a private key, and signing with one would mint an attestation
     * anybody on the box could forge. Its \RuntimeException carries the path,
     * so it is converted here into a typed refusal that keeps the path in the
     * operator-only channel.
     */
    private static function secretKey(string $path): string {
        try {
            return AdapterCertify::readSecretKey($path);
        } catch (\RuntimeException $e) {
            throw new CommandRefusalException(
                'contract_attestation_key_unreadable',
                'the attestation secret key file could not be read as an Ed25519 private key',
                'pass --secret-key-file=<path> to a chmod 600 file holding a base64 or hexadecimal '
                    . 'Ed25519 secret key, as duo adapter keygen writes',
                [],
                $e->getMessage(),
                $e
            );
        }
    }

    /**
     * One fresh assessment, over every operation.
     *
     * `propose` and `accept` both need the *whole* projection: a contract
     * that declared only the operations someone happened to ask about would
     * silently omit the rest, and §3.4's rule is that a declaration never
     * grants authority — it can only fail to record a gap.
     *
     * @param callable(array):array|null $hostCatalog
     * @param callable():string|null $clock
     * @return array<string,mixed>
     */
    private static function freshAssessment(
        EnvironmentDriver $driver,
        string $sourceRoot,
        ?callable $hostCatalog,
        ?callable $clock
    ): array {
        return AssessCommand::assess($driver, [
            'operations' => ProjectionVocabulary::OPERATIONS,
            'source_root' => $sourceRoot,
            'host_catalog' => $hostCatalog,
            'generated_at' => ($clock ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z'))(),
        ]);
    }

    /**
     * Human rendering of the contract and its projection, bounded per
     * MUP §4.6.
     *
     * @param array<string,mixed> $contract
     * @param array<string,mixed>|null $projection
     * @return list<string>
     */
    private static function renderContract(array $contract, ?array $projection): array {
        $declarations = is_array($contract['declarations'] ?? null) ? $contract['declarations'] : [];
        $attestation = is_array($contract['attestation'] ?? null) ? $contract['attestation'] : [];
        $surfaces = is_array($declarations['surfaces'] ?? null) ? $declarations['surfaces'] : [];
        $effects = is_array($declarations['external_effects'] ?? null) ? $declarations['external_effects'] : [];
        $journeys = is_array($declarations['journeys'] ?? null) ? $declarations['journeys'] : [];

        $lines = [
            'contract: ' . self::safe($contract['site']['name'] ?? '?')
                . ' · spec_version ' . (int) ($contract['site']['spec_version'] ?? 0),
            'attestation: ' . self::safe($attestation['state'] ?? '?')
                . ' — ' . self::safe($attestation['reason'] ?? ''),
            'declared: ' . count($surfaces) . ' surface(s), ' . count($effects)
                . ' external effect(s), ' . count($journeys) . ' journey(s)',
        ];
        if (($attestation['state'] ?? null) === 'signed') {
            // Printed only under `signed`, and only reachable at all because
            // `readContract()` verified the document on the way in: a
            // signature that did not verify never becomes a printed principal,
            // it becomes a refusal. So this line states a checked fact.
            array_splice($lines, 2, 0, ['attested by: ' . self::safe($attestation['approving_principal'] ?? '?')
                . ' (' . self::safe($attestation['trust_root'] ?? '?') . ' trust root, key '
                . self::safe($attestation['key_id'] ?? '?') . ')'
                . ' · policy ' . self::safe($attestation['policy_version'] ?? '?')
                . ' · expires ' . self::safe($attestation['expires_at'] ?? '?')]);
        }
        foreach (array_slice($effects, 0, AssessRenderer::DEFAULT_LIMIT) as $effect) {
            if (!is_array($effect)) {
                continue;
            }
            $lines[] = '  effect ' . self::safe($effect['id'] ?? '?')
                . ': containment ' . self::safe($effect['containment'] ?? '?')
                . ' · recovery ' . self::safe($effect['effect_recovery_semantics'] ?? '?')
                . ' · decided by ' . self::safe($effect['decided_by'] ?? '?');
        }
        if ($journeys === []) {
            // MUP §2.4's disclosure, said once here rather than only at
            // verify time: byte-level convergence is necessary and not
            // sufficient, and an operator reading their contract should see
            // the gap before a release depends on it.
            $lines[] = 'no journeys declared: verify will be byte-level only';
        }

        if ($projection === null) {
            $lines[] = 'projection: none on disk (run duo assess <env> to regenerate it)';

            return $lines;
        }
        $rows = is_array($projection['surfaces'] ?? null) ? $projection['surfaces'] : [];
        $pins = is_array($projection['evidence_pins'] ?? null) ? $projection['evidence_pins'] : [];
        $lines[] = 'projection: ' . count($rows) . ' surface(s) · evidence '
            . (($pins['current'] ?? false) === true ? 'current' : 'stale');
        $shown = array_slice($rows, 0, AssessRenderer::DEFAULT_LIMIT);
        foreach ($shown as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lines[] = '  ' . self::safe($row['label'] ?? $row['id'] ?? '?')
                . ' — ' . self::safe($row['state_class'] ?? '?')
                . ' / ' . self::safe($row['handling'] ?? '?')
                . (($row['declared'] ?? false) === true ? '' : ' (undeclared)');
        }
        $remaining = count($rows) - count($shown);
        if ($remaining > 0) {
            $lines[] = '  ' . $remaining . ' more (use --format=json)';
        }

        return $lines;
    }

    /**
     * Stage the two review artifacts. Never commits, and never runs a
     * repository hook: `git add` is invoked argv-style with `bypass_shell`,
     * exactly as `Registry::isGitTracked()` does.
     *
     * @param list<string> $paths repository-relative
     */
    private static function stage(string $siteRepo, array $paths): bool {
        // `-f`: the site repository's own boundary (`duo init`'s required
        // `/.duo/` rule, InitRepositoryBoundary::ensure_gitignore()) ignores
        // Duo's whole private working area — checkpoints, artifacts, env
        // values, control state — and that boundary is right. The two review
        // artifacts are the deliberate exception MUP §3.1 names as committed:
        // the contract is a reviewed declaration and the projection its
        // review record, and a `git add` that silently obeyed the boundary
        // left every product-initialized site with a contract nothing
        // versioned (grind_mup.sh step 4). Force-adding exactly these two
        // paths tracks them from here on; nothing else under .duo/ is touched.
        $command = array_merge(['git', '-C', $siteRepo, 'add', '-f', '--'], $paths);
        $process = @proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            return false;
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0;
    }

    /** @param list<string> $extra */
    private static function subcommand(array $extra): string {
        $found = null;
        foreach ($extra as $arg) {
            if (!is_string($arg) || str_starts_with($arg, '-')) {
                continue;
            }
            if ($arg === 'json') {
                // The spaced `--format json` spelling; the flag parser above
                // already consumed its meaning.
                continue;
            }
            if ($found !== null) {
                throw self::usage();
            }
            $found = $arg;
        }
        if ($found === null || !in_array($found, self::SUBCOMMANDS, true)) {
            throw self::usage();
        }

        return $found;
    }

    private static function usage(): CommandRefusalException {
        return new CommandRefusalException(
            'invalid_arguments',
            'contract takes exactly one subcommand',
            'run duo contract <env> ' . implode('|', self::SUBCOMMANDS)
        );
    }

    /** @param mixed $value */
    private static function safe($value): string {
        if (!is_string($value)) {
            return is_scalar($value) ? (string) $value : '?';
        }
        $bounded = strlen($value) > 200 ? substr($value, 0, 200) . '…' : $value;

        return (string) preg_replace('/[\x00-\x1f\x7f]/', '?', $bounded);
    }

    private static function token(string $value): string {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $value) === 1 ? $value : '<env>';
    }
}

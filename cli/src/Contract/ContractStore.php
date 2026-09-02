<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once __DIR__ . '/ApplicationContract.php';
require_once __DIR__ . '/ContractAttestation.php';

use WPrism\Canon;
use WPrism\CommandRefusalException;

/**
 * The `<siteRepo>/.wprism/contract/` directory as an object (round-3 MUP §3.1).
 *
 * Three documents live here — `contract.json` (reviewed authority),
 * `proposed.json` (assess output, never authoritative) and `projection.json`
 * (generated, a review artifact). All three are canonical JSON through
 * `\WPrism\Canon` so they diff and merge like the rest of `state/`.
 *
 * **Two tiers, and the directory says which.** `contract.json` and
 * `projection.json` are PER SITE and sit directly in `.wprism/contract/`: the
 * contract is one reviewed statement about this repository, and
 * `environment_bindings.required[]` is how it says what varies per
 * environment (ApplicationContract.php:428-441). `proposed.json` is PER
 * ENVIRONMENT and sits in `.wprism/contract/<env>/`, because the document is
 * environment-specific in two independent ways: it stamps `environment`
 * from the report (ContractProposal.php:185), and the `assess_digest` it
 * binds itself with covers `env` plus `target.home`/`target.siteurl`
 * (AssessReport.php:103, ContractProposal.php:113). One slot for N
 * environments meant `wprism assess <other-env>` silently overwrote a
 * reviewed-but-unaccepted proposal — unrecoverably, since `/.wprism/*` is
 * inside the site repo's own ignore (apart from the release trust policy) — and
 * the next accept blamed the site with `contract_proposal_stale` (issue #3503).
 * That is why the three proposal methods take the environment as a REQUIRED
 * argument: a default would let the shared slot back in by omission.
 *
 * Three mechanics are the reason this is a class rather than three
 * `file_put_contents()` calls.
 *
 * **Write-then-rename.** `rename(2)` within one directory is atomic, so a
 * reader either sees the whole previous document or the whole new one, never
 * a truncated middle. `file_put_contents()` straight onto the destination
 * has a real window in which `contract.json` is zero bytes, and the file it
 * would truncate is the document `wprism release` cites in a frozen
 * authorization plan.
 *
 * **Compare-and-swap on `contract_digest`.** Accepting a contract is a
 * read-modify-write across a human review step that can take minutes, and
 * `.wprism/contract/` is a git working tree two operators (or an operator and
 * an agent) can both be sitting in. The caller states the digest it read;
 * if the on-disk digest has moved since, the write refuses instead of
 * silently discarding the other writer's review. A refusal here is cheap —
 * re-run assess — while a lost reviewed declaration is exactly the kind of
 * unknown MUP §1.6 exists to keep out of production.
 *
 * **The attestation boundary.** `attestation.state` is validated as a shape by
 * `ApplicationContract` and decided as a fact here, because writing and
 * reading are where a trust root can actually be consulted. There are two
 * doors and they are not interchangeable: `writeContract()` — the accept path
 * — refuses every signed document with `attestation_signing_unsupported`, and
 * `writeAttestedContract()` opens only for bytes `ContractAttestation::verify()`
 * accepts under a key the operator provisioned in
 * `.wprism/contract/authorities.json`. `readContract()` re-verifies on the way
 * out, once, for every consumer, so a contract whose bytes moved under a
 * signature drops the claim at all of them rather than degrading at some.
 * Nothing here can mint on a site with no trust root, and no shipped site has
 * one — the file is created by `wprism contract <env> attest` and by nothing else.
 */
final class ContractStore {
    public const DIRECTORY = '.wprism/contract';
    public const CONTRACT_FILE = 'contract.json';
    public const PROPOSAL_FILE = 'proposed.json';
    public const PROJECTION_FILE = 'projection.json';

    /**
     * The charset an environment name must match before it becomes a path
     * segment. Byte-identical to the registry's own rule
     * (Registry.php:103), which is what makes this a defence-in-depth
     * check rather than a second, divergent opinion: every name that
     * reaches here through `EnvironmentDriver::name()` has already passed
     * it, and the point of repeating it is that this is where the value
     * stops being a name and starts being a directory.
     */
    private const ENVIRONMENT_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D';

    private string $siteRepo;

    public function __construct(string $siteRepo) {
        $resolved = realpath($siteRepo);
        if ($resolved === false || !is_dir($resolved)) {
            throw new CommandRefusalException(
                'site_repo_missing',
                'the site repository directory does not exist',
                'run this command from inside the site repository, or pass an existing repository path',
                [],
                "site repo not found: $siteRepo"
            );
        }
        $this->siteRepo = $resolved;
    }

    public function directory(): string {
        return $this->siteRepo . '/' . self::DIRECTORY;
    }

    public function contractPath(): string {
        return $this->directory() . '/' . self::CONTRACT_FILE;
    }

    /** The proposal for one environment: `.wprism/contract/<env>/proposed.json`. */
    public function proposalPath(string $environment): string {
        return $this->directory() . '/' . self::segment($environment) . '/' . self::PROPOSAL_FILE;
    }

    /**
     * The same path relative to the site repository, for the three places
     * that print it to an operator (AssessCommand::run(),
     * ContractCommand::propose(), ReleaseCommand::prepare()). Rendering it
     * here rather than at each call site is what keeps the printed promise
     * and the written file from drifting apart.
     */
    public function proposalRelativePath(string $environment): string {
        return self::DIRECTORY . '/' . self::segment($environment) . '/' . self::PROPOSAL_FILE;
    }

    public function projectionPath(): string {
        return $this->directory() . '/' . self::PROJECTION_FILE;
    }

    /**
     * The accepted contract, validated — and VERIFIED when it claims to be
     * signed — or null when the site has none yet.
     *
     * This is the single reader every consumer goes through
     * (ContractCommand::show():114, VerifyCommand:158, AssessCommand:174,
     * ReleaseCommand:257), which is why the attestation check belongs here and
     * not at each call site: a byte edited under a signed contract must DROP
     * the claim at every consumer at once, not degrade it at some of them.
     * The check is gated on `state === 'signed'`, so the unsigned path — every
     * shipped site — does exactly the same work, the same I/O and the same
     * refusals it did before this existed.
     *
     * @param string|null $manifestDir the manifest library to re-bind the
     *        attested platform boundary against; null is this checkout's own
     */
    public function readContract(?string $manifestDir = null): ?array {
        $raw = $this->readIfPresent($this->contractPath());
        if ($raw === null) {
            return null;
        }
        $document = ApplicationContract::parse($raw);
        if (($document['attestation']['state'] ?? null) === 'signed') {
            ContractAttestation::verify($document, $this->siteRepo, $manifestDir);
        }

        return $document;
    }

    /**
     * The stored contract, shape-validated but NOT attestation-verified.
     *
     * Exactly one caller: `wprism contract <env> attest`. Every verification
     * refusal this build can raise — a moved platform boundary, an expired
     * attestation, a revoked key — has re-attesting as its remedy, so the verb
     * that re-attests has to be able to read the document it is about to
     * re-sign. Making it go through `readContract()` would mean the only
     * command that can fix the state is the one command the state locks out.
     *
     * Nothing is loosened by that: `writeAttestedContract()` verifies the
     * OUTPUT before it lands, so the bytes that reach disk are still bytes a
     * reader will accept.
     *
     * @return array<string,mixed>|null
     */
    public function readContractUnverified(): ?array {
        $raw = $this->readIfPresent($this->contractPath());

        return $raw === null ? null : ApplicationContract::parse($raw);
    }

    /**
     * The digest of the contract currently on disk, or null when absent.
     *
     * Deliberately does not validate: the CAS token must be readable even
     * from a document this build would refuse, or a stale-schema contract
     * could be silently overwritten by a caller that never saw it.
     */
    public function currentDigest(): ?string {
        $raw = $this->readIfPresent($this->contractPath());
        if ($raw === null) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !is_string($decoded['contract_digest'] ?? null)) {
            throw new CommandRefusalException(
                'contract_unreadable',
                'the stored application contract has no readable contract_digest',
                'restore .wprism/contract/contract.json from git, or remove it and re-accept a fresh proposal'
            );
        }

        return $decoded['contract_digest'];
    }

    /** @return array<string,mixed>|null */
    public function readProposal(string $environment): ?array {
        return $this->readJson($this->proposalPath($environment));
    }

    /** @return array<string,mixed>|null */
    public function readProjection(): ?array {
        return $this->readJson($this->projectionPath());
    }

    /**
     * Write the accepted contract under compare-and-swap.
     *
     * @param array<string,mixed> $document a validated contract
     * @param string|null $expectedDigest the `contract_digest` the caller
     *        read before it began, or null when the caller read no contract
     *        at all (a first accept). Passing null against an existing
     *        contract is the classic lost-update and refuses.
     */
    public function writeContract(array $document, ?string $expectedDigest): void {
        ApplicationContract::validate($document);
        if (($document['attestation']['state'] ?? null) !== 'unsigned') {
            // MUP §7/§8: the certification gate is deferred, so nothing in
            // this build can produce a signature worth trusting. Parsing a
            // signed contract stays legal; minting one does not.
            throw new CommandRefusalException(
                'attestation_signing_unsupported',
                'this build only writes an unsigned contract attestation',
                'accept the proposal unchanged; signed attestation arrives with the certification gate'
            );
        }

        $current = $this->currentDigest();
        if ($current !== $expectedDigest) {
            throw new CommandRefusalException(
                'contract_digest_stale',
                'the stored application contract changed since it was read',
                're-run wprism assess and wprism contract propose, review the fresh proposal, then accept it',
                [['expected' => $expectedDigest ?? 'none', 'stored' => $current ?? 'none']]
            );
        }

        $this->writeAtomic($this->contractPath(), Canon::encode($document));
    }

    /**
     * The ONE door a `signed` contract enters by.
     *
     * `writeContract()` above refuses every signed document and keeps
     * refusing: accepting a reviewed proposal is not an act that can produce a
     * signature, and the refusal it raises still means exactly what it says.
     * This method is the attest verb's, and it verifies its own input through
     * the same `ContractAttestation::verify()` that every later read will run
     * — the `AdapterCertify::certify()` precedent (:340-348), where a producer
     * that trusted its own bytes would put the one document nobody checked
     * into the repository.
     *
     * @param array<string,mixed> $document a signed, validated contract
     * @param string|null $expectedDigest the CAS token, as writeContract()
     * @param string|null $manifestDir the manifest library to bind against
     */
    public function writeAttestedContract(
        array $document,
        ?string $expectedDigest,
        ?string $manifestDir = null
    ): void {
        ApplicationContract::validate($document);
        if (($document['attestation']['state'] ?? null) !== 'signed') {
            // Not a fallback into writeContract(): a caller that reached this
            // door with an unsigned document is a caller whose intent and
            // whose bytes disagree, and silently writing the unsigned one
            // would report success for an attestation that never happened.
            throw new CommandRefusalException(
                'contract_attestation_signature_invalid',
                'this entry point writes a signed contract attestation only',
                'accept an unsigned contract with wprism contract <env> accept, or attest a signed one'
            );
        }
        ContractAttestation::verify($document, $this->siteRepo, $manifestDir);

        $current = $this->currentDigest();
        if ($current !== $expectedDigest) {
            throw new CommandRefusalException(
                'contract_digest_stale',
                'the stored application contract changed since it was read',
                're-run wprism assess and wprism contract propose, review the fresh proposal, then accept it',
                [['expected' => $expectedDigest ?? 'none', 'stored' => $current ?? 'none']]
            );
        }

        $this->writeAtomic($this->contractPath(), Canon::encode($document));
    }

    /** @param array<string,mixed> $document */
    public function writeProposal(string $environment, array $document): void {
        // writeAtomic() creates the directory it is handed (:273), so the
        // new `.wprism/contract/<env>/` level needs no separate mkdir here.
        $this->writeAtomic($this->proposalPath($environment), Canon::encode($document));
    }

    /** @param array<string,mixed> $document */
    public function writeProjection(array $document): void {
        $this->writeAtomic($this->projectionPath(), Canon::encode($document));
    }

    /**
     * The environment as one path segment, refused unless it is one.
     *
     * The value is about to be joined into a filesystem path, so an
     * unchecked name is a traversal (`../../etc`) or a name no filesystem
     * accepts. Refusing beats sanitizing: a silently rewritten segment
     * means the file the operator was told about and the file that was
     * written are different files. Follows this class's `site_repo_missing`
     * (:80) in keeping the offending value operator-only.
     */
    private static function segment(string $environment): string {
        if (preg_match(self::ENVIRONMENT_PATTERN, $environment) !== 1) {
            throw new CommandRefusalException(
                'contract_environment_invalid',
                'the environment name is not a legal path segment',
                'rename the environment in site.wprism.json to match [A-Za-z0-9][A-Za-z0-9._-]{0,63}',
                [],
                "illegal environment path segment: $environment"
            );
        }

        return $environment;
    }

    /** @return array<string,mixed>|null */
    private function readJson(string $path): ?array {
        $raw = $this->readIfPresent($path);
        if ($raw === null) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new CommandRefusalException(
                'contract_document_unreadable',
                'a document under .wprism/contract/ is not a JSON object',
                'restore the file from git, or regenerate it with wprism contract propose',
                [['file' => basename($path)]]
            );
        }

        return $decoded;
    }

    private function readIfPresent(string $path): ?string {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new CommandRefusalException(
                'contract_document_unreadable',
                'a document under .wprism/contract/ could not be read',
                'check the file permissions on the .wprism/contract directory',
                [['file' => basename($path)]]
            );
        }

        return $raw;
    }

    /**
     * Create the directory if needed, then commit through a same-directory
     * temporary file. `tempnam()` in the destination directory guarantees
     * `rename()` stays inside one filesystem, which is what makes it atomic.
     */
    private function writeAtomic(string $path, string $contents): void {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new CommandRefusalException(
                'contract_directory_unwritable',
                'the .wprism/contract directory could not be created',
                'check write permission on the site repository working tree'
            );
        }
        $temporary = @tempnam($directory, '.wprism-contract-');
        if (!is_string($temporary) || $temporary === '') {
            throw new CommandRefusalException(
                'contract_write_failed',
                'a temporary file could not be created beside the contract',
                'check write permission and free space on the site repository working tree'
            );
        }
        try {
            if (@file_put_contents($temporary, $contents, LOCK_EX) === false) {
                throw new CommandRefusalException(
                    'contract_write_failed',
                    'the contract document could not be written',
                    'check write permission and free space on the site repository working tree'
                );
            }
            // tempnam() creates 0600; these documents are committed review
            // artifacts read by every team member, so widen to the process
            // umask's ordinary file mode before publishing them.
            @chmod($temporary, 0666 & ~umask());
            if (!@rename($temporary, $path)) {
                throw new CommandRefusalException(
                    'contract_write_failed',
                    'the contract document could not be published atomically',
                    'check write permission on the .wprism/contract directory'
                );
            }
            $temporary = null;
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}

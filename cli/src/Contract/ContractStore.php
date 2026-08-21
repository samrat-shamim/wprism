<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once __DIR__ . '/ApplicationContract.php';

use Duo\Canon;
use Duo\CommandRefusalException;

/**
 * The `<siteRepo>/.duo/contract/` directory as an object (round-3 MUP §3.1).
 *
 * Three documents live here — `contract.json` (reviewed authority),
 * `proposed.json` (assess output, never authoritative) and `projection.json`
 * (generated, a review artifact). All three are canonical JSON through
 * `\Duo\Canon` so they diff and merge like the rest of `state/`.
 *
 * **Two tiers, and the directory says which.** `contract.json` and
 * `projection.json` are PER SITE and sit directly in `.duo/contract/`: the
 * contract is one reviewed statement about this repository, and
 * `environment_bindings.required[]` is how it says what varies per
 * environment (ApplicationContract.php:428-441). `proposed.json` is PER
 * ENVIRONMENT and sits in `.duo/contract/<env>/`, because the document is
 * environment-specific in two independent ways: it stamps `environment`
 * from the report (ContractProposal.php:185), and the `assess_digest` it
 * binds itself with covers `env` plus `target.home`/`target.siteurl`
 * (AssessReport.php:103, ContractProposal.php:113). One slot for N
 * environments meant `duo assess <other-env>` silently overwrote a
 * reviewed-but-unaccepted proposal — unrecoverably, since `/.duo/` is
 * inside the site repo's own ignore (InitRepositoryBoundary.php:247) — and
 * the next accept blamed the site with `contract_proposal_stale` (DUO-3503).
 * That is why the three proposal methods take the environment as a REQUIRED
 * argument: a default would let the shared slot back in by omission.
 *
 * Two mechanics are the reason this is a class rather than three
 * `file_put_contents()` calls.
 *
 * **Write-then-rename.** `rename(2)` within one directory is atomic, so a
 * reader either sees the whole previous document or the whole new one, never
 * a truncated middle. `file_put_contents()` straight onto the destination
 * has a real window in which `contract.json` is zero bytes, and the file it
 * would truncate is the document `duo release` cites in a frozen
 * authorization plan.
 *
 * **Compare-and-swap on `contract_digest`.** Accepting a contract is a
 * read-modify-write across a human review step that can take minutes, and
 * `.duo/contract/` is a git working tree two operators (or an operator and
 * an agent) can both be sitting in. The caller states the digest it read;
 * if the on-disk digest has moved since, the write refuses instead of
 * silently discarding the other writer's review. A refusal here is cheap —
 * re-run assess — while a lost reviewed declaration is exactly the kind of
 * unknown MUP §1.6 exists to keep out of production.
 */
final class ContractStore {
    public const DIRECTORY = '.duo/contract';
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

    /** The proposal for one environment: `.duo/contract/<env>/proposed.json`. */
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

    /** The accepted contract, validated, or null when the site has none yet. */
    public function readContract(): ?array {
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
                'restore .duo/contract/contract.json from git, or remove it and re-accept a fresh proposal'
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
                're-run duo assess and duo contract propose, review the fresh proposal, then accept it',
                [['expected' => $expectedDigest ?? 'none', 'stored' => $current ?? 'none']]
            );
        }

        $this->writeAtomic($this->contractPath(), Canon::encode($document));
    }

    /** @param array<string,mixed> $document */
    public function writeProposal(string $environment, array $document): void {
        // writeAtomic() creates the directory it is handed (:273), so the
        // new `.duo/contract/<env>/` level needs no separate mkdir here.
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
                'rename the environment in site.duo.json to match [A-Za-z0-9][A-Za-z0-9._-]{0,63}',
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
                'a document under .duo/contract/ is not a JSON object',
                'restore the file from git, or regenerate it with duo contract propose',
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
                'a document under .duo/contract/ could not be read',
                'check the file permissions on the .duo/contract directory',
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
                'the .duo/contract directory could not be created',
                'check write permission on the site repository working tree'
            );
        }
        $temporary = @tempnam($directory, '.duo-contract-');
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
                    'check write permission on the .duo/contract directory'
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

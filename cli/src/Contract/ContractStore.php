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

    public function proposalPath(): string {
        return $this->directory() . '/' . self::PROPOSAL_FILE;
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
    public function readProposal(): ?array {
        return $this->readJson($this->proposalPath());
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
    public function writeProposal(array $document): void {
        $this->writeAtomic($this->proposalPath(), Canon::encode($document));
    }

    /** @param array<string,mixed> $document */
    public function writeProjection(array $document): void {
        $this->writeAtomic($this->projectionPath(), Canon::encode($document));
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

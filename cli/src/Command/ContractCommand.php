<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/../Contract/ApplicationContract.php';
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
 */
final class ContractCommand {
    /** @var list<string> */
    public const SUBCOMMANDS = ['show', 'propose', 'accept'];

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
        AssessCommand::writeLocalArtifacts($result);
        $proposal = $result['store']->readProposal() ?? [];
        if ($json) {
            return [rtrim(Canon::encode($proposal), "\n")];
        }

        $lines = [
            'proposed contract written: ' . AssessCommand::PROPOSAL_PATH,
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

        $proposal = $store->readProposal();
        if ($proposal === null) {
            throw new CommandRefusalException(
                'contract_proposal_missing',
                'this site repository has no proposed application contract',
                'run duo contract <env> propose, review the proposal, then accept it'
            );
        }
        ContractProposal::validateProposal($proposal);

        // The digest the caller read before it began. `writeContract()`
        // refuses if the stored contract has moved since, which is the
        // lost-update guard across the human review step.
        $expectedDigest = $store->currentDigest();

        $result = self::freshAssessment($driver, $sourceRoot, $hostCatalog, $clock);
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

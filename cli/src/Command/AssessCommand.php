<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/../Transport/Transport.php';
require_once __DIR__ . '/../Onboarding/Doctor.php';
require_once __DIR__ . '/../Onboarding/BootstrapEligibility.php';
require_once __DIR__ . '/../Onboarding/Init.php';
require_once __DIR__ . '/../Adapter/AdapterCatalog.php';
require_once __DIR__ . '/../Assess/StackInventory.php';
require_once __DIR__ . '/../Assess/SurfaceCatalog.php';
require_once __DIR__ . '/../Assess/GapActions.php';
require_once __DIR__ . '/../Assess/AssessReport.php';
require_once __DIR__ . '/../Assess/AssessRenderer.php';
require_once __DIR__ . '/../Contract/ApplicationContract.php';
require_once __DIR__ . '/../Contract/ContractProjection.php';
require_once __DIR__ . '/../Contract/ContractProposal.php';
require_once __DIR__ . '/../Contract/ContractStore.php';
require_once __DIR__ . '/../Contract/ProjectionVocabulary.php';
require_once __DIR__ . '/CommandOutput.php';

use Duo\CommandRefusalException;

/**
 * `duo assess <env>` — the decision-first, read-only assessment
 * (round-3 MUP §2.1; verb boundary per the module map's rule 9).
 *
 * This class is the *only* place round 3 composes across modules for
 * assessment. `Doctor` (Onboarding), `BootstrapEligibility`/`Init`
 * (Onboarding), the environment driver (Transport) and `AdapterCatalog`
 * (Adapter) are all called here and their results handed to
 * `StackInventory`/`SurfaceCatalog`/`AssessReport` as data. That is not
 * style: it is what keeps `cli/src/Assess/` free of intra-layer edges, so
 * the module map's "zero new layer debt" claim survives round 3
 * (docs/modules/README.md rule 9).
 *
 * ## Composition order (MUP §2.1)
 *
 * The order is fixed and each step gates the next, so a broken transport
 * produces one refusal naming the transport rather than five downstream
 * failures naming symptoms:
 *
 *  1. `Doctor::run()` — transport, WordPress, agent, repository. A failing
 *     blocking check is a **structured refusal**: an assessment that could
 *     not reach the site is not a bounded assessment with blocked rows, it
 *     is no assessment at all. MUP §2.1's exit rule says so explicitly.
 *  2. Bootstrap and init probes — both read-only, both optional, both
 *     recorded with their reason when unavailable. An already-adopted site
 *     refuses `wp duo init`, which is the normal case and is reported as a
 *     fact, not an error.
 *  3. `wp duo assess-inventory --format=json` — the one new agent command.
 *     It carries `coverage` and `pending` inside it, which is why neither
 *     appears as a separate step: MUP §2.1 lists them as inputs, and the
 *     agent already composes them into one read-only pass.
 *  4. `wp duo capabilities --operation=<op> --format=json`, once per
 *     *distinct registry operation* the requested product operations map
 *     to — four calls for all six operations, not six.
 *  5. `AdapterCatalog::run(['list'])` on the host, offline; the target's
 *     own `adapter-survey` block already rode in with the inventory.
 *
 * Nothing in this command writes to the target. The only write it performs
 * is local: `.duo/contract/proposed.json` in the site repository, and
 * `.duo/contract/projection.json` when a contract has already been
 * accepted (MUP §3.4's refresh row).
 *
 * ## Exit status
 *
 * `0` for a bounded assessment — *including* one where every surface is
 * blocked. "Assessment is not a completeness claim": a site Duo can
 * describe honestly and cannot yet manage is a successful assessment, and
 * making it non-zero would train operators to ignore the exit code. `1`
 * only when the assessment itself refuses.
 */
final class AssessCommand {
    /** Where the proposal and projection live, relative to the site repo. */
    public const PROPOSAL_PATH = ContractStore::DIRECTORY . '/' . ContractStore::PROPOSAL_FILE;

    /** The operation whose projection the human table's columns show. */
    public const DEFAULT_VIEW_OPERATION = 'release';

    /** The composition steps, in order, as the suites assert them. */
    public const STEPS = [
        'doctor', 'bootstrap', 'init-probe', 'assess-inventory', 'capabilities', 'adapter-catalog',
    ];

    /**
     * @param list<string> $extra everything after `<env>`
     * @param string $sourceRoot this checkout's root (for the bootstrap probe)
     * @param callable(array):array|null $hostCatalog an injection seam for the
     *        host adapter catalog; null uses `AdapterCatalog` itself
     * @param callable():string|null $clock null reads the wall clock
     */
    public static function run(
        EnvironmentDriver $driver,
        array $extra,
        string $sourceRoot,
        ?callable $hostCatalog = null,
        ?callable $clock = null
    ): int {
        $json = self::wantsJson($extra);
        try {
            $limit = AssessRenderer::limitFromArgs(self::flags($extra, ['--limit', '--format', '--operation']));
            $operations = self::operationsFromArgs($extra);
            $viewOperation = self::viewOperation($extra, $operations);
            $result = self::assess($driver, [
                'operations' => $operations,
                'source_root' => $sourceRoot,
                'host_catalog' => $hostCatalog,
                'generated_at' => ($clock ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z'))(),
            ]);
            self::writeLocalArtifacts($result);
        } catch (CommandRefusalException $refusal) {
            return self::renderRefusal($refusal, $json);
        }

        if ($json) {
            echo AssessReport::encode($result['report']);

            return 0;
        }
        $lines = AssessRenderer::render($result['report'], $limit, [
            'proposal_path' => self::PROPOSAL_PATH,
            'contract_present' => $result['contract'] !== null,
            'unpinned_subjects' => $result['unpinned_subjects'],
            'operation' => $viewOperation,
        ]);
        foreach ($lines as $line) {
            echo $line . "\n";
        }

        return 0;
    }

    /**
     * The composition itself, shared with `ContractCommand`.
     *
     * `duo contract propose` and `duo contract accept` both need a fresh
     * assessment, and they call this function rather than re-invoking
     * `php cli/duo assess` as a subprocess: a subprocess would double every
     * target round-trip, lose the typed refusal, and make "the same
     * assessment" a claim about two processes agreeing rather than a fact.
     *
     * @param array<string,mixed> $options `operations`, `source_root`,
     *        `host_catalog`, `generated_at`, and optionally `manifests_dir`
     * @return array{report:array<string,mixed>,catalog:array<string,mixed>,
     *         registry_reports:array<string,array<string,mixed>>,inventory:array<string,mixed>,
     *         seed:array<string,mixed>,site_repo:string,store:ContractStore,
     *         contract:array<string,mixed>|null,operations:list<string>,
     *         unpinned_subjects:int,composition:list<string>}
     */
    public static function assess(EnvironmentDriver $driver, array $options): array {
        $composition = [];
        $operations = is_array($options['operations'] ?? null) && $options['operations'] !== []
            ? array_values($options['operations'])
            : ProjectionVocabulary::OPERATIONS;
        $generatedAt = (string) ($options['generated_at'] ?? gmdate('Y-m-d\TH:i:s\Z'));

        // The local site repository is resolved BEFORE the target is
        // contacted. An assessment that reaches a site, spends four
        // round-trips describing it, and then discovers it has nowhere to
        // write the proposal has wasted the operator's time to produce a
        // refusal it could have produced first.
        $siteRepo = self::siteRepo(getcwd() ?: '.');
        $store = new ContractStore($siteRepo);
        $contract = $store->readContract();

        $composition[] = 'doctor';
        $doctor = Doctor::run($driver);
        if (($doctor['ok'] ?? false) !== true) {
            throw new CommandRefusalException(
                'assess_target_unreachable',
                'the environment did not pass the checks an assessment reads from',
                'run duo doctor ' . self::token($driver->name()) . ', repair every failing check, then rerun assess',
                array_values(array_map(
                    static fn (array $check): array => [
                        'code' => 'doctor_check_failed',
                        'check' => (string) $check['label'],
                        'message' => 'a blocking environment check did not pass',
                        'remediation' => 'run duo doctor for this environment and read the check detail',
                    ],
                    array_filter(
                        is_array($doctor['checks'] ?? null) ? $doctor['checks'] : [],
                        static fn ($check): bool => is_array($check)
                            && ($check['ok'] ?? false) !== true
                            && ($check['advisory'] ?? false) !== true
                    )
                ))
            );
        }

        $composition[] = 'bootstrap';
        $bootstrap = self::bootstrapProbe($driver, (string) ($options['source_root'] ?? ''));

        $composition[] = 'init-probe';
        $initProbe = self::initProbe($driver);

        $composition[] = 'assess-inventory';
        $inventory = self::agentJson(
            $driver,
            ['duo', 'assess-inventory', '--repo=' . $driver->repoPath(), '--format=json'],
            'assess_inventory_unavailable',
            'the target could not produce a read-only assessment inventory'
        );
        if (($inventory['format'] ?? null) !== 'duo-assess-inventory/v1') {
            throw new CommandRefusalException(
                'assess_inventory_unavailable',
                'the target returned an inventory document this build does not read',
                'upgrade the target agent to a build that emits duo-assess-inventory/v1, then rerun assess'
            );
        }
        $target = StackInventory::stack($inventory);
        if ($target['site_mode'] !== 'single-site') {
            // MUP §2.1's own list of assessment refusals: missing access,
            // unsupported topology, multisite. The registry is single-site
            // only, so every row would carry `multisite_unsupported` and the
            // report would be a page-long restatement of one fact.
            throw new CommandRefusalException(
                'assess_topology_unsupported',
                'this profile assesses single-site installations only',
                'assess a single-site installation; multisite support is outside the certified boundary'
            );
        }

        $composition[] = 'capabilities';
        $registryReports = [];
        foreach (self::registryOperations($operations) as $registryOperation) {
            $registryReports[$registryOperation] = self::agentJson(
                $driver,
                [
                    'duo', 'capabilities', '--repo=' . $driver->repoPath(),
                    '--operation=' . $registryOperation, '--format=json',
                ],
                'assess_registry_unavailable',
                'the target could not evaluate its capability registry for this operation'
            );
        }

        $composition[] = 'adapter-catalog';
        $catalogDocument = self::hostCatalog($options['host_catalog'] ?? null);

        $catalog = SurfaceCatalog::catalog($inventory, $registryReports, $contract, [
            'operations' => $operations,
            // MUP §1.3 lists `Providers::diagnose()` negotiation problems as
            // a readiness input. The §2.1 composition has no source for
            // them — diagnose() runs inside plan/apply, which assess
            // deliberately does not — so this profile reports none, and the
            // seam stays explicit rather than being quietly absent.
            'provider_negotiation' => [],
        ]);

        $report = AssessReport::build(
            $driver->name(),
            $generatedAt,
            $target,
            StackInventory::authority([
                'environment' => $driver->name(),
                'driver' => $driver->driverId(),
                'transport' => $driver->describe(),
                'repo_path' => $driver->repoPath(),
                'site_repo' => $siteRepo,
                'doctor' => $doctor,
                'bootstrap' => $bootstrap,
                'init_probe' => $initProbe,
                'catalog' => $catalogDocument,
                'inventory' => $inventory,
            ]),
            $catalog['rows'],
            AssessReport::unknown($inventory),
            AssessReport::evidence($registryReports, self::registryProvenance($options))
        );

        return [
            'report' => $report,
            'catalog' => $catalog,
            'registry_reports' => $registryReports,
            'inventory' => $inventory,
            'seed' => AssessReport::proposalSeed($inventory, self::siteIdentity($siteRepo)),
            'site_repo' => $siteRepo,
            'store' => $store,
            'contract' => $contract,
            'operations' => $operations,
            'unpinned_subjects' => AssessReport::unpinnedSubjects($registryReports),
            'composition' => $composition,
        ];
    }

    /**
     * Write the two local artifacts an assessment owns.
     *
     * The proposal is always written — it is the output of the assessment,
     * never authoritative (MUP §3.1). The projection is regenerated only
     * when a contract has already been accepted, which is MUP §3.4's
     * "refresh" row: an assess that leaves a stale projection beside a
     * fresh assessment would let a reviewer read expired readiness out of a
     * committed file.
     *
     * @param array<string,mixed> $result an `assess()` result
     */
    public static function writeLocalArtifacts(array $result): void {
        /** @var ContractStore $store */
        $store = $result['store'];
        $store->writeProposal(ContractProposal::fromAssessReport($result['report'], $result['seed']));
        if ($result['contract'] === null) {
            return;
        }
        $store->writeProjection(self::projection($result));
    }

    /**
     * Generate `projection.json` from the accepted contract and this
     * assessment's own fact vectors.
     *
     * @param array<string,mixed> $result an `assess()` result
     * @param array<string,mixed>|null $contract overrides the stored contract
     * @return array<string,mixed>
     */
    public static function projection(array $result, ?array $contract = null): array {
        $contract ??= $result['contract'];
        if (!is_array($contract)) {
            throw new CommandRefusalException(
                'contract_missing',
                'this site repository has no accepted application contract',
                'run duo contract <env> propose, review the proposal, then duo contract <env> accept'
            );
        }
        $report = $result['report'];
        // Re-derive the catalog against the contract this projection is
        // about. `accept` reads the contract that existed BEFORE it wrote,
        // so a projection built from the assessment's own catalog would
        // describe the site as it was without the declarations just
        // accepted — the reviewed live-effect declaration would still read
        // `block`. The recomputation is pure and contacts nothing.
        $catalog = SurfaceCatalog::catalog(
            $result['inventory'],
            $result['registry_reports'],
            $contract,
            ['operations' => $result['operations'], 'provider_negotiation' => []]
        );

        return ContractProjection::generate(
            $contract,
            SurfaceCatalog::projectionFacts(
                $catalog,
                $result['operations'],
                (string) $report['evidence']['registry_sha256'],
                AssessReport::observedBundles($result['registry_reports'])
            ),
            ['wordpress' => (string) $report['target']['wordpress'], 'php' => (string) $report['target']['php']],
            $result['inventory'],
            (string) $report['generated_at']
        );
    }

    /**
     * The LOCAL site repository — the directory holding `site.duo.json`,
     * which is where `.duo/contract/` lives.
     *
     * This is deliberately not `$driver->repoPath()`: that is the *target's*
     * repository path, as seen from inside the target, and on a docker or
     * ssh environment it names a directory this process cannot write to and
     * may not even exist here. The rule matches `Registry::load()`'s
     * exactly — walk upward for `site.duo.json`, and inside Git accept it
     * only at the worktree root — because a contract written under a nested
     * repository would authorize a site whose environments are controlled
     * somewhere else.
     */
    public static function siteRepo(string $startDir): string {
        $dir = realpath($startDir) ?: $startDir;
        $siteFile = null;
        $cursor = $dir;
        while (true) {
            if (is_file($cursor . '/site.duo.json')) {
                $siteFile = $cursor . '/site.duo.json';
                break;
            }
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                break;
            }
            $cursor = $parent;
        }
        if ($siteFile === null) {
            throw new CommandRefusalException(
                'local_site_repo_missing',
                'no site.duo.json was found at or above the current directory',
                'run this command from inside the site repository that holds site.duo.json'
            );
        }
        $root = self::gitRoot($dir);
        if ($root !== null && dirname($siteFile) !== $root) {
            throw new CommandRefusalException(
                'local_site_repo_nested',
                'the site.duo.json found is not at the Git worktree root',
                'move the site registry to the root of the repository whose environments it controls'
            );
        }

        return dirname($siteFile);
    }

    /** Refuse anything that is not a Git worktree root (MUP §2.6's stage step). */
    public static function requireGitWorktreeRoot(string $siteRepo): void {
        if (self::gitRoot($siteRepo) !== $siteRepo) {
            throw new CommandRefusalException(
                'site_repo_not_git_root',
                'the site repository is not the root of a Git worktree',
                'initialize or check out the site repository as a Git worktree before accepting a contract; '
                    . 'the accepted contract and its projection are review artifacts that must be committed'
            );
        }
    }

    /** @return list<string> */
    public static function registryOperations(array $operations): array {
        $out = [];
        foreach ($operations as $operation) {
            $registryOperation = SurfaceCatalog::REGISTRY_OPERATION[$operation] ?? null;
            if ($registryOperation === null) {
                throw new CommandRefusalException(
                    'invalid_arguments',
                    'assess was asked for an operation this profile does not project',
                    'request one or more of ' . implode(', ', ProjectionVocabulary::OPERATIONS)
                );
            }
            $out[$registryOperation] = true;
        }
        ksort($out, SORT_STRING);

        return array_keys($out);
    }

    /** Render a typed refusal in whichever channel the operator asked for. */
    public static function renderRefusal(CommandRefusalException $refusal, bool $json, string $command = 'assess'): int {
        if ($json) {
            return CommandOutput::renderRefusalJson(
                $command,
                $refusal->reasonCode,
                $refusal->publicMessage,
                $refusal->remediation,
                $refusal->diagnostics
            );
        }
        fwrite(STDERR, '[' . $refusal->reasonCode . '] ' . $refusal->publicMessage . "\n");
        foreach ($refusal->diagnostics as $diagnostic) {
            if (!is_array($diagnostic)) {
                continue;
            }
            // A diagnostic is a reviewed public bag, not a fixed shape:
            // some carry code+message, others carry only the key path that
            // failed. Rendering whatever scalars are present keeps the one
            // that names the actual location from printing as an empty line.
            $parts = [];
            foreach ($diagnostic as $key => $value) {
                if (is_scalar($value)) {
                    $parts[] = (string) $key . '=' . (string) $value;
                }
            }
            if ($parts !== []) {
                fwrite(STDERR, '  - ' . implode(' ', $parts) . "\n");
            }
        }
        fwrite(STDERR, 'remedy: ' . $refusal->remediation . "\n");

        return 1;
    }

    public static function wantsJson(array $extra): bool {
        foreach ($extra as $index => $arg) {
            if ($arg === '--json' || $arg === '--format=json'
                || ($arg === '--format' && ($extra[$index + 1] ?? null) === 'json')) {
                return true;
            }
        }

        return false;
    }

    /**
     * One read-only bootstrap probe, or a stated reason there was none.
     *
     * `BootstrapEligibilityReport::inspect()` only reads — it proves a safe
     * topology before `duo adopt` would write — but it is reachable only on
     * a transport that implements `AdoptionTransport`. A docker environment
     * does not, and saying so is more useful than an absent key.
     *
     * @return array<string,mixed>
     */
    private static function bootstrapProbe(EnvironmentDriver $driver, string $sourceRoot): array {
        if (!$driver instanceof AdoptionTransport || $sourceRoot === '') {
            return [
                'probed' => false,
                'ready' => null,
                'blockers' => [],
                'reason' => 'this driver exposes no read-only adoption probe',
            ];
        }
        try {
            $eligibility = BootstrapEligibilityReport::inspect(
                $driver,
                $driver->name(),
                $driver->driverId(),
                $sourceRoot
            );

            return [
                'probed' => true,
                'ready' => $eligibility->ready(),
                'blockers' => array_values(array_map(
                    static fn (array $blocker): string => (string) ($blocker['code'] ?? 'blocker'),
                    $eligibility->blockers()
                )),
                'reason' => null,
            ];
        } catch (\Throwable) {
            // A probe that cannot run is a fact about this environment, not
            // a failed assessment. The exception text is operator-only (it
            // carries paths) and is deliberately not promoted here.
            return [
                'probed' => false,
                'ready' => null,
                'blockers' => [],
                'reason' => 'the adoption probe could not complete on this environment',
            ];
        }
    }

    /**
     * The `wp duo init` proposal read, which is read-only by construction:
     * without `--confirm=<digest>` the agent computes and returns a plan and
     * writes nothing.
     *
     * An already-owned repository refuses it, which is the normal state of
     * every site an operator assesses. That refusal is recorded by its
     * reason code — a machine-readable fact — and never treated as an
     * assessment failure.
     *
     * @return array<string,mixed>
     */
    private static function initProbe(EnvironmentDriver $driver): array {
        try {
            $proposal = Init::proposal($driver);

            return [
                'probed' => true,
                'ready' => ($proposal['ready'] ?? false) === true,
                'reason_code' => null,
            ];
        } catch (InitRefusalException $refusal) {
            $body = $refusal->refusal;

            return [
                'probed' => true,
                'ready' => false,
                'reason_code' => is_string($body['reason_code'] ?? null) ? $body['reason_code'] : 'init_refused',
            ];
        } catch (\Throwable) {
            return ['probed' => false, 'ready' => null, 'reason_code' => 'init_probe_unavailable'];
        }
    }

    /**
     * The host adapter catalog, captured as a document.
     *
     * `AdapterCatalog::run()` is the module's only entry point and it writes
     * to stdout, so the output is buffered here rather than a second, quiet
     * catalog reader being built beside it — the assessment must report the
     * same source list `duo adapter list` reports, and the way to guarantee
     * that is to call it.
     *
     * @param callable(array):array|null $injected
     * @return array<string,mixed>|null
     */
    private static function hostCatalog(?callable $injected): ?array {
        if ($injected !== null) {
            $document = $injected(['list', '--format=json']);

            return is_array($document) ? $document : null;
        }
        ob_start();
        try {
            AdapterCatalog::run(['list', '--format=json']);
        } catch (\Throwable) {
            ob_end_clean();

            return null;
        }
        $raw = (string) ob_get_clean();
        $decoded = json_decode(trim($raw), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * One `wp duo …` JSON read, with the agent's own refusal preserved.
     *
     * The agent answers a refusal with `duo-command-refusal/v1` on stdout,
     * so it is decoded first and re-raised with its own reason code: an
     * assessment that reported `assess_inventory_unavailable` when the
     * target actually said `multisite_unsupported` would send the operator
     * to the wrong problem.
     *
     * @param list<string> $args
     * @return array<string,mixed>
     */
    private static function agentJson(
        EnvironmentDriver $driver,
        array $args,
        string $fallbackCode,
        string $fallbackMessage
    ): array {
        $result = $driver->captureWp($args);
        if ($result['exit'] !== 0) {
            $refusal = json_decode(trim((string) $result['stdout']), true);
            if (is_array($refusal) && ($refusal['format'] ?? null) === 'duo-command-refusal/v1') {
                throw new CommandRefusalException(
                    self::reasonCode($refusal, $fallbackCode),
                    (string) ($refusal['message'] ?? $fallbackMessage),
                    (string) ($refusal['remediation'] ?? 'repair the target, then rerun assess'),
                    array_values(array_filter(
                        (array) ($refusal['diagnostics'] ?? []),
                        'is_array'
                    ))
                );
            }
            throw new CommandRefusalException(
                $fallbackCode,
                $fallbackMessage,
                'run duo doctor ' . self::token($driver->name()) . ' and repair the target, then rerun assess'
            );
        }
        $decoded = json_decode(trim((string) $result['stdout']), true);
        if (!is_array($decoded)) {
            throw new CommandRefusalException(
                $fallbackCode,
                'the target returned output this build could not parse as JSON',
                'upgrade the target agent, then rerun assess'
            );
        }

        return $decoded;
    }

    /** @param array<string,mixed> $refusal */
    private static function reasonCode(array $refusal, string $fallback): string {
        $code = $refusal['reason_code'] ?? $refusal['error'] ?? null;

        return is_string($code) && preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $code) === 1 ? $code : $fallback;
    }

    /**
     * The generator provenance of the shipped capability registry.
     *
     * Read host-side because that is the only place it exists: the triple
     * names the *inputs* the registry was generated from, and those inputs
     * live beside the generator, never on a target. The pin that must flip
     * when a site changes is `registry_sha256`, which is read from the
     * target's own report.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private static function registryProvenance(array $options): array {
        $path = (string) ($options['manifests_dir'] ?? dirname(__DIR__, 3) . '/manifests')
            . '/capabilities/registry.json';
        $raw = is_file($path) ? @file_get_contents($path) : false;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded) || !is_array($decoded['generated_from'] ?? null)) {
            throw new CommandRefusalException(
                'capability_registry_unreadable',
                'the shipped capability registry could not be read for its generator provenance',
                'restore manifests/capabilities/registry.json in this checkout, then rerun assess'
            );
        }

        return $decoded['generated_from'];
    }

    /**
     * The site's own identity for the proposed contract.
     *
     * `site.duo.json` carries no name — it is the wire contract for state,
     * not a project file — so the repository directory name is used, which
     * is the name the operator already types. `spec_version` is read from
     * the file, because it is the one integer that must equal the engine's
     * own and a contract stating a different one would be reviewed against
     * the wrong grammar.
     *
     * @return array{name:string,spec_version:int}
     */
    private static function siteIdentity(string $siteRepo): array {
        $raw = @file_get_contents($siteRepo . '/site.duo.json');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded) || !is_int($decoded['spec_version'] ?? null)) {
            throw new CommandRefusalException(
                'site_policy_unreadable',
                'the local site.duo.json could not be read, or declares no integer spec_version',
                'repair site.duo.json in the site repository, then rerun assess'
            );
        }

        return ['name' => basename($siteRepo), 'spec_version' => $decoded['spec_version']];
    }

    /**
     * The requested product operations.
     *
     * @param list<string> $extra
     * @return list<string>
     */
    private static function operationsFromArgs(array $extra): array {
        $requested = null;
        foreach ($extra as $arg) {
            if (!is_string($arg) || !str_starts_with($arg, '--operation')) {
                continue;
            }
            if ($requested !== null || !str_starts_with($arg, '--operation=')) {
                throw new CommandRefusalException(
                    'invalid_arguments',
                    'assess accepts at most one --operation=<csv>',
                    'supply a single comma-separated --operation list, or omit it for every operation'
                );
            }
            $requested = array_values(array_filter(
                array_map('trim', explode(',', substr($arg, strlen('--operation=')))),
                static fn (string $value): bool => $value !== ''
            ));
        }
        if ($requested === null || $requested === []) {
            return ProjectionVocabulary::OPERATIONS;
        }
        foreach ($requested as $operation) {
            if (!in_array($operation, ProjectionVocabulary::OPERATIONS, true)) {
                throw new CommandRefusalException(
                    'invalid_arguments',
                    'assess was asked for an operation this profile does not project',
                    'request one or more of ' . implode(', ', ProjectionVocabulary::OPERATIONS)
                );
            }
        }

        return $requested;
    }

    /** @param list<string> $operations */
    private static function viewOperation(array $extra, array $operations): string {
        if (in_array(self::DEFAULT_VIEW_OPERATION, $operations, true)) {
            return self::DEFAULT_VIEW_OPERATION;
        }

        return (string) $operations[0];
    }

    /**
     * Reject anything that is not one of this verb's own flags, so a
     * mistyped option is a refusal rather than a silently ignored request.
     *
     * @param list<string> $extra
     * @param list<string> $allowed
     * @return list<string>
     */
    private static function flags(array $extra, array $allowed): array {
        $out = [];
        foreach ($extra as $arg) {
            if (!is_string($arg)) {
                continue;
            }
            $name = str_contains($arg, '=') ? explode('=', $arg, 2)[0] : $arg;
            if ($arg === 'json' || $name === '--json') {
                continue;
            }
            if (!in_array($name, $allowed, true)) {
                throw new CommandRefusalException(
                    'invalid_arguments',
                    'assess received an option it does not define',
                    'assess accepts --format=json, --limit=<1..200> and --operation=<csv>'
                );
            }
            $out[] = $arg;
        }

        return $out;
    }

    private static function gitRoot(string $startDir): ?string {
        $dir = realpath($startDir) ?: $startDir;
        while (true) {
            if (is_dir($dir . '/.git') || is_file($dir . '/.git')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                return null;
            }
            $dir = $parent;
        }
    }

    /** An environment name safe to print inside a remediation sentence. */
    private static function token(string $value): string {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $value) === 1 ? $value : '<env>';
    }
}

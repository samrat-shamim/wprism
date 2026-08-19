<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/../Plan/PlanContract.php';
require_once __DIR__ . '/../Contract/ContractStore.php';
require_once __DIR__ . '/../Release/AuthorizationPlan.php';
require_once __DIR__ . '/../Release/JourneyOracle.php';
require_once __DIR__ . '/AssessCommand.php';
require_once __DIR__ . '/CommandOutput.php';

use Duo\CommandRefusalException;

/**
 * `duo verify <env>` — post-release verification (round-3 MUP §2.4).
 *
 * Two independent parts, BOTH required for a pass, exactly as §2.4 states:
 * a convergence re-read and the contract-declared affected-journey oracles.
 * `JourneyOracle::verdict()` owns the conjunction; this class owns only the
 * composition and the two evidence reads.
 *
 * ## The convergence half, and one deliberate deviation
 *
 * §2.4 names "the existing internal `wp duo verify-canonical` path
 * (`\Duo\ConvergenceVerifier`)". That command is reachable from a target, but
 * it is not reachable from a HOST: `agent/src/Command/Cli.php::verify_canonical()`
 * requires `--expected-artifact`, `--compiled` and `--policy-snapshot`, and
 * `agent/src/Review/ConvergenceVerifier.php:87-110` shows where the last two
 * come from — two `tempnam()` files that the MUTATING apply process writes
 * from its own in-memory `CompiledRepository` and frozen `Policy`, and
 * deletes in a `finally`. No shipped `wp duo` subcommand exports a
 * `duo-policy-snapshot/v6` (`agent/src/Policy/Policy.php:183`; the v5
 * generation this line used to name froze a generated capability registry and
 * is now refused outright), so a host cannot supply that input, and MUP §2.4
 * forbids adding an agent command to make one ("Host-side only; no new agent
 * code").
 *
 * So the host uses the other positive re-read the agent already publishes and
 * that the promotion path itself already treats as convergence proof: one
 * read-only `wp duo plan --format=json`. `cli/duo`'s
 * `frozen_promotion_reconcile()` states the rule in its own words — "a clean
 * exact plan proves the prior apply converged" — because `plan` recaptures
 * owned state from the live site and compares it with the compiled tree. It
 * is a fresh re-read producing a positive statement, not the absence of an
 * error, which is the property §2.4 actually demands.
 *
 * The report says so rather than hiding it: the convergence block carries
 * `verifier: "plan-reconciliation/v1"`, never `canonical-recapture/v1`.
 * `\Duo\ConvergenceVerifier`'s byte-level recapture still runs — inside the
 * apply that the release performed, where it fails closed — and that is
 * stated in the disclosure line, so an operator is never told this command
 * ran a verifier it did not run.
 *
 * ## The journey half
 *
 * The journeys come from the accepted contract. With `--plan=<digest>` the
 * frozen authorization plan selects them instead: the plan names the contract
 * digest it was authorized against, and a contract whose digest has since
 * moved is refused rather than silently substituted — verifying a release
 * against journeys nobody authorized would make the report a different
 * document with the same name.
 *
 * Exit status: `0` when the verdict is `pass`, `1` on a `fail` or a refusal.
 */
final class VerifyCommand {
    /** The verifier name this profile's convergence half honestly reports. */
    public const CONVERGENCE_VERIFIER = 'plan-reconciliation/v1';

    /**
     * The plan buckets whose emptiness IS convergence.
     *
     * Every one of them is a difference between the compiled tree and the
     * live site. `unchanged`, `deleted`, `uploads_inventory` and the advisory
     * projections are deliberately absent: they are the converged state, not
     * a divergence from it.
     *
     * @var list<string>
     */
    public const DIVERGENCE_BUCKETS = [
        'adopt', 'collision', 'conflict', 'create', 'delete', 'delete_conflict',
        'drift', 'incomplete_apply', 'incomplete_lifecycle', 'regen_pending', 'update',
    ];

    /** The disclosure that names what this profile's convergence half is. */
    public const CONVERGENCE_DISCLOSURE =
        'convergence here is a fresh read-only plan re-read (plan-reconciliation/v1); the byte-level '
        . 'canonical recapture runs inside the release\'s own apply, where it fails closed';

    /**
     * @param list<string> $extra everything after `<env>`
     * @param ?callable(string,int):array $fetch the journey probe seam;
     *        null uses `JourneyOracle::fetch()`
     */
    public static function run(EnvironmentDriver $driver, array $extra, ?callable $fetch = null): int {
        $json = AssessCommand::wantsJson($extra);
        try {
            $flags = self::flags($extra);
            $report = self::report($driver, [
                'fetch' => $fetch,
                'limit' => $flags['limit'],
                'plan_digest' => $flags['plan'],
            ]);
        } catch (CommandRefusalException $refusal) {
            return AssessCommand::renderRefusal($refusal, $json, 'verify');
        }

        if ($json) {
            echo JourneyOracle::encode($report);

            return $report['verdict'] === JourneyOracle::PASS ? 0 : 1;
        }
        foreach (JourneyOracle::humanLines($report, $flags['limit']) as $line) {
            echo $line . "\n";
        }

        return $report['verdict'] === JourneyOracle::PASS ? 0 : 1;
    }

    /**
     * Produce the `duo-verify-report/v1` document.
     *
     * Shared with `ReleaseCommand`, which calls it as MUP §2.3 step 5 with
     * the contract and scope it already holds rather than re-reading them —
     * a release that verified against a contract it re-read from disk after
     * mutating would be verifying against a document nobody authorized.
     *
     * @param array<string,mixed> $options keys: `contract` (?array),
     *        `plan_digest` (?string), `scope_surfaces` (list<string>),
     *        `fetch` (?callable), `timeout` (int)
     * @return array<string,mixed>
     */
    public static function report(EnvironmentDriver $driver, array $options): array {
        $siteRepo = AssessCommand::siteRepo(getcwd() ?: '.');
        $planDigest = is_string($options['plan_digest'] ?? null) ? (string) $options['plan_digest'] : null;
        $scopeSurfaces = is_array($options['scope_surfaces'] ?? null)
            ? array_values(array_map('strval', $options['scope_surfaces']))
            : null;

        $contract = is_array($options['contract'] ?? null) ? $options['contract'] : null;
        $frozen = null;
        if ($planDigest !== null) {
            $frozen = AuthorizationPlan::read($siteRepo, $planDigest);
            if ($frozen === null) {
                throw new CommandRefusalException(
                    'verify_plan_unknown',
                    'no frozen authorization plan with that digest exists in this site repository',
                    'run duo verify without --plan, or restore .duo/releases from the commit that recorded '
                        . 'this release'
                );
            }
            $planDigest = (string) $frozen['plan_digest'];
            $scopeSurfaces ??= array_values(array_map('strval', (array) ($frozen['scope']['surfaces'] ?? [])));
        }
        if ($contract === null) {
            $store = new ContractStore($siteRepo);
            $contract = $store->readContract();
        }
        if ($contract === null) {
            throw new CommandRefusalException(
                'contract_missing',
                'this site repository has no accepted application contract, so no journey is declared to verify',
                'run duo contract ' . self::token($driver->name())
                    . ' propose, review the proposal, then duo contract '
                    . self::token($driver->name()) . ' accept'
            );
        }
        if ($frozen !== null) {
            self::assertContractBinding($frozen, $contract);
        }

        $plan = self::targetPlan($driver);
        $convergence = JourneyOracle::convergence(self::convergenceSummary($plan));

        $journeys = self::journeys($contract);
        JourneyOracle::validateJourneys($journeys);
        $rows = [];
        if ($journeys !== []) {
            $rows = JourneyOracle::run(
                $journeys,
                self::baseUrl($driver),
                is_callable($options['fetch'] ?? null) ? $options['fetch'] : null,
                is_int($options['timeout'] ?? null)
                    ? (int) $options['timeout']
                    : JourneyOracle::DEFAULT_TIMEOUT_SECONDS
            );
        }

        $report = JourneyOracle::report(
            $convergence,
            $rows,
            $journeys,
            $scopeSurfaces ?? self::declaredSurfaces($contract),
            $driver->name(),
            $planDigest
        );
        // The disclosure list is the report's own honesty channel (§2.4), so
        // the convergence provenance rides in it rather than in a comment
        // nobody reading the output can see.
        $report['disclosures'][] = self::CONVERGENCE_DISCLOSURE;

        return $report;
    }

    /**
     * Normalize one read-only plan into `JourneyOracle::convergence()`'s
     * input shape.
     *
     * The counts are the agent's own: `unchanged` is every owned entity that
     * matched on this fresh re-read, `deleted` is every tombstone already
     * carried out. A plan with any divergence bucket populated is a
     * positively-observed non-convergence and reports `result: fail`.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    public static function convergenceSummary(array $plan): array {
        $diverged = [];
        foreach (self::DIVERGENCE_BUCKETS as $bucket) {
            $count = count((array) ($plan[$bucket] ?? []));
            if ($count > 0) {
                $diverged[$bucket] = $count;
            }
        }

        return [
            'deletions' => count((array) ($plan['deleted'] ?? [])),
            'diverged' => $diverged,
            'live_entities' => count((array) ($plan['unchanged'] ?? [])),
            'result' => $diverged === [] ? JourneyOracle::CONVERGENCE_PASS : 'fail',
            'verifier' => self::CONVERGENCE_VERIFIER,
        ];
    }

    /**
     * A frozen plan binds the contract it was authorized against.
     *
     * @param array<string,mixed> $frozen
     * @param array<string,mixed> $contract
     */
    private static function assertContractBinding(array $frozen, array $contract): void {
        $planContract = $frozen['contract_digest'] ?? null;
        $current = (string) ($contract['contract_digest'] ?? '');
        if (!is_string($planContract) || $planContract === '' || hash_equals($planContract, $current)) {
            return;
        }
        throw new CommandRefusalException(
            'verify_contract_moved',
            'the accepted application contract has changed since this release was authorized, so its declared '
                . 'journeys are not the journeys that release was verified against',
            'check out the commit whose contract this release cited and re-run duo verify --plan with the same '
                . 'digest, or run duo verify without --plan to verify against the contract as it is now'
        );
    }

    /**
     * The declared journeys.
     *
     * @param array<string,mixed> $contract
     * @return list<array<string,mixed>>
     */
    private static function journeys(array $contract): array {
        $journeys = $contract['declarations']['journeys'] ?? [];

        return is_array($journeys) ? array_values(array_filter($journeys, 'is_array')) : [];
    }

    /**
     * Every surface the contract declares, used as the disclosure scope when
     * the caller named none.
     *
     * @param array<string,mixed> $contract
     * @return list<string>
     */
    private static function declaredSurfaces(array $contract): array {
        $out = [];
        foreach ((array) ($contract['declarations']['surfaces'] ?? []) as $surface) {
            if (is_array($surface) && is_string($surface['id'] ?? null) && $surface['id'] !== '') {
                $out[] = $surface['id'];
            }
        }

        return $out;
    }

    /**
     * The base URL journeys are probed against.
     *
     * Read from the target's own read-only inventory (`home`, then
     * `siteurl`), never from the host registry: a registry entry names how to
     * REACH a target, and a journey must be probed at the URL WordPress
     * itself believes it serves, or a redirect turns every probe into a 301
     * the operator cannot explain.
     */
    private static function baseUrl(EnvironmentDriver $driver): string {
        $result = $driver->captureWp([
            'duo', 'assess-inventory', '--repo=' . $driver->repoPath(), '--format=json',
        ]);
        $inventory = ($result['exit'] ?? 1) === 0
            ? json_decode(trim((string) ($result['stdout'] ?? '')), true)
            : null;
        $url = null;
        if (is_array($inventory)) {
            foreach (['home', 'siteurl'] as $key) {
                // `duo-assess-inventory/v1` carries both under `target`
                // (agent/src/Assess/AssessInventory.php); `home` first
                // because that is the URL WordPress serves pages at.
                $candidate = $inventory['target'][$key] ?? null;
                if (is_string($candidate) && preg_match('~^https?://~i', $candidate) === 1) {
                    $url = rtrim($candidate, '/');
                    break;
                }
            }
        }
        if ($url === null) {
            throw new CommandRefusalException(
                'journey_base_url_missing',
                'the target did not report the URL it serves, so a declared journey cannot be probed',
                'confirm the target agent answers wp duo assess-inventory, then re-run duo verify'
            );
        }

        return $url;
    }

    /**
     * One complete read-only plan.
     *
     * @return array<string,mixed>
     */
    private static function targetPlan(EnvironmentDriver $driver): array {
        $result = $driver->captureWp(['duo', 'plan', '--repo=' . $driver->repoPath(), '--format=json']);
        if (($result['exit'] ?? 1) !== 0) {
            throw new CommandRefusalException(
                'verify_plan_unavailable',
                'the target could not produce the plan this verification re-reads, so convergence is unproven',
                'run duo status ' . self::token($driver->name()) . ', repair the target, then re-run duo verify'
            );
        }
        $decoded = json_decode(trim((string) ($result['stdout'] ?? '')), true);
        if (!is_array($decoded)) {
            throw new CommandRefusalException(
                'verify_plan_unavailable',
                'the target returned plan output this build could not parse as JSON',
                'upgrade the target agent, then re-run duo verify'
            );
        }

        try {
            return PlanContract::requireComplete($decoded, 'duo verify');
        } catch (\Throwable $incomplete) {
            throw new CommandRefusalException(
                'verify_plan_incomplete',
                'the target plan envelope is incomplete, so its emptiness cannot be read as convergence',
                'upgrade the target agent to a build that emits the complete plan envelope, then re-run duo verify',
                [['detail' => 'plan envelope incomplete']],
                $incomplete->getMessage()
            );
        }
    }

    /**
     * The closed flag grammar.
     *
     * @param list<string> $extra
     * @return array{plan:?string,limit:int}
     */
    private static function flags(array $extra): array {
        $plan = null;
        $limit = 50;
        $limitSeen = false;
        foreach ($extra as $arg) {
            if (!is_string($arg)) {
                throw self::invalidArguments('verify received a non-string argument');
            }
            $name = str_contains($arg, '=') ? explode('=', $arg, 2)[0] : $arg;
            $value = str_contains($arg, '=') ? substr($arg, strlen($name) + 1) : null;
            switch ($name) {
                case '--plan':
                    if ($plan !== null || $value === null || $value === '') {
                        throw self::invalidArguments('--plan takes exactly one --plan=<digest> value');
                    }
                    $plan = $value;
                    break;
                case '--limit':
                    if ($limitSeen
                        || $value === null
                        || preg_match('/^(?:[1-9]|[1-9][0-9]|1[0-9]{2}|200)$/D', $value) !== 1) {
                        throw self::invalidArguments('--limit must be a single value between 1 and 200');
                    }
                    $limitSeen = true;
                    $limit = (int) $value;
                    break;
                case '--format':
                case '--json':
                    break;
                default:
                    throw self::invalidArguments("verify received an option it does not define: '$name'");
            }
        }

        return ['limit' => $limit, 'plan' => $plan];
    }

    private static function invalidArguments(string $message): CommandRefusalException {
        return new CommandRefusalException(
            'invalid_arguments',
            $message,
            'duo verify <env> accepts --plan=<digest>, --limit=<1..200> and --format=json'
        );
    }

    /** An environment name safe to print inside a remediation sentence. */
    private static function token(string $value): string {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $value) === 1 ? $value : '<env>';
    }
}

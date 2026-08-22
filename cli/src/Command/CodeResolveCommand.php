<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/../Code/CodeResolver.php';
require_once __DIR__ . '/../Code/WpOrgReleases.php';
require_once dirname(__DIR__, 3) . '/agent/src/Code/CodeSourceLock.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';

use Duo\CodeSourceLock;
use Duo\CommandRefusalException;

/**
 * `duo code-resolve <env>` — put the locked components' bytes back (DUO-3500).
 *
 * A fresh clone of a split repository carries no bytes for anything
 * `code/duo-code.lock.json` declares, so it refuses to compile with
 * `code_component_unresolved`. This verb is the step that answers that
 * refusal, and `duo deploy` / `duo promote` run it automatically as
 * `<verb> phase: code-resolve` before compiling, so the ordinary path needs no
 * separate command at all.
 *
 * ## Where it can run, and why that is a property of the transport
 *
 * Resolution is HOST work by owner ruling: the production target never fetches
 * from a registry, because `code_release_provider`'s probe attests exactly
 * that (docs/code-release-runtime.md:24-28). So the host must be able to write
 * the repository the compile will hash:
 *
 * - **local** — `repo_path` IS a host path; that is what the transport means.
 * - **docker** — `repo_path` is the path INSIDE the container, and the host
 *   side of that bind mount is the checkout the CLI is standing in. `duo` is
 *   already anchored there: the environment registry itself is found by
 *   walking up from the working directory, so the `<env>` being resolved and
 *   the checkout being written come from one place.
 * - **ssh** — the repository is on the far side of a network boundary and the
 *   host cannot write it at all. Host→target push is DUO-3514. Until it lands
 *   the verb refuses with `code_resolve_transport_unsupported`, and the
 *   automatic deploy phase refuses too — UNLESS every locked component already
 *   hashes correctly on the target, which it proves by asking rather than
 *   assuming. That asymmetry is deliberate: the verb's contract is "make these
 *   bytes present HERE", which it cannot honour; the phase's contract is "the
 *   bytes the compile is about to hash are the declared ones", which on ssh
 *   only the target can answer.
 */
final class CodeResolveCommand {
    /** The host cannot materialize into this environment's repository. */
    public const REASON_TRANSPORT_UNSUPPORTED = 'code_resolve_transport_unsupported';

    /** @param list<string> $extra */
    public static function run(EnvironmentDriver $transport, array $extra): int {
        $dryRun = false;
        $offline = false;
        $cacheDir = null;
        foreach ($extra as $arg) {
            if ($arg === '--dry-run') {
                $dryRun = true;
                continue;
            }
            if ($arg === '--offline') {
                $offline = true;
                continue;
            }
            if (str_starts_with($arg, '--cache-dir=')) {
                $cacheDir = substr($arg, strlen('--cache-dir='));
                if ($cacheDir === '' || !str_starts_with($cacheDir, '/')) {
                    fwrite(STDERR, "duo: code-resolve --cache-dir requires an absolute path\n");
                    return 1;
                }
                continue;
            }
            fwrite(
                STDERR,
                "duo: code-resolve accepts only --dry-run, --offline and --cache-dir=<path>; unsupported argument '$arg'\n"
            );
            return 1;
        }

        try {
            $repo = self::hostRepo($transport);
            if ($repo === null) {
                throw self::unresolvableTransport($transport);
            }
            $lock = CodeResolver::declaredLock($repo);
            if ($lock === null) {
                echo 'duo: this repository declares no code lock (site.duo.json code format 1, or no code half); '
                    . "nothing to resolve.\n";
                return 0;
            }
            $rows = self::resolver($offline, $cacheDir)->resolve($repo, $lock['components'], $dryRun);
            self::render($lock['path'], $rows, $dryRun, '');
            return 0;
        } catch (CommandRefusalException $refusal) {
            return self::renderRefusal($refusal, 'code-resolve');
        } catch (\Throwable $error) {
            fwrite(STDERR, 'duo: code-resolve: ' . $error->getMessage() . "\n");
            return 1;
        }
    }

    /**
     * The automatic host-side phase `duo deploy` and `duo promote` run before
     * compiling. Returns null to continue, or the exit code to return.
     *
     * It prints NOTHING when the repository declares no lock, which is every
     * format-1 repository and every state-only one — the existing phase lines
     * of an ordinary deploy are byte-identical to what they were.
     *
     * It runs before `compile` and therefore before `promotion-begin`, so it
     * holds no promotion lease, has taken no checkpoint, and has no cleanup to
     * compensate: a refusal here is simply a deploy that never started.
     */
    public static function deployPhase(EnvironmentDriver $transport, string $verb): ?int {
        $driver = $transport->driverId();
        // Closed vocabulary: Transport::make() constructs exactly local,
        // docker and ssh and refuses every other `transport` value
        // (cli/src/Transport/Transport.php:65-73), so in a real deployment the
        // three arms below are total. Anything else here is a hand-rolled
        // driver in an offline suite, which owns no site repository and no
        // lock; and the compile gate immediately below still refuses
        // `code_component_unresolved` for anything unresolved, so no
        // unresolved code can pass through this branch unnoticed.
        if ($driver !== 'local' && $driver !== 'docker' && $driver !== 'ssh') {
            return null;
        }
        try {
            $repo = self::hostRepo($transport);
            if ($repo === null) {
                return self::targetPhase($transport, $verb);
            }
            $lock = CodeResolver::declaredLock($repo);
            if ($lock === null) {
                return null;
            }
            echo "$verb phase: code-resolve\n";
            $rows = self::resolver(false, null)->resolve($repo, $lock['components'], false);
            self::assertTargetHoldsLock($transport, $lock['components']);
            self::render($lock['path'], $rows, false, $verb);
            return null;
        } catch (CommandRefusalException $refusal) {
            self::renderRefusal($refusal, "$verb: code-resolve");
            fwrite(STDERR, "duo: $verb: refusing before compile; no lifecycle or code materialization occurred\n");
            return 1;
        } catch (\Throwable $error) {
            fwrite(STDERR, "duo: $verb: code-resolve: " . $error->getMessage() . "\n");
            fwrite(STDERR, "duo: $verb: refusing before compile; no lifecycle or code materialization occurred\n");
            return 1;
        }
    }

    /**
     * The host-side path of the repository this environment compiles, or null
     * when the host cannot see it.
     *
     * Null is not "no lock": it is "ask the target instead", which is what the
     * ssh arm and a docker invocation from outside the checkout both need.
     */
    private static function hostRepo(EnvironmentDriver $transport): ?string {
        // One question, asked of the driver, for every transport (DUO-3526).
        //
        // Before this the docker arm inferred the answer from
        // `CodeResolver::locateSiteRepo(getcwd())` — the directory the operator
        // happened to stand in. `repo_path` is a CONTAINER path and both sides
        // of a pair mount their own repository at `/siterepo`, so cwd answered
        // for the wrong environment whenever the two differed: during a
        // rehearse it named the SOURCE repository, which resolved cleanly and
        // reported "unchanged" while the target the compile would read stayed
        // empty (grind_adapter_walk.sh S1, "branch candidate did not compile on
        // the target").
        //
        // `instanceof Transport` rather than a driverId match: hostRepoPath()
        // is declared on the driver base with a null default and overridden by
        // the two transports that can answer, so this needs no per-driver
        // knowledge here and no `require` of any transport file — naming
        // DockerTransport directly cannot be done from this file at all
        // (DockerTransport.php does not require its own base class, so a
        // require_once of it here fatals under cli/duo's load order). A fake
        // driver that implements only the interface returns null and falls to
        // the read-only target arm, which is the correct answer for a driver
        // that exposes no host repository.
        return $transport instanceof Transport ? $transport->hostRepoPath() : null;
    }

    /**
     * Prove, through the TARGET, that the host wrote where the target reads.
     *
     * This is what makes a derived host path safe to trust. The path comes
     * from compose rather than from the operator's cwd, but a derivation can
     * still be wrong — a stale compose file, an edited mount — and a resolve
     * that wrote somewhere harmless would otherwise report success and leave
     * the compile one phase later to fail with `code_source_missing` and no
     * explanation. Asking the target for its own inventory closes that: if the
     * bytes did not land where the target reads, the components are still
     * reported absent here, by name, before anything is compiled.
     *
     * It reuses the same `wp duo code-inventory` read the read-only arm uses,
     * so there is one notion of "what the target holds".
     *
     * @param list<array<string,mixed>> $components
     */
    private static function assertTargetHoldsLock(EnvironmentDriver $transport, array $components): void {
        if ($transport->driverId() !== 'docker') {
            // `local` needs no proof: repoPath() IS the directory the target
            // reads, so there is no derivation that could be wrong, and this
            // would only add a wp call to every local deploy.
            return;
        }
        $inventory = self::targetInventory($transport, rtrim($transport->repoPath(), '/'));
        $missing = [];
        foreach ($components as $entry) {
            $key = $entry['root'] . '/' . $entry['component'];
            $actual = $inventory[$key] ?? '';
            if (!hash_equals((string) $entry['tree_sha256'], $actual)) {
                $missing[] = $key . ' ' . $entry['version']
                    . ($actual === '' ? ' (absent)' : ' (present at ' . $actual . ')');
            }
        }
        if ($missing !== []) {
            throw new CommandRefusalException(
                CodeResolver::REASON_TREE_DIGEST_MISMATCH,
                'the host resolved this environment\'s locked components, but the target does not report them '
                . 'at the locked digest',
                'confirm the compose service mounts this environment\'s repository at its repo_path, then rerun',
                [],
                'duo: after resolving, the target still does not hold: ' . implode(', ', $missing)
            );
        }
    }

    private static function resolver(bool $offline, ?string $cacheDir): CodeResolver {
        return new CodeResolver(new WpOrgReleases($cacheDir ?? WpOrgReleases::defaultCacheDir(), $offline));
    }

    /**
     * The ssh arm (and docker-from-outside-the-checkout): the host cannot
     * write this repository, so the only question left is whether it already
     * holds the declared bytes.
     *
     * Everything here is read-only and target-side. It asks the target for its
     * own `site.duo.json`, its own lock, and its own component inventory — the
     * same `wp duo code-inventory` read `duo code-classify` uses to prove a
     * checkout and a target agree (cli/src/Command/CodeClassifyCommand.php:231-265).
     */
    private static function targetPhase(EnvironmentDriver $transport, string $verb): ?int {
        $repo = rtrim($transport->repoPath(), '/');
        $site = $transport->captureRaw('cat ' . escapeshellarg($repo . '/site.duo.json'));
        if ($site['exit'] !== 0) {
            // Not a silent skip: compile reads the same file one phase later
            // and refuses by name if it is missing, so nothing unresolved can
            // slip past this return.
            return null;
        }
        $document = json_decode(trim($site['stdout']), true);
        $code = is_array($document) ? ($document['code'] ?? null) : null;
        if (!is_array($code) || ($code['format'] ?? null) !== 2) {
            return null;
        }
        echo "$verb phase: code-resolve\n";
        $relative = is_string($code['lock'] ?? null) ? (string) $code['lock'] : CodeSourceLock::PATH;
        $lockRead = $transport->captureRaw('cat ' . escapeshellarg($repo . '/' . $relative));
        if ($lockRead['exit'] !== 0) {
            throw new CommandRefusalException(
                CodeResolver::REASON_LOCK_UNREADABLE,
                'the target declares code format 2 but its declared lock could not be read',
                'restore the declared lock file in the target repository, then retry',
                [],
                "duo: could not read $relative from the target repository"
            );
        }
        $lock = CodeSourceLock::parse(trim($lockRead['stdout']));
        $inventory = self::targetInventory($transport, $repo);
        $pending = [];
        $rows = [];
        foreach ((array) $lock['components'] as $entry) {
            $key = $entry['root'] . '/' . $entry['component'];
            $actual = $inventory[$key] ?? '';
            if (hash_equals((string) $entry['tree_sha256'], $actual)) {
                $rows[] = [
                    'root' => (string) $entry['root'],
                    'component' => (string) $entry['component'],
                    'version' => (string) $entry['version'],
                    'state' => 'unchanged',
                    'detail' => 'already present on the target at the locked digest',
                ];
                continue;
            }
            $pending[] = $key . ' ' . $entry['version']
                . ($actual === '' ? ' (absent)' : ' (present at ' . $actual . ')');
        }
        if ($pending !== []) {
            throw self::sshRefusal(
                'duo: the host cannot materialize into an ssh target, and these locked component(s) are not '
                . 'already correct there: ' . implode(', ', $pending)
            );
        }
        self::render($relative, $rows, false, $verb);
        return null;
    }

    /**
     * `{root}/{component}` => `tree_sha256`, as the target itself reports it.
     *
     * @return array<string,string>
     */
    private static function targetInventory(EnvironmentDriver $transport, string $repo): array {
        $result = $transport->captureWp(['duo', 'code-inventory', '--repo=' . $repo, '--format=json']);
        if ($result['exit'] !== 0) {
            throw self::sshRefusal(
                'duo: the host cannot materialize into an ssh target, and the target could not report its own '
                . 'code inventory (exit ' . $result['exit'] . '), so nothing proves the locked components are '
                . 'already correct there'
            );
        }
        $decoded = json_decode(trim($result['stdout']), true);
        if (!is_array($decoded) || ($decoded['format'] ?? null) !== 'duo-code-inventory/v1'
            || !is_array($decoded['components'] ?? null)) {
            throw self::sshRefusal(
                'duo: the host cannot materialize into an ssh target, and the target returned an unrecognized '
                . 'code inventory, so nothing proves the locked components are already correct there'
            );
        }
        $inventory = [];
        foreach ($decoded['components'] as $row) {
            if (is_array($row) && is_string($row['root'] ?? null) && is_string($row['component'] ?? null)) {
                $inventory[$row['root'] . '/' . $row['component']] = (string) ($row['tree_sha256'] ?? '');
            }
        }
        return $inventory;
    }

    /** The one refusal that names the DUO-3514 runbook, from either arm. */
    private static function sshRefusal(string $operatorMessage): CommandRefusalException {
        return new CommandRefusalException(
            self::REASON_TRANSPORT_UNSUPPORTED,
            'this transport cannot be resolved from the host: only local and docker environments expose the '
            . 'site repository to the machine running duo',
            'materialize the locked components on the target itself, following "Resolving a split repository" '
            . 'in docs/guides/code-updates.md, or run the deploy from a local or docker environment; host-to-target '
            . 'push over ssh is DUO-3514 and is not implemented',
            [],
            $operatorMessage
        );
    }

    private static function unresolvableTransport(EnvironmentDriver $transport): CommandRefusalException {
        if ($transport->driverId() === 'docker') {
            // Reworded with the mechanism it now describes (DUO-3526). The
            // previous text sent the operator to run from inside a checkout,
            // which was the cwd inference this issue removed; leaving it would
            // name a remedy that no longer affects the outcome.
            return new CommandRefusalException(
                self::REASON_TRANSPORT_UNSUPPORTED,
                'a docker environment is resolved through the host side of its bind mount, and this service does '
                . 'not bind its repo_path to a writable host directory',
                'mount the environment\'s repository into the service at its repo_path as a writable bind mount, '
                . 'then rerun',
                [],
                'duo: the compose service for this environment exposes no writable bind mount at its repo_path, '
                . 'so the host side of its repository could not be identified'
            );
        }
        return self::sshRefusal(
            'duo: ' . $transport->driverId() . " transport '" . $transport->name()
            . "' keeps its repository on the far side of the transport, where this host cannot write"
        );
    }

    /**
     * Report the code resolution a refresh/rebase/rehearse performed into its
     * compile worktrees (DUO-3523).
     *
     * Rendering lives HERE, not in Refresh: `cli/src/Refresh/Refresh.php`
     * contains no `echo` at all — it returns structured results and its command
     * renders them — and the work being reported is this file's own, so it gets
     * this file's own vocabulary. A split refresh therefore prints the same
     * `RESOLVED/UNCHANGED` rows and the same `N materialized, M unchanged`
     * summary as `duo code-resolve` and the deploy phase, differing only in the
     * prefix that says which worktree it was for.
     *
     * A format-1 repository resolves nothing, so `code_resolve` is absent or
     * empty and NOTHING is printed — its output stays byte-identical.
     *
     * @param array<string,mixed> $result a Refresh::refresh()/rebase() result
     */
    public static function renderRefreshPhase(array $result, string $verb): void {
        foreach ((array) ($result['code_resolve'] ?? []) as $role => $phase) {
            if (!is_array($phase) || ($phase['rows'] ?? []) === []) {
                continue;
            }
            self::render((string) $phase['lock'], $phase['rows'], false, "$verb: code-resolve ($role worktree)");
        }
    }

    /**
     * @param list<array{root:string,component:string,version:string,state:string,detail:string}> $rows
     */
    private static function render(string $lockPath, array $rows, bool $dryRun, string $phasePrefix): void {
        $prefix = $phasePrefix === '' ? 'duo: code-resolve' : "duo: $phasePrefix";
        echo $prefix . ': ' . count($rows) . ' component(s) declared in ' . $lockPath . "\n";
        $counts = ['resolved' => 0, 'unchanged' => 0, 'would-resolve' => 0];
        foreach ($rows as $row) {
            $counts[$row['state']] = ($counts[$row['state']] ?? 0) + 1;
            echo '  ' . strtoupper($row['state']) . ' ' . $row['root'] . '/' . $row['component']
                . ' ' . ($row['version'] !== '' ? $row['version'] : '(no version header)')
                . ' — ' . $row['detail'] . "\n";
        }
        if ($dryRun) {
            echo "duo: --dry-run: nothing was fetched, written, or cached.\n";
            return;
        }
        echo $prefix . ': ' . $counts['resolved'] . ' materialized, ' . $counts['unchanged'] . " unchanged.\n";
    }

    /**
     * Human-mode refusal rendering.
     *
     * Both halves are printed on purpose. The operator line is
     * `getMessage()` — the private, path-and-digest-bearing evidence the
     * operator needs to act; the `[reason]`/`remedy:` pair is the reviewed
     * public contract, which must stay value-free because
     * CommandRefusalException redacts every public field naming a home
     * directory (agent/src/Kernel/CommandRefusal.php:199).
     */
    private static function renderRefusal(CommandRefusalException $refusal, string $command): int {
        fwrite(STDERR, "duo: $command: " . $refusal->getMessage() . "\n");
        fwrite(STDERR, '[' . $refusal->reasonCode . '] ' . $refusal->publicMessage . "\n");
        fwrite(STDERR, 'remedy: ' . $refusal->remediation . "\n");
        return 1;
    }
}

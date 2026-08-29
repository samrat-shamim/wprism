<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/../Transport/CodePushTransport.php';
require_once __DIR__ . '/../Code/CodeResolver.php';
require_once __DIR__ . '/../Code/WpOrgReleases.php';
require_once dirname(__DIR__, 3) . '/agent/src/Code/CodeSourceLock.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';

use WPrism\CodeSourceLock;
use WPrism\CommandRefusalException;

/**
 * `wprism code-resolve <env>` — put the locked components' bytes back (issue #3500).
 *
 * A fresh clone of a split repository carries no bytes for anything
 * `code/wprism-code.lock.json` declares, so it refuses to compile with
 * `code_component_unresolved`. This verb is the step that answers that
 * refusal, and `wprism deploy` / `wprism promote` run it automatically as
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
 *   side of that bind mount is the checkout the CLI is standing in. `wprism` is
 *   already anchored there: the environment registry itself is found by
 *   walking up from the working directory, so the `<env>` being resolved and
 *   the checkout being written come from one place.
 * - **ssh** — the repository is on the far side of a network boundary, so the
 *   host resolves and then PUSHES (issue #3514). The lock is read from the
 *   TARGET, never from the operator's cwd (which is issue #3526's ruling applied
 *   to the transport that has no host repository at all); resolution happens
 *   in a throwaway host staging worktree, so no checkout of the site is
 *   needed; the resolved trees travel as ONE tar through
 *   `CodePushTransport`; and they are verified TARGET-SIDE, in a staging
 *   directory under `.wprism/code-push/`, against the lock's `tree_sha256`
 *   BEFORE anything is renamed into place. A component the target already
 *   holds at a different digest refuses `code_resolve_component_drifted`
 *   rather than being overwritten — CodeResolver's own doctrine, "nothing
 *   overwrites a tree Git does not carry" (CodeResolver.php:207-220), applied
 *   unchanged across a transport. The push arm is gated on
 *   `instanceof CodePushTransport`, so a docker service with no writable bind
 *   and every hand-rolled driver keep the `code_resolve_transport_unsupported`
 *   refusal they had, byte for byte.
 *
 * Rejected on the way here: resolving ON the target (docs/code-half.md:241
 * rejects it — production would need registry egress, and
 * `code_release_provider`'s probe attests it does not have it), and rsync plus
 * a `releases/<rev>` symlink flip (§2.2's refinement), which moves where
 * `wp_path` points and is a deploy-topology change rather than a
 * materialization one.
 */
final class CodeResolveCommand {
    /** The host cannot materialize into this environment's repository. */
    public const REASON_TRANSPORT_UNSUPPORTED = 'code_resolve_transport_unsupported';

    /** The host→target push could not be completed; the target was not changed. */
    public const REASON_PUSH_FAILED = 'code_resolve_push_failed';

    /** Target-side staging root for a push, under the repository's own `.wprism/`. */
    public const PUSH_STAGING = '.wprism/code-push';

    /** Disposable target-visible repository snapshots used by release compilation. */
    public const RELEASE_STAGING = '.wprism/code-release-prepare';

    /**
     * Which inventory proof is being taken. Not decoration: each one may state
     * a different thing about what the target currently holds, and saying the
     * wrong one is how an operator ends up trusting "nothing was published"
     * after something was.
     */
    private const PROOF_DOCKER = 'docker-bind';
    private const PROOF_STAGED = 'staged';
    private const PROOF_POST_PUSH = 'post-push';

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
                    fwrite(STDERR, "wprism: code-resolve --cache-dir requires an absolute path\n");
                    return 1;
                }
                continue;
            }
            fwrite(
                STDERR,
                "wprism: code-resolve accepts only --dry-run, --offline and --cache-dir=<path>; unsupported argument '$arg'\n"
            );
            return 1;
        }

        try {
            $repo = self::hostRepo($transport);
            if ($repo === null) {
                if (!$transport instanceof CodePushTransport) {
                    throw self::unresolvableTransport($transport);
                }

                // issue #3514. The verb's contract — "make these bytes present
                // where this environment reads them" — is honoured on ssh by
                // resolving here and pushing, so it no longer refuses. The
                // empty verb selects the verb's own render prefix; the phase
                // passes its verb name instead.
                return self::targetResolve($transport, '', $dryRun, $offline, $cacheDir) ?? 0;
            }
            $lock = CodeResolver::declaredLock($repo);
            if ($lock === null) {
                echo 'wprism: this repository declares no code lock (site.wprism.json code format 1, or no code half); '
                    . "nothing to resolve.\n";
                return 0;
            }
            $rows = self::resolver($offline, $cacheDir)->resolve($repo, $lock['components'], $dryRun);
            self::render($lock['path'], $rows, $dryRun, '');
            return 0;
        } catch (CommandRefusalException $refusal) {
            return self::renderRefusal($refusal, 'code-resolve');
        } catch (\Throwable $error) {
            fwrite(STDERR, 'wprism: code-resolve: ' . $error->getMessage() . "\n");
            return 1;
        }
    }

    /**
     * The automatic host-side phase `wprism deploy` and `wprism promote` run before
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
                return self::targetResolve($transport, $verb, false, false, null);
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
            fwrite(STDERR, "wprism: $verb: refusing before compile; no lifecycle or code materialization occurred\n");
            return 1;
        } catch (\Throwable $error) {
            fwrite(STDERR, "wprism: $verb: code-resolve: " . $error->getMessage() . "\n");
            fwrite(STDERR, "wprism: $verb: refusing before compile; no lifecycle or code materialization occurred\n");
            return 1;
        }
    }

    /**
     * Compile one release from an isolated, target-visible repository snapshot.
     *
     * A format-2 repository deliberately omits locked third-party component
     * bytes. The public `wprism code-resolve` verb materializes those bytes into
     * the repository because that is exactly what its operator requested.
     * Automatic deploy/promote compilation has a different transaction
     * boundary: leaving those bytes in the canonical repository before a
     * promotion lease or checkpoint makes a failed preflight a repository
     * mutation with no compensation (agency audit finding 72).
     *
     * This boundary snapshots the repository under its private `.wprism/`
     * runtime directory, resolves only inside that snapshot, compiles through
     * the supplied product callback, and removes the snapshot in `finally`.
     * The snapshot path is visible through every transport, so the agent that
     * compiles is also the authority that hashes the resolved trees. A
     * format-1 repository takes the old direct path and pays no snapshot or
     * target-read cost.
     *
     * @template T
     * @param callable(string):T $compile receives the target-visible repository path
     * @return T|int the compile result, or exit 1 after a rendered resolution refusal
     */
    public static function releaseCompile(
        EnvironmentDriver $transport,
        string $verb,
        callable $compile
    ): mixed {
        $driver = $transport->driverId();
        if ($driver !== 'local' && $driver !== 'docker' && $driver !== 'ssh') {
            return $compile(rtrim($transport->repoPath(), '/'));
        }

        $repo = rtrim($transport->repoPath(), '/');
        try {
            $hostRepo = self::hostRepo($transport);
            $declared = $hostRepo === null
                ? self::targetDeclaresLock($transport, $repo)
                : CodeResolver::declaredLock($hostRepo) !== null;
            if (!$declared) {
                return $compile($repo);
            }

            echo "$verb phase: code-resolve\n";
            $token = bin2hex(random_bytes(12));
            $targetStage = $repo . '/' . self::RELEASE_STAGING . '/' . $token;
            $hostStage = $hostRepo === null
                ? null
                : rtrim($hostRepo, '/') . '/' . self::RELEASE_STAGING . '/' . $token;
            self::snapshotForRelease($transport, $repo, $targetStage);
            try {
                if ($hostStage === null) {
                    // SSH resolves on the host and pushes into the disposable
                    // repository. targetResolve() performs the staged and
                    // post-publish inventory proofs before compile sees it.
                    self::targetResolve($transport, '', false, false, null, $targetStage, false);
                } else {
                    $lock = CodeResolver::declaredLock($hostStage);
                    if ($lock === null) {
                        throw self::releaseStageFailed(
                            "wprism: the isolated repository snapshot at $targetStage did not carry the declared lock"
                        );
                    }
                    $rows = self::resolver(false, null)->resolve($hostStage, $lock['components'], false);
                    self::assertInventory(
                        $transport,
                        $targetStage,
                        $lock['components'],
                        self::PROOF_STAGED
                    );
                    self::render($lock['path'], $rows, false, $verb);
                }

                return $compile($targetStage);
            } finally {
                self::removeReleaseStaging($transport, $targetStage);
            }
        } catch (CommandRefusalException $refusal) {
            self::renderRefusal($refusal, "$verb: code-resolve");
            fwrite(
                STDERR,
                "wprism: $verb: refusing before compile; the canonical repository was not materialized\n"
            );
            return 1;
        } catch (\Throwable $error) {
            fwrite(STDERR, "wprism: $verb: code-resolve: " . $error->getMessage() . "\n");
            fwrite(
                STDERR,
                "wprism: $verb: refusing before compile; the canonical repository was not materialized\n"
            );
            return 1;
        }
    }

    /** Whether a target-only repository declares the locked code format. */
    private static function targetDeclaresLock(EnvironmentDriver $transport, string $repo): bool {
        $site = $transport->captureRaw('cat ' . escapeshellarg($repo . '/site.wprism.json'));
        if ($site['exit'] !== 0) {
            return false;
        }
        $document = json_decode(trim($site['stdout']), true);
        $code = is_array($document) ? ($document['code'] ?? null) : null;

        return is_array($code) && ($code['format'] ?? null) === 2;
    }

    /** Create one complete repository snapshot without copying runtime state. */
    private static function snapshotForRelease(
        EnvironmentDriver $transport,
        string $repo,
        string $stage
    ): void {
        $archive = $stage . '.tar';
        $script = 'umask 077; mkdir -p ' . escapeshellarg(dirname($stage))
            . ' && tar -C ' . escapeshellarg($repo)
            . " --exclude='./.git' --exclude='./.wprism' -cf " . escapeshellarg($archive) . ' .'
            . ' && mkdir ' . escapeshellarg($stage)
            . ' && tar --no-same-owner -xf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($stage)
            . ' && rm -f ' . escapeshellarg($archive);
        $result = $transport->captureRaw($script);
        if ($result['exit'] !== 0) {
            $transport->captureRaw(
                'rm -f ' . escapeshellarg($archive) . '; rm -rf ' . escapeshellarg($stage)
            );
            throw self::releaseStageFailed(
                'wprism: the target could not create an isolated repository snapshot for release compilation',
                $result
            );
        }
    }

    /** Remove only a release snapshot whose identity this class minted. */
    private static function removeReleaseStaging(EnvironmentDriver $transport, string $stage): void {
        if (!str_contains($stage, '/' . self::RELEASE_STAGING . '/')) {
            return;
        }
        $transport->captureRaw('rm -rf ' . escapeshellarg($stage));
    }

    /** @param array{exit:int,stdout:string,stderr:string}|null $result */
    private static function releaseStageFailed(
        string $operatorMessage,
        ?array $result = null
    ): CommandRefusalException {
        $detail = $result === null
            ? $operatorMessage
            : $operatorMessage . ' (exit ' . $result['exit'] . '): '
                . trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);

        return new CommandRefusalException(
            self::REASON_PUSH_FAILED,
            'locked component bytes could not be prepared in an isolated repository for release compilation',
            'inspect the target filesystem and retry; the canonical repository was not materialized',
            [],
            $detail
        );
    }

    /**
     * The host-side path of the repository this environment compiles, or null
     * when the host cannot see it.
     *
     * Null is not "no lock": it is "ask the target instead", which is what the
     * ssh arm and a docker invocation from outside the checkout both need.
     */
    private static function hostRepo(EnvironmentDriver $transport): ?string {
        // One question, asked of the driver, for every transport (issue #3526).
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
        // require_once of it here fatals under cli/wprism's load order). A fake
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
     * It reuses the same `wp wprism code-inventory` read the read-only arm uses,
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
        self::assertInventory($transport, rtrim($transport->repoPath(), '/'), $components, self::PROOF_DOCKER);
    }

    /**
     * Ask one repository ON THE TARGET for its digests and compare them
     * against the lock.
     *
     * Shared by the docker proof above and by the ssh push, which needs the
     * identical comparison twice: once against the STAGING repository, before
     * anything is renamed into place, and once against the real repository
     * afterwards as the post-condition.
     *
     * `$proof` selects only the reviewed remediation, and the three are NOT
     * interchangeable: the docker arm's public message, remediation and
     * operator line stay byte-identical to what they were before the push
     * existed (AGENTS.md rule 8); the staged proof may say nothing was
     * published, because nothing has been; and the post-push proof may not say
     * that, because by then it has.
     *
     * @param list<array<string,mixed>> $components
     */
    private static function assertInventory(
        EnvironmentDriver $transport,
        string $repo,
        array $components,
        string $proof
    ): void {
        $inventory = self::targetInventory($transport, rtrim($repo, '/'), $proof !== self::PROOF_DOCKER);
        $missing = [];
        foreach ($components as $entry) {
            $key = $entry['root'] . '/' . $entry['component'];
            $actual = $inventory[$key] ?? '';
            if (!hash_equals((string) $entry['tree_sha256'], $actual)) {
                $missing[] = $key . ' ' . $entry['version']
                    . ($actual === '' ? ' (absent)' : ' (present at ' . $actual . ')');
            }
        }
        if ($missing === []) {
            return;
        }
        if ($proof === self::PROOF_STAGED) {
            throw new CommandRefusalException(
                CodeResolver::REASON_TREE_DIGEST_MISMATCH,
                'the trees pushed to the target do not hash to the digests the lock declares',
                're-lock the components with wprism code-classify if the releases were legitimately re-packaged; '
                . 'nothing was published into the target\'s code/wp-content and its staging directory was removed',
                [],
                'wprism: the target does not report the locked digest for staged: ' . implode(', ', $missing)
            );
        }
        if ($proof === self::PROOF_POST_PUSH) {
            // Reached only after verified trees were renamed into place, so
            // this may NOT claim the target is unchanged. Something moved
            // between the staged proof and this read; the operator is owed
            // that fact rather than a reassurance.
            throw new CommandRefusalException(
                CodeResolver::REASON_TREE_DIGEST_MISMATCH,
                'the target does not report the locked digests after the verified trees were published there',
                'inspect the target repository: the trees verified in staging and were renamed into place, so '
                . 'something changed code/wp-content between those two reads; do not compile this target until '
                . 'wprism code-resolve reports every component at its locked digest',
                [],
                'wprism: after publishing, the target does not hold: ' . implode(', ', $missing)
            );
        }
        throw new CommandRefusalException(
            CodeResolver::REASON_TREE_DIGEST_MISMATCH,
            'the host resolved this environment\'s locked components, but the target does not report them '
            . 'at the locked digest',
            'confirm the compose service mounts this environment\'s repository at its repo_path, then rerun',
            [],
            'wprism: after resolving, the target still does not hold: ' . implode(', ', $missing)
        );
    }

    private static function resolver(bool $offline, ?string $cacheDir): CodeResolver {
        return new CodeResolver(new WpOrgReleases($cacheDir ?? WpOrgReleases::defaultCacheDir(), $offline));
    }

    /**
     * The target-side arm: ssh (which now resolves and pushes, issue #3514) and
     * docker-from-outside-the-checkout (which still only asks).
     *
     * The reads are unchanged and stay first, because they are the pre-push
     * half of docs/code-half.md:241-242's design and they are what makes the
     * push safe: the target's own `site.wprism.json`, the target's own lock, and
     * the target's own `wp wprism code-inventory` — the same read
     * `wprism code-classify` uses to prove a checkout and a target agree
     * (cli/src/Command/CodeClassifyCommand.php:231-265). Nothing is fetched or
     * transferred until they say which components are missing.
     *
     * `$verb === ''` selects the VERB's rendering (no phase line, "wprism:
     * code-resolve" prefix, exit code returned); any other value is the
     * automatic phase, which prints its phase line and returns null to
     * continue.
     */
    private static function targetResolve(
        EnvironmentDriver $transport,
        string $verb,
        bool $dryRun,
        bool $offline,
        ?string $cacheDir,
        ?string $repoOverride = null,
        bool $render = true
    ): ?int {
        $repo = rtrim($repoOverride ?? $transport->repoPath(), '/');
        $site = $transport->captureRaw('cat ' . escapeshellarg($repo . '/site.wprism.json'));
        if ($site['exit'] !== 0) {
            // Not a silent skip: compile reads the same file one phase later
            // and refuses by name if it is missing, so nothing unresolved can
            // slip past this return.
            return self::targetUnlocked($verb);
        }
        $document = json_decode(trim($site['stdout']), true);
        $code = is_array($document) ? ($document['code'] ?? null) : null;
        if (!is_array($code) || ($code['format'] ?? null) !== 2) {
            return self::targetUnlocked($verb);
        }
        if ($verb !== '') {
            echo "$verb phase: code-resolve\n";
        }
        $relative = is_string($code['lock'] ?? null) ? (string) $code['lock'] : CodeSourceLock::PATH;
        $lockRead = $transport->captureRaw('cat ' . escapeshellarg($repo . '/' . $relative));
        if ($lockRead['exit'] !== 0) {
            throw new CommandRefusalException(
                CodeResolver::REASON_LOCK_UNREADABLE,
                'the target declares code format 2 but its declared lock could not be read',
                'restore the declared lock file in the target repository, then retry',
                [],
                "wprism: could not read $relative from the target repository"
            );
        }
        $lock = CodeSourceLock::parse(trim($lockRead['stdout']));
        $components = array_values((array) $lock['components']);
        $pushing = $transport instanceof CodePushTransport;
        $inventory = self::targetInventory($transport, $repo, $pushing);

        $pending = [];
        $absent = [];
        $drifted = [];
        $unchanged = [];
        foreach ($components as $entry) {
            $key = $entry['root'] . '/' . $entry['component'];
            $actual = $inventory[$key] ?? '';
            if (hash_equals((string) $entry['tree_sha256'], $actual)) {
                $unchanged[$key] = true;
                continue;
            }
            // Built in lock order and used verbatim by the refusal below, so a
            // transport that cannot be pushed to says exactly what it said
            // before this issue existed.
            $pending[] = $key . ' ' . $entry['version']
                . ($actual === '' ? ' (absent)' : ' (present at ' . $actual . ')');
            if ($actual === '') {
                $absent[] = $entry;
                continue;
            }
            $drifted[] = $key . ' ' . $entry['version'] . ' (target holds ' . $actual
                . ', the lock declares ' . (string) $entry['tree_sha256'] . ')';
        }

        $pushed = [];
        if ($pending !== []) {
            if (!$pushing) {
                throw self::pushUnsupported(
                    'wprism: the host cannot materialize into an ssh target, and these locked component(s) are not '
                    . 'already correct there: ' . implode(', ', $pending)
                );
            }
            if ($drifted !== []) {
                // Decided BEFORE anything is fetched or transferred, and
                // refusing the whole run rather than pushing the absent
                // remainder: a repository half at the lock and half at
                // something else is the state nobody can reason about
                // afterwards, and it is the same all-or-nothing rule
                // CodeResolver::resolve() already applies on the host.
                throw new CommandRefusalException(
                    CodeResolver::REASON_DRIFTED,
                    'a locked component is present on the target with bytes other than the ones the lock declares',
                    'remove the component directory on the target and rerun to push the locked release, or '
                    . 're-lock it with wprism code-classify if the present bytes are the intended ones; '
                    . 'nothing overwrites a tree Git does not carry',
                    [],
                    'wprism: the target holds locked component(s) at a different digest: ' . implode(', ', $drifted)
                    . '; nothing was transferred'
                );
            }
            $pushed = self::push($transport, $repo, $components, $absent, $dryRun, $offline, $cacheDir);
        }

        // Rebuilt in LOCK order rather than in classification order, so the
        // report reads the same on every transport.
        $rows = [];
        foreach ($components as $entry) {
            $key = $entry['root'] . '/' . $entry['component'];
            $outcome = isset($unchanged[$key])
                ? ['state' => 'unchanged', 'detail' => 'already present on the target at the locked digest']
                : $pushed[$key];
            $rows[] = [
                'root' => (string) $entry['root'],
                'component' => (string) $entry['component'],
                'version' => (string) $entry['version'],
                'state' => $outcome['state'],
                'detail' => $outcome['detail'],
            ];
        }
        if ($render) {
            self::render($relative, $rows, $dryRun, $verb);
        }

        return $verb === '' ? 0 : null;
    }

    /**
     * What a target with no code lock means, per caller.
     *
     * The phase returns null and prints nothing, which is what keeps every
     * format-1 deploy byte-identical. The verb prints the same sentence the
     * host-side arm prints for a repository that declares no lock, because it
     * is the same conclusion about the same question.
     */
    private static function targetUnlocked(string $verb): ?int {
        if ($verb !== '') {
            return null;
        }
        echo 'wprism: this repository declares no code lock (site.wprism.json code format 1, or no code half); '
            . "nothing to resolve.\n";

        return 0;
    }

    /**
     * Resolve on the host, ship one tar, verify target-side, then rename into
     * place (issue #3514).
     *
     * The ORDER is the contract, and it is the same order
     * `CodeResolver::materialize()` uses on the host (:326-336), lifted across
     * a transport:
     *
     *  1. resolve into a THROWAWAY host staging worktree, so the operator
     *     needs no checkout of the site and `CodeResolver` still verifies
     *     `archive_sha256` before unpacking and `tree_sha256` after;
     *  2. build ONE tar of exactly the resolved component paths. Per-file scp
     *     was rejected: it multiplies round trips and cannot be made atomic
     *     per component;
     *  3. place it through `CodePushTransport`, inside a `try/finally` that
     *     removes it on every observed exit including a partial upload;
     *  4. extract into `<repo>/.wprism/code-push/<token>/code/wp-content` — a
     *     directory laid out as a REPOSITORY, so the target's own
     *     `wp wprism code-inventory --repo=<staging>` reports the staged trees'
     *     digests (agent/src/Command/Cli.php:948-960 reads only
     *     `<repo>/code/wp-content`);
     *  5. compare those against the lock BEFORE a single byte reaches
     *     `code/wp-content`. A mismatch refuses with the target untouched;
     *  6. `mv` each verified tree into place, refusing rather than
     *     overwriting anything that appeared meanwhile;
     *  7. re-read the target's inventory as the post-condition — the ssh twin
     *     of `assertTargetHoldsLock()`.
     *
     * @param list<array<string,mixed>> $components every locked component
     * @param list<array<string,mixed>> $absent the ones the target does not hold
     * @return array<string,array{state:string,detail:string}> keyed `root/component`
     */
    private static function push(
        CodePushTransport $transport,
        string $repo,
        array $components,
        array $absent,
        bool $dryRun,
        bool $offline,
        ?string $cacheDir
    ): array {
        $out = [];
        if ($dryRun) {
            foreach ($absent as $entry) {
                $out[$entry['root'] . '/' . $entry['component']] = [
                    'state' => 'would-resolve',
                    'detail' => 'would resolve on this host and push the verified tree to the target',
                ];
            }

            return $out;
        }

        $stage = self::hostStage();
        $localTar = $stage . '/code-push.tar';
        $stagingRoot = $repo . '/' . self::PUSH_STAGING . '/' . bin2hex(random_bytes(8));
        $stagedSource = $stagingRoot . '/' . CodeSourceLock::SOURCE;
        $targetTar = $transport->allocateCodePushInput('tree');
        try {
            self::resolver($offline, $cacheDir)->resolve($stage, $absent, false);

            $members = [];
            foreach ($absent as $entry) {
                $members[] = escapeshellarg($entry['root'] . '/' . $entry['component']);
            }
            $tar = self::runLocal(
                'tar -C ' . escapeshellarg($stage . '/' . CodeSourceLock::SOURCE)
                . ' -cf ' . escapeshellarg($localTar) . ' ' . implode(' ', $members)
            );
            if ($tar['exit'] !== 0) {
                throw self::pushFailed('wprism: the host could not archive the resolved component trees', $tar);
            }

            $put = $transport->putCodePushInput($localTar, $targetTar);
            if ($put['exit'] !== 0) {
                throw self::pushFailed(
                    'wprism: the resolved component archive could not be placed on the target',
                    $put
                );
            }

            $extract = $transport->captureRaw(
                'mkdir -p ' . escapeshellarg($stagedSource)
                . ' && tar --no-same-owner -xf ' . escapeshellarg($targetTar)
                . ' -C ' . escapeshellarg($stagedSource)
            );
            if ($extract['exit'] !== 0) {
                throw self::pushFailed(
                    'wprism: the target could not unpack the resolved component archive into its staging directory',
                    $extract
                );
            }

            // THE gate: the target's own digests for the STAGED trees, read
            // before anything under code/wp-content has been touched.
            self::assertInventory($transport, $stagingRoot, $absent, self::PROOF_STAGED);

            $publish = [];
            foreach ($absent as $entry) {
                $key = $entry['root'] . '/' . $entry['component'];
                $source = $stagedSource . '/' . $key;
                $destination = $repo . '/' . CodeSourceLock::SOURCE . '/' . $key;
                $publish[] = 's=' . escapeshellarg($source) . '; d=' . escapeshellarg($destination)
                    . '; p=' . escapeshellarg($repo . '/' . CodeSourceLock::SOURCE . '/' . $entry['root']) . '; '
                    . '[ -d "$s" ] || { echo ' . escapeshellarg('wprism: staged tree missing: ' . $key)
                    . ' >&2; exit 1; }; '
                    // Absence was PROVED by the inventory above; a path that
                    // exists now appeared during the push, and overwriting it
                    // would destroy bytes Git does not carry.
                    . '{ [ ! -e "$d" ] && [ ! -L "$d" ]; } || { echo '
                    . escapeshellarg('wprism: target component appeared during the push: ' . $key)
                    . ' >&2; exit 1; }; '
                    . 'mkdir -p "$p" || exit 1; mv "$s" "$d" || exit 1; ';
            }
            $moved = $transport->captureRaw(implode('', $publish) . 'exit 0');
            if ($moved['exit'] !== 0) {
                throw self::pushFailed(
                    'wprism: the target could not publish the verified component trees into code/wp-content',
                    $moved
                );
            }

            // The post-condition, over EVERY locked component and not only the
            // pushed ones: what this phase promises the compile is that the
            // bytes it is about to hash are the declared ones.
            self::assertInventory($transport, $repo, $components, self::PROOF_POST_PUSH);
        } finally {
            $transport->removeCodePushInput($targetTar);
            self::removeTargetStaging($transport, $stagingRoot);
            self::removeHostStage($stage);
        }

        foreach ($absent as $entry) {
            $out[$entry['root'] . '/' . $entry['component']] = [
                'state' => 'resolved',
                'detail' => 'verified on the target against the locked digest, then published',
            ];
        }

        return $out;
    }

    /** A throwaway host worktree for one push. Never inside a site repository. */
    private static function hostStage(): string {
        $stage = rtrim(sys_get_temp_dir(), '/') . '/wprism-code-push-' . bin2hex(random_bytes(8));
        if (!@mkdir($stage, 0700, true) && !is_dir($stage)) {
            throw self::pushFailed(
                'wprism: the host could not create a staging worktree for the code push',
                ['exit' => 1, 'stdout' => '', 'stderr' => "could not create $stage"]
            );
        }

        return $stage;
    }

    /**
     * Remove one host staging worktree this class created, and nothing else.
     *
     * The identity guard is the point, for exactly the reason
     * `CodeResolver::removeStaging()` states (:424-430): this runs in a
     * `finally` and a recursive delete with no identity check is one refactor
     * away from being pointed at a real checkout.
     */
    private static function removeHostStage(string $path): void {
        if (!str_contains($path, '/wprism-code-push-') || !is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }

    /** The same guard, target-side: only a path this class named is removable. */
    private static function removeTargetStaging(EnvironmentDriver $transport, string $stagingRoot): void {
        if (!str_contains($stagingRoot, '/' . self::PUSH_STAGING . '/')) {
            return;
        }
        $transport->captureRaw('rm -rf ' . escapeshellarg($stagingRoot));
    }

    /** @return array{exit:int, stdout:string, stderr:string} */
    private static function runLocal(string $command): array {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['exit' => 255, 'stdout' => '', 'stderr' => 'failed to start local process'];
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * `{root}/{component}` => `tree_sha256`, as the target itself reports it.
     *
     * @return array<string,string>
     */
    private static function targetInventory(EnvironmentDriver $transport, string $repo, bool $pushing = false): array {
        $result = $transport->captureWp(['wprism', 'code-inventory', '--repo=' . $repo, '--format=json']);
        if ($result['exit'] !== 0) {
            throw $pushing
                ? self::pushFailed(
                    'wprism: the target could not report the code inventory of ' . $repo
                    . ', so nothing proves what it holds',
                    $result
                )
                : self::pushUnsupported(
                    'wprism: the host cannot materialize into an ssh target, and the target could not report its own '
                    . 'code inventory (exit ' . $result['exit'] . '), so nothing proves the locked components are '
                    . 'already correct there'
                );
        }
        $decoded = json_decode(trim($result['stdout']), true);
        if (!is_array($decoded) || ($decoded['format'] ?? null) !== 'wprism-code-inventory/v1'
            || !is_array($decoded['components'] ?? null)) {
            throw $pushing
                ? self::pushFailed(
                    'wprism: the target returned an unrecognized code inventory for ' . $repo
                    . ', so nothing proves what it holds',
                    $result
                )
                : self::pushUnsupported(
                    'wprism: the host cannot materialize into an ssh target, and the target returned an unrecognized '
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

    /**
     * The refusal for a transport this host can neither WRITE nor PUSH to.
     *
     * Its bytes are unchanged, deliberately. issue #3514 landed the push for
     * `CodePushTransport` transports, so a real ssh environment no longer
     * reaches this sentence at all; what still does is a docker service with
     * no writable bind at its `repo_path` and any hand-rolled driver, and for
     * those "host-to-target push over ssh is issue #3514 and is not implemented"
     * remains exactly as true as it was. Rewording it would move a refusal
     * this issue did not change the behaviour of (AGENTS.md rule 8).
     */
    private static function pushUnsupported(string $operatorMessage): CommandRefusalException {
        return new CommandRefusalException(
            self::REASON_TRANSPORT_UNSUPPORTED,
            'this transport cannot be resolved from the host: only local and docker environments expose the '
            . 'site repository to the machine running wprism',
            'materialize the locked components on the target itself, following "Resolving a split repository" '
            . 'in docs/guides/code-updates.md, or run the deploy from a local or docker environment; host-to-target '
            . 'push over ssh is issue #3514 and is not implemented',
            [],
            $operatorMessage
        );
    }

    private static function unresolvableTransport(EnvironmentDriver $transport): CommandRefusalException {
        if ($transport->driverId() === 'docker') {
            // Reworded with the mechanism it now describes (issue #3526). The
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
                'wprism: the compose service for this environment exposes no writable bind mount at its repo_path, '
                . 'so the host side of its repository could not be identified'
            );
        }
        return self::pushUnsupported(
            'wprism: ' . $transport->driverId() . " transport '" . $transport->name()
            . "' keeps its repository on the far side of the transport, where this host cannot write"
        );
    }

    /**
     * A push that started and could not be completed (issue #3514).
     *
     * Separate from `pushUnsupported()` because it answers a different
     * question: not "this transport has no mechanism" but "the mechanism ran
     * and stopped". Every call site raises it only where the target's
     * `code/wp-content` has NOT been written, or after the post-condition read
     * found it wrong — so the remediation can say so without qualification.
     *
     * @param array{exit:int, stdout:string, stderr:string} $result
     */
    private static function pushFailed(string $operatorMessage, array $result): CommandRefusalException {
        $detail = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);

        return new CommandRefusalException(
            self::REASON_PUSH_FAILED,
            'the resolved component trees could not be pushed to this target',
            'confirm the target provides tar and an ssh account that can write its repo_path and /tmp, then '
            . 'rerun; a failed push publishes nothing, so the target is left exactly as it was',
            [],
            $operatorMessage . ($detail === '' ? '' : ': ' . $detail)
        );
    }

    /**
     * Report the code resolution a refresh/rebase/rehearse performed into its
     * compile worktrees (issue #3523).
     *
     * Rendering lives HERE, not in Refresh: `cli/src/Refresh/Refresh.php`
     * contains no `echo` at all — it returns structured results and its command
     * renders them — and the work being reported is this file's own, so it gets
     * this file's own vocabulary. A split refresh therefore prints the same
     * `RESOLVED/UNCHANGED` rows and the same `N materialized, M unchanged`
     * summary as `wprism code-resolve` and the deploy phase, differing only in the
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
        $prefix = $phasePrefix === '' ? 'wprism: code-resolve' : "wprism: $phasePrefix";
        echo $prefix . ': ' . count($rows) . ' component(s) declared in ' . $lockPath . "\n";
        $counts = ['resolved' => 0, 'unchanged' => 0, 'would-resolve' => 0];
        foreach ($rows as $row) {
            $counts[$row['state']] = ($counts[$row['state']] ?? 0) + 1;
            echo '  ' . strtoupper($row['state']) . ' ' . $row['root'] . '/' . $row['component']
                . ' ' . ($row['version'] !== '' ? $row['version'] : '(no version header)')
                . ' — ' . $row['detail'] . "\n";
        }
        if ($dryRun) {
            echo "wprism: --dry-run: nothing was fetched, written, or cached.\n";
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
        fwrite(STDERR, "wprism: $command: " . $refusal->getMessage() . "\n");
        fwrite(STDERR, '[' . $refusal->reasonCode . '] ' . $refusal->publicMessage . "\n");
        fwrite(STDERR, 'remedy: ' . $refusal->remediation . "\n");
        return 1;
    }
}

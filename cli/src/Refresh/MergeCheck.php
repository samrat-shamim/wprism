<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/Refresh.php';
// Same guard, same reason as cli/duo:71-74: RefreshPlan is a separately
// reviewable semantic layer, and this host shell must stay loadable while it
// is unavailable. requirePlanner() below then refuses by name.
if (is_file(__DIR__ . '/RefreshPlan.php')) {
    require_once __DIR__ . '/RefreshPlan.php';
}
require_once dirname(__DIR__, 3) . '/agent/src/Code/CodeSourceLock.php';

/** One refusal an operator or a CI job can act on, with its stable reason code. */
final class MergeCheckRefusal extends \RuntimeException {
    public function __construct(
        public string $reasonCode,
        string $message,
        public string $remediation,
        public ?string $diagnostic = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

/**
 * `duo merge-check` — repository-side merge validation and ref-vs-ref
 * conflict planning, with no environment, no registry and no target.
 *
 * ## What was missing
 *
 * DESIGN.md:125 defines merge as "git merge of canonical text — entity-level
 * three-way", and docs/roadmap.md:105 calls the merge story "the headline
 * capability". Every verb that could answer a question about a merged tree
 * took an `<env>` anyway, because `Refresh::assertTargetHead()`
 * (Refresh.php:540-575) refuses unless a live production target's HEAD equals
 * `--production-ref` with an empty status walk. So the operator who has just
 * run `git merge feature/x` and holds a line-merged `state/` tree had no way
 * to ask whether it is coherent without a reachable, clean production target
 * — the moment the answer is most useful is exactly the moment it was least
 * obtainable.
 *
 * ## Why this is a shell, not a second planner
 *
 * `RefreshPlan` is already pure: "This class never contacts WordPress and
 * never raw-merges state" (RefreshPlan.php:11-12). Two facts make ref-vs-ref
 * planning a pass-through into it rather than a parallel implementation:
 *
 *   1. `RefreshPlan::assertSnapshot()` (RefreshPlan.php:1045-1059) validates
 *      structure — records, deletions, media, policy, repository — and never
 *      inspects `format`. A `duo-refresh-git/v1` artifact from
 *      `compileGitWorktree()` is therefore already a legal P slot.
 *   2. `plan()` reads `$production['scope']` only when a scope contract is
 *      supplied (RefreshPlan.php:349-353). Unscoped, nothing in the planner
 *      wants anything a live export alone can produce.
 *
 * So this class assembles B/P/W from three Git refs, calls the SHIPPED
 * planner, and renders. It adds no merge semantics of its own.
 *
 * ## Non-authorizing, by three independent mechanisms
 *
 *   1. It never calls `materialize`/`materializeFieldResolved` and never
 *      opens a `RefreshRunJournal` run directory: no run id, no candidate
 *      worktree, no branch ref, nothing to `--continue`.
 *   2. The plan context carries `advisory: true` and
 *      `production_source: 'git-ref'` INSTEAD of `production_env` /
 *      `production_snapshot_hash`. Context is inside the bytes `plan_hash`
 *      covers (RefreshPlan.php:456-464), so a merge-check `plan_hash` can
 *      never collide with a refresh one.
 *   3. `Refresh::assertAuthorizingPlan()` refuses such a plan positively at
 *      both materialization entries (Refresh.php:219, :224).
 *
 * ## What it is not
 *
 * It validates a COMMITTED ref. `compileGitWorktreeWorker()` verifies
 * `HEAD == declared commit` (RefreshPlan.php:139-142) while compiling from
 * the filesystem, so accepting a dirty tree would compile bytes it then
 * labels with a commit that does not describe them — a silent lie, not an
 * ergonomics gap. A completed `git merge` leaves a clean tree anyway, so
 * mode 1 refuses a dirty canonical partition by name and says so.
 */
final class MergeCheck {
    public const FORMAT = 'duo-merge-check/v1';

    /** Exit 3 is an ANSWER, not a refusal: the tree compiled and the plan is valid. */
    public const EXIT_OK = 0;
    public const EXIT_REFUSED = 1;
    public const EXIT_USAGE = 2;
    public const EXIT_CONFLICTS = 3;

    private const PLAN_FORMAT = 'duo-refresh-plan/v1';

    /** The canonical partitions a compile reads from the filesystem. */
    private const CANONICAL_PARTITIONS = [
        'site.duo.json',
        'state',
        'media',
        'code',
        'adapters',
        'adapter-packages',
        'platform',
        'manifests',
    ];

    /**
     * @param array{ref?:?string,against?:?string,base?:?string} $options
     * @return array<string,mixed> one `duo-merge-check/v1` document
     */
    public static function run(array $options): array {
        self::requirePlanner(['compileGitWorktree', 'plan', 'normalizePlan']);
        $root = self::repositoryRoot();
        $leftRef = (string) ($options['ref'] ?? 'HEAD');
        $left = self::resolveCommit($root, $leftRef, '--ref');
        $againstRef = $options['against'] ?? null;
        self::assertCommittedTree($root, $left);

        if ($againstRef === null) {
            return self::validateOneTree($root, $left, $leftRef);
        }
        $right = self::resolveCommit($root, (string) $againstRef, '--against');
        $baseRef = $options['base'] ?? null;
        $base = $baseRef === null
            ? self::mergeBase($root, $left, $right)
            : self::resolveCommit($root, (string) $baseRef, '--base');
        return self::compareTwoRefs($root, $base, $left, $right);
    }

    /**
     * Mode 1 — does this tree compile with the real repository compiler?
     *
     * Duplicate uuid, dangling typed ref, unresolvable ledger kind, media
     * catalog mismatch and code lock digest mismatch are all already the
     * compiler's OWN refusals. Nothing is reimplemented here; the deliverable
     * is that they become obtainable with no environment at all.
     *
     * @return array<string,mixed>
     */
    private static function validateOneTree(string $root, string $left, string $leftRef): array {
        $scratch = self::scratch();
        try {
            $tree = self::addWorktree($root, $scratch, 'ref', $left);
            $artifact = self::compile($tree, $left, 'merge-check-left');
        } finally {
            self::discard($root, $scratch);
        }
        return self::document([
            'code_skew' => [],
            'coherence' => self::coherence($artifact),
            'conflicts' => [],
            'counts' => null,
            'exit_code' => self::EXIT_OK,
            'mode' => 'validate',
            'plan' => null,
            'refs' => ['base' => null, 'left' => $left, 'right' => null],
            'verdict' => 'coherent',
        ], $leftRef);
    }

    /**
     * Mode 2 — the DESIGN.md:182 Spike B question, answered from Git alone.
     *
     * W is `--ref` (the merged or working branch), P is `--against`, B is
     * their merge base. That mapping is the refresh vocabulary unchanged, so
     * `branch-only` still reads "changed only on my side" and
     * `production-only` still reads "changed only on the other side" — one
     * set of category words for one planner.
     *
     * @return array<string,mixed>
     */
    private static function compareTwoRefs(
        string $root,
        string $base,
        string $left,
        string $right
    ): array {
        $scratch = self::scratch();
        try {
            $baseTree = self::addWorktree($root, $scratch, 'base', $base);
            $leftTree = self::addWorktree($root, $scratch, 'left', $left);
            $rightTree = self::addWorktree($root, $scratch, 'right', $right);
            $baseArtifact = self::compile($baseTree, $base, 'merge-check-base');
            $leftArtifact = self::compile($leftTree, $left, 'merge-check-left');
            $rightArtifact = self::compile($rightTree, $right, 'merge-check-right');
        } finally {
            self::discard($root, $scratch);
        }

        // Context is an INPUT to plan(), not an annotation: the planner folds
        // it into the canonical bytes plan_hash covers, which is what makes
        // `advisory` unforgeable rather than cosmetic.
        $context = [
            'advisory' => true,
            'base_commit' => $base,
            'branch_commit' => $left,
            'production_commit' => $right,
            'production_source' => 'git-ref',
        ];
        try {
            $plan = RefreshPlan::plan($baseArtifact, $rightArtifact, $leftArtifact, $context);
            $plan = RefreshPlan::normalizePlan($plan);
        } catch (\Throwable $e) {
            throw new MergeCheckRefusal(
                'repository_incoherent',
                'the semantic planner could not plan these two refs',
                'read the planner diagnostic in this refusal, fix the repository, commit, and rerun merge-check',
                $e->getMessage(),
                $e
            );
        }
        if (($plan['format'] ?? null) !== self::PLAN_FORMAT || !self::isHash($plan['plan_hash'] ?? null)) {
            throw new MergeCheckRefusal(
                'repository_incoherent',
                'the semantic planner did not return a hash-bound plan',
                'reinstall or repair the semantic planner, then rerun merge-check'
            );
        }
        // Belt-and-braces at the producing end too: an advisory plan that
        // somehow lost its marker would be indistinguishable from an
        // authorizing one, so prove the marker survived the planner before
        // anything can read this document.
        self::assertAdvisory($plan);

        $conflicts = [];
        foreach ((array) ($plan['entries'] ?? []) as $entry) {
            if (!is_array($entry) || ($entry['category'] ?? null) !== 'conflicting') {
                continue;
            }
            $conflicts[] = [
                'id' => (string) ($entry['id'] ?? ''),
                'identity' => (string) ($entry['identity'] ?? ''),
                'reason' => (string) ($entry['reason'] ?? 'production_and_branch_changed_differently'),
                'type' => (string) ($entry['type'] ?? 'record'),
            ];
        }
        $codeSkew = self::codeSkew($root, $left, $right);
        $blocked = $conflicts !== [] || $codeSkew !== [];
        return self::document([
            'code_skew' => $codeSkew,
            'coherence' => self::coherence($leftArtifact),
            'conflicts' => $conflicts,
            'counts' => $plan['counts'] ?? null,
            'exit_code' => $blocked ? self::EXIT_CONFLICTS : self::EXIT_OK,
            'mode' => 'compare',
            'plan' => $plan,
            'refs' => ['base' => $base, 'left' => $left, 'right' => $right],
            'verdict' => $conflicts !== [] ? 'conflicts' : ($codeSkew !== [] ? 'code_skew' : 'clean'),
        ], null);
    }

    // The redacted field-level diff is NOT part of this verb. Recorded, not
    // half-built.
    //
    // It looked like a pass-through — `RefreshPlan::fieldDiff()` wants
    // `field_diff_policy` for all three artifacts and merge-check holds all
    // three from Git, where refresh has to source P's from the verified
    // production-code ref. It is not: `RefreshFieldDiff::project()` refuses
    // with "field-level resolution requires an exact production snapshot
    // hash" unless `context.production_snapshot_hash` is a SHA-256
    // (RefreshFieldDiff.php:97-100), and an advisory plan built from two Git
    // refs has no live snapshot to hash — that absence is the marker that
    // makes it non-authorizing in the first place.
    //
    // Relaxing that check is not a reporting change: the same projection
    // feeds `materializeFieldResolved()`, so weakening the binding for a
    // read-only report would weaken the one path that splices exact field
    // bytes into a candidate. `--field-diff` is therefore absent from
    // merge-check's surface rather than present and always refusing, and the
    // record-level conflict report is the honest slice with or without it.

    /**
     * Cross-branch plugin-version skew.
     *
     * This has nowhere else to live: `code_versions` is "overwritten, never
     * merged" (docs/guides/code-updates.md:196), so the two branches' locked
     * component versions never meet in a state merge at all. Each ref's
     * `code/duo-code.lock.json` is TRACKED even on a split repository — that
     * is the whole point of the lock (agent/src/Code/CodeSourceLock.php:70) —
     * so both sides are readable straight out of Git with no worktree and no
     * environment.
     *
     * It is a blocking merge-check answer. Merging state across different
     * plugin schemas before code-first migration and recapture is not a safe
     * advisory condition; `code_skew[]` carries the exact components and exit
     * 3 prevents a CI merge gate from silently approving the ordering defect.
     *
     * @return list<array<string,mixed>>
     */
    private static function codeSkew(string $root, string $left, string $right): array {
        $leftLock = self::lockAt($root, $left);
        $rightLock = self::lockAt($root, $right);
        if ($leftLock === null && $rightLock === null) {
            return [];
        }
        $names = array_values(array_unique(array_merge(
            array_keys($leftLock ?? []),
            array_keys($rightLock ?? [])
        )));
        sort($names, SORT_STRING);
        $rows = [];
        foreach ($names as $name) {
            $ours = $leftLock[$name] ?? null;
            $theirs = $rightLock[$name] ?? null;
            if ($ours === $theirs) {
                continue;
            }
            $rows[] = [
                'component' => $name,
                'left_version' => $ours,
                'right_version' => $theirs,
                'status' => $ours === null
                    ? 'only_in_right'
                    : ($theirs === null ? 'only_in_left' : 'version_skew'),
            ];
        }
        return $rows;
    }

    /**
     * `{root}/{component} => version` at one commit, or null when that ref
     * declares no lock (code format 1, or no code half at all).
     *
     * Every failing direction returns null on purpose: merge-check's job here
     * is a WARNING, and refusing the whole run because one side of a
     * comparison has an unparseable lock would put a compile-gate decision
     * (`code_component_unlocked`, CodeDescriptorCompiler::lock_diagnostics())
     * behind an advisory report that is not entitled to make it.
     *
     * @return ?array<string,string>
     */
    private static function lockAt(string $root, string $commit): ?array {
        try {
            $bytes = Refresh::gitStdout($root, ['cat-file', '-p', $commit . ':' . \Duo\CodeSourceLock::PATH]);
            $lock = \Duo\CodeSourceLock::parse($bytes);
        } catch (\Throwable) {
            return null;
        }
        $versions = [];
        foreach ((array) ($lock['components'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $versions[(string) $entry['root'] . '/' . (string) $entry['component']] = (string) $entry['version'];
        }
        ksort($versions, SORT_STRING);
        return $versions;
    }

    /** @return array<string,mixed> */
    private static function coherence(array $artifact): array {
        return [
            'artifact_hash' => (string) ($artifact['repository']['artifact_hash'] ?? ''),
            'compiled' => true,
            'manifest_hash' => (string) ($artifact['policy']['manifest_hash'] ?? ''),
            'site_hash' => (string) ($artifact['policy']['site_hash'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $fields @return array<string,mixed> */
    private static function document(array $fields, ?string $leftRef): array {
        $document = ['format' => self::FORMAT] + $fields;
        if ($leftRef !== null) {
            $document['requested_ref'] = $leftRef;
        }
        ksort($document, SORT_STRING);
        return $document;
    }

    /** The advisory marker must survive the planner, or the document is not publishable. */
    private static function assertAdvisory(array $plan): void {
        $context = $plan['context'] ?? null;
        if (!is_array($context) || ($context['advisory'] ?? null) !== true
            || ($context['production_source'] ?? null) !== 'git-ref'
            || array_key_exists('production_env', $context)
            || array_key_exists('production_snapshot_hash', $context)) {
            throw new MergeCheckRefusal(
                'repository_incoherent',
                'the planner did not bind the advisory, non-authorizing plan context',
                'reinstall or repair the semantic planner, then rerun merge-check'
            );
        }
    }

    // ------------------------------------------------------------------ git

    /**
     * The enclosing Git worktree root.
     *
     * `Refresh::repositoryRoot()` is not reused: its diagnostic names
     * "refresh/rebase" (Refresh.php:853-859), and AGENTS.md rule 8 keeps a shipped
     * refusal sentence byte-identical. Its three transport-free helpers ARE
     * reused; only the two sentences that name another verb are restated.
     */
    private static function repositoryRoot(): string {
        try {
            $root = Refresh::gitStdout(getcwd() ?: '.', ['rev-parse', '--show-toplevel']);
        } catch (\Throwable $e) {
            throw new MergeCheckRefusal(
                'ref_unresolvable',
                'merge-check must run inside the site repository it validates',
                'cd into the site repository (the directory holding site.duo.json) and rerun merge-check',
                $e->getMessage(),
                $e
            );
        }
        if ($root === '' || !is_dir($root)) {
            throw new MergeCheckRefusal(
                'ref_unresolvable',
                'merge-check must run inside the site repository it validates',
                'cd into the site repository (the directory holding site.duo.json) and rerun merge-check'
            );
        }
        return $root;
    }

    private static function resolveCommit(string $root, string $ref, string $flag): string {
        if ($ref === '' || str_starts_with($ref, '-') || str_contains($ref, "\0")) {
            throw new MergeCheckRefusal(
                'ref_unresolvable',
                "$flag must name one reachable Git commit",
                "pass $flag=<branch|tag|sha> that this repository can resolve"
            );
        }
        try {
            return Refresh::gitStdout($root, ['rev-parse', '--verify', $ref . '^{commit}']);
        } catch (\Throwable $e) {
            throw new MergeCheckRefusal(
                'ref_unresolvable',
                "$flag does not resolve to a commit in this repository",
                "pass $flag=<branch|tag|sha> that this repository can resolve",
                $e->getMessage(),
                $e
            );
        }
    }

    private static function mergeBase(string $root, string $left, string $right): string {
        try {
            return Refresh::gitStdout($root, ['merge-base', $left, $right]);
        } catch (\Throwable $e) {
            throw new MergeCheckRefusal(
                'ref_unresolvable',
                'the two refs share no merge base, so there is no B to plan against',
                'pass --base=<ref> naming the common ancestor these two branches should be compared through',
                $e->getMessage(),
                $e
            );
        }
    }

    /**
     * Refuse a dirty canonical partition when the operator is asking about
     * the tree they are looking at.
     *
     * Only then: a compile always runs in a detached worktree at the resolved
     * commit, so naming some OTHER ref can mislead nobody. But `merge-check`
     * with no `--ref` reads as "check what I have", and answering that from
     * the last commit while `state/` holds uncommitted bytes would be a
     * confidently wrong answer — the one outcome this verb exists to prevent.
     */
    private static function assertCommittedTree(string $root, string $left): void {
        try {
            $head = Refresh::gitStdout($root, ['rev-parse', '--verify', 'HEAD^{commit}']);
        } catch (\Throwable) {
            return; // an unborn HEAD cannot be the tree the operator means
        }
        if (!hash_equals($head, $left)) {
            return;
        }
        $status = Refresh::run(array_merge(
            ['git', '-C', $root, 'status', '--porcelain=v1', '--untracked-files=all', '--'],
            self::CANONICAL_PARTITIONS
        ));
        if (($status['exit'] ?? 1) !== 0 || trim((string) $status['stdout']) === '') {
            return;
        }
        throw new MergeCheckRefusal(
            'working_tree_dirty',
            'merge-check validates a committed ref and this checkout has uncommitted canonical changes',
            'commit the merge (git add -A && git commit), then rerun merge-check; '
                . 'or name an already-committed ref with --ref=<ref>',
            trim((string) $status['stdout'])
        );
    }

    /** A scratch directory that is NOT a refresh run: merge-check creates no run identity. */
    private static function scratch(): string {
        $scratch = rtrim(sys_get_temp_dir(), '/') . '/duo-merge-check-' . bin2hex(random_bytes(8));
        if (!mkdir($scratch, 0700, true) && !is_dir($scratch)) {
            throw new MergeCheckRefusal(
                'merge_check_failed',
                'merge-check could not create its local scratch directory',
                'ensure the system temporary directory is writable, then rerun merge-check'
            );
        }
        return $scratch;
    }

    private static function addWorktree(string $root, string $scratch, string $slot, string $commit): string {
        $tree = $scratch . '/' . $slot;
        Refresh::git($root, ['worktree', 'add', '--detach', $tree, $commit]);
        // Same reason as Refresh::prepare():346-350 — a split repository keeps
        // each locked component out of Git, so a bare checkout has no
        // code/wp-content and the compiler correctly refuses it.
        Refresh::materializeLockedCode($tree);
        return $tree;
    }

    /** @return array<string,mixed> */
    private static function compile(string $tree, string $commit, string $label): array {
        try {
            $artifact = RefreshPlan::compileGitWorktree($tree, $commit, $label);
        } catch (\Throwable $e) {
            throw new MergeCheckRefusal(
                'repository_incoherent',
                'the repository does not compile at this ref',
                'read the compiler diagnostic in this refusal, fix the named surface, commit, and rerun merge-check',
                $e->getMessage(),
                $e
            );
        }
        if (!is_array($artifact) || !is_array($artifact['policy'] ?? null)
            || !is_array($artifact['repository'] ?? null)) {
            throw new MergeCheckRefusal(
                'repository_incoherent',
                'the repository compiler did not return a normalized artifact',
                'reinstall or repair the semantic planner, then rerun merge-check'
            );
        }
        return $artifact;
    }

    /** Remove every worktree this run added, then the scratch directory itself. */
    private static function discard(string $root, string $scratch): void {
        foreach (['base', 'left', 'right', 'ref'] as $slot) {
            $tree = $scratch . '/' . $slot;
            if (is_dir($tree) || is_file($tree . '/.git')) {
                try {
                    Refresh::removeWorktree($root, $tree, false);
                } catch (\Throwable) {
                    // Reported by the caller's own refusal, if any; a stuck
                    // scratch worktree must not replace the real diagnostic.
                }
            }
        }
        try {
            Refresh::git($root, ['worktree', 'prune']);
        } catch (\Throwable) {
            // ditto
        }
        @rmdir($scratch);
    }

    /** @param list<string> $methods */
    private static function requirePlanner(array $methods): void {
        foreach ($methods as $method) {
            if (!class_exists(RefreshPlan::class) || !method_exists(RefreshPlan::class, $method)) {
                throw new MergeCheckRefusal(
                    'merge_check_failed',
                    'the semantic planner this host verb reads is unavailable',
                    'reinstall the orchestrator so cli/src/Refresh/RefreshPlan.php is present, then rerun merge-check'
                );
            }
        }
    }

    private static function isHash(mixed $value): bool {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}

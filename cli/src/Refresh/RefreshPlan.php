<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/RefreshFieldDiff.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/MediaPayloadAuthority.php';

/**
 * Pure semantic B/P/W planner and local state materializer for Refresh.
 *
 * Git supplies validated repository snapshots; only refresh-export supplies
 * production.  This class never contacts WordPress and never raw-merges state.
 */
final class RefreshPlan {
    private const GIT_FORMAT = 'duo-refresh-git/v1';
    private const PRODUCTION_FORMAT = 'duo-refresh-production/v1';
    private const PLAN_FORMAT = 'duo-refresh-plan/v1';
    private const MATERIALIZATION_FORMAT = 'duo-refresh-materialization/v1';

    /** @return array<string,mixed> */
    public static function normalizeProductionSnapshot(array $raw): array {
        self::loadCompiler();
        if (($raw['format'] ?? null) !== self::PRODUCTION_FORMAT) {
            throw new \RuntimeException('production snapshot has an unsupported format');
        }
        $claimed = (string) ($raw['snapshot_hash'] ?? '');
        $basis = $raw;
        unset($basis['snapshot_hash']);
        if (!self::isHash($claimed) || !hash_equals($claimed, hash('sha256', \Duo\Canon::encode($basis)))) {
            throw new \RuntimeException('production snapshot hash does not verify');
        }
        self::assertSnapshot($raw, 'production');
        ksort($raw['records'], SORT_STRING);
        ksort($raw['deletions'], SORT_STRING);
        ksort($raw['media'], SORT_STRING);
        return $raw;
    }

    /**
     * Every worktree role this planner will compile.
     *
     * `base`, `branch`, `production-code` and `candidate` are Refresh's four
     * (Refresh.php:347-349, :189). The three `merge-check-*` roles are the
     * same three positions assembled entirely from Git by
     * `MergeCheck::run()` — B, W (`--ref`) and P (`--against`) — and they are
     * a separate tag rather than a reuse of the refresh names on purpose:
     * every artifact this planner emits records its `label` (:219), that
     * label is what a worker failure names in its diagnostic (:111), and an
     * advisory merge-check artifact must never read in a log or a stack trace
     * as though a live refresh had produced it.
     *
     * @var list<string>
     */
    private const WORKTREE_ROLES = [
        'base', 'branch', 'production-code', 'candidate',
        'merge-check-base', 'merge-check-left', 'merge-check-right',
    ];

    /**
     * The roles compiled with `compile_for_diff()` rather than `compile()`.
     *
     * B is the merge base: it supplies the "what did both sides start from"
     * comparison only, never bytes anyone materializes, which is why refresh
     * has always compiled it in the cheaper diff mode (:148-150). A
     * merge-check base is that same position, so it takes that same mode.
     *
     * @var list<string>
     */
    private const DIFF_ONLY_ROLES = ['base', 'merge-check-base'];

    /** Compile each ref in a fresh process so provider classes cannot leak between refs. */
    public static function compileGitWorktree(
        string $path,
        string $commit,
        string $label = '',
        ?array $scopeContract = null,
        bool $completeMedia = false
    ): array {
        if (!in_array($label, self::WORKTREE_ROLES, true)) {
            throw new \RuntimeException("cannot compile unknown '$label' Git worktree role");
        }
        $worker = __DIR__ . '/RefreshPlanCompile.php';
        if (!is_file($worker)) {
            throw new \RuntimeException('refresh compiler worker is unavailable');
        }
        $scopePath = null;
        $args = [PHP_BINARY, $worker, $path, $commit, $label];
        if ($scopeContract !== null) {
            self::loadCompiler();
            $scopeContract = \Duo\ScopeContract::from_array($scopeContract);
            $scopePath = tempnam(sys_get_temp_dir(), 'duo-refresh-scope-');
            if ($scopePath === false
                || file_put_contents($scopePath, \Duo\Canon::encode($scopeContract), LOCK_EX) === false) {
                throw new \RuntimeException('cannot stage immutable scope evidence for candidate validation');
            }
            @chmod($scopePath, 0600);
            $args[] = 'candidate';
            $args[] = $scopePath;
        } elseif ($completeMedia) {
            $args[] = 'complete-media';
        }
        try {
            $result = self::runProcess($args);
        } finally {
            if ($scopePath !== null && is_file($scopePath)) {
                @unlink($scopePath);
            }
        }
        if ($result['exit'] !== 0) {
            $reason = trim($result['stderr']) ?: trim($result['stdout']);
            throw new \RuntimeException("cannot compile $label Git worktree: " . ($reason !== '' ? $reason : 'worker failed'));
        }
        try {
            $compiled = json_decode(trim($result['stdout']), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new \RuntimeException("cannot decode $label Git compiler result: " . $e->getMessage());
        }
        if (!is_array($compiled)) {
            throw new \RuntimeException("cannot compile $label Git worktree: worker returned no artifact");
        }
        self::assertSnapshot($compiled, $label);
        return $compiled;
    }

    /** Fresh-process entrypoint; public only for RefreshPlanCompile.php. */
    public static function compileGitWorktreeWorker(
        string $path,
        string $commit,
        string $label,
        ?string $scopeMode = null,
        ?string $scopePath = null
    ): array {
        self::loadCompiler();
        $root = realpath($path);
        if (!self::isCommit($commit) || $root === false || !is_file($root . '/site.duo.json')
            || !in_array($label, self::WORKTREE_ROLES, true)) {
            throw new \RuntimeException("cannot compile $label Git worktree");
        }
        $head = self::runProcess(['git', '-C', $root, 'rev-parse', '--verify', 'HEAD^{commit}']);
        if ($head['exit'] !== 0 || !hash_equals($commit, trim($head['stdout']))) {
            throw new \RuntimeException("$label Git worktree HEAD does not match its declared commit");
        }
        [$compiled, $fieldDiffPolicy] = self::withGitWorktreePolicy(
            $root,
            static function (\Duo\Policy $policy) use ($root, $label, $scopeMode, $scopePath): array {
                $fieldDiffPolicy = self::fieldDiffPolicyProjection($policy);
                $compiled = in_array($label, self::DIFF_ONLY_ROLES, true)
                    ? \Duo\RepositoryCompiler::compile_for_diff($root, $policy)
                    : \Duo\RepositoryCompiler::compile($root, $policy);
                if ($scopeMode === 'candidate' || $scopePath !== null) {
                    if ($scopeMode !== 'candidate' || $scopePath === null || !is_file($scopePath)) {
                        throw new \RuntimeException('cannot validate scoped candidate: malformed worker request');
                    }
                    $decoded = \Duo\Canon::decode(\Duo\Canon::read_file($scopePath));
                    if (!is_array($decoded)) {
                        throw new \RuntimeException('cannot validate scoped candidate: contract is not an object');
                    }
                    $contract = \Duo\ScopeContract::from_array($decoded);
                    $sourceTombstones = array_fill_keys(
                        array_map('strval', array_column((array) $contract['tombstones'], 'uuid')),
                        true
                    );
                    $selected = array_fill_keys(\Duo\ScopedStateOverlay::selected_identities($contract), true);
                    $authorizedDeletions = [];
                    foreach (array_keys($compiled->deletions()) as $identity) {
                        $identity = (string) $identity;
                        if (isset($selected[$identity]) && !isset($sourceTombstones[$identity])) {
                            $authorizedDeletions[] = $identity;
                        }
                    }
                    \Duo\ScopeContract::assert_candidate_bounded(
                        $contract,
                        $compiled,
                        $policy,
                        $authorizedDeletions
                    );
                } elseif ($scopeMode !== null && $scopeMode !== 'complete-media') {
                    throw new \RuntimeException('cannot validate scoped candidate: malformed worker request');
                }

                return [$compiled, $fieldDiffPolicy];
            }
        );
        $artifact = $compiled->export();
        $records = [];
        foreach ($compiled->tree() as $identity => $row) {
            $records[(string) $identity] = self::recordFromCompiled((string) $identity, $row);
        }
        $deletions = [];
        foreach ($compiled->deletions() as $identity => $row) {
            $deletions[(string) $identity] = self::recordFromCompiled((string) $identity, $row);
        }
        ksort($records, SORT_STRING);
        ksort($deletions, SORT_STRING);
        // A scoped refresh starts from exact W bytes, including safe orphan
        // content-addressed blobs. The artifact's `media` field contains
        // only referenced payloads, while `media_catalog` deliberately binds
        // every direct media/ file. Read and verify that complete catalog
        // here so materializeScoped() cannot silently delete W orphans.
        $media = is_array($artifact['media'] ?? null) ? $artifact['media'] : [];
        if (in_array($scopeMode, ['candidate', 'complete-media'], true)) {
            $media = [];
            $catalog = [];
            $catalogBytes = 0;
            foreach ((array) ($artifact['media_catalog'] ?? []) as $name => $expected) {
                $name = (string) $name;
                $path = rtrim($root, '/') . '/media/' . $name;
                try {
                    $parsed = \Duo\MediaPayloadAuthority::parseMediaName($name);
                    $observed = \Duo\MediaPayloadAuthority::observeCatalogFile($path, $name);
                    if (!self::isHash($expected)
                        || !hash_equals($parsed['sha256'], (string) $expected)
                        || !hash_equals($observed['sha256'], (string) $expected)) {
                        throw new \RuntimeException('content address mismatch');
                    }
                    $catalogBytes = \Duo\MediaPayloadAuthority::addToAggregate($catalogBytes, $observed['size']);
                    $catalog[$name] = (string) $expected;
                } catch (\Throwable $failure) {
                    throw new \RuntimeException("compiled media catalog entry '$name' cannot be verified");
                }
            }
            \Duo\MediaPayloadAuthority::assertRefreshExportHeadroom($catalogBytes);
            foreach ($catalog as $name => $expected) {
                try {
                    $bytes = \Duo\MediaPayloadAuthority::readCatalogBlob(
                        rtrim($root, '/') . '/media/' . $name,
                        $name
                    );
                } catch (\Throwable $failure) {
                    throw new \RuntimeException("compiled media catalog entry '$name' cannot be verified");
                }
                $media[$name] = ['sha256' => (string) $expected, 'base64' => base64_encode($bytes)];
            }
        }
        ksort($media, SORT_STRING);
        self::assertMediaPayloadBudget($media);
        return [
            'format' => self::GIT_FORMAT,
            'commit' => $commit,
            'label' => $label,
            'records' => $records,
            'deletions' => $deletions,
            'media' => $media,
            'policy' => [
                'site_hash' => $compiled->site_hash(),
                'manifest_hash' => $compiled->manifest_hash(),
                'resolved_adapters' => $compiled->resolved_adapters(),
            ],
            'field_diff_policy' => $fieldDiffPolicy,
            'completed_code' => $compiled->code_descriptor() === null ? null : [
                'revision' => (string) $compiled->code_revision(),
                'descriptor' => $compiled->code_descriptor(),
            ],
            'repository' => [
                'artifact_hash' => $compiled->artifact_hash(),
                'revision_hash' => $compiled->revision_hash(),
                'code_revision' => $compiled->code_revision(),
            ],
        ];
    }

    /**
     * Load policy only in a fresh process after code replay. Unlike a full
     * repository compiler this does not inspect pre-materialization state.
     */
    public static function fieldDiffPolicyFromGitWorktree(string $path, string $commit, string $label = 'candidate'): array {
        if ($label !== 'candidate') {
            throw new \RuntimeException('cannot read field policy for an unknown Git worktree role');
        }
        $worker = __DIR__ . '/RefreshPlanCompile.php';
        if (!is_file($worker)) {
            throw new \RuntimeException('refresh compiler worker is unavailable');
        }
        $result = self::runProcess([PHP_BINARY, $worker, $path, $commit, $label, 'field-diff-policy']);
        if ($result['exit'] !== 0) {
            $reason = trim($result['stderr']) ?: trim($result['stdout']);
            throw new \RuntimeException('cannot load candidate field policy: ' . ($reason !== '' ? $reason : 'worker failed'));
        }
        try {
            $projection = json_decode(trim($result['stdout']), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new \RuntimeException('cannot decode candidate field policy: ' . $e->getMessage());
        }
        if (!is_array($projection)) {
            throw new \RuntimeException('cannot load candidate field policy: worker returned no projection');
        }
        return RefreshFieldDiff::normalizePolicyProjection($projection);
    }

    /** Fresh-process entrypoint; public only for RefreshPlanCompile.php. */
    public static function fieldDiffPolicyWorker(string $path, string $commit, string $label): array {
        self::loadCompiler();
        $root = realpath($path);
        if ($label !== 'candidate' || !self::isCommit($commit) || $root === false || !is_file($root . '/site.duo.json')) {
            throw new \RuntimeException('cannot load candidate field policy');
        }
        $head = self::runProcess(['git', '-C', $root, 'rev-parse', '--verify', 'HEAD^{commit}']);
        if ($head['exit'] !== 0 || !hash_equals($commit, trim($head['stdout']))) {
            throw new \RuntimeException('candidate Git worktree HEAD does not match its declared commit');
        }
        return self::withGitWorktreePolicy(
            $root,
            static fn(\Duo\Policy $policy): array => self::fieldDiffPolicyProjection($policy)
        );
    }

    /** Run against a ref's package library without changing process-global policy state. */
    private static function withGitWorktreePolicy(string $root, callable $operation): mixed {
        $packages = $root . '/adapter-packages';
        $platform = $root . '/platform/adapter-library';
        if (file_exists($packages) || is_link($packages) || file_exists($platform) || is_link($platform)) {
            return $operation(
                \Duo\Policy::load(
                    $root,
                    null,
                    false,
                    null,
                    \Duo\AdapterLibrary::fromSourceTree($root)
                )
            );
        }

        // Transitional only: old Git refs and deliberately sparse regression
        // repositories expose the pre-package authoring input. Keep their
        // override bounded to this one load; package-layout refs never enter
        // this process-global branch and cannot fall back to flat bytes.
        $old = getenv('DUO_MANIFESTS_DIR');
        putenv('DUO_MANIFESTS_DIR=' . $root . '/manifests');
        try {
            return $operation(\Duo\Policy::load($root));
        } finally {
            $old === false ? putenv('DUO_MANIFESTS_DIR') : putenv('DUO_MANIFESTS_DIR=' . $old);
        }
    }

    /** @return array<string,mixed> */
    private static function fieldDiffPolicyProjection(\Duo\Policy $policy): array {
        $types = array_values(array_unique(array_merge($policy->post_types(), $policy->declared_post_types())));
        sort($types, SORT_STRING);
        $derived = [];
        foreach ($types as $type) {
            $fields = [];
            foreach (array_keys(\Duo\Policy::DERIVABLE_FIELD_COLUMNS) as $field) {
                if ($policy->field_class((string) $type, (string) $field) === 'derived') {
                    $fields[] = (string) $field;
                }
            }
            sort($fields, SORT_STRING);
            $derived[(string) $type] = $fields;
        }
        $projection = [
            'derived_post_fields' => $derived,
            'format' => RefreshFieldDiff::POLICY_FORMAT,
            'manifest_hash' => \Duo\RepositoryCompiler::manifest_hash($policy),
            'resolved_adapters_sha256' => hash('sha256', \Duo\Canon::encode(\Duo\RepositoryCompiler::resolved_adapters($policy))),
            'state_site_hash' => \Duo\RepositoryCompiler::state_site_hash($policy),
        ];
        $projection['projection_hash'] = hash('sha256', \Duo\Canon::encode($projection));
        return RefreshFieldDiff::normalizePolicyProjection($projection);
    }

    /** Production code/policy must be exactly the verified production ref. */
    public static function assertProductionCodeMatches(array $production, array $productionRef): void {
        self::assertSnapshot($production, 'production');
        self::assertSnapshot($productionRef, 'production-ref');
        foreach (['site_hash', 'manifest_hash'] as $key) {
            if (!hash_equals((string) ($production['policy'][$key] ?? ''), (string) ($productionRef['policy'][$key] ?? ''))) {
                throw new \RuntimeException("production $key does not match --production-ref");
            }
        }
        if (self::encode($production['policy']['resolved_adapters'] ?? null)
            !== self::encode($productionRef['policy']['resolved_adapters'] ?? null)) {
            throw new \RuntimeException('production adapter contract does not match --production-ref');
        }
        $live = $production['completed_code'] ?? null;
        $ref = $productionRef['completed_code'] ?? null;
        if (($live === null) !== ($ref === null)
            || ($live !== null && ((string) ($live['revision'] ?? '') !== (string) ($ref['revision'] ?? '')
                || self::encode($live['descriptor'] ?? null) !== self::encode($ref['descriptor'] ?? null)))) {
            throw new \RuntimeException('completed production code does not match --production-ref');
        }
    }

    /** @return array<string,mixed> */
    public static function plan(array $base, array $production, array $branch, array $context = []): array {
        self::assertSnapshot($base, 'base');
        self::assertSnapshot($production, 'production');
        self::assertSnapshot($branch, 'branch');
        $scopeContract = is_array($context['scope_contract'] ?? null)
            ? $context['scope_contract']
            : null;
        $selectedScope = [];
        if ($scopeContract !== null) {
            self::loadCompiler();
            $scopeContract = \Duo\ScopeContract::from_array($scopeContract);
            $scopeEvidence = $production['scope'] ?? null;
            if (!is_array($scopeEvidence)
                || ($scopeEvidence['format'] ?? null) !== 'duo-refresh-scope/v1'
                || ($scopeEvidence['out_of_scope'] ?? null) !== 'omitted_not_absent'
                || !hash_equals((string) $scopeContract['scope_hash'], (string) ($scopeEvidence['scope_hash'] ?? ''))) {
                throw new \RuntimeException('scoped production snapshot does not match plan context');
            }
            foreach (\Duo\ScopedStateOverlay::selected_identities($scopeContract) as $identity) {
                $selectedScope[$identity] = true;
            }
            foreach (\Duo\ScopeContract::option_root_names($scopeContract) as $name) {
                $selectedScope['option:' . $name] = true;
            }
        }
        // Per-option three-way planning applies uniformly whether or not a
        // scope contract is present: expand() always explodes options/core
        // into one virtual identity per authored option name. An option-root
        // scope grants only that record; materializeScoped() recombines the
        // in-scope result into the carrier document while preserving every
        // excluded option's baseline bytes untouched.
        $snapshots = [
            'base' => self::expand($base),
            'production' => self::expand($production),
            'branch' => self::expand($branch),
        ];
        $keys = [];
        foreach ($snapshots as $snapshot) {
            foreach (array_keys($snapshot['states']) as $key) $keys[$key] = true;
        }
        ksort($keys, SORT_STRING);
        $resolution = is_array($context['resolution'] ?? null) ? $context['resolution'] : [];
        $strategy = (string) ($resolution['strategy'] ?? $context['strategy'] ?? 'manual');
        $perRecord = is_array($resolution['records'] ?? null)
            ? $resolution['records']
            : (is_array($context['resolutions'] ?? null) ? $context['resolutions'] : []);
        if (!in_array($strategy, ['manual', 'ours', 'theirs'], true)) {
            throw new \RuntimeException('refresh strategy must be manual, ours, or theirs');
        }
        $entries = [];
        $usedResolutions = [];
        foreach (array_keys($keys) as $identity) {
            $b = $snapshots['base']['states'][$identity] ?? null;
            $p = $snapshots['production']['states'][$identity] ?? null;
            $w = $snapshots['branch']['states'][$identity] ?? null;
            $inScope = $scopeContract === null
                || isset($selectedScope[$identity])
                || (str_starts_with($identity, 'option:') && isset($selectedScope['options/core']));
            // Omitted production rows are explicitly not absence. Outside a
            // scoped export, P is semantically B for categorization and W is
            // always retained by the overlay materializer.
            $effectiveP = $inScope ? $p : $b;
            $bp = self::same($b, $effectiveP);
            $bw = self::same($b, $w);
            $pw = self::same($effectiveP, $w);
            if ($bp && $bw) $category = 'unchanged';
            elseif ($bw) $category = 'production-only';
            elseif ($bp) $category = 'branch-only';
            elseif ($pw) $category = 'compatible';
            else $category = 'conflicting';

            $unsafeAbsence = $inScope
                && (self::unsafeAbsence($b, $effectiveP) || self::unsafeAbsence($b, $w));
            if ($unsafeAbsence) $category = 'conflicting';
            // A tombstone is a state, not a new entity kind. Prefer B's live
            // type so deleting a branch record does not rename its conflict
            // from e.g. post:<uuid> to deletion:<uuid>.
            $type = (string) (($b ?? $w ?? $p)['type'] ?? 'record');
            $id = str_starts_with($identity, 'option:') ? $identity : $type . ':' . $identity;
            $choice = null;
            if ($category === 'conflicting') {
                $choice = $perRecord[$id] ?? ($strategy === 'manual' ? null : $strategy);
                if ($choice !== null && !in_array($choice, ['ours', 'theirs'], true)) {
                    throw new \RuntimeException("refresh resolution '$id' must be ours or theirs");
                }
                if (array_key_exists($id, $perRecord)) $usedResolutions[$id] = true;
            }
            $selectedSource = !$inScope ? 'branch' : match ($category) {
                'unchanged' => $w !== null ? 'branch' : ($p !== null ? 'production' : 'base'),
                'production-only' => 'production',
                'branch-only', 'compatible' => 'branch',
                default => $choice === 'ours' ? 'branch' : ($choice === 'theirs' ? 'production' : null),
            };
            $selected = $selectedSource === null ? null : ($snapshots[$selectedSource]['states'][$identity] ?? null);
            if ($selectedSource !== null && self::unsafeAbsence($b, $selected)) {
                throw new \RuntimeException("resolution '$id' selects absence without deletion authority");
            }
            $entries[] = [
                'id' => $id,
                'identity' => $identity,
                'type' => $type,
                'category' => $category,
                'reason' => $unsafeAbsence
                    ? 'absence_without_tombstone'
                    : ($category === 'conflicting' ? 'production_and_branch_changed_differently' : null),
                'versions' => ['base' => $b, 'production' => $p, 'branch' => $w],
                'resolution' => $choice,
                'selected_source' => $selectedSource,
                'selected' => $selected,
                'in_scope' => $inScope,
                'production_omitted' => !$inScope,
            ];
        }
        foreach (array_keys($perRecord) as $id) {
            if (!isset($usedResolutions[$id])) {
                throw new \RuntimeException("refresh resolution '$id' is stale or does not name a conflict");
            }
        }
        $safeContext = $context;
        unset($safeContext['repo_root']);
        $plan = [
            'format' => self::PLAN_FORMAT,
            'context' => $safeContext,
            'strategy' => $strategy,
            'entries' => $entries,
            'media' => [
                'base' => $snapshots['base']['media'],
                'production' => $snapshots['production']['media'],
                'branch' => $snapshots['branch']['media'],
            ],
        ];
        if ($scopeContract !== null) {
            $plan['scope_baseline'] = [
                'format' => 'duo-refresh-scope-baseline/v1',
                'records' => $branch['records'],
                'deletions' => $branch['deletions'],
                'media' => $branch['media'],
                'repository' => $branch['repository'],
                'scope_hash' => (string) $scopeContract['scope_hash'],
            ];
        }
        return self::normalizePlan($plan);
    }

    /**
     * Build the separate redacted field projection without changing the
     * private plan or its historical plan_hash contract. P policy evidence is
     * supplied by the verified production-code ref, never by live export.
     *
     * @return array{diff:array<string,mixed>,bundle:array<string,mixed>}
     */
    public static function fieldDiff(array $plan, array $base, array $productionCode, array $branch): array {
        $plan = self::normalizePlan($plan);
        return RefreshFieldDiff::project($plan, [
            'base' => $base['field_diff_policy'] ?? null,
            'production' => $productionCode['field_diff_policy'] ?? null,
            'branch' => $branch['field_diff_policy'] ?? null,
        ]);
    }

    /** @return array<string,mixed> */
    public static function validateFieldDiff(array $diff): array {
        return RefreshFieldDiff::validateDiff($diff);
    }

    /** @return array<string,mixed> */
    public static function readFieldResolution(array $diff, string $path): array {
        return RefreshFieldDiff::readResolutionFile($path, $diff);
    }

    /**
     * Local-only TTY context. Unlike the public field diff, this carries
     * bounded sanitized labels and is intentionally never persisted.
     *
     * @return array<string,mixed>
     */
    public static function interactiveFieldPresentation(array $plan, array $diff, array $bundle): array {
        return RefreshFieldDiff::interactivePresentation($plan, $diff, $bundle);
    }

    /** @param resource $in @param resource $out @return ?array<string,mixed> */
    public static function interactiveFieldResolution(array $diff, $in, $out, ?array $presentation = null): ?array {
        return RefreshFieldDiff::interactiveResolution($diff, $in, $out, $presentation);
    }

    public static function assertFieldCandidatePolicy(array $bundle, array $candidatePolicy): void {
        RefreshFieldDiff::assertCandidatePolicy($bundle, $candidatePolicy);
    }

    /** Sort and hash a plan, verifying a supplied hash if present. */
    public static function normalizePlan(array $plan): array {
        if (($plan['format'] ?? null) !== self::PLAN_FORMAT || !is_array($plan['entries'] ?? null)) {
            throw new \RuntimeException('refresh planner produced a malformed plan');
        }
        usort($plan['entries'], static fn(array $a, array $b): int => (string) ($a['id'] ?? '') <=> (string) ($b['id'] ?? ''));
        $seen = [];
        $counts = array_fill_keys(['unchanged', 'production-only', 'branch-only', 'compatible', 'conflicting'], 0);
        $unresolved = [];
        $scoped = is_array($plan['context']['scope_contract'] ?? null);
        $scopeCounts = array_fill_keys(['unchanged', 'production-only', 'branch-only', 'compatible', 'conflicting'], 0);
        foreach ($plan['entries'] as $entry) {
            $id = (string) ($entry['id'] ?? '');
            $category = (string) ($entry['category'] ?? '');
            if ($id === '' || isset($seen[$id]) || !isset($counts[$category])) {
                throw new \RuntimeException('refresh plan has an invalid or duplicate entry');
            }
            $seen[$id] = true;
            $counts[$category]++;
            if ($scoped && !is_bool($entry['in_scope'] ?? null)) {
                throw new \RuntimeException('scoped refresh plan entry lacks an exact in_scope decision');
            }
            $inScope = !$scoped || $entry['in_scope'] === true;
            if ($inScope) {
                $scopeCounts[$category]++;
            }
            if ($inScope && $category === 'conflicting' && ($entry['selected_source'] ?? null) === null) $unresolved[] = $id;
        }
        $plan['counts'] = $counts;
        $plan['unresolved'] = $unresolved;
        if ($scoped) {
            $plan['scope_counts'] = $scopeCounts;
        }
        $claimed = $plan['plan_hash'] ?? null;
        unset($plan['plan_hash']);
        $actual = hash('sha256', self::encode($plan));
        if ($claimed !== null && (!self::isHash($claimed) || !hash_equals((string) $claimed, $actual))) {
            throw new \RuntimeException('refresh plan hash does not verify');
        }
        $plan['plan_hash'] = $actual;
        return $plan;
    }

    /** Write resolved semantic state/media into the disposable worktree. */
    public static function materialize(array $plan, string $worktree, array $resolution = []): array {
        $plan = self::normalizePlan($plan);
        $planHash = $plan['plan_hash'];
        $plan = self::applyResolution($plan, $resolution);
        return self::materializeResolved($plan, $planHash, $worktree, $resolution);
    }

    /**
     * Materialize a field-resolution run without feeding it through the
     * legacy whole-record resolver. The private span bundle never reaches a
     * journal or receipt.
     */
    public static function materializeFieldResolved(
        array $plan,
        string $worktree,
        array $bundle,
        array $fieldResolution
    ): array {
        $plan = self::normalizePlan($plan);
        if (is_array($plan['context'] ?? null) && array_key_exists('scope_contract', $plan['context'])) {
            throw new \RuntimeException('field-level resolution is unavailable for scoped refresh plans');
        }
        $planHash = $plan['plan_hash'];
        $plan = RefreshFieldDiff::apply($plan, $bundle, $fieldResolution);
        $receipt = self::materializeResolved($plan, $planHash, $worktree, $fieldResolution);
        $receipt['field_diff_hash'] = (string) ($bundle['diff_hash'] ?? '');
        $receipt['field_resolution_hash'] = (string) ($fieldResolution['resolution_hash'] ?? '');
        return $receipt;
    }

    /** @param array<string,mixed> $resolution */
    private static function materializeResolved(array $plan, string $planHash, string $worktree, array $resolution): array {
        $root = realpath($worktree);
        if ($root === false || !is_dir($root) || !is_file($root . '/.git')) {
            throw new \RuntimeException('refresh materializer requires a disposable Git worktree');
        }
        if (is_array($plan['context']['scope_contract'] ?? null)) {
            return self::materializeScoped($plan, $planHash, $root, $resolution);
        }
        $stateRows = [];
        $options = [];
        $mediaNeeded = [];
        foreach ($plan['entries'] as $entry) {
            $row = $entry['selected'] ?? null;
            if ($row === null) continue;
            if (($row['virtual'] ?? null) === 'option') {
                $options[(string) $row['option_name']] = \Duo\Canon::decode((string) $row['content']);
                continue;
            }
            $path = (string) ($row['path'] ?? '');
            self::assertRelative($path);
            if (isset($stateRows[$path])) throw new \RuntimeException("two refresh records select '$path'");
            $stateRows[$path] = (string) $row['content'];
            $source = (string) ($entry['selected_source'] ?? '');
            // Field-spliced rows keep branch bytes as their scaffold; the
            // eligible scalar surface carries no media authority. Attachments
            // are record-atomic and therefore retain their ordinary source.
            $mediaSources = $source === 'field-resolution' ? ['branch'] : [$source];
            foreach ($mediaSources as $mediaSource) {
                foreach ((array) ($plan['media'][$mediaSource] ?? []) as $name => $payload) {
                    if (str_contains((string) $row['content'], (string) $name)) $mediaNeeded[(string) $name] = $payload;
                }
            }
        }
        ksort($options, SORT_STRING);
        $format = 'duo-options/v1';
        foreach ($options as $record) if (is_array($record) && array_key_exists('classification_witness', $record)) $format = 'duo-options/v2';
        $stateRows['options/core.json'] = \Duo\Canon::encode(['format' => $format, 'records' => (object) $options]);
        ksort($stateRows, SORT_STRING);
        ksort($mediaNeeded, SORT_STRING);
        self::assertMediaPayloadBudget($mediaNeeded);
        self::replaceTree($root, 'state', $stateRows, false);
        $mediaRows = [];
        foreach ($mediaNeeded as $name => $payload) {
            $mediaRows[(string) $name] = self::verifiedMedia((string) $name, $payload);
        }
        self::replaceTree($root, 'media', $mediaRows, true);
        return [
            'format' => self::MATERIALIZATION_FORMAT,
            'plan_hash' => $planHash,
            'resolution_hash' => hash('sha256', self::encode($resolution)),
            'resolved' => true,
            'state_hash' => self::treeHash($root . '/state'),
            'media_hash' => self::treeHash($root . '/media'),
        ];
    }

    /** Contract-bound overlay: start from every exact W byte, replace only selected records. */
    private static function materializeScoped(
        array $plan,
        string $planHash,
        string $root,
        array $resolution
    ): array {
        $baseline = $plan['scope_baseline'] ?? null;
        if (!is_array($baseline)
            || ($baseline['format'] ?? null) !== 'duo-refresh-scope-baseline/v1'
            || !is_array($baseline['records'] ?? null)
            || !is_array($baseline['deletions'] ?? null)
            || !is_array($baseline['media'] ?? null)) {
            throw new \RuntimeException('scoped refresh plan has no exact branch baseline');
        }
        $scopeContract = \Duo\ScopeContract::from_array($plan['context']['scope_contract']);
        if (!hash_equals((string) $scopeContract['scope_hash'], (string) ($baseline['scope_hash'] ?? ''))) {
            throw new \RuntimeException('scoped refresh baseline does not match its immutable contract');
        }

        $stateByIdentity = [];
        foreach (['records', 'deletions'] as $field) {
            foreach ($baseline[$field] as $identity => $row) {
                if (!is_array($row) || !is_string($row['path'] ?? null) || !is_string($row['content'] ?? null)) {
                    throw new \RuntimeException('scoped refresh baseline has a malformed canonical row');
                }
                $stateByIdentity[(string) $identity] = $row;
            }
        }
        // Options are not one-identity-one-path like every other surface:
        // options/core.json holds every authored option in a single
        // OptionState document, so an in-scope `option:<name>` entry
        // (expand()'s per-option explosion) must be recombined into ONE
        // options/core row, never written to its own path. Seed from the
        // baseline's whole document -- preserving every out-of-scope
        // option's exact bytes, same discipline as every other identity
        // below -- and overlay only in-scope per-option resolutions. A null
        // `selected` here is exactly as safe as it is for any other
        // identity: applyResolution() already refused an unresolved
        // conflict before this ever runs, and OptionState never represents
        // "removed" as a missing map key (only as an explicit 'deleted'
        // record state), so a genuinely null selection only occurs when
        // base itself had no row for that option either -- nothing to
        // preserve.
        $optionRecords = null;
        foreach ($plan['entries'] as $entry) {
            if (($entry['in_scope'] ?? null) !== true) {
                continue;
            }
            $identity = (string) ($entry['identity'] ?? '');
            $row = $entry['selected'] ?? null;
            if (str_starts_with($identity, 'option:')) {
                if ($optionRecords === null) {
                    $baselineOptions = $stateByIdentity['options/core'] ?? null;
                    $optionRecords = $baselineOptions !== null
                        ? \Duo\OptionState::records(\Duo\Canon::decode((string) $baselineOptions['content']))
                        : [];
                }
                $name = substr($identity, strlen('option:'));
                if ($row === null) {
                    unset($optionRecords[$name]);
                } else {
                    $optionRecords[$name] = \Duo\Canon::decode((string) $row['content']);
                }
                continue;
            }
            unset($stateByIdentity[$identity]);
            if ($row !== null) {
                $stateByIdentity[$identity] = $row;
            }
        }
        if ($optionRecords !== null) {
            $content = self::encode(\Duo\OptionState::document($optionRecords));
            $stateByIdentity['options/core'] = [
                'identity' => 'options/core',
                'type' => 'options',
                'path' => 'options/core.json',
                'hash' => hash('sha256', $content),
                'content' => $content,
            ];
        }
        $stateRows = [];
        foreach ($stateByIdentity as $row) {
            $path = (string) $row['path'];
            self::assertRelative($path);
            if (isset($stateRows[$path])) {
                throw new \RuntimeException("two scoped refresh records select '$path'");
            }
            $stateRows[$path] = (string) $row['content'];
        }
        ksort($stateRows, SORT_STRING);

        $mediaPayloads = [];
        foreach ($baseline['media'] as $name => $payload) {
            $mediaPayloads[(string) $name] = $payload;
        }
        foreach ($plan['entries'] as $entry) {
            if (($entry['in_scope'] ?? null) !== true || !is_array($entry['selected'] ?? null)) {
                continue;
            }
            $selectedRow = $entry['selected'];
            if (($selectedRow['type'] ?? null) !== 'post') {
                continue;
            }
            try {
                [$front] = \Duo\Canon::parse_post_file((string) ($selectedRow['content'] ?? ''));
            } catch (\Throwable $failure) {
                throw new \RuntimeException('scoped refresh selected malformed canonical post state', 0, $failure);
            }
            if (($front['type'] ?? null) !== 'attachment') {
                continue;
            }
            $mediaName = (string) ($front['media'] ?? '');
            if ($mediaName === '') {
                continue;
            }
            $source = (string) ($entry['selected_source'] ?? '');
            $payload = $plan['media'][$source][$mediaName] ?? null;
            if ($payload === null) {
                throw new \RuntimeException("scoped refresh selected attachment media '$mediaName' is unavailable");
            }
            $mediaPayloads[$mediaName] = $payload;
        }
        ksort($mediaPayloads, SORT_STRING);
        self::assertMediaPayloadBudget($mediaPayloads);
        $mediaRows = [];
        foreach ($mediaPayloads as $name => $payload) {
            $mediaRows[$name] = self::verifiedMedia($name, $payload);
        }
        ksort($mediaRows, SORT_STRING);
        self::replaceTree($root, 'state', $stateRows, false);
        self::replaceTree($root, 'media', $mediaRows, true);
        return [
            'format' => self::MATERIALIZATION_FORMAT,
            'plan_hash' => $planHash,
            'resolution_hash' => hash('sha256', self::encode($resolution)),
            'resolved' => true,
            'scope_hash' => (string) $scopeContract['scope_hash'],
            'state_hash' => self::treeHash($root . '/state'),
            'media_hash' => self::treeHash($root . '/media'),
        ];
    }

    private static function verifiedMedia(string $name, mixed $payload): string {
        if (!is_array($payload)) {
            throw new \RuntimeException("refresh media '$name' is malformed");
        }
        if (!self::isHash($payload['sha256'] ?? null) || !is_string($payload['base64'] ?? null)) {
            throw new \RuntimeException("refresh media '$name' does not verify");
        }
        try {
            return \Duo\MediaPayloadAuthority::decodeArtifactMedia($name, [
                'sha256' => $payload['sha256'],
                'base64' => $payload['base64'],
            ]);
        } catch (\Throwable $failure) {
            throw new \RuntimeException("refresh media '$name' does not verify");
        }
    }

    /** @param array<string,mixed> $media */
    private static function assertMediaPayloadBudget(array $media): void {
        $bytes = 0;
        foreach ($media as $name => $payload) {
            if (!is_string($name) || !is_array($payload)
                || !is_string($payload['base64'] ?? null)) {
                throw new \RuntimeException('refresh media payload is malformed');
            }
            try {
                $bytes = \Duo\MediaPayloadAuthority::addToAggregate(
                    $bytes,
                    \Duo\MediaPayloadAuthority::canonicalBase64DecodedLength(
                        $payload['base64'],
                        'refresh media payload'
                    )
                );
            } catch (\Throwable $failure) {
                throw new \RuntimeException("refresh media '$name' does not verify");
            }
        }
        \Duo\MediaPayloadAuthority::assertRefreshExportHeadroom($bytes);
    }

    /** Apply the immutable run's choices without rewriting the persisted plan. */
    private static function applyResolution(array $plan, array $resolution): array {
        $strategy = $resolution['strategy'] ?? 'manual';
        $records = $resolution['records'] ?? [];
        if (!is_string($strategy) || !in_array($strategy, ['manual', 'ours', 'theirs'], true)
            || !is_array($records)) {
            throw new \RuntimeException('refresh materialization resolution is malformed');
        }
        foreach ($records as $id => $choice) {
            if (!is_string($id) || $id === '' || !is_string($choice) || !in_array($choice, ['ours', 'theirs'], true)) {
                throw new \RuntimeException('refresh materialization resolution is malformed');
            }
        }

        $used = [];
        $unresolved = [];
        foreach ($plan['entries'] as &$entry) {
            if (($entry['in_scope'] ?? true) === false) {
                $entry['resolution'] = null;
                $entry['selected_source'] = 'branch';
                $entry['selected'] = $entry['versions']['branch'] ?? null;
                continue;
            }
            if (($entry['category'] ?? null) !== 'conflicting') continue;
            $id = (string) ($entry['id'] ?? '');
            if (array_key_exists($id, $records)) {
                $choice = $records[$id];
                $used[$id] = true;
            } elseif ($strategy !== 'manual') {
                $choice = $strategy;
            } else {
                $choice = $entry['resolution'] ?? null;
            }
            if (!in_array($choice, ['ours', 'theirs'], true)) {
                $entry['resolution'] = null;
                $entry['selected_source'] = null;
                $entry['selected'] = null;
                $unresolved[] = $id;
                continue;
            }
            $source = $choice === 'ours' ? 'branch' : 'production';
            $selected = $entry['versions'][$source] ?? null;
            if (self::unsafeAbsence($entry['versions']['base'] ?? null, $selected)) {
                throw new \RuntimeException("resolution '$id' selects absence without deletion authority");
            }
            $entry['resolution'] = $choice;
            $entry['selected_source'] = $source;
            $entry['selected'] = $selected;
        }
        unset($entry);
        foreach (array_keys($records) as $id) {
            if (!isset($used[$id])) {
                throw new \RuntimeException("refresh resolution '$id' is stale or does not name a conflict");
            }
        }
        if ($unresolved !== []) {
            sort($unresolved, SORT_STRING);
            throw new \RuntimeException('refresh rebase has unresolved semantic conflicts: ' . implode(', ', $unresolved));
        }
        return $plan;
    }

    /** Strict-compile and bind the exact materialized bytes to the receipt. */
    public static function validateMaterialization(array $receipt, array $plan, string $worktree): void {
        $plan = self::normalizePlan($plan);
        $scopeContract = is_array($plan['context']['scope_contract'] ?? null)
            ? \Duo\ScopeContract::from_array($plan['context']['scope_contract'])
            : null;
        if (($receipt['format'] ?? null) !== self::MATERIALIZATION_FORMAT
            || ($receipt['resolved'] ?? null) !== true
            || !hash_equals((string) $plan['plan_hash'], (string) ($receipt['plan_hash'] ?? ''))
            || !hash_equals((string) ($receipt['state_hash'] ?? ''), self::treeHash($worktree . '/state'))
            || !hash_equals((string) ($receipt['media_hash'] ?? ''), self::treeHash($worktree . '/media'))
            || ($scopeContract !== null
                && !hash_equals((string) $scopeContract['scope_hash'], (string) ($receipt['scope_hash'] ?? '')))) {
            throw new \RuntimeException('refresh materialization receipt does not match candidate bytes');
        }
        $head = self::runProcess(['git', '-C', $worktree, 'rev-parse', '--verify', 'HEAD^{commit}']);
        if ($head['exit'] !== 0 || !self::isCommit(trim($head['stdout']))) {
            throw new \RuntimeException('refresh candidate has no valid Git HEAD');
        }
        $candidate = self::compileGitWorktree(
            $worktree,
            trim($head['stdout']),
            'candidate',
            $scopeContract
        );
        if ($scopeContract !== null) {
            self::assertScopedBaselinePreserved($plan, $candidate, $scopeContract, $worktree);
        }
    }

    /** Excluded W rows/media are exact baseline bytes, never inferred from P omission. */
    private static function assertScopedBaselinePreserved(
        array $plan,
        array $candidate,
        array $scopeContract,
        string $worktree
    ): void {
        $baseline = $plan['scope_baseline'] ?? null;
        if (!is_array($baseline)) {
            throw new \RuntimeException('scoped refresh validation has no exact branch baseline');
        }
        $selected = array_fill_keys(\Duo\ScopedStateOverlay::selected_identities($scopeContract), true);
        $selectedOptions = array_fill_keys(\Duo\ScopeContract::option_root_names($scopeContract), true);
        foreach (['records', 'deletions'] as $field) {
            foreach ((array) ($baseline[$field] ?? []) as $identity => $row) {
                $identity = (string) $identity;
                // options/core is a physical carrier, while an option-root
                // scope selects its virtual option:<name> records. Compare
                // every excluded record in that carrier individually so a
                // permitted selected change cannot make strict validation
                // reject the whole file (or hide a sibling change).
                if ($field === 'records' && $identity === 'options/core'
                    && $selectedOptions !== [] && !isset($selected[$identity])) {
                    self::assertExcludedOptionsPreserved($row, $candidate[$field][$identity] ?? null, $selectedOptions);
                    continue;
                }
                if (isset($selected[$identity])) {
                    continue;
                }
                $actualField = $field;
                $actual = $candidate[$actualField][$identity] ?? null;
                if (!is_array($row) || !is_array($actual)
                    || (string) ($actual['path'] ?? '') !== (string) ($row['path'] ?? '')
                    || (string) ($actual['content'] ?? '') !== (string) ($row['content'] ?? '')) {
                    throw new \RuntimeException(
                        "scoped refresh changed or removed excluded branch $field identity '$identity'"
                    );
                }
            }
        }
        foreach ((array) ($baseline['media'] ?? []) as $name => $row) {
            $path = rtrim($worktree, '/') . '/media/' . $name;
            $bytes = is_file($path) ? file_get_contents($path) : false;
            $expected = is_array($row) ? self::verifiedMedia((string) $name, $row) : false;
            if ($bytes === false || $expected === false || !hash_equals($expected, $bytes)) {
                throw new \RuntimeException("scoped refresh changed or removed excluded branch media '$name'");
            }
        }
    }

    /** @param array<string,true> $selectedOptions */
    private static function assertExcludedOptionsPreserved(
        mixed $baselineRow,
        mixed $candidateRow,
        array $selectedOptions
    ): void {
        if (!is_array($baselineRow) || !is_array($candidateRow)
            || (string) ($candidateRow['path'] ?? '') !== (string) ($baselineRow['path'] ?? '')) {
            throw new \RuntimeException('scoped refresh changed or removed excluded branch records identity \'options/core\'');
        }
        try {
            $baselineOptions = \Duo\OptionState::records(\Duo\Canon::decode((string) ($baselineRow['content'] ?? '')));
            $candidateOptions = \Duo\OptionState::records(\Duo\Canon::decode((string) ($candidateRow['content'] ?? '')));
        } catch (\Throwable $failure) {
            throw new \RuntimeException('scoped refresh candidate has malformed options/core state', 0, $failure);
        }
        foreach ($baselineOptions as $name => $record) {
            if (isset($selectedOptions[$name])) {
                continue;
            }
            if (!array_key_exists($name, $candidateOptions)
                || \Duo\Canon::encode($candidateOptions[$name]) !== \Duo\Canon::encode($record)) {
                throw new \RuntimeException("scoped refresh changed or removed excluded option '$name'");
            }
        }
        foreach ($candidateOptions as $name => $_record) {
            if (!isset($selectedOptions[$name]) && !array_key_exists($name, $baselineOptions)) {
                throw new \RuntimeException("scoped refresh introduced excluded option '$name'");
            }
        }
    }

    /** @return array{states:array<string,mixed>,media:array<string,mixed>} */
    private static function expand(array $snapshot): array {
        self::loadCompiler();
        $states = [];
        foreach ((array) ($snapshot['records'] ?? []) as $identity => $row) {
            if ((string) $identity === 'options/core') {
                $document = \Duo\Canon::decode((string) $row['content']);
                foreach (\Duo\OptionState::records($document) as $name => $record) {
                    $content = \Duo\Canon::encode($record);
                    $states['option:' . $name] = [
                        'identity' => 'option:' . $name, 'type' => 'option', 'path' => 'options/core.json',
                        'hash' => hash('sha256', $content), 'content' => $content,
                        'virtual' => 'option', 'option_name' => (string) $name,
                    ];
                }
                continue;
            }
            $states[(string) $identity] = $row;
        }
        foreach ((array) ($snapshot['deletions'] ?? []) as $identity => $row) {
            if (!isset($states[(string) $identity])) $states[(string) $identity] = $row + ['deletion_authority' => true];
        }
        ksort($states, SORT_STRING);
        $media = (array) ($snapshot['media'] ?? []);
        ksort($media, SORT_STRING);
        return ['states' => $states, 'media' => $media];
    }

    private static function same(?array $a, ?array $b): bool {
        if ($a === null || $b === null) return $a === $b;
        return ($a['type'] ?? null) === ($b['type'] ?? null)
            && ($a['hash'] ?? null) === ($b['hash'] ?? null);
    }

    private static function unsafeAbsence(?array $base, ?array $side): bool {
        return $base !== null && ($base['type'] ?? null) !== 'deletion' && $side === null;
    }

    /** @return array<string,mixed> */
    private static function recordFromCompiled(string $identity, array $row): array {
        // A parsed tree cannot recover every source-level semantic detail:
        // Capture's order-preserved subtrees deliberately lose their marker
        // after JSON decoding.  The compiler therefore binds exact validated
        // state bytes into each row.  Refresh must relay those bytes, never
        // reserialize decoded data and silently reorder a map.
        if (!array_key_exists('content', $row) || !is_string($row['content'])) {
            throw new \RuntimeException("compiled record '$identity' has no exact validated content");
        }
        $content = $row['content'];
        return [
            'identity' => $identity,
            'type' => (string) ($row['type'] ?? ''),
            'path' => (string) ($row['path'] ?? ''),
            'hash' => (string) ($row['hash'] ?? hash('sha256', $content)),
            'content' => $content,
        ];
    }

    private static function assertSnapshot(array $snapshot, string $label): void {
        foreach (['records', 'deletions', 'media', 'policy', 'repository'] as $field) {
            if (!is_array($snapshot[$field] ?? null)) throw new \RuntimeException("$label snapshot lacks $field");
        }
        foreach (['records', 'deletions'] as $field) {
            foreach ($snapshot[$field] as $identity => $row) {
                if (!is_string($identity) || !is_array($row) || ($row['identity'] ?? null) !== $identity
                    || !is_string($row['type'] ?? null) || !is_string($row['path'] ?? null)
                    || !self::isHash($row['hash'] ?? null) || !is_string($row['content'] ?? null)) {
                    throw new \RuntimeException("$label snapshot has a malformed $field record");
                }
            }
        }
    }

    private static function loadCompiler(): void {
        if (class_exists(\Duo\RepositoryCompiler::class, false)
            && class_exists(\Duo\CodeStateContract::class, false)) return;
        if (!defined('DUO_SPEC_VERSION')) define('DUO_SPEC_VERSION', 3);
        $root = dirname(__DIR__, 3);
        $duoAgentClassmap = require $root . '/agent/duo-classmap.php';
        if (!is_array($duoAgentClassmap)) {
            throw new \RuntimeException('refresh: agent/duo-classmap.php did not return a map');
        }
        $duoAgentFiles = [];
        foreach ($duoAgentClassmap as $duoAgentPath) {
            $duoAgentFiles[basename((string) $duoAgentPath, '.php')] = (string) $duoAgentPath;
        }
        foreach (['Uuid','OrderPreserved','Canon','OptionState','UserMetaState','Db','Secrets','PersonalData','ManifestDispositions','Policy','Ledger','PromotionLock','Identity','IdentityBackup','Deletion','JsonRefs','Tokens','Blocks','PlainData','SidebarState','Shortcodes','Canary','IdentityNotes','Snapshot','Orphans','TransientDbException','Publish','Capture','RepositoryAuthorization','CodeCompatibility','Code','ReferenceGraph','RepositoryCompiler','ScopeClosure','CanonicalSurfaces','ScopeContract','ScopedStateOverlay','CodeStateContract'] as $file) {
            $duoAgentFile = $duoAgentFiles[$file] ?? null;
            if (!is_string($duoAgentFile)) {
                throw new \RuntimeException('refresh: agent source ' . $file . '.php is absent from agent/duo-classmap.php');
            }
            require_once $root . '/agent/' . $duoAgentFile;
        }
    }

    /** @param array<string,string> $rows */
    private static function replaceTree(string $root, string $name, array $rows, bool $binary): void {
        $target = $root . '/' . $name;
        $stage = $root . '/.' . $name . '.refresh-' . bin2hex(random_bytes(8));
        if (!mkdir($stage, 0700, true)) throw new \RuntimeException("cannot stage refresh $name");
        try {
            foreach ($rows as $path => $bytes) {
                self::assertRelative((string) $path);
                $dest = $stage . '/' . $path;
                if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0700, true) && !is_dir(dirname($dest))) {
                    throw new \RuntimeException("cannot create refresh path '$path'");
                }
                if (file_put_contents($dest, $bytes, LOCK_EX) !== strlen($bytes)) {
                    throw new \RuntimeException("cannot write refresh path '$path'");
                }
            }
            self::removeTree($target);
            if (!rename($stage, $target)) throw new \RuntimeException("cannot publish refresh $name");
        } catch (\Throwable $e) {
            self::removeTree($stage);
            throw $e;
        }
    }

    private static function removeTree(string $path): void {
        if (!file_exists($path) && !is_link($path)) return;
        if (is_file($path) || is_link($path)) { unlink($path);
        return; }
        foreach (scandir($path) ?: [] as $child) if ($child !== '.' && $child !== '..') self::removeTree($path . '/' . $child);
        rmdir($path);
    }

    private static function treeHash(string $root): string {
        if (!is_dir($root)) return hash('sha256', '');
        $rows = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) if ($file->isFile()) {
            $path = substr($file->getPathname(), strlen(rtrim($root, '/')) + 1);
            $rows[$path] = hash_file('sha256', $file->getPathname());
        }
        ksort($rows, SORT_STRING);
        return hash('sha256', self::encode($rows));
    }

    private static function assertRelative(string $path): void {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0") || in_array('..', explode('/', $path), true)) {
            throw new \RuntimeException("unsafe refresh path '$path'");
        }
    }

    private static function encode(mixed $value): string {
        self::loadCompiler();
        return \Duo\Canon::encode($value);
    }

    private static function isHash(mixed $value): bool {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private static function isCommit(string $value): bool {
        return preg_match('/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/D', $value) === 1;
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private static function runProcess(array $command): array {
        $pipes = [];
        $proc = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($proc)) {
            return ['exit' => 255, 'stdout' => '', 'stderr' => 'could not start process'];
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}

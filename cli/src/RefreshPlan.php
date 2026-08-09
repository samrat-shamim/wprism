<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

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

    /** Compile each ref in a fresh process so provider classes cannot leak between refs. */
    public static function compileGitWorktree(string $path, string $commit, string $label = ''): array {
        if (!in_array($label, ['base', 'branch', 'production-code', 'candidate'], true)) {
            throw new \RuntimeException("cannot compile unknown '$label' Git worktree role");
        }
        $worker = __DIR__ . '/RefreshPlanCompile.php';
        if (!is_file($worker)) {
            throw new \RuntimeException('refresh compiler worker is unavailable');
        }
        $result = self::runProcess([PHP_BINARY, $worker, $path, $commit, $label]);
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
    public static function compileGitWorktreeWorker(string $path, string $commit, string $label): array {
        self::loadCompiler();
        $root = realpath($path);
        if (!self::isCommit($commit) || $root === false || !is_file($root . '/site.duo.json')
            || !in_array($label, ['base', 'branch', 'production-code', 'candidate'], true)) {
            throw new \RuntimeException("cannot compile $label Git worktree");
        }
        $head = self::runProcess(['git', '-C', $root, 'rev-parse', '--verify', 'HEAD^{commit}']);
        if ($head['exit'] !== 0 || !hash_equals($commit, trim($head['stdout']))) {
            throw new \RuntimeException("$label Git worktree HEAD does not match its declared commit");
        }
        $old = getenv('DUO_MANIFESTS_DIR');
        putenv('DUO_MANIFESTS_DIR=' . $root . '/manifests');
        try {
            $policy = \Duo\Policy::load($root);
            $compiled = $label === 'base'
                ? \Duo\RepositoryCompiler::compile_for_diff($root, $policy)
                : \Duo\RepositoryCompiler::compile($root, $policy);
        } finally {
            $old === false ? putenv('DUO_MANIFESTS_DIR') : putenv('DUO_MANIFESTS_DIR=' . $old);
        }
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
        $media = is_array($artifact['media'] ?? null) ? $artifact['media'] : [];
        ksort($media, SORT_STRING);
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
            $bp = self::same($b, $p);
            $bw = self::same($b, $w);
            $pw = self::same($p, $w);
            if ($bp && $bw) $category = 'unchanged';
            elseif ($bw) $category = 'production-only';
            elseif ($bp) $category = 'branch-only';
            elseif ($pw) $category = 'compatible';
            else $category = 'conflicting';

            $unsafeAbsence = self::unsafeAbsence($b, $p) || self::unsafeAbsence($b, $w);
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
            $selectedSource = match ($category) {
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
            ];
        }
        foreach (array_keys($perRecord) as $id) {
            if (!isset($usedResolutions[$id])) {
                throw new \RuntimeException("refresh resolution '$id' is stale or does not name a conflict");
            }
        }
        $safeContext = $context;
        unset($safeContext['repo_root']);
        return self::normalizePlan([
            'format' => self::PLAN_FORMAT,
            'context' => $safeContext,
            'strategy' => $strategy,
            'entries' => $entries,
            'media' => [
                'base' => $snapshots['base']['media'],
                'production' => $snapshots['production']['media'],
                'branch' => $snapshots['branch']['media'],
            ],
        ]);
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
        foreach ($plan['entries'] as $entry) {
            $id = (string) ($entry['id'] ?? '');
            $category = (string) ($entry['category'] ?? '');
            if ($id === '' || isset($seen[$id]) || !isset($counts[$category])) {
                throw new \RuntimeException('refresh plan has an invalid or duplicate entry');
            }
            $seen[$id] = true;
            $counts[$category]++;
            if ($category === 'conflicting' && ($entry['selected_source'] ?? null) === null) $unresolved[] = $id;
        }
        $plan['counts'] = $counts;
        $plan['unresolved'] = $unresolved;
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
        $root = realpath($worktree);
        if ($root === false || !is_dir($root) || !is_file($root . '/.git')) {
            throw new \RuntimeException('refresh materializer requires a disposable Git worktree');
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
            foreach ((array) ($plan['media'][$source] ?? []) as $name => $payload) {
                if (str_contains((string) $row['content'], (string) $name)) $mediaNeeded[(string) $name] = $payload;
            }
        }
        ksort($options, SORT_STRING);
        $format = 'duo-options/v1';
        foreach ($options as $record) if (is_array($record) && array_key_exists('classification_witness', $record)) $format = 'duo-options/v2';
        $stateRows['options/core.json'] = \Duo\Canon::encode(['format' => $format, 'records' => (object) $options]);
        ksort($stateRows, SORT_STRING);
        ksort($mediaNeeded, SORT_STRING);
        self::replaceTree($root, 'state', $stateRows, false);
        $mediaRows = [];
        foreach ($mediaNeeded as $name => $payload) {
            self::assertRelative((string) $name);
            $bytes = base64_decode((string) ($payload['base64'] ?? ''), true);
            if ($bytes === false || !self::isHash($payload['sha256'] ?? null)
                || !hash_equals((string) $payload['sha256'], hash('sha256', $bytes))) {
                throw new \RuntimeException("refresh media '$name' does not verify");
            }
            $mediaRows[(string) $name] = $bytes;
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
        if (($receipt['format'] ?? null) !== self::MATERIALIZATION_FORMAT
            || ($receipt['resolved'] ?? null) !== true
            || !hash_equals((string) $plan['plan_hash'], (string) ($receipt['plan_hash'] ?? ''))
            || !hash_equals((string) ($receipt['state_hash'] ?? ''), self::treeHash($worktree . '/state'))
            || !hash_equals((string) ($receipt['media_hash'] ?? ''), self::treeHash($worktree . '/media'))) {
            throw new \RuntimeException('refresh materialization receipt does not match candidate bytes');
        }
        $head = self::runProcess(['git', '-C', $worktree, 'rev-parse', '--verify', 'HEAD^{commit}']);
        if ($head['exit'] !== 0 || !self::isCommit(trim($head['stdout']))) {
            throw new \RuntimeException('refresh candidate has no valid Git HEAD');
        }
        self::compileGitWorktree($worktree, trim($head['stdout']), 'candidate');
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
        $content = (string) ($row['content'] ?? '');
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
        if (!defined('DUO_SPEC_VERSION')) define('DUO_SPEC_VERSION', 2);
        $root = dirname(__DIR__, 2);
        foreach (['Uuid','OrderPreserved','Canon','OptionState','UserMetaState','Db','WooCommerceContract','Secrets','PersonalData','ManifestDispositions','CapabilityRegistry','Policy','Ledger','PromotionLock','Identity','IdentityBackup','Deletion','JsonRefs','Tokens','Blocks','PlainData','SidebarState','Shortcodes','Canary','IdentityNotes','Snapshot','Orphans','TransientDbException','Publish','Capture','RepositoryAuthorization','CodeCompatibility','Code','RepositoryCompiler','CodeStateContract'] as $file) {
            require_once $root . '/agent/src/' . $file . '.php';
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
        if (is_file($path) || is_link($path)) { unlink($path); return; }
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

<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform;

/**
 * @phpstan-import-type CatalogAggregate from Catalog
 * @phpstan-import-type Profile from Catalog
 * @phpstan-type ShardRow array{index:int,estimated_duration_ms:int,suite_ids:list<string>}
 * @phpstan-type ShardDocument array{
 *     format:string,
 *     candidate_sha:string,
 *     catalog_sha256:string,
 *     profile_id:string,
 *     profile_sha256:string,
 *     history_sha256:string,
 *     planner_version:string,
 *     execution_waves:list<list<int>>,
 *     shards:list<ShardRow>
 * }
 * @phpstan-type ShardContext array{
 *     candidate_sha:string,
 *     profile_id:string,
 *     plan_sha256:string,
 *     index:int,
 *     count:int,
 *     estimated_duration_ms:int,
 *     suite_ids:list<string>
 * }
 */
final class ShardPlan
{
    /** @param CatalogAggregate $catalog */
    public function __construct(
        private readonly string $root,
        private readonly array $catalog,
    ) {}

    /**
     * @param list<string> $historyPaths
     * @return ShardDocument
     */
    public function create(string $profileId, int $count, array $historyPaths): array
    {
        $profile = $this->profile($profileId);
        if ($profile['environment_class'] !== 'offline') {
            throw new CatalogException('only offline profiles may be sharded without external authority');
        }
        if ($count < 1 || $count > count($profile['suite_ids'])) {
            throw new CatalogException('shard count must be between one and the profile suite count');
        }
        $durations = $this->durations($historyPaths);
        $weighted = [];
        foreach ($profile['suite_ids'] as $suiteId) {
            $weighted[] = ['id' => $suiteId, 'duration_ms' => $durations[$suiteId] ?? 1000];
        }
        usort($weighted, static function (array $left, array $right): int {
            $duration = $right['duration_ms'] <=> $left['duration_ms'];
            return $duration !== 0 ? $duration : strcmp($left['id'], $right['id']);
        });
        $shards = $this->emptyShards($count);
        foreach ($weighted as $suite) {
            usort($shards, static function (array $left, array $right): int {
                $duration = $left['estimated_duration_ms'] <=> $right['estimated_duration_ms'];
                return $duration !== 0 ? $duration : $left['index'] <=> $right['index'];
            });
            $target = $shards[0];
            $target['suite_ids'][] = $suite['id'];
            $target['estimated_duration_ms'] += $suite['duration_ms'];
            $shards[0] = $target;
        }
        foreach (array_keys($shards) as $index) {
            $shard = $shards[$index];
            $suiteIds = $shard['suite_ids'];
            sort($suiteIds, SORT_STRING);
            $shard['suite_ids'] = $suiteIds;
            $shard['estimated_duration_ms'] = max(1, $shard['estimated_duration_ms']);
            $shards[$index] = $shard;
        }
        usort($shards, static fn(array $left, array $right): int => $left['index'] <=> $right['index']);
        $waves = $this->executionWaves($shards);
        return [
            'format' => 'duo-test-shard-plan/v1',
            'candidate_sha' => $this->candidateSha(),
            'catalog_sha256' => $this->catalogDigest(),
            'profile_id' => $profileId,
            'profile_sha256' => 'sha256:' . hash('sha256', $this->canonical($profile)),
            'history_sha256' => 'sha256:' . hash('sha256', $this->canonical($this->historyBindings($historyPaths))),
            'planner_version' => 'duration-lpt/v1',
            'execution_waves' => $waves,
            'shards' => $shards,
        ];
    }

    /** @param ShardDocument $document */
    public function publish(array $document, string $relative): void
    {
        $this->publishBytes($relative, json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n");
    }

    /** @return ShardDocument */
    public function load(string $relative): array
    {
        $path = $this->artifactPath($relative);
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($bytes)) {
            throw new CatalogException('shard plan is unavailable');
        }
        try {
            $document = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new CatalogException('shard plan is invalid JSON: ' . $exception->getMessage());
        }
        if (!is_array($document)
            || array_keys($document) !== [
                'format', 'candidate_sha', 'catalog_sha256', 'profile_id', 'profile_sha256',
                'history_sha256', 'planner_version', 'execution_waves', 'shards',
            ]
            || $document['format'] !== 'duo-test-shard-plan/v1'
            || !is_string($document['candidate_sha']) || preg_match('/^[a-f0-9]{40}$/D', $document['candidate_sha']) !== 1
            || $document['catalog_sha256'] !== $this->catalogDigest()
            || !is_string($document['profile_id'])
            || !is_string($document['profile_sha256'])
            || !is_string($document['history_sha256'])
            || $document['planner_version'] !== 'duration-lpt/v1'
            || !is_array($document['execution_waves']) || !array_is_list($document['execution_waves']) || $document['execution_waves'] === []
            || !is_array($document['shards']) || !array_is_list($document['shards']) || $document['shards'] === []) {
            throw new CatalogException('shard plan is malformed or stale');
        }
        $profile = $this->profile($document['profile_id']);
        if ('sha256:' . hash('sha256', $this->canonical($profile)) !== $document['profile_sha256']) {
            throw new CatalogException('shard plan profile digest is stale');
        }
        $seen = [];
        $shards = [];
        foreach ($document['shards'] as $expectedIndex => $row) {
            if (!is_array($row)
                || array_keys($row) !== ['index', 'estimated_duration_ms', 'suite_ids']
                || ($row['index'] ?? null) !== $expectedIndex
                || !is_int($row['estimated_duration_ms'] ?? null) || $row['estimated_duration_ms'] < 1
                || !is_array($row['suite_ids'] ?? null) || !array_is_list($row['suite_ids']) || $row['suite_ids'] === []) {
                throw new CatalogException('shard plan row is malformed');
            }
            $suiteIds = [];
            foreach ($row['suite_ids'] as $suiteId) {
                if (!is_string($suiteId) || isset($seen[$suiteId])) {
                    throw new CatalogException('shard plan suite set is invalid or duplicated');
                }
                $seen[$suiteId] = true;
                $suiteIds[] = $suiteId;
            }
            $sorted = $suiteIds;
            sort($sorted, SORT_STRING);
            if ($sorted !== $suiteIds) {
                throw new CatalogException('shard suite IDs must be sorted');
            }
            $shards[] = [
                'index' => $expectedIndex,
                'estimated_duration_ms' => $row['estimated_duration_ms'],
                'suite_ids' => $suiteIds,
            ];
        }
        $actual = array_keys($seen);
        sort($actual, SORT_STRING);
        $expected = $profile['suite_ids'];
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new CatalogException('shard plan does not partition the complete profile suite set');
        }
        $waves = [];
        $waveIndices = [];
        foreach ($document['execution_waves'] as $wave) {
            if (!is_array($wave) || !array_is_list($wave) || $wave === []) {
                throw new CatalogException('shard execution wave is malformed');
            }
            $normalizedWave = [];
            foreach ($wave as $index) {
                if (!is_int($index) || !isset($shards[$index]) || isset($waveIndices[$index])) {
                    throw new CatalogException('shard execution waves are incomplete or duplicated');
                }
                $waveIndices[$index] = true;
                $normalizedWave[] = $index;
            }
            $waves[] = $normalizedWave;
        }
        if ($waves !== $this->executionWaves($shards)) {
            throw new CatalogException('shard execution waves violate declared parallel safety or resource locks');
        }
        /** @var ShardDocument $normalized */
        $normalized = [
            'format' => 'duo-test-shard-plan/v1',
            'candidate_sha' => $document['candidate_sha'],
            'catalog_sha256' => $document['catalog_sha256'],
            'profile_id' => $document['profile_id'],
            'profile_sha256' => $document['profile_sha256'],
            'history_sha256' => $document['history_sha256'],
            'planner_version' => 'duration-lpt/v1',
            'execution_waves' => $waves,
            'shards' => $shards,
        ];
        return $normalized;
    }

    /** @return ShardContext */
    public function context(string $relative, int $index): array
    {
        $document = $this->load($relative);
        if (!isset($document['shards'][$index])) {
            throw new CatalogException('shard index is outside the plan');
        }
        $row = $document['shards'][$index];
        return [
            'candidate_sha' => $document['candidate_sha'],
            'profile_id' => $document['profile_id'],
            'plan_sha256' => 'sha256:' . hash('sha256', $this->canonical($document)),
            'index' => $index,
            'count' => count($document['shards']),
            'estimated_duration_ms' => $row['estimated_duration_ms'],
            'suite_ids' => $row['suite_ids'],
        ];
    }

    /**
     * @param array<int,string> $receiptPaths shard index => artifacts-relative receipt path
     * @return array{exit:int,receipt:array<string,mixed>}
     */
    public function aggregate(string $planPath, array $receiptPaths): array
    {
        $document = $this->load($planPath);
        $planDigest = 'sha256:' . hash('sha256', $this->canonical($document));
        $expectedIndices = range(0, count($document['shards']) - 1);
        $actualIndices = array_keys($receiptPaths);
        sort($actualIndices, SORT_NUMERIC);
        if ($actualIndices !== $expectedIndices) {
            throw new CatalogException('shard aggregate requires exactly one result for every planned index');
        }
        $results = [];
        $pass = true;
        foreach ($expectedIndices as $index) {
            $relative = $receiptPaths[$index];
            $path = $this->artifactPath($relative);
            $bytes = is_file($path) ? file_get_contents($path) : false;
            $state = 'infra_error';
            $message = 'shard result is absent';
            $digest = null;
            if (is_string($bytes)) {
                $digest = 'sha256:' . hash('sha256', $bytes);
                try {
                    $receipt = json_decode($bytes, true, 128, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $receipt = null;
                }
                $expectedSuiteIds = $document['shards'][$index]['suite_ids'];
                if (is_array($receipt)
                    && ($receipt['format'] ?? null) === 'duo-test-run-receipt/v1'
                    && ($receipt['candidate_sha'] ?? null) === $document['candidate_sha']
                    && ($receipt['authority'] ?? null) === 'non_authorizing_shard'
                    && ($receipt['state'] ?? null) === 'pass'
                    && ($receipt['selected_suite_ids'] ?? null) === $expectedSuiteIds
                    && is_array($receipt['shard'] ?? null)
                    && ($receipt['shard']['plan_sha256'] ?? null) === $planDigest
                    && ($receipt['shard']['index'] ?? null) === $index) {
                    $state = 'pass';
                    $message = 'shard passed with the exact planned suite set';
                } else {
                    $message = 'shard result is malformed, stale, incomplete, or non-passing';
                }
            }
            if ($state !== 'pass') {
                $pass = false;
            }
            $results[] = [
                'index' => $index,
                'path' => $relative,
                'sha256' => $digest,
                'state' => $state,
                'message' => $message,
            ];
        }
        return [
            'exit' => $pass ? 0 : 1,
            'receipt' => [
                'format' => 'duo-shard-aggregate/v1',
                'candidate_sha' => $document['candidate_sha'],
                'state' => $pass ? 'pass' : 'fail',
                'profile_id' => $document['profile_id'],
                'profile_sha256' => $document['profile_sha256'],
                'plan_sha256' => $planDigest,
                'shards' => $results,
                'finished_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
        ];
    }

    /** @param array<string,mixed> $receipt */
    public function publishAggregate(array $receipt, string $relative): void
    {
        $this->publishBytes($relative, json_encode(
            $receipt,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n");
    }

    /**
     * @param list<string> $historyPaths
     * @return array<string,int>
     */
    private function durations(array $historyPaths): array
    {
        $samples = [];
        foreach ($historyPaths as $relative) {
            $path = $this->artifactPath($relative);
            $bytes = is_file($path) ? file_get_contents($path) : false;
            if (!is_string($bytes)) {
                throw new CatalogException("shard history receipt is unavailable: $relative");
            }
            try {
                $receipt = json_decode($bytes, true, 128, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new CatalogException('shard history receipt is invalid JSON: ' . $exception->getMessage());
            }
            if (!is_array($receipt) || !is_array($receipt['results'] ?? null) || !array_is_list($receipt['results'])) {
                throw new CatalogException('shard history receipt has no suite results');
            }
            foreach ($receipt['results'] as $result) {
                if (is_array($result) && is_string($result['id'] ?? null) && is_int($result['duration_ms'] ?? null) && $result['duration_ms'] >= 0) {
                    $samples[$result['id']][] = max(1, $result['duration_ms']);
                }
            }
        }
        $durations = [];
        foreach ($samples as $id => $values) {
            sort($values, SORT_NUMERIC);
            $position = max(0, (int) ceil(count($values) * 0.95) - 1);
            $durations[$id] = $values[$position];
        }
        return $durations;
    }

    /**
     * @param list<string> $historyPaths
     * @return array<string,string>
     */
    private function historyBindings(array $historyPaths): array
    {
        $bindings = [];
        foreach ($historyPaths as $relative) {
            $path = $this->artifactPath($relative);
            $digest = hash_file('sha256', $path);
            if (!is_string($digest)) {
                throw new CatalogException('cannot digest shard history receipt');
            }
            $bindings[$relative] = 'sha256:' . $digest;
        }
        ksort($bindings, SORT_STRING);
        return $bindings;
    }

    /** @return Profile */
    private function profile(string $profileId): array
    {
        foreach ($this->catalog['profiles'] as $profile) {
            if ($profile['id'] === $profileId) {
                return $profile;
            }
        }
        throw new CatalogException("unknown shard profile: $profileId");
    }

    /** @return list<ShardRow> */
    private function emptyShards(int $count): array
    {
        $shards = [];
        for ($index = 0; $index < $count; ++$index) {
            $shards[] = ['index' => $index, 'estimated_duration_ms' => 0, 'suite_ids' => []];
        }
        return $shards;
    }

    /**
     * @param list<ShardRow> $shards
     * @return list<list<int>>
     */
    private function executionWaves(array $shards): array
    {
        $suiteMap = [];
        foreach ($this->catalog['suites'] as $suite) {
            $suiteMap[$suite['id']] = $suite;
        }
        $waves = [];
        $current = [];
        $currentLocks = [];
        foreach ($shards as $shard) {
            $exclusive = false;
            $locks = [];
            foreach ($shard['suite_ids'] as $suiteId) {
                $suite = $suiteMap[$suiteId] ?? null;
                if (!is_array($suite)) {
                    throw new CatalogException("shard execution policy cannot resolve suite: $suiteId");
                }
                if (!$suite['parallel_safe'] || $suite['workspace_mode'] === 'exclusive') {
                    $exclusive = true;
                }
                foreach ($suite['resource_locks'] as $lock) {
                    $locks[$lock] = true;
                }
            }
            $conflict = array_intersect_key($currentLocks, $locks) !== [];
            if ($exclusive || $conflict) {
                if ($current !== []) {
                    $waves[] = $current;
                    $current = [];
                    $currentLocks = [];
                }
                $waves[] = [$shard['index']];
                continue;
            }
            $current[] = $shard['index'];
            $currentLocks += $locks;
        }
        if ($current !== []) {
            $waves[] = $current;
        }
        return $waves;
    }

    private function catalogDigest(): string
    {
        return 'sha256:' . hash('sha256', (new Catalog($this->root))->encode($this->catalog));
    }

    private function candidateSha(): string
    {
        $process = proc_open(
            ['git', 'rev-parse', 'HEAD'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            ['PATH' => (string) getenv('PATH'), 'LC_ALL' => 'C'],
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new CatalogException('cannot resolve shard-plan candidate');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($stdout) || preg_match('/^[a-f0-9]{40}$/D', trim($stdout)) !== 1) {
            throw new CatalogException('shard-plan candidate is not a full SHA');
        }
        return trim($stdout);
    }

    private function publishBytes(string $relative, string $bytes): void
    {
        $path = $this->artifactPath($relative);
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            throw new CatalogException('cannot create shard output directory');
        }
        $temporary = tempnam(dirname($path), '.shard.');
        if (!is_string($temporary)) {
            throw new CatalogException('cannot create shard temporary file');
        }
        try {
            if (file_put_contents($temporary, $bytes) !== strlen($bytes) || !chmod($temporary, 0600) || !rename($temporary, $path)) {
                throw new CatalogException('cannot publish shard output');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function artifactPath(string $relative): string
    {
        if (preg_match('~^artifacts/(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]+$~D', $relative) !== 1 || str_contains($relative, '..')) {
            throw new CatalogException('shard path must be artifacts-relative');
        }
        return $this->root . '/' . $relative;
    }

    private function canonical(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}

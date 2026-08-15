<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform\Tests;

use Duo\EngineeringPlatform\Catalog;
use Duo\EngineeringPlatform\CatalogException;
use Duo\EngineeringPlatform\ShardPlan;
use PHPUnit\Framework\TestCase;

final class ShardPlanTest extends TestCase
{
    private string $root;
    private ShardPlan $planner;

    /** @var list<string> */
    private array $cleanupPaths = [];

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 5);
        $this->planner = new ShardPlan($this->root, (new Catalog($this->root))->validate());
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanupPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testPlanIsDeterministicCompleteAndBalancedWithoutHistory(): void
    {
        $first = $this->planner->create('platform-p0', 3, []);
        $second = $this->planner->create('platform-p0', 3, []);

        self::assertSame($first, $second);
        $ids = [];
        $counts = [];
        foreach ($first['shards'] as $index => $shard) {
            self::assertSame($index, $shard['index']);
            $sorted = $shard['suite_ids'];
            sort($sorted, SORT_STRING);
            self::assertSame($sorted, $shard['suite_ids']);
            $ids = array_merge($ids, $shard['suite_ids']);
            $counts[] = count($shard['suite_ids']);
        }
        if ($counts === []) {
            throw new \LogicException('shard plan unexpectedly has no shards');
        }
        $expectedIds = null;
        $catalog = (new Catalog($this->root))->validate();
        foreach ($catalog['profiles'] as $profile) {
            if ($profile['id'] === 'platform-p0') {
                $expectedIds = $profile['suite_ids'];
                break;
            }
        }
        if ($expectedIds === null) {
            throw new \LogicException('platform-p0 profile is absent');
        }
        sort($ids, SORT_STRING);
        sort($expectedIds, SORT_STRING);
        self::assertSame($expectedIds, $ids);
        self::assertLessThanOrEqual(1, max($counts) - min($counts));
    }

    public function testPublishedPlanRoundTripsAndBindsContext(): void
    {
        $relative = 'artifacts/test-results/shard-tests/' . bin2hex(random_bytes(6)) . '.json';
        $this->cleanupPaths[] = $this->root . '/' . $relative;
        $document = $this->planner->create('platform-p0', 2, []);
        $this->planner->publish($document, $relative);

        self::assertSame($document, $this->planner->load($relative));
        $context = $this->planner->context($relative, 1);
        self::assertSame('platform-p0', $context['profile_id']);
        self::assertSame(1, $context['index']);
        self::assertSame(2, $context['count']);
        self::assertSame($document['shards'][1]['suite_ids'], $context['suite_ids']);
        self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/D', $context['plan_sha256']);
    }

    public function testDuplicateSuiteAcrossShardsFailsClosed(): void
    {
        $relative = 'artifacts/test-results/shard-tests/' . bin2hex(random_bytes(6)) . '.json';
        $absolute = $this->root . '/' . $relative;
        $this->cleanupPaths[] = $absolute;
        $document = $this->planner->create('platform-p0', 2, []);
        $document['shards'][1]['suite_ids'][] = $document['shards'][0]['suite_ids'][0];
        sort($document['shards'][1]['suite_ids'], SORT_STRING);
        if (!is_dir(dirname($absolute))) {
            self::assertTrue(mkdir(dirname($absolute), 0700, true));
        }
        file_put_contents($absolute, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        $this->expectException(CatalogException::class);
        $this->expectExceptionMessage('invalid or duplicated');
        $this->planner->load($relative);
    }

    public function testAggregateRequiresEveryExactPassingShard(): void
    {
        $planPath = 'artifacts/test-results/shard-tests/' . bin2hex(random_bytes(6)) . '-plan.json';
        $this->cleanupPaths[] = $this->root . '/' . $planPath;
        $document = $this->planner->create('platform-p0', 2, []);
        $this->planner->publish($document, $planPath);
        $planDigest = 'sha256:' . hash('sha256', json_encode(
            $document,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
        $receipts = [];
        foreach ($document['shards'] as $shard) {
            $relative = 'artifacts/test-results/shard-tests/' . bin2hex(random_bytes(6)) . '-result.json';
            $absolute = $this->root . '/' . $relative;
            $this->cleanupPaths[] = $absolute;
            file_put_contents($absolute, json_encode([
                'format' => 'duo-test-run-receipt/v1',
                'candidate_sha' => $document['candidate_sha'],
                'authority' => 'non_authorizing_shard',
                'state' => 'pass',
                'selected_suite_ids' => $shard['suite_ids'],
                'shard' => [
                    'plan_sha256' => $planDigest,
                    'index' => $shard['index'],
                ],
            ], JSON_THROW_ON_ERROR) . "\n");
            $receipts[$shard['index']] = $relative;
        }

        $evaluation = $this->planner->aggregate($planPath, $receipts);
        self::assertSame(0, $evaluation['exit']);
        self::assertSame('pass', $evaluation['receipt']['state'] ?? null);
    }
}

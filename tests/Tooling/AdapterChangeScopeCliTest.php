<?php

declare(strict_types=1);

namespace Duo\Tests\Tooling;

use Duo\Tooling\AdapterChangeScopeDecision;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tools/src/AdapterChangeScopeDecision.php';

/** The executable gate decision must never turn uncovered input green. */
final class AdapterChangeScopeCliTest extends TestCase
{
    private static function repoRoot(): string
    {
        $env = getenv('DUO_REPO_ROOT');
        return is_string($env) && $env !== '' ? $env : dirname(__DIR__, 2);
    }

    /**
     * @param list<string> $arguments
     * @return array{status:int,stdout:string,stderr:string}
     */
    private static function invoke(array $arguments): array
    {
        $repo = self::repoRoot();
        $command = [PHP_BINARY, $repo . '/tools/adapter-change-scope.php', ...$arguments];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repo);
        self::assertIsResource($process, 'could not launch tools/adapter-change-scope.php');
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        return ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @return array<string,mixed> */
    private static function json(array $arguments): array
    {
        $result = self::invoke(['--json', ...$arguments]);
        self::assertSame(0, $result['status'], $result['stderr']);
        self::assertSame('', $result['stderr']);
        $decoded = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    public function testHumanOutputIsStableForOneAdapter(): void
    {
        $result = self::invoke(['--path=adapter-packages/acf/manifest.json']);

        self::assertSame(0, $result['status']);
        self::assertSame('', $result['stderr']);
        self::assertSame(
            "adapter-change-scope: adapter\n"
            . "adapter: acf\n"
            . "reason: single_adapter\n"
            . "owner: adapter adapter-packages/acf adapter-packages/acf/manifest.json\n",
            $result['stdout']
        );
    }

    public function testJsonOutputIsVersionedAndSelectsOneAdapter(): void
    {
        $decision = self::json([
            '--path=adapter-packages/woocommerce/manifest.json',
            '--path=adapter-packages/woocommerce/tests/offline/regress.php',
        ]);

        self::assertSame(AdapterChangeScopeDecision::FORMAT, $decision['format']);
        self::assertSame(AdapterChangeScopeDecision::GATE_ADAPTER, $decision['gate']);
        self::assertSame('woocommerce', $decision['adapter']);
        self::assertSame('single_adapter', $decision['reason_code']);
        self::assertSame(['woocommerce'], $decision['classification']['adapters']);
    }

    public function testTwoAdaptersAlwaysSelectTheFullGate(): void
    {
        $decision = self::json([
            '--path=adapter-packages/yoast/manifest.json',
            '--path=adapter-packages/acf/manifest.json',
        ]);

        self::assertSame(AdapterChangeScopeDecision::GATE_FULL, $decision['gate']);
        self::assertNull($decision['adapter']);
        self::assertSame('cross_adapter_change', $decision['reason_code']);
        self::assertSame(['acf', 'yoast'], $decision['classification']['adapters']);
    }

    /** @return iterable<string,array{path:string,reason:string}> */
    public static function fullGatePaths(): iterable
    {
        yield 'engine' => ['path' => 'agent/src/Policy/Policy.php', 'reason' => 'engine_change'];
        yield 'platform' => ['path' => 'platform/schema.json', 'reason' => 'platform_change'];
        yield 'adapter library' => [
            'path' => 'agent/adapter-library/AdapterLibrary.php',
            'reason' => 'adapter_library_change',
        ];
        yield 'shared runner' => [
            'path' => 'adapter-package-runner/bin/run.php',
            'reason' => 'shared_package_infrastructure',
        ];
        yield 'integration' => [
            'path' => 'integration-scenarios/woo-acf/scenario.json',
            'reason' => 'integration_participants_unresolved',
        ];
        yield 'unknown' => ['path' => 'future-root/new.php', 'reason' => 'uncovered_path'];
        yield 'invalid absolute' => ['path' => '/adapter-packages/acf/manifest.json', 'reason' => 'uncovered_path'];
    }

    #[DataProvider('fullGatePaths')]
    public function testNonAdapterOwnershipAlwaysSelectsFull(string $path, string $reason): void
    {
        $decision = self::json(['--path=' . $path]);

        self::assertSame(AdapterChangeScopeDecision::GATE_FULL, $decision['gate']);
        self::assertNull($decision['adapter']);
        self::assertSame($reason, $decision['reason_code']);
    }

    public function testRenameInsideOneAdapterCanSelectItsAdapter(): void
    {
        $decision = self::json([
            '--rename-from=adapter-packages/acf/tests/old.php',
            '--rename-to=adapter-packages/acf/tests/new.php',
        ]);

        self::assertSame(AdapterChangeScopeDecision::GATE_ADAPTER, $decision['gate']);
        self::assertSame('acf', $decision['adapter']);
        self::assertFalse($decision['classification']['cross_root_rename']);
    }

    public function testCrossAdapterRenameAlwaysSelectsFull(): void
    {
        $decision = self::json([
            '--rename-from=adapter-packages/acf/manifest.json',
            '--rename-to=adapter-packages/yoast/manifest.json',
        ]);

        self::assertSame(AdapterChangeScopeDecision::GATE_FULL, $decision['gate']);
        self::assertNull($decision['adapter']);
        self::assertSame('cross_root_rename', $decision['reason_code']);
        self::assertTrue($decision['classification']['cross_root_rename']);
    }

    public function testEmptyExplicitSetFailsClosedToFull(): void
    {
        $decision = self::json([]);

        self::assertSame(AdapterChangeScopeDecision::GATE_FULL, $decision['gate']);
        self::assertSame('empty_change_set', $decision['reason_code']);
    }

    /** @return iterable<string,array{0:list<string>,1:string}> */
    public static function malformedArguments(): iterable
    {
        yield 'unknown option' => [['--base=main'], 'unknown argument'];
        yield 'missing rename destination' => [
            ['--rename-from=adapter-packages/acf/old.php'],
            '--rename-from requires a following --rename-to',
        ];
        yield 'destination without source' => [
            ['--rename-to=adapter-packages/acf/new.php'],
            '--rename-to requires a preceding --rename-from',
        ];
        yield 'duplicate json' => [['--json', '--json'], '--json may be specified only once'];
    }

    /** @param list<string> $arguments */
    #[DataProvider('malformedArguments')]
    public function testMalformedUsageExitsTwoWithoutAClassification(array $arguments, string $message): void
    {
        $result = self::invoke($arguments);

        self::assertSame(2, $result['status']);
        self::assertSame('', $result['stdout']);
        self::assertStringContainsString($message, $result['stderr']);
        self::assertStringContainsString('usage: php tools/adapter-change-scope.php', $result['stderr']);
    }
}

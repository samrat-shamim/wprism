<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform\Tests;

use Duo\EngineeringPlatform\Catalog;
use Duo\EngineeringPlatform\CatalogException;
use Duo\EngineeringPlatform\Selection;
use PHPUnit\Framework\TestCase;

final class SelectionTest extends TestCase
{
    private string $root;
    private Selection $selection;

    /** @var list<string> */
    private array $cleanupPaths = [];

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 5);
        $this->selection = new Selection($this->root, (new Catalog($this->root))->validate());
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanupPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testEqualResolvedCommitsProduceBoundNotApplicableSelection(): void
    {
        $head = $this->headSha();
        self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/D', $head);

        $document = $this->selection->changed($head, $head);

        self::assertSame('not_applicable', $document['state']);
        self::assertSame([], $document['selected_suite_ids']);
        self::assertSame($head, $document['base_sha']);
        self::assertSame($head, $document['head_sha']);
        self::assertSame($head, $document['merge_base_sha']);
        self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/D', $document['changed_paths_sha256']);
    }

    public function testAbbreviatedCommitIsRejected(): void
    {
        $this->expectException(CatalogException::class);
        $this->expectExceptionMessage('base SHA must be 40 lowercase hexadecimal characters');
        $this->selection->changed('deadbeef', str_repeat('a', 40));
    }

    public function testExplicitConformanceSubjectsResolveToCatalogIds(): void
    {
        $document = $this->selection->explicit('conformance', ['core', 'woocommerce']);

        self::assertSame('diagnostic', $document['authority']);
        self::assertSame(
            ['legacy-conformance-core', 'legacy-conformance-woocommerce'],
            $document['selected_suite_ids'],
        );
        self::assertNull($document['head_sha']);
    }

    public function testPublishedSelectionRejectsDigestTampering(): void
    {
        $path = 'artifacts/test-results/selection-tests/' . bin2hex(random_bytes(6)) . '.json';
        $absolute = $this->root . '/' . $path;
        $this->cleanupPaths[] = $absolute;
        $document = $this->selection->explicit('integration', ['platform-catalog-self-test']);
        $this->selection->publish($document, $path);
        $decoded = json_decode((string) file_get_contents($absolute), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \LogicException('published selection is not an object');
        }
        $decoded['changed_paths_sha256'] = 'sha256:' . str_repeat('0', 64);
        file_put_contents($absolute, json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        $this->expectException(CatalogException::class);
        $this->expectExceptionMessage('changed-path digest');
        $this->selection->load($path);
    }

    private function headSha(): string
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
            throw new \RuntimeException('cannot resolve test HEAD');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($stdout)) {
            throw new \RuntimeException('cannot resolve test HEAD');
        }
        return trim($stdout);
    }
}

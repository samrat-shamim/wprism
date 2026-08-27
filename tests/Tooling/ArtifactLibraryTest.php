<?php

declare(strict_types=1);

namespace Duo\Tests\Tooling;

use Duo\Tooling\ArtifactLibrary;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/tools/src/ArtifactLibrary.php';

final class ArtifactLibraryTest extends TestCase
{
    private string $repoRoot;
    private string $scratch;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $this->scratch = sys_get_temp_dir() . '/duo-artifact-library-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch . '/adapter-packages', 0777, true));
        self::assertTrue(mkdir($this->scratch . '/platform/artifact-library', 0777, true));
    }

    protected function tearDown(): void
    {
        self::removeTree($this->scratch);
    }

    public function testDiscoveredAggregatePreservesEveryFormerFlatLockFact(): void
    {
        $library = ArtifactLibrary::load($this->repoRoot);
        $canonical = json_encode(self::sortRecursive($library), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        self::assertCount(16, $library['plugins']);
        self::assertCount(2, $library['themes']);
        self::assertSame(['3.3.21.4', '3.4.34.2', '3.14.11'], array_keys($library['plugins']['ninja-forms']));
        self::assertSame(46, array_sum(array_map('count', $library['plugins']))
            + array_sum(array_map('count', $library['themes'])));
        self::assertSame('5f5d65df4bea8f3801545d5a4bd0c4dafa653eacb84ed776f0056bc5c908256f', hash('sha256', $canonical));
    }

    public function testOneAdapterLoadsWithoutPlatformOrSiblingCapsules(): void
    {
        $library = ArtifactLibrary::loadPackage($this->repoRoot, 'woocommerce');

        self::assertSame(['woocommerce'], array_keys($library['plugins']));
        self::assertSame(['10.9.4', '11.0.0', '11.0.1'], array_keys($library['plugins']['woocommerce']));
        self::assertSame([], $library['themes']);
    }

    public function testDuplicateSubjectOwnershipRefusesInsteadOfOverwriting(): void
    {
        $this->writeFragment('platform/artifact-library/artifacts.lock.json', [
            'plugins' => [],
            'themes' => ['core-theme' => ['1.0' => self::entry('exercise-fixture')]],
        ]);
        $this->writeFragment('adapter-packages/alpha/evidence/artifacts.lock.json', [
            'plugins' => ['duplicate' => ['1.0' => self::entry('certified-boundary')]],
            'themes' => [],
        ]);
        $this->writeFragment('adapter-packages/beta/evidence/artifacts.lock.json', [
            'plugins' => ['duplicate' => ['2.0' => self::entry('refusal-fixture')]],
            'themes' => [],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Artifact subject is owned by more than one fragment: plugins/duplicate');
        ArtifactLibrary::load($this->scratch);
    }

    public function testThemeCannotClaimAPluginOnlyRole(): void
    {
        $this->writeFragment('platform/artifact-library/artifacts.lock.json', [
            'plugins' => [],
            'themes' => ['core-theme' => ['1.0' => self::entry('certified-boundary')]],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Theme artifact roles are exercise-fixture only');
        ArtifactLibrary::load($this->scratch);
    }

    /** @return array{url:string,sha256:string,role:string} */
    private static function entry(string $role): array
    {
        return [
            'url' => 'https://fixture.invalid/artifact.zip',
            'sha256' => str_repeat('a', 64),
            'role' => $role,
        ];
    }

    /** @param array<string,mixed> $fragment */
    private function writeFragment(string $relative, array $fragment): void
    {
        $path = $this->scratch . '/' . $relative;
        $directory = dirname($path);
        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0777, true));
        }
        self::assertNotFalse(file_put_contents(
            $path,
            json_encode($fragment, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        ));
    }

    private static function sortRecursive(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as &$item) {
            $item = self::sortRecursive($item);
        }
        unset($item);
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return $value;
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_dir($child) && !is_link($child)) {
                self::removeTree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}

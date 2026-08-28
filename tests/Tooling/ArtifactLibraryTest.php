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

    public function testDiscoveredAggregateIsExactlyTheDynamicFragmentUnion(): void
    {
        $library = ArtifactLibrary::load($this->repoRoot);
        $expected = ['plugins' => [], 'themes' => []];
        $fragments = [$this->repoRoot . '/platform/artifact-library/artifacts.lock.json'];
        foreach (scandir($this->repoRoot . '/adapter-packages') ?: [] as $package) {
            if ($package === '.' || $package === '..') {
                continue;
            }
            $path = ArtifactLibrary::packagePath($this->repoRoot, $package);
            if (is_file($path)) {
                $fragments[] = $path;
            }
        }
        sort($fragments, SORT_STRING);
        foreach ($fragments as $path) {
            $fragment = ArtifactLibrary::loadFragment($path);
            foreach (['plugins', 'themes'] as $namespace) {
                foreach ($fragment[$namespace] as $subject => $versions) {
                    self::assertArrayNotHasKey($subject, $expected[$namespace]);
                    $expected[$namespace][$subject] = $versions;
                }
            }
        }
        ksort($expected['plugins'], SORT_STRING);
        ksort($expected['themes'], SORT_STRING);

        self::assertNotSame([], $expected['plugins']);
        self::assertSame($expected, $library);
    }

    public function testOneAdapterLoadsWithoutPlatformOrSiblingCapsules(): void
    {
        $library = ArtifactLibrary::loadPackage($this->repoRoot, 'woocommerce');

        self::assertSame(['woocommerce'], array_keys($library['plugins']));
        self::assertSame(['10.9.4', '11.0.0', '11.0.1'], array_keys($library['plugins']['woocommerce']));
        self::assertSame([], $library['themes']);
    }

    public function testScenarioParticipantsIgnoreMalformedNonParticipantWithoutChangingGlobalAggregation(): void
    {
        $this->writeFragment('adapter-packages/woocommerce/evidence/artifacts.lock.json', [
            'plugins' => ['woocommerce' => ['11.0.1' => self::entry('certified-boundary')]],
            'themes' => [],
        ]);
        $this->writeFragment('adapter-packages/yoast/evidence/artifacts.lock.json', [
            'plugins' => ['wordpress-seo' => ['28.3' => self::entry('certified-boundary')]],
            'themes' => [],
        ]);
        $acf = $this->scratch . '/adapter-packages/acf/evidence';
        self::assertTrue(mkdir($acf, 0777, true));
        self::assertNotFalse(file_put_contents($acf . '/artifacts.lock.json', "not json\n"));

        $library = ArtifactLibrary::loadParticipants($this->scratch, ['woocommerce', 'yoast']);

        self::assertSame(['woocommerce', 'wordpress-seo'], array_keys($library['plugins']));
        self::assertSame([], $library['themes']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Artifact fragment is not valid JSON');
        ArtifactLibrary::load($this->scratch);
    }

    public function testScenarioParticipantMutationStillRefuses(): void
    {
        $this->writeFragment('adapter-packages/woocommerce/evidence/artifacts.lock.json', [
            'plugins' => ['woocommerce' => ['11.0.1' => self::entry('certified-boundary')]],
            'themes' => [],
        ]);
        $this->writeFragment('adapter-packages/yoast/evidence/artifacts.lock.json', [
            'plugins' => ['wordpress-seo' => ['28.3' => self::entry('certified-boundary')]],
            'themes' => [],
        ]);
        self::assertSame(
            ['woocommerce', 'wordpress-seo'],
            array_keys(ArtifactLibrary::loadParticipants($this->scratch, ['woocommerce', 'yoast'])['plugins'])
        );

        self::assertNotFalse(file_put_contents(
            $this->scratch . '/adapter-packages/yoast/evidence/artifacts.lock.json',
            "not json\n"
        ));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Artifact fragment is not valid JSON');
        ArtifactLibrary::loadParticipants($this->scratch, ['woocommerce', 'yoast']);
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

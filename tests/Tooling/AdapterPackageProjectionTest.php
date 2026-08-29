<?php

declare(strict_types=1);

namespace WPrism\Tests\Tooling;

use WPrism\Tooling\AdapterPackageProjection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/tools/src/AdapterPackageProjection.php';

final class AdapterPackageProjectionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wprism-adapter-projection-' . bin2hex(random_bytes(8));
        self::makeDirectory($this->root);
        $resolvedRoot = realpath($this->root);
        self::assertNotFalse($resolvedRoot);
        $this->root = $resolvedRoot;
        self::makeDirectory($this->root . '/adapter-packages');
        $this->addPlatform();
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    public function testPlanIsOrderedAndContainsOnlyTheAllowlistedProjection(): void
    {
        $this->addPackage('zeta');
        $this->addPackage(
            'acf',
            [
                'interpreter' => 'acf',
                'providers' => [
                    ['id' => 'remote-provider', 'source' => 'plugin'],
                    ['id' => 'cache', 'source' => 'manifest'],
                ],
                'post_types' => [
                    'article' => ['regen_dependency' => ['regenerator' => 'article-index']],
                ],
            ],
            [
                'interpreters/acf.php',
                'providers/cache.php',
                'regenerators/article-index.php',
            ]
        );

        self::makeDirectory($this->root . '/adapter-packages/acf/tests/offline');
        self::write($this->root . '/adapter-packages/acf/tests/offline/regress.php', '<?php');
        self::makeDirectory($this->root . '/adapter-packages/acf/fixtures');
        self::write($this->root . '/adapter-packages/acf/fixtures/site.json', '{}');
        self::makeDirectory($this->root . '/adapter-packages/acf/evidence');
        self::write($this->root . '/adapter-packages/acf/evidence/live.txt', 'not shipped');
        self::write($this->root . '/adapter-packages/acf/README.md', '# ACF');

        $plan = AdapterPackageProjection::plan($this->root);
        $acf = $this->root . '/adapter-packages/acf/package';
        $zeta = $this->root . '/adapter-packages/zeta/package';
        $platform = $this->root . '/platform/adapter-library';
        self::assertSame([
            $acf . '/manifest.json' => 'adapter-library/adapters/acf/manifest.json',
            $acf . '/disposition.json' => 'adapter-library/adapters/acf/disposition.json',
            $acf . '/runtime/interpreters/acf.php' => 'adapter-library/adapters/acf/runtime/interpreters/acf.php',
            $acf . '/runtime/providers/cache.php' => 'adapter-library/adapters/acf/runtime/providers/cache.php',
            $acf . '/runtime/regenerators/article-index.php' => 'adapter-library/adapters/acf/runtime/regenerators/article-index.php',
            $zeta . '/manifest.json' => 'adapter-library/adapters/zeta/manifest.json',
            $zeta . '/disposition.json' => 'adapter-library/adapters/zeta/disposition.json',
            $platform . '/core/manifest.json' => 'adapter-library/platform/core/manifest.json',
            $platform . '/core/disposition.json' => 'adapter-library/platform/core/disposition.json',
            $platform . '/profiles.json' => 'adapter-library/platform/profiles.json',
            $platform . '/capabilities/platform.json' => 'adapter-library/platform/capabilities/platform.json',
            $platform . '/capabilities/adapter-authorities.json' => 'adapter-library/platform/capabilities/adapter-authorities.json',
        ], $plan);

        foreach ($plan as $source => $destination) {
            self::assertStringStartsWith($this->root . '/', $source);
            self::assertStringStartsWith('adapter-library/', $destination);
            self::assertStringNotContainsString('/tests/', $destination);
            self::assertStringNotContainsString('/fixtures/', $destination);
            self::assertStringNotContainsString('/evidence/', $destination);
            self::assertStringNotContainsString('README', $destination);
        }
    }

    /** @return iterable<string,array{0:string}> */
    public static function unknownPackageMembers(): iterable
    {
        yield 'ordinary file' => ['notes.txt'];
        yield 'authoring directory inside package' => ['tests'];
        yield 'readme inside package' => ['README.md'];
    }

    #[DataProvider('unknownPackageMembers')]
    public function testUnknownPackageMembersAreRejected(string $member): void
    {
        $this->addPackage('acf');
        $path = $this->root . '/adapter-packages/acf/package/' . $member;
        str_contains($member, '.') ? self::write($path, 'x') : self::makeDirectory($path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown package/ for acf member');
        AdapterPackageProjection::plan($this->root);
    }

    public function testManifestNameMustMatchPackageBasename(): void
    {
        $this->addPackage('acf', ['name' => 'yoast']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('name/basename mismatch');
        AdapterPackageProjection::plan($this->root);
    }

    public function testNonCanonicalPackageBasenameIsRejected(): void
    {
        self::makeDirectory($this->root . '/adapter-packages/Bad_Name');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical slug');
        AdapterPackageProjection::plan($this->root);
    }

    /** @return iterable<string,array{0:string}> */
    public static function missingRequiredFiles(): iterable
    {
        yield 'manifest' => ['adapter-packages/acf/package/manifest.json'];
        yield 'disposition' => ['adapter-packages/acf/package/disposition.json'];
        yield 'platform profiles' => ['platform/adapter-library/profiles.json'];
        yield 'platform authority' => ['platform/adapter-library/capabilities/adapter-authorities.json'];
    }

    #[DataProvider('missingRequiredFiles')]
    public function testMissingRequiredFilesAreRejected(string $relativePath): void
    {
        $this->addPackage('acf');
        self::assertTrue(unlink($this->root . '/' . $relativePath));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required');
        AdapterPackageProjection::plan($this->root);
    }

    public function testUndeclaredRuntimePhpIsRejected(): void
    {
        $this->addPackage('acf', [], ['providers/ghost.php']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Undeclared runtime PHP');
        AdapterPackageProjection::plan($this->root);
    }

    public function testDeclaredRuntimePhpMustExist(): void
    {
        $this->addPackage('acf', ['interpreter' => 'acf']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing declared runtime PHP');
        AdapterPackageProjection::plan($this->root);
    }

    public function testNonPhpRuntimeMemberIsRejected(): void
    {
        $this->addPackage('acf', [], ['providers/readme.txt']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown non-PHP runtime member');
        AdapterPackageProjection::plan($this->root);
    }

    public function testUnsafeDeclaredRuntimePathIsRejected(): void
    {
        $this->addPackage('acf', ['interpreter' => '../escape']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsafe declared runtime path');
        AdapterPackageProjection::plan($this->root);
    }

    public function testSymlinkedPackageFileIsRejectedBeforeResolution(): void
    {
        $this->addPackage('acf');
        $manifest = $this->root . '/adapter-packages/acf/package/manifest.json';
        self::assertTrue(unlink($manifest));
        self::assertTrue(symlink('/etc/hosts', $manifest));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not be a symbolic link');
        AdapterPackageProjection::plan($this->root);
    }

    public function testSymlinkInExcludedAuthoringTreeIsAlsoRejected(): void
    {
        $this->addPackage('acf');
        self::makeDirectory($this->root . '/adapter-packages/acf/fixtures');
        self::assertTrue(symlink('/etc/hosts', $this->root . '/adapter-packages/acf/fixtures/escape'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not be a symbolic link');
        AdapterPackageProjection::plan($this->root);
    }

    public function testSpecialRuntimeNodeIsRejected(): void
    {
        if (!function_exists('posix_mkfifo')) {
            self::markTestSkipped('posix_mkfifo is unavailable');
        }

        $this->addPackage('acf', [
            'providers' => [['id' => 'cache', 'source' => 'manifest']],
        ]);
        $providerDirectory = $this->root . '/adapter-packages/acf/package/runtime/providers';
        self::makeDirectory($providerDirectory);
        self::assertTrue(posix_mkfifo($providerDirectory . '/cache.php', 0600));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not an ordinary regular file');
        AdapterPackageProjection::plan($this->root);
    }

    public function testUnknownPlatformMemberIsRejected(): void
    {
        $this->addPackage('acf');
        self::write($this->root . '/platform/adapter-library/capabilities/revocations.json', '{}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown platform capabilities member');
        AdapterPackageProjection::plan($this->root);
    }

    /**
     * @param array<string,mixed> $manifestFields
     * @param list<string> $runtimeFiles
     */
    private function addPackage(string $slug, array $manifestFields = [], array $runtimeFiles = []): void
    {
        $package = $this->root . '/adapter-packages/' . $slug . '/package';
        self::makeDirectory($package);
        self::writeJson($package . '/manifest.json', ['name' => $slug, ...$manifestFields]);
        self::writeJson($package . '/disposition.json', ['status' => 'supported']);
        foreach ($runtimeFiles as $relativePath) {
            self::makeDirectory(dirname($package . '/runtime/' . $relativePath));
            self::write($package . '/runtime/' . $relativePath, '<?php // fixture');
        }
    }

    private function addPlatform(): void
    {
        $platform = $this->root . '/platform/adapter-library';
        self::makeDirectory($platform . '/core');
        self::makeDirectory($platform . '/capabilities');
        self::writeJson($platform . '/core/manifest.json', ['name' => 'core']);
        self::writeJson($platform . '/core/disposition.json', ['status' => 'supported']);
        self::writeJson($platform . '/profiles.json', ['profiles' => []]);
        self::writeJson($platform . '/capabilities/platform.json', ['capabilities' => []]);
        self::writeJson($platform . '/capabilities/adapter-authorities.json', ['authorities' => []]);
    }

    /** @param array<mixed> $value */
    private static function writeJson(string $path, array $value): void
    {
        $bytes = json_encode($value, JSON_THROW_ON_ERROR);
        self::write($path, $bytes);
    }

    private static function write(string $path, string $bytes): void
    {
        $result = file_put_contents($path, $bytes);
        self::assertNotFalse($result);
    }

    private static function makeDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        self::assertTrue(mkdir($path, 0777, true));
    }

    private static function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || !is_dir($path)) {
            unlink($path);

            return;
        }

        $entries = scandir($path);
        if ($entries !== false) {
            foreach (array_diff($entries, ['.', '..']) as $entry) {
                self::removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}

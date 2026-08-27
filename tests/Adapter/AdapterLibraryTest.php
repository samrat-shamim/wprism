<?php

declare(strict_types=1);

namespace Duo\Tests\Adapter;

use Duo\AdapterCertification;
use Duo\AdapterLibrary;
use Duo\ManifestDispositions;
use Duo\Policy;
use Duo\Orchestrator\ContractAttestation;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/agent/src/Policy/AdapterLibrary.php';
require_once dirname(__DIR__, 2) . '/agent/src/Policy/ManifestDispositions.php';
require_once dirname(__DIR__, 2) . '/agent/src/Policy/Policy.php';
require_once dirname(__DIR__, 2) . '/agent/src/Adapter/AdapterCertification.php';
require_once dirname(__DIR__, 2) . '/cli/src/Contract/ContractAttestation.php';

/** Pin the closed physical inventory before any production consumer adopts it. */
final class AdapterLibraryTest extends TestCase
{
    /** @var list<string> */
    private array $scratchRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->scratchRoots as $root) {
            self::removeTree($root);
        }
        $this->scratchRoots = [];
    }

    public function testCurrentSourceLibraryIncludesRedirectionAndSortedPackages(): void
    {
        $root = dirname(__DIR__, 2);
        $library = AdapterLibrary::fromSourceTree($root);
        $names = array_map(static fn($package): string => $package->name(), $library->packages());

        $this->assertCount(17, $names);
        $sorted = $names;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $names);

        $redirection = $library->package('redirection');
        $this->assertNotNull($redirection);
        $this->assertNull($redirection->interpreterPath());
        $this->assertSame(
            realpath($root . '/adapter-packages/redirection/package/runtime/providers/redirection-state.php'),
            $redirection->providerPath('redirection-state')
        );
        $this->assertSame([
            realpath($root . '/adapter-packages/redirection/package/disposition.json'),
            realpath($root . '/adapter-packages/redirection/package/manifest.json'),
            realpath($root . '/adapter-packages/redirection/package/runtime/providers/redirection-state.php'),
        ], $redirection->shippablePaths());
        $this->assertContains(
            realpath($root . '/adapter-packages/redirection/package/runtime/providers'),
            $library->scanAnchors()
        );
        $this->assertContains(
            realpath($root . '/adapter-packages/redirection/package/runtime/providers/redirection-state.php'),
            $library->scanFiles()
        );
        $this->assertSame(
            $root . '/platform/adapter-library/capabilities/adapter-revocations.json',
            $library->revocationsPath()
        );
        $this->assertNotContains($library->revocationsPath(), $library->scanFiles());
    }

    public function testTrustConsumersUseObjectPathsWithoutMovingCurrentValues(): void
    {
        $root = dirname(__DIR__, 2);
        $platformRoot = $root . '/platform/adapter-library';
        $library = AdapterLibrary::fromSourceTree($root);
        $dispositions = ManifestDispositions::load_library($library);

        $this->assertSame(64, strlen($dispositions->sha256()));
        $this->assertSame(
            ManifestDispositions::platform_boundary($platformRoot),
            ManifestDispositions::platform_boundary_library($library)
        );
        $this->assertSame(
            AdapterCertification::hasAuthorities($platformRoot),
            AdapterCertification::hasAuthorities($library)
        );
        $this->assertSame(
            AdapterCertification::revocation_channel($platformRoot),
            AdapterCertification::revocation_channel($library)
        );
        $this->assertSame(
            ContractAttestation::currentPlatformDigest($platformRoot),
            ContractAttestation::currentPlatformDigest($library)
        );
    }

    public function testProductionSelectionIgnoresTheRetiredProcessGlobalFlatPath(): void
    {
        $legacy = $this->fixture();
        $previous = getenv('DUO_MANIFESTS_DIR');
        putenv('DUO_MANIFESTS_DIR=' . $legacy);
        try {
            $library = Policy::adapter_library_context();
        } finally {
            $previous === false
                ? putenv('DUO_MANIFESTS_DIR')
                : putenv('DUO_MANIFESTS_DIR=' . $previous);
        }

        $this->assertInstanceOf(AdapterLibrary::class, $library);
        $this->assertSame(realpath(dirname(__DIR__, 2)), $library->root());
        $this->assertFalse(method_exists(Policy::class, 'manifests_dir'));
        $policySource = (string) file_get_contents(dirname(__DIR__, 2) . '/agent/src/Policy/Policy.php');
        $this->assertStringNotContainsString('DUO_MANIFESTS_DIR', $policySource);
        $this->assertStringNotContainsString('fromLegacyFlatDirectory', $policySource);
    }

    public function testPackagePathsFollowDecodedRuntimeDeclarationsWithoutRewritingManifest(): void
    {
        $root = $this->fixture([
            'name' => 'alpha',
            'interpreter' => 'alpha',
            'providers' => [
                ['id' => 'plugin-owned', 'source' => 'plugin'],
                ['id' => 'alpha-provider', 'source' => 'manifest'],
            ],
            'post_types' => [
                'post' => ['regen_dependency' => ['regenerator' => 'alpha-regenerator']],
            ],
        ]);
        $manifestBefore = file_get_contents($root . '/alpha.json');

        $library = AdapterLibrary::fromLegacyFlatDirectory($root);
        $package = $library->package('alpha');

        $this->assertNotNull($package);
        $this->assertSame(realpath($root), $library->root());
        $this->assertSame(realpath($root . '/alpha.json'), $package->manifestPath());
        $this->assertSame(realpath($root . '/dispositions/alpha.json'), $package->dispositionPath());
        $this->assertSame(realpath($root . '/interpreters/alpha.php'), $package->interpreterPath());
        $this->assertSame(
            realpath($root . '/providers/alpha-provider.php'),
            $package->providerPath('alpha-provider')
        );
        $this->assertSame(
            realpath($root . '/regenerators/alpha-regenerator.php'),
            $package->regeneratorPath('alpha-regenerator')
        );
        $this->assertSame($manifestBefore, file_get_contents($root . '/alpha.json'));
    }

    public function testMissingDeclaredRuntimeFileRefuses(): void
    {
        $root = $this->fixture();
        unlink($root . '/providers/alpha-provider.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing=[alpha-provider]');
        AdapterLibrary::fromLegacyFlatDirectory($root);
    }

    public function testUndeclaredRuntimePhpRefuses(): void
    {
        $root = $this->fixture();
        file_put_contents($root . '/providers/extra.php', "<?php\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('undeclared=[extra]');
        AdapterLibrary::fromLegacyFlatDirectory($root);
    }

    public function testManifestBasenameMustMatchDeclaredName(): void
    {
        $root = $this->fixture();
        $manifest = json_decode((string) file_get_contents($root . '/alpha.json'), true);
        $manifest['name'] = 'different';
        file_put_contents($root . '/alpha.json', json_encode($manifest, JSON_PRETTY_PRINT) . "\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('basename alpha disagrees with its declared name different');
        AdapterLibrary::fromLegacyFlatDirectory($root);
    }

    public function testDuplicateDeclaredNameRefusesBeforeASecondPackageCanBeConstructed(): void
    {
        $root = $this->fixture();
        copy($root . '/alpha.json', $root . '/beta.json');
        copy($root . '/dispositions/alpha.json', $root . '/dispositions/beta.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate adapter name alpha');
        AdapterLibrary::fromLegacyFlatDirectory($root);
    }

    public function testRuntimePathEscapeInManifestRefuses(): void
    {
        $root = $this->fixture();
        $manifest = json_decode((string) file_get_contents($root . '/alpha.json'), true);
        $manifest['providers'][0]['id'] = '../outside';
        file_put_contents($root . '/alpha.json', json_encode($manifest, JSON_PRETTY_PRINT) . "\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('provider id is not a canonical lowercase ASCII slug');
        AdapterLibrary::fromLegacyFlatDirectory($root);
    }

    public function testSymlinkedRuntimeRefusesEvenWhenItIsDeclared(): void
    {
        $root = $this->fixture();
        $outside = $this->scratch('outside');
        $outsideFile = $outside . '/alpha-provider.php';
        file_put_contents($outsideFile, "<?php\n");
        unlink($root . '/providers/alpha-provider.php');
        if (!@symlink($outsideFile, $root . '/providers/alpha-provider.php')) {
            $this->markTestSkipped('symlinks are unavailable');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('runtime may not be a symlink');
        AdapterLibrary::fromLegacyFlatDirectory($root);
    }

    public function testInvalidAndSymlinkedRootsRefuse(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('root is not a readable directory');
        AdapterLibrary::fromLegacyFlatDirectory($this->scratch('missing') . '/absent');
    }

    public function testSourceTreeFactoryOwnsOnlyPackagePayloadsAndPlatformFiles(): void
    {
        [$root, $packageRoot, $platformRoot] = $this->logicalFixture('source');
        file_put_contents(dirname($packageRoot) . '/README.md', "authoring only\n");
        mkdir(dirname($packageRoot) . '/tests');
        file_put_contents(dirname($packageRoot) . '/tests/regress.php', "<?php\n");

        $library = AdapterLibrary::fromSourceTree($root);
        $alpha = $library->package('alpha');

        $this->assertSame(realpath($root), $library->root());
        $this->assertSame(['alpha', 'core'], array_map(static fn($package): string => $package->name(), $library->packages()));
        $this->assertNotNull($alpha);
        $this->assertSame(realpath($packageRoot), $alpha->root());
        $this->assertSame(realpath($packageRoot . '/manifest.json'), $alpha->manifestPath());
        $this->assertSame(realpath($packageRoot . '/disposition.json'), $alpha->dispositionPath());
        $this->assertSame(realpath($packageRoot . '/runtime/interpreters/alpha.php'), $alpha->interpreterPath());
        $this->assertSame(
            realpath($packageRoot . '/runtime/providers/alpha-provider.php'),
            $alpha->providerPath('alpha-provider')
        );
        $this->assertSame(realpath($platformRoot . '/profiles.json'), $library->profilesPath());
        $this->assertSame(realpath($platformRoot . '/capabilities/platform.json'), $library->platformBoundaryPath());
        $this->assertContains(realpath($packageRoot), $library->scanAnchors());
        $this->assertContains(realpath($packageRoot . '/manifest.json'), $library->scanFiles());
        $this->assertNotContains(realpath(dirname($packageRoot) . '/README.md'), $library->scanFiles());
        $this->assertNotContains(realpath(dirname($packageRoot) . '/tests/regress.php'), $library->scanFiles());
    }

    public function testEmbeddedFactoryReadsOnlyTheProjectedLibrary(): void
    {
        [$root, $packageRoot, $platformRoot] = $this->logicalFixture('embedded');

        $library = AdapterLibrary::fromEmbeddedDirectory($root);
        $alpha = $library->package('alpha');

        $this->assertNotNull($alpha);
        $this->assertSame(['alpha', 'core'], array_map(static fn($package): string => $package->name(), $library->packages()));
        $this->assertSame(realpath($packageRoot . '/manifest.json'), $alpha->manifestPath());
        $this->assertSame(realpath($platformRoot . '/core/manifest.json'), $library->package('core')?->manifestPath());
        $this->assertSame(
            dirname($library->profilesPath()) . '/capabilities/adapter-revocations.json',
            $library->revocationsPath()
        );
        $this->assertNotContains($library->revocationsPath(), $library->scanFiles());
    }

    public function testEmbeddedFactoryCanBindDurableOperatorRevocationsOutsideAgentTree(): void
    {
        [$root] = $this->logicalFixture('embedded');
        $control = $this->scratch('control') . '/adapter-revocations.json';
        file_put_contents($control, "{}\n");

        $library = AdapterLibrary::fromEmbeddedDirectory($root, $control);

        $this->assertSame(realpath($control), $library->revocationsPath());
        $this->assertContains(realpath($control), $library->scanFiles());
        $this->assertContains(realpath($control), $library->scanAnchors());
    }

    public function testEmbeddedFactoryRefusesTwoRevocationAuthorities(): void
    {
        [$root, , $platformRoot] = $this->logicalFixture('embedded');
        file_put_contents($platformRoot . '/capabilities/adapter-revocations.json', "{}\n");
        $control = $this->scratch('control-empty') . '/adapter-revocations.json';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('may not carry operator revocations');
        AdapterLibrary::fromEmbeddedDirectory($root, $control);
    }

    public function testLogicalFactoriesDoNotSearchForAnotherLayout(): void
    {
        [$root] = $this->logicalFixture('source');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unexpected embedded adapter library entry');
        AdapterLibrary::fromEmbeddedDirectory($root);
    }

    public function testLogicalPackageSlugMustBeCanonical(): void
    {
        [$root, $packageRoot] = $this->logicalFixture('source');
        rename(dirname($packageRoot), dirname(dirname($packageRoot)) . '/Alpha');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not a canonical adapter slug');
        AdapterLibrary::fromSourceTree($root);
    }

    public function testLogicalManifestSubjectMustAgreeWithPackageSlug(): void
    {
        [$root, $packageRoot] = $this->logicalFixture('source');
        $manifest = json_decode((string) file_get_contents($packageRoot . '/manifest.json'), true);
        $manifest['name'] = 'different';
        file_put_contents($packageRoot . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT) . "\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('package basename alpha disagrees with its declared name different');
        AdapterLibrary::fromSourceTree($root);
    }

    public function testLogicalPackageRequiresItsDisposition(): void
    {
        [$root, $packageRoot] = $this->logicalFixture('embedded');
        unlink($packageRoot . '/disposition.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('adapter alpha disposition is not a readable regular file');
        AdapterLibrary::fromEmbeddedDirectory($root);
    }

    public function testLogicalPackageRequiresEveryDeclaredRuntimeFile(): void
    {
        [$root, $packageRoot] = $this->logicalFixture('embedded');
        unlink($packageRoot . '/runtime/providers/alpha-provider.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing=[alpha-provider]');
        AdapterLibrary::fromEmbeddedDirectory($root);
    }

    public function testLogicalPackageRefusesUndeclaredRuntime(): void
    {
        [$root, $packageRoot] = $this->logicalFixture('embedded');
        file_put_contents($packageRoot . '/runtime/providers/extra.php', "<?php\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('undeclared=[extra]');
        AdapterLibrary::fromEmbeddedDirectory($root);
    }

    public function testLogicalPackageRefusesUnknownShippableMembers(): void
    {
        [$root, $packageRoot] = $this->logicalFixture('embedded');
        file_put_contents($packageRoot . '/helper.php', "<?php\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unexpected adapter package alpha entry');
        AdapterLibrary::fromEmbeddedDirectory($root);
    }

    public function testLogicalPackageRefusesSymlinkedShippableBytes(): void
    {
        [$root, $packageRoot] = $this->logicalFixture('embedded');
        $outside = $this->scratch('logical-outside');
        $outsideFile = $outside . '/disposition.json';
        file_put_contents($outsideFile, "{}\n");
        unlink($packageRoot . '/disposition.json');
        if (!@symlink($outsideFile, $packageRoot . '/disposition.json')) {
            $this->markTestSkipped('symlinks are unavailable');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('disposition may not be a symlink');
        AdapterLibrary::fromEmbeddedDirectory($root);
    }

    public function testPlatformCoreCannotBeShadowedByAnAdapterPackage(): void
    {
        [$root] = $this->logicalFixture('source');
        $packageRoot = $root . '/adapter-packages/core/package';
        mkdir($packageRoot, 0o777, true);
        file_put_contents($packageRoot . '/manifest.json', "{\"name\":\"core\"}\n");
        file_put_contents($packageRoot . '/disposition.json', "{}\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('collides with the platform-owned core package');
        AdapterLibrary::fromSourceTree($root);
    }

    private function fixture(?array $manifest = null): string
    {
        $root = $this->scratch('library');
        foreach (['dispositions', 'capabilities', 'interpreters', 'providers', 'regenerators'] as $directory) {
            mkdir($root . '/' . $directory, 0o777, true);
        }
        file_put_contents($root . '/dispositions/profiles.json', "{}\n");
        file_put_contents($root . '/capabilities/platform.json', "{}\n");
        file_put_contents($root . '/capabilities/adapter-authorities.json', "{}\n");
        file_put_contents($root . '/dispositions/alpha.json', "{}\n");

        $manifest ??= [
            'name' => 'alpha',
            'interpreter' => 'alpha',
            'providers' => [['id' => 'alpha-provider', 'source' => 'manifest']],
            'post_types' => [
                'post' => ['regen_dependency' => ['regenerator' => 'alpha-regenerator']],
            ],
        ];
        file_put_contents($root . '/alpha.json', json_encode($manifest, JSON_PRETTY_PRINT) . "\n");
        file_put_contents($root . '/interpreters/alpha.php', "<?php\n");
        file_put_contents($root . '/providers/alpha-provider.php', "<?php\n");
        file_put_contents($root . '/regenerators/alpha-regenerator.php', "<?php\n");
        return $root;
    }

    /** @return array{0:string,1:string,2:string} */
    private function logicalFixture(string $layout): array
    {
        $root = $this->scratch('logical-' . $layout);
        if ($layout === 'source') {
            $packageRoot = $root . '/adapter-packages/alpha/package';
            $platformRoot = $root . '/platform/adapter-library';
        } else {
            $packageRoot = $root . '/adapters/alpha';
            $platformRoot = $root . '/platform';
        }

        foreach (['interpreters', 'providers', 'regenerators'] as $kind) {
            mkdir($packageRoot . '/runtime/' . $kind, 0o777, true);
        }
        mkdir($platformRoot . '/core', 0o777, true);
        mkdir($platformRoot . '/capabilities', 0o777, true);

        $manifest = [
            'name' => 'alpha',
            'interpreter' => 'alpha',
            'providers' => [['id' => 'alpha-provider', 'source' => 'manifest']],
            'post_types' => [
                'post' => ['regen_dependency' => ['regenerator' => 'alpha-regenerator']],
            ],
        ];
        file_put_contents($packageRoot . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT) . "\n");
        file_put_contents($packageRoot . '/disposition.json', "{}\n");
        file_put_contents($packageRoot . '/runtime/interpreters/alpha.php', "<?php\n");
        file_put_contents($packageRoot . '/runtime/providers/alpha-provider.php', "<?php\n");
        file_put_contents($packageRoot . '/runtime/regenerators/alpha-regenerator.php', "<?php\n");
        file_put_contents($platformRoot . '/core/manifest.json', "{\"name\":\"core\"}\n");
        file_put_contents($platformRoot . '/core/disposition.json', "{}\n");
        file_put_contents($platformRoot . '/profiles.json', "{}\n");
        file_put_contents($platformRoot . '/capabilities/platform.json', "{}\n");
        file_put_contents($platformRoot . '/capabilities/adapter-authorities.json', "{}\n");

        return [$root, $packageRoot, $platformRoot];
    }

    private function scratch(string $label): string
    {
        $root = sys_get_temp_dir() . '/duo-adapter-library-' . $label . '-' . bin2hex(random_bytes(6));
        $this->scratchRoots[] = $root;
        mkdir($root, 0o777, true);
        return $root;
    }

    private static function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTree($path . '/' . $entry);
                }
            }
            rmdir($path);
            return;
        }
        if (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}

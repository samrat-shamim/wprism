<?php

declare(strict_types=1);

namespace Duo\Tests\Adapter;

use Duo\AdapterCertification;
use Duo\AdapterLibrary;
use Duo\ManifestDispositions;
use Duo\Orchestrator\ContractAttestation;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/agent/src/Policy/AdapterLibrary.php';
require_once dirname(__DIR__, 2) . '/agent/src/Policy/ManifestDispositions.php';
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

    public function testCurrentFlatLibraryIncludesRedirectionAndSortedPackages(): void
    {
        $root = dirname(__DIR__, 2) . '/manifests';
        $library = AdapterLibrary::fromDirectory($root);
        $names = array_map(static fn($package): string => $package->name(), $library->packages());

        $this->assertCount(17, $names);
        $sorted = $names;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $names);

        $redirection = $library->package('redirection');
        $this->assertNotNull($redirection);
        $this->assertNull($redirection->interpreterPath());
        $this->assertSame(
            realpath($root . '/providers/redirection-state.php'),
            $redirection->providerPath('redirection-state')
        );
        $this->assertSame([
            realpath($root . '/dispositions/redirection.json'),
            realpath($root . '/providers/redirection-state.php'),
            realpath($root . '/redirection.json'),
        ], $redirection->shippablePaths());
        $this->assertContains(realpath($root . '/providers'), $library->scanAnchors());
        $this->assertContains(realpath($root . '/providers/redirection-state.php'), $library->scanFiles());
        $this->assertSame($root . '/capabilities/adapter-revocations.json', $library->revocationsPath());
        $this->assertNotContains($library->revocationsPath(), $library->scanFiles());
    }

    public function testTrustConsumersUseObjectPathsWithoutMovingCurrentValues(): void
    {
        $root = dirname(__DIR__, 2) . '/manifests';
        $library = AdapterLibrary::fromDirectory($root);
        $legacyDispositions = ManifestDispositions::load($root);

        $this->assertNotNull($legacyDispositions);
        $this->assertSame(
            $legacyDispositions->sha256(),
            ManifestDispositions::load_library($library)->sha256()
        );
        $this->assertSame(
            ManifestDispositions::platform_boundary($root),
            ManifestDispositions::platform_boundary_library($library)
        );
        $this->assertSame(
            AdapterCertification::hasAuthorities($root),
            AdapterCertification::hasAuthorities($library)
        );
        $this->assertSame(
            AdapterCertification::revocation_channel($root),
            AdapterCertification::revocation_channel($library)
        );
        $this->assertSame(
            ContractAttestation::currentPlatformDigest($root),
            ContractAttestation::currentPlatformDigest($library)
        );
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

        $library = AdapterLibrary::fromDirectory($root);
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
        AdapterLibrary::fromDirectory($root);
    }

    public function testUndeclaredRuntimePhpRefuses(): void
    {
        $root = $this->fixture();
        file_put_contents($root . '/providers/extra.php', "<?php\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('undeclared=[extra]');
        AdapterLibrary::fromDirectory($root);
    }

    public function testManifestBasenameMustMatchDeclaredName(): void
    {
        $root = $this->fixture();
        $manifest = json_decode((string) file_get_contents($root . '/alpha.json'), true);
        $manifest['name'] = 'different';
        file_put_contents($root . '/alpha.json', json_encode($manifest, JSON_PRETTY_PRINT) . "\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('basename alpha disagrees with its declared name different');
        AdapterLibrary::fromDirectory($root);
    }

    public function testDuplicateDeclaredNameRefusesBeforeASecondPackageCanBeConstructed(): void
    {
        $root = $this->fixture();
        copy($root . '/alpha.json', $root . '/beta.json');
        copy($root . '/dispositions/alpha.json', $root . '/dispositions/beta.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate adapter name alpha');
        AdapterLibrary::fromDirectory($root);
    }

    public function testRuntimePathEscapeInManifestRefuses(): void
    {
        $root = $this->fixture();
        $manifest = json_decode((string) file_get_contents($root . '/alpha.json'), true);
        $manifest['providers'][0]['id'] = '../outside';
        file_put_contents($root . '/alpha.json', json_encode($manifest, JSON_PRETTY_PRINT) . "\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('provider id is not a canonical lowercase ASCII slug');
        AdapterLibrary::fromDirectory($root);
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
        AdapterLibrary::fromDirectory($root);
    }

    public function testInvalidAndSymlinkedRootsRefuse(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('root is not a readable directory');
        AdapterLibrary::fromDirectory($this->scratch('missing') . '/absent');
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

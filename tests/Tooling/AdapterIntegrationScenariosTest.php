<?php

declare(strict_types=1);

namespace Duo\Tests\Tooling;

use Duo\Tooling\AdapterIntegrationScenarios;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/tools/src/AdapterIntegrationScenarios.php';

final class AdapterIntegrationScenariosTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir() . '/duo-integration-scenarios-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch . '/adapter-packages', 0777, true));
        self::assertTrue(mkdir($this->scratch . '/integration-scenarios', 0777, true));
    }

    protected function tearDown(): void
    {
        self::removeTree($this->scratch);
    }

    public function testRepositoryScenariosBuildAReverseParticipantIndexAndExecutableGates(): void
    {
        $catalog = AdapterIntegrationScenarios::discover(dirname(__DIR__, 2));

        self::assertSame(
            ['polylang-tec-rewrite-coinstall', 'woocommerce-rewrite-coinstall'],
            AdapterIntegrationScenarios::forParticipant($catalog, 'polylang')
        );
        self::assertSame(
            ['polylang', 'the-events-calendar'],
            $catalog['scenarios']['polylang-tec-rewrite-coinstall']['participants']
        );
        self::assertSame(
            ['bash', 'integration-scenarios/polylang-tec-rewrite-coinstall/tests/live/regress_polylang_tec_rewrite_coinstall.sh'],
            $catalog['scenarios']['polylang-tec-rewrite-coinstall']['gates'][0]['command']
        );
    }

    public function testUnknownParticipantSlugRefusesInsteadOfDisappearingFromTheReverseIndex(): void
    {
        $this->writePackage('alpha');
        $this->writeScenario('alpha-beta', ['alpha', 'missing-package']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("scenario participant 'missing-package'");
        AdapterIntegrationScenarios::discover($this->scratch);
    }

    public function testParticipantManifestIdentityIsValidatedByTheGlobalCatalog(): void
    {
        $this->writePackage('alpha');
        $this->writePackage('beta');
        self::assertNotFalse(file_put_contents(
            $this->scratch . '/adapter-packages/beta/package/manifest.json',
            json_encode(['name' => 'not-beta'], JSON_THROW_ON_ERROR) . "\n"
        ));
        $this->writeScenario('alpha-beta', ['alpha', 'beta']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("participant 'beta' manifest identity does not agree with its slug");
        AdapterIntegrationScenarios::discover($this->scratch);
    }

    public function testMetadataDiscoveryDoesNotCoupleScopeToSiblingPackageBytes(): void
    {
        $this->writePackage('alpha');
        $this->writePackage('beta');
        self::assertNotFalse(file_put_contents(
            $this->scratch . '/adapter-packages/beta/package/manifest.json',
            "not json\n"
        ));
        $this->writeScenario('alpha-beta', ['alpha', 'beta']);

        $catalog = AdapterIntegrationScenarios::discover($this->scratch, false);

        self::assertSame(['alpha-beta'], AdapterIntegrationScenarios::forParticipant($catalog, 'alpha'));
    }

    public function testDuplicateOrUnsortedParticipantsRefuse(): void
    {
        $this->writePackage('alpha');
        $this->writePackage('beta');
        $this->writeScenario('order', ['beta', 'alpha']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("scenario 'order' participants must be sorted");
        AdapterIntegrationScenarios::discover($this->scratch);
    }

    public function testUnknownScenarioTestEntryRefusesInsteadOfBecomingUngatedEvidence(): void
    {
        $this->writePackage('alpha');
        $this->writePackage('beta');
        $this->writeScenario('alpha-beta', ['alpha', 'beta'], 'helper.sh');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unrecognized tests/live entry: helper.sh');
        AdapterIntegrationScenarios::discover($this->scratch);
    }

    private function writePackage(string $slug): void
    {
        $directory = $this->scratch . '/adapter-packages/' . $slug . '/package';
        self::assertTrue(mkdir($directory, 0777, true));
        self::assertNotFalse(file_put_contents(
            $directory . '/manifest.json',
            json_encode(['name' => $slug], JSON_THROW_ON_ERROR) . "\n"
        ));
    }

    /** @param list<string> $participants */
    private function writeScenario(string $name, array $participants, string $test = 'regress_fixture.sh'): void
    {
        $directory = $this->scratch . '/integration-scenarios/' . $name;
        self::assertTrue(mkdir($directory . '/tests/live', 0777, true));
        self::assertNotFalse(file_put_contents(
            $directory . '/scenario.json',
            json_encode(
                ['format' => AdapterIntegrationScenarios::FORMAT, 'participants' => $participants],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . "\n"
        ));
        self::assertNotFalse(file_put_contents($directory . '/tests/live/' . $test, "#!/usr/bin/env bash\n"));
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
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

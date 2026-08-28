<?php

declare(strict_types=1);

namespace Duo\Tests\Tooling;

use Duo\Tooling\AdapterPackageTestDiscovery;
use Duo\Tooling\AdapterPackageTestRunner;
use Duo\Tooling\AdapterPackageTestsCommand;
use Duo\Tooling\AdapterPackageValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/tools/src/AdapterPackageTestDiscovery.php';
require_once dirname(__DIR__, 2) . '/tools/src/AdapterPackageTestRunner.php';
require_once dirname(__DIR__, 2) . '/tools/src/AdapterPackageTestsCommand.php';
require_once dirname(__DIR__, 2) . '/tools/src/AdapterPackageValidator.php';

final class AdapterPackageTestsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/duo-adapter-package-tests-' . bin2hex(random_bytes(8));
        self::makeDirectory($this->root . '/adapter-packages');
        $canonical = realpath($this->root);
        self::assertNotFalse($canonical);
        $this->root = $canonical;
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    public function testDiscoveryIsRecursiveDeterministicAndConfinedToTestsClass(): void
    {
        $package = $this->package('acf');
        self::write($package . '/tests/offline/zeta/regress_z.sh', "#!/usr/bin/env bash\n");
        self::write($package . '/tests/offline/regress_b.php', '<?php');
        self::write($package . '/tests/offline/alpha/regress_a.sh', "#!/usr/bin/env bash\n");
        self::write($package . '/tests/live/regress_live.php', '<?php');
        self::write($package . '/tests/certify/version-matrix.sh', "seed_acf_content() { :; }\n");
        self::write($package . '/tests/conformance/entry.json', "{}\n");
        self::write($package . '/tests/conformance/seed.sh', "seed_acf_content\n");
        self::write($package . '/fixtures/regress_fixture.php', '<?php throw new Exception;');
        self::write($package . '/evidence/regress_evidence.sh', 'exit 99');
        self::write($package . '/package/regress_payload.php', '<?php exit(99);');

        $found = AdapterPackageTestDiscovery::discover($this->root, 'acf');

        self::assertSame('offline', $found['class']);
        self::assertSame([
            'adapter-packages/acf/tests/offline/alpha/regress_a.sh',
            'adapter-packages/acf/tests/offline/regress_b.php',
            'adapter-packages/acf/tests/offline/zeta/regress_z.sh',
        ], array_column($found['tests'], 'path'));
        self::assertSame(['bash', 'php', 'bash'], array_column($found['tests'], 'runtime'));
    }

    public function testCurrentAcfCapsuleValidatesWithoutReadingSiblingPackages(): void
    {
        $result = AdapterPackageValidator::validate(dirname(__DIR__, 2), 'acf');

        self::assertSame(AdapterPackageValidator::FORMAT, $result['format']);
        self::assertSame('acf', $result['adapter']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $result['digest']);
        self::assertSame(['conformance-acf', 'exact-artifact-version-matrix'], $result['evidence_tests']);
        self::assertContains('closed-library', $result['checks']);
        self::assertContains('adapter-identity', $result['checks']);
        self::assertNotEmpty(array_filter(
            $result['checks'],
            static fn(string $check): bool => str_starts_with($check, 'dependency-boundary:')
        ));
        self::assertContains('runtime-sdk:' . AdapterPackageValidator::RUNTIME_SDK_FORMAT, $result['checks']);
        self::assertContains('artifact-evidence', $result['checks']);
        self::assertContains('production-readiness', $result['checks']);
        self::assertContains('premise-evidence:2', $result['checks']);
        self::assertContains('version-matrix-premises', $result['checks']);
        self::assertContains('evidence-wiring:2', $result['checks']);
    }

    public function testRuntimeSdkIsVersionedAndInventoriesEveryCurrentLegitimateDependency(): void
    {
        self::assertSame([
            'format' => 'duo-adapter-runtime-sdk/v1',
            'symbols' => [
                'Duo\\CacheInvalidationTransaction',
                'Duo\\Canon',
                'Duo\\IdentityTokenCodec',
                'Duo\\Ledger',
                'Duo\\ManifestProviderRuntime',
                'Duo\\NativeActions',
                'Duo\\NativeRewriteEffects',
                'Duo\\PlainData',
                'Duo\\Policy',
                'Duo\\ProviderSdk',
                'Duo\\Providers',
                'Duo\\Secrets',
                'Duo\\SidebarState',
                'Duo\\Tokens',
                'Duo\\WpCliChildProcess',
            ],
        ], AdapterPackageValidator::runtimeSdk());
    }

    public function testValidatorRejectsRuntimeDependencyOutsideTheVersionedSdk(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/package/runtime/interpreters/acf.php';
        $source = (string) file_get_contents($path);
        self::write(
            $path,
            str_replace(
                "namespace Duo\\Interpreters;\n",
                "namespace Duo\\Interpreters;\n\nuse Duo\\RepositoryCompiler;\n",
                $source
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "depends on non-SDK Duo symbol 'Duo\\RepositoryCompiler' at package/runtime/interpreters/acf.php"
        );
        AdapterPackageValidator::validate($root, 'acf');
    }

    /** @return iterable<string,array{0:string}> */
    public static function hiddenRuntimeSdkDependencies(): iterable
    {
        yield 'root namespace unqualified name' => [
            "\nnamespace Duo;\nRepositoryCompiler::compile();\n",
        ];
        yield 'root namespace constructor' => [
            "\nnamespace Duo;\nfunction hiddenSdkConstructor(): void { new RepositoryCompiler(); }\n",
        ];
        yield 'root namespace return type' => [
            "\nnamespace Duo;\nfunction hiddenSdkReturnType(): RepositoryCompiler {}\n",
        ];
        yield 'namespace-relative name' => [
            "\nnamespace Duo;\nnamespace\\RepositoryCompiler::compile();\n",
        ];
        yield 'deeper Duo namespace unqualified name' => [
            "\nnamespace Duo\\Repository;\nCompiledArtifactReader::load();\n",
        ];
        yield 'dynamic class string' => [
            "\n\$class = 'Duo\\\\RepositoryCompiler';\n\$class::compile();\n",
        ];
        yield 'concatenated dynamic class string' => [
            "\n\$class = 'Duo' . '\\\\RepositoryCompiler';\n\$class::compile();\n",
        ];
        yield 'case-variant fully-qualified name' => [
            "\n\\duo\\RepositoryCompiler::compile();\n",
        ];
    }

    #[DataProvider('hiddenRuntimeSdkDependencies')]
    public function testValidatorRejectsSdkDependenciesHiddenByPhpNameForms(string $mutation): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/package/runtime/interpreters/acf.php';
        self::write($path, (string) file_get_contents($path) . $mutation);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('depends on non-SDK Duo symbol');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsCapsuleClassDeclaredInAnEngineInternalNamespace(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/package/runtime/interpreters/acf.php';
        self::write(
            $path,
            str_replace(
                'namespace Duo\\Interpreters;',
                'namespace Duo\\Repository;',
                (string) file_get_contents($path)
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("outside owned namespace 'Duo\\Interpreters'");
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorAcceptsDynamicReferencesToSdkAndCapsuleOwnedClasses(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/package/runtime/interpreters/acf.php';
        self::write(
            $path,
            (string) file_get_contents($path)
                . "\nis_callable(['\\\\Duo\\\\Policy', 'load']);\n"
                . "is_callable(['\\\\Duo\\\\Interpreters\\\\Acf', 'tokens']);\n"
                . "namespace Duo;\nuse Duo\\Policy as SdkPolicy;\n"
                . "function sdkReturnType(): SdkPolicy {}\n"
        );

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertSame('acf', $result['adapter']);
    }

    /** @return iterable<string,array{0:string}> */
    public static function unresolvedDuoImports(): iterable
    {
        yield 'grouped import' => ['use Duo\\{Policy, RepositoryCompiler};'];
        yield 'root alias' => ['use Duo as Engine;'];
    }

    #[DataProvider('unresolvedDuoImports')]
    public function testValidatorRejectsSdkImportSyntaxThatCouldHideAnExactSymbol(string $import): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/package/runtime/interpreters/acf.php';
        $source = (string) file_get_contents($path);
        self::write(
            $path,
            str_replace("namespace Duo\\Interpreters;\n", "namespace Duo\\Interpreters;\n\n$import\n", $source)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('uses an unresolved grouped or root Duo import');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testSdkScannerIgnoresDependencyShapedCommentsAndRuntimeLineCounts(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/package/runtime/interpreters/acf.php';
        self::write(
            $path,
            (string) file_get_contents($path) . "\n// use Duo\\{RepositoryCompiler}; is documentation, not an import.\n"
        );

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertSame('acf', $result['adapter']);
        self::assertFileDoesNotExist($root . '/tools/adapter-executable-inventory.json');
    }

    public function testValidatorRejectsMalformedPackageArtifactEvidence(): void
    {
        $root = $this->validatorFixture();
        self::write($root . '/adapter-packages/acf/evidence/artifacts.lock.json', "{}\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Artifact fragment must contain exactly plugins and themes');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsArtifactSubjectsNotOwnedByTheManifest(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/evidence/artifacts.lock.json';
        $artifacts = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($artifacts);
        $artifacts['plugins']['woocommerce'] = $artifacts['plugins']['advanced-custom-fields'];
        self::write(
            $path,
            json_encode($artifacts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("must own exactly its manifest plugin 'advanced-custom-fields'");
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsIncompletePackageReadinessSemantics(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/evidence/production-readiness.json';
        $record = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($record);
        unset($record['not_applicable']['derived-state']);
        self::write($path, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not account for every scenario family');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsReadinessEvidenceFromASiblingCapsule(): void
    {
        $root = $this->validatorFixture();
        $evidence = 'adapter-packages/woocommerce/tests/offline/regress_sibling.php';
        self::write($root . '/' . $evidence, "<?php\n");
        self::replaceReadinessEvidence(
            $root,
            'sandbox/tests/offline/apply/regress_fatal_mutations.php',
            $evidence
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('readiness evidence cites a sibling adapter capsule');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsReadinessEvidenceFromAnUndeclaredIntegrationScenario(): void
    {
        $root = $this->validatorFixture();
        $evidence = self::scenarioFixture(
            $root,
            'polylang-tec-rewrite-coinstall',
            ['polylang', 'the-events-calendar']
        );
        self::replaceReadinessEvidence(
            $root,
            'sandbox/tests/offline/apply/regress_fatal_mutations.php',
            $evidence
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("cites undeclared integration scenario 'polylang-tec-rewrite-coinstall'");
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorAcceptsReadinessEvidenceFromADeclaredIntegrationScenario(): void
    {
        $root = $this->validatorFixture();
        $evidence = self::scenarioFixture($root, 'acf-woocommerce-coinstall', ['acf', 'woocommerce']);
        self::replaceReadinessEvidence(
            $root,
            'sandbox/tests/offline/apply/regress_fatal_mutations.php',
            $evidence
        );

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertSame('acf', $result['adapter']);
        self::assertContains('production-readiness', $result['checks']);
    }

    public function testDeclaredScenarioDoesNotMakePackageValidationReadAParticipantSibling(): void
    {
        $root = $this->validatorFixture();
        $evidence = self::scenarioFixture($root, 'acf-woocommerce-coinstall', ['acf', 'woocommerce']);
        self::replaceReadinessEvidence(
            $root,
            'sandbox/tests/offline/apply/regress_fatal_mutations.php',
            $evidence
        );
        self::write($root . '/adapter-packages/woocommerce/package/manifest.json', "{not-json\n");

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertSame('acf', $result['adapter']);
        self::assertContains('production-readiness', $result['checks']);
    }

    public function testValidatorRejectsExternalEvidenceFromAnUndeclaredIntegrationScenario(): void
    {
        $root = $this->validatorFixture();
        $evidence = self::scenarioFixture(
            $root,
            'polylang-tec-rewrite-coinstall',
            ['polylang', 'the-events-calendar']
        );
        self::addExternalEvidence($root, 'regress-polylang-tec-rewrite-coinstall', $evidence);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("cites undeclared integration scenario 'polylang-tec-rewrite-coinstall'");
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testDeclaredExternalScenarioDoesNotReadAParticipantSiblingManifest(): void
    {
        $root = $this->validatorFixture();
        $evidence = self::scenarioFixture($root, 'acf-woocommerce-coinstall', ['acf', 'woocommerce']);
        self::addExternalEvidence($root, 'regress-acf-woocommerce-coinstall', $evidence);
        self::write($root . '/adapter-packages/woocommerce/package/manifest.json', "{not-json\n");

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertSame('acf', $result['adapter']);
        self::assertContains('regress-acf-woocommerce-coinstall', $result['evidence_tests']);
    }

    public function testExternalScenarioCannotAliasReservedLocalEvidence(): void
    {
        $root = $this->validatorFixture();
        $evidence = self::scenarioFixture($root, 'acf-woocommerce-coinstall', ['acf', 'woocommerce']);
        self::addExternalEvidence($root, 'exact-artifact-version-matrix', $evidence);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must equal its scenario gate basename');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testExternalScenarioEvidenceKeyIsDerivedFromItsGateBasename(): void
    {
        $root = $this->validatorFixture();
        $evidence = self::scenarioFixture($root, 'acf-woocommerce-coinstall', ['acf', 'woocommerce']);
        self::addExternalEvidence($root, 'regress-invented-alias', $evidence);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "key 'regress-invented-alias' must equal its scenario gate basename 'regress-acf-woocommerce-coinstall'"
        );
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testExternalScenarioCannotCollideWithAPackageLocalSuite(): void
    {
        $root = $this->validatorFixture();
        $evidence = self::scenarioFixture($root, 'acf-woocommerce-coinstall', ['acf', 'woocommerce']);
        self::write(
            $root . '/adapter-packages/acf/tests/offline/regress_acf_woocommerce_coinstall.php',
            "<?php\n"
        );
        self::addExternalEvidence($root, 'regress-acf-woocommerce-coinstall', $evidence);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("external evidence collides with local test 'regress-acf-woocommerce-coinstall'");
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsReadinessEvidenceOutsideClosedSharedRoots(): void
    {
        $root = $this->validatorFixture();
        $evidence = 'docs/adapter-evidence.php';
        self::write($root . '/' . $evidence, "<?php\n");
        self::replaceReadinessEvidence(
            $root,
            'sandbox/tests/offline/apply/regress_fatal_mutations.php',
            $evidence
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('readiness evidence is outside the closed shared evidence roots');
        AdapterPackageValidator::validate($root, 'acf');
    }

    /** @return iterable<string,array{0:string,1:string}> */
    public static function processGlobalLibrarySelections(): iterable
    {
        yield 'runtime getenv' => [
            'package/runtime/interpreters/acf.php',
            "\ngetenv('DUO_MANIFESTS_DIR');\n",
        ];
        yield 'test putenv' => [
            'tests/offline/regress_global_library.php',
            "<?php putenv('DUO_MANIFESTS_DIR=/tmp/legacy');\n",
        ];
        yield 'shell assignment' => [
            'tests/live/regress_global_library.sh',
            "#!/usr/bin/env bash\nDUO_MANIFESTS_DIR=/tmp/legacy\n",
        ];
        yield 'test text outside a negative assertion' => [
            'tests/offline/regress_global_library_text.php',
            "<?php \$legacySelector = 'DUO_MANIFESTS_DIR';\n",
        ];
        yield 'selection beside a negative assertion' => [
            'tests/offline/regress_global_library_mixed.php',
            "<?php putenv('DUO_MANIFESTS_DIR=/tmp/legacy'); assert(!str_contains('', 'DUO_MANIFESTS_DIR'));\n",
        ];
        yield 'concatenated selector spelling' => [
            'tests/offline/regress_global_library_concatenated.php',
            "<?php getenv('DUO_' . 'MANIFESTS_DIR');\n",
        ];
    }

    #[DataProvider('processGlobalLibrarySelections')]
    public function testValidatorRejectsProcessGlobalLibrarySelection(string $relative, string $bytes): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/' . $relative;
        if (is_file($path)) {
            $bytes = (string) file_get_contents($path) . $bytes;
        }
        self::write($path, $bytes);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("selects a manifest library through DUO_MANIFESTS_DIR at $relative");
        AdapterPackageValidator::validate($root, 'acf');
    }

    /** @return iterable<string,array{0:string,1:string}> */
    public static function staleRelativeAgentDependencies(): iterable
    {
        yield 'runtime PHP' => [
            'package/runtime/interpreters/acf.php',
            "\nrequire_once __DIR__ . '/../../agent/src/Kernel/Canon.php';\n",
        ];
        yield 'offline PHP' => [
            'tests/offline/regress_stale_agent_path.php',
            "<?php require_once __DIR__ . '/../../agent/src/Kernel/Canon.php';\n",
        ];
        yield 'live shell' => [
            'tests/live/regress_stale_agent_path.sh',
            "#!/usr/bin/env bash\nSOURCE=../../agent/src/Kernel/Canon.php\n",
        ];
    }

    #[DataProvider('staleRelativeAgentDependencies')]
    public function testValidatorRejectsStaleRelativeAgentDependency(string $relative, string $bytes): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/' . $relative;
        if (is_file($path)) {
            $bytes = (string) file_get_contents($path) . $bytes;
        }
        self::write($path, $bytes);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("has a legacy relative agent dependency at $relative");
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorAllowsNegativeAssertionsAndCorrectCapsuleRelativeTestDependencies(): void
    {
        $root = $this->validatorFixture();
        self::write(
            $root . '/adapter-packages/acf/tests/offline/regress_dependency_boundary.php',
            <<<'PHP'
<?php
$source = '';
assert(!str_contains($source, 'DUO_MANIFESTS_DIR'));
assert(!str_contains($source, '../../agent/src'));
require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
PHP
        );

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertNotEmpty(array_filter(
            $result['checks'],
            static fn(string $check): bool => str_starts_with($check, 'dependency-boundary:')
        ));
    }

    /** @return iterable<string,array{0:string,1:string}> */
    public static function siblingCapsuleSourceReferences(): iterable
    {
        yield 'runtime PHP string' => [
            'package/runtime/interpreters/acf.php',
            "\nfile_get_contents(__DIR__ . '/../../../../adapter-packages/woocommerce/package/manifest.json');\n",
        ];
        yield 'runtime cwd-relative sibling' => [
            'package/runtime/interpreters/acf.php',
            "\nfile_get_contents('../woocommerce/package/manifest.json');\n",
        ];
        yield 'offline PHP string' => [
            'tests/offline/regress_sibling_package.php',
            "<?php file_get_contents(__DIR__ . '/../../../../adapter-packages/woocommerce/package/manifest.json');\n",
        ];
        yield 'offline cwd-relative sibling' => [
            'tests/offline/regress_relative_sibling.php',
            "<?php file_get_contents('../woocommerce/package/manifest.json');\n",
        ];
        yield 'offline file-relative sibling' => [
            'tests/offline/regress_file_relative_sibling.php',
            "<?php file_get_contents(__DIR__ . '/../../../woocommerce/package/manifest.json');\n",
        ];
        yield 'live shell command' => [
            'tests/live/regress_sibling_package.sh',
            "#!/usr/bin/env bash\nphp adapter-packages/woocommerce/tests/offline/regress_woocommerce_contract.php\n",
        ];
        yield 'fixture PHP string' => [
            'fixtures/sibling_package.php',
            "<?php file_get_contents(__DIR__ . '/../../../adapter-packages/woocommerce/package/manifest.json');\n",
        ];
        yield 'fixture cwd-relative sibling' => [
            'fixtures/cwd_relative_sibling.php',
            "<?php file_get_contents('../woocommerce/package/manifest.json');\n",
        ];
        yield 'fixture file-relative sibling' => [
            'fixtures/relative_sibling.php',
            "<?php file_get_contents(__DIR__ . '/../../woocommerce/package/manifest.json');\n",
        ];
    }

    #[DataProvider('siblingCapsuleSourceReferences')]
    public function testValidatorRejectsSiblingCapsuleReferencesInPackageSources(
        string $relative,
        string $bytes
    ): void {
        $root = $this->validatorFixture();
        self::makeDirectory($root . '/adapter-packages/woocommerce');
        $path = $root . '/adapter-packages/acf/' . $relative;
        if (is_file($path)) {
            $bytes = (string) file_get_contents($path) . $bytes;
        }
        self::write($path, $bytes);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "references sibling adapter package 'woocommerce' at $relative"
        );
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsAComputedAdapterPackagesRoot(): void
    {
        $root = $this->validatorFixture();
        self::write(
            $root . '/adapter-packages/acf/tests/offline/regress_computed_package.php',
            "<?php file_get_contents(__DIR__ . '/../../../../adapter-packages/' . 'woocommerce/package/manifest.json');\n"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has an unresolved adapter-packages path');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorIgnoresSiblingPackageReferencesInComments(): void
    {
        $root = $this->validatorFixture();
        self::write(
            $root . '/adapter-packages/acf/tests/offline/regress_package_comment.php',
            "<?php\n// adapter-packages/woocommerce is historical context, not a dependency.\n"
        );
        self::write(
            $root . '/adapter-packages/acf/tests/live/regress_package_comment.sh',
            "#!/usr/bin/env bash\n# adapter-packages/woocommerce is historical context, not a dependency.\n"
        );

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertSame('acf', $result['adapter']);
    }

    public function testValidatorRequiresAPackagePremiseContractWhenItsTestsUsePremiseHelpers(): void
    {
        $root = $this->validatorFixture();
        self::assertTrue(unlink(
            $root . '/adapter-packages/acf/evidence/target-observation-premises.tsv'
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('uses target-observation premise helpers but owns no target-observation-premises.tsv');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsAStalePackagePremiseAssertion(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/evidence/target-observation-premises.tsv';
        self::write(
            $path,
            str_replace(
                'require_observed_nonempty "conf2 ACF runtime observation"',
                'require_observed_nonempty "stale ACF runtime observation"',
                (string) file_get_contents($path)
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source is missing premise');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsADeletedPackagePremiseRow(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/evidence/target-observation-premises.tsv';
        self::write(
            $path,
            str_replace(
                "observation\ttests/conformance/seed.sh\trequire_observed_nonempty \"conf1 ACF seed output\"\n",
                '',
                (string) file_get_contents($path)
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expected-count mismatch (2/0 declared, 1/0 found)');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsAPackagePremisePathEscape(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/evidence/target-observation-premises.tsv';
        self::write(
            $path,
            str_replace(
                'tests/conformance/check.sh',
                '../outside.sh',
                (string) file_get_contents($path)
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("names invalid premise source '../outside.sh'");
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsAnUnguardedVersionMatrixObservation(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/tests/certify/version-matrix.sh';
        self::write(
            $path,
            str_replace(
                'require_fixture_values INSTALLED_2',
                '# removed fixture premise',
                (string) file_get_contents($path)
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INSTALLED_2 assignment/premise mismatch (1/0)');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorDoesNotInspectSiblingCapsules(): void
    {
        $root = $this->validatorFixture();
        $baseline = AdapterPackageValidator::validate($root, 'acf');
        self::write(
            $root . '/adapter-packages/broken/package/runtime/providers/broken.php',
            "<?php getenv('DUO_MANIFESTS_DIR'); this is not PHP;\n"
        );
        self::write($root . '/adapter-packages/broken/package/manifest.json', "{not-json\n");

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertSame($baseline, $result);
    }

    public function testValidatorStillInspectsTheSharedPlatformContract(): void
    {
        $root = $this->validatorFixture();
        self::assertTrue(unlink($root . '/platform/adapter-library/capabilities/adapter-authorities.json'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('adapter authorities is not a readable regular file');
        AdapterPackageValidator::validate($root, 'acf');
    }

    /** @return iterable<string,array{0:string}> */
    public static function invalidSlugs(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['ACF'];
        yield 'underscore' => ['bad_slug'];
        yield 'traversal' => ['../acf'];
        yield 'slash' => ['vendor/acf'];
    }

    #[DataProvider('invalidSlugs')]
    public function testInvalidSlugFailsClosed(string $slug): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('slug is not canonical');
        AdapterPackageTestDiscovery::discover($this->root, $slug);
    }

    public function testMissingPackageFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("package 'acf' is missing");
        AdapterPackageTestDiscovery::discover($this->root, 'acf');
    }

    public function testMissingOfflineDirectoryFailsClosed(): void
    {
        $package = $this->package('acf', false);
        self::write($package . '/tests/live/regress_live.php', '<?php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no tests/offline directory');
        AdapterPackageTestDiscovery::discover($this->root, 'acf');
    }

    public function testEmptyOfflineDirectoryFailsClosed(): void
    {
        $this->package('acf');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no class-named *.php/.sh suites');
        AdapterPackageTestDiscovery::discover($this->root, 'acf');
    }

    public function testUnknownTestClassEntryFailsClosed(): void
    {
        $package = $this->package('acf');
        self::write($package . '/tests/offline/regress_ok.php', '<?php');
        self::write($package . '/tests/fixtures/data.json', '{}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown adapter test class entry');
        AdapterPackageTestDiscovery::discover($this->root, 'acf');
    }

    /** @return iterable<string,array{0:string}> */
    public static function unknownTestFiles(): iterable
    {
        yield 'ordinary fixture' => ['data.json'];
        yield 'wrong prefix' => ['test_product.php'];
        yield 'wrong extension' => ['regress_product.txt'];
        yield 'hidden file' => ['.regress_hidden.php'];
        yield 'empty regression name' => ['regress_.php'];
    }

    #[DataProvider('unknownTestFiles')]
    public function testUnknownFileInAClassFailsClosed(string $filename): void
    {
        $package = $this->package('acf');
        self::write($package . '/tests/offline/regress_ok.php', '<?php');
        self::write($package . '/tests/offline/' . $filename, 'unknown');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown adapter test file');
        AdapterPackageTestDiscovery::discover($this->root, 'acf');
    }

    public function testUnknownFileInAnotherClassStillFailsThePackageBoundary(): void
    {
        $package = $this->package('acf');
        self::write($package . '/tests/offline/regress_ok.php', '<?php');
        self::write($package . '/tests/live/notes.md', 'not a suite');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown adapter test file');
        AdapterPackageTestDiscovery::discover($this->root, 'acf');
    }

    public function testSymlinkedTestFileFailsClosed(): void
    {
        $package = $this->package('acf');
        self::assertTrue(symlink('/etc/hosts', $package . '/tests/offline/regress_escape.php'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not be a symbolic link');
        AdapterPackageTestDiscovery::discover($this->root, 'acf');
    }

    public function testSymlinkedPackageCannotEscapeTheRepository(): void
    {
        $outside = $this->root . '-outside';
        self::makeDirectory($outside . '/tests/offline');
        self::write($outside . '/tests/offline/regress_escape.php', '<?php');
        self::assertTrue(symlink($outside, $this->root . '/adapter-packages/acf'));
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('is not an ordinary directory');
            AdapterPackageTestDiscovery::discover($this->root, 'acf');
        } finally {
            self::removeTree($outside);
        }
    }

    public function testSpecialTestNodeFailsClosed(): void
    {
        if (!function_exists('posix_mkfifo')) {
            self::markTestSkipped('posix_mkfifo is unavailable');
        }
        $package = $this->package('acf');
        self::assertTrue(posix_mkfifo($package . '/tests/offline/regress_fifo.php', 0600));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not an ordinary file or directory');
        AdapterPackageTestDiscovery::discover($this->root, 'acf');
    }

    public function testRunnerExecutesEveryOfflineTestAndAggregatesOutputAndExits(): void
    {
        [$root] = $this->runnerFixture([
            'regress_01_pass.php' => '<?php fwrite(STDOUT, "php-out\\n"); fwrite(STDERR, "php-err\\n");',
            'regress_02_fail.sh' => "#!/usr/bin/env bash\nprintf 'bash-out\\n'\nprintf 'bash-err\\n' >&2\nexit 3\n",
            'nested/regress_03_after.php' => '<?php fwrite(STDOUT, "after-out\\n");',
        ]);

        $run = AdapterPackageTestRunner::run($root, 'acf');

        self::assertSame(AdapterPackageTestRunner::FORMAT, $run['format']);
        self::assertSame('failed', $run['status']);
        self::assertSame(1, $run['exit_code']);
        self::assertSame([
            'adapter-packages/acf/tests/offline/nested/regress_03_after.php',
            'adapter-packages/acf/tests/offline/regress_01_pass.php',
            'adapter-packages/acf/tests/offline/regress_02_fail.sh',
        ], array_column($run['tests'], 'path'));
        self::assertSame([0, 0, 3], array_column($run['tests'], 'exit_code'));
        self::assertSame(["after-out\n", "php-out\n", "bash-out\n"], array_column($run['tests'], 'stdout'));
        self::assertSame(['', "php-err\n", "bash-err\n"], array_column($run['tests'], 'stderr'));
    }

    public function testListAndJsonAreDryRuns(): void
    {
        $package = $this->package('acf');
        $marker = $this->root . '/executed';
        self::write(
            $package . '/tests/offline/regress_marker.php',
            '<?php file_put_contents(' . var_export($marker, true) . ', "ran");'
        );

        $listed = self::invoke([
            '--repo=' . $this->root,
            '--adapter=acf',
            '--list',
        ]);
        self::assertSame(0, $listed['status'], $listed['stderr']);
        self::assertStringContainsString('php adapter-packages/acf/tests/offline/regress_marker.php', $listed['stdout']);
        self::assertFileDoesNotExist($marker);

        $json = self::invoke([
            '--repo=' . $this->root,
            '--adapter=acf',
            '--json',
        ]);
        self::assertSame(0, $json['status'], $json['stderr']);
        $plan = json_decode($json['stdout'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($plan);
        self::assertSame(AdapterPackageTestsCommand::PLAN_FORMAT, $plan['format']);
        self::assertSame('acf', $plan['adapter']);
        self::assertSame([
            'path' => 'adapter-packages/acf/tests/offline/regress_marker.php',
            'runtime' => 'php',
            'command' => ['php', 'adapter-packages/acf/tests/offline/regress_marker.php'],
        ], $plan['tests'][0]);
        self::assertFileDoesNotExist($marker);
    }

    public function testCliExecutionReturnsAggregateFailureAfterRunningLaterTests(): void
    {
        [$root] = $this->runnerFixture([
            'regress_01_fail.sh' => "exit 9\n",
            'regress_02_after.php' => '<?php echo "after\\n";',
        ]);

        $result = self::invoke(['--repo=' . $root, '--adapter=acf']);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('after', $result['stdout']);
        self::assertStringContainsString('1 passed, 1 failed', $result['stdout']);
        self::assertStringContainsString('exited 9', $result['stderr']);
    }

    public function testRunnerCannotReplacePackageValidationWithOneGreenTest(): void
    {
        $root = $this->validatorFixture();
        $package = $root . '/adapter-packages/acf';
        self::removeTree($package . '/tests/offline');
        self::write($package . '/tests/offline/regress_green.php', '<?php exit(0);');
        self::assertTrue(unlink($package . '/package/manifest.json'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('manifest.json');
        AdapterPackageTestRunner::run($root, 'acf');
    }

    public function testRunnerValidatesArtifactAndReadinessEvidenceBeforeExecutingTests(): void
    {
        $marker = $this->root . '/runner-evidence-marker';
        [$root, $package] = $this->runnerFixture([
            'regress_green.php' => '<?php file_put_contents(' . var_export($marker, true) . ', "ran");',
        ]);
        self::write($package . '/evidence/artifacts.lock.json', "{}\n");

        try {
            AdapterPackageTestRunner::run($root, 'acf');
            self::fail('malformed artifact evidence reached a green package test');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('Artifact fragment must contain exactly plugins and themes', $failure->getMessage());
        }
        self::assertFileDoesNotExist($marker);
    }

    public function testOtherClassesCanBeListedButNotExecuted(): void
    {
        $package = $this->package('acf');
        self::write($package . '/tests/offline/regress_offline.php', '<?php');
        self::write($package . '/tests/conformance/regress_contract.sh', "exit 0\n");

        $listed = self::invoke([
            '--repo=' . $this->root,
            '--adapter=acf',
            '--class=conformance',
            '--list',
        ]);
        self::assertSame(0, $listed['status'], $listed['stderr']);
        self::assertStringContainsString('conformance (1)', $listed['stdout']);

        $run = self::invoke([
            '--repo=' . $this->root,
            '--adapter=acf',
            '--class=conformance',
        ]);
        self::assertSame(2, $run['status']);
        self::assertStringContainsString('execution is available only for --class=offline', $run['stderr']);
    }

    public function testSpikeUsesItsClassPrefixAndCanBeListed(): void
    {
        $package = $this->package('acf');
        self::write($package . '/tests/offline/regress_offline.php', '<?php');
        self::write($package . '/tests/spike/spike_acf_probe.sh', "exit 0\n");

        $listed = self::invoke([
            '--repo=' . $this->root,
            '--adapter=acf',
            '--class=spike',
            '--list',
        ]);

        self::assertSame(0, $listed['status'], $listed['stderr']);
        self::assertStringContainsString('acf spike (1)', $listed['stdout']);
        self::assertStringContainsString('bash adapter-packages/acf/tests/spike/spike_acf_probe.sh', $listed['stdout']);
    }

    public function testDiscoveryFailureIsNeverRenderedAsAnEmptyGreenList(): void
    {
        $result = self::invoke([
            '--repo=' . $this->root,
            '--adapter=missing',
            '--json',
        ]);

        self::assertSame(1, $result['status']);
        self::assertSame('', $result['stdout']);
        self::assertStringContainsString("package 'missing' is missing", $result['stderr']);
    }

    /**
     * @param list<string> $arguments
     * @return array{status:int,stdout:string,stderr:string}
     */
    private static function invoke(array $arguments): array
    {
        $repo = dirname(__DIR__, 2);
        $process = proc_open(
            [PHP_BINARY, $repo . '/tools/adapter-package-tests.php', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function package(string $slug, bool $offline = true): string
    {
        $package = $this->root . '/adapter-packages/' . $slug;
        self::makeDirectory($package . '/tests');
        if ($offline) {
            self::makeDirectory($package . '/tests/offline');
        }
        return $package;
    }

    private function validatorFixture(): string
    {
        $repo = dirname(__DIR__, 2);
        $root = $this->root . '/validator';
        self::copyTree($repo . '/adapter-packages/acf', $root . '/adapter-packages/acf');
        self::copyTree($repo . '/platform/adapter-library', $root . '/platform/adapter-library');
        self::makeDirectory($root . '/agent/src');
        self::write($root . '/agent/duo.php', (string) file_get_contents($repo . '/agent/duo.php'));
        foreach ([
            'agent/src/Kernel/PlainData.php',
            'sandbox/tests/live/regress_capture_concurrency.sh',
            'sandbox/tests/live/regress_multisite_refusal.sh',
            'sandbox/tests/offline/apply/regress_fatal_mutations.php',
        ] as $evidence) {
            self::write($root . '/' . $evidence, (string) file_get_contents($repo . '/' . $evidence));
        }
        return $root;
    }

    /**
     * @param array<string,string> $tests relative path below tests/offline => bytes
     * @return array{0:string,1:string} fixture root and capsule root
     */
    private function runnerFixture(array $tests): array
    {
        $root = $this->validatorFixture();
        $package = $root . '/adapter-packages/acf';
        self::removeTree($package . '/tests/offline');
        foreach ($tests as $relative => $bytes) {
            self::write($package . '/tests/offline/' . $relative, $bytes);
        }

        $first = array_key_first($tests);
        self::assertIsString($first);
        $readinessPath = $package . '/evidence/production-readiness.json';
        $readiness = json_decode(
            (string) file_get_contents($readinessPath),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        self::assertIsArray($readiness);
        foreach ($readiness['covered'] as &$paths) {
            foreach ($paths as &$path) {
                if (is_string($path) && str_starts_with($path, 'adapter-packages/acf/tests/offline/')) {
                    $path = 'adapter-packages/acf/tests/offline/' . $first;
                }
            }
            unset($path);
            $paths = array_values(array_unique($paths));
        }
        unset($paths);
        self::write(
            $readinessPath,
            json_encode($readiness, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );

        return [$root, $package];
    }

    private static function replaceReadinessEvidence(string $root, string $from, string $to): void
    {
        $path = $root . '/adapter-packages/acf/evidence/production-readiness.json';
        $record = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($record);
        $replaced = 0;
        foreach ($record['covered'] as &$paths) {
            foreach ($paths as &$evidence) {
                if ($evidence === $from) {
                    $evidence = $to;
                    $replaced++;
                }
            }
            unset($evidence);
        }
        unset($paths);
        self::assertGreaterThan(0, $replaced);
        self::write(
            $path,
            json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );
    }

    private static function addExternalEvidence(string $root, string $test, string $evidence): void
    {
        $package = $root . '/adapter-packages/acf';
        self::write(
            $package . '/evidence/external-tests.json',
            json_encode([
                'format' => 'duo-adapter-external-evidence/v1',
                'tests' => [$test => $evidence],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );
        $path = $package . '/package/disposition.json';
        $disposition = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($disposition);
        if (!in_array($test, $disposition['evidence']['tests'], true)) {
            $disposition['evidence']['tests'][] = $test;
        }
        self::write(
            $path,
            json_encode($disposition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );
    }

    /** @param list<string> $participants */
    private static function scenarioFixture(string $root, string $scenario, array $participants): string
    {
        foreach ($participants as $participant) {
            $manifest = $root . '/adapter-packages/' . $participant . '/package/manifest.json';
            if (!is_file($manifest)) {
                self::write(
                    $manifest,
                    json_encode(['name' => $participant], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"
                );
            }
        }
        $directory = $root . '/integration-scenarios/' . $scenario;
        self::write(
            $directory . '/scenario.json',
            json_encode([
                'format' => 'duo-adapter-integration-scenario/v1',
                'participants' => $participants,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );
        $gate = 'regress_' . str_replace('-', '_', $scenario) . '.php';
        $evidence = 'integration-scenarios/' . $scenario . '/tests/offline/' . $gate;
        self::write($root . '/' . $evidence, "<?php\n");
        return $evidence;
    }

    private static function copyTree(string $source, string $destination): void
    {
        self::makeDirectory($destination);
        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $from = $source . '/' . $entry;
            $to = $destination . '/' . $entry;
            if (is_dir($from) && !is_link($from)) {
                self::copyTree($from, $to);
                continue;
            }
            self::assertFalse(is_link($from));
            self::write($to, (string) file_get_contents($from));
        }
    }

    private static function write(string $path, string $bytes): void
    {
        self::makeDirectory(dirname($path));
        self::assertNotFalse(file_put_contents($path, $bytes));
    }

    private static function makeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            self::assertTrue(mkdir($path, 0777, true));
        }
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

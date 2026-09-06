<?php

declare(strict_types=1);

namespace WPrism\Tests\Tooling;

use WPrism\Tooling\AdapterChangeScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tools/src/AdapterChangeScope.php';

/**
 * Pin the closed path-ownership boundary before it becomes merge authority.
 *
 * The dangerous failure mode is an unrecognized path producing an empty narrow
 * work list. Every refusal-oriented case below therefore asserts `full`, not a
 * warning or an empty result that a caller could accidentally treat as green.
 */
final class AdapterChangeScopeTest extends TestCase
{
    public function testOneAdapterPackageSelectsOnlyThatAdapter(): void
    {
        $result = AdapterChangeScope::classify([
            'adapter-packages/woocommerce/manifest.json',
            'adapter-packages/woocommerce/tests/offline/regress_product.php',
        ]);

        self::assertSame(AdapterChangeScope::SCOPE_ADAPTERS, $result['scope']);
        self::assertSame(['woocommerce'], $result['adapters']);
        self::assertSame(
            [
                'rank-math-commerce-multilingual',
                'woocommerce-rewrite-coinstall',
            ],
            $result['scenarios']
        );
        self::assertSame(
            [
                'integration-scenarios/rank-math-commerce-multilingual/tests/live/regress_rank_math_commerce_multilingual.sh',
                'integration-scenarios/rank-math-commerce-multilingual/tests/live/regress_rank_math_commerce_multilingual_ssh_deletion.sh',
                'integration-scenarios/rank-math-commerce-multilingual/tests/offline/regress_canonical_recapture_evidence.php',
                'integration-scenarios/rank-math-commerce-multilingual/tests/offline/regress_deletion_boundary.php',
                'integration-scenarios/rank-math-commerce-multilingual/tests/offline/regress_rank_math_commerce_multilingual_contract.php',
                'integration-scenarios/rank-math-commerce-multilingual/tests/offline/regress_source_native_premise.php',
                'integration-scenarios/woocommerce-rewrite-coinstall/tests/live/regress_woocommerce_rewrite_coinstall.sh',
                'integration-scenarios/woocommerce-rewrite-coinstall/tests/offline/regress_woocommerce_hierarchy_lookups.php',
            ],
            array_column($result['scenario_gates'], 'path')
        );
        self::assertFalse($result['requires_participant_resolution']);
    }

    public function testSeveralAdapterPackagesSelectTheirSortedUnion(): void
    {
        $result = AdapterChangeScope::classify([
            'adapter-packages/yoast/runtime/provider.php',
            'adapter-packages/acf/disposition.json',
            'adapter-packages/yoast/tests/live/conformance.sh',
        ]);

        self::assertSame(AdapterChangeScope::SCOPE_ADAPTERS, $result['scope']);
        self::assertSame(['acf', 'yoast'], $result['adapters']);
        self::assertSame(
            [
                'rank-math-commerce-multilingual',
                'rank-math-yoast-incompatibility',
                'woocommerce-rewrite-coinstall',
            ],
            $result['scenarios']
        );
    }

    /** @return iterable<string,array{0:string}> */
    public static function enginePaths(): iterable
    {
        yield 'agent' => ['agent/src/Policy/Policy.php'];
        yield 'cli' => ['cli/src/Command/Command.php'];
        yield 'recovery' => ['recovery/rollback-control.php'];
    }

    #[DataProvider('enginePaths')]
    public function testEngineRootsSelectTheEngineGate(string $path): void
    {
        $result = AdapterChangeScope::classify([$path]);

        self::assertSame(AdapterChangeScope::SCOPE_ENGINE, $result['scope']);
        self::assertSame([], $result['adapters']);
    }

    public function testOrdinaryChangesAcrossEngineRootsRemainEngineScoped(): void
    {
        $result = AdapterChangeScope::classify([
            'agent/src/Kernel/Canon.php',
            'cli/src/Command/CommandOutput.php',
            'recovery/canonical-json.php',
        ]);

        self::assertSame(AdapterChangeScope::SCOPE_ENGINE, $result['scope']);
    }

    /** @return iterable<string,array{0:string}> */
    public static function fullPaths(): iterable
    {
        yield 'platform' => ['platform/adapter-library/platform.json'];
        yield 'agent adapter library' => ['agent/adapter-library/AdapterLibrary.php'];
        yield 'package test command' => ['tools/adapter-package-tests.php'];
        yield 'package validator implementation' => ['tools/src/AdapterPackageValidator.php'];
        yield 'generated adapter kit' => ['tools/adapter-kit.json'];
        yield 'shared conformance harness' => ['sandbox/conformance/checks/core.sh'];
        yield 'shared test library' => ['sandbox/tests/lib/FakeWpdb.php'];
        yield 'unknown root' => ['some-new-root/file.php'];
        yield 'malformed adapter slug' => ['adapter-packages/Bad_Slug/manifest.json'];
        yield 'adapter package root without subject' => ['adapter-packages/README.md'];
        yield 'absolute path' => ['/adapter-packages/acf/manifest.json'];
        yield 'parent traversal' => ['adapter-packages/acf/../yoast/manifest.json'];
        yield 'backslash spelling' => ['adapter-packages\\acf\\manifest.json'];
    }

    #[DataProvider('fullPaths')]
    public function testSharedUnknownAndMalformedPathsFailClosedToFull(string $path): void
    {
        $result = AdapterChangeScope::classify([$path]);

        self::assertSame(AdapterChangeScope::SCOPE_FULL, $result['scope']);
    }

    public function testIntegrationScenarioIsValidatedAndItsEditStaysFull(): void
    {
        $result = AdapterChangeScope::classify([
            'integration-scenarios/polylang-tec-rewrite-coinstall/scenario.json',
        ]);

        self::assertSame(AdapterChangeScope::SCOPE_FULL, $result['scope']);
        self::assertSame(['polylang-tec-rewrite-coinstall'], $result['scenarios']);
        self::assertFalse($result['requires_participant_resolution']);
        self::assertSame('bash', $result['scenario_gates'][0]['command'][0]);
    }

    public function testAdapterParticipantsSelectScenarioNamesSortedAndUnique(): void
    {
        $result = AdapterChangeScope::classify([
            'adapter-packages/polylang/package/manifest.json',
        ]);

        self::assertSame(AdapterChangeScope::SCOPE_ADAPTERS, $result['scope']);
        self::assertSame(
            [
                'polylang-tec-rewrite-coinstall',
                'rank-math-commerce-multilingual',
                'woocommerce-rewrite-coinstall',
            ],
            $result['scenarios']
        );
    }

    public function testMixedAdapterAndEngineChangeSelectsFull(): void
    {
        $result = AdapterChangeScope::classify([
            'adapter-packages/acf/manifest.json',
            'agent/src/Apply/Apply.php',
        ]);

        self::assertSame(AdapterChangeScope::SCOPE_FULL, $result['scope']);
        self::assertSame(['acf'], $result['adapters']);
    }

    public function testRenameInsideOneAdapterRemainsAdapterScoped(): void
    {
        $result = AdapterChangeScope::classify([[
            'from' => 'adapter-packages/acf/tests/offline/old.php',
            'to' => 'adapter-packages/acf/tests/offline/new.php',
        ]]);

        self::assertSame(AdapterChangeScope::SCOPE_ADAPTERS, $result['scope']);
        self::assertSame(['acf'], $result['adapters']);
    }

    /** @return iterable<string,array{0:array{from:string,to:string}}> */
    public static function crossRootRenames(): iterable
    {
        yield 'adapter to another adapter' => [[
            'from' => 'adapter-packages/acf/manifest.json',
            'to' => 'adapter-packages/yoast/manifest.json',
        ]];
        yield 'adapter to engine' => [[
            'from' => 'adapter-packages/acf/runtime/interpreter.php',
            'to' => 'agent/src/Adapter/Acf.php',
        ]];
        yield 'agent to cli' => [[
            'from' => 'agent/src/Kernel/Shared.php',
            'to' => 'cli/src/Kernel/Shared.php',
        ]];
        yield 'engine to adapter library' => [[
            'from' => 'agent/src/Policy/Library.php',
            'to' => 'agent/adapter-library/Library.php',
        ]];
    }

    /** @param array{from:string,to:string} $rename */
    #[DataProvider('crossRootRenames')]
    public function testCrossRootRenameSelectsFull(array $rename): void
    {
        $result = AdapterChangeScope::classify([$rename]);

        self::assertSame(AdapterChangeScope::SCOPE_FULL, $result['scope']);
    }

    public function testRenameInsideOneEngineRootRemainsEngineScoped(): void
    {
        $result = AdapterChangeScope::classify([[
            'from' => 'agent/src/Policy/OldName.php',
            'to' => 'agent/src/Policy/NewName.php',
        ]]);

        self::assertSame(AdapterChangeScope::SCOPE_ENGINE, $result['scope']);
    }

    public function testEmptyChangeSetFailsClosedToFull(): void
    {
        $result = AdapterChangeScope::classify([]);

        self::assertSame(AdapterChangeScope::SCOPE_FULL, $result['scope']);
        self::assertSame([], $result['owners']);
    }

    public function testMalformedChangeRecordFailsClosedToFull(): void
    {
        $malformed = ['from' => 'adapter-packages/acf/manifest.json'];
        // Reflection reaches the runtime boundary without lying to static
        // analysis about classify()'s documented input type.
        $result = (new \ReflectionMethod(AdapterChangeScope::class, 'classify'))->invoke(null, [$malformed]);

        self::assertIsArray($result);
        self::assertSame(AdapterChangeScope::SCOPE_FULL, $result['scope']);
        self::assertSame('<malformed-change>', $result['owners'][0]['path']);
    }
}

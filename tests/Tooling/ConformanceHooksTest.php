<?php

declare(strict_types=1);

namespace WPrism\Tests\Tooling;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WPrism\Tooling\AdapterPackageValidator;
use WPrism\Tooling\ConformanceHooks;
use WPrismTest\ShellProbe;

require_once dirname(__DIR__, 2) . '/tools/src/ConformanceHooks.php';
require_once dirname(__DIR__, 2) . '/tools/src/AdapterPackageValidator.php';
require_once dirname(__DIR__, 2) . '/sandbox/tests/lib/ShellProbe.php';

final class ConformanceHooksTest extends TestCase
{
    private string $capsule;

    protected function setUp(): void
    {
        $this->capsule = sys_get_temp_dir() . '/conformance-hooks-' . bin2hex(random_bytes(8));
        mkdir($this->capsule . '/fixtures', 0700, true);
        $this->capsule = (string) realpath($this->capsule);
        file_put_contents($this->capsule . '/fixtures/seed.sh', 'echo selected-seed');
        file_put_contents($this->capsule . '/fixtures/check.sh', 'echo selected-check');
        symlink('seed.sh', $this->capsule . '/fixtures/alias.sh');
        symlink('.', $this->capsule . '/fixtures/nested');
        symlink('fixtures', $this->capsule . '/alias');
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->capsule, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->capsule);
    }

    /** @return array<string,mixed> */
    private function entry(): array
    {
        return ['hooks' => ['seed' => 'fixtures/seed.sh', 'capture-check' => null,
            'postdeploy' => null, 'postapply' => null, 'check' => 'fixtures/check.sh']];
    }

    public function testAbsentDeclarationPreservesConventionAndExplicitHooksResolve(): void
    {
        self::assertNull(ConformanceHooks::resolve([], '/missing/platform/capsule'));
        self::assertSame(['seed' => $this->capsule . '/fixtures/seed.sh', 'capture-check' => null,
            'postdeploy' => null, 'postapply' => null, 'check' => $this->capsule . '/fixtures/check.sh'],
            ConformanceHooks::resolve($this->entry(), $this->capsule));
    }

    public function testPackageValidationUsesTheSameSelectedHooks(): void
    {
        $root = $this->capsule . '/repository';
        $owned = $root . '/adapter-packages/example';
        mkdir($owned . '/tests/conformance', 0700, true);
        mkdir($owned . '/fixtures');
        foreach (['seed.sh', 'check.sh'] as $file) {
            copy($this->capsule . '/fixtures/' . $file, $owned . '/fixtures/' . $file);
        }
        $entryFile = $owned . '/tests/conformance/entry.json';
        file_put_contents($entryFile, json_encode(['manifest' => 'example', 'entry' => $this->entry()], JSON_THROW_ON_ERROR));
        self::assertContains('conformance-example', AdapterPackageValidator::discoverableEvidence($root, 'example'));
        unlink($owned . '/fixtures/check.sh');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("conformance hook 'check'");
        AdapterPackageValidator::discoverableEvidence($root, 'example');
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function invalidHooks(): iterable
    {
        yield 'null map' => ['hooks', null];
        yield 'empty map' => ['hooks', []];
        yield 'string map' => ['hooks', 'seed.sh'];
        $hooks = ['seed' => 'fixtures/seed.sh', 'capture-check' => null, 'postdeploy' => null, 'postapply' => null, 'check' => 'fixtures/check.sh'];
        yield 'missing phase' => ['hooks', array_diff_key($hooks, ['postapply' => true])];
        yield 'unknown phase' => ['hooks', $hooks + ['fallback' => 'fixtures/seed.sh']];
        foreach ([null, false, 1, '', '/fixtures/seed.sh', '../fixtures/seed.sh', 'fixtures/../fixtures/seed.sh',
            'fixtures/missing.sh', 'fixtures/alias.sh', 'fixtures/nested/seed.sh', 'alias/seed.sh', 'package/run.sh', 'fixtures/seed.php'] as $i => $value) {
            yield 'invalid seed ' . $i => ['seed', $value];
        }
    }

    #[DataProvider('invalidHooks')]
    public function testMalformedOrUnownedHooksRefuse(string $key, mixed $value): void
    {
        $entry = $this->entry();
        if ($key === 'hooks') {
            $entry['hooks'] = $value;
        } else {
            $entry['hooks'][$key] = $value;
        }
        $this->expectException(RuntimeException::class);
        ConformanceHooks::resolve($entry, $this->capsule);
    }

    public function testRunnerUsesDeclaredHooksWithoutLegacyFallback(): void
    {
        $root = dirname(__DIR__, 2);
        $source = (string) file_get_contents($root . '/sandbox/conformance/run.sh');
        self::assertSame(1, preg_match('/^conformance_hook\(\).*?^\}/ms', $source, $match));
        $probe = <<<'SH'
set -euo pipefail
CONFORMANCE_HOOKS=$("$1" "$2/tools/conformance-hooks.php" "$3" "$4")
PACKAGE_CONFORMANCE="$4/unused"
eval "$5"
test "$(bash "$(conformance_hook seed.sh "$4/fixtures/check.sh")")" = selected-seed
test "$(bash "$(conformance_hook check.sh "$4/fixtures/seed.sh")")" = selected-check
test -z "$(conformance_hook postapply.sh "$4/fixtures/check.sh")"
echo hook-dispatch-passed
SH;
        [$status, $output] = ShellProbe::run($probe, [PHP_BINARY, $root,
            json_encode($this->entry(), JSON_THROW_ON_ERROR), $this->capsule, $match[0]], $root);
        self::assertSame(0, $status, $output);
        self::assertSame("hook-dispatch-passed\n", $output);
        self::assertLessThan(strpos($source, 'bash bin/pair.sh reset'), strpos($source, 'CONFORMANCE_HOOKS=$(php'));
    }
}

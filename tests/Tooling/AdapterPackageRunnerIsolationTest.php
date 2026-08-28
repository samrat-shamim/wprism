<?php

declare(strict_types=1);

namespace Duo\Tests\Tooling;

use Duo\Tooling\AdapterPackageTestRunner;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tools/src/AdapterPackageTestRunner.php';

final class AdapterPackageRunnerIsolationTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir() . '/duo-adapter-runner-isolation-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch, 0o777, true));
    }

    protected function tearDown(): void
    {
        self::removeTree($this->scratch);
    }

    public function testAcfAndWooRunnersIgnoreAMalformedSiblingCapsule(): void
    {
        $repo = dirname(__DIR__, 2);
        foreach ([
            'agent',
            'platform',
            'tools',
            'recovery',
            'sandbox/tests',
            'integration-scenarios',
            'adapter-packages/acf',
            'adapter-packages/woocommerce',
        ] as $relative) {
            self::copyTree($repo . '/' . $relative, $this->scratch . '/' . $relative);
        }
        foreach (['run.sh', 'asserts.sh'] as $file) {
            self::copyFile(
                $repo . '/sandbox/conformance/' . $file,
                $this->scratch . '/sandbox/conformance/' . $file
            );
        }
        foreach (['bin/fetch-artifact.sh', 'lib/pair_identity.sh'] as $file) {
            self::copyFile(
                $repo . '/sandbox/' . $file,
                $this->scratch . '/sandbox/' . $file
            );
        }
        self::copyFile(
            $repo . '/adapter-packages/woocommerce/evidence/artifacts.lock.json',
            $this->scratch . '/adapter-packages/malformed-sibling/evidence/artifacts.lock.json'
        );
        self::copyFile(
            $repo . '/adapter-packages/woocommerce/package/disposition.json',
            $this->scratch . '/adapter-packages/malformed-sibling/package/disposition.json'
        );
        self::copyFile(
            $repo . '/adapter-packages/woocommerce/package/manifest.json',
            $this->scratch . '/adapter-packages/malformed-sibling/package/manifest.json'
        );
        self::assertNotFalse(file_put_contents(
            $this->scratch . '/adapter-packages/malformed-sibling/package/manifest.json',
            "{not-json\n"
        ));

        foreach (['acf', 'woocommerce'] as $slug) {
            $run = AdapterPackageTestRunner::run($this->scratch, $slug);
            self::assertSame('passed', $run['status'], self::failureSummary($run));
            self::assertSame(0, $run['exit_code'], self::failureSummary($run));
            self::assertNotSame([], $run['tests']);
        }
    }

    /** @param array{tests:list<array{path:string,exit_code:int,stdout:string,stderr:string}>} $run */
    private static function failureSummary(array $run): string
    {
        $failures = [];
        foreach ($run['tests'] as $test) {
            if ($test['exit_code'] !== 0) {
                $failures[] = $test['path'] . ': ' . trim($test['stderr'] . "\n" . $test['stdout']);
            }
        }

        return implode("\n", $failures);
    }

    private static function copyTree(string $source, string $destination): void
    {
        self::assertDirectoryExists($source);
        if (!is_dir($destination)) {
            self::assertTrue(mkdir($destination, 0o777, true));
        }
        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $from = $source . '/' . $entry;
            $to = $destination . '/' . $entry;
            self::assertFalse(is_link($from), "fixture source must not contain symlinks: $from");
            if (is_dir($from)) {
                self::copyTree($from, $to);
            } else {
                self::copyFile($from, $to);
            }
        }
    }

    private static function copyFile(string $source, string $destination): void
    {
        if (!is_dir(dirname($destination))) {
            self::assertTrue(mkdir(dirname($destination), 0o777, true));
        }
        self::assertTrue(copy($source, $destination), "could not copy fixture file: $source");
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
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

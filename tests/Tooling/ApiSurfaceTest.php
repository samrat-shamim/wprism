<?php

declare(strict_types=1);

namespace WPrism\Tests\Tooling;

use PHPUnit\Framework\TestCase;

/**
 * Pins tools/api-surface.php (WP-10) against the two things a
 * regenerate-and-gate snapshot must guarantee, plus its comparison logic
 * directly:
 *
 *  1. `--check` is GREEN against the fixture this commit ships
 *     (sandbox/tests/fixtures/api-surface.json). A red check here means
 *     either the fixture is stale (someone changed a reflected file without
 *     `--write`) or the tool itself regressed — either way this is where a
 *     decomposition PR's accidental API break is meant to be caught, per the
 *     tool's own header docblock.
 *  2. Two consecutive runs are BYTE IDENTICAL. The tool's own determinism
 *     claim (no timestamps, no environment-specific values, sorted keys) is
 *     only worth anything if it is actually pinned; a non-deterministic
 *     generator would make `--check` flaky rather than trustworthy.
 *  3. as_diff() — the pure comparison function `--check` calls to produce
 *     its readable diff — actually reports a removed method on a synthetic
 *     mutation of the real fixture. This is tested by calling as_diff()
 *     directly rather than only through a real subprocess mismatch, because
 *     a CLI-level assertion could only say "some diff was printed", which
 *     would still pass if the comparator degenerated to reporting nothing
 *     useful.
 *
 * The CLI-level cases (1, 2) run tools/api-surface.php out-of-process via
 * `proc_open` — it is a CLI entry point that spawns its own child
 * `PHP_BINARY` subprocess and shells out to a fresh reflected load of the
 * entire WPrism\* class graph, none of which belongs happening as a side
 * effect of requiring a test file in-process (mirrors
 * tests/Tooling/AffectedTest.php's own invoke() helper and its stated
 * rationale). The comparator case (3) requires tools/api-surface.php
 * IN-process instead: the file's bottom guard only runs as_main() when
 * SCRIPT_FILENAME resolves to itself (never under phpunit — same guard
 * shape as tools/affected.php's af_main()), and as_diff() is a pure
 * array-in/array-out function that neither shells out nor writes anything,
 * so pinning it at unit level is safe and is the only way to assert the
 * exact reported line rather than merely "the exit code was 1".
 */
final class ApiSurfaceTest extends TestCase
{
    private static function repoRoot(): string
    {
        $env = getenv('WPRISM_REPO_ROOT');
        return is_string($env) && $env !== '' ? $env : dirname(__DIR__, 2);
    }

    private static function fixturePath(): string
    {
        return self::repoRoot() . '/sandbox/tests/fixtures/api-surface.json';
    }

    /**
     * @param list<string> $args
     * @return array{status:int,stdout:string,stderr:string}
     */
    private static function invoke(array $args): array
    {
        $repo = self::repoRoot();
        $cmd = [PHP_BINARY, $repo . '/tools/api-surface.php', ...$args];
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes, $repo);
        self::assertIsResource($process, 'could not launch tools/api-surface.php');
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        return ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @return array<string,mixed> */
    private static function decodedFixture(): array
    {
        $raw = (string) file_get_contents(self::fixturePath());
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, 'sandbox/tests/fixtures/api-surface.json is not valid JSON');
        return $decoded;
    }

    /**
     * as_diff() lives in the global namespace (tools/api-surface.php is a
     * plain CLI script, not PSR-4 autoloaded — see the file's own bottom
     * guard). function_exists() makes this require idempotent across the
     * several test methods in this class that call it, mirroring
     * tests/Tooling/AffectedTest.php's identical guard for af_extract_paths().
     */
    private static function requireTool(): void
    {
        if (!function_exists('as_diff')) {
            require_once self::repoRoot() . '/tools/api-surface.php';
        }
    }

    public function testCommittedFixtureExists(): void
    {
        self::assertFileExists(
            self::fixturePath(),
            'sandbox/tests/fixtures/api-surface.json is missing — run: php tools/api-surface.php --write'
        );
    }

    public function testCheckPassesAgainstTheCommittedFixture(): void
    {
        $result = self::invoke(['--check']);
        self::assertSame(
            0,
            $result['status'],
            "php tools/api-surface.php --check failed against the committed fixture.\n"
            . "stdout:\n{$result['stdout']}\nstderr:\n{$result['stderr']}\n"
            . 'If this change is intended, regenerate with: php tools/api-surface.php --write'
        );
    }

    public function testTwoConsecutiveRunsAreByteIdentical(): void
    {
        $first = self::invoke([]);
        $second = self::invoke([]);

        self::assertSame(0, $first['status'], 'first run failed: ' . $first['stderr']);
        self::assertSame(0, $second['status'], 'second run failed: ' . $second['stderr']);
        self::assertNotSame('', $first['stdout'], 'first run produced no output');
        self::assertSame(
            $first['stdout'],
            $second['stdout'],
            'two consecutive php tools/api-surface.php runs produced different bytes — '
            . 'the snapshot is not deterministic'
        );
    }

    public function testSelfTypesHaveOneRepresentationAcrossSupportedPhpMinors(): void
    {
        self::requireTool();
        $class = new \ReflectionClass(ApiSurfaceSelfTypeFixture::class);
        $method = $class->getMethod('copy');
        $surface = as_reflect_methods($class);

        self::assertSame(ApiSurfaceSelfTypeFixture::class, $surface['copy']['return_type']);
        self::assertSame('?' . ApiSurfaceSelfTypeFixture::class, $surface['copy']['parameters'][0]['type']);
        self::assertSame(ApiSurfaceSelfTypeFixture::class, as_type_repr($method->getReturnType(), $class));
    }

    public function testCompareReportsNothingWhenSurfacesAreIdentical(): void
    {
        self::requireTool();
        $surface = self::decodedFixture();

        self::assertSame([], as_diff($surface, $surface));
    }

    public function testCompareReportsARemovedMethod(): void
    {
        self::requireTool();
        $committed = self::decodedFixture();
        self::assertIsArray($committed['classes'] ?? null);

        $classWithMethod = null;
        foreach ($committed['classes'] as $name => $entry) {
            if (is_array($entry['methods'] ?? null) && $entry['methods'] !== []) {
                $classWithMethod = $name;
                break;
            }
        }
        self::assertIsString($classWithMethod, 'fixture has no class with a public/protected method to mutate');

        $methods = $committed['classes'][$classWithMethod]['methods'];
        $methodName = array_key_first($methods);
        self::assertIsString($methodName);

        $mutated = $committed;
        unset($mutated['classes'][$classWithMethod]['methods'][$methodName]);

        $diff = as_diff($committed, $mutated);

        self::assertContains(
            "- $classWithMethod::$methodName (methods, removed)",
            $diff,
            "as_diff() did not report the synthetically removed method $classWithMethod::$methodName.\n"
            . 'Reported lines: ' . implode(' | ', $diff)
        );
    }

    public function testCompareReportsAnAddedClass(): void
    {
        self::requireTool();
        $committed = self::decodedFixture();
        self::assertIsArray($committed['classes'] ?? null);

        $mutated = $committed;
        $mutated['classes']['WPrism\\__SyntheticTestClass__'] = [
            'kind' => 'class',
            'final' => true,
            'abstract' => false,
            'readonly' => false,
            'parent' => null,
            'interfaces' => [],
            'constants' => [],
            'properties' => [],
            'methods' => [],
        ];

        $diff = as_diff($committed, $mutated);

        self::assertContains('+ WPrism\\__SyntheticTestClass__ (added)', $diff);
    }
}

final class ApiSurfaceSelfTypeFixture
{
    public function copy(?self $other = null): self
    {
        return $other ?? $this;
    }
}

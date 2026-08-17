<?php

declare(strict_types=1);

namespace Duo\Tests\Tooling;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins tools/codemod/move-modules.php — the ROUND 3 TRAIN 1 codemod that moves
 * the flat agent/src and cli/src trees into module directories while leaving
 * every `namespace Duo;` declaration alone.
 *
 * Two levels, for two different reasons.
 *
 * The synthetic-repo cases build a miniature of the real tree in a temp dir
 * (its own `git init`, so `git mv` is exercised rather than the rename
 * fallback) and run the script out-of-process with --root/--map. That is the
 * only way to state the *exact* rewrites the move must produce — same-module
 * require unchanged, cross-module require re-based through `../`, the guarded
 * `class_exists(X::class, false)` block preserved byte-for-byte around it,
 * `dirname(__DIR__)` bumped, loader lines re-pointed, literals in shell and
 * JSON followed, and a directory-level `agent/src` mention left alone. A
 * CLI-level assertion against the real tree could only say "something changed".
 *
 * The real-repo case runs --plan against this checkout with a five-file map and
 * asserts the plan names the cross-references it must find (agent/duo.php's
 * loader lines, sandbox/tests requires) while changing nothing: `git status
 * --porcelain` is captured either side and compared. It uses --allow-partial,
 * because a five-file map is deliberately not total and the totality guard —
 * asserted separately — would otherwise refuse it.
 *
 * A few pure helpers are additionally exercised in-process. That is safe: the
 * script's bottom guard only calls mm_main() when SCRIPT_FILENAME resolves to
 * itself, which never happens under phpunit.
 */
final class MoveModulesTest extends TestCase
{
    private static string $tempRoot = '';

    // ------------------------------------------------------------- fixtures

    private static function repoRoot(): string
    {
        $env = getenv('DUO_REPO_ROOT');
        return is_string($env) && $env !== '' ? $env : dirname(__DIR__, 2);
    }

    private static function script(): string
    {
        return self::repoRoot() . '/tools/codemod/move-modules.php';
    }

    public static function setUpBeforeClass(): void
    {
        require_once self::script();
    }

    protected function tearDown(): void
    {
        if (self::$tempRoot !== '' && is_dir(self::$tempRoot)) {
            self::removeTree(self::$tempRoot);
        }
        self::$tempRoot = '';
    }

    private static function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    private static function write(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("could not create $dir");
        }
        file_put_contents($path, $contents);
    }

    /**
     * A miniature of the real tree: a loader that requires by root-relative
     * path, three engine files with same-directory requires (one guarded, one
     * carrying a `dirname(__DIR__)` path base), a file that must stay put, a
     * suite that requires across the repo AND asserts on require source text,
     * a shell script, and the two layer data files.
     */
    private function makeSyntheticRepo(): string
    {
        $root = (string) tempnam(sys_get_temp_dir(), 'duo-mm-');
        unlink($root);
        mkdir($root, 0777, true);
        self::$tempRoot = $root;

        self::write($root . '/agent/duo.php', <<<'PHP'
<?php

define('DUO_AGENT_VERSION', '0.5.0');
define('DUO_SPEC_VERSION', 2);

require_once __DIR__ . '/src/Alpha.php';
require_once __DIR__ . '/src/Beta.php';
require_once __DIR__ . '/src/Gamma.php';
require_once __DIR__ . '/src/Stays.php';

PHP);

        self::write($root . '/agent/src/Alpha.php', <<<'PHP'
<?php

namespace Duo;

require_once __DIR__ . '/Beta.php';
require_once __DIR__ . '/Gamma.php';

class Alpha
{
    public static function manifests(): string
    {
        return dirname(__DIR__) . '/manifests';
    }
}

PHP);

        self::write($root . '/agent/src/Beta.php', <<<'PHP'
<?php

namespace Duo;

if (!class_exists(Gamma::class, false)) {
    require_once __DIR__ . '/Gamma.php';
}

class Beta
{
}

PHP);

        self::write($root . '/agent/src/Gamma.php', "<?php\n\nnamespace Duo;\n\nclass Gamma\n{\n}\n");
        self::write($root . '/agent/src/Stays.php', "<?php\n\nnamespace Duo;\n\nclass Stays\n{\n}\n");

        self::write($root . '/sandbox/tests/regress_demo.php', <<<'PHP'
<?php

require_once __DIR__ . '/../../agent/src/Alpha.php';
$betaSource = (string) file_get_contents(__DIR__ . '/../../agent/src/Beta.php');
if (!str_contains($betaSource, "require_once __DIR__ . '/Gamma.php';")) {
    exit(1);
}

PHP);

        self::write($root . '/sandbox/tests/regress_demo.sh', <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
php "$ROOT/agent/src/Beta.php"
ls "$ROOT/agent/src"

SH);

        self::write($root . '/tools/layers.json', json_encode([
            'ladder' => ['kernel', 'engine'],
            'rule' => 'a file may reference only files in its own or a lower layer',
            'files' => [
                'src/Alpha.php' => 'engine',
                'src/Beta.php' => 'engine',
                'src/Gamma.php' => 'kernel',
                'src/Stays.php' => 'kernel',
            ],
            'notes' => ['src/Alpha.php' => 'the demo entry point'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        self::write($root . '/tools/layers-exceptions.json', json_encode([
            'src/Alpha.php -> src/Beta.php',
            'src/Gamma.php -> src/Alpha.php',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        exec('git -C ' . escapeshellarg($root) . ' init -q 2>&1');
        exec('git -C ' . escapeshellarg($root) . ' add -A 2>&1');
        exec('git -C ' . escapeshellarg($root) . ' -c user.email=t@t -c user.name=t commit -qm base 2>&1');

        return $root;
    }

    /** @param array<string,mixed> $map */
    private function writeMap(string $root, array $map): string
    {
        $path = $root . '/modules.json';
        file_put_contents($path, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return $path;
    }

    /** @return array<string,mixed> the three-module map the synthetic repo is built for */
    private static function syntheticMap(): array
    {
        return [
            'agent' => [
                'root' => 'agent/src',
                'modules' => [
                    'Kernel' => ['layer' => 'kernel', 'files' => ['Gamma.php']],
                    'Engine' => ['layer' => 'engine', 'files' => ['Alpha.php', 'Beta.php']],
                    '.' => ['layer' => 'kernel', 'files' => ['Stays.php']],
                ],
            ],
        ];
    }

    /** @param list<string> $args
     * @return array{status:int, stdout:string, stderr:string} */
    private static function runCodemod(array $args): array
    {
        $cmd = [PHP_BINARY, self::script(), ...$args];
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::repoRoot());
        self::assertIsResource($process, 'could not launch move-modules.php');
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** Content hash of every file under $root except .git — the no-op oracle. */
    private static function treeDigest(string $root): string
    {
        $rows = [];
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($walk as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile()) {
                continue;
            }
            $relative = substr($entry->getPathname(), strlen($root) + 1);
            if (str_starts_with($relative, '.git/')) {
                continue;
            }
            $rows[$relative] = hash_file('sha256', $entry->getPathname());
        }
        ksort($rows, SORT_STRING);
        return hash('sha256', (string) json_encode($rows));
    }

    // -------------------------------------------------------- plan is inert

    public function testPlanListsEveryMoveAndRewriteWithoutTouchingTheTree(): void
    {
        $root = $this->makeSyntheticRepo();
        $map = $this->writeMap($root, self::syntheticMap());
        $before = self::treeDigest($root);

        $result = self::runCodemod(['--plan', '--root=' . $root, '--map=' . $map]);
        self::assertSame(0, $result['status'], $result['stderr']);

        $plan = $result['stdout'];
        self::assertStringContainsString('git mv agent/src/Gamma.php agent/src/Kernel/Gamma.php', $plan);
        self::assertStringContainsString('git mv agent/src/Alpha.php agent/src/Engine/Alpha.php', $plan);
        self::assertStringContainsString('keep  agent/src/Stays.php', $plan);
        // The cross-module require, the dirname base, the loader line, the
        // suite's require and its source-text needle all appear as rewrites.
        self::assertStringContainsString("__DIR__ . '/../Kernel/Gamma.php'", $plan);
        self::assertStringContainsString('dirname(__DIR__) -> dirname(__DIR__, 2)', $plan);
        self::assertStringContainsString("__DIR__ . '/src/Engine/Alpha.php'", $plan);
        self::assertStringContainsString('agent/src/Engine/Alpha.php', $plan);

        self::assertSame($before, self::treeDigest($root), '--plan must not change a single byte');
    }

    // -------------------------------------------------------------- the move

    public function testApplyMovesFilesAndRebasesEveryReference(): void
    {
        $root = $this->makeSyntheticRepo();
        $map = $this->writeMap($root, self::syntheticMap());

        $result = self::runCodemod(['--apply', '--root=' . $root, '--map=' . $map]);
        self::assertSame(0, $result['status'], $result['stderr'] . $result['stdout']);

        // 1. Files land in the right directories; a "." module stays put.
        self::assertFileExists($root . '/agent/src/Engine/Alpha.php');
        self::assertFileExists($root . '/agent/src/Engine/Beta.php');
        self::assertFileExists($root . '/agent/src/Kernel/Gamma.php');
        self::assertFileExists($root . '/agent/src/Stays.php');
        self::assertFileDoesNotExist($root . '/agent/src/Alpha.php');

        // 2. Same-module require unchanged; cross-module require re-based.
        $alpha = (string) file_get_contents($root . '/agent/src/Engine/Alpha.php');
        self::assertStringContainsString("require_once __DIR__ . '/Beta.php';", $alpha);
        self::assertStringContainsString("require_once __DIR__ . '/../Kernel/Gamma.php';", $alpha);

        // 3. The guarded form survives intact around the re-based path — the
        //    `false` argument is what keeps every shadow-block suite working.
        $beta = (string) file_get_contents($root . '/agent/src/Engine/Beta.php');
        self::assertStringContainsString(
            "if (!class_exists(Gamma::class, false)) {\n    require_once __DIR__ . '/../Kernel/Gamma.php';\n}",
            $beta
        );

        // 4. The dirname path base is one level deeper.
        self::assertStringContainsString("dirname(__DIR__, 2) . '/manifests'", $alpha);
        self::assertStringNotContainsString("dirname(__DIR__) . '/manifests'", $alpha);

        // 5. The loader's root-relative requires follow, and the define lines
        //    every certification bundle binds are untouched.
        $loader = (string) file_get_contents($root . '/agent/duo.php');
        self::assertStringContainsString("require_once __DIR__ . '/src/Engine/Alpha.php';", $loader);
        self::assertStringContainsString("require_once __DIR__ . '/src/Kernel/Gamma.php';", $loader);
        self::assertStringContainsString("require_once __DIR__ . '/src/Stays.php';", $loader);
        self::assertStringContainsString("define('DUO_AGENT_VERSION', '0.5.0');", $loader);
        self::assertStringContainsString("define('DUO_SPEC_VERSION', 2);", $loader);

        // 6. A suite's own require follows, and so does the source-text needle
        //    it asserts Beta.php contains.
        $suite = (string) file_get_contents($root . '/sandbox/tests/regress_demo.php');
        self::assertStringContainsString("require_once __DIR__ . '/../../agent/src/Engine/Alpha.php';", $suite);
        self::assertStringContainsString('agent/src/Engine/Beta.php', $suite);
        self::assertStringContainsString("\"require_once __DIR__ . '/../Kernel/Gamma.php';\"", $suite);

        // 7. Shell literal follows; the DIRECTORY-level mention does not.
        $shell = (string) file_get_contents($root . '/sandbox/tests/regress_demo.sh');
        self::assertStringContainsString('"$ROOT/agent/src/Engine/Beta.php"', $shell);
        self::assertStringContainsString('ls "$ROOT/agent/src"', $shell);

        // 8. Both layer data files follow, and the exception list stays sorted
        //    (regress_agent_src_requires.php asserts SORT_STRING order).
        $layers = json_decode((string) file_get_contents($root . '/tools/layers.json'), true);
        self::assertIsArray($layers);
        self::assertArrayHasKey('src/Engine/Alpha.php', $layers['files']);
        self::assertArrayHasKey('src/Kernel/Gamma.php', $layers['files']);
        self::assertArrayHasKey('src/Stays.php', $layers['files']);
        self::assertArrayHasKey('src/Engine/Alpha.php', $layers['notes']);

        $exceptions = json_decode((string) file_get_contents($root . '/tools/layers-exceptions.json'), true);
        self::assertSame(
            ['src/Engine/Alpha.php -> src/Engine/Beta.php', 'src/Kernel/Gamma.php -> src/Engine/Alpha.php'],
            $exceptions
        );
        $sorted = $exceptions;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $exceptions, 'the exception list must stay SORT_STRING ordered');

        // 9. The move went through git, so the index knows about it.
        exec('git -C ' . escapeshellarg($root) . ' status --porcelain', $status);
        self::assertNotSame([], $status);
        self::assertStringContainsString('agent/src/Engine/Alpha.php', implode("\n", $status));
    }

    public function testSecondApplyIsANoOp(): void
    {
        $root = $this->makeSyntheticRepo();
        $map = $this->writeMap($root, self::syntheticMap());

        $first = self::runCodemod(['--apply', '--root=' . $root, '--map=' . $map]);
        self::assertSame(0, $first['status'], $first['stderr']);
        $afterFirst = self::treeDigest($root);

        $second = self::runCodemod(['--apply', '--root=' . $root, '--map=' . $map]);
        self::assertSame(0, $second['status'], $second['stderr'] . $second['stdout']);
        self::assertSame($afterFirst, self::treeDigest($root), 'a second --apply must change nothing');
    }

    // ------------------------------------------------------------- refusals

    public function testUnassignedFileIsRefused(): void
    {
        $root = $this->makeSyntheticRepo();
        $map = $this->writeMap($root, [
            'agent' => [
                'root' => 'agent/src',
                'modules' => [
                    'Kernel' => ['files' => ['Gamma.php']],
                    'Engine' => ['files' => ['Alpha.php', 'Beta.php']],
                ],
            ],
        ]);

        $before = self::treeDigest($root);
        $result = self::runCodemod(['--plan', '--root=' . $root, '--map=' . $map]);
        self::assertSame(1, $result['status']);
        self::assertStringContainsString('agent/src/Stays.php is not assigned to any module', $result['stderr']);
        self::assertSame($before, self::treeDigest($root));
    }

    public function testFileAssignedTwiceIsRefused(): void
    {
        $root = $this->makeSyntheticRepo();
        $map = $this->writeMap($root, [
            'agent' => [
                'root' => 'agent/src',
                'modules' => [
                    'Kernel' => ['files' => ['Gamma.php', 'Alpha.php']],
                    'Engine' => ['files' => ['Alpha.php', 'Beta.php']],
                    '.' => ['files' => ['Stays.php']],
                ],
            ],
        ]);

        $result = self::runCodemod(['--plan', '--root=' . $root, '--map=' . $map]);
        self::assertSame(1, $result['status']);
        self::assertStringContainsString('is assigned to both', $result['stderr']);
    }

    public function testMapNamingAMissingFileIsRefused(): void
    {
        $root = $this->makeSyntheticRepo();
        $map = $this->syntheticMapWithExtra('Absent.php');
        $mapPath = $this->writeMap($root, $map);

        $result = self::runCodemod(['--plan', '--root=' . $root, '--map=' . $mapPath]);
        self::assertSame(1, $result['status']);
        self::assertStringContainsString('agent/src/Absent.php', $result['stderr']);
        self::assertStringContainsString('exists at neither its old nor its new path', $result['stderr']);
    }

    /** @return array<string,mixed> */
    private function syntheticMapWithExtra(string $file): array
    {
        $map = self::syntheticMap();
        $map['agent']['modules']['Kernel']['files'][] = $file;
        return $map;
    }

    // --------------------------------------------------- real-repo dry run

    public function testPlanAgainstTheRealRepositoryChangesNothing(): void
    {
        $repo = self::repoRoot();
        self::assertFileExists($repo . '/agent/src/Canon.php', 'the real tree is not in its pre-move shape');

        $mapPath = (string) tempnam(sys_get_temp_dir(), 'duo-mm-realmap-');
        file_put_contents($mapPath, json_encode([
            'agent' => [
                'root' => 'agent/src',
                'modules' => [
                    'Kernel' => ['layer' => 'kernel', 'files' => ['Canon.php', 'Uuid.php', 'OrderPreserved.php']],
                    'Policy' => ['layer' => 'policy', 'files' => ['Policy.php', 'OptionState.php']],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        exec('git -C ' . escapeshellarg($repo) . ' status --porcelain', $statusBefore);

        try {
            $result = self::runCodemod(['--plan', '--allow-partial', '--root=' . $repo, '--map=' . $mapPath, '--tree=agent']);
        } finally {
            unlink($mapPath);
        }

        self::assertSame(0, $result['status'], $result['stderr']);
        $plan = $result['stdout'];

        // The five files move.
        self::assertStringContainsString('git mv agent/src/Canon.php agent/src/Kernel/Canon.php', $plan);
        self::assertStringContainsString('git mv agent/src/Policy.php agent/src/Policy/Policy.php', $plan);

        // agent/duo.php's loader lines are found and re-pointed.
        self::assertStringContainsString("require_once __DIR__ . '/src/Kernel/Canon.php';", $plan);
        self::assertStringContainsString("require_once __DIR__ . '/src/Policy/Policy.php';", $plan);

        // The sandbox suites' cross-repo requires are found.
        self::assertMatchesRegularExpression(
            '#sandbox/tests/\S+\.php#',
            $plan,
            'the plan must name the sandbox suites that require the moved files'
        );
        self::assertStringContainsString('agent/src/Kernel/Canon.php', $plan);

        // manifests/ is reported, never rewritten.
        self::assertStringContainsString('manifests/ (left untouched, digest-bound)', $plan);
        self::assertStringContainsString('manifests/capabilities/evidence.json', $plan);

        exec('git -C ' . escapeshellarg($repo) . ' status --porcelain', $statusAfter);
        self::assertSame($statusBefore, $statusAfter, '--plan must leave the real working tree untouched');
    }

    // ------------------------------------------------------- pure helpers

    public function testRelativePathHelper(): void
    {
        self::assertSame('Canon.php', mm_relpath('agent/src/Kernel', 'agent/src/Kernel/Canon.php'));
        self::assertSame('../Kernel/Canon.php', mm_relpath('agent/src/Engine', 'agent/src/Kernel/Canon.php'));
        self::assertSame('../../../manifests', mm_relpath('agent/src/Engine', 'manifests'));
        self::assertSame('agent/src', mm_norm('agent/./src/Engine/..'));
        self::assertSame('/a/b', mm_norm('/a/c/../b'));
    }

    public function testLiteralRewriteIgnoresANonMatchingPrefix(): void
    {
        $index = ['agent' => ['Canon' => 'Kernel']];
        $roots = ['agent' => 'agent/src'];

        $hit = mm_rewrite_literals("require 'agent/src/Canon.php';", $index, $roots);
        self::assertSame("require 'agent/src/Kernel/Canon.php';", $hit['text']);

        // tools/affected.php documents this exact case: myagent/src/Canon.php
        // is a different, non-existent file and must never be rewritten.
        $miss = mm_rewrite_literals("require 'myagent/src/Canon.php';", $index, $roots);
        self::assertSame("require 'myagent/src/Canon.php';", $miss['text']);
        self::assertSame([], $miss['changes']);

        // A directory-level mention is not a file reference.
        $dir = mm_rewrite_literals("paths:\n    - agent/src\n", $index, $roots);
        self::assertSame("paths:\n    - agent/src\n", $dir['text']);

        // A basename the map does not assign is reported, not guessed.
        $unknown = mm_rewrite_literals("require 'agent/src/Nope.php';", $index, $roots);
        self::assertSame("require 'agent/src/Nope.php';", $unknown['text']);
        self::assertCount(1, $unknown['unknown']);
    }

    /**
     * Three spellings of the same path appear in this tree and all three must
     * move together. `tools/affected.php`'s own tests pair an input in one
     * spelling with an expectation in another — rewriting only the ordinary
     * one silently turns the assertion into a contradiction.
     */
    public function testEveryPathSpellingFollowsTheMove(): void
    {
        $index = ['agent' => ['Canon' => 'Kernel', 'PathSafety' => 'Kernel', 'Stays' => '']];
        $roots = ['agent' => 'agent/src'];

        $cases = [
            // ordinary
            "require 'agent/src/Canon.php';" => "require 'agent/src/Kernel/Canon.php';",
            // af_normalize_path()'s dot-segment fixture (AffectedTest:332)
            "af_normalize_path('./agent/./src/Canon.php')" => "af_normalize_path('./agent/./src/Kernel/Canon.php')",
            // the same path inside a regex literal (AffectedTest:239)
            '/^x <- agent\/src\/PathSafety\.php$/m' => '/^x <- agent\/src\/Kernel\/PathSafety\.php$/m',
            // a doubled separator
            'agent//src/Canon.php' => 'agent//src/Kernel/Canon.php',
            // a "." module keeps its file where it is
            'agent/src/Stays.php' => 'agent/src/Stays.php',
            // already applied: re-running changes nothing
            'agent/src/Kernel/Canon.php' => 'agent/src/Kernel/Canon.php',
            // the lookbehind's whole job
            "require 'myagent/src/Canon.php';" => "require 'myagent/src/Canon.php';",
            // no slash at all is not a path
            'myagent..src' => 'myagent..src',
        ];

        foreach ($cases as $input => $expected) {
            $result = mm_rewrite_literals((string) $input, $index, $roots);
            self::assertSame($expected, $result['text'], "rewriting: $input");
        }
    }

    public function testGuardedRequireKeepsItsClassExistsArgument(): void
    {
        $source = "<?php\nif (!class_exists(Gamma::class, false)) {\n    require_once __DIR__ . '/Gamma.php';\n}\n";
        $placement = ['agent/src/Beta.php' => 'agent/src/Engine/Beta.php', 'agent/src/Gamma.php' => 'agent/src/Kernel/Gamma.php'];

        $out = mm_rewrite_dir_relative($source, 'agent/src/Beta.php', 'agent/src/Engine/Beta.php', $placement);

        self::assertStringContainsString('class_exists(Gamma::class, false)', $out['source']);
        self::assertStringContainsString("require_once __DIR__ . '/../Kernel/Gamma.php';", $out['source']);
        self::assertCount(1, $out['changes']);
        self::assertSame('dir-relative', $out['changes'][0]['kind']);
    }
}

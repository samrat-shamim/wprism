<?php

declare(strict_types=1);

namespace Duo\Tests\Tooling;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pin the generated classmaps and the generator that produces them.
 *
 * What is actually at risk here is not the generator's cleverness — it is the
 * *currency* of two committed files. agent/duo-classmap.php and
 * cli/duo-classmap.php back an spl_autoload_register() fallback in
 * agent/duo.php and cli/duo; a map that has drifted from the source tree is
 * either a dead entry (an autoload that fails to open a file) or a missing
 * entry (a class the fallback silently declines to resolve). Neither shows up
 * in the offline corpus, because on the production load path every require
 * still runs and the fallback is never consulted. So the currency check lives
 * here and is a byte-compare: regenerate in memory, compare to what is
 * committed. `php tools/classmap-generate.php` is the fix for every failure
 * this class reports.
 *
 * tools/classmap-generate.php is required in-process for the derivation
 * helpers (its main() is behind the repo's usual SCRIPT_FILENAME guard, so
 * requiring it runs nothing). The subprocess tests are subprocesses for a
 * reason and not out of caution: proving that a *fresh* interpreter with the
 * map, the closure, and no other includes can resolve a class is only
 * meaningful when no other test has already declared it.
 */
final class ClassmapTest extends TestCase
{
    private static function repoRoot(): string
    {
        $env = getenv('DUO_REPO_ROOT');
        return is_string($env) && $env !== '' ? $env : dirname(__DIR__, 2);
    }

    public static function setUpBeforeClass(): void
    {
        require_once self::repoRoot() . '/tools/classmap-generate.php';
    }

    /** @return array{status:int,stdout:string,stderr:string} */
    private static function invoke(array $args, ?string $cwd = null): array
    {
        $cmd = escapeshellarg(PHP_BINARY);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg((string) $arg);
        }
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $process = proc_open($cmd, $descriptors, $pipes, $cwd ?? self::repoRoot());
        self::assertIsResource($process, "could not launch: $cmd");
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @return list<array{0:string,1:string,2:string}> root, scan dir, generated file */
    public static function targetProvider(): array
    {
        return [
            'agent' => ['agent', 'src', 'agent/duo-classmap.php'],
            'cli' => ['cli', 'src', 'cli/duo-classmap.php'],
        ];
    }

    #[DataProvider('targetProvider')]
    public function testCommittedMapIsByteIdenticalToAFreshGeneration(
        string $root,
        string $scanDir,
        string $generated
    ): void {
        $repo = self::repoRoot();
        $fresh = cm_render(cm_build_map($repo . '/' . $root, $scanDir), $root, $scanDir);
        self::assertFileExists($repo . '/' . $generated);
        self::assertSame(
            $fresh,
            (string) file_get_contents($repo . '/' . $generated),
            "$generated is stale; regenerate with: php tools/classmap-generate.php"
        );
    }

    /**
     * The --check mode is what a reviewer or a future gate actually runs, so
     * its exit status is pinned separately from the in-process byte-compare
     * above: the two could disagree if --check ever grew its own derivation.
     */
    public function testCheckModeAgreesWithTheCommittedFiles(): void
    {
        $result = self::invoke([self::repoRoot() . '/tools/classmap-generate.php', '--check']);
        self::assertSame(0, $result['status'], "--check reported drift:\n{$result['stdout']}{$result['stderr']}");
        self::assertStringContainsString('ok    agent/duo-classmap.php', $result['stdout']);
        self::assertStringContainsString('ok    cli/duo-classmap.php', $result['stdout']);
    }

    #[DataProvider('targetProvider')]
    public function testEveryDeclaredTypeAppearsExactlyOnceOrIsAnExplicitExclusion(
        string $root,
        string $scanDir,
        string $generated
    ): void {
        $repo = self::repoRoot();
        /** @var array<string,string> $map */
        $map = require $repo . '/' . $generated;

        $declaredIn = [];
        foreach (cm_php_files($repo . '/' . $root . '/' . $scanDir) as $file) {
            $relative = substr($file, strlen($repo . '/' . $root) + 1);
            foreach (cm_declared_types((string) file_get_contents($file)) as $fqcn) {
                $declaredIn[$fqcn][] = $relative;
            }
        }

        foreach ($declaredIn as $fqcn => $files) {
            if (isset(CM_EXCLUSIONS[$fqcn])) {
                self::assertArrayNotHasKey(
                    $fqcn,
                    $map,
                    "$fqcn is excluded by CM_EXCLUSIONS but still present in $generated"
                );
                continue;
            }
            self::assertArrayHasKey($fqcn, $map, "$fqcn is declared in $root/$scanDir but missing from $generated");
            self::assertSame(
                $files[0],
                $map[$fqcn],
                "$fqcn maps to {$map[$fqcn]} but is declared in {$files[0]}"
            );
        }

        foreach (array_keys($map) as $fqcn) {
            self::assertArrayHasKey(
                $fqcn,
                $declaredIn,
                "$generated maps $fqcn, which nothing under $root/$scanDir declares"
            );
        }
    }

    /**
     * Every exclusion must name a type that really exists and must carry a
     * reason. A stale exclusion is worse than none: it silently withholds a
     * class from the fallback forever, and nothing else in the tree would
     * notice.
     */
    public function testEveryExclusionIsRealAndDocumented(): void
    {
        $repo = self::repoRoot();
        $declared = [];
        foreach (self::targetProvider() as [$root, $scanDir, $_generated]) {
            foreach (cm_php_files($repo . '/' . $root . '/' . $scanDir) as $file) {
                foreach (cm_declared_types((string) file_get_contents($file)) as $fqcn) {
                    $declared[$fqcn] = true;
                }
            }
        }
        self::assertNotSame([], CM_EXCLUSIONS, 'the exclusion list is the audit trail; an empty one is suspicious');
        foreach (CM_EXCLUSIONS as $fqcn => $reason) {
            self::assertArrayHasKey($fqcn, $declared, "CM_EXCLUSIONS names $fqcn, which nothing declares any more");
            self::assertNotSame('', trim($reason), "CM_EXCLUSIONS[$fqcn] has no documented reason");
        }
        self::assertArrayHasKey(
            'Duo\\Cli',
            CM_EXCLUSIONS,
            'agent/src/Command/Cli.php ends in a top-level WP_CLI::add_command() call and must never be autoloadable'
        );
    }

    #[DataProvider('targetProvider')]
    public function testNoMapEntryPointsAtAMissingFile(string $root, string $scanDir, string $generated): void
    {
        $repo = self::repoRoot();
        /** @var array<string,string> $map */
        $map = require $repo . '/' . $generated;
        self::assertNotSame([], $map, "$generated is empty");
        foreach ($map as $fqcn => $relative) {
            self::assertFileExists(
                $repo . '/' . $root . '/' . $relative,
                "$generated maps $fqcn to a file that does not exist"
            );
        }
    }

    /**
     * The two maps are registered side by side in cli/duo, so a shared key
     * would make resolution depend on registration order.
     */
    public function testTheTwoMapsHaveDisjointKeys(): void
    {
        $repo = self::repoRoot();
        /** @var array<string,string> $agent */
        $agent = require $repo . '/agent/duo-classmap.php';
        /** @var array<string,string> $cli */
        $cli = require $repo . '/cli/duo-classmap.php';
        self::assertSame([], array_intersect_key($agent, $cli));
    }

    /**
     * The scanner is rooted at agent/src and cli/src. sandbox/tmp is the
     * repo's scratch directory and gitignored; a class file dropped there
     * must not reach either map, or a developer's throwaway file would
     * expire nine certifications the next time anybody regenerates.
     */
    public function testScratchFilesOutsideTheScannedRootsAreIgnored(): void
    {
        $repo = self::repoRoot();
        $dir = $repo . '/sandbox/tmp/classmap-test-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0700, true), "could not create $dir");
        $file = $dir . '/ScratchProbe.php';
        try {
            file_put_contents($file, "<?php\nnamespace Duo;\nfinal class ClassmapScratchProbe {}\n");
            foreach (self::targetProvider() as [$root, $scanDir, $_generated]) {
                $map = cm_build_map($repo . '/' . $root, $scanDir);
                self::assertArrayNotHasKey('Duo\\ClassmapScratchProbe', $map);
                foreach ($map as $relative) {
                    self::assertStringStartsWith($scanDir . '/', $relative);
                }
            }
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }

    /**
     * Determinism is what makes the byte-compare above a gate rather than a
     * coin flip, and it is also a certification-cost question: a generator
     * whose output depended on readdir order would expire nine subjects every
     * time it ran on a different filesystem. Two runs, two working
     * directories, byte-identical stdout.
     */
    public function testGeneratorOutputIsDeterministicAndCwdIndependent(): void
    {
        $script = self::repoRoot() . '/tools/classmap-generate.php';
        $first = self::invoke([$script, '--print']);
        $second = self::invoke([$script, '--print'], sys_get_temp_dir());
        self::assertSame(0, $first['status'], $first['stderr']);
        self::assertSame(0, $second['status'], $second['stderr']);
        self::assertSame($first['stdout'], $second['stdout']);
        self::assertStringContainsString('==> agent/duo-classmap.php', $first['stdout']);
        self::assertStringContainsString('==> cli/duo-classmap.php', $first['stdout']);
        self::assertStringNotContainsString(self::repoRoot(), $first['stdout'], 'the map must carry no host path');
    }

    /**
     * The end-to-end claim, in a fresh interpreter that has included nothing
     * but the map: the closure agent/duo.php registers resolves a class whose
     * file name does not match it (CommandRefusalException lives in
     * CommandRefusal.php, CompiledRepository in CompiledArtifact.php,
     * PublicationRecord in PublicationJournal.php). Those three are exactly
     * the shape a filename-derived autoloader would get wrong, which is why
     * they are the ones pinned.
     */
    public function testTheRegisteredClosureResolvesNonMatchingFileNames(): void
    {
        $agentDir = self::repoRoot() . '/agent';
        $probe = <<<'PHP'
$dir = $argv[1];
$map = require $dir . '/duo-classmap.php';
spl_autoload_register(static function (string $class) use ($map, $dir): void {
    if (!isset($map[$class])) { return; }
    $file = $dir . '/' . $map[$class];
    if (is_file($file)) { require_once $file; }
});
$names = ['Duo\CommandRefusalException', 'Duo\CompiledRepository', 'Duo\PublicationRecord'];
foreach ($names as $name) {
    if (class_exists($name, false)) { echo "PRELOADED $name\n"; continue; }
    echo (class_exists($name) ? 'RESOLVED ' : 'UNRESOLVED ') . $name . "\n";
}
echo 'AUTOLOADERS=' . count(spl_autoload_functions() ?: []) . "\n";
PHP;
        $result = self::invoke(['-r', $probe, $agentDir]);
        self::assertSame(0, $result['status'], $result['stderr']);
        self::assertSame('', $result['stderr']);
        self::assertSame(
            "RESOLVED Duo\\CommandRefusalException\n"
            . "RESOLVED Duo\\CompiledRepository\n"
            . "RESOLVED Duo\\PublicationRecord\n"
            . "AUTOLOADERS=1\n",
            $result['stdout']
        );
    }

    /**
     * Duo\Cli is the one type whose file has a top-level side effect
     * (WP_CLI::add_command at agent/src/Command/Cli.php's last line). Autoloading it
     * outside a WP-CLI runtime is an immediate fatal, so the fallback must
     * decline to resolve it and leave it to duo.php's WP_CLI-gated require.
     */
    public function testDuoCliIsNotResolvableThroughTheFallback(): void
    {
        $agentDir = self::repoRoot() . '/agent';
        $probe = <<<'PHP'
$dir = $argv[1];
$map = require $dir . '/duo-classmap.php';
spl_autoload_register(static function (string $class) use ($map, $dir): void {
    if (!isset($map[$class])) { return; }
    $file = $dir . '/' . $map[$class];
    if (is_file($file)) { require_once $file; }
});
echo (class_exists('Duo\Cli') ? 'RESOLVED' : 'UNRESOLVED') . "\n";
PHP;
        $result = self::invoke(['-r', $probe, $agentDir]);
        self::assertSame(0, $result['status'], $result['stderr']);
        self::assertSame("UNRESOLVED\n", $result['stdout']);
    }

    /**
     * The whole point of "additive": including agent/duo.php must still load
     * every file it loaded before, with the autoloader as a net underneath
     * rather than a replacement. 222 of the 224 agent/src files load eagerly
     * here (Cli.php is WP_CLI-gated, AdapterCertification.php is deliberately
     * lazy — see agent/src/Adapter/AdapterSources.php:898), and the two names that
     * lazy file declares (AdapterCertification and its companion
     * SupersededSiteAdapterCertificate exception) are left for the fallback.
     */
    public function testDuoPhpStillLoadsEagerlyWithExactlyOneExtraAutoloader(): void
    {
        $repo = self::repoRoot();
        $probe = <<<'PHP'
define('ABSPATH', sys_get_temp_dir() . '/duo-classmap-probe/');
$before = count(spl_autoload_functions() ?: []);
require $argv[1] . '/agent/duo.php';
$map = require $argv[1] . '/agent/duo-classmap.php';
$undeclared = [];
foreach (array_keys($map) as $fqcn) {
    if (!class_exists($fqcn, false) && !interface_exists($fqcn, false)
        && !trait_exists($fqcn, false) && !enum_exists($fqcn, false)) {
        $undeclared[] = $fqcn;
    }
}
echo 'AUTOLOADERS=' . ($before + 1) . '/' . count(spl_autoload_functions() ?: []) . "\n";
echo 'VERSION=' . DUO_AGENT_VERSION . '/' . DUO_SPEC_VERSION . "\n";
echo 'UNDECLARED=' . implode(',', $undeclared) . "\n";
PHP;
        $result = self::invoke(['-r', $probe, $repo]);
        self::assertSame(0, $result['status'], $result['stderr']);
        self::assertSame('', $result['stderr']);
        self::assertSame(
            "AUTOLOADERS=1/1\n"
            . "VERSION=0.5.0/2\n"
            . "UNDECLARED=Duo\\AdapterCertification,Duo\\SupersededSiteAdapterCertificate\n",
            $result['stdout']
        );
    }

    /**
     * Two files declaring one name is a refusal, not a last-writer-wins
     * merge. Proven against a throwaway root rather than the real tree, so
     * the assertion does not depend on anybody's source staying broken.
     */
    public function testDuplicateDeclarationsAreRefused(): void
    {
        $dir = self::repoRoot() . '/sandbox/tmp/classmap-dup-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir . '/src', 0700, true), "could not create $dir/src");
        try {
            file_put_contents($dir . '/src/A.php', "<?php\nnamespace Duo\\Probe;\nclass Twin {}\n");
            file_put_contents($dir . '/src/B.php', "<?php\nnamespace Duo\\Probe;\nclass Twin {}\n");
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Duo\\Probe\\Twin is declared twice');
            cm_build_map($dir, 'src');
        } finally {
            @unlink($dir . '/src/A.php');
            @unlink($dir . '/src/B.php');
            @rmdir($dir . '/src');
            @rmdir($dir);
        }
    }

    /**
     * A file with no namespace and no type declaration is skipped, not an
     * error: cli/src/Refresh/RefreshPlanCompile.php is a worker script that executes
     * on include and must never be reachable by autoload.
     */
    public function testFilesWithoutTypeDeclarationsAreSkipped(): void
    {
        $repo = self::repoRoot();
        $worker = $repo . '/cli/src/Refresh/RefreshPlanCompile.php';
        self::assertFileExists($worker);
        self::assertSame([], cm_declared_types((string) file_get_contents($worker)));
        /** @var array<string,string> $cli */
        $cli = require $repo . '/cli/duo-classmap.php';
        self::assertNotContains('src/RefreshPlanCompile.php', array_values($cli));
    }

    /**
     * The tokenizer cases a regex would get wrong, pinned directly.
     */
    public function testDeclarationScannerIgnoresClassConstantsAndAnonymousClasses(): void
    {
        $source = <<<'PHP'
<?php
namespace Duo\Probe;
$a = Something::class;
$b = new class { public function f(): void {} };
final class Real {}
interface Contract {}
trait Shared {}
enum Mode: string { case On = 'on'; }
PHP;
        self::assertSame(
            ['Duo\\Probe\\Real', 'Duo\\Probe\\Contract', 'Duo\\Probe\\Shared', 'Duo\\Probe\\Mode'],
            cm_declared_types($source)
        );
    }
}

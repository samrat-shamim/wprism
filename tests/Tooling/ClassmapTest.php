<?php

declare(strict_types=1);

namespace WPrism\Tests\Tooling;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pin the generated classmaps and the generator that produces them.
 *
 * What is actually at risk here is not the generator's cleverness — it is the
 * *currency* of two committed files. agent/wprism-classmap.php and
 * cli/wprism-classmap.php back an spl_autoload_register() fallback in
 * agent/wprism.php and cli/wprism; a map that has drifted from the source tree is
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
        $env = getenv('WPRISM_REPO_ROOT');
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
            'agent' => ['agent', 'src', 'agent/wprism-classmap.php'],
            'cli' => ['cli', 'src', 'cli/wprism-classmap.php'],
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
        self::assertStringContainsString('ok    agent/wprism-classmap.php', $result['stdout']);
        self::assertStringContainsString('ok    cli/wprism-classmap.php', $result['stdout']);
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
            'WPrism\\Cli',
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
     * The two maps are registered side by side in cli/wprism, so a shared key
     * would make resolution depend on registration order.
     */
    public function testTheTwoMapsHaveDisjointKeys(): void
    {
        $repo = self::repoRoot();
        /** @var array<string,string> $agent */
        $agent = require $repo . '/agent/wprism-classmap.php';
        /** @var array<string,string> $cli */
        $cli = require $repo . '/cli/wprism-classmap.php';
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
            file_put_contents($file, "<?php\nnamespace WPrism;\nfinal class ClassmapScratchProbe {}\n");
            foreach (self::targetProvider() as [$root, $scanDir, $_generated]) {
                $map = cm_build_map($repo . '/' . $root, $scanDir);
                self::assertArrayNotHasKey('WPrism\\ClassmapScratchProbe', $map);
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
        self::assertStringContainsString('==> agent/wprism-classmap.php', $first['stdout']);
        self::assertStringContainsString('==> cli/wprism-classmap.php', $first['stdout']);
        self::assertStringNotContainsString(self::repoRoot(), $first['stdout'], 'the map must carry no host path');
    }

    /**
     * The end-to-end claim, in a fresh interpreter that has included nothing
     * but the map: the closure agent/wprism.php registers resolves a class whose
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
$map = require $dir . '/wprism-classmap.php';
spl_autoload_register(static function (string $class) use ($map, $dir): void {
    if (!isset($map[$class])) { return; }
    $file = $dir . '/' . $map[$class];
    if (is_file($file)) { require_once $file; }
});
$names = ['WPrism\CommandRefusalException', 'WPrism\CompiledRepository', 'WPrism\PublicationRecord'];
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
            "RESOLVED WPrism\\CommandRefusalException\n"
            . "RESOLVED WPrism\\CompiledRepository\n"
            . "RESOLVED WPrism\\PublicationRecord\n"
            . "AUTOLOADERS=1\n",
            $result['stdout']
        );
    }

    /**
     * WPrism\Cli is the one type whose file has a top-level side effect
     * (WP_CLI::add_command at agent/src/Command/Cli.php's last line). Autoloading it
     * outside a WP-CLI runtime is an immediate fatal, so the fallback must
     * decline to resolve it and leave it to wprism.php's WP_CLI-gated require.
     */
    public function testWPrismCliIsNotResolvableThroughTheFallback(): void
    {
        $agentDir = self::repoRoot() . '/agent';
        $probe = <<<'PHP'
$dir = $argv[1];
$map = require $dir . '/wprism-classmap.php';
spl_autoload_register(static function (string $class) use ($map, $dir): void {
    if (!isset($map[$class])) { return; }
    $file = $dir . '/' . $map[$class];
    if (is_file($file)) { require_once $file; }
});
echo (class_exists('WPrism\Cli') ? 'RESOLVED' : 'UNRESOLVED') . "\n";
PHP;
        $result = self::invoke(['-r', $probe, $agentDir]);
        self::assertSame(0, $result['status'], $result['stderr']);
        self::assertSame("UNRESOLVED\n", $result['stdout']);
    }

    /**
     * The whole point of "additive": including agent/wprism.php must still load
     * every file it loaded before, with the autoloader as a net underneath
     * rather than a replacement. Every mapped name but five is declared
     * eagerly here; those five are exactly the five
     * AdapterCertification.php declares, and that file is deliberately lazy —
     * only a record carrying a signed external claim requires it
     * (agent/src/Adapter/AdapterSources.php:838, :1091, :3899). WPrism\Cli is not
     * in this list because it is not in the map at all (see
     * testWPrismCliIsNotResolvableThroughTheFallback above).
     *
     * The list is spelled out rather than counted: WP-1.1 added two withdrawal
     * exceptions to that file and G2-FIXES C3 added the third, and a count
     * would have absorbed each of them silently where this assertion names
     * them.
     */
    public function testWPrismPhpStillLoadsEagerlyWithExactlyOneExtraAutoloader(): void
    {
        $repo = self::repoRoot();
        $probe = <<<'PHP'
define('ABSPATH', sys_get_temp_dir() . '/wprism-classmap-probe/');
$before = count(spl_autoload_functions() ?: []);
require $argv[1] . '/agent/wprism.php';
$map = require $argv[1] . '/agent/wprism-classmap.php';
$undeclared = [];
foreach (array_keys($map) as $fqcn) {
    if (!class_exists($fqcn, false) && !interface_exists($fqcn, false)
        && !trait_exists($fqcn, false) && !enum_exists($fqcn, false)) {
        $undeclared[] = $fqcn;
    }
}
echo 'AUTOLOADERS=' . ($before + 1) . '/' . count(spl_autoload_functions() ?: []) . "\n";
echo 'VERSION=' . WPRISM_AGENT_VERSION . '/' . WPRISM_SPEC_VERSION . "\n";
echo 'UNDECLARED=' . implode(',', $undeclared) . "\n";
PHP;
        $result = self::invoke(['-r', $probe, $repo]);
        self::assertSame(0, $result['status'], $result['stderr']);
        self::assertSame('', $result['stderr']);
        // The VERSION line proves `wprism.php` EXECUTED rather than merely parsed
        // — the constants only exist at runtime — so what it must compare
        // against is the source of record, not a literal. WP-4.12 measured the
        // cost of the literal: an assertion pinned to the previous version
        // goes red on a correct bump for a
        // reason unrelated to its own subject (one extra autoloader, no
        // eagerly-undeclared class) trains a reader to retype the number rather
        // than read the failure. Same regex and same reason as
        // `AdapterCertify::boot()` (:1463-1481), `tools/wire-surface.php`
        // (:128-136) and `sandbox/tests/lib/agent_version.php`.
        $source = (string) file_get_contents($repo . '/agent/wprism.php');
        self::assertSame(1, preg_match("/define\('WPRISM_AGENT_VERSION', '([^']+)'\)/", $source, $agent));
        self::assertSame(1, preg_match("/define\('WPRISM_SPEC_VERSION', ([0-9]+)\)/", $source, $spec));
        self::assertSame(
            "AUTOLOADERS=1/1\n"
            . "VERSION={$agent[1]}/{$spec[1]}\n"
            . 'UNDECLARED=WPrism\\AdapterCertification,WPrism\\StalePlatformSiteAdapterCertificate,'
            . 'WPrism\\SupersededSiteAdapterCertificate,WPrism\\SupersededWireSiteAdapterCertificate,'
            . "WPrism\\WithdrawnAuthoritySiteAdapterCertificate\n",
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
            file_put_contents($dir . '/src/A.php', "<?php\nnamespace WPrism\\Probe;\nclass Twin {}\n");
            file_put_contents($dir . '/src/B.php', "<?php\nnamespace WPrism\\Probe;\nclass Twin {}\n");
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('WPrism\\Probe\\Twin is declared twice');
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
        $cli = require $repo . '/cli/wprism-classmap.php';
        self::assertNotContains('src/RefreshPlanCompile.php', array_values($cli));
    }

    /**
     * The tokenizer cases a regex would get wrong, pinned directly.
     */
    public function testDeclarationScannerIgnoresClassConstantsAndAnonymousClasses(): void
    {
        $source = <<<'PHP'
<?php
namespace WPrism\Probe;
$a = Something::class;
$b = new class { public function f(): void {} };
final class Real {}
interface Contract {}
trait Shared {}
enum Mode: string { case On = 'on'; }
PHP;
        self::assertSame(
            ['WPrism\\Probe\\Real', 'WPrism\\Probe\\Contract', 'WPrism\\Probe\\Shared', 'WPrism\\Probe\\Mode'],
            cm_declared_types($source)
        );
    }
}

<?php

declare(strict_types=1);

namespace Duo\Tests\Tooling;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pin tools/doctor.sh against the checkout it is meant to diagnose.
 *
 * WHY THESE ASSERTIONS AND NOT MORE
 * ---------------------------------
 * doctor.sh is a diagnosis tool, so the only thing worth pinning mechanically
 * is that it agrees with reality on a checkout that IS healthy: a doctor that
 * cries wolf gets ignored, and a doctor that reports green while
 * `make release-gate` is red is worse than no doctor. Four checks are the ones
 * whose green answer is load-bearing for every other tool in tools/ --
 * full clone, certification commits present, release-gate green, a usable PHP
 * -- so those four are asserted by name. The rest of the output (docker, gh,
 * pair budget) is environment-dependent by design and is deliberately NOT
 * asserted: it is WARN-only in the script for the same reason.
 *
 * The script is run out of process (proc_open) because it is a CLI entry point
 * that `cd`s to its own repo root and exits with a status; there is nothing to
 * require in-process. It is invoked with `--no-pairs` so the suite never takes
 * the host-wide sandbox pair lock or talks to a docker daemon, and
 * `--no-color` so assertions match literal text rather than ANSI runs.
 *
 * A red result here is a real finding about the checkout, not a flaky test:
 * the usual cause is an untracked file left under agent/, cli/ or sandbox/bin/
 * (which expires all nine certifications) or a stale capability registry.
 */
final class DoctorTest extends TestCase
{
    /** @var array{status:int,stdout:string,stderr:string}|null */
    private static ?array $run = null;

    private static function repoRoot(): string
    {
        $env = getenv('DUO_REPO_ROOT');

        return is_string($env) && $env !== '' ? $env : dirname(__DIR__, 2);
    }

    /**
     * @param list<string> $args
     *
     * @return array{status:int,stdout:string,stderr:string}
     */
    private static function bash(array $args): array
    {
        $repo = self::repoRoot();
        $cmd = 'bash';
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $pipes = [];
        $process = proc_open(
            $cmd,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            // NO_COLOR belts-and-braces with --no-color; TERM=dumb keeps any
            // nested tool from probing terminfo for a tty that is not there.
            ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => getenv('HOME') ?: '/tmp', 'NO_COLOR' => '1', 'TERM' => 'dumb']
        );
        self::assertIsResource($process, 'could not launch bash');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * One real doctor run shared by every assertion below. Doctor shells out to
     * git, php and composer's vendor tree; running it once per test method
     * would multiply that cost for no extra coverage.
     *
     * @return array{status:int,stdout:string,stderr:string}
     */
    private static function doctor(): array
    {
        if (self::$run === null) {
            self::$run = self::bash([self::repoRoot() . '/tools/doctor.sh', '--no-pairs', '--no-color']);
        }

        return self::$run;
    }

    public function testTheScriptParses(): void
    {
        $result = self::bash(['-n', self::repoRoot() . '/tools/doctor.sh']);

        self::assertSame(0, $result['status'], 'bash -n rejected tools/doctor.sh: ' . $result['stderr']);
        self::assertSame('', trim($result['stderr']));
    }

    public function testDoctorExitsZeroOnAHealthyCheckout(): void
    {
        $result = self::doctor();

        self::assertSame(
            0,
            $result['status'],
            'doctor.sh reported a FAIL on this checkout -- that is a finding about the checkout, '
            . "not about the test:\n" . $result['stdout'] . $result['stderr']
        );
        // Not a substring search: the trailing tally line always spells
        // "FAIL 0". Only a line that BEGINS with FAIL is a failed check.
        self::assertNull(
            self::lineStartingWith($result['stdout'], 'FAIL'),
            'a green run must print no FAIL line'
        );
    }

    /**
     * The four load-bearing checks, each asserted as an `ok` line so that a
     * downgrade to WARN (which would keep the exit status at 0) still fails.
     *
     * @return array<string,array{0:string}>
     */
    public static function loadBearingChecks(): array
    {
        return [
            'full clone' => ['full clone (git rev-parse --is-shallow-repository = false)'],
            'certification commits' => ['every certification commit resolves'],
            'release gate' => ['make release-gate: capability registry check:'],
            'php extension sodium' => ['php extension: sodium'],
        ];
    }

    #[DataProvider('loadBearingChecks')]
    public function testLoadBearingCheckIsReportedOk(string $needle): void
    {
        $line = self::lineContaining(self::doctor()['stdout'], $needle);

        self::assertNotNull($line, "doctor.sh printed no line containing '$needle'");
        self::assertStringStartsWith('ok', $line, "expected an ok line, got: $line");
    }

    /**
     * The PHP version line is asserted separately because it is legitimately
     * either colour: `ok` inside the certified 8.3.0-<8.4 window from
     * docs/compatibility-baseline.json, or `WARN` on a local 8.4/8.5 engine,
     * which is forward coverage rather than the pinned target. What must never
     * happen is silence -- an unreported engine version is how a Deprecated
     * that only the newer engine emits reaches the diagnostics guard unexplained.
     */
    public function testPhpVersionIsAlwaysReported(): void
    {
        $line = self::lineContaining(self::doctor()['stdout'], 'PHP ' . PHP_VERSION);

        self::assertNotNull($line, 'doctor.sh did not report the running PHP version');
        self::assertMatchesRegularExpression('/^(ok|WARN)\s/', $line);
        if (version_compare(PHP_VERSION, '8.3.0', '>=') && version_compare(PHP_VERSION, '8.4.0', '<')) {
            self::assertStringStartsWith('ok', $line, 'a certified-range engine must not warn');
        } else {
            self::assertStringStartsWith('WARN', $line, 'an out-of-range engine must warn');
            self::assertStringContainsString('compatibility-baseline.json', self::doctor()['stdout']);
        }
    }

    public function testEveryFailAndWarnCarriesARemedy(): void
    {
        $lines = explode("\n", self::doctor()['stdout']);
        foreach ($lines as $index => $line) {
            if (!str_starts_with($line, 'WARN') && !str_starts_with($line, 'FAIL')) {
                continue;
            }
            $tail = implode("\n", array_slice($lines, $index + 1, 12));
            self::assertStringContainsString(
                'remedy:',
                $tail,
                "no remedy printed under: $line"
            );
        }
    }

    public function testTheCheatSheetNamesTheCanonicalGate(): void
    {
        $stdout = self::doctor()['stdout'];

        // The whole point of the cheat sheet is that the fast tools never read
        // as a substitute for the gate, so both must appear together.
        self::assertStringContainsString('php tools/offline.php -j8', $stdout);
        self::assertStringContainsString('php tools/cert-impact.php', $stdout);
        self::assertStringContainsString('make regress-offline-all', $stdout);
        self::assertStringContainsString('canonical merge gate', $stdout);
        self::assertStringContainsString('docs/dev-setup.md', $stdout);
    }

    /**
     * Pin the closure-hygiene split against a synthetic repo: a TRACKED,
     * unstaged modification inside agent/ must WARN ("a certification round
     * is due"), never FAIL (which reads as "delete your own in-progress
     * edit" — the bug this test guards against), while a genuinely
     * UNTRACKED file inside agent/ must still FAIL, because that really is
     * the filesystem-walk hazard the check exists to catch.
     *
     * This copies the real, unmodified tools/doctor.sh into the synthetic
     * repo and runs it there (doctor.sh derives REPO_ROOT from its own
     * script location via BASH_SOURCE, so cwd alone cannot repoint it at a
     * different tree) rather than duplicating its grep pattern here, so a
     * regression in the shipped filter is what this test actually observes.
     * Everything outside the closure-hygiene section is expected to be red
     * (no evidence.json, no vendor/, …) in a bare synthetic repo and is
     * deliberately not asserted.
     */
    public function testClosureHygieneWarnsOnTrackedModificationButFailsOnUntracked(): void
    {
        $root = self::makeSyntheticRepoWithDoctor();

        try {
            $result = self::bash([$root . '/tools/doctor.sh', '--no-color', '--no-pairs']);
            $section = self::extractSection($result['stdout'], 'certification closure hygiene');
            self::assertNotNull($section, 'doctor.sh printed no closure-hygiene section: ' . $result['stdout']);

            $failLine = self::lineContaining($section, 'untracked/ignored files inside the certification closure');
            $warnLine = self::lineContaining($section, 'modified closure files');
            self::assertNotNull($failLine, "no FAIL line for the untracked file:\n$section");
            self::assertNotNull($warnLine, "no WARN line for the tracked modification:\n$section");
            self::assertStringStartsWith('FAIL', $failLine);
            self::assertStringStartsWith('WARN', $warnLine);

            // The untracked file is named under the FAIL block ...
            $failIndex = strpos($section, $failLine);
            $warnIndex = strpos($section, $warnLine);
            self::assertNotFalse($failIndex);
            self::assertNotFalse($warnIndex);
            $failBlock = substr($section, $failIndex, $warnIndex - $failIndex);
            self::assertStringContainsString('agent/src/Stray.php', $failBlock);
            // ... and the tracked modification is never folded into it: that
            // was exactly the false FAIL this fix removes.
            self::assertStringNotContainsString('A.php', $failBlock);

            $warnBlock = substr($section, $warnIndex);
            self::assertStringContainsString('agent/src/A.php', $warnBlock);
        } finally {
            self::rrmdir($root);
        }
    }

    private static function makeSyntheticRepoWithDoctor(): string
    {
        $root = (string) tempnam(sys_get_temp_dir(), 'duo-doctor-test-');
        unlink($root);
        mkdir($root . '/agent/src', 0o777, true);
        mkdir($root . '/cli/src', 0o777, true);
        mkdir($root . '/sandbox/bin', 0o777, true);
        mkdir($root . '/tools', 0o777, true);

        file_put_contents($root . '/agent/src/A.php', "<?php\n// baseline\n");
        self::assertTrue(copy(self::repoRoot() . '/tools/doctor.sh', $root . '/tools/doctor.sh'));

        self::git($root, ['init', '--quiet']);
        self::git($root, ['add', '-A']);
        self::git($root, ['-c', 'user.email=doctor-test@example.invalid', '-c', 'user.name=doctor-test',
            'commit', '--quiet', '-m', 'baseline']);

        // A tracked modification, left unstaged: the case the FAIL/WARN
        // split exists for.
        file_put_contents($root . '/agent/src/A.php', "<?php\n// baseline\n// modified\n");
        // A file git has never seen: the case the FAIL branch must still
        // catch — this test would be worthless if the fix had simply
        // silenced the FAIL branch outright.
        file_put_contents($root . '/agent/src/Stray.php', "<?php\n// never committed\n");

        return $root;
    }

    /** @param list<string> $args */
    private static function git(string $root, array $args): void
    {
        $cmd = 'git -C ' . escapeshellarg($root);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        $pipes = [];
        $process = proc_open(
            $cmd,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => getenv('HOME') ?: '/tmp']
        );
        self::assertIsResource($process, 'could not launch git');
        fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($process);
        self::assertSame(0, $status, 'git ' . implode(' ', $args) . " failed: $stderr");
    }

    private static function rrmdir(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            if (file_exists($path)) {
                unlink($path);
            }
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::rrmdir($path . '/' . $entry);
        }
        rmdir($path);
    }

    private static function extractSection(string $stdout, string $heading): ?string
    {
        $marker = '== ' . $heading . ' ==';
        $start = strpos($stdout, $marker);
        if ($start === false) {
            return null;
        }
        $next = strpos($stdout, "\n== ", $start + strlen($marker));

        return $next === false ? substr($stdout, $start) : substr($stdout, $start, $next - $start);
    }

    public function testHelpIsFreeAndAnUnknownOptionIsRejected(): void
    {
        $help = self::bash([self::repoRoot() . '/tools/doctor.sh', '--help']);
        self::assertSame(0, $help['status']);
        self::assertStringContainsString('usage: bash tools/doctor.sh', $help['stdout']);

        $bogus = self::bash([self::repoRoot() . '/tools/doctor.sh', '--not-an-option']);
        self::assertSame(2, $bogus['status'], 'a usage error must be distinguishable from a check failure (1)');
        self::assertStringContainsString("unknown option '--not-an-option'", $bogus['stderr']);
    }

    private static function lineStartingWith(string $haystack, string $prefix): ?string
    {
        foreach (explode("\n", $haystack) as $line) {
            if (str_starts_with($line, $prefix)) {
                return $line;
            }
        }

        return null;
    }

    private static function lineContaining(string $haystack, string $needle): ?string
    {
        foreach (explode("\n", $haystack) as $line) {
            if (str_contains($line, $needle)) {
                return $line;
            }
        }

        return null;
    }
}

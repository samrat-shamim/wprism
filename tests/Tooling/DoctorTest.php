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
 * `make release-gate` is red is worse than no doctor. Three checks are the ones
 * whose green answer is load-bearing for every other tool in tools/ --
 * full clone, release-gate green, a usable PHP -- so those three are asserted
 * by name. The rest of the output (docker, gh, pair budget) is
 * environment-dependent by design and is deliberately NOT asserted: it is
 * WARN-only in the script for the same reason.
 *
 * The script is run out of process (proc_open) because it is a CLI entry point
 * that `cd`s to its own repo root and exits with a status; there is nothing to
 * require in-process. It is invoked with `--no-pairs` so the suite never takes
 * the host-wide sandbox pair lock or talks to a docker daemon, and
 * `--no-color` so assertions match literal text rather than ANSI runs.
 *
 * A red result here is a real finding about the checkout, not a flaky test:
 * the usual cause is generated output (docs/capabilities.md, the classmap) that
 * has drifted from the source `make release-gate` regenerates it from.
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
     * The three load-bearing checks, each asserted as an `ok` line so that a
     * downgrade to WARN (which would keep the exit status at 0) still fails.
     *
     * The release-gate needle spans doctor.sh's own label and the first words of
     * `tools/capability-doc.php --check`'s output, so a gate silently rewired to
     * a different tool fails here. It stops before that line's remainder, which
     * states the specific agreement reached and is that tool's to reword.
     *
     * @return array<string,array{0:string}>
     */
    public static function loadBearingChecks(): array
    {
        return [
            'full clone' => ['full clone (git rev-parse --is-shallow-repository = false)'],
            'release gate' => ['make release-gate: capability doc check:'],
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
        self::assertStringContainsString('make regress-offline-all', $stdout);
        self::assertStringContainsString('canonical merge gate', $stdout);
        self::assertStringContainsString('docs/dev-setup.md', $stdout);
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

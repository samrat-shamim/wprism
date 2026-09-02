<?php

declare(strict_types=1);

namespace WPrism\Tests\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the two properties that make a PHPStan baseline safe to keep.
 *
 * A baseline is a debt ledger. Left unguarded it does the opposite of what it
 * was adopted for: new violations get appended on the next `--generate-baseline`
 * and the analyser stays green forever. Two assertions turn it back into a
 * ratchet.
 *
 *  1. ENTRY COUNT <= CEILING. The ceiling lives in a one-integer fixture that a
 *     human has to edit, so raising the debt is a reviewable diff line rather
 *     than a side effect of regenerating. Lowering it is free.
 *
 *  2. EVERY `path:` STILL EXISTS. PHPStan's own `reportUnmatchedIgnoredErrors:
 *     true` (set in phpstan.neon.dist) already fails on a stale *message*, but
 *     only while that file is inside `paths`. A file that was deleted, renamed,
 *     or moved out of the analysed set takes its baseline entries with it into
 *     silence — and those entries then inflate the ceiling's headroom. This
 *     check is the filesystem half that PHPStan cannot do from inside a run.
 *
 * The third test here is unrelated to PHPStan and is the reason this whole
 * toolchain is allowed to exist at the repo root at all: the drop-in must stay
 * dependency-free. Adoption assembles package payloads inside the staged agent
 * and archives only `agent recovery` — no vendor/, no composer.json, no
 * autoloader ever reaches the site. If a drop-in file ever grew a
 * `require vendor/autoload.php`, every adoption would fatal at load time on a
 * real site while every developer machine stayed green, because only here is
 * vendor/ present. That failure mode is invisible to the analyser and to the
 * offline corpus, so it is asserted directly against the source bytes.
 */
#[CoversNothing]
final class PhpstanBaselineRatchetTest extends TestCase
{
    private const BASELINE = WPRISM_REPO_ROOT . '/phpstan-baseline.neon';
    private const CEILING = WPRISM_REPO_ROOT . '/tests/Tooling/fixtures/phpstan-baseline-ceiling.txt';
    private const COMPOSER = WPRISM_REPO_ROOT . '/composer.json';

    /**
     * Concrete needles only. The word "composer" on its own appears in
     * cli/wprism's and cli/README's prose *documenting* this very invariant
     * ("dependency-free PHP 8+ … no composer"), so matching it would fail on
     * the documentation of the rule it enforces.
     *
     * @var list<string>
     */
    private const AUTOLOAD_NEEDLES = [
        'vendor/autoload',
        'Composer\\Autoload',
        'ComposerAutoloaderInit',
    ];

    /**
     * The drop-in's OWN classmap autoloader (owner ruling D3, issue #3481) is the
     * one sanctioned `spl_autoload_register` in shipped code: agent/wprism.php and
     * cli/wprism register a closure over the committed, generated
     * wprism-classmap.php files (plain project source, not a vendored library;
     * every existing require_once is retained). Any other registration site
     * in the drop-in is a new autoloader and fails this test.
     *
     * @var list<string>
     */
    private const SANCTIONED_SPL_AUTOLOAD_SITES = [
        'agent/wprism.php',
        'cli/wprism',
    ];

    public function testBaselineEntryCountIsAtOrBelowTheCommittedCeiling(): void
    {
        $baseline = $this->baselineSource();
        $entries = $this->countEntries($baseline);
        $suppressed = $this->countSuppressedErrors($baseline);
        $ceiling = $this->ceiling();

        $this->assertLessThanOrEqual(
            $ceiling,
            $entries,
            sprintf(
                'phpstan-baseline.neon has grown to %d ignoreErrors entries (%d suppressed errors); '
                . "the committed ceiling is %d.\n"
                . 'Fix the new errors, or raise the integer in %s in the same commit that adds them.',
                $entries,
                $suppressed,
                $ceiling,
                'tests/Tooling/fixtures/phpstan-baseline-ceiling.txt'
            )
        );
    }

    public function testPhpstanAlwaysAnalysesCliArgvAsRegistered(): void
    {
        $composer = json_decode(
            (string) file_get_contents(self::COMPOSER),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame(
            '@php -d register_argc_argv=1 vendor/bin/phpstan analyse --memory-limit=1G',
            $composer['scripts']['stan'] ?? null,
            'PHPStan must not inherit register_argc_argv from the developer php.ini: Homebrew PHP 8.3 defaults '
            . 'to on while PHP 8.5 defaults to off, which otherwise makes five baseline entries alternate '
            . 'between required and stale.'
        );
    }

    public function testBaselineIsNotSilentlyOverProvisioned(): void
    {
        // A ceiling far above the real count is the same failure as no ceiling:
        // it buys headroom nobody reviewed. Ten entries of slack is enough to
        // land a legitimate refactor without a fixture edit in the same commit.
        $entries = $this->countEntries($this->baselineSource());

        $this->assertLessThanOrEqual(
            $entries + 10,
            $this->ceiling(),
            'The baseline ceiling has drifted more than 10 entries above the actual baseline. '
            . 'Lower it to the current count so the ratchet keeps its grip.'
        );
    }

    public function testEveryBaselinePathStillExistsOnDisk(): void
    {
        $paths = $this->baselinePaths();
        $this->assertNotSame([], $paths, 'The baseline declares no paths at all — is it truncated?');

        $missing = [];
        foreach ($paths as $path) {
            if (!is_file(WPRISM_REPO_ROOT . '/' . $path)) {
                $missing[] = $path;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "phpstan-baseline.neon suppresses errors for files that no longer exist:\n  "
            . implode("\n  ", $missing)
            . "\nRegenerate the baseline (vendor/bin/phpstan analyse --generate-baseline "
            . 'phpstan-baseline.neon) and lower the ceiling to match.'
        );
    }

    public function testBaselinePathsAreRepoRelativeAndInsideAnalysedRoots(): void
    {
        $roots = ['agent/', 'cli/', 'recovery/', 'scripts/', 'tools/', 'tests/'];

        foreach ($this->baselinePaths() as $path) {
            $this->assertStringStartsNotWith('/', $path, "Baseline path is absolute and not portable: $path");
            $this->assertStringNotContainsString('..', $path, "Baseline path escapes the repo: $path");

            $inRoot = false;
            foreach ($roots as $root) {
                if (str_starts_with($path, $root)) {
                    $inRoot = true;
                    break;
                }
            }
            $this->assertTrue($inRoot, "Baseline path is outside every analysed root: $path");
        }
    }

    #[DataProvider('dropInFiles')]
    public function testDropInSourceNeverReferencesAnAutoloader(string $relative): void
    {
        $source = self::codeWithoutComments((string) file_get_contents(WPRISM_REPO_ROOT . '/' . $relative));

        // Code, not commentary: the generated maps and the loaders describe the
        // fallback in docblocks; only a real registration counts.
        if (str_contains($source, 'spl_autoload_register(')) {
            $this->assertContains(
                $relative,
                self::SANCTIONED_SPL_AUTOLOAD_SITES,
                sprintf(
                    '%s registers an autoloader. Only agent/wprism.php and cli/wprism may (the additive '
                    . 'classmap over wprism-classmap.php, owner ruling D3); anything else is a new loading '
                    . 'mechanism inside the dependency-free drop-in and needs its own ruling.',
                    $relative
                )
            );
            $this->assertStringContainsString(
                'wprism-classmap.php',
                $source,
                "$relative registers an autoloader that is not the committed classmap"
            );
        }

        foreach (self::AUTOLOAD_NEEDLES as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $source,
                sprintf(
                    '%s references "%s". The drop-in ships as the staged `agent recovery` payload '
                    . '(cli/src/Onboarding/Adopt.php) and never receives vendor/, so this would fatal on every '
                    . 'adopted site while staying green here.',
                    $relative,
                    $needle
                )
            );
        }
    }

    /** Strip comments (and doc-comments) so prose about an invariant never trips it. */
    private static function codeWithoutComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    /** @return iterable<string, array{string}> */
    public static function dropInFiles(): iterable
    {
        $root = dirname(__DIR__, 2);
        $seen = [];

        foreach (['agent', 'cli', 'recovery'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                // cli/wprism is the extensionless `#!/usr/bin/env php` entrypoint;
                // it is drop-in-adjacent source and must obey the same rule.
                if ($file->getExtension() !== 'php' && $file->getFilename() !== 'wprism') {
                    continue;
                }
                $seen[substr($file->getPathname(), strlen($root) + 1)] = true;
            }
        }

        ksort($seen);
        foreach (array_keys($seen) as $relative) {
            yield $relative => [$relative];
        }
    }

    private function baselineSource(): string
    {
        $this->assertFileExists(self::BASELINE, 'phpstan-baseline.neon is missing; phpstan.neon.dist includes it.');

        return (string) file_get_contents(self::BASELINE);
    }

    private function ceiling(): int
    {
        $this->assertFileExists(self::CEILING);
        $raw = (string) file_get_contents(self::CEILING);

        $value = null;
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $value = $line;
            break;
        }

        $this->assertNotNull($value, 'phpstan-baseline-ceiling.txt holds no integer.');
        $this->assertMatchesRegularExpression('/^\d+$/', $value, 'The ceiling fixture must be a bare integer.');

        return (int) $value;
    }

    private function countEntries(string $baseline): int
    {
        return preg_match_all('/^\s*message:\s/m', $baseline);
    }

    private function countSuppressedErrors(string $baseline): int
    {
        preg_match_all('/^\s*count:\s*(\d+)\s*$/m', $baseline, $matches);

        return array_sum(array_map(intval(...), $matches[1]));
    }

    /** @return list<string> */
    private function baselinePaths(): array
    {
        preg_match_all('/^\s*path:\s*(\S.*?)\s*$/m', $this->baselineSource(), $matches);

        return array_map(static fn (string $path): string => trim($path, "'\""), $matches[1]);
    }
}

<?php

declare(strict_types=1);

namespace WPrism\Tests\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** The greenfield rename has one identity; tracked history is out of scope. */
#[CoversNothing]
final class BrandIdentityTest extends TestCase
{
    public function testTrackedTreeContainsNoRetiredProductIdentityOrIssueAliases(): void
    {
        $violations = [];
        foreach (self::trackedFiles() as $relative) {
            $path = WPRISM_REPO_ROOT . '/' . $relative;
            if (!is_file($path)) {
                $violations[] = $relative . ': tracked path is missing';
                continue;
            }

            $bytes = file_get_contents($path);
            if (!is_string($bytes)) {
                $violations[] = $relative . ': tracked bytes are unreadable';
                continue;
            }

            array_push($violations, ...self::identityViolations($relative, $bytes));
        }

        self::assertSame(
            [],
            $violations,
            'The WPrism cutover is greenfield; tracked paths and bytes may not retain the retired product '
            . "identity or issue-shaped aliases:\n  " . implode("\n  ", $violations)
        );
    }

    public function testIdentityRuleAllowsTheCompanyNameButRejectsProductAndIssueAliases(): void
    {
        self::assertSame([], self::identityViolations(
            'docs/vendor.md',
            'Duotronic and duotronic-ai are company identities.'
        ));

        $retired = self::retiredToken();
        self::assertNotSame([], self::identityViolations(
            'docs/' . $retired . '-guide.md',
            'Run ' . $retired . ' capture.'
        ));

        foreach (['#1234', '/1234', ': 1234', '-1234', '_XXXX', ' 5678'] as $suffix) {
            self::assertNotSame([], self::identityViolations(
                'docs/issue.md',
                self::brandToken() . $suffix . ' is an issue-shaped alias.'
            ));
        }

        self::assertSame([], self::identityViolations(
            'docs/source-reference.md',
            self::brandToken() . ':2385 is a source line reference, not a ticket alias.'
        ));
    }

    /** @return list<string> */
    private static function trackedFiles(): array
    {
        $pipes = [];
        $process = proc_open(
            ['git', '-C', WPRISM_REPO_ROOT, 'ls-files', '-z'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('could not enumerate tracked files');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($exit !== 0 || !is_string($stdout)) {
            throw new RuntimeException('git ls-files failed: ' . trim((string) $stderr));
        }

        $files = array_values(array_filter(explode("\0", $stdout), static fn (string $path): bool => $path !== ''));
        sort($files, SORT_STRING);

        return $files;
    }

    /** @return list<string> */
    private static function identityViolations(string $path, string $bytes): array
    {
        $violations = [];
        foreach ([$path => $path, $path . ':bytes' => $bytes] as $subject => $value) {
            foreach (self::retiredIdentityOffsets($value) as $offset) {
                $violations[] = $subject . ': retired identity at byte ' . $offset;
            }

            $matches = [];
            preg_match_all(self::issueAliasPattern(), $value, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] ?? [] as $match) {
                $violations[] = $subject . ': issue-shaped alias at byte ' . $match[1];
            }
        }

        return $violations;
    }

    /** @return list<int> */
    private static function retiredIdentityOffsets(string $subject): array
    {
        $matches = [];
        preg_match_all('/' . preg_quote(self::retiredToken(), '/') . '/i', $subject, $matches, PREG_OFFSET_CAPTURE);

        $offsets = [];
        foreach ($matches[0] ?? [] as $match) {
            $offset = $match[1];
            $companySuffix = substr($subject, $offset + strlen($match[0]), 6);
            if (strtolower($companySuffix) !== 'tronic') {
                $offsets[] = $offset;
            }
        }

        return $offsets;
    }

    private static function issueAliasPattern(): string
    {
        return '/' . self::brandToken()
            . '(?:\\s*[#\\/_-]\\s*|:\\s+|\\s+)(?:[0-9]{3,}|[Xx]{3,})/i';
    }

    private static function brandToken(): string
    {
        return 'WPRI' . 'SM';
    }

    private static function retiredToken(): string
    {
        return chr(100) . chr(117) . chr(111);
    }
}

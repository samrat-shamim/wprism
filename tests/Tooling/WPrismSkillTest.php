<?php

declare(strict_types=1);

namespace WPrism\Tests\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/** The published agent entry point must remain discoverable and self-contained. */
#[CoversNothing]
final class WPrismSkillTest extends TestCase
{
    public function testEntrypointAndInterfaceMetadataAreDiscoverable(): void
    {
        $entrypoint = self::read(self::skillRoot() . '/SKILL.md');
        self::assertStringNotContainsString('[TODO', $entrypoint);
        self::assertMatchesRegularExpression('/\A---\Rname: wprism\Rdescription: \S[^\r\n]+\R---\R/', $entrypoint);

        $interface = self::read(self::skillRoot() . '/agents/openai.yaml');
        self::assertMatchesRegularExpression('/^\s*display_name: "WPrism"$/m', $interface);
        self::assertMatchesRegularExpression('/^\s*short_description: "\S[^\r\n]+"$/m', $interface);
        self::assertMatchesRegularExpression('/^\s*default_prompt: "[^"]*\$wprism[^"]*"$/m', $interface);
    }

    public function testEveryLocalMarkdownReferenceExistsInsideTheSkill(): void
    {
        $root = realpath(self::skillRoot());
        self::assertIsString($root, 'skills/wprism is not a readable directory');

        $checked = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'md') {
                continue;
            }

            $markdown = self::read($file->getPathname());
            $matches = [];
            preg_match_all('/\[[^\]]*\]\(([^)]+)\)/', $markdown, $matches);
            foreach ($matches[1] ?? [] as $reference) {
                if (!is_string($reference) || $reference === '' || $reference[0] === '#') {
                    continue;
                }
                if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $reference) === 1 || $reference[0] === '/') {
                    continue;
                }

                $relative = explode('#', $reference, 2)[0];
                $target = realpath($file->getPath() . '/' . rawurldecode($relative));
                self::assertIsString($target, $file->getPathname() . " references missing path $reference");
                self::assertStringStartsWith($root . '/', $target, $file->getPathname() . " escapes the skill: $reference");
                $checked[] = $file->getPathname() . ' -> ' . $target;
            }
        }

        self::assertNotSame([], $checked, 'the entrypoint must route conditional detail to supporting references');
    }

    public function testRunnableExamplesDoNotBypassThePublicOrchestrator(): void
    {
        $verbs = self::publicHostVerbs();
        $citations = [];
        foreach (self::markdownFiles() as $path) {
            $markdown = self::read($path);
            $fences = [];
            preg_match_all('/```[^\r\n]*\R(.*?)```/s', $markdown, $fences);
            foreach ($fences[1] ?? [] as $commands) {
                self::assertDoesNotMatchRegularExpression(
                    '/(^|\R)\s*(?:\$\s+)?wp\s+wprism(?:\s|$)/',
                    (string) $commands,
                    "$path contains a runnable internal wp wprism command"
                );

                $matches = [];
                preg_match_all(
                    '/(?<![a-zA-Z0-9_.\/-])(?:cli\/)?wprism\s+([a-z][a-z0-9-]*)/',
                    (string) $commands,
                    $matches
                );
                foreach ($matches[1] ?? [] as $verb) {
                    self::assertContains($verb, $verbs, "$path cites unknown public host verb wprism $verb");
                    $citations[] = $path . ':wprism ' . $verb;
                }
            }
        }

        self::assertNotSame([], $citations, 'the skill must exercise its documented public command surface');
    }

    private static function skillRoot(): string
    {
        return WPRISM_REPO_ROOT . '/skills/wprism';
    }

    /** @return list<string> */
    private static function markdownFiles(): array
    {
        $paths = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::skillRoot()));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'md') {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths, SORT_STRING);

        return $paths;
    }

    private static function read(string $path): string
    {
        $bytes = file_get_contents($path);
        self::assertIsString($bytes, "$path is unreadable");

        return $bytes;
    }

    /** @return list<string> */
    private static function publicHostVerbs(): array
    {
        require_once WPRISM_REPO_ROOT . '/cli/src/Command/EnvironmentCommandPreflight.php';

        $verbs = \WPrism\Orchestrator\EnvironmentCommandPreflight::environmentVerbs();
        $entrypoint = self::read(WPRISM_REPO_ROOT . '/cli/wprism');
        $matches = [];
        preg_match_all("/verb === '([a-z][a-z0-9-]*)'/", $entrypoint, $matches);
        foreach ($matches[1] ?? [] as $verb) {
            $verbs[] = $verb;
        }
        $verbs = array_values(array_unique($verbs));
        sort($verbs, SORT_STRING);

        return $verbs;
    }
}

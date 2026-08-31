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
        $frontmatter = self::yamlMapping(self::frontmatter($entrypoint), 'SKILL.md frontmatter');
        self::assertSame(basename(self::skillRoot()), $frontmatter['name'] ?? null);
        self::assertIsString($frontmatter['description'] ?? null);
        self::assertNotSame('', trim((string) $frontmatter['description']));

        $interface = self::yamlMapping(
            self::read(self::skillRoot() . '/agents/openai.yaml'),
            'agents/openai.yaml'
        );
        self::assertSame('WPrism', $interface['interface.display_name'] ?? null);
        $shortDescription = $interface['interface.short_description'] ?? null;
        self::assertIsString($shortDescription);
        self::assertGreaterThanOrEqual(25, strlen($shortDescription));
        self::assertLessThanOrEqual(64, strlen($shortDescription));
        self::assertIsString($interface['interface.default_prompt'] ?? null);
        self::assertStringContainsString('$wprism', (string) $interface['interface.default_prompt']);
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
                self::assertDoesNotMatchRegularExpression(
                    '/(^|\R)\s*(?:\$\s+)?git\s+commit(?:\s|$)/',
                    (string) $commands,
                    "$path lets the agent create a human review-signature commit"
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

    public function testMachineCompatibilityManifestMatchesProductContracts(): void
    {
        foreach ([
            '/cli/src/Transport/EnvironmentDriver.php',
            '/cli/src/Assess/AssessReport.php',
            '/agent/src/Review/PlanView.php',
            '/cli/src/Refresh/MergeCheck.php',
            '/cli/src/Release/SourceStageReceipt.php',
            '/cli/src/Release/ReleasePrepare.php',
            '/cli/src/Authority/OperationAuthorization.php',
            '/cli/src/Release/ReleaseOutcome.php',
            '/cli/src/Release/ReleaseOperationStatus.php',
            '/cli/src/Release/JourneyOracle.php',
            '/cli/src/Recovery/CheckpointCatalog.php',
            '/cli/src/Recovery/RecoveryPlan.php',
            '/cli/src/Recovery/RecoveryOutcome.php',
            '/cli/src/Command/CommandOutput.php',
        ] as $file) {
            require_once WPRISM_REPO_ROOT . $file;
        }

        $manifest = self::compatibilityManifest();
        self::assertSame('wprism-skill-compatibility/v1', $manifest['format'] ?? null);
        $documents = $manifest['documents'] ?? null;
        self::assertIsArray($documents);
        $expectedFormats = [
            'assess-report' => \WPrism\Orchestrator\AssessReport::FORMAT,
            'branch-environment-reap' => 'wprism-branch-environment-reap/v1',
            'branch-environment-receipt' => 'wprism-branch-environment-receipt/v1',
            'checkpoint-catalog' => \WPrism\Orchestrator\CheckpointCatalog::FORMAT,
            'command-refusal' => 'wprism-command-refusal/v1',
            'complete-plan' => null,
            'contract-proposal' => \WPrism\Orchestrator\ContractProposal::FORMAT,
            'driver-capabilities' => \WPrism\Orchestrator\DriverCapabilityReport::FORMAT,
            'explain-report' => \WPrism\PlanExplanation::FORMAT,
            'legacy-recovery-outcome' => 'wprism-recovery-outcome/v1',
            'merge-check' => \WPrism\Orchestrator\MergeCheck::FORMAT,
            'operation-authorization' => \WPrism\Orchestrator\OperationAuthorization::FORMAT,
            'plan-view' => \WPrism\PlanView::FORMAT,
            'recovery-outcome' => \WPrism\Orchestrator\RecoveryOutcome::FORMAT,
            'recovery-plan' => \WPrism\Orchestrator\RecoveryPlan::FORMAT,
            'refresh-plan' => 'wprism-refresh-plan/v1',
            'release-outcome' => \WPrism\Orchestrator\ReleaseOutcome::FORMAT,
            'release-prepare' => \WPrism\Orchestrator\ReleasePrepare::FORMAT,
            'release-status' => \WPrism\Orchestrator\ReleaseOperationStatus::FORMAT,
            'source-stage-receipt' => \WPrism\Orchestrator\SourceStageReceipt::FORMAT,
            'verify-report' => \WPrism\Orchestrator\JourneyOracle::FORMAT,
        ];
        self::assertSame(array_keys($expectedFormats), array_keys($documents));
        foreach ($expectedFormats as $name => $format) {
            self::assertSame($format, $documents[$name]['format'] ?? null, "$name format drifted");
            self::assertIsArray($documents[$name]['required'] ?? null, "$name has no required-field contract");
            self::assertNotSame([], $documents[$name]['required'], "$name admits an empty document");
        }

        foreach (['commands', 'artifacts'] as $map) {
            self::assertIsArray($manifest[$map] ?? null);
            foreach ($manifest[$map] as $subject => $document) {
                self::assertArrayHasKey($document, $documents, "$map entry $subject names an unknown document");
            }
        }
        self::assertSame(
            \WPrism\PlanCategorySummary::FORMAT,
            $documents['complete-plan']['embedded_formats']['category_summary'] ?? null
        );

        $exit = null;
        $refusalBytes = false;
        ob_start();
        try {
            $exit = \WPrism\Orchestrator\CommandOutput::renderRefusalJson(
                'test-command',
                'test_refusal',
                'test message',
                'test remediation'
            );
            $refusalBytes = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertSame(1, $exit);
        self::assertIsString($refusalBytes);
        $refusal = json_decode($refusalBytes, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($refusal);
        self::assertSame($documents['command-refusal']['required'], array_keys($refusal));
    }

    public function testRiskyCommandExamplesAreStructurallyBound(): void
    {
        $manifest = self::compatibilityManifest();
        $artifacts = $manifest['artifacts'] ?? null;
        self::assertIsArray($artifacts);
        foreach (self::markdownFiles() as $path) {
            $fences = [];
            preg_match_all('/```[^\r\n]*\R(.*?)```/s', self::read($path), $fences);
            foreach ($fences[1] ?? [] as $commands) {
                $saved = [];
                preg_match_all('/>\s*([a-z][a-z0-9-]*\.json)/', (string) $commands, $saved);
                foreach ($saved[1] ?? [] as $artifact) {
                    self::assertArrayHasKey($artifact, $artifacts, "$path saves an untyped machine artifact $artifact");
                }
            }
        }

        $onboarding = self::read(self::skillRoot() . '/references/onboarding-and-assessment.md');
        $blocks = [];
        preg_match_all('/```[^\r\n]*\R(.*?)```/s', $onboarding, $blocks);
        $contract = null;
        foreach ($blocks[1] ?? [] as $block) {
            if (str_contains((string) $block, 'wprism contract <env> propose')) {
                $contract = (string) $block;
                break;
            }
        }
        self::assertIsString($contract, 'the contract review workflow has no runnable example');
        $propose = strpos($contract, 'wprism contract <env> propose');
        $accept = strpos($contract, 'wprism contract <env> accept');
        $show = strpos($contract, 'wprism contract <env> show');
        self::assertIsInt($propose);
        self::assertIsInt($accept);
        self::assertIsInt($show);
        self::assertTrue($propose < $accept && $accept < $show, 'contract review commands are out of trust order');
        self::assertSame(1, substr_count($contract, 'wprism contract <env> show'));
    }

    private static function skillRoot(): string
    {
        return WPRISM_REPO_ROOT . '/skills/wprism';
    }

    /** @return array<string,mixed> */
    private static function compatibilityManifest(): array
    {
        $decoded = json_decode(
            self::read(self::skillRoot() . '/references/formats.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertIsArray($decoded);

        return $decoded;
    }

    private static function frontmatter(string $markdown): string
    {
        $matches = [];
        self::assertSame(1, preg_match('/\A---\R(.*?)\R---(?:\R|\z)/s', $markdown, $matches));

        return (string) ($matches[1] ?? '');
    }

    /**
     * Parse the deliberately small metadata subset used by skill packages.
     * This validates mappings and scalar meaning without coupling tests to key
     * order or whether a valid string needed YAML quoting.
     *
     * @return array<string,string|null>
     */
    private static function yamlMapping(string $yaml, string $label): array
    {
        $values = [];
        $parents = [];
        $lines = preg_split('/\R/', $yaml);
        self::assertIsArray($lines);
        foreach ($lines as $number => $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            $indent = strlen($line) - strlen(ltrim($line, ' '));
            self::assertSame(0, $indent % 2, "$label line " . ($number + 1) . ' has invalid indentation');
            $depth = intdiv($indent, 2);
            $matches = [];
            self::assertSame(
                1,
                preg_match('/^\s*([a-zA-Z_][a-zA-Z0-9_-]*):(?:\s+(.*))?$/', $line, $matches),
                "$label line " . ($number + 1) . ' is not a mapping entry'
            );
            $key = (string) ($matches[1] ?? '');
            self::assertArrayHasKey($depth - 1, [-1 => true] + $parents, "$label line " . ($number + 1) . ' skips a level');
            $path = array_slice($parents, 0, $depth);
            $path[] = $key;
            $qualified = implode('.', $path);
            self::assertArrayNotHasKey($qualified, $values, "$label contains duplicate key $qualified");

            $scalar = $matches[2] ?? null;
            if ($scalar === null || $scalar === '') {
                $values[$qualified] = null;
                $parents = array_slice($parents, 0, $depth);
                $parents[$depth] = $key;
                continue;
            }

            $values[$qualified] = self::yamlString((string) $scalar, "$label key $qualified");
            $parents = array_slice($parents, 0, $depth);
        }

        return $values;
    }

    private static function yamlString(string $scalar, string $label): string
    {
        if (!str_starts_with($scalar, '"')) {
            return $scalar;
        }
        $decoded = json_decode($scalar, true);
        self::assertSame(JSON_ERROR_NONE, json_last_error(), "$label is not a valid quoted string");
        self::assertIsString($decoded, "$label must be a string");

        return $decoded;
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

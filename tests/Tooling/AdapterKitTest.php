<?php

declare(strict_types=1);

namespace WPrism\Tests\Tooling;

use WPrism\Tooling\AdapterKit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pin the assembler behind the adapter test kit.
 *
 * The estate-level properties — the kit assembles, its skeleton runs on `php`
 * alone from outside a checkout, the shipped FakeWpdb still refuses an
 * uninterpretable statement, and Adopt still embeds the adapter library in
 * `agent/` before archiving exactly `agent recovery` — are proven against the REAL tree in
 * sandbox/tests/offline/guards/regress_adapter_test_kit.php. What is left for a
 * unit test is the part of tools/adapter-kit.php that decides WHAT goes in a
 * kit and what may leave it, driven against synthetic trees carrying exactly
 * one planted defect each.
 *
 * That split matters because the defects here are the invisible ones. A kit
 * member that grows a `require_once __DIR__ . '/../../../agent/...'` works in
 * this repository — the path resolves — and fails only for the third party who
 * received the kit, weeks later, with no way to attribute it. Every refusal
 * below is that shape: something that passes in-tree and breaks in the field,
 * made to fail at build time with the offending target named.
 */
final class AdapterKitTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    private static function repoRoot(): string
    {
        $env = getenv('WPRISM_REPO_ROOT');

        return is_string($env) && $env !== '' ? $env : dirname(__DIR__, 2);
    }

    public static function setUpBeforeClass(): void
    {
        // PHP strips `#!...` only from the ENTRY script, never from an include,
        // so a plain require of an executable tool would print its shebang and
        // trip phpunit.xml.dist's beStrictAboutOutputDuringTests. The file is
        // otherwise side-effect free: ak_main() is behind the same
        // SCRIPT_FILENAME guard tools/offline-corpus.php uses.
        if (!class_exists(AdapterKit::class, false)) {
            ob_start();
            require_once self::repoRoot() . '/tools/adapter-kit.php';
            ob_end_clean();
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            self::removeTree($root);
        }
        $this->roots = [];
    }

    private static function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTree($path . '/' . $entry);
                }
            }
            rmdir($path);

            return;
        }
        if (file_exists($path)) {
            unlink($path);
        }
    }

    private function scratch(string $label): string
    {
        $root = sys_get_temp_dir() . '/wprism-adapter-kit-' . $label . '-' . bin2hex(random_bytes(6));
        $this->roots[] = $root;
        mkdir($root, 0o777, true);

        return $root;
    }

    /**
     * A synthetic tree carrying only what the assembler reads: the packaged
     * packaged sources, copied from the real ones so the dependency shapes are
     * the real ones, plus Adopt.php.
     *
     * @param array<string,string> $overrides repo-relative path => replacement contents
     */
    private function fixtureRoot(array $overrides = []): string
    {
        $root = $this->scratch('tree');
        $repo = self::repoRoot();
        $sources = array_map(static fn(array $row): string => $row[0], AdapterKit::COPIED);
        $sources[] = AdapterKit::ADOPT_PATH;
        foreach ($sources as $relative) {
            $target = $root . '/' . $relative;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0o777, true);
            }
            $contents = $overrides[$relative] ?? file_get_contents($repo . '/' . $relative);
            file_put_contents($target, (string) $contents);
        }

        return $root;
    }

    /** The projection is a pure function of the packaged bytes. */
    public function testManifestOfACopiedTreeMatchesTheRealOne(): void
    {
        $this->assertSame(
            AdapterKit::render(AdapterKit::manifest(self::repoRoot())),
            AdapterKit::render(AdapterKit::manifest($this->fixtureRoot()))
        );
    }

    /**
     * The point of the committed manifest: a one-byte edit to a packaged file
     * moves exactly one digest, so `make release-gate` names the file that
     * changed rather than reporting that "the kit" changed.
     */
    public function testEditingOnePackagedFileMovesOnlyItsDigest(): void
    {
        $before = AdapterKit::manifest($this->fixtureRoot());
        $edited = AdapterKit::manifest($this->fixtureRoot([
            'sandbox/tests/lib/FakeWpdb.php' => file_get_contents(
                self::repoRoot() . '/sandbox/tests/lib/FakeWpdb.php'
            ) . "\n// one edited byte\n",
        ]));

        $moved = [];
        foreach ($before['members'] as $index => $member) {
            if ($member['sha256'] !== $edited['members'][$index]['sha256']) {
                $moved[] = $member['path'];
            }
        }
        $this->assertSame(['lib/FakeWpdb.php'], $moved);
    }

    /**
     * The refusal that keeps a kit runnable outside this repository. In-tree
     * the added path resolves and nothing notices; the kit it produces is
     * broken for everyone else.
     */
    public function testUndeclaredOutOfKitRequireIsRefusedByName(): void
    {
        $root = $this->fixtureRoot([
            'sandbox/tests/lib/wp_stubs.php' => file_get_contents(
                self::repoRoot() . '/sandbox/tests/lib/wp_stubs.php'
            ) . "\nrequire_once __DIR__ . '/../../../agent/src/Kernel/Secrets.php';\n",
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('lib/wp_stubs.php reaches outside the kit for /../../../agent/src/Kernel/Secrets.php');
        AdapterKit::manifest($root);
    }

    /** The same list checked the other way: an unreached declaration is folklore. */
    public function testDeclarationNothingReachesForIsRefused(): void
    {
        $check = (string) file_get_contents(self::repoRoot() . '/sandbox/tests/lib/check.php');
        $root = $this->fixtureRoot([
            'sandbox/tests/lib/check.php' => str_replace(
                "require_once __DIR__ . '/../../../agent/src/Kernel/CommandRefusal.php';",
                '',
                $check
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no longer reaches for');
        AdapterKit::manifest($root);
    }

    /** A missing packaged source refuses rather than shipping a short kit. */
    public function testMissingPackagedSourceRefuses(): void
    {
        $root = $this->fixtureRoot();
        unlink($root . '/sandbox/tests/lib/frozen_policy.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('kit source is missing: sandbox/tests/lib/frozen_policy.php');
        AdapterKit::manifest($root);
    }

    /**
     * The tar composition is READ, never restated. A hardcoded answer would
     * record what the tool believes instead of what Adopt.php does, which is
     * the drift recording it exists to catch.
     */
    public function testAdoptionTarIsReadFromAdopt(): void
    {
        $this->assertSame(AdapterKit::ADOPTION_TAR, AdapterKit::adoptionTar(self::repoRoot()));

        $root = $this->fixtureRoot([
            AdapterKit::ADOPT_PATH => "<?php\n\$cmd = 'tar -cf ' . escapeshellarg(\$localArchive) . ' agent recovery sandbox';\n",
        ]);
        $this->assertSame(['agent', 'recovery', 'sandbox'], AdapterKit::adoptionTar($root));
    }

    public function testUnreadableAdoptionTarRefuses(): void
    {
        $root = $this->fixtureRoot([
            AdapterKit::ADOPT_PATH => "<?php\n\$cmd = 'tar -cf ' . escapeshellarg(\$localArchive) . self::COMPONENTS;\n",
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot read the adoption tar composition');
        AdapterKit::adoptionTar($root);
    }

    /**
     * Both dependency spellings the packaged files actually use, and only
     * those: a computed require cannot be resolved at build time, so it must
     * not be silently accepted as "no dependency".
     */
    public function testDependencyTargetsReadsBothMemberKinds(): void
    {
        $this->assertSame(
            ['/check.php', '/../lib/FakeWpdb.php'],
            AdapterKit::dependencyTargets(
                'skeleton/x.php',
                "<?php require_once __DIR__ . '/check.php';\ninclude __DIR__ . '/../lib/FakeWpdb.php';\n"
            )
        );
        $this->assertSame(
            [
                'conformance/asserts.sh',
                'lib/host_orchestrator.sh',
                'lib/pair_identity.sh',
                'bin/fetch-artifact.sh',
            ],
            AdapterKit::dependencyTargets(
                'conformance/run.sh',
                ". conformance/asserts.sh\n. lib/host_orchestrator.sh\n"
                    . "  . lib/pair_identity.sh\n  source bin/fetch-artifact.sh\n"
            )
        );
        $this->assertSame(
            [],
            AdapterKit::dependencyTargets('lib/x.php', "<?php require_once \$computed . '/check.php';\n"),
            'a computed require names no static target, so it lands in no dependency list'
        );
    }

    /**
     * .php members resolve against their own directory, .sh members against
     * the kit root (run.sh cd's there). Getting that backwards would classify
     * an internal dependency as external and refuse a correct kit.
     */
    public function testResolveTargetsSplitsOnTheMemberKind(): void
    {
        $members = [
            'lib/check.php',
            'lib/FakeWpdb.php',
            'lib/host_orchestrator.sh',
            'lib/pair_identity.sh',
            'conformance/asserts.sh',
            'skeleton/suite.php',
        ];

        $this->assertSame(
            ['internal' => ['/../lib/check.php'], 'external' => ['/../../../agent/x.php']],
            AdapterKit::resolveTargets($members, 'skeleton/suite.php', ['/../lib/check.php', '/../../../agent/x.php'])
        );
        $this->assertSame(
            [
                'internal' => ['conformance/asserts.sh', 'lib/host_orchestrator.sh', 'lib/pair_identity.sh'],
                'external' => ['bin/fetch-artifact.sh'],
            ],
            AdapterKit::resolveTargets($members, 'conformance/run.sh', [
                'conformance/asserts.sh',
                'lib/host_orchestrator.sh',
                'lib/pair_identity.sh',
                'bin/fetch-artifact.sh',
            ])
        );
    }

    /** A path that climbs out of the kit is null, not a silently clamped path. */
    public function testNormalizeRefusesToClimbOutOfTheKit(): void
    {
        $this->assertSame('lib/check.php', AdapterKit::normalize('lib/./sub/../check.php'));
        $this->assertNull(AdapterKit::normalize('lib/../../agent/x.php'));
    }

    public function testSlugShapesTheGeneratedNames(): void
    {
        $this->assertSame('My_Forms', AdapterKit::studly('my-forms'));
        $this->assertSame('my_forms', AdapterKit::snake('my-forms'));
        $this->assertSame('skeleton/regress_my_forms_kit.php', AdapterKit::skeletonSuitePath('my-forms'));
        $this->assertSame('skeleton/my-forms-adapter.php', AdapterKit::skeletonAdapterPath('my-forms'));
    }

    /**
     * @return list<array{0:string}>
     */
    public static function badSlugs(): array
    {
        return [['Not A Slug'], ['trailing-'], ['9lives'], [''], ['under_score'], ['../escape']];
    }

    #[DataProvider('badSlugs')]
    public function testUnusableSlugIsRefused(string $slug): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not usable as a file and class name');
        AdapterKit::assertSlug($slug);
    }

    /**
     * assemble() writes the members the manifest promised, and only those. The
     * checksum comparison is the interesting half: a source that changed
     * between the manifest read and the copy would hand a recipient a
     * MANIFEST.json that lies about what is next to it.
     */
    public function testAssembleWritesEveryMemberAndItsManifest(): void
    {
        $repo = self::repoRoot();
        $dir = $this->scratch('assembled') . '/kit';
        $written = AdapterKit::assemble($repo, $dir, 'my-forms');

        $manifest = AdapterKit::manifest($repo, 'my-forms');
        $expected = array_map(static fn(array $m): string => (string) $m['path'], $manifest['members']);
        $expected[] = AdapterKit::KIT_MANIFEST;
        $this->assertSame($expected, $written);

        foreach ($manifest['members'] as $member) {
            $this->assertFileExists($dir . '/' . $member['path']);
            $this->assertSame(
                $member['sha256'],
                hash_file('sha256', $dir . '/' . $member['path']),
                (string) $member['path']
            );
        }
        $this->assertSame(
            AdapterKit::render($manifest),
            file_get_contents($dir . '/' . AdapterKit::KIT_MANIFEST)
        );
        $this->assertStringContainsString(
            'class My_Forms_Adapter_Store',
            (string) file_get_contents($dir . '/skeleton/my-forms-adapter.php')
        );
    }

    public function testAssembleRefusesANonEmptyDirectory(): void
    {
        $dir = $this->scratch('occupied');
        file_put_contents($dir . '/leftover', 'x');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refusing to assemble into a non-empty directory');
        AdapterKit::assemble(self::repoRoot(), $dir);
    }

    /**
     * A kit may not be handed out while the committed checksum sheet disagrees
     * with the tree: the digests are the only thing a recipient has to tell one
     * revision of the harness from another.
     */
    public function testAssembleRefusesWhileTheCommittedManifestIsStale(): void
    {
        $root = $this->fixtureRoot();
        mkdir($root . '/tools', 0o777, true);
        file_put_contents($root . '/' . AdapterKit::MANIFEST_PATH, "{\n}\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('php tools/adapter-kit.php --write');
        AdapterKit::assemble($root, $this->scratch('stale') . '/kit');
    }

    /** The committed sheet is current, which is what `make release-gate` asserts. */
    public function testCommittedManifestMatchesTheTree(): void
    {
        $this->assertSame(
            AdapterKit::render(AdapterKit::manifest(self::repoRoot())),
            file_get_contents(self::repoRoot() . '/' . AdapterKit::MANIFEST_PATH),
            'run: php tools/adapter-kit.php --write'
        );
    }

    /** drift() reports the first differing line, not a diff nobody reads. */
    public function testDriftNamesTheFirstDifferingLine(): void
    {
        $rows = AdapterKit::drift("a\nb\nc\n", "a\nB\nc\n");
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('line 2: committed b / generated B', $rows[0]);
        $this->assertSame(
            ['byte-identical text compared unequal (line endings?)'],
            AdapterKit::drift("a\n", "a\n")
        );
    }

    /** The rendered manifest is newline-terminated pretty JSON, so a diff is readable. */
    public function testRenderIsNewlineTerminatedPrettyJson(): void
    {
        $rendered = AdapterKit::render(AdapterKit::manifest(self::repoRoot()));
        $this->assertStringEndsWith("\n", $rendered);
        $this->assertStringContainsString("\n    \"format\": \"" . AdapterKit::FORMAT . '",', $rendered);
        $this->assertIsArray(json_decode($rendered, true, 512, JSON_THROW_ON_ERROR));
    }
}

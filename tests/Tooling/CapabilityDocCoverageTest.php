<?php

declare(strict_types=1);

namespace WPrism\Tests\Tooling;

use PHPUnit\Framework\TestCase;

/**
 * `make release-gate` is where "the shipped library is exactly reviewed" is
 * enforced, and this is the proof that it actually refuses without requiring
 * a checked-in aggregate adapter inventory.
 *
 * WHY THIS TEST EXISTS AT ALL
 * ---------------------------
 * Until WP-1.2 the bidirectional coverage rule was enforced by
 * `ManifestDispositions::load()` on every `Policy::load()` (a whole-directory
 * glob and decode, O(library) for an O(pins) question, which refused every
 * unrelated pin over one unreviewed file). Runtime is now scoped to the PINNED
 * shipped subset (`ManifestDispositions::assert_covers()`), while
 * AdapterLibrary closes the complete authoring inventory before this tool
 * projects it. A gate nobody has watched fail is a gate nobody knows works.
 *
 * `capdoc_build()` resolves AdapterLibrary before rendering, so an
 * incomplete package fails `--check` whether or not the generated prose is
 * current.
 *
 * The tool resolves its inputs from `dirname(__DIR__)` of its own file and has
 * no --repo seam (deliberately: it is a release gate, not a library), so each
 * case runs against a COPY of the repository's logical inputs under a temp
 * root. Copying is also what keeps a red assertion from leaving an unreviewed
 * package in adapter-packages/, which the source-tree reader closes strictly.
 */
final class CapabilityDocCoverageTest extends TestCase
{
    private static function repoRoot(): string
    {
        $env = getenv('WPRISM_REPO_ROOT');
        return is_string($env) && $env !== '' ? $env : dirname(__DIR__, 2);
    }

    /**
     * A temp root carrying exactly the inputs tools/capability-doc.php reads:
     * the closed package adapter library, agent/version sources, the generated
     * compatibility baseline and the agent readers it requires. Deliberately
     * no README or docs/capabilities.md is staged: neither is an adapter input.
     */
    private function stagedRepo(): string
    {
        $repo = self::repoRoot();
        $root = sys_get_temp_dir() . '/wprism_capdoc_' . bin2hex(random_bytes(6));
        foreach ([
            'tools',
            'agent/src/Kernel',
            'agent/src/Policy',
            'docs',
        ] as $dir) {
            self::assertTrue(mkdir("$root/$dir", 0o777, true), "could not create $root/$dir");
        }
        foreach ([
            'tools/capability-doc.php',
            'agent/src/Kernel/Canon.php',
            'agent/src/Policy/AdapterLibrary.php',
            'agent/src/Policy/AdapterPackage.php',
            'agent/wprism.php',
            'docs/compatibility-baseline.json',
        ] as $relative) {
            self::assertTrue(copy("$repo/$relative", "$root/$relative"), "could not stage $relative");
        }
        self::copyTree("$repo/adapter-packages", "$root/adapter-packages");
        self::copyTree("$repo/platform", "$root/platform");
        return $root;
    }

    private static function copyTree(string $source, string $target): void
    {
        self::assertTrue(mkdir($target, 0o777, true), "could not create $target");
        foreach (new \FilesystemIterator($source) as $item) {
            $destination = $target . '/' . $item->getBasename();
            if ($item->isDir() && !$item->isLink()) {
                self::copyTree($item->getPathname(), $destination);
                continue;
            }
            self::assertTrue(copy($item->getPathname(), $destination), "could not stage $destination");
        }
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path) as $item) {
            if ($item->isDir() && !$item->isLink()) {
                self::removeTree($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }

    /** @return array{status:int,stdout:string,stderr:string} */
    private static function check(string $stagedRepo): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $process = proc_open(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$stagedRepo/tools/capability-doc.php") . ' --check',
            $descriptors,
            $pipes,
            $stagedRepo
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * The control leg. Without it the two refusals below could both be passing
     * for the wrong reason — a staged tree that cannot run at all refuses
     * everything.
     */
    public function testTheStagedShippedLibraryPassesTheGate(): void
    {
        $root = $this->stagedRepo();
        try {
            $result = self::check($root);
            self::assertSame(0, $result['status'], "staged --check failed:\n{$result['stdout']}{$result['stderr']}");
            self::assertStringContainsString('capability doc check:', $result['stdout']);
        } finally {
            self::removeTree($root);
        }
    }

    public function testAValidAdapterEditNeedsNoCentralProjectionFile(): void
    {
        $root = $this->stagedRepo();
        try {
            $disposition = "$root/adapter-packages/wps-hide-login/package/disposition.json";
            $bytes = (string) file_get_contents($disposition);
            self::assertStringContainsString('"reason": "Certified for exact', $bytes);
            $count = 0;
            self::assertNotFalse(file_put_contents(
                $disposition,
                str_replace(
                    '"reason": "Certified for exact',
                    '"reason": "Review-only detail. Certified for exact',
                    $bytes,
                    $count
                )
            ));
            self::assertSame(1, $count);

            $result = self::check($root);
            self::assertSame(0, $result['status'], "package-local edit failed source check:\n{$result['stderr']}");
            self::assertFileDoesNotExist("$root/docs/capabilities.md");
            self::assertFileDoesNotExist("$root/README.md");
        } finally {
            self::removeTree($root);
        }
    }

    public function testAManifestWithNoReviewedEntryFailsTheGate(): void
    {
        $root = $this->stagedRepo();
        try {
            self::assertTrue(mkdir("$root/adapter-packages/zz-unreviewed/package", 0o777, true));
            self::assertNotFalse(file_put_contents(
                "$root/adapter-packages/zz-unreviewed/package/manifest.json",
                (string) json_encode(['name' => 'zz-unreviewed', 'spec_version' => 2])
            ));
            $result = self::check($root);
            self::assertSame(1, $result['status'], 'an unreviewed manifest must fail release-gate');
            self::assertStringContainsString(
                'adapter zz-unreviewed disposition is not a readable regular file',
                $result['stderr']
            );
        } finally {
            self::removeTree($root);
        }
    }

    /**
     * The direction no LOAD can see: a reviewed entry whose manifest is gone.
     * `assert_covers()` validates what it was handed, so a dangling entry is
     * invisible to the pinned path by construction, and this gate is what
     * bounds it IN-REPO — for the shipped library only, at authoring time.
     *
     * It is not a runtime guarantee, and the distinction matters for any
     * library that did not come from this repository: a dangling entry
     * survives on such a site, and the one consumer that would act on it is
     * `profiles`, whose `manifest` validate_profiles() resolves against the
     * registry's OWN declared names rather than against the directory. That is
     * guarded where the profile is CONSUMED — InitPlanner::fse_profile_scope()
     * resolves the target against the manifests actually installed and
     * degrades to its `fse_profile_not_certified` advisory rather than
     * proposing scope from a ghost — because resolving it at load against the
     * pinned subset would refuse a correct library (a site pinning only
     * woocommerce leaves `fse` -> `core` unpinned).
     */
    public function testAReviewedEntryThatOutlivedItsManifestFailsTheGate(): void
    {
        $root = $this->stagedRepo();
        try {
            $manifest = "$root/adapter-packages/wps-hide-login/package/manifest.json";
            self::assertFileExists($manifest);
            unlink($manifest);
            $result = self::check($root);
            self::assertSame(1, $result['status'], 'a reviewed entry with no manifest must fail release-gate');
            self::assertStringContainsString(
                'adapter wps-hide-login manifest is not a readable regular file',
                $result['stderr']
            );
        } finally {
            self::removeTree($root);
        }
    }

    /**
     * The refusal above is only a gate if the gate runs it. Asserted against
     * the Makefile recipe rather than assumed, because the whole authoring
     * half of the coverage rule now hangs off this one line.
     */
    public function testReleaseGateRunsTheCapabilityDocCheck(): void
    {
        $makefile = (string) file_get_contents(self::repoRoot() . '/Makefile');
        self::assertMatchesRegularExpression(
            '/^release-gate:\n(?:\t[^\n]*\n)*\tphp tools\/capability-doc\.php --check\n/m',
            $makefile,
            'release-gate must run tools/capability-doc.php --check'
        );
    }
}

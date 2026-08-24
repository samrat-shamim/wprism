<?php

declare(strict_types=1);

namespace Duo\Tests\Tooling;

use PHPUnit\Framework\TestCase;

/**
 * `make release-gate` is where "the shipped library is exactly reviewed" is
 * enforced, and this is the proof that it actually refuses.
 *
 * WHY THIS TEST EXISTS AT ALL
 * ---------------------------
 * Until WP-1.2 the bidirectional coverage rule was enforced twice: once by
 * `ManifestDispositions::load()` on every `Policy::load()` (a whole-directory
 * glob and decode, O(library) for an O(pins) question, which refused every
 * unrelated pin over one unreviewed file) and once by capdoc_cross_check()
 * here. The runtime half is now scoped to the PINNED shipped subset
 * (`ManifestDispositions::assert_covers()`), so this tool's copy is no longer
 * a second opinion — it is the whole of the authoring rule, and a gate nobody
 * has ever watched fail is a gate nobody knows still works.
 *
 * No second implementation was added for the gate: capdoc_cross_check()
 * already computed the identical two-way comparison with the identical
 * sentence, and `capdoc_build()` calls it BEFORE the byte-compare, so a
 * mismatched library fails `--check` whether or not the generated prose is
 * current. A new checker beside it would be exactly the drift trap
 * tools/capability-doc.php's own header warns about.
 *
 * The tool resolves its inputs from `dirname(__DIR__)` of its own file and has
 * no --repo seam (deliberately: it is a release gate, not a library), so each
 * case runs against a COPY of the repository's four input files under a temp
 * root. Copying is also what keeps a red assertion from leaving an unreviewed
 * manifest in manifests/, which AGENTS.md rule 3 forbids and pair.sh refuses.
 */
final class CapabilityDocCoverageTest extends TestCase
{
    private static function repoRoot(): string
    {
        $env = getenv('DUO_REPO_ROOT');
        return is_string($env) && $env !== '' ? $env : dirname(__DIR__, 2);
    }

    /**
     * A temp root carrying exactly the files tools/capability-doc.php reads:
     * the four sources named in its header, plus the two generated documents
     * it byte-compares and the Canon it requires.
     */
    private function stagedRepo(): string
    {
        $repo = self::repoRoot();
        $root = sys_get_temp_dir() . '/duo_capdoc_' . bin2hex(random_bytes(6));
        foreach (['tools', 'manifests/capabilities', 'agent/src/Kernel', 'docs'] as $dir) {
            self::assertTrue(mkdir("$root/$dir", 0o777, true), "could not create $root/$dir");
        }
        foreach ([
            'tools/capability-doc.php',
            'agent/src/Kernel/Canon.php',
            'agent/duo.php',
            'manifests/dispositions.json',
            'manifests/capabilities/platform.json',
            'docs/capabilities.md',
            'docs/compatibility-baseline.json',
            'README.md',
        ] as $relative) {
            self::assertTrue(copy("$repo/$relative", "$root/$relative"), "could not stage $relative");
        }
        foreach (glob("$repo/manifests/*.json") ?: [] as $manifest) {
            self::assertTrue(copy($manifest, "$root/manifests/" . basename($manifest)));
        }
        return $root;
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

    public function testAManifestWithNoReviewedEntryFailsTheGate(): void
    {
        $root = $this->stagedRepo();
        try {
            file_put_contents(
                "$root/manifests/zz-unreviewed.json",
                (string) json_encode(['name' => 'zz-unreviewed', 'spec_version' => 2])
            );
            $result = self::check($root);
            self::assertSame(1, $result['status'], 'an unreviewed manifest must fail release-gate');
            self::assertStringContainsString(
                'manifest disposition coverage mismatch; missing=[zz-unreviewed]',
                $result['stderr']
            );
        } finally {
            self::removeTree($root);
        }
    }

    /**
     * The direction the runtime can no longer see at all: a reviewed entry
     * whose manifest is gone. `assert_covers()` validates what it was given,
     * so nothing on the pinned path would ever notice this; the gate is the
     * only reader left.
     */
    public function testAReviewedEntryThatOutlivedItsManifestFailsTheGate(): void
    {
        $root = $this->stagedRepo();
        try {
            self::assertFileExists("$root/manifests/wps-hide-login.json");
            unlink("$root/manifests/wps-hide-login.json");
            $result = self::check($root);
            self::assertSame(1, $result['status'], 'a reviewed entry with no manifest must fail release-gate');
            self::assertStringContainsString(
                'manifest disposition coverage mismatch; missing=[], extra=[wps-hide-login]',
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

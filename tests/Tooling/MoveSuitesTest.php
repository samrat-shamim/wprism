<?php

declare(strict_types=1);

namespace Duo\Tests\Tooling;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins tools/codemod/move-suites.php — the codemod that executes a
 * hand-reviewed sandbox/tests restructure and then proves it.
 *
 * Three levels, for three different reasons.
 *
 * The synthetic-repo cases build a miniature corpus in a temp dir (its own
 * `git init`, so `git mv` is exercised rather than the rename fallback) and run
 * the script out-of-process with --root/--map. That is the only way to state
 * the EXACT bytes each rewrite class must produce: a `dirname(__DIR__, 2)`
 * bumped to 4, a sibling `'/lib/check.php'` becoming `'/../../lib/check.php'`,
 * a bare `cd "$(dirname "$0")"` left byte-identical while its `../../agent/…`
 * tokens gain two levels, and — the ones that are easy to get wrong — a nowdoc
 * and a source-text assertion needle left completely alone. A CLI assertion
 * against the real tree could only say "something changed".
 *
 * The refusal cases each hand the tool a map that must not execute. They assert
 * the message, not just the exit code, because these refusals are the whole
 * safety argument for pointing a codemod at 333 files: a basename collision
 * that slipped through would leave a suite unrunnable while looking wired.
 *
 * The real-repo case runs --plan against this checkout with an EMPTY map and
 * asserts zero moves, zero REVIEW, exit 0, and a byte-identical working tree.
 * It is the guard that the scan roots, the literal pattern and the review
 * contract stay quiet against the tree as it actually is.
 */
final class MoveSuitesTest extends TestCase
{
    private static string $tempRoot = '';

    // ------------------------------------------------------------- fixtures

    private static function repoRoot(): string
    {
        $env = getenv('DUO_REPO_ROOT');
        return is_string($env) && $env !== '' ? $env : dirname(__DIR__, 2);
    }

    private static function script(): string
    {
        return self::repoRoot() . '/tools/codemod/move-suites.php';
    }

    public static function setUpBeforeClass(): void
    {
        require_once self::script();
    }

    protected function tearDown(): void
    {
        if (self::$tempRoot !== '' && is_dir(self::$tempRoot)) {
            self::removeTree(self::$tempRoot);
        }
        self::$tempRoot = '';
    }

    private static function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    private static function write(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("could not create $dir");
        }
        file_put_contents($path, $contents);
    }

    private static function read(string $path): string
    {
        $bytes = @file_get_contents($path);
        if (!is_string($bytes)) {
            throw new RuntimeException("could not read $path");
        }
        return $bytes;
    }

    /**
     * @return array{0:int, 1:string} exit status and combined output
     */
    private function runTool(string $root, string ...$args): array
    {
        $cmd = 'php ' . escapeshellarg(self::script()) . ' --root=' . escapeshellarg($root);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        $output = [];
        exec($cmd . ' 2>&1', $output, $status);
        return [$status, implode("\n", $output)];
    }

    /**
     * A miniature corpus carrying one instance of every rewrite class, plus the
     * three shapes that must survive untouched.
     */
    private function makeSyntheticRepo(): string
    {
        $root = (string) tempnam(sys_get_temp_dir(), 'duo-move-suites-');
        unlink($root);
        mkdir($root, 0777, true);
        self::$tempRoot = $root;

        self::write($root . '/agent/src/Kernel/Canon.php', "<?php\nnamespace Duo;\nclass Canon {}\n");
        self::write($root . '/manifests/core.json', "{\"format\":\"duo-manifest/v1\"}\n");
        self::write($root . '/docs/guides/internals.md', "See sandbox/tests/regress_thing.php for the proof.\n");

        // Substrate: named by every suite, and it stays at the corpus root.
        self::write($root . '/sandbox/tests/lib/check.php', "<?php\nfunction check(bool \$ok): void {}\n");
        self::write($root . '/sandbox/tests/support/stub.php', "<?php\n// a WordPress stub\n");
        self::write($root . '/sandbox/tests/manifest_fixtures.php', "<?php\n// shared manifest fixtures\n");
        self::write($root . '/sandbox/tests/fixtures/vec/sample.json', "{\"vector\":1}\n");

        // The PHP suite: classes 1 and 2, plus the three no-touch shapes.
        self::write($root . '/sandbox/tests/regress_thing.php', <<<'PHP'
<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/check.php';
require_once __DIR__ . '/manifest_fixtures.php';
require_once __DIR__ . '/support/stub.php';

$root = dirname(__DIR__, 2);
require $root . '/agent/src/Kernel/Canon.php';
$manifests = $root . '/manifests/core.json';
$everyManifest = $root . '/manifests/*.json';

// A trailing separator joined with a variable: dropping the slash yields a
// path that still resolves to an existing DIRECTORY, so only running the
// suite catches it.
$vector = __DIR__ . '/fixtures/vec/' . 'sample' . '.json';
if (!is_file($vector)) {
    throw new RuntimeException("missing vector $vector");
}

// A source-text assertion needle: the __DIR__ belongs to the file under test,
// not to this suite, so it must survive byte-for-byte.
$needle = "require_once __DIR__ . '/CapturePublicationWorkflow.php';";

// A nowdoc program written to a temp file: its __DIR__ resolves in the temp
// directory, so it must survive byte-for-byte too.
$probe = <<<'PROBE'
<?php
define('WP_PLUGIN_DIR', __DIR__ . '/wp-plugins');
$here = dirname(__DIR__, 1);
PROBE;

// A negative existence assertion: the path is SUPPOSED to resolve to nothing.
$gone = !is_file($root . '/manifests/regenerators/retired.php');

echo "ok\n";
PHP);

        // The shell suite whose cwd FOLLOWS it: classes 3 (no-op), 4a, 4b, 4c.
        self::write($root . '/sandbox/tests/regress_thing.sh', <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"   # -> sandbox/tests/

# shellcheck source=lib/check.php
# shellcheck source=/dev/null

php -l ../../agent/src/Kernel/Canon.php >/dev/null
php regress_thing.php
php support/stub.php
for manifest in ../../manifests/*.json; do echo "$manifest"; done

tree_hash() { (cd ../.. && find manifests -type f -print0 | shasum -a 256); }

repo="$(php -r 'echo dirname(getcwd(), 2);')"
echo "$repo"
SH);

        // The shell suite whose cwd class 3 PRESERVES: its tokens must not move.
        self::write($root . '/sandbox/tests/regress_preserved.sh', <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

cat ../manifests/core.json
php ../agent/src/Kernel/Canon.php
SH);

        // The assignment form, moving only one level.
        self::write($root . '/sandbox/tests/certify_x.sh', <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cat "$ROOT/manifests/core.json"
SH);

        self::write($root . '/Makefile', <<<'MAKE'
regress-thing:
	php sandbox/tests/regress_thing.php
	bash sandbox/tests/regress_thing.sh

certify-x:
	PAIR="$(PAIR)" bash sandbox/tests/certify_x.sh
MAKE);

        exec('git -C ' . escapeshellarg($root) . ' init -q 2>&1');
        exec('git -C ' . escapeshellarg($root) . ' add -A 2>&1');
        exec('git -C ' . escapeshellarg($root)
            . ' -c user.email=t@example.invalid -c user.name=t commit -qm base 2>&1');

        return $root;
    }

    /** @param array<string,string> $map */
    private function writeMap(string $root, array $map): string
    {
        $path = $root . '/suite-layout.json';
        file_put_contents($path, (string) json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $path;
    }

    /** @return array<string,string> */
    private function syntheticMap(): array
    {
        return [
            'sandbox/tests/regress_thing.php' => 'sandbox/tests/offline/thing/regress_thing.php',
            'sandbox/tests/regress_thing.sh' => 'sandbox/tests/offline/thing/regress_thing.sh',
            'sandbox/tests/regress_preserved.sh' => 'sandbox/tests/offline/thing/regress_preserved.sh',
            'sandbox/tests/certify_x.sh' => 'sandbox/tests/certify/certify_x.sh',
        ];
    }

    // ------------------------------------------------------ plan and apply

    public function testPlanNamesEveryMoveAndChangesNothing(): void
    {
        $root = $this->makeSyntheticRepo();
        $map = $this->writeMap($root, $this->syntheticMap());

        exec('git -C ' . escapeshellarg($root) . ' status --porcelain 2>&1', $before);
        [$status, $output] = $this->runTool($root, '--plan', '--map=' . $map);
        exec('git -C ' . escapeshellarg($root) . ' status --porcelain 2>&1', $after);

        self::assertSame(0, $status, $output);
        self::assertSame($before, $after, '--plan must not touch the tree');
        self::assertStringContainsString(
            'git mv sandbox/tests/regress_thing.php -> sandbox/tests/offline/thing/regress_thing.php',
            $output
        );
        self::assertStringContainsString('4 move(s) pending', $output);
        self::assertStringContainsString('0 review item(s)', $output);
        // The file still lives at its old path: --plan is pure.
        self::assertFileExists($root . '/sandbox/tests/regress_thing.php');
    }

    public function testApplyRebasesEveryPhpPathExpression(): void
    {
        $root = $this->makeSyntheticRepo();
        [$status, $output] = $this->runTool($root, '--apply', '--map=' . $this->writeMap($root, $this->syntheticMap()));
        self::assertSame(0, $status, $output);

        $moved = $root . '/sandbox/tests/offline/thing/regress_thing.php';
        self::assertFileExists($moved);
        self::assertFileDoesNotExist($root . '/sandbox/tests/regress_thing.php');
        $php = self::read($moved);

        // Class 1: two directories deeper, so the depth argument gains two.
        self::assertStringContainsString('$root = dirname(__DIR__, 4);', $php);
        self::assertStringNotContainsString('dirname(__DIR__, 2);', $php);

        // Class 2: substrate stays at the corpus root, so the siblings climb.
        self::assertStringContainsString("require_once __DIR__ . '/../../lib/check.php';", $php);
        self::assertStringContainsString("require_once __DIR__ . '/../../manifest_fixtures.php';", $php);
        self::assertStringContainsString("require_once __DIR__ . '/../../support/stub.php';", $php);

        // The trailing separator survives. Without it the join produces
        // `fixtures/vecsample.json`, which --prove cannot see: the truncated
        // path `sandbox/tests/fixtures/vec` is a directory that exists.
        self::assertStringContainsString(
            "\$vector = __DIR__ . '/../../fixtures/vec/' . 'sample' . '.json';",
            $php
        );

        // The three shapes the tokenizer is right to skip, byte-for-byte.
        self::assertStringContainsString(
            '$needle = "require_once __DIR__ . \'/CapturePublicationWorkflow.php\';";',
            $php,
            'a source-text assertion needle describes another file\'s require and must not be re-based'
        );
        self::assertStringContainsString("define('WP_PLUGIN_DIR', __DIR__ . '/wp-plugins');", $php);
        self::assertStringContainsString('$here = dirname(__DIR__, 1);', $php);
    }

    public function testApplyLeavesABareSelfAnchorAloneAndRebasesItsTokens(): void
    {
        $root = $this->makeSyntheticRepo();
        [$status, $output] = $this->runTool($root, '--apply', '--map=' . $this->writeMap($root, $this->syntheticMap()));
        self::assertSame(0, $status, $output);

        $sh = self::read($root . '/sandbox/tests/offline/thing/regress_thing.sh');

        // Class 3: a bare self-anchor makes no repo-path claim, so it stays.
        // Rewriting it to `/../..` would hard-code the pre-move layout.
        self::assertStringContainsString('cd "$(dirname "$0")"', $sh);
        self::assertStringNotContainsString('cd "$(dirname "$0")/..', $sh);

        // Class 4b: the cwd followed the script, so its tokens gain two levels.
        self::assertStringContainsString('php -l ../../../../agent/src/Kernel/Canon.php', $sh);
        self::assertStringContainsString('php ../../support/stub.php', $sh);
        // …and the trailing slash before a glob survives.
        self::assertStringContainsString('for manifest in ../../../../manifests/*.json;', $sh);
        // A sibling that moved with it stays a sibling.
        self::assertStringContainsString('php regress_thing.php', $sh);

        // Class 4a: shellcheck resolves source= against the FILE, not the cwd.
        self::assertStringContainsString('# shellcheck source=../../lib/check.php', $sh);
        self::assertStringContainsString('# shellcheck source=/dev/null', $sh);

        // Class 4c: both spellings of the cwd-anchored climb to the repo root.
        self::assertStringContainsString('(cd ../../../.. && find manifests', $sh);
        self::assertStringContainsString('dirname(getcwd(), 4)', $sh);
    }

    public function testApplyPreservesTheCwdOfAClimbingSelfAnchor(): void
    {
        $root = $this->makeSyntheticRepo();
        [$status, $output] = $this->runTool($root, '--apply', '--map=' . $this->writeMap($root, $this->syntheticMap()));
        self::assertSame(0, $status, $output);

        $sh = self::read($root . '/sandbox/tests/offline/thing/regress_preserved.sh');
        // The anchor claimed `sandbox/`; the run is extended so it still does.
        self::assertStringContainsString('cd "$(dirname "$0")/../../.."', $sh);
        // And because the cwd is unchanged, the tokens must NOT move.
        self::assertStringContainsString('cat ../manifests/core.json', $sh);
        self::assertStringContainsString('php ../agent/src/Kernel/Canon.php', $sh);

        $certify = self::read($root . '/sandbox/tests/certify/certify_x.sh');
        self::assertStringContainsString('ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"', $certify);
    }

    public function testApplyRewritesMakefileRecipesAndDocsWithoutIntroducingVariables(): void
    {
        $root = $this->makeSyntheticRepo();
        [$status, $output] = $this->runTool($root, '--apply', '--map=' . $this->writeMap($root, $this->syntheticMap()));
        self::assertSame(0, $status, $output);

        $makefile = self::read($root . '/Makefile');
        self::assertStringContainsString("\tphp sandbox/tests/offline/thing/regress_thing.php\n", $makefile);
        self::assertStringContainsString("\tbash sandbox/tests/offline/thing/regress_thing.sh\n", $makefile);
        // tools/offline.php reads recipes UNEXPANDED: a $(VAR) in the PATH would
        // silently disable its serial-group collision detection. The env prefix
        // that was already there is untouched; no new variable appears.
        self::assertStringContainsString('PAIR="$(PAIR)" bash sandbox/tests/certify/certify_x.sh', $makefile);
        self::assertSame(1, substr_count($makefile, '$('), 'no variable may be introduced into a recipe path');

        self::assertStringContainsString(
            'See sandbox/tests/offline/thing/regress_thing.php for the proof.',
            self::read($root . '/docs/guides/internals.md')
        );
    }

    public function testAppliedTreeStillRunsItsSuites(): void
    {
        $root = $this->makeSyntheticRepo();
        [$status] = $this->runTool($root, '--apply', '--map=' . $this->writeMap($root, $this->syntheticMap()));
        self::assertSame(0, $status);

        // The point of every rewrite above, stated once as behaviour: the moved
        // suites still resolve everything they load.
        $php = [];
        exec('php ' . escapeshellarg($root . '/sandbox/tests/offline/thing/regress_thing.php') . ' 2>&1', $php, $phpStatus);
        self::assertSame(0, $phpStatus, implode("\n", $php));

        $sh = [];
        exec('bash ' . escapeshellarg($root . '/sandbox/tests/offline/thing/regress_thing.sh') . ' 2>&1', $sh, $shStatus);
        self::assertSame(0, $shStatus, implode("\n", $sh));
    }

    public function testSecondApplyIsANoOp(): void
    {
        $root = $this->makeSyntheticRepo();
        $map = $this->writeMap($root, $this->syntheticMap());

        [$first] = $this->runTool($root, '--apply', '--map=' . $map);
        self::assertSame(0, $first);
        $afterFirst = self::read($root . '/sandbox/tests/offline/thing/regress_thing.php');

        [$second, $output] = $this->runTool($root, '--apply', '--map=' . $map);
        self::assertSame(0, $second, $output);
        self::assertStringContainsString('0 file(s) moved, 0 rewritten', $output);
        self::assertSame(
            $afterFirst,
            self::read($root . '/sandbox/tests/offline/thing/regress_thing.php'),
            'a re-run must not re-base an already-re-based path'
        );
    }

    // -------------------------------------------------------------- prove

    public function testProveCatchesADeliberatelyDangledReference(): void
    {
        $root = $this->makeSyntheticRepo();
        $map = $this->writeMap($root, $this->syntheticMap());
        [$status] = $this->runTool($root, '--apply', '--map=' . $map);
        self::assertSame(0, $status);

        [$clean, $cleanOutput] = $this->runTool($root, '--prove', '--map=' . $map);
        self::assertSame(0, $clean, $cleanOutput);
        self::assertStringContainsString('none', $cleanOutput);

        // Lose one level off a rewritten require — the exact shape a wrong
        // depth delta produces, and the one the offline gate never executes in
        // a live/ or certify/ file.
        $moved = $root . '/sandbox/tests/offline/thing/regress_thing.php';
        file_put_contents($moved, str_replace(
            "__DIR__ . '/../../lib/check.php'",
            "__DIR__ . '/../lib/check.php'",
            self::read($moved)
        ));

        [$dangling, $output] = $this->runTool($root, '--prove', '--map=' . $map);
        self::assertSame(1, $dangling, $output);
        self::assertStringContainsString('sandbox/tests/offline/thing/regress_thing.php', $output);
        self::assertStringContainsString('sandbox/tests/offline/lib/check.php', $output);
        self::assertStringContainsString('which does not exist', $output);
    }

    public function testProveCatchesASurvivingPreMovePath(): void
    {
        $root = $this->makeSyntheticRepo();
        $map = $this->writeMap($root, $this->syntheticMap());
        [$status] = $this->runTool($root, '--apply', '--map=' . $map);
        self::assertSame(0, $status);

        // A mention the literal pass missed: post-apply it names a file that is
        // no longer there, and nothing else in the tree would say so.
        file_put_contents(
            $root . '/docs/guides/internals.md',
            "See sandbox/tests/regress_thing.php for the proof.\n"
        );

        [$dangling, $output] = $this->runTool($root, '--prove', '--map=' . $map);
        self::assertSame(1, $dangling, $output);
        self::assertStringContainsString('a surviving mention of the pre-move path', $output);
    }

    public function testProveAcceptsANegativeExistenceAssertion(): void
    {
        $root = $this->makeSyntheticRepo();
        $map = $this->writeMap($root, $this->syntheticMap());
        [$status] = $this->runTool($root, '--apply', '--map=' . $map);
        self::assertSame(0, $status);

        // regress_thing.php asserts `!is_file($root . '/manifests/regenerators/
        // retired.php')`. The path is meant to resolve to nothing; requiring it
        // to exist would invert the suite's own claim.
        self::assertFileDoesNotExist($root . '/manifests/regenerators/retired.php');
        [$clean, $output] = $this->runTool($root, '--prove', '--map=' . $map);
        self::assertSame(0, $clean, $output);
    }

    // ------------------------------------------------------------ refusals

    public function testMapNamingAMissingFileIsRefused(): void
    {
        $root = $this->makeSyntheticRepo();
        $map = $this->syntheticMap();
        $map['sandbox/tests/regress_absent.php'] = 'sandbox/tests/offline/thing/regress_absent.php';

        [$status, $output] = $this->runTool($root, '--plan', '--map=' . $this->writeMap($root, $map));
        self::assertSame(1, $status);
        self::assertStringContainsString(
            'sandbox/tests/regress_absent.php exists at neither its old nor its new path',
            $output
        );
    }

    public function testTwoKeysMappingToOneValueAreRefused(): void
    {
        $root = $this->makeSyntheticRepo();
        $map = $this->syntheticMap();
        $map['sandbox/tests/regress_preserved.sh'] = 'sandbox/tests/offline/thing/regress_thing.sh';

        [$status, $output] = $this->runTool($root, '--plan', '--map=' . $this->writeMap($root, $map));
        self::assertSame(1, $status);
        self::assertStringContainsString('both map to sandbox/tests/offline/thing/regress_thing.sh', $output);
    }

    public function testABasenameCollisionIsRefused(): void
    {
        $root = $this->makeSyntheticRepo();
        self::write($root . '/sandbox/tests/regress_thing.json', "{}\n");
        $map = $this->syntheticMap();
        // Two different directories, one basename: a Makefile target name comes
        // from the basename alone, so only one of them could ever be wired.
        $map['sandbox/tests/regress_preserved.sh'] = 'sandbox/tests/live/regress_thing.sh';

        [$status, $output] = $this->runTool($root, '--plan', '--map=' . $this->writeMap($root, $map));
        self::assertSame(1, $status);
        self::assertStringContainsString("share the basename 'regress_thing.sh'", $output);
        self::assertStringContainsString('only one of them could ever be wired', $output);
    }

    public function testSplittingTheTwoHalvesOfOneSubjectIsRefused(): void
    {
        $root = $this->makeSyntheticRepo();
        $map = $this->syntheticMap();
        $map['sandbox/tests/regress_thing.sh'] = 'sandbox/tests/live/regress_thing.sh';

        [$status, $output] = $this->runTool($root, '--plan', '--map=' . $this->writeMap($root, $map));
        self::assertSame(1, $status);
        self::assertStringContainsString("share the stem 'regress_thing'", $output);
        self::assertStringContainsString('the two halves of one subject move together', $output);
    }

    /**
     * @return list<array{0:string, 1:string}>
     */
    public static function substrateProvider(): array
    {
        return [
            'lib' => ['sandbox/tests/lib/check.php', 'lib'],
            'support' => ['sandbox/tests/support/stub.php', 'support'],
        ];
    }

    #[DataProvider('substrateProvider')]
    public function testMovingSharedSubstrateIsRefused(string $key, string $member): void
    {
        $root = $this->makeSyntheticRepo();
        $map = $this->syntheticMap();
        $map[$key] = 'sandbox/tests/offline/thing/' . basename($key);

        [$status, $output] = $this->runTool($root, '--plan', '--map=' . $this->writeMap($root, $map));
        self::assertSame(1, $status);
        self::assertStringContainsString("is shared substrate ($member)", $output);
        self::assertStringContainsString('must stay at the corpus root', $output);
    }

    public function testAValueLeavingTheCorpusIsRefused(): void
    {
        $root = $this->makeSyntheticRepo();
        $map = $this->syntheticMap();
        $map['sandbox/tests/regress_thing.php'] = 'sandbox/conformance/regress_thing.php';

        [$status, $output] = $this->runTool($root, '--plan', '--map=' . $this->writeMap($root, $map));
        self::assertSame(1, $status);
        self::assertStringContainsString('which leaves sandbox/tests', $output);
    }

    public function testAnUnrecognisedPathExpressionRefusesThePlanAndTheApply(): void
    {
        $root = $this->makeSyntheticRepo();
        // A cwd-anchored climb in a spelling class 4c does not know, inside a
        // script whose cwd follows it. Rewriting it wrongly would be worse than
        // refusing, so the tool refuses.
        file_put_contents(
            $root . '/sandbox/tests/regress_thing.sh',
            self::read($root . '/sandbox/tests/regress_thing.sh') . "\nbase=\"\$(pwd)/../..\"\necho \"\$base\"\n"
        );
        $map = $this->writeMap($root, $this->syntheticMap());

        [$planStatus, $planOutput] = $this->runTool($root, '--plan', '--map=' . $map);
        self::assertSame(1, $planStatus);
        self::assertStringContainsString('cwd-anchored climb', $planOutput);
        self::assertStringContainsString('no rewrite class recognises', $planOutput);

        [$applyStatus, $applyOutput] = $this->runTool($root, '--apply', '--map=' . $map);
        self::assertSame(1, $applyStatus);
        self::assertStringContainsString('refusing to apply', $applyOutput);
        self::assertFileExists(
            $root . '/sandbox/tests/regress_thing.sh',
            '--apply must move nothing when --plan would refuse'
        );
    }

    // ----------------------------------------------------- the real repository

    public function testEmptyMapAgainstTheRealRepositoryIsAQuietNoOp(): void
    {
        $repo = self::repoRoot();
        $map = (string) tempnam(sys_get_temp_dir(), 'duo-empty-map-');
        file_put_contents($map, "{}\n");

        exec('git -C ' . escapeshellarg($repo) . ' status --porcelain 2>&1', $before);
        [$status, $output] = $this->runTool($repo, '--plan', '--map=' . $map);
        exec('git -C ' . escapeshellarg($repo) . ' status --porcelain 2>&1', $after);
        unlink($map);

        self::assertSame(0, $status, $output);
        self::assertSame($before, $after);
        self::assertStringContainsString('0 move(s) pending', $output);
        self::assertStringContainsString('0 review item(s)', $output);
        self::assertStringContainsString('0 unprovable site(s)', $output);
    }

    public function testProveAgainstTheRealRepositoryWithAnEmptyMapIsGreen(): void
    {
        $map = (string) tempnam(sys_get_temp_dir(), 'duo-empty-map-');
        file_put_contents($map, "{}\n");
        [$status, $output] = $this->runTool(self::repoRoot(), '--prove', '--map=' . $map);
        unlink($map);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('=== DANGLING ===' . "\n" . 'none', $output);
    }

    // ---------------------------------------------------- couplings and traps

    /**
     * tools/affected.php carries a comment line that
     * tools/codemod/move-modules.php matches VERBATIM to detect whether its own
     * scanner rewrite has already been applied
     * (`mm_compute()` falls back to the replacement's first line). Reflowing or
     * re-pathing it would make that codemod believe its source had drifted and
     * throw. It carries no corpus path, so nothing here should reach it — this
     * asserts that rather than assuming it.
     */
    public function testTheAffectedPhpModuleMoveSentinelIsNeverRewritten(): void
    {
        $sentinel = '// The third arm is new with the module move (ROUND 3 TRAIN 1):';
        $affected = self::read(self::repoRoot() . '/tools/affected.php');
        self::assertStringContainsString($sentinel, $affected);

        // Against a full, realistic placement — every flat suite moving into a
        // domain directory — the sentinel line still comes back unchanged.
        $placement = [];
        foreach ((array) glob(self::repoRoot() . '/sandbox/tests/*.{php,sh}', GLOB_BRACE) as $path) {
            $name = basename((string) $path);
            $placement['sandbox/tests/' . $name] = 'sandbox/tests/offline/domain/' . $name;
        }
        self::assertNotEmpty($placement);

        $result = ms_rewrite_literals($affected, $placement, 'tools/affected.php');
        self::assertStringContainsString($sentinel, $result['text']);
        foreach ($result['changes'] as $change) {
            self::assertStringNotContainsString('ROUND 3 TRAIN 1', $change['from']);
        }
    }

    /**
     * `mm_scanner_fixes()` is keyed by six flat suite paths and consulted with
     * `isset()`. A key that stops naming a file does not fail — the lookup just
     * misses, and move-modules.php's scanner-recursion pass becomes a no-op
     * nobody notices. The tool refuses rather than let that happen silently.
     */
    public function testAMoveModulesKeyThatDidNotFollowTheMoveIsReported(): void
    {
        $root = $this->makeSyntheticRepo();
        self::write($root . '/sandbox/tests/regress_manifest_validate.php', "<?php\necho \"ok\\n\";\n");
        // A stand-in for the real table, keyed exactly the way it is keyed.
        self::write($root . '/tools/codemod/move-modules.php', <<<'PHP'
<?php
function mm_scanner_fixes(): array
{
    return [
        'sandbox/tests/regress_manifest_validate.php' => [],
    ];
}
PHP);
        exec('git -C ' . escapeshellarg($root) . ' add -A 2>&1');

        $map = $this->syntheticMap();
        $map['sandbox/tests/regress_manifest_validate.php']
            = 'sandbox/tests/offline/manifest/regress_manifest_validate.php';
        [$status, $output] = $this->runTool($root, '--plan', '--map=' . $this->writeMap($root, $map));

        // The literal pass follows the key, so the coupling holds and the plan
        // passes; the assertion is that the NEW key is what ends up in the file.
        self::assertSame(0, $status, $output);
        self::assertStringContainsString(
            "'sandbox/tests/offline/manifest/regress_manifest_validate.php'",
            $output
        );
    }

    // ------------------------------------------------------- pure helpers

    public function testRelativePathHelper(): void
    {
        self::assertSame('../../lib/check.php', ms_relpath('sandbox/tests/offline/thing', 'sandbox/tests/lib/check.php'));
        self::assertSame('../../../../agent/src/Kernel/Canon.php', ms_relpath('sandbox/tests/offline/thing', 'agent/src/Kernel/Canon.php'));
        self::assertSame('regress_thing.php', ms_relpath('sandbox/tests/offline/thing', 'sandbox/tests/offline/thing/regress_thing.php'));
    }

    public function testGlobTargetsReduceToTheirContainingDirectory(): void
    {
        // A glob names a pattern, not a file; the directory is the strongest
        // claim that still holds, and it still catches a lost level.
        self::assertSame('manifests', ms_join_target('', '/manifests/*.json'));
        self::assertSame('recovery', ms_join_target('', '/recovery/*.php'));
        self::assertSame('manifests/core.json', ms_join_target('', '/manifests/core.json'));
    }

    public function testLiteralHitsAcceptALeadingSlashButNotALongerName(): void
    {
        // `$root . '/sandbox/tests/regress_x.sh'` must match — four sites in the
        // real tree spell it that way, and skipping them produced dangling
        // references that only --prove caught.
        self::assertSame(1, ms_literal_hits("\$root . '/sandbox/tests/regress_x.sh'", 'sandbox/tests/regress_x.sh'));
        self::assertSame(1, ms_literal_hits('bash sandbox/tests/regress_x.sh', 'sandbox/tests/regress_x.sh'));
        self::assertSame(0, ms_literal_hits('mysandbox/tests/regress_x.sh', 'sandbox/tests/regress_x.sh'));
        self::assertSame(0, ms_literal_hits('sandbox/tests/regress_x.sh.bak', 'sandbox/tests/regress_x.sh'));
    }

    public function testNegativeExistenceAssertionsAreRecognised(): void
    {
        self::assertTrue(ms_line_negates_existence("check(!is_file(\$root . '/manifests/gone.php'), 'retired');"));
        self::assertTrue(ms_line_negates_existence('assert(is_dir($p) === false);'));
        self::assertFalse(ms_line_negates_existence("check(is_file(\$root . '/manifests/core.json'), 'ships');"));
    }
}

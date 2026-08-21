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
        // Reads like substrate, is NOT: the ratified layout moves it, so the
        // tool must let the map decide rather than a hardcoded stay-list.
        self::write($root . '/sandbox/tests/manifest_fixtures.php', "<?php\n// shared manifest fixtures\n");
        self::write($root . '/sandbox/tests/fixtures/vec/sample.json', "{\"vector\":1}\n");

        // An ancestor-anchored script: it cds to sandbox/, NOT to its own
        // directory, and names siterepo/ paths relative to that anchor.
        self::write($root . '/sandbox/siterepo/e1/state/.keep', '');
        self::write($root . '/sandbox/tests/lint_smoke.sh', <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

FIXDIR=siterepo/e1/.tmp-lint-fixture
[ -d siterepo/e1/state ] || { echo "seed first"; exit 1; }
echo "$FIXDIR"
SH);

        // The assert-on-path-string shape: this suite asserts that ANOTHER
        // file contains a literal naming a THIRD file. All three move to
        // different directories.
        self::write($root . '/sandbox/tests/regress_harness_contract.php', <<<'PHP'
<?php

declare(strict_types=1);

$harness = (string) file_get_contents(__DIR__ . '/regress_live_init.sh');
$needle = 'php sandbox/tests/manifest_fixtures.php "$SCRATCH"';
if (!str_contains($harness, $needle)) {
    fwrite(STDERR, "harness does not invoke the fixture builder\n");
    exit(1);
}
echo "ok\n";
PHP);

        self::write($root . '/sandbox/tests/regress_live_init.sh', <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
SCRATCH=$(mktemp -d)
php sandbox/tests/manifest_fixtures.php "$SCRATCH"
SH);

        // A data file that both MOVES and carries paths of its own.
        self::write($root . '/sandbox/tests/grind_matrix.json', <<<'JSON'
{
  "cases": [
    {"id": "a", "harness": "sandbox/tests/grind_walk.sh"},
    {"id": "b", "harness": "sandbox/tests/grind_walk.sh", "public_command": "php cli/duo promote target"}
  ]
}
JSON);
        self::write($root . '/sandbox/tests/grind_walk.sh', "#!/usr/bin/env bash\nset -euo pipefail\necho walk\n");

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

        // A cwd-`sandbox/` script that names a suite SANDBOX-relatively, the
        // spelling DUO-3482 taught the literal pass. Modelled on
        // regress_grind_r1c_manifest_preserve.sh:12,17. It stays at the corpus
        // root deliberately: class 6b is not gated on the referring file
        // having moved, and a stayer is the case W2 shipped broken.
        self::write($root . '/sandbox/tests/regress_cwd_sandbox.sh', <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

G=tests/grind_walk.sh
[ -f "$G" ] || { echo "$G is missing"; exit 1; }
grep -q walk "$G"
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
            // Reads like substrate; the map moves it, and the map wins.
            'sandbox/tests/manifest_fixtures.php' => 'sandbox/tests/offline/policy/manifest_fixtures.php',
            'sandbox/tests/lint_smoke.sh' => 'sandbox/tests/spike/lint_smoke.sh',
            'sandbox/tests/regress_harness_contract.php' => 'sandbox/tests/offline/cli/regress_harness_contract.php',
            'sandbox/tests/regress_live_init.sh' => 'sandbox/tests/live/regress_live_init.sh',
            'sandbox/tests/grind_matrix.json' => 'sandbox/tests/grind/grind_matrix.json',
            'sandbox/tests/grind_walk.sh' => 'sandbox/tests/grind/grind_walk.sh',
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
        self::assertStringContainsString('10 move(s) pending', $output);
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
        // manifest_fixtures.php reads like substrate but the map moves it, so
        // the reference follows it to its new directory rather than climbing
        // back to a corpus root it no longer lives at.
        self::assertStringContainsString("require_once __DIR__ . '/../policy/manifest_fixtures.php';", $php);
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

    /**
     * The map decides what moves; the only stay-list is MS_SUBSTRATE.
     *
     * `manifest_fixtures.php` reads like substrate — 12 suites require it by a
     * fixed sibling path — and the ratified layout moves it anyway, to
     * offline/policy/. A tool that privileged it by filename would either
     * refuse the ratified map or re-point every referrer at a corpus root the
     * file no longer lives at.
     */
    public function testAFileThatReadsLikeSubstrateMovesWhenTheMapSaysSo(): void
    {
        $root = $this->makeSyntheticRepo();
        [$status, $output] = $this->runTool($root, '--apply', '--map=' . $this->writeMap($root, $this->syntheticMap()));
        self::assertSame(0, $status, $output);

        self::assertFileDoesNotExist($root . '/sandbox/tests/manifest_fixtures.php');
        self::assertFileExists($root . '/sandbox/tests/offline/policy/manifest_fixtures.php');
        // The referrer followed it across directories, not up to the root.
        self::assertStringContainsString(
            "require_once __DIR__ . '/../policy/manifest_fixtures.php';",
            self::read($root . '/sandbox/tests/offline/thing/regress_thing.php')
        );
        // …while a real substrate reference still climbs to the corpus root.
        self::assertStringContainsString(
            "require_once __DIR__ . '/../../lib/check.php';",
            self::read($root . '/sandbox/tests/offline/thing/regress_thing.php')
        );
    }

    /**
     * A suite that asserts on a path STRING inside another file.
     *
     * `regress_init_contract.php:994` checks that regress_duo_init.sh contains
     * the literal `php sandbox/tests/certification_fixture.php "$HERMETIC_ROOT"`.
     * The asserting suite, the asserted script and the named helper all land in
     * different directories, so the needle and the line it looks for have to be
     * rewritten to the same new path or the moved suite fails on a string
     * comparison with nothing wrong underneath it.
     */
    public function testAnAssertionOnAPathStringStillMatchesTheFileItAssertsAbout(): void
    {
        $root = $this->makeSyntheticRepo();
        [$status, $output] = $this->runTool($root, '--apply', '--map=' . $this->writeMap($root, $this->syntheticMap()));
        self::assertSame(0, $status, $output);

        $moved = 'sandbox/tests/offline/policy/manifest_fixtures.php';
        self::assertStringContainsString(
            "\$needle = 'php $moved \"\$SCRATCH\"';",
            self::read($root . '/sandbox/tests/offline/cli/regress_harness_contract.php')
        );
        self::assertStringContainsString(
            "php $moved \"\$SCRATCH\"",
            self::read($root . '/sandbox/tests/live/regress_live_init.sh')
        );

        // The assertion is the test: both halves agree, so the suite passes.
        $out = [];
        exec('php ' . escapeshellarg($root . '/sandbox/tests/offline/cli/regress_harness_contract.php') . ' 2>&1', $out, $rc);
        self::assertSame(0, $rc, implode("\n", $out));
    }

    /**
     * An anchor that names an ANCESTOR other than the script's own directory.
     *
     * `lint_smoke.sh:20` is `cd "$(dirname "$0")/.."`, so the cwd is `sandbox/`
     * and the `siterepo/…` tokens below it are relative to that, not to the
     * script. Class 3 extends the run so the anchor keeps naming `sandbox/`,
     * and precisely because it does, those tokens must NOT be touched.
     * regress_ecommerce_developer_static.sh and grind_ecommerce_developer.sh
     * share the shape.
     */
    public function testAnAncestorAnchorKeepsItsTargetAndLeavesItsTokensAlone(): void
    {
        $root = $this->makeSyntheticRepo();
        [$status, $output] = $this->runTool($root, '--apply', '--map=' . $this->writeMap($root, $this->syntheticMap()));
        self::assertSame(0, $status, $output);

        $sh = self::read($root . '/sandbox/tests/spike/lint_smoke.sh');
        // One level deeper, so the run gains one and still lands on sandbox/.
        self::assertStringContainsString('cd "$(dirname "$0")/../.."', $sh);
        self::assertStringContainsString('FIXDIR=siterepo/e1/.tmp-lint-fixture', $sh);
        self::assertStringContainsString('[ -d siterepo/e1/state ]', $sh);

        $out = [];
        exec('bash ' . escapeshellarg($root . '/sandbox/tests/spike/lint_smoke.sh') . ' 2>&1', $out, $rc);
        self::assertSame(0, $rc, implode("\n", $out));
    }

    /**
     * A moved JSON data file gets class 6 applied to its own contents.
     *
     * grind_ecommerce_developer.matrix.json moves to grind/ AND carries 21
     * `"harness"` values naming grind_ecommerce_developer.sh, which moves in
     * the same wave.
     */
    public function testAMovedDataFileHasItsOwnPathsRewritten(): void
    {
        $root = $this->makeSyntheticRepo();
        [$status, $output] = $this->runTool($root, '--apply', '--map=' . $this->writeMap($root, $this->syntheticMap()));
        self::assertSame(0, $status, $output);

        $json = self::read($root . '/sandbox/tests/grind/grind_matrix.json');
        self::assertSame(2, substr_count($json, '"sandbox/tests/grind/grind_walk.sh"'));
        self::assertStringNotContainsString('"sandbox/tests/grind_walk.sh"', $json);
        // Prose in a neighbouring field is not a path and is left alone.
        self::assertStringContainsString('"php cli/duo promote target"', $json);
        self::assertIsArray(json_decode($json, true), 'the rewrite must leave valid JSON');
    }

    /**
     * Subset maps compose.
     *
     * W2 feeds the live/grind/certify/spike values and W3 the offline ones.
     * Applying them in sequence must land exactly where applying the whole map
     * at once lands — otherwise the wave order becomes load-bearing and a
     * half-migrated tree is a state nobody validated. Mid-split a file that
     * stays put may name one that moved, which is why the __DIR__ pass is not
     * gated on the referring file having moved.
     */
    public function testASplitMapAppliedInSequenceMatchesTheWholeMapAtOnce(): void
    {
        $whole = $this->makeSyntheticRepo();
        $map = $this->syntheticMap();
        [$status, $output] = $this->runTool($whole, '--apply', '--map=' . $this->writeMap($whole, $map));
        self::assertSame(0, $status, $output);

        $split = $this->makeSyntheticRepo();
        $first = [];
        $second = [];
        foreach ($map as $key => $value) {
            if (preg_match('#^sandbox/tests/(live|grind|certify|spike)/#', $value) === 1) {
                $first[$key] = $value;
            } else {
                $second[$key] = $value;
            }
        }
        self::assertNotEmpty($first);
        self::assertNotEmpty($second);

        file_put_contents($split . '/w2.json', (string) json_encode($first, JSON_UNESCAPED_SLASHES));
        file_put_contents($split . '/w3.json', (string) json_encode($second, JSON_UNESCAPED_SLASHES));
        [$s1, $o1] = $this->runTool($split, '--apply', '--map=' . $split . '/w2.json');
        self::assertSame(0, $s1, $o1);
        [$s2, $o2] = $this->runTool($split, '--apply', '--map=' . $split . '/w3.json');
        self::assertSame(0, $s2, $o2);

        // Compare the corpus and every rewritten file outside it.
        exec('diff -r ' . escapeshellarg($split . '/sandbox/tests') . ' '
            . escapeshellarg($whole . '/sandbox/tests') . ' 2>&1', $diff, $diffStatus);
        self::assertSame(0, $diffStatus, "split and whole trees differ:\n" . implode("\n", $diff));
        foreach (['Makefile', 'docs/guides/internals.md'] as $file) {
            self::assertSame(
                self::read($whole . '/' . $file),
                self::read($split . '/' . $file),
                "$file differs between the split and whole applies"
            );
        }

        // tearDown only removes the most recent temp root.
        self::removeTree($whole);
    }

    /**
     * A subset map is validated on its own terms: no totality check, and the
     * literal pass touches only paths the GIVEN map names. Without this, W2
     * could not run at all — 266 of the 335 corpus entries are absent from it.
     */
    public function testASubsetMapNeitherRequiresNorRewritesUnmappedFiles(): void
    {
        $root = $this->makeSyntheticRepo();
        $subset = ['sandbox/tests/certify_x.sh' => 'sandbox/tests/certify/certify_x.sh'];

        [$status, $output] = $this->runTool($root, '--apply', '--map=' . $this->writeMap($root, $subset));
        self::assertSame(0, $status, $output);

        self::assertFileExists($root . '/sandbox/tests/certify/certify_x.sh');
        // Everything the subset does not name is untouched, in place and in text.
        self::assertFileExists($root . '/sandbox/tests/regress_thing.php');
        self::assertStringContainsString(
            "require_once __DIR__ . '/lib/check.php';",
            self::read($root . '/sandbox/tests/regress_thing.php')
        );
        $makefile = self::read($root . '/Makefile');
        self::assertStringContainsString('bash sandbox/tests/certify/certify_x.sh', $makefile);
        self::assertStringContainsString('php sandbox/tests/regress_thing.php', $makefile);
    }

    // ------------------------------------- class 6b: sandbox-relative paths

    /**
     * The defect DUO-3482 names, at product level.
     *
     * regress_cwd_sandbox.sh cds to `sandbox/` and therefore spells the suite
     * it guards `tests/grind_walk.sh` — a real corpus reference carrying no
     * `sandbox/` prefix for class 6's needles to match. Before class 6b the map
     * moved grind_walk.sh into `grind/` and left this line naming nothing,
     * while `--plan` reported zero review items and `--prove` reported zero
     * dangling references. That is not a hypothetical: it is what W2 (#483)
     * shipped over seven suites and repaired by hand afterwards.
     */
    public function testACwdSandboxScriptFollowsAMovedSuiteUnderItsSandboxRelativeSpelling(): void
    {
        $root = $this->makeSyntheticRepo();
        $map = $this->writeMap($root, $this->syntheticMap());
        [$status, $output] = $this->runTool($root, '--apply', '--map=' . $map);
        self::assertSame(0, $status, $output);

        // The referring script does not move; only the file it names does.
        $script = $root . '/sandbox/tests/regress_cwd_sandbox.sh';
        self::assertFileExists($script);
        $text = self::read($script);
        self::assertStringContainsString('G=tests/grind/grind_walk.sh', $text);
        self::assertStringNotContainsString('G=tests/grind_walk.sh', $text);
        // The rewrite must not have reached for the repo-relative spelling:
        // this script's cwd is sandbox/, so `sandbox/tests/...` would break it.
        self::assertStringNotContainsString('sandbox/tests/grind/grind_walk.sh', $text);

        // …and it still does what it was written to do, from its own cwd.
        $run = [];
        exec('bash ' . escapeshellarg($script) . ' 2>&1', $run, $runStatus);
        self::assertSame(0, $runStatus, implode("\n", $run));

        [$clean, $proveOutput] = $this->runTool($root, '--prove', '--map=' . $map);
        self::assertSame(0, $clean, $proveOutput);
    }

    /**
     * The prover's half of the same rule: a class 6b rewrite that did NOT
     * happen must be caught, or the tool is back to reporting green over
     * references nobody looked at.
     */
    public function testProveCatchesASurvivingPreMoveSandboxRelativePath(): void
    {
        $root = $this->makeSyntheticRepo();
        $map = $this->writeMap($root, $this->syntheticMap());
        [$status] = $this->runTool($root, '--apply', '--map=' . $map);
        self::assertSame(0, $status);

        $script = $root . '/sandbox/tests/regress_cwd_sandbox.sh';
        file_put_contents($script, str_replace(
            'G=tests/grind/grind_walk.sh',
            'G=tests/grind_walk.sh',
            self::read($script)
        ));

        [$dangling, $output] = $this->runTool($root, '--prove', '--map=' . $map);
        self::assertSame(1, $dangling, $output);
        self::assertStringContainsString('sandbox/tests/regress_cwd_sandbox.sh', $output);
        self::assertStringContainsString('the pre-move sandbox-relative path tests/grind_walk.sh', $output);
    }

    /**
     * The disambiguation, exercised on the collision the tree does not have.
     *
     * The repo root has a `tests/` directory of its own — the PHPUnit
     * self-tests for tools/ — so `tests/regress_thing.php` inside a corpus file
     * could mean either tree. Measured on the real tree the collision set is
     * empty (all 20 distinct sandbox-relative spellings resolve under sandbox/
     * and none exists at the repo root), which is why the rule is allowed to
     * resolve rather than guess. This fixture manufactures the collision and
     * asserts the tool refuses instead of picking: the site goes to REVIEW,
     * `--plan` exits 1, and the line is left byte-identical.
     */
    public function testAnAmbiguousSandboxRelativeTokenGoesToReviewInsteadOfBeingGuessed(): void
    {
        $root = $this->makeSyntheticRepo();
        // A genuine repo-root PHPUnit file whose path collides with the
        // sandbox-relative spelling of a MAPPED corpus suite.
        self::write($root . '/tests/regress_thing.php', "<?php\n// the PHPUnit tree's own file\n");
        self::write($root . '/sandbox/tests/regress_ambiguous.sh', <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/
php tests/regress_thing.php
SH);

        $map = $this->writeMap($root, $this->syntheticMap());
        [$status, $output] = $this->runTool($root, '--plan', '--map=' . $map);
        self::assertSame(1, $status, $output);
        self::assertStringContainsString('sandbox/tests/regress_ambiguous.sh', $output);
        self::assertStringContainsString("'tests/regress_thing.php' is ambiguous", $output);
        self::assertStringNotContainsString('0 review item(s)', $output);

        // --apply refuses for the same reason, so the ambiguous line survives.
        [$applyStatus] = $this->runTool($root, '--apply', '--map=' . $map);
        self::assertSame(1, $applyStatus);
        self::assertStringContainsString(
            'php tests/regress_thing.php',
            self::read($root . '/sandbox/tests/regress_ambiguous.sh')
        );
    }

    /**
     * The rule is stated ONCE. Three standalone tools implement it three ways
     * on purpose (the codemod refuses an ambiguity, affected.php over-selects
     * through it, offline.php cannot reach it at all), so the thing that rots
     * is the STATEMENT, not any one regex — and a second copy of the statement
     * is how the three drift apart silently. This asserts one authority and two
     * pointers to it, which is the whole of what "stated once" can be checked
     * to mean.
     */
    public function testTheSandboxRelativeRuleIsStatedInExactlyOnePlace(): void
    {
        $repo = self::repoRoot();
        $authority = self::read($repo . '/tools/codemod/move-suites.php');
        self::assertStringContainsString(
            'THE SANDBOX-RELATIVE SUITE-REFERENCE RULE — stated once, HERE',
            $authority,
            'the codemod owns the statement'
        );

        foreach (['tools/affected.php', 'tools/offline.php'] as $consumer) {
            $text = self::read($repo . '/' . $consumer);
            self::assertStringContainsString(
                'ms_sandbox_relative_tail()',
                $text,
                "$consumer must point at the rule rather than restate it"
            );
            self::assertStringNotContainsString(
                'THE SANDBOX-RELATIVE SUITE-REFERENCE RULE',
                $text,
                "$consumer must not carry a second copy of the statement"
            );
        }

        // The candidate needle itself is quoted in the statement and compiled
        // in affected.php; if the two ever disagree, the prose is wrong about
        // what the reader does. affected.php is a pure-function file whose main
        // guard never fires under phpunit (see AffectedTest's header).
        if (!defined('AF_SANDBOX_RELATIVE_RX')) {
            require_once $repo . '/tools/affected.php';
        }
        self::assertStringContainsString(
            '(?<![A-Za-z0-9_./-])tests/[A-Za-z0-9_./-]+\.(?:sh|php)',
            $authority
        );
        self::assertSame(
            '#(?<![A-Za-z0-9_./-])tests/[A-Za-z0-9_./-]+\.(?:sh|php)#',
            AF_SANDBOX_RELATIVE_RX
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
        self::assertStringContainsString('the pre-move path (now at', $output);
    }

    /**
     * The prover's ONLY softening is a classification with evidence.
     *
     * A referent under a gitignored runtime root is exempt and says why; a
     * referent that simply is not there is a dangling reference and fails.
     * Both live in the same file and are reached by the same pass, so this is
     * the test that the exemption is a classification rather than a hole.
     */
    public function testTheProverExemptsClassifiedRuntimeTargetsButNotDanglingOnes(): void
    {
        $root = $this->makeSyntheticRepo();
        $map = $this->syntheticMap();

        // Exempt: sandbox/tmp/ is gitignored scratch (.gitignore:4).
        self::write($root . '/sandbox/tests/regress_runtime.php', <<<'PHP'
<?php

declare(strict_types=1);

$sandbox = dirname(__DIR__, 1);
$scratch = $sandbox . '/tmp/reference-env/origin.git';
$estate = $sandbox . '/siterepo/mup1';
echo "ok\n";
PHP);
        $map['sandbox/tests/regress_runtime.php'] = 'sandbox/tests/offline/thing/regress_runtime.php';
        exec('git -C ' . escapeshellarg($root) . ' add -A 2>&1');

        [$status, $output] = $this->runTool($root, '--apply', '--map=' . $this->writeMap($root, $map));
        self::assertSame(0, $status, $output);

        [$clean, $proveOutput] = $this->runTool($root, '--prove', '--map=' . $this->writeMap($root, $map));
        self::assertSame(0, $clean, $proveOutput);
        self::assertStringContainsString('gitignored scratch root', $proveOutput);
        self::assertStringContainsString('gitignored pair estate', $proveOutput);

        // Now add a referent that is NOT classified: same file, same pass.
        $moved = $root . '/sandbox/tests/offline/thing/regress_runtime.php';
        file_put_contents($moved, str_replace(
            "\$estate = \$sandbox . '/siterepo/mup1';",
            "\$estate = \$sandbox . '/tests/lib/absent_helper.php';",
            self::read($moved)
        ));

        [$dangling, $danglingOutput] = $this->runTool($root, '--prove', '--map=' . $this->writeMap($root, $map));
        self::assertSame(1, $dangling, $danglingOutput);
        self::assertStringContainsString('sandbox/tests/lib/absent_helper.php', $danglingOutput);
        self::assertStringContainsString('which does not exist', $danglingOutput);
        // …and the classified one is still exempt rather than swept up with it.
        self::assertStringContainsString('gitignored scratch root', $danglingOutput);
    }

    /**
     * DUO-3494: `.php-cs-fixer.dist.php` is an MS_SCAN_FILES member, so
     * ms_compute() scans and rewrites it REGARDLESS of $moved (the comment
     * above the __DIR__ pass at :1875 is why — a file nobody is moving can
     * still name one that moved). Its own `__DIR__ . '/.php-cs-fixer.cache'`
     * (.php-cs-fixer.dist.php:86) names php-cs-fixer's result cache, which is
     * gitignored (.gitignore:67) and absent until php-cs-fixer has run once —
     * so on any fresh clone/worktree that reference used to land in
     * UNPROVABLE. That is exactly what
     * testEmptyMapAgainstTheRealRepositoryIsAQuietNoOp caught against this
     * repo's own root file (CONTRIBUTING.md's first command produces exactly
     * that state). Reproduced here against a synthetic root so the assertion
     * is about the CLASSIFICATION rather than this developer machine's
     * cache-file history — paired with an unrelated `__DIR__` miss in the SAME
     * file, so a loosened match (e.g. "any repo-root reference the prover
     * can't find") would still fail this test.
     */
    public function testThePlanExemptsThePhpCsFixerCacheReferenceButNotAnUnrelatedOne(): void
    {
        $root = $this->makeSyntheticRepo();
        self::write($root . '/.php-cs-fixer.dist.php', <<<'PHP'
<?php

declare(strict_types=1);

$cache = __DIR__ . '/.php-cs-fixer.cache';
$bogus = __DIR__ . '/not-a-real-file.txt';
echo "$cache $bogus\n";
PHP);
        self::assertFileDoesNotExist($root . '/.php-cs-fixer.cache');

        [$status, $output] = $this->runTool($root, '--plan', '--map=' . $this->writeMap($root, $this->syntheticMap()));

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('1 unprovable site(s)', $output);
        self::assertStringNotContainsString('.php-cs-fixer.cache', $output);
        self::assertStringContainsString("not-a-real-file.txt' -> /not-a-real-file.txt", $output);
    }

    /**
     * PHP variables are function-scoped; this scan is file-scoped.
     *
     * `cli/src/Adapter/AdapterDraft.php` binds `$repo = dirname(__DIR__, 3)` in
     * boot() at :390 and separately declares `read_prior_manifest(string $repo,
     * …)` at :435, where `$repo` is a MANAGED SITE's repository. Resolving the
     * second against the first reported `$repo . '/site.duo.json'` as a
     * dangling reference to a file that only ever exists on a site.
     */
    public function testAFunctionParameterIsNotResolvedAsARootVariable(): void
    {
        $shared = token_get_all(<<<'PHP'
<?php
class Draft {
    private static function boot(): void {
        $repo = dirname(__DIR__, 3);
        $agent = $repo . '/agent/duo.php';
    }
    private static function read_prior(string $repo, string $name): void {
        $path = $repo . '/adapters/' . $name . '.json';
    }
}
PHP);
        self::assertArrayNotHasKey('repo', ms_php_root_vars($shared, 'cli/src/Adapter', 0));

        // A name never used as a parameter still resolves, or the fix would
        // have bought its precision by disabling the feature.
        $clean = token_get_all(<<<'PHP'
<?php
$duoRoot = dirname(__DIR__, 2);
$canon = $duoRoot . '/agent/src/Kernel/Canon.php';
PHP);
        self::assertSame(
            ['duoRoot' => ''],
            ms_php_root_vars($clean, 'sandbox/tests', 0)
        );
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

    /**
     * The SHIPPED map must stay executable against the tree it describes.
     *
     * The empty-map cases below prove the scan roots and the review contract
     * stay quiet; neither of them reads `tools/suite-layout.json`, and that gap
     * is what let the map rot unnoticed. `ms_load_map()` is_file()-checks every
     * row and refuses the WHOLE map when one names nothing at either path
     * ("exists at neither its old nor its new path"), which takes `--plan`,
     * `--apply` and `--prove` down together. So a suite deleted after its move
     * strands a row and silently disarms `--prove` — the only pre-merge check
     * that reaches the live/, grind/, certify/ and spike/ files, none of which
     * `make regress-offline-all` runs.
     *
     * That is not hypothetical. #486 deleted the two `offline/reference-scope`
     * wrappers after W3 moved them, left their rows behind (the map is in
     * MS_SCAN_EXCLUDE, so the codemod never rewrites its own input), and the
     * prover stayed refused from that merge until DUO-3482 found it by hand.
     * This case runs inside `composer check`, so the next such deletion fails
     * the loop the moment it lands.
     */
    public function testTheShippedLayoutMapStillPlansCleanAgainstTheRealTree(): void
    {
        $repo = self::repoRoot();
        $map = $repo . '/tools/suite-layout.json';
        self::assertFileExists($map);

        [$status, $output] = $this->runTool($repo, '--plan', '--map=' . $map);

        // Named explicitly: this refusal precedes every rewrite class, so it
        // reports as a map error rather than as anything about the corpus, and
        // the fix is to delete the stranded row (recording why in
        // tools/suite-layout.review.md), never to restore a deleted suite.
        self::assertStringNotContainsString('exists at neither its old nor its new path', $output);
        self::assertSame(0, $status, $output);

        // The whole map is applied, so a clean plan is also an empty one.
        self::assertStringContainsString('0 move(s) pending', $output);
        self::assertStringContainsString('0 review item(s)', $output);
    }

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

        // Against a full, realistic placement the sentinel line still comes
        // back unchanged. The placement re-homes every path the corpus holds
        // TODAY — the shipped map's values, not its keys. Two earlier spellings
        // of this fixture each went quietly vacuous: globbing the corpus root
        // stopped being realistic once the last wave emptied it, and a
        // placement keyed on the map's pre-move paths matches nothing in a file
        // that has already been rewritten to the new ones. Both still passed.
        $shipped = json_decode(
            self::read(self::repoRoot() . '/tools/suite-layout.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertIsArray($shipped);
        $placement = [];
        foreach ($shipped as $current) {
            $placement[$current] = 'sandbox/tests/offline/domain/' . basename((string) $current);
        }
        self::assertGreaterThan(300, count($placement), 'the shipped map is the realism this test depends on');

        $result = ms_rewrite_literals(self::repoRoot(), $affected, $placement, 'tools/affected.php');
        self::assertStringContainsString($sentinel, $result['text']);
        foreach ($result['changes'] as $change) {
            self::assertStringNotContainsString('ROUND 3 TRAIN 1', $change['from']);
        }
    }

    /**
     * The restructure lands one wave at a time, so `tools/suite-layout.json`
     * and its review record must keep naming pre-move paths until the last
     * wave lands — they are the map the next wave is derived from and the
     * record of what each placement was decided on. Both sit inside the
     * `tools` scan root, so without the exclusion the literal pass rewrites
     * every completed row to `"<new>": "<new>"` and the prover's stale-mention
     * check makes that corruption mandatory rather than optional.
     */
    public function testTheLayoutMapAndItsReviewRecordAreNeverRewritten(): void
    {
        $root = $this->makeSyntheticRepo();
        $record = "The ratified rename is\n"
            . "sandbox/tests/regress_thing.sh -> sandbox/tests/offline/thing/regress_thing.sh\n";
        self::write(
            $root . '/tools/suite-layout.json',
            (string) json_encode($this->syntheticMap(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
        self::write($root . '/tools/suite-layout.review.md', $record);
        exec('git -C ' . escapeshellarg($root) . ' add -A 2>&1');
        $mapBefore = self::read($root . '/tools/suite-layout.json');

        [$status, $output] = $this->runTool($root, '--apply', '--map=' . $this->writeMap($root, $this->syntheticMap()));
        self::assertSame(0, $status, $output);

        self::assertSame($mapBefore, self::read($root . '/tools/suite-layout.json'));
        self::assertSame($record, self::read($root . '/tools/suite-layout.review.md'));

        // The pre-move paths those two files still spell are not dangling
        // references — they are the record. --prove must stay green.
        [$clean, $proveOutput] = $this->runTool($root, '--prove', '--map=' . $this->writeMap($root, $this->syntheticMap()));
        self::assertSame(0, $clean, $proveOutput);
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
        // A path that ends a prose sentence is a hit: the full stop is not the
        // head of a longer name (regress_mup_leak_audit.sh:50 in W2).
        self::assertSame(1, ms_literal_hits('audited by sandbox/tests/regress_x.sh.', 'sandbox/tests/regress_x.sh'));
    }

    public function testNegativeExistenceAssertionsAreRecognised(): void
    {
        self::assertTrue(ms_line_negates_existence("check(!is_file(\$root . '/manifests/gone.php'), 'retired');"));
        self::assertTrue(ms_line_negates_existence('assert(is_dir($p) === false);'));
        self::assertFalse(ms_line_negates_existence("check(is_file(\$root . '/manifests/core.json'), 'ships');"));
    }

    /**
     * ms_runtime_created_reason()'s php-cs-fixer-cache entry (DUO-3494),
     * isolated from the file-scanning machinery exercised above.
     *
     * The LEADING SLASH in the key is not incidental. ms_rewrite_php()'s
     * class 2 pass computes a `__DIR__ . '<literal>'` target as
     * `ms_norm($oldDir . '/' . ltrim($literal, '/'))`; for every OTHER referrer
     * $oldDir is a non-empty directory, so the join never starts with '/' and
     * ms_norm() returns a plain relative path — the shape 'sandbox/unsafe1' and
     * 'agent/scoped-promotion-control.json' both use. .php-cs-fixer.dist.php is
     * the one MS_SCAN_FILES member ms_is_php_path() accepts that sits at the
     * repo ROOT (oldDir === ''), so its own join already starts with '/' before
     * ms_norm() ever sees it, and ms_norm() keeps that leading slash rather
     * than stripping it. A key without it is a DIFFERENT string and must not
     * match — which is what the second assertion pins.
     */
    public function testRuntimeCreatedReasonExemptsThePhpCsFixerCacheByItsExactTarget(): void
    {
        $reason = ms_runtime_created_reason('/.php-cs-fixer.cache');
        self::assertNotNull($reason);
        self::assertStringContainsString('php-cs-fixer', $reason);

        // Without the leading slash it is a different string — a loosened
        // match (str_ends_with, basename comparison) would pass this test
        // vacuously; the exact isset() lookup does not.
        self::assertNull(ms_runtime_created_reason('.php-cs-fixer.cache'));
        // An unrelated repo-root __DIR__ miss is still a dangling reference,
        // not swept up by the same classification.
        self::assertNull(ms_runtime_created_reason('/not-a-real-file.txt'));
    }
}

<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

use Duo\Lint;
use Duo\LintEnvironment;
use Duo\Policy;

/**
 * `duo lint-tree` — the suspicious-reference linter as a WordPress-free host verb.
 *
 * `wp duo lint` has always been the only way to lint a captured tree, which
 * means the review bottleneck — read every finding, decide which are refs and
 * which are coincidences, write the `lint_ok` declarations — could only be
 * worked from a loaded target. This command moves that loop to the laptop
 * beside `duo manifest-validate`, whose own docblock states the pattern this
 * one follows: nothing is reimplemented, the REAL engine does the work, and
 * the checks that genuinely need a live target are printed on EVERY run.
 *
 * The verb is `lint-tree`, not `lint`, for a reason worth stating: `duo lint
 * <env>` already exists and is a PASSTHROUGH (`cli/duo:1380`) that runs
 * `wp duo lint --repo=<repo_path>` over an environment's transport. Taking the
 * name would have silently replaced an environment-bound verb with a
 * repo-bound one whose first argument means something else entirely. The name
 * chosen is the engine method both verbs call, `Lint::scan_tree()`.
 *
 * ONE IMPLEMENTATION, NOT TWO. `Lint::scan_tree()` is the same static method
 * both verbs call. Everything it reads that is not a byte of the state tree —
 * the home URL, every `Pending::resolve_id()` answer, the probed column types
 * — reaches it through a `Duo\LintEnvironment`, which the live verb RECORDS
 * (`wp duo lint --emit-environment=<file>`) and this one REPLAYS. So the
 * findings are byte-identical to the live scan's by construction: the two
 * calls differ in which environment object they hand one method, and in
 * nothing else. `sandbox/tests/offline/cli/regress_lint_host_verb.php` asserts
 * that against the real subprocess, over the real `--format=json` bytes.
 *
 * WHAT THIS PROCESS CANNOT DO, and therefore says on every run: WordPress's
 * own parsers — `parse_blocks()`, `get_shortcode_regex()`,
 * `shortcode_parse_atts()` — do not exist here, and their output is a function
 * of state-tree bytes rather than of the environment, so it cannot be recorded
 * either (a transcript of every post's parsed block tree would be a second
 * copy of the tree). The block and shortcode attribute classes are therefore
 * DEFERRED, named in the engine's own words on stderr, on a clean run and a
 * dirty one alike. `duo adapter-draft` already draws the same boundary for
 * itself (`AdapterDraft.php:1718`, "unavailable in this pure process"), and
 * `manifest-validate` already established why the list prints unconditionally:
 * a tool that shows its limitations only when something goes wrong lets
 * silence read as "everything was checked".
 *
 * The provenance block and the deferred list go to STDERR, never stdout.
 * Stdout is exactly what `wp duo lint` prints — the shared
 * `Lint::render_lines()` rendering, or the same
 * `json_encode($findings, JSON_UNESCAPED_SLASHES)` line — so a caller can
 * diff the two verbs byte for byte without filtering anything out, which is
 * the whole claim this command makes about itself.
 */
final class LintTree {
    /**
     * @param list<string> $args everything after the verb
     * @return int 0 clean, 1 findings exist (the live verb's own exit contract), 2 usage/IO
     */
    public static function run(array $args): int {
        $repoArg = null;
        $environmentArg = null;
        $json = false;

        // A repeated flag is refused rather than last-wins, the posture every
        // host verb here already takes (ManifestValidate::run()): a second
        // --environment silently replacing the first would replay a transcript
        // the caller did not ask for and label the findings as though they had.
        $seen = [];
        foreach ($args as $arg) {
            $flag = str_starts_with($arg, '--') ? explode('=', $arg, 2)[0] : null;
            if ($flag !== null) {
                if (isset($seen[$flag])) {
                    return self::fail("duplicate flag '$flag'");
                }
                $seen[$flag] = true;
            }
            if ($arg === '--format=json') {
                $json = true;
            } elseif (str_starts_with($arg, '--environment=')) {
                $environmentArg = trim(substr($arg, strlen('--environment=')));
                if ($environmentArg === '') {
                    return self::fail('--environment needs the path of a duo-lint-environment/v1 file');
                }
            } elseif (str_starts_with($arg, '--evidence=')) {
                // The probe belongs to the RECORDING, not to the replay. Column
                // types supplied here could differ from the ones the live scan
                // used, and the two verbs would then disagree for a reason that
                // has nothing to do with the tree — the one failure mode this
                // command exists to make impossible.
                return self::fail(
                    '--evidence belongs on the live verb, which records the probe into the transcript: '
                    . '`wp duo lint --repo=<repo> --evidence=<probe.json> --emit-environment=<file>`, then '
                    . 'replay that file here'
                );
            } elseif (str_starts_with($arg, '-')) {
                return self::fail("unsupported flag '$arg'");
            } elseif ($repoArg !== null) {
                return self::fail("expected exactly one <site-repo>, got a second argument '$arg'");
            } else {
                $repoArg = $arg;
            }
        }

        if ($repoArg === null) {
            return self::fail('a <site-repo> argument is required (the directory holding site.duo.json)');
        }
        $repo = is_dir($repoArg) ? realpath($repoArg) : false;
        if ($repo === false) {
            return self::fail("'$repoArg' is not a directory");
        }
        if (!is_file($repo . '/site.duo.json')) {
            return self::fail(
                "'$repo' has no site.duo.json — this verb takes the duo SITE REPO, whose policy the captured "
                . 'tree under state/ is linted against'
            );
        }
        if ($environmentArg === null) {
            // Refused rather than defaulted. Without a transcript this process
            // has no home URL (core.json declares `home` class:env, so capture
            // excludes it) and no way to resolve an id, and a scan that skipped
            // both would report a strictly smaller finding set than the live
            // verb while looking like the same command.
            return self::fail(
                '--environment=<file> is required: id resolution and the home URL are facts about a live target, '
                . 'not bytes of the tree. Record them with `wp duo lint --repo=<repo> --emit-environment=<file>` '
                . 'on the target, then replay that file here'
            );
        }
        if (!is_file($environmentArg) || !is_readable($environmentArg)) {
            return self::fail("--environment '$environmentArg' is not a readable file");
        }

        try {
            self::boot();
        } catch (\Throwable $t) {
            return self::fail($t->getMessage());
        }

        try {
            $document = json_decode((string) file_get_contents($environmentArg), true);
            if (!is_array($document) || array_is_list($document)) {
                return self::fail("--environment '$environmentArg' is not a JSON object");
            }
            $environment = LintEnvironment::recorded($document);
            $policy = Policy::load($repo);
            $findings = Lint::scan_tree($repo . '/state', $policy, $environment);
        } catch (\Throwable $t) {
            return self::fail($t->getMessage());
        }

        if ($json) {
            echo json_encode($findings, JSON_UNESCAPED_SLASHES) . "\n";
        } else {
            foreach (Lint::render_lines($findings) as $line) {
                echo $line . "\n";
            }
            if ($findings !== []) {
                echo "\n";
            }
        }
        self::provenance($environmentArg, $document, $environment, count($findings));
        return $findings === [] ? 0 : 1;
    }

    /**
     * The stderr half: where these findings came from, and what was NOT
     * scanned.
     *
     * Printed on every run, clean or dirty, for `manifest-validate`'s reason.
     * The deferrals are read back from the environment the scan just used, so
     * they are the engine's own sentences (`Lint::BLOCK_DEFERRAL` /
     * `SHORTCODE_DEFERRAL`) rather than a second description of the same
     * limitation maintained here.
     *
     * @param array<string,mixed> $document
     */
    private static function provenance(
        string $path,
        array $document,
        LintEnvironment $environment,
        int $findingCount
    ): void {
        $scanned = $environment->scanned();
        $probe = $document['probe_hash'] ?? null;
        fwrite(STDERR, "\n");
        fwrite(STDERR, "duo lint-tree (host, no WordPress) — findings: $findingCount\n");
        fwrite(STDERR, '  environment:  ' . $path . "\n");
        fwrite(STDERR, '  home:         ' . $environment->home() . "\n");
        fwrite(STDERR, '  state tree:   ' . (string) ($document['state_hash'] ?? '(none)') . "\n");
        fwrite(STDERR, '  resolutions:  ' . count((array) ($document['entities'] ?? []))
            . " recorded id lookups replayed\n");
        fwrite(STDERR, '  column types: ' . (is_string($probe)
            ? 'from probe ' . $probe
            : 'none recorded — no `lint_ok` proposal can be made without a live column type') . "\n");
        fwrite(STDERR, '  recorded scan: blocks=' . ($scanned['blocks'] ? 'yes' : 'no')
            . ', shortcodes=' . ($scanned['shortcodes'] ? 'yes' : 'no') . "\n");
        $deferrals = $environment->deferrals();
        if ($deferrals === []) {
            fwrite(STDERR, "  deferred:     nothing — every scan class this tree could produce was performed\n");
            return;
        }
        fwrite(STDERR, '  deferred (this process could not perform these classes; the tree may hold findings '
            . "they would have reported):\n");
        foreach ($deferrals as $deferral) {
            fwrite(STDERR, '    - ' . $deferral . "\n");
        }
    }

    /**
     * Load the engine's lint surface into this WordPress-free process.
     *
     * The same shape as `ManifestValidate::boot()` and `AdapterDraft::boot()`,
     * including resolving both version constants out of `agent/duo.php`'s own
     * source rather than repeating a literal, plus the Review files this verb
     * drives. `Lint.php` `require_once`s its five scanner collaborators,
     * `LintFinding`, `LintEnvironment` and `StateTreeWalker` itself; what it
     * reaches lazily (`Pending`, `ReferenceRules`, `OptionState`) arrives
     * through the additive agent classmap `cli/duo` already registers.
     */
    private static function boot(): void {
        $repo = dirname(__DIR__, 3);
        $agent = $repo . '/agent/duo.php';
        if (!is_file($agent)) {
            throw new \RuntimeException("lint-tree: agent source not found at $agent");
        }
        $source = (string) file_get_contents($agent);
        if (!defined('DUO_AGENT_VERSION')) {
            if (preg_match("/define\('DUO_AGENT_VERSION', '([^']+)'\)/", $source, $m) !== 1) {
                throw new \RuntimeException('lint-tree: could not resolve DUO_AGENT_VERSION');
            }
            define('DUO_AGENT_VERSION', $m[1]);
        }
        if (!defined('DUO_SPEC_VERSION')) {
            if (preg_match("/define\('DUO_SPEC_VERSION', ([0-9]+)\)/", $source, $m) !== 1) {
                throw new \RuntimeException('lint-tree: could not resolve DUO_SPEC_VERSION');
            }
            define('DUO_SPEC_VERSION', (int) $m[1]);
        }
        $duoAgentClassmap = require $repo . '/agent/duo-classmap.php';
        if (!is_array($duoAgentClassmap)) {
            throw new \RuntimeException('lint-tree: agent/duo-classmap.php did not return a map');
        }
        $duoAgentFiles = [];
        foreach ($duoAgentClassmap as $duoAgentPath) {
            $duoAgentFiles[basename((string) $duoAgentPath, '.php')] = (string) $duoAgentPath;
        }
        foreach (['Canon', 'OptionState', 'ManifestDispositions', 'Policy', 'Lint'] as $class) {
            $duoAgentFile = $duoAgentFiles[$class] ?? null;
            if (!is_string($duoAgentFile)) {
                throw new \RuntimeException('lint-tree: agent source ' . $class . '.php is absent from agent/duo-classmap.php');
            }
            require_once $repo . '/agent/' . $duoAgentFile;
        }
    }

    private static function fail(string $message): int {
        fwrite(STDERR, "duo: lint-tree: $message\n");
        return 2;
    }
}

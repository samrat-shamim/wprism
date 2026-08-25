<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

use Duo\AdapterRegistry;
use Duo\AdapterSources;
use Duo\Canon;
use Duo\ManifestDispositions;
use Duo\Policy;

/**
 * `duo adapter list|inspect|doctor` — what is installed, what it is allowed to
 * do, and what is wrong with it.
 *
 * Every fact this command prints already existed and was already enforced. It
 * was just not reachable as an answer to the question an operator actually
 * has. `wp duo capabilities --all` reports the shipped library and only the
 * shipped library; `--repo` reports exactly the pinned set and nothing else;
 * `duo manifest-validate` answers a grammar question about a directory;
 * `wp duo manifest-pin` answers about one adapter. None of them says "here is
 * everything installed on this machine, where each piece came from, what
 * executable authority its own declarations reach, and which of them is
 * broken" — and the operator most in need of that answer is the one whose
 * repository is in a state that makes every other command refuse.
 *
 * That last clause is the design constraint. `AdapterSources::discover()`
 * refuses a shadowed adapter, an ambiguous identity, or a confusable name by
 * THROWING, whole-directory, before any pin resolves — correct for a command
 * that is about to load a manifest, and useless for a command whose entire
 * job is to report those states. So the scan grew a reporting mode rather
 * than a second implementation (`AdapterSources::survey()`); the refusal rows
 * here carry the engine's own message, byte for byte, with a stable code and
 * the remediation beside it. A doctor that paraphrased the engine would be a
 * second copy of the rules, drifting from the day it shipped.
 *
 * WordPress-free by construction, the same way `duo manifest-validate` is: no
 * environment, no transport, no registry service, no database. Everything it
 * reads is on this machine — the agent's own manifest library, the reviewed
 * dispositions beside it, the capability claim those dispositions project, and
 * (with `--repo`) one site repository's `adapters/` source and its pins.
 *
 * That is also the exact reason this command cannot be the whole answer. The
 * engine has THREE adapter sources since DUO-3339, and the third —
 * `<plugin-dir>/duo-adapter.json`, bundled by an active plugin — lives in
 * WP_PLUGIN_DIR, which a WordPress-free host process does not have and must
 * not invent. So the `sources` block of every report says which of the three
 * this run actually reached, rendered FROM the survey rather than asserted in
 * prose here, and the deferred list points at `wp duo adapter-survey`, which
 * is the same survey running ON the target where that source exists.
 *
 * Two boundaries it states rather than hides, both in the always-emitted
 * `deferred` list:
 *
 *   1. It never LOADS manifest-shipped PHP. A declared `interpreter` or
 *      `regen_dependency.regenerator` is reported — it is the basis of the
 *      trust tier, and the tier is the whole point — but resolving one means
 *      running its top level and its constructor. An inventory of what is
 *      installed must not be the command that executes it; `duo
 *      manifest-validate` is where that trust decision is taken deliberately.
 *   2. It answers nothing about a live target. Whether a declared plugin is
 *      installed, active, and inside its version window, whether a provider
 *      answers, and whether a claim is certified for one environment's
 *      WordPress/PHP/database are facts about that environment. `duo
 *      capabilities <env>` and `duo plan <env>` are where those get answered.
 *
 * Neither boundary is a footnote: they are printed on every run, passing or
 * failing, for the reason `duo manifest-validate`'s own docblock gives — a
 * tool that listed its limits only when something went wrong would let
 * silence read as "everything about these adapters is verified".
 */
final class AdapterCatalog {
    /**
     * Envelope of the catalog report (both output modes carry it).
     *
     * v2 is DUO-3339's plugin source: the document gained `sources` (which of
     * the three this process could reach) and `not_installed` (an adapter that
     * is on this disk and lost to a higher-precedence definition), and every
     * refusal row gained `source` and `scope`. A consumer written against v1
     * would read a v2 report as complete while missing an entire source, so
     * the generation moves.
     */
    public const FORMAT = 'duo-adapter-catalog/v2';

    private const VERBS = ['list', 'inspect', 'doctor'];

    /**
     * Checks this command does not perform, with the engine symbol that owns
     * each one so a reader can go read it instead of taking this list's word.
     * Emitted unconditionally — see this class's docblock.
     *
     * The first two rows are not live-target rows and never become false:
     * they are limits of what an offline inventory IS, and an operator who
     * read a clean `doctor` without them could reasonably believe the adapter
     * code had been contract-checked and the pin set co-validated. Neither is
     * true here.
     *
     * @param list<array<string,mixed>> $sources the survey's own source inventory
     * @return list<array{status:string, surface:string, check:string, why:string}>
     */
    private static function deferred(?string $repo, bool $sourceRefused, array $sources): array {
        $unscanned = [];
        foreach ($sources as $source) {
            if (empty($source['scanned'])) {
                $unscanned[(string) $source['source']] = (string) ($source['note'] ?? '');
            }
        }
        $rows = [
            [
                'surface' => 'interpreter / post_types[].regen_dependency.regenerator / providers[].source=manifest',
                'check' => 'Policy::interpreters() / Policy::regenerators() / Providers::diagnose()',
                'why' => 'the manifest-shipped PHP these name is REPORTED here (it is what the trust tier is '
                    . 'derived from) and deliberately never loaded: checking that a file defines its contract '
                    . 'class requires running its top level and its constructor, which an inventory of what is '
                    . 'installed must not do. `duo manifest-validate <manifests-dir>` takes that trust decision '
                    . 'explicitly and reports the class-contract verdict',
            ],
            [
                'surface' => 'the pinned SET (cross-manifest guards)',
                'check' => 'CrossManifestGuards::validate_one_owner_per_declared_name() / '
                    . 'ActionProviderGrammar::validate_no_conflicting_provider_ids() / '
                    . 'Policy::validate_no_conflicting_adapter_claims()',
                'why' => 'each adapter\'s grammar verdict here is an ISOLATED load, so a manifest can read `ok` '
                    . 'and still be illegal in company — one owner per declared name, globally unique provider '
                    . 'ids, and one plugin/theme range per claim are properties of a SET. '
                    . '`duo manifest-validate <manifests-dir> --pins=<a,b,...>` co-loads a set and runs them',
            ],
            [
                'surface' => 'site.duo.json policy.tables / policy.options',
                'check' => 'ReferenceKindGrammar::validate_ref_kinds() / CrossManifestGuards::validate_no_conflicting_option_rules()',
                // Three states, not two: a run that WAS given --repo but whose
                // source carries a refusal did not get to use it either, and
                // saying otherwise would be the one claim on this list that is
                // simply false for that run.
                'why' => $repo === null
                    ? 'both guards take the SITE half of policy as INPUT: a table declared in site.duo.json '
                        . 'extends the legal ref/token/ledger kind vocabulary, and a site policy.options rule is '
                        . 'the explicit resolution for one option two manifests declare differently. Without '
                        . '--repo=<site-repo> the grammar verdicts above were produced with no site policy at '
                        . 'all, so one can read `error` for an adapter its real site accepts'
                    : ($sourceRefused
                        ? '--repo was given, but this run refused something in that repository, and the engine '
                            . 'refuses an adapter source WHOLE-DIRECTORY. So the shipped grammar verdicts above '
                            . 'were produced with no site policy after all — exactly as a run without --repo '
                            . 'produces them, and with the same caveat — and no site adapter\'s own grammar was '
                            . 'judged at all. Resolve the refusals and re-run for the site-policy-aware verdicts'
                        : 'the grammar verdicts above were produced against the site.duo.json on THIS machine. '
                            . 'Whether the target runs that revision of the repository is a fact about the target'),
            ],
            [
                'surface' => 'plugin / version_range / theme / theme_range / providers[] identity',
                'check' => 'Providers::diagnose() / Deploy::code_mismatch() / Deploy::code_drift()',
                'why' => 'whether a declared plugin or theme is installed, active, and inside its declared '
                    . 'window, and whether the provider code registered under a declared id exists and matches '
                    . 'its identity, are facts about one target filesystem and one running WordPress. Only the '
                    . 'shape of the claim is checkable here — run `duo plan <env>`, whose provider_problems rows '
                    . 'are the negotiated answer',
            ],
            [
                'surface' => 'dispositions/ against a target',
                'check' => 'AdapterRegistry::report()',
                'why' => 'certification is a reviewed claim evaluated against one target: whether the plugin the '
                    . 'claim is authored for is installed, active, and inside the reviewed version window. The '
                    . 'reviewed status and its authored evidence citation are read here; whether the claim holds '
                    . 'FOR YOUR SITE is not — run `duo capabilities <env>` for that',
            ],
            [
                'surface' => 'the ' . AdapterSources::PLUGIN . ' adapter source ('
                    . AdapterSources::PLUGIN_PATH_PREFIX . '/<plugin-dir>/' . AdapterSources::PLUGIN_FILE . ')',
                'check' => 'AdapterSources::survey() on the target — `wp duo adapter-survey`',
                // Rendered FROM the survey's own source inventory rather than
                // restated here. Two CLIs describing one scan in two hand-
                // written paragraphs is exactly how a catalog ends up
                // describing an engine that no longer exists — which is what
                // this row itself was, until DUO-3339: it said the engine had
                // "exactly two adapter sources" and that a plugin-bundled
                // manifest "is discovered by nothing".
                'why' => 'the engine has THREE adapter sources: the shipped library, <site-repo>/'
                    . AdapterSources::SITE_DIR . '/, and one ' . AdapterSources::PLUGIN_FILE
                    . ' at the root of each ACTIVE plugin that bundles one. This process scanned '
                    . self::source_summary($sources) . '. A bundled adapter is discoverable only where '
                    . 'WP_PLUGIN_DIR exists, so run `wp duo adapter-survey [--repo=<path>]` on the target for '
                    . 'that source'
                    . (isset($unscanned[AdapterSources::PLUGIN])
                        ? ' — ' . $unscanned[AdapterSources::PLUGIN]
                        : '')
                    . '. An independently distributed adapter PACKAGE is not a fourth source: it installs into '
                    . 'the site source as ' . AdapterSources::SITE_DIR . '/<name>.json plus '
                    . AdapterSources::SITE_DIR . '/' . AdapterSources::CERTIFICATION_DIR . '/<name>.json, which '
                    . 'this command already surveys with --repo',
            ],
        ];
        foreach ($rows as $i => $row) {
            $rows[$i] = ['status' => 'deferred'] + $row;
        }
        return $rows;
    }

    /**
     * "shipped and site (plugin not scanned)" — one clause, built from the
     * survey's own rows so it cannot say something the scan did not do.
     *
     * @param list<array<string,mixed>> $sources
     */
    private static function source_summary(array $sources): string {
        $scanned = [];
        $skipped = [];
        foreach ($sources as $source) {
            $name = (string) $source['source'];
            if (!empty($source['scanned'])) {
                $scanned[] = $name;
            } else {
                $skipped[] = $name;
            }
        }
        return ($scanned === [] ? 'no source' : implode(' and ', $scanned))
            . ($skipped === [] ? '' : ' (' . implode(', ', $skipped) . ' not scanned)');
    }

    /**
     * @param list<string> $args everything after the verb
     * @return int 0 healthy, 1 a refusal/blocker/grammar error was surfaced, 2 usage/IO
     */
    public static function run(array $args): int {
        // `doctor --migration` is a different question with a different
        // document (`duo-migration-preflight/v1`), so it gets its own owner and
        // its own parser rather than a branch inside the loop below. Same
        // split, same reason as `AdapterCertify::VERBS` in cli/duo: keeping the
        // read-only catalog's flag loop and every refusal string it produces
        // exactly where they were is what stops a new mode from moving the
        // output of an existing one (AGENTS.md rule 8).
        if (in_array('--migration', $args, true)) {
            return MigrationPreflight::run($args);
        }

        $verb = null;
        $name = null;
        $repoArg = null;
        $json = false;

        // A repeated flag is refused rather than last-wins, the posture
        // `duo manifest-validate` and `duo driver-capabilities` both take: a
        // second --repo silently replacing the first would survey a source
        // the operator did not ask about and report it as though they had.
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
            } elseif (str_starts_with($arg, '--repo=')) {
                $repoArg = trim(substr($arg, strlen('--repo=')));
                if ($repoArg === '') {
                    return self::fail('--repo needs the path of a duo site repo (the directory holding site.duo.json)');
                }
            } elseif (str_starts_with($arg, '-')) {
                return self::fail("unsupported flag '$arg'");
            } elseif ($verb === null) {
                $verb = $arg;
            } elseif ($name === null) {
                $name = $arg;
            } else {
                return self::fail("unexpected argument '$arg'");
            }
        }

        if ($verb === null) {
            return self::fail('a subcommand is required: ' . implode(' | ', self::VERBS));
        }
        if (!in_array($verb, self::VERBS, true)) {
            return self::fail("unknown subcommand '$verb' (expected " . implode(' | ', self::VERBS) . ')');
        }
        if ($verb === 'inspect' && ($name === null || $name === '')) {
            return self::fail('inspect needs an adapter name: duo adapter inspect <name> [--repo=<site-repo>]');
        }
        if ($verb !== 'inspect' && $name !== null) {
            return self::fail("'$verb' takes no positional argument, got '$name'");
        }

        // A directory that is not a duo site repo would fail once per adapter
        // with the engine's "not a duo site repo?" message, which reads as
        // "your adapters are broken". It is a usage error about the flag, so
        // it is refused here, once, as one — `duo manifest-validate`'s
        // precedent for exactly this.
        $repo = null;
        if ($repoArg !== null) {
            $resolved = is_dir($repoArg) ? realpath($repoArg) : false;
            if ($resolved === false) {
                return self::fail("--repo '$repoArg' is not a directory");
            }
            if (!is_file($resolved . '/site.duo.json')) {
                return self::fail(
                    "--repo '$resolved' has no site.duo.json — --repo takes the duo SITE REPO (the directory "
                    . 'holding site.duo.json), whose adapters/ source and pins this catalog reports'
                );
            }
            $repo = $resolved;
        }

        try {
            self::boot();
        } catch (\Throwable $t) {
            return self::fail($t->getMessage());
        }

        $manifestDir = Policy::manifests_dir();
        if (!is_dir($manifestDir)) {
            return self::fail("the agent manifest library '$manifestDir' is not a directory");
        }

        try {
            $survey = AdapterSources::survey($repo);
        } catch (\Throwable $t) {
            // survey() reports rather than throws by construction, so reaching
            // here means the library itself is unreadable — an IO fault about
            // this command's inputs, not a verdict about anybody's adapter.
            return self::fail($t->getMessage());
        }

        $report = [
            'format' => self::FORMAT,
            'spec_version' => DUO_SPEC_VERSION,
            'command' => $verb,
            'manifests_dir' => $manifestDir,
            'repo' => $repo,
            'sources' => $survey['sources'],
            'not_installed' => $survey['not_installed'],
            'refusals' => $survey['refusals'],
            'deferred' => self::deferred($repo, self::source_refused($survey['refusals']), $survey['sources']),
        ];

        if ($verb === 'inspect') {
            $row = null;
            foreach ($survey['adapters'] as $adapter) {
                if ($adapter['name'] === $name) {
                    $row = $adapter;
                }
            }
            if ($row === null) {
                // "Not installed" and "installed but refused" are different
                // answers, and only one of them is a usage error. A file the
                // scan refused is absent from the adapter rows by design, so
                // without this an operator inspecting the very adapter they
                // just installed was told it does not exist — while it sat on
                // disk with a named, remediable refusal against it. Report the
                // refusal and exit 1 (a surfaced finding), not 2 (bad input).
                $about = array_values(array_filter(
                    $survey['refusals'],
                    static fn(array $r): bool => in_array(
                        AdapterSources::SITE_DIR . "/$name.json",
                        (array) ($r['paths'] ?? []),
                        true
                    ) || in_array(
                        AdapterSources::SITE_DIR . '/' . AdapterSources::CERTIFICATION_DIR . "/$name.json",
                        (array) ($r['paths'] ?? []),
                        true
                    )
                ));
                // A shadowed adapter is a third answer again, and the one an
                // operator is most likely to be confused by: the file IS
                // installed, it is not refused, and the name resolves to
                // somebody else. Saying "no adapter named x is installed"
                // there would contradict the row `list` prints on every run.
                $shadowed = array_values(array_filter(
                    $survey['not_installed'],
                    static fn(array $r): bool => ($r['name'] ?? null) === $name
                ));
                if ($about === [] && $shadowed !== []) {
                    $report['adapter'] = null;
                    $report['status'] = 'ok';
                    if ($json) {
                        echo self::encode($report) . "\n";
                    } else {
                        echo "manifests dir: {$report['manifests_dir']}\n";
                        echo 'site repo:     ' . ($report['repo'] ?? '(none)') . "\n";
                        echo "\nADAPTER $name — INSTALLED, NOT LOADED. Another definition answers to this name:\n";
                        self::render_not_installed($shadowed);
                        echo "\nInspect the definition that WON to see what this site actually runs.\n";
                    }
                    // Not an error: the engine resolved a collision by the
                    // rule it exists to apply, and reported it. See render().
                    return 0;
                }
                if ($about === []) {
                    return self::fail(
                        "no adapter named '$name' is installed in $manifestDir"
                        . ($repo === null
                            ? " (pass --repo=<site-repo> to include that repository's own adapters/ source)"
                            : " or in $repo/" . AdapterSources::SITE_DIR)
                    );
                }
                $report['adapter'] = null;
                $report['refused'] = $about;
                $report['status'] = 'error';
                if ($json) {
                    echo self::encode($report) . "\n";
                } else {
                    echo "manifests dir: {$report['manifests_dir']}\n";
                    echo 'site repo:     ' . ($report['repo'] ?? '(none)') . "\n";
                    echo "\nADAPTER $name — INSTALLED, AND REFUSED. It is on disk, and the scan will not load "
                        . "it:\n";
                    self::render_refusals($about);
                    echo "\nNo further detail is available: the refusal happens before this adapter's own "
                        . "manifest is read, so there is nothing yet to inspect.\n";
                }
                return 1;
            }
            $report['adapter'] = self::inspect_row($row, $manifestDir, $repo, $survey['adapters']);
            // `inspect` reports one adapter, but exit 0 is a claim about the
            // whole run, so it holds to the same bar `list` and `doctor` do.
            // Three ways it does not:
            //   - a refusal was surfaced. Even about a different file: the
            //     source this adapter lives in is broken, and a zero exit
            //     would tell a script the opposite.
            //   - the adapter's grammar is anything but ok — including
            //     `blocked_by_source_refusal`, which is not a pass.
            //   - --repo was given and no verdict came back. A null verdict
            //     means the pin set would not load, so the certification
            //     question was never answered; defaulting the unanswered case
            //     to `certified` was reporting a verdict nobody reached.
            $verdict = $report['adapter']['verdict'] ?? null;
            $verdictAnswered = $repo === null || is_array($verdict);
            $report['status'] = ($survey['refusals'] === []
                && $row['grammar']['status'] === AdapterSources::GRAMMAR_OK
                && $verdictAnswered
                && (!is_array($verdict) || ($verdict['status'] ?? null) !== 'blocked')) ? 'ok' : 'error';
        } else {
            $report['adapters'] = $survey['adapters'];
            $grammarErrors = 0;
            $grammarBlocked = 0;
            foreach ($survey['adapters'] as $adapter) {
                $grammarErrors += $adapter['grammar']['status'] === AdapterSources::GRAMMAR_ERROR ? 1 : 0;
                $grammarBlocked += $adapter['grammar']['status'] === AdapterSources::GRAMMAR_BLOCKED ? 1 : 0;
            }
            $report['summary'] = [
                'adapters' => count($survey['adapters']),
                'shipped' => count(array_filter(
                    $survey['adapters'],
                    static fn(array $r): bool => $r['source'] === AdapterSources::SHIPPED
                )),
                'site' => count(array_filter(
                    $survey['adapters'],
                    static fn(array $r): bool => $r['source'] === AdapterSources::SITE
                )),
                'plugin' => count(array_filter(
                    $survey['adapters'],
                    static fn(array $r): bool => $r['source'] === AdapterSources::PLUGIN
                )),
                // Counted, printed, and deliberately NOT folded into the exit
                // code — see render().
                'not_installed' => count($survey['not_installed']),
                'grammar_error' => $grammarErrors,
                // Counted apart from grammar_error on purpose: these adapters
                // were not judged, so folding them in would inflate the number
                // an operator reads as "how many of my manifests are broken".
                'grammar_unjudged' => $grammarBlocked,
                'refusals' => count($survey['refusals']),
            ];
            // grammar_unjudged is structurally implied by a non-empty refusal
            // list, and is named here anyway so a future change that produces
            // one without the other cannot quietly exit 0.
            $healthy = $survey['refusals'] === [] && $grammarErrors === 0 && $grammarBlocked === 0;
            if ($verb === 'doctor') {
                // Blockers are the pinned set's readiness verdict, which only
                // exists when a repository named the pins. Without --repo the
                // doctor is honest about having no pin set rather than
                // inventing one out of the whole library.
                $report['blockers'] = $repo === null ? [] : self::blockers($repo, $survey['refusals']);
                $report['summary']['blockers'] = count($report['blockers']);
                $healthy = $healthy && $report['blockers'] === [];
            }
            $report['status'] = $healthy ? 'ok' : 'error';
        }

        if ($json) {
            echo self::encode($report) . "\n";
        } else {
            self::render($report);
        }
        return $report['status'] === 'ok' ? 0 : 1;
    }

    /**
     * The survey row plus everything else this machine already knows about
     * one adapter: its reviewed disposition entry, the capability claim that
     * disposition projects, and — with `--repo` — the verdict that claim gets
     * for this repository's pins.
     *
     * "Verification strength" is reported as the facts that exist, never as a
     * new scale, and there is one fewer such fact than there used to be. What
     * remains is somebody else's: the claim's own `plugin_execution.status`
     * (`verified`, `unverified`, or `not-a-product-claim`), the bundle schema
     * its disposition cites, and the test ids named in that citation. The
     * `evidence.status` word is gone with the generated evidence record that
     * decided it — a citation is what a reviewer wrote down, not a verdict
     * this process can resolve, and printing `current` beside it would be the
     * agent vouching for itself. Minting a word like "strongly verified" here
     * would put another vocabulary next to dispositions.json — the exact
     * refusal AdapterSources.php's header documents for the `uncertified`
     * status, and for the same reason: a word invented next to reviewed
     * evidence reads as reviewed evidence.
     *
     * @param list<array<string,mixed>> $adapters the whole survey, for the pinned-set load
     * @return array<string,mixed>
     */
    private static function inspect_row(array $row, string $manifestDir, ?string $repo, array $adapters): array {
        $name = (string) $row['name'];
        $out = $row;
        $out['disposition'] = null;
        $out['claim'] = null;
        $out['verification'] = [
            'bundle_schema' => null,
            'plugin_execution_status' => null,
            'tests' => [],
        ];

        $dispositions = null;
        try {
            $dispositions = ManifestDispositions::load($manifestDir);
        } catch (\Throwable $t) {
            $dispositions = null;
        }
        if ($dispositions !== null) {
            $entry = $dispositions->entry($name);
            $out['disposition'] = is_array($entry) ? $entry : null;
        }

        // An out-of-tree adapter has no registry entry BY CONSTRUCTION, so it
        // carries the synthesized provenance record instead — the same
        // substitution Policy::manifest_disposition() makes, rather than a
        // missing-entry story that would send the operator to regenerate a
        // registry that was never supposed to name it.
        if ($row['source'] === AdapterSources::SITE && $repo !== null) {
            try {
                $out['disposition'] = AdapterSources::discover($manifestDir, $repo)->provenance($name);
            } catch (\Throwable $t) {
                // discover() refuses whole-directory, so ANOTHER file in this
                // source can make it throw. The refusal is already a row of its
                // own and already the grammar verdict on this row; a second
                // copy of it in the disposition slot would read as a
                // certification finding about this adapter, which it is not.
                $out['disposition'] = null;
            }
        }

        if ($dispositions !== null) {
            $manifests = [];
            foreach ($adapters as $adapter) {
                if ($adapter['source'] !== AdapterSources::SHIPPED) {
                    continue;
                }
                try {
                    $manifests[] = Canon::decode(Canon::read_file(
                        rtrim($manifestDir, '/') . '/' . $adapter['name'] . '.json'
                    ));
                } catch (\Throwable $t) {
                    // Already reported as a refusal row by the survey.
                    continue;
                }
            }
            // The claim is the reviewed disposition PROJECTED — there is no
            // second, generated document to read it out of — so it comes from
            // the same `AdapterRegistry::report()` every other consumer reads.
            // `$sources` is passed because report() attributes each row's
            // source and certification word from it; with no target (this
            // process has no WordPress) the report is the source/authorship
            // gate only, which is exactly what the deferred list above says.
            $sources = [];
            try {
                $sources = AdapterSources::discover($manifestDir, $repo)->diagnostics($manifests);
            } catch (\Throwable $t) {
                // discover() refuses WHOLE-DIRECTORY, so an unrelated file in
                // this source makes it throw — already reported as its own
                // refusal row. Every manifest in $manifests is shipped, and
                // report()'s own default for an unattributed row is exactly
                // `shipped` / `registry`, so the claim is unaffected.
                $sources = [];
            }
            try {
                $report = AdapterRegistry::report(
                    $dispositions,
                    $manifests,
                    ['operation' => 'promote'],
                    null,
                    $sources
                );
            } catch (\Throwable $t) {
                $report = null;
            }
            foreach (($report['manifests'] ?? []) as $reported) {
                if (($reported['name'] ?? null) !== $name) {
                    continue;
                }
                // Strip the report FRAME, keep the claim. `verdict` here would
                // be the verdict for this call's targetless, pin-less query,
                // and the row below already carries the verdict for this
                // repository's actual pins; two verdicts under one row meaning
                // different things is the confusion this command exists to
                // end. `source` and `evidence_scope` are the survey's answer
                // and are already on the row.
                unset($reported['verdict'], $reported['source'], $reported['evidence_scope']);
                $out['claim'] = $reported;
                $out['verification'] = self::verification($reported);
            }
        }

        // A site adapter's version story does not live in the shipped reviewed
        // library and never can: `AdapterRegistry::report()` is handed the
        // SHIPPED subset only here (Policy::load() does the same, for the
        // reason its own comment gives), so no row answers for an out-of-tree
        // name — and this command printed "registry claim: (none)"
        // for an adapter carrying a complete, verified signed envelope. Its
        // evidence comes from the survey row instead, unmodified: the
        // authority that signed, the certificate/statement/platform digests
        // the signature covers, the evidence bundle with its git revision and
        // named tests, the artifacts[] rows naming what was exercised at which
        // version, and the supported_versions the signed ratification forced
        // to equal the manifest's own. Reported next to `claim`, never merged
        // into it: one is a reviewed shipped registry entry and the other is a
        // third party's signed statement, and a reader has to be able to tell
        // which one they are looking at.
        $out['certification_evidence'] = $row['certification_evidence'] ?? null;

        if ($repo !== null) {
            try {
                $report = Policy::load($repo, [$name], true)->capability_report(['operation' => 'promote']);
                foreach ($report['manifests'] as $reported) {
                    if (($reported['name'] ?? null) === $name) {
                        $out['verdict'] = $reported['verdict'];
                    }
                }
            } catch (\Throwable $t) {
                // The grammar verdict on the row already carries this exact
                // refusal; a second copy under `verdict` would read as a
                // certification finding, which it is not.
                $out['verdict'] = null;
            }
        }

        return $out;
    }

    /**
     * The evidence facts behind one claim, which are now exactly the authored
     * citation: the bundle schema a reviewer named and the test ids they cited.
     *
     * The citation is reported, never resolved. Nothing on this machine runs
     * those tests, and the record that used to hold their verdicts is gone, so
     * a per-test `pass`/`absent` word here would be this command inventing a
     * result for a test it did not run. A site adapter's evidence carries more
     * (a signed bundle digest, a git revision, artifacts) and that whole
     * envelope is printed separately as `certification_evidence` — reported
     * beside the claim, never merged into it.
     *
     * @return array<string,mixed>
     */
    private static function verification(?array $claim): array {
        $evidence = is_array($claim['evidence'] ?? null) ? $claim['evidence'] : [];
        $tests = [];
        foreach ((array) ($evidence['tests'] ?? []) as $cited) {
            $tests[] = (string) $cited;
        }
        return [
            'bundle_schema' => isset($evidence['bundle_schema'])
                ? (string) $evidence['bundle_schema']
                : null,
            'plugin_execution_status' => isset($claim['plugin_execution']['status'])
                ? (string) $claim['plugin_execution']['status']
                : null,
            'tests' => $tests,
        ];
    }

    /**
     * The pinned set's readiness blockers, from the same
     * `AdapterRegistry::report()` the plan's `adapter_dispositions` rows
     * and host promotion consume — never a second evaluation.
     *
     * `probe_target()` returns null outside WordPress, so the target half of
     * that report is simply not evaluated here. That is the honest offline
     * answer and it is why the deferred list names it: doctor reports the
     * immutable/source gate, and says out loud that it is not claiming a live
     * verdict.
     *
     * @param list<array<string,mixed>> $refusals this run's refusals, for attribution
     * @return list<array<string,mixed>>
     */
    private static function blockers(string $repo, array $refusals): array {
        try {
            return Policy::load($repo, null, true)->adapter_readiness_blockers();
        } catch (\Throwable $t) {
            // A repository whose pins cannot load has no readiness verdict to
            // report. Surfacing the engine's own message as a blocker row
            // keeps doctor answering rather than dying, and keeps the row
            // shape the two plan renderers already know.
            //
            // `source` is ATTRIBUTED rather than assumed. Hardcoding `shipped`
            // here sent an operator whose site adapter shadowed a shipped one
            // to the wrong directory — the exact failure DUO-3314 put `source`
            // on these rows to prevent. With nothing attributable it reports
            // `unknown`, which is deliberately not one of the three source
            // words for the same reason `trust_tier` below is not one of the
            // four tiers: this row is about a pin SET spanning every source,
            // not about one adapter, so borrowing a word would be a guess
            // printed as a fact. The engine's own message on `reason` already
            // names the exact file in every attributable case.
            //
            // Read off the refusal's own `source` since DUO-3339. The previous
            // implementation sniffed `paths` for a leading `adapters/`, which
            // a `plugins/<dir>/duo-adapter.json` path silently fell out of —
            // and only whole-SOURCE refusals can stop a pin set from loading
            // at all, so a per-adapter plugin refusal is not a candidate here
            // in the first place.
            $source = 'unknown';
            foreach ($refusals as $refusal) {
                if (($refusal['scope'] ?? AdapterSources::SCOPE_SOURCE) !== AdapterSources::SCOPE_SOURCE) {
                    continue;
                }
                if (is_string($refusal['source'] ?? null)) {
                    $source = (string) $refusal['source'];
                    break;
                }
            }
            return [[
                'name' => 'pins',
                'status' => 'unsupported',
                'code' => 'pin_set_unloadable',
                'reason' => $t->getMessage(),
                'source' => $source,
                'trust_tier' => 'unknown',
                'remediation' => 'resolve the refusal(s) this report lists, or amend site.duo.json\'s '
                    . '`manifests` pins',
            ]];
        }
    }

    /** @param array<string,mixed> $report */
    private static function render(array $report): void {
        echo "manifests dir: {$report['manifests_dir']}\n";
        echo 'site repo:     ' . ($report['repo']
            ?? '(none — this run surveyed the shipped library ALONE; a site repository\'s own '
                . AdapterSources::SITE_DIR . '/ source is not part of it. Pass --repo=<site-repo>)') . "\n";
        echo "spec_version:  {$report['spec_version']}\n";

        echo "\nadapter sources (the engine has three; this run reached the ones marked scanned):\n";
        foreach ($report['sources'] as $source) {
            echo sprintf(
                "  [%s] %-8s %s\n",
                empty($source['scanned']) ? 'not scanned' : '  scanned  ',
                (string) $source['source'],
                (string) ($source['path'] ?? '(none)')
            );
            echo '          ' . (string) ($source['note'] ?? '') . "\n";
        }

        if (isset($report['adapter'])) {
            self::render_adapter($report['adapter']);
        } else {
            echo "\ninstalled adapters (each grammar verdict is an ISOLATED load; see deferred):\n";
            foreach ($report['adapters'] as $row) {
                echo sprintf(
                    "  [%s] %-28s %-8s %-21s %-18s %s\n",
                    $row['grammar']['status'],
                    $row['name'],
                    $row['source'],
                    $row['trust_tier'],
                    self::certification_cell($row),
                    AdapterSources::render_untrusted($row['path'])
                );
                echo '          tier basis: ' . AdapterSources::render_untrusted($row['tier_basis']) . "\n";
                foreach (self::certification_detail($row) as $line) {
                    echo '          ' . $line . "\n";
                }
                if ($row['grammar']['message'] !== null) {
                    echo '          ' . AdapterSources::render_untrusted($row['grammar']['message']) . "\n";
                }
            }
            if ($report['adapters'] === []) {
                echo "  (none)\n";
            }
        }

        self::render_not_installed($report['not_installed']);
        self::render_refusals($report['refusals']);

        if (isset($report['blockers'])) {
            echo "\nreadiness blockers for this repository's pins (source gate only; no live target was probed):\n";
            foreach ($report['blockers'] as $blocker) {
                // Lockstep with agent/src/Command/Cli.php's plan renderer and
                // cli/src/Plan/PlanSummary.php: same row, same fields, same order.
                echo '  - ' . ($blocker['name'] ?? '?') . ' [' . ($blocker['status'] ?? 'unreviewed')
                    . '] [source=' . ($blocker['source'] ?? 'shipped')
                    . ' tier=' . ($blocker['trust_tier'] ?? 'unknown')
                    . '] [' . ($blocker['code'] ?? 'not_certified') . ']: '
                    . ($blocker['reason'] ?? 'not certified') . "\n";
                if (($blocker['remediation'] ?? '') !== '') {
                    echo '    remediation: ' . $blocker['remediation'] . "\n";
                }
            }
            if ($report['blockers'] === []) {
                echo "  (none)\n";
            }
        }

        echo "\ndeferred — NOT checked here, and not checked anywhere else by this command:\n";
        foreach ($report['deferred'] as $row) {
            echo "  [deferred] {$row['surface']} — {$row['check']}\n";
            echo '             ' . $row['why'] . "\n";
        }

        if (isset($report['summary'])) {
            $s = $report['summary'];
            echo "\nsummary: {$s['adapters']} adapter(s) installed ({$s['shipped']} shipped, {$s['site']} site, "
                . "{$s['plugin']} plugin), {$s['not_installed']} installed but not loaded, "
                . "{$s['grammar_error']} grammar error(s), {$s['grammar_unjudged']} unjudged "
                . '(their source is refused, so their own manifests were never read), '
                . "{$s['refusals']} refusal(s)"
                . (isset($s['blockers']) ? ", {$s['blockers']} readiness blocker(s)" : '')
                . '; ' . count($report['deferred']) . " check(s) NOT performed here (see the deferred list above)\n";
        }
    }

    /**
     * The certification column, which after T6 §3.2 has to say more.
     *
     * Before this it printed one of three things: `uncertified`, the shipped
     * disposition status, or the literal word `unreviewed`. That last case
     * swallowed every signed state — an adapter carrying a VALID Ed25519
     * certificate under a trusted key printed `unreviewed`, which is the one
     * word that is not true of it. The catalog is the command an operator
     * runs to find out why promotion refuses, so the certification WORD the
     * engine actually derived is what belongs here.
     *
     * The words come from `AdapterSources`: `registry` (shipped, reviewed),
     * `uncertified`, `signed_unpinned`, `third_party_signed`, T6's
     * `site_signed`, and § v3.16's `reviewer_signed` (a signed bundle that also
     * named who exercised it). A shipped row keeps printing its disposition status,
     * because for those the reviewed registry IS the certification state and
     * printing `registry` beside it would say the same thing twice.
     *
     * @param array<string,mixed> $row
     */
    private static function certification_cell(array $row): string {
        $certification = $row['certification'] ?? null;
        if ($certification === null) {
            return 'no-registry';
        }
        if ($certification === 'registry') {
            // A shipped adapter with no reviewed entry is genuinely
            // unreviewed; one with an entry prints that entry's own status.
            return (string) ($row['disposition_status'] ?? 'unreviewed');
        }

        return (string) $certification;
    }

    /**
     * The lines under a row that a certification word alone cannot carry.
     *
     * `principal` and `trust_root` are T6 §3.2's addition to every catalog
     * row: WHO vouched, and under whose root. An operator looking at two
     * signed adapters needs to tell their own organization's key from a
     * third party's, and the word `site_signed` does not say which key.
     *
     * T6 §3.3's `shadowed_by_site` is deliberately NOT here. A shipped
     * adapter an explicit site pin displaced has no `adapters[]` row at all
     * — it is not loaded, and minting a row for a definition nothing loads
     * would contradict what this section means. It is a `not_installed[]`
     * row carrying `reason_code: shadowed_by_site` and a `winner` naming the
     * site copy, which `render_not_installed()` already prints in full.
     *
     * @param array<string,mixed> $row
     * @return list<string>
     */
    private static function certification_detail(array $row): array {
        $lines = [];
        $principal = $row['principal'] ?? null;
        $trustRoot = $row['trust_root'] ?? null;
        if (is_string($principal) && $principal !== '') {
            $lines[] = 'certified by: ' . AdapterSources::render_untrusted($principal)
                . (is_string($trustRoot) && $trustRoot !== ''
                    ? ' (' . AdapterSources::render_untrusted($trustRoot) . ' trust root)'
                    : '');
        }

        return $lines;
    }

    /** @param list<array<string,mixed>> $refusals */
    private static function render_refusals(array $refusals): void {
        echo "\nrefusals — installed files the engine will not load, and why:\n";
        if ($refusals === []) {
            echo "  (none)\n";
            return;
        }
        foreach ($refusals as $refusal) {
            // The scope is printed because the two are different sizes of
            // problem and the message alone does not say which: `source` means
            // nothing in that directory was judged and the pin set will not
            // load; `adapter` means one adapter was dropped and the rest of
            // its source is unaffected.
            echo '  [' . $refusal['code'] . '] [' . ($refusal['source'] ?? '?') . ' source, '
                . ($refusal['scope'] ?? '?') . ' scope] ' . implode(', ', array_map(
                    static fn($path): string => AdapterSources::render_untrusted($path),
                    (array) $refusal['paths']
                )) . "\n";
            echo '          ' . $refusal['message'] . "\n";
            echo '          remediation: ' . $refusal['remediation'] . "\n";
        }
    }

    /**
     * Adapters that are on this machine and did not load.
     *
     * These print on EVERY run and do not flip the exit code, which is a
     * deliberate and load-bearing choice. A shadowed adapter is not a blocker
     * and not a break: it is the precedence rule working, reported so nobody
     * has to guess which of two definitions their site runs. The likely
     * collision is a popular plugin bundling an adapter this project also
     * ships, so making it red would leave a permanently failing `doctor` on
     * every such site — and an exit code that is always 1 stops meaning
     * anything at all, including on the day something is genuinely wrong.
     *
     * @param list<array<string,mixed>> $rows
     */
    private static function render_not_installed(array $rows): void {
        echo "\ninstalled, NOT loaded — on this machine, and something else answers to the name\n"
            . "  (reported on every run; NOT an error and never the exit code — this is precedence working):\n";
        if ($rows === []) {
            echo "  (none)\n";
            return;
        }
        foreach ($rows as $row) {
            $winner = is_array($row['winner'] ?? null) ? $row['winner'] : null;
            // Rendered at the point of PRINT, never in the document: `name` is
            // a declared name out of a third party's manifest and `path`
            // carries a plugin directory name, so both can hold bytes that
            // rewrite a terminal. The JSON keeps them exact — a report whose
            // rendered line and machine record disagreed about a filename
            // would be worse than either.
            echo '  [' . (string) $row['reason_code'] . '] ' . ($row['name'] === null
                    ? '(name unreadable)'
                    : AdapterSources::render_untrusted($row['name']))
                . ' — ' . AdapterSources::render_untrusted($row['path'])
                . ($winner === null
                    ? "\n"
                    : ' — ' . (string) $winner['source'] . ' answers to this name ('
                        . AdapterSources::render_untrusted($winner['path']) . ")\n");
            echo '          ' . (string) $row['message'] . "\n";
        }
    }

    /** @param array<string,mixed> $row */
    private static function render_adapter(array $row): void {
        echo "\nADAPTER {$row['name']}\n";
        echo '  source:            ' . $row['source'] . ' (' . AdapterSources::render_untrusted($row['path']) . ")\n";
        echo "  sha256:            {$row['sha256']}\n";
        echo "  trust_tier:        {$row['trust_tier']}\n";
        echo '  tier_basis:        ' . AdapterSources::render_untrusted($row['tier_basis']) . "\n";
        echo '  certification:     ' . ($row['certification']
            ?? '(none — this manifest library carries no reviewed dispositions and no generated registry, '
                . 'so it makes no product claim)') . "\n";
        echo '  disposition:       ' . ($row['disposition_status'] ?? '(no reviewed entry)') . "\n";
        echo '  grammar:           ' . $row['grammar']['status']
            . ($row['grammar']['message'] === null ? '' : ' — ' . AdapterSources::render_untrusted($row['grammar']['message'])) . "\n";

        $surfaces = $row['executable_surfaces'];
        echo '  interpreter:       ' . ($surfaces['interpreter'] ?? '(none)') . "\n";
        echo '  regenerators:      ' . ($surfaces['regenerators'] === []
            ? '(none)'
            : implode(', ', array_map(
                static fn(array $r): string => $r['post_type'] . ' -> ' . $r['regenerator'],
                $surfaces['regenerators']
            ))) . "\n";
        echo '  manifest providers:' . ($surfaces['manifest_providers'] === []
            ? ' (none)'
            : ' ' . implode(', ', $surfaces['manifest_providers'])) . "\n";

        echo "  required providers:\n";
        if ($row['required_providers'] === []) {
            echo "    (none)\n";
        }
        foreach ($row['required_providers'] as $provider) {
            echo "    - {$provider['id']} v{$provider['version']} (source={$provider['source']}"
                . " plugin={$provider['plugin']}): " . implode(', ', $provider['capabilities']) . "\n";
        }

        $claim = $row['claim'];
        if (!is_array($claim)) {
            // A site adapter has no SHIPPED reviewed claim by construction —
            // the report above is handed the shipped subset only. Saying just
            // "(none)" beside a complete signed envelope read as "nothing is
            // known about this adapter", which was the whole complaint: the
            // operator has to be told the claim is absent for a structural
            // reason and that the real evidence is a few lines down, not left
            // to infer it.
            echo '  registry claim:    (none — ' . (($row['certification_evidence'] ?? null) !== null
                ? 'a non-shipped adapter never has a shipped reviewed claim; its own signed certification '
                    . 'evidence is reported below'
                : 'no reviewed disposition projects a capability claim for this adapter') . ")\n";
        } else {
            // No `adapter_digest` line: a claim no longer carries one, and
            // deriving one here would print a number that is not the pin.
            // Adapter identity is ArtifactPolicyIdentity::manifest_rows()
            // hashed against a LOADED policy — the site's own pin set, its
            // interpreter and provider bytes — which is what
            // `AdapterCertify::pinObject()` resolves for `duo adapter certify
            // <repo> --name=<n> --pin`. This command loads the shipped library
            // with no site policy, so its row would differ from the one a pin
            // must carry. The manifest's own content hash is on `sha256:`
            // above; it is a different fact and is labelled as one.
            echo "  registry claim:\n";
            echo '    status:              ' . ($claim['status'] ?? '?') . "\n";
            echo '    reason:              ' . ($claim['reason'] ?? '') . "\n";
            echo '    supported_versions:  ' . self::inline($claim['supported_versions'] ?? []) . "\n";
            echo '    plugin_execution:    ' . self::inline($claim['plugin_execution'] ?? []) . "\n";
            echo '    authored_state:      ' . self::inline($claim['authored_state'] ?? []) . "\n";
            echo '    deletion_semantics:  ' . self::inline($claim['deletion_semantics'] ?? []) . "\n";
            echo '    operations:          ' . implode(', ', $claim['operations'] ?? []) . "\n";
            echo '    surfaces:            ' . implode(', ', $claim['surfaces'] ?? []) . "\n";
            foreach ($claim['unsupported'] ?? [] as $unsupported) {
                echo '    unsupported:         ' . ($unsupported['surface'] ?? '?') . ' / '
                    . ($unsupported['operation'] ?? '?') . ' — ' . ($unsupported['reason'] ?? '') . "\n";
            }
            echo '    evidence citation:   ' . ($claim['evidence']['bundle_schema'] ?? 'none') . "\n";
        }

        $evidence = $row['certification_evidence'] ?? null;
        if (is_array($evidence)) {
            echo "  signed certification evidence (this adapter's OWN envelope, not the shipped registry):\n";
            echo '    authority:            ' . self::inline($evidence['authority'] ?? null) . "\n";
            echo '    certificate_sha256:   ' . ($evidence['certificate_sha256'] ?? 'none') . "\n";
            echo '    statement_sha256:     ' . ($evidence['statement_sha256'] ?? 'none') . "\n";
            // The column is one wider than it was because this label is: WP-4.7
            // renamed the value with its meaning (§ v3.6), and the printed label
            // is the JSON key an operator will grep for. `platform_sha256` still
            // exists in this product and means something ELSE — the contract
            // attestation's whole-boundary digest — so the two must not be
            // spelled alike in a report a human reads.
            echo '    platform_axes_sha256: ' . ($evidence['platform_axes_sha256'] ?? 'none') . "\n";
            echo '    supported_versions:   ' . self::inline($evidence['supported_versions'] ?? null) . "\n";
            $bundle = is_array($evidence['bundle'] ?? null) ? $evidence['bundle'] : [];
            echo '    bundle:               ' . ($bundle['digest'] ?? 'none')
                . ' (' . ($bundle['schema'] ?? '?') . ' @ ' . ($bundle['git_revision'] ?? '?') . ")\n";
            echo '    bundle tests:         ' . (($bundle['tests'] ?? []) === []
                ? '(none)'
                : implode(', ', array_map('strval', (array) $bundle['tests']))) . "\n";
            // Printed ONLY when the bundle named one (§ v3.16). Every existing
            // certificate carries no reviewer member at all, so this row is
            // absent for them and their output stays byte-identical — AGENTS.md
            // rule 8 applied to a line an operator's eye and a grep both use.
            // The label says "exercised by" rather than "reviewer" because the
            // fact is who RAN it; who vouched is `authority:` three lines up,
            // and the whole point of the tier is that those are two parties.
            if (is_string($bundle['reviewer'] ?? null) && $bundle['reviewer'] !== '') {
                echo '    exercised by:         ' . $bundle['reviewer'] . "\n";
            }
            // Three answers, not two: a bundle that exercised no named
            // artifact and a certificate whose artifact list could not be
            // decoded are different facts, and only one of them is a clean
            // report.
            if (($evidence['artifacts'] ?? null) === null) {
                echo "    artifacts:            (UNREADABLE — this certificate's artifact list could not be "
                    . "decoded)\n";
            } elseif ($evidence['artifacts'] === []) {
                echo "    artifacts:            (none)\n";
            }
            foreach ((array) ($evidence['artifacts'] ?? []) as $artifact) {
                echo '    artifact:             ' . ($artifact['name'] ?? '?') . ' v'
                    . ($artifact['version'] ?? '?') . ' [' . ($artifact['role'] ?? '?') . '] '
                    . ($artifact['sha256'] ?? '') . "\n";
            }
        }

        $verification = $row['verification'];
        echo "  verification (the facts that exist, not a scale):\n";
        echo '    evidence.bundle_schema:     ' . ($verification['bundle_schema'] ?? '(no claim)') . "\n";
        echo '    plugin_execution.status:    ' . ($verification['plugin_execution_status'] ?? '(no claim)') . "\n";
        if ($verification['tests'] === []) {
            echo "    cited tests:                (none)\n";
        }
        // The id and nothing else: see verification() — a verdict word here
        // would be a result this process did not produce.
        foreach ($verification['tests'] as $test) {
            echo '    cited test:                 ' . $test . "\n";
        }

        if (array_key_exists('verdict', $row)) {
            $verdict = $row['verdict'];
            echo '  verdict for this repo: ' . (is_array($verdict) ? ($verdict['status'] ?? '?') : '(unavailable)')
                . "\n";
            foreach (is_array($verdict) ? ($verdict['reasons'] ?? []) : [] as $reason) {
                echo '    blocked: ' . ($reason['code'] ?? 'unknown') . ' — ' . ($reason['message'] ?? '') . "\n";
                if (($reason['remediation'] ?? '') !== '') {
                    echo '      remediation: ' . $reason['remediation'] . "\n";
                }
            }
        }
    }

    /**
     * A structured claim field on ONE terminal line. Deliberately not
     * Canon::encode(): that is the canonical repository representation and it
     * pretty-prints, so a four-key `supported_versions` would occupy nine
     * lines in the middle of a report whose whole value is scanability. The
     * canonical form is one `--format=json` away and is what a consumer
     * should read anyway.
     */
    private static function inline(mixed $value): string {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : '(unencodable)';
    }

    /**
     * Machine-read output, so slashes stay unescaped and key order is the
     * engine's — the same rule `duo manifest-validate` states for its own two
     * documents.
     *
     * @param array<string,mixed> $document
     */
    private static function encode(array $document): string {
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('duo: adapter catalog could not encode its report');
        }
        return $json;
    }

    /**
     * Load the engine's pure surface into this WordPress-free process, exactly
     * as cli/src/Adapter/ManifestValidate.php's own boot() does and for the same
     * reason: `Policy::load()` compares a manifest's declared `spec_version`
     * against DUO_SPEC_VERSION, so leaving it undefined would make every
     * shipped adapter report a grammar error about this command rather than
     * about the adapter.
     */
    private static function boot(): void {
        $repo = dirname(__DIR__, 3);
        $agent = $repo . '/agent/duo.php';
        if (!is_file($agent)) {
            throw new \RuntimeException("adapter: agent source not found at $agent");
        }
        $source = (string) file_get_contents($agent);
        if (!defined('DUO_AGENT_VERSION')) {
            if (preg_match("/define\('DUO_AGENT_VERSION', '([^']+)'\)/", $source, $m) !== 1) {
                throw new \RuntimeException('adapter: could not resolve DUO_AGENT_VERSION');
            }
            define('DUO_AGENT_VERSION', $m[1]);
        }
        if (!defined('DUO_SPEC_VERSION')) {
            if (preg_match("/define\('DUO_SPEC_VERSION', ([0-9]+)\)/", $source, $m) !== 1) {
                throw new \RuntimeException('adapter: could not resolve DUO_SPEC_VERSION');
            }
            define('DUO_SPEC_VERSION', (int) $m[1]);
        }
        $duoAgentClassmap = require $repo . '/agent/duo-classmap.php';
        if (!is_array($duoAgentClassmap)) {
            throw new \RuntimeException('adapter: agent/duo-classmap.php did not return a map');
        }
        $duoAgentFiles = [];
        foreach ($duoAgentClassmap as $duoAgentPath) {
            $duoAgentFiles[basename((string) $duoAgentPath, '.php')] = (string) $duoAgentPath;
        }
        foreach (['Canon', 'OptionState', 'ManifestDispositions', 'AdapterRegistry', 'Policy'] as $class) {
            $duoAgentFile = $duoAgentFiles[$class] ?? null;
            if (!is_string($duoAgentFile)) {
                throw new \RuntimeException('adapter: agent source ' . $class . '.php is absent from agent/duo-classmap.php');
            }
            require_once $repo . '/agent/' . $duoAgentFile;
        }
    }

    /**
     * Whether this run refused a whole SOURCE, which is what the deferred
     * list's site-policy row is about. A per-adapter plugin refusal is not one
     * of those: the site half of policy was still used for every verdict.
     *
     * @param list<array<string,mixed>> $refusals
     */
    private static function source_refused(array $refusals): bool {
        foreach ($refusals as $refusal) {
            if (($refusal['scope'] ?? AdapterSources::SCOPE_SOURCE) === AdapterSources::SCOPE_SOURCE) {
                return true;
            }
        }
        return false;
    }

    /** Fail closed on this command's own paths: usage, a bad dir, an unreadable library. */
    private static function fail(string $message): int {
        fwrite(STDERR, "duo: adapter: $message\n");
        return 2;
    }
}

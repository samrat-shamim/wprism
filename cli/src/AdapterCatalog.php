<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

use Duo\AdapterSources;
use Duo\Canon;
use Duo\CapabilityRegistry;
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
 * dispositions beside it, the generated capability registry, and (with
 * `--repo`) one site repository's `adapters/` source and its pins.
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
    /** Envelope of the catalog report (both output modes carry it). */
    public const FORMAT = 'duo-adapter-catalog/v1';

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
     * @return list<array{status:string, surface:string, check:string, why:string}>
     */
    private static function deferred(?string $repo, bool $sourceRefused): array {
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
                'check' => 'Policy::validate_one_owner_per_declared_name() / '
                    . 'validate_no_conflicting_provider_ids() / validate_no_conflicting_adapter_claims()',
                'why' => 'each adapter\'s grammar verdict here is an ISOLATED load, so a manifest can read `ok` '
                    . 'and still be illegal in company — one owner per declared name, globally unique provider '
                    . 'ids, and one plugin/theme range per claim are properties of a SET. '
                    . '`duo manifest-validate <manifests-dir> --pins=<a,b,...>` co-loads a set and runs them',
            ],
            [
                'surface' => 'site.duo.json policy.tables / policy.options',
                'check' => 'Policy::validate_ref_kinds() / Policy::validate_no_conflicting_option_rules()',
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
                'surface' => 'dispositions.json / capabilities/registry.json against a target',
                'check' => 'CapabilityRegistry::report()',
                'why' => 'certification is evidence evaluated against one target revision and its installed '
                    . 'plugin/theme versions, WordPress, PHP, and database. The reviewed status and the '
                    . 'evidence binding are read here; whether a claim holds FOR YOUR SITE is not — run '
                    . '`duo capabilities <env>` for that',
            ],
            [
                'surface' => 'adapter sources other than the shipped library and <site-repo>/adapters/',
                'check' => 'AdapterSources::SHIPPED / AdapterSources::SITE',
                'why' => 'the engine has exactly two adapter sources and this command surveys both of them. A '
                    . 'manifest a plugin ships inside its own directory, or one installed as a versioned '
                    . 'package, is discovered by nothing — not by this command and not by any other — and '
                    . 'Policy::normalize_manifest_pins() refuses a pin naming any source but those two. An '
                    . 'empty result from this command means "no adapter is installed in either source", never '
                    . '"no adapter is installed"',
            ],
        ];
        foreach ($rows as $i => $row) {
            $rows[$i] = ['status' => 'deferred'] + $row;
        }
        return $rows;
    }

    /**
     * @param list<string> $args everything after the verb
     * @return int 0 healthy, 1 a refusal/blocker/grammar error was surfaced, 2 usage/IO
     */
    public static function run(array $args): int {
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
            'refusals' => $survey['refusals'],
            'deferred' => self::deferred($repo, $survey['refusals'] !== []),
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
     * one adapter: its reviewed disposition entry, its generated registry
     * claim, and — with `--repo` — the verdict that claim gets for this
     * repository's pins.
     *
     * "Verification strength" is reported as the facts that exist, never as a
     * new scale. There are exactly three of them and they are all somebody
     * else's: `evidence.status` (`current` or `candidate`), the claim's own
     * `plugin_execution.status` (`verified`, `unverified`, or
     * `not-a-product-claim`), and the named test citations resolved against
     * the bundle's own verdicts. Minting a word like "strongly verified" here
     * would put a fourth vocabulary next to dispositions.json — the exact
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
            'evidence_status' => null,
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
            try {
                $registry = CapabilityRegistry::load($manifestDir, $dispositions, $manifests);
            } catch (\Throwable $t) {
                $registry = null;
            }
            if ($registry !== null) {
                $out['claim'] = $registry->claim($name);
                $out['verification'] = self::verification($out['claim'], $registry->data());
            }
        }

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
     * The evidence facts behind a claim, joined to the bundle's own verdicts.
     *
     * A citation naming a test the imported bundle does not carry resolves to
     * `absent` rather than being dropped: `CapabilityRegistry::validate()`
     * already refuses that combination while evidence is `current`, so seeing
     * it here means the evidence is `candidate` — the re-certification
     * bootstrap — and an operator reading a claim mid-cycle is entitled to
     * know which of its citations are not yet backed.
     *
     * @return array<string,mixed>
     */
    private static function verification(?array $claim, array $registry): array {
        $verdicts = [];
        foreach ($registry['evidence']['tests'] ?? [] as $test) {
            if (is_array($test) && is_string($test['id'] ?? null)) {
                $verdicts[$test['id']] = (string) ($test['verdict'] ?? 'unknown');
            }
        }
        $tests = [];
        foreach ($claim['evidence']['tests'] ?? [] as $cited) {
            $tests[] = ['id' => (string) $cited, 'verdict' => $verdicts[(string) $cited] ?? 'absent'];
        }
        return [
            'evidence_status' => isset($registry['evidence']['status'])
                ? (string) $registry['evidence']['status']
                : null,
            'plugin_execution_status' => isset($claim['plugin_execution']['status'])
                ? (string) $claim['plugin_execution']['status']
                : null,
            'tests' => $tests,
        ];
    }

    /**
     * The pinned set's readiness blockers, from the same
     * `CapabilityRegistry::report()` the plan's `adapter_dispositions` rows
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
            // on these rows to prevent. When this run refused something in the
            // site source, that is what stopped the pin set from loading and
            // the row says so. With nothing attributable it reports `unknown`,
            // which is deliberately not one of the two source words for the
            // same reason `trust_tier` below is not one of the four tiers:
            // this row is about a pin SET spanning both sources, not about one
            // adapter, so borrowing either word would be a guess printed as a
            // fact. The engine's own message on `reason` already names the
            // exact file in every attributable case.
            $source = 'unknown';
            foreach ($refusals as $refusal) {
                foreach ((array) ($refusal['paths'] ?? []) as $path) {
                    if (str_starts_with((string) $path, AdapterSources::SITE_DIR . '/')
                        || str_ends_with((string) $path, '/site.duo.json')) {
                        $source = AdapterSources::SITE;
                        break 2;
                    }
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

        if (isset($report['adapter'])) {
            self::render_adapter($report['adapter']);
        } else {
            echo "\ninstalled adapters (each grammar verdict is an ISOLATED load; see deferred):\n";
            foreach ($report['adapters'] as $row) {
                echo sprintf(
                    "  [%s] %-28s %-8s %-21s %-13s %s\n",
                    $row['grammar']['status'],
                    $row['name'],
                    $row['source'],
                    $row['trust_tier'],
                    $row['certification'] === 'uncertified'
                        ? 'uncertified'
                        : (string) ($row['disposition_status'] ?? ($row['certification'] === null
                            ? 'no-registry'
                            : 'unreviewed')),
                    $row['path']
                );
                echo '          tier basis: ' . $row['tier_basis'] . "\n";
                if ($row['grammar']['message'] !== null) {
                    echo '          ' . $row['grammar']['message'] . "\n";
                }
            }
            if ($report['adapters'] === []) {
                echo "  (none)\n";
            }
        }

        self::render_refusals($report['refusals']);

        if (isset($report['blockers'])) {
            echo "\nreadiness blockers for this repository's pins (source gate only; no live target was probed):\n";
            foreach ($report['blockers'] as $blocker) {
                // Lockstep with agent/src/Cli.php's plan renderer and
                // cli/src/PlanSummary.php: same row, same fields, same order.
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
            echo "\nsummary: {$s['adapters']} adapter(s) installed ({$s['shipped']} shipped, {$s['site']} site), "
                . "{$s['grammar_error']} grammar error(s), {$s['grammar_unjudged']} unjudged "
                . '(their source is refused, so their own manifests were never read), '
                . "{$s['refusals']} refusal(s)"
                . (isset($s['blockers']) ? ", {$s['blockers']} readiness blocker(s)" : '')
                . '; ' . count($report['deferred']) . " check(s) NOT performed here (see the deferred list above)\n";
        }
    }

    /** @param list<array<string,mixed>> $refusals */
    private static function render_refusals(array $refusals): void {
        echo "\nrefusals — installed files the engine will not load, and why:\n";
        if ($refusals === []) {
            echo "  (none)\n";
            return;
        }
        foreach ($refusals as $refusal) {
            echo '  [' . $refusal['code'] . '] ' . implode(', ', $refusal['paths']) . "\n";
            echo '          ' . $refusal['message'] . "\n";
            echo '          remediation: ' . $refusal['remediation'] . "\n";
        }
    }

    /** @param array<string,mixed> $row */
    private static function render_adapter(array $row): void {
        echo "\nADAPTER {$row['name']}\n";
        echo "  source:            {$row['source']} ({$row['path']})\n";
        echo "  sha256:            {$row['sha256']}\n";
        echo "  trust_tier:        {$row['trust_tier']}\n";
        echo "  tier_basis:        {$row['tier_basis']}\n";
        echo '  certification:     ' . ($row['certification']
            ?? '(none — this manifest library carries no reviewed dispositions and no generated registry, '
                . 'so it makes no product claim)') . "\n";
        echo '  disposition:       ' . ($row['disposition_status'] ?? '(no reviewed entry)') . "\n";
        echo '  grammar:           ' . $row['grammar']['status']
            . ($row['grammar']['message'] === null ? '' : ' — ' . $row['grammar']['message']) . "\n";

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
            echo "  registry claim:    (none — this adapter has no generated capability claim)\n";
        } else {
            echo "  registry claim:\n";
            echo '    status:              ' . ($claim['status'] ?? '?') . "\n";
            echo '    reason:              ' . ($claim['reason'] ?? '') . "\n";
            echo '    adapter_digest:      ' . ($claim['adapter_digest'] ?? 'none') . "\n";
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
            echo '    evidence bundle:     ' . ($claim['evidence']['bundle_digest'] ?? 'none') . "\n";
        }

        $verification = $row['verification'];
        echo "  verification (the facts that exist, not a scale):\n";
        echo '    evidence.status:            ' . ($verification['evidence_status'] ?? '(no registry)') . "\n";
        echo '    plugin_execution.status:    ' . ($verification['plugin_execution_status'] ?? '(no claim)') . "\n";
        if ($verification['tests'] === []) {
            echo "    cited tests:                (none)\n";
        }
        foreach ($verification['tests'] as $test) {
            echo '    cited test:                 ' . $test['id'] . ' — ' . $test['verdict'] . "\n";
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
     * as cli/src/ManifestValidate.php's own boot() does and for the same
     * reason: `Policy::load()` compares a manifest's declared `spec_version`
     * against DUO_SPEC_VERSION, so leaving it undefined would make every
     * shipped adapter report a grammar error about this command rather than
     * about the adapter.
     */
    private static function boot(): void {
        $repo = dirname(__DIR__, 2);
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
        foreach (['Canon', 'OptionState', 'ManifestDispositions', 'CapabilityRegistry', 'Policy'] as $class) {
            require_once $repo . "/agent/src/$class.php";
        }
    }

    /** Fail closed on this command's own paths: usage, a bad dir, an unreadable library. */
    private static function fail(string $message): int {
        fwrite(STDERR, "duo: adapter: $message\n");
        return 2;
    }
}

<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once __DIR__ . '/CommandOutput.php';
require_once __DIR__ . '/../Assess/FleetCensus.php';

/**
 * Host command boundary for `wprism census`.
 *
 * ## The exit-code contract
 *
 * The same three codes the rest of the env-free family publishes
 * (MergeCheckCommand.php:19-24, ManifestValidate.php, AdapterDraft.php,
 * AdapterCatalog.php):
 *
 *   0  a census was produced
 *   1  refusal — a submission, a label or the manifest library could not be read
 *   2  usage error
 *
 * A narrow sample is NOT a fourth code. `basis.sample_class` says so inside
 * the document, because a two-site census is a real answer about two sites and
 * a CI job that treated it as a failure would be wrong about the only thing
 * the operator asked.
 *
 * ## Why the label is a flag and not the filename
 *
 * `--site=<label>=<path>` forces the caller to name the site, and
 * `FleetCensus::LABEL_PATTERN` refuses anything that could be a hostname. The
 * `--dir` convenience uses each file's stem as the label and puts it through
 * the SAME grammar, so a submission saved as `<host>.<tld>.json` is refused
 * rather than quietly pooled — which is the point: a census document is meant
 * to be shared, and the moment a filename becomes an identifier the redaction
 * `Coverage` maintains all the way through the inventory is undone by the
 * shell.
 */
final class CensusCommand {
    private const FLAGS = ['--site', '--dir', '--manifests', '--format', '--limit', '--health', '--baseline', '--current'];

    /** Human rows only; the JSON document is never truncated. */
    private const DEFAULT_LIMIT = 20;

    /** @param list<string> $args */
    public static function run(array $args): int {
        // Read off raw argv before parsing, exactly as MergeCheckCommand.php:50
        // does: a caller that asked for machine output must get one parseable
        // envelope even for the refusal that fires while its own flags are
        // still being read.
        $json = CommandOutput::wantsAgentRefusalJson('census', $args);
        try {
            $options = self::options($args);
        } catch (\RuntimeException $e) {
            return self::refuse(
                $json,
                FleetCensus::EXIT_USAGE,
                'invalid_arguments',
                $e->getMessage(),
                // Byte-identical to what it has always been (AGENTS.md rule 8):
                // `--health` is optional, and the line already omits `--limit`
                // for the same reason — a usage remediation names the shape of
                // the call, not every flag.
                'run `wprism census --site=<label>=<inventory.json> [--dir=<dir>] [--manifests=<dir>] [--format=json]`'
            );
        }
        $json = $options['format'] === 'json';

        try {
            // One verb, two documents. `--baseline=` is the whole switch: with
            // it this run answers "what moved", without it "what is". Both
            // still exit 0/1/2 — a cohort FINDING is a fact inside the
            // document, never a fourth code (CohortRebaseline.php's header).
            $document = $options['baseline'] === null
                ? FleetCensus::run($options)
                : FleetCensus::rebaseline($options);
        } catch (FleetCensusRefusal $refusal) {
            return self::refuse(
                $json,
                FleetCensus::EXIT_REFUSED,
                $refusal->reasonCode,
                $refusal->getMessage(),
                $refusal->remediation,
                $refusal->diagnostic
            );
        } catch (\Throwable $e) {
            return self::refuse(
                $json,
                FleetCensus::EXIT_REFUSED,
                'census_failed',
                'census could not complete over these submissions',
                'read the diagnostic below, resolve it, and rerun census',
                $e->getMessage()
            );
        }

        if ($json) {
            // Canon::encode() already terminates with LF (Canon.php:102).
            echo \WPrism\Canon::encode($document);
            return FleetCensus::EXIT_OK;
        }
        if ($options['baseline'] === null) {
            self::render($document, $options['limit']);
        } else {
            self::renderRebaseline($document, $options['limit']);
        }
        return FleetCensus::EXIT_OK;
    }

    /**
     * One refusal channel for both output modes, and the code is passed rather
     * than taken from `renderRefusalJson()` — which always returns 1 — because
     * a usage error is 2 in this verb family.
     */
    private static function refuse(
        bool $json,
        int $code,
        string $reason,
        string $message,
        string $remediation,
        ?string $diagnostic = null
    ): int {
        if ($json) {
            CommandOutput::renderRefusalJson(
                'census',
                $reason,
                $message,
                $remediation,
                $diagnostic === null ? [] : [['code' => $reason, 'detail' => $diagnostic]]
            );
            return $code;
        }
        fwrite(STDERR, 'wprism: census: ' . $message . "\n");
        if ($diagnostic !== null && trim($diagnostic) !== '') {
            foreach (explode("\n", rtrim($diagnostic, "\n")) as $line) {
                fwrite(STDERR, '  ' . $line . "\n");
            }
        }
        fwrite(STDERR, 'wprism: census: remedy: ' . $remediation . "\n");
        return $code;
    }

    /**
     * The human view is the decision, in reading order: how much of this is
     * measured, how much of it WPrism sees, what to build, what to pin. Counts
     * only — the residual listings live in the JSON document, and a terminal
     * that printed one line per invisible option group on a fleet of forty
     * sites would bury the four numbers an operator came for.
     *
     * @param array<string,mixed> $document
     */
    private static function render(array $document, int $limit): void {
        $population = (array) $document['population'];
        $basis = (array) $document['basis'];
        $fleet = (array) $document['fleet'];

        echo 'census: ' . (int) $population['eligible'] . ' eligible of '
            . (int) $population['submissions'] . ' submitted (sample: '
            . (string) $basis['sample_class'] . ")\n";
        foreach ((array) $population['excluded'] as $row) {
            $row = (array) $row;
            echo 'excluded: ' . (int) $row['sites'] . ' x site_mode='
                . (string) $row['site_mode'] . ' — ' . (string) $row['reason'] . "\n";
        }
        echo 'denominator: ' . (string) $population['disclosure'] . "\n";
        echo 'coverage: ' . self::percent($fleet['covered_ppm'] ?? null) . ' of '
            . (int) $fleet['total'] . ' surfaces (' . (int) $fleet['covered'] . ' covered, '
            . (int) $fleet['pending'] . ' pending, ' . (int) $fleet['uncovered'] . " uncovered)\n";

        $funnel = (array) $document['funnel'];
        $parts = [];
        foreach (FleetCensus::FUNNEL_STAGES as $stage) {
            $parts[] = $stage . '=' . (int) ((array) $funnel[$stage])['plugins'];
        }
        echo 'funnel: ' . implode(' ', $parts) . "\n";

        $demand = (array) $document['demand'];
        $shown = 0;
        foreach ($demand as $row) {
            $row = (array) $row;
            if ($shown >= $limit) {
                break;
            }
            $shown++;
            echo 'demand ' . (string) $row['slug'] . ': score=' . (int) $row['demand_score']
                . ' sites=' . (int) $row['sites_installed']
                . ' uncovered=' . (int) $row['uncovered_option_groups'] . ' group(s)/'
                . (int) $row['uncovered_tables'] . ' table(s)'
                . ' adapter=' . (($row['covering_adapter'] ?? null) === null ? 'none' : (string) $row['covering_adapter'])
                . ' pinned=' . (int) $row['sites_pinning'] . '/' . (int) $row['sites_installed']
                . ' [' . (string) $row['funnel_stage'] . "]\n";
        }
        if (count($demand) > $shown) {
            echo 'demand: ' . (count($demand) - $shown) . " further row(s) in --format=json\n";
        }

        // Health prints only when a health document was supplied. The block is
        // always in the JSON with its own disclosure, but a terminal line
        // saying "0 stale" when nothing was measured would be the silence this
        // whole verb exists to remove.
        $health = (array) ($document['fleet_health'] ?? []);
        if (($health['source'] ?? 'not-supplied') !== 'not-supplied') {
            echo 'health: ' . (int) $health['stale'] . ' stale of ' . (int) $health['adapters']
                . ' adapter(s), ' . (int) $health['open_proposals'] . " open range proposal(s)\n";
            $shownHealth = 0;
            foreach ((array) $health['rows'] as $row) {
                $row = (array) $row;
                if ($shownHealth >= $limit) {
                    break;
                }
                if ($row['stale'] !== true) {
                    continue;
                }
                $shownHealth++;
                $proposal = $row['open_proposal'] ?? null;
                echo 'stale ' . (string) $row['adapter'] . ': ' . (string) $row['freshness_class']
                    . ' last_verified=' . ($row['last_verified'] === null ? 'none' : (string) $row['last_verified'])
                    . ' behind=' . (int) $row['releases_behind']
                    . ' pinned=' . (int) $row['sites_pinning'] . '/' . (int) $row['sites_installed']
                    . ' exposure=' . (int) $row['exposure_score']
                    . (is_array($proposal)
                        ? ' proposal=' . (string) $proposal['min'] . '..' . (string) $proposal['max']
                        : '')
                    . "\n";
            }
        }

        $unattributed = (array) $document['unattributed'];
        if ((int) $unattributed['surfaces'] > 0) {
            // Named rather than folded into a plugin: Coverage::attribute() is
            // advisory and returns null rather than guessing, and a residual
            // nobody owns is exactly the part an operator has to look at by
            // hand.
            echo 'unattributed: ' . (int) $unattributed['surfaces'] . ' residual surface(s), '
                . (int) $unattributed['option_rows'] . ' option row(s), '
                . (int) $unattributed['table_rows'] . " table row(s)\n";
        }
        echo 'basis: ' . (string) $basis['caveat'] . "\n";
    }

    /**
     * The re-baseline view, in the order the decision is made: is this even a
     * comparison, did the ratio move, where did the funnel move, which
     * adapters moved which surfaces, and what does that add up to.
     *
     * The verdict line is LAST and unbounded — `--limit` bounds the
     * attribution listing above it, never the finding, because the one row a
     * truncated view must never drop is the one that says the cohort failed.
     *
     * @param array<string,mixed> $document
     */
    private static function renderRebaseline(array $document, int $limit): void {
        $comparability = (array) $document['comparability'];
        $sites = (array) $comparability['sites'];
        $library = (array) $comparability['library'];
        echo 'rebaseline: ' . (int) $sites['baseline'] . ' -> ' . (int) $sites['current']
            . ' labelled site(s) (' . (string) $comparability['class'] . ")\n";
        echo 'comparability: ' . (string) $comparability['disclosure'] . "\n";
        echo 'library: ' . (int) $library['baseline_adapters'] . ' -> ' . (int) $library['current_adapters']
            . ' adapter(s), ' . (int) $library['baseline_reviewed'] . ' -> ' . (int) $library['current_reviewed']
            . ' reviewed, coverage oracle '
            . ($library['moved'] === true ? 'MOVED' : 'unchanged (identical surfaces_sha256)') . "\n";

        $coverage = (array) $document['coverage'];
        $baseline = (array) $coverage['baseline'];
        $current = (array) $coverage['current'];
        $delta = (array) $coverage['delta'];
        echo 'coverage: ' . self::percent($baseline['covered_ppm'] ?? null) . ' -> '
            . self::percent($current['covered_ppm'] ?? null) . ' ('
            . self::signedPpm($delta['covered_ppm'] ?? null) . ') over '
            . (int) $baseline['total'] . ' -> ' . (int) $current['total'] . " surface(s)\n";

        $parts = [];
        foreach (FleetCensus::FUNNEL_STAGES as $stage) {
            $row = (array) ((array) $document['funnel'])[$stage];
            $parts[] = $stage . '=' . (int) ((array) $row['baseline'])['plugins']
                . '->' . (int) ((array) $row['current'])['plugins']
                . '(' . self::signed((int) ((array) $row['delta'])['plugins']) . ')';
        }
        echo 'funnel: ' . implode(' ', $parts) . "\n";

        $attribution = (array) $document['attribution'];
        $moved = (array) $attribution['moved'];
        $shown = 0;
        foreach ($moved as $row) {
            $row = (array) $row;
            if ($shown >= $limit) {
                break;
            }
            $shown++;
            echo 'moved ' . (string) $row['slug'] . ': adapter='
                . (($row['adapter_baseline'] ?? null) === null ? 'none' : (string) $row['adapter_baseline'])
                . '->' . (($row['adapter_current'] ?? null) === null ? 'none' : (string) $row['adapter_current'])
                . ' (' . (string) $row['adapter_change'] . ')'
                . ' claimed=' . count((array) $row['surfaces_claimed'])
                . ' regressed=' . count((array) $row['surfaces_regressed'])
                . ' appeared=' . count((array) $row['surfaces_appeared'])
                . ' resolved=' . count((array) $row['surfaces_resolved'])
                . ' pins=' . self::signed((int) $row['sites_pinning_delta'])
                . ' [' . (($row['stage_baseline'] ?? null) === null ? 'absent' : (string) $row['stage_baseline'])
                . '->' . (($row['stage_current'] ?? null) === null ? 'absent' : (string) $row['stage_current'])
                . "]\n";
        }
        if (count($moved) > $shown) {
            echo 'moved: ' . (count($moved) - $shown) . " further row(s) in --format=json\n";
        }
        echo 'attribution: ' . count($moved) . ' slug row(s) moved, '
            . (int) $attribution['unchanged'] . " unchanged\n";

        $cohort = (array) $document['cohort'];
        echo 'cohort: ' . count((array) $cohort['adapters_added']) . ' adapter(s) added, '
            . count((array) $cohort['slugs_newly_covered']) . ' slug(s) newly covered, '
            . count((array) $cohort['slugs_newly_reviewed']) . ' newly reviewed, '
            . count((array) $cohort['slugs_newly_pinned']) . ' newly pinned; '
            . (int) $cohort['surfaces_claimed'] . ' surface(s) claimed, '
            . (int) $cohort['surfaces_regressed'] . " regressed\n";
        echo 'verdict: ' . (string) $cohort['verdict'] . ' — ' . (string) $cohort['disclosure'] . "\n";

        $finding = $cohort['finding'] ?? null;
        if (is_array($finding)) {
            // stdout, not stderr: this is the ANSWER the run was asked for,
            // and a caller redirecting stdout to a file must find it there.
            echo 'FINDING ' . (string) $finding['code'] . ': ' . (string) $finding['statement'] . "\n";
            echo 'remedy: ' . (string) $finding['remedy'] . "\n";
        }
    }

    /** A ppm delta with its sign, and the percentage-point move a human reads. */
    private static function signedPpm(mixed $ppm): string {
        if (!is_int($ppm)) {
            return 'delta n/a';
        }
        return 'delta ' . self::signed($ppm) . ' ppm / '
            . ($ppm < 0 ? '-' : '+') . number_format(abs($ppm) / 10000, 1) . 'pp';
    }

    /** An integer that always carries its sign, so `0` cannot read as "unmeasured". */
    private static function signed(int $value): string {
        return ($value < 0 ? '-' : '+') . abs($value);
    }

    /** A ppm integer rendered as the percentage a human reads; `n/a` when nothing was measured. */
    private static function percent(mixed $ppm): string {
        if (!is_int($ppm)) {
            return 'n/a';
        }
        return number_format($ppm / 10000, 1) . '%';
    }

    /**
     * `--site` is the only repeatable flag here, which is why this parser is
     * written rather than reused from MergeCheckCommand::flags() — that one
     * refuses a duplicate outright, and a census of one site would be the only
     * census it could express.
     *
     * @param list<string> $args
     * @return array{sites:array<string,string>,manifests:string|null,format:string,limit:int,health:?string,baseline:?string,current:?string}
     */
    private static function options(array $args): array {
        $sites = [];
        $manifests = null;
        $health = null;
        $baseline = null;
        $current = null;
        $format = 'human';
        $limit = self::DEFAULT_LIMIT;
        $dirs = [];
        foreach ($args as $arg) {
            if (!is_string($arg) || !str_starts_with($arg, '--') || !str_contains($arg, '=')) {
                throw new \RuntimeException('expected --name=value flags');
            }
            [$name, $value] = explode('=', $arg, 2);
            if (!in_array($name, self::FLAGS, true) || $value === '') {
                throw new \RuntimeException("unsupported or empty flag '$arg'");
            }
            switch ($name) {
                case '--site':
                    if (!str_contains($value, '=')) {
                        throw new \RuntimeException('--site takes <label>=<path>');
                    }
                    [$label, $path] = explode('=', $value, 2);
                    if ($label === '' || $path === '') {
                        throw new \RuntimeException('--site takes <label>=<path>');
                    }
                    if (isset($sites[$label])) {
                        throw new \RuntimeException("duplicate site label '$label'");
                    }
                    $sites[$label] = $path;
                    break;
                case '--dir':
                    $dirs[] = $value;
                    break;
                case '--manifests':
                    if ($manifests !== null) {
                        throw new \RuntimeException('duplicate flag \'--manifests\'');
                    }
                    $manifests = $value;
                    break;
                case '--health':
                    // Refused rather than last-wins, exactly like --manifests:
                    // a second health document silently replacing the first
                    // would rank a freshness record the operator did not name.
                    if ($health !== null) {
                        throw new \RuntimeException('duplicate flag \'--health\'');
                    }
                    $health = $value;
                    break;
                case '--baseline':
                    if ($baseline !== null) {
                        throw new \RuntimeException('duplicate flag \'--baseline\'');
                    }
                    $baseline = $value;
                    break;
                case '--current':
                    if ($current !== null) {
                        throw new \RuntimeException('duplicate flag \'--current\'');
                    }
                    $current = $value;
                    break;
                case '--format':
                    if ($value !== 'json') {
                        throw new \RuntimeException('--format must be json');
                    }
                    $format = 'json';
                    break;
                case '--limit':
                    if (preg_match('/^[1-9][0-9]{0,2}$/D', $value) !== 1 || (int) $value > 200) {
                        throw new \RuntimeException('--limit must be 1..200');
                    }
                    $limit = (int) $value;
                    break;
            }
        }
        // The current side has exactly one source. `--current` names a census
        // measured by the engine of its own day — the only way to compare
        // across a spec flag day — and submissions name one measured here; a
        // call that supplied both would be asking this verb to choose which of
        // the operator's two answers is the real present, which is not a
        // choice a tool gets to make silently.
        if ($current !== null && $baseline === null) {
            throw new \RuntimeException('--current needs --baseline: a re-baseline is a comparison, not one document');
        }
        if ($current !== null && ($sites !== [] || $dirs !== [])) {
            throw new \RuntimeException('--current and --site/--dir both name the current side; pass exactly one');
        }
        if ($current !== null && $health !== null) {
            throw new \RuntimeException('--health ranks a census this run does not measure; drop it when --current is a document');
        }

        foreach ($dirs as $dir) {
            foreach (self::submissionsIn($dir) as $label => $path) {
                if (isset($sites[$label])) {
                    throw new \RuntimeException("duplicate site label '$label'");
                }
                $sites[$label] = $path;
            }
        }
        ksort($sites, SORT_STRING);

        return [
            'sites' => $sites,
            // Null selects Policy::shipped_adapter_library() inside the
            // census after its env-free agent bootstrap. A path exists only
            // when the operator explicitly selected the transitional sparse
            // legacy-fixture surface with --manifests.
            'manifests' => $manifests,
            'format' => $format,
            'limit' => $limit,
            // No default path. The freshness document is DERIVED and this
            // process writes nothing, so there is no location this command may
            // assume one was left at — an assumed default that happened to be
            // stale would rank yesterday's backlog as today's.
            'health' => $health,
            // No default for either side, for the same reason: a re-baseline
            // against a baseline nobody named is a comparison against a file
            // this process happened to find.
            'baseline' => $baseline,
            'current' => $current,
        ];
    }

    /**
     * Every `*.json` directly inside one directory, labelled by its stem.
     *
     * Non-recursive on purpose: a collection directory is a flat drop box, and
     * walking it would make what the census folded a function of a tree the
     * caller cannot see in the output.
     *
     * @return array<string,string>
     */
    private static function submissionsIn(string $dir): array {
        if (!is_dir($dir)) {
            throw new \RuntimeException("--dir '$dir' is not a directory");
        }
        $out = [];
        foreach (glob(rtrim($dir, '/') . '/*.json') ?: [] as $path) {
            $out[basename($path, '.json')] = $path;
        }
        if ($out === []) {
            throw new \RuntimeException("--dir '$dir' holds no .json submissions");
        }
        ksort($out, SORT_STRING);
        return $out;
    }
}

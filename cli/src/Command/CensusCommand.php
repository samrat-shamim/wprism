<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/CommandOutput.php';
require_once __DIR__ . '/../Assess/FleetCensus.php';

/**
 * Host command boundary for `duo census`.
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
    private const FLAGS = ['--site', '--dir', '--manifests', '--format', '--limit', '--health'];

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
                'run `duo census --site=<label>=<inventory.json> [--dir=<dir>] [--manifests=<dir>] [--format=json]`'
            );
        }
        $json = $options['format'] === 'json';

        try {
            $document = FleetCensus::run($options);
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
            echo \Duo\Canon::encode($document);
            return FleetCensus::EXIT_OK;
        }
        self::render($document, $options['limit']);
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
        fwrite(STDERR, 'duo: census: ' . $message . "\n");
        if ($diagnostic !== null && trim($diagnostic) !== '') {
            foreach (explode("\n", rtrim($diagnostic, "\n")) as $line) {
                fwrite(STDERR, '  ' . $line . "\n");
            }
        }
        fwrite(STDERR, 'duo: census: remedy: ' . $remediation . "\n");
        return $code;
    }

    /**
     * The human view is the decision, in reading order: how much of this is
     * measured, how much of it Duo sees, what to build, what to pin. Counts
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
     * @return array{sites:array<string,string>,manifests:string,format:string,limit:int,health:?string}
     */
    private static function options(array $args): array {
        $sites = [];
        $manifests = null;
        $health = null;
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
            // The library this checkout ships is the default oracle for the
            // same reason `Policy::manifests_dir()` is the agent's: a census
            // run from a duo checkout is asking about the adapters that
            // checkout could deploy.
            'manifests' => $manifests ?? dirname(__DIR__, 3) . '/manifests',
            'format' => $format,
            'limit' => $limit,
            // No default path. The freshness document is DERIVED and this
            // process writes nothing, so there is no location this command may
            // assume one was left at — an assumed default that happened to be
            // stale would rank yesterday's backlog as today's.
            'health' => $health,
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

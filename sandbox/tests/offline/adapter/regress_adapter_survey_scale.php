<?php

/**
 * Offline scale + staleness contract for `AdapterSources::survey()`: ONE
 * resolved adapter library per survey, and a library that moves under one
 * REFUSES rather than being re-resolved halfway through.
 *
 * WHAT IS BEING PINNED
 * --------------------
 * Until WP-1.3 every surveyed adapter was judged through its own
 * `Policy::load()`, and each of those re-ran the two whole-library
 * resolutions the row before it had already paid for — `AdapterSources::
 * discover()` (which globs the library, walks `adapters/`, verifies every
 * site certificate and scans the active plugin bundles) and
 * `ManifestDispositions::load()`. The work is a function of the LIBRARY and
 * it was being repeated once per ROW. Measured before the change against this
 * fixture: 501 library globs, 501 reads of the reviewed registry and 1,524 ms
 * for 500 adapters, growing 3.7x per doubling — the shape of O(N^2). After: 6
 * globs and 2 registry reads at every size, 85 ms at 500, 2.1x per doubling.
 *
 * COUNTED, NOT TIMED, for the reason
 * sandbox/tests/offline/policy/regress_policy_load_scale.php states: a wall
 * time threshold on a shared developer machine is a flake generator and names
 * no mechanism. The counts below are exact, reproducible on any host, and
 * each one IS the mechanism — a survey that resolved the library twice fails
 * them by name. Wall time is reported beside them as evidence, and bounded
 * only where the bound is loose enough to be a shape check rather than a
 * performance budget.
 *
 * The counters sit on the real product path: `Duo\glob()`,
 * `Duo\json_decode()` and `Duo\file_get_contents()` are declared in the
 * measuring process before the agent is required, so the UNQUALIFIED calls
 * inside `namespace Duo` resolve to them and no seam is added to the engine
 * for a test's benefit. The interception is proved live before any number is
 * trusted (`instrument_alive`), because a counter that silently stopped
 * intercepting would report a beautiful, meaningless 0.
 *
 * THE STALENESS HALF
 * ------------------
 * The memo is only safe while it still describes the disk, so the same
 * intercepted read is used to MUTATE the library mid-survey — a new manifest
 * appearing, and an existing manifest rewritten in place — and the survey
 * must refuse with `adapter_library_moved` instead of publishing rows judged
 * against two different libraries. The two mutations are caught by different
 * halves of the witness (directory shape per row, file content at settle),
 * which is why both are here.
 *
 * NO `declare(strict_types=1)` HERE, DELIBERATELY, for
 * regress_policy_load_scale.php's reason: this file re-declares three
 * functions the whole `Duo` namespace calls, and under strict types every
 * forwarded call would be argument-checked with THIS file's strictness rather
 * than the calling file's — a harness able to change the behaviour of the path
 * it measures.
 */

namespace Duo {
    /**
     * The three seams. Each forwards verbatim — same arguments, same return
     * value, and for json_decode the same `json_last_error()` state for
     * `Canon::decode()`'s check on the line after its call.
     */
    function glob(...$args) {
        \DuoSurveyProbe::$globs[] = (string) $args[0];
        return \glob(...$args);
    }

    function json_decode(...$args) {
        \DuoSurveyProbe::$decodes++;
        return \json_decode(...$args);
    }

    function file_get_contents(...$args) {
        \DuoSurveyProbe::read((string) $args[0]);
        return \file_get_contents(...$args);
    }
}

namespace {

    // WordPress supplies this in production; Policy's single-site gate runs
    // without bootstrapping WordPress. ABSPATH/WPINC stay undefined on
    // purpose: assert_supported_platform() returns early without them, so the
    // measured counts are the scan's alone.
    function is_multisite(): bool {
        return false;
    }
    if (!defined('DUO_SPEC_VERSION')) {
        define('DUO_SPEC_VERSION', 2);
    }

    final class DuoSurveyProbe {
        /** @var list<string> */
        public static array $globs = [];
        public static int $decodes = 0;
        /** @var list<string> */
        public static array $reads = [];
        /** The read ordinal at which the library is mutated; 0 disables. */
        public static int $mutateAt = 0;
        public static string $mutateMode = '';
        public static string $library = '';
        public static bool $mutated = false;

        public static function read(string $path): void {
            self::$reads[] = $path;
            if (self::$mutateAt > 0 && count(self::$reads) === self::$mutateAt) {
                self::mutate();
            }
        }

        /**
         * The mid-survey mutation, performed from INSIDE a read the survey
         * itself is making — the only way to interleave a change with a loop
         * that neither yields nor calls out. `add` moves the file SET (caught
         * by the per-row directory witness); `rewrite` edits one manifest in
         * place, churning no directory entry (caught by the content witness
         * at settle()).
         */
        private static function mutate(): void {
            self::$mutated = true;
            if (self::$mutateMode === 'add') {
                file_put_contents(
                    self::$library . '/zz-late-arrival.json',
                    (string) json_encode(['name' => 'zz-late-arrival', 'spec_version' => DUO_SPEC_VERSION])
                );
                return;
            }
            $file = self::$library . '/scale-adapter-00000.json';
            $bytes = (string) file_get_contents($file);
            // Same LENGTH, so nothing but the content digest can see it.
            file_put_contents($file, strrev($bytes) === $bytes ? $bytes . ' ' : str_replace('authored', 'AUTHORED', $bytes));
        }

        public static function reset(): void {
            self::$globs = [];
            self::$decodes = 0;
            self::$reads = [];
        }
    }

    $scaleLibrary = (string) (getenv('DUO_ADAPTER_SURVEY_SCALE_LIBRARY') ?: '');

    // ------------------------------------------------------------------
    // Child mode: one survey of one library, reported as one JSON line.
    // ------------------------------------------------------------------
    if ($scaleLibrary !== '') {
        $agent = __DIR__ . '/../../../../agent';
        require $agent . '/src/Kernel/Canon.php';
        require $agent . '/src/Kernel/OptionState.php';
        require $agent . '/src/Kernel/Db.php';
        require $agent . '/src/Kernel/Secrets.php';
        require $agent . '/src/Kernel/CommandRefusal.php';
        require $agent . '/src/Policy/ManifestDispositions.php';
        require $agent . '/src/Policy/Policy.php';
        require_once $agent . '/src/Adapter/AdapterSources.php';

        $repo = (string) (getenv('DUO_ADAPTER_SURVEY_SCALE_REPO') ?: '');
        $repo = $repo === '' ? null : $repo;
        DuoSurveyProbe::$library = $scaleLibrary;
        DuoSurveyProbe::$mutateAt = (int) (getenv('DUO_ADAPTER_SURVEY_SCALE_MUTATE') ?: '0');
        DuoSurveyProbe::$mutateMode = (string) (getenv('DUO_ADAPTER_SURVEY_SCALE_MUTATE_MODE') ?: '');
        putenv("DUO_MANIFESTS_DIR=$scaleLibrary");

        // Proof that the seams are live, through the same engine methods the
        // measurement counts, before any number is reported.
        DuoSurveyProbe::reset();
        Duo\Canon::decode('{"instrument":"alive"}');
        $instrumentAlive = DuoSurveyProbe::$decodes === 1;

        DuoSurveyProbe::reset();
        $started = microtime(true);
        $refusal = null;
        $movedBy = null;
        $rows = -1;
        try {
            $survey = Duo\AdapterSources::survey($repo);
            $rows = count($survey['adapters']);
            $statuses = array_count_values(array_column(array_column($survey['adapters'], 'grammar'), 'status'));
        } catch (Duo\CommandRefusalException $typed) {
            $refusal = $typed->reasonCode;
            // WHICH half of the witness saw it. The operator sentence names
            // the axis, and the two mutations below are here precisely because
            // they are caught by different ones; reported as that one word
            // rather than the whole sentence, which carries scratch paths.
            $movedBy = str_contains($typed->getMessage(), 'file content change')
                ? 'content'
                : (str_contains($typed->getMessage(), 'directory or activation change') ? 'shape' : 'unknown');
            $statuses = [];
        } catch (\Throwable $other) {
            $refusal = 'unexpected/' . get_class($other) . ': ' . $other->getMessage();
            $statuses = [];
        }
        $elapsed = microtime(true) - $started;
        // The counters are read off HERE, before this file does any measuring
        // of its own: the witness comparison below globs the library twice
        // itself, and a harness that counted its own reads would report the
        // survey as more expensive than it is.
        $globs = DuoSurveyProbe::$globs;
        $reads = DuoSurveyProbe::$reads;
        $decodes = DuoSurveyProbe::$decodes;

        // Every read this survey made under the library or the repository,
        // against the dependency set the memo's witness is taken over: a read
        // the witness does not name is a file whose change the memo cannot
        // see.
        $witness = Duo\AdapterSources::scan_dependencies($scaleLibrary, $repo);
        $named = [];
        foreach ($witness['files'] as $file) {
            $named[(string) (realpath($file) ?: $file)] = true;
        }
        $roots = array_values(array_filter([$scaleLibrary, $repo]));
        $unwitnessed = [];
        foreach (array_unique($reads) as $read) {
            $resolved = (string) (realpath($read) ?: $read);
            $inside = false;
            foreach ($roots as $root) {
                if (str_starts_with($resolved, (string) (realpath($root) ?: $root))) {
                    $inside = true;
                }
            }
            if ($inside && !isset($named[$resolved])) {
                $unwitnessed[] = basename($read);
            }
        }

        $libraryGlobs = 0;
        foreach ($globs as $pattern) {
            if (str_starts_with($pattern, rtrim($scaleLibrary, '/') . '/')) {
                $libraryGlobs++;
            }
        }
        $registryReads = 0;
        foreach ($reads as $read) {
            if ($read === rtrim($scaleLibrary, '/') . '/dispositions.json') {
                $registryReads++;
            }
        }

        fwrite(STDOUT, (string) json_encode([
            'decodes' => $decodes,
            'globs' => count($globs),
            'instrument_alive' => $instrumentAlive,
            'library_globs' => $libraryGlobs,
            'moved_by' => $movedBy,
            'ms' => round($elapsed * 1000, 2),
            'mutated' => DuoSurveyProbe::$mutated,
            'reads' => count($reads),
            'refusal' => $refusal,
            'registry_reads' => $registryReads,
            'rows' => $rows,
            'statuses' => $statuses,
            'unwitnessed_reads' => array_values(array_unique($unwitnessed)),
        ]) . "\n");
        exit(0);
    }

    // ------------------------------------------------------------------
    // Parent mode: manufacture the libraries, drive the measurements, assert.
    // ------------------------------------------------------------------
    require_once __DIR__ . '/../../lib/check.php';

    /**
     * One stable scratch root under sandbox/tmp (AGENTS.md rule 3), cleared
     * before use rather than made unique per run: nothing else writes this
     * path, and a per-pid name would leave a fresh fixture behind on every red
     * run.
     */
    $scaleRoot = dirname(__DIR__, 3) . '/tmp/adapter-survey-scale';
    exec('rm -rf ' . escapeshellarg($scaleRoot));

    /** The reviewed entry every synthetic manifest gets, so the library LOADS. */
    function survey_scale_entry(): array {
        return [
            'capabilities' => [
                'deletion_semantics' => ['supported' => [], 'unsupported' => ['nothing is deletable here']],
                'entity_sections' => [],
                'field_sections' => [],
                'lifecycle_phases' => [],
                'operations' => ['apply'],
            ],
            'default_authored_keyspaces' => [],
            'reason' => 'Synthetic fixture entry: this library exists to be surveyed and counted, not to make a claim.',
            'status' => 'experimental',
            'supported_versions' => ['range' => ['max' => '2.0.0', 'min' => '1.0.0']],
            'unsupported' => [[
                'operation' => 'apply',
                'reason' => 'a synthetic fixture adapter supports nothing at all',
                'surface' => 'options.synthetic',
            ]],
        ];
    }

    /**
     * A library of $n valid, reviewed, purely declarative adapters — so every
     * surveyed row reaches `ok` and the counts measure the LOADER rather than a
     * refusal path that short-circuits it.
     */
    function survey_scale_library(string $root, int $n): string {
        $dir = "$root/n$n/manifests";
        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new RuntimeException("cannot create the synthetic manifest library at $dir");
        }
        $entries = [];
        for ($i = 0; $i < $n; $i++) {
            $name = sprintf('scale-adapter-%05d', $i);
            file_put_contents("$dir/$name.json", (string) json_encode([
                'name' => $name,
                'option_autoload' => 'preserve',
                'options' => [str_replace('-', '_', $name) . '_layout' => ['class' => 'authored']],
                'spec_version' => DUO_SPEC_VERSION,
            ]));
            $entries[$name] = survey_scale_entry();
        }
        file_put_contents("$dir/dispositions.json", (string) json_encode([
            'format' => 'duo-manifest-dispositions/v1',
            'manifests' => $entries,
            'profiles' => new stdClass(),
        ]));
        return $dir;
    }

    /**
     * A repository whose site source holds one adapter and one companion
     * certificate. The certificate is unsigned junk on purpose: the survey
     * still OPENS it (and reports the refusal as a row), which is exactly the
     * read the witness has to name.
     */
    function survey_scale_repo(string $root): string {
        $repo = "$root/repo";
        if (!is_dir("$repo/adapters/certifications") && !mkdir("$repo/adapters/certifications", 0o777, true)) {
            throw new RuntimeException("cannot create the synthetic repository at $repo");
        }
        file_put_contents("$repo/site.duo.json", (string) json_encode([
            'manifests' => ['scale-adapter-00000'],
            'policy' => new stdClass(),
            'spec_version' => DUO_SPEC_VERSION,
        ]));
        file_put_contents("$repo/adapters/acme-widget.json", (string) json_encode([
            'name' => 'acme-widget',
            'option_autoload' => 'preserve',
            'options' => ['acme_widget_layout' => ['class' => 'authored']],
            'spec_version' => DUO_SPEC_VERSION,
        ]));
        file_put_contents(
            "$repo/adapters/certifications/acme-widget.json",
            (string) json_encode(['format' => 'not-a-certificate'])
        );
        return $repo;
    }

    /**
     * One measurement, in a child `php` invocation of THIS file — the idiom
     * regress_policy_load_scale.php and regress_doctor_command.php use.
     * Re-entering the same file keeps one definition of the measurement
     * instead of a second fixture that could agree with a broken engine.
     *
     * @return array{exit:int,output:string,measurement:?array<string,mixed>}
     */
    function survey_scale_measure(string $library, ?string $repo = null, int $mutateAt = 0, string $mode = ''): array {
        $command = 'DUO_ADAPTER_SURVEY_SCALE_LIBRARY=' . escapeshellarg($library)
            . ' DUO_ADAPTER_SURVEY_SCALE_REPO=' . escapeshellarg((string) $repo)
            . ' DUO_ADAPTER_SURVEY_SCALE_MUTATE=' . escapeshellarg((string) $mutateAt)
            . ' DUO_ADAPTER_SURVEY_SCALE_MUTATE_MODE=' . escapeshellarg($mode)
            . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>&1';
        $lines = [];
        $status = 0;
        exec($command, $lines, $status);
        $decoded = json_decode((string) end($lines), true);
        return [
            'exit' => $status,
            'output' => implode("\n", $lines),
            'measurement' => is_array($decoded) ? $decoded : null,
        ];
    }

    $sizes = [125, 250, 500];
    $libraries = [];
    foreach ($sizes as $n) {
        $libraries[$n] = survey_scale_library($scaleRoot, $n);
    }

    echo "\n== one resolved library per survey, whatever the origin count ==\n";
    // The reviewed registry is decoded TWICE per survey and no more: once by
    // survey() for its own disposition column, once by the scan handle for the
    // loader. Before WP-1.3 it was N+1 — one more for every adapter on disk —
    // and that number IS the defect, stated exactly rather than as a bound: an
    // engine that resolved the library one extra time would pass any bound.
    $measurements = [];
    foreach ($sizes as $n) {
        $result = survey_scale_measure($libraries[$n]);
        $measurement = $result['measurement'];
        duo_check(
            $result['exit'] === 0 && is_array($measurement),
            "a survey of a $n-adapter library succeeds"
        );
        if (!is_array($measurement)) {
            duo_check_detail(duo_check_repr($result['output']));
            continue;
        }
        $measurements[$n] = $measurement;
        duo_check(
            $measurement['instrument_alive'] === true,
            "the counters are proved live before the $n-adapter measurement is trusted"
        );
        duo_check(
            $measurement['rows'] === $n && ($measurement['statuses']['ok'] ?? 0) === $n,
            "all $n rows are surveyed and every one of them reaches grammar ok: "
            . json_encode($measurement['statuses'])
        );
        duo_check(
            $measurement['registry_reads'] === 2,
            "the reviewed registry is read exactly twice for $n adapters (survey + scan handle), not once per row; "
            . 'counted ' . $measurement['registry_reads']
        );
        duo_check(
            $measurement['library_globs'] === 6,
            "and the library directory is enumerated exactly 6 times for $n adapters — the survey's own collect scan, "
            . "the handle's one discover, and the two witness passes that each name the adapters and the "
            . 'capabilities directory; counted ' . $measurement['library_globs']
        );
    }

    echo "\n== the counted work is LINEAR in the library, which is the sub-quadratic claim ==\n";
    duo_check(
        count($measurements) === count($sizes)
            && count(array_unique(array_column($measurements, 'library_globs'))) === 1
            && count(array_unique(array_column($measurements, 'registry_reads'))) === 1,
        'a library 4x larger costs the same whole-library work: ' . implode(', ', array_map(
            static fn(int $n): string => "$n => " . ($measurements[$n]['library_globs'] ?? '?') . ' globs / '
                . ($measurements[$n]['registry_reads'] ?? '?') . ' registry reads',
            $sizes
        ))
    );
    // Per-adapter work is the half that MUST grow: each row still reads and
    // validates its own manifest, which is the verdict the survey is for. Two
    // decodes per row plus the two registry decodes, exactly.
    $perRow = [];
    foreach ($sizes as $n) {
        $perRow[$n] = ($measurements[$n]['decodes'] ?? 0) - 2 * $n;
    }
    duo_check(
        count(array_unique($perRow)) === 1 && (int) reset($perRow) === 2,
        'decodes are exactly 2 per surveyed adapter plus the 2 registry decodes — the per-row half of the cost is '
        . 'untouched: ' . implode(', ', array_map(
            static fn(int $n): string => "$n => " . ($measurements[$n]['decodes'] ?? '?') . ' decodes',
            $sizes
        ))
    );
    // Wall time is REPORTED and never asserted, and the counts above are why
    // that is not a gap: they are the mechanism, exactly, at three sizes. A
    // ratio threshold here would be a flake generator on a shared machine —
    // measured on this one while five other agents ran, the 125-adapter
    // survey moved between 53 ms and 245 ms run to run, which is more spread
    // than the 4x the assertion would have been trying to see.
    duo_check_detail('wall time: ' . implode(', ', array_map(
        static fn(int $n): string => "$n adapters => " . ($measurements[$n]['ms'] ?? '?') . ' ms',
        $sizes
    )) . ' (evidence, not an assertion)');

    echo "\n== the witness names every file the scan actually reads ==\n";
    // The dependency list is hand-maintained beside scan(); this is what stops
    // it from drifting away from the walk it describes. A file the scan opens
    // and the witness does not name is a file whose change the memo cannot
    // see — the exact shape of a stale-memo defect.
    $repo = survey_scale_repo($scaleRoot);
    $withRepo = survey_scale_measure($libraries[125], $repo);
    $repoMeasurement = $withRepo['measurement'];
    duo_check(
        $withRepo['exit'] === 0 && is_array($repoMeasurement),
        'a survey with a repository, a site adapter and a companion certificate succeeds'
    );
    if (is_array($repoMeasurement)) {
        duo_check_same(
            [],
            $repoMeasurement['unwitnessed_reads'],
            'every file the scan opened under the library or the repository is named by scan_dependencies()'
        );
        duo_check(
            $repoMeasurement['registry_reads'] === 2 && $repoMeasurement['library_globs'] === 6,
            'and the repository half changes none of the whole-library counts: '
            . $repoMeasurement['registry_reads'] . ' registry reads, ' . $repoMeasurement['library_globs'] . ' globs'
        );
    } else {
        duo_check_detail(duo_check_repr($withRepo['output']));
    }

    echo "\n== a library that moves mid-survey REFUSES, both ways it can move ==\n";
    // The mutation happens from inside a read the survey itself is making, so
    // it lands between two rows of the loop — the interleaving a memo has to
    // survive, and the one an out-of-process editor produces by accident. The
    // ordinal is N + 10: the collect scan opens every manifest first (N reads,
    // plus the registry), so N + 10 is ten rows into the loop and well after
    // the handle has resolved. Mutating DURING the collect scan would be a
    // different, uninteresting test — nothing is memoized yet.
    foreach ([
        'add' => ['shape', 'a manifest that APPEARS mid-survey moves the directory witness'],
        'rewrite' => ['content', 'a manifest rewritten IN PLACE, to the same length, churns no directory entry and '
            . 'moves the content witness at settle()'],
    ] as $mode => [$half, $what]) {
        $size = 125 + ($mode === 'add' ? 1 : 2);
        $library = survey_scale_library($scaleRoot, $size);
        $moved = survey_scale_measure($library, null, $size + 10, $mode);
        $movedMeasurement = $moved['measurement'];
        duo_check(
            is_array($movedMeasurement) && $movedMeasurement['mutated'] === true,
            "$what: the fixture really did mutate the library mid-survey"
        );
        duo_check(
            is_array($movedMeasurement) && $movedMeasurement['refusal'] === 'adapter_library_moved',
            "$what — the survey refuses with the typed reason code instead of publishing rows judged against two "
            . 'libraries (got: ' . var_export(
                is_array($movedMeasurement) ? $movedMeasurement['refusal'] : $moved['output'],
                true
            ) . ')'
        );
        duo_check(
            is_array($movedMeasurement) && $movedMeasurement['moved_by'] === $half,
            "and it is the $half witness that saw it — the two halves are not interchangeable, which is why both "
            . 'exist (saw: ' . var_export(
                is_array($movedMeasurement) ? $movedMeasurement['moved_by'] : null,
                true
            ) . ')'
        );
        duo_check(
            is_array($movedMeasurement) && $movedMeasurement['rows'] === -1,
            'and it publishes NO rows at all — a refused survey is not a partial inventory'
        );
    }

    echo "\n== the handle is reachable from the read-only survey and from nowhere else ==\n";
    // The risk this work package carries is a memo that outlives its reader
    // and makes a stale origins map authoritative on a MUTATION path. The
    // mitigation is structural — one caller each — so it is asserted against
    // the shipped tree rather than promised in a docblock.
    $root = dirname(__DIR__, 4);
    /** @return list<string> repo-relative files containing $needle, declaration site excluded */
    $callers = static function (string $needle, string $declaredIn) use ($root): array {
        $found = [];
        foreach (['agent', 'cli', 'recovery'] as $dir) {
            $walk = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS)
            );
            foreach ($walk as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($root) + 1);
                if ($relative === $declaredIn) {
                    continue;
                }
                if (str_contains((string) file_get_contents($file->getPathname()), $needle)) {
                    $found[] = $relative;
                }
            }
        }
        sort($found, SORT_STRING);
        return $found;
    };
    duo_check_same(
        ['agent/src/Adapter/AdapterScan.php'],
        $callers('::load_from_scan(', 'agent/src/Policy/Policy.php'),
        'Policy::load_from_scan() — the entry that accepts an already-resolved library — is called only by the scan '
        . 'handle, so no mutation entry point can be handed one'
    );
    duo_check_same(
        ['agent/src/Adapter/AdapterSources.php'],
        $callers('AdapterScan::open(', 'agent/src/Adapter/AdapterScan.php'),
        'and a handle is opened only by AdapterSources::survey(), the read-only inventory'
    );

    if (duo_check_failed() === 0) {
        exec('rm -rf ' . escapeshellarg($scaleRoot));
    }
    duo_check_summary('regress_adapter_survey_scale');
}

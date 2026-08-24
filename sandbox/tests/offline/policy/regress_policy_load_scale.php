<?php

/**
 * Offline scale contract: `Policy::load()` costs one manifest decode per PIN,
 * never one per manifest in the library.
 *
 * WHAT IS BEING PINNED, AND WHY IT IS COUNTED RATHER THAN TIMED
 * ------------------------------------------------------------
 * Until WP-1.2 `ManifestDispositions::load()` globbed `<manifests>/*.json` and
 * decoded every one of them before validating a single entry, so a one-pin
 * load of the shipped 16-manifest library performed 18 JSON decodes: the
 * registry, all sixteen manifests, then the pinned manifest again. The cost was
 * O(library) where the question is O(pins), and it grew with every adapter the
 * library gains — including the out-of-tree adapters this program opens the
 * door to. Coverage is now proved against the pinned shipped subset
 * (`ManifestDispositions::assert_covers()`), so the count is 1 + pins.
 *
 * The assertion is a DECODE COUNT, not a wall time. A timing threshold on a
 * shared developer machine is a flake generator and says nothing about the
 * mechanism; the count is exact, reproducible on any host, and names the defect
 * directly. It is collected by declaring `Duo\json_decode()` and
 * `Duo\file_get_contents()` in the measuring process before the agent is
 * required: `Canon::decode()`/`Canon::read_file()` call both UNQUALIFIED from
 * inside `namespace Duo`, so PHP resolves the namespaced function first and the
 * counter sits on the real product path with no seam added to the engine for a
 * test's benefit. The interception is proved live before it is trusted
 * (`instrument_alive` below); a counter that silently stopped intercepting
 * would report a beautiful, meaningless 0.
 *
 * NO `declare(strict_types=1)` HERE, DELIBERATELY. This file re-declares two
 * functions the whole `Duo` namespace calls. Under strict types every one of
 * those forwarded calls would be argument-checked against the global signature
 * with THIS file's strictness rather than the calling file's, which would make
 * the harness capable of changing the behaviour of the path it exists to
 * measure.
 *
 * WHY IT RE-ENTERS ITSELF
 * -----------------------
 * `memory_get_peak_usage()` is monotonic per process and building a 10,000-file
 * fixture allocates far more than the load under test, so a single-process
 * suite could only ever report its own fixture manufacture. Each measurement
 * therefore runs in a child `php` invocation of THIS file, selected by
 * DUO_POLICY_LOAD_SCALE_LIBRARY, which is set nowhere else. Re-entering the
 * same file (the idiom sandbox/tests/offline/cli/regress_doctor_command.php
 * uses) keeps one definition of the measurement instead of a second fixture
 * that could agree with a broken engine.
 */

namespace Duo {
    /**
     * The two seams the count is taken at. Both forward verbatim — same
     * arguments, same return value, same `json_last_error()` state for
     * `Canon::decode()`'s check on the line after its call.
     */
    function json_decode(...$args) {
        \DuoScaleProbe::$decodes++;
        return \json_decode(...$args);
    }

    function file_get_contents(...$args) {
        \DuoScaleProbe::$reads[] = (string) $args[0];
        return \file_get_contents(...$args);
    }
}

namespace {

    // WordPress supplies this in production; Policy::load()'s single-site gate
    // runs without bootstrapping WordPress. ABSPATH/WPINC stay undefined on
    // purpose: assert_supported_platform() returns early without them, so the
    // measured constant is the manifest path alone and does not silently
    // absorb the platform-boundary read.
    function is_multisite(): bool {
        return false;
    }
    if (!defined('DUO_SPEC_VERSION')) {
        define('DUO_SPEC_VERSION', 2);
    }

    final class DuoScaleProbe {
        public static int $decodes = 0;
        /** @var list<string> */
        public static array $reads = [];

        public static function reset(): void {
            self::$decodes = 0;
            self::$reads = [];
        }
    }

    $scaleLibrary = (string) (getenv('DUO_POLICY_LOAD_SCALE_LIBRARY') ?: '');

    // ------------------------------------------------------------------
    // Child mode: one measurement of one library, as one JSON line.
    // ------------------------------------------------------------------
    if ($scaleLibrary !== '') {
        require __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
        require __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
        require __DIR__ . '/../../../../agent/src/Kernel/Db.php';
        require __DIR__ . '/../../../../agent/src/Policy/ManifestDispositions.php';
        require __DIR__ . '/../../../../agent/src/Policy/Policy.php';

        $pins = explode(',', (string) getenv('DUO_POLICY_LOAD_SCALE_PINS'));
        putenv("DUO_MANIFESTS_DIR=$scaleLibrary");

        // Proof that the seam is live, taken through the same engine method the
        // measurement counts, before any number is reported.
        DuoScaleProbe::reset();
        Duo\Canon::decode('{"instrument":"alive"}');
        $instrumentAlive = DuoScaleProbe::$decodes === 1;

        DuoScaleProbe::reset();
        gc_collect_cycles();
        $usedBefore = memory_get_usage();
        $peakBefore = memory_get_peak_usage();
        $policy = Duo\Policy::load(null, $pins);
        $peakAfter = memory_get_peak_usage();
        $usedAfter = memory_get_usage();
        $libraryReads = array_values(array_filter(
            DuoScaleProbe::$reads,
            static fn(string $path): bool => str_starts_with($path, rtrim($scaleLibrary, '/') . '/')
        ));
        fwrite(STDOUT, (string) json_encode([
            'decodes' => DuoScaleProbe::$decodes,
            'instrument_alive' => $instrumentAlive,
            'library_reads' => array_map('basename', $libraryReads),
            'manifests' => count($policy->manifests),
            'peak_delta' => $peakAfter - $peakBefore,
            'retained_delta' => $usedAfter - $usedBefore,
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
     * path, and a per-pid name would leave a fresh 13 MB fixture behind on
     * every red run.
     */
    $scaleRoot = dirname(__DIR__, 3) . '/tmp/policy-load-scale';
    exec('rm -rf ' . escapeshellarg($scaleRoot));

    /** A minimal, valid, purely declarative manifest — the shape a pin resolves. */
    function scale_pinned_manifest(string $name): array {
        return [
            'name' => $name,
            'spec_version' => DUO_SPEC_VERSION,
            'option_autoload' => 'preserve',
            'options' => [str_replace('-', '_', $name) . '_layout' => ['class' => 'authored']],
        ];
    }

    /**
     * A filler manifest, padded to roughly the size of the smallest manifest
     * that actually ships (manifests/classic-editor.json is 1,378 bytes). The
     * padding is what makes the memory half of this suite mean anything: the
     * pre-WP-1.2 engine held every one of these decoded at once.
     */
    function scale_filler_manifest(string $name): array {
        return [
            'name' => $name,
            'notes' => str_repeat('synthetic library filler for the O(pinned) load contract. ', 16),
            'option_autoload' => 'preserve',
            'options' => [str_replace('-', '_', $name) . '_layout' => ['class' => 'authored']],
            'spec_version' => DUO_SPEC_VERSION,
        ];
    }

    /** The reviewed entry every synthetic manifest gets, so BOTH engines load this library. */
    function scale_disposition_entry(): array {
        return [
            'capabilities' => [
                'deletion_semantics' => ['supported' => [], 'unsupported' => ['nothing is deletable here']],
                'entity_sections' => [],
                'field_sections' => [],
                'lifecycle_phases' => [],
                'operations' => ['apply'],
            ],
            'default_authored_keyspaces' => [],
            'reason' => 'Synthetic fixture entry: this library exists to be counted, not to make a product claim.',
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
     * A fully COVERED library: every manifest has a reviewed entry, so the
     * pre-WP-1.2 engine and this one both LOAD it and the only difference
     * between them is the number this suite counts. An uncovered library would
     * separate the two by a refusal instead, which measures the semantic change
     * (regress_manifest_dispositions.php's group) rather than the cost.
     *
     * @param list<string> $pinned
     * @return array{dir:string,filler_bytes:int}
     */
    function scale_build_library(string $root, int $fillers, array $pinned): array {
        $dir = "$root/n$fillers/manifests";
        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new RuntimeException("cannot create the synthetic manifest library at $dir");
        }
        $entries = [];
        foreach ($pinned as $name) {
            file_put_contents("$dir/$name.json", (string) json_encode(scale_pinned_manifest($name)));
            $entries[$name] = scale_disposition_entry();
        }
        $fillerBytes = 0;
        for ($i = 0; $i < $fillers; $i++) {
            $name = sprintf('scale-filler-%06d', $i);
            $bytes = (string) json_encode(scale_filler_manifest($name));
            file_put_contents("$dir/$name.json", $bytes);
            $fillerBytes += strlen($bytes);
            $entries[$name] = scale_disposition_entry();
        }
        file_put_contents("$dir/dispositions.json", (string) json_encode([
            'format' => 'duo-manifest-dispositions/v1',
            'manifests' => $entries,
            'profiles' => new stdClass(),
        ]));
        return ['dir' => $dir, 'filler_bytes' => $fillerBytes];
    }

    /**
     * One measurement, in a child process of this same file.
     *
     * @param list<string> $pins
     * @return array{exit:int,output:string,measurement:?array<string,mixed>}
     */
    function scale_measure(string $library, array $pins): array {
        $command = 'DUO_POLICY_LOAD_SCALE_LIBRARY=' . escapeshellarg($library)
            . ' DUO_POLICY_LOAD_SCALE_PINS=' . escapeshellarg(implode(',', $pins))
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

    $sizes = [100, 1000, 10000];
    $libraries = [];
    foreach ($sizes as $n) {
        $libraries[$n] = scale_build_library($scaleRoot, $n, ['pinned-adapter', 'second-adapter']);
    }

    echo "\n== the decode count is 1 + pins, at every library size ==\n";
    // One registry decode plus one per pin. An exact number rather than an
    // upper bound: an engine that re-read a manifest it already held would be a
    // different defect with the same symptom, and a bound would pass it.
    $measurements = [];
    foreach ($sizes as $n) {
        $result = scale_measure($libraries[$n]['dir'], ['pinned-adapter']);
        $measurement = $result['measurement'];
        duo_check(
            $result['exit'] === 0 && is_array($measurement),
            "a one-pin load against a $n-manifest library succeeds"
        );
        if (!is_array($measurement)) {
            // Through duo_check_repr(): a child that died carries a PHP
            // diagnostic and a 10,000-name refusal list, and both a raw
            // "Fatal error: … in x.php on line N" at column 0 and the length
            // are things the offline diagnostics guard and the reader
            // respectively cannot use.
            duo_check_detail(duo_check_repr($result['output']));
            continue;
        }
        $measurements[$n] = $measurement;
        duo_check(
            $measurement['instrument_alive'] === true,
            "the decode counter is proved live before the $n-manifest measurement is trusted"
        );
        duo_check(
            $measurement['decodes'] === 2,
            "one pin against $n manifests costs exactly 2 decodes (registry + the pinned manifest); counted "
            . $measurement['decodes']
        );
        // The read list is ELIDED in the message on purpose: the pre-WP-1.2
        // engine opened every file in the directory, and printing 10,000
        // basenames turned one red assertion into 480 KB of gate output that
        // buried the other nineteen.
        $reads = $measurement['library_reads'];
        duo_check(
            $reads === ['dispositions.json', 'pinned-adapter.json'],
            "and exactly those two library files are opened at $n manifests: "
            . implode(', ', array_slice($reads, 0, 3))
            . (count($reads) > 3 ? ' … and ' . (count($reads) - 3) . ' more' : '')
        );
        duo_check(
            $measurement['manifests'] === 1,
            "the load resolved its one pin against the $n-manifest library"
        );
    }

    echo "\n== proportional to PINS, independent of library size ==\n";
    $twoPins = scale_measure($libraries[1000]['dir'], ['pinned-adapter', 'second-adapter']);
    duo_check(
        $twoPins['exit'] === 0
            && ($twoPins['measurement']['decodes'] ?? null) === 3
            && ($twoPins['measurement']['manifests'] ?? null) === 2,
        'a second pin costs exactly one more decode — the count tracks pins, which is the whole claim ('
        . var_export($twoPins['measurement']['decodes'] ?? $twoPins['output'], true) . ')'
    );
    duo_check(
        count($measurements) === count($sizes)
            && count(array_unique(array_column($measurements, 'decodes'))) === 1,
        'a library 100x larger costs the same: ' . implode(', ', array_map(
            static fn(int $n): string => "$n manifests => " . ($measurements[$n]['decodes'] ?? '?') . ' decodes',
            $sizes
        ))
    );

    echo "\n== manifest CONTENT is never materialised; the residual term is the directory scan ==\n";
    // The same 10,000 manifest files, with a registry that reviews only the two
    // adapters a pin can name — so the reviewed document is a constant and
    // anything that still grows with the library is something else.
    //
    // WHAT THE RESIDUAL ACTUALLY IS. Attributed by measuring each step of
    // Policy::load() alone against this same lean fixture at 100 / 1,000 /
    // 10,000 files, rather than inferred from the difference between two
    // registries:
    //
    //   ManifestDispositions::load()   9,584 bytes retained at EVERY size
    //   AdapterSources::discover()     13,248 -> 607,584 -> 7,174,240 peak
    //   Policy::load() (whole)         83,544 -> 645,112 -> 7,175,480 peak
    //
    // (A separate attribution harness, one step per child process, so its
    // whole-load figure sits a few percent under the number the assertion
    // below prints from this suite's own child — process overhead, not a
    // different mechanism. What is being read off it is the SHAPE: flat versus
    // linear.)
    //
    // The reviewed document is flat; discover() is the whole O(library) term.
    // It stats the directory and retains one origins row per file it finds
    // (name, source, path) so a pin can be resolved and a shadowed name
    // refused, which is a fact about every file in the directory by
    // construction — the property AdapterSources' header calls a broken
    // INSTALLATION rather than an unusable adapter. It is O(names), not
    // O(bytes): none of these files is opened or decoded, which is what the
    // read-list and decode-count assertions above pin and what the two
    // assertions below bound. Narrowing the scan is a separate question from
    // this work package, which is about DECODES.
    //
    // This variant is also the semantic half at scale: 10,000 unreviewed files
    // sitting in the library, and a covered pin still resolves. The pre-WP-1.2
    // engine could not load this directory at all — it refused the whole
    // library over manifests nobody pinned.
    file_put_contents($libraries[10000]['dir'] . '/dispositions.json', (string) json_encode([
        'format' => 'duo-manifest-dispositions/v1',
        'manifests' => [
            'pinned-adapter' => scale_disposition_entry(),
            'second-adapter' => scale_disposition_entry(),
        ],
        'profiles' => new stdClass(),
    ]));
    $lean = scale_measure($libraries[10000]['dir'], ['pinned-adapter']);
    $leanMeasurement = $lean['measurement'];
    duo_check(
        $lean['exit'] === 0 && is_array($leanMeasurement),
        '10,000 UNREVIEWED manifests beside the registry no longer refuse a covered pin'
    );
    if (!is_array($leanMeasurement)) {
        duo_check_detail(duo_check_repr($lean['output']));
        $leanMeasurement = ['decodes' => -1, 'peak_delta' => PHP_INT_MAX, 'retained_delta' => PHP_INT_MAX];
    }
    $unreadBytes = $libraries[10000]['filler_bytes'];
    duo_check(
        $leanMeasurement['decodes'] === 2,
        'and they are neither read nor refused: still 2 decodes, counted ' . $leanMeasurement['decodes']
    );
    // Stated against a measured quantity rather than an absolute ceiling that
    // would rot across PHP builds and hosts. Both bounds are the scan's, not
    // the registry's — see the attribution above — so they say what they can
    // honestly say: whatever the scan costs per NAME, it stays under what the
    // library holds in BYTES, because no manifest is ever opened.
    duo_check(
        $leanMeasurement['peak_delta'] < $unreadBytes,
        'the load peaks at ' . number_format($leanMeasurement['peak_delta'])
        . ' bytes — the adapter-source scan, one origins row per file — under the '
        . number_format($unreadBytes) . ' bytes of manifest content it never opened'
    );
    duo_check(
        $leanMeasurement['retained_delta'] < $unreadBytes,
        'and the loaded policy retains ' . number_format($leanMeasurement['retained_delta'])
        . ' bytes: those origins, plus ONE decoded manifest per pin — never the library\'s manifest content'
    );
    duo_check(
        $leanMeasurement['peak_delta'] * 4 < ($measurements[10000]['peak_delta'] ?? 0),
        'and the reviewed document is a SECOND per-entry term on top of that scan: over the identical 10,000 files a '
        . '10,001-entry registry costs ' . number_format($measurements[10000]['peak_delta'] ?? 0)
        . ' bytes against a 2-entry registry\'s ' . number_format($leanMeasurement['peak_delta'])
        . ', while the manifests themselves cost the same nothing in both'
    );

    if (duo_check_failed() === 0) {
        exec('rm -rf ' . escapeshellarg($scaleRoot));
    }
    duo_check_summary('regress_policy_load_scale');
}

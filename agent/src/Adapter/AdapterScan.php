<?php
namespace Duo;

require_once __DIR__ . '/AdapterSources.php';
require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Policy/AdapterLibrary.php';
require_once __DIR__ . '/../Policy/Policy.php';

/**
 * ONE resolved adapter library, threaded explicitly through one read-only
 * survey.
 *
 * THE DEFECT THIS REMOVES
 * -----------------------
 * `AdapterSources::survey()` judges each installed adapter through the REAL
 * loader — the posture grammar_verdict() argues for at length — and every one
 * of those `Policy::load()` calls re-ran the two whole-library resolutions the
 * row before it had already paid for: `AdapterSources::discover()` (which
 * globs the shipped library, walks `adapters/`, verifies every site
 * certificate and scans every active plugin bundle) and
 * `ManifestDispositions::load()` (which decodes the reviewed registry), plus
 * `assert_supported_platform()`'s read of `capabilities/platform.json`. The
 * work is a function of the LIBRARY, and it was being repeated once per ROW.
 *
 * Measured on this tree with one instrument, a synthetic library surveyed
 * with no repository, before and after —
 * `sandbox/tests/offline/adapter/regress_adapter_survey_scale.php` is the
 * standing proof of the right-hand column:
 *
 *   adapters   library globs   registry reads   wall        after
 *   125        126             126              100 ms      6 / 2 / 17 ms
 *   250        251             251              412 ms      6 / 2 / 35 ms
 *   500        501             501              1,524 ms     6 / 2 / 85 ms
 *
 * 3.7x per doubling before (O(N^2)), 2.1x after (linear), 18x faster at 500.
 * The six globs are the survey's own collect-mode scan, the one throw-mode
 * `discover()` behind this handle, and the two witness passes below; the two
 * registry reads are survey()'s own and this handle's.
 *
 * WHY A HANDLE AND NOT PROCESS STATE
 * ----------------------------------
 * A static memo inside `Policy` or `AdapterSources` would be the obvious
 * two-line version and is exactly the wrong shape: it would outlive the
 * survey, and the next `capture`, `apply` or `deploy` in the same process
 * would resolve its pins against an origins map taken before that command
 * started. A stale origins map decides WHICH adapter definition is in force —
 * the one fact AdapterSources exists to make unambiguous (see its header,
 * property 1) — so a memo that outlives its reader is worse than the O(N^2)
 * it fixes.
 *
 * This handle is therefore:
 *   - CREATED by `AdapterSources::survey()` and by nothing else,
 *   - PASSED down the call as an argument, never installed anywhere,
 *   - DROPPED when the survey returns, and
 *   - consumed through `Policy::load_from_scan()`, a read-only entry point
 *     `Policy::load()` does not route through, so every mutation entry point
 *     still discovers its own sources exactly as it always did.
 * `regress_adapter_survey_scale.php` asserts both call-site facts against the
 * shipped tree, so "never crosses a mutation entry point" is checked rather
 * than promised.
 *
 * THE WITNESS, AND WHY THERE ARE TWO OF THEM
 * ------------------------------------------
 * The memo keys on what the resolution is a FUNCTION of:
 * `AdapterSources::scan_dependencies()` names it — the directories the scan
 * enumerates, every file it can open, and the active-plugin set that decides
 * which bundles are in force.
 *
 *   - The SHAPE witness (`stat` of each anchor: the scanned directories, the
 *     two documents that gate the whole scan, the library path itself, and
 *     the activation set) is re-derived on EVERY reuse. It is O(anchors) —
 *     four `stat`s — because a per-reuse check over every FILE would be
 *     O(rows x files), which is the quadratic term this work package exists
 *     to delete.
 *   - The CONTENT witness (`hash_file` of every dependency) is re-derived once
 *     more, at `settle()`, before the survey returns its rows. It is exact,
 *     and it is what closes the in-place edit — a certificate or a reviewed
 *     disposition rewritten under the survey with no directory-entry churn.
 *
 * Either witness moving is a REFUSAL, never a silent re-scan: a survey that
 * re-resolved halfway through would report a library that existed at no single
 * instant, with the first rows judged against one and the last rows against
 * another, and nothing in the output saying which row got which. The refusal
 * is typed (`CommandRefusalException`, reason code
 * `adapter_library_moved`), so `--format=json` names it rather than
 * collapsing it to `<command>_failed`, and `grammar_verdict()` rethrows it
 * instead of folding it into one row's grammar message.
 *
 * WHAT IT DELIBERATELY DOES NOT COVER. The survey's own collect-mode scan is
 * a single snapshot taken before the row loop, both before and after this
 * change; this handle memoizes the LOADER's resolutions, and its witness is
 * therefore the loader's dependency set. A `site.duo.json` or `adapters/`
 * change is in that set (`discover()` reads both), so the two are the same
 * set in practice for a survey with a repository.
 */
final class AdapterScan {
    /**
     * The one refusal this handle mints. A reason code rather than a bare
     * RuntimeException because the whole survey dies on it: `Cli::
     * halt_json_failure()` publishes a CommandRefusalException's payload and
     * redacts everything else (agent/src/Command/Cli.php:60-64, :83-98).
     */
    public const REFUSAL_MOVED = 'adapter_library_moved';

    private ?string $repo;
    private AdapterLibrary $adapterLibrary;
    /** @var ?array{dir:string, adapter_library:AdapterLibrary, dispositions:?ManifestDispositions, sources:AdapterSources} */
    private ?array $library = null;
    /**
     * A resolution that REFUSED, replayed per row instead of re-attempted.
     *
     * Byte-identical to today: `Policy::load()` resolved the same library for
     * every row, so a broken installation already produced the same sentence
     * on all of them. Replaying the object keeps that true while paying for
     * the scan once — and the witness above is what makes the replay honest,
     * since a repaired library moves it and is refused rather than reported
     * as still broken.
     */
    private ?\Throwable $failure = null;
    private bool $resolved = false;
    /** @var array<string,string> stat stamps, re-derived at every reuse */
    private array $shape = [];
    /** @var array<string,string> content digests, re-derived once at settle() */
    private array $content = [];

    private function __construct(?string $repo, AdapterLibrary $adapterLibrary) {
        $this->repo = $repo;
        $this->adapterLibrary = $adapterLibrary;
    }

    /**
     * Open a handle for the repository the survey's loads will be judged
     * against — `null` when a source refusal already forced every verdict to
     * be taken without the site half (grammar_verdict()).
     *
     * Nothing is read here. A survey whose every row is answered without a
     * load (a fully blocked site source) must pay for no scan at all, which is
     * also what keeps this constructor safe to call unconditionally.
     */
    public static function open(?string $repo): self {
        return new self($repo, Policy::shipped_adapter_library());
    }

    /** Open a survey handle over exactly this closed library inventory. */
    public static function open_library(AdapterLibrary $library, ?string $repo): self {
        return new self($repo, $library);
    }

    /**
     * One pinned adapter, loaded against the memoized library.
     *
     * The order is the contract: prove the memo still describes the disk,
     * then replay a refused resolution, then load. A row can never be answered
     * from a resolution the witness no longer vouches for.
     */
    public function load(string $name): Policy {
        $this->resolve();
        $this->assert_unmoved();
        if ($this->failure !== null) {
            throw $this->failure;
        }
        if ($this->library === null) {
            // Unreachable: resolve() sets exactly one of the two. Stated as a
            // refusal rather than left to a `(array) null` cast, which would
            // hand Policy an empty library and produce a confusing message
            // about a manifest directory instead of about this state.
            throw new \RuntimeException(
                'duo: the adapter scan holds neither a resolved library nor the refusal that replaced it'
            );
        }
        return Policy::load_from_scan($this->library, $this->repo, [$name]);
    }

    /**
     * The exact check, once, before the survey publishes its rows.
     *
     * The shape witness above is cheap enough to re-derive per row and blind
     * to an in-place rewrite that churns no directory entry; this is the half
     * that sees one. It runs at the END because that is the last moment the
     * whole answer is still recallable: a difference here refuses the survey
     * outright rather than returning rows that describe two different
     * libraries.
     *
     * A no-op when nothing was ever loaded — there is no memo to defend.
     */
    public function settle(): void {
        if (!$this->resolved) {
            return;
        }
        $this->assert_unmoved();
        $dependencies = $this->dependencies();
        $content = self::content_witness($dependencies['files']);
        if ($content !== $this->content) {
            throw self::moved($this->content, $content, 'file content');
        }
    }

    /**
     * The one resolution, taken once.
     *
     * The witness is captured BEFORE the resolution reads anything, so a
     * mutation that straddles the resolution itself is caught by the first
     * `assert_unmoved()` rather than baked in.
     */
    private function resolve(): void {
        if ($this->resolved) {
            return;
        }
        $this->resolved = true;
        $dependencies = $this->dependencies();
        $this->shape = self::shape_witness($dependencies, $this->adapterLibrary);
        $this->content = self::content_witness($dependencies['files']);
        try {
            $this->library = Policy::resolve_library($this->repo, $this->adapterLibrary);
        } catch (\Throwable $t) {
            $this->failure = $t;
        }
    }

    private function assert_unmoved(): void {
        $shape = self::shape_witness(
            $this->anchors(),
            $this->adapterLibrary
        );
        if ($shape !== $this->shape) {
            throw self::moved($this->shape, $shape, 'directory or activation');
        }
    }

    /**
     * O(anchors) — a handful of `stat`s and the activation list — because this
     * one is re-derived per row. It reads scan_ANCHORS() and never
     * scan_dependencies(): the latter globs each source, and one glob per row
     * over a library is the O(rows x files) term this handle exists to delete.
     *
     * `clearstatcache()` first: PHP caches `stat` per path for the request, so
     * without it this check would answer from the same cached inode the
     * resolution saw and could never see a mid-process move.
     *
     * The selected library root is an entry of its own, so replacing the
     * embedded projection moves the witness even when its new tree has the
     * same package names.
     *
     * @param array{anchors:list<string>, plugins:list<string>} $anchored a full
     *        dependency set is a superset of this and is accepted as one
     * @return array<string,string>
     */
    private static function shape_witness(array $anchored, AdapterLibrary $adapterLibrary): array {
        clearstatcache(true);
        $witness = [
            'library' => $adapterLibrary->root(),
            'plugins' => implode(',', $anchored['plugins']),
        ];
        foreach ($anchored['anchors'] as $anchor) {
            $witness[$anchor] = self::stamp($anchor);
        }
        return $witness;
    }

    /** @return array{anchors:list<string>, files:list<string>, plugins:list<string>} */
    private function dependencies(): array {
        return AdapterSources::scan_dependencies_library($this->adapterLibrary, $this->repo);
    }

    /** @return array{anchors:list<string>, plugins:list<string>} */
    private function anchors(): array {
        return AdapterSources::scan_anchors_library($this->adapterLibrary, $this->repo);
    }

    /**
     * O(files) in bytes, and therefore taken exactly twice per survey.
     *
     * A digest and not a stat: `size:mtime` cannot see a certificate or a
     * reviewed disposition rewritten to the same length inside one second,
     * and this witness is the reason the survey may answer from a memo at all.
     *
     * @param list<string> $files
     * @return array<string,string>
     */
    private static function content_witness(array $files): array {
        clearstatcache(true);
        $witness = [];
        foreach ($files as $file) {
            // GUARDED, not suppressed. `@` still calls a registered error
            // handler, and Query Monitor, Sentry and Whoops all install one
            // that throws — the channel regress_plugin_adapter_source.php
            // exists to keep closed, since a warning raised inside the scan
            // takes discover() and survey() down together. So an unreadable
            // or absent file is ASKED about rather than tripped over.
            // `unreadable` is a witness VALUE, not an excuse: a file that
            // stops being readable under the survey moves the witness and
            // refuses, which is the direction this whole class fails in.
            $digest = is_readable($file) ? @hash_file('sha256', $file) : false;
            $witness[$file] = is_string($digest) ? $digest : 'unreadable';
        }
        return $witness;
    }

    /** Inode, size, and both timestamps: entry churn in a directory moves all four. */
    private static function stamp(string $path): string {
        // file_exists() before stat(), for content_witness()'s reason: stat()
        // on a path that is not there is a WARNING, and a warning inside the
        // scan is fatal on any site running a throwing error handler.
        $stat = file_exists($path) ? @stat($path) : false;
        if (!is_array($stat)) {
            return 'absent';
        }
        return implode(':', [
            (string) ($stat['ino'] ?? ''),
            (string) ($stat['size'] ?? ''),
            (string) ($stat['mtime'] ?? ''),
            (string) ($stat['ctime'] ?? ''),
            (string) ($stat['mode'] ?? ''),
        ]);
    }

    /**
     * The refusal, naming what moved.
     *
     * The public half carries no path: every one of these is absolute, and
     * `CommandRefusalException::containsSensitivePublicDetail()` redacts a
     * whole payload that carries a `/Users/` or `/home/` shape
     * (agent/src/Kernel/CommandRefusal.php:199), which would replace the
     * reason code's guidance with the generic redaction notice. The paths go
     * in the operator sentence, where every other refusal in this area puts
     * them.
     *
     * @param array<string,string> $before
     * @param array<string,string> $after
     */
    private static function moved(array $before, array $after, string $what): CommandRefusalException {
        $changed = [];
        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changed[] = $key;
            }
        }
        foreach ($before as $key => $value) {
            if (!array_key_exists($key, $after)) {
                $changed[] = $key;
            }
        }
        sort($changed, SORT_STRING);
        $named = array_slice($changed, 0, 3);
        return new CommandRefusalException(
            self::REFUSAL_MOVED,
            'the adapter library changed while this survey was reading it, so the survey refused rather than '
            . 'answer half its rows against the library before the change and half against the library after it',
            'let the manifest library, the repository adapter source and the active plugin set settle, then re-run '
            . 'the survey',
            [],
            'duo: the adapter library moved mid-survey (' . count($changed) . ' ' . $what . ' change(s): '
            . implode(', ', $named) . (count($changed) > count($named) ? ', …' : '') . ') — the resolved scan was '
            . 'not reused for another row, and no row was answered from it'
        );
    }
}

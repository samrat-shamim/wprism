<?php
declare(strict_types=1);

namespace Duo;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/Pending.php';
require_once __DIR__ . '/../Repository/StateTreeWalker.php';

/**
 * Every input `Lint::scan_tree()` reads from the ENVIRONMENT rather than from
 * the state tree, as one recordable, replayable value (`duo-lint-environment/v1`).
 *
 * WP-2.4's premise was that `scan_tree()` is pure over (stateDir, Policy)
 * "except one `get_option('home')` read — the state tree records the value".
 * Measured against this tree, that is wrong in three ways, and the correction
 * is why this class exists rather than a one-line `?string $home` parameter:
 *
 *   1. `get_option('home')` — the one the premise names, and the premise is
 *      wrong about where it comes from: the state tree does NOT record it.
 *      `manifests/core.json` declares both `home` and `siteurl`
 *      `"class": "env"`, and an env-class option is excluded from capture
 *      whole. A host process has nowhere to read it from except a recording.
 *   2. `Pending::resolve_id()` — NINE call sites in `Lint::scan_tree()`'s own
 *      body before this class existed (`git show <pre-WP-2.4>:Lint.php |
 *      grep -c Pending::resolve_id` is 11, two of which are prose), plus the
 *      default resolver in all five scanner collaborators. Each is a live
 *      `SELECT` against `wp_posts`/`wp_terms` (`Pending::resolve_id()`). A
 *      `bare_id` finding EXISTS only because a number resolved on this
 *      environment, so this is not a decoration on the scan, it is half its
 *      verdict.
 *   3. `parse_blocks()`, `get_shortcode_regex()`, `shortcode_parse_atts()` —
 *      WordPress's own parsing primitives (`Lint::scan_post_file()` and
 *      `scan_widget_blocks()`; `ShortcodeReferenceScanner::scan()`), which a
 *      host process does not have. `duo adapter-draft` already states this
 *      boundary for itself (`cli/src/Adapter/AdapterDraft.php`,
 *      propose_block_shortcode_paths(): "unavailable in this pure process")
 *      and scans block markup by delimiter regex instead.
 *
 * (1) and (2) are RECORDED: `live()` answers them from WordPress and memoizes
 * every answer, so `document()` is an exact, complete transcript of what one
 * scan consumed over one state tree, and `recorded()` replays it into the
 * identical code path. That is what makes the host verb's findings byte-
 * identical to the live verb's BY CONSTRUCTION rather than by hope — there is
 * one `scan_tree()`, and the only thing that differs between the two callers
 * is which of these two objects it is handed.
 *
 * (3) cannot be recorded: the parse is a function of state-tree bytes this
 * object never sees, and recording WordPress's parse of every post body would
 * put a copy of the whole tree inside the transcript. It is therefore
 * DECLARED — `parses_blocks()` / `parses_shortcodes()` answer from
 * `function_exists()` — and a scan that skips a class because the process
 * cannot perform it records the skip through `defer()`. A caller that prints
 * those deferrals cannot let silence read as "the tree is clean", the same
 * reason `duo manifest-validate` emits its deferred list on every run,
 * passing or failing (`cli/src/Adapter/ManifestValidate.php:26-31`).
 *
 * Two refusals keep a replay from being quietly SMALLER than the scan it
 * replays, which is the exact false-green shape this review surface exists to
 * prevent: a recorded environment refuses an id it holds no answer for rather
 * than resolving it to null, and `assert_state_tree()` refuses a tree whose
 * bytes are not the ones the transcript was recorded over.
 */
final class LintEnvironment {
    public const FORMAT = 'duo-lint-environment/v1';

    /** The `duo-adapter-probe/v1` envelope this class reads column types out of. */
    public const PROBE_FORMAT = 'duo-adapter-probe/v1';

    /**
     * Live MySQL types whose value space is bounded tightly enough that a
     * `bare_id` collision on the column can be PROPOSED as a `lint_ok`
     * exemption, each with the premise the proposal carries.
     *
     * `bit(1)` is a structural argument; `tinyint(1)` is a conventional one and
     * says so, because MySQL's display width constrains nothing — a
     * `TINYINT(1)` column really can hold 42. The difference is stated in the
     * finding rather than smoothed over: a proposal whose premise a reviewer
     * cannot check is a silencer with extra steps.
     *
     * `enum` is deliberately ABSENT even though an `ENUM('0','1')` column is
     * exactly as bounded as `BIT(1)`. `AdapterProbe::normalized_type()`
     * reduces every enum/set to its bare base word because the member list
     * carries SITE VALUES inside the MySQL type string
     * (`agent/src/Adapter/AdapterProbe.php:435-447`, boundary 2), so the
     * evidence reaching this class cannot distinguish `enum('0','1')` from
     * `enum('draft','publish','42')`. Proposing on the base word alone would
     * invent the premise instead of carrying it.
     *
     * @var array<string,string> normalized type => the premise, verbatim
     */
    private const BOOLEAN_DOMAIN = [
        'bit(1)' => 'BIT(1) holds one bit: its value space is {0,1} and structurally excludes every entity id',
        'tinyint(1)' => "TINYINT(1) is the conventional boolean width. MySQL's display width does NOT constrain "
            . 'the range (the column can hold -128..127), so this premise is convention, not structure — confirm '
            . 'the column is a flag before ratifying',
    ];

    /** Home URL, already `untrailingslashit()`-normalized. */
    private string $home;

    /** @var array<int,?array{kind:string,id:int,title:string,post_type:string}> */
    private array $entities = [];

    /** @var array<string,array<string,string>> table => column => live MySQL type */
    private array $columnTypes;

    /** Provenance of `columnTypes`, or null when no probe was supplied. */
    private ?string $probeHash;

    /** True for `live()`: resolve against WordPress and record; false: replay only. */
    private bool $live;

    /** @var array{blocks:bool,shortcodes:bool} what the recording process could parse */
    private array $scanned;

    /** Digest of the state tree a transcript was recorded over; null while recording. */
    private ?string $stateHash;

    /** @var list<string> scan classes this process could not perform, first-seen order */
    private array $deferrals = [];

    /**
     * @param array<string,array<string,string>> $columnTypes
     * @param array<int,?array> $entities
     * @param array{blocks:bool,shortcodes:bool} $scanned
     */
    private function __construct(
        string $home,
        array $columnTypes,
        ?string $probeHash,
        bool $live,
        array $entities,
        array $scanned,
        ?string $stateHash
    ) {
        $this->home = $home;
        $this->columnTypes = $columnTypes;
        $this->probeHash = $probeHash;
        $this->live = $live;
        $this->entities = $entities;
        $this->scanned = $scanned;
        $this->stateHash = $stateHash;
    }

    /**
     * The live environment: WordPress answers, and every answer is kept.
     *
     * `$probe` is an optional `duo-adapter-probe/v1` document
     * (`wp duo adapter-probe --format=json`). It is read on BOTH sides — live
     * and host — rather than having the live side read `SHOW COLUMNS` itself,
     * because a live-only type source would make the two verbs' findings
     * differ for a reason that has nothing to do with the state tree.
     *
     * @param array<string,mixed>|null $probe
     */
    public static function live(?array $probe = null): self {
        return new self(
            untrailingslashit((string) get_option('home')),
            $probe === null ? [] : self::column_types_from_probe($probe),
            $probe === null ? null : (string) $probe['probe_hash'],
            true,
            [],
            [
                'blocks' => function_exists('parse_blocks'),
                'shortcodes' => function_exists('get_shortcode_regex') && function_exists('shortcode_parse_atts'),
            ],
            null
        );
    }

    /**
     * Replay a recorded environment. Validated field by field: a document this
     * class cannot fully understand is refused, never partially honoured — a
     * half-read transcript resolves fewer ids and reports fewer findings.
     *
     * @param array<string,mixed> $document
     */
    public static function recorded(array $document): self {
        if (($document['format'] ?? null) !== self::FORMAT) {
            throw new \RuntimeException(
                'duo: lint environment must be a ' . self::FORMAT . ' document (wp duo lint --emit-environment=<file>)'
            );
        }
        if (!is_string($document['home'] ?? null)) {
            throw new \RuntimeException('duo: lint environment has no recorded home URL');
        }
        $entities = [];
        foreach ((array) ($document['entities'] ?? []) as $row) {
            if (!is_array($row) || !is_int($row['id'] ?? null) || (int) $row['id'] <= 0
                || !array_key_exists('resolved', $row)) {
                throw new \RuntimeException(
                    'duo: lint environment entity rows must be {id:<positive int>, resolved:<match|null>}'
                );
            }
            $resolved = $row['resolved'];
            if ($resolved !== null) {
                if (!is_array($resolved)) {
                    throw new \RuntimeException("duo: lint environment entity {$row['id']} has a non-object resolution");
                }
                $fields = array_keys($resolved);
                sort($fields, SORT_STRING);
                if ($fields !== ['id', 'kind', 'post_type', 'title']) {
                    throw new \RuntimeException(
                        "duo: lint environment entity {$row['id']} carries a resolution outside the "
                        . 'Pending::resolve_id() shape (kind, id, title, post_type)'
                    );
                }
                // Rebuilt in Pending::resolve_id()'s own key ORDER, not the
                // transcript's. The document is canonical JSON, so its keys are
                // sorted; a finding's `matches` object is `json_encode()`d in
                // insertion order by `lint --format=json`. Replaying the sorted
                // shape verbatim made the host verb's bytes differ from the live
                // verb's in exactly one place — inside `matches` — which is the
                // whole claim, so the reconstruction is explicit here rather
                // than left to whatever order the reader happened to produce.
                $resolved = [
                    'kind' => (string) $resolved['kind'],
                    'id' => (int) $resolved['id'],
                    'title' => (string) $resolved['title'],
                    'post_type' => (string) $resolved['post_type'],
                ];
            }
            $entities[(int) $row['id']] = $resolved;
        }
        $columnTypes = [];
        foreach ((array) ($document['column_types'] ?? []) as $table => $columns) {
            if (!is_string($table) || !is_array($columns)) {
                throw new \RuntimeException('duo: lint environment column_types must be table => column => type');
            }
            foreach ($columns as $column => $type) {
                if (!is_string($column) || !is_string($type)) {
                    throw new \RuntimeException(
                        "duo: lint environment column_types['$table'] must map column names to live type strings"
                    );
                }
                $columnTypes[$table][$column] = $type;
            }
        }
        $scanned = (array) ($document['scanned'] ?? []);
        if (!is_bool($scanned['blocks'] ?? null) || !is_bool($scanned['shortcodes'] ?? null)) {
            throw new \RuntimeException(
                'duo: lint environment must record scanned.blocks and scanned.shortcodes — a replay that cannot '
                . 'say whether the recording process parsed blocks cannot say what its finding set omits'
            );
        }
        $probeHash = $document['probe_hash'] ?? null;
        if ($probeHash !== null && !is_string($probeHash)) {
            throw new \RuntimeException('duo: lint environment probe_hash must be a string or null');
        }
        $stateHash = $document['state_hash'] ?? null;
        if (!is_string($stateHash) || preg_match('/^sha256:[0-9a-f]{64}$/D', $stateHash) !== 1) {
            throw new \RuntimeException(
                'duo: lint environment has no canonical state_hash, so nothing could check that this transcript '
                . 'describes the tree being scanned'
            );
        }
        return new self(
            (string) $document['home'],
            $columnTypes,
            $probeHash,
            false,
            $entities,
            ['blocks' => (bool) $scanned['blocks'], 'shortcodes' => (bool) $scanned['shortcodes']],
            $stateHash
        );
    }

    public function home(): string {
        return $this->home;
    }

    /**
     * The one id lookup, live or replayed.
     *
     * Non-positive ids short-circuit WITHOUT being recorded, exactly as
     * `Pending::resolve_id()` returns null for them without issuing a query
     * (`Pending.php:361-363`) — a transcript of answers nothing asked for
     * would make the document a function of the scanner's loop shape rather
     * than of the environment.
     *
     * @return ?array{kind:string,id:int,title:string,post_type:string}
     */
    public function resolve_id(int $id): ?array {
        if ($id <= 0) {
            return null;
        }
        if (array_key_exists($id, $this->entities)) {
            return $this->entities[$id];
        }
        if (!$this->live) {
            throw new \RuntimeException(
                "duo: lint environment has no recorded answer for id $id — this transcript was recorded under a "
                . 'different policy, so the scan is asking a question the recording never asked. Re-run '
                . '`wp duo lint --repo=<repo> --emit-environment=<file>` against the same tree; guessing null here '
                . 'would report FEWER findings than the live scan'
            );
        }
        return $this->entities[$id] = Pending::resolve_id($id);
    }

    /** @return callable(int):?array the resolver the scanner collaborators already accept */
    public function resolver(): callable {
        return fn(int $id): ?array => $this->resolve_id($id);
    }

    /**
     * The live MySQL type of one custom-table column, when a probe supplied it.
     * Null means "not asked, or the probe does not carry that table" — never a
     * default, because a guessed type is exactly the premise a proposed
     * exemption must not invent.
     */
    public function column_type(string $table, string $column): ?string {
        $type = $this->columnTypes[$table][$column] ?? null;
        return is_string($type) ? $type : null;
    }

    /**
     * The premise for proposing `lint_ok` on a column of this live type, or
     * null when the type is not evidence for one. See BOOLEAN_DOMAIN.
     */
    public static function boolean_domain_premise(?string $type): ?string {
        if ($type === null) {
            return null;
        }
        return self::BOOLEAN_DOMAIN[strtolower(trim($type))] ?? null;
    }

    /**
     * Whether this process can perform the block / shortcode scan classes.
     *
     * Two conditions, and the second is the non-obvious one: a replay performs
     * a class only if the RECORDING performed it too. Without that, a process
     * that happens to have `parse_blocks()` would report findings the
     * transcript's own scan never produced, and the two verbs' outputs would
     * differ in the one direction the byte-identity claim cannot survive.
     */
    public function parses_blocks(): bool {
        return function_exists('parse_blocks') && $this->scanned['blocks'];
    }

    public function parses_shortcodes(): bool {
        return function_exists('get_shortcode_regex')
            && function_exists('shortcode_parse_atts')
            && $this->scanned['shortcodes'];
    }

    /** @return array{blocks:bool,shortcodes:bool} */
    public function scanned(): array {
        return $this->scanned;
    }

    /** Record a scan class this process could not perform. Idempotent, first-seen order. */
    public function defer(string $what): void {
        if (!in_array($what, $this->deferrals, true)) {
            $this->deferrals[] = $what;
        }
    }

    /** @return list<string> */
    public function deferrals(): array {
        return $this->deferrals;
    }

    /**
     * Refuse a state tree whose bytes are not the ones this transcript was
     * recorded over.
     *
     * Without this the replay's failure mode is a mid-scan "no recorded answer
     * for id N", which reads as a policy problem, or — worse, when the tree
     * merely SHRANK — no refusal at all and a strictly smaller finding set. A
     * transcript is evidence about one tree; saying which one costs 64 hex
     * characters.
     */
    public function assert_state_tree(string $stateDir): void {
        if ($this->stateHash === null) {
            return; // recording: the tree being scanned IS the tree being described
        }
        $actual = self::state_hash($stateDir);
        if (!hash_equals($this->stateHash, $actual)) {
            throw new \RuntimeException(
                'duo: this lint environment was recorded over a different state tree (' . $this->stateHash
                . ' recorded, ' . $actual . ' on disk). Findings replayed against other bytes are not the findings '
                . 'the live scan produced — re-run `wp duo lint --repo=<repo> --emit-environment=<file>`'
            );
        }
    }

    /**
     * The tree digest: canonical bytes of {state-relative path => sha256}, over
     * exactly the files `StateTreeWalker::files()` hands the scanner, so a file
     * lint never reads cannot move it and every file lint does read must.
     */
    public static function state_hash(string $stateDir): string {
        $stateDir = rtrim($stateDir, '/');
        $files = [];
        foreach (StateTreeWalker::files($stateDir) as $file) {
            $digest = hash_file('sha256', $stateDir . '/' . $file['path']);
            if ($digest === false) {
                throw new \RuntimeException('duo: lint could not read ' . $file['path'] . ' to digest the state tree');
            }
            $files[$file['path']] = $digest;
        }
        ksort($files, SORT_STRING);
        return 'sha256:' . hash('sha256', Canon::encode(['files' => $files]));
    }

    /**
     * The transcript. Entities are a SORTED LIST of {id, resolved} rows rather
     * than an id-keyed object: PHP turns numeric string keys back into ints on
     * decode, so an object would round-trip through two different shapes and
     * the document would stop being a stable comparison basis.
     *
     * @return array<string,mixed>
     */
    public function document(string $stateDir): array {
        $ids = array_keys($this->entities);
        sort($ids, SORT_NUMERIC);
        $entities = [];
        foreach ($ids as $id) {
            $entities[] = ['id' => $id, 'resolved' => $this->entities[$id]];
        }
        return [
            'column_types' => $this->columnTypes,
            'entities' => $entities,
            'format' => self::FORMAT,
            'home' => $this->home,
            'probe_hash' => $this->probeHash,
            'scanned' => $this->scanned,
            'state_hash' => $this->stateHash ?? self::state_hash($stateDir),
        ];
    }

    /**
     * `tables.<t>.columns.<c>.type` out of a `duo-adapter-probe/v1` document.
     *
     * The self-hash is re-checked here against the same basis
     * `AdapterProbe::hash_document()` defines — canonical bytes of the document
     * with `probe_hash` removed. It is recomputed rather than delegated because
     * `agent/src/Review` sits on the `engine` rung and `agent/src/Adapter` on
     * `adapter` (tools/modules.json), so requiring the emitter here would be an
     * upward edge; `AdapterDraft::read_probe()` re-checks the same basis from
     * its own side for the same reason. `sandbox/tests/offline/policy/
     * regress_lint_type_exemptions.php` pins the two against one document.
     *
     * Nothing else in the probe is read. A probe carries no `class`, no
     * `identity` and no capability word by construction (`AdapterProbe`'s
     * boundary 1), and a lint proposal must not be able to reach for one.
     *
     * @param array<string,mixed> $probe
     * @return array<string,array<string,string>>
     */
    public static function column_types_from_probe(array $probe): array {
        if (($probe['format'] ?? null) !== self::PROBE_FORMAT) {
            throw new \RuntimeException(
                'duo: lint --evidence expects a ' . self::PROBE_FORMAT
                . ' document (`wp duo adapter-probe --format=json`)'
            );
        }
        if (($probe['authority'] ?? null) !== false) {
            throw new \RuntimeException(
                'duo: lint --evidence document must declare authority:false; a probe reports live facts and '
                . 'ratifies nothing'
            );
        }
        $hash = $probe['probe_hash'] ?? null;
        if (!is_string($hash) || preg_match('/^sha256:[0-9a-f]{64}$/D', $hash) !== 1) {
            throw new \RuntimeException('duo: lint --evidence document has no canonical probe_hash');
        }
        $basis = $probe;
        unset($basis['probe_hash']);
        if (!hash_equals($hash, 'sha256:' . hash('sha256', Canon::encode($basis)))) {
            throw new \RuntimeException(
                'duo: lint --evidence probe_hash does not describe the document; re-run `wp duo adapter-probe` '
                . 'rather than editing a probe by hand'
            );
        }
        $out = [];
        foreach ((array) ($probe['tables'] ?? []) as $table => $facts) {
            if (!is_string($table) || !is_array($facts) || ($facts['present'] ?? null) !== true) {
                continue; // present:false is a real answer about a table this target lacks, and carries no columns
            }
            foreach ((array) ($facts['columns'] ?? []) as $column => $shape) {
                if (!is_string($column) || !is_array($shape) || !is_string($shape['type'] ?? null)) {
                    continue;
                }
                $out[$table][$column] = $shape['type'];
            }
        }
        return $out;
    }
}

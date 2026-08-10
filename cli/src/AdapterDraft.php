<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

use Duo\Canon;
use Duo\NativeActions;
use Duo\Policy;
use Duo\Secrets;

/**
 * `duo adapter-draft` — the safe adapter DRAFT generator (DUO-3325, offline slice).
 *
 * `duo policy-to-manifest` promotes a site's ALREADY-classified policy rules into
 * a manifest and emits ONLY facts; it is human-only by a reviewed decision and has
 * no proposers. This verb is its generator sibling: it reuses that exact facts core
 * (`Policy::export_manifest()`, VERBATIM, so facts never diverge from what
 * policy-to-manifest promotes), then adds OFFLINE proposers that observe a site
 * repo's captured `state/**` and emit grammar-shaped CANDIDATES the author can
 * ratify by hand. It writes nothing live and promotes nothing automatically.
 *
 * Like `duo manifest-validate` this is a HOST verb, not a `wp duo` subcommand: it
 * boots the pure engine (Canon/OptionState/ManifestDispositions/CapabilityRegistry/
 * Policy, plus Secrets for the value screen) WordPress-free, with no DB and no
 * docker, which is exactly why the whole thing is offline-buildable. Every fact that
 * genuinely needs a live target (a column's SQL type, its real PRIMARY KEY, whether
 * an integer resolves to a live entity, natural-key uniqueness across the keyspace)
 * stays a `proposal` carrying a `question` that names the deferral. `--evidence=<file>`
 * is the single seam a later, live slice attaches through; in THIS slice the flag is
 * accepted and then explicitly ignored, with a note, so nothing offline pretends to
 * have seen a live target.
 *
 * TWO code-derived constraints govern the envelope, both correctness-critical:
 *
 *   A. `AdapterSources::assert_out_of_tree_contract()` refuses a site/plugin-installed
 *      manifest that names a top-level `evidence`/`disposition`/`certification`/
 *      `signature`/… key. A draft's destiny is to become a site adapter, so proposals
 *      and their per-candidate `evidence`/`questions` nest under ONE neutral,
 *      non-reserved top-level key: `_draft`. (`_draft` is not on that reserved list.)
 *
 *   B. `Policy::validate_ref_kinds()` → `collect_ref_kind_claims()` blind-walks the
 *      WHOLE manifest, skipping only top-level `notes`/`actions`/`providers`/
 *      `lifecycle_effects`, and collects the literal keys `ref`/`refs`/`json_refs`/
 *      `key_refs` at ANY depth against the closed ref-kind vocabulary. `_draft` IS
 *      walked. So a proposed table/reference carrying a live `refs:[{kind:…}]` whose
 *      kind no live `tables` section declares would trip the closed-vocabulary refusal
 *      and FAIL `manifest-validate` — breaking both inertness and the acceptance.
 *      DEFENSE: inside `_draft`, every candidate FRAGMENT has those four trigger keys
 *      RENAMED (`ref`→`proposed_ref`, …) by rename_triggers(), so the blind walk can
 *      never collect an inert proposal, and NO proposal is mirrored under a live
 *      top-level `tables`/`block_attrs`/`shortcode_attrs`/`actions`/`providers`/
 *      `deletions` key. `--check-proposals` restores the names into a throwaway
 *      in-memory manifest before running the REAL validators. This makes the sidecar
 *      inert against every validator by construction — the blind walk is the only
 *      arbitrary-depth reader; every other validator reads named sections only.
 *
 * Guardrails are STRUCTURAL, enforced by every proposer:
 *   - proposals only ever land under `_draft` (inert); Policy::load() never applies one.
 *   - executable semantics NEVER become code: a generated/derived-looking surface is
 *     emitted as a provider capability DECLARATION or a native-action name from the
 *     closed vocabulary, plus guidance — never a PHP interpreter/regenerator stub.
 *   - deletion authority is never inferred: a deletion candidate spells out the STATIC
 *     required-cascade set (mirroring Deletion::capability()'s per-kind map) as a
 *     proposal with a question, never an applied capability.
 *   - secrets/PII are never captured: Secrets::hard_match()/suspicious() screen EVERY
 *     candidate value, and on a hit the candidate is DROPPED to a question that names
 *     the label only — the value is never authored into the draft.
 */
final class AdapterDraft {
    /** Envelope of the draft artifact (nested under the neutral `_draft` key). */
    public const FORMAT = 'duo-adapter-draft/v1';

    /** Envelope of the `--check-proposals` report. */
    public const CHECK_FORMAT = 'duo-adapter-draft-check/v1';

    /**
     * Constraint B: the four blind-walk trigger keys and their inert draft spellings.
     * A candidate fragment is stored with the RIGHT column; `--check-proposals` maps
     * back with the flip of this table before lifting into the throwaway manifest.
     */
    private const RENAME = [
        'ref' => 'proposed_ref',
        'refs' => 'proposed_refs',
        'json_refs' => 'proposed_json_refs',
        'key_refs' => 'proposed_key_refs',
    ];

    /**
     * The proposal buckets and the live section each lifts into. Keys mirror the
     * design envelope; `unsupported` is handled separately (it never lifts as
     * grammar — it needs executable semantics).
     */
    private const BUCKETS = ['tables', 'references', 'deletions', 'block_paths', 'shortcode_paths', 'actions', 'providers'];

    /**
     * @param list<string> $args everything after the verb
     * @return int 0 ok, 2 usage/IO
     */
    public static function run(array $args): int {
        $repoArg = null;
        $name = null;
        $match = null;
        $evidence = null;
        $json = false;
        $checkProposals = false;

        // A repeated flag is refused rather than last-wins — the same posture
        // manifest-validate takes: a silently replaced flag validates a request
        // nobody wrote.
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
            } elseif ($arg === '--check-proposals') {
                $checkProposals = true;
            } elseif (str_starts_with($arg, '--name=')) {
                $name = trim(substr($arg, strlen('--name=')));
                if ($name === '') {
                    return self::fail('--name needs the exported adapter\'s manifest name');
                }
            } elseif (str_starts_with($arg, '--match=')) {
                $match = substr($arg, strlen('--match='));
            } elseif (str_starts_with($arg, '--evidence=')) {
                $evidence = trim(substr($arg, strlen('--evidence=')));
                if ($evidence === '') {
                    return self::fail('--evidence needs the path of a live-evidence json file');
                }
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
        if ($name === null) {
            return self::fail('--name=<manifest-name> is required');
        }
        // Same default as running policy-to-manifest with no key filter: every
        // classified rule is a fact. A narrower --match scopes the facts core
        // exactly as it does there.
        if ($match === null) {
            $match = '.*';
        }

        $resolved = is_dir($repoArg) ? realpath($repoArg) : false;
        if ($resolved === false) {
            return self::fail("'$repoArg' is not a directory");
        }
        if (!is_file($resolved . '/site.duo.json')) {
            return self::fail("'$resolved' has no site.duo.json — <site-repo> is the duo SITE REPO (the directory holding site.duo.json)");
        }

        // The single seam for LIVE-only inputs (deferred slice). Presence is
        // validated so a typo fails loudly; contents are NOT read here — every
        // live-dependent fact stays a proposal+question regardless.
        $evidenceNote = null;
        if ($evidence !== null) {
            if (!is_file($evidence) || !is_readable($evidence)) {
                return self::fail("--evidence '$evidence' is not a readable file");
            }
            $evidenceNote = 'accepted but NOT consumed in this offline slice — live-evidence enrichment '
                . '(SHOW COLUMNS types/PK, journal WHY-signal, live id-resolution, keyspace enumeration) is a '
                . 'deferred follow-up; every live-dependent fact below stays a proposal with a question';
        }

        try {
            self::boot();
        } catch (\Throwable $t) {
            return self::fail($t->getMessage());
        }

        // Facts core, reused VERBATIM so a draft's facts are byte-for-byte what
        // policy-to-manifest would promote from the same site + --match.
        try {
            $manifest = Policy::export_manifest($resolved, $match, $name);
        } catch (\Throwable $t) {
            return self::fail($t->getMessage());
        }

        try {
            $manifest['_draft'] = self::build_draft($resolved, $name, $evidenceNote);
        } catch (\Throwable $t) {
            return self::fail($t->getMessage());
        }

        if ($checkProposals) {
            return self::emit_check($manifest, $json);
        }

        if ($json) {
            echo rtrim(Canon::encode($manifest)) . "\n";
        } else {
            self::render($manifest, $resolved);
        }
        return 0;
    }

    /**
     * Load the engine's pure surface into this WordPress-free process. A clone of
     * ManifestValidate::boot() — resolve the two version constants out of
     * agent/duo.php's own source (never a literal, so they cannot drift from what
     * Policy::load() requires), then require the same engine files, plus Secrets
     * for the per-candidate value screen. is_multisite() is deliberately NOT
     * defined: Policy::assert_single_site() is function_exists()-guarded so the
     * pure validators run outside WordPress.
     */
    private static function boot(): void {
        $repo = dirname(__DIR__, 2);
        $agent = $repo . '/agent/duo.php';
        if (!is_file($agent)) {
            throw new \RuntimeException("adapter-draft: agent source not found at $agent");
        }
        $source = (string) file_get_contents($agent);
        if (!defined('DUO_AGENT_VERSION')) {
            if (preg_match("/define\('DUO_AGENT_VERSION', '([^']+)'\)/", $source, $m) !== 1) {
                throw new \RuntimeException('adapter-draft: could not resolve DUO_AGENT_VERSION');
            }
            define('DUO_AGENT_VERSION', $m[1]);
        }
        if (!defined('DUO_SPEC_VERSION')) {
            if (preg_match("/define\('DUO_SPEC_VERSION', ([0-9]+)\)/", $source, $m) !== 1) {
                throw new \RuntimeException('adapter-draft: could not resolve DUO_SPEC_VERSION');
            }
            define('DUO_SPEC_VERSION', (int) $m[1]);
        }
        foreach (['Canon', 'OptionState', 'ManifestDispositions', 'CapabilityRegistry', 'Policy', 'Secrets'] as $class) {
            require_once $repo . "/agent/src/$class.php";
        }
    }

    // -------------------------------------------------------------- draft build

    /**
     * Run every offline proposer over `<repo>/state/**`, screen each candidate for
     * secrets, rename the blind-walk trigger keys into their inert spellings, and
     * assemble the `_draft` sidecar with per-candidate `_meta` (content hash +
     * ratified/edited markers) preserved across re-observation.
     *
     * @return array<string,mixed>
     */
    private static function build_draft(string $repo, string $name, ?string $evidenceNote): array {
        $stateDir = $repo . '/state';
        $site = self::read_json($repo . '/site.duo.json');

        // Each proposer returns a flat list of raw candidates
        // {target, candidate, status, confidence, evidence[], questions[]} — before
        // the secret screen and before the trigger-key rename.
        $raw = [];
        foreach ([
            self::propose_tables($stateDir),
            self::propose_block_shortcode_paths($stateDir),
            self::propose_references($stateDir),
            self::propose_deletions($stateDir),
            self::propose_generated_surfaces($stateDir),
        ] as $group) {
            foreach ($group as $candidate) {
                $raw[] = $candidate;
            }
        }

        // Structural guardrail: Secrets::hard_match()/suspicious() over EVERY
        // candidate value (the same screen Pending uses). On a hit the fragment is
        // DROPPED and replaced by a question naming the label only — never the value.
        $candidates = [];
        foreach ($raw as $candidate) {
            $candidate = self::screen_secrets($candidate);
            // Constraint B: rename trigger keys in the fragment so the blind walk
            // cannot collect an inert proposal.
            $candidate['candidate'] = self::rename_triggers($candidate['candidate'], self::RENAME);
            $candidates[$candidate['target']] = $candidate;
        }

        // Human-edit preservation across re-observation (Decision 4), computed
        // against the prior artifact if one was saved to the site adapter overlay.
        [$candidates, $meta] = self::preserve_human_edits($repo, $name, $candidates, !empty($evidenceNote));

        $proposals = array_fill_keys(self::BUCKETS, []);
        $unsupported = [];
        foreach ($candidates as $candidate) {
            if (($candidate['status'] ?? '') === 'unsupported') {
                $unsupported[] = $candidate;
                continue;
            }
            $bucket = self::bucket_for_target((string) $candidate['target']);
            $proposals[$bucket][] = $candidate;
        }

        $draft = [
            'format' => self::FORMAT,
            'proposals' => (object) array_map(
                static fn(array $list) => $list,
                array_filter($proposals, static fn(array $list) => $list !== [])
            ),
            'unsupported' => $unsupported,
            '_meta' => (object) $meta,
        ];
        // The single deferred-slice seam, recorded (nested — not a reserved
        // top-level key) so a reader sees the flag was honored and ignored.
        $draft['evidence_seam'] = $evidenceNote
            ?? 'no --evidence given; this is the offline slice — live-dependent facts are proposals with questions';
        return $draft;
    }

    /**
     * Which proposal bucket a target lifts into. `tables.x`→tables,
     * `block_paths.x`→block_paths, `post_meta.x`/`references.x`→references,
     * `deletions.x`→deletions, `providers.x`→providers, `actions[i]`→actions.
     */
    private static function bucket_for_target(string $target): string {
        $head = explode('.', $target, 2)[0];
        $head = explode('[', $head, 2)[0];
        if ($head === 'shortcode_paths') {
            return 'shortcode_paths';
        }
        if (in_array($head, self::BUCKETS, true)) {
            return $head;
        }
        // meta-ref candidates carry a section-shaped head (post_meta/term_meta/…).
        return 'references';
    }

    // --------------------------------------------------------- typed-table proposer

    /**
     * Typed-table schema + natural-key/ownership, from `state/tables/<t>/*.json`
     * `columns` maps. Column NAMES and weak per-column class hints are offline
     * facts; column TYPES, the real PRIMARY KEY, and nullability are LIVE
     * (SHOW COLUMNS) and stay proposals with a question. A non-pk column whose
     * sampled values are all distinct non-empty strings is a natural-key candidate,
     * confidence bounded because live uniqueness across the whole keyspace is not
     * observable offline. (Reuses Lint::scan_tree()'s tables row-file traversal
     * shape; the method itself is not called — it reaches WordPress via get_option()
     * and Pending::resolve_id().)
     *
     * @return list<array<string,mixed>>
     */
    private static function propose_tables(string $stateDir): array {
        $out = [];
        foreach (glob($stateDir . '/tables/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $table = basename($dir);
            $rowFiles = glob($dir . '/*.json') ?: [];
            if ($rowFiles === []) {
                continue;
            }
            $columnValues = [];
            $rows = 0;
            foreach ($rowFiles as $rowFile) {
                $row = self::read_json($rowFile);
                $cols = (array) ($row['columns'] ?? []);
                if ($cols === []) {
                    continue;
                }
                $rows++;
                foreach ($cols as $col => $value) {
                    $columnValues[(string) $col][] = $value;
                }
            }
            if ($rows === 0) {
                continue;
            }
            $colNames = array_keys($columnValues);

            // Weak offline PK guess: a column named `id` (or `<t>_id`/`*_id`) whose
            // sampled values are all positive integers. This is a STRUCTURAL guess,
            // not the live PRIMARY KEY — flagged as such by the question below.
            $pk = null;
            foreach (['id', $table . '_id'] as $candidatePk) {
                if (isset($columnValues[$candidatePk]) && self::all_int($columnValues[$candidatePk])) {
                    $pk = $candidatePk;
                    break;
                }
            }
            if ($pk === null) {
                foreach ($colNames as $col) {
                    if (str_ends_with($col, '_id') && self::all_int($columnValues[$col])) {
                        $pk = $col;
                        break;
                    }
                }
            }

            // Natural-key candidate: a non-pk column whose non-empty string values
            // are all distinct across the sample.
            $naturalKey = null;
            foreach ($colNames as $col) {
                if ($col === $pk) {
                    continue;
                }
                $values = $columnValues[$col];
                $strings = array_filter($values, static fn($v) => is_string($v) && $v !== '');
                if (count($strings) === count($values) && count($values) === count(array_unique($values)) && count($values) > 1) {
                    $naturalKey = $col;
                    break;
                }
            }

            $columns = [];
            foreach ($colNames as $col) {
                if ($col === $pk) {
                    continue; // the pk is named by `pk`, not a columns{} entry
                }
                // A natural-key/slug column must be an authored column; everything
                // else defaults to the safe runtime hint until a live type says more.
                $columns[$col] = ['class' => $col === $naturalKey ? 'authored' : 'runtime'];
            }

            $identity = ['mode' => 'mapped'];
            if ($naturalKey !== null) {
                $identity = ['mode' => 'natural_key', 'column' => $naturalKey];
            }

            $fragment = [
                'class' => 'authored_snapshot',
                'refs' => [], // reference columns are live-resolved; empty offline
                'columns' => $columns,
                'identity' => $identity,
            ];
            if ($pk !== null) {
                $fragment['pk'] = $pk;
            }
            if ($naturalKey !== null) {
                $fragment['slug_column'] = $naturalKey;
            }
            // A table declaring its own id_kind is the only way to extend the ref
            // vocabulary; propose the table name as its keyspace when it is a legal
            // id_kind token, with a question to confirm against the live keyspace.
            if (preg_match('/^[a-z][a-z0-9_]*$/', $table) === 1) {
                $fragment['id_kind'] = $table;
            }

            $evidence = [[
                'source' => 'state/tables/' . $table,
                'locator' => 'columns',
                'observation' => 'observed columns [' . implode(', ', $colNames) . '] across ' . $rows . ' row file(s)',
            ]];
            $questions = [
                'column TYPES, the real PRIMARY KEY, and nullability are live facts (SHOW COLUMNS) — deferred; '
                    . 'confirm against a live target before ratifying'
                    . ($pk !== null ? " (pk='$pk' is a structural guess)" : ' (no PK inferable offline)'),
            ];
            if ($naturalKey !== null) {
                $evidence[] = [
                    'source' => 'state/tables/' . $table,
                    'locator' => 'columns.' . $naturalKey,
                    'observation' => "values distinct across $rows sampled row(s) — candidate natural key",
                ];
                $questions[] = "natural-key uniqueness for column '$naturalKey' needs live keyspace enumeration — "
                    . 'deferred; confidence is bounded offline';
            }

            $out[] = [
                'target' => 'tables.' . $table,
                'candidate' => $fragment,
                'status' => 'proposal',
                'confidence' => $naturalKey !== null ? 0.4 : 0.3,
                'evidence' => $evidence,
                'questions' => $questions,
                '_screen' => array_merge([], ...array_values($columnValues)),
            ];
        }
        return $out;
    }

    // ------------------------------------------------- block / shortcode proposers

    /**
     * Block and shortcode attribute PATHS carrying id-shaped values, from
     * `state/posts/<type>/*.md`. The PATH (block/shortcode name + attribute) and the
     * value's id shape are offline facts; the ref KIND and whether the id resolves
     * to a live entity are LIVE and stay a question (the kind is defaulted to `post`).
     * Block markup is scanned by its `<!-- wp:name {json} -->` delimiter rather than
     * WordPress's parse_blocks(), which is unavailable in this pure process.
     *
     * @return list<array<string,mixed>>
     */
    private static function propose_block_shortcode_paths(string $stateDir): array {
        $blockSeen = [];
        $shortcodeSeen = [];
        $out = [];
        foreach (glob($stateDir . '/posts/*/*.md') ?: [] as $file) {
            try {
                [, $body] = Canon::parse_post_file(Canon::read_file($file));
            } catch (\Throwable) {
                continue;
            }
            $rel = 'posts/' . basename(dirname($file)) . '/' . basename($file);

            // Blocks: <!-- wp:core/image {"id":42} --> (self-closing or paired).
            if (preg_match_all('/<!--\s*wp:([a-zA-Z0-9\/_-]+)\s*(\{.*?\})?\s*\/?-->/s', $body, $m, PREG_SET_ORDER)) {
                foreach ($m as $hit) {
                    $block = $hit[1];
                    $attrs = isset($hit[2]) && $hit[2] !== '' ? json_decode($hit[2], true) : null;
                    if (!is_array($attrs)) {
                        continue;
                    }
                    foreach ($attrs as $attr => $value) {
                        $type = self::id_attr_type($value);
                        if ($type === null) {
                            continue;
                        }
                        $key = $block . '::' . $attr;
                        if (isset($blockSeen[$key])) {
                            continue;
                        }
                        $blockSeen[$key] = true;
                        $out[] = [
                            'target' => 'block_paths.' . $block,
                            'candidate' => ['path' => (string) $attr, 'type' => $type, 'kind' => 'post'],
                            'status' => 'proposal',
                            'confidence' => 0.35,
                            'evidence' => [[
                                'source' => $rel,
                                'locator' => 'blocks.' . $block . '.attrs.' . $attr,
                                'observation' => 'id-shaped attribute value ' . self::short_value($value),
                            ]],
                            'questions' => [
                                "ref kind for block '$block' attribute '$attr' (defaulted to post; may be term/tt/user) "
                                    . 'and whether the id resolves to a live entity are live facts — deferred',
                            ],
                            '_screen' => [$value],
                        ];
                    }
                }
            }

            // Shortcodes: [gallery ids="1,2,3"] — attribute name + id-shaped value.
            if (preg_match_all('/\[([a-z0-9_-]+)((?:\s+[a-z0-9_-]+="[^"]*")+)\s*\]/i', $body, $sm, PREG_SET_ORDER)) {
                foreach ($sm as $hit) {
                    $tag = $hit[1];
                    if (preg_match_all('/([a-z0-9_-]+)="([^"]*)"/i', $hit[2], $am, PREG_SET_ORDER)) {
                        foreach ($am as $attr) {
                            [$attrName, $attrVal] = [$attr[1], $attr[2]];
                            $type = self::shortcode_attr_type($attrVal);
                            if ($type === null) {
                                continue;
                            }
                            $key = $tag . '::' . $attrName;
                            if (isset($shortcodeSeen[$key])) {
                                continue;
                            }
                            $shortcodeSeen[$key] = true;
                            $out[] = [
                                'target' => 'shortcode_paths.' . $tag,
                                'candidate' => ['path' => $attrName, 'type' => $type, 'kind' => 'post'],
                                'status' => 'proposal',
                                'confidence' => 0.3,
                                'evidence' => [[
                                    'source' => $rel,
                                    'locator' => 'shortcodes.' . $tag . '.' . $attrName,
                                    'observation' => 'id-shaped shortcode attribute value "' . self::short_value($attrVal) . '"',
                                ]],
                                'questions' => [
                                    "ref kind for shortcode '$tag' attribute '$attrName' (defaulted to post) and live "
                                        . 'id resolution are deferred',
                                ],
                                '_screen' => [$attrVal],
                            ];
                        }
                    }
                }
            }
        }
        return $out;
    }

    // ---------------------------------------------------------- reference proposer

    /**
     * Meta reference candidates: integer-looking values in a post's front-matter
     * `meta` that carry no declared ref. STRUCTURE (which key holds an id shape) is
     * offline; whether the id resolves is LIVE and stays a question.
     *
     * @return list<array<string,mixed>>
     */
    private static function propose_references(string $stateDir): array {
        $seen = [];
        $out = [];
        foreach (glob($stateDir . '/posts/*/*.md') ?: [] as $file) {
            try {
                [$front] = Canon::parse_post_file(Canon::read_file($file));
            } catch (\Throwable) {
                continue;
            }
            $rel = 'posts/' . basename(dirname($file)) . '/' . basename($file);
            foreach ((array) ($front['meta'] ?? []) as $metaKey => $value) {
                $type = self::id_attr_type($value);
                if ($type === null || isset($seen[$metaKey])) {
                    continue;
                }
                $seen[$metaKey] = true;
                // A meta ref rule names its keyspace as a STRING kind (`ref: "post"`
                // or `"post[]"`), not an object — see ReferenceRules::value_rule().
                $ref = $type === 'int[]' ? 'post[]' : 'post';
                $out[] = [
                    'target' => 'post_meta.' . $metaKey,
                    'candidate' => ['ref' => $ref],
                    'status' => 'proposal',
                    'confidence' => 0.3,
                    'evidence' => [[
                        'source' => $rel,
                        'locator' => 'meta.' . $metaKey,
                        'observation' => 'id-shaped meta value ' . self::short_value($value) . ' with no declared ref',
                    ]],
                    'questions' => [
                        "does meta key '$metaKey' hold a post id (vs term/tt/user, or an unrelated count)? live id "
                            . 'resolution is deferred; kind defaulted to post',
                    ],
                    '_screen' => [$value],
                ];
            }
        }
        return $out;
    }

    // ----------------------------------------------------------- deletion proposer

    /**
     * Deletion guards/cascades for the entity kinds present in `state/**`. The
     * required-cascade set is a STATIC per-kind function mirroring
     * Deletion::capability()'s own `match ($kind)` map — deletion authority is never
     * inferred, so each candidate is a proposal spelling out that set plus a question,
     * never an applied capability. Guards (reverse-reference checks) need live
     * reverse-ref observation and stay empty with a question.
     *
     * @return list<array<string,mixed>>
     */
    private static function propose_deletions(string $stateDir): array {
        $kinds = [];
        foreach (glob($stateDir . '/posts/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $kinds[] = ['post', basename($dir)];
        }
        foreach (glob($stateDir . '/terms/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $kinds[] = ['term', basename($dir)];
        }
        if (glob($stateDir . '/menus/*.json')) {
            $kinds[] = ['menu', 'nav_menu'];
        }
        foreach (glob($stateDir . '/tables/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $kinds[] = ['table', basename($dir)];
        }

        $out = [];
        foreach ($kinds as [$kind, $type]) {
            $selector = $kind . ':' . $type;
            $out[] = [
                'target' => 'deletions.' . $selector,
                'candidate' => [
                    'cascades' => self::required_cascades($kind),
                    'guards' => [],
                ],
                'status' => 'proposal',
                'confidence' => 0.25,
                'evidence' => [[
                    'source' => 'state/' . ($kind === 'menu' ? 'menus' : ($kind === 'table' ? 'tables/' . $type : ($kind === 'post' ? 'posts/' . $type : 'terms/' . $type))),
                    'locator' => $selector,
                    'observation' => "entity kind '$selector' present in captured state",
                ]],
                'questions' => [
                    "deletion authority for '$selector' is never inferred: this proposal spells the required cascade "
                        . 'effects [' . implode(', ', self::required_cascades($kind)) . '] a ratifying human must own; '
                        . 'apply nothing until an adapter declares them',
                    "reverse-reference guards for '$selector' need live reverse-ref observation — deferred",
                ],
            ];
        }
        return $out;
    }

    /**
     * The STATIC required-cascade set per deletion kind — a verbatim mirror of the
     * `match ($kind)` arms in Deletion::capability() (agent/src/Deletion.php). Kept
     * as a spelled-out set because Deletion::capability() offers no pure accessor
     * (it needs a live Policy and throws on a missing capability); the regression
     * suite pins this against Deletion.php's source so the two cannot drift. The
     * `table` arm is live-conditional there (attached_meta iff the table has an EAV
     * sidecar), so offline it is left empty with the question above.
     *
     * @return list<string>
     */
    private static function required_cascades(string $kind): array {
        return match ($kind) {
            'post' => ['postmeta', 'post_revisions', 'term_relationships'],
            'term' => ['termmeta', 'term_taxonomy', 'term_relationships'],
            'menu' => ['termmeta', 'term_taxonomy', 'term_relationships', 'menu_items'],
            'table' => [],
            default => [],
        };
    }

    // ------------------------------------------------ generated-surface proposer

    /**
     * A serialized/derived-looking meta or column payload is a GENERATED surface: it
     * needs executable semantics to reproduce, which this engine never infers as
     * code. It is emitted as `unsupported` carrying a provider capability
     * DECLARATION (or the option of a native action from the closed vocabulary) plus
     * guidance — NEVER a PHP interpreter/regenerator stub. Whether it is truly
     * plugin-generated needs the LIVE journal WHY-signal, so confidence is low and a
     * question says so.
     *
     * @return list<array<string,mixed>>
     */
    private static function propose_generated_surfaces(string $stateDir): array {
        $out = [];
        $index = 0;
        $emit = function (string $rel, string $locator, $value) use (&$out, &$index): void {
            if (!self::looks_generated($value)) {
                return;
            }
            $slug = self::slug(basename($rel) . '_' . $locator);
            $out[] = [
                'target' => 'unsupported.' . $index,
                'candidate' => [
                    // A capability DECLARATION, not code. `source: plugin` keeps the
                    // installed plugin the trust anchor; no interpreter/regenerator.
                    'id' => 'gen-' . substr($slug, 0, 40),
                    'plugin' => 'observed-plugin/observed-plugin.php',
                    'source' => 'plugin',
                    'version' => '0.0.0',
                    'capabilities' => ['regenerate_' . substr($slug, 0, 48)],
                ],
                'status' => 'unsupported',
                'confidence' => 0.2,
                'evidence' => [[
                    'source' => $rel,
                    'locator' => $locator,
                    'observation' => 'serialized/derived-looking payload ' . self::short_value($value) . ' — likely plugin-generated',
                ]],
                'questions' => [
                    'is this actually plugin-generated? the LIVE journal WHY-signal is needed to confirm — deferred',
                    'executable regeneration is a plugin capability declaration or a native action from the closed '
                        . 'vocabulary (' . implode(', ', NativeActions::vocabulary()) . '), NEVER engine code',
                ],
                '_screen' => [$value],
            ];
            $index++;
        };
        foreach (glob($stateDir . '/posts/*/*.md') ?: [] as $file) {
            try {
                [$front] = Canon::parse_post_file(Canon::read_file($file));
            } catch (\Throwable) {
                continue;
            }
            $rel = 'posts/' . basename(dirname($file)) . '/' . basename($file);
            foreach ((array) ($front['meta'] ?? []) as $metaKey => $value) {
                $emit($rel, 'meta.' . $metaKey, $value);
            }
        }
        foreach (glob($stateDir . '/tables/*/*.json') ?: [] as $file) {
            $row = self::read_json($file);
            $rel = 'tables/' . basename(dirname($file)) . '/' . basename($file);
            foreach ((array) ($row['columns'] ?? []) as $col => $value) {
                $emit($rel, 'columns.' . $col, $value);
            }
        }
        return $out;
    }

    // -------------------------------------------------------- secret screen

    /**
     * Secrets::hard_match()/suspicious() over every string in a candidate fragment
     * (the same discipline Pending applies to a pending item). On a hit the fragment
     * is DROPPED — replaced by an empty candidate and a question naming the label
     * only. The screen never authors the offending value into the draft.
     *
     * @param array<string,mixed> $candidate
     * @return array<string,mixed>
     */
    private static function screen_secrets(array $candidate): array {
        // `_screen` carries the RAW observed source values (a fragment holds only
        // names/structure, so a secret in a captured VALUE would otherwise slip the
        // screen). Scanned here against both the fragment and the raw values, then
        // stripped so it never reaches the output.
        $screen = $candidate['_screen'] ?? [];
        unset($candidate['_screen']);
        $label = self::scan_secret('', $candidate['candidate'] ?? null)
            ?? self::scan_secret('', $screen);
        if ($label === null) {
            return $candidate;
        }
        $candidate['candidate'] = (object) [];
        $candidate['status'] = 'proposal';
        $candidate['confidence'] = 0.0;
        $candidate['evidence'] = [];
        $candidate['questions'] = array_merge($candidate['questions'] ?? [], [
            "a candidate value screened as a possible secret ($label) and was DROPPED — the value is never authored "
                . 'into a draft; re-derive this candidate by hand from a redacted source if it is genuinely a reference',
        ]);
        return $candidate;
    }

    /** Recursively hard_match()/suspicious() the string leaves of a value. */
    private static function scan_secret(string $key, $value): ?string {
        if (is_string($value)) {
            $hard = Secrets::hard_match($value);
            if ($hard !== null) {
                return $hard;
            }
            return Secrets::suspicious($key, $value) ? 'suspicious credential-shaped value' : null;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $label = self::scan_secret((string) $k, $v);
                if ($label !== null) {
                    return $label;
                }
            }
        }
        return null;
    }

    // ----------------------------------------------- trigger-key rename (Constraint B)

    /**
     * Recursively rename map KEYS through $map at any depth (renames only, values
     * untouched). Used one way to make a fragment inert under `_draft`
     * (ref→proposed_ref, …) and the flip to restore it before `--check-proposals`
     * lifts it into a throwaway manifest. A list's integer keys are never renamed.
     *
     * @param array<string,string> $map
     */
    private static function rename_triggers(mixed $node, array $map): mixed {
        if (!is_array($node)) {
            return $node;
        }
        $isList = array_is_list($node);
        $out = [];
        foreach ($node as $key => $value) {
            $newKey = (!$isList && is_string($key) && isset($map[$key])) ? $map[$key] : $key;
            $out[$newKey] = self::rename_triggers($value, $map);
        }
        return $out;
    }

    // ---------------------------------------------- human-edit preservation (_meta)

    /**
     * Per-candidate content hash + ratified/edited markers, preserved across
     * re-observation against the prior artifact if the author saved one to the site
     * adapter overlay (`<repo>/adapters/<name>.json`):
     *   - ratified:true  → the human lifted it into a live section; carried verbatim,
     *     never regenerated.
     *   - the prior fragment's hash disagrees with the stored generated_hash → the
     *     human edited it; the edit is PRESERVED, edited:true, and the fresh
     *     observation recorded as drift under evidence/questions (never overwritten).
     *   - otherwise → refreshed in place with a fresh generated_hash.
     *
     * @param array<string,array<string,mixed>> $fresh keyed by target
     * @return array{0:array<string,array<string,mixed>>,1:array<string,mixed>}
     */
    private static function preserve_human_edits(string $repo, string $name, array $fresh, bool $hasEvidence): array {
        $priorDraft = null;
        $priorFile = $repo . '/adapters/' . $name . '.json';
        if (is_file($priorFile) && is_readable($priorFile)) {
            try {
                $priorDraft = self::read_json($priorFile)['_draft'] ?? null;
            } catch (\Throwable) {
                $priorDraft = null;
            }
        }
        $priorIndex = [];
        $priorMeta = [];
        if (is_array($priorDraft)) {
            $priorMeta = (array) ($priorDraft['_meta'] ?? []);
            foreach ((array) ($priorDraft['proposals'] ?? []) as $list) {
                foreach ((array) $list as $candidate) {
                    if (is_array($candidate) && isset($candidate['target'])) {
                        $priorIndex[(string) $candidate['target']] = $candidate;
                    }
                }
            }
            foreach ((array) ($priorDraft['unsupported'] ?? []) as $candidate) {
                if (is_array($candidate) && isset($candidate['target'])) {
                    $priorIndex[(string) $candidate['target']] = $candidate;
                }
            }
        }

        $out = [];
        $meta = [];
        foreach ($fresh as $target => $candidate) {
            $priorEntry = $priorMeta[$target] ?? null;

            if (is_array($priorEntry) && ($priorEntry['ratified'] ?? false) === true) {
                // Ratified: the generator NEVER touches it.
                $out[$target] = $priorIndex[$target] ?? $candidate;
                $meta[$target] = $priorEntry;
                continue;
            }

            $freshHash = self::generated_hash($candidate['candidate']);
            if (is_array($priorEntry) && isset($priorIndex[$target])) {
                $priorHash = self::generated_hash($priorIndex[$target]['candidate'] ?? null);
                $stored = (string) ($priorEntry['generated_hash'] ?? '');
                if ($stored !== '' && $priorHash !== $stored) {
                    // Human edited the prior fragment: preserve it, record drift.
                    $preserved = $priorIndex[$target];
                    $preserved['evidence'] = array_merge((array) ($preserved['evidence'] ?? []), [[
                        'source' => 'regeneration-drift',
                        'locator' => (string) $target,
                        'observation' => 'the generator re-observed the source and would now write a different '
                            . 'fragment (fresh hash ' . substr($freshHash, 0, 12) . '); the human edit is preserved, '
                            . 'not overwritten',
                    ]]);
                    $preserved['questions'] = array_merge((array) ($preserved['questions'] ?? []), [
                        'a human-edited candidate was preserved across regeneration; reconcile it with the fresh '
                            . 'observation above if the underlying source changed',
                    ]);
                    $out[$target] = $preserved;
                    $meta[$target] = ['generated_hash' => $stored, 'ratified' => false, 'edited' => true];
                    continue;
                }
            }

            // New, or unedited and unratified: refresh in place.
            $out[$target] = $candidate;
            $meta[$target] = ['generated_hash' => $freshHash, 'ratified' => false, 'edited' => false];
        }

        // Preserve ratified records for targets no longer observed (already lifted).
        foreach ($priorMeta as $target => $entry) {
            if (isset($out[$target])) {
                continue;
            }
            if (is_array($entry) && ($entry['ratified'] ?? false) === true) {
                $meta[$target] = $entry;
                if (isset($priorIndex[$target])) {
                    $out[$target] = $priorIndex[$target];
                }
            }
        }

        return [$out, $meta];
    }

    /** The engine's content-hash idiom: sha256 over the canonical encoding. */
    private static function generated_hash(mixed $fragment): string {
        return hash('sha256', Canon::encode($fragment));
    }

    // --------------------------------------------------------- --check-proposals

    /**
     * Lift each proposal candidate into a THROWAWAY in-memory manifest, restore the
     * trigger keys, and run the REAL validators (Policy::load over a temp library),
     * reporting per-candidate grammar-validity. Nothing is written live: the
     * throwaway manifest lives only in a temp directory removed on return.
     */
    private static function emit_check(array $manifest, bool $json): int {
        $draft = $manifest['_draft'] ?? [];
        $proposals = (array) ($draft['proposals'] ?? []);
        $results = [];
        $tmp = null;
        foreach (self::BUCKETS as $bucket) {
            foreach ((array) ($proposals[$bucket] ?? []) as $candidate) {
                if (!is_array($candidate) || !isset($candidate['target'])) {
                    continue;
                }
                $target = (string) $candidate['target'];
                $section = self::lift_section($target, self::rename_triggers($candidate['candidate'] ?? null, array_flip(self::RENAME)));
                if ($section === null) {
                    $results[] = ['target' => $target, 'liftable' => false, 'message' => 'no live section maps this target'];
                    continue;
                }
                $tmp = $tmp ?? self::make_tmp_library();
                [$ok, $message] = self::validate_lifted($tmp, $section);
                $results[] = ['target' => $target, 'liftable' => $ok, 'message' => $message];
            }
        }
        if ($tmp !== null) {
            self::rrmdir($tmp);
        }

        $liftable = 0;
        foreach ($results as $r) {
            $liftable += $r['liftable'] ? 1 : 0;
        }
        $report = [
            'format' => self::CHECK_FORMAT,
            'spec_version' => DUO_SPEC_VERSION,
            'results' => $results,
            'summary' => ['checked' => count($results), 'liftable' => $liftable, 'refused' => count($results) - $liftable],
        ];
        if ($json) {
            echo rtrim(Canon::encode($report)) . "\n";
        } else {
            echo "adapter-draft --check-proposals (throwaway lift; nothing written live):\n";
            foreach ($results as $r) {
                echo '  [' . ($r['liftable'] ? 'liftable' : 'refused') . '] ' . $r['target'] . "\n";
                if ($r['message'] !== null) {
                    echo '          ' . $r['message'] . "\n";
                }
            }
            echo "\nsummary: {$report['summary']['checked']} checked, {$report['summary']['liftable']} liftable, "
                . "{$report['summary']['refused']} refused\n";
        }
        return 0;
    }

    /**
     * Place a (trigger-keys-restored) candidate fragment into the live section its
     * target names. Returns null if the target has no liftable live section.
     *
     * @return array<string,mixed>|null
     */
    private static function lift_section(string $target, mixed $fragment): ?array {
        $head = explode('.', $target, 2)[0];
        $tail = substr($target, strlen($head) + 1);
        switch ($head) {
            case 'tables':
                return ['tables' => [$tail => $fragment]];
            case 'block_paths':
                return ['block_attrs' => [$tail => [$fragment]]];
            case 'shortcode_paths':
                return ['shortcode_attrs' => [$tail => [$fragment]]];
            case 'deletions':
                return ['deletions' => [$tail => $fragment]];
            case 'providers':
                return ['providers' => [$fragment]];
            case 'options':
            case 'post_meta':
            case 'term_meta':
            case 'user_meta':
                return [$head => [$tail => $fragment]];
            default:
                return null;
        }
    }

    /**
     * Load a throwaway one-candidate manifest through the REAL Policy::load(). The
     * name equals the filename (DUO-3371), core is present, and DUO_MANIFESTS_DIR
     * points at the temp library only for this load — nothing live is touched.
     *
     * @param array<string,mixed> $section
     * @return array{0:bool,1:?string}
     */
    private static function validate_lifted(string $library, array $section): array {
        $checkName = 'duo-adapter-draft-check';
        $manifest = array_merge(['name' => $checkName, 'spec_version' => DUO_SPEC_VERSION], $section);
        Canon::write_file($library . '/' . $checkName . '.json', Canon::encode($manifest));
        $prev = getenv('DUO_MANIFESTS_DIR');
        putenv('DUO_MANIFESTS_DIR=' . $library);
        try {
            Policy::load(null, [$checkName]);
            return [true, 'grammar-valid — liftable into its live section by a human'];
        } catch (\Throwable $t) {
            return [false, $t->getMessage()];
        } finally {
            if ($prev === false) {
                putenv('DUO_MANIFESTS_DIR');
            } else {
                putenv('DUO_MANIFESTS_DIR=' . $prev);
            }
        }
    }

    /** A temp manifest library carrying a minimal `core` for the throwaway loads. */
    private static function make_tmp_library(): string {
        $dir = sys_get_temp_dir() . '/duo_adapter_draft_check_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        Canon::write_file($dir . '/core.json', Canon::encode([
            'name' => 'core',
            'spec_version' => DUO_SPEC_VERSION,
            'options' => (object) [],
            'post_meta' => (object) [],
            'term_meta' => (object) [],
        ]));
        return $dir;
    }

    // ---------------------------------------------------------------- render

    /** @param array<string,mixed> $manifest */
    private static function render(array $manifest, string $repo): void {
        $draft = $manifest['_draft'] ?? [];
        echo "site repo:    $repo\n";
        echo "adapter name: {$manifest['name']}\n";
        echo "spec_version: {$manifest['spec_version']}\n";
        $facts = 0;
        foreach (['options', 'post_meta', 'term_meta', 'user_meta'] as $section) {
            $facts += count((array) ($manifest[$section] ?? []));
        }
        echo "\nfacts (validated, applied — real classification sections): $facts rule(s)\n";
        echo "  " . self::fact_counts($manifest) . "\n";

        $proposals = (array) ($draft['proposals'] ?? []);
        $pCount = 0;
        echo "\nproposals (INERT under _draft — nothing is applied; ratify by hand):\n";
        foreach ($proposals as $bucket => $list) {
            foreach ((array) $list as $candidate) {
                $pCount++;
                echo "  [proposal] {$candidate['target']} (confidence "
                    . number_format((float) ($candidate['confidence'] ?? 0), 2) . ")\n";
                foreach ((array) ($candidate['questions'] ?? []) as $q) {
                    echo "          ? $q\n";
                }
            }
        }
        if ($pCount === 0) {
            echo "  (none observed offline)\n";
        }

        $unsupported = (array) ($draft['unsupported'] ?? []);
        echo "\nunsupported (needs executable semantics — capability declaration + guidance, never code):\n";
        foreach ($unsupported as $candidate) {
            echo "  [unsupported] {$candidate['target']}\n";
            foreach ((array) ($candidate['questions'] ?? []) as $q) {
                echo "          ? $q\n";
            }
        }
        if ($unsupported === []) {
            echo "  (none observed offline)\n";
        }

        echo "\nevidence seam: " . ($draft['evidence_seam'] ?? '') . "\n";
        echo "\nsummary: $facts fact(s) validated; $pCount proposal(s) + " . count($unsupported)
            . " unsupported are INERT and unvalidated here — run 'duo adapter-draft --check-proposals' or "
            . "install the draft and 'duo manifest-validate <dir>'. --format=json prints the draft artifact.\n";
    }

    /** @param array<string,mixed> $manifest */
    private static function fact_counts(array $manifest): string {
        $parts = [];
        foreach (['options', 'post_meta', 'term_meta', 'user_meta'] as $section) {
            $parts[] = $section . '=' . count((array) ($manifest[$section] ?? []));
        }
        return implode(', ', $parts);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string,mixed> */
    private static function read_json(string $path): array {
        return (array) Canon::decode(Canon::read_file($path));
    }

    private static function all_int(array $values): bool {
        foreach ($values as $v) {
            if (!(is_int($v) || (is_string($v) && ctype_digit($v) && $v !== ''))) {
                return false;
            }
        }
        return $values !== [];
    }

    /** The block/shortcode attribute value's id-shape as an ATTR_VALUE_TYPES token, or null. */
    private static function id_attr_type(mixed $value): ?string {
        if (self::is_id_scalar($value)) {
            return 'int';
        }
        if (is_array($value) && array_is_list($value) && $value !== []) {
            foreach ($value as $entry) {
                if (!self::is_id_scalar($entry)) {
                    return null;
                }
            }
            return 'int[]';
        }
        return null;
    }

    /** A comma-list-or-single id shape for a shortcode attribute string. */
    private static function shortcode_attr_type(string $value): ?string {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (self::is_id_scalar($value)) {
            return 'int';
        }
        if (str_contains($value, ',')) {
            foreach (explode(',', $value) as $part) {
                if (!self::is_id_scalar(trim($part))) {
                    return null;
                }
            }
            return 'int[]';
        }
        return null;
    }

    private static function is_id_scalar(mixed $v): bool {
        if (is_int($v)) {
            return $v > 0;
        }
        if (is_string($v)) {
            return ctype_digit($v) && $v !== '' && strlen($v) <= 9 && $v[0] !== '0';
        }
        return false;
    }

    /** A serialized/derived-looking payload: PHP-serialized, or a large JSON/opaque blob. */
    private static function looks_generated(mixed $value): bool {
        if (!is_string($value)) {
            return false;
        }
        if (preg_match('/^(a:\d+:\{|O:\d+:"|s:\d+:")/', $value) === 1) {
            return true; // PHP serialize()
        }
        if (strlen($value) >= 120 && (str_starts_with(ltrim($value), '{') || str_starts_with(ltrim($value), '['))) {
            return true; // large embedded JSON blob (Elementor-style)
        }
        return false;
    }

    private static function short_value(mixed $value): string {
        $s = is_string($value) ? $value : json_encode($value);
        $s = (string) $s;
        return strlen($s) > 80 ? substr($s, 0, 77) . '...' : $s;
    }

    private static function slug(string $s): string {
        $s = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $s) ?? '');
        $s = trim($s, '_');
        return $s === '' ? 'x' : $s;
    }

    private static function rrmdir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    /** Fail closed on this command's own paths: usage, a bad dir, an unreadable file. */
    private static function fail(string $message): int {
        fwrite(STDERR, "duo: adapter-draft: $message\n");
        return 2;
    }
}

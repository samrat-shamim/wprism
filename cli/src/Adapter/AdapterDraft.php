<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

use Duo\Canon;
use Duo\AdapterSources;
use Duo\Deletion;
use Duo\NativeActions;
use Duo\Policy;
use Duo\Secrets;

/**
 * `duo adapter-draft` — the safe adapter DRAFT generator (DUO-3325, offline slice).
 *
 * `duo policy-to-manifest` promotes a site's ALREADY-classified policy rules into
 * a manifest and emits ONLY facts; it is human-only by a reviewed decision and has
 * no proposers. This verb is its generator sibling: it starts from that exact facts
 * core (`Policy::export_manifest()`), then conservatively merges a prior draft so
 * a later policy export cannot silently erase a human's classification decision or
 * any non-generator-owned manifest declaration. It also adds OFFLINE proposers that
 * observe a site repo's captured `state/**` and emit grammar-shaped CANDIDATES the
 * author can ratify by hand. It writes nothing live and promotes nothing automatically.
 *
 * Like `duo manifest-validate` this is a HOST verb, not a `wp duo` subcommand: it
 * boots the pure engine (Canon/OptionState/ManifestDispositions/Policy, plus
 * Secrets for the value screen) WordPress-free, with no DB and no
 * docker, which is exactly why the whole thing is offline-buildable. Every fact that
 * genuinely needs a live target (a column's SQL type, its real PRIMARY KEY, whether
 * an integer resolves to a live entity, natural-key uniqueness across the keyspace)
 * stays a `proposal` carrying a `question` that names the deferral. `--evidence=<file>`
 * is the single seam a live answer attaches through: a `duo-adapter-probe/v1` document
 * from `wp duo adapter-probe`, the only half that runs on a target. Its facts land as
 * `evidence[]` rows at confidence 1.0, each naming the question it closes (see
 * PROBE_QUESTIONS); nothing else changes. A probe converts "pk='id' is a structural
 * guess" into "the live PRIMARY KEY is (id)" — it makes the human ratification better
 * founded, it never performs it, and read_probe() refuses any document carrying a word
 * outside the closed probe vocabulary so it cannot try.
 *
 * TWO code-derived constraints govern the envelope, both correctness-critical:
 *
 *   A. `AdapterSources::assert_out_of_tree_contract()` refuses a site/plugin-installed
 *      manifest that names a top-level `evidence`/`disposition`/`certification`/
 *      `signature`/… key. A draft's destiny is to become a site adapter, so proposals
 *      and their per-candidate `evidence`/`questions` nest under ONE neutral,
 *      non-reserved top-level key: `_draft`. (`_draft` is not on that reserved list.)
 *
 *   B. `ReferenceKindGrammar::validate_ref_kinds()` → `collect_ref_kind_claims()` blind-walks the
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

    /** Envelope of the `--gap-report` emission: draft engine-gap ledger rows, primitive unnamed. */
    public const GAP_REPORT_FORMAT = 'duo-adapter-draft-gap-report/v1';

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
    private const BUCKETS = [
        'tables', 'references', 'deletions', 'block_paths', 'shortcode_paths', 'actions', 'providers',
        // DUO T6 §3.5's --seed families. They are separate buckets rather than
        // folded into `references` because a seeded candidate answers a
        // different question: `references` proposes how an already-captured
        // value points at an entity, while these three propose that a surface
        // the site HAS should be modelled at all. An author reviewing a draft
        // needs those two piles apart.
        'option_namespaces', 'option_patterns', 'post_types',
    ];

    /**
     * The whole ownership contract for regeneration. This is intentionally a
     * closed list rather than "everything Policy::export_manifest() returned": a
     * future exporter key must be consciously assigned an owner before this command
     * can replace an author's top-level intent.
     */
    private const OWNED_TOP_LEVEL = [
        'name' => true,
        'spec_version' => true,
        'options' => true,
        'post_meta' => true,
        'term_meta' => true,
        'user_meta' => true,
        '_draft' => true,
    ];

    /** The classification sections Policy::export_manifest() owns as facts. */
    private const FACT_SECTIONS = ['options', 'post_meta', 'term_meta', 'user_meta'];

    /** Envelope of the live document `--evidence=<file>` accepts (`Duo\AdapterProbe::FORMAT`). */
    public const PROBE_FORMAT = 'duo-adapter-probe/v1';

    /**
     * The CLOSED vocabulary of live questions a probe can answer.
     *
     * A question a proposer defers is free-form prose except for one thing:
     * when a live document CAN answer it, the question is written
     * `[<name>] <prose>` and an incoming evidence row names that same
     * `<name>`. That is the whole "answered by name" mechanism — the prose is
     * for the human, the name is what makes an answer attachable to the
     * deferral it closes instead of arriving as an unsolicited assertion.
     * A name is a promise that some live fact addresses it, so this list only
     * grows with the probe's own fact families.
     */
    private const PROBE_QUESTIONS = [
        // column types, nullability, and the real PRIMARY KEY
        'table_schema' => true,
        // uniqueness of a natural-key column across the whole keyspace
        'natural_key_uniqueness' => true,
        // DeleteGuardEvaluator::lock_index()'s first-column/prefix coverage
        'lock_index' => true,
        // declared FOREIGN KEY constraints (context for deletion, never authority)
        'foreign_keys' => true,
        // the `<table>meta` sidecar a draft that declares only the parent misses
        'eav_twin' => true,
    ];

    /**
     * The closed per-table key set a probe document may carry.
     *
     * This is the risk boundary, not a schema formality: the danger of a
     * live-evidence file is that it is read as an unreviewed AUTHORITY, so
     * the consumer refuses a document that carries any word outside this
     * list — `class`, `identity`, `deletions` and every capability noun
     * included. A probe can therefore never propose a classification, only
     * report what the server said.
     */
    private const PROBE_TABLE_KEYS = [
        'present', 'columns', 'primary_key', 'unique_keys', 'index_coverage',
        'foreign_keys', 'eav_twin', 'natural_key',
    ];

    /** `duo coverage <env> --format=json` — the seed document's primary shape. */
    public const SEED_COVERAGE_FORMAT = 'duo-coverage-report/v1';

    /**
     * `wp duo assess-inventory --format=json` is also accepted, and is the
     * RICHER seed: it embeds the whole coverage report AND the `pending`
     * queue, which is where the scope-gate's unclassified post types live.
     * Coverage alone cannot seed a `post_types` proposal because it does not
     * report post types at all — so an operator seeding from coverage gets
     * two of the three families and is told which one is missing, rather than
     * silently getting a shorter draft.
     */
    public const SEED_INVENTORY_FORMAT = 'duo-assess-inventory/v1';

    /**
     * @param list<string> $args everything after the verb
     * @return int 0 ok, 2 usage/IO
     */
    public static function run(array $args): int {
        $repoArg = null;
        $name = null;
        $match = null;
        $evidence = null;
        $seed = null;
        $out = null;
        $force = false;
        $json = false;
        $checkProposals = false;
        $gapReport = false;

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
            } elseif ($arg === '--gap-report') {
                $gapReport = true;
            } elseif ($arg === '--force') {
                $force = true;
            } elseif (str_starts_with($arg, '--out=')) {
                $out = trim(substr($arg, strlen('--out=')));
                if ($out === '') {
                    return self::fail('--out needs the path to write the draft to');
                }
            } elseif (str_starts_with($arg, '--seed=')) {
                $seed = trim(substr($arg, strlen('--seed=')));
                if ($seed === '') {
                    return self::fail('--seed needs the path of a `duo coverage --format=json` document');
                }
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
        // Two report modes over the same lift, answering different questions —
        // "which proposals can I promote" and "which observed shapes the grammar
        // cannot express at all". Refusing the pair is the same posture the
        // duplicate-flag guard above takes: silently printing one of the two
        // reports answers a question nobody asked.
        if ($checkProposals && $gapReport) {
            return self::fail('--check-proposals and --gap-report are two reports over one lift; ask for one');
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

        // --force is a modifier on --out and means nothing without it. Refusing
        // rather than ignoring is the difference between an author learning
        // their overwrite guard is off and believing it is on.
        if ($force && $out === null) {
            return self::fail('--force modifies --out; it has no meaning on its own');
        }
        $outPath = null;
        if ($out !== null) {
            $outDir = dirname($out);
            $resolvedOutDir = is_dir($outDir) ? realpath($outDir) : false;
            if ($resolvedOutDir === false) {
                return self::fail("--out '$out' names a directory that does not exist: $outDir");
            }
            $outPath = $resolvedOutDir . '/' . basename($out);
            if (file_exists($outPath) && !$force) {
                // A draft is REVIEWED by hand — an author's ratifications live
                // in the file this would replace. Overwriting silently is the
                // one way this command can destroy work, so it never does it
                // without being told twice. (Re-running WITH --force is safe by
                // design: preserve_human_edits() reads the prior artifact and
                // carries ratified candidates forward.)
                return self::fail(
                    "[draft_output_exists] --out '$outPath' already exists; adapter-draft never replaces a "
                    . 'reviewed draft silently. re-run with --force to regenerate over it (human edits in the '
                    . 'prior draft are preserved), or name a different path'
                );
            }
            if (is_link($outPath)) {
                return self::fail("--out '$outPath' is a symbolic link; adapter-draft writes regular files only");
            }
        }

        // The seed is a document another duo command already produced. It is
        // read for NAMES ONLY — prefixes, table names, post-type names — never
        // for values, exactly as `duo coverage` publishes it.
        $seedDocument = null;
        if ($seed !== null) {
            if (!is_file($seed) || !is_readable($seed)) {
                return self::fail("--seed '$seed' is not a readable file");
            }
            $decoded = json_decode((string) file_get_contents($seed), true);
            if (!is_array($decoded) || array_is_list($decoded)) {
                return self::fail("--seed '$seed' is not a JSON object");
            }
            $seedFormat = $decoded['format'] ?? null;
            if (!in_array($seedFormat, [self::SEED_COVERAGE_FORMAT, self::SEED_INVENTORY_FORMAT], true)) {
                return self::fail(
                    "--seed '$seed' is not a " . self::SEED_COVERAGE_FORMAT . ' document ('
                    . '`duo coverage <env> --format=json`) or a ' . self::SEED_INVENTORY_FORMAT
                    . ' document (`wp duo assess-inventory --format=json`)'
                );
            }
            $seedDocument = $decoded;
        }

        // The single seam for LIVE-only inputs: a `duo-adapter-probe/v1`
        // document from `wp duo adapter-probe`, the only half that runs on a
        // target. It is validated against a CLOSED key set here (read_probe())
        // rather than trusted, because the failure mode this flag can have is
        // a live document being read as an unreviewed authority.
        $probe = null;
        if ($evidence !== null && (!is_file($evidence) || !is_readable($evidence))) {
            return self::fail("--evidence '$evidence' is not a readable file");
        }

        try {
            self::boot();
        } catch (\Throwable $t) {
            return self::fail($t->getMessage());
        }
        // After boot(), because the hash check is Canon's canonical encoding —
        // the same bytes the emitter hashed, not a second JSON opinion.
        if ($evidence !== null) {
            try {
                $probe = self::read_probe($evidence);
            } catch (\Throwable $t) {
                return self::fail($t->getMessage());
            }
        }
        try {
            AdapterSources::assert_name($name, 'adapter-draft --name');
        } catch (\Throwable) {
            return self::fail('--name must use the canonical lowercase adapter-name grammar');
        }

        try {
            $prior = self::read_prior_manifest($resolved, $name);
            if ($prior !== null) {
                self::validate_prior_manifest($resolved, $name);
            }
            $exported = Policy::export_manifest($resolved, $match, $name);
            [$manifest, $factConflicts] = self::merge_prior_manifest_intent($exported, $prior);
            $priorDraft = $prior === null ? null : ($prior['_draft'] ?? null);
            $manifest['_draft'] = self::build_draft(
                $resolved, $name, $probe, $priorDraft, $factConflicts, $seedDocument, $match
            );
            self::assert_output_is_safe($manifest);
        } catch (\Throwable $t) {
            return self::fail($t->getMessage());
        }

        if ($checkProposals) {
            return self::emit_check($manifest, $json);
        }
        if ($gapReport) {
            return self::emit_gap_report($manifest, $name, $json);
        }

        if ($outPath !== null) {
            // Canonical bytes, exactly what --format=json prints: the file an
            // author edits is the file `duo manifest-validate` and
            // `Policy::load()` will read, so it is written in the form both
            // demand rather than a pretty one they refuse.
            $encoded = Canon::encode($manifest);
            if (!self::write_atomic($outPath, $encoded)) {
                return self::fail("cannot write the draft to $outPath");
            }
            echo "wrote $outPath (" . strlen($encoded) . " bytes)\n";
            $relative = str_starts_with($outPath, $resolved . '/')
                ? substr($outPath, strlen($resolved) + 1)
                : $outPath;
            echo "\nThis is a DRAFT. Everything under `_draft` is inert — Policy::load() never applies a\n"
                . "proposal. Read each candidate's questions, promote what you ratify into the real\n"
                . "sections by hand, delete the rest, then:\n";
            if (str_starts_with($relative, AdapterSources::SITE_DIR . '/')) {
                // The draft is INSTALLED, so the command that judges it is the
                // one that reads the site source. `manifest-validate <dir>`
                // would point DUO_MANIFESTS_DIR at the same adapters/ the
                // --site half also scans, and the engine correctly refuses
                // that as a site adapter shadowing a "shipped" one — a
                // refusal about the invocation, not about the draft.
                echo "  duo adapter inspect $name --repo=$resolved\n";
                echo "  duo adapter certify $resolved --name=$name --secret-key-file=<key> --pin\n";
            } else {
                echo '  duo manifest-validate ' . dirname($outPath) . " --site=$resolved --manifest=$name\n";
            }
            return 0;
        }
        if ($json) {
            echo rtrim(Canon::encode($manifest)) . "\n";
        } else {
            self::render($manifest, $resolved);
        }
        return 0;
    }

    /**
     * Write the draft atomically so a crash mid-write cannot leave an author
     * holding a half-file that `manifest-validate` then blames them for.
     */
    private static function write_atomic(string $path, string $contents): bool {
        $temporary = tempnam(dirname($path), '.duo-adapter-draft-');
        if ($temporary === false) {
            return false;
        }
        $ok = file_put_contents($temporary, $contents, LOCK_EX) !== false
            && chmod($temporary, 0644)
            && rename($temporary, $path);
        if (is_file($temporary)) {
            unlink($temporary);
        }
        return $ok;
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
        $repo = dirname(__DIR__, 3);
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
        $duoAgentClassmap = require $repo . '/agent/duo-classmap.php';
        if (!is_array($duoAgentClassmap)) {
            throw new \RuntimeException('adapter-draft: agent/duo-classmap.php did not return a map');
        }
        $duoAgentFiles = [];
        foreach ($duoAgentClassmap as $duoAgentPath) {
            $duoAgentFiles[basename((string) $duoAgentPath, '.php')] = (string) $duoAgentPath;
        }
        foreach (['Canon', 'OptionState', 'ManifestDispositions', 'Policy', 'Deletion', 'Secrets'] as $class) {
            $duoAgentFile = $duoAgentFiles[$class] ?? null;
            if (!is_string($duoAgentFile)) {
                throw new \RuntimeException('adapter-draft: agent source ' . $class . '.php is absent from agent/duo-classmap.php');
            }
            require_once $repo . '/agent/' . $duoAgentFile;
        }
    }

    // -------------------------------------------------------- prior-artifact merge

    /**
     * Read the optional saved adapter once, before any regeneration work. A prior
     * artifact is input to preservation, not a best-effort hint: unreadable,
     * malformed, or name-mismatched input cannot be safely merged, so refusing is
     * safer than returning an output that silently dropped its author's intent.
     *
     * @return array<string,mixed>|null
     */
    private static function read_prior_manifest(string $repo, string $name): ?array {
        $path = $repo . '/adapters/' . $name . '.json';
        // file_exists() is false for a dangling symlink. Treat one as a prior
        // artifact that cannot be read, not as an absent file whose intent may
        // be silently discarded.
        if (!file_exists($path) && !is_link($path)) {
            return null;
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException("adapter-draft: prior adapter '$path' is not a readable regular file");
        }
        $prior = Canon::decode(Canon::read_file($path));
        if (!is_array($prior) || array_is_list($prior)) {
            throw new \RuntimeException("adapter-draft: prior adapter '$path' must be a JSON object");
        }
        if (!isset($prior['name']) || !is_string($prior['name']) || $prior['name'] !== $name) {
            throw new \RuntimeException(
                "adapter-draft: prior adapter '$path' must declare name '$name' exactly; refusing to merge a different adapter"
            );
        }
        foreach (self::FACT_SECTIONS as $section) {
            if (array_key_exists($section, $prior)
                && (!is_array($prior[$section]) || ($prior[$section] !== [] && array_is_list($prior[$section])))) {
                throw new \RuntimeException(
                    "adapter-draft: prior adapter '$path' $section must be an object when present"
                );
            }
        }
        if (array_key_exists('_draft', $prior)
            && (!is_array($prior['_draft']) || ($prior['_draft'] !== [] && array_is_list($prior['_draft'])))) {
            throw new \RuntimeException("adapter-draft: prior adapter '$path' _draft must be an object when present");
        }
        return $prior;
    }

    /**
     * Validate the saved artifact through the real Policy grammar before it can be
     * preserved. `Policy::export_manifest()` loads only the site's currently pinned
     * manifests, whereas the prior file can legitimately be the adapter currently
     * being drafted and not pinned yet. Start with the site's raw pins so their
     * declared digest/source constraints and normal source precedence are retained,
     * then append the prior name as an explicitly site-sourced pin only when it is
     * absent. Policy::load() consequently checks this exact on-disk artifact in its
     * actual dependency context (actions, providers, classifications, ref kinds,
     * and cross-manifest invariants) rather than treating a JSON-shaped file as
     * preservation-safe.
     */
    private static function validate_prior_manifest(string $repo, string $name): void {
        $site = self::read_json($repo . '/site.duo.json');
        $pins = $site['manifests'] ?? ['core'];
        if (!is_array($pins) || !array_is_list($pins)) {
            throw new \RuntimeException(
                'adapter-draft: site.duo.json manifests must be a JSON list before a prior adapter can be validated'
            );
        }

        $alreadyPinned = false;
        foreach ($pins as $pin) {
            if ($pin === $name) {
                $alreadyPinned = true;
                break;
            }
            if (is_array($pin) && (($pin['name'] ?? null) === $name)) {
                $alreadyPinned = true;
                break;
            }
        }
        if (!$alreadyPinned) {
            // A prior file lives in the site repo. Naming its source explicitly
            // prevents any lower-precedence shipped/plugin artifact from being
            // silently validated in its place; no digest is fabricated because it
            // is not a current site pin.
            $pins[] = ['name' => $name, 'source' => 'site'];
        }
        Policy::load($repo, $pins);
    }

    /**
     * Merge a fresh policy export with a saved artifact under the closed ownership
     * rule above. Non-owned top-level declarations are copied as whole values; they
     * are never deep-merged or normalized by this command. Fact rules are more
     * conservative: a fresh rule adds only when absent/equal, while a differing
     * prior rule stays authoritative and gets a redacted, inert conflict record.
     *
     * @param array<string,mixed> $fresh
     * @param array<string,mixed>|null $prior
     * @return array{0:array<string,mixed>,1:list<array<string,mixed>>}
     */
    private static function merge_prior_manifest_intent(array $fresh, ?array $prior): array {
        foreach (array_keys($fresh) as $key) {
            if (!is_string($key) || !isset(self::OWNED_TOP_LEVEL[$key])) {
                throw new \RuntimeException(
                    "adapter-draft: Policy::export_manifest() returned unowned top-level key '"
                    . (string) $key . "'; assign explicit generator ownership before regeneration"
                );
            }
        }
        if ($prior === null) {
            return [$fresh, []];
        }

        $out = $fresh;
        $conflicts = [];
        foreach (self::FACT_SECTIONS as $section) {
            $freshRules = (array) ($fresh[$section] ?? []);
            $priorRules = (array) ($prior[$section] ?? []);
            foreach ($priorRules as $key => $priorRule) {
                if (!is_string($key)) {
                    throw new \RuntimeException(
                        "adapter-draft: prior adapter $section has a non-string rule key; refusing an ambiguous merge"
                    );
                }
                if (!array_key_exists($key, $freshRules)) {
                    // A graduated decision must not disappear merely because a
                    // later site's local policy no longer echoes it.
                    $freshRules[$key] = $priorRule;
                    continue;
                }
                if (self::semantic_hash($priorRule) === self::semantic_hash($freshRules[$key])) {
                    continue;
                }

                // The prior artifact is the human-edited source at this surface.
                // Keep it and expose the competing export only as hashes: a rule
                // must never be copied under _draft where `ref`/etc. could wake a
                // blind validator walk, and values have no place in conflict prose.
                $freshRule = $freshRules[$key];
                $freshRules[$key] = $priorRule;
                $conflict = self::fact_conflict($section, $key, $priorRule, $freshRule);
                $target = (string) $conflict['target'];
                if (isset($conflicts[$target])) {
                    throw new \RuntimeException(
                        "adapter-draft: classification conflict identity '$target' is ambiguous; refusing to overwrite either rule"
                    );
                }
                $conflicts[$target] = $conflict;
            }
            $out[$section] = (object) $freshRules;
        }

        foreach ($prior as $key => $value) {
            if (!is_string($key)) {
                throw new \RuntimeException('adapter-draft: prior adapter has a non-string top-level key');
            }
            if (isset(self::OWNED_TOP_LEVEL[$key])) {
                continue;
            }
            // Fresh was checked against the closed ownership list above, so this
            // assignment cannot overwrite a newly generated declaration.
            $out[$key] = $value;
        }
        ksort($conflicts, SORT_STRING);
        return [$out, array_values($conflicts)];
    }

    /** A stable, redacted conflict record whose key cannot collide by concatenation. */
    private static function fact_conflict(string $section, string $key, mixed $prior, mixed $fresh): array {
        $surface = ['kind' => 'classification', 'section' => $section, 'key' => $key];
        $target = 'conflicts.classification.' . hash('sha256', Canon::encode($surface));
        return [
            'target' => $target,
            'status' => 'conflict',
            'surface' => $section . '.' . $key,
            'prior_hash' => self::semantic_hash($prior),
            'export_hash' => self::semantic_hash($fresh),
            'questions' => [
                'the current policy export differs from an existing hand-authored classification; the existing '
                    . 'rule was preserved and the competing rule remains only this inert, redacted conflict. '
                    . 'Reconcile the two declarations by hand before changing either source of truth',
            ],
        ];
    }

    /** Canonical semantic equality/hash, deliberately never a raw-byte claim. */
    private static function semantic_hash(mixed $value): string {
        return hash('sha256', Canon::encode($value));
    }

    /**
     * The candidate-level screen cannot cover preserved top-level intent: `notes`,
     * a provider argument, or a future non-generator-owned declaration is copied
     * deliberately and exactly. Before emitting the complete regenerated artifact,
     * scan every key and value for a high-confidence secret pattern. Refuse rather
     * than redact: changing a semantic declaration silently would violate the
     * ownership contract, while printing it would violate the secret boundary.
     *
     * The existing candidate screen remains more conservative (it also flags the
     * name-aware heuristic); this final gate is hard-pattern-only so ordinary human
     * prose cannot become an unreviewable false-positive preservation loss.
     *
     * @param array<string,mixed> $manifest
     */
    private static function assert_output_is_safe(array $manifest): void {
        $label = self::output_hard_secret_label($manifest);
        if ($label !== null) {
            throw new \RuntimeException(
                "adapter-draft: regenerated artifact contains a possible secret ($label); refusing to emit or silently redact semantic intent"
            );
        }
        if (self::contains_executable_stub($manifest)) {
            throw new \RuntimeException(
                'adapter-draft: regenerated artifact contains executable/interpreter semantics; refusing to emit or silently alter semantic intent'
            );
        }
        if (self::contains_entity_local_coordinate($manifest)) {
            throw new \RuntimeException(
                'adapter-draft: regenerated artifact contains an entity-local UUID/path; refusing to emit or silently alter semantic intent'
            );
        }
    }

    /** Walk both map keys and leaves; fresh export sections use stdClass for `{}`. */
    private static function output_hard_secret_label(mixed $value): ?string {
        if (is_string($value)) {
            return Secrets::hard_match($value);
        }
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (!is_array($value)) {
            return null;
        }
        foreach ($value as $key => $child) {
            if (is_string($key)) {
                $keyLabel = Secrets::hard_match($key);
                if ($keyLabel !== null) {
                    return $keyLabel;
                }
            }
            $label = self::output_hard_secret_label($child);
            if ($label !== null) {
                return $label;
            }
        }
        return null;
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
    private static function build_draft(
        string $repo,
        string $name,
        ?array $probe,
        ?array $priorDraft,
        array $factConflicts,
        ?array $seed = null,
        string $match = '.*'
    ): array {
        $stateDir = $repo . '/state';

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
            // The one proposer whose input is another duo command's OUTPUT
            // rather than the repository's captured state. It is last so a
            // seeded candidate loses a target collision to an observation of
            // real captured bytes — a table Duo already captured is better
            // evidence than a table Duo merely knows exists.
            self::propose_from_seed($seed, $match),
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
            $target = (string) ($candidate['target'] ?? '');
            if ($target === '') {
                throw new \RuntimeException('adapter-draft: proposer emitted a candidate with no target');
            }
            // Every proposal identity is structural. A duplicate here is an
            // ambiguous attachment, never a safe last-wins replacement — with
            // one stated exception: a --seed candidate YIELDS to an
            // observation of the same target. The seed's input is another
            // command's summary of the live site; the observers' input is the
            // repository's own captured bytes. Where both describe one surface
            // the captured bytes say strictly more, so this is a precedence
            // rule between two known sources rather than the ambiguity the
            // refusal above exists for.
            if (isset($candidates[$target])) {
                if (($candidate['_seeded'] ?? false) === true) {
                    continue;
                }
                if (($candidates[$target]['_seeded'] ?? false) === true) {
                    $candidates[$target] = $candidate;
                    continue;
                }
                throw new \RuntimeException(
                    "adapter-draft: proposal identity '$target' collided; refusing to attach either observation"
                );
            }
            $candidates[$target] = $candidate;
        }

        // Human-edit preservation across re-observation (Decision 4), computed
        // against the one already-validated prior artifact, if one was saved.
        [$candidates, $meta] = self::preserve_human_edits($priorDraft, $candidates);

        // Live answers land AFTER preservation, on the final candidate set, so
        // a candidate a human already ratified gets its live facts too. Only
        // `evidence[]` is touched — never the fragment — so `generated_hash`
        // (computed over `candidate['candidate']` at :2236) does not move and
        // an --evidence run cannot make a candidate look hand-edited.
        [$candidates, $probeSeam] = self::apply_probe($candidates, $probe);
        ksort($candidates, SORT_STRING);

        $proposals = array_fill_keys(self::BUCKETS, []);
        $unsupported = [];
        foreach ($candidates as $candidate) {
            // `_seeded` is the precedence marker the collision rule above
            // reads; it is an internal fact about where a candidate came
            // from, not something an author ratifies, so it never reaches the
            // artifact. The seed itself is recorded once in `_meta`.
            unset($candidate['_seeded']);
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
        if ($factConflicts !== []) {
            // These records contain only a stable surface label and canonical
            // hashes. In particular they never mirror a live `ref`/`refs` key
            // into _draft, so the sidecar remains inert under Policy's blind walk.
            $draft['classification_conflicts'] = $factConflicts;
        }
        // The live seam, recorded (nested — not a reserved top-level key) so a
        // reader of a committed draft can tell whether any candidate below was
        // answered from a target, and how much of the document applied.
        $draft['evidence_seam'] = $probeSeam;
        // The seed is recorded for the same reason: a reader of a committed
        // draft must be able to tell which candidates came from the
        // repository's own captured bytes and which came from a report about
        // a live site — and, when a coverage-only seed was used, that no
        // post-type family could be seeded at all.
        $draft['seed'] = $seed === null
            ? 'no --seed given; candidates come only from this repository\'s captured state/**'
            : ((string) ($seed['format'] ?? 'unknown') . ' — option prefixes and undeclared tables seeded'
                . (($seed['format'] ?? null) === self::SEED_INVENTORY_FORMAT
                    ? ', scope-gate post types seeded'
                    : '; scope-gate post types NOT seeded (coverage reports no post types — seed from '
                        . self::SEED_INVENTORY_FORMAT . ' for those)'));
        return $draft;
    }

    // ------------------------------------------------------- live probe seam

    /**
     * A deferral a probe CAN close, written `[<name>] <prose>`.
     *
     * Going through this helper is what keeps the two halves of the seam on
     * one vocabulary: a proposer cannot invent a live-question name no
     * evidence row will ever carry, and apply_probe() applies the same
     * membership test from the other side.
     */
    private static function live_question(string $name, string $prose): string {
        if (!isset(self::PROBE_QUESTIONS[$name])) {
            throw new \RuntimeException("adapter-draft: '$name' is not a live question a probe can answer");
        }
        return "[$name] $prose";
    }

    /**
     * Read and STRUCTURALLY VALIDATE a `duo-adapter-probe/v1` document.
     *
     * Every refusal here is the same refusal: a live-evidence file must not be
     * able to say anything an author would mistake for a decision. So the
     * envelope is checked by name, `authority` must be literally false, each
     * per-table object is closed over PROBE_TABLE_KEYS (which contains no
     * `class`, `identity`, `deletions` or capability word), every identifier
     * must match the portable grammar the emitter enforced, and the canonical
     * `probe_hash` must still describe the bytes — so a hand-written "fact"
     * pasted into an emitted document is refused rather than attached at
     * confidence 1.0.
     *
     * @return array<string,mixed>
     */
    private static function read_probe(string $path): array {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException("adapter-draft: --evidence '$path' is not a JSON object");
        }
        if (($decoded['format'] ?? null) !== self::PROBE_FORMAT) {
            throw new \RuntimeException(
                "adapter-draft: --evidence '$path' is not a " . self::PROBE_FORMAT
                . ' document (`wp duo adapter-probe --format=json`)'
            );
        }
        if (($decoded['authority'] ?? null) !== false) {
            throw new \RuntimeException(
                'adapter-draft: --evidence document must declare authority:false; a probe reports live facts and '
                . 'decides nothing'
            );
        }
        $hash = $decoded['probe_hash'] ?? null;
        if (!is_string($hash) || preg_match('/^sha256:[0-9a-f]{64}$/D', $hash) !== 1) {
            throw new \RuntimeException('adapter-draft: --evidence document has no canonical probe_hash');
        }
        $basis = $decoded;
        unset($basis['probe_hash']);
        if (!hash_equals($hash, 'sha256:' . hash('sha256', Canon::encode($basis)))) {
            throw new \RuntimeException(
                'adapter-draft: --evidence probe_hash does not describe the document; re-run `wp duo adapter-probe` '
                . 'rather than editing an evidence file by hand'
            );
        }
        $tables = $decoded['tables'] ?? null;
        if (!is_array($tables) || (array_is_list($tables) && $tables !== [])) {
            throw new \RuntimeException('adapter-draft: --evidence document has no `tables` object');
        }
        $validated = [];
        foreach ($tables as $table => $facts) {
            self::assert_probe_identifier((string) $table);
            if (!is_array($facts) || array_is_list($facts)) {
                throw new \RuntimeException("adapter-draft: --evidence table '$table' is not an object");
            }
            $unknown = array_diff(array_keys($facts), self::PROBE_TABLE_KEYS);
            if ($unknown !== []) {
                // The load-bearing refusal: `class` (or any other word this
                // list does not contain) is exactly what a probe may not say.
                throw new \RuntimeException(
                    "adapter-draft: --evidence table '$table' carries key(s) outside the closed probe vocabulary: "
                    . implode(', ', array_map('strval', $unknown))
                );
            }
            if (!is_bool($facts['present'] ?? null)) {
                throw new \RuntimeException("adapter-draft: --evidence table '$table' has no boolean `present`");
            }
            self::assert_probe_facts((string) $table, $facts);
            $validated[(string) $table] = $facts;
        }
        return ['format' => self::PROBE_FORMAT, 'probe_hash' => $hash, 'tables' => $validated];
    }

    /** Every name in a probe document is a server identifier, never prose or a value. */
    private static function assert_probe_identifier(string $value): void {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $value) !== 1) {
            throw new \RuntimeException(
                'adapter-draft: --evidence document names an identifier outside the portable grammar'
            );
        }
    }

    /**
     * The per-fact shapes. Types are bounded to the emitter's normalized form
     * (`agent/src/Adapter/AdapterProbe.php` normalized_type()) so an
     * `enum('draft','publish')` — a MySQL type string that carries SITE VALUES
     * inside it — cannot reach a draft through this seam either.
     *
     * @param array<string,mixed> $facts
     */
    private static function assert_probe_facts(string $table, array $facts): void {
        foreach ((array) ($facts['columns'] ?? []) as $column => $shape) {
            self::assert_probe_identifier((string) $column);
            $type = is_array($shape) ? ($shape['type'] ?? null) : null;
            if (!is_string($type) || preg_match('/^[a-z]+(\([0-9]+(,[0-9]+)?\))?( unsigned)?( zerofill)?$/D', $type) !== 1) {
                throw new \RuntimeException("adapter-draft: --evidence column '$table.$column' has no bounded MySQL type");
            }
            if (!is_bool($shape['nullable'] ?? null)) {
                throw new \RuntimeException("adapter-draft: --evidence column '$table.$column' has no boolean nullability");
            }
        }
        foreach ((array) ($facts['primary_key'] ?? []) as $column) {
            self::assert_probe_identifier((string) $column);
        }
        foreach ((array) ($facts['unique_keys'] ?? []) as $index => $columns) {
            self::assert_probe_identifier((string) $index);
            foreach ((array) $columns as $column) {
                self::assert_probe_identifier((string) $column);
            }
        }
        foreach ((array) ($facts['index_coverage'] ?? []) as $column => $coverage) {
            self::assert_probe_identifier((string) $column);
            $index = is_array($coverage) ? ($coverage['index'] ?? null) : null;
            if ($index !== null) {
                self::assert_probe_identifier((string) $index);
            }
            $prefix = is_array($coverage) ? ($coverage['prefix'] ?? null) : null;
            if ($prefix !== null && (!is_int($prefix) || $prefix < 1)) {
                throw new \RuntimeException("adapter-draft: --evidence index prefix on '$table.$column' is not a width");
            }
        }
        foreach ((array) ($facts['foreign_keys'] ?? []) as $column => $referenced) {
            self::assert_probe_identifier((string) $column);
            self::assert_probe_identifier((string) $referenced);
        }
        $twin = $facts['eav_twin'] ?? null;
        if ($twin !== null) {
            if (!is_array($twin) || !is_string($twin['table'] ?? null)) {
                throw new \RuntimeException("adapter-draft: --evidence eav_twin on '$table' is malformed");
            }
            foreach (['table', 'key_column', 'value_column'] as $key) {
                self::assert_probe_identifier((string) ($twin[$key] ?? ''));
            }
            if (($twin['parent_column'] ?? null) !== null) {
                self::assert_probe_identifier((string) $twin['parent_column']);
            }
        }
        $natural = $facts['natural_key'] ?? null;
        if ($natural !== null) {
            if (!is_array($natural)) {
                throw new \RuntimeException("adapter-draft: --evidence natural_key on '$table' is malformed");
            }
            self::assert_probe_identifier((string) ($natural['column'] ?? ''));
            foreach (['rows', 'distinct'] as $key) {
                if (!is_int($natural[$key] ?? null) || $natural[$key] < 0) {
                    throw new \RuntimeException("adapter-draft: --evidence natural_key.$key on '$table' is not a count");
                }
            }
            if (!is_bool($natural['unique'] ?? null)) {
                throw new \RuntimeException("adapter-draft: --evidence natural_key.unique on '$table' is not a boolean");
            }
        }
    }

    /**
     * Attach the probe's answers to the candidates that asked the questions.
     *
     * This is the whole consumption, and what it does NOT do is the point:
     * every row lands in `evidence[]` — structurally inert under Policy's
     * blind walk — and NOTHING here writes to `$candidate['candidate']`,
     * `status` or `confidence`. So a live PRIMARY KEY that disagrees with the
     * offline `pk` guess is REPORTED beside it and the guess stands until a
     * human changes it; a table the probe says is absent does not withdraw its
     * proposal; a UNIQUE index on a natural-key column does not promote the
     * identity mode. The candidate's own `confidence` stays the guess it was
     * (0.3/0.4); `1.0` belongs to the individual observed row, which is
     * certain in a way the proposal built around it is not.
     *
     * @param array<string,array<string,mixed>> $candidates
     * @return array{0:array<string,array<string,mixed>>,1:string}
     */
    private static function apply_probe(array $candidates, ?array $probe): array {
        if ($probe === null) {
            return [$candidates, 'no --evidence given; live-dependent facts below are proposals carrying the '
                . 'named questions a `' . self::PROBE_FORMAT . '` document answers'];
        }
        $applied = [];
        $unmatched = [];
        foreach ($probe['tables'] as $table => $facts) {
            $target = 'tables.' . $table;
            if (!isset($candidates[$target])) {
                // A probed table nobody proposed is not an error and is not
                // silently dropped either: it is the one thing a reader of the
                // seam needs to know about the run that a candidate cannot say.
                $unmatched[] = $table;
                continue;
            }
            foreach (self::probe_evidence_rows((string) $table, $facts) as $row) {
                // The two halves of the seam share ONE vocabulary or they are
                // not a seam: a row naming a question no proposer can ask is a
                // fact attached to nothing, and it fails here rather than
                // shipping as an unsolicited assertion inside a draft.
                if (!isset(self::PROBE_QUESTIONS[(string) $row['question']])) {
                    throw new \RuntimeException(
                        "adapter-draft: probe evidence names question '{$row['question']}', which is outside the "
                        . 'closed live-question vocabulary'
                    );
                }
                $candidates[$target] = self::add_draft_evidence($candidates[$target], $row);
            }
            $applied[] = $table;
        }
        sort($applied, SORT_STRING);
        sort($unmatched, SORT_STRING);
        $seam = self::PROBE_FORMAT . ' consumed (' . $probe['probe_hash'] . '): live facts landed as evidence rows '
            . 'at confidence 1.0 answering the named questions of ' . count($applied) . ' table candidate(s)'
            . ($applied === [] ? '' : ' [' . implode(', ', $applied) . ']')
            . '; the probe proposed no class, identity or deletion authority and promoted nothing';
        if ($unmatched !== []) {
            $seam .= '. Probed but not proposed here: ' . implode(', ', $unmatched);
        }
        return [$candidates, $seam];
    }

    /**
     * One table's facts as evidence rows, each naming the question it answers.
     *
     * `question` is a name from PROBE_QUESTIONS, not a copy of the prose: the
     * prose can be rewritten for a human without breaking the attachment, and
     * a reviewer reading `question: table_schema` beside a `[table_schema]`
     * deferral can see which of them is closed.
     *
     * @param array<string,mixed> $facts
     * @return list<array<string,mixed>>
     */
    private static function probe_evidence_rows(string $table, array $facts): array {
        $locator = static fn(string $tail): string => 'tables.' . $table . '.' . $tail;
        if (($facts['present'] ?? false) !== true) {
            return [[
                'confidence' => 1.0,
                'locator' => $locator('present'),
                'observation' => 'this target has no such table; the proposal stands and is unratifiable here',
                'question' => 'table_schema',
                'source' => self::PROBE_FORMAT,
            ]];
        }

        $columns = (array) ($facts['columns'] ?? []);
        $shapes = [];
        foreach ($columns as $column => $shape) {
            $shapes[(string) $column] = $shape['type'] . ' ' . (($shape['nullable'] ?? false) ? 'NULL' : 'NOT NULL');
        }
        $primaryKey = array_map('strval', (array) ($facts['primary_key'] ?? []));
        $rows = [[
            'columns' => $shapes,
            'confidence' => 1.0,
            'locator' => $locator('columns'),
            'observation' => 'live SHOW COLUMNS type and nullability for ' . count($shapes) . ' column(s)',
            'question' => 'table_schema',
            'source' => self::PROBE_FORMAT,
        ], [
            'confidence' => 1.0,
            'locator' => $locator('pk'),
            'observation' => $primaryKey === []
                ? 'the live table declares NO PRIMARY KEY'
                : 'the live PRIMARY KEY is (' . implode(', ', $primaryKey) . ')',
            'primary_key' => $primaryKey,
            'question' => 'table_schema',
            'source' => self::PROBE_FORMAT,
        ]];

        $coverage = (array) ($facts['index_coverage'] ?? []);
        $covered = [];
        foreach ($coverage as $column => $entry) {
            if (($entry['index'] ?? null) !== null) {
                $covered[(string) $column] = (string) $entry['index']
                    . ($entry['prefix'] === null ? '' : '(' . $entry['prefix'] . ')');
            }
        }
        $rows[] = [
            'confidence' => 1.0,
            'covering_indexes' => $covered,
            'locator' => $locator('index_coverage'),
            'observation' => count($covered) . ' of ' . count($coverage) . ' column(s) are the FIRST column of some '
                . 'index, which is the coverage DeleteGuardEvaluator::lock_index() resolves; a prefix width in '
                . 'parentheses is that index\'s Sub_part',
            'question' => 'lock_index',
            'source' => self::PROBE_FORMAT,
        ];

        $uniqueKeys = (array) ($facts['unique_keys'] ?? []);
        $rendered = [];
        foreach ($uniqueKeys as $index => $indexColumns) {
            $rendered[(string) $index] = implode(', ', array_map('strval', (array) $indexColumns));
        }
        $rows[] = [
            'confidence' => 1.0,
            'locator' => $locator('unique_keys'),
            'observation' => $rendered === []
                ? 'the live table declares no UNIQUE key besides its PRIMARY KEY'
                : 'live UNIQUE key(s): ' . implode('; ', array_map(
                    static fn(string $index, string $cols): string => "$index($cols)",
                    array_keys($rendered),
                    array_values($rendered)
                )),
            'question' => 'natural_key_uniqueness',
            'source' => self::PROBE_FORMAT,
            'unique_keys' => $rendered,
        ];

        $natural = $facts['natural_key'] ?? null;
        if (is_array($natural)) {
            $rows[] = [
                'confidence' => 1.0,
                'distinct' => (int) $natural['distinct'],
                'locator' => $locator('natural_key.' . $natural['column']),
                'observation' => 'COUNT(*) ' . $natural['rows'] . ' vs COUNT(DISTINCT `' . $natural['column'] . '`) '
                    . $natural['distinct'] . ' across the whole live keyspace — '
                    . ($natural['unique'] ? 'unique' : 'NOT unique')
                    . ' (measured in the column\'s own collation, NULL rows excluded)',
                'question' => 'natural_key_uniqueness',
                'row_count' => (int) $natural['rows'],
                'source' => self::PROBE_FORMAT,
                'unique' => (bool) $natural['unique'],
            ];
        }

        $foreignKeys = (array) ($facts['foreign_keys'] ?? []);
        $rows[] = [
            'confidence' => 1.0,
            'foreign_keys' => array_map('strval', $foreignKeys),
            'locator' => $locator('foreign_keys'),
            'observation' => $foreignKeys === []
                ? 'the live table declares no FOREIGN KEY'
                : count($foreignKeys) . ' live FOREIGN KEY constraint(s); this is context for a deletion decision, '
                    . 'never authority for one',
            'question' => 'foreign_keys',
            'source' => self::PROBE_FORMAT,
        ];

        $twin = $facts['eav_twin'] ?? null;
        $rows[] = [
            'confidence' => 1.0,
            'locator' => $locator('eav_twin'),
            'observation' => is_array($twin)
                ? 'live EAV twin `' . $twin['table'] . '` (' . ($twin['parent_column'] ?? 'no parent column') . ' / '
                    . $twin['key_column'] . ' / ' . $twin['value_column'] . '); declaring the parent alone captures '
                    . 'half the entity'
                : 'this target has no EAV twin for the table',
            'question' => 'eav_twin',
            'source' => self::PROBE_FORMAT,
            'twin' => is_array($twin) ? (string) $twin['table'] : null,
        ];

        return $rows;
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
        if (in_array($head, [
            'tables', 'block_paths', 'deletions', 'actions', 'providers',
            'option_namespaces', 'option_patterns', 'post_types',
        ], true)) {
            return $head;
        }
        // Meta-ref candidates carry a section-shaped head
        // (post_meta/term_meta/options/user_meta), not a generic catch-all.
        if (in_array($head, ['options', 'post_meta', 'term_meta', 'user_meta'], true)) {
            return 'references';
        }
        throw new \RuntimeException(
            "adapter-draft: proposal target '$target' has no supported v1 target family"
        );
    }

    /**
     * One block/shortcode can expose several independent reference-bearing
     * attributes. Bind each proposal identity to the owner plus attribute path
     * so neither PHP/JSON iteration order nor a newly observed sibling can make
     * one candidate overwrite another.
     */
    private static function attribute_target(string $kind, string $owner, string $path): string {
        $surface = ['kind' => $kind, 'owner' => $owner, 'path' => $path];
        return $kind . '.' . $owner . '.attrs.' . hash('sha256', Canon::encode($surface));
    }

    /** Resolve and authenticate a new structural attribute target; accept v1 legacy targets. */
    private static function attribute_owner(string $kind, string $tail, mixed $fragment): ?string {
        $marker = '.attrs.';
        if (!str_contains($tail, $marker)) {
            return $tail === '' ? null : $tail;
        }
        [$owner, $digest] = explode($marker, $tail, 2);
        if ($owner === '' || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
            || !is_array($fragment) || !is_string($fragment['path'] ?? null)) {
            return null;
        }
        return hash_equals(self::attribute_target($kind, $owner, $fragment['path']), $kind . '.' . $tail)
            ? $owner
            : null;
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
            // Every deferral a `duo-adapter-probe/v1` document can close is
            // written `[<name>] …` (see PROBE_QUESTIONS): the name is what an
            // incoming evidence row answers, so the two halves of the seam
            // cannot drift into two different vocabularies for one question.
            $questions = [
                self::live_question(
                    'table_schema',
                    'column TYPES, the real PRIMARY KEY, and nullability are live facts (SHOW COLUMNS) — deferred; '
                    . 'confirm against a live target before ratifying'
                    . ($pk !== null ? " (pk='$pk' is a structural guess)" : ' (no PK inferable offline)')
                ),
                // lock_index() resolves the covering index by FIRST column and
                // compares a meta_key length against Sub_part
                // (agent/src/Delete/DeleteGuardEvaluator.php:445-456). Neither
                // fact is in a captured row file, and a deletion candidate
                // ratified without them proposes a guard that cannot lock.
                self::live_question(
                    'lock_index',
                    'whether a delete guard can lock this table on its predicate column — the covering index and '
                    . 'its prefix width in DeleteGuardEvaluator::lock_index()\'s own terms — is a live SHOW INDEX '
                    . 'fact; deferred'
                ),
                // Presence is context for a deletion decision. Cascade
                // authority is still never inferred (see propose_deletions).
                self::live_question(
                    'foreign_keys',
                    'declared FOREIGN KEY constraints are live facts and are deferred; note that they remain '
                    . 'context for a deletion decision, never authority for one'
                ),
                // A draft that declares the parent and misses its EAV sidecar
                // captures half an entity, and no state/tables/** row file
                // names the sidecar.
                self::live_question(
                    'eav_twin',
                    'whether this table has an EAV twin (a `<table>meta` sidecar holding its per-row key/value '
                    . 'pairs) is a live fact — deferred; a ratified parent without its twin captures half the entity'
                ),
            ];
            if ($naturalKey !== null) {
                $evidence[] = [
                    'source' => 'state/tables/' . $table,
                    'locator' => 'columns.' . $naturalKey,
                    'observation' => "values distinct across $rows sampled row(s) — candidate natural key",
                ];
                $questions[] = self::live_question(
                    'natural_key_uniqueness',
                    "natural-key uniqueness for column '$naturalKey' needs live keyspace enumeration — deferred; "
                    . 'confidence is bounded offline'
                );
            }

            // Each observed value carried under its REAL column name, so the
            // heuristic screen (Secrets::suspicious, which keys on the NAME) can
            // fire on a credential-named column, not just hard_match.
            $screen = [];
            foreach ($columnValues as $col => $vals) {
                foreach ($vals as $v) {
                    $screen[] = [(string) $col, $v];
                }
            }
            $out[] = [
                'target' => 'tables.' . $table,
                'candidate' => $fragment,
                'status' => 'proposal',
                'confidence' => $naturalKey !== null ? 0.4 : 0.3,
                'evidence' => $evidence,
                'questions' => $questions,
                '_screen' => $screen,
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------ seed proposer

    /**
     * Candidates seeded from another duo command's report (T6 §3.5).
     *
     * This is the one proposer whose evidence is not the repository's own
     * captured bytes. The reason it exists is the gap `duo coverage` names
     * and nothing closes: an option prefix invisible to every installed
     * adapter, and a live table no manifest declares, are exactly the
     * surfaces an operator is authoring an adapter FOR — and they are the
     * surfaces the offline observers cannot see, because Duo never captured
     * them. Coverage saw them on the live site; this turns each into a
     * candidate the author ratifies by hand, with the observation quoted.
     *
     * Names and counts only, exactly as coverage publishes them. No value
     * from the seed reaches a fragment, so the secret screen has nothing new
     * to catch here — which is a property of the input, not a skipped check:
     * every candidate still goes through `screen_secrets()` with the rest.
     *
     * Confidence is deliberately low across the board. A prefix grouping is
     * `Coverage::guess_prefix()`'s heuristic, an undeclared table's class is
     * unknown by construction, and a scope-gate post type is a name the site
     * refused to capture rather than one it classified.
     *
     * @param array<string,mixed>|null $seed a coverage or assess-inventory document
     * @return list<array<string,mixed>>
     */
    private static function propose_from_seed(?array $seed, string $match = '.*'): array {
        if ($seed === null) {
            return [];
        }
        // An inventory embeds the whole coverage report under `coverage`; a
        // coverage report IS the report. Both spellings are read here so a
        // caller can seed from whichever document they already have.
        $coverage = ($seed['format'] ?? null) === self::SEED_INVENTORY_FORMAT
            ? (is_array($seed['coverage'] ?? null) ? $seed['coverage'] : [])
            : $seed;
        $out = [];
        // `--match` scopes the seed the way it scopes the exported rules: an
        // author drafting the wpforms adapter wants wpforms' family, not the
        // WordPress default-option prefixes coverage also cannot attribute to
        // any active plugin (the T6 walk read a draft proposing `admin`,
        // `blog`, `avatar`… beside `wpforms`). DUO-3505 shrank that set but
        // not to zero: `admin` and `blog` came from names manifests/core.json
        // declares by name and coverage no longer reports them invisible at
        // all, while `avatar_default`, `upload_path` and their siblings sit
        // outside core.json's 19 exact declarations, so they are genuinely
        // undeclared and still noise in somebody else's adapter draft.
        // A prefix or table is kept when the operator's pattern matches it as
        // an option name would spell it (`<prefix>_`); an unscoped draft
        // keeps everything, as before.
        $keeps = static function (string $name) use ($match): bool {
            return $match === '.*' || $match === ''
                || @preg_match('/' . $match . '/', $name) === 1
                || @preg_match('/' . $match . '/', $name . '_') === 1;
        };

        foreach (($coverage['options']['invisible_groups'] ?? []) as $group) {
            if (!is_array($group) || !is_string($group['prefix'] ?? null) || $group['prefix'] === '') {
                continue;
            }
            $prefix = $group['prefix'];
            if (!$keeps($prefix)) {
                continue;
            }
            // `guess_prefix()` strips a leading underscore for GROUPING only,
            // so the namespace has to match both spellings or every private
            // option in the family stays invisible after the author ratifies.
            $regex = '^_?' . preg_quote($prefix, '/') . '_';
            $count = is_int($group['count'] ?? null) ? $group['count'] : 0;
            $owner = is_string($group['probable_owner'] ?? null) ? $group['probable_owner'] : null;
            $observation = "coverage reported $count option name(s) under prefix '$prefix' that no installed "
                . 'adapter can see'
                . ($owner === null ? '' : ", probably owned by the active plugin '$owner'");

            $out[] = [
                'target' => 'option_namespaces[' . $prefix . ']',
                'candidate' => ['match' => $regex],
                'status' => 'proposal',
                'confidence' => 0.35,
                'evidence' => [[
                    'source' => 'seed: ' . self::SEED_COVERAGE_FORMAT,
                    'locator' => 'options.invisible_groups[' . $prefix . ']',
                    'observation' => $observation,
                ]],
                'questions' => [
                    "claiming the '$prefix' namespace makes every option under it VISIBLE to this adapter, "
                    . 'which is not the same as classifying it: each name still needs a rule, an '
                    . 'option_patterns entry, or an interpreter, or it lands in the `duo pending` queue',
                    "confirm the regex '$regex' does not also match another plugin's options on this site",
                ],
                '_seeded' => true,
            ];
            $out[] = [
                'target' => 'option_patterns[' . $prefix . ']',
                'candidate' => ['class' => 'runtime', 'match' => $regex],
                'status' => 'proposal',
                'confidence' => 0.2,
                'evidence' => [[
                    'source' => 'seed: ' . self::SEED_COVERAGE_FORMAT,
                    'locator' => 'options.invisible_groups[' . $prefix . ']',
                    'observation' => $observation,
                ]],
                'questions' => [
                    "class 'runtime' is the SAFE default, not an observation: it excludes the whole family "
                    . 'from capture. Whatever in it is authored configuration must be narrowed to its own '
                    . 'authored pattern or exact option rule BEFORE this is ratified, or that configuration '
                    . 'stops being versioned',
                ],
                '_seeded' => true,
            ];
        }

        foreach (($coverage['tables']['undeclared'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            // `logical_name` is the unprefixed name a manifest declares;
            // `table` is the live prefixed name. A manifest section keys off
            // the logical one, so a row carrying only the live name cannot be
            // turned into a declaration — say so rather than guessing the
            // prefix off, which would be wrong on any site whose prefix is
            // itself a substring of the table name.
            $logical = is_string($row['logical_name'] ?? null) ? $row['logical_name'] : null;
            $live = is_string($row['table'] ?? null) ? $row['table'] : null;
            if ($logical === null || $logical === '' || !$keeps($logical)) {
                continue;
            }
            $rows = is_int($row['row_count'] ?? null) ? $row['row_count'] : 0;
            $owner = is_string($row['probable_owner'] ?? null) ? $row['probable_owner'] : null;
            $out[] = [
                'target' => 'tables.' . $logical,
                // `runtime` because nothing here is evidence of authorship.
                // An undeclared table is invisible to every adapter, so Duo
                // has never read a row of it; declaring it `authored` on that
                // basis would put live operational rows into the repository.
                'candidate' => ['class' => 'runtime'],
                'status' => 'proposal',
                'confidence' => 0.25,
                'evidence' => [[
                    'source' => 'seed: ' . self::SEED_COVERAGE_FORMAT,
                    'locator' => 'tables.undeclared[' . $logical . ']',
                    'observation' => 'coverage reported live table '
                        . ($live ?? $logical) . " with $rows row(s) that no manifest declares"
                        . ($owner === null ? '' : ", probably owned by the active plugin '$owner'"),
                ]],
                'questions' => [
                    "class 'runtime' is the SAFE default: it declares the table VISIBLE and excluded, which "
                    . 'is what stops it reading `unclassified / block` in assess. If this table holds '
                    . 'authored configuration, promote it to a typed authored class — and then its columns, '
                    . 'PRIMARY KEY and identity are LIVE facts this offline draft cannot supply',
                ],
                '_seeded' => true,
            ];
        }

        foreach (self::seed_scope_post_types($seed) as $postType => $observation) {
            if (!$keeps($postType)) {
                continue;
            }
            $out[] = [
                'target' => 'post_types.' . $postType,
                'candidate' => ['class' => 'runtime'],
                'status' => 'proposal',
                'confidence' => 0.25,
                'evidence' => [[
                    'source' => 'seed: ' . self::SEED_INVENTORY_FORMAT,
                    'locator' => 'pending.rows[scope:post_type:' . $postType . ']',
                    'observation' => $observation,
                ]],
                'questions' => [
                    "class 'runtime' records a deliberate EXCLUSION from capture — the same decision "
                    . "`duo classify <env> --set='scope:post_type:$postType=runtime'` writes site-locally, "
                    . 'but declared by this adapter so every site running it inherits it. Ratify '
                    . "'authored' instead only if these entities are content an operator edits and expects "
                    . 'to branch',
                ],
                '_seeded' => true,
            ];
        }

        return $out;
    }

    /**
     * Post types the scope gate refused to capture, from a seed rich enough to
     * carry them.
     *
     * `duo coverage` reports options and tables and nothing else, so a
     * coverage-only seed yields no post types at all — the fact is in the
     * `pending` queue, which only `duo-assess-inventory/v1` embeds. Returning
     * an empty map for a coverage seed is therefore correct rather than
     * lossy, and `render()` states which families a seed supplied.
     *
     * @param array<string,mixed> $seed
     * @return array<string,string> post type => observation
     */
    private static function seed_scope_post_types(array $seed): array {
        $out = [];
        foreach (($seed['pending']['rows'] ?? []) as $row) {
            if (!is_array($row) || ($row['section'] ?? null) !== 'scope'
                || !is_string($row['key'] ?? null)) {
                continue;
            }
            // `spec/repo-format.md`'s own spelling: `scope:post_type:book` in
            // the pending queue, `post_type:book` as the row key underneath.
            if (!str_starts_with($row['key'], 'post_type:')) {
                continue;
            }
            $postType = substr($row['key'], strlen('post_type:'));
            if ($postType === '' || preg_match('/^[a-zA-Z0-9_-]+$/D', $postType) !== 1) {
                continue;
            }
            $entities = $row['evidence']['entities'] ?? null;
            $out[$postType] = 'the capture scope gate refused this post type as unclassified'
                . (is_int($entities) ? " ($entities live entit(ies))" : '')
                . '; it is registered on the site and no pinned manifest declares it';
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
        $postFiles = glob($stateDir . '/posts/*/*.md') ?: [];
        sort($postFiles, SORT_STRING);
        foreach ($postFiles as $file) {
            try {
                [, $body] = Canon::parse_post_file(Canon::read_file($file));
            } catch (\Throwable) {
                continue;
            }
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
                            $index = $blockSeen[$key];
                            self::merge_id_shape_observation(
                                $out[$index],
                                $type,
                                "block '$block' attribute '$attr'"
                            );
                            $out[$index]['_screen'][] = [(string) $attr, $value];
                            continue;
                        }
                        $blockSeen[$key] = count($out);
                        $out[] = [
                            'target' => self::attribute_target('block_paths', $block, (string) $attr),
                            'candidate' => ['path' => (string) $attr, 'type' => $type, 'kind' => 'post'],
                            'status' => 'proposal',
                            'confidence' => 0.35,
                            'evidence' => [[
                                'count' => 1,
                                'shapes' => [$type],
                                'source' => 'state/posts',
                                'locator' => 'blocks.' . $block . '.attrs.' . $attr,
                                'observation' => 'id-shaped block attribute observed; shape=' . $type . '; values omitted',
                            ]],
                            'questions' => [
                                "ref kind for block '$block' attribute '$attr' (defaulted to post; may be term/tt/user) "
                                    . 'and whether the id resolves to a live entity are live facts — deferred',
                            ],
                            '_screen' => [[(string) $attr, $value]],
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
                                $index = $shortcodeSeen[$key];
                                self::merge_id_shape_observation(
                                    $out[$index],
                                    $type,
                                    "shortcode '$tag' attribute '$attrName'"
                                );
                                $out[$index]['_screen'][] = [$attrName, $attrVal];
                                continue;
                            }
                            $shortcodeSeen[$key] = count($out);
                            $out[] = [
                                'target' => self::attribute_target('shortcode_paths', $tag, $attrName),
                                'candidate' => ['path' => $attrName, 'type' => $type, 'kind' => 'post'],
                                'status' => 'proposal',
                                'confidence' => 0.3,
                                'evidence' => [[
                                    'count' => 1,
                                    'shapes' => [$type],
                                    'source' => 'state/posts',
                                    'locator' => 'shortcodes.' . $tag . '.' . $attrName,
                                    'observation' => 'id-shaped shortcode attribute observed; shape=' . $type . '; values omitted',
                                ]],
                                'questions' => [
                                    "ref kind for shortcode '$tag' attribute '$attrName' (defaulted to post) and live "
                                        . 'id resolution are deferred',
                                ],
                                '_screen' => [[$attrName, $attrVal]],
                            ];
                        }
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Aggregate one redacted id-shaped observation without allowing iteration
     * order to decide a scalar-vs-list rule. Once both shapes are observed there
     * is no safe default: emit an empty inert fragment, zero confidence, and an
     * explicit reconciliation question. Raw observed values remain only in the
     * private `_screen` input consumed by screen_secrets().
     *
     * @param array<string,mixed> $candidate
     */
    private static function merge_id_shape_observation(array &$candidate, string $shape, string $surface): void {
        $evidence = (array) ($candidate['evidence'][0] ?? []);
        $shapes = array_values(array_unique(array_merge(
            array_map('strval', (array) ($evidence['shapes'] ?? [])),
            [$shape]
        )));
        sort($shapes, SORT_STRING);
        $candidate['evidence'][0]['count'] = ((int) ($evidence['count'] ?? 0)) + 1;
        $candidate['evidence'][0]['shapes'] = $shapes;
        if (count($shapes) < 2) {
            return;
        }
        $candidate['candidate'] = (object) [];
        $candidate['confidence'] = 0.0;
        $candidate['evidence'][0]['observation'] = 'ambiguous id-shaped ' . $surface
            . ' observed; shapes=' . implode(',', $shapes) . '; values omitted';
        $question = 'both scalar and list id shapes were observed for ' . $surface
            . '; no reference rule is proposed until a human reconciles the structural ambiguity';
        $questions = (array) ($candidate['questions'] ?? []);
        if (!in_array($question, $questions, true)) {
            $questions[] = $question;
        }
        $candidate['questions'] = $questions;
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
            foreach ((array) ($front['meta'] ?? []) as $metaKey => $value) {
                $type = self::id_attr_type($value);
                if ($type === null) {
                    continue;
                }
                if (isset($seen[$metaKey])) {
                    $index = $seen[$metaKey];
                    self::merge_id_shape_observation(
                        $out[$index],
                        $type,
                        "post_meta key '$metaKey'"
                    );
                    $out[$index]['_screen'][] = [(string) $metaKey, $value];
                    continue;
                }
                $seen[$metaKey] = count($out);
                // A meta ref rule names its keyspace as a STRING kind (`ref: "post"`
                // or `"post[]"`), not an object — see ReferenceRules::value_rule().
                $ref = $type === 'int[]' ? 'post[]' : 'post';
                $out[] = [
                    'target' => 'post_meta.' . $metaKey,
                    'candidate' => ['ref' => $ref],
                    'status' => 'proposal',
                    'confidence' => 0.3,
                    'evidence' => [[
                        'count' => 1,
                        'shapes' => [$type],
                        'source' => 'state/post_meta',
                        'locator' => 'meta.' . $metaKey,
                        'observation' => 'id-shaped meta value observed; shape=' . $type
                            . '; value omitted; no declared ref',
                    ]],
                    'questions' => [
                        "does meta key '$metaKey' hold a post id (vs term/tt/user, or an unrelated count)? live id "
                            . 'resolution is deferred; kind defaulted to post',
                    ],
                    '_screen' => [[(string) $metaKey, $value]],
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
     * `match ($kind)` arms in Deletion::capability() (agent/src/Delete/Deletion.php). Kept
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
        /**
         * Keyed by a hash of ONLY the structural surface. The values are never
         * used to name a candidate: one observed row/post must not change which
         * saved human edit belongs to another row/post, and row filenames often
         * carry entity UUIDs that do not belong in an adapter artifact.
         *
         * @var array<string,array{surface:array<string,string>,id:string,capability:string,shapes:array<string,true>,screen:list<array{0:string,1:mixed}>,count:int}>
         */
        $observed = [];
        /** @var array<string,string> $providerIds provider id => generated target */
        $providerIds = [];
        /** @var array<string,string> $capabilities capability => generated target */
        $capabilities = [];
        $emit = function (array $surface, string $screenName, mixed $value) use (&$observed, &$providerIds, &$capabilities): void {
            if (!self::looks_generated($value)) {
                return;
            }
            $encodedSurface = Canon::encode($surface);
            $digest = hash('sha256', $encodedSurface);
            $target = 'unsupported.generated.' . $digest;
            // Both names fit the grammar of the declaration they illustrate.
            // The target keeps the full digest, while these shorter names are
            // separately collision-checked before any artifact is emitted.
            $providerId = 'gen-' . substr($digest, 0, 60);
            $capability = 'regenerate_' . substr($digest, 0, 53);
            foreach ([[$providerIds, $providerId, 'provider id'], [$capabilities, $capability, 'capability']] as [$seen, $name, $kind]) {
                if (isset($seen[$name]) && $seen[$name] !== $target) {
                    throw new \RuntimeException(
                        "adapter-draft: generated-surface $kind '$name' collides between structural surfaces; refusing to merge them"
                    );
                }
            }
            $providerIds[$providerId] = $target;
            $capabilities[$capability] = $target;

            if (isset($observed[$target])) {
                if (Canon::encode($observed[$target]['surface']) !== $encodedSurface) {
                    // A full target digest collision is fantastically unlikely,
                    // but treating it as impossible would turn it into last-wins.
                    throw new \RuntimeException(
                        "adapter-draft: generated-surface identity '$target' collides between structural surfaces; refusing to merge them"
                    );
                }
            } else {
                $observed[$target] = [
                    'surface' => $surface,
                    'id' => $providerId,
                    'capability' => $capability,
                    'shapes' => [],
                    'screen' => [],
                    'count' => 0,
                ];
            }
            // SHAPE only — never the observed bytes (a generated blob can embed
            // a credential no hard_match pattern would catch). Every raw value
            // still reaches screen_secrets() under its real surface-key name.
            $observed[$target]['shapes'][self::shape_descriptor($value)] = true;
            $observed[$target]['screen'][] = [$screenName, $value];
            $observed[$target]['count']++;
        };
        foreach (glob($stateDir . '/posts/*/*.md') ?: [] as $file) {
            try {
                [$front] = Canon::parse_post_file(Canon::read_file($file));
            } catch (\Throwable) {
                continue;
            }
            foreach ((array) ($front['meta'] ?? []) as $metaKey => $value) {
                $emit(['kind' => 'post_meta', 'key' => (string) $metaKey], (string) $metaKey, $value);
            }
        }
        $tableFiles = glob($stateDir . '/tables/*/*.json') ?: [];
        sort($tableFiles, SORT_STRING);
        foreach ($tableFiles as $file) {
            $row = self::read_json($file);
            $table = basename(dirname($file));
            foreach ((array) ($row['columns'] ?? []) as $col => $value) {
                $emit(
                    ['kind' => 'table_column', 'table' => $table, 'column' => (string) $col],
                    (string) $col,
                    $value
                );
            }
        }
        ksort($observed, SORT_STRING);
        $out = [];
        foreach ($observed as $target => $record) {
            $shapes = array_keys($record['shapes']);
            sort($shapes, SORT_STRING);
            $out[] = [
                'target' => $target,
                'candidate' => [
                    // A capability DECLARATION, not code. `source: plugin` keeps the
                    // installed plugin the trust anchor; no interpreter/regenerator.
                    'id' => $record['id'],
                    'plugin' => 'observed-plugin/observed-plugin.php',
                    'source' => 'plugin',
                    'version' => '0.0.0',
                    'capabilities' => [$record['capability']],
                ],
                'status' => 'unsupported',
                'confidence' => 0.2,
                'evidence' => [[
                    'source' => self::generated_surface_source($record['surface']),
                    'locator' => self::generated_surface_locator($record['surface']),
                    'observation' => $record['count'] . ' redacted observation(s): ' . implode('; ', $shapes)
                        . ' — looks plugin-generated (bytes withheld)',
                ]],
                'questions' => [
                    'is this actually plugin-generated? the LIVE journal WHY-signal is needed to confirm — deferred',
                    'executable regeneration is a plugin capability declaration or a native action from the closed '
                        . 'vocabulary (' . implode(', ', NativeActions::vocabulary()) . '), NEVER engine code',
                ],
                '_screen' => $record['screen'],
            ];
        }
        return $out;
    }

    /** Structural evidence only: a captured entity file name never belongs here. */
    private static function generated_surface_source(array $surface): string {
        return ($surface['kind'] ?? '') === 'table_column'
            ? 'state/tables/' . $surface['table'] . '/columns'
            : 'state/post_meta';
    }

    /** The stable locator that drives identity, review, and aggregation. */
    private static function generated_surface_locator(array $surface): string {
        return ($surface['kind'] ?? '') === 'table_column'
            ? 'tables.' . $surface['table'] . '.columns.' . $surface['column']
            : 'post_meta.' . $surface['key'];
    }

    // -------------------------------------------------------- secret screen

    /**
     * Secrets::hard_match_deep()/suspicious_deep() over every observed candidate
     * value (the same discipline Pending applies to a pending item). On a hit the
     * candidate is DROPPED — replaced by an empty fragment and a question naming the
     * label only. The screen never authors the offending value into the draft.
     *
     * `_screen` carries the RAW observed values as [name, value] pairs (a fragment
     * holds only names/structure, so a secret in a captured VALUE would otherwise
     * slip the screen). The REAL name is passed to Secrets::suspicious_deep(), which
     * keys on the name — passing a positional index there (the earlier bug) made the
     * whole heuristic tier inert, so a suspicious-but-not-hard_match credential under
     * a credential-named column/meta key leaked. `_screen` is stripped here so it
     * never reaches the output.
     *
     * @param array<string,mixed> $candidate
     * @return array<string,mixed>
     */
    private static function screen_secrets(array $candidate): array {
        $screen = $candidate['_screen'] ?? [];
        unset($candidate['_screen']);
        // A fragment holds only structure, but hard_match it as defense in depth.
        $label = Secrets::hard_match_deep($candidate['candidate'] ?? null);
        if ($label === null) {
            foreach ($screen as $pair) {
                $name = (string) ($pair[0] ?? '');
                $value = $pair[1] ?? null;
                $label = Secrets::hard_match_deep($value);
                if ($label === null && Secrets::suspicious_deep($name, $value)) {
                    $label = 'suspicious credential-shaped value';
                }
                if ($label !== null) {
                    break;
                }
            }
        }
        if ($label === null) {
            return $candidate;
        }
        $candidate['candidate'] = (object) [];
        // An unsupported observation has no liftable target family by design.
        // Screening its bytes must make it *more* inert, never recast it as a
        // proposal that bucket_for_target() then cannot route.
        if (($candidate['status'] ?? null) !== 'unsupported') {
            $candidate['status'] = 'proposal';
        }
        $candidate['confidence'] = 0.0;
        $candidate['evidence'] = [];
        $candidate['questions'] = array_merge($candidate['questions'] ?? [], [
            "a candidate value screened as a possible secret ($label) and was DROPPED — the value is never authored "
                . 'into a draft; re-derive this candidate by hand from a redacted source if it is genuinely a reference',
        ]);
        return $candidate;
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
            if (array_key_exists($newKey, $out)) {
                throw new \RuntimeException(
                    'adapter-draft: candidate fragment contains colliding live/inert trigger keys; '
                        . 'reconcile the declaration manually before regeneration'
                );
            }
            $out[$newKey] = self::rename_triggers($value, $map);
        }
        return $out;
    }

    // ---------------------------------------------- human-edit preservation (_meta)

    /**
     * Preserve a saved candidate whenever the generator cannot prove that a fresh
     * observation is its machine-owned replacement. That includes legacy ordinal
     * `unsupported.N` targets: they are deliberately NOT mapped by position to a
     * new structural identity, because a newly inserted observation would make
     * that attachment wrong. They remain inert with an explicit reconciliation
     * question instead.
     *
     * @param array<string,mixed>|null $priorDraft
     * @param array<string,array<string,mixed>> $fresh keyed by target
     * @return array{0:array<string,array<string,mixed>>,1:array<string,mixed>}
     */
    private static function preserve_human_edits(?array $priorDraft, array $fresh): array {
        [$priorIndex, $priorMeta] = self::index_prior_draft($priorDraft);
        $out = [];
        $meta = [];

        foreach ($fresh as $target => $candidate) {
            $priorCandidate = $priorIndex[$target] ?? null;
            $priorEntry = $priorMeta[$target] ?? null;
            $freshHash = self::generated_hash($candidate['candidate'] ?? null);

            if ($priorCandidate === null) {
                // A meta record without its fragment has no safe object to
                // preserve. index_prior_draft() has already refused that shape.
                $out[$target] = $candidate;
                $meta[$target] = ['generated_hash' => $freshHash, 'ratified' => false, 'edited' => false];
                continue;
            }
            if (($priorEntry['ratified'] ?? false) === true) {
                // Ratified: the generator NEVER touches it, even if this is a
                // legacy record that predates generated_hash bookkeeping.
                $out[$target] = $priorCandidate;
                $meta[$target] = $priorEntry;
                continue;
            }
            if ($priorEntry === null
                || ($priorEntry['legacy_untracked'] ?? false) === true
                || !array_key_exists('generated_hash', $priorEntry)) {
                $preserved = self::add_draft_question(
                    $priorCandidate,
                    'the prior candidate has no trustworthy generated_hash, so it was retained inert rather than '
                        . 'risking an overwrite; reconcile or ratify it by hand before accepting a fresh observation'
                );
                $out[$target] = $preserved;
                $meta[$target] = $priorEntry ?? [];
                $meta[$target]['generated_hash'] = self::generated_hash($priorCandidate['candidate'] ?? null);
                $meta[$target]['ratified'] = false;
                $meta[$target]['edited'] = true;
                $meta[$target]['legacy_untracked'] = true;
                continue;
            }
            if (self::prior_candidate_edited($priorCandidate, $priorEntry)) {
                // Human edited the prior fragment: preserve it, record drift.
                $preserved = self::add_draft_evidence($priorCandidate, [
                    'source' => 'regeneration-drift',
                    'locator' => (string) $target,
                    'observation' => 'the generator re-observed the source and would now write a different '
                        . 'fragment (fresh hash ' . substr($freshHash, 0, 12) . '); the human edit is preserved, '
                        . 'not overwritten',
                ]);
                $preserved = self::add_draft_question(
                    $preserved,
                    'a human-edited candidate was preserved across regeneration; reconcile it with the fresh '
                        . 'observation above if the underlying source changed'
                );
                $out[$target] = $preserved;
                $meta[$target] = $priorEntry;
                $meta[$target]['edited'] = true;
                continue;
            }

            // Unedited and unratified: refresh in place.
            $out[$target] = $candidate;
            $meta[$target] = ['generated_hash' => $freshHash, 'ratified' => false, 'edited' => false];
        }

        // A prior candidate with no matching structural target is ambiguous: it
        // might be a removed source, or it might be the legacy ordinal identity.
        // In either case retaining it inert is the only no-data-loss outcome.
        foreach ($priorIndex as $target => $priorCandidate) {
            if (isset($out[$target])) {
                continue;
            }
            $legacy = preg_match('/^unsupported\.[0-9]+$/D', $target) === 1;
            $question = $legacy
                ? 'this candidate uses a legacy ordinal generated-surface identity and cannot be safely attached to '
                    . 'a fresh structural surface; it was retained inert for manual reconciliation'
                : 'this prior candidate was not re-observed at the same structural target; it was retained inert '
                    . 'rather than silently dropping possible hand-authored intent';
            $preserved = self::add_draft_question($priorCandidate, $question);
            $out[$target] = $preserved;
            $meta[$target] = $priorMeta[$target] ?? [
                'generated_hash' => self::generated_hash($priorCandidate['candidate'] ?? null),
                'ratified' => false,
                'edited' => true,
                'legacy_untracked' => true,
            ];
        }

        ksort($out, SORT_STRING);
        ksort($meta, SORT_STRING);
        return [$out, $meta];
    }

    /**
     * Build collision-refusing indexes from a prior sidecar. A duplicate target
     * cannot be attached to a fresh observation by any deterministic rule, so this
     * is deliberately a loud refusal rather than the old assignment-last-wins map.
     *
     * @param array<string,mixed>|null $priorDraft
     * @return array{0:array<string,array<string,mixed>>,1:array<string,array<string,mixed>>}
     */
    private static function index_prior_draft(?array $priorDraft): array {
        if ($priorDraft === null) {
            return [[], []];
        }
        if (($priorDraft['format'] ?? null) !== self::FORMAT) {
            throw new \RuntimeException(
                'adapter-draft: prior _draft.format must be ' . self::FORMAT
                    . '; refusing to interpret an unknown draft contract'
            );
        }
        $unknownRoot = array_values(array_diff(
            array_keys($priorDraft),
            ['format', 'proposals', 'unsupported', '_meta', 'classification_conflicts', 'evidence_seam', 'seed']
        ));
        if ($unknownRoot !== []) {
            throw new \RuntimeException(
                'adapter-draft: prior _draft contains unrecognized v1 field(s); '
                    . 'refusing to ignore data outside the closed sidecar schema'
            );
        }
        if (array_key_exists('classification_conflicts', $priorDraft)
            && (!is_array($priorDraft['classification_conflicts'])
                || !array_is_list($priorDraft['classification_conflicts']))) {
            throw new \RuntimeException('adapter-draft: prior _draft.classification_conflicts must be a list');
        }
        if (array_key_exists('evidence_seam', $priorDraft) && !is_string($priorDraft['evidence_seam'])) {
            throw new \RuntimeException('adapter-draft: prior _draft.evidence_seam must be a string');
        }
        if (array_key_exists('seed', $priorDraft) && !is_string($priorDraft['seed'])) {
            throw new \RuntimeException('adapter-draft: prior _draft.seed must be a string');
        }
        $priorIndex = [];
        $priorMeta = [];
        $rawMeta = $priorDraft['_meta'] ?? [];
        if (!is_array($rawMeta) || ($rawMeta !== [] && array_is_list($rawMeta))) {
            throw new \RuntimeException('adapter-draft: prior _draft._meta must be an object');
        }
        foreach ($rawMeta as $target => $entry) {
            if (!is_string($target) || $target === '' || !is_array($entry) || ($entry !== [] && array_is_list($entry))) {
                throw new \RuntimeException('adapter-draft: prior _draft._meta contains a malformed target record');
            }
            self::assert_safe_prior_target($target);
            self::validate_prior_meta_entry($entry);
            // `_meta` is machine authority, not an extension point. Retain only
            // its validated closed vocabulary so an old auxiliary field cannot
            // reappear in the regenerated artifact as a secret or local trace.
            $priorMeta[$target] = self::normalized_prior_meta_entry($entry);
        }

        $proposals = $priorDraft['proposals'] ?? [];
        if (!is_array($proposals) || ($proposals !== [] && array_is_list($proposals))) {
            throw new \RuntimeException('adapter-draft: prior _draft.proposals must be an object');
        }
        foreach ($proposals as $bucket => $list) {
            if (!is_string($bucket) || !in_array($bucket, self::BUCKETS, true)) {
                throw new \RuntimeException('adapter-draft: prior _draft.proposals contains an unknown v1 bucket');
            }
            if (!is_array($list) || ($list !== [] && !array_is_list($list))) {
                throw new \RuntimeException("adapter-draft: prior _draft.proposals.$bucket must be a list");
            }
            foreach ($list as $candidate) {
                self::index_prior_candidate(
                    $priorIndex,
                    $candidate,
                    '_draft.proposals.' . $bucket,
                    $bucket,
                    'proposal'
                );
            }
        }
        $unsupported = $priorDraft['unsupported'] ?? [];
        if (!is_array($unsupported) || ($unsupported !== [] && !array_is_list($unsupported))) {
            throw new \RuntimeException('adapter-draft: prior _draft.unsupported must be a list');
        }
        foreach ($unsupported as $candidate) {
            self::index_prior_candidate($priorIndex, $candidate, '_draft.unsupported', null, 'unsupported');
        }
        foreach ($priorMeta as $target => $_) {
            if (!isset($priorIndex[$target])) {
                throw new \RuntimeException(
                    "adapter-draft: prior _draft._meta target '$target' has no candidate; refusing to attach its marker elsewhere"
                );
            }
        }
        ksort($priorIndex, SORT_STRING);
        ksort($priorMeta, SORT_STRING);
        return [$priorIndex, $priorMeta];
    }

    /** @param array<string,array<string,mixed>> $index */
    private static function index_prior_candidate(
        array &$index,
        mixed $candidate,
        string $where,
        ?string $expectedBucket,
        string $expectedStatus
    ): void {
        if (!is_array($candidate) || !isset($candidate['target']) || !is_string($candidate['target'])
            || $candidate['target'] === '') {
            throw new \RuntimeException("adapter-draft: prior $where contains a candidate with no string target");
        }
        $target = $candidate['target'];
        self::assert_safe_prior_target($target);
        if (($candidate['status'] ?? null) !== $expectedStatus) {
            throw new \RuntimeException(
                "adapter-draft: prior $where candidate status must be '$expectedStatus'"
            );
        }
        $confidence = $candidate['confidence'] ?? null;
        if (!(is_int($confidence) || is_float($confidence)) || $confidence < 0 || $confidence > 1) {
            throw new \RuntimeException(
                "adapter-draft: prior $where candidate confidence must be a number from 0 through 1"
            );
        }
        if (!array_key_exists('candidate', $candidate)
            || !(is_object($candidate['candidate'])
                || (is_array($candidate['candidate'])
                    && ($candidate['candidate'] === [] || !array_is_list($candidate['candidate']))))) {
            throw new \RuntimeException(
                "adapter-draft: prior $where candidate declaration must be an object"
            );
        }
        if ($expectedBucket === null) {
            if (preg_match('/^unsupported\.(?:[0-9]+|generated\.[a-f0-9]{64})$/D', $target) !== 1) {
                throw new \RuntimeException(
                    'adapter-draft: prior _draft.unsupported candidate target has no supported v1 identity'
                );
            }
        } elseif (self::bucket_for_target($target) !== $expectedBucket) {
            throw new \RuntimeException(
                "adapter-draft: prior $where candidate target belongs to a different v1 proposal bucket"
            );
        }
        if (isset($index[$target])) {
            throw new \RuntimeException(
                "adapter-draft: prior _draft repeats target '$target'; refusing a last-wins preservation merge"
            );
        }
        $index[$target] = self::sanitize_prior_candidate($candidate);
    }

    /**
     * `_meta` is machine authority for a preservation decision. A malformed
     * marker must not quietly read as false/absent and let a fresh observation
     * overwrite a fragment whose ownership this command cannot establish.
     *
     * @param array<string,mixed> $entry
     */
    private static function validate_prior_meta_entry(array $entry): void {
        $unknown = array_diff(array_keys($entry), ['generated_hash', 'ratified', 'edited', 'legacy_untracked']);
        if ($unknown !== []) {
            throw new \RuntimeException(
                'adapter-draft: prior _draft._meta contains unrecognized authority field(s): '
                . implode(',', $unknown) . '; refusing to ignore a preservation marker'
            );
        }
        if (array_key_exists('generated_hash', $entry)
            && (!is_string($entry['generated_hash']) || preg_match('/^[a-f0-9]{64}$/D', $entry['generated_hash']) !== 1)) {
            throw new \RuntimeException('adapter-draft: prior _draft._meta.generated_hash must be a lowercase sha256 string');
        }
        foreach (['ratified', 'edited', 'legacy_untracked'] as $marker) {
            if (array_key_exists($marker, $entry) && !is_bool($entry[$marker])) {
                throw new \RuntimeException("adapter-draft: prior _draft._meta.$marker must be a boolean");
            }
        }
    }

    /** @return array<string,mixed> only the validated, authority-bearing fields. */
    private static function normalized_prior_meta_entry(array $entry): array {
        $out = [];
        foreach (['generated_hash', 'ratified', 'edited', 'legacy_untracked'] as $key) {
            if (array_key_exists($key, $entry)) {
                $out[$key] = $entry[$key];
            }
        }
        return $out;
    }

    /**
     * A previous draft is untrusted input for output safety. Candidate fragments
     * carry the only semantic declaration worth preserving; machine evidence and
     * free-form questions may contain a captured value, row filename, or UUID, so
     * they are never replayed. The replacement row names no entity and contains no
     * observed bytes. A secret or entity-local coordinate inside the declaration
     * itself cannot be safely redacted without changing its meaning, so it refuses.
     *
     * @param array<string,mixed> $candidate
     * @return array<string,mixed>
     */
    private static function sanitize_prior_candidate(array $candidate): array {
        $fragment = $candidate['candidate'] ?? null;
        // Canon::decode() uses associative arrays, so a JSON object `{}` and
        // list `[]` otherwise collapse to the same PHP value. Candidate
        // declarations are maps; restore the empty-map shape before computing
        // preservation hashes so a redacted `{}` candidate does not acquire a
        // false human edit on its second generation.
        if ($fragment === []) {
            $fragment = (object) [];
        }
        if (self::contains_executable_stub($fragment)) {
            throw new \RuntimeException(
                'adapter-draft: prior _draft candidate contains executable/interpreter semantics; '
                    . 'move plugin behavior into a plugin-owned provider or a structured native action'
            );
        }
        $secret = self::prior_fragment_secret_label($fragment);
        if ($secret !== null) {
            throw new \RuntimeException(
                "adapter-draft: prior _draft candidate contains a possible secret ($secret); refusing to emit or overwrite it"
            );
        }
        if (self::contains_entity_local_coordinate($fragment)) {
            throw new \RuntimeException(
                'adapter-draft: prior _draft candidate contains an entity-local UUID/path; redact and reconcile it manually before regeneration'
            );
        }
        // A hand-crafted old sidecar can carry literal trigger keys even though
        // this generator never writes them. Reapply the structural inertness
        // transform before the declaration can reach the output blind walk.
        // Rebuild (rather than mutate) the envelope: target + declaration are
        // the human semantic intent; evidence, questions, `_screen`, and every
        // unknown auxiliary field are untrusted machine context and must never
        // be replayed verbatim into a new artifact.
        // index_prior_candidate() already validated these closed v1 fields.
        // Preserve them exactly; never turn malformed saved authority into a
        // plausible-looking proposal/default confidence.
        $status = (string) $candidate['status'];
        $confidence = $candidate['confidence'];
        return [
            'target' => (string) $candidate['target'],
            'candidate' => self::rename_triggers($fragment, self::RENAME),
            'status' => $status,
            'confidence' => (float) $confidence,
            'evidence' => [[
                'source' => 'prior-artifact-redacted',
                'locator' => self::prior_redacted_locator($candidate),
                'observation' => 'prior evidence was redacted during regeneration; no observed value, entity filename, or UUID was replayed',
            ]],
            'questions' => [
                'prior evidence and free-form questions were redacted during regeneration; re-derive review context from a redacted source if needed',
            ],
        ];
    }

    /**
     * Preserve only a known-safe structural locator from prior machine evidence.
     * It helps a reviewer recognize a retained candidate without replaying a row
     * filename. Everything else becomes a bounded hash coordinate.
     */
    private static function prior_redacted_locator(array $candidate): string {
        foreach ((array) ($candidate['evidence'] ?? []) as $evidence) {
            $locator = is_array($evidence) ? ($evidence['locator'] ?? null) : null;
            if (is_string($locator) && self::safe_surface_locator($locator)) {
                return $locator;
            }
        }
        return 'candidate:' . substr(self::semantic_hash($candidate['candidate'] ?? null), 0, 12);
    }

    private static function safe_surface_locator(string $locator): bool {
        if (Secrets::hard_match($locator) !== null || self::contains_entity_local_coordinate($locator)) {
            return false;
        }
        return preg_match('/^(?:options|post_meta|term_meta|user_meta)\.[A-Za-z0-9_.-]{1,191}$/D', $locator) === 1
            || preg_match('/^tables\.[A-Za-z0-9_.-]{1,128}\.columns\.[A-Za-z0-9_.-]{1,128}$/D', $locator) === 1;
    }

    /** Hard patterns plus the name-aware heuristic, recursively over a fragment. */
    private static function prior_fragment_secret_label(mixed $fragment): ?string {
        $hard = Secrets::hard_match_deep($fragment);
        if ($hard !== null) {
            return $hard;
        }
        return self::fragment_is_suspicious($fragment, 'candidate')
            ? 'suspicious credential-shaped value'
            : null;
    }

    private static function fragment_is_suspicious(mixed $value, string $key): bool {
        if (is_string($value)) {
            return Secrets::suspicious($key, $value);
        }
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $childKey => $child) {
            $name = is_string($childKey) ? $childKey : $key;
            if (self::fragment_is_suspicious($child, $name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * A draft candidate is data-only. Prior artifacts are untrusted and may
     * predate that boundary, so refuse the explicit executable seams rather than
     * preserving them under an inert-looking sidecar. The PHP opening token is
     * also refused at any value depth without echoing the offending bytes.
     */
    private static function contains_executable_stub(mixed $value): bool {
        if (is_string($value)) {
            return str_contains($value, '<?');
        }
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $key => $child) {
            if (is_string($key)
                && in_array(strtolower($key), ['interpreter', 'rebuilders', 'regenerator', 'regen_dependency'], true)) {
                return true;
            }
            if (self::contains_executable_stub($child)) {
                return true;
            }
        }
        return false;
    }

    /** Reject raw entity coordinates in a target without echoing the unsafe text. */
    private static function assert_safe_prior_target(string $target): void {
        if (Secrets::hard_match($target) !== null || self::contains_entity_local_coordinate($target)) {
            throw new \RuntimeException(
                'adapter-draft: prior _draft target contains a secret or entity-local coordinate; redact it manually before regeneration'
            );
        }
    }

    /**
     * UUIDs and captured state-file paths are local observations, not adapter
     * identity. This intentionally does not treat ordinary numeric declarations
     * as local ids: whether an integer is a true entity reference is live-only and
     * removing it here would silently change a human declaration's semantics.
     */
    private static function contains_entity_local_coordinate(mixed $value): bool {
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if ((is_string($key) && self::contains_entity_local_coordinate($key))
                    || self::contains_entity_local_coordinate($child)) {
                    return true;
                }
            }
            return false;
        }
        if (!is_string($value)) {
            return false;
        }
        if (preg_match('/(?<![a-z0-9])[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}(?![a-z0-9])/i', $value) === 1) {
            return true;
        }
        return preg_match('~(?:^|/)(?:state/)?(?:posts|terms|tables)/[^/]+/[^/]+\.(?:md|json)(?:$|[?#])~', $value) === 1;
    }

    /** Does a saved marker prove that its candidate fragment was hand-edited? */
    private static function prior_candidate_edited(array $candidate, array $entry): bool {
        $stored = $entry['generated_hash'] ?? null;
        return is_string($stored) && $stored !== ''
            && !hash_equals($stored, self::generated_hash($candidate['candidate'] ?? null));
    }

    /** Add a question once so stable re-observation does not churn the artifact. */
    private static function add_draft_question(array $candidate, string $question): array {
        $questions = (array) ($candidate['questions'] ?? []);
        if (!in_array($question, $questions, true)) {
            $questions[] = $question;
        }
        $candidate['questions'] = $questions;
        return $candidate;
    }

    /** Add one redacted drift evidence record without duplicating it on the next run. */
    private static function add_draft_evidence(array $candidate, array $record): array {
        $evidence = (array) ($candidate['evidence'] ?? []);
        foreach ($evidence as $existing) {
            if (is_array($existing) && self::semantic_hash($existing) === self::semantic_hash($record)) {
                return $candidate;
            }
        }
        $evidence[] = $record;
        $candidate['evidence'] = $evidence;
        return $candidate;
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
        $results = self::lift_results($manifest);

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
     * Every proposal's grammar verdict, computed once through the REAL validators.
     *
     * Extracted from emit_check() so `--gap-report` reads the same verdicts rather
     * than a second lookalike lift: the two modes disagreeing about whether a shape
     * is expressible is exactly the failure a shared computation makes impossible.
     *
     * @return list<array{target:string,liftable:bool,message:?string}>
     */
    private static function lift_results(array $manifest): array {
        $draft = $manifest['_draft'] ?? [];
        $proposals = (array) ($draft['proposals'] ?? []);
        $results = [];
        $tmp = null;
        try {
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
        } finally {
            if ($tmp !== null) {
                self::rrmdir($tmp);
            }
        }
        return $results;
    }

    /**
     * Emit the REFUSED proposals as draft rows for the engine-gap ledger
     * (tools/engine-gaps.json).
     *
     * The ledger's stated failure mode is rotting into a memory-fed wishlist: a
     * shape nobody wrote down on the day it was found is a shape nobody counts.
     * This is the tool-fed half. A `liftable:false` verdict is not a bug report
     * about the draft — it is the real `Policy::load()` grammar refusing a shape
     * observed in a real site's captured state, which is precisely the ledger's
     * subject. Liftable proposals are omitted: a shape the grammar accepts is not
     * a gap, and a report that listed them would need reading before it could be
     * used.
     *
     * `primitive_required` is emitted as null ON PURPOSE. Naming the missing
     * primitive is a review judgement about which EXISTING vocabulary entry this
     * demand collapses into, and inventing one per report is how duplicate demand
     * would stop being countable — the one property the ledger exists for. So the
     * row lands incomplete by construction, and tools/engine-gap-doc.php refuses
     * it ("the closed vocabulary does not declare") until a human classifies it.
     */
    private static function emit_gap_report(array $manifest, string $name, bool $json): int {
        $results = self::lift_results($manifest);
        $rows = [];
        foreach ($results as $result) {
            if ($result['liftable']) {
                continue;
            }
            $rows[] = [
                'coordinate' => $result['target'],
                'cannot_represent' => (string) ($result['message'] ?? 'the grammar refused this shape'),
                'primitive_required' => null,
                'question' => 'name the primitive this demand collapses into from the engine-gap ledger\'s closed '
                    . 'vocabulary (tools/engine-gaps.json `primitives`), or add one there, before this row is filed',
            ];
        }
        $report = [
            'format' => self::GAP_REPORT_FORMAT,
            'spec_version' => DUO_SPEC_VERSION,
            'adapter' => $name,
            'rows' => $rows,
            'summary' => ['checked' => count($results), 'gaps' => count($rows)],
        ];
        if ($json) {
            echo rtrim(Canon::encode($report)) . "\n";
            return 0;
        }
        echo "adapter-draft --gap-report (throwaway lift; nothing written live):\n";
        foreach ($rows as $row) {
            echo '  ' . $row['coordinate'] . "\n";
            echo '          ' . $row['cannot_represent'] . "\n";
        }
        echo "\nsummary: {$report['summary']['checked']} proposals checked, {$report['summary']['gaps']} "
            . "with no expressible shape\n";
        if ($rows !== []) {
            echo "\nEach row above is a DRAFT ledger row with no primitive named. Classify it against\n"
                . "tools/engine-gaps.json's closed primitive vocabulary before filing it; the ledger's\n"
                . "projector refuses a row whose primitive is not in that vocabulary.\n";
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
        $headToken = explode('.', $target, 2)[0];
        $head = explode('[', $headToken, 2)[0];
        $tail = str_contains($target, '.') ? substr($target, strpos($target, '.') + 1) : '';
        switch ($head) {
            case 'tables':
                return ['tables' => [$tail => $fragment]];
            case 'block_paths': {
                $owner = self::attribute_owner($head, $tail, $fragment);
                return $owner === null ? null : ['block_attrs' => [$owner => [$fragment]]];
            }
            case 'shortcode_paths': {
                $owner = self::attribute_owner($head, $tail, $fragment);
                return $owner === null ? null : ['shortcode_attrs' => [$owner => [$fragment]]];
            }
            case 'deletions':
                return ['deletions' => [$tail => $fragment]];
            case 'actions':
                return ['actions' => [$fragment]];
            case 'providers':
                return ['providers' => [$fragment]];
            // These two sections are LISTS in the manifest grammar, not maps,
            // so the candidate lifts as a one-element list exactly like
            // actions/providers. The prefix rides in the target's `[...]`
            // identity so two seeded prefixes cannot collide.
            case 'option_namespaces':
                return ['option_namespaces' => [$fragment]];
            case 'option_patterns':
                return ['option_patterns' => [$fragment]];
            case 'post_types':
                return ['post_types' => [$tail => $fragment]];
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
        // The eventual artifact lives under a site's adapters/ directory, not
        // the shipped manifest library used for this isolated grammar load.
        // Apply the exact out-of-tree source boundary first so a manifest-code
        // provider cannot be misreported as liftable merely because the temp
        // file itself is loaded with shipped precedence.
        $prev = getenv('DUO_MANIFESTS_DIR');
        try {
            AdapterSources::assert_out_of_tree_contract(
                $manifest,
                $checkName,
                'adapters/' . $checkName . '.json'
            );
            Canon::write_file($library . '/' . $checkName . '.json', Canon::encode($manifest));
            putenv('DUO_MANIFESTS_DIR=' . $library);
            $policy = Policy::load(null, [$checkName]);
            foreach ((array) ($section['deletions'] ?? []) as $selector => $_) {
                if (!is_string($selector) || !str_contains($selector, ':')) {
                    throw new \RuntimeException('duo: deletion proposal has an invalid selector');
                }
                [$kind, $type] = explode(':', $selector, 2);
                if ($type === '') {
                    throw new \RuntimeException('duo: deletion proposal has an invalid selector');
                }
                if ($kind === 'table') {
                    return [
                        false,
                        'table deletion cascade completeness is live-policy conditional (attached-meta ownership); '
                            . 'this offline one-candidate check deliberately defers that decision',
                    ];
                }
                Deletion::capability($policy, $kind, $type);
            }
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
        $dir = null;
        for ($attempt = 0; $attempt < 32; $attempt++) {
            $candidate = sys_get_temp_dir() . '/duo_adapter_draft_check_' . bin2hex(random_bytes(16));
            if (@mkdir($candidate, 0700, false)) {
                $dir = $candidate;
                break;
            }
        }
        if ($dir === null) {
            throw new \RuntimeException('adapter-draft: could not create a private proposal-check directory');
        }
        try {
            Canon::write_file($dir . '/core.json', Canon::encode([
                'name' => 'core',
                'spec_version' => DUO_SPEC_VERSION,
                'options' => (object) [],
                'post_meta' => (object) [],
                'term_meta' => (object) [],
            ]));
        } catch (\Throwable $t) {
            self::rrmdir($dir);
            throw $t;
        }
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
        foreach (self::FACT_SECTIONS as $section) {
            $facts += count((array) ($manifest[$section] ?? []));
        }
        echo "\nfacts (validated, applied — real classification sections): $facts rule(s)\n";
        echo '  ' . self::fact_counts($manifest) . "\n";

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

        $conflicts = (array) ($draft['classification_conflicts'] ?? []);
        if ($conflicts !== []) {
            echo "\nclassification conflicts (INERT — existing hand-authored facts were preserved):\n";
            foreach ($conflicts as $conflict) {
                echo '  [conflict] ' . ($conflict['surface'] ?? $conflict['target'] ?? '?') . "\n";
                foreach ((array) ($conflict['questions'] ?? []) as $q) {
                    echo "          ? $q\n";
                }
            }
        }

        echo "\nevidence seam: " . ($draft['evidence_seam'] ?? '') . "\n";
        echo 'seed:          ' . ($draft['seed'] ?? '') . "\n";
        echo "\nsummary: $facts fact(s) validated; $pCount proposal(s) + " . count($unsupported)
            . ' unsupported + ' . count($conflicts)
            . " conflict record(s) are INERT and unvalidated here — run 'duo adapter-draft --check-proposals' or "
            . "install the draft and 'duo manifest-validate <dir>'. --format=json prints the draft artifact.\n";
    }

    /** @param array<string,mixed> $manifest */
    private static function fact_counts(array $manifest): string {
        $parts = [];
        foreach (self::FACT_SECTIONS as $section) {
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

    /**
     * A byte-free description of a generated/derived value's SHAPE — its size and
     * encoding family, never its content. A generated payload may embed a
     * credential no hard_match pattern catches, so its bytes must never be pasted
     * into an artifact (evidence, questions, or otherwise).
     */
    private static function shape_descriptor(mixed $value): string {
        if (is_string($value)) {
            $len = strlen($value);
            if (preg_match('/^(a:\d+:\{|O:\d+:"|s:\d+:")/', $value) === 1) {
                return "opaque ~{$len}-char PHP-serialized blob";
            }
            return "opaque ~{$len}-char JSON/serialized-looking blob";
        }
        return 'derived-looking ' . gettype($value) . ' value';
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
            ($f->isLink() || !$f->isDir()) ? unlink($f->getPathname()) : rmdir($f->getPathname());
        }
        rmdir($dir);
    }

    /** Fail closed on this command's own paths: usage, a bad dir, an unreadable file. */
    private static function fail(string $message): int {
        fwrite(STDERR, "duo: adapter-draft: $message\n");
        return 2;
    }
}

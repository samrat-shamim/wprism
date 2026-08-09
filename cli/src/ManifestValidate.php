<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

use Duo\NativeActions;
use Duo\Policy;

/**
 * `duo manifest-validate` — the adapter author's offline grammar check.
 *
 * An adapter author writing a manifest has, until now, had exactly one way to
 * find out whether the declaration is well-formed: install the agent on a
 * WordPress target, pin the manifest, and run a command that reaches that
 * target. That is a slow loop for a question that is a pure function of the
 * manifest bytes — every validator this command drives already refuses at
 * `Policy::load()` time, before any target contact, which is precisely why it
 * can run here on a laptop with no WordPress, no database, and no docker.
 *
 * This is an authoring aid. It is not a gate on anything, and it deliberately
 * says so in its own output: the checks that genuinely need a live target are
 * printed on EVERY run, passing or failing, as `deferred`. A tool that only
 * listed them when something went wrong would let silence read as "everything
 * about this manifest is verified", which is the false-green class the whole
 * verification posture refuses.
 *
 * Nothing here reimplements a validator. The engine files are required
 * directly and the REAL `Policy::load()` does the work, per manifest and then
 * once more over the co-loaded pin set so the cross-manifest guards (one owner
 * per declared name, namespace overlap, adapter claims, provider ids, id_kinds)
 * run too. Each successful load is followed by resolving the manifest-shipped
 * PHP it NAMES — interpreters and regenerators — because Policy resolves those
 * lazily and a manifest naming a file that does not exist would otherwise
 * report `ok` (see resolve()).
 *
 * The site half is optional but it is not absent from the question. Two of
 * those guards read `site.duo.json`'s own policy as INPUT: a site-declared
 * table extends the legal ref/token/ledger kind vocabulary, and a site
 * `policy.options` rule is the ratified resolution when two manifests declare
 * one option differently. Loading with a null repo therefore does NOT merely
 * check less — it can refuse a manifest that its real site accepts, with a
 * remediation telling the author to add an override they already have. So
 * `--site=<site-repo-path>` passes the real repo through to both phases; without
 * it, a refusal from either of those two guards is ANNOTATED (never rewritten)
 * as possibly site-resolvable, and the always-emitted deferred list carries the
 * missing half as a permanent, named limitation. `wp duo manifest-pin` still
 * validates one installed manifest with a null repo, and is still right to: it
 * checks one manifest in isolation, where neither guard has anything to say.
 *
 * The emitted grammar document (`--emit-schema`) follows the same rule one step
 * further: every closed set in it is read from the engine at emission time
 * (Policy::closed_vocabularies(), Policy::grammar_patterns(),
 * NativeActions::vocabulary()/arg_schemas()). None of it is authored here, so
 * it cannot describe a grammar the engine stopped enforcing.
 */
final class ManifestValidate {
    /** Envelope of the validation report (both output modes carry it). */
    public const FORMAT = 'duo-manifest-validation/v1';

    /** Envelope of the emitted grammar document. */
    public const SCHEMA = 'duo-manifest-grammar/v1';

    /**
     * The two engine refusals whose verdict is a function of the SITE half of
     * policy, matched on the stable substring each one's message is built
     * around:
     *
     *   - the ref/token/ledger kind vocabularies union their engine base with
     *     every `id_kind` declared by a pinned manifest AND by
     *     `site.duo.json`'s own `policy.tables` (Policy::validate_ref_kinds()),
     *     so a manifest referencing a kind the SITE declares is legal there and
     *     refused here;
     *   - two manifests declaring contradictory rules for one option name are
     *     refused unless the site resolves the name with an explicit
     *     `policy.options` override (Policy::validate_no_conflicting_option_
     *     rules()), which this command cannot see without a site repo.
     *
     * Matched, never rewritten: the engine's message is the author's actual
     * coordinate and this command has no business editing it. The annotation is
     * a separate field saying the refusal may not be one on the real site — and
     * naming the flag that answers the question. Getting this wrong in the safe
     * direction (annotating a refusal a site could not have fixed) costs a line
     * of output; getting it wrong in the other direction sends an author to
     * "add a site.duo.json override" they already have.
     */
    private const SITE_SENSITIVE_REFUSALS = [
        'kind vocabulary is closed',
        'declare contradictory rules for options.',
    ];

    /** Verbatim annotation for a refusal that a real site policy may resolve. */
    private const SITE_NOTE = 'note: this refusal can be resolved by a site.duo.json this offline check was not '
        . 'given — re-run with --site=<repo> to validate against the real site policy';

    /**
     * Checks this command does not perform, with the engine symbol that owns
     * each one so a reader can go read it rather than take this list's word for
     * it. Emitted unconditionally — see this class's docblock for why "we
     * printed nothing" may never be readable as "we checked everything".
     *
     * Most rows are deferred because they need a LIVE TARGET. The first is a
     * different species and stays permanently in the list for the same reason:
     * it is a limitation of what this command is given, not of what it runs,
     * and an author who never passes `--site` would otherwise have no way to
     * learn that half of two guards' input was simply absent.
     *
     * @return list<array{status:string, surface:string, check:string, why:string}>
     */
    private static function deferred(): array {
        $rows = [
            [
                'surface' => 'site.duo.json policy.tables / policy.options',
                'check' => 'Policy::validate_ref_kinds() / Policy::validate_no_conflicting_option_rules()',
                'why' => 'both guards take the SITE half of policy as INPUT, not just the manifests: a table '
                    . 'declared in site.duo.json extends the legal ref/token/ledger kind vocabulary, and a '
                    . 'site policy.options rule is the explicit resolution for one option two manifests declare '
                    . 'differently. Without --site=<site-repo-path> this command loads with no site policy at '
                    . 'all, so either guard can refuse a manifest its real site accepts (such a refusal is '
                    . 'annotated as possibly site-resolvable). With --site, the site repo read is the one on '
                    . 'THIS machine — whether the target runs that revision is a fact about the target',
            ],
            [
                'surface' => 'tables',
                'check' => 'Snapshot::assert_row_schema() / assert_composite_row_schema() / assert_meta_schema()',
                'why' => 'the declaration half of a table (class, pk, id_kind, columns, refs, identity) is checked '
                    . 'here; the SHOW COLUMNS half — that the target really has those columns, that every live '
                    . 'column is accounted for by exactly one of pk/refs/columns, and that the declared types '
                    . 'match — is a fact about one database and cannot be read offline',
            ],
            [
                'surface' => 'taxonomy_patterns',
                'check' => 'Policy::taxonomies()',
                'why' => 'a taxonomy_patterns entry is validated as a declaration here; which dynamic taxonomy '
                    . 'NAMES it actually expands to is read from live wp_term_taxonomy rows (deliberately, since '
                    . "the in-memory registry is stale mid-request), so the in-scope name set does not exist yet",
            ],
            [
                'surface' => 'plugin / version_range / theme_range',
                'check' => 'Deploy::code_mismatch() / Deploy::code_drift()',
                'why' => 'whether the declared plugin or theme is installed, active, and inside the declared '
                    . 'version window is a fact about a target filesystem; only the shape of the compatibility '
                    . 'claim is checkable from the manifest alone',
            ],
            [
                'surface' => 'providers / actions[].kind=provider',
                'check' => 'Providers::negotiate()',
                'why' => 'a provider declaration is an identity assertion about executable code the engine does '
                    . 'not own. Contract shape, exact identity and version match, capability advertisement, and '
                    . "each action's args against the capability's OWN declared schema are negotiated against the "
                    . 'installed code before the first mutation',
            ],
            [
                'surface' => 'actions[].kind=native',
                'check' => 'NativeActions::execute()',
                'why' => 'the action name and every argument are closed and fully checked here; performing the '
                    . 'effect and proving it landed by a value-level readback receipt needs a loaded WordPress '
                    . 'runtime',
            ],
            [
                'surface' => 'dispositions.json / capabilities/registry.json',
                'check' => 'CapabilityRegistry::report()',
                'why' => 'certification is evidence evaluated against one target revision and its installed '
                    . 'plugin/theme versions. Nothing here says whether a capability is certified, exercised, '
                    . 'uncertified, or incompatible for your site — run `duo capabilities <env>` for that',
            ],
            [
                'surface' => 'block_attrs / shortcode_attrs / json_refs / key_refs',
                'check' => 'Lint::scan_tree()',
                'why' => 'reference rules are grammar-checked here; whether a captured id resolves to a live '
                    . 'entity, and the home-URL rewriting those findings are computed against, are read off a '
                    . 'live site',
            ],
        ];
        foreach ($rows as $i => $row) {
            $rows[$i] = ['status' => 'deferred'] + $row;
        }
        return $rows;
    }

    /**
     * @param list<string> $args everything after the verb
     * @return int 0 all valid, 1 any invalid, 2 usage/IO
     */
    public static function run(array $args): int {
        $dir = null;
        $siteArg = null;
        $manifestSelection = null;
        $pinSelection = null;
        $all = false;
        $json = false;
        $emitSchema = false;

        // A repeated flag is refused rather than last-wins (the same posture
        // `duo driver-capabilities` takes): a second --pins silently replacing
        // the first would validate a set the author did not ask for and report
        // it as though they had.
        $seen = [];
        foreach ($args as $arg) {
            $flag = str_starts_with($arg, '--') ? explode('=', $arg, 2)[0] : null;
            if ($flag !== null) {
                if (isset($seen[$flag])) {
                    return self::fail("duplicate flag '$flag'");
                }
                $seen[$flag] = true;
            }
            if ($arg === '--emit-schema') {
                $emitSchema = true;
            } elseif ($arg === '--all') {
                $all = true;
            } elseif ($arg === '--format=json') {
                $json = true;
            } elseif (str_starts_with($arg, '--manifest=')) {
                $manifestSelection = self::names(substr($arg, strlen('--manifest=')));
                if ($manifestSelection === null) {
                    return self::fail('--manifest needs a comma-separated list of manifest names');
                }
            } elseif (str_starts_with($arg, '--pins=')) {
                $pinSelection = self::names(substr($arg, strlen('--pins=')));
                if ($pinSelection === null) {
                    return self::fail('--pins needs a comma-separated list of manifest names');
                }
            } elseif (str_starts_with($arg, '--site=')) {
                $siteArg = trim(substr($arg, strlen('--site=')));
                if ($siteArg === '') {
                    return self::fail('--site needs the path of a duo site repo (the directory holding site.duo.json)');
                }
            } elseif (str_starts_with($arg, '-')) {
                return self::fail("unsupported flag '$arg'");
            } elseif ($dir !== null) {
                return self::fail("expected exactly one <manifests-dir>, got a second argument '$arg'");
            } else {
                $dir = $arg;
            }
        }

        if ($pinSelection !== null && $all) {
            return self::fail('--pins and --all are mutually exclusive');
        }
        if ($emitSchema) {
            if ($dir !== null || $manifestSelection !== null || $pinSelection !== null || $all || $siteArg !== null) {
                return self::fail(
                    '--emit-schema takes no manifests dir, no manifest/pin selection, and no --site — the grammar '
                    . 'is read from the engine, not from a directory of declarations or one site'
                );
            }
            return self::emitSchema();
        }
        if ($dir === null) {
            return self::fail('a <manifests-dir> argument is required (or --emit-schema)');
        }

        $resolved = is_dir($dir) ? realpath($dir) : false;
        if ($resolved === false) {
            return self::fail("'$dir' is not a directory");
        }

        // The site half is optional and, when present, must be a real duo site
        // repo: handing Policy::load() a directory with no site.duo.json would
        // fail per manifest with the engine's "not a duo site repo?" message on
        // every row, which reads as "your manifests are broken". This is a
        // usage error about the flag, so it is refused here, once, as one.
        $site = null;
        if ($siteArg !== null) {
            $siteResolved = is_dir($siteArg) ? realpath($siteArg) : false;
            if ($siteResolved === false) {
                return self::fail("--site '$siteArg' is not a directory");
            }
            if (!is_file($siteResolved . '/site.duo.json')) {
                return self::fail(
                    "--site '$siteResolved' has no site.duo.json — --site takes the duo SITE REPO (the directory "
                    . 'holding site.duo.json), whose policy half these manifests are validated against'
                );
            }
            $site = $siteResolved;
        }

        $available = [];
        foreach (glob(rtrim($resolved, '/') . '/*.json') ?: [] as $file) {
            $base = basename($file, '.json');
            // The disposition registry lives beside the manifests and is not
            // one: it is external review state, loaded by every Policy::load()
            // below as part of the directory, never pinned by name.
            if ($base === 'dispositions') {
                continue;
            }
            $available[$base] = $file;
        }
        ksort($available, SORT_STRING);
        if ($available === []) {
            return self::fail("$resolved contains no manifest *.json files");
        }
        foreach ($available as $file) {
            if (!is_readable($file)) {
                return self::fail("$file is not readable");
            }
        }

        $selected = $manifestSelection ?? array_keys($available);
        $pins = $pinSelection ?? array_keys($available);
        foreach ([['--manifest', $selected], ['--pins', $pins]] as [$flag, $requested]) {
            foreach ($requested as $name) {
                if (!isset($available[$name])) {
                    return self::fail("$flag names '$name', which is not a manifest in $resolved");
                }
            }
        }

        try {
            self::boot();
        } catch (\Throwable $t) {
            return self::fail($t->getMessage());
        }
        putenv('DUO_MANIFESTS_DIR=' . $resolved);

        $rows = [];
        foreach ($selected as $name) {
            $row = ['name' => $name, 'file' => $available[$name], 'status' => 'ok', 'message' => null];
            try {
                // The REAL loader. One manifest at a time so a broken
                // declaration does not hide every later manifest's verdict —
                // an author fixing three manifests should need one run, not
                // three.
                self::resolve(Policy::load($site, [$name]));
            } catch (\Throwable $t) {
                $row['status'] = 'error';
                $row['message'] = $t->getMessage();
            }
            $rows[] = $row;
        }

        // The pin set is a SET, so there is no single file to attach; the paths
        // of everything co-loaded are attached instead, because a cross-manifest
        // refusal names manifests ("manifests 'a' and 'b' …") and a consumer
        // holding this row should not have to re-derive which files those were.
        $pinnedFiles = [];
        foreach ($pins as $name) {
            $pinnedFiles[$name] = $available[$name];
        }
        $pinned = ['names' => $pins, 'files' => $pinnedFiles, 'status' => 'ok', 'message' => null];
        try {
            self::resolve(Policy::load($site, $pins));
        } catch (\Throwable $t) {
            $pinned['status'] = 'error';
            $pinned['message'] = $t->getMessage();
        }

        // In no-site mode, a refusal whose verdict depends on the site half of
        // policy is annotated — never rewritten — so the author reads the
        // engine's exact words plus the one fact this command knows and the
        // engine does not: half its input was withheld by the caller.
        if ($site === null) {
            foreach ($rows as $i => $row) {
                if (self::site_sensitive($row['status'], $row['message'])) {
                    $rows[$i]['site_policy_note'] = self::SITE_NOTE;
                }
            }
            if (self::site_sensitive($pinned['status'], $pinned['message'])) {
                $pinned['site_policy_note'] = self::SITE_NOTE;
            }
        }

        // A manifest may legitimately be unloadable alone and fine in company:
        // the ref-kind vocabulary is extended by DECLARING a table, so an
        // adapter naming another adapter's id_kind needs that adapter pinned.
        // Saying so turns a confusing isolated failure into an actionable one
        // — the repair is a pin, not an edit.
        foreach ($rows as $i => $row) {
            if ($row['status'] === 'error' && $pinned['status'] === 'ok' && in_array($row['name'], $pins, true)) {
                $rows[$i]['pinned_set_note'] = 'valid within the requested pin set; this row is the '
                    . 'isolated verdict, so this manifest is not self-contained';
            }
        }

        $errors = 0;
        foreach ($rows as $row) {
            $errors += $row['status'] === 'error' ? 1 : 0;
        }
        $status = ($errors > 0 || $pinned['status'] === 'error') ? 'error' : 'ok';

        $report = [
            'format' => self::FORMAT,
            'spec_version' => DUO_SPEC_VERSION,
            'manifests_dir' => $resolved,
            'site' => $site,
            'status' => $status,
            'manifests' => $rows,
            'pinned_set' => $pinned,
            'deferred' => self::deferred(),
            'summary' => ['checked' => count($rows), 'ok' => count($rows) - $errors, 'error' => $errors],
        ];

        if ($json) {
            echo self::encode($report) . "\n";
        } else {
            self::render($report);
        }
        return $status === 'ok' ? 0 : 1;
    }

    /**
     * Resolve the manifest-shipped PHP a loaded policy NAMES but does not load.
     *
     * `interpreter` and `post_types.<t>.regen_dependency.regenerator` are the
     * two places a manifest points at a file instead of declaring a value, and
     * Policy resolves both LAZILY — deliberately, since a live command that
     * never classifies meta should not pay for an interpreter it will not use.
     * The consequence for an offline check is that a manifest naming an
     * interpreter or regenerator file that does not exist, or one that does not
     * define the contract class, loaded clean and reported `ok`: the single
     * loudest thing an author could get wrong about a manifest's code half was
     * the one thing this command did not look at.
     *
     * Both resolutions are fully offline — the files live inside the very
     * manifests directory being validated, and the contract is `is_file()` plus
     * `class_exists()`/`method_exists()`. Calling them here, inside the caller's
     * try/catch, turns their refusals into ordinary per-manifest errors carrying
     * the engine's own message (which already names the exact missing path or
     * the exact class it wanted).
     *
     * Nothing is invoked: instantiation is the contract, `post_meta_rule()` /
     * `regenerate()` are live operations and stay deferred.
     */
    private static function resolve(Policy $policy): void {
        $policy->interpreters();
        $policy->regenerators();
    }

    /**
     * Whether a failure may be an artifact of loading with no site policy.
     * Substring-matched against the engine's own message on purpose — the
     * alternative is a second copy of the two guards' conditions here, which
     * would be a validator this command does not own.
     */
    private static function site_sensitive(string $status, ?string $message): bool {
        if ($status !== 'error' || $message === null) {
            return false;
        }
        foreach (self::SITE_SENSITIVE_REFUSALS as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The grammar document, derived from the engine at emission time.
     *
     * Every set below is a live read of the accessor the validators themselves
     * consult. Adding a value to a closed vocabulary in the engine changes this
     * document on the very next emission with no edit here, which is the entire
     * point: an editor, a schema-aware linter, or a reviewer holding this
     * document is holding the engine's own answer, not a snapshot of it.
     */
    private static function emitSchema(): int {
        try {
            self::boot();
        } catch (\Throwable $t) {
            return self::fail($t->getMessage());
        }

        $schemas = NativeActions::arg_schemas();
        $actions = [];
        foreach (NativeActions::vocabulary() as $action) {
            $actions[$action] = ['args' => $schemas[$action] ?? []];
        }

        echo self::encode([
            'schema' => self::SCHEMA,
            'spec_version' => DUO_SPEC_VERSION,
            'agent_version' => DUO_AGENT_VERSION,
            'derived_from' => [
                'Duo\\Policy::closed_vocabularies()',
                'Duo\\Policy::grammar_patterns()',
                'Duo\\NativeActions::vocabulary()',
                'Duo\\NativeActions::arg_schemas()',
            ],
            // Every consumer of this document is entitled to know what it does
            // NOT describe, in the document rather than in a guide it may never
            // read: an editor built on `vocabularies` alone would happily offer
            // a key the engine refuses, and would do so believing it held the
            // whole grammar. The accessors' own docblocks state the identical
            // boundary — this is that statement, shipped.
            'coverage' => [
                'vocabularies' => 'VALUE vocabularies only — the legal values of a declared field.',
                'patterns' => 'A NAMED SUBSET — the bounded patterns the engine keeps as named constants.',
                'not_included' => [
                    'key vocabularies: which KEYS a surface admits (an actions[] entry\'s allowed keys, the exact '
                        . 'five a providers[] entry requires, the invalidate key set, a table declaration\'s '
                        . 'sections) are equally closed and equally refused, and none is published — several are a '
                        . 'function of a sibling value (an action\'s legal keys depend on its kind), so there is no '
                        . 'flat set to publish',
                    'conditional subsets: where a value is legal only in combination with another (mode=prevented '
                        . 'needs kind in mail/http/queue; kind=database needs selector.type in table/option; '
                        . 'mode=restorable needs scope=database_checkpoint), the CONDITION is not expressible in '
                        . 'these sets — they are the alphabet, never which sentences are well-formed',
                    'unnamed patterns: roughly twenty further inline PCREs in Policy.php alone (identity and '
                        . 'column-name shapes, sha-256 digests, secret-shaped-value screens, the placeholder-brace '
                        . 'scan) have no engine-owned name and are deliberately not scraped into `patterns`',
                    'pin-dependent halves: ref, token, and ledger kind vocabularies publish their engine-owned '
                        . 'BASE only; the declared half is a property of one pin set plus one site.duo.json, '
                        . 'reported per run by `duo manifest-validate <manifests-dir> [--site=<repo>]`',
                ],
            ],
            'vocabularies' => Policy::closed_vocabularies(),
            'patterns' => Policy::grammar_patterns(),
            'native_actions' => $actions,
            'deferred' => self::deferred(),
        ]) . "\n";
        return 0;
    }

    /** @param array<string,mixed> $report */
    private static function render(array $report): void {
        echo "manifests dir: {$report['manifests_dir']}\n";
        echo 'site repo:     ' . ($report['site'] ?? '(none — site policy is NOT part of this check; see deferred)') . "\n";
        echo "spec_version:  {$report['spec_version']}\n";
        echo "\nper manifest (each loaded on its own, so every verdict shows in one run):\n";
        foreach ($report['manifests'] as $row) {
            echo "  [{$row['status']}] {$row['name']} — {$row['file']}\n";
            if ($row['message'] !== null) {
                echo '          ' . $row['message'] . "\n";
            }
            if (isset($row['pinned_set_note'])) {
                echo '          note: ' . $row['pinned_set_note'] . "\n";
            }
            if (isset($row['site_policy_note'])) {
                echo '          ' . $row['site_policy_note'] . "\n";
            }
        }

        $pinned = $report['pinned_set'];
        $names = $pinned['names'] === [] ? '(none)' : implode(', ', $pinned['names']);
        echo "\npinned set — cross-manifest guards over " . count($pinned['names'])
            . " manifest(s) in {$report['manifests_dir']}: $names\n";
        echo "  [{$pinned['status']}] cross-manifest guards\n";
        if ($pinned['message'] !== null) {
            echo '          ' . $pinned['message'] . "\n";
            // A cross-manifest refusal names manifests, not paths. Print the
            // co-loaded set's files under the failure so the names in the
            // engine's own message resolve to something an editor can open.
            foreach ($pinned['files'] as $name => $file) {
                echo "          - $name: $file\n";
            }
        }
        if (isset($pinned['site_policy_note'])) {
            echo '          ' . $pinned['site_policy_note'] . "\n";
        }

        echo "\ndeferred — NOT checked here, and not checked anywhere else by this command:\n";
        foreach ($report['deferred'] as $row) {
            echo "  [deferred] {$row['surface']} — {$row['check']}\n";
            echo '             ' . $row['why'] . "\n";
        }

        $s = $report['summary'];
        // Not "deferred to a live target": one of these rows is the site-policy
        // half, which is a limitation of what this command was GIVEN rather
        // than of what a laptop can answer. A summary that called it a
        // live-target check would misfile the one entry an author can act on
        // immediately, by passing --site.
        echo "\nsummary: {$s['checked']} manifest(s) checked, {$s['ok']} ok, {$s['error']} error; "
            . "pinned set {$pinned['status']}; " . count($report['deferred'])
            . " check(s) NOT performed here (see the deferred list above)\n";
    }

    /**
     * Both documents are machine-read (editor/LSP integration is the stated
     * consumer), so slashes stay unescaped and keys stay in engine order —
     * declared order is load-bearing in the refusal messages that print these
     * same sets, and re-sorting here would publish an order the engine does not
     * use.
     *
     * @param array<string,mixed> $document
     */
    private static function encode(array $document): string {
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('duo: manifest-validate could not encode its report');
        }
        return $json;
    }

    /**
     * Load the engine's pure validation surface into this WordPress-free
     * process.
     *
     * The two version constants are resolved out of agent/duo.php's own source
     * rather than defaulted, exactly as scripts/capability-registry.php already
     * does: `Policy::load()` compares a manifest's declared `spec_version`
     * against DUO_SPEC_VERSION, so leaving it undefined would make every
     * shipped manifest fail this command for a reason that is about the
     * command, not the manifest.
     *
     * `is_multisite()` is deliberately NOT defined here (capability-registry.php
     * defines it because it predates the guard): Policy::assert_single_site()
     * is already function_exists()-guarded precisely so the offline validators
     * run outside WordPress, and defining a WordPress function in a host
     * process that has no WordPress would be claiming something untrue.
     */
    private static function boot(): void {
        $repo = dirname(__DIR__, 2);
        $agent = $repo . '/agent/duo.php';
        if (!is_file($agent)) {
            throw new \RuntimeException("manifest-validate: agent source not found at $agent");
        }
        $source = (string) file_get_contents($agent);
        if (!defined('DUO_AGENT_VERSION')) {
            if (preg_match("/define\('DUO_AGENT_VERSION', '([^']+)'\)/", $source, $m) !== 1) {
                throw new \RuntimeException('manifest-validate: could not resolve DUO_AGENT_VERSION');
            }
            define('DUO_AGENT_VERSION', $m[1]);
        }
        if (!defined('DUO_SPEC_VERSION')) {
            if (preg_match("/define\('DUO_SPEC_VERSION', ([0-9]+)\)/", $source, $m) !== 1) {
                throw new \RuntimeException('manifest-validate: could not resolve DUO_SPEC_VERSION');
            }
            define('DUO_SPEC_VERSION', (int) $m[1]);
        }

        // Policy.php requires NativeActions.php itself. The other three are
        // what Policy::load() reaches: canonical decoding, the autoload
        // vocabulary, and the external review/evidence pair it consults when
        // the directory carries one.
        foreach (['Canon', 'OptionState', 'ManifestDispositions', 'CapabilityRegistry', 'Policy'] as $class) {
            require_once $repo . "/agent/src/$class.php";
        }
    }

    /** @return list<string>|null null when the list is empty or has an empty member */
    private static function names(string $raw): ?array {
        if (trim($raw) === '') {
            return null;
        }
        $names = [];
        foreach (explode(',', $raw) as $name) {
            $name = trim($name);
            if ($name === '') {
                return null;
            }
            if (!in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /** Fail closed on this command's own paths: usage, a bad dir, an unreadable file. */
    private static function fail(string $message): int {
        fwrite(STDERR, "duo: manifest-validate: $message\n");
        return 2;
    }
}

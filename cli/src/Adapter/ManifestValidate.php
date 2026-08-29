<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

use WPrism\AdapterCertification;
use WPrism\AdapterContractGrammar;
use WPrism\AdapterLibrary;
use WPrism\AdapterSources;
use WPrism\Canon;
use WPrism\NativeActions;
use WPrism\Policy;

/**
 * `wprism manifest-validate` — the adapter author's offline grammar check.
 *
 * An adapter author writing a manifest has, until now, had exactly one way to
 * find out whether the declaration is well-formed: install the agent on a
 * WordPress target, pin the manifest, and run a command that reaches that
 * target. That is a slow loop for a question that is answered entirely from
 * the manifest artifact's own bytes — every validator this command drives
 * already refuses at `Policy::load()` time, before any target contact, which is
 * precisely why it can run here on a laptop with no WordPress, no database, and
 * no docker. "From the artifact's own bytes" is not the same as "pure": a
 * manifest declaring an interpreter or regenerator names a PHP FILE in that
 * artifact, and checking the file's class contract means loading it. See the
 * trust boundary below.
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
 * those guards read `site.wprism.json`'s own policy as INPUT: a site-declared
 * table extends the legal ref/token/ledger kind vocabulary, and a site
 * `policy.options` rule is the ratified resolution when two manifests declare
 * one option differently. Loading with a null repo therefore does NOT merely
 * check less — it can refuse a manifest that its real site accepts, with a
 * remediation telling the author to add an override they already have. So
 * `--site=<site-repo-path>` passes the real repo through to both phases; without
 * it, a refusal from either of those two guards is ANNOTATED (never rewritten)
 * as possibly site-resolvable, and the always-emitted deferred list carries the
 * missing half as a permanent, named limitation. `wp wprism manifest-pin` still
 * validates one installed manifest with a null repo, and is still right to: it
 * checks one manifest in isolation, where neither guard has anything to say.
 *
 * The emitted grammar document (`--emit-schema`) follows the same rule one step
 * further: every closed set in it is read from the engine at emission time
 * (Policy::closed_vocabularies(), Policy::grammar_patterns(),
 * NativeActions::vocabulary()/arg_schemas(),
 * AdapterCertification::topLevelKeyPartition()), and the one set the engine
 * keeps as a CONDITION rather than as a list — which `spec_version` integers it
 * accepts — is measured by running the shipped refusal over candidate integers
 * (specWindow()). None of it is authored here, so it cannot describe a grammar
 * the engine stopped enforcing.
 *
 * Trust boundary: point this command only at an adapter source tree or an
 * explicitly selected historical flat library trusted as much as the agent's
 * own — validating a manifest that declares an
 * interpreter or regenerator LOADS that PHP (top level + constructor), the
 * unavoidable cost of checking the class contract at all. For a first look
 * at an unfamiliar out-of-tree package, --no-code skips the code half and
 * reports it as not-performed instead.
 */
final class ManifestValidate {
    /** Envelope of the validation report (both output modes carry it). */
    public const FORMAT = 'wprism-manifest-validation/v1';

    /**
     * Envelope of the emitted grammar document.
     *
     * v2 (WP-4.1) adds two derived blocks the v1 document could not answer and
     * an author had to read source for: `spec_window` — which `spec_version`
     * integers this engine ACCEPTS, measured by running the shipped refusal
     * rather than restated — and `top_level_keys`, the signer's own three-arm
     * partition. Both are additive; every v1 field keeps its name, its
     * contents and its order, so a consumer that only reads `vocabularies`,
     * `patterns`, `native_actions`, `coverage` and `deferred` is unaffected by
     * the bump.
     */
    public const SCHEMA = 'wprism-manifest-grammar/v2';

    /**
     * The two engine refusals whose verdict is a function of the SITE half of
     * policy, matched on the stable substring each one's message is built
     * around:
     *
     *   - the ref/token/ledger kind vocabularies union their engine base with
     *     every `id_kind` declared by a pinned manifest AND by
     *     `site.wprism.json`'s own `policy.tables` (ReferenceKindGrammar::validate_ref_kinds()),
     *     so a manifest referencing a kind the SITE declares is legal there and
     *     refused here;
     *   - two manifests declaring contradictory rules for one option name are
     *     refused unless the site resolves the name with an explicit
     *     `policy.options` override (CrossManifestGuards::validate_no_
     *     conflicting_option_rules()), which this command cannot see without
     *     a site repo;
     *   - two manifests claiming the same plugin or theme with different
     *     ranges are refused unless the site resolves the claim with an
     *     explicit `policy.adapter_claims` row naming which is in force
     *     (AdapterContractGrammar::validate_no_conflicting_adapter_claims(),
     *     WP-5.5, spec/repo-format.md § v3.13) — the third guard whose verdict
     *     is a function of the site half, added here in the change that made
     *     it one, because a list that lagged the engine would send an author
     *     to add an override they already have.
     *
     * Matched, never rewritten: the engine's message is the author's actual
     * coordinate and this command has no business editing it. The annotation is
     * a separate field saying the refusal may not be one on the real site — and
     * naming the flag that answers the question. Getting this wrong in the safe
     * direction (annotating a refusal a site could not have fixed) costs a line
     * of output; getting it wrong in the other direction sends an author to
     * "add a site.wprism.json override" they already have.
     */
    private const SITE_SENSITIVE_REFUSALS = [
        'kind vocabulary is closed',
        'declare contradictory rules for options.',
        'conflicting ownership with no v2 composition rule',
    ];

    /** Verbatim annotation for a refusal that a real site policy may resolve. */
    private const SITE_NOTE = 'note: this refusal can be resolved by a site.wprism.json this offline check was not '
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
     * `--no-code` adds a third species: a check this command CAN perform and
     * was told not to. It appears only on a run that passed the flag, because
     * on any other run it would be false — but on a run that did pass it, it
     * appears in the same always-emitted list as everything else, so a
     * `--no-code` pass can never be mistaken for a full one.
     *
     * @return list<array{status:string, surface:string, check:string, why:string}>
     */
    private static function deferred(bool $noCode = false): array {
        $rows = [];
        if ($noCode) {
            $rows[] = [
                'surface' => 'interpreter / post_types[].regen_dependency.regenerator',
                'check' => 'Policy::interpreters() / Policy::regenerators()',
                'why' => '--no-code: declared interpreter/regenerator PHP was not loaded or contract-checked — '
                    . 'use for a first look at an untrusted package; a full validation requires a trusted dir',
            ];
        }
        $rows = array_merge($rows, [
            [
                'surface' => 'site.wprism.json policy.tables / policy.options',
                'check' => 'ReferenceKindGrammar::validate_ref_kinds() / CrossManifestGuards::validate_no_conflicting_option_rules()',
                'why' => 'both guards take the SITE half of policy as INPUT, not just the manifests: a table '
                    . 'declared in site.wprism.json extends the legal ref/token/ledger kind vocabulary, and a '
                    . 'site policy.options rule is the explicit resolution for one option two manifests declare '
                    . 'differently. Without --site=<site-repo-path> this command loads with no site policy at '
                    . 'all, so either guard can refuse a manifest its real site accepts (such a refusal is '
                    . 'annotated as possibly site-resolvable). With --site, the site repo read is the one on '
                    . 'THIS machine — whether the target runs that revision is a fact about the target',
            ],
            [
                'surface' => 'PHP / database / WordPress / site mode',
                'check' => 'PlatformCompatibility::current_facts() / PlatformCompatibility::assert_supported()',
                'why' => 'the platform declaration is separate from one adapter manifest, and the observed PHP, '
                    . 'database engine/version, exact WordPress version, and site topology exist only on a loaded '
                    . 'target. Policy::load() checks those facts before repository reads on the real product path',
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
                    . 'the in-memory registry is stale mid-request), so the in-scope name set does not exist yet',
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
                'surface' => 'dispositions/',
                'check' => 'AdapterRegistry::report()',
                'why' => 'certification is a reviewed claim evaluated against one target and the plugin version '
                    . 'installed on it. Nothing here says whether a capability is certified, exercised, '
                    . 'uncertified, or incompatible for your site — run `wprism capabilities <env>` for that',
            ],
            [
                'surface' => 'block_attrs / shortcode_attrs / json_refs / key_refs',
                'check' => 'Lint::scan_tree()',
                'why' => 'reference rules are grammar-checked here; whether a captured id resolves to a live '
                    . 'entity, and the home-URL rewriting those findings are computed against, are read off a '
                    . 'live site',
            ],
        ]);
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
        $noCode = false;

        // A repeated flag is refused rather than last-wins (the same posture
        // `wprism driver-capabilities` takes): a second --pins silently replacing
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
            } elseif ($arg === '--no-code') {
                // Duplicate-refused by the $seen check above, like every other
                // flag: a repeated --no-code is a command line nobody wrote on
                // purpose, and last-wins on a TRUST flag is the wrong default.
                $noCode = true;
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
                    return self::fail('--site needs the path of a wprism site repo (the directory holding site.wprism.json)');
                }
            } elseif (str_starts_with($arg, '-')) {
                return self::fail("unsupported flag '$arg'");
            } elseif ($dir !== null) {
                return self::fail("expected exactly one <adapter-library>, got a second argument '$arg'");
            } else {
                $dir = $arg;
            }
        }

        if ($pinSelection !== null && $all) {
            return self::fail('--pins and --all are mutually exclusive');
        }
        if ($emitSchema) {
            if ($dir !== null || $manifestSelection !== null || $pinSelection !== null || $all
                || $siteArg !== null || $noCode) {
                return self::fail(
                    '--emit-schema takes no adapter library, no manifest/pin selection, no --site, and no --no-code '
                    . '— the grammar is read from the engine, not from a directory of declarations, one site, or '
                    . 'any manifest-shipped code'
                );
            }
            return self::emitSchema();
        }
        if ($dir === null) {
            return self::fail('an <adapter-library> argument is required (or --emit-schema)');
        }

        $resolved = is_dir($dir) ? realpath($dir) : false;
        if ($resolved === false) {
            return self::fail("'$dir' is not a directory");
        }

        // The site half is optional and, when present, must be a real wprism site
        // repo: handing Policy::load() a directory with no site.wprism.json would
        // fail per manifest with the engine's "not a wprism site repo?" message on
        // every row, which reads as "your manifests are broken". This is a
        // usage error about the flag, so it is refused here, once, as one.
        $site = null;
        if ($siteArg !== null) {
            $siteResolved = is_dir($siteArg) ? realpath($siteArg) : false;
            if ($siteResolved === false) {
                return self::fail("--site '$siteArg' is not a directory");
            }
            if (!is_file($siteResolved . '/site.wprism.json')) {
                return self::fail(
                    "--site '$siteResolved' has no site.wprism.json — --site takes the wprism SITE REPO (the directory "
                    . 'holding site.wprism.json), whose policy half these manifests are validated against'
                );
            }
            $site = $siteResolved;
        }

        try {
            self::boot();
        } catch (\Throwable $t) {
            return self::fail($t->getMessage());
        }

        // One physical inventory object is constructed once and passed to
        // EVERY Policy load below. Production no longer reads
        // WPRISM_MANIFESTS_DIR; letting this host command set that retired global
        // would make the report describe the checkout's default packages while
        // printing paths from the caller's input. A source checkout is the
        // normal authoring input. The strict legacy reader remains available
        // only when the caller explicitly names a complete historical flat
        // library, for archived-ref validation and migration rehearsal.
        $siteAdaptersDir = $site === null ? false : realpath($site . '/' . AdapterSources::SITE_DIR);
        $validatingSiteSource = $siteAdaptersDir !== false && $siteAdaptersDir === $resolved;
        try {
            $adapterLibrary = $validatingSiteSource
                ? AdapterLibrary::fromSourceTree(dirname(__DIR__, 3))
                : self::readLibrary($resolved);
            $available = $validatingSiteSource
                ? self::siteManifests($resolved)
                : self::libraryManifests($adapterLibrary);
        } catch (\Throwable $t) {
            return self::fail($t->getMessage());
        }
        if ($available === []) {
            return self::fail("$resolved contains no adapter manifests");
        }

        $selected = $manifestSelection ?? array_keys($available);
        $pins = $pinSelection ?? array_keys($available);
        foreach ([['--manifest', $selected], ['--pins', $pins]] as [$flag, $requested]) {
            foreach ($requested as $name) {
                if (!isset($available[$name])) {
                    return self::fail("$flag names '$name', which is not an adapter manifest in $resolved");
                }
            }
        }

        // Pre-flight the site half ALONE, before any manifest is judged against
        // it. A malformed site.wprism.json is an input this command was handed, not
        // a verdict about anybody's manifest — but every phase below loads that
        // same file, so without this the site's own single refusal is repeated
        // once per manifest plus once for the pin set, and an author reads
        // sixteen "your manifest is broken" rows for one broken line that is not
        // in any of them. Empty pins so nothing but the site half can speak.
        if ($site !== null) {
            try {
                Policy::load($site, [], false, null, $adapterLibrary);
            } catch (\Throwable $t) {
                // The empty-pin load still walks the adapter library
                // (sources, dispositions, platform boundary), so a defect
                // THERE also surfaces here. Blame --site only when the engine
                // names the site file; anything else is the input dir's own
                // problem and gets the message unprefixed.
                // A refusal the adapter-source scan knows by code (a site
                // copy shadowing a shipped name, a malformed trust root…) is
                // reported typed, whatever words it contains — its sentence
                // may well mention site.wprism.json, since that file is where
                // the remedy lives.
                $typed = self::typedSourceRefusal($adapterLibrary, $site, $t->getMessage());
                if ($typed !== null) {
                    return self::fail($typed);
                }
                if (str_contains($t->getMessage(), 'site.wprism.json')) {
                    return self::fail(
                        "--site '$site' has a site.wprism.json this command cannot load, so no manifest was judged "
                        . 'against it: ' . $t->getMessage()
                    );
                }
                return self::fail($t->getMessage());
            }
        }

        $rows = [];
        foreach ($selected as $name) {
            $row = ['name' => $name, 'file' => $available[$name], 'status' => 'ok', 'message' => null];
            try {
                // The REAL loader. One manifest at a time so a broken
                // declaration does not hide every later manifest's verdict —
                // an author fixing three manifests should need one run, not
                // three.
                $policy = Policy::load($site, [$name], false, null, $adapterLibrary);
                // --no-code stops here: resolving the declared code half means
                // loading it, and an author looking at an untrusted package
                // asked not to. The skip is reported, never silent.
                if (!$noCode) {
                    self::resolve($policy);
                }
            } catch (\Throwable $t) {
                $row['status'] = 'error';
                $row['message'] = $t->getMessage();
            }
            // issue #3325: a manifest carrying a `wprism adapter-draft` `_draft` sidecar
            // has facts validated by the loop above and proposals/unsupported that
            // are INERT here by construction (trigger keys renamed so the blind
            // ref-kind walk cannot collect them, no live section mirrors them). Say
            // so, per the acceptance's "visibly distinguishes facts / proposals /
            // unsupported" — read from the file's own bytes, never re-validated.
            $draft = self::draft_summary($available[$name]);
            if ($draft !== null) {
                $row['draft'] = $draft;
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
            $policy = Policy::load($site, $pins, false, null, $adapterLibrary);
            if (!$noCode) {
                self::resolve($policy);
            }
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
            'spec_version' => WPRISM_SPEC_VERSION,
            'manifests_dir' => $resolved,
            'site' => $site,
            'code' => $noCode ? 'skipped' : 'resolved',
            'status' => $status,
            'manifests' => $rows,
            'pinned_set' => $pinned,
            'deferred' => self::deferred($noCode),
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
     * adapter package being validated, and nothing about them needs a
     * target. Calling them here, inside the caller's try/catch, turns their
     * refusals into ordinary per-manifest errors carrying the engine's own
     * message (which already names the exact missing path or the exact class it
     * wanted).
     *
     * Offline is not inert, and this is the honest statement of the cost:
     * checking that a file defines `\WPrism\Interpreters\<Name>` requires the file
     * to have been `require`d, so its top level RUNS, and `new $class($this)`
     * runs its constructor. That is the same trust decision Policy::
     * package boundary already documents for the agent itself — the adapter
     * source tree is operator-controlled — and it is why this command's own
     * docblock says to point it only at a directory trusted that far. What is
     * NOT invoked is the contract methods: `post_meta_rule()` / `regenerate()`
     * are live operations and stay deferred.
     *
     * `--no-code` is the escape for the one case that boundary does not cover —
     * a first look at an untrusted package — and skipping is reported, never
     * silent (see deferred()).
     */
    private static function resolve(Policy $policy): void {
        $policy->interpreters();
        $policy->regenerators();
    }

    /**
     * The facts/proposals/unsupported counts of a `wprism adapter-draft` `_draft`
     * sidecar, or null when the manifest carries none. Read from the file's own
     * bytes (decoded, not re-validated): the facts are the real classification
     * sections this command already validated above; the `_draft` proposals and
     * unsupported are inert and unvalidated here. Any read/decode problem returns
     * null rather than speaking — a malformed file is the loader's verdict to give,
     * not this annotation's.
     *
     * @return array{facts:int, proposals:int, unsupported:int}|null
     */
    private static function draft_summary(string $file): ?array {
        try {
            $manifest = Canon::decode(Canon::read_file($file));
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($manifest) || !isset($manifest['_draft']) || !is_array($manifest['_draft'])) {
            return null;
        }
        $facts = 0;
        foreach (['options', 'post_meta', 'term_meta', 'user_meta'] as $section) {
            $facts += count((array) ($manifest[$section] ?? []));
        }
        $proposals = 0;
        foreach ((array) ($manifest['_draft']['proposals'] ?? []) as $bucket) {
            $proposals += count((array) $bucket);
        }
        $unsupported = count((array) ($manifest['_draft']['unsupported'] ?? []));
        return ['facts' => $facts, 'proposals' => $proposals, 'unsupported' => $unsupported];
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
            'spec_version' => WPRISM_SPEC_VERSION,
            'agent_version' => WPRISM_AGENT_VERSION,
            'derived_from' => [
                'WPrism\\Policy::closed_vocabularies()',
                'WPrism\\Policy::grammar_patterns()',
                'WPrism\\NativeActions::vocabulary()',
                'WPrism\\NativeActions::arg_schemas()',
                'WPrism\\AdapterContractGrammar::validate_adapter_contract() (probed)',
                'WPrism\\AdapterContractGrammar::implemented_feature_rows()',
                'WPrism\\AdapterContractGrammar::feature_section_grammars()',
                'WPrism\\AdapterCertification::topLevelKeyPartition()',
                'WPrism\\AdapterCertification::certificateArms()',
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
                        . 'five a providers[] entry requires plus its optional contracts/requires keys, the '
                        . 'invalidate key set, a table declaration\'s '
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
                        . 'BASE only; the declared half is a property of one pin set plus one site.wprism.json, '
                        . 'reported per run by `wprism manifest-validate <adapter-library> [--site=<repo>]`',
                ],
                'spec_window' => 'MEASURED, not declared — the accepted set is whatever the shipped '
                    . 'validate_adapter_contract() answers over the probed integers, so a widened or narrowed '
                    . 'window moves this block with no edit here.',
                'top_level_keys' => 'The SIGNER\'s partition and the load-time base set for every accepted '
                    . 'manifest version. A manifest may declare keys '
                    . 'BEYOND these arms when a declared engine feature claims them, so this block is the base '
                    . 'set rather than the whole answer for one manifest (see `enforced_by`, `status`). Those '
                    . 'further keys carry their arm in `engine_features.implemented.<feature>.sections`, never '
                    . 'here: the partition and the roster are two definitions of two disjoint sets, and the '
                    . 'signer refuses an overlap between them (§ v3.21).',
                // WP-6.5. The gap this closes was found by walking the
                // decentralized authoring path as a stranger: the four
                // post-v3 SECTIONS (`attr_id_codecs`, `column_codecs`,
                // `declaration_evidence`, `body_refs`) and the channel key
                // `engine_features` itself appeared NOWHERE in this document —
                // not in `vocabularies` (they are keys, not values), not in
                // `top_level_keys` (which publishes the signer's base partition
                // and says so), and not in `not_included`. So an author
                // reading the engine's own answer could not learn that the
                // features exist, and had to read three engine source files to
                // author one. It is published rather than added to
                // `not_included` because the answer is DERIVABLE from the same
                // validator every other block here is derived from — and a
                // boundary a document could have simply stated is not a
                // boundary, it is an omission.
                // WP-6.6 widened this block twice over, because publishing that
                // a section EXISTS turned out to answer neither question an
                // author actually has. `sections[].grammar` is the section's
                // own closed key set, projected by the collaborator that
                // validates it, so authoring one no longer means reading
                // BodyRefGrammar.php; `sections[].arm` is what a certificate
                // says about it, which used to be readable nowhere at all — the
                // measured consequence being an adapter that loaded on every
                // site and could not be certified, with the document silent
                // about why (spec/repo-format.md § v3.21).
                'engine_features' => 'The post-v3 declaration channel (spec/repo-format.md § v3.2), derived from '
                    . 'the same `IMPLEMENTED_FEATURES` rows the validator refuses against: each feature\'s '
                    . '`since` is the first spec_version whose grammar HAS its sections, and `keys` are the '
                    . 'top-level sections declaring it ADMITS (§ v3.3\'s growth rule). A feature may legitimately '
                    . 'claim no key — one that widens a value vocabulary inside a section that already exists. '
                    . 'Names are ENGINE-OWNED: an adapter declares one and never mints one. `sections` carries, '
                    . 'per claimed key, the certificate ARM the roster classifies it into (§ v3.21 — `entity` '
                    . 'and `field` become covered surfaces in a signed claim, `non_surface` covers no state) '
                    . 'and that section\'s own value GRAMMAR, published by the validator that owns it.',
            ],
            'spec_window' => self::specWindow(),
            'engine_features' => self::engineFeatures(),
            'top_level_keys' => self::topLevelKeys(),
            'vocabularies' => Policy::closed_vocabularies(),
            'patterns' => Policy::grammar_patterns(),
            'native_actions' => $actions,
            'deferred' => self::deferred(),
        ]) . "\n";
        return 0;
    }

    /**
     * Which `spec_version` integers this engine accepts — MEASURED by asking
     * the shipped refusal, never by restating its condition (WP-4.1).
     *
     * The condition is a few lines inside
     * `AdapterContractGrammar::validate_adapter_contract()`, and writing the
     * answer here — `[WPRISM_SPEC_VERSION]` when this was written, now
     * `[WPRISM_SPEC_VERSION - 1, WPRISM_SPEC_VERSION]` — would be a second copy of it
     * that stays right only until the day the window changes, which is
     * precisely the day a consumer needs this document to be right. The window
     * HAS since changed (WP-4.2), and this block followed it with no edit here;
     * that is the whole argument for the technique, now with a measurement
     * behind it. So each candidate integer is handed to the real validator on a
     * minimal manifest and the ACCEPTED ones are reported — the technique
     * `tools/wire-surface.php` already uses for the grammars it publishes: run
     * the shipped refusal and print what it answers.
     *
     * The probe window is deliberately wider than the engine's own answer
     * (N-2 … N+1) so a widened window shows up as a wider `accepted` list
     * rather than as a silently clipped one, and `probed` publishes the range
     * so a reader can tell "refused" from "never asked". N-2 in particular is
     * ASKED and refused today, which is what makes "the floor is exactly N-1" a
     * measurement here rather than an assumption; `tools/wire-surface.php`
     * refuses the release on that same equality (register row R-18).
     *
     * A minimal manifest is the right probe subject because every other check
     * in `validate_adapter_contract()` is keyed on a declaration this manifest
     * does not carry (`interpreter`, `plugin`/`version_range`,
     * `theme`/`theme_version_range`), so the only verdict being measured is
     * the spec-version one.
     *
     * `n_minus_1_accepted` is the fact spec v3's acceptance window (WP-4.2)
     * turned from false to true; it is REPORTED here, never assumed, and the
     * status line names the section that specifies it. Because it is measured,
     * the day WP-4.2 shipped the window this block moved with no edit here —
     * which is the property the technique was chosen for.
     *
     * @return array<string,mixed>
     */
    /**
     * The engine features this engine IMPLEMENTS, and what each one claims
     * (WP-6.5).
     *
     * DERIVED, from the exact rows the validator itself refuses against:
     * `implemented_feature_rows()` is the same constant `assert_engine_
     * features()` compares a declaration against and `assert_section_versions()`
     * reads section versions out of. So a feature added to the engine appears
     * here on the very next emission with no edit in this file — the same
     * technique, and the same argument, as `specWindow()` below: a literal typed
     * here would equal the engine on the day it is typed and stop equalling it
     * on the day the engine grows, which is the day a consumer needs this
     * document to be right.
     *
     * @return array<string,mixed>
     */
    private static function engineFeatures(): array {
        return [
            'declared_by' => 'the manifest\'s own top-level `engine_features` list (a non-empty, sorted, '
                . 'duplicate-free list of strings)',
            'implemented' => AdapterContractGrammar::implemented_feature_rows(),
            'enforced_by' => 'WPrism\\AdapterContractGrammar::assert_engine_features()',
            'certificate_arms' => AdapterCertification::certificateArms(),
            'status' => 'A manifest declaring a name in `implemented` loads; one declaring any other name is '
                . 'refused BY FEATURE NAME, naming this list. A declared feature\'s `keys` are admitted as '
                . 'top-level sections on top of `top_level_keys` (§ v3.3\'s growth rule), which is why that block '
                . 'is the base set rather than the whole answer for one manifest. Each claimed key\'s '
                . '`sections.<key>.arm` is one of `certificate_arms` and decides what `wprism adapter certify` '
                . 'says about that section: `entity`/`field` put it in the signed claim\'s `surfaces` list, '
                . '`non_surface` covers no state (§ v3.21). A key with no arm is unrepresentable — the roster '
                . 'is a map, so a feature classifies every key it claims in the row that claims it.',
        ];
    }

    private static function specWindow(): array {
        $supported = WPRISM_SPEC_VERSION;
        $probed = [];
        $accepted = [];
        for ($candidate = $supported - 2; $candidate <= $supported + 1; $candidate++) {
            $probed[] = $candidate;
            try {
                AdapterContractGrammar::validate_adapter_contract([
                    'name' => 'wprism-manifest-grammar-probe',
                    'spec_version' => $candidate,
                ]);
                $accepted[] = $candidate;
            } catch (\Throwable) {
                // Refused: this integer is outside the shipped window. The
                // message is the author's coordinate, not this document's —
                // running the command on a real manifest prints it verbatim.
            }
        }

        return [
            'declared_by' => 'the manifest\'s own top-level `spec_version` (int)',
            'engine_supported' => $supported,
            'probed' => $probed,
            'accepted' => $accepted,
            'n_minus_1_accepted' => in_array($supported - 1, $accepted, true),
            'enforced_by' => 'WPrism\\AdapterContractGrammar::validate_adapter_contract()',
            'status' => 'This engine accepts exactly the integers in `accepted`. The N/N-1 acceptance window '
                . '(spec/repo-format.md "Spec v3" § v3.1) is ENFORCED here: an integer outside the window '
                . 'refuses wholesale naming the window, a manifest inside it that declares a section this '
                . 'engine implements only at a higher version refuses naming the section, and an absent or '
                . 'non-integer `spec_version` keeps the older refusal because it is not a version at all.',
        ];
    }

    /**
     * The closed top-level manifest key set, read from the engine constant the
     * signer classifies against (WP-4.1).
     *
     * Published because an author has no other way to learn it: the partition
     * is private to `AdapterCertification`, and until WP-4.3 the only place it
     * spoke was a certificate-signing run, which most authors reach long after
     * the typo. `enforced_by`/`not_enforced_by` are in the document rather than
     * in a guide because authors need the same closed answer at every accepted
     * manifest version. The known v2 `_draft` authoring sidecar is the sole
     * load-only exception and remains unsignable.
     *
     * The three arms are kept apart rather than merged: a key's ARM decides
     * what a derived ratification says about it (an entity section becomes a
     * covered surface; a non-surface key covers nothing), so flattening them
     * would publish less than the engine knows. `all` is the merged, sorted
     * set for a consumer that only wants membership.
     *
     * @return array<string,mixed>
     */
    private static function topLevelKeys(): array {
        $partition = AdapterCertification::topLevelKeyPartition();
        $all = array_merge(
            $partition['entity_sections'],
            $partition['field_sections'],
            $partition['non_surface_keys']
        );
        sort($all, SORT_STRING);

        return $partition + [
            'all' => $all,
            'enforced_by' => 'WPrism\\AdapterCertification::siteRatification() — signing refuses a key it cannot '
                . 'classify, by name, at every spec_version; and '
                . 'WPrism\\AdapterContractGrammar::validate_adapter_contract() — loading any accepted manifest '
                . 'version refuses an unrecognised top-level key, by name, before any value in it is read',
            'not_enforced_by' => 'No accepted manifest version. The recognised `_draft` authoring sidecar is '
                . 'admitted only at v2 and remains refused by the signer; arbitrary keys have no exception',
            'status' => 'ENFORCED from `spec_version: 2` (spec/repo-format.md "Spec v3" § v3.3). The set '
                . 'GROWS only through § v3.2\'s channel: a '
                . 'key claimed by a declared engine feature this engine implements is admitted beside these '
                . 'arms, a key claimed by a feature it does not implement refuses by FEATURE name, and a key '
                . 'nothing claims refuses as an unrecognised section. `_draft` is the recognised v2 authoring '
                . 'sidecar and is refused from v3; it must be stripped before install.',
        ];
    }

    /** @param array<string,mixed> $report */
    private static function render(array $report): void {
        echo "adapter library: {$report['manifests_dir']}\n";
        echo 'site repo:     ' . ($report['site'] ?? '(none — site policy is NOT part of this check; see deferred)') . "\n";
        echo 'manifest code: ' . ($report['code'] === 'skipped'
            ? '--no-code — declared interpreter/regenerator PHP was NOT loaded or contract-checked'
            : 'resolved (declared interpreter/regenerator files were loaded and contract-checked)') . "\n";
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
            if (isset($row['draft'])) {
                $d = $row['draft'];
                echo "          draft: {$d['facts']} facts validated, {$d['proposals']} proposals + "
                    . "{$d['unsupported']} unsupported are INERT and unvalidated here — run "
                    . "'wprism adapter-draft --check-proposals'\n";
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
            throw new \RuntimeException('wprism: manifest-validate could not encode its report');
        }
        return $json;
    }

    /**
     * Resolve an explicitly selected shipped-library input.
     *
     * Source checkouts are the authoring path. A flat directory is accepted
     * only through AdapterLibrary's strict historical reader: it must carry
     * the complete platform/disposition/runtime closure, so a loose directory
     * of JSON files cannot become production policy by accident.
     */
    private static function readLibrary(string $root): AdapterLibrary {
        if (is_dir($root . '/adapter-packages') || is_dir($root . '/platform/adapter-library')) {
            return AdapterLibrary::fromSourceTree($root);
        }
        return AdapterLibrary::fromLegacyFlatDirectory($root);
    }

    /** @return array<string,string> manifest name => canonical manifest path */
    private static function libraryManifests(AdapterLibrary $library): array {
        $available = [];
        foreach ($library->packages() as $package) {
            $available[$package->name()] = $package->manifestPath();
        }
        ksort($available, SORT_STRING);
        return $available;
    }

    /**
     * Site adapters remain a distinct, repository-owned source rather than a
     * shipped library. The site loader reserves its trust documents by name;
     * mirror that selection here only to choose which rows to report, while
     * Policy performs all validation against the explicit shipped library.
     *
     * @return array<string,string> manifest name => canonical manifest path
     */
    private static function siteManifests(string $directory): array {
        $available = [];
        foreach (glob(rtrim($directory, '/') . '/*.json') ?: [] as $file) {
            if (basename($file) === AdapterSources::SITE_AUTHORITIES_FILE
                || basename($file) === AdapterSources::SITE_DELEGATIONS_FILE) {
                continue;
            }
            if (!is_readable($file)) {
                throw new \RuntimeException("$file is not readable");
            }
            $available[basename($file, '.json')] = $file;
        }
        ksort($available, SORT_STRING);
        return $available;
    }

    /**
     * Load the engine's pure validation surface into this WordPress-free
     * process.
     *
     * The two version constants are resolved out of agent/wprism.php's own source
     * rather than defaulted, exactly as scripts/capability-registry.php already
     * does: `Policy::load()` compares a manifest's declared `spec_version`
     * against WPRISM_SPEC_VERSION, so leaving it undefined would make every
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
        $repo = dirname(__DIR__, 3);
        $agent = $repo . '/agent/wprism.php';
        if (!is_file($agent)) {
            throw new \RuntimeException("manifest-validate: agent source not found at $agent");
        }
        $source = (string) file_get_contents($agent);
        if (!defined('WPRISM_AGENT_VERSION')) {
            if (preg_match("/define\('WPRISM_AGENT_VERSION', '([^']+)'\)/", $source, $m) !== 1) {
                throw new \RuntimeException('manifest-validate: could not resolve WPRISM_AGENT_VERSION');
            }
            define('WPRISM_AGENT_VERSION', $m[1]);
        }
        if (!defined('WPRISM_SPEC_VERSION')) {
            if (preg_match("/define\('WPRISM_SPEC_VERSION', ([0-9]+)\)/", $source, $m) !== 1) {
                throw new \RuntimeException('manifest-validate: could not resolve WPRISM_SPEC_VERSION');
            }
            define('WPRISM_SPEC_VERSION', (int) $m[1]);
        }

        // Policy.php requires NativeActions.php — and AdapterRegistry.php —
        // itself. The other three are what Policy::load() reaches: canonical
        // decoding, the autoload vocabulary, and the external review document
        // it consults when the directory carries one.
        //
        // AdapterCertification joined the list for --emit-schema (WP-4.1),
        // which publishes its top-level key partition. It was already inside
        // the static require closure this command's guard scans (signed
        // site-adapter validation reaches it from AdapterSources), so nothing
        // new enters the WordPress-reach allowlist — it is simply loaded up
        // front now instead of on the first signed-adapter path, which is the
        // cheapest way for the emitter to name a class it does not own.
        $wprismAgentClassmap = require $repo . '/agent/wprism-classmap.php';
        if (!is_array($wprismAgentClassmap)) {
            throw new \RuntimeException('manifest-validate: agent/wprism-classmap.php did not return a map');
        }
        $wprismAgentFiles = [];
        foreach ($wprismAgentClassmap as $wprismAgentPath) {
            $wprismAgentFiles[basename((string) $wprismAgentPath, '.php')] = (string) $wprismAgentPath;
        }
        foreach (['Canon', 'OptionState', 'ManifestDispositions', 'Policy', 'AdapterCertification'] as $class) {
            $wprismAgentFile = $wprismAgentFiles[$class] ?? null;
            if (!is_string($wprismAgentFile)) {
                throw new \RuntimeException('manifest-validate: agent source ' . $class . '.php is absent from agent/wprism-classmap.php');
            }
            require_once $repo . '/agent/' . $wprismAgentFile;
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
    /**
     * A site-source refusal, typed. `Policy::load()` throws the loader's
     * sentence and nothing else; the catalog scan (`AdapterSources::survey()`)
     * collects the SAME refusal as a row that carries its reason code and
     * remediation. When the row exists, the sentence is prefixed with the
     * bracketed code every other host refusal prints (`[shadows_shipped] …`)
     * and followed by the remediation — an author who validates the site
     * copy of a shipped name before stating the override reads the verb that
     * states it, not only "rename or remove" (T6 walk S4). Null when the
     * survey has no row for this sentence — the caller prints it as it was.
     */
    private static function typedSourceRefusal(
        AdapterLibrary $adapterLibrary,
        string $site,
        string $message
    ): ?string {
        try {
            $survey = AdapterSources::survey_library($adapterLibrary, $site);
        } catch (\Throwable $t) {
            return null;
        }
        foreach ((array) ($survey['refusals'] ?? []) as $row) {
            if (!is_array($row) || (string) ($row['message'] ?? '') !== $message) {
                continue;
            }
            $code = (string) ($row['code'] ?? '');
            $remediation = (string) ($row['remediation'] ?? '');

            return ($code !== '' ? "[$code] " : '') . $message
                . ($remediation !== '' ? "\n       remediation: $remediation" : '');
        }

        return null;
    }

    private static function fail(string $message): int {
        fwrite(STDERR, "wprism: manifest-validate: $message\n");
        return 2;
    }
}

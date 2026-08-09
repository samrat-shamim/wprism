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
 * run too. Loading with a null repo is the established offline shape — the
 * `wp duo manifest-pin` handler already validates one installed manifest that
 * way, for the same reason: a site repo is not needed to answer a question
 * about the manifest itself.
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
     * Checks that genuinely need a live target, with the engine symbol that
     * owns each one so a reader can go read it rather than take this list's
     * word for it. Emitted unconditionally — see this class's docblock for why
     * "we printed nothing" may never be readable as "we checked everything".
     *
     * @return list<array{status:string, surface:string, check:string, why:string}>
     */
    private static function deferred(): array {
        $rows = [
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
            if ($dir !== null || $manifestSelection !== null || $pinSelection !== null || $all) {
                return self::fail(
                    '--emit-schema takes no manifests dir and no manifest/pin selection — the grammar is read '
                    . 'from the engine, not from a directory of declarations'
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
                Policy::load(null, [$name]);
            } catch (\Throwable $t) {
                $row['status'] = 'error';
                $row['message'] = $t->getMessage();
            }
            $rows[] = $row;
        }

        $pinned = ['names' => $pins, 'status' => 'ok', 'message' => null];
        try {
            Policy::load(null, $pins);
        } catch (\Throwable $t) {
            $pinned['status'] = 'error';
            $pinned['message'] = $t->getMessage();
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
        }

        $pinned = $report['pinned_set'];
        $names = $pinned['names'] === [] ? '(none)' : implode(', ', $pinned['names']);
        echo "\npinned set — cross-manifest guards over " . count($pinned['names']) . " manifest(s): $names\n";
        echo "  [{$pinned['status']}] cross-manifest guards\n";
        if ($pinned['message'] !== null) {
            echo '          ' . $pinned['message'] . "\n";
        }

        echo "\ndeferred — NOT checked here, and not checked anywhere else by this command:\n";
        foreach ($report['deferred'] as $row) {
            echo "  [deferred] {$row['surface']} — {$row['check']}\n";
            echo '             ' . $row['why'] . "\n";
        }

        $s = $report['summary'];
        echo "\nsummary: {$s['checked']} manifest(s) checked, {$s['ok']} ok, {$s['error']} error; "
            . "pinned set {$pinned['status']}; " . count($report['deferred']) . " check(s) deferred to a live target\n";
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

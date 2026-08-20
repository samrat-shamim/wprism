<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression harness for
 * DUO-3339/B2: the THIRD adapter source — `<plugin-dir>/duo-adapter.json`,
 * bundled by an active plugin.
 *
 * Nothing is faked about the scan. Every check below drives the REAL
 * `AdapterSources`/`Policy`/`RepositoryCompiler` over REAL fixture files this
 * test writes to scratch directories, against the REAL shipped manifest
 * library — which is the only way the precedence claims mean anything, since
 * the collision that matters in practice is a plugin bundling a name this
 * project already ships (`woocommerce`).
 *
 * WP_PLUGIN_DIR is a `define()`, so a single process can hold exactly one
 * plugin directory for its whole life. Every fixture therefore runs in a
 * clean PHP CHILD rendered from the template in child_source() below —
 * the idiom regress_site_adapter_certification.php:807-830 established for
 * exactly this reason, and the reason regress_plugin_dependency_order.php:84
 * can get away with a single define() (it needs one tree, this needs a dozen).
 * The parent process deliberately never defines it: that is what makes the
 * "no WP_PLUGIN_DIR in this process" behavior — an unscanned source, and a
 * frozen policy that reconstructs a bundled adapter without reopening the
 * plugin directory — testable here at all.
 *
 * The two claims this suite exists to protect, both of them amendments the
 * design ratified against the obvious implementation:
 *
 *   1. PRECEDENCE, not refusal, for a plugin-side name collision. A bundled
 *      adapter whose name a shipped or site definition already answers to is
 *      dropped and REPORTED, never refused. Under whole-scan refusal, the day
 *      a popular plugin ships a colliding `duo-adapter.json` every duo site
 *      running it loses every command through an automatic plugin update its
 *      operator never performed.
 *   2. PER-ADAPTER refusal scope. Every condition in this source records a row
 *      in BOTH scan modes and lets the walk continue, so a third party's typo
 *      cannot take down an installation the operator did not author. The
 *      refusal becomes fatal exactly once: when a pin names that adapter.
 *
 * What this does NOT cover, deliberately: whether the bundling plugin's own
 * CODE does anything (that is provider negotiation — regress_provider_contract*),
 * and whether a bundled adapter's capability claim holds against a live target
 * (it cannot be certified at all, which is asserted here as a property of the
 * source rather than measured against a target).
 *
 * Exit 0 and "ALL PASSED" on success; any failed check prints "FAIL: ..." and
 * the script exits 1.
 */

$engineRoot = dirname(__DIR__, 4);

if (preg_match("/define\('DUO_SPEC_VERSION', ([0-9]+)\)/", (string) file_get_contents($engineRoot . '/agent/duo.php'), $m) !== 1) {
    fwrite(STDERR, "could not resolve DUO_SPEC_VERSION from agent/duo.php\n");
    exit(1);
}
define('DUO_SPEC_VERSION', (int) $m[1]);
if (preg_match("/define\('DUO_AGENT_VERSION', '([^']+)'\)/", (string) file_get_contents($engineRoot . '/agent/duo.php'), $m) !== 1) {
    fwrite(STDERR, "could not resolve DUO_AGENT_VERSION from agent/duo.php\n");
    exit(1);
}
define('DUO_AGENT_VERSION', $m[1]);

// The parent reaches Policy::load() for the frozen-reconstruction group, which
// runs the real single-site gate; same switchable stub the sibling suites use.
$GLOBALS['duo_test_is_multisite'] = false;
function is_multisite(): bool {
    return (bool) $GLOBALS['duo_test_is_multisite'];
}

require $engineRoot . '/agent/src/Kernel/Canon.php';
require $engineRoot . '/agent/src/Kernel/OptionState.php';
require $engineRoot . '/agent/src/Policy/ManifestDispositions.php';
require $engineRoot . '/agent/src/Policy/Policy.php';
require $engineRoot . '/agent/src/Repository/Ledger.php';
require $engineRoot . '/agent/src/Repository/RepositoryCompiler.php';

use Duo\AdapterSources;
use Duo\Canon;
use Duo\Policy;

$failures = 0;
function check(bool $cond, string $msg): void {
    global $failures;
    if ($cond) {
        echo "ok: $msg\n";
    } else {
        echo "FAIL: $msg\n";
        $failures++;
    }
}

function rm_rf(string $path): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                rm_rf("$path/$entry");
            }
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
}

function scratch(string $label): string {
    $root = sys_get_temp_dir() . "/duo_regress_plugin_adapter_{$label}_" . bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    register_shutdown_function(fn() => rm_rf($root));
    return $root;
}

function write_file(string $path, string $contents): void {
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $contents);
}

/** A minimal, valid, purely declarative adapter manifest. */
function adapter(string $name, array $extra = []): array {
    return $extra + [
        'name' => $name,
        'spec_version' => DUO_SPEC_VERSION,
        'option_autoload' => 'preserve',
        'options' => ['acme_widget_layout' => ['class' => 'authored']],
    ];
}

/**
 * A bundled adapter, which is an adapter that ALSO declares its owning plugin
 * — and therefore its version range.
 *
 * That second half is not a fixture convenience: DUO-3339's anchor rule makes
 * `plugin` mandatory for this source, and AdapterContractGrammar::validate_adapter_contract()
 * (DUO-3222, Policy.php:5428-5434) has always refused a manifest that names a
 * plugin without an exact `version_range` — "no latest, wildcard, or unbounded
 * version support may be certified". So a bundled adapter is transitively
 * version-bounded, which is also what keeps `plugin_version_mismatch` a
 * reachable readiness blocker for this source rather than a dead code path.
 * A bundle omitting the range installs and then reports a grammar error, which
 * the anchor group below asserts rather than assumes.
 */
function bundle(string $name, string $plugin, array $extra = []): array {
    return adapter($name, $extra + [
        'plugin' => $plugin,
        'version_range' => ['max' => '9.0.0', 'min' => '1.0.0'],
    ]);
}

/**
 * A plugins directory: `<dir> => [manifest|raw string|null, extra files]`.
 * A null manifest writes the plugin's own PHP file and no bundle.
 */
function plugins_dir(string $label, array $plugins): string {
    $root = scratch($label);
    $dir = $root . '/plugins';
    mkdir($dir, 0777, true);
    foreach ($plugins as $sub => $spec) {
        write_file("$dir/$sub/$sub.php", "<?php\n// fixture plugin $sub\n");
        $bundle = $spec['bundle'] ?? null;
        if ($bundle !== null) {
            write_file(
                "$dir/$sub/duo-adapter.json",
                is_string($bundle) ? $bundle : Canon::encode($bundle)
            );
        }
        foreach ((array) ($spec['files'] ?? []) as $relative => $contents) {
            write_file("$dir/$sub/$relative", $contents);
        }
    }
    foreach ((array) ($plugins['__root_files__'] ?? []) as $relative => $contents) {
        write_file("$dir/$relative", $contents);
    }
    return $dir;
}

/** A site repository with the given pins and (optionally) site adapters. */
function site_repo(array $pins, array $adapters = []): string {
    $root = scratch('repo');
    write_file($root . '/site.duo.json', Canon::encode([
        'manifests' => $pins,
        'policy' => new \stdClass(),
        'spec_version' => DUO_SPEC_VERSION,
    ]));
    foreach ($adapters as $name => $content) {
        write_file(
            "$root/adapters/$name.json",
            is_string($content) ? $content : Canon::encode($content)
        );
    }
    return $root;
}

/**
 * The one child template, rendered per fixture.
 *
 * It defines WP_PLUGIN_DIR and `get_option()` CONDITIONALLY, because the two
 * guards `AdapterSources::plugin_source()` keys on are themselves under test:
 * a process with neither is a process where the plugin source is reported as
 * unscanned rather than as empty, and the difference between those two answers
 * is the whole reason `sources` exists.
 */
function child_source(): string {
    return <<<'PHP'
<?php
declare(strict_types=1);
define('DUO_SPEC_VERSION', __SPEC__);
define('DUO_AGENT_VERSION', __AGENT__);
function is_multisite(): bool { return false; }
// Query Monitor, Sentry, and Whoops all install one of these on ordinary
// WordPress sites, which turns any PHP warning raised inside the scan into a
// thrown ErrorException — a channel that bypasses the per-adapter refusal
// scope entirely and takes discover() AND survey() down together.
if (__STRICT_ERRORS__) {
    set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
}
if (__MANIFESTS__ !== null) {
    putenv('DUO_MANIFESTS_DIR=' . __MANIFESTS__);
}
$pluginDir = __WP_PLUGIN_DIR__;
if ($pluginDir !== null) {
    define('WP_PLUGIN_DIR', $pluginDir);
}
if (__WITH_GET_OPTION__) {
    function get_option(string $name, mixed $default = false): mixed {
        return $name === 'active_plugins' ? __ACTIVE__ : $default;
    }
}
final class WP_CLI {
    public static array $lines = [];
    public static function add_command($name, $class): void {}
    public static function line($line): void { self::$lines[] = (string) $line; }
    public static function warning($line): void { self::$lines[] = 'WARNING: ' . (string) $line; }
    public static function success($line): void { self::$lines[] = 'SUCCESS: ' . (string) $line; }
    public static function error($message): void { throw new \RuntimeException((string) $message); }
    public static function halt($code): void { throw new \RuntimeException('halt:' . $code); }
}
require __ENGINE_ROOT__ . '/agent/src/Kernel/Canon.php';
require __ENGINE_ROOT__ . '/agent/src/Kernel/OptionState.php';
require __ENGINE_ROOT__ . '/agent/src/Policy/ManifestDispositions.php';
require __ENGINE_ROOT__ . '/agent/src/Policy/Policy.php';
require __ENGINE_ROOT__ . '/agent/src/Repository/Ledger.php';
require __ENGINE_ROOT__ . '/agent/src/Repository/RepositoryCompiler.php';
require __ENGINE_ROOT__ . '/agent/src/Command/Cli.php';
require __ENGINE_ROOT__ . '/cli/src/Plan/PlanSummary.php';

$repo = __REPO__;
$name = __NAME__;
$payload = [];

// ONE scan per mode, and both views of it: the architectural claim under test
// is that discover() and survey() are the same walk, so they are taken
// together and compared by the parent rather than trusted apart.
try {
    $discover = \Duo\AdapterSources::discover(\Duo\Policy::manifests_dir(), $repo);
    $payload['discover'] = [
        'names' => array_values(array_map('strval', array_keys((function ($d) {
            $out = [];
            foreach (['acme-widget', 'woocommerce', 'core', 'keeper', 'other-widget'] as $probe) {
                if ($d->path($probe) !== null) {
                    $out[$probe] = true;
                }
            }
            return $out;
        })($discover)))),
        'not_installed' => $discover->not_installed(),
        'plugin_refusals' => $discover->plugin_refusals(),
        'sources' => $discover->sources(),
    ];
    if ($name !== null) {
        $payload['discover']['source'] = $discover->path($name) === null ? null : $discover->source($name);
        $payload['discover']['path'] = $discover->path($name);
        $payload['discover']['provenance'] = $discover->provenance($name);
        try {
            $payload['discover']['file'] = $discover->file($name, \Duo\Policy::manifests_dir());
        } catch (\Throwable $t) {
            $payload['discover']['file_error'] = $t->getMessage();
        }
    }
} catch (\Throwable $t) {
    $payload['discover'] = ['error' => $t->getMessage()];
}

try {
    $payload['survey'] = \Duo\AdapterSources::survey($repo);
} catch (\Throwable $t) {
    $payload['survey'] = ['error' => $t->getMessage()];
}

if (__MODE__ === 'pin' || __MODE__ === 'all') {
    try {
        $policy = \Duo\Policy::load($repo, [$name]);
        $payload['pin'] = [
            'digest' => \Duo\RepositoryCompiler::resolved_adapters($policy)[0]['digest'] ?? null,
            'source' => \Duo\RepositoryCompiler::resolved_adapters($policy)[0]['source'] ?? null,
            'trust_tier' => \Duo\RepositoryCompiler::resolved_adapters($policy)[0]['trust_tier'] ?? null,
        ];
        WP_CLI::$lines = [];
        (new \Duo\Cli())->manifest_pin([], array_filter(['name' => $name, 'repo' => $repo]));
        $payload['pin']['emitted'] = json_decode(implode("\n", WP_CLI::$lines), true);
    } catch (\Throwable $t) {
        $payload['pin'] = ['error' => $t->getMessage()];
    }
}

if (__MODE__ === 'load' || __MODE__ === 'all') {
    try {
        $policy = \Duo\Policy::load($repo);
        $payload['load'] = [
            'names' => array_map(static fn(array $m): string => (string) $m['name'], $policy->manifests),
            'diagnostics' => $policy->adapter_sources()->diagnostics($policy->manifests),
            // The readiness/plan half, from the SAME report host promotion and
            // the plan renderers consume — never a second evaluation.
            'blockers' => $policy->adapter_readiness_blockers(),
            'report' => $policy->capability_report(['operation' => 'promote']),
            'plan_lines' => \Duo\Orchestrator\PlanSummary::render([
                'adapter_dispositions' => $policy->adapter_readiness_blockers(),
            ])['lines'],
        ];
    } catch (\Throwable $t) {
        $payload['load'] = ['error' => $t->getMessage()];
    }
}

if (__MODE__ === 'snapshot') {
    try {
        $policy = \Duo\Policy::load($repo);
        $payload['snapshot'] = $policy->export_snapshot();
        $payload['resolved'] = \Duo\RepositoryCompiler::resolved_adapters($policy);
    } catch (\Throwable $t) {
        $payload['snapshot'] = ['error' => $t->getMessage()];
    }
}

if (__MODE__ === 'survey_cli') {
    try {
        WP_CLI::$lines = [];
        (new \Duo\Cli())->adapter_survey([], array_filter(['format' => 'json', 'repo' => $repo]));
        $payload['halted'] = false;
    } catch (\Throwable $t) {
        $payload['halted'] = $t->getMessage();
    }
    $payload['document'] = json_decode(implode("\n", WP_CLI::$lines), true);
    WP_CLI::$lines = [];
    try {
        (new \Duo\Cli())->adapter_survey([], array_filter(['repo' => $repo]));
    } catch (\Throwable $t) {
        // halt() is the exit-code signal; the rendered lines are the subject.
    }
    $payload['text'] = implode("\n", WP_CLI::$lines);
}

echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
PHP;
}

/**
 * Run one fixture in a clean child and return its decoded payload.
 *
 * @param array{plugins:?string, active?:list<string>, get_option?:bool, repo?:?string, name?:?string, mode?:string} $spec
 */
function child(array $spec): array {
    global $engineRoot;
    static $seq = 0;
    $seq++;
    $script = str_replace(
        [
            '__SPEC__', '__AGENT__', '__WP_PLUGIN_DIR__', '__WITH_GET_OPTION__', '__ACTIVE__',
            '__ENGINE_ROOT__', '__REPO__', '__NAME__', '__MODE__', '__STRICT_ERRORS__', '__MANIFESTS__',
        ],
        [
            (string) DUO_SPEC_VERSION,
            var_export(DUO_AGENT_VERSION, true),
            var_export($spec['plugins'] ?? null, true),
            ($spec['get_option'] ?? true) ? 'true' : 'false',
            var_export($spec['active'] ?? [], true),
            var_export($engineRoot, true),
            var_export($spec['repo'] ?? null, true),
            var_export($spec['name'] ?? null, true),
            var_export($spec['mode'] ?? 'all', true),
            ($spec['strict_errors'] ?? false) ? 'true' : 'false',
            var_export($spec['manifests'] ?? null, true),
        ],
        child_source()
    );
    $dir = sys_get_temp_dir() . '/duo_regress_plugin_adapter_children';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
        register_shutdown_function(fn() => rm_rf($dir));
    }
    $file = "$dir/child_$seq.php";
    file_put_contents($file, $script);
    $pipes = [];
    $process = proc_open([PHP_BINARY, $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('cannot start fixture child');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $decoded = json_decode((string) $stdout, true);
    if (!is_array($decoded)) {
        // Normally a harness bug, and loud. `tolerate_junk` is for the one
        // group whose SUBJECT is output purity: there, a corrupted document is
        // the finding, and throwing would report it as this file being broken
        // rather than as the scan being unsafe to run.
        if (empty($spec['tolerate_junk'])) {
            throw new RuntimeException(
                "fixture child produced no JSON (exit $exit)\nstdout: $stdout\nstderr: $stderr"
            );
        }
        return ['__unparseable' => (string) $stdout, '__stderr' => (string) $stderr, '__exit' => $exit];
    }
    // A PHP warning printed by the scan corrupts every --format=json document
    // downstream of it, so the child's own diagnostic stream is part of the
    // result rather than noise beside it.
    $decoded['__stderr'] = (string) $stderr;
    $decoded['__exit'] = $exit;
    return $decoded;
}

/** @return list<array<string,mixed>> */
function rows_with(array $rows, string $key, string $value): array {
    return array_values(array_filter(
        $rows,
        static fn(array $r): bool => (string) ($r[$key] ?? '') === $value
    ));
}

$manifestDir = Policy::manifests_dir();

// ======================================================================
echo "\n== a plugin bundles an adapter, and it is installed (case a) ==\n";
// ======================================================================
$happyPlugins = plugins_dir('happy', [
    'acme' => ['bundle' => bundle('acme-widget', 'acme/acme.php')],
]);
$happyRepo = site_repo([['name' => 'acme-widget', 'source' => 'plugin']]);
$happy = child([
    'plugins' => $happyPlugins,
    'active' => ['acme/acme.php'],
    'repo' => $happyRepo,
    'name' => 'acme-widget',
]);
check(
    ($happy['discover']['source'] ?? null) === 'plugin'
    && ($happy['discover']['path'] ?? null) === 'plugins/acme/duo-adapter.json'
    && ($happy['discover']['file'] ?? null) === $happyPlugins . '/acme/duo-adapter.json',
    'an active plugin\'s duo-adapter.json is discovered, sourced `plugin`, with a `plugins/`-prefixed provenance '
    . 'path and the real file behind it'
);
$happyRow = rows_with($happy['survey']['adapters'] ?? [], 'name', 'acme-widget')[0] ?? [];
check(
    ($happyRow['source'] ?? null) === 'plugin'
    && ($happyRow['trust_tier'] ?? null) === 'declarative_manifest'
    && ($happyRow['tier_basis'] ?? null) === 'no interpreter, regenerator, provider, or native action is declared'
    && ($happyRow['grammar']['status'] ?? null) === 'ok'
    && ($happyRow['certification'] ?? null) === 'uncertified'
    && ($happyRow['certification_evidence'] ?? null) === null,
    'the survey row carries its derived tier WITH the declaration behind it, an `ok` grammar verdict from the real '
    . 'loader, and `uncertified` — the only certification word this source can ever hold'
);
$happyProvenance = $happy['discover']['provenance'] ?? [];
check(
    ($happyProvenance['status'] ?? null) === 'uncertified'
    && ($happyProvenance['provenance']['source'] ?? null) === 'plugin'
    && ($happyProvenance['provenance']['path'] ?? null) === 'plugins/acme/duo-adapter.json'
    && ($happyProvenance['provenance']['format'] ?? null) === AdapterSources::FORMAT,
    'and a synthesized provenance record whose source and repo-independent path are the bundled ones'
);
// The reason string enters the adapter digest through the disposition slot, so
// it is IDENTITY-BEARING: this assertion is what stops it being reworded
// casually, because rewording it invalidates every pin naming a bundled
// adapter. It has to say which plugin, and that certifying in place is not a
// thing that can be done.
check(
    ($happyProvenance['reason'] ?? null) === "adapter 'acme-widget' is bundled by the active plugin 'acme/acme.php' "
    . '(plugins/acme/duo-adapter.json) and carries no reviewed certification evidence; a bundled adapter cannot be '
    . 'certified in place — certification is a repository-scoped signed companion at '
    . 'adapters/certifications/acme-widget.json.',
    'the digest-bearing provenance reason names the owning plugin, the bundled path, and the impossibility of '
    . 'certifying in place, byte for byte (found: ' . (string) ($happyProvenance['reason'] ?? '(absent)') . ')'
);
check(
    ($happy['pin']['source'] ?? null) === 'plugin'
    && is_string($happy['pin']['digest'] ?? null)
    && ($happy['pin']['emitted']['source'] ?? null) === 'plugin'
    && ($happy['pin']['emitted']['digest'] ?? null) === ($happy['pin']['digest'] ?? null)
    && ($happy['pin']['emitted']['name'] ?? null) === 'acme-widget',
    '`wp duo manifest-pin` emits the bundled adapter\'s own {name,source:"plugin",digest} — pasting it IS the '
    . 'deliberate act, so no new flag guards it'
);
// DUO-3371 (#185) added a shipped/site rule — a manifest's declared `name`
// must equal its FILE name — and applies it in Policy::load() to whatever
// source resolved the pin, this one included. For the plugin source it is a
// TAUTOLOGY: identity inverts here, every bundle is called duo-adapter.json,
// so the scan keys the origin off the declared name and the two cannot
// disagree. Pinned because that is a property of how the scan keys an origin,
// not a law of nature: if anything ever keys a plugin origin off something
// else, this assertion starts refusing every bundled adapter at load, and this
// check is what says so first.
check(
    ($happy['load']['names'] ?? null) === ['acme-widget']
    && ($happy['discover']['provenance']['provenance']['path'] ?? null) === 'plugins/acme/duo-adapter.json',
    'a bundled adapter passes DUO-3371\'s declared-name==file-name assertion at load: the plugin scan keys its '
    . 'origin off the declared name, so the rule is satisfied by construction rather than by luck'
);
check(
    ($happy['load']['names'] ?? null) === ['acme-widget']
    && ($happy['load']['diagnostics']['acme-widget']['source'] ?? null) === 'plugin'
    && ($happy['load']['diagnostics']['acme-widget']['certification'] ?? null) === 'uncertified',
    'and a repository pinning source:"plugin" loads it — the pin vocabulary accepts the third source (case n)'
);
check(
    str_contains(
        (string) ($happy['load']['diagnostics']['acme-widget']['remediation'] ?? ''),
        'install this adapter as a repository package at adapters/acme-widget.json'
    )
    && str_contains(
        (string) ($happy['load']['diagnostics']['acme-widget']['remediation'] ?? ''),
        'The site copy wins by precedence'
    )
    && str_contains(
        (string) ($happy['load']['diagnostics']['acme-widget']['remediation'] ?? ''),
        'the plugin stays active throughout'
    ),
    'its remediation is the PROMOTION PATH rather than "get it signed", which is impossible where it lives'
);
// The readiness posture, end to end, through the machinery that already
// existed: AdapterRegistry::report() keys on `source !== shipped` and
// interpolates whatever source/path/remediation the diagnostics row carries,
// so the third source needed NO change there — asserted rather than assumed,
// because "needs no change" is the kind of claim that is true right up until
// someone adds a two-source branch.
$happyBlocker = ($happy['load']['blockers'] ?? [])[0] ?? [];
check(
    ($happy['load']['report']['ready'] ?? null) === false
    && count($happy['load']['blockers'] ?? []) === 1
    && ($happyBlocker['code'] ?? null) === 'adapter_source_uncertified'
    && ($happyBlocker['source'] ?? null) === 'plugin'
    && ($happyBlocker['trust_tier'] ?? null) === 'declarative_manifest'
    && str_contains((string) ($happyBlocker['reason'] ?? ''), 'plugin adapter source')
    && str_contains((string) ($happyBlocker['reason'] ?? ''), 'plugins/acme/duo-adapter.json'),
    'a bundled adapter carries exactly the posture an unsigned site adapter does — one '
    . 'adapter_source_uncertified blocker naming ITS source and ITS path, from the same report host promotion '
    . 'consumes (code: ' . (string) ($happyBlocker['code'] ?? '(none)') . ')'
);
$happyPlan = implode("\n", (array) ($happy['load']['plan_lines'] ?? []));
check(
    str_contains($happyPlan, 'source=plugin tier=declarative_manifest certification=uncertified')
    && str_contains($happyPlan, 'adapter_source_uncertified')
    && str_contains($happyPlan, 'remediation:'),
    'and the plan/status renderer prints that row in lockstep, with the third source word and the promotion '
    . 'remediation, without a renderer change'
);

$pluginSourceRow = rows_with($happy['discover']['sources'] ?? [], 'source', 'plugin')[0] ?? [];
check(
    ($pluginSourceRow['scanned'] ?? null) === true
    && ($pluginSourceRow['path'] ?? null) === $happyPlugins,
    'the source inventory reports the plugin source as scanned, naming the directory it scanned'
);

// ======================================================================
echo "\n== an INACTIVE plugin's bundle is reported, never installed (case b) ==\n";
// ======================================================================
$inactive = child([
    'plugins' => $happyPlugins,
    'active' => [],
    'repo' => null,
    'name' => 'acme-widget',
]);
check(
    ($inactive['discover']['source'] ?? null) === null
    && ($inactive['discover']['not_installed'] ?? null) === []
    && rows_with($inactive['survey']['adapters'] ?? [], 'name', 'acme-widget') === [],
    'with the plugin inactive the adapter is not installed, and discover() does not even list it — activation is '
    . 'the operator consent that installs a bundled adapter'
);
$inactiveRow = rows_with($inactive['survey']['not_installed'] ?? [], 'reason_code', 'plugin_not_active')[0] ?? [];
check(
    ($inactiveRow['name'] ?? null) === 'acme-widget'
    && ($inactiveRow['path'] ?? null) === 'plugins/acme/duo-adapter.json'
    && ($inactiveRow['source'] ?? null) === 'plugin'
    && ($inactiveRow['winner'] ?? null) === null
    && str_contains((string) ($inactiveRow['message'] ?? ''), 'is not active'),
    'but survey() SHOWS it, with plugin_not_active and no winner — the third documented divergence between the '
    . 'two scan modes, and the only one that adds I/O rather than changing a finding'
);
check(
    ($inactive['discover']['file_error'] ?? null) !== null
    && str_contains((string) $inactive['discover']['file_error'], 'not found in')
    && str_contains((string) $inactive['discover']['file_error'], '(plugin)'),
    'and a pin naming it fails as not-found, enumerating the sources that WERE searched'
);

// ======================================================================
echo "\n== two active plugins, one name: both lose (case c) ==\n";
// ======================================================================
$collisionPlugins = plugins_dir('collision', [
    'acme' => ['bundle' => bundle('acme-widget', 'acme/acme.php')],
    'beta' => ['bundle' => bundle('acme-widget', 'beta/beta.php')],
    'gamma' => ['bundle' => bundle('other-widget', 'gamma/gamma.php')],
]);
$collisionRepo = site_repo([['name' => 'acme-widget', 'source' => 'plugin']]);
$collision = child([
    'plugins' => $collisionPlugins,
    'active' => ['acme/acme.php', 'beta/beta.php', 'gamma/gamma.php'],
    'repo' => $collisionRepo,
    'name' => 'acme-widget',
]);
$collisionRows = rows_with($collision['discover']['plugin_refusals'] ?? [], 'code', 'source_collision');
check(
    count($collisionRows) === 1
    && ($collisionRows[0]['paths'] ?? null) === ['plugins/acme/duo-adapter.json', 'plugins/beta/duo-adapter.json']
    && ($collisionRows[0]['source'] ?? null) === 'plugin'
    && ($collisionRows[0]['scope'] ?? null) === 'adapter',
    'one row for the pair, naming both files — precedence ranks SOURCES, and these are the same source, so there '
    . 'is no rule that could pick a winner'
);
check(
    ($collision['discover']['source'] ?? null) === null
    && rows_with($collision['survey']['adapters'] ?? [], 'name', 'acme-widget') === [],
    'neither claimant is installed'
);
check(
    rows_with($collision['survey']['adapters'] ?? [], 'name', 'other-widget') !== [],
    'and the third plugin\'s unrelated adapter is completely unaffected — a per-adapter refusal, not a whole-scan '
    . 'one (this is Amendment B)'
);
check(
    str_contains((string) ($collision['discover']['file_error'] ?? ''), 'each bundle an adapter named')
    && str_contains((string) ($collision['discover']['file_error'] ?? ''), 'This adapter is pinned')
    && str_contains((string) ($collision['discover']['file_error'] ?? ''), 'deactivate one of the colliding plugins'),
    'a PIN naming the colliding adapter is where the refusal turns fatal, and it fails with that row\'s own '
    . 'message and remediation rather than a generic not-found'
);
check(
    str_contains((string) ($collision['load']['error'] ?? ''), 'each bundle an adapter named'),
    'so the ordinary loading path refuses that repository with the same words'
);

// ======================================================================
echo "\n== a plugin bundling a SHIPPED name is shadowed, not refused (case d) ==\n";
// ======================================================================
$woo = plugins_dir('woo', [
    'woocommerce' => ['bundle' => bundle('woocommerce', 'woocommerce/woocommerce.php')],
    'acme' => ['bundle' => bundle('acme-widget', 'acme/acme.php')],
]);
$wooRepo = site_repo(['woocommerce', ['name' => 'acme-widget', 'source' => 'plugin']]);
$wooResult = child([
    'plugins' => $woo,
    'active' => ['woocommerce/woocommerce.php', 'acme/acme.php'],
    'repo' => $wooRepo,
    'name' => 'woocommerce',
]);
$wooShadow = rows_with($wooResult['discover']['not_installed'] ?? [], 'reason_code', 'shadowed')[0] ?? [];
check(
    ($wooResult['discover']['plugin_refusals'] ?? null) === []
    && ($wooShadow['name'] ?? null) === 'woocommerce'
    && ($wooShadow['path'] ?? null) === 'plugins/woocommerce/duo-adapter.json'
    && ($wooShadow['winner']['source'] ?? null) === 'shipped'
    && ($wooShadow['winner']['path'] ?? null) === $manifestDir . '/woocommerce.json',
    'THE case Amendment A exists for: a plugin that starts bundling a name this project ships produces NO refusal '
    . '— one not_installed row naming the shipped winner'
);
check(
    ($wooResult['discover']['source'] ?? null) === 'shipped'
    && ($wooResult['load']['names'] ?? null) === ['woocommerce', 'acme-widget']
    && ($wooResult['load']['diagnostics']['woocommerce']['source'] ?? null) === 'shipped',
    'the shipped definition still answers to the name and the whole repository still loads — no site running that '
    . 'plugin loses a single command to an update its operator never performed'
);
check(
    str_contains((string) ($wooShadow['message'] ?? ''), 'it stays active and its own code keeps running'),
    'and the row says out loud that nothing about the plugin changes'
);
$wooPinnedPlugin = site_repo([['name' => 'woocommerce', 'source' => 'plugin']]);
$wooPinned = child([
    'plugins' => $woo,
    'active' => ['woocommerce/woocommerce.php'],
    'repo' => $wooPinnedPlugin,
    'mode' => 'load',
]);
check(
    str_contains((string) ($wooPinned['load']['error'] ?? ''), 'pinned to the plugin adapter source but resolves')
    && str_contains((string) ($wooPinned['load']['error'] ?? ''), 'from the shipped source'),
    'while a pin that WROTE DOWN source:"plugin" for that name refuses loudly — shadowing is reported, but a '
    . 'repository asserting the bundled definition is never quietly served the shipped one'
);

// ======================================================================
echo "\n== a plugin bundling a SITE adapter's name loses to it (case e, first half) ==\n";
// ======================================================================
$siteWinsRepo = site_repo(
    [['name' => 'acme-widget', 'source' => 'site']],
    ['acme-widget' => bundle('acme-widget', 'acme/acme.php')]
);
$siteWins = child([
    'plugins' => $happyPlugins,
    'active' => ['acme/acme.php'],
    'repo' => $siteWinsRepo,
    'name' => 'acme-widget',
]);
$siteShadow = rows_with($siteWins['discover']['not_installed'] ?? [], 'reason_code', 'shadowed')[0] ?? [];
check(
    ($siteWins['discover']['source'] ?? null) === 'site'
    && ($siteWins['discover']['path'] ?? null) === 'adapters/acme-widget.json'
    && ($siteShadow['winner']['source'] ?? null) === 'site'
    && ($siteShadow['winner']['path'] ?? null) === 'adapters/acme-widget.json'
    && ($siteWins['discover']['plugin_refusals'] ?? null) === [],
    'the operator-authored site copy outranks the bundled one, which reports shadowed — this is what makes the '
    . 'certification promotion path work without deactivating anybody'
);

// ======================================================================
echo "\n== the data-only privilege boundary, with the source noun changed (case f) ==\n";
// ======================================================================
$privilegeCases = [
    'interpreter' => ['interpreter' => 'acf'],
    'manifest provider' => ['providers' => [[
        'capabilities' => ['flush'], 'id' => 'acme-cache', 'plugin' => 'acme/acme.php',
        'source' => 'manifest', 'version' => '1.0.0',
    ]]],
    'reserved authority field' => ['trust_tier' => 'declarative_manifest'],
    'regenerator' => ['post_types' => ['page' => [
        'regen_dependency' => ['regenerator' => 'acme'],
    ]]],
];
foreach ($privilegeCases as $label => $extra) {
    $dir = plugins_dir('privilege', [
        'acme' => ['bundle' => bundle('acme-widget', 'acme/acme.php', $extra)],
        'gamma' => ['bundle' => bundle('other-widget', 'gamma/gamma.php')],
    ]);
    $result = child([
        'plugins' => $dir,
        'active' => ['acme/acme.php', 'gamma/gamma.php'],
        'repo' => null,
        'name' => 'acme-widget',
    ]);
    $rows = rows_with($result['discover']['plugin_refusals'] ?? [], 'code', 'out_of_tree_privilege');
    check(
        count($rows) === 1
        && ($rows[0]['paths'] ?? null) === ['plugins/acme/duo-adapter.json']
        && str_starts_with((string) $rows[0]['message'], "duo: plugin adapter 'plugins/acme/duo-adapter.json'")
        && ($result['discover']['source'] ?? null) === null,
        "a bundled manifest reaching for $label is refused by the SAME assert_out_of_tree_contract() the site "
        . 'source uses, with only the noun changed, and is not installed (message: '
        . substr((string) ($rows[0]['message'] ?? '(none)'), 0, 120) . '...)'
    );
    check(
        rows_with($result['survey']['adapters'] ?? [], 'name', 'other-widget') !== [],
        "and the unrelated plugin beside it keeps its adapter ($label)"
    );
}

// ======================================================================
echo "\n== the anchor rule (case g) ==\n";
// ======================================================================
$anchorCases = [
    'no plugin claim at all' => adapter('acme-widget'),
    'another plugin' => bundle('acme-widget', 'beta/beta.php'),
    'a single-file plugin' => bundle('acme-widget', 'acme.php'),
    'a traversing basename' => bundle('acme-widget', '../outside/x.php'),
];
$anchorMessages = [
    // The ABSENCE of a `plugin` claim gets its own sentence rather than
    // falling through to the basename grammar's "must be a non-empty plugin
    // basename". An author who simply did not know the claim was required
    // needs to be told which plugin to name and why the claim exists at all —
    // it is what anchors the manifest to the code it ships with, and it is
    // what a frozen policy rebuilds the provenance path from.
    'no plugin claim at all' => 'declares no `plugin`',
    'another plugin' => 'but it is bundled by',
    'a single-file plugin' => 'but it is bundled by',
    'a traversing basename' => 'never one containing a ".." segment',
];
foreach ($anchorCases as $label => $manifest) {
    $dir = plugins_dir('anchor', ['acme' => ['bundle' => $manifest]]);
    $result = child(['plugins' => $dir, 'active' => ['acme/acme.php'], 'name' => 'acme-widget']);
    $rows = $result['discover']['plugin_refusals'] ?? [];
    check(
        count($rows) === 1
        && ($rows[0]['code'] ?? null) === 'plugin_anchor_mismatch'
        && ($result['discover']['source'] ?? null) === null,
        "a bundled manifest declaring $label is refused as plugin_anchor_mismatch (rows: "
        . implode(', ', array_column($rows, 'code')) . ')'
    );
    check(
        str_contains((string) ($rows[0]['message'] ?? ''), $anchorMessages[$label])
        && str_contains((string) ($rows[0]['message'] ?? ''), 'acme'),
        "and the message says what is actually wrong ($label): it contains '{$anchorMessages[$label]}' and names "
        . 'the owning plugin (message: ' . substr((string) ($rows[0]['message'] ?? '(none)'), 0, 150) . '...)'
    );
    check(
        str_contains((string) ($result['discover']['file_error'] ?? ''), 'This adapter is pinned'),
        "and a pin naming it is fatal with that row's own message ($label)"
    );
}
$anchorOk = child([
    'plugins' => plugins_dir('anchor-ok', [
        'acme' => ['bundle' => bundle('acme-widget', 'acme/acme.php', [
            'providers' => [[
                'capabilities' => ['flush'], 'id' => 'acme-cache', 'plugin' => 'acme/acme.php',
                'source' => 'plugin', 'version' => '1.0.0',
            ]],
        ])],
    ]),
    'active' => ['acme/acme.php'],
    'name' => 'acme-widget',
]);
// The anchor rule makes `plugin` mandatory, and Policy has refused a manifest
// naming a plugin with no exact `version_range` since DUO-3222 ("no latest,
// wildcard, or unbounded version support may be certified"). So EVERY bundled
// adapter is transitively version-bounded — which is what keeps
// plugin_version_mismatch a reachable readiness blocker for this source rather
// than a dead code path, and is asserted here rather than assumed because the
// two rules were written years apart and only meet in this source.
$unbounded = child([
    'plugins' => plugins_dir('unbounded', [
        'acme' => ['bundle' => adapter('acme-widget', ['plugin' => 'acme/acme.php'])],
    ]),
    'active' => ['acme/acme.php'],
    'name' => 'acme-widget',
]);
$unboundedRow = rows_with($unbounded['survey']['adapters'] ?? [], 'name', 'acme-widget')[0] ?? [];
check(
    ($unbounded['discover']['plugin_refusals'] ?? null) === []
    && ($unbounded['discover']['source'] ?? null) === 'plugin'
    && ($unboundedRow['grammar']['status'] ?? null) === 'error'
    && str_contains((string) ($unboundedRow['grammar']['message'] ?? ''), 'unbounded support')
    && str_contains((string) ($unbounded['pin']['error'] ?? ''), 'unbounded support'),
    'a bundle declaring its owning plugin with no version_range is DISCOVERED and then refused by the ordinary '
    . 'adapter contract — the anchor rule makes every bundled adapter transitively version-bounded (DUO-3222), '
    . 'which is not a rule this source invented and not one it can waive'
);

$anchorOkRow = rows_with($anchorOk['survey']['adapters'] ?? [], 'name', 'acme-widget')[0] ?? [];
check(
    ($anchorOk['discover']['source'] ?? null) === 'plugin'
    && ($anchorOkRow['trust_tier'] ?? null) === 'plugin_provider'
    && ($anchorOkRow['required_providers'][0]['plugin'] ?? null) === 'acme/acme.php',
    'a bundled adapter declaring a provider owned by ITS OWN plugin is accepted at the plugin_provider tier — the '
    . 'trust anchor is the plugin the operator already installed (case l)'
);

// ======================================================================
echo "\n== identity comes from the DECLARED name, and only from it (case h) ==\n";
// ======================================================================
$identityCases = [
    'missing' => ['plugin' => 'acme/acme.php', 'spec_version' => DUO_SPEC_VERSION],
    'uppercase' => adapter('Acme-Widget', ['plugin' => 'acme/acme.php']),
    'numeric-only' => bundle('123', 'acme/acme.php'),
    'path-like' => adapter('../escape', ['plugin' => 'acme/acme.php']),
    'reserved' => bundle('dispositions', 'acme/acme.php'),
    'non-string' => ['name' => 42, 'plugin' => 'acme/acme.php', 'spec_version' => DUO_SPEC_VERSION],
];
foreach ($identityCases as $label => $manifest) {
    $dir = plugins_dir('identity', ['acme' => ['bundle' => $manifest]]);
    $result = child(['plugins' => $dir, 'active' => ['acme/acme.php']]);
    $rows = $result['discover']['plugin_refusals'] ?? [];
    check(
        count($rows) === 1
        && ($rows[0]['code'] ?? null) === 'invalid_adapter_name'
        && ($rows[0]['paths'] ?? null) === ['plugins/acme/duo-adapter.json']
        && ($result['survey']['adapters'] ?? []) !== []
        && rows_with($result['survey']['adapters'], 'source', 'plugin') === [],
        "a $label declared name is refused as invalid_adapter_name and installs nothing (rows: "
        . implode(', ', array_column($rows, 'code')) . ')'
    );
}

// ======================================================================
echo "\n== a bundle that is not a manifest (case i) ==\n";
// ======================================================================
$shapeCases = [
    'not JSON' => "this is not json\n",
    'a JSON array' => "[1, 2, 3]\n",
    'a scalar' => "42\n",
    'a string' => "\"hello\"\n",
];
foreach ($shapeCases as $label => $raw) {
    $dir = plugins_dir('shape', ['acme' => ['bundle' => $raw]]);
    $result = child(['plugins' => $dir, 'active' => ['acme/acme.php']]);
    $rows = $result['discover']['plugin_refusals'] ?? [];
    check(
        count($rows) === 1
        && ($rows[0]['code'] ?? null) === 'malformed_manifest'
        && str_starts_with((string) $rows[0]['message'], "duo: plugin adapter 'plugins/acme/duo-adapter.json'"),
        "a bundle that is $label is refused as malformed_manifest naming the file as a plugin adapter (rows: "
        . implode(', ', array_column($rows, 'code')) . ')'
    );
}
$shapeTypes = child([
    'plugins' => plugins_dir('shape-type', ['acme' => ['bundle' => "42\n"]]),
    'active' => ['acme/acme.php'],
]);
check(
    str_contains((string) ($shapeTypes['discover']['plugin_refusals'][0]['message'] ?? ''), 'its top level is int'),
    'and it names the top-level type it actually found, rather than "repair the JSON" for bytes that parse'
);

// ======================================================================
echo "\n== near-misses in this engine's own duo- namespace (case j) ==\n";
// ======================================================================
// `duo-adapters.JSON` rather than `duo-adapter.JSON` for the extension-case
// row, and for exactly the reason DUO-3381 records for the certificate
// fixture: on a case-insensitive host (macOS default) writing
// `duo-adapter.JSON` beside `duo-adapter.json` OVERWRITES the real bundle
// instead of creating a second entry, so the fixture would silently stop
// testing the refusal it names. The rule under test is about the EXTENSION's
// case, which a distinct basename exercises identically on every host.
$nearMisses = [
    'duo-adapters.JSON' => 'extension_case_mismatch',
    'duo-adapters.json' => 'reserved_name',
    'duo-adapter.certification.json' => 'certification_source',
    'duo-adapter.v2.json' => 'reserved_name',
];
foreach ($nearMisses as $entry => $expected) {
    $dir = plugins_dir('nearmiss', [
        'acme' => [
            'bundle' => bundle('acme-widget', 'acme/acme.php'),
            'files' => [$entry => "{}\n"],
        ],
    ]);
    // Fixture manufacture asserted before the refusal is (DUO-3381): a near
    // miss that the filesystem folded onto the real bundle would leave this
    // check passing for the wrong reason, or silently not running at all.
    if (!in_array($entry, scandir("$dir/acme") ?: [], true)
        || !in_array('duo-adapter.json', scandir("$dir/acme") ?: [], true)) {
        check(false, "fixture '$entry' does not exist as its own entry beside duo-adapter.json on this filesystem");
        continue;
    }
    $result = child(['plugins' => $dir, 'active' => ['acme/acme.php'], 'name' => 'acme-widget']);
    $rows = $result['discover']['plugin_refusals'] ?? [];
    check(
        count($rows) === 1 && ($rows[0]['code'] ?? null) === $expected
        && ($rows[0]['paths'] ?? null) === ["plugins/acme/$entry"]
        && ($result['discover']['source'] ?? null) === null,
        "a plugin shipping '$entry' beside its bundle is refused as $expected, and its adapter is NOT installed — "
        . 'bytes in this engine\'s reserved namespace are never silently ignored (rows: '
        . implode(', ', array_column($rows, 'code')) . ')'
    );
}
check(
    str_contains(
        (string) (child([
            'plugins' => plugins_dir('cert-namespace', [
                'acme' => [
                    'bundle' => bundle('acme-widget', 'acme/acme.php'),
                    'files' => ['duo-adapter.certification.json' => "{}\n"],
                ],
            ]),
            'active' => ['acme/acme.php'],
        ])['discover']['plugin_refusals'][0]['message'] ?? ''),
        'a plugin-bundled adapter cannot carry its own certification'
    ),
    'and the certificate-shaped companion is told the actual rule: certification is repository-scoped and signed'
);
$thirdParty = child([
    'plugins' => plugins_dir('thirdparty', [
        'acme' => [
            'bundle' => bundle('acme-widget', 'acme/acme.php'),
            'files' => [
                'README.txt' => "notes\n",
                'config.json' => "{}\n",
                'certifications/whatever.json' => "{}\n",
                'adapters/other.json' => "{}\n",
            ],
        ],
    ]),
    'active' => ['acme/acme.php'],
    'name' => 'acme-widget',
]);
check(
    ($thirdParty['discover']['plugin_refusals'] ?? null) === []
    && ($thirdParty['discover']['source'] ?? null) === 'plugin',
    'while ordinary third-party files a plugin root legitimately holds — README, config.json, even directories '
    . 'named certifications/ and adapters/ — draw nothing at all: the sweep only judges names this engine claimed'
);
$rootBundle = plugins_dir('rootfile', ['acme' => ['bundle' => bundle('acme-widget', 'acme/acme.php')]]);
write_file($rootBundle . '/duo-adapter.json', Canon::encode(adapter('rogue')));
$rootResult = child(['plugins' => $rootBundle, 'active' => ['acme/acme.php'], 'name' => 'acme-widget']);
$rootRows = rows_with($rootResult['discover']['plugin_refusals'] ?? [], 'code', 'reserved_name');
check(
    count($rootRows) === 1
    && ($rootRows[0]['paths'] ?? null) === ['plugins/duo-adapter.json']
    && str_contains((string) $rootRows[0]['message'], 'owned by no plugin')
    && ($rootResult['discover']['source'] ?? null) === 'plugin',
    'a duo-adapter.json at the PLUGINS ROOT belongs to no plugin — no owning basename, no version window, no '
    . 'activation that consented to it — so it is refused while the properly bundled adapter beside it installs'
);
$singleFile = child([
    'plugins' => plugins_dir('singlefile', ['acme' => ['bundle' => bundle('acme-widget', 'acme/acme.php')]]),
    'active' => ['hello.php', 'acme/acme.php'],
    'name' => 'acme-widget',
]);
check(
    ($singleFile['discover']['plugin_refusals'] ?? null) === []
    && ($singleFile['discover']['source'] ?? null) === 'plugin',
    'and an active SINGLE-FILE plugin simply has no directory to bundle in, which is silence rather than a '
    . 'refusal — the same "strongest true statement available" Providers::plugin_anchor_problem() makes'
);

// ======================================================================
echo "\n== symlinks: the bundle is real, the plugin directory need not be (case k) ==\n";
// ======================================================================
$linkRoot = scratch('symlink');
mkdir($linkRoot . '/plugins/acme', 0777, true);
write_file($linkRoot . '/plugins/acme/acme.php', "<?php\n");
write_file($linkRoot . '/elsewhere/duo-adapter.json', Canon::encode(
    bundle('acme-widget', 'acme/acme.php')
));
symlink($linkRoot . '/elsewhere/duo-adapter.json', $linkRoot . '/plugins/acme/duo-adapter.json');
$linkResult = child([
    'plugins' => $linkRoot . '/plugins',
    'active' => ['acme/acme.php'],
    'name' => 'acme-widget',
]);
$linkRows = $linkResult['discover']['plugin_refusals'] ?? [];
check(
    count($linkRows) === 1
    && ($linkRows[0]['code'] ?? null) === 'symlink_source'
    && ($linkResult['discover']['source'] ?? null) === null,
    'a symlinked duo-adapter.json is refused: what the engine loads has to be covered by the owning plugin\'s own '
    . 'version, update, and review story'
);
$linkedDirRoot = scratch('symlinkdir');
mkdir($linkedDirRoot . '/plugins', 0777, true);
mkdir($linkedDirRoot . '/checkout/acme', 0777, true);
write_file($linkedDirRoot . '/checkout/acme/acme.php', "<?php\n");
write_file($linkedDirRoot . '/checkout/acme/duo-adapter.json', Canon::encode(
    bundle('acme-widget', 'acme/acme.php')
));
symlink($linkedDirRoot . '/checkout/acme', $linkedDirRoot . '/plugins/acme');
$linkedDir = child([
    'plugins' => $linkedDirRoot . '/plugins',
    'active' => ['acme/acme.php'],
    'name' => 'acme-widget',
]);
check(
    ($linkedDir['discover']['plugin_refusals'] ?? null) === []
    && ($linkedDir['discover']['source'] ?? null) === 'plugin'
    && ($linkedDir['discover']['path'] ?? null) === 'plugins/acme/duo-adapter.json',
    'while a symlinked PLUGIN DIRECTORY is accepted — development checkouts symlink plugin directories as a '
    . 'matter of course, and both sides resolve exactly as Providers::plugin_anchor_problem() resolves them'
);

// ======================================================================
echo "\n== the source is UNSCANNED, not empty, where WP_PLUGIN_DIR is absent ==\n";
// ======================================================================
$noWordPress = child(['plugins' => null, 'repo' => null, 'name' => 'acme-widget']);
$noWpRow = rows_with($noWordPress['discover']['sources'] ?? [], 'source', 'plugin')[0] ?? [];
check(
    ($noWpRow['scanned'] ?? null) === false
    && ($noWpRow['path'] ?? null) === null
    && str_contains((string) $noWpRow['note'], 'no WP_PLUGIN_DIR in this process')
    && str_contains((string) $noWpRow['note'], 'wp duo adapter-survey'),
    'a process with no WP_PLUGIN_DIR reports the plugin source as NOT SCANNED and names the command that can '
    . 'scan it — an empty result would have been a claim about the world instead of about this process'
);
$noGetOption = child(['plugins' => $happyPlugins, 'get_option' => false, 'active' => ['acme/acme.php']]);
check(
    (rows_with($noGetOption['discover']['sources'] ?? [], 'source', 'plugin')[0]['scanned'] ?? null) === false,
    'and so does a process with WP_PLUGIN_DIR but no get_option() — the active-plugins gate is not optional, so a '
    . 'process that cannot read it has not scanned this source'
);
check(
    str_contains(
        (string) ($noWordPress['discover']['file_error'] ?? ''),
        'plugin source not scanned (no WP_PLUGIN_DIR in this process)'
    ),
    'and an unresolvable pin says so in the not-found message, rather than implying the adapter does not exist '
    . 'anywhere'
);

// ======================================================================
echo "\n== one scan, two modes: discover() and survey() agree (case p) ==\n";
// ======================================================================
$mixedPlugins = plugins_dir('mixed', [
    'acme' => ['bundle' => bundle('acme-widget', 'acme/acme.php')],
    'beta' => ['bundle' => bundle('acme-widget', 'beta/beta.php')],
    'broken' => ['bundle' => "nope\n"],
    'overreach' => ['bundle' => bundle('over-widget', 'overreach/overreach.php', ['interpreter' => 'acf'])],
    'wrongplugin' => ['bundle' => bundle('wrong-widget', 'somewhere/else.php')],
    'woocommerce' => ['bundle' => bundle('woocommerce', 'woocommerce/woocommerce.php')],
    'inactive' => ['bundle' => bundle('sleeping', 'inactive/inactive.php')],
    'good' => ['bundle' => bundle('other-widget', 'good/good.php')],
]);
$mixed = child([
    'plugins' => $mixedPlugins,
    'active' => [
        'acme/acme.php', 'beta/beta.php', 'broken/broken.php', 'overreach/overreach.php',
        'wrongplugin/wrongplugin.php', 'woocommerce/woocommerce.php', 'good/good.php',
    ],
    'repo' => null,
    'name' => 'other-widget',
]);
$surveyPluginRefusals = array_values(array_filter(
    $mixed['survey']['refusals'] ?? [],
    static fn(array $r): bool => ($r['source'] ?? null) === 'plugin'
));
check(
    ($mixed['discover']['plugin_refusals'] ?? null) === $surveyPluginRefusals
    && count($surveyPluginRefusals) === 4,
    'discover()->plugin_refusals() IS survey()["refusals"] filtered to the plugin source — row for row, byte for '
    . 'byte, from one walk (discover: ' . count($mixed['discover']['plugin_refusals'] ?? [])
    . ', survey: ' . count($surveyPluginRefusals) . ')'
);
check(
    array_column($surveyPluginRefusals, 'code')
        === ['malformed_manifest', 'out_of_tree_privilege', 'plugin_anchor_mismatch', 'source_collision'],
    'four different plugins are refused for four different reasons in one pass — no condition hides another '
    . '(codes: ' . implode(', ', array_column($surveyPluginRefusals, 'code')) . ')'
);
check(
    array_values(array_unique(array_column($surveyPluginRefusals, 'scope'))) === ['adapter'],
    'and every one of them is scoped to its own adapter, never to the source'
);
check(
    ($mixed['discover']['source'] ?? null) === 'plugin'
    && rows_with($mixed['survey']['adapters'] ?? [], 'name', 'other-widget') !== [],
    'with five plugins in four different broken states, the one healthy bundle still installs'
);
check(
    ($mixed['discover']['not_installed'] ?? null)
        === array_values(array_filter(
            $mixed['survey']['not_installed'] ?? [],
            static fn(array $r): bool => ($r['reason_code'] ?? null) === 'shadowed'
        )),
    'and the shadowed rows match between the modes too, with survey() adding only the inactive installation '
    . 'discover() has no business reading'
);
check(
    rows_with($mixed['survey']['not_installed'] ?? [], 'reason_code', 'plugin_not_active') !== []
    && rows_with($mixed['discover']['not_installed'] ?? [], 'reason_code', 'plugin_not_active') === [],
    'which is exactly the documented third divergence between the two modes'
);
$siteGrammar = null;
foreach ($mixed['survey']['adapters'] ?? [] as $row) {
    if ($row['source'] === 'shipped' && $row['name'] === 'core') {
        $siteGrammar = $row['grammar']['status'];
    }
}
check(
    $siteGrammar === 'ok',
    'and four plugin refusals do not un-judge one shipped adapter — grammar_verdict() filters on scope, so a '
    . 'third party\'s typo cannot mark this whole machine unjudged'
);
// A pin must get ITS OWN refusal, which is only observable when several
// refusals exist and the one being asked about is not the first. Reading the
// last recorded row by index was correct exactly while every fixture refused
// once; here `broken/` refuses before `wrongplugin/` does, so an index-based
// lookup hands a pin the wrong file's message.
$pinnedAmongMany = child([
    'plugins' => $mixedPlugins,
    'active' => [
        'acme/acme.php', 'beta/beta.php', 'broken/broken.php', 'overreach/overreach.php',
        'wrongplugin/wrongplugin.php', 'woocommerce/woocommerce.php', 'good/good.php',
    ],
    'repo' => null,
    'name' => 'wrong-widget',
]);
check(
    count($pinnedAmongMany['discover']['plugin_refusals'] ?? []) === 4
    && str_contains((string) ($pinnedAmongMany['discover']['file_error'] ?? ''), 'declares plugin')
    && str_contains((string) ($pinnedAmongMany['discover']['file_error'] ?? ''), 'plugins/wrongplugin/')
    && !str_contains((string) ($pinnedAmongMany['discover']['file_error'] ?? ''), 'plugins/broken/'),
    'with four refusals recorded, a pin naming the anchor-mismatched adapter gets the ANCHOR row\'s message and '
    . 'not some other plugin\'s (message: '
    . substr((string) ($pinnedAmongMany['discover']['file_error'] ?? '(none)'), 0, 130) . '...)'
);
// The sharper half of the same claim, and the one that would have regressed
// silently: a SITE adapter reports `blocked_by_source_refusal` when its own
// source is refused, and the plugin source's per-adapter refusals are not
// that. Without the scope filter, one broken bundle in one plugin nobody asked
// about would mark every operator-authored adapter unjudged and drop the site
// half of every shipped verdict — a report that is both false and useless.
$siteBesidePluginRefusals = child([
    'plugins' => $mixedPlugins,
    'active' => ['broken/broken.php', 'good/good.php'],
    'repo' => site_repo(
        [['name' => 'keeper', 'source' => 'site']],
        ['keeper' => adapter('keeper')]
    ),
    'name' => 'keeper',
]);
$keeperRow = rows_with($siteBesidePluginRefusals['survey']['adapters'] ?? [], 'name', 'keeper')[0] ?? [];
check(
    ($siteBesidePluginRefusals['discover']['plugin_refusals'] ?? null) !== []
    && ($keeperRow['source'] ?? null) === 'site'
    && ($keeperRow['grammar']['status'] ?? null) === 'ok',
    'a site adapter is judged NORMALLY while the plugin source carries refusals — the whole-directory blackout is '
    . 'a property of the source that was refused, not of the machine (verdict: '
    . (string) ($keeperRow['grammar']['status'] ?? '(no row)') . ')'
);
$shippedBesidePluginRefusals = rows_with($siteBesidePluginRefusals['survey']['adapters'] ?? [], 'name', 'core')[0]
    ?? [];
check(
    ($shippedBesidePluginRefusals['grammar']['status'] ?? null) === 'ok',
    'and so is every shipped adapter, against the real site policy rather than without it'
);

// ======================================================================
echo "\n== `wp duo adapter-survey` is the target-side surface (7.3) ==\n";
// ======================================================================
$cli = child([
    'plugins' => $mixedPlugins,
    'active' => [
        'acme/acme.php', 'beta/beta.php', 'broken/broken.php', 'overreach/overreach.php',
        'wrongplugin/wrongplugin.php', 'woocommerce/woocommerce.php', 'good/good.php',
    ],
    'repo' => null,
    'mode' => 'survey_cli',
]);
$document = $cli['document'] ?? [];
check(
    ($document['format'] ?? null) === 'duo-adapter-catalog/v2'
    && ($document['command'] ?? null) === 'survey'
    && is_array($document['sources'] ?? null) && count($document['sources']) === 3
    && is_array($document['not_installed'] ?? null)
    && is_array($document['refusals'] ?? null)
    && is_array($document['deferred'] ?? null) && $document['deferred'] !== []
    && ($document['status'] ?? null) === 'error',
    'the verb emits the same v2 document the host catalog does, with sources, not_installed, refusals, and an '
    . 'always-present deferred list'
);
check(
    ($cli['halted'] ?? null) === 'halt:1',
    'and exits 1 when it surfaced a finding, matching every other finding-reporting verb'
);
check(
    ($document['summary']['plugin'] ?? null) === 1
    && ($document['summary']['refusals'] ?? null) === 4
    && ($document['summary']['not_installed'] ?? null) === 2,
    'its summary counts the third source, its refusals, and its not-loaded rows separately (found: '
    . json_encode($document['summary'] ?? [], JSON_UNESCAPED_SLASHES) . ')'
);
// The deferred list is a promise about what was NOT done, so its relationship
// to the host catalog's list is a measured fact rather than a docblock claim.
// The survey runs ON the target, so it scans the plugin source instead of
// deferring it, and answers the host's two live-target rows with one command;
// every other row is the same row, INCLUDING the site-policy one — a survey
// run without --repo produced its grammar verdicts with no site policy at all,
// and that caveat is owed to the reader wherever the command runs.
$surveySurfaces = array_column($document['deferred'] ?? [], 'surface');
check(
    count($surveySurfaces) === 4
    && str_contains(implode("\n", $surveySurfaces), 'site.duo.json policy.tables / policy.options')
    && str_contains(implode("\n", $surveySurfaces), 'the pinned SET')
    && str_contains(implode("\n", $surveySurfaces), 'interpreter /')
    && str_contains(implode("\n", $surveySurfaces), 'provider negotiation and certification'),
    'the survey defers four things, and the site-policy caveat is one of them — it was missing, which made the '
    . 'list quietly claim a grammar verdict this run had not earned (surfaces: '
    . implode(' | ', $surveySurfaces) . ')'
);
$surveyDeferredText = implode("\n", array_column($document['deferred'] ?? [], 'why'));
check(
    str_contains($surveyDeferredText, 'no --repo')
    && !str_contains(implode("\n", $surveySurfaces), 'adapter source'),
    'and it does NOT defer the plugin source, because this process is the target that has one'
);
check(
    str_contains((string) ($cli['text'] ?? ''), 'installed, NOT loaded')
    && str_contains((string) ($cli['text'] ?? ''), 'never the exit code')
    && str_contains((string) ($cli['text'] ?? ''), 'plugin source, adapter scope')
    && str_contains((string) ($cli['text'] ?? ''), '[deferred]'),
    'and the human renderer prints the not-loaded block with its own justification, the refusal scope, and the '
    . 'deferred list'
);
$cliClean = child([
    'plugins' => $happyPlugins,
    'active' => ['acme/acme.php'],
    'repo' => null,
    'mode' => 'survey_cli',
]);
check(
    ($cliClean['halted'] ?? null) === false
    && ($cliClean['document']['status'] ?? null) === 'ok',
    'a clean target exits 0'
);
$cliShadow = child([
    'plugins' => $woo,
    'active' => ['woocommerce/woocommerce.php', 'acme/acme.php'],
    'repo' => null,
    'mode' => 'survey_cli',
]);
check(
    ($cliShadow['halted'] ?? null) === false
    && ($cliShadow['document']['summary']['not_installed'] ?? null) === 1,
    'and a SHADOWED adapter is reported on a run that still exits 0 — a permanently red survey on every site '
    . 'running a colliding plugin would destroy the exit code\'s meaning'
);

// ======================================================================
echo "\n== a plugin directory this scan cannot ENUMERATE ==\n";
// ======================================================================
// Mode 0711 — search without read — is an ordinary hardened-hosting
// permission, not a contrived one. Unguarded, scandir() there emits a PHP
// warning and returns false: the warning corrupts every --format=json document
// the scan feeds, and under a warnings-as-exceptions handler (Query Monitor,
// Sentry, Whoops — all common on WordPress) it becomes an ErrorException
// thrown out of BOTH discover() and survey(). That is the site-wide outage the
// per-adapter refusal scope exists to prevent, arriving through the one
// channel that bypasses it.
$lockedRoot = plugins_dir('locked', [
    'acme' => ['bundle' => bundle('acme-widget', 'acme/acme.php')],
    'good' => ['bundle' => bundle('other-widget', 'good/good.php')],
]);
// 0311, not 0711: the OWNER's read bit is what scandir() consults, and this
// process owns the fixture. `d-wx--x--x` is the same search-without-read
// condition a 0711 directory presents to a non-owning web user, reproduced
// where the harness can actually create it.
$chmodWorks = @chmod($lockedRoot . '/acme', 0311)
    && !is_readable($lockedRoot . '/acme')
    && is_file($lockedRoot . '/acme/duo-adapter.json');
if (!$chmodWorks) {
    // Skipping is stated, never silent: a check that quietly stops running is
    // the same failure as a check that never existed.
    check(true, '(SKIPPED: this harness cannot create a search-without-read directory on this filesystem/uid)');
    @chmod($lockedRoot . '/acme', 0755);
} else {
    foreach ([false, true] as $strict) {
        $label = $strict ? 'with a warnings-as-exceptions handler installed' : 'with ordinary PHP error handling';
        $locked = child([
            'plugins' => $lockedRoot,
            'active' => ['acme/acme.php', 'good/good.php'],
            'name' => 'acme-widget',
            'strict_errors' => $strict,
            'tolerate_junk' => true,
        ]);
        check(
            !array_key_exists('__unparseable', $locked),
            "the scan's own output is still a parseable document, $label — a PHP warning printed mid-scan is not "
            . 'cosmetic, it is a corrupted `--format=json` answer (junk: '
            . substr((string) ($locked['__unparseable'] ?? ''), 0, 160) . ')'
        );
        check(
            !isset($locked['discover']['error']) && !isset($locked['survey']['error']),
            "neither discover() nor survey() throws over an unreadable plugin directory, $label (discover: "
            . substr((string) ($locked['discover']['error'] ?? 'no error'), 0, 110) . ')'
        );
        check(
            ($locked['__stderr'] ?? '') === '',
            "and nothing is written to the diagnostic stream, $label — a PHP warning here would corrupt every "
            . '--format=json document the scan feeds (stderr: '
            . substr((string) ($locked['__stderr'] ?? ''), 0, 140) . ')'
        );
        $lockedRows = rows_with($locked['discover']['plugin_refusals'] ?? [], 'code', 'source_unreadable');
        check(
            count($lockedRows) === 1
            && ($lockedRows[0]['paths'] ?? null) === ['plugins/acme/duo-adapter.json']
            && ($lockedRows[0]['scope'] ?? null) === 'adapter'
            && str_contains((string) $lockedRows[0]['message'], 'cannot prove')
            && ($locked['discover']['source'] ?? null) === null,
            "the adapter is REFUSED rather than installed, $label: the near-miss set is the evidence that this "
            . 'plugin bundles exactly one duo-adapter.json, and a directory that will not list has not produced '
            . 'it (rows: ' . implode(', ', array_column($locked['discover']['plugin_refusals'] ?? [], 'code')) . ')'
        );
        check(
            rows_with($locked['survey']['adapters'] ?? [], 'name', 'other-widget') !== [],
            "while the readable plugin beside it still installs, $label — per-adapter, as ever"
        );
    }
    @chmod($lockedRoot . '/acme', 0755);
}
$lockedPluginsRoot = plugins_dir('lockedroot', [
    'acme' => ['bundle' => bundle('acme-widget', 'acme/acme.php')],
]);
if (@chmod($lockedPluginsRoot, 0311) && !is_readable($lockedPluginsRoot)) {
    $rootLocked = child([
        'plugins' => $lockedPluginsRoot,
        'active' => ['acme/acme.php'],
        'name' => 'acme-widget',
        'strict_errors' => true,
        'tolerate_junk' => true,
    ]);
    check(
        !array_key_exists('__unparseable', $rootLocked),
        "an unreadable plugins ROOT likewise leaves the scan's output parseable (junk: "
        . substr((string) ($rootLocked['__unparseable'] ?? ''), 0, 160) . ')'
    );
    check(
        !isset($rootLocked['survey']['error'])
        && ($rootLocked['__stderr'] ?? '') === ''
        && rows_with($rootLocked['survey']['refusals'] ?? [], 'code', 'source_unreadable') !== []
        && ($rootLocked['discover']['source'] ?? null) === 'plugin',
        'an unreadable PLUGINS ROOT costs only the inactive listing — reported as its own row, while every ACTIVE '
        . 'plugin is still scanned by name and still installs'
    );
    check(
        array_values(array_unique(array_column(
            rows_with($rootLocked['survey']['refusals'] ?? [], 'code', 'source_unreadable'),
            'scope'
        ))) === ['adapter'],
        'and it is scoped to `adapter` even though it is about a directory — `source` would blank out every SITE '
        . "adapter's grammar verdict over a permission on somebody else's tree"
    );
    @chmod($lockedPluginsRoot, 0755);
} else {
    check(true, '(SKIPPED: this harness cannot create a search-without-read plugins root)');
    @chmod($lockedPluginsRoot, 0755);
}

// ======================================================================
echo "\n== an UNREADABLE bundle refuses cleanly, on the active path ==\n";
// ======================================================================
// The directory-level twin of the group above, one level down and on the path
// every Policy::load() takes. read_manifest() already caught the exception
// half, but Canon::read_file()'s file_get_contents() emitted a PHP warning
// BEFORE returning false — printed into the middle of whatever document the
// caller was building, which is how a --format=json answer became unparseable
// over a file the engine was about to refuse cleanly anyway.
$unreadableBundle = plugins_dir('unreadable', [
    'acme' => ['bundle' => bundle('acme-widget', 'acme/acme.php')],
    'good' => ['bundle' => bundle('other-widget', 'good/good.php')],
]);
if (@chmod($unreadableBundle . '/acme/duo-adapter.json', 0000)
    && !is_readable($unreadableBundle . '/acme/duo-adapter.json')) {
    foreach ([false, true] as $strictRead) {
        $readLabel = $strictRead ? 'with a warnings-as-exceptions handler' : 'with ordinary PHP error handling';
        $unreadable = child([
            'plugins' => $unreadableBundle,
            'active' => ['acme/acme.php', 'good/good.php'],
            'name' => 'acme-widget',
            'strict_errors' => $strictRead,
            'tolerate_junk' => true,
        ]);
        check(
            !array_key_exists('__unparseable', $unreadable)
            && ($unreadable['__stderr'] ?? '') === '',
            "an unreadable bundle leaves the scan's output a parseable document with an empty diagnostic "
            . "stream, $readLabel (junk: " . substr((string) ($unreadable['__unparseable'] ?? ''), 0, 160)
            . ' | stderr: ' . substr((string) ($unreadable['__stderr'] ?? ''), 0, 160) . ')'
        );
        $unreadableRows = rows_with($unreadable['discover']['plugin_refusals'] ?? [], 'code', 'malformed_manifest');
        check(
            count($unreadableRows) === 1
            && str_contains((string) $unreadableRows[0]['message'], 'cannot be read')
            && str_contains((string) $unreadableRows[0]['remediation'], 'readable by the user running duo')
            && ($unreadable['discover']['source'] ?? null) === null,
            "and refuses it as one clean row telling the operator what to fix, $readLabel (rows: "
            . implode(', ', array_column($unreadable['discover']['plugin_refusals'] ?? [], 'code')) . ')'
        );
        check(
            rows_with($unreadable['survey']['adapters'] ?? [], 'name', 'other-widget') !== [],
            "while the readable bundle beside it still installs, $readLabel"
        );
    }
    @chmod($unreadableBundle . '/acme/duo-adapter.json', 0644);
} else {
    check(true, '(SKIPPED: this harness cannot create an unreadable file on this filesystem/uid)');
    @chmod($unreadableBundle . '/acme/duo-adapter.json', 0644);
}

// ======================================================================
echo "\n== a bundle is CONTAINED before it is read — inactive plugins included ==\n";
// ======================================================================
// The inactive path used to read first and test containment never: a bundle
// symlinked out of WP_PLUGIN_DIR was opened, its declared name lifted into a
// reported row, and no refusal raised. Canon::read_file has no size ceiling,
// so "report the name of a file we are not going to install" was also an
// unbounded read of a path the plugin does not own.
$escapeRoot = scratch('escape');
mkdir($escapeRoot . '/plugins/sleeping', 0777, true);
write_file($escapeRoot . '/plugins/sleeping/sleeping.php', "<?php\n");
write_file($escapeRoot . '/outside/duo-adapter.json', Canon::encode(
    bundle('escaped-name', 'sleeping/sleeping.php')
));
symlink($escapeRoot . '/outside/duo-adapter.json', $escapeRoot . '/plugins/sleeping/duo-adapter.json');
// No `name` here on purpose: passing one makes the child call file(), whose
// not-found message legitimately echoes the name the CALLER asked for. The
// claim under test is that the name inside the FILE never appears, so nothing
// else may put it in the payload.
$escaped = child([
    'plugins' => $escapeRoot . '/plugins',
    'active' => [],
]);
$escapedRefusals = rows_with($escaped['survey']['refusals'] ?? [], 'code', 'symlink_source');
check(
    count($escapedRefusals) === 1
    && ($escapedRefusals[0]['paths'] ?? null) === ['plugins/sleeping/duo-adapter.json'],
    'an INACTIVE plugin whose bundle is a symlink out of the plugins directory draws the same symlink_source '
    . 'refusal an active one does (rows: '
    . implode(', ', array_column($escaped['survey']['refusals'] ?? [], 'code')) . ')'
);
check(
    $escaped['survey']['not_installed'] === []
    && rows_with($escaped['survey']['adapters'] ?? [], 'name', 'escaped-name') === [],
    'and its declared name never reaches a reported row — "not installed" describes what the engine will LOAD, '
    . 'and is not a licence to open a file the plugin does not own'
);
check(
    !str_contains(json_encode($escaped, JSON_UNESCAPED_SLASHES) ?: '', 'escaped-name'),
    'the name inside those bytes appears NOWHERE in the report, which is the proof the file was never read'
);

// ======================================================================
echo "\n== a third party's bytes cannot forge this report's own output ==\n";
// ======================================================================
// Every refusal row is printed to a terminal and embedded in a JSON document.
// An ANSI escape inside a declared name, a plugin basename, or a directory
// entry would otherwise clear the screen and print a line indistinguishable
// from this command's own — a refusal message forging the report it appears in.
$escape = "\x1b[2J\x1b[1;1Hok: everything is certified";
$injectionRoot = plugins_dir('injection', [
    'acme' => [
        'bundle' => adapter($escape, ['plugin' => 'acme/acme.php', 'version_range' => ['max' => '9.0.0', 'min' => '1.0.0']]),
    ],
]);
$injected = child(['plugins' => $injectionRoot, 'active' => ['acme/acme.php']]);
$injectedBlob = json_encode($injected, JSON_UNESCAPED_SLASHES) ?: '';
check(
    !str_contains($injectedBlob, "\x1b") && !str_contains($injectedBlob, ''),
    'a declared name carrying ANSI escapes produces a report with no escape byte anywhere in it'
);
check(
    str_contains($injectedBlob, 'hex ') && str_contains($injectedBlob, bin2hex($escape)),
    'and the hex receipt is still there, so the refusal stays actionable after the substitution'
);
$injectedInactive = child([
    'plugins' => plugins_dir('injection-inactive', [
        'sleeping' => ['bundle' => adapter($escape, [
            'plugin' => 'sleeping/sleeping.php',
            'version_range' => ['max' => '9.0.0', 'min' => '1.0.0'],
        ])],
    ]),
    'active' => [],
]);
$inactiveText = implode("\n", array_column($injectedInactive['survey']['not_installed'] ?? [], 'message'));
check(
    $injectedInactive['survey']['not_installed'] !== []
    && strcspn($inactiveText, "\x1b\x00\x07\r") === strlen($inactiveText)
    && str_contains($inactiveText, 'hex '),
    'the not_installed row an INACTIVE bundle produces is rendered the same way — that message interpolates a '
    . 'declared name straight out of a third party\'s JSON'
);
$injectedEntry = plugins_dir('injection-entry', [
    'acme' => ['bundle' => bundle('acme-widget', 'acme/acme.php')],
]);
$entryEscape = "duo-adapters\x1b[2J.json";
write_file($injectedEntry . '/acme/' . $entryEscape, "{}\n");
$entryInjected = child(['plugins' => $injectedEntry, 'active' => ["acme\x1b[2J/acme.php", 'acme/acme.php']]);
$entryText = implode("\n", array_column($entryInjected['discover']['plugin_refusals'] ?? [], 'message'));
check(
    $entryInjected['discover']['plugin_refusals'] !== []
    && str_contains($entryText, 'hex ')
    && strcspn($entryText, "\x1b\x00\x07\r") === strlen($entryText),
    'and so are a directory ENTRY name and an active_plugins basename, neither of which this scan authored either'
);
// Long AND refusable: `assert_name()` has no length cap of its own (a
// 4000-character lowercase slug is a legal identity), so an uppercase byte is
// what makes this reach a refusal message at all.
// The rendered TEXT, not just the row fields. render() sanitizes what the scan
// puts in a message, but `name` and `paths` are DATA — deliberately kept raw so
// a rendered line and a --format=json document describe the same bytes — and
// both renderers printed them straight to the terminal. Two vectors, one per
// field, driven end to end through the real renderers.
$inactiveNameVector = child([
    'plugins' => plugins_dir('render-name', [
        'sleeping' => ['bundle' => adapter($escape, [
            'plugin' => 'sleeping/sleeping.php',
            'version_range' => ['max' => '9.0.0', 'min' => '1.0.0'],
        ])],
    ]),
    'active' => [],
    'repo' => null,
    'mode' => 'survey_cli',
]);
check(
    str_contains((string) ($inactiveNameVector['text'] ?? ''), 'hex ')
    && strcspn((string) ($inactiveNameVector['text'] ?? ''), "\x1b\x00\x07")
        === strlen((string) ($inactiveNameVector['text'] ?? '')),
    'the RENDERED not-installed block carries no escape byte for an inactive bundle whose declared name is an '
    . 'ANSI sequence — the `name` field reaches the terminal through the renderer, not only through a message'
);
check(
    ($inactiveNameVector['document']['not_installed'][0]['name'] ?? null) === $escape,
    'while the DOCUMENT keeps that name exactly as the bundle declared it — a report whose rendered line and '
    . 'machine record disagreed about an identity would be worse than either (JSON transport escapes it as '
    . '\\u001b, which is a transport encoding rather than a sanitization)'
);
$pathVector = plugins_dir('render-path', [
    'acme' => ['bundle' => bundle('acme-widget', 'acme/acme.php')],
]);
write_file($pathVector . '/acme/' . "duo-adapters\x1b[2J.json", "{}\n");
$pathRendered = child([
    'plugins' => $pathVector,
    'active' => ['acme/acme.php'],
    'repo' => null,
    'mode' => 'survey_cli',
]);
check(
    str_contains((string) ($pathRendered['text'] ?? ''), 'hex ')
    && strcspn((string) ($pathRendered['text'] ?? ''), "\x1b\x00\x07")
        === strlen((string) ($pathRendered['text'] ?? '')),
    'and an ACTIVE plugin shipping a near-miss FILE whose name carries ESC bytes cannot inject through the '
    . 'refusal `paths` list either, which the renderer used to implode straight into the terminal'
);
check(
    ($pathRendered['document']['refusals'][0]['paths'][0] ?? null) === "plugins/acme/duo-adapters\x1b[2J.json",
    'and that path is likewise exact in the document, so it still names the file an operator has to go delete'
);

// The INSTALLED row was the one row type the wraps above did not cover — a
// perfectly VALID bundle from an ESC-named plugin directory reaches the
// terminal through the adapter table's own `path` column, and its
// `tier_basis` (computed before any grammar verdict, so unvalidated by
// construction) sits beside `trust_tier`, the highest-value forgery target
// on the row. The attacker's best move is a well-formed bundle; this pins
// that the row for a SUCCESSFUL install renders too.
$validDirEscape = "okplug\x1b[2Jx";
$validInstalled = child([
    'plugins' => plugins_dir('render-installed', [
        $validDirEscape => ['bundle' => adapter('escrowed-widget', [
            'plugin' => $validDirEscape . '/okplug.php',
            'version_range' => ['max' => '9.0.0', 'min' => '1.0.0'],
            'actions' => [[
                'kind' => 'native',
                'action' => 'transient.delete',
                'args' => ['name' => "seen\x1b[2J\x1b[1;31mCERTIFIED\x1b[0m"],
                'triggers' => ['option:escrowed_widget_layout'],
                'effects' => [],
            ]],
        ])],
    ]),
    'active' => [$validDirEscape . '/okplug.php'],
    'repo' => null,
    'mode' => 'survey_cli',
]);
$installedRows = array_column((array) ($validInstalled['document']['adapters'] ?? []), 'name');
check(
    in_array('escrowed-widget', $installedRows, true),
    'the ESC-directory bundle really is INSTALLED — the fixture drives the adapter-table row, not a refusal'
);
check(
    strcspn((string) ($validInstalled['text'] ?? ''), "\x1b\x00\x07")
        === strlen((string) ($validInstalled['text'] ?? '')),
    'a VALID bundle installed from an ESC-named plugin directory renders zero escape bytes — the installed '
    . 'row\'s path and tier_basis go through the renderer like every refused and shadowed row already did'
);
check(
    str_contains((string) json_encode($validInstalled['document'] ?? [], JSON_UNESCAPED_SLASHES), 'okplug'),
    'while the document keeps the raw path bytes for correlation, as everywhere else'
);

// tier_basis is the second untrusted string on that row, and it needs its own
// vector: the basis interpolates the DECLARED action name, which
// tier_decision() reads before any grammar verdict — so an ESC-bearing action
// name reaches the basis even though grammar will refuse the declaration a
// moment later (the row still prints, with its grammar error beside it).
$basisVector = child([
    'plugins' => plugins_dir('render-basis', [
        'basisplug' => ['bundle' => adapter('basis-widget', [
            'plugin' => 'basisplug/basisplug.php',
            'version_range' => ['max' => '9.0.0', 'min' => '1.0.0'],
            'actions' => [[
                'kind' => 'native',
                'action' => "do\x1b[2J\x1b[1;31mCERTIFIED\x1b[0m",
                'args' => [],
                'triggers' => ['option:basis_widget_layout'],
                'effects' => [],
            ]],
        ])],
    ]),
    'active' => ['basisplug/basisplug.php'],
    'repo' => null,
    'mode' => 'survey_cli',
]);
$basisRow = null;
foreach ((array) ($basisVector['document']['adapters'] ?? []) as $candidateRow) {
    if (($candidateRow['name'] ?? null) === 'basis-widget') {
        $basisRow = $candidateRow;
    }
}
check(
    is_array($basisRow) && str_contains((string) ($basisRow['tier_basis'] ?? ''), "\x1b"),
    'the fixture manufactured what it claims: the DOCUMENT row\'s tier_basis really carries the raw ESC bytes'
);
check(
    strcspn((string) ($basisVector['text'] ?? ''), "\x1b\x00\x07")
        === strlen((string) ($basisVector['text'] ?? '')),
    'and the RENDERED tier basis line — sitting beside trust_tier, the highest-value forgery target on the '
    . 'row — carries zero escape bytes'
);

// The third channel, and the one the other two do not reach: a plugin
// DIRECTORY whose own name carries ESC bytes puts them in the middle of every
// repo-relative path this scan builds — including the paths interpolated into
// the shared privilege message, which is engine prose rather than a data field.
$dirEscape = "acme\x1b[2Jx";
$dirEscapeRoot = plugins_dir('render-dir', []);
write_file($dirEscapeRoot . '/' . $dirEscape . '/' . $dirEscape . '.php', "<?php\n");
write_file(
    $dirEscapeRoot . '/' . $dirEscape . '/duo-adapter.json',
    Canon::encode(bundle('acme-widget', $dirEscape . '/' . $dirEscape . '.php', ['interpreter' => 'acf']))
);
$dirEscaped = child([
    'plugins' => $dirEscapeRoot,
    'active' => [$dirEscape . '/' . $dirEscape . '.php'],
    'repo' => null,
    'mode' => 'survey_cli',
]);
$dirEscapedRefusals = $dirEscaped['document']['refusals'] ?? [];
check(
    rows_with($dirEscapedRefusals, 'code', 'out_of_tree_privilege') !== []
    && strcspn((string) ($dirEscaped['text'] ?? ''), "\x1b\x00\x07")
        === strlen((string) ($dirEscaped['text'] ?? ''))
    && str_contains((string) ($dirEscaped['text'] ?? ''), 'hex '),
    'a plugin DIRECTORY whose name carries ESC bytes cannot inject through the shared privilege refusal either '
    . '— that message is engine prose interpolating a path, so rendering the data fields alone would have left '
    . 'it open (codes: ' . implode(', ', array_column($dirEscapedRefusals, 'code')) . ')'
);

$longName = str_repeat('A', 4000);
$capped = child([
    'plugins' => plugins_dir('longname', [
        'acme' => ['bundle' => adapter($longName, [
            'plugin' => 'acme/acme.php',
            'version_range' => ['max' => '9.0.0', 'min' => '1.0.0'],
        ])],
    ]),
    'active' => ['acme/acme.php'],
]);
$cappedRow = ($capped['discover']['plugin_refusals'] ?? [])[0] ?? [];
check(
    ($cappedRow['code'] ?? null) === 'invalid_adapter_name'
    && strlen((string) ($cappedRow['message'] ?? '')) < 1200
    && str_contains((string) ($cappedRow['message'] ?? ''), 'truncated from 4000 bytes'),
    'a 4000-byte declared name is rendered capped and SAYS it was capped, rather than pasting itself into every '
    . 'row of the report (message length: ' . strlen((string) ($cappedRow['message'] ?? '')) . ')'
);

// ======================================================================
echo "\n== the anchor names an exact plugin FILE, not merely its directory ==\n";
// ======================================================================
// A directory can hold more than one plugin file, and only some of them are
// active. Anchoring on dirname() alone accepted a manifest in plugins/acme/
// declaring `acme/other.php` while `acme/acme.php` was the plugin that was
// activated — after which its version_range, plugin_not_active, and
// plugin_version_mismatch verdicts were all answered against a plugin file
// nobody turned on.
$multiHeader = plugins_dir('multiheader', [
    'acme' => ['bundle' => bundle('acme-widget', 'acme/other.php'), 'files' => ['other.php' => "<?php\n"]],
]);
$wrongFile = child(['plugins' => $multiHeader, 'active' => ['acme/acme.php'], 'name' => 'acme-widget']);
$wrongFileRows = $wrongFile['discover']['plugin_refusals'] ?? [];
check(
    count($wrongFileRows) === 1
    && ($wrongFileRows[0]['code'] ?? null) === 'plugin_anchor_mismatch'
    && str_contains((string) $wrongFileRows[0]['message'], "'acme/other.php'")
    && str_contains((string) $wrongFileRows[0]['message'], "'acme/acme.php'")
    && ($wrongFile['discover']['source'] ?? null) === null,
    'a bundle naming a DIFFERENT plugin file in its own directory is refused, and the message names both the '
    . 'claim and the plugin that actually owns the bundle (rows: '
    . implode(', ', array_column($wrongFileRows, 'code')) . ')'
);
$bothActive = child([
    'plugins' => $multiHeader,
    'active' => ['acme/acme.php', 'acme/other.php'],
    'name' => 'acme-widget',
]);
check(
    ($bothActive['discover']['plugin_refusals'] ?? null) === []
    && ($bothActive['discover']['source'] ?? null) === 'plugin'
    && ($bothActive['discover']['path'] ?? null) === 'plugins/acme/duo-adapter.json',
    'while a directory whose SECOND plugin file is also active accepts the manifest that names it — exact '
    . 'equality against every active basename in that directory, not against whichever one sorts first'
);
// IDENTITY, not just acceptance. The anchored basename enters the §5
// provenance reason, the reason enters the disposition, and the disposition
// enters the adapter digest a pin binds — so taking the alphabetically-first
// active basename instead of the PROVEN one made the adapter's identity depend
// on which SIBLING plugin happened to be active. Deactivating an unrelated
// plugin then silently moved the digest, and the pin failed with a mismatch
// pointing at nothing the operator had touched.
$bothActivePin = child([
    'plugins' => $multiHeader,
    'active' => ['acme/acme.php', 'acme/other.php'],
    'repo' => site_repo([['name' => 'acme-widget', 'source' => 'plugin']]),
    'name' => 'acme-widget',
    'mode' => 'pin',
]);
$siblingGonePin = child([
    'plugins' => $multiHeader,
    'active' => ['acme/other.php'],
    'repo' => site_repo([['name' => 'acme-widget', 'source' => 'plugin']]),
    'name' => 'acme-widget',
    'mode' => 'pin',
]);
check(
    is_string($bothActivePin['pin']['digest'] ?? null)
    && ($bothActivePin['pin']['digest'] ?? null) === ($siblingGonePin['pin']['digest'] ?? null),
    'and the adapter\'s DIGEST is unchanged when an unrelated sibling plugin in the same directory is '
    . 'deactivated — its identity is a function of its own bytes and its own path, never of which neighbour '
    . 'happens to be running (both active: ' . substr((string) ($bothActivePin['pin']['digest'] ?? '?'), 0, 12)
    . ', sibling off: ' . substr((string) ($siblingGonePin['pin']['digest'] ?? '?'), 0, 12) . ')'
);
check(
    str_contains(
        (string) ($bothActivePin['discover']['provenance']['reason'] ?? ''),
        "bundled by the active plugin 'acme/other.php'"
    ),
    'because the digest-bearing reason names the plugin the manifest ANCHORED to, which is the one the version '
    . 'and activation verdicts will be answered against (reason: '
    . substr((string) ($bothActivePin['discover']['provenance']['reason'] ?? '(none)'), 0, 110) . '...)'
);

// ======================================================================
echo "\n== two shapes that used to be invisible ==\n";
// ======================================================================
$dirBundle = plugins_dir('dirbundle', ['acme' => []]);
mkdir($dirBundle . '/acme/duo-adapter.json', 0777, true);
write_file($dirBundle . '/acme/duo-adapter.json/real.json', "{}\n");
$dirBundleResult = child(['plugins' => $dirBundle, 'active' => ['acme/acme.php'], 'name' => 'acme-widget']);
$dirRows = rows_with($dirBundleResult['discover']['plugin_refusals'] ?? [], 'code', 'symlink_source');
check(
    count($dirRows) === 1
    && str_contains((string) $dirRows[0]['message'], 'a directory')
    && ($dirBundleResult['discover']['source'] ?? null) === null,
    'a DIRECTORY named duo-adapter.json draws a refusal naming what it actually is — is_file() alone passed it '
    . 'over in silence, so a plugin whose author made one installed nothing and was told nothing'
);
// The bait is real: a duo-adapter.json ONE LEVEL ABOVE the plugins directory,
// which is exactly where `..` lands. Without the fixture the exclusion would
// be untestable — the walk would find nothing there and pass for the wrong
// reason.
$traversalRoot = plugins_dir('traversal', ['acme' => ['bundle' => bundle('acme-widget', 'acme/acme.php')]]);
write_file(dirname($traversalRoot) . '/duo-adapter.json', Canon::encode(adapter('smuggled', [
    'plugin' => '../x.php',
])));
$traversalActive = child([
    'plugins' => $traversalRoot,
    'active' => ['../evil.php', 'acme/acme.php'],
    'name' => 'acme-widget',
]);
check(
    is_file(dirname($traversalRoot) . '/duo-adapter.json'),
    'the traversal bait exists one level above the plugins directory, so the exclusion below is testable'
);
check(
    ($traversalActive['discover']['plugin_refusals'] ?? null) === []
    && ($traversalActive['discover']['source'] ?? null) === 'plugin'
    && !str_contains(json_encode($traversalActive, JSON_UNESCAPED_SLASHES) ?: '', 'smuggled'),
    'a poisoned active_plugins row whose basename walks up out of the plugins directory is skipped before any '
    . 'I/O — the file waiting there is never opened, named, or reported'
);

// ======================================================================
echo "\n== `uncertified` is the ONLY certification word this source can hold ==\n";
// ======================================================================
// A manifest library carrying neither dispositions nor a generated registry
// makes no product claim, and a SHIPPED row there reports `null`. A plugin row
// must still report `uncertified` — the word is a property of the source, not
// of whether this library happens to have a reviewed certification story, and
// falling through to the site branch there would answer `certification_unjudged`
// about evidence that could not exist in the first place.
$bareLibrary = scratch('bare-library');
write_file($bareLibrary . '/solo.json', Canon::encode(adapter('solo')));
$bare = child([
    'plugins' => $happyPlugins,
    'active' => ['acme/acme.php'],
    'manifests' => $bareLibrary,
    'name' => 'acme-widget',
]);
$bareRows = $bare['survey']['adapters'] ?? [];
$barePluginRow = rows_with($bareRows, 'name', 'acme-widget')[0] ?? [];
$bareShippedRow = rows_with($bareRows, 'name', 'solo')[0] ?? [];
check(
    array_key_exists('certification', $barePluginRow) && $barePluginRow['certification'] === 'uncertified'
    && array_key_exists('certification', $bareShippedRow) && $bareShippedRow['certification'] === null,
    'against a registry-less library the plugin row still says exactly `uncertified` while the shipped row beside '
    . 'it correctly says nothing at all (plugin: '
    . var_export($barePluginRow['certification'] ?? '(absent)', true) . ', shipped: '
    . var_export($bareShippedRow['certification'] ?? '(absent)', true) . ')'
);

// The DISCRIMINATING fixture, and the check above is not it: against a
// registry-less library `site_certification()` would answer `uncertified` for
// a plugin row anyway, so routing plugin rows through it is indistinguishable
// there. What separates the two is a live `certification_source` refusal —
// which B2 made reachable from the PLUGIN source, by a plugin shipping a
// `duo-adapter.certification.json` beside its bundle.
//
// Two independent bugs are pinned here at once. Routing a plugin row through
// the site branch answers `certification_unjudged` about a certificate that
// cannot exist for this source at all; and letting that refusal set the
// unjudged flag WITHOUT filtering on `source` lets one third party's stray
// file re-judge every adapter in the operator's own repository.
$strayCertPlugins = plugins_dir('straycert', [
    'acme' => [
        'bundle' => bundle('acme-widget', 'acme/acme.php'),
        'files' => ['duo-adapter.certification.json' => "{}\n"],
    ],
    'good' => ['bundle' => bundle('other-widget', 'good/good.php')],
]);
$strayCertRepo = site_repo(
    [['name' => 'keeper', 'source' => 'site']],
    ['keeper' => adapter('keeper')]
);
$strayCert = child([
    'plugins' => $strayCertPlugins,
    'active' => ['acme/acme.php', 'good/good.php'],
    'repo' => $strayCertRepo,
    'name' => 'other-widget',
]);
$strayRows = $strayCert['survey']['adapters'] ?? [];
$strayPluginRow = rows_with($strayRows, 'name', 'other-widget')[0] ?? [];
$straySiteRow = rows_with($strayRows, 'name', 'keeper')[0] ?? [];
check(
    rows_with($strayCert['survey']['refusals'] ?? [], 'code', 'certification_source') !== []
    && array_values(array_unique(array_column(
        rows_with($strayCert['survey']['refusals'] ?? [], 'code', 'certification_source'),
        'source'
    ))) === ['plugin'],
    'a plugin shipping a certificate-shaped companion draws a certification_source refusal attributed to the '
    . 'PLUGIN source (rows: '
    . implode(', ', array_column($strayCert['survey']['refusals'] ?? [], 'code')) . ')'
);
check(
    array_key_exists('certification', $strayPluginRow)
    && $strayPluginRow['certification'] === 'uncertified',
    'the healthy plugin row beside it still says exactly `uncertified` — never `certification_unjudged`, which '
    . 'would be a verdict about certificate evidence this source cannot carry in the first place (found: '
    . var_export($strayPluginRow['certification'] ?? '(absent)', true) . ')'
);
check(
    array_key_exists('certification', $straySiteRow)
    && $straySiteRow['certification'] === 'uncertified',
    'and the operator\'s OWN site adapter is untouched by it: one third party\'s stray file must not re-judge '
    . 'every adapter in this repository, which is grammar_verdict()\'s rule applied to the same two fields '
    . '(found: ' . var_export($straySiteRow['certification'] ?? '(absent)', true) . ')'
);
// The filter has to hold in BOTH directions, and this is the half that also
// discriminates "plugin rows are flatly uncertified" from "plugin rows go
// through site_certification()". A genuine SITE certification-source refusal
// DOES set the unjudged flag — correctly, for site rows, whose companion
// certificates really were never paired. A plugin row must not inherit it:
// `certification_unjudged` would be a verdict about evidence that cannot exist
// for this source at all, which is a different false statement from the one
// above and needs its own fixture to see.
$siteStrayRepo = site_repo(
    [['name' => 'keeper', 'source' => 'site']],
    ['keeper' => adapter('keeper')]
);
write_file($siteStrayRepo . '/adapters/certifications/README', "notes\n");
$siteStray = child([
    'plugins' => $happyPlugins,
    'active' => ['acme/acme.php'],
    'repo' => $siteStrayRepo,
    'name' => 'acme-widget',
]);
$siteStrayRefusals = rows_with($siteStray['survey']['refusals'] ?? [], 'code', 'certification_source');
$siteStrayPluginRow = rows_with($siteStray['survey']['adapters'] ?? [], 'name', 'acme-widget')[0] ?? [];
check(
    $siteStrayRefusals !== []
    && ($siteStrayRefusals[0]['source'] ?? null) === 'site',
    'a malformed SITE certification directory still draws its own certification_source refusal, attributed to '
    . 'the site source'
);
check(
    array_key_exists('certification', $siteStrayPluginRow)
    && $siteStrayPluginRow['certification'] === 'uncertified',
    'and a plugin row beside it is still exactly `uncertified` — it does not inherit the site source\'s '
    . '`certification_unjudged`, which would report unexamined certificate evidence for a source that can '
    . 'carry none (found: ' . var_export($siteStrayPluginRow['certification'] ?? '(absent)', true) . ')'
);

// ======================================================================
echo "\n== identity: the same bytes from two sources are two adapters (case o) ==\n";
// ======================================================================
$sameBytes = bundle('acme-widget', 'acme/acme.php');
$asSite = site_repo([['name' => 'acme-widget', 'source' => 'site']], ['acme-widget' => $sameBytes]);
$asSiteResult = child([
    'plugins' => plugins_dir('identity-none', ['other' => ['bundle' => null]]),
    'active' => [],
    'repo' => $asSite,
    'name' => 'acme-widget',
    'mode' => 'pin',
]);
$asPlugin = child([
    'plugins' => $happyPlugins,
    'active' => ['acme/acme.php'],
    'repo' => site_repo([['name' => 'acme-widget', 'source' => 'plugin']]),
    'name' => 'acme-widget',
    'mode' => 'pin',
]);
check(
    is_string($asSiteResult['pin']['digest'] ?? null)
    && is_string($asPlugin['pin']['digest'] ?? null)
    && $asSiteResult['pin']['digest'] !== $asPlugin['pin']['digest']
    && ($asSiteResult['pin']['source'] ?? null) === 'site'
    && ($asPlugin['pin']['source'] ?? null) === 'plugin',
    'byte-identical manifests installed from two different sources are two DIFFERENT adapter identities — the '
    . 'provenance record (path, source, and its digest-bearing reason) is inside the digest, so a pin cannot be '
    . 'moved between sources without being noticed'
);

// ======================================================================
echo "\n== a frozen bundled adapter reconstructs where no plugin directory exists ==\n";
// ======================================================================
$frozenChild = child([
    'plugins' => $happyPlugins,
    'active' => ['acme/acme.php'],
    'repo' => site_repo([['name' => 'acme-widget', 'source' => 'plugin']]),
    'name' => 'acme-widget',
    'mode' => 'snapshot',
]);
$snapshot = $frozenChild['snapshot'] ?? [];
check(
    ($snapshot['adapter_sources']['out_of_tree']['acme-widget']['provenance']['source'] ?? null) === 'plugin'
    && ($snapshot['adapter_sources']['out_of_tree']['acme-widget']['provenance']['path'] ?? null)
        === 'plugins/acme/duo-adapter.json'
    && ($snapshot['adapter_sources']['format'] ?? null) === AdapterSources::FORMAT
    && ($snapshot['format'] ?? null) === 'duo-policy-snapshot/v6'
    && !array_key_exists('capabilities', $snapshot),
    'the exported snapshot freezes the bundled provenance inside the adapter-sources record it always used — the '
    . 'path is a function of the manifest\'s own `plugin` claim, so bundled provenance still needs no key of its '
    . 'own; v6 is the generation that dropped the retired generated capability registry, not one this feature bought'
);
// This process has never defined WP_PLUGIN_DIR and never will: reconstructing
// here is the proof that the frozen path re-derives the bundled provenance
// rather than reopening the plugin directory it came from.
$frozen = Policy::from_snapshot($snapshot);
check(
    $frozen->adapter_sources()->source('acme-widget') === 'plugin'
    && $frozen->adapter_sources()->path('acme-widget') === 'plugins/acme/duo-adapter.json'
    && \Duo\RepositoryCompiler::resolved_adapters($frozen) === ($frozenChild['resolved'] ?? null),
    'and a process with NO plugin directory at all reconstructs the identical source, path, and digest'
);
check(
    $frozen->adapter_sources()->sources() === []
    && $frozen->adapter_sources()->not_installed() === []
    && $frozen->adapter_sources()->plugin_refusals() === [],
    'while honestly reporting that it scanned nothing — a reconstructed policy reopened no source, so it knows '
    . 'nothing about what is on this machine today'
);

if ($failures !== 0) {
    fwrite(STDERR, "\n$failures plugin-adapter-source regression assertion(s) failed\n");
    exit(1);
}

echo "\nALL PASSED\n";

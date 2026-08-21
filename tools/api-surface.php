#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Duo public/protected API-surface snapshot (WP-10).
 *
 * WHAT THIS IS FOR
 * -----------------
 * Regenerate-and-gate, exactly like tools/classmap-generate.php's
 * check/generate pair: a reflected characterization of every loaded Duo\*
 * class/interface/trait/enum's PUBLIC and PROTECTED surface (kind,
 * final/abstract/readonly, parent, implemented interfaces, own-declared
 * constants, properties, and methods with full parameter/return signatures)
 * sorted and serialized to one deterministic JSON fixture
 * (sandbox/tests/fixtures/api-surface.json). tests/Tooling/ApiSurfaceTest.php
 * pins `--check` against that committed fixture, so a decomposition PR
 * (docs/adapter-boundary.md; DUO-3335 byte-compatibility
 * posture) that silently drops, narrows, or renames a method some OTHER file
 * still calls fails a fast, offline PHPUnit assertion instead of surfacing
 * three call-sites deep in the offline corpus -- or not surfacing
 * at all, if nothing offline happens to exercise that exact path today.
 * Regenerate deliberately with `--write` in the SAME commit that intends the
 * change; an unexplained fixture diff in review is exactly the signal this
 * exists to produce.
 *
 * WHAT THIS IS NOT
 * -----------------
 * Not a behavior test. A method whose SIGNATURE is unchanged but whose body
 * now does something different is invisible here -- that is what the
 * offline corpus and PHPUnit product tests are for. Not a completeness check
 * on PRIVATE members: only public/protected are captured, because a private
 * method is not callable from any OTHER file, so it is not part of the
 * "surface" a decomposition PR could accidentally break externally. Not a
 * check on inherited members: a class's own entry lists only what IT
 * declares (ReflectionMethod/Property/ClassConstant::getDeclaringClass()
 * filters out inherited members), because an inherited member's removal
 * already shows up on the class that actually declares it -- listing it
 * again on every subclass would just be duplicate noise in every diff.
 *
 * HOW IT WORKS
 * ------------
 * Reflection cannot happen in THIS process: the drop-in has no autoloader
 * (agent/duo.php require_once's 91 files, which in turn require_once the
 * remaining agent/src siblings; cli/duo does the same for cli/src) and
 * composer's autoload-dev maps only Duo\Tests\ (tests/bootstrap.php), so
 * every Duo\* symbol exists only after that whole require chain has run --
 * and once a class is declared in a PHP process it stays declared, so a
 * second `--check` in the SAME process could not observe a clean slate. A
 * fresh `PHP_BINARY` child is therefore spawned for every single
 * invocation (as_generate() below): it loads the WordPress + WP-CLI stubs
 * from vendor/php-stubs when present (falling back to a two-method WP_CLI
 * stub otherwise -- see as_bootstrap_stubs()), defines just enough WP
 * constants for agent/duo.php's own top-level guard
 * (`if (!defined('ABSPATH') && !(defined('WP_CLI') && WP_CLI)) return;`,
 * agent/duo.php:8) and agent/src/Command/Cli.php's file-scope
 * `WP_CLI::add_command('duo', Cli::class)` (agent/src/Command/Cli.php:2954) to both
 * load, requires agent/duo.php, then requires cli/duo's own bootstrap
 * requires in cli/duo's own order (as_cli_bootstrap_paths() regex-parses
 * them straight out of cli/duo rather than hand-duplicating the list, so it
 * cannot silently drift the day cli/duo's prologue changes) -- deliberately
 * NOT cli/duo itself, which unconditionally executes `exit(main($argv))`
 * with no reachable-without-running guard. RefreshPlanCompile.php is never
 * in that list (cli/duo never requires it -- it is a standalone worker
 * script invoked via proc_open, confirmed by grepping cli/ for its
 * filename) and recovery/rollback-control.php loads naturally via
 * cli/src/Command/AdoptCommand.php's own require_once, exactly as it would under a
 * real `duo` invocation. The child then reflects every declared symbol
 * whose name starts with `Duo\` and prints canonical JSON
 * (\Duo\Canon::encode(), already loaded transitively -- sorted keys, LF,
 * trailing newline) to its own stdout, which this parent process reads back
 * over a pipe and either prints, diffs, or writes to the fixture.
 *
 * Usage:
 *   php tools/api-surface.php            print canonical JSON to stdout
 *   php tools/api-surface.php --write    regenerate sandbox/tests/fixtures/api-surface.json
 *   php tools/api-surface.php --check    regenerate + byte-compare the fixture;
 *                                        exit 1 with a readable diff on mismatch
 */

/** Repo-relative fixture path, resolved against $repo by every caller. */
const API_SURFACE_FIXTURE_RELATIVE = '/sandbox/tests/fixtures/api-surface.json';

/** Internal-only argv marker recognised by as_main(); never a public flag. */
const API_SURFACE_SUBPROCESS_FLAG = '--__reflect-subprocess';

function as_usage(): string
{
    return <<<TXT
    Usage:
      php tools/api-surface.php            print canonical JSON to stdout
      php tools/api-surface.php --write    regenerate sandbox/tests/fixtures/api-surface.json
      php tools/api-surface.php --check    regenerate + byte-compare the fixture

    TXT;
}

function as_repo_root(): string
{
    return dirname(__DIR__);
}

function as_fixture_path(string $repo): string
{
    return $repo . API_SURFACE_FIXTURE_RELATIVE;
}

/**
 * Enough WordPress + WP-CLI surface for agent/duo.php's top-level guard and
 * agent/src/Command/Cli.php's file-scope `WP_CLI::add_command(...)` call to both
 * load without a real WordPress runtime -- and nothing more. Every OTHER
 * agent/src|cli/src top-level statement that reads ABSPATH/WP_CONTENT_DIR/
 * WP_PLUGIN_DIR is guarded by `defined(...)` INSIDE a function or method
 * body (verified with `grep -rn 'ABSPATH\|WP_CONTENT_DIR\|WP_PLUGIN_DIR'
 * agent/src cli/src` when this file was written: every hit outside a
 * doc-comment sits inside a function/method), so mere reflection -- which
 * inspects signatures without ever calling the method that owns them --
 * never reaches those branches regardless of what these constants resolve
 * to. The paths therefore only need to be syntactically well-formed
 * strings, never real directories.
 */
function as_bootstrap_stubs(string $repo): void
{
    $wordpressStubs = $repo . '/vendor/php-stubs/wordpress-stubs/wordpress-stubs.php';
    $wpCliStubs = $repo . '/vendor/php-stubs/wp-cli-stubs/wp-cli-stubs.php';

    if (is_file($wordpressStubs)) {
        require_once $wordpressStubs;
    }

    if (is_file($wpCliStubs)) {
        require_once $wpCliStubs;
    } elseif (!class_exists('WP_CLI', false)) {
        // No vendor/ (a checkout before `composer install`, or a hermetic
        // sandbox that never installs it): the drop-in itself never depends
        // on vendor/ (AGENTS.md non-negotiable 1), so this dev tool falls
        // back rather than hard-requiring it. Only the one file-scope call
        // this snapshot's own include chain actually reaches
        // (add_command(), agent/src/Command/Cli.php:2954) needs to be a real no-op;
        // every other WP_CLI:: call in the tree lives inside a method body
        // that reflection never executes.
        if (!class_exists('WP_CLI_Command', false)) {
            abstract class WP_CLI_Command
            {
            }
        }
        final class WP_CLI
        {
            /** @param mixed $callable @param array<string,mixed> $args */
            public static function add_command(string $name, $callable, array $args = []): void
            {
            }
        }
    }

    if (!defined('ABSPATH')) {
        define('ABSPATH', rtrim(sys_get_temp_dir(), '/') . '/duo-api-surface-abspath/');
    }
    if (!defined('WP_CONTENT_DIR')) {
        define('WP_CONTENT_DIR', rtrim((string) ABSPATH, '/') . '/wp-content');
    }
    if (!defined('WP_PLUGIN_DIR')) {
        define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
    }
    if (!defined('WP_CLI')) {
        define('WP_CLI', true);
    }
}

/**
 * cli/duo's own bootstrap requires, in cli/duo's own order, resolved to
 * absolute paths -- regex-parsed straight out of cli/duo rather than
 * hand-duplicated, so this can never silently drift from what a real `duo`
 * invocation actually loads. The prologue (everything before the first
 * `use Duo\Orchestrator\...` line) is exactly `require`/`require_once`
 * statements of the three shapes cli/duo and cli/src ever use --
 * `__DIR__ . '...'`, `dirname(__DIR__) . '...'`, `dirname(__DIR__, 2) .
 * '...'` -- plus comments and one `if (is_file(...)) { require ...; }`
 * guard (RefreshPlan.php); `is_file()` on the resolved path reproduces that
 * guard generically without needing to special-case it.
 *
 * Each returned file self-requires its own dependencies (require_once, so
 * later re-requires of an already-loaded file are no-ops) -- confirmed by
 * walking the require graph from this exact list: it reaches 47 of
 * cli/src's 48 files, the sole absence being RefreshPlanCompile.php, which
 * cli/duo never requires at all (it is a standalone `proc_open` worker
 * script, not part of the host shell's own class graph) and which the task
 * that produced this tool separately excludes as "executable". No
 * additional exclusion logic is therefore needed here: this list already
 * IS "every cli/src/*.php file except RefreshPlanCompile.php", derived
 * rather than asserted.
 *
 * @return list<string> absolute file paths, in cli/duo's own require order
 */
function as_cli_bootstrap_paths(string $repo): array
{
    $source = (string) file_get_contents($repo . '/cli/duo');
    $prologueEnd = strpos($source, "\nuse Duo\\Orchestrator\\");
    if ($prologueEnd === false) {
        throw new RuntimeException(
            'api-surface: cli/duo bootstrap prologue shape changed '
            . '(no "use Duo\\Orchestrator\\..." marker found) -- update as_cli_bootstrap_paths()'
        );
    }
    $prologue = substr($source, 0, $prologueEnd);

    $pattern = '/require(?:_once)?\\s+(__DIR__|dirname\\(__DIR__\\)|dirname\\(__DIR__,\\s*2\\))'
        . "\\s*\\.\\s*'([^']+)'/";
    if (preg_match_all($pattern, $prologue, $matches, PREG_SET_ORDER) === false) {
        throw new RuntimeException('api-surface: could not parse cli/duo require lines');
    }

    $paths = [];
    foreach ($matches as $match) {
        $base = match ($match[1]) {
            '__DIR__' => $repo . '/cli',
            'dirname(__DIR__)' => $repo,
            default => $repo, // dirname(__DIR__, 2) — unused in cli/duo's own prologue today; handled for symmetry with cli/src's own require shapes.
        };
        $resolved = $base . $match[2];
        if (is_file($resolved)) {
            $paths[] = $resolved;
        }
    }

    if ($paths === []) {
        throw new RuntimeException('api-surface: parsed zero require lines out of cli/duo — parser or prologue is broken');
    }

    return $paths;
}

/**
 * Runs ONLY inside the fresh child process (dispatched by as_main() on
 * API_SURFACE_SUBPROCESS_FLAG). Loads the full Duo\* class graph and prints
 * canonical JSON to stdout; never returns a value because its one output
 * channel is the pipe as_generate() reads.
 */
function as_reflect_and_print(string $repo): void
{
    as_bootstrap_stubs($repo);

    require_once $repo . '/agent/duo.php';
    foreach (as_cli_bootstrap_paths($repo) as $path) {
        require_once $path;
    }

    $surface = as_build_surface();
    echo \Duo\Canon::encode($surface);
}

/** @return list<string> every declared class/interface/trait/enum name starting with "Duo\" */
function as_declared_duo_symbols(): array
{
    $all = array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits());
    $names = [];
    foreach ($all as $name) {
        if (str_starts_with($name, 'Duo\\')) {
            $names[$name] = true;
        }
    }
    $result = array_keys($names);
    sort($result, SORT_STRING);
    return $result;
}

/** @return array{format:string,class_count:int,classes:array<string,mixed>} */
function as_build_surface(): array
{
    $classes = [];
    foreach (as_declared_duo_symbols() as $name) {
        $classes[$name] = as_reflect_symbol($name);
    }
    return [
        'format' => 'duo-api-surface/v1',
        'class_count' => count($classes),
        'classes' => $classes,
    ];
}

/** @return array<string,mixed> */
function as_reflect_symbol(string $name): array
{
    $r = new ReflectionClass($name);
    $kind = match (true) {
        $r->isInterface() => 'interface',
        $r->isTrait() => 'trait',
        $r->isEnum() => 'enum',
        default => 'class',
    };
    $parent = $r->getParentClass();
    $interfaces = $r->getInterfaceNames();
    sort($interfaces, SORT_STRING);

    $data = [
        'kind' => $kind,
        'final' => $r->isFinal(),
        'abstract' => $r->isAbstract(),
        'readonly' => $r->isReadOnly(),
        'parent' => $parent !== false ? $parent->getName() : null,
        'interfaces' => $interfaces,
        'constants' => as_reflect_constants($r),
        'properties' => as_reflect_properties($r),
        'methods' => as_reflect_methods($r),
    ];

    if ($r->isEnum()) {
        $data['cases'] = as_reflect_enum_cases($name);
    }

    return $data;
}

/** @return array<string,mixed> own-declared, public/protected only */
function as_reflect_constants(ReflectionClass $r): array
{
    $out = [];
    foreach ($r->getReflectionConstants() as $constant) {
        if ($constant->getDeclaringClass()->getName() !== $r->getName() || $constant->isPrivate()) {
            continue;
        }
        $out[$constant->getName()] = ['value_repr' => as_value_repr($constant->getValue())];
    }
    return $out;
}

/** @return array<string,mixed> own-declared, public/protected only */
function as_reflect_properties(ReflectionClass $r): array
{
    $out = [];
    foreach ($r->getProperties() as $property) {
        if ($property->getDeclaringClass()->getName() !== $r->getName() || $property->isPrivate()) {
            continue;
        }
        $type = $property->getType();
        $out[$property->getName()] = [
            'visibility' => $property->isProtected() ? 'protected' : 'public',
            'static' => $property->isStatic(),
            'readonly' => $property->isReadOnly(),
            'type' => $type !== null ? (string) $type : null,
        ];
    }
    return $out;
}

/** @return array<string,mixed> own-declared, public/protected only */
function as_reflect_methods(ReflectionClass $r): array
{
    $out = [];
    foreach ($r->getMethods() as $method) {
        if ($method->getDeclaringClass()->getName() !== $r->getName() || $method->isPrivate()) {
            continue;
        }
        $returnType = $method->getReturnType();
        $out[$method->getName()] = [
            'visibility' => $method->isProtected() ? 'protected' : 'public',
            'static' => $method->isStatic(),
            'abstract' => $method->isAbstract(),
            'final' => $method->isFinal(),
            'return_type' => $returnType !== null ? (string) $returnType : null,
            'parameters' => as_reflect_parameters($method),
        ];
    }
    return $out;
}

/** @return list<array<string,mixed>> positional; never key-sorted (order is the signature) */
function as_reflect_parameters(ReflectionMethod $method): array
{
    $out = [];
    foreach ($method->getParameters() as $param) {
        $type = $param->getType();
        $hasDefault = $param->isDefaultValueAvailable();
        $default = null;
        if ($hasDefault) {
            // A constant-expression default (e.g. `= self::FORMAT`) is
            // stored by its NAME rather than its resolved literal: the
            // point of this snapshot is the DECLARED signature, and two
            // constants that happen to share a value today are still a
            // different signature if either name changes tomorrow.
            $default = $param->isDefaultValueConstant()
                ? $param->getDefaultValueConstantName()
                : as_value_repr($param->getDefaultValue());
        }
        $out[] = [
            'name' => $param->getName(),
            'type' => $type !== null ? (string) $type : null,
            'by_ref' => $param->isPassedByReference(),
            'variadic' => $param->isVariadic(),
            'has_default' => $hasDefault,
            'default' => $default,
        ];
    }
    return $out;
}

/** @return array<string,mixed> case name => backing value repr (unbacked: null) */
function as_reflect_enum_cases(string $name): array
{
    $re = new ReflectionEnum($name);
    $out = [];
    foreach ($re->getCases() as $case) {
        $out[$case->getName()] = ($re->isBacked() && $case instanceof ReflectionEnumBackedCase)
            ? as_value_repr($case->getBackingValue())
            : null;
    }
    return $out;
}

/**
 * A stable, JSON-safe representation of a class-constant or default-parameter
 * value. Scalars/null/arrays-of-those round-trip through JSON as-is (Canon's
 * own encoder sorts/serializes them exactly like every other value in this
 * document); the only PHP class-constant expression that is NOT one of those
 * is an enum case, rendered as `EnumClass::CASE_NAME` (plus its backing value
 * for a backed enum) since that is what actually appears in the source.
 *
 * @param mixed $value
 * @return mixed
 */
function as_value_repr($value)
{
    if ($value instanceof UnitEnum) {
        $repr = get_class($value) . '::' . $value->name;
        return $value instanceof BackedEnum
            ? $repr . '(' . var_export($value->value, true) . ')'
            : $repr;
    }
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = as_value_repr($v);
        }
        return $out;
    }
    if (is_scalar($value) || $value === null) {
        return $value;
    }
    // A PHP class-constant value must be a constant-expression (scalar,
    // array of those, class-const reference, or enum case) -- reaching here
    // would mean either a language change this file predates or a bug in
    // the branches above, so fail loud in the OUTPUT rather than in a
    // silent json_encode() error further up the call chain.
    return '(unrepresentable:' . get_debug_type($value) . ')';
}

/**
 * Spawns a FRESH `PHP_BINARY` child (never the running process — see the
 * file header's HOW IT WORKS) and returns its canonical-JSON stdout.
 *
 * @throws RuntimeException on spawn failure, a non-zero exit, or empty stdout
 */
function as_generate(string $repo): string
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open(
        [PHP_BINARY, __FILE__, API_SURFACE_SUBPROCESS_FLAG, $repo],
        $descriptors,
        $pipes,
        $repo
    );
    if (!is_resource($proc)) {
        throw new RuntimeException('api-surface: could not spawn ' . PHP_BINARY . ' ' . __FILE__);
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    if ($exit !== 0) {
        throw new RuntimeException("api-surface: reflection subprocess exited $exit\n" . $err);
    }
    if ($out === '') {
        throw new RuntimeException("api-surface: reflection subprocess produced no output\n" . $err);
    }
    return $out;
}

function as_scalar_repr(mixed $value): string
{
    if ($value === null) {
        return 'null';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '[]';
    }
    return (string) $value;
}

/**
 * Pure structural diff between two decoded surface documents -- exposed (not
 * only reachable through --check's own control flow) specifically so
 * tests/Tooling/ApiSurfaceTest.php can assert its behavior directly against
 * a synthetic mutation without shelling out to a real subprocess reflection
 * run for every case.
 *
 * @param array<string,mixed> $before
 * @param array<string,mixed> $after
 * @return list<string> human-readable lines, one per changed/added/removed
 *         class or member; empty when the two surfaces are equivalent
 */
function as_diff(array $before, array $after): array
{
    $lines = [];
    $beforeClasses = is_array($before['classes'] ?? null) ? $before['classes'] : [];
    $afterClasses = is_array($after['classes'] ?? null) ? $after['classes'] : [];

    $names = array_unique(array_merge(array_keys($beforeClasses), array_keys($afterClasses)));
    sort($names, SORT_STRING);

    foreach ($names as $name) {
        $inBefore = array_key_exists($name, $beforeClasses);
        $inAfter = array_key_exists($name, $afterClasses);
        if (!$inBefore) {
            $lines[] = "+ $name (added)";
            continue;
        }
        if (!$inAfter) {
            $lines[] = "- $name (removed)";
            continue;
        }
        $lines = array_merge($lines, as_diff_class($name, $beforeClasses[$name], $afterClasses[$name]));
    }
    return $lines;
}

/**
 * @param array<string,mixed> $before
 * @param array<string,mixed> $after
 * @return list<string>
 */
function as_diff_class(string $name, array $before, array $after): array
{
    $lines = [];
    foreach (['kind', 'final', 'abstract', 'readonly', 'parent'] as $field) {
        $b = $before[$field] ?? null;
        $a = $after[$field] ?? null;
        if ($b !== $a) {
            $lines[] = "~ $name: $field changed (" . as_scalar_repr($b) . ' -> ' . as_scalar_repr($a) . ')';
        }
    }
    $bInterfaces = $before['interfaces'] ?? [];
    $aInterfaces = $after['interfaces'] ?? [];
    if ($bInterfaces !== $aInterfaces) {
        $lines[] = "~ $name: interfaces changed";
    }
    foreach (['constants', 'properties', 'methods'] as $bucket) {
        $b = is_array($before[$bucket] ?? null) ? $before[$bucket] : [];
        $a = is_array($after[$bucket] ?? null) ? $after[$bucket] : [];
        $lines = array_merge($lines, as_diff_members($name, $bucket, $b, $a));
    }
    return $lines;
}

/**
 * @param array<string,mixed> $before
 * @param array<string,mixed> $after
 * @return list<string>
 */
function as_diff_members(string $className, string $bucket, array $before, array $after): array
{
    $lines = [];
    $names = array_unique(array_merge(array_keys($before), array_keys($after)));
    sort($names, SORT_STRING);

    foreach ($names as $member) {
        $inBefore = array_key_exists($member, $before);
        $inAfter = array_key_exists($member, $after);
        if (!$inBefore) {
            $lines[] = "+ $className::$member ($bucket, added)";
            continue;
        }
        if (!$inAfter) {
            $lines[] = "- $className::$member ($bucket, removed)";
            continue;
        }
        if ($before[$member] !== $after[$member]) {
            $lines[] = "~ $className::$member ($bucket, changed)";
        }
    }
    return $lines;
}

/** @param list<string> $argv */
function as_main(array $argv): int
{
    if (($argv[1] ?? '') === API_SURFACE_SUBPROCESS_FLAG) {
        as_reflect_and_print($argv[2] ?? as_repo_root());
        return 0;
    }

    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['', '--write', '--check'], true)) {
        fwrite(STDERR, as_usage());
        return 2;
    }

    $repo = as_repo_root();

    try {
        $json = as_generate($repo);
    } catch (Throwable $e) {
        fwrite(STDERR, 'api-surface: ' . $e->getMessage() . "\n");
        return 1;
    }

    $fixture = as_fixture_path($repo);

    if ($mode === '--write') {
        $dir = dirname($fixture);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            fwrite(STDERR, "api-surface: cannot create directory $dir\n");
            return 1;
        }
        if (file_put_contents($fixture, $json) === false) {
            fwrite(STDERR, "api-surface: could not write $fixture\n");
            return 1;
        }
        fwrite(STDOUT, "api-surface: wrote $fixture\n");
        return 0;
    }

    if ($mode === '--check') {
        if (!is_file($fixture)) {
            fwrite(STDERR, "api-surface: no committed fixture at $fixture; run --write first\n");
            return 1;
        }
        $committed = (string) file_get_contents($fixture);
        if ($committed === $json) {
            fwrite(STDOUT, "api-surface: OK ($fixture matches the current surface)\n");
            return 0;
        }

        fwrite(STDERR, "api-surface: $fixture is stale.\n");
        fwrite(STDERR, "  Run 'php tools/api-surface.php --write' if this change is intended, then commit the fixture.\n");
        $before = json_decode($committed, true);
        $after = json_decode($json, true);
        if (is_array($before) && is_array($after)) {
            foreach (as_diff($before, $after) as $line) {
                fwrite(STDERR, "  $line\n");
            }
        } else {
            fwrite(STDERR, "  (fixture is not valid JSON -- showing raw byte mismatch only)\n");
        }
        return 1;
    }

    fwrite(STDOUT, $json);
    return 0;
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    // $_SERVER['argv'] rather than the bare $argv superglobal: PHPStan
    // cannot prove register_argc_argv is on (it always is for the CLI SAPI
    // this script runs under), so the bare form is a permanent false
    // positive here; $_SERVER is a tracked superglobal and avoids it. This
    // guard also lets tests/Tooling/ApiSurfaceTest.php `require` this file
    // in-process and call as_diff()/as_reflect_symbol()/etc directly without
    // as_main() ever running (mirrors tools/affected.php's own af_main()
    // guard, pinned by tests/Tooling/AffectedTest.php).
    $cliArgv = $_SERVER['argv'] ?? [];
    exit(as_main(is_array($cliArgv) ? array_map('strval', $cliArgv) : []));
}

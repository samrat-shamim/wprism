<?php

declare(strict_types=1);

namespace WPrism\Tooling;

use WPrism\AdapterLibrary;
use WPrism\ArtifactPolicyIdentity;
use WPrism\Canon;
use WPrism\LegacyRuntimeExecutionDebt;
use WPrism\ManifestDispositions;
use WPrism\Policy;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/agent/src/Policy/AdapterLibrary.php';
require_once dirname(__DIR__, 2) . '/agent/src/Policy/Policy.php';
require_once dirname(__DIR__, 2) . '/agent/src/Policy/ArtifactPolicyIdentity.php';
require_once dirname(__DIR__, 2) . '/agent/src/Policy/LegacyRuntimeExecutionDebt.php';
require_once __DIR__ . '/ActiveShellSource.php';
require_once __DIR__ . '/AdapterProductionReadiness.php';
require_once __DIR__ . '/AdapterPackageTestDiscovery.php';
require_once __DIR__ . '/ArtifactLibrary.php';

/** Validate one adapter capsule without reading or executing a sibling adapter. */
final class AdapterPackageValidator
{
    public const FORMAT = 'wprism-adapter-package-validation/v1';
    public const RUNTIME_SDK_FORMAT = 'wprism-adapter-runtime-sdk/v2';
    private const EXTERNAL_EVIDENCE_FORMAT = 'wprism-adapter-external-evidence/v1';
    private const INTEGRATION_SCENARIO_FORMAT = 'wprism-adapter-integration-scenario/v1';
    private const PREMISE_EVIDENCE = 'target-observation-premises.tsv';

    /**
     * Package live tests run from sandbox/, so these reviewed harness
     * libraries are the only intentional cwd-relative source dependencies.
     * Package-owned sources use the BASH_SOURCE-relative form enforced below.
     *
     * @var list<string>
     */
    private const REVIEWED_SHARED_SHELL_SOURCES = [
        'bin/fetch-artifact.sh',
        'conformance/asserts.sh',
        'lib/pair_identity.sh',
        'lib/pair_db.sh',
        'tests/lib/private_command_capture.sh',
        'tests/lib/conformance_private_command.sh',
        'tests/lib/wordpress_cron_window.sh',
    ];

    /** @var list<string> */
    private const SHARED_EVIDENCE_PREFIXES = [
        'agent/src/',
        'platform/adapter-library/',
        'sandbox/conformance/',
        'sandbox/tests/offline/apply/',
        'sandbox/tests/offline/grammar/',
        'sandbox/tests/offline/reference-scope/',
        'sandbox/tests/offline/repository/',
    ];

    /** @var list<string> */
    private const SHARED_EVIDENCE_PATHS = [
        'sandbox/tests/live/regress_capture_concurrency.sh',
        'sandbox/tests/live/regress_multisite_refusal.sh',
    ];

    /**
     * Public engine symbols used by the current manifest-bound runtime.
     *
     * This closed set is the versioned adapter ABI. Adding a dependency is an
     * engine SDK decision; merely importing another WPrism class from a capsule is
     * not enough to make that internal class public.
     *
     * @var list<string>
     */
    private const RUNTIME_SDK_SYMBOLS = [
        'WPrism\\CacheInvalidationTransaction',
        'WPrism\\Canon',
        'WPrism\\IdentityTokenCodec',
        'WPrism\\Ledger',
        'WPrism\\ManifestProviderRuntime',
        'WPrism\\NativeActions',
        'WPrism\\NativeRewriteEffects',
        'WPrism\\PlainData',
        'WPrism\\Policy',
        'WPrism\\ProviderSdk',
        'WPrism\\Providers',
        'WPrism\\Secrets',
        'WPrism\\SidebarState',
        'WPrism\\Tokens',
    ];

    /**
     * Exact, immutable static-regression inventory of runtime infrastructure
     * which predates the engine-owned provider process/database boundaries.
     * This lexical check is not a hostile-PHP sandbox; reviewed package
     * provenance and digest binding are the executable trust boundary. A
     * listed source may keep only the findings already present in these
     * reviewed bytes: changing one byte requires migrating the file and
     * deleting its row, never merely refreshing the hash. This keeps
     * historical debt from acting as a public SDK or as precedent for a newly
     * authored adapter.
     *
     * @var array<string,array{sha256:string,findings:list<string>,migration:string}>
     */
    private const LEGACY_RUNTIME_EXECUTION_DEBT = LegacyRuntimeExecutionDebt::ROWS;

    /** @var list<string> */
    private const RUNTIME_PROCESS_FUNCTIONS = [
        'exec',
        'passthru',
        'pcntl_exec',
        'pcntl_fork',
        'popen',
        'proc_open',
        'shell_exec',
        'system',
    ];

    /** @var list<string> */
    private const WPDB_NON_TRANSPORT_METHODS = [
        'esc_like',
        'get_blog_prefix',
        'prepare',
    ];

    /** @var list<string> */
    private const RAW_DATABASE_CLASSES = ['mysqli', 'mysqli_stmt', 'pdo', 'wpdb'];

    /** @var list<string> */
    private const WP_CLI_PROCESS_METHODS = ['launch', 'launch_self', 'run_command', 'runcommand'];

    /** @return array{format:string,symbols:list<string>} */
    public static function runtimeSdk(): array
    {
        return [
            'format' => self::RUNTIME_SDK_FORMAT,
            'symbols' => self::RUNTIME_SDK_SYMBOLS,
        ];
    }

    /**
     * Every convention- or scenario-discovered evidence id one capsule owns.
     *
     * This is public because package validation and the whole-library
     * disposition regression answer the same wiring question. Keeping the
     * discovery here prevents the aggregate gate from growing a second roster
     * whenever a capsule adds an ordinary test or participant-owned scenario.
     *
     * @return list<string>
     */
    public static function discoverableEvidence(string $repoRoot, string $slug): array
    {
        $root = self::repositoryRoot($repoRoot);
        if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
            throw new RuntimeException("Adapter package slug is not canonical: $slug");
        }
        $capsule = $root . '/adapter-packages/' . $slug;
        if (realpath($capsule) !== $capsule || !is_dir($capsule) || is_link($capsule)) {
            throw new RuntimeException("Adapter package '$slug' is not an ordinary capsule: $capsule");
        }

        $available = [];
        $testsRoot = $capsule . '/tests';
        if (is_dir($testsRoot)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($testsRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $entry) {
                if (!$entry->isFile() || $entry->isLink()) {
                    continue;
                }
                $relative = substr($entry->getPathname(), strlen($testsRoot) + 1);
                $parts = explode('/', $relative);
                $class = $parts[0] ?? '';
                $basename = $entry->getBasename('.' . $entry->getExtension());
                $prefix = match ($class) {
                    'certify' => '(?:certify|regress)',
                    'spike' => 'spike',
                    'offline', 'live', 'conformance' => 'regress',
                    default => null,
                };
                if ($prefix !== null
                    && in_array($entry->getExtension(), ['php', 'sh'], true)
                    && preg_match('/^' . $prefix . '_[a-z0-9][a-z0-9._-]*$/D', $basename) === 1) {
                    $available[str_replace('_', '-', $basename)] = true;
                }
            }
        }
        $conformance = $testsRoot . '/conformance';
        if (is_file($conformance . '/entry.json')) {
            $entry = Canon::decode(Canon::read_file($conformance . '/entry.json'));
            if (!is_array($entry)
                || ($entry['manifest'] ?? null) !== $slug
                || !is_array($entry['entry'] ?? null)
                || !is_file($conformance . '/seed.sh')
                || !is_file($conformance . '/check.sh')) {
                throw new RuntimeException("Adapter package '$slug' conformance fixture is incomplete or misnamed");
            }
            $available['conformance-' . $slug] = true;
        }
        if (is_file($testsRoot . '/certify/version-matrix.sh')) {
            $available['exact-artifact-version-matrix'] = true;
        }
        foreach (self::externalEvidence($root, $capsule, $slug) as $test => $_path) {
            if (isset($available[$test])) {
                throw new RuntimeException("Adapter package '$slug' external evidence collides with local test '$test'");
            }
            $available[$test] = true;
        }

        $tests = array_keys($available);
        sort($tests, SORT_STRING);
        return $tests;
    }

    /**
     * @return array{
     *     format:string,
     *     adapter:string,
     *     digest:string,
     *     manifest_sha256:string,
     *     checks:list<string>,
     *     evidence_tests:list<string>
     * }
     */
    public static function validate(string $repoRoot, string $slug): array
    {
        $root = self::repositoryRoot($repoRoot);
        self::defineVersions($root);
        $capsule = $root . '/adapter-packages/' . $slug;
        self::assertNoOutOfBoundaryExecutableSources($capsule, $slug);
        $library = AdapterLibrary::fromSourcePackage($root, $slug);
        $package = $library->package($slug);
        if ($package === null) {
            throw new RuntimeException("Adapter package '$slug' did not enter its own closed library");
        }
        $checks = ['closed-library', 'manifest-grammar', 'reviewed-disposition', 'adapter-identity'];
        self::dependencyBoundary($root, $capsule, $slug, $checks);

        $manifest = Canon::decode(Canon::read_file($package->manifestPath()));
        if (!is_array($manifest)) {
            throw new RuntimeException("Adapter package '$slug' manifest is not an object");
        }
        $disposition = Canon::decode(Canon::read_file($package->dispositionPath()));
        if (!is_array($disposition)) {
            throw new RuntimeException("Adapter package '$slug' disposition is not an object");
        }
        $registry = ManifestDispositions::load_library($library);
        $registry->assert_covers([$manifest]);

        $policy = Policy::load(null, ['core', $slug], adapterLibrary: $library);
        $identity = null;
        foreach (ArtifactPolicyIdentity::resolved_adapters($policy) as $row) {
            if (($row['name'] ?? null) === $slug) {
                $identity = $row;
                break;
            }
        }
        if (!is_array($identity) || !is_string($identity['digest'] ?? null)) {
            throw new RuntimeException("Adapter package '$slug' produced no identity row");
        }

        self::syntax($capsule, $checks);
        self::premiseEvidence($root, $capsule, $slug, $manifest, $checks);
        self::packageEvidence($root, $slug, $manifest, $disposition, $checks);
        $evidenceTests = self::evidence($root, $capsule, $slug, $manifest, $disposition, $checks);
        $discovered = AdapterPackageTestDiscovery::discover($root, $slug);
        $checks[] = 'test-discovery:' . count($discovered['tests']);

        return [
            'format' => self::FORMAT,
            'adapter' => $slug,
            'digest' => $identity['digest'],
            'manifest_sha256' => hash('sha256', Canon::encode($manifest)),
            'checks' => $checks,
            'evidence_tests' => $evidenceTests,
        ];
    }

    private static function assertNoOutOfBoundaryExecutableSources(string $capsule, string $slug): void
    {
        if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $slug) !== 1
            || !is_dir($capsule)
            || is_link($capsule)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($capsule, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink() || !$entry->isFile()) {
                continue;
            }
            $path = $entry->getPathname();
            $relative = substr($path, strlen($capsule) + 1);
            if (str_starts_with($relative, 'package/runtime/')
                || str_starts_with($relative, 'tests/')
                || str_starts_with($relative, 'fixtures/')
                || str_starts_with($relative, 'evidence/')) {
                continue;
            }
            $source = file_get_contents($path);
            if ($source === false) {
                throw new RuntimeException("Adapter package '$slug' cannot read authoring member $path");
            }
            $extension = strtolower($entry->getExtension());
            if (!in_array($extension, ['php', 'sh'], true)
                && preg_match('/<\?(?:php|=)|\A#![^\r\n]*(?:php|(?:ba|z)?sh)(?:[ \t]|$)/i', $source) !== 1) {
                continue;
            }
            throw new RuntimeException(
                "Adapter package '$slug' has executable source outside recognized package code roots at $relative"
            );
        }
    }

    /** @param list<string> $checks */
    private static function dependencyBoundary(string $root, string $capsule, string $slug, array &$checks): void
    {
        $agentSource = realpath($root . '/agent/src');
        if ($agentSource === false || !is_dir($agentSource)) {
            throw new RuntimeException("Adapter package '$slug' cannot resolve the repository agent/src contract");
        }
        self::assertCapsuleNodesAreOrdinary($capsule, $slug);
        $runtimeSymbols = self::declaredRuntimeSymbols($capsule . '/package/runtime');
        $legacyRuntimeDebt = [];

        $scanned = 0;
        foreach ([
            $capsule . '/package/runtime',
            $capsule . '/tests',
            $capsule . '/fixtures',
            $capsule . '/evidence',
        ] as $boundaryRoot) {
            if (!is_dir($boundaryRoot)) {
                continue;
            }
            $tests = $boundaryRoot !== $capsule . '/package/runtime';
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($boundaryRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $entry) {
                if ($entry->isDir()) {
                    continue;
                }
                if ($entry->isLink() || !$entry->isFile()) {
                    $relative = substr($entry->getPathname(), strlen($capsule) + 1);
                    throw new RuntimeException(
                        "Adapter package '$slug' contains a non-ordinary dependency node at $relative"
                    );
                }
                $path = $entry->getPathname();
                $extension = strtolower($entry->getExtension());
                $source = file_get_contents($path);
                if ($source === false) {
                    throw new RuntimeException("Adapter package '$slug' cannot read dependency source $path");
                }
                $unsupportedExecutable = !in_array($extension, ['json', 'php', 'sh'], true)
                    && (preg_match('/^(?:inc|phtml|php[0-9]*|phar|bash|zsh|ksh)$/D', $extension) === 1
                        || preg_match('/<\?(?:php|=)/i', $source) === 1
                        || preg_match('/\A#![^\r\n]*(?:php|(?:ba|z)?sh)(?:[ \t]|$)/i', $source) === 1);
                if ($unsupportedExecutable) {
                    $relative = substr($path, strlen($capsule) + 1);
                    throw new RuntimeException(
                        "Adapter package '$slug' has executable source with an unsupported extension at $relative"
                    );
                }
                if ($extension === 'sh') {
                    self::assertShellSourceDependenciesUseRecognizedFiles($root, $capsule, $path, $source, $slug);
                }
                if ($boundaryRoot === $capsule . '/evidence' && !in_array($extension, ['php', 'sh'], true)) {
                    $scanned++;
                    continue;
                }
                self::assertNoGlobalLibrarySelection($capsule, $path, $source, $tests, $slug);
                self::assertNoSiblingCapsuleReference(
                    $capsule,
                    $path,
                    $source,
                    $extension,
                    $slug
                );
                self::assertRelativeAgentDependencies($root, $agentSource, $capsule, $path, $source, $tests, $slug);
                if (!$tests && $extension === 'php') {
                    $legacy = self::assertRuntimeExecutionBoundary($capsule, $path, $source, $slug);
                    if ($legacy !== null) {
                        $legacyRuntimeDebt[$legacy] = true;
                    }
                    self::assertRuntimeSdk($capsule, $path, $source, $slug, $runtimeSymbols);
                }
                $scanned++;
            }
        }
        self::assertCompleteLegacyRuntimeDebt($slug, $legacyRuntimeDebt);
        $checks[] = "dependency-boundary:$scanned";
        $checks[] = 'runtime-sdk:' . self::RUNTIME_SDK_FORMAT;
        $checks[] = 'runtime-execution-boundary:' . count($legacyRuntimeDebt);
    }

    private static function assertShellSourceDependenciesUseRecognizedFiles(
        string $root,
        string $capsule,
        string $path,
        string $source,
        string $slug
    ): void {
        $commandLines = [];
        foreach (ActiveShellSource::commandLines($source) as $commandLine) {
            $commandLines[$commandLine['line']] = $commandLine['code'];
        }
        foreach (ActiveShellSource::lines($source) as $line) {
            $code = trim($line['code']);
            $sourceCommand = self::leadingShellSourceCommand($code);
            if ($sourceCommand === null) {
                $commandCode = trim($commandLines[$line['line']] ?? '');
                if (self::containsEmbeddedShellSourceCommand($code, $commandCode)) {
                    self::shellSourceFailure($capsule, $path, $slug, $line['line'], $code);
                }
                continue;
            }
            $candidate = rtrim($sourceCommand);
            $resolved = null;
            if (in_array($candidate, self::REVIEWED_SHARED_SHELL_SOURCES, true)) {
                $resolved = $root . '/sandbox/' . $candidate;
            } elseif (preg_match(
                '~^"\$\(dirname "\$\{BASH_SOURCE\[0\]\}"\)/'
                    . '(?<relative>[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*)"$~D',
                $candidate,
                $packageMatch
            ) === 1) {
                $resolved = dirname($path) . '/' . $packageMatch['relative'];
            }
            $canonical = is_string($resolved) ? realpath($resolved) : false;
            $insideCapsule = is_string($canonical)
                && str_starts_with($canonical, rtrim($capsule, '/') . '/');
            $reviewedShared = is_string($canonical)
                && in_array($candidate, self::REVIEWED_SHARED_SHELL_SOURCES, true)
                && $canonical === realpath($root . '/sandbox/' . $candidate);
            if (!is_string($canonical)
                || !is_file($canonical)
                || is_link($canonical)
                || !str_ends_with($canonical, '.sh')
                || (!$insideCapsule && !$reviewedShared)) {
                self::shellSourceFailure($capsule, $path, $slug, $line['line'], $candidate);
            }
        }
    }

    private static function leadingShellSourceCommand(string $code): ?string
    {
        $cursor = 0;
        $length = strlen($code);
        while ($cursor < $length) {
            while ($cursor < $length && ($code[$cursor] === ' ' || $code[$cursor] === "\t")) {
                $cursor++;
            }
            if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\+)?=/', substr($code, $cursor)) !== 1) {
                break;
            }
            $cursor = self::shellWordEnd($code, $cursor);
            if ($cursor >= $length || !ctype_space($code[$cursor])) {
                return null;
            }
        }
        while ($cursor < $length && ($code[$cursor] === ' ' || $code[$cursor] === "\t")) {
            $cursor++;
        }
        $command = self::literalShellWord($code, $cursor);
        if ($command === null || ($command['word'] !== 'source' && $command['word'] !== '.')) {
            return null;
        }
        $cursor = $command['end'];
        if ($cursor >= $length || !ctype_space($code[$cursor])) {
            return null;
        }
        $candidate = ltrim(substr($code, $cursor));
        return $candidate === '' ? null : $candidate;
    }

    private static function containsEmbeddedShellSourceCommand(string $code, string $commandCode): bool
    {
        if (preg_match_all(
            '/[;&|(){}!]|\b(?:if|then|elif|while|until|do|else|time|command|builtin|exec|coproc)\b/',
            $commandCode,
            $boundaries,
            PREG_OFFSET_CAPTURE
        ) !== false) {
            foreach ($boundaries[0] as [$boundary, $offset]) {
                if (self::leadingShellSourceCommand(substr($code, $offset + strlen($boundary))) !== null) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function shellWordEnd(string $code, int $cursor): int
    {
        $quote = null;
        $escaped = false;
        $parentheses = 0;
        $braces = 0;
        for ($length = strlen($code); $cursor < $length; $cursor++) {
            $character = $code[$cursor];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($quote !== "'" && $character === '\\') {
                $escaped = true;
                continue;
            }
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($character === "'" || $character === '"') {
                $quote = $character;
                continue;
            }
            if ($character === '$' && ($code[$cursor + 1] ?? '') === '(') {
                $parentheses++;
                $cursor++;
                continue;
            }
            if ($character === '$' && ($code[$cursor + 1] ?? '') === '{') {
                $braces++;
                $cursor++;
                continue;
            }
            if ($parentheses > 0 && $character === '(') {
                $parentheses++;
                continue;
            }
            if ($parentheses > 0 && $character === ')') {
                $parentheses--;
                continue;
            }
            if ($braces > 0 && $character === '}') {
                $braces--;
                continue;
            }
            if ($parentheses === 0
                && $braces === 0
                && (ctype_space($character) || str_contains(';&|()<>', $character))) {
                break;
            }
        }
        return $cursor;
    }

    /** @return array{word:string,end:int}|null */
    private static function literalShellWord(string $code, int $cursor): ?array
    {
        $word = '';
        $started = false;
        for ($length = strlen($code); $cursor < $length;) {
            $character = $code[$cursor];
            if (ctype_space($character) || str_contains(';&|()<>', $character)) {
                break;
            }
            $started = true;
            if ($character === '\\') {
                if (!isset($code[$cursor + 1])) {
                    return null;
                }
                $word .= $code[$cursor + 1];
                $cursor += 2;
                continue;
            }
            if ($character === "'" || $character === '"') {
                $quote = $character;
                for ($cursor++; $cursor < $length && $code[$cursor] !== $quote; $cursor++) {
                    if ($quote === '"' && $code[$cursor] === '\\' && isset($code[$cursor + 1])) {
                        $escaped = $code[$cursor + 1];
                        if (str_contains('$`"\\', $escaped)) {
                            $word .= $escaped;
                            $cursor++;
                            continue;
                        }
                    }
                    if ($quote === '"' && ($code[$cursor] === '$' || $code[$cursor] === '`')) {
                        return null;
                    }
                    $word .= $code[$cursor];
                }
                if ($cursor >= $length) {
                    return null;
                }
                $cursor++;
                continue;
            }
            if ($character === '$' || $character === '`') {
                return null;
            }
            $word .= $character;
            $cursor++;
        }
        return $started ? ['word' => $word, 'end' => $cursor] : null;
    }

    private static function shellSourceFailure(
        string $capsule,
        string $path,
        string $slug,
        int $line,
        string $candidate
    ): never {
        $relative = substr($path, strlen($capsule) + 1);
        throw new RuntimeException(
            "Adapter package '$slug' sources a dependency that is not an explicit recognized .sh file at "
            . "$relative:$line ('$candidate')"
        );
    }

    private static function assertCapsuleNodesAreOrdinary(string $capsule, string $slug): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($capsule, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if (!$entry->isLink()
                && ($entry->isFile() || $entry->isDir())
                && realpath($path) === $path) {
                continue;
            }
            $relative = substr($path, strlen($capsule) + 1);
            throw new RuntimeException(
                "Adapter package '$slug' contains a symlink or non-ordinary node at $relative"
            );
        }
    }

    private static function assertNoSiblingCapsuleReference(
        string $capsule,
        string $path,
        string $source,
        string $extension,
        string $slug
    ): void {
        $fragments = [];
        if ($extension === 'php') {
            $tokens = token_get_all($source);
            foreach ($tokens as $offset => $token) {
                if (!is_array($token)
                    || !in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }
                $fragments[] = [
                    $token[0] === T_CONSTANT_ENCAPSED_STRING
                        ? self::decodePhpStringLiteral($token[1])
                        : $token[1],
                    $token[2],
                ];
                if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $concatenated = self::concatenatedPhpStringLiteral($tokens, $offset);
                    if ($concatenated !== null) {
                        $fragments[] = [$concatenated, $token[2]];
                    }
                }
            }
        } else {
            foreach (preg_split('/\R/', $source) ?: [] as $offset => $line) {
                if ($extension === 'sh' && str_starts_with(ltrim($line), '#')) {
                    continue;
                }
                $fragments[] = [$line, $offset + 1];
            }
        }

        foreach ($fragments as [$fragment, $line]) {
            self::assertNoRelativeSiblingPath($capsule, $path, $fragment, $line, $slug);
            if (!str_contains($fragment, 'adapter-packages/')) {
                continue;
            }
            preg_match_all(
                '~adapter-packages/(?<adapter>[a-z][a-z0-9]*(?:-[a-z0-9]+)*)(?:/|$|(?=[\'"$]))~',
                $fragment,
                $matches
            );
            $adapters = array_values(array_unique(array_filter(
                $matches['adapter'] ?? [],
                static fn(mixed $adapter): bool => is_string($adapter)
            )));
            foreach ($adapters as $adapter) {
                if ($adapter === $slug) {
                    continue;
                }
                $relative = substr($path, strlen($capsule) + 1);
                throw new RuntimeException(
                    "Adapter package '$slug' references sibling adapter package '$adapter' at $relative:$line"
                );
            }
            if ($adapters === []) {
                $relative = substr($path, strlen($capsule) + 1);
                throw new RuntimeException(
                    "Adapter package '$slug' has an unresolved adapter-packages path at $relative:$line; "
                    . "package sources must name adapter-packages/$slug directly"
                );
            }
        }
    }

    private static function assertNoRelativeSiblingPath(
        string $capsule,
        string $sourcePath,
        string $fragment,
        int $line,
        string $slug
    ): void {
        preg_match_all(
            '~(?<![A-Za-z0-9_.-])(?<relative>(?:\.\./)+(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]+)~',
            $fragment,
            $matches
        );
        $packagesRoot = dirname($capsule);
        $relativeSource = substr($sourcePath, strlen($capsule) + 1);
        $bases = [dirname($sourcePath) => false, $capsule => true];
        foreach ($matches['relative'] ?? [] as $relativePath) {
            if (!is_string($relativePath)) {
                continue;
            }
            foreach ($bases as $base => $requiresExistingCapsule) {
                $resolved = self::lexicalAbsolutePath($base, $relativePath);
                $prefix = rtrim($packagesRoot, '/') . '/';
                if (!str_starts_with($resolved, $prefix)) {
                    continue;
                }
                $remainder = substr($resolved, strlen($prefix));
                $adapter = explode('/', $remainder, 2)[0];
                if ($adapter === ''
                    || $adapter === $slug
                    || $adapter === 'adapter-packages'
                    || preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $adapter) !== 1) {
                    continue;
                }
                if ($requiresExistingCapsule && !is_dir($packagesRoot . '/' . $adapter)) {
                    continue;
                }
                throw new RuntimeException(
                    "Adapter package '$slug' references sibling adapter package '$adapter' at "
                    . "$relativeSource:$line"
                );
            }
        }
    }

    private static function lexicalAbsolutePath(string $base, string $relative): string
    {
        $segments = explode('/', rtrim($base, '/') . '/' . $relative);
        $normalized = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($normalized);
                continue;
            }
            $normalized[] = $segment;
        }
        return '/' . implode('/', $normalized);
    }

    /**
     * Refuse adapter-owned execution/database transport while preserving only
     * the byte-exact historical files recorded above. The return value lets the
     * capsule-level caller prove that every registry row was actually visited;
     * a stale path is debt that silently escaped enforcement and must fail too.
     */
    private static function assertRuntimeExecutionBoundary(
        string $capsule,
        string $path,
        string $source,
        string $slug
    ): ?string {
        $key = self::runtimeDebtKey($capsule, $path, $slug);
        $findings = self::runtimeExecutionFindings($source);
        $legacy = self::legacyRuntimeDebtRows($slug)[$key] ?? null;
        if ($legacy === null) {
            if ($findings === []) {
                return null;
            }
            $finding = array_key_first($findings);
            $line = $finding === null ? 1 : $findings[$finding];
            $relative = substr($path, strlen($capsule) + 1);
            throw new RuntimeException(
                "Adapter package '$slug' introduces engine-owned runtime machinery '$finding' at "
                . "$relative:$line; use the manifest runtime and ProviderSdk instead"
            );
        }

        $digest = hash('sha256', $source);
        if (!hash_equals($legacy['sha256'], $digest)) {
            throw new RuntimeException(
                "Adapter package '$slug' changed frozen legacy runtime debt at $key; migrate it through "
                . $legacy['migration'] . ' and remove the reviewed debt row instead of refreshing its hash'
            );
        }
        if (array_keys($findings) !== $legacy['findings']) {
            throw new RuntimeException(
                "Adapter package '$slug' frozen legacy runtime findings disagree with the reviewed debt row at $key"
            );
        }
        return $key;
    }

    /** @param array<string,true> $visited */
    private static function assertCompleteLegacyRuntimeDebt(string $slug, array $visited): void
    {
        $expected = array_keys(self::legacyRuntimeDebtRows($slug));
        $actual = array_keys($visited);
        sort($actual, SORT_STRING);
        if ($actual !== $expected) {
            $missing = array_values(array_diff($expected, $actual));
            throw new RuntimeException(
                "Adapter package '$slug' did not consume its frozen legacy runtime debt row"
                . ($missing === [] ? '' : ' at ' . implode(', ', $missing))
            );
        }
    }

    /**
     * Validate the in-code review record before selecting only this capsule's
     * rows. This is pure constant validation and does not inspect a sibling
     * capsule, preserving validate()'s one-capsule boundary.
     *
     * @return array<string,array{sha256:string,findings:list<string>,migration:string}>
     */
    private static function legacyRuntimeDebtRows(string $slug): array
    {
        $allowed = [
            'direct-include',
            'direct-process',
            'direct-self-include',
            'raw-database-transport',
            'transaction-control',
            'wp-cli-child-process',
        ];
        $paths = array_keys(self::LEGACY_RUNTIME_EXECUTION_DEBT);
        $sortedPaths = $paths;
        sort($sortedPaths, SORT_STRING);
        if ($paths !== $sortedPaths) {
            throw new RuntimeException('Legacy adapter runtime debt registry paths are not sorted');
        }

        $selected = [];
        foreach (self::LEGACY_RUNTIME_EXECUTION_DEBT as $path => $row) {
            if (preg_match(
                '~^adapter-packages/(?<slug>[a-z][a-z0-9]*(?:-[a-z0-9]+)*)/'
                    . 'package/runtime/(?:interpreters|providers|regenerators)/'
                    . '[a-z0-9][a-z0-9._-]*\.php$~D',
                $path,
                $match
            ) !== 1
                || array_keys($row) !== ['sha256', 'findings', 'migration']
                || preg_match('/^[a-f0-9]{64}$/D', $row['sha256']) !== 1
                || !is_array($row['findings'])
                || !array_is_list($row['findings'])
                || $row['findings'] === []
                || array_filter($row['findings'], 'is_string') !== $row['findings']
                || array_values(array_unique($row['findings'])) !== $row['findings']
                || array_diff($row['findings'], $allowed) !== []
                || !is_string($row['migration'])
                || $row['migration'] === ''
                || strlen($row['migration']) > 160
                || preg_match('/[\x00-\x1f\x7f]/', $row['migration']) === 1) {
                throw new RuntimeException("Legacy adapter runtime debt registry row is malformed: $path");
            }
            $sortedFindings = $row['findings'];
            sort($sortedFindings, SORT_STRING);
            if ($row['findings'] !== $sortedFindings) {
                throw new RuntimeException("Legacy adapter runtime debt findings are not sorted: $path");
            }
            if ($match['slug'] === $slug) {
                $selected[$path] = $row;
            }
        }
        return $selected;
    }

    private static function runtimeDebtKey(string $capsule, string $path, string $slug): string
    {
        $prefix = rtrim($capsule, '/') . '/';
        if (!str_starts_with($path, $prefix)) {
            throw new RuntimeException("Adapter package '$slug' runtime source escaped its capsule");
        }
        return 'adapter-packages/' . $slug . '/' . substr($path, strlen($prefix));
    }

    /** @return array<string,int> finding => first source line */
    private static function runtimeExecutionFindings(string $source): array
    {
        $tokens = token_get_all($source);
        $findings = [];
        $lastLine = 1;
        $namespace = '';
        $namespaceStatement = null;
        $namespaceDepth = 0;
        $depth = 0;
        $imports = ['class' => [], 'function' => []];
        foreach ($tokens as $offset => $token) {
            if (!is_array($token)) {
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}') {
                    $depth--;
                    if ($depth < $namespaceDepth) {
                        $namespace = '';
                        $namespaceDepth = 0;
                        $imports = ['class' => [], 'function' => []];
                    }
                }
                if ($namespaceStatement !== null && in_array($token, [';', '{'], true)) {
                    $namespace = strtolower($namespaceStatement);
                    $namespaceStatement = null;
                    $namespaceDepth = $depth;
                    $imports = ['class' => [], 'function' => []];
                }
                if ($token === '`') {
                    $findings['direct-process'] ??= $lastLine;
                }
                continue;
            }
            [$kind, $bytes, $line] = $token;
            $lastLine = $line;
            if (in_array($kind, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            }
            if ($kind === T_NAMESPACE) {
                $namespaceStatement = '';
                continue;
            }
            if ($namespaceStatement !== null) {
                if (in_array($kind, [T_STRING, T_NAME_QUALIFIED], true)) {
                    $namespaceStatement .= $bytes;
                }
                continue;
            }

            // Trait composition and closure captures are not namespace imports.
            if ($kind === T_USE && $depth === $namespaceDepth) {
                $statement = self::runtimeImportStatementAt($tokens, $offset);
                if ($statement !== null) {
                    foreach (self::runtimeImports($statement) as $import) {
                        $symbol = strtolower(ltrim($import['symbol'], '\\'));
                        $imports[$import['kind']][strtolower($import['alias'])] = $symbol;
                        if ($import['kind'] === 'class'
                            && in_array($symbol, self::RAW_DATABASE_CLASSES, true)) {
                            $findings['raw-database-transport'] ??= $line;
                        }
                        if ($import['kind'] === 'class' && $symbol === 'wp_cli') {
                            $findings['direct-process'] ??= $line;
                        }
                        if ($import['kind'] === 'function'
                            && in_array($symbol, self::RUNTIME_PROCESS_FUNCTIONS, true)) {
                            $findings['direct-process'] ??= $line;
                        }
                        if ($import['kind'] === 'function' && str_starts_with($symbol, 'mysqli_')) {
                            $findings['raw-database-transport'] ??= $line;
                        }
                    }
                }
            }

            if (in_array($kind, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $name = strtolower(ltrim($bytes, '\\'));
                // Bare functions can fall back to PHP globals; classes cannot.
                // Compare complete symbols so Vendor\PDO is not native PDO.
                $function = $kind === T_STRING ? ($imports['function'][$name] ?? $name) : $name;
                $class = $kind === T_NAME_FULLY_QUALIFIED
                    ? $name
                    : ($imports['class'][$name] ?? ($namespace === '' ? $name : $namespace . '\\' . $name));
                if (in_array($function, self::RUNTIME_PROCESS_FUNCTIONS, true)
                    && self::isDirectFunctionCall($tokens, $offset)) {
                    $findings['direct-process'] ??= $line;
                }
                if (str_starts_with($function, 'mysqli_') && self::isDirectFunctionCall($tokens, $offset)) {
                    $findings['raw-database-transport'] ??= $line;
                }
                if (in_array($class, self::RAW_DATABASE_CLASSES, true)
                    && self::isRawDatabaseClassUse($tokens, $offset)) {
                    $findings['raw-database-transport'] ??= $line;
                }
                if ($class === 'wp_cli' && self::isWpCliProcessUse($tokens, $offset)) {
                    $findings['direct-process'] ??= $line;
                }
                if ($class === 'wprism\\wpclichildprocess') {
                    $findings['wp-cli-child-process'] ??= $line;
                }
            }

            if ($kind === T_VARIABLE) {
                $access = self::wpdbMemberAccessAt($tokens, $offset);
                if ($access !== null
                    && ($access['member'] === 'dbh'
                        || ($access['call']
                            && !in_array($access['member'], self::WPDB_NON_TRANSPORT_METHODS, true)))) {
                    $findings['raw-database-transport'] ??= $line;
                }
            }

            if (in_array($kind, [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
                $finding = self::includeTargetsCurrentFile($tokens, $offset)
                    ? 'direct-self-include'
                    : 'direct-include';
                $findings[$finding] ??= $line;
            }

            if (!in_array($kind, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            $fragment = $kind === T_CONSTANT_ENCAPSED_STRING
                ? self::decodePhpStringLiteral($bytes)
                : $bytes;
            foreach (self::dynamicWPrismSymbols($bytes, $kind === T_CONSTANT_ENCAPSED_STRING) as $symbol) {
                if (strcasecmp($symbol, 'WPrism\\WpCliChildProcess') === 0) {
                    $findings['wp-cli-child-process'] ??= $line;
                }
            }
            if (self::fragmentHasRawWpdbTransport($fragment)
                || preg_match(
                    '/(?:\A|[\'\"])\s*(?:DELETE\s+FROM|INSERT(?:\s+IGNORE)?\s+INTO|REPLACE\s+INTO|'
                        . 'TRUNCATE(?:\s+TABLE)?|UPDATE\s+[`A-Za-z_$])/i',
                    $fragment
                ) === 1) {
                $findings['raw-database-transport'] ??= $line;
            }
            if (self::fragmentHasTransactionControl($fragment)) {
                $findings['transaction-control'] ??= $line;
            }
            if (preg_match('/\b(?:require|require_once|include|include_once)\s*\(?\s*__FILE__\b/i', $fragment) === 1
                || ($kind === T_CONSTANT_ENCAPSED_STRING
                    && self::encodedSelfIncludeAt($tokens, $offset, $fragment))) {
                $findings['direct-self-include'] ??= $line;
            }
        }
        ksort($findings, SORT_STRING);
        return $findings;
    }

    private static function fragmentHasTransactionControl(string $fragment): bool
    {
        $xid = "(?:'(?:[^'\r\n]|'')*'|\"(?:[^\"\r\n]|\"\")*\"|0x[0-9a-f]+)";
        $control = '(?:'
            . 'SET\s+(?:SESSION\s+)?TRANSACTION\b[^\'"\r\n;]*'
            . '|SET\s+(?:(?:SESSION|LOCAL)\s+|@@(?:SESSION\.|LOCAL\.)?)?AUTOCOMMIT\s*=\s*(?:0|1|ON|OFF)'
            . '|START\s+TRANSACTION\b[^\'"\r\n;]*'
            . '|BEGIN(?:\s+WORK)?'
            . '|(?:RELEASE\s+)?SAVEPOINT\s+[`A-Za-z0-9_$]+'
            . '|ROLLBACK(?:\s+WORK)?\s+TO(?:\s+SAVEPOINT)?\s+[`A-Za-z0-9_$]+'
            . '|LOCK\s+TABLES\b[^\'"\r\n;]*|UNLOCK\s+TABLES'
            . '|XA\s+(?:START|BEGIN|END|PREPARE|COMMIT|ROLLBACK)\s+' . $xid
                . '(?:\s*,\s*' . $xid . '(?:\s*,\s*\d+)?)?'
                . '(?:\s+(?:JOIN|RESUME|SUSPEND(?:\s+FOR\s+MIGRATE)?|ONE\s+PHASE))?'
            . '|XA\s+RECOVER(?:\s+CONVERT\s+XID)?'
            . '|COMMIT(?:\s+WORK)?(?:\s+AND\s+(?:NO\s+)?CHAIN)?(?:\s+(?:NO\s+)?RELEASE)?'
            . '|ROLLBACK(?:\s+WORK)?(?:\s+AND\s+(?:NO\s+)?CHAIN)?(?:\s+(?:NO\s+)?RELEASE)?'
            . '|SELECT\s+(?:GET_LOCK|RELEASE_LOCK)\s*\([^\'"\r\n;]*\)'
            . ')';
        return preg_match('/\A\s*' . $control . '\s*;?\s*\z/i', $fragment) === 1
            || preg_match('/[\'\"]\s*' . $control . '\s*;?\s*[\'\"]/i', $fragment) === 1;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function isDirectFunctionCall(array $tokens, int $offset): bool
    {
        $next = self::nextSignificantToken($tokens, $offset + 1);
        if ($next === null || $next['token'] !== '(') {
            return false;
        }
        $previous = self::previousSignificantOffset($tokens, $offset - 1);
        if ($previous === null) {
            return true;
        }
        $token = $tokens[$previous];
        return !is_array($token)
            || !in_array($token[0], [T_DOUBLE_COLON, T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function isRawDatabaseClassUse(array $tokens, int $offset): bool
    {
        $previous = self::previousSignificantOffset($tokens, $offset - 1);
        if ($previous !== null
            && is_array($tokens[$previous])
            && in_array($tokens[$previous][0], [T_EXTENDS, T_NEW], true)) {
            return true;
        }
        $cursor = self::afterGroupingParentheses($tokens, $offset + 1, self::groupingParenthesesBefore($tokens, $offset));
        $operator = self::nextSignificantToken($tokens, $cursor);
        $member = $operator === null ? null : self::nextSignificantToken($tokens, $operator['offset'] + 1);
        $open = $member === null ? null : self::nextSignificantToken($tokens, $member['offset'] + 1);
        return $operator !== null && is_array($operator['token'])
            && $operator['token'][0] === T_DOUBLE_COLON
            && $member !== null && is_array($member['token'])
            && $member['token'][0] === T_STRING
            && $open !== null && $open['token'] === '(';
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function isWpCliProcessUse(array $tokens, int $offset): bool
    {
        $wrappers = self::groupingParenthesesBefore($tokens, $offset);
        $cursor = self::afterGroupingParentheses($tokens, $offset + 1, $wrappers);
        $operator = self::nextSignificantToken($tokens, $cursor);
        $method = $operator === null
            ? null
            : self::nextSignificantToken($tokens, $operator['offset'] + 1);
        $open = $method === null
            ? null
            : self::nextSignificantToken($tokens, $method['offset'] + 1);
        if ($operator === null
            || !is_array($operator['token'])
            || $operator['token'][0] !== T_DOUBLE_COLON
            || $method === null
            || !is_array($method['token'])
            || $method['token'][0] !== T_STRING
            || $open === null
            || $open['token'] !== '(') {
            return false;
        }
        if (in_array(strtolower($method['token'][1]), self::WP_CLI_PROCESS_METHODS, true)) {
            return true;
        }
        if (strtolower($method['token'][1]) !== 'get_runner') {
            return false;
        }
        $close = self::matchingPhpDelimiter($tokens, $open['offset'], '(', ')');
        if ($close === null) {
            return false;
        }
        $runnerOperator = self::nextSignificantToken(
            $tokens,
            self::afterGroupingParentheses($tokens, $close + 1, $wrappers)
        );
        $runnerMethod = $runnerOperator === null
            ? null
            : self::nextSignificantToken($tokens, $runnerOperator['offset'] + 1);
        $runnerOpen = $runnerMethod === null
            ? null
            : self::nextSignificantToken($tokens, $runnerMethod['offset'] + 1);
        return $runnerOperator !== null
            && is_array($runnerOperator['token'])
            && in_array($runnerOperator['token'][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            && $runnerMethod !== null
            && is_array($runnerMethod['token'])
            && $runnerMethod['token'][0] === T_STRING
            && in_array(strtolower($runnerMethod['token'][1]), self::WP_CLI_PROCESS_METHODS, true)
            && $runnerOpen !== null
            && $runnerOpen['token'] === '(';
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return array{member:string,call:bool}|null
     */
    private static function wpdbMemberAccessAt(array $tokens, int $offset): ?array
    {
        $token = $tokens[$offset] ?? null;
        if (!is_array($token) || $token[0] !== T_VARIABLE) {
            return null;
        }
        $wrappers = self::groupingParenthesesBefore($tokens, $offset);
        $cursor = $offset + 1;
        if ($token[1] === '$GLOBALS') {
            $open = self::nextSignificantToken($tokens, $cursor);
            $key = $open === null ? null : self::nextSignificantToken($tokens, $open['offset'] + 1);
            $close = $key === null ? null : self::nextSignificantToken($tokens, $key['offset'] + 1);
            if ($open === null || $open['token'] !== '['
                || $key === null || !is_array($key['token'])
                || $key['token'][0] !== T_CONSTANT_ENCAPSED_STRING
                || self::decodePhpStringLiteral($key['token'][1]) !== 'wpdb'
                || $close === null || $close['token'] !== ']') {
                return null;
            }
            $cursor = $close['offset'] + 1;
        } elseif ($token[1] !== '$wpdb') {
            return null;
        }

        for ($depth = 0; $depth < $wrappers; $depth++) {
            $close = self::nextSignificantToken($tokens, $cursor);
            if ($close === null || $close['token'] !== ')') {
                return null;
            }
            $cursor = $close['offset'] + 1;
        }

        $operator = self::nextSignificantToken($tokens, $cursor);
        $member = $operator === null
            ? null
            : self::nextSignificantToken($tokens, $operator['offset'] + 1);
        if ($operator === null || !is_array($operator['token'])
            || !in_array($operator['token'][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            || $member === null || !is_array($member['token'])
            || $member['token'][0] !== T_STRING) {
            return null;
        }
        $next = self::nextSignificantToken($tokens, $member['offset'] + 1);
        return [
            'member' => strtolower($member['token'][1]),
            'call' => $next !== null && $next['token'] === '(',
        ];
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function groupingParenthesesBefore(array $tokens, int $offset): int
    {
        $count = 0;
        $cursor = self::previousSignificantOffset($tokens, $offset - 1);
        while ($cursor !== null && $tokens[$cursor] === '(') {
            $before = self::previousSignificantOffset($tokens, $cursor - 1);
            if ($before !== null) {
                $token = $tokens[$before];
                if ((is_array($token) && in_array($token[0], [
                    T_ARRAY,
                    T_CATCH,
                    T_EMPTY,
                    T_EVAL,
                    T_FOR,
                    T_FOREACH,
                    T_IF,
                    T_ISSET,
                    T_NAME_FULLY_QUALIFIED,
                    T_NAME_QUALIFIED,
                    T_STRING,
                    T_SWITCH,
                    T_VARIABLE,
                    T_WHILE,
                ], true)) || in_array($token, [')', ']'], true)) {
                    break;
                }
            }
            $count++;
            $cursor = $before;
        }
        return $count;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function afterGroupingParentheses(array $tokens, int $cursor, int $limit): int
    {
        for ($depth = 0; $depth < $limit; $depth++) {
            $next = self::nextSignificantToken($tokens, $cursor);
            if ($next === null || $next['token'] !== ')') {
                break;
            }
            $cursor = $next['offset'] + 1;
        }
        return $cursor;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function runtimeImportStatementAt(array $tokens, int $offset): ?string
    {
        $statement = '';
        for ($cursor = $offset + 1, $count = count($tokens); $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];
            if ($token === '(') {
                return null;
            }
            if ($token === ';') {
                return trim($statement);
            }
            if (is_array($token)) {
                $statement .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    ? ' '
                    : $token[1];
            } else {
                $statement .= $token;
            }
        }
        return null;
    }

    /** @return list<array{kind:'class'|'function',symbol:string,alias:string}> */
    private static function runtimeImports(string $statement): array
    {
        $normalized = preg_replace('/\s+/', ' ', trim($statement));
        if (!is_string($normalized) || $normalized === '') {
            return [];
        }
        $kind = 'class';
        if (preg_match('/^(function|const)\s+(.+)$/Di', $normalized, $match) === 1) {
            if (strtolower($match[1]) === 'const') {
                return [];
            }
            $kind = 'function';
            $normalized = $match[2];
        }

        $imports = [];
        foreach (self::splitRuntimeImports($normalized) as $part) {
            $import = $kind === 'function'
                ? self::exactFunctionImport('function ' . $part)
                : self::exactClassImport($part);
            if ($import !== null) {
                $imports[] = ['kind' => $kind, ...$import];
            }
        }
        return $imports;
    }

    /** @return list<string> */
    private static function splitRuntimeImports(string $statement): array
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        for ($offset = 0, $length = strlen($statement); $offset < $length; $offset++) {
            if ($statement[$offset] === '{') {
                $depth++;
            } elseif ($statement[$offset] === '}') {
                $depth--;
            } elseif ($statement[$offset] === ',' && $depth === 0) {
                $parts[] = trim(substr($statement, $start, $offset - $start));
                $start = $offset + 1;
            }
        }
        $parts[] = trim(substr($statement, $start));
        return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
    }

    private static function fragmentHasRawWpdbTransport(string $fragment): bool
    {
        // Encoded child source shares the same receiver parser as live PHP;
        // a regex-only twin missed parenthesized and nullsafe receivers.
        $tokens = token_get_all('<?php ' . $fragment);
        foreach ($tokens as $offset => $token) {
            if (!is_array($token) || $token[0] !== T_VARIABLE) {
                continue;
            }
            $access = self::wpdbMemberAccessAt($tokens, $offset);
            if ($access !== null
                && ($access['member'] === 'dbh'
                    || ($access['call']
                        && !in_array($access['member'], self::WPDB_NON_TRANSPORT_METHODS, true)))) {
                return true;
            }
        }
        return false;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function includeTargetsCurrentFile(array $tokens, int $offset): bool
    {
        $target = self::nextSignificantToken($tokens, $offset + 1);
        if ($target !== null && $target['token'] === '(') {
            $target = self::nextSignificantToken($tokens, $target['offset'] + 1);
        }
        return $target !== null
            && is_array($target['token'])
            && $target['token'][0] === T_FILE;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function encodedSelfIncludeAt(array $tokens, int $offset, string $fragment): bool
    {
        if (!in_array(strtolower(trim($fragment)), ['include', 'include_once', 'require', 'require_once'], true)) {
            return false;
        }
        $dot = self::nextSignificantToken($tokens, $offset + 1);
        $export = $dot === null ? null : self::nextSignificantToken($tokens, $dot['offset'] + 1);
        $open = $export === null ? null : self::nextSignificantToken($tokens, $export['offset'] + 1);
        $file = $open === null ? null : self::nextSignificantToken($tokens, $open['offset'] + 1);
        return $dot !== null
            && $dot['token'] === '.'
            && $export !== null
            && is_array($export['token'])
            && $export['token'][0] === T_STRING
            && strcasecmp($export['token'][1], 'var_export') === 0
            && $open !== null
            && $open['token'] === '('
            && $file !== null
            && is_array($file['token'])
            && $file['token'][0] === T_FILE;
    }

    /** @param array<string,true> $runtimeSymbols */
    private static function assertRuntimeSdk(
        string $capsule,
        string $path,
        string $source,
        string $slug,
        array $runtimeSymbols
    ): void
    {
        $tokens = token_get_all($source);
        $previous = null;
        $namespace = '';
        $namespaceStatement = null;
        $imports = [];
        $useStatement = null;
        $useLine = 0;
        foreach ($tokens as $offset => $token) {
            if (!is_array($token)) {
                if ($namespaceStatement !== null) {
                    if ($token === ';' || $token === '{') {
                        $namespace = $namespaceStatement;
                        $namespaceStatement = null;
                        $imports = [];
                    }
                    continue;
                }
                if ($useStatement !== null) {
                    if ($token === '(' && trim($useStatement) === '') {
                        // Closure capture, not an import declaration.
                        $useStatement = null;
                    } elseif ($token === ';') {
                        self::assertResolvableWPrismImport($capsule, $path, $slug, $useStatement, $useLine);
                        $import = self::exactClassImport($useStatement);
                        if ($import !== null) {
                            $imports[strtolower($import['alias'])] = $import['symbol'];
                        }
                        $useStatement = null;
                    } else {
                        $useStatement .= $token;
                    }
                }
                $previous = $token;
                continue;
            }
            [$kind, $bytes, $line] = $token;
            if ($namespaceStatement !== null) {
                if (in_array($kind, [T_STRING, T_NAME_QUALIFIED], true)) {
                    $namespaceStatement .= $bytes;
                }
                continue;
            }
            if ($kind === T_NAMESPACE) {
                $namespaceStatement = '';
                $previous = T_NAMESPACE;
                continue;
            }
            if ($kind === T_USE) {
                $useStatement = '';
                $useLine = $line;
            } elseif ($useStatement !== null && !in_array($kind, [T_COMMENT, T_DOC_COMMENT], true)) {
                $useStatement .= $bytes;
            }
            if (in_array($kind, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $symbol = ltrim($bytes, '\\');
                if (strncasecmp($symbol, 'WPrism\\', strlen('WPrism\\')) === 0) {
                    self::assertRuntimeSdkSymbol($capsule, $path, $slug, $symbol, $line, $runtimeSymbols);
                }
            } elseif ($kind === T_NAME_RELATIVE && self::isWPrismNamespace($namespace)) {
                $symbol = $namespace . '\\' . substr($bytes, strlen('namespace\\'));
                self::assertRuntimeSdkSymbol($capsule, $path, $slug, $symbol, $line, $runtimeSymbols);
            } elseif ($kind === T_STRING
                && self::isWPrismNamespace($namespace)
                && !in_array(strtolower($bytes), [
                    'array',
                    'bool',
                    'callable',
                    'false',
                    'float',
                    'int',
                    'iterable',
                    'mixed',
                    'never',
                    'null',
                    'object',
                    'parent',
                    'resource',
                    'self',
                    'string',
                    'true',
                    'void',
                ], true)
                && self::isUnqualifiedClassReference($tokens, $offset, $previous)) {
                $symbol = $imports[strtolower($bytes)] ?? ($namespace . '\\' . $bytes);
                if (!self::isWPrismNamespace($symbol)) {
                    continue;
                }
                self::assertRuntimeSdkSymbol(
                    $capsule,
                    $path,
                    $slug,
                    $symbol,
                    $line,
                    $runtimeSymbols
                );
            } elseif (in_array($kind, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                foreach (self::dynamicWPrismSymbols($bytes, $kind === T_CONSTANT_ENCAPSED_STRING) as $symbol) {
                    self::assertRuntimeSdkSymbol($capsule, $path, $slug, $symbol, $line, $runtimeSymbols);
                }
                if ($kind === T_CONSTANT_ENCAPSED_STRING) {
                    foreach (self::concatenatedDynamicWPrismSymbols($tokens, $offset) as $symbol) {
                        self::assertRuntimeSdkSymbol($capsule, $path, $slug, $symbol, $line, $runtimeSymbols);
                    }
                }
            }
            if (!in_array($kind, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $previous = $kind;
            }
        }
        foreach (self::computedDynamicWPrismSymbols($tokens, $capsule, $path, $slug) as $reference) {
            self::assertRuntimeSdkSymbol(
                $capsule,
                $path,
                $slug,
                $reference['symbol'],
                $reference['line'],
                $runtimeSymbols
            );
        }
    }

    /** @return array<string,true> */
    private static function declaredRuntimeSymbols(string $runtimeRoot): array
    {
        if (!is_dir($runtimeRoot)) {
            return [];
        }
        $symbols = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($runtimeRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if (!$entry->isFile() || $entry->isLink() || $entry->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($entry->getPathname());
            if ($source === false) {
                throw new RuntimeException('Cannot read adapter runtime source ' . $entry->getPathname());
            }
            $relative = substr($entry->getPathname(), strlen(rtrim($runtimeRoot, '/')) + 1);
            $kind = explode('/', $relative, 2)[0];
            $ownedNamespace = match ($kind) {
                'interpreters' => 'WPrism\\Interpreters',
                'providers' => 'WPrism\\Providers',
                'regenerators' => 'WPrism\\Regenerators',
                default => throw new RuntimeException(
                    "Adapter runtime symbol is outside an owned runtime kind: $relative"
                ),
            };
            $namespace = '';
            $namespaceStatement = null;
            $declaration = false;
            $previous = null;
            foreach (token_get_all($source) as $token) {
                if (!is_array($token)) {
                    if ($namespaceStatement !== null) {
                        if ($token === ';' || $token === '{') {
                            $namespace = $namespaceStatement;
                            $namespaceStatement = null;
                        }
                        continue;
                    }
                    if ($declaration && !in_array($token, ['&'], true)) {
                        $declaration = false;
                    }
                    continue;
                }
                [$kind, $bytes] = $token;
                if ($namespaceStatement !== null) {
                    if (in_array($kind, [T_STRING, T_NAME_QUALIFIED], true)) {
                        $namespaceStatement .= $bytes;
                    }
                    continue;
                }
                if ($kind === T_NAMESPACE) {
                    $namespaceStatement = '';
                    $previous = T_NAMESPACE;
                    continue;
                }
                if (in_array($kind, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
                    && !in_array($previous, [T_DOUBLE_COLON, T_NEW], true)) {
                    $declaration = true;
                } elseif ($declaration && $kind === T_STRING) {
                    $symbol = ltrim($namespace . '\\' . $bytes, '\\');
                    if ($namespace !== $ownedNamespace) {
                        throw new RuntimeException(
                            "Adapter runtime symbol '$symbol' is outside owned namespace '$ownedNamespace' at $relative"
                        );
                    }
                    $symbols[$symbol] = true;
                    $declaration = false;
                }
                if (!in_array($kind, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $previous = $kind;
                }
            }
        }
        return $symbols;
    }

    /** @return array{alias:string,symbol:string}|null */
    private static function exactClassImport(string $statement): ?array
    {
        $normalized = preg_replace('/\s+/', ' ', trim($statement));
        if (!is_string($normalized)
            || preg_match('/^(?:function|const)\s/i', $normalized) === 1) {
            return null;
        }
        $normalized = ltrim($normalized, '\\');
        if (preg_match(
            '/^([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)'
                . '(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?$/D',
            $normalized,
            $match
        ) !== 1) {
            return null;
        }
        $alias = $match[2] ?? '';
        if ($alias === '') {
            $separator = strrpos($match[1], '\\');
            $alias = $separator === false ? $match[1] : substr($match[1], $separator + 1);
        }
        return [
            'alias' => $alias,
            'symbol' => $match[1],
        ];
    }

    /** @return array{alias:string,symbol:string}|null */
    private static function exactFunctionImport(string $statement): ?array
    {
        $normalized = preg_replace('/\s+/', ' ', trim($statement));
        if (!is_string($normalized)) {
            return null;
        }
        if (preg_match('/^function\s+(.+)$/Di', $normalized, $function) !== 1) {
            return null;
        }
        $normalized = 'function ' . ltrim($function[1], '\\');
        if (preg_match(
            '/^function\s+([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)'
                . '(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?$/Di',
            $normalized,
            $match
        ) !== 1) {
            return null;
        }
        $alias = $match[2] ?? '';
        if ($alias === '') {
            $separator = strrpos($match[1], '\\');
            $alias = $separator === false ? $match[1] : substr($match[1], $separator + 1);
        }
        return [
            'alias' => $alias,
            'symbol' => $match[1],
        ];
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function isUnqualifiedClassReference(array $tokens, int $offset, int|string|null $previous): bool
    {
        if (in_array($previous, [T_NEW, T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS, T_ATTRIBUTE], true)) {
            return true;
        }
        if (in_array($previous, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_FUNCTION, T_CONST], true)) {
            return false;
        }
        for ($next = $offset + 1, $count = count($tokens); $next < $count; $next++) {
            $token = $tokens[$next];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_array($token) && $token[0] === T_DOUBLE_COLON) {
                return true;
            }
            break;
        }
        return self::typeSequenceHasClassTerminator($tokens, $offset)
            || self::typeSequenceHasReturnPrefix($tokens, $offset);
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function typeSequenceHasClassTerminator(array $tokens, int $offset): bool
    {
        for ($next = $offset + 1, $count = count($tokens); $next < $count; $next++) {
            $token = $tokens[$next];
            if (is_array($token)
                && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_array($token) && $token[0] === T_VARIABLE) {
                return true;
            }
            if ((is_array($token)
                    && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true))
                || in_array($token, ['|', '&', '\\', ')'], true)) {
                continue;
            }
            return false;
        }
        return false;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function typeSequenceHasReturnPrefix(array $tokens, int $offset): bool
    {
        for ($previous = $offset - 1; $previous >= 0; $previous--) {
            $token = $tokens[$previous];
            if (is_array($token)
                && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ((is_array($token)
                    && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true))
                || in_array($token, ['?', '|', '&', '\\', '(', ')'], true)) {
                continue;
            }
            if ($token !== ':') {
                return false;
            }
            return self::isFunctionReturnSeparator($tokens, $previous);
        }
        return false;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function isFunctionReturnSeparator(array $tokens, int $colon): bool
    {
        $close = self::previousSignificantOffset($tokens, $colon - 1);
        if ($close === null || $tokens[$close] !== ')') {
            return false;
        }
        $depth = 1;
        for ($cursor = $close - 1; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];
            if ($token === ')') {
                $depth++;
            } elseif ($token === '(' && --$depth === 0) {
                $declaration = self::previousSignificantOffset($tokens, $cursor - 1);
                if ($declaration === null) {
                    return false;
                }
                $candidate = $tokens[$declaration];
                if (is_array($candidate) && in_array($candidate[0], [T_FUNCTION, T_FN], true)) {
                    return true;
                }
                if (!is_array($candidate) || $candidate[0] !== T_STRING) {
                    return false;
                }
                $function = self::previousSignificantOffset($tokens, $declaration - 1);
                while ($function !== null && $tokens[$function] === '&') {
                    $function = self::previousSignificantOffset($tokens, $function - 1);
                }
                return $function !== null
                    && is_array($tokens[$function])
                    && $tokens[$function][0] === T_FUNCTION;
            }
        }
        return false;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function previousSignificantOffset(array $tokens, int $offset): ?int
    {
        for (; $offset >= 0; $offset--) {
            $token = $tokens[$offset];
            if (is_array($token)
                && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $offset;
        }
        return null;
    }

    /** @return list<string> */
    private static function dynamicWPrismSymbols(string $bytes, bool $quoted): array
    {
        if ($quoted && strlen($bytes) >= 2) {
            $quote = $bytes[0];
            $bytes = substr($bytes, 1, -1);
            if ($quote === "'") {
                $bytes = str_replace(['\\\\', "\\'"], ['\\', "'"], $bytes);
            } else {
                $bytes = str_replace('\\\\', '\\', $bytes);
            }
        }
        preg_match_all(
            '/(?<![A-Za-z0-9_\\\\])\\\\?WPrism\\\\[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*/i',
            $bytes,
            $matches
        );
        $symbols = [];
        foreach ($matches[0] ?? [] as $symbol) {
            if (is_string($symbol)) {
                $symbols[] = ltrim($symbol, '\\');
            }
        }
        return array_values(array_unique($symbols));
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens @return list<string> */
    private static function concatenatedDynamicWPrismSymbols(array $tokens, int $offset): array
    {
        $bytes = self::concatenatedPhpStringLiteral($tokens, $offset);
        return $bytes === null ? [] : self::dynamicWPrismSymbols($bytes, false);
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function concatenatedPhpStringLiteral(array $tokens, int $offset): ?string
    {
        $first = $tokens[$offset] ?? null;
        if (!is_array($first) || $first[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $bytes = self::decodePhpStringLiteral($first[1]);
        $cursor = $offset + 1;
        $joined = false;
        while (true) {
            $dot = self::nextSignificantToken($tokens, $cursor);
            if ($dot === null || $dot['token'] !== '.') {
                break;
            }
            $next = self::nextSignificantToken($tokens, $dot['offset'] + 1);
            if ($next === null || !is_array($next['token']) || $next['token'][0] !== T_CONSTANT_ENCAPSED_STRING) {
                break;
            }
            $bytes .= self::decodePhpStringLiteral($next['token'][1]);
            $cursor = $next['offset'] + 1;
            $joined = true;
        }
        return $joined ? $bytes : null;
    }

    /**
     * Resolve constant string assignments and concatenations so splitting a
     * dynamic class name across variables cannot hide an engine dependency.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return list<array{symbol:string,line:int}>
     */
    private static function computedDynamicWPrismSymbols(
        array $tokens,
        string $capsule,
        string $path,
        string $slug
    ): array
    {
        $variables = [];
        $unresolvedVariables = [];
        $objectVariables = [];
        $references = [];
        /**
         * Function-local assignments must neither inherit undeclared outer
         * locals nor leak back into the enclosing scope. Arrow functions are
         * the exception: PHP captures their visible outer variables by value.
         *
         * @var list<array{
         *     end:int,
         *     body_start:int,
         *     variables:array<string,string>,
         *     unresolved:array<string,int>,
         *     objects:array<string,true>
         * }> $scopeStack
         */
        $scopeStack = [];
        foreach ($tokens as $offset => $token) {
            while ($scopeStack !== [] && $offset >= $scopeStack[array_key_last($scopeStack)]['end']) {
                $outer = array_pop($scopeStack);
                $variables = $outer['variables'];
                $unresolvedVariables = $outer['unresolved'];
                $objectVariables = $outer['objects'];
            }
            if (is_array($token) && in_array($token[0], [T_FUNCTION, T_FN], true)) {
                $scope = self::dynamicFunctionScope($tokens, $offset);
                if ($scope === null) {
                    $variables = [];
                    $unresolvedVariables = [];
                    $objectVariables = [];
                    continue;
                }
                $outerVariables = $variables;
                $outerUnresolved = $unresolvedVariables;
                $outerObjects = $objectVariables;
                $scopeStack[] = [
                    'end' => $scope['end'],
                    'body_start' => $scope['body_start'],
                    'variables' => $outerVariables,
                    'unresolved' => $outerUnresolved,
                    'objects' => $outerObjects,
                ];
                $variables = $scope['arrow'] ? $outerVariables : [];
                $unresolvedVariables = $scope['arrow'] ? $outerUnresolved : [];
                $objectVariables = $scope['arrow'] ? $outerObjects : [];
                foreach ($scope['captures'] as $capture) {
                    unset(
                        $variables[$capture['name']],
                        $unresolvedVariables[$capture['name']],
                        $objectVariables[$capture['name']]
                    );
                    if (isset($outerVariables[$capture['name']])) {
                        $variables[$capture['name']] = $outerVariables[$capture['name']];
                    } else {
                        $unresolvedVariables[$capture['name']] = $outerUnresolved[$capture['name']]
                            ?? $capture['line'];
                    }
                    if (isset($outerObjects[$capture['name']])) {
                        $objectVariables[$capture['name']] = true;
                    }
                }
                foreach ($scope['parameters'] as $parameter) {
                    unset($variables[$parameter['name']], $objectVariables[$parameter['name']]);
                    $unresolvedVariables[$parameter['name']] = $parameter['line'];
                }
                foreach ($scope['object_parameters'] as $parameter) {
                    $objectVariables[$parameter] = true;
                }
                continue;
            }
            if ($scopeStack !== []
                && $offset < $scopeStack[array_key_last($scopeStack)]['body_start']) {
                continue;
            }
            if (!is_array($token) || $token[0] !== T_VARIABLE) {
                continue;
            }
            $next = self::nextSignificantToken($tokens, $offset + 1);
            if ($next !== null && $next['token'] === '=') {
                $resolved = self::resolvedPhpStringExpression($tokens, $next['offset'] + 1, $variables);
                if ($resolved === null) {
                    $fragments = self::unresolvedExpressionStringFragments(
                        $tokens,
                        $next['offset'] + 1,
                        $variables
                    );
                    $symbols = self::dynamicWPrismSymbols($fragments, false);
                    foreach ($symbols as $symbol) {
                        $references[$symbol . ':' . $token[2]] = ['symbol' => $symbol, 'line' => $token[2]];
                    }
                    if ($symbols === []
                        && preg_match(
                            '/(?:^|[^A-Za-z0-9_])\\\\?(?i:WPrism)(?:\\\\|[A-Z]|$)/',
                            $fragments
                        ) === 1) {
                        $relative = substr($path, strlen($capsule) + 1);
                        throw new RuntimeException(
                            "Adapter package '$slug' depends on non-SDK WPrism symbol constructed dynamically at "
                            . "$relative:{$token[2]}"
                        );
                    }
                    unset($variables[$token[1]], $objectVariables[$token[1]]);
                    $unresolvedVariables[$token[1]] = $token[2];
                    continue;
                }
                $terminator = self::nextSignificantToken($tokens, $resolved['end'] + 1);
                if ($terminator !== null && !in_array($terminator['token'], [';', ',', ')', ']'], true)) {
                    unset($variables[$token[1]], $objectVariables[$token[1]]);
                    $unresolvedVariables[$token[1]] = $token[2];
                    continue;
                }
                $variables[$token[1]] = $resolved['value'];
                unset($unresolvedVariables[$token[1]], $objectVariables[$token[1]]);
                foreach (self::dynamicWPrismSymbols($resolved['value'], false) as $symbol) {
                    $references[$symbol . ':' . $token[2]] = ['symbol' => $symbol, 'line' => $token[2]];
                }
                continue;
            }

            if (isset($unresolvedVariables[$token[1]])
                && self::isDynamicClassDispatch($tokens, $offset)) {
                $scopeStart = $scopeStack === []
                    ? 0
                    : $scopeStack[array_key_last($scopeStack)]['body_start'];
                if (self::isReflectionInspection($tokens, $offset)
                    && (isset($objectVariables[$token[1]])
                        || self::hasPriorFailingObjectGuard($tokens, $scopeStart, $offset, $token[1]))) {
                    continue;
                }
                if (self::hasEnclosingLiteralClassAllowlist(
                    $tokens,
                    $scopeStart,
                    $offset,
                    $token[1]
                )) {
                    continue;
                }
                $relative = substr($path, strlen($capsule) + 1);
                throw new RuntimeException(
                    "Adapter package '$slug' uses unresolved dynamic class dispatch at "
                    . "$relative:{$token[2]}"
                );
            }

            $resolved = self::resolvedPhpStringExpression($tokens, $offset, $variables);
            if ($resolved === null || !$resolved['joined']) {
                continue;
            }
            foreach (self::dynamicWPrismSymbols($resolved['value'], false) as $symbol) {
                $references[$symbol . ':' . $token[2]] = ['symbol' => $symbol, 'line' => $token[2]];
            }
        }
        return array_values($references);
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return array{
     *     arrow:bool,
     *     body_start:int,
     *     end:int,
     *     parameters:list<array{name:string,line:int}>,
     *     object_parameters:list<string>,
     *     captures:list<array{name:string,line:int}>
     * }|null
     */
    private static function dynamicFunctionScope(array $tokens, int $functionOffset): ?array
    {
        $function = $tokens[$functionOffset] ?? null;
        if (!is_array($function) || !in_array($function[0], [T_FUNCTION, T_FN], true)) {
            return null;
        }
        $arrow = $function[0] === T_FN;
        $parameterOpen = null;
        $named = false;
        for ($cursor = $functionOffset + 1, $count = count($tokens); $cursor < $count; $cursor++) {
            $candidate = $tokens[$cursor];
            if (is_array($candidate)
                && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($candidate === '(') {
                $parameterOpen = $cursor;
                break;
            }
            if (is_array($candidate) && $candidate[0] === T_STRING) {
                $named = true;
            }
        }
        if ($parameterOpen === null) {
            return null;
        }
        $parameterClose = self::matchingPhpDelimiter($tokens, $parameterOpen, '(', ')');
        if ($parameterClose === null) {
            return null;
        }
        $parameters = self::topLevelVariables($tokens, $parameterOpen, $parameterClose);
        $objectParameters = self::objectTypedParameters($tokens, $parameterOpen, $parameterClose);
        $captures = [];
        $afterHeader = $parameterClose + 1;
        $next = self::nextSignificantToken($tokens, $afterHeader);
        if (!$arrow
            && !$named
            && $next !== null
            && is_array($next['token'])
            && $next['token'][0] === T_USE) {
            $captureOpen = self::nextSignificantToken($tokens, $next['offset'] + 1);
            if ($captureOpen === null || $captureOpen['token'] !== '(') {
                return null;
            }
            $captureClose = self::matchingPhpDelimiter($tokens, $captureOpen['offset'], '(', ')');
            if ($captureClose === null) {
                return null;
            }
            $captures = self::topLevelVariables($tokens, $captureOpen['offset'], $captureClose);
            $afterHeader = $captureClose + 1;
        }

        for ($cursor = $afterHeader, $count = count($tokens); $cursor < $count; $cursor++) {
            $candidate = $tokens[$cursor];
            if ($arrow && is_array($candidate) && $candidate[0] === T_DOUBLE_ARROW) {
                $body = self::nextSignificantToken($tokens, $cursor + 1);
                if ($body === null) {
                    return null;
                }
                return [
                    'arrow' => true,
                    'body_start' => $body['offset'],
                    'end' => self::arrowFunctionExpressionEnd($tokens, $body['offset']),
                    'parameters' => $parameters,
                    'object_parameters' => $objectParameters,
                    'captures' => [],
                ];
            }
            if (!$arrow && $candidate === '{') {
                $end = self::matchingPhpDelimiter($tokens, $cursor, '{', '}');
                if ($end === null) {
                    return null;
                }
                return [
                    'arrow' => false,
                    'body_start' => $cursor + 1,
                    'end' => $end,
                    'parameters' => $parameters,
                    'object_parameters' => $objectParameters,
                    'captures' => $captures,
                ];
            }
            if (!$arrow && $candidate === ';') {
                return [
                    'arrow' => false,
                    'body_start' => $cursor,
                    'end' => $cursor,
                    'parameters' => $parameters,
                    'object_parameters' => $objectParameters,
                    'captures' => $captures,
                ];
            }
        }
        return null;
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return list<array{name:string,line:int}>
     */
    private static function topLevelVariables(array $tokens, int $open, int $close): array
    {
        $variables = [];
        $round = 0;
        $square = 0;
        $brace = 0;
        for ($offset = $open + 1; $offset < $close; $offset++) {
            $token = $tokens[$offset];
            if (is_array($token) && $token[0] === T_ATTRIBUTE) {
                $square++;
            } elseif ($token === '(') {
                $round++;
            } elseif ($token === ')') {
                $round--;
            } elseif ($token === '[') {
                $square++;
            } elseif ($token === ']') {
                $square--;
            } elseif ($token === '{') {
                $brace++;
            } elseif ($token === '}') {
                $brace--;
            } elseif ($round === 0
                && $square === 0
                && $brace === 0
                && is_array($token)
                && $token[0] === T_VARIABLE) {
                $variables[$token[1]] = ['name' => $token[1], 'line' => $token[2]];
            }
        }
        return array_values($variables);
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens @return list<string> */
    private static function objectTypedParameters(array $tokens, int $open, int $close): array
    {
        $objects = [];
        $segmentStart = $open + 1;
        $round = 0;
        $square = 0;
        $brace = 0;
        for ($offset = $open + 1; $offset <= $close; $offset++) {
            $token = $tokens[$offset] ?? ')';
            if (is_array($token) && $token[0] === T_ATTRIBUTE) {
                $square++;
            } elseif ($token === '(') {
                $round++;
            } elseif ($token === ')') {
                if ($round > 0) {
                    $round--;
                }
            } elseif ($token === '[') {
                $square++;
            } elseif ($token === ']') {
                $square--;
            } elseif ($token === '{') {
                $brace++;
            } elseif ($token === '}') {
                $brace--;
            }
            if ($offset !== $close && ($token !== ',' || $round !== 0 || $square !== 0 || $brace !== 0)) {
                continue;
            }
            $name = null;
            $object = false;
            for ($cursor = $segmentStart; $cursor < $offset; $cursor++) {
                $candidate = $tokens[$cursor];
                if (is_array($candidate) && $candidate[0] === T_VARIABLE) {
                    $name = $candidate[1];
                }
                if (is_array($candidate)
                    && in_array($candidate[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                    && strtolower(ltrim($candidate[1], '\\')) === 'object') {
                    $object = true;
                }
            }
            if ($name !== null && $object) {
                $objects[$name] = true;
            }
            $segmentStart = $offset + 1;
        }
        return array_keys($objects);
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function matchingPhpDelimiter(
        array $tokens,
        int $openOffset,
        string $open,
        string $close
    ): ?int {
        $depth = 0;
        for ($offset = $openOffset, $count = count($tokens); $offset < $count; $offset++) {
            $token = $tokens[$offset];
            if ($token === $open) {
                $depth++;
            } elseif ($token === $close && --$depth === 0) {
                return $offset;
            }
        }
        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function arrowFunctionExpressionEnd(array $tokens, int $start): int
    {
        $round = 0;
        $square = 0;
        $brace = 0;
        for ($offset = $start, $count = count($tokens); $offset < $count; $offset++) {
            $token = $tokens[$offset];
            if ($token === '(') {
                $round++;
                continue;
            }
            if ($token === '[') {
                $square++;
                continue;
            }
            if ($token === '{') {
                $brace++;
                continue;
            }
            if ($token === ')') {
                if ($round === 0) {
                    return $offset;
                }
                $round--;
                continue;
            }
            if ($token === ']') {
                if ($square === 0) {
                    return $offset;
                }
                $square--;
                continue;
            }
            if ($token === '}') {
                if ($brace === 0) {
                    return $offset;
                }
                $brace--;
                continue;
            }
            if (($token === ',' || $token === ';') && $round === 0 && $square === 0 && $brace === 0) {
                return $offset;
            }
            if (is_array($token) && $token[0] === T_CLOSE_TAG) {
                return $offset;
            }
        }
        return count($tokens);
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function isDynamicClassDispatch(array $tokens, int $offset): bool
    {
        $next = self::nextSignificantToken($tokens, $offset + 1);
        if ($next !== null && is_array($next['token']) && $next['token'][0] === T_DOUBLE_COLON) {
            return true;
        }

        $previousOffset = self::previousSignificantOffset($tokens, $offset - 1);
        if ($previousOffset === null) {
            return false;
        }
        $previous = $tokens[$previousOffset];
        if (is_array($previous) && $previous[0] === T_NEW) {
            return true;
        }
        if ($previous !== '(') {
            return false;
        }
        $calleeOffset = self::previousSignificantOffset($tokens, $previousOffset - 1);
        if ($calleeOffset === null) {
            return false;
        }
        $callee = $tokens[$calleeOffset];
        if (is_array($callee) && $callee[0] === T_NEW) {
            return true;
        }
        if (!is_array($callee)
            || !in_array($callee[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
            return false;
        }
        return in_array(strtolower(ltrim($callee[1], '\\')), [
            'class_exists',
            'enum_exists',
            'interface_exists',
            'is_a',
            'is_subclass_of',
            'reflectionclass',
            'reflectionmethod',
            'reflectionproperty',
            'trait_exists',
        ], true);
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function isReflectionInspection(array $tokens, int $offset): bool
    {
        $open = self::previousSignificantOffset($tokens, $offset - 1);
        if ($open === null || $tokens[$open] !== '(') {
            return false;
        }
        $calleeOffset = self::previousSignificantOffset($tokens, $open - 1);
        if ($calleeOffset === null) {
            return false;
        }
        $callee = $tokens[$calleeOffset];
        if (!is_array($callee)
            || !in_array($callee[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
            return false;
        }
        return in_array(strtolower(ltrim($callee[1], '\\')), [
            'reflectionclass',
            'reflectionmethod',
            'reflectionproperty',
        ], true);
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function hasPriorFailingObjectGuard(
        array $tokens,
        int $scopeStart,
        int $dispatchOffset,
        string $variable
    ): bool {
        $braceDepth = 0;
        for ($offset = $scopeStart; $offset < $dispatchOffset; $offset++) {
            $token = $tokens[$offset];
            if ($token === '{') {
                $braceDepth++;
                continue;
            }
            if ($token === '}') {
                $braceDepth--;
                continue;
            }
            if ($braceDepth !== 0) {
                continue;
            }
            if (!is_array($token) || $token[0] !== T_IF) {
                continue;
            }
            $conditionOpen = self::nextSignificantToken($tokens, $offset + 1);
            if ($conditionOpen === null || $conditionOpen['token'] !== '(') {
                continue;
            }
            $conditionClose = self::matchingPhpDelimiter($tokens, $conditionOpen['offset'], '(', ')');
            if ($conditionClose === null || $conditionClose >= $dispatchOffset) {
                continue;
            }
            $negation = self::nextSignificantToken($tokens, $conditionOpen['offset'] + 1);
            $predicate = $negation === null
                ? null
                : self::nextSignificantToken($tokens, $negation['offset'] + 1);
            $argumentOpen = $predicate === null
                ? null
                : self::nextSignificantToken($tokens, $predicate['offset'] + 1);
            $argument = $argumentOpen === null
                ? null
                : self::nextSignificantToken($tokens, $argumentOpen['offset'] + 1);
            $argumentClose = $argument === null
                ? null
                : self::nextSignificantToken($tokens, $argument['offset'] + 1);
            if ($negation === null || $negation['token'] !== '!'
                || $predicate === null
                || !is_array($predicate['token'])
                || $predicate['token'][0] !== T_STRING
                || strtolower($predicate['token'][1]) !== 'is_object'
                || $argumentOpen === null || $argumentOpen['token'] !== '('
                || $argument === null
                || !is_array($argument['token'])
                || $argument['token'][0] !== T_VARIABLE
                || $argument['token'][1] !== $variable
                || $argumentClose === null || $argumentClose['token'] !== ')') {
                continue;
            }
            $conditionRemainder = self::nextSignificantToken($tokens, $argumentClose['offset'] + 1);
            if ($conditionRemainder === null
                || ($conditionRemainder['offset'] !== $conditionClose
                    && (!is_array($conditionRemainder['token'])
                        || !in_array($conditionRemainder['token'][0], [T_BOOLEAN_OR, T_LOGICAL_OR], true)))) {
                continue;
            }
            $bodyOpen = self::nextSignificantToken($tokens, $conditionClose + 1);
            if ($bodyOpen === null || $bodyOpen['token'] !== '{') {
                continue;
            }
            $bodyClose = self::matchingPhpDelimiter($tokens, $bodyOpen['offset'], '{', '}');
            if ($bodyClose === null || $bodyClose >= $dispatchOffset) {
                continue;
            }
            $firstBody = self::nextSignificantToken($tokens, $bodyOpen['offset'] + 1);
            if ($firstBody !== null
                && is_array($firstBody['token'])
                && $firstBody['token'][0] === T_THROW) {
                return true;
            }
        }
        return false;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function hasEnclosingLiteralClassAllowlist(
        array $tokens,
        int $scopeStart,
        int $dispatchOffset,
        string $variable
    ): bool {
        for ($offset = $scopeStart; $offset < $dispatchOffset; $offset++) {
            $token = $tokens[$offset];
            if (!is_array($token) || $token[0] !== T_IF) {
                continue;
            }
            $conditionOpen = self::nextSignificantToken($tokens, $offset + 1);
            if ($conditionOpen === null || $conditionOpen['token'] !== '(') {
                continue;
            }
            $conditionClose = self::matchingPhpDelimiter($tokens, $conditionOpen['offset'], '(', ')');
            if ($conditionClose === null) {
                continue;
            }
            $callee = self::nextSignificantToken($tokens, $conditionOpen['offset'] + 1);
            $callOpen = $callee === null
                ? null
                : self::nextSignificantToken($tokens, $callee['offset'] + 1);
            $subject = $callOpen === null
                ? null
                : self::nextSignificantToken($tokens, $callOpen['offset'] + 1);
            $firstComma = $subject === null
                ? null
                : self::nextSignificantToken($tokens, $subject['offset'] + 1);
            $listOpen = $firstComma === null
                ? null
                : self::nextSignificantToken($tokens, $firstComma['offset'] + 1);
            if ($callee === null
                || !is_array($callee['token'])
                || $callee['token'][0] !== T_STRING
                || strtolower($callee['token'][1]) !== 'in_array'
                || $callOpen === null || $callOpen['token'] !== '('
                || $subject === null
                || !is_array($subject['token'])
                || $subject['token'][0] !== T_VARIABLE
                || $subject['token'][1] !== $variable
                || $firstComma === null || $firstComma['token'] !== ','
                || $listOpen === null || $listOpen['token'] !== '[') {
                continue;
            }
            $listClose = self::matchingPhpDelimiter($tokens, $listOpen['offset'], '[', ']');
            if ($listClose === null) {
                continue;
            }
            $literalCount = 0;
            $valid = true;
            for ($cursor = $listOpen['offset'] + 1; $cursor < $listClose; $cursor++) {
                $candidate = $tokens[$cursor];
                if (is_array($candidate)
                    && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($candidate === ',') {
                    continue;
                }
                if (!is_array($candidate) || $candidate[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    $valid = false;
                    break;
                }
                $class = self::decodePhpStringLiteral($candidate[1]);
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $class) !== 1
                    || self::dynamicWPrismSymbols($class, false) !== []) {
                    $valid = false;
                    break;
                }
                $literalCount++;
            }
            $secondComma = self::nextSignificantToken($tokens, $listClose + 1);
            $strict = $secondComma === null
                ? null
                : self::nextSignificantToken($tokens, $secondComma['offset'] + 1);
            $callClose = $strict === null
                ? null
                : self::nextSignificantToken($tokens, $strict['offset'] + 1);
            $conditionEnd = $callClose === null
                ? null
                : self::nextSignificantToken($tokens, $callClose['offset'] + 1);
            $bodyOpen = self::nextSignificantToken($tokens, $conditionClose + 1);
            $bodyClose = $bodyOpen !== null && $bodyOpen['token'] === '{'
                ? self::matchingPhpDelimiter($tokens, $bodyOpen['offset'], '{', '}')
                : null;
            if (!$valid || $literalCount === 0
                || $secondComma === null || $secondComma['token'] !== ','
                || $strict === null
                || !is_array($strict['token'])
                || $strict['token'][0] !== T_STRING
                || strtolower($strict['token'][1]) !== 'true'
                || $callClose === null || $callClose['token'] !== ')'
                || $conditionEnd === null || $conditionEnd['offset'] !== $conditionClose
                || $bodyOpen === null || $bodyOpen['token'] !== '{'
                || $bodyClose === null
                || $dispatchOffset <= $bodyOpen['offset']
                || $dispatchOffset >= $bodyClose) {
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * An adapter may construct plugin class names dynamically, but a partially
     * resolvable WPrism namespace is an ABI reference and must fail closed.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @param array<string,string> $variables
     */
    private static function unresolvedExpressionStringFragments(
        array $tokens,
        int $offset,
        array $variables
    ): string {
        $fragments = '';
        $depth = 0;
        for ($count = count($tokens); $offset < $count; $offset++) {
            $token = $tokens[$offset];
            if (!is_array($token)) {
                if (in_array($token, ['(', '[', '{'], true)) {
                    $depth++;
                } elseif (in_array($token, [')', ']', '}'], true)) {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif (in_array($token, [';', ','], true) && $depth === 0) {
                    break;
                }
                continue;
            }
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $fragments .= self::decodePhpStringLiteral($token[1]);
            } elseif ($token[0] === T_VARIABLE && isset($variables[$token[1]])) {
                $fragments .= $variables[$token[1]];
            }
        }
        return $fragments;
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @param array<string,string> $variables
     * @return array{value:string,joined:bool,end:int}|null
     */
    private static function resolvedPhpStringExpression(array $tokens, int $offset, array $variables): ?array
    {
        $operand = self::nextSignificantToken($tokens, $offset);
        if ($operand === null) {
            return null;
        }
        $token = $operand['token'];
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $value = self::decodePhpStringLiteral($token[1]);
        } elseif (is_array($token) && $token[0] === T_VARIABLE && isset($variables[$token[1]])) {
            $value = $variables[$token[1]];
        } else {
            return null;
        }

        $end = $operand['offset'];
        $joined = false;
        while (true) {
            $dot = self::nextSignificantToken($tokens, $end + 1);
            if ($dot === null || $dot['token'] !== '.') {
                break;
            }
            $operand = self::nextSignificantToken($tokens, $dot['offset'] + 1);
            if ($operand === null) {
                return null;
            }
            $token = $operand['token'];
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $value .= self::decodePhpStringLiteral($token[1]);
            } elseif (is_array($token) && $token[0] === T_VARIABLE && isset($variables[$token[1]])) {
                $value .= $variables[$token[1]];
            } else {
                return null;
            }
            $end = $operand['offset'];
            $joined = true;
        }

        return ['value' => $value, 'joined' => $joined, 'end' => $end];
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return array{offset:int,token:array{0:int,1:string,2:int}|string}|null
     */
    private static function nextSignificantToken(array $tokens, int $offset): ?array
    {
        for ($count = count($tokens); $offset < $count; $offset++) {
            $token = $tokens[$offset];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return ['offset' => $offset, 'token' => $token];
        }
        return null;
    }

    private static function decodePhpStringLiteral(string $bytes): string
    {
        if (strlen($bytes) < 2) {
            return $bytes;
        }
        $quote = $bytes[0];
        $inner = substr($bytes, 1, -1);
        if ($quote === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $inner);
        }
        return stripcslashes($inner);
    }

    private static function isWPrismNamespace(string $namespace): bool
    {
        return strcasecmp($namespace, 'WPrism') === 0
            || strncasecmp($namespace, 'WPrism\\', strlen('WPrism\\')) === 0;
    }

    private static function assertResolvableWPrismImport(
        string $capsule,
        string $path,
        string $slug,
        string $statement,
        int $line
    ): void {
        $normalized = preg_replace('/\s+/', '', $statement);
        if (!is_string($normalized)) {
            return;
        }
        foreach (['function', 'const'] as $kind) {
            if (str_starts_with(strtolower($normalized), $kind)) {
                $normalized = substr($normalized, strlen($kind));
                break;
            }
        }
        $normalized = ltrim($normalized, '\\');
        $grouped = str_starts_with($normalized, 'WPrism\\{');
        $rootAlias = $normalized === 'WPrism'
            || preg_match('/^WPrismas[A-Za-z_][A-Za-z0-9_]*$/i', $normalized) === 1;
        if (!$grouped && !$rootAlias) {
            return;
        }
        $relative = substr($path, strlen($capsule) + 1);
        throw new RuntimeException(
            "Adapter package '$slug' uses an unresolved grouped or root WPrism import at $relative:$line; "
            . 'runtime dependencies must name exact symbols from ' . self::RUNTIME_SDK_FORMAT
        );
    }

    /** @param array<string,true> $runtimeSymbols */
    private static function assertRuntimeSdkSymbol(
        string $capsule,
        string $path,
        string $slug,
        string $symbol,
        int $line,
        array $runtimeSymbols
    ): void {
        if (in_array($symbol, self::RUNTIME_SDK_SYMBOLS, true)
            || (strcasecmp($symbol, 'WPrism\\WpCliChildProcess') === 0
                && self::isGrandfatheredRuntimeFinding(
                    $capsule,
                    $path,
                    $slug,
                    'wp-cli-child-process'
                ))
            || (isset($runtimeSymbols[$symbol]) && substr_count($symbol, '\\') >= 2)) {
            return;
        }
        $relative = substr($path, strlen($capsule) + 1);
        throw new RuntimeException(
            "Adapter package '$slug' depends on non-SDK WPrism symbol '$symbol' at $relative:$line; "
            . 'allowed surface is ' . self::RUNTIME_SDK_FORMAT
        );
    }

    private static function isGrandfatheredRuntimeFinding(
        string $capsule,
        string $path,
        string $slug,
        string $finding
    ): bool {
        $key = self::runtimeDebtKey($capsule, $path, $slug);
        $row = self::legacyRuntimeDebtRows($slug)[$key] ?? null;
        if ($row === null || !in_array($finding, $row['findings'], true)) {
            return false;
        }
        $digest = hash_file('sha256', $path);
        return is_string($digest) && hash_equals($row['sha256'], $digest);
    }

    private static function assertNoGlobalLibrarySelection(
        string $capsule,
        string $path,
        string $source,
        bool $tests,
        string $slug
    ): void {
        foreach (preg_split('/\R/', $source) ?: [] as $offset => $line) {
            $normalized = preg_replace(
                '/WPRISM_[\'".\s]*MANIFESTS_DIR/',
                'WPRISM_MANIFESTS_DIR',
                $line
            );
            if (!is_string($normalized) || !str_contains($normalized, 'WPRISM_MANIFESTS_DIR')) {
                continue;
            }
            if ($tests
                && substr_count($normalized, 'WPRISM_MANIFESTS_DIR') === 1
                && self::isNegativeTextAssertion($normalized, 'WPRISM_MANIFESTS_DIR')) {
                continue;
            }
            $relative = substr($path, strlen($capsule) + 1);
            throw new RuntimeException(
                "Adapter package '$slug' selects a manifest library through WPRISM_MANIFESTS_DIR at "
                . "$relative:" . ($offset + 1)
            );
        }
    }

    private static function assertRelativeAgentDependencies(
        string $root,
        string $agentSource,
        string $capsule,
        string $path,
        string $source,
        bool $tests,
        string $slug
    ): void {
        foreach (preg_split('/\R/', $source) ?: [] as $offset => $line) {
            preg_match_all(
                '~(?<relative>(?:\.\./)+agent/src)(?:/|(?=[\'\"]))~',
                $line,
                $matches
            );
            foreach ($matches['relative'] ?? [] as $relativeAgent) {
                if ($tests
                    && substr_count($line, $relativeAgent) === 1
                    && self::isNegativeTextAssertion($line, $relativeAgent)) {
                    continue;
                }
                $resolved = realpath(dirname($path) . '/' . $relativeAgent);
                if ($resolved === $agentSource) {
                    continue;
                }
                $relative = substr($path, strlen($capsule) + 1);
                $target = $resolved === false ? dirname($path) . '/' . $relativeAgent : $resolved;
                throw new RuntimeException(
                    "Adapter package '$slug' has a legacy relative agent dependency at $relative:"
                    . ($offset + 1) . "; '$relativeAgent' does not resolve to $root/agent/src (resolved $target)"
                );
            }
        }
    }

    private static function isNegativeTextAssertion(string $line, string $needle): bool
    {
        $quotedNeedle = preg_quote($needle, '~');
        return preg_match(
            '~!\s*str_(?:contains|starts_with|ends_with)\s*\([^;]*[\'\"][^\'\"]*' . $quotedNeedle
                . '|str_(?:contains|starts_with|ends_with)\s*\([^;]*[\'\"][^\'\"]*' . $quotedNeedle
                . '[^;]*\)\s*===\s*false'
                . '|(?:^|[;&|]\s*)!\s*(?:grep|rg)\b[^;]*' . $quotedNeedle . '~D',
            $line
        ) === 1;
    }

    /** @param list<string> $checks */
    private static function syntax(string $capsule, array &$checks): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($capsule, \FilesystemIterator::SKIP_DOTS)
        );
        $php = 0;
        $shell = 0;
        $json = 0;
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isLink() || !$entry->isFile() || realpath($path) !== $path) {
                throw new RuntimeException("Adapter capsule contains a non-ordinary file: $path");
            }
            if (str_ends_with($path, '.php')) {
                self::command([PHP_BINARY, '-l', $path], "PHP syntax failed for $path");
                $php++;
            } elseif (str_ends_with($path, '.sh')) {
                self::command(['bash', '-n', $path], "shell syntax failed for $path");
                $shell++;
            } elseif (str_ends_with($path, '.json')) {
                Canon::decode(Canon::read_file($path));
                $json++;
            }
        }
        $checks[] = "php-syntax:$php";
        $checks[] = "shell-syntax:$shell";
        $checks[] = "json-syntax:$json";
    }

    /** @param array<string,mixed> $manifest @param list<string> $checks */
    private static function premiseEvidence(
        string $root,
        string $capsule,
        string $slug,
        array $manifest,
        array &$checks
    ): void
    {
        $testsRoot = $capsule . '/tests';
        $needsContract = false;
        if (is_dir($testsRoot)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($testsRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $entry) {
                if (!$entry->isFile() || $entry->isLink() || $entry->getExtension() !== 'sh') {
                    continue;
                }
                $source = file_get_contents($entry->getPathname());
                if ($source === false) {
                    throw new RuntimeException("Adapter package '$slug' cannot read premise source " . $entry->getPathname());
                }
                if (preg_match(
                    '/\b(?:require_(?:observed_nonempty|wprism_answered|fixture_ids|fixture_values)|capture_wprism_json_(?:success|refusal))\b/',
                    ActiveShellSource::source($source)
                ) === 1) {
                    $needsContract = true;
                }
            }
        }

        $contract = $capsule . '/evidence/' . self::PREMISE_EVIDENCE;
        if ($needsContract && !is_file($contract)) {
            throw new RuntimeException(
                "Adapter package '$slug' uses target-observation premise helpers but owns no " . self::PREMISE_EVIDENCE
            );
        }
        $rows = is_file($contract) ? self::validatePremiseContract($root, $capsule, $slug, $contract) : 0;

        $matrix = $testsRoot . '/certify/version-matrix.sh';
        if (is_file($matrix)) {
            $plugin = $manifest['plugin'] ?? null;
            if (!is_string($plugin)
                || preg_match('~^([a-z0-9][a-z0-9.-]*)/[A-Za-z0-9._-]+\.php$~D', $plugin, $match) !== 1) {
                throw new RuntimeException(
                    "Adapter package '$slug' version matrix requires one canonical manifest plugin subject"
                );
            }
            self::validateVersionMatrixPremises($capsule, $slug, $matrix, $match[1]);
            $checks[] = 'version-matrix-premises';
        }
        $checks[] = "premise-evidence:$rows";
    }

    private static function validatePremiseContract(
        string $root,
        string $capsule,
        string $slug,
        string $contract
    ): int {
        if (is_link($contract) || realpath($contract) !== $contract) {
            throw new RuntimeException("Adapter package '$slug' premise contract is not an ordinary file: $contract");
        }
        $bytes = file_get_contents($contract);
        if ($bytes === false) {
            throw new RuntimeException("Adapter package '$slug' cannot read premise contract: $contract");
        }
        if (str_contains($bytes, "\r")) {
            throw new RuntimeException("Adapter package '$slug' premise contract contains non-canonical CR bytes");
        }

        $lines = explode("\n", $bytes);
        if (($lines[0] ?? null) !== '# format wprism-target-observation-premises/v1') {
            throw new RuntimeException(
                "Adapter package '$slug' premise contract is missing format wprism-target-observation-premises/v1"
            );
        }
        if (preg_match(
            '/^# expected observations=(0|[1-9][0-9]*) fixtures=(0|[1-9][0-9]*)$/D',
            $lines[1] ?? '',
            $expected
        ) !== 1) {
            throw new RuntimeException(
                "Adapter package '$slug' premise contract has no canonical expected-count ratchet"
            );
        }

        $seen = [];
        $rows = 0;
        $observations = 0;
        $fixtures = 0;
        foreach ($lines as $offset => $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $fields = explode("\t", $line);
            if (count($fields) !== 3) {
                throw new RuntimeException(
                    "Adapter package '$slug' premise contract line " . ($offset + 1)
                    . ' must contain exactly three tab-separated fields'
                );
            }
            [$kind, $relative, $needle] = $fields;
            if (!in_array($kind, ['observation', 'fixture'], true)) {
                throw new RuntimeException(
                    "Adapter package '$slug' premise contract line " . ($offset + 1)
                    . " has unknown premise kind '$kind'"
                );
            }
            if ($kind === 'observation') {
                $observations++;
            } else {
                $fixtures++;
            }
            if ($relative === '' || $needle === '' || isset($seen[$line])) {
                throw new RuntimeException(
                    "Adapter package '$slug' premise contract line " . ($offset + 1)
                    . ' is empty or duplicated'
                );
            }
            $seen[$line] = true;

            $source = null;
            if (preg_match('~^tests/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\.sh$~D', $relative) === 1) {
                $source = $capsule . '/' . $relative;
            } elseif (preg_match(
                '~^@repo/sandbox/tests/certify/[A-Za-z0-9_.-]+\.sh$~D',
                $relative
            ) === 1) {
                $source = $root . '/' . substr($relative, strlen('@repo/'));
            }
            if ($source === null
                || str_contains($relative, '..')
                || !is_file($source)
                || is_link($source)
                || realpath($source) !== $source) {
                throw new RuntimeException(
                    "Adapter package '$slug' premise contract line " . ($offset + 1)
                    . " names invalid premise source '$relative'"
                );
            }
            $sourceBytes = file_get_contents($source);
            $statement = $sourceBytes === false ? null : self::activePremiseStatement($sourceBytes, $needle);
            if ($statement === null) {
                throw new RuntimeException(
                    "Adapter package '$slug' premise contract line " . ($offset + 1)
                    . " source is missing active premise '$needle'"
                );
            }
            if (str_starts_with($relative, '@repo/')) {
                self::assertCertificationPremiseParticipant(
                    $slug,
                    $relative,
                    $sourceBytes,
                    $statement['comment'],
                    $offset + 1
                );
            }
            $rows++;
        }
        if ($rows === 0) {
            throw new RuntimeException("Adapter package '$slug' premise contract contains no premise rows");
        }
        if ($expected[1] !== (string) $observations || $expected[2] !== (string) $fixtures) {
            throw new RuntimeException(
                "Adapter package '$slug' premise contract expected-count mismatch ("
                . $expected[1] . '/' . $expected[2] . " declared, $observations/$fixtures found)"
            );
        }
        return $rows;
    }

    private static function assertCertificationPremiseParticipant(
        string $slug,
        string $relative,
        string $source,
        string $premiseComment,
        int $line
    ): void {
        if (!in_array($slug, ActiveShellSource::manifestParticipants($source), true)) {
            throw new RuntimeException(
                "Adapter package '$slug' premise contract line $line cites certification source '$relative' "
                . 'without an active canonical manifest participant declaration'
            );
        }
        if (preg_match(
            '/^wprism-premise-owner: ([a-z][a-z0-9]*(?:-[a-z0-9]+)*)$/D',
            trim($premiseComment),
            $owner
        ) !== 1 || ($owner[1] ?? null) !== $slug) {
            $bound = is_string($owner[1] ?? null) ? "'$owner[1]'" : 'no participant';
            throw new RuntimeException(
                "Adapter package '$slug' premise contract line $line cites certification premise in '$relative' "
                . "bound to $bound; @repo premises require '# wprism-premise-owner: $slug' on the active assertion"
            );
        }
    }

    private static function validateVersionMatrixPremises(
        string $capsule,
        string $slug,
        string $matrix,
        string $pluginSlug
    ): void
    {
        if (is_link($matrix) || realpath($matrix) !== $matrix) {
            throw new RuntimeException("Adapter package '$slug' version matrix is not an ordinary file");
        }
        $source = file_get_contents($matrix);
        if ($source === false) {
            throw new RuntimeException("Adapter package '$slug' cannot read its version matrix");
        }
        $activeSource = ActiveShellSource::source($source);
        $relative = substr($matrix, strlen($capsule) + 1);
        preg_match_all('/^[ \t]*(?:export[ \t]+)?VMATRIX_PLUGIN_SLUG[ \t]*=/m', $activeSource, $assignments);
        preg_match_all(
            '/^VMATRIX_PLUGIN_SLUG=([a-z][a-z0-9]*(?:-[a-z0-9]+)*)$/m',
            $activeSource,
            $canonicalAssignments
        );
        if (count($assignments[0]) !== 1
            || count($canonicalAssignments[0]) !== 1
            || ($canonicalAssignments[1][0] ?? null) !== $pluginSlug) {
            throw new RuntimeException(
                "Adapter package '$slug' version matrix $relative must declare exactly one canonical "
                . "VMATRIX_PLUGIN_SLUG=$pluginSlug"
            );
        }
        preg_match_all(
            '/^[ \t]*(?:function[ \t]+version_matrix_workflow|version_matrix_workflow[ \t]*\(\))[ \t]*(?:\{)?/m',
            $activeSource,
            $workflowDeclarations
        );
        preg_match_all('/^version_matrix_workflow\(\) \{$/m', $activeSource, $canonicalWorkflows);
        if (count($workflowDeclarations[0]) !== 1 || count($canonicalWorkflows[0]) !== 1) {
            throw new RuntimeException(
                "Adapter package '$slug' version matrix $relative must declare exactly one canonical "
                . 'version_matrix_workflow()'
            );
        }
        foreach (['INSTALLED_2', 'TEC_INSTALLED_2', 'NEGATIVE_INSTALLED'] as $variable) {
            preg_match_all('/^[ \t]*' . $variable . '=/m', $activeSource, $assignments);
            preg_match_all(
                '/^[ \t]*require_fixture_values[ \t]+' . $variable . '(?:[ \t]|$)/m',
                $activeSource,
                $premises
            );
            if (count($assignments[0]) !== count($premises[0])) {
                throw new RuntimeException(
                    "Adapter package '$slug' version matrix $relative has $variable assignment/premise mismatch ("
                    . count($assignments[0]) . '/' . count($premises[0]) . ')'
                );
            }
        }
    }

    /** @return array{code:string,comment:string,line:int}|null */
    private static function activePremiseStatement(string $source, string $needle): ?array
    {
        return ActiveShellSource::statement($source, $needle);
    }

    /**
     * @param array<string,mixed> $manifest
     * @param list<string> $checks
     * @return list<string>
     */
    private static function evidence(
        string $root,
        string $capsule,
        string $slug,
        array $manifest,
        array $disposition,
        array &$checks
    ): array {
        $tests = $disposition['evidence']['tests'] ?? [];
        if (!is_array($tests) || !array_is_list($tests)) {
            throw new RuntimeException("Adapter package '$slug' disposition evidence tests are malformed");
        }

        $available = array_fill_keys(self::discoverableEvidence($root, $slug), true);

        $normalized = [];
        foreach ($tests as $test) {
            if (!is_string($test) || $test === '' || !isset($available[$test])) {
                throw new RuntimeException("Adapter package '$slug' cites undiscoverable evidence test " . var_export($test, true));
            }
            $normalized[] = $test;
        }
        if (($disposition['status'] ?? null) === 'certified' && $normalized === []) {
            throw new RuntimeException("Certified adapter package '$slug' has no discoverable evidence tests");
        }
        if (($manifest['name'] ?? null) !== $slug) {
            throw new RuntimeException("Adapter package '$slug' manifest identity changed during evidence validation");
        }
        $checks[] = 'evidence-wiring:' . count($normalized);
        return $normalized;
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $disposition
     * @param list<string> $checks
     */
    private static function packageEvidence(
        string $root,
        string $slug,
        array $manifest,
        array $disposition,
        array &$checks
    ): void {
        if (($disposition['status'] ?? null) === 'excluded') {
            $checks[] = 'package-evidence:excluded';
            return;
        }

        $artifacts = ArtifactLibrary::loadPackage($root, $slug);
        $plugin = $manifest['plugin'] ?? null;
        if (is_string($plugin)) {
            $subject = explode('/', $plugin, 2)[0];
            if (array_keys($artifacts['plugins']) !== [$subject] || $artifacts['themes'] !== []) {
                throw new RuntimeException(
                    "Adapter package '$slug' artifact evidence must own exactly its manifest plugin '$subject'"
                );
            }
            if (($disposition['status'] ?? null) === 'certified') {
                self::certifiedArtifactBoundary($root, $slug, $manifest, $artifacts['plugins'][$subject]);
            }
        }
        $readiness = AdapterProductionReadiness::record(
            $root,
            $slug,
            static function (string $repo, string $adapter, string $evidence): void {
                self::assertReadinessEvidenceOwnership($repo, $adapter, $evidence);
            }
        );
        // A complete ledger can truthfully say unready. That is valid for a
        // non-authorizing preview, never for a certified product claim.
        if (($disposition['status'] ?? null) === 'certified' && $readiness['readiness'] !== 'ready') {
            throw new RuntimeException(
                "Certified adapter package '$slug' must have ready production-readiness evidence"
            );
        }
        $checks[] = 'artifact-evidence';
        $checks[] = 'production-readiness';
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,array<string,string>> $versions
     */
    private static function certifiedArtifactBoundary(
        string $root,
        string $slug,
        array $manifest,
        array $versions
    ): void {
        $matrix = $root . '/adapter-packages/' . $slug . '/tests/certify/version-matrix.sh';
        if (!is_file($matrix) || is_link($matrix) || realpath($matrix) !== $matrix) {
            throw new RuntimeException(
                "Certified adapter package '$slug' must own tests/certify/version-matrix.sh"
            );
        }
        $range = $manifest['version_range'] ?? null;
        if (!is_array($range)
            || !is_string($range['min'] ?? null)
            || !is_string($range['max'] ?? null)) {
            throw new RuntimeException(
                "Certified adapter package '$slug' must declare one plugin version_range"
            );
        }
        $source = file_get_contents($matrix);
        if ($source === false) {
            throw new RuntimeException("Certified adapter package '$slug' cannot read its version matrix");
        }
        $active = self::certifiedArtifactEvidenceSource($root, $slug, $matrix, $source);
        $certified = 0;
        $refusals = 0;
        foreach ($versions as $version => $entry) {
            $inside = version_compare($version, $range['min'], '>=')
                && version_compare($version, $range['max'], '<');
            $role = $entry['role'] ?? null;
            if ($role === 'certified-boundary') {
                $certified++;
                if (!$inside) {
                    throw new RuntimeException(
                        "Adapter package '$slug' pins certified boundary $version outside its declared version_range"
                    );
                }
            } elseif ($role === 'refusal-fixture') {
                $refusals++;
                if ($inside) {
                    throw new RuntimeException(
                        "Adapter package '$slug' pins refusal fixture $version inside its declared version_range"
                    );
                }
            }
            $quoted = preg_quote($version, '/');
            if (preg_match('/(?<![A-Za-z0-9._-])' . $quoted . '(?![A-Za-z0-9._-])/', $active) !== 1) {
                throw new RuntimeException(
                    "Adapter package '$slug' artifact pin $version is absent from active certified workflow source"
                );
            }
        }
        if ($certified === 0 || $refusals === 0) {
            throw new RuntimeException(
                "Certified adapter package '$slug' needs at least one in-range certified boundary and one out-of-range refusal fixture"
            );
        }
    }

    private static function certifiedArtifactEvidenceSource(
        string $root,
        string $slug,
        string $matrix,
        string $matrixSource
    ): string {
        $sources = [ActiveShellSource::source($matrixSource)];
        $tests = $root . '/adapter-packages/' . $slug . '/tests';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tests, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if (!$entry->isFile() || $entry->isLink() || $entry->getPathname() === $matrix) {
                continue;
            }
            $extension = strtolower($entry->getExtension());
            if (!in_array($extension, ['php', 'sh'], true)) {
                continue;
            }
            $source = file_get_contents($entry->getPathname());
            if ($source === false) {
                throw new RuntimeException(
                    "Certified adapter package '$slug' cannot read artifact evidence source " . $entry->getPathname()
                );
            }
            $sources[] = $extension === 'sh' ? ActiveShellSource::source($source) : self::activePhpSource($source);
        }
        $capsule = $root . '/adapter-packages/' . $slug;
        foreach (self::externalEvidence($root, $capsule, $slug) as $relative) {
            $path = $root . '/' . $relative;
            $source = file_get_contents($path);
            if ($source === false) {
                throw new RuntimeException(
                    "Certified adapter package '$slug' cannot read external artifact evidence source $path"
                );
            }
            $sources[] = str_ends_with($path, '.sh')
                ? ActiveShellSource::source($source)
                : self::activePhpSource($source);
        }

        return implode("\n", $sources);
    }

    /** Comments are documentation, never executable evidence that an artifact pin is exercised. */
    private static function activePhpSource(string $source): string
    {
        $active = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $active .= $token[1];
                continue;
            }
            $active .= $token;
        }
        return $active;
    }

    private static function assertReadinessEvidenceOwnership(
        string $root,
        string $slug,
        string $evidence
    ): void {
        $path = $root . '/' . $evidence;
        if (realpath($path) !== $path) {
            throw new RuntimeException(
                "Adapter package '$slug' readiness evidence escapes its declared ownership path: $evidence"
            );
        }

        $owned = "adapter-packages/$slug/";
        if (str_starts_with($evidence, $owned)) {
            $relative = substr($evidence, strlen($owned));
            if (in_array($relative, [
                'package/manifest.json',
                'package/disposition.json',
                'evidence/artifacts.lock.json',
            ], true)
                || preg_match(
                    '~^package/runtime/(?:interpreters|providers|regenerators)/[a-z0-9][a-z0-9._-]*\.php$~D',
                    $relative
                ) === 1
                || preg_match('~^fixtures/(?:[a-z0-9][a-z0-9._-]*/)*[a-z0-9][a-z0-9._-]*\.(?:json|php|sh)$~D', $relative) === 1
                || self::isOwnedReadinessTest($relative)) {
                return;
            }
            throw new RuntimeException(
                "Adapter package '$slug' readiness evidence is not a recognized owned evidence asset: $evidence"
            );
        }
        if (str_starts_with($evidence, 'adapter-packages/')) {
            throw new RuntimeException(
                "Adapter package '$slug' readiness evidence cites a sibling adapter capsule: $evidence"
            );
        }
        if (str_starts_with($evidence, 'integration-scenarios/')) {
            self::assertScenarioEvidenceOwnership($root, $slug, $evidence);
            return;
        }
        if (in_array($evidence, self::SHARED_EVIDENCE_PATHS, true)) {
            return;
        }
        foreach (self::SHARED_EVIDENCE_PREFIXES as $prefix) {
            if (str_starts_with($evidence, $prefix)) {
                return;
            }
        }

        throw new RuntimeException(
            "Adapter package '$slug' readiness evidence is outside the closed shared evidence roots: $evidence"
        );
    }

    private static function isOwnedReadinessTest(string $relative): bool
    {
        if (in_array($relative, [
            'tests/certify/version-matrix.sh',
            'tests/conformance/entry.json',
            'tests/conformance/seed.sh',
            'tests/conformance/capture-check.sh',
            'tests/conformance/postdeploy.sh',
            'tests/conformance/postapply.sh',
            'tests/conformance/check.sh',
        ], true)) {
            return true;
        }
        if (preg_match(
            '~^tests/(?<class>offline|live|certify|spike)/(?:[a-z0-9][a-z0-9._-]*/)*'
                . '(?<name>[a-z0-9][a-z0-9._-]*)\.(?:php|sh)$~D',
            $relative,
            $match
        ) !== 1) {
            return false;
        }
        $prefix = $match['class'] === 'spike'
            ? 'spike_'
            : ($match['class'] === 'certify' ? '(?:certify|regress)_' : 'regress_');
        return preg_match('/^' . $prefix . '[a-z0-9][a-z0-9._-]*$/D', $match['name']) === 1;
    }

    private static function assertScenarioEvidenceOwnership(string $root, string $slug, string $evidence): void
    {
        if (preg_match(
            '~^integration-scenarios/([a-z][a-z0-9]*(?:-[a-z0-9]+)*)/tests/(offline|live|certify|spike)/([^/]+)\\.(php|sh)$~D',
            $evidence,
            $match
        ) !== 1) {
            throw new RuntimeException(
                "Adapter package '$slug' readiness evidence is not a recognized integration scenario gate: $evidence"
            );
        }

        $scenario = $match[1];
        $class = $match[2];
        $filename = $match[3] . '.' . $match[4];
        $prefix = $class === 'spike' ? 'spike' : ($class === 'certify' ? '(?:certify|regress)' : 'regress');
        if (preg_match('/^' . $prefix . '_[a-z0-9][a-z0-9._-]*\\.(?:php|sh)$/D', $filename) !== 1) {
            throw new RuntimeException(
                "Adapter package '$slug' readiness evidence is not a recognized integration scenario gate: $evidence"
            );
        }
        $directory = $root . '/integration-scenarios/' . $scenario;
        $recordPath = $directory . '/scenario.json';
        if (realpath($directory) !== $directory
            || !is_dir($directory)
            || is_link($directory)
            || realpath($recordPath) !== $recordPath
            || !is_file($recordPath)
            || is_link($recordPath)) {
            throw new RuntimeException(
                "Adapter package '$slug' readiness evidence scenario has no ordinary participant record: $scenario"
            );
        }

        $record = Canon::decode(Canon::read_file($recordPath));
        if (!is_array($record)
            || array_is_list($record)
            || array_keys($record) !== ['format', 'participants']
            || ($record['format'] ?? null) !== self::INTEGRATION_SCENARIO_FORMAT
            || !is_array($record['participants'] ?? null)
            || !array_is_list($record['participants'])
            || count($record['participants']) < 2) {
            throw new RuntimeException(
                "Adapter package '$slug' readiness evidence scenario '$scenario' has a malformed participant record"
            );
        }

        $participants = [];
        foreach ($record['participants'] as $participant) {
            if (!is_string($participant)
                || preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $participant) !== 1
                || isset($participants[$participant])) {
                throw new RuntimeException(
                    "Adapter package '$slug' readiness evidence scenario '$scenario' has invalid participants"
                );
            }
            $participants[$participant] = true;
        }
        $ordered = array_keys($participants);
        $sorted = $ordered;
        sort($sorted, SORT_STRING);
        if ($ordered !== $sorted) {
            throw new RuntimeException(
                "Adapter package '$slug' readiness evidence scenario '$scenario' participants must be sorted"
            );
        }
        if (!isset($participants[$slug])) {
            throw new RuntimeException(
                "Adapter package '$slug' readiness evidence cites undeclared integration scenario '$scenario'"
            );
        }
    }

    /** @return array<string,string> */
    private static function externalEvidence(string $root, string $capsule, string $slug): array
    {
        $path = $capsule . '/evidence/external-tests.json';
        if (!file_exists($path)) {
            return [];
        }
        $document = Canon::decode(Canon::read_file($path));
        if (!is_array($document)
            || ($document['format'] ?? null) !== self::EXTERNAL_EVIDENCE_FORMAT
            || !is_array($document['tests'] ?? null)
            || array_keys($document) !== ['format', 'tests']) {
            throw new RuntimeException("Adapter external evidence document is malformed: $path");
        }
        $tests = [];
        foreach ($document['tests'] as $test => $relative) {
            if (!is_string($test)
                || preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $test) !== 1
                || !is_string($relative)
                || !str_starts_with($relative, 'integration-scenarios/')
                || str_contains($relative, '..')
                || realpath($root . '/' . $relative) !== $root . '/' . $relative
                || !is_file($root . '/' . $relative)) {
                throw new RuntimeException("Adapter external evidence entry is invalid: $path");
            }
            self::assertScenarioEvidenceOwnership($root, $slug, $relative);
            $extension = pathinfo($relative, PATHINFO_EXTENSION);
            $derived = str_replace('_', '-', basename($relative, '.' . $extension));
            if ($test !== $derived
                || in_array($test, ['conformance-' . $slug, 'exact-artifact-version-matrix'], true)) {
                throw new RuntimeException(
                    "Adapter external evidence key '$test' must equal its scenario gate basename '$derived'"
                );
            }
            $tests[$test] = $relative;
        }
        ksort($tests, SORT_STRING);
        return $tests;
    }

    /** @param non-empty-list<string> $command */
    private static function command(array $command, string $message): void
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException("$message: process could not start");
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            $detail = trim($stderr !== '' ? $stderr : $stdout);
            throw new RuntimeException($message . ($detail === '' ? '' : ": $detail"));
        }
    }

    private static function defineVersions(string $root): void
    {
        $source = (string) file_get_contents($root . '/agent/wprism.php');
        if (!defined('WPRISM_AGENT_VERSION')) {
            if (preg_match("/define\\('WPRISM_AGENT_VERSION', '([^']+)'\\)/D", $source, $match) !== 1) {
                throw new RuntimeException('Could not resolve WPRISM_AGENT_VERSION for adapter validation');
            }
            define('WPRISM_AGENT_VERSION', $match[1]);
        }
        if (!defined('WPRISM_SPEC_VERSION')) {
            if (preg_match("/define\\('WPRISM_SPEC_VERSION', ([0-9]+)\\)/D", $source, $match) !== 1) {
                throw new RuntimeException('Could not resolve WPRISM_SPEC_VERSION for adapter validation');
            }
            define('WPRISM_SPEC_VERSION', (int) $match[1]);
        }
    }

    private static function repositoryRoot(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || is_link($path)) {
            throw new RuntimeException("Repository root is not an ordinary directory: $path");
        }
        $root = realpath($path);
        if ($root === false || !is_dir($root) || !is_readable($root)) {
            throw new RuntimeException("Repository root is not an ordinary directory: $path");
        }
        return rtrim($root, '/');
    }
}

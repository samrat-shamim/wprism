<?php

declare(strict_types=1);

namespace Duo\Tooling;

use Duo\AdapterLibrary;
use Duo\ArtifactPolicyIdentity;
use Duo\Canon;
use Duo\ManifestDispositions;
use Duo\Policy;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/agent/src/Policy/AdapterLibrary.php';
require_once dirname(__DIR__, 2) . '/agent/src/Policy/Policy.php';
require_once dirname(__DIR__, 2) . '/agent/src/Policy/ArtifactPolicyIdentity.php';
require_once __DIR__ . '/AdapterProductionReadiness.php';
require_once __DIR__ . '/ArtifactLibrary.php';

/** Validate one adapter capsule without reading or executing a sibling adapter. */
final class AdapterPackageValidator
{
    public const FORMAT = 'duo-adapter-package-validation/v1';
    public const RUNTIME_SDK_FORMAT = 'duo-adapter-runtime-sdk/v1';
    private const EXTERNAL_EVIDENCE_FORMAT = 'duo-adapter-external-evidence/v1';
    private const INTEGRATION_SCENARIO_FORMAT = 'duo-adapter-integration-scenario/v1';
    private const PREMISE_EVIDENCE = 'target-observation-premises.tsv';

    /** @var list<string> */
    private const SHARED_EVIDENCE_PREFIXES = [
        'agent/src/',
        'platform/adapter-library/',
        'sandbox/conformance/',
        'sandbox/tests/live/',
        'sandbox/tests/offline/',
    ];

    /**
     * Public engine symbols used by the current manifest-bound runtime.
     *
     * This closed set is the versioned adapter ABI. Adding a dependency is an
     * engine SDK decision; merely importing another Duo class from a capsule is
     * not enough to make that internal class public.
     *
     * @var list<string>
     */
    private const RUNTIME_SDK_SYMBOLS = [
        'Duo\\CacheInvalidationTransaction',
        'Duo\\Canon',
        'Duo\\IdentityTokenCodec',
        'Duo\\Ledger',
        'Duo\\ManifestProviderRuntime',
        'Duo\\NativeActions',
        'Duo\\NativeRewriteEffects',
        'Duo\\PlainData',
        'Duo\\Policy',
        'Duo\\ProviderSdk',
        'Duo\\Providers',
        'Duo\\Secrets',
        'Duo\\SidebarState',
        'Duo\\Tokens',
        'Duo\\WpCliChildProcess',
    ];

    /** @return array{format:string,symbols:list<string>} */
    public static function runtimeSdk(): array
    {
        return [
            'format' => self::RUNTIME_SDK_FORMAT,
            'symbols' => self::RUNTIME_SDK_SYMBOLS,
        ];
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
        $library = AdapterLibrary::fromSourcePackage($root, $slug);
        $package = $library->package($slug);
        if ($package === null) {
            throw new RuntimeException("Adapter package '$slug' did not enter its own closed library");
        }
        $capsule = $root . '/adapter-packages/' . $slug;
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
        self::premiseEvidence($root, $capsule, $slug, $checks);
        self::packageEvidence($root, $slug, $manifest, $disposition, $checks);
        $evidenceTests = self::evidence($root, $capsule, $slug, $manifest, $disposition, $checks);

        return [
            'format' => self::FORMAT,
            'adapter' => $slug,
            'digest' => $identity['digest'],
            'manifest_sha256' => hash('sha256', Canon::encode($manifest)),
            'checks' => $checks,
            'evidence_tests' => $evidenceTests,
        ];
    }

    /** @param list<string> $checks */
    private static function dependencyBoundary(string $root, string $capsule, string $slug, array &$checks): void
    {
        $agentSource = realpath($root . '/agent/src');
        if ($agentSource === false || !is_dir($agentSource)) {
            throw new RuntimeException("Adapter package '$slug' cannot resolve the repository agent/src contract");
        }
        $runtimeSymbols = self::declaredRuntimeSymbols($capsule . '/package/runtime');

        $scanned = 0;
        foreach ([$capsule . '/package/runtime', $capsule . '/tests', $capsule . '/fixtures'] as $boundaryRoot) {
            if (!is_dir($boundaryRoot)) {
                continue;
            }
            $tests = $boundaryRoot !== $capsule . '/package/runtime';
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($boundaryRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $entry) {
                if (!$entry->isFile() || $entry->isLink()) {
                    continue;
                }
                $path = $entry->getPathname();
                if (!in_array($entry->getExtension(), ['json', 'php', 'sh'], true)) {
                    continue;
                }
                $source = file_get_contents($path);
                if ($source === false) {
                    throw new RuntimeException("Adapter package '$slug' cannot read dependency source $path");
                }
                self::assertNoGlobalLibrarySelection($capsule, $path, $source, $tests, $slug);
                self::assertNoSiblingCapsuleReference(
                    $capsule,
                    $path,
                    $source,
                    $entry->getExtension(),
                    $slug
                );
                self::assertRelativeAgentDependencies($root, $agentSource, $capsule, $path, $source, $tests, $slug);
                if (!$tests && $entry->getExtension() === 'php') {
                    self::assertRuntimeSdk($capsule, $path, $source, $slug, $runtimeSymbols);
                }
                $scanned++;
            }
        }
        $checks[] = "dependency-boundary:$scanned";
        $checks[] = 'runtime-sdk:' . self::RUNTIME_SDK_FORMAT;
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
            foreach (token_get_all($source) as $token) {
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
                        self::assertResolvableDuoImport($capsule, $path, $slug, $useStatement, $useLine);
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
                if (strncasecmp($symbol, 'Duo\\', strlen('Duo\\')) === 0) {
                    self::assertRuntimeSdkSymbol($capsule, $path, $slug, $symbol, $line, $runtimeSymbols);
                }
            } elseif ($kind === T_NAME_RELATIVE && self::isDuoNamespace($namespace)) {
                $symbol = $namespace . '\\' . substr($bytes, strlen('namespace\\'));
                self::assertRuntimeSdkSymbol($capsule, $path, $slug, $symbol, $line, $runtimeSymbols);
            } elseif ($kind === T_STRING
                && self::isDuoNamespace($namespace)
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
                if (!self::isDuoNamespace($symbol)) {
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
                foreach (self::dynamicDuoSymbols($bytes, $kind === T_CONSTANT_ENCAPSED_STRING) as $symbol) {
                    self::assertRuntimeSdkSymbol($capsule, $path, $slug, $symbol, $line, $runtimeSymbols);
                }
                if ($kind === T_CONSTANT_ENCAPSED_STRING) {
                    foreach (self::concatenatedDynamicDuoSymbols($tokens, $offset) as $symbol) {
                        self::assertRuntimeSdkSymbol($capsule, $path, $slug, $symbol, $line, $runtimeSymbols);
                    }
                }
            }
            if (!in_array($kind, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $previous = $kind;
            }
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
                'interpreters' => 'Duo\\Interpreters',
                'providers' => 'Duo\\Providers',
                'regenerators' => 'Duo\\Regenerators',
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
    private static function dynamicDuoSymbols(string $bytes, bool $quoted): array
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
            '/(?<![A-Za-z0-9_\\\\])\\\\?Duo\\\\[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*/i',
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
    private static function concatenatedDynamicDuoSymbols(array $tokens, int $offset): array
    {
        $first = $tokens[$offset] ?? null;
        if (!is_array($first) || $first[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return [];
        }
        $bytes = self::decodePhpStringLiteral($first[1]);
        $cursor = $offset + 1;
        $joined = false;
        while (true) {
            $dot = self::nextSignificantToken($tokens, $cursor);
            if ($dot === null || $dot['token'] !== '.') {
                break;
            }
            $cursor = $dot['offset'] + 1;
            $next = self::nextSignificantToken($tokens, $cursor);
            if ($next === null || !is_array($next['token']) || $next['token'][0] !== T_CONSTANT_ENCAPSED_STRING) {
                break;
            }
            $bytes .= self::decodePhpStringLiteral($next['token'][1]);
            $cursor = $next['offset'] + 1;
            $joined = true;
        }
        return $joined ? self::dynamicDuoSymbols($bytes, false) : [];
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

    private static function isDuoNamespace(string $namespace): bool
    {
        return strcasecmp($namespace, 'Duo') === 0
            || strncasecmp($namespace, 'Duo\\', strlen('Duo\\')) === 0;
    }

    private static function assertResolvableDuoImport(
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
        $grouped = str_starts_with($normalized, 'Duo\\{');
        $rootAlias = $normalized === 'Duo'
            || preg_match('/^Duoas[A-Za-z_][A-Za-z0-9_]*$/i', $normalized) === 1;
        if (!$grouped && !$rootAlias) {
            return;
        }
        $relative = substr($path, strlen($capsule) + 1);
        throw new RuntimeException(
            "Adapter package '$slug' uses an unresolved grouped or root Duo import at $relative:$line; "
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
            || (isset($runtimeSymbols[$symbol]) && substr_count($symbol, '\\') >= 2)) {
            return;
        }
        $relative = substr($path, strlen($capsule) + 1);
        throw new RuntimeException(
            "Adapter package '$slug' depends on non-SDK Duo symbol '$symbol' at $relative:$line; "
            . 'allowed surface is ' . self::RUNTIME_SDK_FORMAT
        );
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
                '/DUO_[\'".\s]*MANIFESTS_DIR/',
                'DUO_MANIFESTS_DIR',
                $line
            );
            if (!is_string($normalized) || !str_contains($normalized, 'DUO_MANIFESTS_DIR')) {
                continue;
            }
            if ($tests
                && substr_count($normalized, 'DUO_MANIFESTS_DIR') === 1
                && self::isNegativeTextAssertion($normalized, 'DUO_MANIFESTS_DIR')) {
                continue;
            }
            $relative = substr($path, strlen($capsule) + 1);
            throw new RuntimeException(
                "Adapter package '$slug' selects a manifest library through DUO_MANIFESTS_DIR at "
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

    /** @param list<string> $checks */
    private static function premiseEvidence(string $root, string $capsule, string $slug, array &$checks): void
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
                if (preg_match('/\brequire_(?:observed_nonempty|duo_answered|fixture_ids|fixture_values)\b/', $source) === 1) {
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
            self::validateVersionMatrixPremises($capsule, $slug, $matrix);
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
        if (($lines[0] ?? null) !== '# format duo-target-observation-premises/v1') {
            throw new RuntimeException(
                "Adapter package '$slug' premise contract is missing format duo-target-observation-premises/v1"
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
            if ($sourceBytes === false || !str_contains($sourceBytes, $needle)) {
                throw new RuntimeException(
                    "Adapter package '$slug' premise contract line " . ($offset + 1)
                    . " source is missing premise '$needle'"
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

    private static function validateVersionMatrixPremises(string $capsule, string $slug, string $matrix): void
    {
        if (is_link($matrix) || realpath($matrix) !== $matrix) {
            throw new RuntimeException("Adapter package '$slug' version matrix is not an ordinary file");
        }
        $source = file_get_contents($matrix);
        if ($source === false) {
            throw new RuntimeException("Adapter package '$slug' cannot read its version matrix");
        }
        foreach (['INSTALLED_2', 'TEC_INSTALLED_2', 'NEGATIVE_INSTALLED'] as $variable) {
            preg_match_all('/^[ \t]*' . $variable . '=/m', $source, $assignments);
            preg_match_all(
                '/^[ \t]*require_fixture_values[ \t]+' . $variable . '(?:[ \t]|$)/m',
                $source,
                $premises
            );
            if (count($assignments[0]) !== count($premises[0])) {
                $relative = substr($matrix, strlen($capsule) + 1);
                throw new RuntimeException(
                    "Adapter package '$slug' version matrix $relative has $variable assignment/premise mismatch ("
                    . count($assignments[0]) . '/' . count($premises[0]) . ')'
                );
            }
        }
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

        $available = [];
        $testsRoot = $capsule . '/tests';
        if (is_dir($testsRoot)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($testsRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $entry) {
                if (!$entry->isFile()) {
                    continue;
                }
                $basename = $entry->getBasename('.' . $entry->getExtension());
                if (preg_match('/^(?:regress|certify|spike)_[a-z0-9][a-z0-9._-]*$/D', $basename) === 1) {
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
        }
        AdapterProductionReadiness::record(
            $root,
            $slug,
            static function (string $repo, string $adapter, string $evidence): void {
                self::assertReadinessEvidenceOwnership($repo, $adapter, $evidence);
            }
        );
        $checks[] = 'artifact-evidence';
        $checks[] = 'production-readiness';
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

        if (str_starts_with($evidence, "adapter-packages/$slug/")) {
            return;
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
        foreach (self::SHARED_EVIDENCE_PREFIXES as $prefix) {
            if (str_starts_with($evidence, $prefix)) {
                return;
            }
        }

        throw new RuntimeException(
            "Adapter package '$slug' readiness evidence is outside the closed shared evidence roots: $evidence"
        );
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
        $source = (string) file_get_contents($root . '/agent/duo.php');
        if (!defined('DUO_AGENT_VERSION')) {
            if (preg_match("/define\\('DUO_AGENT_VERSION', '([^']+)'\\)/D", $source, $match) !== 1) {
                throw new RuntimeException('Could not resolve DUO_AGENT_VERSION for adapter validation');
            }
            define('DUO_AGENT_VERSION', $match[1]);
        }
        if (!defined('DUO_SPEC_VERSION')) {
            if (preg_match("/define\\('DUO_SPEC_VERSION', ([0-9]+)\\)/D", $source, $match) !== 1) {
                throw new RuntimeException('Could not resolve DUO_SPEC_VERSION for adapter validation');
            }
            define('DUO_SPEC_VERSION', (int) $match[1]);
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

<?php
declare(strict_types=1);

// DUO-3443: every engine source file must name the engine classes it loads.
// The product has no autoloader; the bootstrap order is not a standalone-load
// contract.  This scanner is deliberately conservative about PHP syntax and
// deliberately explicit about today's pre-existing gaps.

$root = dirname(__DIR__, 2);
$src = $root . '/agent/src';

function check(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

/** @return list<array{0:int|string,1:string}> */
function significant_tokens(string $source): array {
    $tokens = token_get_all($source);
    $out = [];
    foreach ($tokens as $token) {
        if (is_array($token)) {
            [$id, $text] = $token;
            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out[] = [$id, $text];
        } else {
            $out[] = [$token, $token];
        }
    }
    return $out;
}

function token_id(mixed $token): int|string {
    return is_array($token) ? $token[0] : $token;
}

function token_text(mixed $token): string {
    return is_array($token) ? (string) $token[1] : (string) $token;
}

function normalized_engine_name(string $name): ?string {
    $name = ltrim($name, '\\');
    if (str_starts_with($name, 'namespace\\')) {
        $name = 'Duo\\' . substr($name, strlen('namespace\\'));
    }
    if (str_starts_with($name, 'Duo\\')) {
        $name = substr($name, 4);
    } elseif (str_contains($name, '\\')) {
        // A qualified non-Duo name is not an engine class merely because its
        // final component happens to match one of ours.
        return null;
    }
    return preg_match('/^[A-Z_][A-Za-z0-9_]*$/D', $name) === 1 ? $name : null;
}

/** @return array<string,?string> short alias => engine class leaf, or null for external imports */
function namespace_aliases(string $source): array {
    $aliases = [];
    $tokens = significant_tokens($source);
    $count = count($tokens);
    $nameTokenIds = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];
    if (defined('T_NS_SEPARATOR')) {
        $nameTokenIds[] = T_NS_SEPARATOR;
    }
    for ($i = 0; $i < $count; $i++) {
        if (token_id($tokens[$i]) !== T_USE) {
            continue;
        }
        if ($i + 1 < $count && token_id($tokens[$i + 1]) === '(') {
            continue; // closure capture, not a namespace import
        }
        $end = $i + 1;
        while ($end < $count && token_id($tokens[$end]) !== ';') {
            $end++;
        }
        if ($end >= $count) {
            continue;
        }
        if ($i + 1 >= $end) {
            continue;
        }
        $importKind = token_id($tokens[$i + 1]);
        $register = static function (string $targetName, ?string $alias, bool $forceExternal = false) use (&$aliases): void {
            $targetName = trim($targetName, "\\");
            $local = $alias ?? basename(str_replace('\\', '/', $targetName));
            if ($local !== '') {
                // Preserve non-Duo imports as a negative entry.  Otherwise a
                // local alias such as `use Vendor\Canon; Canon::run()` would
                // be mistaken for Duo\Canon merely because the leaf matches.
                if ($forceExternal) {
                    $aliases[$local] = null;
                } elseif ($targetName === 'Duo' || (str_starts_with($targetName, 'Duo\\') && normalized_engine_name($targetName) === null)) {
                    $aliases[$local] = '@namespace:' . $targetName . '\\';
                } else {
                    $aliases[$local] = normalized_engine_name($targetName);
                }
            }
        };
        $group = null;
        for ($j = $i + 1; $j < $end; $j++) {
            if (token_id($tokens[$j]) === '{') {
                $group = $j;
                break;
            }
        }
        $parseClause = static function (array $parts, string $prefix, callable $register, bool $forceExternal) use ($nameTokenIds): void {
            $target = '';
            $alias = null;
            $seenAs = false;
            $clauseExternal = $forceExternal || ($parts !== [] && in_array(token_id($parts[0]), [T_CONST, T_FUNCTION], true));
            foreach ($parts as $part) {
                $id = token_id($part);
                if ($id === T_AS) {
                    $seenAs = true;
                    continue;
                }
                if ($seenAs) {
                    $alias = token_text($part);
                    $seenAs = false;
                    continue;
                }
                if (in_array($id, $nameTokenIds, true)) {
                    $target .= token_text($part);
                }
            }
            if ($target !== '') {
                $register($prefix . $target, $alias, $clauseExternal);
            }
        };
        if ($group !== null) {
            $prefix = '';
            for ($j = $i + 1; $j < $group; $j++) {
                if (in_array(token_id($tokens[$j]), $nameTokenIds, true)) {
                    $prefix .= token_text($tokens[$j]);
                }
            }
            $parts = [];
            for ($j = $group + 1; $j < $end; $j++) {
                if (token_id($tokens[$j]) === ',' || token_id($tokens[$j]) === '}') {
                    $parseClause($parts, $prefix, $register, in_array($importKind, [T_CONST, T_FUNCTION], true));
                    $parts = [];
                    continue;
                }
                $parts[] = $tokens[$j];
            }
            if ($parts !== []) {
                $parseClause($parts, $prefix, $register, in_array($importKind, [T_CONST, T_FUNCTION], true));
            }
            continue;
        }
        $parts = [];
        for ($j = $i + 1; $j < $end; $j++) {
            if (token_id($tokens[$j]) === ',') {
                $parseClause($parts, '', $register, in_array($importKind, [T_CONST, T_FUNCTION], true));
                $parts = [];
                continue;
            }
            $parts[] = $tokens[$j];
        }
        if ($parts !== []) {
            $parseClause($parts, '', $register, in_array($importKind, [T_CONST, T_FUNCTION], true));
        }
    }
    return $aliases;
}

/** @return array<string,string> class name => declaring basename */
function declarations(string $source, string $basename): array {
    $tokens = significant_tokens($source);
    $declared = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $id = token_id($tokens[$i]);
        if (!in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            continue;
        }
        $previous = $i > 0 ? token_id($tokens[$i - 1]) : null;
        if ($id === T_CLASS && in_array($previous, [T_NEW, T_DOUBLE_COLON], true)) {
            continue; // anonymous class
        }
        for ($j = $i + 1; $j < $count; $j++) {
            if (token_id($tokens[$j]) === T_STRING || in_array(token_id($tokens[$j]), [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $name = normalized_engine_name(token_text($tokens[$j]));
                if ($name !== null) {
                    $declared[$name] = $basename;
                }
                break;
            }
            if (token_id($tokens[$j]) === ';' || token_id($tokens[$j]) === '{') {
                break;
            }
        }
    }
    return $declared;
}

/** @return array<string,true> */
function direct_requires(string $source): array {
    $requires = [];
    $tokens = significant_tokens($source);
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (token_id($tokens[$i]) !== T_REQUIRE_ONCE) {
            continue;
        }
        for ($j = $i + 1; $j < $count && token_id($tokens[$j]) !== ';'; $j++) {
            if (token_id($tokens[$j]) !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $path = trim(token_text($tokens[$j]), "'\"");
            if (!str_ends_with($path, '.php')) {
                continue;
            }
            $name = basename($path, '.php');
            if ($name !== '') {
                $requires[$name] = true;
            }
        }
    }
    return $requires;
}

/** @return array<string,true> */
function references(string $source, array $known, array $aliases = []): array {
    $tokens = significant_tokens($source);
    $refs = [];
    $count = count($tokens);
    $ignoredUse = [];
    for ($u = 0; $u < $count; $u++) {
        if (token_id($tokens[$u]) !== T_USE || ($u + 1 < $count && token_id($tokens[$u + 1]) === '(')) {
            continue;
        }
        for ($v = $u; $v < $count; $v++) {
            $ignoredUse[$v] = true;
            if (token_id($tokens[$v]) === ';') {
                break;
            }
        }
    }
    $classish = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];
    for ($i = 0; $i < $count; $i++) {
        $id = token_id($tokens[$i]);
        if (!in_array($id, $classish, true)) {
            continue;
        }
        if (isset($ignoredUse[$i])) {
            continue;
        }
        $rawName = token_text($tokens[$i]);
        // Prefer an explicit import.  An alias such as PolicyAlias is a
        // perfectly ordinary unqualified token and would otherwise be
        // normalized to the alias leaf before the import map is consulted.
        $name = null;
        $aliasResolved = false;
        if (array_key_exists($rawName, $aliases)) {
            $name = $aliases[$rawName];
            $aliasResolved = true;
        } elseif (str_contains($rawName, '\\')) {
            [$head, $tail] = explode('\\', $rawName, 2);
            if (array_key_exists($head, $aliases)) {
                $prefix = $aliases[$head];
                if (is_string($prefix) && str_starts_with($prefix, '@namespace:')) {
                    $name = normalized_engine_name(substr($prefix, strlen('@namespace:')) . $tail);
                }
                $aliasResolved = true;
            }
        }
        if (!$aliasResolved) {
            $name = normalized_engine_name($rawName);
        }
        if ($name === null || !isset($known[$name])) {
            continue;
        }
        $previousId = $i > 0 ? token_id($tokens[$i - 1]) : null;
        $nextId = $i + 1 < $count ? token_id($tokens[$i + 1]) : null;
        if (in_array($previousId, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_FUNCTION, T_CONST, T_NAMESPACE, T_USE], true)) {
            continue;
        }
        if (in_array($previousId, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            continue; // method/property name, not a class reference
        }
        if ($previousId === T_DOUBLE_COLON) {
            continue; // member/method name after an already-scanned class LHS
        }
        if ($nextId === '(' && $previousId !== T_DOUBLE_COLON && $previousId !== T_NEW) {
            continue; // ordinary function/method call; constructors use new
        }
        if ($nextId === ':') {
            continue; // named argument label, not a class/type position
        }
        if ($nextId === T_DOUBLE_COLON) {
            $refs[$name] = true;
            continue;
        }
        $catchType = $previousId === '(' && $i > 1 && token_id($tokens[$i - 2]) === T_CATCH;
        if ($catchType || in_array($previousId, [T_NEW, T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS, T_CATCH], true)) {
            $refs[$name] = true;
            continue;
        }
        // Remaining occurrences are type positions (parameter/property/return
        // hints) or a bare class name in a supported PHP expression.  The
        // function-call filter above excludes ordinary method/function names.
        $refs[$name] = true;
    }
    return $refs;
}

/** @return array<string,list<string>> */
function find_gaps(array $sources, array $classFiles, array $knownGaps): array {
    $gaps = [];
    foreach ($sources as $basename => $source) {
        $requires = direct_requires($source);
        foreach (references($source, $classFiles, namespace_aliases($source)) as $name => $_) {
            $declaringFiles = $classFiles[$name];
            if (in_array($basename, $declaringFiles, true)
                || array_intersect($declaringFiles, array_keys($requires)) !== []) {
                continue;
            }
            $key = "$basename::$name";
            if (isset($knownGaps[$key])) {
                continue;
            }
            $gaps[$key] = "$basename references Duo\\$name (declared in " . implode(', ', $declaringFiles) . ".php) without require_once";
        }
    }
    return $gaps;
}

$synthetic = <<<'PHP'
<?php
namespace Duo;
use Duo\Policy as PolicyAlias;
// require_once __DIR__ . '/CommentOnly.php';
$literal = "require_once __DIR__ . '/StringOnly.php';";
function synthetic(Foo $value): Bar {
    $value = new PolicyAlias();
    Bar::probe();
    return $value;
}
PHP;
$syntheticKnown = ['Policy' => [], 'Foo' => [], 'Bar' => []];
$syntheticRefs = references($synthetic, $syntheticKnown, namespace_aliases($synthetic));
check(isset($syntheticRefs['Policy'], $syntheticRefs['Foo'], $syntheticRefs['Bar']), 'scanner misses a type, alias, or static class reference');
check(direct_requires($synthetic) === [], 'scanner counted a require_once hidden in a comment or string');
$foreign = '<?php new \\Vendor\\Canon();';
check(references($foreign, ['Canon' => []]) === [], 'scanner collapsed a non-Duo qualified class to an engine leaf');
$foreignImport = '<?php namespace Duo; use Vendor\\Canon; Canon::run();';
check(references($foreignImport, ['Canon' => []], namespace_aliases($foreignImport)) === [], 'scanner collapsed an imported non-Duo class to an engine leaf');
$foreignAlias = '<?php namespace Duo; use Vendor\\Canon as CanonAlias; CanonAlias::run();';
check(references($foreignAlias, ['Canon' => []], namespace_aliases($foreignAlias)) === [], 'scanner collapsed an aliased non-Duo class to an engine leaf');
$foreignGroup = '<?php namespace Duo; use Vendor\\{Canon as C}; C::run();';
check(references($foreignGroup, ['Canon' => []], namespace_aliases($foreignGroup)) === [], 'scanner collapsed a grouped non-Duo class to an engine leaf');
$duoGroup = '<?php namespace Duo; use Duo\\{Canon as C}; C::run();';
check(isset(references($duoGroup, ['Canon' => []], namespace_aliases($duoGroup))['Canon']), 'scanner missed a grouped Duo import');
$relative = '<?php namespace Duo; namespace\\Canon::run(); new namespace\\Canon();';
check(isset(references($relative, ['Canon' => []])['Canon']), 'scanner missed namespace-relative class references');
$member = '<?php $value::Policy(); self::Policy(); static::Policy(); parent::Policy(); Canon::Policy();';
$memberRefs = references($member, ['Canon' => [], 'Policy' => []]);
check(array_keys($memberRefs) === ['Canon'], 'scanner treated member names after :: as engine classes');
$namedArgument = '<?php call(Policy: true);';
check(references($namedArgument, ['Policy' => []]) === [], 'scanner treated a named argument label as an engine class');
$directAlias = '<?php namespace Duo; use Duo\\DatabaseMutationException as D; new D();';
check(isset(references($directAlias, ['DatabaseMutationException' => []], namespace_aliases($directAlias))['DatabaseMutationException']), 'scanner missed a direct Duo alias containing "as" in its class name');
$commaImports = '<?php namespace Duo; use Duo\\Canon, Duo\\Policy as P; Canon::run(); P::run();';
$commaRefs = references($commaImports, ['Canon' => [], 'Policy' => []], namespace_aliases($commaImports));
check(isset($commaRefs['Canon'], $commaRefs['Policy']), 'scanner missed one of multiple direct imports');
$constImport = '<?php namespace Duo; use const Vendor\\Canon; echo Canon;';
check(references($constImport, ['Canon' => []], namespace_aliases($constImport)) === [], 'scanner treated a const import as an engine class');
$duoConstImport = '<?php namespace Duo; use const Duo\\Canon; echo Canon;';
check(references($duoConstImport, ['Canon' => []], namespace_aliases($duoConstImport)) === [], 'scanner treated a Duo const import as an engine class');
$duoConstAlias = '<?php namespace Duo; use const Duo\\Canon as C; echo C;';
check(references($duoConstAlias, ['Canon' => []], namespace_aliases($duoConstAlias)) === [], 'scanner treated an aliased Duo const import as an engine class');
$duoConstGroup = '<?php namespace Duo; use const Duo\\{Canon as C}; echo C;';
check(references($duoConstGroup, ['Canon' => []], namespace_aliases($duoConstGroup)) === [], 'scanner treated a grouped Duo const import as an engine class');
$mixedConstGroup = '<?php namespace Duo; use Duo\\{Canon, const Policy as P}; echo P;';
check(references($mixedConstGroup, ['Canon' => [], 'Policy' => []], namespace_aliases($mixedConstGroup)) === [], 'scanner treated a mixed grouped const import as an engine class');
$functionImport = '<?php namespace Duo; use function Vendor\\Canon; Canon();';
check(references($functionImport, ['Canon' => []], namespace_aliases($functionImport)) === [], 'scanner treated a function import as an engine class');
$mixedFunctionGroup = '<?php namespace Duo; use Duo\\{Canon, function Policy as P}; P();';
check(references($mixedFunctionGroup, ['Canon' => [], 'Policy' => []], namespace_aliases($mixedFunctionGroup)) === [], 'scanner treated a mixed grouped function import as an engine class');
$namespaceAlias = '<?php namespace Duo; use Duo as D; D\\Canon::run();';
check(isset(references($namespaceAlias, ['Canon' => []], namespace_aliases($namespaceAlias))['Canon']), 'scanner missed a namespace prefix alias');
$syntheticWithRequire = $synthetic . "\nrequire_once __DIR__ . '/Policy.php';\n";
check(direct_requires($syntheticWithRequire) === ['Policy' => true], 'scanner failed to parse a tokenized require_once path');

$files = glob($src . '/*.php') ?: [];
sort($files, SORT_STRING);
check($files !== [], 'agent/src contains no PHP files');

$classFiles = [];
$sources = [];
foreach ($files as $file) {
    $basename = basename($file, '.php');
    $source = file_get_contents($file);
    check($source !== false, "could not read $file");
    $sources[$basename] = $source;
    foreach (declarations($source, $basename) as $name => $declaringFile) {
        $classFiles[$name] ??= [];
        if (!in_array($declaringFile, $classFiles[$name], true)) {
            $classFiles[$name][] = $declaringFile;
        }
    }
}

// These are existing, separately tracked gaps.  The list is deliberately
// explicit: a new file/class pair is not silently grandfathered in.  Each
// group is a future self-require slice, while DUO-3442/3444 remain separately
// actionable defects rather than being hidden by this guard.
$knownGapsByFile = [
    'AdapterObservation' => ['AdapterSources', 'Canon', 'CommandRefusalException', 'Journal', 'Pending', 'Policy'],
    'AdapterRegistry' => ['AdapterSources', 'ManifestDispositions', 'Policy'],
    'AdapterSources' => ['Canon', 'ManifestDispositions', 'Policy'],
    'Apply' => ['Canary', 'Canon', 'Capture', 'Code', 'CompiledRepository', 'DatabaseMutationException', 'Db', 'Deletion', 'Deploy', 'IdentityNotes', 'Ledger', 'LedgerScopedApplySessionStorage', 'NativeActions', 'OptionState', 'Policy', 'PromotionLock', 'RepositoryCompiler', 'ScopeContract', 'ScopedApply', 'ScopedApplySession', 'ScopedPromotionAuthority', 'ScopedStateOverlay', 'SidebarState', 'Snapshot', 'Tokens'],
    'ApplyFieldMaterializer' => ['Db', 'StructuredValue'],
    'AttachmentMaterializer' => ['CompiledRepository'],
    'Blocks' => ['Capture', 'Policy', 'Shortcodes', 'Tokens'],
    'Canon' => ['OrderPreserved', 'Policy'],
    'CanonicalSurfaces' => ['OptionState', 'Policy', 'SidebarState'],
    'CapabilityRegistry' => ['Canon', 'ManifestDispositions', 'Policy'],
    'Capture' => ['Blocks', 'Code', 'CompiledRepository', 'Db', 'Deletion', 'Deploy', 'Identity', 'Ledger', 'Lint', 'OptionState', 'OrderPreserved', 'PersonalData', 'Policy', 'RepositoryCompiler', 'ScopeContract', 'ScopedStateOverlay', 'Secrets', 'SidebarState', 'Snapshot', 'Tokens', 'TransientDbException', 'UserMetaState', 'Uuid'],
    'Cli' => ['AdapterObservation', 'AdapterSources', 'Apply', 'Canon', 'CapabilityRegistry', 'Capture', 'Code', 'CodeCompilationException', 'Coverage', 'Db', 'Deploy', 'IdentityBackup', 'Init', 'InitialStateBoundaryException', 'Journal', 'Ledger', 'Lint', 'ManifestDispositions', 'Orphans', 'Pending', 'Policy', 'PromotionLock', 'RefreshExport', 'RepositoryAuthorizationException', 'RepositoryCompilationException', 'RepositoryCompiler', 'ScopeClosure', 'ScopeContract', 'ScopedPromotionAuthority', 'ScopedStateOverlay', 'Secrets'],
    'Code' => ['Canon', 'CodeCompatibility', 'CodeStateContract', 'CompiledRepository', 'Db', 'Ledger', 'PromotionLock'],
    'CodeConfigGrammar' => ['Code'],
    'CodeStateContract' => ['CompiledRepository', 'OptionState'],
    'CompiledArtifact' => ['Canon', 'Code'],
    'ConvergenceVerifier' => ['Capture', 'CompiledRepository', 'LedgerScopedApplySessionStorage', 'Policy', 'ScopedApply', 'ScopedApplySession'],
    'Coverage' => ['Policy'],
    'Db' => ['TransientDbException'],
    'DeleteExecutor' => ['Db', 'Ledger'],
    'Deletion' => ['Canon', 'CompiledRepository', 'Policy', 'SidebarState', 'Snapshot'],
    'Deploy' => ['Canary', 'Canon', 'Capture', 'Code', 'CompiledRepository', 'Ledger', 'OptionState', 'Policy', 'PromotionLock', 'RepositoryCompiler'],
    'Identity' => ['Canon', 'SidebarState', 'Uuid'],
    'IdentityBackup' => ['Canon', 'Identity', 'Ledger', 'Policy', 'RepositoryCompiler', 'SidebarState', 'Snapshot', 'Uuid'],
    'IdentityNotes' => ['Snapshot', 'Uuid'],
    'Init' => ['AdapterSources', 'BoundHelper', 'Canon', 'Capture', 'Code', 'CommandRefusalException', 'InitialStateBoundaryException', 'PersonalData', 'Policy', 'Publish', 'RepositoryCompiler', 'Secrets'],
    'Journal' => ['CommandRefusalException', 'Db', 'Ledger', 'Policy'],
    'Ledger' => ['Db', 'Uuid'],
    'LifecyclePlanner' => ['Code', 'CompiledRepository', 'Ledger', 'Policy'],
    'Lint' => ['Canon', 'OptionState', 'Pending', 'Policy', 'ReferenceRules'],
    'ManifestDispositions' => ['Canon'],
    'MenuMaterializer' => ['Db', 'Ledger', 'PlainData', 'Policy', 'Tokens'],
    'OptionState' => ['Canon'],
    'OptionsMaterializer' => ['Db'],
    'Orphans' => ['Canary', 'DatabaseMutationException', 'Db', 'Ledger', 'Policy', 'Snapshot'],
    'Pending' => ['Capture', 'CommandRefusalException', 'Journal', 'Ledger', 'Policy', 'Secrets', 'Snapshot'],
    'PinResolver' => ['Policy', 'RepositoryCompiler'],
    'PlanExplanation' => ['CommandRefusalException', 'CompiledRepository', 'Deletion', 'OptionState', 'Policy', 'ReferenceGraph', 'SidebarState'],
    'Policy' => ['Canon', 'CapabilityRegistry', 'ManifestDispositions', 'OptionState'],
    'PostMaterializer' => ['Db', 'Ledger'],
    'PromotionLease' => ['Db', 'Ledger'],
    'PromotionSessionJournal' => ['Ledger'],
    'Providers' => ['Deploy', 'Policy'],
    'Publish' => ['PublicationJournal'],
    'ReferenceGraph' => ['Policy', 'SidebarState', 'Snapshot'],
    'RefreshExport' => ['Canon', 'Capture', 'Code', 'CompiledRepository', 'Deletion', 'Identity', 'Ledger', 'Policy', 'RepositoryCompiler', 'ScopeContract', 'ScopedStateOverlay'],
    'RelationshipMaterializer' => ['Db', 'Ledger'],
    'RepositoryAuthorization' => ['Canon', 'OptionState', 'PersonalData', 'Policy', 'ReferenceRules', 'RepositoryCompiler', 'Secrets', 'SidebarState', 'Snapshot'],
    'RepositoryCompiler' => ['AdapterSources', 'Canon', 'CapabilityRegistry', 'Code', 'CodeCompilationException', 'CodeStateContract', 'Deletion', 'JsonRefs', 'OptionState', 'Policy', 'ReferenceRules', 'RepositoryAuthorization', 'SidebarState', 'Snapshot', 'UserMetaState'],
    'ScopeClosure' => ['CompiledRepository', 'Policy', 'SidebarState', 'UserMetaState'],
    'ScopeContract' => ['Canon', 'CompiledRepository', 'Deletion', 'Policy', 'ReferenceGraph', 'ScopeClosure'],
    'ScopedApply' => ['Canon', 'CompiledRepository', 'Db', 'Ledger', 'Policy', 'ReferenceGraph', 'RepositoryCompiler', 'ScopeContract', 'ScopedApplySession', 'ScopedApplySessionStorage', 'ScopedStateOverlay', 'SidebarState', 'Snapshot', 'Uuid'],
    'ScopedStateOverlay' => ['Canon', 'CompiledRepository', 'Policy', 'ScopeClosure', 'ScopeContract'],
    'Shortcodes' => ['Capture', 'Policy', 'Tokens'],
    'SidebarState' => ['Blocks', 'Canon', 'Db', 'Ledger', 'Policy', 'Secrets', 'Snapshot', 'Tokens', 'Uuid'],
    'Snapshot' => ['Canon', 'Db', 'IdentityNotes', 'Ledger', 'OptionState', 'Policy', 'Secrets', 'Tokens', 'Uuid'],
    'TermMaterializer' => ['Db', 'Ledger'],
    'Tokens' => ['Capture', 'JsonRefs', 'Ledger', 'Policy'],
    'UserMetaMaterializer' => ['Db'],
];
$knownGaps = [];
foreach ($knownGapsByFile as $basename => $names) {
    foreach ($names as $name) {
        $knownGaps["$basename::$name"] = 'pre-existing bootstrap dependency; future self-require slice';
    }
}

$gaps = find_gaps($sources, $classFiles, $knownGaps);

check(count($knownGaps) >= 250, 'known-gap baseline unexpectedly shrank; review the allowlist rather than hiding changes');
fwrite(STDOUT, 'known gaps: ' . count($knownGaps) . " (explicit baseline; new pairs fail)\n");
// The allowlist cannot become a dead, copy-pasted escape hatch: every entry
// must still be observed in the current baseline.  Then mutate the three
// concrete standalone-load fixes that motivated this issue and prove the
// scanner would flag each one when its require_once disappears.
$baselineGaps = find_gaps($sources, $classFiles, []);
$staleAllowlist = array_diff_key($knownGaps, $baselineGaps);
check($staleAllowlist === [], 'allowlist contains no-longer-observed gaps: ' . implode(', ', array_keys($staleAllowlist)));

$mutated = $sources;
foreach ([
    ['Apply', 'ConvergenceVerifier'],
    ['ConvergenceVerifier', 'Canon'],
    ['Capture', 'Canary'],
] as [$file, $dependency]) {
    $pattern = "~^require_once __DIR__ \\. '/" . preg_quote($dependency, '~') . "\\.php';\\R~m";
    $changed = preg_replace($pattern, '', $mutated[$file], 1, $count);
    check($count === 1 && is_string($changed), "mutation fixture could not remove $file.php -> $dependency.php require_once");
    $mutated[$file] = $changed;
    $mutatedGaps = find_gaps($mutated, $classFiles, $knownGaps);
    check(isset($mutatedGaps["$file::$dependency"]), "scanner missed mutated $file::$dependency gap");
}
fwrite(STDOUT, "ok: mutations for DUO-3440/3441/3442 are detected before the explicit baseline allowlist\n");

if ($gaps !== []) {
    foreach ($gaps as $key => $reason) {
        fwrite(STDERR, "gap: $key — $reason\n");
    }
    exit(1);
}

check(count($classFiles) >= 80, 'scanner discovered too few engine declarations');
fwrite(STDOUT, 'ok: every agent/src engine class reference is self-required or declared locally; ' . count($classFiles) . " declarations checked\n");

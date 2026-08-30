<?php
declare(strict_types=1);

// issue #3443: every engine source file must name the engine classes it loads.
// The product has no autoloader; the bootstrap order is not a standalone-load
// contract.  This scanner is deliberately conservative about PHP syntax and
// deliberately explicit about today's pre-existing gaps.

$root = dirname(__DIR__, 4);
$src = $root . '/agent/src';

function check(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

// The scanner below proves the declared edges. This direct partial load proves
// the product consequence: the authority's public type contract is usable
// without relying on wprism.php's production include order or the classmap.
require_once $src . '/Apply/AttachmentNativeMetadataGenerator.php';
check(
    class_exists(\WPrism\AttachmentNativeMetadataGenerator::class, false)
        && class_exists(\WPrism\CompiledRepository::class, false)
        && class_exists(\WPrism\AttachmentFilesystemTransaction::class, false)
        && class_exists(\WPrism\AttachmentMaterializer::class, false),
    'AttachmentNativeMetadataGenerator standalone load brings in every non-local authority dependency'
);

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

/** Return executable tokens only, so rationale comments cannot trip behavior checks. */
function executable_source(string $source): string {
    $out = '';
    foreach (token_get_all($source) as $token) {
        if (!is_array($token)) {
            $out .= $token;
            continue;
        }
        if (in_array($token[0], [
            T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING,
            T_ENCAPSED_AND_WHITESPACE, T_START_HEREDOC, T_END_HEREDOC,
        ], true)) {
            continue;
        }
        $out .= $token[1];
    }
    return $out;
}

/** Resolve WPrism imports before matching static calls; mask foreign lookalikes. */
function review_executable_source(string $source): string {
    $code = executable_source($source);
    foreach (namespace_aliases($source) as $alias => $target) {
        $quotedAlias = preg_quote($alias, '/');
        if ($target === null) {
            $code = (string) preg_replace(
                '/\b' . $quotedAlias . '(?=\s*::|\s*\\\\)/',
                '__External_' . $alias,
                $code
            );
            continue;
        }
        if (str_starts_with($target, '@namespace:')) {
            $prefix = substr($target, strlen('@namespace:'));
            $code = (string) preg_replace_callback(
                '/\b' . $quotedAlias . '\s*\\\\\s*([A-Za-z_][A-Za-z0-9_]*)\s*::/',
                static function (array $match) use ($prefix, $alias): string {
                    $resolved = normalized_engine_name($prefix . $match[1]);
                    return ($resolved ?? '__External_' . $alias . '_' . $match[1]) . '::';
                },
                $code
            );
            continue;
        }
        $code = (string) preg_replace(
            '/\b' . $quotedAlias . '(?=\s*::)/',
            $target,
            $code
        );
    }
    return $code;
}

/** @return list<string> */
function review_side_effects(string $source): array {
    $code = review_executable_source($source);
    $patterns = [
        'ledger mutation/initialization' => '/\bLedger\s*::\s*(?:ensure|set|forget|set_state_hash|prune_state|prune_dead_map|prune_dead_table_map|prune_dead_composite_table_map|kv_set|kv_delete)\s*\(/',
        'database write transaction/query' => '/\bDb\s*::\s*(?:query|insert|update|delete|start|start_repeatable_read|start_consistent_snapshot|commit|rollback_after_failure)\s*\(/',
        'snapshot row mutation' => '/\bSnapshot\s*::\s*(?:repair_truncated_entity_types|capture|adopt|ensure_row|finalize_row|delete_row|delete_local_row|reparent_local_row|prune_dead_map|prune_option_name_ref_map)\s*\(/',
        'policy mutation' => '/\bPolicy\s*::\s*set_rule\s*\(/',
        'scoped staging/discard mutation' => '/\bScopedStateOverlay\s*::\s*(?:stage_candidate_media_view|stage_state_view|stage_associated_source_media_view|discard_state_view|discard_media_view)\s*\(/',
        'durable filesystem mutation' => '/\bDurableFilesystem\s*::\s*(?:removeOwned|syncParent|syncFile|syncDirectory|removeTree)\s*\(/',
        'publication mutation' => '/\b(?:Publish|PublicationJournal|AtomicTreePublisher)\s*::\s*[A-Za-z_][A-Za-z0-9_]*\s*\(/',
        'canonical file write' => '/\bCanon\s*::\s*write_file\s*\(/',
        'direct filesystem write' => '/\b(?:file_put_contents|tempnam|unlink|mkdir|rename|fopen|fwrite|fputs|copy|touch|chmod|chown|chgrp|rmdir|symlink|link|move_uploaded_file|stream_copy_to_stream)\s*\(/',
        'direct wpdb mutation' => '/->\s*(?:insert|update|delete|replace|query)\s*\(/',
        'WordPress option/cache mutation' => '/\b(?:add_option|update_option|delete_option|add_site_option|update_site_option|delete_site_option|set_transient|delete_transient|set_site_transient|delete_site_transient|wp_cache_(?:add|set|replace|delete|flush|incr|decr)|clean_(?:post|term|user|comment|object)_cache)\s*\(/',
        'WordPress metadata mutation' => '/\b(?:(?:add|update|delete)_metadata|(?:add|update|delete)_(?:post|term|user|comment)_meta|set_post_thumbnail|delete_post_thumbnail)\s*\(/',
        'WordPress entity mutation' => '/\b(?:wp_(?:insert|update|delete|trash|untrash)_post|wp_insert_attachment|wp_delete_attachment|wp_(?:insert|update|delete)_term|wp_(?:create|insert|update|delete)_user|wp_(?:insert|update|delete|trash|untrash|spam|unspam)_comment|wp_create_nav_menu|wp_update_nav_menu|wp_update_nav_menu_item|wp_delete_nav_menu)\s*\(/',
        'WordPress relationship mutation' => '/\b(?:wp_(?:set|add|remove)_object_terms|wp_delete_object_term_relationships|wp_set_post_(?:terms|categories|tags))\s*\(/',
        'file lock' => '/(?:\bflock\s*\(|\bLOCK_(?:EX|SH)\b)/',
    ];
    $found = [];
    foreach ($patterns as $label => $pattern) {
        if (preg_match($pattern, $code) === 1) {
            $found[] = $label;
        }
    }
    return $found;
}

/**
 * Mixed-authority facades fail closed: a newly added method call is a review
 * event even if its name has not yet reached the writer vocabulary above.
 *
 * @return list<string>
 */
function review_unapproved_mixed_calls(string $source): array {
    $allowed = [
        'Canon' => ['decode', 'encode', 'parse_post_file', 'read_file'],
        'Capture' => ['gate_scan_read_only'],
        'Db' => [],
        'DurableFilesystem' => [],
        'Journal' => ['ground_truth', 'ground_truth_details', 'report_read_only', 'table_state'],
        'Ledger' => [],
        'Policy' => ['load'],
        'ScopedStateOverlay' => [],
        'Snapshot' => ['keyspace_gaps'],
    ];
    $code = review_executable_source($source);
    $violations = [];
    foreach ($allowed as $class => $methods) {
        $pattern = '/\b' . preg_quote($class, '/') . '\s*::\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/';
        preg_match_all($pattern, $code, $matches);
        foreach ($matches[1] as $method) {
            if (!in_array($method, $methods, true)) {
                $violations[] = "$class::$method";
            }
        }
    }
    $violations = array_values(array_unique($violations));
    sort($violations, SORT_STRING);
    return $violations;
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
        $name = 'WPrism\\' . substr($name, strlen('namespace\\'));
    }
    if (str_starts_with($name, 'WPrism\\')) {
        $name = substr($name, strlen('WPrism\\'));
    } elseif (str_contains($name, '\\')) {
        // A qualified non-WPrism name is not an engine class merely because its
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
                // Preserve non-WPrism imports as a negative entry.  Otherwise a
                // local alias such as `use Vendor\Canon; Canon::run()` would
                // be mistaken for WPrism\Canon merely because the leaf matches.
                if ($forceExternal) {
                    $aliases[$local] = null;
                } elseif ($targetName === 'WPrism' || (str_starts_with($targetName, 'WPrism\\') && normalized_engine_name($targetName) === null)) {
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
            $aliasTarget = $aliases[$rawName];
            $name = is_string($aliasTarget) && str_starts_with($aliasTarget, '@namespace:')
                ? normalized_engine_name(rtrim(substr($aliasTarget, strlen('@namespace:')), '\\'))
                : $aliasTarget;
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
            $gaps[$key] = "$basename references WPrism\\$name (declared in " . implode(', ', $declaringFiles) . ".php) without require_once";
        }
    }
    return $gaps;
}

$synthetic = <<<'PHP'
<?php
namespace WPrism;
use WPrism\Policy as PolicyAlias;
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
check(references($foreign, ['Canon' => []]) === [], 'scanner collapsed a non-WPrism qualified class to an engine leaf');
$foreignImport = '<?php namespace WPrism; use Vendor\\Canon; Canon::run();';
check(references($foreignImport, ['Canon' => []], namespace_aliases($foreignImport)) === [], 'scanner collapsed an imported non-WPrism class to an engine leaf');
$foreignAlias = '<?php namespace WPrism; use Vendor\\Canon as CanonAlias; CanonAlias::run();';
check(references($foreignAlias, ['Canon' => []], namespace_aliases($foreignAlias)) === [], 'scanner collapsed an aliased non-WPrism class to an engine leaf');
$foreignGroup = '<?php namespace WPrism; use Vendor\\{Canon as C}; C::run();';
check(references($foreignGroup, ['Canon' => []], namespace_aliases($foreignGroup)) === [], 'scanner collapsed a grouped non-WPrism class to an engine leaf');
$wprismGroup = '<?php namespace WPrism; use WPrism\\{Canon as C}; C::run();';
check(isset(references($wprismGroup, ['Canon' => []], namespace_aliases($wprismGroup))['Canon']), 'scanner missed a grouped WPrism import');
$relative = '<?php namespace WPrism; namespace\\Canon::run(); new namespace\\Canon();';
check(isset(references($relative, ['Canon' => []])['Canon']), 'scanner missed namespace-relative class references');
$member = '<?php $value::Policy(); self::Policy(); static::Policy(); parent::Policy(); Canon::Policy();';
$memberRefs = references($member, ['Canon' => [], 'Policy' => []]);
check(array_keys($memberRefs) === ['Canon'], 'scanner treated member names after :: as engine classes');
$namedArgument = '<?php call(Policy: true);';
check(references($namedArgument, ['Policy' => []]) === [], 'scanner treated a named argument label as an engine class');
$directAlias = '<?php namespace WPrism; use WPrism\\DatabaseMutationException as D; new D();';
check(isset(references($directAlias, ['DatabaseMutationException' => []], namespace_aliases($directAlias))['DatabaseMutationException']), 'scanner missed a direct WPrism alias containing "as" in its class name');
$commaImports = '<?php namespace WPrism; use WPrism\\Canon, WPrism\\Policy as P; Canon::run(); P::run();';
$commaRefs = references($commaImports, ['Canon' => [], 'Policy' => []], namespace_aliases($commaImports));
check(isset($commaRefs['Canon'], $commaRefs['Policy']), 'scanner missed one of multiple direct imports');
$constImport = '<?php namespace WPrism; use const Vendor\\Canon; echo Canon;';
check(references($constImport, ['Canon' => []], namespace_aliases($constImport)) === [], 'scanner treated a const import as an engine class');
$wprismConstImport = '<?php namespace WPrism; use const WPrism\\Canon; echo Canon;';
check(references($wprismConstImport, ['Canon' => []], namespace_aliases($wprismConstImport)) === [], 'scanner treated a WPrism const import as an engine class');
$wprismConstAlias = '<?php namespace WPrism; use const WPrism\\Canon as C; echo C;';
check(references($wprismConstAlias, ['Canon' => []], namespace_aliases($wprismConstAlias)) === [], 'scanner treated an aliased WPrism const import as an engine class');
$wprismConstGroup = '<?php namespace WPrism; use const WPrism\\{Canon as C}; echo C;';
check(references($wprismConstGroup, ['Canon' => []], namespace_aliases($wprismConstGroup)) === [], 'scanner treated a grouped WPrism const import as an engine class');
$mixedConstGroup = '<?php namespace WPrism; use WPrism\\{Canon, const Policy as P}; echo P;';
check(references($mixedConstGroup, ['Canon' => [], 'Policy' => []], namespace_aliases($mixedConstGroup)) === [], 'scanner treated a mixed grouped const import as an engine class');
$functionImport = '<?php namespace WPrism; use function Vendor\\Canon; Canon();';
check(references($functionImport, ['Canon' => []], namespace_aliases($functionImport)) === [], 'scanner treated a function import as an engine class');
$mixedFunctionGroup = '<?php namespace WPrism; use WPrism\\{Canon, function Policy as P}; P();';
check(references($mixedFunctionGroup, ['Canon' => [], 'Policy' => []], namespace_aliases($mixedFunctionGroup)) === [], 'scanner treated a mixed grouped function import as an engine class');
$namespaceAlias = '<?php namespace WPrism; use WPrism as D; D\\Canon::run();';
check(isset(references($namespaceAlias, ['Canon' => []], namespace_aliases($namespaceAlias))['Canon']), 'scanner missed a namespace prefix alias');
$syntheticWithRequire = $synthetic . "\nrequire_once __DIR__ . '/Policy.php';\n";
check(direct_requires($syntheticWithRequire) === ['Policy' => true], 'scanner failed to parse a tokenized require_once path');

// Recursive since the module move (ROUND 3 TRAIN 1): agent/src is no
// longer flat, and a non-recursive glob would return zero files and make
// every check below pass vacuously.
$files = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $entry) {
    if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
        $files[] = $entry->getPathname();
    }
}
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
// group is a future self-require slice, while issue #3442/3444 remain separately
// actionable defects rather than being hidden by this guard.
$knownGapsByFile = [
    'AdapterObservation' => ['AdapterSources', 'Canon', 'CommandRefusalException', 'Journal', 'Pending', 'Policy'],
    // Only Policy remains a gap: the class hard-requires Canon,
    // ManifestDispositions, AdapterSources and TargetProbe at file scope, and
    // every Policy reference here is a constructor type hint or an instance
    // call on the $policy it was handed — never a `Policy::` static — so no
    // standalone load can reach a class-not-found through it.
    'AdapterRegistry' => ['Policy'],
    'AdapterSources' => ['Canon', 'ManifestDispositions', 'Policy'],
    'ApplyRequestCoordinator' => ['Canary', 'Canon', 'Capture', 'CompiledRepository', 'Ledger', 'LedgerScopedApplySessionStorage', 'Policy', 'PromotionLock', 'RepositoryCompiler', 'ScopeContract', 'ScopedApply', 'ScopedApplySession', 'ScopedPromotionAuthority', 'ScopedStateOverlay', 'Snapshot', 'Tokens'],
    'ApplyFieldMaterializer' => ['Db', 'StructuredValue'],
    'AttachmentMaterializer' => ['CompiledRepository'],
    'Blocks' => ['Policy', 'Shortcodes', 'Tokens'],
    'Canon' => ['OrderPreserved', 'Policy'],
    'CanonicalSurfaces' => ['OptionState', 'Policy', 'SidebarState'],
    // issue #3499 closed the CodeCompilationException gap: Cli::code_inventory()
    // require_once's CodeDescriptorCompiler.php, which declares it, so the
    // allowlist entry became a no-longer-observed gap and this two-sided
    // ratchet correctly refused to keep it.
    // EffectDeclarationCoverage joins the same slice as Journal and Coverage:
    // requiring it from Cli.php would pull the real Policy.php in at file
    // scope, and the JSON-refusal suites load Cli.php against a pre-declared
    // \WPrism\Policy stub — the shadow-block idiom agent/wprism.php:106-138 names.
    'Cli' => ['AdapterObservation', 'AdapterRegistry', 'AdapterSources', 'Apply', 'Canon', 'Capture', 'Code', 'Coverage', 'Db', 'Deploy', 'EffectDeclarationCoverage', 'IdentityBackup', 'Init', 'InitialStateBoundaryException', 'Journal', 'Ledger', 'Lint', 'ManifestDispositions', 'Orphans', 'Pending', 'Policy', 'PromotionLock', 'RefreshExport', 'RepositoryAuthorizationException', 'RepositoryCompilationException', 'RepositoryCompiler', 'ScopeClosure', 'ScopeContract', 'ScopedPromotionAuthority', 'ScopedStateOverlay', 'Secrets'],
    // The reader deliberately tests this bridge at runtime rather than
    // requiring it: an unavailable bridge is a stable artifact diagnostic.
    'CompiledArtifactReader' => ['CodeStateContract'],
    'Code' => ['Canon', 'CodeCompatibility', 'CodeStateContract', 'CompiledRepository', 'Db', 'Ledger', 'PromotionLock'],
    'CodeConfigGrammar' => ['Code'],
    'CodeStateContract' => ['CompiledRepository', 'OptionState'],
    'CompiledArtifact' => ['Canon', 'Code'],
    'ConvergenceVerifier' => ['CompiledRepository', 'Policy'],
    'Coverage' => ['Policy'],
    'Db' => ['TransientDbException'],
    'DeleteExecutor' => ['Db', 'Ledger'],
    'Deletion' => ['Canon', 'CompiledRepository', 'Policy', 'SidebarState', 'Snapshot'],
    'Deploy' => ['Canary', 'Code', 'CompiledRepository', 'Ledger', 'OptionState', 'Policy', 'PromotionLock', 'RepositoryCompiler'],
    'Identity' => ['Canon', 'SidebarState', 'Uuid'],
    'IdentityBackup' => ['Canon', 'Identity', 'Ledger', 'Policy', 'RepositoryCompiler', 'SidebarState', 'Snapshot', 'Uuid'],
    'IdentityNotes' => ['Snapshot', 'Uuid'],
    'Ledger' => ['Db', 'Uuid'],
    'LifecycleExecutor' => ['PromotionLock'],
    'LifecyclePlanner' => ['Code', 'CompiledRepository', 'Ledger', 'Policy'],
    'Lint' => ['Canon', 'OptionState', 'Pending', 'Policy', 'ReferenceRules'],
    // `Policy` arrives with the guarded manifests_dir() relocated here from the
    // deleted CapabilityRegistry: the call is behind `class_exists(Policy::class)`
    // precisely so a partially-loaded offline context that never includes
    // Policy.php falls through to WPRISM_MANIFESTS_DIR instead of fatalling, so it
    // is allowlisted exactly as CapabilityRegistry's identical guard was.
    'ManifestDispositions' => ['Canon', 'Policy'],
    'MenuMaterializer' => ['Db', 'Ledger', 'PlainData', 'Policy', 'Tokens'],
    'OptionState' => ['Canon'],
    'OptionsMaterializer' => ['Db'],
    'Pending' => ['Capture', 'CommandRefusalException', 'Journal', 'Policy', 'Secrets', 'Snapshot'],
    'PinResolver' => ['Policy', 'RepositoryCompiler'],
    'PlanExplanation' => ['CommandRefusalException', 'CompiledRepository', 'Deletion', 'OptionState', 'Policy', 'ReferenceGraph', 'SidebarState'],
    'Policy' => ['Canon', 'ManifestDispositions', 'OptionState'],
    'PostMaterializer' => ['Db', 'Ledger'],
    'PromotionLease' => ['Db', 'Ledger'],
    'PromotionSessionJournal' => ['Ledger'],
    'Providers' => ['Deploy', 'Policy'],
    'Publish' => ['PublicationJournal'],
    'ReferenceGraph' => ['Policy', 'SidebarState', 'Snapshot'],
    'RefreshExport' => ['Canon', 'Capture', 'Code', 'CompiledRepository', 'Deletion', 'Identity', 'Ledger', 'Policy', 'RepositoryCompiler', 'ScopeContract', 'ScopedStateOverlay'],
    'RelationshipMaterializer' => ['Db', 'Ledger'],
    'RepositoryAuthorization' => ['Canon', 'OptionState', 'Policy', 'ReferenceRules', 'RepositoryCompiler', 'SidebarState', 'Snapshot'],
    'RepositoryCompiler' => ['Canon', 'Code', 'CodeCompilationException', 'CodeStateContract', 'Policy', 'RepositoryAuthorization', 'SidebarState', 'UserMetaState'],
    'ScopeClosure' => ['CompiledRepository', 'Policy', 'SidebarState', 'UserMetaState'],
    'ScopeContract' => ['Canon', 'CompiledRepository', 'Deletion', 'Policy', 'ReferenceGraph'],
    'ScopedApply' => ['Canon', 'CompiledRepository', 'Db', 'Ledger', 'Policy', 'ReferenceGraph', 'RepositoryCompiler', 'ScopeContract', 'ScopedStateOverlay', 'SidebarState', 'Snapshot', 'Uuid'],
    'ScopedStateOverlay' => ['Canon', 'CompiledRepository', 'Policy', 'ScopeClosure', 'ScopeContract'],
    'Shortcodes' => ['Policy', 'Tokens'],
    'SidebarState' => ['Blocks', 'Canon', 'Db', 'Ledger', 'Policy', 'Snapshot', 'Tokens'],
    'Snapshot' => ['Canon', 'Db', 'IdentityNotes', 'Ledger', 'OptionState', 'Policy', 'Tokens', 'Uuid'],
    'StateHandoffVerifier' => ['Canon', 'Capture', 'CompiledRepository', 'OptionState', 'Policy'],
    'TermMaterializer' => ['Db', 'Ledger'],
    'Tokens' => ['Ledger', 'Policy'],
    'UserMetaMaterializer' => ['Db'],
];
$knownGaps = [];
foreach ($knownGapsByFile as $basename => $names) {
    foreach ($names as $name) {
        $knownGaps["$basename::$name"] = 'pre-existing bootstrap dependency; future self-require slice';
    }
}

$gaps = find_gaps($sources, $classFiles, $knownGaps);

// The Review ownership correction closed ten explicit gaps by giving the
// relocated writers their own requirements and removing Pending's DDL
// dependency (258 -> 247); retain the previous eight-pair deletion tripwire
// below that reviewed baseline rather than making real gap closure fail.
check(count($knownGaps) >= 239, 'known-gap baseline unexpectedly shrank; review the allowlist rather than hiding changes');
fwrite(STDOUT, 'known gaps: ' . count($knownGaps) . " (explicit baseline; new pairs fail)\n");
// The allowlist cannot become a dead, copy-pasted escape hatch: every entry
// must still be observed in the current baseline.  Then mutate concrete
// standalone-load fixes and prove the
// scanner would flag each one when its require_once disappears.
$baselineGaps = find_gaps($sources, $classFiles, []);
$staleAllowlist = array_diff_key($knownGaps, $baselineGaps);
check($staleAllowlist === [], 'allowlist contains no-longer-observed gaps: ' . implode(', ', array_keys($staleAllowlist)));

$mutated = $sources;
foreach ([
    ['ApplyRequestCoordinator', 'ConvergenceVerifier'],
    ['ConvergenceVerifier', 'Canon'],
    ['CapturePublicationWorkflow', 'Canary'],
    ['CapturePublicationWorkflow', 'ScopedApply'],
    ['ConvergenceVerifier', 'OptionState'],
    ['ConvergenceVerifier', 'ScopeClosure'],
    ['ConvergenceVerifier', 'ScopedApply'],
    ['ConvergenceVerifier', 'ScopedApplySession'],
    ['ScopedApply', 'ScopeClosure'],
    ['ScopedApply', 'ScopedApplySession'],
    ['AttachmentNativeMetadataGenerator', 'CompiledRepository', 'CompiledArtifact'],
    ['AttachmentNativeMetadataGenerator', 'AttachmentFilesystemTransaction'],
    ['AttachmentNativeMetadataGenerator', 'AttachmentMaterializer'],
] as $edge) {
    [$file, $dependency] = $edge;
    $requiredFile = $edge[2] ?? $dependency;
    // Since the module move (ROUND 3 TRAIN 1) a cross-module dependency is
    // spelled `__DIR__ . '/../<Module>/X.php'` while a same-module one is
    // still `/X.php`; without the optional segment this fixture would delete
    // nothing and stop proving anything.
    $pattern = "~^[ \\t]*require_once __DIR__ \\. '/(?:\\.\\./[A-Za-z0-9_]+/)?" . preg_quote($requiredFile, '~') . "\\.php';\\R~m";
    $changed = preg_replace($pattern, '', $mutated[$file], 1, $count);
    check($count === 1 && is_string($changed), "mutation fixture could not remove $file.php -> $dependency.php require_once");
    $mutated[$file] = $changed;
    $mutatedGaps = find_gaps($mutated, $classFiles, $knownGaps);
    check(isset($mutatedGaps["$file::$dependency"]), "scanner missed mutated $file::$dependency gap");
}
fwrite(STDOUT, "ok: mutations for issue #3440/3441/3442 are detected before the explicit baseline allowlist\n");

if ($gaps !== []) {
    foreach ($gaps as $key => $reason) {
        fwrite(STDERR, "gap: $key — $reason\n");
    }
    exit(1);
}

check(count($classFiles) >= 80, 'scanner discovered too few engine declarations');
fwrite(STDOUT, 'ok: every agent/src engine class reference is self-required or declared locally; ' . count($classFiles) . " declarations checked\n");

// ---------------------------------------------------------------------------
// issue #3481 (WP-11): directional layer lint.
//
// Everything above answers "does this file load what it names".  It says
// nothing about direction.  agent/src is 260 files across 18 module
// directories with no autoloader and no package boundary, and the reference
// graph above puts most of them in one strongly connected component, so the
// boundary doctrine in docs/adapter-boundary.md ("engine
// core ships generic mechanisms"; adapters sit outside it) is today a claim
// nobody can check by reading.  This section makes it mechanical: every
// agent/src file sits on exactly one rung of an ordered ladder, and a
// reference from a lower rung to a higher one is a violation.
//
// issue #3493: the rung comes straight from tools/modules.json, not a second,
// separately hand-maintained tools/layers.json.  ROUND 3 TRAIN 1 already made
// directory equal module (Canon.php now lives under agent/src/Kernel/, not
// directly under agent/src), so a file's layer was never information independent
// of tools/modules.json -- it is its module's `layer` field, expanded over
// that module's `files` list.  Measured against every one of the 225
// agent/src files at the time this was consolidated, the old tools/layers.json
// path=>layer map matched that expansion exactly (zero mismatches), which is
// what docs/modules/README.md rule 2 predicted ("a directory-level dependency
// lint replace the file-level map") and named as a deferred follow-up.
// Keeping both was the friction issue #3493 tracked: one new agent/src file
// needed a hand entry in each of two registries, checked by two independent
// gates that could silently disagree -- and one already had: the Apply
// module's own file_count sat at 26 against a 27-entry files list until the
// check below was added to catch exactly that. There is now one
// hand-maintained fact per file (which module's `files` list names it in
// tools/modules.json) and the layer is read off that module, so a new file's
// only manual edit is the one line tests/Tooling/MoveModulesTest.php's
// real-tree dry run (testPlanAgainstTheRealRepositoryIsANoOpAfterTheMove)
// already required for module membership.
//
// The violations that exist today are listed one per line in
// tools/layers-exceptions.json.  That file is a ratchet, not a mute button:
// a new upward edge fails, an entry that stopped being a violation fails so
// the list cannot rot into the copy-pasted escape hatch the allowlist above
// is guarded against, and the count may never exceed the ceiling recorded
// here.  Adding a violation therefore means editing the baseline in the same
// change, where review sees it.
//
// The graph reuses this file's scanner -- declarations() plus references()
// with namespace_aliases() -- so the layer lint and the requires check can
// never disagree about what "A references B" means.  Edges are deduplicated
// per file pair: two references into the same file are one edge.  A class
// declared in several files (today only the guarded
// InitialStateBoundaryException) yields an edge to each declaring file, which
// is why DurableFilesystem carries two recorded upward edges.
// ---------------------------------------------------------------------------

// Measured at 40 on the WP-11 baseline.  It only ever moves down: a change
// that improves the graph deletes exception lines and lowers this number in
// the same commit.
const LAYER_UPWARD_EDGE_CEILING = 40;

/** @return list<string> every PHP file under agent/src, as agent-relative paths */
function layer_source_paths(string $src): array {
    $paths = [];
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($walk as $entry) {
        if (!$entry instanceof SplFileInfo || !$entry->isFile() || $entry->getExtension() !== 'php') {
            continue;
        }
        $paths[] = 'src/' . str_replace('\\', '/', substr($entry->getPathname(), strlen($src) + 1));
    }
    sort($paths, SORT_STRING);
    return $paths;
}

/**
 * issue #3493: pure derivation of tools/layers.json's old {path => layer} shape
 * from tools/modules.json's agent modules -- no check()/exit call, so the
 * mutation self-test below can feed it a deliberately broken structure and
 * inspect what it reports instead of the process dying mid-suite. `repeated`
 * is the one malformation the eventual real-tree comparison cannot see by
 * itself: the same filename listed twice inside one module's `files` array
 * derives one path, so a copy-paste duplicate would otherwise silently
 * vanish into a single map entry instead of failing loudly.
 *
 * @param array<string,mixed> $modules decoded tools/modules.json
 * @return array{layers: array<string,string>, notes: array<string,mixed>, repeated: list<string>, malformed: list<string>}
 */
function layers_from_modules(array $modules): array {
    $layers = [];
    $repeated = [];
    $malformed = [];
    $modulesMap = (isset($modules['agent']['modules']) && is_array($modules['agent']['modules'])) ? $modules['agent']['modules'] : [];
    foreach ($modulesMap as $moduleName => $module) {
        if (!is_string($moduleName) || $moduleName === ''
            || !is_array($module) || !isset($module['layer'], $module['files'])
            || !is_string($module['layer']) || !is_array($module['files'])) {
            $malformed[] = is_string($moduleName) ? $moduleName : var_export($moduleName, true);
            continue;
        }
        $seenInModule = [];
        foreach ($module['files'] as $file) {
            if (!is_string($file) || $file === '') {
                $malformed[] = "$moduleName::" . var_export($file, true);
                continue;
            }
            if (isset($seenInModule[$file])) {
                $repeated[] = "$moduleName::$file";
                continue;
            }
            $seenInModule[$file] = true;
            $path = $moduleName === '.' ? "src/$file" : "src/$moduleName/$file";
            $layers[$path] = $module['layer'];
        }
    }
    $notes = (isset($modules['layer_notes']) && is_array($modules['layer_notes'])) ? $modules['layer_notes'] : [];
    return ['layers' => $layers, 'notes' => $notes, 'repeated' => $repeated, 'malformed' => $malformed];
}

$modulesRaw = file_get_contents($root . '/tools/modules.json');
check(is_string($modulesRaw), 'tools/modules.json is unreadable');
$modulesDecoded = json_decode($modulesRaw, true);
check(is_array($modulesDecoded), 'tools/modules.json is not a JSON object');
check(isset($modulesDecoded['ladder']) && is_array($modulesDecoded['ladder']) && $modulesDecoded['ladder'] !== [] && $modulesDecoded['ladder'] === array_values($modulesDecoded['ladder']), 'tools/modules.json ladder must be a non-empty ordered list');
$ladder = $modulesDecoded['ladder'];
$rank = array_flip($ladder);
check(isset($modulesDecoded['agent']['modules']) && is_array($modulesDecoded['agent']['modules']) && $modulesDecoded['agent']['modules'] !== [], 'tools/modules.json has no agent.modules object');

// file_count is read by nobody else -- it is prose a human skims in the
// module table, exactly the kind of decorative fact that drifts silently.
// It already had: Apply's file_count sat at 26 against a 27-entry files list
// until this check was added (issue #3493). A mismatch names the module and the
// fix is always the same: set file_count to count(files) in the same edit.
foreach ($modulesDecoded['agent']['modules'] as $moduleName => $module) {
    $files = (is_array($module) && isset($module['files']) && is_array($module['files'])) ? $module['files'] : null;
    if ($files === null) {
        continue; // reported as malformed by layers_from_modules() below.
    }
    $fileCount = is_array($module) ? ($module['file_count'] ?? null) : null;
    check(
        $fileCount === null || $fileCount === count($files),
        "tools/modules.json agent.$moduleName.file_count (" . var_export($fileCount, true) . ') disagrees with its files list (' . count($files) . ' entries); set file_count to match files in the same edit'
    );
}

// Review is an operational trust boundary, not a naming convention:
// docs/modules/agent-Review.md promises no writes, locks or publication. The
// previous placement let Pending initialize DDL, Journal flush INSERTs,
// Orphans delete/reparent rows and ConvergenceVerifier stage files while the
// dependency graph remained green. Check executable tokens in every mapped
// Review file so those behaviors cannot return under a different class name.
// RefreshExport also proved that direct tokens are insufficient: it delegated
// scratch-tree writes to ScopedStateOverlay, so mixed-authority calls use a
// read allowlist and fail closed when a new method appears.
$reviewFiles = $modulesDecoded['agent']['modules']['Review']['files'] ?? null;
check(is_array($reviewFiles) && $reviewFiles !== [], 'tools/modules.json has no populated Review module');
$reviewViolations = [];
foreach ($reviewFiles as $file) {
    $reviewSource = file_get_contents($root . '/agent/src/Review/' . $file);
    check(is_string($reviewSource), "could not read Review/$file for the read-only ownership guard");
    $effects = review_side_effects($reviewSource);
    $unapprovedCalls = review_unapproved_mixed_calls($reviewSource);
    if ($unapprovedCalls !== []) {
        $effects[] = 'unapproved mixed-authority calls ' . implode(', ', $unapprovedCalls);
    }
    if ($effects !== []) {
        $reviewViolations[] = "$file: " . implode(', ', $effects);
    }
}
check($reviewViolations === [], 'Review must stay read-only: ' . implode('; ', $reviewViolations));
check(review_side_effects(<<<'PHP'
<?php
Ledger::set($uuid, $type, $kind, $id);
Db::delete($table, $where);
Snapshot::ensure_row($policy, $entity);
Policy::set_rule($repo, $section, $key, $rule);
ScopedStateOverlay::stage_state_view($rows);
DurableFilesystem::syncFile($path);
Publish::run($repo);
Canon::write_file($path, $bytes);
mkdir($path);
$wpdb->replace($table, $row);
update_option($key, $value);
update_metadata('post', $id, $key, $value);
wp_update_post($post);
wp_set_object_terms($id, $terms, $taxonomy);
flock($handle, LOCK_SH);
PHP) === [
    'ledger mutation/initialization',
    'database write transaction/query',
    'snapshot row mutation',
    'policy mutation',
    'scoped staging/discard mutation',
    'durable filesystem mutation',
    'publication mutation',
    'canonical file write',
    'direct filesystem write',
    'direct wpdb mutation',
    'WordPress option/cache mutation',
    'WordPress metadata mutation',
    'WordPress entity mutation',
    'WordPress relationship mutation',
    'file lock',
], 'Review read-only mutation probe no longer detects every forbidden writer group');
check(
    review_unapproved_mixed_calls('<?php Capture::run($repo); Ledger::kv_set($key, $value);')
        === ['Capture::run', 'Ledger::kv_set'],
    'Review mixed-authority allowlist no longer fails closed on unapproved calls'
);
check(
    review_side_effects(
        '<?php namespace WPrism; use WPrism\\Ledger as EvidenceLedger; EvidenceLedger::set($u, $t, $k, $id);'
    ) === ['ledger mutation/initialization']
        && review_unapproved_mixed_calls(
            '<?php namespace WPrism; use WPrism\\Ledger as EvidenceLedger; EvidenceLedger::set($u, $t, $k, $id);'
        ) === ['Ledger::set'],
    'Review read-only guard can be bypassed through a same-namespace class alias'
);
check(
    review_side_effects('<?php // Ledger::set(); Db::delete();\n $literal = "Snapshot::ensure_row"; Db::rollback();') === []
        && review_unapproved_mixed_calls(
            '<?php Canon::read_file($path); Capture::gate_scan_read_only($repo, $policy, $checkpoint); '
            . 'Journal::report_read_only($policy); Policy::load($repo); Snapshot::keyspace_gaps($policy);'
        ) === []
        && review_side_effects(
            '<?php namespace WPrism; use Vendor\\Ledger; Ledger::set($u, $t, $k, $id);'
        ) === []
        && review_unapproved_mixed_calls(
            '<?php namespace WPrism; use Vendor\\Ledger; Ledger::set($u, $t, $k, $id);'
        ) === [],
    'Review read-only guard rejects comments, strings, or approved read calls'
);
fwrite(STDOUT, "ok: every mapped Review file is free of database, ledger, filesystem and lock mutation primitives\n");

$derived = layers_from_modules($modulesDecoded);
check($derived['malformed'] === [], 'tools/modules.json agent modules malformed (missing/wrong-typed layer or files): ' . implode(', ', $derived['malformed']));
check($derived['repeated'] === [], 'tools/modules.json repeats a filename within one module\'s files list: ' . implode(', ', $derived['repeated']));
$assignedLayers = $derived['layers'];
check($assignedLayers !== [], 'tools/modules.json derives no agent/src file => layer assignments');
$layerNotes = $derived['notes'];

// (v) The map and the tree agree in both directions.  An unassigned file is a
// silent hole in the lint; an assignment for a deleted file is stale prose.
$layerPaths = layer_source_paths($root . '/agent/src');
check($layerPaths !== [], 'agent/src contains no PHP files for the layer map');
$unassignedFiles = array_values(array_diff($layerPaths, array_keys($assignedLayers)));
check($unassignedFiles === [], 'tools/modules.json assigns no module to ' . implode(', ', $unassignedFiles) . "; add each to its module's \"files\" list in tools/modules.json (agent root)");
$vanishedFiles = array_values(array_diff(array_keys($assignedLayers), $layerPaths));
check($vanishedFiles === [], 'tools/modules.json assigns a module to files that no longer exist: ' . implode(', ', $vanishedFiles) . '; remove them from that module\'s "files" list');
foreach ($assignedLayers as $path => $layer) {
    check(is_string($layer) && isset($rank[$layer]), "tools/modules.json puts $path on the unknown rung " . var_export($layer, true));
}
$strayNotes = array_values(array_diff(array_keys($layerNotes), $layerPaths));
check($strayNotes === [], 'tools/modules.json layer_notes describe files no module assigns: ' . implode(', ', $strayNotes));

// Mutation self-test (issue #3493): prove the four checks above -- unassigned,
// vanished, repeated, file_count -- would actually catch the failure mode two
// independently hand-maintained registries invited: a file quietly dropped
// from (or duplicated across) modules.json's module `files` lists.
$mutationProbeModule = 'Kernel';
$mutationProbeFile = 'Canon.php';
$mutationProbePath = "src/$mutationProbeModule/$mutationProbeFile";
check(isset($assignedLayers[$mutationProbePath]), "issue #3493 mutation probe assumes $mutationProbePath is assigned; tools/modules.json's Kernel module moved or lost Canon.php");

// (a) Dropped from its module: the derived map loses the path entirely, so
// it would surface as "assigns no module to src/Kernel/Canon.php" against
// the real, on-disk $layerPaths this suite already computed above.
$droppedModules = $modulesDecoded;
$droppedModules['agent']['modules'][$mutationProbeModule]['files'] = array_values(array_diff(
    $droppedModules['agent']['modules'][$mutationProbeModule]['files'],
    [$mutationProbeFile]
));
$droppedDerived = layers_from_modules($droppedModules);
check(!isset($droppedDerived['layers'][$mutationProbePath]), "layers_from_modules() would not have caught $mutationProbePath dropped from its module's files list");
check(
    in_array($mutationProbePath, array_diff($layerPaths, array_keys($droppedDerived['layers'])), true),
    "the real-tree comparison would not have flagged $mutationProbePath as unassigned after the drop"
);

// (b) Claimed by a second module too: the path a module builds always
// embeds that module's own name, so claiming Canon.php from Policy derives
// the phantom "src/Policy/Canon.php" -- not a path collision with Kernel's
// entry, but a file that does not exist on disk, caught by the same
// real-tree comparison as a "vanished" (never-existed) assignment.
$otherModule = null;
foreach (array_keys($modulesDecoded['agent']['modules']) as $candidate) {
    if ($candidate !== $mutationProbeModule) {
        $otherModule = $candidate;
        break;
    }
}
check($otherModule !== null, 'layer mutation self-test needs a second agent module to duplicate into');
$claimedModules = $modulesDecoded;
$claimedModules['agent']['modules'][$otherModule]['files'][] = $mutationProbeFile;
$claimedDerived = layers_from_modules($claimedModules);
$phantomPath = "src/$otherModule/$mutationProbeFile";
check(isset($claimedDerived['layers'][$phantomPath]), "layers_from_modules() did not derive the expected phantom path $phantomPath");
check(
    in_array($phantomPath, array_diff(array_keys($claimedDerived['layers']), $layerPaths), true),
    "the real-tree comparison would not have flagged $phantomPath as an assignment to a file that does not exist"
);

// (c) Repeated within one module's own files list: collapses into the map
// silently unless `repeated` is checked separately from the path map.
$repeatedModules = $modulesDecoded;
$repeatedModules['agent']['modules'][$mutationProbeModule]['files'][] = $mutationProbeFile;
$repeatedDerived = layers_from_modules($repeatedModules);
check(in_array("$mutationProbeModule::$mutationProbeFile", $repeatedDerived['repeated'], true), "layers_from_modules() would not have caught $mutationProbeFile repeated in $mutationProbeModule's files list");

// (d) file_count drift: reproduces the actual pre-existing Apply bug this
// change found and fixed (26 recorded against a 27-entry files list).
$staleCountModules = $modulesDecoded;
$staleCountModules['agent']['modules'][$mutationProbeModule]['file_count']++;
$staleCount = $staleCountModules['agent']['modules'][$mutationProbeModule]['file_count'];
$realCount = count($staleCountModules['agent']['modules'][$mutationProbeModule]['files']);
check($staleCount !== $realCount, 'file_count mutation fixture must actually disagree with the files list');
fwrite(STDOUT, "ok: modules.json mutations (dropped file, file claimed by a second module, repeated filename, stale file_count) are detected\n");

$layerSources = [];
$layerDeclarations = [];
foreach ($layerPaths as $path) {
    $source = file_get_contents($root . '/agent/' . $path);
    check(is_string($source), "could not read agent/$path");
    $layerSources[$path] = $source;
    foreach (declarations($source, $path) as $name => $declaringPath) {
        $layerDeclarations[$name] ??= [];
        if (!in_array($declaringPath, $layerDeclarations[$name], true)) {
            $layerDeclarations[$name][] = $declaringPath;
        }
    }
}

$layerEdges = [];
$upwardEdges = [];
foreach ($layerSources as $path => $source) {
    foreach (references($source, $layerDeclarations, namespace_aliases($source)) as $name => $_) {
        foreach ($layerDeclarations[$name] as $target) {
            if ($target === $path) {
                continue;
            }
            $edge = "$path -> $target";
            $layerEdges[$edge] = true;
            if ($rank[$assignedLayers[$target]] > $rank[$assignedLayers[$path]]) {
                $upwardEdges[$edge] = true;
            }
        }
    }
}
$actualUpward = array_keys($upwardEdges);
sort($actualUpward, SORT_STRING);

$describeEdge = static function (string $edge) use ($assignedLayers): string {
    [$from, $to] = explode(' -> ', $edge, 2);
    return $edge . ' (' . $assignedLayers[$from] . ' -> ' . $assignedLayers[$to] . ')';
};

$exceptionsRaw = file_get_contents($root . '/tools/layers-exceptions.json');
check(is_string($exceptionsRaw), 'tools/layers-exceptions.json is unreadable');
$layerExceptions = json_decode($exceptionsRaw, true);
check(is_array($layerExceptions) && $layerExceptions === array_values($layerExceptions), 'tools/layers-exceptions.json must be a JSON list of "A -> B" strings');
$sortedExceptions = $layerExceptions;
sort($sortedExceptions, SORT_STRING);
check($sortedExceptions === $layerExceptions, 'tools/layers-exceptions.json must stay sorted so a review diff is one line per changed edge');
check(count(array_unique($layerExceptions)) === count($layerExceptions), 'tools/layers-exceptions.json repeats an entry');
foreach ($layerExceptions as $entry) {
    check(is_string($entry) && substr_count($entry, ' -> ') === 1, 'tools/layers-exceptions.json entry is not an "A -> B" string: ' . var_export($entry, true));
    [$from, $to] = explode(' -> ', $entry, 2);
    check(isset($assignedLayers[$from], $assignedLayers[$to]), "tools/layers-exceptions.json names a file no layer map assigns in '$entry'");
}

// (i) A new upward edge is the whole point of the check: it fails by name,
// with both rungs, rather than being absorbed by the baseline.
$newUpwardEdges = array_values(array_diff($actualUpward, $layerExceptions));
check($newUpwardEdges === [], 'new upward reference(s); a file may reference only its own or a lower layer: ' . implode('; ', array_map($describeEdge, $newUpwardEdges)));

// (ii) The reverse: an exception that is no longer observed means the edge is
// gone and the line is dead prose.
$staleExceptions = array_values(array_diff($layerExceptions, $actualUpward));
check($staleExceptions === [], 'tools/layers-exceptions.json lists edges that are no longer violations: ' . implode('; ', $staleExceptions) . ' — remove it, the graph improved');

// (iv) src/Kernel/ is the rung with the tightest ratchet.  ROUND 3 TRAIN 1
// moved the 28 existing kernel-layer files into it, and three of them carry
// upward edges that were already ratified in tools/layers-exceptions.json
// before the move (Canon -> Policy; DurableFilesystem -> PublicationJournal /
// Publish, an artefact of the guarded triple declaration of
// InitialStateBoundaryException).  A behaviour-preserving move may not fix
// them, so the rule is: NO Kernel upward edge beyond the ratified baseline —
// every one of those baselined Kernel edges is named here so it stays a
// visible debt, and no NEW one may be added (the baseline for src/Kernel/ can
// only shrink; a new file arriving in Kernel with an upward edge fails by
// name, exactly as before).
$kernelUpwardNew = [];
$kernelUpwardBaselined = [];
foreach ($actualUpward as $edge) {
    if (!str_starts_with($edge, 'src/Kernel/')) {
        continue;
    }
    if (in_array($edge, $layerExceptions, true)) {
        $kernelUpwardBaselined[] = $edge;
    } else {
        $kernelUpwardNew[] = $describeEdge($edge);
    }
}
check($kernelUpwardNew === [], 'src/Kernel/ must reference nothing above itself beyond the ratified baseline: ' . implode('; ', $kernelUpwardNew));
const KERNEL_UPWARD_EDGE_CEILING = 3;
$kernelExceptions = array_values(array_filter($layerExceptions, static fn (string $entry): bool => str_starts_with($entry, 'src/Kernel/')));
check(count($kernelExceptions) <= KERNEL_UPWARD_EDGE_CEILING, 'tools/layers-exceptions.json baselines ' . count($kernelExceptions) . ' src/Kernel/ edges, past the recorded ceiling of ' . KERNEL_UPWARD_EDGE_CEILING . '; a Kernel debt may only be paid down, never added');
if ($kernelUpwardBaselined !== []) {
    fwrite(STDOUT, 'layers: src/Kernel/ carries ' . count($kernelUpwardBaselined) . " baselined upward edge(s) — the first debts to burn down: " . implode('; ', $kernelUpwardBaselined) . "\n");
}

// (iii) Monotonic ratchet.  (i) and (ii) together make the baseline count
// equal to the observed violation count, so this bound is what stops a change
// from buying itself room by appending to the baseline.
check(count($layerExceptions) <= LAYER_UPWARD_EDGE_CEILING, 'tools/layers-exceptions.json holds ' . count($layerExceptions) . ' entries, past the recorded ceiling of ' . LAYER_UPWARD_EDGE_CEILING . '; lower the edge, do not raise the ceiling');
if (count($layerExceptions) < LAYER_UPWARD_EDGE_CEILING) {
    fwrite(STDOUT, 'layers: LAYER_UPWARD_EDGE_CEILING can drop to ' . count($layerExceptions) . "\n");
}

// (vi) Mutation self-tests, in the spirit of the require_once mutations above:
// prove the check bites before trusting its green.  First, drop a baseline
// entry in memory and confirm the edge resurfaces as a new violation.
check(count($layerExceptions) >= 3, 'layer mutation self-test needs at least three baseline entries');
foreach (array_unique([0, intdiv(count($layerExceptions), 2), count($layerExceptions) - 1]) as $index) {
    $mutatedExceptions = $layerExceptions;
    unset($mutatedExceptions[$index]);
    $wouldFail = array_values(array_diff($actualUpward, $mutatedExceptions));
    check($wouldFail === [$layerExceptions[$index]], 'layer lint would not have flagged the un-baselined edge ' . $layerExceptions[$index]);
}
// Second, demote a file below one of its own dependencies and confirm the
// direction comparison — not just the baseline diff — is what fails.
$demotionProbe = null;
foreach (array_keys($layerEdges) as $edge) {
    [$from, $to] = explode(' -> ', $edge, 2);
    if ($rank[$assignedLayers[$to]] > 0 && $rank[$assignedLayers[$to]] < $rank[$assignedLayers[$from]]) {
        $demotionProbe = $edge;
        break;
    }
}
check($demotionProbe !== null, 'layer graph has no downward edge to probe the direction comparison with');
[$probeFrom] = explode(' -> ', $demotionProbe, 2);
$mutatedLayers = $assignedLayers;
$mutatedLayers[$probeFrom] = $ladder[0];
$mutatedUpward = [];
foreach (array_keys($layerEdges) as $edge) {
    [$from, $to] = explode(' -> ', $edge, 2);
    if ($rank[$mutatedLayers[$to]] > $rank[$mutatedLayers[$from]]) {
        $mutatedUpward[] = $edge;
    }
}
check(in_array($demotionProbe, $mutatedUpward, true), "layer lint would not have caught $probeFrom demoted below its own dependency");
check(array_diff($mutatedUpward, $layerExceptions) !== [], 'layer lint would not have reported a demoted file as a new violation');
fwrite(STDOUT, "ok: layer mutations (un-baselined edge, demoted file) are detected\n");

$layerCensus = [];
foreach ($ladder as $rung) {
    $layerCensus[] = $rung . ' ' . count(array_keys($assignedLayers, $rung, true));
}
fwrite(STDOUT, 'layers: ' . implode(', ', $layerCensus) . "\n");
fwrite(STDOUT, sprintf("layers: %d edges, %d upward (baselined), %d new\n", count($layerEdges), count($actualUpward), count($newUpwardEdges)));

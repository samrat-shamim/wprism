<?php
/**
 * Offline characterization for the issue #3499 code-lock compile gate.
 *
 * The gate is the whole reason a split repository is expressible: before it,
 * a repository whose third-party components were not in Git simply refused to
 * compile with `canonical active plugin '<file>' has no matching plugin main
 * file` (agent/src/Code/CodeStateContract.php:61-67) and nothing could say
 * "this component is deliberately absent, and here is what it must hash to".
 *
 * The five properties pinned here are the ones the split rests on:
 *   1. migration is free — a resolved split tree compiles to a code_revision
 *      byte-identical to the same tree compiled with no lock at all;
 *   2. an absent locked component is blocking, non-forceable, and names the
 *      materialization step;
 *   3. one changed byte is a digest mismatch, not a silent acceptance;
 *   4. a component Git excludes but the lock does not declare is refused,
 *      because a fresh clone would carry neither its bytes nor a way to get
 *      them;
 *   5. a component the payload carries that is neither locked nor declared
 *      first-party is refused (`code_component_undeclared`) — Git never
 *      carries third-party code, and "vendored by omission" is exactly the
 *      shape that rule exists to refuse. A legacy v1 lock declares no
 *      first-party list, so it reaches the same refusal and the same remedy.
 */
declare(strict_types=1);

// From offline/<domain>/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/PathSafety.php';
require_once __DIR__ . '/../../../../agent/src/Code/CodeSourceLock.php';
require_once __DIR__ . '/../../../../agent/src/Code/CodeDescriptorCompiler.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Code/CodeStateContract.php';

use WPrism\CodeCompilationException;
use WPrism\CodeDescriptorCompiler;
use WPrism\CodeSourceLock;
use WPrism\CodeStateContract;
use WPrism\OptionState;

const FORMAT_1 = ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'];
const FORMAT_2 = ['format' => 2, 'layout' => 'wp-content', 'lock' => 'code/wprism-code.lock.json', 'source' => 'code/wp-content'];

$scratch = sys_get_temp_dir() . '/wprism_regress_code_lock_gate_' . bin2hex(random_bytes(6));
mkdir($scratch, 0775, true);
$repoSeq = 0;

/**
 * A repository with two plugins and one theme under code/wp-content.
 * `$components` selects which are materialized on disk.
 *
 * @param list<string> $components
 */
function make_repo(string $scratch, int &$seq, array $components = ['plugins/woocommerce', 'plugins/wprism-agency', 'themes/storefront']): string {
    $repo = $scratch . '/repo' . (++$seq);
    $source = $repo . '/code/wp-content';
    mkdir($source, 0775, true);
    $bodies = [
        'plugins/woocommerce' => [
            'woocommerce.php' => "<?php\n/**\n * Plugin Name: WooCommerce\n * Version: 11.0.0\n */\n",
            'includes/class-wc.php' => "<?php\n// wc\n",
        ],
        'plugins/wprism-agency' => [
            'wprism-agency.php' => "<?php\n/**\n * Plugin Name: WPrism Agency\n * Version: 1.0.0\n */\n",
        ],
        'themes/storefront' => [
            'style.css' => "/*\nTheme Name: Storefront\nVersion: 4.6.0\n*/\n",
        ],
    ];
    foreach ($components as $component) {
        foreach ($bodies[$component] as $relative => $body) {
            $path = $source . '/' . $component . '/' . $relative;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            file_put_contents($path, $body);
        }
    }
    return $repo;
}

/**
 * @param list<array<string,mixed>> $entries
 * @param list<string> $firstParty the components Git carries by declaration;
 *        the fixture's in-house plugin by default, because a lock that leaves
 *        it undeclared is refused (property 5) rather than silently vendored
 */
function write_lock(string $repo, array $entries, array $firstParty = ['plugins/wprism-agency']): void {
    file_put_contents($repo . '/code/wprism-code.lock.json', CodeSourceLock::encode($entries, $firstParty));
}

function entry_for(string $repo, string $root, string $component, string $version): array {
    $descriptor = CodeDescriptorCompiler::descriptor_from_source($repo . '/code/wp-content');
    return [
        'root' => $root,
        'component' => $component,
        'version' => $version,
        'origin' => [
            'kind' => 'wp-org-release',
            'url' => "https://downloads.wordpress.org/plugin/$component.$version.zip",
            'archive_sha256' => str_repeat('a', 64),
        ],
        'tree_sha256' => (string) CodeSourceLock::tree_sha256_from_descriptor($descriptor, $root, $component),
    ];
}

/** @return list<array<string,mixed>> */
function diagnostics_of(callable $compile): array {
    try {
        $compile();
    } catch (CodeCompilationException $e) {
        return $e->diagnostics;
    }
    return [];
}

/** @param list<array<string,mixed>> $diagnostics */
function codes_of(array $diagnostics): array {
    return array_values(array_unique(array_map(static fn(array $d): string => (string) $d['code'], $diagnostics)));
}

// ---------------------------------------------------------------------------
// 1. Migration is free: the split compiles to the identical code_revision.
// ---------------------------------------------------------------------------

$repo = make_repo($scratch, $repoSeq);
write_lock($repo, [entry_for($repo, 'plugins', 'woocommerce', '11.0.0'), entry_for($repo, 'themes', 'storefront', '4.6.0')]);

$unlockedDescriptor = CodeDescriptorCompiler::compile($repo, FORMAT_1);
$lockedDescriptor = CodeDescriptorCompiler::compile($repo, FORMAT_2);
wprism_check_same(
    $unlockedDescriptor,
    $lockedDescriptor,
    'a resolved lock changes nothing about the descriptor: same files, same owned_roots, same code_revision'
);
wprism_check(
    !in_array('wprism-code.lock.json', array_column((array) $lockedDescriptor['files'], 'path'), true),
    'the lock lives at code/wprism-code.lock.json, outside code/wp-content, so the descriptor never inventories it'
);

// ---------------------------------------------------------------------------
// 2. An absent locked component: blocking, non-forceable, remedy named.
// ---------------------------------------------------------------------------

$absent = make_repo($scratch, $repoSeq, ['plugins/wprism-agency', 'themes/storefront']);
copy($repo . '/code/wprism-code.lock.json', $absent . '/code/wprism-code.lock.json');

$diagnostics = diagnostics_of(static fn() => CodeDescriptorCompiler::compile($absent, FORMAT_2));
wprism_check_same(['code_component_unresolved'], codes_of($diagnostics), 'an absent locked component is code_component_unresolved');
wprism_check_same('code/wp-content/plugins/woocommerce', $diagnostics[0]['path'], 'the diagnostic names the component path');
wprism_check_same('blocking', $diagnostics[0]['severity'], 'the lock gate is blocking, like every other code diagnostic');
wprism_check(
    str_contains($diagnostics[0]['message'], 'version 11.0.0'),
    'the refusal names the exact locked version, because that is what has to be materialized'
);
wprism_check(
    str_contains($diagnostics[0]['message'], 'docs/guides/code-updates.md'),
    'the refusal names the materialization step rather than only the absence'
);

// Non-forceable is a property of the SHAPE, not of a flag that happens to be
// unset: neither entry point takes an argument that could relax the gate.
$compileParameters = array_map(
    static fn(ReflectionParameter $p): string => $p->getName(),
    (new ReflectionMethod(CodeDescriptorCompiler::class, 'compile'))->getParameters()
);
wprism_check_same(['repo', 'config'], $compileParameters, 'compile() takes a repo and a config; there is no force argument to pass');
wprism_check(
    $absent !== null && diagnostics_of(static fn() => CodeDescriptorCompiler::compile($absent, FORMAT_2)) !== [],
    'the refusal is deterministic: recompiling the same tree refuses again'
);

// The unresolved component is still absent from the descriptor's ownership, so
// nothing downstream can mistake the refusal for a shrunken-but-valid payload.
wprism_check_throws(
    static fn() => CodeDescriptorCompiler::compile($absent, FORMAT_2),
    CodeCompilationException::class,
    'the gate throws the same structured exception family the rest of the code half uses'
);

// ---------------------------------------------------------------------------
// 3. One changed byte is a digest mismatch.
// ---------------------------------------------------------------------------

$drifted = make_repo($scratch, $repoSeq);
copy($repo . '/code/wprism-code.lock.json', $drifted . '/code/wprism-code.lock.json');
CodeDescriptorCompiler::compile($drifted, FORMAT_2);
wprism_check(true, 'the untouched copy compiles before the byte is changed');

file_put_contents($drifted . '/code/wp-content/plugins/woocommerce/includes/class-wc.php', "<?php\n// wc \n");
$diagnostics = diagnostics_of(static fn() => CodeDescriptorCompiler::compile($drifted, FORMAT_2));
wprism_check_same(['code_component_digest_mismatch'], codes_of($diagnostics), 'one changed byte inside a locked component is a digest mismatch');
wprism_check(
    str_contains($diagnostics[0]['message'], 'wprism code-classify'),
    'the mismatch names both remedies: re-materialize the locked release, or re-lock these bytes'
);

// A change OUTSIDE any locked component is not the lock's business.
$sibling = make_repo($scratch, $repoSeq);
copy($repo . '/code/wprism-code.lock.json', $sibling . '/code/wprism-code.lock.json');
file_put_contents($sibling . '/code/wp-content/plugins/wprism-agency/wprism-agency.php', "<?php\n/**\n * Plugin Name: WPrism Agency\n * Version: 1.0.1\n */\n");
CodeDescriptorCompiler::compile($sibling, FORMAT_2);
wprism_check(true, 'editing a first-party (unlocked, declared) component compiles: the lock scopes itself to what it declares');

// ---------------------------------------------------------------------------
// 4. Ignored but unlocked, and the two .gitignore placements the compiler
//    already refuses or ships.
// ---------------------------------------------------------------------------

$unlocked = make_repo($scratch, $repoSeq);
write_lock($unlocked, [entry_for($unlocked, 'plugins', 'woocommerce', '11.0.0')], ['plugins/wprism-agency', 'themes/storefront']);
file_put_contents($unlocked . '/.gitignore', "/.wprism/\n/code/wp-content/plugins/woocommerce/\n/code/wp-content/plugins/wprism-agency/\n");
$diagnostics = diagnostics_of(static fn() => CodeDescriptorCompiler::compile($unlocked, FORMAT_2));
wprism_check_same(['code_component_unlocked'], codes_of($diagnostics), 'a Git-excluded component the lock does not declare is code_component_unlocked');
wprism_check_same('code/wp-content/plugins/wprism-agency', $diagnostics[0]['path'], 'the unlocked diagnostic names the excluded component');
wprism_check(
    str_contains($diagnostics[0]['message'], 'fresh clone'),
    'the refusal states the harm: a clone would carry neither the bytes nor a way to obtain them'
);

file_put_contents($unlocked . '/.gitignore', "/.wprism/\n/code/wp-content/plugins/woocommerce/\n");
CodeDescriptorCompiler::compile($unlocked, FORMAT_2);
wprism_check(true, 'removing the undeclared ignore line clears the refusal');

// NEGATIVE: a .gitignore inside code/wp-content/ is refused by the descriptor
// compiler itself (CodeDescriptorCompiler.php:97-99), which is why generated
// ignore lines are root-anchored in the repository-root file and nowhere else.
$misplaced = make_repo($scratch, $repoSeq);
write_lock($misplaced, [entry_for($misplaced, 'plugins', 'woocommerce', '11.0.0')], ['plugins/wprism-agency', 'themes/storefront']);
file_put_contents($misplaced . '/code/wp-content/.gitignore', "plugins/woocommerce/\n");
$diagnostics = diagnostics_of(static fn() => CodeDescriptorCompiler::compile($misplaced, FORMAT_2));
wprism_check_same(['unsafe_code_path'], codes_of($diagnostics), 'a .gitignore at code/wp-content/ refuses compile: the payload may hold only the three roots');
unlink($misplaced . '/code/wp-content/.gitignore');

// NEGATIVE: one inside plugins/ compiles and is SHIPPED to the target as an
// owned component file — the second reason the root file is the only supported
// placement.
file_put_contents($misplaced . '/code/wp-content/plugins/wprism-agency/.gitignore', "node_modules/\n");
$shipped = CodeDescriptorCompiler::compile($misplaced, FORMAT_2);
wprism_check(
    in_array('plugins/wprism-agency/.gitignore', array_column((array) $shipped['files'], 'path'), true),
    'a .gitignore under plugins/<component>/ is inventoried as an owned payload file and would be staged onto the target'
);
wprism_check_same(
    [],
    array_keys(CodeSourceLock::ignored_components("plugins/woocommerce/\nnode_modules/\n")),
    'and its contents are not read as component exclusions, because only the root-anchored form counts'
);

// ---------------------------------------------------------------------------
// 5. A declared lock that is not there at all is a hard failure, never a
//    lock-free fallback.
// ---------------------------------------------------------------------------

$noLock = make_repo($scratch, $repoSeq);
wprism_check_throws(
    static fn() => CodeDescriptorCompiler::compile($noLock, FORMAT_2),
    RuntimeException::class,
    'format 2 without its lock file refuses instead of compiling as if it were format 1',
    'code/wprism-code.lock.json, but it is not a regular file'
);
file_put_contents($noLock . '/code/wprism-code.lock.json', "{\"format\":\"wprism-code-lock/v1\",\"components\":[{\"root\":\"plugins\"}]}\n");
wprism_check_throws(
    static fn() => CodeDescriptorCompiler::compile($noLock, FORMAT_2),
    RuntimeException::class,
    'a malformed lock refuses by grammar before any component is compared',
    'must contain exactly component, origin, root, tree_sha256, and version'
);

// ---------------------------------------------------------------------------
// 5b. Undeclared: carried by the payload, neither locked nor first-party.
// ---------------------------------------------------------------------------

$undeclared = make_repo($scratch, $repoSeq);
write_lock($undeclared, [entry_for($undeclared, 'plugins', 'woocommerce', '11.0.0')], ['themes/storefront']);
$diagnostics = diagnostics_of(static fn() => CodeDescriptorCompiler::compile($undeclared, FORMAT_2));
wprism_check_same(['code_component_undeclared'], codes_of($diagnostics), 'a carried component that is neither locked nor first-party is code_component_undeclared');
wprism_check_same('code/wp-content/plugins/wprism-agency', $diagnostics[0]['path'], 'the undeclared diagnostic names the component');
wprism_check(
    str_contains($diagnostics[0]['message'], 'Git must not carry third-party code')
        && str_contains($diagnostics[0]['message'], '`wprism code-classify --first-party=plugins/wprism-agency`')
        && str_contains($diagnostics[0]['message'], '`wprism code-import <archive.zip>`'),
    'the refusal states the invariant and both remedies by name: declare first-party, or import the archive and re-lock'
);
wprism_check_same('blocking', $diagnostics[0]['severity'], 'undeclared is blocking, like every other lock diagnostic');
write_lock($undeclared, [entry_for($undeclared, 'plugins', 'woocommerce', '11.0.0')], ['plugins/wprism-agency', 'themes/storefront']);
CodeDescriptorCompiler::compile($undeclared, FORMAT_2);
wprism_check(true, 'declaring the component first-party clears the refusal without moving a byte');

// A legacy v1 lock declares no first_party list, so a v1 repository that still
// carries an in-house component reaches the same refusal — and its remedy,
// `wprism code-classify`, is how it gains the declaration.
$legacy = make_repo($scratch, $repoSeq, ['plugins/woocommerce', 'plugins/wprism-agency']);
$legacyEntry = entry_for($legacy, 'plugins', 'woocommerce', '11.0.0');
file_put_contents($legacy . '/code/wprism-code.lock.json', \WPrism\Canon::encode([
    'format' => CodeSourceLock::LEGACY_FORMAT,
    'components' => [$legacyEntry],
]));
$diagnostics = diagnostics_of(static fn() => CodeDescriptorCompiler::compile($legacy, FORMAT_2));
wprism_check_same(['code_component_undeclared'], codes_of($diagnostics), 'a legacy v1 lock still parses, and the component it leaves undeclared is refused');
wprism_check_same('code/wp-content/plugins/wprism-agency', $diagnostics[0]['path'], 'the v1 refusal names the undeclared component, not the locked one');

// An owned root with no file beneath it is not a component Git carries, so it
// is not undeclared either: the gate and component_inventory() agree.
$hollow = make_repo($scratch, $repoSeq, ['plugins/woocommerce']);
mkdir($hollow . '/code/wp-content/plugins/empty-dir', 0775, true);
write_lock($hollow, [entry_for($hollow, 'plugins', 'woocommerce', '11.0.0')], []);
CodeDescriptorCompiler::compile($hollow, FORMAT_2);
wprism_check(true, 'an empty component directory is neither carried nor undeclared');

// ---------------------------------------------------------------------------
// 6. CodeStateContract: the lock-aware remedy branch, and the byte-identical
//    unlocked message beside it.
// ---------------------------------------------------------------------------

$tree = [
    'options/core' => ['data' => OptionState::document([
        'active_plugins' => OptionState::present(['woocommerce/woocommerce.php'], 'yes'),
        'stylesheet' => OptionState::present('storefront', 'yes'),
        'template' => OptionState::present('storefront', 'yes'),
    ])],
];
$emptyDescriptor = ['plugin_main_files' => [], 'theme_slugs' => [], 'theme_templates' => []];
$historical = "wprism: code-stage refused — canonical active plugin 'woocommerce/woocommerce.php' has no matching plugin main file in code/wp-content/plugins";

$message = null;
try {
    CodeStateContract::validate_tree($tree, $emptyDescriptor);
} catch (RuntimeException $e) {
    $message = $e->getMessage();
}
wprism_check_same($historical, $message, 'with nothing locked the absence refusal is byte-identical to its historical text');

$message = null;
try {
    CodeStateContract::validate_tree($tree, $emptyDescriptor, [
        'plugins/woocommerce' => ['root' => 'plugins', 'component' => 'woocommerce', 'version' => '11.0.0'],
    ]);
} catch (RuntimeException $e) {
    $message = $e->getMessage();
}
wprism_check(
    is_string($message) && str_starts_with($message, $historical),
    'the lock-aware refusal keeps the historical sentence in front, so anything that greps for it still matches'
);
wprism_check(
    is_string($message) && str_contains($message, 'The code lock declares plugins/woocommerce version 11.0.0'),
    'and names the lock entry, so the operator knows this is a materialization step and not a missing plugin'
);

$message = null;
try {
    CodeStateContract::validate_tree($tree, ['plugin_main_files' => [['basename' => 'woocommerce/woocommerce.php', 'path' => 'plugins/woocommerce/woocommerce.php', 'sha256' => str_repeat('a', 64)]], 'theme_slugs' => [], 'theme_templates' => []], [
        'themes/storefront' => ['root' => 'themes', 'component' => 'storefront', 'version' => '4.6.0'],
    ]);
} catch (RuntimeException $e) {
    $message = $e->getMessage();
}
wprism_check(
    is_string($message) && str_contains($message, "canonical template 'storefront' has no matching theme directory")
        && str_contains($message, 'The code lock declares themes/storefront version 4.6.0'),
    'a locked theme gets the same remedy branch as a locked plugin'
);

// The compiler is what threads the lock into that branch; pin the call so the
// remedy cannot quietly stop being reachable from the product path.
$compilerSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Repository/RepositoryCompiler.php');
wprism_check(
    str_contains($compilerSource, '$codeLockedComponents = Code::locked_components($this->repo, $codeConfig);')
        && str_contains($compilerSource, 'CodeStateContract::validate_tree($tree, $codeDescriptor, $codeLockedComponents);'),
    'RepositoryCompiler resolves the lock beside the descriptor and passes it to the code/state bridge'
);

// Teardown: the suite's own scratch tree, never anything it did not create.
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($scratch, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $item) {
    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
}
@rmdir($scratch);

wprism_check_summary('regress_code_lock_compile_gate');

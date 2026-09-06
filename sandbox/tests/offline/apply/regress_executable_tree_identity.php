<?php
/**
 * Product-path regression for the executable-owner authoring observer.
 *
 * WooCommerce previously carried two unbounded RecursiveIterator digest
 * implementations in live/certify scripts. This suite pins the replacement:
 * one dependency-free engine identity implementation, one inert command that
 * names exactly one owner, and the same exact bounds/refusals used by deletion
 * enforcement. No policy or deletion authority is available on this surface.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';

$repoRoot = $argv[1] ?? dirname(__DIR__, 4);
$scratch = sys_get_temp_dir() . '/wprism-executable-tree-' . bin2hex(random_bytes(8));
$content = $scratch . '/wp-content';
$plugins = $content . '/plugins';
$themes = $content . '/themes';
$muPlugins = $content . '/mu-plugins';

function executable_tree_remove(string $path): void {
    clearstatcache(true, $path);
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            executable_tree_remove($path . '/' . $entry);
        }
    }
    @rmdir($path);
}

try {
    foreach ([$plugins . '/acme/inc', $plugins . '/single', $themes . '/storefront', $muPlugins . '/lib'] as $dir) {
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("could not create fixture directory $dir");
        }
    }
    file_put_contents($plugins . '/acme/acme.php', "<?php\n// main\n");
    file_put_contents($plugins . '/acme/a.php', "<?php\n// a\n");
    file_put_contents($plugins . '/acme/inc/z.php', "<?php\n// z\n");
    file_put_contents($plugins . '/single/single.php', "<?php\n// single\n");
    file_put_contents($themes . '/storefront/style.css', "Theme Name: Storefront\n");
    file_put_contents($muPlugins . '/loader.php', "<?php\n// loader\n");
    file_put_contents($muPlugins . '/lib/dependency.php', "<?php\n// dependency\n");
    file_put_contents($content . '/object-cache.php', "<?php\n// drop-in\n");

    define('WP_CONTENT_DIR', $content);
    define('WP_PLUGIN_DIR', $plugins);
    define('WPMU_PLUGIN_DIR', $muPlugins);
    if (!function_exists('get_theme_root')) {
        function get_theme_root(?string $theme = null): string {
            return WP_CONTENT_DIR . '/themes';
        }
    }

    require_once $repoRoot . '/agent/src/Kernel/ExecutableTreeIdentity.php';
    require_once $repoRoot . '/agent/src/Delete/ExecutableOwnerBoundary.php';

    $plugin = \WPrism\ExecutableOwnerBoundary::observe_owner('plugin:acme/acme.php');
    $pluginRows = [
        ['path' => 'a.php', 'sha256' => hash_file('sha256', $plugins . '/acme/a.php')],
        ['path' => 'acme.php', 'sha256' => hash_file('sha256', $plugins . '/acme/acme.php')],
        ['path' => 'inc/z.php', 'sha256' => hash_file('sha256', $plugins . '/acme/inc/z.php')],
    ];
    $expectedPluginIdentity = [
        'format' => \WPrism\ExecutableTreeIdentity::FORMAT,
        'root' => 'plugins/acme',
        'sha256' => hash('sha256', \WPrism\Canon::encode([
            'files' => $pluginRows,
            'format' => \WPrism\ExecutableTreeIdentity::FORMAT,
            'root' => 'plugins/acme',
        ])),
    ];
    wprism_check_same(
        ['owner' => 'plugin:acme/acme.php', 'code_identity' => $expectedPluginIdentity],
        $plugin,
        'the public observer returns only the canonical owner and the enforcement-path tree identity'
    );

    $before = $plugin['code_identity']['sha256'];
    file_put_contents($plugins . '/acme/inc/z.php', "<?php\n// changed\n");
    $after = \WPrism\ExecutableOwnerBoundary::observe_owner('plugin:acme/acme.php');
    wprism_check(
        !hash_equals($before, $after['code_identity']['sha256']),
        'changing one executable-tree byte changes the observed owner identity'
    );

    $theme = \WPrism\ExecutableOwnerBoundary::observe_owner('theme:storefront');
    wprism_check_same(
        ['format' => \WPrism\ExecutableTreeIdentity::FORMAT, 'root' => 'themes/storefront'],
        array_intersect_key($theme['code_identity'], ['format' => true, 'root' => true]),
        'theme observation is confined to the canonical wp-content/themes root'
    );
    $mu = \WPrism\ExecutableOwnerBoundary::observe_owner('mu-plugin:loader.php');
    wprism_check_same('mu-plugins', $mu['code_identity']['root'], 'an active top-level MU owner binds the whole MU tree');
    $dropin = \WPrism\ExecutableOwnerBoundary::observe_owner('dropin:object-cache.php');
    wprism_check_same('object-cache.php', $dropin['code_identity']['root'], 'a supported live drop-in binds its exact file');

    wprism_check_throws(
        static fn() => \WPrism\ExecutableOwnerBoundary::observe_owner('plugin:../outside.php'),
        RuntimeException::class,
        'a plugin owner containing a parent traversal is refused before filesystem observation',
        'requires one canonical owner'
    );
    wprism_check_throws(
        static fn() => \WPrism\ExecutableOwnerBoundary::observe_owner('theme:.'),
        RuntimeException::class,
        'a dot theme owner is refused instead of collapsing onto the complete themes directory',
        'requires one canonical owner'
    );
    wprism_check_throws(
        static fn() => \WPrism\ExecutableOwnerBoundary::observe_owner('mu-plugin:missing.php'),
        RuntimeException::class,
        'a named MU owner must be an executable top-level PHP file',
        'is not an active ordinary top-level file'
    );
    wprism_check_throws(
        static fn() => \WPrism\ExecutableOwnerBoundary::observe_owner('dropin:db.php'),
        RuntimeException::class,
        'a supported but absent drop-in cannot produce an authoring identity',
        'is absent, symlinked, or unreadable'
    );
    wprism_check_throws(
        static fn() => \WPrism\ExecutableTreeIdentity::observe($content, $plugins . '/acme', 'plugins/../themes'),
        RuntimeException::class,
        'the engine observer itself rejects noncanonical caller roots',
        'identity root is not canonical'
    );

    $link = $plugins . '/acme/inc/link.php';
    if (!symlink($plugins . '/acme/a.php', $link)) {
        throw new RuntimeException('could not create symlink refusal fixture');
    }
    wprism_check_throws(
        static fn() => \WPrism\ExecutableOwnerBoundary::observe_owner('plugin:acme/acme.php'),
        RuntimeException::class,
        'a symlink inside the observed tree is refused rather than followed or omitted',
        'symlinked or nonregular'
    );
    unlink($link);

    $boundedTheme = $themes . '/bounded';
    mkdir($boundedTheme);
    $cursor = $boundedTheme;
    for ($depth = 1; $depth <= 128; $depth++) {
        $cursor .= '/d';
        mkdir($cursor);
    }
    \WPrism\ExecutableOwnerBoundary::observe_owner('theme:bounded');
    wprism_check(true, 'the documented 128-level directory depth is accepted exactly');
    mkdir($cursor . '/d');
    wprism_check_throws(
        static fn() => \WPrism\ExecutableOwnerBoundary::observe_owner('theme:bounded'),
        RuntimeException::class,
        'the next directory beyond the exact depth budget is refused',
        'depth bound'
    );

    $identityReflection = new ReflectionClass(\WPrism\FilesystemTreeSnapshot::class);
    wprism_check_same(
        100000,
        $identityReflection->getReflectionConstant('MAX_TREE_ENTRIES')->getValue(),
        'the shared observer owns the exact 100000-entry traversal budget'
    );
    wprism_check_same(
        1073741824,
        $identityReflection->getReflectionConstant('MAX_TREE_BYTES')->getValue(),
        'the shared observer owns the exact 1 GiB byte budget'
    );
    $consume = $identityReflection->getMethod('consume_tree_entry');
    $entries = 99999;
    $consume->invokeArgs(null, [&$entries, 'executable owner']);
    wprism_check_same(100000, $entries, 'the final entry inside the exact traversal budget is admitted');
    wprism_check_throws(
        static function () use ($consume, &$entries): void {
            $consume->invokeArgs(null, [&$entries, 'executable owner']);
        },
        RuntimeException::class,
        'entry 100001 is refused before traversal continues',
        'entry bound'
    );
    $oneByte = $scratch . '/one-byte';
    file_put_contents($oneByte, 'x');
    $append = $identityReflection->getMethod('append_file_identity');
    $rows = [];
    $bytes = 1073741823;
    $stat = lstat($oneByte);
    $append->invokeArgs(null, [$oneByte, 'one-byte', &$rows, &$bytes, $stat, 'executable owner']);
    wprism_check_same(1073741824, $bytes, 'the final byte inside the exact byte budget is admitted');
    wprism_check_throws(
        static function () use ($append, $oneByte, &$rows, &$bytes, $stat): void {
            $append->invokeArgs(
                null,
                [$oneByte, 'one-byte-again', &$rows, &$bytes, $stat, 'executable owner']
            );
        },
        RuntimeException::class,
        'the first byte beyond 1 GiB is refused before hashing',
        'byte bound'
    );

    $replaced = $scratch . '/replaced-while-inspected';
    file_put_contents($replaced, 'old');
    $replacedStat = lstat($replaced);
    $replacement = $scratch . '/replacement';
    file_put_contents($replacement, 'new');
    rename($replacement, $replaced);
    $raceRows = [];
    $raceBytes = 0;
    wprism_check_throws(
        static function () use ($append, $replaced, &$raceRows, &$raceBytes, $replacedStat): void {
            $append->invokeArgs(
                null,
                [$replaced, 'replaced-while-inspected', &$raceRows, &$raceBytes, $replacedStat, 'executable owner']
            );
        },
        RuntimeException::class,
        'a same-size path replacement between inspection and open is refused instead of hashing another inode',
        'changed while being inspected'
    );
    wprism_check_same([], $raceRows, 'a replaced path contributes no partial identity row');
    wprism_check_same(0, $raceBytes, 'a replaced path consumes no partial byte budget');

    $grown = $scratch . '/grown-while-inspected';
    file_put_contents($grown, 'x');
    $grownStat = lstat($grown);
    file_put_contents($grown, 'y', FILE_APPEND);
    wprism_check_throws(
        static function () use ($append, $grown, &$raceRows, &$raceBytes, $grownStat): void {
            $append->invokeArgs(
                null,
                [$grown, 'grown-while-inspected', &$raceRows, &$raceBytes, $grownStat, 'executable owner']
            );
        },
        RuntimeException::class,
        'a file that grows after inspection is refused before stale size can bypass the byte bound',
        'changed while being inspected'
    );
    wprism_check_same([], $raceRows, 'a grown path contributes no partial identity row');
    wprism_check_same(0, $raceBytes, 'a grown path consumes no partial byte budget');

    $hook = $identityReflection->getProperty('testDirectoryObservationHook');
    $rosterRace = $themes . '/roster-race';
    mkdir($rosterRace);
    file_put_contents($rosterRace . '/stable.css', 'stable');
    $rosterMutationRan = false;
    $hook->setValue(null, static function (string $phase, string $directory, string $relative) use (
        &$rosterMutationRan,
        $rosterRace
    ): void {
        if (!$rosterMutationRan && $phase === 'after-directory-snapshot' && $relative === '') {
            $rosterMutationRan = true;
            file_put_contents($rosterRace . '/inserted.css', 'inserted');
        }
    });
    try {
        wprism_check_throws(
            static fn() => \WPrism\ExecutableTreeIdentity::observe(
                $content,
                $rosterRace,
                'themes/roster-race'
            ),
            RuntimeException::class,
            'a directory roster mutation at the observation boundary is refused',
            'changed while being inspected'
        );
    } finally {
        $hook->setValue(null, null);
    }
    wprism_check($rosterMutationRan, 'the deterministic directory roster race checkpoint was reached');

    $contentRace = $themes . '/content-race';
    mkdir($contentRace);
    file_put_contents($contentRace . '/style.css', 'old');
    $contentMutationRan = false;
    $hook->setValue(null, static function (string $phase, string $directory, string $relative) use (
        &$contentMutationRan,
        $contentRace
    ): void {
        if (!$contentMutationRan && $phase === 'before-directory-revalidation' && $relative === '') {
            $contentMutationRan = true;
            file_put_contents($contentRace . '/style.css', 'new');
        }
    });
    try {
        wprism_check_throws(
            static fn() => \WPrism\ExecutableTreeIdentity::observe(
                $content,
                $contentRace,
                'themes/content-race'
            ),
            RuntimeException::class,
            'a same-size same-inode content rewrite after hashing is refused',
            'changed while being inspected'
        );
    } finally {
        $hook->setValue(null, null);
    }
    wprism_check($contentMutationRan, 'the deterministic same-size content race checkpoint was reached');

    if (!class_exists('WP_CLI', false)) {
        final class WP_CLI {
            /** @var list<string> */
            public static array $lines = [];

            public static function add_command(string $name, string $class): void {}

            public static function line(string $line): void {
                self::$lines[] = $line;
            }

            public static function error(string $message): never {
                throw new RuntimeException($message);
            }
        }
    }
    require_once $repoRoot . '/agent/src/Command/Cli.php';
    WP_CLI::$lines = [];
    (new \WPrism\Cli())->executable_owner_observe([], ['owner' => 'plugin:acme/acme.php']);
    wprism_check_same(1, count(WP_CLI::$lines), 'the product command emits exactly one stdout document');
    wprism_check_same(
        rtrim(\WPrism\Canon::encode(
            \WPrism\ExecutableOwnerBoundary::observe_owner('plugin:acme/acme.php')
        )),
        WP_CLI::$lines[0],
        'the product command emits the canonical enforcement-path observation bytes'
    );
    WP_CLI::$lines = [];
    wprism_check_throws(
        static fn() => (new \WPrism\Cli())->executable_owner_observe([], [
            'owner' => 'plugin:acme/acme.php',
            'policy' => '/tmp/authority.json',
        ]),
        RuntimeException::class,
        'the authoring command refuses every flag that could widen it into a policy surface',
        'accepts only --owner'
    );
    wprism_check_same([], WP_CLI::$lines, 'a refused command emits no partial observation');

    // Integer-looking directory-map keys used to crash the shared walker
    // before the inert product command could report an owner identity.
    $numeric = $themes . '/numeric';
    foreach (['-1', '01', '1'] as $name) mkdir($numeric . '/' . $name, 0700, true);
    $numericRows = [];
    foreach (['-1/0', '0', '00', '01/0', '02', '1/0', '9223372036854775808'] as $name) {
        $value = 'literal path ' . $name;
        file_put_contents($numeric . '/' . $name, $value);
        $numericRows[] = ['path' => $name, 'sha256' => hash('sha256', $value)];
    }
    try {
        $numericSnapshot = \WPrism\FilesystemTreeSnapshot::observe(
            $content, $numeric, 'themes/numeric', 'numeric identity', 'wp-content'
        );
        wprism_check_same(['', '-1', '01', '1'], $numericSnapshot['directories'],
            'numeric directory names retain exact string spelling and lexical order');
        wprism_check_same(array_column($numericRows, 'path'), array_column($numericSnapshot['files'], 'path'),
            'numeric file names, leading zeroes and integer overflow spellings remain distinct strings');
        WP_CLI::$lines = [];
        (new \WPrism\Cli())->executable_owner_observe([], ['owner' => 'theme:numeric']);
        $numericAnswer = json_decode(WP_CLI::$lines[0], true, 32, JSON_THROW_ON_ERROR);
        wprism_check_same(hash('sha256', \WPrism\Canon::encode([
            'files' => $numericRows, 'format' => \WPrism\ExecutableTreeIdentity::FORMAT, 'root' => 'themes/numeric',
        ])), $numericAnswer['code_identity']['sha256'],
            'the actual owner-observe command hashes every literal numeric path without a compatibility projection');
    } catch (Throwable $error) {
        wprism_check(false, 'numeric paths must produce a product observation, not ' . get_class($error) . ': ' . $error->getMessage());
    }

    $boundarySource = (string) file_get_contents($repoRoot . '/agent/src/Delete/ExecutableOwnerBoundary.php');
    $liveSource = (string) file_get_contents(
        $repoRoot . '/adapter-packages/woocommerce/tests/live/regress_woocommerce_scoped_deletion.sh'
    );
    $multisiteSource = (string) file_get_contents(
        $repoRoot . '/adapter-packages/woocommerce/tests/live/regress_woocommerce_multisite_refusal.sh'
    );
    $wooOfflineSource = (string) file_get_contents(
        $repoRoot . '/adapter-packages/woocommerce/tests/offline/regress_woocommerce_deletion_authority.php'
    );
    $certifySource = (string) file_get_contents(
        $repoRoot . '/adapter-packages/woocommerce/tests/certify/version-matrix.sh'
    );
    $certifyOwnerFunction = '';
    if (preg_match(
        '/woocommerce_deletion_owner_agreements\(\) \{(?<body>.*?)\n\}\n\nVMATRIX_PLUGIN_SLUG/s',
        $certifySource,
        $certifyOwnerMatch
    ) === 1) {
        $certifyOwnerFunction = $certifyOwnerMatch['body'];
    }
    $multisiteObserverFunction = '';
    if (preg_match(
        '/woo_observed_plugin_sha\(\) \{(?<body>.*?)\n\}\n\nwoo_plugin_identity/s',
        $multisiteSource,
        $multisiteObserverMatch
    ) === 1) {
        $multisiteObserverFunction = $multisiteObserverMatch['body'];
    }
    wprism_check(
        substr_count($boundarySource, 'ExecutableTreeIdentity::observe(') >= 4
            && !str_contains($boundarySource, 'function tree_identity(')
            && !str_contains($boundarySource, 'MAX_TREE_DEPTH')
            && !str_contains($boundarySource, 'MAX_TREE_BYTES'),
        'deletion enforcement consumes the shared observer and owns no second digest walker or digest budget'
    );
    $phpFilesStart = strpos($boundarySource, 'private static function php_files(');
    $phpFilesEnd = $phpFilesStart === false
        ? false
        : strpos($boundarySource, 'private static function assert_plugin_owner(', $phpFilesStart);
    $phpFilesSource = $phpFilesStart !== false && $phpFilesEnd !== false
        ? substr($boundarySource, $phpFilesStart, $phpFilesEnd - $phpFilesStart)
        : '';
    $muBudgetCheck = strpos($phpFilesSource, 'self::consume_owner_roster_entry($entries);');
    $muPhpFilter = strpos($phpFilesSource, "str_ends_with(strtolower(\$entry), '.php')");
    wprism_check(
        str_contains($phpFilesSource, '@opendir($root)')
            && str_contains($phpFilesSource, 'readdir($handle)')
            && !str_contains($phpFilesSource, 'scandir(')
            && $muBudgetCheck !== false
            && $muPhpFilter !== false
            && $muBudgetCheck < $muPhpFilter,
        'generic MU owner discovery streams entries and charges non-PHP names before roster filtering'
    );
    foreach ([
        'live scoped-deletion extension' => $liveSource,
        'certification matrix owner function' => $certifyOwnerFunction,
    ] as $label => $source) {
        wprism_check(
            str_contains($source, 'wprism executable-owner-observe')
                && str_contains($source, 'stylesheet="$(')
                && str_contains($source, 'template="$(')
                && str_contains(
                    $source,
                    'active_themes="$(printf \'%s\\n\' "$stylesheet" "$template" | LC_ALL=C sort -u)"'
                )
                && !str_contains($source, 'active_themes="$({')
                && !str_contains($source, 'RecursiveIteratorIterator')
                && !str_contains($source, 'hash_file("sha256"')
                && !str_contains($source, "hash_file('sha256'"),
            "WooCommerce $label independently observes both active themes before consuming the product observer and contains no independent executable-tree hash"
        );
    }
    wprism_check(
        str_contains($multisiteObserverFunction, 'wprism executable-owner-observe')
            && !str_contains($multisiteObserverFunction, 'RecursiveIteratorIterator')
            && !str_contains($multisiteObserverFunction, 'hash_file("sha256"')
            && !str_contains($multisiteObserverFunction, "hash_file('sha256'"),
        'WooCommerce multisite immutability evidence consumes the product observer and contains no independent executable-tree hash'
    );
    wprism_check(
        substr_count($wooOfflineSource, 'ExecutableOwnerBoundary::observe_owner(') >= 4
            && !str_contains($wooOfflineSource, 'function woo_fixture_code_identity(')
            && !str_contains($wooOfflineSource, 'hash_file("sha256"')
            && !str_contains($wooOfflineSource, "hash_file('sha256'"),
        'WooCommerce offline agreement fixtures consume the shared observer instead of carrying an adapter-owned digest implementation'
    );
} finally {
    executable_tree_remove($scratch);
}

wprism_check_summary('executable-tree identity is bounded engine machinery with an inert authoring product path');

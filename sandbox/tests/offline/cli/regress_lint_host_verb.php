<?php
/**
 * WP-2.4(a): `duo lint-tree` is the suspicious-reference linter as a
 * WordPress-free host verb, and its findings are the live verb's byte for byte.
 *
 * The claim is not "these two agree today". It is that there is ONE
 * implementation — `Lint::scan_tree()` — and that everything it reads which is
 * not a byte of the state tree reaches it through one recorded object, so the
 * two verbs cannot drift apart without the recording itself changing. This
 * suite is built to make that claim falsifiable rather than plausible:
 *
 *   - the LIVE half is the real `Cli::lint()` handler, driven through a WP_CLI
 *     stub. Its flag parsing, its transcript write, its rendering and its exit
 *     code are the shipped product path, not a re-spelling of it;
 *   - the HOST half is the real `php cli/duo lint-tree` binary, run as a
 *     SUBPROCESS. Nothing is stubbed into it, and there is no WordPress in it
 *     to stub;
 *   - the comparison is on raw stdout bytes of `--format=json`, plus the
 *     human rendering, in both directions;
 *   - one child run is POISONED: `get_option()`, `untrailingslashit()` and a
 *     `$wpdb` are defined in the child as functions that throw. A host verb
 *     that reached for the environment instead of the transcript would die
 *     there. Producing identical findings under the poison is the positive
 *     form of "WordPress-free", rather than the absence of an error.
 *
 * WHAT IS DELIBERATELY NOT IDENTICAL, and is asserted as such: WordPress's own
 * `parse_blocks()` does not exist in a host process, and its output is a
 * function of state-tree bytes rather than of the environment, so it cannot be
 * recorded either. Tree B below carries block markup, the live half parses it
 * with the vendored REAL WordPress parser
 * (`sandbox/tests/support/wp-block-parser-stub.php`) and reports a finding, and
 * the host half reports the class as DEFERRED on stderr instead of reporting
 * nothing. The difference between the two outputs is asserted to be exactly
 * that class, and the deferral text is asserted to be the engine's own
 * sentence — the same discipline `duo manifest-validate` established: a tool
 * that lists its limitations only on failure lets silence read as "verified".
 */
declare(strict_types=1);

namespace {
    /** `WP_CLI::halt()` never returns; this is how the suite observes the exit. */
    final class LintHostHalt extends RuntimeException {
        public function __construct(public int $status) {
            parent::__construct("halt:$status");
        }
    }

    /** Reaching `WP_CLI::error()` at all is a refusal this suite did not expect. */
    final class LintHostError extends RuntimeException {}

    final class WP_CLI {
        /** @var list<string> */
        public static array $lines = [];

        public static function add_command($name, $class): void {}

        public static function line($line): void {
            self::$lines[] = (string) $line;
        }

        public static function warning($message): void {
            self::$lines[] = 'warning:' . (string) $message;
        }

        public static function success($message): void {
            self::$lines[] = 'success:' . (string) $message;
        }

        public static function halt($status): void {
            throw new LintHostHalt((int) $status);
        }

        public static function error($message, $exit = true): void {
            throw new LintHostError((string) $message);
        }

        public static function reset(): void {
            self::$lines = [];
        }
    }
}

namespace {
    // From offline/<domain>/: two hops to the corpus root, four to the repo root.
    require_once __DIR__ . '/../../lib/check.php';
    require_once __DIR__ . '/../../lib/wp_stubs.php';
    require_once __DIR__ . '/../../lib/FakeWpdb.php';
    // The REAL WordPress block grammar, vendored, so the live half of the
    // block-class comparison is WordPress's own parse and not a stand-in.
    require_once __DIR__ . '/../../support/wp-block-parser-stub.php';

    $root = dirname(__DIR__, 4);

    $agentSource = (string) file_get_contents($root . '/agent/duo.php');
    if (preg_match("/define\('DUO_SPEC_VERSION', ([0-9]+)\)/", $agentSource, $m) !== 1) {
        fwrite(STDERR, "FAIL: could not resolve DUO_SPEC_VERSION from agent/duo.php\n");
        exit(1);
    }
    define('DUO_SPEC_VERSION', (int) $m[1]);
    if (preg_match("/define\('DUO_AGENT_VERSION', '([^']+)'\)/", $agentSource, $m) !== 1) {
        fwrite(STDERR, "FAIL: could not resolve DUO_AGENT_VERSION from agent/duo.php\n");
        exit(1);
    }
    define('DUO_AGENT_VERSION', $m[1]);

    require_once $root . '/agent/src/Kernel/Canon.php';
    require_once $root . '/agent/src/Kernel/OptionState.php';
    require_once $root . '/agent/src/Policy/Policy.php';
    require_once $root . '/agent/src/Review/Lint.php';
    require_once $root . '/agent/src/Command/Cli.php';

    use Duo\Canon;
    use Duo\Cli;
    use Duo\LintEnvironment;
    use DuoTest\FakeWpdb;
    use DuoTest\WpStore;

    const LINT_HOST_HOME = 'https://lint-host.test';

    $tmp = sys_get_temp_dir() . '/duo_lint_host_verb_' . bin2hex(random_bytes(6));
    mkdir($tmp, 0777, true);
    register_shutdown_function(static function () use ($tmp): void {
        if (!is_dir($tmp)) {
            return;
        }
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($walk as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($tmp);
    });

    /**
     * A site repo pinning only the SHIPPED `core` manifest, so both halves load
     * one policy out of one manifests directory — the real one. A frozen or
     * synthetic policy would have made the comparison a statement about the
     * fixture rather than about the two verbs.
     */
    function lint_host_repo(string $tmp, string $name, string $body): string {
        $repo = $tmp . '/' . $name;
        mkdir($repo . '/state/posts/post', 0777, true);
        file_put_contents($repo . '/site.duo.json', Canon::encode([
            'manifests' => ['core'],
            'policy' => [
                'options' => (object) [],
                'post_meta' => (object) [],
                'post_types' => ['post', 'page'],
                'taxonomies' => ['category'],
                'term_meta' => (object) [],
            ],
            'spec_version' => DUO_SPEC_VERSION,
        ]));
        file_put_contents(
            $repo . '/state/posts/post/44444444-4444-5444-8444-444444444444--fixture.md',
            Canon::post_file([
                'meta' => [
                    // bare_id: an undeclared meta key holding a number that is a
                    // real post id on this environment.
                    'duo_related' => 3,
                    // escaped_home: the JSON-escaped form tokenize_text() cannot match.
                    'duo_blob' => '{"url":"https:\\/\\/lint-host.test\\/about"}',
                    // unrewritten_url_query_ref: WordPress's own ?p= id scheme.
                    'duo_link' => LINT_HOST_HOME . '/?p=1',
                ],
                'terms' => (object) [],
                'type' => 'post',
            ], $body)
        );
        return $repo;
    }

    // Tree A: no block or shortcode content at all, so every class the scan can
    // produce is one both processes can perform.
    $repo = lint_host_repo($tmp, 'repo-a', '');
    // Tree B: block markup, the one class a host process structurally cannot read.
    $repoBlocks = lint_host_repo(
        $tmp,
        'repo-b',
        "<!-- wp:core/navigation-link {\"id\":3,\"label\":\"About\"} /-->\n"
    );

    WpStore::reset()->seedOptions(['home' => LINT_HOST_HOME]);
    $wpdb = FakeWpdb::install();
    $wpdb->seedTable('wp_posts', [
        ['ID' => 1, 'post_type' => 'post', 'post_title' => 'Hello world!', 'post_status' => 'publish'],
        ['ID' => 3, 'post_type' => 'page', 'post_title' => 'Sample Page', 'post_status' => 'publish'],
    ]);

    /**
     * Run the REAL `wp duo lint` handler and return what it printed.
     *
     * @param array<string,string> $assoc
     * @return array{lines:list<string>,status:int}
     */
    function lint_live(array $assoc): array {
        WP_CLI::reset();
        $status = 0;
        try {
            (new Cli())->lint([], $assoc);
        } catch (LintHostHalt $halt) {
            $status = $halt->status;
        }
        return ['lines' => WP_CLI::$lines, 'status' => $status];
    }

    /**
     * Run the REAL host binary as a subprocess.
     *
     * @param list<string> $args
     * @return array{stdout:string,stderr:string,status:int}
     */
    function lint_tree(string $root, array $args, ?string $prepend = null): array {
        $command = [PHP_BINARY];
        if ($prepend !== null) {
            $command[] = '-d';
            $command[] = 'auto_prepend_file=' . $prepend;
        }
        $command[] = $root . '/cli/duo';
        $command[] = 'lint-tree';
        foreach ($args as $arg) {
            $command[] = $arg;
        }
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            implode(' ', array_map('escapeshellarg', $command)),
            $descriptors,
            $pipes
        );
        if (!is_resource($process)) {
            return ['stdout' => '', 'stderr' => 'proc_open failed', 'status' => 127];
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['stdout' => $stdout, 'stderr' => $stderr, 'status' => proc_close($process)];
    }

    // ------------------------------------------------ 1. record, replay, compare

    $environmentFile = $tmp . '/lint-environment.json';
    $live = lint_live(['repo' => $repo, 'format' => 'json', 'emit-environment' => $environmentFile]);
    duo_check_same(1, $live['status'], 'the live verb exits 1 with findings, its unchanged contract');
    duo_check_same(1, count($live['lines']), 'the live verb prints exactly one JSON line');
    $liveJson = $live['lines'][0];
    $liveFindings = json_decode($liveJson, true);
    duo_check_same(3, count((array) $liveFindings), 'the fixture produces the three recordable classes');
    duo_check_same(
        ['bare_id', 'escaped_home', 'unrewritten_url_query_ref'],
        array_values(array_unique(array_column((array) $liveFindings, 'class'))),
        'bare_id, escaped_home and unrewritten_url_query_ref, in scan order'
    );

    duo_check(is_file($environmentFile), '--emit-environment wrote the transcript');
    $document = json_decode((string) file_get_contents($environmentFile), true);
    duo_check_same(
        'duo-lint-environment/v1',
        (string) ($document['format'] ?? ''),
        'the transcript declares its own envelope'
    );
    duo_check_same(
        LINT_HOST_HOME,
        (string) ($document['home'] ?? ''),
        "the transcript records get_option('home'), which the state tree does NOT carry — core.json declares "
        . 'home class:env, so capture excludes it'
    );
    duo_check_same(
        [['id' => 1, 'resolved' => ['id' => 1, 'kind' => 'post', 'post_type' => 'post', 'title' => 'Hello world!']],
         ['id' => 3, 'resolved' => ['id' => 3, 'kind' => 'post', 'post_type' => 'page', 'title' => 'Sample Page']]],
        (array) ($document['entities'] ?? []),
        'every id lookup the scan made is recorded, id-sorted, in Pending::resolve_id()\'s own shape'
    );
    duo_check(
        is_string($document['state_hash'] ?? null) && str_starts_with((string) $document['state_hash'], 'sha256:'),
        'the transcript names the state tree it was recorded over'
    );

    $host = lint_tree($root, [$repo, '--environment=' . $environmentFile, '--format=json']);
    duo_check_same(
        $liveJson . "\n",
        $host['stdout'],
        'THE CLAIM: the host verb\'s --format=json stdout is the live verb\'s output, byte for byte'
    );
    duo_check_same(1, $host['status'], 'the host verb reproduces the live exit code as well as the bytes');

    // The human rendering travels through the same LintFinding::render_lines(),
    // so it is comparable too — the live half adds only WP-CLI's own warning
    // envelope after the shared lines.
    $liveHuman = lint_live(['repo' => $repo]);
    $hostHuman = lint_tree($root, [$repo, '--environment=' . $environmentFile]);
    $liveRendered = array_slice($liveHuman['lines'], 0, -1);
    duo_check_same(
        implode("\n", $liveRendered) . "\n",
        $hostHuman['stdout'],
        'the human rendering matches too: one renderer, two callers'
    );
    duo_check_same(
        'warning:3 finding(s) — review before trusting a byte-identical round trip',
        (string) end($liveHuman['lines']),
        'the live verb\'s own WP-CLI summary line is unchanged by the extraction'
    );

    // ------------------------------------------------ 2. WordPress-free, positively

    $poison = $tmp . '/poison.php';
    file_put_contents($poison, <<<'PHP'
<?php
/**
 * Every environment read `Lint::scan_tree()` used to make, defined in the CHILD
 * as a function that throws. A host verb that reached for the environment
 * instead of the recorded transcript dies here rather than quietly answering
 * from a process that has no WordPress in it.
 *
 * parse_blocks()/get_shortcode_regex()/shortcode_parse_atts() are deliberately
 * NOT poisoned: their absence is what the deferral reports, and defining them
 * at all would change what the host verb decides it can scan.
 */
function get_option($name, $default = false) {
    throw new RuntimeException("duo lint-tree read the live environment: get_option($name)");
}
function untrailingslashit($value) {
    throw new RuntimeException('duo lint-tree normalized a live home URL');
}
$GLOBALS['wpdb'] = new class {
    public function get_row($query, $output = null) {
        throw new RuntimeException('duo lint-tree issued a database read');
    }
    public function get_results($query, $output = null) {
        throw new RuntimeException('duo lint-tree issued a database read');
    }
    public function prepare($query, ...$args) {
        throw new RuntimeException('duo lint-tree prepared a database read');
    }
};
PHP);
    $poisoned = lint_tree($root, [$repo, '--environment=' . $environmentFile, '--format=json'], $poison);
    duo_check_same(
        $liveJson . "\n",
        $poisoned['stdout'],
        'with every live environment read poisoned to throw, the host verb still produces the identical findings — '
        . 'it answers from the transcript, not from a target'
    );
    duo_check_same(1, $poisoned['status'], 'and still exits 1');

    // ------------------------------------------------ 3. the boundary it cannot cross

    $blockEnvironment = $tmp . '/lint-environment-blocks.json';
    $liveBlocks = lint_live(['repo' => $repoBlocks, 'format' => 'json', 'emit-environment' => $blockEnvironment]);
    $liveBlockFindings = json_decode($liveBlocks['lines'][0] ?? '[]', true);
    duo_check_same(
        4,
        count((array) $liveBlockFindings),
        'with WordPress\'s real parser the live verb adds the block-attribute finding'
    );
    // `core` declares a block_attrs rule for core/navigation-link.id, so the
    // extra finding is the DECLARED-ref twin (`unrewritten_registered_ref` at
    // a `blocks.` locator) rather than `unregistered_block_attr` — the more
    // interesting half of the pair to lose silently, since it means a declared
    // rewrite did not run.
    duo_check_same(
        ['unrewritten_registered_ref'],
        array_values(array_map(
            static fn(array $f): string => $f['class'],
            array_filter((array) $liveBlockFindings, static fn(array $f): bool => str_starts_with($f['locator'], 'blocks.'))
        )),
        'the block class is exactly what the live half found and the host half cannot'
    );
    duo_check_same(
        true,
        (bool) (json_decode((string) file_get_contents($blockEnvironment), true)['scanned']['blocks'] ?? false),
        'the transcript records that the RECORDING process parsed blocks'
    );

    $hostBlocks = lint_tree($root, [$repoBlocks, '--environment=' . $blockEnvironment, '--format=json']);
    $hostBlockFindings = json_decode($hostBlocks['stdout'], true);
    duo_check_same(
        3,
        count((array) $hostBlockFindings),
        'the host half reports the three classes it can perform'
    );
    duo_check_same(
        array_values(array_filter(
            (array) $liveBlockFindings,
            static fn(array $f): bool => !str_starts_with($f['locator'], 'blocks.')
        )),
        (array) $hostBlockFindings,
        'and every one of them is byte-identical to the live verb\'s: the difference between the two outputs is '
        . 'exactly the class the host cannot perform, never a subtly different reading of a class it can'
    );
    duo_check(
        str_contains($hostBlocks['stderr'], 'WordPress parse_blocks() is unavailable in this process'),
        'the host names the deferred class in the ENGINE\'s own words, on stderr, so silence cannot read as clean'
    );
    duo_check(
        str_contains($hostBlocks['stderr'], 'unregistered_block_attr / unrewritten_registered_ref'),
        'and names which finding classes went unreported'
    );
    duo_check(
        !str_contains($hostBlocks['stderr'], 'shortcode_parse_atts'),
        'the shortcode class is NOT deferred: `core` declares no shortcode_attrs, so that scan had nothing to do '
        . 'either way and a deferral would have been a blanket disclaimer rather than a report'
    );
    duo_check(
        str_contains($host['stderr'], 'deferred:     nothing'),
        'on a tree with no block or shortcode content the host reports nothing deferred — the list is a report, '
        . 'not boilerplate'
    );
    duo_check(
        str_contains($host['stderr'], 'duo lint-tree (host, no WordPress)')
        && str_contains($host['stderr'], $environmentFile)
        && str_contains($host['stderr'], '2 recorded id lookups replayed'),
        'every run states its provenance: which transcript, which home, how many replayed lookups'
    );

    // ------------------------------------------------ 4. the two refusals that keep a replay honest

    // The mutation deliberately removes a finding without changing a single id
    // the scan asks about: the escaped home URL becomes a plain one. A replay
    // that did not check the tree would answer every recorded lookup happily
    // and report a strictly SMALLER finding set — the false-green shape, not an
    // error. That is what the digest is for, and it is why this case does not
    // move an id (which the "no recorded answer" refusal would catch anyway).
    $mutated = $repo . '/state/posts/post/44444444-4444-5444-8444-444444444444--fixture.md';
    $original = (string) file_get_contents($mutated);
    // The canonical file holds the escaped host twice-escaped (`https:\\/\\/`),
    // since the meta VALUE is itself a JSON document.
    $escapedInFile = 'https:\\\\/\\\\/lint-host.test';
    duo_check(str_contains($original, $escapedInFile), 'the fixture really carries the escaped-home bytes to remove');
    file_put_contents($mutated, str_replace($escapedInFile, 'https://elsewhere.test', $original));
    $stale = lint_tree($root, [$repo, '--environment=' . $environmentFile, '--format=json']);
    duo_check_same(2, $stale['status'], 'a transcript replayed over different state-tree bytes is refused');
    duo_check(
        str_contains($stale['stderr'], 'recorded over a different state tree'),
        'and the refusal says so, naming both digests — the alternative is a silently smaller finding set'
    );
    duo_check_same('', $stale['stdout'], 'a refused run prints no findings at all');
    file_put_contents($mutated, $original);

    // A policy that asks a question the recording never asked: the transcript
    // holds no answer, and guessing null would report FEWER findings than the
    // live scan. Rebuild the same tree with one extra numeric meta key.
    $widened = lint_host_repo($tmp, 'repo-widened', '');
    $widenedFile = $widened . '/state/posts/post/44444444-4444-5444-8444-444444444444--fixture.md';
    file_put_contents(
        $widenedFile,
        str_replace('"duo_related": 3', '"duo_other": 7,' . "\n" . '  "duo_related": 3', (string) file_get_contents($widenedFile))
    );
    $widenedEnvironment = $tmp . '/lint-environment-widened.json';
    lint_live(['repo' => $widened, 'format' => 'json', 'emit-environment' => $widenedEnvironment]);
    // Now hand the WIDENED transcript's own tree the NARROW transcript is not
    // about — same shape of mistake, caught by the state digest first.
    $crossed = lint_tree($root, [$widened, '--environment=' . $environmentFile, '--format=json']);
    duo_check_same(2, $crossed['status'], 'a transcript from another repo is refused before a finding is computed');

    duo_check_throws(
        static fn() => LintEnvironment::recorded(['format' => 'duo-lint-environment/v1', 'home' => 'x',
            'entities' => [], 'scanned' => ['blocks' => true, 'shortcodes' => true]]),
        RuntimeException::class,
        'a transcript with no state_hash is refused: nothing could then check which tree it describes',
        'no canonical state_hash'
    );
    $replay = LintEnvironment::recorded($document);
    duo_check_throws(
        static fn() => $replay->resolve_id(4242),
        RuntimeException::class,
        'a replay REFUSES an id it holds no answer for rather than resolving it to null',
        'no recorded answer for id 4242'
    );
    duo_check_same(
        null,
        $replay->resolve_id(0),
        'a non-positive id short-circuits without a recorded answer, exactly as Pending::resolve_id() does'
    );

    // ------------------------------------------------ 5. the verb's own argument surface

    duo_check_same(
        2,
        lint_tree($root, [$repo, '--format=json'])['status'],
        '--environment is required: without it the host has no home URL and no way to resolve an id'
    );
    duo_check(
        str_contains(lint_tree($root, [$repo])['stderr'], 'facts about a live target'),
        'and the refusal explains why, naming the command that records them'
    );
    duo_check(
        str_contains(
            lint_tree($root, [$repo, '--environment=' . $environmentFile, '--evidence=probe.json'])['stderr'],
            '--evidence belongs on the live verb'
        ),
        'a probe cannot be supplied to the replay: column types differing from the recording would make the two '
        . 'verbs disagree for a reason that is not about the tree'
    );
    duo_check_same(
        2,
        lint_tree($root, [$repo, '--environment=' . $environmentFile, '--environment=' . $environmentFile])['status'],
        'a repeated flag is refused rather than last-wins, the posture every host verb here takes'
    );
    duo_check_same(
        2,
        lint_tree($root, [$tmp, '--environment=' . $environmentFile])['status'],
        'a directory with no site.duo.json is not a site repo'
    );
    duo_check(
        str_contains(lint_tree($root, ['--environment=' . $environmentFile])['stderr'], '<site-repo> argument is required'),
        'the repo argument is required'
    );
    duo_check_same(
        2,
        lint_tree($root, [$repo, '--environment=' . $tmp . '/nope.json'])['status'],
        'an unreadable transcript is a usage failure, not an empty environment'
    );

    // The verb name is not `lint`, and that is load-bearing: `duo lint <env>`
    // is an existing environment-bound passthrough (cli/duo:1379) that this
    // command must not shadow.
    $cliSource = (string) file_get_contents($root . '/cli/duo');
    duo_check(
        str_contains($cliSource, "'capabilities', 'lint', 'plan', 'explain', 'apply', 'coverage' => cmd_passthrough"),
        'the environment-bound `duo lint <env>` passthrough is still routed to the transport'
    );

    duo_check_summary('regress_lint_host_verb');
}

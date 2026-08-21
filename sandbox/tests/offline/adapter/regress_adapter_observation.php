<?php
/**
 * DUO-3340 offline regression for the closed, redacted adapter observation.
 *
 * This deliberately uses a fake wpdb/WP hook surface instead of a live pair:
 * it proves the target projection's no-DML/read-error boundary and the host's
 * strict transport/schema/create-only boundary without claiming a two-env
 * exercise. The live bootstrap boundary is represented precisely: normal
 * bootstrap can buffer a journal observation, then adapter-observe must detach
 * and discard it before either a refused command or provider negotiation can
 * cause Journal::flush() to insert provenance.
 */
declare(strict_types=1);

namespace {
    if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
    define('DUO_AGENT_VERSION', '0.5.0');
    define('DUO_SPEC_VERSION', 2);

    final class ObservationCliHalt extends RuntimeException {}

    /**
     * Minimal WP-CLI surface to run Cli::adapter_observe's refusal path, plus
     * the warning/success channels DUO-3497's journal-reset guard writes on.
     */
    final class WP_CLI {
        /** @var list<string> */
        public static array $errors = [];
        /** @var list<string> */
        public static array $warnings = [];
        /** @var list<string> */
        public static array $successes = [];

        public static function add_command(mixed $name, mixed $class): void {}
        public static function line(mixed $line): void {}
        public static function halt(mixed $status): never { throw new ObservationCliHalt((string) $status); }
        public static function warning(mixed $message): void { self::$warnings[] = (string) $message; }
        public static function success(mixed $message): void { self::$successes[] = (string) $message; }
        public static function error(mixed $message): never {
            self::$errors[] = (string) $message;
            throw new ObservationCliHalt((string) $message);
        }
    }

    $GLOBALS['duo_observation_hooks'] = [
        'filters' => [], 'actions' => [], 'removed_filters' => [], 'removed_actions' => [],
    ];

    function get_option(string $name): bool { return false; }
    function is_serialized(mixed $value): bool { return false; }
    function add_filter(string $hook, mixed $callback, int $priority = 10): void {
        $GLOBALS['duo_observation_hooks']['filters'][] = [$hook, $callback, $priority];
    }
    function add_action(string $hook, mixed $callback, int $priority = 10): void {
        $GLOBALS['duo_observation_hooks']['actions'][] = [$hook, $callback, $priority];
    }
    function remove_filter(string $hook, mixed $callback, int $priority = 10): void {
        $GLOBALS['duo_observation_hooks']['removed_filters'][] = [$hook, $callback, $priority];
    }
    function remove_action(string $hook, mixed $callback, int $priority = 10): void {
        $GLOBALS['duo_observation_hooks']['removed_actions'][] = [$hook, $callback, $priority];
    }

    final class ObservationFakeWpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public string $options = 'wp_options';
        public string $postmeta = 'wp_postmeta';
        public string $termmeta = 'wp_termmeta';
        public string $usermeta = 'wp_usermeta';
        public string $posts = 'wp_posts';
        public string $terms = 'wp_terms';
        public string $term_taxonomy = 'wp_term_taxonomy';
        public bool $journalPresent = true;
        public bool $journalPrerequisiteQueryFails = false;
        public bool $journalQueryFails = false;
        /** DUO-3497: what `journal-reset` is about to destroy, and a read that fails. */
        public int $journalRowCount = 0;
        public bool $journalCountQueryFails = false;
        /** Simulate a failed value SELECT whose next successful SELECT clears wpdb::$last_error. */
        public bool $failCurrentValueThenSuccess = false;
        private bool $currentValueFailureDelivered = false;
        /** Simulate a failed post ref SELECT whose term fallback clears wpdb::$last_error. */
        public bool $failPostReferenceThenSuccess = false;
        private bool $postReferenceFailureDelivered = false;
        /** @var list<string> */
        public array $queries = [];

        public function prepare(string $query, mixed ...$args): string {
            foreach ($args as $arg) {
                if (is_array($arg)) {
                    foreach ($arg as $nested) {
                        $query = $this->replace_placeholder($query, $nested);
                    }
                    continue;
                }
                $query = $this->replace_placeholder($query, $arg);
            }
            return $query;
        }

        private function replace_placeholder(string $query, mixed $value): string {
            $replacement = is_int($value) ? (string) $value : "'" . addslashes((string) $value) . "'";
            return (string) preg_replace('/%[sd]/', $replacement, $query, 1);
        }

        public function get_var(string $query): mixed {
            $this->begin_read($query);
            if (stripos($query, 'SHOW TABLES LIKE') === 0) {
                if ($this->journalPrerequisiteQueryFails) {
                    $this->last_error = 'access denied with secret-shaped private driver detail';
                    return null;
                }
                return $this->journalPresent ? 'wp_duo_journal' : null;
            }
            if (str_contains($query, 'SELECT COUNT(*) FROM wp_duo_journal')) {
                if ($this->journalCountQueryFails) {
                    $this->last_error = 'simulated unreadable duo_journal COUNT';
                    return null;
                }
                // wpdb reads mysqli's text protocol: COUNT(*) arrives as a string.
                return (string) $this->journalRowCount;
            }
            if (str_contains($query, 'secret-meta')) {
                return 'sk_live_abcdefghijklmnopqrst';
            }
            if (str_contains($query, 'SELECT meta_value') || str_contains($query, 'SELECT option_value')) {
                if ($this->failCurrentValueThenSuccess && !$this->currentValueFailureDelivered) {
                    $this->currentValueFailureDelivered = true;
                    $this->last_error = 'simulated failed current-value SELECT';
                    return null;
                }
                return '4242';
            }
            return null;
        }

        /** @return list<array<string,mixed>>|false */
        public function get_results(string $query, mixed $format = null): array|false {
            $this->begin_read($query);
            if (str_contains($query, 'SELECT tbl, item, surface, caps, proposal')) {
                if ($this->journalQueryFails) {
                    $this->last_error = 'simulated malformed duo_journal schema';
                    return [];
                }
                return [[
                    'tbl' => 'postmeta',
                    'item' => "sk_live_abcdefghijklmnopqrst\n/home/private/JOURNAL_VALUE\n550e8400-e29b-41d4-a716-446655440000",
                    'surface' => 'rest',
                    'caps' => 'edit_posts',
                    'proposal' => 'authored',
                    'n' => 2,
                ]];
            }
            return [];
        }

        /** @return array<string,mixed>|null */
        public function get_row(string $query, mixed $format = null): ?array {
            $this->begin_read($query);
            if (str_contains($query, 'SELECT ID, post_type, post_title')) {
                if ($this->failPostReferenceThenSuccess && !$this->postReferenceFailureDelivered) {
                    $this->postReferenceFailureDelivered = true;
                    $this->last_error = 'simulated failed post reference SELECT';
                    return null;
                }
                return [
                    'ID' => 4242,
                    'post_type' => 'product',
                    'post_title' => "PRIVATE_TITLE\nPLUGIN_THROWABLE: authorization=secret",
                ];
            }
            return null;
        }

        /** @return list<int> */
        public function get_col(string $query): array {
            $this->begin_read($query);
            return [];
        }

        /** A successful wpdb query clears the error from a previous failed read. */
        private function begin_read(string $query): void {
            $this->last_error = '';
            $this->record($query);
        }

        private function record(string $query): void {
            $this->queries[] = $query;
        }
    }
}

namespace Duo {
    final class Ledger {
        public const TABLE_IDENTIFIER_WIDTH = 191;
        public static int $ensureCalls = 0;
        public static function ensure(): void { self::$ensureCalls++; }
    }

    final class Db {
        public static int $mutationCalls = 0;
        public static function query(string $query, string $context = ''): void { self::$mutationCalls++; }
    }

    final class Policy {
        public static int $loads = 0;

        public static function load(?string $repo, ?array $names = null, bool $readOnly = false): self {
            self::$loads++;
            return new self();
        }

        public function option_rule(string $key): array { return []; }
        public function post_meta_rule(string $key): array { return []; }
        public function term_meta_rule(string $key): array { return []; }
        public function table_rule(string $table): array { return []; }

        /** @return array<string,mixed> */
        public function capability_report(array $query = []): array {
            return [
                'ready' => false,
                'registry_sha256' => str_repeat('b', 64),
                'manifests' => [[
                    'name' => '1vendor.foo_bar',
                    'status' => 'certified',
                    'source' => ['source' => 'plugin', 'trust_tier' => 'plugin_provider'],
                    'verdict' => ['status' => 'blocked', 'reasons' => [[
                        'message' => "PLUGIN_THROWABLE\nAuthorization: Bearer SECRET",
                    ]]],
                    'operations' => ["apply\nRAW_OPERATION"],
                    'surfaces' => ["/home/private/surface"],
                ]],
                'blockers' => [[
                    'name' => '1vendor.foo_bar',
                    'status' => 'blocked',
                    'code' => 'provider_negotiation_failed',
                    'source' => 'plugin',
                    'trust_tier' => 'plugin_provider',
                    'reason' => "PLUGIN_THROWABLE\nsecret=top-secret",
                    'remediation' => '/home/private/remediation',
                ]],
            ];
        }
    }

    final class Capture {
        public static int $readOnlyCalls = 0;
        /** A fake early gate SELECT failure, followed by a later successful read. */
        public static bool $failIntermediateReadThenSuccess = false;

        /** @return array<string,mixed> */
        public static function gate_scan_read_only(
            string $repo,
            Policy $policy,
            ?callable $observationReadCheckpoint = null
        ): array {
            self::$readOnlyCalls++;
            if (self::$failIntermediateReadThenSuccess) {
                $GLOBALS['wpdb']->last_error = 'simulated failed intermediate gate SELECT';
                if ($observationReadCheckpoint !== null) {
                    $observationReadCheckpoint();
                }
                // The following successful read would erase the failure if
                // Pending checked only after this multi-query helper returns.
                $GLOBALS['wpdb']->last_error = '';
            }
            return [
                'scope' => [],
                'options' => [],
                'widgets' => [],
                'post_meta' => [
                    "unsafe\n/home/private/PENDING_VALUE" => ['entities' => 1, 'post_types' => ['product']],
                    'sha256:' . str_repeat('a', 64) => ['entities' => 1, 'post_types' => ['product']],
                    'secret-meta' => ['entities' => 1, 'post_types' => ['product']],
                ],
                'term_meta' => [],
                'user_meta' => [],
            ];
        }
    }

    final class Snapshot {
        /** A fake failed SHOW/SELECT followed by a later successful read. */
        public static bool $failIntermediateReadThenSuccess = false;

        /** @return list<array<string,mixed>> */
        public static function keyspace_gaps(
            Policy $policy,
            ?callable $observationReadCheckpoint = null
        ): array {
            if (self::$failIntermediateReadThenSuccess) {
                $GLOBALS['wpdb']->last_error = 'simulated failed keyspace SELECT';
                if ($observationReadCheckpoint !== null) {
                    $observationReadCheckpoint();
                }
                $GLOBALS['wpdb']->last_error = '';
            }
            return [];
        }
    }

    final class AdapterSources {
        public const FORMAT = 'duo-adapter-sources/v2';
        public const SHIPPED = 'shipped';
        public const SITE = 'site';
        public const PLUGIN = 'plugin';
        public const TIER_DECLARATIVE = 'declarative_manifest';
        public const TIER_NATIVE_ACTION = 'native_action';
        public const TIER_PLUGIN_PROVIDER = 'plugin_provider';
        public const TIER_COMPATIBILITY_SHIM = 'compatibility_shim';
        public const SCOPE_SOURCE = 'source';
        public const SCOPE_ADAPTER = 'adapter';
        public const GRAMMAR_OK = 'ok';
        public const GRAMMAR_ERROR = 'error';
        public const GRAMMAR_BLOCKED = 'blocked_by_source_refusal';

        public static function assert_name(string $name, string $label = 'adapter name'): void {
            if (preg_match('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/D', $name) !== 1
                || preg_match('/[a-z]/D', $name) !== 1) {
                throw new \RuntimeException('invalid adapter identity');
            }
        }

        /** @return array<string,mixed> */
        public static function survey(?string $repo): array {
            return [
                'sources' => [
                    ['source' => 'site', 'scanned' => true, 'path' => '/home/private/site', 'note' => "PLUGIN_THROWABLE\n"],
                    ['source' => 'plugin', 'scanned' => true, 'path' => '/home/private/plugin', 'note' => 'Authorization: Bearer SECRET'],
                    ['source' => 'shipped', 'scanned' => true, 'path' => '/home/private/agent', 'note' => 'raw'],
                ],
                'adapters' => [[
                    'certification' => 'uncertified',
                    'grammar' => ['status' => 'ok', 'message' => "PLUGIN_THROWABLE\nsecret"],
                    'executable_surfaces' => [
                        'interpreter' => '/home/private/interpreter.php',
                        'manifest_providers' => ['provider-with-private-message'],
                        'regenerators' => [['post_type' => 'product', 'regenerator' => '/home/private/regen.php']],
                    ],
                    'name' => '1vendor.foo_bar',
                    'path' => '/home/private/plugin/duo-adapter.json',
                    'sha256' => str_repeat('c', 64),
                    'source' => 'plugin',
                    'tier_basis' => "PLUGIN_THROWABLE\nAuthorization: Bearer SECRET",
                    'trust_tier' => 'plugin_provider',
                ]],
                'not_installed' => [[
                    'name' => "BAD\nPRIVATE_TITLE\nsk_live_abcdefghijklmnopqrst",
                    'reason_code' => 'plugin_adapter_shadowed',
                    'source' => 'plugin',
                    'winner' => ['source' => 'site', 'path' => '/home/private/winner'],
                    'path' => '/home/private/not-installed',
                    'message' => "PLUGIN_THROWABLE\nsecret",
                ]],
                'refusals' => [[
                    'code' => 'plugin_source_unreadable',
                    'paths' => ['/home/private/plugin', "Authorization: Bearer SECRET\n"],
                    'scope' => 'source',
                    'source' => 'plugin',
                    'message' => "PLUGIN_THROWABLE\nsecret",
                    'remediation' => '/home/private/fix',
                ]],
            ];
        }
    }
}

namespace {
    $repoRoot = dirname(__DIR__, 4);

    require_once $repoRoot . '/agent/src/Kernel/Canon.php';
    require_once $repoRoot . '/agent/src/Kernel/Secrets.php';
    require_once $repoRoot . '/agent/src/Kernel/CommandRefusal.php';
    require_once $repoRoot . '/agent/src/Review/Journal.php';
    require_once $repoRoot . '/agent/src/Review/Pending.php';
    require_once $repoRoot . '/agent/src/Adapter/AdapterObservation.php';
    require_once $repoRoot . '/agent/src/Command/Cli.php';
    require_once $repoRoot . '/cli/src/Transport/EnvironmentDriver.php';
    require_once $repoRoot . '/cli/src/Adapter/AdapterObservation.php';

    use Duo\AdapterObservation as TargetObservation;
    use Duo\CommandRefusalException;
    use Duo\Cli;
    use Duo\Db;
    use Duo\Journal;
    use Duo\Ledger;
    use Duo\Pending;
    use Duo\Policy;
    use Duo\Orchestrator\AdapterObservation as HostObservation;
    use Duo\Orchestrator\DriverCapability;
    use Duo\Orchestrator\DriverCapabilityReport;
    use Duo\Orchestrator\EnvironmentDriver;

    $failures = 0;
    function check(bool $condition, string $message): void {
        global $failures;
        if ($condition) {
            echo "ok: $message\n";
            return;
        }
        echo "FAIL: $message\n";
        $failures++;
    }

    function expect_throw(callable $fn, string $message): void {
        try {
            $fn();
            check(false, $message);
        } catch (\Throwable) {
            check(true, $message);
        }
    }

    function method_source(string $path, string $class, string $method): string {
        $reflection = new ReflectionMethod($class, $method);
        $lines = file($path);
        if (!is_array($lines)) return '';
        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }

    function all_select_only(array $queries): bool {
        foreach ($queries as $query) {
            if (preg_match('/^\s*(?:SELECT|SHOW)\b/i', $query) !== 1
                || preg_match('/\b(?:CREATE|INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP)\b/i', $query) === 1) {
                return false;
            }
        }
        return true;
    }

    function remove_tree(string $path): void {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') remove_tree($path . '/' . $entry);
            }
            @rmdir($path);
            return;
        }
        @unlink($path);
    }

    final class ObservationDriver implements EnvironmentDriver {
        /** @var list<list<string>> */
        public array $calls = [];

        public function __construct(private string $wire, private string $repo = '/target/private/repo') {}
        public function name(): string { return 'observation-fixture'; }
        public function driverId(): string { return 'fixture'; }
        public function repoPath(): string { return $this->repo; }
        public function describe(): string { return 'fixture'; }
        public function captureRaw(string $script): array { return ['exit' => 99, 'stdout' => '', 'stderr' => 'unused']; }
        public function captureWp(array $wpArgs): array {
            $this->calls[] = $wpArgs;
            return ['exit' => 0, 'stdout' => $this->wire, 'stderr' => "PLUGIN_THROWABLE\nsecret"];
        }
        public function streamWp(array $wpArgs): int { return 99; }
        public function wpInstruction(array $wpArgs): string { return 'unused'; }
        public function capabilityReport(string $operation): DriverCapabilityReport {
            return DriverCapabilityReport::forDriver($this->name(), $this->driverId(), $operation, [
                DriverCapability::ATTACH => true,
                DriverCapability::WP_CONTROL => true,
            ]);
        }
    }

    echo "== command-entry journal suspension ==\n";
    $wpdb = new ObservationFakeWpdb();
    $GLOBALS['wpdb'] = $wpdb;
    Journal::boot();
    Journal::observe("UPDATE wp_posts SET post_title = 'bootstrap callback write'");
    $refusalPathRan = false;
    try {
        // `--out` is invalid on the target verb, but it supplies a repo so
        // this crosses the command's exact malformed-argument branch.
        (new Cli())->adapter_observe([], ['repo' => '/target/private/repo', 'out' => '/tmp/private-evidence']);
    } catch (ObservationCliHalt) {
        $refusalPathRan = true;
    }
    Journal::observe("UPDATE wp_posts SET post_title = 'provider callback write'");
    Journal::flush();
    check(
        $refusalPathRan && Ledger::$ensureCalls === 0 && Db::$mutationCalls === 0,
        'malformed adapter-observe detaches/discards journal state so its shutdown flush cannot insert provenance'
    );
    check(
        $GLOBALS['duo_observation_hooks']['removed_filters'] !== []
            && $GLOBALS['duo_observation_hooks']['removed_actions'] !== [],
        'malformed adapter-observe detaches both query and shutdown callbacks'
    );
    $cliSource = (string) file_get_contents($repoRoot . '/agent/src/Command/Cli.php');
    $entry = strpos($cliSource, 'public function adapter_observe');
    $suspend = $entry === false ? false : strpos($cliSource, 'Journal::suspend_for_observation()', $entry);
    $argumentGate = $entry === false ? false : strpos($cliSource, 'if ($args !== []', $entry);
    check(
        $entry !== false && $suspend !== false && $argumentGate !== false && $suspend < $argumentGate,
        'adapter-observe suspends journal before even malformed-argument refusal paths'
    );

    // DUO-3497: the other end of the same evidence. `journal-reset` is the one
    // statement in the shipped runtime that removes journal rows, and options
    // no adapter declares are recorded NOWHERE else — capture whitelists
    // options, so the journal is their only witness (agent/src/Review/
    // Pending.php:16-19). Truncating silently therefore empties `duo pending`
    // while the writes it named are still in the database: silent-uncapture.
    // The live report had it destroying the observations only because
    // `duo init` refused `existing_duo_ledger` on a journal-only environment
    // and named no other escape; that half is fixed in InitSiteProbe::ledger(),
    // and this half stays true wherever an operator reaches for the command.
    echo "\n== journal-reset states the evidence it destroys ==\n";
    $resetWpdb = new ObservationFakeWpdb();
    $GLOBALS['wpdb'] = $resetWpdb;
    $resetWpdb->journalRowCount = 7;
    Ledger::$ensureCalls = 0;
    Db::$mutationCalls = 0;
    WP_CLI::$warnings = [];
    WP_CLI::$successes = [];
    WP_CLI::$errors = [];
    (new Cli())->journal_reset([], []);
    check(
        count(WP_CLI::$warnings) === 1
            && str_contains(WP_CLI::$warnings[0], 'destroying 7 observation row(s)')
            && str_contains(WP_CLI::$warnings[0], 'duo pending loses them permanently'),
        'a populated journal-reset names the count and what the review queue loses before truncating'
    );
    check(
        Db::$mutationCalls === 1 && WP_CLI::$successes === ['journal truncated'],
        'the truncate still runs and its success line keeps its exact bytes'
    );

    $resetWpdb->journalRowCount = 0;
    Db::$mutationCalls = 0;
    WP_CLI::$warnings = [];
    WP_CLI::$successes = [];
    (new Cli())->journal_reset([], []);
    check(
        WP_CLI::$warnings === [] && Db::$mutationCalls === 1
            && WP_CLI::$successes === ['journal truncated'],
        'an already-empty journal warns about nothing and still resets'
    );

    // A read that fails is not "nothing to lose": destroying evidence whose
    // size could not be read is exactly the move this product refuses.
    $resetWpdb->journalCountQueryFails = true;
    Db::$mutationCalls = 0;
    WP_CLI::$warnings = [];
    WP_CLI::$successes = [];
    WP_CLI::$errors = [];
    $resetRefused = false;
    try {
        (new Cli())->journal_reset([], []);
    } catch (ObservationCliHalt) {
        $resetRefused = true;
    }
    check(
        $resetRefused && Db::$mutationCalls === 0 && WP_CLI::$successes === []
            && count(WP_CLI::$errors) === 1
            && str_contains(WP_CLI::$errors[0], 'could not read the observations it would destroy'),
        'an unreadable journal count refuses before the TRUNCATE instead of destroying an unknown quantity of evidence'
    );
    $resetWpdb->journalCountQueryFails = false;
    WP_CLI::$errors = [];

    echo "\n== target projection and read-only target paths ==\n";
    $site = sys_get_temp_dir() . '/duo-observation-site-' . bin2hex(random_bytes(6));
    mkdir($site, 0700, true);
    file_put_contents($site . '/site.duo.json', "{\"spec_version\":2,\"private\":\"sk_live_abcdefghijklmnopqrst\"}\n");
    register_shutdown_function(static fn() => remove_tree($site));

    Ledger::$ensureCalls = 0;
    Db::$mutationCalls = 0;
    \Duo\Capture::$readOnlyCalls = 0;
    $wpdb = new ObservationFakeWpdb();
    $GLOBALS['wpdb'] = $wpdb;
    $report = TargetObservation::report($site);
    $again = TargetObservation::report($site);
    $canonical = \Duo\Canon::encode($report);

    $expectedTop = [
        'authority', 'catalog', 'deferred', 'format', 'journal', 'observation_hash', 'pending', 'policy',
        'redaction', 'repository', 'target',
    ];
    $actualTop = array_keys($report);
    sort($actualTop, SORT_STRING);
    check($actualTop === $expectedTop, 'target emits the exact closed top-level schema');
    check($report === $again && $report['observation_hash'] === TargetObservation::hash_document($report), 'target output and canonical observation hash are deterministic');
    check(
        $report['authority'] === false
            && $report['redaction'] === 'values_omitted'
            && $report['repository']['site_policy_sha256'] === 'sha256:' . hash('sha256', file_get_contents($site . '/site.duo.json')),
        'target records only the observed site-policy hash, no overclaimed repository identity'
    );
    check(
        $report['catalog']['format'] === 'duo-adapter-sources/v2'
            && $report['catalog']['installed'][0]['name'] === '1vendor.foo_bar',
        'catalog is explicitly a source-v2 projection and preserves valid digit/dot/underscore adapter names'
    );
    $literalWitness = false;
    $hashedWitness = false;
    foreach ($report['pending']['items'] as $item) {
        $label = $item['key']['label'];
        if ($item['key']['encoding'] === 'literal' && str_starts_with($label, 'sha256:')) $literalWitness = true;
        if ($item['key']['encoding'] === 'sha256') $hashedWitness = true;
    }
    check($literalWitness && $hashedWitness, 'structural label encoding distinguishes literal sha256-looking keys from redacted keys');
    check(
        $report['pending']['summary']['secret'] === 1
            && in_array('hard:stripe_key', array_column($report['pending']['items'], 'secret'), true),
        'pending secret output is a closed label, never the detected credential bytes'
    );
    $bootstrapDeferred = end($report['deferred']);
    check(
        is_array($bootstrapDeferred)
            && $bootstrapDeferred['subject'] === 'bootstrap_effects'
            && $bootstrapDeferred['statement'] === 'normal plugin/provider registration and capability negotiation remain enabled; third-party callbacks may have side effects before or during evidence collection; Duo invokes no provider action and performs no explicit mutation after observer entry'
            && in_array('version_lifecycle', array_column($report['deferred'], 'subject'), true),
        'closed deferred rows state callback scope and complete version lifecycle boundary exactly'
    );
    $privateMarkers = [
        'sk_live_', '/home/', '4242', 'PRIVATE_TITLE', 'PENDING_VALUE', 'JOURNAL_VALUE',
        '550e8400-e29b-41d4-a716-446655440000', 'AUTHORIZATION', 'PLUGIN_THROWABLE', 'Bearer SECRET',
    ];
    $markersAbsent = true;
    foreach ($privateMarkers as $marker) {
        $markersAbsent = $markersAbsent && !str_contains($canonical, $marker);
    }
    check($markersAbsent, 'target canonical document omits raw secrets, paths, IDs, titles, values, and plugin Throwable-shaped text');
    check(
        Ledger::$ensureCalls === 0 && Db::$mutationCalls === 0 && \Duo\Capture::$readOnlyCalls === 2
            && all_select_only($wpdb->queries),
        'target Journal/Pending observation uses no Ledger repair or DB mutation and issues SELECT/SHOW queries only'
    );
    $journalReadSource = method_source($repoRoot . '/agent/src/Review/Journal.php', Journal::class, 'report_read_only');
    $pendingReadSource = method_source($repoRoot . '/agent/src/Review/Pending.php', Pending::class, 'scan_read_only');
    check(
        !str_contains($journalReadSource, 'Ledger::ensure') && !str_contains($pendingReadSource, 'Ledger::ensure'),
        'narrow read-only Journal/Pending entry points cannot call Ledger::ensure'
    );
    // DUO-3497's survival half. Observations recorded before `duo init` reach
    // the post-init review queue only because this aggregation has no time,
    // id, or initialization predicate — it groups every row in the table. The
    // statement is pinned whitespace-normalized so a date bound added later is
    // a red assertion here rather than a queue that quietly goes short.
    $pendingJournalSource = method_source(
        $repoRoot . '/agent/src/Review/Pending.php',
        Pending::class,
        'journal_unclassified'
    );
    check(
        str_contains(
            (string) preg_replace('/\s+/', ' ', $pendingJournalSource),
            'SELECT item, surface, caps, proposal, COUNT(*) AS n '
            . 'FROM {$wpdb->prefix}duo_journal '
            . "WHERE tbl = %s AND item != '' "
            . 'GROUP BY item, surface, caps, proposal'
        ),
        'the pending queue aggregates every journal row, so observations written before init still reach it'
    );

    $prerequisiteReadErrorDb = new ObservationFakeWpdb();
    $prerequisiteReadErrorDb->journalPrerequisiteQueryFails = true;
    $GLOBALS['wpdb'] = $prerequisiteReadErrorDb;
    $prerequisiteReadError = null;
    try {
        TargetObservation::report($site);
    } catch (CommandRefusalException $e) {
        $prerequisiteReadError = $e->reasonCode;
    }
    check(
        $prerequisiteReadError === 'adapter_observation_journal_unreadable',
        'failed journal prerequisite SHOW refuses as unreadable instead of falsely reporting the table absent'
    );

    $queryErrorDb = new ObservationFakeWpdb();
    $queryErrorDb->journalQueryFails = true;
    $GLOBALS['wpdb'] = $queryErrorDb;
    $journalError = null;
    try {
        TargetObservation::report($site);
    } catch (CommandRefusalException $e) {
        $journalError = $e->reasonCode;
    }
    check(
        $journalError === 'adapter_observation_journal_unreadable',
        'failed/empty journal SELECT with wpdb last_error refuses instead of publishing a false zero report'
    );

    $pendingErrorDb = new ObservationFakeWpdb();
    $pendingErrorDb->last_error = 'simulated pending read failure';
    $GLOBALS['wpdb'] = $pendingErrorDb;
    $pendingError = null;
    try {
        Pending::scan_read_only($site, new Policy());
    } catch (CommandRefusalException $e) {
        $pendingError = $e->reasonCode;
    }
    check(
        $pendingError === 'adapter_observation_pending_unreadable',
        'read-only pending path fails closed when wpdb reports a read error'
    );

    // T7 grind A1: on a target Duo has never written to (the first `duo assess`
    // on an adoption seed) the journal table is provably absent. That is a
    // known state, not a failed read: the strict scan proceeds with the live
    // gate walk alone and the journal-derived half of the queue is empty. A
    // probe that cannot even prove presence still refuses.
    $noJournalDb = new ObservationFakeWpdb();
    $noJournalDb->journalPresent = false;
    $GLOBALS['wpdb'] = $noJournalDb;
    check(Pending::journal_installed() === false, 'journal_installed() reads a clean absent probe as false');
    $noJournalError = null;
    $noJournalItems = null;
    try {
        $noJournalItems = Pending::scan_read_only($site, new Policy());
    } catch (CommandRefusalException $e) {
        $noJournalError = $e->reasonCode;
    }
    check(
        $noJournalError === null && is_array($noJournalItems),
        'the strict pending scan proceeds on a target whose journal was never installed (got ' . var_export($noJournalError, true) . ')'
    );
    check(
        is_array($noJournalItems) && !in_array(true, array_map(
            static fn ($item): bool => str_contains(json_encode($item), 'JOURNAL_VALUE'),
            $noJournalItems
        ), true),
        'and the journal-derived half of the queue is empty rather than read from a table that does not exist'
    );
    $journalProbeFailDb = new ObservationFakeWpdb();
    $journalProbeFailDb->journalPrerequisiteQueryFails = true;
    $GLOBALS['wpdb'] = $journalProbeFailDb;
    $probeError = null;
    try {
        Pending::scan_read_only($site, new Policy());
    } catch (CommandRefusalException $e) {
        $probeError = $e->reasonCode;
    }
    check(
        $probeError === 'adapter_observation_pending_unreadable',
        'a journal probe that fails is refused as unreadable, never read as absent'
    );
    $GLOBALS['wpdb'] = new ObservationFakeWpdb();
    check(Pending::journal_installed() === true, 'journal_installed() reads a present journal as true');

    echo "\n== strict intermediate pending reads ==\n";
    $gateIntermediateDb = new ObservationFakeWpdb();
    $GLOBALS['wpdb'] = $gateIntermediateDb;
    \Duo\Capture::$failIntermediateReadThenSuccess = true;
    $gateIntermediateError = null;
    try {
        Pending::scan_read_only($site, new Policy());
    } catch (CommandRefusalException $e) {
        $gateIntermediateError = $e->reasonCode;
    } finally {
        \Duo\Capture::$failIntermediateReadThenSuccess = false;
    }
    check(
        $gateIntermediateError === 'adapter_observation_pending_unreadable',
        'observer refuses an intermediate gate SELECT before a later success can clear wpdb last_error'
    );

    $keyspaceIntermediateDb = new ObservationFakeWpdb();
    $GLOBALS['wpdb'] = $keyspaceIntermediateDb;
    \Duo\Snapshot::$failIntermediateReadThenSuccess = true;
    $keyspaceIntermediateError = null;
    try {
        Pending::scan_read_only($site, new Policy());
    } catch (CommandRefusalException $e) {
        $keyspaceIntermediateError = $e->reasonCode;
    } finally {
        \Duo\Snapshot::$failIntermediateReadThenSuccess = false;
    }
    check(
        $keyspaceIntermediateError === 'adapter_observation_pending_unreadable',
        'observer refuses an intermediate keyspace SELECT before a later success can clear wpdb last_error'
    );

    $currentValueIntermediateDb = new ObservationFakeWpdb();
    $currentValueIntermediateDb->failCurrentValueThenSuccess = true;
    $GLOBALS['wpdb'] = $currentValueIntermediateDb;
    $currentValueIntermediateError = null;
    try {
        Pending::scan_read_only($site, new Policy());
    } catch (CommandRefusalException $e) {
        $currentValueIntermediateError = $e->reasonCode;
    }
    check(
        $currentValueIntermediateError === 'adapter_observation_pending_unreadable',
        'observer refuses a current-value SELECT before the next item can erase its wpdb error'
    );

    $referenceIntermediateDb = new ObservationFakeWpdb();
    $referenceIntermediateDb->failPostReferenceThenSuccess = true;
    $GLOBALS['wpdb'] = $referenceIntermediateDb;
    $referenceIntermediateError = null;
    try {
        Pending::scan_read_only($site, new Policy());
    } catch (CommandRefusalException $e) {
        $referenceIntermediateError = $e->reasonCode;
    }
    check(
        $referenceIntermediateError === 'adapter_observation_pending_unreadable',
        'observer refuses a post reference SELECT before its term fallback can erase wpdb last_error'
    );

    $normalPendingDb = new ObservationFakeWpdb();
    $normalPendingDb->failCurrentValueThenSuccess = true;
    $GLOBALS['wpdb'] = $normalPendingDb;
    $normalPending = Pending::scan($site);
    check(
        $normalPending !== [] && $normalPendingDb->last_error === '',
        'ordinary pending keeps its legacy non-observer read path while observation-only checkpoints stay opt-in'
    );

    echo "\n== strict host validation and one-call transport ==\n";
    $GLOBALS['wpdb'] = new ObservationFakeWpdb();
    try {
        HostObservation::validate($canonical);
        check(true, 'host accepts the target canonical baseline before transport');
    } catch (\Throwable $e) {
        check(false, 'host accepts the target canonical baseline before transport: ' . $e->getMessage());
    }
    $driver = new ObservationDriver($canonical);
    ob_start();
    $hostResult = HostObservation::run($driver, ['--format=json']);
    $hostJson = (string) ob_get_clean();
    check($hostResult === 0 && $hostJson === $canonical, 'host emits only the validated canonical target document in JSON mode');
    check(
        $driver->calls === [[
            'duo', 'adapter-observe', '--repo=/target/private/repo', '--format=json',
        ]],
        'host calls target exactly once with configured target repo_path and no local --repo conflation'
    );
    check(!str_contains($hostJson, '/target/private/repo') && !str_contains($hostJson, 'PLUGIN_THROWABLE'), 'host never leaks transport path or target plugin text');

    $extra = $report;
    $extra['pending']['items'][0]['value'] = 'raw-secret-value';
    $extra['observation_hash'] = HostObservation::hash_document($extra);
    expect_throw(static fn() => HostObservation::validate(\Duo\Canon::encode($extra)), 'host rejects unknown/value-bearing fields even with a recomputed hash');

    $badHash = $report;
    $badHash['observation_hash'] = 'sha256:' . str_repeat('0', 64);
    expect_throw(static fn() => HostObservation::validate(\Duo\Canon::encode($badHash)), 'host recomputes and rejects a mutated observation hash');

    $badType = $report;
    $badType['journal']['summary']['observations'] = '2';
    $badType['observation_hash'] = HostObservation::hash_document($badType);
    expect_throw(static fn() => HostObservation::validate(\Duo\Canon::encode($badType)), 'host rejects malformed field types even with a recomputed hash');

    $reordered = $report;
    [$reordered['pending']['items'][0], $reordered['pending']['items'][1]] = [
        $reordered['pending']['items'][1], $reordered['pending']['items'][0],
    ];
    $reordered['observation_hash'] = HostObservation::hash_document($reordered);
    expect_throw(static fn() => HostObservation::validate(\Duo\Canon::encode($reordered)), 'host rejects reordered closed rows even when their hash is recomputed');

    $noncanonical = json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
    expect_throw(static fn() => HostObservation::validate((string) $noncanonical), 'host rejects noncanonical object byte ordering');

    $driverCapability = DriverCapabilityReport::forDriver('fixture', 'fixture', 'adapter-observe', [
        DriverCapability::ATTACH => true,
        DriverCapability::WP_CONTROL => true,
    ]);
    check($driverCapability->ready(), 'adapter-observe is wired to normal attach + WP-CLI driver capability requirements');

    echo "\n== create-only local evidence ==\n";
    $outDir = sys_get_temp_dir() . '/duo-observation-out-' . bin2hex(random_bytes(6));
    mkdir($outDir, 0700, true);
    register_shutdown_function(static fn() => remove_tree($outDir));
    $out = $outDir . '/evidence.json';
    $outDriver = new ObservationDriver($canonical);
    ob_start();
    $outResult = HostObservation::run($outDriver, ['--out=' . $out]);
    $outHuman = (string) ob_get_clean();
    check(
        $outResult === 0 && $outDriver->calls === [[
            'duo', 'adapter-observe', '--repo=/target/private/repo', '--format=json',
        ]] && file_get_contents($out) === $canonical,
        'host makes one target call then writes validated evidence atomically and create-only'
    );
    check(!str_contains($outHuman, $out) && str_contains($outHuman, 'path omitted'), 'human write receipt omits arbitrary local evidence path');
    ob_start();
    $againResult = HostObservation::run($outDriver, ['--out=' . $out]);
    ob_end_clean();
    check($againResult !== 0 && count($outDriver->calls) === 1 && file_get_contents($out) === $canonical, 'existing evidence file is refused without overwrite or a second target call');

    $link = $outDir . '/existing-link.json';
    symlink($out, $link);
    $linkDriver = new ObservationDriver($canonical);
    ob_start();
    $linkResult = HostObservation::run($linkDriver, ['--out=' . $link]);
    ob_end_clean();
    check($linkResult !== 0 && $linkDriver->calls === [], 'symlink output target is refused before target transport');

    $fifo = $outDir . '/existing-fifo';
    $fifoCreated = function_exists('posix_mkfifo') && posix_mkfifo($fifo, 0600);
    $fifoDriver = new ObservationDriver($canonical);
    ob_start();
    $fifoResult = $fifoCreated ? HostObservation::run($fifoDriver, ['--out=' . $fifo]) : 1;
    ob_end_clean();
    check($fifoCreated && $fifoResult !== 0 && $fifoDriver->calls === [], 'FIFO output target is refused before target transport');

    if ($failures !== 0) {
        fwrite(STDERR, "FAIL: $failures adapter observation checks failed\n");
        exit(1);
    }
    echo "ALL ADAPTER OBSERVATION CHECKS PASSED\n";
}

<?php
/**
 * Offline boundary regression: only a fake SELECT-capable wpdb is present.
 * Any mutation query throws, so this proves the strict ledger/export helpers
 * are observers rather than capture repair paths.
 */
$root = realpath(__DIR__ . '/../../../..');
if ($root === false) throw new RuntimeException('FAIL: root missing');
define('ARRAY_A', 'ARRAY_A');
require_once $root . '/agent/src/Kernel/Uuid.php';
require_once $root . '/agent/src/Kernel/Db.php';
require_once $root . '/agent/src/Repository/Ledger.php';
require_once $root . '/agent/src/Review/RefreshExport.php';
require_once $root . '/agent/src/Repository/Snapshot.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Grammar/Tokens.php';
require_once $root . '/agent/src/Policy/ScopeDiscovery.php';
require_once $root . '/agent/src/Cloud/OriginStore.php';
require_once $root . '/agent/src/Cloud/OriginExporter.php';

use Duo\Canon;
use Duo\Ledger;
use Duo\OptionState;
use Duo\OriginExporter;
use Duo\Policy;
use Duo\RefreshExport;
use Duo\ScopeDiscovery;
use Duo\Snapshot;
use Duo\Tokens;
use Duo\Uuid;

function fail_re(string $message): never { throw new RuntimeException("FAIL: $message"); }
function check_re(bool $ok, string $message): void { if (!$ok) fail_re($message); }
if (!function_exists('get_taxonomy')) {
    function get_taxonomy(string $_taxonomy): false { return false; }
}

final class RefreshExportReadOnlyWpdb {
    public string $prefix = 'wp_';
    public string $last_error = '';
    /** @var list<array<string,mixed>> */
    public array $maps = [];
    public int $queries = 0;

    public function prepare(string $sql, mixed ...$args): string {
        foreach ($args as $arg) {
            $value = is_int($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'";
            $sql = preg_replace('/%[sd]/', $value, $sql, 1) ?? $sql;
        }
        return $sql;
    }
    public function query(string $sql): never {
        $this->queries++;
        throw new RuntimeException("FAIL: unexpected mutation query $sql");
    }
    /** @return list<array<string,mixed>> */
    public function get_results(string $sql, mixed $_output = null): array {
        if (str_contains($sql, 'information_schema.COLUMNS')) return $this->columns();
        if (str_contains($sql, 'information_schema.STATISTICS')) return $this->indexes();
        if (str_contains($sql, 'SELECT uuid, entity_type, id_kind, local_id FROM wp_duo_map')) return $this->maps;
        throw new RuntimeException("FAIL: unexpected inventory query $sql");
    }
    public function get_var(string $sql): mixed {
        if (preg_match("/WHERE id_kind = '([^']+)' AND local_id = ([0-9]+)/", $sql, $m) === 1) {
            foreach ($this->maps as $row) {
                if ($row['id_kind'] === $m[1] && (int) $row['local_id'] === (int) $m[2]) {
                    return $row['uuid'];
                }
            }
            return null;
        }
        throw new RuntimeException("FAIL: unexpected scalar query $sql");
    }
    /** @return ?array<string,mixed> */
    public function get_row(string $sql, mixed $_output = null): ?array {
        if (preg_match("/WHERE uuid = '([^']+)' AND id_kind = '([^']+)'/", $sql, $m) === 1) {
            foreach ($this->maps as $row) if ($row['uuid'] === $m[1] && $row['id_kind'] === $m[2]) {
                return ['entity_type' => $row['entity_type'], 'local_id' => $row['local_id']];
            }
            return null;
        }
        if (preg_match("/WHERE id_kind = '([^']+)' AND local_id = ([0-9]+)/", $sql, $m) === 1) {
            foreach ($this->maps as $row) if ($row['id_kind'] === $m[1] && (int) $row['local_id'] === (int) $m[2]) {
                return ['uuid' => $row['uuid'], 'entity_type' => $row['entity_type']];
            }
            return null;
        }
        throw new RuntimeException("FAIL: unexpected row query $sql");
    }
    /** @return list<array<string,mixed>> */
    private function columns(): array {
        $out = [];
        $add = static function (string $table, string $name, string $type, int $length) use (&$out): void {
            $out[] = ['TABLE_NAME' => $table, 'COLUMN_NAME' => $name, 'DATA_TYPE' => $type,
                'COLUMN_TYPE' => $type, 'CHARACTER_MAXIMUM_LENGTH' => $length];
        };
        $add('wp_duo_map', 'uuid', 'char(36)', 36);
        $add('wp_duo_map', 'entity_type', 'varchar(64)', 64);
        $add('wp_duo_map', 'id_kind', 'varchar(32)', 32);
        $add('wp_duo_map', 'local_id', 'bigint(20) unsigned', 0);
        $add('wp_duo_state', 'uuid', 'varchar(64)', 64);
        $add('wp_duo_state', 'entity_type', 'varchar(64)', 64);
        $add('wp_duo_state', 'content_hash', 'char(64)', 64);
        $add('wp_duo_kv', 'k', 'varchar(191)', 191);
        $add('wp_duo_kv', 'v', 'longtext', PHP_INT_MAX);
        return $out;
    }
    /** @return list<array<string,mixed>> */
    private function indexes(): array {
        return [
            ['TABLE_NAME' => 'wp_duo_map','INDEX_NAME' => 'PRIMARY','NON_UNIQUE' => 0,'SEQ_IN_INDEX' => 1,'COLUMN_NAME' => 'uuid'],
            ['TABLE_NAME' => 'wp_duo_map','INDEX_NAME' => 'PRIMARY','NON_UNIQUE' => 0,'SEQ_IN_INDEX' => 2,'COLUMN_NAME' => 'id_kind'],
            ['TABLE_NAME' => 'wp_duo_map','INDEX_NAME' => 'kind_local','NON_UNIQUE' => 0,'SEQ_IN_INDEX' => 1,'COLUMN_NAME' => 'id_kind'],
            ['TABLE_NAME' => 'wp_duo_map','INDEX_NAME' => 'kind_local','NON_UNIQUE' => 0,'SEQ_IN_INDEX' => 2,'COLUMN_NAME' => 'local_id'],
            ['TABLE_NAME' => 'wp_duo_state','INDEX_NAME' => 'PRIMARY','NON_UNIQUE' => 0,'SEQ_IN_INDEX' => 1,'COLUMN_NAME' => 'uuid'],
            ['TABLE_NAME' => 'wp_duo_kv','INDEX_NAME' => 'PRIMARY','NON_UNIQUE' => 0,'SEQ_IN_INDEX' => 1,'COLUMN_NAME' => 'k'],
        ];
    }
}

$uuid = '123e4567-e89b-42d3-a456-426614174000';
$renamedNaturalUuid = Uuid::v5(
    Uuid::NAMESPACE_DUO,
    'woocommerce_attribute_taxonomies:original-name'
);
$wpdb = new RefreshExportReadOnlyWpdb();
$wpdb->maps = [
    ['uuid' => $uuid, 'entity_type' => 'post', 'id_kind' => 'post', 'local_id' => 7],
    [
        'uuid' => $renamedNaturalUuid,
        'entity_type' => 'woocommerce_attribute_taxonomies',
        'id_kind' => 'attr_taxonomy',
        'local_id' => 42,
    ],
];
$GLOBALS['wpdb'] = $wpdb;
Ledger::assert_read_only_schema();
Ledger::require_read_only_mapping($uuid, 'post', 'post', 7, 'fixture post');
check_re($wpdb->queries === 0, 'read-only ledger helper attempted a mutation query');

$identify = new ReflectionMethod(Snapshot::class, 'identify_row');
// DUO-3318: identify_row() takes the capture-direction tokenizer, because a
// parent-scoped natural key's ref component derives from the REFERENCED row's
// uuid. The strict read-only branch under test returns before touching it —
// asserted below by the unchanged zero-mutation-query check — so an
// uninitialized instance is exactly the right fixture: it proves that path
// never reaches for one.
$identifyTokens = (new ReflectionClass(Tokens::class))->newInstanceWithoutConstructor();
$retained = $identify->invoke(null, 'woocommerce_attribute_taxonomies', [
    'id_kind' => 'attr_taxonomy',
    'identity' => ['mode' => 'natural_key', 'column' => 'attribute_name'],
], ['attribute_name' => 'renamed-value'], 42, $identifyTokens, false, true);
check_re($retained === $renamedNaturalUuid,
    'strict export did not preserve durable natural-key identity across an authored rename');
check_re($wpdb->queries === 0, 'natural-key continuity check attempted a mutation query');

// The isolated control bootstrap deliberately skips user plugins. An exact
// plugin taxonomy can therefore be in policy scope without being registered.
// Production truth must refuse that unknown relationship ownership rather
// than returning a warning plus a silently incomplete P snapshot.
$policy = new Policy();
$warnings = [];
$scope = new ScopeDiscovery(
    $policy,
    null,
    static function (string $warning) use (&$warnings): void {
        $warnings[] = $warning;
    }
);
try {
    $scope->taxonomyOwnership(['plugin_exact_taxonomy'], ['post'], true);
    fail_re('strict export silently accepted an unregistered scoped plugin taxonomy');
} catch (RuntimeException $e) {
    check_re(str_contains($e->getMessage(), 'refresh export refused')
        && str_contains($e->getMessage(), 'plugin-owned'),
        'unregistered scoped taxonomy refusal was not actionable');
}
check_re($warnings === [], 'strict taxonomy refusal degraded to a warning');

try {
    Ledger::require_read_only_mapping($uuid, 'term', 'post', 7, 'contradictory fixture');
    fail_re('contradictory durable identity was accepted');
} catch (RuntimeException $e) {
    check_re(str_contains($e->getMessage(), 'contradicts'), 'contradiction did not fail loudly');
}

$source = file_get_contents($root . '/agent/src/Review/RefreshExport.php');
if ($source === false) fail_re('cannot read exporter source');
$code = '';
foreach (token_get_all($source) as $token) {
    if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) continue;
    $code .= is_array($token) ? $token[1] : $token;
}
check_re(str_contains($source, 'START TRANSACTION READ ONLY, WITH CONSISTENT SNAPSHOT'), 'missing read-only consistent snapshot');
check_re(preg_match('/Ledger::(?:ensure|set|forget|prune_state|prune_dead_map|prune_dead_table_map|kv_set|kv_delete)\(/', $code) !== 1, 'exporter calls a forbidden ledger mutation');
check_re(preg_match('/Snapshot::(?:repair_truncated_entity_types|prune_dead_map|prune_option_name_ref_map)\(/', $code) !== 1, 'exporter calls a forbidden snapshot repair');
check_re(!str_contains($code, 'Canon::write_file('), 'exporter writes filesystem state');

$records = new ReflectionMethod(RefreshExport::class, 'records');
$result = $records->invoke(null, [[
    'uuid' => 'options/core', 'type' => 'options', 'path' => 'options/core.json', 'content' => "{}\n",
], [
    'uuid' => $uuid, 'type' => 'post', 'path' => "posts/post/$uuid--fixture.md", 'content' => "visible\n", 'hash_basis' => "semantic\n",
]]);
check_re(array_keys($result) === [$uuid, 'options/core'], 'records are not keyed by semantic identity');
check_re($result[$uuid]['hash'] === hash('sha256', "semantic\n"), 'post semantic hash basis was not retained');
check_re($result['options/core']['content'] === "{}\n", 'canonical content was not preserved');
try {
    $records->invoke(null, [[
        'uuid' => 'options/core', 'type' => 'options', 'path' => '../options/core.json', 'content' => "{}\n",
    ]]);
    fail_re('unsafe export record path was accepted');
} catch (RuntimeException $e) {
    check_re(str_contains($e->getMessage(), 'invalid'), 'unsafe path refusal was unclear');
}

// A per-option root is a virtual state identity. The real exporter helper
// must emit a minimal options/core carrier rather than accidentally sending
// every sibling option through the scoped-refresh wire envelope.
$optionContent = Canon::encode(OptionState::document([
    'blogdescription' => OptionState::present('excluded sibling', 'yes'),
    'blogname' => OptionState::present('selected title', 'yes'),
]));
$scopedLive = new ReflectionMethod(RefreshExport::class, 'scoped_live_entities');
$projected = $scopedLive->invoke(null, [
    ['uuid' => 'options/core', 'type' => 'options', 'path' => 'options/core.json', 'content' => $optionContent],
    ['uuid' => $uuid, 'type' => 'post', 'path' => "posts/post/$uuid--fixture.md", 'content' => "visible\n"],
], [$uuid, 'option:blogname'], ['blogname']);
$projectedOptions = OptionState::records(Canon::decode((string) $projected['options/core']['content']));
$projectedKeys = array_keys($projected);
sort($projectedKeys, SORT_STRING);
check_re($projectedKeys === [$uuid, 'options/core']
    && array_keys($projectedOptions) === ['blogname']
    && ($projectedOptions['blogname']['value'] ?? null) === 'selected title'
    && ($projected['options/core']['hash_basis'] ?? null) === (string) $projected['options/core']['content'],
    'option-root refresh projection emits only the selected record with matching carrier hash basis');
$wholeOptionsProjection = $scopedLive->invoke(null, [
    ['uuid' => 'options/core', 'type' => 'options', 'path' => 'options/core.json', 'content' => $optionContent],
], ['options/core', 'option:blogname'], ['blogname']);
check_re(($wholeOptionsProjection['options/core']['content'] ?? null) === $optionContent
    && !array_key_exists('hash_basis', $wholeOptionsProjection['options/core']),
    'a whole-options root remains whole when a redundant option root is also selected');
try {
    $scopedLive->invoke(null, [
        ['uuid' => 'options/core', 'type' => 'options', 'path' => 'options/core.json', 'content' => $optionContent],
    ], ['option:missing'], ['missing']);
    fail_re('option-root refresh projection accepted a missing selected record');
} catch (RuntimeException $e) {
    check_re(str_contains($e->getMessage(), "lost selected option 'missing'"),
        'missing selected option did not fail closed at the export boundary');
}

// The outbound boundary must reject an ordinary WordPress bootstrap before it
// even stats the supplied repository. A missing pathname distinguishes that
// ordering from a later repository-validation refusal.
try {
    OriginExporter::seal(
        '/duo-origin-export-control-plane-gate-does-not-exist',
        hash('sha256', 'control-plane-gate'),
        str_repeat('a', 40)
    );
    fail_re('origin exporter accepted a non-control-plane caller');
} catch (RuntimeException $e) {
    check_re(str_contains($e->getMessage(), 'isolated WP-CLI control plane'),
        'origin exporter inspected the repository before enforcing the control-plane gate');
}

// Run the sealed-export path in a fresh process so its deliberately tiny
// Policy/RefreshExport doubles can be defined before OriginExporter loads.
// The test still executes the real OriginExporter and OriginStore byte for
// byte; only the live WordPress/DB observer is replaced with a deterministic
// counter and canonical payload.
$originChild = <<<'PHP'
<?php
declare(strict_types=1);

namespace Duo {
    final class Policy {
        public static int $loads = 0;
        public static bool $failLoad = false;
        /** @var array<string,mixed> */
        public static array $siteFixture = [];
        /** @var list<array<string,mixed>> */
        public static array $manifestFixture = [];
        /** @var list<'allow_pii'|'allow_secret'> */
        public static array $interpreterGrantFixture = [];
        public static bool $interpreterCapabilityDeclared = true;
        /** @var array<string,mixed> */
        public array $site = [];
        /** @var list<array<string,mixed>> */
        public array $manifests = [];

        public static function load(?string $_repo): self {
            self::$loads++;
            if (self::$failLoad) {
                throw new \RuntimeException('FAIL: sealed retry reloaded policy');
            }
            $policy = new self();
            $policy->site = self::$siteFixture;
            $policy->manifests = self::$manifestFixture;
            return $policy;
        }

        /** @return list<'allow_pii'|'allow_secret'> */
        public function egress_sensitivity_grants(): array {
            $grants = [];
            $scan = static function (mixed $value) use (&$scan, &$grants): void {
                if (!is_array($value)) {
                    return;
                }
                foreach ($value as $key => $child) {
                    if (($key === 'allow_secret' || $key === 'allow_pii') && $child === true) {
                        $grants[$key] = true;
                    }
                    $scan($child);
                }
            };
            $scan($this->site);
            $scan($this->manifests);
            foreach ($this->manifests as $manifest) {
                if (($manifest['interpreter'] ?? null) !== null
                    && !self::$interpreterCapabilityDeclared) {
                    throw new \RuntimeException(
                        "duo: interpreter 'dynamic-fixture' does not declare its egress sensitivity capabilities"
                    );
                }
            }
            foreach (self::$interpreterGrantFixture as $grant) {
                $grants[$grant] = true;
            }
            $result = array_keys($grants);
            sort($result, SORT_STRING);
            return $result;
        }
    }

    final class RefreshExport {
        public const FORMAT = 'duo-refresh-production/v1';
        public static int $calls = 0;
        public static string $mode = 'normal';

        /** @return array<string,mixed> */
        public static function run(
            string $repo,
            bool $_forceUnresolvedRefs = false,
            ?array $_scopeRequest = null
        ): array {
            self::$calls++;
            if (self::$mode === 'throw') {
                throw new \RuntimeException('FAIL: sealed retry recaptured live state');
            }
            $content = str_repeat('canonical-origin-byte-', 55000);
            $payload = [
                'completed_code' => null,
                'deletions' => [],
                'format' => self::FORMAT,
                'media' => [],
                'policy' => [
                    'manifest_hash' => hash('sha256', 'manifest'),
                    'resolved_adapters' => [],
                    'site_hash' => hash('sha256', 'site'),
                ],
                'records' => [
                    'options/core' => [
                        'content' => $content,
                        'hash' => hash('sha256', $content),
                        'path' => 'options/core.json',
                        'type' => 'options',
                        'uuid' => 'options/core',
                    ],
                ],
                'repository' => [
                    'artifact_hash' => hash('sha256', 'artifact'),
                    'code_revision' => null,
                    'revision_hash' => hash('sha256', 'revision'),
                ],
                'warnings' => [],
            ];
            if (self::$mode === 'secret-sentinel') {
                $secretContent = "sk_live_1234567890ABCDEF\n";
                $payload['records']['secret-sentinel'] = [
                    'content' => $secretContent,
                    'hash' => hash('sha256', $secretContent),
                    'path' => 'options/secret-sentinel.json',
                    'type' => 'options',
                    'uuid' => 'secret-sentinel',
                ];
            }
            $payload['snapshot_hash'] = hash('sha256', Canon::encode($payload));
            if (self::$mode === 'post-capture-drift') {
                if (file_put_contents($repo . '/state/post-capture-drift.json', "drift\n") === false) {
                    throw new \RuntimeException('FAIL: could not inject post-capture drift');
                }
            }
            return $payload;
        }
    }
}

namespace {
    define('WP_CLI', true);
    define('DUO_CONTROL_PLANE', true);
    $projectRoot = __PROJECT_ROOT__;
    require_once $projectRoot . '/agent/src/Kernel/Canon.php';
    require_once $projectRoot . '/agent/src/Cloud/OriginStore.php';
    require_once $projectRoot . '/agent/src/Cloud/OriginExporter.php';

    use Duo\Canon;
    use Duo\OriginExporter;
    use Duo\OriginStore;
    use Duo\Policy;
    use Duo\RefreshExport;

    function origin_fail(string $message): never {
        throw new \RuntimeException("FAIL: $message");
    }

    function origin_check(bool $ok, string $message): void {
        if (!$ok) origin_fail($message);
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    function origin_process(array $command, ?string $cwd = null): array {
        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) origin_fail('could not start fixture process');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [
            'exit' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    function origin_kill_export(string $repo, string $sessionId, string $expectedCommit): int {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            origin_fail('pcntl is required for the real origin-export SIGKILL regression');
        }
        $pid = pcntl_fork();
        if (!is_int($pid) || $pid < 0) {
            origin_fail('could not fork the origin-export SIGKILL fixture');
        }
        if ($pid === 0) {
            putenv('DUO_TEST_MODE=1');
            putenv('DUO_TEST_ORIGIN_KILL_PHASE=after-manifest-before-publish');
            try {
                OriginExporter::seal($repo, $sessionId, $expectedCommit);
                exit(73);
            } catch (\Throwable $e) {
                fwrite(STDERR, 'origin-export SIGKILL fixture threw: ' . $e->getMessage() . "\n");
                exit(74);
            }
        }
        $waited = pcntl_waitpid($pid, $status);
        if ($waited !== $pid || !is_int($status)) {
            origin_fail('could not wait for the origin-export SIGKILL fixture');
        }
        return $status;
    }

    function origin_was_killed(int $status): bool {
        return (pcntl_wifsignaled($status) && pcntl_wtermsig($status) === 9)
            || (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 137);
    }

    function origin_git(string $repo, string ...$arguments): string {
        $result = origin_process(array_merge(['git', '-C', $repo], $arguments));
        if ($result['exit'] !== 0) {
            origin_fail('git fixture command failed: ' . trim($result['stderr']));
        }
        return trim($result['stdout']);
    }

    function origin_expect(callable $callback, string $needle, string $message): void {
        try {
            $callback();
        } catch (\RuntimeException $e) {
            origin_check(str_contains($e->getMessage(), $needle),
                "$message (unexpected refusal: {$e->getMessage()})");
            return;
        }
        origin_fail("$message (operation succeeded)");
    }

    function origin_mode(string $path): int {
        clearstatcache(true, $path);
        $mode = fileperms($path);
        if (!is_int($mode)) origin_fail("cannot stat mode for $path");
        return $mode & 0777;
    }

    function origin_remove_tree(string $path): void {
        $stat = @lstat($path);
        if (!is_array($stat)) return;
        if (($stat['mode'] & 0170000) !== 0040000 || is_link($path)) {
            if (!@unlink($path)) origin_fail("could not remove fixture path $path");
            return;
        }
        $entries = scandir($path);
        if (!is_array($entries)) origin_fail("could not enumerate fixture path $path");
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') origin_remove_tree($path . '/' . $entry);
        }
        if (!@rmdir($path)) origin_fail("could not remove fixture directory $path");
    }

    $fixture = sys_get_temp_dir() . '/duo-origin-export-' . bin2hex(random_bytes(8));
    $repo = $fixture . '/repo';
    if (!mkdir($repo . '/.duo/control', 0700, true)
        || !mkdir($repo . '/state', 0700)) {
        origin_fail('could not create origin-export fixture repository');
    }
    file_put_contents($repo . '/.gitignore', ".duo/\nstate/ignored.json\n");
    file_put_contents($repo . '/site.duo.json', "{}\n");
    file_put_contents($repo . '/state/base.json', "{}\n");
    origin_process(['git', 'init', '--quiet', $repo]);
    origin_git($repo, 'config', 'user.email', 'duo-origin@example.test');
    origin_git($repo, 'config', 'user.name', 'Duo Origin Fixture');
    origin_git($repo, 'add', '.gitignore', 'site.duo.json', 'state/base.json');
    origin_git($repo, 'commit', '--quiet', '-m', 'origin fixture');
    $head = origin_git($repo, 'rev-parse', '--verify', 'HEAD^{commit}');
    origin_check(preg_match('/^[a-f0-9]{40}$/D', $head) === 1, 'fixture HEAD is malformed');

    try {
        chmod($repo . '/.duo', 0777);
        origin_expect(
            static fn() => OriginExporter::seal($repo, hash('sha256', 'open duo parent'), $head),
            'Duo state directory is not protected',
            'origin export accepted a group/world-writable Duo authority parent'
        );
        chmod($repo . '/.duo', 0700);
        chmod($repo . '/.duo/control', 0755);
        origin_expect(
            static fn() => OriginExporter::seal($repo, hash('sha256', 'open control parent'), $head),
            'Duo control directory is not protected',
            'origin export accepted a non-private control authority parent'
        );
        chmod($repo . '/.duo/control', 0700);
        origin_check(RefreshExport::$calls === 0 && Policy::$loads === 0,
            'unsafe authority-parent refusal reached policy or the live observer');

        $plainAdoptedRepo = $fixture . '/plain-adopted-repo';
        if (!mkdir($plainAdoptedRepo . '/.duo/control', 0700, true)) {
            origin_fail('could not create plain adopted origin fixture');
        }
        file_put_contents($plainAdoptedRepo . '/site.duo.json', "{}\n");
        origin_expect(
            static fn() => OriginExporter::seal(
                $plainAdoptedRepo,
                hash('sha256', 'plain adopted repository'),
                $head
            ),
            'HEAD does not match',
            'origin export accepted an adopted seed directory without a target Git worktree'
        );
        origin_check(RefreshExport::$calls === 0 && Policy::$loads === 0,
            'Git-less adopted-repository refusal reached policy or the live observer');

        $session = hash('sha256', 'first sealed export');
        $manifest = OriginExporter::seal($repo, $session, $head);
        origin_check(RefreshExport::$calls === 1, 'first seal did not perform exactly one live export');
        origin_check(Policy::$loads === 1, 'first seal did not preflight policy exactly once');
        origin_check(($manifest['format'] ?? null) === OriginStore::MANIFEST_FORMAT,
            'sealed manifest has the wrong format');
        origin_check(count($manifest['chunks'] ?? []) === 2,
            'canonical export was not split into deterministic fixed chunks');
        origin_check(($manifest['chunks'][0]['length'] ?? null) === OriginStore::CHUNK_BYTES,
            'first origin-export chunk is not the fixed chunk size');
        origin_check(($manifest['chunks'][1]['length'] ?? 0) > 0
            && ($manifest['chunks'][1]['length'] ?? 0) < OriginStore::CHUNK_BYTES,
            'final origin-export chunk has an invalid bounded length');

        $cloudRoot = $repo . '/.duo/control/cloud-origin';
        $sessionRoot = $cloudRoot . '/sessions/' . $session;
        foreach ([$cloudRoot, $cloudRoot . '/sessions', $sessionRoot, $sessionRoot . '/chunks'] as $directory) {
            origin_check(is_dir($directory) && !is_link($directory) && origin_mode($directory) === 0700,
                "origin-export directory is not private and non-symlink: $directory");
            origin_check(!function_exists('posix_geteuid') || fileowner($directory) === posix_geteuid(),
                "origin-export directory is not process-owned: $directory");
        }
        foreach ([$cloudRoot . '/export.lock', $sessionRoot . '/manifest.json'] as $file) {
            origin_check(is_file($file) && !is_link($file) && origin_mode($file) === 0600,
                "origin-export control file is not private and non-symlink: $file");
            origin_check(!function_exists('posix_geteuid') || fileowner($file) === posix_geteuid(),
                "origin-export control file is not process-owned: $file");
        }
        $artifact = '';
        foreach ($manifest['chunks'] as $chunk) {
            $path = $sessionRoot . '/chunks/' . sprintf(
                '%06d-%s.chunk',
                $chunk['index'],
                $chunk['sha256']
            );
            origin_check(is_file($path) && !is_link($path) && origin_mode($path) === 0600,
                'origin-export chunk is not private and non-symlink');
            origin_check(!function_exists('posix_geteuid') || fileowner($path) === posix_geteuid(),
                'origin-export chunk is not process-owned');
            $bytes = file_get_contents($path);
            if (!is_string($bytes)) origin_fail('could not read sealed fixture chunk');
            $artifact .= $bytes;
        }
        origin_check(strlen($artifact) === $manifest['artifact']['bytes']
            && hash_equals($manifest['artifact']['sha256'], hash('sha256', $artifact)),
            'sealed chunk stream does not reconstruct the canonical artifact');
        origin_check(Canon::encode(Canon::decode($artifact)) === $artifact,
            'sealed origin export is not canonical JSON');
        $wireStore = OriginStore::open($repo);
        $wireManifest = $wireStore->wireManifest($session, $head, 7);
        $wireBasis = $wireManifest;
        unset($wireBasis['manifest_sha256']);
        origin_check(array_keys($wireManifest) === [
            'artifact_hash', 'chunks', 'code_revision', 'expected_production_commit',
            'export_sha256', 'export_size', 'format', 'generation',
            'repository_revision_hash', 'snapshot_hash', 'manifest_sha256',
        ]
            && $wireManifest['format'] === OriginStore::WIRE_MANIFEST_FORMAT
            && $wireManifest['generation'] === 7
            && $wireManifest['export_sha256'] === $manifest['artifact']['sha256']
            && $wireManifest['export_size'] === $manifest['artifact']['bytes']
            && $wireManifest['artifact_hash'] === $manifest['repository']['artifact_hash']
            && $wireManifest['repository_revision_hash'] === $manifest['repository']['revision_hash']
            && hash_equals(
                $wireManifest['manifest_sha256'],
                hash('sha256', Canon::encode($wireBasis))
            ),
            'local sealed session did not map to the exact generation-bound wire manifest');
        $wireArtifact = '';
        foreach ($wireManifest['chunks'] as $chunk) {
            $wireArtifact .= $wireStore->readChunk(
                $session,
                $head,
                $chunk['index'],
                $chunk['sha256']
            );
        }
        origin_check(hash_equals($wireManifest['export_sha256'], hash('sha256', $wireArtifact)),
            'wire upload reads did not reproduce the already-verified sealed artifact');
        origin_expect(
            static fn() => $wireStore->readChunk($session, $head, 0, hash('sha256', 'foreign chunk')),
            'does not match the sealed session',
            'origin spool returned bytes for a foreign chunk identity'
        );
        $manifestBytes = file_get_contents($sessionRoot . '/manifest.json');
        origin_check(is_string($manifestBytes)
            && Canon::encode(Canon::decode($manifestBytes)) === $manifestBytes,
            'published origin-export manifest is not canonical JSON');

        putenv('DUO_TEST_MODE=1');
        putenv('DUO_TEST_ORIGIN_FAIL_PHASE=after-discard-rename');
        origin_expect(
            static fn() => $wireStore->discardSealed($session, $head),
            'injected origin export interruption at after-discard-rename',
            'origin spool discard did not expose its durable tombstone recovery seam'
        );
        $discardTombstone = $cloudRoot . '/sessions/.deleting-' . $session . '.data';
        origin_check(!file_exists($sessionRoot) && is_dir($discardTombstone),
            'interrupted origin spool discard did not publish exactly one tombstone');
        putenv('DUO_TEST_ORIGIN_FAIL_PHASE');
        $wireStore->discardSealed($session, $head);
        origin_check(!file_exists($sessionRoot)
            && !file_exists($discardTombstone)
            && !file_exists($cloudRoot . '/sessions/.deleting-' . $session . '.json'),
            'resumed origin spool discard retained production-content bytes or intent');
        $wireStore->discardSealed($session, $head);
        origin_check(!file_exists($sessionRoot),
            'an exact origin spool discard replay resurrected its session');
        putenv('DUO_TEST_MODE');

        $calls = RefreshExport::$calls;
        $loads = Policy::$loads;
        origin_expect(
            static fn() => OriginExporter::seal(
                $repo,
                hash('sha256', 'wrong head'),
                str_repeat('f', 40)
            ),
            'HEAD does not match',
            'origin export accepted the wrong production HEAD'
        );
        origin_check(RefreshExport::$calls === $calls && Policy::$loads === $loads,
            'wrong-HEAD refusal occurred after policy or live capture');

        file_put_contents($repo . '/ordinary-untracked.txt', "dirty\n");
        origin_expect(
            static fn() => OriginExporter::seal($repo, hash('sha256', 'dirty before'), $head),
            'tracked or untracked changes',
            'origin export accepted ordinary untracked bytes'
        );
        unlink($repo . '/ordinary-untracked.txt');
        origin_check(RefreshExport::$calls === $calls && Policy::$loads === $loads,
            'dirty-tree refusal occurred after policy or live capture');

        file_put_contents($repo . '/state/ignored.json', "ignored canonical input\n");
        origin_expect(
            static fn() => OriginExporter::seal($repo, hash('sha256', 'ignored before'), $head),
            'ignored canonical compiler inputs',
            'origin export accepted ignored canonical input bytes'
        );
        unlink($repo . '/state/ignored.json');
        origin_check(RefreshExport::$calls === $calls && Policy::$loads === $loads,
            'ignored-input refusal occurred after policy or live capture');

        Policy::$siteFixture = ['policy' => ['allow_secret' => true]];
        origin_expect(
            static fn() => OriginExporter::seal($repo, hash('sha256', 'secret grant'), $head),
            'allow_secret',
            'origin export accepted an explicit secret egress grant'
        );
        Policy::$siteFixture = [];
        origin_check(RefreshExport::$calls === $calls,
            'allow_secret preflight touched the live exporter');

        Policy::$manifestFixture = [['rules' => ['allow_pii' => true]]];
        origin_expect(
            static fn() => OriginExporter::seal($repo, hash('sha256', 'pii grant'), $head),
            'allow_pii',
            'origin export accepted an explicit PII egress grant'
        );
        Policy::$manifestFixture = [];
        origin_check(RefreshExport::$calls === $calls,
            'allow_pii preflight touched the live exporter');

        Policy::$manifestFixture = [['interpreter' => 'dynamic-fixture']];
        Policy::$interpreterCapabilityDeclared = false;
        origin_expect(
            static fn() => OriginExporter::seal($repo, hash('sha256', 'interpreter'), $head),
            'does not declare its egress sensitivity capabilities',
            'origin export guessed that an undeclared interpreter could not synthesize a sensitivity grant'
        );
        Policy::$interpreterCapabilityDeclared = true;
        origin_check(RefreshExport::$calls === $calls,
            'undeclared interpreter preflight touched the live exporter');

        Policy::$interpreterGrantFixture = ['allow_pii'];
        origin_expect(
            static fn() => OriginExporter::seal($repo, hash('sha256', 'interpreter grant'), $head),
            'allow_pii',
            'origin export accepted a sensitivity grant synthesized by an interpreter'
        );
        Policy::$interpreterGrantFixture = [];
        origin_check(RefreshExport::$calls === $calls,
            'interpreter sensitivity preflight touched the live exporter');

        $safeInterpreterSession = hash('sha256', 'safe interpreter');
        $safeInterpreter = OriginExporter::seal($repo, $safeInterpreterSession, $head);
        origin_check(($safeInterpreter['session_id'] ?? null) === $safeInterpreterSession,
            'an interpreter with an explicit empty sensitivity capability could not export');
        origin_check(RefreshExport::$calls === $calls + 1,
            'safe interpreter export did not perform exactly one coherent observation');
        Policy::$manifestFixture = [];
        $calls = RefreshExport::$calls;

        RefreshExport::$mode = 'secret-sentinel';
        $secretSentinelSession = hash('sha256', 'secret sentinel');
        origin_expect(
            static fn() => OriginExporter::seal($repo, $secretSentinelSession, $head),
            'high-confidence stripe key',
            'origin export sealed a secret that survived the ordinary capture boundary'
        );
        RefreshExport::$mode = 'normal';
        origin_check(RefreshExport::$calls === $calls + 1,
            'secret defense-in-depth check did not follow exactly one coherent observation');
        origin_check(!file_exists($cloudRoot . '/sessions/' . $secretSentinelSession),
            'secret defense-in-depth refusal left a visible sealed session');
        $calls = RefreshExport::$calls;

        RefreshExport::$mode = 'post-capture-drift';
        $postDriftSession = hash('sha256', 'post capture drift');
        origin_expect(
            static fn() => OriginExporter::seal($repo, $postDriftSession, $head),
            'tracked or untracked changes',
            'origin export published bytes after the repository changed during capture'
        );
        RefreshExport::$mode = 'normal';
        unlink($repo . '/state/post-capture-drift.json');
        origin_check(RefreshExport::$calls === $calls + 1,
            'post-capture Git guard did not follow exactly one live export');
        origin_check(!file_exists($cloudRoot . '/sessions/' . $postDriftSession),
            'post-capture drift left a visible sealed session');
        $calls = RefreshExport::$calls;

        putenv('DUO_TEST_MODE=1');
        putenv('DUO_TEST_ORIGIN_FAIL_PHASE=after-manifest-before-publish');
        $beforePublishSession = hash('sha256', 'before publish interruption');
        origin_expect(
            static fn() => OriginExporter::seal($repo, $beforePublishSession, $head),
            'after-manifest-before-publish',
            'origin export did not expose the pre-publication interruption checkpoint'
        );
        origin_check(!file_exists($cloudRoot . '/sessions/' . $beforePublishSession),
            'pre-publication interruption exposed a canonical session path');
        $builds = glob($cloudRoot . '/sessions/.building-*');
        origin_check(is_array($builds) && $builds === [],
            'pre-publication interruption left an ambiguous build tree');
        origin_check(RefreshExport::$calls === $calls + 1,
            'pre-publication attempt did not capture exactly once');
        putenv('DUO_TEST_ORIGIN_FAIL_PHASE');
        $recoveredBeforePublish = OriginExporter::seal($repo, $beforePublishSession, $head);
        origin_check(($recoveredBeforePublish['session_id'] ?? null) === $beforePublishSession
            && RefreshExport::$calls === $calls + 2,
            'retry after an unpublished interruption did not perform one fresh coherent capture');
        $calls = RefreshExport::$calls;

        $killedRetrySession = hash('sha256', 'killed build retried');
        $killedRetryStatus = origin_kill_export($repo, $killedRetrySession, $head);
        $killedRetryBuild = $cloudRoot . '/sessions/.building-' . $killedRetrySession . '.data';
        origin_check(origin_was_killed($killedRetryStatus)
            && is_dir($killedRetryBuild)
            && is_file($killedRetryBuild . '/manifest.json'),
            'real SIGKILL did not retain exactly one deterministic unpublished build');
        $recoveredKilled = OriginExporter::seal($repo, $killedRetrySession, $head);
        origin_check(($recoveredKilled['session_id'] ?? null) === $killedRetrySession
            && RefreshExport::$calls === $calls + 1
            && !file_exists($killedRetryBuild),
            'retry did not remove the killed unpublished build and publish one fresh artifact');
        $calls = RefreshExport::$calls;

        $killedDiscardSession = hash('sha256', 'killed build discarded');
        $killedDiscardStatus = origin_kill_export($repo, $killedDiscardSession, $head);
        $killedDiscardBuild = $cloudRoot . '/sessions/.building-' . $killedDiscardSession . '.data';
        origin_check(origin_was_killed($killedDiscardStatus) && is_dir($killedDiscardBuild),
            'real SIGKILL did not retain the build needed for revoke cleanup evidence');
        OriginStore::open($repo)->discardSealed($killedDiscardSession, $head);
        origin_check(!file_exists($killedDiscardBuild)
            && !file_exists($cloudRoot . '/sessions/' . $killedDiscardSession),
            'terminal discard retained production content from a killed unpublished build');

        $unsafeBuildSession = hash('sha256', 'unsafe killed build');
        $unsafeBuild = $cloudRoot . '/sessions/.building-' . $unsafeBuildSession . '.data';
        $outsideBuild = $fixture . '/outside-killed-build';
        if (!mkdir($outsideBuild, 0700)
            || file_put_contents($outsideBuild . '/sentinel', "outside\n") === false
            || !symlink($outsideBuild, $unsafeBuild)) {
            origin_fail('could not construct unsafe interrupted-build fixture');
        }
        origin_expect(
            static fn() => OriginStore::open($repo)->discardSealed($unsafeBuildSession, $head),
            'directory is not protected',
            'interrupted-build cleanup followed a symlink outside provider-owned state'
        );
        origin_check(is_file($outsideBuild . '/sentinel'),
            'interrupted-build cleanup mutated the symlink target');
        unlink($unsafeBuild);
        origin_remove_tree($outsideBuild);

        putenv('DUO_TEST_ORIGIN_FAIL_PHASE=after-publish');
        $afterPublishSession = hash('sha256', 'after publish interruption');
        origin_expect(
            static fn() => OriginExporter::seal($repo, $afterPublishSession, $head),
            'after-publish',
            'origin export did not expose the post-publication interruption checkpoint'
        );
        origin_check(RefreshExport::$calls === $calls + 1
            && is_file($cloudRoot . '/sessions/' . $afterPublishSession . '/manifest.json'),
            'post-publication interruption did not leave one complete terminal artifact');
        putenv('DUO_TEST_MODE');
        putenv('DUO_TEST_ORIGIN_FAIL_PHASE');

        $loadsBeforeReplay = Policy::$loads;
        $callsBeforeReplay = RefreshExport::$calls;
        Policy::$failLoad = true;
        RefreshExport::$mode = 'throw';
        if (!rename($repo . '/.git', $fixture . '/git-offline')
            || !rename($repo . '/site.duo.json', $fixture . '/site-offline.json')) {
            origin_fail('could not detach live repository inputs for sealed-retry proof');
        }
        $replayed = OriginExporter::seal($repo, $afterPublishSession, $head);
        origin_check(Canon::encode($replayed) === Canon::encode(
            Canon::decode((string) file_get_contents(
                $cloudRoot . '/sessions/' . $afterPublishSession . '/manifest.json'
            ))
        ), 'sealed retry did not return the exact published manifest');
        origin_check(Policy::$loads === $loadsBeforeReplay
            && RefreshExport::$calls === $callsBeforeReplay,
            'sealed retry touched policy or the live production observer');
    } finally {
        putenv('DUO_TEST_MODE');
        putenv('DUO_TEST_ORIGIN_FAIL_PHASE');
        putenv('DUO_TEST_ORIGIN_KILL_PHASE');
        origin_remove_tree($fixture);
    }
}
PHP;
$originChild = str_replace('__PROJECT_ROOT__', var_export($root, true), $originChild);
$originChildPath = tempnam(sys_get_temp_dir(), 'duo-origin-child-');
if (!is_string($originChildPath)) fail_re('could not allocate origin-export child fixture');
try {
    if (file_put_contents($originChildPath, $originChild) !== strlen($originChild)) {
        fail_re('could not write origin-export child fixture');
    }
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $originChildPath],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) fail_re('could not start origin-export child fixture');
    fclose($pipes[0]);
    $originStdout = stream_get_contents($pipes[1]);
    $originStderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $originExit = proc_close($process);
    check_re($originExit === 0,
        'origin-export child fixture failed: ' . trim((string) $originStdout . "\n" . (string) $originStderr));
} finally {
    @unlink($originChildPath);
}

// The generic interpreter case above proves OriginExporter's fail-closed
// contract. Compose the real certified ACF manifest, its shipped interpreter,
// real Policy, OriginStore, and OriginExporter in a second process so a Policy
// test double cannot mask the exact supported-origin path.
$acfOriginChild = <<<'PHP'
<?php
declare(strict_types=1);

namespace Duo {
    final class RefreshExport {
        public const FORMAT = 'duo-refresh-production/v1';
        public static int $calls = 0;

        /** @return array<string,mixed> */
        public static function run(
            string $_repo,
            bool $_forceUnresolvedRefs = false,
            ?array $_scopeRequest = null
        ): array {
            self::$calls++;
            $payload = [
                'completed_code' => null,
                'deletions' => [],
                'format' => self::FORMAT,
                'media' => [],
                'policy' => [
                    'manifest_hash' => hash('sha256', 'real-acf-manifest'),
                    'resolved_adapters' => ['acf'],
                    'site_hash' => hash('sha256', 'real-acf-site'),
                ],
                'records' => [],
                'repository' => [
                    'artifact_hash' => hash('sha256', 'real-acf-artifact'),
                    'code_revision' => null,
                    'revision_hash' => hash('sha256', 'real-acf-revision'),
                ],
                'warnings' => [],
            ];
            $payload['snapshot_hash'] = hash('sha256', Canon::encode($payload));
            return $payload;
        }
    }
}

namespace {
    $projectRoot = __PROJECT_ROOT__;
    $platform = json_decode(
        (string) file_get_contents($projectRoot . '/manifests/capabilities/platform.json'),
        true,
        32,
        JSON_THROW_ON_ERROR
    );
    define('WP_CLI', true);
    define('DUO_CONTROL_PLANE', true);
    define('DUO_AGENT_VERSION', (string) $platform['platform']['agent_version']);
    define('DUO_SPEC_VERSION', (int) $platform['platform']['spec_version']);
    putenv('DUO_MANIFESTS_DIR=' . $projectRoot . '/manifests');

    require_once $projectRoot . '/agent/src/Kernel/Canon.php';
    require_once $projectRoot . '/agent/src/Policy/Policy.php';
    require_once $projectRoot . '/agent/src/Cloud/OriginStore.php';
    require_once $projectRoot . '/agent/src/Cloud/OriginExporter.php';

    $fixture = sys_get_temp_dir() . '/duo-real-acf-origin-' . bin2hex(random_bytes(8));
    $repo = $fixture . '/site';
    $remove = static function (string $path) use (&$remove): void {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $remove($path . '/' . $entry);
            }
        }
        @rmdir($path);
    };
    $run = static function (array $command, string $cwd): string {
        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('could not start real ACF composition command');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            throw new \RuntimeException(
                'real ACF composition command failed: ' . trim((string) $stderr)
            );
        }
        return trim((string) $stdout);
    };

    try {
        if (!mkdir($repo . '/.duo/control', 0700, true)
            || !chmod($fixture, 0700)
            || !chmod($repo, 0700)
            || !chmod($repo . '/.duo', 0700)
            || !chmod($repo . '/.duo/control', 0700)) {
            throw new \RuntimeException('could not create private real ACF origin fixture');
        }
        Duo\Canon::write_file($repo . '/.gitignore', ".duo/\n");
        Duo\Canon::write_file($repo . '/site.duo.json', Duo\Canon::encode([
            'manifests' => ['acf'],
            'policy' => [
                'options' => new \stdClass(),
                'post_meta' => new \stdClass(),
                'post_types' => [],
                'taxonomies' => [],
                'term_meta' => new \stdClass(),
            ],
            'spec_version' => DUO_SPEC_VERSION,
        ]));
        $run(['git', 'init', '-q', '-b', 'production'], $repo);
        $run(['git', 'config', 'user.email', 'real-acf-origin@example.invalid'], $repo);
        $run(['git', 'config', 'user.name', 'Real ACF Origin'], $repo);
        $run(['git', 'add', '.'], $repo);
        $run(['git', 'commit', '-qm', 'real ACF origin'], $repo);
        $head = $run(['git', 'rev-parse', 'HEAD'], $repo);

        $policy = Duo\Policy::load($repo);
        $interpreters = $policy->interpreters();
        if (($interpreters['acf'] ?? null) === null
            || get_class($interpreters['acf']) !== Duo\Interpreters\Acf::class
            || $policy->egress_sensitivity_grants() !== []) {
            throw new \RuntimeException('real ACF policy did not prove its closed egress capability');
        }

        $session = hash('sha256', 'real shipped acf egress');
        $sealed = Duo\OriginExporter::seal($repo, $session, $head);
        if (($sealed['format'] ?? null) !== Duo\OriginStore::MANIFEST_FORMAT
            || ($sealed['session_id'] ?? null) !== $session
            || Duo\RefreshExport::$calls !== 1) {
            throw new \RuntimeException(
                'real certified ACF origin did not complete exactly one sealed observation'
            );
        }
    } finally {
        putenv('DUO_MANIFESTS_DIR');
        $remove($fixture);
    }
}
PHP;
$acfOriginChild = str_replace('__PROJECT_ROOT__', var_export($root, true), $acfOriginChild);
$acfOriginChildPath = tempnam(sys_get_temp_dir(), 'duo-acf-origin-child-');
if (!is_string($acfOriginChildPath)) fail_re('could not allocate real ACF origin child fixture');
try {
    if (file_put_contents($acfOriginChildPath, $acfOriginChild) !== strlen($acfOriginChild)) {
        fail_re('could not write real ACF origin child fixture');
    }
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $acfOriginChildPath],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) fail_re('could not start real ACF origin child fixture');
    fclose($pipes[0]);
    $acfOriginStdout = stream_get_contents($pipes[1]);
    $acfOriginStderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $acfOriginExit = proc_close($process);
    check_re($acfOriginExit === 0,
        'real certified ACF origin composition failed: '
            . trim((string) $acfOriginStdout . "\n" . (string) $acfOriginStderr));
} finally {
    @unlink($acfOriginChildPath);
}
echo "REGRESS_REFRESH_EXPORT_UNIT PASSED\n";

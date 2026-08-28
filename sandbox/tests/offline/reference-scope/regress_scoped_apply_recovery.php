<?php
declare(strict_types=1);

/**
 * Offline DUO-3344 scoped-apply recovery matrix.
 *
 * The harness deliberately uses the public protocol seams instead of a
 * WordPress fixture: a byte-CAS session store, a value-only scoped target
 * observation, and operation-bound provider/native effect stubs.  It proves
 * that an authored boundary is decided once, response loss reconciles the
 * exact operation, protected/selected drift becomes recovery_required, and a
 * terminal result can be replayed byte-for-byte.  No product file is changed
 * by this test and no target process is started.
 */

$root = dirname(__DIR__, 4);
// WP-4.12: derived from agent/duo.php — this suite reaches the shipped
// platform.json, which restates both defines.
require_once __DIR__ . '/../../lib/agent_version.php';
duo_test_define_agent_versions();
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

$duoAgentClassmap = require $root . '/agent/duo-classmap.php';
if (!is_array($duoAgentClassmap)) {
    throw new \RuntimeException('regress_scoped_apply_recovery: agent/duo-classmap.php did not return a map');
}
$duoAgentFiles = [];
foreach ($duoAgentClassmap as $duoAgentPath) {
    $duoAgentFiles[basename((string) $duoAgentPath, '.php')] = (string) $duoAgentPath;
}
foreach ([
    'Canon', 'Uuid', 'OrderPreserved', 'OptionState', 'UserMetaState', 'Db',
    'TransientDbException', 'PlainData', 'StructuredValue', 'Secrets',
    'PersonalData', 'AdapterSources', 'ManifestDispositions', 'TargetProbe',
    'AdapterRegistry', 'NativeActions', 'ReferenceRules', 'Policy',
    'ReferenceGraph', 'CodeCompatibility', 'RepositoryCompiler',
    'ScopeClosure', 'CanonicalSurfaces', 'CanonicalMapWitness', 'CanonicalLedgerMapGuard',
    'Deletion', 'SidebarState', 'Snapshot',
    'RepositoryAuthorization', 'Tokens', 'ScopeContract',
    'ScopedStateOverlay', 'ScopedApplySession', 'CommandRefusal', 'ScopedApply', 'ScopedApplyCoordinator',
    'ScopedApplyWorkProjector',
    'Providers', 'ProviderActionBatchBuilder', 'RebuildActionDispatcher',
    'Canary', 'Ledger', 'PromotionLock', 'Apply',
] as $file) {
    $duoAgentFile = $duoAgentFiles[$file] ?? null;
    if (!is_string($duoAgentFile)) {
        throw new \RuntimeException('regress_scoped_apply_recovery: agent source ' . $file . '.php is absent from agent/duo-classmap.php');
    }
    require_once $root . '/agent/' . $duoAgentFile;
}

use Duo\Canon;
use Duo\CanonicalLedgerMapGuard;
use Duo\CompiledRepository;
use Duo\NativeActions;
use Duo\Policy;
use Duo\PromotionLock;
use Duo\Providers;
use Duo\ScopeContract;
use Duo\ScopedApply;
use Duo\ScopedApplySession;
use Duo\ScopedApplySessionStorage;

final class ScopedRecoveryMemoryStore implements ScopedApplySessionStorage {
    /** @var array<string,?string> */
    public array $values = [];
    public bool $forceConflict = false;

    public function read(string $key): ?string {
        if ($key !== ScopedApplySession::STORAGE_KEY
            && !str_starts_with($key, ScopedApplySession::TERMINAL_KEY_PREFIX)
            && !str_starts_with($key, ScopedApplySession::TERMINAL_REQUEST_KEY_PREFIX)) {
            throw new RuntimeException('unexpected scoped recovery storage key');
        }
        return $this->values[$key] ?? null;
    }

    public function compare_and_swap(string $key, ?string $expected, ?string $replacement): bool {
        if ($this->forceConflict) {
            $this->forceConflict = false;
            return false;
        }
        if ($this->read($key) !== $expected) {
            return false;
        }
        if ($replacement === null) {
            unset($this->values[$key]);
        } else {
            $this->values[$key] = $replacement;
        }
        return true;
    }
}

/** Minimal options/cache target for the real operation-bound effect seams. */
final class ScopedRecoveryEffectWpdb {
    public string $options = 'wp_options';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $terms = 'wp_terms';
    public string $termmeta = 'wp_termmeta';
    public string $term_taxonomy = 'wp_term_taxonomy';
    public string $term_relationships = 'wp_term_relationships';
    public string $prefix = 'wp_';
    public string $last_error = '';
    public bool $failResults = false;
    /** @var array<string,string> */
    public array $optionRows = [];
    /** @var array<string,string> */
    public array $kvRows = [];
    /** @var list<array{uuid:string,entity_type:string,id_kind:string,local_id:int}> */
    public array $mapRows = [];
    /** @var array<int,array{post_type:string,post_status?:string,uuid:string,term_taxonomy_ids:list<int>}> */
    public array $postRows = [];
    /** @var array<int,string> */
    public array $termRows = [];
    /** @var array<int,array{term_id:int,taxonomy:string}> */
    public array $taxonomyRows = [];

    public function get_charset_collate(): string {
        return '';
    }

    public function prepare(string $query, mixed ...$args): string {
        foreach ($args as $arg) {
            $query = preg_replace_callback('/%[sd]/', static function (array $match) use ($arg): string {
                return $match[0] === '%d'
                    ? (string) (int) $arg
                    : "'" . str_replace("'", "''", (string) $arg) . "'";
            }, $query, 1) ?? $query;
        }
        return $query;
    }

    public function get_var(string $query): string|false|null {
        $this->last_error = '';
        if (preg_match("/SELECT v FROM wp_duo_kv WHERE k = '([^']*)'/", $query, $match) === 1) {
            return $this->kvRows[$match[1]] ?? null;
        }
        if (preg_match("/option_name = '([^']*)'/", $query, $match) === 1) {
            return $this->optionRows[$match[1]] ?? null;
        }
        if (preg_match('/SELECT 1 FROM wp_posts WHERE ID = ([0-9]+)/', $query, $match) === 1) {
            return isset($this->postRows[(int) $match[1]]) ? '1' : null;
        }
        if (preg_match('/SELECT 1 FROM wp_terms WHERE term_id = ([0-9]+)/', $query, $match) === 1) {
            return isset($this->termRows[(int) $match[1]]) ? '1' : null;
        }
        if (preg_match('/SELECT 1 FROM wp_term_taxonomy WHERE term_id = ([0-9]+)/', $query, $match) === 1) {
            foreach ($this->taxonomyRows as $row) {
                if ($row['term_id'] === (int) $match[1]) return '1';
            }
            return null;
        }
        if (preg_match('/SELECT 1 FROM wp_term_taxonomy WHERE term_taxonomy_id = ([0-9]+)/', $query, $match) === 1) {
            return isset($this->taxonomyRows[(int) $match[1]]) ? '1' : null;
        }
        return null;
    }

    public function get_row(string $query, mixed $output = null): array|false|null {
        $this->last_error = '';
        if (preg_match("/FROM wp_duo_map WHERE uuid = '([^']+)' AND id_kind = '([^']+)'/", $query, $match) === 1) {
            foreach ($this->mapRows as $row) {
                if ($row['uuid'] === $match[1] && $row['id_kind'] === $match[2]) {
                    return ['entity_type' => $row['entity_type'], 'local_id' => $row['local_id']];
                }
            }
            return null;
        }
        if (preg_match("/FROM wp_duo_map WHERE id_kind = '([^']+)' AND local_id = ([0-9]+)/", $query, $match) === 1) {
            foreach ($this->mapRows as $row) {
                if ($row['id_kind'] === $match[1] && $row['local_id'] === (int) $match[2]) {
                    return ['uuid' => $row['uuid'], 'entity_type' => $row['entity_type']];
                }
            }
            return null;
        }
        if (preg_match('/WHERE p.ID = ([0-9]+)/', $query, $match) === 1) {
            $id = (int) $match[1];
            $row = $this->postRows[$id] ?? null;
            if ($row === null) return null;
            if (preg_match('/tr.term_taxonomy_id = ([0-9]+)/', $query, $tt) === 1
                && !in_array((int) $tt[1], $row['term_taxonomy_ids'], true)) {
                return null;
            }
            if (preg_match("/p\\.post_status\\s*=\\s*'([^']+)'/", $query, $status) === 1
                && ($row['post_status'] ?? 'publish') !== $status[1]) {
                return null;
            }
            return [
                'ID' => $id,
                'post_type' => $row['post_type'],
                'post_status' => $row['post_status'] ?? 'publish',
                'duo_uuid' => $row['uuid'],
            ];
        }
        if (preg_match('/WHERE t.term_id = ([0-9]+) AND tt.term_taxonomy_id = ([0-9]+)/', $query, $match) === 1) {
            $termId = (int) $match[1];
            $ttId = (int) $match[2];
            $tt = $this->taxonomyRows[$ttId] ?? null;
            if ($tt === null || $tt['term_id'] !== $termId || !isset($this->termRows[$termId])) return null;
            return [
                'term_id' => $termId,
                'term_taxonomy_id' => $ttId,
                'taxonomy' => $tt['taxonomy'],
                'duo_uuid' => $this->termRows[$termId],
            ];
        }
        return null;
    }

    public function query(string $query): int {
        $this->last_error = '';
        return 0;
    }

    public function get_results(string $query, mixed $output = null): mixed {
        if ($this->failResults) {
            $this->last_error = 'DATABASE_SECRET_MUST_NOT_LEAK';
            return false;
        }
        $this->last_error = '';
        if (str_contains($query, 'FROM wp_duo_map')) {
            return $this->mapRows;
        }
        if (str_contains($query, 'FROM wp_posts p')
            && preg_match('/tr\.term_taxonomy_id = ([0-9]+)/', $query, $match) === 1) {
            $rows = [];
            foreach ($this->postRows as $id => $row) {
                if ($row['post_type'] !== 'nav_menu_item'
                    || !in_array((int) $match[1], $row['term_taxonomy_ids'], true)) {
                    continue;
                }
                $rows[] = [
                    'ID' => $id,
                    'post_type' => $row['post_type'],
                    'post_status' => $row['post_status'] ?? 'publish',
                    'duo_uuid' => $row['uuid'],
                ];
            }
            usort($rows, static fn(array $a, array $b): int => (int) $a['ID'] <=> (int) $b['ID']);
            return $rows;
        }
        if (str_contains($query, 'FROM wp_term_relationships tr')
            && preg_match('/tr\.object_id = ([0-9]+)/', $query, $match) === 1) {
            $post = $this->postRows[(int) $match[1]] ?? null;
            if ($post === null) {
                return [];
            }
            $rows = [];
            $navMenuOnly = str_contains($query, "tt.taxonomy = 'nav_menu'");
            foreach ($post['term_taxonomy_ids'] as $ttId) {
                $taxonomy = $this->taxonomyRows[$ttId] ?? null;
                if ($taxonomy !== null
                    && (!$navMenuOnly || $taxonomy['taxonomy'] === 'nav_menu')) {
                    $rows[] = [
                        'term_taxonomy_id' => $ttId,
                        'taxonomy' => $taxonomy['taxonomy'],
                    ];
                }
            }
            usort($rows, static fn(array $a, array $b): int =>
                (int) $a['term_taxonomy_id'] <=> (int) $b['term_taxonomy_id']
            );
            return $rows;
        }
        if (str_contains($query, 'FROM wp_options')
            && preg_match("/option_name = '((?:''|[^'])*)'/", $query, $match) === 1) {
            $name = str_replace("''", "'", $match[1]);
            if (!array_key_exists($name, $this->optionRows)) {
                return [];
            }
            $value = $this->optionRows[$name];
            return str_contains($query, 'OCTET_LENGTH(option_value)')
                ? [[
                    'option_name' => $name,
                    'option_value_bytes' => (string) strlen($value),
                    'option_value_sha256' => hash('sha256', $value),
                ]]
                : [['option_name' => $name, 'option_value' => $value]];
        }
        if (str_contains($query, "option_name LIKE 'widget")) {
            $rows = [];
            foreach ($this->optionRows as $name => $value) {
                if (str_starts_with($name, 'widget_')) {
                    $rows[] = str_contains($query, 'OCTET_LENGTH(option_value)')
                        ? [
                            'option_name' => $name,
                            'option_value_bytes' => (string) strlen($value),
                            'option_value_sha256' => hash('sha256', $value),
                        ]
                        : ['option_name' => $name, 'option_value' => $value];
                }
            }
            return $rows;
        }
        return [];
    }
}

$GLOBALS['wpdb'] = new ScopedRecoveryEffectWpdb();
$GLOBALS['scoped_recovery_cache'] = ['transient' => []];
$GLOBALS['scoped_recovery_delete_calls'] = 0;

function is_multisite(): bool {
    return false;
}

function untrailingslashit(string $value): string {
    return rtrim($value, '/');
}

function get_option(string $name, mixed $default = false): mixed {
    return $name === 'home' ? 'https://scoped-recovery.example.test' : $default;
}

function wp_upload_dir(mixed $time = null, bool $refresh = false): array {
    return ['baseurl' => 'https://scoped-recovery.example.test/wp-content/uploads'];
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): true {
    return true;
}

function add_option(string $name, mixed $value, mixed $deprecated = '', mixed $autoload = 'yes'): bool {
    global $wpdb;
    if (array_key_exists($name, $wpdb->optionRows)) {
        return false;
    }
    $wpdb->optionRows[$name] = (string) $value;
    return true;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool {
    global $wpdb;
    $old = $wpdb->optionRows[$name] ?? null;
    $wpdb->optionRows[$name] = (string) $value;
    return $old !== $wpdb->optionRows[$name];
}

function wp_cache_get(string $key, string $group = '', bool $force = false, mixed &$found = null): mixed {
    $found = array_key_exists($key, $GLOBALS['scoped_recovery_cache'][$group] ?? []);
    return $found ? $GLOBALS['scoped_recovery_cache'][$group][$key] : false;
}

function wp_cache_flush(): bool {
    return true;
}

function delete_transient(string $name): bool {
    global $wpdb;
    $GLOBALS['scoped_recovery_delete_calls']++;
    unset($wpdb->optionRows['_transient_' . $name]);
    unset($wpdb->optionRows['_transient_timeout_' . $name]);
    unset($GLOBALS['scoped_recovery_cache']['transient'][$name]);
    return true;
}

final class ScopedRecoveryProvider {
    public int $invocations = 0;
    public int $reconciliations = 0;
    public int $state = 0;

    public function invoke_scoped(string $capability, array $args, array $operation): array {
        $this->invocations++;
        $before = ['state' => $this->state];
        $this->state++;
        return [
            'operation' => $operation,
            'before' => $before,
            'after' => ['state' => $this->state],
            'verified' => true,
        ];
    }

    public function reconcile_scoped(string $capability, array $args, array $operation): array {
        $this->reconciliations++;
        return [
            'operation' => $operation,
            'after' => ['state' => $this->state],
            'verified' => true,
        ];
    }
}

$failures = 0;
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures++;
    }
};
$expectThrow = static function (callable $fn, string $needle, string $message) use (&$failures): void {
    try {
        $fn();
        echo "FAIL: $message (did not throw)\n";
        $failures++;
    } catch (Throwable $failure) {
        $ok = str_contains($failure->getMessage(), $needle);
        echo ($ok ? 'ok: ' : 'FAIL: ') . $message . ' (' . $failure->getMessage() . ")\n";
        if (!$ok) {
            $failures++;
        }
    }
};
$hash = static fn(string $label): string => hash('sha256', $label);
$uuid = static fn(int $n): string => sprintf('00000000-0000-4000-8000-%012d', $n);

// A pre-session-id promotion remains readable for ordinary full-promotion
// recovery, but it cannot seed scoped authority whose recovery protocol
// requires an exact random generation. The strict accessor must refuse before
// Apply renews the continuation lease or records any scoped session.
$legacyOwner = 'legacy-scoped-owner';
$legacyArtifact = $hash('legacy-scoped-artifact');
$legacyBegunAt = time() - 5;
$GLOBALS['wpdb']->kvRows['promotion_lock'] = Canon::encode([
    'owner' => $legacyOwner,
    'artifact_hash' => $legacyArtifact,
    'phase' => 'apply',
    'acquired_at' => $legacyBegunAt,
    'expires_at' => time() + 300,
]);
$GLOBALS['wpdb']->kvRows['promotion_session'] = Canon::encode([
    'owner' => $legacyOwner,
    'artifact_hash' => $legacyArtifact,
    'begun_at' => $legacyBegunAt,
]);
$legacySessionId = PromotionLock::session_id($legacyOwner, $legacyArtifact);
$check(
    str_starts_with($legacySessionId, 'legacy-'),
    'ordinary promotion recovery retains a stable legacy generation for a pre-session-id record'
);
$expectThrow(
    static fn() => PromotionLock::scoped_session_id($legacyOwner, $legacyArtifact),
    'exact random promotion session generation',
    'scoped authority refuses a legacy continuation generation before recovery can become impossible'
);
$randomSessionId = 'ps-' . str_repeat('a', 32);
$GLOBALS['wpdb']->kvRows['promotion_session'] = Canon::encode([
    'owner' => $legacyOwner,
    'artifact_hash' => $legacyArtifact,
    'begun_at' => $legacyBegunAt,
    'session_id' => $randomSessionId,
]);
$check(
    PromotionLock::scoped_session_id($legacyOwner, $legacyArtifact) === $randomSessionId,
    'scoped authority accepts the exact live random promotion session generation'
);
unset(
    $GLOBALS['wpdb']->kvRows['promotion_lock'],
    $GLOBALS['wpdb']->kvRows['promotion_session']
);

$selectedId = $uuid(1);
$protectedId = $uuid(2);
$beforeHash = $hash('selected-before');
$desiredHash = $hash('selected-desired');
$protectedHash = $hash('protected-before');
$sourceHash = $hash('scope-source');

/**
 * Construct the smallest strict scope contract accepted by the real
 * selected_set()/authored_state() seam.  The row is still a real root with a
 * UUID, provenance, and hash-bound source evidence; no contract internals are
 * bypassed.
 */
$contract = [
    'format' => ScopeContract::FORMAT,
    'purpose' => 'read-only scope evidence; never mutation authority',
    'read_only_evidence' => true,
    'mutation_authority' => false,
    'source' => [
        'artifact_hash' => $sourceHash,
        'state_revision_hash' => $hash('state-revision'),
        'manifest_hash' => $hash('manifest'),
    ],
    'code_diagnostic' => null,
    'selectors' => ['post:' . $selectedId],
    'resolution' => [
        'live_root_entities' => [$selectedId],
        'tombstone_uuids' => [],
    ],
    'live' => [
        'roots' => [[
            'entity' => $selectedId,
            'entity_hash' => $hash('selected-entity'),
            'path' => 'posts/page/' . $selectedId . '--selected.md',
            'type' => 'post',
            'source_hash' => $hash('selected-source'),
            'provenance' => ['kind' => 'root', 'selector' => 'post:' . $selectedId],
        ]],
        'closure' => [],
        'excluded' => [],
        'inbound' => [],
    ],
    'tombstones' => [],
    'uploads' => [],
    'media' => [],
    'eligible_surfaces' => [],
    'potential_actions' => [],
    'potential_providers' => [],
    'potential_effects' => [],
    'exclusions' => [
        'code' => 'excluded from scope semantics',
        'lifecycle' => 'excluded from scope semantics',
        'target_guard_witnesses' => 'excluded from immutable evidence',
        'mutation_execution' => 'deferred to the target operation',
    ],
];
$contract['scope_hash'] = $hash(Canon::encode($contract));
$contract = ScopeContract::from_array($contract);
$check(true, 'strict scope contract fixture validates before mutation tests');

$compiled = CompiledRepository::create([
    'tree' => [
        $selectedId => [
            'type' => 'post',
            'hash' => $desiredHash,
            'path' => 'posts/page/' . $selectedId . '--selected.md',
            'content' => 'desired canonical post bytes',
            'data' => [],
        ],
    ],
    'deletions' => [],
    'revision_hash' => $hash('compiled-revision'),
    'manifest_hash' => $hash('compiled-manifest'),
    'site_hash' => $hash('compiled-site'),
    'effects_inventory' => [],
]);
$policy = new Policy();

// Capture returns post entities in their canonical Markdown file format,
// unlike JSON-backed terms, menus, sidebars, and table rows. The selected
// identity rebind must parse that same front matter before proving the live
// post_type; treating every target entity as raw JSON made even a clean
// scoped post no-op refuse before authority.
$postFront = ['uuid' => $selectedId, 'type' => 'page'];
$postCompiled = CompiledRepository::create([
    'tree' => [
        $selectedId => [
            'type' => 'post',
            'hash' => $desiredHash,
            'path' => 'posts/page/' . $selectedId . '--selected.md',
            'content' => Canon::post_file($postFront, 'selected body'),
            'data' => $postFront,
        ],
    ],
    'deletions' => [],
    'revision_hash' => $hash('post-rebind-revision'),
    'manifest_hash' => $hash('post-rebind-manifest'),
    'site_hash' => $hash('post-rebind-site'),
    'effects_inventory' => [],
]);
$postActual = [
    $selectedId => [
        'type' => 'post',
        'hash' => $beforeHash,
        'content' => Canon::post_file($postFront, 'selected body'),
        'path' => 'posts/page/' . $selectedId . '--selected.md',
    ],
];
$GLOBALS['wpdb']->mapRows = [[
    'uuid' => $selectedId,
    'entity_type' => 'post',
    'id_kind' => 'post',
    'local_id' => 71,
]];
$GLOBALS['wpdb']->postRows = [
    71 => [
        'post_type' => 'page',
        'post_status' => 'publish',
        'uuid' => $selectedId,
        'term_taxonomy_ids' => [],
    ],
];
ScopedApply::assert_selected_ledger_map_observation(
    $policy,
    $contract,
    $postActual,
    [$hash($selectedId)],
    $postCompiled
);
$check(true, 'selected post map rebind parses canonical Markdown front matter before physical proof');
$GLOBALS['wpdb']->mapRows = [];
$GLOBALS['wpdb']->postRows = [];

$beforeRows = [[
    'identity_hash' => $hash($selectedId),
    'type' => 'post',
    'content_hash' => $beforeHash,
    'state' => 'live',
]];
$protectedRows = [[
    'identity_hash' => $hash($protectedId),
    'type' => 'post',
    'content_hash' => $protectedHash,
]];
$selectedBeforeRoot = ScopedApply::hash_rows($beforeRows);
$protectedRoot = ScopedApply::hash_rows($protectedRows);

$actualBefore = [
    $selectedId => ['type' => 'post', 'hash' => $beforeHash, 'content' => '', 'path' => 'selected'],
    $protectedId => ['type' => 'post', 'hash' => $protectedHash, 'content' => '', 'path' => 'protected'],
];
$check(
    ScopedApply::authored_state($actualBefore, $compiled, $policy, $contract, $selectedBeforeRoot) === 'before',
    'exact before observation is recognized before any authored transaction'
);

$workItem = [
    'identity_hash' => $hash($selectedId),
    'type' => 'post',
    'desired_hash' => $desiredHash,
];
$selection = [
    'work_hash' => ScopedApplySession::hash_value([$workItem]),
    'work_items' => [$workItem],
    'deletions_hash' => ScopedApplySession::hash_value([]),
    'deletion_items' => [],
    'action_declarations_hash' => ScopedApplySession::hash_value([]),
    'action_items' => [],
    'capabilities_hash' => ScopedApplySession::hash_value([]),
    'effects_hash' => ScopedApplySession::hash_value([]),
    'effect_items' => [],
    'ledger_map_identity_hashes' => [],
    'ledger_map_identity_set_hash' => ScopedApplySession::hash_value([]),
];
$artifactHash = $compiled->artifact_hash();
$authority = ScopedApplySession::make_authority(
    $contract['scope_hash'],
    [
        'artifact_hash' => $artifactHash,
        'state_revision_hash' => $compiled->revision_hash(),
        'manifest_hash' => $compiled->manifest_hash(),
    ],
    [
        'owner' => 'scoped-recovery-owner',
        'artifact_hash' => $artifactHash,
        'session_id' => 'scoped-recovery-session',
    ],
    [
        'selected_before_hash' => $selectedBeforeRoot,
        'selected_before_ledger_map_hash' => $hash('selected-map-before'),
        'protected_ledger_map_hash' => $hash('protected-map-before'),
        'protected_out_of_scope_hash' => $protectedRoot,
        'ledger_roots_hash' => $hash('ledger-roots'),
    ],
    [
        'precondition_hash' => $hash('preconditions'),
        'guard_witnesses_hash' => $hash('guards'),
    ],
    $selection,
    $hash('code-witness')
);

$sessionStore = new ScopedRecoveryMemoryStore();
$session = ScopedApplySession::begin($sessionStore, $authority);
$session->transition(ScopedApplySession::PHASE_AUTHORING);
$recoveryPlanner = new \Duo\ApplyPlanner(
    $policy,
    [],
    static fn(string $uuid, string $kind): ?int => null,
    static fn(string $uuid, string $kind): ?int => null
);
$convergedRecoveryWork = \Duo\ScopedApplyWorkProjector::project(
    [
        'create' => [],
        'adopt' => [],
        'update' => [],
        'unchanged' => [[
            'uuid' => $selectedId,
            'type' => 'post',
            'path' => 'posts/page/' . $selectedId . '--selected.md',
        ]],
        'drift' => [],
        'conflict' => [],
        'delete' => [],
        'delete_conflict' => [],
        'deleted' => [],
    ],
    $compiled,
    $session,
    $contract,
    $recoveryPlanner
);
$check(
    count($convergedRecoveryWork['work']) === 1
        && ($convergedRecoveryWork['work'][0]['uuid'] ?? null) === $selectedId
        && $convergedRecoveryWork['delete_work'] === []
        && $convergedRecoveryWork['rebuild_delete_work'] === [],
    'a converged authored row remains frozen recovery work while its selected effect is pending'
);
$authorIntent = [
    'ordinal' => 1,
    'authority_hash' => $session->authority_hash_value(),
    'lease_hash' => ScopedApplySession::lease_hash($session->lease()),
    'action_hash' => $hash('authored-transaction'),
    'operation_hash' => $hash('authored-operation'),
    'input_hash' => $hash('authored-input'),
    'effect_hash' => $hash('authored-effect'),
    'before_hash' => $selectedBeforeRoot,
];
$session->append_intent($authorIntent);

$authorCommits = 0;
$actualDesired = $actualBefore;
$actualDesired[$selectedId]['hash'] = $desiredHash;
$authorCommits++;
$check(
    $authorCommits === 1
        && ScopedApply::authored_state($actualDesired, $compiled, $policy, $contract, $selectedBeforeRoot) === 'desired',
    'the exact before boundary permits one authored transaction and desired readback'
);
$desiredBeforeRows = [[
    'identity_hash' => $hash($selectedId),
    'type' => 'post',
    'content_hash' => $desiredHash,
    'state' => 'live',
]];
$desiredObservation = [
    'selected_before_root' => ScopedApply::hash_rows($desiredBeforeRows),
    'selected_ledger_map_root' => $hash('selected-map-after-authoring'),
];
$authoredReadbackHash = \Duo\ScopedApplyCoordinator::authored_ledger_map_hash($desiredObservation);
$check(
    $authoredReadbackHash === hash(
        'sha256',
        "duo-scoped-authored-map-witness/v1\0" . $desiredObservation['selected_ledger_map_root']
    ),
    'author receipt after_hash is the physical selected map generation while its intent binds desired work'
);
$changedDesiredObservation = $desiredObservation;
$changedDesiredObservation['selected_ledger_map_root'] = $hash('selected-map-aba-after-authoring');
$check(
    !hash_equals(
        $authoredReadbackHash,
        \Duo\ScopedApplyCoordinator::authored_ledger_map_hash($changedDesiredObservation)
    ),
    'same desired content with a changed selected identity map changes the author receipt'
);
$expectThrow(
    static fn() => \Duo\ScopedApplyCoordinator::authored_ledger_map_hash([
        'selected_ledger_map_root' => 'malformed',
    ]),
    'malformed selected ledger-map root',
    'author readback refuses a malformed map root before receipt publication'
);
$check(
    ScopedApply::authored_state(
        $actualDesired,
        $compiled,
        $policy,
        $contract,
        ScopedApply::hash_rows($desiredBeforeRows)
    ) === 'desired',
    'a selected state that is both before and desired is a clean no-op, not an empty authored transaction'
);

$cleanCodePlan = ['code_mismatch' => [], 'code_drift' => []];
$changedCodePlan = [
    'code_mismatch' => [['code' => 'inactive_in_environment']],
    'code_drift' => [],
];
$check(
    !hash_equals(
        ScopedApply::code_witness_hash($cleanCodePlan, $compiled),
        ScopedApply::code_witness_hash($changedCodePlan, $compiled)
    ),
    'scoped code witness changes when the target code/lifecycle observation changes'
);

$applyForReadback = new \Duo\ScopedApplyWorkflow();
$GLOBALS['wpdb']->failResults = true;
try {
    $applyForReadback->core_readback_hash($policy, [], []);
    $check(false, 'a failed taxonomy-count read cannot mint an engine-effect receipt');
} catch (Throwable $failure) {
    $check(
        str_contains($failure->getMessage(), 'taxonomy-count readback failed')
            && str_contains($failure->getMessage(), 'recovery_required')
            && !str_contains($failure->getMessage(), 'DATABASE_SECRET_MUST_NOT_LEAK'),
        'a failed taxonomy-count read is recovery_required and redacts database detail'
    );
}
$GLOBALS['wpdb']->failResults = false;

// The authored phase and map receipt are one CAS in the same transaction as
// authored state. A lost response can reopen only the complete boundary.
$session->commit_authored_receipt($authorIntent + ['after_hash' => $authoredReadbackHash]);
$reopenedAfterCommit = ScopedApplySession::open($sessionStore);
$check(
    $authorCommits === 1
        && $reopenedAfterCommit !== null
        && $reopenedAfterCommit->phase() === ScopedApplySession::PHASE_AUTHORED_COMMITTED
        && count($reopenedAfterCommit->receipts()) === 1,
    'lost response reopens the atomic authored phase and map receipt without replay'
);

$makePostAuthorSession = static function () use ($authority, $authorIntent, $authoredReadbackHash): array {
    $store = new ScopedRecoveryMemoryStore();
    $session = ScopedApplySession::begin($store, $authority);
    $session->transition(ScopedApplySession::PHASE_AUTHORING);
    $session->append_intent($authorIntent);
    $session->commit_authored_receipt($authorIntent + ['after_hash' => $authoredReadbackHash]);
    $session->transition(ScopedApplySession::PHASE_EFFECTS_PENDING);
    return [$store, $session];
};

$ambiguousAuthorStore = new ScopedRecoveryMemoryStore();
$ambiguousAuthor = ScopedApplySession::begin($ambiguousAuthorStore, $authority);
$ambiguousAuthor->transition(ScopedApplySession::PHASE_AUTHORING);
$ambiguousAuthor->append_intent($authorIntent);
$ambiguousWorkflow = new \Duo\ScopedApplyWorkflow();
$ambiguousWorkflow->session = $ambiguousAuthor;
$expectThrow(
    static fn() => $ambiguousWorkflow->assert_authored_recovery_boundary(
        'desired',
        $authorIntent,
        $desiredObservation,
        $hash('irrelevant-authoring-plan'),
        $hash('irrelevant-authoring-guards')
    ),
    'without its atomic author receipt',
    'authoring plus desired state is refused instead of inferring a committed transaction'
);

$plannedChangedStore = new ScopedRecoveryMemoryStore();
$plannedChangedSession = ScopedApplySession::begin($plannedChangedStore, $authority);
$plannedChangedWorkflow = new \Duo\ScopedApplyWorkflow();
$plannedChangedWorkflow->session = $plannedChangedSession;
$expectThrow(
    static fn() => $plannedChangedWorkflow->assert_authored_recovery_boundary(
        'desired',
        $authorIntent,
        $desiredObservation,
        $hash('preconditions'),
        $hash('guards')
    ),
    'no longer matches its exact pre-author state',
    'planned desired state that differs from authority before-state refuses instead of becoming a no-op'
);

$noopAuthorityBase = $authority;
unset($noopAuthorityBase['authority_hash']);
$noopAuthorityBase['lease']['session_id'] = 'noop-author-session';
$noopAuthorityBase['target']['selected_before_hash'] = $desiredObservation['selected_before_root'];
$noopAuthorityBase['target']['selected_before_ledger_map_hash'] = $desiredObservation['selected_ledger_map_root'];
$noopAuthority = ScopedApplySession::seal_authority($noopAuthorityBase);
$noopStore = new ScopedRecoveryMemoryStore();
$noopSession = ScopedApplySession::begin($noopStore, $noopAuthority);
$noopWorkflow = new \Duo\ScopedApplyWorkflow();
$noopWorkflow->session = $noopSession;
$noopIntent = $noopWorkflow->intent(
    1,
    'duo-scoped-authored-transaction/v2',
    'noop-author-operation',
    $hash('noop-input'),
    $hash('noop-effect'),
    $desiredObservation['selected_before_root']
);
$check(
    $noopWorkflow->assert_authored_recovery_boundary(
        'desired',
        $noopIntent,
        $desiredObservation,
        $hash('preconditions'),
        $hash('guards')
    ) === ScopedApplySession::PHASE_PLANNED,
    'planned desired no-op requires exact authority state, map, plan, and guards'
);
$noopReceipt = $noopWorkflow->receipt($noopIntent, $authoredReadbackHash);
$noopSession->commit_desired_authoring($noopIntent, $noopReceipt);
$check(
    $noopSession->phase() === ScopedApplySession::PHASE_AUTHORED_COMMITTED
        && Canon::encode((array) $noopWorkflow->receipt_at(1)) === Canon::encode($noopReceipt),
    'already-desired no-op seals intent, map receipt, and both author phases in one CAS'
);

$legacyStore = new ScopedRecoveryMemoryStore();
$legacySession = ScopedApplySession::begin($legacyStore, $authority);
$legacySession->transition(ScopedApplySession::PHASE_AUTHORING);
$legacyWorkflow = new \Duo\ScopedApplyWorkflow();
$legacyWorkflow->session = $legacySession;
$legacyIntent = $legacyWorkflow->intent(
    1,
    'duo-scoped-authored-transaction/v1',
    'legacy-author-operation',
    $hash('legacy-input'),
    $hash('legacy-effect'),
    $selectedBeforeRoot
);
$legacySession->append_intent($legacyIntent);
$expectThrow(
    static fn() => $legacyWorkflow->assert_authored_recovery_boundary(
        'before',
        $authorIntent,
        $desiredObservation,
        $hash('preconditions'),
        $hash('guards')
    ),
    'obsolete v1 author evidence',
    'retained v1 state-only author evidence refuses with explicit checkpoint remediation'
);

$commitReceiptCrashStore = new ScopedRecoveryMemoryStore();
$commitReceiptCrash = ScopedApplySession::begin($commitReceiptCrashStore, $authority);
$commitReceiptCrash->transition(ScopedApplySession::PHASE_AUTHORING);
$commitReceiptCrash->append_intent($authorIntent);
$legacyMissingReceipt = Canon::decode($commitReceiptCrash->canonical());
$legacyMissingReceipt['phase'] = ScopedApplySession::PHASE_AUTHORED_COMMITTED;
$legacyMissingReceipt['phase_history'][] = ScopedApplySession::PHASE_AUTHORED_COMMITTED;
unset($legacyMissingReceipt['session_hash']);
$legacyMissingReceipt['session_hash'] = ScopedApplySession::hash_value($legacyMissingReceipt);
$commitReceiptCrashStore->values[ScopedApplySession::STORAGE_KEY] = Canon::encode($legacyMissingReceipt);
$commitReceiptCrash = ScopedApplySession::open($commitReceiptCrashStore);
$check($commitReceiptCrash !== null, 'legacy authored_committed fixture remains structurally decodable');
$commitReceiptCrash ??= ScopedApplySession::begin($commitReceiptCrashStore, $authority);
$commitReceiptCrash->recover($hash('commit-receipt-crash'));
$commitReceiptWorkflow = new \Duo\ScopedApplyWorkflow();
$commitReceiptWorkflow->session = $commitReceiptCrash;
$expectThrow(
    static fn() => $commitReceiptWorkflow->assert_authored_recovery_boundary(
        'desired',
        $authorIntent,
        $desiredObservation,
        $hash('irrelevant-commit-plan'),
        $hash('irrelevant-commit-guards')
    ),
    'author receipt does not match',
    'legacy authored_committed state without an atomic receipt refuses before effects'
);
$effectsBeforeReceipt = 0;
$check(
    $effectsBeforeReceipt === 0
        && $commitReceiptCrash->is_recovery_required()
        && $commitReceiptWorkflow->receipt_at(1) === null,
    'missing atomic author receipt remains recovery_required and cannot be reconstructed'
);

$rollbackStore = new ScopedRecoveryMemoryStore();
$rollbackSession = ScopedApplySession::begin($rollbackStore, $authority);
$rollbackSession->transition(ScopedApplySession::PHASE_AUTHORING);
$rollbackSession->append_intent($authorIntent);
$rollbackBefore = $rollbackSession->canonical();
$rollbackSession->commit_authored_receipt($authorIntent + ['after_hash' => $authoredReadbackHash]);
$rollbackStore->values[ScopedApplySession::STORAGE_KEY] = $rollbackBefore;
$rollbackSession->reload();
$check(
    $rollbackSession->phase() === ScopedApplySession::PHASE_AUTHORING
        && $rollbackSession->receipts() === []
        && Canon::encode($rollbackSession->intents()) === Canon::encode([$authorIntent]),
    'confirmed database rollback reloads the session object to authoring plus intent with no receipt'
);

// A retained post-author recovery gate is not cleared until the exact desired
// content+ledger-map receipt is re-proved. The plan/guard values deliberately
// differ here: those witnesses belonged to the original locked authoring
// transaction and deletion can legitimately change them after commit.
[, $retainedPostAuthor] = $makePostAuthorSession();
$retainedPostAuthor->recover($hash('post-author-recovery'));
$postAuthorWorkflow = new \Duo\ScopedApplyWorkflow();
$postAuthorWorkflow->session = $retainedPostAuthor;
$check(
    $postAuthorWorkflow->assert_authored_recovery_boundary(
        'desired',
        $authorIntent,
        $desiredObservation,
        $hash('changed-post-author-plan'),
        $hash('changed-post-author-guards')
    ) === ScopedApplySession::PHASE_EFFECTS_PENDING
        && $retainedPostAuthor->is_recovery_required(),
    'post-author recovery re-proves its composite receipt without clearing the durable gate or old guards'
);
$retainedPostAuthor->resume_recorded_recovery();
$check(
    $retainedPostAuthor->phase() === ScopedApplySession::PHASE_EFFECTS_PENDING,
    'post-author recovery resumes only after its exact receipt recheck'
);

$observationRegating = new \Duo\ScopedApplyWorkflow();
$observationRegating->session = $retainedPostAuthor;
$expectThrow(
    static fn() => $observationRegating->recheck_target_observation(
        static fn() => throw new RuntimeException('synthetic selected-map observation refusal')
    ),
    'synthetic selected-map observation refusal',
    'normal post-author observation failure is surfaced without dispatch'
);
$check(
    $retainedPostAuthor->is_recovery_required(),
    'normal post-author observation failure durably re-gates the exact phase'
);
$retainedObservationRecoveryBytes = $retainedPostAuthor->canonical();
$expectThrow(
    static fn() => $observationRegating->recheck_target_observation(
        static fn() => throw new RuntimeException('repeat selected-map observation refusal')
    ),
    'repeat selected-map observation refusal',
    'observation refusal preserves an already-active recovery witness'
);
$check(
    $retainedPostAuthor->canonical() === $retainedObservationRecoveryBytes,
    'repeated observation refusal leaves retained recovery bytes unchanged'
);
$retainedPostAuthor->resume_recorded_recovery();

// Model a crash immediately after the resume CAS: the normal nonterminal
// phase must repeat the same composite readback check on the next request.
$normalPostAuthor = new \Duo\ScopedApplyWorkflow();
$normalPostAuthor->session = $retainedPostAuthor;
$effectDispatches = 0;
$expectThrow(
    static function () use (
        $normalPostAuthor,
        $authorIntent,
        $changedDesiredObservation,
        $hash,
        &$effectDispatches
    ): void {
        $normalPostAuthor->assert_authored_recovery_boundary(
            'desired',
            $authorIntent,
            $changedDesiredObservation,
            $hash('irrelevant-post-author-plan'),
            $hash('irrelevant-post-author-guards')
        );
        $effectDispatches++;
    },
    'author receipt does not match',
    'normal effects_pending retry refuses same-content selected-map drift before dispatch'
);
$check(
    $effectDispatches === 0 && $retainedPostAuthor->is_recovery_required(),
    'crash-after-resume map drift restores recovery_required and leaves effect dispatch untouched'
);

[, $changedRecoveryMap] = $makePostAuthorSession();
$changedRecoveryMap->recover($hash('retained-map-drift-cause'));
$retainedRecoveryBytes = $changedRecoveryMap->canonical();
$changedRecoveryWorkflow = new \Duo\ScopedApplyWorkflow();
$changedRecoveryWorkflow->session = $changedRecoveryMap;
$expectThrow(
    static fn() => $changedRecoveryWorkflow->assert_authored_recovery_boundary(
        'desired',
        $authorIntent,
        $changedDesiredObservation,
        $hash('irrelevant-retained-plan'),
        $hash('irrelevant-retained-guards')
    ),
    'author receipt does not match',
    'retained effects_pending recovery refuses changed selected map before resume'
);
$check(
    $changedRecoveryMap->canonical() === $retainedRecoveryBytes,
    'failed post-author recheck preserves the already-active recovery witness byte-for-byte'
);

$preAuthorMenuStore = new ScopedRecoveryMemoryStore();
$preAuthorMenuSession = ScopedApplySession::begin($preAuthorMenuStore, $authority);
$preAuthorMenuSession->transition(ScopedApplySession::PHASE_AUTHORING);
$preAuthorMenuSession->recover($hash('pre-author-menu-recovery'));
$check(
    \Duo\ScopedApplyCoordinator::allows_target_old_menu_items(
        $preAuthorMenuSession,
        $contract,
        $actualBefore
    )
        && !\Duo\ScopedApplyCoordinator::allows_target_old_menu_items(
            $preAuthorMenuSession,
            $contract,
            $actualDesired
        ),
    'planned/authoring recovery uses its recorded phase only for the exact before-menu inventory premise'
);

// A selected row can be desired while an excluded row changes: protected
// evidence is an independent recovery fence and is never silently overwritten.
$protectedDrift = $actualDesired;
$protectedDrift[$protectedId]['hash'] = $hash('protected-drift');
$driftRows = [[
    'identity_hash' => $hash($protectedId),
    'type' => 'post',
    'content_hash' => $protectedDrift[$protectedId]['hash'],
]];
$driftSessionStore = new ScopedRecoveryMemoryStore();
$driftSession = ScopedApplySession::begin($driftSessionStore, $authority);
$driftSession->transition(ScopedApplySession::PHASE_AUTHORING);
$driftSession->append_intent($authorIntent);
$check(
    ScopedApply::authored_state($protectedDrift, $compiled, $policy, $contract, $selectedBeforeRoot) === 'desired'
        && !hash_equals($protectedRoot, ScopedApply::hash_rows($driftRows)),
    'selected desired state does not hide protected out-of-scope drift'
);
$driftSession->recover($hash('protected-drift-recovery'));
$check($driftSession->is_recovery_required(), 'protected drift enters recovery_required');
$expectThrow(
    static fn() => $driftSession->resume(ScopedApplySession::PHASE_PLANNED),
    'not exact',
    'protected-drift recovery cannot resume an earlier phase'
);

// A mixed selected boundary is ambiguous and must not be treated as a retry.
$mixed = $actualBefore;
$mixed[$selectedId]['hash'] = $hash('selected-mixed');
$mixedStore = new ScopedRecoveryMemoryStore();
$mixedSession = ScopedApplySession::begin($mixedStore, $authority);
$mixedSession->transition(ScopedApplySession::PHASE_AUTHORING);
$mixedSession->append_intent($authorIntent);
$check(
    ScopedApply::authored_state($mixed, $compiled, $policy, $contract, $selectedBeforeRoot) === 'mixed',
    'a selected target changed to an unknown value is classified as mixed'
);
$mixedSession->recover($hash('mixed-boundary-recovery'));
$check($mixedSession->is_recovery_required() && $authorCommits === 1,
    'mixed authored drift enters recovery_required without a second authored transaction');

// The durable authority carries selected and protected ledger witnesses as
// separate roots. The selection contains only the selected identity and the
// session does not contain an applied_revision claim: scoped metadata cannot
// advance the whole target revision or rewrite excluded mappings.
$check(
    count($authority['selection']['work_items']) === 1
        && $authority['selection']['work_items'][0]['identity_hash'] === $hash($selectedId)
        && !array_key_exists('applied_revision', $session->to_array())
        && $authority['target']['protected_out_of_scope_hash'] === $protectedRoot,
    'scoped authority retains selected-only work, a protected ledger witness, and no applied_revision'
);
$projected = ScopedApply::project_plan([
    'create' => [['uuid' => $selectedId], ['uuid' => $protectedId]],
    'update' => [['uuid' => $selectedId]],
    'unchanged' => [], 'drift' => [], 'conflict' => [], 'adopt' => [],
    'collision' => [], 'delete' => [], 'delete_conflict' => [], 'deleted' => [],
    'missing_user' => [], 'skipped_user_meta' => [], 'regen_pending' => [], 'regen_context' => [],
], $contract);
$check(
    count($projected['create']) === 1 && $projected['create'][0]['uuid'] === $selectedId
        && count($projected['update']) === 1 && $projected['update'][0]['uuid'] === $selectedId,
    'scoped plan projection excludes the protected identity from authored work'
);

// File-level sidebar/menu selection owns nested widget/menu-item ledger UUIDs.
// Seal the union of source-new and target-old children before mutation so a
// removal cannot reclassify its old map row as protected during recovery.
$selectedSidebar = 'sidebar/selected';
$selectedMenu = $uuid(20);
$sourceWidget = $uuid(21);
$targetWidget = $uuid(22);
$sourceMenuItem = $uuid(23);
$targetMenuItem = $uuid(24);
$protectedWidget = $uuid(25);
$protectedMenuItem = $uuid(26);
$draftTargetMenuItem = $uuid(28);
$trashTargetMenuItem = $uuid(29);
$nestedContract = $contract;
$nestedContract['selectors'] = ['menu:selected', 'sidebar:selected'];
$nestedRootEntities = [$selectedMenu, $selectedSidebar];
sort($nestedRootEntities, SORT_STRING);
$nestedContract['resolution'] = [
    'live_root_entities' => $nestedRootEntities,
    'tombstone_uuids' => [],
];
$nestedContract['live'] = [
    'roots' => [[
        'entity' => $selectedMenu,
        'entity_hash' => $hash('nested-menu-entity'),
        'path' => 'menus/selected.json',
        'type' => 'menu',
        'source_hash' => $hash('nested-menu-source'),
        'provenance' => ['kind' => 'root', 'selector' => 'menu:selected'],
    ], [
        'entity' => $selectedSidebar,
        'entity_hash' => $hash('nested-sidebar-entity'),
        'path' => 'sidebars/selected.json',
        'type' => 'sidebar',
        'source_hash' => $hash('nested-sidebar-source'),
        'provenance' => ['kind' => 'root', 'selector' => 'sidebar:selected'],
    ]],
    'closure' => [],
    'excluded' => [],
    'inbound' => [],
];
$nestedContract['tombstones'] = [];
unset($nestedContract['scope_hash']);
$nestedContract['scope_hash'] = $hash(Canon::encode($nestedContract));
$nestedContract = ScopeContract::from_array($nestedContract);
$nestedCompiled = CompiledRepository::create([
    'tree' => [
        $selectedSidebar => [
            'type' => 'sidebar', 'hash' => $hash('selected-sidebar'),
            'path' => 'sidebars/selected.json', 'content' => '',
            'data' => ['widgets' => [[
                'uuid' => $sourceWidget, 'type' => 'text', 'settings' => (object) [],
            ]]],
        ],
        'sidebar/protected' => [
            'type' => 'sidebar', 'hash' => $hash('protected-sidebar'),
            'path' => 'sidebars/protected.json', 'content' => '',
            'data' => ['widgets' => [[
                'uuid' => $protectedWidget, 'type' => 'block', 'settings' => (object) [],
            ]]],
        ],
        $selectedMenu => [
            'type' => 'menu', 'hash' => $hash('selected-menu'),
            'path' => 'menus/selected.json', 'content' => '',
            'data' => ['uuid' => $selectedMenu, 'items' => [['uuid' => $sourceMenuItem]]],
        ],
        $uuid(27) => [
            'type' => 'menu', 'hash' => $hash('protected-menu'),
            'path' => 'menus/protected.json', 'content' => '',
            'data' => ['uuid' => $uuid(27), 'items' => [['uuid' => $protectedMenuItem]]],
        ],
    ],
    'deletions' => [],
    'revision_hash' => $hash('nested-revision'),
    'manifest_hash' => $hash('nested-manifest'),
    'site_hash' => $hash('nested-site'),
    'effects_inventory' => [],
]);
$nestedActual = [
    $selectedSidebar => [
        'type' => 'sidebar', 'hash' => $hash('target-sidebar'), 'path' => 'sidebars/selected.json',
        'content' => Canon::encode(['widgets' => [[
            'uuid' => $targetWidget, 'type' => 'text', 'settings' => (object) [],
        ]]]),
    ],
    'sidebar/protected' => [
        'type' => 'sidebar', 'hash' => $hash('target-protected-sidebar'),
        'path' => 'sidebars/protected.json',
        'content' => Canon::encode(['widgets' => [[
            'uuid' => $protectedWidget, 'type' => 'block', 'settings' => (object) [],
        ]]]),
    ],
    $selectedMenu => [
        'type' => 'menu', 'hash' => $hash('target-menu'), 'path' => 'menus/selected.json',
        'content' => Canon::encode([
            'uuid' => $selectedMenu, 'items' => [['uuid' => $targetMenuItem]],
        ]),
    ],
];
// Strict map/physical fixture for the source-new and target-old ownership
// union. Capture intentionally omits the draft/trash items below, while
// finalize_menu() will enumerate and delete them with every other status.
$GLOBALS['wpdb']->mapRows = [
    ['uuid' => $selectedMenu, 'entity_type' => 'menu', 'id_kind' => 'term', 'local_id' => 20],
    ['uuid' => $selectedMenu, 'entity_type' => 'menu', 'id_kind' => 'term_taxonomy', 'local_id' => 120],
    ['uuid' => $targetWidget, 'entity_type' => 'widget', 'id_kind' => 'widget_text', 'local_id' => 4],
    ['uuid' => $targetMenuItem, 'entity_type' => 'menu_item', 'id_kind' => 'post', 'local_id' => 24],
    ['uuid' => $draftTargetMenuItem, 'entity_type' => 'menu_item', 'id_kind' => 'post', 'local_id' => 28],
    ['uuid' => $trashTargetMenuItem, 'entity_type' => 'menu_item', 'id_kind' => 'post', 'local_id' => 29],
    ['uuid' => $protectedWidget, 'entity_type' => 'widget', 'id_kind' => 'widget_block', 'local_id' => 9],
];
$GLOBALS['wpdb']->termRows = [20 => $selectedMenu];
$GLOBALS['wpdb']->taxonomyRows = [120 => ['term_id' => 20, 'taxonomy' => 'nav_menu']];
$GLOBALS['wpdb']->postRows = [
    24 => [
        'post_type' => 'nav_menu_item', 'post_status' => 'publish',
        'uuid' => $targetMenuItem, 'term_taxonomy_ids' => [120],
    ],
    28 => [
        'post_type' => 'nav_menu_item', 'post_status' => 'draft',
        'uuid' => $draftTargetMenuItem, 'term_taxonomy_ids' => [120],
    ],
    29 => [
        'post_type' => 'nav_menu_item', 'post_status' => 'trash',
        'uuid' => $trashTargetMenuItem, 'term_taxonomy_ids' => [120],
    ],
];
$GLOBALS['wpdb']->optionRows['widget_text'] = serialize([
    4 => ['title' => 'selected'],
    '_multiwidget' => 1,
]);
$GLOBALS['wpdb']->optionRows['sidebars_widgets'] = serialize([
    'selected' => ['text-4'],
    'protected' => [],
    'wp_inactive_widgets' => [],
    'array_version' => 3,
]);
$nestedPolicy = clone $policy;
$nestedPolicy->manifests = [[
    'name' => 'scoped-recovery-fixture',
    'widgets' => ['text' => ['settings' => []]],
]];

// Full plan/apply must inspect retained canonical maps before its historical
// dead-map maintenance can erase the evidence. No map remains a legitimate
// fresh target, while a partial, missing, reused, or target-only tuple has an
// explicit deterministic verdict.
$guardMapRows = $GLOBALS['wpdb']->mapRows;
$guardTermRows = $GLOBALS['wpdb']->termRows;
$guardTaxonomyRows = $GLOBALS['wpdb']->taxonomyRows;
$guardPostRows = $GLOBALS['wpdb']->postRows;
$guardOptionRows = $GLOBALS['wpdb']->optionRows;
$GLOBALS['wpdb']->mapRows = [[
    'uuid' => $selectedMenu, 'entity_type' => 'menu', 'id_kind' => 'term', 'local_id' => 20,
], [
    'uuid' => $selectedMenu, 'entity_type' => 'menu', 'id_kind' => 'term_taxonomy', 'local_id' => 120,
]];
CanonicalLedgerMapGuard::assert_pre_prune($nestedPolicy, $nestedCompiled);
$check(true, 'full pre-prune guard accepts a complete exact canonical menu mapping');

array_pop($GLOBALS['wpdb']->mapRows);
try {
    CanonicalLedgerMapGuard::assert_pre_prune($nestedPolicy, $nestedCompiled);
    $check(false, 'full pre-prune guard must reject a partial term/taxonomy tuple');
} catch (\Duo\CommandRefusalException $failure) {
    $check(
        $failure->reasonCode === 'canonical_identity_recovery_required'
            && str_contains($failure->getMessage(), 'refusing to create or rebind'),
        'partial canonical term history has the stable full-plan recovery refusal'
    );
}

$GLOBALS['wpdb']->mapRows = [[
    'uuid' => $selectedMenu, 'entity_type' => 'menu', 'id_kind' => 'term', 'local_id' => 20,
], [
    'uuid' => $selectedMenu, 'entity_type' => 'menu', 'id_kind' => 'term_taxonomy', 'local_id' => 120,
]];
$GLOBALS['wpdb']->termRows = [];
$GLOBALS['wpdb']->taxonomyRows = [];
try {
    CanonicalLedgerMapGuard::assert_pre_prune($nestedPolicy, $nestedCompiled);
    $check(false, 'full pre-prune guard must reject an absent canonical backing row');
} catch (\Duo\CommandRefusalException $failure) {
    $check(
        $failure->reasonCode === 'canonical_identity_recovery_required',
        'destructively absent canonical rows refuse before dead-map pruning'
    );
}

$GLOBALS['wpdb']->mapRows = [];
CanonicalLedgerMapGuard::assert_pre_prune($nestedPolicy, $nestedCompiled);
$check(true, 'full pre-prune guard preserves the genuine fresh-target path');
$GLOBALS['wpdb']->mapRows = [[
    'uuid' => $targetWidget, 'entity_type' => 'widget', 'id_kind' => 'widget_text', 'local_id' => 4,
]];
CanonicalLedgerMapGuard::assert_pre_prune($nestedPolicy, $nestedCompiled);
$check(true, 'full pre-prune guard ignores a target-only mapping outside canonical source identity');

$GLOBALS['wpdb']->mapRows = [[
    'uuid' => $sourceWidget, 'entity_type' => 'widget', 'id_kind' => 'widget_text', 'local_id' => 4,
]];
$GLOBALS['wpdb']->optionRows = [];
try {
    CanonicalLedgerMapGuard::assert_pre_prune($nestedPolicy, $nestedCompiled);
    $check(false, 'full pre-prune guard must reject a missing canonical widget instance');
} catch (\Duo\CommandRefusalException $failure) {
    $check(
        $failure->reasonCode === 'canonical_identity_recovery_required',
        'missing canonical widget backing refuses through the same recovery boundary'
    );
}

$GLOBALS['wpdb']->mapRows = [[
    'uuid' => $sourceMenuItem, 'entity_type' => 'menu_item', 'id_kind' => 'post', 'local_id' => 24,
], [
    'uuid' => $selectedMenu, 'entity_type' => 'menu', 'id_kind' => 'term', 'local_id' => 20,
], [
    'uuid' => $selectedMenu, 'entity_type' => 'menu', 'id_kind' => 'term_taxonomy', 'local_id' => 120,
]];
$GLOBALS['wpdb']->termRows = [20 => $selectedMenu];
$GLOBALS['wpdb']->taxonomyRows = [120 => ['term_id' => 20, 'taxonomy' => 'nav_menu']];
$GLOBALS['wpdb']->postRows = [
    24 => [
        'post_type' => 'nav_menu_item', 'post_status' => 'publish',
        'uuid' => $targetMenuItem, 'term_taxonomy_ids' => [120],
    ],
];
try {
    CanonicalLedgerMapGuard::assert_pre_prune($nestedPolicy, $nestedCompiled);
    $check(false, 'full pre-prune guard must reject local-id reuse under a different UUID');
} catch (\Duo\CommandRefusalException $failure) {
    $check(
        $failure->reasonCode === 'canonical_identity_recovery_required',
        'local-id reuse cannot turn retained canonical identity into write-through authority'
    );
}
$GLOBALS['wpdb']->mapRows = $guardMapRows;
$GLOBALS['wpdb']->termRows = $guardTermRows;
$GLOBALS['wpdb']->taxonomyRows = $guardTaxonomyRows;
$GLOBALS['wpdb']->postRows = $guardPostRows;
$GLOBALS['wpdb']->optionRows = $guardOptionRows;
try {
    ScopedApply::ledger_map_identity_hashes($nestedContract, $nestedCompiled, $nestedActual);
    $check(false, 'post-author identity derivation must not admit retained hidden menu-item maps');
} catch (\Duo\CommandRefusalException $failure) {
    $check(
        $failure->reasonCode === 'scoped_identity_recovery_required',
        'default identity derivation fails closed when an all-status target-old menu item reappears'
    );
}
$nestedIdentityHashes = ScopedApply::ledger_map_identity_hashes(
    $nestedContract,
    $nestedCompiled,
    $nestedActual,
    true
);
$expectedNestedHashes = array_map($hash, [
    $selectedMenu, $sourceWidget, $targetWidget, $sourceMenuItem, $targetMenuItem,
    $draftTargetMenuItem, $trashTargetMenuItem,
]);
sort($expectedNestedHashes, SORT_STRING);
$check(
    $nestedIdentityHashes === $expectedNestedHashes
        && !in_array($hash($protectedWidget), $nestedIdentityHashes, true)
        && !in_array($hash($protectedMenuItem), $nestedIdentityHashes, true),
    'sealed map membership includes selected source-new, canonical target, and strict all-status target-old menu owners only'
);
$mapRows = $GLOBALS['wpdb']->mapRows;
usort($mapRows, static fn(array $a, array $b): int => [
    $a['uuid'], $a['id_kind'], $a['local_id'], $a['entity_type'],
] <=> [
    $b['uuid'], $b['id_kind'], $b['local_id'], $b['entity_type'],
]);
$expectedSelectedMapRows = array_values(array_filter(
    $mapRows,
    static fn(array $row): bool => in_array(hash('sha256', $row['uuid']), $expectedNestedHashes, true)
));
$expectedProtectedMapRows = array_values(array_filter(
    $mapRows,
    static fn(array $row): bool => !in_array(hash('sha256', $row['uuid']), $expectedNestedHashes, true)
));
$nestedMapRoots = ScopedApply::ledger_map_roots($nestedIdentityHashes);
$check(
    $nestedMapRoots['selected_ledger_map_root'] === $hash(Canon::encode($expectedSelectedMapRows))
        && $nestedMapRoots['protected_ledger_map_root'] === $hash(Canon::encode($expectedProtectedMapRows))
        && count($expectedSelectedMapRows) === 6
        && count($expectedProtectedMapRows) === 1,
    'ledger partition moves all selected menu draft/trash map rows together and protects unselected owner rows'
);
try {
    ScopedApply::assert_selected_ledger_map_observation(
        $nestedPolicy,
        $nestedContract,
        $nestedActual,
        $nestedIdentityHashes,
        $nestedCompiled
    );
    $check(false, 'post-author strict observation must not accept retained hidden menu-item maps');
} catch (\Duo\CommandRefusalException $failure) {
    $check(
        $failure->reasonCode === 'scoped_identity_recovery_required',
        'post-author strict observation treats a reappearing draft/trash map as recovery evidence'
    );
}
ScopedApply::assert_selected_ledger_map_observation(
    $nestedPolicy,
    $nestedContract,
    $nestedActual,
    $nestedIdentityHashes,
    $nestedCompiled,
    true
);
$check(true, 'initial/pre-author strict observation admits exact all-status target rows and ignores unselected stale rows');

// A lost response can leave the durable session at `authoring` after the
// selected file rows are already desired. Root matching—not the phase name—
// must then disable target-old admission, while a genuine exact-before retry
// remains eligible.
$phaseContract = $nestedContract;
$phaseContract['selectors'] = ['menu:selected'];
$phaseContract['resolution']['live_root_entities'] = [$selectedMenu];
$phaseContract['live']['roots'] = array_values(array_filter(
    $nestedContract['live']['roots'],
    static fn(array $row): bool => ($row['entity'] ?? '') === $selectedMenu
));
unset($phaseContract['scope_hash']);
$phaseContract['scope_hash'] = $hash(Canon::encode($phaseContract));
$phaseContract = ScopeContract::from_array($phaseContract);
$phaseCompiled = CompiledRepository::create([
    'tree' => [$selectedMenu => $nestedCompiled->tree()[$selectedMenu]],
    'deletions' => [],
    'revision_hash' => $hash('phase-menu-revision'),
    'manifest_hash' => $hash('phase-menu-manifest'),
    'site_hash' => $hash('phase-menu-site'),
    'effects_inventory' => [],
]);
$phaseBeforeActual = [$selectedMenu => $nestedActual[$selectedMenu]];
$authoringDesiredActual = [$selectedMenu => $nestedActual[$selectedMenu]];
$authoringDesiredActual[$selectedMenu]['content'] = Canon::encode(
    (array) $phaseCompiled->tree()[$selectedMenu]['data']
);
$authoringDesiredActual[$selectedMenu]['hash'] = $hash('authoring-desired-menu');
$phaseIdentityHashes = ScopedApply::ledger_map_identity_hashes(
    $phaseContract,
    $phaseCompiled,
    $phaseBeforeActual,
    true
);
$nestedBeforeRoot = ScopedApply::selected_observation_root($phaseBeforeActual, $phaseContract);
$phaseAuthority = ScopedApplySession::make_authority(
    $phaseContract['scope_hash'],
    [
        'artifact_hash' => $phaseCompiled->artifact_hash(),
        'state_revision_hash' => $phaseCompiled->revision_hash(),
        'manifest_hash' => $phaseCompiled->manifest_hash(),
    ],
    [
        'owner' => 'nested-phase-owner',
        'artifact_hash' => $phaseCompiled->artifact_hash(),
        'session_id' => 'nested-phase-session',
    ],
    [
        'selected_before_hash' => $nestedBeforeRoot,
        'selected_before_ledger_map_hash' => $hash('nested-before-map'),
        'protected_ledger_map_hash' => $hash('nested-protected-map'),
        'protected_out_of_scope_hash' => $hash('nested-protected-root'),
        'ledger_roots_hash' => $hash('nested-ledger-roots'),
    ],
    [
        'precondition_hash' => $hash('nested-phase-preconditions'),
        'guard_witnesses_hash' => $hash('nested-phase-guards'),
    ],
    $selection,
    $hash('nested-phase-code-witness')
);
$phaseStore = new ScopedRecoveryMemoryStore();
$phaseSession = ScopedApplySession::begin($phaseStore, $phaseAuthority);
$phaseSession->transition(ScopedApplySession::PHASE_AUTHORING);
$phaseApply = new \Duo\ScopedApplyWorkflow();
$phaseApply->scopeContract = $phaseContract;
$phaseApply->session = $phaseSession;
$phaseAllowsBefore = $phaseApply->allows_target_old_menu_items($phaseBeforeActual);
$phaseDesiredState = ScopedApply::authored_state(
    $authoringDesiredActual,
    $phaseCompiled,
    $nestedPolicy,
    $phaseContract,
    $nestedBeforeRoot
);
$phaseAllowsDesired = $phaseApply->allows_target_old_menu_items($authoringDesiredActual);
$check(
    $phaseAllowsBefore === true,
    'authoring recovery admits target-old identities only while the exact selected before root remains intact'
);
$check(
    $phaseDesiredState === 'desired',
    'authoring desired selected content is classified as desired before phase admission is evaluated'
);
$check(
    $phaseAllowsDesired === false,
    'authoring recovery blocks a desired readback from target-old admission'
);
try {
    ScopedApply::assert_selected_ledger_map_observation(
        $nestedPolicy,
        $phaseContract,
        $authoringDesiredActual,
        $phaseIdentityHashes,
        $phaseCompiled,
        false
    );
    $check(false, 'authoring desired readback with a reappearing hidden map must refuse');
} catch (\Duo\CommandRefusalException $failure) {
    $check(
        $failure->reasonCode === 'scoped_identity_recovery_required',
        'desired authoring recovery treats a hidden all-status map as recovery evidence before false-green transition'
    );
}

$GLOBALS['wpdb']->mapRows[3]['local_id'] = 25;
try {
    ScopedApply::assert_selected_ledger_map_observation(
        $nestedPolicy,
        $nestedContract,
        $nestedActual,
        $nestedIdentityHashes,
        $nestedCompiled,
        true
    );
    $check(false, 'a same-kind selected map rebound to a different local id must refuse');
} catch (\Duo\CommandRefusalException $failure) {
    $check(
        $failure->reasonCode === 'scoped_identity_recovery_required',
        'selected map validation rebinds the exact local id to its physical sidecar and menu owner'
    );
}
$GLOBALS['wpdb']->mapRows[3]['local_id'] = 24;

// A source-owned item can be draft/trash in the target and still be safe to
// reuse: finalize_menu() will publish it. An exact map is retained in the
// selected partition; an entirely missing map is created through Ledger::set.
$GLOBALS['wpdb']->mapRows[] = [
    'uuid' => $sourceMenuItem,
    'entity_type' => 'menu_item',
    'id_kind' => 'post',
    'local_id' => 230,
];
$GLOBALS['wpdb']->postRows[230] = [
    'post_type' => 'nav_menu_item', 'post_status' => 'draft',
    'uuid' => $sourceMenuItem, 'term_taxonomy_ids' => [120],
];
$sourceHiddenHashes = ScopedApply::ledger_map_identity_hashes(
    $nestedContract,
    $nestedCompiled,
    $nestedActual,
    true
);
$check(
    $sourceHiddenHashes === $nestedIdentityHashes,
    'an exact hidden source-owned menu-item map remains in its already-selected source partition'
);
ScopedApply::assert_selected_ledger_map_observation(
    $nestedPolicy,
    $nestedContract,
    $nestedActual,
    $sourceHiddenHashes,
    $nestedCompiled,
    true
);
$check(true, 'an exact draft source-owned menu item is reusable before authoring');
array_pop($GLOBALS['wpdb']->mapRows);

$sourceHiddenUnmappedHashes = ScopedApply::ledger_map_identity_hashes(
    $nestedContract,
    $nestedCompiled,
    $nestedActual,
    true
);
ScopedApply::assert_selected_ledger_map_observation(
    $nestedPolicy,
    $nestedContract,
    $nestedActual,
    $sourceHiddenUnmappedHashes,
    $nestedCompiled,
    true
);
$check(
    $sourceHiddenUnmappedHashes === $nestedIdentityHashes,
    'a hidden source-owned menu item with no map remains eligible for Ledger::set creation'
);

// Reuse publishes this source-owned item but does not delete it, so a
// non-nav relationship is allowed as long as its sole nav-menu owner remains
// the selected menu.
$GLOBALS['wpdb']->taxonomyRows[122] = ['term_id' => 22, 'taxonomy' => 'category'];
$GLOBALS['wpdb']->postRows[230]['term_taxonomy_ids'] = [120, 122];
$check(
    ScopedApply::ledger_map_identity_hashes($nestedContract, $nestedCompiled, $nestedActual, true)
        === $nestedIdentityHashes,
    'source-desired hidden reuse preserves a non-nav relationship while retaining its exact selected nav-menu owner'
);
$GLOBALS['wpdb']->postRows[230]['term_taxonomy_ids'] = [120];
unset($GLOBALS['wpdb']->taxonomyRows[122]);

// The zero-map reuse path must still reject a local post already bound to a
// different map UUID: otherwise Ledger::set(sourceUuid, localPost) fails only
// after authoring begins.
$conflictingLocalMenuItem = $uuid(37);
$GLOBALS['wpdb']->mapRows[] = [
    'uuid' => $conflictingLocalMenuItem,
    'entity_type' => 'menu_item',
    'id_kind' => 'post',
    'local_id' => 230,
];
try {
    ScopedApply::ledger_map_identity_hashes($nestedContract, $nestedCompiled, $nestedActual, true);
    $check(false, 'a zero-map source-owned hidden item bound to another local post map must refuse');
} catch (\Duo\CommandRefusalException $failure) {
    $check(
        $failure->reasonCode === 'scoped_identity_recovery_required',
        'a zero-map source-owned hidden item refuses before Ledger::set can collide with a local post map'
    );
}
array_pop($GLOBALS['wpdb']->mapRows);

// Source-desired hidden reuse is not safe when the physical item is also
// attached to an unselected menu: finalization would publish it through both
// menus. The exclusive-owner check applies before the zero-map reuse branch.
$GLOBALS['wpdb']->taxonomyRows[121] = ['term_id' => 21, 'taxonomy' => 'nav_menu'];
$GLOBALS['wpdb']->postRows[230]['term_taxonomy_ids'] = [120, 121];
try {
    ScopedApply::ledger_map_identity_hashes($nestedContract, $nestedCompiled, $nestedActual, true);
    $check(false, 'a source-owned hidden item shared with a protected menu must refuse');
} catch (\Duo\CommandRefusalException $failure) {
    $check(
        $failure->reasonCode === 'scoped_identity_recovery_required',
        'source-desired hidden reuse requires exactly one selected nav-menu relationship'
    );
}
$GLOBALS['wpdb']->postRows[230]['term_taxonomy_ids'] = [120];
unset($GLOBALS['wpdb']->taxonomyRows[121]);

// The same source UUID is stale when its map no longer names a physical item:
// it must not be trusted as an old id merely because the source owns it.
unset($GLOBALS['wpdb']->postRows[230]);
$GLOBALS['wpdb']->mapRows[] = [
    'uuid' => $sourceMenuItem,
    'entity_type' => 'menu_item',
    'id_kind' => 'post',
    'local_id' => 230,
];
try {
    ScopedApply::assert_selected_ledger_map_observation(
        $nestedPolicy,
        $nestedContract,
        $nestedActual,
        $nestedIdentityHashes,
        $nestedCompiled,
        true
    );
    $check(false, 'a source-owned menu-item map without its physical sidecar row must refuse');
} catch (\Duo\CommandRefusalException $failure) {
    $check(
        $failure->reasonCode === 'scoped_identity_recovery_required'
            && !str_contains($failure->publicMessage, $sourceMenuItem)
            && !str_contains($failure->remediation, $sourceMenuItem),
        'a stale source menu-item map still refuses through a value-free recovery contract'
    );
}
array_pop($GLOBALS['wpdb']->mapRows);

// A compiled protected owner cannot be reclassified merely because the
// target's selected menu now physically holds it, even when Capture would
// canonically expose that published item under the selected owner.
$protectedOwnerMismatchActual = $nestedActual;
$protectedOwnerMismatchActual[$selectedMenu]['content'] = Canon::encode([
    'uuid' => $selectedMenu,
    'items' => [['uuid' => $targetMenuItem], ['uuid' => $protectedMenuItem]],
]);
$GLOBALS['wpdb']->mapRows[] = [
    'uuid' => $protectedMenuItem,
    'entity_type' => 'menu_item',
    'id_kind' => 'post',
    'local_id' => 260,
];
$GLOBALS['wpdb']->postRows[260] = [
    'post_type' => 'nav_menu_item', 'post_status' => 'publish',
    'uuid' => $protectedMenuItem, 'term_taxonomy_ids' => [120],
];
try {
    ScopedApply::ledger_map_identity_hashes(
        $nestedContract,
        $nestedCompiled,
        $protectedOwnerMismatchActual,
        true
    );
    $check(false, 'a compiled protected menu-item owner must not enter selected authority');
} catch (\Duo\CommandRefusalException $failure) {
    $check(
        $failure->reasonCode === 'scoped_identity_recovery_required',
        'a protected compiled owner mismatch refuses even for a published selected-target candidate'
    );
}
array_pop($GLOBALS['wpdb']->mapRows);
unset($GLOBALS['wpdb']->postRows[260]);

// The generic frozen-owner comparison applies before map union for widgets
// too: target ownership under the selected sidebar cannot steal a UUID the
// complete source tree assigns to a protected sidebar.
$protectedWidgetMismatchActual = $nestedActual;
$protectedWidgetMismatchActual[$selectedSidebar]['content'] = Canon::encode([
    'widgets' => [[
        'uuid' => $targetWidget, 'type' => 'text', 'settings' => (object) [],
    ], [
        'uuid' => $protectedWidget, 'type' => 'block', 'settings' => (object) [],
    ]],
]);
try {
    ScopedApply::ledger_map_identity_hashes(
        $nestedContract,
        $nestedCompiled,
        $protectedWidgetMismatchActual,
        true
    );
    $check(false, 'a compiled protected widget owner must not enter selected authority');
} catch (\Duo\CommandRefusalException $failure) {
    $check(
        $failure->reasonCode === 'scoped_identity_recovery_required',
        'a protected compiled widget owner mismatch refuses before nested-map union'
    );
}

// A physical selected menu row with a post map but an incorrect entity type
// must refuse rather than remain a hidden protected Ledger::forget target.
$malformedTypeMenuItem = $uuid(30);
$GLOBALS['wpdb']->mapRows[] = [
    'uuid' => $malformedTypeMenuItem,
    'entity_type' => 'post',
    'id_kind' => 'post',
    'local_id' => 30,
];
$GLOBALS['wpdb']->postRows[30] = [
    'post_type' => 'nav_menu_item', 'post_status' => 'draft',
    'uuid' => $malformedTypeMenuItem, 'term_taxonomy_ids' => [120],
];
try {
    ScopedApply::ledger_map_identity_hashes($nestedContract, $nestedCompiled, $nestedActual, true);
    $check(false, 'a malformed selected physical menu-item entity type must refuse');
} catch (\Duo\CommandRefusalException $failure) {
    $check(
        $failure->reasonCode === 'scoped_identity_recovery_required',
        'physical id_kind=post discovery rejects a malformed menu-item entity type'
    );
}
array_pop($GLOBALS['wpdb']->mapRows);
unset($GLOBALS['wpdb']->postRows[30]);

// Likewise the embedded physical sidecar must agree with the selected map
// tuple before target-only ownership can be sealed.
$malformedSidecarMenuItem = $uuid(31);
$GLOBALS['wpdb']->mapRows[] = [
    'uuid' => $malformedSidecarMenuItem,
    'entity_type' => 'menu_item',
    'id_kind' => 'post',
    'local_id' => 31,
];
$GLOBALS['wpdb']->postRows[31] = [
    'post_type' => 'nav_menu_item', 'post_status' => 'trash',
    'uuid' => $uuid(32), 'term_taxonomy_ids' => [120],
];
try {
    ScopedApply::ledger_map_identity_hashes($nestedContract, $nestedCompiled, $nestedActual, true);
    $check(false, 'a mismatched selected physical menu-item sidecar must refuse');
} catch (\Duo\CommandRefusalException $failure) {
    $check(
        $failure->reasonCode === 'scoped_identity_recovery_required',
        'physical id_kind=post discovery rejects a mismatched menu-item sidecar'
    );
}
array_pop($GLOBALS['wpdb']->mapRows);
unset($GLOBALS['wpdb']->postRows[31]);

// finalize_menu() keys envByUuid from every nonempty physical sidecar, not
// from duo_map. A sidecar with no exact map could make Ledger::forget() erase
// another UUID's state, so initial observation must refuse it before a
// session/mutation boundary exists.
$unmappedSidecarMenuItem = $uuid(33);
$GLOBALS['wpdb']->postRows[33] = [
    'post_type' => 'nav_menu_item', 'post_status' => 'draft',
    'uuid' => $unmappedSidecarMenuItem, 'term_taxonomy_ids' => [120],
];
try {
    ScopedApply::ledger_map_identity_hashes($nestedContract, $nestedCompiled, $nestedActual, true);
    $check(false, 'a selected physical menu-item sidecar without a map must refuse');
} catch (\Duo\CommandRefusalException $failure) {
    $check(
        $failure->reasonCode === 'scoped_identity_recovery_required',
        'all-status sidecar discovery refuses an unmapped Ledger::forget identity before authority'
    );
}
unset($GLOBALS['wpdb']->postRows[33]);

// A map under the same UUID but bound to another local post is equally unsafe:
// finalization will forget by sidecar UUID, not the stale local map id.
$reboundSidecarMenuItem = $uuid(34);
$GLOBALS['wpdb']->mapRows[] = [
    'uuid' => $reboundSidecarMenuItem,
    'entity_type' => 'menu_item',
    'id_kind' => 'post',
    'local_id' => 35,
];
$GLOBALS['wpdb']->postRows[34] = [
    'post_type' => 'nav_menu_item', 'post_status' => 'trash',
    'uuid' => $reboundSidecarMenuItem, 'term_taxonomy_ids' => [120],
];
try {
    ScopedApply::ledger_map_identity_hashes($nestedContract, $nestedCompiled, $nestedActual, true);
    $check(false, 'a selected physical menu-item sidecar rebound to another map id must refuse');
} catch (\Duo\CommandRefusalException $failure) {
    $check(
        $failure->reasonCode === 'scoped_identity_recovery_required',
        'all-status sidecar discovery refuses a local-id rebound before Ledger::forget can cross partitions'
    );
}
array_pop($GLOBALS['wpdb']->mapRows);
unset($GLOBALS['wpdb']->postRows[34]);

// A genuine target-old row may be selected for deletion only when the
// selected menu is its sole nav-menu owner. Sharing it with a protected menu
// would make finalize_menu() delete protected membership/content too.
$dualOwnedTargetMenuItem = $uuid(36);
$GLOBALS['wpdb']->mapRows[] = [
    'uuid' => $dualOwnedTargetMenuItem,
    'entity_type' => 'menu_item',
    'id_kind' => 'post',
    'local_id' => 36,
];
$GLOBALS['wpdb']->postRows[36] = [
    'post_type' => 'nav_menu_item', 'post_status' => 'trash',
    'uuid' => $dualOwnedTargetMenuItem, 'term_taxonomy_ids' => [120, 121],
];
$GLOBALS['wpdb']->taxonomyRows[121] = ['term_id' => 21, 'taxonomy' => 'nav_menu'];
try {
    ScopedApply::ledger_map_identity_hashes($nestedContract, $nestedCompiled, $nestedActual, true);
    $check(false, 'a target-old menu item shared with a protected menu must refuse');
} catch (\Duo\CommandRefusalException $failure) {
    $check(
        $failure->reasonCode === 'scoped_identity_recovery_required',
        'target-old menu ownership requires exactly one selected nav-menu relationship'
    );
}
array_pop($GLOBALS['wpdb']->mapRows);
unset($GLOBALS['wpdb']->postRows[36], $GLOBALS['wpdb']->taxonomyRows[121]);

// A canonical target-only item is removed by finalize_menu(), which deletes
// its post row and every eligible taxonomy relationship. It therefore cannot
// retain an unrelated taxonomy relationship merely because its selected
// nav-menu attachment is valid.
$GLOBALS['wpdb']->taxonomyRows[122] = ['term_id' => 22, 'taxonomy' => 'category'];
$GLOBALS['wpdb']->postRows[24]['term_taxonomy_ids'] = [120, 122];
try {
    ScopedApply::ledger_map_identity_hashes($nestedContract, $nestedCompiled, $nestedActual, true);
    $check(false, 'a source-absent canonical menu item with another taxonomy relationship must refuse');
} catch (\Duo\CommandRefusalException $failure) {
    $check(
        $failure->reasonCode === 'scoped_identity_recovery_required',
        'source-absent canonical deletion requires full relationship exclusivity'
    );
}
$GLOBALS['wpdb']->postRows[24]['term_taxonomy_ids'] = [120];
unset($GLOBALS['wpdb']->taxonomyRows[122]);

// A menu tombstone takes a different physical deletion path: delete_entity()
// enumerates every attached nav_menu_item, including sidecarless rows that
// normal canonical capture cannot name. Its pre-author inventory must retain
// only exact mapped children while proving the whole cascade is safe.
$tombstoneMenu = $uuid(38);
$tombstoneItem = $uuid(39);
$tombstoneDeletion = [
    'format' => 'duo-deletion/v1',
    'uuid' => $tombstoneMenu,
    'kind' => 'menu',
    'type' => 'nav_menu',
    'source_path' => 'menus/tombstoned.json',
    'expected_hash' => $hash('tombstone-menu-expected'),
    'expected_revision' => $hash('tombstone-menu-revision'),
];
$tombstoneContract = $nestedContract;
$tombstoneContract['selectors'] = ['tombstone:' . $tombstoneMenu];
$tombstoneContract['resolution'] = [
    'live_root_entities' => [],
    'tombstone_uuids' => [$tombstoneMenu],
];
$tombstoneContract['live'] = [
    'roots' => [],
    'closure' => [],
    'excluded' => [],
    'inbound' => [],
];
$tombstoneContract['tombstones'] = [[
    'uuid' => $tombstoneMenu,
    'path' => 'deletions/menu/' . $tombstoneMenu . '.json',
    'tombstone_hash' => $hash('tombstone-menu-record'),
    'deletion' => $tombstoneDeletion,
    'policy_deletion_obligations' => [
        'selector' => 'menu:nav_menu',
        'cascades' => ['menu_items', 'term_relationships', 'term_taxonomy', 'termmeta'],
        'guards' => [],
        'declared_by' => ['core'],
        'static_only' => true,
    ],
]];
unset($tombstoneContract['scope_hash']);
$tombstoneContract['scope_hash'] = $hash(Canon::encode($tombstoneContract));
$tombstoneContract = ScopeContract::from_array($tombstoneContract);
$tombstoneCompiled = CompiledRepository::create([
    'tree' => [],
    'deletions' => [$tombstoneMenu => [
        'type' => 'menu',
        'hash' => $hash('tombstone-menu-entry'),
        'path' => 'deletions/menu/' . $tombstoneMenu . '.json',
        'data' => $tombstoneDeletion,
    ]],
    'revision_hash' => $hash('tombstone-compiled-revision'),
    'manifest_hash' => $hash('tombstone-compiled-manifest'),
    'site_hash' => $hash('tombstone-compiled-site'),
    'effects_inventory' => [],
]);
$tombstoneActual = [
    $tombstoneMenu => [
        'type' => 'menu',
        'hash' => $hash('tombstone-target-menu'),
        'path' => 'menus/tombstoned.json',
        'content' => Canon::encode(['uuid' => $tombstoneMenu, 'items' => []]),
    ],
];
$savedTombstoneMaps = $GLOBALS['wpdb']->mapRows;
$savedTombstoneTerms = $GLOBALS['wpdb']->termRows;
$savedTombstoneTaxonomies = $GLOBALS['wpdb']->taxonomyRows;
$savedTombstonePosts = $GLOBALS['wpdb']->postRows;
$GLOBALS['wpdb']->mapRows = [
    ['uuid' => $tombstoneMenu, 'entity_type' => 'menu', 'id_kind' => 'term', 'local_id' => 40],
    ['uuid' => $tombstoneMenu, 'entity_type' => 'menu', 'id_kind' => 'term_taxonomy', 'local_id' => 140],
];
$GLOBALS['wpdb']->termRows = [40 => $tombstoneMenu];
$GLOBALS['wpdb']->taxonomyRows = [140 => ['term_id' => 40, 'taxonomy' => 'nav_menu']];
$GLOBALS['wpdb']->postRows = [400 => [
    'post_type' => 'nav_menu_item', 'post_status' => 'trash',
    'uuid' => '', 'term_taxonomy_ids' => [140],
]];

$sidecarlessTombstoneHashes = ScopedApply::ledger_map_identity_hashes(
    $tombstoneContract,
    $tombstoneCompiled,
    $tombstoneActual,
    true
);
$check(
    $sidecarlessTombstoneHashes === [$hash($tombstoneMenu)],
    'a deletion-authorized tombstone may cascade a sidecarless unmapped item owned only by its selected menu'
);
ScopedApply::assert_selected_ledger_map_observation(
    $nestedPolicy,
    $tombstoneContract,
    $tombstoneActual,
    $sidecarlessTombstoneHashes,
    $tombstoneCompiled,
    true
);
$check(true, 'tombstone sidecarless cascade is strictly observed before any deletion transaction');

// Even without a target map, a hidden sidecar cannot be deleted through M
// when the frozen source assigns that UUID to protected menu N. Tombstone
// deletion removes the physical row, so source ownership is independently
// authoritative from target ledger presence.
$protectedTombstoneItem = $uuid(42);
$protectedTombstoneMenu = $uuid(43);
$protectedTombstoneCompiled = CompiledRepository::create([
    'tree' => [$protectedTombstoneMenu => [
        'type' => 'menu',
        'hash' => $hash('protected-tombstone-owner-menu'),
        'path' => 'menus/protected-tombstone-owner.json',
        'content' => '',
        'data' => [
            'uuid' => $protectedTombstoneMenu,
            'items' => [['uuid' => $protectedTombstoneItem]],
        ],
    ]],
    'deletions' => $tombstoneCompiled->deletions(),
    'revision_hash' => $hash('protected-tombstone-owner-revision'),
    'manifest_hash' => $hash('protected-tombstone-owner-manifest'),
    'site_hash' => $hash('protected-tombstone-owner-site'),
    'effects_inventory' => [],
]);
$GLOBALS['wpdb']->postRows[400]['uuid'] = $protectedTombstoneItem;
try {
    ScopedApply::ledger_map_identity_hashes(
        $tombstoneContract,
        $protectedTombstoneCompiled,
        $tombstoneActual,
        true
    );
    $check(false, 'an unmapped tombstone item owned by a protected source menu must refuse');
} catch (\Duo\CommandRefusalException $failure) {
    $check(
        $failure->reasonCode === 'scoped_identity_recovery_required',
        'menu tombstone inventory refuses a hidden sidecar owned by a protected frozen menu'
    );
}
$GLOBALS['wpdb']->postRows[400]['uuid'] = '';

$GLOBALS['wpdb']->taxonomyRows[141] = ['term_id' => 41, 'taxonomy' => 'category'];
$GLOBALS['wpdb']->postRows[400]['term_taxonomy_ids'] = [140, 141];
try {
    ScopedApply::ledger_map_identity_hashes($tombstoneContract, $tombstoneCompiled, $tombstoneActual, true);
    $check(false, 'a tombstone item with another taxonomy relationship must refuse');
} catch (\Duo\CommandRefusalException $failure) {
    $check(
        $failure->reasonCode === 'scoped_identity_recovery_required',
        'menu tombstone cascade requires full relationship exclusivity before delete_entity()'
    );
}
$GLOBALS['wpdb']->postRows[400]['term_taxonomy_ids'] = [140];
unset($GLOBALS['wpdb']->taxonomyRows[141]);

// A map without its matching physical sidecar is not a safely deletable map
// tuple, even under a selected menu tombstone.
$GLOBALS['wpdb']->mapRows[] = [
    'uuid' => $tombstoneItem,
    'entity_type' => 'menu_item',
    'id_kind' => 'post',
    'local_id' => 400,
];
try {
    ScopedApply::ledger_map_identity_hashes($tombstoneContract, $tombstoneCompiled, $tombstoneActual, true);
    $check(false, 'a map-only tombstone menu item must refuse');
} catch (\Duo\CommandRefusalException $failure) {
    $check(
        $failure->reasonCode === 'scoped_identity_recovery_required',
        'menu tombstone inventory refuses a mapped item without its matching sidecar'
    );
}
array_pop($GLOBALS['wpdb']->mapRows);

$GLOBALS['wpdb']->postRows[400]['uuid'] = $tombstoneItem;
$GLOBALS['wpdb']->mapRows[] = [
    'uuid' => $tombstoneItem,
    'entity_type' => 'post',
    'id_kind' => 'post',
    'local_id' => 400,
];
try {
    ScopedApply::ledger_map_identity_hashes($tombstoneContract, $tombstoneCompiled, $tombstoneActual, true);
    $check(false, 'a mistyped tombstone menu-item map must refuse');
} catch (\Duo\CommandRefusalException $failure) {
    $check(
        $failure->reasonCode === 'scoped_identity_recovery_required',
        'menu tombstone inventory refuses a non-menu-item post map'
    );
}
array_pop($GLOBALS['wpdb']->mapRows);

// The map UUID itself cannot be rebound to a different physical post: the
// tombstone loop deletes P, while Ledger::uuid_for(P) would otherwise miss B.
$GLOBALS['wpdb']->mapRows[] = [
    'uuid' => $tombstoneItem,
    'entity_type' => 'menu_item',
    'id_kind' => 'post',
    'local_id' => 401,
];
try {
    ScopedApply::ledger_map_identity_hashes($tombstoneContract, $tombstoneCompiled, $tombstoneActual, true);
    $check(false, 'a tombstone menu-item map rebound to another local post must refuse');
} catch (\Duo\CommandRefusalException $failure) {
    $check(
        $failure->reasonCode === 'scoped_identity_recovery_required',
        'tombstone inventory refuses a sidecar whose map is bound to another local post'
    );
}
$GLOBALS['wpdb']->mapRows[count($GLOBALS['wpdb']->mapRows) - 1]['local_id'] = 400;
$mappedTombstoneHashes = ScopedApply::ledger_map_identity_hashes(
    $tombstoneContract,
    $tombstoneCompiled,
    $tombstoneActual,
    true
);
$expectedMappedTombstoneHashes = [$hash($tombstoneMenu), $hash($tombstoneItem)];
sort($expectedMappedTombstoneHashes, SORT_STRING);
$check(
    $mappedTombstoneHashes === $expectedMappedTombstoneHashes,
    'an exact mapped tombstone item is sealed into the selected ledger partition before deletion'
);
ScopedApply::assert_selected_ledger_map_observation(
    $nestedPolicy,
    $tombstoneContract,
    $tombstoneActual,
    $mappedTombstoneHashes,
    $tombstoneCompiled,
    true
);
$check(true, 'an exact mapped tombstone menu item passes strict pre-author observation');

$GLOBALS['wpdb']->mapRows = $savedTombstoneMaps;
$GLOBALS['wpdb']->termRows = $savedTombstoneTerms;
$GLOBALS['wpdb']->taxonomyRows = $savedTombstoneTaxonomies;
$GLOBALS['wpdb']->postRows = $savedTombstonePosts;

// The same boundary protects a top-level source entity: a deleted target menu
// with surviving term/term-taxonomy map rows must not make ensure_term_row()
// skip creation or make finalize_menu() write through dead ids.
$missingSelectedMenuActual = $nestedActual;
unset($missingSelectedMenuActual[$selectedMenu], $missingSelectedMenuActual[$selectedSidebar]);
$GLOBALS['wpdb']->mapRows = [[
    'uuid' => $selectedMenu,
    'entity_type' => 'menu',
    'id_kind' => 'term',
    'local_id' => 20,
], [
    'uuid' => $selectedMenu,
    'entity_type' => 'menu',
    'id_kind' => 'term_taxonomy',
    'local_id' => 120,
]];
try {
    ScopedApply::assert_selected_ledger_map_observation(
        $nestedPolicy,
        $nestedContract,
        $missingSelectedMenuActual,
        $nestedIdentityHashes,
        $nestedCompiled,
        true
    );
    $check(false, 'a stale selected top-level menu map must refuse');
} catch (\Duo\CommandRefusalException $failure) {
    $check(
        $failure->reasonCode === 'scoped_identity_recovery_required',
        'a stale selected top-level map refuses before create/finalize can trust dead term ids'
    );
}

$settledTombstoneCheck = \Closure::bind(
    static fn(Policy $p, array $deletion, array $row): bool =>
        ScopedApply::settled_tombstone_map_row_is_absent($p, $deletion, $row),
    null,
    ScopedApply::class
);
$GLOBALS['wpdb']->termRows = [];
$GLOBALS['wpdb']->taxonomyRows = [];
$settledMenuRow = [
    'uuid' => $selectedMenu,
    'entity_type' => 'menu',
    'id_kind' => 'term',
    'local_id' => 20,
];
$check(
    $settledTombstoneCheck($policy, ['kind' => 'menu', 'type' => 'nav_menu'], $settledMenuRow),
    'an already-absent selected tombstone may retain its direct map until terminal ledger finalization'
);
$GLOBALS['wpdb']->termRows = [20 => $selectedMenu];
$check(
    !$settledTombstoneCheck($policy, ['kind' => 'menu', 'type' => 'nav_menu'], $settledMenuRow),
    'a partially-settled tombstone with a surviving physical row refuses instead of being called absent'
);
$GLOBALS['wpdb']->termRows = [];
$GLOBALS['wpdb']->postRows = [];
$GLOBALS['wpdb']->optionRows = [];
$GLOBALS['wpdb']->mapRows = [];

// Operation-bound provider response loss: invocation is durable exactly once,
// reconciliation calls the provider's readback hook, and a mismatched readback
// is recovery_required rather than a second invocation.
$provider = new ScopedRecoveryProvider();
$providerAction = ['kind' => 'provider', 'provider' => 'scoped-recovery', 'capability' => 'repair', 'args' => []];
$providerDecl = [
    'args' => [], 'reads' => ['option:scoped_recovery'], 'writes' => ['option:scoped_recovery'],
    'scope' => 'site', 'idempotent' => true, 'timeout_seconds' => 5,
    'scoped' => ['operation_envelope' => Providers::SCOPED_OPERATION_FORMAT, 'reconcile' => true],
];
$providerOperation = [
    'authority_hash' => $hash('provider-authority'),
    'lease_session_id' => 'provider-lease-session',
    'operation_id' => 'provider-operation-1',
    'input_hash' => Providers::scoped_input_hash($providerAction, $providerDecl),
    'effect_hash' => $hash('provider-effect'),
];
$providerReceipt = Providers::invoke_scoped($provider, $providerAction, $providerDecl, $providerOperation);
$providerRecovered = Providers::reconcile_scoped($provider, $providerAction, $providerDecl, $providerOperation);
$check(
    $providerReceipt['status'] === 'verified'
        && $providerRecovered['status'] === 'verified'
        && $provider->invocations === 1
        && $provider->reconciliations === 1,
    'lost provider response reconciles the same operation without a second invocation'
);
$provider->state = 99;
$expectThrow(
    static fn() => Providers::reconcile_scoped($provider, $providerAction, $providerDecl, $providerOperation),
    'recovery_required',
    'provider postcondition drift is recovery_required and still does not reinvoke'
);
$check($provider->invocations === 1, 'provider mismatch recovery never invokes the effect twice');

$outerProviderReceipt = [
    'after_hash' => hash('sha256', Canon::encode($providerReceipt)),
];
ScopedApplySession::require_reviewed_effect_receipt($outerProviderReceipt, $providerReceipt);
$expectThrow(
    static fn() => ScopedApplySession::require_reviewed_effect_receipt(
        $outerProviderReceipt,
        array_replace($providerReceipt, ['after_hash' => $hash('changed-reviewed-provider-readback')])
    ),
    'postcondition no longer matches',
    'an existing outer receipt is accepted only after a fresh reviewed effect readback still matches it'
);

// Drive the same recovery through the real product dispatcher. The session
// resumes only after the selected action/capability evidence is re-proved;
// the dispatcher then decides from the durable inner operation whether to
// reconcile, invoke once, or leave an unknowable intent recovery_required.
$dispatchAction = [
    'kind' => 'provider',
    'manifest' => 'scoped-recovery',
    'index' => 0,
    'provider' => 'scoped-recovery',
    'capability' => 'repair',
    'args' => [],
    'triggers' => ['option:scoped_recovery'],
    'effects' => [[
        'id' => 'scoped-recovery-effect',
        'kind' => 'external',
        'mode' => 'irreversible',
        'selector' => [
            'scope' => 'external',
            'type' => 'provider_resource',
            'value' => 'scoped-recovery:v1',
        ],
    ]],
];
$dispatchCapabilityDigest = Providers::scoped_capability_digest(
    'scoped-recovery',
    'repair',
    $providerDecl
);
$dispatchProvider = new ScopedRecoveryProvider();
$dispatchNegotiation = [
    'providers' => ['scoped-recovery' => $dispatchProvider],
    'capabilities' => ['scoped-recovery' => ['repair' => $providerDecl]],
    'scoped_capabilities' => ['scoped-recovery' => ['repair' => [
        'operation_envelope' => Providers::SCOPED_OPERATION_FORMAT,
        'receipt_format' => Providers::SCOPED_RECEIPT_FORMAT,
        'capability_digest' => $dispatchCapabilityDigest,
    ]]],
];
$dispatchActionRow = [
    'manifest' => 'scoped-recovery',
    'index' => 0,
    'declaration_hash' => hash('sha256', Canon::encode($dispatchAction)),
];
$dispatchSelection = [
    'work_hash' => ScopedApplySession::hash_value([]),
    'work_items' => [],
    'deletions_hash' => ScopedApplySession::hash_value([]),
    'deletion_items' => [],
    'action_declarations_hash' => ScopedApplySession::hash_value([$dispatchActionRow]),
    'action_items' => [$dispatchActionRow],
    'capabilities_hash' => ScopedApplySession::hash_value($dispatchNegotiation['scoped_capabilities']),
    'effects_hash' => ScopedApplySession::hash_value([[
        'action_hash' => hash('sha256', Canon::encode($dispatchActionRow)),
        'effect_hash' => hash('sha256', Canon::encode($dispatchAction['effects'][0])),
    ]]),
    'effect_items' => [[
        'action_hash' => hash('sha256', Canon::encode($dispatchActionRow)),
        'effect_hash' => hash('sha256', Canon::encode($dispatchAction['effects'][0])),
    ]],
    'ledger_map_identity_hashes' => [],
    'ledger_map_identity_set_hash' => ScopedApplySession::hash_value([]),
];
$makeDispatchRecovery = static function (string $label) use (
    $contract,
    $compiled,
    $dispatchSelection,
    $hash,
    $protectedRoot,
    $selectedBeforeRoot
): array {
    $authority = ScopedApplySession::make_authority(
        $contract['scope_hash'],
        [
            'artifact_hash' => $compiled->artifact_hash(),
            'state_revision_hash' => $compiled->revision_hash(),
            'manifest_hash' => $compiled->manifest_hash(),
        ],
        [
            'owner' => 'dispatch-recovery-owner',
            'artifact_hash' => $compiled->artifact_hash(),
            'session_id' => 'dispatch-recovery-' . $label,
        ],
        [
            'selected_before_hash' => $selectedBeforeRoot,
            'selected_before_ledger_map_hash' => $hash('dispatch-selected-map-' . $label),
            'protected_ledger_map_hash' => $hash('dispatch-protected-map-' . $label),
            'protected_out_of_scope_hash' => $protectedRoot,
            'ledger_roots_hash' => $hash('dispatch-ledger-roots-' . $label),
        ],
        [
            'precondition_hash' => $hash('dispatch-preconditions-' . $label),
            'guard_witnesses_hash' => $hash('dispatch-guards-' . $label),
        ],
        $dispatchSelection,
        $hash('dispatch-code-' . $label)
    );
    $store = new ScopedRecoveryMemoryStore();
    $session = ScopedApplySession::begin($store, $authority);
    $session->transition(ScopedApplySession::PHASE_AUTHORING);
    $author = \Duo\ScopedApplyCoordinator::intent(
        $session,
        1,
        'dispatch-author',
        'dispatch-author-operation',
        $hash('dispatch-author-input-' . $label),
        $hash('dispatch-author-effect-' . $label),
        $selectedBeforeRoot
    );
    $session->append_intent($author);
    $session->commit_authored_receipt(\Duo\ScopedApplyCoordinator::receipt(
        $author,
        \Duo\ScopedApplyCoordinator::authored_ledger_map_hash([
            'selected_before_root' => $selectedBeforeRoot,
            'selected_ledger_map_root' => $hash('dispatch-selected-map-after-' . $label),
        ])
    ));
    $session->transition(ScopedApplySession::PHASE_EFFECTS_PENDING);
    $core = \Duo\ScopedApplyCoordinator::intent(
        $session,
        2,
        'dispatch-core',
        'dispatch-core-operation',
        $hash('dispatch-core-input-' . $label),
        $hash('dispatch-core-effect-' . $label),
        $selectedBeforeRoot
    );
    $session->append_intent($core);
    $session->append_receipt(\Duo\ScopedApplyCoordinator::receipt(
        $core,
        $hash('dispatch-core-after-' . $label)
    ));
    $session->recover($hash('dispatch-recovery-cause-' . $label));
    return [$store, $authority, $session];
};
$dispatch = new \Duo\RebuildActionDispatcher(
    $policy,
    new \Duo\ProviderActionBatchBuilder($policy, []),
    static function (): void {}
);
$driveScopedDispatch = static function (ScopedApplySession $session) use (
    $dispatch,
    $dispatchAction,
    $dispatchNegotiation,
    $selectedBeforeRoot
): array {
    $warnings = [];
    $receipts = [];
    $failure = null;
    try {
        $dispatch->dispatch(
            [$dispatchAction],
            $dispatchNegotiation,
            [],
            [],
            [],
            [],
            [],
            [],
            false,
            true,
            $session,
            ['selected_before_root' => $selectedBeforeRoot],
            $warnings,
            $receipts
        );
    } catch (Throwable $caught) {
        $failure = $caught;
    }
    return ['failure' => $failure, 'warnings' => $warnings, 'receipts' => $receipts];
};

[$dispatchStore, $dispatchAuthority, $dispatchSession] = $makeDispatchRecovery('verified');
\Duo\ScopedApplyCoordinator::assert_recovery_selection(
    $dispatchSession,
    [$dispatchAction],
    $dispatchNegotiation
);
$dispatchSession->resume_recorded_recovery();
$firstDispatch = $driveScopedDispatch($dispatchSession);
$check(
    $firstDispatch['failure'] === null
        && $dispatchProvider->invocations === 1
        && $dispatchProvider->reconciliations === 0
        && count($dispatchSession->receipts()) === 3,
    'product dispatcher invokes an absent scoped operation once after phase-exact recovery resume'
);
$dispatchSession->recover($hash('dispatch-lost-response'));
$reopenedDispatch = ScopedApplySession::begin($dispatchStore, $dispatchAuthority);
\Duo\ScopedApplyCoordinator::assert_recovery_selection(
    $reopenedDispatch,
    [$dispatchAction],
    $dispatchNegotiation
);
$reopenedDispatch->resume_recorded_recovery();
$secondDispatch = $driveScopedDispatch($reopenedDispatch);
$check(
    $secondDispatch['failure'] === null
        && $dispatchProvider->invocations === 1
        && $dispatchProvider->reconciliations === 1
        && count($reopenedDispatch->receipts()) === 3,
    'product dispatcher reconciles a verified retained effect without a second invocation'
);

[, , $changedSelectionSession] = $makeDispatchRecovery('changed-selection');
$changedAction = $dispatchAction;
$changedAction['args'] = ['changed' => true];
$changedSelectionWorkflow = new \Duo\ScopedApplyWorkflow();
$changedSelectionWorkflow->session = $changedSelectionSession;
$retainedSelectionRecovery = $changedSelectionSession->canonical();
$expectThrow(
    static fn() => $changedSelectionWorkflow->assert_recovery_selection(
        [$changedAction],
        $dispatchNegotiation
    ),
    'action/capability evidence changed',
    'changed selected action evidence refuses before product recovery resume'
);
$check(
    $changedSelectionSession->is_recovery_required()
        && $changedSelectionSession->canonical() === $retainedSelectionRecovery,
    'a changed action/capability recheck leaves the retained recovery witness untouched'
);

[, , $normalSelectionSession] = $makeDispatchRecovery('normal-selection-drift');
$normalSelectionSession->resume_recorded_recovery();
$normalSelectionWorkflow = new \Duo\ScopedApplyWorkflow();
$normalSelectionWorkflow->session = $normalSelectionSession;
$expectThrow(
    static fn() => $normalSelectionWorkflow->assert_recovery_selection(
        [$changedAction],
        $dispatchNegotiation
    ),
    'action/capability evidence changed',
    'a crash-after-resume normal phase repeats action/capability selection checks'
);
$check(
    $normalSelectionSession->is_recovery_required()
        && $normalSelectionSession->recorded_recovery_phase() === ScopedApplySession::PHASE_EFFECTS_PENDING,
    'normal-phase selection mismatch deterministically re-gates the exact active phase'
);

[$intentStore, $intentAuthority, $intentSession] = $makeDispatchRecovery('intent-only');
$intentOperation = \Duo\ScopedApplyCoordinator::effect_operation(
    $intentSession,
    3,
    Providers::scoped_input_hash($dispatchAction, $providerDecl),
    \Duo\ScopedApplyCoordinator::action_effect_hash($dispatchAction)
);
Providers::begin_scoped_operation('scoped-recovery', 'repair', $intentOperation);
\Duo\ScopedApplyCoordinator::assert_recovery_selection(
    $intentSession,
    [$dispatchAction],
    $dispatchNegotiation
);
$intentSession->resume_recorded_recovery();
$intentFirst = $driveScopedDispatch($intentSession);
$check(
    $intentFirst['failure'] instanceof RuntimeException
        && str_contains($intentFirst['failure']->getMessage(), 'durable intent without a verified receipt')
        && $intentSession->is_recovery_required(),
    'product dispatcher keeps an intent-only effect recovery_required instead of invoking it'
);
$intentRetry = ScopedApplySession::begin($intentStore, $intentAuthority);
\Duo\ScopedApplyCoordinator::assert_recovery_selection(
    $intentRetry,
    [$dispatchAction],
    $dispatchNegotiation
);
$intentRetry->resume_recorded_recovery();
$intentSecond = $driveScopedDispatch($intentRetry);
$check(
    $intentSecond['failure'] instanceof RuntimeException
        && $intentRetry->is_recovery_required()
        && $dispatchProvider->invocations === 1
        && $dispatchProvider->reconciliations === 1,
    'same-process retry never re-invokes or reconciles an unknowable intent-only effect'
);

// Native transient delete uses the same operation receipt channel and must
// not infer execution merely from an absent transient.
$nativeName = 'scoped_recovery_native';
$GLOBALS['wpdb']->optionRows['_transient_' . $nativeName] = 'stale';
$GLOBALS['wpdb']->optionRows['_transient_timeout_' . $nativeName] = '123';
$GLOBALS['scoped_recovery_cache']['transient'][$nativeName] = false;
$nativeArgs = ['name' => $nativeName];
$nativeOperation = [
    'authority_hash' => $hash('native-authority'),
    'lease_session_id' => 'native-lease-session',
    'operation_id' => 'native-operation-1',
    'input_hash' => NativeActions::scoped_input_hash('transient.delete', $nativeArgs),
    'effect_hash' => $hash('native-effect'),
];
$nativeReceipt = NativeActions::invoke_scoped('transient.delete', $nativeArgs, $nativeOperation);
$nativeRecovered = NativeActions::reconcile_scoped('transient.delete', $nativeArgs, $nativeOperation);
$check(
    $nativeReceipt['status'] === 'verified'
        && $nativeRecovered['status'] === 'verified'
        && $GLOBALS['scoped_recovery_delete_calls'] === 1,
    'lost native-action response reconciles the exact receipt without a second delete'
);
$outerNativeReceipt = [
    'after_hash' => hash('sha256', Canon::encode($nativeReceipt)),
];
ScopedApplySession::require_reviewed_effect_receipt($outerNativeReceipt, $nativeRecovered);
$check(
    $GLOBALS['scoped_recovery_delete_calls'] === 1,
    'an existing native outer receipt is checked against reconciliation evidence without invoking twice'
);
$coreReadbackHash = $hash('scoped-core-readback');
ScopedApplySession::require_effect_receipt_hash(
    ['after_hash' => $coreReadbackHash],
    $coreReadbackHash
);
$expectThrow(
    static fn() => ScopedApplySession::require_effect_receipt_hash(
        ['after_hash' => $coreReadbackHash],
        $hash('changed-scoped-core-readback')
    ),
    'postcondition no longer matches',
    'an existing engine-owned effect receipt also requires its fresh schedule/count readback hash'
);

// Complete the original authored session after its effect phase and prove the
// terminal receipt is the exact replay payload a lost CLI response can return.
$reopenedAfterCommit->transition(ScopedApplySession::PHASE_EFFECTS_PENDING);
$reopenedAfterCommit->transition(ScopedApplySession::PHASE_VERIFYING);
$verifierEffectsRoot = ScopedApplySession::hash_value($reopenedAfterCommit->receipts());
$verifiedSession = ScopedApplySession::require_verifying_evidence(
    $sessionStore,
    $reopenedAfterCommit->authority_hash_value(),
    $verifierEffectsRoot,
    $contract['scope_hash'],
    $artifactHash
);
$check(
    $verifiedSession->canonical() === $reopenedAfterCommit->canonical(),
    'fresh verifier opens and binds the exact active authority/effect receipt roots rather than echoing argv'
);
$expectThrow(
    static fn() => ScopedApplySession::require_verifying_evidence(
        $sessionStore,
        $hash('wrong-verifier-authority'),
        $verifierEffectsRoot,
        $contract['scope_hash'],
        $artifactHash
    ),
    'authority/effect evidence mismatch',
    'fresh verifier refuses a caller-supplied authority hash that differs from the active session'
);
$expectThrow(
    static fn() => ScopedApplySession::require_verifying_evidence(
        $sessionStore,
        $reopenedAfterCommit->authority_hash_value(),
        $hash('wrong-verifier-effects'),
        $contract['scope_hash'],
        $artifactHash
    ),
    'authority/effect evidence mismatch',
    'fresh verifier refuses a caller-supplied effects root that differs from active receipts'
);
$terminalTarget = [
    'protected_ledger_map_hash' => $hash('protected-map-before'),
    // A create/adopt/delete may legitimately change only the selected map.
    'selected_ledger_map_hash' => $hash('selected-map-after'),
];
$reopenedAfterCommit->complete($hash('scoped-convergence'), $terminalTarget);
$terminalBytes = $reopenedAfterCommit->terminal_receipt_bytes();
$terminalReplay = ScopedApplySession::begin($sessionStore, $authority);
$check(
    is_string($terminalBytes)
        && $terminalReplay->is_terminal()
        && $terminalReplay->terminal_receipt_bytes() === $terminalBytes
        && $terminalReplay->terminal_identity() === $reopenedAfterCommit->terminal_identity(),
    'lost terminal CLI response reopens one byte-stable receipt and identity'
);
$check(
    ($terminalReplay->terminal_receipt()['selected_ledger_map_hash'] ?? null)
        === $terminalTarget['selected_ledger_map_hash']
        && ($terminalReplay->terminal_receipt()['selected_ledger_map_hash'] ?? null)
        !== $authority['target']['selected_before_ledger_map_hash']
        && ($terminalReplay->terminal_receipt()['protected_ledger_map_hash'] ?? null)
        === $authority['target']['protected_ledger_map_hash'],
    'terminal replay binds the changed selected map after create/adopt/delete while protecting the out-of-scope map'
);
$terminalObservation = [
    'protected_out_of_scope_root' => $protectedRoot,
    'protected_ledger_map_root' => $terminalTarget['protected_ledger_map_hash'],
    'selected_ledger_map_root' => $terminalTarget['selected_ledger_map_hash'],
];
$check(
    ScopedApply::terminal_replay_matches(
        $actualDesired,
        $compiled,
        $policy,
        $contract,
        $authority,
        (array) $terminalReplay->terminal_receipt(),
        $terminalObservation
    ),
    'product terminal gate accepts desired authored state plus the post-finalization selected-map root'
);
$selectedMapDrift = $terminalObservation;
$selectedMapDrift['selected_ledger_map_root'] = $hash('selected-map-mutated-after-terminal');
$check(
    !ScopedApply::terminal_replay_matches(
        $actualDesired,
        $compiled,
        $policy,
        $contract,
        $authority,
        (array) $terminalReplay->terminal_receipt(),
        $selectedMapDrift
    ),
    'product terminal gate refuses selected identity-map drift after completion'
);
$selectedMapPreApply = $terminalObservation;
$selectedMapPreApply['selected_ledger_map_root'] = $authority['target']['selected_before_ledger_map_hash'];
$check(
    !ScopedApply::terminal_replay_matches(
        $actualDesired,
        $compiled,
        $policy,
        $contract,
        $authority,
        (array) $terminalReplay->terminal_receipt(),
        $selectedMapPreApply
    ),
    'product terminal gate never mistakes the pre-apply selected-map root for a successful create/adopt/delete replay'
);

// Generic apply interlock: an active nonterminal scoped session owns the
// single slot, so a different operation cannot silently start beside it. A
// terminal session is intentionally reopenable by the exact authority above.
$interlockStore = new ScopedRecoveryMemoryStore();
$interlockSession = ScopedApplySession::begin($interlockStore, $authority);
$interlockSession->transition(ScopedApplySession::PHASE_AUTHORING);
$differentAuthority = $authority;
$differentAuthority['lease']['session_id'] = 'different-recovery-session';
unset($differentAuthority['authority_hash']);
$differentAuthority = ScopedApplySession::seal_authority($differentAuthority);
$expectThrow(
    static fn() => ScopedApplySession::begin($interlockStore, $differentAuthority),
    'different scoped apply session',
    'generic apply interlock refuses a different operation while scoped work is nonterminal'
);
$check(
    ScopedApplySession::open($interlockStore)?->phase() === ScopedApplySession::PHASE_AUTHORING,
    'generic interlock leaves the original nonterminal authority intact'
);

// Exercise the public full-plan entrypoint at its generic interlock gate as
// well. The scratch repository is compile-only; the fake ledger returns an
// already-persisted nonterminal session, so Apply::plan() must refuse before
// it reaches any target snapshot or mutation path.
$interlockRepo = sys_get_temp_dir() . '/duo-scoped-apply-interlock-' . bin2hex(random_bytes(5));
if (!mkdir($interlockRepo . '/state/options', 0700, true)
    || !mkdir($interlockRepo . '/media', 0700, true)) {
    throw new RuntimeException('could not create scoped apply interlock fixture');
}
register_shutdown_function(static function () use ($interlockRepo): void {
    if (!is_dir($interlockRepo)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($interlockRepo, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($interlockRepo);
});
file_put_contents($interlockRepo . '/site.duo.json', Canon::encode([
    'spec_version' => DUO_SPEC_VERSION,
    'manifests' => ['core'],
    'policy' => [
        'options' => (object) [],
        'post_meta' => (object) [],
        'term_meta' => (object) [],
        'post_types' => ['post', 'page', 'attachment'],
        'taxonomies' => ['category', 'post_tag'],
    ],
]));
$requiredOptions = [];
foreach ([
    'active_plugins', 'blog_public', 'blogdescription', 'blogname', 'default_category',
    'page_for_posts', 'page_on_front', 'permalink_structure', 'posts_per_page',
    'show_on_front', 'sticky_posts', 'stylesheet', 'template',
    'wp_page_for_privacy_policy',
] as $name) {
    $requiredOptions[$name] = \Duo\OptionState::absent();
}
file_put_contents(
    $interlockRepo . '/state/options/core.json',
    Canon::encode(\Duo\OptionState::document($requiredOptions))
);
$GLOBALS['wpdb']->kvRows[ScopedApplySession::STORAGE_KEY] = $interlockSession->canonical();
$expectThrow(
    static fn() => \Duo\Apply::plan($interlockRepo),
    'full plan refused',
    'public full apply planning interlock refuses before target contact while scoped work is nonterminal'
);
$check(
    $GLOBALS['wpdb']->kvRows[ScopedApplySession::STORAGE_KEY] === $interlockSession->canonical(),
    'public full-plan interlock leaves the exact scoped session bytes untouched'
);

$executorWithoutParticipant = (new ReflectionClass(\Duo\AuthoredTransactionExecutor::class))
    ->newInstanceWithoutConstructor();
$executorWarnings = [];
$expectThrow(
    static function () use ($executorWithoutParticipant, &$executorWarnings): void {
        $executorWithoutParticipant->execute(
            new \Duo\AuthoredTransactionRequest(
                new \Duo\ApplyWorkset([], [], [], [], [], [], []),
                new \Duo\DeletionAuthority(false, false, false),
                true,
                [],
                true,
                null,
                null,
                null
            ),
            $executorWarnings
        );
    },
    'invalid scoped commit participant',
    'direct scoped executor refuses a performing request without atomic session participants before target setup'
);

// The full public Apply path needs WordPress and a promotion lease, so pin the
// four recovery/isolation threading edges in its bounded run()/rebuild()
// source. The protocol seams they call are exercised dynamically above.
$applySource = (string) file_get_contents($root . '/agent/src/Apply/ApplyRequestCoordinator.php');
$preparationSource = (string) file_get_contents($root . '/agent/src/Apply/ApplyPreparationCoordinator.php');
$rebuildCoordinatorSource = (string) file_get_contents($root . '/agent/src/Apply/ApplyRebuildCoordinator.php');
$batchBuilderSource = (string) file_get_contents($root . '/agent/src/Adapter/ProviderActionBatchBuilder.php');
$scopedCoordinatorSource = (string) file_get_contents($root . '/agent/src/Scope/ScopedApplyCoordinator.php');
$scopedWorkflowSource = (string) file_get_contents($root . '/agent/src/Scope/ScopedApplyWorkflow.php');
$authoredExecutorSource = (string) file_get_contents($root . '/agent/src/Apply/AuthoredTransactionExecutor.php');
$actionDispatcherSource = (string) file_get_contents($root . '/agent/src/Rebuild/RebuildActionDispatcher.php');
$actionNegotiatorSource = (string) file_get_contents($root . '/agent/src/Rebuild/RebuildActionNegotiator.php');
$ledgerFinalizerSource = (string) file_get_contents($root . '/agent/src/Apply/ApplyLedgerFinalizer.php');
$convergenceVerifierSource = (string) file_get_contents($root . '/agent/src/Apply/ConvergenceVerifier.php');
$repoFormatSource = (string) file_get_contents($root . '/spec/repo-format.md');
$check(
    str_contains(
        preg_replace('/\s+/', ' ', $ledgerFinalizerSource),
        '!empty($requestedRevision) ? $requestedRevision : $compiled->revision_hash()'
    ),
    'ordinary ledger finalization preserves the legacy empty revision fallback, including string zero'
);
$legacyScopedGuardAt = strpos(
    $applySource,
    'PromotionLock::scoped_session_id($promotionOwner, $promotionArtifact);'
);
$promotionAcquireAt = strpos(
    $applySource,
    "PromotionLock::acquire(\$promotionOwner, \$promotionArtifact, 'apply', null, true);"
);
$check(
    $legacyScopedGuardAt !== false
        && $promotionAcquireAt !== false
        && $legacyScopedGuardAt < $promotionAcquireAt
        && str_contains($scopedCoordinatorSource, '$sessionId = PromotionLock::scoped_session_id('),
    'scoped continuations refuse legacy generations before lease renewal and authority seals only random generations'
);
$deleteGateAt = strpos($preparationSource, 'scoped apply selected live tombstones but --with-deletes was not supplied');
$preparedAt = strpos($applySource, '$prepared = $this->preparationCoordinator->prepare(');
$freshActualContractAt = strpos($preparationSource, 'freshActual: $freshActual');
$freshActualHandoffAt = strpos($applySource, '$freshActual = $prepared->freshActual;');
$authoredStateAt = strpos($applySource, 'ScopedApply::authored_state(');
$freshActualProbe = ['selected' => ['sentinel' => true]];
$preparedProbe = new \Duo\PreparedApply(
    freshPlan: [],
    work: [],
    deleteWork: [],
    rebuildDeleteWork: [],
    deleteUuids: [],
    guardRepairUuids: [],
    negotiation: [],
    executeDeletes: false,
    defaultAuthor: null,
    freshActual: $freshActualProbe
);
$terminalArchiveAt = strpos($applySource, '$this->scopedWorkflow->terminalSessionToArchive->archive_terminal();');
$archivedReplayLookupAt = strpos($applySource, 'ScopedApplySession::open_terminal_for_request(');
$sessionBeginAt = strpos($applySource, 'ScopedApplySession::begin(');
$promotionSessionBeginAt = strpos($applySource, 'PromotionLock::begin_apply_session(');
$check(
    $deleteGateAt !== false && $preparedAt !== false && $sessionBeginAt !== false && $preparedAt < $sessionBeginAt,
    'scoped tombstones without --with-deletes refuse before a session can authorize target mutation'
);
$check(
    $promotionSessionBeginAt !== false
        && $sessionBeginAt !== false
        && $promotionSessionBeginAt < $sessionBeginAt
        && str_contains(
            (string) file_get_contents($root . '/agent/src/Promotion/PromotionLease.php'),
            "PromotionSessionJournal::start("
        )
        && str_contains(
            (string) file_get_contents($root . '/agent/src/Promotion/PromotionLease.php'),
            "'ps-' . bin2hex(random_bytes(16))"
        ),
    'direct scoped apply publishes a random promotion generation only at the sealed-authority boundary'
);
$check(
    substr_count($applySource . $preparationSource, 'CommandRefusalException::applyRefused(') >= 3
        && str_contains(
            $applySource,
            'scoped apply refused because its scope evidence is stale or invalid for the current source artifact'
        )
        && str_contains(
            $applySource,
            'terminal scoped receipt no longer describes the live bounded target; no mutation or replay attempted'
        )
        && str_contains(
            $preparationSource,
            'scoped apply selected live tombstones but --with-deletes was not supplied; '
        ),
    'known stale-scope, stale-terminal, and missing-delete scoped gates retain typed public refusals'
);
$check(
    $freshActualContractAt !== false
        && $freshActualHandoffAt !== false
        && $authoredStateAt !== false
        && $freshActualHandoffAt < $authoredStateAt,
    'scoped preparation carries the fresh target snapshot through PreparedApply before authored-state validation'
);
$check(
    $preparedProbe->freshActual === $freshActualProbe,
    'PreparedApply declares and preserves the freshActual named state contract'
);
$check(
    $terminalArchiveAt !== false
        && $deleteGateAt !== false
        && $sessionBeginAt !== false
        && $preparedAt < $terminalArchiveAt
        && $terminalArchiveAt < $sessionBeginAt,
    'a different scoped request preserves prior terminal evidence until every new-operation gate passes'
);
$check(
    $archivedReplayLookupAt !== false && $archivedReplayLookupAt < $terminalArchiveAt,
    'public apply resolves an exact archived scope/source terminal before rotating or minting a new authority'
);
$check(
    substr_count($actionDispatcherSource, 'NativeActions::reconcile_scoped(') === 2
        && substr_count($actionDispatcherSource, 'Providers::reconcile_scoped(') === 2
        && substr_count($actionDispatcherSource, 'ScopedApplySession::require_reviewed_effect_receipt(') === 2,
    'both no-receipt and existing-receipt native/provider paths reconcile before trusting completion'
);
$check(
    str_contains($rebuildCoordinatorSource, '$durableReparents = $request->scoped')
        && str_contains($rebuildCoordinatorSource, '$durableDeletions = $request->scoped')
        && str_contains($rebuildCoordinatorSource, 'if (!$request->scoped) {')
        && str_contains($rebuildCoordinatorSource, '$this->services->dependency_regenerator()->run(')
        && str_contains($batchBuilderSource, 'Ledger::kv_prefix(self::REGEN_PENDING_PREFIX) : []'),
    'scoped rebuild neither consumes nor sweeps the generic regen retry/context keyspaces'
);
$check(
    str_contains($actionNegotiatorSource, "foreach (['deletions', 'reparents'] as \$channel)")
        && str_contains($actionNegotiatorSource, "provider channel '\$channel'")
        && str_contains($actionNegotiatorSource, 'durable environment-local recovery input'),
    'scoped preflight refuses provider context channels whose local-id payload cannot be reconstructed after a crash'
);
$codeWitnessCheckAt = strpos($applySource, "'duo:scoped-code-witness-changed'");
$sessionBeginAt = strpos($applySource, 'ScopedApplySession::begin(');
$protectedTargetCheckAt = strpos($applySource, "'duo:scoped-protected-target-drift'");
$authoredBoundaryCheckAt = strpos($applySource, '->assert_authored_recovery_boundary(');
$recordedRecoveryResumeAt = strpos($applySource, '->resume_recorded_recovery();');
$authorReceiptSealAt = strpos($applySource, '$commitScopedAuthoring = static function');
$authoredExecutorAt = strpos($applySource, '->authored_transaction_executor()->execute(');
$finalAuthorReadbackAt = strrpos($applySource, 'ScopedApplyCoordinator::authored_ledger_map_hash($afterObservation)');
$rebuildRenewAt = strpos($applySource, "renew_promotion_lock('apply-rebuild')");
$selectionRecheckAt = strpos($preparationSource, '->assert_recovery_selection(');
$freshRebuildAt = strpos($preparationSource, '$freshRebuildWork = $this->services->apply_planner()->rebuild_work(');
$freshRecoveryProjectionAt = strpos(
    $preparationSource,
    '$freshRebuildWork = ScopedApplyWorkProjector::project('
);
$freshSelectedActionsAt = strpos($preparationSource, '$freshSelectedActions = $this->policy->actions_for(');
$attachmentSealAt = strpos($authoredExecutorSource, '->seal_authored_transaction();');
$canaryCheckAt = strpos($authoredExecutorSource, '$violations = Canary::violations();');
$isolationCheckAt = strpos($authoredExecutorSource, "'authored transaction final commit boundary'");
$atomicParticipantAt = strpos($authoredExecutorSource, '$commitScopedAuthoring();');
$databaseCommitAt = strpos($authoredExecutorSource, "Db::commit('apply transaction commit')");
$authoredEngineBoundaryAt = strpos(
    $authoredExecutorSource,
    "'authored transaction storage-engine boundary'"
);
$termParticipantAt = strpos($authoredExecutorSource, '->begin_authored_transaction();', $authoredEngineBoundaryAt + 1);
$check(
    substr_count($applySource . $scopedCoordinatorSource, 'ScopedApply::code_witness_hash(') === 2
        && $codeWitnessCheckAt !== false
        && $sessionBeginAt !== false
        && $codeWitnessCheckAt < $sessionBeginAt,
    'recovery re-proves the sealed code/lifecycle witness before opening or advancing target mutation state'
);
$check(
    $selectionRecheckAt !== false
        && substr_count($preparationSource, 'ScopedApplyWorkProjector::project(') === 2
        && $freshRebuildAt !== false
        && $freshRecoveryProjectionAt !== false
        && $freshSelectedActionsAt !== false
        && $freshRebuildAt < $freshRecoveryProjectionAt
        && $freshRecoveryProjectionAt < $freshSelectedActionsAt
        && $codeWitnessCheckAt !== false
        && $protectedTargetCheckAt !== false
        && $sessionBeginAt !== false
        && $authoredBoundaryCheckAt !== false
        && $recordedRecoveryResumeAt !== false
        && $authorReceiptSealAt !== false
        && $authoredExecutorAt !== false
        && $finalAuthorReadbackAt !== false
        && $rebuildRenewAt !== false
        && $preparedAt !== false
        && $preparedAt < $codeWitnessCheckAt
        && $codeWitnessCheckAt < $protectedTargetCheckAt
        && $protectedTargetCheckAt < $sessionBeginAt
        && $sessionBeginAt < $authoredBoundaryCheckAt
        && $authoredBoundaryCheckAt < $recordedRecoveryResumeAt
        && $recordedRecoveryResumeAt < $authorReceiptSealAt
        && $authorReceiptSealAt < $authoredExecutorAt
        && $authoredExecutorAt < $finalAuthorReadbackAt
        && $finalAuthorReadbackAt < $rebuildRenewAt,
    'product recovery rechecks selected authority and author receipt before resume, executor, or rebuild'
);
$check(
    substr_count($applySource, '->assert_authored_recovery_boundary(') === 1
        && str_contains($scopedWorkflowSource, '$recordedPhase ?? $this->session->phase()')
        && str_contains($scopedWorkflowSource, 'ScopedApplySession::PHASE_EFFECTS_PENDING')
        && str_contains($scopedWorkflowSource, 'ScopedApplySession::PHASE_VERIFYING')
        && str_contains($scopedWorkflowSource, 'ScopedApplyCoordinator::authored_ledger_map_hash($observation)')
        && !str_contains(
            substr(
                $scopedWorkflowSource,
                (int) strpos($scopedWorkflowSource, 'if ($authoredState !== \'desired\')'),
                2400
            ),
            'guard_witnesses_hash'
        ),
    'normal post-author phases repeat composite receipt checks without reusing pre-author deletion guards'
);
$check(
    $attachmentSealAt !== false
        && $canaryCheckAt !== false
        && $isolationCheckAt !== false
        && $atomicParticipantAt !== false
        && $databaseCommitAt !== false
        && $attachmentSealAt < $canaryCheckAt
        && $canaryCheckAt < $isolationCheckAt
        && $isolationCheckAt < $atomicParticipantAt
        && $atomicParticipantAt < $databaseCommitAt
        && strpos($authoredExecutorSource, 'if (($commitScopedAuthoring !== null) !== $requiresScopedParticipant') !== false
        && strpos($authoredExecutorSource, '$rollbackScopedAuthoring();') > strpos($authoredExecutorSource, "Db::rollback('apply transaction rollback')"),
    'atomic scoped map receipt runs after seal/canary/isolation and before commit, with post-rollback reload'
);
$convergenceObservationAt = strpos($convergenceVerifierSource, '$observation = ScopedApply::observe_target(');
$convergenceMapWitnessAt = strpos(
    $convergenceVerifierSource,
    'ScopedApply::authored_ledger_map_hash($observation)'
);
$convergenceSelectedAt = strpos($convergenceVerifierSource, '$selected = ScopedApply::selected_set(');
$convergenceReceiptAt = strpos(
    $convergenceVerifierSource,
    '\'authored_ledger_map_hash\' => $authoredLedgerMapHash'
);
$finalizerMapLockAt = strpos($ledgerFinalizerSource, 'self::locked_map_inventory()');
$finalizerForgetAt = strpos($ledgerFinalizerSource, 'Ledger::forget($row[\'uuid\']);');
$finalizerMapReadbackAt = strrpos($ledgerFinalizerSource, 'self::locked_map_inventory()');
$finalizerCompleteAt = strpos($ledgerFinalizerSource, '$scopedSession->complete(');
$check(
    $convergenceObservationAt !== false
        && $convergenceMapWitnessAt !== false
        && $convergenceSelectedAt !== false
        && $convergenceReceiptAt !== false
        && $convergenceObservationAt < $convergenceMapWitnessAt
        && $convergenceMapWitnessAt < $convergenceSelectedAt
        && $convergenceSelectedAt < $convergenceReceiptAt
        && str_contains(
            $convergenceVerifierSource,
            'selected identity-map drift after authored commit'
        ),
    'fresh scoped convergence rejects selected-map drift against ordinal one before selected content can pass'
);
$check(
    str_contains($ledgerFinalizerSource, "Db::start_repeatable_read('scoped ledger transaction start')")
        && str_contains($ledgerFinalizerSource, 'DeleteGuardEvaluator::assert_innodb_tables([')
        && $finalizerMapLockAt !== false
        && $finalizerForgetAt !== false
        && $finalizerMapReadbackAt !== false
        && $finalizerCompleteAt !== false
        && $finalizerMapLockAt < $finalizerForgetAt
        && $finalizerForgetAt < $finalizerMapReadbackAt
        && $finalizerMapReadbackAt < $finalizerCompleteAt
        && str_contains($ledgerFinalizerSource, '$authorizedMapDeletes[$uuid] = true;')
        && str_contains($ledgerFinalizerSource, '$expectedTerminalMap = array_values(array_filter(')
        && str_contains($ledgerFinalizerSource, 'private const MAX_LOCKED_MAP_ROWS = 100000;')
        && str_contains($ledgerFinalizerSource, '$limit = self::MAX_LOCKED_MAP_ROWS + 1;')
        && str_contains($ledgerFinalizerSource, 'count($rows) > self::MAX_LOCKED_MAP_ROWS')
        && str_contains($ledgerFinalizerSource, 'scoped ledger map inventory exceeds the bounded row frontier')
        && str_contains($ledgerFinalizerSource, 'FORCE INDEX (PRIMARY) ORDER BY uuid ASC, id_kind ASC LIMIT $limit FOR UPDATE')
        && substr_count($ledgerFinalizerSource, "assert_transaction_isolation('scoped ledger map inventory") === 2,
    'terminalization range-locks the complete selected map and permits only explicit tombstone cleanup before sealing roots'
);
$check(
    str_contains($repoFormatSource, 'admits at most 100,000 physical map rows')
        && str_contains($repoFormatSource, 'requests one proof row beyond the')
        && str_contains($repoFormatSource, 'refuses before ledger mutation'),
    'the scoped terminal protocol documents its exact 100k full-map refusal frontier'
);
$check(
    $authoredEngineBoundaryAt !== false
        && $termParticipantAt !== false
        && $authoredEngineBoundaryAt < $termParticipantAt
        && $authoredEngineBoundaryAt < $atomicParticipantAt
        && str_contains($authoredExecutorSource, '$this->snapshotRowTables as $name => $declaration')
        && str_contains($authoredExecutorSource, '->attached_meta_table_for_owner((string) $name)')
        && str_contains($authoredExecutorSource, '$wpdb->prefix . \'duo_map\'')
        && str_contains($authoredExecutorSource, '$wpdb->prefix . \'duo_state\'')
        && str_contains($authoredExecutorSource, '$wpdb->prefix . \'duo_kv\'')
        && str_contains($authoredExecutorSource, '$declaration[\'invalidate\'] ?? []'),
    'authored apply metadata-locks and proves every effective core/ledger/typed/sidecar/invalidation table before DML or session CAS'
);
$check(
    substr_count($applySource, '->recheck_target_observation(') === 2
        && substr_count($preparationSource, '->recheck_target_observation(') === 1
        && str_contains($scopedWorkflowSource, "recover_once('duo:scoped-target-observation-failed')"),
    'every normal scoped target observation failure re-gates the retained phase before surfacing drift'
);

if ($failures !== 0) {
    fwrite(STDERR, "$failures scoped apply recovery assertion(s) failed\n");
    exit(1);
}
echo "ALL PASSED\n";

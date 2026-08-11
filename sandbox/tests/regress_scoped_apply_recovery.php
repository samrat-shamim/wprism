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

$root = dirname(__DIR__, 2);
if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

foreach ([
    'Canon', 'Uuid', 'OrderPreserved', 'OptionState', 'UserMetaState', 'Db',
    'TransientDbException', 'PlainData', 'StructuredValue', 'Secrets',
    'PersonalData', 'AdapterSources', 'ManifestDispositions',
    'CapabilityRegistry', 'NativeActions', 'ReferenceRules', 'Policy',
    'ReferenceGraph', 'CodeCompatibility', 'RepositoryCompiler',
    'ScopeClosure', 'CanonicalSurfaces', 'Deletion', 'SidebarState', 'Snapshot',
    'RepositoryAuthorization', 'Tokens', 'ScopeContract',
    'ScopedStateOverlay', 'ScopedApplySession', 'CommandRefusal', 'ScopedApply', 'Providers',
    'Canary', 'Ledger', 'PromotionLock', 'Apply',
] as $file) {
    require_once "$root/agent/src/$file.php";
}

use Duo\Canon;
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
        if (str_contains($query, "option_name LIKE 'widget")) {
            $rows = [];
            foreach ($this->optionRows as $name => $value) {
                if (str_starts_with($name, 'widget_')) {
                    $rows[] = ['option_name' => $name, 'option_value' => $value];
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

$applyReflection = new ReflectionClass(\Duo\Apply::class);
$applyForReadback = $applyReflection->newInstanceWithoutConstructor();
$policyProperty = $applyReflection->getProperty('policy');
$policyProperty->setValue($applyForReadback, $policy);
$coreReadbackMethod = $applyReflection->getMethod('scoped_core_readback_hash');
$GLOBALS['wpdb']->failResults = true;
try {
    $coreReadbackMethod->invoke($applyForReadback, [], []);
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

// Simulate process/response loss after the DB transaction committed. The
// durable session is still authoring and has an intent but no receipt; the
// desired readback upgrades that exact intent without a second write.
$reopenedAfterCommit = ScopedApplySession::open($sessionStore);
$check($reopenedAfterCommit !== null && $reopenedAfterCommit->phase() === ScopedApplySession::PHASE_AUTHORING,
    'response loss leaves the durable authored intent at the authoring phase');
if ($reopenedAfterCommit !== null) {
    $reopenedState = ScopedApply::authored_state(
        $actualDesired,
        $compiled,
        $policy,
        $contract,
        $selectedBeforeRoot
    );
    if ($reopenedState === 'desired') {
        $reopenedAfterCommit->transition(ScopedApplySession::PHASE_AUTHORED_COMMITTED);
        $reopenedAfterCommit->append_receipt($authorIntent + ['after_hash' => $desiredHash]);
    }
}
$check(
    $authorCommits === 1
        && $reopenedAfterCommit !== null
        && $reopenedAfterCommit->phase() === ScopedApplySession::PHASE_AUTHORED_COMMITTED
        && count($reopenedAfterCommit->receipts()) === 1,
    'desired readback after a lost response reconciles the same operation without replay'
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
$phaseApply = $applyReflection->newInstanceWithoutConstructor();
$applyReflection->getProperty('scopeContract')->setValue($phaseApply, $phaseContract);
$applyReflection->getProperty('scopedSession')->setValue($phaseApply, $phaseSession);
$phaseAllowance = $applyReflection->getMethod('scoped_allows_target_old_menu_items');
$phaseAllowsBefore = $phaseAllowance->invoke($phaseApply, $phaseBeforeActual);
$phaseDesiredState = ScopedApply::authored_state(
    $authoringDesiredActual,
    $phaseCompiled,
    $nestedPolicy,
    $phaseContract,
    $nestedBeforeRoot
);
$phaseAllowsDesired = $phaseAllowance->invoke($phaseApply, $authoringDesiredActual);
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
    'active_plugins', 'blogdescription', 'blogname', 'default_category', 'page_for_posts',
    'page_on_front', 'posts_per_page', 'show_on_front', 'sticky_posts', 'stylesheet',
    'template', 'wp_page_for_privacy_policy',
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

// The full public Apply path needs WordPress and a promotion lease, so pin the
// four recovery/isolation threading edges in its bounded run()/rebuild()
// source. The protocol seams they call are exercised dynamically above.
$applySource = (string) file_get_contents($root . '/agent/src/Apply.php');
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
        && str_contains(
            $applySource,
            '$sessionId = PromotionLock::scoped_session_id($this->promotionOwner, $this->promotionArtifact);'
        ),
    'scoped continuations refuse legacy generations before lease renewal and authority seals only random generations'
);
$deleteGateAt = strpos($applySource, 'scoped apply selected live tombstones but --with-deletes was not supplied');
$terminalArchiveAt = strpos($applySource, '$this->terminalScopedSessionToArchive->archive_terminal();');
$archivedReplayLookupAt = strpos($applySource, 'ScopedApplySession::open_terminal_for_request(');
$sessionBeginAt = strpos($applySource, 'ScopedApplySession::begin(');
$promotionSessionBeginAt = strpos($applySource, 'PromotionLock::begin_apply_session(');
$check(
    $deleteGateAt !== false && $sessionBeginAt !== false && $deleteGateAt < $sessionBeginAt,
    'scoped tombstones without --with-deletes refuse before a session can authorize target mutation'
);
$check(
    $promotionSessionBeginAt !== false
        && $sessionBeginAt !== false
        && $promotionSessionBeginAt < $sessionBeginAt
        && str_contains(
            (string) file_get_contents($root . '/agent/src/PromotionLock.php'),
            "'session_id' => 'ps-' . bin2hex(random_bytes(16))"
        ),
    'direct scoped apply publishes a random promotion generation only at the sealed-authority boundary'
);
$check(
    substr_count($applySource, 'CommandRefusalException::applyRefused(') === 3
        && str_contains(
            $applySource,
            'scoped apply refused because its scope evidence is stale or invalid for the current source artifact'
        )
        && str_contains(
            $applySource,
            'terminal scoped receipt no longer describes the live bounded target; no mutation or replay attempted'
        )
        && str_contains(
            $applySource,
            'scoped apply selected live tombstones but --with-deletes was not supplied; '
        ),
    'known stale-scope, stale-terminal, and missing-delete scoped gates retain typed public refusals'
);
$check(
    $terminalArchiveAt !== false
        && $deleteGateAt !== false
        && $sessionBeginAt !== false
        && $deleteGateAt < $terminalArchiveAt
        && $terminalArchiveAt < $sessionBeginAt,
    'a different scoped request preserves prior terminal evidence until every new-operation gate passes'
);
$check(
    $archivedReplayLookupAt !== false && $archivedReplayLookupAt < $terminalArchiveAt,
    'public apply resolves an exact archived scope/source terminal before rotating or minting a new authority'
);
$check(
    substr_count($applySource, 'NativeActions::reconcile_scoped(') === 2
        && substr_count($applySource, 'Providers::reconcile_scoped(') === 2
        && substr_count($applySource, 'ScopedApplySession::require_reviewed_effect_receipt(') === 2,
    'both no-receipt and existing-receipt native/provider paths reconcile before trusting completion'
);
$check(
    str_contains($applySource, '$durableReparents = $scoped ? []')
        && str_contains($applySource, '$durableDeletions = $scoped ? []')
        && str_contains($applySource, 'if (!$scoped) {' . "\n" . '            $this->regen_dependencies(')
        && str_contains($applySource, 'Ledger::kv_prefix(self::REGEN_PENDING_PREFIX) : []'),
    'scoped rebuild neither consumes nor sweeps the generic regen retry/context keyspaces'
);
$check(
    str_contains($applySource, "foreach (['deletions', 'reparents'] as \$channel)")
        && str_contains($applySource, "provider channel '\$channel'")
        && str_contains($applySource, 'durable environment-local recovery input'),
    'scoped preflight refuses provider context channels whose local-id payload cannot be reconstructed after a crash'
);
$codeWitnessCheckAt = strpos($applySource, "'duo:scoped-code-witness-changed'");
$sessionBeginAt = strpos($applySource, 'ScopedApplySession::begin(');
$check(
    substr_count($applySource, 'ScopedApply::code_witness_hash(') === 2
        && $codeWitnessCheckAt !== false
        && $sessionBeginAt !== false
        && $codeWitnessCheckAt < $sessionBeginAt,
    'recovery re-proves the sealed code/lifecycle witness before opening or advancing target mutation state'
);

if ($failures !== 0) {
    fwrite(STDERR, "$failures scoped apply recovery assertion(s) failed\n");
    exit(1);
}
echo "ALL PASSED\n";

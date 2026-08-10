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

foreach ([
    'Canon', 'Uuid', 'OrderPreserved', 'OptionState', 'UserMetaState', 'Db',
    'TransientDbException', 'PlainData', 'StructuredValue', 'Secrets',
    'PersonalData', 'AdapterSources', 'ManifestDispositions',
    'CapabilityRegistry', 'NativeActions', 'ReferenceRules', 'Policy',
    'ReferenceGraph', 'CodeCompatibility', 'RepositoryCompiler',
    'ScopeClosure', 'CanonicalSurfaces', 'Deletion', 'SidebarState', 'Snapshot',
    'RepositoryAuthorization', 'Tokens', 'ScopeContract',
    'ScopedStateOverlay', 'ScopedApplySession', 'ScopedApply', 'Providers',
    'Canary', 'Ledger', 'Apply',
] as $file) {
    require_once "$root/agent/src/$file.php";
}

use Duo\Canon;
use Duo\CompiledRepository;
use Duo\NativeActions;
use Duo\Policy;
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
            && !str_starts_with($key, ScopedApplySession::TERMINAL_KEY_PREFIX)) {
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
    public string $prefix = 'wp_';
    public string $last_error = '';
    /** @var array<string,string> */
    public array $optionRows = [];
    /** @var array<string,string> */
    public array $kvRows = [];

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
        return null;
    }

    public function query(string $query): int {
        $this->last_error = '';
        return 0;
    }

    public function get_results(string $query, mixed $output = null): array {
        $this->last_error = '';
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

// Complete the original authored session after its effect phase and prove the
// terminal receipt is the exact replay payload a lost CLI response can return.
$reopenedAfterCommit->transition(ScopedApplySession::PHASE_EFFECTS_PENDING);
$reopenedAfterCommit->transition(ScopedApplySession::PHASE_VERIFYING);
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

if ($failures !== 0) {
    fwrite(STDERR, "$failures scoped apply recovery assertion(s) failed\n");
    exit(1);
}
echo "ALL PASSED\n";

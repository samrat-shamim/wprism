<?php
declare(strict_types=1);

/** Structural regression for Capture's stable façade and collaborator split. */
$root = dirname(__DIR__, 4);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        fwrite(STDOUT, "ok: $message\n");
        return;
    }
    $failures[] = $message;
    fwrite(STDERR, "FAIL: $message\n");
};
$source = static function (string $name) use ($root): string {
    // Since the module move (ROUND 3 TRAIN 1) the engine tree is not flat; the
    // classmap beside it is what still knows where a file lives. Indexed by
    // the mapped path's BASENAME, not by class name: this closure is handed a
    // file name, and agent/src/Kernel/CommandRefusal.php declares
    // WPrism\CommandRefusalException, so a class lookup would miss it. A name the
    // map does not carry leaves $bytes false, which the throw below names.
    static $wprismAgentFiles = null;
    if ($wprismAgentFiles === null) {
        $wprismAgentFiles = [];
        foreach ((array) (require "$root/agent/wprism-classmap.php") as $wprismAgentPath) {
            $wprismAgentFiles[basename((string) $wprismAgentPath, '.php')] = (string) $wprismAgentPath;
        }
    }
    $bytes = isset($wprismAgentFiles[$name]) ? file_get_contents("$root/agent/" . $wprismAgentFiles[$name]) : false;
    if (!is_string($bytes)) {
        throw new RuntimeException("cannot read $name.php");
    }
    return $bytes;
};

$capture = $source('Capture');
$candidate = $source('CaptureCandidateBuilder');
$snapshot = $source('CaptureSnapshotService');
$workflow = $source('CapturePublicationWorkflow');
$recovery = $source('CapturePublicationRecovery');
$initial = $source('InitialCaptureBoundary');
$scoped = $source('ScopedCaptureProjector');
$identity = $source('CaptureIdentity');
$blocks = $source('Blocks');
$post = $source('PostCapture');
$term = $source('TermCapture');
$captureCode = '';
foreach (token_get_all($capture) as $token) {
    if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
        continue;
    }
    $captureCode .= is_array($token) ? $token[1] : $token;
}
$buildStart = strpos($candidate, 'public function build(');
$buildEnd = strpos($candidate, 'private function reset(', (int) $buildStart);
$candidateBuild = substr($candidate, (int) $buildStart, (int) $buildEnd - (int) $buildStart);
$ordinarySnapshotStart = strpos($snapshot, 'public static function snapshot(');
$ordinarySnapshotEnd = strpos($snapshot, "\n    /**", (int) $ordinarySnapshotStart + 1);
$ordinarySnapshot = substr(
    $snapshot,
    (int) $ordinarySnapshotStart,
    (int) $ordinarySnapshotEnd - (int) $ordinarySnapshotStart
);
$prePruneGuard = strpos($ordinarySnapshot, 'CanonicalLedgerMapGuard::assert_pre_prune(');
$ledgerPrune = strpos($ordinarySnapshot, 'Ledger::prune_dead_map()');
$sidebarPrune = strpos($ordinarySnapshot, 'SidebarState::prune_dead_map($policy)');
$typedPrune = strpos($ordinarySnapshot, 'Snapshot::prune_dead_map($policy, $repositoryOptions)');

require_once "$root/agent/src/Capture/Capture.php";
$captureReflection = new ReflectionClass(WPrism\Capture::class);

$check(substr_count($capture, "\n") + 1 <= 650, 'Capture stays a small command-facing façade');
$check(!str_contains($capture, '$this->'), 'Capture has no per-build mutable instance state');
$check(!str_contains($captureCode, 'Db::') && !str_contains($captureCode, '$wpdb'),
    'Capture owns neither database discovery nor transaction implementation');
$check(!str_contains($capture, 'Publish::begin_intent(') && !str_contains($capture, 'Publish::swap('),
    'Capture owns no publication implementation');
$check(
    $ordinarySnapshotStart !== false
        && $ordinarySnapshotEnd !== false
        && $prePruneGuard !== false
        && $ledgerPrune !== false
        && $sidebarPrune !== false
        && $typedPrune !== false
        && $prePruneGuard < $ledgerPrune
        && $prePruneGuard < $sidebarPrune
        && $prePruneGuard < $typedPrune,
    'ordinary plan/apply proves retained canonical maps before every dead-map pruner can erase recovery evidence'
);

$check(!$captureReflection->isInstantiable(), 'Capture preserves its historical non-instantiable boundary');
$constructor = $captureReflection->getConstructor();
$check(
    $constructor !== null
        && $constructor->isPrivate()
        && array_map(
            static fn(ReflectionParameter $parameter): array => [
                'name' => $parameter->getName(),
                'type' => $parameter->hasType() ? (string) $parameter->getType() : null,
            ],
            $constructor->getParameters()
        ) === [
            ['name' => 'repo', 'type' => 'string'],
            ['name' => 'policy', 'type' => 'WPrism\\Policy'],
        ],
    'Capture preserves its private constructor contract'
);

$methodContract = static function (ReflectionMethod $method): array {
    return [
        'public' => $method->isPublic(),
        'static' => $method->isStatic(),
        'return' => $method->hasReturnType() ? (string) $method->getReturnType() : null,
        'parameters' => array_map(static function (ReflectionParameter $parameter): array {
            return [
                'name' => $parameter->getName(),
                'type' => $parameter->hasType() ? (string) $parameter->getType() : null,
                'by_reference' => $parameter->isPassedByReference(),
                'variadic' => $parameter->isVariadic(),
                'required' => !$parameter->isDefaultValueAvailable(),
                'default' => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
            ];
        }, $method->getParameters()),
    ];
};
$parameter = static fn(
    string $name,
    ?string $type,
    bool $required = true,
    mixed $default = null,
    bool $byReference = false
): array => [
    'name' => $name,
    'type' => $type,
    'by_reference' => $byReference,
    'variadic' => false,
    'required' => $required,
    'default' => $default,
];
$expectedApi = [
    'run' => ['array', [
        $parameter('repo', 'string'),
        $parameter('outDir', '?string', false),
        $parameter('forceUnresolvedRefs', 'bool', false, false),
        $parameter('scopeRequest', '?array', false),
        $parameter('hostEnvironment', '?string', false),
        $parameter('adapterLibrary', '?WPrism\\AdapterLibrary', false),
        $parameter('expectedRepositoryBranch', '?string', false),
    ]],
    'run_initial_baseline' => ['array', [
        $parameter('repo', 'string'),
        $parameter('publicationLock', null),
        $parameter('initialStateIdentity', 'string'),
        $parameter('initialMediaIdentity', 'string'),
        $parameter('initialConfigIdentity', 'string'),
        $parameter('onPayloadReady', '?callable', false),
    ]],
    'snapshot' => ['array', [
        $parameter('repo', 'string'),
        $parameter('forceUnresolvedRefs', 'bool', false, false),
        $parameter('compiled', '?WPrism\\CompiledRepository', false),
        $parameter('policy', '?WPrism\\Policy', false),
        $parameter('planObservations', '?array', false, null, true),
        // Observe AS a named environment binding — the rehearsal target rebind off its restored source snapshot (grind_adoption A6).
        $parameter('binding', '?array', false),
        // First-apply comparison accepts a natural-key witness, never publication authority; all strict/export signatures remain unchanged.
        $parameter('unmappedTermObserver', '?Closure', false),
    ]],
    'snapshot_read_only' => ['array', [
        $parameter('repo', 'string'),
        $parameter('forceUnresolvedRefs', 'bool', false, false),
        $parameter('compiled', '?WPrism\\CompiledRepository', false),
        $parameter('policy', '?WPrism\\Policy', false),
    ]],
    // Apply borrows capture under its native consumer locks; the required
    // authority keeps this separate from a snapshot that starts a transaction.
    'block_inputs_in_transaction' => ['array', [
        $parameter('repo', 'string'),
        $parameter('policy', 'WPrism\\Policy'),
        $parameter('compiled', 'WPrism\\CompiledRepository'),
        $parameter('workAuthority', 'WPrism\\DatabaseWorkAuthority'),
        $parameter('blockNames', 'array'),
    ]],
    'build_read_only_export' => ['array', [
        $parameter('repo', 'string'),
        $parameter('policy', 'WPrism\\Policy'),
        $parameter('previousOptions', '?array'),
        $parameter('previousUserLogins', 'array'),
        $parameter('forceUnresolvedRefs', 'bool', false, false),
    ]],
    'assert_read_only_export_engine_support' => ['void', [
        $parameter('policy', 'WPrism\\Policy'),
    ]],
    'snapshot_options_core' => ['array', [
        $parameter('repo', 'string'),
        $parameter('forceUnresolvedRefs', 'bool', false, false),
        $parameter('compiled', '?WPrism\\CompiledRepository', false),
        $parameter('policy', '?WPrism\\Policy', false),
    ]],
    'gate_scan' => ['array', [$parameter('repo', 'string')]],
    'gate_scan_read_only' => ['array', [
        $parameter('repo', 'string'),
        $parameter('policy', 'WPrism\\Policy'),
        $parameter('observationReadCheckpoint', '?callable', false),
    ]],
    'publication_commit_status' => ['bool', [
        $parameter('stateDir', 'string'),
        $parameter('intent', 'array'),
    ]],
    'ref_target_type' => ['?string', [
        $parameter('id', 'int'),
        $parameter('kind', 'string'),
    ]],
    'classify_unscoped_ref' => ['?string', [
        $parameter('id', 'int'),
        $parameter('kind', 'string'),
        $parameter('force', 'bool'),
        $parameter('policy', 'WPrism\\Policy'),
    ]],
];
$actualApi = [];
foreach ($captureReflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
    if ($method->getDeclaringClass()->getName() === WPrism\Capture::class) {
        $actualApi[$method->getName()] = $methodContract($method);
    }
}
$normalizedExpectedApi = array_map(
    static fn(array $contract): array => [
        'public' => true,
        'static' => true,
        'return' => $contract[0],
        'parameters' => $contract[1],
    ],
    $expectedApi
);
$check(array_keys($actualApi) === array_keys($normalizedExpectedApi),
    'Capture preserves the declared public method set and declaration order');
$check($actualApi === $normalizedExpectedApi,
    'Capture preserves visibility, staticness, return types, parameters, defaults, and references');

$branchRepo = sys_get_temp_dir() . '/wprism-capture-branch-' . bin2hex(random_bytes(8));
mkdir($branchRepo, 0700, true);
$branchProcess = proc_open(
    ['git', 'init', '--initial-branch=feature/target', $branchRepo],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $branchPipes
);
if (is_resource($branchProcess)) {
    stream_get_contents($branchPipes[1]);
    stream_get_contents($branchPipes[2]);
    fclose($branchPipes[1]);
    fclose($branchPipes[2]);
    proc_close($branchProcess);
}
$branchGuard = new ReflectionMethod(WPrism\CapturePublicationWorkflow::class, 'assertRepositoryBranch');
$branchMatched = true;
try {
    $branchGuard->invoke(null, $branchRepo, 'feature/target');
} catch (Throwable) {
    $branchMatched = false;
}
$branchMismatchRefused = false;
try {
    $branchGuard->invoke(null, $branchRepo, 'feature/other');
} catch (Throwable $failure) {
    $branchMismatchRefused = str_contains($failure->getMessage(), 'target branch does not match');
}
$check($branchMatched && $branchMismatchRefused,
    'the target product path accepts only the exact named branch binding before capture publication');
$removeBranchFixture = static function (string $path) use (&$removeBranchFixture): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    foreach ((array) @scandir($path) as $entry) {
        if ($entry !== '.' && $entry !== '..') $removeBranchFixture($path . '/' . $entry);
    }
    @rmdir($path);
};
$removeBranchFixture($branchRepo);

foreach ([
    'CapturePublicationWorkflow::run(' => 'publication workflow',
    'CaptureSnapshotService::snapshot(' => 'ordinary snapshot service',
    'CaptureSnapshotService::snapshotReadOnly(' => 'strict snapshot service',
    'CaptureSnapshotService::blockInputsInTransaction(' => 'caller-owned block input observation service',
    'CaptureSnapshotService::buildReadOnlyExport(' => 'read-only export service',
    'CaptureSnapshotService::snapshotOptionsCore(' => 'lifecycle options service',
    'CaptureGateScanner(' => 'pending gate scanner',
    'CapturePublicationRecovery::commitStatus(' => 'publication recovery proof',
    'ReferenceScopeClassifier::targetType(' => 'reference target classifier',
    'ReferenceScopeClassifier::classify(' => 'reference scope classifier',
] as $needle => $label) {
    $check(str_contains($capture, $needle), "Capture delegates to the $label");
}

$order = [];
foreach ([
    '$this->scopeDiscovery->discover(' => 'scope',
    '$this->captureIdentity->ensurePost(' => 'post identity',
    '$this->captureIdentity->ensureTerm(' => 'term identity',
    '$this->menuCapture->capture(' => 'menu identity/capture',
    '$this->termCapture->capture(' => 'term capture',
    '$this->postCapture->capture(' => 'post capture',
    '$this->buildOptions(' => 'options capture',
    '$this->safetyGates->assertContentReferences($this->tokens);' => 'final safety gates',
] as $needle => $label) {
    $offset = strpos($candidateBuild, $needle);
    $check($offset !== false, "candidate builder owns $label");
    $order[] = (int) $offset;
}
$check($order === array_values($order) && $order === array_unique($order)
    && $order === (function (array $positions): array { sort($positions);
    return $positions; })($order),
    'candidate construction preserves its identity/entity/gate ordering');

$widgetReferenceScan = strpos($candidateBuild, '$this->portableWidgetReferenceScan(');
$widgetIdentityGuard = strpos($candidateBuild, '$postUuid = $postUuids[$postId] ?? null;');
$widgetBodyGuard = strpos($candidateBuild, '$this->policy->body_mode((string) $post->post_type) !== \'blocks\'');
$sidebarCapture = strpos($candidateBuild, 'SidebarState::capture(');
$postCapture = strpos($candidateBuild, '$this->postCapture->capture(');
$check(
    $widgetReferenceScan !== false
        && $widgetIdentityGuard !== false
        && $widgetBodyGuard !== false
        && $sidebarCapture !== false
        && $postCapture !== false
        && $widgetReferenceScan < $sidebarCapture
        && $sidebarCapture < $postCapture
        && str_contains($candidateBuild, 'Blocks::capture_widget_instance_references('),
    'whole-block widget references are discovered only from selected mapped block posts before SidebarState identity capture'
);
$publication = file_get_contents($root . '/agent/src/Capture/CapturePublicationWorkflow.php');
$check(
    is_string($publication)
        && str_contains($candidateBuild, '($selected !== null && !isset($selected[$postUuid]))')
        && str_contains($publication, '$scoped ? ScopedStateOverlay::selected_identities($scopeContract) : null'),
    'scoped widget pre-scan uses the associated contract selected-identity projection rather than all discovered posts'
);
$check(
    !str_contains($candidateBuild, "capture_widget_instance_references('',")
        && str_contains($candidateBuild, '$referencesByKey = [];')
        && str_contains($candidateBuild, '$portableWidgetReferences === [] ? null : $portableWidgetReferences'),
    'candidate construction never primes an empty overlay and passes no SidebarState pseudo-entity authority when selected posts contain no stored-widget references'
);
$check(
    str_contains($blocks, '$details = $policy->block_attr_rule_details(\'core/legacy-widget\');')
        && str_contains($blocks, '$policy->widget_type_rule_details((string) $type)')
        && str_contains($blocks, 'stored legacy widget reference belongs to a different manifest owner'),
    'the pre-SidebarState product path requires effective block and widget grammar declarations to have one manifest owner'
);

$check(str_contains($identity, 'public function ensurePost(')
    && str_contains($identity, 'public function ensureTerm(')
    && str_contains($identity, 'Ledger::require_read_only_mapping('),
    'identity collaborator owns minting plus strict durable-map verification');

// Exercise the real collaborator against the shared row-backed wpdb. The
// default WordPress metadata collation is case-insensitive, so an alias must
// be observed in the same equality set and refused rather than ignored/minted
// over; a failed compact read likewise cannot become "identity absent".
require_once "$root/sandbox/tests/lib/wp_stubs.php";
require_once "$root/sandbox/tests/lib/FakeWpdb.php";
$identityDb = static function (array $postmeta): \WPrismTest\FakeWpdb {
    $db = new \WPrismTest\FakeWpdb();
    $db->seedTable('wp_postmeta', $postmeta)
        ->setTableEngine('wp_postmeta', 'InnoDB');
    $db->seedTable('wp_wprism_map', [])
        ->setTableEngine('wp_wprism_map', 'InnoDB')
        ->setUniqueKey('wp_wprism_map', ['uuid', 'id_kind'])
        ->setUniqueKey('wp_wprism_map', ['id_kind', 'local_id']);
    return $db->enableInformationSchema();
};
$canonicalUuid = '11111111-1111-7111-8111-111111111111';
$GLOBALS['wpdb'] = $identityDb([[
    'meta_id' => 1,
    'post_id' => 7,
    'meta_key' => '_wprism_uuid',
    'meta_value' => $canonicalUuid,
]]);
$runtimeIdentity = new \WPrism\CaptureIdentity(static function (string $_purpose): void {});
$check(
    $runtimeIdentity->ensurePost(7, 'post', false) === $canonicalUuid,
    'capture identity accepts one canonical exact sidecar through the real ledger product path'
);

$durableMap = $GLOBALS['wpdb']->rows('wp_wprism_map');
$canonicalMeta = $GLOBALS['wpdb']->rows('wp_postmeta');
$contradictoryMap = $durableMap;
$contradictoryMap[0]['entity_type'] = 'term:category';
foreach ([
    'absent' => [[], [], null, null],
    'durable' => [$canonicalMeta, $durableMap, $canonicalUuid, null],
    'missing map' => [$canonicalMeta, [], null, 'durable identity is missing'],
    'contradictory map' => [$canonicalMeta, $contradictoryMap, null, 'durable identity contradicts'],
    'alias' => [[array_replace($canonicalMeta[0], ['meta_key' => '_WPRISM_UUID'])], [], null, 'aliased'],
    'duplicate' => [[...$canonicalMeta, array_replace($canonicalMeta[0], ['meta_id' => 2])], [], null, 'ambiguous collation-equal'],
] as $name => [$metadata, $mapping, $expectedUuid, $expectedFailure]) {
    $GLOBALS['wpdb'] = $identityDb($metadata)->seedTable('wp_wprism_map', $mapping)->resetLog();
    $observedUuid = null;
    $observationFailure = null;
    try {
        $observedUuid = $runtimeIdentity->observePost(7, 'post');
    } catch (Throwable $failure) {
        $observationFailure = $failure->getMessage();
    }
    $check($expectedFailure === null
        ? $observationFailure === null && $observedUuid === $expectedUuid
        : $observationFailure !== null && str_contains($observationFailure, $expectedFailure),
        "optional identity observation preserves the $name identity contract");
    $check($GLOBALS['wpdb']->rows('wp_postmeta') === $metadata
        && $GLOBALS['wpdb']->rows('wp_wprism_map') === $mapping
        && array_filter($GLOBALS['wpdb']->queries(), static fn(string $sql): bool =>
            preg_match('/^\\s*(?:INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE)\\b/i', $sql) === 1) === [],
        "optional $name observation neither mints nor repairs identity");
}

$GLOBALS['wpdb'] = $identityDb([[
    'meta_id' => 1,
    'post_id' => 7,
    'meta_key' => '_WPRISM_UUID',
    'meta_value' => $canonicalUuid,
]]);
try {
    $runtimeIdentity->ensurePost(7, 'post', true);
    $aliasRefused = false;
} catch (Throwable $failure) {
    $aliasRefused = str_contains($failure->getMessage(), 'aliased');
}
$check(
    $aliasRefused && $GLOBALS['wpdb']->rows('wp_wprism_map') === [],
    'capture identity refuses a collation-equal non-byte-exact alias before minting or ledger mutation'
);

$GLOBALS['wpdb'] = $identityDb([
    ['meta_id' => 1, 'post_id' => 7, 'meta_key' => '_wprism_uuid', 'meta_value' => $canonicalUuid],
    ['meta_id' => 2, 'post_id' => 7, 'meta_key' => '_WPRISM_UUID', 'meta_value' => $canonicalUuid],
]);
try {
    $runtimeIdentity->ensurePost(7, 'post', true);
    $duplicateRefused = false;
} catch (Throwable $failure) {
    $duplicateRefused = str_contains($failure->getMessage(), 'ambiguous collation-equal');
}
$check($duplicateRefused, 'capture identity refuses an exact-plus-alias equality set instead of choosing one');

$secretShapedIdentity = 'credential=' . str_repeat('X', 80);
$GLOBALS['wpdb'] = $identityDb([[
    'meta_id' => 1,
    'post_id' => 7,
    'meta_key' => '_wprism_uuid',
    'meta_value' => $secretShapedIdentity,
]]);
try {
    $runtimeIdentity->ensurePost(7, 'post', true);
    $malformedIdentityMessage = '';
} catch (Throwable $failure) {
    $malformedIdentityMessage = $failure->getMessage();
}
$check(
    str_contains($malformedIdentityMessage, 'malformed')
        && !str_contains($malformedIdentityMessage, 'credential='),
    'capture identity rejects malformed/oversized bytes without leaking their value'
);

$GLOBALS['wpdb'] = $identityDb([]);
$GLOBALS['wpdb']->failNextQuery('simulated identity read failure', 'LEFT(meta_value, 37)');
try {
    $runtimeIdentity->ensurePost(7, 'post', true);
    $identityReadFailureRefused = false;
} catch (Throwable $failure) {
    $identityReadFailureRefused = str_contains($failure->getMessage(), 'identity read')
        && $GLOBALS['wpdb']->rows('wp_postmeta') === []
        && $GLOBALS['wpdb']->rows('wp_wprism_map') === [];
}
$check($identityReadFailureRefused, 'capture identity DB failure cannot become an absent row and mint new authority');
$check(str_contains($post, '$this->mediaCapture->capture(')
    && str_contains($post, '$this->entityMetaCapture->classifyValue('),
    'post collaborator owns post/meta/media projection');
$check(str_contains($term, '$this->entityMetaCapture->termMetaByKey(')
    && str_contains($term, "'relationships'"),
    'term collaborator owns term/meta/relationship projection');

$check(str_contains($snapshot, 'Ledger::assert_read_only_schema()')
    && !str_contains($snapshot, 'Snapshot::repair_truncated_entity_types(')
    && str_contains($snapshot, '$capture->build('),
    'snapshot service keeps strict reads separate from maintenance-aware observation');
$transactionStart = strpos($workflow, '$build = self::runInConsistentSnapshot(');
$transactionEnd = strpos($workflow, '}, $publicationPhase, $policy);', (int) $transactionStart);
$transactionProtocol = substr(
    $workflow,
    (int) $transactionStart,
    (int) $transactionEnd - (int) $transactionStart
);
$protocolOffsets = [];
foreach ([
    "\$publicationPhase['publication_started'] = true;" => 'publication-start boundary',
    'Publish::begin_intent(' => 'durable intent',
    'Publish::mark_swapped(' => 'swapped intent',
    'Ledger::set_state_hash(' => 'ledger finalization',
    'Publish::mark_commit_ready(' => 'commit-ready intent',
    'Ledger::kv_set(' => 'database commit marker',
    'return $candidate;' => 'transaction return',
] as $needle => $label) {
    $offset = strpos($transactionProtocol, $needle);
    $check($offset !== false, "publication workflow owns $label");
    $protocolOffsets[] = $offset;
}
$check(
    !in_array(false, $protocolOffsets, true)
        && $protocolOffsets === (function (array $positions): array { sort($positions);
        return $positions; })($protocolOffsets),
    'publication protocol preserves intent, swap, ledger, commit-marker, and transaction-return ordering'
);
$beginIntentOffset = strpos($transactionProtocol, 'Publish::begin_intent(');
$markSwappedOffset = strpos($transactionProtocol, 'Publish::mark_swapped(');
foreach ([
    'Publish::swap_initial(' => 'initial filesystem swap',
    'Publish::swap($stateDir, true);' => 'ordinary filesystem swap',
] as $needle => $label) {
    $swapOffset = strpos($transactionProtocol, $needle);
    $check(
        $beginIntentOffset !== false
            && $swapOffset !== false
            && $markSwappedOffset !== false
            && $beginIntentOffset < $swapOffset
            && $swapOffset < $markSwappedOffset,
        "$label remains between durable intent and the swapped marker"
    );
}
$receiptOffset = strpos($workflow, 'Publish::write_receipt(', (int) $transactionEnd);
$cleanupInitialOffset = strpos($workflow, 'Publish::cleanup_committed_initial(', (int) $receiptOffset);
$cleanupOffset = strpos($workflow, 'Publish::cleanup_committed(', (int) $receiptOffset);
$check(
    $transactionEnd !== false
        && $receiptOffset !== false
        && $cleanupInitialOffset !== false
        && $cleanupOffset !== false
        && $transactionEnd < $receiptOffset
        && $receiptOffset < $cleanupInitialOffset
        && $receiptOffset < $cleanupOffset,
    'receipt and cleanup remain strictly post-transaction'
);
$check(str_contains($recovery, "'wprism-capture-commit-marker/v1'")
    && str_contains($recovery, 'CommandRefusalException::ambiguousCaptureRecovery('),
    'publication recovery owns exact self-hashed commit proof and ambiguity refusal');
$check(str_contains($initial, "require_once __DIR__ . '/../Init/InitProtocol.php';")
    && str_contains($initial, 'InitProtocol::ATTEMPT_FILE')
    && str_contains($initial, 'InitProtocol::ATTEMPT_NEXT_FILE')
    && str_contains($initial, 'assertProtocolBoundaries(')
    && str_contains($initial, 'assertStateReservation('),
    'initial boundary owns interrupted-init and first-publication ownership checks');
$check(str_contains($scoped, 'ScopeContract::assert_associated(')
    && str_contains($scoped, 'ScopedStateOverlay::resolve_request('),
    'scoped projector owns full and compact request association');

if ($failures !== []) {
    fwrite(STDERR, 'REGRESS_CAPTURE_REFACTOR_BOUNDARIES FAILED: ' . count($failures) . " check(s)\n");
    exit(1);
}
fwrite(STDOUT, "REGRESS_CAPTURE_REFACTOR_BOUNDARIES PASSED\n");

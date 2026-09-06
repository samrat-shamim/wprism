<?php
/** Participant-owned Rank Math/Yoast fail-closed composition contract. */
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/wp_stubs.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/agent_version.php';

$root = dirname(__DIR__, 4);
wprism_test_define_agent_versions();

require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Policy/Policy.php';

use WPrism\AdapterLibrary;
use WPrism\Canon;
use WPrism\Policy;

$scenarioPath = $root . '/integration-scenarios/rank-math-yoast-incompatibility/scenario.json';
$scenarioBytes = (string) file_get_contents($scenarioPath);
$scenario = Canon::decode($scenarioBytes);
wprism_check_same($scenarioBytes, Canon::encode($scenario),
    'the Rank Math/Yoast participant record is canonical');
wprism_check_same(['rank-math', 'yoast'], $scenario['participants'] ?? null,
    'the incompatibility scenario declares both exact adapter owners in sorted order');

$liveRelative = 'integration-scenarios/rank-math-yoast-incompatibility/tests/live/'
    . 'regress_rank_math_yoast_incompatibility.sh';
foreach (['rank-math', 'yoast'] as $participant) {
    $external = Canon::decode(Canon::read_file(
        $root . "/adapter-packages/$participant/evidence/external-tests.json"
    ));
    wprism_check_same(
        $liveRelative,
        $external['tests']['regress-rank-math-yoast-incompatibility'] ?? null,
        "$participant cites the participant-owned live incompatibility gate"
    );
}

$privateEvidencePath = $root . '/integration-scenarios/rank-math-yoast-incompatibility/'
    . 'fixtures/private-refusal-evidence.php';
require_once $privateEvidencePath;
wprism_check_same(
    'e0e3db584904aa388ce5547e87b149223ed113f23751f0bc94b5f98e8acbb421',
    hash('sha256', WPRISM_RANK_YOAST_PRIVATE_REFUSAL_MESSAGE),
    'the scenario verifier pins the exact private incompatibility sentence by hash'
);
$privateScratch = sys_get_temp_dir() . '/wprism_rank_yoast_private_' . bin2hex(random_bytes(8));
$privateDirectory = $privateScratch . '/refusals';
$privateName = '20260903-193751-compile-' . str_repeat('a', 24) . '.json';
$privatePath = $privateDirectory . '/' . $privateName;
register_shutdown_function(static function () use ($privatePath, $privateDirectory, $privateScratch): void {
    @unlink($privatePath);
    @rmdir($privateDirectory);
    @rmdir($privateScratch);
});
mkdir($privateDirectory, 0700, true);
chmod($privateDirectory, 0700);
wprism_check_same([], rank_yoast_private_refusal_inventory($privateDirectory),
    'the scenario verifier starts from one command-scoped empty baseline');
$privateRecord = [
    'format' => 'wprism-private-refusal-evidence/v2',
    'command' => 'compile',
    'reason_code' => 'compile_failed',
    'throwable' => [[
        'index' => 0,
        'parent_index' => null,
        'relation' => 'root',
        'message' => WPRISM_RANK_YOAST_PRIVATE_REFUSAL_MESSAGE,
        'message_encoding' => 'utf-8',
        'message_original_bytes' => strlen(WPRISM_RANK_YOAST_PRIVATE_REFUSAL_MESSAGE),
        'message_sha256' => hash('sha256', WPRISM_RANK_YOAST_PRIVATE_REFUSAL_MESSAGE),
        'message_truncated' => false,
    ]],
    'traversal' => ['scan_complete' => true, 'record_complete' => true],
];
$writePrivateRecord = static function (array $record) use ($privatePath): void {
    file_put_contents(
        $privatePath,
        json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
    );
    chmod($privatePath, 0600);
};
$writePrivateRecord($privateRecord);
$privateReceipt = rank_yoast_private_refusal_verify($privateDirectory, '[]');
wprism_check_same(rank_yoast_private_refusal_receipt(), $privateReceipt,
    'one exact appended private graph yields the fixed value-free scenario receipt');
wprism_check(!str_contains($privateReceipt, WPRISM_RANK_YOAST_PRIVATE_REFUSAL_MESSAGE),
    'the scenario receipt never republishes its private root sentence');
$wrongPrivateRecord = $privateRecord;
$wrongPrivateRecord['throwable'][0]['message'] = 'wrong private incompatibility canary';
$wrongPrivateRecord['throwable'][0]['message_original_bytes'] = strlen('wrong private incompatibility canary');
$wrongPrivateRecord['throwable'][0]['message_sha256'] = hash('sha256', 'wrong private incompatibility canary');
$writePrivateRecord($wrongPrivateRecord);
wprism_check_throws(
    static fn() => rank_yoast_private_refusal_verify($privateDirectory, '[]'),
    RuntimeException::class,
    'a wrong private incompatibility cause cannot satisfy the scenario verifier',
    'does not retain the exact complete root cause'
);
wprism_check_throws(
    static fn() => rank_yoast_private_refusal_verify(
        $privateDirectory,
        json_encode([$privateName], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    ),
    RuntimeException::class,
    'a stale current record cannot masquerade as evidence appended by a new invocation',
    'did not append exactly one compile refusal record'
);

$site = sys_get_temp_dir() . '/wprism_rank_yoast_' . bin2hex(random_bytes(8));
if (!mkdir($site, 0700, true) && !is_dir($site)) {
    throw new RuntimeException("could not create scratch site repository '$site'");
}
register_shutdown_function(static function () use ($site): void {
    @unlink($site . '/site.wprism.json');
    @rmdir($site);
});
$library = AdapterLibrary::fromSourceTree($root);
$expected = "wprism: manifest 'rank-math' for plugin 'seo-by-rank-math/rank-math.php' declares plugin "
    . "'wordpress-seo/wp-seo.php' incompatible, and pinned manifest(s) {'yoast'} claim that plugin — "
    . 'incompatible plugin adapters cannot share one policy; pin only one';
foreach ([
    ['core', 'rank-math', 'yoast'],
    ['core', 'yoast', 'rank-math'],
] as $pins) {
    Canon::write_file($site . '/site.wprism.json', Canon::encode([
        'manifests' => array_map(
            static fn(string $name): array => ['name' => $name, 'source' => 'shipped'],
            array_slice($pins, 1)
        ),
        'policy' => new stdClass(),
        'spec_version' => WPRISM_SPEC_VERSION,
    ]));
    wprism_check_throws(
        static fn() => Policy::load($site, $pins, adapterLibrary: $library),
        RuntimeException::class,
        'the participant policy refuses before composition in order ' . implode(',', $pins),
        $expected
    );
}

$live = (string) file_get_contents($root . '/' . $liveRelative);
foreach ([
    'candidate SHA',
    'clean candidate checkout',
    'seo-by-rank-math 1.0.277.2',
    'wordpress-seo 28.3',
    'core,rank-math,yoast',
    'core,yoast,rank-math',
    $expected,
    'capture refused before repository publication',
    'deploy refused before promotion-begin or provider settlement',
    'no lease, apply session, or provider intent survived',
] as $witness) {
    wprism_check(str_contains($live, $witness), "the candidate-bound live refusal pins: $witness");
}

$privateSnapshot = strpos($live,
    'private_baseline=$(private_evidence "$side" snapshot /siterepo/.wprism/refusals)');
$privateDeploy = strpos($live, 'output=$(host_deploy "$side" 2>&1)');
$privateVerify = strpos($live,
    'private_receipt=$(private_evidence "$side" verify');
$answered = strpos($live, 'require_wprism_answered "Rank Math/Yoast $operation refusal" human "$output"');
$captureBranch = $answered === false ? false : strpos($live, 'if [ "$operation" = capture ]; then', $answered);
$captureCause = $captureBranch === false ? false
    : strpos($live, 'grep -Fq "$EXPECTED_REFUSAL" <<<"$output"', $captureBranch);
$captureNotRedacted = $captureCause === false ? false
    : strpos($live, '! grep -Fq \'"details_redacted":true\' <<<"$output"', $captureCause);
wprism_check(
    $answered !== false
        && $captureBranch !== false
        && $captureCause !== false
        && $captureNotRedacted !== false
        && $answered < $captureBranch
        && $captureBranch < $captureCause
        && $captureCause < $captureNotRedacted
        && $captureNotRedacted < $privateVerify,
    'capture proves the exact incompatibility cause publicly and rejects a redacted local envelope before deploy proof'
);
$deployFormat = $privateDeploy === false ? false
    : strpos($live, 'grep -Fq \'"format":"wprism-command-refusal/v1"\' <<<"$output"', $privateDeploy);
$deployCommand = $deployFormat === false ? false
    : strpos($live, 'grep -Fq \'"command":"compile"\' <<<"$output"', $deployFormat);
$deployReason = $deployCommand === false ? false
    : strpos($live, 'grep -Fq \'"reason_code":"compile_failed"\' <<<"$output"', $deployCommand);
$deployRedacted = $deployReason === false ? false
    : strpos($live, 'grep -Fq \'"details_redacted":true\' <<<"$output"', $deployReason);
$deployHint = $deployRedacted === false ? false
    : strpos($live, 'grep -Fq "the target\'s compile refusal was redacted" <<<"$output"', $deployRedacted);
$deployPointer = $deployHint === false ? false
    : strpos($live, 'grep -Fq \'.wprism/refusals/\' <<<"$output"', $deployHint);
$deployPrivateAbsent = $deployPointer === false ? false
    : strpos($live, '! grep -Fq "$EXPECTED_REFUSAL" <<<"$output"', $deployPointer);
wprism_check(
    $privateSnapshot !== false
        && $privateDeploy !== false
        && $privateVerify !== false
        && $privateSnapshot < $privateDeploy
        && $privateDeploy < $privateVerify
        && str_contains($live, '/.tmp-rank-math-yoast-private-refusal.php')
        && str_contains($live, '! -path "$repo/.wprism/refusals/*"')
        && $deployFormat !== false
        && $deployCommand !== false
        && $deployReason !== false
        && $deployRedacted !== false
        && $deployHint !== false
        && $deployPointer !== false
        && $deployPrivateAbsent !== false
        && $privateDeploy < $deployFormat
        && $deployFormat < $deployCommand
        && $deployCommand < $deployReason
        && $deployReason < $deployRedacted
        && $deployRedacted < $deployHint
        && $deployHint < $deployPointer
        && $deployPointer < $deployPrivateAbsent
        && $deployPrivateAbsent < $privateVerify
        && str_contains($live, "'" . rank_yoast_private_refusal_receipt() . "'"),
    'deploy proves one new private cause around its redacted public compile refusal without calling it repository publication'
);

$order1Readback = strpos($live, 'ORDER1=$(wp1 option get active_plugins');
$order2Readback = strpos($live, 'ORDER2=$(wp2 option get active_plugins');
$order1Guard = strpos($live,
    '[ "$ORDER1" = \'["seo-by-rank-math/rank-math.php","wordpress-seo/wp-seo.php"]\' ]');
$order2Guard = strpos($live,
    '[ "$ORDER2" = \'["wordpress-seo/wp-seo.php","seo-by-rank-math/rank-math.php"]\' ]');
$runtimeBaseline = strpos($live, 'BASE1=$(target_witness 1)');
wprism_check(
    $order1Readback !== false
        && $order2Readback !== false
        && $order1Guard !== false
        && $order2Guard !== false
        && $runtimeBaseline !== false
        && $order1Readback < $order2Readback
        && $order2Readback < $order1Guard
        && $order1Guard < $order2Guard
        && $order2Guard < $runtimeBaseline,
    'the live gate reads back and exactly guards both plugin boot orders before refusal execution'
);
$orderSetters = [
    "wp1 option update active_plugins \\\n  '[\"seo-by-rank-math/rank-math.php\",\"wordpress-seo/wp-seo.php\"]' --format=json",
    "wp2 option update active_plugins \\\n  '[\"wordpress-seo/wp-seo.php\",\"seo-by-rank-math/rank-math.php\"]' --format=json",
];
wprism_check_same(2, substr_count($live, 'option update active_plugins'),
    'the live gate authors exactly the two plugin-order premises it claims');
foreach ($orderSetters as $orderSetter) {
    $position = strpos($live, $orderSetter);
    wprism_check($position !== false && $position < $order1Readback,
        'each exact active-plugin order is persisted before either order is observed');
}

foreach ([
    'PAIR="${RANK_MATH_YOAST_PAIR:-}"',
    'PORT1_RAW="${RANK_MATH_YOAST_PORT1:-}"',
    'PORT2_RAW="${RANK_MATH_YOAST_PORT2:-}"',
    'EXPECTED_SHA="${RANK_MATH_YOAST_EXPECTED_SOURCE_SHA:-}"',
    'PORT1 % 2 == 0 && PORT2 == PORT1 + 1',
    'export WPRISM_SOURCE_ROOT="$ROOT" WPRISM_EXPECTED_SOURCE_SHA="$EXPECTED_SHA"',
    "WPRISM_DB_ENGINE='mariadb' WPRISM_DB_HOST='wprism-shared-db'",
    '. tests/lib/pair_live_ownership.sh',
    'pair_live_ownership_prepare "$PAIR" "$PORT1" "$PORT2"',
    'TMP_ROOT="$PAIR_LIVE_OWNERSHIP_TMP_ROOT"',
    'WPRISM_HOST_REGISTRY="$TMP_ROOT/host-envs.json"',
    'private Rank Math/Yoast host registry mode is not 0600',
    'pair_live_ownership_mode_of "$WPRISM_HOST_REGISTRY"',
    'pair_live_ownership_acquire mariadb',
    'pair_live_ownership_up --artifacts --headless',
    'pair_live_ownership_repo_host both',
    'pair_live_ownership_complete',
] as $ownershipWitness) {
    wprism_check(str_contains($live, $ownershipWitness),
        "the incompatibility gate owns its disposable boundary: $ownershipWitness");
}
wprism_check(
    !str_contains($live, 'RANK_MATH_YOAST_PAIR:-rmyoast')
        && !str_contains($live, 'RANK_MATH_YOAST_EXPECTED_SOURCE_SHA:-${WPRISM_EXPECTED_SOURCE_SHA')
        && !str_contains($live, 'bash bin/pair.sh reset "$PAIR"')
        && !str_contains($live, 'destroy "$PAIR" >/dev/null 2>&1 || true')
        && !str_contains($live, 'left up for inspection after failure')
        && !str_contains($live, 'cleanup() {')
        && !str_contains($live, "stat -f '%Lp' \"\$TMP_ROOT\" 2>/dev/null || stat -c")
        && substr_count(
            $live,
            'PASS: Rank Math/Yoast incompatibility is deterministic before mutation in both orders'
        ) === 1,
    'the one-leg refusal gate has no reset, default allocation, failure leak, suppressed teardown, or early PASS route'
);
$ownershipPrepare = strpos($live, 'pair_live_ownership_prepare "$PAIR" "$PORT1" "$PORT2"');
$leaseAcquire = strpos($live, 'pair_live_ownership_acquire mariadb');
$pairUp = strpos($live, 'pair_live_ownership_up --artifacts --headless', $leaseAcquire === false ? 0 : $leaseAcquire);
$firstInstall = strpos($live, 'install_exact 1 seo-by-rank-math 1.0.277.2');
wprism_check(
    $ownershipPrepare !== false
        && $leaseAcquire !== false
        && $pairUp !== false
        && $firstInstall !== false
        && $ownershipPrepare < $leaseAcquire
        && $leaseAcquire < $pairUp
        && $pairUp < $firstInstall,
    'the incompatibility proof starts its sole pair only after generic lease publication and ownership marking'
);
$ownershipHelper = (string) file_get_contents($root . '/sandbox/tests/lib/pair_live_ownership.sh');
wprism_check(
    str_contains($ownershipHelper, 'pair_live_ownership_remove_pair_roots')
        && str_contains($ownershipHelper, 'pair_live_ownership_remove_scratch')
        && str_contains($ownershipHelper, 'lease-batch-release')
        && str_contains($ownershipHelper, 'PAIR_LIVE_OWNERSHIP_BODY_COMPLETE=1'),
    'partial-up cleanup and sole PASS are delegated to the behaviorally tested shared ownership state machine'
);
$makefile = (string) file_get_contents($root . '/Makefile');
wprism_check(
    str_contains($makefile, "regress-rank-math-yoast-incompatibility:\n")
        && str_contains($makefile, '@test -n "$(RANK_MATH_YOAST_PAIR)"')
        && str_contains($makefile, '@test -n "$(RANK_MATH_YOAST_PORT1)"')
        && str_contains($makefile, '@test -n "$(RANK_MATH_YOAST_PORT2)"')
        && str_contains($makefile, '@test -n "$(RANK_MATH_YOAST_EXPECTED_SOURCE_SHA)"')
        && str_contains($makefile,
            'RANK_MATH_YOAST_EXPECTED_SOURCE_SHA="$(RANK_MATH_YOAST_EXPECTED_SOURCE_SHA)" bash integration-scenarios/rank-math-yoast-incompatibility/tests/live/regress_rank_math_yoast_incompatibility.sh'),
    'the Make entrypoint requires and forwards the exact incompatibility allocation instead of reviving defaults'
);

wprism_check_summary('regress_rank_math_yoast_incompatibility');

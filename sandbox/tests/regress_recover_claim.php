<?php
/**
 * Offline characterization for the recovery claim, the profile selection that
 * produces it and the checkpoint catalog `duo recover --list` reads (round-3
 * MUP §2.3.1, §2.5; product spec safety invariant 10, *Recovery claims are
 * literal*).
 *
 * Three properties are the whole point, and each has a way of quietly
 * breaking that only a gate catches:
 *
 *  - **The claim printed at recovery is byte-identical to the claim in the
 *    frozen plan.** Not "equivalent", not "regenerated from the same inputs":
 *    the same bytes. A regenerated claim drifts the moment anything about the
 *    target changes, and the operator would then authorize one guarantee and
 *    receive another. The plan embeds the canonical array; this suite freezes
 *    it, reads it back off disk and compares encodings.
 *  - **`does_not_restore` is non-empty for EVERY profile, `verified-automatic`
 *    included.** The strongest profile this platform can prove still cannot
 *    un-send a mail. A profile that listed nothing it fails to restore would
 *    be implying external reality was undone, which is the exact sentence the
 *    safety invariant forbids.
 *  - **The profile enum is closed** — `verified-automatic | operator-directed
 *    | none` — and `none` is only ever requested, never proved.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/check.php';

require_once __DIR__ . '/../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../cli/src/Contract/ApplicationContract.php';
require_once __DIR__ . '/../../cli/src/Recovery/CheckpointCatalog.php';
require_once __DIR__ . '/../../cli/src/Recovery/RecoveryClaim.php';
require_once __DIR__ . '/../../cli/src/Recovery/RecoveryProfileSelection.php';
require_once __DIR__ . '/../../cli/src/Release/AuthorizationPlan.php';

use Duo\Orchestrator\ApplicationContract;
use Duo\Orchestrator\AuthorizationPlan;
use Duo\Orchestrator\CheckpointCatalog;
use Duo\Orchestrator\RecoveryClaim;
use Duo\Orchestrator\RecoveryProfileSelection;

$fixtures = __DIR__ . '/fixtures/release';

/** @return array<string,mixed> */
function claim_fixture(string $path): array {
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        throw new RuntimeException("fixture is not a JSON document: $path");
    }

    return $decoded;
}

function claim_fixture_repo(): string {
    $root = sys_get_temp_dir() . '/duo-claim-' . bin2hex(random_bytes(6));
    if (!mkdir($root, 0777, true)) {
        throw new RuntimeException("cannot create fixture site repo at $root");
    }
    register_shutdown_function(static function () use ($root): void {
        if (!is_dir($root)) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($root);
    });

    return (string) (realpath($root) ?: $root);
}

$contract = ApplicationContract::withDigest(claim_fixture("$fixtures/contract-declared-unbound.json"));
$effects = $contract['declarations']['external_effects'];

// ---------------------------------------- does_not_restore, for every profile
foreach (RecoveryClaim::PROFILES as $profile) {
    $claim = RecoveryClaim::build(['profile' => $profile]);
    duo_check(
        $claim['does_not_restore'] !== [],
        "the $profile profile names what it does not restore"
    );
    foreach (RecoveryClaim::UNIVERSAL_DOES_NOT_RESTORE as $row) {
        if (!in_array($row, $claim['does_not_restore'], true)) {
            duo_check(false, "the $profile claim dropped the universal row: $row");
        }
    }
    RecoveryClaim::validate($claim);
    duo_check_same(
        RecoveryClaim::RESTORES[$profile],
        $claim['restores'],
        "the $profile profile restores exactly the resources it operates on"
    );
}
duo_check(true, 'every profile keeps the universal does-not-restore floor, verified-automatic included');

duo_check(
    in_array('emails already sent', RecoveryClaim::build(['profile' => RecoveryClaim::VERIFIED_AUTOMATIC])['does_not_restore'], true),
    'even the strongest provable profile states that a sent mail is not undone'
);
duo_check_same(
    [
        RecoveryClaim::RESOURCE_DATABASE_CHECKPOINT,
        RecoveryClaim::RESOURCE_CODE_RELEASE,
        RecoveryClaim::RESOURCE_UPLOAD_BUNDLE,
        RecoveryClaim::RESOURCE_EFFECT_BUNDLE,
    ],
    RecoveryClaim::RESTORES[RecoveryClaim::VERIFIED_AUTOMATIC],
    'the verified profile restores the four named resources VerifiedRollbackProfile::rollback() operates on'
);
duo_check_same(
    [RecoveryClaim::RESOURCE_DATABASE_CHECKPOINT],
    RecoveryClaim::RESTORES[RecoveryClaim::OPERATOR_DIRECTED],
    'the operator-directed profile is checkpoint-only, exactly like ScopedRollbackProfile'
);
duo_check_same([], RecoveryClaim::RESTORES[RecoveryClaim::NONE], 'the none profile restores nothing');

// ------------------------------------------------------------- profile enum
duo_check_throws(
    static fn () => RecoveryClaim::build(['profile' => 'best-effort']),
    InvalidArgumentException::class,
    'a profile outside the closed enum is a caller bug, not an operator condition'
);
duo_check_throws(
    static fn () => RecoveryClaim::build(['profile' => RecoveryClaim::NONE, 'unexpected' => 1]),
    InvalidArgumentException::class,
    'the claim fact set is closed'
);

// ------------------------------------------- the site's own declared effects
$verified = RecoveryClaim::build([
    'checkpoint_at' => '2026-08-17T09:14:02Z',
    'covered_resources' => ['code release e2f1a09', 'upload bundle (3 entries)'],
    'declared_external_effects' => $effects,
    'profile' => RecoveryClaim::VERIFIED_AUTOMATIC,
]);
$mailRow = 'declared external effect "transactional-mail" — recovery semantics: irreversible';
duo_check(
    in_array($mailRow, $verified['does_not_restore'], true),
    'an irreversible declared effect appears in the does-not-restore list verbatim'
);
duo_check(
    !in_array(
        'declared external effect "code-lifecycle-window" — restored by "code release", '
            . 'which the verified-automatic profile does not restore',
        $verified['does_not_restore'],
        true
    ),
    'an effect the verified profile really does restore is not listed as unrestored'
);

$operatorDirected = RecoveryClaim::build([
    'declared_external_effects' => $effects,
    'profile' => RecoveryClaim::OPERATOR_DIRECTED,
]);
duo_check(
    in_array(
        'declared external effect "code-lifecycle-window" — restored by "code release", '
            . 'which the operator-directed profile does not restore',
        $operatorDirected['does_not_restore'],
        true
    ),
    'a "restorable by code release" effect is honestly unrestored under a checkpoint-only profile'
);

duo_check_same(
    'writes committed after checkpoint 2026-08-17T09:14:02Z',
    $verified['maximum_loss_boundary'],
    'the maximum loss boundary is literal about which writes are lost'
);
duo_check_same(
    'writes committed after the checkpoint this release takes immediately before mutation',
    RecoveryClaim::build(['profile' => RecoveryClaim::VERIFIED_AUTOMATIC])['maximum_loss_boundary'],
    'without a checkpoint timestamp the boundary says so rather than inventing one'
);
duo_check(
    str_contains(
        RecoveryClaim::build(['profile' => RecoveryClaim::NONE])['maximum_loss_boundary'],
        'nothing bounds the loss'
    ),
    'the none profile states that nothing bounds the loss'
);

duo_check_same(
    false,
    $verified['writer_exclusion']['required'],
    'the verified profile holds its own exclusion, so the operator asserts nothing'
);
duo_check_same(
    true,
    $operatorDirected['writer_exclusion']['required'],
    'the operator-directed profile requires an asserted external maintenance window'
);

// -------------------------------------------------------- digest and tamper
duo_check_same(
    RecoveryClaim::digest($verified),
    $verified['claim_digest'],
    'the claim digest covers everything except itself'
);
$softened = $verified;
$softened['maximum_loss_boundary'] = 'nothing is lost';
duo_check_refuses(
    static fn () => RecoveryClaim::validate($softened),
    'recovery_claim_digest_mismatch',
    'quietly editing the loss boundary in a frozen plan breaks the claim digest'
);
$tampered = $verified;
$tampered['does_not_restore'] = [];
duo_check_refuses(
    static fn () => RecoveryClaim::validate($tampered),
    'recovery_claim_not_literal',
    'a claim that lists nothing it fails to restore is refused on its own terms, before the digest is consulted'
);
$rehashed = $tampered;
$rehashed['claim_digest'] = RecoveryClaim::digest($rehashed);
duo_check_refuses(
    static fn () => RecoveryClaim::validate($rehashed),
    'recovery_claim_not_literal',
    'even a re-digested claim that restores everything is refused as not literal'
);
$overclaim = $verified;
$overclaim['profile'] = RecoveryClaim::NONE;
$overclaim['claim_digest'] = RecoveryClaim::digest($overclaim);
duo_check_refuses(
    static fn () => RecoveryClaim::validate($overclaim),
    'recovery_claim_not_literal',
    'a claim whose restores exceed its named profile is refused'
);

// ------------------------------- byte identity between plan and recovery
$selection = RecoveryProfileSelection::decide([
    'automatic' => true,
    'profile' => RecoveryClaim::VERIFIED_AUTOMATIC,
    'reason' => 'all verified rollback capabilities are ready',
    'scoped' => false,
    'status' => [],
], [
    'checkpoint_at' => '2026-08-17T09:14:02Z',
    'covered_resources' => ['code release e2f1a09', 'upload bundle (3 entries)'],
    'declared_external_effects' => $effects,
]);
duo_check_same(
    RecoveryClaim::encode($verified),
    RecoveryClaim::encode($selection['claim']),
    'the selection builds the same claim the claim builder does, from the same facts'
);

$document = AuthorizationPlan::build([
    'authority' => [],
    'capabilities' => [],
    'contract' => $contract,
    'deletion_semantics' => [],
    'environment' => 'production',
    'flags' => ['plan_only' => false, 'with_deletes' => false],
    'frozen_at' => '2026-08-17T09:14:02Z',
    'plan' => claim_fixture("$fixtures/plan-clean.json"),
    'projection' => claim_fixture("$fixtures/projection-ready.json"),
    'recovery' => $selection,
    'scope' => [
        'code' => ['lifecycle_phases' => ['retire', 'activate'], 'plugins_changed' => 0, 'themes_changed' => 1],
        'surfaces' => ['products', 'pages'],
    ],
    'target' => claim_fixture("$fixtures/target-facts.json"),
]);
$repo = claim_fixture_repo();
AuthorizationPlan::freeze($document, $repo);
$frozen = AuthorizationPlan::read($repo, (string) $document['plan_digest']);
duo_check_same(
    RecoveryClaim::encode($selection['claim']),
    RecoveryClaim::encode($frozen['recovery_profile']['claim']),
    'the claim duo recover re-prints off disk is byte-identical to the one the operator authorized'
);
duo_check_same(
    RecoveryClaim::humanLines($selection['claim']),
    RecoveryClaim::humanLines($frozen['recovery_profile']['claim']),
    'the human printing at recovery is line-for-line the printing at authorization'
);
RecoveryClaim::validate($frozen['recovery_profile']['claim']);
duo_check(true, 'the claim read back off disk still validates as literal');

// ------------------------------------------------ selection warning lines
$degraded = RecoveryProfileSelection::decide([
    'automatic' => false,
    'profile' => RecoveryClaim::OPERATOR_DIRECTED,
    'reason' => 'missing effect provider',
    'scoped' => false,
    'status' => [],
], []);
duo_check_same(
    RecoveryProfileSelection::WARN_PREFIX . 'missing effect provider' . RecoveryProfileSelection::WARN_SUFFIX,
    $degraded['warning'],
    'the degraded-profile warning is the same sentence duo promote already prints'
);
duo_check_same(null, $selection['warning'], 'a fully proved verified profile prints no warning');
duo_check_same(
    null,
    RecoveryProfileSelection::decide([
        'automatic' => true,
        'profile' => RecoveryClaim::OPERATOR_DIRECTED,
        'reason' => 'checkpoint-only scoped promotion capabilities are ready',
        'scoped' => true,
        'status' => [],
    ], [])['warning'],
    'a scoped window is checkpoint-only by design, so it warns about nothing'
);

// ----------------------------------------------------- checkpoint catalog
$verifiedStatus = [
    'active' => true,
    'artifact_hash' => str_repeat('9', 64),
    'available' => true,
    'checkpoint_sha256' => str_repeat('c', 64),
    'claim_expires_at' => '2026-08-17T09:24:02Z',
    'code_release_metadata_sha256' => str_repeat('d', 64),
    'generation' => 12,
    'lifecycle_receipts_sha256' => str_repeat('e', 64),
    'ok' => true,
    'owner' => 'verified-9ac4',
    'receipt_id' => 'receipt-0012',
    'retention_until' => '2026-08-24T09:14:02Z',
    'state' => 'committed',
    'terminal' => true,
    'uploads_inventory_sha256' => str_repeat('f', 64),
];
$catalog = CheckpointCatalog::fromStatus($verifiedStatus, null, '2026-08-17T09:20:02Z');
duo_check_same(1, count($catalog['rows']), 'the catalog lists the active receipt');
duo_check_same(CheckpointCatalog::KIND_VERIFIED, $catalog['rows'][0]['kind'], 'a full receipt is a verified promotion');
duo_check_same(
    RecoveryClaim::RESOURCES,
    $catalog['rows'][0]['covers'],
    'a full receipt covers exactly the resources its own evidence keys prove'
);
duo_check(
    in_array(CheckpointCatalog::DISCLOSURE_ACTIVE_ONLY, $catalog['disclosures'], true),
    'the catalog discloses that the authority holds one generation, not a history'
);
duo_check(
    in_array(CheckpointCatalog::DISCLOSURE_NO_CREATION_TIME, $catalog['disclosures'], true),
    'a full receipt publishes no creation time, and the catalog says so instead of fabricating an age'
);
duo_check_same(null, $catalog['rows'][0]['age_seconds'], 'no creation time means no age, never a guessed one');
duo_check_same(
    $catalog['rows'][0],
    CheckpointCatalog::find($catalog, 'receipt-0012'),
    'a row is findable by the receipt id duo recover --restore= takes'
);

$partial = $verifiedStatus;
$partial['code_release_metadata_sha256'] = null;
$partial['uploads_inventory_sha256'] = '';
duo_check_same(
    [RecoveryClaim::RESOURCE_DATABASE_CHECKPOINT, RecoveryClaim::RESOURCE_EFFECT_BUNDLE],
    CheckpointCatalog::fromStatus($partial, null, '2026-08-17T09:20:02Z')['rows'][0]['covers'],
    'a receipt without code/upload evidence does not claim to cover them'
);

$scopedStatus = [
    'active' => true,
    'artifact_hash' => str_repeat('9', 64),
    'available' => true,
    'checkpoint_sha256' => str_repeat('c', 64),
    'created_at' => '2026-08-17T09:14:02Z',
    'generation' => 3,
    'ok' => true,
    'owner' => 'scoped-abcd',
    'receipt_format' => 'duo-scoped-promotion-receipt/v1',
    'receipt_id' => 'receipt-0003',
    'scope_hash' => str_repeat('a', 64),
    'state' => 'promoting',
    'terminal' => false,
];
$scopedCatalog = CheckpointCatalog::fromStatus($scopedStatus, null, '2026-08-17T09:20:02Z');
duo_check_same(
    CheckpointCatalog::KIND_SCOPED,
    $scopedCatalog['rows'][0]['kind'],
    'a scoped receipt is listed as a scoped promotion'
);
duo_check_same(
    [RecoveryClaim::RESOURCE_DATABASE_CHECKPOINT],
    $scopedCatalog['rows'][0]['covers'],
    'a scoped receipt covers the checkpoint alone, because that is all it ever prepares'
);
duo_check_same(360, $scopedCatalog['rows'][0]['age_seconds'], 'a scoped receipt does publish a real age');

duo_check_same(
    [CheckpointCatalog::DISCLOSURE_UNAVAILABLE],
    CheckpointCatalog::fromStatus(['available' => false, 'ok' => false], null, '2026-08-17T09:20:02Z')['disclosures'],
    'a target with no rollback runtime says so rather than listing an empty catalog silently'
);
duo_check_same(
    [CheckpointCatalog::DISCLOSURE_NONE_ACTIVE],
    CheckpointCatalog::fromStatus(
        ['active' => false, 'available' => true, 'ok' => true],
        null,
        '2026-08-17T09:20:02Z'
    )['disclosures'],
    'an available authority with no active receipt is distinguished from an absent one'
);
duo_check_same(
    [CheckpointCatalog::DISCLOSURE_INVALID],
    CheckpointCatalog::fromStatus(
        ['active' => null, 'available' => true, 'ok' => false, 'error' => 'verification failed'],
        null,
        '2026-08-17T09:20:02Z'
    )['disclosures'],
    'evidence that did not verify is reported as unknown, never as an empty catalog'
);
duo_check_refuses(
    static fn () => CheckpointCatalog::fromStatus(
        array_replace($verifiedStatus, ['state' => 'teleporting']),
        null,
        '2026-08-17T09:20:02Z'
    ),
    'checkpoint_state_unknown',
    'a receipt state this build does not know refuses rather than being printed as if understood'
);

$lines = CheckpointCatalog::humanLines($catalog);
duo_check(
    str_contains(implode("\n", $lines), 'note: ' . CheckpointCatalog::DISCLOSURE_ACTIVE_ONLY),
    'the human listing prints the disclosure, not only the JSON view'
);

duo_check_summary('regress_recover_claim');

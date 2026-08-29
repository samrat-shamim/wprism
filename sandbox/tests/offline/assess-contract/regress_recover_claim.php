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
 *
 * A fourth property joined them once the first one was found to be reachable
 * only by luck: **the claim carries no clock value**. It is embedded in the
 * DIGESTED part of the frozen plan, so a timestamp anywhere in it makes
 * `plan_digest` change every second for one unchanged decision — and a claim
 * whose bytes move cannot be byte-identical at two printings either. The
 * checkpoint instant therefore travels beside the claim
 * (`recovery_profile.checkpoint_at`, excluded from the digest exactly like
 * `frozen_at`), and `regress_authorization_plan.php` holds the clock-second
 * gate over the plan. Here the claim is checked for the absence itself.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../cli/src/Contract/ApplicationContract.php';
require_once __DIR__ . '/../../../../cli/src/Recovery/CheckpointCatalog.php';
require_once __DIR__ . '/../../../../cli/src/Recovery/RecoveryClaim.php';
require_once __DIR__ . '/../../../../cli/src/Recovery/RecoveryProfileSelection.php';
require_once __DIR__ . '/../../../../cli/src/Command/RecoverCommand.php';
require_once __DIR__ . '/../../../../cli/src/Release/AuthorizationPlan.php';

use Duo\Orchestrator\ApplicationContract;
use Duo\Orchestrator\AuthorizationPlan;
use Duo\Orchestrator\CheckpointCatalog;
use Duo\Orchestrator\RetainedCheckpoints;
use Duo\Orchestrator\RecoverCommand;
use Duo\Orchestrator\RecoveryClaim;
use Duo\Orchestrator\RecoveryProfileSelection;

$fixtures = __DIR__ . '/../../fixtures/release';

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
    'writes committed after the checkpoint this release takes immediately before mutation; '
        . 'the instant that checkpoint pins is printed beside this claim',
    $verified['maximum_loss_boundary'],
    'the maximum loss boundary is literal about which writes are lost, and about where its instant is'
);
duo_check_same(
    $verified['maximum_loss_boundary'],
    RecoveryClaim::build(['profile' => RecoveryClaim::VERIFIED_AUTOMATIC])['maximum_loss_boundary'],
    'the boundary sentence is a fact about the profile alone, so no caller fact can move it'
);
duo_check(
    str_contains(
        RecoveryClaim::build(['profile' => RecoveryClaim::NONE])['maximum_loss_boundary'],
        'nothing bounds the loss'
    ),
    'the none profile states that nothing bounds the loss'
);

// --------------------------------------------------- the claim carries no clock
// Checked on the ENCODED claim rather than field by field, because the defect
// this closes did not arrive through a field named for a time: it arrived
// inside a prose sentence. Any future one would too.
duo_check_throws(
    static fn () => RecoveryClaim::build([
        'checkpoint_at' => '2026-08-17T09:14:02Z',
        'profile' => RecoveryClaim::VERIFIED_AUTOMATIC,
    ]),
    InvalidArgumentException::class,
    'checkpoint_at is not a claim fact at all: a caller offering one is a caller bug, not a silently ignored key'
);
foreach (RecoveryClaim::PROFILES as $profile) {
    $encoded = RecoveryClaim::encode(RecoveryClaim::build([
        'covered_resources' => ['code release e2f1a09'],
        'declared_external_effects' => $effects,
        'profile' => $profile,
    ]));
    if (preg_match('/[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z/', $encoded) === 1) {
        duo_check(false, "the $profile claim carries a timestamp, which puts a clock inside plan_digest");
    }
}
duo_check(true, 'no profile puts a canonical UTC instant anywhere in the claim it builds');
duo_check_same(
    RecoveryClaim::encode(RecoveryClaim::build(['profile' => RecoveryClaim::VERIFIED_AUTOMATIC])),
    RecoveryClaim::encode(RecoveryClaim::build(['profile' => RecoveryClaim::VERIFIED_AUTOMATIC])),
    'two claims built from the same facts at different moments are the same bytes'
);

// The selection is where the instant surfaces instead — beside the claim, and
// null for the profile that takes no checkpoint at all.
$dated = RecoveryProfileSelection::decide([
    'automatic' => true,
    'profile' => RecoveryClaim::VERIFIED_AUTOMATIC,
    'reason' => 'all verified rollback capabilities are ready',
    'scoped' => false,
    'status' => [],
], ['checkpoint_at' => '2026-08-17T09:14:02Z']);
duo_check_same(
    '2026-08-17T09:14:02Z',
    $dated['checkpoint_at'],
    'the selection publishes the checkpoint instant beside the claim, where no digest covers it'
);
duo_check(
    !str_contains(RecoveryClaim::encode($dated['claim']), '2026-08-17T09:14:02Z'),
    'the same selection keeps that instant out of the claim it embeds in the plan'
);
duo_check_same(
    null,
    RecoveryProfileSelection::decide([
        'automatic' => true,
        'profile' => RecoveryClaim::VERIFIED_AUTOMATIC,
        'reason' => 'all verified rollback capabilities are ready',
        'scoped' => false,
        'status' => [],
    ], [
        'accept_weaker_recovery' => true,
        'checkpoint_at' => '2026-08-17T09:14:02Z',
        'requested_profile' => RecoveryClaim::NONE,
    ])['checkpoint_at'],
    'the none profile takes no checkpoint, so it publishes no instant however the caller dated the request'
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
duo_check_same(
    '2026-08-17T09:14:02Z',
    $frozen['recovery_profile']['checkpoint_at'],
    'the instant the claim no longer carries is on the frozen plan, one key away, for duo recover to print'
);
duo_check_same(
    (string) $document['plan_digest'],
    AuthorizationPlan::digest(array_replace(
        $document,
        ['recovery_profile' => array_replace($document['recovery_profile'], ['checkpoint_at' => '2099-01-01T00:00:00Z'])]
    )),
    'and it sits outside plan_digest, so re-dating it cannot re-identify the authorization'
);

// ------------------------------- what duo recover prints beside the claim
// The instant is not in the claim any more, so where `duo recover` gets it is
// now a decision with an order, and the order is the point: the receipt's own
// creation time is evidence about THIS checkpoint, the frozen plan's
// `checkpoint_at` is the release-start instant the operator was shown, and a
// full promotion receipt publishes no creation time at all
// (CheckpointCatalog::DISCLOSURE_NO_CREATION_TIME) — which is exactly the case
// the plan covers and the one no fixture with a `created_at` can reach.
$verifiedRow = [
    'artifact_hash' => (string) $document['artifact_hash'],
    'covers' => RecoveryClaim::RESOURCES,
    'created_at' => null,
    'kind' => CheckpointCatalog::KIND_VERIFIED,
];
$fromPlan = RecoverCommand::resolveClaim($verifiedRow, [$frozen]);
duo_check_same(
    RecoveryClaim::encode($selection['claim']),
    RecoveryClaim::encode($fromPlan['claim']),
    'a receipt matching a frozen plan is recovered under that plan claim, byte for byte'
);
duo_check_same(
    '2026-08-17T09:14:02Z',
    $fromPlan['checkpoint_at'],
    'a receipt that publishes no creation time is dated from the frozen plan instead of from nothing'
);
duo_check_same(
    RecoverCommand::SOURCE_PLAN,
    $fromPlan['checkpoint_source'],
    'and the printing says which of the two instants it is'
);
duo_check(
    str_contains(RecoverCommand::checkpointLine($fromPlan), 'release start, from the frozen authorization plan'),
    'the printed line names the plan as its source rather than implying the receipt published it'
);

$fromReceipt = RecoverCommand::resolveClaim(
    array_replace($verifiedRow, ['created_at' => '2026-08-17T09:31:44Z']),
    [$frozen]
);
duo_check_same(
    '2026-08-17T09:31:44Z',
    $fromReceipt['checkpoint_at'],
    'when the receipt does publish its creation time, that beats the plan: it is when this checkpoint was pinned'
);
duo_check_same(
    RecoveryClaim::encode($selection['claim']),
    RecoveryClaim::encode($fromReceipt['claim']),
    'and the claim is still the frozen plan claim, unchanged by which instant was printed beside it'
);

$unplanned = RecoverCommand::resolveClaim(
    array_replace($verifiedRow, ['artifact_hash' => str_repeat('7', 64)]),
    [$frozen]
);
duo_check_same(
    null,
    $unplanned['checkpoint_at'],
    'a receipt no frozen plan matches and no creation time carries no instant at all'
);
duo_check(
    str_contains(RecoverCommand::checkpointLine($unplanned), 'not published by this receipt'),
    'and the absence is printed rather than filled in with the wall clock or the plan of another release'
);
duo_check(
    !str_contains(RecoveryClaim::encode($unplanned['claim']), '2026'),
    'the claim rebuilt from a receipt is clock-free too, so it cannot re-date anything it is embedded in'
);

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

// ------------------------------------------- retained release checkpoints
// The second catalog source (round-3 T5): authenticated encrypted checkpoints
// every operator-directed promotion retains under .duo/checkpoints, read by
// one script and parsed into rows of the SAME key set, on every transport.
$script = RetainedCheckpoints::script('/srv/site');
duo_check(str_contains($script, "'/srv/site/.duo'"), 'the listing script is rooted at the repository .duo directory, quoted');
duo_check(
    str_contains($script, 'checkpoints/promote-*.sql.enc') && str_contains($script, 'checkpoints/deploy-*.sql.enc'),
    'the listing script enumerates exactly the promote-*.sql.enc and deploy-*.sql.enc checkpoints'
);
duo_check(
    !preg_match('~checkpoints/\*\.sql\.enc~', $script),
    'the prefix set stays closed: no bare *.sql.enc glob sweeps in materialize-<operation_id>.sql.enc'
);
duo_check(str_contains($script, 'artifacts/$b.json'), 'the listing script reads the sibling compiled artifact for the lease identity');
duo_check(str_contains($script, 'stat -c %Y') && str_contains($script, 'stat -f %m'), 'both stat dialects are tried');
duo_check(!str_contains($script, 'php '), 'the listing script needs no language runtime on the target: grep, stat, sed, basename');
duo_check(!str_contains($script, 'rm ') && !str_contains($script, '> "'), 'the listing script writes nothing');

$older = 'promote-20260817-091402-0123456789abcdef0123456789abcdef';
$newer = 'promote-20260817-101010-fedcba9876543210fedcba9876543210';
// The second writer: `duo deploy` retains deploy-<owner>.sql beside its own
// deploy-<owner>.json artifact, so the identity derivation is unchanged and
// only the prefix moves.
$deployed = 'deploy-20260817-095500-abcdefabcdefabcdefabcdefabcdefab';
$stdout = $older . "\t" . str_repeat('9', 64) . "\t1786958042\n"
    . $newer . "\t" . str_repeat('8', 64) . "\t1786961410\n"
    . $deployed . "\t" . str_repeat('7', 64) . "\t1786960000\n"
    . "promote-orphan\t\t1786950000\n";
$retained = RetainedCheckpoints::parse($stdout, '2026-08-17T10:20:10Z');
duo_check_same(4, count($retained), 'every non-empty checkpoint line becomes a row, including one with no artifact');
duo_check_same(
    [$newer, $deployed, $older, 'promote-orphan'],
    array_map(static fn (array $row): string => (string) $row['id'], $retained),
    'retained rows list newest first, promote and deploy rows in one order'
);
duo_check_same(
    array_keys($catalog['rows'][0]),
    array_keys($retained[0]),
    'a retained row carries exactly the key set an authority row carries'
);
duo_check_same(RetainedCheckpoints::KIND, $retained[0]['kind'], 'a retained row names its kind');
duo_check_same(RetainedCheckpoints::STATE, $retained[0]['state'], 'a retained row has the one state a plain file can have');
duo_check_same('20260817-101010-fedcba9876543210fedcba9876543210', $retained[0]['owner'], 'the owner is the file name without prefix and suffix — the lease owner promote used');
duo_check_same(str_repeat('8', 64), $retained[0]['artifact_hash'], 'the artifact hash is the retained compiled artifact identity');
duo_check_same('2026-08-17T10:10:10Z', $retained[0]['created_at'], 'created_at is the checkpoint file time in canonical UTC seconds');
duo_check_same(600, $retained[0]['age_seconds'], 'age is computed from the file time');
duo_check_same([RecoveryClaim::RESOURCE_DATABASE_CHECKPOINT], $retained[0]['covers'], 'a retained checkpoint covers the database checkpoint and nothing else');
duo_check_same(0, $retained[0]['generation'], 'a retained checkpoint has no signed generation');
duo_check_same(null, $retained[0]['event_chain_sha256'], 'a retained checkpoint has no event chain');
duo_check_same(true, $retained[0]['terminal'], 'nothing about a retained file is in progress');
duo_check_same('', $retained[3]['artifact_hash'], 'a checkpoint whose artifact is gone is listed with an empty identity, never dropped');
duo_check_same(
    '/srv/site/.duo/checkpoints/' . $newer . '.sql.enc',
    RetainedCheckpoints::checkpointPath('/srv/site/', $retained[0]),
    'the restore path is the file promote wrote'
);

// The deploy row: same kind, same key set, its own prefix — and a path that
// resolves. `prefix` is deliberately NOT a row key (the key-set assertion
// above is what forbids it), so the id is what carries the fact.
$deployRow = $retained[1];
duo_check_same(RetainedCheckpoints::KIND, $deployRow['kind'], 'a deploy checkpoint is the same catalog kind');
duo_check_same(
    array_keys($catalog['rows'][0]),
    array_keys($deployRow),
    'a retained deploy row carries exactly the key set an authority row carries'
);
duo_check_same(
    '20260817-095500-abcdefabcdefabcdefabcdefabcdefab',
    $deployRow['owner'],
    'the owner is the deploy file name without prefix and suffix — the lease owner deploy used'
);
duo_check_same(str_repeat('7', 64), $deployRow['artifact_hash'], 'the deploy lease identity comes from its sibling artifact');
duo_check_same(
    RetainedCheckpoints::DEPLOY_ID_PREFIX,
    RetainedCheckpoints::prefixForRow($deployRow),
    'prefixForRow reads the deploy prefix back off the retained row id'
);
duo_check_same(
    '/srv/site/.duo/checkpoints/' . $deployed . '.sql.enc',
    RetainedCheckpoints::checkpointPath('/srv/site/', $deployRow, RetainedCheckpoints::prefixForRow($deployRow)),
    'the restore path is the file deploy wrote'
);
duo_check_same(
    RetainedCheckpoints::ID_PREFIX,
    RetainedCheckpoints::prefixForRow($catalog['rows'][0]),
    'a signed receipt row still resolves through promote\'s prefix: its id is a receipt id, not a file name'
);
duo_check_refuses(
    static fn () => RetainedCheckpoints::parse("promote-x\tnothex\t1\n", '2026-08-17T10:20:10Z'),
    'checkpoint_listing_malformed',
    'a hash that is not 64 hex refuses rather than becoming a lease identity'
);
duo_check_refuses(
    static fn () => RetainedCheckpoints::parse("checkpoint-x\t\t1\n", '2026-08-17T10:20:10Z'),
    'checkpoint_listing_malformed',
    'a name carrying neither the promote- nor the deploy- prefix refuses'
);
duo_check_refuses(
    static fn () => RetainedCheckpoints::parse("materialize-abc123\t\t1\n", '2026-08-17T10:20:10Z'),
    'checkpoint_listing_malformed',
    'the environment materializer\'s own dump (cli/duo:2126, :2576) is refused by name rather than inventoried as restorable'
);
duo_check_refuses(
    static fn () => RetainedCheckpoints::parse("promote-a/b\t\t1\n", '2026-08-17T10:20:10Z'),
    'checkpoint_listing_malformed',
    'an owner that could become a path refuses'
);
duo_check_same([], RetainedCheckpoints::parse("\n\n", '2026-08-17T10:20:10Z'), 'blank output is an empty listing');

$merged = CheckpointCatalog::withRetained($catalog, $retained);
duo_check_same(5, count($merged['rows']), 'authority rows and retained rows are merged into one catalog');
duo_check_same('receipt-0012', $merged['rows'][0]['id'], 'the signed, in-progress facts stay first');
duo_check(
    in_array(RetainedCheckpoints::DISCLOSURE_RETAINED, $merged['disclosures'], true),
    'the merged catalog says what a retained checkpoint is and how it is restored'
);
duo_check_same(
    'retained release checkpoints are authenticated encrypted database checkpoints whose key is derived '
        . 'from target-local WordPress salts and is never stored in the site repository; restoring one drives '
        . 'the operator-directed path (abort, begin, isolated import, final abort)',
    RetainedCheckpoints::DISCLOSURE_RETAINED,
    'the disclosure names both verbs, so no listed row is mislabelled by the sentence that defines its kind'
);
duo_check(
    in_array(RetainedCheckpoints::DISCLOSURE_NO_IDENTITY, $merged['disclosures'], true),
    'one checkpoint without identity adds the no-identity disclosure'
);
duo_check_same($catalog, CheckpointCatalog::withRetained($catalog, []), 'no retained checkpoints leaves the authority catalog untouched');
$identified = CheckpointCatalog::withRetained($catalog, array_slice($retained, 0, 2));
duo_check(
    !in_array(RetainedCheckpoints::DISCLOSURE_NO_IDENTITY, $identified['disclosures'], true),
    'the no-identity disclosure appears only when a checkpoint actually lacks its artifact'
);
$mergedLines = implode("\n", CheckpointCatalog::humanLines($merged));
duo_check(str_contains($mergedLines, '  ' . $newer . '  retained  ' . RetainedCheckpoints::KIND . '  600s old'), 'a retained row prints id, state, kind and age');
duo_check(!str_contains($mergedLines, RetainedCheckpoints::KIND . '  generation'), 'a retained row prints no generation');
duo_check(!str_contains($mergedLines, str_repeat('8', 64)), 'the human listing prints no artifact hash');

duo_check_summary('regress_recover_claim');

<?php
/**
 * issue #3506 — the two `promotion-abort` refusals carry a stable reason code,
 * and their operator sentence does not move.
 *
 * `wprism recover <env> --restore=<older retained id> --writers-excluded` stopped
 * at step 1 with one constant, reasonless line because these two refusals were
 * bare `\RuntimeException`s: `Cli::halt_json_failure()` classified them
 * through its catch-all (agent/src/Command/Cli.php:79-94), so a machine caller
 * got `promotion_abort_failed` plus `details_redacted: true` and the actual,
 * DOCUMENTED reason (docs/guides/code-updates.md:207-209) never left the
 * target.
 *
 * Four properties, each with its own way of regressing:
 *
 *  1. The CODE. `abort()` refuses a superseded session with
 *     `promotion_abort_session_superseded` and a lease it does not own with
 *     `promotion_abort_lock_not_owned`. Reverting either throw to
 *     `\RuntimeException` fails here.
 *  2. The HUMAN BYTES. `getMessage()` is byte-identical to the sentence
 *     `WP_CLI::error($t->getMessage())` printed before, which
 *     `sandbox/tests/live/regress_promotion_lock.sh:99-107` greps on a real
 *     pair. Anyone who "improves" the operator wording breaks that live pin,
 *     and this suite says so offline first.
 *  3. The §5.2 BOUNDARY. The public payload is constant and value-free: the
 *     lease owner token and the 64-hex artifact hash live in the operator
 *     sentence only, because no documented command consumes either.
 *  4. The CATCH COMPATIBILITY. `CommandRefusalException extends
 *     \RuntimeException`, so every existing `catch (\RuntimeException)` and
 *     `catch (\Throwable)` around abort still classifies these identically.
 *
 * The idempotent successes are asserted beside them: this change must not turn
 * a cleanup that legitimately finds nothing into a refusal.
 *
 * Offline: no WordPress, no MySQL. `PromotionLease::abort()` runs for real
 * against `FakeWpdb`, whose advisory-lock/connection model is what carries
 * `ProcessFence` (agent/src/Kernel/ProcessFence.php:27,54-58) without a
 * server. The DELETE that a MATCHING lease row takes is deliberately not
 * exercised here — it is a `JSON_EXTRACT` statement the interpreter refuses on
 * purpose, and `regress_promotion_unit.sh` plus the live pin own that path.
 */
declare(strict_types=1);

// From offline/<domain>/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';

require_once __DIR__ . '/../../../../agent/src/Kernel/CommandRefusal.php';
require_once __DIR__ . '/../../../../agent/src/Promotion/PromotionLease.php';
require_once __DIR__ . '/../../../../agent/src/Promotion/PromotionLock.php';
require_once __DIR__ . '/../../../../agent/src/Repository/Ledger.php';

use WPrism\CommandRefusalException;
use WPrism\PromotionLease;
use WPrism\PromotionLock;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

/** The abort this operator is trying to run: an older release's cleanup. */
const OBSOLETE_OWNER = 'promote-obsolete-checkpoint-owner';
const OBSOLETE_ARTIFACT = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

/** The release that came after it and now owns the target. */
const LATER_OWNER = 'promote-later-release-owner';
const LATER_ARTIFACT = '0f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c4b5a69788796a5b4c3d2e1f0';

/**
 * One `wprism_kv` store, rebuilt per case.
 *
 * `Ledger::kv_get()` reads `SELECT v FROM {prefix}wprism_kv WHERE k = %s`
 * (agent/src/Repository/Ledger.php:552-556), so seeding rows is the whole
 * fixture: the lease row under `promotion_lock` and the durable session row
 * under `promotion_session`.
 *
 * @param array<string,mixed> $rows key => decoded payload
 */
function abort_reason_seed(array $rows): FakeWpdb {
    WpStore::reset();
    $wpdb = FakeWpdb::install();
    $wpdb->setLockResult(1);
    $wpdb->seedTable('wp_wprism_kv', array_values(array_map(
        static fn(string $key, array $payload): array => [
            'k' => $key,
            'v' => json_encode($payload, JSON_UNESCAPED_SLASHES),
        ],
        array_keys($rows),
        array_values($rows)
    )));

    return $wpdb;
}

/** The durable `promotion_session` row a later `promotion-begin` wrote. */
function abort_reason_session(string $owner, string $artifact): array {
    return [
        'artifact_hash' => $artifact,
        'begun_at' => 1755000000,
        'owner' => $owner,
        'session_id' => 'ps-' . str_repeat('ab', 16),
    ];
}

/** The live `promotion_lock` row a promotion holds while it runs. */
function abort_reason_lease(string $owner, string $artifact): array {
    return [
        'acquired_at' => 1755000000,
        'artifact_hash' => $artifact,
        'expires_at' => 1755003600,
        'owner' => $owner,
        'phase' => 'deploy',
    ];
}

/**
 * Run one abort and return the refusal it threw, asserting the type on the
 * way past. A `\RuntimeException` that is not a `CommandRefusalException` is
 * exactly the prior defect, so it is reported as such rather than as a
 * missing-property failure three assertions later.
 */
function abort_reason_refusal(string $owner, string $artifact, string $case): ?CommandRefusalException {
    try {
        PromotionLease::abort($owner, $artifact);
    } catch (CommandRefusalException $refusal) {
        wprism_check(true, "$case refuses with a typed, machine-readable refusal");

        return $refusal;
    } catch (\Throwable $other) {
        wprism_check(false, "$case threw " . get_class($other) . ' instead of CommandRefusalException');

        return null;
    }
    wprism_check(false, "$case did not refuse at all");

    return null;
}

// ------------------------------------------------ a superseded abort session
// The lease row is already gone (the later release's own completion removed
// it), so `abort()` reaches `assert_abort_session()` through the
// already-absent branch (agent/src/Promotion/PromotionLease.php:875-883). The
// durable session row names the LATER release, which is what makes this
// obsolete checkpoint an unsafe recovery source.
abort_reason_seed(['promotion_session' => abort_reason_session(LATER_OWNER, LATER_ARTIFACT)]);

$superseded = abort_reason_refusal(OBSOLETE_OWNER, OBSOLETE_ARTIFACT, 'a superseded abort');
if ($superseded !== null) {
    wprism_check_same(
        'promotion_abort_session_superseded',
        $superseded->reasonCode,
        'the superseded abort names promotion_abort_session_superseded'
    );
    wprism_check_same(
        'promotion abort refused: a newer promotion session superseded the one this abort names',
        $superseded->publicMessage,
        'its public message states the property without naming the session'
    );
    wprism_check_same(
        'restore or recover the release that owns the latest begun promotion session; an obsolete checkpoint '
            . 'is not a safe recovery source, so recover this target through the provider that owns its backups '
            . 'instead',
        $superseded->remediation,
        'its remediation sends the operator to the provider that owns the backups'
    );
    // The live pin greps this sentence on a real pair
    // (sandbox/tests/live/regress_promotion_lock.sh:105). It must not move.
    wprism_check_same(
        "wprism: promotion abort refused; the latest begun session belongs to '" . LATER_OWNER . "' "
            . "for artifact '" . LATER_ARTIFACT . "'",
        $superseded->getMessage(),
        'getMessage() is byte-identical to the operator sentence WP_CLI::error() prints'
    );
    wprism_check(
        $superseded instanceof \RuntimeException,
        'it is still a \RuntimeException, so every existing catch around abort behaves identically'
    );
    wprism_check_same(false, $superseded->detailsRedacted, 'the reviewed public fields survive the class screen');
}

// ------------------------------------------------------ the §5.2 boundary
// The reason the operator sentence cannot simply be republished: it carries a
// lease owner token and a 64-hex artifact hash, and MUP §5.2 admits an
// internal identifier into a public view only when a documented command
// consumes it. `wprism recover` publishes the payload below and nothing else.
if ($superseded !== null) {
    $payload = $superseded->payload();
    $published = json_encode($payload, JSON_UNESCAPED_SLASHES);
    wprism_check_same(
        'promotion_abort_session_superseded',
        $payload['error'] ?? null,
        'the published payload carries the reason code'
    );
    wprism_check(
        !str_contains((string) $published, LATER_OWNER) && !str_contains((string) $published, OBSOLETE_OWNER),
        'no lease owner token reaches the published payload'
    );
    wprism_check(
        preg_match('/(?<![0-9a-f])[0-9a-f]{64}(?![0-9a-f])/', (string) $published) !== 1,
        'no 64-hex artifact hash reaches the published payload'
    );
    wprism_check_same(
        false,
        CommandRefusalException::containsSensitivePublicDetail($payload),
        'the payload passes the same screen Cli.php applies at the serialization boundary'
    );
    wprism_check(
        !array_key_exists('details_redacted', $payload),
        'the refusal is classified, so it is not the redacted catch-all this used to fall into'
    );
}

// ---------------------------------------------------- a lease owned by another
// The other half of the same gate: a live lease row that belongs to a
// different owner/artifact (agent/src/Promotion/PromotionLease.php:885).
abort_reason_seed([
    'promotion_lock' => abort_reason_lease(LATER_OWNER, LATER_ARTIFACT),
    'promotion_session' => abort_reason_session(LATER_OWNER, LATER_ARTIFACT),
]);

$notOwned = abort_reason_refusal(OBSOLETE_OWNER, OBSOLETE_ARTIFACT, 'an abort of a lease held by another release');
if ($notOwned !== null) {
    wprism_check_same(
        'promotion_abort_lock_not_owned',
        $notOwned->reasonCode,
        'the foreign-lease abort names promotion_abort_lock_not_owned'
    );
    wprism_check_same(
        'promotion abort refused: the promotion lease on this target belongs to a different owner and artifact',
        $notOwned->publicMessage,
        'its public message states the property without naming the holder'
    );
    wprism_check_same(
        'release the exact recorded lease through the release that holds it, or restore its database '
            . 'checkpoint, before aborting again',
        $notOwned->remediation,
        'its remediation names the two ways the lease legitimately goes away'
    );
    wprism_check_same(
        "wprism: promotion abort refused; lock belongs to '" . LATER_OWNER . "' for artifact '" . LATER_ARTIFACT . "'",
        $notOwned->getMessage(),
        'getMessage() is byte-identical to the operator sentence WP_CLI::error() prints'
    );
    wprism_check_same(
        false,
        CommandRefusalException::containsSensitivePublicDetail($notOwned->payload()),
        'the foreign-lease payload is value-free too'
    );
}

// --------------------------------------------- the idempotent successes stand
// abort() treats an already-absent MATCHING lease as success, and that is what
// makes it safe as compensating cleanup. Neither reason code may be reachable
// from a cleanup that is simply late.
abort_reason_seed([]);
$noSession = PromotionLock::abort(OBSOLETE_OWNER, OBSOLETE_ARTIFACT);
wprism_check_same(true, $noSession['already_absent'], 'an abort with no lease and no session is still idempotent');
wprism_check_same(false, $noSession['released'], 'nothing was released, because nothing was there');

abort_reason_seed(['promotion_session' => abort_reason_session(OBSOLETE_OWNER, OBSOLETE_ARTIFACT)]);
$ownSession = PromotionLock::abort(OBSOLETE_OWNER, OBSOLETE_ARTIFACT);
wprism_check_same(
    true,
    $ownSession['already_absent'],
    'an abort whose own session is still the latest begun one succeeds, as before'
);

// ------------------------------------------------- the codes stay greppable
// Both codes appear verbatim in the operator-facing guides, because a reason
// code an operator cannot look up is only marginally better than none.
$guides = dirname(__DIR__, 4) . '/docs/guides/capabilities-and-limits.md';
$guideText = (string) file_get_contents($guides);
foreach (['promotion_abort_session_superseded', 'promotion_abort_lock_not_owned'] as $code) {
    wprism_check(
        str_contains($guideText, $code),
        "docs/guides/capabilities-and-limits.md carries a refusal-to-remedy row for $code"
    );
}

wprism_check_summary('promotion abort reason codes (issue #3506)');

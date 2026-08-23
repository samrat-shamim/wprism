<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once __DIR__ . '/RecoveryClaim.php';
require_once __DIR__ . '/RetainedCheckpoints.php';
require_once __DIR__ . '/RollbackAuthority.php';

use Duo\CommandRefusalException;

/**
 * What checkpoints and receipts a target actually holds — the read-only
 * listing behind `duo recover <env> --list` (round-3 MUP §2.5).
 *
 * ## Read-only, and only what the runtime publishes
 *
 * Every fact here comes from `RollbackAuthority::status()` /
 * `::scopedStatus()` / `::audit()`, which are the three actions
 * `recovery/rollback-control.php` exposes for reading. This class signs
 * nothing, claims nothing and mutates nothing; it is the honest inventory an
 * operator reads before choosing `--restore=<id>`.
 *
 * ## The disclosure that makes the listing honest
 *
 * The rollback runtime is a single-active-generation state machine:
 * `target.json` names one `active_receipt`, and `auditEvidence()` refuses
 * outright when there is none. There is therefore no history to list — a
 * "catalog" that printed one row while looking like a log would teach an
 * operator that older checkpoints exist and are selectable. Every catalog
 * consequently carries `disclosures[]`, and `DISCLOSURE_ACTIVE_ONLY` is on
 * every non-empty one. That is the same rule §2.4 applies to undeclared
 * journeys: the absence is printed, not implied.
 *
 * Two further absences are stated rather than smoothed over:
 *
 *  - **`created_at` is null for a full (non-scoped) receipt.** The verified
 *    status document publishes `claim_expires_at`, `claim_ttl_seconds` and
 *    `retention_until`, but no creation timestamp — only the scoped receipt
 *    carries `created_at` (recovery/rollback-control.php,
 *    `scopedStatusFromVerified()`). Deriving one from the claim epoch would
 *    be fabrication: keepalive moves that epoch. The row therefore reports
 *    `created_at: null`, `age_seconds: null` and prints `claim_expires_at`
 *    instead, which is a real time anchor.
 *  - **`covers[]` is derived from the receipt's own evidence keys**, not from
 *    the profile the operator asked for. A verified receipt covers the code
 *    release / upload bundle / effect bundle only when its status carries
 *    that evidence; a scoped receipt covers the database checkpoint and
 *    nothing else, because `ScopedRollbackProfile` never prepares the other
 *    providers.
 *
 * ## The second source: retained release checkpoints
 *
 * The signed authority is one source of rows; the plain database checkpoints
 * every operator-directed promotion retains under `.duo/checkpoints/` are
 * the other (`RetainedCheckpoints`). They are the only rows a local, docker
 * or plain SSH target has, and they are what the operator-directed claim in
 * every frozen authorization plan on those transports refers to. `list()`
 * therefore reads the authority only where one can exist (an SSH transport)
 * and the retained checkpoints everywhere, and says which of the two it
 * could not read rather than printing an empty table.
 */
final class CheckpointCatalog {
    public const FORMAT = 'duo-checkpoint-catalog/v1';

    /** A full promotion receipt: the four-resource covered inventory. */
    public const KIND_VERIFIED = 'verified-promotion';

    /** A scoped promotion receipt: checkpoint-only by construction. */
    public const KIND_SCOPED = 'scoped-promotion';

    /**
     * The receipt states MUP §2.5 names for `--list`, in lifecycle order.
     *
     * @var list<string>
     */
    public const STATES = [
        'prepared', 'promoting', 'verifying_new', 'committed',
        'rollback_pending', 'rolling_back', 'verifying_prior', 'rolled_back',
    ];

    public const DISCLOSURE_ACTIVE_ONLY =
        'the rollback authority holds one generation at a time: this is the active receipt, not a history';

    public const DISCLOSURE_UNAVAILABLE =
        'the target publishes no rollback authority runtime, so it holds no checkpoint this command can list';

    public const DISCLOSURE_NONE_ACTIVE =
        'the rollback authority is available and holds no active receipt';

    public const DISCLOSURE_NO_CREATION_TIME =
        'a full promotion receipt publishes no creation timestamp; claim_expires_at is shown instead of an age';

    public const DISCLOSURE_INVALID =
        'the rollback authority answered, but its evidence did not verify; treat this target as unknown '
        . 'and inspect private operator evidence before recovering';

    public const DISCLOSURE_NO_AUTHORITY_TRANSPORT =
        'this transport carries no rollback authority runtime, so only the database checkpoints its releases '
        . 'retained are listed';

    /**
     * Read the catalog from a target.
     *
     * `status()` is used rather than `authorityStatus()` because the
     * operator listing wants the same decorated view `duo promote` acts on;
     * `audit()` is read only when a receipt is active, because
     * `auditEvidence()` throws when there is none.
     *
     * @return array<string,mixed>
     */
    public static function list(EnvironmentDriver $driver, ?string $now = null): array {
        $now ??= gmdate('Y-m-d\TH:i:s\Z');
        // Widened with the promote dispatch (cli/duo) so the listing an
        // operator reads names the same authority `duo promote` acts on. The
        // carriesRollbackAuthority() half keeps `duo recover --list`
        // byte-identical for every environment that never configured one.
        if ($driver instanceof RecoveryTransport && $driver->carriesRollbackAuthority()) {
            $status = RollbackAuthority::status($driver);
            $audit = null;
            if (($status['available'] ?? false) === true
                && ($status['ok'] ?? false) === true
                && ($status['active'] ?? false) === true) {
                try {
                    $audit = RollbackAuthority::audit($driver);
                } catch (\Throwable $failure) {
                    // A missing or refused audit does not invalidate the status
                    // read; it only means the event-chain digest is unavailable
                    // for this listing. Losing the whole catalog over it would
                    // be worse than printing the row without that column.
                    $audit = null;
                }
            }
            $catalog = self::fromStatus($status, $audit, $now);
        } else {
            $catalog = [
                'disclosures' => [self::DISCLOSURE_NO_AUTHORITY_TRANSPORT],
                'format' => self::FORMAT,
                'rows' => [],
            ];
        }

        return self::withRetained($catalog, RetainedCheckpoints::list($driver, $now));
    }

    /**
     * Append the retained release checkpoints to an authority catalog. Pure.
     *
     * Authority rows stay first (they are the signed, in-progress facts an
     * operator must see before any plain file); retained rows follow in the
     * order `RetainedCheckpoints::parse()` fixed. The retained disclosure is
     * added only when there is at least one such row, and the no-identity
     * disclosure only when one of them cannot be restored.
     *
     * @param array<string,mixed> $catalog a `fromStatus()` result
     * @param list<array<string,mixed>> $retained `RetainedCheckpoints` rows
     * @return array<string,mixed>
     */
    public static function withRetained(array $catalog, array $retained): array {
        if ($retained === []) {
            return $catalog;
        }
        $disclosures = is_array($catalog['disclosures'] ?? null) ? array_values($catalog['disclosures']) : [];
        $disclosures[] = RetainedCheckpoints::DISCLOSURE_RETAINED;
        // Unconditional beside the retained disclosure, because the host
        // cannot tell which rows it applies to: `promotion_session` is
        // target-side state no host verb reads (DUO-3506). A per-row marker
        // would be a fabrication; a note beside the list is the honest form.
        $disclosures[] = RetainedCheckpoints::DISCLOSURE_SUPERSEDED;
        foreach ($retained as $row) {
            if ((string) ($row['artifact_hash'] ?? '') === '') {
                $disclosures[] = RetainedCheckpoints::DISCLOSURE_NO_IDENTITY;
                break;
            }
        }

        return [
            'disclosures' => $disclosures,
            'format' => self::FORMAT,
            'rows' => array_merge(
                is_array($catalog['rows'] ?? null) ? array_values($catalog['rows']) : [],
                $retained
            ),
        ];
    }

    /**
     * Build the catalog from already-read evidence. Pure.
     *
     * @param ?array<string,mixed> $status a `RollbackAuthority::status()` result
     * @param ?array<string,mixed> $audit a `RollbackAuthority::audit()` result, or null
     * @param string $now canonical UTC seconds, used only for `age_seconds`
     * @return array<string,mixed> keys: format, disclosures, rows
     */
    public static function fromStatus(?array $status, ?array $audit, string $now): array {
        self::assertTimestamp($now, 'now');
        $disclosures = [];
        $rows = [];

        if ($status === null || ($status['available'] ?? false) !== true) {
            $disclosures[] = self::DISCLOSURE_UNAVAILABLE;
        } elseif (($status['ok'] ?? false) !== true) {
            $disclosures[] = self::DISCLOSURE_INVALID;
        } elseif (($status['active'] ?? false) !== true) {
            $disclosures[] = self::DISCLOSURE_NONE_ACTIVE;
        } else {
            $rows[] = self::row($status, $audit, $now);
            $disclosures[] = self::DISCLOSURE_ACTIVE_ONLY;
            if ($rows[0]['created_at'] === null) {
                $disclosures[] = self::DISCLOSURE_NO_CREATION_TIME;
            }
        }

        return [
            'disclosures' => $disclosures,
            'format' => self::FORMAT,
            'rows' => $rows,
        ];
    }

    /**
     * One catalog row for one active receipt. Pure.
     *
     * @param array<string,mixed> $status
     * @param ?array<string,mixed> $audit
     * @return array<string,mixed>
     */
    public static function row(array $status, ?array $audit, string $now): array {
        $scoped = array_key_exists('receipt_format', $status) || array_key_exists('scope_hash', $status);
        $createdAt = is_string($status['created_at'] ?? null) && $status['created_at'] !== ''
            ? (string) $status['created_at']
            : null;
        $state = (string) ($status['state'] ?? '');
        if ($state !== '' && !in_array($state, self::STATES, true)) {
            throw new CommandRefusalException(
                'checkpoint_state_unknown',
                'the rollback authority reported a receipt state this build does not know',
                'upgrade the host orchestrator to the build that matches this target runtime before recovering',
                [['state' => $state]]
            );
        }

        return [
            'age_seconds' => $createdAt === null ? null : self::ageSeconds($createdAt, $now),
            'artifact_hash' => (string) ($status['artifact_hash'] ?? ''),
            'claim_expires_at' => is_string($status['claim_expires_at'] ?? null)
                ? (string) $status['claim_expires_at']
                : null,
            'covers' => self::covers($status, $scoped),
            'created_at' => $createdAt,
            'event_chain_sha256' => is_array($audit) && is_string($audit['event_chain_sha256'] ?? null)
                ? (string) $audit['event_chain_sha256']
                : null,
            'generation' => (int) ($status['generation'] ?? 0),
            'id' => (string) ($status['receipt_id'] ?? ''),
            'kind' => $scoped ? self::KIND_SCOPED : self::KIND_VERIFIED,
            'owner' => (string) ($status['owner'] ?? ''),
            'retention_until' => is_string($status['retention_until'] ?? null)
                ? (string) $status['retention_until']
                : null,
            'state' => $state,
            'terminal' => ($status['terminal'] ?? false) === true,
        ];
    }

    /** Find one row by receipt id, or null. @param array<string,mixed> $catalog */
    public static function find(array $catalog, string $receiptId): ?array {
        foreach (is_array($catalog['rows'] ?? null) ? $catalog['rows'] : [] as $row) {
            if (is_array($row) && (string) ($row['id'] ?? '') === $receiptId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Bounded human listing (MUP §4.6).
     *
     * @param array<string,mixed> $catalog
     * @return list<string>
     */
    public static function humanLines(array $catalog, int $limit = 50): array {
        if ($limit < 1) {
            throw new \InvalidArgumentException('checkpoint catalog listing limit must be at least 1');
        }
        $rows = is_array($catalog['rows'] ?? null) ? array_values($catalog['rows']) : [];
        $lines = [];
        if ($rows === []) {
            $lines[] = 'checkpoints: none';
        } else {
            $lines[] = 'checkpoints: ' . count($rows);
            foreach (array_slice($rows, 0, $limit) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $age = $row['age_seconds'] === null
                    ? 'expires ' . (string) ($row['claim_expires_at'] ?? 'unknown')
                    : ((int) $row['age_seconds']) . 's old';
                // The lease owner is deliberately absent from this line.
                // MUP §5.2: a human view may print an internal identifier only
                // when a documented command consumes it, and nothing consumes
                // the owner — `--restore=<id>` takes the receipt id, which is
                // the one identifier on this row. In production the owner is an
                // opaque token (cli/duo's orchestrator_run_id(), or the
                // `scoped-`/`verified-`/`direct-` forms), so printing it taught
                // an operator a name they can only mistype. It stays in
                // `--format=json`, alongside `artifact_hash`, which this line
                // already omits for the same reason.
                // A retained checkpoint has no signed generation to print.
                $lines[] = '  ' . (string) $row['id'] . '  ' . (string) $row['state']
                    . '  ' . (string) $row['kind']
                    . (RetainedCheckpoints::isRetained($row) ? '' : '  generation ' . (string) $row['generation'])
                    . '  ' . $age;
                $lines[] = '    covers: ' . implode(', ', array_map('strval', (array) $row['covers']));
            }
            if (count($rows) > $limit) {
                $lines[] = '  ' . (count($rows) - $limit) . ' more (use --format=json)';
            }
        }
        foreach (is_array($catalog['disclosures'] ?? null) ? $catalog['disclosures'] : [] as $disclosure) {
            $lines[] = 'note: ' . (string) $disclosure;
        }

        return $lines;
    }

    /**
     * The covered inventory, derived from the receipt's own evidence.
     *
     * @param array<string,mixed> $status
     * @return list<string>
     */
    private static function covers(array $status, bool $scoped): array {
        $covers = [];
        if (($status['checkpoint_sha256'] ?? '') !== '') {
            $covers[] = RecoveryClaim::RESOURCE_DATABASE_CHECKPOINT;
        }
        if ($scoped) {
            // ScopedRollbackProfile "never prepares/releases code, uploads,
            // lifecycle, or effect providers" (that class's docblock), so no
            // other evidence can legitimately appear on this receipt.
            return $covers;
        }
        if (is_string($status['code_release_metadata_sha256'] ?? null)
            && $status['code_release_metadata_sha256'] !== '') {
            $covers[] = RecoveryClaim::RESOURCE_CODE_RELEASE;
        }
        if (($status['uploads_inventory_sha256'] ?? '') !== '') {
            $covers[] = RecoveryClaim::RESOURCE_UPLOAD_BUNDLE;
        }
        if (($status['lifecycle_receipts_sha256'] ?? '') !== '') {
            $covers[] = RecoveryClaim::RESOURCE_EFFECT_BUNDLE;
        }

        return $covers;
    }

    private static function ageSeconds(string $createdAt, string $now): int {
        $age = self::assertTimestamp($now, 'now') - self::assertTimestamp($createdAt, 'created_at');

        return $age < 0 ? 0 : $age;
    }

    private static function assertTimestamp(string $value, string $label): int {
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if (!$time || $time->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new CommandRefusalException(
                'checkpoint_timestamp_invalid',
                "the checkpoint catalog $label is not canonical UTC seconds",
                'inspect private operator evidence on the target rollback runtime before recovering'
            );
        }

        return $time->getTimestamp();
    }
}

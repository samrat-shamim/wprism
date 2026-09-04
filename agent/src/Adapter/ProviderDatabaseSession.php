<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/../Kernel/DatabaseExceptions.php';
require_once __DIR__ . '/../Kernel/DatabaseQueryIsolation.php';
require_once __DIR__ . '/../Kernel/NativeDatabaseProfile.php';
require_once __DIR__ . '/../Kernel/TransactionAuthority.php';
if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/../Kernel/Db.php';
}

/** A repeatable provider write was positively classified as not applied. */
final class ProviderDatabaseTransactionNotAppliedException extends \RuntimeException {
    public string $transactionContext;

    public function __construct(string $context, ?\Throwable $previous = null) {
        $this->transactionContext = $context;
        parent::__construct(
            "wprism: provider database transaction did not publish its physical postimage: $context",
            0,
            $previous
        );
    }
}

/**
 * Engine-owned transaction boundary for digest-bound provider behavior.
 *
 * Providers own the native calls and the physical postimage that gives those
 * calls meaning. Db owns the exact server session, one-shot isolation control,
 * transaction witness, and terminal outcome proof. This class joins the two:
 * a provider never emits transaction SQL, and an ambiguous write is accepted
 * only when a server-enforced read-only callback identifies the complete
 * applied postimage. A positively unchanged preimage is separately surfaced as
 * retry-safe; every partial or unreadable state remains recovery-required.
 */
final class ProviderDatabaseSession {
    public const POSTIMAGE_APPLIED = 'applied';
    public const POSTIMAGE_NOT_APPLIED = 'not_applied';
    public const POSTIMAGE_UNKNOWN = 'unknown';

    /**
     * Run checked provider reads in one server-enforced read-only snapshot.
     *
     * @template T
     * @param callable():T $read
     * @return T
     */
    public static function read_only_snapshot(
        string $context,
        NativeDatabaseProfile $profile,
        callable $read
    ): mixed {
        self::assert_context($context);
        if (!$profile->is_read_only()) {
            throw new \InvalidArgumentException(
                'wprism: provider read-only snapshot received a mutation-table profile'
            );
        }
        Db::start_read_only_consistent_snapshot($context . ' start', $profile);
        $authority = Db::transaction_authority($context . ' initial authority');
        try {
            $result = (static function () use ($read, $authority, $context): mixed {
                $result = $read();
                try {
                    self::assert_callback_continuity($authority, $context . ' callback');
                } catch (\Throwable $failure) {
                    throw new DatabaseTransactionOutcomeException(
                        $context . ' read callback changed its transaction boundary',
                        $failure
                    );
                }
                return $result;
            })();
        } catch (\Throwable $failure) {
            self::settle_no_write_failure($failure, $context . ' rollback');
            throw $failure;
        }

        try {
            Db::rollback($context . ' close');
        } catch (\Throwable $failure) {
            // READ ONLY is the positive fact that permits an uncertain terminal
            // response to be settled without a plugin-shaped postimage. No
            // provider DML could have crossed this transaction boundary.
            self::settle_no_write_failure($failure, $context . ' close');
        }
        return $result;
    }

    /**
     * Run one provider mutation in an authority-tracked repeatable-read snapshot.
     *
     * The classifier is reached only after COMMIT crossed an uncertain outcome
     * boundary and the original transaction is positively inactive (or its
     * physical session was replaced by an idle one). It runs inside a new
     * server-enforced read-only snapshot and must return exactly one POSTIMAGE_*
     * constant. APPLIED returns the callback result, NOT_APPLIED raises the one
     * retry-safe type, and UNKNOWN refuses with recovery_required.
     *
     * @template T
     * @param callable():T $write
     * @param callable(T):string $classifyPhysicalPostimage
     * @return T
     */
    public static function repeatable_read_write(
        string $context,
        NativeDatabaseProfile $profile,
        callable $write,
        callable $classifyPhysicalPostimage
    ): mixed {
        self::assert_context($context);
        if ($profile->is_read_only()) {
            throw new \InvalidArgumentException(
                'wprism: provider mutation requires at least one declared write table'
            );
        }
        Db::start_consistent_snapshot($context . ' start', $profile);
        $authority = Db::transaction_authority($context . ' initial authority');
        try {
            $result = $write();
            self::assert_callback_continuity($authority, $context . ' callback');
        } catch (\Throwable $failure) {
            self::rollback_write_failure($failure, $context . ' rollback');
            throw $failure;
        }

        try {
            Db::commit($context . ' commit');
            return $result;
        } catch (DatabaseTransactionOutcomeException $uncertain) {
            return self::classify_uncertain_write(
                $context,
                $profile,
                $result,
                $classifyPhysicalPostimage,
                $uncertain
            );
        } catch (\Throwable $notCommitted) {
            try {
                Db::rollback_after_failure($notCommitted, $context . ' refused-commit rollback');
            } catch (DatabaseTransactionOutcomeException $uncertain) {
                return self::classify_uncertain_write(
                    $context,
                    $profile,
                    $result,
                    $classifyPhysicalPostimage,
                    $uncertain
                );
            }
            throw $notCommitted;
        }
    }

    private static function settle_no_write_failure(\Throwable $failure, string $context): void {
        if ($failure instanceof DatabaseQueryIsolationViolationException
            || $failure->getPrevious() instanceof DatabaseQueryIsolationViolationException) {
            // ProviderSdk retains its value-free checked-read diagnostic and
            // chains the query gate as the private cause. Treat that exact
            // engine cause like a direct gate violation so rollback enters
            // the poisoned boundary's one cleanup permit instead of trying an
            // ordinary state read which the gate must reject.
            Db::rollback_after_failure($failure, $context);
            return;
        }
        try {
            $active = Db::transaction_active($context . ' authority');
        } catch (\Throwable) {
            try {
                $currentActive = Db::connection_transaction_active($context . ' current session');
            } catch (\Throwable) {
                throw new DatabaseTransactionOutcomeException(
                    $context . ' could not prove a closed read-only session',
                    $failure
                );
            }
            if ($currentActive) {
                throw new DatabaseTransactionOutcomeException(
                    $context . ' found an unauthorised active replacement session',
                    $failure
                );
            }
            // A disconnected original session cannot retain its read-only
            // transaction; the observed replacement is idle, so no cleanup SQL
            // may be sent under the lost authority.
            Db::forget_transaction_tracking();
            return;
        }
        if ($active) {
            Db::rollback_after_failure($failure, $context);
            return;
        }
        Db::forget_transaction_tracking();
    }

    private static function rollback_write_failure(\Throwable $failure, string $context): void {
        Db::rollback_after_failure($failure, $context);
    }

    /**
     * @template T
     * @param T $result
     * @param callable(T):string $classifier
     * @return T
     */
    private static function classify_uncertain_write(
        string $context,
        NativeDatabaseProfile $profile,
        mixed $result,
        callable $classifier,
        DatabaseTransactionOutcomeException $uncertain
    ): mixed {
        try {
            $active = Db::transaction_active($context . ' uncertain-commit authority');
        } catch (\Throwable) {
            try {
                $currentActive = Db::connection_transaction_active($context . ' uncertain-commit current session');
            } catch (\Throwable) {
                throw new DatabaseTransactionOutcomeException(
                    $context . ' could not establish a clean postimage-classification session',
                    $uncertain
                );
            }
            if ($currentActive) {
                throw new DatabaseTransactionOutcomeException(
                    $context . ' found an unauthorised active replacement session after COMMIT',
                    $uncertain
                );
            }
            // The old physical session was replaced and therefore cannot retain
            // an InnoDB transaction. The idle replacement may observe the
            // durable postimage, but must never receive cleanup for the old one.
            Db::forget_transaction_tracking();
        }
        if (isset($active)) {
            if ($active) {
                Db::rollback_after_failure($uncertain, $context . ' uncertain-commit rollback');
                throw new ProviderDatabaseTransactionNotAppliedException($context, $uncertain);
            }
            Db::forget_transaction_tracking();
        }

        try {
            $classification = self::read_only_snapshot(
                $context . ' physical postimage',
                NativeDatabaseProfile::read_only(array_merge(
                    $profile->read_tables(),
                    $profile->write_tables()
                )),
                static fn(): mixed => $classifier($result)
            );
        } catch (\Throwable) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' physical postimage classification failed',
                $uncertain
            );
        }
        if ($classification === self::POSTIMAGE_APPLIED) {
            return $result;
        }
        if ($classification === self::POSTIMAGE_NOT_APPLIED) {
            throw new ProviderDatabaseTransactionNotAppliedException($context, $uncertain);
        }
        throw new DatabaseTransactionOutcomeException(
            $context . ' physical postimage is neither completely applied nor unchanged',
            $uncertain
        );
    }

    private static function assert_context(string $context): void {
        if ($context === '' || strlen($context) > 160 || preg_match('/[\x00-\x1f\x7f]/', $context) === 1) {
            throw new \InvalidArgumentException('wprism: provider database session context is malformed');
        }
    }

    private static function assert_callback_continuity(
        TransactionAuthority $authority,
        string $context
    ): void {
        if (!$authority->equals(Db::transaction_authority($context . ' continuity'))) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' changed database session authority'
            );
        }
    }

}

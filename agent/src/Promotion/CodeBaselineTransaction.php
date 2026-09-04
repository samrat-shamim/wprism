<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/../Kernel/LockedOptionRows.php';
require_once __DIR__ . '/../Kernel/TransactionalTableBoundary.php';
require_once __DIR__ . '/../Kernel/TransactionAuthority.php';
if (!class_exists(Ledger::class, false)) {
    require_once __DIR__ . '/../Repository/Ledger.php';
}
require_once __DIR__ . '/CodeLifecycleObservation.php';

/** Shared lock/order/publication boundary for every code-baseline writer. */
final class CodeBaselineTransaction {
    public const BASELINE_KEY = 'code_versions';
    public const RECEIPT_KEY = 'code_baseline_acceptance';

    private function __construct(
        private readonly array $desired,
        private readonly TransactionAuthority $authority,
        private readonly string $optionIndex,
        private readonly string $kvIndex,
        private readonly ?string $receiptRaw,
        private readonly ?string $baselineRaw,
        private readonly array $observation
    ) {}

    public static function lock_current(
        array $desired,
        TransactionAuthority $authority,
        string $purpose
    ): self {
        global $wpdb;
        $kvTable = $wpdb->prefix . 'wprism_kv';
        // Db::start_repeatable_read() has already retained MDL and proven the
        // full read/write profile for this transaction. This layer owns only
        // the key-specific locking indexes and semantic row witnesses.
        $optionIndex = TransactionalTableBoundary::full_width_unique_lock_index(
            $wpdb->options,
            'option_name',
            $authority,
            $purpose . ' options'
        );
        $kvIndex = TransactionalTableBoundary::full_width_unique_lock_index(
            $kvTable,
            'k',
            $authority,
            $purpose . ' ledger'
        );

        // This sequence is shared, not convention: every writer acquires the
        // three lifecycle rows before the two lexical durable keys.
        $optionRows = LockedOptionRows::read_required(
            CodeLifecycleObservation::option_names(),
            $optionIndex,
            $authority,
            $purpose
        );
        $receiptRaw = Ledger::kv_get_for_update(self::RECEIPT_KEY, $kvIndex, $authority);
        $baselineRaw = Ledger::kv_get_for_update(self::BASELINE_KEY, $kvIndex, $authority);
        $observation = CodeLifecycleObservation::from_locked_rows(
            $desired,
            $optionRows,
            $baselineRaw
        );
        return new self(
            $desired,
            $authority,
            $optionIndex,
            $kvIndex,
            $receiptRaw,
            $baselineRaw,
            $observation
        );
    }

    public function receipt_raw(): ?string {
        return $this->receiptRaw;
    }

    public function baseline_raw(): ?string {
        return $this->baselineRaw;
    }

    /** @return array<string,mixed> */
    public function observation(): array {
        return $this->observation;
    }

    /** @return array{receipt:?string,baseline:?string} */
    public function readback(): array {
        return [
            'receipt' => Ledger::kv_get_for_update(
                self::RECEIPT_KEY,
                $this->kvIndex,
                $this->authority
            ),
            'baseline' => Ledger::kv_get_for_update(
                self::BASELINE_KEY,
                $this->kvIndex,
                $this->authority
            ),
        ];
    }

    /** @return array<string,mixed> */
    public function reread_observation(?string $recordedRaw, string $purpose): array {
        $rows = LockedOptionRows::read_required(
            CodeLifecycleObservation::option_names(),
            $this->optionIndex,
            $this->authority,
            $purpose
        );
        return CodeLifecycleObservation::from_locked_rows(
            $this->desired,
            $rows,
            $recordedRaw
        );
    }

    public function publish_acceptance(string $baseline, string $receipt): void {
        Ledger::kv_set_transactional([
            self::BASELINE_KEY => $baseline,
            self::RECEIPT_KEY => $receipt,
        ], $this->authority);
    }

    public function publish_terminal(string $baseline): void {
        Ledger::kv_set_transactional([self::BASELINE_KEY => $baseline], $this->authority);
        Ledger::kv_delete_transactional(self::RECEIPT_KEY, $this->authority);
    }
}

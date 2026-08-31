<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/DeleteGuardEvaluator.php';

/**
 * Exact external writer-exclusion authority for one authored delete transaction.
 *
 * ProcessFence serializes only cooperating WPrism database connections. The
 * caller supplies the target-owned recovery verifier; this Delete-private
 * object never reaches across the module ladder into Promotion. It binds one
 * signed recovery generation, re-verifies it at every destructive
 * frontier, and issues a one-use token that DeleteExecutor must consume before
 * it can touch a row.
 */
final class DeletionWriterExclusion {
    /** @var list<string> */
    private const SCOPED_WITNESS_KEYS = [
        'active', 'allow_deletes', 'artifact_hash', 'exclusion_state', 'format',
        'generation', 'ok', 'owner', 'receipt_format', 'receipt_id',
        'receipt_payload_sha256', 'recovery_ready', 'scope_hash', 'signing_key_id',
        'state', 'target_id', 'terminal',
    ];
    /** @var list<string> */
    private const VERIFIED_WITNESS_KEYS = [
        'active', 'allow_deletes', 'artifact_hash', 'exclusion_state', 'format',
        'generation', 'ok', 'owner', 'receipt_format', 'receipt_id',
        'receipt_payload_sha256', 'recovery_ready', 'signing_key_id', 'state',
        'target_id', 'terminal',
    ];

    /** @var ?array<string,mixed> */
    private ?array $binding = null;
    private bool $transactionBound = false;
    private bool $deleteArmed = false;
    /** @var \Closure(array<string,mixed>):array<string,mixed> */
    private readonly \Closure $verify;

    /**
     * The verifier is injected by the Apply composition boundary. The default
     * is intentionally unusable so a direct/default construction cannot turn
     * missing recovery integration into delete authority.
     *
     * @param ?\Closure(array<string,mixed>):array<string,mixed> $verify
     */
    public function __construct(?\Closure $verify = null) {
        $this->verify = $verify ?? static function (array $_binding): array {
            throw new \RuntimeException(
                'wprism: deletion writer-exclusion verifier is not configured'
            );
        };
    }

    /** Bind the exact already-verified signed generation before any mutation. */
    public function bind(array $witness): void {
        $validated = self::validate_witness($witness);
        if ($this->binding !== null) {
            if (!hash_equals(self::binding_hash($this->binding), self::binding_hash($validated))) {
                throw new \RuntimeException(
                    'wprism: deletion writer exclusion cannot replace its signed generation in one apply process'
                );
            }
            return;
        }
        $this->binding = $validated;
    }

    /** Fail before target mutation when a selected delete lacks external exclusion. */
    public function assert_plan_authority(): void {
        if ($this->binding === null) {
            throw new CommandRefusalException(
                'deletion_writer_exclusion_required',
                'deletion requires a signed recovery promotion whose external exclusion blocks all target writers',
                'run the deletion through automatic verified promotion with its recovery exclusion held',
                [[
                    'code' => 'deletion_writer_exclusion_required',
                    'surface' => 'deletion',
                    'message' => 'no exact external writer-exclusion generation is bound',
                    'remediation' => 'use automatic verified promotion for this deletion',
                ]],
                'wprism: deletion refused before mutation — no exact held external writer exclusion is bound'
            );
        }
        $this->assert_external_held('deletion plan authority');
    }

    /** Verify the external fence inside the authored transaction before owner binding. */
    public function begin_authored_transaction(): void {
        if ($this->transactionBound) {
            throw new \RuntimeException('wprism: deletion writer exclusion is already bound to a transaction');
        }
        DeleteGuardEvaluator::assert_transaction_isolation('deletion writer exclusion binding');
        $this->assert_external_held('deletion executable-owner binding');
        $this->transactionBound = true;
        $this->deleteArmed = false;
    }

    /** Verify the exact held generation and arm one immediate destructive unit. */
    public function authorize_next_delete(): void {
        $this->assert_transaction_bound('delete authorization');
        if ($this->deleteArmed) {
            throw new \RuntimeException('wprism: deletion writer exclusion has an unconsumed destructive-unit token');
        }
        $this->assert_external_held('delete authorization');
        $this->deleteArmed = true;
    }

    /** Cancel an armed unit when a later owner/guard assertion refuses it. */
    public function cancel_next_delete(): void {
        $this->deleteArmed = false;
    }

    /** Called only by DeleteExecutor at its first instruction. */
    public function consume_delete_authority(): void {
        $this->assert_transaction_bound('delete execution');
        if (!$this->deleteArmed) {
            throw new CommandRefusalException(
                'deletion_writer_exclusion_not_authorized',
                'direct deletion lacks a one-use destructive-unit authorization under the held writer exclusion',
                'invoke deletion only through the transaction coordinator that re-verifies the external exclusion',
                [],
                'wprism: direct delete execution refused without coordinator-bound external exclusion authority'
            );
        }
        // Consume before the first row lookup: an exception cannot leave replay
        // authority available to a second direct call in the same transaction.
        $this->deleteArmed = false;
    }

    /** The external admission gate must still be held immediately before COMMIT. */
    public function assert_commit_boundary(): void {
        $this->assert_transaction_bound('delete commit boundary');
        if ($this->deleteArmed) {
            throw new \RuntimeException('wprism: deletion commit found an unconsumed destructive-unit token');
        }
        $this->assert_external_held('delete commit boundary');
    }

    /** Clear transaction-local authority after either COMMIT or ROLLBACK. */
    public function end_authored_transaction(): void {
        $this->transactionBound = false;
        $this->deleteArmed = false;
    }

    private function assert_transaction_bound(string $purpose): void {
        if (!$this->transactionBound) {
            throw new CommandRefusalException(
                'deletion_writer_exclusion_not_bound',
                "deletion $purpose is outside the authored transaction's verified external writer exclusion",
                'retry through the promotion transaction coordinator holding the signed recovery exclusion',
                [],
                "wprism: deletion $purpose refused outside its coordinator-bound external exclusion"
            );
        }
        DeleteGuardEvaluator::assert_transaction_isolation("deletion writer exclusion $purpose");
    }

    private function assert_external_held(string $purpose): void {
        if ($this->binding === null) {
            $this->assert_plan_authority();
            return;
        }
        try {
            $current = self::validate_witness(($this->verify)($this->binding));
        } catch (\Throwable $failure) {
            throw new CommandRefusalException(
                'deletion_writer_exclusion_lost',
                "deletion refused because the complete external writer exclusion could not be verified at the $purpose frontier",
                'retain the failed recovery generation; do not retry outside its verified exclusion',
                [],
                "wprism: deletion external writer exclusion was lost or unverifiable at $purpose",
                $failure
            );
        }
        if (!hash_equals(self::binding_hash($this->binding), self::binding_hash($current))) {
            throw new CommandRefusalException(
                'deletion_writer_exclusion_changed',
                "deletion refused because external writer-exclusion authority changed at the $purpose frontier",
                'recover the exact original promotion generation before another deletion attempt',
                [],
                "wprism: deletion external writer-exclusion binding changed at $purpose"
            );
        }
    }

    /** @return array<string,mixed> */
    private static function validate_witness(array $witness): array {
        $reportedProfile = $witness['profile'] ?? null;
        unset($witness['profile']);
        $format = $witness['format'] ?? null;
        if ($format === 'wprism-scoped-promotion-witness/v1') {
            $expected = self::SCOPED_WITNESS_KEYS;
            $profile = 'scoped';
        } elseif ($format === 'wprism-verified-promotion-witness/v1') {
            $expected = self::VERIFIED_WITNESS_KEYS;
            $profile = 'verified';
        } else {
            throw new \RuntimeException('wprism: deletion writer-exclusion witness has an unsupported profile');
        }
        if ($reportedProfile !== null && $reportedProfile !== $profile) {
            throw new \RuntimeException('wprism: deletion writer-exclusion witness profile changed');
        }
        $keys = array_keys($witness);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new \RuntimeException('wprism: deletion writer-exclusion witness has missing or unknown fields');
        }
        $witness['profile'] = $profile;
        return $witness;
    }

    private static function binding_hash(array $witness): string {
        $binding = $witness;
        ksort($binding, SORT_STRING);
        $json = json_encode($binding, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return hash('sha256', $json);
    }
}

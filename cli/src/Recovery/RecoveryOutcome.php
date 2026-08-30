<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once __DIR__ . '/RecoveryPlan.php';

use WPrism\Canon;
use WPrism\CommandRefusalException;

/**
 * Validated terminal evidence for the asynchronous recovery protocol.
 *
 * Legacy `recover --restore` keeps its existing v1 summary until an authorized
 * executor exists.  This v2 foundation is deliberately stricter: every
 * outcome names the frozen plan, consumed external authorization, target-side
 * re-verification record, and hash-bound steps.  It is shaped for
 * `TargetOperationStore::complete()`; that store supplies durable one-time
 * publication while this class decides whether the claimed recovery result
 * is internally coherent.
 */
final class RecoveryOutcome {
    public const FORMAT = 'wprism-recovery-outcome/v2';

    public const RECOVERED = 'recovered';
    public const FAILED = 'failed';
    public const RECONCILE_REQUIRED = 'reconcile_required';

    /** @var list<string> */
    public const STATUSES = [self::RECOVERED, self::FAILED, self::RECONCILE_REQUIRED];

    /** The complete full-receipt rollback path, including its two terminal facts. @var list<string> */
    public const RECOVERY_STEPS = [
        'promotion_failed',
        'rollback_start',
        'effects_inverse',
        'storage_restore',
        'code_restore',
        'database_restore',
        'verifying_prior',
        'prior_verify',
        'rolled_back_verified',
        'exclusion_release',
    ];

    /** @var list<string> */
    private const TARGET_KEYS = [
        'artifact_hash',
        'event_chain_sha256',
        'exclusion_state',
        'generation',
        'head_event_sha256',
        'receipt_id',
        'rollback_target_id',
        'sequence',
        'state',
        'target_record_sha256',
        'terminal',
    ];

    /** @var list<string> */
    private const DOCUMENT_KEYS = [
        'authorization_digest',
        'environment',
        'failure',
        'format',
        'operation_id',
        'outcome_digest',
        'plan_digest',
        'recovered',
        'reverified',
        'status',
        'steps',
        'subject_digest',
        'target_after',
        'target_after_sha256',
        'verification_sha256',
    ];

    /** @var list<string> */
    private const INPUT_KEYS = [
        'authorization_digest',
        'environment',
        'failure',
        'operation_id',
        'plan_digest',
        'reverified',
        'status',
        'steps',
        'subject_digest',
        'target_after',
        'target_after_sha256',
        'verification_sha256',
    ];

    /**
     * @param array<string,mixed> $inputs
     * @return array<string,mixed>
     */
    public static function build(array $inputs): array {
        self::assertExactKeys($inputs, self::INPUT_KEYS, 'recovery outcome inputs');
        $outcome = [
            'authorization_digest' => $inputs['authorization_digest'],
            'environment' => $inputs['environment'],
            'failure' => $inputs['failure'],
            'format' => self::FORMAT,
            'operation_id' => $inputs['operation_id'],
            'plan_digest' => $inputs['plan_digest'],
            'recovered' => $inputs['status'] === self::RECOVERED,
            'reverified' => $inputs['reverified'],
            'status' => $inputs['status'],
            'steps' => $inputs['steps'],
            'subject_digest' => $inputs['subject_digest'],
            'target_after' => $inputs['target_after'],
            'target_after_sha256' => $inputs['target_after_sha256'],
            'verification_sha256' => $inputs['verification_sha256'],
        ];
        $outcome['outcome_digest'] = self::digest($outcome);
        self::validate($outcome);

        return $outcome;
    }

    public static function encode(array $outcome): string {
        self::validate($outcome);

        return Canon::encode($outcome);
    }

    public static function digest(array $outcome): string {
        unset($outcome['outcome_digest']);

        return 'sha256:' . hash('sha256', Canon::encode($outcome));
    }

    /** @param array<string,mixed> $outcome */
    public static function validate(array $outcome): void {
        self::assertExactKeys($outcome, self::DOCUMENT_KEYS, 'recovery outcome');
        if (($outcome['format'] ?? null) !== self::FORMAT) {
            throw self::refuse('recovery_outcome_format_invalid', 'the document is not a ' . self::FORMAT);
        }
        $status = $outcome['status'] ?? null;
        if (!is_string($status) || !in_array($status, self::STATUSES, true)) {
            throw self::refuse('recovery_outcome_shape_invalid', 'the recovery outcome names no known status');
        }
        if (!is_string($outcome['environment'] ?? null)
            || trim((string) $outcome['environment']) === ''
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@+\/-]{0,255}$/D', (string) ($outcome['operation_id'] ?? '')) !== 1) {
            throw self::refuse(
                'recovery_outcome_shape_invalid',
                'the recovery outcome has no environment or stable operation id'
            );
        }
        foreach (['authorization_digest', 'plan_digest', 'subject_digest', 'outcome_digest'] as $field) {
            self::assertDigest((string) ($outcome[$field] ?? ''), $field);
        }
        if (!hash_equals(self::digest($outcome), (string) $outcome['outcome_digest'])) {
            throw self::refuse(
                'recovery_outcome_digest_mismatch',
                'the recovery outcome digest does not match its own content'
            );
        }

        $reverified = $outcome['reverified'] ?? null;
        if (!is_array($reverified)) {
            throw self::refuse(
                'recovery_outcome_shape_invalid',
                'the recovery outcome carries no mutation-gate re-verification evidence'
            );
        }
        self::assertExactKeys(
            $reverified,
            ['at', 'facts_sha256', 'plan_digest', 'subject_digest'],
            'recovery outcome re-verification'
        );
        self::assertTimestamp((string) ($reverified['at'] ?? ''), 're-verification timestamp');
        foreach (['facts_sha256', 'plan_digest', 'subject_digest'] as $field) {
            self::assertDigest((string) ($reverified[$field] ?? ''), "re-verification $field");
        }
        foreach (['plan_digest', 'subject_digest'] as $field) {
            if (!hash_equals((string) $outcome[$field], (string) $reverified[$field])) {
                throw self::refuse(
                    'recovery_outcome_subject_mismatch',
                    "the recovery outcome changed the re-verified $field"
                );
            }
        }
        if (!hash_equals((string) $outcome['subject_digest'], (string) $reverified['facts_sha256'])) {
            throw self::refuse(
                'recovery_outcome_subject_mismatch',
                'the recovery outcome was not executed against its exact frozen fact vector'
            );
        }

        $steps = $outcome['steps'] ?? null;
        if (!is_array($steps) || !array_is_list($steps) || $steps === []) {
            throw self::refuse('recovery_outcome_shape_invalid', 'the recovery outcome carries no ordered step evidence');
        }
        $ambiguous = false;
        $allCompleted = true;
        $failed = false;
        $names = [];
        foreach ($steps as $step) {
            if (!is_array($step)) {
                throw self::refuse('recovery_outcome_shape_invalid', 'a recovery outcome step is not an object');
            }
            self::assertExactKeys(
                $step,
                ['input_sha256', 'result_sha256', 'status', 'step'],
                'recovery outcome step'
            );
            $name = $step['step'] ?? null;
            $stepStatus = $step['status'] ?? null;
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $name) !== 1
                || !is_string($stepStatus)
                || !in_array($stepStatus, ['ambiguous', 'completed', 'failed', 'not_started'], true)) {
                throw self::refuse('recovery_outcome_shape_invalid', 'a recovery outcome step identity is malformed');
            }
            if (in_array($name, $names, true)) {
                throw self::refuse('recovery_outcome_shape_invalid', 'a recovery outcome repeats a step identity');
            }
            $names[] = $name;
            self::assertHash((string) ($step['input_sha256'] ?? ''), 'step input hash');
            $result = $step['result_sha256'] ?? null;
            if ($stepStatus === 'completed') {
                self::assertHash(is_string($result) ? $result : '', 'step result hash');
            } elseif ($result !== null) {
                throw self::refuse(
                    'recovery_outcome_shape_invalid',
                    'a non-completed recovery step claims a result hash'
                );
            }
            $ambiguous = $ambiguous || $stepStatus === 'ambiguous';
            $failed = $failed || $stepStatus === 'failed';
            $allCompleted = $allCompleted && $stepStatus === 'completed';
        }
        if ($names !== self::RECOVERY_STEPS) {
            throw self::refuse(
                'recovery_outcome_shape_invalid',
                'the recovery outcome does not carry the complete ordered full-receipt rollback vocabulary'
            );
        }

        $targetAfter = self::validateTargetAfter($outcome['target_after'] ?? null);
        $targetAfterDigest = $outcome['target_after_sha256'] ?? null;
        if ($targetAfter === null) {
            if ($targetAfterDigest !== null) {
                throw self::refuse(
                    'recovery_outcome_shape_invalid',
                    'an unobserved target-after state claims a target evidence hash'
                );
            }
        } else {
            self::assertHash(is_string($targetAfterDigest) ? $targetAfterDigest : '', 'target after hash');
            if (!hash_equals(hash('sha256', Canon::encode($targetAfter)), (string) $targetAfterDigest)) {
                throw self::refuse(
                    'recovery_outcome_digest_mismatch',
                    'the target-after evidence hash does not match the observable terminal target facts'
                );
            }
        }

        $failure = $outcome['failure'] ?? null;
        if ($status === self::RECOVERED) {
            if (($outcome['recovered'] ?? null) !== true || $failure !== null || !$allCompleted
                || !is_array($targetAfter)
                || ($targetAfter['state'] ?? null) !== 'rolled_back'
                || ($targetAfter['terminal'] ?? null) !== true
                || ($targetAfter['exclusion_state'] ?? null) !== 'released') {
                throw self::refuse(
                    'recovery_outcome_state_invalid',
                    'a recovered outcome lacks the full rollback, rolled-back terminal receipt, or released exclusion'
                );
            }
            self::assertOptionalHash($outcome['verification_sha256'] ?? null, 'verification hash', false);

            return;
        }
        if (($outcome['recovered'] ?? null) !== false || !is_array($failure)) {
            throw self::refuse(
                'recovery_outcome_state_invalid',
                'an incomplete recovery outcome carries no classified failure'
            );
        }
        self::assertExactKeys($failure, ['next_action', 'reason_code', 'remediation'], 'recovery outcome failure');
        foreach (['next_action', 'reason_code', 'remediation'] as $field) {
            if (!is_string($failure[$field] ?? null) || trim((string) $failure[$field]) === '') {
                throw self::refuse('recovery_outcome_shape_invalid', "the recovery failure has no $field");
            }
        }
        if ($status === self::RECONCILE_REQUIRED
            && (($failure['next_action'] ?? null) !== 'reconcile' || !$ambiguous)) {
            throw self::refuse(
                'recovery_outcome_state_invalid',
                'a reconcile-required outcome has no ambiguous step and reconcile next action'
            );
        }
        if ($status === self::FAILED
            && (!in_array($failure['next_action'], ['retry', 'escalate'], true) || $ambiguous || !$failed)) {
            throw self::refuse(
                'recovery_outcome_state_invalid',
                'a failed outcome either carries ambiguity or an invalid next action'
            );
        }
        if (is_array($targetAfter)
            && ($targetAfter['state'] ?? null) === 'rolled_back'
            && ($targetAfter['terminal'] ?? null) === true
            && ($targetAfter['exclusion_state'] ?? null) === 'released') {
            throw self::refuse(
                'recovery_outcome_state_invalid',
                'a terminal rolled-back target with released exclusion cannot be recorded as incomplete recovery'
            );
        }
        self::assertOptionalHash($outcome['verification_sha256'] ?? null, 'verification hash', true);
    }

    /** @return ?array<string,mixed> */
    private static function validateTargetAfter(mixed $value): ?array {
        if ($value === null) return null;
        if (!is_array($value)) {
            throw self::refuse('recovery_outcome_shape_invalid', 'target-after recovery evidence is not an object');
        }
        self::assertExactKeys($value, self::TARGET_KEYS, 'target-after recovery evidence');
        foreach (['artifact_hash', 'event_chain_sha256', 'head_event_sha256', 'target_record_sha256'] as $field) {
            self::assertHash((string) ($value[$field] ?? ''), "target-after $field");
        }
        foreach (['generation', 'sequence'] as $field) {
            if (!is_int($value[$field] ?? null) || (int) $value[$field] < 1) {
                throw self::refuse('recovery_outcome_shape_invalid', "target-after $field is not positive");
            }
        }
        if (preg_match('/^[a-f0-9]{32,64}$/D', (string) ($value['receipt_id'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', (string) ($value['rollback_target_id'] ?? '')) !== 1
            || !in_array((string) ($value['state'] ?? ''), array_merge(
                RecoveryPlan::ELIGIBLE_STATES,
                ['committed', 'rolled_back']
            ), true)
            || !in_array((string) ($value['exclusion_state'] ?? ''), ['held', 'none', 'released'], true)
            || !is_bool($value['terminal'] ?? null)) {
            throw self::refuse(
                'recovery_outcome_shape_invalid',
                'target-after recovery identity, state, or exclusion evidence is malformed'
            );
        }

        return $value;
    }

    /** @param list<string> $expected */
    private static function assertExactKeys(array $value, array $expected, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw self::refuse('recovery_outcome_shape_invalid', "$label has missing or unknown fields");
        }
    }

    private static function assertOptionalHash(mixed $value, string $label, bool $nullable): void {
        if ($nullable && $value === null) {
            return;
        }
        self::assertHash(is_string($value) ? $value : '', $label);
    }

    private static function assertHash(string $value, string $label): void {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw self::refuse('recovery_outcome_shape_invalid', "$label is not a sha256 hash");
        }
    }

    private static function assertDigest(string $value, string $label): void {
        if (preg_match('/^sha256:[a-f0-9]{64}$/D', $value) !== 1) {
            throw self::refuse('recovery_outcome_shape_invalid', "$label is not a sha256: digest");
        }
    }

    private static function assertTimestamp(string $value, string $label): void {
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if (!$time || $time->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw self::refuse('recovery_outcome_shape_invalid', "$label is not canonical UTC seconds");
        }
    }

    private static function refuse(string $code, string $message): CommandRefusalException {
        return new CommandRefusalException(
            $code,
            $message,
            'do not record or retry this recovery; reconcile the frozen plan, authorization, and target evidence'
        );
    }
}

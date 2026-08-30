<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once __DIR__ . '/ReleaseOutcome.php';
require_once __DIR__ . '/ReleasePrepare.php';

use WPrism\Canon;

/** Bounded read-only projection of one durable release operation lineage. */
final class ReleaseOperationStatus {
    public const FORMAT = 'wprism-release-operation-status/v1';

    private const DIGEST_PATTERN = '/^sha256:[a-f0-9]{64}$/D';
    private const REVISION_PATTERN = '/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/D';
    private const STATES = [
        'prepared' => 0,
        'elected' => 1,
        'consumed' => 2,
        'completed' => 3,
    ];

    /**
     * Project only target-private durable records. No target observation or
     * controller clock can advance this sequence, so repeated reads never
     * invent progress after a lost process.
     *
     * @param array<string,mixed> $prepare
     * @param ?array{authorization_digest:string,completion:?array<string,mixed>,consumption:?array<string,mixed>} $stored
     * @return array<string,mixed>
     */
    public static function build(array $prepare, ?array $stored): array {
        ReleasePrepare::validate($prepare);
        $authorizationDigest = is_array($stored)
            ? (string) ($stored['authorization_digest'] ?? '')
            : null;
        $consumption = is_array($stored['consumption'] ?? null) ? $stored['consumption'] : null;
        $completion = is_array($stored['completion'] ?? null) ? $stored['completion'] : null;

        $state = $stored === null
            ? 'prepared'
            : ($consumption === null ? 'elected' : ($completion === null ? 'consumed' : 'completed'));
        if ($stored !== null && preg_match(self::DIGEST_PATTERN, (string) $authorizationDigest) !== 1) {
            throw new \InvalidArgumentException('release operation status has no elected authorization identity');
        }
        if ($consumption !== null) {
            self::assertStoredIdentity($prepare, $consumption, (string) $authorizationDigest, 'consumption');
        }
        $outcome = null;
        if ($completion !== null) {
            self::assertStoredIdentity($prepare, $completion, (string) $authorizationDigest, 'completion');
            $outcome = $completion['outcome'] ?? null;
            if (!is_array($outcome)) {
                throw new \InvalidArgumentException('release operation completion has no terminal outcome');
            }
            ReleaseOutcome::validate($outcome);
            if (($outcome['environment'] ?? null) !== $prepare['environment']
                || ($outcome['plan_digest'] ?? null) !== $prepare['plan_digest']) {
                throw new \InvalidArgumentException('release operation completion describes another release');
            }
        }

        $document = [
            'authorization_digest' => $authorizationDigest,
            'completion_digest' => $completion['completion_digest'] ?? null,
            'consumption_digest' => $consumption['consumption_digest'] ?? null,
            'format' => self::FORMAT,
            'operation' => 'release',
            'operation_id' => (string) $prepare['operation_id'],
            'outcome' => $outcome,
            'outcome_digest' => $completion['outcome_digest'] ?? null,
            'plan_digest' => (string) $prepare['plan_digest'],
            'presented_plan_sha256' => (string) $prepare['presented_plan_sha256'],
            'reconciliation_required' => in_array($state, ['elected', 'consumed'], true),
            'sequence' => self::STATES[$state],
            'source_commit' => (string) $prepare['stage_receipt']['source']['commit'],
            'source_tree' => (string) $prepare['stage_receipt']['source']['tree'],
            'stage_receipt_sha256' => (string) $prepare['stage_receipt']['receipt_sha256'],
            'state' => $state,
            'subject_digest' => (string) $prepare['subject_sha256'],
            'target_id' => (string) $prepare['target_id'],
            'terminal' => $state === 'completed',
        ];
        $document['status_sha256'] = self::documentDigest($document);
        $document = Canon::normalize($document);
        self::validate($document);

        return $document;
    }

    /** @param array<string,mixed> $document */
    public static function validate(array $document): void {
        self::closedKeys($document, [
            'authorization_digest', 'completion_digest', 'consumption_digest', 'format', 'operation',
            'operation_id', 'outcome', 'outcome_digest', 'plan_digest', 'presented_plan_sha256',
            'reconciliation_required', 'sequence', 'source_commit', 'source_tree',
            'stage_receipt_sha256', 'state', 'status_sha256', 'subject_digest', 'target_id', 'terminal',
        ]);
        $state = $document['state'] ?? null;
        if (($document['format'] ?? null) !== self::FORMAT
            || ($document['operation'] ?? null) !== 'release'
            || !is_string($state)
            || !array_key_exists($state, self::STATES)
            || ($document['sequence'] ?? null) !== self::STATES[$state]
            || !is_bool($document['terminal'] ?? null)
            || $document['terminal'] !== ($state === 'completed')
            || !is_bool($document['reconciliation_required'] ?? null)
            || $document['reconciliation_required'] !== in_array($state, ['elected', 'consumed'], true)) {
            throw new \InvalidArgumentException('release operation status state is malformed');
        }
        foreach (['plan_digest', 'presented_plan_sha256', 'stage_receipt_sha256', 'subject_digest'] as $field) {
            self::assertDigest($document[$field] ?? null, "release operation status $field");
        }
        if (!is_string($document['operation_id'] ?? null) || $document['operation_id'] === ''
            || !is_string($document['target_id'] ?? null)
            || preg_match('/^wprism-target:[a-f0-9]{64}$/D', $document['target_id']) !== 1
            || !is_string($document['source_commit'] ?? null)
            || preg_match(self::REVISION_PATTERN, $document['source_commit']) !== 1
            || !is_string($document['source_tree'] ?? null)
            || preg_match(self::REVISION_PATTERN, $document['source_tree']) !== 1) {
            throw new \InvalidArgumentException('release operation status lineage is malformed');
        }

        $authorization = $document['authorization_digest'] ?? null;
        $consumption = $document['consumption_digest'] ?? null;
        $completion = $document['completion_digest'] ?? null;
        $outcomeDigest = $document['outcome_digest'] ?? null;
        $outcome = $document['outcome'] ?? null;
        if ($state === 'prepared') {
            if ($authorization !== null || $consumption !== null || $completion !== null
                || $outcomeDigest !== null || $outcome !== null) {
                throw new \InvalidArgumentException('prepared release status carries target execution evidence');
            }
        } else {
            self::assertDigest($authorization, 'release operation authorization digest');
            if ($state === 'elected') {
                if ($consumption !== null || $completion !== null || $outcomeDigest !== null || $outcome !== null) {
                    throw new \InvalidArgumentException('elected release status carries unelected execution evidence');
                }
            } elseif ($state === 'consumed') {
                self::assertDigest($consumption, 'release operation consumption digest');
                if ($completion !== null || $outcomeDigest !== null || $outcome !== null) {
                    throw new \InvalidArgumentException('consumed release status carries terminal evidence');
                }
            } else {
                self::assertDigest($consumption, 'release operation consumption digest');
                self::assertDigest($completion, 'release operation completion digest');
                self::assertDigest($outcomeDigest, 'release operation outcome digest');
                if (!is_array($outcome)) {
                    throw new \InvalidArgumentException('completed release status has no outcome');
                }
                ReleaseOutcome::validate($outcome);
                if (!hash_equals((string) $outcomeDigest, 'sha256:' . hash('sha256', Canon::encode($outcome)))) {
                    throw new \InvalidArgumentException('release operation outcome digest does not match its bytes');
                }
            }
        }
        self::assertDigest($document['status_sha256'] ?? null, 'release operation status digest');
        if (!hash_equals((string) $document['status_sha256'], self::documentDigest($document))) {
            throw new \InvalidArgumentException('release operation status digest does not match its contents');
        }
    }

    /** @param array<string,mixed> $document */
    public static function encode(array $document): string {
        self::validate($document);

        return Canon::encode($document);
    }

    /** @param array<string,mixed> $prepare @param array<string,mixed> $stored */
    private static function assertStoredIdentity(
        array $prepare,
        array $stored,
        string $authorizationDigest,
        string $label
    ): void {
        $expected = [
            'authorization_digest' => $authorizationDigest,
            'operation' => 'release',
            'operation_id' => (string) $prepare['operation_id'],
            'subject_digest' => (string) $prepare['subject_sha256'],
            'target_id' => (string) $prepare['target_id'],
        ];
        foreach ($expected as $field => $value) {
            if (!is_string($stored[$field] ?? null) || !hash_equals($value, (string) $stored[$field])) {
                throw new \InvalidArgumentException("release operation $label changed $field");
            }
        }
    }

    /** @param array<string,mixed> $document */
    private static function documentDigest(array $document): string {
        unset($document['status_sha256']);

        return 'sha256:' . hash('sha256', Canon::encode($document));
    }

    private static function assertDigest(mixed $value, string $label): void {
        if (!is_string($value) || preg_match(self::DIGEST_PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException("$label is malformed");
        }
    }

    /** @param list<string> $expected */
    private static function closedKeys(array $document, array $expected): void {
        $actual = array_keys($document);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \InvalidArgumentException('release operation status has an unexpected key set');
        }
    }
}

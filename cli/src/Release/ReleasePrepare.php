<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once __DIR__ . '/AuthorizationPlan.php';
require_once __DIR__ . '/SourceStageReceipt.php';

use WPrism\Canon;

/** Exact, self-contained subject an external release authority can authorize. */
final class ReleasePrepare {
    public const FORMAT = 'wprism-release-prepare/v1';

    /**
     * @param array<string,mixed> $receipt
     * @param array<string,mixed> $plan
     * @param array{accept_weaker_recovery:bool,authority_policy_digest:string,capability_registry_sha256:string,profile:?string,with_deletes:bool} $request
     * @return array<string,mixed>
     */
    public static function build(array $receipt, array $plan, array $request): array {
        SourceStageReceipt::validate($receipt);
        AuthorizationPlan::validate($plan);

        $document = [
            'authorization_plan' => $plan,
            'authority_policy_digest' => $request['authority_policy_digest'],
            'environment' => (string) $receipt['environment'],
            'format' => self::FORMAT,
            'operation_id' => (string) $receipt['operation_id'],
            'plan_digest' => (string) $plan['plan_digest'],
            'presented_plan_sha256' => 'sha256:' . hash('sha256', AuthorizationPlan::encode($plan)),
            'required_grants' => self::requiredGrants($plan),
            'request' => [
                'accept_weaker_recovery' => $request['accept_weaker_recovery'],
                'expected_capability_registry_sha256' => $request['capability_registry_sha256'],
                'expected_artifact_hash' => (string) $plan['artifact_hash'],
                'expected_base_commit' => (string) $receipt['base']['commit'],
                'expected_plan_digest' => (string) $plan['plan_digest'],
                'expected_source_commit' => (string) $receipt['source']['commit'],
                'expected_source_tree' => (string) $receipt['source']['tree'],
                'expected_stage_receipt_sha256' => (string) $receipt['receipt_sha256'],
                'expected_target_identity_sha256' => (string) $receipt['target']['identity_sha256'],
                'profile' => $request['profile'],
                'with_deletes' => $request['with_deletes'],
            ],
            'stage_receipt' => $receipt,
            'target_id' => (string) $receipt['target']['id'],
        ];
        $document['subject_sha256'] = self::subjectDigest($document);
        self::validate($document);

        return $document;
    }

    /** @param array<string,mixed> $document */
    public static function validate(array $document): void {
        self::closedKeys($document, [
            'authorization_plan', 'authority_policy_digest', 'environment', 'format', 'operation_id', 'plan_digest',
            'presented_plan_sha256', 'request', 'required_grants', 'stage_receipt', 'subject_sha256', 'target_id',
        ], 'release prepare document');
        if (($document['format'] ?? null) !== self::FORMAT) {
            throw new \InvalidArgumentException('release prepare format is unsupported');
        }
        $receipt = $document['stage_receipt'] ?? null;
        $plan = $document['authorization_plan'] ?? null;
        if (!is_array($receipt) || !is_array($plan)) {
            throw new \InvalidArgumentException('release prepare needs a stage receipt and authorization plan');
        }
        SourceStageReceipt::validate($receipt);
        AuthorizationPlan::validate($plan);
        if (($document['environment'] ?? null) !== $receipt['environment']
            || ($document['operation_id'] ?? null) !== $receipt['operation_id']
            || ($document['plan_digest'] ?? null) !== $plan['plan_digest']
            || ($document['target_id'] ?? null) !== $receipt['target']['id']) {
            throw new \InvalidArgumentException('release prepare outer identity does not match its inputs');
        }
        if (!is_string($document['authority_policy_digest'] ?? null)
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $document['authority_policy_digest']) !== 1) {
            throw new \InvalidArgumentException('release prepare authority policy digest is invalid');
        }
        if (($document['required_grants'] ?? null) !== self::requiredGrants($plan)) {
            throw new \InvalidArgumentException('release prepare required grants do not match the authorization plan');
        }
        $presented = $document['presented_plan_sha256'] ?? null;
        if (!is_string($presented)
            || !hash_equals('sha256:' . hash('sha256', AuthorizationPlan::encode($plan)), $presented)) {
            throw new \InvalidArgumentException('release prepare presented plan digest does not match the exact plan bytes');
        }

        $request = $document['request'] ?? null;
        if (!is_array($request) || array_is_list($request)) {
            throw new \InvalidArgumentException('release prepare request is invalid');
        }
        self::closedKeys($request, [
            'accept_weaker_recovery', 'expected_artifact_hash', 'expected_base_commit',
            'expected_capability_registry_sha256', 'expected_plan_digest', 'expected_source_commit',
            'expected_source_tree', 'expected_stage_receipt_sha256',
            'expected_target_identity_sha256', 'profile', 'with_deletes',
        ], 'release prepare request');
        if (!is_bool($request['accept_weaker_recovery'] ?? null) || !is_bool($request['with_deletes'] ?? null)
            || (($request['profile'] ?? null) !== null && !is_string($request['profile']))) {
            throw new \InvalidArgumentException('release prepare request flags are invalid');
        }
        if (!is_string($request['expected_capability_registry_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $request['expected_capability_registry_sha256']) !== 1) {
            throw new \InvalidArgumentException('release prepare capability registry digest is invalid');
        }
        $expected = [
            'expected_artifact_hash' => $plan['artifact_hash'],
            'expected_base_commit' => $receipt['base']['commit'],
            'expected_plan_digest' => $plan['plan_digest'],
            'expected_source_commit' => $receipt['source']['commit'],
            'expected_source_tree' => $receipt['source']['tree'],
            'expected_stage_receipt_sha256' => $receipt['receipt_sha256'],
            'expected_target_identity_sha256' => $receipt['target']['identity_sha256'],
        ];
        foreach ($expected as $key => $value) {
            if (!is_string($request[$key] ?? null) || !hash_equals((string) $value, $request[$key])) {
                throw new \InvalidArgumentException("release prepare request $key does not match its input");
            }
        }
        $subject = $document['subject_sha256'] ?? null;
        if (!is_string($subject) || preg_match('/^sha256:[a-f0-9]{64}$/D', $subject) !== 1
            || !hash_equals(self::subjectDigest($document), $subject)) {
            throw new \InvalidArgumentException('release prepare subject digest does not match its complete contents');
        }
    }

    /** @param array<string,mixed> $document */
    public static function subjectDigest(array $document): string {
        unset($document['subject_sha256']);

        return 'sha256:' . hash('sha256', Canon::encode($document));
    }

    /** @param array<string,mixed> $document */
    public static function encode(array $document): string {
        self::validate($document);

        return Canon::encode($document);
    }

    /** Read one exact canonical prepared subject from a caller-selected file. */
    public static function read(string $path): array {
        $bytes = @file_get_contents($path);
        if (!is_string($bytes)) {
            throw new \InvalidArgumentException('the requested release prepare document could not be read');
        }

        return self::fromBytes($bytes);
    }

    /** Decode only exact canonical bytes; alternate JSON spellings are not the signed presentation. */
    public static function fromBytes(string $bytes): array {
        try {
            $document = Canon::decode($bytes);
        } catch (\Throwable $error) {
            throw new \InvalidArgumentException('the release prepare document is malformed JSON', 0, $error);
        }
        if (!is_array($document) || array_is_list($document)) {
            throw new \InvalidArgumentException('the release prepare document is not a JSON object');
        }
        self::validate($document);
        if (!hash_equals(Canon::encode($document), $bytes)) {
            throw new \InvalidArgumentException('the release prepare document is not canonical JSON');
        }

        return $document;
    }

    /** @return array{authority_policy_digest:string,operation:string,operation_id:string,presentation_digest:string,required_grants:list<string>,subject_digest:string,target_id:string} */
    public static function authorizationSubject(array $document): array {
        self::validate($document);

        return [
            'authority_policy_digest' => (string) $document['authority_policy_digest'],
            'operation' => 'release',
            'operation_id' => (string) $document['operation_id'],
            'presentation_digest' => (string) $document['presented_plan_sha256'],
            'required_grants' => array_values($document['required_grants']),
            'subject_digest' => (string) $document['subject_sha256'],
            'target_id' => (string) $document['target_id'],
        ];
    }

    /** @param array<string,mixed> $plan @return list<string> */
    private static function requiredGrants(array $plan): array {
        $grants = [];
        foreach ((array) ($plan['authority_still_required'] ?? []) as $row) {
            $kind = is_array($row) ? ($row['kind'] ?? null) : null;
            if (is_string($kind) && $kind !== '') {
                $grants[$kind] = true;
            }
        }
        ksort($grants, SORT_STRING);

        return array_keys($grants);
    }

    /** @param list<string> $keys */
    private static function closedKeys(array $value, array $keys, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) {
            throw new \InvalidArgumentException("$label has an unexpected key set");
        }
    }
}

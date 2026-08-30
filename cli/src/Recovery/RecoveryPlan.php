<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once __DIR__ . '/RecoveryClaim.php';

use WPrism\Canon;
use WPrism\CommandRefusalException;

/**
 * The immutable, read-only subject of one future recovery execution.
 *
 * `wprism recover <env> prepare` builds this document without taking writer
 * exclusion, changing rollback authority, or touching the database.  The
 * subject deliberately binds both layers of checkpoint identity: the signed
 * receipt's checkpoint-metadata digest and the encrypted checkpoint's actual
 * bytes.  A controller that later gains actor authority must call
 * `reverify()` with a freshly observed `currentFacts()` vector before its
 * first mutating step; any changed fact invalidates the plan.
 *
 * This v1 format is intentionally narrower than the checkpoint catalog.  It
 * admits only a nonterminal, full verified-promotion generation whose receipt
 * proves the complete database/code/uploads/effects scope.  A retained file
 * has neither a target generation nor a signed receipt, and a terminal or
 * scoped receipt has no executable public state-machine transition in this
 * build.  Those sources must refuse preparation rather than receive invented
 * identity or a compatibility transition out of a terminal state.
 */
final class RecoveryPlan {
    public const FORMAT = 'wprism-recovery-plan/v1';

    /** @var list<string> */
    public const ELIGIBLE_STATES = [
        'prepared',
        'promoting',
        'verifying_new',
        'rollback_pending',
        'rolling_back',
        'verifying_prior',
    ];

    /** @var list<string> */
    private const DOCUMENT_KEYS = [
        'checkpoint',
        'claim',
        'authority_policy_digest',
        'environment',
        'format',
        'operation_id',
        'plan_digest',
        'presentation_digest',
        'prepared_at',
        'required_grants',
        'scope',
        'subject_digest',
        'target',
        'target_head',
        'topology',
    ];

    /** @var list<string> */
    private const INPUT_KEYS = [
        'checkpoint',
        'claim',
        'authority_policy_digest',
        'environment',
        'operation_id',
        'prepared_at',
        'required_grants',
        'scope',
        'target',
        'target_head',
        'topology',
    ];

    /** @var list<string> */
    private const CHECKPOINT_KEYS = [
        'bytes',
        'created_at',
        'encryption_key_id',
        'id',
        'kind',
        'metadata_sha256',
        'retention_until',
        'sha256',
    ];

    /** @var list<string> */
    private const SCOPE_KEYS = [
        'adapter_versions_sha256',
        'allow_deletes',
        'code_release_metadata_sha256',
        'effects_metadata_sha256',
        'ledger_session_sha256',
        'prior_code_descriptor_sha256',
        'prior_verifier_inputs_sha256',
        'resources',
        'resources_inventory_sha256',
        'runtime_fingerprints_sha256',
        'scope_hash',
        'uploads_inventory_sha256',
    ];

    /** @var list<string> */
    private const TARGET_KEYS = [
        'artifact_hash',
        'claim_epoch',
        'claim_expires_at',
        'claimant',
        'event_chain_sha256',
        'generation',
        'head_event_sha256',
        'operation_target_id',
        'owner',
        'receipt_envelope_sha256',
        'receipt_id',
        'receipt_payload_sha256',
        'sequence',
        'state',
        'rollback_target_id',
        'target_record_sha256',
        'terminal',
    ];

    /**
     * Build one frozen recovery subject. Pure: no clock and no I/O.
     *
     * @param array<string,mixed> $inputs
     * @return array<string,mixed>
     */
    public static function build(array $inputs): array {
        self::assertExactKeys($inputs, self::INPUT_KEYS, 'recovery plan inputs');
        self::validateObservation($inputs);
        self::assertOperationId((string) $inputs['operation_id']);
        self::assertTimestamp((string) $inputs['prepared_at'], 'prepared_at');
        self::assertClaimActive($inputs, (string) $inputs['prepared_at']);

        $document = [
            'authority_policy_digest' => $inputs['authority_policy_digest'],
            'checkpoint' => $inputs['checkpoint'],
            'claim' => $inputs['claim'],
            'environment' => $inputs['environment'],
            'format' => self::FORMAT,
            'operation_id' => $inputs['operation_id'],
            'prepared_at' => $inputs['prepared_at'],
            'required_grants' => $inputs['required_grants'],
            'scope' => $inputs['scope'],
            'target' => $inputs['target'],
            'target_head' => $inputs['target_head'],
            'topology' => $inputs['topology'],
        ];
        $document['subject_digest'] = self::subjectDigest($document);
        $document['presentation_digest'] = self::presentationDigest($document);
        $document['plan_digest'] = self::digest($document);
        self::validate($document);

        return $document;
    }

    /** Canonical public bytes; preparation never writes them itself. */
    public static function encode(array $document): string {
        self::validate($document);

        return Canon::encode($document);
    }

    /** Git supports only the exact SHA-1 and SHA-256 object-id widths. */
    public static function isGitObjectId(string $value): bool {
        return preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $value) === 1;
    }

    /** Read one caller-selected canonical plan without accepting a symlink. */
    public static function read(string $path): array {
        if ((!file_exists($path) && !is_link($path)) || !is_file($path) || is_link($path)) {
            throw self::refuse(
                'recovery_plan_unreadable',
                'the required recovery plan is absent or is not an ordinary regular file'
            );
        }
        $raw = @file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded) || array_is_list($decoded) || !hash_equals(Canon::encode($decoded), (string) $raw)) {
            throw self::refuse(
                'recovery_plan_noncanonical',
                'the required recovery plan is not canonical ' . self::FORMAT . ' JSON'
            );
        }
        self::validate($decoded);

        return $decoded;
    }

    /** `sha256:` of the whole plan except its self-identifying digest. */
    public static function digest(array $document): string {
        unset($document['plan_digest']);

        return 'sha256:' . hash('sha256', Canon::encode($document));
    }

    /** Digest of the exact canonical plan projection an actor reviews. */
    public static function presentationDigest(array $document): string {
        unset($document['plan_digest'], $document['presentation_digest']);

        return 'sha256:' . hash('sha256', Canon::encode($document));
    }

    /**
     * The projection consumed by the shared actor-authority verifier.
     *
     * @return array{authority_policy_digest:string,operation:string,operation_id:string,presentation_digest:string,required_grants:list<string>,subject_digest:string,target_id:string}
     */
    public static function authorizationSubject(array $document): array {
        self::validate($document);

        return [
            'authority_policy_digest' => (string) $document['authority_policy_digest'],
            'operation' => 'recovery',
            'operation_id' => (string) $document['operation_id'],
            'presentation_digest' => (string) $document['presentation_digest'],
            'required_grants' => array_values($document['required_grants']),
            'subject_digest' => (string) $document['subject_digest'],
            'target_id' => (string) $document['target']['operation_target_id'],
        ];
    }

    /**
     * Identity of the complete recovery subject, excluding operation/time.
     *
     * Actor authority can therefore bind `(operation_id, subject_digest)`:
     * the operation remains one-time while the subject names only the target
     * and effects that would be recovered.
     *
     * @param array<string,mixed> $observation
     */
    public static function subjectDigest(array $observation): string {
        return self::factsDigest(self::currentFacts($observation));
    }

    /** @param array<string,mixed> $facts */
    public static function factsDigest(array $facts): string {
        return 'sha256:' . hash('sha256', Canon::encode($facts));
    }

    /**
     * The exact fact vector a future execute mutation gate must reacquire.
     *
     * Dotted keys are deliberate: a refusal can say `target.generation` or
     * `checkpoint.sha256` changed without publishing either sensitive value.
     *
     * @param array<string,mixed> $observation
     * @return array<string,mixed>
     */
    public static function currentFacts(array $observation): array {
        self::validateObservation($observation);
        $checkpoint = (array) $observation['checkpoint'];
        $scope = (array) $observation['scope'];
        $target = (array) $observation['target'];

        $facts = [
            'authority_policy_digest' => (string) $observation['authority_policy_digest'],
            'claim.claim_digest' => (string) $observation['claim']['claim_digest'],
            'environment' => (string) $observation['environment'],
            'required_grants' => array_values($observation['required_grants']),
            'target_head' => (string) $observation['target_head'],
            'topology' => (string) $observation['topology'],
        ];
        foreach (self::CHECKPOINT_KEYS as $key) {
            $facts['checkpoint.' . $key] = $checkpoint[$key];
        }
        foreach (self::SCOPE_KEYS as $key) {
            $facts['scope.' . $key] = $scope[$key];
        }
        foreach (self::TARGET_KEYS as $key) {
            $facts['target.' . $key] = $target[$key];
        }
        ksort($facts, SORT_STRING);

        return $facts;
    }

    /**
     * Assert the frozen subject is still the exact target subject now.
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $currentFacts returned by currentFacts()
     * @return array{at:string,facts_sha256:string,plan_digest:string,subject_digest:string}
     */
    public static function reverify(array $plan, array $currentFacts, string $at): array {
        self::validate($plan);
        self::assertTimestamp($at, 'reverified at');
        self::assertClaimActive($plan, $at);
        $frozen = self::currentFacts($plan);
        $changed = [];
        foreach (array_unique(array_merge(array_keys($frozen), array_keys($currentFacts))) as $field) {
            if (!array_key_exists($field, $frozen)
                || !array_key_exists($field, $currentFacts)
                || Canon::encode($frozen[$field]) !== Canon::encode($currentFacts[$field])) {
                $changed[] = $field;
            }
        }
        sort($changed, SORT_STRING);
        if ($changed !== []) {
            throw new CommandRefusalException(
                'recovery_plan_changed',
                'the recovery subject changed after preparation, so the prepared plan no longer applies',
                'run wprism recover <env> prepare again against the current target, review the new plan, and '
                    . 'obtain fresh authority before executing',
                [['changed_fields' => $changed]]
            );
        }
        $factsDigest = self::factsDigest($currentFacts);
        if (!hash_equals((string) $plan['subject_digest'], $factsDigest)) {
            throw self::refuse(
                'recovery_plan_digest_mismatch',
                'the current recovery facts do not match the prepared subject digest'
            );
        }

        return [
            'at' => $at,
            'facts_sha256' => $factsDigest,
            'plan_digest' => (string) $plan['plan_digest'],
            'subject_digest' => (string) $plan['subject_digest'],
        ];
    }

    /** Refuse a malformed or internally inconsistent frozen subject. */
    public static function validate(array $document): void {
        self::assertExactKeys($document, self::DOCUMENT_KEYS, 'recovery plan');
        if (($document['format'] ?? null) !== self::FORMAT) {
            throw self::refuse('recovery_plan_format_invalid', 'the document is not a ' . self::FORMAT);
        }
        self::assertOperationId((string) ($document['operation_id'] ?? ''));
        self::assertTimestamp((string) ($document['prepared_at'] ?? ''), 'prepared_at');
        self::validateObservation($document);
        self::assertClaimActive($document, (string) $document['prepared_at']);
        self::assertDigest((string) ($document['subject_digest'] ?? ''), 'subject digest');
        self::assertDigest((string) ($document['presentation_digest'] ?? ''), 'presentation digest');
        self::assertDigest((string) ($document['plan_digest'] ?? ''), 'plan digest');
        if (!hash_equals(self::subjectDigest($document), (string) $document['subject_digest'])) {
            throw self::refuse(
                'recovery_plan_digest_mismatch',
                'the recovery subject digest does not match its target, checkpoint, scope, and claim'
            );
        }
        if (!hash_equals(self::digest($document), (string) $document['plan_digest'])) {
            throw self::refuse(
                'recovery_plan_digest_mismatch',
                'the recovery plan digest does not match its own content'
            );
        }
        if (!hash_equals(self::presentationDigest($document), (string) $document['presentation_digest'])) {
            throw self::refuse(
                'recovery_plan_digest_mismatch',
                'the recovery presentation digest does not match the reviewed canonical plan projection'
            );
        }
    }

    /** @param array<string,mixed> $observation */
    private static function validateObservation(array $observation): void {
        self::assertDigest(
            is_string($observation['authority_policy_digest'] ?? null)
                ? $observation['authority_policy_digest']
                : '',
            'authority policy digest'
        );
        $requiredGrants = $observation['required_grants'] ?? null;
        if (!is_array($requiredGrants) || !array_is_list($requiredGrants)
            || $requiredGrants !== ['business_owner', 'operator_confirmation']) {
            throw self::refuse(
                'recovery_plan_shape_invalid',
                'the recovery plan does not name the complete sorted recovery authority grant set'
            );
        }
        $environment = $observation['environment'] ?? null;
        if (!is_string($environment) || trim($environment) === '' || strlen($environment) > 200) {
            throw self::refuse('recovery_plan_shape_invalid', 'the recovery plan names no valid environment');
        }
        if (($observation['topology'] ?? null) !== 'single-site') {
            throw self::refuse(
                'recovery_plan_shape_invalid',
                'the recovery plan does not bind the supported single-site topology'
            );
        }
        $head = $observation['target_head'] ?? null;
        if (!is_string($head) || !self::isGitObjectId($head)) {
            throw self::refuse('recovery_plan_shape_invalid', 'the recovery plan carries no exact target code head');
        }

        $claim = $observation['claim'] ?? null;
        if (!is_array($claim)) {
            throw self::refuse('recovery_plan_shape_invalid', 'the recovery plan carries no recovery claim');
        }
        RecoveryClaim::validate($claim);
        if (($claim['profile'] ?? null) !== RecoveryClaim::VERIFIED_AUTOMATIC) {
            throw self::refuse(
                'recovery_plan_identity_incomplete',
                'the recovery plan is not backed by the complete verified-automatic resource identity'
            );
        }

        $checkpoint = $observation['checkpoint'] ?? null;
        if (!is_array($checkpoint)) {
            throw self::refuse('recovery_plan_shape_invalid', 'the recovery plan carries no checkpoint identity');
        }
        self::assertExactKeys($checkpoint, self::CHECKPOINT_KEYS, 'recovery checkpoint');
        if (($checkpoint['kind'] ?? null) !== 'verified-promotion'
            || !is_int($checkpoint['bytes'] ?? null)
            || (int) $checkpoint['bytes'] < 1) {
            throw self::refuse(
                'recovery_plan_identity_incomplete',
                'the recovery checkpoint has no complete verified byte identity'
            );
        }
        self::assertIdentifier((string) ($checkpoint['id'] ?? ''), 'checkpoint id', 32, 64);
        self::assertHash((string) ($checkpoint['metadata_sha256'] ?? ''), 'checkpoint metadata hash');
        self::assertHash((string) ($checkpoint['sha256'] ?? ''), 'checkpoint byte hash');
        self::assertActor((string) ($checkpoint['encryption_key_id'] ?? ''), 'checkpoint encryption key id');
        self::assertTimestamp((string) ($checkpoint['created_at'] ?? ''), 'checkpoint created_at');
        self::assertTimestamp((string) ($checkpoint['retention_until'] ?? ''), 'checkpoint retention_until');

        $scope = $observation['scope'] ?? null;
        if (!is_array($scope)) {
            throw self::refuse('recovery_plan_shape_invalid', 'the recovery plan carries no recovery scope');
        }
        self::assertExactKeys($scope, self::SCOPE_KEYS, 'recovery scope');
        foreach ([
            'adapter_versions_sha256',
            'code_release_metadata_sha256',
            'effects_metadata_sha256',
            'ledger_session_sha256',
            'prior_code_descriptor_sha256',
            'prior_verifier_inputs_sha256',
            'resources_inventory_sha256',
            'runtime_fingerprints_sha256',
            'uploads_inventory_sha256',
        ] as $key) {
            self::assertHash((string) ($scope[$key] ?? ''), "recovery scope $key");
        }
        if ($scope['allow_deletes'] !== null || $scope['scope_hash'] !== null) {
            throw self::refuse(
                'recovery_plan_identity_incomplete',
                'the recovery scope is not a full verified-promotion receipt'
            );
        }
        $resources = $scope['resources'] ?? null;
        if (!is_array($resources) || !array_is_list($resources)
            || array_values($resources) !== RecoveryClaim::RESOURCES) {
            throw self::refuse(
                'recovery_plan_identity_incomplete',
                'the recovery scope does not prove the complete verified resource inventory'
            );
        }

        $target = $observation['target'] ?? null;
        if (!is_array($target)) {
            throw self::refuse('recovery_plan_shape_invalid', 'the recovery plan carries no target identity');
        }
        self::assertExactKeys($target, self::TARGET_KEYS, 'recovery target');
        self::assertHash((string) ($target['artifact_hash'] ?? ''), 'target artifact hash');
        if (preg_match('/^wprism-target:[a-f0-9]{64}$/D', (string) ($target['operation_target_id'] ?? '')) !== 1) {
            throw self::refuse(
                'recovery_plan_shape_invalid',
                'the recovery target has no stable operation-authority identity'
            );
        }
        self::assertIdentifier((string) ($target['rollback_target_id'] ?? ''), 'rollback target id', 32, 32);
        self::assertIdentifier((string) ($target['receipt_id'] ?? ''), 'target receipt id', 32, 64);
        foreach ([
            'event_chain_sha256',
            'head_event_sha256',
            'receipt_envelope_sha256',
            'receipt_payload_sha256',
            'target_record_sha256',
        ] as $key) {
            self::assertHash((string) ($target[$key] ?? ''), "target $key");
        }
        foreach (['generation', 'claim_epoch', 'sequence'] as $key) {
            if (!is_int($target[$key] ?? null) || (int) $target[$key] < 1) {
                throw self::refuse('recovery_plan_shape_invalid', "the recovery target $key is not positive");
            }
        }
        self::assertActor((string) ($target['owner'] ?? ''), 'target owner');
        self::assertActor((string) ($target['claimant'] ?? ''), 'target claimant');
        self::assertTimestamp((string) ($target['claim_expires_at'] ?? ''), 'target claim_expires_at');
        if (($target['terminal'] ?? null) !== false
            || !in_array((string) ($target['state'] ?? ''), self::ELIGIBLE_STATES, true)) {
            throw self::refuse(
                'recovery_plan_state_ineligible',
                'the selected signed generation has no nonterminal rollback transition available'
            );
        }
        if (!hash_equals((string) $checkpoint['id'], (string) $target['receipt_id'])) {
            throw self::refuse(
                'recovery_plan_identity_incomplete',
                'the checkpoint id does not match the signed target receipt'
            );
        }
    }

    /** @param list<string> $expected */
    private static function assertExactKeys(array $value, array $expected, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw self::refuse('recovery_plan_shape_invalid', "$label has missing or unknown fields");
        }
    }

    private static function assertOperationId(string $value): void {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@+\/-]{0,255}$/D', $value) !== 1) {
            throw self::refuse(
                'recovery_plan_operation_invalid',
                'the recovery operation id is not a safe stable identifier'
            );
        }
    }

    private static function assertIdentifier(string $value, string $label, int $min, int $max): void {
        $length = strlen($value);
        if ($length < $min || $length > $max || preg_match('/^[a-f0-9]+$/D', $value) !== 1) {
            throw self::refuse('recovery_plan_shape_invalid', "$label is malformed");
        }
    }

    private static function assertActor(string $value, string $label): void {
        if ($value === '' || strlen($value) > 200 || preg_match('/^[A-Za-z0-9._:@+\/-]+$/D', $value) !== 1) {
            throw self::refuse('recovery_plan_shape_invalid', "$label is malformed");
        }
    }

    private static function assertHash(string $value, string $label): void {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw self::refuse('recovery_plan_shape_invalid', "$label is not a sha256 hash");
        }
    }

    private static function assertDigest(string $value, string $label): void {
        if (preg_match('/^sha256:[a-f0-9]{64}$/D', $value) !== 1) {
            throw self::refuse('recovery_plan_shape_invalid', "$label is not a sha256: digest");
        }
    }

    /** @param array<string,mixed> $observation */
    private static function assertClaimActive(array $observation, string $at): void {
        $expires = self::assertTimestamp(
            (string) ($observation['target']['claim_expires_at'] ?? ''),
            'target claim_expires_at'
        );
        if ($expires <= self::assertTimestamp($at, 'recovery claim eligibility time')) {
            throw new CommandRefusalException(
                'recovery_claim_expired',
                'the frozen rollback claimant lease expired before the recovery mutation boundary',
                'do not consume actor authority; establish the next valid rollback claimant epoch, then prepare '
                    . 'and authorize a fresh recovery subject'
            );
        }
    }

    private static function assertTimestamp(string $value, string $label): int {
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if (!$time || $time->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw self::refuse('recovery_plan_shape_invalid', "$label is not canonical UTC seconds");
        }

        return $time->getTimestamp();
    }

    private static function refuse(string $code, string $message): CommandRefusalException {
        return new CommandRefusalException(
            $code,
            $message,
            'do not execute recovery; prepare a fresh plan from a complete signed checkpoint identity'
        );
    }
}

<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/**
 * The published `duo-branch-environment-provider-request/v1` and
 * `duo-branch-environment-provider-response/v1` protocol, as data.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * Every rule a customer's provider must satisfy was previously reachable only
 * by triggering one refusal at a time out of
 * `CommandEnvironmentProvider::validateActionResult()`
 * (EnvironmentLifecycle.php:357-476), `assertAction()` (:536-547) and the field
 * validators at (:478-535) — and the only way to reach those refusals was
 * `duo env materialize`, whose second provider action freezes the named
 * production source (`snapshot-prepare`, EnvironmentLifecycle.php:1017-1021).
 * "Debug your provider against prod" is not an onboarding path, so the closed
 * key sets and their field types are extracted here ONCE and read back by the
 * orchestrator, by `duo env provider-check`, and by the generator that writes
 * docs/branch-environment-provider.md.
 *
 * THE ANTI-DRIFT CONTRACT
 * -----------------------
 * This table is not a second implementation of the boundary. The orchestrator
 * reads its key sets and its action list from here, so a key can only drift by
 * moving in one place. The FIELD TYPES below are the part that is stated twice
 * (here as a token, in CommandEnvironmentProvider as a private assertion), and
 * `sandbox/tests/offline/environment/regress_env_provider_conformance.php`
 * property 1 is what keeps that honest: for all 18 actions it builds a
 * conformant result, then drops / retypes / extra-keys each declared field in
 * turn and requires the LIVE validator to refuse. A token that stops matching
 * the validator fails offline, not in a customer's production freeze.
 *
 * Capability ids are plain strings here rather than
 * `EnvironmentProviderCapability::` constants: that class lives in
 * EnvironmentLifecycle.php, which requires this file, and a back-reference
 * would be a require cycle. The same offline suite asserts every string below
 * is a member of `EnvironmentProviderCapability::all()`.
 */
final class EnvironmentProviderProtocol {
    /** A `[A-Za-z0-9._:@+-]{8,256}` opaque provider-owned identity. */
    public const TYPE_IDENTIFIER = 'identifier';
    /** Lowercase hex SHA-256, 64 characters, unprefixed. */
    public const TYPE_SHA256 = 'sha256';
    /** JSON integer >= 1. A float, a numeric string, or 0 is a refusal. */
    public const TYPE_POSITIVE_INT = 'positive-int';
    /** `YYYY-MM-DDTHH:MM:SSZ` exactly — canonical UTC seconds, no offset form. */
    public const TYPE_UTC_SECOND = 'utc-second';
    /** 40 or 64 lowercase hex characters (SHA-1 or SHA-256 Git object id). */
    public const TYPE_GIT_OID = 'git-oid';
    /** Credential-free http(s) base URL, <= 2048 bytes, no query or fragment. */
    public const TYPE_BASE_URL = 'base-url';
    /** JSON `true`. Nothing else, including 1 or "true". */
    public const TYPE_TRUE = 'true';
    /** A list of capability ids drawn from EnvironmentProviderCapability::all(). */
    public const TYPE_CAPABILITY_LIST = 'capability-list';
    /* The three tokens below appear only on REQUEST fields. The orchestrator
     * closes and types the RESPONSE and leaves the request open (call(),
     * EnvironmentLifecycle.php:254-263), so these describe what a provider
     * will actually receive rather than something it can be refused over. */
    /** A registry environment name as written in site.duo.json / .duo-envs.json. */
    public const TYPE_ENV_NAME = 'environment-name';
    /** An absolute filesystem path on the host that runs `duo`. */
    public const TYPE_PATH = 'absolute-path';
    /** A Git ref name, already resolved to the accompanying object id. */
    public const TYPE_GIT_REF = 'git-ref';
    /** `enum:a|b` — one of the named string values. */
    public const TYPE_ENUM_PREFIX = 'enum:';

    /**
     * The identity block every resource-bearing result repeats verbatim.
     * Mirrors `$identityKeys` at EnvironmentLifecycle.php:363-366 plus
     * `validateIdentity()` (:501-535).
     */
    private const IDENTITY_RESULT = [
        'environment_identity' => self::TYPE_IDENTIFIER,
        'lease_generation' => self::TYPE_POSITIVE_INT,
        'lease_id' => self::TYPE_IDENTIFIER,
        'ownership_receipt_sha256' => self::TYPE_SHA256,
        'resource_id' => self::TYPE_IDENTIFIER,
        'url' => self::TYPE_BASE_URL,
    ];

    /** The snapshot-set block shared by snapshot-create and snapshot-read (:380-397, validateSnapshotSet() :465-476). */
    private const SNAPSHOT_SET_RESULT = [
        'database_sha256' => self::TYPE_SHA256,
        'lease_generation' => self::TYPE_POSITIVE_INT,
        'lease_id' => self::TYPE_IDENTIFIER,
        'lease_receipt_sha256' => self::TYPE_SHA256,
        'media_sha256' => self::TYPE_SHA256,
        'retention_receipt_sha256' => self::TYPE_SHA256,
        'semantic_snapshot_sha256' => self::TYPE_SHA256,
        'snapshot_session_id' => self::TYPE_IDENTIFIER,
        'snapshot_set_id' => self::TYPE_IDENTIFIER,
        'snapshot_set_receipt_sha256' => self::TYPE_SHA256,
        'source_identity' => self::TYPE_IDENTIFIER,
    ];

    /** The source snapshot-session block (snapshot-prepare / snapshot-abort). */
    private const SNAPSHOT_SESSION_RESULT = [
        'lease_generation' => self::TYPE_POSITIVE_INT,
        'lease_id' => self::TYPE_IDENTIFIER,
        'lease_receipt_sha256' => self::TYPE_SHA256,
        'snapshot_session_id' => self::TYPE_IDENTIFIER,
        'source_identity' => self::TYPE_IDENTIFIER,
    ];

    /** `identityInput()` — EnvironmentLifecycle.php:1835-1843. */
    private const IDENTITY_INPUT = [
        'expected_environment_identity' => self::TYPE_IDENTIFIER,
        'expected_lease_generation' => self::TYPE_POSITIVE_INT,
        'expected_lease_id' => self::TYPE_IDENTIFIER,
        'expected_ownership_receipt_sha256' => self::TYPE_SHA256,
        'expected_resource_id' => self::TYPE_IDENTIFIER,
    ];

    /** `mutationInput()` — EnvironmentLifecycle.php:2005-2012. */
    private const MUTATION_INPUT = [
        'expected_mutation_generation' => self::TYPE_POSITIVE_INT,
        'expected_mutation_id' => self::TYPE_IDENTIFIER,
        'expected_mutation_owner' => self::TYPE_IDENTIFIER,
        'expected_mutation_receipt_sha256' => self::TYPE_SHA256,
    ];

    /** `ttlInput()` — EnvironmentLifecycle.php:2043-2050. */
    private const TTL_INPUT = [
        'expected_expires_at' => self::TYPE_UTC_SECOND,
        'expected_ttl_generation' => self::TYPE_POSITIVE_INT,
        'expected_ttl_lease_id' => self::TYPE_IDENTIFIER,
        'expected_ttl_receipt_sha256' => self::TYPE_SHA256,
    ];

    /** The source snapshot-session expectations echoed back on every later source action. */
    private const SNAPSHOT_SESSION_INPUT = [
        'expected_snapshot_session_id' => self::TYPE_IDENTIFIER,
        'expected_source_identity' => self::TYPE_IDENTIFIER,
        'expected_source_lease_generation' => self::TYPE_POSITIVE_INT,
        'expected_source_lease_id' => self::TYPE_IDENTIFIER,
        'expected_source_lease_receipt_sha256' => self::TYPE_SHA256,
    ];

    /**
     * The 18 actions, in the orchestrator's own order.
     *
     * `assertAction()` (EnvironmentLifecycle.php:536-547) reads this list, so
     * the vocabulary has exactly one definition.
     *
     * @return list<string>
     */
    public static function actions(): array {
        return array_keys(self::table());
    }

    /**
     * The capability id that gates one action, or null for `capabilities`
     * (negotiation itself cannot be gated on a negotiated capability).
     */
    public static function capabilityFor(string $action): ?string {
        return self::entry($action)['capability'];
    }

    /**
     * The closed RESULT key set for one action, sorted.
     *
     * `validateActionResult()` (EnvironmentLifecycle.php:357-476) hands this to
     * `assertExactKeys()`, which sorts and compares, so ordering here is
     * presentational only.
     *
     * @return list<string>
     */
    public static function resultKeys(string $action): array {
        $keys = array_keys(self::entry($action)['result']);
        sort($keys, SORT_STRING);
        return $keys;
    }

    /**
     * Per-field type tokens for one action's RESULT object.
     *
     * @return array<string,string> field => type token
     */
    public static function resultFields(string $action): array {
        $fields = self::entry($action)['result'];
        ksort($fields, SORT_STRING);
        return $fields;
    }

    /**
     * What the orchestrator sends in `input` for one action.
     *
     * The request `input` object is deliberately NOT a closed set at this
     * boundary: `call()` (EnvironmentLifecycle.php:254-263) requires only that
     * it be an object. `required` keys are sent on every call of that action;
     * `conditional` keys are sent only in the phases named by `notes`. A
     * provider must refuse when a key it needs is absent, and must not refuse
     * merely because a key it ignores is present.
     *
     * @return array{required:array<string,string>,conditional:array<string,string>,notes:string}
     */
    public static function requestInput(string $action): array {
        $entry = self::entry($action);
        return [
            'required' => $entry['input'],
            'conditional' => $entry['input_conditional'],
            'notes' => $entry['input_notes'],
        ];
    }

    /**
     * Every capability set the orchestrator requires before it will act, in
     * the exact wording `EnvironmentProviderCapabilityReport::require()`
     * (EnvironmentLifecycle.php:92-105) prints after "cannot ".
     *
     * @return list<array{id:string,side:string,operation:string,capabilities:list<string>,conditional:list<string>,conditional_when:string}>
     */
    public static function requirementSets(): array {
        $sets = [];
        foreach (['create', 'attach'] as $mode) {
            $reap = $mode === 'create' ? 'destroy' : 'detach';
            $sets[] = [
                'id' => 'materialize-target-' . $mode,
                'side' => 'target',
                // EnvironmentLifecycle.php:974-992.
                'operation' => "materialize a $mode branch environment",
                'capabilities' => self::sorted([
                    'environment.inspect', 'snapshot.set.restore', 'repository.materialize',
                    'environment.url.discover', 'environment.url.set',
                    'environment.mutation.acquire', 'environment.mutation.read', 'environment.mutation.release',
                    'operation.receipts', 'environment.' . $mode, 'environment.' . $reap,
                ]),
                'conditional' => ['environment.ttl', 'environment.ttl.read'],
                'conditional_when' => '`duo env materialize --ttl <seconds>` is given',
            ];
        }
        // EnvironmentLifecycle.php:965-972.
        $sets[] = [
            'id' => 'materialize-source',
            'side' => 'source',
            'operation' => 'materialize a coherent production snapshot',
            'capabilities' => self::sorted([
                'environment.inspect', 'snapshot.set.prepare', 'snapshot.set.create',
                'snapshot.set.read', 'snapshot.set.abort', 'operation.receipts',
            ]),
            'conditional' => [],
            'conditional_when' => '',
        ];
        foreach (['create', 'attach'] as $mode) {
            $reap = $mode === 'create' ? 'destroy' : 'detach';
            // EnvironmentLifecycle.php:1521-1530.
            $sets[] = [
                'id' => 'reap-target-' . $mode,
                'side' => 'target',
                'operation' => "reap a $mode branch environment",
                'capabilities' => self::sorted([
                    'environment.inspect', 'environment.mutation.acquire', 'environment.mutation.read',
                    'environment.mutation.release', 'operation.receipts', 'environment.' . $reap,
                ]),
                'conditional' => ['environment.ttl.read'],
                'conditional_when' => 'the materialization journaled a `ttl-set` phase',
            ];
        }
        // EnvironmentLifecycle.php:1473-1478.
        $sets[] = [
            'id' => 'reap-source-session',
            'side' => 'source',
            'operation' => 'reap an unfinished source snapshot session',
            'capabilities' => self::sorted([
                'snapshot.set.prepare', 'snapshot.set.abort', 'operation.receipts',
            ]),
            'conditional' => [],
            'conditional_when' => '',
        ];
        foreach (['create', 'attach'] as $mode) {
            // EnvironmentLifecycle.php:1445-1450.
            $sets[] = [
                'id' => 'recover-target-' . $mode,
                'side' => 'target',
                'operation' => "recover a lost target $mode response",
                'capabilities' => self::sorted(['environment.' . $mode, 'operation.receipts']),
                'conditional' => [],
                'conditional_when' => '',
            ];
        }
        return $sets;
    }

    /**
     * Re-diagnose one captured provider result against the declared table.
     *
     * The orchestrator's own refusal is the verdict; this only names the field
     * that caused it. `assertExactKeys()` (EnvironmentLifecycle.php:548-556)
     * deliberately says no more than "has missing or unknown fields" — the
     * boundary must not describe host internals back to an untrusted provider
     * — so the naming lives here, on the operator's side of the boundary,
     * where `duo env provider-check` renders it.
     *
     * @return ?array{action:string,field:string,expected:string,observed:string}
     */
    public static function diagnose(string $action, mixed $result): ?array {
        if (!is_array($result) || (array_is_list($result) && $result !== [])) {
            return self::finding($action, '(result)', 'a JSON object', self::observed($result));
        }
        $fields = self::resultFields($action);
        foreach (array_keys($result) as $key) {
            if (!is_string($key) || !array_key_exists($key, $fields)) {
                return self::finding($action, is_string($key) ? $key : (string) $key, 'not present (the result key set is closed)', 'present');
            }
        }
        foreach ($fields as $field => $type) {
            if (!array_key_exists($field, $result)) {
                return self::finding($action, $field, self::describeType($type), 'absent');
            }
            if (!self::matchesType($type, $result[$field])) {
                return self::finding($action, $field, self::describeType($type), self::observed($result[$field]));
            }
        }
        return null;
    }

    /** Does one value satisfy one type token? Mirrors the assertions at EnvironmentLifecycle.php:478-535. */
    public static function matchesType(string $type, mixed $value): bool {
        if (str_starts_with($type, self::TYPE_ENUM_PREFIX)) {
            return is_string($value)
                && in_array($value, explode('|', substr($type, strlen(self::TYPE_ENUM_PREFIX))), true);
        }
        switch ($type) {
            case self::TYPE_IDENTIFIER:
                return is_string($value) && preg_match('/^[A-Za-z0-9._:@+-]{8,256}$/D', $value) === 1;
            case self::TYPE_SHA256:
                return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
            case self::TYPE_POSITIVE_INT:
                return is_int($value) && $value >= 1;
            case self::TYPE_GIT_OID:
                return is_string($value) && preg_match('/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/D', $value) === 1;
            case self::TYPE_TRUE:
                return $value === true;
            case self::TYPE_UTC_SECOND:
                if (!is_string($value)) {
                    return false;
                }
                $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
                return $time !== false && $time->format('Y-m-d\TH:i:s\Z') === $value;
            case self::TYPE_ENV_NAME:
            case self::TYPE_GIT_REF:
                return is_string($value) && $value !== '' && !str_contains($value, "\0");
            case self::TYPE_PATH:
                return is_string($value) && $value !== '' && $value[0] === '/' && !str_contains($value, "\0");
            case self::TYPE_CAPABILITY_LIST:
                if (!is_array($value) || !array_is_list($value)) {
                    return false;
                }
                foreach ($value as $entry) {
                    if (!is_string($entry)) {
                        return false;
                    }
                }
                return true;
            case self::TYPE_BASE_URL:
                $parts = is_string($value) ? parse_url($value) : false;
                return is_string($value)
                    && strlen($value) <= 2048
                    && filter_var($value, FILTER_VALIDATE_URL) !== false
                    && is_array($parts)
                    && isset($parts['scheme'], $parts['host'])
                    && in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
                    && !isset($parts['user']) && !isset($parts['pass'])
                    && !isset($parts['query']) && !isset($parts['fragment']);
        }
        throw new \RuntimeException("unknown environment provider field type '$type'");
    }

    /** One human sentence per type token, for the harness and the generated document. */
    public static function describeType(string $type): string {
        if (str_starts_with($type, self::TYPE_ENUM_PREFIX)) {
            $values = explode('|', substr($type, strlen(self::TYPE_ENUM_PREFIX)));
            return count($values) === 1
                ? 'exactly "' . $values[0] . '"'
                : 'one of "' . implode('", "', $values) . '"';
        }
        return match ($type) {
            self::TYPE_IDENTIFIER => 'an opaque identifier matching [A-Za-z0-9._:@+-]{8,256}',
            self::TYPE_SHA256 => 'a lowercase 64-character hex SHA-256',
            self::TYPE_POSITIVE_INT => 'a JSON integer >= 1',
            self::TYPE_UTC_SECOND => 'canonical UTC seconds, YYYY-MM-DDTHH:MM:SSZ',
            self::TYPE_GIT_OID => 'a 40- or 64-character lowercase hex Git object id',
            self::TYPE_BASE_URL => 'a credential-free http(s) base URL under 2048 bytes with no query or fragment',
            self::TYPE_TRUE => 'JSON true',
            self::TYPE_CAPABILITY_LIST => 'a JSON list of capability id strings',
            self::TYPE_ENV_NAME => 'a registry environment name',
            self::TYPE_PATH => 'an absolute filesystem path on the orchestrating host',
            self::TYPE_GIT_REF => 'a Git ref name',
            default => throw new \RuntimeException("unknown environment provider field type '$type'"),
        };
    }

    /** A one-line, secret-free rendering of what a provider actually sent. */
    public static function observed(mixed $value): string {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return "the integer $value";
        }
        if (is_float($value)) {
            return 'a JSON number with a fractional part';
        }
        if (is_array($value)) {
            return array_is_list($value) ? 'a JSON list' : 'a JSON object';
        }
        if (is_string($value)) {
            return $value === '' ? 'an empty string' : 'a ' . strlen($value) . '-byte string';
        }
        return 'an unsupported JSON value';
    }

    /** @return array{action:string,field:string,expected:string,observed:string} */
    private static function finding(string $action, string $field, string $expected, string $observed): array {
        return ['action' => $action, 'field' => $field, 'expected' => $expected, 'observed' => $observed];
    }

    /** @param list<string> $ids @return list<string> */
    private static function sorted(array $ids): array {
        sort($ids, SORT_STRING);
        return $ids;
    }

    /** @return array{capability:?string,result:array<string,string>,input:array<string,string>,input_conditional:array<string,string>,input_notes:string} */
    private static function entry(string $action): array {
        $table = self::table();
        if (!isset($table[$action])) {
            // Same wording the orchestrator uses, because a caller reaching
            // here has made exactly the mistake assertAction() names
            // (EnvironmentLifecycle.php:544).
            throw new \RuntimeException("unknown environment provider action '$action'");
        }
        return $table[$action];
    }

    /**
     * @return array<string,array{capability:?string,result:array<string,string>,input:array<string,string>,input_conditional:array<string,string>,input_notes:string}>
     */
    private static function table(): array {
        $identityResult = self::IDENTITY_RESULT;
        $fenced = self::IDENTITY_INPUT + self::MUTATION_INPUT;
        $mutationResult = $identityResult + [
            'mutation_generation' => self::TYPE_POSITIVE_INT,
            'mutation_id' => self::TYPE_IDENTIFIER,
            'mutation_owner' => self::TYPE_IDENTIFIER,
            'mutation_receipt_sha256' => self::TYPE_SHA256,
        ];
        $ttlResult = $identityResult + [
            'expires_at' => self::TYPE_UTC_SECOND,
            'ttl_generation' => self::TYPE_POSITIVE_INT,
            'ttl_lease_id' => self::TYPE_IDENTIFIER,
            'ttl_receipt_sha256' => self::TYPE_SHA256,
            'ttl_state' => self::TYPE_ENUM_PREFIX . 'active',
        ];
        $reapResult = [
            'absence_proof_sha256' => self::TYPE_SHA256,
            'environment_identity' => self::TYPE_IDENTIFIER,
            'lease_generation' => self::TYPE_POSITIVE_INT,
            'lease_id' => self::TYPE_IDENTIFIER,
            'ownership_receipt_sha256' => self::TYPE_SHA256,
            'resource_id' => self::TYPE_IDENTIFIER,
        ];
        return [
            'capabilities' => [
                'capability' => null,
                'result' => ['capabilities' => self::TYPE_CAPABILITY_LIST],
                'input' => [],
                'input_conditional' => [],
                'input_notes' => 'The negotiation probe is the one request whose `input` is the empty JSON LIST `[]` rather than an object.',
            ],
            'inspect' => [
                'capability' => 'environment.inspect',
                'result' => $identityResult + ['presence' => self::TYPE_ENUM_PREFIX . 'present|absent'],
                'input' => ['role' => self::TYPE_ENUM_PREFIX . 'source|target'],
                'input_conditional' => self::IDENTITY_INPUT,
                'input_notes' => 'The identity expectations are sent only once the orchestrator already holds a target identity (EnvironmentLifecycle.php:1319, :1588); the very first source inspect carries `role` alone (:1005).',
            ],
            'attach' => [
                'capability' => 'environment.attach',
                'result' => $identityResult + ['presence' => self::TYPE_ENUM_PREFIX . 'present'],
                'input' => [
                    'intent_sha256' => self::TYPE_SHA256,
                    'mode' => self::TYPE_ENUM_PREFIX . 'attach',
                    'target_environment' => self::TYPE_ENV_NAME,
                ],
                'input_conditional' => [],
                'input_notes' => 'EnvironmentLifecycle.php:1139-1142. The same object is replayed verbatim on recovery, so acquisition must be idempotent under `intent_sha256`.',
            ],
            'create' => [
                'capability' => 'environment.create',
                'result' => $identityResult + ['presence' => self::TYPE_ENUM_PREFIX . 'present'],
                'input' => [
                    'intent_sha256' => self::TYPE_SHA256,
                    'mode' => self::TYPE_ENUM_PREFIX . 'create',
                    'target_environment' => self::TYPE_ENV_NAME,
                ],
                'input_conditional' => [],
                'input_notes' => 'EnvironmentLifecycle.php:1139-1142. The same object is replayed verbatim on recovery, so acquisition must be idempotent under `intent_sha256`.',
            ],
            'snapshot-prepare' => [
                'capability' => 'snapshot.set.prepare',
                'result' => self::SNAPSHOT_SESSION_RESULT,
                'input' => self::IDENTITY_INPUT + ['snapshot_session_id' => self::TYPE_IDENTIFIER],
                'input_conditional' => [],
                'input_notes' => 'EnvironmentLifecycle.php:1017. This is the action that FREEZES the named source; the session id is deterministic per operation so a lost response is retryable and abortable.',
            ],
            'snapshot-create' => [
                'capability' => 'snapshot.set.create',
                'result' => self::SNAPSHOT_SET_RESULT,
                'input' => self::SNAPSHOT_SESSION_INPUT + [
                    'expected_semantic_snapshot_sha256' => self::TYPE_SHA256,
                    'production_commit' => self::TYPE_GIT_OID,
                ],
                'input_conditional' => [],
                'input_notes' => 'EnvironmentLifecycle.php:1074-1082. `semantic_snapshot_sha256` in the result must echo `expected_semantic_snapshot_sha256` exactly.',
            ],
            'snapshot-abort' => [
                'capability' => 'snapshot.set.abort',
                'result' => self::SNAPSHOT_SESSION_RESULT + ['disposition' => self::TYPE_ENUM_PREFIX . 'aborted'],
                'input' => self::SNAPSHOT_SESSION_INPUT,
                'input_conditional' => [],
                'input_notes' => 'EnvironmentLifecycle.php:2141-2145. Aborting must release the source freeze and discard any immutable set the same session created.',
            ],
            'snapshot-read' => [
                'capability' => 'snapshot.set.read',
                'result' => self::SNAPSHOT_SET_RESULT + ['immutable' => self::TYPE_TRUE],
                'input' => self::SNAPSHOT_SESSION_INPUT + [
                    'expected_snapshot_set_id' => self::TYPE_IDENTIFIER,
                    'expected_snapshot_set_receipt_sha256' => self::TYPE_SHA256,
                ],
                'input_notes' => 'EnvironmentLifecycle.php:1113-1121. `immutable: true` is a claim that the set was re-read from storage, not remembered.',
                'input_conditional' => [],
            ],
            'snapshot-restore' => [
                'capability' => 'snapshot.set.restore',
                'result' => $identityResult + ['snapshot_set_id' => self::TYPE_IDENTIFIER],
                'input' => $fenced + [
                    'database_sha256' => self::TYPE_SHA256,
                    'media_sha256' => self::TYPE_SHA256,
                    'snapshot_set_id' => self::TYPE_IDENTIFIER,
                ],
                'input_conditional' => [],
                'input_notes' => 'EnvironmentLifecycle.php:1210-1214. Restoring another set than `snapshot_set_id` is refused by the orchestrator on readback (:1220).',
            ],
            'repository-materialize' => [
                'capability' => 'repository.materialize',
                'result' => $identityResult + [
                    'branch_commit' => self::TYPE_GIT_OID,
                    'repository_receipt_sha256' => self::TYPE_SHA256,
                ],
                'input' => $fenced + [
                    'branch_commit' => self::TYPE_GIT_OID,
                    'branch_ref' => self::TYPE_GIT_REF,
                    'repo_path' => self::TYPE_PATH,
                ],
                'input_conditional' => [],
                'input_notes' => 'EnvironmentLifecycle.php:1223-1228. The result `branch_commit` must equal the requested one (:1232).',
            ],
            'url-set' => [
                'capability' => 'environment.url.set',
                'result' => $identityResult,
                'input' => $fenced + ['url' => self::TYPE_BASE_URL],
                'input_conditional' => [],
                'input_notes' => 'EnvironmentLifecycle.php:1234-1238. The URL is the provider-owned one it discovered itself; the readback must match it (:1241).',
            ],
            'mutation-acquire' => [
                'capability' => 'environment.mutation.acquire',
                'result' => $mutationResult + ['state' => self::TYPE_ENUM_PREFIX . 'held'],
                'input' => self::IDENTITY_INPUT + ['mutation_owner' => self::TYPE_IDENTIFIER],
                'input_conditional' => [],
                'input_notes' => 'EnvironmentLifecycle.php:1148. Acquisition is idempotent per `mutation_owner`: a re-acquire by the SAME owner returns the same fence, a different owner must refuse.',
            ],
            'mutation-read' => [
                'capability' => 'environment.mutation.read',
                'result' => $mutationResult + ['state' => self::TYPE_ENUM_PREFIX . 'held|released'],
                'input' => $fenced,
                'input_conditional' => [],
                'input_notes' => 'EnvironmentLifecycle.php:1195. A readback may never mint a new `mutation_receipt_sha256` (:2027-2033).',
            ],
            'mutation-release' => [
                'capability' => 'environment.mutation.release',
                'result' => $mutationResult + ['state' => self::TYPE_ENUM_PREFIX . 'released'],
                'input' => $fenced,
                'input_conditional' => [],
                'input_notes' => 'EnvironmentLifecycle.php:1347. The held -> released acknowledgement is the ONE transition allowed to mint a new receipt for the same lineage.',
            ],
            'ttl-set' => [
                'capability' => 'environment.ttl',
                'result' => $ttlResult,
                'input' => $fenced + ['ttl_seconds' => self::TYPE_POSITIVE_INT],
                'input_conditional' => [],
                'input_notes' => 'EnvironmentLifecycle.php:1327. A TTL is observable expiry metadata only; expiry never implies the resource may be reused or reaped without the explicit fenced reap action.',
            ],
            'ttl-read' => [
                'capability' => 'environment.ttl.read',
                'result' => $ttlResult,
                'input' => self::IDENTITY_INPUT + self::TTL_INPUT,
                'input_conditional' => self::MUTATION_INPUT,
                'input_notes' => 'The mutation expectations are present while the materialization fence is still held (EnvironmentLifecycle.php:1335) and absent on the reap readback (:1593).',
            ],
            'destroy' => [
                'capability' => 'environment.destroy',
                'result' => $reapResult + ['disposition' => self::TYPE_ENUM_PREFIX . 'destroyed'],
                'input' => $fenced + ['compare_and_reap' => self::TYPE_TRUE],
                'input_conditional' => [],
                'input_notes' => 'EnvironmentLifecycle.php:1735. Note the result carries NO `url`: after a reap there is no environment to address.',
            ],
            'detach' => [
                'capability' => 'environment.detach',
                'result' => $reapResult + ['disposition' => self::TYPE_ENUM_PREFIX . 'detached'],
                'input' => $fenced + ['compare_and_reap' => self::TYPE_TRUE],
                'input_conditional' => [],
                'input_notes' => 'EnvironmentLifecycle.php:1735. Serving `detach` where `destroy` was asked (or the reverse) converts a missing capability into a silent data-loss class; the disposition is what proves which one ran.',
            ],
        ];
    }
}

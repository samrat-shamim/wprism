<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';

use WPrism\Canon;

/** Stable, externally consumable adoption-to-release handoff evidence. */
final class OnboardingHandoffReceipt {
    public const FORMAT = 'wprism-onboarding-handoff/v1';
    public const NEXT_ACTIONS = [
        'review_application_contract',
        'enroll_operation_authority',
        'prepare_release',
    ];

    /**
     * @param array<string,mixed> $facts
     * @return array<string,mixed>
     */
    public static function build(array $facts): array {
        $environmentBody = [
            'config_sha256' => (string) $facts['environment_config_sha256'],
            'driver' => (string) $facts['driver'],
            'name' => (string) $facts['environment'],
            'repo_path' => (string) $facts['repo_path'],
        ];
        $environmentBody['generation_sha256'] = self::digest([
            'environment' => $environmentBody,
            'repository_commit' => (string) $facts['commit'],
            'target_id' => (string) $facts['target_id'],
        ]);
        $document = [
            'application_contract' => $facts['application_contract'],
            'assessment' => $facts['assessment'],
            'authority_policy' => $facts['authority_policy'],
            'created_at' => (string) $facts['created_at'],
            'environment' => $environmentBody,
            'format' => self::FORMAT,
            'next_action' => (string) $facts['next_action'],
            'repository' => [
                'branch' => (string) $facts['branch'],
                'commit' => (string) $facts['commit'],
                'handoff_ref' => 'refs/wprism/handoff/' . (string) $facts['branch'],
                'remote_sha256' => self::digestBytes((string) $facts['git_url']),
                'tree' => (string) $facts['tree'],
            ],
            'target' => [
                'id' => (string) $facts['target_id'],
                'identity_sha256' => self::digest(['target_id' => (string) $facts['target_id']]),
            ],
        ];
        $document['receipt_sha256'] = self::digest($document);
        self::validate($document);

        return Canon::normalize($document);
    }

    /** @param array<string,mixed> $document */
    public static function validate(array $document): void {
        self::keys($document, [
            'application_contract', 'assessment', 'authority_policy', 'created_at',
            'environment', 'format', 'next_action', 'receipt_sha256', 'repository', 'target',
        ], 'handoff receipt');
        if (($document['format'] ?? null) !== self::FORMAT
            || !self::timestamp($document['created_at'] ?? null)
            || !in_array($document['next_action'] ?? null, self::NEXT_ACTIONS, true)) {
            throw new \InvalidArgumentException('onboarding handoff identity fields are invalid');
        }

        $environment = self::object($document['environment'] ?? null, 'environment');
        self::keys($environment, ['config_sha256', 'driver', 'generation_sha256', 'name', 'repo_path'], 'environment');
        if (!self::environment($environment['name'] ?? null)
            || !self::text($environment['driver'] ?? null)
            || !self::absolutePath($environment['repo_path'] ?? null)
            || !self::digestValue($environment['config_sha256'] ?? null)
            || !self::digestValue($environment['generation_sha256'] ?? null)) {
            throw new \InvalidArgumentException('onboarding handoff environment binding is invalid');
        }

        $repository = self::object($document['repository'] ?? null, 'repository');
        self::keys($repository, ['branch', 'commit', 'handoff_ref', 'remote_sha256', 'tree'], 'repository');
        if (!self::branch($repository['branch'] ?? null)
            || !self::gitOid($repository['commit'] ?? null)
            || !self::gitOid($repository['tree'] ?? null)
            || ($repository['handoff_ref'] ?? null) !== 'refs/wprism/handoff/' . $repository['branch']
            || !self::digestValue($repository['remote_sha256'] ?? null)) {
            throw new \InvalidArgumentException('onboarding handoff repository binding is invalid');
        }

        $target = self::object($document['target'] ?? null, 'target');
        self::keys($target, ['id', 'identity_sha256'], 'target');
        if (!is_string($target['id'] ?? null)
            || preg_match('/^wprism-target:[a-f0-9]{64}$/D', $target['id']) !== 1
            || ($target['identity_sha256'] ?? null) !== self::digest(['target_id' => $target['id']])) {
            throw new \InvalidArgumentException('onboarding handoff target binding is invalid');
        }

        $assessment = self::object($document['assessment'] ?? null, 'assessment');
        self::keys($assessment, ['assess_digest', 'format', 'generated_at', 'review_required_count'], 'assessment');
        if (($assessment['format'] ?? null) !== 'wprism-assess-report/v1'
            || !self::digestValue($assessment['assess_digest'] ?? null)
            || !self::timestamp($assessment['generated_at'] ?? null)
            || !is_int($assessment['review_required_count'] ?? null)
            || $assessment['review_required_count'] < 0) {
            throw new \InvalidArgumentException('onboarding handoff assessment evidence is invalid');
        }

        $contract = self::object($document['application_contract'] ?? null, 'application_contract');
        self::keys($contract, ['contract_digest', 'status'], 'application_contract');
        if (!in_array($contract['status'] ?? null, ['proposed', 'accepted', 'attested'], true)
            || !self::digestValue($contract['contract_digest'] ?? null)) {
            throw new \InvalidArgumentException('onboarding handoff contract evidence is invalid');
        }
        $authority = self::object($document['authority_policy'] ?? null, 'authority_policy');
        self::keys($authority, ['policy_digest', 'status'], 'authority_policy');
        if (!in_array($authority['status'] ?? null, ['absent', 'enrolled'], true)
            || (($authority['status'] === 'absent') !== ($authority['policy_digest'] === null))
            || ($authority['policy_digest'] !== null && !self::digestValue($authority['policy_digest']))) {
            throw new \InvalidArgumentException('onboarding handoff authority-policy evidence is invalid');
        }

        $expectedNext = $contract['status'] === 'proposed'
            ? 'review_application_contract'
            : ($authority['status'] === 'absent' ? 'enroll_operation_authority' : 'prepare_release');
        if ($document['next_action'] !== $expectedNext) {
            throw new \InvalidArgumentException('onboarding handoff next action contradicts its evidence');
        }
        $expectedGeneration = self::digest([
            'environment' => [
                'config_sha256' => $environment['config_sha256'],
                'driver' => $environment['driver'],
                'name' => $environment['name'],
                'repo_path' => $environment['repo_path'],
            ],
            'repository_commit' => $repository['commit'],
            'target_id' => $target['id'],
        ]);
        if ($environment['generation_sha256'] !== $expectedGeneration) {
            throw new \InvalidArgumentException('onboarding handoff environment generation is not canonically bound');
        }
        $receiptDigest = $document['receipt_sha256'] ?? null;
        $body = $document;
        unset($body['receipt_sha256']);
        if (!self::digestValue($receiptDigest) || $receiptDigest !== self::digest($body)) {
            throw new \InvalidArgumentException('onboarding handoff receipt digest is invalid');
        }
    }

    /** @param array<string,mixed> $document */
    private static function digest(array $document): string {
        return self::digestBytes(Canon::encode($document));
    }

    private static function digestBytes(string $bytes): string {
        return 'sha256:' . hash('sha256', $bytes);
    }

    private static function timestamp(mixed $value): bool {
        return is_string($value)
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) === 1;
    }

    private static function environment(mixed $value): bool {
        return is_string($value)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $value) === 1;
    }

    private static function branch(mixed $value): bool {
        return is_string($value) && $value !== '' && strlen($value) <= 255
            && !preg_match('/[\x00-\x20\x7f~^:?*\\[\\]]/', $value)
            && !str_contains($value, '..') && !str_contains($value, '@{')
            && !str_starts_with($value, '/') && !str_ends_with($value, '/')
            && !str_ends_with($value, '.') && !str_ends_with($value, '.lock');
    }

    private static function gitOid(mixed $value): bool {
        return is_string($value) && preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $value) === 1;
    }

    private static function digestValue(mixed $value): bool {
        return is_string($value) && preg_match('/^sha256:[a-f0-9]{64}$/D', $value) === 1;
    }

    private static function text(mixed $value): bool {
        return is_string($value) && $value !== '' && strlen($value) <= 4096
            && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1;
    }

    private static function absolutePath(mixed $value): bool {
        return self::text($value) && str_starts_with((string) $value, '/');
    }

    /** @return array<string,mixed> */
    private static function object(mixed $value, string $label): array {
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException("onboarding handoff $label is not an object");
        }
        return $value;
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function keys(array $value, array $expected, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \InvalidArgumentException("onboarding handoff $label has an unexpected shape");
        }
    }
}

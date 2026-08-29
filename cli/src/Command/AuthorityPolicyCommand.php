<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Authority/OperationAuthorization.php';
require_once __DIR__ . '/../Authority/TargetOperationStore.php';
require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/AssessCommand.php';

use WPrism\Canon;
use WPrism\CommandRefusalException;

/** Explicit target-control enrollment and CAS update of operation authority. */
final class AuthorityPolicyCommand {
    public const STATUS_FORMAT = 'wprism-target-authority-policy-status/v1';

    /** @param list<string> $extra */
    public static function run(EnvironmentDriver $driver, array $extra): int {
        try {
            if (($extra[0] ?? null) === 'status') {
                self::assertOnlyJson(array_slice($extra, 1), 'status');
                $policy = TargetOperationStore::readAuthorityPolicy($driver);
                echo Canon::encode([
                    'format' => self::STATUS_FORMAT,
                    'policy_digest' => OperationAuthorization::trustDigest($policy),
                    'target_id' => TargetOperationStore::readIdentity($driver),
                ]);

                return 0;
            }
            if (($extra[0] ?? null) !== 'sync') {
                throw self::invalid('authority-policy requires status or sync');
            }
            $flags = self::syncFlags(array_slice($extra, 1));
            $bytes = @file_get_contents($flags['policy']);
            if (!is_string($bytes)) {
                throw new CommandRefusalException(
                    'target_authority_policy_input_unreadable',
                    'the requested operation-authority policy file could not be read',
                    'supply one readable canonical authority policy file outside the target control store'
                );
            }
            try {
                $policy = Canon::decode($bytes);
            } catch (\Throwable $error) {
                throw new CommandRefusalException(
                    'target_authority_policy_input_invalid',
                    'the requested operation-authority policy is malformed JSON',
                    'supply one canonical wprism-operation-authorities/v1 policy',
                    [],
                    $error->getMessage(),
                    $error
                );
            }
            if (!is_array($policy) || array_is_list($policy)) {
                throw new CommandRefusalException(
                    'target_authority_policy_input_invalid',
                    'the requested operation-authority policy is not a JSON object',
                    'supply one canonical wprism-operation-authorities/v1 policy'
                );
            }
            OperationAuthorization::validateTrust($policy);
            if (!hash_equals(Canon::encode($policy), $bytes)) {
                throw new CommandRefusalException(
                    'target_authority_policy_input_invalid',
                    'the requested operation-authority policy is not canonical JSON',
                    'canonicalize the reviewed policy before explicitly synchronizing it'
                );
            }
            echo Canon::encode(TargetOperationStore::syncAuthorityPolicy(
                $driver,
                $policy,
                $flags['expected_current']
            ));

            return 0;
        } catch (CommandRefusalException $refusal) {
            return AssessCommand::renderRefusal($refusal, true, 'authority-policy');
        } catch (\Throwable $error) {
            $refusal = new CommandRefusalException(
                'target_authority_policy_invalid',
                'the target operation-authority policy command could not be completed safely',
                'repair the named policy, target identity or control storage before retrying',
                [],
                $error->getMessage(),
                $error
            );

            return AssessCommand::renderRefusal($refusal, true, 'authority-policy');
        }
    }

    /** @param list<string> $extra */
    private static function assertOnlyJson(array $extra, string $verb): void {
        if ($extra !== ['--format=json']) {
            throw self::invalid("authority-policy $verb requires --format=json and accepts no other option");
        }
    }

    /** @param list<string> $extra @return array{expected_current:string,policy:string} */
    private static function syncFlags(array $extra): array {
        $out = ['expected_current' => null, 'policy' => null];
        $json = false;
        foreach ($extra as $arg) {
            if (!is_string($arg)) {
                throw self::invalid('authority-policy sync received a non-string argument');
            }
            if ($arg === '--format=json') {
                if ($json) throw self::invalid('authority-policy sync received --format twice');
                $json = true;
                continue;
            }
            if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
                throw self::invalid("authority-policy sync received an unsupported argument: '$arg'");
            }
            [$name, $value] = explode('=', $arg, 2);
            $key = match ($name) {
                '--expected-current' => 'expected_current',
                '--policy' => 'policy',
                default => throw self::invalid("authority-policy sync received an option it does not define: '$name'"),
            };
            if ($out[$key] !== null || $value === '') {
                throw self::invalid("authority-policy sync requires one non-empty $name value");
            }
            $out[$key] = $value;
        }
        if (!$json || !is_string($out['expected_current']) || !is_string($out['policy'])) {
            throw self::invalid(
                'authority-policy sync requires --policy, --expected-current=absent|sha256:<hex> and --format=json'
            );
        }

        return ['expected_current' => $out['expected_current'], 'policy' => $out['policy']];
    }

    private static function invalid(string $message): CommandRefusalException {
        return new CommandRefusalException(
            'target_authority_policy_invalid_arguments',
            $message,
            'use authority-policy <env> status --format=json or sync with one canonical policy and expected identity'
        );
    }
}

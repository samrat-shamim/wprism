<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform\Tests;

use Duo\EngineeringPlatform\DeterministicArchive;
use Duo\EngineeringPlatform\Qualification;
use PHPUnit\Framework\TestCase;

final class QualificationTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir() . '/duo-qualification-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporary, 0700));
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->temporary) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($this->temporary . '/' . $entry);
            }
        }
        rmdir($this->temporary);
    }

    public function testCandidateQualificationIsExplicitlyNonAuthorizingAndNonAdoptable(): void
    {
        $artifact = $this->temporary . '/candidate.tar';
        $artifactBytes = DeterministicArchive::encode([
            ['path' => 'payload/agent/fixture.php', 'bytes' => "<?php\n", 'mode' => 0644],
        ]);
        $this->writeBytes($artifact, $artifactBytes, 0600);
        $script = $this->temporary . '/observe.php';
        $this->writeBytes($script, <<<'PHP'
#!/usr/bin/env php
<?php
$artifact = (string) getenv('DUO_QUALIFICATION_ARTIFACT');
$output = (string) getenv('DUO_QUALIFICATION_OBSERVATION');
$mode = (string) getenv('DUO_QUALIFICATION_MODE');
$document = [
    'artifact_sha256' => 'sha256:' . hash_file('sha256', $artifact),
    'format' => 'duo-qualification-observation/v1',
    'mode' => $mode,
    'non_adoptable_enforced' => true,
    'operations' => ['adoption' => 'pass', 'recovery' => 'pass', 'rollback' => 'pass', 'swap' => 'pass', 'transfer' => 'pass'],
    'release_family_sha256' => null,
    'rollback_restored' => true,
    'source_checkout_fallback' => false,
    'target_mutated' => true,
];
ksort($document, SORT_STRING);
file_put_contents($output, json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
PHP
            . "\n", 0700);
        $this->authority($artifact, $artifactBytes, $script);

        $receipt = (new Qualification(dirname(__DIR__, 5)))->run('candidate_adoption', $this->temporary);

        self::assertSame('pass', $receipt['state']);
        self::assertSame('non_authorizing', $receipt['authority']);
        self::assertSame('forbidden', $receipt['adoptability']);
        self::assertFalse($receipt['source_checkout_fallback']);
        $operations = $receipt['operations'] ?? null;
        self::assertIsArray($operations);
        self::assertSame('pass', $operations['rollback'] ?? null);
    }

    private function authority(string $artifact, string $artifactBytes, string $script): void
    {
        $identity = [
            'credential_realm' => 'non_production',
            'data_profile' => 'approved_synthetic',
            'effect_policy' => 'sandbox_only',
            'egress_policy' => 'default_deny',
            'environment_role' => 'isolated_qualification',
            'image_digest' => 'sha256:' . str_repeat('1', 64),
            'output_adoptability' => 'forbidden',
            'output_authority' => 'non_authorizing',
            'sandbox_destinations_sha256' => 'sha256:' . str_repeat('2', 64),
            'target_sha256' => 'sha256:' . str_repeat('3', 64),
        ];
        $provisioning = ['environment_variable_names' => [], 'format' => 'duo-harness-provisioning/v1'] + $identity;
        $provisioningBytes = $this->canonical($provisioning) . "\n";
        $this->writeBytes($this->temporary . '/provisioning.json', $provisioningBytes, 0600);
        $provisioningDigest = $this->digest($provisioningBytes);
        $probe = ['format' => 'duo-harness-probe/v1', 'observed_at' => gmdate('Y-m-d\TH:i:s\Z'), 'provisioning_sha256' => $provisioningDigest] + $identity;
        $this->writeBytes($this->temporary . '/probe.json', $this->canonical($probe) . "\n", 0600);
        $keypair = sodium_crypto_sign_keypair();
        $approval = [
            'approval_id' => 'qualification-1',
            'credential_realm' => 'non_production',
            'data_profile' => 'approved_synthetic',
            'effect_policy' => 'sandbox_only',
            'egress_policy' => 'default_deny',
            'environment_role' => 'isolated_qualification',
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
            'format' => 'duo-harness-approval/v1',
            'issuer' => 'fixture-authority',
            'key_id' => 'fixture-key',
            'provisioning_sha256' => $provisioningDigest,
            'sandbox_destinations_sha256' => $identity['sandbox_destinations_sha256'],
            'target_sha256' => $identity['target_sha256'],
        ];
        $approval['signature'] = base64_encode(sodium_crypto_sign_detached(
            $this->canonical($approval),
            sodium_crypto_sign_secretkey($keypair),
        ));
        $this->writeBytes($this->temporary . '/approval.json', $this->canonical($approval) . "\n", 0600);
        $keyring = [
            'format' => 'duo-harness-keyring/v1',
            'keys' => [[
                'issuer' => 'fixture-authority',
                'key_id' => 'fixture-key',
                'public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            ]],
        ];
        $this->writeBytes($this->temporary . '/keyring.json', $this->canonical($keyring) . "\n", 0600);
        $command = [
            'argv' => [PHP_BINARY, $script],
            'artifact_path' => $artifact,
            'artifact_sha256' => $this->digest($artifactBytes),
            'format' => 'duo-qualification-command/v1',
            'mode' => 'candidate_adoption',
            'timeout_seconds' => 10,
        ];
        $this->writeBytes($this->temporary . '/command.json', $this->canonical($command) . "\n", 0600);
    }

    private function writeBytes(string $path, string $bytes, int $mode): void
    {
        self::assertNotFalse(file_put_contents($path, $bytes));
        self::assertTrue(chmod($path, $mode));
    }

    private function digest(string $bytes): string
    {
        return 'sha256:' . hash('sha256', $bytes);
    }

    private function canonical(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(',', array_map(fn(mixed $item): string => $this->canonical($item), $value)) . ']';
            }
            ksort($value, SORT_STRING);
            $pairs = [];
            foreach ($value as $key => $item) {
                $pairs[] = json_encode((string) $key, JSON_THROW_ON_ERROR) . ':' . $this->canonical($item);
            }
            return '{' . implode(',', $pairs) . '}';
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}

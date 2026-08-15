<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform\Tests;

use Duo\EngineeringPlatform\CatalogException;
use Duo\EngineeringPlatform\HarnessApproval;
use PHPUnit\Framework\TestCase;

final class HarnessApprovalTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir() . '/duo-harness-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporary, 0700));
        putenv('DUO_QUALIFICATION_TOKEN=fixture-secret');
    }

    protected function tearDown(): void
    {
        putenv('DUO_QUALIFICATION_TOKEN');
        foreach (scandir($this->temporary) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($this->temporary . '/' . $entry);
            }
        }
        rmdir($this->temporary);
    }

    public function testValidApprovalBindsFreshActualProbeAndRedactsEnvironment(): void
    {
        [$approval, $keyring, $provisioning, $probe] = $this->fixture();
        $context = (new HarnessApproval(dirname(__DIR__, 5)))->verify($approval, $keyring, $provisioning, $probe);

        self::assertSame('approval-1', $context['approval_id']);
        self::assertSame('non_authorizing', $context['output_authority']);
        self::assertSame('forbidden', $context['output_adoptability']);
        self::assertSame(['DUO_QUALIFICATION_TOKEN' => 'fixture-secret'], $context['environment']);
        $receiptContext = $context;
        unset($receiptContext['environment']);
        self::assertStringNotContainsString('fixture-secret', json_encode($receiptContext, JSON_THROW_ON_ERROR));
    }

    public function testTamperingAndRepositoryControlledAuthorityFailClosed(): void
    {
        [$approval, $keyring, $provisioning, $probe] = $this->fixture();
        $document = json_decode((string) file_get_contents($probe), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        $document = $this->stringKeyed($document);
        $document['target_sha256'] = 'sha256:' . str_repeat('f', 64);
        $this->write($probe, $document);

        $verifier = new HarnessApproval(dirname(__DIR__, 5));
        $this->expectException(CatalogException::class);
        $this->expectExceptionMessage('disagrees');
        $verifier->verify($approval, $keyring, $provisioning, $probe);
    }

    /** @return array{string,string,string,string} */
    private function fixture(): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($keypair);
        $public = sodium_crypto_sign_publickey($keypair);
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
        $provisioningDocument = ['environment_variable_names' => ['DUO_QUALIFICATION_TOKEN'], 'format' => 'duo-harness-provisioning/v1'] + $identity;
        $provisioning = $this->temporary . '/provisioning.json';
        $this->write($provisioning, $provisioningDocument);
        $provisioningBytes = (string) file_get_contents($provisioning);
        $provisioningDigest = 'sha256:' . hash('sha256', $provisioningBytes);
        $probeDocument = ['format' => 'duo-harness-probe/v1', 'observed_at' => gmdate('Y-m-d\TH:i:s\Z'), 'provisioning_sha256' => $provisioningDigest] + $identity;
        $probe = $this->temporary . '/probe.json';
        $this->write($probe, $probeDocument);
        $approvalDocument = [
            'approval_id' => 'approval-1',
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
        $approvalDocument['signature'] = base64_encode(sodium_crypto_sign_detached($this->canonical($approvalDocument), $secret));
        $approval = $this->temporary . '/approval.json';
        $this->write($approval, $approvalDocument);
        $keyring = $this->temporary . '/keyring.json';
        $this->write($keyring, [
            'format' => 'duo-harness-keyring/v1',
            'keys' => [['issuer' => 'fixture-authority', 'key_id' => 'fixture-key', 'public_key' => base64_encode($public)]],
        ]);
        return [$approval, $keyring, $provisioning, $probe];
    }

    /** @param array<string,mixed> $document */
    private function write(string $path, array $document): void
    {
        self::assertNotFalse(file_put_contents($path, $this->canonical($document) . "\n"));
        self::assertTrue(chmod($path, 0600));
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

    /**
     * @param array<mixed,mixed> $value
     * @return array<string,mixed>
     */
    private function stringKeyed(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            self::assertIsString($key);
            $result[$key] = $item;
        }
        return $result;
    }
}

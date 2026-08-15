<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform\Tests;

use Duo\EngineeringPlatform\DeterministicArchive;
use Duo\EngineeringPlatform\CloseGate;
use Duo\EngineeringPlatform\Release;
use PHPUnit\Framework\TestCase;

final class ReleaseTest extends TestCase
{
    private string $temporary;
    private string $repository;
    private string $bundle;
    private string $authority;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir() . '/duo-release-test-' . bin2hex(random_bytes(8));
        $this->repository = $this->temporary . '/repository';
        $this->bundle = $this->temporary . '/bundle';
        $this->authority = $this->temporary . '/authority';
        self::assertTrue(mkdir($this->repository, 0700, true));
        self::assertTrue(mkdir($this->bundle . '/component-archives', 0700, true));
        self::assertTrue(mkdir($this->bundle . '/component-manifests', 0700, true));
        self::assertTrue(mkdir($this->bundle . '/declarations', 0700, true));
        self::assertTrue(mkdir($this->bundle . '/evidence-inputs', 0700, true));
        self::assertTrue(mkdir($this->authority, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->temporary);
    }

    public function testExactRetainedFamilyAndAssemblyVerify(): void
    {
        [$candidate, $child, $selection, $pin] = $this->fixture();
        $release = new Release($this->repository);
        $verified = $release->verify($this->bundle, $selection, $pin);
        $reproduced = $release->reproducibility($this->bundle, $selection, $pin);

        self::assertSame('pass', $verified['state']);
        self::assertSame($candidate, $verified['candidate_sha']);
        self::assertSame($child, $verified['evidence_child_sha']);
        $familyDigest = $verified['release_family_sha256'] ?? null;
        self::assertIsString($familyDigest);
        self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/D', $familyDigest);
        self::assertSame('pass', $reproduced['state']);
        self::assertSame($candidate, $reproduced['candidate_sha']);
        $composite = $reproduced['composite_sha256'] ?? null;
        self::assertIsString($composite);
        self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/D', $composite);
    }

    public function testFinalIntegrationCloseGateBindsFreshMainAndRetainedBytes(): void
    {
        [$candidate, $child, $selection, $pin] = $this->fixture();
        $this->git(['update-ref', 'refs/remotes/origin/main', $child]);

        $receipt = (new CloseGate($this->repository))->verify(
            $candidate,
            $child,
            'refs/remotes/origin/main',
            $this->bundle,
            $selection,
            $pin,
        );

        self::assertSame('pass', $receipt['state']);
        self::assertSame($child, $receipt['fresh_main_sha']);
        self::assertTrue($receipt['runtime_declaration_build_inputs_unchanged']);
    }

    /** @return array{string,string,string,string} */
    private function fixture(): array
    {
        $this->git(['init', '--initial-branch=main']);
        $this->git(['config', 'user.name', 'Release Test']);
        $this->git(['config', 'user.email', 'release@example.invalid']);
        self::assertNotFalse(file_put_contents($this->repository . '/README.md', "candidate\n"));
        $this->git(['add', 'README.md']);
        $this->git(['commit', '-m', 'candidate']);
        $candidate = trim($this->git(['rev-parse', 'HEAD']));
        self::assertTrue(mkdir($this->repository . '/manifests/capabilities', 0700, true));
        self::assertNotFalse(file_put_contents($this->repository . '/manifests/capabilities/evidence.json', "{}\n"));
        $this->git(['add', 'manifests/capabilities/evidence.json']);
        $this->git(['commit', '-m', 'evidence child']);
        $child = trim($this->git(['rev-parse', 'HEAD']));

        $components = [
            'host-cli' => [['path' => 'host/cli/duo', 'bytes' => "#!/usr/bin/env php\n<?php exit(0);\n", 'mode' => 0755]],
            'agent' => [['path' => 'payload/agent/duo/duo.php', 'bytes' => "<?php\n", 'mode' => 0644]],
            'recovery' => [['path' => 'payload/recovery/health.php', 'bytes' => "<?php\n", 'mode' => 0644]],
            'declarations' => [['path' => 'payload/declarations/contracts/fixture.json', 'bytes' => "{}\n", 'mode' => 0644]],
        ];
        $manifestDigests = [];
        $targetFiles = [];
        foreach ($components as $component => $files) {
            $archive = DeterministicArchive::encode($files);
            $archivePath = $component === 'host-cli'
                ? $this->bundle . '/host-artifact.tar'
                : $this->bundle . "/component-archives/$component.tar";
            $this->writeBytes($archivePath, $archive);
            if ($component !== 'host-cli') {
                array_push($targetFiles, ...$files);
            }
            $root = $component === 'host-cli' ? 'host' : 'payload/' . $component;
            $rows = [];
            foreach ($files as $file) {
                $rows[] = [
                    'mode' => sprintf('%04o', $file['mode']),
                    'path' => substr($file['path'], strlen($root) + 1),
                    'sha256' => $this->digest($file['bytes']),
                    'size' => strlen($file['bytes']),
                ];
            }
            $manifest = [
                'archive_sha256' => $this->digest($archive),
                'build_version' => '1',
                'compatibility' => ['fixture' => true],
                'component' => $component,
                'content_sha256' => $this->digest($this->canonical($rows)),
                'files' => $rows,
                'format' => 'duo-artifact-manifest/v1',
                'root' => $root,
                'source_commit' => $candidate,
            ];
            $manifestBytes = $this->canonical($manifest) . "\n";
            $this->writeBytes($this->bundle . "/component-manifests/$component.json", $manifestBytes);
            $manifestDigests[$component] = $this->digest($manifestBytes);
        }
        $targetInstall = DeterministicArchive::encode($targetFiles);
        $this->writeBytes($this->bundle . '/target-install.tar', $targetInstall);
        $candidateSet = $this->canonical(['candidate_sha' => $candidate, 'format' => 'duo-candidate-release-set/v1']) . "\n";
        $this->writeBytes($this->bundle . '/component-manifests/candidate-release-set.json', $candidateSet);
        $declarationBytes = "{\"fixture\":true}\n";
        $evidenceBytes = "{\"evidence\":true}\n";
        $this->writeBytes($this->bundle . '/declarations/contract.json', $declarationBytes);
        $this->writeBytes($this->bundle . '/evidence-inputs/evidence.json', $evidenceBytes);
        $protocols = ['agent' => 'fixture-agent-v1', 'host' => 'fixture-host-v1', 'recovery' => 'fixture-recovery-v1'];
        $payload = [
            'declaration_payloads' => ['contract' => $this->digest($declarationBytes)],
            'evidence_inputs' => ['evidence' => $this->digest($evidenceBytes)],
            'format' => 'duo-review-bundle/v2',
            'host_artifact_sha256' => $this->digest((string) file_get_contents($this->bundle . '/host-artifact.tar')),
            'protocols' => $protocols,
            'target_install_sha256' => $this->digest($targetInstall),
        ];
        $keypair = sodium_crypto_sign_keypair();
        $claims = [
            'authority_id' => 'fixture-review',
            'format' => 'duo-review-envelope/v1',
            'key_id' => 'fixture-key',
            'payload' => $payload,
        ];
        $envelope = $claims + [
            'signature' => base64_encode(sodium_crypto_sign_detached(
                $this->canonical($claims),
                sodium_crypto_sign_secretkey($keypair),
            )),
        ];
        $envelopeBytes = $this->canonical($envelope) . "\n";
        $this->writeBytes($this->bundle . '/review-envelope.json', $envelopeBytes);
        $projectionBytes = $this->canonical([
            'format' => 'duo-projection-pack/v1',
            'review_envelope_sha256' => $this->digest($envelopeBytes),
            'reviewed_payload_sha256' => $this->digest($this->canonical($payload) . "\n"),
        ]) . "\n";
        $this->writeBytes($this->bundle . '/projection-pack.json', $projectionBytes);
        $setBytes = $this->canonical([
            'format' => 'duo-target-release-set/v1',
            'projection_pack_sha256' => $this->digest($projectionBytes),
            'review_envelope_sha256' => $this->digest($envelopeBytes),
            'target_install_sha256' => $this->digest($targetInstall),
        ]) . "\n";
        $this->writeBytes($this->bundle . '/target-release-set.json', $setBytes);
        $hostArtifact = (string) file_get_contents($this->bundle . '/host-artifact.tar');
        $familyBytes = $this->canonical([
            'format' => 'duo-release-family/v1',
            'host_artifact_sha256' => $this->digest($hostArtifact),
            'protocols' => $protocols,
            'target_release_set_sha256' => $this->digest($setBytes),
        ]) . "\n";
        $this->writeBytes($this->bundle . '/release-family.json', $familyBytes);
        $lineageBytes = $this->canonical([
            'candidate_release_set_sha256' => $this->digest($candidateSet),
            'candidate_sha' => $candidate,
            'component_manifest_sha256' => $manifestDigests,
            'evidence_child_sha' => $child,
            'format' => 'duo-evidence-child-lineage/v1',
            'projection_pack_sha256' => $this->digest($projectionBytes),
            'review_envelope_sha256' => $this->digest($envelopeBytes),
        ]) . "\n";
        $this->writeBytes($this->bundle . '/lineage.json', $lineageBytes);

        $trustedKeys = ['fixture-review:fixture-key' => base64_encode(sodium_crypto_sign_publickey($keypair))];
        $pinBytes = $this->canonical([
            'expected_host_artifact_sha256' => $this->digest($hostArtifact),
            'expected_protocols' => $protocols,
            'expected_release_family_sha256' => $this->digest($familyBytes),
            'expected_target_release_set_sha256' => $this->digest($setBytes),
            'format' => 'duo-release-selection-pin/v1',
            'trusted_review_keys' => $trustedKeys,
        ]) . "\n";
        $pin = $this->authority . '/pin-record.json';
        $this->writeBytes($pin, $pinBytes);
        $selectionBytes = $this->canonical([
            'bundle_path' => $this->bundle,
            'expected_host_artifact_sha256' => $this->digest($hostArtifact),
            'expected_protocols' => $protocols,
            'expected_release_family_sha256' => $this->digest($familyBytes),
            'expected_target_release_set_sha256' => $this->digest($setBytes),
            'format' => 'duo-release-selection/v1',
            'pin_record_sha256' => $this->digest($pinBytes),
            'trusted_review_keys' => $trustedKeys,
        ]) . "\n";
        $selection = $this->authority . '/selection.json';
        $this->writeBytes($selection, $selectionBytes);
        return [$candidate, $child, $selection, $pin];
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): string
    {
        $process = proc_open(
            array_merge(['git', '-C', $this->repository], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->repository,
            ['PATH' => (string) getenv('PATH'), 'LC_ALL' => 'C'],
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $stderr);
        self::assertIsString($stdout);
        return $stdout;
    }

    private function writeBytes(string $path, string $bytes): void
    {
        self::assertNotFalse(file_put_contents($path, $bytes));
        self::assertTrue(chmod($path, 0600));
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
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = json_encode((string) $key, JSON_THROW_ON_ERROR) . ':' . $this->canonical($item);
            }
            return '{' . implode(',', $parts) . '}';
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (!is_dir($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}

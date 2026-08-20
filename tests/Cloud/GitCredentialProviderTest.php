<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\CanonicalJson;
use Duo\Cloud\ControlRefusal;
use Duo\Cloud\GitCredentialProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/cloud/runtime/GitCredentialProvider.php';

#[CoversNothing]
final class GitCredentialProviderTest extends TestCase {
    private string $scratch;
    private string $url;
    private string $descriptor;
    private string $pin;
    private string $passwordFile;

    protected function setUp(): void {
        $this->scratch = sys_get_temp_dir() . '/duo-git-credential-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch, 0700));
        self::assertTrue(chmod($this->scratch, 0700));
        $this->url = 'https://git.example.test/tenant/site.git';
        $this->passwordFile = $this->scratch . '/password';
        $password = "secret-token-abcdefghijklmnopqrstuvwxyz\n";
        self::assertNotFalse(file_put_contents($this->passwordFile, $password));
        self::assertTrue(chmod($this->passwordFile, 0600));
        $document = [
            'format' => GitCredentialProvider::FORMAT,
            'password_file' => $this->passwordFile,
            'password_sha256' => hash('sha256', $password),
            'remote_url_sha256' => hash(
                'sha256',
                "duo-cloud-repository-remote-url/v1\0{$this->url}"
            ),
            'username' => 'duo-cloud-token',
        ];
        $bytes = CanonicalJson::encode($document) . "\n";
        $this->descriptor = $this->scratch . '/repository-credential.json';
        $this->pin = $this->scratch . '/repository-credential.sha256';
        self::assertNotFalse(file_put_contents($this->descriptor, $bytes));
        self::assertTrue(chmod($this->descriptor, 0600));
        self::assertNotFalse(file_put_contents($this->pin, hash('sha256', $bytes) . "\n"));
        self::assertTrue(chmod($this->pin, 0600));
    }

    protected function tearDown(): void {
        foreach (scandir($this->scratch) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->scratch . '/' . $entry);
            }
        }
        @rmdir($this->scratch);
    }

    public function testReturnsCredentialOnlyForExactPinnedHttpsRemote(): void {
        $provider = GitCredentialProvider::load($this->descriptor, $this->pin);
        self::assertSame([
            'format' => 'duo-cloud-git-credential-provider-status/v1',
            'ready' => true,
            'remote_url_sha256' => hash(
                'sha256',
                "duo-cloud-repository-remote-url/v1\0{$this->url}"
            ),
        ], $provider->status());
        $request = "protocol=https\nhost=git.example.test\npath=tenant/site.git\n\n";
        self::assertSame(
            "username=duo-cloud-token\npassword=secret-token-abcdefghijklmnopqrstuvwxyz\n\n",
            $provider->handle('get', $request)
        );
        self::assertSame('', $provider->handle(
            'store',
            "protocol=https\nhost=git.example.test\npath=tenant/site.git\n"
                . "username=duo-cloud-token\npassword=secret-token-abcdefghijklmnopqrstuvwxyz\n\n"
        ));
    }

    public function testRefusesForeignRemoteWithoutReturningAnyCredential(): void {
        $provider = GitCredentialProvider::load($this->descriptor, $this->pin);

        $this->expectException(ControlRefusal::class);
        $this->expectExceptionMessage('foreign remote');
        $provider->handle('get', "protocol=https\nhost=evil.example\npath=tenant/site.git\n\n");
    }

    public function testRefusesSecretRotationUntilDescriptorAndPinRotateTogether(): void {
        self::assertNotFalse(file_put_contents(
            $this->passwordFile,
            "rotated-secret-token-abcdefghijklmnopqrstuvwxyz\n"
        ));
        self::assertTrue(chmod($this->passwordFile, 0600));

        $this->expectException(ControlRefusal::class);
        $this->expectExceptionMessage('differs from its pin');
        GitCredentialProvider::load($this->descriptor, $this->pin);
    }
}

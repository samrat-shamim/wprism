<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\Deploy\FpmIngressRefusal;
use Duo\Cloud\Deploy\FpmIngressVerifier;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/cloud/deploy/verify-fpm-ingress.php';

#[CoversNothing]
final class FpmIngressVerifierTest extends TestCase {
    public function testExecutablePublishesAClosedBoundedContract(): void {
        $path = DUO_REPO_ROOT . '/cloud/deploy/verify-fpm-ingress.php';
        $bytes = file_get_contents($path);
        self::assertIsString($bytes);
        self::assertTrue(is_executable($path));
        self::assertStringStartsWith("#!/usr/bin/env php\n<?php\ndeclare(strict_types=1);\n", $bytes);
        self::assertSame([
            'format' => FpmIngressVerifier::CONTRACT_FORMAT,
            'max_wall_seconds' => 40,
            'premises' => [
                'caddy-v2',
                'curl',
                'non-root-posix-identity',
                'php-fpm-8.3-or-newer',
            ],
            'probes' => [
                'distinct-worker-config-digests',
                'host-a-only-worker-a',
                'host-b-only-worker-b',
                'known-host-unknown-path-exact-404',
                'no-cross-marker-or-source-disclosure',
                'socket-directory-owner-group-mode-traversal',
                'unknown-host-exact-404',
            ],
            'state' => 'described',
        ], FpmIngressVerifier::contract());
        self::assertSame(40, FpmIngressVerifier::MAX_WALL_SECONDS);
    }

    public function testArgumentProtocolAcceptsOnlyExactExecutableDescriptors(): void {
        self::assertSame([
            'caddy' => '/usr/bin/caddy',
            'contract' => false,
            'curl' => '/usr/bin/curl',
            'php_fpm' => '/usr/sbin/php-fpm8.3',
        ], FpmIngressVerifier::parseArguments([
            '--php-fpm=/usr/sbin/php-fpm8.3',
            '--caddy=/usr/bin/caddy',
            '--curl=/usr/bin/curl',
        ]));
        self::assertSame([
            'caddy' => null,
            'contract' => true,
            'curl' => null,
            'php_fpm' => null,
        ], FpmIngressVerifier::parseArguments(['--contract']));

        foreach ([
            ['--timeout=0'],
            ['--contract', '--curl=/usr/bin/curl'],
            ['--caddy='],
            ['--curl=/usr/bin/curl', '--curl=/usr/bin/curl'],
        ] as $invalid) {
            try {
                FpmIngressVerifier::parseArguments($invalid);
                self::fail('invalid verifier option was accepted');
            } catch (FpmIngressRefusal $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testCaddyVersionPremiseAcceptsUbuntuAndUpstreamV2Forms(): void {
        $method = new \ReflectionMethod(FpmIngressVerifier::class, 'caddyV2Version');
        self::assertTrue($method->invoke(null, '2.6.2'));
        self::assertTrue($method->invoke(null, 'v2.10.2 h1:reviewed-build'));
        self::assertFalse($method->invoke(null, '1.0.5'));
        self::assertFalse($method->invoke(null, '2.6'));
        self::assertFalse($method->invoke(null, 'caddy 2.6.2'));
    }

    public function testConfigsBindTwoHostsToDisjointPoolsAndSocketAuthorities(): void {
        $script = '/tmp/duo-ingress/www/ingress.php';
        $workers = [
            'site-a' => [
                'host' => 'site-a.control.example.invalid',
                'socket' => '/tmp/duo-ingress/run/site-a/php-fpm.sock',
            ],
            'site-b' => [
                'host' => 'site-b.control.example.invalid',
                'socket' => '/tmp/duo-ingress/run/site-b/php-fpm.sock',
            ],
        ];
        $caddy = FpmIngressVerifier::caddyConfig(18081, $script, $workers);
        self::assertStringContainsString("http://:18081 {\n\tbind 127.0.0.1", $caddy);
        self::assertStringContainsString("\t\tprotocols h1", $caddy);
        self::assertStringNotContainsString('http://127.0.0.1:18081', $caddy);
        self::assertStringNotContainsString('host *', $caddy);
        $siteAStart = strpos($caddy, '@worker_site_a');
        $siteBStart = strpos($caddy, '@worker_site_b');
        $fallback = strrpos($caddy, "\thandle {");
        self::assertIsInt($siteAStart);
        self::assertIsInt($siteBStart);
        self::assertIsInt($fallback);
        $siteA = substr($caddy, $siteAStart, $siteBStart - $siteAStart);
        $siteB = substr($caddy, $siteBStart, $fallback - $siteBStart);
        self::assertStringContainsString($workers['site-a']['host'], $siteA);
        self::assertStringContainsString('unix/' . $workers['site-a']['socket'], $siteA);
        self::assertStringNotContainsString('site-b', $siteA);
        self::assertStringContainsString($workers['site-b']['host'], $siteB);
        self::assertStringContainsString('unix/' . $workers['site-b']['socket'], $siteB);
        self::assertStringNotContainsString('site-a', $siteB);
        self::assertStringContainsString("\thandle {\n\t\trespond 404\n", substr($caddy, $fallback));

        $digestA = str_repeat('a', 64);
        $digestB = str_repeat('b', 64);
        $fpmA = FpmIngressVerifier::fpmConfig(
            'site-a',
            'duo-cloud',
            'duo-cloud',
            $workers['site-a']['socket'],
            '/tmp/duo-ingress/run/site-a/php-fpm.pid',
            '/tmp/duo-ingress/logs/site-a.log',
            '/tmp/duo-ingress/config/site-a.json',
            $digestA
        );
        $fpmB = FpmIngressVerifier::fpmConfig(
            'site-b',
            'duo-cloud',
            'duo-cloud',
            $workers['site-b']['socket'],
            '/tmp/duo-ingress/run/site-b/php-fpm.pid',
            '/tmp/duo-ingress/logs/site-b.log',
            '/tmp/duo-ingress/config/site-b.json',
            $digestB
        );
        self::assertStringContainsString("listen = {$workers['site-a']['socket']}", $fpmA);
        self::assertStringContainsString("env[DUO_CLOUD_CONFIG_SHA256] = $digestA", $fpmA);
        self::assertStringNotContainsString('site-b', $fpmA);
        self::assertStringContainsString("listen = {$workers['site-b']['socket']}", $fpmB);
        self::assertStringContainsString("env[DUO_CLOUD_CONFIG_SHA256] = $digestB", $fpmB);
        self::assertStringNotContainsString('site-a', $fpmB);
        self::assertStringContainsString('listen.mode = 0660', $fpmA . $fpmB);
        self::assertStringNotContainsString('cgi.check_shebang_line', $fpmA . $fpmB);

        $pool = file_get_contents(DUO_REPO_ROOT . '/cloud/deploy/php-fpm-pool.conf.example');
        $ini = file_get_contents(DUO_REPO_ROOT . '/cloud/deploy/php-fpm.ini');
        self::assertIsString($pool);
        self::assertIsString($ini);
        self::assertStringNotContainsString('php_admin_flag[cgi.check_shebang_line]', $pool);
        self::assertStringContainsString("cgi.check_shebang_line=1\n", $ini);
    }

    public function testWorkerSourceAndProbeValidationRefuseDisclosureOrCrossRouting(): void {
        $source = FpmIngressVerifier::workerScript();
        self::assertStringStartsWith("<?php\ndeclare(strict_types=1);\n", $source);
        self::assertStringNotContainsString('#!', substr($source, 0, 64));
        self::assertStringContainsString('DUO_FPM_SOURCE_SENTINEL_DO_NOT_DISCLOSE', $source);
        self::assertStringContainsString("['format', 'host', 'marker', 'worker']", $source);

        $markerA = str_repeat('a', 64);
        $markerB = str_repeat('b', 64);
        $body = FpmIngressVerifier::canonicalJson([
            'configuration_sha256' => str_repeat('c', 64),
            'format' => FpmIngressVerifier::WORKER_FORMAT,
            'marker' => $markerA,
            'worker' => 'site-a',
        ]) . "\n";
        $probe = [
            'body' => $body,
            'headers' => "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n",
            'remote_ip' => '127.0.0.1',
            'status' => 200,
        ];
        FpmIngressVerifier::assertWorkerProbe($probe, $body, $markerB);
        self::addToAssertionCount(1);

        foreach ([
            array_replace($probe, ['body' => $body . $markerB]),
            array_replace($probe, ['body' => $body . "<?php\n"]),
            array_replace($probe, ['headers' => $probe['headers'] . "X-Powered-By: PHP\r\n"]),
            array_replace($probe, ['status' => 502]),
        ] as $invalid) {
            try {
                FpmIngressVerifier::assertWorkerProbe($invalid, $body, $markerB);
                self::fail('ambiguous or disclosed worker response was accepted');
            } catch (FpmIngressRefusal $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }

        $notFound = [
            'body' => '',
            'headers' => "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\n\r\n",
            'remote_ip' => '127.0.0.1',
            'status' => 404,
        ];
        FpmIngressVerifier::assertNotFoundProbe($notFound, [$markerA, $markerB]);
        self::addToAssertionCount(1);
        $this->expectException(FpmIngressRefusal::class);
        FpmIngressVerifier::assertNotFoundProbe(
            array_replace($notFound, ['body' => $markerA]),
            [$markerA, $markerB]
        );
    }

    public function testSocketContractReadsBackDirectoryAndUnixSocketMetadata(): void {
        if (!function_exists('posix_geteuid') || !function_exists('posix_getegid')) {
            self::markTestSkipped('POSIX identity is required by the production FPM boundary');
        }
        $temporaryBase = realpath('/tmp');
        self::assertIsString($temporaryBase);
        $root = $temporaryBase . '/duo-fpm-test-' . bin2hex(random_bytes(8));
        $runtime = $root . '/run';
        $directories = [
            'site-a' => $runtime . '/site-a',
            'site-b' => $runtime . '/site-b',
        ];
        $sockets = [
            'site-a' => $directories['site-a'] . '/php-fpm.sock',
            'site-b' => $directories['site-b'] . '/php-fpm.sock',
        ];
        $servers = [];
        try {
            self::assertTrue(mkdir($root, 0700));
            self::assertTrue(mkdir($runtime, 0750));
            self::assertTrue(chgrp($runtime, posix_getegid()));
            self::assertTrue(chmod($runtime, 0750));
            foreach ($directories as $directory) {
                self::assertTrue(mkdir($directory, 0750));
                self::assertTrue(chgrp($directory, posix_getegid()));
                self::assertTrue(chmod($directory, 0750));
            }
            foreach ($sockets as $worker => $socket) {
                $errorCode = 0;
                $errorMessage = '';
                $server = stream_socket_server(
                    'unix://' . $socket,
                    $errorCode,
                    $errorMessage,
                    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
                );
                self::assertIsResource($server, $errorMessage);
                $servers[$worker] = $server;
                self::assertTrue(chgrp($socket, posix_getegid()));
                self::assertTrue(chmod($socket, 0660));
            }
            FpmIngressVerifier::assertSocketContract(
                $runtime,
                $directories,
                $sockets,
                posix_geteuid(),
                posix_getegid()
            );
            self::addToAssertionCount(1);
            self::assertTrue(chmod($sockets['site-b'], 0600));
            try {
                FpmIngressVerifier::assertSocketContract(
                    $runtime,
                    $directories,
                    $sockets,
                    posix_geteuid(),
                    posix_getegid()
                );
                self::fail('non-group-readable worker socket was accepted');
            } catch (FpmIngressRefusal $error) {
                self::assertStringContainsString('site-b socket', $error->getMessage());
            }
        } finally {
            foreach ($servers as $server) {
                fclose($server);
            }
            foreach ($sockets as $socket) {
                if (file_exists($socket)) {
                    self::assertTrue(unlink($socket));
                }
            }
            foreach (array_reverse($directories) as $directory) {
                if (is_dir($directory)) {
                    self::assertTrue(rmdir($directory));
                }
            }
            if (is_dir($runtime)) {
                self::assertTrue(rmdir($runtime));
            }
            if (is_dir($root)) {
                self::assertTrue(rmdir($root));
            }
        }
    }
}

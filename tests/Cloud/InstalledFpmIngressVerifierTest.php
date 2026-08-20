<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\Deploy\InstalledFpmIngressRefusal;
use Duo\Cloud\Deploy\InstalledFpmIngressVerifier;
use Duo\Cloud\ProductionDeploymentProofs;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

use function Duo\Cloud\Deploy\observedInstalledFpmProofFile;
use function Duo\Cloud\Deploy\revalidateInstalledFpmProofFile;

require_once DUO_REPO_ROOT . '/cloud/deploy/verify-installed-fpm-ingress.php';

#[CoversNothing]
final class InstalledFpmIngressVerifierTest extends TestCase {
    public function testDeploymentPublishesRootOnlyFailClosedInstalledContract(): void {
        $verifierPath = DUO_REPO_ROOT . '/cloud/deploy/verify-installed-fpm-ingress.php';
        $verifier = (string) file_get_contents($verifierPath);
        $service = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/duo-cloud-verify-installed-fpm-ingress.service'
        );
        $environment = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/installed-fpm-ingress.env.example'
        );
        self::assertTrue(is_executable($verifierPath));
        self::assertStringStartsWith(
            "#!/usr/bin/env php\n<?php\ndeclare(strict_types=1);\n",
            $verifier
        );
        self::assertSame([
            'format' => InstalledFpmIngressVerifier::CONTRACT_FORMAT,
            'max_wall_seconds' => 45,
            'premises' => [
                'linux-systemd-host',
                'root-proof-identity',
                'root-owned-pinned-descriptor',
                'fixed-distinct-service-identities',
            ],
            'probes' => [
                'active-byte-identical-no-drop-in-units',
                'active-process-identities-and-executables',
                'rendered-configs-pinned-before-service-start',
                'exact-control-host-to-worker-socket-topology',
                'exact-empty-404-fallback',
                'socket-owner-group-mode-and-path',
                'live-control-edge-to-every-worker-socket',
            ],
            'state' => 'described',
        ], InstalledFpmIngressVerifier::contract());
        foreach ([
            'User=root',
            'Group=root',
            'CapabilityBoundingSet=CAP_CHOWN CAP_DAC_OVERRIDE CAP_DAC_READ_SEARCH CAP_FOWNER CAP_SYS_PTRACE',
            'NoNewPrivileges=true',
            'ProtectSystem=strict',
            'RestrictAddressFamilies=AF_INET AF_UNIX',
            'TimeoutStartSec=60s',
            '/opt/duo-cloud/deploy/verify-installed-fpm-ingress.php',
            'ReadWritePaths=/var/lib/duo-cloud/host-preflight',
        ] as $boundary) {
            self::assertStringContainsString($boundary, $service);
        }
        self::assertStringNotContainsString('[Install]', $service);
        self::assertStringContainsString(
            'DUO_CLOUD_INSTALLED_FPM_CONFIGURATION_FILE=' .
                InstalledFpmIngressVerifier::CONFIGURATION_PATH,
            $environment
        );
        self::assertStringContainsString('PHP_OS_FAMILY !== \'Linux\'', $verifier);
        self::assertStringContainsString('posix_geteuid() !== 0', $verifier);
        self::assertStringContainsString("ini_set('pcre.jit', '0')", $verifier);
        self::assertLessThan(
            strpos($verifier, 'foreach ($installedFpmSourcePaths as $name => $path)'),
            strpos($verifier, "ini_set('pcre.jit', '0')")
        );
        self::assertStringContainsString(
            'ProductionDeploymentProofs::invalidateInstalledFpm(',
            $verifier
        );
        self::assertStringContainsString('!self::hasExactKeys($properties, [', $verifier);
        self::assertStringContainsString(
            'installed shared PHP-FPM executable',
            $verifier
        );
        self::assertStringContainsString('$this->workerSnapshotLabels[$workerId]', $verifier);
        self::assertStringNotContainsString('str_contains($label, $workerId)', $verifier);
        self::assertStringContainsString('publishInstalledFpmEvidence(', $verifier);
        self::assertStringContainsString("'php_closure_sha256'", $verifier);
        self::assertStringContainsString("'HOME' => '/nonexistent'", $verifier);
        self::assertStringNotContainsString('verify-fpm-ingress.php --', $service);
        $fpmService = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/duo-cloud-php-fpm@.service'
        );
        $controlService = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/duo-cloud-control-caddy.service'
        );
        self::assertStringContainsString(
            "\nUser=duo-cloud\nGroup=duo-cloud\nSupplementaryGroups=docker\n",
            $fpmService
        );
        self::assertStringContainsString(
            "\nUser=duo-cloud-edge\nGroup=duo-cloud-edge\nSupplementaryGroups=duo-cloud\n",
            $controlService
        );
        $readme = (string) file_get_contents(DUO_REPO_ROOT . '/cloud/README.md');
        $operatorProse = preg_replace('/\s+/', ' ', $readme);
        self::assertIsString($operatorProse);
        foreach ([
            'Render `deploy/installed-fpm-ingress.json.example` for every fleet worker',
            'systemctl enable --now duo-cloud-verify-installed-fpm-ingress.timer',
            'The portable and installed proofs establish different premises; both are required.',
            'macOS can run only the portable proof and cannot emit installed readiness.',
        ] as $operatorContract) {
            self::assertStringContainsString($operatorContract, $operatorProse);
        }
        self::assertSame([
            'deploy/verify-installed-fpm-ingress.php',
            'runtime/ProductionConfig.php',
            'runtime/ProductionDeploymentProofs.php',
            'src/CanonicalJson.php',
            'src/ControlRefusal.php',
            'src/HostAuthorityBusy.php',
            'src/ImmutableOciReference.php',
        ], array_keys(ProductionDeploymentProofs::installedFpmVerifierSourcePaths(
            DUO_REPO_ROOT . '/cloud/deploy'
        )));
    }

    public function testFinalSourceCheckpointRefusesAtomicRename(): void {
        $sandbox = realpath(DUO_REPO_ROOT . '/sandbox/tmp');
        self::assertIsString($sandbox);
        $root = $sandbox . '/installed-fpm-source-snapshot-' . bin2hex(random_bytes(8));
        $path = $root . '/source.php';
        $replacement = $root . '/replacement.php';
        self::assertTrue(mkdir($root, 0700));
        try {
            $bytes = "<?php\nreturn 'captured';\n";
            self::assertSame(strlen($bytes), file_put_contents($path, $bytes));
            self::assertTrue(chmod($path, 0600));
            $identity = observedInstalledFpmProofFile(
                $path,
                'installed FPM publication checkpoint source',
                2097152
            );
            self::assertSame(hash('sha256', $bytes), $identity['sha256']);

            self::assertSame(strlen($bytes), file_put_contents($replacement, $bytes));
            self::assertTrue(chmod($replacement, 0600));
            self::assertTrue(rename($replacement, $path));

            $this->expectException(InstalledFpmIngressRefusal::class);
            $this->expectExceptionMessage(
                'installed FPM publication checkpoint source changed during installed proof'
            );
            revalidateInstalledFpmProofFile(
                $identity,
                'installed FPM publication checkpoint source',
                2097152
            );
        } finally {
            if (is_file($replacement)) {
                unlink($replacement);
            }
            if (is_file($path)) {
                unlink($path);
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }

    public function testDescriptorProtocolIsCanonicalPinnedAndFleetBounded(): void {
        $shaA = str_repeat('a', 64);
        $shaB = str_repeat('b', 64);
        $shaC = str_repeat('c', 64);
        $shaD = str_repeat('d', 64);
        $document = [
            'control_caddy_sha256' => $shaA,
            'format' => InstalledFpmIngressVerifier::CONFIGURATION_FORMAT,
            'host_preflight_root' => '/var/lib/duo-cloud/host-preflight',
            'php_fpm_ini_sha256' => $shaB,
            'workers' => [
                [
                    'control_host' => 'site-a.control.example.invalid',
                    'fpm_configuration_sha256' => $shaC,
                    'worker_configuration_sha256' => $shaA,
                    'worker_id' => 'site-a',
                ],
                [
                    'control_host' => 'site-b.control.example.invalid',
                    'fpm_configuration_sha256' => $shaD,
                    'worker_configuration_sha256' => $shaB,
                    'worker_id' => 'site-b',
                ],
            ],
        ];
        $bytes = InstalledFpmIngressVerifier::canonicalJson($document) . "\n";
        self::assertSame($document, InstalledFpmIngressVerifier::parseConfiguration($bytes));
        $singleWorker = array_replace($document, [
            'workers' => [$document['workers'][0]],
        ]);
        self::assertSame(
            $singleWorker,
            InstalledFpmIngressVerifier::parseConfiguration(
                InstalledFpmIngressVerifier::canonicalJson($singleWorker) . "\n"
            )
        );
        self::assertSame([
            'configuration_file' => InstalledFpmIngressVerifier::CONFIGURATION_PATH,
            'configuration_sha256' => $shaA,
            'contract' => false,
            'host_preflight_root' => '/var/lib/duo-cloud/host-preflight',
        ], InstalledFpmIngressVerifier::parseArguments([
            '--configuration-file=' . InstalledFpmIngressVerifier::CONFIGURATION_PATH,
            "--configuration-sha256=$shaA",
            '--host-preflight-root=/var/lib/duo-cloud/host-preflight',
        ]));
        self::assertSame([
            'configuration_file' => null,
            'configuration_sha256' => null,
            'contract' => true,
            'host_preflight_root' => null,
        ], InstalledFpmIngressVerifier::parseArguments(['--contract']));

        $invalidDocuments = [
            ' {' . substr($bytes, 1),
            InstalledFpmIngressVerifier::canonicalJson(array_replace($document, [
                'workers' => [],
            ])) . "\n",
            InstalledFpmIngressVerifier::canonicalJson(array_replace($document, [
                'workers' => array_reverse($document['workers']),
            ])) . "\n",
            InstalledFpmIngressVerifier::canonicalJson(array_replace($document, [
                'workers' => [
                    $document['workers'][0],
                    array_replace($document['workers'][1], [
                        'control_host' => $document['workers'][0]['control_host'],
                    ]),
                ],
            ])) . "\n",
        ];
        foreach ($invalidDocuments as $invalid) {
            try {
                InstalledFpmIngressVerifier::parseConfiguration($invalid);
                self::fail('invalid installed proof descriptor was accepted');
            } catch (InstalledFpmIngressRefusal $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
        foreach ([
            [
                '--configuration-file=/tmp/proof.json',
                "--configuration-sha256=$shaA",
                '--host-preflight-root=/var/lib/duo-cloud/host-preflight',
            ],
            ['--configuration-file=' . InstalledFpmIngressVerifier::CONFIGURATION_PATH],
            ['--contract', "--configuration-sha256=$shaA"],
            ['--unknown=value'],
        ] as $arguments) {
            try {
                InstalledFpmIngressVerifier::parseArguments($arguments);
                self::fail('invalid installed proof arguments were accepted');
            } catch (InstalledFpmIngressRefusal $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testRenderedPoolMustBindExactIdentitySocketAndWorkerPin(): void {
        $workerSha = str_repeat('a', 64);
        $template = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/php-fpm-pool.conf.example'
        );
        $rendered = str_replace(
            ['REPLACE_WORKER', 'REPLACE_WITH_LOWERCASE_SHA256'],
            ['site-a', $workerSha],
            $template
        );
        InstalledFpmIngressVerifier::assertFpmConfiguration(
            $rendered,
            'site-a',
            $workerSha,
            $template
        );
        self::addToAssertionCount(1);

        foreach ([
            str_replace('listen.group = duo-cloud', 'listen.group = duo-cloud-edge', $rendered),
            str_replace('listen.mode = 0660', 'listen.mode = 0600', $rendered),
            str_replace('/run/duo-cloud/site-a/', '/run/duo-cloud/site-b/', $rendered),
            str_replace($workerSha, str_repeat('b', 64), $rendered),
            $rendered . "[other-pool]\nuser = duo-cloud\n",
            str_replace(
                'listen.mode = 0660',
                "listen.mode = 0660\nlisten.acl_groups = duo-cloud-edge",
                $rendered
            ),
        ] as $invalid) {
            try {
                InstalledFpmIngressVerifier::assertFpmConfiguration(
                    $invalid,
                    'site-a',
                    $workerSha,
                    $template
                );
                self::fail('misbound rendered FPM pool was accepted');
            } catch (InstalledFpmIngressRefusal $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testAdaptedCaddyMustHaveOnlyExactHostSocketRoutesAndFallback(): void {
        $expected = [
            'site-a.control.example.invalid' => '/run/duo-cloud/site-a/php-fpm.sock',
            'site-b.control.example.invalid' => '/run/duo-cloud/site-b/php-fpm.sock',
        ];
        $routes = [];
        foreach ($expected as $host => $socket) {
            $routes[] = self::adaptedHostRoute($host, $socket);
        }
        $routes[] = [
            'group' => 'group3',
            'handle' => [[
                'handler' => 'subroute',
                'routes' => [[
                    'handle' => [['handler' => 'static_response', 'status_code' => 404]],
                ]],
            ]],
        ];
        $document = [
            'admin' => ['disabled' => true],
            'apps' => [
                'http' => [
                    'servers' => [
                        'srv0' => [
                            'idle_timeout' => 30000000000,
                            'listen' => ['127.0.0.1:8081'],
                            'read_header_timeout' => 5000000000,
                            'read_timeout' => 10000000000,
                            'routes' => [[
                                'handle' => [[
                                    'handler' => 'subroute',
                                    'routes' => $routes,
                                ]],
                                'terminal' => true,
                            ]],
                        ],
                    ],
                ],
            ],
        ];
        InstalledFpmIngressVerifier::assertAdaptedCaddy($document, $expected);
        self::addToAssertionCount(1);
        $singleExpected = [
            'site-a.control.example.invalid' => '/run/duo-cloud/site-a/php-fpm.sock',
        ];
        $single = $document;
        $single['apps']['http']['servers']['srv0']['routes'][0]['handle'][0]['routes'] = [
            $routes[0],
            $routes[2],
        ];
        InstalledFpmIngressVerifier::assertAdaptedCaddy($single, $singleExpected);
        self::addToAssertionCount(1);

        $crossed = $document;
        $crossed['apps']['http']['servers']['srv0']['routes'][0]['handle'][0]
            ['routes'][0]['handle'][0]['routes'][0]['handle'][1]['upstreams'][0]['dial'] =
                'unix//run/duo-cloud/site-b/php-fpm.sock';
        $wildcard = $document;
        $wildcard['apps']['http']['servers']['srv0']['routes'][0]['handle'][0]
            ['routes'][0]['match'][0]['host'] = ['*'];
        $nonEmptyFallback = $document;
        $nonEmptyFallback['apps']['http']['servers']['srv0']['routes'][0]['handle'][0]
            ['routes'][2]['handle'][0]['routes'][0]['handle'][0]['body'] = 'not found';
        $extraProxy = $document;
        $extraProxy['apps']['http']['servers']['srv0']['routes'][] = [
            'handle' => [[
                'handler' => 'reverse_proxy',
                'upstreams' => [['dial' => '127.0.0.1:9000']],
            ]],
        ];
        $additiveHandler = $document;
        $additiveHandler['apps']['http']['servers']['srv0']['routes'][0]['handle'][0]
            ['routes'][2]['handle'][] = ['handler' => 'file_server'];
        foreach ([
            $crossed, $wildcard, $nonEmptyFallback, $extraProxy, $additiveHandler,
        ] as $invalid) {
            try {
                InstalledFpmIngressVerifier::assertAdaptedCaddy($invalid, $expected);
                self::fail('open or cross-routed adapted Caddy topology was accepted');
            } catch (InstalledFpmIngressRefusal $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testSystemdAndProcParsersRefuseOverridesOrWrongGroups(): void {
        $properties = "LoadState=loaded\nActiveState=active\nSubState=running\n"
            . "FragmentPath=/etc/systemd/system/duo.service\nDropInPaths=\n"
            . "NeedDaemonReload=no\nMainPID=42\nExecMainPID=42\n"
            . "ActiveEnterTimestamp=Thu 2026-08-20 10:00:00 UTC\n"
            . 'ExecStart={ path=/usr/bin/caddy ; argv[]=/usr/bin/caddy run ; '
            . 'ignore_errors=no ; start_time=[Thu 2026-08-20 10:00:00 UTC] ; '
            . "stop_time=[n/a] ; pid=0 ; code=(null) ; status=0/0 }\n"
            . "User=duo-cloud-edge\nGroup=duo-cloud-edge\n"
            . "SupplementaryGroups=duo-cloud\nUMask=0077\n";
        $unitProperties = InstalledFpmIngressVerifier::parseUnitProperties($properties);
        self::assertSame('duo-cloud', $unitProperties['SupplementaryGroups']);
        self::assertSame(
            42,
            InstalledFpmIngressVerifier::assertUnitExecutionBinding(
                $unitProperties,
                '/usr/bin/caddy'
            )
        );
        $reportedMainPid = $unitProperties;
        $reportedMainPid['ExecStart'] = str_replace('pid=0', 'pid=42', $reportedMainPid['ExecStart']);
        self::assertSame(
            42,
            InstalledFpmIngressVerifier::assertUnitExecutionBinding(
                $reportedMainPid,
                '/usr/bin/caddy'
            )
        );
        foreach ([
            array_replace($unitProperties, ['ExecMainPID' => '43']),
            array_replace($unitProperties, [
                'ExecStart' => str_replace('pid=0', 'pid=41', $unitProperties['ExecStart']),
            ]),
        ] as $invalidBinding) {
            try {
                InstalledFpmIngressVerifier::assertUnitExecutionBinding(
                    $invalidBinding,
                    '/usr/bin/caddy'
                );
                self::fail('systemd execution identity mismatch was accepted');
            } catch (InstalledFpmIngressRefusal $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
        InstalledFpmIngressVerifier::assertProcessIdentity(
            "Name:\tcaddy\nUid:\t1001\t1001\t1001\t1001\n"
                . "Gid:\t1002\t1002\t1002\t1002\nGroups:\t1002 1003\n",
            1001,
            1002,
            1003
        );
        self::addToAssertionCount(1);
        $identities = [
            'duo-cloud' => ['gid' => 10001, 'uid' => 10001],
            'duo-cloud-edge' => ['gid' => 10002, 'uid' => 10002],
            'docker' => ['gid' => 998, 'uid' => 0],
        ];
        InstalledFpmIngressVerifier::assertServiceIdentityContract($identities);
        self::addToAssertionCount(1);
        $executableStat = ['dev' => 7, 'ino' => 11, 'mode' => 0100755];
        InstalledFpmIngressVerifier::assertExecutableIdentity(
            '/usr/bin/caddy',
            '/usr/bin/caddy',
            $executableStat,
            $executableStat
        );
        self::addToAssertionCount(1);
        foreach ([
            ['/usr/bin/other', $executableStat],
            ['/usr/bin/caddy', array_replace($executableStat, ['ino' => 12])],
        ] as [$target, $stat]) {
            try {
                InstalledFpmIngressVerifier::assertExecutableIdentity(
                    '/usr/bin/caddy',
                    $target,
                    $executableStat,
                    $stat
                );
                self::fail('different live MainPID executable was accepted');
            } catch (InstalledFpmIngressRefusal $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }

        foreach ([
            $properties . "User=other\n",
            "Not a property\n",
        ] as $invalid) {
            try {
                InstalledFpmIngressVerifier::parseUnitProperties($invalid);
                self::fail('ambiguous systemd properties were accepted');
            } catch (InstalledFpmIngressRefusal $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
        foreach ([
            array_replace_recursive($identities, [
                'duo-cloud-edge' => ['gid' => 10001, 'uid' => 10001],
            ]),
            array_replace_recursive($identities, [
                'docker' => ['gid' => 10001],
            ]),
            array_replace_recursive($identities, [
                'docker' => ['gid' => 10002],
            ]),
        ] as $invalid) {
            try {
                InstalledFpmIngressVerifier::assertServiceIdentityContract($invalid);
                self::fail('aliased service or privileged group identity was accepted');
            } catch (InstalledFpmIngressRefusal $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
        foreach ([
            "Uid:\t0\t0\t0\t0\nGid:\t1002\t1002\t1002\t1002\nGroups:\t1002 1003\n",
            "Uid:\t1001\t1001\t1001\t1001\nGid:\t1002\t1002\t1002\t1002\nGroups:\t1002\n",
            "Uid:\t1001\t1001\t1001\t1001\nGid:\t1002\t1002\t1002\t1002\n"
                . "Groups:\t1002 1003 999\n",
        ] as $invalid) {
            try {
                InstalledFpmIngressVerifier::assertProcessIdentity(
                    $invalid,
                    1001,
                    1002,
                    1003
                );
                self::fail('wrong live process identity was accepted');
            } catch (InstalledFpmIngressRefusal $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    /** @return array<string,mixed> */
    private static function adaptedHostRoute(string $host, string $socket): array {
        return [
            'group' => 'group3',
            'handle' => [[
                'handler' => 'subroute',
                'routes' => [[
                    'handle' => [
                        ['handler' => 'request_body', 'max_size' => 2097152],
                        [
                            'handler' => 'reverse_proxy',
                            'transport' => [
                                'env' => [
                                    'SCRIPT_FILENAME' => '/opt/duo-cloud/bin/duo-cloud-http',
                                    'SCRIPT_NAME' => '/duo-cloud-http',
                                ],
                                'protocol' => 'fastcgi',
                                'root' => '/opt/duo-cloud',
                            ],
                            'upstreams' => [['dial' => 'unix/' . $socket]],
                        ],
                    ],
                ]],
            ]],
            'match' => [[
                'host' => [$host],
                'path' => [
                    '/v1/preview/control',
                    '/v1/preview/lifecycle',
                    '/v1/origin/controller/export',
                    '/v1/origin/pair/begin',
                    '/v1/origin/pair/poll',
                    '/v1/origin/demand/poll',
                    '/v1/origin/export/announce',
                    '/v1/origin/export/missing',
                    '/v1/origin/export/chunk',
                    '/v1/origin/export/commit',
                    '/v1/origin/key/rotate',
                    '/v1/origin/revoke',
                ],
            ]],
        ];
    }
}

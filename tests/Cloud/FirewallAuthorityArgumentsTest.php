<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class FirewallAuthorityArgumentsTest extends TestCase {
    public function testWorkerPreflightCrossesTheSharedClientAuthorityGrammar(): void {
        foreach ([
            DUO_REPO_ROOT . '/cloud/bin/duo-cloud-firewall-client',
            DUO_REPO_ROOT . '/cloud/bin/duo-cloud-firewall-authority',
        ] as $entrypoint) {
            $source = file_get_contents($entrypoint);
            self::assertIsString($source);
            self::assertStringContainsString(
                "require_once dirname(__DIR__) . '/runtime/FirewallAuthorityArguments.php';",
                $source
            );
            self::assertStringContainsString('FirewallAuthorityArguments::parse($arguments)', $source);
        }

        $configurationSha256 = hash('sha256', 'worker-preflight-configuration');
        $accepted = $this->runGrammar([
            'worker-preflight', '--config-sha256', $configurationSha256,
        ]);
        self::assertSame(0, $accepted['exit']);
        self::assertSame('', $accepted['stderr']);
        self::assertSame(json_encode([
            'action' => 'worker-preflight',
            'options' => ['config-sha256' => $configurationSha256],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
            $accepted['stdout']);

        foreach ([
            ['worker-preflight'],
            ['worker-preflight', '--config-sha256', $configurationSha256,
                '--config-sha256', $configurationSha256],
            ['worker-preflight', '--unknown', $configurationSha256],
        ] as $arguments) {
            self::assertSame([
                'exit' => 70,
                'stderr' => "duo-cloud-firewall-arguments: refused\n",
                'stdout' => '',
            ], $this->runGrammar($arguments));
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{exit:int,stderr:string,stdout:string}
     */
    private function runGrammar(array $arguments): array {
        $path = var_export(
            DUO_REPO_ROOT . '/cloud/runtime/FirewallAuthorityArguments.php',
            true
        );
        $source = <<<'PHP'
require_once __PATH__;
try {
    $result = \Duo\Cloud\FirewallAuthorityArguments::parse(array_slice($_SERVER['argv'], 1));
    fwrite(STDOUT, json_encode(
        $result,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . "\n");
} catch (Throwable) {
    fwrite(STDERR, "duo-cloud-firewall-arguments: refused\n");
    exit(70);
}
PHP;
        $source = str_replace('__PATH__', $path, $source);
        $pipes = [];
        $process = proc_open(array_merge([
            PHP_BINARY, '-r', $source, '--',
        ], $arguments), [
            0 => ['file', '/dev/null', 'rb'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, ['LANG' => 'C'], ['bypass_shell' => true]);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertIsString($stdout);
        self::assertIsString($stderr);
        return ['exit' => $exit, 'stderr' => $stderr, 'stdout' => $stdout];
    }
}

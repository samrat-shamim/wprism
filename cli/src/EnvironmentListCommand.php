<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/Registry.php';
require_once __DIR__ . '/Environment/EnvironmentRegistry.php';
require_once __DIR__ . '/Transport.php';
require_once __DIR__ . '/LocalTransport.php';
require_once __DIR__ . '/DockerTransport.php';
require_once __DIR__ . '/SshTransport.php';

/** Target-free host handler for listing configured environments. */
final class EnvironmentListCommand {
    public static function run(?string $envsFileOverride, string $startDir): int {
        $envs = EnvironmentRegistry::load($envsFileOverride, $startDir)->all();
        if (!$envs) {
            fwrite(STDERR, "duo: no environments defined (looked for an 'envs' object in site.duo.json and .duo-envs.json)\n");
            return 1;
        }
        $width = max(array_map(static fn ($name): int => strlen((string) $name), array_keys($envs)));
        foreach ($envs as $name => $cfg) {
            $name = (string) $name;
            try {
                $transport = Transport::make($name, $cfg);
                printf("%-{$width}s  %s\n", $name, $transport->describe());
            } catch (\Throwable $e) {
                printf("%-{$width}s  ERROR: %s\n", $name, $e->getMessage());
            }
        }
        return 0;
    }
}

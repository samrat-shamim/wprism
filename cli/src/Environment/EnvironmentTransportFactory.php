<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/CloudPreviewTransport.php';
require_once __DIR__ . '/../Transport/Transport.php';
require_once __DIR__ . '/../Transport/LocalTransport.php';
require_once __DIR__ . '/../Transport/DockerTransport.php';
require_once __DIR__ . '/../Transport/SshTransport.php';

/** Surface composition for configured ordinary and Cloud preview transports. */
final class EnvironmentTransportFactory {
    /** @param array<string,mixed> $config */
    public static function make(string $name, array $config): Transport {
        $type = $config['transport'] ?? null;
        if (!is_string($type) || $type === '') {
            throw new \RuntimeException(
                "env '$name': missing required key 'transport' "
                    . '(expected one of: cloud-preview, local, docker, ssh)'
            );
        }
        return match ($type) {
            'cloud-preview' => new CloudPreviewTransport($name, $config),
            'local', 'docker', 'ssh' => Transport::make($name, $config),
            default => throw new \RuntimeException(
                "env '$name': unknown transport '$type' "
                    . '(expected one of: cloud-preview, local, docker, ssh)'
            ),
        };
    }
}

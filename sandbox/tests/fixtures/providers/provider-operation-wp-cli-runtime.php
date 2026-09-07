<?php
/** WP-CLI process seams that route the product launcher into the real fixture boot. */
declare(strict_types=1);

namespace WPrismTest\ProviderOperationCli {
    final class Configurator {
        /** @return array{0:array{},1:array{},2:array{}} */
        public function parse_args(array $args): array {
            return [[], [], []];
        }
    }
}

namespace {
    final class WP_CLI {
        public static function add_command(string $name, string $class): void {}

        public static function line(string $line): void {
            echo $line . "\n";
        }

        public static function halt(int $status): never {
            throw new \RuntimeException('fixture WP-CLI halt ' . $status);
        }

        public static function get_configurator(): \WPrismTest\ProviderOperationCli\Configurator {
            return new \WPrismTest\ProviderOperationCli\Configurator();
        }

        public static function get_runner(): object {
            return (object) ['alias' => null];
        }
    }
}

namespace WP_CLI\Utils {
    function check_proc_available(string $context): void {}

    function get_php_binary(): string {
        return PHP_BINARY;
    }

    /** @param array<string,mixed> $args */
    function assoc_args_to_str(array $args, array $sensitive = []): string {
        return '';
    }

    /**
     * Replace only WP-CLI's executable in the offline fixture. The production
     * launcher still owns the session gate, watchdog, stdin/output bounds,
     * deadline, exit classification, and response validation around it.
     *
     * @param array<int,mixed> $descriptors
     * @param array<int,resource> $pipes
     */
    function proc_open_compat(
        string $command,
        array $descriptors,
        array &$pipes,
        ?string $cwd = null,
        ?array $environment = null,
        ?array $options = null
    ) {
        $driver = $GLOBALS['wprism_provider_operation_child_driver'] ?? null;
        $repo = $GLOBALS['wprism_provider_operation_repo'] ?? null;
        $state = $GLOBALS['wprism_provider_operation_state'] ?? null;
        $injectedStderr = $GLOBALS['wprism_provider_operation_injected_stderr'] ?? '';
        $transportCase = $GLOBALS['wprism_provider_operation_transport_case'] ?? 'native';
        if (!is_string($driver)
            || !is_string($repo)
            || !is_string($state)
            || !is_string($injectedStderr)
            || !in_array($transportCase, ['native', 'boot-exit', 'malformed-stdout', 'failure-as-success', 'wrong-identity'], true)
            || realpath($driver) !== $driver
            || realpath($repo) !== $repo
            || realpath($state) !== $state) {
            return false;
        }
        $wrapper = <<<'PHP'
$sid = posix_setsid();
$gate = fopen('php://fd/3', 'rb');
$start = is_resource($gate) ? fread($gate, 1) : false;
if (is_resource($gate)) {
    fclose($gate);
}
if (!is_int($sid) || $sid < 1 || $start !== 'S') {
    exit(125);
}
$driver = $argv[1] ?? null;
$repo = $argv[2] ?? null;
$state = $argv[3] ?? null;
$injectedStderr = $argv[4] ?? null;
$transportCase = $argv[5] ?? null;
if (!is_string($driver) || !is_string($repo) || !is_string($state)) {
    exit(126);
}
if (is_string($injectedStderr) && $injectedStderr !== '') {
    fwrite(STDERR, $injectedStderr);
}
if ($transportCase !== 'native') {
    if ($transportCase === 'boot-exit') {
        echo 'private-boot-stdout';
        fwrite(STDERR, 'private-boot-stderr');
        exit(7);
    }
    if ($transportCase === 'malformed-stdout') {
        echo 'private-malformed-stdout';
        exit(0);
    }
    require $repo . '/agent/src/Kernel/Canon.php';
    $input = stream_get_contents(STDIN);
    $request = json_decode($input, true, 64, JSON_THROW_ON_ERROR);
    echo \WPrism\Canon::encode([
        'adapter' => $transportCase === 'wrong-identity' ? 'private-foreign-adapter' : $request['adapter'],
        'adapter_digest' => $request['adapter_digest'],
        'capability' => $request['capability'],
        'format' => $transportCase === 'failure-as-success'
            ? 'wprism-provider-operation-failure/v1' : 'wprism-provider-operation-response/v2',
        'operation' => $request['operation'],
        'provider' => $request['provider'],
        'request_sha256' => hash('sha256', $input),
        'result' => ['private' => 'private-unaccepted-receipt'],
    ]);
    exit(0);
}
$GLOBALS['argv'] = [$driver, $repo, $state];
require $driver;
PHP;
        $store = \wprism_wp_store();
        $identity = (new \ReflectionProperty(\WPrism\ProviderOperationProcess::class, 'pendingIdentity'))->getValue();
        $GLOBALS['wprism_provider_operation_request_identities'][] = $identity;
        if (($identity['operation'] ?? null) === 'observe'
            && array_key_exists('wprism_provider_operation_between_children_value', $GLOBALS)) {
            // A distinct actor changes the durable fixture between real child
            // boots. The production observer and comparison must see it; no
            // receipt or response parser is replaced by the test.
            $record = json_decode((string) file_get_contents($state), true, 64, JSON_THROW_ON_ERROR);
            $record['value'] = $GLOBALS['wprism_provider_operation_between_children_value'];
            $bytes = \WPrism\Canon::encode($record);
            if (file_put_contents($state, $bytes) !== strlen($bytes)) {
                throw new \RuntimeException('cannot change durable state between provider children');
            }
        }
        $GLOBALS['wprism_provider_operation_prelaunch_cache_views'][] = $store->cache === [];
        $process = proc_open(
            [PHP_BINARY, '-r', $wrapper, $driver, $repo, $state, $injectedStderr, $transportCase],
            $descriptors,
            $pipes,
            $cwd,
            $environment,
            $options
        );
        // A persistent backend may be repopulated as soon as one child boots;
        // force the next launch to prove it performs its own pre-boot flush.
        $store->cache['persistent']['provider-operation-fixture'] = 'stale';
        return $process;
    }
}

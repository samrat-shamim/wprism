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
        if (!is_string($driver)
            || !is_string($repo)
            || !is_string($state)
            || !is_string($injectedStderr)
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
if (!is_string($driver) || !is_string($repo) || !is_string($state)) {
    exit(126);
}
if (is_string($injectedStderr) && $injectedStderr !== '') {
    fwrite(STDERR, $injectedStderr);
}
$GLOBALS['argv'] = [$driver, $repo, $state];
require $driver;
PHP;
        $store = \wprism_wp_store();
        $GLOBALS['wprism_provider_operation_prelaunch_cache_views'][] = $store->cache === [];
        $process = proc_open(
            [PHP_BINARY, '-r', $wrapper, $driver, $repo, $state, $injectedStderr],
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

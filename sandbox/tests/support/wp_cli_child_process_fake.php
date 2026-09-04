<?php
declare(strict_types=1);

namespace WPrismTest;

/** Shared offline WP-CLI runtime shape for the bounded child launcher. */
final class WpCliChildConfigurator {
    /** @return array{0:array{},1:array{},2:array{}} */
    public function parse_args(array $args): array {
        return [[], [], []];
    }
}

trait WpCliChildRuntime {
    public static function get_configurator(): WpCliChildConfigurator {
        return new WpCliChildConfigurator();
    }

    public static function get_runner(): object {
        return (object) ['alias' => null];
    }
}

namespace WP_CLI\Utils;

function check_proc_available(string $context): void {}

function get_php_binary(): string {
    return PHP_BINARY;
}

/** @param array<string,mixed> $args */
function assoc_args_to_str(array $args, array $sensitive = []): string {
    $out = '';
    foreach ($args as $key => $value) {
        $out .= $value === true
            ? " --$key"
            : " --$key=" . escapeshellarg((string) $value);
    }
    return $out;
}

/**
 * Preserve each product fixture's in-process mutation model, then hand the
 * bounded transport a real child pipe carrying that fake command's receipt.
 * Production never reaches this file; the prior tests' WP_CLI::runcommand()
 * implementation remains the exact semantic fault-injection boundary.
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
    $result = \WP_CLI::runcommand($command, [
        'launch' => true,
        'return' => 'all',
        'exit_error' => false,
    ]);
    if (!is_object($result)
        || !isset($result->return_code)
        || !is_int($result->return_code)) {
        return false;
    }
    $stdout = is_string($result->stdout ?? null) ? $result->stdout : (string) ($result->stdout ?? '');
    $stderr = is_string($result->stderr ?? null) ? $result->stderr : (string) ($result->stderr ?? '');
    $exit = $result->return_code;
    if ($exit < 0 || $exit > 255) {
        return false;
    }
    $stderrFirst = ($GLOBALS['wprism_wp_cli_child_fake_stderr_first'] ?? false) === true;
    // Mirror the production wrapper's setsid + parent start gate. The fake
    // still supplies in-process semantic output, but transport exercises the
    // real parent watchdog and cannot let plugin-shaped output race ahead of
    // its liveness fence.
    $script = '$sid=posix_setsid();$gate=fopen("php://fd/3","rb");'
        . '$start=is_resource($gate)?fread($gate,1):false;if(is_resource($gate)){fclose($gate);}'
        . 'if(!is_int($sid)||$sid<1||$start!=="S"){exit(125);}'
        . 'if(($argv[4]??"")==="1"){stream_get_contents(STDIN);}'
        . ($stderrFirst
            ? 'fwrite(STDERR, base64_decode($argv[2], true)); fwrite(STDOUT, base64_decode($argv[1], true)); '
            : 'fwrite(STDOUT, base64_decode($argv[1], true)); fwrite(STDERR, base64_decode($argv[2], true)); ');
    $script .= 'exit((int) $argv[3]);';
    return proc_open(
        [
            PHP_BINARY,
            '-r',
            $script,
            base64_encode($stdout),
            base64_encode($stderr),
            (string) $exit,
            is_array($descriptors[0] ?? null) ? '1' : '0',
        ],
        $descriptors,
        $pipes,
        $cwd,
        $environment,
        $options
    );
}

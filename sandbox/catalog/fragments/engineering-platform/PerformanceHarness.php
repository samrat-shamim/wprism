<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform;

/**
 * The authorizing benchmark harness. It deliberately uses one FPM container
 * for both variants so scheduler, database, WordPress and OPcache conditions
 * are shared while samples alternate baseline/candidate order.
 *
 * @phpstan-type Sample array{duration_ms:float,rss_kib:int}
 * @phpstan-type Result array{samples:list<Sample>,median_ms:float,p95_ms:float,maximum_rss_kib:int,noise_ratio:float}
 */
final class PerformanceHarness
{
    private string $temporary = '';
    private string $network = '';
    private string $databaseContainer = '';
    private string $wordpressContainer = '';
    private string $baselineRoot = '';
    private int $fpmPort = 0;

    public function __construct(private readonly string $root) {}

    /**
     * @param array<string,mixed> $profile
     * @return array{environment:array<string,mixed>,scenarios:array<string,array{baseline:Result,candidate:Result,interleave_order:list<string>}>}
     */
    public function run(array $profile, int $warmups, int $samples): array
    {
        if ($warmups < 0 || $samples < 1) {
            throw new CatalogException('controlled performance sample counts are invalid');
        }
        $baseline = $profile['baseline'] ?? null;
        $images = $profile['images'] ?? null;
        $resources = $profile['resources'] ?? null;
        if (!is_array($baseline) || !is_array($images) || !is_array($resources)
            || !is_string($baseline['commit'] ?? null)
            || preg_match('/^[a-f0-9]{40}$/D', $baseline['commit']) !== 1
            || !is_string($images['wordpress'] ?? null)
            || !is_string($images['database'] ?? null)
            || !is_int($resources['cpu_count'] ?? null)
            || !is_int($resources['memory_mib'] ?? null)) {
            throw new CatalogException('controlled performance profile cannot initialize the harness');
        }
        if ($this->capture(['git', 'status', '--porcelain=v1', '--untracked-files=all'], $this->root) !== '') {
            throw new CatalogException('controlled performance benchmark requires a clean candidate worktree');
        }
        $this->temporary = sys_get_temp_dir() . '/duo-performance-' . bin2hex(random_bytes(8));
        $this->network = 'duo-perf-' . bin2hex(random_bytes(6));
        $this->databaseContainer = $this->network . '-db';
        $this->wordpressContainer = $this->network . '-wp';
        $this->baselineRoot = $this->temporary . '/baseline';
        if (!mkdir($this->temporary, 0700, true)) {
            throw new CatalogException('cannot create controlled performance root');
        }

        try {
            $this->capture(['git', 'worktree', 'add', '--detach', $this->baselineRoot, $baseline['commit']], $this->root);
            $candidateDist = $this->temporary . '/candidate-dist';
            $baselineDist = $this->temporary . '/baseline-dist';
            (new Build($this->root))->build($candidateDist);
            (new Build($this->baselineRoot))->build($baselineDist);
            $this->startInfrastructure($images, $resources);
            $this->installWordPressPayloads($baselineDist, $candidateDist);
            $environment = $this->environment($images, $resources, $baseline['commit']);
            $scenarios = [];
            foreach ($this->scenarioNames() as $scenario) {
                $scenarios[$scenario] = $this->measurePaired($scenario, $warmups, $samples);
            }
            return ['environment' => $environment, 'scenarios' => $scenarios];
        } finally {
            $this->cleanup();
        }
    }

    /** @return list<string> */
    private function scenarioNames(): array
    {
        return [
            'ordinary-wordpress-disabled',
            'ordinary-wordpress-enabled',
            'journal-enabled',
            'agent-command-cold',
            'agent-command-warm',
            'host-cli-cold',
            'host-cli-warm',
            'closure-hashing',
            'targeted-test-feedback',
            'complete-test-feedback',
        ];
    }

    /**
     * @param array<mixed,mixed> $images
     * @param array<mixed,mixed> $resources
     */
    private function startInfrastructure(array $images, array $resources): void
    {
        $wordpressImage = $images['wordpress'];
        $databaseImage = $images['database'];
        $cpuCount = $resources['cpu_count'];
        $memoryMib = $resources['memory_mib'];
        if (!is_string($wordpressImage) || !is_string($databaseImage)
            || !is_int($cpuCount) || !is_int($memoryMib)) {
            throw new CatalogException('controlled performance image/resource profile is malformed');
        }
        foreach ([$wordpressImage, $databaseImage] as $image) {
            $this->capture(['docker', 'image', 'inspect', $image, '--format={{.Id}}']);
        }
        $documentRoot = $this->temporary . '/wordpress';
        if (!mkdir($documentRoot, 0777, true)) {
            throw new CatalogException('cannot create WordPress performance document root');
        }
        chmod($documentRoot, 0777);
        $this->capture(['docker', 'network', 'create', $this->network]);
        $this->capture([
            'docker', 'run', '--detach', '--pull=never', '--name', $this->databaseContainer,
            '--network', $this->network, '--network-alias', 'database',
            '--cpus=1', '--memory=1024m', '--pids-limit=256',
            '--env', 'MARIADB_DATABASE=duo_performance',
            '--env', 'MARIADB_USER=duo',
            '--env', 'MARIADB_PASSWORD=duo-performance-only',
            '--env', 'MARIADB_ROOT_PASSWORD=duo-performance-root-only',
            $databaseImage,
        ]);
        $this->waitForDatabase();
        $this->capture([
            'docker', 'run', '--detach', '--pull=never', '--name', $this->wordpressContainer,
            '--network', $this->network,
            '--cpus=' . $cpuCount, '--memory=' . $memoryMib . 'm', '--pids-limit=256',
            '--publish', '127.0.0.1::9000',
            '--mount', 'type=bind,source=' . $documentRoot . ',target=/var/www/html',
            '--env', 'WORDPRESS_DB_HOST=database:3306',
            '--env', 'WORDPRESS_DB_USER=duo',
            '--env', 'WORDPRESS_DB_PASSWORD=duo-performance-only',
            '--env', 'WORDPRESS_DB_NAME=duo_performance',
            $wordpressImage,
        ]);
        $this->waitForWordPress($documentRoot);
        $this->installWordPress();
    }

    private function waitForDatabase(): void
    {
        for ($attempt = 0; $attempt < 120; ++$attempt) {
            if ($this->tryCommand([
                'docker', 'exec', $this->databaseContainer,
                'mariadb-admin', 'ping', '--host=127.0.0.1', '--user=root',
                '--password=duo-performance-root-only', '--silent',
            ])) {
                return;
            }
            usleep(500_000);
        }
        throw new CatalogException('controlled MariaDB did not become ready');
    }

    private function waitForWordPress(string $documentRoot): void
    {
        for ($attempt = 0; $attempt < 120; ++$attempt) {
            $port = trim($this->captureOrEmpty([
                'docker', 'port', $this->wordpressContainer, '9000/tcp',
            ]));
            if (preg_match('/127\.0\.0\.1:([0-9]+)$/D', $port, $match) === 1
                && is_file($documentRoot . '/wp-config.php')) {
                $this->fpmPort = (int) $match[1];
                if ($this->fpmPort > 0 && $this->tcpReady($this->fpmPort)) {
                    return;
                }
            }
            usleep(500_000);
        }
        throw new CatalogException('controlled WordPress FPM did not become ready');
    }

    private function tcpReady(int $port): bool
    {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $error, $message, 0.25);
        if (!is_resource($socket)) {
            return false;
        }
        fclose($socket);
        return true;
    }

    private function installWordPress(): void
    {
        $script = <<<'PHP'
define('WP_INSTALLING', true);
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
if (!is_blog_installed()) {
    wp_install('Duo performance', 'duo-admin', 'duo@example.invalid', false, '', 'duo-performance-password');
}
if (!is_blog_installed()) {
    fwrite(STDERR, "WordPress installation failed\n");
    exit(1);
}
PHP;
        $this->capture(['docker', 'exec', $this->wordpressContainer, 'php', '-r', $script]);
    }

    private function installWordPressPayloads(string $baselineDist, string $candidateDist): void
    {
        $muPlugins = $this->temporary . '/wordpress/wp-content/mu-plugins';
        if (!is_dir($muPlugins) && !mkdir($muPlugins, 0755, true)) {
            throw new CatalogException('cannot create benchmark mu-plugin directory');
        }
        $this->copyTree($baselineDist . '/payload/agent', $muPlugins . '/baseline');
        $this->copyTree($candidateDist . '/payload/agent', $muPlugins . '/candidate');
        $loader = <<<'PHP'
<?php
$variant = $_SERVER['DUO_VARIANT'] ?? '';
if (($_SERVER['DUO_JOURNAL'] ?? '') === '1' && !defined('DUO_JOURNAL')) {
    define('DUO_JOURNAL', true);
}
if ($variant === 'baseline' || $variant === 'candidate') {
    require __DIR__ . '/' . $variant . '/duo-loader.php';
}
PHP;
        $agent = <<<'PHP'
<?php
class WP_CLI {
    public static function add_command($name, $class): void {}
}
define('ABSPATH', __DIR__ . '/');
define('WP_CLI', true);
$variant = $_SERVER['DUO_VARIANT'] ?? '';
if ($variant !== 'baseline' && $variant !== 'candidate') {
    http_response_code(400);
    exit;
}
require __DIR__ . '/wp-content/mu-plugins/' . $variant . '/duo-loader.php';
echo "ok\n";
PHP;
        $environment = <<<'PHP'
<?php
header('Content-Type: application/json');
echo json_encode([
    'php_version' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'opcache_enabled' => function_exists('opcache_get_status') && opcache_get_status(false) !== false,
]);
PHP;
        $reset = <<<'PHP'
<?php
echo function_exists('opcache_reset') && opcache_reset() ? "reset\n" : "unavailable\n";
PHP;
        $this->publish($muPlugins . '/duo-benchmark-loader.php', $loader);
        $this->publish($this->temporary . '/wordpress/duo-benchmark-agent.php', $agent);
        $this->publish($this->temporary . '/wordpress/duo-benchmark-environment.php', $environment);
        $this->publish($this->temporary . '/wordpress/duo-benchmark-reset.php', $reset);
    }

    /**
     * @param array<mixed,mixed> $images
     * @param array<mixed,mixed> $resources
     * @return array<string,mixed>
     */
    private function environment(array $images, array $resources, string $baseline): array
    {
        $wordpressImage = $images['wordpress'] ?? null;
        $databaseImage = $images['database'] ?? null;
        if (!is_string($wordpressImage) || !is_string($databaseImage)) {
            throw new CatalogException('controlled performance image profile is malformed');
        }
        $response = $this->fastCgi('/var/www/html/duo-benchmark-environment.php', []);
        try {
            $fpm = json_decode($response, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new CatalogException('controlled FPM environment response is invalid: ' . $exception->getMessage());
        }
        if (!is_array($fpm) || ($fpm['sapi'] ?? null) !== 'fpm-fcgi'
            || ($fpm['opcache_enabled'] ?? null) !== true
            || !is_string($fpm['php_version'] ?? null)
            || !str_starts_with($fpm['php_version'], '8.3.')) {
            throw new CatalogException('controlled WordPress runtime is not PHP 8.3 FPM with OPcache');
        }
        $extensions = explode("\n", trim($this->capture([
            'docker', 'exec', $this->wordpressContainer, 'php', '-m',
        ])));
        sort($extensions, SORT_STRING);
        return [
            'baseline_sha' => $baseline,
            'candidate_sha' => trim($this->capture(['git', 'rev-parse', 'HEAD'], $this->root)),
            'wordpress_image' => $wordpressImage,
            'wordpress_image_id' => trim($this->capture([
                'docker', 'image', 'inspect', $wordpressImage, '--format={{.Id}}',
            ])),
            'database_image' => $databaseImage,
            'database_image_id' => trim($this->capture([
                'docker', 'image', 'inspect', $databaseImage, '--format={{.Id}}',
            ])),
            'resources' => $resources,
            'kernel' => php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m'),
            'docker_server_version' => trim($this->capture(['docker', 'version', '--format={{.Server.Version}}'])),
            'fpm' => $fpm,
            'extensions' => $extensions,
            'interleaving' => 'alternating baseline/candidate first on one FPM/OPcache stack',
        ];
    }

    /** @return array{baseline:Result,candidate:Result,interleave_order:list<string>} */
    private function measurePaired(string $scenario, int $warmups, int $samples): array
    {
        for ($index = 0; $index < $warmups; ++$index) {
            foreach ($this->order($index) as $variant) {
                $this->scenarioSample($scenario, $variant);
            }
        }
        /** @var array{baseline:list<Sample>,candidate:list<Sample>} $results */
        $results = ['baseline' => [], 'candidate' => []];
        $order = [];
        for ($index = 0; $index < $samples; ++$index) {
            foreach ($this->order($index) as $variant) {
                $results[$variant][] = $this->scenarioSample($scenario, $variant);
                $order[] = $variant;
            }
        }
        return [
            'baseline' => $this->summarize($results['baseline']),
            'candidate' => $this->summarize($results['candidate']),
            'interleave_order' => $order,
        ];
    }

    /** @return list<string> */
    private function order(int $index): array
    {
        return $index % 2 === 0 ? ['baseline', 'candidate'] : ['candidate', 'baseline'];
    }

    /** @return Sample */
    private function scenarioSample(string $scenario, string $variant): array
    {
        if (!in_array($variant, ['baseline', 'candidate'], true)) {
            throw new CatalogException('performance harness received an unknown variant');
        }
        if (in_array($scenario, ['ordinary-wordpress-disabled', 'ordinary-wordpress-enabled', 'journal-enabled'], true)) {
            $parameters = [];
            if ($scenario !== 'ordinary-wordpress-disabled') {
                $parameters['DUO_VARIANT'] = $variant;
            }
            if ($scenario === 'journal-enabled') {
                $parameters['DUO_JOURNAL'] = '1';
            }
            return $this->fpmSample('/var/www/html/index.php', $parameters);
        }
        if ($scenario === 'agent-command-cold') {
            $this->fastCgi('/var/www/html/duo-benchmark-reset.php', []);
            return $this->fpmSample('/var/www/html/duo-benchmark-agent.php', ['DUO_VARIANT' => $variant]);
        }
        if ($scenario === 'agent-command-warm') {
            $this->fastCgi('/var/www/html/duo-benchmark-agent.php', ['DUO_VARIANT' => $variant]);
            return $this->fpmSample('/var/www/html/duo-benchmark-agent.php', ['DUO_VARIANT' => $variant]);
        }
        $variantRoot = $variant === 'baseline' ? $this->baselineRoot : $this->root;
        $command = match ($scenario) {
            'host-cli-cold', 'host-cli-warm' => [PHP_BINARY, $variantRoot . '/cli/duo', '--help'],
            'closure-hashing' => [
                PHP_BINARY, '-r',
                '$files=array_merge(glob($argv[1]."/agent/src/*.php")?:[],glob($argv[1]."/cli/src/*.php")?:[]);'
                    . 'sort($files);$h=hash_init("sha256");foreach($files as $f){hash_update_file($h,$f);}echo hash_final($h);',
                $variantRoot,
            ],
            'targeted-test-feedback' => [PHP_BINARY, '-l', $variantRoot . '/agent/duo-loader.php'],
            'complete-test-feedback' => [
                PHP_BINARY, $variantRoot . '/sandbox/catalog/fragments/engineering-platform/catalog.php', 'validate',
            ],
            default => throw new CatalogException("unknown controlled performance scenario: $scenario"),
        };
        if ($scenario === 'host-cli-warm') {
            $this->processSample($command, $variantRoot);
        }
        return $this->processSample($command, $variantRoot);
    }

    /**
     * @param array<string,string> $parameters
     * @return Sample
     */
    private function fpmSample(string $script, array $parameters): array
    {
        $started = hrtime(true);
        $this->fastCgi($script, $parameters);
        return [
            'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 3),
            'rss_kib' => $this->containerRss(),
        ];
    }

    /**
     * @param list<string> $command
     * @return Sample
     */
    private function processSample(array $command, string $directory): array
    {
        $started = hrtime(true);
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $directory,
            ['PATH' => (string) getenv('PATH'), 'LC_ALL' => 'C', 'LANG' => 'C', 'TZ' => 'UTC'],
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new CatalogException('cannot start controlled performance process');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stderr = '';
        $rss = 0;
        $status = proc_get_status($process);
        while ($status['running']) {
            stream_get_contents($pipes[1]);
            $chunk = stream_get_contents($pipes[2]);
            if (is_string($chunk)) {
                $stderr .= $chunk;
            }
            $rss = max($rss, $this->processRss((int) $status['pid']));
            usleep(1000);
            $status = proc_get_status($process);
        }
        stream_get_contents($pipes[1]);
        $tail = stream_get_contents($pipes[2]);
        if (is_string($tail)) {
            $stderr .= $tail;
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit === -1 && $status['exitcode'] >= 0) {
            $exit = $status['exitcode'];
        }
        if ($exit !== 0) {
            throw new CatalogException('controlled performance process failed: ' . trim($stderr));
        }
        return [
            'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 3),
            'rss_kib' => $rss,
        ];
    }

    /**
     * @param list<Sample> $samples
     * @return Result
     */
    private function summarize(array $samples): array
    {
        if ($samples === []) {
            throw new CatalogException('controlled performance scenario emitted no samples');
        }
        $durations = [];
        $rss = [];
        foreach ($samples as $sample) {
            $durations[] = $sample['duration_ms'];
            $rss[] = $sample['rss_kib'];
        }
        sort($durations, SORT_NUMERIC);
        $median = $this->percentile($durations, 0.5);
        return [
            'samples' => $samples,
            'median_ms' => $median,
            'p95_ms' => $this->percentile($durations, 0.95),
            'maximum_rss_kib' => max($rss),
            'noise_ratio' => $median > 0.0 ? round((max($durations) - min($durations)) / $median, 6) : 0.0,
        ];
    }

    /** @param list<float> $values */
    private function percentile(array $values, float $percentile): float
    {
        return $values[max(0, (int) ceil(count($values) * $percentile) - 1)];
    }

    /** @param array<string,string> $extra */
    private function fastCgi(string $script, array $extra): string
    {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $this->fpmPort, $error, $message, 10.0);
        if (!is_resource($socket)) {
            throw new CatalogException("cannot connect to controlled FPM: $message ($error)");
        }
        stream_set_timeout($socket, 30);
        $requestId = 1;
        $begin = pack('nC6', 1, 0, 0, 0, 0, 0, 0);
        $parameters = [
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'SERVER_SOFTWARE' => 'duo-performance-harness',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'REQUEST_METHOD' => 'GET',
            'SCRIPT_FILENAME' => $script,
            'SCRIPT_NAME' => '/' . basename($script),
            'REQUEST_URI' => '/',
            'DOCUMENT_ROOT' => '/var/www/html',
            'SERVER_NAME' => 'duo-performance.invalid',
            'HTTP_HOST' => 'duo-performance.invalid',
            'SERVER_PORT' => '80',
            'REMOTE_ADDR' => '127.0.0.1',
            'REMOTE_PORT' => '1',
            'CONTENT_LENGTH' => '0',
            'REDIRECT_STATUS' => '200',
        ] + $extra;
        $encoded = '';
        foreach ($parameters as $name => $value) {
            $encoded .= $this->fastCgiLength(strlen($name)) . $this->fastCgiLength(strlen($value)) . $name . $value;
        }
        $request = $this->fastCgiRecord(1, $requestId, $begin)
            . $this->fastCgiRecord(4, $requestId, $encoded)
            . $this->fastCgiRecord(4, $requestId, '')
            . $this->fastCgiRecord(5, $requestId, '');
        if (fwrite($socket, $request) !== strlen($request)) {
            fclose($socket);
            throw new CatalogException('cannot send controlled FastCGI request');
        }
        $stdout = '';
        $stderr = '';
        while (!feof($socket)) {
            $header = $this->readBytes($socket, 8);
            if ($header === '') {
                break;
            }
            $fields = unpack('Cversion/Ctype/nrequest/nlength/Cpadding/Creserved', $header);
            if (!is_array($fields) || ($fields['version'] ?? null) !== 1) {
                fclose($socket);
                throw new CatalogException('controlled FastCGI response has an invalid header');
            }
            $lengthValue = $fields['length'] ?? null;
            $paddingValue = $fields['padding'] ?? null;
            if (!is_int($lengthValue) || !is_int($paddingValue)) {
                fclose($socket);
                throw new CatalogException('controlled FastCGI response lengths are invalid');
            }
            $length = $lengthValue;
            $padding = $paddingValue;
            $content = $this->readBytes($socket, $length);
            if ($padding > 0) {
                $this->readBytes($socket, $padding);
            }
            if (($fields['type'] ?? null) === 6) {
                $stdout .= $content;
            } elseif (($fields['type'] ?? null) === 7) {
                $stderr .= $content;
            } elseif (($fields['type'] ?? null) === 3) {
                break;
            }
        }
        fclose($socket);
        if ($stderr !== '' || preg_match('/^Status:\s+5[0-9]{2}/mi', $stdout) === 1
            || str_contains($stdout, 'Fatal error')) {
            throw new CatalogException('controlled FastCGI request failed: ' . trim($stderr . "\n" . $stdout));
        }
        $parts = preg_split("/\r?\n\r?\n/", $stdout, 2);
        return is_array($parts) && isset($parts[1]) ? $parts[1] : $stdout;
    }

    private function fastCgiLength(int $length): string
    {
        return $length < 128 ? chr($length) : pack('N', $length | 0x80000000);
    }

    private function fastCgiRecord(int $type, int $requestId, string $content): string
    {
        $length = strlen($content);
        $padding = (8 - ($length % 8)) % 8;
        return pack('CCnnCC', 1, $type, $requestId, $length, $padding, 0)
            . $content . str_repeat("\0", $padding);
    }

    /** @param resource $stream */
    private function readBytes($stream, int $length): string
    {
        if ($length < 0) {
            throw new CatalogException('controlled FastCGI response has a negative length');
        }
        if ($length === 0) {
            return '';
        }
        $bytes = '';
        while (strlen($bytes) < $length) {
            $remaining = $length - strlen($bytes);
            if ($remaining < 1) {
                break;
            }
            $chunk = fread($stream, $remaining);
            if (!is_string($chunk) || $chunk === '') {
                $metadata = stream_get_meta_data($stream);
                if ($metadata['timed_out'] === true) {
                    throw new CatalogException('controlled FastCGI response timed out');
                }
                break;
            }
            $bytes .= $chunk;
        }
        if (strlen($bytes) !== $length) {
            throw new CatalogException('controlled FastCGI response was truncated');
        }
        return $bytes;
    }

    private function containerRss(): int
    {
        $output = $this->capture(['docker', 'top', $this->wordpressContainer, '-eo', 'rss,comm']);
        $rss = 0;
        foreach (explode("\n", trim($output)) as $line) {
            if (preg_match('/^\s*([0-9]+)\s+/', $line, $match) === 1) {
                $rss += (int) $match[1];
            }
        }
        return $rss;
    }

    private function processRss(int $pid): int
    {
        $status = @file_get_contents('/proc/' . $pid . '/status');
        return is_string($status) && preg_match('/^VmRSS:\s+([0-9]+)\s+kB$/m', $status, $match) === 1
            ? (int) $match[1]
            : 0;
    }

    private function publish(string $path, string $bytes): void
    {
        if (file_put_contents($path, $bytes) !== strlen($bytes) || !chmod($path, 0644)) {
            throw new CatalogException("cannot publish controlled benchmark helper: $path");
        }
    }

    private function copyTree(string $source, string $destination): void
    {
        if (!mkdir($destination, 0755, true)) {
            throw new CatalogException('cannot create controlled benchmark payload root');
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                throw new CatalogException('controlled benchmark iterator returned an invalid entry');
            }
            $path = $item->getPathname();
            $relative = substr($path, strlen($source) + 1);
            $target = $destination . '/' . $relative;
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0755, true)) {
                    throw new CatalogException('cannot create controlled benchmark payload directory');
                }
            } elseif ($item->isFile()) {
                $bytes = file_get_contents($path);
                if (!is_string($bytes)) {
                    throw new CatalogException('cannot read controlled benchmark payload');
                }
                $this->publish($target, $bytes);
            } else {
                throw new CatalogException('controlled benchmark payload contains a non-file entry');
            }
        }
    }

    /** @param list<string> $command */
    private function capture(array $command, ?string $directory = null): string
    {
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $directory ?? $this->root,
            ['PATH' => (string) getenv('PATH'), 'LC_ALL' => 'C', 'LANG' => 'C', 'TZ' => 'UTC'],
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new CatalogException('cannot start controlled performance command');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($stdout)) {
            throw new CatalogException('controlled performance command failed: ' . trim((string) $stderr));
        }
        return $stdout;
    }

    /** @param list<string> $command */
    private function captureOrEmpty(array $command): string
    {
        try {
            return $this->capture($command);
        } catch (CatalogException) {
            return '';
        }
    }

    /** @param list<string> $command */
    private function tryCommand(array $command): bool
    {
        try {
            $this->capture($command);
            return true;
        } catch (CatalogException) {
            return false;
        }
    }

    private function cleanup(): void
    {
        if ($this->wordpressContainer !== '') {
            $this->tryCommand(['docker', 'rm', '--force', $this->wordpressContainer]);
        }
        if ($this->databaseContainer !== '') {
            $this->tryCommand(['docker', 'rm', '--force', $this->databaseContainer]);
        }
        if ($this->network !== '') {
            $this->tryCommand(['docker', 'network', 'rm', $this->network]);
        }
        if ($this->baselineRoot !== '' && is_dir($this->baselineRoot)) {
            $this->tryCommand(['git', 'worktree', 'remove', '--force', $this->baselineRoot]);
        }
        if ($this->temporary !== '' && is_dir($this->temporary)) {
            $this->removeTree($this->temporary);
        }
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

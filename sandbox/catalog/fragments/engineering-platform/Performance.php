<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform;

/**
 * @phpstan-type Sample array{duration_ms:float,rss_kib:int}
 * @phpstan-type ScenarioResult array{samples:list<Sample>,median_ms:float,p95_ms:float,maximum_rss_kib:int,noise_ratio:float}
 */
final class Performance
{
    public function __construct(private readonly string $root) {}

    /** @return array<string,mixed> */
    public function smoke(): array
    {
        $scenarios = [];
        foreach ($this->commands() as $name => $command) {
            $scenarios[$name] = $this->measure($command, 1, 3);
        }
        return [
            'format' => 'duo-performance-result/v1',
            'kind' => 'smoke',
            'state' => 'pass',
            'authority' => 'non_authorizing_harness_health',
            'candidate_sha' => $this->candidateSha(),
            'profile_sha256' => $this->digest(__DIR__ . '/performance-profile.json'),
            'environment' => $this->environment(null),
            'scenarios' => $scenarios,
            'budgets_enforced' => false,
            'cache_policy' => 'disabled',
        ];
    }

    /** @return array<string,mixed> */
    public function budget(): array
    {
        $profile = $this->profile();
        $environmentFailure = $this->controlledEnvironmentFailure($profile);
        if ($environmentFailure !== null) {
            return [
                'format' => 'duo-performance-result/v1',
                'kind' => 'budget',
                'state' => 'infra_error',
                'authority' => 'gate_result',
                'candidate_sha' => $this->candidateSha(),
                'profile_sha256' => $this->digest(__DIR__ . '/performance-profile.json'),
                'environment' => $this->environment($profile),
                'attempts' => [],
                'message' => $environmentFailure,
                'cache_policy' => 'disabled',
            ];
        }
        $warmups = $profile['warmups'];
        $samples = $profile['samples'];
        $maximumNoise = $profile['maximum_noise_ratio'];
        $maximumBaselineDrift = $profile['maximum_baseline_drift_ratio'];
        if (!is_int($warmups) || !is_int($samples) || !is_float($maximumNoise)
            || !is_float($maximumBaselineDrift)) {
            throw new CatalogException('performance profile sampling fields are malformed');
        }
        $ratified = $this->baselineRecord($profile);
        $attempts = [];
        $finalState = 'infra_error';
        $finalMessage = 'performance run was not attempted';
        $profileBudgets = $profile['budgets'] ?? null;
        if (!is_array($profileBudgets)) {
            throw new CatalogException('performance profile budgets are malformed');
        }
        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            $measurement = (new PerformanceHarness($this->root))->run($profile, $warmups, $samples);
            $scenarios = $measurement['scenarios'];
            $ratifiedScenarios = $ratified['scenarios'] ?? null;
            if (!is_array($ratifiedScenarios)) {
                throw new CatalogException('ratified performance scenarios are malformed');
            }
            $noisy = [];
            $drifted = [];
            $failures = [];
            foreach ($scenarios as $name => $paired) {
                $ratifiedScenario = $ratifiedScenarios[$name] ?? null;
                if (!is_array($ratifiedScenario)) {
                    throw new CatalogException('controlled performance scenario result is malformed');
                }
                $baselineResult = $paired['baseline'];
                $candidateResult = $paired['candidate'];
                if ($baselineResult['noise_ratio'] > $maximumNoise
                    || $candidateResult['noise_ratio'] > $maximumNoise) {
                    $noisy[] = $name;
                }
                $budget = $profileBudgets[$name] ?? null;
                if (!$this->withinBaselineDrift($baselineResult, $ratifiedScenario, $maximumBaselineDrift)) {
                    $drifted[] = $name;
                }
                if (!is_array($budget) || !$this->withinBudget($candidateResult, $baselineResult, $budget)) {
                    $failures[] = $name;
                }
            }
            $reason = $noisy !== []
                ? 'noise_above_profile_limit'
                : ($drifted !== [] ? 'ratified_baseline_drift' : null);
            $infraError = $noisy !== [] || $drifted !== [];
            $attempts[] = [
                'attempt' => $attempt,
                'state' => $infraError ? 'infra_error' : ($failures === [] ? 'pass' : 'fail'),
                'retry_reason' => $reason,
                'noisy_scenarios' => $noisy,
                'baseline_drift_scenarios' => $drifted,
                'budget_failures' => $failures,
                'environment' => $measurement['environment'],
                'scenarios' => $scenarios,
            ];
            if ($infraError && $attempt === 1) {
                continue;
            }
            $finalState = $infraError ? 'infra_error' : ($failures === [] ? 'pass' : 'fail');
            $finalMessage = $infraError
                ? 'performance measurements exceed the ratified noise/drift limits'
                : ($failures === [] ? 'ratified performance budgets passed' : 'one or more performance budgets failed');
            break;
        }
        return [
            'format' => 'duo-performance-result/v1',
            'kind' => 'budget',
            'state' => $finalState,
            'authority' => 'gate_result',
            'candidate_sha' => $this->candidateSha(),
            'profile_sha256' => $this->digest(__DIR__ . '/performance-profile.json'),
            'baseline_record_sha256' => $this->digest(__DIR__ . '/performance-baseline.json'),
            'environment' => $this->environment($profile),
            'attempts' => $attempts,
            'message' => $finalMessage,
            'cache_policy' => 'disabled',
        ];
    }

    /** @return array<string,mixed> */
    public function baselineProposal(): array
    {
        $profile = $this->profile();
        $environmentFailure = $this->controlledEnvironmentFailure($profile);
        if ($environmentFailure !== null) {
            return [
                'format' => 'duo-performance-baseline-proposal/v1',
                'state' => 'infra_error',
                'authority' => 'non_authorizing_review_input',
                'candidate_sha' => $this->candidateSha(),
                'profile_sha256' => $this->digest(__DIR__ . '/performance-profile.json'),
                'message' => $environmentFailure,
            ];
        }
        $warmups = $profile['warmups'] ?? null;
        $samples = $profile['samples'] ?? null;
        if (!is_int($warmups) || !is_int($samples)) {
            throw new CatalogException('performance profile sampling fields are malformed');
        }
        $measurement = (new PerformanceHarness($this->root))->run($profile, $warmups, $samples);
        return [
            'format' => 'duo-performance-baseline-proposal/v1',
            'state' => 'pass',
            'authority' => 'non_authorizing_review_input',
            'candidate_sha' => $this->candidateSha(),
            'profile_sha256' => $this->digest(__DIR__ . '/performance-profile.json'),
            'environment' => $measurement['environment'],
            'scenarios' => $measurement['scenarios'],
            'message' => 'reviewed measurements must be committed separately before they become a ratified baseline',
        ];
    }

    /** @return array<string,list<string>> */
    private function commands(): array
    {
        $agent = $this->root . '/agent/duo.php';
        return [
            'ordinary-wordpress-disabled' => [PHP_BINARY, '-r', 'echo "ok";'],
            'ordinary-wordpress-enabled' => [PHP_BINARY, '-r', 'define("ABSPATH",__DIR__."/");require $argv[1];echo "ok";', $agent],
            'journal-enabled' => [
                PHP_BINARY,
                '-r',
                'function get_option(){return null;}function add_filter(){}function add_action(){}'
                    . 'define("ABSPATH",__DIR__."/");define("DUO_JOURNAL",true);require $argv[1];echo "ok";',
                $agent,
            ],
            'agent-command-cold' => $this->agentCommand($agent),
            'agent-command-warm' => $this->agentCommand($agent),
            'host-cli-cold' => [PHP_BINARY, $this->root . '/cli/duo', '--help'],
            'host-cli-warm' => [PHP_BINARY, $this->root . '/cli/duo', '--help'],
            'closure-hashing' => [
                PHP_BINARY,
                '-r',
                '$files=array_merge(glob($argv[1]."/agent/src/*.php")?:[],glob($argv[1]."/cli/src/*.php")?:[]);'
                    . 'sort($files);$h=hash_init("sha256");foreach($files as $f){hash_update_file($h,$f);}echo hash_final($h);',
                $this->root,
            ],
            'targeted-test-feedback' => [PHP_BINARY, '-l', $this->root . '/agent/duo-loader.php'],
            'complete-test-feedback' => [PHP_BINARY, $this->root . '/sandbox/catalog/fragments/engineering-platform/catalog.php', 'validate'],
        ];
    }

    /** @return list<string> */
    private function agentCommand(string $agent): array
    {
        return [
            PHP_BINARY,
            '-r',
            'class WP_CLI{public static function add_command(){}}define("ABSPATH",__DIR__."/");'
                . 'define("WP_CLI",true);require $argv[1];echo "ok";',
            $agent,
        ];
    }

    /**
     * @param list<string> $command
     * @return ScenarioResult
     */
    private function measure(array $command, int $warmups, int $samples): array
    {
        if ($samples < 1 || $warmups < 0) {
            throw new CatalogException('performance sample counts are invalid');
        }
        for ($index = 0; $index < $warmups; ++$index) {
            $this->sample($command);
        }
        $results = [];
        for ($index = 0; $index < $samples; ++$index) {
            $results[] = $this->sample($command);
        }
        $durations = [];
        $rss = [];
        foreach ($results as $result) {
            $durations[] = $result['duration_ms'];
            $rss[] = $result['rss_kib'];
        }
        sort($durations, SORT_NUMERIC);
        $median = $this->percentile($durations, 0.5);
        $minimum = min($durations);
        $maximum = max($durations);
        return [
            'samples' => $results,
            'median_ms' => $median,
            'p95_ms' => $this->percentile($durations, 0.95),
            'maximum_rss_kib' => max($rss),
            'noise_ratio' => $median > 0 ? round(($maximum - $minimum) / $median, 6) : 0.0,
        ];
    }

    /**
     * @param list<string> $command
     * @return Sample
     */
    private function sample(array $command): array
    {
        $started = hrtime(true);
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            ['PATH' => (string) getenv('PATH'), 'LC_ALL' => 'C', 'LANG' => 'C', 'TZ' => 'UTC'],
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new CatalogException('cannot start performance scenario');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stderr = '';
        $maximumRss = 0;
        $lastStatus = proc_get_status($process);
        while ($lastStatus['running']) {
            stream_get_contents($pipes[1]);
            $chunk = stream_get_contents($pipes[2]);
            if (is_string($chunk)) {
                $stderr .= $chunk;
            }
            $maximumRss = max($maximumRss, $this->residentSetSize((int) $lastStatus['pid']));
            usleep(1000);
            $lastStatus = proc_get_status($process);
        }
        stream_get_contents($pipes[1]);
        $tail = stream_get_contents($pipes[2]);
        if (is_string($tail)) {
            $stderr .= $tail;
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit === -1 && $lastStatus['exitcode'] >= 0) {
            $exit = $lastStatus['exitcode'];
        }
        $duration = round((hrtime(true) - $started) / 1_000_000, 3);
        if ($exit !== 0) {
            throw new CatalogException('performance scenario failed: ' . trim((string) $stderr));
        }
        return ['duration_ms' => $duration, 'rss_kib' => $maximumRss];
    }

    private function residentSetSize(int $pid): int
    {
        if ($pid < 1) {
            return 0;
        }
        $status = @file_get_contents("/proc/$pid/status");
        if (!is_string($status) || preg_match('/^VmRSS:\s+([0-9]+)\s+kB$/m', $status, $match) !== 1) {
            return 0;
        }
        return (int) $match[1];
    }

    /** @param list<float> $values */
    private function percentile(array $values, float $percentile): float
    {
        $index = max(0, (int) ceil(count($values) * $percentile) - 1);
        return $values[$index];
    }

    /**
     * @param ScenarioResult $candidate
     * @param ScenarioResult $baseline
     * @param array<mixed,mixed> $budget
     */
    private function withinBudget(array $candidate, array $baseline, array $budget): bool
    {
        foreach (['median_ms', 'p95_ms', 'rss_kib', 'maximum_regression_ratio'] as $key) {
            if (!is_int($budget[$key] ?? null) && !is_float($budget[$key] ?? null)) {
                return false;
            }
        }
        $regressionRatio = (float) $budget['maximum_regression_ratio'];
        return $candidate['median_ms'] <= (float) $budget['median_ms']
            && $candidate['median_ms'] <= $baseline['median_ms'] * (1.0 + $regressionRatio)
            && $candidate['p95_ms'] <= (float) $budget['p95_ms']
            && $candidate['p95_ms'] <= $baseline['p95_ms'] * (1.0 + $regressionRatio)
            && $candidate['maximum_rss_kib'] <= (int) $budget['rss_kib'];
    }

    /**
     * @param ScenarioResult $live
     * @param array<mixed,mixed> $ratified
     */
    private function withinBaselineDrift(array $live, array $ratified, float $maximumDrift): bool
    {
        foreach (['median_ms', 'p95_ms', 'maximum_rss_kib'] as $key) {
            if (!is_int($ratified[$key] ?? null) && !is_float($ratified[$key] ?? null)) {
                return false;
            }
            $expected = (float) $ratified[$key];
            $actual = (float) $live[$key];
            if ($expected <= 0.0 || abs($actual - $expected) / $expected > $maximumDrift) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,mixed> */
    private function profile(): array
    {
        $bytes = file_get_contents(__DIR__ . '/performance-profile.json');
        if (!is_string($bytes)) {
            throw new CatalogException('cannot read performance profile');
        }
        try {
            $profile = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new CatalogException('invalid performance profile: ' . $exception->getMessage());
        }
        if (!is_array($profile) || array_is_list($profile)
            || ($profile['format'] ?? null) !== 'duo-performance-profile/v1'
            || !is_string($profile['id'] ?? null)
            || !is_string($profile['runner_class'] ?? null)
            || !is_array($profile['images'] ?? null)
            || !is_array($profile['resources'] ?? null)
            || !is_array($profile['baseline'] ?? null)
            || !is_array($profile['budgets'] ?? null)) {
            throw new CatalogException('performance profile is malformed');
        }
        $normalized = [];
        foreach ($profile as $key => $value) {
            if (!is_string($key)) {
                throw new CatalogException('performance profile must be an object');
            }
            $normalized[$key] = $value;
        }
        return $normalized;
    }

    /** @param array<string,mixed> $profile */
    private function controlledEnvironmentFailure(array $profile): ?string
    {
        $images = $profile['images'];
        $resources = $profile['resources'];
        if (!is_array($images) || !is_array($resources)) {
            return 'ratified performance profile is malformed';
        }
        $cpuCount = $resources['cpu_count'] ?? null;
        $memoryMib = $resources['memory_mib'] ?? null;
        if (!is_int($cpuCount) || !is_int($memoryMib)) {
            return 'ratified performance resource profile is malformed';
        }
        $expected = [
            'DUO_PERF_RUNNER_PROFILE' => $profile['runner_class'] ?? null,
            'DUO_PERF_RESOURCE_CLASS' => sprintf('%dcpu-%dmib', $cpuCount, $memoryMib),
        ];
        foreach ($expected as $name => $value) {
            if (!is_string($value) || getenv($name) !== $value) {
                return "controlled performance prerequisite mismatch: $name";
            }
        }
        if (php_uname('s') !== 'Linux' || php_uname('m') !== 'aarch64') {
            return 'controlled performance prerequisite mismatch: Linux aarch64';
        }
        if (!$this->commandAvailable('docker')) {
            return 'controlled performance prerequisite mismatch: docker';
        }
        return null;
    }

    /**
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    private function baselineRecord(array $profile): array
    {
        $record = $this->readJson(__DIR__ . '/performance-baseline.json');
        $baseline = $profile['baseline'] ?? null;
        if (($record['format'] ?? null) !== 'duo-performance-baseline/v1'
            || ($record['profile_id'] ?? null) !== ($profile['id'] ?? null)
            || !is_array($baseline)
            || ($record['baseline_sha'] ?? null) !== ($baseline['commit'] ?? null)
            || !is_array($record['scenarios'] ?? null)) {
            throw new CatalogException('ratified performance baseline disagrees with the active profile');
        }
        return $record;
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new CatalogException("cannot read performance input: $path");
        }
        try {
            $value = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new CatalogException('invalid performance input: ' . $exception->getMessage());
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new CatalogException('performance input must be an object');
        }
        $normalized = [];
        foreach ($value as $key => $entry) {
            if (!is_string($key)) {
                throw new CatalogException('performance input has a non-string key');
            }
            $normalized[$key] = $entry;
        }
        return $normalized;
    }

    private function commandAvailable(string $command): bool
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory !== '' && is_file($directory . '/' . $command) && is_executable($directory . '/' . $command)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed>|null $profile
     * @return array<string,mixed>
     */
    private function environment(?array $profile): array
    {
        $cpu = 'unknown';
        $cpuInfo = @file_get_contents('/proc/cpuinfo');
        if (is_string($cpuInfo) && preg_match('/^model name\s*:\s*(.+)$/m', $cpuInfo, $match) === 1) {
            $cpu = trim($match[1]);
        }
        $extensions = get_loaded_extensions();
        sort($extensions, SORT_STRING);
        return [
            'cpu_model' => $cpu,
            'kernel' => php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m'),
            'php_version' => PHP_VERSION,
            'php_binary_sha256' => $this->digest(PHP_BINARY),
            'extensions' => $extensions,
            'opcache_enabled' => ini_get('opcache.enable') === '1',
            'opcache_cli_enabled' => ini_get('opcache.enable_cli') === '1',
            'memory_limit' => ini_get('memory_limit'),
            'runner_profile' => $profile['runner_class'] ?? null,
            'wordpress_image' => is_array($profile['images'] ?? null) ? ($profile['images']['wordpress'] ?? null) : null,
            'database_image' => is_array($profile['images'] ?? null) ? ($profile['images']['database'] ?? null) : null,
            'resource_profile' => $profile['resources'] ?? null,
        ];
    }

    private function digest(string $path): string
    {
        $digest = hash_file('sha256', $path);
        if (!is_string($digest)) {
            throw new CatalogException("cannot digest performance input: $path");
        }
        return 'sha256:' . $digest;
    }

    private function candidateSha(): string
    {
        $process = proc_open(
            ['git', 'rev-parse', 'HEAD'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            ['PATH' => (string) getenv('PATH'), 'LC_ALL' => 'C'],
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new CatalogException('cannot resolve performance candidate');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($stdout) || preg_match('/^[a-f0-9]{40}\s*$/D', $stdout) !== 1) {
            throw new CatalogException('performance candidate is not a full SHA');
        }
        return trim($stdout);
    }
}

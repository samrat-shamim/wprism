<?php
declare(strict_types=1);

namespace WPrismTest;

/** A retained Docker inspect binds abrupt termination to one owned command, not merely status 137. */
final class ContainerProcessEvidence {
    public static function assertKilled(array $record, string $name, string $project, string $service,
        array $command, array $environment, array $window): void {
        foreach ([$name, $project, $service] as $identity) self::check(preg_match('/^[a-z][a-z0-9-]+$/D', $identity) === 1, 'exact container identity');
        self::check(array_is_list($command) && $command !== [] && $command[0] !== ''
            && count(array_filter($command, 'is_string')) === count($command), 'exact command argv');
        self::check(!array_is_list($environment) && $environment !== [], 'explicit fault environment');
        self::check(array_keys($window) === ['before', 'after'] && is_int($window['before']) && is_int($window['after'])
            && $window['before'] <= $window['after'], 'bounded invocation window');
        self::check(is_string($record['Id'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $record['Id']) === 1
            && ($record['Name'] ?? null) === '/' . $name && ($record['RestartCount'] ?? null) === 0, 'fresh named container');
        $config = $record['Config'] ?? [];
        self::check(($config['Cmd'] ?? null) === $command
            && ($config['Labels']['com.docker.compose.project'] ?? null) === $project
            && ($config['Labels']['com.docker.compose.service'] ?? null) === $service
            && ($config['Labels']['com.docker.compose.oneoff'] ?? null) === 'True', 'owned Compose invocation');
        $env = $config['Env'] ?? null;
        self::check(is_array($env) && array_is_list($env) && count(array_filter($env, 'is_string')) === count($env), 'complete container environment');
        foreach ($environment as $key => $value) {
            self::check(is_string($key) && preg_match('/^[A-Z][A-Z0-9_]+$/D', $key) === 1 && is_string($value), 'literal fault environment binding');
            $matches = array_values(array_filter($env, static fn(string $entry): bool => str_starts_with($entry, $key . '=')));
            self::check($matches === [$key . '=' . $value], 'exact unique fault environment');
        }
        $state = $record['State'] ?? [];
        self::check(($state['Status'] ?? null) === 'exited' && ($state['ExitCode'] ?? null) === 137
            && ($state['OOMKilled'] ?? null) === false && ($state['Running'] ?? null) === false
            && ($state['Restarting'] ?? null) === false && ($state['Dead'] ?? null) === false
            && ($state['Error'] ?? null) === '' && ($state['Pid'] ?? null) === 0, 'stopped process without OOM or runtime failure');
        $times = [];
        foreach (['StartedAt', 'FinishedAt'] as $field) {
            $value = $state[$field] ?? null;
            self::check(is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?Z$/D', $value) === 1, 'exact process timestamp');
            $time = strtotime($value);
            self::check($time !== false && gmdate('Y-m-d\\TH:i:s\\Z', $time) === preg_replace('/\\.\\d+Z$/D', 'Z', $value)
                && $time >= $window['before'] && $time <= $window['after'], 'process belongs to this invocation');
            $times[] = $time;
        }
        self::check($times[0] <= $times[1], 'ordered process lifetime');
    }

    private static function check(bool $condition, string $reason): void {
        if (!$condition) throw new \RuntimeException('container process evidence: ' . $reason);
    }
}

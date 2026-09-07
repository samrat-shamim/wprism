<?php
declare(strict_types=1);

namespace WPrism\Providers;

use WPrism\ManifestProviderRuntime;

/** Minimal semantic provider used by the engine fresh-process protocol suite. */
final class FixtureFresh extends ManifestProviderRuntime {
    public static string $state = 'before';

    private const DURABLE_FORMAT = 'wprism-provider-process-fixture/v1';

    /** @return array{before:array{value:string},after:array{value:string},verified:true} */
    protected function invoke_execute(array $args): array {
        $value = $args['value'] ?? null;
        if (!is_string($value) || !in_array($value, ['after', 'bounded', 'dml', 'large', 'private-failure', 'recurse'], true)) {
            throw new \RuntimeException('fixture received unexpected args');
        }
        if ($value === 'private-failure') {
            throw new \RuntimeException('fixture operation refused', 0,
                new \RuntimeException('private-provider-cause-canary'));
        }
        if ($value === 'recurse') {
            return $this->invoke('execute', $args);
        }
        $durablePath = self::durablePath();
        $before = $durablePath === null
            ? self::$state
            : self::readDurableRecord($durablePath)['value'];
        $after = $value === 'large' ? str_repeat('x', 530000) : $value;
        if ($value === 'bounded') $after = str_repeat('a', 997);
        if ($durablePath !== null) {
            self::writeDurableRecord($durablePath, $after);
            // The observer below accepts a durable record only from a clean
            // boot. A same-process invoke/observe shortcut therefore cannot
            // make this fixture pass merely because static state survived.
            self::$state = 'mutation-process-poison';
        } else {
            self::$state = $after;
        }
        return [
            'before' => ['value' => $before],
            'after' => ['value' => $after],
            'verified' => true,
        ];
    }

    /** @return array{value:string} */
    protected function reconcile_execute(array $args): array {
        return ['value' => self::$state];
    }

    /** @return array{value:string} */
    protected function project_execute(array $value): array {
        $projected = $value['value'] ?? null;
        if (!is_string($projected)) {
            throw new \RuntimeException('fixture projection is malformed');
        }
        return ['value' => $projected];
    }

    /** @return array{value:string} */
    protected function observe_fresh_postimage_execute(array $args): array {
        $durablePath = self::durablePath();
        if ($durablePath !== null) {
            $record = self::readDurableRecord($durablePath);
            $pid = getmypid();
            if (self::$state !== 'before'
                || !is_int($pid)
                || $pid < 2
                || $record['mutation_pid'] < 2
                || $record['mutation_pid'] === $pid) {
                throw new \RuntimeException('fixture observer did not start in a distinct clean process');
            }
            if (($args['value'] ?? null) === 'dml') {
                global $wpdb;
                if (!is_object($wpdb) || !is_string($wpdb->options ?? null)) {
                    throw new \RuntimeException('fixture DML observer has no database transport');
                }
                $wpdb->query(
                    "UPDATE `{$wpdb->options}` SET `option_value` = 'escaped' WHERE `option_name` = 'fixture'"
                );
            }
            return ['value' => $record['value']];
        }
        return ['value' => self::$state];
    }

    /** @return array{value:string} */
    protected function project_fresh_postimage_execute(array $value): array {
        return $this->project_execute($value);
    }

    private static function durablePath(): ?string {
        if (!defined('WPRISM_PROVIDER_PROCESS_FIXTURE_STATE')) {
            return null;
        }
        $path = constant('WPRISM_PROVIDER_PROCESS_FIXTURE_STATE');
        if (!is_string($path) || $path === '' || realpath($path) !== $path) {
            throw new \RuntimeException('fixture durable state path is invalid');
        }
        return $path;
    }

    /** @return array{format:string,mutation_pid:int,value:string} */
    private static function readDurableRecord(string $path): array {
        $handle = fopen($path, 'rb');
        if (!is_resource($handle) || !flock($handle, LOCK_SH)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException('fixture cannot lock durable state for reading');
        }
        $bytes = stream_get_contents($handle, 1048577);
        flock($handle, LOCK_UN);
        fclose($handle);
        try {
            $record = is_string($bytes)
                ? json_decode($bytes, true, 8, JSON_THROW_ON_ERROR)
                : null;
        } catch (\Throwable $failure) {
            throw new \RuntimeException('fixture durable state is malformed', 0, $failure);
        }
        if (!is_array($record)
            || array_keys($record) !== ['format', 'mutation_pid', 'value']
            || $record['format'] !== self::DURABLE_FORMAT
            || !is_int($record['mutation_pid'])
            || $record['mutation_pid'] < 0
            || !is_string($record['value'])) {
            throw new \RuntimeException('fixture durable state has an invalid envelope');
        }
        return $record;
    }

    private static function writeDurableRecord(string $path, string $value): void {
        $pid = getmypid();
        if (!is_int($pid) || $pid < 2 || !function_exists('fsync')) {
            throw new \RuntimeException('fixture has no durable process identity boundary');
        }
        $bytes = json_encode([
            'format' => self::DURABLE_FORMAT,
            'mutation_pid' => $pid,
            'value' => $value,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $handle = fopen($path, 'c+b');
        if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException('fixture cannot lock durable state for writing');
        }
        $written = ftruncate($handle, 0) && rewind($handle)
            ? fwrite($handle, $bytes)
            : false;
        $durable = $written === strlen($bytes) && fflush($handle) && fsync($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        if (!$durable) {
            throw new \RuntimeException('fixture could not durably publish mutation state');
        }
    }
}

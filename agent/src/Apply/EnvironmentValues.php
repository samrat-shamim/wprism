<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Kernel/PostPasswordBinding.php';

/** Target-local intended values for env options and canonical secret bindings. */
final class EnvironmentValues {
    public const FILE = '.wprism-env-values.json';
    public const POST_PASSWORD_PREFIX = PostPasswordBinding::PREFIX;

    public static function postPasswordName(string $uuid): string {
        return PostPasswordBinding::name($uuid);
    }

    public static function postPasswordUuid(string $name): ?string {
        return PostPasswordBinding::uuid($name);
    }

    /** @return array<string,string> */
    public static function read(string $repo): array {
        $path = self::path($repo);
        if (!file_exists($path) && !is_link($path)) {
            return [];
        }
        if (!is_file($path) || is_link($path)) {
            throw new \RuntimeException('wprism: ' . self::FILE . ' must be a regular non-symlink file');
        }
        $mode = fileperms($path);
        if ($mode === false || ($mode & 0077) !== 0) {
            throw new \RuntimeException('wprism: ' . self::FILE . ' must be readable only by its owner (mode 0600)');
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('wprism: cannot read ' . self::FILE);
        }
        try {
            $typed = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
            $values = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('wprism: ' . self::FILE . ' is not valid JSON: ' . $failure->getMessage());
        }
        if (!is_object($typed) || !is_array($values) || array_is_list($values)) {
            throw new \RuntimeException('wprism: ' . self::FILE . ' must be a JSON object of binding name to value');
        }
        foreach ($values as $name => $value) {
            if (!is_string($name) || $name === '' || !is_string($value) || $value === '') {
                throw new \RuntimeException(
                    'wprism: ' . self::FILE . ' must contain only non-empty string binding names and values'
                );
            }
        }
        ksort($values, SORT_STRING);
        return $values;
    }

    public static function set(string $repo, string $name, string $value): void {
        $values = self::read($repo);
        $values[$name] = $value;
        ksort($values, SORT_STRING);
        $path = self::path($repo);
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new \RuntimeException('wprism: cannot stage ' . self::FILE);
        }
        try {
            if (!chmod($temporary, 0600)) {
                throw new \RuntimeException('wprism: cannot restrict staged ' . self::FILE . ' to mode 0600');
            }
            $bytes = Canon::encode($values);
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($handle, substr($bytes, $offset));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException('wprism: cannot write staged ' . self::FILE);
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new \RuntimeException('wprism: cannot flush staged ' . self::FILE);
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new \RuntimeException('wprism: cannot sync staged ' . self::FILE);
            }
            fclose($handle);
            $handle = null;
            if (!rename($temporary, $path)) {
                throw new \RuntimeException('wprism: cannot publish ' . self::FILE);
            }
            if (!chmod($path, 0600)) {
                throw new \RuntimeException('wprism: cannot restrict published ' . self::FILE . ' to mode 0600');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (file_exists($temporary) || is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private static function path(string $repo): string {
        $root = realpath($repo);
        if ($root === false || !is_dir($root)) {
            throw new \RuntimeException('wprism: environment value repository root is not a directory');
        }
        return $root . '/' . self::FILE;
    }
}

<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/** Closed lowercase subset of Docker/OCI repository digest references. */
final class ImmutableOciReference {
    private const MAX_NAME_BYTES = 255;
    private const MAX_COMPONENTS = 32;

    public static function valid(mixed $value): bool {
        if (!is_string($value)
            || preg_match('/\A(.+)@sha256:([a-f0-9]{64})\z/D', $value, $match) !== 1) {
            return false;
        }
        $name = $match[1];
        if (strlen($name) > self::MAX_NAME_BYTES) {
            return false;
        }

        $lastSlash = strrpos($name, '/');
        $lastColon = strrpos($name, ':');
        if ($lastColon !== false && ($lastSlash === false || $lastColon > $lastSlash)) {
            $tag = substr($name, $lastColon + 1);
            if (preg_match('/\A[a-z0-9_][a-z0-9_.-]{0,127}\z/D', $tag) !== 1) {
                return false;
            }
            $name = substr($name, 0, $lastColon);
        }

        $components = explode('/', $name);
        if ($components === [] || count($components) > self::MAX_COMPONENTS
            || in_array('', $components, true)) {
            return false;
        }
        if (count($components) > 1 && str_contains($components[0], ':')) {
            if (substr_count($components[0], ':') !== 1) {
                return false;
            }
            [$host, $port] = explode(':', $components[0], 2);
            if (preg_match(
                '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*\z/D',
                $host
            ) !== 1
                || preg_match('/\A(?:[1-9][0-9]{0,4})\z/D', $port) !== 1
                || (int) $port > 65535) {
                return false;
            }
            array_shift($components);
        }

        foreach ($components as $component) {
            if (preg_match('/\A[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*\z/D', $component) !== 1) {
                return false;
            }
        }
        return true;
    }

    public static function digest(string $value): string {
        return substr($value, (int) strrpos($value, '@') + 1);
    }
}

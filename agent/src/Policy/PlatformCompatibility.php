<?php
declare(strict_types=1);

namespace Duo;

require_once __DIR__ . '/../Kernel/CommandRefusal.php';

/**
 * Fail-closed runtime gate for the one shipped platform boundary.
 *
 * The host-side doctor has always compared PHP and the database against
 * docs/compatibility-baseline.json, but a direct `wp duo` mutation bypasses
 * that host command. Policy load is the first common product boundary before
 * repository reads, identity allocation, locks, or authored-state mutation,
 * so the agent independently checks the identical declaration from
 * manifests/capabilities/platform.json here. WordPress is exact rather than
 * a fabricated range: 7.0.3 is the one image exercised by the live estate.
 */
final class PlatformCompatibility {
    /** @return array{php:string,database:array{engine:string,version:string},wordpress:string,site_mode:string} */
    public static function current_facts(): array {
        if (!function_exists('get_bloginfo')) {
            throw self::probe_refusal('wordpress');
        }
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            throw self::probe_refusal('database');
        }

        $wpdb->last_error = '';
        try {
            $server = $wpdb->get_var('SELECT VERSION()');
        } catch (\Throwable $failure) {
            throw self::probe_refusal('database', $failure);
        }
        if (!is_string($server) || $server === '' || (string) ($wpdb->last_error ?? '') !== '') {
            throw self::probe_refusal('database');
        }
        if (preg_match('/^\s*(\d+(?:\.\d+){1,3})/', $server, $match) !== 1) {
            throw self::probe_refusal('database');
        }

        $wordpress = (string) get_bloginfo('version');
        if ($wordpress === '') {
            throw self::probe_refusal('wordpress');
        }
        return [
            'php' => PHP_VERSION,
            'database' => [
                'engine' => stripos($server, 'mariadb') !== false ? 'MariaDB' : 'MySQL',
                'version' => $match[1],
            ],
            'wordpress' => $wordpress,
            'site_mode' => function_exists('is_multisite') && is_multisite() ? 'multisite' : 'single-site',
        ];
    }

    /**
     * @param array<string,mixed> $platform
     * @param ?array{php:string,database:array{engine:string,version:string},wordpress:string,site_mode:string} $facts
     */
    public static function assert_supported(array $platform, ?array $facts = null): void {
        self::assert_boundary_shape($platform);
        $facts ??= self::current_facts();
        self::assert_facts_shape($facts);

        $compatibility = $platform['compatibility'];
        $diagnostics = [];
        $siteMode = (string) $facts['site_mode'];
        if ($siteMode !== (string) $platform['site_mode']) {
            $diagnostics[] = self::diagnostic(
                'platform_site_mode_unsupported',
                'site_mode',
                $siteMode,
                (string) $platform['site_mode'],
                'this repository format does not support the observed WordPress site topology'
            );
        }

        $php = (string) $facts['php'];
        $phpRange = $compatibility['php'];
        if (!self::inside_range($php, $phpRange)) {
            $diagnostics[] = self::diagnostic(
                'platform_php_version_unsupported',
                'php',
                $php,
                self::range_label($phpRange),
                'the loaded PHP runtime is outside the exercised platform range'
            );
        }

        $database = $facts['database'];
        $databaseBoundary = $compatibility['database'];
        if ($database['engine'] !== $databaseBoundary['engine']) {
            $diagnostics[] = self::diagnostic(
                'platform_database_engine_unsupported',
                'database.engine',
                $database['engine'],
                $databaseBoundary['engine'],
                'the connected database engine is outside the exercised platform contract'
            );
        } elseif (!self::inside_range($database['version'], $databaseBoundary)) {
            $diagnostics[] = self::diagnostic(
                'platform_database_version_unsupported',
                'database.version',
                $database['version'],
                self::range_label($databaseBoundary),
                'the connected database version is outside the exercised platform range'
            );
        }

        $wordpress = (string) $facts['wordpress'];
        $verifiedWordPress = (string) $compatibility['wordpress']['last_verified'];
        if (!hash_equals($verifiedWordPress, $wordpress)) {
            $diagnostics[] = self::diagnostic(
                'platform_wordpress_version_unsupported',
                'wordpress',
                $wordpress,
                $verifiedWordPress,
                'the loaded WordPress core differs from the exact version exercised by this agent'
            );
        }

        if ($diagnostics === []) {
            return;
        }
        throw new CommandRefusalException(
            'platform_unsupported',
            'this target is outside the exercised Duo platform boundary; policy load and mutation were refused',
            'move the target onto every required platform value, then retry from an unchanged repository revision',
            $diagnostics,
            'duo: platform compatibility refused before policy load — ' . implode('; ', array_map(
                static fn(array $row): string => $row['axis'] . ' observed ' . $row['observed']
                    . ', requires ' . $row['required'],
                $diagnostics
            ))
        );
    }

    /** @param array<string,mixed> $platform */
    private static function assert_boundary_shape(array $platform): void {
        $compatibility = $platform['compatibility'] ?? null;
        $php = is_array($compatibility) ? ($compatibility['php'] ?? null) : null;
        $database = is_array($compatibility) ? ($compatibility['database'] ?? null) : null;
        $wordpress = is_array($compatibility) ? ($compatibility['wordpress'] ?? null) : null;
        if (($platform['site_mode'] ?? null) !== 'single-site'
            || !is_array($compatibility) || array_is_list($compatibility)
            || !is_array($php) || array_is_list($php)
            || !is_array($database) || array_is_list($database)
            || !is_array($wordpress) || array_is_list($wordpress)
            || !self::valid_range($php)
            || !self::valid_range($database)
            || !is_string($database['engine'] ?? null) || $database['engine'] === ''
            || !self::version((string) ($wordpress['last_verified'] ?? ''))) {
            throw new CommandRefusalException(
                'platform_boundary_invalid',
                'the shipped platform compatibility declaration is malformed or unsupported',
                'restore the reviewed manifests/capabilities/platform.json bytes before another command',
                [[
                    'code' => 'platform_boundary_invalid',
                    'message' => 'the platform boundary cannot be evaluated safely',
                    'remediation' => 'restore the reviewed platform declaration before retrying',
                ]],
                'duo: platform compatibility declaration is malformed; refusing before policy load'
            );
        }
    }

    /** @param array<string,mixed> $facts */
    private static function assert_facts_shape(array $facts): void {
        $database = $facts['database'] ?? null;
        if (!is_string($facts['php'] ?? null) || $facts['php'] === ''
            || !is_array($database)
            || !in_array($database['engine'] ?? null, ['MariaDB', 'MySQL'], true)
            || !is_string($database['version'] ?? null) || !self::version($database['version'])
            || !is_string($facts['wordpress'] ?? null) || $facts['wordpress'] === ''
            || !in_array($facts['site_mode'] ?? null, ['single-site', 'multisite'], true)) {
            throw self::probe_refusal('platform');
        }
    }

    /** @param array<string,mixed> $range */
    private static function valid_range(array $range): bool {
        $min = $range['min'] ?? null;
        $max = $range['max'] ?? null;
        return is_string($min) && is_string($max)
            && self::version($min) && self::version($max)
            && version_compare($min, $max, '<');
    }

    /** @param array<string,mixed> $range */
    private static function inside_range(string $observed, array $range): bool {
        return self::version($observed)
            && version_compare($observed, (string) $range['min'], '>=')
            && version_compare($observed, (string) $range['max'], '<');
    }

    private static function version(string $version): bool {
        return preg_match('/^\d+(?:\.\d+){1,3}$/D', $version) === 1;
    }

    /** @param array<string,mixed> $range */
    private static function range_label(array $range): string {
        return '>=' . $range['min'] . ' <' . $range['max'];
    }

    /** @return array{code:string,axis:string,observed:string,required:string,message:string,remediation:string} */
    private static function diagnostic(
        string $code,
        string $axis,
        string $observed,
        string $required,
        string $message
    ): array {
        return [
            'code' => $code,
            'axis' => $axis,
            'observed' => $observed,
            'required' => $required,
            'message' => $message,
            'remediation' => 'use the required platform value before another Duo mutation',
        ];
    }

    private static function probe_refusal(string $axis, ?\Throwable $previous = null): CommandRefusalException {
        return new CommandRefusalException(
            'platform_probe_unavailable',
            'Duo could not read every platform fact required before policy load',
            'restore the WordPress and database runtime, then retry without changing the repository revision',
            [[
                'code' => 'platform_probe_unavailable',
                'axis' => $axis,
                'message' => 'a required platform fact could not be read safely',
                'remediation' => 'restore the target runtime and repeat the platform preflight',
            ]],
            "duo: platform compatibility could not read the $axis fact; refusing before policy load",
            $previous
        );
    }
}

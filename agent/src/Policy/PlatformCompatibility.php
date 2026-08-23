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
 * manifests/capabilities/platform.json here. WordPress is a bounded range
 * narrowed to the exercised series rather than a fabricated open range:
 * acceptance is inside [min, max) AND the observed MAJOR.MINOR present in
 * `verified`, whose values name the exact patch each series was proven on.
 * The series map exists because WordPress is not semver — a bare range would
 * claim a minor line nobody ran, which is exactly the "unproven behavior
 * hidden behind a broad compatibility claim" DESIGN.md's vision invariant
 * forbids — while patch-level generalization inside a proven series is the
 * same basis PHP 8.3.x and MariaDB 11.x are already claimed on in the same
 * declaration: one measured runtime per line.
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
        $wordpressBoundary = $compatibility['wordpress'];
        if (!self::wordpress_supported($wordpress, $wordpressBoundary)) {
            $diagnostics[] = self::diagnostic(
                'platform_wordpress_version_unsupported',
                'wordpress',
                $wordpress,
                self::wordpress_label($wordpressBoundary),
                'the loaded WordPress core is outside the exercised core matrix'
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
            || !self::valid_wordpress_axis($wordpress)) {
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

    /**
     * The WordPress axis is a range PLUS the exercised-series map that narrows
     * it, and every invariant below is what stops a typo widening the claim
     * past what was actually run. Fail-closed and required, with no path that
     * accepts the pre-matrix two-key {last_verified, note} shape: a boundary
     * that cannot state which series were exercised must refuse
     * (platform_boundary_invalid) rather than fall back to comparing one exact
     * version, per AGENTS.md rule 9.
     *
     * @param array<string,mixed> $wordpress
     */
    private static function valid_wordpress_axis(array $wordpress): bool {
        if (!self::valid_range($wordpress)) {
            return false;
        }
        $verified = $wordpress['verified'] ?? null;
        if (!is_array($verified) || $verified === [] || array_is_list($verified)) {
            return false;
        }
        foreach ($verified as $series => $patch) {
            // A series key that disagrees with its own patch is the failure
            // this check exists for: `{"6.9": "7.0.1"}` would otherwise admit
            // every 6.9.x while naming a 7.0 proof.
            if (preg_match('/^\d+\.\d+$/D', (string) $series) !== 1
                || !is_string($patch) || !self::version($patch)
                || self::series($patch) !== (string) $series
                || !self::inside_range($patch, $wordpress)) {
                return false;
            }
        }
        $lastVerified = $wordpress['last_verified'] ?? null;

        // last_verified stays the newest proven core because other readers
        // bind it as a scalar (cli/src/Assess/SurfaceCatalog.php:851 renders
        // it into every contract's expiry_and_dependencies; the generic pair
        // image is derived from it). Requiring membership in `verified` is
        // what keeps that scalar a value this matrix actually proved.
        if (!is_string($lastVerified) || !self::version($lastVerified)
            || !in_array($lastVerified, array_values($verified), true)) {
            return false;
        }
        foreach ($verified as $patch) {
            // ...and requiring it be the GREATEST proven core is what keeps
            // those readers honest in the other direction: a claim that gains
            // a newer exercised series while last_verified stays behind would
            // silently point the generic pair — and every contract's declared
            // dependency — at a core that is no longer the newest one claimed
            // (sandbox/tests/offline/guards/regress_ecommerce_developer_static.sh
            // asserts the same ordering against the baseline copy).
            if (version_compare((string) $patch, $lastVerified, '>')) {
                return false;
            }
        }

        return true;
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

    /**
     * Inside [min, max) AND the observed MAJOR.MINOR exercised. Both halves
     * are load-bearing: the range alone would admit an unexercised minor line
     * inside it (a hole in the matrix), and the series map alone would admit
     * a future major the range deliberately stops at.
     *
     * @param array<string,mixed> $wordpress
     */
    private static function wordpress_supported(string $observed, array $wordpress): bool {
        return self::inside_range($observed, $wordpress)
            && is_string($wordpress['verified'][self::series($observed)] ?? null);
    }

    /** The MAJOR.MINOR series of a dotted version, or '' when it has none. */
    private static function series(string $version): string {
        return preg_match('/^(\d+\.\d+)/', $version, $match) === 1 ? $match[1] : '';
    }

    /**
     * The `required` value of a WordPress diagnostic, e.g.
     * `>=6.9.0 <7.1.0 exercised 6.9, 7.0`. Ordered by version_compare rather
     * than string sort so a two-digit minor (6.10) cannot render before 6.9
     * and make the label non-deterministic against the claim's own order.
     *
     * @param array<string,mixed> $wordpress
     */
    private static function wordpress_label(array $wordpress): string {
        $series = array_map('strval', array_keys((array) ($wordpress['verified'] ?? [])));
        usort($series, static fn(string $a, string $b): int => version_compare($a, $b));

        return self::range_label($wordpress) . ' exercised ' . implode(', ', $series);
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

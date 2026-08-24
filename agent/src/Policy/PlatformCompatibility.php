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
 * manifests/capabilities/platform.json here.
 *
 * WordPress and PHP are both bounded ranges narrowed to their exercised
 * series rather than fabricated open ranges: acceptance is inside [min, max)
 * AND the observed MAJOR.MINOR present in that axis's `verified` map, whose
 * values name the exact patch each series was proven on
 * (valid_exercised_axis()/exercised_supported()/exercised_label() below are
 * the one implementation both axes share, so the two cannot drift). The
 * series map exists because a bare range claims every minor line inside it,
 * which is exactly the "unproven behavior hidden behind a broad
 * compatibility claim" DESIGN.md's vision invariant forbids: WordPress is not
 * semver at all, and PHP's own range now spans three feature releases
 * (8.3, 8.4, 8.5), each of which is its own migration with its own
 * deprecations — one exercised 8.3 runtime says nothing about 8.5. Patch-level
 * generalization INSIDE a proven series is the narrower claim both axes are
 * actually making: one measured runtime per line.
 *
 * PHP deliberately carries no `last_verified` scalar. WordPress needs one
 * because other readers bind it (see valid_wordpress_axis()); nothing reads a
 * newest-PHP scalar anywhere in this tree, and an invariant with no reader is
 * decoration that can only rot.
 *
 * The database axis is an engine-keyed map instead, because its range is a
 * function of the engine: MariaDB 11.x and MySQL 8.4.x are different lines of
 * different products, and a single {min,max} could only describe one of them.
 * It carries no `verified` series map for the same reason PHP carries no
 * `last_verified`: each engine entry is already one measured runtime per
 * declared line, and the engines map already forces an engine-by-engine
 * decision, so a third series map would prevent no failure. That asymmetry is
 * deliberate, and the axis note in manifests/capabilities/platform.json says
 * so where a reviewer will see it.
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
        // Deliberately kept even though it is unreachable on the product path:
        // Policy::load() calls assert_single_site() FIRST and unconditionally
        // (Policy.php:334-337), so a network is answered by
        // SiteTopology::assert_single_site() before this runs. This gate cannot
        // replace it — assert_supported_platform() returns early without
        // ABSPATH/WPINC/get_bloginfo (Policy.php:290-297), so a topology answer
        // would become conditional on bootstrap shape; current_facts() throws
        // probe_refusal('database') first (:31-41), so a network with an
        // unreadable SELECT VERSION() would be told about its database instead
        // of its topology; and the aggregate reason code is one
        // `platform_unsupported` for four axes, which is exactly the
        // un-actionable answer the typed topology refusal exists to remove.
        // This diagnostic is the boundary-document and defence-in-depth path,
        // exercised directly by the 'multisite topology' cell of
        // sandbox/tests/offline/policy/regress_platform_compatibility.php
        // (named rather than line-cited: that suite's tables grow every time
        // an axis gains a shape, and a line number there rots by the next one).
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
        $phpBoundary = $compatibility['php'];
        if (!self::exercised_supported($php, $phpBoundary)) {
            $diagnostics[] = self::diagnostic(
                'platform_php_version_unsupported',
                'php',
                $php,
                self::exercised_label($phpBoundary),
                'the loaded PHP runtime is outside the exercised platform matrix'
            );
        }

        // Exact-case lookup against current_facts()'s own two classifications
        // (:58 emits 'MariaDB' or 'MySQL' and nothing else), so an engine the
        // map does not name cannot be answered by a range that belongs to a
        // different product. The version diagnostic's `required` is
        // engine-qualified for the same reason: with a per-engine range a bare
        // '>=11.0.0 <12.0.0' would not say WHOSE range it is.
        $database = $facts['database'];
        $engines = $compatibility['database']['engines'];
        $engineRange = $engines[$database['engine']] ?? null;
        if (!is_array($engineRange)) {
            $diagnostics[] = self::diagnostic(
                'platform_database_engine_unsupported',
                'database.engine',
                $database['engine'],
                self::engines_label($engines),
                'the connected database engine is outside the exercised platform contract'
            );
        } elseif (!self::inside_range($database['version'], $engineRange)) {
            $diagnostics[] = self::diagnostic(
                'platform_database_version_unsupported',
                'database.version',
                $database['version'],
                $database['engine'] . ' ' . self::range_label($engineRange),
                'the connected database version is outside the exercised platform range'
            );
        }

        $wordpress = (string) $facts['wordpress'];
        $wordpressBoundary = $compatibility['wordpress'];
        if (!self::exercised_supported($wordpress, $wordpressBoundary)) {
            $diagnostics[] = self::diagnostic(
                'platform_wordpress_version_unsupported',
                'wordpress',
                $wordpress,
                self::exercised_label($wordpressBoundary),
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
            || !self::valid_exercised_axis($php)
            || !self::valid_database_axis($database)
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
     * A range PLUS the exercised-series map that narrows it — the shape the
     * WordPress and PHP axes both declare. Every invariant here is what stops
     * a typo widening a claim past what was actually run. Fail-closed and
     * required, with no path that accepts either axis's pre-map shape (the
     * two-key {last_verified, note} WordPress axis, or the two-key {min, max}
     * PHP one): a boundary that cannot state which series were exercised must
     * refuse (platform_boundary_invalid) rather than fall back to a bare
     * range, per AGENTS.md rule 9.
     *
     * @param array<string,mixed> $axis
     */
    private static function valid_exercised_axis(array $axis): bool {
        if (!self::valid_range($axis)) {
            return false;
        }
        $verified = $axis['verified'] ?? null;
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
                || !self::inside_range($patch, $axis)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The WordPress axis is valid_exercised_axis() PLUS the two invariants
     * that keep `last_verified` honest. PHP declares no such scalar and needs
     * neither (see the class header), so this wrapper is where the asymmetry
     * lives rather than an `if` inside the shared validator.
     *
     * @param array<string,mixed> $wordpress
     */
    private static function valid_wordpress_axis(array $wordpress): bool {
        if (!self::valid_exercised_axis($wordpress)) {
            return false;
        }
        $verified = $wordpress['verified'];
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

    /**
     * The database axis is an engine-keyed map of ranges, and the shape rules
     * are what stop a second engine being smuggled in as a range that belongs
     * to the first. `engines` must be a non-empty object of non-empty string
     * keys, and every value exactly {max, min} — no stray key, because nothing
     * else validates an engine entry (tools/capability-doc.php:200-207 checks
     * only the axis's own top-level keys) and a `verified`-looking extra key
     * would read as an exercised-series claim this gate never evaluates.
     *
     * The pre-map two-key {engine, min, max} shape has no acceptance path:
     * under a per-engine claim, a boundary that names one engine and one bare
     * range cannot say which engine that range describes for any OTHER engine
     * it might later gain (AGENTS.md rule 9 — no compat shim).
     *
     * @param array<string,mixed> $database
     */
    private static function valid_database_axis(array $database): bool {
        $engines = $database['engines'] ?? null;
        if (!is_array($engines) || $engines === [] || array_is_list($engines)) {
            return false;
        }
        foreach ($engines as $engine => $range) {
            // A numeric-looking JSON key decodes to an int here, which
            // is_string() rejects — the same fail-closed answer a blank key
            // gets, and the reason the lookup in assert_supported() can be a
            // plain exact-case array read.
            if (!is_string($engine) || $engine === ''
                || !is_array($range) || array_is_list($range)) {
                return false;
            }
            $keys = array_keys($range);
            sort($keys, SORT_STRING);
            if ($keys !== ['max', 'min'] || !self::valid_range($range)) {
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
     * @param array<string,mixed> $axis
     */
    private static function exercised_supported(string $observed, array $axis): bool {
        return self::inside_range($observed, $axis)
            && is_string($axis['verified'][self::series($observed)] ?? null);
    }

    /** The MAJOR.MINOR series of a dotted version, or '' when it has none. */
    private static function series(string $version): string {
        return preg_match('/^(\d+\.\d+)/', $version, $match) === 1 ? $match[1] : '';
    }

    /**
     * The `required` value of an exercised-axis diagnostic, e.g.
     * `>=6.9.0 <7.2.0 exercised 6.9, 7.0, 7.1`. Ordered by version_compare rather
     * than string sort so a two-digit minor (6.10, or PHP 8.10) cannot render
     * before 6.9 and make the label non-deterministic against the claim's own
     * order.
     *
     * @param array<string,mixed> $axis
     */
    private static function exercised_label(array $axis): string {
        $series = array_map('strval', array_keys((array) ($axis['verified'] ?? [])));
        usort($series, static fn(string $a, string $b): int => version_compare($a, $b));

        return self::range_label($axis) . ' exercised ' . implode(', ', $series);
    }

    /**
     * The `required` value of an engine diagnostic: every engine the claim
     * names, in a stable order, so an operator on an unclaimed engine is told
     * the whole claimed set rather than one arbitrary member of it.
     *
     * @param array<string,mixed> $engines
     */
    private static function engines_label(array $engines): string {
        $names = array_map('strval', array_keys($engines));
        sort($names, SORT_STRING);

        return implode(', ', $names);
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

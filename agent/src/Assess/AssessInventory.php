<?php
declare(strict_types=1);

namespace Duo;

require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Adapter/AdapterSources.php';
require_once __DIR__ . '/../Adapter/CapabilityRegistry.php';
require_once __DIR__ . '/../Review/Coverage.php';
require_once __DIR__ . '/../Review/Pending.php';

/**
 * The one read-only pass `duo assess` runs on a target (round-3 MUP §4.5).
 *
 * It answers "what is here, and what does the pinned policy already say about
 * it" in a single document, so the host does not have to compose five agent
 * commands and reconcile five wire formats. It introduces no planner, no gate
 * and no new evidence: every field is either a live probe the agent already
 * performs (`CapabilityRegistry::probe_target()`), an existing projection
 * quoted verbatim (`Coverage::report()`, `Pending::scan_read_only()`,
 * `AdapterSources::survey()`), or a derivation over declarations `Policy`
 * already exposes.
 *
 * REDACTION. Names and counts only — exactly `Coverage`'s discipline and for
 * the same reason: an inventory an operator runs *before* deciding to put a
 * site under management must not be a data export. Concretely this class
 * never reads an option VALUE and never reads a table ROW. It counts rows
 * with `COUNT(*)`, it groups `wp_posts`/`wp_term_taxonomy` by their type
 * columns, and it reads plugin/theme headers, which are code metadata rather
 * than site content. The one place content-shaped bytes reach the document is
 * `pending.rows`, which is byte-identical to what `wp duo pending
 * --format=json` already publishes (a review queue is useless without its
 * `ref_hint`); this command deliberately does not invent a second, different
 * redaction boundary for the same queue.
 *
 * READ-ONLY. No locks, no hooks, no DDL, no `Ledger::ensure()`. The heavy
 * collaborators are the strictly-read-only twins (`Pending::scan_read_only()`
 * asserts the database stayed readable and refuses rather than reporting an
 * empty queue on a failed SELECT), and the surface counts below are plain
 * aggregates.
 *
 * BOUNDED (MUP §4.6). `surface_groups` is bounded by DECLARATIONS, not by the
 * site: one row per declared post type, taxonomy, table, menu field, widget
 * type, and one per declarant x class for the two long flat sections (options,
 * user meta), which is why those two are grouped rather than listed per key.
 * `pending` is truncated at PENDING_ROW_LIMIT with an explicit `truncated`
 * flag and the untruncated `count` beside it. `plugins` and `themes` are
 * site-sized and deliberately NOT truncated: an installed-code inventory with
 * a hidden tail is worse than a long one, and this is the section an operator
 * is reading the report for. `coverage` keeps its own listing discipline
 * (`Coverage::LARGE_LISTING_THRESHOLD`) unchanged. The human renderer on the
 * command side prints counts only, so no listing reaches a terminal at all.
 *
 * ENGINE-ADAPTER BOUNDARY. No plugin slug, name, schema or business rule
 * appears in this file. Every surface row is derived from what a pinned
 * manifest declared; the manifest NAME travels as data, in `declared_by`,
 * which is the boundary doctrine working rather than being violated.
 */
final class AssessInventory {
    public const FORMAT = 'duo-assess-inventory/v1';

    /** MUP §4.6: the same default listing bound `PlanView` uses. */
    public const PENDING_ROW_LIMIT = 50;

    /** Closed vocabulary for `target.site_mode`. */
    public const SITE_MODES = ['single-site', 'multisite'];

    /** Closed vocabulary for `target.database.engine`. */
    public const DATABASE_ENGINES = ['MariaDB', 'MySQL'];

    /** Closed vocabulary for `policy.surface_groups[].kind`. */
    public const SURFACE_KINDS = [
        'post_type', 'taxonomy', 'option_group', 'table', 'menu', 'media', 'user_meta', 'widget',
    ];

    /**
     * `declared_by` for a rule no pinned ADAPTER declared — WordPress core's
     * own surfaces and the site's own `site.duo.json` policy. The distinction
     * that matters downstream is "an adapter claimed this" versus "nobody
     * did"; naming the site file here would put a second, non-manifest token
     * into a field whose consumers key it against manifest names.
     */
    public const CORE_DECLARANT = 'core';

    /**
     * One live pass. `$options['repo']` is the site repository whose policy
     * and coverage are being reported; it is required because both quoted
     * projections take it.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed> a `duo-assess-inventory/v1` document
     */
    public static function report(Policy $policy, array $options = []): array {
        $repo = isset($options['repo']) ? (string) $options['repo'] : '';
        if ($repo === '') {
            throw CommandRefusalException::invalidArgument('assess-inventory', '--repo');
        }

        // survey() reports rather than throws by construction (see
        // Cli::adapter_survey's own note), so reaching the catch means an IO
        // fault about the manifest library itself. That is a degraded
        // inventory, not a failed one: the rest of the document is still the
        // truth about this target, and a refusal here would deny an operator
        // the assessment because one of six sections could not be read.
        $survey = null;
        $surveyReason = null;
        try {
            $survey = AdapterSources::survey($repo);
        } catch (\Throwable $t) {
            $surveyReason = 'adapter_survey_unreadable';
        }

        return self::from_facts($policy, [
            'probe' => CapabilityRegistry::probe_target(),
            'coverage' => Coverage::report($repo),
            'pending' => Pending::scan_read_only($repo, $policy),
            'adapter_survey' => $survey,
            'adapter_survey_reason' => $surveyReason,
        ]);
    }

    /**
     * Composition seam. The four facts this class does not produce itself —
     * the stack probe and the three quoted projections — arrive as data, so
     * the document's shape, its closed vocabularies, its bounding and its
     * canonical bytes are provable offline without booting the collaborators
     * that need a live WordPress (`Capture::gate_scan_read_only()`'s whole
     * gate walk) or a `SELECT VERSION()` the offline `$wpdb` deliberately
     * refuses to model. Everything else — plugins, themes, media, the policy
     * manifest list and every surface group — is still read live from here.
     *
     * @param array<string,mixed> $facts
     * @return array<string,mixed>
     */
    public static function from_facts(Policy $policy, array $facts): array {
        $probe = $facts['probe'] ?? null;
        if (!is_array($probe)) {
            // probe_target() returns null outside WordPress. A target block
            // reconstructed from defaults would be a fiction about somebody's
            // production site, so this refuses instead.
            throw new CommandRefusalException(
                'assess_inventory_unavailable',
                'assess-inventory could not probe this target',
                'run assess-inventory inside a loaded WordPress environment on the target and try again',
                [[
                    'code' => 'assess_inventory_unavailable',
                    'message' => 'the WordPress/PHP/database stack probe returned no facts',
                    'remediation' => 'run assess-inventory through wp-cli on the target environment',
                ]],
                'duo: assess-inventory refused because CapabilityRegistry::probe_target() reported no target'
            );
        }
        $coverage = is_array($facts['coverage'] ?? null) ? $facts['coverage'] : [];
        $pending = is_array($facts['pending'] ?? null) ? array_values($facts['pending']) : [];
        $survey = is_array($facts['adapter_survey'] ?? null) ? $facts['adapter_survey'] : null;

        $liveCounts = self::live_counts($policy);
        $document = [
            'format' => self::FORMAT,
            'spec_version' => defined('DUO_SPEC_VERSION') ? (int) DUO_SPEC_VERSION : 0,
            'agent_version' => defined('DUO_AGENT_VERSION') ? (string) DUO_AGENT_VERSION : 'unknown',
            'target' => self::target($probe),
            'plugins' => self::plugins($probe),
            'themes' => self::themes($probe),
            'media' => [
                'count' => $liveCounts['post_types']['attachment'] ?? 0,
                // No bounded oracle for stored bytes exists on the database
                // side: attachment size is a filesystem fact, and walking the
                // uploads tree is exactly the unbounded work this command
                // refuses to do. Declared null rather than guessed from
                // `_wp_attachment_metadata`, which is absent for non-images
                // and stale whenever a plugin rewrites the file.
                'bytes' => null,
            ],
            'policy' => [
                'manifests' => self::manifest_rows($policy),
                'surface_groups' => self::surface_groups($policy, $liveCounts),
            ],
            'coverage' => $coverage,
            'pending' => [
                'count' => count($pending),
                'rows' => array_slice($pending, 0, self::PENDING_ROW_LIMIT),
                'truncated' => count($pending) > self::PENDING_ROW_LIMIT,
            ],
            'adapter_survey' => $survey,
        ];
        if ($survey === null) {
            // The shared contract makes the survey block nullable; a null with
            // no reason would be indistinguishable from "this target has no
            // adapters". The key is present only on that degraded path, so a
            // healthy document carries exactly the contract's key set.
            $document['adapter_survey_reason'] = is_string($facts['adapter_survey_reason'] ?? null)
                ? (string) $facts['adapter_survey_reason']
                : 'adapter_survey_unavailable';
        }
        return $document;
    }

    /**
     * The stack half of MUP §2.1's first section, entirely from the probe the
     * capability registry already runs against every target.
     *
     * @param array<string,mixed> $probe
     * @return array<string,mixed>
     */
    private static function target(array $probe): array {
        $database = is_array($probe['database'] ?? null) ? $probe['database'] : [];
        $server = (string) ($database['server'] ?? '');
        if ($server === '') {
            $server = (string) ($database['client'] ?? '');
        }
        return [
            'wordpress' => (string) ($probe['wordpress'] ?? ''),
            'php' => (string) ($probe['php'] ?? ''),
            'database' => [
                // probe_target() reports `MariaDB` or `unknown`; the contract
                // here is a two-value engine, so the same string is read once
                // more rather than promoting `unknown` into `MySQL` blindly:
                // a server banner without "mariadb" in it IS MySQL's banner.
                'engine' => stripos($server, 'mariadb') !== false ? 'MariaDB' : 'MySQL',
                'version' => self::numeric_version($server),
            ],
            'site_mode' => !empty($probe['multisite']) ? 'multisite' : 'single-site',
            'home' => self::site_url_fact('home_url'),
            'siteurl' => self::site_url_fact('site_url'),
        ];
    }

    /**
     * The leading dotted-numeric run of a server banner
     * ("11.8.8-MariaDB-1:11.8.8+maria~ubu2404" -> "11.8.8"). Version
     * comparison is the consumer's job and every comparator in this codebase
     * takes that form; the banner's vendor suffix is not a version.
     */
    private static function numeric_version(string $server): string {
        return preg_match('/\d+(?:\.\d+)*/', $server, $m) === 1 ? $m[0] : $server;
    }

    /**
     * WordPress resolves both URLs through filters, so the option value alone
     * is not the answer a browser would get. An environment where neither
     * function exists is not a target this command can describe.
     */
    private static function site_url_fact(string $function): string {
        if (!function_exists($function)) {
            throw new CommandRefusalException(
                'assess_inventory_unavailable',
                'assess-inventory could not resolve this target\'s addresses',
                'run assess-inventory inside a loaded WordPress environment on the target and try again',
                [[
                    'code' => 'assess_inventory_unavailable',
                    'message' => 'the WordPress URL API is unavailable in this process',
                    'remediation' => 'run assess-inventory through wp-cli on the target environment',
                ]],
                'duo: assess-inventory refused because ' . $function . '() is not defined'
            );
        }
        return (string) $function();
    }

    /**
     * Every INSTALLED plugin with its activation state, not just the active
     * set: an assessment answers "what is here", and an inactive plugin that
     * owns 400 option rows is exactly the kind of thing an operator needs to
     * see before committing. `get_plugins()` lives in wp-admin/includes and is
     * not loaded by default (the same require `Deploy` and `InitCodeInventory`
     * already perform).
     *
     * @param array<string,mixed> $probe
     * @return list<array<string,mixed>>
     */
    private static function plugins(array $probe): array {
        if (!function_exists('get_plugins') && defined('ABSPATH')) {
            $include = ABSPATH . 'wp-admin/includes/plugin.php';
            if (is_file($include)) {
                require_once $include;
            }
        }
        if (!function_exists('get_plugins')) {
            throw new CommandRefusalException(
                'assess_inventory_unavailable',
                'assess-inventory could not read this target\'s installed plugins',
                'run assess-inventory through wp-cli on the target so the WordPress plugin API is loaded, then try again',
                [[
                    'code' => 'assess_inventory_unavailable',
                    'message' => 'the WordPress plugin inventory API is unavailable in this process',
                    'remediation' => 'run assess-inventory through wp-cli on the target environment',
                ]],
                'duo: assess-inventory refused because get_plugins() is not defined'
            );
        }
        $active = array_map('strval', (array) ($probe['active_plugins'] ?? []));
        $rows = [];
        foreach ((array) get_plugins() as $basename => $header) {
            $header = is_array($header) ? $header : [];
            $rows[] = [
                'basename' => (string) $basename,
                'name' => (string) ($header['Name'] ?? ''),
                'version' => (string) ($header['Version'] ?? ''),
                'active' => in_array((string) $basename, $active, true),
            ];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($a['basename'], $b['basename']));
        return $rows;
    }

    /**
     * Every installed theme. Both the stylesheet and the template count as
     * active, because a child theme makes them different rows and a report
     * that marked only one of them would misdescribe every child-theme site.
     *
     * @param array<string,mixed> $probe
     * @return list<array<string,mixed>>
     */
    private static function themes(array $probe): array {
        if (!function_exists('wp_get_themes')) {
            throw new CommandRefusalException(
                'assess_inventory_unavailable',
                'assess-inventory could not read this target\'s installed themes',
                'run assess-inventory through wp-cli on the target so the WordPress theme API is loaded, then try again',
                [[
                    'code' => 'assess_inventory_unavailable',
                    'message' => 'the WordPress theme inventory API is unavailable in this process',
                    'remediation' => 'run assess-inventory through wp-cli on the target environment',
                ]],
                'duo: assess-inventory refused because wp_get_themes() is not defined'
            );
        }
        $activeTheme = is_array($probe['active_theme'] ?? null) ? $probe['active_theme'] : [];
        $active = array_map('strval', array_values($activeTheme));
        $rows = [];
        foreach ((array) wp_get_themes() as $stylesheet => $theme) {
            $rows[] = [
                'stylesheet' => (string) $stylesheet,
                'name' => is_object($theme) && method_exists($theme, 'get') ? (string) $theme->get('Name') : '',
                'version' => is_object($theme) && method_exists($theme, 'get') ? (string) $theme->get('Version') : '',
                'active' => in_array((string) $stylesheet, $active, true),
            ];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($a['stylesheet'], $b['stylesheet']));
        return $rows;
    }

    /**
     * Which adapters this repository actually pinned, where each came from,
     * and what the reviewed registry says about it. Deliberately the pinned
     * set rather than the installed library: the installed library is what
     * `adapter_survey` already reports, and conflating the two is how an
     * operator ends up believing an installed-but-unpinned adapter is in
     * force.
     *
     * @return list<array<string,mixed>>
     */
    private static function manifest_rows(Policy $policy): array {
        $sources = $policy->adapter_sources();
        $rows = [];
        foreach ($policy->manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $claim = $policy->capability_claim($name);
            $disposition = $policy->manifest_disposition($name);
            $digest = is_array($claim) ? ($claim['adapter_digest'] ?? null) : null;
            $status = is_array($disposition) ? ($disposition['status'] ?? null) : null;
            $rows[] = [
                'name' => $name,
                'source' => $sources->source($name),
                'adapter_digest' => is_string($digest) && $digest !== '' ? $digest : null,
                'status' => is_string($status) && $status !== '' ? $status : null,
            ];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $rows;
    }

    /**
     * The bounded live aggregates every surface group draws its count from:
     * one GROUP BY over `wp_posts`, one over `wp_term_taxonomy`, one
     * `SHOW TABLES LIKE` for existence, and one `COUNT(*)` per DECLARED table
     * that actually exists. Three queries plus one per declared table — the
     * same shape and the same discipline `Coverage::tables_report()` already
     * runs, never a per-row read.
     *
     * @return array{post_types: array<string,int>, taxonomies: array<string,int>, tables: array<string,int>}
     */
    private static function live_counts(Policy $policy): array {
        global $wpdb;
        $postTypes = [];
        foreach ((array) $wpdb->get_results(
            "SELECT post_type, COUNT(*) AS n FROM {$wpdb->posts} GROUP BY post_type ORDER BY post_type ASC",
            ARRAY_A
        ) as $row) {
            $postTypes[(string) $row['post_type']] = (int) $row['n'];
        }
        $taxonomies = [];
        foreach ((array) $wpdb->get_results(
            "SELECT taxonomy, COUNT(*) AS n FROM {$wpdb->term_taxonomy} GROUP BY taxonomy ORDER BY taxonomy ASC",
            ARRAY_A
        ) as $row) {
            $taxonomies[(string) $row['taxonomy']] = (int) $row['n'];
        }
        $prefix = (string) $wpdb->prefix;
        $live = array_map(
            'strval',
            (array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($prefix) . '%'))
        );
        $tables = [];
        foreach (array_keys($policy->declared_tables()) as $logical) {
            $physical = $prefix . (string) $logical;
            if (!in_array($physical, $live, true)) {
                continue;
            }
            $tables[(string) $logical] = (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . esc_sql($physical) . '`');
        }
        return ['post_types' => $postTypes, 'taxonomies' => $taxonomies, 'tables' => $tables];
    }

    /**
     * One row per WordPress-language surface the pinned policy already knows
     * about, in the eight kinds the host contract names. Every row's class and
     * declarant come from `Policy`'s own precedence accessors rather than from
     * a second walk of the manifest arrays: a report that resolved a rule
     * differently from the engine would be describing a site nobody runs.
     *
     * `count` is a LIVE row count wherever one bounded aggregate answers it
     * (post types, taxonomies, tables, media) and null where none does —
     * counting the option rows behind an option group would mean re-reading
     * the whole options table `Coverage` already read.
     *
     * @param array{post_types: array<string,int>, taxonomies: array<string,int>, tables: array<string,int>} $counts
     * @return list<array<string,mixed>>
     */
    private static function surface_groups(Policy $policy, array $counts): array {
        $rows = [];
        foreach (self::declared_post_types($policy) as $postType) {
            $details = $policy->post_type_rule_details($postType);
            $rows[] = self::group(
                'post_type',
                $postType,
                (string) ($details['rule']['class'] ?? 'authored'),
                self::declarant($policy, $details['source'] ?? null),
                $counts['post_types'][$postType] ?? 0
            );
        }
        foreach (self::declared_taxonomies($policy) as $taxonomy) {
            $details = $policy->taxonomy_rule_details($taxonomy);
            $rows[] = self::group(
                'taxonomy',
                $taxonomy,
                (string) ($details['rule']['class'] ?? 'authored'),
                self::declarant($policy, $details['source'] ?? null),
                $counts['taxonomies'][$taxonomy] ?? 0
            );
        }
        foreach (array_keys($policy->declared_tables()) as $logical) {
            $logical = (string) $logical;
            $details = $policy->declared_table_details($logical);
            $rows[] = self::group(
                'table',
                $logical,
                (string) ($details['rule']['class'] ?? 'authored'),
                self::declarant($policy, $details['source'] ?? null),
                // Absent from this install: null is "not here", which is a
                // different fact from an empty table's 0.
                $counts['tables'][$logical] ?? null
            );
        }
        foreach (self::section_groups($policy, 'options') as $key => $group) {
            $rows[] = self::group('option_group', $key, $group['class'], $group['declared_by'], null);
        }
        foreach (self::section_groups($policy, 'user_meta') as $key => $group) {
            $rows[] = self::group('user_meta', $key, $group['class'], $group['declared_by'], null);
        }
        foreach (self::declared_names($policy, 'menu_fields') as $field) {
            $details = $policy->menu_field_rule_details($field);
            $rows[] = self::group(
                'menu',
                $field,
                $policy->menu_field_class($field),
                self::declarant($policy, $details['source'] ?? null),
                null
            );
        }
        foreach (array_keys($policy->widget_types()) as $type) {
            $type = (string) $type;
            $details = $policy->widget_type_rule_details($type);
            $rows[] = self::group(
                'widget',
                $type,
                self::widget_class($details['rule'] ?? null),
                self::declarant($policy, $details['source'] ?? null),
                null
            );
        }
        // Media is the one surface WordPress models as a post type and every
        // operator names separately, so it appears in both kinds by design:
        // `post_type:attachment` is the policy rule, `media:attachment` is the
        // surface an assess report puts a storage number next to.
        $mediaDetails = $policy->post_type_rule_details('attachment');
        $rows[] = self::group(
            'media',
            'attachment',
            (string) ($mediaDetails['rule']['class'] ?? 'authored'),
            self::declarant($policy, $mediaDetails['source'] ?? null),
            $counts['post_types']['attachment'] ?? 0
        );

        usort($rows, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
        return $rows;
    }

    /** @return array<string,mixed> */
    private static function group(string $kind, string $id, string $class, string $declaredBy, ?int $count): array {
        return [
            'id' => $kind . ':' . $id,
            'kind' => $kind,
            'class' => in_array($class, Policy::CLASSES, true) ? $class : 'authored',
            'declared_by' => $declaredBy,
            'count' => $count,
        ];
    }

    /**
     * A widget declares a class per SETTING, not per type, so the type-level
     * row reports the shared class when the settings agree and `authored`
     * when they do not — the widget record itself is authored state, and a
     * mixed-class settings map is a per-field detail this row does not claim
     * to summarise.
     */
    private static function widget_class(mixed $rule): string {
        $classes = [];
        foreach ((is_array($rule) ? $rule['settings'] ?? [] : []) as $setting) {
            if (is_array($setting) && isset($setting['class'])) {
                $classes[(string) $setting['class']] = true;
            }
        }
        return count($classes) === 1 ? (string) array_key_first($classes) : 'authored';
    }

    /**
     * Group one flat `<section>.<key>` declaration set by its effective
     * declarant and class. Options and user meta are long, per-key lists; one
     * row per key would be a listing bounded by the site's plugin set rather
     * than by anything, which §4.6 forbids.
     *
     * @return array<string,array{class:string,declared_by:string}>
     */
    private static function section_groups(Policy $policy, string $section): array {
        $groups = [];
        foreach (self::declared_names($policy, $section) as $name) {
            $details = $section === 'options'
                ? $policy->option_rule_details($name)
                : ['rule' => $policy->user_meta_rule($name), 'source' => null];
            if ($section !== 'options') {
                // user_meta has no *_rule_details() accessor; resolve the
                // declarant from the same precedence the resolver uses rather
                // than inventing a second one.
                $details['source'] = self::section_source($policy, $section, $name);
            }
            $class = (string) ($details['rule']['class'] ?? 'authored');
            $declaredBy = self::declarant($policy, $details['source'] ?? null);
            $groups[$declaredBy . ':' . $class] = ['class' => $class, 'declared_by' => $declaredBy];
        }
        ksort($groups, SORT_STRING);
        return $groups;
    }

    /**
     * Site policy first, then the pinned manifests in pin order with core
     * yielding to a non-core declaration — `PolicyRuleResolver::details()`'s
     * own rule, restated only for the sections that expose no details()
     * accessor.
     */
    private static function section_source(Policy $policy, string $section, string $name): ?string {
        if (isset($policy->site['policy'][$section][$name])) {
            return 'site.duo.json';
        }
        $core = null;
        foreach ($policy->manifests as $manifest) {
            if (!isset($manifest[$section][$name])) {
                continue;
            }
            $source = (string) ($manifest['name'] ?? '');
            if ($source === 'core') {
                $core = $source;
                continue;
            }
            return $source;
        }
        return $core;
    }

    /**
     * Every key any pinned manifest or the site itself declares in one flat
     * section, deduplicated and ordered.
     *
     * @return list<string>
     */
    private static function declared_names(Policy $policy, string $section): array {
        $names = array_keys((array) ($policy->site['policy'][$section] ?? []));
        foreach ($policy->manifests as $manifest) {
            $names = array_merge($names, array_keys((array) ($manifest[$section] ?? [])));
        }
        $names = array_values(array_unique(array_map('strval', $names)));
        sort($names, SORT_STRING);
        return $names;
    }

    /**
     * The post types this policy has an opinion about: the site's own
     * authored scope plus every whole-type manifest contract.
     *
     * @return list<string>
     */
    private static function declared_post_types(Policy $policy): array {
        $types = array_merge($policy->post_types(), $policy->declared_post_types());
        foreach (array_keys((array) ($policy->site['policy']['scope']['post_type'] ?? [])) as $name) {
            $types[] = (string) $name;
        }
        $types = array_values(array_unique(array_map('strval', $types)));
        sort($types, SORT_STRING);
        return $types;
    }

    /**
     * The taxonomies this policy has an opinion about. Deliberately the
     * DECLARED set rather than `Policy::taxonomies()`, which expands
     * `taxonomy_patterns` against the live registry: a read-only inventory
     * must not depend on which plugin happened to finish registering before
     * this request.
     *
     * @return list<string>
     */
    private static function declared_taxonomies(Policy $policy): array {
        $taxonomies = array_merge(
            array_map('strval', (array) ($policy->site['policy']['taxonomies'] ?? ['category', 'post_tag'])),
            $policy->declared_taxonomies()
        );
        foreach (array_keys((array) ($policy->site['policy']['scope']['taxonomy'] ?? [])) as $name) {
            $taxonomies[] = (string) $name;
        }
        $taxonomies = array_values(array_unique(array_map('strval', $taxonomies)));
        sort($taxonomies, SORT_STRING);
        return $taxonomies;
    }

    /**
     * Map a rule's resolved source onto the contract's two-value declarant:
     * the manifest name when a pinned ADAPTER declared it, `core` otherwise.
     * `site.duo.json` collapses into `core` on purpose — the consumer keys
     * this field against manifest names, and a site override is "no adapter
     * claimed this", which is exactly what `core` means here.
     */
    private static function declarant(Policy $policy, mixed $source): string {
        if (!is_string($source) || $source === '') {
            return self::CORE_DECLARANT;
        }
        foreach ($policy->manifests as $manifest) {
            if ((string) ($manifest['name'] ?? '') === $source) {
                return $source;
            }
        }
        return self::CORE_DECLARANT;
    }
}

<?php
declare(strict_types=1);

namespace Duo;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Init/InitPlanner.php';
require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Policy/AdapterLibrary.php';
// DUO-3504: pattern_groups() reads PATTERN_KEYS through this class's own
// accessor rather than restating `option_patterns`, so it is required here in
// its own right (rule 1) and not by way of Policy.php's transitive load.
require_once __DIR__ . '/../Policy/PolicyRuleResolver.php';
require_once __DIR__ . '/../Adapter/AdapterSources.php';
require_once __DIR__ . '/../Adapter/TargetProbe.php';
require_once __DIR__ . '/../Repository/RepositoryCompiler.php';
require_once __DIR__ . '/../Review/Coverage.php';
require_once __DIR__ . '/../Review/Pending.php';

/**
 * The one read-only pass `duo assess` runs on a target (round-3 MUP §4.5).
 *
 * It answers "what is here, and what does the pinned policy already say about
 * it" in a single document, so the host does not have to compose five agent
 * commands and reconcile five wire formats. It introduces no planner, no gate
 * and no new evidence: every field is either a live probe the agent already
 * performs (`TargetProbe::probe_target()`), an existing projection
 * quoted verbatim (`Coverage::report()`, `Pending::scan()`,
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
 * collaborators are strictly read-only (`Pending::scan()`
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
     * `declared_by` for a surface NO PINNED ADAPTER DECLARES — WordPress
     * core's own surfaces, and site-local policy nobody shipped a manifest
     * for. The distinction that matters downstream is "an adapter claimed
     * this" versus "nobody did"; naming the site file here would put a
     * second, non-manifest token into a field whose consumers key it against
     * manifest names.
     *
     * DUO-3504 narrowed this from "no adapter's rule WON" to "no adapter
     * DECLARES it". A site scope rule outranks every manifest for
     * classification and must keep doing so, but it does not un-declare the
     * surface — and `duo adapter certify --pin` writes one for every type the
     * adapter it just certified declares (DUO-3495,
     * cli/src/Adapter/AdapterCertify.php:588-609), so the old reading made
     * `duo assess` credit the platform for a site-certified adapter's own
     * CPT. `declarant()` below asks `Policy::declaring_manifest()` before it
     * settles for this value.
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
            $survey = AdapterSources::survey_library($policy->adapter_library(), $repo);
        } catch (\Throwable $t) {
            $surveyReason = 'adapter_survey_unreadable';
        }

        return self::from_facts($policy, [
            'probe' => TargetProbe::probe_target(),
            'coverage' => Coverage::report($repo),
            'pending' => Pending::scan($repo, $policy),
            'adapter_survey' => $survey,
            'adapter_survey_reason' => $surveyReason,
            'adoption' => is_array($options['adoption'] ?? null) ? $options['adoption'] : null,
        ]);
    }

    /**
     * The policy an assessment projects against, and the adoption block that
     * says which one it was.
     *
     * An init-owned repository is assessed as it stands: its own site.duo.json
     * (`adoption` null). An ADOPTION SEED is assessed as `duo init` would
     * propose it: the proposal's config — the adapters init selects for the
     * active plugins and theme, the scope it proposes, the types it leaves
     * local — is written under a private temporary directory and loaded as
     * the policy, with the repository itself still supplying the site adapter
     * source and every state read. The `adoption` block names the mode, the
     * selected adapters, the proposed scope and the proposal's own advisories
     * and unsupported rows, so a reader can tell a preview from a repository
     * in force. If the proposal cannot be computed the seed policy stands and
     * the block says why (`preview: unavailable`).
     *
     * @return array{0:Policy,1:?array<string,mixed>}
     */
    public static function policy_for_assessment(string $repo, ?AdapterLibrary $adapterLibrary = null): array {
        $adapterLibrary ??= Policy::shipped_adapter_library();
        $seedPolicy = Policy::load($repo, null, true, null, $adapterLibrary);
        if (!InitPlanner::is_adoption_seed($repo, $adapterLibrary)) {
            return [$seedPolicy, null];
        }
        try {
            $proposal = InitPlanner::proposal($repo, true, null, $adapterLibrary);
        } catch (\Throwable $t) {
            return [$seedPolicy, [
                'mode' => 'seed',
                'preview' => 'unavailable',
                'reason' => 'the init proposal could not be computed: ' . $t->getMessage(),
                'adapters' => [],
                'scope' => ['post_types' => [], 'taxonomies' => [], 'left_local' => []],
                'advisories' => [],
                'unsupported' => [],
                'ready' => false,
            ]];
        }
        $config = is_array($proposal['state']['config'] ?? null) ? $proposal['state']['config'] : null;
        if ($config === null) {
            return [$seedPolicy, [
                'mode' => 'seed',
                'preview' => 'unavailable',
                'reason' => 'the init proposal carries no config',
                'adapters' => [],
                'scope' => ['post_types' => [], 'taxonomies' => [], 'left_local' => []],
                'advisories' => (array) ($proposal['advisories'] ?? []),
                'unsupported' => (array) ($proposal['unsupported'] ?? []),
                'ready' => ($proposal['ready'] ?? false) === true,
            ]];
        }
        $dir = rtrim(sys_get_temp_dir(), '/') . '/duo-assess-preview-' . bin2hex(random_bytes(6));
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("duo: assess could not create the adoption preview directory $dir");
        }
        register_shutdown_function(static function () use ($dir): void {
            @unlink($dir . '/site.duo.json');
            @rmdir($dir);
        });
        Canon::write_file($dir . '/site.duo.json', Canon::encode($config));
        $policy = Policy::load($dir, null, true, $repo, $adapterLibrary);
        $leftLocal = [];
        foreach ((array) ($config['policy']['scope'] ?? []) as $kind => $rules) {
            foreach ((array) $rules as $name => $rule) {
                $leftLocal[] = "$kind:$name";
            }
        }
        sort($leftLocal, SORT_STRING);
        $adapters = [];
        foreach ((array) ($config['manifests'] ?? []) as $pin) {
            $adapters[] = is_array($pin) ? (string) ($pin['name'] ?? '') : (string) $pin;
        }
        sort($adapters, SORT_STRING);

        return [$policy, [
            'mode' => 'seed',
            'preview' => 'init-proposal',
            'reason' => 'the repository is an adoption seed; surfaces are projected against the policy duo init would propose',
            'adapters' => $adapters,
            'scope' => [
                'post_types' => array_values(array_map('strval', (array) ($config['policy']['post_types'] ?? []))),
                'taxonomies' => array_values(array_map('strval', (array) ($config['policy']['taxonomies'] ?? []))),
                'left_local' => $leftLocal,
            ],
            'advisories' => array_values((array) ($proposal['advisories'] ?? [])),
            'unsupported' => array_values((array) ($proposal['unsupported'] ?? [])),
            'ready' => ($proposal['ready'] ?? false) === true,
        ]];
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
                'duo: assess-inventory refused because TargetProbe::probe_target() reported no target'
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
            // The `plugin:<slug>` surface rows `duo assess` mints (round-3 T6
            // §3.6). A sibling top-level key rather than a member nested under
            // `plugins`, which is a JSON LIST every host consumer already
            // iterates (cli/src/Assess/StackInventory.php:208,247 and
            // AssessReport.php:334) and two of those feed the assess digest;
            // turning it into an object would silently change what those loops
            // walk.
            'plugins_without_adapter' => self::plugins_without_adapter($policy, $probe),
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
        if (is_array($facts['adoption'] ?? null)) {
            // Present only for an adoption seed (policy_for_assessment()): a
            // reader who sees it knows the surfaces below were projected
            // against the init proposal, not a repository in force. An
            // init-owned repository's document keeps the contract's key set.
            $document['adoption'] = $facts['adoption'];
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
     * Every ACTIVE plugin no pinned adapter declares — the inventory half of
     * the `plugin:<slug>` assess surface (round-3 T6 §3.6), whose projection
     * is `unclassified / block / Not qualified` with next action `install
     * adapter`.
     *
     * Judged against the PINNED manifests, not the installed library, and the
     * distinction is the whole point of the row: an adapter sitting in
     * `adapters/` that no pin selects is not managing anything on this site,
     * and reporting the plugin as managed because a file exists would be the
     * silence this document exists to remove. `adapter_survey` is where the
     * installed-but-unpinned adapter is already visible with its own
     * certification word and remediation.
     *
     * Names only, exactly like every other row here, and all three parts of
     * the identity rather than one the reader has to split:
     *
     *   - `basename` is WordPress's own `<dir>/<file>.php`, the same key and
     *     the same meaning the `plugins` rows above already use;
     *   - `slug` is the directory, which is what the host builds
     *     `plugin:<slug>` from and what a bundled `duo-adapter.json` anchors
     *     to;
     *   - `file` is the entry file.
     *
     * A single-file plugin has no directory, so its slug is the file name
     * without `.php` (`hello.php` -> slug `hello`, file `hello.php`).
     *
     * @param array<string,mixed> $probe
     * @return list<array{basename:string,file:string,slug:string}>
     */
    private static function plugins_without_adapter(Policy $policy, array $probe): array {
        $owned = [];
        foreach ($policy->manifests as $manifest) {
            $plugin = is_array($manifest) ? ($manifest['plugin'] ?? null) : null;
            if (is_string($plugin) && $plugin !== '') {
                $owned[$plugin] = true;
            }
        }
        $rows = [];
        $seen = [];
        foreach ((array) ($probe['active_plugins'] ?? []) as $basename) {
            $basename = (string) $basename;
            if ($basename === '' || isset($owned[$basename]) || isset($seen[$basename])) {
                continue;
            }
            $seen[$basename] = true;
            $directory = strpos($basename, '/') === false ? '' : dirname($basename);
            $file = basename($basename);
            $rows[] = [
                'basename' => $basename,
                'file' => $file,
                'slug' => $directory !== '' && $directory !== '.'
                    ? $directory
                    : (string) preg_replace('/\.php$/D', '', $file),
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
        // The content digest every repository pin binds, from the one place
        // that derives it for EVERY source (RepositoryCompiler::resolved_adapters,
        // via ArtifactPolicyIdentity). The reviewed registry's claim carries
        // the same value for a shipped adapter, but a site-certified claim is
        // projected before its final digest exists (AdapterCertification::
        // derivedDisposition says so), so reading the claim left every
        // Site-certified adapter without a digest and `duo assess` refusing
        // `assess_report_unbuildable` on exactly the repositories T6 exists
        // for (grind_adapter_walk.sh S2).
        $resolvedDigests = [];
        foreach (RepositoryCompiler::resolved_adapters($policy) as $row) {
            $resolvedDigests[(string) ($row['name'] ?? '')] = (string) ($row['digest'] ?? '');
        }
        $rows = [];
        foreach ($policy->manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $disposition = $policy->manifest_disposition($name);
            $digest = $resolvedDigests[$name] ?? null;
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
                self::declarant($policy, 'post_types', $postType, $details['source'] ?? null),
                $counts['post_types'][$postType] ?? 0
            );
        }
        foreach (self::declared_taxonomies($policy) as $taxonomy) {
            $details = $policy->taxonomy_rule_details($taxonomy);
            $rows[] = self::group(
                'taxonomy',
                $taxonomy,
                (string) ($details['rule']['class'] ?? 'authored'),
                self::declarant($policy, 'taxonomies', $taxonomy, $details['source'] ?? null),
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
                self::declarant($policy, 'tables', $logical, $details['source'] ?? null),
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
                self::declarant($policy, 'menu_fields', $field, $details['source'] ?? null),
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
                self::declarant($policy, 'widgets', $type, $details['source'] ?? null),
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
            self::declarant($policy, 'post_types', 'attachment', $mediaDetails['source'] ?? null),
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
            $declaredBy = self::declarant($policy, $section, $name, $details['source'] ?? null);
            $groups[$declaredBy . ':' . $class] = ['class' => $class, 'declared_by' => $declaredBy];
        }
        foreach (self::pattern_groups($policy, $section) as $key => $group) {
            $groups[$key] ??= $group;
        }
        ksort($groups, SORT_STRING);
        return $groups;
    }

    /**
     * The same `declarant:class` groups for declarations made by PATTERN
     * rather than by exact key (DUO-3504).
     *
     * `declared_names()` above enumerates exact keys only, so an adapter that
     * classifies its options by namespace — `option_patterns`, the fallback
     * `PolicyRuleResolver::details()`:89-103 consults after every exact
     * declaration misses — had NO row here at all. Its options are genuinely
     * classified, so they never appear in the pending queue either: the whole
     * surface was invisible to assess rather than merely mis-attributed.
     *
     * The pattern key comes from `PolicyRuleResolver::pattern_keys()` rather
     * than a literal, so this enumerates exactly the sections whose lookup
     * really has a pattern fallback; `user_meta` has none and correctly
     * yields nothing. A section may have a surface-specific fallback followed
     * by a legacy shared fallback, and both are enumerated here.
     *
     * §4.6's bound is untouched: no option NAME is produced (a pattern has no
     * finite name set to list), only the declarant x class pair the group id
     * already is — so the row count stays declarants x classes and cannot
     * grow with the site. The `??=` above is precedence written down rather
     * than a real contest: a group's value is a function of its own key, so a
     * pattern re-minting an existing group re-mints an identical row.
     *
     * @return array<string,array{class:string,declared_by:string}>
     */
    private static function pattern_groups(Policy $policy, string $section): array {
        $patternKeys = PolicyRuleResolver::pattern_keys()[$section] ?? [];
        if ($patternKeys === []) {
            return [];
        }
        $groups = [];
        foreach ($policy->manifests as $manifest) {
            $declaredBy = (string) ($manifest['name'] ?? '');
            if ($declaredBy === '') {
                continue;
            }
            foreach ($patternKeys as $patternKey) {
                foreach ((array) ($manifest[$patternKey] ?? []) as $pattern) {
                    if (!is_array($pattern)) {
                        continue;
                    }
                    $class = (string) ($pattern['class'] ?? 'authored');
                    $groups[$declaredBy . ':' . $class] = ['class' => $class, 'declared_by' => $declaredBy];
                }
            }
        }
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
     * Name the pinned adapter behind a surface: the winning rule's own source
     * when a pinned manifest wrote it, otherwise whichever pinned adapter
     * DECLARES the surface, otherwise `core`.
     *
     * The second step is the DUO-3504 fix and it is additive — the rule that
     * won is untouched, and `site.duo.json` still never appears in this field
     * (the consumer keys it against manifest names). What changed is that a
     * site rule shadowing a manifest declaration no longer costs the adapter
     * the credit for declaring it: `certify --pin` writes exactly such a rule
     * for every type it adopts, and the resulting `core` made
     * `ProjectionVocabulary::projectProvenance()` print "Platform-certified"
     * for a site-certified adapter's own CPT while
     * `AssessRenderer::siteCertifiedPrincipals()` printed no principal at all.
     *
     * `$section` and `$name` locate the surface in the manifest grammar; they
     * are what `Policy::declaring_manifest()` needs and they are known at
     * every call site.
     */
    private static function declarant(Policy $policy, string $section, string $name, mixed $source): string {
        if (is_string($source) && $source !== '') {
            foreach ($policy->manifests as $manifest) {
                if ((string) ($manifest['name'] ?? '') === $source) {
                    return $source;
                }
            }
        }
        return $policy->declaring_manifest($section, $name) ?? self::CORE_DECLARANT;
    }
}

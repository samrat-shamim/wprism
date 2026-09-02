<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../Onboarding/Adopt.php';
require_once __DIR__ . '/../Contract/ContractProposal.php';
require_once __DIR__ . '/../Contract/ContractStore.php';
require_once __DIR__ . '/HostProcess.php';

/** Source-checkout, disposable two-site journey over the real WPrism commands. */
final class DemoCommand {
    private const FORMAT = 'wprism-demo-session/v2';
    private const LEGACY_FORMAT = 'wprism-demo-session/v1';
    private const LEGACY_KEYS = [
        'format',
        'name',
        'source_port',
        'target_port',
        'source_repo',
        'target_repo',
        'origin',
        'compose_file',
        'compose_env_file',
        'state_file',
        'phase',
        'ownership_token',
        'runtime_before',
        'last_applied_revision',
        'pending_revision',
        'owned_paths',
    ];
    private const DEFAULT_NAME = 'wprismdemo';
    private const DEFAULT_SOURCE_PORT = 8781;
    private const DEFAULT_TARGET_PORT = 8782;
    private const WOO_VERSION = '11.0.1';
    private const CLI_IMAGE = 'wprism-demo-cli-git:php8.3';
    private const WORDPRESS_IMAGE =
        'wordpress@sha256:65919a9ca10940feb10d9400fead0d639bf86241f47c91e2b9ea4703aa8452cf';
    private const WORDPRESS_VERSION = '7.1';
    private const CORE_OPTION_TOTAL = 134;
    /** Exact sidebar/widget rows measured in the pinned fresh WordPress 7.1 image. @var list<string> */
    private const CORE_SIDEBAR_OPTIONS = [
        'sidebars_widgets',
        'widget_archives',
        'widget_block',
        'widget_calendar',
        'widget_categories',
        'widget_custom_html',
        'widget_media_audio',
        'widget_media_gallery',
        'widget_media_image',
        'widget_media_video',
        'widget_meta',
        'widget_nav_menu',
        'widget_pages',
        'widget_recent-comments',
        'widget_recent-posts',
        'widget_rss',
        'widget_search',
        'widget_tag_cloud',
        'widget_text',
    ];
    /** @var list<string> */
    private const CORE_REVIEWED_PAGE_OPERATIONS = ['capture', 'merge', 'release', 'verify'];

    /** @var list<string> */
    private const CORE_AUTHORED_OPTIONS = [
        'avatar_default',
        'avatar_rating',
        'blog_charset',
        'category_base',
        'close_comments_days_old',
        'close_comments_for_old_posts',
        'comment_max_links',
        'comment_moderation',
        'comment_order',
        'comment_previously_approved',
        'comment_registration',
        'comments_notify',
        'comments_per_page',
        'date_format',
        'default_comment_status',
        'default_comments_page',
        'default_email_category',
        'default_ping_status',
        'default_pingback_flag',
        'default_post_format',
        'default_role',
        'disallowed_keys',
        'gmt_offset',
        'html_type',
        'image_default_align',
        'image_default_link_type',
        'image_default_size',
        'large_size_h',
        'large_size_w',
        'links_updated_date_format',
        'medium_large_size_h',
        'medium_large_size_w',
        'medium_size_h',
        'medium_size_w',
        'moderation_keys',
        'moderation_notify',
        'page_comments',
        'ping_sites',
        'posts_per_rss',
        'require_name_email',
        'rss_use_excerpt',
        'show_avatars',
        'show_comments_cookies_opt_in',
        'site_icon',
        'start_of_week',
        'tag_base',
        'thread_comments',
        'thread_comments_depth',
        'thumbnail_crop',
        'thumbnail_size_h',
        'thumbnail_size_w',
        'time_format',
        'timezone_string',
        'uploads_use_yearmonth_folders',
        'use_balanceTags',
        'use_smilies',
        'use_trackback',
        'users_can_register',
        // WP 7.1 reads this to control attachment URLs/admin links and has no
        // ordinary apply-time regenerator, so it is authored, not derived.
        'wp_attachment_pages_enabled',
        'wp_notes_notify',
    ];

    /** Numeric/boolean authored settings reviewed as values, never entity ids. @var list<string> */
    private const CORE_AUTHORED_LINT_OK = [
        'close_comments_days_old',
        'close_comments_for_old_posts',
        'comment_max_links',
        'comment_moderation',
        'comment_previously_approved',
        'comment_registration',
        'comments_notify',
        'comments_per_page',
        'default_pingback_flag',
        'gmt_offset',
        'large_size_h',
        'large_size_w',
        'medium_large_size_h',
        'medium_large_size_w',
        'medium_size_h',
        'medium_size_w',
        'moderation_notify',
        'page_comments',
        'posts_per_rss',
        'require_name_email',
        'rss_use_excerpt',
        'show_avatars',
        'show_comments_cookies_opt_in',
        'start_of_week',
        'thread_comments',
        'thread_comments_depth',
        'thumbnail_crop',
        'thumbnail_size_h',
        'thumbnail_size_w',
        'uploads_use_yearmonth_folders',
        'use_balanceTags',
        'use_smilies',
        'use_trackback',
        'users_can_register',
        'wp_attachment_pages_enabled',
        'wp_notes_notify',
    ];

    /** @var array<string,string> */
    private const CORE_AUTHORED_REFS = [
        'default_email_category' => 'term',
        'site_icon' => 'post',
    ];

    /**
     * DB/upgrade facts. In particular, core's upgrade.php turns
     * link_manager_enabled off when its links-table probe finds no row; it is
     * a local projection of that database, not portable authored intent.
     *
     * @var list<string>
     */
    private const CORE_DERIVED_OPTIONS = [
        'db_version',
        'finished_splitting_shared_terms',
        'initial_db_version',
        'link_manager_enabled',
    ];

    /** @var list<string> */
    private const CORE_RUNTIME_OPTIONS = [
        'admin_email_lifespan',
        'auto_plugin_theme_update_emails',
        'auto_update_core_dev',
        'auto_update_core_major',
        'auto_update_core_minor',
        // This is a link_category term id, but that taxonomy is deliberately
        // outside the page-only demo. Keeping it local is honest; a raw id is
        // never smuggled into portable state.
        'default_link_category',
        'hack_file',
        'wp_force_deactivated_plugins',
        'wp_user_roles',
    ];

    /** Credentials, connection facts and host paths/URLs; none is captured. @var list<string> */
    private const CORE_OPTIONAL_ENV_OPTIONS = [
        'mailserver_login',
        'mailserver_pass',
        'mailserver_port',
        'mailserver_url',
        'upload_path',
        'upload_url_path',
    ];

    private const LIVE_PROCESS_TIMEOUT_MILLISECONDS = 1800000;

    /**
     * @param list<string> $args everything after `demo`
     * @param ?callable(string):void $phaseHook offline fault-injection seam
     */
    public static function run(array $args, string $sourceRoot, ?callable $phaseHook = null): int {
        $action = array_shift($args);
        if (!is_string($action)
            || !in_array($action, ['start', 'status', 'review', 'capture', 'apply', 'refusal', 'stop'], true)) {
            fwrite(STDERR, "wprism: demo: expected start, status, review, capture, apply, refusal, or stop\n");
            return 1;
        }
        try {
            $options = self::options($action, $args);
            $lock = self::lock($sourceRoot, $options['name']);
            try {
                return match ($action) {
                    'start' => self::start($sourceRoot, $options, $phaseHook),
                    'status' => self::status($sourceRoot, $options['name']),
                    'review' => self::review($sourceRoot, $options['name']),
                    'capture' => self::capture($sourceRoot, $options['name']),
                    'apply' => self::apply($sourceRoot, $options['name']),
                    'refusal' => self::refusal($sourceRoot, $options['name']),
                    'stop' => self::stop($sourceRoot, $options['name'], $phaseHook),
                };
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } catch (\Throwable $error) {
            fwrite(STDERR, 'wprism: demo: ' . $error->getMessage() . "\n");
            return 1;
        }
    }

    /** @return array{name:string,source_port:int,target_port:int,scenario:string,accept_page_only:bool} */
    public static function options(string $action, array $args): array {
        $values = [
            'name' => self::DEFAULT_NAME,
            'source_port' => self::DEFAULT_SOURCE_PORT,
            'target_port' => self::DEFAULT_TARGET_PORT,
            'scenario' => 'core',
            'accept_page_only' => false,
        ];
        $seen = [];
        foreach ($args as $arg) {
            if ($action === 'review' && $arg === '--accept-page-only') {
                if (isset($seen['accept_page_only'])) {
                    throw new \RuntimeException('review received --accept-page-only more than once');
                }
                $seen['accept_page_only'] = true;
                $values['accept_page_only'] = true;
                continue;
            }
            if (!is_string($arg) || preg_match('/^--(name|source-port|target-port|scenario)=(.+)$/D', $arg, $match) !== 1) {
                throw new \RuntimeException("unsupported $action argument '$arg'");
            }
            $key = str_replace('-', '_', $match[1]);
            if (isset($seen[$key])) {
                throw new \RuntimeException("$action received --{$match[1]} more than once");
            }
            $seen[$key] = true;
            $values[$key] = in_array($key, ['source_port', 'target_port'], true)
                ? self::port($match[2], '--' . $match[1])
                : $match[2];
        }
        if (preg_match('/^[a-z][a-z0-9]{1,23}$/D', (string) $values['name']) !== 1) {
            throw new \RuntimeException('--name must begin with a lowercase letter and contain 2-24 lowercase alphanumerics');
        }
        if (!in_array($values['scenario'], ['core', 'woocommerce'], true)) {
            throw new \RuntimeException("--scenario must be 'core' or 'woocommerce'");
        }
        if ($action !== 'start'
            && ($values['source_port'] !== self::DEFAULT_SOURCE_PORT
                || $values['target_port'] !== self::DEFAULT_TARGET_PORT
                || $values['scenario'] !== 'core')) {
            throw new \RuntimeException("$action accepts only --name=<demo-name>");
        }
        if ($values['source_port'] === $values['target_port']) {
            throw new \RuntimeException('source and target ports must differ');
        }
        if ($action === 'review' && $values['accept_page_only'] !== true) {
            throw new \RuntimeException(
                'review requires the exact --accept-page-only confirmation; no proposal or repository bytes changed'
            );
        }
        return [
            'name' => (string) $values['name'],
            'source_port' => (int) $values['source_port'],
            'target_port' => (int) $values['target_port'],
            'scenario' => (string) $values['scenario'],
            'accept_page_only' => (bool) $values['accept_page_only'],
        ];
    }

    /** @param array{name:string,source_port:int,target_port:int,scenario:string,accept_page_only:bool} $options */
    private static function start(string $sourceRoot, array $options, ?callable $phaseHook): int {
        self::requireTools(['docker', 'git', 'jq']);
        $session = self::sessionShape($sourceRoot, $options);
        self::restoreClaimedSession((string) $session['state_file'], (string) $options['name']);
        if (file_exists($session['state_file']) || is_link($session['state_file'])) {
            throw new \RuntimeException("demo '{$options['name']}' already has a session; run `wprism demo status` or `wprism demo stop`");
        }
        foreach (['source_repo', 'target_repo', 'origin', 'compose_env_file'] as $field) {
            $path = (string) $session[$field];
            if (file_exists($path) || is_link($path)) {
                throw new \RuntimeException("refusing to reuse existing demo path $path");
            }
            $claim = self::deletionClaim($session, $field);
            if (file_exists($claim) || is_link($claim)) {
                throw new \RuntimeException("refusing to reuse existing demo cleanup claim $claim");
            }
            if ($field !== 'compose_env_file') {
                $acquisition = self::acquisitionStage($session, $field);
                if (file_exists($acquisition) || is_link($acquisition)) {
                    throw new \RuntimeException("refusing to reuse existing demo acquisition stage $acquisition");
                }
            }
        }

        self::writeSession($session);
        if ($phaseHook !== null) {
            $phaseHook('session_published');
        }
        try {
            foreach (['source_repo', 'target_repo', 'origin'] as $field) {
                self::acquireOwnedDirectory($session, $field, $phaseHook);
            }
            self::acquireOwnedEnvironment($session, $sourceRoot, $phaseHook);
            self::assertWordPressImage($sourceRoot);
            $label = $options['scenario'] === 'woocommerce' ? 'WooCommerce' : 'WordPress core';
            echo "Starting an exact, disposable $label pair. This can take a few minutes on the first image/artifact pull.\n";
            $up = self::runProcess(
                [
                    'bash', $sourceRoot . '/sandbox/bin/pair.sh', 'up', $options['name'],
                    (string) $options['source_port'], (string) $options['target_port'],
                    '--http', '--artifacts', '--git-cli',
                ],
                $sourceRoot,
                [
                    'WPRISM_SOURCE_ROOT' => $sourceRoot,
                    'WPRISM_EXPECTED_SOURCE_SHA' => trim(self::mustRun(['git', 'rev-parse', 'HEAD'], $sourceRoot)['stdout']),
                    'WPRISM_CLI_IMAGE' => self::CLI_IMAGE,
                    'WPRISM_WP_IMAGE' => self::WORDPRESS_IMAGE,
                ],
                true,
                self::LIVE_PROCESS_TIMEOUT_MILLISECONDS
            );
            if ($up['exit'] !== 0) {
                throw new \RuntimeException('pair startup failed');
            }
            if ($phaseHook !== null) {
                $phaseHook('compose_env_published');
            }
            self::prepareSourceRepository($session, $phaseHook);
            if ($options['scenario'] === 'woocommerce') {
                self::installWooCommerce($session, $sourceRoot);
            }
            self::seedSourceContent($session);
            self::runWPrism($session, $sourceRoot, $session['source_repo'], ['capture', 'demo-source'], true);
            if ($options['scenario'] === 'core') {
                self::assertCoreProfileCapture($session);
            }
            self::git($session['source_repo'], ['add', '-A']);
            self::git($session['source_repo'], [
                '-c', 'user.name=wprism-demo', '-c', 'user.email=demo@example.test',
                'commit', '-m', 'demo: initial ' . $options['scenario'] . ' authored state',
            ]);
            self::git($session['source_repo'], ['push', '-u', 'origin', 'main']);
            self::cloneTargetRepository($session);
            self::runWPrism($session, $sourceRoot, $session['target_repo'], ['deploy', 'demo-target'], true);
            if ($options['scenario'] === 'woocommerce') {
                self::establishHpos($session, 2);
                self::configureWooQualification($session, 2);
            }
            self::seedTargetManagedContent($session);
            if ($options['scenario'] === 'core') {
                self::provisionCoreEnvironment($session);
            }
            $revision = trim(self::git($session['target_repo'], ['rev-parse', 'HEAD'])['stdout']);
            self::runWPrism(
                $session,
                $sourceRoot,
                $session['target_repo'],
                ['apply', 'demo-target', '--adopt-by-slug=terms,posts', '--default-author=admin', '--revision=' . $revision],
                true
            );
            $session['runtime_before'] = self::seedTargetRuntime($session);
            self::assertCapabilityQualification($session, $sourceRoot, 'demo-source', $session['source_repo']);
            self::assertCapabilityQualification($session, $sourceRoot, 'demo-target', $session['target_repo']);
            if ($options['scenario'] === 'core') {
                self::assertCoreOptionInventory($session, $sourceRoot);
                self::assertCoreAssessment($session, $sourceRoot);
                self::prepareCoreContractReview($session, $sourceRoot);
            }
            $session['last_applied_revision'] = $revision;
            $session['phase'] = $options['scenario'] === 'core' ? 'review_required' : 'ready';
            self::replaceSession($session);
        } catch (\Throwable $error) {
            try {
                self::teardownSession($sourceRoot, $session, false, null);
            } catch (\Throwable $cleanup) {
                throw new \RuntimeException($error->getMessage() . '; cleanup paused: ' . $cleanup->getMessage());
            }
            throw $error;
        }

        echo $options['scenario'] === 'core' ? "\nDemo review required.\n" : "\nDemo ready.\n";
        echo "  Source: http://localhost:{$options['source_port']}/wp-admin/\n";
        echo "  Target: http://localhost:{$options['target_port']}/wp-admin/\n";
        echo "  Login:  admin / admin\n";
        echo "  Repo:   {$session['source_repo']}\n\n";
        $edit = $options['scenario'] === 'woocommerce' ? "'WPrism Demo Mug' product" : "'WPrism Demo Page' page";
        if ($options['scenario'] === 'core') {
            echo "Whole-site release assessment: READY (bounded machine view verified).\n";
            echo "The generated contract still names an optional code-lifecycle window this page-only journey will not enter.\n";
            echo "Review boundary: accept only the generated page surface; no code, deletion, or unknown effect is accepted.\n";
            echo "Continue with the explicit operator action:\n";
            echo '  ' . escapeshellarg(self::demoCli($sourceRoot)) . ' demo review --name=' . $options['name']
                . " --accept-page-only\n";
            return 0;
        } else {
            echo "Capability preflight: READY. Run `wprism assess` to review the advanced WooCommerce surface.\n";
        }
        echo "Edit the $edit on the SOURCE site, then run:\n";
        echo '  ' . escapeshellarg(self::demoCli($sourceRoot)) . ' demo capture --name=' . $options['name'] . "\n";
        return 0;
    }

    private static function status(string $sourceRoot, string $name): int {
        $session = self::readSession($sourceRoot, $name, null, true);
        echo "Demo '$name' phase: {$session['phase']}.\n";
        echo "  scenario: {$session['scenario']}\n";
        echo "  source: http://localhost:{$session['source_port']} ({$session['source_repo']})\n";
        echo "  target: http://localhost:{$session['target_port']} ({$session['target_repo']})\n";
        if ($session['runtime_before'] !== '') {
            echo '  runtime proof: ' . self::runtimeStatusWitness((string) $session['scenario']) . "\n";
        }
        if ($session['phase'] === 'review_required') {
            echo '  next: ' . escapeshellarg(self::demoCli($sourceRoot)) . " demo review --name=$name --accept-page-only\n";
        }
        return 0;
    }

    private static function review(string $sourceRoot, string $name): int {
        $session = self::readSession($sourceRoot, $name);
        if (($session['scenario'] ?? null) !== 'core' || ($session['phase'] ?? null) !== 'review_required') {
            throw new \RuntimeException('demo review requires a core session stopped at its review-required phase');
        }
        $sourceRepo = (string) $session['source_repo'];
        $status = self::git($sourceRepo, ['status', '--porcelain=v2', '--untracked-files=all']);
        if (trim($status['stdout']) !== '') {
            throw new \RuntimeException(
                'demo review requires a clean source index and worktree, including untracked files'
            );
        }

        $store = new ContractStore($sourceRepo);
        $proposal = $store->readProposal('demo-target');
        if (!is_array($proposal)) {
            throw new \RuntimeException('the generated demo-target contract proposal is missing');
        }
        self::assertCoreGeneratedProposal($proposal);

        $reviewItem = 'review and decide external effect ' . ContractProposal::LIFECYCLE_EFFECT_ID;
        $pageSurface = null;
        foreach ($proposal['contract']['declarations']['surfaces'] as $surface) {
            if (($surface['id'] ?? null) === 'post_type:page') {
                $pageSurface = $surface;
                break;
            }
        }
        if (!is_array($pageSurface)) {
            throw new \RuntimeException('the generated proposal lost its reviewed page surface');
        }
        $pageSurface['operations'] = self::CORE_REVIEWED_PAGE_OPERATIONS;
        $pageLabel = (string) $pageSurface['label'];
        $proposal['contract']['declarations']['surfaces'] = [$pageSurface];
        $proposal['contract']['declarations']['external_effects'] = [];
        $proposal['contract']['declarations']['surface_labels'] = ['post_type:page' => $pageLabel];
        $proposal['contract']['declarations']['unsupported'] = array_values(array_filter(
            $proposal['contract']['declarations']['unsupported'],
            static fn ($row): bool => is_array($row) && ($row['surface'] ?? null) === 'post_type:page'
        ));
        $proposal['review_required'] = array_values(array_filter(
            $proposal['review_required'],
            static fn ($item): bool => $item !== $reviewItem
        ));
        $proposal['review_required_count'] = count($proposal['review_required']);
        $store->writeProposal('demo-target', $proposal);

        $accepted = self::runWPrism(
            $session,
            $sourceRoot,
            $sourceRepo,
            ['contract', 'demo-target', 'accept', '--format=json'],
            false,
            false
        );
        $receipt = json_decode($accepted['stdout'], true);
        if ($accepted['exit'] !== 0
            || ($receipt['format'] ?? null) !== 'wprism-contract-accept/v1'
            || ($receipt['environment'] ?? null) !== 'demo-target'
            || ($receipt['staged'] ?? null) !== true
            || preg_match('/^sha256:[a-f0-9]{64}$/D', (string) ($receipt['contract_digest'] ?? '')) !== 1) {
            throw new \RuntimeException('the explicitly reviewed page-only contract was not accepted: '
                . trim($accepted['stderr'] !== '' ? $accepted['stderr'] : $accepted['stdout']));
        }

        $expectedArtifacts = [
            '.wprism/contract/contract.json',
            '.wprism/contract/projection.json',
        ];
        $staged = preg_split('/\R/', trim(self::git($sourceRepo, ['diff', '--cached', '--name-only'])['stdout']));
        $staged = is_array($staged) ? array_values(array_filter($staged, 'strlen')) : [];
        sort($staged, SORT_STRING);
        if ($staged !== $expectedArtifacts || trim(self::git($sourceRepo, ['diff', '--name-only'])['stdout']) !== '') {
            throw new \RuntimeException('contract accept staged bytes outside the two exact review artifacts');
        }

        $contract = $store->readContract();
        $projection = $store->readProjection();
        if (!is_array($contract)
            || !hash_equals((string) $receipt['contract_digest'], (string) ($contract['contract_digest'] ?? ''))
            || !is_array($projection)) {
            throw new \RuntimeException('the staged contract/projection did not verify after acceptance');
        }
        self::assertCoreAcceptedContract($contract);
        $shown = self::runWPrism(
            $session,
            $sourceRoot,
            $sourceRepo,
            ['contract', 'demo-target', 'show', '--format=json'],
            false,
            false
        );
        $showDocument = json_decode($shown['stdout'], true);
        if ($shown['exit'] !== 0
            || !hash_equals(
                (string) $receipt['contract_digest'],
                (string) ($showDocument['contract']['contract_digest'] ?? '')
            )
            || !is_array($showDocument['projection'] ?? null)) {
            throw new \RuntimeException('the real contract reader did not verify the accepted review artifacts');
        }

        self::git($sourceRepo, [
            '-c', 'user.name=wprism-demo', '-c', 'user.email=demo@example.test',
            'commit', '-m', 'demo: accept reviewed page-only contract',
        ]);
        $revision = trim(self::git($sourceRepo, ['rev-parse', 'HEAD'])['stdout']);
        self::git($sourceRepo, ['push', 'origin', $revision . ':refs/heads/main']);
        self::git((string) $session['target_repo'], ['pull', '--ff-only', 'origin', 'main']);
        self::runWPrism(
            $session,
            $sourceRoot,
            (string) $session['target_repo'],
            ['deploy', 'demo-target'],
            true
        );
        $targetRevision = trim(self::git((string) $session['target_repo'], ['rev-parse', 'HEAD'])['stdout']);
        if (!hash_equals($revision, $targetRevision)) {
            throw new \RuntimeException('target checkout did not fast-forward to the reviewed contract revision');
        }

        $session['last_applied_revision'] = $revision;
        $session['phase'] = 'ready';
        self::replaceSession($session);

        echo "Demo ready: the real contract command accepted and committed only contract.json + projection.json.\n";
        echo "Edit the 'WPrism Demo Page' page on the SOURCE site, then run:\n";
        echo '  ' . escapeshellarg(self::demoCli($sourceRoot)) . " demo capture --name=$name\n";
        return 0;
    }

    /** @param array<string,mixed> $proposal */
    private static function assertCoreGeneratedProposal(array $proposal): void {
        ContractProposal::validateProposal($proposal);
        $contract = is_array($proposal['contract'] ?? null) ? $proposal['contract'] : [];
        ApplicationContract::validate($contract, false);
        $expectedEffect = [
            'containment' => 'live',
            'decided_by' => ApplicationContract::UNREVIEWED_DECIDED_BY,
            'effect_recovery_semantics' => 'provider-state restorable',
            'id' => ContractProposal::LIFECYCLE_EFFECT_ID,
            'reason' => ContractProposal::UNREVIEWED_REASON,
            'restored_by' => 'code release',
            'surfaces' => ['plugins/themes'],
        ];
        if (($contract['declarations']['external_effects'] ?? null) !== [$expectedEffect]) {
            throw new \RuntimeException('the core proposal did not contain exactly the generated lifecycle placeholder');
        }
        $pageRelease = 0;
        foreach ((array) ($contract['declarations']['surfaces'] ?? []) as $surface) {
            if (($surface['decided_by'] ?? null) === ApplicationContract::UNREVIEWED_DECIDED_BY) {
                throw new \RuntimeException('the core proposal contains an unresolved surface');
            }
            if (($surface['id'] ?? null) === 'post_type:page'
                && count(array_diff(self::CORE_REVIEWED_PAGE_OPERATIONS, (array) ($surface['operations'] ?? []))) === 0) {
                $pageRelease++;
            }
        }
        $reviewItem = 'review and decide external effect ' . ContractProposal::LIFECYCLE_EFFECT_ID;
        $reviewItems = is_array($proposal['review_required'] ?? null) ? $proposal['review_required'] : [];
        if ($pageRelease !== 1
            || ($contract['declarations']['journeys'] ?? null) !== []
            || count(array_keys($reviewItems, $reviewItem, true)) !== 1
            || ($proposal['review_required_count'] ?? null) !== count($reviewItems)) {
            throw new \RuntimeException('the generated core proposal review boundary is not the exact page-only shape');
        }
    }

    /** @param array<string,mixed> $contract */
    private static function assertCoreAcceptedContract(array $contract): void {
        $surfaces = $contract['declarations']['surfaces'] ?? null;
        $page = is_array($surfaces) && count($surfaces) === 1 ? ($surfaces[0] ?? null) : null;
        $unsupported = $contract['declarations']['unsupported'] ?? null;
        $unsupportedIsPageOnly = is_array($unsupported) && array_is_list($unsupported);
        if ($unsupportedIsPageOnly) {
            foreach ($unsupported as $row) {
                if (!is_array($row) || ($row['surface'] ?? null) !== 'post_type:page') {
                    $unsupportedIsPageOnly = false;
                    break;
                }
            }
        }
        if (!is_array($page)
            || ($page['id'] ?? null) !== 'post_type:page'
            || ($page['operations'] ?? null) !== self::CORE_REVIEWED_PAGE_OPERATIONS
            || in_array('delete', (array) ($page['operations'] ?? []), true)
            || ($contract['declarations']['external_effects'] ?? null) !== []
            || ($contract['declarations']['journeys'] ?? null) !== []
            || ($contract['declarations']['surface_labels'] ?? null) !== [
                'post_type:page' => (string) ($page['label'] ?? ''),
            ]
            || !$unsupportedIsPageOnly) {
            throw new \RuntimeException(
                'the accepted contract is not the exact page-only, no-delete, no-external-effect review boundary'
            );
        }
    }

    /** @param array<string,mixed> $session */
    private static function prepareCoreContractReview(array $session, string $sourceRoot): void {
        $sourceRepo = (string) $session['source_repo'];
        $store = new ContractStore($sourceRepo);
        if ($store->readContract() !== null || $store->readProjection() !== null) {
            throw new \RuntimeException('the core demo had contract authority before operator review');
        }
        $proposed = self::runWPrism(
            $session,
            $sourceRoot,
            $sourceRepo,
            ['contract', 'demo-target', 'propose', '--format=json'],
            false,
            false
        );
        $proposal = json_decode($proposed['stdout'], true);
        if ($proposed['exit'] !== 0 || !is_array($proposal)) {
            throw new \RuntimeException('the real contract command did not generate the core proposal');
        }
        self::assertCoreGeneratedProposal($proposal);
        if ($store->readProposal('demo-target') !== $proposal) {
            throw new \RuntimeException('the generated core proposal output disagrees with its source-repository artifact');
        }

        $unread = self::runWPrism(
            $session,
            $sourceRoot,
            $sourceRepo,
            ['contract', 'demo-target', 'accept', '--format=json'],
            false,
            false
        );
        $refusal = json_decode($unread['stdout'], true);
        if ($unread['exit'] === 0
            || ($refusal['format'] ?? null) !== 'wprism-command-refusal/v1'
            || ($refusal['reason_code'] ?? null) !== 'external_effect_unreviewed'
            || $store->readContract() !== null
            || $store->readProjection() !== null
            || trim(self::git($sourceRepo, ['diff', '--cached', '--name-only'])['stdout']) !== '') {
            throw new \RuntimeException('an unread generated proposal did not refuse without repository authority');
        }
    }

    private static function runtimeStatusWitness(string $scenario): string {
        return $scenario === 'woocommerce'
            ? 'target-only WooCommerce order and live stock retained; exact internal row witness recorded'
            : 'target-only WordPress comment and comment metadata retained; exact internal row witness recorded';
    }

    private static function capture(string $sourceRoot, string $name): int {
        $session = self::readSession($sourceRoot, $name);
        self::assertReady($session);
        self::runWPrism($session, $sourceRoot, $session['source_repo'], ['capture', 'demo-source'], true);
        $diff = self::git($session['source_repo'], ['status', '--short']);
        if (trim($diff['stdout']) === '') {
            echo "Capture is byte-identical to the baseline; make an authored change on the source site first.\n";
            return 0;
        }
        echo "\nCaptured changes (review with `git -C " . escapeshellarg($session['source_repo']) . " diff`):\n";
        echo $diff['stdout'];
        echo "Next:\n  " . escapeshellarg(self::demoCli($sourceRoot)) . " demo apply --name=$name\n";
        return 0;
    }

    private static function apply(string $sourceRoot, string $name): int {
        $session = self::readSession($sourceRoot, $name);
        self::assertReady($session);
        $status = trim(self::git($session['source_repo'], ['status', '--short'])['stdout']);
        $pending = $session['pending_revision'];
        if ($status !== '' && $pending !== null) {
            throw new \RuntimeException('a prior revision is still pending; revert the new source edit, retry apply, then capture it separately');
        }
        if ($status !== '') {
            self::git($session['source_repo'], ['add', '-A']);
            self::git($session['source_repo'], [
                '-c', 'user.name=wprism-demo', '-c', 'user.email=demo@example.test',
                'commit', '-m', 'demo: capture authored source change',
            ]);
            $revision = trim(self::git($session['source_repo'], ['rev-parse', 'HEAD'])['stdout']);
            $session['pending_revision'] = $revision;
            self::replaceSession($session);
        } else {
            $revision = is_string($pending)
                ? $pending
                : trim(self::git($session['source_repo'], ['rev-parse', 'HEAD'])['stdout']);
            if ($revision === (string) $session['last_applied_revision']) {
                throw new \RuntimeException('there is no captured Git diff; edit the source and run demo capture first');
            }
            if ($pending === null) {
                $session['pending_revision'] = $revision;
                self::replaceSession($session);
            }
        }
        if (($session['scenario'] ?? null) === 'core') {
            self::assertCorePageOnlyRevision($session, $revision);
        }
        self::git($session['source_repo'], ['push', 'origin', $revision . ':refs/heads/main']);
        self::git($session['target_repo'], ['pull', '--ff-only', 'origin', 'main']);
        self::runWPrism($session, $sourceRoot, $session['target_repo'], ['deploy', 'demo-target'], true);
        $targetRevision = trim(self::git($session['target_repo'], ['rev-parse', 'HEAD'])['stdout']);
        if ($targetRevision !== $revision) {
            throw new \RuntimeException('target checkout does not match the pending source revision');
        }
        if (($session['scenario'] ?? null) === 'core') {
            self::assertCoreReleasePlan($session, $sourceRoot, $revision);
            echo "Authorization preview verified the exact page-only revision without mutating repository or runtime state.\n";
            echo "Running the lower-level evaluation apply; this is not production release authority.\n";
        }
        self::runWPrism(
            $session,
            $sourceRoot,
            $session['target_repo'],
            ['apply', 'demo-target', '--adopt-by-slug=terms,posts', '--default-author=admin', '--revision=' . $revision],
            true
        );
        if (($session['scenario'] ?? null) === 'core') {
            self::assertCorePageConvergence($session);
        }
        $after = self::targetRuntimeSnapshot($session);
        if (!hash_equals((string) $session['runtime_before'], $after)) {
            throw new \RuntimeException("target runtime changed across apply\n  before: {$session['runtime_before']}\n  after:  $after");
        }
        $session['last_applied_revision'] = $revision;
        $session['pending_revision'] = null;
        self::replaceSession($session);
        $proof = $session['scenario'] === 'woocommerce'
            ? 'Its order identity/status/total and live stock stayed byte-identical.'
            : 'The target page matches its exact captured artifact and its target-only comment stayed byte-identical.';
        echo (($session['scenario'] ?? null) === 'core'
            ? 'Lower-level evaluation apply completed for the reviewed Git revision. '
            : 'Applied the reviewed Git revision to the target. ') . $proof . "\n";
        if (($session['scenario'] ?? null) === 'core') {
            echo "Production execution requires stage-source, release prepare, signed authorization, and release execute.\n";
        }
        echo "Next:\n  " . escapeshellarg(self::demoCli($sourceRoot)) . " demo refusal --name=$name\n";
        return 0;
    }

    /** @param array<string,mixed> $session */
    private static function assertCorePageOnlyRevision(array $session, string $revision): void {
        $base = (string) ($session['last_applied_revision'] ?? '');
        if (preg_match('/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/D', $base) !== 1
            || preg_match('/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/D', $revision) !== 1) {
            throw new \RuntimeException('the core demo cannot bind its page review to exact Git revisions');
        }
        $changed = preg_split('/\R/', trim(self::git(
            (string) $session['source_repo'],
            ['diff', '--name-only', '--diff-filter=ACDMRTUXB', $base . '..' . $revision, '--']
        )['stdout']));
        $changed = is_array($changed) ? array_values(array_filter($changed, 'strlen')) : [];
        if (count($changed) !== 1
            || preg_match(
                '#^state/posts/page/[a-f0-9-]{36}--wprism-demo-page\.md$#D',
                (string) ($changed[0] ?? '')
            ) !== 1) {
            throw new \RuntimeException(
                'the reviewed core demo permits exactly one WPrism Demo Page state artifact in this revision'
            );
        }
    }

    /** Prove authored page fields converged while the separate runtime witness proves preservation. */
    private static function assertCorePageConvergence(array $session): void {
        $paths = glob((string) $session['target_repo'] . '/state/posts/page/*--wprism-demo-page.md');
        if (!is_array($paths) || count($paths) !== 1 || is_link($paths[0]) || !is_file($paths[0])) {
            throw new \RuntimeException('the deployed target repository does not contain one exact demo page artifact');
        }
        $bytes = file_get_contents($paths[0]);
        if (!is_string($bytes)) {
            throw new \RuntimeException('the deployed demo page artifact is unreadable');
        }
        [$front, $body] = \WPrism\Canon::parse_post_file($bytes);
        $expected = [
            'comment_status' => $front['comment_status'] ?? null,
            'content' => $body,
            'excerpt' => $front['excerpt'] ?? null,
            'menu_order' => $front['menu_order'] ?? null,
            'ping_status' => $front['ping_status'] ?? null,
            'slug' => $front['slug'] ?? null,
            'status' => $front['status'] ?? null,
            'title' => $front['title'] ?? null,
        ];
        if (($front['type'] ?? null) !== 'page'
            || array_filter($expected, static fn ($value): bool => !is_string($value) && !is_int($value)) !== []) {
            throw new \RuntimeException('the deployed demo page artifact has no complete authored field witness');
        }
        $php = '$page = get_page_by_path("wprism-demo-page", OBJECT, "page"); '
            . 'if (!$page) { throw new RuntimeException("demo page missing"); } '
            . '$value = ["comment_status" => (string) $page->comment_status, '
            . '"content" => (string) $page->post_content, "excerpt" => (string) $page->post_excerpt, '
            . '"menu_order" => (int) $page->menu_order, "ping_status" => (string) $page->ping_status, '
            . '"slug" => (string) $page->post_name, "status" => (string) $page->post_status, '
            . '"title" => (string) $page->post_title]; ksort($value, SORT_STRING); echo wp_json_encode($value);';
        $result = self::wp($session, 2, ['eval', $php]);
        $actual = json_decode(trim($result['stdout']), true);
        if ($result['exit'] !== 0 || !is_array($actual) || $actual !== $expected) {
            throw new \RuntimeException('the target page does not equal the exact captured authored page artifact');
        }
    }

    /**
     * Semantic repository bytes: refs/index plus every worktree entry, including
     * ignored WPrism control artifacts. Git's implementation-private object
     * store is excluded, while show-ref and the index/tree bind its meaning.
     */
    private static function repositorySnapshot(string $repository): string {
        $root = realpath($repository);
        if (!is_string($root) || $root === '' || is_link($root) || !is_dir($root)) {
            throw new \RuntimeException('the demo cannot snapshot a non-ordinary repository');
        }
        $facts = [
            'head' => self::git($root, ['rev-parse', '--verify', 'HEAD'])['stdout'],
            'index' => self::git($root, ['ls-files', '--stage'])['stdout'],
            'refs' => self::git($root, ['show-ref', '--head'])['stdout'],
            'status' => self::git($root, ['status', '--porcelain=v2', '--untracked-files=all'])['stdout'],
            'tree' => self::git($root, ['rev-parse', '--verify', 'HEAD^{tree}'])['stdout'],
        ];
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            $relative = substr($path, strlen($root) + 1);
            if ($relative === '.git' || str_starts_with($relative, '.git/')) {
                continue;
            }
            $stat = lstat($path);
            if (!is_array($stat)) {
                throw new \RuntimeException("the demo repository entry disappeared during snapshot: $relative");
            }
            $mode = sprintf('%o', ((int) $stat['mode']) & 07777);
            if ($entry->isLink()) {
                $target = readlink($path);
                if (!is_string($target)) {
                    throw new \RuntimeException("the demo repository symlink changed during snapshot: $relative");
                }
                $entries[$relative] = ['link', $mode, $target];
            } elseif ($entry->isDir()) {
                $entries[$relative] = ['directory', $mode];
            } elseif ($entry->isFile()) {
                $bytes = file_get_contents($path);
                if (!is_string($bytes)) {
                    throw new \RuntimeException("the demo repository file changed during snapshot: $relative");
                }
                $entries[$relative] = ['file', $mode, hash('sha256', $bytes)];
            } else {
                throw new \RuntimeException("the demo repository contains an unsupported entry: $relative");
            }
        }
        ksort($entries, SORT_STRING);
        $encoded = json_encode(['entries' => $entries, 'git' => $facts], JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \RuntimeException('the demo could not encode its repository snapshot');
        }

        return hash('sha256', $encoded);
    }

    /** @return array<string,mixed> */
    private static function lastJsonDocument(string $output): array {
        $lines = preg_split('/\R/', trim($output));
        if (!is_array($lines)) {
            throw new \RuntimeException('the release authorization preview was not line-delimited output');
        }
        for ($index = count($lines) - 1; $index >= 0; $index--) {
            $line = trim($lines[$index]);
            if ($line === '') {
                continue;
            }
            $document = json_decode($line, true);
            if (!is_array($document)) {
                break;
            }

            return $document;
        }
        throw new \RuntimeException('the release authorization preview had no final JSON document');
    }

    /** @param array<string,mixed> $session */
    private static function assertCoreReleasePlan(array $session, string $sourceRoot, string $revision): void {
        $sourceRepo = (string) $session['source_repo'];
        $targetRepo = (string) $session['target_repo'];
        $contract = (new ContractStore($sourceRepo))->readContract();
        $contractDigest = is_array($contract) ? ($contract['contract_digest'] ?? null) : null;
        if (!is_string($contractDigest)
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $contractDigest) !== 1
            || ($contract['declarations']['external_effects'] ?? null) !== []) {
            throw new \RuntimeException('the core demo has no accepted page-only contract to bind the release preview');
        }

        $sourceBefore = self::repositorySnapshot($sourceRepo);
        $targetBefore = self::repositorySnapshot($targetRepo);
        $runtimeBefore = self::targetRuntimeSnapshot($session);
        $result = self::runProcess(
            [
                self::demoCli($sourceRoot), 'release', 'demo-target', '--from=' . $revision,
                '--plan-only', '--format=json',
            ],
            $sourceRepo,
            [],
            false,
            self::LIVE_PROCESS_TIMEOUT_MILLISECONDS
        );
        $runtimeAfter = self::targetRuntimeSnapshot($session);
        $targetAfter = self::repositorySnapshot($targetRepo);
        $sourceAfter = self::repositorySnapshot($sourceRepo);
        if (!hash_equals($sourceBefore, $sourceAfter)
            || !hash_equals($targetBefore, $targetAfter)
            || !hash_equals($runtimeBefore, $runtimeAfter)) {
            throw new \RuntimeException(
                'the plan-only authorization preview changed source repository, target repository, or target runtime'
            );
        }
        if ($result['exit'] !== 0) {
            throw new \RuntimeException('the real page-only release authorization preview refused: '
                . trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']));
        }

        $plan = self::lastJsonDocument($result['stdout']);
        if (($plan['format'] ?? null) !== 'wprism-authorization-plan/v1'
            || ($plan['environment'] ?? null) !== 'demo-target'
            || !hash_equals($revision, (string) ($plan['code_revision_from'] ?? ''))
            || !hash_equals($contractDigest, (string) ($plan['contract_digest'] ?? ''))
            || ($plan['scope']['entities'] ?? null) !== ['create' => 0, 'delete' => 0, 'update' => 1]
            || ($plan['scope']['surfaces'] ?? null) !== ['post_type:page']
            || ($plan['scope']['code'] ?? null) !== [
                'lifecycle_phases' => ['verify'],
                'plugins_changed' => 0,
                'themes_changed' => 0,
            ]
            || ($plan['may_change']['authored_state'] ?? null) !== ['post_type:page']
            || ($plan['may_change']['code'] ?? null) !== []
            || ($plan['may_change']['external'] ?? null) !== []
            || ($plan['effects']['known_irreversible'] ?? null) !== []
            || !is_array($plan['effects'] ?? null)
            || !array_key_exists('lifecycle_window', $plan['effects'])
            || $plan['effects']['lifecycle_window'] !== null
            || ($plan['effects']['unknown_blocking'] ?? null) !== []) {
            throw new \RuntimeException(
                'the authorization preview was not exact, nonempty, page-scoped, revision/contract-bound and effect-clean'
            );
        }
    }

    private static function refusal(string $sourceRoot, string $name): int {
        $session = self::readSession($sourceRoot, $name);
        self::assertReady($session);
        $before = self::targetRuntimeSnapshot($session);
        $result = self::runWPrism(
            $session,
            $sourceRoot,
            $session['target_repo'],
            ['apply', 'demo-target', '--repo=/tmp/not-the-demo-target'],
            false,
            false
        );
        if ($result['exit'] === 0) {
            throw new \RuntimeException('the deliberate target-binding override unexpectedly succeeded');
        }
        $detail = $result['stderr'] !== '' ? $result['stderr'] : $result['stdout'];
        if (!str_contains($detail, 'apply received a host-owned target binding argument')) {
            throw new \RuntimeException('the deliberate check failed for an unexpected reason: ' . trim($detail));
        }
        $after = self::targetRuntimeSnapshot($session);
        if (!hash_equals($before, $after)) {
            throw new \RuntimeException('the deliberate refusal changed target runtime state');
        }
        echo trim($detail) . "\n";
        echo "PASS: WPrism refused a caller-supplied target binding and the target-local runtime proof stayed unchanged.\n";
        echo "Next:\n  " . escapeshellarg(self::demoCli($sourceRoot)) . " demo stop --name=$name\n";
        return 0;
    }

    private static function stop(string $sourceRoot, string $name, ?callable $phaseHook): int {
        $session = self::readSession($sourceRoot, $name, $phaseHook, true);
        self::teardownSession($sourceRoot, $session, true, $phaseHook);
        echo "Removed demo '$name': containers, volumes, databases, and its three disposable repositories.\n";
        return 0;
    }

    /** @return array<string,mixed> */
    private static function sessionShape(string $sourceRoot, array $options): array {
        $sandbox = $sourceRoot . '/sandbox';
        $name = (string) $options['name'];
        return [
            'format' => self::FORMAT,
            'name' => $name,
            'scenario' => (string) ($options['scenario'] ?? 'core'),
            'source_port' => $options['source_port'],
            'target_port' => $options['target_port'],
            'source_repo' => $sandbox . '/siterepo/' . $name . '1',
            'target_repo' => $sandbox . '/siterepo/' . $name . '2',
            'origin' => $sandbox . '/siterepo/origin-' . $name . '.git',
            'compose_file' => $sandbox . '/pair.yml',
            'compose_env_file' => $sandbox . '/tmp/demo-' . $name . '.env',
            'state_file' => $sandbox . '/tmp/demo-' . $name . '.json',
            'phase' => 'starting',
            'ownership_token' => is_string($options['ownership_token'] ?? null)
                ? $options['ownership_token']
                : bin2hex(random_bytes(32)),
            'runtime_before' => '',
            'last_applied_revision' => '',
            'pending_revision' => null,
            'owned_paths' => [
                'source_repo' => ['state' => 'planned', 'identity' => null],
                'target_repo' => ['state' => 'planned', 'identity' => null],
                'origin' => ['state' => 'planned', 'identity' => null],
                'compose_env_file' => ['state' => 'planned', 'identity' => null],
            ],
        ];
    }

    /** @param array<string,mixed> $session */
    private static function composeEnvBytes(array $session, string $sourceRoot): string {
        return '# WPRISM_DEMO_OWNER=' . $session['ownership_token'] . ":compose_env_file\n"
            . 'WPRISM_PAIR=' . $session['name'] . "\n"
            . 'WPRISM_PORT1=' . $session['source_port'] . "\n"
            . 'WPRISM_PORT2=' . $session['target_port'] . "\n"
            . 'WPRISM_AGENT_SRC=' . $sourceRoot . "/agent\n"
            . 'WPRISM_ADAPTER_PACKAGES_SRC=' . $sourceRoot . "/adapter-packages\n"
            . 'WPRISM_PLATFORM_SRC=' . $sourceRoot . "/platform\n"
            . 'WPRISM_CLI_IMAGE=' . self::CLI_IMAGE . "\n"
            . 'WPRISM_WP_IMAGE=' . self::WORDPRESS_IMAGE . "\n"
            . "WPRISM_DB_HOST=wprism-shared-db\n";
    }

    /** @return array<string,array<string,mixed>> */
    private static function coreOptionProfile(): array {
        $profile = [];
        foreach (self::CORE_AUTHORED_OPTIONS as $name) {
            $rule = ['autoload' => 'preserve', 'class' => 'authored'];
            if (isset(self::CORE_AUTHORED_REFS[$name])) {
                $rule['ref'] = self::CORE_AUTHORED_REFS[$name];
            }
            if (in_array($name, self::CORE_AUTHORED_LINT_OK, true)) {
                $rule['lint_ok'] = true;
            }
            $profile[$name] = $rule;
        }
        foreach (self::CORE_DERIVED_OPTIONS as $name) {
            $profile[$name] = ['class' => 'derived'];
        }
        foreach (self::CORE_RUNTIME_OPTIONS as $name) {
            $profile[$name] = ['class' => 'runtime'];
        }
        foreach (self::CORE_OPTIONAL_ENV_OPTIONS as $name) {
            $profile[$name] = ['class' => 'env', 'required' => false];
        }
        ksort($profile, SORT_STRING);
        if (count($profile) !== 79
            || ($profile['mailserver_pass'] ?? null) !== ['class' => 'env', 'required' => false]) {
            throw new \LogicException('the reviewed WordPress 7.1 demo option profile is internally inconsistent');
        }

        return $profile;
    }

    /** Prove the digest-pinned image itself carries the reviewed WordPress build before Compose starts it. */
    private static function assertWordPressImage(string $sourceRoot): void {
        $probe = '$wp_version = null; require "/usr/src/wordpress/wp-includes/version.php"; '
            . 'if (!is_string($wp_version)) { exit(2); } echo $wp_version;';
        $result = self::runProcess(
            ['docker', 'run', '--rm', '--entrypoint', 'php', self::WORDPRESS_IMAGE, '-r', $probe],
            $sourceRoot,
            [],
            false,
            self::LIVE_PROCESS_TIMEOUT_MILLISECONDS
        );
        if ($result['exit'] !== 0 || trim($result['stdout']) !== self::WORDPRESS_VERSION) {
            throw new \RuntimeException(
                'the digest-pinned demo image did not prove exact WordPress ' . self::WORDPRESS_VERSION
            );
        }
    }

    /** @param array<string,mixed> $session */
    private static function installWooCommerce(array $session, string $sourceRoot): void {
        $envFile = escapeshellarg((string) $session['compose_env_file']);
        $script = 'set -euo pipefail; PAIR_COMPOSE=(docker compose --env-file ' . $envFile
            . ' -f pair.yml -f pair.artifacts.yml); . bin/fetch-artifact.sh; '
            . 'for side in 1 2; do artifact=$(fetch_artifact woocommerce ' . self::WOO_VERSION
            . ' "cli$side" plugin); "${PAIR_COMPOSE[@]}" run --rm -T "cli$side" wp plugin install "$artifact" --force; done; '
            . 'for side in 1 2; do "${PAIR_COMPOSE[@]}" run --rm -T "cli$side" wp plugin activate woocommerce; done';
        $result = self::runProcess(
            ['bash', '-c', $script],
            $sourceRoot . '/sandbox',
            [],
            true,
            self::LIVE_PROCESS_TIMEOUT_MILLISECONDS
        );
        if ($result['exit'] !== 0) {
            throw new \RuntimeException('could not install the digest-pinned WooCommerce demo artifact');
        }
        self::establishHpos($session, 1);
        self::configureWooQualification($session, 1);
    }

    /** @param array<string,mixed> $session */
    private static function prepareSourceRepository(array $session, ?callable $phaseHook): void {
        self::mustRun(['git', 'init', '--bare', '--initial-branch=main', $session['origin']], null);
        if ($phaseHook !== null) {
            $phaseHook('origin_initialized');
        }
        $woocommerce = ($session['scenario'] ?? null) === 'woocommerce';
        $policy = [
            'manifests' => $woocommerce ? ['core', 'woocommerce'] : ['core'],
            'policy' => [
                'options' => $woocommerce ? (object) [] : self::coreOptionProfile(),
                'post_meta' => (object) [],
                'term_meta' => (object) [],
                'post_types' => $woocommerce
                    ? ['post', 'page', 'attachment', 'product', 'product_variation', 'shop_coupon']
                    : ['post', 'page', 'attachment'],
                'taxonomies' => $woocommerce ? [
                    'category', 'post_tag', 'product_brand', 'product_cat', 'product_shipping_class',
                    'product_tag', 'product_type', 'product_visibility',
                ] : ['category', 'post_tag'],
            ],
            'spec_version' => 3,
        ];
        $bytes = json_encode($policy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($bytes)) {
            throw new \RuntimeException('could not encode demo policy');
        }
        self::writeNew($session['source_repo'] . '/site.wprism.json', $bytes . "\n", 0644);
        self::writeNew($session['source_repo'] . '/.gitignore', Adopt::repositoryGitignoreBytes(), 0644);
        self::writeOverlay($session, $session['source_repo']);
        self::mustRun(['git', 'init', '--initial-branch=main', $session['source_repo']], null);
        self::git($session['source_repo'], ['remote', 'add', 'origin', $session['origin']]);
        self::git($session['source_repo'], ['add', 'site.wprism.json', '.gitignore']);
        self::git($session['source_repo'], [
            '-c', 'user.name=wprism-demo', '-c', 'user.email=demo@example.test',
            'commit', '-m', 'demo: declare ' . ($woocommerce ? 'WooCommerce' : 'core') . ' managed scope',
        ]);
        self::git($session['source_repo'], ['push', '-u', 'origin', 'main']);
    }

    /** @param array<string,mixed> $session */
    private static function writeOverlay(array $session, string $repo): void {
        $config = static fn(string $service): array => [
            'transport' => 'docker',
            'compose_file' => $session['compose_file'],
            'compose_env_file' => $session['compose_env_file'],
            'service' => $service,
            'repo_path' => '/siterepo',
        ];
        $bytes = json_encode(
            ['envs' => ['demo-source' => $config('cli1'), 'demo-target' => $config('cli2')]],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($bytes)) {
            throw new \RuntimeException('could not encode demo environment registry');
        }
        self::writeNew($repo . '/.wprism-envs.json', $bytes . "\n", 0600);
    }

    /** @param array<string,mixed> $session */
    private static function seedSourceContent(array $session): void {
        if (($session['scenario'] ?? null) === 'core') {
            $result = self::wp($session, 1, [
                'post', 'create', '--post_type=page', '--post_status=publish',
                '--post_title=WPrism Demo Page', '--post_name=wprism-demo-page',
                '--post_content=Edit this source page, capture it, and apply the reviewed Git revision.',
                '--porcelain',
            ]);
            if ($result['exit'] !== 0 || preg_match('/^[0-9]+$/D', trim($result['stdout'])) !== 1) {
                throw new \RuntimeException('could not seed the source core page');
            }
            return;
        }
        $php = '$product = new WC_Product_Simple(); '
            . '$product->set_name("WPrism Demo Mug"); $product->set_slug("wprism-demo-mug"); '
            . '$product->set_regular_price("24.00"); $product->set_manage_stock(true); '
            . '$product->set_stock_quantity(12); $product->set_status("publish"); $product->save(); echo $product->get_id();';
        $result = self::wp($session, 1, ['eval', $php]);
        if ($result['exit'] !== 0 || preg_match('/^[0-9]+$/D', trim($result['stdout'])) !== 1) {
            throw new \RuntimeException('could not seed the source WooCommerce product');
        }
    }

    /** @param array<string,mixed> $session */
    private static function cloneTargetRepository(array $session): void {
        $entries = array_values(array_diff(scandir($session['target_repo']) ?: [], ['.', '..']));
        if ($entries !== []) {
            throw new \RuntimeException('target demo repository was not empty before clone');
        }
        self::mustRun(['git', 'clone', '--branch', 'main', $session['origin'], $session['target_repo']], null);
        self::writeOverlay($session, $session['target_repo']);
    }

    /** @param array<string,mixed> $session */
    private static function seedTargetRuntime(array $session): string {
        if (($session['scenario'] ?? null) === 'core') {
            $php = '$page = get_page_by_path("wprism-demo-page", OBJECT, "page"); '
                . 'if (!$page) { throw new RuntimeException("demo page missing"); } '
                . '$id = wp_insert_comment(["comment_post_ID" => $page->ID, "comment_content" => "Target-only review note", '
                . '"comment_author" => "Demo reviewer", "comment_approved" => "1"]); '
                . 'if (!$id) { throw new RuntimeException("could not create target comment"); } echo $id;';
            $created = self::wp($session, 2, ['eval', $php]);
            if ($created['exit'] !== 0 || preg_match('/^[0-9]+$/D', trim($created['stdout'])) !== 1) {
                throw new \RuntimeException('could not seed target-only core runtime');
            }
            return self::targetRuntimeSnapshot($session);
        }
        $php = '$product = get_page_by_path("wprism-demo-mug", OBJECT, "product"); '
            . 'if (!$product) { throw new RuntimeException("demo product missing"); } '
            . '$order = wc_create_order(); $order->add_product(wc_get_product($product->ID), 1); '
            . '$order->calculate_totals(); $order->update_status("processing"); '
            . 'echo $order->get_id();';
        $created = self::wp($session, 2, ['eval', $php]);
        if ($created['exit'] !== 0 || preg_match('/^[0-9]+$/D', trim($created['stdout'])) !== 1) {
            throw new \RuntimeException('could not seed target-only order runtime');
        }
        return self::targetRuntimeSnapshot($session);
    }

    /**
     * Give apply a target-local product identity to adopt. Its stock quantity
     * is runtime and therefore must survive while the authored title/price
     * converge from Git. Starting at 37 lets WooCommerce's native processing
     * transition decrement it to 36 without manufacturing an out-of-stock
     * visibility projection in managed taxonomy state.
     *
     * @param array<string,mixed> $session
     */
    private static function seedTargetManagedContent(array $session): void {
        if (($session['scenario'] ?? null) === 'core') {
            $result = self::wp($session, 2, [
                'post', 'create', '--post_type=page', '--post_status=publish',
                '--post_title=Target-local placeholder', '--post_name=wprism-demo-page',
                '--post_content=This value must converge from Git.', '--porcelain',
            ]);
            if ($result['exit'] !== 0 || preg_match('/^[0-9]+$/D', trim($result['stdout'])) !== 1) {
                throw new \RuntimeException('could not seed the target-local core page');
            }
            return;
        }
        $php = '$product = new WC_Product_Simple(); '
            . '$product->set_name("Target-local placeholder"); $product->set_slug("wprism-demo-mug"); '
            . '$product->set_regular_price("1.00"); $product->set_manage_stock(true); '
            . '$product->set_stock_quantity(37); $product->set_status("publish"); $product->save(); echo $product->get_id();';
        $result = self::wp($session, 2, ['eval', $php]);
        if ($result['exit'] !== 0 || preg_match('/^[0-9]+$/D', trim($result['stdout'])) !== 1) {
            throw new \RuntimeException('could not seed the target-local product runtime');
        }
    }

    /** Provision the core binding set through env-set without exposing values in host argv or output. */
    private static function provisionCoreEnvironment(array $session): void {
        $script = <<<'SH'
set -eu
for name in admin_email home siteurl; do
  value="$(wp option get "$name")"
  [ -n "$value" ]
  printf '%s\n' "$value" | wp wprism env-set --repo=/siterepo --name="$name" --stdin >/dev/null
done
SH;
        $result = self::runProcess([
            'docker', 'compose', '--env-file', $session['compose_env_file'], '-f', $session['compose_file'],
            'run', '--rm', '-T', 'cli2', 'sh', '-c', $script,
        ], dirname((string) $session['compose_file']), [], false, self::LIVE_PROCESS_TIMEOUT_MILLISECONDS);
        if ($result['exit'] !== 0) {
            throw new \RuntimeException('could not provision the core demo environment: ' . trim($result['stderr']));
        }
    }

    /** @param array<string,mixed> $session */
    private static function assertCoreProfileCapture(array $session): void {
        $path = (string) $session['source_repo'] . '/state/options/core.json';
        $document = is_file($path) && !is_link($path)
            ? json_decode((string) file_get_contents($path), true)
            : null;
        $records = is_array($document) && is_array($document['records'] ?? null)
            ? $document['records']
            : null;
        if (!is_array($records)) {
            throw new \RuntimeException('the core demo capture did not publish its canonical option document');
        }
        foreach (self::CORE_AUTHORED_OPTIONS as $name) {
            if (!is_array($records[$name] ?? null) || ($records[$name]['state'] ?? null) !== 'present') {
                throw new \RuntimeException("the reviewed core profile did not capture authored option $name");
            }
        }
        foreach (array_merge(
            self::CORE_DERIVED_OPTIONS,
            self::CORE_RUNTIME_OPTIONS,
            self::CORE_OPTIONAL_ENV_OPTIONS
        ) as $name) {
            if (array_key_exists($name, $records)) {
                throw new \RuntimeException("the reviewed core profile captured excluded option $name");
            }
        }

        $categoryRef = $records['default_email_category']['value'] ?? null;
        if (!is_string($categoryRef)
            || preg_match('/^\{\{term:([a-f0-9-]{36})\}\}$/D', $categoryRef, $match) !== 1) {
            throw new \RuntimeException(
                'default_email_category did not resolve through the term-ref product path; refusing to weaken it'
            );
        }
        $term = glob((string) $session['source_repo'] . '/state/terms/category/' . $match[1] . '--*.json');
        if (!is_array($term) || count($term) !== 1 || !is_file($term[0])) {
            throw new \RuntimeException('default_email_category resolved to no captured category entity');
        }
        if (array_key_exists('mailserver_pass', $records)) {
            throw new \RuntimeException('mailserver_pass crossed the environment boundary into authored state');
        }
    }

    /** @param array<string,mixed> $session */
    private static function assertCoreOptionInventory(array $session, string $sourceRoot): void {
        $sidebarOptions = var_export(self::CORE_SIDEBAR_OPTIONS, true);
        $namesResult = self::wp($session, 2, ['eval',
            'global $wpdb; $names = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} '
                . 'ORDER BY option_name ASC"); '
                . '$policy = \\WPrism\\Policy::load("/siterepo"); '
                . '$expectedExact = array_keys($policy->exact_options()); sort($expectedExact, SORT_STRING); '
                . '$expectedSidebar = ' . $sidebarOptions . '; '
                . '$stylesheet = (string) get_option("stylesheet"); '
                . '$expectedDynamic = $stylesheet === "" '
                . '? ["theme_mods_<missing-active-stylesheet>"] : ["theme_mods_" . $stylesheet]; '
                . '$missingExact = array_values(array_diff($expectedExact, $names)); '
                . '$missingSidebar = array_values(array_diff($expectedSidebar, $names)); '
                . '$missingDynamic = array_values(array_diff($expectedDynamic, $names)); '
                . '$exactSet = array_fill_keys($expectedExact, true); $unseen = []; '
                . 'foreach ($names as $name) { if (isset($exactSet[$name]) '
                . '|| \\WPrism\\SidebarState::owns_option($name) '
                . '|| $policy->option_rule($name) !== null '
                . '|| $policy->dynamic_option_rule_for_prefix($name) !== null) { continue; } '
                . '$unseen[] = $name; } '
                . 'echo wp_json_encode(["names" => $names, "missing_exact" => $missingExact, '
                . '"missing_sidebar" => $missingSidebar, "missing_dynamic" => $missingDynamic, '
                . '"unseen" => $unseen]);',
        ]);
        $inventory = json_decode(trim($namesResult['stdout']), true);
        $inventoryKeys = ['names', 'missing_exact', 'missing_sidebar', 'missing_dynamic', 'unseen'];
        if ($namesResult['exit'] !== 0 || !is_array($inventory) || array_keys($inventory) !== $inventoryKeys) {
            throw new \RuntimeException('the core demo could not enumerate its exact live option inventory');
        }
        foreach ($inventoryKeys as $key) {
            if (!is_array($inventory[$key]) || !array_is_list($inventory[$key])
                || array_filter($inventory[$key], static fn ($name): bool => !is_string($name)) !== []) {
                throw new \RuntimeException('the core demo returned a malformed live option mechanism witness');
            }
        }
        $names = $inventory['names'];
        $profileNames = array_keys(self::coreOptionProfile());
        $missing = array_values(array_diff($profileNames, $names));
        if (count($names) !== self::CORE_OPTION_TOTAL || count(array_unique($names)) !== count($names)
            || $missing !== [] || $inventory['missing_exact'] !== [] || $inventory['missing_sidebar'] !== []
            || $inventory['missing_dynamic'] !== [] || $inventory['unseen'] !== []) {
            throw new \RuntimeException(
                'the WordPress 7.1 option inventory moved: total=' . count($names)
                    . ', missing_expected=' . implode(',', $missing)
                    . ', missing_exact=' . implode(',', $inventory['missing_exact'])
                    . ', missing_sidebar=' . implode(',', $inventory['missing_sidebar'])
                    . ', missing_dynamic=' . implode(',', $inventory['missing_dynamic'])
                    . ', unseen=' . implode(',', $inventory['unseen'])
            );
        }

        $result = self::runWPrism(
            $session,
            $sourceRoot,
            (string) $session['source_repo'],
            ['coverage', 'demo-target', '--format=json'],
            false,
            false
        );
        $report = json_decode($result['stdout'], true);
        $options = is_array($report['options'] ?? null) ? $report['options'] : [];
        $invariant = (int) ($options['captured'] ?? -1)
            + (int) ($options['declared_excluded'] ?? -1)
            + (int) ($options['pending'] ?? -1)
            + (int) ($options['invisible_total'] ?? -1);
        if ($result['exit'] !== 0
            || ($report['format'] ?? null) !== 'wprism-coverage-report/v1'
            || ($options['total'] ?? null) !== self::CORE_OPTION_TOTAL
            || ($options['captured'] ?? null) !== 94
            || ($options['declared_excluded'] ?? null) !== 40
            || ($options['declared_excluded_by_class'] ?? null) !== [
                'derived' => 14,
                'env' => 10,
                'runtime' => 16,
            ]
            || ($options['pending'] ?? null) !== 0
            || ($options['invisible_total'] ?? null) !== 0
            || $invariant !== self::CORE_OPTION_TOTAL) {
            throw new \RuntimeException(
                'the live option inventory is not exactly covered by policy and dedicated mechanisms'
            );
        }
    }

    /** @param array<string,mixed> $session */
    private static function targetRuntimeSnapshot(array $session): string {
        if (($session['scenario'] ?? null) === 'core') {
            $php = '$page = get_page_by_path("wprism-demo-page", OBJECT, "page"); '
                . 'if (!$page) { throw new RuntimeException("demo page missing"); } '
                . 'global $wpdb; $wpdb->last_error = ""; '
                . '$commentIds = $wpdb->get_col($wpdb->prepare("SELECT comment_ID FROM {$wpdb->comments} '
                . 'WHERE comment_post_ID = %d AND comment_approved = %s ORDER BY comment_ID ASC", $page->ID, "1")); '
                . 'if (!is_array($commentIds) || count($commentIds) !== 1 || $wpdb->last_error !== "" '
                . '|| !is_string($commentIds[0]) || preg_match("/^[1-9][0-9]*$/D", $commentIds[0]) !== 1) '
                . '{ throw new RuntimeException("demo comment missing"); } '
                . '$commentId = (int) $commentIds[0]; $wpdb->last_error = ""; '
                . '$comment = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->comments} WHERE comment_ID = %d", '
                . '$commentId), ARRAY_A); if (!is_array($comment) || $wpdb->last_error !== "") '
                . '{ throw new RuntimeException("demo comment row unreadable"); } ksort($comment, SORT_STRING); '
                . '$wpdb->last_error = ""; $commentmeta = $wpdb->get_results($wpdb->prepare('
                . '"SELECT * FROM {$wpdb->commentmeta} WHERE comment_id = %d ORDER BY meta_id ASC", $commentId), ARRAY_A); '
                . 'if (!is_array($commentmeta) || !array_is_list($commentmeta) || $wpdb->last_error !== "") '
                . '{ throw new RuntimeException("demo comment metadata unreadable"); } '
                . 'foreach ($commentmeta as &$metaRow) { if (!is_array($metaRow)) '
                . '{ throw new RuntimeException("demo comment metadata malformed"); } ksort($metaRow, SORT_STRING); } unset($metaRow); '
                . '$commentBytes = wp_json_encode($comment, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); '
                . '$commentmetaBytes = wp_json_encode($commentmeta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); '
                . 'if (!is_string($commentBytes) || !is_string($commentmetaBytes)) '
                . '{ throw new RuntimeException("demo comment witness could not be encoded"); } '
                . '$value = ["comment_id" => $commentId, "comment_row_sha256" => hash("sha256", $commentBytes), '
                . '"commentmeta_rows" => count($commentmeta), "commentmeta_sha256" => hash("sha256", $commentmetaBytes)]; '
                . 'ksort($value); echo wp_json_encode($value);';
            $result = self::wp($session, 2, ['eval', $php]);
            $value = trim($result['stdout']);
            if ($result['exit'] !== 0 || !is_array(json_decode($value, true))) {
                throw new \RuntimeException('could not verify target-only core runtime');
            }
            return $value;
        }
        $php = '$ids = wc_get_orders(["limit" => -1, "return" => "ids"]); sort($ids, SORT_NUMERIC); '
            . '$product = get_page_by_path("wprism-demo-mug", OBJECT, "product"); '
            . '$order = count($ids) === 1 ? wc_get_order($ids[0]) : null; '
            . 'if (!$product || !$order) { throw new RuntimeException("demo runtime missing"); } '
            . '$value = ["order_id" => (int) $order->get_id(), "status" => $order->get_status(), '
            . '"total" => $order->get_total(), "items" => count($order->get_items()), '
            . '"stock" => wc_get_product($product->ID)->get_stock_quantity()]; ksort($value); echo wp_json_encode($value);';
        $result = self::wp($session, 2, ['eval', $php]);
        $value = trim($result['stdout']);
        if ($result['exit'] !== 0 || !is_array(json_decode($value, true))) {
            throw new \RuntimeException('could not verify target order/stock runtime');
        }
        return $value;
    }

    /** @param array<string,mixed> $session */
    private static function establishHpos(array $session, int $side): void {
        $php = 'WC_Install::maybe_enable_hpos(); WC_Install::create_tables(); '
            . 'if (!\\Automattic\\WooCommerce\\Utilities\\OrderUtil::custom_orders_table_usage_is_enabled()) '
            . '{ throw new RuntimeException("HPOS unavailable"); }';
        $result = self::wp($session, $side, ['eval', $php]);
        if ($result['exit'] !== 0) {
            throw new \RuntimeException("WooCommerce HPOS setup failed on side $side: " . trim($result['stderr']));
        }
    }

    /** @param array<string,mixed> $session */
    private static function configureWooQualification(array $session, int $side): void {
        $constant = self::wp($session, $side, [
            'config', 'set', 'WOOCOMMERCE_BIS_ALPHA_ENABLED', 'true', '--raw', '--type=constant',
        ]);
        if ($constant['exit'] !== 0) {
            throw new \RuntimeException("WooCommerce qualification constant setup failed on side $side");
        }
        // Stock-notification schema is conditional on the constant above. The
        // activation-time WC_Install call ran before that constant existed, so
        // create the native tables in this fresh process before products can
        // fire Woo 11.0.1's StockSyncController callbacks.
        $enable = 'WC_Install::create_tables(); '
            . '$features = wc_get_container()->get(\\Automattic\\WooCommerce\\Internal\\Features\\FeaturesController::class); '
            . 'if (!$features->feature_is_enabled("fulfillments") '
            . '&& !$features->change_feature_enable("fulfillments", true)) '
            . '{ throw new RuntimeException("could not enable native fulfillments"); }';
        $enabled = self::wp($session, $side, ['eval', $enable]);
        if ($enabled['exit'] !== 0) {
            throw new \RuntimeException("WooCommerce fulfillment feature setup failed on side $side");
        }
        $migration = self::wp($session, $side, ['action-scheduler', 'migrate']);
        if ($migration['exit'] !== 0) {
            throw new \RuntimeException("WooCommerce Action Scheduler migration failed on side $side");
        }
        // The feature option changes after init in the command above. A fresh
        // WordPress request must run Woo's own init lifecycle so its taxonomy,
        // tables, marker, Action Scheduler, and stock-retention hooks are the
        // native 11.0.1 projection the shipped providers verify.
        $verify = 'global $wpdb; '
            . '$stock_table = $wpdb->prefix . "wc_stock_notifications"; '
            . '$stock_meta_table = $wpdb->prefix . "wc_stock_notificationmeta"; '
            . '$table_exists = static fn (string $table): bool => $wpdb->get_var($wpdb->prepare('
            . '"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() '
            . 'AND BINARY TABLE_NAME = BINARY %s", $table)) === $table; '
            . '$features = wc_get_container()->get(\\Automattic\\WooCommerce\\Internal\\Features\\FeaturesController::class); '
            . 'if (!$features->feature_is_enabled("fulfillments") '
            . '|| !taxonomy_exists("wc_fulfillment_shipping_provider") '
            . '|| get_option("woocommerce_fulfillments_db_tables_created") !== "1" '
            . '|| !$table_exists($stock_table) || !$table_exists($stock_meta_table) '
            . '|| !\\Automattic\\WooCommerce\\Admin\\Features\\Features::is_enabled("analytics-scheduled-import") '
            . '|| get_class(\\ActionScheduler::store()) !== "ActionScheduler_DBStore" '
            . '|| !defined("WOOCOMMERCE_BIS_ALPHA_ENABLED") || WOOCOMMERCE_BIS_ALPHA_ENABLED !== true) '
            . '{ throw new RuntimeException("WooCommerce qualification prerequisites are incomplete"); }';
        $verified = self::wp($session, $side, ['eval', $verify]);
        if ($verified['exit'] !== 0) {
            throw new \RuntimeException(
                "WooCommerce qualification lifecycle failed on side $side: " . trim($verified['stderr'])
            );
        }
    }

    /** @param array<string,mixed> $session */
    private static function assertCapabilityQualification(
        array $session,
        string $sourceRoot,
        string $environment,
        string $repository
    ): void {
        $result = self::runWPrism(
            $session,
            $sourceRoot,
            $repository,
            ['capabilities', $environment, '--operation=promote', '--format=json'],
            false,
            false
        );
        $report = json_decode($result['stdout'], true);
        if (!is_array($report) || ($report['ready'] ?? null) !== true) {
            $blockers = is_array($report['blockers'] ?? null) ? $report['blockers'] : [];
            $detail = json_encode($blockers, JSON_UNESCAPED_SLASHES);
            throw new \RuntimeException(
                "demo environment $environment is not release-qualified: "
                . (is_string($detail) ? $detail : 'capability report was malformed')
            );
        }
    }

    /** @return array<string,mixed> the bounded, ready view summary */
    private static function assertCoreAssessment(array $session, string $sourceRoot): array {
        $result = self::runWPrism(
            $session,
            $sourceRoot,
            (string) $session['source_repo'],
            ['assess', 'demo-target', '--operation=release', '--limit=10', '--format=json'],
            false,
            false
        );
        $report = json_decode($result['stdout'], true);
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $page = is_array($report['page'] ?? null) ? $report['page'] : [];
        $rows = is_array($report['rows'] ?? null) ? $report['rows'] : null;
        if ($result['exit'] !== 0
            || !is_array($report)
            || ($report['format'] ?? null) !== 'wprism-assess-view/v1'
            || ($summary['readiness'] ?? null) !== 'ready'
            || ($summary['counts']['invisible_option_names'] ?? null) !== 0
            || ($summary['counts']['pending_classifications'] ?? null) !== 0
            || ($summary['counts']['undeclared_tables'] ?? null) !== 0
            || ($summary['dispositions']['agree'] ?? null) !== true
            || !is_int($page['shown'] ?? null)
            || $page['shown'] < 0
            || $page['shown'] > 10
            || $rows === null
            || count($rows) !== $page['shown']) {
            $detail = json_encode([
                'exit' => $result['exit'],
                'format' => $report['format'] ?? null,
                'readiness' => $summary['readiness'] ?? null,
                'counts' => $summary['counts'] ?? null,
                'adoption' => $summary['authority']['adoption'] ?? null,
                'evidence' => $summary['evidence'] ?? null,
                'dispositions' => $summary['dispositions'] ?? null,
                'shown' => $page['shown'] ?? null,
            ], JSON_UNESCAPED_SLASHES);
            throw new \RuntimeException(
                'core demo did not produce a complete bounded release assessment: '
                    . (is_string($detail) ? $detail : 'summary unavailable')
            );
        }
        return $summary;
    }

    /** @param array<string,mixed> $session @return array{exit:int,stdout:string,stderr:string} */
    private static function wp(array $session, int $side, array $args): array {
        return self::runProcess(array_merge([
            'docker', 'compose', '--env-file', $session['compose_env_file'], '-f', $session['compose_file'],
            'run', '--rm', '-T', 'cli' . $side, 'wp',
        ], $args), dirname((string) $session['compose_file']));
    }

    /** @param array<string,mixed> $session @return array{exit:int,stdout:string,stderr:string} */
    private static function runWPrism(
        array $session,
        string $sourceRoot,
        string $cwd,
        array $args,
        bool $passthrough,
        bool $mustSucceed = true
    ): array {
        $result = self::runProcess(
            array_merge([$sourceRoot . '/cli/wprism'], $args),
            $cwd,
            [],
            $passthrough,
            $passthrough ? self::LIVE_PROCESS_TIMEOUT_MILLISECONDS : 30000
        );
        if ($mustSucceed && $result['exit'] !== 0) {
            throw new \RuntimeException('WPrism command failed: ' . implode(' ', $args));
        }
        return $result;
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private static function git(string $repo, array $args): array {
        return self::mustRun(array_merge(['git', '-C', $repo], $args), null);
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private static function mustRun(array $argv, ?string $cwd): array {
        $result = self::runProcess($argv, $cwd);
        if ($result['exit'] !== 0) {
            throw new \RuntimeException(implode(' ', $argv) . ': ' . trim($result['stderr']));
        }
        return $result;
    }

    /** @param array<string,mixed> $extraEnv @return array{exit:int,stdout:string,stderr:string} */
    private static function runProcess(
        array $argv,
        ?string $cwd,
        array $extraEnv = [],
        bool $passthrough = false,
        int $timeoutMilliseconds = 30000
    ): array {
        return HostProcess::run($argv, $cwd, $extraEnv, $passthrough, $timeoutMilliseconds);
    }

    /** @return array<string,mixed> */
    private static function readSession(
        string $sourceRoot,
        string $name,
        ?callable $phaseHook = null,
        bool $acceptLegacy = false
    ): array {
        $stateFile = $sourceRoot . '/sandbox/tmp/demo-' . $name . '.json';
        self::restoreClaimedSession($stateFile, $name);
        $handle = !is_link($stateFile) && is_file($stateFile) ? @fopen($stateFile, 'rb') : false;
        $opened = is_resource($handle) ? fstat($handle) : false;
        $bytes = is_resource($handle) ? stream_get_contents($handle) : false;
        $named = @lstat($stateFile);
        if (is_resource($handle)) {
            fclose($handle);
        }
        $data = is_string($bytes) ? json_decode($bytes, true) : null;
        $legacy = $acceptLegacy && is_array($data) && self::isLegacySession($data);
        if (!is_array($data)
            || !is_array($opened) || !is_array($named)
            || $opened['dev'] !== $named['dev'] || $opened['ino'] !== $named['ino']
            || is_link($stateFile) || !is_file($stateFile)
            || (!$legacy && ($data['format'] ?? null) !== self::FORMAT)
            || ($data['name'] ?? null) !== $name
            || (!$legacy && !in_array($data['scenario'] ?? null, ['core', 'woocommerce'], true))
            || !is_int($data['source_port'] ?? null)
            || !is_int($data['target_port'] ?? null)) {
            throw new \RuntimeException("demo '$name' is not active; run `wprism demo start --name=$name`");
        }
        $scenario = $legacy ? 'woocommerce' : (string) $data['scenario'];
        self::port((string) $data['source_port'], 'stored source port');
        self::port((string) $data['target_port'], 'stored target port');
        $expected = self::sessionShape($sourceRoot, [
            'name' => $name,
            'source_port' => $data['source_port'],
            'target_port' => $data['target_port'],
            'scenario' => $scenario,
            'ownership_token' => $data['ownership_token'] ?? null,
        ]);
        foreach (['source_repo', 'target_repo', 'origin', 'compose_file', 'compose_env_file', 'state_file'] as $field) {
            if (($data[$field] ?? null) !== $expected[$field]) {
                throw new \RuntimeException("demo '$name' session does not authorize its $field path");
            }
        }
        if (!in_array($data['phase'] ?? null, ['starting', 'review_required', 'ready', 'stopping'], true)
            || !is_string($data['ownership_token'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', (string) $data['ownership_token']) !== 1
            || !is_string($data['runtime_before'] ?? null)
            || !is_string($data['last_applied_revision'] ?? null)
            || (($data['last_applied_revision'] ?? '') !== ''
                && preg_match('/^[a-f0-9]{40}$/D', (string) $data['last_applied_revision']) !== 1)
            || (!is_null($data['pending_revision'] ?? null)
                && (!is_string($data['pending_revision'])
                    || preg_match('/^[a-f0-9]{40}$/D', $data['pending_revision']) !== 1))
            || !self::validOwnedPaths($data['owned_paths'] ?? null)) {
            throw new \RuntimeException("demo '$name' session lifecycle state is malformed");
        }
        $identity = self::identityFromStat($opened, 'file');
        if ($phaseHook !== null) {
            $phaseHook('state_file_read');
        }
        if (self::pathIdentity($stateFile) !== $identity) {
            throw new \RuntimeException("demo '$name' session identity changed while it was read");
        }
        $data['_state_identity'] = $identity;
        if ($legacy) {
            // V1 was emitted only by the Woo journey. Migrate that one exact
            // historical shape after every path, lifecycle and opened-inode
            // proof has passed; accepting a partial/hybrid shape here would
            // turn recovery into an unreviewed compatibility parser.
            $data['format'] = self::FORMAT;
            $data['scenario'] = 'woocommerce';
            self::replaceSession($data);
        }
        return $data;
    }

    /** @param array<string,mixed> $session */
    private static function isLegacySession(array $session): bool {
        if (($session['format'] ?? null) !== self::LEGACY_FORMAT) {
            return false;
        }
        $keys = array_keys($session);
        $expected = self::LEGACY_KEYS;
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);

        return $keys === $expected;
    }

    private static function restoreClaimedSession(string $stateFile, string $name): void {
        $claims = glob($stateFile . '.remove-*', GLOB_NOSORT);
        if (!is_array($claims) || $claims === []) {
            return;
        }
        if (file_exists($stateFile) || is_link($stateFile) || count($claims) !== 1) {
            throw new \RuntimeException("demo '$name' has an ambiguous interrupted session cleanup");
        }
        $claim = $claims[0];
        $bytes = !is_link($claim) && is_file($claim) ? file_get_contents($claim) : false;
        $session = is_string($bytes) ? json_decode($bytes, true) : null;
        $token = is_array($session) ? ($session['ownership_token'] ?? null) : null;
        $owned = is_array($session) ? ($session['owned_paths'] ?? null) : null;
        $supported = is_array($session)
            && (self::isLegacySession($session)
                || (($session['format'] ?? null) === self::FORMAT
                    && in_array($session['scenario'] ?? null, ['core', 'woocommerce'], true)));
        if (!is_string($token)
            || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1
            || $claim !== $stateFile . '.remove-' . $token
            || !$supported
            || ($session['name'] ?? null) !== $name
            || ($session['state_file'] ?? null) !== $stateFile
            || ($session['phase'] ?? null) !== 'stopping'
            || !self::validOwnedPaths($owned)
            || array_filter(
                $owned,
                static fn(array $row): bool => $row['state'] !== 'deleted' || $row['identity'] !== null
            ) !== []) {
            throw new \RuntimeException("demo '$name' interrupted session claim is not owned cleanup state");
        }
        $identity = self::pathIdentity($claim);
        if (file_exists($stateFile) || is_link($stateFile) || !@rename($claim, $stateFile)
            || self::pathIdentity($stateFile) !== $identity) {
            throw new \RuntimeException("demo '$name' could not resume its interrupted session cleanup");
        }
    }

    /** @param array<string,mixed> $session */
    private static function writeSession(array &$session): void {
        $bytes = json_encode(self::persistedSession($session), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($bytes)) {
            throw new \RuntimeException('could not encode demo session');
        }
        $session['_state_identity'] = self::writeNew((string) $session['state_file'], $bytes . "\n", 0600);
    }

    /** @param array<string,mixed> $session */
    private static function replaceSession(array &$session): void {
        $path = (string) $session['state_file'];
        $expected = $session['_state_identity'] ?? null;
        if (!is_array($expected) || self::pathIdentity($path) !== $expected) {
            throw new \RuntimeException('demo session boundary changed before update');
        }
        $bytes = json_encode(self::persistedSession($session), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($bytes)) {
            throw new \RuntimeException('could not encode demo session');
        }
        $stage = $path . '.next-' . bin2hex(random_bytes(16));
        $stageIdentity = self::writeNew($stage, $bytes . "\n", 0600);
        if (self::pathIdentity($path) !== $expected || !@rename($stage, $path)) {
            if ((file_exists($stage) || is_link($stage))
                && self::pathIdentity($stage) === $stageIdentity) {
                @unlink($stage);
            }
            throw new \RuntimeException('could not atomically update the demo session');
        }
        if (self::pathIdentity($path) !== $stageIdentity) {
            throw new \RuntimeException('demo session publication identity changed');
        }
        $session['_state_identity'] = $stageIdentity;
    }

    /** @return array{dev:string,ino:string,type:string} */
    private static function writeNew(string $path, string $bytes, int $mode): array {
        if (file_exists($path) || is_link($path)) {
            throw new \RuntimeException("refusing to overwrite $path");
        }
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('could not create ' . dirname($path));
        }
        $handle = @fopen($path, 'x+b');
        if (!is_resource($handle)) {
            throw new \RuntimeException("could not exclusively create $path");
        }
        $written = 0;
        $identity = null;
        try {
            while ($written < strlen($bytes)) {
                $count = fwrite($handle, substr($bytes, $written));
                if (!is_int($count) || $count < 1) {
                    throw new \RuntimeException("could not write $path");
                }
                $written += $count;
            }
            $created = fstat($handle);
            $current = @lstat($path);
            if (!is_array($created) || !is_array($current)
                || $created['dev'] !== $current['dev'] || $created['ino'] !== $current['ino']
                || !@chmod($path, $mode)
                || !fflush($handle)
                || (function_exists('fsync') && !fsync($handle))) {
                throw new \RuntimeException("could not sync $path");
            }
            $identity = self::identityFromStat($created, 'file');
        } catch (\Throwable $error) {
            $created = fstat($handle);
            fclose($handle);
            $current = lstat($path);
            if (is_array($created) && is_array($current)
                && $created['dev'] === $current['dev'] && $created['ino'] === $current['ino']) {
                @unlink($path);
            }
            throw $error;
        }
        fclose($handle);
        if (!is_array($identity) || self::pathIdentity($path) !== $identity) {
            throw new \RuntimeException("created file identity changed before receipt: $path");
        }
        return $identity;
    }

    /** @param array<string,mixed> $session @return array<string,mixed> */
    private static function persistedSession(array $session): array {
        unset($session['_state_identity']);
        return $session;
    }

    private static function destroyPair(string $sourceRoot, string $name, bool $passthrough): int {
        return self::runProcess(
            ['bash', $sourceRoot . '/sandbox/bin/pair.sh', 'destroy', $name],
            $sourceRoot,
            [],
            $passthrough,
            self::LIVE_PROCESS_TIMEOUT_MILLISECONDS
        )['exit'];
    }

    /** @param array<string,mixed> $session */
    private static function teardownSession(
        string $sourceRoot,
        array &$session,
        bool $passthrough,
        ?callable $phaseHook
    ): void {
        self::assertOwnedSessionPaths($sourceRoot, $session);
        if ($session['phase'] !== 'stopping') {
            $session['phase'] = 'stopping';
            self::replaceSession($session);
        }
        if ($phaseHook !== null) {
            $phaseHook('stopping_published');
        }
        if (self::destroyPair($sourceRoot, (string) $session['name'], $passthrough) !== 0) {
            throw new \RuntimeException(
                'pair teardown failed; run `' . self::demoCli($sourceRoot)
                . " demo stop --name={$session['name']}` to resume cleanup"
            );
        }
        self::cleanupOwnedSession($sourceRoot, $session, $phaseHook);
    }

    /** @param array<string,mixed> $session */
    private static function cleanupOwnedSession(
        string $sourceRoot,
        array &$session,
        ?callable $phaseHook
    ): void {
        self::assertOwnedSessionPaths($sourceRoot, $session);
        foreach (['source_repo', 'target_repo', 'origin', 'compose_env_file'] as $field) {
            self::cleanupOwnedField($sourceRoot, $session, $field, $phaseHook);
        }
        $state = (string) $session['state_file'];
        if ($phaseHook !== null) {
            $phaseHook('state_file_removing');
        }
        $identity = $session['_state_identity'] ?? null;
        if (!is_array($identity) || self::pathIdentity($state) !== $identity) {
            throw new \RuntimeException("demo session identity changed and was retained: $state");
        }
        $claim = $state . '.remove-' . $session['ownership_token'];
        if (file_exists($claim) || is_link($claim) || !@rename($state, $claim)) {
            throw new \RuntimeException("could not remove owned demo session $state");
        }
        if (self::pathIdentity($claim) !== $identity) {
            if (!file_exists($state) && !is_link($state)) {
                @rename($claim, $state);
            }
            throw new \RuntimeException("demo session identity changed and was retained: $state");
        }
        if ($phaseHook !== null) {
            $phaseHook('state_file_claimed');
        }
        if (!@unlink($claim)) {
            if (!file_exists($state) && !is_link($state)) {
                @rename($claim, $state);
            }
            throw new \RuntimeException("could not remove owned demo session $state");
        }
    }

    /** @param array<string,mixed> $session */
    private static function cleanupOwnedField(
        string $sourceRoot,
        array &$session,
        string $field,
        ?callable $phaseHook
    ): void {
        $path = (string) $session[$field];
        $claim = self::deletionClaim($session, $field);
        $row = $session['owned_paths'][$field];
        if ($row['state'] === 'planned') {
            self::adoptPlannedPath($sourceRoot, $session, $field);
            $row = $session['owned_paths'][$field];
        }
        if ($row['state'] === 'acquiring') {
            self::adoptAcquiringPath($session, $field);
            $row = $session['owned_paths'][$field];
        }
        if ($row['state'] === 'deleted') {
            if (file_exists($path) || is_link($path) || file_exists($claim) || is_link($claim)) {
                throw new \RuntimeException("deleted demo $field path reappeared and was retained: $path");
            }
            return;
        }
        if ($row['state'] === 'owned') {
            if (file_exists($claim) || is_link($claim)
                || self::pathIdentity($path) !== $row['identity']) {
                throw new \RuntimeException("owned demo path changed before deletion: $path");
            }
            $session['owned_paths'][$field] = [
                'state' => 'deleting',
                'identity' => $row['identity'],
            ];
            self::replaceSession($session);
            if ($phaseHook !== null) {
                $phaseHook($field . '_deleting');
            }
            $row = $session['owned_paths'][$field];
        }
        if ($row['state'] !== 'deleting') {
            throw new \RuntimeException("demo $field has an unsupported cleanup state");
        }

        $pathExists = file_exists($path) || is_link($path);
        $claimExists = file_exists($claim) || is_link($claim);
        if ($pathExists && $claimExists) {
            throw new \RuntimeException("demo cleanup found both the canonical path and its private claim: $path");
        }
        if ($pathExists) {
            if (self::pathIdentity($path) !== $row['identity'] || !@rename($path, $claim)) {
                throw new \RuntimeException("could not claim the recorded demo path for deletion: $path");
            }
            try {
                if (self::pathIdentity($claim) !== $row['identity']) {
                    throw new \RuntimeException("demo cleanup claim identity changed: $claim");
                }
            } catch (\Throwable $error) {
                if (!file_exists($path) && !is_link($path) && (file_exists($claim) || is_link($claim))) {
                    @rename($claim, $path);
                }
                throw $error;
            }
            $claimExists = true;
            if ($phaseHook !== null) {
                $phaseHook($field . '_claimed');
            }
        }
        if ($claimExists) {
            if (self::pathIdentity($claim) !== $row['identity']) {
                throw new \RuntimeException("demo cleanup claim identity changed and was retained: $claim");
            }
            self::removeTree($claim);
            if ($phaseHook !== null) {
                $phaseHook($field . '_removed');
            }
        }
        if (file_exists($path) || is_link($path) || file_exists($claim) || is_link($claim)) {
            throw new \RuntimeException("demo cleanup could not prove $field absent after deletion");
        }
        $session['owned_paths'][$field] = ['state' => 'deleted', 'identity' => null];
        self::replaceSession($session);
    }

    private static function removeTree(string $root): void {
        if (!file_exists($root) && !is_link($root)) {
            return;
        }
        if (is_link($root) || is_file($root)) {
            if (!@unlink($root)) {
                throw new \RuntimeException("could not remove owned demo path $root");
            }
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            $removed = $entry->isDir() && !$entry->isLink() ? @rmdir($path) : @unlink($path);
            if (!$removed) {
                throw new \RuntimeException("could not remove owned demo path $path");
            }
        }
        if (!@rmdir($root)) {
            throw new \RuntimeException("could not remove owned demo path $root");
        }
    }

    /** @param array<string,mixed> $session */
    private static function acquireOwnedDirectory(array &$session, string $field, ?callable $phaseHook): void {
        $path = (string) $session[$field];
        $stage = self::acquisitionStage($session, $field);
        if (($session['owned_paths'][$field]['state'] ?? null) !== 'planned'
            || file_exists($path) || is_link($path) || file_exists($stage) || is_link($stage)) {
            throw new \RuntimeException("demo could not exclusively reserve $field path $path");
        }
        self::ensureDirectoryParent($path);
        if (!mkdir($stage, 0700)) {
            throw new \RuntimeException("demo could not create $field path $path");
        }
        $identity = self::pathIdentity($stage);
        $marker = self::ownerMarker($session, $field, $stage);
        self::writeNew($marker, $session['ownership_token'] . ':' . $field . "\n", 0600);
        if (self::pathIdentity($stage) !== $identity) {
            throw new \RuntimeException("demo $field acquisition identity changed before its durable receipt");
        }
        $session['owned_paths'][$field] = ['state' => 'acquiring', 'identity' => $identity];
        self::replaceSession($session);
        if ($phaseHook !== null) {
            $phaseHook($field . '_staged');
        }
        if (self::pathIdentity($stage) !== $identity) {
            throw new \RuntimeException("demo $field acquisition stage identity changed and was retained");
        }
        if (file_exists($path) || is_link($path) || !@rename($stage, $path)) {
            throw new \RuntimeException("demo could not publish the reserved $field path $path");
        }
        if (self::pathIdentity($path) !== $identity) {
            throw new \RuntimeException("demo $field acquisition identity changed during publication");
        }
        $marker = self::ownerMarker($session, $field);
        if ($phaseHook !== null) {
            $phaseHook($field . '_created');
        }
        if (self::pathIdentity($path) !== $identity) {
            throw new \RuntimeException("demo $field acquisition identity changed before ownership publication");
        }
        $session['owned_paths'][$field] = ['state' => 'owned', 'identity' => $identity];
        self::replaceSession($session);
        if (self::pathIdentity($path) !== $identity || !unlink($marker)) {
            throw new \RuntimeException("could not retire the $field ownership marker");
        }
    }

    private static function ensureDirectoryParent(string $path): void {
        $parent = dirname($path);
        if (is_link($parent) || (file_exists($parent) && !is_dir($parent))) {
            throw new \RuntimeException("demo directory parent is not an ordinary directory: $parent");
        }
        if (!is_dir($parent)) {
            $ancestor = dirname($parent);
            if (is_link($ancestor) || !is_dir($ancestor) || !@mkdir($parent, 0700)) {
                throw new \RuntimeException("demo could not create directory parent $parent");
            }
        }
        if (is_link($parent) || !is_dir($parent)) {
            throw new \RuntimeException("demo directory parent changed during creation: $parent");
        }
    }

    /** @param array<string,mixed> $session */
    private static function acquireOwnedEnvironment(
        array &$session,
        string $sourceRoot,
        ?callable $phaseHook
    ): void {
        $field = 'compose_env_file';
        $path = (string) $session[$field];
        if (($session['owned_paths'][$field]['state'] ?? null) !== 'planned'
            || file_exists($path) || is_link($path)) {
            throw new \RuntimeException("demo could not exclusively reserve $field path $path");
        }
        $identity = self::writeNew($path, self::composeEnvBytes($session, $sourceRoot), 0600);
        $session['owned_paths'][$field] = ['state' => 'acquiring', 'identity' => $identity];
        self::replaceSession($session);
        if ($phaseHook !== null) {
            $phaseHook($field . '_created');
        }
        if (self::pathIdentity($path) !== $identity) {
            throw new \RuntimeException("demo $field acquisition identity changed before ownership publication");
        }
        $session['owned_paths'][$field] = ['state' => 'owned', 'identity' => $identity];
        self::replaceSession($session);
    }

    /** @param array<string,mixed> $session */
    private static function adoptAcquiringPath(array &$session, string $field): void {
        $path = (string) $session[$field];
        $stage = $field === 'compose_env_file' ? null : self::acquisitionStage($session, $field);
        $identity = $session['owned_paths'][$field]['identity'] ?? null;
        if (!is_array($identity)) {
            throw new \RuntimeException("demo $field acquisition has no identity receipt");
        }
        $pathExists = file_exists($path) || is_link($path);
        $stageExists = $stage !== null && (file_exists($stage) || is_link($stage));
        if ($pathExists && $stageExists) {
            throw new \RuntimeException("demo $field acquisition has both canonical and staging paths");
        }
        if (!$pathExists && !$stageExists) {
            $session['owned_paths'][$field] = ['state' => 'deleted', 'identity' => null];
            self::replaceSession($session);
            return;
        }
        $candidate = $pathExists ? $path : (string) $stage;
        if (self::pathIdentity($candidate) !== $identity) {
            throw new \RuntimeException("demo $field acquisition identity changed and was retained: $candidate");
        }
        if (!$pathExists) {
            if ((file_exists($path) || is_link($path)) || !@rename($candidate, $path)
                || self::pathIdentity($path) !== $identity) {
                throw new \RuntimeException("could not resume the recorded $field acquisition: $path");
            }
        }
        $session['owned_paths'][$field] = ['state' => 'owned', 'identity' => $identity];
        self::replaceSession($session);
        if ($field !== 'compose_env_file') {
            $marker = self::ownerMarker($session, $field);
            if (is_file($marker) && !is_link($marker)
                && (self::pathIdentity($path) !== $identity || !unlink($marker))) {
                throw new \RuntimeException("could not retire the resumed $field ownership marker");
            }
        }
    }

    /** @param array<string,mixed> $session */
    private static function adoptPlannedPath(string $sourceRoot, array &$session, string $field): void {
        $path = (string) $session[$field];
        $claim = self::deletionClaim($session, $field);
        if (file_exists($claim) || is_link($claim)) {
            throw new \RuntimeException("planned demo $field has an unexpected cleanup claim: $claim");
        }
        if (!file_exists($path) && !is_link($path)) {
            if ($field !== 'compose_env_file') {
                $stage = self::acquisitionStage($session, $field);
                if (file_exists($stage) || is_link($stage)) {
                    self::assertOwnershipMarker($session, $field, $stage);
                    if (!@rename($stage, $path)) {
                        throw new \RuntimeException("could not resume the planned $field acquisition: $path");
                    }
                }
            }
        }
        if (!file_exists($path) && !is_link($path)) {
            $session['owned_paths'][$field] = ['state' => 'deleted', 'identity' => null];
            self::replaceSession($session);
            return;
        }
        if ($field === 'compose_env_file') {
            if (is_link($path) || !is_file($path)
                || file_get_contents($path) !== self::composeEnvBytes($session, $sourceRoot)) {
                throw new \RuntimeException("planned demo environment is not the reserved file: $path");
            }
        } else {
            if (is_link($path) || !is_dir($path)) {
                throw new \RuntimeException("planned demo $field is not an ordinary directory: $path");
            }
            self::assertOwnershipMarker($session, $field, $path);
        }
        $session['owned_paths'][$field] = ['state' => 'owned', 'identity' => self::pathIdentity($path)];
        self::replaceSession($session);
        if ($field !== 'compose_env_file') {
            $marker = self::ownerMarker($session, $field);
            if (is_file($marker) && !is_link($marker) && !unlink($marker)) {
                throw new \RuntimeException("could not retire the resumed $field ownership marker");
            }
        }
    }

    /** @param array<string,mixed> $session */
    private static function assertOwnedSessionPaths(string $sourceRoot, array $session): void {
        foreach (['source_repo', 'target_repo', 'origin', 'compose_env_file'] as $field) {
            $path = (string) $session[$field];
            $claim = self::deletionClaim($session, $field);
            $row = $session['owned_paths'][$field];
            $pathExists = file_exists($path) || is_link($path);
            $claimExists = file_exists($claim) || is_link($claim);
            if ($row['state'] === 'planned') {
                self::assertPlannedPath($sourceRoot, $session, $field, $pathExists, $claimExists);
                continue;
            }
            if ($row['state'] === 'acquiring') {
                self::assertAcquiringPath($session, $field, $pathExists, $claimExists);
                continue;
            }
            if ($field !== 'compose_env_file') {
                $acquisition = self::acquisitionStage($session, $field);
                if (file_exists($acquisition) || is_link($acquisition)) {
                    throw new \RuntimeException("demo $field retained an unexpected acquisition stage");
                }
            }
            if ($row['state'] === 'deleted') {
                if ($pathExists || $claimExists) {
                    throw new \RuntimeException("deleted demo $field path reappeared and was retained: $path");
                }
                continue;
            }
            if ($pathExists && $claimExists) {
                throw new \RuntimeException("demo $field has both its canonical path and cleanup claim");
            }
            if ($row['state'] === 'owned'
                && (!$pathExists || $claimExists || self::pathIdentity($path) !== $row['identity'])) {
                throw new \RuntimeException("demo $field identity changed and was retained: $path");
            }
            if ($row['state'] === 'deleting') {
                $candidate = $claimExists ? $claim : ($pathExists ? $path : null);
                if ($candidate !== null && self::pathIdentity($candidate) !== $row['identity']) {
                    throw new \RuntimeException("demo $field cleanup identity changed and was retained: $candidate");
                }
            }
        }
    }

    /** @param array<string,mixed> $session */
    private static function assertAcquiringPath(
        array $session,
        string $field,
        bool $pathExists,
        bool $claimExists
    ): void {
        if ($claimExists) {
            throw new \RuntimeException("acquiring demo $field has an unexpected cleanup claim");
        }
        $stage = $field === 'compose_env_file' ? null : self::acquisitionStage($session, $field);
        $stageExists = $stage !== null && (file_exists($stage) || is_link($stage));
        if ($pathExists && $stageExists) {
            throw new \RuntimeException("acquiring demo $field has both canonical and staging paths");
        }
        if (!$pathExists && !$stageExists) {
            return;
        }
        $candidate = $pathExists ? (string) $session[$field] : (string) $stage;
        if (self::pathIdentity($candidate) !== $session['owned_paths'][$field]['identity']) {
            throw new \RuntimeException("acquiring demo $field identity changed and was retained: $candidate");
        }
    }

    /** @param array<string,mixed> $session */
    private static function assertPlannedPath(
        string $sourceRoot,
        array $session,
        string $field,
        bool $pathExists,
        bool $claimExists
    ): void {
        $path = (string) $session[$field];
        if ($claimExists) {
            throw new \RuntimeException("planned demo $field has an unexpected cleanup claim");
        }
        $stageExists = false;
        if ($field !== 'compose_env_file') {
            $stage = self::acquisitionStage($session, $field);
            $stageExists = file_exists($stage) || is_link($stage);
            if ($pathExists && $stageExists) {
                throw new \RuntimeException("planned demo $field has both canonical and acquisition paths");
            }
            if ($stageExists) {
                self::assertOwnershipMarker($session, $field, $stage);
                return;
            }
        }
        if (!$pathExists) {
            return;
        }
        if ($field === 'compose_env_file') {
            if (is_link($path) || !is_file($path)
                || file_get_contents($path) !== self::composeEnvBytes($session, $sourceRoot)) {
                throw new \RuntimeException("planned demo environment changed and was retained: $path");
            }
            return;
        }
        if (is_link($path) || !is_dir($path)) {
            throw new \RuntimeException("planned demo $field changed and was retained: $path");
        }
        self::assertOwnershipMarker($session, $field, $path);
    }

    /** @param array<string,mixed> $session */
    private static function ownerMarker(array $session, string $field, ?string $root = null): string {
        return ($root ?? (string) $session[$field])
            . '/.wprism-demo-owner-' . $session['ownership_token'] . '-' . $field;
    }

    /** @param array<string,mixed> $session */
    private static function acquisitionStage(array $session, string $field): string {
        $path = (string) $session[$field];
        return dirname($path) . '/.wprism-demo-acquire-' . $session['ownership_token'] . '-' . $field;
    }

    /** @param array<string,mixed> $session */
    private static function assertOwnershipMarker(array $session, string $field, string $root): void {
        if (is_link($root) || !is_dir($root)) {
            throw new \RuntimeException("planned demo $field is not an ordinary directory: $root");
        }
        $entries = array_values(array_diff(scandir($root) ?: [], ['.', '..']));
        $marker = self::ownerMarker($session, $field, $root);
        if ($entries !== [basename($marker)] || is_link($marker) || !is_file($marker)
            || file_get_contents($marker) !== $session['ownership_token'] . ':' . $field . "\n") {
            throw new \RuntimeException("planned demo $field contains bytes without an ownership receipt: $root");
        }
    }

    /** @param array<string,mixed> $session */
    private static function deletionClaim(array $session, string $field): string {
        $path = (string) $session[$field];
        return dirname($path) . '/.wprism-demo-remove-' . $session['ownership_token'] . '-' . $field;
    }

    /** @return array{dev:string,ino:string,type:string} */
    private static function pathIdentity(string $path): array {
        $stat = lstat($path);
        if (!is_array($stat) || is_link($path) || (!is_dir($path) && !is_file($path))) {
            throw new \RuntimeException("demo path is not an ordinary file or directory: $path");
        }
        return [
            'dev' => (string) $stat['dev'],
            'ino' => (string) $stat['ino'],
            'type' => is_dir($path) ? 'directory' : 'file',
        ];
    }

    /** @param array<int|string,mixed> $stat @return array{dev:string,ino:string,type:string} */
    private static function identityFromStat(array $stat, string $type): array {
        if (!isset($stat['dev'], $stat['ino'])
            || !is_int($stat['dev']) || $stat['dev'] < 0
            || !is_int($stat['ino']) || $stat['ino'] < 0
            || !in_array($type, ['file', 'directory'], true)) {
            throw new \RuntimeException('demo path identity receipt is malformed');
        }
        return ['dev' => (string) $stat['dev'], 'ino' => (string) $stat['ino'], 'type' => $type];
    }

    private static function validOwnedPaths(mixed $owned): bool {
        if (!is_array($owned) || array_keys($owned) !== ['source_repo', 'target_repo', 'origin', 'compose_env_file']) {
            return false;
        }
        foreach ($owned as $field => $row) {
            if (!is_array($row) || array_keys($row) !== ['state', 'identity']
                || !in_array($row['state'] ?? null, ['planned', 'acquiring', 'owned', 'deleting', 'deleted'], true)) {
                return false;
            }
            $identity = $row['identity'] ?? null;
            if (in_array($row['state'], ['planned', 'deleted'], true)) {
                if ($identity !== null) {
                    return false;
                }
                continue;
            }
            if (!is_array($identity) || array_keys($identity) !== ['dev', 'ino', 'type']
                || preg_match('/^[0-9]+$/D', $identity['dev'] ?? '') !== 1
                || preg_match('/^[0-9]+$/D', $identity['ino'] ?? '') !== 1
                || ($identity['type'] ?? null) !== ($field === 'compose_env_file' ? 'file' : 'directory')) {
                return false;
            }
        }
        return true;
    }

    private static function requireTools(array $tools): void {
        foreach ($tools as $tool) {
            $result = self::runProcess(['sh', '-c', 'command -v "$1"', 'wprism-demo', $tool], null);
            if ($result['exit'] !== 0) {
                throw new \RuntimeException("required command '$tool' is unavailable");
            }
        }
    }

    /** @return resource */
    private static function lock(string $sourceRoot, string $name) {
        $path = sys_get_temp_dir() . '/wprism-demo-' . hash('sha256', $sourceRoot . "\0" . $name) . '.lock';
        $handle = @fopen($path, 'c');
        if (!is_resource($handle) || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException("demo '$name' already has an active command");
        }
        return $handle;
    }

    /** @param array<string,mixed> $session */
    private static function assertReady(array $session): void {
        if (($session['phase'] ?? null) !== 'ready') {
            throw new \RuntimeException(($session['phase'] ?? null) === 'review_required'
                ? 'demo contract review is required; run demo review --accept-page-only first'
                : 'demo setup is incomplete; run demo stop, then start it again');
        }
    }

    private static function demoCli(string $sourceRoot): string {
        return realpath($sourceRoot . '/cli/wprism') ?: $sourceRoot . '/cli/wprism';
    }

    private static function port(string $value, string $flag): int {
        if (preg_match('/^[0-9]+$/D', $value) !== 1 || (int) $value < 1024 || (int) $value > 65535) {
            throw new \RuntimeException("$flag must be an integer from 1024 through 65535");
        }
        return (int) $value;
    }
}

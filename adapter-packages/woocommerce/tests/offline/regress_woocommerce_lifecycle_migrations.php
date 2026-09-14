<?php
/** Offline product-path proof for WooCommerce's deferred DB-update settlement gate. */
declare(strict_types=1);

namespace {
    $wprismRoot = dirname(__DIR__, 4);
    require_once $wprismRoot . '/sandbox/tests/lib/check.php';
    require_once $wprismRoot . '/sandbox/tests/lib/FakeWpdb.php';

    define('WC_VERSION', '11.0.1');

    $GLOBALS['woo_lifecycle_options'] = [];
    $GLOBALS['woo_lifecycle_last_db_update_key'] = '11.0.0';
    $GLOBALS['woo_lifecycle_after_drain'] = null;

    function get_option(string $name, mixed $default = false): mixed {
        return $GLOBALS['woo_lifecycle_options'][$name] ?? $default;
    }

    // WC_Install::needs_db_update() verbatim from includes/class-wc-install.php:890-894,
    // byte-identical in official 11.0.1 and 11.1.0. The fixture supplies the last
    // $db_updates key per scenario, which is the only thing that differs between the
    // two releases: 11.0.1 ends at '11.0.0', 11.1.0 ends at the suffixed '11.1.0-1'.
    final class WC_Install {
        public static function needs_db_update(): bool {
            $current_db_version = get_option('woocommerce_db_version', null);

            return ! is_null($current_db_version)
                && version_compare($current_db_version, $GLOBALS['woo_lifecycle_last_db_update_key'], '<');
        }
    }

    /** Action Scheduler's own claim shape (classes/ActionScheduler_ActionClaim.php:35,42). */
    final class ActionScheduler_ActionClaim {
        /** @param list<string> $actions */
        public function __construct(private string $id, private array $actions) {}

        public function get_id(): string {
            return $this->id;
        }

        /** @return list<string> */
        public function get_actions(): array {
            return $this->actions;
        }
    }

    /**
     * The store seam the provider claims through. It hands back the seeded pending
     * action ids in batches and records exactly how it was asked for them, so the
     * scope the retired WP-CLI command carried -- two hooks, one group, batch size
     * 25 -- stays assertable after the command itself is gone.
     */
    final class WooLifecycleStore {
        /** @var list<array{max:int,hooks:list<string>,group:string}> */
        public array $stakes = [];
        /** @var list<string> */
        public array $released = [];
        /** @var list<string> */
        public array $pending = [];
        public ?string $stakeFailure = null;
        /** ActionScheduler_wpPostStore::get_actions_by_group()'s refusal, reproduced. */
        public bool $rejectNonEmptyGroup = false;
        private int $claimSequence = 0;

        public function stake_claim(
            int $max_actions = 10,
            ?DateTimeInterface $before_date = null,
            array $hooks = [],
            string $group = ''
        ): ActionScheduler_ActionClaim {
            $this->stakes[] = ['max' => $max_actions, 'hooks' => $hooks, 'group' => $group];
            if ($this->rejectNonEmptyGroup && $group !== '') {
                throw new InvalidArgumentException(sprintf('The group "%s" does not exist.', $group));
            }
            if ($this->stakeFailure !== null) {
                throw new InvalidArgumentException($this->stakeFailure);
            }
            $batch = array_splice($this->pending, 0, $max_actions);
            if ($batch === [] && is_callable($GLOBALS['woo_lifecycle_after_drain'])) {
                ($GLOBALS['woo_lifecycle_after_drain'])();
                $GLOBALS['woo_lifecycle_after_drain'] = null;
            }
            $this->claimSequence++;
            return new ActionScheduler_ActionClaim('claim-' . $this->claimSequence, $batch);
        }

        public function release_claim(ActionScheduler_ActionClaim $claim): void {
            $this->released[] = $claim->get_id();
        }
    }

    /** The runner seam. process_action() records failures against the row rather than throwing. */
    final class WooLifecycleRunner {
        /** @var list<array{id:int,context:string}> */
        public array $processed = [];

        public function process_action($action_id, $context = ''): void {
            $this->processed[] = ['id' => $action_id, 'context' => $context];
        }
    }

    /** Facade matching packages/action-scheduler/classes/abstracts/ActionScheduler.php:47,68. */
    final class ActionScheduler {
        public static function store(): WooLifecycleStore {
            return $GLOBALS['woo_lifecycle_store'];
        }

        public static function runner(): WooLifecycleRunner {
            return $GLOBALS['woo_lifecycle_runner'];
        }
    }
}

namespace WPrism {
    final class Policy {}
}

namespace {
    require_once $wprismRoot . '/agent/src/Adapter/ProviderSdk.php';
    require_once $wprismRoot . '/agent/src/Adapter/ManifestProviderRuntime.php';
    require_once $wprismRoot . '/agent/src/Adapter/ProviderOperationProcess.php';
    require_once $wprismRoot . '/agent/src/Adapter/Providers.php';
    require_once $wprismRoot . '/agent/src/Kernel/PrivateRefusalEvidence.php';
    require_once $wprismRoot . '/adapter-packages/woocommerce/package/runtime/providers/woocommerce-lifecycle-migrations.php';

    use WPrism\Providers\WoocommerceLifecycleMigrations;
    use WPrismTest\FakeWpdb;

    /** @return array<string,mixed> */
    function woo_lifecycle_declaration(): array {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/package/manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        foreach ($manifest['providers'] as $provider) {
            if (($provider['id'] ?? null) === 'woocommerce-lifecycle-migrations') {
                return $provider;
            }
        }
        throw new RuntimeException('fixture could not find lifecycle provider declaration');
    }

    /**
     * One update action per row, which is what the provider's GROUP BY projection
     * counts. The shared FakeWpdb answers the aggregate itself, so scenarios seed
     * rows rather than pre-baked totals.
     *
     * @return list<array<string,string>>
     */
    function woo_lifecycle_rows(string $status, int $count): array {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['hook' => 'woocommerce_run_update_callback', 'status' => $status];
        }
        return $rows;
    }

    /**
     * Move the observable database state without touching the store or runner the
     * assertions are about. The drain callback uses this; only woo_lifecycle_reset()
     * builds fresh seams.
     *
     * @param list<list<array<string,string>>> $groups
     */
    function woo_lifecycle_state(
        array $groups,
        string $databaseVersion,
        string $pluginVersion,
        string $lastDatabaseUpdateKey = '11.0.0'
    ): void {
        $rows = [];
        foreach ($groups as $group) {
            foreach ($group as $row) {
                $rows[] = $row;
            }
        }
        // A row outside the two reviewed hooks must never reach the projection.
        $rows[] = ['hook' => 'action_scheduler/migration_hook', 'status' => 'pending'];
        $GLOBALS['wpdb']->seedTable('actionscheduler_actions', $rows);
        $GLOBALS['woo_lifecycle_last_db_update_key'] = $lastDatabaseUpdateKey;
        $GLOBALS['woo_lifecycle_options'] = [
            'woocommerce_db_version' => $databaseVersion,
            'woocommerce_version' => $pluginVersion,
        ];
    }

    /** @param list<list<array<string,string>>> $groups */
    function woo_lifecycle_reset(
        array $groups,
        string $databaseVersion,
        string $pluginVersion,
        string $lastDatabaseUpdateKey = '11.0.0',
        int $pendingActionIds = 0
    ): void {
        $rows = [];
        foreach ($groups as $group) {
            foreach ($group as $row) {
                $rows[] = $row;
            }
        }
        // A row outside the two reviewed hooks must never reach the projection.
        $rows[] = ['hook' => 'action_scheduler/migration_hook', 'status' => 'pending'];
        $GLOBALS['wpdb']->seedTable('actionscheduler_actions', $rows);
        $GLOBALS['woo_lifecycle_last_db_update_key'] = $lastDatabaseUpdateKey;
        $GLOBALS['woo_lifecycle_options'] = [
            'woocommerce_db_version' => $databaseVersion,
            'woocommerce_version' => $pluginVersion,
        ];
        $GLOBALS['woo_lifecycle_after_drain'] = null;
        $store = new WooLifecycleStore();
        $store->pending = array_map(static fn(int $i): string => (string) (100 + $i), range(0, max(0, $pendingActionIds - 1)));
        if ($pendingActionIds === 0) {
            $store->pending = [];
        }
        $GLOBALS['woo_lifecycle_store'] = $store;
        $GLOBALS['woo_lifecycle_runner'] = new WooLifecycleRunner();
    }

    $GLOBALS['wpdb'] = new FakeWpdb('wp_');
    $GLOBALS['wpdb']->setColumns('actionscheduler_actions', ['hook' => 'string', 'status' => 'string']);

    // settle_lifecycle_migrations runs inside the ENGINE's child
    // (manifest-provider-fresh-process/v1), and that binding refuses a declaration
    // without a frozen policy and digest-bound source
    // (ManifestProviderRuntime.php:118-128). Supply the same fixture binding the
    // rank-math provider capsule uses, so the semantics below are exercised through
    // the real runtime rather than around it.
    $wooLifecycleDeclaration = woo_lifecycle_declaration();
    $wooLifecycleProviderFile = realpath(
        dirname(__DIR__, 2) . '/package/runtime/providers/woocommerce-lifecycle-migrations.php'
    );
    if ($wooLifecycleProviderFile === false) {
        throw new RuntimeException('fixture provider source is unavailable');
    }
    $wooLifecycleDeclaration['manifest'] = 'woocommerce';
    $wooLifecycleDeclaration['_wprism_adapter_digest'] = str_repeat('a', 64);
    $wooLifecycleDeclaration['_wprism_adapter_library_root'] = dirname(__DIR__, 4);
    $wooLifecycleDeclaration['_wprism_execution_bound'] = true;
    $wooLifecycleDeclaration['_wprism_execution_identity'] = [
        'artifact_hash' => str_repeat('b', 64),
        'manifest_hash' => str_repeat('c', 64),
        'resolved_adapters_sha256' => str_repeat('d', 64),
        'site_hash' => str_repeat('e', 64),
    ];
    $wooLifecycleDeclaration['_wprism_plugin_runtime'] = [
        'active' => true,
        'installed' => true,
        'version' => WC_VERSION,
    ];
    $wooLifecycleDeclaration['_wprism_policy_snapshot'] = ['fixture' => 'woocommerce-lifecycle-migrations'];
    $wooLifecycleDeclaration['_wprism_provider_file'] = $wooLifecycleProviderFile;
    $wooLifecycleDeclaration['_wprism_provider_sha256'] = hash_file('sha256', $wooLifecycleProviderFile);
    $provider = new WoocommerceLifecycleMigrations($wooLifecycleDeclaration);

    // ProviderSdk's checked reads answer only inside a bound contract, which is the
    // private join Providers makes in production.
    $wooLifecycleBind = new ReflectionMethod(\WPrism\Providers::class, 'bind_manifest_runtime_contracts');
    $wooLifecycleBind->invoke(null, $provider, $provider->capabilities());

    // A fresh-process capability's public invoke() spawns the ENGINE's child, which a
    // bare offline fixture has no boot for (ProviderOperationProcess.php:565). Reach
    // the same handler the child would run, the way the rank-math provider capsule
    // does: invokeDirect still walks contract binding and receipt assertion.
    $wooLifecycleInvokeDirect = new ReflectionMethod(\WPrism\ManifestProviderRuntime::class, 'invokeDirect');
    $wooLifecycleSettle = static function (array $args = []) use ($provider, $wooLifecycleInvokeDirect): array {
        return $wooLifecycleInvokeDirect->invoke($provider, 'settle_lifecycle_migrations', $args);
    };

    // --- a settled target does no work at all -------------------------------
    woo_lifecycle_reset([woo_lifecycle_rows('complete', 4)], WC_VERSION, WC_VERSION);
    $already = $wooLifecycleSettle();
    wprism_check_same([], $GLOBALS['woo_lifecycle_store']->stakes,
        'an already-settled target claims nothing from Action Scheduler');
    wprism_check_same([], $GLOBALS['woo_lifecycle_runner']->processed,
        'an already-settled target runs no update action');
    wprism_check_same($already['before'], $already['after'], 'the no-op receipt proves the same closed projection twice');
    wprism_check_same(true, $already['verified'], 'the no-op settlement receipt is verified');

    // --- an unsettled target drains through the store and runner ------------
    woo_lifecycle_reset(
        [woo_lifecycle_rows('complete', 3), woo_lifecycle_rows('pending', 2)],
        '11.0.0',
        WC_VERSION,
        '11.0.0',
        2
    );
    $GLOBALS['woo_lifecycle_after_drain'] = static function (): void {
        woo_lifecycle_state([woo_lifecycle_rows('complete', 5)], WC_VERSION, WC_VERSION);
    };
    $settled = $wooLifecycleSettle();
    wprism_check_same(2, count($GLOBALS['woo_lifecycle_runner']->processed),
        'every claimed update action is run exactly once');
    wprism_check_same(
        ['max' => 25, 'hooks' => ['woocommerce_run_update_callback', 'woocommerce_update_db_to_current_version'], 'group' => ''],
        $GLOBALS['woo_lifecycle_store']->stakes[0],
        'the claim carries the retired command scope, keyed by hook exactly as the projection is'
    );
    wprism_check_same(2, $settled['before']['queue']['pending'], 'the receipt preserves the pre-run pending count');
    wprism_check_same(0, $settled['after']['queue']['pending'], 'the receipt proves no pending update remains');
    wprism_check_same(WC_VERSION, $settled['after']['database_version'], 'the database marker equals the activated code version');
    wprism_check_same(true, $settled['verified'], 'the completed migration receipt is verified');

    // --- every claim is released, including on the empty terminating claim ---
    wprism_check_same(
        count($GLOBALS['woo_lifecycle_store']->stakes),
        count($GLOBALS['woo_lifecycle_store']->released),
        'every staked claim is released, so a drained queue leaves no claim behind'
    );

    // --- a still-failed queue refuses ---------------------------------------
    woo_lifecycle_reset([woo_lifecycle_rows('failed', 1)], WC_VERSION, WC_VERSION);
    try {
        $wooLifecycleSettle();
        wprism_check(false, 'a run that leaves a failed update behind must refuse');
    } catch (RuntimeException $failure) {
        wprism_check(
            str_contains($failure->getMessage(), 'remain pending, running, failed, or version-incomplete')
                && str_contains($failure->getMessage(), 'recovery_required'),
            'post-run queue/version incompleteness is an explicit recovery-required refusal'
        );
    }

    // --- the group filter is never passed, because it cannot be honoured ---------
    // A HybridStore site resolves a group by TAXONOMY TERM through the legacy
    // wp_posts store (ActionScheduler_HybridStore.php:243-253 delegating to
    // ActionScheduler_wpPostStore.php:713-717), and that term does not exist on a
    // site that began on the DBStore. Measured on the woovm pair: every claim raised
    // 'The group "woocommerce-db-updates" does not exist.' while the DBStore groups
    // row was present. A store that refuses any non-empty group must therefore still
    // drain.
    woo_lifecycle_reset([woo_lifecycle_rows('pending', 1)], WC_VERSION, WC_VERSION, '11.0.0', 1);
    $GLOBALS['woo_lifecycle_store']->rejectNonEmptyGroup = true;
    $GLOBALS['woo_lifecycle_after_drain'] = static function (): void {
        woo_lifecycle_state([woo_lifecycle_rows('complete', 1)], WC_VERSION, WC_VERSION);
    };
    $hybrid = $wooLifecycleSettle();
    wprism_check_same(true, $hybrid['verified'],
        'a store that refuses every non-empty group still settles, because none is passed');
    wprism_check_same([''], array_values(array_unique(array_map(
        static fn(array $stake): string => $stake['group'],
        $GLOBALS['woo_lifecycle_store']->stakes
    ))), 'every claim the drain makes carries an empty group');

    // --- a claim that fails for a real reason still refuses ---------------------
    woo_lifecycle_reset([woo_lifecycle_rows('pending', 1)], WC_VERSION, WC_VERSION, '11.0.0', 1);
    $GLOBALS['woo_lifecycle_store']->stakeFailure = 'Unable to claim actions. Database error.';
    try {
        $wooLifecycleSettle();
        wprism_check(false, 'an unclaimable queue must refuse');
    } catch (RuntimeException $failure) {
        wprism_check(
            $failure->getMessage() === 'wprism: WooCommerce migration queue could not be claimed in the bounded fresh process; recovery_required'
                && $failure->getPrevious() instanceof InvalidArgumentException,
            'an unclaimable queue returns one safe recovery-required refusal that retains its native cause'
        );
    }

    // --- WooCommerce 11.1.0's suffixed database marker ------------------------
    // 11.1.0 is the first release in the admitted range whose last $db_updates key
    // carries a suffix, '11.1.0-1'. WC_Install::update_db_version() stores
    // max(WC()->version, that key) by version_compare, so the marker a settled
    // install records is NOT the plugin version. Pin the PHP semantics that make it
    // so, by their real names, before exercising the shape:
    wprism_check(
        version_compare('11.1.0', '11.1.0-1', '>') === false
            && version_compare('11.0.1', '11.0.0', '>') === true,
        'version_compare ranks 11.1.0 below its own suffixed update key and 11.0.1 above its last 11.0.0 key'
    );
    // Measured on the woovm pair at certify case 90: WC_VERSION 11.1.0,
    // woocommerce_db_version '11.1.0-1', needs_db_update() false, queue empty, and no
    // 'woocommerce-db-updates' group at all. String equality called that unsettled and
    // claimed against a group Action Scheduler had never created. This file's
    // WC_VERSION is the 11.0.1 constant above, so the same shape is built relative to
    // it: a settled target whose marker is its own suffixed update key.
    woo_lifecycle_reset([], WC_VERSION . '-1', WC_VERSION, WC_VERSION . '-1');
    $suffixed = $wooLifecycleSettle();
    wprism_check_same([], $GLOBALS['woo_lifecycle_store']->stakes,
        'a settled target whose db marker is its own suffixed update key claims nothing');
    wprism_check_same(true, $suffixed['verified'], 'the suffixed-marker settlement receipt is verified');
    wprism_check_same(WC_VERSION . '-1', $suffixed['after']['database_version'],
        "the receipt still records WooCommerce's own database marker verbatim");
    wprism_check_same(false, $suffixed['after']['database_update_pending'],
        "settlement is WooCommerce's own needs_db_update() verdict, not string equality");

    // The same suffixed key, one update behind, must still be unsettled -- otherwise
    // the fix would have traded a false negative for a false positive.
    woo_lifecycle_reset([woo_lifecycle_rows('pending', 1)], WC_VERSION, WC_VERSION, WC_VERSION . '-1', 1);
    $GLOBALS['woo_lifecycle_after_drain'] = static function (): void {
        woo_lifecycle_state([woo_lifecycle_rows('complete', 1)], WC_VERSION . '-1', WC_VERSION, WC_VERSION . '-1');
    };
    $behind = $wooLifecycleSettle();
    wprism_check_same(1, count($GLOBALS['woo_lifecycle_runner']->processed),
        'a target behind the suffixed update key still runs its claimed action');
    wprism_check_same(true, $behind['before']['database_update_pending'],
        'the pre-run receipt records the pending database update WooCommerce reported');
    wprism_check_same(false, $behind['after']['database_update_pending'],
        'the post-run receipt proves WooCommerce no longer reports a pending database update');

    // --- the engine's independent postimage readback -------------------------
    woo_lifecycle_reset([woo_lifecycle_rows('complete', 2)], WC_VERSION, WC_VERSION);
    $observe = new ReflectionMethod(WoocommerceLifecycleMigrations::class, 'observe_fresh_postimage_settle_lifecycle_migrations');
    $project = new ReflectionMethod(WoocommerceLifecycleMigrations::class, 'project_fresh_postimage_settle_lifecycle_migrations');
    $observed = $observe->invoke($provider, []);
    wprism_check_same([], $GLOBALS['woo_lifecycle_store']->stakes,
        'the independent postimage readback observes without settling anything');
    $projected = $project->invoke($provider, $observed);
    wprism_check_same(
        ['active_version', 'database_update_pending', 'database_version', 'plugin_version', 'unfinished'],
        array_keys($projected),
        'the projection drops the volatile complete/canceled totals two children may legitimately disagree on'
    );
    wprism_check_same(0, $projected['unfinished'], 'a settled projection carries no unfinished update action');

    woo_lifecycle_reset([woo_lifecycle_rows('pending', 1)], WC_VERSION, WC_VERSION);
    try {
        $project->invoke($provider, $observe->invoke($provider, []));
        wprism_check(false, 'an unsettled postimage must refuse in the readback child too');
    } catch (RuntimeException $failure) {
        wprism_check(
            str_contains($failure->getMessage(), 'remain pending, running, failed, or version-incomplete'),
            'the readback child refuses an unsettled postimage rather than reporting success'
        );
    }

    try {
        $wooLifecycleSettle(['forged' => true]);
        wprism_check(false, 'settlement must reject caller-defined provider arguments');
    } catch (RuntimeException $failure) {
        wprism_check(
            $failure->getMessage() === 'wprism: WooCommerce lifecycle settlement accepts no arguments',
            'the manifest-fixed settlement scope cannot be widened by an artifact argument'
        );
    }

    echo "PASS: WooCommerce lifecycle migrations (28 assertions)\n";
}

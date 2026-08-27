<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/../Onboarding/Adopt.php';
require_once __DIR__ . '/HostProcess.php';

/** Source-checkout, disposable two-site journey over the real Duo commands. */
final class DemoCommand {
    private const FORMAT = 'duo-demo-session/v1';
    private const DEFAULT_NAME = 'duodemo';
    private const DEFAULT_SOURCE_PORT = 8781;
    private const DEFAULT_TARGET_PORT = 8782;
    private const WOO_VERSION = '11.0.1';
    private const LIVE_PROCESS_TIMEOUT_MILLISECONDS = 1800000;

    /**
     * @param list<string> $args everything after `demo`
     * @param ?callable(string):void $phaseHook offline fault-injection seam
     */
    public static function run(array $args, string $sourceRoot, ?callable $phaseHook = null): int {
        $action = array_shift($args);
        if (!is_string($action) || !in_array($action, ['start', 'status', 'capture', 'apply', 'refusal', 'stop'], true)) {
            fwrite(STDERR, "duo: demo: expected start, status, capture, apply, refusal, or stop\n");
            return 1;
        }
        try {
            $options = self::options($action, $args);
            $lock = self::lock($sourceRoot, $options['name']);
            try {
                return match ($action) {
                    'start' => self::start($sourceRoot, $options, $phaseHook),
                    'status' => self::status($sourceRoot, $options['name']),
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
            fwrite(STDERR, 'duo: demo: ' . $error->getMessage() . "\n");
            return 1;
        }
    }

    /** @return array{name:string,source_port:int,target_port:int,scenario:string} */
    public static function options(string $action, array $args): array {
        $values = [
            'name' => self::DEFAULT_NAME,
            'source_port' => self::DEFAULT_SOURCE_PORT,
            'target_port' => self::DEFAULT_TARGET_PORT,
            'scenario' => 'woocommerce',
        ];
        foreach ($args as $arg) {
            if (!is_string($arg) || preg_match('/^--(name|source-port|target-port|scenario)=(.+)$/D', $arg, $match) !== 1) {
                throw new \RuntimeException("unsupported $action argument '$arg'");
            }
            $key = str_replace('-', '_', $match[1]);
            $values[$key] = in_array($key, ['source_port', 'target_port'], true)
                ? self::port($match[2], '--' . $match[1])
                : $match[2];
        }
        if (preg_match('/^[a-z][a-z0-9]{1,23}$/D', (string) $values['name']) !== 1) {
            throw new \RuntimeException('--name must begin with a lowercase letter and contain 2-24 lowercase alphanumerics');
        }
        if ($values['scenario'] !== 'woocommerce') {
            throw new \RuntimeException("the first demo scenario is 'woocommerce'");
        }
        if ($action !== 'start'
            && ($values['source_port'] !== self::DEFAULT_SOURCE_PORT
                || $values['target_port'] !== self::DEFAULT_TARGET_PORT
                || $values['scenario'] !== 'woocommerce')) {
            throw new \RuntimeException("$action accepts only --name=<demo-name>");
        }
        if ($values['source_port'] === $values['target_port']) {
            throw new \RuntimeException('source and target ports must differ');
        }
        return [
            'name' => (string) $values['name'],
            'source_port' => (int) $values['source_port'],
            'target_port' => (int) $values['target_port'],
            'scenario' => (string) $values['scenario'],
        ];
    }

    /** @param array{name:string,source_port:int,target_port:int,scenario:string} $options */
    private static function start(string $sourceRoot, array $options, ?callable $phaseHook): int {
        self::requireTools(['docker', 'git', 'jq']);
        $session = self::sessionShape($sourceRoot, $options);
        self::restoreClaimedSession((string) $session['state_file'], (string) $options['name']);
        if (file_exists($session['state_file']) || is_link($session['state_file'])) {
            throw new \RuntimeException("demo '{$options['name']}' already has a session; run `duo demo status` or `duo demo stop`");
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
            echo "Starting an exact, disposable WooCommerce pair. This can take a few minutes on the first image/artifact pull.\n";
            $up = self::runProcess(
                [
                    'bash', $sourceRoot . '/sandbox/bin/pair.sh', 'up', $options['name'],
                    (string) $options['source_port'], (string) $options['target_port'], '--http', '--artifacts',
                ],
                $sourceRoot,
                [
                    'DUO_SOURCE_ROOT' => $sourceRoot,
                    'DUO_EXPECTED_SOURCE_SHA' => trim(self::mustRun(['git', 'rev-parse', 'HEAD'], $sourceRoot)['stdout']),
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
            self::installWooCommerce($session, $sourceRoot);
            self::seedSourceCatalog($session);
            self::runDuo($session, $sourceRoot, $session['source_repo'], ['capture', 'demo-source'], true);
            self::git($session['source_repo'], ['add', '-A']);
            self::git($session['source_repo'], ['-c', 'user.name=duo-demo', '-c', 'user.email=demo@example.test', 'commit', '-m', 'demo: initial WooCommerce catalog']);
            self::git($session['source_repo'], ['push', '-u', 'origin', 'main']);
            self::cloneTargetRepository($session);
            self::runDuo($session, $sourceRoot, $session['target_repo'], ['deploy', 'demo-target'], true);
            self::establishHpos($session, 2);
            self::seedTargetProductRuntime($session);
            $revision = trim(self::git($session['target_repo'], ['rev-parse', 'HEAD'])['stdout']);
            self::runDuo(
                $session,
                $sourceRoot,
                $session['target_repo'],
                ['apply', 'demo-target', '--adopt-by-slug=terms,posts', '--default-author=admin', '--revision=' . $revision],
                true
            );
            $session['runtime_before'] = self::seedTargetRuntime($session);
            $session['last_applied_revision'] = $revision;
            $session['phase'] = 'ready';
            self::replaceSession($session);
        } catch (\Throwable $error) {
            try {
                self::teardownSession($sourceRoot, $session, false, null);
            } catch (\Throwable $cleanup) {
                throw new \RuntimeException($error->getMessage() . '; cleanup paused: ' . $cleanup->getMessage());
            }
            throw $error;
        }

        echo "\nDemo ready.\n";
        echo "  Source: http://localhost:{$options['source_port']}/wp-admin/\n";
        echo "  Target: http://localhost:{$options['target_port']}/wp-admin/\n";
        echo "  Login:  admin / admin\n";
        echo "  Repo:   {$session['source_repo']}\n\n";
        echo "Edit 'Duo Demo Mug' on the SOURCE site, then run:\n";
        echo '  ' . escapeshellarg(self::demoCli($sourceRoot)) . ' demo capture --name=' . $options['name'] . "\n";
        return 0;
    }

    private static function status(string $sourceRoot, string $name): int {
        $session = self::readSession($sourceRoot, $name);
        echo "Demo '$name' phase: {$session['phase']}.\n";
        echo "  source: http://localhost:{$session['source_port']} ({$session['source_repo']})\n";
        echo "  target: http://localhost:{$session['target_port']} ({$session['target_repo']})\n";
        if ($session['runtime_before'] !== '') {
            echo "  runtime proof: {$session['runtime_before']}\n";
        }
        return 0;
    }

    private static function capture(string $sourceRoot, string $name): int {
        $session = self::readSession($sourceRoot, $name);
        self::assertReady($session);
        self::runDuo($session, $sourceRoot, $session['source_repo'], ['capture', 'demo-source'], true);
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
                '-c', 'user.name=duo-demo', '-c', 'user.email=demo@example.test',
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
        self::git($session['source_repo'], ['push', 'origin', $revision . ':refs/heads/main']);
        self::git($session['target_repo'], ['pull', '--ff-only', 'origin', 'main']);
        self::runDuo($session, $sourceRoot, $session['target_repo'], ['deploy', 'demo-target'], true);
        $targetRevision = trim(self::git($session['target_repo'], ['rev-parse', 'HEAD'])['stdout']);
        if ($targetRevision !== $revision) {
            throw new \RuntimeException('target checkout does not match the pending source revision');
        }
        self::runDuo(
            $session,
            $sourceRoot,
            $session['target_repo'],
            ['apply', 'demo-target', '--adopt-by-slug=terms,posts', '--default-author=admin', '--revision=' . $revision],
            true
        );
        $after = self::targetRuntimeSnapshot($session);
        if (!hash_equals((string) $session['runtime_before'], $after)) {
            throw new \RuntimeException("target runtime changed across apply\n  before: {$session['runtime_before']}\n  after:  $after");
        }
        $session['last_applied_revision'] = $revision;
        $session['pending_revision'] = null;
        self::replaceSession($session);
        echo "Applied the reviewed Git revision to the target. Its order identity/status/total and live stock stayed byte-identical.\n";
        echo "Next:\n  " . escapeshellarg(self::demoCli($sourceRoot)) . " demo refusal --name=$name\n";
        return 0;
    }

    private static function refusal(string $sourceRoot, string $name): int {
        $session = self::readSession($sourceRoot, $name);
        self::assertReady($session);
        $before = self::targetRuntimeSnapshot($session);
        $result = self::runDuo(
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
        echo "PASS: Duo refused a caller-supplied target binding and the target order/stock proof stayed unchanged.\n";
        echo "Next:\n  " . escapeshellarg(self::demoCli($sourceRoot)) . " demo stop --name=$name\n";
        return 0;
    }

    private static function stop(string $sourceRoot, string $name, ?callable $phaseHook): int {
        $session = self::readSession($sourceRoot, $name, $phaseHook);
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
        return '# DUO_DEMO_OWNER=' . $session['ownership_token'] . ":compose_env_file\n"
            . 'DUO_PAIR=' . $session['name'] . "\n"
            . 'DUO_PORT1=' . $session['source_port'] . "\n"
            . 'DUO_PORT2=' . $session['target_port'] . "\n"
            . 'DUO_AGENT_SRC=' . $sourceRoot . "/agent\n"
            . 'DUO_ADAPTER_PACKAGES_SRC=' . $sourceRoot . "/adapter-packages\n"
            . 'DUO_PLATFORM_SRC=' . $sourceRoot . "/platform\n"
            . "DUO_DB_HOST=duo-shared-db\n";
    }

    /** @param array<string,mixed> $session */
    private static function installWooCommerce(array $session, string $sourceRoot): void {
        $envFile = escapeshellarg((string) $session['compose_env_file']);
        $script = 'set -euo pipefail; PAIR_COMPOSE=(docker compose --env-file ' . $envFile
            . ' -f pair.yml -f pair.artifacts.yml); . bin/fetch-artifact.sh; '
            . 'for side in 1 2; do artifact=$(fetch_artifact woocommerce ' . self::WOO_VERSION
            . ' "cli$side" plugin); "${PAIR_COMPOSE[@]}" run --rm -T "cli$side" wp plugin install "$artifact" --force; done; '
            . '"${PAIR_COMPOSE[@]}" run --rm -T cli1 wp plugin activate woocommerce';
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
    }

    /** @param array<string,mixed> $session */
    private static function prepareSourceRepository(array $session, ?callable $phaseHook): void {
        self::mustRun(['git', 'init', '--bare', '--initial-branch=main', $session['origin']], null);
        if ($phaseHook !== null) {
            $phaseHook('origin_initialized');
        }
        $policy = [
            'manifests' => ['core', 'woocommerce'],
            'policy' => [
                'options' => (object) [],
                'post_meta' => (object) [],
                'term_meta' => (object) [],
                'post_types' => ['post', 'page', 'attachment', 'product', 'product_variation', 'shop_coupon'],
                'taxonomies' => [
                    'category', 'post_tag', 'product_brand', 'product_cat', 'product_shipping_class',
                    'product_tag', 'product_type', 'product_visibility',
                ],
            ],
            'spec_version' => 3,
        ];
        $bytes = json_encode($policy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($bytes)) {
            throw new \RuntimeException('could not encode demo policy');
        }
        self::writeNew($session['source_repo'] . '/site.duo.json', $bytes . "\n", 0644);
        self::writeNew($session['source_repo'] . '/.gitignore', Adopt::repositoryGitignoreBytes(), 0644);
        self::writeOverlay($session, $session['source_repo']);
        self::mustRun(['git', 'init', '--initial-branch=main', $session['source_repo']], null);
        self::git($session['source_repo'], ['remote', 'add', 'origin', $session['origin']]);
        self::git($session['source_repo'], ['add', 'site.duo.json', '.gitignore']);
        self::git($session['source_repo'], [
            '-c', 'user.name=duo-demo', '-c', 'user.email=demo@example.test',
            'commit', '-m', 'demo: declare WooCommerce managed scope',
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
        self::writeNew($repo . '/.duo-envs.json', $bytes . "\n", 0600);
    }

    /** @param array<string,mixed> $session */
    private static function seedSourceCatalog(array $session): void {
        $php = '$product = new WC_Product_Simple(); '
            . '$product->set_name("Duo Demo Mug"); $product->set_slug("duo-demo-mug"); '
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
        $php = '$product = get_page_by_path("duo-demo-mug", OBJECT, "product"); '
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
    private static function seedTargetProductRuntime(array $session): void {
        $php = '$product = new WC_Product_Simple(); '
            . '$product->set_name("Target-local placeholder"); $product->set_slug("duo-demo-mug"); '
            . '$product->set_regular_price("1.00"); $product->set_manage_stock(true); '
            . '$product->set_stock_quantity(37); $product->set_status("publish"); $product->save(); echo $product->get_id();';
        $result = self::wp($session, 2, ['eval', $php]);
        if ($result['exit'] !== 0 || preg_match('/^[0-9]+$/D', trim($result['stdout'])) !== 1) {
            throw new \RuntimeException('could not seed the target-local product runtime');
        }
    }

    /** @param array<string,mixed> $session */
    private static function targetRuntimeSnapshot(array $session): string {
        $php = '$ids = wc_get_orders(["limit" => -1, "return" => "ids"]); sort($ids, SORT_NUMERIC); '
            . '$product = get_page_by_path("duo-demo-mug", OBJECT, "product"); '
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

    /** @param array<string,mixed> $session @return array{exit:int,stdout:string,stderr:string} */
    private static function wp(array $session, int $side, array $args): array {
        return self::runProcess(array_merge([
            'docker', 'compose', '--env-file', $session['compose_env_file'], '-f', $session['compose_file'],
            'run', '--rm', '-T', 'cli' . $side, 'wp',
        ], $args), dirname((string) $session['compose_file']));
    }

    /** @param array<string,mixed> $session @return array{exit:int,stdout:string,stderr:string} */
    private static function runDuo(
        array $session,
        string $sourceRoot,
        string $cwd,
        array $args,
        bool $passthrough,
        bool $mustSucceed = true
    ): array {
        $result = self::runProcess(
            array_merge([$sourceRoot . '/cli/duo'], $args),
            $cwd,
            [],
            $passthrough,
            $passthrough ? self::LIVE_PROCESS_TIMEOUT_MILLISECONDS : 30000
        );
        if ($mustSucceed && $result['exit'] !== 0) {
            throw new \RuntimeException('Duo command failed: ' . implode(' ', $args));
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
    private static function readSession(string $sourceRoot, string $name, ?callable $phaseHook = null): array {
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
        if (!is_array($data)
            || !is_array($opened) || !is_array($named)
            || $opened['dev'] !== $named['dev'] || $opened['ino'] !== $named['ino']
            || is_link($stateFile) || !is_file($stateFile)
            || ($data['format'] ?? null) !== self::FORMAT
            || ($data['name'] ?? null) !== $name
            || !is_int($data['source_port'] ?? null)
            || !is_int($data['target_port'] ?? null)) {
            throw new \RuntimeException("demo '$name' is not active; run `duo demo start --name=$name`");
        }
        self::port((string) $data['source_port'], 'stored source port');
        self::port((string) $data['target_port'], 'stored target port');
        $expected = self::sessionShape($sourceRoot, [
            'name' => $name,
            'source_port' => $data['source_port'],
            'target_port' => $data['target_port'],
            'ownership_token' => $data['ownership_token'] ?? null,
        ]);
        foreach (['source_repo', 'target_repo', 'origin', 'compose_file', 'compose_env_file', 'state_file'] as $field) {
            if (($data[$field] ?? null) !== $expected[$field]) {
                throw new \RuntimeException("demo '$name' session does not authorize its $field path");
            }
        }
        if (!in_array($data['phase'] ?? null, ['starting', 'ready', 'stopping'], true)
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
        return $data;
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
        if (!is_string($token)
            || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1
            || $claim !== $stateFile . '.remove-' . $token
            || ($session['format'] ?? null) !== self::FORMAT
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
            . '/.duo-demo-owner-' . $session['ownership_token'] . '-' . $field;
    }

    /** @param array<string,mixed> $session */
    private static function acquisitionStage(array $session, string $field): string {
        $path = (string) $session[$field];
        return dirname($path) . '/.duo-demo-acquire-' . $session['ownership_token'] . '-' . $field;
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
        return dirname($path) . '/.duo-demo-remove-' . $session['ownership_token'] . '-' . $field;
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
            $result = self::runProcess(['sh', '-c', 'command -v "$1"', 'duo-demo', $tool], null);
            if ($result['exit'] !== 0) {
                throw new \RuntimeException("required command '$tool' is unavailable");
            }
        }
    }

    /** @return resource */
    private static function lock(string $sourceRoot, string $name) {
        $path = sys_get_temp_dir() . '/duo-demo-' . hash('sha256', $sourceRoot . "\0" . $name) . '.lock';
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
            throw new \RuntimeException('demo setup is incomplete; run demo stop, then start it again');
        }
    }

    private static function demoCli(string $sourceRoot): string {
        return realpath($sourceRoot . '/cli/duo') ?: $sourceRoot . '/cli/duo';
    }

    private static function port(string $value, string $flag): int {
        if (preg_match('/^[0-9]+$/D', $value) !== 1 || (int) $value < 1024 || (int) $value > 65535) {
            throw new \RuntimeException("$flag must be an integer from 1024 through 65535");
        }
        return (int) $value;
    }
}

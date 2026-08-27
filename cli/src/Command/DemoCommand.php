<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/../Onboarding/Adopt.php';

/** Source-checkout, disposable two-site journey over the real Duo commands. */
final class DemoCommand {
    private const FORMAT = 'duo-demo-session/v1';
    private const DEFAULT_NAME = 'duodemo';
    private const DEFAULT_SOURCE_PORT = 8781;
    private const DEFAULT_TARGET_PORT = 8782;
    private const WOO_VERSION = '11.0.1';

    /** @param list<string> $args everything after `demo` */
    public static function run(array $args, string $sourceRoot): int {
        $action = array_shift($args);
        if (!is_string($action) || !in_array($action, ['start', 'status', 'capture', 'apply', 'refusal', 'stop'], true)) {
            fwrite(STDERR, "duo: demo: expected start, status, capture, apply, refusal, or stop\n");
            return 1;
        }
        try {
            $options = self::options($action, $args);
            return match ($action) {
                'start' => self::start($sourceRoot, $options),
                'status' => self::status($sourceRoot, $options['name']),
                'capture' => self::capture($sourceRoot, $options['name']),
                'apply' => self::apply($sourceRoot, $options['name']),
                'refusal' => self::refusal($sourceRoot, $options['name']),
                'stop' => self::stop($sourceRoot, $options['name']),
            };
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
    private static function start(string $sourceRoot, array $options): int {
        self::requireTools(['docker', 'git', 'jq']);
        $session = self::sessionShape($sourceRoot, $options);
        if (is_file($session['state_file'])) {
            throw new \RuntimeException("demo '{$options['name']}' already has a session; run `duo demo status` or `duo demo stop`");
        }
        foreach ([$session['source_repo'], $session['target_repo'], $session['origin']] as $path) {
            if (file_exists($path) || is_link($path)) {
                throw new \RuntimeException("refusing to reuse existing demo path $path");
            }
        }

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
            true
        );
        if ($up['exit'] !== 0) {
            throw new \RuntimeException('pair startup failed');
        }

        try {
            self::writeComposeEnv($session, $sourceRoot);
            self::installWooCommerce($session, $sourceRoot);
            self::prepareSourceRepository($session);
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
            self::writeSession($session);
        } catch (\Throwable $error) {
            self::destroyPair($sourceRoot, $options['name'], false);
            throw $error;
        }

        echo "\nDemo ready.\n";
        echo "  Source: http://localhost:{$options['source_port']}/wp-admin/\n";
        echo "  Target: http://localhost:{$options['target_port']}/wp-admin/\n";
        echo "  Login:  admin / admin\n";
        echo "  Repo:   {$session['source_repo']}\n\n";
        echo "Edit 'Duo Demo Mug' on the SOURCE site, then run:\n";
        echo '  ' . escapeshellarg($sourceRoot . '/cli/duo') . ' demo capture --name=' . $options['name'] . "\n";
        echo "Inspect the Git diff, then run `duo demo apply --name={$options['name']}`.\n";
        echo "Afterward, `duo demo refusal` proves a caller cannot override the trusted target binding, and `duo demo stop` removes the pair.\n";
        return 0;
    }

    private static function status(string $sourceRoot, string $name): int {
        $session = self::readSession($sourceRoot, $name);
        echo "Demo '$name' is active.\n";
        echo "  source: http://localhost:{$session['source_port']} ({$session['source_repo']})\n";
        echo "  target: http://localhost:{$session['target_port']} ({$session['target_repo']})\n";
        echo "  runtime proof: {$session['runtime_before']}\n";
        return 0;
    }

    private static function capture(string $sourceRoot, string $name): int {
        $session = self::readSession($sourceRoot, $name);
        self::runDuo($session, $sourceRoot, $session['source_repo'], ['capture', 'demo-source'], true);
        $diff = self::git($session['source_repo'], ['status', '--short']);
        if (trim($diff['stdout']) === '') {
            echo "Capture is byte-identical to the baseline; make an authored change on the source site first.\n";
            return 0;
        }
        echo "\nCaptured changes (review with `git -C " . escapeshellarg($session['source_repo']) . " diff`):\n";
        echo $diff['stdout'];
        return 0;
    }

    private static function apply(string $sourceRoot, string $name): int {
        $session = self::readSession($sourceRoot, $name);
        $status = trim(self::git($session['source_repo'], ['status', '--short'])['stdout']);
        if ($status === '') {
            throw new \RuntimeException('there is no captured Git diff; edit the source and run `duo demo capture` first');
        }
        self::git($session['source_repo'], ['add', '-A']);
        self::git($session['source_repo'], [
            '-c', 'user.name=duo-demo', '-c', 'user.email=demo@example.test',
            'commit', '-m', 'demo: capture authored source change',
        ]);
        self::git($session['source_repo'], ['push', 'origin', 'main']);
        self::git($session['target_repo'], ['pull', '--ff-only', 'origin', 'main']);
        self::runDuo($session, $sourceRoot, $session['target_repo'], ['deploy', 'demo-target'], true);
        $revision = trim(self::git($session['target_repo'], ['rev-parse', 'HEAD'])['stdout']);
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
        echo "Applied the reviewed Git revision to the target. Its order identity/status/total and live stock stayed byte-identical.\n";
        return 0;
    }

    private static function refusal(string $sourceRoot, string $name): int {
        $session = self::readSession($sourceRoot, $name);
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
        return 0;
    }

    private static function stop(string $sourceRoot, string $name): int {
        $session = self::readSession($sourceRoot, $name);
        $exit = self::destroyPair($sourceRoot, $name, true);
        if ($exit !== 0) {
            throw new \RuntimeException('pair teardown failed; repositories were retained for diagnosis');
        }
        foreach ([$session['source_repo'], $session['target_repo'], $session['origin']] as $path) {
            self::removeTree($path);
        }
        foreach ([$session['compose_env_file'], $session['state_file']] as $path) {
            if (is_file($path) && !is_link($path)) {
                unlink($path);
            }
        }
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
            'runtime_before' => '',
        ];
    }

    /** @param array<string,mixed> $session */
    private static function writeComposeEnv(array $session, string $sourceRoot): void {
        $bytes = 'DUO_PAIR=' . $session['name'] . "\n"
            . 'DUO_PORT1=' . $session['source_port'] . "\n"
            . 'DUO_PORT2=' . $session['target_port'] . "\n"
            . 'DUO_AGENT_SRC=' . $sourceRoot . "/agent\n"
            . 'DUO_MANIFESTS_SRC=' . $sourceRoot . "/manifests\n"
            . "DUO_DB_HOST=duo-shared-db\n";
        self::writeNew((string) $session['compose_env_file'], $bytes, 0600);
    }

    /** @param array<string,mixed> $session */
    private static function installWooCommerce(array $session, string $sourceRoot): void {
        $envFile = escapeshellarg((string) $session['compose_env_file']);
        $script = 'set -euo pipefail; PAIR_COMPOSE=(docker compose --env-file ' . $envFile
            . ' -f pair.yml -f pair.artifacts.yml); . bin/fetch-artifact.sh; '
            . 'for side in 1 2; do artifact=$(fetch_artifact woocommerce ' . self::WOO_VERSION
            . ' "cli$side" plugin); "${PAIR_COMPOSE[@]}" run --rm -T "cli$side" wp plugin install "$artifact" --force; done; '
            . '"${PAIR_COMPOSE[@]}" run --rm -T cli1 wp plugin activate woocommerce';
        $result = self::runProcess(['bash', '-c', $script], $sourceRoot . '/sandbox', [], true);
        if ($result['exit'] !== 0) {
            throw new \RuntimeException('could not install the digest-pinned WooCommerce demo artifact');
        }
        self::establishHpos($session, 1);
    }

    /** @param array<string,mixed> $session */
    private static function prepareSourceRepository(array $session): void {
        self::mustRun(['git', 'init', '--bare', '--initial-branch=main', $session['origin']], null);
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
        $result = self::runProcess(array_merge([$sourceRoot . '/cli/duo'], $args), $cwd, [], $passthrough);
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
        bool $passthrough = false
    ): array {
        $descriptors = $passthrough
            ? [0 => STDIN, 1 => STDOUT, 2 => STDERR]
            : [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $hostEnvironment = getenv();
        $environment = $extraEnv === []
            ? null
            : array_replace(is_array($hostEnvironment) ? $hostEnvironment : [], $extraEnv);
        $process = @proc_open($argv, $descriptors, $pipes, $cwd, $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return ['exit' => 127, 'stdout' => '', 'stderr' => 'could not start process'];
        }
        if ($passthrough) {
            return ['exit' => proc_close($process), 'stdout' => '', 'stderr' => ''];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [
            'exit' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    /** @return array<string,mixed> */
    private static function readSession(string $sourceRoot, string $name): array {
        $stateFile = $sourceRoot . '/sandbox/tmp/demo-' . $name . '.json';
        $data = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : null;
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT || ($data['name'] ?? null) !== $name) {
            throw new \RuntimeException("demo '$name' is not active; run `duo demo start --name=$name`");
        }
        return $data;
    }

    /** @param array<string,mixed> $session */
    private static function writeSession(array $session): void {
        $bytes = json_encode($session, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($bytes)) {
            throw new \RuntimeException('could not encode demo session');
        }
        self::writeNew((string) $session['state_file'], $bytes . "\n", 0600);
    }

    private static function writeNew(string $path, string $bytes, int $mode): void {
        if (file_exists($path) || is_link($path)) {
            throw new \RuntimeException("refusing to overwrite $path");
        }
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('could not create ' . dirname($path));
        }
        if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes) || !chmod($path, $mode)) {
            throw new \RuntimeException("could not write $path");
        }
    }

    private static function destroyPair(string $sourceRoot, string $name, bool $passthrough): int {
        return self::runProcess(
            ['bash', $sourceRoot . '/sandbox/bin/pair.sh', 'destroy', $name],
            $sourceRoot,
            [],
            $passthrough
        )['exit'];
    }

    private static function removeTree(string $root): void {
        if (!file_exists($root) && !is_link($root)) {
            return;
        }
        if (is_link($root) || is_file($root)) {
            unlink($root);
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            $entry->isDir() && !$entry->isLink() ? rmdir($path) : unlink($path);
        }
        rmdir($root);
    }

    private static function requireTools(array $tools): void {
        foreach ($tools as $tool) {
            $result = self::runProcess(['sh', '-c', 'command -v "$1"', 'duo-demo', $tool], null);
            if ($result['exit'] !== 0) {
                throw new \RuntimeException("required command '$tool' is unavailable");
            }
        }
    }

    private static function port(string $value, string $flag): int {
        if (preg_match('/^[0-9]+$/D', $value) !== 1 || (int) $value < 1024 || (int) $value > 65535) {
            throw new \RuntimeException("$flag must be an integer from 1024 through 65535");
        }
        return (int) $value;
    }
}

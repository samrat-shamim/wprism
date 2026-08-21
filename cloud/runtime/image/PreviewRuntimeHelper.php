<?php
declare(strict_types=1);

namespace Duo\Cloud\Image;

/** Fixed, dependency-free helper implementation baked into the reviewed image. */
final class PreviewRuntimeHelper {
    private const STATUS_FORMAT = 'duo-cloud-preview-runtime-status/v1';
    private const STAGE_FORMAT = 'duo-cloud-preview-runtime-stage/v1';
    private const STATE_FORMAT = 'duo-cloud-preview-image-state/v1';
    private const ARCHIVE_LIMIT = 536870912;
    private const FILE_LIMIT = 1048576;
    private const OUTPUT_LIMIT = 16777216;
    private const PROCESS_ENVIRONMENT = [
        'LANG' => 'C',
        'LC_ALL' => 'C',
        'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
    ];

    /** @var \Closure(array,?string):array{exit:int,stderr:string,stdout:string} */
    private \Closure $run;

    public function __construct(
        private string $databaseRoot = '/var/lib/duo/database',
        private string $wordpressRoot = '/var/lib/duo/wordpress',
        private string $repositoryRoot = '/srv/duo/repository',
        private string $secretRoot = '/run/secrets/duo',
        ?callable $run = null,
        private string $inputPath = 'php://stdin',
        private int $processTimeoutSeconds = 300,
        private string $runtimeRoot = '/run/duo'
    ) {
        if ($processTimeoutSeconds < 1 || $processTimeoutSeconds > 3600) {
            throw new \RuntimeException('reviewed runtime process timeout is outside its closed range');
        }
        if ($runtimeRoot === '' || $runtimeRoot[0] !== '/' || str_contains($runtimeRoot, "\0")
            || preg_match('#(?:^|/)\.\.?(/|$)#D', $runtimeRoot) === 1) {
            throw new \RuntimeException('reviewed runtime process root is invalid');
        }
        $this->run = $run === null
            ? fn (array $argv, ?string $stdin): array => $this->nativeRun($argv, $stdin)
            : \Closure::fromCallable($run);
    }

    /** @param list<string> $arguments */
    public function dispatch(string $program, array $arguments): int {
        return match ($program) {
            'duo-preview-runtime' => $this->serve($arguments),
            'runtime-status' => $this->status($arguments),
            'materialize-repository' => $this->materializeRepository($arguments),
            'restore-database' => $this->restoreDatabase($arguments),
            'restore-media' => $this->restoreMedia($arguments),
            'url-rebind' => $this->urlRebind($arguments),
            'duo-preview-command' => $this->command($arguments),
            default => throw new \RuntimeException('unknown reviewed runtime helper'),
        };
    }

    /** @param list<string> $arguments */
    private function status(array $arguments): int {
        $options = self::options($arguments, [
            'config-sha256', 'format', 'lease-generation', 'reviewed-base-sha256',
        ]);
        if ($options['format'] !== self::STATUS_FORMAT) {
            throw new \RuntimeException('runtime status format is invalid');
        }
        $state = $this->readState();
        self::assertAuthority($options, $state);
        $ready = ($state['ready'] ?? null) === true && $this->readyMarkerMatches($state);
        self::writeJson([
            'clean_base' => $state['clean_base'],
            'configuration_sha256' => $state['configuration_sha256'],
            'format' => self::STATUS_FORMAT,
            'lease_generation' => $state['lease_generation'],
            'ready' => $ready,
            'reviewed_base_sha256' => $state['reviewed_base_sha256'],
        ]);
        return 0;
    }

    /** @param list<string> $arguments */
    private function materializeRepository(array $arguments): int {
        $options = self::options($arguments, [
            'branch-commit', 'branch-ref', 'config-sha256', 'destination',
            'format', 'lease-generation', 'reviewed-base-sha256',
        ]);
        $state = $this->stageState($options, 'materialize-repository');
        if ($options['destination'] !== $this->repositoryRoot
            || preg_match('/\A[a-f0-9]{40}(?:[a-f0-9]{24})?\z/D', $options['branch-commit']) !== 1
            || preg_match('#\Arefs/(?:heads|tags)/[A-Za-z0-9._/-]+\z#D', $options['branch-ref']) !== 1) {
            throw new \RuntimeException('repository materialization identity is invalid');
        }
        $archive = $this->captureStdin('repository');
        try {
            $this->replaceFromTar($archive, $this->repositoryRoot);
        } finally {
            @unlink($archive);
        }
        $this->markChanged($state);
        $this->stageComplete('materialize-repository', $state);
        return 0;
    }

    /** @param list<string> $arguments */
    private function restoreMedia(array $arguments): int {
        $options = self::options($arguments, [
            'config-sha256', 'format', 'lease-generation', 'media-sha256',
            'reviewed-base-sha256', 'snapshot-set-id',
        ]);
        $state = $this->stageState($options, 'restore-media');
        self::identifier($options['snapshot-set-id'], 'snapshot set id');
        self::sha256($options['media-sha256'], 'media digest');
        $archive = $this->captureStdin('media');
        try {
            $actual = hash_file('sha256', $archive);
            if (!is_string($actual) || !hash_equals($options['media-sha256'], $actual)) {
                throw new \RuntimeException('media stream differs from its approved digest');
            }
            $this->replaceFromTar($archive, $this->wordpressRoot . '/wp-content/uploads');
        } finally {
            @unlink($archive);
        }
        $this->markChanged($state);
        $this->stageComplete('restore-media', $state);
        return 0;
    }

    /** @param list<string> $arguments */
    private function restoreDatabase(array $arguments): int {
        $options = self::options($arguments, [
            'config-sha256', 'database-sha256', 'format', 'lease-generation',
            'reviewed-base-sha256', 'snapshot-set-id',
        ]);
        $state = $this->stageState($options, 'restore-database');
        self::identifier($options['snapshot-set-id'], 'snapshot set id');
        self::sha256($options['database-sha256'], 'database digest');
        $sql = $this->captureStdin('database');
        try {
            $actual = hash_file('sha256', $sql);
            if (!is_string($actual) || !hash_equals($options['database-sha256'], $actual)) {
                throw new \RuntimeException('database stream differs from its approved digest');
            }
            $result = ($this->run)([
                '/usr/bin/mariadb', '--no-defaults', '--protocol=socket',
                '--socket=' . $this->runtimeRoot . '/mariadb.sock', '--user=root', 'wordpress',
            ], $sql);
            self::mustSucceed($result, 'database restore');
        } finally {
            @unlink($sql);
        }
        $this->markChanged($state);
        $this->stageComplete('restore-database', $state);
        return 0;
    }

    /** @param list<string> $arguments */
    private function urlRebind(array $arguments): int {
        $options = self::options($arguments, [
            'config-sha256', 'format', 'lease-generation', 'reviewed-base-sha256', 'url',
        ]);
        $state = $this->stageState($options, 'url-rebind');
        if (filter_var($options['url'], FILTER_VALIDATE_URL) === false
            || !str_starts_with($options['url'], 'https://')
            || str_contains($options['url'], '@') || str_contains($options['url'], '?')
            || str_contains($options['url'], '#')) {
            throw new \RuntimeException('preview URL is not credential-free HTTPS');
        }
        foreach (['home', 'siteurl'] as $option) {
            $result = ($this->run)([
                '/usr/local/bin/wp', '--path=' . $this->wordpressRoot,
                'option', 'update', $option, $options['url'], '--quiet',
            ], null);
            self::mustSucceed($result, "WordPress $option rebind");
        }
        $state['url'] = $options['url'];
        $this->markChanged($state);
        $this->stageComplete('url-rebind', $state);
        return 0;
    }

    /** @param list<string> $arguments */
    private function command(array $arguments): int {
        $action = array_shift($arguments);
        if ($action === 'wp') {
            if (array_shift($arguments) !== '--' || $arguments === []) {
                throw new \RuntimeException('reviewed WordPress command framing is invalid');
            }
            foreach ($arguments as $argument) {
                if ($argument === '' || str_contains($argument, "\0")) {
                    throw new \RuntimeException('reviewed WordPress command argument is invalid');
                }
            }
            $result = ($this->run)(array_merge([
                '/usr/local/bin/wp', '--path=' . $this->wordpressRoot,
            ], $arguments), null);
        } elseif ($action === 'raw' && $arguments === []) {
            $input = $this->captureStdin('raw-command');
            try {
                $result = ($this->run)(['/bin/sh', '-seu'], $input);
            } finally {
                @unlink($input);
            }
        } else {
            throw new \RuntimeException('reviewed workload command action is invalid');
        }
        echo $result['stdout'];
        fwrite(STDERR, $result['stderr']);
        return $result['exit'];
    }

    /** @param list<string> $arguments */
    private function serve(array $arguments): int {
        if (array_shift($arguments) !== 'serve' || array_shift($arguments) !== '--clean-base') {
            throw new \RuntimeException('reviewed runtime serve framing is invalid');
        }
        $options = self::options($arguments, [
            'config-sha256', 'lease-generation', 'reviewed-base-sha256',
        ]);
        self::sha256($options['config-sha256'], 'runtime configuration digest');
        self::sha256($options['reviewed-base-sha256'], 'reviewed base digest');
        $generation = self::positiveInt($options['lease-generation'], 'lease generation');
        $this->ensurePrivateDirectory($this->databaseRoot);
        $this->ensurePrivateDirectory($this->wordpressRoot);
        $this->ensurePrivateDirectory($this->runtimeRoot);
        $readyMarker = $this->runtimeRoot . '/runtime-ready.json';
        if (is_file($readyMarker) && !unlink($readyMarker)) {
            throw new \RuntimeException('reviewed runtime readiness marker could not be cleared');
        }
        $statePath = $this->databaseRoot . '/runtime-state.json';
        $cleanBase = !is_file($statePath);
        if (!$cleanBase) {
            $previous = $this->readState();
            if (($previous['configuration_sha256'] ?? null) !== $options['config-sha256']
                || ($previous['lease_generation'] ?? null) !== $generation
                || ($previous['reviewed_base_sha256'] ?? null) !== $options['reviewed-base-sha256']) {
                throw new \RuntimeException('reviewed runtime restart authority is stale or foreign');
            }
        }
        $this->writeState([
            'clean_base' => $cleanBase,
            'configuration_sha256' => $options['config-sha256'],
            'format' => self::STATE_FORMAT,
            'lease_generation' => $generation,
            'ready' => false,
            'reviewed_base_sha256' => $options['reviewed-base-sha256'],
            'url' => 'http://127.0.0.1:8080',
        ]);
        $databasePassword = $this->secret('database-password');
        $authKey = $this->secret('wordpress-auth-key');
        $authSalt = $this->secret('wordpress-auth-salt');
        $this->initializeDatabase();
        $database = $this->spawn([
            '/usr/sbin/mariadbd', '--no-defaults', '--datadir=' . $this->databaseRoot,
            '--socket=' . $this->runtimeRoot . '/mariadb.sock',
            '--pid-file=' . $this->runtimeRoot . '/mariadb.pid',
            '--skip-networking', '--skip-name-resolve',
            '--log-error=' . $this->runtimeRoot . '/mariadb.err',
        ], $this->runtimeRoot . '/mariadb.out', $this->runtimeRoot . '/mariadb.err');
        try {
            $this->waitForDatabase($database);
            $this->configureDatabase($databasePassword);
            $this->initializeWordPress($databasePassword, $authKey, $authSalt);
            $apache = $this->spawn(
                ['/usr/local/bin/apache2-foreground'],
                $this->runtimeRoot . '/apache.out',
                $this->runtimeRoot . '/apache.err'
            );
            $this->waitForHttp($apache);
            $this->writeFile($readyMarker, self::canonical([
                'configuration_sha256' => $options['config-sha256'],
                'format' => 'duo-cloud-preview-process-readiness/v1',
                'lease_generation' => $generation,
                'reviewed_base_sha256' => $options['reviewed-base-sha256'],
            ]) . "\n", 0600);
            $this->writeState([
                'clean_base' => $cleanBase,
                'configuration_sha256' => $options['config-sha256'],
                'format' => self::STATE_FORMAT,
                'lease_generation' => $generation,
                'ready' => true,
                'reviewed_base_sha256' => $options['reviewed-base-sha256'],
                'url' => 'http://127.0.0.1:8080',
            ]);
            while (true) {
                $databaseStatus = proc_get_status($database);
                $apacheStatus = proc_get_status($apache);
                if (!is_array($databaseStatus) || !is_array($apacheStatus)
                    || $databaseStatus['running'] !== true || $apacheStatus['running'] !== true) {
                    proc_terminate($database);
                    proc_terminate($apache);
                    proc_close($apache);
                    proc_close($database);
                    throw new \RuntimeException('reviewed runtime child process stopped');
                }
                usleep(250000);
            }
        } catch (\Throwable $error) {
            proc_terminate($database);
            proc_close($database);
            throw $error;
        }
    }

    private function initializeDatabase(): void {
        if (is_dir($this->databaseRoot . '/mysql')) {
            return;
        }
        $result = ($this->run)([
            '/usr/bin/mariadb-install-db', '--no-defaults', '--datadir=' . $this->databaseRoot,
            '--auth-root-authentication-method=normal', '--skip-test-db',
        ], null);
        self::mustSucceed($result, 'database initialization');
    }

    /** @param resource $database */
    private function waitForDatabase($database): void {
        for ($attempt = 0; $attempt < 120; $attempt++) {
            $status = proc_get_status($database);
            if (!is_array($status) || $status['running'] !== true) {
                throw new \RuntimeException('database stopped during readiness');
            }
            $result = ($this->run)([
                '/usr/bin/mariadb-admin', '--no-defaults', '--protocol=socket',
                '--socket=' . $this->runtimeRoot . '/mariadb.sock', '--user=root', 'ping', '--silent',
            ], null);
            if ($result['exit'] === 0) {
                return;
            }
            usleep(250000);
        }
        throw new \RuntimeException('database readiness timed out');
    }

    /** @param resource $apache */
    private function waitForHttp($apache): void {
        $lastStatusLine = '';
        for ($attempt = 0; $attempt < 120; $attempt++) {
            $status = proc_get_status($apache);
            if (!is_array($status) || $status['running'] !== true) {
                throw new \RuntimeException('HTTP server stopped during readiness');
            }
            $socket = @fsockopen('127.0.0.1', 8080, $errorCode, $errorMessage, 0.25);
            if (is_resource($socket)) {
                stream_set_timeout($socket, 1);
                fwrite($socket, "GET /wp-login.php HTTP/1.0\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
                $statusLine = fgets($socket, 256);
                fclose($socket);
                $lastStatusLine = is_string($statusLine) ? trim($statusLine) : '';
                if (is_string($statusLine)
                    && preg_match('#\AHTTP/1\.[01] [23][0-9]{2}(?: |\r?\n)#D', $statusLine) === 1) {
                    return;
                }
            }
            usleep(250000);
        }
        $apacheError = @file_get_contents($this->runtimeRoot . '/apache.err');
        throw new \RuntimeException(
            'HTTP server readiness timed out: ' . substr(
                $lastStatusLine . ' ' . (is_string($apacheError) ? $apacheError : ''),
                0,
                1024
            )
        );
    }

    private function configureDatabase(string $password): void {
        if (preg_match('/\A[A-Za-z0-9_-]{32,128}\z/D', $password) !== 1) {
            throw new \RuntimeException('database password alphabet is invalid');
        }
        $sql = $this->temporary('database-bootstrap');
        $statement = "CREATE DATABASE IF NOT EXISTS wordpress CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
            . "CREATE USER IF NOT EXISTS 'wordpress'@'localhost' IDENTIFIED BY '$password';\n"
            . "ALTER USER 'wordpress'@'localhost' IDENTIFIED BY '$password';\n"
            . "GRANT ALL PRIVILEGES ON wordpress.* TO 'wordpress'@'localhost';\nFLUSH PRIVILEGES;\n";
        file_put_contents($sql, $statement, LOCK_EX);
        try {
            $result = ($this->run)([
                '/usr/bin/mariadb', '--no-defaults', '--protocol=socket',
                '--socket=' . $this->runtimeRoot . '/mariadb.sock', '--user=root',
            ], $sql);
            self::mustSucceed($result, 'database account initialization');
        } finally {
            @unlink($sql);
        }
    }

    private function initializeWordPress(string $password, string $authKey, string $authSalt): void {
        if (!is_file($this->wordpressRoot . '/wp-load.php')) {
            $this->copyTree('/usr/src/wordpress', $this->wordpressRoot);
        }
        $this->copyTree('/opt/duo/dropin/agent', $this->wordpressRoot . '/wp-content/mu-plugins/duo');
        $this->copyTree('/opt/duo/dropin/manifests', $this->wordpressRoot . '/wp-content/mu-plugins/duo-manifests');
        copy('/opt/duo/dropin/agent/duo-loader.php', $this->wordpressRoot . '/wp-content/mu-plugins/duo-loader.php');
        $repoTarget = $this->wordpressRoot . '/duo/repository';
        if (!is_dir($repoTarget) && !mkdir($repoTarget, 0700, true)) {
            throw new \RuntimeException('workload repository root could not be created');
        }
        $config = $this->wordpressRoot . '/wp-config.php';
        if (!is_file($config)) {
            $this->writeFile(
                $config,
                self::wordpressConfiguration($password, $authKey, $authSalt),
                0600
            );
        }
        $installed = ($this->run)([
            '/usr/local/bin/wp', '--path=' . $this->wordpressRoot, 'core', 'is-installed', '--quiet',
        ], null);
        if ($installed['exit'] !== 0) {
            $adminPassword = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $input = $this->temporary('admin-password');
            file_put_contents($input, $adminPassword . "\n", LOCK_EX);
            try {
                $result = ($this->run)([
                    '/usr/local/bin/wp', '--path=' . $this->wordpressRoot, 'core', 'install',
                    '--url=http://127.0.0.1:8080', '--title=Duo Preview', '--admin_user=duo-admin',
                    '--admin_email=duo-preview@example.invalid', '--skip-email', '--prompt=admin_password',
                ], $input);
                self::mustSucceed($result, 'WordPress clean-base installation');
            } finally {
                @unlink($input);
            }
        }
    }

    private static function wordpressConfiguration(
        string $password,
        string $authKey,
        string $authSalt
    ): string {
        $salts = [];
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $name) {
            $salts[$name] = hash_hmac('sha256', $name . "\0" . $authSalt, $authKey);
        }
        $php = "<?php\n"
            . "define('DB_NAME', 'wordpress');\n"
            . "define('DB_USER', 'wordpress');\n"
            . "define('DB_PASSWORD', " . var_export($password, true) . ");\n"
            . "define('DB_HOST', 'localhost:/run/duo/mariadb.sock');\n"
            . "define('DB_CHARSET', 'utf8mb4');\ndefine('DB_COLLATE', '');\n"
            . "define('DISABLE_WP_CRON', true);\n"
            . "define('DISALLOW_FILE_MODS', true);\n"
            . "define('WP_ENVIRONMENT_TYPE', 'staging');\n";
        foreach ($salts as $name => $value) {
            $php .= "define('$name', " . var_export($value, true) . ");\n";
        }
        return $php . "\$table_prefix = 'wp_';\ndefine('WP_DEBUG', false);\n"
            . "if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');\nrequire_once ABSPATH . 'wp-settings.php';\n";
    }

    /** @param array<string,string> $options @return array<string,mixed> */
    private function stageState(array $options, string $stage): array {
        if ($options['format'] !== self::STAGE_FORMAT) {
            throw new \RuntimeException("$stage format is invalid");
        }
        $state = $this->readState();
        self::assertAuthority($options, $state);
        if (($state['ready'] ?? null) !== true) {
            throw new \RuntimeException("$stage requires a ready runtime");
        }
        return $state;
    }

    /** @param array<string,mixed> $state */
    private function markChanged(array &$state): void {
        $state['clean_base'] = false;
        $this->writeState($state);
    }

    /** @param array<string,mixed> $state */
    private function stageComplete(string $stage, array $state): void {
        self::writeJson([
            'configuration_sha256' => $state['configuration_sha256'],
            'format' => self::STAGE_FORMAT,
            'reviewed_base_sha256' => $state['reviewed_base_sha256'],
            'stage' => $stage,
            'state' => 'complete',
        ]);
    }

    private function replaceFromTar(string $archive, string $destination): void {
        $parent = dirname($destination);
        if (!is_dir($parent) && !mkdir($parent, 0700, true)) {
            throw new \RuntimeException('archive destination parent could not be created');
        }
        [$stage, $backup] = self::replacementPaths($destination);
        $this->reconcileReplacement($destination, $stage, $backup);
        if (!mkdir($stage, 0700)) {
            throw new \RuntimeException('archive stage could not be created');
        }
        try {
            $phar = new \PharData($archive, 0, null, \Phar::TAR);
            $entries = 0;
            $bytes = 0;
            $canonicalArchive = realpath($archive);
            if (!is_string($canonicalArchive)) {
                throw new \RuntimeException('archive path is not canonical');
            }
            $prefix = 'phar://' . $canonicalArchive . '/';
            /** @var \PharFileInfo $entry */
            foreach (new \RecursiveIteratorIterator($phar) as $entry) {
                $entries++;
                $bytes += $entry->getSize();
                $path = str_starts_with($entry->getPathname(), $prefix)
                    ? substr($entry->getPathname(), strlen($prefix))
                    : '';
                if ($entries > 100000 || $bytes > self::ARCHIVE_LIMIT || !self::safeArchivePath($path)
                    || $entry->isLink()) {
                    throw new \RuntimeException('archive contains an unsafe or oversized entry');
                }
            }
            $phar->extractTo($stage, null, true);
            $hadDestination = file_exists($destination) || is_link($destination);
            if ($hadDestination && (!is_dir($destination) || is_link($destination)
                || !rename($destination, $backup))) {
                throw new \RuntimeException('archive destination could not be isolated for replacement');
            }
            if ($hadDestination) {
                $this->syncDirectory($parent, 'archive replacement parent');
            }
            if (!rename($stage, $destination)) {
                if ($hadDestination) {
                    @rename($backup, $destination);
                    $this->syncDirectory($parent, 'archive replacement parent');
                }
                throw new \RuntimeException('archive stage could not be atomically published');
            }
            $this->syncDirectory($parent, 'archive replacement parent');
            if ($hadDestination) {
                $this->removeTree($backup);
                $this->syncDirectory($parent, 'archive replacement parent');
            }
        } finally {
            if (is_dir($stage)) {
                $this->removeTree($stage);
                $this->syncDirectory($parent, 'archive replacement parent');
            }
        }
    }

    /** @return array{string,string} */
    private static function replacementPaths(string $destination): array {
        $parent = dirname($destination);
        $token = hash('sha256', "duo-preview-runtime-replacement/v1\0" . $destination);
        return [
            $parent . '/.duo-stage-' . $token,
            $parent . '/.duo-backup-' . $token,
        ];
    }

    private function reconcileReplacement(string $destination, string $stage, string $backup): void {
        $parent = dirname($destination);
        foreach ([$destination, $stage, $backup] as $path) {
            clearstatcache(true, $path);
            if (@lstat($path) !== false && (!is_dir($path) || is_link($path))) {
                throw new \RuntimeException('archive replacement residue is not a directory');
            }
        }
        $backupExists = is_dir($backup);
        $destinationExists = is_dir($destination);
        if ($backupExists && !$destinationExists) {
            if (is_dir($stage)) {
                $this->removeTree($stage);
                $this->syncDirectory($parent, 'archive replacement parent');
            }
            if (!rename($backup, $destination)) {
                throw new \RuntimeException('archive replacement backup could not be restored');
            }
            $this->syncDirectory($parent, 'archive replacement parent');
            return;
        }
        if (is_dir($stage)) {
            $this->removeTree($stage);
            $this->syncDirectory($parent, 'archive replacement parent');
        }
        if ($backupExists) {
            $this->removeTree($backup);
            $this->syncDirectory($parent, 'archive replacement parent');
        }
    }

    private function syncDirectory(string $path, string $label): void {
        if (!function_exists('fsync')) {
            return;
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException("$label could not be opened for synchronization");
        }
        $synced = @fsync($handle);
        $closed = fclose($handle);
        if (!$synced || !$closed) {
            throw new \RuntimeException("$label could not be synchronized");
        }
    }

    private function captureStdin(string $label): string {
        $path = $this->temporary(
            $label,
            in_array($label, ['media', 'repository'], true) ? '.tar' : ''
        );
        $input = fopen($this->inputPath, 'rb');
        $output = fopen($path, 'wb');
        if (!is_resource($input) || !is_resource($output)) {
            @unlink($path);
            throw new \RuntimeException("$label stream could not be opened");
        }
        $bytes = stream_copy_to_stream($input, $output, self::ARCHIVE_LIMIT + 1);
        fclose($input);
        $closed = fclose($output);
        if (!is_int($bytes) || $bytes < 1 || $bytes > self::ARCHIVE_LIMIT || !$closed) {
            @unlink($path);
            throw new \RuntimeException("$label stream is empty or exceeds its limit");
        }
        return $path;
    }

    private function secret(string $name): string {
        $path = $this->secretRoot . '/' . $name;
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path) || !is_file($path)
            || (($stat['mode'] ?? 0) & 0077) !== 0 || (int) $stat['size'] > 512) {
            throw new \RuntimeException('reviewed runtime secret file is not private');
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || !str_ends_with($bytes, "\n") || substr_count($bytes, "\n") !== 1) {
            throw new \RuntimeException('reviewed runtime secret file is malformed');
        }
        return substr($bytes, 0, -1);
    }

    /** @return array<string,mixed> */
    private function readState(): array {
        $path = $this->databaseRoot . '/runtime-state.json';
        $this->reconcileFileTemporary($path, 0600);
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || strlen($bytes) > 65536) {
            throw new \RuntimeException('reviewed runtime state is unavailable');
        }
        $state = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($state) || array_is_list($state) || self::canonical($state) . "\n" !== $bytes
            || ($state['format'] ?? null) !== self::STATE_FORMAT) {
            throw new \RuntimeException('reviewed runtime state is not canonical');
        }
        return $state;
    }

    /** @param array<string,mixed> $state */
    private function readyMarkerMatches(array $state): bool {
        $path = $this->runtimeRoot . '/runtime-ready.json';
        $this->reconcileFileTemporary($path, 0600);
        $bytes = @file_get_contents($path);
        if (!is_string($bytes) || strlen($bytes) > 4096) {
            return false;
        }
        try {
            $marker = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return false;
        }
        if (!is_array($marker) || array_is_list($marker)
            || self::canonical($marker) . "\n" !== $bytes) {
            return false;
        }
        $keys = array_keys($marker);
        $expected = [
            'configuration_sha256', 'format', 'lease_generation', 'reviewed_base_sha256',
        ];
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        return $keys === $expected
            && ($marker['format'] ?? null) === 'duo-cloud-preview-process-readiness/v1'
            && ($marker['configuration_sha256'] ?? null) === ($state['configuration_sha256'] ?? null)
            && ($marker['lease_generation'] ?? null) === ($state['lease_generation'] ?? null)
            && ($marker['reviewed_base_sha256'] ?? null) === ($state['reviewed_base_sha256'] ?? null);
    }

    /** @param array<string,mixed> $state */
    private function writeState(array $state): void {
        $this->writeFile($this->databaseRoot . '/runtime-state.json', self::canonical($state) . "\n", 0600);
    }

    /** @param array<string,string> $options @param array<string,mixed> $state */
    private static function assertAuthority(array $options, array $state): void {
        self::sha256($options['config-sha256'], 'runtime configuration digest');
        self::sha256($options['reviewed-base-sha256'], 'reviewed base digest');
        $generation = self::positiveInt($options['lease-generation'], 'lease generation');
        if (($state['configuration_sha256'] ?? null) !== $options['config-sha256']
            || ($state['reviewed_base_sha256'] ?? null) !== $options['reviewed-base-sha256']
            || ($state['lease_generation'] ?? null) !== $generation) {
            throw new \RuntimeException('reviewed runtime helper authority is stale or foreign');
        }
    }

    /** @param list<string> $arguments @param list<string> $names @return array<string,string> */
    private static function options(array $arguments, array $names): array {
        $allowed = array_fill_keys($names, true);
        $result = [];
        while ($arguments !== []) {
            $flag = array_shift($arguments);
            $value = array_shift($arguments);
            if (!is_string($flag) || !str_starts_with($flag, '--') || !is_string($value)
                || $value === '' || str_contains($value, "\0")) {
                throw new \RuntimeException('reviewed runtime helper arguments are malformed');
            }
            $name = substr($flag, 2);
            if (!isset($allowed[$name]) || isset($result[$name])) {
                throw new \RuntimeException('reviewed runtime helper option is unknown or repeated');
            }
            $result[$name] = $value;
        }
        $actual = array_keys($result);
        sort($actual, SORT_STRING);
        sort($names, SORT_STRING);
        if ($actual !== $names) {
            throw new \RuntimeException('reviewed runtime helper options are incomplete');
        }
        return $result;
    }

    /** @param array{exit:int,stderr:string,stdout:string} $result */
    private static function mustSucceed(array $result, string $label): void {
        if ($result['exit'] !== 0) {
            throw new \RuntimeException("$label failed");
        }
    }

    /** @param non-empty-list<string> $argv @return array{exit:int,stderr:string,stdout:string} */
    private function nativeRun(array $argv, ?string $stdin): array {
        $stdoutFile = tmpfile();
        $stderrFile = tmpfile();
        if (!is_resource($stdoutFile) || !is_resource($stderrFile)) {
            if (is_resource($stdoutFile)) {
                fclose($stdoutFile);
            }
            if (is_resource($stderrFile)) {
                fclose($stderrFile);
            }
            throw new \RuntimeException('reviewed runtime process output files could not be created');
        }
        $descriptors = [
            0 => $stdin === null ? ['file', '/dev/null', 'rb'] : ['file', $stdin, 'rb'],
            1 => $stdoutFile,
            2 => $stderrFile,
        ];
        $process = proc_open($argv, $descriptors, $pipes, null, self::PROCESS_ENVIRONMENT, [
            'bypass_shell' => true,
        ]);
        if (!is_resource($process)) {
            fclose($stdoutFile);
            fclose($stderrFile);
            throw new \RuntimeException('reviewed runtime process could not start');
        }
        $deadline = self::monotonicNow() + $this->processTimeoutSeconds;
        $exit = -1;
        while (true) {
            $status = proc_get_status($process);
            $stdoutStat = fstat($stdoutFile);
            $stderrStat = fstat($stderrFile);
            if (!is_array($status) || !is_array($stdoutStat) || !is_array($stderrStat)) {
                proc_terminate($process, 9);
                proc_close($process);
                fclose($stdoutFile);
                fclose($stderrFile);
                throw new \RuntimeException('reviewed runtime process status could not be read');
            }
            if ((int) $stdoutStat['size'] > self::OUTPUT_LIMIT
                || (int) $stderrStat['size'] > self::OUTPUT_LIMIT) {
                proc_terminate($process, 9);
                proc_close($process);
                fclose($stdoutFile);
                fclose($stderrFile);
                throw new \RuntimeException('reviewed runtime process output exceeded its limit');
            }
            if ($status['running'] !== true) {
                $exit = (int) $status['exitcode'];
                break;
            }
            if (self::monotonicNow() >= $deadline) {
                proc_terminate($process);
                usleep(100000);
                $status = proc_get_status($process);
                if (is_array($status) && $status['running'] === true) {
                    proc_terminate($process, 9);
                }
                proc_close($process);
                fclose($stdoutFile);
                fclose($stderrFile);
                throw new \RuntimeException('reviewed runtime process exceeded its deadline');
            }
            usleep(10000);
        }
        $closedExit = proc_close($process);
        if ($exit < 0) {
            $exit = $closedExit;
        }
        rewind($stdoutFile);
        rewind($stderrFile);
        $stdout = stream_get_contents($stdoutFile, self::OUTPUT_LIMIT + 1);
        $stderr = stream_get_contents($stderrFile, self::OUTPUT_LIMIT + 1);
        fclose($stdoutFile);
        fclose($stderrFile);
        if (!is_string($stdout) || !is_string($stderr)
            || strlen($stdout) > self::OUTPUT_LIMIT || strlen($stderr) > self::OUTPUT_LIMIT) {
            throw new \RuntimeException('reviewed runtime process output exceeded its limit');
        }
        return ['exit' => $exit, 'stderr' => $stderr, 'stdout' => $stdout];
    }

    private static function monotonicNow(): float {
        return hrtime(true) / 1_000_000_000;
    }

    /** @return resource */
    private function spawn(array $argv, string $stdout, string $stderr) {
        $process = proc_open($argv, [
            0 => ['file', '/dev/null', 'rb'],
            1 => ['file', $stdout, 'ab'],
            2 => ['file', $stderr, 'ab'],
        ], $pipes, null, self::PROCESS_ENVIRONMENT, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new \RuntimeException('reviewed runtime child could not start');
        }
        return $process;
    }

    private function temporary(string $label, string $suffix = ''): string {
        $root = is_dir($this->runtimeRoot) ? $this->runtimeRoot : sys_get_temp_dir();
        $safeLabel = preg_replace('/[^a-z0-9-]/', '-', $label);
        if (!is_string($safeLabel) || $safeLabel === ''
            || !in_array($suffix, ['', '.tar'], true)) {
            throw new \RuntimeException('reviewed runtime temporary identity is invalid');
        }
        $path = $root . '/duo-' . $safeLabel . $suffix;
        $this->removeEphemeralFile($path);
        $previousUmask = umask(0077);
        try {
            $handle = fopen($path, 'x+b');
        } finally {
            umask($previousUmask);
        }
        if (!is_resource($handle) || !chmod($path, 0600) || !fclose($handle)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($path);
            throw new \RuntimeException('reviewed runtime temporary file could not be created privately');
        }
        self::assertPrivateFile($path, 'reviewed runtime temporary file');
        return $path;
    }

    private function removeEphemeralFile(string $path): void {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if ($before === false) {
            return;
        }
        self::assertPrivateFile($path, 'reviewed runtime stale ephemeral file');
        if ((int) ($before['size'] ?? -1) < 0 || (int) $before['size'] > self::ARCHIVE_LIMIT) {
            throw new \RuntimeException('reviewed runtime stale ephemeral file has an invalid size');
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('reviewed runtime stale ephemeral file could not be opened');
        }
        try {
            self::assertOpenedPrivateFile($handle, $path, 'reviewed runtime stale ephemeral file');
            $opened = fstat($handle);
            clearstatcache(true, $path);
            $after = @lstat($path);
            if (!is_array($opened) || !is_array($after)
                || !self::samePrivateFile($before, $opened)
                || !self::samePrivateFile($before, $after)
                || !@unlink($path)) {
                throw new \RuntimeException('reviewed runtime stale ephemeral file changed during recovery');
            }
            $unlinked = fstat($handle);
            clearstatcache(true, $path);
            if (!is_array($unlinked) || @lstat($path) !== false
                || (int) $unlinked['dev'] !== (int) $opened['dev']
                || (int) $unlinked['ino'] !== (int) $opened['ino']
                || (int) ($unlinked['nlink'] ?? -1) !== 0) {
                throw new \RuntimeException(
                    'reviewed runtime stale ephemeral file changed while being removed'
                );
            }
        } finally {
            fclose($handle);
        }
        $this->syncDirectory(dirname($path), 'reviewed runtime temporary directory');
    }

    private function writeFile(string $path, string $bytes, int $mode): void {
        if ($mode !== 0600 || $bytes === '' || strlen($bytes) > self::FILE_LIMIT) {
            throw new \RuntimeException('reviewed runtime file is outside its private publication contract');
        }
        $this->reconcileFileTemporary($path, $mode);
        $temporary = $path . '.tmp';
        $previousUmask = umask(0077);
        try {
            $handle = fopen($temporary, 'x+b');
        } finally {
            umask($previousUmask);
        }
        if (!is_resource($handle)) {
            throw new \RuntimeException('reviewed runtime file could not be created');
        }
        try {
            if (!chmod($temporary, $mode)) {
                throw new \RuntimeException('reviewed runtime temporary file could not be protected');
            }
            self::assertOpenedPrivateFile($handle, $temporary, 'reviewed runtime temporary file');
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)
                || function_exists('fsync') && !fsync($handle) || !fclose($handle)) {
                throw new \RuntimeException('reviewed runtime file could not be published');
            }
            $handle = null;
            if (!rename($temporary, $path)) {
                throw new \RuntimeException('reviewed runtime file could not be renamed');
            }
            self::assertPrivateFile($path, 'reviewed runtime published file');
            $this->syncDirectory(dirname($path), 'reviewed runtime state directory');
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($temporary);
        }
    }

    private function reconcileFileTemporary(string $path, int $mode): void {
        $temporary = $path . '.tmp';
        clearstatcache(true, $temporary);
        $before = @lstat($temporary);
        if ($before === false) {
            return;
        }
        if ($mode !== 0600) {
            throw new \RuntimeException('reviewed runtime temporary mode is unsupported');
        }
        self::assertPrivateFile($temporary, 'reviewed runtime stale temporary file');
        if ((int) ($before['size'] ?? -1) < 0 || (int) $before['size'] > self::FILE_LIMIT) {
            throw new \RuntimeException('reviewed runtime stale temporary file has an invalid size');
        }
        $handle = @fopen($temporary, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('reviewed runtime stale temporary file could not be opened');
        }
        try {
            self::assertOpenedPrivateFile($handle, $temporary, 'reviewed runtime stale temporary file');
            $opened = fstat($handle);
            clearstatcache(true, $temporary);
            $after = @lstat($temporary);
            if (!is_array($opened) || !is_array($after)
                || !self::samePrivateFile($before, $opened)
                || !self::samePrivateFile($before, $after)
                || !@unlink($temporary)) {
                throw new \RuntimeException('reviewed runtime stale temporary file changed during recovery');
            }
            $unlinked = fstat($handle);
            clearstatcache(true, $temporary);
            if (!is_array($unlinked) || @lstat($temporary) !== false
                || (int) $unlinked['dev'] !== (int) $opened['dev']
                || (int) $unlinked['ino'] !== (int) $opened['ino']
                || (int) ($unlinked['nlink'] ?? -1) !== 0) {
                throw new \RuntimeException(
                    'reviewed runtime stale temporary file changed while being removed'
                );
            }
        } finally {
            fclose($handle);
        }
        $this->syncDirectory(dirname($path), 'reviewed runtime state directory');
    }

    private static function assertPrivateFile(string $path, string $label): void {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path)
            || ((int) $stat['mode'] & 0170000) !== 0100000
            || (int) ($stat['nlink'] ?? 0) !== 1
            || (DIRECTORY_SEPARATOR === '/' && ((int) $stat['mode'] & 0777) !== 0600)
            || (function_exists('posix_geteuid') && (int) $stat['uid'] !== posix_geteuid())) {
            throw new \RuntimeException("$label must be a process-owned single-link mode-0600 file");
        }
    }

    /** @param resource $handle */
    private static function assertOpenedPrivateFile($handle, string $path, string $label): void {
        clearstatcache(true, $path);
        $named = @lstat($path);
        $opened = fstat($handle);
        if (!is_array($named) || !is_array($opened)
            || !self::samePrivateFile($named, $opened)) {
            throw new \RuntimeException("$label changed while opening");
        }
        self::assertPrivateFile($path, $label);
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private static function samePrivateFile(array $left, array $right): bool {
        return (int) $left['dev'] === (int) $right['dev']
            && (int) $left['ino'] === (int) $right['ino']
            && (int) $left['mode'] === (int) $right['mode']
            && (int) $left['uid'] === (int) $right['uid']
            && (int) ($left['nlink'] ?? 0) === (int) ($right['nlink'] ?? 0)
            && (int) $left['size'] === (int) $right['size'];
    }

    private function copyTree(string $source, string $destination): void {
        if (!is_dir($source) || is_link($source)) {
            throw new \RuntimeException('reviewed runtime source tree is unavailable');
        }
        if (!is_dir($destination) && !mkdir($destination, 0700, true)) {
            throw new \RuntimeException('reviewed runtime destination tree could not be created');
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new \RuntimeException('reviewed runtime source tree contains a link');
            }
            $relative = substr($entry->getPathname(), strlen(rtrim($source, '/')) + 1);
            $target = $destination . '/' . $relative;
            if ($entry->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0700, true)) {
                    throw new \RuntimeException('reviewed runtime directory copy failed');
                }
            } elseif (!copy($entry->getPathname(), $target) || !chmod($target, 0600)) {
                throw new \RuntimeException('reviewed runtime file copy failed');
            }
        }
    }

    private function removeTree(string $path): void {
        if (!is_dir($path) || is_link($path)) {
            throw new \RuntimeException('reviewed runtime removal target is not a directory');
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink() || $entry->isFile()) {
                if (!unlink($entry->getPathname())) {
                    throw new \RuntimeException('reviewed runtime file removal failed');
                }
            } elseif (!rmdir($entry->getPathname())) {
                throw new \RuntimeException('reviewed runtime directory removal failed');
            }
        }
        if (!rmdir($path)) {
            throw new \RuntimeException('reviewed runtime root removal failed');
        }
    }

    private function ensurePrivateDirectory(string $path): void {
        if (!is_dir($path) && !mkdir($path, 0700, true)) {
            throw new \RuntimeException('reviewed runtime private directory could not be created');
        }
        if (is_link($path) || !chmod($path, 0700) || !is_writable($path)) {
            throw new \RuntimeException('reviewed runtime private directory is unsafe');
        }
    }

    private static function safeArchivePath(string $path): bool {
        return $path !== '' && $path[0] !== '/' && !str_contains($path, "\0")
            && preg_match('#(?:^|/)\.\.?(/|$)#D', $path) !== 1;
    }

    private static function identifier(string $value, string $label): string {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value) !== 1) {
            throw new \RuntimeException("$label is invalid");
        }
        return $value;
    }

    private static function sha256(string $value, string $label): string {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new \RuntimeException("$label is not lowercase SHA-256");
        }
        return $value;
    }

    private static function positiveInt(string $value, string $label): int {
        if (preg_match('/\A[1-9][0-9]{0,18}\z/D', $value) !== 1 || (int) $value < 1) {
            throw new \RuntimeException("$label is not a positive integer");
        }
        return (int) $value;
    }

    private static function writeJson(array $value): void {
        echo self::canonical($value) . "\n";
    }

    private static function canonical(mixed $value): string {
        $normalized = self::sort($value);
        return json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function sort(mixed $value): mixed {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::sort(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $child) {
            $value[$key] = self::sort($child);
        }
        return $value;
    }
}

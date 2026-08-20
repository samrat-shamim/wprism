<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once dirname(__DIR__) . '/src/CanonicalJson.php';
require_once dirname(__DIR__) . '/src/ContainerWorkloadRuntime.php';
require_once dirname(__DIR__) . '/src/ControlRefusal.php';
require_once dirname(__DIR__) . '/src/HostAuthorityBusy.php';

/** Host-global nftables authority for every Duo-owned Docker bridge. */
final class HostFirewallAuthority {
    public const FORMAT = 'duo-cloud-host-firewall-state/v1';
    private const STORE_FORMAT = 'duo-cloud-host-firewall-store/v1';
    private const TABLE = 'duo_cloud_preview';
    private const STATE_LIMIT = 1048576;
    // One host admits at most 32 active site workers/bindings. This keeps the
    // complete owned-table JSON and durable journal well below the runner's
    // 1 MiB cap and matches the storage authority's measured transaction bound.
    private const BINDING_LIMIT = 32;

    /** @var array<string,int> */
    private array $principals = [];
    /** @var list<array{configuration_sha256s:list<string>,service_uid:int,worker_id:string}> */
    private array $principalRegistry;
    private string $directory;
    private string $statePath;
    private string $stateTemporaryPath;
    private string $batchPath;
    private string $lockPath;

    /** @param list<array{configuration_sha256s:list<string>,service_uid:int,worker_id:string}> $principals */
    public function __construct(
        private ContainerArgvProcessRunner $runner,
        private string $engineBinary,
        private string $nftBinary,
        private string $ipBinary,
        string $stateRoot,
        array $principals,
        private int $callerUid
    ) {
        self::absoluteExecutable($engineBinary, 'container engine');
        self::absoluteExecutable($nftBinary, 'nftables');
        self::absoluteExecutable($ipBinary, 'IP route');
        if ($principals === [] || count($principals) > self::BINDING_LIMIT || $callerUid < 1) {
            throw new ControlRefusal('firewall authority principal registry is empty or unbounded');
        }
        $previousWorker = null;
        foreach ($principals as $principal) {
            if (!is_array($principal) || array_is_list($principal)) {
                throw new ControlRefusal('firewall authority principal is malformed');
            }
            self::exactKeys($principal, ['configuration_sha256s', 'service_uid', 'worker_id']);
            $configurationSha256s = $principal['configuration_sha256s'] ?? null;
            $serviceUid = $principal['service_uid'] ?? null;
            $workerId = $principal['worker_id'] ?? null;
            if (!is_string($workerId)
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $workerId) !== 1
                || $previousWorker !== null && strcmp($previousWorker, $workerId) >= 0) {
                throw new ControlRefusal('firewall authority principals are not canonical and unique');
            }
            if (!is_int($serviceUid) || $serviceUid < 1) {
                throw new ControlRefusal('firewall authority service UID is invalid');
            }
            if (!is_array($configurationSha256s) || !array_is_list($configurationSha256s)
                || $configurationSha256s === [] || count($configurationSha256s) > 16) {
                throw new ControlRefusal('firewall authority configuration rotation set is invalid');
            }
            $previousConfiguration = null;
            foreach ($configurationSha256s as $configurationSha256) {
                self::sha256($configurationSha256, 'firewall authority configuration digest');
                if (($previousConfiguration !== null
                        && strcmp($previousConfiguration, $configurationSha256) >= 0)
                    || isset($this->principals[$configurationSha256])) {
                    throw new ControlRefusal('firewall authority configuration digests are not unique');
                }
                $this->principals[$configurationSha256] = $serviceUid;
                $previousConfiguration = $configurationSha256;
            }
            $previousWorker = $workerId;
        }
        if (!in_array($callerUid, $this->principals, true)) {
            throw new ControlRefusal('firewall authority caller UID is not registered');
        }
        $this->principalRegistry = $principals;
        $real = realpath($stateRoot);
        $stat = @lstat($stateRoot);
        if (!is_string($real) || $real === '/' || !is_array($stat) || is_link($stateRoot)
            || ($stat['mode'] & 0170000) !== 0040000
            || (DIRECTORY_SEPARATOR === '/' && ($stat['mode'] & 0077) !== 0)
            || (function_exists('posix_geteuid') && (int) $stat['uid'] !== posix_geteuid())) {
            throw new ControlRefusal('firewall authority state root is not private and process-owned');
        }
        $this->directory = $real;
        $this->statePath = $real . '/authority.json';
        $this->stateTemporaryPath = $this->statePath . '.tmp';
        $this->batchPath = $real . '/authority.nft';
        $this->lockPath = $real . '/authority.lock';
    }

    /** @param array<string,mixed> $binding @return array<string,mixed> */
    public function bind(array $binding): array {
        $binding = $this->binding($binding, true);
        $this->assertBridge($binding);
        return $this->locked(function (array $state) use ($binding): array {
            $key = self::bindingKey($binding['configuration_sha256'], $binding['network_name']);
            $bindings = self::bindingMap($state['bindings']);
            if (isset($bindings[$key])
                && CanonicalJson::encode($bindings[$key]) !== CanonicalJson::encode($binding)) {
                throw new ControlRefusal('firewall binding name is already owned by different network authority');
            }
            $bindings[$key] = $binding;
            $this->publishBindings($state, array_values($bindings));
            return self::boundOutput($binding);
        });
    }

    /** @return array<string,mixed> */
    public function inspect(string $configurationSha256, string $networkName): array {
        $this->authorizePrincipal($configurationSha256);
        self::networkName($networkName);
        return $this->locked(function (array $state) use ($configurationSha256, $networkName): array {
            $bindings = self::bindingMap($state['bindings']);
            $key = self::bindingKey($configurationSha256, $networkName);
            if (!isset($bindings[$key])) {
                return self::absentOutput($configurationSha256, $networkName);
            }
            $this->assertBridge($bindings[$key]);
            return self::boundOutput($bindings[$key]);
        });
    }

    /** @return array<string,mixed> */
    public function unbind(string $configurationSha256, string $networkName): array {
        $this->authorizePrincipal($configurationSha256);
        self::networkName($networkName);
        return $this->locked(function (array $state) use ($configurationSha256, $networkName): array {
            $bindings = self::bindingMap($state['bindings']);
            unset($bindings[self::bindingKey($configurationSha256, $networkName)]);
            $this->publishBindings($state, array_values($bindings));
            return self::absentOutput($configurationSha256, $networkName);
        });
    }

    /** @return array<string,mixed> */
    public function preflight(): array {
        return $this->locked(fn (array $state): array => [
            'bindings' => count($state['bindings']),
            'format' => self::FORMAT,
            'principals' => $this->principalRegistry,
            'state' => 'ready',
        ]);
    }

    /** @return array<string,mixed> */
    public function workerPreflight(string $configurationSha256): array {
        $this->authorizePrincipal($configurationSha256);
        $state = $this->stableReadState();
        $bindings = [];
        foreach ($state['bindings'] as $binding) {
            if (($binding['configuration_sha256'] ?? null) === $configurationSha256) {
                $bindings[] = $binding;
            }
        }
        foreach ($bindings as $binding) {
            $this->assertBridge($binding);
        }
        return [
            'bindings' => count($bindings),
            'bindings_sha256' => hash(
                'sha256',
                "duo-cloud-host-firewall-worker-preflight-bindings/v1\0"
                    . CanonicalJson::encode($bindings)
            ),
            'configuration_sha256' => $configurationSha256,
            'format' => 'duo-cloud-host-firewall-worker-preflight/v1',
            'state' => 'ready',
        ];
    }

    /** @return array<string,mixed> */
    public function reconcile(): array {
        return $this->locked(fn (array $state): array => [
            'bindings' => count($state['bindings']),
            'format' => self::FORMAT,
            'principals' => $this->principalRegistry,
            'state' => 'reconciled',
        ], true);
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $callback @return array<string,mixed> */
    private function locked(callable $callback, bool $reconcileStable = false): array {
        $handle = @fopen($this->lockPath, 'c+b');
        if (!is_resource($handle) || !chmod($this->lockPath, 0600)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new ControlRefusal('firewall authority lock could not be acquired privately');
        }
        $wouldBlock = 0;
        if (!flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            fclose($handle);
            if ($wouldBlock === 1) {
                throw new HostAuthorityBusy('firewall authority lock is busy');
            }
            throw new ControlRefusal('firewall authority lock could not be acquired privately');
        }
        try {
            // One global lock owns both fixed crash-residue names. Cleaning
            // them before the journal read prevents a killed predecessor from
            // consuming an unbounded filename per retry or supplying nft input.
            $this->reconcileTemporary(
                $this->stateTemporaryPath,
                'firewall authority state temporary file'
            );
            $this->reconcileTemporary($this->batchPath, 'host firewall batch');
            $state = $this->readState();
            if ($state['phase'] === 'applying') {
                $current = $this->applyRules($state['bindings'], $this->rawTablePresent());
                $state = self::stableState($state['bindings'], $current);
                $this->writeState($state);
            } elseif ($reconcileStable) {
                $current = $this->applyRules($state['bindings'], $this->rawTablePresent());
                $state = self::stableState($state['bindings'], $current);
                $this->writeState($state);
            } else {
                $current = $this->nftDigest($state['bindings']);
                if ($current !== $state['nft_sha256']) {
                    throw new ControlRefusal('host firewall rules differ from their exact authority readback');
                }
            }
            return $callback($state);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function rawTablePresent(): bool {
        $result = $this->mustRun(
            [$this->nftBinary, '--json', 'list', 'tables'],
            'host firewall structural ruleset readback'
        );
        try {
            $ruleset = json_decode($result['stdout'], true, 128, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new ControlRefusal('host firewall structural ruleset readback is not JSON', 0, $error);
        }
        $objects = is_array($ruleset) && !array_is_list($ruleset) ? ($ruleset['nftables'] ?? null) : null;
        if (!is_array($objects) || !array_is_list($objects)) {
            throw new ControlRefusal('host firewall structural ruleset readback has invalid framing');
        }
        $tables = 0;
        foreach ($objects as $object) {
            if (!is_array($object) || array_is_list($object) || count($object) !== 1) {
                throw new ControlRefusal('host firewall structural ruleset contains malformed objects');
            }
            $table = $object['table'] ?? null;
            if (is_array($table) && !array_is_list($table)
                && ($table['family'] ?? null) === 'inet' && ($table['name'] ?? null) === self::TABLE) {
                $tables++;
            }
        }
        if ($tables > 1) {
            throw new ControlRefusal('host firewall structural ruleset has duplicate owned tables');
        }
        return $tables === 1;
    }

    /** @param array<string,mixed> $state @param list<array<string,mixed>> $bindings */
    private function publishBindings(array $state, array $bindings): void {
        usort($bindings, static fn (array $left, array $right): int => strcmp(
            self::bindingKey($left['configuration_sha256'], $left['network_name']),
            self::bindingKey($right['configuration_sha256'], $right['network_name'])
        ));
        $this->assertBindingSet($bindings);
        if (CanonicalJson::encode($bindings) === CanonicalJson::encode($state['bindings'])) {
            return;
        }
        $this->writeState([
            'bindings' => $bindings,
            'format' => self::STORE_FORMAT,
            'nft_sha256' => $state['nft_sha256'],
            'phase' => 'applying',
        ]);
        $digest = $this->applyRules($bindings, $state['nft_sha256'] !== null);
        $this->writeState(self::stableState($bindings, $digest));
    }

    /** @param list<array<string,mixed>> $bindings */
    private function applyRules(array $bindings, bool $tablePresent): ?string {
        $batch = '';
        if ($tablePresent) {
            $batch .= 'delete table inet ' . self::TABLE . "\n";
        }
        if ($bindings !== []) {
            $batch .= 'add table inet ' . self::TABLE . "\n"
                . 'add chain inet ' . self::TABLE
                    . ' input { type filter hook input priority -5; policy accept; }' . "\n"
                . 'add chain inet ' . self::TABLE
                    . ' forward { type filter hook forward priority -5; policy accept; }' . "\n";
            foreach ($bindings as $binding) {
                $prefix = 'duo:' . $binding['network_id'] . ':';
                // Docker's IPv4 setting is not an authority boundary: a workload can
                // originate IPv6/link-local or spoof a source. The bridge interface
                // is the kernel-owned identity, so every terminal deny is iif-only.
                $match = ' iifname "' . $binding['bridge_name'] . '"';
                $batch .= 'add rule inet ' . self::TABLE . ' input' . $match
                    . ' ct state established accept comment "' . $prefix
                    . 'input-established"' . "\n";
                $batch .= 'add rule inet ' . self::TABLE . ' input' . $match
                    . ' drop comment "' . $prefix . 'input-drop"' . "\n";
                $batch .= 'add rule inet ' . self::TABLE . ' forward' . $match
                    . ' drop comment "' . $prefix . 'forward-drop"' . "\n";
            }
        }
        $path = $this->batchPath;
        $this->reconcileTemporary($path, 'host firewall batch');
        $handle = $this->createPrivateTemporary($path, $batch, 'host firewall batch');
        try {
            self::testCheckpoint('nft-batch-synchronized');
            $this->assertOpenedPrivateFile($handle, $path, 'host firewall batch', strlen($batch));
            $this->mustRun([$this->nftBinary, '--check', '--file', $path], 'host firewall batch check');
            $this->assertOpenedPrivateFile($handle, $path, 'host firewall batch', strlen($batch));
            $this->mustRun([$this->nftBinary, '--file', $path], 'host firewall atomic publish');
            $this->assertOpenedPrivateFile($handle, $path, 'host firewall batch', strlen($batch));
        } finally {
            if (is_resource($handle)) {
                $this->removeOpenedTemporary($handle, $path, 'host firewall batch');
                fclose($handle);
            }
        }
        $digest = $this->nftDigest($bindings);
        if (($bindings === []) !== ($digest === null)) {
            throw new ControlRefusal('host firewall publish did not reach its exact terminal state');
        }
        return $digest;
    }

    /** @param list<array<string,mixed>> $bindings */
    private function nftDigest(array $bindings, bool $validatePolicy = true): ?string {
        if (!$this->rawTablePresent()) {
            if ($bindings !== []) {
                throw new ControlRefusal('host firewall table is absent for active bindings');
            }
            return null;
        }
        $result = $this->mustRun(
            [$this->nftBinary, '--json', 'list', 'table', 'inet', self::TABLE],
            'host firewall ruleset readback'
        );
        try {
            $ruleset = json_decode($result['stdout'], true, 128, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new ControlRefusal('host firewall ruleset readback is not JSON', 0, $error);
        }
        if (!is_array($ruleset) || array_is_list($ruleset)
            || !is_array($ruleset['nftables'] ?? null) || !array_is_list($ruleset['nftables'])) {
            throw new ControlRefusal('host firewall ruleset readback has invalid framing');
        }
        $owned = [];
        foreach ($ruleset['nftables'] as $object) {
            if (!is_array($object) || array_is_list($object) || count($object) !== 1) {
                throw new ControlRefusal('host firewall ruleset contains malformed objects');
            }
            $kind = array_key_first($object);
            $value = is_string($kind) ? $object[$kind] : null;
            if (!is_array($value) || array_is_list($value)) {
                continue;
            }
            if (($value['family'] ?? null) === 'inet'
                && (($kind === 'table' && ($value['name'] ?? null) === self::TABLE)
                    || ($kind !== 'table' && ($value['table'] ?? null) === self::TABLE))) {
                $owned[] = $object;
            }
        }
        if ($owned === []) {
            if ($validatePolicy && $bindings !== []) {
                throw new ControlRefusal('host firewall table is absent for active bindings');
            }
            return null;
        }
        $expectedComments = [];
        $expectedRules = [];
        $expectedOrder = ['forward' => [], 'input' => []];
        foreach ($bindings as $binding) {
            $prefix = 'duo:' . $binding['network_id'] . ':';
            foreach ([
                ['input-established', 'input', true],
                ['input-drop', 'input', false],
                ['forward-drop', 'forward', false],
            ] as [$suffix, $chain, $established]) {
                $comment = $prefix . $suffix;
                $expectedComments[] = $comment;
                $expectedOrder[$chain][] = $comment;
                $expression = [[
                    'match' => [
                        'left' => ['meta' => ['key' => 'iifname']],
                        'op' => '==',
                        'right' => $binding['bridge_name'],
                    ],
                ]];
                if ($established) {
                    $expression[] = [
                        'match' => [
                            'left' => ['ct' => ['key' => 'state']],
                            'op' => 'in',
                            'right' => 'established',
                        ],
                    ];
                    $expression[] = ['accept' => null];
                } else {
                    $expression[] = ['drop' => null];
                }
                $expectedRules[$comment] = ['chain' => $chain, 'expr' => $expression];
            }
        }
        sort($expectedComments, SORT_STRING);
        $comments = [];
        $actualOrder = ['forward' => [], 'input' => []];
        $tables = 0;
        $chains = [];
        foreach ($owned as $object) {
            $kind = array_key_first($object);
            $value = $object[$kind];
            if ($kind === 'table') {
                $tables++;
            } elseif ($kind === 'chain') {
                $name = $value['name'] ?? null;
                $chains[] = $name;
                if (($value['type'] ?? null) !== 'filter' || ($value['policy'] ?? null) !== 'accept'
                    || !in_array($name, ['input', 'forward'], true)
                    || ($value['hook'] ?? null) !== $name || ($value['prio'] ?? null) !== -5) {
                    throw new ControlRefusal('host firewall chain readback differs from fixed policy');
                }
            } elseif ($kind === 'rule' && is_string($value['comment'] ?? null)) {
                $comment = $value['comment'];
                $expected = $expectedRules[$comment] ?? null;
                if (!is_array($expected) || ($value['chain'] ?? null) !== $expected['chain']
                    || CanonicalJson::encode($value['expr'] ?? null)
                        !== CanonicalJson::encode($expected['expr'])) {
                    throw new ControlRefusal('host firewall rule semantic readback differs from fixed policy');
                }
                $comments[] = $comment;
                $actualOrder[$expected['chain']][] = $comment;
            } else {
                throw new ControlRefusal('host firewall table contains an unknown object');
            }
        }
        sort($chains, SORT_STRING);
        sort($comments, SORT_STRING);
        if ($validatePolicy && ($tables !== 1 || $chains !== ['forward', 'input']
            || $comments !== $expectedComments
            || $actualOrder !== $expectedOrder
            || count($owned) !== 3 + count($expectedComments))) {
            throw new ControlRefusal('host firewall rule readback differs from fixed binding policy');
        }
        return hash('sha256', "duo-cloud-host-firewall-nft-readback/v1\0" . CanonicalJson::encode($owned));
    }

    /** @param array<string,mixed> $binding */
    private function assertBridge(array $binding): void {
        $network = $this->mustRun([
            $this->engineBinary, 'network', 'inspect', '--format', '{{json .}}',
            $binding['network_name'],
        ], 'host firewall Docker network readback');
        $link = $this->mustRun([
            $this->ipBinary, '-details', '-json', 'link', 'show', 'dev', $binding['bridge_name'],
        ], 'host firewall bridge link readback');
        $address = $this->mustRun([
            $this->ipBinary, '-json', '-4', 'address', 'show', 'dev', $binding['bridge_name'],
        ], 'host firewall bridge address readback');
        try {
            $dockerNetwork = json_decode($network['stdout'], true, 32, JSON_THROW_ON_ERROR);
            $links = json_decode($link['stdout'], true, 32, JSON_THROW_ON_ERROR);
            $addresses = json_decode($address['stdout'], true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new ControlRefusal('host firewall bridge readback is not JSON', 0, $error);
        }
        $ipam = is_array($dockerNetwork) ? ($dockerNetwork['IPAM']['Config'] ?? null) : null;
        if (!is_array($dockerNetwork) || array_is_list($dockerNetwork)
            || ($dockerNetwork['Id'] ?? null) !== $binding['network_id']
            || ($dockerNetwork['Name'] ?? null) !== $binding['network_name']
            || ($dockerNetwork['Driver'] ?? null) !== 'bridge'
            || ($dockerNetwork['Internal'] ?? null) !== true
            || ($dockerNetwork['EnableIPv6'] ?? null) !== false
            || ($dockerNetwork['Options']['com.docker.network.bridge.name'] ?? null)
                !== $binding['bridge_name']
            || ($dockerNetwork['Options']['com.docker.network.bridge.enable_ip_masquerade'] ?? null)
                !== 'false'
            || ($dockerNetwork['Options']['com.docker.network.bridge.enable_icc'] ?? null) !== 'false'
            || !is_array($ipam) || !array_is_list($ipam) || count($ipam) !== 1
            || ($ipam[0]['Subnet'] ?? null) !== $binding['subnet']
            || ($ipam[0]['Gateway'] ?? null) !== $binding['gateway']
            || !is_array($links) || !array_is_list($links) || count($links) !== 1
            || ($links[0]['ifname'] ?? null) !== $binding['bridge_name']
            || ($links[0]['linkinfo']['info_kind'] ?? null) !== 'bridge'
            || !is_array($addresses) || !array_is_list($addresses) || count($addresses) !== 1) {
            throw new ControlRefusal('host firewall cannot prove the exact Docker bridge interface');
        }
        $gateway = explode('/', $binding['subnet'], 2);
        $matched = 0;
        foreach (($addresses[0]['addr_info'] ?? []) as $info) {
            if (is_array($info) && ($info['family'] ?? null) === 'inet'
                && ($info['local'] ?? null) === $binding['gateway']
                && ($info['prefixlen'] ?? null) === (int) $gateway[1]) {
                $matched++;
            }
        }
        if ($matched !== 1) {
            throw new ControlRefusal('host firewall bridge address differs from the Docker subnet gateway');
        }
    }

    /** @param array<string,mixed> $binding @return array<string,mixed> */
    private function binding(array $binding, bool $authorizeCaller): array {
        self::exactKeys($binding, [
            'bridge_name', 'configuration_sha256', 'gateway', 'network_id', 'network_name', 'subnet',
        ]);
        if ($authorizeCaller) {
            $this->authorizePrincipal($binding['configuration_sha256'] ?? null);
        } else {
            $this->registeredPrincipal($binding['configuration_sha256'] ?? null);
        }
        self::networkName($binding['network_name'] ?? null);
        if (!is_string($binding['bridge_name'] ?? null)
            || $binding['bridge_name'] !== self::bridgeName($binding['network_name'])) {
            throw new ControlRefusal('firewall binding bridge name is not deterministic');
        }
        if (!is_string($binding['network_id'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $binding['network_id']) !== 1) {
            throw new ControlRefusal('firewall binding network id is invalid');
        }
        self::ipv4Subnet($binding['subnet'] ?? null, $binding['gateway'] ?? null);
        return $binding;
    }

    private function authorizePrincipal(mixed $configurationSha256): string {
        self::sha256($configurationSha256, 'firewall binding configuration digest');
        if (($this->principals[$configurationSha256] ?? null) !== $this->callerUid) {
            throw new ControlRefusal('firewall binding configuration is not registered to the caller UID');
        }
        return $configurationSha256;
    }

    private function registeredPrincipal(mixed $configurationSha256): string {
        self::sha256($configurationSha256, 'firewall binding configuration digest');
        if (!isset($this->principals[$configurationSha256])) {
            throw new ControlRefusal('firewall binding configuration is not registered');
        }
        return $configurationSha256;
    }

    /** @return array<string,mixed> */
    private function stableReadState(): array {
        $last = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return $this->readState();
            } catch (ControlRefusal $error) {
                $last = $error;
            }
        }
        throw new ControlRefusal(
            'firewall authority state could not be read as one stable publication',
            0,
            $last
        );
    }

    /** @return array<string,mixed> */
    private function readState(): array {
        clearstatcache(true, $this->statePath);
        if (!file_exists($this->statePath) && !is_link($this->statePath)) {
            return self::stableState([], null);
        }
        $before = @lstat($this->statePath);
        if (!is_array($before) || is_link($this->statePath)
            || ($before['mode'] & 0170000) !== 0100000
            || (int) ($before['nlink'] ?? 0) !== 1
            || (DIRECTORY_SEPARATOR === '/' && ($before['mode'] & 0777) !== 0600)
            || (function_exists('posix_geteuid') && (int) $before['uid'] !== posix_geteuid())) {
            throw new ControlRefusal('firewall authority state is unavailable');
        }
        $handle = @fopen($this->statePath, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal('firewall authority state is unavailable');
        }
        try {
            $opened = fstat($handle);
            if (!is_array($opened) || !self::sameFile($before, $opened)
                || (int) $opened['size'] < 2 || (int) $opened['size'] > self::STATE_LIMIT) {
                throw new ControlRefusal('firewall authority state changed while opening');
            }
            $bytes = stream_get_contents($handle, self::STATE_LIMIT + 1);
        } finally {
            $closed = fclose($handle);
        }
        clearstatcache(true, $this->statePath);
        $after = @lstat($this->statePath);
        if (!is_string($bytes) || !$closed || strlen($bytes) > self::STATE_LIMIT
            || !is_array($after) || !self::sameFile($before, $after)) {
            throw new ControlRefusal('firewall authority state changed while reading');
        }
        $state = CanonicalJson::decodeObject($bytes, self::STATE_LIMIT);
        self::exactKeys($state, ['bindings', 'format', 'nft_sha256', 'phase']);
        if ($bytes !== CanonicalJson::encode($state) . "\n" || $state['format'] !== self::STORE_FORMAT
            || !is_array($state['bindings']) || !array_is_list($state['bindings'])
            || !in_array($state['phase'], ['applying', 'stable'], true)
            || $state['nft_sha256'] !== null
                && (!is_string($state['nft_sha256'])
                    || preg_match('/\A[a-f0-9]{64}\z/D', $state['nft_sha256']) !== 1)) {
            throw new ControlRefusal('firewall authority state is not canonical');
        }
        if (count($state['bindings']) > self::BINDING_LIMIT) {
            throw new ControlRefusal('firewall authority binding registry is over its closed limit');
        }
        $previous = null;
        $networkNames = [];
        $bridgeNames = [];
        $networkIds = [];
        foreach ($state['bindings'] as $binding) {
            if (!is_array($binding) || array_is_list($binding)) {
                throw new ControlRefusal('firewall authority binding state is malformed');
            }
            $binding = $this->binding($binding, false);
            $key = self::bindingKey($binding['configuration_sha256'], $binding['network_name']);
            if ($previous !== null && strcmp($previous, $key) >= 0) {
                throw new ControlRefusal('firewall authority bindings are not canonical and unique');
            }
            if (isset($networkNames[$binding['network_name']])
                || isset($bridgeNames[$binding['bridge_name']])
                || isset($networkIds[$binding['network_id']])) {
                throw new ControlRefusal('firewall authority network identities are not globally unique');
            }
            $networkNames[$binding['network_name']] = true;
            $bridgeNames[$binding['bridge_name']] = true;
            $networkIds[$binding['network_id']] = true;
            $previous = $key;
        }
        return $state;
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private static function sameFile(array $left, array $right): bool {
        return (int) $left['dev'] === (int) $right['dev']
            && (int) $left['ino'] === (int) $right['ino']
            && (int) $left['mode'] === (int) $right['mode']
            && (int) $left['uid'] === (int) $right['uid']
            && (int) ($left['nlink'] ?? 0) === (int) ($right['nlink'] ?? 0)
            && (int) $left['size'] === (int) $right['size'];
    }

    /** @param array<string,mixed> $state */
    private function writeState(array $state): void {
        $bytes = CanonicalJson::encode($state) . "\n";
        if (strlen($bytes) > self::STATE_LIMIT) {
            throw new ControlRefusal('firewall authority state exceeds its closed durable limit');
        }
        $temporary = $this->stateTemporaryPath;
        $this->reconcileTemporary($temporary, 'firewall authority state temporary file');
        $this->assertAbsentOrPrivateFile($this->statePath, 'firewall authority state');
        $handle = $this->createPrivateTemporary(
            $temporary,
            $bytes,
            'firewall authority state temporary file'
        );
        $published = false;
        try {
            self::testCheckpoint('state-temporary-synchronized');
            if (!@rename($temporary, $this->statePath)) {
                throw new ControlRefusal('firewall authority state could not be published atomically');
            }
            $published = true;
            $this->assertOpenedPrivateFile(
                $handle,
                $this->statePath,
                'firewall authority state',
                strlen($bytes)
            );
            $this->syncDirectory('firewall authority state directory');
            if (!fclose($handle)) {
                $handle = null;
                throw new ControlRefusal('firewall authority state could not be synchronized');
            }
            $handle = null;
            $readback = $this->readPrivateBytes(
                $this->statePath,
                'firewall authority state',
                2,
                self::STATE_LIMIT
            );
            if (!hash_equals(hash('sha256', $bytes), hash('sha256', $readback))) {
                throw new ControlRefusal('firewall authority durable state readback differs after publish');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (!$published && (file_exists($temporary) || is_link($temporary))) {
                $this->reconcileTemporary($temporary, 'firewall authority state temporary file');
            }
        }
    }

    /** @return resource */
    private function createPrivateTemporary(string $path, string $bytes, string $label) {
        $previousUmask = umask(0077);
        try {
            $handle = @fopen($path, 'x+b');
        } finally {
            umask($previousUmask);
        }
        if (!is_resource($handle)) {
            throw new ControlRefusal("$label could not be created privately");
        }
        try {
            $this->assertOpenedPrivateFile($handle, $path, $label, 0);
            self::writeAll($handle, $bytes, $label);
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new ControlRefusal("$label could not be synchronized privately");
            }
            $this->assertOpenedPrivateFile($handle, $path, $label, strlen($bytes));
        } catch (\Throwable $error) {
            try {
                $this->removeOpenedTemporary($handle, $path, $label);
            } finally {
                fclose($handle);
            }
            throw $error;
        }
        return $handle;
    }

    private function reconcileTemporary(string $path, string $label): void {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if ($before === false) {
            return;
        }
        self::assertPrivateStat($before, $path, $label);
        if ((int) ($before['size'] ?? -1) < 0 || (int) $before['size'] > self::STATE_LIMIT) {
            throw new ControlRefusal("$label has an invalid size");
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal("$label could not be opened for recovery");
        }
        try {
            $this->removeOpenedTemporary($handle, $path, $label);
        } finally {
            fclose($handle);
        }
        $this->syncDirectory("$label directory");
    }

    /** @param resource $handle */
    private function removeOpenedTemporary($handle, string $path, string $label): void {
        clearstatcache(true, $path);
        $before = @lstat($path);
        $opened = fstat($handle);
        if (!is_array($before) || !is_array($opened)
            || !self::sameFile($before, $opened)) {
            throw new ControlRefusal("$label changed during recovery");
        }
        self::assertPrivateStat($before, $path, $label);
        if (!@unlink($path)) {
            throw new ControlRefusal("$label could not be removed during recovery");
        }
        $unlinked = fstat($handle);
        clearstatcache(true, $path);
        if (!is_array($unlinked) || @lstat($path) !== false
            || (int) $unlinked['dev'] !== (int) $opened['dev']
            || (int) $unlinked['ino'] !== (int) $opened['ino']
            || (int) ($unlinked['nlink'] ?? -1) !== 0) {
            throw new ControlRefusal("$label changed while being removed");
        }
    }

    /** @param resource $handle */
    private function assertOpenedPrivateFile(
        $handle,
        string $path,
        string $label,
        int $expectedSize
    ): void {
        clearstatcache(true, $path);
        $named = @lstat($path);
        $opened = fstat($handle);
        if (!is_array($named) || !is_array($opened)
            || !self::sameFile($named, $opened)
            || (int) ($opened['size'] ?? -1) !== $expectedSize) {
            throw new ControlRefusal("$label changed while opening");
        }
        self::assertPrivateStat($opened, $path, $label);
    }

    /** @param array<string|int,mixed> $stat */
    private static function assertPrivateStat(array $stat, string $path, string $label): void {
        if (($stat['mode'] & 0170000) !== 0100000 || is_link($path)
            || (int) ($stat['nlink'] ?? 0) !== 1
            || (DIRECTORY_SEPARATOR === '/' && ($stat['mode'] & 0777) !== 0600)
            || (function_exists('posix_geteuid') && (int) $stat['uid'] !== posix_geteuid())) {
            throw new ControlRefusal(
                "$label must be a process-owned, single-link, mode-0600 regular file"
            );
        }
    }

    private function assertAbsentOrPrivateFile(string $path, string $label): void {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat !== false) {
            self::assertPrivateStat($stat, $path, $label);
        }
    }

    private function readPrivateBytes(
        string $path,
        string $label,
        int $minimum,
        int $maximum
    ): string {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!is_array($before)) {
            throw new ControlRefusal("$label is unavailable");
        }
        self::assertPrivateStat($before, $path, $label);
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal("$label is unavailable");
        }
        try {
            $opened = fstat($handle);
            if (!is_array($opened) || !self::sameFile($before, $opened)
                || (int) $opened['size'] < $minimum || (int) $opened['size'] > $maximum) {
                throw new ControlRefusal("$label changed while opening");
            }
            $bytes = stream_get_contents($handle, $maximum + 1);
        } finally {
            $closed = fclose($handle);
        }
        clearstatcache(true, $path);
        $after = @lstat($path);
        if (!is_string($bytes) || !$closed || !is_array($after)
            || !self::sameFile($before, $after)) {
            throw new ControlRefusal("$label changed while reading");
        }
        return $bytes;
    }

    /** @param resource $handle */
    private static function writeAll($handle, string $bytes, string $label): void {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($handle, substr($bytes, $offset));
            if (!is_int($written) || $written < 1) {
                throw new ControlRefusal("$label could not be written completely");
            }
            $offset += $written;
        }
    }

    private function syncDirectory(string $label): void {
        if (!function_exists('fsync')) {
            return;
        }
        $handle = @fopen($this->directory, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal("$label could not be opened for synchronization");
        }
        $synced = @fsync($handle);
        $closed = fclose($handle);
        if (!$synced || !$closed) {
            throw new ControlRefusal("$label could not be synchronized");
        }
    }

    private static function testCheckpoint(string $phase): void {
        if (!function_exists('posix_kill')
            || getenv('DUO_TEST_HOST_FIREWALL_KILL_PHASE') !== $phase) {
            return;
        }
        posix_kill(getmypid(), SIGKILL);
        usleep(1000000);
        exit(137);
    }

    /** @param list<array<string,mixed>> $bindings */
    private function assertBindingSet(array $bindings): void {
        if (count($bindings) > self::BINDING_LIMIT) {
            throw new ControlRefusal('firewall authority binding registry is full');
        }
        $candidate = self::stableState($bindings, str_repeat('0', 64));
        if (strlen(CanonicalJson::encode($candidate) . "\n") > self::STATE_LIMIT) {
            throw new ControlRefusal('firewall authority binding registry exceeds its durable limit');
        }
        $networkNames = [];
        $bridgeNames = [];
        $networkIds = [];
        foreach ($bindings as $binding) {
            if (isset($networkNames[$binding['network_name']])
                || isset($bridgeNames[$binding['bridge_name']])
                || isset($networkIds[$binding['network_id']])) {
                throw new ControlRefusal('firewall authority network identity is already bound');
            }
            $networkNames[$binding['network_name']] = true;
            $bridgeNames[$binding['bridge_name']] = true;
            $networkIds[$binding['network_id']] = true;
        }
    }

    /** @param list<array<string,mixed>> $bindings @return array<string,mixed> */
    private static function stableState(array $bindings, ?string $digest): array {
        return [
            'bindings' => $bindings,
            'format' => self::STORE_FORMAT,
            'nft_sha256' => $digest,
            'phase' => 'stable',
        ];
    }

    /** @param list<array<string,mixed>> $bindings @return array<string,array<string,mixed>> */
    private static function bindingMap(array $bindings): array {
        $map = [];
        foreach ($bindings as $binding) {
            $map[self::bindingKey($binding['configuration_sha256'], $binding['network_name'])] = $binding;
        }
        return $map;
    }

    /** @param array<string,mixed> $binding @return array<string,mixed> */
    private static function boundOutput(array $binding): array {
        return ['format' => self::FORMAT, 'state' => 'bound'] + $binding;
    }

    /** @return array<string,mixed> */
    private static function absentOutput(string $configurationSha256, string $networkName): array {
        return [
            'configuration_sha256' => $configurationSha256,
            'format' => self::FORMAT,
            'network_name' => $networkName,
            'state' => 'absent',
        ];
    }

    /** @return array{exit:int,stderr:string,stdout:string} */
    private function mustRun(array $argv, string $label): array {
        $result = $this->runner->run($argv);
        if ($result['exit'] !== 0 || $result['stderr'] !== '') {
            throw new ControlRefusal("$label failed");
        }
        return $result;
    }

    private static function bindingKey(string $configurationSha256, string $networkName): string {
        return $configurationSha256 . "\0" . $networkName;
    }

    public static function bridgeName(string $networkName): string {
        self::networkName($networkName);
        return 'duo' . substr(hash('sha256', $networkName), 0, 12);
    }

    private static function networkName(mixed $value): string {
        if (!is_string($value)
            || preg_match('/\Aduo-preview-net-[a-f0-9]{64}-g[0-9]{10}\z/D', $value) !== 1) {
            throw new ControlRefusal('firewall binding network name is invalid');
        }
        return $value;
    }

    private static function ipv4Subnet(mixed $subnet, mixed $gateway): void {
        if (!is_string($subnet) || !is_string($gateway)
            || preg_match('/\A([0-9]{1,3}(?:\.[0-9]{1,3}){3})\/([0-9]{1,2})\z/D', $subnet, $match) !== 1
            || filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ControlRefusal('firewall binding IPv4 subnet or gateway is invalid');
        }
        $prefix = (int) $match[2];
        $network = (int) sprintf('%u', ip2long($match[1]));
        $gatewayNumber = (int) sprintf('%u', ip2long($gateway));
        if ($prefix < 16 || $prefix > 30) {
            throw new ControlRefusal('firewall binding IPv4 prefix is outside the closed range');
        }
        $mask = (0xffffffff << (32 - $prefix)) & 0xffffffff;
        $broadcast = $network | (~$mask & 0xffffffff);
        if (($network & $mask) !== $network || $gatewayNumber <= $network || $gatewayNumber >= $broadcast) {
            throw new ControlRefusal('firewall binding IPv4 subnet is not canonical or does not contain gateway');
        }
    }

    private static function sha256(mixed $value, string $label): void {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new ControlRefusal("$label is not lowercase SHA-256");
        }
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new ControlRefusal('firewall authority object has missing or unknown fields');
        }
    }

    private static function absoluteExecutable(string $path, string $label): void {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")
            || preg_match('#(?:^|/)\.\.?(/|$)#D', $path) === 1 || !is_executable($path)) {
            throw new ControlRefusal("$label executable is invalid");
        }
    }
}

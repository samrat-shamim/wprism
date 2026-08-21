<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once dirname(__DIR__) . '/src/CanonicalJson.php';
require_once dirname(__DIR__) . '/src/ControlRefusal.php';

/** Pinned non-root client configuration for the narrow sudo authority bridge. */
final class FirewallClientConfig {
    public const FORMAT = 'duo-cloud-firewall-client-config/v1';
    private const LIMIT = 1048576;
    private const EXECUTABLE_LIMIT = 67108864;
    private const INSTALLED_ROOT = '/var/lib/duo-cloud/config';

    /**
     * @param array<string,mixed> $document
     * @param list<array{
     *     executable:bool,
     *     label:string,
     *     limit:int,
     *     path:string,
     *     pin:bool,
     *     sha256:string,
     *     snapshot:array<string,int>
     * }> $identities
     */
    private function __construct(
        private array $document,
        private string $configurationSha256,
        private array $identities,
        private int $ownerUid
    ) {}

    public static function loadInstalled(
        string $path,
        string $pinPath,
        int $ownerUid = 0
    ): self {
        [$document, $configurationSha256, $identities] = self::installedPair(
            $path,
            $pinPath,
            'client configuration',
            $ownerUid,
            'firewall client configuration is not canonical JSON'
        );
        self::validateClientDocument(
            $document,
            $identities,
            $ownerUid,
            dirname($path) === self::INSTALLED_ROOT
        );
        return new self($document, $configurationSha256, $identities, $ownerUid);
    }

    public static function load(string $path, string $expectedSha256): self {
        $configuration = self::pinnedIdentity(
            $path,
            $expectedSha256,
            'client configuration',
            self::LIMIT,
            false,
            0
        );
        $document = self::canonicalDocument(
            $configuration['bytes'],
            'firewall client configuration',
            'firewall client configuration is not canonical JSON'
        );
        $identities = [$configuration['identity']];
        self::validateClientDocument($document, $identities, 0, false);
        return new self($document, $expectedSha256, $identities, 0);
    }

    /**
     * Snapshot the separately loaded root authority configuration without
     * pretending to execute its root-only environmental validation. Every
     * descriptor the exact schema can execute is nevertheless pinned here;
     * the first authority call performs the complete domain validation.
     */
    public static function inspectInstalledAuthority(
        string $authority,
        string $path,
        string $pinPath,
        int $ownerUid = 0
    ): self {
        $schemas = [
            'firewall' => [
                'descriptors' => ['container_engine', 'ip', 'nft'],
                'fields' => [
                    'container_engine', 'format', 'ip', 'nft', 'principals',
                    'process_timeout_seconds', 'state_root',
                ],
                'format' => 'duo-cloud-host-firewall-config/v1',
            ],
            'storage' => [
                'descriptors' => [
                    'container_engine', 'cryptsetup', 'dmsetup', 'findmnt',
                    'xfs_io', 'xfs_quota',
                ],
                'fields' => [
                    'backing_device', 'cipher', 'configuration_sha256s',
                    'container_engine', 'cryptsetup', 'dmsetup', 'docker_root',
                    'durable_paths', 'filesystem_uuid', 'findmnt', 'format',
                    'key_location', 'key_size_bits', 'limits', 'luks_uuid',
                    'mapper_name', 'mapper_path', 'mapper_size_sectors', 'mapper_uuid',
                    'mountpoint', 'payload_offset_sectors', 'process_timeout_seconds',
                    'sector_size_bytes', 'service_uids', 'state_root', 'worker_roots',
                    'xfs_io', 'xfs_quota',
                ],
                'format' => 'duo-cloud-host-storage-config/v1',
            ],
        ];
        $schema = $schemas[$authority] ?? null;
        if (!is_array($schema)) {
            throw new ControlRefusal('host authority configuration kind is invalid');
        }
        [$document, $configurationSha256, $identities] = self::installedPair(
            $path,
            $pinPath,
            "$authority authority configuration",
            $ownerUid
        );
        self::exactKeys($document, $schema['fields']);
        if (($document['format'] ?? null) !== $schema['format']) {
            throw new ControlRefusal("$authority authority configuration is unsupported");
        }
        foreach ($schema['descriptors'] as $name) {
            $descriptor = $document[$name] ?? null;
            if (!is_array($descriptor) || array_is_list($descriptor)) {
                throw new ControlRefusal("$authority authority $name descriptor is invalid");
            }
            self::exactKeys($descriptor, ['path', 'sha256']);
            $identity = self::pinnedIdentity(
                $descriptor['path'] ?? null,
                $descriptor['sha256'] ?? null,
                "$authority authority $name",
                self::EXECUTABLE_LIMIT,
                true,
                $ownerUid
            );
            $identities[] = $identity['identity'];
        }
        return new self($document, $configurationSha256, $identities, $ownerUid);
    }

    public function configurationSha256(): string {
        return $this->configurationSha256;
    }

    public function assertCurrent(): void {
        foreach ($this->identities as $identity) {
            $current = self::pinnedIdentity(
                $identity['path'],
                $identity['sha256'],
                $identity['label'],
                $identity['limit'],
                $identity['executable'],
                $this->ownerUid,
                $identity['pin'] ? 65 : null
            );
            if (($identity['pin']
                    && preg_match('/\A[a-f0-9]{64}\n\z/D', $current['bytes']) !== 1)
                || $current['identity']['snapshot'] !== $identity['snapshot']) {
                throw new ControlRefusal(
                    'installed host authority configuration changed during verification'
                );
            }
        }
    }

    public function authority(): string {
        return $this->document['authority']['path'];
    }

    public function sudo(): string {
        return $this->document['sudo']['path'];
    }

    public function timeout(): int {
        return $this->document['process_timeout_seconds'];
    }

    /**
     * @param array<string,mixed> $document
     * @param list<array{
     *     executable:bool,
     *     label:string,
     *     limit:int,
     *     path:string,
     *     pin:bool,
     *     sha256:string,
     *     snapshot:array<string,int>
     * }> $identities
     */
    private static function validateClientDocument(
        array $document,
        array &$identities,
        int $ownerUid,
        bool $enforceInstalledClosure
    ): void {
        self::exactKeys($document, [
            'authority', 'closure', 'format', 'interpreter', 'process_timeout_seconds', 'sudo',
        ]);
        if (($document['format'] ?? null) !== self::FORMAT
            || !is_int($document['process_timeout_seconds'] ?? null)
            || $document['process_timeout_seconds'] < 45
            || $document['process_timeout_seconds'] > 60) {
            throw new ControlRefusal('firewall client configuration is unsupported');
        }
        $bytesByName = [];
        foreach (['authority', 'interpreter', 'sudo'] as $name) {
            $descriptor = $document[$name] ?? null;
            if (!is_array($descriptor) || array_is_list($descriptor)) {
                throw new ControlRefusal("firewall client $name descriptor is invalid");
            }
            self::exactKeys($descriptor, ['path', 'sha256']);
            $identity = self::pinnedIdentity(
                $descriptor['path'] ?? null,
                $descriptor['sha256'] ?? null,
                $name,
                self::EXECUTABLE_LIMIT,
                true,
                $ownerUid
            );
            $bytesByName[$name] = $identity['bytes'];
            $identities[] = $identity['identity'];
        }
        $closure = $document['closure'] ?? null;
        if (!is_array($closure) || !array_is_list($closure) || $closure === []
            || count($closure) > 32) {
            throw new ControlRefusal('firewall client authority closure is invalid');
        }
        $previous = null;
        $authorityCovered = 0;
        foreach ($closure as $descriptor) {
            if (!is_array($descriptor) || array_is_list($descriptor)) {
                throw new ControlRefusal('firewall client authority closure descriptor is invalid');
            }
            self::exactKeys($descriptor, ['path', 'sha256']);
            $closurePath = $descriptor['path'] ?? null;
            if (!is_string($closurePath)
                || ($previous !== null && strcmp($previous, $closurePath) >= 0)) {
                throw new ControlRefusal(
                    'firewall client authority closure is not canonical and unique'
                );
            }
            $identity = self::pinnedIdentity(
                $closurePath,
                $descriptor['sha256'] ?? null,
                'authority closure',
                self::EXECUTABLE_LIMIT,
                false,
                $ownerUid
            );
            $identities[] = $identity['identity'];
            if ($closurePath === ($document['authority']['path'] ?? null)) {
                $authorityCovered++;
            }
            $previous = $closurePath;
        }
        if ($enforceInstalledClosure) {
            $authorityPath = $document['authority']['path'] ?? null;
            $expectedClosure = self::installedAuthorityClosure($authorityPath);
            if (array_column($closure, 'path') !== $expectedClosure) {
                throw new ControlRefusal(
                    'firewall client installed authority closure is incomplete or expanded'
                );
            }
        }
        $shebang = '#!' . ($document['interpreter']['path'] ?? '') . "\n";
        if ($authorityCovered !== 1 || !str_starts_with($bytesByName['authority'], $shebang)) {
            throw new ControlRefusal(
                'firewall client authority is not bound to its absolute interpreter closure'
            );
        }
    }

    /** @return list<string> */
    private static function installedAuthorityClosure(mixed $authorityPath): array {
        $common = [
            '/opt/duo-cloud/runtime/RuntimeSlotLock.php',
            '/opt/duo-cloud/src/CanonicalJson.php',
            '/opt/duo-cloud/src/ContainerWorkloadRuntime.php',
            '/opt/duo-cloud/src/ControlRefusal.php',
            '/opt/duo-cloud/src/HostAuthorityBusy.php',
            '/opt/duo-cloud/src/ImmutableOciReference.php',
            '/opt/duo-cloud/src/WorkloadRuntime.php',
            '/opt/duo-cloud/src/WorkloadSecurityInspection.php',
        ];
        if ($authorityPath === '/opt/duo-cloud/libexec/duo-cloud-firewall-authority') {
            $paths = [
                $authorityPath,
                '/opt/duo-cloud/runtime/FirewallAuthorityArguments.php',
                '/opt/duo-cloud/runtime/HostFirewallAuthority.php',
                '/opt/duo-cloud/runtime/HostFirewallConfig.php',
                ...$common,
            ];
        } elseif ($authorityPath === '/opt/duo-cloud/libexec/duo-cloud-storage-authority') {
            $paths = [
                $authorityPath,
                '/opt/duo-cloud/runtime/HostStorageConfig.php',
                '/opt/duo-cloud/runtime/XfsQuotaStorageAuthority.php',
                ...$common,
            ];
        } else {
            throw new ControlRefusal('firewall client installed authority path is unsupported');
        }
        sort($paths, SORT_STRING);
        return $paths;
    }

    /**
     * @return array{
     *     0:array<string,mixed>,
     *     1:string,
     *     2:list<array{
     *         executable:bool,
     *         label:string,
     *         limit:int,
     *         path:string,
     *         pin:bool,
     *         sha256:string,
     *         snapshot:array<string,int>
     *     }>
     * }
     */
    private static function installedPair(
        string $path,
        string $pinPath,
        string $label,
        int $ownerUid,
        ?string $nonCanonicalMessage = null
    ): array {
        $pin = self::rootPinIdentity($pinPath, "$label pin", $ownerUid);
        $configuration = self::pinnedIdentity(
            $path,
            $pin['configuration_sha256'],
            $label,
            self::LIMIT,
            false,
            $ownerUid
        );
        $document = self::canonicalDocument(
            $configuration['bytes'],
            $label,
            $nonCanonicalMessage
        );
        return [
            $document,
            $pin['configuration_sha256'],
            [$pin['identity'], $configuration['identity']],
        ];
    }

    /** @return array<string,mixed> */
    private static function canonicalDocument(
        string $bytes,
        string $label,
        ?string $nonCanonicalMessage = null
    ): array {
        $document = CanonicalJson::decodeObject($bytes, self::LIMIT);
        if ($bytes !== CanonicalJson::encode($document) . "\n") {
            throw new ControlRefusal(
                $nonCanonicalMessage ?? "$label is not canonical JSON with one trailing LF"
            );
        }
        return $document;
    }

    /**
     * @return array{
     *     bytes:string,
     *     identity:array{
     *         executable:bool,
     *         label:string,
     *         limit:int,
     *         path:string,
     *         pin:bool,
     *         sha256:string,
     *         snapshot:array<string,int>
     *     }
     * }
     */
    private static function pinnedIdentity(
        mixed $path,
        mixed $sha256,
        string $label,
        int $limit,
        bool $executable,
        int $ownerUid,
        ?int $exactSize = null
    ): array {
        if (!is_string($path) || $path === '' || $path[0] !== '/' || str_contains($path, "\0")
            || str_contains($path, '//') || str_ends_with($path, '/')
            || preg_match('#(?:^|/)\.\.?(/|$)#D', $path) === 1
            || !is_string($sha256) || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1
            || $ownerUid < 0 || $limit < 1 || $limit > self::EXECUTABLE_LIMIT
            || ($exactSize !== null && ($exactSize < 1 || $exactSize > $limit))) {
            throw new ControlRefusal("firewall client $label descriptor is invalid");
        }
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!is_array($before) || is_link($path) || ($before['mode'] & 0170000) !== 0100000
            || ($before['mode'] & 0022) !== 0 || (int) $before['nlink'] !== 1
            || (int) $before['size'] < 1 || (int) $before['size'] > $limit
            || ($exactSize !== null && (int) $before['size'] !== $exactSize)
            || (int) $before['uid'] !== $ownerUid || ($executable && !is_executable($path))) {
            throw new ControlRefusal("firewall client $label is not an approved regular file");
        }
        self::protectedAncestors(dirname($path), $label, $ownerUid);
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal("firewall client $label could not be opened");
        }
        $opened = fstat($handle);
        $bytes = stream_get_contents($handle, $limit + 1);
        $finished = fstat($handle);
        $closed = fclose($handle);
        clearstatcache(true, $path);
        $after = @lstat($path);
        if (!is_array($opened) || !is_array($finished) || !is_array($after)
            || !is_string($bytes) || !$closed || !self::sameFile($before, $opened)
            || !self::sameFile($before, $finished) || !self::sameFile($before, $after)
            || strlen($bytes) > $limit || !hash_equals($sha256, hash('sha256', $bytes))) {
            throw new ControlRefusal("firewall client $label changed or differs from its digest");
        }
        return [
            'bytes' => $bytes,
            'identity' => [
                'executable' => $executable,
                'label' => $label,
                'limit' => $limit,
                'path' => $path,
                'pin' => $exactSize === 65,
                'sha256' => $sha256,
                'snapshot' => self::snapshot($before),
            ],
        ];
    }

    /**
     * @return array{
     *     configuration_sha256:string,
     *     identity:array{
     *         executable:bool,
     *         label:string,
     *         limit:int,
     *         path:string,
     *         pin:bool,
     *         sha256:string,
     *         snapshot:array<string,int>
     *     }
     * }
     */
    private static function rootPinIdentity(string $path, string $label, int $ownerUid): array {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")
            || str_contains($path, '//') || str_ends_with($path, '/')
            || preg_match('#(?:^|/)\.\.?(/|$)#D', $path) === 1 || $ownerUid < 0) {
            throw new ControlRefusal('firewall client configuration pin path is invalid');
        }
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!is_array($before) || is_link($path) || ($before['mode'] & 0170000) !== 0100000
            || ($before['mode'] & 0022) !== 0 || (int) $before['nlink'] !== 1
            || (int) $before['uid'] !== $ownerUid || (int) $before['size'] !== 65) {
            throw new ControlRefusal('firewall client configuration pin is not protected');
        }
        self::protectedAncestors(dirname($path), $label, $ownerUid);
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal('firewall client configuration pin could not be opened');
        }
        $opened = fstat($handle);
        $bytes = stream_get_contents($handle, 66);
        $finished = fstat($handle);
        $closed = fclose($handle);
        clearstatcache(true, $path);
        $after = @lstat($path);
        if (!is_array($opened) || !is_array($finished) || !is_array($after)
            || !is_string($bytes) || !$closed || !self::sameFile($before, $opened)
            || !self::sameFile($before, $finished) || !self::sameFile($before, $after)
            || preg_match('/\A[a-f0-9]{64}\n\z/D', $bytes) !== 1) {
            throw new ControlRefusal('firewall client configuration pin changed or is invalid');
        }
        $identity = [
            'executable' => false,
            'label' => $label,
            'limit' => 65,
            'path' => $path,
            'pin' => true,
            'sha256' => hash('sha256', $bytes),
            'snapshot' => self::snapshot($before),
        ];
        return [
            'configuration_sha256' => substr($bytes, 0, -1),
            'identity' => $identity,
        ];
    }

    private static function protectedAncestors(string $path, string $label, int $ownerUid): void {
        while ($path !== '/') {
            $stat = @lstat($path);
            $owner = is_array($stat) ? (int) ($stat['uid'] ?? -1) : -1;
            if (!is_array($stat) || is_link($path) || ($stat['mode'] & 0170000) !== 0040000
                || ($stat['mode'] & 0022) !== 0 || !in_array($owner, [0, $ownerUid], true)) {
                throw new ControlRefusal(
                    "firewall client $label has an unprotected install ancestor"
                );
            }
            $parent = dirname($path);
            if ($parent === $path) {
                throw new ControlRefusal("firewall client $label install ancestry is invalid");
            }
            $path = $parent;
        }
    }

    /** @param array<string,mixed> $stat @return array<string,int> */
    private static function snapshot(array $stat): array {
        $snapshot = [];
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'uid', 'gid', 'mtime', 'ctime'] as $field) {
            $snapshot[$field] = (int) ($stat[$field] ?? -1);
        }
        return $snapshot;
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function sameFile(array $left, array $right): bool {
        return self::snapshot($left) === self::snapshot($right);
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new ControlRefusal('firewall client configuration has missing or unknown fields');
        }
    }
}

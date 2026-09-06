<?php

declare(strict_types=1);

namespace WPrism;

/**
 * One loader for every digest-bound PHP component shipped by an adapter.
 *
 * ArtifactPolicyIdentity supplies the descriptor; this class never rediscovers
 * a path or hashes a different manifest projection. The process registry is
 * keyed by PHP's case-insensitive symbol identity, so a class can be reused
 * only for the exact component bytes that first introduced it.
 */
final class ManifestExecutableLoader
{
    /** @var array<string,array{adapter:string,class:string,file:string,id:string,kind:string,sha256:string}> */
    private static array $loads = [];

    /** @return class-string The normalized symbol identity for one manifest executable. */
    public static function className(string $kind, string $id): string
    {
        if (preg_match('/\A[a-z][a-z0-9_-]*\z/D', $id) !== 1) {
            throw new \RuntimeException(
                'wprism: adapter executable id is not a canonical lowercase ASCII slug: ' . var_export($id, true)
            );
        }
        $namespace = match ($kind) {
            'interpreters' => 'Interpreters',
            'providers' => 'Providers',
            'regenerators' => 'Regenerators',
            default => throw new \RuntimeException("wprism: unknown adapter runtime kind '$kind'"),
        };
        $leaf = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $id)));
        return "\\WPrism\\$namespace\\$leaf";
    }

    /**
     * @param array{
     *     adapter:string,
     *     adapter_sha256:string,
     *     class:string,
     *     file:string,
     *     id:string,
     *     kind:string,
     *     sha256:?string
     * } $descriptor
     * @return class-string
     */
    public static function load(array $descriptor, ?string $requiredAdapterDigest = null): string
    {
        self::assertDescriptor($descriptor);

        $adapter = $descriptor['adapter'];
        $kind = $descriptor['kind'];
        $id = $descriptor['id'];
        $class = $descriptor['class'];
        $file = $descriptor['file'];
        $digest = $descriptor['sha256'];
        $label = self::label($kind);

        if ($requiredAdapterDigest !== null
            && (preg_match('/^[a-f0-9]{64}$/D', $requiredAdapterDigest) !== 1
                || !hash_equals($requiredAdapterDigest, $descriptor['adapter_sha256']))) {
            throw new \RuntimeException(
                "wprism: manifest '$adapter' $label '$id' no longer matches its validated adapter identity"
            );
        }
        if ($digest === null) {
            throw new \RuntimeException(
                "wprism: manifest '$adapter' $label '$id' has no digest-bound source at $file"
            );
        }

        $source = self::sourceSnapshot($file, $digest, $adapter, $label, $id);
        $registryKey = strtolower($class);
        $component = [
            'adapter' => $adapter,
            'class' => $class,
            'file' => $source['path'],
            'id' => $id,
            'kind' => $kind,
            'sha256' => $digest,
        ];
        $loaded = self::$loads[$registryKey] ?? null;
        $occupiedAs = self::symbolKind($class);

        if ($loaded !== null) {
            if ($loaded !== $component) {
                throw new \RuntimeException(
                    "wprism: manifest executable class $class is already bound to a different digest-bound component"
                );
            }
            if ($occupiedAs !== 'class') {
                throw new \RuntimeException(
                    "wprism: manifest executable class $class disappeared from its validated engine loader"
                );
            }
            self::assertDefinition($class, $source['path'], $adapter, $label, $id);
            return $class;
        }

        if ($occupiedAs !== null) {
            throw new \RuntimeException(
                "wprism: manifest '$adapter' $label '$id' was preloaded as $occupiedAs outside its validated engine loader"
            );
        }

        self::invalidateOpcodeCache($source['path'], $adapter, $label, $id);
        $loadSource = self::sourceSnapshot($file, $digest, $adapter, $label, $id);
        if (!self::sameFileIdentity($source, $loadSource)) {
            throw new \RuntimeException(
                "wprism: manifest '$adapter' $label '$id' source identity changed before executable loading"
            );
        }

        require_once $loadSource['path'];

        $loadedSource = self::sourceSnapshot($file, $digest, $adapter, $label, $id);
        if (!self::sameFileIdentity($loadSource, $loadedSource)) {
            throw new \RuntimeException(
                "wprism: manifest '$adapter' $label '$id' source identity changed while executable loading"
            );
        }
        $occupiedAs = self::symbolKind($class);
        if ($occupiedAs !== 'class') {
            throw new \RuntimeException(self::definitionContract($kind, $file, $class));
        }
        self::assertDefinition($class, $loadedSource['path'], $adapter, $label, $id);

        self::$loads[$registryKey] = $component;
        return $class;
    }

    /**
     * @param array<string,mixed> $descriptor
     * @phpstan-assert array{
     *     adapter:string,
     *     adapter_sha256:string,
     *     class:class-string,
     *     file:string,
     *     id:string,
     *     kind:string,
     *     sha256:?string
     * } $descriptor
     */
    private static function assertDescriptor(array $descriptor): void
    {
        $keys = array_keys($descriptor);
        sort($keys, SORT_STRING);
        if ($keys !== ['adapter', 'adapter_sha256', 'class', 'file', 'id', 'kind', 'sha256']
            || !is_string($descriptor['adapter'] ?? null)
            || preg_match('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/D', $descriptor['adapter']) !== 1
            || preg_match('/[a-z]/D', $descriptor['adapter']) !== 1
            || !is_string($descriptor['adapter_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $descriptor['adapter_sha256']) !== 1
            || !is_string($descriptor['kind'] ?? null)
            || !in_array($descriptor['kind'], ['interpreters', 'providers', 'regenerators'], true)
            || !is_string($descriptor['id'] ?? null)
            || preg_match('/^[a-z][a-z0-9_-]*$/D', $descriptor['id']) !== 1
            || !is_string($descriptor['file'] ?? null)
            || $descriptor['file'] === ''
            || str_contains($descriptor['file'], "\0")
            || !is_string($descriptor['class'] ?? null)
            || preg_match('/^\\\\WPrism\\\\(?:Interpreters|Providers|Regenerators)\\\\[A-Z][A-Za-z0-9]*$/D', $descriptor['class']) !== 1
            || (!is_null($descriptor['sha256'] ?? null)
                && (!is_string($descriptor['sha256'])
                    || preg_match('/^[a-f0-9]{64}$/D', $descriptor['sha256']) !== 1))) {
            throw new \RuntimeException('wprism: manifest executable descriptor is malformed');
        }
        $namespace = match ($descriptor['kind']) {
            'interpreters' => '\\WPrism\\Interpreters\\',
            'providers' => '\\WPrism\\Providers\\',
            'regenerators' => '\\WPrism\\Regenerators\\',
        };
        if (!str_starts_with($descriptor['class'], $namespace)) {
            throw new \RuntimeException('wprism: manifest executable descriptor class disagrees with its kind');
        }
        if ($descriptor['class'] !== self::className($descriptor['kind'], $descriptor['id'])) {
            throw new \RuntimeException('wprism: manifest executable descriptor class disagrees with its id');
        }
    }

    /**
     * @return array{path:string,dev:int,ino:int,mode:int,size:int,mtime:int,ctime:int}
     */
    private static function sourceSnapshot(
        string $file,
        string $digest,
        string $adapter,
        string $label,
        string $id
    ): array {
        clearstatcache(true, $file);
        if (is_link($file)) {
            throw new \RuntimeException(
                "wprism: manifest '$adapter' $label '$id' source may not be a symlink: $file"
            );
        }
        $canonical = realpath($file);
        if ($canonical === false || $canonical !== $file || !is_file($file) || !is_readable($file)) {
            throw new \RuntimeException(
                "wprism: manifest '$adapter' $label '$id' source is not its validated readable file: $file"
            );
        }
        $before = lstat($file);
        if (!is_array($before) || (((int) $before['mode']) & 0170000) !== 0100000) {
            throw new \RuntimeException(
                "wprism: manifest '$adapter' $label '$id' source is not a regular file: $file"
            );
        }
        $actual = hash_file('sha256', $file);
        clearstatcache(true, $file);
        $after = lstat($file);
        if (!is_string($actual)
            || !hash_equals($digest, $actual)
            || !is_array($after)
            || !self::sameStat($before, $after)) {
            throw new \RuntimeException(
                "wprism: manifest '$adapter' $label '$id' source changed after adapter identity validation"
            );
        }
        return [
            'path' => $canonical,
            'dev' => (int) $after['dev'],
            'ino' => (int) $after['ino'],
            'mode' => (int) $after['mode'],
            'size' => (int) $after['size'],
            'mtime' => (int) $after['mtime'],
            'ctime' => (int) $after['ctime'],
        ];
    }

    /** @param array<string,int> $left @param array<string,int> $right */
    private static function sameStat(array $left, array $right): bool
    {
        foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'] as $field) {
            if ((int) ($left[$field] ?? -1) !== (int) ($right[$field] ?? -2)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array{path:string,dev:int,ino:int,mode:int,size:int,mtime:int,ctime:int} $left
     * @param array{path:string,dev:int,ino:int,mode:int,size:int,mtime:int,ctime:int} $right
     */
    private static function sameFileIdentity(array $left, array $right): bool
    {
        return $left['path'] === $right['path'] && self::sameStat($left, $right);
    }

    /** @param null|callable(string):bool $invalidate */
    private static function invalidateOpcodeCache(
        string $file,
        string $adapter,
        string $label,
        string $id,
        ?bool $configured = null,
        ?callable $invalidate = null
    ): void {
        $configured ??= self::opcodeCacheConfigured();
        if (!$configured) {
            return;
        }
        $invalidate ??= static fn(string $path): bool => function_exists('opcache_invalidate')
            && @opcache_invalidate($path, true);
        if (!$invalidate($file)) {
            throw new \RuntimeException(
                "wprism: manifest '$adapter' $label '$id' opcode cache could not be invalidated before loading"
            );
        }
    }

    /**
     * Runtime status reporting may be hidden by opcache.restrict_api even
     * while this process executes cached code. Configuration is the fail-closed
     * authority: CLI additionally needs opcache.enable_cli; other SAPIs use
     * opcache.enable directly.
     */
    private static function opcodeCacheConfigured(): bool
    {
        if (!self::iniBoolean(ini_get('opcache.enable'))) {
            return false;
        }
        return PHP_SAPI !== 'cli' || self::iniBoolean(ini_get('opcache.enable_cli'));
    }

    private static function iniBoolean(string|false $value): bool
    {
        if ($value === false) {
            return false;
        }
        return !in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'none', 'off'], true);
    }

    /** @param class-string $class */
    private static function assertDefinition(
        string $class,
        string $file,
        string $adapter,
        string $label,
        string $id
    ): void {
        $reflection = new \ReflectionClass($class);
        $definedIn = $reflection->getFileName();
        if (!is_string($definedIn) || realpath($definedIn) !== $file) {
            throw new \RuntimeException(
                "wprism: manifest '$adapter' $label '$id' class $class was not defined by $file"
            );
        }
    }

    /** @return 'class'|'enum'|'interface'|'trait'|null */
    private static function symbolKind(string $class): ?string
    {
        if (function_exists('enum_exists') && enum_exists($class, false)) {
            return 'enum';
        }
        if (interface_exists($class, false)) {
            return 'interface';
        }
        if (trait_exists($class, false)) {
            return 'trait';
        }
        if (class_exists($class, false)) {
            return 'class';
        }
        return null;
    }

    private static function label(string $kind): string
    {
        return match ($kind) {
            'interpreters' => 'interpreter',
            'providers' => 'provider',
            'regenerators' => 'regenerator',
            default => 'executable',
        };
    }

    /** Preserve each public loader route's established packaging refusal. */
    private static function definitionContract(string $kind, string $file, string $class): string
    {
        return match ($kind) {
            'interpreters' => "wprism: interpreter file $file must define $class with "
                . 'post_meta_rule(string, array): ?array',
            'providers' => "wprism: provider file $file must define $class with identity(): array, "
                . 'capabilities(): array, and invoke(string $capability, array $args): array',
            'regenerators' => "wprism: regenerator file $file must define $class with "
                . 'regenerate(int $localId): void',
            default => "wprism: manifest executable file $file must define class $class",
        };
    }
}

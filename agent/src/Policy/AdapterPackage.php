<?php

declare(strict_types=1);

namespace Duo;

/** One adapter's closed set of reviewed and executable shipped bytes. */
final class AdapterPackage
{
    private string $name;
    private string $root;
    private string $manifestPath;
    private string $dispositionPath;
    private ?string $interpreterPath;
    /** @var array<string,string> */
    private array $providerPaths;
    /** @var array<string,string> */
    private array $regeneratorPaths;
    /** @var list<string> */
    private array $shippablePaths;

    /**
     * AdapterLibrary is the production constructor. Keeping this constructor
     * validating means a partially loaded test or tool cannot manufacture a
     * package whose declared path escapes the library root.
     *
     * @param array<string,string> $providerPaths provider id => absolute path
     * @param array<string,string> $regeneratorPaths regenerator id => absolute path
     */
    public function __construct(
        string $name,
        string $root,
        string $manifestPath,
        string $dispositionPath,
        ?string $interpreterPath,
        array $providerPaths,
        array $regeneratorPaths
    ) {
        self::assertName($name, 'adapter name');
        $canonicalRoot = self::canonicalRoot($root);

        $logicalLayout = $manifestPath === $canonicalRoot . '/manifest.json'
            && $dispositionPath === $canonicalRoot . '/disposition.json';
        $legacyLayout = $manifestPath === $canonicalRoot . '/' . $name . '.json'
            && $dispositionPath === $canonicalRoot . '/dispositions/' . $name . '.json';
        if (!$logicalLayout && !$legacyLayout) {
            throw new \RuntimeException("duo: adapter $name package paths do not match a supported explicit layout");
        }
        $runtimePrefix = $logicalLayout ? 'runtime/' : '';

        $this->name = $name;
        $this->root = $canonicalRoot;
        $this->manifestPath = self::packageFile(
            $canonicalRoot,
            $manifestPath,
            $logicalLayout ? 'manifest.json' : $name . '.json',
            "adapter $name manifest"
        );
        $this->dispositionPath = self::packageFile(
            $canonicalRoot,
            $dispositionPath,
            $logicalLayout ? 'disposition.json' : 'dispositions/' . $name . '.json',
            "adapter $name disposition"
        );

        $this->interpreterPath = null;
        if ($interpreterPath !== null) {
            $interpreter = pathinfo($interpreterPath, PATHINFO_FILENAME);
            self::assertName($interpreter, "adapter $name interpreter id");
            $this->interpreterPath = self::packageFile(
                $canonicalRoot,
                $interpreterPath,
                $runtimePrefix . 'interpreters/' . $interpreter . '.php',
                "adapter $name interpreter"
            );
        }

        $this->providerPaths = self::runtimePaths(
            $canonicalRoot,
            $providerPaths,
            $runtimePrefix . 'providers',
            "adapter $name provider"
        );
        $this->regeneratorPaths = self::runtimePaths(
            $canonicalRoot,
            $regeneratorPaths,
            $runtimePrefix . 'regenerators',
            "adapter $name regenerator"
        );

        $paths = [$this->manifestPath, $this->dispositionPath];
        if ($this->interpreterPath !== null) {
            $paths[] = $this->interpreterPath;
        }
        $paths = array_merge($paths, array_values($this->providerPaths), array_values($this->regeneratorPaths));
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);
        $this->shippablePaths = $paths;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function manifestPath(): string
    {
        return $this->manifestPath;
    }

    public function dispositionPath(): string
    {
        return $this->dispositionPath;
    }

    public function interpreterPath(): ?string
    {
        return $this->interpreterPath;
    }

    public function providerPath(string $provider): string
    {
        self::assertName($provider, 'provider id');
        if (!isset($this->providerPaths[$provider])) {
            throw new \RuntimeException("duo: adapter {$this->name} does not declare provider $provider");
        }
        return $this->providerPaths[$provider];
    }

    public function regeneratorPath(string $regenerator): string
    {
        self::assertName($regenerator, 'regenerator id');
        if (!isset($this->regeneratorPaths[$regenerator])) {
            throw new \RuntimeException("duo: adapter {$this->name} does not declare regenerator $regenerator");
        }
        return $this->regeneratorPaths[$regenerator];
    }

    /** @return list<string> */
    public function shippablePaths(): array
    {
        return $this->shippablePaths;
    }

    private static function canonicalRoot(string $root): string
    {
        if ($root === '' || str_contains($root, "\0")) {
            throw new \RuntimeException('duo: adapter package root is invalid');
        }
        if (is_link($root)) {
            throw new \RuntimeException("duo: adapter package root may not be a symlink: $root");
        }
        $canonical = realpath($root);
        if ($canonical === false || !is_dir($canonical) || !is_readable($canonical)) {
            throw new \RuntimeException("duo: adapter package root is not a readable directory: $root");
        }
        return rtrim($canonical, '/');
    }

    private static function packageFile(string $root, string $path, string $relative, string $label): string
    {
        if ($path !== $root . '/' . $relative) {
            throw new \RuntimeException("duo: $label must resolve to $relative inside its adapter package");
        }
        if (is_link($path)) {
            throw new \RuntimeException("duo: $label may not be a symlink: $path");
        }
        $canonical = realpath($path);
        if ($canonical === false || !is_file($canonical) || !is_readable($canonical)) {
            throw new \RuntimeException("duo: $label is not a readable regular file: $path");
        }
        if (!str_starts_with($canonical, $root . '/')) {
            throw new \RuntimeException("duo: $label escapes the adapter package root: $path");
        }
        return $canonical;
    }

    /**
     * @param array<string,string> $paths
     * @return array<string,string>
     */
    private static function runtimePaths(string $root, array $paths, string $directory, string $label): array
    {
        $validated = [];
        foreach ($paths as $id => $path) {
            self::assertName($id, "$label id");
            if (isset($validated[$id])) {
                throw new \RuntimeException("duo: $label $id is declared more than once");
            }
            $validated[$id] = self::packageFile(
                $root,
                $path,
                $directory . '/' . $id . '.php',
                "$label $id"
            );
        }
        ksort($validated, SORT_STRING);
        return $validated;
    }

    private static function assertName(string $name, string $label): void
    {
        if (preg_match('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/D', $name) !== 1
            || preg_match('/[a-z]/D', $name) !== 1) {
            throw new \RuntimeException("duo: $label is not a canonical lowercase ASCII slug: " . var_export($name, true));
        }
    }
}

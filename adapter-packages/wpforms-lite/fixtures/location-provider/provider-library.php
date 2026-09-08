<?php
declare(strict_types=1);

/** Private candidate identity, never an installed or production-authorizing library. */
final class WPFormsLocationProviderLibrary {
    public const PROVIDER = 'wpforms-form-locations';
    public const CAPABILITY = 'rebuild_form_locations';

    public static function create(string $source, string $target): WPrism\AdapterLibrary {
        if (file_exists($target) || is_link($target) || !mkdir($target, 0700)) {
            throw new RuntimeException('WPForms provider fixture requires an unoccupied library root');
        }
        $package = $target . '/adapter-packages/wpforms-lite/package';
        self::copy_tree($source . '/adapter-packages/wpforms-lite/package', $package);
        self::copy_tree($source . '/platform/adapter-library', $target . '/platform/adapter-library');
        // Negative identity/provider tests retain an excluded clone. The
        // native Apply lane uses the actual package, including in its fresh
        // verifier; a private parent-only library cannot confer that authority.
        $disposition = json_decode((string) file_get_contents($package . '/disposition.json'), true, 64, JSON_THROW_ON_ERROR);
        $disposition['status'] = 'excluded';
        $disposition['reason'] = 'Private native provider-path fixture only; not Apply, Deploy, lifecycle or production readiness evidence.';
        $disposition['unsupported'][0]['reason'] = 'This private candidate exercises provider loading and execution only; full Apply remains unproved.';
        unset($disposition['evidence']);
        self::replace($package . '/disposition.json', json_encode($disposition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        return WPrism\AdapterLibrary::fromSourcePackage($target, 'wpforms-lite');
    }

    public static function compile_and_load(string $repo, WPrism\AdapterLibrary $library): array {
        $policy = WPrism\Policy::load($repo, adapterLibrary: $library);
        $artifact = WPrism\RepositoryCompiler::compile($repo, $policy);
        $path = $repo . '/.wprism/compiled/provider.json';
        $artifact->write($path);
        $policy = WPrism\Policy::load($repo, adapterLibrary: $library);
        $read = WPrism\RepositoryCompiler::read_artifact($path, $policy);
        if ($read->artifact_hash() !== $artifact->artifact_hash()) throw new RuntimeException('WPForms fixture artifact changed on reread');
        return [$policy, $read];
    }

    private static function copy_tree(string $source, string $target): void {
        if (is_link($source) || file_exists($target) || is_link($target)) {
            throw new RuntimeException('WPForms fixture copy requires ordinary source and unoccupied destination');
        }
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true)) {
            throw new RuntimeException('WPForms fixture cannot create private parent');
        }
        if (is_dir($source)) {
            if (!mkdir($target, 0700)) throw new RuntimeException('WPForms fixture cannot create private directory');
            foreach (scandir($source) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') self::copy_tree($source . '/' . $name, $target . '/' . $name);
            }
            return;
        }
        if (!is_file($source)) throw new RuntimeException('WPForms fixture source is not an ordinary file');
        $bytes = file_get_contents($source);
        if (!is_string($bytes)) throw new RuntimeException('WPForms fixture source is unreadable');
        self::replace($target, $bytes);
        if (!hash_equals(hash('sha256', $bytes), (string) hash_file('sha256', $target))) {
            throw new RuntimeException('WPForms fixture copied bytes do not verify');
        }
    }

    private static function replace(string $path, string $bytes): void {
        if (file_put_contents($path, $bytes) !== strlen($bytes) || !chmod($path, 0600)) {
            throw new RuntimeException('WPForms fixture cannot publish private bytes');
        }
    }
}

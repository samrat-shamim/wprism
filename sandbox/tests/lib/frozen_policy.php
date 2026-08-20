<?php
/**
 * The frozen-policy envelope for suites whose subject is NOT the snapshot wire.
 *
 * Eighteen suites needed a `Policy` instance to test something else — a
 * grammar, an action selection, an effects inventory — and built one by handing
 * `Policy::from_snapshot()` the smallest envelope it would accept. That
 * envelope used to be `duo-policy-snapshot/v4`, whose
 * `duo-adapter-sources/v1` record let a manifest absent from `out_of_tree` take
 * shipped authority with no proof at all, so any synthetic manifest name
 * loaded for free. v4 is now refused by name
 * (`Policy::from_snapshot()`), and v2 makes that shipped claim fail-closed:
 * `AdapterSources::from_snapshot()` compares the frozen manifest canonically
 * against `<manifests_dir>/<name>.json` and refuses with "frozen provenance
 * cannot relabel site content as shipped" when there is nothing to compare to.
 *
 * So a synthetic manifest now needs a trusted library that really holds its
 * bytes. `envelope()` writes exactly that into a scratch directory and points
 * `DUO_MANIFESTS_DIR` at it — the same idiom `regress_adapter_registry.php`
 * and `regress_plugin_adapter_source.php` already use — which is why the
 * vehicles moved rather than being deleted: they now ride the fail-closed wire
 * they will meet in production instead of the fail-open one nothing writes.
 *
 * Suites that publish their OWN manifest library (they exercise
 * `Policy::load()` too) call `envelope()` before that setup, or pass their own
 * directory to `library()`.
 */
declare(strict_types=1);

namespace DuoTest;

final class FrozenPolicy
{
    /** The one wire generation `Policy::from_snapshot()` reads. */
    public const SNAPSHOT_FORMAT = 'duo-policy-snapshot/v6';
    public const ADAPTER_SOURCES_FORMAT = 'duo-adapter-sources/v2';

    private static ?string $library = null;

    /**
     * A scratch shipped manifest library, created once per process and removed
     * at shutdown. `DUO_MANIFESTS_DIR` points at it from the first call.
     */
    public static function library(): string
    {
        if (self::$library !== null) {
            return self::$library;
        }
        $dir = sys_get_temp_dir() . '/duo_frozen_policy_' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            fwrite(STDERR, "FAIL: cannot create scratch manifest library $dir\n");
            exit(1);
        }
        self::$library = $dir;
        register_shutdown_function(static function () use ($dir): void {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        });
        return $dir;
    }

    /**
     * Publish `$manifests` as shipped bytes so the v2 membership proof has
     * something to compare against, and return the v6 envelope naming them.
     *
     * The bytes are rewritten on every call because refusal cases mutate a
     * manifest and reuse its name: the proof is against THESE bytes, not
     * against whatever an earlier case happened to leave behind.
     *
     * `$library` names an existing scratch manifest directory the CALLER
     * already owns and already points `DUO_MANIFESTS_DIR` at — the case for a
     * suite that also drives `Policy::load()` against its own fixture library.
     * Passing it keeps this helper from moving the env out from under that
     * suite. It is never the real `manifests/` directory: publishing there
     * would leave scratch in the shipped tree (AGENTS.md rule 3).
     *
     * @param list<array<string,mixed>> $manifests
     * @param array<string,mixed>       $site
     * @return array<string,mixed>
     */
    public static function envelope(array $manifests, array $site, ?string $library = null): array
    {
        $dir = $library;
        if ($dir === null) {
            $dir = self::library();
            putenv('DUO_MANIFESTS_DIR=' . $dir);
        }
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '');
            if ($name === '') {
                // A nameless manifest is a case about manifest validation, which
                // refuses before the provenance proof runs. Nothing to publish.
                continue;
            }
            file_put_contents($dir . '/' . $name . '.json', \Duo\Canon::encode($manifest));
        }
        return [
            'adapter_sources' => self::adapterSources(),
            'dispositions' => null,
            'format' => self::SNAPSHOT_FORMAT,
            'manifests' => $manifests,
            'site' => $site,
        ];
    }

    /**
     * The site half most vehicles want: pin exactly the manifests being frozen,
     * declare no authored policy.
     *
     * @param list<array<string,mixed>> $manifests
     * @return array<string,mixed>
     */
    public static function site(array $manifests, int $specVersion = 2): array
    {
        return [
            'manifests' => array_map(
                static fn(array $manifest): string => (string) ($manifest['name'] ?? ''),
                $manifests
            ),
            'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []],
            'spec_version' => $specVersion,
        ];
    }

    /** @return array<string,mixed> */
    public static function adapterSources(): array
    {
        return [
            'certificates' => [],
            'format' => self::ADAPTER_SOURCES_FORMAT,
            'out_of_tree' => [],
        ];
    }
}

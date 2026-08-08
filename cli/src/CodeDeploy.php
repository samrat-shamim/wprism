<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/**
 * Host-side half of code deployment.
 *
 * The agent owns all filesystem mutation and verification.  The host only
 * creates one immutable compiler artifact, decides whether that artifact
 * declares code materialization, and carries its expected outer hash, exact
 * artifact path, and one promotion owner through stage -> lifecycle retire ->
 * fresh-process activate -> finalize. Keeping
 * this boundary here is intentional: a Docker/SSH/local target can decide
 * how to materialize its code without the orchestration CLI learning its
 * filesystem layout or reimplementing any agent validation.
 */
final class CodeDeploy {
    /**
     * Load only the out-of-band Duo agent for control-plane commands. A
     * newly staged regular plugin, theme, or user MU plugin may fatal during
     * WordPress bootstrap; compile/stage/finalize and exact lease cleanup
     * must remain available so the next reviewed artifact can repair it.
     *
     * WP-CLI evaluates --exec before wp-config.php, so the bootstrap registers
     * an after_wp_config_load hook instead of guessing the configured layout.
     * That hook first proves the effective content/MU roots are standard, then
     * shadows the MU root with a fresh nonexistent directory so user MU code
     * is never included. DUO_CONTROL_WPMU_PLUGIN_DIR preserves the proven real
     * materialization target for Code.php, and the protected agent is required
     * explicitly from that standard installation path.
     */
    private const CONTROL_BOOTSTRAP = <<<'PHP'
$duoWpRoot = (string) (\WP_CLI::get_runner()->config['path'] ?? '');
if ($duoWpRoot === '') {
    $duoWpRoot = (string) getcwd();
}
$duoResolvedRoot = realpath($duoWpRoot);
if ($duoResolvedRoot === false) {
    throw new \RuntimeException('duo: control-plane bootstrap could not resolve the WordPress root');
}
$duoWpRoot = rtrim($duoResolvedRoot, '/');
$duoAgent = $duoWpRoot . '/wp-content/mu-plugins/duo/duo.php';
if (defined('WPMU_PLUGIN_DIR')) {
    throw new \RuntimeException('duo: control-plane bootstrap started with WPMU_PLUGIN_DIR already defined');
}
\WP_CLI::add_hook('after_wp_config_load', static function () use ($duoWpRoot, $duoAgent): void {
    $duoNormalize = static function (string $path): string {
        $resolved = realpath($path);
        $path = $resolved === false ? $path : $resolved;
        return rtrim(str_replace('\\', '/', $path), '/');
    };
    $duoStandardContent = $duoWpRoot . '/wp-content';
    $duoConfiguredContent = defined('WP_CONTENT_DIR')
        ? (string) constant('WP_CONTENT_DIR')
        : $duoStandardContent;
    $duoConfiguredMu = defined('WPMU_PLUGIN_DIR')
        ? (string) constant('WPMU_PLUGIN_DIR')
        : rtrim($duoConfiguredContent, '/\\') . '/mu-plugins';
    $duoStandardMu = $duoStandardContent . '/mu-plugins';
    if (defined('SUNRISE')) {
        throw new \RuntimeException(
            'duo: control-plane bootstrap cannot safely isolate a configured SUNRISE loader'
        );
    }
    if ($duoNormalize($duoConfiguredContent) !== $duoNormalize($duoStandardContent)
        || $duoNormalize($duoConfiguredMu) !== $duoNormalize($duoStandardMu)) {
        throw new \RuntimeException(
            "duo: control-plane bootstrap requires standard wp-content/mu-plugins; wp-config.php resolves '$duoConfiguredMu'"
        );
    }
    if (defined('WPMU_PLUGIN_DIR')) {
        throw new \RuntimeException(
            'duo: control-plane bootstrap cannot safely isolate an explicit WPMU_PLUGIN_DIR; remove the redundant standard definition or use a supported target layout'
        );
    }
    if (!is_file($duoAgent)) {
        throw new \RuntimeException("duo: control-plane bootstrap could not find the protected agent at '$duoAgent'");
    }
    define('DUO_CONTROL_PLANE', true);
    define('DUO_CONTROL_WPMU_PLUGIN_DIR', $duoStandardMu);
    define('WPMU_PLUGIN_DIR', $duoWpRoot . '/wp-content/.duo-control-mu-' . bin2hex(random_bytes(16)));
    require_once $duoAgent;
});
PHP;

    /**
     * Compile into a target-visible artifact and decode the agent's JSON
     * response.  `wp duo compile --format=json` is the sole source of truth
     * for whether code exists in this revision; do not inspect a mutable
     * checkout on the host after compilation.
     *
     * @return array{exit:int, stdout:string, stderr:string, summary:?array}
     */
    public static function compile(Transport $transport, string $repo, string $artifact): array {
        $result = $transport->captureWp(self::controlArgs([
            'duo', 'compile', '--repo=' . $repo, '--out=' . $artifact, '--format=json',
        ]));
        if ($result['exit'] !== 0) {
            return $result + ['summary' => null];
        }

        $summary = json_decode(trim($result['stdout']), true);
        if (!is_array($summary) || !self::validArtifactHash($summary)) {
            return $result + ['summary' => null];
        }
        return $result + ['summary' => $summary];
    }

    /** The content-addressed artifact invariant the host can validate itself. */
    public static function validArtifactHash(array $summary): bool {
        return is_string($summary['artifact_hash'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/', (string) $summary['artifact_hash']) === 1;
    }

    /**
     * Whether this compiled revision declares a code half.
     *
     * The compiled repository's top-level `code` value is an opaque
     * descriptor which includes a separate code revision. The host
     * intentionally knows no descriptor internals: code-stage/finalize
     * receive the frozen artifact and let the agent validate paths, hashes,
     * ownership, and transport-local facts. An old artifact with no `code`
     * descriptor takes the pre-code lifecycle path exactly.
     */
    public static function enabled(array $summary): bool {
        return isset($summary['code']) && is_array($summary['code']) && $summary['code'] !== [];
    }

    /**
     * Return pinned adapters whose external review status is not certified.
     * Null dispositions preserve legacy/custom test libraries which do not
     * publish certification claims; canonical shipped entries always carry
     * a disposition and are checked before a target lease or checkpoint.
     */
    public static function dispositionBlockers(array $summary): array {
        $out = [];
        foreach (($summary['resolved_adapters'] ?? []) as $adapter) {
            if (!is_array($adapter) || !is_array($adapter['disposition'] ?? null)) {
                continue;
            }
            $disposition = $adapter['disposition'];
            if (($disposition['status'] ?? null) === 'certified') {
                continue;
            }
            $out[] = [
                'name' => (string) ($adapter['name'] ?? '?'),
                'status' => (string) ($disposition['status'] ?? 'unreviewed'),
                'reason' => (string) ($disposition['reason'] ?? 'not certified'),
            ];
        }
        return $out;
    }

    /**
     * Acquire the target lease before promotion's DB checkpoint. The outer
     * artifact hash is deliberately the only code/state input: it binds both
     * halves without making the host inspect the opaque code descriptor.
     *
     * @return array<int,string>
     */
    public static function beginArgs(string $owner, string $artifactHash): array {
        return self::controlArgs([
            'duo', 'promotion-begin', '--promotion-owner=' . $owner,
            '--artifact-hash=' . $artifactHash,
        ]);
    }

    /**
     * Exact, idempotent cleanup. Hash rather than --compiled keeps recovery
     * available when a failed run's checkout/policy changes before cleanup.
     *
     * @return array<int,string>
     */
    public static function abortArgs(string $owner, string $artifactHash): array {
        return self::controlArgs([
            'duo', 'promotion-abort', '--promotion-owner=' . $owner,
            '--artifact-hash=' . $artifactHash,
        ]);
    }

    /**
     * Recovery imports must remain reachable even when installed user code
     * fatals during ordinary WordPress bootstrap. Reuse the isolated control
     * bootstrap so the checkpoint restore cannot load plugins, themes, or the
     * real user MU directory before replacing the database.
     *
     * @return array<int,string>
     */
    public static function recoveryDbImportArgs(string $checkpoint): array {
        return self::controlArgs(['db', 'import', $checkpoint]);
    }

    /** @return array<int,string> */
    public static function stageArgs(string $repo, string $artifact, string $owner, string $artifactHash): array {
        return self::controlArgs([
            'duo', 'code-stage', '--repo=' . $repo, '--compiled=' . $artifact,
            '--promotion-owner=' . $owner, '--artifact-hash=' . $artifactHash,
        ]);
    }

    /** @return array<int,string> */
    public static function finalizeArgs(
        string $repo,
        string $artifact,
        string $owner,
        string $artifactHash,
        bool $hold
    ): array {
        $args = [
            'duo', 'code-finalize', '--repo=' . $repo, '--compiled=' . $artifact,
            '--promotion-owner=' . $owner, '--artifact-hash=' . $artifactHash,
        ];
        if ($hold) {
            $args[] = '--promotion-hold';
        }
        return self::controlArgs($args);
    }

    /** @return array<int,string> */
    public static function lifecycleArgs(
        string $repo,
        string $artifact,
        string $owner,
        string $artifactHash,
        bool $hold,
        bool $materializingCode,
        bool $stateHandoff,
        string $lifecyclePhase,
        array $extra = []
    ): array {
        if (!in_array($lifecyclePhase, ['retire', 'activate'], true)) {
            throw new \InvalidArgumentException("unsupported lifecycle phase '$lifecyclePhase'");
        }
        $args = [
            'duo', 'deploy', '--repo=' . $repo, '--compiled=' . $artifact,
            '--promotion-owner=' . $owner, '--artifact-hash=' . $artifactHash,
            '--lifecycle-phase=' . $lifecyclePhase,
        ];
        if ($hold) {
            $args[] = '--promotion-hold';
        }
        if ($materializingCode) {
            $args[] = '--materializing-code';
        }
        if ($stateHandoff) {
            $args[] = '--state-handoff';
        }
        return array_merge($args, $extra);
    }

    /** @return array<int,string> */
    public static function controlArgs(array $command): array {
        $bootstrap = trim(str_replace(["\r", "\n"], ' ', self::CONTROL_BOOTSTRAP));
        return array_merge([
            '--exec=' . $bootstrap,
            '--skip-plugins',
            '--skip-themes',
        ], $command);
    }
}

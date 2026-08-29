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
    /* A control-plane command observes or drives the target; it must not
       spawn the target's cron. WordPress core hooks wp_cron() on `init`, and
       spawn_cron() rewrites the doing_cron transient (a database write) and
       POSTs wp-cron.php, which runs the very plugin code this bootstrap
       exists to keep out of the window (Action Scheduler included). Found
       live: refresh-export's nominal read-only observation moved the source
       between a rehearsal's snapshot-prepare and snapshot-create. Block
       comment on purpose: this bootstrap is flattened to one line. */
    if (!defined('DISABLE_WP_CRON')) {
        define('DISABLE_WP_CRON', true);
    }
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
    public static function compile(EnvironmentDriver $transport, string $repo, string $artifact): array {
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

    /**
     * Read the target's exact runtime through the protected control plane and
     * compare it with standard headers in the frozen artifact's source. The
     * report is intentionally outside the artifact/hash: target evidence is
     * ephemeral and must be reacquired before every new promotion lease.
     *
     * @return array{exit:int,stdout:string,stderr:string,summary:?array}
     */
    public static function preflight(
        EnvironmentDriver $transport,
        string $repo,
        string $artifact,
        string $artifactHash,
        string $codeRevision
    ): array {
        $result = $transport->captureWp(self::preflightArgs($repo, $artifact, $artifactHash));
        if ($result['exit'] !== 0) {
            return $result + ['summary' => null];
        }
        $summary = json_decode(trim($result['stdout']), true);
        if (!is_array($summary)
            || ($summary['format'] ?? null) !== 'duo-code-runtime/v1'
            || ($summary['enabled'] ?? null) !== true
            || !is_bool($summary['change_required'] ?? null)
            || ($summary['compatible'] ?? null) !== true
            || !hash_equals($codeRevision, (string) ($summary['code_revision'] ?? ''))
            || !is_array($summary['target'] ?? null)
            || ($summary['target']['source'] ?? null) !== 'target-control-plane'
            || !is_string($summary['target']['php'] ?? null)
            || trim((string) $summary['target']['php']) === ''
            || !is_string($summary['target']['wordpress'] ?? null)
            || trim((string) $summary['target']['wordpress']) === ''
            || !is_array($summary['requirements'] ?? null)
            || ($summary['diagnostics'] ?? null) !== []) {
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
     * Return pinned adapters whose reviewed capability claim is not certified.
     *
     * The null-claim row is NOT dead with the generated registry: a shipped
     * adapter's claim is now projected from its own reviewed disposition
     * (AdapterRegistry::capability_claim()), so it exists wherever a
     * disposition does — but an out-of-tree adapter carries a synthesized
     * provenance record as its `disposition` while AdapterSources::claim()
     * answers null until a certificate verifies. That row is a signed-nothing
     * site adapter reaching a promotion lease, which must refuse.
     *
     * Evidence currency is deliberately no longer a second axis. A shipped
     * claim carries the authored citation verbatim and has no `evidence.status`
     * at all; a site claim exists only after AdapterCertification verified the
     * signature that produced it (AdapterCertification::projectClaim()). The
     * old `evidence.status === 'current'` conjunct would now refuse every
     * certified shipped adapter.
     */
    public static function dispositionBlockers(array $summary): array {
        $out = [];
        foreach (($summary['resolved_adapters'] ?? []) as $adapter) {
            if (!is_array($adapter) || !is_array($adapter['disposition'] ?? null)) {
                continue;
            }
            $capability = $adapter['capability'] ?? null;
            if (!is_array($capability)) {
                $out[] = [
                    'name' => (string) ($adapter['name'] ?? '?'),
                    'status' => 'unsupported',
                    'reason' => 'no reviewed capability claim is bound to this compiled adapter',
                ];
                continue;
            }
            if (($capability['status'] ?? null) === 'certified') {
                continue;
            }
            $out[] = [
                'name' => (string) ($adapter['name'] ?? '?'),
                'status' => (string) ($capability['status'] ?? 'unsupported'),
                'reason' => (string) (
                    $capability['reason']
                    ?? $adapter['disposition']['reason']
                    ?? 'capability is not certified'
                ),
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
     * Idempotent target handoff for the externally checkpointed scoped
     * promotion profile. Exact retries reuse rather than rotate its ps-* id.
     *
     * @return array<int,string>
     */
    public static function beginScopedArgs(
        string $owner,
        string $artifactHash,
        string $receiptHash,
        string $scopeHash
    ): array {
        return self::controlArgs([
            'duo', 'promotion-begin-scoped', '--promotion-owner=' . $owner,
            '--artifact-hash=' . $artifactHash,
            '--scoped-promotion-receipt=' . $receiptHash,
            '--scope-hash=' . $scopeHash,
            '--format=json',
        ]);
    }

    /** @return array<int,string> */
    public static function completeScopedArgs(
        string $owner,
        string $artifactHash,
        string $receiptHash,
        string $scopeHash
    ): array {
        return self::controlArgs([
            'duo', 'promotion-complete-scoped', '--promotion-owner=' . $owner,
            '--artifact-hash=' . $artifactHash,
            '--scoped-promotion-receipt=' . $receiptHash,
            '--scope-hash=' . $scopeHash,
            '--format=json',
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
     * The same two lease commands, asked in MACHINE mode, for `duo recover`
     * only (DUO-3506).
     *
     * `abortArgs()`/`beginArgs()` stay human deliberately: promote and deploy
     * run them as compensating cleanup and render the target's own stdout and
     * stderr straight back to the operator who is standing there
     * (cli/duo:3094-3103), so a `--format=json` on those two would change
     * that output for every caller of those verbs.
     *
     * `RecoverCommand` has the opposite need. It drives the four ordered
     * steps unattended and reports one line per step, so with the agent in
     * human mode `promotion_abort()` falls through to
     * `WP_CLI::error($t->getMessage())` (agent/src/Command/Cli.php:645-657)
     * and the reason exists only as prose on a stream nothing reads. Asked in
     * JSON, the agent answers a refusal with `duo-command-refusal/v1`
     * carrying a stable reason code — `promotion_abort_session_superseded`
     * for the case that produced this issue — which `RecoverCommand::step()`
     * surfaces. Same command, same identity, same order; only the reply
     * format differs.
     *
     * @return array<int,string>
     */
    public static function recoveryAbortArgs(string $owner, string $artifactHash): array {
        return array_merge(self::abortArgs($owner, $artifactHash), ['--format=json']);
    }

    /** @return array<int,string> */
    public static function recoveryBeginArgs(string $owner, string $artifactHash): array {
        return array_merge(self::beginArgs($owner, $artifactHash), ['--format=json']);
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

    /**
     * Export directly into authenticated ciphertext. No durable plaintext
     * path exists: the transport connects the two WP-CLI process streams.
     *
     * @return array{exit:int,stdout:string,stderr:string}
     */
    public static function encryptedCheckpoint(
        EnvironmentDriver $transport,
        string $repo,
        string $checkpoint
    ): array {
        if (!is_callable([$transport, 'captureWpPipeline'])) {
            return [
                'exit' => 1,
                'stdout' => '',
                'stderr' => 'the target transport does not implement the authenticated checkpoint stream',
            ];
        }

        return $transport->captureWpPipeline(
            ['db', 'export', '-'],
            self::controlArgs(['duo', 'checkpoint-seal', '--repo=' . $repo, '--output=' . $checkpoint])
        );
    }

    /**
     * Authenticate the entire ciphertext, then stream it into the isolated
     * database importer without a durable plaintext staging file.
     *
     * @return array{exit:int,stdout:string,stderr:string}
     */
    public static function encryptedCheckpointImport(
        EnvironmentDriver $transport,
        string $repo,
        string $checkpoint
    ): array {
        if (!is_callable([$transport, 'captureWpPipeline'])) {
            return [
                'exit' => 1,
                'stdout' => '',
                'stderr' => 'the target transport does not implement the authenticated checkpoint stream',
            ];
        }

        return $transport->captureWpPipeline(
            self::controlArgs(['duo', 'checkpoint-open', '--repo=' . $repo, '--input=' . $checkpoint]),
            self::controlArgs(['db', 'import', '-'])
        );
    }

    /** @return array<int,string> */
    public static function stageArgs(string $repo, string $artifact, string $owner, string $artifactHash): array {
        return self::controlArgs([
            'duo', 'code-stage', '--repo=' . $repo, '--compiled=' . $artifact,
            '--promotion-owner=' . $owner, '--artifact-hash=' . $artifactHash,
        ]);
    }

    /** @return array<int,string> */
    public static function preflightArgs(string $repo, string $artifact, string $artifactHash): array {
        return self::controlArgs([
            'duo', 'code-preflight', '--repo=' . $repo, '--compiled=' . $artifact,
            '--artifact-hash=' . $artifactHash, '--format=json',
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
    public static function lifecycleSettleArgs(
        string $repo,
        string $artifact,
        string $artifactHash,
        string $owner
    ): array {
        // Deliberately not controlArgs(): settlement runs the newly activated
        // plugin and its native queue provider in a fresh ordinary process.
        return [
            'duo', 'lifecycle-settle', '--repo=' . $repo,
            '--compiled=' . $artifact, '--artifact-hash=' . $artifactHash,
            '--promotion-owner=' . $owner,
        ];
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

<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

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
     * Load only the out-of-band WPrism agent for control-plane commands. A
     * newly staged regular plugin, theme, or user MU plugin may fatal during
     * WordPress bootstrap; compile/stage/finalize and exact lease cleanup
     * must remain available so the next reviewed artifact can repair it.
     *
     * WP-CLI evaluates --exec before wp-config.php, so the bootstrap registers
     * an after_wp_config_load hook instead of guessing the configured layout.
     * That hook first proves the effective content/MU roots are standard, then
     * shadows the MU root with a fresh nonexistent directory so user MU code
     * is never included. WPRISM_CONTROL_WPMU_PLUGIN_DIR preserves the proven real
     * materialization target for Code.php, and the protected agent is required
     * explicitly from that standard installation path.
     */
    private const CONTROL_BOOTSTRAP = <<<'PHP'
$wprismWpRoot = (string) (\WP_CLI::get_runner()->config['path'] ?? '');
if ($wprismWpRoot === '') {
    $wprismWpRoot = (string) getcwd();
}
$wprismResolvedRoot = realpath($wprismWpRoot);
if ($wprismResolvedRoot === false) {
    throw new \RuntimeException('wprism: control-plane bootstrap could not resolve the WordPress root');
}
$wprismWpRoot = rtrim($wprismResolvedRoot, '/');
$wprismAgent = $wprismWpRoot . '/wp-content/mu-plugins/wprism/wprism.php';
if (defined('WPMU_PLUGIN_DIR')) {
    throw new \RuntimeException('wprism: control-plane bootstrap started with WPMU_PLUGIN_DIR already defined');
}
\WP_CLI::add_hook('after_wp_config_load', static function () use ($wprismWpRoot, $wprismAgent): void {
    $wprismNormalize = static function (string $path): string {
        $resolved = realpath($path);
        $path = $resolved === false ? $path : $resolved;
        return rtrim(str_replace('\\', '/', $path), '/');
    };
    $wprismStandardContent = $wprismWpRoot . '/wp-content';
    $wprismConfiguredContent = defined('WP_CONTENT_DIR')
        ? (string) constant('WP_CONTENT_DIR')
        : $wprismStandardContent;
    $wprismConfiguredMu = defined('WPMU_PLUGIN_DIR')
        ? (string) constant('WPMU_PLUGIN_DIR')
        : rtrim($wprismConfiguredContent, '/\\') . '/mu-plugins';
    $wprismStandardMu = $wprismStandardContent . '/mu-plugins';
    if (defined('SUNRISE')) {
        throw new \RuntimeException(
            'wprism: control-plane bootstrap cannot safely isolate a configured SUNRISE loader'
        );
    }
    if ($wprismNormalize($wprismConfiguredContent) !== $wprismNormalize($wprismStandardContent)
        || $wprismNormalize($wprismConfiguredMu) !== $wprismNormalize($wprismStandardMu)) {
        throw new \RuntimeException(
            "wprism: control-plane bootstrap requires standard wp-content/mu-plugins; wp-config.php resolves '$wprismConfiguredMu'"
        );
    }
    if (defined('WPMU_PLUGIN_DIR')) {
        throw new \RuntimeException(
            'wprism: control-plane bootstrap cannot safely isolate an explicit WPMU_PLUGIN_DIR; remove the redundant standard definition or use a supported target layout'
        );
    }
    if (!is_file($wprismAgent)) {
        throw new \RuntimeException("wprism: control-plane bootstrap could not find the protected agent at '$wprismAgent'");
    }
    define('WPRISM_CONTROL_PLANE', true);
    define('WPRISM_CONTROL_WPMU_PLUGIN_DIR', $wprismStandardMu);
    define('WPMU_PLUGIN_DIR', $wprismWpRoot . '/wp-content/.wprism-control-mu-' . bin2hex(random_bytes(16)));
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
    require_once $wprismAgent;
});
PHP;

    /** WordPress bootstrap for one checkpoint-bound core database command. */
    private const ISOLATED_DATABASE_BOOTSTRAP = <<<'PHP'
$wprismDatabaseTargetPayload = json_decode(base64_decode('__PAYLOAD__', true), true, 8, JSON_THROW_ON_ERROR);
if (!is_array($wprismDatabaseTargetPayload)
    || array_keys($wprismDatabaseTargetPayload) !== ['database_target_sha256', 'repo', 'require_recovery_intent']
    || !is_bool($wprismDatabaseTargetPayload['require_recovery_intent'])) {
    throw new \RuntimeException('wprism: malformed isolated database target payload');
}
if (defined('WPMU_PLUGIN_DIR')) {
    throw new \RuntimeException('wprism: isolated database bootstrap started with WPMU_PLUGIN_DIR already defined');
}
\WP_CLI::add_hook('after_wp_config_load', static function () use ($wprismDatabaseTargetPayload): void {
    if (defined('SUNRISE') || defined('WPMU_PLUGIN_DIR')) {
        throw new \RuntimeException('wprism: isolated database bootstrap cannot isolate this wp-config.php');
    }
    if (!defined('DISABLE_WP_CRON')) {
        define('DISABLE_WP_CRON', true);
    }
    $base = defined('ABSPATH') ? rtrim((string) constant('ABSPATH'), '/\\') : (string) getcwd();
    define('WPMU_PLUGIN_DIR', $base . '/.wprism-recovery-mu-' . bin2hex(random_bytes(16)));
    $root = rtrim((string) $wprismDatabaseTargetPayload['repo'], '/') . '/.wprism/control';
    $runtime = $root . '/recovery-runtime';
    $identityPath = $runtime . '/DatabaseTargetIdentity.php';
    if (is_link($identityPath) || !is_file($identityPath)) {
        throw new \RuntimeException('wprism: durable database target runtime is incomplete');
    }
    require_once $identityPath;
    $databaseTargetSha256 = \WPrism\DatabaseTargetIdentity::fromWordPressConfig();
    if (!hash_equals((string) $wprismDatabaseTargetPayload['database_target_sha256'], $databaseTargetSha256)) {
        throw new \RuntimeException('wprism: configured database target changed before checkpoint database access');
    }
    if (!$wprismDatabaseTargetPayload['require_recovery_intent']) {
        return;
    }
    foreach (['CanonicalJson.php', 'AtomicStore.php', 'ProtocolLock.php', 'CheckpointRecoveryIntent.php'] as $file) {
        $path = $runtime . '/' . $file;
        if (is_link($path) || !is_file($path)) {
            throw new \RuntimeException('wprism: durable checkpoint recovery runtime is incomplete');
        }
        require_once $path;
    }
    \WPrism\Recovery\CheckpointRecoveryIntent::assertDatabaseTarget($root, $databaseTargetSha256);
});
PHP;

    /**
     * Compile into a target-visible artifact and decode the agent's JSON
     * response.  `wp wprism compile --format=json` is the sole source of truth
     * for whether code exists in this revision; do not inspect a mutable
     * checkout on the host after compilation.
     *
     * @return array{exit:int, stdout:string, stderr:string, summary:?array}
     */
    public static function compile(EnvironmentDriver $transport, string $repo, string $artifact): array {
        $result = $transport->captureWp(self::controlArgs([
            'wprism', 'compile', '--repo=' . $repo, '--out=' . $artifact, '--format=json',
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
            || ($summary['format'] ?? null) !== 'wprism-code-runtime/v1'
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
     * @param ?array<string,string> $authorizedSource internal externally
     *        authorized release repository/artifact binding
     * @return array<int,string>
     */
    public static function beginArgs(
        string $repo,
        string $owner,
        string $artifactHash,
        ?array $authorizedSource = null
    ): array {
        $arguments = [
            'wprism', 'promotion-begin', '--promotion-owner=' . $owner,
            '--artifact-hash=' . $artifactHash, '--repo=' . $repo,
        ];
        if ($authorizedSource !== null) {
            $authorizedRepo = is_string($authorizedSource['repo_path'] ?? null)
                ? rtrim($authorizedSource['repo_path'], '/')
                : '';
            if ($authorizedRepo === '' || !hash_equals(rtrim($repo, '/'), $authorizedRepo)) {
                throw new \InvalidArgumentException(
                    'authorized release repository does not match the promotion target repository'
                );
            }
            $arguments = array_merge($arguments, [
                '--release-operation-id=' . (string) ($authorizedSource['operation_id'] ?? ''),
                '--expected-source-commit=' . (string) ($authorizedSource['source_commit'] ?? ''),
                '--expected-source-tree=' . (string) ($authorizedSource['source_tree'] ?? ''),
            ]);
        }

        return self::controlArgs($arguments);
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
            'wprism', 'promotion-begin-scoped', '--promotion-owner=' . $owner,
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
            'wprism', 'promotion-complete-scoped', '--promotion-owner=' . $owner,
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
            'wprism', 'promotion-abort', '--promotion-owner=' . $owner,
            '--artifact-hash=' . $artifactHash,
        ]);
    }

    /**
     * The same two lease commands, asked in MACHINE mode, for `wprism recover`
     * only (issue #3506).
     *
     * `abortArgs()`/`beginArgs()` stay human deliberately: promote and deploy
     * run them as compensating cleanup and render the target's own stdout and
     * stderr straight back to the operator who is standing there
     * (cli/wprism:3094-3103), so a `--format=json` on those two would change
     * that output for every caller of those verbs.
     *
     * `RecoverCommand` has the opposite need. It drives the four ordered
     * steps unattended and reports one line per step, so with the agent in
     * human mode `promotion_abort()` falls through to
     * `WP_CLI::error($t->getMessage())` (agent/src/Command/Cli.php:645-657)
     * and the reason exists only as prose on a stream nothing reads. Asked in
     * JSON, the agent answers a refusal with `wprism-command-refusal/v1`
     * carrying a stable reason code — `promotion_abort_session_superseded`
     * for the case that produced this issue — which `RecoverCommand::step()`
     * surfaces. Same command, same identity, same order; only the reply
     * format differs.
     *
     * @return array<int,string>
     */
    public static function recoveryAbortArgs(
        string $owner,
        string $artifactHash,
        string $databaseTargetSha256
    ): array {
        if (preg_match('/^[a-f0-9]{64}$/D', $databaseTargetSha256) !== 1) {
            throw new \InvalidArgumentException('checkpoint database target identity is malformed');
        }
        return array_merge(self::abortArgs($owner, $artifactHash), [
            '--expected-database-target-sha256=' . $databaseTargetSha256,
            '--format=json',
        ]);
    }

    /** @return array<int,string> */
    public static function recoveryBeginArgs(
        string $repo,
        string $checkpoint,
        string $owner,
        string $artifactHash,
        string $cipherSha256,
        string $databaseTargetSha256
    ): array {
        if (preg_match('/^[a-f0-9]{64}$/D', $databaseTargetSha256) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $cipherSha256) !== 1) {
            throw new \InvalidArgumentException('checkpoint database target identity is malformed');
        }
        return array_merge(self::beginArgs($repo, $owner, $artifactHash), [
            '--checkpoint=' . $checkpoint,
            '--expected-cipher-sha256=' . $cipherSha256,
            '--expected-database-target-sha256=' . $databaseTargetSha256,
            '--format=json',
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

    /**
     * Publish cross-process provider debt after checkpoint/code staging and
     * before the first lifecycle or settlement callback. The recovery runtime
     * owns the file; the agent only reads it while executing an exact
     * authorized phase.
     *
     * @param list<string> $phases
     * @return array{exit:int,stdout:string,stderr:string}
     */
    public static function beginProviderSettlement(
        EnvironmentDriver $transport,
        string $repo,
        string $artifact,
        string $checkpoint,
        string $owner,
        string $artifactHash,
        array $phases
    ): array {
        self::assertProviderSettlementPhases($phases);
        $result = $transport->captureWp(self::providerSettlementBeginArgs(
            $repo,
            $artifact,
            $checkpoint,
            $owner,
            $artifactHash,
            $phases
        ));
        if ((int) ($result['exit'] ?? 1) !== 0) {
            return $result;
        }
        try {
            $summary = json_decode(
                trim((string) ($result['stdout'] ?? '')),
                true,
                16,
                JSON_THROW_ON_ERROR
            );
        } catch (\Throwable $_failure) {
            $summary = null;
        }
        $keys = is_array($summary) ? array_keys($summary) : [];
        sort($keys, SORT_STRING);
        if ($keys !== ['cipher_sha256', 'format', 'phases', 'resumed']
            || ($summary['format'] ?? null) !== 'wprism-provider-settlement-intent/v1'
            || ($summary['phases'] ?? null) !== $phases
            || !is_bool($summary['resumed'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($summary['cipher_sha256'] ?? '')) !== 1) {
            return [
                'exit' => 1,
                'stdout' => '',
                'stderr' => 'provider settlement authorization returned malformed checkpoint identity',
            ];
        }
        return $result;
    }

    /** @param list<string> $phases @return array<int,string> */
    public static function providerSettlementBeginArgs(
        string $repo,
        string $artifact,
        string $checkpoint,
        string $owner,
        string $artifactHash,
        array $phases
    ): array {
        self::assertProviderSettlementPhases($phases);
        $payload = base64_encode(json_encode([
            'artifact' => $artifact,
            'artifact_hash' => $artifactHash,
            'checkpoint' => $checkpoint,
            'owner' => $owner,
            'phases' => $phases,
            'repo' => $repo,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $program = <<<'PHP'
$wprismProviderPayload = json_decode(base64_decode('__PAYLOAD__', true), true, 16, JSON_THROW_ON_ERROR);
if (!is_array($wprismProviderPayload)
    || array_keys($wprismProviderPayload) !== ['artifact', 'artifact_hash', 'checkpoint', 'owner', 'phases', 'repo']) {
    throw new \RuntimeException('wprism: malformed provider settlement payload');
}
$summary = \WPrism\PromotionLock::with_existing_lease_fence(
    (string) $wprismProviderPayload['owner'],
    (string) $wprismProviderPayload['artifact_hash'],
    'provider-settlement-publish',
    static function () use ($wprismProviderPayload): array {
        $root = rtrim((string) $wprismProviderPayload['repo'], '/') . '/.wprism/control';
        $runtime = $root . '/recovery-runtime';
        /* controlArgs() has loaded agent/wprism.php, including the WPrism
           identity/cipher classes (:121-122). Their recovery-runtime copies
           are for agent-free rollback; loading both paths fatals on duplicate
           class declarations before the intent can be published. */
        foreach (['CanonicalJson.php', 'AtomicStore.php', 'ProtocolLock.php', 'ProviderSettlementIntent.php'] as $file) {
            $path = $runtime . '/' . $file;
            if (is_link($path) || !is_file($path)) {
                throw new \RuntimeException('wprism: durable provider settlement runtime is incomplete');
            }
            require_once $path;
        }
        $verification = \WPrism\RetainedCheckpointCipher::verify(
            (string) $wprismProviderPayload['repo'],
            (string) $wprismProviderPayload['checkpoint']
        );
        \WPrism\DatabaseTargetIdentity::assertWordPressConfig(
            (string) $verification['database_target_sha256']
        );
        return \WPrism\Recovery\ProviderSettlementIntent::begin(
            $root,
            (string) $wprismProviderPayload['repo'],
            (string) $wprismProviderPayload['artifact'],
            (string) $wprismProviderPayload['checkpoint'],
            (string) $verification['cipher_sha256'],
            (string) $wprismProviderPayload['owner'],
            (string) $wprismProviderPayload['artifact_hash'],
            (array) $wprismProviderPayload['phases']
        );
    }
);
echo \WPrism\Recovery\CanonicalJson::encode($summary);
PHP;
        $program = str_replace('__PAYLOAD__', $payload, $program);
        return self::controlArgs(['eval', trim(str_replace(["\r", "\n"], ' ', $program))]);
    }

    /** @param list<string> $phases @return array{exit:int,stdout:string,stderr:string} */
    public static function advanceProviderSettlement(
        EnvironmentDriver $transport,
        string $repo,
        string $artifact,
        string $checkpoint,
        string $owner,
        string $artifactHash,
        array $phases,
        string $phase
    ): array {
        self::assertProviderSettlementPhases($phases);
        if (!in_array($phase, $phases, true)) {
            throw new \InvalidArgumentException('provider settlement advance phase is not declared');
        }
        $root = rtrim($repo, '/') . '/.wprism/control';
        $runtime = $root . '/recovery-runtime/rollback-control.php';
        return $transport->captureRaw(
            'php ' . escapeshellarg($runtime)
            . ' provider-settlement-advance --root=' . escapeshellarg($root)
            . ' --repo=' . escapeshellarg($repo)
            . ' --artifact=' . escapeshellarg($artifact)
            . ' --checkpoint=' . escapeshellarg($checkpoint)
            . ' --owner=' . escapeshellarg($owner)
            . ' --artifact-hash=' . escapeshellarg($artifactHash)
            . ' --phases=' . escapeshellarg(implode(',', $phases))
            . ' --phase=' . escapeshellarg($phase)
        );
    }

    /** @param list<string> $phases @return array{exit:int,stdout:string,stderr:string} */
    public static function completeProviderSettlement(
        EnvironmentDriver $transport,
        string $repo,
        string $artifact,
        string $checkpoint,
        string $owner,
        string $artifactHash,
        array $phases
    ): array {
        self::assertProviderSettlementPhases($phases);
        $root = rtrim($repo, '/') . '/.wprism/control';
        $runtime = $root . '/recovery-runtime/rollback-control.php';
        return $transport->captureRaw(
            'php ' . escapeshellarg($runtime)
            . ' provider-settlement-complete --root=' . escapeshellarg($root)
            . ' --repo=' . escapeshellarg($repo)
            . ' --artifact=' . escapeshellarg($artifact)
            . ' --checkpoint=' . escapeshellarg($checkpoint)
            . ' --owner=' . escapeshellarg($owner)
            . ' --artifact-hash=' . escapeshellarg($artifactHash)
            . ' --phases=' . escapeshellarg(implode(',', $phases))
        );
    }

    /** @param list<string> $phases */
    private static function assertProviderSettlementPhases(array $phases): void {
        if (!in_array($phases, [
            ['schema-settle'],
            ['lifecycle-settle'],
            ['schema-settle', 'lifecycle-settle'],
            ['lifecycle-retire', 'lifecycle-activate', 'schema-settle'],
            ['lifecycle-retire', 'lifecycle-activate', 'lifecycle-settle'],
            ['lifecycle-retire', 'lifecycle-activate', 'schema-settle', 'lifecycle-settle'],
        ], true)) {
            throw new \InvalidArgumentException('provider settlement phases are malformed');
        }
    }

    /** @return array{database_target_sha256:string,format:string}|null */
    private static function databaseTargetResult(array $result): ?array {
        try {
            $summary = json_decode(
                trim((string) ($result['stdout'] ?? '')),
                true,
                8,
                JSON_THROW_ON_ERROR
            );
        } catch (\Throwable $_failure) {
            return null;
        }
        $keys = is_array($summary) ? array_keys($summary) : [];
        sort($keys, SORT_STRING);
        if ($keys !== ['database_target_sha256', 'format']
            || ($summary['format'] ?? null) !== 'wprism-database-target/v1'
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($summary['database_target_sha256'] ?? '')) !== 1) {
            return null;
        }
        return $summary;
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

        $target = $transport->captureWp(self::controlArgs([
            'wprism', 'checkpoint-target', '--format=json',
        ]));
        if ((int) ($target['exit'] ?? 1) !== 0) {
            return $target;
        }
        $identity = self::databaseTargetResult($target);
        if ($identity === null) {
            return [
                'exit' => 1,
                'stdout' => '',
                'stderr' => 'checkpoint target preflight returned malformed database identity',
            ];
        }
        $databaseTargetSha256 = $identity['database_target_sha256'];
        return $transport->captureWpPipeline(
            self::isolatedDatabaseArgs(['db', 'export', '-'], $repo, $databaseTargetSha256, false),
            self::controlArgs([
                'wprism', 'checkpoint-seal', '--repo=' . $repo, '--output=' . $checkpoint,
                '--database-target-sha256=' . $databaseTargetSha256,
            ])
        );
    }

    /**
     * Authenticate the checkpoint and compare its pre-mutation target before
     * recovery lease commands are allowed to write to the configured DB.
     *
     * @return array{exit:int,stdout:string,stderr:string,summary:?array}
     */
    public static function checkpointRecoveryPreflight(
        EnvironmentDriver $transport,
        string $repo,
        string $checkpoint
    ): array {
        $result = $transport->captureWp(self::checkpointRecoveryPreflightArgs($repo, $checkpoint));
        if ((int) ($result['exit'] ?? 1) !== 0) {
            return $result + ['summary' => null];
        }
        try {
            $summary = json_decode(
                trim((string) ($result['stdout'] ?? '')),
                true,
                8,
                JSON_THROW_ON_ERROR
            );
        } catch (\Throwable $_failure) {
            $summary = null;
        }
        $keys = is_array($summary) ? array_keys($summary) : [];
        sort($keys, SORT_STRING);
        if ($keys !== ['cipher_sha256', 'database_target_sha256', 'format']
            || ($summary['format'] ?? null) !== 'wprism-retained-checkpoint-verification/v2'
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($summary['cipher_sha256'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($summary['database_target_sha256'] ?? '')) !== 1) {
            return [
                'exit' => 1,
                'stdout' => '',
                'stderr' => 'checkpoint recovery preflight returned malformed target identity',
                'summary' => null,
            ];
        }
        return $result + ['summary' => $summary];
    }

    /** @return array<int,string> */
    public static function checkpointRecoveryPreflightArgs(string $repo, string $checkpoint): array {
        $payload = base64_encode(json_encode([
            'checkpoint' => $checkpoint,
            'repo' => $repo,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $bootstrap = <<<'PHP'
$wprismRecoveryPreflight = json_decode(base64_decode('__PAYLOAD__', true), true, 8, JSON_THROW_ON_ERROR);
if (!is_array($wprismRecoveryPreflight)
    || array_keys($wprismRecoveryPreflight) !== ['checkpoint', 'repo']) {
    throw new \RuntimeException('wprism: malformed checkpoint recovery preflight payload');
}
if (defined('WPMU_PLUGIN_DIR')) {
    throw new \RuntimeException('wprism: checkpoint recovery preflight started with WPMU_PLUGIN_DIR already defined');
}
\WP_CLI::add_hook('after_wp_config_load', static function () use ($wprismRecoveryPreflight): void {
    if (defined('SUNRISE') || defined('WPMU_PLUGIN_DIR')) {
        throw new \RuntimeException('wprism: checkpoint recovery preflight cannot isolate this wp-config.php');
    }
    if (!defined('DISABLE_WP_CRON')) {
        define('DISABLE_WP_CRON', true);
    }
    $base = defined('ABSPATH') ? rtrim((string) constant('ABSPATH'), '/\\') : (string) getcwd();
    define('WPMU_PLUGIN_DIR', $base . '/.wprism-recovery-mu-' . bin2hex(random_bytes(16)));
    $runtime = rtrim((string) $wprismRecoveryPreflight['repo'], '/')
        . '/.wprism/control/recovery-runtime';
    foreach (['DatabaseTargetIdentity.php', 'RetainedCheckpointCipher.php'] as $file) {
        $path = $runtime . '/' . $file;
        if (is_link($path) || !is_file($path)) {
            throw new \RuntimeException('wprism: durable checkpoint target runtime is incomplete');
        }
        require_once $path;
    }
    $verification = \WPrism\RetainedCheckpointCipher::verify(
        (string) $wprismRecoveryPreflight['repo'],
        (string) $wprismRecoveryPreflight['checkpoint']
    );
    \WPrism\DatabaseTargetIdentity::assertWordPressConfig(
        (string) $verification['database_target_sha256']
    );
    echo json_encode($verification, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit(0);
});
PHP;
        $bootstrap = str_replace('__PAYLOAD__', $payload, $bootstrap);
        return [
            '--exec=' . trim(str_replace(["\r", "\n"], ' ', $bootstrap)),
            '--skip-plugins',
            '--skip-themes',
            'eval',
            '0;',
        ];
    }

    /**
     * Authenticate the entire ciphertext before destructive recovery, reset
     * the database to an exact empty topology, then bind the streamed import
     * to those same ciphertext bytes. `db import` alone only creates/replaces
     * objects named by the dump and would retain tables created after export.
     *
     * @return array{exit:int,stdout:string,stderr:string}
     */
    public static function encryptedCheckpointImport(
        EnvironmentDriver $transport,
        string $repo,
        string $checkpoint,
        string $owner,
        string $artifactHash
    ): array {
        if (!is_callable([$transport, 'captureWpPipeline'])) {
            return [
                'exit' => 1,
                'stdout' => '',
                'stderr' => 'the target transport does not implement the authenticated checkpoint stream',
            ];
        }

        $verified = $transport->captureWp(self::checkpointRecoveryBeginArgs(
            $repo,
            $checkpoint,
            $owner,
            $artifactHash
        ));
        if ((int) ($verified['exit'] ?? 1) !== 0) {
            return $verified;
        }
        $verification = json_decode((string) ($verified['stdout'] ?? ''), true);
        $cipherSha256 = is_array($verification)
            ? (string) ($verification['cipher_sha256'] ?? '')
            : '';
        $databaseTargetSha256 = is_array($verification)
            ? (string) ($verification['database_target_sha256'] ?? '')
            : '';
        $verificationKeys = is_array($verification) ? array_keys($verification) : [];
        sort($verificationKeys, SORT_STRING);
        if ($verificationKeys !== [
            'cipher_sha256', 'database_target_sha256', 'format', 'provider_intent', 'resumed',
            'schema_intent',
        ]
            || ($verification['format'] ?? null) !== 'wprism-checkpoint-recovery-intent/v1'
            || !is_bool($verification['resumed'] ?? null)
            || !is_bool($verification['provider_intent'] ?? null)
            || !is_bool($verification['schema_intent'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $cipherSha256) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $databaseTargetSha256) !== 1) {
            return [
                'exit' => 1,
                'stdout' => '',
                'stderr' => 'checkpoint verification returned malformed ciphertext identity',
            ];
        }

        $reset = $transport->captureWp(self::isolatedDatabaseArgs(
            ['db', 'reset', '--yes'],
            $repo,
            $databaseTargetSha256
        ));
        if ((int) ($reset['exit'] ?? 1) !== 0) {
            return $reset;
        }

        return $transport->captureWpPipeline(
            self::checkpointOpenBeforeLoadArgs(
                $repo,
                $checkpoint,
                $cipherSha256,
                $databaseTargetSha256
            ),
            self::isolatedDatabaseArgs(['db', 'import', '-'], $repo, $databaseTargetSha256)
        );
    }

    /**
     * Authenticate through the adoption-stable recovery runtime, then publish
     * or resume the database-external intent in the same isolated process.
     *
     * @return array<int,string>
     */
    public static function checkpointRecoveryBeginArgs(
        string $repo,
        string $checkpoint,
        string $owner,
        string $artifactHash
    ): array {
        $payload = base64_encode(json_encode([
            'artifact_hash' => $artifactHash,
            'checkpoint' => $checkpoint,
            'owner' => $owner,
            'repo' => $repo,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $bootstrap = <<<'PHP'
$wprismRecoveryPayload = json_decode(base64_decode('__PAYLOAD__', true), true, 8, JSON_THROW_ON_ERROR);
if (!is_array($wprismRecoveryPayload)
    || array_keys($wprismRecoveryPayload) !== ['artifact_hash', 'checkpoint', 'owner', 'repo']) {
    throw new \RuntimeException('wprism: malformed checkpoint recovery payload');
}
if (defined('WPMU_PLUGIN_DIR')) {
    throw new \RuntimeException('wprism: checkpoint recovery bootstrap started with WPMU_PLUGIN_DIR already defined');
}
\WP_CLI::add_hook('after_wp_config_load', static function () use ($wprismRecoveryPayload): void {
    if (defined('SUNRISE') || defined('WPMU_PLUGIN_DIR')) {
        throw new \RuntimeException('wprism: checkpoint recovery cannot isolate this wp-config.php');
    }
    if (!defined('DISABLE_WP_CRON')) {
        define('DISABLE_WP_CRON', true);
    }
    $base = defined('ABSPATH') ? rtrim((string) constant('ABSPATH'), '/\\') : (string) getcwd();
    define('WPMU_PLUGIN_DIR', $base . '/.wprism-recovery-mu-' . bin2hex(random_bytes(16)));
    $root = rtrim((string) $wprismRecoveryPayload['repo'], '/') . '/.wprism/control';
    $runtime = $root . '/recovery-runtime';
    foreach (['CanonicalJson.php', 'AtomicStore.php', 'ProtocolLock.php', 'DatabaseTargetIdentity.php', 'CheckpointRecoveryIntent.php', 'RetainedCheckpointCipher.php'] as $file) {
        $path = $runtime . '/' . $file;
        if (is_link($path) || !is_file($path)) {
            throw new \RuntimeException('wprism: durable checkpoint recovery runtime is incomplete');
        }
        require_once $path;
    }
    $verification = \WPrism\RetainedCheckpointCipher::verify(
        (string) $wprismRecoveryPayload['repo'],
        (string) $wprismRecoveryPayload['checkpoint']
    );
    \WPrism\DatabaseTargetIdentity::assertWordPressConfig(
        (string) $verification['database_target_sha256']
    );
    $databaseTargetSha256 = (string) $verification['database_target_sha256'];
    $summary = \WPrism\Recovery\CheckpointRecoveryIntent::resume(
        $root,
        (string) $wprismRecoveryPayload['repo'],
        (string) $wprismRecoveryPayload['checkpoint'],
        (string) $verification['cipher_sha256'],
        (string) $wprismRecoveryPayload['owner'],
        (string) $wprismRecoveryPayload['artifact_hash'],
        $databaseTargetSha256
    );
    if ($summary !== null) {
        echo \WPrism\Recovery\CanonicalJson::encode($summary);
        exit(0);
    }
    $GLOBALS['wprism_checkpoint_recovery_payload'] = $wprismRecoveryPayload;
    $GLOBALS['wprism_checkpoint_recovery_verification'] = $verification;
    $GLOBALS['wprism_checkpoint_recovery_database_target_sha256'] = $databaseTargetSha256;
    $GLOBALS['wprism_checkpoint_recovery_summary'] = $summary;
});
PHP;
        $bootstrap = str_replace('__PAYLOAD__', $payload, $bootstrap);
        $bootstrap = trim(str_replace(["\r", "\n"], ' ', $bootstrap));
        $evaluate = <<<'PHP'
$summary = $GLOBALS['wprism_checkpoint_recovery_summary'] ?? null;
if ($summary === null) {
    $payload = $GLOBALS['wprism_checkpoint_recovery_payload'] ?? null;
    $verification = $GLOBALS['wprism_checkpoint_recovery_verification'] ?? null;
    $databaseTargetSha256 = $GLOBALS['wprism_checkpoint_recovery_database_target_sha256'] ?? null;
    if (!is_array($payload) || !is_array($verification) || !is_string($databaseTargetSha256)) {
        throw new \RuntimeException('wprism: checkpoint recovery bootstrap evidence is absent');
    }
    if (!function_exists('is_multisite') || is_multisite()) {
        throw new \RuntimeException(
            'wprism: checkpoint recovery requires a readable single-site topology before database reset'
        );
    }
    global $wpdb;
    $table = (string) $wpdb->prefix . 'wprism_kv';
    $wpdb->last_error = '';
    $present = $wpdb->get_var($wpdb->prepare(
        'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
        $table
    ));
    if ((string) ($wpdb->last_error ?? '') !== '') {
        throw new \RuntimeException('wprism: checkpoint recovery could not inspect the schema intent table');
    }
    $schemaIntent = null;
    if ($present === $table) {
        $wpdb->last_error = '';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT v FROM $table WHERE k = %s",
            'schema_settlement_in_progress'
        ), ARRAY_A);
        if (($row !== null && !is_array($row)) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException('wprism: checkpoint recovery could not read the schema intent');
        }
        $schemaIntent = is_array($row) ? (string) ($row['v'] ?? '') : null;
    }
    $root = rtrim((string) $payload['repo'], '/') . '/.wprism/control';
    $summary = \WPrism\Recovery\CheckpointRecoveryIntent::begin(
        $root,
        (string) $payload['repo'],
        (string) $payload['checkpoint'],
        (string) $verification['cipher_sha256'],
        (string) $payload['owner'],
        (string) $payload['artifact_hash'],
        $databaseTargetSha256,
        'single-site',
        $schemaIntent
    );
}
echo \WPrism\Recovery\CanonicalJson::encode($summary);
PHP;
        return [
            '--exec=' . $bootstrap,
            '--skip-plugins',
            '--skip-themes',
            'eval',
            trim(str_replace(["\r", "\n"], ' ', $evaluate)),
        ];
    }

    /**
     * Decrypt after wp-config has supplied target salts but before WordPress
     * touches the database. Recovery has just reset that database, so an
     * ordinary custom command cannot be registered or dispatched reliably.
     *
     * @return array<int,string>
     */
    public static function checkpointOpenBeforeLoadArgs(
        string $repo,
        string $checkpoint,
        string $cipherSha256,
        string $databaseTargetSha256
    ): array {
        if (preg_match('/^[a-f0-9]{64}$/D', $cipherSha256) !== 1) {
            throw new \InvalidArgumentException('checkpoint ciphertext identity is malformed');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $databaseTargetSha256) !== 1) {
            throw new \InvalidArgumentException('checkpoint database target identity is malformed');
        }
        $payload = base64_encode(json_encode(
            [
                'checkpoint' => $checkpoint,
                'cipher_sha256' => $cipherSha256,
                'database_target_sha256' => $databaseTargetSha256,
                'repo' => $repo,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        ));
        $bootstrap = <<<'PHP'
$wprismOpenPayload = json_decode(base64_decode('__PAYLOAD__', true), true, 8, JSON_THROW_ON_ERROR);
if (!is_array($wprismOpenPayload)
    || array_keys($wprismOpenPayload) !== ['checkpoint', 'cipher_sha256', 'database_target_sha256', 'repo']) {
    throw new \RuntimeException('wprism: malformed early checkpoint-open payload');
}
\WP_CLI::add_hook('after_wp_config_load', static function () use ($wprismOpenPayload): void {
    $wprismCipher = rtrim((string) $wprismOpenPayload['repo'], '/')
        . '/.wprism/control/recovery-runtime/RetainedCheckpointCipher.php';
    if (is_link($wprismCipher) || !is_file($wprismCipher)) {
        throw new \RuntimeException('wprism: early checkpoint-open could not find the durable cipher runtime');
    }
    require_once $wprismCipher;
    \WPrism\RetainedCheckpointCipher::open(
        (string) $wprismOpenPayload['repo'],
        (string) $wprismOpenPayload['checkpoint'],
        null,
        (string) $wprismOpenPayload['cipher_sha256'],
        (string) $wprismOpenPayload['database_target_sha256']
    );
    exit(0);
});
PHP;
        $bootstrap = str_replace('__PAYLOAD__', $payload, $bootstrap);
        $bootstrap = trim(str_replace(["\r", "\n"], ' ', $bootstrap));
        return [
            '--exec=' . $bootstrap,
            '--skip-plugins',
            '--skip-themes',
            'eval',
            '0;',
        ];
    }

    /** @return array<int,string> */
    private static function isolatedDatabaseArgs(
        array $command,
        string $repo,
        string $databaseTargetSha256,
        bool $requireRecoveryIntent = true
    ): array {
        if (preg_match('/^[a-f0-9]{64}$/D', $databaseTargetSha256) !== 1) {
            throw new \InvalidArgumentException('checkpoint database target identity is malformed');
        }
        $payload = base64_encode(json_encode([
            'database_target_sha256' => $databaseTargetSha256,
            'repo' => $repo,
            'require_recovery_intent' => $requireRecoveryIntent,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $bootstrap = str_replace('__PAYLOAD__', $payload, self::ISOLATED_DATABASE_BOOTSTRAP);
        $bootstrap = trim(str_replace(["\r", "\n"], ' ', $bootstrap));
        return array_merge([
            '--exec=' . $bootstrap,
            '--skip-plugins',
            '--skip-themes',
        ], $command);
    }

    /** Complete recovery debt only after import and final abort both succeeded. */
    public static function completeCheckpointRecovery(
        EnvironmentDriver $transport,
        string $repo,
        string $checkpoint,
        string $owner,
        string $artifactHash
    ): array {
        $root = rtrim($repo, '/') . '/.wprism/control';
        $runtime = $root . '/recovery-runtime/rollback-control.php';
        return $transport->captureRaw(
            'php ' . escapeshellarg($runtime)
            . ' checkpoint-recovery-complete --root=' . escapeshellarg($root)
            . ' --repo=' . escapeshellarg($repo)
            . ' --checkpoint=' . escapeshellarg($checkpoint)
            . ' --owner=' . escapeshellarg($owner)
            . ' --artifact-hash=' . escapeshellarg($artifactHash)
        );
    }

    /**
     * Version-independent host fence: even a code-first-restored old agent is
     * not asked whether database-external recovery debt exists.
     *
     * @return array{exit:int,stdout:string,stderr:string}
     */
    public static function checkpointRecoveryFence(EnvironmentDriver $transport, string $repo): array {
        $program = <<<'PHP'
$repo = realpath($argv[1]);
if ($repo === false || !is_dir($repo)) {
    fwrite(STDERR, 'checkpoint recovery repository is unreadable');
    exit(76);
}
$state = $repo . '/.wprism';
$control = $state . '/control';
foreach ([$state, $control] as $directory) {
    $stat = @lstat($directory);
    if ($stat === false) {
        echo 'clear';
        exit(0);
    }
    if (($stat['mode'] & 0170000) !== 0040000 || is_link($directory) || realpath($directory) !== $directory) {
        fwrite(STDERR, 'checkpoint recovery control boundary is unsafe');
        exit(76);
    }
}
$intent = $control . '/checkpoint-recovery-intent.json';
if (@lstat($intent) === false) {
    echo 'clear';
    exit(0);
}
fwrite(STDERR, 'incomplete checkpoint recovery is active');
exit(75);
PHP;
        return $transport->captureRaw(
            'php -r ' . escapeshellarg(trim($program)) . ' ' . escapeshellarg($repo)
        );
    }

    /**
     * Fence every host mutation while either database-external debt exists.
     *
     * `state` is derived from the exact closed exit/output tuple rather than
     * prose matching in cli/wprism. Any transport truncation, extra output, or
     * unknown exit is unsafe; callers never reinterpret it as clear.
     *
     * @return array{exit:int,stdout:string,stderr:string,state:string}
     */
    public static function externalRecoveryFence(EnvironmentDriver $transport, string $repo): array {
        $program = <<<'PHP'
$repo = realpath($argv[1]);
if ($repo === false || !is_dir($repo)) {
    fwrite(STDERR, 'external recovery repository is unreadable');
    exit(76);
}
$state = $repo . '/.wprism';
$control = $state . '/control';
foreach ([$state, $control] as $directory) {
    $stat = @lstat($directory);
    if ($stat === false) {
        echo 'clear';
        exit(0);
    }
    if (($stat['mode'] & 0170000) !== 0040000 || is_link($directory) || realpath($directory) !== $directory) {
        fwrite(STDERR, 'external recovery control boundary is unsafe');
        exit(76);
    }
}
foreach ([
    'checkpoint-recovery-intent.json' => 'incomplete checkpoint recovery is active',
    'provider-settlement-intent.json' => 'incomplete provider settlement is active',
] as $file => $message) {
    $path = $control . '/' . $file;
    $stat = @lstat($path);
    if ($stat === false) {
        continue;
    }
    if (($stat['mode'] & 0170000) !== 0100000 || is_link($path)) {
        fwrite(STDERR, 'external recovery intent boundary is unsafe');
        exit(76);
    }
    fwrite(STDERR, $message);
    exit(75);
}
echo 'clear';
exit(0);
PHP;
        $result = $transport->captureRaw(
            'php -r ' . escapeshellarg(trim($program)) . ' ' . escapeshellarg($repo)
        );
        $exit = (int) ($result['exit'] ?? 1);
        $stdout = (string) ($result['stdout'] ?? '');
        $stderr = (string) ($result['stderr'] ?? '');
        $state = match (true) {
            $exit === 0 && $stdout === 'clear' && $stderr === '' => 'clear',
            $exit === 75 && $stdout === ''
                && $stderr === 'incomplete checkpoint recovery is active' => 'checkpoint_recovery',
            $exit === 75 && $stdout === ''
                && $stderr === 'incomplete provider settlement is active' => 'provider_settlement',
            default => 'unsafe',
        };
        return array_merge($result, ['state' => $state]);
    }

    /**
     * @return array{exit:int,stdout:string,stderr:string,summary:?array}
     */
    public static function providerSettlementRecoveryStatus(
        EnvironmentDriver $transport,
        string $repo
    ): array {
        $root = rtrim($repo, '/') . '/.wprism/control';
        $runtime = $root . '/recovery-runtime/rollback-control.php';
        $result = $transport->captureRaw(
            'php ' . escapeshellarg($runtime)
            . ' provider-settlement-recovery-status --root=' . escapeshellarg($root)
        );
        if ((int) ($result['exit'] ?? 1) !== 0) {
            return $result + ['summary' => null];
        }
        try {
            $summary = json_decode(
                trim((string) ($result['stdout'] ?? '')),
                true,
                16,
                JSON_THROW_ON_ERROR
            );
        } catch (\Throwable $_failure) {
            $summary = null;
        }
        $keys = is_array($summary) ? array_keys($summary) : [];
        sort($keys, SORT_STRING);
        $checkpoint = is_array($summary) ? ($summary['checkpoint'] ?? null) : null;
        $checkpointKeys = is_array($checkpoint) ? array_keys($checkpoint) : [];
        sort($checkpointKeys, SORT_STRING);
        $active = is_array($summary) ? ($summary['active'] ?? null) : null;
        $validInactive = $active === false
            && array_key_exists('artifact_hash', $summary)
            && $summary['artifact_hash'] === null
            && $checkpoint === null
            && array_key_exists('owner', $summary)
            && $summary['owner'] === null;
        $validActive = $active === true
            && preg_match('/^[a-f0-9]{64}$/D', (string) ($summary['artifact_hash'] ?? '')) === 1
            && is_string($summary['owner'] ?? null)
            && $summary['owner'] !== ''
            && $checkpointKeys === ['cipher_sha256', 'path']
            && preg_match('/^[a-f0-9]{64}$/D', (string) ($checkpoint['cipher_sha256'] ?? '')) === 1
            && is_string($checkpoint['path'] ?? null)
            && dirname((string) $checkpoint['path']) === rtrim($repo, '/') . '/.wprism/checkpoints';
        if ($keys !== ['active', 'artifact_hash', 'checkpoint', 'format', 'owner']
            || ($summary['format'] ?? null) !== 'wprism-provider-settlement-recovery/v1'
            || (!$validInactive && !$validActive)) {
            return [
                'exit' => 1,
                'stdout' => '',
                'stderr' => 'provider settlement recovery status is malformed',
                'summary' => null,
            ];
        }
        return $result + ['summary' => $summary];
    }

    /** @return array<int,string> */
    public static function stageArgs(string $repo, string $artifact, string $owner, string $artifactHash): array {
        return self::controlArgs([
            'wprism', 'code-stage', '--repo=' . $repo, '--compiled=' . $artifact,
            '--promotion-owner=' . $owner, '--artifact-hash=' . $artifactHash,
        ]);
    }

    /** @return array<int,string> */
    public static function preflightArgs(string $repo, string $artifact, string $artifactHash): array {
        return self::controlArgs([
            'wprism', 'code-preflight', '--repo=' . $repo, '--compiled=' . $artifact,
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
            'wprism', 'code-finalize', '--repo=' . $repo, '--compiled=' . $artifact,
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
        string $owner,
        string $checkpoint = '',
        bool $releaseOnSuccess = false
    ): array {
        // Deliberately not controlArgs(): settlement runs the newly activated
        // plugin and its native queue provider in a fresh ordinary process.
        $args = [
            'wprism', 'lifecycle-settle', '--repo=' . $repo,
            '--compiled=' . $artifact, '--artifact-hash=' . $artifactHash,
            '--promotion-owner=' . $owner,
        ];
        if ($checkpoint !== '') {
            $args[] = '--checkpoint=' . $checkpoint;
        }
        if ($releaseOnSuccess) {
            $args[] = '--release-on-success';
        }
        return $args;
    }

    /** @return array<int,string> */
    public static function lifecycleStatusArgs(
        string $repo,
        string $artifact,
        string $artifactHash,
        array $extra = []
    ): array {
        $args = [
            'wprism', 'lifecycle-status', '--repo=' . $repo,
            '--compiled=' . $artifact, '--artifact-hash=' . $artifactHash,
            '--format=json',
        ];
        if (in_array('--force-code-mismatch', $extra, true)) {
            $args[] = '--force-code-mismatch';
        }
        return self::controlArgs($args);
    }

    /**
     * @param array{exit:int,stdout:string,stderr:string} $result
     * @return array{format:string,required:bool,reasons:list<string>}
     */
    public static function lifecycleStatusResult(array $result): array {
        if ((int) ($result['exit'] ?? 1) !== 0) {
            throw new \RuntimeException('target lifecycle preflight failed');
        }
        try {
            $status = json_decode(trim((string) ($result['stdout'] ?? '')), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('target returned malformed lifecycle preflight evidence', 0, $failure);
        }
        $keys = is_array($status) ? array_keys($status) : [];
        sort($keys, SORT_STRING);
        $reasons = is_array($status) ? ($status['reasons'] ?? null) : null;
        $allowed = [
            'active_plugin_order_mismatch',
            'inactive_in_environment',
            'template_mismatch',
            'unexpected_active_plugin',
        ];
        $validReasons = is_array($reasons) && array_is_list($reasons);
        $seen = [];
        if ($validReasons) {
            foreach ($reasons as $reason) {
                if (!is_string($reason)
                    || !in_array($reason, $allowed, true)
                    || isset($seen[$reason])) {
                    $validReasons = false;
                    break;
                }
                $seen[$reason] = true;
            }
        }
        if ($keys !== ['format', 'reasons', 'required']
            || ($status['format'] ?? null) !== 'wprism-lifecycle-status/v1'
            || !is_bool($status['required'] ?? null)
            || !$validReasons
            || ($status['required'] !== ($reasons !== []))) {
            throw new \RuntimeException('target returned malformed lifecycle preflight evidence');
        }
        $sorted = $reasons;
        sort($sorted, SORT_STRING);
        if ($reasons !== $sorted) {
            throw new \RuntimeException('target returned malformed lifecycle preflight evidence');
        }
        return $status;
    }

    /** A compiled schema-phase effect is the immutable host selection witness. */
    public static function schemaSettlementRequired(array $compileSummary): bool {
        foreach ((array) ($compileSummary['effects_inventory'] ?? []) as $row) {
            if (is_array($row) && ($row['phase'] ?? null) === 'schema-settle') {
                return true;
            }
        }
        return false;
    }

    /** A compiled lifecycle-phase effect is the immutable host selection witness. */
    public static function lifecycleSettlementDeclared(array $compileSummary): bool {
        foreach ((array) ($compileSummary['effects_inventory'] ?? []) as $row) {
            if (is_array($row) && ($row['phase'] ?? null) === 'lifecycle-settle') {
                return true;
            }
        }
        return false;
    }

    /** @return array<int,string> */
    public static function schemaStatusArgs(
        string $repo,
        string $artifact,
        string $artifactHash,
        bool $presenceOnly = false
    ): array {
        $args = [
            'wprism', 'schema-status', '--repo=' . $repo,
            '--compiled=' . $artifact, '--artifact-hash=' . $artifactHash,
            '--format=json',
        ];
        if ($presenceOnly) {
            $args[] = '--presence-only';
            return self::controlArgs($args);
        }
        return $args;
    }

    /**
     * Validate the target's read-only schema readiness response.
     *
     * @param array{exit:int,stdout:string,stderr:string} $result
     * @return array{format:string,declared:bool,required:bool,state:string,tables:list<array{table:string,present:bool}>}
     */
    public static function schemaStatusResult(array $result): array {
        if ((int) ($result['exit'] ?? 1) !== 0) {
            throw new \RuntimeException('target schema readiness preflight failed');
        }
        try {
            $status = json_decode(trim((string) ($result['stdout'] ?? '')), true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('target returned malformed schema readiness evidence', 0, $failure);
        }
        $keys = is_array($status) ? array_keys($status) : [];
        sort($keys, SORT_STRING);
        if ($keys !== ['declared', 'format', 'mode', 'required', 'state', 'tables']
            || ($status['format'] ?? null) !== 'wprism-schema-settlement-status/v1'
            || !is_bool($status['declared'] ?? null)
            || !in_array($status['mode'] ?? null, ['exact', 'presence'], true)
            || !is_bool($status['required'] ?? null)
            || !in_array($status['state'] ?? null, ['none', 'present', 'ready', 'required'], true)
            || !is_array($status['tables'] ?? null)
            || !array_is_list($status['tables'])) {
            throw new \RuntimeException('target returned malformed schema readiness evidence');
        }
        $seen = [];
        foreach ($status['tables'] as $row) {
            $rowKeys = is_array($row) ? array_keys($row) : [];
            sort($rowKeys, SORT_STRING);
            $table = is_array($row) ? ($row['table'] ?? null) : null;
            if ($rowKeys !== ['present', 'table']
                || !is_string($table)
                || preg_match('/^[a-z0-9][a-z0-9_]{0,63}$/D', $table) !== 1
                || !is_bool($row['present'] ?? null)
                || isset($seen[$table])) {
                throw new \RuntimeException('target returned malformed schema readiness evidence');
            }
            $seen[$table] = true;
        }
        $tables = array_keys($seen);
        $sorted = $tables;
        sort($sorted, SORT_STRING);
        if ($tables !== $sorted
            || (!$status['declared'] && ($status['required'] || $status['state'] !== 'none' || $tables !== []))
            || ($status['declared'] && $status['required'] !== ($status['state'] === 'required'))
            || ($status['declared'] && !$status['required']
                && $status['state'] !== ($status['mode'] === 'exact' ? 'ready' : 'present'))
            || $status['required'] !== in_array(false, array_column($status['tables'], 'present'), true)) {
            throw new \RuntimeException('target returned inconsistent schema readiness evidence');
        }
        return $status;
    }

    /** @return array<int,string> */
    public static function schemaSettleArgs(
        string $repo,
        string $artifact,
        string $artifactHash,
        string $owner,
        string $checkpoint,
        bool $afterCodeTransition,
        bool $releaseOnSuccess = false
    ): array {
        // Deliberately not controlArgs(): the provider belongs to the active
        // plugin and must execute in a fresh ordinary WordPress process.
        $args = [
            'wprism', 'schema-settle', '--repo=' . $repo,
            '--compiled=' . $artifact, '--artifact-hash=' . $artifactHash,
            '--promotion-owner=' . $owner,
            '--checkpoint=' . $checkpoint,
        ];
        if ($afterCodeTransition) {
            $args[] = '--after-code-transition';
        }
        if ($releaseOnSuccess) {
            $args[] = '--release-on-success';
        }
        return $args;
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
        array $extra = [],
        string $checkpoint = ''
    ): array {
        if (!in_array($lifecyclePhase, ['retire', 'activate'], true)) {
            throw new \InvalidArgumentException("unsupported lifecycle phase '$lifecyclePhase'");
        }
        $args = [
            'wprism', 'deploy', '--repo=' . $repo, '--compiled=' . $artifact,
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
        if ($checkpoint !== '') {
            $args[] = '--checkpoint=' . $checkpoint;
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

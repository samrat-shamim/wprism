<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Kernel/OptionState.php';
require_once __DIR__ . '/../Policy/ScopeClosure.php';
require_once __DIR__ . '/../Scope/ScopedApply.php';
require_once __DIR__ . '/../Scope/ScopedApplySession.php';

/**
 * Read-only post-apply convergence verification (DUO-3220): re-captures the
 * target through the same canonical reader used by plan/capture and proves
 * every entity in the immutable compiled tree landed byte-semantically (same
 * type + canonical hash), or — for a scoped run — that every selected
 * identity matches while every protected out-of-scope row and ledger-map
 * entry retained its pre-mutation root. Absence of a thrown mutation/rebuild
 * error is never the proof; only an explicit 'pass' report from a fresh
 * re-read is. Never writes.
 *
 * Extracted from Apply (DUO-3347 slice 1): the constructor's five fields are
 * exactly what verify_canonical() and Apply::run()'s post-apply gate ever
 * read from an Apply instance to reach this cluster — verify_canonical()
 * previously built a full `new self(...)` Apply just to reach two of these
 * methods, touching none of Apply's other state.
 */
final class ConvergenceVerifier {
    private string $repo;
    private Policy $policy;
    private ?array $scopeContract;
    private ?array $scopedObservation;
    private ?ScopedApplySession $scopedSession;

    public function __construct(
        string $repo,
        Policy $policy,
        ?array $scopeContract = null,
        ?array $scopedObservation = null,
        ?ScopedApplySession $scopedSession = null
    ) {
        $this->repo = $repo;
        $this->policy = $policy;
        $this->scopeContract = $scopeContract;
        $this->scopedObservation = $scopedObservation;
        $this->scopedSession = $scopedSession;
    }

    /** @param array<string,mixed> $entity */
    public static function hash(array $entity): string {
        if (($entity['type'] ?? '') === 'post') {
            return (string) $entity['hash'];
        }
        return hash(
            'sha256',
            Canon::encode((array) $entity['data'])
        );
    }

    /**
     * Mandatory post-apply canonical convergence gate (DUO-3220).
     *
     * This is intentionally the cheap, engine-owned verifier from the
     * Architecture Ruling for this slice. Adapter-declared behavioural /
     * render probes and signed verification reports belong to DUO-3223;
     * required derived dependencies are already hard-verified in rebuild().
     *
     * @return array{verifier:string,result:string,live_entities:int,deletions:int}
     */
    public function verify(array $opts, CompiledRepository $compiled, array $preservedDrift = []): array {
        if ($this->scopeContract !== null) {
            return $this->verify_scoped($opts, $compiled);
        }
        if (!class_exists('\WP_CLI')) {
            throw $this->failure(
                'duo: post-apply convergence verification is unavailable outside wp-cli; promotion metadata was not committed',
                $preservedDrift
            );
        }

        // Do not recapture inside this mutating process. Adapter runtimes
        // were initialized against the pre-apply database and may persist
        // stale in-memory models from a shutdown callback if a post-apply
        // read refreshes only part of that model (Polylang 3.8.6 is the
        // reproduced case). A launched command boots from the committed
        // authored state, so its snapshot is both independent and unable to
        // contaminate this process's shutdown state. Pin it to the exact
        // artifact used above; a concurrently changed repository fails
        // closed instead of verifying a different desired revision.
        $artifactSnapshot = tempnam(sys_get_temp_dir(), 'duo-verify-artifact-');
        $policySnapshot = tempnam(sys_get_temp_dir(), 'duo-verify-policy-');
        if ($artifactSnapshot === false || $policySnapshot === false) {
            if (is_string($artifactSnapshot)) { @unlink($artifactSnapshot); }
            if (is_string($policySnapshot)) { @unlink($policySnapshot); }
            throw new \RuntimeException('duo: could not allocate frozen canonical-verification inputs');
        }
        @chmod($artifactSnapshot, 0600);
        @chmod($policySnapshot, 0600);
        $cmd = 'duo verify-canonical --repo=' . escapeshellarg($this->repo)
            . ' --expected-artifact=' . $compiled->artifact_hash()
            . ' --compiled=' . escapeshellarg($artifactSnapshot)
            . ' --policy-snapshot=' . escapeshellarg($policySnapshot)
            . ' --format=json';
        if (!empty($opts['with_deletes'])) {
            $cmd .= ' --with-deletes';
        }
        if (!empty($opts['force_unresolved_refs'])) {
            $cmd .= ' --force-unresolved-refs';
        }

        try {
            $compiled->write($artifactSnapshot);
            Canon::write_file($policySnapshot, Canon::encode($this->policy->export_snapshot()));
            $res = \WP_CLI::runcommand($cmd, [
                'launch' => true,
                'return' => 'all',
                'exit_error' => false,
            ]);
        } catch (\Throwable $t) {
            throw $this->failure(
                'duo: post-apply convergence verification subprocess failed; promotion metadata was not committed',
                $preservedDrift,
                $t
            );
        } finally {
            @unlink($artifactSnapshot);
            @unlink($policySnapshot);
        }
        if ((int) $res->return_code !== 0) {
            throw $this->failure(
                self::subprocess_diagnosis(
                    (string) ($res->stderr ?? ''),
                    (string) ($res->stdout ?? '')
                ),
                $preservedDrift
            );
        }
        $lines = preg_split('/\R/', trim((string) ($res->stdout ?? ''))) ?: [];
        $json = (string) end($lines);
        try {
            $report = Canon::decode($json);
        } catch (\Throwable $t) {
            throw $this->failure(
                'duo: post-apply convergence verification returned malformed evidence; promotion metadata was not committed',
                $preservedDrift,
                $t
            );
        }
        if (!is_array($report)
            || ($report['verifier'] ?? '') !== 'canonical-recapture/v1'
            || ($report['result'] ?? '') !== 'pass') {
            throw $this->failure(
                'duo: post-apply convergence verification returned invalid evidence; promotion metadata was not committed',
                $preservedDrift
            );
        }
        return $report;
    }

    /**
     * What the verifier subprocess actually said, in the order the channels
     * are trustworthy.
     *
     * DUO-3489 root cause: DUO-3399 (aa58959) routed `verify-canonical`
     * through Cli::halt_json_failure(), and this class always launches it with
     * `--format=json` (:97-107). That path prints the refusal envelope with
     * WP_CLI::line() and WP_CLI::halt(1) (Cli.php:150-151), so STDERR — the
     * only channel the caller below reads — was empty and every convergence
     * failure collapsed into the constant "subprocess failed" sentence. That
     * was measured live: `duo apply prod` on a 2-entity-drifted target
     * refused with exactly that sentence and named nothing, while
     * spec/repo-format.md:1235 requires "a mismatch names the failed
     * invariant". Cli::verify_canonical() now writes the operator sentence to
     * STDERR before halting, so the first branch is the live one again; the
     * envelope branch is what keeps a nameless refusal from ever being the
     * answer if some other path halts without prose.
     */
    private static function subprocess_diagnosis(string $stderr, string $stdout): string {
        $detail = trim($stderr);
        if (str_starts_with($detail, 'Error: ')) {
            $detail = substr($detail, strlen('Error: '));
        }
        if ($detail !== '') {
            return $detail;
        }
        $lines = preg_split('/\R/', trim($stdout)) ?: [];
        try {
            $envelope = Canon::decode((string) end($lines));
        } catch (\Throwable $undecodable) {
            $envelope = null;
        }
        if (!is_array($envelope) || ($envelope['format'] ?? '') !== 'duo-command-refusal/v1') {
            return 'duo: post-apply convergence verification subprocess failed with no diagnosis on either channel; '
                . 'promotion metadata was not committed';
        }
        $reason = (string) ($envelope['reason_code'] ?? $envelope['error'] ?? 'unclassified');
        $message = (string) ($envelope['message'] ?? '');
        $remediation = (string) ($envelope['remediation'] ?? '');
        $lines = [
            'duo: post-apply convergence verification refused (' . $reason
                . '); promotion metadata was not committed',
        ];
        if ($message !== '') {
            $lines[] = '  ' . $message;
        }
        if ($remediation !== '') {
            $lines[] = '  remedy: ' . $remediation;
        }
        if (($envelope['details_redacted'] ?? false) === true) {
            $lines[] = '  the subprocess redacted its detail; the full chain is in '
                . '<repo>/.duo/refusals/ on this environment';
        }
        return implode("\n", $lines);
    }

    /**
     * Every non-scoped convergence failure, with the two facts the operator
     * asked for and DUO-3489 found missing.
     *
     * This gate has exactly one caller — ApplyRequestCoordinator::run():1496,
     * after the authored transaction committed and the rebuild pass ran — so
     * "the target was mutated" is a fact about this refusal, not a guess.
     * Preserved drift is the structural cause when it is present: verify_local()
     * proves the WHOLE compiled tree, and ApplyPlanner::rebuild_work():699-706 deliberately
     * excludes `drift` from the write set, so a drifted target can never pass
     * this gate. Naming it turns a mysterious subprocess exit into the
     * documented capture-first remedy (docs/guides/capabilities-and-limits.md
     * "ordinary `drift` … `duo capture` first").
     *
     * @param list<array<string,mixed>> $preservedDrift plan `drift` rows this run did not write
     */
    private function failure(
        string $detail,
        array $preservedDrift,
        ?\Throwable $previous = null
    ): \RuntimeException {
        $lines = [$detail];
        $lines[] = 'The target WAS mutated: this apply committed its authored writes and ran its rebuild pass. '
            . 'Only the convergence metadata did not advance — base hashes, applied_revision and deletion '
            . 'receipts are unchanged and the incomplete-apply retry marker is retained.';
        if ($preservedDrift !== []) {
            $paths = [];
            foreach ($preservedDrift as $row) {
                $paths[] = '  - ' . (string) ($row['path'] ?? (string) ($row['uuid'] ?? '?'));
            }
            sort($paths, SORT_STRING);
            $lines[] = 'This apply preserved ' . count($preservedDrift)
                . ' environment-drifted entit' . (count($preservedDrift) === 1 ? 'y' : 'ies')
                . ' rather than overwriting them, and the gate above proves the whole compiled tree, '
                . 'so it cannot pass while the repository does not hold them:';
            $lines[] = implode("\n", $paths);
            $lines[] = 'Run `duo capture` to fold those environment changes into the repository, commit, '
                . 'then apply again.';
        }
        return new \RuntimeException(implode("\n", $lines), 0, $previous);
    }

    /** Fresh-process verifier for the bounded target roots and selected intent. */
    private function verify_scoped(array $opts, CompiledRepository $compiled): array {
        if (!class_exists('\WP_CLI') || $this->scopedObservation === null) {
            throw new \RuntimeException(
                'duo: scoped convergence verification is unavailable; scoped ledger evidence was not committed'
            );
        }
        $artifactSnapshot = tempnam(sys_get_temp_dir(), 'duo-scoped-verify-artifact-');
        $policySnapshot = tempnam(sys_get_temp_dir(), 'duo-scoped-verify-policy-');
        if ($artifactSnapshot === false || $policySnapshot === false) {
            if (is_string($artifactSnapshot)) { @unlink($artifactSnapshot); }
            if (is_string($policySnapshot)) { @unlink($policySnapshot); }
            throw new \RuntimeException('duo: could not allocate frozen scoped-verification inputs');
        }
        @chmod($artifactSnapshot, 0600);
        @chmod($policySnapshot, 0600);
        $request = [
            'format' => 'duo-scope-request/v1',
            'scope_hash' => (string) $this->scopeContract['scope_hash'],
            'selectors' => $this->scopeContract['selectors'],
        ];
        if ($this->scopedSession === null
            || $this->scopedSession->phase() !== ScopedApplySession::PHASE_VERIFYING) {
            throw new \RuntimeException('duo: scoped convergence verifier has no exact verifying session');
        }
        $authorityHash = $this->scopedSession->authority_hash_value();
        $effectsRoot = ScopedApplySession::hash_value($this->scopedSession->receipts());
        $cmd = 'duo verify-canonical --repo=' . escapeshellarg($this->repo)
            . ' --expected-artifact=' . $compiled->artifact_hash()
            . ' --compiled=' . escapeshellarg($artifactSnapshot)
            . ' --policy-snapshot=' . escapeshellarg($policySnapshot)
            . ' --scope-request-b64=' . escapeshellarg(base64_encode(Canon::encode($request)))
            . ' --expected-protected-root=' . (string) $this->scopedObservation['protected_out_of_scope_root']
            . ' --expected-protected-map-root=' . (string) $this->scopedObservation['protected_ledger_map_root']
            . ' --expected-authority-hash=' . $authorityHash
            . ' --expected-effects-root=' . $effectsRoot
            . ' --format=json';
        if (!empty($opts['force_unresolved_refs'])) {
            $cmd .= ' --force-unresolved-refs';
        }
        try {
            $compiled->write($artifactSnapshot);
            Canon::write_file($policySnapshot, Canon::encode($this->policy->export_snapshot()));
            $res = \WP_CLI::runcommand($cmd, [
                'launch' => true,
                'return' => 'all',
                'exit_error' => false,
            ]);
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'duo: scoped convergence verification subprocess failed; scoped ledger evidence was not committed',
                0,
                $failure
            );
        } finally {
            @unlink($artifactSnapshot);
            @unlink($policySnapshot);
        }
        if ((int) $res->return_code !== 0) {
            throw new \RuntimeException(
                'duo: scoped convergence verification refused the bounded target; scoped ledger evidence was not committed'
            );
        }
        $lines = preg_split('/\R/', trim((string) ($res->stdout ?? ''))) ?: [];
        try {
            $report = Canon::decode((string) end($lines));
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'duo: scoped convergence verification returned malformed evidence',
                0,
                $failure
            );
        }
        if (!is_array($report)
            || ($report['format'] ?? '') !== ScopedApply::CONVERGENCE_FORMAT
            || ($report['result'] ?? '') !== 'pass'
            || !hash_equals($authorityHash, (string) ($report['authority_hash'] ?? ''))
            || !hash_equals($effectsRoot, (string) ($report['effects_root'] ?? ''))) {
            throw new \RuntimeException('duo: scoped convergence verification returned invalid evidence');
        }
        return $report;
    }

    /**
     * Strict, read-only target-side verification. It verifies only selected
     * desired rows/deletions while proving every protected row and map outside
     * the scope retained its pre-mutation root.
     */
    public function verify_scoped_local(
        CompiledRepository $compiled,
        bool $forceUnresolvedRefs,
        string $expectedProtectedRoot,
        string $expectedProtectedMapRoot,
        string $authorityHash,
        string $effectsRoot
    ): array {
        foreach ([$expectedProtectedRoot, $expectedProtectedMapRoot, $authorityHash, $effectsRoot] as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new \RuntimeException('duo: scoped verification requires complete lowercase SHA-256 witnesses');
            }
        }
        $verifyingSession = ScopedApplySession::require_verifying_evidence(
            new LedgerScopedApplySessionStorage(),
            $authorityHash,
            $effectsRoot,
            (string) $this->scopeContract['scope_hash'],
            $compiled->artifact_hash()
        );
        $authorityHash = $verifyingSession->authority_hash_value();
        $effectsRoot = ScopedApplySession::hash_value($verifyingSession->receipts());
        $actual = Capture::snapshot_read_only(
            $this->repo,
            $forceUnresolvedRefs,
            $compiled,
            $this->policy
        );
        $observation = ScopedApply::observe_target(
            $this->repo,
            $compiled,
            $this->policy,
            $this->scopeContract,
            $actual,
            (array) $verifyingSession->authority()['selection']['ledger_map_identity_hashes'],
            false
        );
        if (!hash_equals($expectedProtectedRoot, (string) $observation['protected_out_of_scope_root'])
            || !hash_equals($expectedProtectedMapRoot, (string) $observation['protected_ledger_map_root'])) {
            throw new \RuntimeException(
                'duo: scoped convergence verification found protected out-of-scope target drift'
            );
        }
        $selected = ScopedApply::selected_set($this->scopeContract);
        $failures = [];
        $verifiedLive = 0;
        $verifiedDeleted = 0;
        $skippedUserMeta = 0;
        foreach (array_keys($selected) as $identity) {
            if (ScopedApply::has_record_scoped_options($this->scopeContract)
                && ScopeClosure::is_option_root($identity)) {
                $name = ScopeClosure::option_name_from_root($identity);
                $expectedCarrier = $compiled->tree()['options/core'] ?? null;
                $observedCarrier = $actual['options/core'] ?? null;
                try {
                    $expected = is_array($expectedCarrier)
                        ? OptionState::records((array) ($expectedCarrier['data'] ?? []))[$name] ?? null
                        : null;
                    $observed = is_array($observedCarrier)
                        ? OptionState::records(Canon::decode((string) ($observedCarrier['content'] ?? '')))[$name] ?? null
                        : null;
                } catch (\Throwable $failure) {
                    $expected = null;
                    $observed = null;
                }
                // An explicit absent record says only that this source has
                // no authored value or deletion intent. It must not turn a
                // target-owned value into a false convergence failure after
                // the scoped no-op terminalizes.
                if (is_array($expected) && ($expected['state'] ?? '') === 'absent') {
                    continue;
                }
                if (!is_array($expected) || !is_array($observed)
                    || !hash_equals(OptionState::record_hash($expected), OptionState::record_hash($observed))) {
                    $failures[] = hash('sha256', $identity) . ':mismatch';
                } else {
                    $verifiedLive++;
                }
                continue;
            }
            $expected = $compiled->tree()[$identity] ?? null;
            if (is_array($expected)) {
                $observed = $actual[$identity] ?? null;
                if ($observed === null) {
                    if (($expected['type'] ?? '') === 'user-meta'
                        && $this->policy->user_meta_missing_behavior((array) ($expected['data']['meta'] ?? [])) === 'warn') {
                        $skippedUserMeta++;
                        continue;
                    }
                    $failures[] = hash('sha256', $identity) . ':missing';
                    continue;
                }
                $expectedHash = self::hash($expected);
                $observedHash = ($expected['type'] ?? '') === 'post'
                    ? (string) ($observed['hash'] ?? '')
                    : hash('sha256', Canon::encode(Canon::decode((string) ($observed['content'] ?? ''))));
                if (!hash_equals((string) ($expected['type'] ?? ''), (string) ($observed['type'] ?? ''))
                    || !hash_equals($expectedHash, $observedHash)) {
                    $failures[] = hash('sha256', $identity) . ':mismatch';
                    continue;
                }
                $verifiedLive++;
                continue;
            }
            if (isset($compiled->deletions()[$identity])) {
                if (isset($actual[$identity])) {
                    $failures[] = hash('sha256', $identity) . ':still-present';
                } else {
                    $verifiedDeleted++;
                }
                continue;
            }
            $failures[] = hash('sha256', $identity) . ':not-in-artifact';
        }
        if ($failures !== []) {
            throw new \RuntimeException(
                'duo: scoped convergence verification failed selected intent (' . implode(',', $failures) . ')'
            );
        }
        $receipt = [
            'format' => ScopedApply::CONVERGENCE_FORMAT,
            'result' => 'pass',
            'authority_hash' => $authorityHash,
            'effects_root' => $effectsRoot,
            'scope_hash' => (string) $this->scopeContract['scope_hash'],
            'source_artifact_hash' => $compiled->artifact_hash(),
            'protected_out_of_scope_root' => $expectedProtectedRoot,
            'protected_ledger_map_root' => $expectedProtectedMapRoot,
            'selected_live' => $verifiedLive,
            'selected_deletions' => $verifiedDeleted,
            'skipped_user_meta' => $skippedUserMeta,
        ];
        $receipt['receipt_hash'] = hash('sha256', Canon::encode($receipt));
        return $receipt;
    }

    public function verify_local(
        CompiledRepository $compiled,
        array $tree,
        array $deletions,
        bool $verifyDeletes,
        bool $forceUnresolvedRefs
    ): array {
        $actual = Capture::snapshot(
            $this->repo,
            $forceUnresolvedRefs,
            $compiled,
            $this->policy
        );
        $failures = [];
        $skippedUserMeta = 0;

        foreach ($tree as $uuid => $expected) {
            $observed = $actual[$uuid] ?? null;
            if ($observed === null) {
                if (($expected['type'] ?? '') === 'user-meta'
                    && $this->policy->user_meta_missing_behavior((array) ($expected['data']['meta'] ?? [])) === 'warn') {
                    $skippedUserMeta++;
                    continue;
                }
                $failures[] = "{$expected['type']} {$expected['path']} ($uuid): missing after apply";
                continue;
            }
            if (!hash_equals((string) $expected['type'], (string) $observed['type'])) {
                $failures[] = "{$expected['type']} {$expected['path']} ($uuid): type mismatch"
                    . " (observed {$observed['type']})";
                continue;
            }
            // Repository JSON publication is documented/formatted at two
            // spaces while Canon::encode() currently emits PHP's native
            // four-space JSON_PRETTY_PRINT form in Capture::snapshot().
            // Hash decoded+re-encoded data on both sides so verification is
            // exact about authored meaning, not serializer presentation.
            // Posts keep their existing derived-field-aware hash basis.
            $expectedHash = self::hash($expected);
            $observedHash = $expected['type'] === 'post'
                ? (string) $observed['hash']
                : hash('sha256', Canon::encode(Canon::decode((string) $observed['content'])));
            if (!hash_equals($expectedHash, $observedHash)) {
                $failures[] = "{$expected['type']} {$expected['path']} ($uuid): canonical hash mismatch"
                    . " (expected $expectedHash, observed $observedHash)";
            }
        }

        $verifiedDeletions = 0;
        if ($verifyDeletes) {
            foreach ($deletions as $uuid => $deletion) {
                $verifiedDeletions++;
                if (isset($actual[$uuid])) {
                    $failures[] = "deletion {$deletion['path']} ($uuid): entity still present after apply";
                }
            }
        }

        if ($failures) {
            throw new \RuntimeException(
                "duo: post-apply convergence verification failed; promotion metadata was not committed:\n  - "
                . implode("\n  - ", $failures)
            );
        }

        return [
            'verifier' => 'canonical-recapture/v1',
            'result' => 'pass',
            'live_entities' => count($tree),
            'deletions' => $verifiedDeletions,
            'skipped_user_meta' => $skippedUserMeta,
        ];
    }
}

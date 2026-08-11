<?php
namespace Duo;

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
    public function verify(array $opts, CompiledRepository $compiled): array {
        if ($this->scopeContract !== null) {
            return $this->verify_scoped($opts, $compiled);
        }
        if (!class_exists('\WP_CLI')) {
            throw new \RuntimeException(
                'duo: post-apply convergence verification is unavailable outside wp-cli; promotion metadata was not committed'
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
            throw new \RuntimeException(
                'duo: post-apply convergence verification subprocess failed; promotion metadata was not committed',
                0,
                $t
            );
        } finally {
            @unlink($artifactSnapshot);
            @unlink($policySnapshot);
        }
        if ((int) $res->return_code !== 0) {
            $detail = trim((string) ($res->stderr ?? ''));
            if (str_starts_with($detail, 'Error: ')) {
                $detail = substr($detail, strlen('Error: '));
            }
            throw new \RuntimeException(
                $detail !== ''
                    ? $detail
                    : 'duo: post-apply convergence verification subprocess failed; promotion metadata was not committed'
            );
        }
        $lines = preg_split('/\R/', trim((string) ($res->stdout ?? ''))) ?: [];
        $json = (string) end($lines);
        try {
            $report = Canon::decode($json);
        } catch (\Throwable $t) {
            throw new \RuntimeException(
                'duo: post-apply convergence verification returned malformed evidence; promotion metadata was not committed',
                0,
                $t
            );
        }
        if (!is_array($report)
            || ($report['verifier'] ?? '') !== 'canonical-recapture/v1'
            || ($report['result'] ?? '') !== 'pass') {
            throw new \RuntimeException(
                'duo: post-apply convergence verification returned invalid evidence; promotion metadata was not committed'
            );
        }
        return $report;
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

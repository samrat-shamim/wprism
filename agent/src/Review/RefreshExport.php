<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/Db.php';
require_once __DIR__ . '/../Kernel/MediaPayloadAuthority.php';
require_once __DIR__ . '/../Kernel/OptionState.php';
require_once __DIR__ . '/../Policy/ScopeClosure.php';

/**
 * Strict production observation boundary for host-side refresh/rebase work.
 *
 * Ordinary `wp duo capture` is deliberately a publication transaction: it
 * may mint identities, repair old ledger widths, prune dead maps, and write
 * a new repository state tree. None of those powers belong to a request
 * whose only job is to report what production already says. This class keeps
 * that distinction executable: it opens one READ ONLY InnoDB snapshot, uses
 * only SELECT-backed ledger helpers, and rejects every absent/contradictory
 * durable identity rather than making production look healthier than it is.
 *
 * The repository is compiled before target contact. That is a read-only
 * validation of the exact policy/manifests/code descriptor the host asked us
 * to bind to, not a source of live truth. Live authored records remain the
 * Capture-derived half; code and policy identities stay explicit siblings so
 * a caller cannot confuse state convergence with code materialization.
 */
final class RefreshExport {
    public const FORMAT = 'duo-refresh-production/v1';

    /**
     * @return array<string,mixed> canonical `duo-refresh-production/v1` payload
     */
    public static function run(
        string $repo,
        bool $forceUnresolvedRefs = false,
        ?array $scopeRequest = null
    ): array {
        $repo = self::repository_root($repo);
        $policy = Policy::load($repo);
        $compiled = RepositoryCompiler::compile($repo, $policy);
        $scopeContract = $scopeRequest === null
            ? null
            : self::scope_contract_for_request($scopeRequest, $compiled, $policy);
        Capture::assert_read_only_export_engine_support($policy);

        $previousOptions = $compiled->tree()['options/core']['data'] ?? null;
        $previousOptions = is_array($previousOptions) ? $previousOptions : null;
        $previousUserLogins = [];
        foreach ($compiled->tree() as $entity) {
            if (($entity['type'] ?? '') === 'user-meta') {
                $previousUserLogins[] = (string) ($entity['data']['login'] ?? '');
            }
        }

        return self::in_read_only_snapshot(static function () use (
            $repo,
            $policy,
            $compiled,
            $previousOptions,
            $previousUserLogins,
            $forceUnresolvedRefs,
            $scopeContract
        ): array {
            // The schema/map checks happen inside the same MVCC view as the
            // rows they authorize. No ensure/repair fallback is available.
            Ledger::assert_read_only_schema();
            self::assert_quiescent();
            Identity::assert_embedded_unique();

            $candidate = Capture::build_read_only_export(
                $repo,
                $policy,
                $previousOptions,
                $previousUserLogins,
                $forceUnresolvedRefs
            );
            Identity::assert_entities_unique($candidate['entities']);
            $selected = $scopeContract === null
                ? null
                : ScopedStateOverlay::selected_identities($scopeContract);
            $deletions = Deletion::capture_tombstones(
                $compiled,
                $candidate['entities'],
                $policy,
                $selected
            );

            if ($scopeContract !== null) {
                $selectedOptionNames = ScopeContract::option_root_names($scopeContract);
                $sourceTombstones = array_fill_keys(
                    array_map('strval', array_column((array) $scopeContract['tombstones'], 'uuid')),
                    true
                );
                $authorizedDeletions = [];
                foreach ($deletions as $deletion) {
                    $identity = (string) ($deletion['uuid'] ?? '');
                    if ($identity !== '' && !isset($sourceTombstones[$identity])) {
                        $authorizedDeletions[] = $identity;
                    }
                }
                // Validate the complete production observation before its
                // out-of-scope rows are intentionally omitted from the wire
                // snapshot. Otherwise a target-only child/referrer could be
                // filtered away and misrepresented as a bounded refresh.
                $targetProbeState = ScopedStateOverlay::stage_state_view(
                    ScopedStateOverlay::target_probe_rows(
                        $compiled,
                        $candidate['entities'],
                        $deletions
                    )
                );
                $targetProbeMedia = null;
                try {
                    $targetProbeMedia = ScopedStateOverlay::stage_candidate_media_view(
                        $repo,
                        $candidate['media']
                    );
                    $targetProbe = RepositoryCompiler::compile_staged(
                        $targetProbeState,
                        $repo,
                        $policy,
                        $targetProbeMedia
                    );
                    ScopeContract::assert_candidate_bounded(
                        $scopeContract,
                        $targetProbe,
                        $policy,
                        $authorizedDeletions
                    );
                } finally {
                    if (is_string($targetProbeMedia)) {
                        ScopedStateOverlay::discard_media_view($targetProbeMedia);
                    }
                    ScopedStateOverlay::discard_state_view($targetProbeState);
                }
                $live = self::scoped_live_entities(
                    $candidate['entities'],
                    $selected,
                    $selectedOptionNames
                );
                $deleted = array_fill_keys(array_map('strval', array_column($deletions, 'uuid')), true);
                foreach ($selected as $identity) {
                    if (ScopeClosure::is_option_root($identity)) {
                        continue;
                    }
                    if (isset($sourceTombstones[$identity])) {
                        if (isset($live[$identity]) || !isset($deleted[$identity])) {
                            throw new \RuntimeException(
                                "duo: scoped refresh export observed resurrection of selected tombstone '$identity'"
                            );
                        }
                        continue;
                    }
                    if (!isset($live[$identity]) && !isset($deleted[$identity])) {
                        throw new \RuntimeException(
                            "duo: scoped refresh export lost selected identity '$identity' without bounded deletion evidence"
                        );
                    }
                    if (isset($deleted[$identity])) {
                        foreach ((array) $scopeContract['live']['inbound'] as $inbound) {
                            if ((string) ($inbound['target'] ?? '') === $identity) {
                                throw new \RuntimeException(
                                    "duo: scoped refresh deletion '$identity' would strand an excluded inbound reference"
                                );
                            }
                        }
                    }
                }
                $candidate['entities'] = array_values($live);
                $candidate['media'] = ScopedStateOverlay::selected_media(
                    $candidate['entities'],
                    $candidate['media']
                );
            }

            return self::payload($candidate, $deletions, $compiled, $scopeContract);
        });
    }

    /** The host must not race an in-flight apply or any live promotion lease. */
    private static function assert_quiescent(): void {
        if (Ledger::kv_get('apply_in_progress') !== null) {
            throw new \RuntimeException(
                'duo: refresh export refused — apply_in_progress is present; recover or complete the interrupted apply before observing production'
            );
        }
        if (Ledger::kv_get('promotion_lock') !== null) {
            throw new \RuntimeException(
                'duo: refresh export refused — a promotion lease is present; wait for or recover that promotion before observing production'
            );
        }
    }

    /**
     * Project a validated live observation onto the exact state identities a
     * scoped refresh is allowed to report. Option roots are virtual records
     * inside options/core, so their wire carrier must contain only the named
     * records unless the same contract independently selects the whole
     * options surface. A whole-surface selection remains authoritative; an
     * additional record root must never accidentally narrow it.
     *
     * @param list<array<string,mixed>> $entities
     * @param list<string> $selected
     * @param list<string> $selectedOptionNames
     * @return array<string,array<string,mixed>> keyed by actual wire identity
     */
    private static function scoped_live_entities(
        array $entities,
        array $selected,
        array $selectedOptionNames
    ): array {
        $selectedSet = array_fill_keys($selected, true);
        $live = [];
        $optionCarrier = null;
        foreach ($entities as $row) {
            $identity = (string) ($row['uuid'] ?? '');
            if (isset($selectedSet[$identity])) {
                $live[$identity] = $row;
            }
            if ($identity === 'options/core') {
                $optionCarrier = $row;
            }
        }
        if ($selectedOptionNames === [] || isset($selectedSet['options/core'])) {
            return $live;
        }
        if (!is_array($optionCarrier)) {
            throw new \RuntimeException('duo: scoped refresh export lost the selected options document');
        }
        $records = OptionState::records(Canon::decode((string) ($optionCarrier['content'] ?? '')));
        $selectedRecords = [];
        foreach ($selectedOptionNames as $name) {
            if (!array_key_exists($name, $records)) {
                throw new \RuntimeException(
                    "duo: scoped refresh export lost selected option '$name' without bounded option evidence"
                );
            }
            $selectedRecords[$name] = $records[$name];
        }
        $content = Canon::encode(OptionState::document($selectedRecords));
        $optionCarrier['content'] = $content;
        $optionCarrier['hash_basis'] = $content;
        $live['options/core'] = $optionCarrier;
        return $live;
    }

    /**
     * Completed code is a separate durable contract. A matching filesystem
     * is not enough: refresh needs the completed ledger receipt, and any
     * stage residue means the code half is explicitly not yet complete.
     *
     * @return ?array{revision:string,descriptor:array<string,mixed>}
     */
    private static function completed_code(CompiledRepository $compiled): ?array {
        foreach ([
            Code::CODE_STAGE_REVISION_KEY,
            Code::CODE_STAGE_DESCRIPTOR_KEY,
            Code::CODE_STAGE_ARTIFACT_KEY,
            Code::CODE_STAGE_HISTORY_KEY,
            Code::CODE_STAGE_CREATED_PATHS_KEY,
        ] as $key) {
            if (Ledger::kv_get($key) !== null) {
                throw new \RuntimeException(
                    "duo: refresh export refused — temporary code-stage metadata '$key' remains; finalize or recover code before observing production"
                );
            }
        }

        $expected = $compiled->code_descriptor();
        $revision = Ledger::kv_get(Code::CODE_REVISION_KEY);
        $raw = Ledger::kv_get(Code::CODE_DESCRIPTOR_KEY);
        if ($expected === null) {
            if ($revision !== null || $raw !== null) {
                throw new \RuntimeException(
                    'duo: refresh export refused — completed code receipt exists but the requested repository has no code descriptor'
                );
            }
            return null;
        }
        if ($revision === null || $raw === null || $revision === '' || $raw === '') {
            throw new \RuntimeException(
                'duo: refresh export refused — repository requires code materialization but production has no complete code descriptor/revision receipt'
            );
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $revision)) {
            throw new \RuntimeException('duo: refresh export refused — completed code_revision is malformed');
        }
        try {
            $descriptor = Canon::decode($raw);
        } catch (\Throwable $t) {
            throw new \RuntimeException('duo: refresh export refused — completed code descriptor is not canonical JSON', 0, $t);
        }
        if (!is_array($descriptor) || Canon::encode($descriptor) !== $raw) {
            throw new \RuntimeException('duo: refresh export refused — completed code descriptor is not canonical JSON');
        }
        Code::assert_descriptor($descriptor);
        if (!hash_equals($revision, (string) ($descriptor['code_revision'] ?? ''))
            || !hash_equals($revision, (string) ($expected['code_revision'] ?? ''))
            || Canon::encode($descriptor) !== Canon::encode($expected)) {
            throw new \RuntimeException(
                'duo: refresh export refused — completed code descriptor/revision does not match the requested repository artifact'
            );
        }
        return ['revision' => $revision, 'descriptor' => $descriptor];
    }

    /** @return array<string,mixed> */
    private static function payload(
        array $candidate,
        array $deletions,
        CompiledRepository $compiled,
        ?array $scopeContract = null
    ): array {
        $records = self::records((array) ($candidate['entities'] ?? []));
        $deleted = self::records($deletions);
        $media = [];
        $mediaBytes = 0;
        foreach ((array) ($candidate['media'] ?? []) as $name => $source) {
            $witness = is_array($source) ? ($source['witness'] ?? null) : null;
            if (!is_array($witness) || !is_int($witness['size'] ?? null)) {
                throw new \RuntimeException('duo: refresh export refused — capture produced an unbounded media source');
            }
            $mediaBytes = MediaPayloadAuthority::addToAggregate($mediaBytes, $witness['size']);
        }
        MediaPayloadAuthority::assertRefreshExportHeadroom($mediaBytes);
        foreach ((array) ($candidate['media'] ?? []) as $name => $source) {
            $name = (string) $name;
            if (!is_array($source)) {
                throw new \RuntimeException('duo: refresh export refused — capture produced an invalid media identity');
            }
            try {
                $bytes = MediaPayloadAuthority::sourceBytes($name, $source);
            } catch (\Throwable $failure) {
                throw new \RuntimeException("duo: refresh export refused — media '$name' does not match its bounded capture witness", 0, $failure);
            }
            $media[$name] = ['sha256' => hash('sha256', $bytes), 'base64' => base64_encode($bytes)];
        }
        ksort($media, SORT_STRING);

        $warnings = array_merge((array) ($candidate['notes'] ?? []), (array) ($candidate['warnings'] ?? []));
        $warnings = array_values(array_unique(array_map('strval', $warnings)));
        sort($warnings, SORT_STRING);
        $payload = [
            'format' => self::FORMAT,
            'records' => $records,
            'media' => $media,
            'policy' => [
                'site_hash' => $compiled->site_hash(),
                'manifest_hash' => $compiled->manifest_hash(),
                'resolved_adapters' => $compiled->resolved_adapters(),
            ],
            'completed_code' => self::completed_code($compiled),
            'repository' => [
                'revision_hash' => $compiled->revision_hash(),
                'artifact_hash' => $compiled->artifact_hash(),
                'code_revision' => $compiled->code_revision(),
            ],
            'deletions' => $deleted,
            'warnings' => $warnings,
        ];
        if ($scopeContract !== null) {
            $payload['scope'] = [
                'format' => 'duo-refresh-scope/v1',
                'scope_hash' => (string) $scopeContract['scope_hash'],
                'source' => $scopeContract['source'],
                'selectors' => $scopeContract['selectors'],
                'selected_identities' => ScopedStateOverlay::selected_identities($scopeContract),
                'out_of_scope' => 'omitted_not_absent',
            ];
        }
        $payload['snapshot_hash'] = hash('sha256', Canon::encode($payload));
        return $payload;
    }

    /** @return array<string,mixed> */
    private static function scope_contract_for_request(
        array $request,
        CompiledRepository $compiled,
        Policy $policy
    ): array {
        if (($request['format'] ?? null) === ScopeContract::FORMAT) {
            $contract = ScopeContract::from_array($request);
            ScopeContract::assert_associated($contract, $compiled, $policy);
            return $contract;
        }
        $keys = array_keys($request);
        sort($keys, SORT_STRING);
        if ($keys !== ['format', 'scope_hash', 'selectors']
            || ($request['format'] ?? null) !== 'duo-scope-request/v1'
            || !is_array($request['selectors'] ?? null)
            || !array_is_list($request['selectors'])) {
            throw new \RuntimeException('duo: scoped refresh request has an unexpected schema');
        }
        $contract = ScopedStateOverlay::resolve_request(
            $compiled,
            $policy,
            ScopeContract::normalize_selectors($request['selectors']),
            (string) ($request['scope_hash'] ?? '')
        );
        return $contract;
    }

    /** @return array<string,array{identity:string,type:string,path:string,hash:string,content:string}> */
    private static function records(array $entities): array {
        $out = [];
        foreach ($entities as $entity) {
            $identity = (string) ($entity['uuid'] ?? '');
            $type = (string) ($entity['type'] ?? '');
            $path = (string) ($entity['path'] ?? '');
            $content = $entity['content'] ?? null;
            if ($identity === '' || $type === '' || !self::safe_relative($path) || !is_string($content)
                || isset($out[$identity])) {
                throw new \RuntimeException('duo: refresh export refused — capture produced an invalid or duplicate semantic record');
            }
            $out[$identity] = [
                'identity' => $identity,
                'type' => $type,
                'path' => $path,
                // Post records intentionally use Capture's derived-aware
                // basis; every other record's bytes are its semantic basis.
                'hash' => hash('sha256', (string) ($entity['hash_basis'] ?? $content)),
                'content' => $content,
            ];
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    private static function safe_relative(string $path): bool {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")
            || str_contains($path, '\\')) {
            return false;
        }
        foreach (explode('/', $path) as $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                return false;
            }
        }
        return true;
    }

    private static function repository_root(string $repo): string {
        if ($repo === '' || str_contains($repo, "\0")) {
            throw new \RuntimeException('duo: refresh export requires a non-empty safe --repo path');
        }
        $root = realpath($repo);
        if ($root === false || !is_dir($root) || !is_file($root . '/site.duo.json')) {
            throw new \RuntimeException('duo: refresh export --repo must resolve to a site repository containing site.duo.json');
        }
        return rtrim($root, '/');
    }

    /** Execute callback in a server-enforced, rollback-only read snapshot. */
    private static function in_read_only_snapshot(callable $fn): array {
        $open = false;
        try {
            Db::start_read_only_consistent_snapshot('starting read-only production snapshot');
            $open = true;
            $result = $fn();
            Db::rollback('closing read-only production snapshot');
            $open = false;
            return $result;
        } catch (\Throwable $t) {
            if ($open) {
                try {
                    if (Db::transaction_active('read-only production snapshot rollback boundary')) {
                        Db::rollback('rolling back read-only production snapshot');
                    } else {
                        Db::forget_transaction_tracking();
                    }
                } catch (\Throwable $_rollback) {
                    // Preserve the original refusal; no Duo DML can have
                    // occurred in a server-enforced READ ONLY transaction.
                    Db::forget_transaction_tracking();
                }
            }
            throw $t;
        }
    }
}

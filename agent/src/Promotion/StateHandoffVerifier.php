<?php
namespace Duo;

/**
 * Before/after canonical options snapshot comparison for one lifecycle
 * phase (DUO-3350 slice 7, the "StateHandoffVerifier" target seam,
 * extracted from Deploy): captures a hash-bound options/core snapshot
 * immediately before and after a lifecycle phase's own activate/deactivate/
 * switch-theme mutations run, then proves any canonical option that changed
 * across that window either matches the frozen desired record exactly or is
 * a hook-created row cryptographically bound to a previously-missing
 * desired record -- never an unrelated, unexplained hook side effect state
 * apply could later mistake for expected lifecycle progress and overwrite.
 *
 * All three methods here are read-only: no activate_plugin()/
 * deactivate_plugins()/switch_theme() call, no promotion-lock/canary call,
 * no WordPress hook fire. That machinery lives in LifecycleExecutor::execute()
 * (DUO-3350 slice 8, the "LifecycleExecutor" half of a different target
 * seam, extracted from Deploy::run()), which Deploy::run() calls into at
 * specific points in its own required sequence -- before the mutations
 * (captures the "before" snapshot, via this collaborator) and after
 * (captures "after" and runs the comparison, also via this collaborator)
 * -- rather than this collaborator owning any part of that sequencing
 * itself.
 *
 * Deploy keeps thin private facades over all three entry points, matching
 * every prior slice in this issue -- including for options_snapshot()/
 * unexpected_lifecycle_state_changes(), which Deploy::run() still calls
 * directly at its own established call sites, and for
 * bind_lifecycle_missing_options(), which run() never called directly (only
 * options_snapshot()'s own internal call did, and that call moves here
 * with it) but which sandbox/tests/offline/code-half/regress_lifecycle_state_handoff.php
 * reaches via ReflectionMethod(Deploy::class, 'bind_lifecycle_missing_options')
 * for a genuine behavioral test -- a hidden reflection-based caller, not a
 * bare method-name mention, caught by grepping for it specifically before
 * writing any code, the same discipline this series has used since DUO-3347
 * slice 12's assign_locations() precedent.
 *
 * Deliberately requires nothing: every external class these three methods
 * touch (Capture, Canon, OptionState, plus the Policy/CompiledRepository
 * type hints) is already a pre-existing regress_agent_src_requires.php
 * baseline gap on Deploy.php itself -- Deploy never required any of them
 * directly either, and this move simply inherits that unchanged rather
 * than introducing a real require whose own transitive chain could cascade
 * into sandbox/tests/offline/policy/regress_manifest_validate.sh's static WordPress-reach
 * scanner the way slice 6's Code.php attempt did.
 */
final class StateHandoffVerifier {
    /** @return array{hash:string,document:array<string,mixed>} */
    public static function options_snapshot(
        string $repo,
        Policy $policy,
        CompiledRepository $compiled,
        bool $forceUnresolvedRefs
    ): array {
        $snapshot = Capture::snapshot_options_core($repo, $forceUnresolvedRefs, $compiled, $policy);
        $row = $snapshot['options/core'] ?? null;
        $hash = is_array($row) ? (string) ($row['hash'] ?? '') : '';
        $content = is_array($row) ? (string) ($row['content'] ?? '') : '';
        if (!preg_match('/^[a-f0-9]{64}$/', $hash) || $content === '') {
            throw new \RuntimeException('duo: lifecycle state handoff could not snapshot canonical options/core');
        }
        try {
            $document = Canon::decode($content);
            if (!is_array($document)) {
                throw new \RuntimeException('snapshot document is not an object');
            }
            OptionState::records($document);
            $desired = $compiled->tree()['options/core']['data'] ?? null;
            if (is_array($desired)) {
                $document = self::bind_lifecycle_missing_options($document, $desired);
                $content = Canon::encode($document);
                $hash = hash('sha256', $content);
            }
        } catch (\Throwable $t) {
            throw new \RuntimeException(
                'duo: lifecycle state handoff captured malformed canonical options/core',
                0,
                $t
            );
        }
        return ['hash' => $hash, 'document' => $document];
    }

    /**
     * Bind a lifecycle snapshot's missing portable projection to the frozen
     * desired record. This is intentionally local to the deploy handoff: an
     * ordinary canonical state=absent remains non-authoritative, and this
     * transient document is never consumed as apply intent.
     *
     * Exact authored options already arrive as bound tombstones when their
     * row is absent before a hook. After activation, however, a hook-created
     * ref-bearing option may exist while its new target-local entity has no
     * Duo identity yet; the non-minting snapshot correctly projects that row
     * as state=absent. Elementor's elementor_active_kit is the proven case.
     * Rebinding that post-hook missing projection makes it byte-identical to
     * the pre-hook proof. Sub-key/dynamic options can have the same absent
     * projection because Duo owns only part of their value. Converting only
     * desired-present/missing observations gives the record gate the same
     * proof without granting deletion authority or reviving the unsafe
     * generic absent-to-present exception.
     */
    public static function bind_lifecycle_missing_options(array $observedDocument, array $desiredDocument): array {
        $observed = OptionState::records($observedDocument);
        foreach (OptionState::records($desiredDocument) as $name => $desiredRecord) {
            $observedRecord = $observed[$name] ?? null;
            if (($desiredRecord['state'] ?? null) === 'present'
                && ($observedRecord === null || ($observedRecord['state'] ?? null) === 'absent')) {
                // The immutable desired document is already available to the
                // record gate, so this transient marker needs only its hash.
                // Omitting a classification witness also makes a ref-bearing
                // post-hook projection that remains unresolved byte-identical
                // to the pre-hook bound tombstone instead of manufacturing a
                // false lifecycle change from witness metadata alone.
                $observed[$name] = OptionState::deleted($desiredRecord);
            }
        }
        return OptionState::document($observed);
    }

    /**
     * A whole-entity hash handoff may cover lifecycle-managed records,
     * authored records whose post-hook value is exactly the frozen desired
     * value, or a missing authored record whose pre-hook deleted tombstone is
     * cryptographically bound to that desired present record. Otherwise
     * apply could mistake an unrelated hook migration for expected lifecycle
     * progress and overwrite it with stale repository data. Return every
     * unsafe name so deploy can stop before state apply.
     *
     * @return list<string>
     */
    public static function unexpected_lifecycle_state_changes(
        array $beforeDocument,
        array $afterDocument,
        array $desiredDocument
    ): array {
        $before = OptionState::records($beforeDocument);
        $after = OptionState::records($afterDocument);
        $desired = OptionState::records($desiredDocument);
        $managed = array_fill_keys(['active_plugins', 'template', 'stylesheet'], true);
        $names = array_unique(array_merge(array_keys($before), array_keys($after)));
        sort($names, SORT_STRING);
        $unexpected = [];
        foreach ($names as $name) {
            $beforeRecord = $before[$name] ?? null;
            $afterRecord = $after[$name] ?? null;
            if (Canon::encode($beforeRecord) === Canon::encode($afterRecord)
                || isset($managed[$name])) {
                continue;
            }
            $desiredRecord = $desired[$name] ?? null;
            // On a first activation OptionsCapture::capture(previous desired)
            // represents a missing exact authored row as a deleted tombstone
            // whose expected_hash is the hash of the frozen desired present
            // record. The hook may then create an ordinary default (present),
            // or a ref-bearing row may be omitted as absent until its identity
            // is minted. Only that cryptographic proof authorizes Apply to
            // reconcile the hook result; a plain state=absent record, a stale
            // expected_hash, or absent/deleted desired intent stays blocked.
            $beforeWasBoundMissing = is_array($beforeRecord)
                && ($beforeRecord['state'] ?? null) === 'deleted'
                && is_array($desiredRecord)
                && ($desiredRecord['state'] ?? null) === 'present'
                && is_array($afterRecord)
                && in_array(($afterRecord['state'] ?? null), ['present', 'absent'], true)
                && hash_equals(
                    (string) $beforeRecord['expected_hash'],
                    OptionState::record_hash($desiredRecord)
                );
            if ($beforeWasBoundMissing) {
                continue;
            }
            if (self::is_theme_switch_storage_transition(
                (string) $name,
                $beforeRecord,
                $afterRecord,
                $desired
            )) {
                continue;
            }
            if (is_array($afterRecord) && is_array($desiredRecord)
                && ($desiredRecord['state'] ?? null) !== 'absent'
                && Canon::encode($afterRecord) === Canon::encode($desiredRecord)) {
                continue;
            }
            $unexpected[] = (string) $name;
        }
        return $unexpected;
    }

    /**
     * WordPress flips the newly-active theme's `theme_mods_*` autoload mode
     * from `off` to `on` inside switch_theme() even when every captured
     * authored sub-key is byte-identical (measured against WordPress 7.0.3 in
     * the core conformance target). That storage-only lifecycle delta is safe
     * exactly when the row belongs to the frozen desired stylesheet and ends
     * at the frozen desired autoload value. Any value change still reaches the
     * ordinary refusal above/below, so a theme hook cannot hide authored state.
     *
     * @param mixed $beforeRecord
     * @param mixed $afterRecord
     * @param array<string,array<string,mixed>> $desired
     */
    private static function is_theme_switch_storage_transition(
        string $name,
        mixed $beforeRecord,
        mixed $afterRecord,
        array $desired
    ): bool {
        $stylesheet = $desired['stylesheet']['value'] ?? null;
        $desiredRecord = $desired[$name] ?? null;
        return is_string($stylesheet)
            && $stylesheet !== ''
            && hash_equals('theme_mods_' . $stylesheet, $name)
            && is_array($beforeRecord)
            && is_array($afterRecord)
            && is_array($desiredRecord)
            && ($beforeRecord['state'] ?? null) === 'present'
            && ($afterRecord['state'] ?? null) === 'present'
            && ($desiredRecord['state'] ?? null) === 'present'
            && Canon::encode($beforeRecord['value'] ?? null) === Canon::encode($afterRecord['value'] ?? null)
            && ($beforeRecord['autoload'] ?? null) !== ($afterRecord['autoload'] ?? null)
            && ($afterRecord['autoload'] ?? null) === ($desiredRecord['autoload'] ?? null);
    }
}

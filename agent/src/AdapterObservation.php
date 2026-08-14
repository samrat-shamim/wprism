<?php
declare(strict_types=1);

namespace Duo;

require_once __DIR__ . '/AdapterObservationProjector.php';

/**
 * WordPress collector for the closed adapter-observation document.
 *
 * This class owns the target reads and prerequisite probe. The capability-
 * owned projector receives facts only and contains no Journal, Pending,
 * provider invocation, or WordPress-global dependency.
 */
final class AdapterObservation {
    public const FORMAT = AdapterObservationProjector::FORMAT;
    public const REDACTION = AdapterObservationProjector::REDACTION;

    /** Read existing evidence without installing or repairing prerequisites. */
    public static function report(string $repo): array {
        self::assertJournalPrerequisite();
        $policy = Policy::load($repo, null, true);
        return AdapterObservationProjector::fromFacts(
            self::sitePolicySha256($repo),
            Journal::report_read_only($policy),
            Pending::scan_read_only($repo, $policy),
            AdapterSources::survey($repo),
            $policy->capability_report(['operation' => 'promote'])
        );
    }

    /** Compatibility facade for the pre-extraction pure test seam. */
    public static function from_facts(
        string $sitePolicySha256,
        array $journal,
        array $pending,
        array $survey,
        array $capabilities
    ): array {
        return AdapterObservationProjector::fromFacts(
            $sitePolicySha256,
            $journal,
            $pending,
            $survey,
            $capabilities
        );
    }

    /** Compatibility facade for the public observation hash basis. */
    public static function hash_document(array $document): string {
        return AdapterObservationProjector::hash_document($document);
    }

    private static function sitePolicySha256(string $repo): string {
        return 'sha256:' . hash(
            'sha256',
            Canon::read_file(rtrim($repo, '/') . '/site.duo.json')
        );
    }

    /** The prerequisite probe is read-only; it never calls Ledger::ensure(). */
    private static function assertJournalPrerequisite(): void {
        global $wpdb;
        if (!is_object($wpdb) || !is_string($wpdb->prefix ?? null)
            || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) {
            self::refusePrerequisite();
        }
        $table = $wpdb->prefix . 'duo_journal';
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $readError = $wpdb->last_error ?? '';
        if (!is_string($readError) || $readError !== '' || $found === false) {
            self::refuseJournalReadError();
        }
        if (!is_string($found) || $found !== $table) {
            self::refusePrerequisite();
        }
    }

    private static function refuseJournalReadError(): never {
        throw new CommandRefusalException(
            'adapter_observation_journal_unreadable',
            'adapter observation could not read the existing provenance journal',
            'inspect and repair the journal through the existing controlled workflow before collecting proposal evidence',
            [[
                'code' => 'adapter_observation_journal_unreadable',
                'message' => 'the observer will not treat a failed journal read as an absent journal',
                'remediation' => 'restore readable provenance state before collecting adapter observation evidence',
            ]],
            'duo: adapter observation refused because the provenance journal prerequisite probe failed'
        );
    }

    private static function refusePrerequisite(): never {
        throw new CommandRefusalException(
            'adapter_observation_prerequisite_absent',
            'adapter observation requires an existing provenance journal table',
            'install or repair the agent through the existing controlled workflow, then rerun adapter observation',
            [[
                'code' => 'adapter_observation_prerequisite_absent',
                'message' => 'the observer will not create or repair provenance state',
                'remediation' => 'restore the existing journal prerequisite before collecting adapter observation evidence',
            ]],
            'duo: adapter observation refused because its provenance journal prerequisite is absent'
        );
    }
}

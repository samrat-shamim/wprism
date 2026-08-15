<?php
declare(strict_types=1);

namespace Duo;

require_once __DIR__ . '/EvidenceImpactReport.php';
require_once __DIR__ . '/EvidenceSubjectIdentity.php';
require_once __DIR__ . '/EvidenceInputClosure.php';

/**
 * Compare actual current subject state with the reviewed impact projection.
 *
 * A matching non-empty stale set is a successful review check, but it remains
 * explicitly non-releasable. This distinction prevents a stale-evidence
 * report from being used as an accidental release waiver.
 */
final class EvidenceStalenessCheck {
    /** @return array<string,mixed> */
    public static function run(string $root, array $report): array {
        EvidenceImpactReport::validate($report);
        $index = EvidenceSubjectIndex::load($root);
        $actual = [];
        $diagnostics = [];
        foreach ($index->certifiedSubjects() as $subject) {
            try {
                self::assertSubjectCurrent($index, $subject);
            } catch (\Throwable $e) {
                $actual[] = $subject['key'];
                $diagnostics[$subject['key']] = self::diagnostic($e->getMessage());
            }
        }
        sort($actual, SORT_STRING);
        ksort($diagnostics, SORT_STRING);
        $reviewed = $report['affected_subjects'];
        $exact = $actual === $reviewed;
        $nonReleasable = $reviewed !== [] || $actual !== [] || !$exact;
        return [
            'format' => 'duo-evidence-staleness-result/v1',
            'closure_policy' => [
                'required_format' => 'duo-certification-closure/v2',
                'legacy_v1_currentness' => 'forbidden',
            ],
            'report_sha256' => hash('sha256', Canon::encode($report)),
            'candidate_head_sha' => $report['head_sha'],
            'state' => $exact ? 'pass' : 'fail',
            'release_eligibility' => $nonReleasable ? 'non_releasable' : 'releasable',
            'non_releasable' => $nonReleasable,
            'reviewed_impact_set' => $reviewed,
            'actual_stale_subjects' => $actual,
            'exact_match' => $exact,
            'diagnostics' => $diagnostics,
            'message' => $exact
                ? ($actual === []
                    ? 'actual subject staleness exactly matches the empty reviewed impact set'
                    : 'actual subject staleness exactly matches the reviewed impact set; fresh certification is required')
                : 'actual subject staleness does not exactly match the reviewed impact set; release is blocked',
        ];
    }

    /** @param array{key:string,kind:string,name:string,manifest:array,claim:array,tests:list<string>} $subject */
    private static function assertSubjectCurrent(EvidenceSubjectIndex $index, array $subject): void {
        $entry = $index->evidenceRecord($subject['key']);
        if (!is_array($entry) || array_is_list($entry)
            || !is_array($entry['bundle'] ?? null) || !is_string($entry['path'] ?? null)) {
            throw new \RuntimeException('evidence record is absent or malformed');
        }
        $bundle = $entry['bundle'];
        if (($bundle['format'] ?? null) === ScopedCertificationBundle::FORMAT) {
            throw new \RuntimeException(
                'scoped certification bundle v1 is historical-only; closure-v2 is required for current claims'
            );
        }
        $expectedPath = 'scoped/' . $subject['kind'] . 's/' . $subject['name'] . '/' . ($bundle['bundle_digest'] ?? '');
        if ($entry['path'] !== $expectedPath) {
            throw new \RuntimeException('evidence record durable path is not canonical');
        }
        $bundleDir = $index->root() . '/manifests/capabilities/' . $entry['path'];
        $bundleFile = $bundleDir . '/bundle.json';
        if (!is_file($bundleFile) || is_link($bundleFile)
            || Canon::read_file($bundleFile) !== Canon::encode($bundle)) {
            throw new \RuntimeException('durable evidence bundle is absent or does not match its index record');
        }
        ScopedCertificationBundle::assertEvidenceAssets($bundle, $bundleDir);
        ScopedCertificationBundle::assertGitRevisionInputs($index->root(), $bundle);
        $subjectDigest = EvidenceSubjectIdentity::digest(
            $subject['kind'],
            $subject['name'],
            $subject['manifest'],
            $subject['claim'],
            $index->root() . '/manifests'
        );
        $currentInputs = EvidenceInputClosure::current(
            $index->root(),
            $subject['kind'],
            $subject['name'],
            $subject['manifest'],
            $subject['tests']
        );
        $artifacts = ScopedCertificationBundle::subjectArtifacts(
            $index->root(),
            $subject['kind'],
            $subject['name'],
            $subject['manifest'],
            $subject['tests']
        );
        ScopedCertificationBundle::assertCurrent(
            $bundle,
            $subject['kind'],
            $subject['name'],
            $subjectDigest,
            $index->platform(),
            $currentInputs,
            $subject['tests'],
            $subject['claim'],
            $artifacts
        );
    }

    private static function diagnostic(string $message): string {
        $message = strtolower($message);
        foreach ([
            'historical-only' => 'legacy_closure_v1',
            'absent' => 'absent',
            'malformed' => 'malformed',
            'not current' => 'identity_or_closure_changed',
            'not match' => 'identity_or_closure_changed',
            'corrupt' => 'corrupt',
            'does not match' => 'identity_or_closure_changed',
        ] as $needle => $code) {
            if (str_contains($message, $needle)) {
                return $code;
            }
        }
        return 'verification_failed';
    }
}

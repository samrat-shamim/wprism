<?php
declare(strict_types=1);

namespace Duo;

require_once __DIR__ . '/EvidenceSubjectIndex.php';
require_once __DIR__ . '/EvidenceInputClosure.php';

/**
 * Deterministic, read-only impact projection for scoped subject evidence.
 */
final class EvidenceImpactReport {
    public const FORMAT = 'duo-evidence-impact-report/v1';

    /** @return array<string,mixed> */
    public static function build(string $root, string $baseSha, string $headSha): array {
        self::sha($baseSha, 'base SHA');
        self::sha($headSha, 'head SHA');
        $root = realpath($root) ?: '';
        if ($root === '' || !is_dir($root) || is_link($root)) {
            throw new \RuntimeException('evidence impact: repository root is absent or unsafe');
        }

        self::git($root, ['cat-file', '-e', $baseSha . '^{commit}'], 'base SHA');
        self::git($root, ['cat-file', '-e', $headSha . '^{commit}'], 'head SHA');
        $checkedOut = trim(self::git($root, ['rev-parse', 'HEAD'], 'checked-out HEAD')['stdout']);
        if ($checkedOut !== $headSha) {
            throw new \RuntimeException(
                "evidence impact: checked-out HEAD '$checkedOut' does not equal requested head '$headSha'"
            );
        }
        $mergeBase = trim(self::git($root, ['merge-base', $baseSha, $headSha], 'merge base')['stdout']);
        $diff = self::git($root, [
            // Disable rename pairing so a deleted closure member and its new
            // replacement are both visible to the impact calculation.
            'diff', '--name-only', '-z', '--no-renames', '--diff-filter=ACDMRTUXB', $baseSha, $headSha,
        ], 'changed paths')['stdout'];
        $changed = self::nulSeparated($diff);

        $index = EvidenceSubjectIndex::load($root);
        $subjects = [];
        $affected = [];
        foreach ($index->certifiedSubjects() as $subject) {
            $closure = EvidenceInputClosure::paths(
                $root,
                $subject['kind'],
                $subject['name'],
                $subject['manifest'],
                $subject['tests']
            );
            $matched = EvidenceInputClosure::changedMembers($changed, $closure);
            $closureDigest = hash('sha256', Canon::encode($closure));
            $row = [
                'key' => $subject['key'],
                'kind' => $subject['kind'],
                'name' => $subject['name'],
                'closure_digest' => $closureDigest,
                'matched_paths' => $matched,
                'affected' => $matched !== [],
            ];
            $subjects[] = $row;
            if ($matched !== []) {
                $affected[] = $subject['key'];
            }
        }
        usort($subjects, static fn(array $a, array $b): int => strcmp($a['key'], $b['key']));
        sort($affected, SORT_STRING);
        sort($changed, SORT_STRING);

        return [
            'format' => self::FORMAT,
            'base_sha' => $baseSha,
            'head_sha' => $headSha,
            'merge_base_sha' => $mergeBase,
            'checked_out_head' => $checkedOut,
            'changed_paths' => $changed,
            'changed_paths_sha256' => hash('sha256', Canon::encode($changed)),
            'closure_authority' => EvidenceInputClosure::AUTHORITY,
            'subjects' => $subjects,
            'affected_subjects' => $affected,
            'review' => [
                'reviewed_impact_set' => $affected,
                'release_eligibility' => $affected === [] ? 'releasable' : 'non_releasable',
                'requires_fresh_certification' => $affected,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function decode(string $raw, string $label = 'evidence impact report'): array {
        $report = Canon::decode($raw);
        self::validate($report, $label);
        return $report;
    }

    /** @param array<string,mixed> $report */
    public static function validate(array $report, string $label = 'evidence impact report'): void {
        $keys = array_keys($report);
        sort($keys, SORT_STRING);
        $expectedKeys = [
            'affected_subjects', 'base_sha', 'changed_paths', 'changed_paths_sha256',
            'checked_out_head', 'closure_authority', 'format', 'head_sha', 'merge_base_sha',
            'review', 'subjects',
        ];
        sort($expectedKeys, SORT_STRING);
        if (($report['format'] ?? null) !== self::FORMAT
            || $keys !== $expectedKeys
            || !self::shaValue($report['base_sha'] ?? null)
            || !self::shaValue($report['head_sha'] ?? null)
            || !self::shaValue($report['merge_base_sha'] ?? null)
            || ($report['checked_out_head'] ?? null) !== $report['head_sha']
            || !is_array($report['changed_paths'] ?? null)
            || !array_is_list($report['changed_paths'])
            || !self::stringPaths($report['changed_paths'])
            || !self::digestValue($report['changed_paths_sha256'] ?? null)
            || ($report['closure_authority'] ?? null) !== ScopedCertificationBundle::FORMAT
            || !is_array($report['subjects'] ?? null)
            || !array_is_list($report['subjects'])
            || !is_array($report['affected_subjects'] ?? null)
            || !array_is_list($report['affected_subjects'])
            || !self::subjectKeys($report['affected_subjects'])
            || !is_array($report['review'] ?? null)
            || ($report['review']['reviewed_impact_set'] ?? null) !== $report['affected_subjects']
            || ($report['review']['requires_fresh_certification'] ?? null) !== $report['affected_subjects']
            || !in_array($report['review']['release_eligibility'] ?? null, ['releasable', 'non_releasable'], true)) {
            throw new \RuntimeException("$label is malformed");
        }
        $reviewKeys = array_keys($report['review']);
        sort($reviewKeys, SORT_STRING);
        $expectedReviewKeys = ['release_eligibility', 'requires_fresh_certification', 'reviewed_impact_set'];
        sort($expectedReviewKeys, SORT_STRING);
        if ($reviewKeys !== $expectedReviewKeys) {
            throw new \RuntimeException("$label review is not a closed object");
        }
        if (!hash_equals($report['changed_paths_sha256'], hash('sha256', Canon::encode($report['changed_paths'])))) {
            throw new \RuntimeException("$label changed-path digest is corrupt");
        }
        $changed = $report['changed_paths'];
        $sortedChanged = $changed;
        sort($sortedChanged, SORT_STRING);
        if ($changed !== $sortedChanged || count($changed) !== count(array_unique($changed, SORT_STRING))) {
            throw new \RuntimeException("$label changed paths are not in canonical order");
        }
        $expectedEligibility = $report['affected_subjects'] === [] ? 'releasable' : 'non_releasable';
        if (($report['review']['release_eligibility'] ?? null) !== $expectedEligibility) {
            throw new \RuntimeException("$label release eligibility disagrees with its impact set");
        }
        $keys = [];
        foreach ($report['subjects'] as $subject) {
            $subjectKeys = is_array($subject) ? array_keys($subject) : [];
            sort($subjectKeys, SORT_STRING);
            $expectedSubjectKeys = ['affected', 'closure_digest', 'key', 'kind', 'matched_paths', 'name'];
            sort($expectedSubjectKeys, SORT_STRING);
            if (!is_array($subject) || !is_string($subject['key'] ?? null)
                || $subjectKeys !== $expectedSubjectKeys
                || !self::subjectKey($subject['key'])
                || !in_array($subject['kind'] ?? null, ['manifest', 'profile'], true)
                || !is_string($subject['name'] ?? null)
                || $subject['key'] !== (($subject['kind'] === 'manifest' ? 'manifests.' : 'profiles.') . $subject['name'])
                || !self::digestValue($subject['closure_digest'] ?? null)
                || !is_array($subject['matched_paths'] ?? null)
                || !array_is_list($subject['matched_paths'])
                || !self::stringPaths($subject['matched_paths'])
                || !is_bool($subject['affected'] ?? null)
                || $subject['affected'] !== ($subject['matched_paths'] !== [])) {
                throw new \RuntimeException("$label contains a malformed subject row");
            }
            $matched = $subject['matched_paths'];
            $sortedMatched = $matched;
            sort($sortedMatched, SORT_STRING);
            if ($matched !== $sortedMatched
                || count($matched) !== count(array_unique($matched, SORT_STRING))
                || array_values(array_intersect($matched, $report['changed_paths'])) !== $matched) {
                throw new \RuntimeException("$label contains an inconsistent subject path projection");
            }
            $keys[] = $subject['key'];
        }
        sort($keys, SORT_STRING);
        if ($keys !== array_values(array_unique($keys, SORT_STRING))) {
            throw new \RuntimeException("$label contains duplicate subject rows");
        }
        $rowKeys = array_map(static fn(array $row): string => $row['key'], $report['subjects']);
        if ($rowKeys !== $keys) {
            throw new \RuntimeException("$label subject rows are not in canonical order");
        }
        $affected = array_values(array_filter($report['subjects'], static fn(array $row): bool => $row['affected']));
        $affectedKeys = array_map(static fn(array $row): string => $row['key'], $affected);
        sort($affectedKeys, SORT_STRING);
        $declared = $report['affected_subjects'];
        $sortedDeclared = $declared;
        sort($sortedDeclared, SORT_STRING);
        if ($declared !== $sortedDeclared || $affectedKeys !== $declared) {
            throw new \RuntimeException("$label affected-subject projection is inconsistent");
        }
    }

    /** @param list<mixed> $paths */
    private static function stringPaths(array $paths): bool {
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '' || str_starts_with($path, '/')
                || str_contains($path, "\0") || str_contains($path, '\\')
                || str_contains($path, '//') || in_array('.', explode('/', $path), true)
                || in_array('..', explode('/', $path), true)) {
                return false;
            }
        }
        return true;
    }

    /** @param list<mixed> $keys */
    private static function subjectKeys(array $keys): bool {
        $previous = null;
        foreach ($keys as $key) {
            if (!is_string($key) || !self::subjectKey($key) || ($previous !== null && strcmp($previous, $key) >= 0)) {
                return false;
            }
            $previous = $key;
        }
        return true;
    }

    private static function subjectKey(string $key): bool {
        return preg_match('/^(manifests|profiles)\\.[a-z][a-z0-9-]*$/D', $key) === 1;
    }

    private static function sha(string $value, string $label): void {
        if (!self::shaValue($value)) {
            throw new \RuntimeException("evidence impact: $label must be a lowercase 40-hex commit SHA");
        }
    }

    private static function shaValue(mixed $value): bool {
        return is_string($value) && preg_match('/^[0-9a-f]{40}$/D', $value) === 1;
    }

    private static function digestValue(mixed $value): bool {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
    }

    /** @return list<string> */
    private static function nulSeparated(string $value): array {
        if ($value === '') {
            return [];
        }
        $paths = array_values(array_filter(explode("\0", $value), static fn(string $path): bool => $path !== ''));
        foreach ($paths as $path) {
            if (str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0")) {
                throw new \RuntimeException('evidence impact: Git returned an unsafe path');
            }
        }
        return $paths;
    }

    /** @param list<string> $args @return array{stdout:string,stderr:string,exit:int} */
    private static function git(string $root, array $args, string $label): array {
        $pipes = [];
        $process = proc_open(
            array_merge(['git', '-C', $root], $args),
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new \RuntimeException("evidence impact: could not start Git for $label");
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            throw new \RuntimeException("evidence impact: Git $label failed: " . trim($stderr));
        }
        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit];
    }
}

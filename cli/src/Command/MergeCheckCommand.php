<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/CommandOutput.php';
require_once __DIR__ . '/../Refresh/MergeCheck.php';
require_once __DIR__ . '/../Refresh/RefreshFieldDiff.php';

/**
 * Host command boundary for `duo merge-check`.
 *
 * ## The exit-code contract is the deliverable
 *
 * A customer's CI binds to these four codes, so they are stated once here and
 * asserted table-driven in
 * `sandbox/tests/offline/refresh/regress_merge_check.php`:
 *
 *   0  the tree compiled and is coherent; in compare mode, zero conflicts
 *   1  refusal — the tree does not compile, or a ref does not resolve
 *   2  usage error, matching the env-free verb family
 *      (ManifestValidate.php:863-866, AdapterDraft.php, AdapterCatalog.php)
 *   3  the tree compiled and the plan is valid, but conflicting entries need
 *      a human or code-version skew requires code-first migration/recapture
 *
 * 3 is deliberately an ANSWER, not a refusal, and that distinction is the
 * whole point: under `--format=json` exit 3 emits the SUCCESS document with
 * `verdict: "conflicts"` or `verdict: "code_skew"`, never a
 * `duo-command-refusal/v1` envelope. A CI job can therefore treat 1 as "my
 * pipeline is broken" and 3 as "this merge is not safe yet" without parsing
 * prose. 3 was verified unused elsewhere in `cli/` before it was claimed.
 */
final class MergeCheckCommand {
    /**
     * `--field-diff` is deliberately absent, not accepted-and-refused: an
     * advisory plan has no `production_snapshot_hash`, and
     * `RefreshFieldDiff::project()` requires one (RefreshFieldDiff.php:97-100).
     * The reasoning is recorded in full at MergeCheck.php:241-259.
     */
    private const FLAGS = ['--ref', '--against', '--base', '--format'];

    private const CATEGORIES = ['unchanged', 'production-only', 'branch-only', 'compatible', 'conflicting'];

    /** @param list<string> $args */
    public static function run(array $args): int {
        // Detected from the raw argv before parsing, exactly as
        // RefreshCommand.php:24 does: a caller that asked for machine output
        // must get one parseable envelope even for the refusal that fires
        // while its own flags are still being read.
        $json = CommandOutput::wantsAgentRefusalJson('merge-check', $args);
        try {
            $flags = self::flags($args);
        } catch (\RuntimeException $e) {
            return self::refuse(
                $json,
                MergeCheck::EXIT_USAGE,
                'invalid_arguments',
                $e->getMessage(),
                'run `duo merge-check [--ref=<ref>] [--against=<ref>] [--base=<ref>] [--format=json]`'
            );
        }
        $json = ($flags['--format'] ?? null) === 'json';

        try {
            $document = MergeCheck::run([
                'against' => $flags['--against'] ?? null,
                'base' => $flags['--base'] ?? null,
                'ref' => $flags['--ref'] ?? null,
            ]);
        } catch (MergeCheckRefusal $refusal) {
            return self::refuse(
                $json,
                $refusal->reasonCode === 'invalid_arguments' ? MergeCheck::EXIT_USAGE : MergeCheck::EXIT_REFUSED,
                $refusal->reasonCode,
                $refusal->getMessage(),
                $refusal->remediation,
                $refusal->diagnostic
            );
        } catch (\Throwable $e) {
            return self::refuse(
                $json,
                MergeCheck::EXIT_REFUSED,
                'merge_check_failed',
                'merge-check could not complete against this repository',
                'read the diagnostic below, resolve it, and rerun merge-check',
                $e->getMessage()
            );
        }

        $exit = (int) ($document['exit_code'] ?? MergeCheck::EXIT_OK);
        if ($json) {
            // Canon::encode() already terminates with LF (Canon.php:102).
            echo \Duo\Canon::encode($document);
            return $exit;
        }
        self::render($document);
        return $exit;
    }

    /**
     * One refusal channel for both output modes.
     *
     * The `$code` is passed rather than taken from `renderRefusalJson()`,
     * which always returns 1: a usage error is 2 in this verb family, and the
     * envelope's job is to describe the refusal, not to decide its code.
     */
    private static function refuse(
        bool $json,
        int $code,
        string $reason,
        string $message,
        string $remediation,
        ?string $diagnostic = null
    ): int {
        if ($json) {
            CommandOutput::renderRefusalJson(
                'merge-check',
                $reason,
                $message,
                $remediation,
                $diagnostic === null ? [] : [['code' => $reason, 'detail' => $diagnostic]]
            );
            return $code;
        }
        fwrite(STDERR, 'duo: merge-check: ' . $message . "\n");
        if ($diagnostic !== null && trim($diagnostic) !== '') {
            foreach (explode("\n", rtrim($diagnostic, "\n")) as $line) {
                fwrite(STDERR, '  ' . $line . "\n");
            }
        }
        fwrite(STDERR, 'duo: merge-check: remedy: ' . $remediation . "\n");
        return $code;
    }

    /** @param array<string,mixed> $document */
    private static function render(array $document): void {
        echo 'merge-check: ' . (string) ($document['verdict'] ?? '?') . "\n";
        $refs = is_array($document['refs'] ?? null) ? $document['refs'] : [];
        if (($document['mode'] ?? null) === 'validate') {
            echo 'ref=' . (string) ($refs['left'] ?? '?') . "\n";
        } else {
            echo 'base=' . (string) ($refs['base'] ?? '?')
                . ' left=' . (string) ($refs['left'] ?? '?')
                . ' right=' . (string) ($refs['right'] ?? '?') . "\n";
        }
        $coherence = is_array($document['coherence'] ?? null) ? $document['coherence'] : [];
        echo 'compiled: site_hash=' . (string) ($coherence['site_hash'] ?? '?')
            . ' manifest_hash=' . (string) ($coherence['manifest_hash'] ?? '?')
            . ' artifact_hash=' . (string) ($coherence['artifact_hash'] ?? '?') . "\n";

        $plan = is_array($document['plan'] ?? null) ? $document['plan'] : null;
        if ($plan !== null) {
            echo 'plan_hash=' . (string) ($plan['plan_hash'] ?? '?') . "\n";
            $counts = is_array($document['counts'] ?? null) ? $document['counts'] : [];
            $parts = [];
            foreach (self::CATEGORIES as $category) {
                $parts[] = $category . '=' . (int) ($counts[$category] ?? 0);
            }
            echo 'categories: ' . implode(' ', $parts) . "\n";
            foreach ((array) ($plan['entries'] ?? []) as $entry) {
                if (!is_array($entry) || ($entry['category'] ?? null) !== 'conflicting') {
                    continue;
                }
                // Same label seam and the same quoting as `duo refresh`
                // (RefreshCommand::renderPlan): one conflict row vocabulary
                // across both verbs, so an operator reads one thing.
                $label = RefreshFieldDiff::localPlanEntryLabel($entry);
                $suffix = $label === '' ? '' : ' ' . json_encode(
                    $label,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                );
                echo 'conflict ' . (string) ($entry['id'] ?? '?') . $suffix . ': '
                    . (string) ($entry['reason'] ?? 'semantic divergence') . "\n";
            }
        }

        foreach ((array) ($document['code_skew'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            echo 'blocked: code skew ' . (string) ($row['component'] ?? '?')
                . ' left=' . (string) ($row['left_version'] ?? 'absent')
                . ' right=' . (string) ($row['right_version'] ?? 'absent') . "\n";
        }
        if ((array) ($document['code_skew'] ?? []) !== []) {
            // DESIGN.md:125's own remedy, verbatim, because the operator
            // reading this warning is about to decide an ordering.
            echo "blocked: remedy: merge code first, run migrations, re-capture, then merge state\n";
        }
        if ($plan !== null) {
            echo 'advisory: this plan carries no production authority; '
                . "run duo rebase <production-env> --production-ref=<ref> to materialize\n";
        }
    }

    /**
     * The same `array_diff(array_keys(...))` strictness as
     * RefreshCommand::flags(): an unrecognized flag is a usage error, never a
     * silently ignored one, because this verb's whole value is that its exit
     * code can be trusted.
     *
     * `--scope-contract` is deliberately absent. A scoped plan needs P to
     * carry `duo-refresh-scope/v1` evidence whose `scope_hash` matches the
     * contract (RefreshPlan.php:349-353), and only a live scoped
     * `refresh-export` can produce that — so a merge-check that accepted the
     * flag could only ever refuse it. Recorded as a non-claim rather than
     * shipped as a flag that never works.
     *
     * @param list<string> $args
     * @return array<string,mixed>
     */
    private static function flags(array $args): array {
        $flags = [];
        foreach ($args as $arg) {
            if (!is_string($arg) || !str_starts_with($arg, '--') || !str_contains($arg, '=')) {
                throw new \RuntimeException('expected --name=value flags');
            }
            [$name, $value] = explode('=', $arg, 2);
            if (!in_array($name, self::FLAGS, true) || $value === '') {
                throw new \RuntimeException("unsupported or empty flag '$arg'");
            }
            if ($name === '--format' && $value !== 'json') {
                throw new \RuntimeException('--format must be json');
            }
            if (isset($flags[$name])) {
                throw new \RuntimeException("duplicate flag '$name'");
            }
            $flags[$name] = $value;
        }
        if (array_diff(array_keys($flags), self::FLAGS) !== []) {
            throw new \RuntimeException('unsupported flag');
        }
        return $flags;
    }
}

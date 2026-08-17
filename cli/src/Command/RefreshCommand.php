<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/CommandOutput.php';
require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/PassthroughCommand.php';
require_once __DIR__ . '/../Refresh/Refresh.php';
require_once __DIR__ . '/../Refresh/RefreshFieldDiff.php';

/** Host command boundary for semantic refresh planning and field-diff output. */
final class RefreshCommand {
    /** @param list<string> $extra */
    public static function run(EnvironmentDriver $driver, array $extra): int {
        $json = CommandOutput::wantsAgentRefusalJson('refresh', $extra);
        $fieldDiff = count(array_filter($extra, static fn(string $arg): bool =>
            $arg === '--field-diff' || str_starts_with($arg, '--field-diff='))) > 0;
        $refusal = self::refusal('invalid_arguments');
        try {
            $flags = self::flags($extra);
            $fieldDiff = ($flags['--field-diff'] ?? false) === true;
            $json = ($flags['--format'] ?? null) === 'json';
            if (!isset($flags['--production-ref'])
                || array_diff(array_keys($flags), ['--production-ref', '--scope-contract', '--field-diff', '--format']) !== []
                || ($json && !$fieldDiff)) {
                throw new \RuntimeException('duo refresh requires --production-ref=<ref>, optional --scope-contract=<local-path>, and --field-diff with optional --format=json');
            }
            if ($fieldDiff && isset($flags['--scope-contract'])) {
                $refusal = self::refusal('scoped_unsupported');
                throw new \RuntimeException('field-level diff is unavailable for scoped refresh; use the legacy whole-record resolver');
            }
            if ($fieldDiff) {
                $refusal = self::refusal('field_level_unavailable');
            }
            $scope = isset($flags['--scope-contract'])
                ? PassthroughCommand::readScopeContractInput($flags['--scope-contract'])['contract']
                : null;
            $result = Refresh::refresh($driver, $flags['--production-ref'], $scope, $fieldDiff);
            if ($fieldDiff) {
                if (!is_array($result['field_diff'] ?? null)) {
                    throw new \RuntimeException('field-level resolution is unavailable for this refresh plan');
                }
                if ($json) {
                    echo \Duo\Canon::encode($result['field_diff']);
                    return 0;
                }
                self::renderFieldDiff($result['field_diff']);
                return 0;
            }
            self::renderPlan($result);
            return 0;
        } catch (\Throwable $e) {
            if ($json) {
                return CommandOutput::renderRefusalJson('refresh', $refusal['reason'], $refusal['message'], $refusal['remediation']);
            }
            if ($fieldDiff) {
                fwrite(STDERR, 'duo: refresh: ' . $refusal['message'] . "\n");
                fwrite(STDERR, 'duo: refresh: remedy: ' . $refusal['remediation'] . "\n");
                return 1;
            }
            fwrite(STDERR, 'duo: refresh: ' . $e->getMessage() . "\n");
            return 1;
        }
    }

    /** @param array<string,mixed> $result */
    private static function renderPlan(array $result): void {
        echo 'refresh plan: ' . ($result['plan_path'] ?? '?') . "\n";
        echo 'base=' . ($result['context']['base_commit'] ?? '?')
            . ' production=' . ($result['context']['production_commit'] ?? '?')
            . ' branch=' . ($result['context']['branch_commit'] ?? '?') . "\n";
        echo 'plan_hash=' . ($result['plan']['plan_hash'] ?? '?') . "\n";
        $counts = $result['plan']['scope_counts'] ?? ($result['plan']['counts'] ?? []);
        if (is_array($counts)) {
            $parts = [];
            foreach (['unchanged', 'production-only', 'branch-only', 'compatible', 'conflicting'] as $category) {
                $parts[] = $category . '=' . (int) ($counts[$category] ?? 0);
            }
            echo 'categories: ' . implode(' ', $parts) . "\n";
        }
        foreach ((array) ($result['plan']['entries'] ?? []) as $entry) {
            if (($entry['category'] ?? null) === 'conflicting' && ($entry['in_scope'] ?? true) === true) {
                $label = RefreshFieldDiff::localPlanEntryLabel($entry);
                $labelSuffix = $label === '' ? '' : ' ' . json_encode(
                    $label,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                );
                echo 'conflict ' . ($entry['id'] ?? '?') . $labelSuffix . ': '
                    . ($entry['reason'] ?? 'semantic divergence') . "\n";
            }
        }
    }

    /** @return array<string,mixed> */
    private static function flags(array $extra): array {
        $flags = [];
        foreach ($extra as $arg) {
            if ($arg === '--field-diff') {
                if (isset($flags[$arg])) throw new \RuntimeException("duo refresh: duplicate flag '$arg'");
                $flags[$arg] = true;
                continue;
            }
            if (!is_string($arg) || !str_starts_with($arg, '--') || !str_contains($arg, '=')) {
                throw new \RuntimeException('duo refresh: expected --field-diff or --name=value flags');
            }
            [$name, $value] = explode('=', $arg, 2);
            if (!in_array($name, ['--production-ref', '--scope-contract', '--format'], true) || $value === '') {
                throw new \RuntimeException("duo refresh: unsupported or empty flag '$arg'");
            }
            if ($name === '--format' && $value !== 'json') {
                throw new \RuntimeException('duo refresh: --format must be json');
            }
            if (isset($flags[$name])) throw new \RuntimeException("duo refresh: duplicate flag '$name'");
            $flags[$name] = $value;
        }
        return $flags;
    }

    /** @return array{reason:string,message:string,remediation:string} */
    private static function refusal(string $reason): array {
        return match ($reason) {
            'scoped_unsupported' => [
                'reason' => 'scoped_unsupported',
                'message' => 'the redacted field-level change diff is unavailable for scoped refresh',
                'remediation' => 'use the legacy whole-record resolver for this scope, then retry without --field-diff',
            ],
            'field_level_unavailable' => [
                'reason' => 'field_level_unavailable',
                'message' => 'the redacted field-level change diff is unavailable for this refresh plan',
                'remediation' => 'verify the production target is reachable, clean, at --production-ref, and supports refresh-export; then regenerate the matching redacted field diff, align policy evidence, or use the legacy whole-record resolver',
            ],
            default => [
                'reason' => 'invalid_arguments',
                'message' => 'refresh arguments are invalid for redacted field-level change output',
                'remediation' => 'supply --production-ref and --field-diff; use --format=json only with --field-diff',
            ],
        };
    }

    /** Render only schema-closed, redacted field-diff relations for humans. */
    private static function renderFieldDiff(array $diff): void {
        $entities = ['post', 'term', 'attachment', 'menu', 'sidebar', 'options', 'user_meta', 'typed_table', 'record'];
        $fields = ['post.author', 'post.comment_status', 'post.excerpt', 'post.menu_order', 'post.parent', 'post.ping_status', 'post.publication', 'post.title', 'post.modification', 'term.description', 'term.name', 'term.parent', 'record'];
        $categories = ['unchanged', 'production-only', 'branch-only', 'compatible', 'conflicting'];
        $states = ['present', 'tombstone', 'absent'];
        $comparisons = ['same', 'different'];
        $reasons = ['eligible_engine_fields', 'scoped_record', 'production_omitted', 'absence_or_tombstone', 'option_or_state_witness', 'opaque_record_type', 'routing_changed', 'unsupported_document_shape', 'attachment_media', 'document_structure_changed', 'opaque_or_structural_field', 'derived_field_policy', 'opaque_container', 'body_changed'];
        $selector = static fn(mixed $value): string => is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : '?';
        $hash = static fn(mixed $value): string => is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : '?';
        $closed = static fn(mixed $value, array $allowed, string $fallback): string => is_string($value) && in_array($value, $allowed, true) ? $value : $fallback;
        $relation = static function (mixed $value) use ($closed, $states, $comparisons): string {
            if (!is_array($value)) return 'B=? P=? W=? W/B=? P/B=? W/P=?';
            return 'B=' . $closed($value['base'] ?? null, $states, '?') . ' P=' . $closed($value['production'] ?? null, $states, '?') . ' W=' . $closed($value['branch'] ?? null, $states, '?') . ' W/B=' . $closed($value['branch_vs_base'] ?? null, $comparisons, '?') . ' P/B=' . $closed($value['production_vs_base'] ?? null, $comparisons, '?') . ' W/P=' . $closed($value['branch_vs_production'] ?? null, $comparisons, '?');
        };
        echo "refresh field diff\nvalues omitted; display-only/non-authorizing; ours=branch, theirs=production\n";
        echo 'diff_hash=' . $hash($diff['diff_hash'] ?? null) . "\n";
        foreach ((array) ($diff['records'] ?? []) as $record) {
            if (!is_array($record)) continue;
            $entity = $closed($record['entity'] ?? null, $entities, 'record');
            $reason = $closed($record['reason'] ?? null, $reasons, 'opaque_record_type');
            echo $entity . ' ' . $selector($record['record_selector_sha256'] ?? null) . ': ' . $reason . "\n";
            foreach ((array) ($record['changes'] ?? []) as $change) {
                if (!is_array($change)) continue;
                $field = $closed($change['field'] ?? null, $fields, 'record');
                $category = $closed($change['category'] ?? null, $categories, 'conflicting');
                $scope = ($change['scope'] ?? null) === 'field' ? 'field' : 'record';
                $changeReason = $closed($change['reason'] ?? $reason, $reasons, $reason);
                echo '  ' . $field . ' ' . $category . ' ' . $scope . ' ' . $changeReason . ' ' . $relation($change['relation'] ?? null) . "\n";
            }
        }
    }
}

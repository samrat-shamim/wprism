<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform;

/**
 * Deterministic JUnit and TAP projections of canonical runner results.
 *
 * @phpstan-type ReportResult array{
 *     id:string,
 *     state:string,
 *     duration_ms:int,
 *     message:?string
 * }
 */
final class Reports
{
    /** @param list<ReportResult> $results */
    public static function junit(array $results): string
    {
        $failures = count(array_filter($results, static fn(array $row): bool => $row['state'] === 'fail'));
        $errors = count(array_filter($results, static fn(array $row): bool => $row['state'] === 'infra_error'));
        $skipped = count(array_filter($results, static fn(array $row): bool => $row['state'] === 'not_applicable'));
        $duration = array_sum(array_column($results, 'duration_ms')) / 1000;
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            sprintf(
                '<testsuite name="duo" tests="%d" failures="%d" errors="%d" skipped="%d" time="%.3f">',
                count($results),
                $failures,
                $errors,
                $skipped,
                $duration,
            ),
        ];
        foreach ($results as $result) {
            $id = self::xml($result['id']);
            $time = $result['duration_ms'] / 1000;
            if ($result['state'] === 'pass') {
                $lines[] = sprintf('  <testcase classname="duo.catalog" name="%s" time="%.3f"/>', $id, $time);
                continue;
            }
            $lines[] = sprintf('  <testcase classname="duo.catalog" name="%s" time="%.3f">', $id, $time);
            $message = self::xml($result['message'] ?? $result['state']);
            $lines[] = match ($result['state']) {
                'fail' => '    <failure message="' . $message . '"/>',
                'infra_error' => '    <error message="' . $message . '"/>',
                default => '    <skipped message="' . $message . '"/>',
            };
            $lines[] = '  </testcase>';
        }
        $lines[] = '</testsuite>';
        return implode("\n", $lines) . "\n";
    }

    /** @param list<ReportResult> $results */
    public static function tap(array $results): string
    {
        $lines = ['TAP version 13', '1..' . count($results)];
        foreach ($results as $index => $result) {
            $number = $index + 1;
            $id = (string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', $result['id']);
            if ($result['state'] === 'pass') {
                $lines[] = "ok $number - $id";
            } elseif ($result['state'] === 'not_applicable') {
                $lines[] = "ok $number - $id # SKIP selector proved not applicable";
            } else {
                $lines[] = "not ok $number - $id";
                $message = (string) preg_replace('/[\r\n]+/', ' ', $result['message'] ?? $result['state']);
                $lines[] = '  ---';
                $lines[] = '  state: ' . $result['state'];
                $lines[] = '  message: ' . json_encode($message, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $lines[] = '  ...';
            }
        }
        return implode("\n", $lines) . "\n";
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}

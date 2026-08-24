<?php
namespace Duo;

/**
 * Canonical constructor for suspicious-reference lint results.
 *
 * The linter's public output deliberately omits `matches` when its lookup is
 * unknown: callers distinguish an unresolved but still-dangerous raw value
 * from a value that happened to coincide with a known live entity. Keeping
 * that optional field rule in one pure constructor prevents scanners from
 * drifting into subtly different result shapes as more surfaces move behind
 * a registry.
 */
final class LintFinding {
    /** @return array{class:string,path:string,locator:string,value:mixed,matches?:array,note:string} */
    public static function make(
        string $class,
        string $path,
        string $locator,
        $value,
        ?array $matches,
        string $note
    ): array {
        $finding = [
            'class' => $class,
            'path' => $path,
            'locator' => $locator,
            'value' => $value,
            'note' => $note,
        ];
        if ($matches !== null) {
            $finding['matches'] = $matches;
        }
        return $finding;
    }

    /**
     * The human rendering of a finding set: two lines per finding, in scan
     * order. Reached through `Lint::render_lines()`, which is the name both
     * verbs call — see that method for why there is exactly one renderer.
     *
     * @param array<int,array<string,mixed>> $findings
     * @return list<string>
     */
    public static function render_lines(array $findings): array {
        $lines = [];
        foreach ($findings as $finding) {
            $value = is_scalar($finding['value'] ?? null)
                ? (string) $finding['value']
                : json_encode($finding['value'] ?? null, JSON_UNESCAPED_SLASHES);
            $match = isset($finding['matches'])
                ? sprintf(
                    ' matches=%s:%d "%s" (%s)',
                    $finding['matches']['kind'],
                    $finding['matches']['id'],
                    $finding['matches']['title'],
                    $finding['matches']['post_type']
                )
                : '';
            $lines[] = sprintf(
                '%-24s %-55s %-32s value=%s%s',
                $finding['class'],
                $finding['path'],
                $finding['locator'],
                $value,
                $match
            );
            $lines[] = '    ' . $finding['note'];
        }
        return $lines;
    }
}

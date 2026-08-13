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
}

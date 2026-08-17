<?php
namespace Duo;

/**
 * Pure manifest projection extracted from Policy::export_manifest()
 * (DUO-3348 slice 27).
 *
 * Policy remains the public facade that loads a site repository and supplies
 * its effective site policy. This collaborator owns only the writer half:
 * selecting matching classification rules and returning the same manifest
 * shaped value that `wp duo policy-to-manifest` serializes. It deliberately
 * receives the section vocabulary from Policy rather than restating it, so a
 * new classification surface cannot become writable in one path and absent
 * from the other.
 */
final class PolicyWriter {
    /**
     * Export matching site-policy classifications as a manifest-shaped array.
     *
     * @param array<string,mixed> $sitePolicy
     * @param list<string> $sections
     * @return array<string,mixed>
     */
    public static function export_manifest(
        array $sitePolicy,
        string $matchRegex,
        string $name,
        int $specVersion,
        array $sections
    ): array {
        $out = [
            'name' => $name,
            'spec_version' => $specVersion,
            'options' => [],
            'post_meta' => [],
            'term_meta' => [],
            'user_meta' => [],
        ];
        foreach ($sections as $section) {
            foreach ($sitePolicy[$section] ?? [] as $key => $rule) {
                $matched = @preg_match('/' . $matchRegex . '/', $key);
                if ($matched === false) {
                    throw new \RuntimeException("duo: invalid --match regex '$matchRegex'");
                }
                if ($matched === 1) {
                    $out[$section][$key] = $rule;
                }
            }
            $out[$section] = (object) $out[$section];
        }
        return $out;
    }
}

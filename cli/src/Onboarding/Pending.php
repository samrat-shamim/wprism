<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/**
 * Renders the JSON from `wp duo pending --format=json` (agent-side
 * Pending::scan(), agent/src/Review/Pending.php — task #12) into `duo pending`'s
 * human table. Also shared by `duo classify`'s interactive per-item blocks
 * (Triage.php), so evidence/ref-hint formatting reads identically in both
 * places. Confirmed shape, one list item per unclassified key:
 *   {section: 'options'|'post_meta'|'term_meta', key: string,
 *    proposal: 'authored'|'runtime'|null,
 *    evidence: {entities?: int, post_types?: string[],
 *               journal?: {n: int, surfaces: array<string,int>,
 *                          caps: array<string,int>, proposal: ?string}},
 *    ref_hint?: {kind: 'post'|'term', id: int, title: string,
 *                post_type: string},  // taxonomy name when kind is 'term'
 *    secret?: string}  // "hard:<label>" or "suspicious"
 * `surfaces`/`caps` are {name => count} maps, not lists -- the compact
 * evidence string below names surfaces only (via array_keys()), matching
 * the mission's "journal n=14 rest/admin" style rather than the agent's own
 * raw-text renderer, which additionally shows each surface's count.
 *
 * Every field access below is still defensive (missing/wrong-shaped keys
 * degrade to '-' rather than a fatal error) as cheap insurance against a
 * future shape change. Placeholders/arrows are plain ASCII ('-', '->'), not
 * Unicode glyphs: column widths below are computed with strlen() (bytes),
 * and a multi-byte glyph in a padded cell throws off every column after it
 * -- matches the rest of the CLI's output (Doctor.php, PlanSummary.php),
 * which is ASCII-only for the same reason.
 */
final class Pending {
    /** @param list<array<string, mixed>> $items @return array{lines: list<string>, ok: bool} */
    public static function render(array $items): array {
        $rows = [];
        foreach ($items as $it) {
            $section = self::str($it['section'] ?? null, '?');
            $key = self::str($it['key'] ?? null, '?');
            $evidence = is_array($it['evidence'] ?? null) ? $it['evidence'] : [];
            $refHint = is_array($it['ref_hint'] ?? null) ? $it['ref_hint'] : null;
            $rows[] = [
                'sectionKey' => "$section:$key",
                'proposal' => self::formatProposal($it),
                'evidence' => self::formatEvidence($evidence, $section),
                'refHint' => self::formatRefHint($refHint),
                'secret' => self::str($it['secret'] ?? null, ''),
            ];
        }

        $w1 = self::colWidth($rows, 'sectionKey', 'SECTION:KEY');
        $w2 = self::colWidth($rows, 'proposal', 'PROPOSAL');
        $w3 = self::colWidth($rows, 'evidence', 'EVIDENCE');

        $lines = [];
        $lines[] = sprintf("%-{$w1}s  %-{$w2}s  %-{$w3}s  %s", 'SECTION:KEY', 'PROPOSAL', 'EVIDENCE', 'REF-HINT');
        foreach ($rows as $r) {
            $line = sprintf("%-{$w1}s  %-{$w2}s  %-{$w3}s  %s", $r['sectionKey'], $r['proposal'], $r['evidence'], $r['refHint']);
            if ($r['secret'] !== '') {
                $line .= "  \033[1;31m[SECRET: {$r['secret']}]\033[0m";
            }
            $lines[] = $line;
        }
        $lines[] = '';
        $n = count($rows);
        $lines[] = "$n item(s) in the review queue. Run \`duo classify <env>\` to triage.";

        return ['lines' => $lines, 'ok' => true];
    }

    /** @param array<string, mixed> $it */
    public static function formatProposal(array $it): string {
        return self::str($it['proposal'] ?? null, '-');
    }

    /** @param array<string, mixed> $evidence */
    public static function formatEvidence(array $evidence, string $section): string {
        $parts = [];
        if (isset($evidence['entities'])) {
            $noun = match ($section) {
                'term_meta' => 'terms',
                'options' => 'entities',
                default => 'posts',
            };
            $types = is_array($evidence['post_types'] ?? null) ? $evidence['post_types'] : [];
            $typesStr = $types ? ' (' . implode(',', $types) . ')' : '';
            $parts[] = "{$evidence['entities']} {$noun}{$typesStr}";
        }
        foreach ([
            'taxonomies' => 'taxonomies',
            'owner_candidates' => 'owner',
            'value_shapes' => 'shapes',
        ] as $field => $label) {
            if (is_array($evidence[$field] ?? null) && $evidence[$field]) {
                $parts[] = $label . '=' . implode(',', $evidence[$field]);
            }
        }
        if (is_string($evidence['reason'] ?? null) && $evidence['reason'] !== '') {
            $parts[] = 'blocked=' . $evidence['reason'];
        }
        if (is_array($evidence['journal'] ?? null)) {
            $j = $evidence['journal'];
            $n = $j['n'] ?? 0;
            // surfaces/caps are {surface => count} maps (agent/src/Review/Pending.php
            // Pending::scan()'s journal evidence shape), not plain lists --
            // the compact view names surfaces only, so it's the keys.
            $surfaces = is_array($j['surfaces'] ?? null) ? implode('/', array_keys($j['surfaces'])) : '';
            $parts[] = rtrim("journal n=$n $surfaces");
        }
        return $parts ? implode('; ', $parts) : '-';
    }

    /** @param ?array<string, mixed> $rh */
    public static function formatRefHint(?array $rh): string {
        if ($rh === null) {
            return '-';
        }
        $kind = self::str($rh['kind'] ?? null, '?');
        $id = self::str($rh['id'] ?? null, '?');
        $title = self::str($rh['title'] ?? null, '');
        $s = "-> $kind #$id";
        if ($title !== '') {
            $s .= " '$title'";
        }
        $postType = self::str($rh['post_type'] ?? null, '');
        if ($postType !== '' && $postType !== $kind) {
            $s .= " ($postType)";
        }
        return $s;
    }

    private static function str(mixed $v, string $default): string {
        return (is_string($v) || is_int($v)) && $v !== '' ? (string) $v : $default;
    }

    /** @param list<array<string, string>> $rows */
    private static function colWidth(array $rows, string $col, string $header): int {
        $w = strlen($header);
        foreach ($rows as $r) {
            $w = max($w, strlen($r[$col]));
        }
        return $w;
    }
}

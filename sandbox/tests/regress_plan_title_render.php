<?php
/**
 * Offline regression — DUO-3345 (plan naming slice): plan rows that carry an
 * authored WordPress display name (`title` for posts, `name` for terms and
 * menus, projected by Apply::entity_display_title() into the row's `title`
 * key) must be rendered with that name beside the repository path, and rows
 * without one must render exactly as before. Pure PHP over the orchestrator
 * renderer; no WordPress or Docker. The agent-side line renderer
 * (agent/src/Cli.php) is the lockstep twin of this boundary and is proven
 * through the live product path by the core conformance check's plan-naming
 * scenario; this file pins the orchestrator half and the display rules:
 *
 *   1. the raw authored title is displayed in single quotes after the path;
 *   2. whitespace runs collapse so one plan row stays one output line
 *      (exactly-one-line assertions elsewhere depend on rows never wrapping);
 *   3. a row without a title renders byte-identically to the pre-DUO-3345
 *      form — absence of authored naming is never decorated or guessed.
 *
 * These assertions fail against the pre-DUO-3345 renderer, which showed only
 * identifier-bearing paths.
 */

require_once __DIR__ . '/../../cli/src/PlanSummary.php';

use Duo\Orchestrator\PlanSummary;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
    echo ($ok ? 'ok' : 'FAIL') . ": $message\n";
};

$empty = array_fill_keys([
    'create', 'update', 'adopt', 'unchanged', 'drift', 'conflict',
    'collision', 'delete', 'delete_conflict', 'deleted',
], []);
$empty['code_mismatch'] = [];

$titled = $empty;
$titled['conflict'] = [[
    'uuid' => '019fdf2e-5e44-7677-89d1-c5917d723001',
    'type' => 'page',
    'path' => 'posts/page/019fdf2e-5e44-7677-89d1-c5917d723001--about.md',
    'title' => 'About Our Store',
]];
$titled['drift'] = [[
    'uuid' => '019fdf2e-5e44-7677-89d1-c5917d723002',
    'type' => 'term',
    'path' => 'terms/product_cat/019fdf2e-5e44-7677-89d1-c5917d723002--sale.md',
    'title' => 'Sale  Items' . "\n" . 'Winter',
]];
$rendered = PlanSummary::render($titled);
$lines = implode("\n", $rendered['lines']);

$check(
    str_contains($lines, "posts/page/019fdf2e-5e44-7677-89d1-c5917d723001--about.md 'About Our Store'"),
    'a titled conflict row shows its WordPress name in single quotes after the path'
);
$check(
    str_contains($lines, "terms/product_cat/019fdf2e-5e44-7677-89d1-c5917d723002--sale.md 'Sale Items Winter'"),
    'display collapses whitespace runs and newlines so one row stays one line'
);
$newlineFree = true;
foreach ($rendered['lines'] as $line) {
    $newlineFree = $newlineFree && !str_contains($line, "\n");
}
$check($newlineFree, 'no rendered plan line embeds a newline (checked unconditionally across every line)');

$untitled = $empty;
$untitled['conflict'] = [[
    'uuid' => '019fdf2e-5e44-7677-89d1-c5917d723003',
    'type' => 'option',
    'path' => 'options/core.json',
]];
$renderedUntitled = PlanSummary::render($untitled);
$untitledLines = implode("\n", $renderedUntitled['lines']);
$check(
    str_contains($untitledLines, '  - options/core.json'),
    'a row without an authored name renders its path exactly as before'
);
$check(
    !str_contains($untitledLines, "options/core.json '"),
    'absence of an authored name is never decorated with a fabricated title'
);

$blank = $empty;
$blank['drift'] = [[
    'uuid' => '019fdf2e-5e44-7677-89d1-c5917d723004',
    'type' => 'page',
    'path' => 'posts/page/019fdf2e-5e44-7677-89d1-c5917d723004--blank.md',
    'title' => '',
]];
$renderedBlank = PlanSummary::render($blank);
$check(
    !str_contains(implode("\n", $renderedBlank['lines']), "--blank.md '"),
    'an empty title string renders as if absent'
);

$padded = $empty;
$padded['drift'] = [[
    'uuid' => '019fdf2e-5e44-7677-89d1-c5917d723005',
    'type' => 'page',
    'path' => 'posts/page/019fdf2e-5e44-7677-89d1-c5917d723005--padded.md',
    'title' => '  Padded Title  ',
]];
$check(
    str_contains(implode("\n", PlanSummary::render($padded)['lines']), "--padded.md 'Padded Title'"),
    'display trims the collapsed title; JSON keeps the raw authored value'
);

// The `duo status` boundary this renderer deliberately keeps (independent
// review of PR #148): clean create/update/adopt/unchanged/deleted rows are
// never itemized here — they exist only as summary-line counts — so a title
// on such a row must produce no itemized line. `wp duo plan`'s own renderer
// itemizes every bucket and is proven live by the core conformance
// plan-naming scenario. This case pins the boundary so the README's
// description of the asymmetry cannot silently drift.
$updateOnly = $empty;
$updateOnly['update'] = [[
    'uuid' => '019fdf2e-5e44-7677-89d1-c5917d723006',
    'type' => 'page',
    'path' => 'posts/page/019fdf2e-5e44-7677-89d1-c5917d723006--clean.md',
    'title' => 'Clean Update Title',
]];
$renderedUpdate = PlanSummary::render($updateOnly);
$check(
    !str_contains(implode("\n", $renderedUpdate['lines']), 'Clean Update Title'),
    'duo status keeps clean update rows as counts only; titles never conjure an itemized line'
);
$check(
    str_contains($renderedUpdate['lines'][0] ?? '', '1 update'),
    'the titled update row still counts on the frozen summary line'
);

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " plan title render check(s) failed\n");
    exit(1);
}
echo "PASS: plan rows speak WordPress names beside repository paths\n";

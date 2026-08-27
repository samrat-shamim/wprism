#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate/check docs/guides/adapter-authoring-limitations.md from tools/engine-gaps.json.
 *
 * Usage:
 *   php tools/engine-gap-doc.php generate   # rewrite docs/guides/adapter-authoring-limitations.md
 *   php tools/engine-gap-doc.php --check    # regenerate in memory, byte-compare, exit 1 on drift
 *   php tools/engine-gap-doc.php            # same as --check
 *
 * WHY THIS IS GENERATED, AND WHAT THE LEDGER BUYS THAT PROSE DID NOT
 * ------------------------------------------------------------------
 * The predecessor was a hand-written document: three rejected WordPress.org
 * candidates, each ending in a "Required platform work:" sentence naming the
 * primitives it needed in whatever words that reviewer chose. Prose cannot be
 * counted. Two candidates blocked on the SAME missing primitive read as two
 * separate paragraphs, so the document could only ever say what is missing —
 * never which missing thing is blocking the most work, which is the one
 * question a platform roadmap has to answer. It also had no way to record a
 * gap AFTER it closed, so the four primitives this engine already grew
 * (composite_ref identity, option_name_refs, row-cache invalidate, the
 * regenerator dependency) left no trace of the demand that justified them.
 *
 * tools/engine-gaps.json fixes both by typing the row: candidate, versions
 * probed, the grammar COORDINATE that could not represent the state, and a
 * `primitive_required` drawn from a CLOSED vocabulary declared in the same
 * file. The closure is the load-bearing part — gap_validate() refuses a
 * coordinate naming an undeclared primitive, so duplicate demand cannot drift
 * into two lookalike names and the open-demand ranking is a real count.
 *
 * This is deliberately tools/capability-doc.php's discipline (:11-58) rather
 * than a second one: project the prose from one source of truth, byte-compare
 * it under `make release-gate`, and refuse anything the document would
 * otherwise have to guess about. The repo has one generated-document pattern,
 * not two.
 *
 * WHAT gap_validate() REFUSES, AND WHY EACH REFUSAL IS THE POINT
 * -------------------------------------------------------------
 *   - a `primitive_required` outside the declared vocabulary: without this the
 *     ledger is a wishlist again, because nothing forces the second candidate
 *     to spell the primitive the way the first one did.
 *   - a declared primitive nothing demands: vocabulary that outlives its
 *     demand is how a ranking rots into an aspiration list.
 *   - an `open` primitive demanded by a CLOSED coordinate, or a `shipped`
 *     primitive demanded by one still open: the lifecycle state of a primitive
 *     and of the coordinate that wanted it are the same fact stated twice, and
 *     a disagreement means one of them is stale.
 *   - a candidate whose `disposition` disagrees with its own coordinates: a row
 *     is `closed` exactly when every coordinate is, and blocked while any one
 *     of them is not.
 *   - a `closed_by`/`evidence` path that is not on disk: "closed" is only a
 *     claim if the implementation it names can be opened. This is the same
 *     tripwire tools/provider-protocol-doc.php uses for its refusal catalogue
 *     — assert the cited thing still exists, so a move fails HERE rather than
 *     silently turning the ledger into a story about code that left.
 *
 * CLOSURE IS PER COORDINATE, NOT PER CANDIDATE (WP-6.1)
 * -----------------------------------------------------
 * It was per candidate until WP-6.1 shipped the first primitive that unblocked
 * PART of a row: `serialized_column_codec` closes Redirection's `action_data`
 * coordinate and leaves its `verified_provider_postcondition` coordinate exactly
 * where it was. Under row-level closure that had two spellings and both were
 * false — mark the row closed and the ledger claims a provider postcondition
 * that does not exist, or leave the primitive `open` and the ranking keeps
 * counting demand that has been met. Neither is a ranking a roadmap can read.
 *
 * So `closed_by` moved down one level, onto the coordinate that names the
 * primitive, and the candidate's `disposition` became derived-and-checked
 * rather than independently asserted. The projection follows: the open-demand
 * table counts OPEN COORDINATES, a blocked candidate's section lists only the
 * coordinates still blocking it, and the closed table lists every closed
 * coordinate — including those belonging to a candidate that is still blocked,
 * which is the honest picture of a boundary that moved without the adapter
 * shipping yet.
 *   - a coordinate whose head is not a real manifest grammar section: the head
 *     is checked against top-level keys in AdapterLibrary's package manifests
 *     rather than a hardcoded list, so this cannot become a second copy of
 *     agent/src/Policy/ManifestGrammar.php's vocabulary that drifts from it.
 *   - rejected rows that disagree on `probed_on`: the preamble states ONE
 *     probe date for the whole document, so two dates would make that sentence
 *     false.
 *
 * The document is emitted with UNWRAPPED paragraph lines, matching
 * docs/capabilities.md's own output. Hard wrapping a projected string would
 * put a newline at an arbitrary point inside it, and
 * sandbox/tests/offline/adapter/regress_ecosystem_adapter_batch.php:404-415
 * asserts specific phrases ("PHP serialization length prefixes", "string-id
 * attribute codec", …) appear in this file with str_contains — a wrap could
 * break one of those in half and fail the corpus for a formatting reason.
 */

use Duo\AdapterLibrary;

$repo = dirname(__DIR__);

require_once $repo . '/agent/src/Policy/AdapterLibrary.php';

const GAP_LEDGER_FILE = '/tools/engine-gaps.json';
const GAP_DOC_FILE = '/docs/guides/adapter-authoring-limitations.md';
const GAP_LEDGER_FORMAT = 'duo-engine-gaps/v1';

/** The three lifecycle states a candidate row can be in; nothing else is a disposition. */
const GAP_DISPOSITIONS = ['rejected', 'promotion_blocked', 'closed'];

function gap_fail(string $message): never {
    fwrite(STDERR, "engine-gap doc: $message\n");
    exit(1);
}

/** @return array<string,mixed> */
function gap_load(string $path): array {
    if (!is_file($path)) {
        throw new RuntimeException('missing ledger: ' . $path);
    }
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new RuntimeException('ledger root must be an object: ' . $path);
    }
    return $decoded;
}

function gap_library(string $repo): AdapterLibrary {
    return AdapterLibrary::fromSourceTree($repo);
}

/**
 * Every top-level key present across the shipped manifest library.
 *
 * A coordinate's head names a manifest grammar section, and this is where the
 * set of real section names is READ rather than restated: a hardcoded list
 * here would be a second copy of the grammar's vocabulary with nothing keeping
 * the two equal.
 *
 * @return list<string>
 */
function gap_manifest_sections(AdapterLibrary $library): array {
    $sections = [];
    foreach ($library->packages() as $package) {
        $file = $package->manifestPath();
        $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (is_array($decoded)) {
            foreach (array_keys($decoded) as $key) {
                $sections[(string) $key] = true;
            }
        }
    }
    if ($sections === []) {
        throw new RuntimeException('the adapter library has no manifests; coordinate heads cannot be checked');
    }
    $names = array_keys($sections);
    sort($names, SORT_STRING);
    return $names;
}

/** A coordinate is CLOSED exactly when it names the implementation that closed it. */
function gap_coordinate_closed(array $coordinate): bool {
    return ($coordinate['closed_by'] ?? null) !== null;
}

/** `tables.nf3_forms.invalidate` and `option_name_refs[cptui-default-term]` both head at their section. */
function gap_coordinate_head(string $coordinate): string {
    $head = explode('.', $coordinate, 2)[0];
    return explode('[', $head, 2)[0];
}

/**
 * Refuse everything the document would otherwise have to guess about.
 *
 * Pure by construction — the manifest section list and the existence predicate
 * are injected — so the PHPUnit self-tests can assert each refusal against a
 * mutated copy of the real ledger instead of building a throwaway repo per
 * case (tests/Tooling/ApiSurfaceTest.php makes the same call for as_diff()).
 *
 * @param array<string,mixed> $ledger
 * @param list<string> $sections manifest grammar section names
 * @param callable(string):bool $exists repo-relative path existence
 */
function gap_validate(array $ledger, array $sections, callable $exists): void {
    $keys = array_keys($ledger);
    sort($keys, SORT_STRING);
    if ($keys !== ['_comment', 'candidates', 'format', 'primitives']
        || ($ledger['format'] ?? null) !== GAP_LEDGER_FORMAT) {
        throw new RuntimeException(
            'ledger must contain exactly _comment, format, primitives and candidates for ' . GAP_LEDGER_FORMAT
        );
    }
    $primitives = $ledger['primitives'];
    $candidates = $ledger['candidates'];
    if (!is_array($primitives) || array_is_list($primitives) || $primitives === []) {
        throw new RuntimeException('ledger `primitives` must be a non-empty object keyed by primitive id');
    }
    if (!is_array($candidates) || !array_is_list($candidates) || $candidates === []) {
        throw new RuntimeException('ledger `candidates` must be a non-empty list');
    }

    foreach ($primitives as $id => $primitive) {
        if (!is_string($id) || preg_match('/^[a-z][a-z0-9_]*$/', $id) !== 1) {
            throw new RuntimeException("primitive id '" . var_export($id, true) . "' is not a lowercase_snake id");
        }
        if (!is_array($primitive)
            || !is_string($primitive['title'] ?? null) || trim((string) $primitive['title']) === ''
            || !is_string($primitive['definition'] ?? null) || trim((string) $primitive['definition']) === ''
            || !in_array($primitive['status'] ?? null, ['open', 'shipped'], true)) {
            throw new RuntimeException("primitive '$id' must declare a title, a definition, and status open|shipped");
        }
        $evidence = $primitive['evidence'] ?? [];
        if ($primitive['status'] === 'shipped' && (!is_array($evidence) || $evidence === [])) {
            throw new RuntimeException(
                "primitive '$id' is shipped but names no evidence; a shipped primitive with nothing to open is a claim"
            );
        }
        gap_assert_paths($evidence, "primitive '$id' evidence", $exists);
    }

    $demand = [];
    $ids = [];
    foreach ($candidates as $i => $row) {
        if (!is_array($row) || !is_string($row['id'] ?? null) || $row['id'] === '') {
            throw new RuntimeException("candidate[$i] has no id");
        }
        $id = (string) $row['id'];
        if (isset($ids[$id])) {
            throw new RuntimeException("candidate id '$id' appears twice; a row is one probe of one candidate");
        }
        $ids[$id] = true;
        if (!is_string($row['candidate'] ?? null) || trim((string) $row['candidate']) === '') {
            throw new RuntimeException("candidate '$id' has no candidate name");
        }
        if (!in_array($row['disposition'] ?? null, GAP_DISPOSITIONS, true)) {
            throw new RuntimeException(
                "candidate '$id' has disposition " . var_export($row['disposition'] ?? null, true)
                . '; expected one of ' . implode('|', GAP_DISPOSITIONS)
            );
        }
        $disposition = (string) $row['disposition'];
        $versions = $row['versions_probed'] ?? null;
        if (!is_array($versions) || !array_is_list($versions) || $versions === []) {
            throw new RuntimeException("candidate '$id' must name at least one probed version");
        }
        $blocked = $row['blocked_adapters'] ?? null;
        if (!is_array($blocked) || !array_is_list($blocked) || $blocked === []) {
            throw new RuntimeException(
                "candidate '$id' names no blocked adapter; a gap that blocks nothing is not a platform boundary"
            );
        }
        // The preamble states ONE probe date for the whole document, and only
        // the rejected rows are what that sentence is about.
        if ($disposition === 'rejected'
            && (!is_string($row['probed_on'] ?? null)
                || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', (string) $row['probed_on']) !== 1)) {
            throw new RuntimeException("candidate '$id' is rejected and must carry a YYYY-MM-DD probed_on");
        }
        if (array_key_exists('closed_by', $row)) {
            throw new RuntimeException(
                "candidate '$id' carries a row-level closed_by; since WP-6.1 closure is per COORDINATE, because a "
                . 'primitive can unblock one coordinate of a row and leave the others exactly where they were'
            );
        }

        $coordinates = $row['coordinates'] ?? null;
        if (!is_array($coordinates) || !array_is_list($coordinates) || $coordinates === []) {
            throw new RuntimeException("candidate '$id' declares no coordinates");
        }
        $openCoordinates = 0;
        foreach ($coordinates as $j => $coordinate) {
            if (!is_array($coordinate)
                || !is_string($coordinate['coordinate'] ?? null) || $coordinate['coordinate'] === ''
                || !is_string($coordinate['cannot_represent'] ?? null)
                || trim((string) $coordinate['cannot_represent']) === '') {
                throw new RuntimeException("candidate '$id' coordinate[$j] needs a coordinate and cannot_represent");
            }
            $head = gap_coordinate_head((string) $coordinate['coordinate']);
            if (!in_array($head, $sections, true)) {
                throw new RuntimeException(
                    "candidate '$id' coordinate '{$coordinate['coordinate']}' heads at '$head', which no manifest "
                    . 'declares as a section; a coordinate must address the real grammar'
                );
            }
            $required = $coordinate['primitive_required'] ?? null;
            if (!is_string($required) || !isset($primitives[$required])) {
                throw new RuntimeException(
                    "candidate '$id' coordinate '{$coordinate['coordinate']}' requires primitive "
                    . var_export($required, true) . ', which the closed vocabulary does not declare; add it to '
                    . '`primitives` or reuse the id an earlier candidate already demanded'
                );
            }
            $closedBy = $coordinate['closed_by'] ?? null;
            $coordinateClosed = $closedBy !== null;
            if ($coordinateClosed) {
                if (!is_array($closedBy) || $closedBy === []) {
                    throw new RuntimeException(
                        "candidate '$id' coordinate '{$coordinate['coordinate']}' is closed but names no closed_by "
                        . 'evidence; closure must be openable'
                    );
                }
                gap_assert_paths(
                    $closedBy,
                    "candidate '$id' coordinate '{$coordinate['coordinate']}' closed_by",
                    $exists
                );
            } else {
                $openCoordinates++;
            }
            $shipped = $primitives[$required]['status'] === 'shipped';
            if ($shipped !== $coordinateClosed) {
                throw new RuntimeException(
                    "candidate '$id' coordinate '{$coordinate['coordinate']}' is "
                    . ($coordinateClosed ? 'closed' : 'open') . " but primitive '$required' is "
                    . $primitives[$required]['status'] . '; a closed coordinate demands a shipped primitive and an '
                    . 'open coordinate demands an open one'
                );
            }
            // Recorded whether the coordinate is open or closed: this map
            // answers "does anything in the ledger demand this primitive", and
            // a shipped primitive's demand is exactly the closed coordinate it
            // shipped for. The OPEN half is counted separately, by
            // gap_open_demand(), which is the ranking.
            $demand[$required][] = $id;
        }
        // The candidate's own lifecycle word is DERIVED and merely checked
        // here: a row is closed exactly when nothing is still blocking it. The
        // alternative — asserting it independently — is how the ledger would
        // come to say "closed" beside a coordinate that is not.
        if (($openCoordinates === 0) !== ($disposition === 'closed')) {
            throw new RuntimeException(
                "candidate '$id' is $disposition with $openCoordinates open coordinate(s); a row is 'closed' "
                . 'exactly when every coordinate carries closed_by, and blocked while any one does not'
            );
        }
    }

    foreach (array_keys($primitives) as $id) {
        if (!isset($demand[(string) $id])) {
            throw new RuntimeException(
                "primitive '$id' is declared but no candidate demands it; the vocabulary is a ranking of real "
                . 'demand, not a catalogue of ideas'
            );
        }
    }
}

/**
 * @param mixed $paths
 * @param callable(string):bool $exists
 */
function gap_assert_paths(mixed $paths, string $where, callable $exists): void {
    if (!is_array($paths)) {
        throw new RuntimeException("$where must be a list of repo-relative paths");
    }
    foreach ($paths as $path) {
        if (!is_string($path) || $path === '') {
            throw new RuntimeException("$where holds a non-string entry");
        }
        if (!$exists($path)) {
            throw new RuntimeException("$where names '$path', which is not in the tree");
        }
    }
}

/** Markdown table cells cannot carry a pipe or a newline. */
function gap_cell(string $text): string {
    return str_replace(['|', "\n"], ['\\|', ' '], $text);
}

/** "WPForms Lite" + ["2.0.0.4","2.0.0.5"] -> "WPForms Lite 2.0.0.4 / 2.0.0.5" (the doc's own anchor). */
function gap_heading(array $row): string {
    return (string) $row['candidate'] . ' ' . implode(' / ', array_map('strval', $row['versions_probed']));
}

/**
 * "A", "A and B", "A, B, and C" — and ", plus B" when a phrase carries its own
 * commas, because then the comma is already spoken for and "and" would read as
 * one more element of the inner list. The predecessor document made exactly
 * this choice by hand for Redirection's two remedies; projecting the same rule
 * is what keeps the generated prose the prose a reviewer wrote.
 */
function gap_join(array $items): string {
    $items = array_values($items);
    if (count($items) === 1) {
        return $items[0];
    }
    $inner = false;
    foreach ($items as $item) {
        $inner = $inner || str_contains($item, ',');
    }
    $last = array_pop($items);
    if ($inner) {
        return implode('; ', $items) . ', plus ' . $last;
    }
    return count($items) === 1 ? $items[0] . ' and ' . $last : implode(', ', $items) . ', and ' . $last;
}

/** @return list<array<string,mixed>> */
function gap_rows(array $ledger, string $disposition): array {
    $out = [];
    foreach ($ledger['candidates'] as $row) {
        if ($row['disposition'] === $disposition) {
            $out[] = $row;
        }
    }
    return $out;
}

/**
 * The one thing prose could not do: count.
 *
 * @return list<array{primitive:string,title:string,candidates:list<string>,adapters:list<string>}>
 */
function gap_open_demand(array $ledger): array {
    $rows = [];
    foreach ($ledger['candidates'] as $row) {
        foreach ($row['coordinates'] as $coordinate) {
            // Per COORDINATE since WP-6.1. A candidate one of whose blockers
            // shipped still appears here for the ones that did not, and stops
            // inflating the count for the one that did.
            if (gap_coordinate_closed($coordinate)) {
                continue;
            }
            $id = (string) $coordinate['primitive_required'];
            $rows[$id]['candidates'][(string) $row['candidate']] = true;
            foreach ($row['blocked_adapters'] as $adapter) {
                $rows[$id]['adapters'][(string) $adapter] = true;
            }
        }
    }
    $out = [];
    foreach ($rows as $id => $entry) {
        $candidates = array_keys($entry['candidates']);
        $adapters = array_keys($entry['adapters']);
        sort($candidates, SORT_STRING);
        sort($adapters, SORT_STRING);
        $out[] = [
            'primitive' => (string) $id,
            'title' => (string) $ledger['primitives'][$id]['title'],
            'candidates' => $candidates,
            'adapters' => $adapters,
        ];
    }
    // Most-blocking first, then by primitive id: the ordering IS the ranking,
    // so it is a total order rather than "whatever the ledger's key order was".
    usort($out, static fn(array $a, array $b): int
        => count($b['candidates']) <=> count($a['candidates']) ?: strcmp($a['primitive'], $b['primitive']));
    return $out;
}

function gap_preamble(array $ledger): string {
    $dates = [];
    foreach (gap_rows($ledger, 'rejected') as $row) {
        $dates[(string) $row['probed_on']] = true;
    }
    if (count($dates) !== 1) {
        throw new RuntimeException(
            'the rejected candidates were probed on ' . count($dates) . ' different dates ('
            . implode(', ', array_keys($dates)) . '); the preamble states one probe date for all of them'
        );
    }

    return "# Adapter-authoring limitation ledger\n\n"
        . '<!-- Generated by tools/engine-gap-doc.php from tools/engine-gaps.json; do not hand-edit. Run '
        . "`php tools/engine-gap-doc.php generate` after editing the ledger. -->\n\n"
        . 'This ledger records plugin state shapes that the current generic grammar cannot represent faithfully. '
        . 'An entry is a platform boundary, not a plugin-specific branch request: the remedy must be a reusable '
        . "primitive with adversarial coverage before any affected adapter is promoted.\n\n"
        . 'The source probes below used official WordPress.org artifacts on ' . array_key_first($dates) . '. '
        . 'They are deliberately separate from adapter package dispositions: a rejected candidate is not shipped '
        . "adapter identity and makes no capability claim.\n\n"
        . 'Every coordinate names its `primitive_required` from a closed vocabulary in the ledger, so two '
        . 'candidates blocked on the same missing thing are ONE countable primitive rather than two lookalike '
        . "sentences. That is what makes the next table a ranking instead of a wishlist.\n\n";
}

function gap_demand_section(array $ledger): string {
    $out = "## Open primitive demand\n\n"
        . "| Primitive | Candidates | Blocked adapters |\n|---|---|---|\n";
    foreach (gap_open_demand($ledger) as $entry) {
        $out .= '| ' . gap_cell($entry['title'])
            . ' | ' . count($entry['candidates']) . ' (' . gap_cell(implode(', ', $entry['candidates'])) . ')'
            . ' | ' . gap_cell(implode(', ', $entry['adapters'])) . " |\n";
    }
    return $out . "\n";
}

/**
 * A still-blocked candidate's section: only the coordinates STILL BLOCKING it.
 *
 * A coordinate whose primitive shipped is no longer "required platform work"
 * and would misread as one; it moves to the closed table, with its own prose,
 * so the record of the defect survives the fix.
 */
function gap_candidate_section(array $row): string {
    $out = '## ' . gap_heading($row) . "\n\n";
    $open = [];
    foreach ($row['coordinates'] as $coordinate) {
        if (!gap_coordinate_closed($coordinate)) {
            $open[] = $coordinate;
        }
    }
    foreach ($open as $coordinate) {
        $out .= '- ' . $coordinate['cannot_represent'] . "\n";
    }
    $phrases = [];
    foreach ($open as $coordinate) {
        $phrase = (string) ($coordinate['remedy_phrase'] ?? '');
        $phrases[] = $phrase === '' ? '' : $phrase;
    }
    $phrases = array_values(array_filter($phrases, static fn(string $p): bool => $p !== ''));
    $tail = '';
    if ($phrases !== []) {
        $tail = 'Required platform work: ' . gap_join($phrases) . '.';
    }
    $closing = (string) ($row['closing'] ?? '');
    if ($closing !== '') {
        $tail = $tail === '' ? $closing : $tail . ' ' . $closing;
    }
    return $out . ($tail === '' ? '' : "\n" . $tail . "\n") . "\n";
}

/**
 * The shipped-but-not-promoted rows share one section because they share one
 * fact: the adapter is in the library, and the withheld operation is named in
 * its package disposition rather than hidden in a caveat here.
 */
function gap_promotion_blocked_section(array $ledger): string {
    $rows = gap_rows($ledger, 'promotion_blocked');
    if ($rows === []) {
        return '';
    }
    $out = "## Shipped experimental adapters with open apply work\n\n";
    foreach ($rows as $row) {
        foreach ($row['coordinates'] as $coordinate) {
            if (gap_coordinate_closed($coordinate)) {
                continue;
            }
            $out .= '- ' . gap_heading($row) . ': ' . $coordinate['cannot_represent'] . "\n";
        }
    }

    return $out . "\nThese are explicit promotion blockers in adapter package dispositions, not silent caveats. "
        . '`conformance-ecosystem-adapter-batch` exercises their exact artifacts through capture, compile, plan, '
        . 'deterministic recapture, and live plugin readback only. Its `capture-plan` mode stops before target '
        . "mutation, so none of these entries claims apply.\n\n";
}

/**
 * The closed rows are the ledger's only evidence that this instrument ever
 * moves. A gap that vanishes on the day it closes leaves the open table
 * looking like a permanent complaint rather than a queue with a throughput.
 */
function gap_closed_section(array $ledger): string {
    $closed = [];
    foreach ($ledger['candidates'] as $row) {
        foreach ($row['coordinates'] as $coordinate) {
            if (gap_coordinate_closed($coordinate)) {
                $closed[] = [$row, $coordinate];
            }
        }
    }
    if ($closed === []) {
        return '';
    }
    $out = "## Closed engine gaps\n\n"
        . 'These shipped. They stay in the ledger because the primitive that closed each one is the unit the open '
        . "table above counts in, and a vocabulary with no closed entries cannot be checked against reality.\n\n"
        . 'Closure is per COORDINATE, so a candidate appears here for the blockers that shipped and above for the '
        . "ones that have not. A row leaves the blocked sections entirely only when every coordinate is closed.\n\n"
        . "| Candidate | Grammar coordinate | Primitive shipped | Closed by |\n|---|---|---|---|\n";
    foreach ($closed as [$row, $coordinate]) {
        $primitive = $ledger['primitives'][(string) $coordinate['primitive_required']];
        $out .= '| ' . gap_cell(gap_heading($row))
            . ' | `' . gap_cell((string) $coordinate['coordinate']) . '`'
            . ' | ' . gap_cell((string) $primitive['title'])
            . ' | ' . gap_cell(implode(', ', array_map(
                static fn(string $path): string => '`' . $path . '`',
                array_map('strval', $coordinate['closed_by'])
            ))) . " |\n";
    }
    // The defect, kept beside the fix. A closed table that records only what
    // shipped turns the ledger into a changelog: the sentence a reviewer needs
    // years later is the one saying what the grammar could not represent, and
    // it is also the sentence the offline corpus greps for.
    $out .= "\nWhat each one could not represent:\n\n";
    foreach ($closed as [$row, $coordinate]) {
        $out .= '- ' . gap_heading($row) . ' — `' . $coordinate['coordinate'] . '`: '
            . $coordinate['cannot_represent'] . "\n";
    }
    $out .= "\n";
    foreach (gap_rows($ledger, 'closed') as $row) {
        $closing = (string) ($row['closing'] ?? '');
        if ($closing !== '') {
            $out .= '- ' . gap_heading($row) . ': ' . $closing . "\n";
        }
    }
    return $out . "\n";
}

function gap_render(array $ledger): string {
    $out = gap_preamble($ledger) . gap_demand_section($ledger);
    foreach (gap_rows($ledger, 'rejected') as $row) {
        $out .= gap_candidate_section($row);
    }
    $out .= gap_promotion_blocked_section($ledger) . gap_closed_section($ledger);
    return rtrim($out, "\n") . "\n";
}

/** @return array<string,string> path => expected bytes */
function gap_build(string $repo): array {
    $ledger = gap_load($repo . GAP_LEDGER_FILE);
    $library = gap_library($repo);
    gap_validate(
        $ledger,
        gap_manifest_sections($library),
        static fn(string $path): bool => file_exists($repo . '/' . ltrim($path, '/'))
    );
    return [$repo . GAP_DOC_FILE => gap_render($ledger)];
}

/** Name the drift instead of merely reporting it, so a failed gate is actionable from its own output. */
function gap_drift(string $expected, string $actual): string {
    $expectedLines = explode("\n", $expected);
    $actualLines = explode("\n", $actual);
    $count = max(count($expectedLines), count($actualLines));
    for ($i = 0; $i < $count; $i++) {
        $want = $expectedLines[$i] ?? '<end of file>';
        $have = $actualLines[$i] ?? '<end of file>';
        if ($want !== $have) {
            return 'first difference at line ' . ($i + 1) . "\n"
                . '    on disk:   ' . gap_excerpt($have) . "\n"
                . '    generated: ' . gap_excerpt($want);
        }
    }
    return 'files differ in trailing bytes only';
}

function gap_excerpt(string $line): string {
    return mb_strlen($line) > 120 ? mb_substr($line, 0, 117) . '...' : $line;
}

function gap_run(string $repo, bool $check): void {
    $expected = gap_build($repo);
    if ($check) {
        $stale = [];
        foreach ($expected as $path => $content) {
            $relative = str_replace($repo . '/', '', $path);
            if (!is_file($path)) {
                $stale[] = $relative . ': absent';
                continue;
            }
            $actual = (string) file_get_contents($path);
            if ($actual !== $content) {
                $stale[] = $relative . ': ' . gap_drift($content, $actual);
            }
        }
        if ($stale !== []) {
            throw new RuntimeException(
                "the generated limitation ledger is stale; run `php tools/engine-gap-doc.php generate`\n  "
                . implode("\n  ", $stale)
            );
        }
        fwrite(STDOUT, "engine-gap doc check: tools/engine-gaps.json and the limitation ledger agree\n");
        return;
    }
    foreach ($expected as $path => $content) {
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('could not write ' . $path);
        }
    }
    fwrite(STDOUT, "generated docs/guides/adapter-authoring-limitations.md\n");
}

function gap_main(string $repo, array $argv): void {
    try {
        $command = $argv[1] ?? '--check';
        if ($command === 'generate') {
            gap_run($repo, false);
        } elseif ($command === '--check' || $command === 'check') {
            gap_run($repo, true);
        } else {
            throw new RuntimeException('usage: php tools/engine-gap-doc.php [generate|--check]');
        }
    } catch (Throwable $e) {
        gap_fail($e->getMessage());
    }
}

// Only as a CLI entry point: requiring this file from a PHPUnit self-test must
// define the functions without regenerating or exiting (same guard shape as
// tools/affected.php's af_main()).
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $cliArgv = $_SERVER['argv'] ?? [];
    gap_main($repo, is_array($cliArgv) ? array_map('strval', $cliArgv) : []);
}

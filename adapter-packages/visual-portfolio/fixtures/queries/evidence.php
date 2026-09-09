<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/settings-evidence.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/FilesystemTreeEvidence.php';

final class VisualPortfolioQueryEvidence {
    public const CASES = ['default', 'manual', 'post-types', 'filters', 'reset', 'duplicates', 'taxonomy-exclusion'];

    public static function check(bool $ok, string $reason): void {
        if (!$ok) throw new RuntimeException('Visual Portfolio query admission: ' . $reason);
    }

    public static function rendered(string $html, string $home): array {
        self::check(strlen($html) >= 4096, 'nonvacuous complete HTTP body');
        $document = new DOMDocument();
        $prior = libxml_use_internal_errors(true);
        try { self::check($document->loadHTML($html), 'HTML document parses'); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($prior); }
        $xpath = new DOMXPath($document);
        $links = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," wp-block-visual-portfolio-item-title ")]/a');
        self::check($links !== false && $links->length > 0, 'native query renders item title links');
        $out = [];
        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            self::check(str_starts_with($href, $home . '/'), 'rendered item belongs to this environment');
            $out[] = ['title' => trim($link->textContent), 'path' => substr($href, strlen($home))];
        }
        return $out;
    }

    public static function queries(array $source, array $target, array $before): void {
        self::check(array_keys($source['pages']) === self::CASES && array_keys($target['pages']) === self::CASES, 'complete query-page roster');
        $sourcePosts = array_column($source['tables']['posts'], null, 'ID');
        $targetPosts = array_column($target['tables']['posts'], null, 'post_name');
        $sourceTerms = array_column($source['tables']['terms'], null, 'term_id');
        $targetTerms = array_column($target['tables']['terms'], null, 'slug');
        foreach (self::CASES as $case) {
            $expected = $source['pages'][$case]['query'];
            if ($expected !== null) foreach (['ids', 'excludeIds', 'taxonomies'] as $field) foreach ($expected[$field] as &$id) {
                self::check(is_string($id), 'native selectors are strings');
                $newId = $field === 'taxonomies'
                    ? $targetTerms[$sourceTerms[$id]['slug']]['term_id']
                    : $targetPosts[$sourcePosts[$id]['post_name']]['ID'];
                self::check((int) $id !== (int) $newId, 'every selected post and term ID diverges');
                $id = (string) $newId;
            }
            unset($id);
            self::check($expected === $target['pages'][$case]['query'], 'complete native query agrees: ' . $case);
            self::check($before['bodies'][$case] !== $target['pages'][$case]['body'], 'Apply replaced the empty target fixture: ' . $case);
        }
        $counters = array_values(array_filter($target['tables']['postmeta'], static fn(array $row): bool => $row['meta_key'] === '_vp_views_count'));
        $counterIds = array_map('intval', array_column($counters, 'post_id'));
        $pageIds = array_column($target['pages'], 'id');
        sort($counterIds, SORT_NUMERIC); sort($pageIds, SORT_NUMERIC);
        self::check($counterIds === $pageIds && array_unique(array_column($counters, 'meta_value')) === ['42'], 'all target runtime counters survive Apply');
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $phase = $argv[1] ?? '';
    if ($phase === 'snapshot') {
        echo json_encode(WPrismTest\FilesystemTreeEvidence::capture($argv[2], 'state', WPrismTest\EvidenceSizeProfile::CONFORMANCE_TREE), JSON_THROW_ON_ERROR), "\n";
        exit(0);
    }
    $sink = $argv[2];
    $pair = $argv[3];
    $root = dirname(__DIR__, 4);
    $transport = '/^ ?Container wprism-' . preg_quote($pair, '/') . '-cli[12]-run-[a-f0-9]{12} (Creating|Created) *$/D';
    $read = static function (string $name, string $verb = '', int $status = 0) use ($root, $sink, $pair, $transport): array {
        VisualPortfolioSettingsEvidence::command($root, "$sink/$name", $pair, $verb, $status);
        return json_decode(WPrismTest\PrivateCommandOutput::readObject("$sink/$name",
            $verb === '' ? $transport : '/^(?:private command diagnostics \(unverified\): [^\r\n]+| ?Container wprism-' . preg_quote($pair, '/') . '-cli[12]-run-[a-f0-9]{12} (Creating|Created) *)$/D', expectedExit: $status), true, 64, JSON_THROW_ON_ERROR);
    };
    if ($phase === 'positive') {
        $source = $read('source'); $target = $read('target');
        $seed = $read('seed1');
        foreach (VisualPortfolioQueryEvidence::CASES as $case) VisualPortfolioQueryEvidence::check(
            $source['pages'][$case]['body'] === $seed['bodies'][$case], 'Capture preserves complete native REST Save bytes: ' . $case);
        VisualPortfolioQueryEvidence::queries($source, $target, $read('seed2'));
        VisualPortfolioQueryEvidence::check($target === $read('stable'), 'repeated Apply preserves every native table and the complete identity map');
        $apply = $read('apply', 'apply'); $repeat = $read('repeat', 'apply');
        VisualPortfolioQueryEvidence::check(($apply['applied'] ?? 0) >= count(VisualPortfolioQueryEvidence::CASES)
            && ($repeat['applied'] ?? null) === 0 && ($apply['canary'] ?? null) === 'clean'
            && ($repeat['canary'] ?? null) === 'clean', 'public Apply and no-op repetition succeed');
        $renders = [];
        foreach (VisualPortfolioQueryEvidence::CASES as $case) {
            foreach ([1 => $source, 2 => $target] as $side => $native) {
                $html = WPrismTest\PrivateCommandOutput::readBytes("$sink/http$side-$case");
                $renders[$case][$side] = VisualPortfolioQueryEvidence::rendered($html, $native['home']);
            }
            VisualPortfolioQueryEvidence::check($renders[$case][1] === $renders[$case][2], 'ordered native HTTP results agree: ' . $case);
        }
        VisualPortfolioQueryEvidence::check(array_column($renders['manual'][2], 'path') === ['/vp-subject-13/', '/portfolio/vp-subject-6/']
            && array_column($renders['duplicates'][2], 'path') === ['/vp-subject-15/', '/vp-subject-14/']
            && array_column($renders['taxonomy-exclusion'][2], 'path') === ['/vp-subject-13/'], 'manual, duplicate-title and positive taxonomy/exclusion semantics are nonvacuous');
        echo "PASS: native query Apply, no-op repeat and seven ordered HTTP results\n";
        exit(0);
    }
    VisualPortfolioQueryEvidence::check($phase === 'negative', 'known evidence phase');
    foreach (['custom', 'hidden-custom', 'missing'] as $case) {
        $refusal = $read("$case-refusal", 'capture', 1);
        VisualPortfolioQueryEvidence::check(($refusal['format'] ?? null) === 'wprism-command-refusal/v1'
            && ($refusal['ok'] ?? null) === false && ($refusal['command'] ?? null) === 'capture'
            && str_contains($refusal['message'] ?? '', $case === 'missing' ? 'refuses an unmapped post reference' : 'requires a declared literal value'), 'exact public query refusal: ' . $case);
        VisualPortfolioQueryEvidence::check($read("$case-before") === $read("$case-after"), 'refused Capture preserves all native tables and identities: ' . $case);
        $before = $read("$case-state-before"); $after = $read("$case-state-after");
        WPrismTest\FilesystemTreeEvidence::assertRecord($before, 'state', WPrismTest\EvidenceSizeProfile::CONFORMANCE_TREE);
        WPrismTest\FilesystemTreeEvidence::assertRecord($after, 'state', WPrismTest\EvidenceSizeProfile::CONFORMANCE_TREE);
        VisualPortfolioQueryEvidence::check($before === $after && count($before['files']) >= 16, 'refused Capture preserves the complete nonempty repository state: ' . $case);
    }
    echo "PASS: visible/hidden custom-query and deleted-selection refusals preserve native and repository state\n";
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/../conformance/corpus.php';

/** Retained native writer bytes supply the oracle; no declaration chooses fields here. */
final class QiNativeGlobalControlsCorpus {
    public const RECORD_SHA256 = '3e1104098f4412cf43fc5977fdf6704f5d011ca36e1aa3b2163e8aec580d48c2';
    public const SOURCE_HOME = 'http://localhost:9508';

    public static function cases(string $bytes): array {
        if (hash('sha256', $bytes) !== self::RECORD_SHA256) throw new RuntimeException('Native global-control observation bytes changed');
        $record = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        if (($record['format'] ?? null) !== 'wprism-qi-native-global-controls/v1' || count($record['cases'] ?? []) !== 7) throw new RuntimeException('Native global-control case census changed');
        $cases = [];
        foreach ($record['cases'] as $case) {
            $name = $case['case'];
            if (!is_string($name) || preg_match('/^global-[a-z-]{1,40}$/D', $name) !== 1 || isset($cases[$name])) throw new RuntimeException('Native global-control case is ambiguous');
            $cases[$name] = $case;
        }
        return $cases;
    }

    public static function saved(string $saved, string $recordBytes, string $name): string {
        $case = self::cases($recordBytes)[$name] ?? null;
        if ($case === null) throw new RuntimeException('Unknown retained native global-control case');
        $native = array_values(array_filter(parse_blocks(str_replace(self::SOURCE_HOME, QiConformanceCorpus::SOURCE_HOME, $case['saved'])),
            static fn(array $block): bool => $block['blockName'] !== null));
        if (count($native) !== 1 || $native[0]['blockName'] !== 'qi-blocks/single-image' || $native[0]['innerBlocks'] !== []
            || ($native[0]['attrs']['image']['id'] ?? null) !== 1 || ($native[0]['attrs']['uniqueClass'] ?? null) !== 'qodef-block-915bb80f') {
            throw new RuntimeException('Native global-control owner or image premise changed');
        }
        // Two independently observed image coordinates are staged to the
        // original saved corpus's ID8, then its existing native seed rehomes
        // that corpus. Metadata is never searched for or assigned ID meaning.
        $replacement = $native[0];
        $replacement['attrs']['image']['id'] = 8;
        foreach ($replacement['innerContent'] as &$fragment) if (is_string($fragment)) {
            $fragment = preg_replace('/(?<![A-Za-z0-9_-])wp-image-1(?![0-9])/', 'wp-image-8', $fragment);
        }
        unset($fragment);
        $blocks = parse_blocks($saved);
        $seen = 0;
        foreach ($blocks as &$block) if ($block['blockName'] === 'qi-blocks/single-image') {
            if (++$seen !== 1 || ($block['attrs']['uniqueClass'] ?? null) !== $replacement['attrs']['uniqueClass']
                || ($block['attrs']['image']['id'] ?? null) !== 8) throw new RuntimeException('Native saved global-control destination is ambiguous');
            $block = $replacement;
        }
        unset($block);
        if ($seen !== 1) throw new RuntimeException('Native saved corpus lost its global-control owner');
        return serialize_blocks($blocks);
    }
}

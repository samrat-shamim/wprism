<?php
declare(strict_types=1);

namespace {
    $root = (string) ($argv[1] ?? '');
    require_once $root . '/agent/src/Kernel/PlainData.php';
    require_once $root . '/agent/src/Grammar/SubKeyGrammar.php';
    require_once $root . '/adapter-packages/polylang/package/runtime/interpreters/polylang.php';

    $manifest = json_decode(
        (string) file_get_contents($root . '/adapter-packages/polylang/package/manifest.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $subKeys = $manifest['options']['polylang']['sub_keys'];
    $base = [
        'browser' => 1,
        'default_lang' => 'en',
        'force_lang' => 1,
        'hide_default' => 1,
        'media_support' => 0,
        'nav_menus' => [],
        'post_types' => ['post'],
        'redirect_lang' => 1,
        'rewrite' => 0,
        'sync' => ['comment_status'],
        'taxonomies' => ['category'],
    ];
    $snapshot = [
        'polylang' => serialize($base),
        'pll_language_from_content_available' => 'target-runtime-sentinel',
    ];
    $interpreter = new \Duo\Interpreters\Polylang(new \Duo\Policy());
    $closedRefusal = false;
    try {
        \Duo\SubKeyGrammar::assert_closed_value(
            'polylang',
            ['closed_sub_keys' => true, 'sub_keys' => $subKeys],
            ['duo_target_runtime_neighbor' => 'target-preserved-東京-🚀'],
            'source'
        );
    } catch (\RuntimeException) {
        $closedRefusal = true;
    }
    if (!$closedRefusal) {
        fwrite(STDERR, "closed Polylang sibling was accepted\n");
        exit(1);
    }
    $normalize = static function (array $value) use ($interpreter, $subKeys, $snapshot): array {
        return $interpreter->normalize_captured_option_sub_keys(
            'polylang',
            $value,
            $subKeys,
            $snapshot,
            true
        );
    };
    $absent = $interpreter->normalize_captured_option_sub_keys(
        'polylang',
        [],
        $subKeys,
        ['pll_language_from_content_available' => 'target-runtime-sentinel'],
        true
    );
    if ($absent !== []) {
        fwrite(STDERR, "strict-read-only changed absent Polylang state\n");
        exit(1);
    }
    $refusals = 0;
    foreach ([
        ['force_lang' => 2, 'reason' => 'topology-bound mode'],
        ['force_lang' => '1', 'reason' => 'stringly force_lang'],
        ['browser' => 'yes', 'reason' => 'malformed boolean'],
        ['default_lang' => '../escape', 'reason' => 'malformed language slug'],
    ] as $case) {
        try {
            $normalize(array_replace($base, $case));
            fwrite(STDERR, "strict-read-only accepted {$case['reason']}\n");
            exit(1);
        } catch (\RuntimeException) {
            ++$refusals;
        }
    }
    $normalized = $normalize($base);
    echo json_encode([
        'refusals' => $refusals,
        'closed_refusal' => $closedRefusal,
        'absent' => $absent,
        'browser' => $normalized['browser'] ?? null,
        'media_support' => $normalized['media_support'] ?? null,
        'rewrite' => $normalized['rewrite'] ?? null,
    ], JSON_THROW_ON_ERROR) . "\n";
}

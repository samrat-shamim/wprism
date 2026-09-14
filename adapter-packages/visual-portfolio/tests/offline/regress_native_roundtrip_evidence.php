<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 2) . '/fixtures/native/roundtrip-evidence.php';
$record = static function (int $offset): array {
    $out = ['format' => 'wprism-vp-native-roundtrip/v1', 'plugin' => '3.8.1', 'home' => 'http://site' . $offset . '.test'];
    foreach (['posts' => VisualPortfolioRoundtripEvidence::POSTS, 'terms' => VisualPortfolioRoundtripEvidence::TERMS] as $kind => $names) {
        foreach ($names as $i => $name) $out[$kind][$name] = ['id' => $offset + $i + 1,
            'uuid' => sprintf('12345678-1234-7123-8123-%012d', $i + 1 + ($kind === 'terms' ? 100 : 0))];
    }
    $out['archive_page'] = $out['posts']['vp-alternate-archive']['id'];
    $out['placeholder'] = $out['posts']['harbor']['id'];
    foreach (['harbor', 'garden'] as $i => $name) $out['media'][$name] = ['id' => $out['posts'][$name]['id'], 'sha256' => hash('sha256', $name), 'bytes' => 400 + $i, 'url' => $out['home'] . '/wp-content/uploads/2026/09/' . $name . '.png'];
    foreach (['harbor-light', 'paper-garden', 'quiet-shapes', 'open-horizon'] as $i => $name) $out['featured'][$name] = $out['posts'][$i % 2 === 0 ? 'harbor' : 'garden']['id'];
    $out['gallery_ids'] = [$out['posts']['harbor']['id'], $out['posts']['garden']['id']];
    return $out;
};
$source = $record(10); $target = $record(100);
VisualPortfolioRoundtripEvidence::compare($source, $target);
wprism_check(true, 'complete divergent native roles and media pass');
foreach (['empty', 'version', 'home', 'missing-role', 'alias-id', 'alias-uuid', 'wrong-uuid', 'same-id', 'archive', 'placeholder', 'media-empty', 'media-corrupt', 'media-unbound', 'featured', 'gallery-order'] as $fault) {
    $bad = $target;
    if ($fault === 'empty') $bad = [];
    if ($fault === 'version') $bad['plugin'] = '3.8.0';
    if ($fault === 'home') $bad['home'] = $source['home'];
    if ($fault === 'missing-role') unset($bad['posts']['open-horizon']);
    if ($fault === 'alias-id') $bad['posts']['open-horizon']['id'] = $bad['posts']['quiet-shapes']['id'];
    if ($fault === 'alias-uuid') $bad['posts']['open-horizon']['uuid'] = $bad['posts']['quiet-shapes']['uuid'];
    if ($fault === 'wrong-uuid') $bad['posts']['open-horizon']['uuid'] = 'ffffffff-ffff-7fff-8fff-ffffffffffff';
    if ($fault === 'same-id') $bad['posts']['open-horizon']['id'] = $source['posts']['open-horizon']['id'];
    if ($fault === 'archive') $bad['archive_page'] = $bad['posts']['vp-author-gallery']['id'];
    if ($fault === 'placeholder') $bad['placeholder'] = $bad['posts']['garden']['id'];
    if ($fault === 'media-empty') $bad['media']['harbor']['bytes'] = 0;
    if ($fault === 'media-corrupt') $bad['media']['garden']['sha256'] = str_repeat('a', 64);
    if ($fault === 'media-unbound') $bad['media']['harbor']['id'] = $source['media']['harbor']['id'];
    if ($fault === 'featured') $bad['featured']['open-horizon'] = $bad['posts']['harbor']['id'];
    if ($fault === 'gallery-order') $bad['gallery_ids'] = array_reverse($bad['gallery_ids']);
    wprism_check_throws(static fn() => VisualPortfolioRoundtripEvidence::compare($source, $bad), RuntimeException::class, "$fault cannot masquerade as native roundtrip");
}
$html = '<!doctype html><html><body>' . str_repeat(' ', 1100);
foreach (['harbor', 'garden'] as $name) {
    $m = $target['media'][$name];
    $html .= '<figure class="wp-block-visual-portfolio-item-image"><a data-vp-popup="image" href="' . $m['url'] . '"><img class="wp-image-' . $m['id'] . '" src="' . $m['url'] . '"></a></figure>';
}
$html .= '</body></html>';
wprism_check_same(array_column($target['media'], 'url'), VisualPortfolioRoundtripEvidence::rendered($html, $target, 'gallery'), 'real gallery selectors bind both downloadable originals');
foreach (['empty', 'missing-image', 'wrong-id', 'wrong-url', 'missing-popup', 'warning'] as $fault) {
    $bad = $html;
    if ($fault === 'empty') $bad = '';
    if ($fault === 'missing-image') $bad = str_replace('<img ', '<not-an-image ', $bad);
    if ($fault === 'wrong-id') $bad = str_replace('wp-image-101', 'wp-image-999', $bad);
    if ($fault === 'wrong-url') $bad = str_replace($target['home'], $source['home'], $bad);
    if ($fault === 'missing-popup') $bad = str_replace('data-vp-popup', 'data-irrelevant', $bad);
    if ($fault === 'warning') $bad .= 'Warning: broken render';
    wprism_check_throws(static fn() => VisualPortfolioRoundtripEvidence::rendered($bad, $target, 'gallery'), RuntimeException::class, "render rejects $fault");
}
$archive = str_repeat(' ', 1100) . '<h2 class="vp-portfolio__item-meta-title">Open Horizon</h2><h2 class="vp-portfolio__item-meta-title">Quiet Shapes</h2>';
wprism_check_same([], VisualPortfolioRoundtripEvidence::rendered($archive, $target, 'archive'), 'legacy archive binds its two descending-date projects');
wprism_check_throws(static fn() => VisualPortfolioRoundtripEvidence::rendered(str_replace('Quiet Shapes', 'Paper Garden', $archive), $target, 'archive'), RuntimeException::class, 'wrong legacy query result refuses');

wprism_check_summary('Visual Portfolio native roundtrip evidence');

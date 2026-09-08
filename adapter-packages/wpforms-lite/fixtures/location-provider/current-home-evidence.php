<?php
declare(strict_types=1);

/** Host-owned full-observation admission, independent of the native writer. */
final class WPFormsCurrentHomeEvidence {
    private const CURRENT = 'http://current.example.test/base';
    private const NEXT = 'http://next.example.test/other';
    private const PATH = '/wprism-current-home-page/';

    public static function verify(array $seed, array $observe, array $rehome, string $initialHome): void {
        self::check(preg_match('#^http://[a-z][a-z0-9]{2,23}1\.invalid$#D', $initialHome) === 1, 'initial home');
        self::header($seed, 'seed', ['initial', 'historical', 'canonical']);
        self::header($observe, 'observe', ['fresh', 'cases']);
        self::header($rehome, 'rehome', ['fresh']);
        $initial = self::observation($seed['initial'], $initialHome);
        $old = self::observation($seed['historical'], self::CURRENT);
        $fresh = self::observation($observe['fresh'], self::CURRENT);
        $later = self::observation($rehome['fresh'], self::NEXT);
        self::check(self::href($initial['html']) === $initial['url'], 'initial native link');
        self::check(self::href($old['html']) === $initialHome . $old['url'], 'historical double-home link');
        self::check(self::href($fresh['html']) === $fresh['url'], 'fresh current-home link');
        self::check(self::href($later['html']) === $later['url'], 'later durable-home link');

        $initialRows = $initial['locations'];
        $canonical = $initialRows;
        $canonical[0]['title'] = 'Current home changed title';
        self::check($initialRows[0]['title'] === 'WPrism current-home page' && $initialRows[0]['url'] === self::PATH,
            'initial public writer bytes');
        $historical = $canonical;
        $historical[0]['url'] = self::CURRENT . self::PATH;
        self::check($old['locations'] === $historical, 'historical public writer bytes');
        self::check($seed['canonical'] === $canonical && $fresh['locations'] === $canonical && $later['locations'] === $canonical,
            'complete canonical metadata survives independent fresh homes');

        // Independent expected input and native href vectors. Do not derive
        // a passing target by decoding whatever the worker happened to emit.
        $cases = [
            'plain' => ['/probe', '/probe', true],
            'query_home' => ['/probe?next=' . self::CURRENT . '/path', '/probe?next=' . self::CURRENT . '/path', true],
            'utf8' => ['/%E6%9D%B1%E4%BA%AC', '/東京', true],
            'unreserved' => ['/%41%7a%30%2d%5f%7e', '/Az0-_~', true],
            'encoded_question' => ['/probe%3Fnext', '/probe?next', false],
            'encoded_ampersand' => ['/probe?q=a%26b', '/probe?q=a&b', false],
            'literal_plus' => ['/probe+a', '/probe a', false],
            'encoded_plus' => ['/probe%2Ba', '/probe+a', false],
            'encoded_percent' => ['/probe%252foutside', '/probe%2foutside', false],
            'encoded_quote' => ['/probe?q=%22%20x%3D%22y', '/probe?q=', false],
            'encoded_hash' => ['/probe%23tail', '/probe#tail', false],
        ];
        self::check(is_array($observe['cases']) && array_keys($observe['cases']) === array_keys($cases), 'complete case inventory');
        foreach ($cases as $name => [$input, $target, $admitted]) {
            $case = $observe['cases'][$name];
            self::check(is_array($case) && array_keys($case) === ['url', 'candidate', 'locations', 'html'], 'case shape');
            $rows = $canonical;
            $rows[0]['url'] = $input;
            self::check($case['url'] === self::CURRENT . $input && $case['candidate'] === ($admitted ? $input : null)
                && $case['locations'] === $rows && self::href($case['html']) === self::CURRENT . $target, 'case ' . $name);
        }
    }

    private static function header(array $value, string $phase, array $keys): void {
        self::check(array_keys($value) === array_merge(['format', 'phase', 'version'], $keys)
            && $value['format'] === 'wprism-wpforms-current-home/v1' && $value['phase'] === $phase
            && $value['version'] === '2.0.1.1', 'record identity');
    }

    private static function observation(mixed $value, string $home): array {
        self::check(is_array($value) && array_keys($value) === ['home', 'url', 'locations', 'html'], 'observation shape');
        self::check($value['home'] === $home && $value['url'] === $home . self::PATH, 'native home/permalink');
        $rows = $value['locations'];
        self::check(is_array($rows) && array_is_list($rows) && count($rows) === 1 && is_array($rows[0]), 'native metadata shape');
        self::check(array_keys($rows[0]) === ['type', 'title', 'form_id', 'id', 'status', 'url']
            && $rows[0]['type'] === 'page' && $rows[0]['status'] === 'publish'
            && is_int($rows[0]['form_id']) && $rows[0]['form_id'] > 0
            && is_int($rows[0]['id']) && $rows[0]['id'] > 0 && $rows[0]['id'] !== $rows[0]['form_id'], 'native post identities');
        return $value;
    }

    private static function href(mixed $html): string {
        self::check(is_string($html) && strlen($html) <= 16384 && preg_match('//u', $html) === 1, 'bounded native UI');
        self::check(preg_match_all('/<a href="([^"]*)" target="_blank" class="wpforms-locations-link">/', $html, $matches) === 1,
            'exact native location link');
        return html_entity_decode($matches[1][0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function check(bool $condition, string $label): void {
        if (!$condition) throw new RuntimeException('WPForms current-home evidence refused: ' . $label);
    }
}

if (isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    if ($argc !== 6 || $argv[1] !== '--admit') throw new RuntimeException('expected --admit seed-stem observe-stem rehome-stem initial-home');
    require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/PrivateCommandOutput.php';
    $records = [];
    foreach (array_slice($argv, 2, 3) as $stem) {
        $records[] = json_decode(WPrismTest\PrivateCommandOutput::readObject($stem,
            '/^ ?Container wprism-[a-z0-9]+-cli1-run-[a-z0-9]+ (Creating|Created) *$/D'), true, 32, JSON_THROW_ON_ERROR);
    }
    WPFormsCurrentHomeEvidence::verify(...array_merge($records, [$argv[5]]));
    echo "WPForms native current-home writer, renderer and refusal evidence admitted\n";
}

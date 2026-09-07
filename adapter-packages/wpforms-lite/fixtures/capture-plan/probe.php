<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/PrivateCommandOutput.php';
require_once dirname(__DIR__, 4) . '/agent/src/Kernel/Canon.php';

final class WPFormsCaptureProbe {
    public static function verify(string $stem, string $pair, string $phase, string $repo, string $port): void {
        self::require(preg_match('/^[a-z][a-z0-9]*$/D', $pair) === 1, 'invalid pair identity');
        self::require(ctype_digit($port) && (int) $port > 0 && (int) $port <= 65535, 'invalid source port');
        $pattern = '/^ ?Container wprism-' . preg_quote($pair, '/') . '-cli1-run-[a-f0-9]+ (Creating|Created) *$/D';
        $record = json_decode(\WPrismTest\PrivateCommandOutput::readObject($stem, $pattern), true, 32, JSON_THROW_ON_ERROR);
        self::admit($record, $phase, 'http://localhost:' . $port);
        if ($phase !== 'observe') {
            return;
        }
        foreach ($record['posts'] as $role => $post) {
            $path = $repo . '/state/posts/' . $post['type'] . '/' . $post['uuid'] . '--' . $post['slug'] . '.md';
            self::require(is_file($path) && !is_link($path), 'missing captured native fixture: ' . $role);
            [$front, $body] = \WPrism\Canon::parse_post_file(\WPrism\Canon::read_file($path));
            self::require(($front['uuid'] ?? null) === $post['uuid'], 'canonical identity differs: ' . $role);
            if ($role === 'embed') {
                $shortcode = '[wpforms id="{{post:' . $record['posts']['integer']['uuid'] . '}}" title="true"]';
                self::require(preg_match('~^' . preg_quote($shortcode, '~') . '\n<!-- wp:wpforms/form-selector (.+) /-->$~D', $body, $match) === 1, 'canonical embed structure differs');
                self::require(json_decode($match[1], true, 32, JSON_THROW_ON_ERROR) === [
                    'formId' => '{{post:' . $record['posts']['string']['uuid'] . '}}', 'displayTitle' => true,
                ], 'canonical block attributes differ');
            } else {
                self::require($body === self::expectedBody($record, $role), 'complete canonical body differs: ' . $role);
            }
        }
    }

    /** Host assertions never trust a native worker's success flag. */
    public static function admit(array $record, string $phase, string $home): void {
        self::require(in_array($phase, ['seed', 'observe'], true), 'unknown admission phase');
        self::keys($record, ['format', 'phase', 'version', 'home', 'onboarding', 'posts', 'located', 'rendered']);
        self::require($record['format'] === 'wprism-wpforms-capture-probe/v1'
            && $record['phase'] === $phase && $record['version'] === '2.0.1.1' && $record['home'] === $home, 'native transport identity differs');
        self::require($record['onboarding'] === ['version' => '2.0.1.1', 'edition' => 'lite'], 'native onboarding is incomplete');
        self::keys($record['posts'], ['integer', 'string', 'template', 'destination', 'embed']);
        self::keys($record['rendered'], ['integer', 'string']);
        foreach ($record['posts'] as $role => $post) {
            self::keys($post, ['id', 'type', 'slug', 'body', 'uuid']);
            self::require(is_int($post['id']) && $post['id'] > 0, 'non-positive native fixture identity');
            $type = match ($role) { 'integer', 'string' => 'wpforms', 'template' => 'wpforms-template', default => 'page' };
            self::require($post['type'] === $type && $post['slug'] === 'wprism-wpf-' . $role
                && is_string($post['body']) && $post['body'] !== '', 'native fixture topology differs');
            self::require($phase === 'seed' ? $post['uuid'] === null
                : is_string($post['uuid']) && preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $post['uuid']) === 1,
                'native fixture has no valid phase-bound mapping');
        }
        self::require(count(array_unique(array_column($record['posts'], 'id'))) === 5, 'native fixture IDs overlap');
        if ($phase === 'observe') {
            self::require(count(array_unique(array_column($record['posts'], 'uuid'))) === 5, 'native fixture mappings overlap');
        }
        $destination = $record['posts']['destination']['id'];
        foreach (['integer', 'string'] as $role) {
            $post = $record['posts'][$role];
            $doc = json_decode($post['body'], true, 32, JSON_THROW_ON_ERROR);
            self::require(($doc['id'] ?? null) === ($role === 'integer' ? $post['id'] : (string) $post['id']), 'native self-reference type differs');
            self::require(($doc['fields'][1]['type'] ?? null) === 'text'
                && ($doc['fields'][1]['default_value'] ?? null) === 'Public fixture'
                && ($doc['fields'][2]['type'] ?? null) === 'email', 'native field premises differ');
            self::require(($doc['settings']['confirmations'] ?? null) === [
                1 => ['type' => 'message', 'message' => 'Thanks 日本語'],
                2 => ['type' => 'page', 'page' => (string) $destination],
                3 => ['type' => 'page', 'page' => 'previous_page'],
                4 => ['type' => 'redirect', 'redirect' => $home . '/wprism-wpf-destination/?page_id=' . $destination],
            ], 'native confirmation references differ');
            self::require(($doc['settings']['notifications'] ?? null) === [1 => [
                'email' => 'operations@example.test', 'sender_name' => 'WPrism notifications Ω',
                'sender_address' => 'forms@example.test', 'replyto' => 'support@example.test',
                'subject' => 'Native fixture', 'message' => '{all_fields}',
            ]], 'native notification premise differs');
            $html = $record['rendered'][$role];
            self::require(is_string($html) && str_contains($html, 'id="wpforms-form-' . $post['id'] . '"')
                && str_contains($html, 'name="wpforms[fields][1]"')
                && str_contains($html, 'name="wpforms[fields][2]"'), 'native renderer did not expose both fixture fields');
        }
        $template = json_decode($record['posts']['template']['body'], true, 32, JSON_THROW_ON_ERROR);
        self::require(is_array($template) && !array_key_exists('id', $template)
            && ($template['settings']['form_title'] ?? null) === 'WPrism WPForms template', 'native template absent-self premise differs');
        $integer = $record['posts']['integer']['id'];
        $string = $record['posts']['string']['id'];
        self::require($record['located'] === [$integer, $string], 'native locator did not resolve both distinct forms');
        self::require($record['posts']['embed']['body'] === '[wpforms id="' . $integer . '" title="true"]' . "\n"
            . '<!-- wp:wpforms/form-selector {"formId":"' . $string . '","displayTitle":true} /-->', 'native embed bytes differ');
        self::require($record['posts']['destination']['body'] === 'Native confirmation destination.', 'native destination differs');
    }

    /** Only fixture-declared reference paths change; all other native bytes survive. */
    public static function expectedBody(array $record, string $role): string {
        $post = $record['posts'][$role];
        if ($role === 'destination') {
            return $post['body'];
        }
        $doc = json_decode($post['body'], false, 32, JSON_THROW_ON_ERROR);
        if ($role !== 'template') {
            $doc->id = (object) [
                'format' => 'wprism-typed-reference/v1', 'type' => $role === 'integer' ? 'int' : 'string',
                'ref' => '{{post:' . $post['uuid'] . '}}',
            ];
            $destination = '{{post:' . $record['posts']['destination']['uuid'] . '}}';
            $doc->settings->confirmations->{'2'}->page = $destination;
            $doc->settings->confirmations->{'4'}->redirect = '{{home}}/wprism-wpf-destination/?page_id=' . $destination;
        }
        return json_encode($doc, JSON_THROW_ON_ERROR);
    }

    private static function keys(array $record, array $expected): void {
        $keys = array_keys($record);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        self::require($keys === $expected, 'observation has missing or extra members');
    }

    private static function require(bool $ok, string $message): void {
        if (!$ok) {
            throw new RuntimeException('WPForms capture probe: ' . $message);
        }
    }
}

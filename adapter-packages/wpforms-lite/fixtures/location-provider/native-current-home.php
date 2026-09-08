<?php
declare(strict_types=1);

// Native public writer/UI evidence for the unshipped URL policy only. The
// fixture intentionally supplies metadata to the renderer; no Apply/provider
// invocation, cache-input admission or private Locator method is claimed.
require_once WPMU_PLUGIN_DIR . '/wprism/src/Adapter/ManifestProviderRuntime.php';
require_once __DIR__ . '/wpforms-form-locations.php';
$phase = $args[0] ?? '';
$check = static function (bool $value, string $label): void {
    if (!$value) throw new RuntimeException('WPForms current-home fixture: ' . $label);
};
$check(defined('WPFORMS_VERSION') && WPFORMS_VERSION === '2.0.1.1', 'wrong exact native version');
$check(current_user_can('manage_options'), 'native administrator required');
$locator = wpforms()->obj('locator');
$check(is_object($locator) && get_class($locator) === 'WPForms\\Forms\\Locator', 'initialized public Locator required');
$relative = static fn(string $home, string $url): string => (new ReflectionMethod(
    WPrism\Providers\WpformsFormLocations::class, 'relative_location_url'))->invoke(null, $home, $url);
$render = static fn(int $form): string => $locator->column_value('', get_post($form), WPForms\Forms\Locator::COLUMN_NAME);
$find = static function (string $type, string $slug) use ($check): int {
    $rows = get_posts(['post_type' => $type, 'name' => $slug, 'post_status' => 'any',
        'posts_per_page' => 2, 'suppress_filters' => true]);
    $check(count($rows) === 1, 'unique native identity required');
    return (int) $rows[0]->ID;
};
$observe = static fn(int $form, int $page): array => ['home' => home_url(), 'url' => get_permalink($page),
    'locations' => get_post_meta($form, 'wpforms_form_locations', true), 'html' => $render($form)];
$result = ['format' => 'wprism-wpforms-current-home/v1', 'phase' => $phase, 'version' => WPFORMS_VERSION];
if ($phase === 'seed') {
    $form = wpforms()->obj('form')->add('WPrism current-home fixture',
        ['post_name' => 'wprism-current-home-form'], ['builder' => false]);
    $check(is_int($form) && $form > 0, 'native form creation failed');
    $page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish',
        'post_title' => 'WPrism current-home page', 'post_name' => 'wprism-current-home-page',
        'post_content' => '[wpforms id="' . $form . '"]'], true);
    $check(is_int($page) && $page > 0, 'native page creation failed');
    $result['initial'] = $observe($form, $page);
    update_option('home', 'http://current.example.test/base');
    $check(home_url() === 'http://current.example.test/base', 'new durable current home was not installed');
    $check(wp_update_post(['ID' => $page, 'post_title' => 'Current home changed title'], true) === $page,
        'public post-updated writer failed');
    $result['historical'] = $observe($form, $page);
    $locations = $result['historical']['locations'];
    $check(is_array($locations) && count($locations) === 1, 'expected one native post location');
    $locations[0]['url'] = $relative(home_url(), get_permalink($page));
    update_post_meta($form, 'wpforms_form_locations', wp_slash($locations));
    $result['canonical'] = get_post_meta($form, 'wpforms_form_locations', true);
} elseif ($phase === 'observe' || $phase === 'rehome') {
    $form = $find('wpforms', 'wprism-current-home-form');
    $page = $find('page', 'wprism-current-home-page');
    $result['fresh'] = $observe($form, $page);
    if ($phase === 'observe') {
        $home = home_url();
        $canonical = $result['fresh']['locations'];
        $cases = ['plain' => '/probe', 'query_home' => '/probe?next=' . $home . '/path',
            'utf8' => '/%E6%9D%B1%E4%BA%AC', 'unreserved' => '/%41%7a%30%2d%5f%7e',
            'encoded_question' => '/probe%3Fnext', 'encoded_ampersand' => '/probe?q=a%26b',
            'literal_plus' => '/probe+a', 'encoded_plus' => '/probe%2Ba',
            'encoded_percent' => '/probe%252foutside', 'encoded_quote' => '/probe?q=%22%20x%3D%22y',
            'encoded_hash' => '/probe%23tail'];
        foreach ($cases as $name => $suffix) {
            $candidate = null;
            try { $candidate = $relative($home, $home . $suffix); }
            catch (RuntimeException $failure) {
                $check(str_contains($failure->getMessage(), 'current-home renderer frontier'), 'unexpected candidate refusal');
            }
            // Deliberate fixture bypass: render the native pre-fix bytes even
            // when the candidate refuses them, so the host sees the defect.
            $locations = $canonical;
            $locations[0]['url'] = $suffix;
            update_post_meta($form, 'wpforms_form_locations', wp_slash($locations));
            $result['cases'][$name] = ['url' => $home . $suffix, 'candidate' => $candidate,
                'locations' => get_post_meta($form, 'wpforms_form_locations', true), 'html' => $render($form)];
        }
        update_post_meta($form, 'wpforms_form_locations', wp_slash($canonical));
        update_option('home', 'http://next.example.test/other');
    }
} else {
    throw new RuntimeException('WPForms current-home fixture: unknown phase');
}
echo wp_json_encode($result, JSON_THROW_ON_ERROR);

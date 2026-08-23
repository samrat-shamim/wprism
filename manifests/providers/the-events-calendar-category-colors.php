<?php
declare(strict_types=1);

namespace Duo\Providers;

use Duo\Policy;

/**
 * The Events Calendar Category Colors derived-CSS regeneration provider.
 *
 * TEC writes category colors as term metadata, then regenerates the
 * `tec_events_category_color_css` option only from its wp-admin save hook.
 * Duo materializes term metadata directly, so that hook is unreachable and a
 * target otherwise keeps its old colors indefinitely. The native CSS
 * controller remains the sole generator here; this provider supplies the
 * structured invocation and verifies every generated selector/value against
 * the same term metadata and color utility used by TEC 6.17.2/6.17.3.
 */
final class TheEventsCalendarCategoryColors {
    private Policy $policy;

    private const CSS_OPTION = 'tec_events_category_color_css';
    private const TAXONOMY = 'tribe_events_cat';
    private const COLOR_META = [
        'primary' => 'tec-events-cat-colors-primary',
        'secondary' => 'tec-events-cat-colors-secondary',
        'text' => 'tec-events-cat-colors-text',
    ];
    private const COLOR_PROPERTIES = [
        'primary' => '--tec-color-category-primary',
        'secondary' => '--tec-color-category-secondary',
        'text' => '--tec-color-category-text',
    ];

    public function __construct(Policy $policy) {
        $this->policy = $policy;
    }

    /** @return array{id:string,plugin:string,version:string} */
    public function identity(): array {
        return [
            'id' => 'the-events-calendar-category-colors',
            'plugin' => 'the-events-calendar/the-events-calendar.php',
            'version' => '1.0.0',
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public function capabilities(): array {
        return [
            'regenerate_css' => [
                'args' => [],
                'reads' => [
                    'term:tribe_events_cat',
                    'option:tec_events_category_color_css',
                    'option:tribe_events_calendar_options',
                ],
                'writes' => ['option:tec_events_category_color_css'],
                'scope' => 'site',
                'idempotent' => true,
                // Native generation and both exact projections walk every
                // event category; the bound must accommodate a large real
                // taxonomy rather than only the conformance fixture.
                'timeout_seconds' => 120,
                'scoped' => [
                    'operation_envelope' => \Duo\Providers::SCOPED_OPERATION_FORMAT,
                    'reconcile' => true,
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $args */
    public function invoke(string $capability, array $args): array {
        return match ($capability) {
            'regenerate_css' => $this->regenerate_css(),
            default => throw new \RuntimeException(
                "duo: The Events Calendar Category Colors provider does not implement capability '$capability'"
            ),
        };
    }

    /** @param array<string,mixed> $args @param array<string,mixed> $operation */
    public function invoke_scoped(string $capability, array $args, array $operation): array {
        $receipt = $this->invoke($capability, $args);
        return [
            'operation' => $operation,
            'before' => $receipt['before'],
            'after' => $receipt['after'],
            'verified' => true,
        ];
    }

    /** @param array<string,mixed> $args @param array<string,mixed> $operation */
    public function reconcile_scoped(string $capability, array $args, array $operation): array {
        if ($capability !== 'regenerate_css') {
            throw new \RuntimeException(
                "duo: The Events Calendar Category Colors provider does not implement capability '$capability'"
            );
        }
        return [
            'operation' => $operation,
            'after' => $this->projection(true),
            'verified' => true,
        ];
    }

    /** @return array{before:array<string,mixed>,after:array<string,mixed>,verified:true} */
    private function regenerate_css(): array {
        $this->assert_runtime_contract();
        $before = $this->projection(false);

        $controller = tribe(\TEC\Events\Category_Colors\CSS\Controller::class);
        if (!is_object($controller) || !is_callable([$controller, 'generate_css'])) {
            throw new \RuntimeException(
                'duo: The Events Calendar 6.17.x Category Colors CSS controller is unavailable'
            );
        }
        $controller->generate_css();

        return [
            'before' => $before,
            'after' => $this->projection(true),
            'verified' => true,
        ];
    }

    private function assert_runtime_contract(): void {
        foreach ([
            'get_option',
            'get_term_meta',
            'get_terms',
            'is_wp_error',
            'sanitize_html_class',
            'sanitize_title',
            'tribe',
            'tribe_get_option',
        ] as $required) {
            if (!function_exists($required)) {
                throw new \RuntimeException(
                    "duo: The Events Calendar Category Colors regeneration requires $required()"
                );
            }
        }
        foreach ([
            \TEC\Events\Category_Colors\CSS\Controller::class,
            \TEC\Events\Category_Colors\CSS\Generator::class,
            \TEC\Events\Category_Colors\Repositories\Category_Color_Dropdown_Provider::class,
            \Tribe__Utils__Color::class,
        ] as $required) {
            if (!class_exists($required)) {
                throw new \RuntimeException(
                    "duo: The Events Calendar Category Colors regeneration requires class $required"
                );
            }
        }
    }

    /** @return array<string,mixed> */
    private function projection(bool $verify): array {
        $this->assert_runtime_contract();
        $stored = get_option(self::CSS_OPTION, null);
        if (!is_string($stored)) {
            if ($verify) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Category Colors CSS option is absent or non-string after regeneration; '
                    . 'recovery_required'
                );
            }
            $stored = '';
        }

        $terms = get_terms(['taxonomy' => self::TAXONOMY, 'hide_empty' => false]);
        if (is_wp_error($terms) || !is_array($terms)) {
            throw new \RuntimeException(
                'duo: The Events Calendar Category Colors taxonomy inventory is unreadable'
            );
        }

        $showHidden = tribe_get_option('category-color-show-hidden-categories', false);
        $showHidden = is_bool($showHidden) ? $showHidden : false;
        $expected = [];
        $expectedColoredCount = 0;
        $expectedDropdown = [];
        foreach ($terms as $term) {
            if (!is_object($term) || !isset($term->term_id, $term->slug)) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Category Colors taxonomy returned a malformed term'
                );
            }
            $properties = [];
            $rawColors = [];
            foreach (self::COLOR_META as $name => $metaKey) {
                $raw = get_term_meta((int) $term->term_id, $metaKey, true);
                $rawColors[$name] = is_string($raw) ? $raw : '';
                $hex = $this->native_hex($raw);
                if ($hex !== '') {
                    $properties[self::COLOR_PROPERTIES[$name]] = $hex;
                }
            }
            if ($properties !== []) {
                ++$expectedColoredCount;
                $expected[
                    '.tribe_events_cat-' . sanitize_html_class(sanitize_title((string) $term->slug))
                ] = $properties;
            }

            $hidden = (bool) get_term_meta(
                (int) $term->term_id,
                'tec-events-cat-colors-hidden',
                true
            );
            $priority = get_term_meta(
                (int) $term->term_id,
                'tec-events-cat-colors-priority',
                true
            );
            if ($rawColors['primary'] !== '' && ($showHidden || !$hidden)) {
                $slug = (string) $term->slug;
                $expectedDropdown[$slug] = [
                    'slug' => $slug,
                    'primary' => $rawColors['primary'],
                    'priority' => is_numeric($priority) ? (int) $priority : -1,
                    'hidden' => $hidden,
                ];
            }
        }
        ksort($expected, SORT_STRING);
        ksort($expectedDropdown, SORT_STRING);

        preg_match_all('/\\.tribe_events_cat-[a-z0-9_-]*\\{/', strtolower($stored), $selectorMatches);
        $actualSelectors = array_map(
            static fn(string $selector): string => substr($selector, 0, -1),
            $selectorMatches[0]
        );
        sort($actualSelectors, SORT_STRING);
        $uniqueActualSelectors = array_values(array_unique($actualSelectors));
        $expectedSelectors = array_keys($expected);
        $selectorMismatchCount = count(array_diff($expectedSelectors, $uniqueActualSelectors))
            + count(array_diff($uniqueActualSelectors, $expectedSelectors))
            + (count($actualSelectors) - count($uniqueActualSelectors))
            + ($expectedColoredCount - count($expectedSelectors));

        $valueMismatchCount = 0;
        $normalized = strtolower($stored);
        foreach ($expected as $selector => $properties) {
            $matched = preg_match(
                '/' . preg_quote(strtolower($selector), '/') . '\\{([^}]*)\\}/D',
                $normalized,
                $block
            );
            if ($matched !== 1) {
                continue;
            }
            foreach ($properties as $property => $hex) {
                if (!str_contains($block[1], strtolower($property . ':' . $hex))) {
                    ++$valueMismatchCount;
                }
            }
        }

        [$actualDropdown, $dropdownMalformedCount, $dropdownReadError] =
            $this->dropdown_projection($verify);
        $dropdownMismatchCount = $dropdownMalformedCount;
        foreach ($expectedDropdown as $slug => $row) {
            if (!isset($actualDropdown[$slug]) || $actualDropdown[$slug] !== $row) {
                ++$dropdownMismatchCount;
            }
        }
        $dropdownMismatchCount += count(array_diff_key($actualDropdown, $expectedDropdown));

        if ($verify && ($selectorMismatchCount > 0 || $valueMismatchCount > 0)) {
            throw new \RuntimeException(
                'duo: The Events Calendar Category Colors CSS readback has '
                . $selectorMismatchCount . ' selector-set mismatch(es) and '
                . $valueMismatchCount . ' value mismatch(es); recovery_required'
            );
        }
        if ($verify && ($dropdownReadError || $dropdownMismatchCount > 0)) {
            throw new \RuntimeException(
                'duo: The Events Calendar Category Colors dropdown readback has '
                . $dropdownMismatchCount . ' row mismatch(es)'
                . ($dropdownReadError ? ' and an unreadable native result' : '')
                . '; recovery_required'
            );
        }

        return [
            'category_count' => count($terms),
            'colored_category_count' => count($expected),
            'css_bytes' => strlen($stored),
            'css_sha256' => hash('sha256', $stored),
            'css_selector_count' => count($uniqueActualSelectors),
            'css_selector_mismatch_count' => $selectorMismatchCount,
            'css_value_mismatch_count' => $valueMismatchCount,
            'dropdown_expected_count' => count($expectedDropdown),
            'dropdown_actual_count' => count($actualDropdown),
            'dropdown_expected_sha256' => $this->rows_digest($expectedDropdown),
            'dropdown_actual_sha256' => $this->rows_digest($actualDropdown),
            'dropdown_mismatch_count' => $dropdownMismatchCount,
            'dropdown_read_error' => $dropdownReadError,
        ];
    }

    /** @return array{0:array<string,array<string,mixed>>,1:int,2:bool} */
    private function dropdown_projection(bool $verify): array {
        $provider = tribe(
            \TEC\Events\Category_Colors\Repositories\Category_Color_Dropdown_Provider::class
        );
        if (!is_object($provider) || !is_callable([$provider, 'get_dropdown_categories'])) {
            if ($verify) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Category Colors dropdown provider is unavailable; '
                    . 'recovery_required'
                );
            }
            return [[], 1, true];
        }
        try {
            $rows = $provider->get_dropdown_categories();
        } catch (\Throwable $failure) {
            if ($verify) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Category Colors dropdown provider failed readback; '
                    . 'recovery_required',
                    0,
                    $failure
                );
            }
            return [[], 1, true];
        }
        if (!is_array($rows)) {
            if ($verify) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Category Colors dropdown provider returned a non-list; '
                    . 'recovery_required'
                );
            }
            return [[], 1, true];
        }

        $normalized = [];
        $malformed = array_is_list($rows) ? 0 : 1;
        foreach ($rows as $row) {
            if (!is_array($row)
                || !is_string($row['slug'] ?? null)
                || !is_string($row['primary'] ?? null)
                || !is_int($row['priority'] ?? null)
                || !is_bool($row['hidden'] ?? null)) {
                ++$malformed;
                continue;
            }
            $slug = $row['slug'];
            if (isset($normalized[$slug])) {
                ++$malformed;
                continue;
            }
            $normalized[$slug] = [
                'slug' => $slug,
                'primary' => $row['primary'],
                'priority' => $row['priority'],
                'hidden' => $row['hidden'],
            ];
        }
        ksort($normalized, SORT_STRING);
        return [$normalized, $malformed, false];
    }

    /** @param array<string,array<string,mixed>> $rows */
    private function rows_digest(array $rows): string {
        return hash('sha256', (string) json_encode(array_values($rows), JSON_UNESCAPED_SLASHES));
    }

    private function native_hex(mixed $value): string {
        if (!is_string($value) || $value === '') {
            return '';
        }
        try {
            $hex = (new \Tribe__Utils__Color($value))->get_hex_with_hash();
        } catch (\Throwable) {
            return '';
        }
        return is_string($hex) ? strtolower($hex) : '';
    }
}

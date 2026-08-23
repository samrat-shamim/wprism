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
                'reads' => ['term:tribe_events_cat', 'option:tec_events_category_color_css'],
                'writes' => ['option:tec_events_category_color_css'],
                'scope' => 'site',
                'idempotent' => true,
                'timeout_seconds' => 30,
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
        foreach (['get_option', 'get_term_meta', 'get_terms', 'is_wp_error', 'sanitize_html_class', 'tribe'] as $required) {
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

        $expected = [];
        foreach ($terms as $term) {
            if (!is_object($term) || !isset($term->term_id, $term->slug)) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Category Colors taxonomy returned a malformed term'
                );
            }
            $properties = [];
            foreach (self::COLOR_META as $name => $metaKey) {
                $hex = $this->native_hex(get_term_meta((int) $term->term_id, $metaKey, true));
                if ($hex !== '') {
                    $properties[self::COLOR_PROPERTIES[$name]] = $hex;
                }
            }
            if ($properties !== []) {
                $expected['.tribe_events_cat-' . sanitize_html_class((string) $term->slug)] = $properties;
            }
        }
        ksort($expected, SORT_STRING);

        $missing = [];
        $normalized = strtolower($stored);
        foreach ($expected as $selector => $properties) {
            $matched = preg_match(
                '/' . preg_quote(strtolower($selector), '/') . '\\{([^}]*)\\}/D',
                $normalized,
                $block
            );
            if ($matched !== 1) {
                $missing[] = $selector;
                continue;
            }
            foreach ($properties as $property => $hex) {
                if (!str_contains($block[1], strtolower($property . ':' . $hex))) {
                    $missing[] = $selector . ':' . $property;
                }
            }
        }
        sort($missing, SORT_STRING);
        if ($verify && $missing !== []) {
            throw new \RuntimeException(
                'duo: The Events Calendar Category Colors CSS readback is missing '
                . count($missing) . ' native selector/value projection(s); recovery_required'
            );
        }

        return [
            'category_count' => count($terms),
            'colored_category_count' => count($expected),
            'css_bytes' => strlen($stored),
            'css_sha256' => hash('sha256', $stored),
            'missing_projection_count' => count($missing),
        ];
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

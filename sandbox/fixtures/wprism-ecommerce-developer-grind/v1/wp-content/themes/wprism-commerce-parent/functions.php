<?php

defined('ABSPATH') || exit;

add_action('after_setup_theme', static function (): void {
    add_theme_support('woocommerce');
    register_nav_menus(['primary' => 'WPrism Commerce Primary']);
});

add_filter('body_class', static function (array $classes): array {
    $classes[] = 'wprism-commerce-storefront';
    return $classes;
});

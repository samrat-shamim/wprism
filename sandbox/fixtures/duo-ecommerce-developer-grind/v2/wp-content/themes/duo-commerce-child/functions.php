<?php

defined('ABSPATH') || exit;

add_action('wp_enqueue_scripts', static function (): void {
    wp_enqueue_style('duo-commerce-parent', get_template_directory_uri() . '/style.css');
    wp_enqueue_style('duo-commerce-child', get_stylesheet_directory_uri() . '/style.css', ['duo-commerce-parent']);
});

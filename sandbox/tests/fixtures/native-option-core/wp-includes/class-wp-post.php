<?php
declare(strict_types=1);

/** Test-only WP 7.1 get_instance ordering; actual core remains a separate native gate. */
#[AllowDynamicProperties]
final class WP_Post {
    public function __construct(object $post) {
        foreach (get_object_vars($post) as $key => $value) $this->$key = $value;
    }

    public static function get_instance(int $id): self|false {
        global $wpdb;
        if ($id <= 0) return false;
        $post = wp_cache_get($id, 'posts');
        if (!$post instanceof stdClass && !$post instanceof self) {
            $post = $wpdb->get_row($wpdb->prepare("SELECT * FROM $wpdb->posts WHERE ID = %d LIMIT 1", $id));
            if (!$post) return false;
            $post = sanitize_post($post, 'raw');
            wp_cache_add((int) $post->ID, $post, 'posts');
        } elseif (empty($post->filter) || $post->filter !== 'raw') {
            $post = sanitize_post($post, 'raw');
        }
        return new self($post);
    }

    public function filter(string $context): self|false {
        return ($this->filter ?? null) === $context ? $this : self::get_instance((int) $this->ID);
    }

    public function __get(string $name): mixed {
        if ($name === 'ancestors') return get_post_ancestors($this);
        return null;
    }
}

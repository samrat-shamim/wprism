<?php
declare(strict_types=1);

/** Only fields consumed by the opt-in permalink graph are modeled here. */
class WP_Post_Type {
    public bool $_builtin = true;
    public bool $map_meta_cap = true;
    public string $name = '';
    public bool $hierarchical = false;
    public string|false $query_var = false;
    public array|bool $rewrite = false;
    public object $cap;
    public function __construct() { $this->cap = (object) ['read_private_posts' => 'read_private_posts', 'read_post' => 'read_post']; }
}

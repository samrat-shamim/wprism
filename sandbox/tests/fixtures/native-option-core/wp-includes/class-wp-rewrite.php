<?php
declare(strict_types=1);

/** Native init/lazy-page dependency ordering; no rule-generation fixture. */
#[AllowDynamicProperties]
class WP_Rewrite {
    public string $permalink_structure;
    public string $front;
    public string $root = '';
    public string $index = 'index.php';
    public bool $use_trailing_slashes;
    public array $extra_permastructs = [];

    public function __construct() {
        $this->permalink_structure = get_option('permalink_structure');
        $this->front = substr($this->permalink_structure, 0, (int) strpos($this->permalink_structure, '%'));
        if (preg_match('#^/*' . $this->index . '#', $this->permalink_structure)) $this->root = $this->index . '/';
        $this->use_trailing_slashes = str_ends_with($this->permalink_structure, '/');
    }

    public function get_page_permastruct(): string|false {
        if (isset($this->page_structure)) return $this->page_structure;
        if ($this->permalink_structure === '') {
            $this->page_structure = '';
            return false;
        }
        return $this->page_structure = $this->root . '%pagename%';
    }

    public function get_extra_permastruct(string $name): string|false {
        return $this->permalink_structure === '' ? false : ($this->extra_permastructs[$name]['struct'] ?? false);
    }

    public function add_permastruct(string $name, string $struct, array $args = []): void {
        $defaults = ['with_front' => true, 'ep_mask' => 0, 'paged' => true, 'feed' => true,
            'forcomments' => false, 'walk_dirs' => true, 'endpoints' => true];
        $args = array_merge($defaults, array_intersect_key($args, $defaults));
        $args['struct'] = ($args['with_front'] ? $this->front : $this->root) . $struct;
        $this->extra_permastructs[$name] = $args;
    }
}

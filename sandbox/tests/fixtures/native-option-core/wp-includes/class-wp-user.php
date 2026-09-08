<?php
declare(strict_types=1);

class WP_User {
    public int $ID = 0;
    public array $allcaps = [];
    public function has_cap(string $cap, int $id): bool {
        $caps = map_meta_cap($cap, $this->ID, $id);
        $all = apply_filters('user_has_cap', $this->allcaps, $caps, [$cap, $this->ID, $id], $this);
        $all['exist'] = true;
        unset($all['do_not_allow']);
        foreach ($caps as $required) if (empty($all[$required])) return false;
        return true;
    }
}

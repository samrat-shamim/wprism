<?php
declare(strict_types=1);

/**
 * Semantic double only: the capsule's live gate supplies the real 3.8.6
 * factory. Database observation and capture are never replaced by this seam.
 */
final class WP_Term {
    public string $term_id;
    public string $name;
    public string $slug;
    public string $term_group;
    public string $term_taxonomy_id;
    public string $taxonomy;
    public string $description;
    public string $parent;
    public string $count;

    public function __construct(object $row) {
        foreach (get_object_vars($row) as $name => $value) $this->$name = $value;
    }
}

final class PLL_Language_Factory {
    /** @var list<array<string,WP_Term>> */
    public static array $inputs = [];
    public static ?Closure $interpret = null;

    public function __construct(public object $options) {}

    public function get_from_terms(array $terms): mixed {
        self::$inputs[] = $terms;
        if (self::$interpret !== null) return (self::$interpret)($terms);
        $term = $terms['language'];
        $description = unserialize($term->description, ['allowed_classes' => false]);
        return (object) [
            'slug' => $term->slug,
            'flag_code' => $description['flag_code'],
            'flag_url' => $description['flag_code'] === '' ? '' : 'https://fixture.test/flags/' . $description['flag_code'] . '.png',
        ];
    }
}

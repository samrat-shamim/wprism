<?php
declare(strict_types=1);

namespace {
    final class ReferenceScopeFakeWpdb {
        public string $posts = 'wp_posts';
        public string $term_taxonomy = 'wp_term_taxonomy';
        /** @var array<int,array{post_type:string,post_status:string}> */
        public array $postsById = [];
        /** @var array<int,string> */
        public array $taxonomiesByTermId = [];

        public function prepare(string $query, ...$args): array {
            return ['query' => $query, 'args' => $args];
        }

        public function get_var(array $prepared) {
            $query = $prepared['query'];
            $id = (int) ($prepared['args'][0] ?? 0);
            if (str_contains($query, 'SELECT post_type')) {
                $row = $this->postsById[$id] ?? null;
                if ($row === null || $row['post_type'] === 'revision' || $row['post_status'] === 'auto-draft') {
                    return null;
                }
                return $row['post_type'];
            }
            if (str_contains($query, 'SELECT taxonomy')) {
                return $this->taxonomiesByTermId[$id] ?? null;
            }
            throw new \RuntimeException('unexpected reference-scope query');
        }
    }

    function check(bool $condition, string $message): void {
        if (!$condition) {
            fwrite(STDERR, "FAIL: $message\n");
            exit(1);
        }
        echo "ok: $message\n";
    }

    $wpdb = new ReferenceScopeFakeWpdb();
    $GLOBALS['wpdb'] = $wpdb;

    require __DIR__ . '/../../agent/src/Policy.php';
    require __DIR__ . '/../../agent/src/ReferenceScopeClassifier.php';

    $policy = new \Duo\Policy();
    $wpdb->postsById = [
        10 => ['post_type' => 'page', 'post_status' => 'publish'],
        11 => ['post_type' => 'elementor_library', 'post_status' => 'publish'],
        12 => ['post_type' => 'revision', 'post_status' => 'inherit'],
        13 => ['post_type' => 'post', 'post_status' => 'auto-draft'],
    ];
    $wpdb->taxonomiesByTermId = [20 => 'category', 21 => 'product_cat'];

    check(class_exists(\Duo\ReferenceScopeClassifier::class, false), 'classifier loads as a direct boundary');
    check(!class_exists(\Duo\Capture::class, false), 'classifier does not load Capture');
    check(\Duo\ReferenceScopeClassifier::targetType(11, 'post') === 'elementor_library', 'live post target type is observed');
    check(\Duo\ReferenceScopeClassifier::targetType(21, 'term') === 'product_cat', 'live term taxonomy is observed');
    check(\Duo\ReferenceScopeClassifier::targetType(12, 'post') === null, 'revisions are not portable targets');
    check(\Duo\ReferenceScopeClassifier::targetType(13, 'post') === null, 'auto-drafts are not portable targets');
    check(\Duo\ReferenceScopeClassifier::classify(999, 'post', false, $policy) === null, 'dangling ids are not scope violations');
    check(\Duo\ReferenceScopeClassifier::classify(10, 'post', false, $policy) === null, 'live in-scope unminted ids are not scope violations');
    check(\Duo\ReferenceScopeClassifier::classify(11, 'post', false, $policy) === 'elementor_library', 'live out-of-scope posts are classified');
    check(\Duo\ReferenceScopeClassifier::classify(21, 'term', false, $policy) === 'product_cat', 'live out-of-scope terms are classified');
    check(\Duo\ReferenceScopeClassifier::classify(11, 'post', true, $policy) === null, 'force preserves best-effort drop behavior');

    echo "REGRESS_REFERENCE_SCOPE_CLASSIFIER PASSED\n";
}

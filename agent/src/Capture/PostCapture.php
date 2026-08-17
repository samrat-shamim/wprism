<?php
namespace Duo;

require_once __DIR__ . '/../Grammar/Blocks.php';
require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/EntityMetaCapture.php';
require_once __DIR__ . '/../Repository/Ledger.php';
require_once __DIR__ . '/MediaCapture.php';
require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Kernel/Secrets.php';
require_once __DIR__ . '/../Grammar/Tokens.php';

/** Builds canonical post entities after post, term, and table identities exist. */
final class PostCapture {
    /** @var array<int,string> */
    private array $userLogins = [];

    public function __construct(
        private Policy $policy,
        private Tokens $tokens,
        private EntityMetaCapture $entityMetaCapture,
        private MediaCapture $mediaCapture
    ) {}

    /**
     * @param array<string,string[]> $taxonomiesByPostType
     * @return array{entity:array,media_ref:?array{0:string,1:array{path?:string,bytes?:string}}}
     */
    public function capture(
        object $post,
        string $uuid,
        array $taxonomiesByPostType,
        bool $forceUnresolvedRefs = false,
        bool $strictReadOnly = false
    ): array {
        global $wpdb;
        $id = (int) $post->ID;
        $isAttachment = $post->post_type === 'attachment';

        if ((string) $post->post_password !== '') {
            throw new \RuntimeException(
                "duo: protected {$post->post_type} '{$post->post_name}' (post $id) has post_password; "
                . 'spec v2 has no portable secret representation for post passwords, so capture refuses it'
            );
        }

        $byKey = $this->entityMetaCapture->postMetaByKey($id);
        $meta = [];
        $attachedFile = null;
        $alt = '';
        $flatMeta = array_map(static fn($values) => $values[0], $byKey);
        foreach ($byKey as $key => $values) {
            if ($key === '_wp_attached_file') {
                $attachedFile = $values[0];
                continue;
            }
            if ($key === '_wp_attachment_image_alt') {
                $alt = (string) $values[0];
                continue;
            }
            [$store, $value] = $this->entityMetaCapture->classifyValue(
                $key,
                $values,
                $flatMeta,
                "post $id",
                'post_meta'
            );
            if ($store) {
                $meta[$key] = $value;
            }
        }

        $parent = null;
        if ((int) $post->post_parent > 0) {
            $parent = $this->tokens->id_to_token((int) $post->post_parent, 'post');
            if ($parent === null) {
                throw new \RuntimeException(
                    "duo: post {$post->post_name} has unmanaged parent post {$post->post_parent} — capture scope must include it"
                );
            }
        }

        $taxonomies = $taxonomiesByPostType[$post->post_type] ?? [];
        $terms = [];
        $termOrders = [];
        if ($taxonomies) {
            $in = "'" . implode("','", array_map('esc_sql', $taxonomies)) . "'";
            $relationships = $wpdb->get_results($wpdb->prepare(
                "SELECT tt.taxonomy, tt.term_id, tr.term_order FROM {$wpdb->term_relationships} tr
                 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 WHERE tr.object_id = %d AND tt.taxonomy IN ($in)",
                $id
            )) ?: [];
            foreach ($relationships as $relationship) {
                $termUuid = Ledger::uuid_for((int) $relationship->term_id, Ledger::KIND_TERM);
                if ($termUuid !== null) {
                    $terms[$relationship->taxonomy][] = $termUuid;
                    $termOrders[$relationship->taxonomy][$termUuid] = (int) $relationship->term_order;
                }
            }
            foreach ($terms as &$list) {
                sort($list, SORT_STRING);
            }
            unset($list);
        }

        $front = [
            'uuid' => $uuid,
            'type' => $post->post_type,
            'slug' => $post->post_name,
            'title' => $post->post_title,
            'status' => $post->post_status,
            'date' => $post->post_date,
            'date_gmt' => $post->post_date_gmt,
            'modified' => $post->post_modified,
            'modified_gmt' => $post->post_modified_gmt,
            'author' => $this->authorToken((int) $post->post_author),
            'parent' => $parent,
            'menu_order' => (int) $post->menu_order,
            'comment_status' => $post->comment_status,
            'ping_status' => $post->ping_status,
            'excerpt' => $this->tokens->tokenize_text((string) $post->post_excerpt),
            'meta' => (object) $meta,
            'terms' => (object) $terms,
            'term_orders' => (object) array_map(static fn($orders) => (object) $orders, $termOrders),
        ];

        $mediaRef = null;
        if ($isAttachment) {
            $attachment = $this->mediaCapture->capture(
                $id,
                $attachedFile,
                (string) $post->post_mime_type,
                $alt,
                $strictReadOnly
            );
            $front += $attachment['front'];
            $mediaRef = $attachment['media_ref'];
        }

        $secretLabel = Secrets::hard_match((string) $post->post_content);
        if ($secretLabel !== null) {
            $this->tokens->warnings[] =
                "{$post->post_type} '{$post->post_name}' body looks like it contains a $secretLabel — review before committing (not blocked: bodies may legitimately discuss credentials)";
        }

        if ($this->policy->body_mode($post->post_type) === 'verbatim') {
            $body = (string) $post->post_content;
            if ($body !== '' && str_contains($body, $this->tokens->home())) {
                $this->tokens->warnings[] =
                    "verbatim body of {$post->post_type} '{$post->post_name}' contains this environment's home URL — it will NOT be re-bound on apply";
            }
        } else {
            $body = Blocks::capture_rewrite(
                (string) $post->post_content,
                $this->policy,
                $this->tokens,
                $forceUnresolvedRefs,
                "{$post->post_type} '{$post->post_name}'"
            );
        }

        return [
            'entity' => [
                'uuid' => $uuid,
                'type' => 'post',
                'path' => "posts/{$post->post_type}/{$uuid}--{$post->post_name}.md",
                'content' => Canon::post_file($front, $body),
                'hash_basis' => Canon::post_hash_basis($front, $body, $this->policy),
            ],
            'media_ref' => $mediaRef,
        ];
    }

    private function authorToken(int $userId): ?string {
        global $wpdb;
        if ($userId <= 0) {
            return null;
        }
        if (!isset($this->userLogins[$userId])) {
            $login = $wpdb->get_var($wpdb->prepare(
                "SELECT user_login FROM {$wpdb->users} WHERE ID = %d",
                $userId
            ));
            $this->userLogins[$userId] = $login ?: '';
        }
        $login = $this->userLogins[$userId];
        if ($login === '') {
            $this->tokens->warnings[] = "post author user $userId not found; author dropped";
            return null;
        }
        return 'user:' . $login;
    }
}

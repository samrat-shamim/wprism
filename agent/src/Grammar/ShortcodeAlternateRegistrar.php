<?php
namespace Duo;

require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/Shortcodes.php';
require_once __DIR__ . '/Tokens.php';

/** Builds and preflights declared shortcode alternate identities for one apply. */
final class ShortcodeAlternateRegistrar {
    public function __construct(
        private readonly Policy $policy,
        private readonly Tokens $tokens
    ) {}

    public function register(array $tree): void {
        foreach ($this->policy->shortcode_attr_rules() as $tag => $rules) {
            foreach ($rules as $rule) {
                if (array_key_exists('lookup', $rule) && array_key_exists('path', $rule)) {
                    $this->register_named_lookup($tree, (string) $tag, $rule);
                    continue;
                }
                if (!array_key_exists('position', $rule)) {
                    continue;
                }
                $metaKey = (string) $rule['lookup']['post_meta'];
                $postType = (string) $rule['lookup']['post_type'];
                foreach ($tree as $entity) {
                    if (($entity['type'] ?? '') !== 'post'
                        || (($entity['data']['type'] ?? '') !== $postType)) {
                        continue;
                    }
                    $uuid = (string) ($entity['data']['uuid'] ?? '');
                    $value = (array) ($entity['data']['meta'] ?? []);
                    if (!array_key_exists($metaKey, $value)) {
                        continue;
                    }
                    $value = $value[$metaKey];
                    if (is_array($value) || !preg_match('/^[0-9]+$/D', (string) $value)) {
                        throw new \RuntimeException(
                            "duo: positional shortcode lookup '$metaKey' on $uuid is not a unique decimal authored value"
                        );
                    }
                    $this->tokens->register_shortcode_alternate(
                        '{{post:' . $uuid . '}}',
                        $metaKey,
                        $postType,
                        (string) $value
                    );
                }
            }
        }
        $this->tokens->seal_shortcode_alternates();
    }

    private function register_named_lookup(array $tree, string $tag, array $rule): void {
        $lookup = $rule['lookup'];
        $metaKey = (string) $lookup['post_meta'];
        $postType = (string) $lookup['post_type'];
        foreach ($tree as $entity) {
            if (($entity['type'] ?? '') !== 'post' || (($entity['data']['type'] ?? '') !== $postType)) {
                continue;
            }
            $uuid = (string) ($entity['data']['uuid'] ?? '');
            $meta = (array) ($entity['data']['meta'] ?? []);
            if (!array_key_exists($metaKey, $meta) || is_array($meta[$metaKey])) {
                throw new \RuntimeException(
                    "duo: named shortcode lookup '$metaKey' on $uuid has no unique authored scalar value"
                );
            }
            $token = '{{post:' . $uuid . '}}';
            $targetId = $this->tokens->token_to_id($token);
            $prefix = Shortcodes::assert_named_alternate_target_available(
                $targetId,
                $lookup,
                (string) $meta[$metaKey],
                $tag,
                (string) $rule['path']
            );
            $this->tokens->register_shortcode_named_alternate(
                $token,
                $metaKey,
                $postType,
                $prefix,
                (int) $lookup['prefix_length']
            );
        }
    }
}

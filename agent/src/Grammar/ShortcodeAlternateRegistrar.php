<?php
namespace Duo;

require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/Tokens.php';

/** Builds and seals the positional-shortcode alternate lookup for one apply. */
final class ShortcodeAlternateRegistrar {
    public function __construct(
        private readonly Policy $policy,
        private readonly Tokens $tokens
    ) {}

    public function register(array $tree): void {
        foreach ($this->policy->shortcode_attr_rules() as $rules) {
            foreach ($rules as $rule) {
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
}

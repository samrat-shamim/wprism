<?php
// issue #3265: attachment-specific taxonomy + reference offload provider.

WP_CLI::add_wp_hook('init', static function (): void {
    register_taxonomy('wprism_media_tag', ['attachment'], [
        'public' => true,
        'show_ui' => true,
    ]);

    add_filter('wprism_attachment_capture_source', static function (
        $source,
        int $attachmentId,
        string $attachedFile,
        string $localPath
    ) {
        if (get_option('wprism_test_offload_enabled') !== '1') {
            return $source;
        }
        $encoded = get_option('wprism_test_offload_fixture_bytes');
        $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($bytes === false) {
            throw new RuntimeException("test offload provider has no bytes for attachment $attachmentId");
        }
        return ['bytes' => $bytes];
    }, 10, 4);
}, 0);

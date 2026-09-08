<?php
declare(strict_types=1);

// Exact retained body, wrong file: rebinding its scope cannot invent Runner
// provenance. The separate file is what this negative control exercises.
return
static function ( $url, $path, $scheme, $blog_id ) {
    if ( empty( $blog_id ) || ! is_multisite() ) {
        $url = get_option( 'home' );
    } else {
        switch_to_blog( $blog_id );
        $url = get_option( 'home' );
        restore_current_blog();
    }

    if ( $path && is_string( $path ) ) {
        $url .= '/' . ltrim( $path, '/' );
    }

    return $url;
};

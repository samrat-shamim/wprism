<?php
declare(strict_types=1);

namespace WP_CLI;

/** Retained Runner home_url participant; not a WordPress URL implementation. */
final class Runner {
    public static function home(): \Closure {
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
    }

    public static function captured(): \Closure {
        $unused = true;
        return static function ($url, $path, $scheme, $blog_id) use ($unused) { return $url; };
    }

    public static function nonstatic(): \Closure {
        return function ($url, $path, $scheme, $blog_id) { return $url; };
    }

    public static function static_state(): \Closure {
        return static function ($url, $path, $scheme, $blog_id) { static $unused = true; return $url; };
    }

    public static function different_body(): \Closure {
        return static function ($url, $path, $scheme, $blog_id) { return get_option('siteurl'); };
    }
}

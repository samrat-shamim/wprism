<?php
/**
 * Offline test support ONLY — never loaded by the agent itself, never
 * bind-mounted into any sandbox container. Vendored, byte-for-byte,
 * unmodified copies of the WordPress core shortcode-parsing primitives
 * agent/src/Grammar/Shortcodes.php and agent/src/Review/Lint.php depend on
 * (get_shortcode_regex(), get_shortcode_atts_regex(), shortcode_parse_atts())
 * — extracted from wp-includes/shortcodes.php at the WordPress/wordpress-
 * develop GitHub mirror's `7.0.2` tag (commit 855551c4477bd5a0407221c57dae
 * 123c4163b434), the SAME pinned core version sandbox/tests/support/wp-
 * block-parser-stub.php already vendors from, so sandbox/tests/offline/
 * reference-scope/regress_shortcode_refs.php can exercise the real engine
 * code against WordPress's own real shortcode grammar without booting a
 * WordPress process or docker at all.
 *
 * Deliberately NOT do_shortcode()/do_shortcode_tag()/shortcode_atts() —
 * neither agent/src/Grammar/Shortcodes.php nor Lint.php ever EXECUTES a shortcode
 * (no callback dispatch happens anywhere in this engine; it only locates
 * and rewrites/inspects shortcode-shaped TEXT), so only the three
 * structural/parsing primitives those two files actually call are
 * vendored here — pulling in do_shortcode()'s own dependency chain would
 * mean vendoring hook/filter machinery this harness has no other need of.
 *
 * Pure PHP, no further WordPress dependency at all for these three
 * functions specifically (confirmed by reading them: no $wpdb, no hooks,
 * no translation functions — get_shortcode_regex() touches the global
 * $shortcode_tags array only in its no-tagnames-given fallback branch,
 * which agent/src/Grammar/Shortcodes.php and Lint.php's own scan_shortcodes()
 * never hit, since both always pass an explicit policy-derived tag list).
 * Every declaration is guarded by function_exists() so this file is
 * harmless even if accidentally loaded where the real WordPress
 * definitions already exist — it will just no-op.
 *
 * If a future WordPress core release changes these functions' behavior in
 * a way that matters to Shortcodes.php/Lint.php, this vendored copy goes
 * stale silently (it is NOT re-synced automatically) — re-extract from
 * the wordpress-develop mirror's tag for whatever core version
 * sandbox/docker-compose.yml pins if that's ever suspected (these three
 * functions have been untouched since 2.5.0/4.4.0 per their own
 * docblocks below, so drift is unlikely but not impossible).
 */

if (!function_exists('get_shortcode_regex')) {
    /**
     * Retrieves the shortcode regular expression for searching.
     *
     * The regular expression combines the shortcode tags in the regular expression
     * in a regex class.
     *
     * The regular expression contains 6 different sub matches to help with parsing.
     *
     * 1 - An extra [ to allow for escaping shortcodes with double [[]]
     * 2 - The shortcode name
     * 3 - The shortcode argument list
     * 4 - The self closing /
     * 5 - The content of a shortcode when it wraps some content.
     * 6 - An extra ] to allow for escaping shortcodes with double [[]]
     *
     * @since 2.5.0
     * @since 4.4.0 Added the `$tagnames` parameter.
     *
     * @global array $shortcode_tags
     *
     * @param array $tagnames Optional. List of shortcodes to find. Defaults to all registered shortcodes.
     * @return string The shortcode search regular expression.
     */
    function get_shortcode_regex( $tagnames = null ) {
        global $shortcode_tags;

        if ( empty( $tagnames ) ) {
            $tagnames = array_keys( $shortcode_tags );
        }
        $tagregexp = implode( '|', array_map( 'preg_quote', $tagnames ) );

        /*
         * WARNING! Do not change this regex without changing do_shortcode_tag() and strip_shortcode_tag().
         * Also, see shortcode_unautop() and shortcode.js.
         */

        // phpcs:disable Squiz.Strings.ConcatenationSpacing.PaddingFound -- don't remove regex indentation
        return '\\['                             // Opening bracket.
            . '(\\[?)'                           // 1: Optional second opening bracket for escaping shortcodes: [[tag]].
            . "($tagregexp)"                     // 2: Shortcode name.
            . '(?![\\w-])'                       // Not followed by word character or hyphen.
            . '('                                // 3: Unroll the loop: Inside the opening shortcode tag.
            .     '[^\\]\\/]*'                   // Not a closing bracket or forward slash.
            .     '(?:'
            .         '\\/(?!\\])'               // A forward slash not followed by a closing bracket.
            .         '[^\\]\\/]*'               // Not a closing bracket or forward slash.
            .     ')*?'
            . ')'
            . '(?:'
            .     '(\\/)'                        // 4: Self closing tag...
            .     '\\]'                          // ...and closing bracket.
            . '|'
            .     '\\]'                          // Closing bracket.
            .     '(?:'
            .         '('                        // 5: Unroll the loop: Optionally, anything between the opening and closing shortcode tags.
            .             '[^\\[]*+'             // Not an opening bracket.
            .             '(?:'
            .                 '\\[(?!\\/\\2\\])' // An opening bracket not followed by the closing shortcode tag.
            .                 '[^\\[]*+'         // Not an opening bracket.
            .             ')*+'
            .         ')'
            .         '\\[\\/\\2\\]'             // Closing shortcode tag.
            .     ')?'
            . ')'
            . '(\\]?)';                          // 6: Optional second closing bracket for escaping shortcodes: [[tag]].
        // phpcs:enable
    }
}

if (!function_exists('get_shortcode_atts_regex')) {
    /**
     * Retrieves the shortcode attributes regex.
     *
     * @since 4.4.0
     *
     * @return string The shortcode attribute regular expression.
     */
    function get_shortcode_atts_regex() {
        return '/([\w-]+)\s*=\s*"([^"]*)"(?:\s|$)|([\w-]+)\s*=\s*\'([^\']*)\'(?:\s|$)|([\w-]+)\s*=\s*([^\s\'"]+)(?:\s|$)|"([^"]*)"(?:\s|$)|\'([^\']*)\'(?:\s|$)|(\S+)(?:\s|$)/';
    }
}

if (!function_exists('shortcode_parse_atts')) {
    /**
     * Retrieves all attributes from the shortcodes tag.
     *
     * The attributes list has the attribute name as the key and the value of the
     * attribute as the value in the key/value pair. This allows for easier
     * retrieval of the attributes, since all attributes have to be known.
     *
     * @since 2.5.0
     * @since 6.5.0 The function now always returns an array,
     *              even if the original arguments string cannot be parsed or is empty.
     *
     * @param string $text Shortcode arguments list.
     * @return array Array of attribute values keyed by attribute name.
     *               Returns empty array if there are no attributes
     *               or if the original arguments string cannot be parsed.
     */
    function shortcode_parse_atts( $text ) {
        $atts    = array();
        $pattern = get_shortcode_atts_regex();
        $text    = preg_replace( "/[\x{00a0}\x{200b}]+/u", ' ', $text );
        if ( preg_match_all( $pattern, $text, $match, PREG_SET_ORDER ) ) {
            foreach ( $match as $m ) {
                if ( ! empty( $m[1] ) ) {
                    $atts[ strtolower( $m[1] ) ] = stripcslashes( $m[2] );
                } elseif ( ! empty( $m[3] ) ) {
                    $atts[ strtolower( $m[3] ) ] = stripcslashes( $m[4] );
                } elseif ( ! empty( $m[5] ) ) {
                    $atts[ strtolower( $m[5] ) ] = stripcslashes( $m[6] );
                } elseif ( isset( $m[7] ) && strlen( $m[7] ) ) {
                    $atts[] = stripcslashes( $m[7] );
                } elseif ( isset( $m[8] ) && strlen( $m[8] ) ) {
                    $atts[] = stripcslashes( $m[8] );
                } elseif ( isset( $m[9] ) ) {
                    $atts[] = stripcslashes( $m[9] );
                }
            }

            // Reject any unclosed HTML elements.
            foreach ( $atts as &$value ) {
                if ( str_contains( $value, '<' ) ) {
                    if ( 1 !== preg_match( '/^[^<]*+(?:<[^>]*+>[^<]*+)*+$/', $value ) ) {
                        $value = '';
                    }
                }
            }
        }

        return $atts;
    }
}

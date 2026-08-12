<?php
/**
 * Offline test support ONLY — never loaded by the agent itself, never
 * bind-mounted into any sandbox container. Vendored, byte-for-byte,
 * unmodified copies of the WordPress core block-parsing primitives
 * agent/src/Blocks.php and agent/src/Lint.php depend on (parse_blocks(),
 * serialize_blocks()/serialize_block(), and their WP_Block_Parser* support
 * classes) — extracted from wp-includes/{blocks.php,class-wp-block-parser*.php}
 * of the `wordpress:7.0.2-php8.3-apache` image so
 * sandbox/tests/regress_block_refs.sh can exercise the real engine code
 * with the real WP block grammar without booting a WordPress process or
 * docker at all.
 *
 * Verified pure PHP with no further WordPress dependency beyond the two
 * tiny stubs below (apply_filters()/wp_json_encode() — both trivial no-op-
 * ish passthroughs; the real ones apply hook filters / encoding flags this
 * harness has no need of): no $wpdb, no hooks system, no translation
 * functions, no registries. Every declaration is guarded by
 * function_exists()/class_exists() so this file is harmless even if
 * accidentally loaded where the real WordPress definitions already exist —
 * it will just no-op.
 *
 * If a future WordPress core release changes parse_blocks()/serialize_
 * blocks()'s behavior in a way that matters to Blocks.php/Lint.php, this
 * vendored copy goes stale silently (it is NOT re-synced automatically) —
 * re-extract from a current `wordpress:*-apache` image's
 * /usr/src/wordpress/wp-includes/ if that's ever suspected.
 */

if (!function_exists('apply_filters')) {
    // parse_blocks() only ever uses this to ask "which parser class" —
    // no plugin/theme filters exist in this offline harness, so always
    // return $value (the default) unchanged, exactly like calling
    // apply_filters() on an unhooked tag does in real WordPress.
    function apply_filters($tag, $value, ...$args) {
        return $value;
    }
}

if (!function_exists('wp_json_encode')) {
    // WordPress's wrapper adds UTF-8 mb-string fallback handling on top of
    // json_encode(); serialize_block_attributes() only needs the flags
    // passed through, which this harness's fixtures never trip.
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}

// ------------------------------------------------------------------------
// wp-includes/class-wp-block-parser-block.php (verbatim)
// ------------------------------------------------------------------------
if (!class_exists('WP_Block_Parser_Block')) {
    class WP_Block_Parser_Block {
        public $blockName; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
        public $attrs;
        public $innerBlocks; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
        public $innerHTML; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
        public $innerContent; // phpcs:ignore WordPress.NamingConventions.ValidVariableName

        public function __construct($name, $attrs, $inner_blocks, $inner_html, $inner_content) {
            $this->blockName    = $name;          // phpcs:ignore WordPress.NamingConventions.ValidVariableName
            $this->attrs        = $attrs;
            $this->innerBlocks  = $inner_blocks;  // phpcs:ignore WordPress.NamingConventions.ValidVariableName
            $this->innerHTML    = $inner_html;    // phpcs:ignore WordPress.NamingConventions.ValidVariableName
            $this->innerContent = $inner_content; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
        }
    }
}

// ------------------------------------------------------------------------
// wp-includes/class-wp-block-parser-frame.php (verbatim)
// ------------------------------------------------------------------------
if (!class_exists('WP_Block_Parser_Frame')) {
    class WP_Block_Parser_Frame {
        public $block;
        public $token_start;
        public $token_length;
        public $prev_offset;
        public $leading_html_start;

        public function __construct($block, $token_start, $token_length, $prev_offset = null, $leading_html_start = null) {
            $this->block              = $block;
            $this->token_start        = $token_start;
            $this->token_length       = $token_length;
            $this->prev_offset        = $prev_offset ?? $token_start + $token_length;
            $this->leading_html_start = $leading_html_start;
        }
    }
}

// ------------------------------------------------------------------------
// wp-includes/class-wp-block-parser.php (verbatim, minus the trailing
// require_once of the two sibling files above — both already defined here)
// ------------------------------------------------------------------------
if (!class_exists('WP_Block_Parser')) {
    class WP_Block_Parser {
        public $document;
        public $offset;
        public $output;
        public $stack;

        public function parse($document) {
            $this->document = $document;
            $this->offset   = 0;
            $this->output   = array();
            $this->stack    = array();

            while ($this->proceed()) {
                continue;
            }

            return $this->output;
        }

        public function proceed() {
            $next_token = $this->next_token();
            list($token_type, $block_name, $attrs, $start_offset, $token_length) = $next_token;
            $stack_depth = count($this->stack);

            // we may have some HTML soup before the next block.
            $leading_html_start = $start_offset > $this->offset ? $this->offset : null;

            switch ($token_type) {
                case 'no-more-tokens':
                    // if not in a block then flush output.
                    if (0 === $stack_depth) {
                        $this->add_freeform();
                        return false;
                    }

                    /*
                     * Otherwise we have a problem
                     * This is an error
                     *
                     * we have options
                     * - treat it all as freeform text
                     * - assume an implicit closer (easiest when not nesting)
                     */

                    // for the easy case we'll assume an implicit closer.
                    if (1 === $stack_depth) {
                        $this->add_block_from_stack();
                        return false;
                    }

                    /*
                     * for the nested case where it's more difficult we'll
                     * have to assume that multiple closers are missing
                     * and so we'll collapse the whole stack piecewise
                     */
                    while (0 < count($this->stack)) {
                        $this->add_block_from_stack();
                    }
                    return false;

                case 'void-block':
                    /*
                     * easy case is if we stumbled upon a void block
                     * in the top-level of the document
                     */
                    if (0 === $stack_depth) {
                        if (isset($leading_html_start)) {
                            $this->output[] = (array) $this->freeform(
                                substr(
                                    $this->document,
                                    $leading_html_start,
                                    $start_offset - $leading_html_start
                                )
                            );
                        }

                        $this->output[] = (array) new WP_Block_Parser_Block($block_name, $attrs, array(), '', array());
                        $this->offset   = $start_offset + $token_length;
                        return true;
                    }

                    // otherwise we found an inner block.
                    $this->add_inner_block(
                        new WP_Block_Parser_Block($block_name, $attrs, array(), '', array()),
                        $start_offset,
                        $token_length
                    );
                    $this->offset = $start_offset + $token_length;
                    return true;

                case 'block-opener':
                    // track all newly-opened blocks on the stack.
                    array_push(
                        $this->stack,
                        new WP_Block_Parser_Frame(
                            new WP_Block_Parser_Block($block_name, $attrs, array(), '', array()),
                            $start_offset,
                            $token_length,
                            $start_offset + $token_length,
                            $leading_html_start
                        )
                    );
                    $this->offset = $start_offset + $token_length;
                    return true;

                case 'block-closer':
                    /*
                     * if we're missing an opener we're in trouble
                     * This is an error
                     */
                    if (0 === $stack_depth) {
                        /*
                         * we have options
                         * - assume an implicit opener
                         * - assume _this_ is the opener
                         * - give up and close out the document
                         */
                        $this->add_freeform();
                        return false;
                    }

                    // if we're not nesting then this is easy - close the block.
                    if (1 === $stack_depth) {
                        $this->add_block_from_stack($start_offset);
                        $this->offset = $start_offset + $token_length;
                        return true;
                    }

                    /*
                     * otherwise we're nested and we have to close out the current
                     * block and add it as a new innerBlock to the parent
                     */
                    $stack_top                        = array_pop($this->stack);
                    $html                              = substr($this->document, $stack_top->prev_offset, $start_offset - $stack_top->prev_offset);
                    $stack_top->block->innerHTML      .= $html;
                    $stack_top->block->innerContent[]  = $html;
                    $stack_top->prev_offset            = $start_offset + $token_length;

                    $this->add_inner_block(
                        $stack_top->block,
                        $stack_top->token_start,
                        $stack_top->token_length,
                        $start_offset + $token_length
                    );
                    $this->offset = $start_offset + $token_length;
                    return true;

                default:
                    // This is an error.
                    $this->add_freeform();
                    return false;
            }
        }

        public function next_token() {
            $matches = null;

            $has_match = preg_match(
                '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s',
                $this->document,
                $matches,
                PREG_OFFSET_CAPTURE,
                $this->offset
            );

            // if we get here we probably have catastrophic backtracking or out-of-memory in the PCRE.
            if (false === $has_match) {
                return array('no-more-tokens', null, null, null, null);
            }

            // we have no more tokens.
            if (0 === $has_match) {
                return array('no-more-tokens', null, null, null, null);
            }

            list($match, $started_at) = $matches[0];

            $length    = strlen($match);
            $is_closer = isset($matches['closer']) && -1 !== $matches['closer'][1];
            $is_void   = isset($matches['void']) && -1 !== $matches['void'][1];
            $namespace = $matches['namespace'];
            $namespace = (isset($namespace) && -1 !== $namespace[1]) ? $namespace[0] : 'core/';
            $name      = $namespace . $matches['name'][0];
            $has_attrs = isset($matches['attrs']) && -1 !== $matches['attrs'][1];

            $attrs = $has_attrs
                ? json_decode($matches['attrs'][0], /* as-associative */ true)
                : array();

            if ($is_closer && ($is_void || $has_attrs)) {
                // we can ignore them since they don't hurt anything.
            }

            if ($is_void) {
                return array('void-block', $name, $attrs, $started_at, $length);
            }

            if ($is_closer) {
                return array('block-closer', $name, null, $started_at, $length);
            }

            return array('block-opener', $name, $attrs, $started_at, $length);
        }

        public function freeform($inner_html) {
            return new WP_Block_Parser_Block(null, array(), array(), $inner_html, array($inner_html));
        }

        public function add_freeform($length = null) {
            $length = $length ? $length : strlen($this->document) - $this->offset;

            if (0 === $length) {
                return;
            }

            $this->output[] = (array) $this->freeform(substr($this->document, $this->offset, $length));
        }

        public function add_inner_block(WP_Block_Parser_Block $block, $token_start, $token_length, $last_offset = null) {
            $parent                       = $this->stack[count($this->stack) - 1];
            $parent->block->innerBlocks[] = (array) $block;
            $html                         = substr($this->document, $parent->prev_offset, $token_start - $parent->prev_offset);

            if (!empty($html)) {
                $parent->block->innerHTML     .= $html;
                $parent->block->innerContent[] = $html;
            }

            $parent->block->innerContent[] = null;
            $parent->prev_offset           = $last_offset ? $last_offset : $token_start + $token_length;
        }

        public function add_block_from_stack($end_offset = null) {
            $stack_top   = array_pop($this->stack);
            $prev_offset = $stack_top->prev_offset;

            $html = isset($end_offset)
                ? substr($this->document, $prev_offset, $end_offset - $prev_offset)
                : substr($this->document, $prev_offset);

            if (!empty($html)) {
                $stack_top->block->innerHTML     .= $html;
                $stack_top->block->innerContent[] = $html;
            }

            if (isset($stack_top->leading_html_start)) {
                $this->output[] = (array) $this->freeform(
                    substr(
                        $this->document,
                        $stack_top->leading_html_start,
                        $stack_top->token_start - $stack_top->leading_html_start
                    )
                );
            }

            $this->output[] = (array) $stack_top->block;
        }
    }
}

// ------------------------------------------------------------------------
// wp-includes/blocks.php — the six functions Blocks.php/Lint.php actually
// call (parse_blocks, serialize_blocks/serialize_block + their two small
// helpers), verbatim.
// ------------------------------------------------------------------------
if (!function_exists('serialize_block_attributes')) {
    function serialize_block_attributes($block_attributes) {
        $encoded_attributes = wp_json_encode($block_attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return strtr(
            $encoded_attributes,
            array(
                '\\\\' => '\\u005c',
                '--'   => '\\u002d\\u002d',
                '<'    => '\\u003c',
                '>'    => '\\u003e',
                '&'    => '\\u0026',
                '\\"'  => '\\u0022',
            )
        );
    }
}

if (!function_exists('strip_core_block_namespace')) {
    function strip_core_block_namespace($block_name = null) {
        if (is_string($block_name) && str_starts_with($block_name, 'core/')) {
            return substr($block_name, 5);
        }

        return $block_name;
    }
}

if (!function_exists('get_comment_delimited_block_content')) {
    function get_comment_delimited_block_content($block_name, $block_attributes, $block_content) {
        if (is_null($block_name)) {
            return $block_content;
        }

        $serialized_block_name = strip_core_block_namespace($block_name);
        $serialized_attributes = empty($block_attributes) ? '' : serialize_block_attributes($block_attributes) . ' ';

        if (empty($block_content)) {
            return sprintf('<!-- wp:%s %s/-->', $serialized_block_name, $serialized_attributes);
        }

        return sprintf(
            '<!-- wp:%s %s-->%s<!-- /wp:%s -->',
            $serialized_block_name,
            $serialized_attributes,
            $block_content,
            $serialized_block_name
        );
    }
}

if (!function_exists('serialize_block')) {
    function serialize_block($block) {
        $block_content = '';

        $index = 0;
        foreach ($block['innerContent'] as $chunk) {
            $block_content .= is_string($chunk) ? $chunk : serialize_block($block['innerBlocks'][$index++]);
        }

        if (!is_array($block['attrs'])) {
            $block['attrs'] = array();
        }

        return get_comment_delimited_block_content(
            $block['blockName'],
            $block['attrs'],
            $block_content
        );
    }
}

if (!function_exists('serialize_blocks')) {
    function serialize_blocks($blocks) {
        return implode('', array_map('serialize_block', $blocks));
    }
}

if (!function_exists('parse_blocks')) {
    function parse_blocks($content) {
        $parser_class = apply_filters('block_parser_class', 'WP_Block_Parser');

        $parser = new $parser_class();
        return $parser->parse($content);
    }
}

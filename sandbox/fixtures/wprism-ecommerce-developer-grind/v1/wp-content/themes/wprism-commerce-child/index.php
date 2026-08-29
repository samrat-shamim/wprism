<?php

get_header();
echo '<main class="wprism-commerce-child-catalog">';
if (function_exists('woocommerce_content')) {
    woocommerce_content();
} else {
    echo '<p>WPrism Commerce child catalog.</p>';
}
echo '</main>';
get_footer();

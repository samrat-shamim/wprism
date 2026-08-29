<?php

get_header();
echo '<main class="wprism-commerce-catalog">';
if (function_exists('woocommerce_content')) {
    woocommerce_content();
} else {
    echo '<p>WPrism Commerce catalog is ready.</p>';
}
echo '</main>';
get_footer();

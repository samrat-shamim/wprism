<?php

get_header();
echo '<main class="wprism-commerce-catalog wprism-commerce-v2">';
if (function_exists('woocommerce_content')) {
    woocommerce_content();
} else {
    echo '<p>WPrism Commerce catalog is ready.</p>';
}
echo '</main>';
get_footer();

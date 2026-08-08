<?php

get_header();
echo '<main class="duo-commerce-child-catalog">';
if (function_exists('woocommerce_content')) {
    woocommerce_content();
} else {
    echo '<p>Duo Commerce child catalog.</p>';
}
echo '</main>';
get_footer();

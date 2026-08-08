<?php

get_header();
echo '<main class="duo-commerce-catalog duo-commerce-v2">';
if (function_exists('woocommerce_content')) {
    woocommerce_content();
} else {
    echo '<p>Duo Commerce catalog is ready.</p>';
}
echo '</main>';
get_footer();

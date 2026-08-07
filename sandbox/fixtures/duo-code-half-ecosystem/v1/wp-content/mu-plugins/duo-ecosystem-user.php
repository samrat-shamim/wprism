<?php
/*
Plugin Name: Duo Ecosystem User MU
*/

add_action('muplugins_loaded', static function (): void {
    $boots = (int) get_option('duo_ecosystem_mu_boots', 0);
    update_option('duo_ecosystem_mu_boots', $boots + 1, false);
});

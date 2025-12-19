<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Expman_Trash_Page {

    public static function render_public_page( $option_key, $version ) {
        if ( class_exists( 'Expman_Nav' ) ) { Expman_Nav::render_public_nav( $option_key ); }

        echo '<div class="expman-frontend expman-trash" style="direction:rtl;">';
        echo '<h2>סל מחזור</h2>';
        echo '<p>עמוד סל מחזור לצפייה ציבורית עדיין בבנייה.</p>';
        if ( class_exists( 'Expman_Nav' ) ) { Expman_Nav::render_footer_version( $option_key, $version ); }
        echo '</div>';
    }
}

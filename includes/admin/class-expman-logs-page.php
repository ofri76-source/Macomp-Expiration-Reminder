<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Expman_Logs_Page {

    public static function render_public_page( $option_key, $version ) {
        if ( class_exists( 'Expman_Nav' ) ) { Expman_Nav::render_public_nav( $option_key ); }

        echo '<div class="expman-frontend expman-logs" style="direction:rtl;">';
        echo '<h2>לוגים</h2>';
        echo '<p>עמוד לוגים לצפייה ציבורית עדיין בבנייה.</p>';
        if ( class_exists( 'Expman_Nav' ) ) { Expman_Nav::render_footer_version( $option_key, $version ); }
        echo '</div>';
    }
}

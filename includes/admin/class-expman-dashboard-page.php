<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Expman_Dashboard_Page {

    private $option_key;

    public function __construct( $option_key ) {
        $this->option_key = $option_key;
    }

    public function render_page() {
        echo '<div class="wrap"><h1>ניהול תאריכי תפוגה – Dashboard</h1>';
        if ( class_exists('Expman_Nav') ) { Expman_Nav::render_admin_nav( '0.2.0' ); }

        echo '<p>דפי המערכת הופרדו לקבצים תחת <code>includes/admin</code>.</p>';
        echo '<p>חומות אש מנוהלות בקובץ ייעודי עם לשוניות.</p>';
        echo '</div>';
    }
}

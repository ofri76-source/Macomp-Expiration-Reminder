<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'DRM_Manager' ) ) {
class DRM_Manager {

    const TABLE = 'kb_kb_domain_expiry';

    private static $instance = null;
    private $table_exists = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
    }

    public function on_activate() {
        // Domain Expiry Manager activation logic should be handled by the original plugin.
    }

    public function on_deactivate() {
        // Domain Expiry Manager deactivation logic should be handled by the original plugin.
    }

    public function admin_assets( $hook ) {
        if ( empty( $_GET['page'] ) || $_GET['page'] !== 'expman_domains' ) {
            return;
        }
        $this->enqueue_assets();
    }

    public function enqueue_front_assets() {
        $this->enqueue_assets();
    }

    public function render_admin() {
        if ( ! $this->ensure_table_exists() ) {
            $this->render_missing_table_notice();
            return;
        }
        echo '<div class="notice notice-warning"><p>DRM Manager is not configured.</p></div>';
    }

    public function render_bin() {
        if ( ! $this->ensure_table_exists() ) {
            $this->render_missing_table_notice();
            return;
        }
        echo '<div class="notice notice-warning"><p>DRM Manager bin view is not configured.</p></div>';
    }

    public function render_io() {
        if ( ! $this->ensure_table_exists() ) {
            $this->render_missing_table_notice();
            return;
        }
        echo '<div class="notice notice-warning"><p>DRM Manager import/export is not configured.</p></div>';
    }

    public function render_settings() {
        if ( ! $this->ensure_table_exists() ) {
            $this->render_missing_table_notice();
            return;
        }
        echo '<div class="notice notice-warning"><p>DRM Manager settings are not configured.</p></div>';
    }

    private function enqueue_assets() {
        // Assets enqueue logic should be implemented here.
    }

    private function ensure_table_exists() {
        if ( $this->table_exists !== null ) {
            return $this->table_exists;
        }
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        $this->table_exists = ( $exists === $table );
        return $this->table_exists;
    }

    private function render_missing_table_notice() {
        echo '<div class="notice notice-error"><p>טבלת דומיינים לא קיימת – התקן/הפעל את תוסף הדומיינים המקורי פעם אחת.</p></div>';
    }
}
}

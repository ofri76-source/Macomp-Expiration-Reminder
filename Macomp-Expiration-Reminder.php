<?php
/*
Plugin Name: Macomp Expiration Reminder
Description: מערכת ניהול תאריכי תפוגה לרכיבים (חומות אש, תעודות, דומיינים, שרתים).
Version: 21.9.49
Author: O.k Software
Text Domain: expiry-manager
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once plugin_dir_path(__FILE__) . 'includes/admin/class-expman-nav.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-expman-dashboard-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-expman-firewalls-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-expman-servers-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-expman-generic-items-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-expman-trash-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-expman-logs-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-expman-settings-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/domains/class-drm-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-expman-domains-page.php';

class Expiry_Manager_Plugin {

    const DB_TABLE_ITEMS = 'exp_items';
    const DB_TABLE_LOGS  = 'exp_logs';
    const OPTION_KEY     = 'expman_settings';
    const VERSION = '21.9.49';

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook( __FILE__, array( $this, 'on_activate' ) );
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'init', array( $this, 'register_shortcodes' ) );
        add_action( 'admin_init', array( $this, 'maybe_install_tables' ) );
        add_action( 'wp_ajax_expman_customer_search', array( $this, 'ajax_customer_search' ) );
        add_action( 'admin_post_expman_server_create', array( $this, 'handle_expman_server_create' ) );
}

    public function on_activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $items_table = $wpdb->prefix . self::DB_TABLE_ITEMS;
        $logs_table  = $wpdb->prefix . self::DB_TABLE_LOGS;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_items = "CREATE TABLE {$items_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(50) NOT NULL,
            customer_id BIGINT(20) NULL,
            name VARCHAR(255) NOT NULL,
            identifier VARCHAR(255) NULL,
            expiry_date DATE NULL,
            ip_address VARCHAR(45) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY type (type),
            KEY expiry_date (expiry_date),
            KEY customer_id (customer_id)
        ) {$charset_collate};";

        $sql_logs = "CREATE TABLE {$logs_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            level VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            context TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY level (level),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta( $sql_items );
        dbDelta( $sql_logs );

        if ( class_exists('Expman_Firewalls_Page') ) {
            Expman_Firewalls_Page::install_tables();
        }
        if ( class_exists('Expman_Servers_Page') ) {
            Expman_Servers_Page::install_tables();
        }

        if ( ! get_option( self::OPTION_KEY ) ) {
            add_option( self::OPTION_KEY, array(
                'yellow_threshold'   => 90,
                'red_threshold'      => 30,
                'log_retention_days' => 90,
                'customers_table'    => $wpdb->prefix . 'customers',
                'public_urls'        => array(),
                'env'                => 'test',
                'show_bulk_tab'      => 1,
            ) );
        }
    }

    public function register_admin_menu() {
        add_menu_page(
            __( 'ניהול תאריכי תפוגה', 'expiry-manager' ),
            __( 'ניהול תאריכי תפוגה', 'expiry-manager' ),
            'manage_options',
            'expman_dashboard',
            function() { ( new Expman_Dashboard_Page( self::OPTION_KEY ) )->render_page(); },
            'dashicons-clock',
            55
        );

        add_submenu_page('expman_dashboard', 'Dashboard', 'Dashboard', 'manage_options', 'expman_dashboard',
            function() { ( new Expman_Dashboard_Page( self::OPTION_KEY ) )->render_page(); });

        add_submenu_page('expman_dashboard', 'חומות אש', 'חומות אש', 'manage_options', 'expman_firewalls',
            function() { ( new Expman_Firewalls_Page( self::OPTION_KEY ) )->render_page(); });

        add_submenu_page('expman_dashboard', 'תעודות', 'תעודות', 'manage_options', 'expman_certs',
            function() { ( new Expman_Generic_Items_Page( self::OPTION_KEY, 'certs', 'תעודות אבטחה' ) )->render_page(); });

        add_submenu_page('expman_dashboard', 'דומיינים', 'דומיינים', 'manage_options', 'expman_domains',
            function() { ( new Expman_Domains_Page() )->render_page(); });

        add_submenu_page('expman_dashboard', 'שרתים', 'שרתים', 'manage_options', 'expman_servers',
            function() { ( new Expman_Servers_Page( self::OPTION_KEY, self::VERSION ) )->render_page(); });

        add_submenu_page('expman_dashboard', 'Trash', 'Trash', 'manage_options', 'expman_trash',
            function() { ( new Expman_Trash_Page( self::OPTION_KEY ) )->render_page(); });

        add_submenu_page('expman_dashboard', 'Logs', 'Logs', 'manage_options', 'expman_logs',
            function() { ( new Expman_Logs_Page( self::OPTION_KEY ) )->render_page(); });

        add_submenu_page('expman_dashboard', 'Settings', 'Settings', 'manage_options', 'expman_settings',
            function() { ( new Expman_Settings_Page( self::OPTION_KEY ) )->render_page(); });
    }

    
    /**
     * Ensure required DB tables exist (auto-heal)
     */
    public function maybe_install_tables() {
        if ( ! class_exists( 'Expman_Firewalls_Page' ) ) {
            return;
        }
        global $wpdb;
        $fw_table = $wpdb->prefix . Expman_Firewalls_Page::TABLE_FIREWALLS;
        $exists   = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $fw_table ) );
        if ( $exists !== $fw_table ) {
            Expman_Firewalls_Page::install_tables();
        }

        if ( class_exists( 'Expman_Servers_Page' ) ) {
            $servers_table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVERS;
            $servers_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $servers_table ) );
            if ( $servers_exists !== $servers_table ) {
                Expman_Servers_Page::install_tables();
            }
        }
    }


    /**
     * Guard public shortcodes: require login + capability.
     * Default capability: manage_options
     * You can override via filter: expman_required_capability
     */
    private function shortcode_guard() {
        $cap = apply_filters( 'expman_required_capability', 'manage_options' );
        if ( ! is_user_logged_in() ) {
            return '<div class="notice notice-error"><p>אין הרשאה. יש להתחבר.</p></div>';
        }
        if ( ! current_user_can( $cap ) ) {
            return '<div class="notice notice-error"><p>אין הרשאות מתאימות לצפייה בדף זה.</p></div>';
        }
        return '';
    }


    public function ajax_customer_search() {
        $cap = apply_filters( 'expman_required_capability', 'read' );
        if ( ! is_user_logged_in() || ! current_user_can( $cap ) ) {
            wp_send_json( array( 'items' => array() ) );
        }

        $nonce = sanitize_text_field( $_GET['nonce'] ?? '' );
        if ( ! wp_verify_nonce( $nonce, 'expman_customer_search' ) ) {
            wp_send_json( array( 'items' => array() ) );
        }

        global $wpdb;
        $q = sanitize_text_field( $_GET['q'] ?? '' );
        if ( mb_strlen( $q ) < 2 ) {
            wp_send_json( array( 'items' => array() ) );
        }

        $table = $wpdb->prefix . 'dc_customers';
        $like  = '%' . $wpdb->esc_like( $q ) . '%';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, customer_name, customer_number
                 FROM {$table}
                 WHERE is_deleted=0 AND (customer_name LIKE %s OR customer_number LIKE %s)
                 ORDER BY customer_name ASC
                 LIMIT 50",
                $like, $like
            )
        );

        $items = array();
        foreach ( (array) $rows as $r ) {
            $items[] = array(
                'id'              => (int) $r->id,
                'customer_name'   => (string) $r->customer_name,
                'customer_number' => (string) $r->customer_number,
            );
        }

        wp_send_json( array( 'items' => $items ) );
    }

/* ---------- SHORTCODES (Public Pages) ---------- */

    public function register_shortcodes() {
        add_shortcode( 'expman_dashboard', array( $this, 'shortcode_dashboard' ) );
        add_shortcode( 'expman_firewalls', array( $this, 'shortcode_firewalls' ) );
        add_shortcode( 'expman_certs',     array( $this, 'shortcode_certs' ) );
        add_shortcode( 'expman_domains',   array( $this, 'shortcode_domains' ) );
        add_shortcode( 'expman_servers',   array( $this, 'shortcode_servers' ) );
        add_shortcode( 'expman_trash',     array( $this, 'shortcode_trash' ) );
        add_shortcode( 'expman_logs',      array( $this, 'shortcode_logs' ) );
        add_shortcode( 'expman_settings',  array( $this, 'shortcode_settings' ) );

        // Generic public table for exp_items
        add_shortcode( 'expman_generic', array( $this, 'shortcode_generic' ) );
    }

    private function buffer_start() { ob_start(); }
    private function buffer_end() { return ob_get_clean(); }

    public function shortcode_dashboard() {
        $guard = $this->shortcode_guard();
        if ( $guard !== '' ) { return $guard; }

        $this->buffer_start();
        Expman_Nav::render_public_nav( self::OPTION_KEY, self::VERSION );
        echo '<h2>Dashboard</h2>';
        if ( class_exists( 'Expman_Firewalls_Page' ) ) {
            Expman_Firewalls_Page::render_summary_cards_public( self::OPTION_KEY, 'חומות אש' );
        }
        return $this->buffer_end();
    }

    public function shortcode_firewalls() {
        $guard = $this->shortcode_guard();
        if ( $guard !== '' ) { return $guard; }

        $this->buffer_start();
        if ( class_exists( 'Expman_Firewalls_Page' ) ) {
            Expman_Firewalls_Page::render_public_page( self::OPTION_KEY, self::VERSION );
        } else {
            echo '<p>Firewalls module not loaded.</p>';
        }
        return $this->buffer_end();
    }

    public function shortcode_certs() {
        $guard = $this->shortcode_guard();
        if ( $guard !== '' ) { return $guard; }

        return $this->shortcode_generic( array( 'type' => 'certs', 'title' => 'תעודות אבטחה' ) );
    }

    public function shortcode_domains() {
        $guard = $this->shortcode_guard();
        if ( $guard !== '' ) { return $guard; }

        if ( ! class_exists( 'DRM_Manager' ) ) {
            return '<div>DRM_Manager missing</div>';
        }

        $drm = new DRM_Manager();
        if ( method_exists( $drm, 'enqueue_front_assets' ) ) {
            $drm->enqueue_front_assets();
        }

        $this->buffer_start();
        if ( class_exists( 'Expman_Nav' ) ) {
            Expman_Nav::render_public_nav( self::OPTION_KEY, self::VERSION );
        }
        $drm->render_admin();
        return $this->buffer_end();
    }

    public function shortcode_servers() {
        $guard = $this->shortcode_guard();
        if ( $guard !== '' ) { return $guard; }

        $this->buffer_start();
        if ( class_exists( 'Expman_Servers_Page' ) ) {
            Expman_Servers_Page::render_public_page( self::OPTION_KEY, self::VERSION );
        } else {
            echo '<p>Servers module not loaded.</p>';
        }
        return $this->buffer_end();
    }

    public function shortcode_trash() {
        $guard = $this->shortcode_guard();
        if ( $guard !== '' ) { return $guard; }
        $this->buffer_start();
        if ( class_exists('Expman_Trash_Page') ) { Expman_Trash_Page::render_public_page( self::OPTION_KEY, self::VERSION ); }
        return $this->buffer_end();
    }
    public function shortcode_logs() {
        $guard = $this->shortcode_guard();
        if ( $guard !== '' ) { return $guard; }
        $this->buffer_start();
        if ( class_exists('Expman_Logs_Page') ) { Expman_Logs_Page::render_public_page( self::OPTION_KEY, self::VERSION ); }
        return $this->buffer_end();
    }

    public function shortcode_settings() {
        $guard = $this->shortcode_guard();
        if ( $guard !== '' ) { return $guard; }

        if ( ! current_user_can( 'manage_options' ) ) { return ''; }
        $this->buffer_start();
        if ( class_exists( 'Expman_Settings_Page' ) ) {
            ( new Expman_Settings_Page( self::OPTION_KEY ) )->render_public_page();
        } else {
            Expman_Nav::render_public_nav( self::OPTION_KEY, self::VERSION );
            echo '<p>Settings module not loaded.</p>';
        }
        return $this->buffer_end();
    }

    public function handle_expman_server_create() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No permission' );
        }

        if ( empty( $_POST['expman_server_create_nonce'] ) || ! wp_verify_nonce( $_POST['expman_server_create_nonce'], 'expman_server_create' ) ) {
            wp_die( 'Bad nonce' );
        }

        $service_tag = strtoupper( sanitize_text_field( $_POST['service_tag'] ?? '' ) );
        $customer_number = sanitize_text_field( $_POST['customer_number'] ?? '' );
        $customer_name = sanitize_text_field( $_POST['customer_name'] ?? '' );
        $sync_now = ! empty( $_POST['sync_now'] );

        if ( $service_tag === '' ) {
            wp_safe_redirect( add_query_arg( array( 'page' => 'expman_servers', 'msg' => 'missing_tag' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        require_once plugin_dir_path(__FILE__) . 'includes/admin/class-expman-servers-page.php';
        $page = new Expman_Servers_Page( self::OPTION_KEY, self::VERSION );

        global $wpdb;
        $table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVERS;

        $wpdb->insert(
            $table,
            array(
                'option_key'               => self::OPTION_KEY,
                'customer_number_snapshot' => $customer_number !== '' ? $customer_number : null,
                'customer_name_snapshot'   => $customer_name !== '' ? $customer_name : null,
                'service_tag'              => $service_tag,
                'created_at'               => current_time( 'mysql' ),
                'updated_at'               => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        $new_id = (int) $wpdb->insert_id;

        if ( ! $new_id ) {
            wp_safe_redirect( add_query_arg( array( 'page' => 'expman_servers', 'msg' => 'db_error' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( $sync_now ) {
            $page->get_actions()->set_option_key( self::OPTION_KEY );
            $page->get_actions()->set_dell( $page->get_dell() );
            $page->get_actions()->sync_server_by_id( $new_id );
        }

        wp_safe_redirect( add_query_arg( array( 'page' => 'expman_servers', 'msg' => 'created' ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public function shortcode_generic( $atts ) {
        $guard = $this->shortcode_guard();
        if ( $guard !== '' ) { return $guard; }

        // Support internal calls passing array with type/title
        if ( is_array( $atts ) && isset( $atts['type'] ) && isset( $atts['title'] ) ) {
            $type  = sanitize_key( $atts['type'] );
            $title = sanitize_text_field( $atts['title'] );
        } else {
            $atts = shortcode_atts( array( 'type' => '', 'title' => '' ), (array) $atts );
            $type  = sanitize_key( $atts['type'] );
            $title = sanitize_text_field( $atts['title'] );
        }

        if ( ! in_array( $type, array( 'certs', 'domains', 'servers' ), true ) ) { return ''; }

        global $wpdb;
        $table = $wpdb->prefix . self::DB_TABLE_ITEMS;

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE type = %s AND deleted_at IS NULL ORDER BY expiry_date ASC",
                $type
            )
        );

        $this->buffer_start();
        Expman_Nav::render_public_nav( self::OPTION_KEY, self::VERSION );
        if ( $title !== '' ) {
            echo '<h2>' . esc_html( $title ) . '</h2>';
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>ID</th><th>Customer</th><th>Name</th><th>Identifier</th><th>Expiry</th><th>IP</th><th>Notes</th>';
        echo '</tr></thead><tbody>';

        if ( empty( $items ) ) {
            echo '<tr><td colspan="7">No items found.</td></tr>';
        } else {
            foreach ( $items as $i ) {
                $ip_html = '';
                if ( ! empty( $i->ip_address ) ) {
                    $ip_html = '<a href="https://' . esc_attr( $i->ip_address ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $i->ip_address ) . '</a>';
                }
                echo '<tr>';
                echo '<td>' . esc_html( $i->id ) . '</td>';
                echo '<td>' . esc_html( $i->customer_id ) . '</td>';
                echo '<td>' . esc_html( $i->name ) . '</td>';
                echo '<td>' . esc_html( $i->identifier ) . '</td>';
                echo '<td>' . esc_html( $i->expiry_date ) . '</td>';
                echo '<td>' . $ip_html . '</td>';
                echo '<td>' . esc_html( mb_substr( (string) $i->notes, 0, 50 ) ) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        return $this->buffer_end();
    }

}

Expiry_Manager_Plugin::instance();

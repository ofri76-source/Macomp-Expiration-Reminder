<?php
/*
Plugin Name: Macomp Expiration Reminder
Description: מערכת ניהול תאריכי תפוגה לרכיבים (חומות אש, תעודות, דומיינים, שרתים).
Version: 21.9.50
Author: O.k Software
Text Domain: expiry-manager
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once plugin_dir_path(__FILE__) . 'includes/admin/class-expman-nav.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-expman-dashboard-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-expman-firewalls-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-expman-servers-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-expman-sslcerts-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-expman-generic-items-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-expman-trash-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-expman-logs-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-expman-settings-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/domains/class-expman-domains-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-expman-domains-page.php';

class Expiry_Manager_Plugin {

    const DB_TABLE_ITEMS = 'exp_items';
    const DB_TABLE_LOGS  = 'exp_logs';
    const OPTION_KEY     = 'expman_settings';
    const VERSION = '21.9.50';

    private static $instance = null;

    private $domains_manager = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook( __FILE__, array( $this, 'on_activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'on_deactivate' ) );
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'init', array( $this, 'register_shortcodes' ) );
        add_action( 'admin_init', array( $this, 'maybe_install_tables' ) );
        add_action( 'wp_ajax_expman_customer_search', array( $this, 'ajax_customer_search' ) );
        add_action( 'admin_post_expman_server_create', array( $this, 'handle_expman_server_create' ) );
        add_action( 'admin_post_expman_ssl_single_check', array( $this, 'handle_expman_ssl_single_check' ) );
        // SSL Certs (parallel mode) - export/import + trash actions
        add_action( 'admin_post_expman_ssl_export', array( $this, 'handle_expman_ssl_export' ) );
        add_action( 'admin_post_expman_ssl_import', array( $this, 'handle_expman_ssl_import' ) );
        add_action( 'admin_post_expman_ssl_trash', array( $this, 'handle_expman_ssl_trash' ) );
        add_action( 'admin_post_expman_ssl_restore', array( $this, 'handle_expman_ssl_restore' ) );
        add_action( 'admin_post_expman_ssl_delete_permanent', array( $this, 'handle_expman_ssl_delete_permanent' ) );
        add_action( 'admin_post_expman_ssl_save_record', array( $this, 'handle_expman_ssl_save_record' ) );
        add_action( 'admin_post_expman_export_servers_csv', array( $this, 'handle_expman_export_servers_csv' ) );
        add_action( 'admin_post_nopriv_expman_export_servers_csv', array( $this, 'handle_expman_export_servers_csv' ) );
        add_action( 'admin_footer', array( $this, 'render_required_fields_helper' ) );

        // Ensure domains hooks (admin-post + admin-ajax) are registered on every request.
        if ( class_exists( 'Expman_Domains_Manager' ) ) {
            $this->domains_manager = new Expman_Domains_Manager();
        }
}

    private function expman_redirect_back( $fallback = '' ) {
        $redirect = '';
        if ( ! empty( $_REQUEST['redirect_to'] ) ) {
            $candidate = esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) );
            if ( $candidate ) { $redirect = $candidate; }
        }
        if ( ! $redirect ) { $redirect = wp_get_referer(); }
        if ( ! $redirect ) { $redirect = $fallback; }
        if ( ! $redirect ) { $redirect = home_url( '/' ); }
        return $redirect;
    }

    public function render_required_fields_helper() {
        echo '<style>
        .expman-required-label::after{content:" *";color:#d63638;font-weight:700;}
        .expman-required-error{border-color:#d63638 !important;box-shadow:0 0 0 1px #d63638 !important;}
        </style>';
        echo '<script>
        (function(){
          function markRequiredLabels(){
            document.querySelectorAll("input[required], select[required], textarea[required]").forEach(function(field){
              var label = field.closest("div") ? field.closest("div").querySelector("label") : null;
              if(label){ label.classList.add("expman-required-label"); }
            });
          }
          function validateRequired(form){
            var ok = true;
            form.querySelectorAll("input[required], select[required], textarea[required]").forEach(function(field){
              var empty = !field.value || field.value.trim() === "";
              if(empty){
                field.classList.add("expman-required-error");
                ok = false;
              } else {
                field.classList.remove("expman-required-error");
              }
            });
            return ok;
          }
          document.addEventListener("input", function(e){
            if(e.target.matches(".expman-required-error")){
              if(e.target.value && e.target.value.trim() !== ""){
                e.target.classList.remove("expman-required-error");
              }
            }
          });
          document.addEventListener("submit", function(e){
            var form = e.target;
            if(!form || !form.querySelectorAll){ return; }
            if(!validateRequired(form)){ e.preventDefault(); }
          }, true);
          markRequiredLabels();
        })();
        </script>';
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

        // SSL logs (separate table)
        $ssl_logs_file = plugin_dir_path( __FILE__ ) . 'includes/admin/ssl/class-expman-ssl-logs.php';
        if ( file_exists( $ssl_logs_file ) ) {
            require_once $ssl_logs_file;
            if ( class_exists( 'Expman_SSL_Logs' ) ) {
                Expman_SSL_Logs::install_table();
            }
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

        // Domains WHOIS scan (01:00 + 13:00 daily)
        if ( class_exists( 'Expman_Domains_Manager' ) ) {
            Expman_Domains_Manager::activate_cron();
        }
    }

    public function on_deactivate() {
        if ( class_exists( 'Expman_Domains_Manager' ) ) {
            Expman_Domains_Manager::deactivate_cron();
        }
    }

    public function register_admin_menu() {
        add_menu_page(
            __( 'ניהול תאריכי תפוגה', 'expiry-manager' ),
            __( 'ניהול תאריכי תפוגה', 'expiry-manager' ),
            'read',
            'expman_dashboard',
            function() { ( new Expman_Dashboard_Page( self::OPTION_KEY ) )->render_page(); },
            'dashicons-clock',
            55
        );

        add_submenu_page('expman_dashboard', 'Dashboard', 'Dashboard', 'read', 'expman_dashboard',
            function() { ( new Expman_Dashboard_Page( self::OPTION_KEY ) )->render_page(); });

        add_submenu_page('expman_dashboard', 'חומות אש', 'חומות אש', 'read', 'expman_firewalls',
            function() { ( new Expman_Firewalls_Page( self::OPTION_KEY ) )->render_page(); });

        add_submenu_page('expman_dashboard', 'תעודות', 'תעודות', 'read', 'expman_certs',
            function() { ( new Expman_Generic_Items_Page( self::OPTION_KEY, 'certs', 'תעודות אבטחה' ) )->render_page(); });

        add_submenu_page('expman_dashboard', 'דומיינים', 'דומיינים', 'read', 'expman_domains',
            function() { ( new Expman_Domains_Page() )->render_page(); });
add_submenu_page('expman_dashboard', 'שרתים', 'שרתים', 'read', 'expman_servers',
            function() { ( new Expman_Servers_Page( self::OPTION_KEY, self::VERSION ) )->render_page(); });

        add_submenu_page('expman_dashboard', 'Trash', 'Trash', 'read', 'expman_trash',
            function() { ( new Expman_Trash_Page( self::OPTION_KEY ) )->render_page(); });

        add_submenu_page('expman_dashboard', 'Logs', 'Logs', 'read', 'expman_logs',
            function() { ( new Expman_Logs_Page( self::OPTION_KEY ) )->render_page(); });

        add_submenu_page('expman_dashboard', 'Settings', 'Settings', 'read', 'expman_settings',
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
        return '';
    }


    public function ajax_customer_search() {
        $nonce = sanitize_text_field( $_GET['nonce'] ?? '' );
        if ( ! wp_verify_nonce( $nonce, 'expman_customer_search' ) ) {
            wp_send_json( array( 'items' => array() ) );
        }

        global $wpdb;
        $q = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
        if ( mb_strlen( $q ) < 2 ) {
            wp_send_json( array( 'items' => array() ) );
        }

        // Performance: resolve customer table & columns once, cache in transient.
        // This endpoint can be hit on every keystroke, so avoiding SHOW TABLES/COLUMNS is critical.
        $cache_key = 'expman_customer_search_tableinfo_v1';
        $info = get_transient( $cache_key );

        if ( ! is_array( $info ) || empty( $info['table'] ) ) {
            $settings  = get_option( self::OPTION_KEY, array() );
            $raw_table = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) ( $settings['customers_table'] ?? '' ) );
            $candidates = array();

            if ( $raw_table !== '' ) {
                $candidates[] = $raw_table;
                if ( strpos( $raw_table, $wpdb->prefix ) !== 0 ) {
                    $candidates[] = $wpdb->prefix . $raw_table;
                }
            }
            $candidates[] = $wpdb->prefix . 'dc_customers';

            $table = '';
            foreach ( $candidates as $candidate ) {
                $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $candidate ) );
                if ( $exists === $candidate ) {
                    $table = $candidate;
                    break;
                }
            }
            if ( $table === '' ) {
                wp_send_json( array( 'items' => array() ) );
            }

            // Resolve column names once (some installs use slightly different names)
            $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
            if ( ! is_array( $cols ) ) { $cols = array(); }
            $cols_lc = array();
            foreach ( $cols as $c ) { $cols_lc[ strtolower( (string) $c ) ] = (string) $c; }

            $name_col = $cols_lc['customer_name'] ?? ( $cols_lc['name'] ?? ( $cols_lc['client_name'] ?? 'customer_name' ) );
            $num_col  = $cols_lc['customer_number'] ?? ( $cols_lc['number'] ?? ( $cols_lc['customer_no'] ?? 'customer_number' ) );
            $id_col   = $cols_lc['id'] ?? 'id';
            $has_is_deleted = isset( $cols_lc['is_deleted'] );

            $info = array(
                'table' => $table,
                'id_col' => $id_col,
                'name_col' => $name_col,
                'num_col' => $num_col,
                'has_is_deleted' => $has_is_deleted ? 1 : 0,
            );

            // Cache for 12 hours.
            set_transient( $cache_key, $info, 12 * HOUR_IN_SECONDS );
        }

        $table = $info['table'];
        $id_col = $info['id_col'] ?? 'id';
        $name_col = $info['name_col'] ?? 'customer_name';
        $num_col  = $info['num_col'] ?? 'customer_number';
        $deleted_clause = ! empty( $info['has_is_deleted'] ) ? 'is_deleted=0 AND ' : '';

        $like  = '%' . $wpdb->esc_like( $q ) . '%';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT {$id_col} AS id, {$name_col} AS customer_name, {$num_col} AS customer_number
                 FROM {$table}
                 WHERE {$deleted_clause}({$name_col} LIKE %s OR {$num_col} LIKE %s)
                 ORDER BY {$name_col} ASC
                 LIMIT 50",
                $like,
                $like
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

    public function handle_expman_export_servers_csv() {
        if ( ! current_user_can( 'read' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'expiry-manager' ), 403 );
        }

        check_admin_referer( 'expman_export_servers_csv', 'expman_export_servers_csv_nonce' );

        if ( ! class_exists( 'Expman_Servers_Page' ) ) {
            wp_die( esc_html__( 'Servers module not available.', 'expiry-manager' ) );
        }

        $page = new Expman_Servers_Page( self::OPTION_KEY, self::VERSION );
        $page->get_actions()->action_export_csv();
        exit;
    }

    public function handle_expman_ssl_single_check() {
        $post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0;
        if ( $post_id <= 0 ) { wp_die( 'Bad post_id' ); }

        $nonce = sanitize_text_field( $_GET['_wpnonce'] ?? '' );
        if ( ! wp_verify_nonce( $nonce, 'expman_ssl_single_check_' . $post_id ) ) {
            wp_die( 'Bad nonce' );
        }

        if ( ! class_exists( 'Expman_SSLCerts_Page' ) ) {
            wp_die( 'Certificates module missing' );
        }

        $redirect = '';
        if ( isset( $_GET['redirect_to'] ) ) {
            $candidate = esc_url_raw( wp_unslash( $_GET['redirect_to'] ) );
            if ( $candidate ) { $redirect = $candidate; }
        }
        if ( ! $redirect ) { $redirect = wp_get_referer(); }
        if ( ! $redirect ) { $redirect = home_url( '/' ); }

        $ok = Expman_SSLCerts_Page::enqueue_check_task( $post_id, 'manual' );

        $redirect = remove_query_arg( array( 'expman_ssl_checked', 'expman_ssl_error' ), $redirect );
        $redirect = add_query_arg( $ok ? 'expman_ssl_checked' : 'expman_ssl_error', $post_id, $redirect );

        wp_safe_redirect( $redirect );
        exit;
    }

    public function handle_expman_ssl_export() {
        if ( ! current_user_can( 'read' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'expiry-manager' ), 403 );
        }

        check_admin_referer( 'expman_ssl_export', 'expman_ssl_export_nonce' );

        if ( ! class_exists( 'Expman_SSLCerts_Page' ) ) {
            wp_die( 'SSL certs module not loaded.' );
        }

        global $wpdb;
        $table = $wpdb->prefix . Expman_SSLCerts_Page::CERTS_TABLE;
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            wp_die( 'Certificates table not found: ' . esc_html( $table ) );
        }

        $cols = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
        $fields = array();
        foreach ( (array) $cols as $c ) {
            if ( ! empty( $c['Field'] ) ) { $fields[] = $c['Field']; }
        }
        if ( empty( $fields ) ) {
            wp_die( 'No columns found.' );
        }

        $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );

        $filename = 'ssl_certs_export_' . gmdate( 'Ymd_His' ) . '.csv';
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        $out = fopen( 'php://output', 'w' );
        if ( $out ) {
            // UTF-8 BOM so Excel will open Hebrew nicely
            fprintf( $out, "\xEF\xBB\xBF" );
            fputcsv( $out, $fields );
            foreach ( (array) $rows as $r ) {
                $line = array();
                foreach ( $fields as $f ) {
                    $line[] = isset( $r[ $f ] ) ? $r[ $f ] : '';
                }
                fputcsv( $out, $line );
            }
            fclose( $out );
        }
        exit;
    }

    public function handle_expman_ssl_import() {
        if ( ! current_user_can( 'read' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'expiry-manager' ), 403 );
        }

        check_admin_referer( 'expman_ssl_import', 'expman_ssl_import_nonce' );

        if ( ! class_exists( 'Expman_SSLCerts_Page' ) ) {
            wp_die( 'SSL certs module not loaded.' );
        }

        if ( empty( $_FILES['expman_ssl_csv'] ) || empty( $_FILES['expman_ssl_csv']['tmp_name'] ) ) {
            wp_safe_redirect( add_query_arg( 'expman_ssl_msg', rawurlencode( 'לא נבחר קובץ לייבוא' ), $this->expman_redirect_back() ) );
            exit;
        }

        $tmp = $_FILES['expman_ssl_csv']['tmp_name'];
        $fh = fopen( $tmp, 'r' );
        if ( ! $fh ) {
            wp_safe_redirect( add_query_arg( 'expman_ssl_msg', rawurlencode( 'לא ניתן לקרוא את הקובץ' ), $this->expman_redirect_back() ) );
            exit;
        }

        global $wpdb;
        $table = $wpdb->prefix . Expman_SSLCerts_Page::CERTS_TABLE;
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            fclose( $fh );
            wp_safe_redirect( add_query_arg( 'expman_ssl_msg', rawurlencode( 'טבלת תעודות לא נמצאה' ), $this->expman_redirect_back() ) );
            exit;
        }

        $cols = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
        $allowed = array();
        foreach ( (array) $cols as $c ) {
            if ( ! empty( $c['Field'] ) ) { $allowed[ $c['Field'] ] = true; }
        }

        $header = fgetcsv( $fh );
        if ( ! is_array( $header ) ) {
            fclose( $fh );
            wp_safe_redirect( add_query_arg( 'expman_ssl_msg', rawurlencode( 'כותרת CSV לא תקינה' ), $this->expman_redirect_back() ) );
            exit;
        }

        // Trim BOM
        if ( isset( $header[0] ) ) {
            $header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
        }

        $map = array();
        foreach ( $header as $idx => $name ) {
            $name = sanitize_key( $name );
            if ( $name !== '' && isset( $allowed[ $name ] ) ) {
                $map[ $idx ] = $name;
            }
        }

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        while ( ( $row = fgetcsv( $fh ) ) !== false ) {
            if ( ! is_array( $row ) ) { continue; }
            $data = array();
            foreach ( $map as $idx => $col ) {
                $val = isset( $row[ $idx ] ) ? $row[ $idx ] : '';
                $data[ $col ] = $val;
            }

            // Basic normalization for numeric fields
            $numeric = array( 'id','post_id','expiry_ts','manual_mode','allow_duplicate_site','follow_up','temporary_enabled','expiry_ts_checked_at' );
            foreach ( $numeric as $nf ) {
                if ( isset( $data[ $nf ] ) && $data[ $nf ] !== '' && is_numeric( $data[ $nf ] ) ) {
                    $data[ $nf ] = (string) intval( $data[ $nf ] );
                }
            }

            $post_id = isset( $data['post_id'] ) ? intval( $data['post_id'] ) : 0;
            $id      = isset( $data['id'] ) ? intval( $data['id'] ) : 0;

            // Remove empty id to avoid accidental primary key collisions
            if ( $id <= 0 ) { unset( $data['id'] ); }

            if ( $post_id > 0 ) {
                $res = $wpdb->replace( $table, $data );
                if ( $res === false ) { $skipped++; } else { $updated++; }
            } elseif ( $id > 0 ) {
                $res = $wpdb->update( $table, $data, array( 'id' => $id ) );
                if ( $res === false ) { $skipped++; } else { $updated++; }
            } else {
                $res = $wpdb->insert( $table, $data );
                if ( $res === false ) { $skipped++; } else { $inserted++; }
            }
        }

        fclose( $fh );

        $msg = sprintf( 'ייבוא הסתיים. נוספו: %d | עודכנו: %d | דולגו/שגיאות: %d', $inserted, $updated, $skipped );
        wp_safe_redirect( add_query_arg( 'expman_ssl_msg', rawurlencode( $msg ), $this->expman_redirect_back() ) );
        exit;
    }

    public function handle_expman_ssl_trash() {
        if ( ! current_user_can( 'read' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'expiry-manager' ), 403 );
        }

        $post_id = intval( $_GET['post_id'] ?? 0 );
        $row_id  = intval( $_GET['row_id'] ?? 0 );
        $nonce   = sanitize_text_field( $_GET['_wpnonce'] ?? '' );
        if ( ! wp_verify_nonce( $nonce, 'expman_ssl_trash_' . ( $post_id ?: $row_id ) ) ) {
            wp_die( 'Bad nonce' );
        }

        $ok = false;
        if ( $post_id > 0 && function_exists( 'wp_trash_post' ) ) {
            $res = wp_trash_post( $post_id );
            $ok  = ( $res !== false );
        }

        if ( ! $ok ) {
            global $wpdb;
            $table = $wpdb->prefix . Expman_SSLCerts_Page::CERTS_TABLE;
            if ( $row_id > 0 ) {
                $ok = $wpdb->update( $table, array( 'status' => 'trash' ), array( 'id' => $row_id ), array( '%s' ), array( '%d' ) ) !== false;
            } elseif ( $post_id > 0 ) {
                $ok = $wpdb->update( $table, array( 'status' => 'trash' ), array( 'post_id' => $post_id ), array( '%s' ), array( '%d' ) ) !== false;
            }
        }

        $redirect = $this->expman_redirect_back();
        $redirect = remove_query_arg( array( 'expman_ssl_action', 'expman_ssl_ok' ), $redirect );
        $redirect = add_query_arg( array( 'expman_ssl_action' => 'trash', 'expman_ssl_ok' => $ok ? 1 : 0 ), $redirect );
        wp_safe_redirect( $redirect );
        exit;
    }

    public function handle_expman_ssl_restore() {
        if ( ! current_user_can( 'read' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'expiry-manager' ), 403 );
        }

        $post_id = intval( $_GET['post_id'] ?? 0 );
        $row_id  = intval( $_GET['row_id'] ?? 0 );
        $nonce   = sanitize_text_field( $_GET['_wpnonce'] ?? '' );
        if ( ! wp_verify_nonce( $nonce, 'expman_ssl_restore_' . ( $post_id ?: $row_id ) ) ) {
            wp_die( 'Bad nonce' );
        }

        $ok = false;
        if ( $post_id > 0 && function_exists( 'wp_untrash_post' ) ) {
            $res = wp_untrash_post( $post_id );
            $ok  = ( $res !== false );
        }

        if ( ! $ok ) {
            global $wpdb;
            $table = $wpdb->prefix . Expman_SSLCerts_Page::CERTS_TABLE;
            if ( $row_id > 0 ) {
                $ok = $wpdb->update( $table, array( 'status' => 'publish' ), array( 'id' => $row_id ), array( '%s' ), array( '%d' ) ) !== false;
            } elseif ( $post_id > 0 ) {
                $ok = $wpdb->update( $table, array( 'status' => 'publish' ), array( 'post_id' => $post_id ), array( '%s' ), array( '%d' ) ) !== false;
            }
        }

        $redirect = $this->expman_redirect_back();
        $redirect = remove_query_arg( array( 'expman_ssl_action', 'expman_ssl_ok' ), $redirect );
        $redirect = add_query_arg( array( 'expman_ssl_action' => 'restore', 'expman_ssl_ok' => $ok ? 1 : 0 ), $redirect );
        wp_safe_redirect( $redirect );
        exit;
    }

    public function handle_expman_ssl_delete_permanent() {
        if ( ! current_user_can( 'read' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'expiry-manager' ), 403 );
        }

        $post_id = intval( $_GET['post_id'] ?? 0 );
        $row_id  = intval( $_GET['row_id'] ?? 0 );
        $nonce   = sanitize_text_field( $_GET['_wpnonce'] ?? '' );
        if ( ! wp_verify_nonce( $nonce, 'expman_ssl_delete_permanent_' . ( $post_id ?: $row_id ) ) ) {
            wp_die( 'Bad nonce' );
        }

        $ok = false;
        if ( $post_id > 0 && function_exists( 'wp_delete_post' ) ) {
            $res = wp_delete_post( $post_id, true );
            $ok  = ( $res !== false );
        }

        if ( ! $ok ) {
            global $wpdb;
            $table = $wpdb->prefix . Expman_SSLCerts_Page::CERTS_TABLE;
            if ( $row_id > 0 ) {
                $ok = $wpdb->delete( $table, array( 'id' => $row_id ), array( '%d' ) ) !== false;
            } elseif ( $post_id > 0 ) {
                $ok = $wpdb->delete( $table, array( 'post_id' => $post_id ), array( '%d' ) ) !== false;
            }
        }

        $redirect = $this->expman_redirect_back();
        $redirect = remove_query_arg( array( 'expman_ssl_action', 'expman_ssl_ok' ), $redirect );
        $redirect = add_query_arg( array( 'expman_ssl_action' => 'delete', 'expman_ssl_ok' => $ok ? 1 : 0 ), $redirect );
        wp_safe_redirect( $redirect );
        exit;
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

        if ( class_exists( 'Expman_SSLCerts_Page' ) ) {
            return Expman_SSLCerts_Page::render();
        }

        // Fallback: previous generic certs view
        return $this->shortcode_generic( array( 'type' => 'certs', 'title' => 'תעודות אבטחה' ) );
    }

    public function shortcode_domains() {
        $guard = $this->shortcode_guard();
        if ( $guard !== '' ) { return $guard; }

        if ( ! class_exists( 'Expman_Domains_Manager' ) ) {
            return '<div>Expman_Domains_Manager missing</div>';
        }

        $drm = new Expman_Domains_Manager();
        if ( method_exists( $drm, 'enqueue_front_assets' ) ) {
            $drm->enqueue_front_assets();
        }

        $tab = isset( $_GET['expman_tab'] ) ? sanitize_key( $_GET['expman_tab'] ) : 'main';
        if ( ! in_array( $tab, array( 'main', 'trash', 'settings', 'map' ), true ) ) {
            $tab = 'main';
        }

        $tabs = array(
            'main'     => 'טבלה ראשית',
            'trash'    => 'סל מחזור',
            'settings' => 'הגדרות',
            'map'      => 'שיוך לקוח',
        );

        $this->buffer_start();

        if ( class_exists( 'Expman_Nav' ) ) {
            Expman_Nav::render_public_nav( self::OPTION_KEY, self::VERSION );
        }

        // Frontend tabs (so the old + new plugins can coexist without using wp-admin routes)
        echo '<div class="expman-domains-frontend-tabs" style="direction:rtl;text-align:right;margin:12px 0 16px;">';
        $base = remove_query_arg( array( 'expman_tab', 'orderby', 'order' ) );
        foreach ( $tabs as $key => $label ) {
            $url = add_query_arg( array( 'expman_tab' => $key ), $base );
            $cls = 'button' . ( $tab === $key ? ' button-primary' : '' );
            echo '<a class="' . esc_attr( $cls ) . '" style="margin-left:6px;" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</div>';

        switch ( $tab ) {
            case 'trash':
                $drm->render_trash();
                break;
            case 'settings':
                $drm->render_settings();
                break;
            case 'map':
                $drm->render_map();
                break;
            case 'main':
            default:
                $drm->render_admin();
                break;
        }

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
        if ( empty( $_POST['expman_server_create_nonce'] ) || ! wp_verify_nonce( $_POST['expman_server_create_nonce'], 'expman_server_create' ) ) {
            wp_die( 'Bad nonce' );
        }

        $service_tag = strtoupper( sanitize_text_field( $_POST['service_tag'] ?? '' ) );
        $customer_id = isset( $_POST['customer_id'] ) ? intval( $_POST['customer_id'] ) : 0;
        $customer_number = sanitize_text_field( $_POST['customer_number'] ?? '' );
        $customer_name = sanitize_text_field( $_POST['customer_name'] ?? '' );

        $express_service_code = sanitize_text_field( $_POST['express_service_code'] ?? '' );
        $ship_date  = sanitize_text_field( $_POST['ship_date'] ?? '' );
        $ending_on  = sanitize_text_field( $_POST['ending_on'] ?? '' );
        $service_level = sanitize_text_field( $_POST['service_level'] ?? '' );
        $server_model  = sanitize_text_field( $_POST['server_model'] ?? '' );
        $notes = sanitize_textarea_field( $_POST['notes'] ?? '' );
        $temp_notice_enabled = ! empty( $_POST['temp_notice_enabled'] ) ? 1 : 0;
        $temp_notice_text = sanitize_textarea_field( $_POST['temp_notice_text'] ?? '' );

        $sync_now = ! empty( $_POST['sync_now'] );

        $redirect_base = wp_get_referer();
        if ( ! $redirect_base ) {
            $redirect_base = admin_url( 'admin.php?page=expman_servers' );
        }

        if ( $service_tag === '' ) {
            wp_safe_redirect( add_query_arg( array( 'msg' => 'missing_tag' ), $redirect_base ) );
            exit;
        }

        require_once plugin_dir_path(__FILE__) . 'includes/admin/class-expman-servers-page.php';
        // Ensure schema exists before insert.
        if ( class_exists( 'Expman_Servers_Page' ) ) {
            Expman_Servers_Page::install_tables();
        }
        $page = new Expman_Servers_Page( self::OPTION_KEY, self::VERSION );

        global $wpdb;
        $table = $wpdb->prefix . Expman_Servers_Page::TABLE_SERVERS;

        // Basic date validation (HTML date input yields YYYY-MM-DD)
        $ship_date_db = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ship_date ) ? $ship_date : null;
        $ending_on_db = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ending_on ) ? $ending_on : null;

        $wpdb->insert(
            $table,
            array(
                'option_key'               => self::OPTION_KEY,
                'customer_id'              => $customer_id > 0 ? $customer_id : null,
                'customer_number_snapshot' => $customer_number !== '' ? $customer_number : null,
                'customer_name_snapshot'   => $customer_name !== '' ? $customer_name : null,
                'service_tag'              => $service_tag,
                'express_service_code'     => $express_service_code !== '' ? $express_service_code : null,
                'ship_date'                => $ship_date_db,
                'ending_on'                => $ending_on_db,
                'service_level'            => $service_level !== '' ? $service_level : null,
                'server_model'             => $server_model !== '' ? $server_model : null,
                'temp_notice_enabled'      => $temp_notice_enabled,
                'temp_notice_text'         => $temp_notice_enabled ? $temp_notice_text : null,
                'notes'                    => $notes !== '' ? $notes : null,
                'created_at'               => current_time( 'mysql' ),
                'updated_at'               => current_time( 'mysql' ),
            ),
            array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
        );

        $new_id = (int) $wpdb->insert_id;

        if ( ! $new_id ) {
            wp_safe_redirect( add_query_arg( array( 'msg' => 'db_error' ), $redirect_base ) );
            exit;
        }

        if ( $sync_now ) {
            $page->get_actions()->set_option_key( self::OPTION_KEY );
            $page->get_actions()->set_dell( $page->get_dell() );
            $page->get_actions()->sync_server_by_id( $new_id );
        }

        wp_safe_redirect( add_query_arg( array( 'msg' => 'created' ), $redirect_base ) );
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

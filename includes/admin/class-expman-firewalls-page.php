<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'Expman_Firewalls_Page' ) ) {
class Expman_Firewalls_Page {

    const TABLE_FIREWALLS = 'exp_firewalls';
    const TABLE_TYPES     = 'exp_firewall_box_types';
    const TABLE_FORTICLOUD_ASSETS = 'exp_forticloud_assets';
    const TABLE_FIREWALL_LOGS = 'exp_firewall_logs';

    private function column_exists( $table, $column ) {
        global $wpdb;
        $col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
        return ! empty( $col );
    }

    /**
     * Convenience wrapper for checking a column on the firewalls table.
     */
    private function fw_column_exists( $column ) {
        global $wpdb;
        $fw_table = $wpdb->prefix . self::TABLE_FIREWALLS;
        return $this->column_exists( $fw_table, $column );
    }

    private function ensure_schema() {
        global $wpdb;
        $fw_table = $wpdb->prefix . self::TABLE_FIREWALLS;

        // Ensure base tables exist
        self::install_tables();

        // Add missing columns on existing installs
        $wanted = array(
            'customer_number' => "ALTER TABLE {$fw_table} ADD COLUMN customer_number VARCHAR(6) NULL",
            'customer_name'   => "ALTER TABLE {$fw_table} ADD COLUMN customer_name VARCHAR(255) NULL",
            'track_only'      => "ALTER TABLE {$fw_table} ADD COLUMN track_only TINYINT(1) NOT NULL DEFAULT 0",
            'access_url'      => "ALTER TABLE {$fw_table} ADD COLUMN access_url VARCHAR(2048) NULL",
            'temp_notice_enabled' => "ALTER TABLE {$fw_table} ADD COLUMN temp_notice_enabled TINYINT(1) NOT NULL DEFAULT 0",
            'temp_notice'     => "ALTER TABLE {$fw_table} ADD COLUMN temp_notice TEXT NULL",
        );

        foreach ( $wanted as $col => $sql ) {
            if ( ! $this->column_exists( $fw_table, $col ) ) {
                $wpdb->query( $sql );
            }
        }
    }



    private static function maybe_add_column( $table, $definition, $column_name ) {
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column_name ) );
        if ( empty( $exists ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$definition}" );
        }
    }

    public static function install_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $fw_table    = $wpdb->prefix . self::TABLE_FIREWALLS;
        $types_table = $wpdb->prefix . self::TABLE_TYPES;
        $assets_table = $wpdb->prefix . self::TABLE_FORTICLOUD_ASSETS;
        $logs_table = $wpdb->prefix . self::TABLE_FIREWALL_LOGS;

        $sql_fw = "CREATE TABLE {$fw_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT(20) NULL,
            customer_number VARCHAR(6) NULL,
            customer_name VARCHAR(255) NULL,
            branch VARCHAR(255) NULL,
            serial_number VARCHAR(255) NOT NULL,
            is_managed TINYINT(1) NOT NULL DEFAULT 1,
            track_only TINYINT(1) NOT NULL DEFAULT 0,
            box_type_id BIGINT(20) NULL,
            expiry_date DATE NULL,
            access_url VARCHAR(255) NULL,
            notes TEXT NULL,
            temp_notice_enabled TINYINT(1) NOT NULL DEFAULT 0,
            temp_notice TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY customer_id (customer_id),
            KEY expiry_date (expiry_date),
            KEY serial_number (serial_number),
            KEY is_managed (is_managed),
            KEY track_only (track_only)
        ) {$charset_collate};";

        $sql_types = "CREATE TABLE {$types_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            vendor VARCHAR(255) NOT NULL,
            model VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            UNIQUE KEY unique_vendor_model (vendor, model),
            PRIMARY KEY (id)
        ) {$charset_collate};";

        dbDelta( $sql_fw );
        
        // Migrations for new columns
        self::maybe_add_column( $fw_table, "customer_number VARCHAR(6) NULL", "customer_number" );
        self::maybe_add_column( $fw_table, "customer_name VARCHAR(255) NULL", "customer_name" );

        $sql_assets = "CREATE TABLE {$assets_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            forticloud_id VARCHAR(255) NULL,
            serial_number VARCHAR(255) NOT NULL,
            category_name VARCHAR(255) NULL,
            model_name VARCHAR(255) NULL,
            registration_date DATE NULL,
            ship_date DATE NULL,
            expiration_date DATE NULL,
            description TEXT NULL,
            folder_id VARCHAR(255) NULL,
            asset_groups TEXT NULL,
            raw_json LONGTEXT NULL,
            customer_id BIGINT(20) NULL,
            customer_number_snapshot VARCHAR(64) NULL,
            customer_name_snapshot VARCHAR(255) NULL,
            mapped_at DATETIME NULL,
            updated_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY serial_number (serial_number),
            KEY customer_id (customer_id)
        ) {$charset_collate};";

        $sql_logs = "CREATE TABLE {$logs_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            firewall_id BIGINT(20) NULL,
            action VARCHAR(50) NOT NULL,
            level VARCHAR(20) NOT NULL DEFAULT 'info',
            message TEXT NULL,
            context LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY firewall_id (firewall_id),
            KEY action (action),
            KEY level (level)
        ) {$charset_collate};";

        dbDelta( $sql_assets );
dbDelta( $sql_types );
        dbDelta( $sql_logs );
    }

    public static function render_public_page( $option_key, $version = '' ) {
        // Internal site, but still require login (per earlier requirement)
        if ( ! is_user_logged_in() ) {
            echo '<div class="notice notice-error"><p>אין הרשאה. יש להתחבר.</p></div>';
            return;
        }

        self::install_if_missing();
        $self = new self( (string) $option_key, (string) $version );
        $self->handle_actions();
        $self->render();
    }

    private static function install_if_missing() {
        global $wpdb;
        $fw_table = $wpdb->prefix . self::TABLE_FIREWALLS;
        $exists   = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $fw_table ) );
        if ( $exists !== $fw_table ) {
            self::install_tables();
            return;
        }

        $assets_table = $wpdb->prefix . self::TABLE_FORTICLOUD_ASSETS;
        $assets_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $assets_table ) );
        if ( $assets_exists !== $assets_table ) {
            self::install_tables();
        }

        $logs_table = $wpdb->prefix . self::TABLE_FIREWALL_LOGS;
        $logs_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $logs_table ) );
        if ( $logs_exists !== $logs_table ) {
            self::install_tables();
        }

        // Ensure new columns exist (track_only)
        $cols = $wpdb->get_results( "SHOW COLUMNS FROM {$fw_table}" );
        $names = array();
        foreach ( (array) $cols as $c ) { $names[] = $c->Field; }
        if ( ! in_array( 'track_only', $names, true ) ) {
            $wpdb->query( "ALTER TABLE {$fw_table} ADD COLUMN track_only TINYINT(1) NOT NULL DEFAULT 0 AFTER is_managed" );
        }
    }

    private $option_key;
    private $version;
    private $notices = array();

    private function __construct( $option_key, $version ) {
        $this->option_key = $option_key;
        $this->version    = $version;
    }

    private function add_notice( $message, $type = 'success' ) {
        $this->notices[] = array(
            'type' => $type,
            'text' => $message,
        );
    }

    private function get_crypto_key() {
        return hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true );
    }

    private function encrypt_secret( $plaintext ) {
        $key = $this->get_crypto_key();
        $cipher = 'aes-256-gcm';
        $iv = random_bytes( 12 );
        $tag = '';
        $ciphertext = openssl_encrypt( $plaintext, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag );
        if ( false === $ciphertext ) {
            $cipher = 'aes-256-cbc';
            $iv = random_bytes( 16 );
            $ciphertext = openssl_encrypt( $plaintext, $cipher, $key, OPENSSL_RAW_DATA, $iv );
            $tag = '';
        }

        return array(
            'cipher' => $cipher,
            'value'  => base64_encode( $ciphertext ),
            'iv'     => base64_encode( $iv ),
            'tag'    => $tag !== '' ? base64_encode( $tag ) : '',
        );
    }

    private function decrypt_secret( $payload ) {
        if ( empty( $payload['value'] ) || empty( $payload['iv'] ) || empty( $payload['cipher'] ) ) {
            return '';
        }

        $key = $this->get_crypto_key();
        $ciphertext = base64_decode( $payload['value'] );
        $iv = base64_decode( $payload['iv'] );
        $tag = ! empty( $payload['tag'] ) ? base64_decode( $payload['tag'] ) : '';

        if ( $payload['cipher'] === 'aes-256-gcm' ) {
            $plain = openssl_decrypt( $ciphertext, $payload['cipher'], $key, OPENSSL_RAW_DATA, $iv, $tag );
        } else {
            $plain = openssl_decrypt( $ciphertext, $payload['cipher'], $key, OPENSSL_RAW_DATA, $iv );
        }

        return $plain ? $plain : '';
    }

    private function get_forticloud_settings() {
        $settings = get_option( $this->option_key, array() );
        return $settings['forticloud'] ?? array();
    }

    private function update_forticloud_settings( $forti_settings ) {
        $settings = get_option( $this->option_key, array() );
        $settings['forticloud'] = $forti_settings;
        update_option( $this->option_key, $settings );
    }

    private function get_forticloud_endpoints() {
        $defaults = array(
            // Fortinet Support Portal - Asset Management API
            'assets' => '/app/asset/api/products',

            // OAuth token retrieval (FortiAuthenticator) - FortiCloud IAM API Users
            'token'  => 'https://customerapiauth.fortinet.com/api/v1/oauth/token/',

            // Optional update endpoint (varies by API availability)
            'update' => '',
        );

        return apply_filters( 'expman_forticloud_endpoints', $defaults );
    }


    private function log_firewall_event( $firewall_id, $action, $message = '', $context = array(), $level = 'info' ) {
        global $wpdb;
        $logs_table = $wpdb->prefix . self::TABLE_FIREWALL_LOGS;
        $wpdb->insert(
            $logs_table,
            array(
                'firewall_id' => $firewall_id ? intval( $firewall_id ) : null,
                'action'      => sanitize_text_field( $action ),
                'level'       => sanitize_text_field( $level ),
                'message'     => sanitize_text_field( $message ),
                'context'     => ! empty( $context ) ? wp_json_encode( $context ) : null,
                'created_at'  => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s' )
        );
    }

    private function new_request_id() {
        return 'sync_' . gmdate('Ymd_His') . '_' . substr( wp_hash( microtime(true) . rand() ), 0, 10 );
    }

    private function http_debug_context( $url, $response ) {
        if ( is_wp_error( $response ) ) {
            return array(
                'url'   => $url,
                'error' => $response->get_error_message(),
                'data'  => $response->get_error_data(),
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        return array(
            'url'          => $url,
            'http_code'    => $code,
            'body_preview' => mb_substr( (string) $body, 0, 2000 ),
        );
    }

    private function normalize_base_url_and_endpoints( $base_url, &$endpoints ) {
        $base_url = trim( (string) $base_url );
        if ( $base_url === '' ) {
            return '';
        }

        $parts = wp_parse_url( $base_url );
        if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            $base_url = 'https://' . ltrim( $base_url, '/' );
            $parts = wp_parse_url( $base_url );
        }

        if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return trim( $base_url );
        }

        $scheme = $parts['scheme'];
        $host   = $parts['host'];
        $port   = isset( $parts['port'] ) ? ':' . intval( $parts['port'] ) : '';
        $path   = isset( $parts['path'] ) ? (string) $parts['path'] : '';

        $normalized = $scheme . '://' . $host . $port;

        // If user pasted a full endpoint, treat the path as the assets endpoint (when matching known prefixes).
        if ( $path && $path !== '/' ) {
            $path = '/' . ltrim( $path, '/' );
            if ( strpos( $path, '/app/asset/api/' ) === 0 || strpos( $path, '/asset/' ) === 0 ) {
                if ( empty( $endpoints['assets'] ) || $endpoints['assets'] === '/app/asset/api/products' || $endpoints['assets'] === '/asset/v1/products' ) {
                    $endpoints['assets'] = $path;
                }
            }
        }

        return $normalized;
    }


    private function get_active_tab() {
        $allowed = array( 'main', 'bulk', 'settings', 'trash', 'logs' );
        $tab = sanitize_key( $_REQUEST['tab'] ?? 'main' );
        return in_array( $tab, $allowed, true ) ? $tab : 'main';
    }

    /* ---------------- Actions ---------------- */

    private function handle_actions() {
        if ( empty( $_POST['expman_action'] ) ) {
            return;
        }
        if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'expman_firewalls' ) ) {
            return;
        }

        $action = sanitize_key( $_POST['expman_action'] );

        if ( $action === 'export_csv' ) {
            $this->action_export_csv();
            exit;
        }

        switch ( $action ) {
            case 'save_firewall':
                $this->action_save_firewall();
                break;
            case 'save_forticloud_settings':
                $this->action_save_forticloud_settings();
                break;
            case 'sync_forticloud_assets':
                $this->action_sync_forticloud_assets();
                break;
            case 'map_forticloud_asset':
                $this->action_map_forticloud_asset();
                break;
            case 'trash_firewall':
                $this->action_trash_firewall();
                break;
            case 'restore_firewall':
                $this->action_restore_firewall();
                break;
            case 'import_csv':
                $this->action_import_csv();
                break;
        }

        wp_safe_redirect( remove_query_arg( array( 'expman_msg' ) ) );
        exit;
    }

    private function action_save_firewall() {
        global $wpdb;
        $fw_table    = $wpdb->prefix . self::TABLE_FIREWALLS;
        $types_table = $wpdb->prefix . self::TABLE_TYPES;

        $id          = isset( $_POST['firewall_id'] ) ? intval( $_POST['firewall_id'] ) : 0;
        $customer_id = isset( $_POST['customer_id'] ) ? intval( $_POST['customer_id'] ) : 0;

        $customer_number = sanitize_text_field( $_POST['customer_number'] ?? '' );
        $customer_name   = sanitize_text_field( $_POST['customer_name'] ?? '' );

        $branch      = sanitize_text_field( $_POST['branch'] ?? '' );
        $serial      = sanitize_text_field( $_POST['serial_number'] ?? '' );

        $is_managed  = isset( $_POST['is_managed'] ) ? intval( $_POST['is_managed'] ) : 1;
        $track_only  = isset( $_POST['track_only'] ) ? 1 : 0;

        $expiry      = sanitize_text_field( $_POST['expiry_date'] ?? '' );
        $access_url  = sanitize_text_field( $_POST['access_url'] ?? '' );
        $notes       = wp_kses_post( $_POST['notes'] ?? '' );

        $temp_enabled = isset( $_POST['temp_notice_enabled'] ) ? 1 : 0;
        $temp_notice  = wp_kses_post( $_POST['temp_notice'] ?? '' );

        $vendor      = sanitize_text_field( $_POST['vendor'] ?? '' );
        $model       = sanitize_text_field( $_POST['model'] ?? '' );

        $errors = array();
        if ( $serial === '' ) { $errors[] = 'מספר סידורי חובה.'; }

        // Unique serial validation (excluding trashed)
        if ( $serial !== '' ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$fw_table} WHERE serial_number=%s AND deleted_at IS NULL AND id != %d LIMIT 1",
                $serial, $id
            ) );
            if ( $existing ) {
                $errors[] = 'מספר סידורי כבר קיים במערכת.';
            }
        }

        if ( ! empty( $expiry ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expiry ) ) {
            $errors[] = 'תאריך תפוגה לא תקין.';
        }

        if ( $access_url !== '' && ! preg_match( '/^https?:\/\//i', $access_url ) ) {
            $access_url = 'https://' . ltrim( $access_url );
        }
        if ( $access_url !== '' && ! filter_var( $access_url, FILTER_VALIDATE_URL ) ) {
            $errors[] = 'URL לגישה מהירה אינו תקין.';
        }

        // Box type (vendor/model select). If one is set, both required.
        $box_type_id = null;
        if ( $vendor !== '' || $model !== '' ) {
            if ( $vendor === '' || $model === '' ) {
                $errors[] = 'יש לבחור גם יצרן וגם דגם.';
            } else {
                $existing = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$types_table} WHERE vendor=%s AND model=%s",
                    $vendor, $model
                ) );
                if ( $existing ) {
                    $box_type_id = (int) $existing;
                } else {
                    $wpdb->insert(
                        $types_table,
                        array(
                            'vendor'     => $vendor,
                            'model'      => $model,
                            'created_at' => current_time( 'mysql' ),
                            'updated_at' => current_time( 'mysql' ),
                        ),
                        array( '%s', '%s', '%s', '%s' )
                    );
                    $box_type_id = (int) $wpdb->insert_id;
                }
            }
        }

        // Temp notice rules
        if ( ! $temp_enabled ) {
            $temp_notice = '';
        }

        // If renewed (expiry increased), auto clear temp notice
        if ( $id > 0 && $expiry !== '' ) {
            $prev = $wpdb->get_var( $wpdb->prepare( "SELECT expiry_date FROM {$fw_table} WHERE id=%d", $id ) );
            if ( $prev && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $prev ) ) {
                if ( strtotime( $expiry ) > strtotime( (string) $prev ) ) {
                    $temp_enabled = 0;
                    $temp_notice  = '';
                }
            }
        }

        if ( ! empty( $errors ) ) {
            set_transient( 'expman_firewalls_errors', $errors, 90 );
            return;
        }

        $data = array(
            'customer_id'          => $customer_id > 0 ? $customer_id : null,
            'customer_number'      => $customer_number !== '' ? $customer_number : null,
            'customer_name'        => $customer_name !== '' ? $customer_name : null,
            'branch'               => $branch !== '' ? $branch : null,
            'serial_number'        => $serial,
            'is_managed'           => $is_managed ? 1 : 0,
            'track_only'           => $track_only ? 1 : 0,
            'box_type_id'          => $box_type_id,
            'expiry_date'          => $expiry !== '' ? $expiry : null,
            'access_url'           => $access_url !== '' ? $access_url : null,
            'notes'                => $notes,
            'temp_notice_enabled'  => $temp_enabled,
            'temp_notice'          => $temp_notice,
            'updated_at'           => current_time( 'mysql' ),
        );

        
            
            // Backward compatibility: if schema migration didn't apply, don't fail the save.
            if ( ! $this->column_exists( $wpdb->prefix . self::TABLE_FIREWALLS, 'customer_number' ) ) { unset( $data['customer_number'] ); }
            if ( ! $this->column_exists( $wpdb->prefix . self::TABLE_FIREWALLS, 'customer_name' ) )   { unset( $data['customer_name'] ); }
// Backward compatibility: if migration didn't run for some reason, don't fail the save.
            if ( ! $this->fw_column_exists( 'customer_number' ) ) { unset( $data['customer_number'] ); }
            if ( ! $this->fw_column_exists( 'customer_name' ) )   { unset( $data['customer_name'] ); }
if ( $id > 0 ) {
            $ok = $wpdb->update( $fw_table, $data, array( 'id' => $id ) );
            if ( $ok === false ) {
                set_transient( 'expman_firewalls_errors', array( 'שמירה נכשלה: ' . $wpdb->last_error ), 90 );
                $this->log_firewall_event( $id, 'update', 'שמירה נכשלה', array( 'error' => $wpdb->last_error ), 'error' );
            } else {
                $this->log_firewall_event( $id, 'update', 'עודכן רישום חומת אש' );
            }
        } else {
            $data['created_at'] = current_time( 'mysql' );
            $ok = $wpdb->insert( $fw_table, $data );
            if ( $ok === false ) {
                set_transient( 'expman_firewalls_errors', array( 'שמירה נכשלה: ' . $wpdb->last_error ), 90 );
                $this->log_firewall_event( null, 'create', 'שמירה נכשלה', array( 'error' => $wpdb->last_error ), 'error' );
            } else {
                $new_id = $wpdb->insert_id;
                $this->log_firewall_event( $new_id, 'create', 'נוסף רישום חומת אש' );
            }
        }
    }

    private function action_save_forticloud_settings() {
        $api_id     = sanitize_text_field( $_POST['forticloud_api_id'] ?? '' );
        $client_id  = sanitize_text_field( $_POST['forticloud_client_id'] ?? '' );

        $base_url_in = isset( $_POST['forticloud_base_url'] ) ? esc_url_raw( wp_unslash( $_POST['forticloud_base_url'] ) ) : '';
        $secret_new  = isset( $_POST['forticloud_api_secret'] ) ? trim( (string) wp_unslash( $_POST['forticloud_api_secret'] ) ) : '';

        $forti_settings = $this->get_forticloud_settings();
        $forti_settings['api_id']    = $api_id;
        $forti_settings['client_id'] = $client_id !== '' ? $client_id : 'assetmanagement';

        // Normalize base_url: keep only scheme://host and, if user pasted an endpoint path, let it override endpoints (runtime)
        $tmp_endpoints = $this->get_forticloud_endpoints();
        $base_url_norm = $this->normalize_base_url_and_endpoints( $base_url_in, $tmp_endpoints );
        $forti_settings['base_url']  = $base_url_norm !== '' ? $base_url_norm : $base_url_in;

        if ( $secret_new !== '' ) {
            $forti_settings['api_secret'] = $this->encrypt_secret( $secret_new );
        }

        $this->update_forticloud_settings( $forti_settings );
        $this->add_notice( 'הגדרות FortiCloud נשמרו.' );
        $this->log_firewall_event( null, 'forticloud_settings', 'הגדרות FortiCloud נשמרו', array(
            'base_url'   => $forti_settings['base_url'] ?? '',
            'client_id'  => $forti_settings['client_id'] ?? '',
        ), 'info' );
    }


    private function action_sync_forticloud_assets() {
        $settings  = $this->get_forticloud_settings();
        $api_id    = $settings['api_id'] ?? '';
        $client_id = $settings['client_id'] ?? 'assetmanagement';
        $base_url  = $settings['base_url'] ?? '';
        $secret    = $this->decrypt_secret( $settings['api_secret'] ?? array() );

        $request_id = $this->new_request_id();
        $this->log_firewall_event( null, 'forticloud_sync', 'התחלת סנכרון נכסים', array(
            'request_id' => $request_id,
        ), 'info' );

        if ( $base_url === '' ) {
            $base_url = 'https://support.fortinet.com';
        }

        $endpoints = $this->get_forticloud_endpoints();

        // Normalize base_url (strip pasted path) and allow pasted endpoint path to override assets endpoint.
        $base_url = $this->normalize_base_url_and_endpoints( $base_url, $endpoints );

        $token_ep  = $endpoints['token'] ?? '';
        $assets_ep = $endpoints['assets'] ?? '';

        if ( $assets_ep === '' ) {
            $this->add_notice( 'חסר endpoint לנכסים (assets).', 'error' );
            $this->log_firewall_event( null, 'forticloud_sync', 'חסר endpoint לנכסים (assets)', array(
                'request_id' => $request_id,
                'endpoints'  => $endpoints,
            ), 'error' );
            return;
        }

        if ( $api_id === '' || $client_id === '' || $secret === '' ) {
            $this->add_notice( 'חסרים פרטי התחברות ל-FortiCloud/IAM (API Key + Password + Client ID).', 'error' );
            $this->log_firewall_event( null, 'forticloud_sync', 'חסרים פרטי התחברות', array(
                'request_id' => $request_id,
            ), 'error' );
            return;
        }

        if ( $token_ep === '' ) {
            $this->add_notice( 'חסר endpoint לטוקן. ברירת מחדל היא customerapiauth.fortinet.com. בדוק filters/endpoints.', 'error' );
            $this->log_firewall_event( null, 'forticloud_sync', 'חסר endpoint לטוקן', array(
                'request_id' => $request_id,
                'endpoints'  => $endpoints,
            ), 'error' );
            return;
        }

        // Token URL can be absolute.
        $token_url = ( preg_match( '#^https?://#i', $token_ep ) ) ? $token_ep : ( rtrim( $base_url, '/' ) . $token_ep );

        $token_payload = array(
            'username'   => $api_id,
            'password'   => $secret,
            'client_id'  => $client_id,
            'grant_type' => 'password',
        );
        $token_payload = apply_filters( 'expman_forticloud_token_request_body', $token_payload, $settings );

        $token_response = wp_remote_post( $token_url, array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept'        => 'application/json',
            ),
            'body'    => wp_json_encode( $token_payload ),
        ) );

        $this->log_firewall_event( null, 'forticloud_sync', 'תגובת טוקן (debug)', array(
            'request_id' => $request_id,
            'debug'      => $this->http_debug_context( $token_url, $token_response ),
        ), is_wp_error( $token_response ) ? 'error' : 'info' );

        if ( is_wp_error( $token_response ) ) {
            $this->add_notice( 'שגיאה בקבלת טוקן: ' . $token_response->get_error_message(), 'error' );
            return;
        }

        $token_raw  = (string) wp_remote_retrieve_body( $token_response );
        $token_data = json_decode( $token_raw, true );

        if ( ! is_array( $token_data ) ) {
            $this->log_firewall_event( null, 'forticloud_sync', 'טוקן: JSON לא תקין/ריק', array(
                'request_id'        => $request_id,
                'http_code'         => wp_remote_retrieve_response_code( $token_response ),
                'json_error'        => function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : json_last_error(),
                'token_raw_preview' => mb_substr( $token_raw, 0, 2000 ),
            ), 'error' );
            $this->add_notice( 'שגיאה בפענוח תגובת הטוקן (לא JSON / ריק). ראה לוגים.', 'error' );
            return;
        }

        $access_token = $token_data['access_token'] ?? '';
        if ( $access_token === '' ) {
            $this->log_firewall_event( null, 'forticloud_sync', 'לא התקבל access token', array(
                'request_id'        => $request_id,
                'http_code'         => wp_remote_retrieve_response_code( $token_response ),
                'token_keys'        => array_keys( $token_data ),
                'token_raw_preview' => mb_substr( $token_raw, 0, 2000 ),
            ), 'error' );
            $this->add_notice( 'לא התקבל access token מהשרת. ראה לוגים.', 'error' );
            return;
        }

        // Assets request
        $assets_url = rtrim( $base_url, '/' ) . $assets_ep;

        // Some Fortinet gateways accept token also as query param; keep it optional but do not log it.
        $assets_url_with_qs = add_query_arg(
            array(
                'access_token' => $access_token,
                'client_id'    => $client_id,
            ),
            $assets_url
        );

        $headers = array(
            'Accept'        => 'application/json',
            'Authorization' => 'Bearer ' . $access_token,
            'X-API-Key'     => $api_id,
            'X-Client-ID'   => $client_id,
        );

        $assets_response = wp_remote_get( $assets_url_with_qs, array(
            'timeout' => 45,
            'headers' => $headers,
        ) );

        // Redact token from URL in logs.
        $assets_log_url = remove_query_arg( 'access_token', $assets_url_with_qs );

        $this->log_firewall_event( null, 'forticloud_sync', 'תגובת נכסים (debug)', array(
            'request_id' => $request_id,
            'debug'      => $this->http_debug_context( $assets_log_url, $assets_response ),
        ), is_wp_error( $assets_response ) ? 'error' : 'info' );

        if ( is_wp_error( $assets_response ) ) {
            $this->add_notice( 'שגיאה בשליפת נכסים: ' . $assets_response->get_error_message(), 'error' );
            return;
        }

        $assets_raw = (string) wp_remote_retrieve_body( $assets_response );
        $assets_payload = json_decode( $assets_raw, true );

        if ( ! is_array( $assets_payload ) ) {
            $this->add_notice( 'תגובה לא צפויה (לא JSON). ראה לוגים.', 'error' );
            $this->log_firewall_event( null, 'forticloud_sync', 'תגובה לא JSON', array(
                'request_id' => $request_id,
                'http_code'  => wp_remote_retrieve_response_code( $assets_response ),
                'raw_preview'=> mb_substr( $assets_raw, 0, 2000 ),
            ), 'error' );
            return;
        }

        $assets = $this->normalize_assets_payload( $assets_payload );

        if ( empty( $assets ) ) {
            $this->add_notice( 'לא נמצאו נכסים לעדכון (payload ריק/מבנה שונה). ראה לוגים.', 'warning' );
            $this->log_firewall_event( null, 'forticloud_sync', 'לא נמצאו נכסים לעדכון', array(
                'request_id'    => $request_id,
                'payload_keys'  => array_keys( $assets_payload ),
            ), 'warning' );
            return;
        }

        $saved = $this->upsert_forticloud_assets( $assets, $request_id );
        $this->add_notice( 'סנכרון הושלם. עודכנו ' . intval( $saved ) . ' נכסים.' );
        $this->log_firewall_event( null, 'forticloud_sync', 'סנכרון הושלם', array(
            'request_id' => $request_id,
            'count'      => intval( $saved ),
        ), 'info' );
    }


    private function action_map_forticloud_asset() {
        global $wpdb;
        $assets_table = $wpdb->prefix . self::TABLE_FORTICLOUD_ASSETS;
        $fw_table = $wpdb->prefix . self::TABLE_FIREWALLS;
        $types_table = $wpdb->prefix . self::TABLE_TYPES;
        $cust_table = $wpdb->prefix . 'dc_customers';

        $asset_id = intval( $_POST['asset_id'] ?? 0 );
        $customer_id = intval( $_POST['customer_id'] ?? 0 );

        if ( $asset_id <= 0 || $customer_id <= 0 ) {
            $this->add_notice( 'יש לבחור לקוח לשיוך.', 'error' );
            return;
        }

        $asset = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$assets_table} WHERE id=%d", $asset_id ) );
        if ( ! $asset ) {
            $this->add_notice( 'נכס לא נמצא.', 'error' );
            return;
        }

        $customer = $wpdb->get_row( $wpdb->prepare( "SELECT id, customer_number, customer_name FROM {$cust_table} WHERE id=%d AND is_deleted=0", $customer_id ) );
        if ( ! $customer ) {
            $this->add_notice( 'לקוח לא נמצא.', 'error' );
            return;
        }

        $wpdb->update(
            $assets_table,
            array(
                'customer_id'             => $customer->id,
                'customer_number_snapshot'=> $customer->customer_number,
                'customer_name_snapshot'  => $customer->customer_name,
                'mapped_at'               => current_time( 'mysql' ),
                'updated_at'              => current_time( 'mysql' ),
            ),
            array( 'id' => $asset_id ),
            array( '%d', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );

        $vendor = sanitize_text_field( $asset->category_name ?? '' );
        $model  = sanitize_text_field( $asset->model_name ?? '' );
        $box_type_id = null;
        if ( $vendor !== '' && $model !== '' ) {
            $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$types_table} WHERE vendor=%s AND model=%s", $vendor, $model ) );
            if ( $existing ) {
                $box_type_id = intval( $existing );
            } else {
                $wpdb->insert(
                    $types_table,
                    array(
                        'vendor'     => $vendor,
                        'model'      => $model,
                        'created_at' => current_time( 'mysql' ),
                        'updated_at' => current_time( 'mysql' ),
                    ),
                    array( '%s', '%s', '%s', '%s' )
                );
                $box_type_id = intval( $wpdb->insert_id );
            }
        }

        $expiry_date = '';
        if ( ! empty( $asset->expiration_date ) ) {
            $expiry_date = date( 'Y-m-d', strtotime( $asset->expiration_date ) );
        }

        $existing_fw = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$fw_table} WHERE serial_number=%s AND deleted_at IS NULL LIMIT 1", $asset->serial_number ) );
        $fw_data = array(
            'customer_id'     => $customer->id,
            'customer_number' => $customer->customer_number,
            'customer_name'   => $customer->customer_name,
            'serial_number'   => $asset->serial_number,
            'is_managed'      => 1,
            'track_only'      => 0,
            'box_type_id'     => $box_type_id,
            'expiry_date'     => $expiry_date !== '' ? $expiry_date : null,
            'updated_at'      => current_time( 'mysql' ),
        );

        if ( $existing_fw ) {
            $wpdb->update( $fw_table, $fw_data, array( 'id' => intval( $existing_fw ) ) );
            $this->log_firewall_event( intval( $existing_fw ), 'map_update', 'עודכן רישום חומת אש משיוך FortiCloud', array( 'serial' => $asset->serial_number ) );
        } else {
            $fw_data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $fw_table, $fw_data );
            $this->log_firewall_event( $wpdb->insert_id, 'map_create', 'נוסף רישום חומת אש משיוך FortiCloud', array( 'serial' => $asset->serial_number ) );
        }

        $this->update_forticloud_description( $asset, $customer );

        $redirect_url = add_query_arg(
            array(
                'expman_msg' => rawurlencode( 'שיוך הושלם' ),
                'highlight'  => rawurlencode( $asset->serial_number ),
                'tab'        => 'main',
            ),
            remove_query_arg( array( 'expman_msg', 'highlight', 'tab' ) )
        );

        wp_safe_redirect( $redirect_url );
        exit;
    }

    private function action_trash_firewall() {
        global $wpdb;
        $fw_table = $wpdb->prefix . self::TABLE_FIREWALLS;
        $id = isset( $_POST['firewall_id'] ) ? intval( $_POST['firewall_id'] ) : 0;
        if ( $id <= 0 ) { return; }

        $wpdb->update(
            $fw_table,
            array( 'deleted_at' => current_time( 'mysql' ) ),
            array( 'id' => $id ),
            array( '%s' ),
            array( '%d' )
        );
        $this->log_firewall_event( $id, 'delete', 'רישום הועבר לסל המחזור' );
    }

    private function action_restore_firewall() {
        global $wpdb;
        $fw_table = $wpdb->prefix . self::TABLE_FIREWALLS;
        $id = isset( $_POST['firewall_id'] ) ? intval( $_POST['firewall_id'] ) : 0;
        if ( $id <= 0 ) { return; }

        $wpdb->update(
            $fw_table,
            array( 'deleted_at' => null ),
            array( 'id' => $id ),
            array( '%s' ),
            array( '%d' )
        );
        $this->log_firewall_event( $id, 'restore', 'רישום שוחזר מסל המחזור' );
    }

    private function action_export_csv() {
        global $wpdb;
        $fw_table    = $wpdb->prefix . self::TABLE_FIREWALLS;
        $types_table = $wpdb->prefix . self::TABLE_TYPES;
        $cust_table  = $wpdb->prefix . 'dc_customers';

        $rows = $wpdb->get_results( "
            SELECT fw.*,
                   c.customer_number AS customer_number,
                   c.customer_name AS customer_name,
                   bt.vendor, bt.model
            FROM {$fw_table} fw
            LEFT JOIN {$cust_table} c ON c.id = fw.customer_id
            LEFT JOIN {$types_table} bt ON bt.id = fw.box_type_id
            WHERE 1=1
            ORDER BY fw.id ASC
        " );

        $filename = 'firewalls-template-' . date( 'Ymd-His' ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        $out = fopen( 'php://output', 'w' );

        fputcsv( $out, array(
            'id',
            'customer_number',
            'customer_name',
            'branch',
            'serial_number',
            'is_managed',
            'track_only',
            'vendor',
            'model',
            'expiry_date',
            'access_url',
            'notes',
            'temp_notice_enabled',
            'temp_notice',
            'deleted_at',
        ) );

        foreach ( (array) $rows as $r ) {
            fputcsv( $out, array(
                $r->id,
                $r->customer_number,
                $r->customer_name,
                $r->branch,
                $r->serial_number,
                $r->is_managed,
                $r->track_only,
                $r->vendor,
                $r->model,
                $r->expiry_date,
                $r->access_url,
                $r->notes,
                $r->temp_notice_enabled,
                $r->temp_notice,
                $r->deleted_at,
            ) );
        }

        fclose( $out );
        exit;
    }

    private function action_import_csv() {
        if ( empty( $_FILES['firewalls_file']['tmp_name'] ) ) {
            set_transient( 'expman_firewalls_errors', array( 'לא נבחר קובץ ליבוא.' ), 90 );
            return;
        }

        $tmp = $_FILES['firewalls_file']['tmp_name'];
        $h = fopen( $tmp, 'r' );
        if ( ! $h ) {
            set_transient( 'expman_firewalls_errors', array( 'לא ניתן לקרוא את הקובץ.' ), 90 );
            return;
        }

        global $wpdb;
        $fw_table    = $wpdb->prefix . self::TABLE_FIREWALLS;
        $types_table = $wpdb->prefix . self::TABLE_TYPES;
        $cust_table  = $wpdb->prefix . 'dc_customers';

        $row_num = 0;
        $imported = 0;
        $updated  = 0;
        $skipped  = 0;
        $errors   = array();

        while ( ( $data = fgetcsv( $h, 0, ',' ) ) !== false ) {
            $row_num++;

            if ( $row_num === 1 && isset( $data[0] ) && strtolower( trim( (string) $data[0] ) ) === 'id' ) {
                continue;
            }

            $col = array_pad( $data, 15, '' );

            $id              = intval( trim( $col[0] ) );
            $customer_number = trim( (string) $col[1] );
            $branch          = trim( (string) $col[3] );
            $serial          = trim( (string) $col[4] );
            $is_managed      = trim( (string) $col[5] ) === '0' ? 0 : 1;
            $track_only      = trim( (string) $col[6] ) === '1' ? 1 : 0;
            $vendor          = trim( (string) $col[7] );
            $model           = trim( (string) $col[8] );
            $expiry          = trim( (string) $col[9] );
            $access_url      = trim( (string) $col[10] );
            $notes           = (string) $col[11];
            $tmp_enabled     = trim( (string) $col[12] ) === '1' ? 1 : 0;
            $tmp_notice      = (string) $col[13];

            if ( $serial === '' ) {
                $skipped++;
                $errors[] = "שורה {$row_num}: חסר מספר סידורי.";
                continue;
            }

            // customer_id by customer_number (optional)
            $customer_id = null;
            if ( $customer_number !== '' ) {
                $customer_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$cust_table} WHERE is_deleted=0 AND customer_number=%s LIMIT 1",
                    $customer_number
                ) );
                $customer_id = $customer_id ? intval( $customer_id ) : null;
            }

            // vendor/model -> box_type_id (optional)
            $box_type_id = null;
            if ( $vendor !== '' && $model !== '' ) {
                $box_type_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$types_table} WHERE vendor=%s AND model=%s LIMIT 1",
                    $vendor, $model
                ) );
                if ( ! $box_type_id ) {
                    $wpdb->insert(
                        $types_table,
                        array(
                            'vendor'     => $vendor,
                            'model'      => $model,
                            'created_at' => current_time( 'mysql' ),
                            'updated_at' => current_time( 'mysql' ),
                        )
                    );
                    $box_type_id = intval( $wpdb->insert_id );
                } else {
                    $box_type_id = intval( $box_type_id );
                }
            }

            if ( $access_url !== '' && ! preg_match( '/^https?:\/\//i', $access_url ) ) {
                $access_url = 'https://' . ltrim( $access_url );
            }

            // Unique serial check (excluding trashed)
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$fw_table} WHERE serial_number=%s AND deleted_at IS NULL AND id != %d LIMIT 1",
                $serial, $id
            ) );
            if ( $exists ) {
                $skipped++;
                $errors[] = "שורה {$row_num}: מספר סידורי כבר קיים ({$serial}).";
                continue;
            }

            $payload = array(
                'customer_id'         => $customer_id,
                'branch'              => $branch !== '' ? $branch : null,
                'serial_number'       => $serial,
                'is_managed'          => $is_managed,
                'track_only'          => $track_only,
                'box_type_id'         => $box_type_id,
                'expiry_date'         => $expiry !== '' ? $expiry : null,
                'access_url'          => $access_url !== '' ? $access_url : null,
                'notes'               => $notes,
                'temp_notice_enabled' => $tmp_enabled,
                'temp_notice'         => $tmp_enabled ? $tmp_notice : '',
                'updated_at'          => current_time( 'mysql' ),
            );

            if ( $id > 0 ) {
                $ok = $wpdb->update( $fw_table, $payload, array( 'id' => $id ) );
                if ( $ok === false ) {
                    $skipped++;
                    $errors[] = "שורה {$row_num}: עדכון נכשל: " . $wpdb->last_error;
                    $this->log_firewall_event( $id, 'import_update', 'עדכון נכשל בייבוא', array( 'error' => $wpdb->last_error ), 'error' );
                    continue;
                }
                $updated++;
                $this->log_firewall_event( $id, 'import_update', 'עודכן רישום בייבוא' );
            } else {
                $payload['created_at'] = current_time( 'mysql' );
                $ok = $wpdb->insert( $fw_table, $payload );
                if ( $ok === false ) {
                    $skipped++;
                    $errors[] = "שורה {$row_num}: הוספה נכשלה: " . $wpdb->last_error;
                    $this->log_firewall_event( null, 'import_create', 'הוספה נכשלה בייבוא', array( 'error' => $wpdb->last_error ), 'error' );
                    continue;
                }
                $imported++;
                $this->log_firewall_event( $wpdb->insert_id, 'import_create', 'נוסף רישום בייבוא' );
            }
        }

        fclose( $h );
        $summary = array( "ייבוא הסתיים. נוספו {$imported}, עודכנו {$updated}, דולגו {$skipped}." );
        set_transient( 'expman_firewalls_errors', array_merge( $summary, $errors ), 120 );
    }

    private function normalize_assets_payload( $payload ) {
        if ( empty( $payload ) ) {
            return array();
        }

        $candidates = array();
        foreach ( array( 'data', 'items', 'assets', 'products' ) as $key ) {
            if ( isset( $payload[ $key ] ) && is_array( $payload[ $key ] ) ) {
                $candidates = $payload[ $key ];
                break;
            }
        }

        if ( empty( $candidates ) && is_array( $payload ) ) {
            $candidates = $payload;
        }

        return is_array( $candidates ) ? $candidates : array();
    }

    private function get_asset_value( $asset, $keys ) {
        foreach ( (array) $keys as $key ) {
            if ( isset( $asset[ $key ] ) && $asset[ $key ] !== '' ) {
                return $asset[ $key ];
            }
        }
        return null;
    }

    private function upsert_forticloud_assets( $assets, $request_id = '' ) {
        global $wpdb;
        $assets_table = $wpdb->prefix . self::TABLE_FORTICLOUD_ASSETS;
        $saved = 0;

        foreach ( (array) $assets as $asset ) {
            if ( ! is_array( $asset ) ) {
                continue;
            }

            $serial = sanitize_text_field( $this->get_asset_value( $asset, array( 'serial_number', 'serialNumber', 'serial', 'sn' ) ) );
            if ( $serial === '' ) {
                continue;
            }

            $forticloud_id = sanitize_text_field( $this->get_asset_value( $asset, array( 'id', 'asset_id', 'product_id' ) ) );
            $category = sanitize_text_field( $this->get_asset_value( $asset, array( 'category_name', 'categoryName', 'category' ) ) );
            $model = sanitize_text_field( $this->get_asset_value( $asset, array( 'model_name', 'modelName', 'model' ) ) );
            $description = sanitize_textarea_field( $this->get_asset_value( $asset, array( 'description', 'desc' ) ) );
            $folder = sanitize_text_field( $this->get_asset_value( $asset, array( 'folder_id', 'folderId', 'folder' ) ) );
            $groups = $this->get_asset_value( $asset, array( 'asset_groups', 'assetGroups', 'groups' ) );
            $groups_value = is_array( $groups ) ? wp_json_encode( $groups ) : sanitize_text_field( (string) $groups );

            $registration = $this->get_asset_value( $asset, array( 'registration_date', 'registrationDate', 'registered_at' ) );
            $ship = $this->get_asset_value( $asset, array( 'ship_date', 'shipDate', 'ship_at' ) );
            $expiration = $this->get_asset_value( $asset, array( 'expiration_date', 'expirationDate', 'expiry_date', 'expiryDate' ) );

            $registration_date = $registration ? date( 'Y-m-d', strtotime( (string) $registration ) ) : null;
            $ship_date = $ship ? date( 'Y-m-d', strtotime( (string) $ship ) ) : null;
            $expiration_date = $expiration ? date( 'Y-m-d', strtotime( (string) $expiration ) ) : null;

            $data = array(
                'forticloud_id'     => $forticloud_id !== '' ? $forticloud_id : null,
                'serial_number'     => $serial,
                'category_name'     => $category !== '' ? $category : null,
                'model_name'        => $model !== '' ? $model : null,
                'registration_date' => $registration_date,
                'ship_date'         => $ship_date,
                'expiration_date'   => $expiration_date,
                'description'       => $description !== '' ? $description : null,
                'folder_id'         => $folder !== '' ? $folder : null,
                'asset_groups'      => $groups_value !== '' ? $groups_value : null,
                'raw_json'          => wp_json_encode( $asset ),
                'updated_at'        => current_time( 'mysql' ),
            );

            $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$assets_table} WHERE serial_number=%s LIMIT 1", $serial ), ARRAY_A );

            if ( $existing ) {
                $ok = $wpdb->update( $assets_table, $data, array( 'id' => intval( $existing['id'] ) ) );
                if ( $ok !== false ) {
                    $changed = array();
                    foreach ( array( 'forticloud_id','category_name','model_name','registration_date','ship_date','expiration_date','description','folder_id','asset_groups' ) as $k ) {
                        $before = isset( $existing[ $k ] ) ? (string) $existing[ $k ] : '';
                        $after  = isset( $data[ $k ] ) ? (string) $data[ $k ] : '';
                        if ( $before !== $after ) {
                            $changed[ $k ] = array( 'before' => $before, 'after' => $after );
                        }
                    }

                    if ( ! empty( $changed ) ) {
                        $this->log_firewall_event( null, 'forticloud_asset_update', 'עודכן נכס FortiCloud', array(
                            'request_id' => $request_id,
                            'serial'     => $serial,
                            'asset_id'   => $existing['id'],
                            'changed'    => $changed,
                        ), 'info' );
                    }
                    $saved++;
                } else {
                    $this->log_firewall_event( null, 'forticloud_asset_update', 'עדכון נכס נכשל', array(
                        'request_id' => $request_id,
                        'serial'     => $serial,
                        'error'      => $wpdb->last_error,
                    ), 'error' );
                }
            } else {
                $data['created_at'] = current_time( 'mysql' );
                $ok = $wpdb->insert( $assets_table, $data );
                if ( $ok !== false ) {
                    $this->log_firewall_event( null, 'forticloud_asset_create', 'נוסף נכס FortiCloud', array(
                        'request_id' => $request_id,
                        'serial'     => $serial,
                        'asset_id'   => intval( $wpdb->insert_id ),
                    ), 'info' );
                    $saved++;
                } else {
                    $this->log_firewall_event( null, 'forticloud_asset_create', 'הוספת נכס נכשלה', array(
                        'request_id' => $request_id,
                        'serial'     => $serial,
                        'error'      => $wpdb->last_error,
                    ), 'error' );
                }
            }
        }

        return $saved;
    }


    private function update_forticloud_description( $asset, $customer ) {
        $settings = $this->get_forticloud_settings();
        $api_id = $settings['api_id'] ?? '';
        $client_id = $settings['client_id'] ?? 'assetmanagement';
        $base_url = $settings['base_url'] ?? '';
        $secret = $this->decrypt_secret( $settings['api_secret'] ?? array() );

        if ( $api_id === '' || $client_id === '' || $secret === '' ) {
            return;
        }

        if ( $base_url === '' ) {
            $base_url = 'https://api.forticloud.com';
        }

        $endpoints = $this->get_forticloud_endpoints();
        $token_url = rtrim( $base_url, '/' ) . ( $endpoints['token'] ?? '' );

        $token_body = array(
            'grant_type'    => 'client_credentials',
            'client_id'     => $client_id,
            'client_secret' => $secret,
            'api_id'        => $api_id,
            'scope'         => 'assetmanagement',
        );
        $token_body = apply_filters( 'expman_forticloud_token_request_body', $token_body, $settings );

        $token_response = wp_remote_post( $token_url, array(
            'timeout' => 20,
            'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
            'body'    => $token_body,
        ) );

        if ( is_wp_error( $token_response ) ) {
            return;
        }

        $token_data = json_decode( wp_remote_retrieve_body( $token_response ), true );
        $access_token = $token_data['access_token'] ?? '';
        if ( $access_token === '' ) {
            return;
        }

        $endpoint = $endpoints['update'] ?? '';
        if ( $endpoint === '' ) {
            return;
        }

        $serial = isset( $asset->serial_number ) ? (string) $asset->serial_number : '';
        $asset_id = isset( $asset->forticloud_id ) ? (string) $asset->forticloud_id : '';
        $endpoint = str_replace( array( '{serial}', '{id}' ), array( rawurlencode( $serial ), rawurlencode( $asset_id ) ), $endpoint );
        $update_url = rtrim( $base_url, '/' ) . $endpoint;

        $description = trim( $customer->customer_number . ' - ' . $customer->customer_name );
        $payload = apply_filters(
            'expman_forticloud_update_payload',
            array( 'description' => $description ),
            $asset,
            $customer
        );

        $update_response = wp_remote_request( $update_url, array(
            'method'  => 'PATCH',
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
        ) );

        if ( is_wp_error( $update_response ) ) {
            $this->log_firewall_event( null, 'forticloud_update', 'שגיאה בעדכון תיאור', array( 'error' => $update_response->get_error_message() ), 'error' );
            return;
        }

        $status = wp_remote_retrieve_response_code( $update_response );
        if ( $status >= 400 ) {
            $this->log_firewall_event(
                null,
                'forticloud_update',
                'שגיאה בעדכון תיאור',
                array(
                    'status' => $status,
                    'body'   => wp_remote_retrieve_body( $update_response ),
                ),
                'error'
            );
        }
    }

    /* ---------------- Data ---------------- */

    private function get_box_types() {
        global $wpdb;
        $types_table = $wpdb->prefix . self::TABLE_TYPES;
        return $wpdb->get_results( "SELECT id, vendor, model FROM {$types_table} ORDER BY vendor ASC, model ASC" );
    }

    private function get_firewalls_rows( $filters, $orderby, $order, $include_trashed = false, $track_only = 0 ) {
        global $wpdb;

        $fw_table    = $wpdb->prefix . self::TABLE_FIREWALLS;
        $types_table = $wpdb->prefix . self::TABLE_TYPES;
        $cust_table  = $wpdb->prefix . 'dc_customers';

        $allowed_orderby = array(
            'id' => 'fw.id',
            'customer_number' => 'c.customer_number',
            'customer_name' => 'c.customer_name',
            'branch' => 'fw.branch',
            'serial_number' => 'fw.serial_number',
            'is_managed' => 'fw.is_managed',
            'track_only' => 'fw.track_only',
            'expiry_date' => 'fw.expiry_date',
            'days_to_renew' => 'days_to_renew',
            'vendor' => 'bt.vendor',
            'model' => 'bt.model',
        );

        $orderby_sql = isset( $allowed_orderby[ $orderby ] ) ? $allowed_orderby[ $orderby ] : 'fw.expiry_date';
        $order_sql   = strtoupper( $order ) === 'DESC' ? 'DESC' : 'ASC';

        $where = "WHERE 1=1";
        $params = array();

        if ( ! $include_trashed ) {
            $where .= " AND fw.deleted_at IS NULL";
        } else {
            $where .= " AND fw.deleted_at IS NOT NULL";
        }

        $where .= " AND fw.track_only = %d";
        $params[] = intval( $track_only ) ? 1 : 0;

        $like_map = array(
            'customer_number' => 'c.customer_number',
            'customer_name'   => 'c.customer_name',
            'branch'          => 'fw.branch',
            'serial_number'   => 'fw.serial_number',
                    );

        foreach ( $like_map as $k => $col ) {
            if ( ! empty( $filters[ $k ] ) ) {
                $where .= " AND {$col} LIKE %s";
                $params[] = '%' . $wpdb->esc_like( (string) $filters[ $k ] ) . '%';
            }
        }

        // vendor / model multi-select support
        if ( ! empty( $filters['vendor'] ) ) {
            $vals = array_filter( array_map( 'trim', explode( ',', (string) $filters['vendor'] ) ) );
            if ( count( $vals ) > 1 ) {
                $in = implode( ',', array_fill( 0, count( $vals ), '%s' ) );
                $where .= " AND bt.vendor IN ($in)";
                foreach ( $vals as $v ) { $params[] = $v; }
            } else {
                $where .= " AND bt.vendor LIKE %s";
                $params[] = '%' . $wpdb->esc_like( (string) $filters['vendor'] ) . '%';
            }
        }

        if ( ! empty( $filters['model'] ) ) {
            $vals = array_filter( array_map( 'trim', explode( ',', (string) $filters['model'] ) ) );
            if ( count( $vals ) > 1 ) {
                $in = implode( ',', array_fill( 0, count( $vals ), '%s' ) );
                $where .= " AND bt.model IN ($in)";
                foreach ( $vals as $v ) { $params[] = $v; }
            } else {
                $where .= " AND bt.model LIKE %s";
                $params[] = '%' . $wpdb->esc_like( (string) $filters['model'] ) . '%';
            }
        }

        if ( $filters['is_managed'] !== '' && $filters['is_managed'] !== null ) {
            $where .= " AND fw.is_managed = %d";
            $params[] = intval( $filters['is_managed'] ) ? 1 : 0;
        }

        if ( ! empty( $filters['expiry_date'] ) ) {
            $where .= " AND fw.expiry_date LIKE %s";
            $params[] = '%' . $wpdb->esc_like( (string) $filters['expiry_date'] ) . '%';
        }

        $sql = "
            SELECT
                fw.id,
                fw.customer_id,
                c.customer_number AS customer_number,
                c.customer_name   AS customer_name,
                fw.branch,
                fw.serial_number,
                fw.is_managed,
                fw.track_only,
                fw.expiry_date,
                DATEDIFF(fw.expiry_date, CURDATE()) AS days_to_renew,
                bt.vendor,
                bt.model,
                fw.access_url,
                fw.notes,
                fw.temp_notice_enabled,
                fw.temp_notice,
                fw.deleted_at
            FROM {$fw_table} fw
            LEFT JOIN {$cust_table} c ON c.id = fw.customer_id AND c.is_deleted = 0
            LEFT JOIN {$types_table} bt ON bt.id = fw.box_type_id
            {$where}
            ORDER BY {$orderby_sql} {$order_sql}, fw.id DESC
        ";

        if ( ! empty( $params ) ) {
            $sql = $wpdb->prepare( $sql, $params );
        }

        return $wpdb->get_results( $sql );
    }

    /* ---------------- UI ---------------- */

    private function render() {
        $errors = get_transient( 'expman_firewalls_errors' );
        delete_transient( 'expman_firewalls_errors' );

        echo '<style>/* expman-compact-fields */
.expman-filter-row input,.expman-filter-row select{height:20px !important;padding:2px 6px !important;font-size:12px !important;}
.fw-form input,.fw-form select{height:24px !important;padding:3px 6px !important;font-size:13px !important;}
.fw-form textarea{min-height:60px !important;font-size:13px !important;}
.expman-btn{padding:6px 10px !important;font-size:12px !important;}
.expman-btn-clear{background:transparent;border:0;box-shadow:none;padding:0 !important;color:#2271b1;cursor:pointer;}
.expman-btn-clear:hover{text-decoration:underline;}
.expman-highlight{background:#fff7c0 !important;}
</style>';
echo '<style>.expman-frontend.expman-firewalls input,.expman-frontend.expman-firewalls select{height:28px!important;line-height:28px!important;padding:2px 6px!important;font-size:13px!important}.expman-frontend.expman-firewalls textarea{min-height:60px!important;font-size:13px!important;padding:6px!important}.expman-frontend.expman-firewalls .button{padding:4px 10px!important;height:30px!important}</style>';
        echo '<div class="expman-frontend expman-firewalls" style="direction:rtl;">';

        if ( class_exists( 'Expman_Nav' ) ) {
            Expman_Nav::render_public_nav( $this->option_key );
        }

        echo '<h2 style="margin-top:10px;">חומות אש</h2>';

        if ( ! empty( $errors ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html( implode( ' | ', (array) $errors ) ) . '</p></div>';
        }

        if ( ! empty( $_GET['expman_msg'] ) ) {
            $msg = rawurldecode( (string) wp_unslash( $_GET['expman_msg'] ) );
            echo '<div class="notice notice-success"><p>' . esc_html( sanitize_text_field( $msg ) ) . '</p></div>';
        }

        foreach ( $this->notices as $notice ) {
            $type = in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';
            echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $notice['text'] ) . '</p></div>';
        }

        $active = $this->get_active_tab();
        $this->render_internal_tabs( $active );

        echo '<div data-expman-panel="main"' . ( $active === 'main' ? '' : ' style="display:none;"' ) . '>';
        $this->render_main_tab();
        echo '</div>';

        echo '<div data-expman-panel="bulk"' . ( $active === 'bulk' ? '' : ' style="display:none;"' ) . '>';
        $this->render_bulk_tab();
        echo '</div>';

        echo '<div data-expman-panel="settings"' . ( $active === 'settings' ? '' : ' style="display:none;"' ) . '>';
        $this->render_settings_tab();
        echo '</div>';

        echo '<div data-expman-panel="trash"' . ( $active === 'trash' ? '' : ' style="display:none;"' ) . '>';
        $this->render_trash_tab();
        echo '</div>';

        echo '<div data-expman-panel="logs"' . ( $active === 'logs' ? '' : ' style="display:none;"' ) . '>';
        $this->render_logs_tab();
        echo '</div>';

        echo '<script>(function(){document.addEventListener("click",function(e){var t=e.target;if(t && t.matches("[data-expman-cancel-new]")){e.preventDefault();var f=document.querySelector(".expman-fw-form-wrap");if(f){f.style.display="none";}}if(t && t.matches("[data-expman-cancel-edit]")){e.preventDefault();document.querySelectorAll(".expman-inline-edit").forEach(function(r){r.remove();});}});})();</script>';
        echo '<div style="margin-top:14px;font-size:11px;color:#666;">Expiry Manager v ' . esc_html( $this->version ) . '</div>';
        echo '</div>';
    }


    private function render_internal_tabs( $active ) {
        // JS tabs (no page reload)
        $tabs = array(
            'main'     => 'רשימה ראשית',
            'bulk'     => 'עריכה קבוצתית',
            'settings' => 'הגדרות',
            'logs'     => 'לוגים',
            'trash'    => 'סל מחזור',
        );

        echo '<div class="expman-internal-tabs" style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin:10px 0">';
        foreach ( $tabs as $k => $label ) {
            $is = ( $k === $active ) ? '1' : '0';
            $href = add_query_arg( 'tab', $k, remove_query_arg( 'tab' ) );
            echo '<a href="' . esc_url( $href ) . '" class="expman-tab-btn" data-expman-tab="' . esc_attr( $k ) . '" data-active="' . esc_attr( $is ) . '" style="background:#2f5ea8;color:#fff;text-decoration:none;padding:10px 14px;border-radius:6px;display:inline-block;font-weight:700;">' . esc_html( $label ) . '</a>';
        }
        echo '</div>';

        echo '<script>(function(){
          const dataEl = document.currentScript.previousElementSibling;
          function show(k){
            document.querySelectorAll("[data-expman-panel]").forEach(p=>{p.style.display=(p.getAttribute("data-expman-panel")==k)?"block":"none";});
            document.querySelectorAll(".expman-tab-btn").forEach(a=>{a.style.opacity=(a.getAttribute("data-expman-tab")==k)?"1":"0.85";});
            const url=new URL(window.location.href);
            url.searchParams.set("tab",k);
            history.replaceState(null,"",url.toString());
          }
          document.querySelectorAll(".expman-tab-btn").forEach(a=>a.addEventListener("click",function(e){e.preventDefault();show(a.getAttribute("data-expman-tab"));}));
          const params=new URLSearchParams(window.location.search);
          var k=params.get("tab")||"main";
          if(!document.querySelector("[data-expman-panel=\""+k+"\"]")){k="main";}
          show(k);
        })();</script>' . "
";
    }

    private function common_filters_from_get() {
        return array(
            'customer_number' => sanitize_text_field( $_GET['f_customer_number'] ?? '' ),
            'customer_name'   => sanitize_text_field( $_GET['f_customer_name'] ?? '' ),
            'branch'          => sanitize_text_field( $_GET['f_branch'] ?? '' ),
            'serial_number'   => sanitize_text_field( $_GET['f_serial_number'] ?? '' ),
            'is_managed'      => isset( $_GET['f_is_managed'] ) ? sanitize_text_field( $_GET['f_is_managed'] ) : '',
            'vendor'          => sanitize_text_field( $_GET['f_vendor'] ?? '' ),
            'model'           => sanitize_text_field( $_GET['f_model'] ?? '' ),
            'expiry_date'     => sanitize_text_field( $_GET['f_expiry_date'] ?? '' ),
        );
    }

    private function render_main_tab() {
        $filters = $this->common_filters_from_get();
        $orderby = sanitize_key( $_GET['orderby'] ?? 'expiry_date' );
        $order   = sanitize_key( $_GET['order'] ?? 'ASC' );

        $base = remove_query_arg( array( 'expman_msg' ) );
        $clear_url = remove_query_arg( array(
            'f_customer_number','f_customer_name','f_branch','f_serial_number','f_is_managed','f_vendor','f_model','f_expiry_date','orderby','order','highlight'
        ), $base );

        echo '<div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;margin:10px 0;">';
        echo '<button type="button" class="expman-btn" id="expman-add-toggle">הוספת חומת אש</button>';
        echo '</div>';

        // Add form container (hidden by default)
        echo '<div id="expman-add-form-wrap" style="display:none;border:1px solid #e6e6e6;border-radius:10px;padding:12px;margin:10px 0;background:#fff;">';
        $this->render_form( 0 );
        echo '</div>';

        // Main list (track_only = 0)
        echo '<h3>רשימה ראשית</h3>';
        $rows = $this->get_firewalls_rows( $filters, $orderby, $order, false, 0 );
        $this->render_table( $rows, $filters, $orderby, $order, false, $clear_url );

        // Tracking list (track_only = 1)
        echo '<h3 style="margin-top:18px;">לקוחות למעקב (לא שולח התראות)</h3>';
        $rows_track = $this->get_firewalls_rows( $filters, $orderby, $order, false, 1 );
        $this->render_table( $rows_track, $filters, $orderby, $order, false, $clear_url, true );

        // Import/Export at bottom
        echo '<div style="margin-top:18px;border-top:1px solid #eee;padding-top:12px;">';
        echo '<h3>ייבוא / ייצוא (Excel CSV)</h3>';
        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">';
        echo '<form method="post" style="margin:0;">';
        wp_nonce_field( 'expman_firewalls' );
        echo '<input type="hidden" name="expman_action" value="export_csv">';
        echo '<button class="expman-btn secondary" type="submit">ייצוא ל-Excel (CSV)</button>';
        echo '</form>';

        echo '<form method="post" enctype="multipart/form-data" style="margin:0;">';
        wp_nonce_field( 'expman_firewalls' );
        echo '<input type="hidden" name="expman_action" value="import_csv">';
        echo '<input type="file" name="firewalls_file" accept=".csv" required>';
        echo '<button class="expman-btn secondary" type="submit">ייבוא מ-Excel (CSV)</button>';
        echo '</form>';
        echo '</div></div>';

        // JS: toggle add form + inline edit
        echo '<script>(function(){
          const dataEl = document.currentScript.previousElementSibling;';
        echo 'const btn=document.getElementById("expman-add-toggle"); const wrap=document.getElementById("expman-add-form-wrap");';
        echo 'if(btn&&wrap){btn.addEventListener("click",()=>{wrap.style.display=(wrap.style.display==="none"||wrap.style.display==="")?"block":"none"; window.scrollTo({top:wrap.getBoundingClientRect().top+window.scrollY-80,behavior:"smooth"});});}';
        echo "document.addEventListener('click',function(e){var c=e.target.closest('.expman-cancel-inline'); if(!c) return; e.preventDefault(); var tr=c.closest('tr.expman-inline-form'); if(tr) tr.style.display='none';});";
        echo 'document.querySelectorAll(".expman-edit-btn").forEach(function(b){b.addEventListener("click",function(e){e.preventDefault();e.stopPropagation();';
        echo 'const tr=b.closest("tr"); const id=b.getAttribute("data-id"); const target=document.querySelector("tr.expman-inline-form[data-for=\'"+id+"\']");';
        echo 'document.querySelectorAll("tr.expman-inline-form").forEach(x=>{if(x!==target) x.style.display="none";});';
        echo 'if(target){target.style.display=(target.style.display==="none"||target.style.display==="")?"table-row":"none";';
        echo 'if(target.style.display==="table-row"){window.scrollTo({top:target.getBoundingClientRect().top+window.scrollY-120,behavior:"smooth"});} }';
        echo '});});';
        echo '})();</script>';
    }

    private function render_bulk_tab() {
        global $wpdb;
        $assets_table = $wpdb->prefix . self::TABLE_FORTICLOUD_ASSETS;

        $assets = $wpdb->get_results(
            "SELECT * FROM {$assets_table} WHERE customer_id IS NULL ORDER BY updated_at DESC, id DESC"
        );

        echo '<div style="margin-bottom:14px;">';
        echo '<form method="post" style="display:inline-block;margin:0 0 10px 0;">';
        wp_nonce_field( 'expman_firewalls' );
        echo '<input type="hidden" name="expman_action" value="sync_forticloud_assets">';
        echo '<input type="hidden" name="tab" value="bulk">';
        echo '<button type="submit" class="button button-primary">סנכרן נכסים מ-FortiCloud</button>';
        echo '</form>';
        echo '<p style="margin-top:6px;color:#555;">מוצגים נכסים שאינם משויכים. לאחר שיוך ייווצר/יעודכן רישום בטבלת חומות אש.</p>';
        echo '</div>';

        if ( empty( $assets ) ) {
            echo '<div class="notice notice-info"><p>אין נכסים לא משויכים.</p></div>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>Serial</th>';
        echo '<th>קטגוריה</th>';
        echo '<th>דגם</th>';
        echo '<th>תפוגה</th>';
        echo '<th>תיאור</th>';
        echo '<th>שייך ללקוח</th>';
        echo '</tr></thead><tbody>';

        foreach ( $assets as $asset ) {
            echo '<tr>';
            echo '<td>' . esc_html( $asset->serial_number ) . '</td>';
            echo '<td>' . esc_html( $asset->category_name ) . '</td>';
            echo '<td>' . esc_html( $asset->model_name ) . '</td>';
            echo '<td>' . esc_html( $asset->expiration_date ) . '</td>';
            echo '<td>' . esc_html( $asset->description ) . '</td>';
            echo '<td class="expman-customer-cell">';
            echo '<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0;">';
            wp_nonce_field( 'expman_firewalls' );
            echo '<input type="hidden" name="expman_action" value="map_forticloud_asset">';
            echo '<input type="hidden" name="tab" value="bulk">';
            echo '<input type="hidden" name="asset_id" value="' . esc_attr( $asset->id ) . '">';
            echo '<input type="hidden" name="customer_id" class="expman-customer-id" value="">';
            echo '<input type="text" class="expman-customer-search" placeholder="חפש לקוח..." style="min-width:220px;">';
            echo '<div class="expman-customer-results" style="position:relative;"></div>';
            echo '<button type="submit" class="button">שייך לקוח</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        $ajax = esc_url( admin_url( 'admin-ajax.php' ) );
        $nonce = wp_create_nonce( 'expman_customer_search' );
        echo '<script>(function(){
          const ajax="' . esc_js( $ajax ) . '";
          const nonce="' . esc_js( $nonce ) . '";
          function fetchCustomers(query, cb){
            const url=ajax+"?action=expman_customer_search&nonce="+encodeURIComponent(nonce)+"&q="+encodeURIComponent(query);
            fetch(url).then(r=>r.json()).then(d=>cb(d.items||[])).catch(()=>cb([]));
          }
          document.addEventListener("input",function(e){
            if(!e.target.classList.contains("expman-customer-search")) return;
            const input=e.target;
            const cell=input.closest(".expman-customer-cell");
            const results=cell.querySelector(".expman-customer-results");
            const q=input.value.trim();
            results.innerHTML="";
            if(q.length<2){return;}
            fetchCustomers(q,function(items){
              results.innerHTML="";
              const wrap=document.createElement("div");
              wrap.style.position="absolute";
              wrap.style.background="#fff";
              wrap.style.border="1px solid #ddd";
              wrap.style.borderRadius="6px";
              wrap.style.zIndex="999";
              wrap.style.minWidth="220px";
              items.forEach(function(it){
                const btn=document.createElement("button");
                btn.type="button";
                btn.textContent=it.customer_number+" - "+it.customer_name;
                btn.style.display="block";
                btn.style.width="100%";
                btn.style.textAlign="right";
                btn.style.padding="6px 8px";
                btn.style.border="0";
                btn.style.background="transparent";
                btn.addEventListener("click",function(){
                  input.value=it.customer_number+" - "+it.customer_name;
                  cell.querySelector(".expman-customer-id").value=it.id;
                  results.innerHTML="";
                });
                wrap.appendChild(btn);
              });
              results.appendChild(wrap);
            });
          });
          document.addEventListener("click",function(e){
            if(e.target.classList.contains("expman-customer-search")) return;
            document.querySelectorAll(".expman-customer-results").forEach(r=>{r.innerHTML="";});
          });
        })();</script>';
    }

    private function render_settings_tab() {
        $settings = $this->get_forticloud_settings();
        $api_id = $settings['api_id'] ?? '';
        $client_id = $settings['client_id'] ?? 'assetmanagement';
        $base_url = $settings['base_url'] ?? '';
        $has_secret = ! empty( $settings['api_secret']['value'] );

        echo '<form method="post" style="max-width:680px;">';
        wp_nonce_field( 'expman_firewalls' );
        echo '<input type="hidden" name="expman_action" value="save_forticloud_settings">';
        echo '<input type="hidden" name="tab" value="settings">';

        echo '<h3>FortiCloud</h3>';
        echo '<p style="max-width:640px;color:#555;">כדי שהסנכרון יעבוד יש ליצור IAM API User עם הרשאות Asset Management ולוודא שהוגדר Client ID כ-<strong>assetmanagement</strong>. לאחר שמירת ההגדרות ניתן לבצע סנכרון מהטאב עריכה קבוצתית.</p>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="forticloud_api_id">forticloud_api_id</label></th><td><input type="text" id="forticloud_api_id" name="forticloud_api_id" value="' . esc_attr( $api_id ) . '" class="regular-text"></td></tr>';
        echo '<tr><th><label for="forticloud_client_id">forticloud_client_id</label></th><td><input type="text" id="forticloud_client_id" name="forticloud_client_id" value="' . esc_attr( $client_id ) . '" class="regular-text"></td></tr>';
        echo '<tr><th><label for="forticloud_base_url">forticloud_base_url</label></th><td><input type="url" id="forticloud_base_url" name="forticloud_base_url" value="' . esc_attr( $base_url ) . '" class="regular-text" placeholder="https://api.forticloud.com"></td></tr>';
        echo '<tr><th>forticloud_api_secret</th><td>';
        if ( $has_secret ) {
            echo '<div style="margin-bottom:6px;color:#666;">סיסמה שמורה.</div>';
        }
        echo '<button type="button" class="button" id="expman-toggle-secret">עדכן סיסמה</button>';
        echo '<div id="expman-secret-wrap" style="display:none;margin-top:8px;">';
        echo '<input type="password" name="forticloud_api_secret" autocomplete="new-password" class="regular-text" placeholder="סיסמה חדשה">';
        echo '</div>';
        echo '</td></tr>';
        echo '</tbody></table>';

        echo '<p><button type="submit" class="button button-primary">שמירה</button></p>';
        echo '</form>';

        echo '<script>(function(){var btn=document.getElementById("expman-toggle-secret");var wrap=document.getElementById("expman-secret-wrap");if(btn&&wrap){btn.addEventListener("click",function(){wrap.style.display=wrap.style.display==="none"?"block":"none";});}})();</script>';
    }

    private function render_trash_tab() {
        $filters = $this->common_filters_from_get();
        $orderby = sanitize_key( $_GET['orderby'] ?? 'deleted_at' );
        $order   = sanitize_key( $_GET['order'] ?? 'DESC' );

        echo '<h3>סל מחזור (חומות אש)</h3>';
        $rows = $this->get_firewalls_rows( $filters, $orderby, $order, true, 0 );
        $this->render_table( $rows, $filters, $orderby, $order, true, null );
    }

    private function render_logs_tab() {
        global $wpdb;
        $logs_table = $wpdb->prefix . self::TABLE_FIREWALL_LOGS;
        $logs = $wpdb->get_results( "SELECT * FROM {$logs_table} ORDER BY id DESC LIMIT 200" );

        echo '<h3>לוגים (חומות אש)</h3>';
        echo '<p style="color:#555;">מציג 200 רשומות אחרונות של יצירה, עדכון, מחיקה וסנכרון.</p>';

        if ( empty( $logs ) ) {
            echo '<div class="notice notice-info"><p>אין לוגים להצגה.</p></div>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>זמן</th>';
        echo '<th>פעולה</th>';
        echo '<th>רמה</th>';
        echo '<th>ID חומת אש</th>';
        echo '<th>הודעה</th>';
        echo '<th>פרטים</th>';
        echo '</tr></thead><tbody>';

        foreach ( $logs as $log ) {
            $context = '';
            if ( ! empty( $log->context ) ) {
                $context = '<pre style="white-space:pre-wrap;margin:0;">' . esc_html( $log->context ) . '</pre>';
            }
            echo '<tr>';
            echo '<td>' . esc_html( $log->created_at ) . '</td>';
            echo '<td>' . esc_html( $log->action ) . '</td>';
            echo '<td>' . esc_html( $log->level ) . '</td>';
            echo '<td>' . esc_html( $log->firewall_id ) . '</td>';
            echo '<td>' . esc_html( $log->message ) . '</td>';
            echo '<td>' . $context . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function get_distinct_type_values( $field ) {
        global $wpdb;
        $types_table = $wpdb->prefix . self::TABLE_TYPES;
        $field = ($field === 'model') ? 'model' : 'vendor';
        $sql = "SELECT DISTINCT {$field} FROM {$types_table} WHERE {$field} IS NOT NULL AND {$field} <> '' ORDER BY {$field} ASC";
        $vals = $wpdb->get_col( $sql );
        $vals = array_values( array_filter( array_map( 'trim', (array) $vals ) ) );
        return $vals;
    }

    private function render_table( $rows, $filters, $orderby, $order, $is_trash, $clear_url = null, $is_tracking_table = false ) {
        $base = remove_query_arg( array( 'expman_msg' ) );
        $uid  = wp_generate_uuid4();
        $show_filters = ! $is_tracking_table;

        if ( $show_filters ) {
            echo '<form method="get" style="margin:0 0 10px 0;">';
        }

        $vendor_opts = $this->get_distinct_type_values( 'vendor' );
        $model_opts  = $this->get_distinct_type_values( 'model' );
        $vendor_sel  = array_filter( array_map( 'trim', explode( ',', (string) ( $filters['vendor'] ?? '' ) ) ) );
        $model_sel   = array_filter( array_map( 'trim', explode( ',', (string) ( $filters['model'] ?? '' ) ) ) );

        if ( $show_filters ) {
            echo '<div id="expman-ms-data-' . esc_attr( $uid ) . '" style="display:none"'
                . ' data-vendor-options="' . esc_attr( wp_json_encode( $vendor_opts ) ) . '"'
                . ' data-model-options="'  . esc_attr( wp_json_encode( $model_opts ) )  . '"'
                . ' data-vendor-selected="' . esc_attr( wp_json_encode( array_values( $vendor_sel ) ) ) . '"'
                . ' data-model-selected="'  . esc_attr( wp_json_encode( array_values( $model_sel ) ) )  . '"'
                . '></div>';

            echo '<script>
            (function(){
              const dataEl = document.currentScript.previousElementSibling;
              function get(key){try{return JSON.parse(document.getElementById("expman-ms-data-' . esc_js( $uid ) . '").dataset[key]||"[]")}catch(e){return []}}
              const optsVendor=get("vendorOptions"), optsModel=get("modelOptions");
              const selVendor=new Set(get("vendorSelected")), selModel=new Set(get("modelSelected"));

              function build(th, key, options, selected){
                const hidden=document.createElement("input");
                hidden.type="hidden"; hidden.name = key==="vendor" ? "f_vendor" : "f_model";
                hidden.value=Array.from(selected).join(",");

                const btn=document.createElement("button");
                btn.type="button";
                btn.className="expman-btn secondary";
                btn.style.width="100%"; btn.style.height="32px";
                btn.textContent = selected.size ? ("נבחרו " + selected.size) : "בחר...";

                const panel=document.createElement("div");
                panel.className="expman-ms-panel";
                panel.style.position="absolute"; panel.style.zIndex="9999";
                panel.style.background="#fff"; panel.style.border="1px solid #ccc";
                panel.style.borderRadius="10px"; panel.style.padding="10px";
                panel.style.minWidth="240px"; panel.style.maxHeight="260px";
                panel.style.overflow="auto"; panel.style.display="none";

                const search=document.createElement("input");
                search.type="text"; search.placeholder="חיפוש...";
                search.style.width="100%"; search.style.marginBottom="8px";

                const list=document.createElement("div");
                function render(q){
                  list.innerHTML="";
                  const qq=(q||"").toLowerCase();
                  options.filter(v=>!qq||String(v).toLowerCase().includes(qq)).forEach(v=>{
                    const label=document.createElement("label");
                    label.style.display="flex"; label.style.gap="8px"; label.style.alignItems="center";
                    label.style.margin="4px 0";
                    const cb=document.createElement("input");
                    cb.type="checkbox"; cb.checked=selected.has(v);
                    cb.addEventListener("change",()=>{cb.checked?selected.add(v):selected.delete(v);});
                    const span=document.createElement("span"); span.textContent=v;
                    label.appendChild(cb); label.appendChild(span);
                    list.appendChild(label);
                  });
                }
                search.addEventListener("input",()=>render(search.value));
                render("");
                const actions=document.createElement("div");
                actions.style.display="flex"; actions.style.gap="8px"; actions.style.marginTop="10px";

                const clear=document.createElement("button");
                clear.type="button"; clear.className="expman-btn expman-btn-clear"; clear.textContent="נקה";
                clear.addEventListener("click",()=>{selected.clear(); render(search.value);});

                actions.appendChild(clear);
                panel.appendChild(search); panel.appendChild(list); panel.appendChild(actions);

                th.style.position="relative";
                th.appendChild(hidden); th.appendChild(btn); th.appendChild(panel);

                btn.addEventListener("click",()=>{ panel.style.display = panel.style.display==="none"?"block":"none"; });
                document.addEventListener("click",(e)=>{ if(!th.contains(e.target)) panel.style.display="none"; });

                th.closest("form").addEventListener("submit",()=>{ hidden.value=Array.from(selected).join(","); });
              }

              document.querySelectorAll("th.expman-ms-wrap[data-ms=vendor]").forEach(th=>build(th,"vendor",optsVendor,selVendor));
              document.querySelectorAll("th.expman-ms-wrap[data-ms=model]").forEach(th=>build(th,"model",optsModel,selModel));
            })();
            </script>';

            foreach ( $_GET as $k => $v ) {
                if ( strpos( $k, 'f_' ) === 0 || in_array( $k, array( 'orderby','order' ), true ) ) { continue; }
                echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
            }
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';

        $this->th_sort( 'customer_number', 'מספר לקוח', $orderby, $order, $base );
        $this->th_sort( 'customer_name', 'שם לקוח', $orderby, $order, $base );
        $this->th_sort( 'branch', 'סניף', $orderby, $order, $base );
        $this->th_sort( 'serial_number', 'מספר סידורי', $orderby, $order, $base );
        $this->th_sort( 'days_to_renew', 'ימים לחידוש', $orderby, $order, $base );
        $this->th_sort( 'vendor', 'יצרן', $orderby, $order, $base );
        $this->th_sort( 'model', 'דגם', $orderby, $order, $base );
        $this->th_sort( 'is_managed', 'ניהול', $orderby, $order, $base );
        echo '<th>גישה</th>';
        echo '<th>פעולות</th>';
        echo '</tr>';

        if ( $show_filters ) {
            // filter row
            echo '<tr class="expman-filter-row">';
            echo '<th><input style="width:100%" name="f_customer_number" value="' . esc_attr( $filters['customer_number'] ) . '" placeholder="סינון..."></th>';
            echo '<th><input style="width:100%" name="f_customer_name" value="' . esc_attr( $filters['customer_name'] ) . '" placeholder="סינון..."></th>';
            echo '<th><input style="width:100%" name="f_branch" value="' . esc_attr( $filters['branch'] ) . '" placeholder="סינון..."></th>';
            echo '<th><input style="width:100%" name="f_serial_number" value="' . esc_attr( $filters['serial_number'] ) . '" placeholder="סינון..."></th>';
            echo '<th></th>'; // days_to_renew
            echo '<th class="expman-ms-wrap" data-ms="vendor"></th>';
            echo '<th class="expman-ms-wrap" data-ms="model"></th>';
            echo '<th><select name="f_is_managed" style="width:100%;">';
            echo '<option value="">הכל</option>';
            echo '<option value="1" ' . selected( $filters['is_managed'], '1', false ) . '>שלנו</option>';
            echo '<option value="0" ' . selected( $filters['is_managed'], '0', false ) . '>לא שלנו</option>';
            echo '</select></th>';
            echo '<th></th>'; // access
            echo '<th style="white-space:nowrap;">';
            echo '<button class="expman-btn secondary" type="submit">סנן</button> ';
            if ( $clear_url ) {
                echo '<a class="expman-btn secondary" style="display:inline-block;text-decoration:none;" href="' . esc_url( $clear_url ) . '">נקה</a>';
            }
            echo '</th>';
            echo '</tr>';
        }

        echo '</thead><tbody>';

        if ( empty( $rows ) ) {
            echo '<tr><td colspan="10">אין נתונים.</td></tr></tbody></table>';
            if ( $show_filters ) {
                echo '</form>';
            }
            return;
        }

        foreach ( (array) $rows as $r ) {
            $days = is_null( $r->days_to_renew ) ? '' : intval( $r->days_to_renew );

            $access_btn = '';
            if ( ! empty( $r->access_url ) ) {
                $u = (string) $r->access_url;
                if ( ! preg_match( '/^https?:\/\//i', $u ) ) { $u = 'https://' . ltrim( $u ); }
                $access_btn = '<a class="expman-btn secondary" style="text-decoration:none;padding:6px 10px;" href="' . esc_url( $u ) . '" target="_blank" rel="noopener noreferrer">גישה</a>';
            }

            $managed_label = intval( $r->is_managed ) ? 'שלנו' : 'לא שלנו';

            $highlight = '';
            if ( ! empty( $_GET['highlight'] ) && (string) $r->serial_number === (string) sanitize_text_field( wp_unslash( $_GET['highlight'] ) ) ) {
                $highlight = ' expman-highlight';
            }
            echo '<tr class="expman-row' . esc_attr( $highlight ) . '" style="cursor:pointer;">';
                        echo '<td>' . esc_html( $r->customer_number ?? '' ) . '</td>';
            echo '<td>' . esc_html( $r->customer_name ?? '' ) . '</td>';
            echo '<td>' . esc_html( $r->branch ?? '' ) . '</td>';
            echo '<td>' . esc_html( $r->serial_number ) . '</td>';
                        echo '<td>' . esc_html( $days ) . '</td>';
            echo '<td>' . esc_html( $r->vendor ?? '' ) . '</td>';
            echo '<td>' . esc_html( $r->model ?? '' ) . '</td>';
            echo '<td>' . esc_html( $managed_label ) . '</td>';
            echo '<td>' . $access_btn . '</td>';

            echo '<td style="white-space:nowrap;" onclick="event.stopPropagation();">';
            echo '<a href="#" class="expman-btn expman-edit-btn" style="text-decoration:none;padding:6px 10px;" data-id="' . esc_attr( $r->id ) . '">עריכה</a> ';
            if ( ! $is_trash ) {
                echo '<form method="post" style="display:inline;">';
                wp_nonce_field( 'expman_firewalls' );
                echo '<input type="hidden" name="expman_action" value="trash_firewall">';
                echo '<input type="hidden" name="firewall_id" value="' . esc_attr( $r->id ) . '">';
                echo '<button class="expman-btn secondary" type="submit" style="padding:6px 10px;" onclick="return confirm(\'להעביר ל־Trash?\');">Trash</button>';
                echo '</form>';
            } else {
                echo '<form method="post" style="display:inline;">';
                wp_nonce_field( 'expman_firewalls' );
                echo '<input type="hidden" name="expman_action" value="restore_firewall">';
                echo '<input type="hidden" name="firewall_id" value="' . esc_attr( $r->id ) . '">';
                echo '<button class="expman-btn secondary" type="submit" style="padding:6px 10px;">שחזור</button>';
                echo '</form>';
            }
            echo '</td>';
            echo '</tr>';

            // Inline edit form row (hidden)
            echo '<tr class="expman-inline-form" data-for="' . esc_attr( $r->id ) . '" style="display:none;background:#fff;">';
            echo '<td colspan="10">';
            $this->render_form( intval( $r->id ), $r );
            echo '</td></tr>';

            // Details row (click row expands)
            echo '<tr class="expman-details" data-for="' . esc_attr( $r->id ) . '" style="display:none;background:#fafafa;">';
            echo '<td colspan="10">';
            echo '<div style="display:flex;gap:20px;flex-wrap:wrap;">';
            echo '<div style="min-width:260px;"><strong>הערה קבועה:</strong><div style="white-space:pre-wrap;">' . esc_html( (string) $r->notes ) . '</div></div>';
            echo '<div style="min-width:260px;"><strong>הודעה זמנית:</strong><div style="white-space:pre-wrap;">' . esc_html( intval( $r->temp_notice_enabled ) ? (string) $r->temp_notice : '' ) . '</div></div>';
            echo '<div style="min-width:180px;"><strong>תאריך לחידוש:</strong> ' . esc_html( ( ! empty($r->expiry_date) ? date_i18n('d-m-Y', strtotime($r->expiry_date)) : '' ) ) . '</div>';
            echo '</div>';
            echo '</td></tr>';
        }

        echo '</tbody></table>';
        if ( $show_filters ) {
            echo '</form>';
        }

        echo '<script>(function(){
          const dataEl = document.currentScript.previousElementSibling;';
        echo 'document.querySelectorAll("tr.expman-row").forEach(function(tr){tr.addEventListener("click",function(){';
        echo 'var id=tr.querySelector("a.expman-edit-btn")?tr.querySelector("a.expman-edit-btn").getAttribute("data-id"):null;';
        echo 'if(!id) return; var d=document.querySelector("tr.expman-details[data-for=\'"+id+"\']"); if(!d) return;';
        echo 'd.style.display=(d.style.display==="none"||d.style.display==="")?"table-row":"none";';
        echo '});});';
        echo '})();</script>';
    }

    private function th_sort( $key, $label, $orderby, $order, $base ) {
        $next_order = ( $orderby === $key && strtoupper( $order ) === 'ASC' ) ? 'DESC' : 'ASC';
        $url = add_query_arg( array( 'orderby' => $key, 'order' => $next_order ), $base );
        echo '<th><a href="' . esc_url( $url ) . '" style="text-decoration:none;">' . esc_html( $label ) . '</a></th>';
    }

    private function render_form( $id = 0, $row_obj = null ) {
        $types = $this->get_box_types();

        $row = array(
            'customer_id'          => 0,
            'customer_number'      => '',
            'customer_name'        => '',
            'branch'               => '',
            'serial_number'        => '',
            'expiry_date'          => '',
            'is_managed'           => 1,
            'track_only'           => 0,
            'access_url'           => '',
            'notes'                => '',
            'temp_notice_enabled'  => 0,
            'temp_notice'          => '',
        );

        $vendor = '';
        $model  = '';

        if ( $row_obj ) {
            $row['customer_id']         = intval( $row_obj->customer_id );
            $row['customer_number']     = (string) ( $row_obj->customer_number ?? '' );
            $row['customer_name']       = (string) ( $row_obj->customer_name ?? '' );
            $row['branch']              = (string) ( $row_obj->branch ?? '' );
            $row['serial_number']       = (string) ( $row_obj->serial_number ?? '' );
            $row['expiry_date']         = (string) ( $row_obj->expiry_date ?? '' );
            $row['is_managed']          = intval( $row_obj->is_managed ?? 1 );
            $row['track_only']          = intval( $row_obj->track_only ?? 0 );
            $row['access_url']          = (string) ( $row_obj->access_url ?? '' );
            $row['notes']               = (string) ( $row_obj->notes ?? '' );
            $row['temp_notice_enabled'] = intval( $row_obj->temp_notice_enabled ?? 0 );
            $row['temp_notice']         = (string) ( $row_obj->temp_notice ?? '' );
            $vendor = (string) ( $row_obj->vendor ?? '' );
            $model  = (string) ( $row_obj->model ?? '' );
        }

        $vendors = array();
        foreach ( (array) $types as $t ) { $vendors[ (string) $t->vendor ] = true; }
        $vendors = array_keys( $vendors );
        sort( $vendors, SORT_NATURAL | SORT_FLAG_CASE );

        echo '<style>
            .expman-fw-form{background:#fff;border:1px solid #e3e3e3;border-radius:12px;padding:14px}
            .expman-fw-grid{display:grid;grid-template-columns:repeat(6,minmax(140px,1fr));gap:10px;align-items:end}
            .expman-fw-grid .full{grid-column:span 6}
            .expman-fw-grid .span2{grid-column:span 2}
            .expman-fw-grid .span3{grid-column:span 3}
            .expman-fw-grid label{display:block;font-size:12px;color:#333;margin-bottom:4px}
            .expman-fw-grid input,.expman-fw-grid select,.expman-fw-grid textarea{width:100%;box-sizing:border-box}
            .expman-fw-actions{display:flex;gap:10px;justify-content:flex-start;margin-top:12px}
            .expman-fw-actions .button{padding:8px 16px}
            @media (max-width: 1100px){ .expman-fw-grid{grid-template-columns:repeat(3,minmax(140px,1fr));} .expman-fw-grid .full{grid-column:span 3} .expman-fw-grid .span2{grid-column:span 3} .expman-fw-grid .span3{grid-column:span 3} }
        </style>';

        echo '<form method="post" class="expman-fw-form" style="margin:0;">';
        wp_nonce_field( 'expman_firewalls' );
        echo '<input type="hidden" name="expman_action" value="save_firewall">';
        echo '<input type="hidden" name="firewall_id" value="' . esc_attr( $id ) . '">';
        echo '<input type="hidden" name="customer_id" class="customer_id" value="' . esc_attr( $row['customer_id'] ) . '">';

        echo '<div class="expman-fw-grid">';

        echo '<div class="full">';
        echo '<label>לקוח (חיפוש לפי שם/מספר)</label>';
        echo '<input type="text" class="customer_search" placeholder="הקלד חלק מהשם או המספר..." autocomplete="off">';
        echo '<div class="customer_results" style="margin-top:6px;"></div>';
        echo '</div>';

        echo '<div class="span2"><label>מספר לקוח</label><input type="text" name="customer_number" class="customer_number" value="' . esc_attr( $row['customer_number'] ) . '" readonly></div>';
        echo '<div class="span2"><label>שם לקוח</label><input type="text" name="customer_name" class="customer_name" value="' . esc_attr( $row['customer_name'] ) . '" readonly></div>';
        echo '<div class="span2"><label>סניף</label><input type="text" name="branch" value="' . esc_attr( $row['branch'] ) . '"></div>';

        echo '<div class="span2"><label>מספר סידורי</label><input type="text" name="serial_number" value="' . esc_attr( $row['serial_number'] ) . '" required></div>';
        echo '<div class="span2"><label>תאריך תפוגה</label><input type="date" name="expiry_date" value="' . esc_attr( $row['expiry_date'] ) . '"></div>';

        echo '<div class="span2"><label>ניהול</label><select name="is_managed"><option value="1" ' . selected( $row['is_managed'], 1, false ) . '>שלנו</option><option value="0" ' . selected( $row['is_managed'], 0, false ) . '>לא שלנו</option></select></div>';

        // Vendor / Model selects
        echo '<div class="span3"><label>יצרן</label><select name="vendor" class="fw_vendor"><option value="">בחר יצרן</option>';
        foreach ( $vendors as $v ) {
            echo '<option value="' . esc_attr( $v ) . '" ' . selected( $vendor, $v, false ) . '>' . esc_html( $v ) . '</option>';
        }
        echo '</select></div>';

        echo '<div class="span3"><label>דגם</label><select name="model" class="fw_model"><option value="">בחר דגם</option>';
        foreach ( (array) $types as $t ) {
            if ( $vendor && (string) $t->vendor !== (string) $vendor ) { continue; }
            $m = (string) $t->model;
            echo '<option value="' . esc_attr( $m ) . '" ' . selected( $model, $m, false ) . '>' . esc_html( $m ) . '</option>';
        }
        echo '</select></div>';

        echo '<div class="span3"><label>URL לגישה מהירה</label><input type="url" name="access_url" value="' . esc_attr( $row['access_url'] ) . '" placeholder="https://..."></div>';

        echo '<div class="span3"><label><input type="checkbox" name="track_only" value="1" ' . checked( $row['track_only'], 1, false ) . '> לקוח למעקב</label></div>';

        echo '<div class="span3"><label>הערה קבועה</label><textarea name="notes" rows="2">' . esc_textarea( $row['notes'] ) . '</textarea></div>';

        echo '<div class="span3"><label><input type="checkbox" name="temp_notice_enabled" class="temp_notice_enabled" value="1" ' . checked( $row['temp_notice_enabled'], 1, false ) . '> הודעה זמנית</label></div>';
        echo '<div class="span3 temp_notice_wrap" style="' . ( $row['temp_notice_enabled'] ? '' : 'display:none;' ) . '"><label>טקסט הודעה זמנית</label><textarea name="temp_notice" rows="2" placeholder="מופיע עד חידוש או ביטול...">' . esc_textarea( $row['temp_notice'] ) . '</textarea></div>';

        echo '</div>'; // grid

        echo '<div class="expman-fw-actions">';
        echo '<button type="submit" class="button button-primary">שמירה</button>
                    <button type="button" class="button" data-expman-cancel-new>ביטול</button>';
        echo '</div>';

        echo '</form>';

        // Per-form JS (delegated)

        $ajax  = esc_url( admin_url( 'admin-ajax.php' ) );
        $nonce = wp_create_nonce( 'expman_customer_search' );
        $types_js = array();
        foreach ( (array) $types as $t ) { $types_js[] = array( 'vendor' => (string) $t->vendor, 'model' => (string) $t->model ); }

        echo '<script>(function(){
          const dataEl = document.currentScript.previousElementSibling;';
        echo 'const TYPES=' . wp_json_encode( $types_js ) . ';';
        echo 'document.querySelectorAll("form").forEach(function(f){';
        echo 'const chk=f.querySelector(".temp_notice_enabled"); const wrap=f.querySelector(".temp_notice_wrap");';
        echo 'if(chk&&wrap){chk.addEventListener("change",()=>{wrap.style.display=chk.checked?"block":"none";});}';
        echo 'const v=f.querySelector(".fw_vendor"); const m=f.querySelector(".fw_model");';
        echo 'if(v&&m){function rebuild(){const vv=v.value; m.innerHTML=""; const o0=document.createElement("option"); o0.value=""; o0.textContent="בחר דגם"; m.appendChild(o0);';
        echo 'TYPES.filter(t=>!vv||t.vendor===vv).forEach(t=>{const o=document.createElement("option"); o.value=t.model; o.textContent=t.model; m.appendChild(o);});}';
        echo 'v.addEventListener("change",()=>{m.value=""; rebuild();});}';
        echo '});';
        echo 'document.querySelectorAll(".customer_search").forEach(function(input){';
        echo 'const f=input.closest("form"); const results=f.querySelector(".customer_results"); const hidden=f.querySelector(".customer_id");';
        echo 'let t=null; function render(list){results.innerHTML=""; if(!list||!list.length) return;';
        echo 'const wrap=document.createElement("div"); wrap.style.border="1px solid #dcdcde"; wrap.style.borderRadius="6px"; wrap.style.background="#fff"; wrap.style.maxWidth="520px";';
        echo 'list.forEach(it=>{const btn=document.createElement("button"); btn.type="button"; btn.textContent=it.customer_number+" - "+it.customer_name;';
        echo 'btn.style.display="block"; btn.style.width="100%"; btn.style.textAlign="right"; btn.style.padding="6px 10px"; btn.style.border="0"; btn.style.borderBottom="1px solid #eee"; btn.style.background="transparent";';
        echo "btn.addEventListener(\"click\",()=>{input.value=it.customer_number+\" - \"+it.customer_name; hidden.value=it.id; var num=f.querySelector('.customer_number'); var name=f.querySelector('.customer_name'); if(num) num.value=it.customer_number||''; if(name) name.value=it.customer_name||''; results.innerHTML=\"\";}); wrap.appendChild(btn);});";
        echo 'results.appendChild(wrap);}';
        echo 'async function search(q){const url="' . esc_js( $ajax ) . '?action=expman_customer_search&nonce=' . esc_js( $nonce ) . '&q="+encodeURIComponent(q);';
        echo 'const res=await fetch(url,{credentials:"same-origin"}); if(!res.ok) return; const data=await res.json(); render(data.items||[]);}';
        echo 'input.addEventListener("input",()=>{const q=input.value.trim(); if(q.length<2){results.innerHTML=""; return;} clearTimeout(t); t=setTimeout(()=>search(q),250);});';
        echo '});';
        echo '})();</script>';
    }

}
}
